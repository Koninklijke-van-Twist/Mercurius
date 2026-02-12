<?php
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";
require __DIR__ . "/odata.php";

$companies = [
    "Koninklijke van Twist",
    "Hunter van Twist",
    "KVT Gas",
];

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
}

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'overdue'], true)) {
    $filter = 'all';
}

$search = trim((string) ($_GET['search'] ?? ''));
$searchLower = strtolower($search);
$openFilter = $_GET['open_filter'] ?? 'open';
if (!in_array($openFilter, ['open', 'closed', 'both'], true)) {
    $openFilter = 'open';
}

function odata_company_url(string $environment, string $company, string $entity, array $params = []): string
{
    global $baseUrl;
    $encCompany = rawurlencode($company);
    $base = $baseUrl . $environment . "/ODataV4/Company('" . $encCompany . "')/";
    $query = '';
    if (!empty($params)) {
        $query = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    return $base . $entity . $query;
}

function pick_amount(array $entry): float
{
    if (isset($entry['Remaining_Amount'])) {
        return (float) $entry['Remaining_Amount'];
    }
    if (isset($entry['Remaining_Amt_LCY'])) {
        return (float) $entry['Remaining_Amt_LCY'];
    }
    return 0.0;
}

function format_amount(float $amount): string
{
    return number_format($amount, 2, ',', '.');
}

function currency_symbol(string $currencyCode): string
{
    $code = strtoupper(trim($currencyCode));
    $symbols = [
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'JPY' => '¥',
        'CNY' => '¥',
        'CHF' => 'CHF',
        'SEK' => 'kr',
        'NOK' => 'kr',
        'DKK' => 'kr',
        'AUD' => 'A$',
        'CAD' => 'C$',
        'NZD' => 'NZ$',
        'PLN' => 'zl',
        'CZK' => 'Kc',
        'HUF' => 'Ft',
        'TRY' => 'TRY',
        'INR' => 'INR',
        'BRL' => 'R$',
        'ZAR' => 'R',
        'MXN' => 'MX$',
        'SGD' => 'S$',
        'HKD' => 'HK$',
        'AED' => 'AED',
        'SAR' => 'SAR',
    ];

    if ($code === '') {
        return '';
    }

    return $symbols[$code] ?? $code;
}

function format_amount_with_currency(float $amount, string $currencyCode): string
{
    $symbol = currency_symbol($currencyCode);
    $formatted = format_amount($amount);
    if ($symbol === '') {
        return $formatted;
    }
    return $symbol . ' ' . $formatted;
}

$customerUrl = odata_company_url(
    $environment,
    $selectedCompany,
    'AppCustomerCard',
    [
        '$select' => 'No,Name,City,Phone_No',
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
        'External_Document_No',
        'Your_Reference',
        //'KVT_Extended_Text',
    ]),
];

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

$today = new DateTime('today');
$groups = [];

