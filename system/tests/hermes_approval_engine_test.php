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

$engine = \Metis\Core\Application::service( 'hermes_approval_engine' );
$repository = \Metis\Core\Application::service( 'hermes_repository' );

$session = $repository->ensureSession( 0, 'TESTAPPROVAL001', 'Approval Engine Contract' );
$processed = [
    'command' => [ 'key' => 'create_user' ],
    'intent' => [ 'payload' => [ 'user_request' => [ 'email' => 'riley@example.com' ] ] ],
    'parsed' => [ 'execution_plan' => [] ],
    'action_plan' => [ 'operation' => 'create_user', 'title' => 'Create User', 'required_permission' => 'people.create', 'steps' => [ 'create_user' ] ],
    'context_packs' => [],
];
$response = [ 'status' => 'awaiting_approval', 'message' => 'Approval required.' ];

$actions = $engine->queueApprovalForProcessedResponse( $session, 'create a new user', $processed, $response );
$assert( count( $actions ) === 1, 'Approval engine should queue an action for awaiting-approval responses.' );
$assert( (string) ( $actions[0]['approval_status'] ?? '' ) === 'pending', 'Queued approval actions should start pending.' );

$withPrompt = $engine->attachApprovalPrompts( [ 'ui_components' => [] ], $actions );
$assert( (string) ( $withPrompt['ui_components'][0]['type'] ?? '' ) === 'ApprovalPrompt', 'Approval engine should attach approval prompts to the response payload.' );

$manual = $repository->createAction(
    (int) ( $session['id'] ?? 0 ),
    0,
    'open_help_topic',
    'Open Help Topic',
    [ 'topic_id' => 'finance.gl_entry' ],
    [ 'title' => 'Open Help Topic', 'summary' => 'Approval required.', 'requires_approval' => true ]
);
$approved = $engine->approve( (string) ( $manual['action_code'] ?? '' ), 7, 'Approved in test' );
$assert( (string) ( $approved['approval_status'] ?? '' ) === 'approved', 'Approval engine should transition repository actions to approved state.' );
$assert( (int) ( $approved['approved_by'] ?? 0 ) === 7, 'Approval engine should persist the approving actor.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes approval engine checks passed.\n" );
