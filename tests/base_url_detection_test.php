<?php
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
define('METIS_STANDALONE', true);
define('METIS_PATH', dirname(__DIR__) . '/');

$_SERVER['HTTP_HOST'] = 'internal.metis.local';
$_SERVER['HTTPS'] = 'off';

$GLOBALS['metis_runtime_config'] = [
    'app_key' => 'test-app-key',
    'base_path' => '/metis',
];

require_once dirname(__DIR__) . '/includes/core/standalone_runtime.php';

function assert_same(mixed $expected, mixed $actual, string $message = 'Values are not equal'): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

$tests = [];

$tests['forwarded_proto_and_host_are_preferred_over_raw_https'] = function (): void {
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['HTTP_HOST'] = 'internal.metis.local';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    $_SERVER['HTTP_X_FORWARDED_HOST'] = 'portal.example.com';
    unset($_SERVER['HTTP_FORWARDED']);

    assert_same('https://portal.example.com/metis', home_url(), 'Forwarded headers should drive the external base URL.');
    assert_same('https://portal.example.com/metis/login', home_url('/login'), 'Forwarded headers should be used for generated links.');
};

$tests['configured_base_url_overrides_proxy_and_server_headers'] = function (): void {
    $GLOBALS['metis_runtime_config']['base_url'] = 'https://configured.example.com/custom-root/';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
    $_SERVER['HTTP_X_FORWARDED_HOST'] = 'proxy.example.net';
    $_SERVER['HTTP_HOST'] = 'internal.metis.local';
    $_SERVER['HTTPS'] = 'off';

    assert_same('https://configured.example.com/custom-root', home_url(), 'Configured base URL should take precedence.');
    assert_same('https://configured.example.com/custom-root/api/callback', site_url('/api/callback'), 'Configured base URL should drive callback URLs.');

    $GLOBALS['metis_runtime_config']['base_url'] = '';
};

foreach ($tests as $name => $test) {
    $test();
}

echo "Base URL detection tests passed.\n";
