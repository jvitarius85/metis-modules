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
require_once $root . '/src/Metis/Hermes/HermesBlockedOperationCatalog.php';

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

$read = static function ( string $relative ) use ( $root ): string {
    $contents = file_get_contents( $root . '/' . ltrim( $relative, '/\\' ) );
    return $contents === false ? '' : $contents;
};

$requiredFiles = [
    'src/Metis/Hermes/Conversation/ConversationStore.php',
    'src/Metis/Hermes/Conversation/ConversationResolver.php',
    'src/Metis/Hermes/Conversation/ConversationStateManager.php',
    'src/Metis/Hermes/Conversation/ConversationContext.php',
    'src/Metis/Hermes/Conversation/PendingAction.php',
    'src/Metis/Hermes/Conversation/PendingQuestion.php',
    'src/Metis/Intelligence/Contracts/IntelligenceProviderInterface.php',
    'src/Metis/Intelligence/DTOs/IntelligenceSnapshot.php',
    'src/Metis/Intelligence/Registry/IntelligenceProviderRegistry.php',
    'src/Metis/Intelligence/Support/IntelligenceResponseFactory.php',
    'src/Metis/Intelligence/Services/TrendIntelligenceService.php',
    'src/Metis/Intelligence/Services/RecommendationIntelligenceService.php',
    'src/Metis/Operations/Contracts/OperationsRegistryInterface.php',
    'src/Metis/Operations/DTOs/OperationDefinition.php',
    'src/Metis/Operations/Registry/OperationsRegistry.php',
    'src/Metis/Operations/Services/OperationDefinitionBuilder.php',
    'src/Metis/Hermes/HermesBlockedOperationCatalog.php',
    'tools/governance/run-hermes-directive-regression.php',
];

foreach ( $requiredFiles as $relativePath ) {
    $assert( is_file( $root . '/' . $relativePath ), sprintf( 'Directive audit requires file [%s].', $relativePath ) );
}

$moduleSource = $read( 'src/Metis/Modules/Hermes/HermesModule.php' );
$gatewaySource = $read( 'src/Metis/Hermes/HermesGateway.php' );
$conversationStateSource = $read( 'src/Metis/Hermes/HermesConversationStateEngine.php' );
$commandRegistrySource = $read( 'src/Metis/Hermes/HermesCommandRegistry.php' );
$operationsBuilderSource = $read( 'src/Metis/Operations/Services/OperationDefinitionBuilder.php' );
$recommendationSource = $read( 'src/Metis/Intelligence/Services/RecommendationIntelligenceService.php' );
$directiveRunnerSource = $read( 'tools/governance/run-hermes-directive-regression.php' );
$architectureSource = $read( 'docs/architecture/hermes-core-architecture.md' );

