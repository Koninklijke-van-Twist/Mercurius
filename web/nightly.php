<?php
/**
 * Nightly OData cache warm-up.
 * Called via GET by a server scheduler. Loads customers + ledger entries for all
 * companies so page loads can serve from file cache (TTL = 23 hours).
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Long-running: BC can take a while per company (open/closed/both + customers).
@ini_set('max_execution_time', '7200');
@ini_set('max_input_time', '7200');
@ini_set('default_socket_timeout', '600');
@ini_set('memory_limit', '512M');
if (function_exists('set_time_limit')) {
    @set_time_limit(7200);
}
ignore_user_abort(true);

require_once __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/odata.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Accel-Buffering: no');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

function nightly_write(string $message, bool $isError = false): void
{
    if (PHP_SAPI === 'cli') {
        fwrite($isError ? STDERR : STDOUT, $message . "\n");
        return;
    }

    echo $message . "\n";
    if (function_exists('flush')) {
        @flush();
    }
}

function nightly_extend_time_limit(): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(7200);
    }
}

$startedAt = microtime(true);
$cacheTtl = odata_cache_ttl_seconds();
$ok = 0;
$failed = 0;

try {
    $companyDiscovery = auth_discover_companies(true);
    $companies = $companyDiscovery['companies'] ?? [];
    if (empty($companies)) {
        throw new RuntimeException('Geen bedrijven gevonden in de actieve environments.');
    }
} catch (Throwable $exception) {
    nightly_write('Bedrijven ophalen mislukt: ' . $exception->getMessage(), true);
    http_response_code(500);
    exit(1);
}

nightly_write('Nightly cache warm-up gestart (' . count($companies) . ' bedrijven, TTL ' . $cacheTtl . 's)');

$partyModes = ['debiteuren', 'crediteuren'];
$openFilters = ['open', 'closed', 'both'];

foreach ($companies as $company) {
    $company = (string) $company;
    nightly_extend_time_limit();

    try {
        $environment = getEnvironmentForCompany($company);
        $auth = auth_get_for_environment($environment);

        foreach ($partyModes as $partyMode) {
            $partyUrl = odata_company_url(
                $environment,
                $company,
                report_party_card_entity($partyMode),
                report_party_odata_params($partyMode)
            );
            $parties = odata_get_all($partyUrl, $auth, $cacheTtl, true);
            nightly_write("  {$company} [{$partyMode}]: parties=" . count($parties));
            nightly_extend_time_limit();

            foreach ($openFilters as $openFilter) {
                $entriesUrl = odata_company_url(
                    $environment,
                    $company,
                    report_ledger_entity($partyMode),
                    report_ledger_odata_params($openFilter, $partyMode)
                );
                $entries = odata_get_all($entriesUrl, $auth, $cacheTtl, true);
                nightly_write("  {$company} [{$partyMode}]: ledger[{$openFilter}]=" . count($entries));
                nightly_extend_time_limit();
            }
        }

        $ok++;
    } catch (Throwable $exception) {
        $failed++;
        nightly_write("  FAILED {$company}: " . $exception->getMessage(), true);
    }
}

$elapsed = round(microtime(true) - $startedAt, 1);
nightly_write("Klaar: ok={$ok} failed={$failed} in {$elapsed}s");

if ($failed > 0 && $ok === 0) {
    http_response_code(500);
    exit(1);
}

exit(0);
