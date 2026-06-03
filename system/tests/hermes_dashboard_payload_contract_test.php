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

$payload = \Metis\Core\Application::service( 'hermes_gateway' )->dashboardPayload();
$operations = (array) ( $payload['operations'] ?? [] );
$tools = (array) ( $payload['tools'] ?? [] );
$recommendations = array_values( (array) ( $payload['recommendations'] ?? [] ) );

$serviceRestart = (array) ( $operations['service_restart'] ?? [] );
$rotateKeys = (array) ( $operations['rotate_keys'] ?? [] );
$exportData = (array) ( $operations['export_data'] ?? [] );
$serviceRestartTool = (array) ( $tools['hermes.system.restart_service'] ?? [] );
$rotateKeysTool = (array) ( $tools['hermes.security.rotate_keys'] ?? [] );
$exportDataTool = (array) ( $tools['hermes.data.export_data'] ?? [] );

$assert( $operations !== [], 'Hermes dashboard payload should expose the operations catalog.' );
$assert( $tools !== [], 'Hermes dashboard payload should expose the tool catalog.' );
$assert( count( $operations ) === count( \Metis\Core\Application::service( 'hermes_operations_registry' )->definitions() ), 'Hermes dashboard payload should surface the full operations registry.' );
$assert( count( $tools ) === count( \Metis\Core\Application::service( 'hermes_tool_registry' )->definitions() ), 'Hermes dashboard payload should surface the full tool registry.' );
$assert( array_key_exists( 'supported', $serviceRestart ) && empty( $serviceRestart['supported'] ), 'Hermes dashboard payload should mark service_restart unsupported.' );
$assert( (string) ( $serviceRestart['unsupported_message'] ?? '' ) === 'Service restart does not have a trusted backend registered for Hermes execution yet.', 'Hermes dashboard payload should preserve the service_restart unsupported message.' );
$assert( array_key_exists( 'supported', $rotateKeys ) && empty( $rotateKeys['supported'] ), 'Hermes dashboard payload should mark rotate_keys unsupported.' );
$assert( trim( (string) ( $rotateKeys['unsupported_message'] ?? '' ) ) !== '', 'Hermes dashboard payload should preserve rotate_keys guidance.' );
$assert( array_key_exists( 'supported', $exportData ) && empty( $exportData['supported'] ), 'Hermes dashboard payload should mark export_data unsupported.' );
$assert( array_key_exists( 'supported', $serviceRestartTool ) && empty( $serviceRestartTool['supported'] ), 'Hermes dashboard payload should mark the restart-service tool unsupported.' );
$assert( (string) ( $serviceRestartTool['unsupported_message'] ?? '' ) === 'Service restart does not have a trusted backend registered for Hermes execution yet.', 'Hermes dashboard payload should preserve the restart-service tool guidance.' );
$assert( array_key_exists( 'supported', $rotateKeysTool ) && empty( $rotateKeysTool['supported'] ), 'Hermes dashboard payload should mark the rotate-keys tool unsupported.' );
$assert( array_key_exists( 'supported', $exportDataTool ) && empty( $exportDataTool['supported'] ), 'Hermes dashboard payload should mark the export-data tool unsupported.' );
$assert( (int) ( (array) ( $payload['overview'] ?? [] ) )['recommendation_count'] === count( $recommendations ), 'Hermes dashboard overview should keep recommendation_count aligned with the payload.' );

foreach ( $recommendations as $recommendation ) {
    if ( ! is_array( $recommendation ) ) {
        continue;
    }

    $recommendedOperation = (array) ( $recommendation['recommended_operation'] ?? [] );
    $operationKey = (string) ( $recommendedOperation['operation_key'] ?? '' );
    if ( $operationKey === '' ) {
        continue;
    }

    $catalogOperation = (array) ( $operations[ $operationKey ] ?? [] );
    $assert( $catalogOperation !== [], sprintf( 'Hermes dashboard recommendations should reference cataloged operations [%s].', $operationKey ) );
    $assert( ! array_key_exists( 'supported', $catalogOperation ) || ! empty( $catalogOperation['supported'] ), sprintf( 'Hermes dashboard recommendations should not suggest unsupported operation [%s].', $operationKey ) );
    $assert( ! array_key_exists( 'supported', $recommendedOperation ) || ! empty( $recommendedOperation['supported'] ), sprintf( 'Hermes dashboard recommended operation metadata should mark [%s] supported.', $operationKey ) );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes dashboard payload contract checks passed.\n" );
