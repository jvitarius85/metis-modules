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

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes conversational parser checks passed.\n" );
