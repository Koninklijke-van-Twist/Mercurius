<?php
/**
 * Actualiseren: ?actualiseren=1 forceert max_age=0 naar Mímir en slaat de lokale filecache over.
 * Run: php tests/actualiseren_test.php
 */

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:9';

require dirname(__DIR__) . '/web/odata.php';
require dirname(__DIR__) . '/web/functions.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

// Vlag uit de query: alleen exact "1", niet vanaf een andere site.
if (!odata_force_refresh_requested(['actualiseren' => '1'], [])) {
    fail('actualiseren=1 zonder Sec-Fetch-Site moet forceren');
}
if (!odata_force_refresh_requested(['actualiseren' => '1'], ['HTTP_SEC_FETCH_SITE' => 'same-origin'])) {
    fail('same-origin moet forceren');
}
if (!odata_force_refresh_requested(['actualiseren' => '1'], ['HTTP_SEC_FETCH_SITE' => 'none'])) {
    fail('Sec-Fetch-Site none (adresbalk) moet forceren');
}
if (odata_force_refresh_requested(['actualiseren' => '1'], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])) {
    fail('cross-site mag niet forceren');
}
if (odata_force_refresh_requested(['actualiseren' => '1'], ['HTTP_SEC_FETCH_SITE' => 'same-site'])) {
    fail('same-site (andere subdomein) mag niet forceren');
}
if (odata_force_refresh_requested(['actualiseren' => '0'], [])) {
    fail('actualiseren=0 mag niet forceren');
}
if (odata_force_refresh_requested(['actualiseren' => ['1']], [])) {
    fail('array-waarde mag niet forceren');
}
if (odata_force_refresh_requested([], [])) {
    fail('zonder parameter mag niet forceren');
}

// Normaal: de UI-TTL gaat als max_age mee.
odata_force_refresh_set(false);
$body = odata_mimir_query_body('KVT', 'CustomerList', ['$select' => 'No,Name'], 82800);
if (($body['max_age'] ?? null) !== 82800) {
    fail('zonder Actualiseren moet max_age de TTL zijn, kreeg ' . var_export($body['max_age'] ?? null, true));
}

// Actualiseren: elke query in dit verzoek max_age=0.
odata_force_refresh_set(true);
if (!odata_force_refresh_active()) {
    fail('vlag moet actief zijn');
}
$body = odata_mimir_query_body('KVT', 'CustomerList', ['$select' => 'No,Name'], 82800);
if (($body['max_age'] ?? null) !== 0) {
    fail('met Actualiseren moet max_age 0 zijn, kreeg ' . var_export($body['max_age'] ?? null, true));
}

// Directe BC-fallback: met Actualiseren geen verse cachefile lezen.
$auth = ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'];
// Opruimronde vooraf draaien, zodat die de testcachefile niet meeneemt.
maybe_cleanup_expired_cache_files();
odata_force_refresh_set(false);

// Zonder vlag geeft dezelfde URL de cache terug.
$cachePath2 = cache_path_for_key(build_cache_key('http://127.0.0.1:9/x', $auth));
write_cache_json($cachePath2, [['No' => 'CACHED']], 3600, 'http://127.0.0.1:9/x');
$rows = odata_get_all_direct('http://127.0.0.1:9/x', $auth, 3600);
if (($rows[0]['No'] ?? '') !== 'CACHED') {
    fail('zonder Actualiseren moet de filecache gebruikt worden');
}
odata_force_refresh_set(true);
$threw = false;
try {
    odata_get_all_direct('http://127.0.0.1:9/x', $auth, 3600);
} catch (Throwable $e) {
    $threw = true;
}
odata_force_refresh_set(false);
@unlink($cachePath2);
if (!$threw) {
    fail('met Actualiseren moet de filecache overgeslagen worden (live fetch verwacht)');
}

