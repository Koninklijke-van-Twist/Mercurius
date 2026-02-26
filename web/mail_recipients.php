<?php
require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/mail_recipients_db.php';

$errorMessage = '';
$successMessage = '';

try {
    initialize_report_mail_recipient_db(
        is_array($defaultMailList ?? null) ? $defaultMailList : []
    );
} catch (Throwable $exception) {
    $errorMessage = 'SQLite configuratie kon niet worden geladen: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage === '') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'add') {
            $email = trim((string) ($_POST['email'] ?? ''));
            $kvt = isset($_POST['kvt']);
            $hvt = isset($_POST['hvt']);
            $gas = isset($_POST['gas']);

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Het e-mailadres lijkt niet geldig.');
            }

            add_report_mail_recipient($email, $kvt, $hvt, $gas);
            $successMessage = 'Ontvanger toegevoegd.';
        } elseif ($action === 'update') {
            $email = trim((string) ($_POST['email'] ?? ''));
            $kvt = isset($_POST['kvt']);
            $hvt = isset($_POST['hvt']);
            $gas = isset($_POST['gas']);

            update_report_mail_recipient_flags($email, $kvt, $hvt, $gas);
            $successMessage = 'Ontvanger ' . $email . ' is bijgewerkt.';
        } elseif ($action === 'delete') {
            $email = trim((string) ($_POST['email'] ?? ''));
            delete_report_mail_recipient($email);
            $successMessage = 'Ontvanger verwijderd.';
        } else {
            throw new InvalidArgumentException('Ongeldige actie.');
        }
    } catch (Throwable $exception) {
        $isDuplicate = strpos(strtolower($exception->getMessage()), 'unique') !== false;
        if ($isDuplicate) {
            $errorMessage = 'Dit e-mailadres bestaat al.';
        } else {
            $errorMessage = $exception->getMessage();
        }
    }
}

