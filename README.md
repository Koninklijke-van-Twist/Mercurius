# Mercurius

Debiteuren/crediteuren-rapportage op basis van Business Central OData.

## Mímir (optional)

Set in `web/auth.php` (not in git):

```php
$mimirApi  = 'mimir_…';
// optional:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

With `$mimirApi` set, `$auth_list`, `$environments`, `$baseUrl` and per-environment BC auth are unused for Business Central — OData fetches (nightly cache warm and UI/on-demand) and company discovery go through Mímir (`max_age` from `MERCURIUS_NIGHTLY_MAX_AGE` = 14400 on nightly builds, `odata_cache_ttl_seconds()` = 82800 on UI/page loads). Without `$mimirApi` the existing direct-BC path remains unchanged. Mercurius has no `hourly.php`.

## Nightly cache warm

```sh
php web/nightly.php
```

## Tests

```sh
php tests/multi_environment_test.php
```
