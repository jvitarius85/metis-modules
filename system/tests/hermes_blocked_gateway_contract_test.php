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

$gateway = \Metis\Core\Application::service( 'hermes_gateway' );

foreach ( metis_hermes_blocked_operations_fixture() as $operationKey => $fixture ) {
    $sessionCode = 'TESTBLOCK' . strtoupper( substr( md5( uniqid( 'blk', true ) ), 0, 8 ) );
    $start = $gateway->converse( (string) $fixture['query'], $sessionCode );

    $assert( (string) ( $start['status'] ?? '' ) === 'error', sprintf( '[%s] should fail directly as an unsupported command.', (string) $fixture['query'] ) );
    $assert( (string) ( $start['response_type'] ?? '' ) === 'ExecutionResult', sprintf( '[%s] should return an execution-style unsupported result.', (string) $fixture['query'] ) );
    $assert( count( (array) ( $start['actions'] ?? [] ) ) === 0, sprintf( '[%s] should not create approval actions.', (string) $fixture['query'] ) );
    $assert( (string) ( $start['reasoning']['structured']['result']['error_code'] ?? '' ) === 'TOOL_NOT_FOUND', sprintf( '[%s] should preserve TOOL_NOT_FOUND in the structured result.', (string) $fixture['query'] ) );
    $assert( (string) ( $start['reasoning']['structured']['result']['operation'] ?? '' ) === $operationKey, sprintf( '[%s] should preserve the canonical blocked operation key.', (string) $fixture['query'] ) );
    $assert( str_contains( strtolower( (string) ( $start['message'] ?? '' ) ), strtolower( (string) $fixture['message_contains'] ) ), sprintf( '[%s] should return the expected operator guidance.', (string) $fixture['query'] ) );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes blocked gateway contract checks passed.\n" );