// Stats en timeout.
odata_request_stats_reset();
odata_force_refresh_set(false);
if (odata_mimir_timeout_seconds() !== odata_mimir_timeout_seconds_for_sapi(PHP_SAPI)) {
    fail('zonder Actualiseren normale Mímir-timeout');
}
odata_force_refresh_set(true);
if (odata_mimir_timeout_seconds() < 300) {
    fail('Actualiseren moet Mímir minstens 300 s geven');
}
odata_force_refresh_set(false);
odata_mimir_remember_meta('KVT', 'Customer_Ledger_Entries', ['from_live' => 5, 'from_cache' => 2]);
$stats = odata_request_stats();
if ($stats['mimir_live'] !== 5 || $stats['mimir_cache'] !== 2 || $stats['mimir_calls'] !== 1) {
    fail('Mímir-tellers kloppen niet: ' . json_encode($stats));
}

// Forced load die terugvalt op directe BC zet een versheidsvloer; volgende gewone load
// vraagt Mímir dan om data die minstens zo vers is.
$baseUrl = 'https://bc.example/';
$environment = 'Production';
$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'u', 'pass' => 'bc-secret']];
$GLOBALS['baseUrl'] = $baseUrl;
$GLOBALS['environment'] = $environment;
$GLOBALS['auth_list'] = $auth_list;
$GLOBALS['auth'] = $auth_list['Production'];
$GLOBALS['MERCURIUS_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl): array {
    return [['No' => 'LIVE1'], ['No' => 'LIVE2']];
};
@unlink(odata_mimir_freshness_floor_path());
odata_request_stats_reset();
odata_mimir_circuit_reset();
$entityUrl = "https://bc.example/Production/ODataV4/Company('KVT')/CustomerList";
odata_force_refresh_set(true);
$rows = @odata_get_all($entityUrl, $GLOBALS['auth'], 82800);
odata_force_refresh_set(false);
if (count($rows) !== 2) {
    fail('forced fallback moet de live BC-rijen geven');
}
$stats = odata_request_stats();
if ($stats['direct_live'] !== 2 || $stats['fallback_errors'] === []) {
    fail('fallback-tellers kloppen niet: ' . json_encode($stats));
}
$floor = odata_mimir_freshness_floor_get($entityUrl);
if ($floor <= 0 || $floor > time()) {
    fail('forced fallback moet een versheidsvloer zetten');
}
odata_mimir_freshness_floor_clear($entityUrl);
if (odata_mimir_freshness_floor_get($entityUrl) !== 0) {
    fail('vloer moet gewist kunnen worden');
}
@unlink(odata_mimir_freshness_floor_path());
unset($GLOBALS['MERCURIUS_ODATA_BC_FETCH']);

// Bedrijfskeuze.
$list = ['Hunter van Twist', 'KVT Gas', 'Koninklijke van Twist'];
if (mercurius_pick_company($list, '', '', '') !== 'Koninklijke van Twist') {
    fail('zonder keuze moet Koninklijke van Twist de standaard zijn');
}
if (mercurius_pick_company($list, 'KVT Gas', 'Hunter van Twist', '') !== 'KVT Gas') {
    fail('?company= wint');
}
if (mercurius_pick_company($list, 'Onbekend', 'Hunter van Twist', 'KVT Gas') !== 'Hunter van Twist') {
    fail('laatst gekozen bedrijf van de gebruiker wint van sessie');
}
if (mercurius_pick_company(['B', 'A'], '', '', '') !== 'B') {
    fail('zonder KVT het eerste bedrijf');
}
$prefsPath = mercurius_user_prefs_path();
$prefsBefore = is_file($prefsPath) ? file_get_contents($prefsPath) : null;
mercurius_user_pref_set_company('Test@KVT.nl', 'KVT Gas');
if (mercurius_user_pref_company('test@kvt.nl') !== 'KVT Gas') {
    fail('voorkeur per gebruiker moet bewaard worden');
}
if ($prefsBefore === null) {
    @unlink($prefsPath);
} else {
    file_put_contents($prefsPath, $prefsBefore);
}

echo "OK actualiseren\n";
