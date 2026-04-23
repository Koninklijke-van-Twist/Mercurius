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

function report_mail_attachment_defaults(): array
{
    return [
        'pdf_report' => 1,
        'csv_rapport' => 1,
        'csv_stambestand' => 0,
        'csv_openstaande' => 1,
        'csv_betaalde' => 0,
    ];
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

    $defaults = report_mail_attachment_defaults();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS report_mail_attachments (
            company TEXT PRIMARY KEY,
            pdf_report INTEGER NOT NULL DEFAULT ' . (int) $defaults['pdf_report'] . ',
            csv_rapport INTEGER NOT NULL DEFAULT ' . (int) $defaults['csv_rapport'] . ',
            csv_stambestand INTEGER NOT NULL DEFAULT ' . (int) $defaults['csv_stambestand'] . ',
            csv_openstaande INTEGER NOT NULL DEFAULT ' . (int) $defaults['csv_openstaande'] . ',
            csv_betaalde INTEGER NOT NULL DEFAULT ' . (int) $defaults['csv_betaalde'] . '
        )'
    );

    // Migrate: add csv_rapport column if it does not exist yet (existing installs).
    try {
        $pdo->exec('ALTER TABLE report_mail_attachments ADD COLUMN csv_rapport INTEGER NOT NULL DEFAULT ' . (int) $defaults['csv_rapport']);
    } catch (Throwable $e) {
        // Column already exists; ignore.
    }

    return $pdo;
}

function initialize_report_mail_recipient_db(array $legacyMailList, array $legacyGlobalRecipients = []): void
{
    $pdo = report_mail_db_open();

    $attachmentDefaults = report_mail_attachment_defaults();
    $attachmentStmt = $pdo->prepare(
        'INSERT INTO report_mail_attachments (company, pdf_report, csv_rapport, csv_stambestand, csv_openstaande, csv_betaalde)
         VALUES (:company, :pdf_report, :csv_rapport, :csv_stambestand, :csv_openstaande, :csv_betaalde)
         ON CONFLICT(company) DO NOTHING'
    );

    foreach (['Koninklijke van Twist', 'Hunter van Twist', 'KVT Gas'] as $company) {
        $attachmentStmt->execute([
            ':company' => $company,
            ':pdf_report' => (int) $attachmentDefaults['pdf_report'],
            ':csv_rapport' => (int) $attachmentDefaults['csv_rapport'],
            ':csv_stambestand' => (int) $attachmentDefaults['csv_stambestand'],
            ':csv_openstaande' => (int) $attachmentDefaults['csv_openstaande'],
            ':csv_betaalde' => (int) $attachmentDefaults['csv_betaalde'],
        ]);
    }

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

function get_report_mail_attachments_for_company(string $company): array
{
    $defaults = report_mail_attachment_defaults();
    if (report_mail_db_company_column($company) === null) {
        return $defaults;
    }

    $pdo = report_mail_db_open();
    $stmt = $pdo->prepare(
        'SELECT pdf_report, csv_rapport, csv_stambestand, csv_openstaande, csv_betaalde
         FROM report_mail_attachments
         WHERE company = :company'
    );
    $stmt->execute([':company' => $company]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        set_report_mail_attachments_for_company(
            $company,
            (bool) $defaults['pdf_report'],
            (bool) $defaults['csv_rapport'],
            (bool) $defaults['csv_stambestand'],
            (bool) $defaults['csv_openstaande'],
            (bool) $defaults['csv_betaalde']
        );
        return $defaults;
    }

    return [
        'pdf_report' => ((int) ($row['pdf_report'] ?? 0)) === 1 ? 1 : 0,
        'csv_rapport' => ((int) ($row['csv_rapport'] ?? $defaults['csv_rapport'])) === 1 ? 1 : 0,
        'csv_stambestand' => ((int) ($row['csv_stambestand'] ?? 0)) === 1 ? 1 : 0,
        'csv_openstaande' => ((int) ($row['csv_openstaande'] ?? 0)) === 1 ? 1 : 0,
        'csv_betaalde' => ((int) ($row['csv_betaalde'] ?? 0)) === 1 ? 1 : 0,
    ];
}

function set_report_mail_attachments_for_company(string $company, bool $pdfReport, bool $csvRapport, bool $csvStambestand, bool $csvOpenstaande, bool $csvBetaalde): void
{
    if (report_mail_db_company_column($company) === null) {
        throw new InvalidArgumentException('Onbekend bedrijf voor bijlage-instellingen.');
    }

    $pdo = report_mail_db_open();
    $stmt = $pdo->prepare(
        'INSERT INTO report_mail_attachments (company, pdf_report, csv_rapport, csv_stambestand, csv_openstaande, csv_betaalde)
         VALUES (:company, :pdf_report, :csv_rapport, :csv_stambestand, :csv_openstaande, :csv_betaalde)
         ON CONFLICT(company) DO UPDATE SET
            pdf_report = excluded.pdf_report,
            csv_rapport = excluded.csv_rapport,
            csv_stambestand = excluded.csv_stambestand,
            csv_openstaande = excluded.csv_openstaande,
            csv_betaalde = excluded.csv_betaalde'
    );

    $stmt->execute([
        ':company' => $company,
        ':pdf_report' => $pdfReport ? 1 : 0,
        ':csv_rapport' => $csvRapport ? 1 : 0,
        ':csv_stambestand' => $csvStambestand ? 1 : 0,
        ':csv_openstaande' => $csvOpenstaande ? 1 : 0,
        ':csv_betaalde' => $csvBetaalde ? 1 : 0,
    ]);
}
