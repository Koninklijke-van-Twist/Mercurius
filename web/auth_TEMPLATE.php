<?php
/**
 * Auth template for Mercurius.
 *
 * Prefer Mímir (no BC credentials needed):
 *   $mimirApi  = 'mimir_…';  // required to activate Mímir
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optional
 *
 * With $mimirApi set, the BC vars below are unused.
 * Without $mimirApi, keep the BC block for the legacy OData path.
 *
 * Tim must set $mimirApi (and optional $mimirBase) in auth.php locally / on server.
 * Never commit web/auth.php.
 */

// --- Mímir (recommended) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Legacy Business Central (only when $mimirApi is not set) ---
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
