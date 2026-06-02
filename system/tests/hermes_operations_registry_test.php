<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesToolRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesIntentRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesOperationsRegistry.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$registry = new \Metis\Hermes\HermesOperationsRegistry(
    new \Metis\Hermes\HermesCommandRegistry(),
    new \Metis\Hermes\HermesToolRegistry(),
    new \Metis\Hermes\HermesIntentRegistry()
);

$operations = $registry->definitions();
$createUser = (array) ( $operations['create_user'] ?? [] );
$workspaceUserCreate = (array) ( $operations['workspace_user_create'] ?? [] );
$lookupProfile = (array) ( $operations['lookup_profile'] ?? [] );

$assert( $createUser !== [], 'Operations registry should include create_user.' );
$assert( (string) ( $createUser['tool_key'] ?? '' ) === 'hermes.user.create_user', 'Operations registry should retain tool mapping.' );
$assert( (string) ( $createUser['top_level_intent'] ?? '' ) === 'CREATE', 'Create operations should surface the CREATE top-level intent.' );
$assert( ! empty( $createUser['dispatch']['method'] ), 'Operations registry should expose dispatch metadata.' );
$assert( $workspaceUserCreate !== [], 'Operations registry should include workspace_user_create.' );
$assert( (string) ( $workspaceUserCreate['tool_key'] ?? '' ) === 'hermes.user.create_user', 'Workspace user create should reuse the user creation tool mapping.' );
$assert( (string) ( $workspaceUserCreate['top_level_intent'] ?? '' ) === 'CREATE', 'Workspace user create should surface the CREATE top-level intent.' );

$assert( (string) ( $lookupProfile['top_level_intent'] ?? '' ) === 'LOOKUP', 'Lookup operations should surface the LOOKUP top-level intent.' );
$assert( (string) ( $lookupProfile['risk_level'] ?? '' ) === 'low', 'Lookup operations should inherit risk level from the tool registry.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes operations registry checks passed.\n" );
