<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function consolelog($text)
{
    static $enabled = null;
    if ($enabled === null) {
        $flag = getenv('KVT_DEBUG_ODATA');
        $enabled = is_string($flag) && in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'on'], true);
    }

    if (!$enabled) {
        return;
    }

    file_put_contents('php://stdout', $text);
}

/**
 * Mímir-proxy: als $mimirApi in auth.php staat, gaan OData-fetches eerst naar Mímir.
 * Faalt die aanroep (cURL/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload),
 * dan valt Mercurius terug op de directe BC-route van vóór Mímir: $baseUrl +
 * $auth / $auth_list / $environment(s) en de lokale odata-filecache.
 * Na de eerste fout in dit PHP-proces wordt Mímir overgeslagen.
 * Zonder $mimirApi blijft alleen die directe route actief.
 * Zonder BC-credentials wordt de oorspronkelijke Mímir-fout opnieuw gegooid.
 *
 * Tim moet in web/auth.php zetten (niet in git):
 *   $mimirApi  = 'mimir_…';              // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *   én $auth_list / $environments / $baseUrl (en optioneel $auth / $environment) voor de BC-fallback.
 */

function odata_mimir_api_key(): string
{
    global $mimirApi;
    if (!isset($mimirApi) || !is_string($mimirApi)) {
        return '';
    }
    return trim($mimirApi);
}

function odata_mimir_enabled(): bool
{
    return odata_mimir_api_key() !== '';
}

function odata_mimir_base_url(): string
{
    global $mimirBase;
    if (isset($mimirBase) && is_string($mimirBase) && trim($mimirBase) !== '') {
        return rtrim(trim($mimirBase), '/');
    }
    return 'https://sleutels.kvt.nl/mimir/api';
}

/**
 * @return array{open: bool, error: ?Throwable}
 */
function &odata_mimir_circuit_state(): array
{
    static $state = [
        'open' => false,
        'error' => null,
    ];
    return $state;
}

function odata_mimir_circuit_open(): bool
{
    $state = &odata_mimir_circuit_state();
    return $state['open'] === true;
}

function odata_mimir_last_error(): ?Throwable
{
    $state = &odata_mimir_circuit_state();
    return $state['error'] instanceof Throwable ? $state['error'] : null;
}

function odata_mimir_trip(Throwable $exception): void
{
    $state = &odata_mimir_circuit_state();
    if ($state['open'] === true) {
        return;
    }
    $state['open'] = true;
    $state['error'] = $exception;
}

function odata_mimir_circuit_reset(): void
{
    $state = &odata_mimir_circuit_state();
    $state['open'] = false;
    $state['error'] = null;
}

function odata_mimir_connect_timeout_seconds(): int
{
    return 10;
}

function odata_mimir_timeout_seconds_for_sapi(string $sapi): int
{
    return strtolower($sapi) === 'cli' ? 600 : 90;
}

function odata_mimir_timeout_seconds(): int
{
    return odata_mimir_timeout_seconds_for_sapi(PHP_SAPI);
}

class OdataMimirException extends Exception
{
}

function odata_mimir_fail(Exception $exception): void
{
    if ($exception instanceof OdataMimirException) {
        $failure = $exception;
    } else {
        $failure = new OdataMimirException($exception->getMessage(), (int) $exception->getCode(), $exception);
    }
    odata_mimir_trip($failure);
    throw $failure;
}

function odata_mimir_is_failure(Throwable $exception): bool
{
    return $exception instanceof OdataMimirException;
}

function odata_auth_is_usable($auth): bool
{
    if (!is_array($auth)) {
        return false;
    }
    $user = trim((string) ($auth['user'] ?? ''));
    if ($user === '') {
        return false;
    }
    $mode = (string) ($auth['mode'] ?? '');
    if ($mode !== 'basic' && $mode !== 'ntlm') {
        return false;
    }
    return array_key_exists('pass', $auth);
}

function odata_bc_base_url(): ?string
{
    global $baseUrl;
    if (!isset($baseUrl) || !is_string($baseUrl)) {
        return null;
    }
    $base = trim($baseUrl);
    if ($base === '' || stripos($base, 'mimir.invalid') !== false) {
        return null;
    }
    return $base;
}

function odata_bc_environment_is_real(string $environment): bool
{
    $environment = trim($environment);
    return $environment !== '' && strcasecmp($environment, 'mimir') !== 0;
}

function odata_bc_encode_environment(string $environment): string
{
    return rawurlencode(rawurldecode(trim($environment)));
}

function odata_bc_global_is_set(string $key): bool
{
    if (!array_key_exists($key, $GLOBALS) || $GLOBALS[$key] === null) {
        return false;
    }
    $value = $GLOBALS[$key];
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }
        if ($key === 'environment' && strcasecmp($trimmed, 'mimir') === 0) {
            return false;
        }
        if (($key === 'baseUrl' || $key === 'base') && stripos($trimmed, 'mimir.invalid') !== false) {
            return false;
        }
        return true;
    }
    if (is_array($value)) {
        return $value !== [];
    }
    return true;
}

/**
 * auth.php dat binnen een functie wordt geladen, zet variabelen lokaal.
 * Lees ze in een closure en kopieer ze naar $GLOBALS zonder gezette waarden te overschrijven.
 */
function odata_bc_load_auth_globals(?string $authFile = null): void
{
    static $loadedFiles = [];
    if ($authFile === null || $authFile === '') {
        $authFile = __DIR__ . '/auth.php';
    }
    if (isset($loadedFiles[$authFile])) {
        return;
    }
    $loadedFiles[$authFile] = true;
    if (!is_file($authFile)) {
        return;
    }

    $loaded = (static function (string $file): array {
        require $file;
        return [
            'baseUrl' => isset($baseUrl) ? $baseUrl : null,
            'auth' => isset($auth) ? $auth : null,
            'auth_list' => isset($auth_list) ? $auth_list : null,
            'environment' => isset($environment) ? $environment : null,
            'environments' => isset($environments) ? $environments : null,
            'base' => isset($base) ? $base : null,
        ];
    })($authFile);

    foreach ($loaded as $key => $value) {
        if ($value === null || odata_bc_global_is_set($key)) {
            continue;
        }
        $GLOBALS[$key] = $value;
    }
}

