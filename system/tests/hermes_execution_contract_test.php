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
require_once $root . '/src/Metis/Core/Security/EnclaveToolRuntime.php';
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

$engine = \Metis\Core\Application::service( 'hermes_operational_engine' );
$result = $engine->process( 'list users' );

$assert(
    metis_core_enclave_operation_for_tool( [
        'tool_key' => 'hermes.directory.lookup_profile',
        'enclave_action' => 'hermes.tool.execute',
        'requires_approval' => false,
    ] ) === 'hermes.tool.query',
    'Read-only Hermes tools should route through the query enclave operation.'
);
$assert(
    metis_core_enclave_operation_for_tool( [
        'tool_key' => 'hermes.system.clear_cache',
        'enclave_action' => 'hermes.tool.execute',
        'requires_approval' => true,
    ] ) === 'hermes.tool.execute',
    'Approval-gated Hermes tools should continue routing through the execute enclave operation.'
);

$assert( (string) ( $result['intent']['action'] ?? '' ) === 'list_users', 'Execution contract should resolve list_users intent.' );
$assert( (string) ( $result['command']['tool_key'] ?? '' ) === 'hermes.user.list_users', 'Execution contract should map list_users to the expected tool.' );
$assert( (string) ( $result['response']['result']['error_code'] ?? '' ) === 'PERMISSION_DENIED', 'Unauthenticated standalone execution should be denied by enclave with standardized permission code.' );

$clarify = $engine->process( 'do it again' );
$assert( (string) ( $clarify['response']['status'] ?? '' ) === 'clarification_required', 'Context-only shorthand should require clarification.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes execution contract checks passed.\n" );
