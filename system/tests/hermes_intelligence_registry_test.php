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

$registry = \Metis\Core\Application::service( 'hermes_intelligence_registry' );
$knowledge = \Metis\Core\Application::service( 'hermes_knowledge' );

$definitions = $registry->definitions();
$resolved = $registry->resolve( 'donation workflow', 5 );
$delegated = $knowledge->resolve( 'donation workflow', 5 );

$assert( isset( $definitions['documentation']['key'] ), 'Intelligence registry should expose documentation source definitions.' );
$assert( (string) ( $definitions['walkthroughs']['type'] ?? '' ) === 'workflow_guidance', 'Walkthrough intelligence should be typed as workflow guidance.' );
$assert( isset( $resolved['sources']['help_topics']['results'] ), 'Intelligence registry should group resolved results by source.' );
$assert( isset( $resolved['grounding'] ), 'Intelligence registry should return grounding metadata.' );
$assert( $delegated === $resolved, 'Knowledge service should delegate directly to the intelligence registry.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes intelligence registry checks passed.\n" );