/**
 * BC-environments uit auth.php: $environments, anders $environment, anders $auth_list-sleutels.
 *
 * @return list<string>
 */
function odata_bc_environment_names(): array
{
    global $environments, $environment, $auth_list;
    $names = [];
    $push = static function ($candidate) use (&$names): void {
        if (!is_string($candidate) || !odata_bc_environment_is_real($candidate)) {
            return;
        }
        $candidate = trim($candidate);
        if (!in_array($candidate, $names, true)) {
            $names[] = $candidate;
        }
    };

    if (isset($environments) && is_array($environments) && $environments !== []) {
        foreach ($environments as $candidate) {
            $push($candidate);
        }
        if ($names !== []) {
            return $names;
        }
    }

    if (isset($environment) && is_string($environment)) {
        $push($environment);
        if ($names !== []) {
            return $names;
        }
    }

    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $key => $entry) {
            if (odata_auth_is_usable($entry)) {
                $push(is_string($key) ? $key : '');
            }
        }
    }

    return $names;
}

function odata_bc_environment(): ?string
{
    $names = odata_bc_environment_names();
    if ($names === []) {
        return null;
    }
    return $names[0];
}

function odata_bc_auth_for_fallback(array $passed): ?array
{
    if (odata_auth_is_usable($passed)) {
        return $passed;
    }
    global $auth, $auth_list;
    if (isset($auth) && odata_auth_is_usable($auth)) {
        return $auth;
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach (odata_bc_environment_names() as $name) {
            if (isset($auth_list[$name]) && odata_auth_is_usable($auth_list[$name])) {
                return $auth_list[$name];
            }
        }
        foreach ($auth_list as $entry) {
            if (odata_auth_is_usable($entry)) {
                return $entry;
            }
        }
    }
    return null;
}

function odata_bc_environment_from_odata_url(string $url): ?string
{
    $parts = parse_url($url);
    if (is_array($parts) && isset($parts['path']) && preg_match('#^/([^/]+)/ODataV4/#', (string) $parts['path'], $match) === 1) {
        $env = rawurldecode($match[1]);
        if (odata_bc_environment_is_real($env)) {
            return $env;
        }
    }
    return odata_bc_environment();
}

function odata_bc_auth_for_environment(?string $environment, array $passed): ?array
{
    if (odata_auth_is_usable($passed)) {
        return $passed;
    }
    global $auth_list;
    if ($environment !== null && isset($auth_list) && is_array($auth_list) && isset($auth_list[$environment]) && odata_auth_is_usable($auth_list[$environment])) {
        return $auth_list[$environment];
    }
    return odata_bc_auth_for_fallback($passed);
}

function odata_bc_credentials_configured(): bool
{
    odata_bc_load_auth_globals();
    if (odata_bc_base_url() === null || odata_bc_environment_names() === []) {
        return false;
    }
    return odata_bc_auth_for_fallback([]) !== null;
}

function odata_bc_remember_company_environment(string $company, string $environment): void
{
    $company = trim($company);
    $environment = trim($environment);
    if ($company === '' || !odata_bc_environment_is_real($environment)) {
        return;
    }
    if (!isset($GLOBALS['odata_bc_company_environment_map']) || !is_array($GLOBALS['odata_bc_company_environment_map'])) {
        $GLOBALS['odata_bc_company_environment_map'] = [];
    }
    $GLOBALS['odata_bc_company_environment_map'][$company] = $environment;
}

function odata_bc_known_company_environment(string $company): ?string
{
    $company = trim($company);
    if ($company === '') {
        return null;
    }

    $maps = [];
    if (isset($GLOBALS['companyEnvironmentMap']) && is_array($GLOBALS['companyEnvironmentMap'])) {
        $maps[] = $GLOBALS['companyEnvironmentMap'];
    }
    if (function_exists('auth_get_company_environment_map')) {
        $discovered = auth_get_company_environment_map();
        if (is_array($discovered)) {
            $maps[] = $discovered;
        }
    }
    if (isset($GLOBALS['odata_bc_company_environment_map']) && is_array($GLOBALS['odata_bc_company_environment_map'])) {
        $maps[] = $GLOBALS['odata_bc_company_environment_map'];
    }

    foreach ($maps as $map) {
        if (!isset($map[$company]) || !is_string($map[$company])) {
            continue;
        }
        $environment = trim($map[$company]);
        if (odata_bc_environment_is_real($environment)) {
            return $environment;
        }
    }

    return null;
}

function odata_bc_environment_in_url(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['path'])) {
        return null;
    }
    if (preg_match('#/([^/]+)/ODataV4(?:/|$)#', (string) $parts['path'], $match) !== 1) {
        return null;
    }
    $environment = rawurldecode($match[1]);
    if (!odata_bc_environment_is_real($environment)) {
        return null;
    }
    return trim($environment);
}

function odata_bc_company_from_url(string $url): ?string
{
    $parsed = odata_mimir_parse_entity_url($url);
    if ($parsed === null) {
        return null;
    }
    $company = trim((string) ($parsed['company'] ?? ''));
    return $company === '' ? null : $company;
}

/**
 * Environment van het gevraagde bedrijf. Een echt URL-segment wint; anders de company-map.
 * Alleen als het bedrijf onbekend is, valt dit terug op de primaire environment.
 */
