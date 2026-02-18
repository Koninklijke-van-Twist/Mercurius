<?php
require __DIR__ . '/auth.php';

$enforceScheduleGuard = true;
$guardRequireMonday = true;
$guardRequireSingleRunPerDay = true;
$guardLastSentFile = __DIR__ . '/cache/report-mail-last-sent.txt';

function write_output($o)
{
    echo json_encode(["error" => $o]);
}

if ($enforceScheduleGuard) {
    $todayIso = date('Y-m-d');

    if ($guardRequireMonday && date('N') !== '1') {
        write_output('Niet versturen: vandaag is geen maandag.');
        exit(0);
    }

    if ($guardRequireSingleRunPerDay && is_file($guardLastSentFile)) {
        $lastSent = trim((string) file_get_contents($guardLastSentFile));
        if ($lastSent === $todayIso) {
            write_output('Niet versturen: er is vandaag al een rapportmail verstuurd.');
            exit(0);
        }
    }
}

if (!isset($mailList) || !is_array($mailList) || empty($mailList)) {
    write_output('mailList is empty in auth.php', true);
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

$reportUrl = trim((string) ($reportMail['report_url'] ?? ''));
if ($reportUrl === '') {
    write_output('report_url is empty in auth.php', true);
    exit(1);
}

$output = [];
foreach ($mailList as $recipient => $company) {
    $recipient = trim((string) $recipient);
    $company = str_replace(" ", "%20", trim((string) $company));

    if ($recipient === '' || $company === '') {
        continue;
    }

    $url = resolve_report_url("index.php?company={$company}&printfriendly=true");

    array_push($output, ["email" => $recipient, "url" => $url]);
}

echo json_encode($output);