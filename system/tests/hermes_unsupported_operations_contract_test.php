<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
require_once $root . '/tests/_support/hermes_blocked_operations_fixture.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'service_registry', 'ajax', 'router', 'security_runtime_bridge' ] );
metis_standalone_boot();
metis_register_core_services();
\Metis\Modules\Hermes\HermesModule::boot();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$capabilities = \Metis\Core\Application::service( 'hermes_capabilities' );
foreach ( metis_hermes_blocked_operations_fixture() as $operationKey => $fixture ) {
    $method = (string) $fixture['capability_method'];
    $result = $capabilities->{$method}( [] );
    $message = (string) ( $result['message'] ?? '' );

    $assert( (string) ( $result['status'] ?? '' ) === 'error', sprintf( '%s should return error status.', $method ) );
    $assert( (string) ( $result['error_code'] ?? '' ) === 'TOOL_NOT_FOUND', sprintf( '%s should return TOOL_NOT_FOUND.', $method ) );
    $assert( (string) ( $result['operation'] ?? '' ) === $operationKey, sprintf( '%s should report the canonical operation key.', $method ) );
    $assert( $message !== '', sprintf( '%s should return a non-empty operator-facing message.', $method ) );
    $assert( str_contains( strtolower( $message ), strtolower( (string) $fixture['message_contains'] ) ), sprintf( '%s should return the expected guidance text fragment.', $method ) );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes unsupported operations contract checks passed.\n" );
