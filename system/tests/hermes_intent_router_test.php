<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesIntentRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesAttributeRegistry.php';
require_once $root . '/src/Metis/Hermes/EntityRegistryBuilder.php';
require_once $root . '/src/Metis/Hermes/HermesIntentParser.php';
require_once $root . '/src/Metis/Hermes/ConversationalParser.php';
require_once $root . '/src/Metis/Hermes/HermesIntentRouter.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$commands = new \Metis\Hermes\HermesCommandRegistry();
$intentRegistry = new \Metis\Hermes\HermesIntentRegistry();
$attributeRegistry = new \Metis\Hermes\HermesAttributeRegistry();
$entityRegistry = new \Metis\Hermes\EntityRegistryBuilder( $root . '/modules' );
$legacy = new \Metis\Hermes\HermesIntentParser( $commands, $entityRegistry, $intentRegistry, $attributeRegistry );
$parser = new \Metis\Hermes\ConversationalParser( $commands, null, null, $legacy, $intentRegistry );
$router = new \Metis\Hermes\HermesIntentRouter( $parser, $commands, $legacy, $intentRegistry );

$data = $router->route( 'Who were the top 5 donors last month?' );
$assert( (string) ( $data['route_type'] ?? '' ) === 'data', 'Top donors query should route to the data pipeline.' );
$assert( (string) ( $data['intent']['action'] ?? '' ) === 'top', 'Top donors query should retain the top data intent.' );
$assert( (string) ( $data['intent']['top_level_intent'] ?? '' ) === 'REPORT', 'Top donors query should surface REPORT as the top-level intent.' );

$help = $router->route( 'How do I create a new user?', [ 'current_module' => 'people', 'current_route' => '/admin/people/dashboard' ] );
$assert( (string) ( $help['route_type'] ?? '' ) === 'command', 'Instructional help should remain a routable Hermes command.' );
$assert( (string) ( $help['intent']['action'] ?? '' ) === 'resolve_help_issue', 'Instructional help should route to resolve_help_issue.' );
$assert( (string) ( $help['intent']['payload']['current_module'] ?? '' ) === 'people', 'Help payload should absorb runtime module context.' );

$attribute = $router->route( 'What is Meg Wallace email?' );
$assert( (string) ( $attribute['route_type'] ?? '' ) === 'entity_attribute', 'Attribute lookup should route to entity-attribute handling.' );

$command = $router->route( 'list users' );
$assert( (string) ( $command['route_type'] ?? '' ) === 'data', 'List-style record queries should route to the structured data pipeline.' );
$assert( (string) ( $command['intent']['action'] ?? '' ) === 'list', 'List-style record queries should retain the list data intent.' );
$assert( (string) ( $command['intent']['top_level_intent'] ?? '' ) === 'LOOKUP', 'Direct list commands should surface LOOKUP as the top-level intent.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes intent router checks passed.\n" );