function odata_bc_environment_for_request(string $url, ?string $company = null): ?string
{
    $fromUrl = odata_bc_environment_in_url($url);
    if ($fromUrl !== null) {
        return $fromUrl;
    }
    if ($company === null || $company === '') {
        $company = odata_bc_company_from_url($url);
    }
    if ($company !== null) {
        $fromCompany = odata_bc_known_company_environment($company);
        if ($fromCompany !== null) {
            return $fromCompany;
        }
    }
    return odata_bc_environment();
}

/**
 * Credentials voor een direct-BC-pad.
 * Een eigen bruikbare $auth_list-entry wint. Anders $auth (of meegegeven bruikbare
 * credentials) als $auth_list ontbreekt of leeg is, of als het environment de primaire
 * $environment is (hoofdletterongevoelig). Een ander environment zonder entry, terwijl
 * $auth_list gevuld is, geeft null; de aanroeper gooit dan de oorspronkelijke Mímir-fout.
 *
 * @param bool $environmentKnown Aanroepers geven door of het environment uit de URL of company-map komt.
 * @return array<string, mixed>|null
 */
function odata_bc_auth_for_resolved_environment(?string $environment, bool $environmentKnown, array $passed): ?array
{
    global $auth, $auth_list;

    $name = $environment !== null ? trim($environment) : '';
    $list = (isset($auth_list) && is_array($auth_list)) ? $auth_list : [];
    if ($name !== '') {
        if (isset($list[$name]) && odata_auth_is_usable($list[$name])) {
            return $list[$name];
        }
        foreach ($list as $key => $entry) {
            if (!is_string($key) || strcasecmp(trim($key), $name) !== 0) {
                continue;
            }
            if (odata_auth_is_usable($entry)) {
                return $entry;
            }
        }
    }

    $primary = '';
    if (isset($GLOBALS['environment']) && is_string($GLOBALS['environment'])) {
        $primary = trim($GLOBALS['environment']);
    }
    $listEmpty = ($list === []);
    $isPrimary = ($name !== '' && $primary !== '' && strcasecmp($name, $primary) === 0);
    if (!$listEmpty && !$isPrimary && ($name !== '' || $environmentKnown)) {
        return null;
    }
    if (odata_auth_is_usable($passed)) {
        return $passed;
    }
    if (isset($auth) && odata_auth_is_usable($auth)) {
        return $auth;
    }
    return null;
}

function odata_mimir_log_fallback(Throwable $exception): void
{
    $message = $exception->getMessage();
    $redactions = [];
    $apiKey = odata_mimir_api_key();
    if ($apiKey !== '') {
        $redactions[] = $apiKey;
    }
    global $auth, $auth_list;
    if (isset($auth) && is_array($auth) && isset($auth['pass']) && is_string($auth['pass']) && $auth['pass'] !== '') {
        $redactions[] = $auth['pass'];
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (is_array($entry) && isset($entry['pass']) && is_string($entry['pass']) && $entry['pass'] !== '') {
                $redactions[] = $entry['pass'];
            }
        }
    }
    foreach ($redactions as $secret) {
        $message = str_replace($secret, '[redacted]', $message);
    }
    $sanitized = preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $message);
    if (is_string($sanitized)) {
        $message = $sanitized;
    }
    error_log('[Mercurius] Mímir failed, falling back to direct OData: ' . $message);
}

function odata_mimir_rethrow_original(): void
{
    $previous = odata_mimir_last_error();
    if ($previous instanceof Throwable) {
        throw $previous;
    }
    throw new Exception('Mímir mislukt.');
}

/**
 * @template T
 * @param callable(): T $viaMimir
 * @param callable(): T $viaDirect
 * @return T
 */
function odata_mimir_or_direct(callable $viaMimir, callable $viaDirect)
{
    if (odata_mimir_circuit_open()) {
        if (!odata_bc_credentials_configured()) {
            odata_mimir_rethrow_original();
        }
        return $viaDirect();
    }

    try {
        return $viaMimir();
    } catch (Throwable $exception) {
        if (!odata_mimir_is_failure($exception)) {
            throw $exception;
        }
        odata_mimir_trip($exception);
        if (!odata_bc_credentials_configured()) {
            throw $exception;
        }
        odata_mimir_log_fallback($exception);
        return $viaDirect();
    }
}

function odata_bc_url_from_odata_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== 'mimir.invalid') {
        return $url;
    }
    $base = odata_bc_base_url();
    if ($base === null) {
        return $url;
    }
    $path = (string) ($parts['path'] ?? '');
    if (preg_match('#^/([^/]+)(/.+)$#', $path, $match) !== 1) {
        return $url;
    }
    $env = rawurldecode($match[1]);
    if (!odata_bc_environment_is_real($env)) {
        $fallbackEnv = odata_bc_environment_for_request($url);
        if ($fallbackEnv === null) {
            return $url;
        }
        $env = $fallbackEnv;
    }
    $rebuilt = $base . odata_bc_encode_environment($env) . $match[2];
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        $rebuilt .= '?' . $parts['query'];
    }
    return $rebuilt;
}

function odata_mimir_request(string $method, string $path, ?array $jsonBody = null): array
{
    $apiKey = odata_mimir_api_key();
    if ($apiKey === '') {
        throw new Exception('Mímir API-sleutel ontbreekt ($mimirApi).');
    }

    if (odata_mimir_circuit_open()) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir overgeslagen na eerdere fout in dit verzoek.');
    }

    $url = odata_mimir_base_url() . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
        'X-API-Key: ' . $apiKey,
    ];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => odata_mimir_connect_timeout_seconds(),
        CURLOPT_TIMEOUT => odata_mimir_timeout_seconds(),
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mercurius-MimirClient/1.0',
    ];
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            curl_close($ch);
            throw new Exception('Mímir request JSON encode mislukt.');
        }
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        odata_mimir_fail(new Exception('Mímir cURL error: ' . $err));
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? $raw) : $raw;
        odata_mimir_fail(new Exception('Mímir HTTP ' . $code . ': ' . $message));
    }
    if (!is_array($decoded)) {
        odata_mimir_fail(new Exception('Mímir gaf ongeldige JSON terug.'));
    }
    $errorField = $decoded['error'] ?? null;
    if ($errorField !== null && $errorField !== '' && $errorField !== false) {
        $message = is_string($errorField) ? $errorField : (string) json_encode($errorField, JSON_UNESCAPED_UNICODE);
        odata_mimir_fail(new Exception('Mímir error: ' . $message));
    }
    return $decoded;
}

