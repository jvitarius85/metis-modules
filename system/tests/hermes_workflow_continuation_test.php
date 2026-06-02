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

$gateway = \Metis\Core\Application::service( 'hermes_gateway' );
$repository = \Metis\Core\Application::service( 'hermes_repository' );
$db = \Metis\Core\Application::service( 'db' );
$continueSessionCode = 'TESTFLOW' . strtoupper( substr( md5( uniqid( 'continue', true ) ), 0, 8 ) );
$expiredSessionCode = 'TESTFLOW' . strtoupper( substr( md5( uniqid( 'expired', true ) ), 0, 8 ) );

$session = $repository->ensureSession( 0, $continueSessionCode, 'Workflow Continuation Contract' );
$pending = $repository->createAction(
    (int) ( $session['id'] ?? 0 ),
    0,
    'open_help_topic',
    'Open Help Topic',
    [ 'topic_id' => 'finance.gl_entry' ],
    [ 'title' => 'Open Help Topic', 'summary' => 'Approval required.', 'requires_approval' => true ]
);

$continued = $gateway->converse( 'yes', $continueSessionCode );
$continuedAction = (array) ( $continued['action'] ?? [] );
$assert( (string) ( $continued['response_type'] ?? '' ) === 'WorkflowContinuationResult', 'Yes replies should continue the pending workflow.' );
$assert( (string) ( $continuedAction['action_code'] ?? '' ) === (string) ( $pending['action_code'] ?? '' ), 'Workflow continuation should attach to the latest pending session action.' );
$assert( (string) ( $continuedAction['approval_status'] ?? '' ) === 'executed', 'Approved conversational continuations should execute the pending action.' );

$expiredSession = $repository->ensureSession( 0, $expiredSessionCode, 'Workflow Expiration Contract' );
$expired = $repository->createAction(
    (int) ( $expiredSession['id'] ?? 0 ),
    0,
    'open_help_topic',
    'Open Help Topic',
    [ 'topic_id' => 'finance.gl_entry' ],
    [ 'title' => 'Open Help Topic', 'summary' => 'Approval required.', 'requires_approval' => true ]
);

$db->update(
    \Metis_Tables::get( 'hermes_actions' ),
    [ 'created_at' => date( 'Y-m-d H:i:s', time() - 1200 ) ],
    [ 'action_code' => (string) ( $expired['action_code'] ?? '' ) ],
    [ '%s' ],
    [ '%s' ]
);

$expiredResponse = $gateway->converse( 'yes', $expiredSessionCode );
$expiredAction = (array) ( $expiredResponse['action'] ?? [] );
$assert( (string) ( $expiredResponse['status'] ?? '' ) === 'workflow_expired', 'Expired pending workflows should not be executed.' );
$assert( (string) ( $expiredResponse['response_type'] ?? '' ) === 'WorkflowExpiredPrompt', 'Expired pending workflows should return an expiration prompt.' );
$assert( (string) ( $expiredAction['approval_status'] ?? '' ) === 'expired', 'Expired pending workflows should be transitioned out of pending state.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes workflow continuation checks passed.\n" );
