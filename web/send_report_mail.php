<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/report_mail_lib.php';

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

$ok = 0;
$failed = 0;
$recipientsByCompany = group_recipients_by_company($mailList);

foreach ($recipientsByCompany as $company => $recipients) {
    try {
        if (empty($recipients)) {
            throw new RuntimeException('Geen geldige ontvangers voor bedrijf');
        }

        send_company_report($reportMail, (string) $company, $recipients);
        record_report_mail_history((string) $company, 'batch@system', $recipients);
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