$rows = [];
if ($errorMessage === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $rows = get_report_mail_recipients();
    } catch (Throwable $exception) {
        $errorMessage = 'Ontvangers laden mislukt: ' . $exception->getMessage();
    }
}
?><!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mailontvangers beheren</title>
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

        h1 {
            margin: 0;
            font-size: 28px;
        }

        .header-actions {
            display: flex;
            gap: 8px;
        }

        .button-link,
        button {
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            color: var(--ink);
            text-decoration: none;
            padding: 8px 12px;
            font-size: 14px;
            cursor: pointer;
        }

        .button-link:hover,
        button:hover {
            border-color: var(--accent);
            color: var(--accent);
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

        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border-bottom: 1px solid #ebe4db;
            padding: 10px 8px;
            text-align: left;
            font-size: 14px;
            vertical-align: middle;
        }

        th {
            color: var(--muted);
            font-weight: 700;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .row-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .check-cols {
            width: 72px;
            text-align: center;
        }

        .email-cell {
            min-width: 260px;
        }

        dialog {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 16px;
            width: min(460px, calc(100% - 30px));
        }

        dialog::backdrop {
            background: rgba(0, 0, 0, 0.35);
        }

        .field {
            margin-bottom: 10px;
        }

        label {
            font-size: 14px;
        }

        input[type="email"] {
            width: 100%;
            margin-top: 4px;
            font-size: 14px;
            padding: 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
        }

        .inline-checks {
            display: flex;
            gap: 14px;
            margin: 10px 0 12px;
        }

        .warning {
            color: var(--danger);
            font-size: 13px;
            margin-bottom: 8px;
            min-height: 18px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .empty {
            font-size: 14px;
            color: var(--muted);
            padding: 14px 0;
        }
    </style>
</head>

<body>
    <header>
        <h1>Mailontvangers</h1>
        <div class="header-actions">
            <button type="button" id="open-add-modal">E-mailadres toevoegen</button>
            <a class="button-link" href="mail_report.php">Terug naar mailrapportage</a>
        </div>
    </header>

    <?php if ($errorMessage !== ''): ?>
        <div class="message error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="message success"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <section class="panel">
        <?php if (empty($rows)): ?>
            <div class="empty">Nog geen ontvangers ingesteld.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="email-cell">E-mail</th>
                        <th class="check-cols">KVT</th>
                        <th class="check-cols">HVT</th>
                        <th class="check-cols">Gas</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php $email = (string) ($row['email'] ?? ''); ?>
                        <?php $formId = 'update-' . md5($email); ?>
                        <tr>
                            <td class="email-cell"><?= htmlspecialchars($email) ?></td>
                            <td class="check-cols">
                                <input type="checkbox" data-autosave="1" form="<?= htmlspecialchars($formId) ?>" name="kvt" value="1" <?= ((int) ($row['kvt'] ?? 0)) === 1 ? 'checked' : '' ?>>
                            </td>
                            <td class="check-cols">
                                <input type="checkbox" data-autosave="1" form="<?= htmlspecialchars($formId) ?>" name="hvt" value="1" <?= ((int) ($row['hvt'] ?? 0)) === 1 ? 'checked' : '' ?>>
                            </td>
                            <td class="check-cols">
                                <input type="checkbox" data-autosave="1" form="<?= htmlspecialchars($formId) ?>" name="gas" value="1" <?= ((int) ($row['gas'] ?? 0)) === 1 ? 'checked' : '' ?>>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <form method="post" id="<?= htmlspecialchars($formId) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                                    </form>
                                    <form method="post"
                                        onsubmit="return confirm('Weet je zeker dat je dit e-mailadres wilt verwijderen?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                                        <button type="submit">Verwijderen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <dialog id="add-modal">
        <form method="post" id="add-form">
            <input type="hidden" name="action" value="add">
            <div class="field">
                <label for="new-email">E-mailadres</label>
                <input id="new-email" type="email" name="email" required>
            </div>

            <div class="inline-checks">
                <label><input type="checkbox" name="kvt" value="1"> KVT</label>
                <label><input type="checkbox" name="hvt" value="1"> HVT</label>
                <label><input type="checkbox" name="gas" value="1"> Gas</label>
            </div>

            <div class="warning" id="email-warning"></div>

            <div class="modal-actions">
                <button type="button" id="close-add-modal">Annuleren</button>
                <button type="submit" id="submit-add">Toevoegen</button>
            </div>
        </form>
    </dialog>

    <script>
        (function ()
        {
            const modal = document.getElementById('add-modal');
            const openButton = document.getElementById('open-add-modal');
            const closeButton = document.getElementById('close-add-modal');
            const emailInput = document.getElementById('new-email');
            const warning = document.getElementById('email-warning');
            const submitButton = document.getElementById('submit-add');
            const autoSaveCheckboxes = document.querySelectorAll('input[type="checkbox"][data-autosave="1"]');

            const updateEmailWarning = () =>
            {
                const value = (emailInput.value || '').trim();
                if (value === '')
                {
                    warning.textContent = '';
                    submitButton.disabled = false;
                    return;
                }

                const looksValid = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i.test(value);
                if (!looksValid)
                {
                    warning.textContent = 'Waarschuwing: dit e-mailadres lijkt niet geldig.';
                    submitButton.disabled = true;
                }
                else
                {
                    warning.textContent = '';
                    submitButton.disabled = false;
                }
            };

            openButton.addEventListener('click', () =>
            {
                emailInput.value = '';
                warning.textContent = '';
                submitButton.disabled = false;
                if (typeof modal.showModal === 'function')
                {
                    modal.showModal();
                }
            });

            closeButton.addEventListener('click', () =>
            {
                if (typeof modal.close === 'function')
                {
                    modal.close();
                }
            });

            emailInput.addEventListener('input', updateEmailWarning);
            emailInput.addEventListener('blur', updateEmailWarning);

            autoSaveCheckboxes.forEach((checkbox) =>
            {
                checkbox.addEventListener('change', () =>
                {
                    const formId = checkbox.getAttribute('form');
                    if (!formId)
                    {
                        return;
                    }

                    const form = document.getElementById(formId);
                    if (form)
                    {
                        form.submit();
                    }
                });
            });
        })();
    </script>
</body>

</html>