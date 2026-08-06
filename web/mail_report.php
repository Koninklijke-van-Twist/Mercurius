<?php
require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/report_mail_lib.php';
require_once __DIR__ . '/mail_recipients_db.php';

$companies = [];

$allUsers = [];
$recipientSettingsByEmail = [];
$attachmentSettingsByCompany = [];
$currentUserEmail = (string) ($_SESSION['user']['email'] ?? '');

$history = load_report_mail_history();

$successMessage = '';
$errorMessage = '';

try {
    $companyDiscovery = auth_discover_companies();
    $companies = $companyDiscovery['companies'];
    if (empty($companies)) {
        throw new RuntimeException('Geen bedrijven gevonden in de actieve environments.');
    }
} catch (Throwable $exception) {
    $errorMessage = 'Bedrijven ophalen mislukt: ' . $exception->getMessage();
}

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
            'germany' => (int) ($row['germany'] ?? 0),
        ];
    }

    foreach ($companies as $company) {
        $attachmentSettingsByCompany[$company] = get_report_mail_attachments_for_company($company);
    }
} catch (Throwable $exception) {
    $errorMessage = 'SQLite configuratie kon niet worden geladen: ' . $exception->getMessage();
}

sort($allUsers, SORT_NATURAL | SORT_FLAG_CASE);

