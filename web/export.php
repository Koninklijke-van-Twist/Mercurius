<?php
require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';

$companies = [
    'Koninklijke van Twist',
    'Hunter van Twist',
    'KVT Gas',
];

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
}

function csv_string(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return $default;
}

function csv_number(array $row, array $keys): ?float
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        if ($row[$key] === null || $row[$key] === '') {
            continue;
        }

        if (is_numeric($row[$key])) {
            return (float) $row[$key];
        }
    }

    return null;
}

function csv_normalize_date(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '' || $trimmed === '0001-01-01') {
        return '';
    }

    try {
        return (new DateTime($trimmed))->format('d/m/Y');
    } catch (Throwable $e) {
        return $trimmed;
    }
}

function csv_normalize_currency(string $value): string
{
    $code = strtoupper(trim($value));
    return $code !== '' ? $code : 'EUR';
}

function csv_format_amount(?float $value): string
{
    if ($value === null) {
        return '0,00';
    }

    return number_format($value, 2, ',', '');
}

function csv_is_paid_row(array $row): bool
{
    $closedRaw = csv_string($row, ['Closed_at_Date']);
    return csv_normalize_date($closedRaw) !== '';
}

function csv_is_invoice_row(array $row): bool
{
    $documentType = strtolower(csv_string($row, ['Document_Type', 'document_type']));
    if ($documentType !== '') {
        if (in_array($documentType, ['invoice', 'factuur'], true)) {
            return true;
        }

        if (in_array($documentType, ['payment', 'betaling'], true)) {
            return false;
        }
    }

    $documentNo = strtoupper(csv_string($row, ['Document_No']));
    if (str_starts_with($documentNo, 'ING')) {
        return false;
    }

    $description = strtolower(csv_string($row, ['Description']));
    if (strpos($description, 'overschrijving') !== false || strpos($description, 'betaling') !== false) {
        return false;
    }

    $amount = csv_number($row, ['Amount', 'Remaining_Amount']);
    if ($amount !== null && $amount <= 0) {
        return false;
    }

    return true;
}

function csv_fetch_customers(string $selectedCompany, string $environment, array $auth): array
{
    $params = [
        '$select' => implode(',', [
            'No',
            'Name',
            'Address',
            'Address_2',
            'Post_Code',
            'City',
            'Country_Region_Code',
            'KVT_Chamber_Of_Commerce_No',
            'VAT_Registration_No',
            'Preferred_Bank_Account_Code',
        ]),
    ];

    $url = odata_company_url($environment, $selectedCompany, 'AppCustomerCard', $params);

    try {
        return odata_get_all($url, $auth, 600);
    } catch (Throwable $e) {
        $fallbackUrl = odata_company_url($environment, $selectedCompany, 'AppCustomerCard');
        return odata_get_all($fallbackUrl, $auth, 300);
    }
}

function csv_fetch_ledger_rows(string $selectedCompany, string $environment, array $auth, bool $open): array
{
    $selectFields = [
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
        'Amount',
        'Original_Amount',
        'Document_Type',
    ];

    $filter = $open ? 'Open eq true' : 'Open eq false';

    $url = odata_company_url(
        $environment,
        $selectedCompany,
        'Customer_Ledger_Entries',
        [
            '$select' => implode(',', $selectFields),
            '$filter' => $filter,
        ]
    );

    try {
        return odata_get_all($url, $auth, 600);
    } catch (Throwable $e) {
        $fallbackFilter = $open ? 'Open eq true' : 'Open eq false';
        $fallbackUrl = odata_company_url(
            $environment,
            $selectedCompany,
            'Customer_Ledger_Entries',
            [
                '$select' => implode(',', $selectFields),
                '$filter' => $fallbackFilter,
            ]
        );
        return odata_get_all($fallbackUrl, $auth, 600);
    }
}

function csv_output(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');
    fputcsv($output, $headers, ';');

    foreach ($rows as $row) {
        fputcsv($output, $row, ';');
    }

    fclose($output);
    exit;
}

