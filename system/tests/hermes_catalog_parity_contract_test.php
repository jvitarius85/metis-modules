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
require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesToolRegistry.php';
require_once $root . '/tests/_support/hermes_blocked_operations_fixture.php';

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

$commandRegistry = new \Metis\Hermes\HermesCommandRegistry();
$toolRegistry = new \Metis\Hermes\HermesToolRegistry();
$operationRegistry = \Metis\Core\Application::service( 'hermes_operations_registry' );
$gateway = \Metis\Core\Application::service( 'hermes_gateway' );

$commands = $commandRegistry->definitions();
$tools = $toolRegistry->definitions();
$operations = $operationRegistry->definitions();
$dashboard = $gateway->dashboardPayload();
$dashboardOperations = (array) ( $dashboard['operations'] ?? [] );
$dashboardTools = (array) ( $dashboard['tools'] ?? [] );
$dashboardRecommendations = array_values( (array) ( $dashboard['recommendations'] ?? [] ) );

foreach ( metis_hermes_blocked_operations_fixture() as $commandKey => $fixture ) {
    $command = (array) ( $commands[ $commandKey ] ?? [] );
    $toolKey = (string) ( $fixture['tool_key'] ?? $command['tool_key'] ?? '' );
    $tool = (array) ( $tools[ $toolKey ] ?? [] );
    $operation = (array) ( $operations[ $commandKey ] ?? [] );
    $dashboardOperation = (array) ( $dashboardOperations[ $commandKey ] ?? [] );
    $dashboardTool = (array) ( $dashboardTools[ $toolKey ] ?? [] );
    $unsupportedMessage = (string) ( $fixture['unsupported_message'] ?? $command['unsupported_message'] ?? '' );

    $assert( $command !== [], sprintf( 'Parity contract requires blocked command [%s].', $commandKey ) );
    $assert( $tool !== [], sprintf( 'Parity contract requires blocked tool [%s].', $toolKey ) );
    $assert( $operation !== [], sprintf( 'Parity contract requires blocked operation [%s].', $commandKey ) );
    $assert( $dashboardOperation !== [], sprintf( 'Parity contract requires blocked dashboard operation [%s].', $commandKey ) );
    $assert( $dashboardTool !== [], sprintf( 'Parity contract requires blocked dashboard tool [%s].', $toolKey ) );

    $assert( array_key_exists( 'supported', $command ) && empty( $command['supported'] ), sprintf( 'Blocked command [%s] must be unsupported.', $commandKey ) );
    $assert( array_key_exists( 'supported', $tool ) && empty( $tool['supported'] ), sprintf( 'Blocked tool [%s] must be unsupported.', $toolKey ) );
    $assert( array_key_exists( 'supported', $operation ) && empty( $operation['supported'] ), sprintf( 'Blocked operation [%s] must be unsupported.', $commandKey ) );
    $assert( array_key_exists( 'supported', $dashboardOperation ) && empty( $dashboardOperation['supported'] ), sprintf( 'Blocked dashboard operation [%s] must be unsupported.', $commandKey ) );
    $assert( array_key_exists( 'supported', $dashboardTool ) && empty( $dashboardTool['supported'] ), sprintf( 'Blocked dashboard tool [%s] must be unsupported.', $toolKey ) );

    $assert( $unsupportedMessage !== '', sprintf( 'Blocked command [%s] must expose an unsupported message.', $commandKey ) );
    $assert( (string) ( $tool['unsupported_message'] ?? '' ) === $unsupportedMessage, sprintf( 'Blocked tool [%s] must share the command unsupported message.', $toolKey ) );
    $assert( (string) ( $operation['unsupported_message'] ?? '' ) === $unsupportedMessage, sprintf( 'Blocked operation [%s] must share the command unsupported message.', $commandKey ) );
    $assert( (string) ( $dashboardOperation['unsupported_message'] ?? '' ) === $unsupportedMessage, sprintf( 'Blocked dashboard operation [%s] must share the command unsupported message.', $commandKey ) );
    $assert( (string) ( $dashboardTool['unsupported_message'] ?? '' ) === $unsupportedMessage, sprintf( 'Blocked dashboard tool [%s] must share the command unsupported message.', $toolKey ) );
}

foreach ( $dashboardRecommendations as $recommendation ) {
    if ( ! is_array( $recommendation ) ) {
        continue;
    }

    $recommendedOperation = (array) ( $recommendation['recommended_operation'] ?? [] );
    $operationKey = (string) ( $recommendedOperation['operation_key'] ?? '' );
    if ( $operationKey === '' ) {
        continue;
    }

    $catalogOperation = (array) ( $operations[ $operationKey ] ?? [] );
    $dashboardOperation = (array) ( $dashboardOperations[ $operationKey ] ?? [] );

    $assert( $catalogOperation !== [], sprintf( 'Recommended operation [%s] must exist in the operations registry.', $operationKey ) );
    $assert( ! array_key_exists( 'supported', $catalogOperation ) || ! empty( $catalogOperation['supported'] ), sprintf( 'Recommended operation [%s] must be supported in the operations registry.', $operationKey ) );
    $assert( ! array_key_exists( 'supported', $dashboardOperation ) || ! empty( $dashboardOperation['supported'] ), sprintf( 'Recommended operation [%s] must be supported in the dashboard operations catalog.', $operationKey ) );
    $assert( ! array_key_exists( 'supported', $recommendedOperation ) || ! empty( $recommendedOperation['supported'] ), sprintf( 'Recommended operation [%s] must be marked supported in recommendation payloads.', $operationKey ) );
    $assert( (string) ( $recommendedOperation['unsupported_message'] ?? '' ) === '', sprintf( 'Recommended operation [%s] must not carry blocked guidance text.', $operationKey ) );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes catalog parity contract checks passed.\n" );
