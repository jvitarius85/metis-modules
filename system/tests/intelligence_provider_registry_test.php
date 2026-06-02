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
\Metis\Modules\Help\HelpModule::boot();
\Metis\Modules\Hermes\HermesModule::boot();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$registry = \Metis\Core\Application::service( 'intelligence_provider_registry' );
$definitions = $registry->definitions();
$resolved = $registry->resolve( 'donation workflow', 5 );
$documentation = (array) ( $resolved['documentation'] ?? [] );
$helpTopics = (array) ( $resolved['help_topics'] ?? [] );
$walkthroughs = (array) ( $resolved['walkthroughs'] ?? [] );

$assert( isset( $definitions['documentation']['key'] ), 'Provider registry should expose documentation intelligence.' );
$assert( isset( $definitions['help_topics']['key'] ), 'Provider registry should expose help-topic intelligence.' );
$assert( (string) ( $definitions['walkthroughs']['type'] ?? '' ) === 'workflow_guidance', 'Walkthrough provider should preserve workflow guidance typing.' );
$assert( isset( $documentation['snapshot']['generated_at'] ), 'Provider registry should emit normalized snapshots.' );
$assert( isset( $helpTopics['results'] ) && is_array( $helpTopics['results'] ), 'Provider registry should expose grouped help-topic results.' );
$assert( isset( $walkthroughs['results'] ) && is_array( $walkthroughs['results'] ), 'Provider registry should expose grouped walkthrough results.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Intelligence provider registry checks passed.\n" );
