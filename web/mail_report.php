<?php
require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/report_mail_lib.php';
require_once __DIR__ . '/mail_recipients_db.php';

$companies = [
    'Koninklijke van Twist',
    'Hunter van Twist',
    'KVT Gas',
];

$allUsers = [];
$recipientSettingsByEmail = [];
$currentUserEmail = (string) ($_SESSION['user']['email'] ?? '');

$history = load_report_mail_history();

$successMessage = '';
$errorMessage = '';

try {
    initialize_report_mail_recipient_db(
        is_array($defaultMailList ?? null) ? $defaultMailList : []
    );

    $recipientRows = get_report_mail_recipients();
    foreach ($recipientRows as $row) {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '') {
            continue;
        }

        $allUsers[] = $email;
        $recipientSettingsByEmail[strtolower($email)] = [
            'kvt' => (int) ($row['kvt'] ?? 0),
            'hvt' => (int) ($row['hvt'] ?? 0),
            'gas' => (int) ($row['gas'] ?? 0),
        ];
    }
} catch (Throwable $exception) {
    $errorMessage = 'SQLite configuratie kon niet worden geladen: ' . $exception->getMessage();
}

sort($allUsers, SORT_NATURAL | SORT_FLAG_CASE);

$submittedRecipientsByCompany = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $company = trim((string) ($_POST['company'] ?? ''));
    $selectedRecipients = $_POST['recipients'] ?? [];
    if (!is_array($selectedRecipients)) {
        $selectedRecipients = [];
    }

    $submittedRecipientsByCompany[$company] = normalize_recipients($selectedRecipients);

    if ($action !== 'send_company') {
        $errorMessage = 'Ongeldige actie.';
    } elseif (!in_array($company, $companies, true)) {
        $errorMessage = 'Ongeldig bedrijf geselecteerd.';
    } else {
        try {
            $result = send_company_report($reportMail, $company, $selectedRecipients);
            record_report_mail_history($company, $currentUserEmail !== '' ? $currentUserEmail : 'onbekend', $result['recipients']);
            $history = load_report_mail_history();
            $recipientCount = count($result['recipients']);
            $successMessage = 'Mail verstuurd voor ' . $company . ' naar ' . $recipientCount . ' ontvanger(s).';
        } catch (Throwable $exception) {
            $errorMessage = 'Mail versturen mislukt voor ' . $company . ': ' . $exception->getMessage();
        }
    }
}

function is_default_for_company(string $company, string $email, array $recipientSettingsByEmail): bool
{
    $column = report_mail_db_company_column($company);
    if ($column === null) {
        return false;
    }

    $settings = $recipientSettingsByEmail[strtolower($email)] ?? null;
    if (!is_array($settings)) {
        return false;
    }

    return ((int) ($settings[$column] ?? 0)) === 1;
}

function is_checked_for_company(string $company, string $email, array $recipientSettingsByEmail, array $submittedRecipientsByCompany): bool
{
    if (isset($submittedRecipientsByCompany[$company])) {
        return in_array($email, $submittedRecipientsByCompany[$company], true);
    }

    return is_default_for_company($company, $email, $recipientSettingsByEmail);
}

function sort_users_for_company(array $users, string $company, array $recipientSettingsByEmail): array
{
    usort($users, function (string $left, string $right) use ($company, $recipientSettingsByEmail): int {
        $leftDefault = is_default_for_company($company, $left, $recipientSettingsByEmail) ? 1 : 0;
        $rightDefault = is_default_for_company($company, $right, $recipientSettingsByEmail) ? 1 : 0;

        if ($leftDefault !== $rightDefault) {
            return $rightDefault <=> $leftDefault;
        }

        return strnatcasecmp($left, $right);
    });

    return $users;
}

?><!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mailrapportage</title>
    <style>
        :root {
            --bg: #f6f3ef;
            --ink: #1f2a2e;
            --muted: #5a6a70;
            --line: #d6d0c8;
            --accent: #254f6e;
            --danger: #b42318;
            --panel: #ffffff;
            --ok: #1f7a3f;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 26px 20px 40px;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--ink);
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .back-link {
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            color: var(--ink);
            text-decoration: none;
            padding: 8px 12px;
            font-size: 14px;
        }

        .message {
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 14px;
            border: 1px solid var(--line);
            background: var(--panel);
        }

        .message.error {
            color: var(--danger);
            border-color: color-mix(in srgb, var(--danger) 45%, var(--line) 55%);
        }

        .message.success {
            color: var(--ok);
            border-color: color-mix(in srgb, var(--ok) 45%, var(--line) 55%);
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 14px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
        }

        .card h2 {
            margin: 0 0 10px;
            font-size: 20px;
        }

        .last-sent {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 10px;
        }

        .user-list {
            border: 1px solid #ebe4db;
            border-radius: 8px;
            overflow: auto;
            padding: 8px 10px;
            margin-bottom: 12px;
            background: #fcfaf7;
        }

        .user-item {
            display: block;
            margin: 6px 0;
            font-size: 14px;
        }

        .user-select-actions {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
        }

        .user-select-button {
            font-size: 12px;
            padding: 5px 8px;
        }

        .last-recipient-mark {
            display: inline-block;
            margin-left: 6px;
            color: var(--ok);
            font-weight: 700;
            cursor: help;
        }

        .actions {
            display: flex;
            justify-content: flex-end;
        }

        button {
            font-size: 14px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--line);
            background: var(--panel);
            color: var(--ink);
            cursor: pointer;
        }

        button:hover {
            border-color: var(--accent);
            color: var(--accent);
        }
    </style>
