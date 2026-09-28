<?php

function auth_start_session_if_possible(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    if (headers_sent()) {
        return;
    }

    @session_start();
}


/**
 * Laadt odata.php indien nodig en checkt of Mímir actief is ($mimirApi gezet).
 */
function auth_ensure_odata_loaded(): void
{
    if (function_exists('odata_mimir_enabled')) {
        return;
    }
    $odataPath = __DIR__ . '/odata.php';
    if (is_file($odataPath)) {
        require_once $odataPath;
    }
}

function auth_mimir_enabled(): bool
{
    auth_ensure_odata_loaded();
    return function_exists('odata_mimir_enabled') && odata_mimir_enabled();
}

function auth_normalize_environment_list($environmentValue = null): array
{
    global $auth_list;

    if ($environmentValue === null) {
        if (isset($GLOBALS['environments'])) {
            $environmentValue = $GLOBALS['environments'];
        } elseif (isset($GLOBALS['environment'])) {
            $environmentValue = $GLOBALS['environment'];
        } else {
            $environmentValue = [];
        }
    }

    $items = [];
    if (is_string($environmentValue)) {
        $items = array_map('trim', explode(',', $environmentValue));
    } elseif (is_array($environmentValue)) {
        foreach ($environmentValue as $value) {
            if (is_string($value)) {
                $items[] = trim($value);
            }
        }
    }

    $normalized = [];
    foreach ($items as $item) {
        if ($item === '' || in_array($item, $normalized, true)) {
            continue;
        }
        $normalized[] = $item;
    }

    if (empty($normalized)) {
        if (auth_mimir_enabled()) {
            return [];
        }
        throw new RuntimeException('Geen actieve environments geconfigureerd in auth.php.');
    }

    $list = is_array($auth_list ?? null) ? $auth_list : [];
    $unknown = [];
    foreach ($normalized as $environment) {
        if (!isset($list[$environment]) || !is_array($list[$environment])) {
            $unknown[] = $environment;
        }
    }

    // Mímir-modus: lokale BC-auth mag ontbreken.
    if (!empty($unknown) && !auth_mimir_enabled()) {
        throw new RuntimeException('Geen auth gevonden voor environment(s): ' . implode(', ', $unknown));
    }

    return $normalized;
}

function auth_get_active_environments(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    try {
        $cached = auth_normalize_environment_list();
    } catch (Throwable $ignored) {
        $cached = [];
    }

    if ($cached === [] && auth_mimir_enabled()) {
        $fromMimir = $GLOBALS['mercurius_active_environments'] ?? null;
        if (is_array($fromMimir) && $fromMimir !== []) {
            $cached = array_values(array_map('strval', $fromMimir));
        } else {
            try {
                auth_ensure_odata_loaded();
                if (function_exists('odata_mimir_companies_as_rows')) {
                    $rows = odata_mimir_companies_as_rows(null);
                    $envs = [];
                    $seen = [];
                    foreach ($rows as $row) {
                        $env = trim((string) ($row['environment'] ?? ''));
                        if ($env === '' || isset($seen[$env])) {
                            continue;
                        }
                        $seen[$env] = true;
                        $envs[] = $env;
                    }
                    $cached = $envs;
                }
            } catch (Throwable $ignored) {
                $cached = [];
            }
        }
    }

    if ($cached === [] && !auth_mimir_enabled()) {
        $cached = auth_normalize_environment_list();
    }

    $GLOBALS['environments'] = $cached;

    return $cached;
}

function auth_primary_environment(): string
{
    $environments = auth_get_active_environments();
    if ($environments === []) {
        if (auth_mimir_enabled()) {
            return '';
        }
        throw new RuntimeException('Geen actieve environments geconfigureerd in auth.php.');
    }
    return $environments[0];
}

function auth_environment_signature(?array $environments = null): string
{
    $active = $environments ?? auth_get_active_environments();
    if ($active === []) {
        return auth_mimir_enabled() ? 'mimir' : '';
    }
    $active = auth_normalize_environment_list($active);

    return implode('|', $active);
}

