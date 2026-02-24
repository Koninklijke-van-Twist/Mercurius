<?php

require_once __DIR__ . '/css_inliner.php';
require_once __DIR__ . '/html_to_pdf.php';

function encode_mimeheader_fallback($str)
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($str, 'UTF-8');
    }
    return '=?UTF-8?B?' . base64_encode($str) . '?=';
}

function normalize_recipients(array $recipients): array
{
    $normalized = [];
    $seen = [];

    foreach ($recipients as $recipient) {
        $recipient = trim((string) $recipient);
        if ($recipient === '' || strpos($recipient, '@') === false) {
            continue;
        }

        $key = strtolower($recipient);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $normalized[] = $recipient;
    }

    return $normalized;
}

function group_recipients_by_company(array $mailList): array
{
    $recipientsByCompany = [];
    foreach ($mailList as $recipient => $company) {
        $recipient = trim((string) $recipient);
        $company = trim((string) $company);

        if ($recipient === '' || $company === '') {
            continue;
        }

        if (!isset($recipientsByCompany[$company])) {
            $recipientsByCompany[$company] = [];
        }
        $recipientsByCompany[$company][] = $recipient;
    }

    foreach ($recipientsByCompany as $company => $recipients) {
        $recipientsByCompany[$company] = normalize_recipients($recipients);
    }

    return $recipientsByCompany;
}

function smtp_read_response($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('Empty SMTP response');
    }

    return $response;
}

function smtp_expect_code(string $response, array $expectedCodes): void
{
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Unexpected SMTP response [' . $code . ']: ' . trim($response));
    }
}

function smtp_command($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    $response = smtp_read_response($socket);
    smtp_expect_code($response, $expectedCodes);
    return $response;
}

