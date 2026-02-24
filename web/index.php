<?php
require_once __DIR__ . '/functions.php';

$isMailReport = false;
// Printvriendelijke modus forceren via ?printfriendly=true
if ((isset($_GET['printfriendly']) && $_GET['printfriendly'] == 'true') || (isset($isMailReport) && $isMailReport)) {
    ob_start();
    $isMailReport = true;
}

require __DIR__ . "/auth.php";
require_once __DIR__ . "/logincheck.php";
require_once __DIR__ . "/odata.php";

$companies = [
    "Koninklijke van Twist",
    "Hunter van Twist",
    "KVT Gas",
];

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
}

$filter = $_GET['filter'] ?? 'overdue';
if (!in_array($filter, ['all', 'overdue'], true)) {
    $filter = 'overdue';
}

$search = trim((string) ($_GET['search'] ?? ''));
$searchLower = strtolower($search);
$openFilter = $_GET['open_filter'] ?? 'open';
if (!in_array($openFilter, ['open', 'closed', 'both'], true)) {
    $openFilter = 'open';
}

$dueBeforeRaw = trim((string) ($_GET['due_before'] ?? ''));
$dueBeforeDate = null;
if ($dueBeforeRaw !== '') {
    $dueBeforeDate = DateTime::createFromFormat('Y-m-d', $dueBeforeRaw) ?: null;
}

$memoTooltipTerms = [
    'Z010' => 'Vooruitbetalen',
];


$customerUrl = odata_company_url(
    $environment,
    $selectedCompany,
    'AppCustomerCard',
    [
        '$select' => 'No,Name,City,E_Mail,Phone_No',
    ]
);

$entriesFilterParts = [];
if ($openFilter === 'open') {
    $entriesFilterParts[] = 'Open eq true';
} elseif ($openFilter === 'closed') {
    $entriesFilterParts[] = 'Open eq false';
}

$entriesParams = [
    '$select' => implode(',', [
        'Entry_No',
        'Posting_Date',
        'Document_Date',
        'Document_No',
        'Customer_No',
        'Customer_Name',
        'Description',
        'Salesperson_Code',
        'Global_Dimension_1_Code',
        'Global_Dimension_2_Code',
        'Currency_Code',
        'Remaining_Amt_LCY',
        'Remaining_Amount',
        'Due_Date',
        'Closed_at_Date',
        'External_Document_No',
        'Your_Reference',
        'Open',
        'KVT_Memo',
    ]),
];

if (!empty($entriesFilterParts)) {
    $entriesParams['$filter'] = implode(' and ', $entriesFilterParts);
}

$entriesUrl = odata_company_url(
    $environment,
    $selectedCompany,
    'Customer_Ledger_Entries',
    $entriesParams
);

$customers = odata_get_all($customerUrl, $auth, 3600);
$entries = odata_get_all($entriesUrl, $auth, 600);

$customerIndex = [];
foreach ($customers as $customer) {
    if (!isset($customer['No'])) {
        continue;
    }
    $customerIndex[(string) $customer['No']] = $customer;
}

$selectedCustomerNo = trim((string) ($_GET['customer_no'] ?? ''));
$customerOptions = array_keys($customerIndex);
sort($customerOptions, SORT_NATURAL);
if ($selectedCustomerNo !== '' && !in_array($selectedCustomerNo, $customerOptions, true)) {
    $customerIndex[$selectedCustomerNo] = ['No' => $selectedCustomerNo, 'Name' => ''];
    array_unshift($customerOptions, $selectedCustomerNo);
}

$today = new DateTime('today');
$todayFormatted = format_date_nl($today->format('Y-m-d'), false);
$groups = [];