foreach ($entries as $entry) {
    if (!isset($entry['Customer_No'])) {
        continue;
    }

    $amount = pick_amount($entry);
    if (abs($amount) < 0.00001) {
        continue;
    }

    $dueDate = null;
    $daysOverdue = 0;
    if (!empty($entry['Due_Date'])) {
        $dueDate = new DateTime($entry['Due_Date']);
        if ($dueDate < $today) {
            $daysOverdue = (int) $dueDate->diff($today)->format('%a');
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
            //(string) ($entry['KVT_Extended_Text'] ?? ''),
            (string) ($customerInfo['No'] ?? ''),
            (string) ($customerInfo['Name'] ?? ''),
            (string) ($customerInfo['City'] ?? ''),
            (string) ($customerInfo['Phone_No'] ?? ''),
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

?><!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Openstaande posten debiteuren</title>
    <link rel="icon" href="/web/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/web/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/web/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/web/favicon-16x16.png">
    <link rel="manifest" href="/web/site.webmanifest">
    <style>
        :root {
            --bg: #f6f3ef;
            --ink: #1f2a2e;
            --muted: #5a6a70;
            --line: #d6d0c8;
            --accent: #254f6e;
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
        }

        h1 {
            margin: 0;
            font-size: 28px;
            letter-spacing: 0.4px;
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
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

        button {
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
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
            padding: 16px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px 20px;
            font-weight: 600;
            background: #f0ede8;
            align-items: center;
        }

        .customer-header span {
            color: var(--muted);
            font-weight: 500;
        }

        .customer-header a {
            color: var(--accent);
            text-decoration: none;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
            padding: 12px 16px;
            border-bottom: 1px solid var(--line);
        }

        tbody td {
            padding: 12px 16px;
            border-bottom: 1px solid #eee6dd;
            vertical-align: top;
            font-size: 14px;
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
            font-size: 12px;
        }

        .empty {
            padding: 18px 20px;
            color: var(--muted);
        }

        @media print {
            .controls {
                display: none !important;
            }
        }

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
        <h1>Openstaande posten debiteuren - <?= $selectedCompany ?></h1>
        <form class="controls" method="get">
            <label>
                <select name="company">
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= htmlspecialchars($company) ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                            <?= htmlspecialchars($company) ?>
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
            <button type="submit" name="filter" value="all" class="<?= $filter === 'all' ? 'filter-active' : '' ?>">Alle
                posten</button>
            <button type="submit" name="filter" value="overdue"
                class="<?= $filter === 'overdue' ? 'filter-active' : '' ?>">Vervallen posten</button>
        </form>
    </header>

    <?php if (empty($groups)): ?>
        <div class="group">
            <hr>
            <div class="empty">Geen openstaande posten gevonden voor deze selectie.</div>
        </div>
    <?php endif; ?>

    <?php foreach ($groups as $group): ?>
        <?php
        $customer = $group['customer'];
        $phone = trim((string) ($customer['Phone_No'] ?? ''));
        $phoneLink = $phone !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $phone) : '';
        ?>
        <section class="group">
            <hr>
            <div class="customer-header">
                <div>Debiteur: <?= htmlspecialchars((string) ($customer['No'] ?? '')) ?></div>
                <div><?= htmlspecialchars((string) ($customer['Name'] ?? '')) ?></div>
                <div><span>Woonplaats:</span> <?= htmlspecialchars((string) ($customer['City'] ?? '')) ?></div>
                <div>
                    <span>Telefoon:</span>
                    <?php if ($phoneLink): ?>
                        <a href="<?= htmlspecialchars($phoneLink) ?>"><?= htmlspecialchars($phone) ?></a>
                    <?php else: ?>
                        <span class="muted">n.v.t.</span>
                    <?php endif; ?>
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Bkst nr</th>
                        <th>Datum gemaakt</th>
                        <th>Datum verval</th>
                        <th class="amount">Verschuldigd</th>
                        <th>Valuta</th>
                        <th>Dagen vervallen</th>
                        <th>Omschrijving</th>
                        <th>Verk./afd./prj.</th>
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
                        $notes = "notes";//(string) ($entry['KVT_Extended_Text'] ?? '');
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td data-label="Bkst nr">
                                <?= htmlspecialchars((string) ($entry['Document_No'] ?? $entry['Entry_No'] ?? '')) ?>
                            </td>
                            <td data-label="Datum gemaakt"><?= htmlspecialchars((string) $dateMade) ?></td>
                            <td data-label="Datum verval"><?= htmlspecialchars((string) $dateDue) ?></td>
                            <td data-label="Verschuldigd" class="amount" title="<?= htmlspecialchars($lcyTitle) ?>">
                                <?= htmlspecialchars(format_amount_with_currency($entry['_amount'], $currencyCode)) ?>
                            </td>
                            <td data-label="Valuta" class="<?= $currencyClass ?>"
                                title="<?= htmlspecialchars($currencyTitle) ?>">
                                <?= htmlspecialchars((string) $currencyDisplay) ?>
                            </td>
                            <td data-label="Dagen vervallen">
                                <?= $entry['_days_overdue'] > 0 ? (int) $entry['_days_overdue'] : '' ?>
                            </td>
                            <td data-label="Omschrijving"><?= htmlspecialchars((string) ($entry['Description'] ?? '')) ?></td>
                            <td data-label="Verkoper/afdeling/project"><?= htmlspecialchars($dimensionText) ?></td>
                            <td data-label="Notities"><?= htmlspecialchars($notes) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td colspan="3">Totaal voor debiteur <?= htmlspecialchars((string) ($customer['No'] ?? '')) ?></td>
                        <?php
                        $totalParts = [];
                        foreach ($group['totals_by_currency'] as $code => $totalAmount) {
                            $totalParts[] = format_amount_with_currency($totalAmount, (string) $code);
                        }
                        $totalText = implode(' / ', $totalParts);
                        ?>
                        <td class="amount"><?= htmlspecialchars($totalText) ?></td>
                        <td colspan="5"></td>
                    </tr>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>

</body>

</html>