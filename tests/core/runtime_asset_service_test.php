<?php
declare(strict_types=1);

define('METIS_ROOT', dirname(__DIR__, 2) . '/');
define('METIS_STANDALONE', true);
define('METIS_PATH', dirname(__DIR__, 2) . '/');
define('METIS_VERSION', 'test-version');
define('METIS_URL', 'https://example.test/metis/');

$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['HTTPS'] = 'on';

$GLOBALS['metis_runtime_config'] = [
    'app_key' => 'test-app-key',
    'base_path' => '/metis',
    'csrf_ttl' => 300,
];

if ( ! class_exists( 'Metis_Cron_Manager' ) ) {
    final class Metis_Cron_Manager {
        public static function register_task( string $slug, callable $callback, array $config = [] ): void {}
        public static function matches_request( Metis_Http_Request $request ): bool { return false; }
    }
}

require_once dirname(__DIR__, 2) . '/src/Metis/Core/CoreBootstrap.php';
metis_core_bootstrap( [ 'standalone_runtime', 'http', 'service_registry', 'security_enclave', 'security_runtime_bridge', 'auth', 'ajax', 'router' ] );

require_once dirname(__DIR__, 2) . '/src/Metis/Core/AssetsRuntime.php';
require_once dirname(__DIR__, 2) . '/src/Metis/Core/Modules/ModulesRuntime.php';

function assert_true( bool $condition, string $message = 'Assertion failed' ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function assert_contains( string $needle, string $haystack, string $message = 'Expected substring not found' ): void {
    if ( ! str_contains( $haystack, $needle ) ) {
        throw new RuntimeException( $message . ' Missing: ' . $needle );
    }
}

$service = \Metis\Core\Application::service( 'runtime_assets' );
assert_true( $service instanceof \Metis\Core\Services\RuntimeAssetService, 'Runtime asset service should be registered.' );

$bootstrap = $service->renderJavascript( 'settings', 'general' );
assert_contains( 'window.metisAjax = ', $bootstrap, 'Runtime bootstrap should include the core AJAX payload.' );
assert_contains( 'window.metisHelp = ', $bootstrap, 'Runtime bootstrap should include the help payload.' );

$css = $service->renderStylesheet( 'settings', 'general' );
assert_true( is_string( $css ), 'Theme stylesheet should render as a string.' );

$request = new Metis_Http_Request(
    'GET',
    '/assets/runtime/bootstrap.js?domain=settings&view=general',
    '/assets/runtime/bootstrap.js',
    [
        'domain' => 'settings',
        'view' => 'general',
    ],
    [],
    []
);

$response = metis_router_handle_runtime_asset_request(
    $request->with_attribute( 'runtime_asset_path', 'bootstrap.js' )
);

assert_true( $response->status() === 200, 'Runtime bootstrap asset route should return HTTP 200.' );
assert_contains( 'application/javascript', (string) ( $response->headers()['Content-Type'] ?? '' ), 'Runtime bootstrap asset should return JavaScript.' );
assert_contains( 'window.metisAjax = ', $response->body(), 'Runtime bootstrap asset should emit localized payloads.' );

echo "Runtime asset service tests passed.\n";
