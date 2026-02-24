<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/css_inliner.php';
require_once __DIR__ . '/html_to_pdf.php';

$enforceScheduleGuard = true;
$guardRequireMonday = true;
$guardRequireSingleRunPerDay = true;
$guardLastSentFile = __DIR__ . '/cache/report-mail-last-sent.txt';

function write_output(string $message, bool $isError = false): void
{
    if (PHP_SAPI === 'cli') {
        $stream = $isError ? STDERR : STDOUT;
        fwrite($stream, $message . "\n");
        return;
    }

    if ($isError) {
        echo '<div style="color:#b42318;">' . htmlspecialchars($message) . "</div>\n";
    } else {
        echo '<div>' . htmlspecialchars($message) . "</div>\n";
    }
}

function encode_mimeheader_fallback($str)
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($str, 'UTF-8');
    }
    // Fallback: base64 encode UTF-8
    return '=?UTF-8?B?' . base64_encode($str) . '?=';
}

if ($enforceScheduleGuard) {
    $todayIso = date('Y-m-d');

    if ($guardRequireMonday && date('N') !== '1') {
        write_output('Niet verstuurd: vandaag is geen maandag.');
        exit(0);
    }

    if ($guardRequireSingleRunPerDay && is_file($guardLastSentFile)) {
        $lastSent = trim((string) file_get_contents($guardLastSentFile));
        if ($lastSent === $todayIso) {
            write_output('Niet verstuurd: er is vandaag al een rapportmail verstuurd.');
            exit(0);
        }
    }
}

if (!isset($mailList) || !is_array($mailList) || empty($mailList)) {
    write_output('mailList is empty in auth.php', true);
    exit(1);
}

if (!isset($reportMail) || !is_array($reportMail)) {
    write_output('reportMail is missing in auth.php', true);
    exit(1);
}


function resolve_report_url(string $url): string
{
    // If already absolute, return as-is
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }
    // Build absolute URL from current request or CLI context
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/web/send_report_mail.php'), '/\\');
    $rel = ltrim($url, '/');
    return "$scheme://$host$basePath/$rel";
}

function build_report_url(string $baseUrl, string $company): string
{
    $absUrl = resolve_report_url($baseUrl);
    $separator = str_contains($absUrl, '?') ? '&' : '?';
    return $absUrl . $separator . http_build_query(['company' => $company], '', '&', PHP_QUERY_RFC3986);
}

function fetch_report_html(string $company): string
{
    $indexFile = __DIR__ . '/index.php';
    if (!file_exists($indexFile)) {
        throw new RuntimeException('index.php niet gevonden');
    }
    // Simuleer GET-parameters en mailmodus met vaste mailfilters.
    // Dit voorkomt dat bestaande query-params (bijv. due_before/search) een lege mail veroorzaken.
    $mailQuery = [
        'company' => $company,
        'printfriendly' => 'true',
        'filter' => 'all',
        'open_filter' => 'both',
        'search' => '',
        'due_before' => '',
        'customer_no' => '',
    ];

    $originalGet = $_GET;
    $_GET = $mailQuery;

    // Flag blijft beschikbaar voor index.php
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
    // Verwijder volledige cache-widget root (incl. inline script/style) indien aanwezig.
    $html = preg_replace('/<div\b[^>]*class="[^"]*odata-cache-root[^"]*"[^>]*>.*?<\/div>\s*<\/div>/is', '', $html) ?? $html;

    // Verwijder controls-formulier volledig voor mailweergave.
    $html = preg_replace('/<form\b[^>]*class="[^"]*controls[^"]*"[^>]*>.*?<\/form>/is', '', $html) ?? $html;

    // Verwijder alle scripts en noscript-inhoud.
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
    $html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $html) ?? $html;

    // Extra defensief: verwijder bekende cache-widget JS-residu als plaintext.
    $html = preg_replace('/const\s+statusUrl\s*=.*?setInterval\s*\(\s*function\s*\(\)\s*\{.*?\}\s*,\s*1000\s*\)\s*;\s*\}\)\s*\(\)\s*;?/is', '', $html) ?? $html;

    return $html;
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

