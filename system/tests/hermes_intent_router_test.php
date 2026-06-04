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
$assert( (string) ( $data['intent']['entity'] ?? '' ) === 'donor', 'Top donors query should preserve the donor entity in the routed data intent.' );
$assert( (string) ( $data['intent']['date_range']['preset'] ?? '' ) === 'last_month', 'Top donors query should preserve last-month date range metadata.' );

$currentCampaign = $router->route( 'What is the current campaign?' );
$assert( (string) ( $currentCampaign['route_type'] ?? '' ) === 'data', 'Current campaign query should route to the data pipeline.' );
$assert( (string) ( $currentCampaign['intent']['action'] ?? '' ) === 'list', 'Current campaign query should resolve to a bounded list data intent.' );
$assert( (string) ( $currentCampaign['intent']['entity'] ?? '' ) === 'donation_campaign', 'Current campaign query should preserve the donation campaign entity.' );
$assert( (int) ( $currentCampaign['intent']['limit'] ?? 0 ) === 1, 'Current campaign query should constrain the result set to the latest record.' );

$lastDonation = $router->route( 'What was the last donation?' );
$assert( (string) ( $lastDonation['route_type'] ?? '' ) === 'data', 'Last donation query should route to the data pipeline.' );
$assert( (string) ( $lastDonation['intent']['action'] ?? '' ) === 'list', 'Last donation query should resolve to a bounded list data intent.' );
$assert( (string) ( $lastDonation['intent']['entity'] ?? '' ) === 'donation_transaction', 'Last donation query should preserve the donation transaction entity.' );
$assert( (int) ( $lastDonation['intent']['limit'] ?? 0 ) === 1, 'Last donation query should constrain the result set to the latest record.' );

$bestCampaign = $router->route( 'Which campaign performed best?' );
$assert( (string) ( $bestCampaign['route_type'] ?? '' ) === 'data', 'Best campaign query should route to the data pipeline.' );
$assert( (string) ( $bestCampaign['intent']['action'] ?? '' ) === 'top', 'Best campaign query should resolve to the top report intent.' );
$assert( (string) ( $bestCampaign['intent']['entity'] ?? '' ) === 'donation_campaign', 'Best campaign query should preserve the donation campaign entity.' );
$assert( (string) ( $bestCampaign['intent']['top_level_intent'] ?? '' ) === 'REPORT', 'Best campaign query should surface REPORT as the top-level intent.' );

$highestCampaign = $router->route( 'Which campaign raised the most money?' );
$assert( (string) ( $highestCampaign['route_type'] ?? '' ) === 'data', 'Highest-grossing campaign query should route to the data pipeline.' );
$assert( (string) ( $highestCampaign['intent']['action'] ?? '' ) === 'top', 'Highest-grossing campaign query should resolve to the top report intent.' );
$assert( (string) ( $highestCampaign['intent']['entity'] ?? '' ) === 'donation_campaign', 'Highest-grossing campaign query should preserve the donation campaign entity.' );

$showCampaign = $router->route( 'Show campaign Summer Giving.' );
$assert( (string) ( $showCampaign['route_type'] ?? '' ) === 'data', 'Specific campaign lookup should route to the data pipeline.' );
$assert( (string) ( $showCampaign['intent']['action'] ?? '' ) === 'list', 'Specific campaign lookup should resolve to a bounded list intent.' );
$assert( (string) ( $showCampaign['intent']['entity'] ?? '' ) === 'donation_campaign', 'Specific campaign lookup should preserve the donation campaign entity.' );
$assert( (string) ( $showCampaign['intent']['payload']['subject'] ?? '' ) === 'Summer Giving', 'Specific campaign lookup should preserve the campaign subject for conversation memory.' );

$deleteCampaign = $router->route( 'Delete campaign Summer Giving.' );
$assert( (string) ( $deleteCampaign['route_type'] ?? '' ) === 'command', 'Campaign deletion should route to a command path.' );
$assert( (string) ( $deleteCampaign['intent']['action'] ?? '' ) === 'campaign_delete', 'Campaign deletion should resolve to campaign_delete.' );
$assert( (string) ( $deleteCampaign['intent']['top_level_intent'] ?? '' ) === 'DELETE', 'Campaign deletion should surface DELETE as the top-level intent.' );

$deletePerson = $router->route( 'Delete John Smith.' );
$assert( (string) ( $deletePerson['route_type'] ?? '' ) === 'command', 'Bare person deletion should route to a command path.' );
$assert( (string) ( $deletePerson['intent']['action'] ?? '' ) === 'user_delete', 'Bare person deletion should resolve to user_delete.' );
$assert( (string) ( $deletePerson['intent']['payload']['subject'] ?? '' ) === 'John Smith', 'Bare person deletion should preserve the target subject.' );

$lastUpdateInstalled = $router->route( 'when was the last update installed' );
$assert( (string) ( $lastUpdateInstalled['route_type'] ?? '' ) === 'command', 'Last installed update query should route to a read-only command.' );
$assert( (string) ( $lastUpdateInstalled['intent']['action'] ?? '' ) === 'get_system_status', 'Last installed update query should resolve to get_system_status.' );
$assert( empty( $lastUpdateInstalled['command']['requires_approval'] ), 'Last installed update query should not require approval.' );

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
