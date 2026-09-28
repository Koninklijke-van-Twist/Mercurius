# Mercurius

Debiteuren/crediteuren-rapportage op basis van Business Central OData.

## Mímir (optional)

Set in `web/auth.php` (not in git):

```php
$mimirApi  = 'mimir_…';
// optional:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

With `$mimirApi` set, OData fetches and company discovery try Mímir first (`max_age` from `MERCURIUS_NIGHTLY_MAX_AGE` = 14400 on nightly builds, `odata_cache_ttl_seconds()` = 82800 on UI/page loads). If that call fails (connection/timeout, non-2xx, invalid JSON, or a Mímir error payload), Mercurius fetches the same data on the legacy Business Central path (`$baseUrl`, `$auth` / `$auth_list`, `$environment` / `$environments`, local odata file cache) and skips Mímir for the rest of that PHP request. Keep those BC credentials in `auth.php` next to `$mimirApi`; if they are absent the original Mímir error is raised. This covers live page loads (`web/index.php`), CSV export (`web/export.php`), the nightly cache warm (`php web/nightly.php` and the HTTP scheduler), and report mail (`web/send_report_mail.php`, including the HTML it renders and CSV attachments). Without `$mimirApi` the existing direct-BC path remains unchanged. Mercurius has no `hourly.php`.

## Nightly cache warm

```sh
php web/nightly.php
```

## Tests

```sh
php tests/multi_environment_test.php
php tests/mimir_fallback_test.php
```