/**
 * @return array{company: string, entity: string, query: array<string, string>}|null
 */
function odata_mimir_parse_entity_url(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['path'])) {
        return null;
    }
    $path = (string) $parts['path'];
    // .../ODataV4/Company('Name')/EntitySet  or urlencoded company
    if (preg_match("#/ODataV4/Company\\((?:'([^']*)'|%27([^%]+)%27)\\)/([^/?]+)#i", $path, $match) !== 1) {
        return null;
    }
    $company = rawurldecode($match[1] !== '' ? $match[1] : $match[2]);
    $company = str_replace("''", "'", $company);
    $entity = rawurldecode($match[3]);
    $query = [];
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        parse_str($parts['query'], $parsed);
        foreach ($parsed as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $query[$key] = (string) $value;
            }
        }
    }
    return [
        'company' => $company,
        'entity' => $entity,
        'query' => $query,
    ];
}

/**
 * @return array{environment: string}|null
 */
function odata_mimir_parse_companies_url(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['path'])) {
        return null;
    }
    $path = (string) $parts['path'];
    // .../{environment}/ODataV4/Company or Companies
    if (preg_match('#/([^/]+)/ODataV4/(?:Companies|Company)(?:/|\\?|$)#i', $path . (isset($parts['query']) ? '?' : ''), $match) !== 1
        && preg_match('#/([^/]+)/ODataV4/(?:Companies|Company)$#i', $path, $match) !== 1) {
        return null;
    }
    return ['environment' => rawurldecode($match[1])];
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_companies_as_rows_impl(?string $environment = null): array
{
    $response = odata_mimir_request('GET', 'companies.php');
    $items = $response['value'] ?? null;
    if (!is_array($items)) {
        odata_mimir_fail(new Exception("Mímir companies-antwoord mist 'value'."));
    }
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? $item['Name'] ?? ''));
        $env = trim((string) ($item['environment'] ?? ''));
        if ($name === '') {
            continue;
        }
        if ($environment !== null && $environment !== '' && $env !== '' && strcasecmp($env, $environment) !== 0) {
            continue;
        }
        $rows[] = ['Name' => $name, 'environment' => $env];
    }
    return $rows;
}

/**
 * Directe BC-companylijst via de pre-Mímir OData-route ({base}/{env}/ODataV4/Company).
 * Zonder filter worden alle environments uit auth.php bevraagd.
 * Zonder $auth_list gaat dat met $auth naar de primaire environment ($baseUrl + $environment).
 *
 * @return list<array<string, mixed>>
 */
function odata_direct_companies_as_rows(?string $environmentFilter = null): array
{
    $envs = [];
    if ($environmentFilter !== null && trim($environmentFilter) !== '' && strcasecmp(trim($environmentFilter), 'mimir') !== 0) {
        $envs = [trim($environmentFilter)];
    } else {
        $envs = odata_bc_environment_names();
        global $auth_list, $environment;
        $listMissing = !isset($auth_list) || !is_array($auth_list) || $auth_list === [];
        if ($listMissing && isset($environment) && is_string($environment) && odata_bc_environment_is_real($environment)) {
            $envs = [trim($environment)];
        }
    }
    $base = odata_bc_base_url();
    if ($base === null || $envs === []) {
        odata_mimir_rethrow_original();
    }

    $out = [];
    $anyAuth = false;
    foreach ($envs as $env) {
        $auth = odata_bc_auth_for_resolved_environment($env, true, []);
        if ($auth === null) {
            continue;
        }
        $anyAuth = true;
        $rows = odata_get_all_direct($base . odata_bc_encode_environment($env) . '/ODataV4/Company', $auth, 300);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            odata_bc_remember_company_environment($name, $env);
            $out[] = ['Name' => $name, 'environment' => $env];
        }
    }
    if (!$anyAuth) {
        odata_mimir_rethrow_original();
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_companies_as_rows(?string $environment = null): array
{
    $fromMimir = static function () use ($environment): array {
        return odata_mimir_companies_as_rows_impl($environment);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($environment): array {
            return odata_direct_companies_as_rows($environment);
        }
    );
}

/**
 * Bedrijfsnamen via Mímir companies.php (gesorteerd).
 *
 * @return list<string>
 */
function odata_mimir_list_companies(?string $environment = null): array
{
    $rows = odata_mimir_companies_as_rows($environment);
    $names = [];
    $seen = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['Name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $name;
    }
    natcasesort($names);
    return array_values($names);
}

/**
 * name => environment map uit Mímir companies.php.
 *
 * @return array<string, string>
 */
function odata_mimir_company_environment_map(?string $environment = null): array
{
    $rows = odata_mimir_companies_as_rows($environment);
    $map = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['Name'] ?? ''));
        $env = trim((string) ($row['environment'] ?? ''));
        if ($name === '' || $env === '') {
            continue;
        }
        $map[$name] = $env;
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    return $map;
}

/**
 * Render a Mímir JSON filter leaf (or a small and/or tree) as an OData $filter.
 * Strings pass through. Empty string means the value cannot be expressed.
 *
 * @param mixed $filter
 */
