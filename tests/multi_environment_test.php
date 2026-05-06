<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/authhelper.php';
require_once __DIR__ . '/../web/odata.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' | expected: ' . var_export($expected, true) . ', actual: ' . var_export($actual, true));
    }
}

$tests = [];

$tests['normalizes environment list'] = function (): void {
    $GLOBALS['auth_list'] = [
        'kvtmdlive_aad' => ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'],
        'kvtgermanylive_aad' => ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'],
    ];

    $normalized = auth_normalize_environment_list(['kvtmdlive_aad', 'kvtgermanylive_aad', 'kvtmdlive_aad']);
    assert_same(['kvtmdlive_aad', 'kvtgermanylive_aad'], $normalized, 'Environment list normalization failed');
};

$tests['builds company-environment mapping across environments'] = function (): void {
    $GLOBALS['auth_list'] = [
        'kvtmdlive_aad' => ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'],
        'kvtgermanylive_aad' => ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'],
    ];
    $GLOBALS['environments'] = ['kvtmdlive_aad', 'kvtgermanylive_aad'];

    auth_set_company_fetcher_for_tests(function (string $environment): array {
        if ($environment === 'kvtmdlive_aad') {
            return ['Koninklijke van Twist', 'KVT Gas'];
        }

        if ($environment === 'kvtgermanylive_aad') {
            return ['Hunter van Twist'];
        }

        return [];
    });

    $result = auth_discover_companies(true);
    $map = $result['companyEnvironmentMap'];

    assert_same('kvtmdlive_aad', $map['Koninklijke van Twist'] ?? null, 'Company mapping mismatch for KVT');
    assert_same('kvtmdlive_aad', $map['KVT Gas'] ?? null, 'Company mapping mismatch for Gas');
    assert_same('kvtgermanylive_aad', $map['Hunter van Twist'] ?? null, 'Company mapping mismatch for Hunter');

    auth_set_company_fetcher_for_tests(null);
};

$tests['detects duplicate company across environments'] = function (): void {
    $GLOBALS['auth_list'] = [
        'kvtmdlive_aad' => ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'],
        'kvtgermanylive_aad' => ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'],
    ];
    $GLOBALS['environments'] = ['kvtmdlive_aad', 'kvtgermanylive_aad'];

    auth_set_company_fetcher_for_tests(function (string $environment): array {
        if ($environment === 'kvtmdlive_aad') {
            return ['Overlap Co'];
        }

        if ($environment === 'kvtgermanylive_aad') {
            return ['Overlap Co'];
        }

        return [];
    });

    $thrown = false;
    try {
        auth_discover_companies(true);
    } catch (RuntimeException $exception) {
        $thrown = true;
        assert_true(stripos($exception->getMessage(), 'overlap') !== false, 'Expected overlap wording in exception');
    }

    auth_set_company_fetcher_for_tests(null);

    assert_true($thrown, 'Expected overlap detection to throw RuntimeException');
};

$tests['builds stable cache key with environment array'] = function (): void {
    $GLOBALS['auth_list'] = [
        'kvtmdlive_aad' => ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'],
        'kvtgermanylive_aad' => ['mode' => 'basic', 'user' => 'u', 'pass' => 'p'],
    ];
    $GLOBALS['environments'] = ['kvtmdlive_aad', 'kvtgermanylive_aad'];

    $cacheKey = build_cache_key('https://example.test/data', ['user' => 'powerbiserv']);

    assert_true(strpos($cacheKey, 'kvtmdlive_aad|kvtgermanylive_aad') !== false, 'Cache key missing stable environment signature');
    assert_true(strpos($cacheKey, 'Array') === false, 'Cache key contains unexpected array conversion text');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo '[PASS] ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[FAIL] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
}

if ($failed > 0) {
    exit(1);
}

echo 'All tests passed.' . PHP_EOL;