function smtp_send_pdf_mail(array $reportMail, array $toEmails, string $subject, string $textBody, string $pdfBinary, string $pdfFilename, ?string $htmlBody = null): void
{
    $smtp = $reportMail['smtp'] ?? [];
    $host = (string) ($smtp['host'] ?? '');
    $port = (int) ($smtp['port'] ?? 587);
    $encryption = strtolower((string) ($smtp['encryption'] ?? 'tls'));
    $username = (string) ($smtp['username'] ?? '');
    $password = (string) ($smtp['password'] ?? '');
    $timeout = (int) ($smtp['timeout'] ?? 20);

    if ($host === '') {
        throw new RuntimeException('SMTP host is empty in auth.php');
    }

    $toEmails = normalize_recipients($toEmails);
    if (empty($toEmails)) {
        throw new RuntimeException('Recipient list is empty');
    }

    $transportHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client($transportHost . ':' . $port, $errno, $errstr, $timeout);
    if ($socket === false) {
        throw new RuntimeException('SMTP connect failed: ' . $errstr . ' (' . $errno . ')');
    }

    stream_set_timeout($socket, $timeout);

    try {
        smtp_expect_code(smtp_read_response($socket), [220]);
        smtp_command($socket, 'EHLO localhost', [250]);

        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            $cryptoEnabled = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('Failed to enable STARTTLS');
            }
            smtp_command($socket, 'EHLO localhost', [250]);
        }

        if ($username !== '' || $password !== '') {
            smtp_command($socket, 'AUTH LOGIN', [334]);
            smtp_command($socket, base64_encode($username), [334]);
            smtp_command($socket, base64_encode($password), [235]);
        }

        $fromEmail = (string) ($reportMail['from_email'] ?? '');
        $fromName = (string) ($reportMail['from_name'] ?? '');
        if ($fromEmail === '') {
            throw new RuntimeException('from_email is empty in auth.php');
        }

        smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        foreach ($toEmails as $toEmail) {
            smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        }
        smtp_command($socket, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromHeader = $fromName !== ''
            ? 'From: ' . encode_mimeheader_fallback($fromName) . ' <' . $fromEmail . '>'
            : 'From: <' . $fromEmail . '>';

        $boundary = '=_Part_' . bin2hex(random_bytes(12));
        $altBoundary = '=_Alt_' . bin2hex(random_bytes(12));
        $safeFilename = str_replace(["\r", "\n", '"'], ['', '', '_'], $pdfFilename);
        $pdfBase64 = chunk_split(base64_encode($pdfBinary));

        $headers = [
            $fromHeader,
            'To: ' . implode(', ', array_map(static fn(string $email): string => '<' . $email . '>', $toEmails)),
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'Date: ' . date(DATE_RFC2822),
        ];

        $textPart =
            '--' . $altBoundary . "\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $textBody . "\r\n\r\n";

        if ($htmlBody !== null && trim($htmlBody) !== '') {
            $textPart .=
                '--' . $altBoundary . "\r\n" .
                "Content-Type: text/html; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                $htmlBody . "\r\n\r\n";
        }

        $textPart .= '--' . $altBoundary . "--\r\n";

        $messageBody =
            '--' . $boundary . "\r\n" .
            'Content-Type: multipart/alternative; boundary="' . $altBoundary . "\"\r\n\r\n" .
            $textPart . "\r\n" .
            '--' . $boundary . "\r\n" .
            'Content-Type: application/pdf; name="' . $safeFilename . "\"\r\n" .
            "Content-Transfer-Encoding: base64\r\n" .
            'Content-Disposition: attachment; filename="' . $safeFilename . "\"\r\n\r\n" .
            $pdfBase64 . "\r\n" .
            '--' . $boundary . "--\r\n";

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $messageBody;
        $message = preg_replace("/(\r\n|\n|\r)/", "\r\n", $message);
        $message = preg_replace('/^\./m', '..', $message);

        fwrite($socket, $message . "\r\n.\r\n");
        smtp_expect_code(smtp_read_response($socket), [250]);
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function send_pdf_mail(array $reportMail, array $toEmails, string $subject, string $textBody, string $pdfBinary, string $pdfFilename, ?string $htmlBody = null): void
{
    if (!isset($reportMail['smtp']) || !is_array($reportMail['smtp']) || empty($reportMail['smtp']['host'])) {
        throw new RuntimeException('SMTP config ontbreekt of is onvolledig in auth.php');
    }
    smtp_send_pdf_mail($reportMail, $toEmails, $subject, $textBody, $pdfBinary, $pdfFilename, $htmlBody);
}

function fetch_report_html(string $company): string
{
    $indexFile = __DIR__ . '/index.php';
    if (!file_exists($indexFile)) {
        throw new RuntimeException('index.php niet gevonden');
    }

    $mailQuery = [
        'company' => $company,
        'printfriendly' => 'true',
        'filter' => 'overdue',
        'open_filter' => 'open',
        'search' => '',
        'due_before' => '',
        'customer_no' => '',
    ];

    $originalGet = $_GET;
    $_GET = $mailQuery;
    $isMailReport = true;

    try {
        $html = (function () use ($indexFile) {
            ob_start();
            include $indexFile;
            return ob_get_clean();
        })();
    } finally {
        $_GET = $originalGet;
    }

    if (!$html || strlen(trim($html)) < 100) {
        throw new RuntimeException('Lege of te korte HTML uit index.php');
    }
    return $html;
}

function sanitize_mail_html(string $html): string
{
    $html = preg_replace('/<div\b[^>]*class="[^"]*odata-cache-root[^"]*"[^>]*>.*?<\/div>\s*<\/div>/is', '', $html) ?? $html;
    $html = preg_replace('/<form\b[^>]*class="[^"]*controls[^"]*"[^>]*>.*?<\/form>/is', '', $html) ?? $html;
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
    $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html) ?? $html;
    $html = preg_replace('/const\s+statusUrl\s*=.*?setInterval\s*\(\s*function\s*\(\)\s*\{.*?\}\s*,\s*1000\s*\)\s*;\s*\}\)\s*\(\)\s*;?/is', '', $html) ?? $html;
    return $html;
}

function slugify_company(string $company): string
{
    $slug = strtolower(trim($company));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'bedrijf';
}

function parse_nl_amount(string $text): ?float
{
    $text = str_replace(["\xc2\xa0", ' '], '', trim($text));
    if ($text === '') {
        return null;
    }

    if (!preg_match('/-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2}|-?\d+/', $text, $matches)) {
        return null;
    }

    $normalized = str_replace('.', '', $matches[0]);
    $normalized = str_replace(',', '.', $normalized);

    if (!is_numeric($normalized)) {
        return null;
    }

    return (float) $normalized;
}

