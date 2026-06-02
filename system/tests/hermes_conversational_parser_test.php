<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/ConversationalParser.php';
require_once $root . '/src/Metis/Hermes/HermesIntentRegistry.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$registry = new \Metis\Hermes\HermesCommandRegistry();
$parser = new \Metis\Hermes\ConversationalParser( $registry, null, null, null, new \Metis\Hermes\HermesIntentRegistry() );

$multi = $parser->parse( 'please disable john@example.com and run diagnostics' );
$assert( $multi['normalized_input'] === 'disable john@example.com and run diagnostics', 'Normalization should strip filler phrases.' );
$assert( count( (array) ( $multi['execution_plan'] ?? [] ) ) === 2, 'Multi-step input should produce a two-step execution plan.' );
$assert( (string) ( $multi['intents'][0]['intent'] ?? '' ) === 'disable_user', 'First fragment should resolve to disable_user.' );
$assert( (string) ( $multi['intents'][1]['intent'] ?? '' ) === 'run_full_diagnostics', 'Second fragment should resolve to run_full_diagnostics.' );
$assert( ! empty( $multi['entities'] ), 'Email entity should be pre-resolved.' );

$context = $parser->parse( 'do it again' );
$assert( ! empty( $context['requires_clarification'] ), 'Context-dependent shorthand without memory should require clarification.' );

$single = $parser->parse( 'list users' );
$assert( (string) ( $single['selected_intent'] ?? '' ) === 'list_users', 'Simple command should map to list_users.' );
$assert( (string) ( $single['top_level_intent'] ?? '' ) === 'LOOKUP', 'Simple list commands should expose the LOOKUP top-level intent.' );
$assert( (string) ( $single['confidence_label'] ?? '' ) === 'high', 'Direct command phrases should achieve high confidence.' );

$lookup = $parser->parse( 'who is meg wallace' );
$assert( (string) ( $lookup['selected_intent'] ?? '' ) === 'lookup_profile', '"who is <name>" should map to lookup_profile.' );
$assert( empty( $lookup['requires_clarification'] ), '"who is <name>" should not require clarification.' );

$attribute = $parser->parse( 'what is meg wallace email' );
$assert( (string) ( $attribute['selected_intent'] ?? '' ) === 'get_entity_attribute', '"what is <name> email" should map to get_entity_attribute.' );

$capability = $parser->parse( 'who has board access' );
$assert( (string) ( $capability['selected_intent'] ?? '' ) === 'query_capability_actors', '"who has board access" should map to query_capability_actors.' );

$help = $parser->parse( "I can't create a new GL entry" );
$assert( (string) ( $help['selected_intent'] ?? '' ) === 'resolve_help_issue', 'Natural-language help issue should map to resolve_help_issue.' );
$assert( (string) ( $help['top_level_intent'] ?? '' ) === 'HELP', 'Natural-language help issue should expose the HELP top-level intent.' );
$assert( (string) ( $help['intents'][0]['payload']['user_message'] ?? '' ) !== '', 'Help issue payload should preserve the user message.' );

$instructional = $parser->parse( 'how do I create a new donation?' );
$assert( (string) ( $instructional['selected_intent'] ?? '' ) === 'resolve_help_issue', 'Instructional help phrasing should map to resolve_help_issue.' );
$assert( empty( $instructional['requires_clarification'] ), 'Instructional help phrasing should resolve without clarification.' );

$userHelp = $parser->parse( 'how do I create a new user?' );
$assert( (string) ( $userHelp['selected_intent'] ?? '' ) === 'resolve_help_issue', 'Instructional user-management phrasing should map to resolve_help_issue.' );
$assert( empty( $userHelp['requires_clarification'] ), 'Instructional user-management phrasing should not require clarification.' );

$userAction = $parser->parse( 'create a new user for Riley with email riley@example.com' );
$assert( (string) ( $userAction['selected_intent'] ?? '' ) === 'create_user', 'Concrete user creation request should map to create_user.' );
$assert( empty( $userAction['requires_clarification'] ), 'Concrete user creation request should not require clarification.' );
$assert( (string) ( $userAction['intents'][0]['payload']['email'] ?? '' ) === 'riley@example.com', 'Concrete user creation request should capture the email payload.' );

$workspaceUser = $parser->parse( 'create a workspace user for Riley with email riley@example.com' );
$assert( (string) ( $workspaceUser['selected_intent'] ?? '' ) === 'workspace_user_create', 'Workspace user creation request should map to workspace_user_create.' );
$assert( empty( $workspaceUser['requires_clarification'] ), 'Workspace user creation request should not require clarification.' );

$noSplit = $parser->parse( 'how do I create a GL entry with debit and credit lines?' );
$assert( count( (array) ( $noSplit['execution_plan'] ?? [] ) ) === 1, 'Instructional GL phrasing should not split on debit and credit wording.' );
$assert( (string) ( $noSplit['selected_intent'] ?? '' ) === 'resolve_help_issue', 'Instructional GL phrasing with accounting terms should still map to resolve_help_issue.' );

$knownActionPrompts = [
    'please run a module diagnotic' => 'check_modules',
    'please run a module diagnostic' => 'check_modules',
    'run full diagnostics' => 'run_full_diagnostics',
    'check database health' => 'check_db',
    'check worker queue' => 'check_workers',
    'show system status' => 'get_system_status',
    'check for system updates' => 'check_system_updates',
    'check updates' => 'check_system_updates',
    'scan integrity' => 'scan_integrity',
    'audit permissions' => 'audit_permissions',
    'validate routes' => 'validate_routes',
    'run enclave test' => 'run_enclave_test',
    'list jobs' => 'list_jobs',
];

foreach ( $knownActionPrompts as $prompt => $expectedIntent ) {
    $parsed = $parser->parse( $prompt );
    $assert(
        (string) ( $parsed['selected_intent'] ?? '' ) === $expectedIntent,
        sprintf( 'Known action prompt [%s] should map to [%s].', $prompt, $expectedIntent )
    );
    $assert(
        (string) ( $parsed['confidence_label'] ?? '' ) === 'high',
        sprintf( 'Known action prompt [%s] should resolve with high confidence.', $prompt )
    );
    $assert(
        empty( $parsed['requires_clarification'] ),
        sprintf( 'Known action prompt [%s] should not require clarification.', $prompt )
    );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes conversational parser checks passed.\n" );
