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
\Metis\Modules\Hermes\HermesModule::boot();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$gateway = \Metis\Core\Application::service( 'hermes_gateway' );
$memory = \Metis\Core\Application::service( 'hermes_memory_store' );
$db = \Metis\Core\Application::service( 'db' );

$sessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wf', true ) ), 0, 8 ) );

$start = $gateway->converse( 'Create a new user.', $sessionCode );
$assert( (string) ( $start['status'] ?? '' ) === 'workflow_question', 'Incomplete create user requests should start a pending workflow.' );
$assert( (string) ( $start['message'] ?? '' ) === 'What is the user\'s name?', 'User workflow should ask for the name first.' );

$name = $gateway->converse( 'John Smith', $sessionCode );
$assert( (string) ( $name['status'] ?? '' ) === 'workflow_question', 'Name-only reply should continue the pending workflow.' );
$assert( (string) ( $name['message'] ?? '' ) === 'What is the user\'s email?', 'Workflow should ask for email after name.' );

$email = $gateway->converse( 'john@example.org', $sessionCode );
$assert( (string) ( $email['status'] ?? '' ) === 'workflow_question', 'Email reply should continue the pending workflow.' );
$assert( str_contains( (string) ( $email['message'] ?? '' ), 'What role should be assigned?' ), 'Workflow should ask for role after required fields are captured.' );

$review = $gateway->converse( 'Board Administrator', $sessionCode );
$actions = (array) ( $review['actions'] ?? [] );
$assert( (string) ( $review['status'] ?? '' ) === 'awaiting_approval', 'Completed workflow should re-enter the normal approval path.' );
$assert( (string) ( $review['response_type'] ?? '' ) === 'WorkflowReview', 'Completed workflow should return a workflow review response.' );
$assert( str_contains( (string) ( $review['message'] ?? '' ), 'Review:' ), 'Workflow review should summarize the collected fields.' );
$assert( count( $actions ) === 1, 'Completed workflow should queue a single approval action.' );

$continued = $gateway->converse( 'yes', $sessionCode );
$continuedAction = (array) ( $continued['action'] ?? [] );
$assert( (string) ( $continued['response_type'] ?? '' ) === 'WorkflowContinuationResult', 'Yes should continue from workflow review into action execution.' );
$assert( (string) ( $continuedAction['approval_status'] ?? '' ) === 'executed', 'Workflow approval continuation should execute the queued action.' );

$expiredSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfx', true ) ), 0, 8 ) );
$expiredStart = $gateway->converse( 'Create a new user.', $expiredSessionCode );
$assert( (string) ( $expiredStart['status'] ?? '' ) === 'workflow_question', 'Expired workflow fixture should begin as a normal workflow.' );

$db->update(
    \Metis_Tables::get( 'hermes_memory' ),
    [ 'updated_at' => date( 'Y-m-d H:i:s', time() - 1200 ) ],
    [ 'memory_key' => 'workflow:' . $expiredSessionCode ],
    [ '%s' ],
    [ '%s' ]
);

$expired = $gateway->converse( 'John Smith', $expiredSessionCode );
$assert( (string) ( $expired['status'] ?? '' ) === 'workflow_expired', 'Stale pending workflows should expire instead of continuing.' );
$assert( (string) ( $expired['response_type'] ?? '' ) === 'WorkflowExpiredPrompt', 'Stale pending workflows should return an expiration prompt.' );
$assert( $memory->recallPendingWorkflow( $expiredSessionCode ) === [], 'Expired workflows should be cleared from memory.' );

$workspaceSessionCode = 'TESTWF' . strtoupper( substr( md5( uniqid( 'wfw', true ) ), 0, 8 ) );
$workspaceStart = $gateway->converse( 'Create a workspace user.', $workspaceSessionCode );
$assert( (string) ( $workspaceStart['status'] ?? '' ) === 'workflow_question', 'Workspace user requests should start the same pending workflow path.' );

$gateway->converse( 'Casey Workspace', $workspaceSessionCode );
$gateway->converse( 'casey.workspace@example.org', $workspaceSessionCode );
$workspaceReview = $gateway->converse( 'no role', $workspaceSessionCode );

$workspaceActions = (array) ( $workspaceReview['actions'] ?? [] );
$workspaceAction = (array) ( $workspaceActions[0] ?? [] );
$workspacePayload = (array) ( $workspaceAction['payload'] ?? [] );
$workspaceRequest = (array) ( $workspacePayload['command_payload']['user_request'] ?? [] );

$assert( (string) ( $workspaceReview['status'] ?? '' ) === 'awaiting_approval', 'Workspace user workflow should end in the normal approval state.' );
$assert( str_contains( (string) ( $workspaceReview['message'] ?? '' ), 'Create workspace user?' ), 'Workspace user workflow review should reflect the workspace operation.' );
$assert( (bool) ( $workspaceRequest['workspace_enabled'] ?? false ) === true, 'Workspace user workflow should force workspace-enabled user creation.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes pending workflow checks passed.\n" );
