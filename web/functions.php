<?php
// Alle hulpfuncties uit index.php

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

function format_date_nl(?string $dateValue, bool $short = true, bool $year2 = false): string
{
    if ($dateValue === null || trim($dateValue) === '') {
        return '';
    }

    try {
        $date = new DateTime($dateValue);
    } catch (Exception $exception) {
        return $dateValue;
    }

    if ($short) {
        return $date->format($year2 ? 'd-m-y' : 'd-m-Y');
    }

    $months = [
        1 => 'januari',
        2 => 'februari',
        3 => 'maart',
        4 => 'april',
        5 => 'mei',
        6 => 'juni',
        7 => 'juli',
        8 => 'augustus',
        9 => 'september',
        10 => 'oktober',
        11 => 'november',
        12 => 'december',
    ];

    $monthIndex = (int) $date->format('n');
    $monthName = $months[$monthIndex] ?? '';
    return $date->format('j') . ' ' . $monthName . ' ' . $date->format($year2 ? 'y' : 'Y');
}

function preserve_memo_whitespace(string $text): string
{
    return str_replace("\t", '    ', $text);
}

function customer_filter_href(array $baseQueryParams, string $customerNo): string
{
    $params = array_merge($baseQueryParams, ['customer_no' => $customerNo]);
    return '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function render_memo_terms(string $segment, array $memoTooltipTerms, ?string $termPattern): string
{
    if ($segment === '') {
        return '';
    }

    if ($termPattern === null) {
        return preserve_memo_whitespace(htmlspecialchars($segment));
    }

    $rendered = '';
    $offset = 0;
    if (preg_match_all($termPattern, $segment, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $matchData) {
            $term = (string) $matchData[0];
            $position = (int) $matchData[1];

            if ($position > $offset) {
                $plainText = substr($segment, $offset, $position - $offset);
                $rendered .= preserve_memo_whitespace(htmlspecialchars($plainText));
            }

            $tooltip = $memoTooltipTerms[$term] ?? '';
            $termDisplay = preserve_memo_whitespace(htmlspecialchars($term));
            if ($tooltip !== '') {
                $rendered .= '<span class="memo-term" title="' . $term . ": " . htmlspecialchars($tooltip) . '">' . $termDisplay . '</span>';
            } else {
                $rendered .= $termDisplay;
            }

            $offset = $position + strlen($term);
        }
    }

    if ($offset < strlen($segment)) {
        $plainText = substr($segment, $offset);
        $rendered .= preserve_memo_whitespace(htmlspecialchars($plainText));
    }

    return $rendered;
}

function render_memo_text_segment(string $segment, array $memoTooltipTerms, ?string $termPattern, array $baseQueryParams): string
{
    if ($segment === '') {
        return '';
    }

    $debtorPattern = '~\b(debiteur|deb\.?|debnr|deb[-\s]*nr|debiteurnr|debiteur[-\s]*nr|debiteurnummer|deb[-\s]*nummer)(\s*[:#-]?\s*)(\d{2,10})\b~iu';
    if (preg_match($debtorPattern, $segment) !== 1) {
        return render_memo_terms($segment, $memoTooltipTerms, $termPattern);
    }

    $rendered = '';
    $offset = 0;
    if (preg_match_all($debtorPattern, $segment, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $index => $fullMatchData) {
            $fullMatch = (string) $fullMatchData[0];
            $position = (int) $fullMatchData[1];
            $prefix = (string) ($matches[1][$index][0] ?? '');
            $separator = (string) ($matches[2][$index][0] ?? '');
            $customerNo = (string) ($matches[3][$index][0] ?? '');

            if ($position > $offset) {
                $plainText = substr($segment, $offset, $position - $offset);
                $rendered .= render_memo_terms($plainText, $memoTooltipTerms, $termPattern);
            }

            $rendered .= render_memo_terms($prefix . $separator, $memoTooltipTerms, $termPattern);
            $href = customer_filter_href($baseQueryParams, $customerNo);
            $numberDisplay = preserve_memo_whitespace(htmlspecialchars($customerNo));
            $rendered .= '<a href="' . htmlspecialchars($href) . '">' . $numberDisplay . '</a>';

            $offset = $position + strlen($fullMatch);
        }
    }

    if ($offset < strlen($segment)) {
        $plainText = substr($segment, $offset);
        $rendered .= render_memo_terms($plainText, $memoTooltipTerms, $termPattern);
    }

    return $rendered;
}

function is_probable_phone(string $text): bool
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return false;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
        return false;
    }

    $digits = preg_replace('/\D+/', '', $trimmed);
    $digitCount = strlen($digits);
    if ($digitCount < 8 || $digitCount > 15) {
        return false;
    }

    return preg_match('/[+\-\s().\/]/', $trimmed) === 1 || preg_match('/^0\d+$/', $digits) === 1;
}

