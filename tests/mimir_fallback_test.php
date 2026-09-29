<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/mercurius-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['MERCURIUS_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';
require dirname(__DIR__) . '/web/functions.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Mercurius] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$syntheticCompanyUrl = odata_company_url('Production', 'KVT Gas', 'Customer_Ledger_Entries', ['$select' => 'No']);
if (strpos($syntheticCompanyUrl, 'https://mimir.invalid/Production/ODataV4/Company(') !== 0) {
    fail('met Mímir aan moet de company-URL synthetisch zijn, kreeg: ' . $syntheticCompanyUrl);
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$directCompanyUrl = odata_company_url('Production', 'KVT Gas', 'Customer_Ledger_Entries', ['$select' => 'No']);
if (strpos($directCompanyUrl, 'https://bc.example:7148/Production/ODataV4/Company(\'KVT%20Gas\')/Customer_Ledger_Entries?') !== 0) {
    fail('na de circuit-open moet odata_company_url de oude BC-URL bouwen, kreeg: ' . $directCompanyUrl);
}
if (strpos($directCompanyUrl, 'mimir.invalid') !== false) {
    fail('synthetische host bleef staan na fallback');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/Customer_Ledger_Entries?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/Customer_Ledger_Entries?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag een fallback loggen, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Mercurius] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppCustomerCard', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppCustomerCard?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/Customer_Ledger_Entries?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/Customer_Ledger_Entries?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$environments = ['env1', 'env2'];
$environment = 'mimir';
$auth = [];
$auth_list = [
    'env1' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
    'env2' => ['mode' => 'ntlm', 'user' => 'bcuser2', 'pass' => 'bc-secret'],
];
$beforeMulti = count($calls);
$multiNames = odata_mimir_list_companies(null);
if ($multiNames !== $expectedNames) {
    fail('multi-env company-fallback gaf ' . json_encode($multiNames));
}
if (count($calls) - $beforeMulti !== 2) {
    fail('multi-env fallback moet elk environment uit $environments bevragen: ' . json_encode(array_slice($calls, $beforeMulti)));
}
if (strpos($calls[$beforeMulti]['url'], 'https://bc.example:7148/env1/ODataV4/Company') !== 0 || $calls[$beforeMulti]['user'] !== 'bcuser') {
    fail('env1-fallback klopt niet: ' . json_encode($calls[$beforeMulti] ?? null));
}
if (strpos($calls[$beforeMulti + 1]['url'], 'https://bc.example:7148/env2/ODataV4/Company') !== 0 || $calls[$beforeMulti + 1]['user'] !== 'bcuser2') {
    fail('env2-fallback klopt niet: ' . json_encode($calls[$beforeMulti + 1] ?? null));
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$environments = [];
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/Customer_Ledger_Entries', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('zonder BC-credentials moet een exception terugkomen');
}
if (strpos($rethrown->getMessage(), 'Mímir') !== 0 && strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$environments = [];
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/Customer_Ledger_Entries?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'env1';
$environments = ['env1', 'env2'];
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = [
    'env1' => $auth,
    'env2' => ['mode' => 'basic', 'user' => 'bcuser2', 'pass' => 'bc-secret-env2'],
];
$GLOBALS['companyEnvironmentMap'] = [
    'KVT Gas' => 'env1',
    'Hunter van Twist' => 'env2',
];
odata_mimir_circuit_reset();
$loggedBeforeSecond = fallback_count();
$beforeSecond = count($calls);
$secondRows = odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/Customer_Ledger_Entries?\$select=No",
    $auth,
    33
);
if (($secondRows[0]['No'] ?? '') !== 'WO-1') {
    fail('tweede-environment-fallback gaf geen rijen');
}
$secondCall = $calls[$beforeSecond] ?? null;
$expectedSecondUrl = "https://bc.example:7148/env2/ODataV4/Company('Hunter%20van%20Twist')/Customer_Ledger_Entries?\$select=No";
if (!is_array($secondCall) || $secondCall['url'] !== $expectedSecondUrl || $secondCall['user'] !== 'bcuser2' || $secondCall['ttl'] !== 33) {
    fail('bedrijf in tweede environment gebruikte niet env2/auth_list: ' . json_encode($secondCall));
}
if (fallback_count() !== $loggedBeforeSecond + 1) {
    fail('een nieuwe Mímir-fout moet precies één keer loggen, log=' . fallback_log());
}
$loggedWhileOpen = fallback_count();
odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/Customer_Ledger_Entries?\$select=No",
    $auth,
    33
);
if (fallback_count() !== $loggedWhileOpen) {
    fail('open circuit mag niet opnieuw loggen, log=' . fallback_log());
}
if (strpos(fallback_log(), 'bc-secret-env2') !== false || strpos(fallback_log(), 'bc-secret') !== false) {
    fail('log bevat een geheim na tweede-environment-fallback');
}

odata_mimir_circuit_reset();
$beforeWin = count($calls);
odata_get_all(
    "https://mimir.invalid/env2/ODataV4/Company('KVT%20Gas')/Customer_Ledger_Entries?\$select=No",
    $auth,
    10
);
$winCall = $calls[$beforeWin] ?? null;
if (!is_array($winCall) || strpos((string) $winCall['url'], "https://bc.example:7148/env2/ODataV4/Company('KVT%20Gas')/") !== 0 || $winCall['user'] !== 'bcuser2') {
    fail('URL-environment moet boven de company-map gaan: ' . json_encode($winCall));
}

odata_mimir_circuit_reset();
$beforeDirectQuery = count($calls);
odata_mimir_query('KVT Gas', 'AppCustomerCard', ['$select' => 'No'], 12);
$directQueryCall = $calls[$beforeDirectQuery] ?? null;
if (!is_array($directQueryCall) || strpos((string) $directQueryCall['url'], "https://bc.example:7148/env1/ODataV4/Company('KVT%20Gas')/AppCustomerCard?") !== 0 || $directQueryCall['user'] !== 'bcuser') {
    fail('odata_direct_query koos niet de environment van het bedrijf: ' . json_encode($directQueryCall));
}

odata_mimir_circuit_reset();
$loggedBeforeCaller = fallback_count();
$callerThrew = false;
try {
    odata_mimir_fetch_all('https://example.test/not-an-odata-url', 5);
} catch (Throwable $exception) {
    $callerThrew = true;
}
if (!$callerThrew) {
    fail('een onvertaalbare URL moet een fout geven');
}
if (odata_mimir_circuit_open()) {
    fail('een fout van de caller mag het circuit niet openen');
}
if (fallback_count() !== $loggedBeforeCaller) {
    fail('een fout van de caller mag geen fallback loggen');
}

$GLOBALS['environment'] = 'mimir';
$GLOBALS['environments'] = ['mimir'];
$cacheKey = build_cache_key(
    "https://bc.example:7148/env2/ODataV4/Company('Hunter%20van%20Twist')/Customer_Ledger_Entries",
    ['user' => 'bcuser2']
);
$cacheSuffix = substr((string) strrchr($cacheKey, '|'), 1);
if ($cacheSuffix !== 'env2' || strpos($cacheKey, '|mimir') !== false) {
    fail('cache-key moet de echte BC-environment gebruiken, kreeg: ' . $cacheKey);
}

$authFile = tempnam(sys_get_temp_dir(), 'merc-auth');
if ($authFile === false) {
    fail('tijdelijk auth-bestand kon niet worden aangemaakt');
}
file_put_contents($authFile, <<<'PHP'
<?php
$baseUrl = 'https://from-auth.example/';
$auth = ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret'];
$auth_list = ['env2' => ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret']];
$environment = 'env2';
$environments = ['env2'];
$base = 'from-auth-base';
PHP
);
$GLOBALS['baseUrl'] = 'https://keep.example/';
unset($GLOBALS['auth'], $GLOBALS['auth_list'], $GLOBALS['environment'], $GLOBALS['environments'], $GLOBALS['base']);
odata_bc_load_auth_globals($authFile);
@unlink($authFile);
if (($GLOBALS['baseUrl'] ?? '') !== 'https://keep.example/') {
    fail('gezette baseUrl werd overschreven: ' . (string) ($GLOBALS['baseUrl'] ?? ''));
}
if (($GLOBALS['environment'] ?? '') !== 'env2') {
    fail('environment uit auth.php kwam niet in $GLOBALS');
}
if (($GLOBALS['auth']['user'] ?? '') !== 'loaded-user') {
    fail('auth uit auth.php kwam niet in $GLOBALS');
}
if (($GLOBALS['auth_list']['env2']['user'] ?? '') !== 'loaded-user') {
    fail('auth_list uit auth.php kwam niet in $GLOBALS');
}
if (($GLOBALS['base'] ?? '') !== 'from-auth-base') {
    fail('base uit auth.php kwam niet in $GLOBALS');
}
if (($GLOBALS['environments'][0] ?? '') !== 'env2') {
    fail('environments uit auth.php kwam niet in $GLOBALS');
}

$onlyAuth = ['mode' => 'basic', 'user' => 'only-auth-user', 'pass' => 'only-auth-secret'];
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$GLOBALS['baseUrl'] = $baseUrl;
$environment = 'Production';
$GLOBALS['environment'] = 'Production';
$environments = [];
$GLOBALS['environments'] = [];
$auth = $onlyAuth;
$GLOBALS['auth'] = $onlyAuth;
unset($auth_list, $GLOBALS['auth_list']);
$GLOBALS['companyEnvironmentMap'] = [];
$GLOBALS['odata_bc_company_environment_map'] = [];

odata_mimir_circuit_reset();
$beforeOnlyAuth = count($calls);
$onlyAuthNames = odata_mimir_list_companies(null);
if ($onlyAuthNames !== $expectedNames) {
    fail('companylijst met alleen $auth gaf ' . json_encode($onlyAuthNames));
}
$onlyAuthCompanyCall = $calls[$beforeOnlyAuth] ?? null;
if (count($calls) - $beforeOnlyAuth !== 1 || !is_array($onlyAuthCompanyCall) || strpos($onlyAuthCompanyCall['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0 || $onlyAuthCompanyCall['user'] !== 'only-auth-user') {
    fail('companylijst zonder $auth_list moet de primaire environment met $auth bevragen: ' . json_encode(array_slice($calls, $beforeOnlyAuth)));
}

odata_mimir_circuit_reset();
$beforeOnlyQuery = count($calls);
$onlyQueryRows = odata_mimir_query('Unmapped Co', 'AppCustomerCard', ['$select' => 'No'], 22);
$onlyQueryCall = $calls[$beforeOnlyQuery] ?? null;
if (($onlyQueryRows[0]['No'] ?? '') !== 'WO-1' || !is_array($onlyQueryCall) || strpos((string) $onlyQueryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('Unmapped%20Co')/AppCustomerCard?") !== 0 || $onlyQueryCall['user'] !== 'only-auth-user') {
    fail('query met alleen $auth ging niet naar BC: ' . json_encode($onlyQueryCall));
}

odata_mimir_circuit_reset();
$beforeOnlyFetch = count($calls);
$onlyFetchRows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Unmapped%20Co')/Customer_Ledger_Entries?\$select=No",
    [],
    23
);
$onlyFetchCall = $calls[$beforeOnlyFetch] ?? null;
$expectedOnlyFetch = "https://bc.example:7148/Production/ODataV4/Company('Unmapped%20Co')/Customer_Ledger_Entries?\$select=No";
if (($onlyFetchRows[0]['No'] ?? '') !== 'WO-1' || !is_array($onlyFetchCall) || $onlyFetchCall['url'] !== $expectedOnlyFetch || $onlyFetchCall['user'] !== 'only-auth-user') {
    fail('URL-fetch met alleen $auth ging niet naar BC: ' . json_encode($onlyFetchCall));
}

odata_mimir_circuit_reset();
$beforeOnlyDirectFetch = count($calls);
odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('Unmapped%20Co')/Customer_Ledger_Entries?\$select=No",
    24
);
$onlyDirectFetchCall = $calls[$beforeOnlyDirectFetch] ?? null;
if (!is_array($onlyDirectFetchCall) || $onlyDirectFetchCall['url'] !== $expectedOnlyFetch || $onlyDirectFetchCall['user'] !== 'only-auth-user') {
    fail('fetch_all met alleen $auth ging niet naar BC: ' . json_encode($onlyDirectFetchCall));
}

$primaryAuth = ['mode' => 'basic', 'user' => 'primary-user', 'pass' => 'primary-secret'];
$sandboxAuth = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$environment = 'Production';
$GLOBALS['environment'] = 'Production';
$environments = [];
$GLOBALS['environments'] = [];
$auth = $primaryAuth;
$GLOBALS['auth'] = $primaryAuth;
$auth_list = ['Sandbox' => $sandboxAuth];
$GLOBALS['auth_list'] = $auth_list;
$GLOBALS['companyEnvironmentMap'] = [];
$GLOBALS['odata_bc_company_environment_map'] = [];
odata_mimir_circuit_reset();
$beforeUnmapped = count($calls);
$unmappedRows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Unmapped%20Co')/Customer_Ledger_Entries?\$select=No",
    [],
    19
);
$unmappedCall = $calls[$beforeUnmapped] ?? null;
if (($unmappedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($unmappedCall) || $unmappedCall['url'] !== $expectedOnlyFetch || $unmappedCall['user'] !== 'primary-user') {
    fail('unmapped bedrijf in primaire environment moet $auth gebruiken, niet Sandbox: ' . json_encode($unmappedCall));
}
odata_mimir_circuit_reset();
$beforeUnmappedQuery = count($calls);
odata_mimir_query('Unmapped Co', 'AppCustomerCard', ['$select' => 'No'], 16);
$unmappedQueryCall = $calls[$beforeUnmappedQuery] ?? null;
if (!is_array($unmappedQueryCall) || strpos((string) $unmappedQueryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('Unmapped%20Co')/AppCustomerCard?") !== 0 || $unmappedQueryCall['user'] !== 'primary-user') {
    fail('query voor unmapped bedrijf moet $auth gebruiken, niet Sandbox: ' . json_encode($unmappedQueryCall));
}

$GLOBALS['environment'] = 'production';
$environment = 'production';
odata_mimir_circuit_reset();
$beforeCase = count($calls);
odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Unmapped%20Co')/Customer_Ledger_Entries?\$select=No",
    [],
    11
);
$caseCall = $calls[$beforeCase] ?? null;
if (!is_array($caseCall) || $caseCall['url'] !== $expectedOnlyFetch || $caseCall['user'] !== 'primary-user') {
    fail('primaire environment moet hoofdletterongevoelig $auth gebruiken: ' . json_encode($caseCall));
}

if (odata_mimir_max_age_seconds(14400, true) !== 0) {
    fail('forceRefresh moet max_age 0 naar Mímir sturen');
}
if (odata_mimir_max_age_seconds(14400, false) !== 14400) {
    fail('zonder forceRefresh blijft de opgegeven max_age staan');
}
if (odata_mimir_max_age_seconds(0, false) !== 3600) {
    fail('ttl 0 zonder forceRefresh moet de oude default 3600 houden');
}
if (odata_filter_to_odata_string(report_open_eq_filter(true)) !== 'Open eq true') {
    fail('open-filter moet Open eq true worden voor BC');
}
if (odata_filter_to_odata_string(report_open_eq_filter(false)) !== 'Open eq false') {
    fail('gesloten-filter moet Open eq false worden voor BC');
}
if (odata_filter_odata_literal(0.1) !== '0.1') {
    fail('0.1 moet een decimaal literal blijven, kreeg: ' . json_encode(odata_filter_odata_literal(0.1)));
}
if (odata_filter_odata_literal(1e-11) !== '0.00000000001') {
    fail('1e-11 mag niet naar 0 afronden, kreeg: ' . json_encode(odata_filter_odata_literal(1e-11)));
}
$below1e17 = odata_filter_odata_literal(1e-18);
if ($below1e17 !== '0.000000000000000001') {
    fail('waarde onder 1e-17 moet een decimaal literal blijven, kreeg: ' . json_encode($below1e17));
}
$tiny = odata_filter_odata_literal(9.999999999999999e-19);
if (!is_string($tiny) || $tiny === '0' || stripos($tiny, 'e') !== false) {
    fail('waarde onder 1e-17 mag niet 0 of wetenschappelijke notatie worden, kreeg: ' . json_encode($tiny));
}

$openBody = odata_mimir_query_body('KVT Gas', 'Customer_Ledger_Entries', [
    '$select' => 'Entry_No,Open',
    '$filter' => report_open_eq_filter(true),
], 0);
if (($openBody['max_age'] ?? null) !== 0) {
    fail('query-body moet max_age 0 bewaren, kreeg: ' . json_encode($openBody));
}
if (($openBody['filter'] ?? null) !== ['field' => 'Open', 'op' => 'eq', 'value' => true]) {
    fail('gestructureerd open-filter moet als JSON-leaf naar Mímir, kreeg: ' . json_encode($openBody['filter'] ?? null));
}
$closedFromString = odata_mimir_filter_for_request('Open eq false');
if ($closedFromString !== ['field' => 'Open', 'op' => 'eq', 'value' => false]) {
    fail('Open eq false uit de URL moet een JSON-leaf worden, kreeg: ' . json_encode($closedFromString));
}
$opaque = odata_mimir_filter_for_request("No eq '1'");
if ($opaque !== "No eq '1'") {
    fail('andere $filter-strings moeten opaque blijven, kreeg: ' . json_encode($opaque));
}
$bothParams = report_ledger_odata_params('both', 'debiteuren');
if (array_key_exists('$filter', $bothParams)) {
    fail('both mag geen Open-filter zetten');
}
$openParams = report_ledger_odata_params('open', 'crediteuren');
if (($openParams['$filter'] ?? null) !== report_open_eq_filter(true)) {
    fail('crediteuren open moet het gestructureerde Open-filter gebruiken');
}

$roundTripUrl = odata_company_url(
    'Production',
    'KVT Gas',
    'Customer_Ledger_Entries',
    report_ledger_odata_params('closed', 'debiteuren')
);
if (strpos($roundTripUrl, 'Open%20eq%20false') === false && strpos($roundTripUrl, 'Open+eq+false') === false) {
    fail('company-URL moet het gesloten filter als OData-string bevatten, kreeg: ' . $roundTripUrl);
}
$roundTrip = odata_mimir_parse_entity_url($roundTripUrl);
$roundTripFilter = is_array($roundTrip) ? odata_mimir_filter_for_request($roundTrip['query']['$filter'] ?? null) : null;
if ($roundTripFilter !== ['field' => 'Open', 'op' => 'eq', 'value' => false]) {
    fail('URL-roundtrip moet weer een JSON-leaf voor Mímir worden, kreeg: ' . json_encode($roundTripFilter));
}

odata_mimir_circuit_reset();
$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$GLOBALS['baseUrl'] = $baseUrl;
$environment = 'Production';
$GLOBALS['environment'] = 'Production';
$environments = ['Production'];
$GLOBALS['environments'] = ['Production'];
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$GLOBALS['auth'] = $auth;
$auth_list = ['Production' => $auth];
$GLOBALS['auth_list'] = $auth_list;
$GLOBALS['companyEnvironmentMap'] = ['KVT Gas' => 'Production'];
$GLOBALS['odata_bc_company_environment_map'] = ['KVT Gas' => 'Production'];
$beforeStructured = count($calls);
odata_mimir_query('KVT Gas', 'Customer_Ledger_Entries', [
    '$select' => 'Entry_No',
    '$filter' => report_open_eq_filter(true),
], 9);
$structuredCall = $calls[$beforeStructured] ?? null;
$structuredUrl = is_array($structuredCall) ? (string) $structuredCall['url'] : '';
if (strpos($structuredUrl, "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/Customer_Ledger_Entries?") !== 0
    || (strpos($structuredUrl, 'Open%20eq%20true') === false && strpos($structuredUrl, 'Open+eq+true') === false)) {
    fail('BC-fallback zette het gestructureerde Open-filter niet terug naar $filter: ' . json_encode($structuredCall));
}

$environment = 'Production';
$GLOBALS['environment'] = 'Production';
$environments = ['Production'];
$GLOBALS['environments'] = ['Production'];
$auth = $primaryAuth;
$GLOBALS['auth'] = $primaryAuth;
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'prod-list-user', 'pass' => 'prod-list-secret']];
$GLOBALS['auth_list'] = $auth_list;
$GLOBALS['companyEnvironmentMap'] = [];
$GLOBALS['odata_bc_company_environment_map'] = [];
odata_mimir_circuit_reset();
$beforeSandbox = count($calls);
$sandboxThrew = null;
try {
    odata_get_all(
        "https://mimir.invalid/Sandbox/ODataV4/Company('Some%20Co')/Customer_Ledger_Entries?\$select=No",
        $primaryAuth,
        10
    );
    fail('Sandbox-URL zonder eigen auth_list-entry moet de Mímir-fout teruggeven');
} catch (Throwable $exception) {
    $sandboxThrew = $exception;
}
if (!$sandboxThrew instanceof Throwable || strpos($sandboxThrew->getMessage(), 'Mímir') === false) {
    fail('Sandbox-weigering is niet de Mímir-fout: ' . ($sandboxThrew instanceof Throwable ? $sandboxThrew->getMessage() : 'geen exception'));
}
if (count($calls) !== $beforeSandbox) {
    fail('Sandbox-URL mag geen BC-call doen: ' . json_encode(array_slice($calls, $beforeSandbox)));
}

echo "OK\n";
