<?php

function report_mail_db_path(): string
{
    return __DIR__ . '/cache/report-mail-recipients.sqlite';
}

function report_mail_db_company_column(string $company): ?string
{
    $normalized = trim($company);
    if ($normalized === 'Koninklijke van Twist') {
        return 'kvt';
    }
    if ($normalized === 'Hunter van Twist') {
        return 'hvt';
    }
    if ($normalized === 'KVT Gas') {
        return 'gas';
    }

    return null;
}

function report_mail_db_open(): PDO
{
    $dbPath = report_mail_db_path();
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS report_mail_recipients (
            email TEXT PRIMARY KEY,
            kvt INTEGER NOT NULL DEFAULT 0,
            hvt INTEGER NOT NULL DEFAULT 0,
            gas INTEGER NOT NULL DEFAULT 0
        )'
    );

    return $pdo;
}

function initialize_report_mail_recipient_db(array $legacyMailList, array $legacyGlobalRecipients = []): void
{
    $pdo = report_mail_db_open();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM report_mail_recipients')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $globals = [];
    foreach ($legacyGlobalRecipients as $email) {
        $email = strtolower(trim((string) $email));
        if ($email !== '') {
            $globals[$email] = true;
        }
    }

    $seedByEmail = [];
    foreach ($legacyMailList as $email => $company) {
        $email = strtolower(trim((string) $email));
        $company = trim((string) $company);

        if ($email === '' || isset($globals[$email])) {
            continue;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }

        $column = report_mail_db_company_column($company);
        if ($column === null) {
            continue;
        }

        if (!isset($seedByEmail[$email])) {
            $seedByEmail[$email] = ['kvt' => 0, 'hvt' => 0, 'gas' => 0];
        }
        $seedByEmail[$email][$column] = 1;
    }

    if (empty($seedByEmail)) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO report_mail_recipients (email, kvt, hvt, gas)
         VALUES (:email, :kvt, :hvt, :gas)
         ON CONFLICT(email) DO UPDATE SET
            kvt = MAX(kvt, excluded.kvt),
            hvt = MAX(hvt, excluded.hvt),
            gas = MAX(gas, excluded.gas)'
    );

    foreach ($seedByEmail as $email => $flags) {
        $stmt->execute([
            ':email' => $email,
            ':kvt' => (int) $flags['kvt'],
            ':hvt' => (int) $flags['hvt'],
            ':gas' => (int) $flags['gas'],
        ]);
    }
}

function get_report_mail_recipients(): array
{
    $pdo = report_mail_db_open();
    $stmt = $pdo->query('SELECT email, kvt, hvt, gas FROM report_mail_recipients ORDER BY email COLLATE NOCASE');
    $rows = $stmt->fetchAll();

    return is_array($rows) ? $rows : [];
}

function get_report_mail_recipients_for_company(string $company): array
{
    $column = report_mail_db_company_column($company);
    if ($column === null) {
        return [];
    }

    $pdo = report_mail_db_open();
    $stmt = $pdo->query('SELECT email FROM report_mail_recipients WHERE ' . $column . ' = 1 ORDER BY email COLLATE NOCASE');
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        return [];
    }

    $emails = [];
    foreach ($rows as $row) {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }

    return $emails;
}

function add_report_mail_recipient(string $email, bool $kvt, bool $hvt, bool $gas): void
{
    $email = strtolower(trim($email));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('E-mailadres is ongeldig.');
    }

    $pdo = report_mail_db_open();
    $stmt = $pdo->prepare('INSERT INTO report_mail_recipients (email, kvt, hvt, gas) VALUES (:email, :kvt, :hvt, :gas)');
    $stmt->execute([
        ':email' => $email,
        ':kvt' => $kvt ? 1 : 0,
        ':hvt' => $hvt ? 1 : 0,
        ':gas' => $gas ? 1 : 0,
    ]);
}

function update_report_mail_recipient_flags(string $email, bool $kvt, bool $hvt, bool $gas): void
{
    $email = strtolower(trim($email));
    if ($email === '') {
        throw new InvalidArgumentException('E-mailadres ontbreekt.');
    }

    $pdo = report_mail_db_open();
    $stmt = $pdo->prepare('UPDATE report_mail_recipients SET kvt = :kvt, hvt = :hvt, gas = :gas WHERE email = :email');
    $stmt->execute([
        ':email' => $email,
        ':kvt' => $kvt ? 1 : 0,
        ':hvt' => $hvt ? 1 : 0,
        ':gas' => $gas ? 1 : 0,
    ]);
}

function delete_report_mail_recipient(string $email): void
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return;
    }

    $pdo = report_mail_db_open();
    $stmt = $pdo->prepare('DELETE FROM report_mail_recipients WHERE email = :email');
    $stmt->execute([':email' => $email]);
}