function csv_build_stambestand_rows(array $customers): array
{
    $rows = [];
    foreach ($customers as $customer) {
        $address = trim(csv_string($customer, ['Address']));
        $address2 = trim(csv_string($customer, ['Address_2']));
        if ($address2 !== '') {
            $address .= ' ' . $address2;
        }

        $rows[] = [
            csv_string($customer, ['No']),
            csv_string($customer, ['Name']),
            trim($address),
            csv_string($customer, ['Post_Code']),
            csv_string($customer, ['City']),
            csv_string($customer, ['Country_Region_Code', 'Country_Code'], 'NL'),
            csv_string($customer, ['KVT_Chamber_Of_Commerce_No', 'KVK_Nr', 'CoC_No', 'Chamber_of_Commerce_No']),
            csv_string($customer, ['Preferred_Bank_Account_Code', 'IBAN', 'IBAN_No', 'Bank_Account_No', 'VAT_Registration_No']),
        ];
    }

    return $rows;
}

function csv_build_openstaande_rows(array $entries): array
{
    $rows = [];
    foreach ($entries as $entry) {
        $amount = pick_amount($entry);
        if (abs($amount) < 0.00001) {
            continue;
        }

        $rows[] = [
            csv_string($entry, ['Customer_No']),
            csv_string($entry, ['Document_No']),
            csv_normalize_date(csv_string($entry, ['Document_Date'])),
            csv_normalize_date(csv_string($entry, ['Due_Date'])),
            csv_normalize_currency(csv_string($entry, ['Currency_Code'])),
            csv_format_amount($amount),
        ];
    }

    return $rows;
}

function csv_build_betaalde_rows(array $entries): array
{
    $rows = [];
    foreach ($entries as $entry) {
        if (!csv_is_paid_row($entry)) {
            continue;
        }

        $documentType = strtolower(csv_string($entry, ['Document_Type', 'document_type']));
        if ($documentType !== '' && !in_array($documentType, ['invoice', 'factuur'], true)) {
            continue;
        }

        $amount = csv_number($entry, ['Amount', 'Original_Amount', 'Remaining_Amount', 'Remaining_Amt_LCY']);
        $paidDate = csv_normalize_date(csv_string($entry, ['Closed_at_Date']));

        $rows[] = [
            csv_string($entry, ['Customer_No']),
            csv_string($entry, ['Document_No']),
            csv_normalize_date(csv_string($entry, ['Document_Date'])),
            csv_normalize_date(csv_string($entry, ['Due_Date'])),
            csv_normalize_currency(csv_string($entry, ['Currency_Code'])),
            csv_format_amount($amount ?? 0.0),
            $paidDate,
        ];
    }

    return $rows;
}

$download = trim((string) ($_GET['download'] ?? ''));

$stamHeaders = ['Deb.nr.', 'Naam', 'Adres', 'PC', 'Plaats', 'Land', 'KvK Nr.', 'Rek.nr.'];
$openHeaders = ['Debnr.', 'Fakt.nr.', 'Fakt.datum', 'Vervaldatum', 'Valuta', 'Bedrag'];
$paidHeaders = ['Debnr.', 'Fakt.nr.', 'Fakt.datum', 'Vervaldatum', 'Valuta', 'Bedrag', 'Datum betaald'];

if ($download === 'stambestand-debiteuren') {
    $customers = csv_fetch_customers($selectedCompany, $environment, $auth);
    $rows = csv_build_stambestand_rows($customers);

    csv_output(
        'Stambestand_Debiteuren.csv',
        $stamHeaders,
        $rows
    );
}

if ($download === 'openstaande-facturen') {
    $entries = csv_fetch_ledger_rows($selectedCompany, $environment, $auth, true);
    $rows = csv_build_openstaande_rows($entries);

    csv_output(
        'Openstaande_facturen.csv',
        $openHeaders,
        $rows
    );
}

if ($download === 'betaalde-facturen') {
    $entries = csv_fetch_ledger_rows($selectedCompany, $environment, $auth, false);
    $rows = csv_build_betaalde_rows($entries);

    csv_output(
        'Betaalde_facturen.csv',
        $paidHeaders,
        $rows
    );
}

$stamRows = csv_build_stambestand_rows(csv_fetch_customers($selectedCompany, $environment, $auth));
$openRows = csv_build_openstaande_rows(csv_fetch_ledger_rows($selectedCompany, $environment, $auth, true));
$paidRows = csv_build_betaalde_rows(csv_fetch_ledger_rows($selectedCompany, $environment, $auth, false));