$submittedRecipientsByCompany = [];
$submittedPartyModeByCompany = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $company = trim((string) ($_POST['company'] ?? ''));
    $selectedRecipients = $_POST['recipients'] ?? [];
    if (!is_array($selectedRecipients)) {
        $selectedRecipients = [];
    }

    $submittedRecipientsByCompany[$company] = normalize_recipients($selectedRecipients);

    $postedAttachmentSettings = [
        'pdf_report' => isset($_POST['attach_pdf_report']),
        'csv_rapport' => isset($_POST['attach_csv_rapport']),
        'csv_stambestand' => isset($_POST['attach_csv_stambestand']),
        'csv_openstaande' => isset($_POST['attach_csv_openstaande']),
        'csv_betaalde' => isset($_POST['attach_csv_betaalde']),
    ];

    $postedPartyMode = report_normalize_party_mode($_POST['party_mode'] ?? 'debiteuren');
    $submittedPartyModeByCompany[$company] = $postedPartyMode;

    if (in_array($company, $companies, true)) {
        $attachmentSettingsByCompany[$company] = [
            'pdf_report' => $postedAttachmentSettings['pdf_report'] ? 1 : 0,
            'csv_rapport' => $postedAttachmentSettings['csv_rapport'] ? 1 : 0,
            'csv_stambestand' => $postedAttachmentSettings['csv_stambestand'] ? 1 : 0,
            'csv_openstaande' => $postedAttachmentSettings['csv_openstaande'] ? 1 : 0,
            'csv_betaalde' => $postedAttachmentSettings['csv_betaalde'] ? 1 : 0,
        ];
    }

    if ($action === 'send_company') {
        @ini_set('max_execution_time', '3600');
        if (function_exists('set_time_limit')) {
            @set_time_limit(3600);
        }
    }

    if ($action !== 'send_company' && $action !== 'save_attachments') {
        $errorMessage = 'Ongeldige actie.';
    } elseif (!in_array($company, $companies, true)) {
        $errorMessage = 'Ongeldig bedrijf geselecteerd.';
    } else {
        try {
            set_report_mail_attachments_for_company(
                $company,
                $postedAttachmentSettings['pdf_report'],
                $postedAttachmentSettings['csv_rapport'],
                $postedAttachmentSettings['csv_stambestand'],
                $postedAttachmentSettings['csv_openstaande'],
                $postedAttachmentSettings['csv_betaalde']
            );

            if ($action === 'save_attachments') {
                $successMessage = 'Bijlage-instellingen opgeslagen voor ' . $company . '.';
            } else {
                $result = send_company_report(
                    $reportMail,
                    $company,
                    $selectedRecipients,
                    $postedAttachmentSettings,
                    $postedPartyMode
                );
                record_report_mail_history($company, $currentUserEmail !== '' ? $currentUserEmail : 'onbekend', $result['recipients']);
                $history = load_report_mail_history();
                $recipientCount = count($result['recipients']);
                $successMessage = 'Mail verstuurd voor ' . $company . ' naar ' . $recipientCount . ' ontvanger(s).';
            }
        } catch (Throwable $exception) {
            $errorMessage = ($action === 'save_attachments' ? 'Opslaan mislukt voor ' : 'Mail versturen mislukt voor ')
                . $company . ': ' . $exception->getMessage();
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

        .sending-overlay {
            position: fixed;
            inset: 0;
            background: rgba(31, 42, 46, 0.72);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 18px;
        }

        .sending-overlay.active {
            display: flex;
        }

        .sending-panel {
            width: min(760px, 100%);
            max-height: 88vh;
            overflow: auto;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 18px 18px 14px;
            box-shadow: 0 20px 42px rgba(0, 0, 0, 0.22);
        }

        .sending-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .sending-spinner {
            width: 24px;
            height: 24px;
            border: 3px solid #d8e0e6;
            border-top-color: var(--accent);
            border-radius: 999px;
            animation: spin 1s linear infinite;
            flex: 0 0 auto;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .sending-title {
            margin: 0;
            font-size: 18px;
        }

        .sending-subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .sending-summary {
            margin-top: 8px;
            border-top: 1px solid var(--line);
            padding-top: 12px;
            font-size: 14px;
        }

        .sending-summary strong {
            display: inline-block;
            min-width: 88px;
        }

        .sending-list {
            margin: 6px 0 12px;
            padding-left: 18px;
        }

        .sending-list li {
            margin: 2px 0;
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

                    <?php
                    $attachmentSettings = $attachmentSettingsByCompany[$company] ?? report_mail_attachment_defaults();
                    ?>

                    <fieldset class="user-list" style="margin-bottom:10px;">
                        <legend style="padding:0 4px; font-size:13px; color:var(--muted);">Rapporttype</legend>
                        <?php
                        $selectedPartyMode = report_normalize_party_mode(
                            $submittedPartyModeByCompany[$company] ?? 'debiteuren'
                        );
                        ?>
                        <label class="user-item">
                            <input type="radio" name="party_mode" value="debiteuren" <?= $selectedPartyMode === 'debiteuren' ? 'checked' : '' ?>>
                            Debiteuren
                        </label>
                        <label class="user-item">
                            <input type="radio" name="party_mode" value="crediteuren" <?= $selectedPartyMode === 'crediteuren' ? 'checked' : '' ?>>
                            Crediteuren
                        </label>
                    </fieldset>

                    <fieldset class="user-list" style="margin-bottom:10px;"
                        data-attachment-company="<?= htmlspecialchars($company) ?>">
                        <legend style="padding:0 4px; font-size:13px; color:var(--muted);">Bijlagen</legend>
                        <label class="user-item">
                            <input type="checkbox" name="attach_pdf_report" <?= ((int) ($attachmentSettings['pdf_report'] ?? 0)) === 1 ? 'checked' : '' ?>>
                            PDF rapportage
                        </label>
                        <label class="user-item">
                            <input type="checkbox" name="attach_csv_rapport" <?= ((int) ($attachmentSettings['csv_rapport'] ?? 1)) === 1 ? 'checked' : '' ?>>
                            CSV - Rapport openstaande posten
                        </label>
                        <label class="user-item">
                            <input type="checkbox" name="attach_csv_stambestand" <?= ((int) ($attachmentSettings['csv_stambestand'] ?? 0)) === 1 ? 'checked' : '' ?>>
                            CSV - Stambestand
                        </label>
                        <label class="user-item">
                            <input type="checkbox" name="attach_csv_openstaande" <?= ((int) ($attachmentSettings['csv_openstaande'] ?? 0)) === 1 ? 'checked' : '' ?>>
                            CSV - Openstaande facturen
                        </label>
                        <label class="user-item">
                            <input type="checkbox" name="attach_csv_betaalde" <?= ((int) ($attachmentSettings['csv_betaalde'] ?? 0)) === 1 ? 'checked' : '' ?>>
                            CSV - Betaalde facturen
                        </label>
                    </fieldset>

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
                                        title="Deze ontvanger heeft de vorige keer deze rapportmail ontvangen.">✓</span>
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

    <div class="sending-overlay" id="sending-overlay" aria-live="polite" aria-busy="true">
        <div class="sending-panel">
            <div class="sending-header">
                <span class="sending-spinner" aria-hidden="true"></span>
                <div>
                    <h3 class="sending-title">Mail wordt verstuurd...</h3>
                    <p class="sending-subtitle">Dit kan even duren bij meerdere CSV-bijlagen. Sluit dit venster niet.</p>
                </div>
            </div>
            <div class="sending-summary" id="sending-summary"></div>
        </div>
    </div>

    <script>
        (function ()
        {
            const forms = document.querySelectorAll('.card form');
            const sendingOverlay = document.getElementById('sending-overlay');
            const sendingSummary = document.getElementById('sending-summary');

            function escapeHtml(value)
            {
                const text = String(value ?? '');
                return text
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function selectedRecipientValues(form)
            {
                return Array.from(form.querySelectorAll('input[type="checkbox"][name="recipients[]"]:checked'))
                    .map((checkbox) => checkbox.value)
                    .filter(Boolean);
            }

            function selectedAttachmentLabels(form)
            {
                return Array.from(form.querySelectorAll('fieldset[data-attachment-company] label'))
                    .map((label) =>
                    {
                        const checkbox = label.querySelector('input[type="checkbox"]');
                        if (!checkbox || !checkbox.checked)
                        {
                            return '';
                        }

                        return label.textContent.replace(/\s+/g, ' ').trim();
                    })
                    .filter(Boolean);
            }

            function renderList(items)
            {
                if (items.length === 0)
                {
                    return '<p>- geen -</p>';
                }

                return '<ul class="sending-list">' + items.map((item) => '<li>' + escapeHtml(item) + '</li>').join('') + '</ul>';
            }

            function showSendingOverlay(form)
            {
                if (!sendingOverlay || !sendingSummary)
                {
                    return;
                }

                const company = (form.querySelector('input[name="company"]')?.value || '').trim();
                const recipients = selectedRecipientValues(form);
                const attachments = selectedAttachmentLabels(form);

                sendingSummary.innerHTML =
                    '<p><strong>Bedrijf:</strong> ' + escapeHtml(company || '-') + '</p>'
                    + '<p><strong>Bijlagen:</strong></p>'
                    + renderList(attachments)
                    + '<p><strong>Ontvangers (' + recipients.length + '):</strong></p>'
                    + renderList(recipients);

                sendingOverlay.classList.add('active');
            }

            forms.forEach((form) =>
            {
                const recipientCheckboxes = form.querySelectorAll('input[type="checkbox"][name="recipients[]"]');
                const selectAllButton = form.querySelector('button[data-select="all"]');
                const selectNoneButton = form.querySelector('button[data-select="none"]');
                const selectDefaultButton = form.querySelector('button[data-select="default"]');
                const actionField = form.querySelector('input[name="action"]');

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

                form.addEventListener('submit', () =>
                {
                    if ((actionField?.value || '') !== 'send_company')
                    {
                        return;
                    }

                    showSendingOverlay(form);
                });
            });
        })();

        // Auto-save attachment checkboxes on change
        document.querySelectorAll('fieldset[data-attachment-company]').forEach((fieldset) =>
        {
            fieldset.querySelectorAll('input[type="checkbox"]').forEach((checkbox) =>
            {
                checkbox.addEventListener('change', () =>
                {
                    const company = fieldset.dataset.attachmentCompany;
                    const data = new FormData();
                    data.append('action', 'save_attachments');
                    data.append('company', company);
                    fieldset.querySelectorAll('input[type="checkbox"]').forEach((cb) =>
                    {
                        if (cb.checked) data.append(cb.name, '1');
                    });
                    fetch('', { method: 'POST', body: data }).catch(() => { });
                });
            });
        });
    </script>
</body>

</html>