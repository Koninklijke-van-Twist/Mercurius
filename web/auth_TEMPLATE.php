<?php
/**
 * Auth template for Mercurius.
 *
 * Prefer Mímir, and keep the BC block as automatic fallback when Mímir is down:
 *   $mimirApi  = 'mimir_…';  // required to activate Mímir
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optional
 *
 * With $mimirApi set, fetches try Mímir first and fall back to the BC vars below.
 * Those BC credentials ($auth_list, $environments, $baseUrl, and optional $auth / $environment)
 * must stay in auth.php next to $mimirApi. Without them a Mímir failure is rethrown.
 * Without $mimirApi, only the BC block is used.
 *
 * Tim must set $mimirApi (and optional $mimirBase) in auth.php locally / on server.
 * Never commit web/auth.php.
 */

// --- Mímir (recommended) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (direct path, and fallback when Mímir fails) ---
$auth_list =
    [
        "env1" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env2" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
        "env3" => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD']
    ];
$environments = [
    'env1',
    'env2',
];
$baseUrl = "https://my-bc-domain.com:7148/";

$allowedUsers = [
    "user@domain.nl"
];

require_once __DIR__ . '/authhelper.php';
