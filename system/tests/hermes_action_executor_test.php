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
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'service_registry', 'ajax', 'router', 'security_runtime_bridge' ] );
metis_standalone_boot();
metis_register_core_services();
\Metis\Modules\Help\HelpModule::boot();
\Metis\Modules\Hermes\HermesModule::boot();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$executor = \Metis\Core\Application::service( 'hermes_action_executor' );
$repository = \Metis\Core\Application::service( 'hermes_repository' );
$session = $repository->ensureSession( 0, 'TESTEXEC001', 'Action Executor Contract' );
$action = $repository->createAction(
    (int) ( $session['id'] ?? 0 ),
    0,
    'open_help_topic',
    'Open Help Topic',
    [ 'topic_id' => 'finance.gl_entry' ],
    [ 'title' => 'Open Help Topic', 'summary' => 'Approval required.', 'requires_approval' => true ]
);
$approved = $repository->approveAction( (string) ( $action['action_code'] ?? '' ), 1, 'approve for runtime test' );
$executed = $executor->executeApprovedAction( (array) $approved, (string) ( $approved['action_code'] ?? '' ) );

$assert( is_array( $executed['action'] ?? null ), 'Action executor should return the saved action payload.' );
$assert( is_array( $executed['result'] ?? null ), 'Action executor should return an execution result payload.' );
$assert( (string) ( $executed['action']['approval_status'] ?? '' ) === 'executed', 'Action executor should persist executed approval state.' );
$assert( isset( $executed['result']['status'] ), 'Action executor should normalize a result status even when enclave authentication blocks execution.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes action executor checks passed.\n" );
