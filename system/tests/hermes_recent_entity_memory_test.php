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
$memory = \Metis\Core\Application::service( 'hermes_memory_store' );
$parser = \Metis\Core\Application::service( 'hermes_conversational_parser' );

$sessionCode = 'TESTENTITY' . strtoupper( substr( md5( uniqid( 'entity', true ) ), 0, 8 ) );
$turn = $state->openTurn( 0, 'Show me John Smith', $sessionCode );
$session = (array) ( $turn['session'] ?? [] );

$processed = [
    'intent' => [
        'action' => 'lookup_profile',
        'top_level_intent' => 'LOOKUP',
        'payload' => [
            'profile_request' => [
                'subject' => 'John Smith',
                'entity_hint' => 'person',
            ],
        ],
    ],
];
$response = [
    'status' => 'success',
    'response_type' => 'ProfileLookup',
    'message' => 'Found John Smith.',
    'entity' => 'person',
    'id' => 42,
];

$state->completeTurn( $session, 'Show me John Smith', $processed, $response );

$recentEntity = $memory->recallRecentEntity( $sessionCode );
$hydrated = $state->hydrateRuntimeContext( $session, [] );
$attributeFollowUp = $parser->parse( 'what is his email', $sessionCode );
$actionFollowUp = $parser->parse( 'disable that user', $sessionCode );
$workspaceResetFollowUp = $parser->parse( 'reset his workspace password', $sessionCode );
$genericResetFollowUp = $parser->parse( 'reset his password', $sessionCode );
$enableFollowUp = $parser->parse( 'enable that user', $sessionCode );
$updateFollowUp = $parser->parse( 'update that user', $sessionCode );

$assert( (string) ( $recentEntity['subject'] ?? '' ) === 'John Smith', 'Recent entity memory should persist the latest resolved subject.' );
$assert( (string) ( $recentEntity['entity_hint'] ?? '' ) === 'person', 'Recent entity memory should retain the entity hint.' );
$assert( (string) ( $hydrated['recent_entity']['subject'] ?? '' ) === 'John Smith', 'Hydrated runtime context should include the recent entity.' );
$assert( (string) ( $attributeFollowUp['selected_intent'] ?? '' ) === 'get_entity_attribute', 'Pronoun attribute follow-up should resolve to get_entity_attribute.' );
$assert( (string) ( $attributeFollowUp['intents'][0]['payload']['attribute_request']['subject'] ?? '' ) === 'John Smith', 'Pronoun attribute follow-up should reuse the recent entity subject.' );
$assert( (string) ( $actionFollowUp['selected_intent'] ?? '' ) === 'disable_user', 'Contextual action follow-up should resolve to disable_user.' );
$assert( (string) ( $actionFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Contextual action follow-up should reuse the recent entity subject.' );
$assert( empty( $actionFollowUp['requires_clarification'] ), 'Contextual action follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $workspaceResetFollowUp['selected_intent'] ?? '' ) === 'workspace_user_password_reset', 'Contextual workspace password reset follow-up should resolve to workspace_user_password_reset.' );
$assert( (string) ( $workspaceResetFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Contextual workspace password reset should reuse the recent entity subject.' );
$assert( empty( $workspaceResetFollowUp['requires_clarification'] ), 'Scoped workspace password reset follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $genericResetFollowUp['selected_intent'] ?? '' ) === 'user_password_reset', 'Generic password reset follow-up should still resolve to user_password_reset before workflow clarification.' );
$assert( (string) ( $genericResetFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Generic password reset follow-up should reuse the recent entity subject.' );
$assert( empty( $genericResetFollowUp['requires_clarification'] ), 'Generic password reset follow-up should gain enough confidence from recent entity context.' );
$assert( (string) ( $enableFollowUp['selected_intent'] ?? '' ) === 'enable_user', 'Contextual enable follow-up should resolve to enable_user.' );
$assert( (string) ( $enableFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Contextual enable follow-up should reuse the recent entity subject.' );
$assert( empty( $enableFollowUp['requires_clarification'] ), 'Contextual enable follow-up should not require clarification when recent entity memory exists.' );
$assert( (string) ( $updateFollowUp['selected_intent'] ?? '' ) === 'update_user', 'Contextual update follow-up should resolve to update_user.' );
$assert( (string) ( $updateFollowUp['intents'][0]['payload']['subject'] ?? '' ) === 'John Smith', 'Contextual update follow-up should reuse the recent entity subject.' );
$assert( empty( $updateFollowUp['requires_clarification'] ), 'Contextual update follow-up should not require clarification when recent entity memory exists.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes recent entity memory checks passed.\n" );
