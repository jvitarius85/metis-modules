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

$engine = \Metis\Core\Application::service( 'hermes_operational_engine' );
$processed = $engine->process( 'clear cache and check database health' );
$plan = (array) ( $processed['action_plan'] ?? [] );
$steps = array_values( (array) ( $plan['steps'] ?? [] ) );
$executionPlan = array_values( (array) ( $processed['parsed']['execution_plan'] ?? [] ) );

$assert( count( $executionPlan ) === 2, 'Multi-step operational input should preserve a two-step execution plan.' );
$assert( count( $steps ) === 2, 'Action plan should expose both multi-step operations.' );
$assert( (string) ( $steps[0]['intent'] ?? '' ) === 'clear_cache', 'First step should preserve the first resolved operation.' );
$assert( (string) ( $steps[1]['intent'] ?? '' ) === 'check_db', 'Second step should preserve the second resolved operation.' );
$assert( ! empty( $steps[0]['requires_approval'] ), 'Write-oriented steps should retain approval requirements in the action plan.' );
$assert( empty( $steps[1]['requires_approval'] ), 'Read-only steps should not be marked as approval-gated in the action plan.' );
$assert( (string) ( $processed['permission']['status'] ?? '' ) === 'granted', 'Multi-step validation should aggregate step permissions when all steps are currently allowed.' );
$assert( count( (array) ( $processed['permission']['steps'] ?? [] ) ) === 2, 'Multi-step permission validation should attach per-step permission results.' );
$assert( (string) ( $processed['response']['status'] ?? '' ) === 'awaiting_approval', 'Mixed multi-step plans should require approval when any step is mutating.' );

$prepared = $engine->validatePreparedAction( [
    'operation' => 'clear_cache',
    'command_payload' => [],
    'execution_plan' => $executionPlan,
    'action_plan' => $plan,
] );

$preparedPlan = array_values( (array) ( $prepared['execution_plan'] ?? [] ) );
$assert( count( $preparedPlan ) === 2, 'Prepared-action validation should keep both execution-plan steps.' );
$assert( isset( $preparedPlan[0]['permission']['status'] ), 'Prepared-action validation should attach permission results to each step.' );
$assert( count( (array) ( $prepared['permission']['steps'] ?? [] ) ) === 2, 'Prepared-action validation should validate each step independently.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes multi-step operations checks passed.\n" );