function odata_filter_to_odata_string($filter): string
{
    if (is_string($filter)) {
        return trim($filter);
    }
    if (!is_array($filter)) {
        return '';
    }
    if (isset($filter['field'], $filter['op']) && array_key_exists('value', $filter)
        && !isset($filter['and']) && !isset($filter['or']) && !isset($filter['xor'])) {
        return odata_filter_leaf_to_odata_string($filter);
    }
    foreach (['and' => ' and ', 'or' => ' or '] as $key => $glue) {
        if (!isset($filter[$key]) || !is_array($filter[$key])) {
            continue;
        }
        $parts = [];
        foreach ($filter[$key] as $child) {
            $part = odata_filter_to_odata_string($child);
            if ($part === '') {
                return '';
            }
            $parts[] = '(' . $part . ')';
        }
        if ($parts === []) {
            return '';
        }
        return implode($glue, $parts);
    }
    return '';
}

/**
 * @param array<string, mixed> $leaf
 */
function odata_filter_leaf_to_odata_string(array $leaf): string
{
    $field = trim((string) ($leaf['field'] ?? ''));
    $op = strtolower(trim((string) ($leaf['op'] ?? '')));
    if ($field === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $field) !== 1) {
        return '';
    }
    $compare = ['eq', 'ne', 'gt', 'ge', 'lt', 'le'];
    $functions = ['contains', 'startswith', 'endswith'];
    if (!in_array($op, $compare, true) && !in_array($op, $functions, true)) {
        return '';
    }
    $literal = odata_filter_odata_literal($leaf['value'] ?? null);
    if ($literal === null) {
        return '';
    }
    if (in_array($op, $functions, true)) {
        return $op . '(' . $field . ',' . $literal . ')';
    }
    return $field . ' ' . $op . ' ' . $literal;
}

/**
 * @param mixed $value
 */
function odata_filter_odata_literal($value): ?string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === null) {
        return 'null';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        return odata_filter_float_literal($value);
    }
    if (!is_string($value)) {
        return null;
    }
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Shortest round-trip decimal literal for an OData $filter.
 * Fixed formats such as %.10F turn 1e-11 into 0; %.17F turns 0.1 into a binary artifact.
 *
 * @return string|null
 */
function odata_filter_float_literal(float $value): ?string
{
    if (!is_finite($value)) {
        return null;
    }
    $previous = ini_get('serialize_precision');
    ini_set('serialize_precision', '-1');
    try {
        $encoded = json_encode($value);
    } finally {
        if (is_string($previous) && $previous !== '') {
            ini_set('serialize_precision', $previous);
        }
    }
    if (!is_string($encoded) || $encoded === '' || strcasecmp($encoded, 'null') === 0) {
        return null;
    }
    if ($encoded === '-0') {
        return '0';
    }
    if (stripos($encoded, 'e') === false) {
        return $encoded;
    }
    return odata_filter_expand_decimal($encoded);
}

/**
 * 1.0e-11 → 0.00000000001, so Business Central receives a decimal literal.
 */
function odata_filter_expand_decimal(string $scientific): ?string
{
    if (preg_match('/^(-?)(\d+)(?:\.(\d+))?e([+-]?\d+)$/i', $scientific, $match) !== 1) {
        return null;
    }
    $sign = $match[1];
    $digits = $match[2] . (isset($match[3]) ? $match[3] : '');
    $point = strlen($match[2]) + (int) $match[4];
    if ($point <= 0) {
        $rendered = '0.' . str_repeat('0', -$point) . $digits;
    } elseif ($point >= strlen($digits)) {
        $rendered = $digits . str_repeat('0', $point - strlen($digits));
    } else {
        $rendered = substr($digits, 0, $point) . '.' . substr($digits, $point);
    }
    if (strpos($rendered, '.') !== false) {
        $parts = explode('.', $rendered, 2);
        $whole = ltrim($parts[0], '0');
        $fraction = rtrim($parts[1], '0');
        if ($whole === '') {
            $whole = '0';
        }
        $rendered = $fraction === '' ? $whole : ($whole . '.' . $fraction);
    } else {
        $rendered = ltrim($rendered, '0');
        if ($rendered === '') {
            $rendered = '0';
        }
    }
    if ($rendered === '0') {
        return '0';
    }
    return $sign . $rendered;
}

/**
 * Body filter for Mímir query.php.
 * Structured arrays are sent as JSON. The simple ledger cases "Open eq true/false"
 * are upgraded from the opaque OData string (URL round-trip) to the same JSON leaf,
 * so coverage-serve can match booleans locally. Other strings stay opaque.
 *
 * @param mixed $filter
 * @return array<string, mixed>|string|null
 */
function odata_mimir_filter_for_request($filter)
{
    if (is_array($filter)) {
        return $filter;
    }
    if (!is_string($filter) && !is_numeric($filter)) {
        return null;
    }
    $text = trim((string) $filter);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^Open\s+eq\s+(true|false)$/i', $text, $match) === 1) {
        return [
            'field' => 'Open',
            'op' => 'eq',
            'value' => strtolower($match[1]) === 'true',
        ];
    }
    return $text;
}

/**
 * Actualiseren: één paginaload waarin elke OData-fetch live moet zijn.
 * Zolang dit actief is stuurt elke Mímir query.php-aanroep max_age=0 (Mímir haalt live
 * bij BC op en slaat het resultaat weer in zijn cache op) en slaat de directe BC-fallback
 * het lezen van de lokale odata-filecache over (het verse antwoord wordt wel weggeschreven).
 */
function odata_force_refresh_set(bool $active): void
{
    $GLOBALS['MERCURIUS_FORCE_REFRESH'] = $active;
}

function odata_force_refresh_active(): bool
{
    return !empty($GLOBALS['MERCURIUS_FORCE_REFRESH']);
}