function extract_report_stats_from_html(string $html): array
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
    $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $debiteurenCount = (int) $xpath->evaluate('count(//section[contains(concat(" ", normalize-space(@class), " "), " group ")])');
    $rows = $xpath->query('//tbody/tr[not(contains(concat(" ", normalize-space(@class), " "), " total-row "))]');

    $postenCount = 0;
    $totaal = 0.0;

    if ($rows !== false) {
        foreach ($rows as $row) {
            if (!($row instanceof DOMElement)) {
                continue;
            }

            $amountCell = $xpath->query('.//td[@data-label="Verschuldigd"]', $row)->item(0);
            if (!($amountCell instanceof DOMElement)) {
                continue;
            }

            $postenCount++;
            $parsedAmount = parse_nl_amount($amountCell->textContent);
            if ($parsedAmount !== null) {
                $totaal += $parsedAmount;
            }
        }
    }

    return [
        'posten' => $postenCount,
        'debiteuren' => $debiteurenCount,
        'totaal' => $totaal,
    ];
}

function format_eur_nl(float $amount): string
{
    return '€' . number_format($amount, 2, ',', '.');
}

function get_report_mail_history_path(): string
{
    return __DIR__ . '/cache/report-mail-history.json';
}

function load_report_mail_history(): array
{
    $path = get_report_mail_history_path();
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function save_report_mail_history(array $history): void
{
    $path = get_report_mail_history_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function record_report_mail_history(string $company, string $sentBy, array $recipients): void
{
    $history = load_report_mail_history();
    $history[$company] = [
        'last_sent_at' => date('c'),
        'last_sent_by' => $sentBy,
        'recipients' => array_values(normalize_recipients($recipients)),
    ];
    save_report_mail_history($history);
}

function send_company_report(array $reportMail, string $company, array $recipients): array
{
    $recipients = normalize_recipients($recipients);
    if (empty($recipients)) {
        throw new RuntimeException('Geen geldige ontvangers voor bedrijf');
    }

    $subjectPrefix = trim((string) ($reportMail['subject_prefix'] ?? 'Openstaande posten debiteuren'));
    $dateText = date('d-m-Y');

    $html = fetch_report_html((string) $company);
    $html = sanitize_mail_html($html);
    $html = inline_css_from_style_tags($html);
    $html = preg_replace_callback(
        '/<a\b[^>]*>(.*?)<\/a>/is',
        function ($m) {
            return isset($m[1]) ? strip_tags($m[1]) : '';
        },
        $html
    );
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;

    $subject = $subjectPrefix . ' - ' . $company . ' - ' . $dateText;
    $pdfBinary = htmlToPdf($html, $reportMail);
    $pdfFilename = 'openstaande-posten-' . slugify_company((string) $company) . '-' . date('Ymd') . '.pdf';
    $stats = extract_report_stats_from_html($html);
    $postenText = number_format((int) ($stats['posten'] ?? 0), 0, ',', '.');
    $debiteurenText = number_format((int) ($stats['debiteuren'] ?? 0), 0, ',', '.');
    $totaalText = format_eur_nl((float) ($stats['totaal'] ?? 0.0));

    $textBody = "Beste collega,\n\n"
        . "Bijgevoegd is de rapportage van vervallen posten.\n\n"
        . "Er staan {$postenText} vervallen posten open, verspreid over {$debiteurenText} debiteuren, met een totaalwaarde van {$totaalText}.\n"
        . "U kunt deze rapportage ook zien op: https://sleutels.kvt.nl/mercurius/\n\n"
        . "Met vriendelijke groet,\n\n"
        . "KVT Robot";

    $htmlBody = '<!doctype html><html><body>'
        . '<p>Beste collega,</p>'
        . '<p>Bijgevoegd is de rapportage van vervallen posten.</p>'
        . '<p>Er staan <strong>' . htmlspecialchars($postenText, ENT_QUOTES, 'UTF-8') . '</strong> vervallen posten open, verspreid over <strong>' . htmlspecialchars($debiteurenText, ENT_QUOTES, 'UTF-8') . '</strong> debiteuren, met een totaalwaarde van <strong>' . htmlspecialchars($totaalText, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
        . '<p>U kunt deze rapportage ook zien op: <a href="https://sleutels.kvt.nl/mercurius/">Mercurius</a></p>'
        . '<p>Met vriendelijke groet,<br><br>KVT Robot</p>'
        . '</body></html>';

    send_pdf_mail($reportMail, $recipients, $subject, $textBody, $pdfBinary, $pdfFilename, $htmlBody);

    return [
        'company' => $company,
        'recipients' => $recipients,
        'stats' => $stats,
        'pdf_filename' => $pdfFilename,
    ];
}