function smtp_send_pdf_mail(array $reportMail, array $toEmails, string $subject, string $textBody, string $pdfBinary, string $pdfFilename): void
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

        $messageBody =
            '--' . $boundary . "\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $textBody . "\r\n\r\n" .
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

function slugify_company(string $company): string
{
    $slug = strtolower(trim($company));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'bedrijf';
}

function send_pdf_mail(array $reportMail, array $toEmails, string $subject, string $textBody, string $pdfBinary, string $pdfFilename): void
{
    // Always use SMTP, never mail()
    if (!isset($reportMail['smtp']) || !is_array($reportMail['smtp']) || empty($reportMail['smtp']['host'])) {
        throw new RuntimeException('SMTP config ontbreekt of is onvolledig in auth.php');
    }
    smtp_send_pdf_mail($reportMail, $toEmails, $subject, $textBody, $pdfBinary, $pdfFilename);
}

$reportUrl = trim((string) ($reportMail['report_url'] ?? ''));
if ($reportUrl === '') {
    write_output('report_url is empty in auth.php', true);
    exit(1);
}

$subjectPrefix = trim((string) ($reportMail['subject_prefix'] ?? 'Openstaande posten debiteuren'));
$dateText = date('d-m-Y');

$ok = 0;
$failed = 0;

$recipientsByCompany = [];
foreach ($mailList as $recipient => $company) {
    $recipient = trim((string) $recipient);
    $company = trim((string) $company);

    if ($recipient === '' || $company === '') {
        $failed++;
        write_output('Skipped invalid mailList entry', true);
        continue;
    }

    if (!isset($recipientsByCompany[$company])) {
        $recipientsByCompany[$company] = [];
    }
    $recipientsByCompany[$company][] = $recipient;
}

foreach ($recipientsByCompany as $company => $recipients) {
    try {
        $recipients = normalize_recipients($recipients);
        if (empty($recipients)) {
            throw new RuntimeException('Geen geldige ontvangers voor bedrijf');
        }

        $html = fetch_report_html((string) $company);
        $html = sanitize_mail_html($html);
        // Zet alle CSS inline voor mail/PDF (heeft geen effect op index.php)
        $html = inline_css_from_style_tags($html);
        // Vervang alle <a>...</a> door alleen de tekstinhoud
        $html = preg_replace_callback(
            '/<a\b[^>]*>(.*?)<\/a>/is',
            function ($m) {
                return isset($m[1]) ? strip_tags($m[1]) : '';
            },
            $html
        );
        // Verwijder scripts nogmaals defensief (na inlinen)
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;

        $subject = $subjectPrefix . ' - ' . $company . ' - ' . $dateText;
        $pdfBinary = htmlToPdf($html, $reportMail);
        $pdfFilename = 'openstaande-posten-' . slugify_company((string) $company) . '-' . date('Ymd') . '.pdf';
        $textBody = 'In de bijlage vindt u het rapport voor ' . $company . ' (' . $dateText . ').';

        send_pdf_mail($reportMail, $recipients, $subject, $textBody, $pdfBinary, $pdfFilename);
        $ok++;
        $recipientLines = array_map(
            static fn(string $email): string => '- ' . $email,
            $recipients
        );
        write_output("Verstuurd - {$company}:\n" . implode("\n", $recipientLines));
    } catch (Throwable $exception) {
        $failed++;
        write_output('Failed for company ' . $company . ': ' . $exception->getMessage(), true);
    }
}

if ($ok > 0 && $enforceScheduleGuard && $guardRequireSingleRunPerDay) {
    @file_put_contents($guardLastSentFile, date('Y-m-d'));
}

write_output("Done. Sent: {$ok}, failed: {$failed}");
exit($failed > 0 ? 2 : 0);