foreach ($entries as $entry) {
    if (!isset($entry['Customer_No'])) {
        continue;
    }

    if ($selectedCustomerNo !== '' && (string) $entry['Customer_No'] !== $selectedCustomerNo) {
        continue;
    }

    $amount = pick_amount($entry);
    if (abs($amount) < 0.00001) {
        continue;
    }

    $closedDate = null;
    if (!$entry['Open'] && isset($entry['Closed_at_Date'])) {
        $closedDate = new DateTime($entry['Closed_at_Date']);
    }

    $dueDate = null;
    $daysOverdue = 0;
    if (!empty($entry['Due_Date'])) {
        $dueDate = new DateTime($entry['Due_Date']);

        $dateToCheck = $closedDate ?? $today;

        if ($dueDate < $dateToCheck) {
            $daysOverdue = (int) $dueDate->diff($dateToCheck)->format('%a');
        }
    }

    if ($dueBeforeDate !== null) {
        if ($dueDate === null || $dueDate >= $dueBeforeDate) {
            continue;
        }
    }

    if ($filter === 'overdue' && $daysOverdue === 0) {
        continue;
    }

    if ($searchLower !== '') {
        $customerNo = (string) $entry['Customer_No'];
        $customerInfo = $customerIndex[$customerNo] ?? [];
        $searchHaystack = implode(' ', [
            (string) ($entry['Document_No'] ?? ''),
            (string) ($entry['Entry_No'] ?? ''),
            (string) ($entry['Customer_No'] ?? ''),
            (string) ($entry['Customer_Name'] ?? ''),
            (string) ($entry['Description'] ?? ''),
            (string) ($entry['Salesperson_Code'] ?? ''),
            (string) ($entry['Global_Dimension_1_Code'] ?? ''),
            (string) ($entry['Global_Dimension_2_Code'] ?? ''),
            (string) ($entry['Currency_Code'] ?? ''),
            (string) ($entry['External_Document_No'] ?? ''),
            (string) ($entry['Your_Reference'] ?? ''),
            (string) ($entry['KVT_Memo'] ?? ''),
            (string) ($customerInfo['No'] ?? ''),
            (string) ($customerInfo['Name'] ?? ''),
            (string) ($customerInfo['City'] ?? ''),
            (string) ($customerInfo['Phone_No'] ?? ''),
            (string) ($customerInfo['E_Mail'] ?? ''),
        ]);

        if (strpos(strtolower($searchHaystack), $searchLower) === false) {
            continue;
        }
    }

    $customerNo = (string) $entry['Customer_No'];
    if (!isset($groups[$customerNo])) {
        $groups[$customerNo] = [
            'customer' => $customerIndex[$customerNo] ?? [
                'No' => $customerNo,
                'Name' => (string) ($entry['Customer_Name'] ?? ''),
                'City' => '',
                'Phone_No' => '',
                'E_Mail' => '',
            ],
            'entries' => [],
            'total' => 0.0,
            'totals_by_currency' => [],
        ];
    }

    $entry['_amount'] = $amount;
    $entry['_amount_lcy'] = isset($entry['Remaining_Amt_LCY']) ? (float) $entry['Remaining_Amt_LCY'] : null;
    $entry['_days_overdue'] = $daysOverdue;
    $entry['_due_date'] = $dueDate;
    $entry['_closed_at_date'] = $closedDate;
    $entry['_currency_code'] = (string) ($entry['Currency_Code'] ?? '');

    $groups[$customerNo]['entries'][] = $entry;
    $groups[$customerNo]['total'] += $amount;
    $totalCurrency = $entry['_currency_code'] !== '' ? $entry['_currency_code'] : 'EUR';
    if (!isset($groups[$customerNo]['totals_by_currency'][$totalCurrency])) {
        $groups[$customerNo]['totals_by_currency'][$totalCurrency] = 0.0;
    }
    $groups[$customerNo]['totals_by_currency'][$totalCurrency] += $amount;
}

ksort($groups);

$baseQueryParams = [
    'company' => $selectedCompany,
    'filter' => $filter,
    'search' => $search,
    'open_filter' => $openFilter,
    'due_before' => $dueBeforeRaw,
];



