<?php
require __DIR__ . '/auth.php';

$enforceScheduleGuard = true;
$guardRequireMonday = false;
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
    // Simuleer GET-parameter en mailmodus
    $_GET['company'] = $company;
    $isMailReport = true;
    ob_start();
    include $indexFile;
    $html = ob_get_clean();
    if (!$html || strlen(trim($html)) < 100) {
        throw new RuntimeException('Lege of te korte HTML uit index.php');
    }
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

function smtp_send_html_mail(array $reportMail, string $toEmail, string $subject, string $html): void
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
        smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromHeader = $fromName !== ''
            ? 'From: ' . encode_mimeheader_fallback($fromName) . ' <' . $fromEmail . '>'
            : 'From: <' . $fromEmail . '>';

        $headers = [
            $fromHeader,
            'To: <' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date(DATE_RFC2822),
        ];

        $message = implode("\r\n", $headers) . "\r\n\r\n" . $html;
        $message = preg_replace("/(\r\n|\n|\r)/", "\r\n", $message);
        $message = preg_replace('/^\./m', '..', $message);

        fwrite($socket, $message . "\r\n.\r\n");
        smtp_expect_code(smtp_read_response($socket), [250]);
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function send_html_mail(array $reportMail, string $toEmail, string $subject, string $html): void
{
    // Always use SMTP, never mail()
    if (!isset($reportMail['smtp']) || !is_array($reportMail['smtp']) || empty($reportMail['smtp']['host'])) {
        throw new RuntimeException('SMTP config ontbreekt of is onvolledig in auth.php');
    }
    smtp_send_html_mail($reportMail, $toEmail, $subject, $html);
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

foreach ($mailList as $recipient => $company) {
    $recipient = trim((string) $recipient);
    $company = trim((string) $company);

    if ($recipient === '' || $company === '') {
        $failed++;
        write_output('Skipped invalid mailList entry', true);
        continue;
    }

    try {
        $html = fetch_report_html($company);
        // Vervang alle <a>...</a> door alleen de tekstinhoud
        $html = preg_replace_callback(
            '/<a\b[^>]*>(.*?)<\/a>/is',
            function ($m) {
                return isset($m[1]) ? strip_tags($m[1]) : '';
            },
            $html
        );
        $subject = $subjectPrefix . ' - ' . $company . ' - ' . $dateText;
        send_html_mail($reportMail, $recipient, $subject, $html);
        $ok++;
        write_output("Sent to {$recipient} ({$company})");
    } catch (Throwable $exception) {
        $failed++;
        write_output('Failed for ' . $recipient . ' (' . $company . '): ' . $exception->getMessage(), true);
    }
}

if ($ok > 0 && $enforceScheduleGuard && $guardRequireSingleRunPerDay) {
    @file_put_contents($guardLastSentFile, date('Y-m-d'));
}

write_output("Done. Sent: {$ok}, failed: {$failed}");
exit($failed > 0 ? 2 : 0);
