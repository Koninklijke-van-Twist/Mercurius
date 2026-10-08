# Mercurius

Debiteuren/crediteuren-rapportage op basis van Business Central OData.

## Mímir (optional)

Set in `web/auth.php` (not in git):

```php
$mimirApi  = 'mimir_…';
// optional:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

With `$mimirApi` set, OData fetches and company discovery try Mímir first. UI and page loads send `max_age` from `odata_cache_ttl_seconds()` (82800). Nightly warm-up calls `odata_get_all(..., true)`: that `forceRefresh` sends `max_age` 0 so Mímir rebuilds coverage from Business Central (Mímir has no separate force flag; 0 is the OpenAPI minimum). The direct BC file-cache fallback still uses `MERCURIUS_NIGHTLY_MAX_AGE` (14400). Simple open/closed ledger filters are sent as structured JSON (`{"field":"Open","op":"eq","value":true|false}`) instead of an opaque `$filter` string; the BC fallback turns that leaf back into `Open eq true` / `Open eq false`. Unfiltered ledger queries (`both`) stay unfiltered. If a Mímir call fails (connection/timeout, non-2xx, invalid JSON, or a Mímir error payload), Mercurius fetches the same data on the legacy Business Central path (`$baseUrl`, `$auth` / `$auth_list`, `$environment` / `$environments`, local odata file cache) and skips Mímir for the rest of that PHP request. Keep those BC credentials in `auth.php` next to `$mimirApi`; if they are absent the original Mímir error is raised. This covers live page loads (`web/index.php`), CSV export (`web/export.php`), the nightly cache warm (`php web/nightly.php` and the HTTP scheduler), and report mail (`web/send_report_mail.php`, including the HTML it renders and CSV attachments). Without `$mimirApi` the existing direct-BC path remains unchanged. Mercurius has no `hourly.php`.

## Actualiseren

Rechtsboven in het overzicht (`web/index.php`) staat de knop **Actualiseren** (vervangt de oude cachewidget). Na bevestigen in de modal laadt de pagina opnieuw met de eenmalige parameter `?actualiseren=1`. In die paginaload stuurt elke Mímir `query.php`-aanroep `max_age` 0 (Mímir haalt live bij Business Central op en slaat het resultaat weer op), en de directe BC-fallback leest de lokale odata-filecache niet (maar schrijft het verse antwoord wel weg). Daarna haalt JavaScript de parameter uit de URL (`history.replaceState`), zodat F5 weer de normale TTL gebruikt. Een navigatie vanaf een andere site (`Sec-Fetch-Site: cross-site`/`same-site`) forceert niets. De bedrijvenlijst (Mímir `companies.php`) heeft geen force-optie en komt uit Mímirs nightly-cache.

## Nightly cache warm

```sh
php web/nightly.php
```

## Tests

```sh
php tests/multi_environment_test.php
php tests/mimir_fallback_test.php
php tests/actualiseren_test.php
```