if (isset($isMailReport) && $isMailReport) {
    // Forceer print CSS inline voor mail
    echo '<style>@media print { body { background: #fff !important; } } body { background: #fff !important; } </style>';
}
?><!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Openstaande posten debiteuren</title>
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <style>
        :root {
            --bg: #f6f3ef;
            --ink: #1f2a2e;
            --muted: #5a6a70;
            --line: #d6d0c8;
            --accent: #254f6e;
            --highlight: #ffe2a6;
            --danger: #e15555;
            --overdue: #f6d8d8;
            --negative: #dff2e2;
            --panel: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 32px 20px 60px;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #f9f4ef 0%, #eef3f6 50%, #f7efe7 100%);
            color: var(--ink);
        }

        header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--bg);
            padding: 12px 0;
            border-bottom: 1px solid var(--line);
        }

        h1 {
            margin: 0;
            font-size: 28px;
            letter-spacing: 0.4px;
        }

        .company-name,
        .customer-no,
        .due-date-cell,
        .due-date-head {
            transition: background 0.15s ease, box-shadow 0.15s ease;
        }

        .company-name,
        .customer-no {
            padding: 0 4px;
            border-radius: 4px;
            display: inline-block;
        }

        .highlight-company .company-name,
        .highlight-customers .customer-no,
        .highlight-due-date .due-date-cell,
        .highlight-due-date .due-date-head {
            background: var(--highlight);
            box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.08);
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .print-date {
            display: none;
            font-size: 14px;
            color: var(--muted);
        }

        select,
        input,
        button {
            font-size: 14px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--line);
            background: var(--panel);
            color: var(--ink);
        }

        .button-link {
            display: inline-block;
            font-size: 14px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--line);
            background: var(--panel);
            color: var(--ink);
            text-decoration: none;
            line-height: normal;
        }

        button {
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }

        .button-link:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.08);
        }

        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.08);
        }

        .filter-active {
            border-color: var(--accent);
            color: var(--accent);
            font-weight: 600;
        }

        .is-disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .is-disabled:hover {
            transform: none;
            box-shadow: none;
        }

        .due-before-warning {
            border-color: var(--danger);
            box-shadow: 0 0 0 2px rgba(225, 85, 85, 0.2);
        }

        .shake {
            animation: shake 0.3s ease;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-3px);
            }

            50% {
                transform: translateX(3px);
            }

            75% {
                transform: translateX(-2px);
            }
        }

        .group {
            background: var(--panel);
            border-radius: 12px;
            box-shadow: 0 14px 30px rgba(20, 30, 40, 0.08);
            margin-bottom: 26px;
            overflow: hidden;
        }

        .group hr {
            margin: 0;
            border: none;
            border-top: 4px solid var(--accent);
        }

        .customer-header {
            padding: 3px 6px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px 20px;
            font-weight: 600;
            background: #f0ede8;
            align-items: center;
            font-size: 12px;
        }

        .customer-header span {
            color: var(--muted);
            font-weight: 500;
        }

        .customer-header a {
            color: var(--accent);
            text-decoration: none;
        }

        .memo-term {
            text-decoration-line: underline;
            text-decoration-style: dotted;
            text-decoration-color: color-mix(in srgb, var(--highlight) 55%, var(--danger) 45%);
            text-decoration-thickness: 2px;
            text-underline-offset: 2px;
            cursor: help;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        col.col-bkst {
            width: 80px;
        }

        col.col-aangemaakt {
            width: 67px;
        }

        col.col-vervalt {
            width: 67px;
        }

        col.col-verschuldigd {
            width: 100px;
        }

        col.col-dagen {
            width: 40px;
        }

        col.col-omschrijving {
            width: 190px;
        }

        col.col-afd {
            width: 95px;
        }

        col.col-notities {
            width: 100%;
        }


        thead th {
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
            padding: 6px 5px;
            border-bottom: 1px solid var(--line);
        }

        tbody td {
            padding: 3px 5px;
            border-bottom: 1px solid #eee6dd;
            vertical-align: top;
            font-size: 11px;
        }

        td[data-label="Notities"] {
            font-size: 11px;
            white-space: pre-wrap;
            overflow-wrap: break-word;
            word-break: normal;
        }

        td[data-label="Omschrijving"] {
            font-size: 11px;
            white-space: pre-wrap;
            overflow-wrap: break-word;
            word-break: normal;
        }

        tbody tr.row-overdue {
            background: var(--overdue);
        }

        tbody tr.row-negative {
            background: var(--negative);
        }

        .amount {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .total-row td {
            font-weight: 700;
            background: #f7f3ee;
        }

        .currency-missing {
            background: #fff4b8;
        }

        .muted {
            color: var(--muted);
            font-size: 11px;
        }

        .empty {
            padding: 18px 20px;
            color: var(--muted);
        }

        <?php if (!$isMailReport): ?>
            @media print {

            <?php endif; ?>
            @page {
                margin: 10mm;
            }

            body {
                padding: 0 !important;
                margin: 0 !important;
                background: #fff !important;
            }

            header {
                position: static !important;
                top: auto !important;
                z-index: auto !important;
            }

            table {
                width: 100% !important;
                max-width: 100% !important;
            }

            .group {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                box-shadow: none !important;
            }

            td[data-label="Notities"],
            td[data-label="Notities"] a,
            td[data-label="Notities"] .memo-term {
                white-space: normal !important;
                overflow-wrap: break-word;
                word-break: normal;
            }

            .controls {
                display: none !important;
            }

            a {
                color: inherit !important;
                text-decoration: none !important;
            }

            .memo-term {
                text-decoration: none !important;
                cursor: default;
            }

            .print-date {
                display: block !important;
            }

            .group {
                break-inside: avoid-page;
                page-break-inside: avoid;
            }

            .customer-header,
            thead {
                break-after: avoid-page;
                page-break-after: avoid;
            }

            <?php if (!$isMailReport): ?>
            }

        <?php endif; ?>

        @media (max-width: 900px) {
            body {
                padding: 24px 14px 50px;
            }

            thead {
                display: none;
            }

            table,
            tbody,
            tr,
            td {
                display: block;
                width: 100%;
            }

            tbody tr {
                border-bottom: 1px solid var(--line);
            }

            tbody td {
                border: none;
                padding: 8px 16px;
            }

            tbody td::before {
                content: attr(data-label);
                display: block;
                font-size: 11px;
                text-transform: uppercase;
                color: var(--muted);
                letter-spacing: 0.4px;
                margin-bottom: 4px;
            }

            .amount {
                text-align: left;
            }
        }
    </style>
</head>

<body>
    <header>
        <h1>Openstaande posten debiteuren - <span class="company-name"><?= $selectedCompany ?></span></h1>
        <div class="print-date">Datum: <?= htmlspecialchars($todayFormatted) ?></div>
        <?php if (!$isMailReport): ?>
            <form class="controls" method="get">
                <?= injectTimerHtml([
                    'statusUrl' => 'odata.php?action=cache_status',
                    'title' => 'Cachebestanden',
                    'label' => 'Cache',
                    'css' => '{{root}} .odata-cache-widget{top:-23px;left:auto;right:0px;} {{root}} .odata-cache-popout{top:64px;left:auto;right:20px;}'
                ]) ?>
                <a class="button-link" href="mail_report.php">Mailrapportage</a>
                <label>
                    <select id="companySelect" name="company">
                        <?php foreach ($companies as $company): ?>
                            <option value="<?= htmlspecialchars($company) ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                                <?= htmlspecialchars($company) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <input type="date" id="dueBeforeInput" name="due_before" value="<?= htmlspecialchars($dueBeforeRaw) ?>"
                        max="<?= htmlspecialchars($today->format('Y-m-d')) ?>"
                        title="Toon posten met vervaldatum voor deze datum" />
                </label>
                <label>
                    <select id="customerSelect" name="customer_no">
                        <option value="">Alle debiteuren</option>
                        <?php foreach ($customerOptions as $customerNo): ?>
                            <?php $customerName = (string) ($customerIndex[$customerNo]['Name'] ?? ''); ?>
                            <option value="<?= htmlspecialchars($customerNo) ?>" <?= $customerNo === $selectedCustomerNo ? 'selected' : '' ?>>
                                <?= htmlspecialchars($customerNo . ($customerName !== '' ? ' - ' . $customerName : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                        placeholder="Zoek in alle tekst" />
                </label>
                <label>
                    <select name="open_filter">
                        <option value="open" <?= $openFilter === 'open' ? 'selected' : '' ?>>Enkel openstaand</option>
                        <option value="closed" <?= $openFilter === 'closed' ? 'selected' : '' ?>>Enkel gesloten</option>
                        <option value="both" <?= $openFilter === 'both' ? 'selected' : '' ?>>Beide</option>
                    </select>
                </label>
                <button id="filterAllButton" type="submit" name="filter" value="all"
                    class="<?= $filter === 'all' ? 'filter-active' : '' ?>">Alle
                    posten</button>
                <button type="submit" name="filter" value="overdue"
                    class="<?= $filter === 'overdue' ? 'filter-active' : '' ?>">Vervallen posten</button>
            </form>
        <?php endif; ?>
    </header>

    <?php if (empty($groups)): ?>
        <div class="group">
            <hr>
            <div class="empty">Geen posten gevonden voor deze selectie.</div>
        </div>
    <?php endif; ?>

    <?php foreach ($groups as $group): ?>
        <?php
        $customer = $group['customer'];
        $customerNoValue = (string) ($customer['No'] ?? '');
        $customerFilterParams = array_merge($baseQueryParams, ['customer_no' => $customerNoValue]);
        $customerLink = '?' . http_build_query($customerFilterParams, '', '&', PHP_QUERY_RFC3986);
        $phone = trim((string) ($customer['Phone_No'] ?? ''));
        $email = trim((string) ($customer['E_Mail'] ?? ''));
        $phoneLink = $phone !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $phone) : '';
        $mailLink = $email !== '' ? 'mailto:' . $email : '';
        ?>
        <section class="group">
            <hr>
            <div class="customer-header">
                <div>Debiteur: <a class="customer-no"
                        href="<?= htmlspecialchars($customerLink) ?>"><?= htmlspecialchars($customerNoValue) ?></a>&nbsp;
                </div>
                <div><?= htmlspecialchars((string) ($customer['Name'] ?? '')) ?>&nbsp;</div>
                <div><span>Woonplaats:</span> <?= htmlspecialchars((string) ($customer['City'] ?? '')) ?>&nbsp;</div>
                <div>
                    <span>Telefoon:</span>
                    <?php if ($phoneLink): ?>
                        <a href="<?= htmlspecialchars($phoneLink) ?>"><?= htmlspecialchars($phone) ?></a>&nbsp;
                    <?php else: ?>
                        <span class="muted">n.v.t.</span>&nbsp;
                    <?php endif; ?>
                </div>
                <div>
                    <span>Email:</span>
                    <?php if ($mailLink): ?>
                        <a href="<?= htmlspecialchars($mailLink) ?>">
                            <?= strtolower(htmlspecialchars($email)) ?>&nbsp;
                        </a>
                    <?php else: ?>
                        <span class="muted">n.v.t.</span>&nbsp;
                    <?php endif; ?>
                </div>
            </div>
            <table>
                <colgroup>
                    <col class="col-bkst">
                    <col class="col-aangemaakt">
                    <col class="col-vervalt">
                    <col class="col-verschuldigd">
                    <col class="col-dagen">
                    <col class="col-omschrijving">
                    <col class="col-afd">
                    <col class="col-notities">
                </colgroup>
                <thead>
                    <tr>
                        <th>Bkst nr</th>
                        <th>Gemaakt</th>
                        <th class="due-date-head">Vervalt</th>
                        <th class="amount">Bedrag</th>
                        <th title="Aantal dagen dat deze post vervallen is.">Dgn</th>
                        <th>Omschrijving</th>
                        <th title="Verkoper, afdeling of project die deze verkoop gemaakt heeft.">Afdeling</th>
                        <th>Notities</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group['entries'] as $entry): ?>
                        <?php
                        $rowClass = '';
                        if ($entry['_amount'] < 0) {
                            $rowClass = 'row-negative';
                        } elseif ($entry['_days_overdue'] > 0) {
                            $rowClass = 'row-overdue';
                        }
                        $dateMade = $entry['Document_Date'] ?? $entry['Posting_Date'] ?? '';
                        $dateDue = $entry['Due_Date'] ?? '';
                        $dateClosed = $entry['Closed_at_Date'] ?? '';
                        $dateMadeDisplay = format_date_nl($dateMade, true, true);
                        $dateDueDisplay = format_date_nl($dateDue, true, true);
                        $dateClosedDisplay = format_date_nl($dateClosed);
                        $currencyCode = $entry['_currency_code'] !== '' ? $entry['_currency_code'] : 'EUR';
                        $currencyDisplay = $entry['_currency_code'] !== '' ? $entry['_currency_code'] : 'EUR';
                        $currencyClass = $entry['_currency_code'] !== '' ? '' : 'currency-missing';
                        $currencyTitle = $entry['_currency_code'] !== ''
                            ? ''
                            : 'Currency code niet ingevuld in BC, EUR wordt aangenomen.';
                        $lcyTitle = '';
                        if ($entry['_amount_lcy'] !== null) {
                            $lcyTitle = 'LCY: ' . format_amount_with_currency($entry['_amount_lcy'], 'EUR');
                        }
                        $dimensionParts = array_filter([
                            (string) ($entry['Salesperson_Code'] ?? ''),
                            (string) ($entry['Global_Dimension_1_Code'] ?? ''),
                            (string) ($entry['Global_Dimension_2_Code'] ?? ''),
                        ]);
                        $dimensionText = $dimensionParts ? implode(' / ', $dimensionParts) : '';
                        $notes = (string) ($entry['KVT_Memo'] ?? '');
                        $notesDisplay = format_memo_html($notes, $memoTooltipTerms, $baseQueryParams);
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td data-label="Bkst nr">
                                <?= htmlspecialchars((string) ($entry['Document_No'] ?? $entry['Entry_No'] ?? '')) ?>
                            </td>
                            <td data-label="Datum gemaakt"><?= htmlspecialchars($dateMadeDisplay) ?></td>
                            <td data-label="Datum verval" class="due-date-cell"><?= htmlspecialchars($dateDueDisplay) ?></td>
                            <td data-label="Verschuldigd" class="amount" title="<?= htmlspecialchars($lcyTitle) ?>">
                                <?= htmlspecialchars(format_amount_with_currency($entry['_amount'], $currencyCode)) ?>
                            </td>
                            <td data-label="Dagen vervallen">
                                <?= $entry['_days_overdue'] > 0 ? (int) $entry['_days_overdue'] : '' ?>
                            </td>
                            <td data-label="Omschrijving"><?= htmlspecialchars((string) ($entry['Description'] ?? '')) ?></td>
                            <td data-label="Verkoper/afdeling/project"><?= htmlspecialchars($dimensionText) ?></td>
                            <td data-label="Notities"><?= $notesDisplay ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="3">Totaal voor debiteur <span
                                class="customer-no"><?= htmlspecialchars((string) ($customer['No'] ?? '')) ?></span></td>
                        <?php
                        $totalParts = [];
                        foreach ($group['totals_by_currency'] as $code => $totalAmount) {
                            $totalParts[] = format_amount_with_currency($totalAmount, (string) $code);
                        }
                        $totalText = implode(' / ', $totalParts);
                        ?>
                        <td class="amount" colspan="6" style="text-align:left;"><?= htmlspecialchars($totalText) ?></td>
                    </tr>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>

</body>

<?php if (!$isMailReport): ?>
    <script>
        (function ()
        {
            const body = document.body;
            const companySelect = document.getElementById('companySelect');
            const customerSelect = document.getElementById('customerSelect');
            const dueBeforeInput = document.getElementById('dueBeforeInput');
            const filterAllButton = document.getElementById('filterAllButton');

            function addHoverClass (element, className)
            {
                if (!element)
                {
                    return;
                }
                const addClass = () => body.classList.add(className);
                const removeClass = () => body.classList.remove(className);
                element.addEventListener('mouseenter', addClass);
                element.addEventListener('mouseleave', removeClass);
                element.addEventListener('focus', addClass);
                element.addEventListener('blur', removeClass);
            }

            addHoverClass(companySelect, 'highlight-company');
            addHoverClass(customerSelect, 'highlight-customers');
            addHoverClass(dueBeforeInput, 'highlight-due-date');

            function isDueBeforeActive ()
            {
                return !!(dueBeforeInput && dueBeforeInput.value.trim() !== '');
            }

            function updateAllButtonState ()
            {
                if (!filterAllButton)
                {
                    return;
                }
                const isBlocked = isDueBeforeActive();
                filterAllButton.classList.toggle('is-disabled', isBlocked);
                filterAllButton.setAttribute('aria-disabled', isBlocked ? 'true' : 'false');
            }

            function setWarningState (active)
            {
                if (!dueBeforeInput)
                {
                    return;
                }
                dueBeforeInput.classList.toggle('due-before-warning', active);
            }

            function triggerShake ()
            {
                if (!dueBeforeInput)
                {
                    return;
                }
                dueBeforeInput.classList.remove('shake');
                void dueBeforeInput.offsetWidth;
                dueBeforeInput.classList.add('shake');
            }

            if (dueBeforeInput)
            {
                dueBeforeInput.addEventListener('input', () =>
                {
                    updateAllButtonState();
                });
            }

            if (filterAllButton)
            {
                filterAllButton.addEventListener('mouseenter', () =>
                {
                    if (isDueBeforeActive())
                    {
                        setWarningState(true);
                    }
                });
                filterAllButton.addEventListener('mouseleave', () =>
                {
                    setWarningState(false);
                });
                filterAllButton.addEventListener('click', (event) =>
                {
                    if (!isDueBeforeActive())
                    {
                        return;
                    }
                    event.preventDefault();
                    setWarningState(true);
                    triggerShake();
                    if (dueBeforeInput)
                    {
                        dueBeforeInput.focus();
                    }
                });
            }

            updateAllButtonState();
        })();
    </script>
<?php endif; ?>

<?php
// Einde van output buffering en post-processing voor mail/print
if (isset($isMailReport) && $isMailReport) {
    $html = ob_get_clean();
    // Verwijder alleen de @media print { ... } wrappers, behoud de inhoud
// Dit werkt ook als er genestelde accolades zijn in de print block
    $html = preg_replace_callback(
        '/@media\s+print\s*{((?:[^{}]+|{[^{}]*})*)}/is',
        function ($m) {
            return $m[1]; // Alleen de inhoud binnen de @media print { ... }
        },
        $html
    );
    echo $html;
}
?>

</html>