function auth_get_for_environment(string $environment): array
{
    global $auth_list;

    $environment = trim($environment);
    $list = is_array($auth_list ?? null) ? $auth_list : [];

    if ($environment === '') {
        if (auth_mimir_enabled()) {
            return [];
        }
        throw new RuntimeException('Lege environment is niet toegestaan.');
    }

    $auth = $list[$environment] ?? null;
    if (!is_array($auth)) {
        // Mímir-modus zonder BC-auth: leftover callers krijgen lege auth i.p.v. exception.
        if (auth_mimir_enabled()) {
            return [];
        }
        throw new RuntimeException('Auth ontbreekt voor environment: ' . $environment);
    }

    return $auth;
}

function auth_fetch_json_with_auth(string $url, array $auth): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
    ]);

    if (($auth['mode'] ?? '') === 'basic') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? ''));
    } elseif (($auth['mode'] ?? '') === 'ntlm') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? ''));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $error);
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('HTTP ' . $code . ' tijdens company discovery: ' . $raw);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Ongeldige JSON tijdens company discovery.');
    }

    return $json;
}

function auth_extract_company_name(array $row): string
{
    $candidates = [
        'Name',
        'name',
        'Display_Name',
        'displayName',
        'Company_Name',
        'companyName',
    ];

    foreach ($candidates as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function auth_fetch_company_names_for_environment(string $environment): array
{
    global $baseUrl;

    $testFetcher = $GLOBALS['auth_company_fetcher_for_tests'] ?? null;
    if (is_callable($testFetcher)) {
        $rows = $testFetcher($environment);
        if (!is_array($rows)) {
            throw new RuntimeException("Test fetcher gaf geen array terug voor environment '{$environment}'.");
        }
        return $rows;
    }

    $auth = auth_get_for_environment($environment);
    $base = rtrim((string) $baseUrl, '/') . '/' . $environment . '/ODataV4';
    $candidateUrls = [
        $base . '/Company?$select=Name',
        $base . '/Company',
        $base . '/companies?$select=Name',
    ];

    $resp = null;
    $errors = [];
    foreach ($candidateUrls as $url) {
        try {
            $resp = auth_fetch_json_with_auth($url, $auth);
            break;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if (!is_array($resp)) {
        $summary = !empty($errors) ? (' Laatste fout: ' . end($errors)) : '';
        throw new RuntimeException("Company discovery mislukt voor environment '{$environment}'." . $summary);
    }

    $rows = $resp['value'] ?? null;
    if (!is_array($rows)) {
        throw new RuntimeException("Company discovery gaf geen geldige lijst voor environment '{$environment}'.");
    }

    $companies = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $name = auth_extract_company_name($row);
        if ($name === '') {
            continue;
        }

        if (!in_array($name, $companies, true)) {
            $companies[] = $name;
        }
    }

    return $companies;
}

function auth_set_company_fetcher_for_tests($fetcher = null): void
{
    if ($fetcher !== null && !is_callable($fetcher)) {
        throw new RuntimeException('Test fetcher moet callable of null zijn.');
    }

    $GLOBALS['auth_company_fetcher_for_tests'] = $fetcher;
}

function auth_set_company_environment_map(array $map): void
{
    $GLOBALS['companyEnvironmentMap'] = $map;

    auth_start_session_if_possible();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['company_environment_map'] = $map;
    }
}

function auth_get_company_environment_map(): array
{
    if (isset($GLOBALS['companyEnvironmentMap']) && is_array($GLOBALS['companyEnvironmentMap'])) {
        return $GLOBALS['companyEnvironmentMap'];
    }

    auth_start_session_if_possible();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $map = $_SESSION['company_environment_map'] ?? [];
        if (is_array($map)) {
            $GLOBALS['companyEnvironmentMap'] = $map;
            return $map;
        }
    }

    return [];
}

function getEnvironmentForCompany(string $company): string
{
    $company = trim($company);
    if ($company === '') {
        throw new RuntimeException('Geen bedrijf geselecteerd.');
    }

    $map = auth_get_company_environment_map();
    if (!empty($map) && isset($map[$company]) && is_string($map[$company]) && trim($map[$company]) !== '') {
        return (string) $map[$company];
    }

    $discovery = auth_discover_companies();
    $resolvedMap = $discovery['companyEnvironmentMap'];
    if (!isset($resolvedMap[$company])) {
        throw new RuntimeException("Geen environment gevonden voor bedrijf '{$company}'.");
    }

    return (string) $resolvedMap[$company];
}

function auth_store_selected_company_context(string $company): void
{
    auth_start_session_if_possible();
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $environment = getEnvironmentForCompany($company);
    $_SESSION['selected_company'] = $company;
    $_SESSION['selected_environment'] = $environment;
}


/**
 * Company-discovery via Mímir companies.php (geen BC auth_list/baseUrl).
 */
function auth_discover_companies_via_mimir(): array
{
    auth_ensure_odata_loaded();
    if (!function_exists('odata_mimir_companies_as_rows')) {
        throw new RuntimeException('Mímir company-discovery vereist odata.php.');
    }

    $rows = odata_mimir_companies_as_rows(null);
    $map = [];
    $duplicates = [];

    foreach ($rows as $row) {
        $companyName = trim((string) ($row['Name'] ?? ''));
        $environment = trim((string) ($row['environment'] ?? ''));
        if ($companyName === '' || $environment === '') {
            continue;
        }

        if (isset($map[$companyName]) && $map[$companyName] !== $environment) {
            $duplicates[$companyName] = true;
            continue;
        }

        $map[$companyName] = $environment;
    }

    if ($duplicates !== []) {
        $names = array_keys($duplicates);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        throw new RuntimeException(
            'Bedrijfsnaam-overlap tussen Mímir-environments. Conflicten: ' . implode('; ', $names)
        );
    }

    $companies = array_keys($map);
    usort($companies, static function (string $left, string $right): int {
        return strnatcasecmp($left, $right);
    });

    $activeEnvironments = array_values(array_unique(array_values($map)));
    sort($activeEnvironments, SORT_NATURAL | SORT_FLAG_CASE);
    $GLOBALS['mercurius_active_environments'] = $activeEnvironments;
    $GLOBALS['environments'] = $activeEnvironments;

    auth_set_company_environment_map($map);

    return [
        'companies' => $companies,
        'companyEnvironmentMap' => $map,
    ];
}

function auth_discover_companies(bool $forceRefresh = false): array
{
    static $cachedResult = null;
    if ($cachedResult !== null && !$forceRefresh) {
        return $cachedResult;
    }

    // Mímir eerst. odata_mimir_companies_as_rows valt bij een fout terug op de
    // pre-Mímir BC-companylijst ($baseUrl, $auth_list, $environments, filecache).
    if (auth_mimir_enabled()) {
        $cachedResult = auth_discover_companies_via_mimir();
        return $cachedResult;
    }

    $environments = auth_get_active_environments();
    $map = [];

    foreach ($environments as $environment) {
        $companies = auth_fetch_company_names_for_environment($environment);

        foreach ($companies as $companyName) {
            if (isset($map[$companyName]) && $map[$companyName] !== $environment) {
                throw new RuntimeException(
                    "Bedrijfsnaam-overlap gedetecteerd: '{$companyName}' staat in meerdere actieve environments ({$map[$companyName]} en {$environment})."
                );
            }

            $map[$companyName] = $environment;
        }
    }

    $companies = array_keys($map);
    usort($companies, static function (string $left, string $right): int {
        return strnatcasecmp($left, $right);
    });

    auth_set_company_environment_map($map);

    $cachedResult = [
        'companies' => $companies,
        'companyEnvironmentMap' => $map,
    ];

    return $cachedResult;
}