/**
 * Eenmalige ?actualiseren=1 uit de knop "Actualiseren". Cross-site navigaties
 * (Sec-Fetch-Site: cross-site / same-site) mogen geen live BC-load afdwingen.
 *
 * @param array<string, mixed> $query
 * @param array<string, mixed> $server
 */
function odata_force_refresh_requested(array $query, array $server): bool
{
    $flag = $query['actualiseren'] ?? '';
    if (!is_string($flag) || trim($flag) !== '1') {
        return false;
    }
    $site = strtolower(trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')));
    return $site === '' || $site === 'same-origin' || $site === 'none';
}

/**
 * Verzamelt meta uit Mímir query-antwoorden van dit verzoek (voor de Actualiseren-melding).
 *
 * @param array<string, mixed> $meta
 */
function odata_mimir_remember_meta(string $company, string $table, array $meta): void
{
    if (!isset($GLOBALS['MERCURIUS_MIMIR_META']) || !is_array($GLOBALS['MERCURIUS_MIMIR_META'])) {
        $GLOBALS['MERCURIUS_MIMIR_META'] = [];
    }
    $GLOBALS['MERCURIUS_MIMIR_META'][] = [
        'company' => $company,
        'table' => $table,
        'max_age' => isset($meta['max_age']) ? (int) $meta['max_age'] : null,
        'from_cache' => isset($meta['from_cache']) ? (int) $meta['from_cache'] : null,
        'from_live' => isset($meta['from_live']) ? (int) $meta['from_live'] : null,
        'fetched_at_min' => isset($meta['fetched_at_min']) ? (int) $meta['fetched_at_min'] : null,
        'fetched_at_max' => isset($meta['fetched_at_max']) ? (int) $meta['fetched_at_max'] : null,
        'source' => isset($meta['source']) && is_string($meta['source']) ? $meta['source'] : null,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_request_meta(): array
{
    $meta = $GLOBALS['MERCURIUS_MIMIR_META'] ?? [];
    return is_array($meta) ? array_values($meta) : [];
}

/**
 * max_age for a Mímir query. forceRefresh must miss fresh coverage (OpenAPI minimum is 0;
 * there is no separate force flag). ttl 0 without forceRefresh keeps the previous 1h default.
 */
function odata_mimir_max_age_seconds(int $ttlSeconds, bool $forceRefresh): int
{
    if ($forceRefresh) {
        return 0;
    }
    if ($ttlSeconds <= 0) {
        return 3600;
    }
    return $ttlSeconds;
}

/**
 * JSON body for POST query.php. $ttlSeconds is sent as max_age (0 is allowed).
 *
 * @param array<string, mixed> $odataQuery
 * @return array<string, mixed>
 */
function odata_mimir_query_body(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    $body = [
        'company' => $company,
        'table' => $table,
        // Actualiseren forceert max_age=0 voor elke query in dit verzoek.
        'max_age' => odata_force_refresh_active() ? 0 : max(0, $ttlSeconds),
        'top' => 0,
    ];

    $select = $odataQuery['$select'] ?? $odataQuery['select'] ?? '';
    if (is_string($select) || is_numeric($select)) {
        $select = trim((string) $select);
        if ($select !== '') {
            $cols = [];
            foreach (explode(',', $select) as $col) {
                $col = trim($col);
                if ($col !== '') {
                    $cols[] = $col;
                }
            }
            if ($cols !== []) {
                $body['select'] = $cols;
            }
        }
    }

    $rawFilter = null;
    if (array_key_exists('$filter', $odataQuery)) {
        $rawFilter = $odataQuery['$filter'];
    } elseif (array_key_exists('filter', $odataQuery)) {
        $rawFilter = $odataQuery['filter'];
    }
    $filter = odata_mimir_filter_for_request($rawFilter);
    if ($filter !== null) {
        $body['filter'] = $filter;
    }

    return $body;
}

/**
 * Directe company/table-query via Mímir — geen BC-URL nodig.
 * $odataQuery gebruikt OData-keys zoals $select / $filter.
 * $filter mag een OData-string of een gestructureerd Mímir-filter zijn.
 *
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_mimir_query_impl(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    consolelog("Mímir query company=$company table=$table\n");

    $body = odata_mimir_query_body($company, $table, $odataQuery, $ttlSeconds);

    $response = odata_mimir_request('POST', 'query.php', $body);
    if (!isset($response['value']) || !is_array($response['value'])) {
        odata_mimir_fail(new Exception("Mímir query-antwoord mist 'value'."));
    }
    if (isset($response['meta']) && is_array($response['meta'])) {
        odata_mimir_remember_meta($company, $table, $response['meta']);
    }
    /** @var list<array<string, mixed>> $value */
    $value = $response['value'];
    return $value;
}

/**
 * Zelfde company/table-query, maar via de pre-Mímir BC-URL en filecache.
 *
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_direct_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    odata_bc_load_auth_globals();
    $known = odata_bc_known_company_environment($company);
    $env = $known ?? odata_bc_environment();
    $base = odata_bc_base_url();
    $auth = odata_bc_auth_for_resolved_environment($env, $known !== null, []);
    if ($env === null || $base === null || $auth === null) {
        odata_mimir_rethrow_original();
    }

    $params = [];
    foreach (['$select', '$filter', '$orderby', '$expand', '$top', '$skip', 'select', 'filter'] as $key) {
        if (!array_key_exists($key, $odataQuery)) {
            continue;
        }
        $value = $odataQuery[$key];
        if (($key === '$filter' || $key === 'filter') && is_array($value)) {
            $value = odata_filter_to_odata_string($value);
            if ($value === '') {
                throw new Exception('Gestructureerd OData-filter kon niet naar een $filter-string worden omgezet.');
            }
        }
        if (!is_string($value) && !is_numeric($value)) {
            continue;
        }
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $odataKey = ($key === 'select' || $key === 'filter') ? ('$' . $key) : $key;
        $params[$odataKey] = $value;
    }

    $url = $base . odata_bc_encode_environment($env) . "/ODataV4/Company('" . rawurlencode($company) . "')/" . $table;
    if ($params !== []) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    return odata_get_all_direct($url, $auth, $ttlSeconds);
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_mimir_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    $fromMimir = static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
        return odata_mimir_query_impl($company, $table, $odataQuery, $ttlSeconds);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
            return odata_direct_query($company, $table, $odataQuery, $ttlSeconds);
        }
    );
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_fetch_all_impl(string $url, int $ttlSeconds): array
{
    consolelog("Mímir fetch $url\n");

    $companies = odata_mimir_parse_companies_url($url);
    if ($companies !== null) {
        return odata_mimir_companies_as_rows_impl($companies['environment']);
    }

    $parsed = odata_mimir_parse_entity_url($url);
    if ($parsed === null) {
        throw new Exception('Mímir: OData-URL kon niet worden vertaald naar company/table: ' . $url);
    }

    return odata_mimir_query_impl($parsed['company'], $parsed['entity'], $parsed['query'], $ttlSeconds);
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_fetch_all(string $url, int $ttlSeconds): array
{
    $fromMimir = static function () use ($url, $ttlSeconds): array {
        return odata_mimir_fetch_all_impl($url, $ttlSeconds);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($url, $ttlSeconds): array {
            $directUrl = odata_bc_url_from_odata_url($url);
            $company = odata_bc_company_from_url($url);
            $environmentKnown = odata_bc_environment_in_url($url) !== null
                || ($company !== null && odata_bc_known_company_environment($company) !== null);
            $resolvedEnv = odata_bc_environment_in_url($directUrl);
            if ($resolvedEnv === null) {
                $resolvedEnv = odata_bc_environment_for_request($url, $company);
            }
            $auth = odata_bc_auth_for_resolved_environment($resolvedEnv, $environmentKnown, []);
            if ($auth === null) {
                odata_mimir_rethrow_original();
            }
            return odata_get_all_direct($directUrl, $auth, $ttlSeconds);
        }
    );
}

// BC-globals blijven beschikbaar als fallback. Alleen ontbrekende waarden worden aangevuld;
// een gezet $baseUrl / $environments / $auth_list uit auth.php wordt niet overschreven.
// Toewijzingen gaan naar $GLOBALS: dit bestand kan vanuit een functie ge-include worden.
if (odata_mimir_enabled()) {
    odata_bc_load_auth_globals();
    if (!odata_bc_global_is_set('environment')) {
        $odataBcEnvironment = odata_bc_environment();
        $GLOBALS['environment'] = $odataBcEnvironment !== null ? $odataBcEnvironment : 'mimir';
    }
    if (!odata_bc_global_is_set('auth')) {
        $odataBcAuth = odata_bc_auth_for_fallback([]);
        if ($odataBcAuth !== null) {
            $GLOBALS['auth'] = $odataBcAuth;
        }
    }
    if (!odata_bc_global_is_set('baseUrl')) {
        $GLOBALS['baseUrl'] = 'https://mimir.invalid/';
    }
}

function odata_get_all(string $url, array $auth, $ttlSeconds = 300, bool $forceRefresh = false): array
{
    consolelog("Fetching $url\n");
    $ttlSeconds = max(0, (int) $ttlSeconds);
    $forceRefresh = $forceRefresh || odata_force_refresh_active();

    if (odata_mimir_enabled()) {
        return odata_mimir_or_direct(
            static function () use ($url, $ttlSeconds, $forceRefresh): array {
                // forceRefresh → max_age=0, zodat Mímir verse dekking bij BC moet ophalen.
                // Zonder forceRefresh blijft ttl 0 de oude default van 3600.
                $maxAge = odata_mimir_max_age_seconds($ttlSeconds, $forceRefresh);
                return odata_mimir_fetch_all_impl($url, $maxAge);
            },
            static function () use ($url, $auth, $ttlSeconds, $forceRefresh): array {
                $directUrl = odata_bc_url_from_odata_url($url);
                $company = odata_bc_company_from_url($url);
                $environmentKnown = odata_bc_environment_in_url($url) !== null || ($company !== null && odata_bc_known_company_environment($company) !== null);
                $resolvedEnv = odata_bc_environment_in_url($directUrl);
                if ($resolvedEnv === null) {
                    $resolvedEnv = odata_bc_environment_for_request($url, $company);
                }
                $directAuth = odata_bc_auth_for_resolved_environment($resolvedEnv, $environmentKnown, $auth);
                if ($directAuth === null) {
                    odata_mimir_rethrow_original();
                }
                return odata_get_all_direct($directUrl, $directAuth, $ttlSeconds, $forceRefresh);
            }
        );
    }

    return odata_get_all_direct($url, $auth, $ttlSeconds, $forceRefresh);
}

function odata_get_all_direct(string $url, array $auth, $ttlSeconds = 300, bool $forceRefresh = false): array
{
    $ttlSeconds = max(1, (int) $ttlSeconds);
    // Actualiseren: lokale filecache niet lezen, wel opnieuw vullen met het live antwoord.
    $forceRefresh = $forceRefresh || odata_force_refresh_active();
    if (isset($GLOBALS['MERCURIUS_ODATA_BC_FETCH']) && is_callable($GLOBALS['MERCURIUS_ODATA_BC_FETCH'])) {
        return $GLOBALS['MERCURIUS_ODATA_BC_FETCH']($url, $auth, $ttlSeconds);
    }

    maybe_cleanup_expired_cache_files();

    $cacheKey = build_cache_key($url, $auth);
    $cachePath = cache_path_for_key($cacheKey);

    if (!$forceRefresh && is_file($cachePath)) {
        $cached = read_cache_payload($cachePath, $ttlSeconds);
        if ($cached['valid']) {
            return $cached['data'];
        }

        if ($cached['delete']) {
            @unlink($cachePath);
        }
    }

    // Live BC fetch can paginate for several minutes; keep PHP from aborting mid-way
    // so the result can be written to cache for subsequent page loads.
    odata_extend_execution_budget();

    $all = [];
    $next = $url;

    while ($next) {
        odata_extend_execution_budget();
        $resp = odata_get_json($next, $auth);

        if (!isset($resp['value']) || !is_array($resp['value'])) {
            throw new Exception("OData response missing 'value' array");
        }

        $all = array_merge($all, $resp['value']);
        $next = $resp['@odata.nextLink'] ?? null;
    }

    write_cache_json($cachePath, $all, $ttlSeconds, $url);
    return $all;
}

function odata_extend_execution_budget(int $seconds = 7200): void
{
    @ini_set('max_execution_time', (string) $seconds);
    if (function_exists('set_time_limit')) {
        @set_time_limit($seconds);
    }
}

function odata_get_json(string $url, array $auth): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 60,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_HTTPHEADER => [
            "Accept: application/json",
        ],
    ]);

    // Auth: kies 1.
    if (($auth['mode'] ?? '') === 'basic') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ":" . $auth['pass']);
    } elseif (($auth['mode'] ?? '') === 'ntlm') {
        // Werkt als BC via Windows auth/NTLM gaat:
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ":" . $auth['pass']);
    }

    // (optioneel) als je met interne CA/self-signed werkt:
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error: " . $error);
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new Exception("HTTP $code from OData: $raw");
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new Exception("Invalid JSON from OData");
    }

    return $json;
}

function odata_bc_cache_environment(string $url): string
{
    $fromUrl = odata_bc_environment_in_url($url);
    if ($fromUrl !== null) {
        return $fromUrl;
    }
    $company = odata_bc_company_from_url($url);
    if ($company !== null) {
        $fromCompany = odata_bc_known_company_environment($company);
        if ($fromCompany !== null) {
            return $fromCompany;
        }
    }
    $primary = odata_bc_environment();
    if ($primary !== null) {
        return $primary;
    }
    return '';
}

function build_cache_key(string $url, array $auth): string
{
    $user = (string) ($auth['user'] ?? '');

    $environmentSignature = '';
    if (function_exists('auth_environment_signature')) {
        try {
            $environmentSignature = auth_environment_signature();
        } catch (Throwable $exception) {
            $environmentSignature = '';
        }
    }

    if ($environmentSignature === '' || strcasecmp($environmentSignature, 'mimir') === 0) {
        $raw = $GLOBALS['environments'] ?? ($GLOBALS['environment'] ?? '');
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $text = trim((string) $value);
                if ($text !== '' && strcasecmp($text, 'mimir') !== 0) {
                    $parts[] = $text;
                }
            }
            $environmentSignature = implode('|', array_values(array_unique($parts)));
        } else {
            $text = trim((string) $raw);
            $environmentSignature = strcasecmp($text, 'mimir') === 0 ? '' : $text;
        }
    }

    if ($environmentSignature === '' || strcasecmp($environmentSignature, 'mimir') === 0) {
        $environmentSignature = odata_bc_cache_environment($url);
    }

    return $url . '|' . $user . '|' . $environmentSignature;
}

function cache_base_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR . "odata";
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function cache_cleanup_marker_path(): string
{
    return cache_base_dir() . "/.cleanup_marker";
}

function maybe_cleanup_expired_cache_files(): void
{
    $markerPath = cache_cleanup_marker_path();
    $now = time();
    $intervalSeconds = 60;

    if (is_file($markerPath)) {
        $lastRun = (int) @file_get_contents($markerPath);
        if ($lastRun > 0 && ($now - $lastRun) < $intervalSeconds) {
            return;
        }
    }

    @file_put_contents($markerPath, (string) $now, LOCK_EX);

    $entries = @scandir(cache_base_dir());
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.cleanup_marker') {
            continue;
        }

        $path = cache_base_dir() . '/' . $entry;
        if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'json') {
            continue;
        }

        $cached = read_cache_payload($path, 0);
        if ($cached['delete']) {
            @unlink($path);
            continue;
        }

        $fallbackMaxAge = 86400;
        $age = $now - (int) @filemtime($path);
        if ($age > $fallbackMaxAge) {
            @unlink($path);
        }
    }
}

function read_cache_payload(string $path, int $fallbackTtlSeconds): array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    if (isset($payload['_meta']) && isset($payload['data']) && is_array($payload['data'])) {
        $expiresAt = (int) ($payload['_meta']['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() <= $expiresAt) {
            return ['valid' => true, 'delete' => false, 'data' => $payload['data']];
        }

        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    if ($fallbackTtlSeconds > 0) {
        $age = time() - (int) @filemtime($path);
        if ($age >= 0 && $age < $fallbackTtlSeconds) {
            return ['valid' => true, 'delete' => false, 'data' => $payload];
        }

        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    return ['valid' => false, 'delete' => false, 'data' => []];
}
function cache_path_for_key(string $cacheKey): string
{
    // bestandsnaam moet veilig en niet te lang: hash is ideaal
    $hash = hash('sha256', $cacheKey);
    return cache_base_dir() . "/" . $hash . ".json";
}

function write_cache_json(string $path, array $data, int $ttlSeconds, string $sourceUrl = ''): void
{
    $tmp = $path . ".tmp";
    $now = time();
    $payload = [
        '_meta' => [
            'cached_at' => $now,
            'expires_at' => $now + max(1, $ttlSeconds),
            'source_url' => $sourceUrl,
        ],
        'data' => $data,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new Exception("Failed to encode cache JSON");
    }

    file_put_contents($tmp, $json, LOCK_EX);
    rename($tmp, $path);
}
