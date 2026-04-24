<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/ConversationalParser.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$registry = new \Metis\Hermes\HermesCommandRegistry();
$parser = new \Metis\Hermes\ConversationalParser( $registry, null, null, null );

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
$assert( (string) ( $help['intents'][0]['payload']['user_message'] ?? '' ) !== '', 'Help issue payload should preserve the user message.' );

$instructional = $parser->parse( 'how do I create a new donation?' );
$assert( (string) ( $instructional['selected_intent'] ?? '' ) === 'resolve_help_issue', 'Instructional help phrasing should map to resolve_help_issue.' );
$assert( empty( $instructional['requires_clarification'] ), 'Instructional help phrasing should resolve without clarification.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes conversational parser checks passed.\n" );
