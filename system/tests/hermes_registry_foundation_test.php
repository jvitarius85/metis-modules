<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesIntentRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesAttributeRegistry.php';
require_once $root . '/src/Metis/Hermes/AttributeResolver.php';
require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesIntentParser.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$intents = new \Metis\Hermes\HermesIntentRegistry();
$attributes = new \Metis\Hermes\HermesAttributeRegistry();
$resolver = new \Metis\Hermes\AttributeResolver( $attributes );
$parser = new \Metis\Hermes\HermesIntentParser(
    new \Metis\Hermes\HermesCommandRegistry(),
    null,
    $intents,
    $attributes
);

$assert( $intents->supportedIntents() === [ 'LOOKUP', 'REPORT', 'CREATE', 'UPDATE', 'DELETE', 'EXECUTE', 'HELP' ], 'Intent registry should expose the canonical Hermes intent set.' );
$assert( $intents->classifyQuery( 'how do I create a campaign?' ) === 'HELP', 'Instructional questions should classify as HELP.' );
$assert( $intents->classifyCommand( 'create_user' ) === 'CREATE', 'Create commands should map to CREATE.' );
$assert( $intents->classifyCommand( 'query_giving_summary' ) === 'REPORT', 'Summary commands should map to REPORT.' );
$assert( $intents->classifyCommand( 'lookup_profile' ) === 'LOOKUP', 'Lookup commands should map to LOOKUP.' );

$assert( $attributes->resolve( 'email address' ) === 'email', 'Attribute registry should resolve email aliases.' );
$assert( $attributes->resolve( 'workspace groups' ) === 'groups', 'Attribute registry should resolve group aliases.' );
$assert( $attributes->detectFromQuery( "whose email is this: meg@example.com" ) === 'name', 'Ownership queries should resolve to the canonical name attribute.' );
$assert( $resolver->supportedAttributes() === $attributes->supportedAttributes(), 'Attribute resolver should delegate supported attributes to the centralized registry.' );

$lookup = $parser->parse( 'What is Meg Wallace email?' );
$assert( (string) ( $lookup['top_level_intent'] ?? '' ) === 'LOOKUP', 'Attribute lookups should surface the LOOKUP top-level intent.' );
$assert( (string) ( $lookup['payload']['attribute_request']['attribute'] ?? '' ) === 'email', 'Attribute parser should use the centralized attribute registry.' );

$help = $parser->parse( 'How do I create a new user?' );
$assert( (string) ( $help['top_level_intent'] ?? '' ) === 'HELP', 'Instructional requests should surface the HELP top-level intent.' );

$report = $parser->parse( 'Who were the top 5 donors last month?' );
$assert( (string) ( $report['top_level_intent'] ?? '' ) === 'REPORT', 'Aggregated donor questions should surface the REPORT top-level intent.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes registry foundation checks passed.\n" );
