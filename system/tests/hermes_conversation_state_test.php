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

$state = \Metis\Core\Application::service( 'hermes_conversation_state' );
$repository = \Metis\Core\Application::service( 'hermes_repository' );
$memory = \Metis\Core\Application::service( 'hermes_memory_store' );
$manager = \Metis\Core\Application::service( 'hermes_conversation_state_manager' );
$resolver = \Metis\Core\Application::service( 'hermes_conversation_resolver' );
$store = \Metis\Core\Application::service( 'hermes_conversation_store' );

$turn = $state->openTurn( 0, 'List users in the workspace', 'TESTSTATE001' );
$session = (array) ( $turn['session'] ?? [] );
$userMessage = (array) ( $turn['user_message'] ?? [] );
$assert( (string) ( $session['session_code'] ?? '' ) === 'TESTSTATE001', 'Conversation state should open the requested session code.' );
$assert( (string) ( $userMessage['content'] ?? '' ) === 'List users in the workspace', 'Conversation state should persist the opening user message.' );

$context = $state->hydrateRuntimeContext( $session, [ 'current_module' => 'people' ] );
$assert( (string) ( $context['session_code'] ?? '' ) === 'TESTSTATE001', 'Hydrated runtime context should include the session code.' );
$assert( (string) ( $context['current_module'] ?? '' ) === 'people', 'Hydrated runtime context should preserve caller context.' );

$processed = [
    'intent' => [
        'action' => 'list_users',
        'top_level_intent' => 'LOOKUP',
        'payload' => [ 'query' => 'list users in the workspace' ],
    ],
    'parsed' => [
        'selected_intent' => 'list_users',
        'top_level_intent' => 'LOOKUP',
    ],
];
$response = [
    'status' => 'success',
    'response_type' => 'ExecutionResult',
    'message' => 'Found 3 users.',
];

$state->completeTurn( $session, 'List users in the workspace', $processed, $response );

$updatedSession = (array) ( $repository->findSessionByCode( 'TESTSTATE001' ) ?? [] );
$summary = $memory->recallConversation( 'TESTSTATE001' );

$assert( (string) ( $updatedSession['last_intent'] ?? '' ) === 'list_users', 'Conversation state completion should update the session last intent.' );
$assert( (string) ( $summary['top_level_intent'] ?? '' ) === 'LOOKUP', 'Conversation summaries should retain the top-level intent.' );
$assert( (string) ( $summary['response_type'] ?? '' ) === 'ExecutionResult', 'Conversation summaries should retain the response type.' );
$assert( (string) ( $summary['status'] ?? '' ) === 'success', 'Conversation summaries should retain the response status.' );

$memory->rememberPendingWorkflow( 'TESTSTATE001', [ 'type' => 'create_user', 'step' => 'email', 'request' => [ 'display_name' => 'Jordan Lee' ] ] );
$memory->rememberPendingDisambiguation( 'TESTSTATE001', [ 'kind' => 'entity_attribute', 'candidates' => [ [ 'name' => 'Jordan Lee', 'email' => 'jordan@example.org' ] ] ] );
$action = $repository->createAction(
    (int) ( $session['id'] ?? 0 ),
    0,
    'open_help_topic',
    'Open Help Topic',
    [ 'topic_id' => 'finance.gl_entry' ],
    [ 'title' => 'Open Help Topic', 'summary' => 'Approval required.', 'requires_approval' => true ]
);
$rehydrated = $manager->hydrateRuntimeContext( $updatedSession, [] );
$resolvedContext = $resolver->hydrateContext( $updatedSession, [] );

$assert( (string) ( $rehydrated['pending_workflow']['step'] ?? '' ) === 'email', 'Conversation state manager should expose pending workflow context.' );
$assert( ! empty( $rehydrated['pending_disambiguation']['candidates'] ), 'Conversation state manager should expose pending disambiguation context.' );
$assert( (string) ( $rehydrated['pending_action']['action_code'] ?? '' ) === (string) ( $action['action_code'] ?? '' ), 'Conversation state manager should expose the latest pending action.' );
$assert( (string) ( $resolvedContext['pending_action']['action_type'] ?? '' ) === 'open_help_topic', 'Conversation resolver should hydrate pending action metadata.' );
$assert( $store->pendingWorkflow( 'TESTSTATE001' ) !== [], 'Conversation store should expose pending workflow state.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes conversation state checks passed.\n" );
