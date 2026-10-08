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

echo "OK actualiseren\n";