$previewLimit = 15;
$stamPreviewRows = array_slice($stamRows, 0, $previewLimit);
$openPreviewRows = array_slice($openRows, 0, $previewLimit);
$paidPreviewRows = array_slice($paidRows, 0, $previewLimit);
?><!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CSV Export</title>
    <style>
        :root {
            --bg: #f6f3ef;
            --ink: #1f2a2e;
            --muted: #5a6a70;
            --line: #d6d0c8;
            --accent: #254f6e;
            --panel: #ffffff;
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

        .back-link {
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            color: var(--ink);
            text-decoration: none;
            padding: 8px 12px;
            font-size: 14px;
        }

        .subtitle {
            color: var(--muted);
            margin: 4px 0 18px;
        }

        .company-form {
            margin-bottom: 18px;
        }

        .company-form select {
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            color: var(--ink);
            font-size: 14px;
            padding: 8px 10px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 14px;
        }

        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 14px;
        }

        .card h2 {
            margin: 0 0 8px;
            font-size: 20px;
        }

        .card p {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.4;
        }

        .export-button {
            display: inline-block;
            text-decoration: none;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            color: var(--ink);
            font-size: 14px;
            padding: 8px 12px;
        }

        .export-button:hover {
            border-color: var(--accent);
            color: var(--accent);
        }

        .preview {
            margin-top: 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow: auto;
            background: #fcfaf7;
        }

        .preview table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .preview th,
        .preview td {
            padding: 6px 8px;
            border-bottom: 1px solid #ebe4db;
            text-align: left;
            white-space: nowrap;
        }

        .preview thead th {
            background: #f4efe8;
            position: sticky;
            top: 0;
        }

        .preview-more td {
            text-align: center;
            color: var(--muted);
            font-weight: 700;
        }
    </style>
</head>

<body>
    <header>
        <h1>CSV Export</h1>
        <a class="back-link" href="index.php?company=<?= urlencode($selectedCompany) ?>">Terug naar overzicht</a>
    </header>

    <p class="subtitle">Bedrijf: <?= htmlspecialchars($selectedCompany) ?></p>

    <form class="company-form" method="get">
        <label for="companySelect">Bedrijf: </label>
        <select id="companySelect" name="company" onchange="this.form.submit()">
            <?php foreach ($companies as $company): ?>
                <option value="<?= htmlspecialchars($company) ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                    <?= htmlspecialchars($company) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Toon</button></noscript>
    </form>

    <div class="cards">
        <section class="card">
            <h2>Stambestand Debiteuren</h2>
            <p>Exporteert alle debiteuren met debiteurnummer, naam, adres, postcode, plaats, landcode, KvK nummer en
                rekeningnummer.</p>
            <a class="export-button"
                href="export.php?company=<?= urlencode($selectedCompany) ?>&download=stambestand-debiteuren">Download
                CSV</a>
            <div class="preview">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($stamHeaders as $header): ?>
                                <th><?= htmlspecialchars($header) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stamPreviewRows as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= htmlspecialchars((string) $cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($stamRows) > $previewLimit): ?>
                            <tr class="preview-more">
                                <td colspan="<?= count($stamHeaders) ?>">(Meer regels volgen in volledige export)</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Openstaande facturen</h2>
            <p>Exporteert alle openstaande facturen met debiteurnummer, factuurnummer, factuurdatum, vervaldatum, valuta
                en bedrag in factuurvaluta.</p>
            <a class="export-button"
                href="export.php?company=<?= urlencode($selectedCompany) ?>&download=openstaande-facturen">Download
                CSV</a>
            <div class="preview">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($openHeaders as $header): ?>
                                <th><?= htmlspecialchars($header) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openPreviewRows as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= htmlspecialchars((string) $cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($openRows) > $previewLimit): ?>
                            <tr class="preview-more">
                                <td colspan="<?= count($openHeaders) ?>">(Meer regels volgen in volledige export)</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Betaalde facturen</h2>
            <p>Exporteert alle betaalde facturen met debiteurnummer, factuurnummer, factuurdatum, vervaldatum, valuta,
                bedrag in factuurvaluta en datum betaald.</p>
            <a class="export-button"
                href="export.php?company=<?= urlencode($selectedCompany) ?>&download=betaalde-facturen">Download CSV</a>
            <div class="preview">
                <table>
                    <thead>
                        <tr>
                            <?php foreach ($paidHeaders as $header): ?>
                                <th><?= htmlspecialchars($header) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paidPreviewRows as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?= htmlspecialchars((string) $cell) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($paidRows) > $previewLimit): ?>
                            <tr class="preview-more">
                                <td colspan="<?= count($paidHeaders) ?>">(Meer regels volgen in volledige export)</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</body>

</html>