</head>

<body>
    <header>
        <h1>Mailrapportage</h1>
        <div class="header-actions">
            <a class="back-link" href="mail_recipients.php">Ontvangers beheren</a>
            <a class="back-link" href="index.php">Terug naar overzicht</a>
        </div>
    </header>

    <?php if ($errorMessage !== ''): ?>
        <div class="message error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="message success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <div class="cards">
        <?php foreach ($companies as $company): ?>
            <?php
            $companyHistory = $history[$company] ?? null;
            $lastSentAt = '';
            $lastSentBy = '';
            $lastRecipients = [];
            $companyUsers = sort_users_for_company($allUsers, $company, $recipientSettingsByEmail);
            if (is_array($companyHistory)) {
                $lastSentAtRaw = (string) ($companyHistory['last_sent_at'] ?? '');
                $lastSentBy = (string) ($companyHistory['last_sent_by'] ?? '');
                $lastRecipientsRaw = $companyHistory['recipients'] ?? [];
                if (is_array($lastRecipientsRaw)) {
                    $lastRecipients = normalize_recipients($lastRecipientsRaw);
                }
                if ($lastSentAtRaw !== '') {
                    try {
                        $lastSentAt = (new DateTime($lastSentAtRaw))->format('d-m-Y H:i');
                    } catch (Throwable $e) {
                        $lastSentAt = $lastSentAtRaw;
                    }
                }
            }
            ?>
            <section class="card">
                <h2><?= htmlspecialchars($company) ?></h2>
                <div class="last-sent">
                    <?php if ($lastSentAt !== ''): ?>
                        Laatst verstuurd: <?= htmlspecialchars($lastSentAt) ?> door
                        <?= htmlspecialchars($lastSentBy !== '' ? $lastSentBy : 'onbekend') ?>
                    <?php else: ?>
                        Laatst verstuurd: nog niet verstuurd via deze pagina
                    <?php endif; ?>
                </div>

                <form method="post">
                    <input type="hidden" name="action" value="send_company">
                    <input type="hidden" name="company" value="<?= htmlspecialchars($company) ?>">

                    <div class="user-select-actions">
                        <button type="button" class="user-select-button" data-select="all">Selecteer iedereen</button>
                        <button type="button" class="user-select-button" data-select="none">Selecteer niemand</button>
                        <button type="button" class="user-select-button" data-select="default">Selecteer standaard</button>
                    </div>

                    <div class="user-list">
                        <?php foreach ($companyUsers as $email): ?>
                            <?php
                            $checked = is_checked_for_company($company, $email, $recipientSettingsByEmail, $submittedRecipientsByCompany);
                            $wasLastRecipient = in_array($email, $lastRecipients, true);
                            $isDefaultForCompany = is_default_for_company($company, $email, $recipientSettingsByEmail);
                            ?>
                            <label class="user-item">
                                <input type="checkbox" name="recipients[]" value="<?= htmlspecialchars($email) ?>"
                                    data-default="<?= $isDefaultForCompany ? '1' : '0' ?>" <?= $checked ? 'checked' : '' ?>>
                                <?= htmlspecialchars($email) ?>
                                <?php if ($wasLastRecipient): ?>
                                    <span class="last-recipient-mark"
                                        title="Deze ontvanger heeft de vorige keer bij mailen de PDF ontvangen.">✓</span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="actions">
                        <button type="submit">Mail versturen</button>
                    </div>
                </form>
            </section>
        <?php endforeach; ?>
    </div>

    <script>
        (function ()
        {
            const forms = document.querySelectorAll('.card form');

            forms.forEach((form) =>
            {
                const recipientCheckboxes = form.querySelectorAll('input[type="checkbox"][name="recipients[]"]');
                const selectAllButton = form.querySelector('button[data-select="all"]');
                const selectNoneButton = form.querySelector('button[data-select="none"]');
                const selectDefaultButton = form.querySelector('button[data-select="default"]');

                if (selectAllButton)
                {
                    selectAllButton.addEventListener('click', () =>
                    {
                        recipientCheckboxes.forEach((checkbox) =>
                        {
                            checkbox.checked = true;
                        });
                    });
                }

                if (selectNoneButton)
                {
                    selectNoneButton.addEventListener('click', () =>
                    {
                        recipientCheckboxes.forEach((checkbox) =>
                        {
                            checkbox.checked = false;
                        });
                    });
                }

                if (selectDefaultButton)
                {
                    selectDefaultButton.addEventListener('click', () =>
                    {
                        recipientCheckboxes.forEach((checkbox) =>
                        {
                            checkbox.checked = checkbox.dataset.default === '1';
                        });
                    });
                }
            });
        })();
    </script>
</body>

</html>