$assert( str_contains( $moduleSource, "'intelligence_provider_registry'" ), 'Directive audit requires Hermes module wiring for the intelligence provider registry.' );
$assert( str_contains( $moduleSource, "'operations_registry'" ), 'Directive audit requires Hermes module wiring for the standalone operations registry.' );
$assert( str_contains( $moduleSource, "'hermes_conversation_state_manager'" ), 'Directive audit requires Hermes module wiring for the conversation state manager.' );
$assert( str_contains( $moduleSource, "'hermes_approval_engine'" ), 'Directive audit requires Hermes module wiring for the approval engine.' );
$assert( str_contains( $moduleSource, "'hermes_workflow_continuation'" ), 'Directive audit requires Hermes module wiring for the workflow continuation engine.' );
$assert( str_contains( $moduleSource, "'hermes_pending_workflow'" ), 'Directive audit requires Hermes module wiring for the pending workflow engine.' );
$assert( str_contains( $moduleSource, "'hermes_disambiguation'" ), 'Directive audit requires Hermes module wiring for the disambiguation engine.' );
$assert( str_contains( $gatewaySource, "'operations' => \$operations" ), 'Directive audit requires Hermes gateway dashboard payload to expose the operations catalog.' );
$assert( str_contains( $gatewaySource, "'tools' => \$this->tools->definitions()" ), 'Directive audit requires Hermes gateway dashboard payload to expose the tool catalog.' );
$assert( str_contains( $conversationStateSource, 'ConversationStateManager' ), 'Directive audit requires the legacy conversation state engine to delegate into the conversation package.' );
$assert( str_contains( $commandRegistrySource, 'HermesBlockedOperationCatalog::definitions()' ), 'Directive audit requires Hermes command registry to source blocked metadata from HermesBlockedOperationCatalog.' );
$assert( str_contains( $operationsBuilderSource, "'unsupported_message'" ) && str_contains( $operationsBuilderSource, "'supported'" ), 'Directive audit requires the standalone operations builder to preserve support metadata.' );
$assert( str_contains( $recommendationSource, "'supported'" ) && str_contains( $recommendationSource, 'unsupported_message' ), 'Directive audit requires recommendation payloads to carry support metadata.' );
$assert( str_contains( $directiveRunnerSource, 'Hermes directive completion audit' ), 'Directive audit requires the aggregate governance runner to execute the directive completion audit.' );
$assert( str_contains( $directiveRunnerSource, 'hermes_catalog_parity_contract_test.php' ), 'Directive audit requires the aggregate governance runner to execute the catalog parity contract.' );
$assert( str_contains( $directiveRunnerSource, 'intelligence_dashboard_services_test.php' ), 'Directive audit requires the aggregate governance runner to execute the dashboard intelligence slice.' );
$assert( str_contains( $architectureSource, '## Directive Completion Checklist' ), 'Directive audit requires the architecture note to expose a directive completion checklist.' );
$assert( str_contains( $architectureSource, 'run-hermes-directive-regression.php' ), 'Directive audit requires the architecture note to reference the aggregate directive runner.' );

$services = [
    'intelligence_provider_registry',
    'intelligence_recommendations',
    'intelligence_trends',
    'intelligence_diagnostic_trends',
    'operations_registry',
    'hermes_operations_registry',
    'hermes_conversation_state_manager',
    'hermes_approval_engine',
    'hermes_workflow_continuation',
    'hermes_pending_workflow',
    'hermes_disambiguation',
    'hermes_gateway',
];

foreach ( $services as $serviceKey ) {
    $service = \Metis\Core\Application::service( $serviceKey );
    $assert( is_object( $service ), sprintf( 'Directive audit requires service [%s] to resolve as an object.', $serviceKey ) );
}

$blockedCatalog = \Metis\Hermes\HermesBlockedOperationCatalog::definitions();
$assert( count( $blockedCatalog ) === 11, 'Directive audit requires the full blocked backend catalog to remain intact.' );
$assert( isset( $blockedCatalog['service_restart']['tool_key'] ) && $blockedCatalog['service_restart']['tool_key'] === 'hermes.system.restart_service', 'Directive audit requires service_restart to stay mapped in the blocked backend catalog.' );
$assert( isset( $blockedCatalog['rotate_keys']['capability_method'] ) && $blockedCatalog['rotate_keys']['capability_method'] === 'rotateKeys', 'Directive audit requires rotate_keys to stay mapped to HermesCapabilityService.' );

$dashboard = \Metis\Core\Application::service( 'hermes_gateway' )->dashboardPayload();
$operations = (array) ( $dashboard['operations'] ?? [] );
$tools = (array) ( $dashboard['tools'] ?? [] );

$assert( isset( $operations['service_restart']['supported'] ) && empty( $operations['service_restart']['supported'] ), 'Directive audit requires dashboard operations to expose blocked service_restart metadata.' );
$assert( isset( $tools['hermes.system.restart_service']['supported'] ) && empty( $tools['hermes.system.restart_service']['supported'] ), 'Directive audit requires dashboard tools to expose blocked restart-service metadata.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes directive completion audit checks passed.\n" );