function phone_href(string $text): string
{
    $trimmed = trim($text);
    $hasLeadingPlus = strpos($trimmed, '+') === 0;
    $digits = preg_replace('/\D+/', '', $trimmed);

    if ($digits === '') {
        return '';
    }

    return 'tel:' . ($hasLeadingPlus ? '+' : '') . $digits;
}

function format_memo_html(string $memo, array $memoTooltipTerms, array $baseQueryParams): string
{
    $normalized = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $memo);
    $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);
    $lines = explode("\n", $normalized);

    $termPattern = null;
    if (!empty($memoTooltipTerms)) {
        $terms = array_keys($memoTooltipTerms);
        usort($terms, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        $quotedTerms = array_map(static fn(string $term): string => preg_quote($term, '~'), $terms);
        $termPattern = '~(' . implode('|', $quotedTerms) . ')~u';
    }

    $pattern = '~(https?://[^\s<>"\']+[^\s<>"\'.,;:!?]|www\.[^\s<>"\']+[^\s<>"\'.,;:!?]|[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|\+?\d[\d\s()./\-]{6,}\d)~iu';
    $resultLines = [];

    foreach ($lines as $line) {
        $renderedLine = '';
        $offset = 0;

        if (preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $matchData) {
                $matchText = (string) $matchData[0];
                $matchPos = (int) $matchData[1];

                if ($matchPos > $offset) {
                    $segment = substr($line, $offset, $matchPos - $offset);
                    $renderedLine .= render_memo_text_segment($segment, $memoTooltipTerms, $termPattern, $baseQueryParams);
                }

                if (strpos($matchText, '@') !== false && strpos($matchText, '://') === false && stripos($matchText, 'www.') !== 0) {
                    $href = 'mailto:' . $matchText;
                    $display = htmlspecialchars($matchText);
                    $renderedLine .= '<a href="' . htmlspecialchars($href) . '">' . $display . '</a>';
                } elseif (strpos($matchText, '://') !== false || stripos($matchText, 'www.') === 0) {
                    $href = stripos($matchText, 'www.') === 0 ? 'https://' . $matchText : $matchText;
                    $display = htmlspecialchars($matchText);
                    $renderedLine .= '<a href="' . htmlspecialchars($href) . '" target="_blank" rel="noopener noreferrer">' . $display . '</a>';
                } elseif (is_probable_phone($matchText)) {
                    $href = phone_href($matchText);
                    $display = preserve_memo_whitespace(htmlspecialchars($matchText));
                    $renderedLine .= '<a href="' . htmlspecialchars($href) . '">' . $display . '</a>';
                } else {
                    $renderedLine .= render_memo_text_segment($matchText, $memoTooltipTerms, $termPattern, $baseQueryParams);
                }

                $offset = $matchPos + strlen($matchText);
            }
        }

        if ($offset < strlen($line)) {
            $segment = substr($line, $offset);
            $renderedLine .= render_memo_text_segment($segment, $memoTooltipTerms, $termPattern, $baseQueryParams);
        }

        if ($renderedLine === '') {
            $renderedLine = '';
        }

        $resultLines[] = $renderedLine;
    }

    return implode('<br/>', $resultLines);
}
