<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes;

use Metis\Core\Application;
use Metis\Intelligence\Registry\IntelligenceProviderRegistry;
use Metis\Intelligence\Services\DocumentationIntelligenceProvider;
use Metis\Intelligence\Services\HelpTopicIntelligenceProvider;
use Metis\Intelligence\Services\WalkthroughIntelligenceProvider;
use Metis\Intelligence\Support\IntelligenceResponseFactory;
use Metis\Hermes\Conversation\ConversationResolver;
use Metis\Hermes\Conversation\ConversationStateManager;
use Metis\Hermes\Conversation\ConversationStore;
use Metis\Hermes\HermesActionPlanner;
use Metis\Hermes\HermesActionPreview;
use Metis\Hermes\HermesActionExecutor;
use Metis\Hermes\HermesApprovalEngine;
use Metis\Hermes\HermesAuditLogger;
use Metis\Hermes\HermesCommandRegistry;
use Metis\Hermes\HermesContextBuilder;
use Metis\Hermes\HermesContextPackLoader;
use Metis\Hermes\ConversationalParser;
use Metis\Hermes\HermesDiagnosticEngine;
use Metis\Hermes\HermesDisambiguationEngine;
use Metis\Hermes\HermesDocumentationIndex;
use Metis\Hermes\HermesExecutionEngine;
use Metis\Hermes\HermesGateway;
use Metis\Hermes\HermesGroundingValidator;
use Metis\Hermes\HermesHelpResolver;
use Metis\Hermes\HermesIntentParser;
use Metis\Hermes\HermesIntentRegistry;
use Metis\Hermes\HermesIntentRouter;
use Metis\Hermes\HermesIntelligenceRegistry;
use Metis\Hermes\HermesKnowledgeService;
use Metis\Hermes\HermesMemoryStore;
use Metis\Hermes\HermesMissionEngine;
use Metis\Hermes\HermesOperationalEngine;
use Metis\Hermes\HermesOperationsRegistry;
use Metis\Hermes\HermesPlaybookEngine;
use Metis\Hermes\HermesPermissionValidator;
use Metis\Hermes\HermesPendingWorkflowEngine;
use Metis\Hermes\HermesReasoner;
use Metis\Hermes\HermesRepository;
use Metis\Hermes\HermesResponseRenderer;
use Metis\Hermes\HermesToolRegistry;
use Metis\Hermes\HermesToolExecutor;
use Metis\Hermes\HermesWalkthroughResolver;
use Metis\Hermes\HermesWorkflowContinuationEngine;
use Metis\Hermes\HermesWorkerManager;
use Metis\Hermes\HelpIssueResolver;
use Metis\Hermes\EntityRegistryBuilder;
use Metis\Hermes\DataCapabilityBuilder;
use Metis\Hermes\HermesSafetyGovernor;
use Metis\Hermes\HermesQueryBuilder;
use Metis\Hermes\EntityResolver;
use Metis\Hermes\AttributeResolver;
use Metis\Hermes\HermesAttributeRegistry;
use Metis\Hermes\HermesDebugLogger;
use Metis\Hermes\HermesReportingService;
use Metis\Hermes\HermesUniversalActionRegistry;
use Metis\Hermes\HermesPlaybookValidator;
use Metis\Hermes\HermesSecurityIntegration;
use Metis\Hermes\HermesConversationStateEngine;

final class HermesModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        self::registerServices();

        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );
        \metis_on( 'init', static function (): void {
            Application::service( 'hermes_worker_manager' )->register();
        }, 8 );

        $enclave = \metis_security_enclave();
        if ( ! $enclave->has_policy( 'hermes.action.execute' ) ) {
            $enclave->register_policy( new \Metis_Security_Policy( 'hermes.action.execute', 'hermes', 'edit', true, true, true, 'metis_ajax:metis_hermes_execute_action', 60, 60 ) );
        }

        // Chunk 6: register all Hermes enclave policies in one pass
        HermesSecurityIntegration::registerPolicies();

        \Metis_Logger::info( 'Hermes bootstrap loaded' );
    }

    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'hermes_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        SchemaManager::ensureSchema();
    }

    private static function registerServices(): void {
        $registry = Application::registry();

        if ( ! $registry->has( 'hermes_repository' ) ) {
            $registry->singleton( 'hermes_repository', static fn (): HermesRepository => new HermesRepository() );
        }
        if ( ! $registry->has( 'hermes_audit_logger' ) ) {
            $registry->singleton( 'hermes_audit_logger', static fn (): HermesAuditLogger => new HermesAuditLogger( Application::service( 'hermes_repository' ) ) );
        }
        if ( ! $registry->has( 'hermes_memory_store' ) ) {
            $registry->singleton( 'hermes_memory_store', static fn (): HermesMemoryStore => new HermesMemoryStore( Application::service( 'hermes_repository' ) ) );
        }
        if ( ! $registry->has( 'hermes_documentation_index' ) ) {
            $registry->singleton( 'hermes_documentation_index', static fn (): HermesDocumentationIndex => new HermesDocumentationIndex( Application::service( 'hermes_library' ) ) );
        }
        if ( ! $registry->has( 'hermes_help_resolver' ) ) {
            $registry->singleton( 'hermes_help_resolver', static fn (): HermesHelpResolver => new HermesHelpResolver( Application::service( 'help' ) ) );
        }
        if ( ! $registry->has( 'hermes_help_issue_resolver' ) ) {
            $registry->singleton( 'hermes_help_issue_resolver', static fn (): HelpIssueResolver => new HelpIssueResolver(
                Application::service( 'help' ),
                new \Metis\Core\HelpSearchStore(),
                Application::service( 'permissions' ),
                Application::service( 'hermes_repository' ),
                Application::service( 'hermes_command_registry' )
            ) );
        }
        if ( ! $registry->has( 'hermes_walkthrough_resolver' ) ) {
            $registry->singleton( 'hermes_walkthrough_resolver', static fn (): HermesWalkthroughResolver => new HermesWalkthroughResolver( Application::service( 'walkthroughs' ) ) );
        }
        if ( ! $registry->has( 'hermes_grounding_validator' ) ) {
            $registry->singleton( 'hermes_grounding_validator', static fn (): HermesGroundingValidator => new HermesGroundingValidator() );
        }
        if ( ! $registry->has( 'intelligence_response_factory' ) ) {
            $registry->singleton( 'intelligence_response_factory', static fn (): IntelligenceResponseFactory => new IntelligenceResponseFactory() );
        }
        if ( ! $registry->has( 'intelligence_provider_registry' ) ) {
            $registry->singleton( 'intelligence_provider_registry', static fn (): IntelligenceProviderRegistry => new IntelligenceProviderRegistry(
                [
                    new DocumentationIntelligenceProvider( Application::service( 'hermes_documentation_index' ) ),
                    new HelpTopicIntelligenceProvider( Application::service( 'hermes_help_resolver' ) ),
                    new WalkthroughIntelligenceProvider( Application::service( 'hermes_walkthrough_resolver' ) ),
                ],
                Application::service( 'intelligence_response_factory' )
            ) );
        }
        if ( ! $registry->has( 'hermes_knowledge' ) ) {
            $registry->singleton( 'hermes_intelligence_registry', static fn (): HermesIntelligenceRegistry => new HermesIntelligenceRegistry(
                Application::service( 'intelligence_provider_registry' ),
                Application::service( 'hermes_grounding_validator' )
            ) );
            $registry->singleton( 'hermes_knowledge', static fn (): HermesKnowledgeService => new HermesKnowledgeService(
                Application::service( 'hermes_intelligence_registry' )
            ) );
        }
        if ( ! $registry->has( 'hermes_tool_registry' ) ) {
            $registry->singleton( 'hermes_tool_registry', static fn (): HermesToolRegistry => new HermesToolRegistry() );
        }
        if ( ! $registry->has( 'hermes_command_registry' ) ) {
            $registry->singleton( 'hermes_command_registry', static fn (): HermesCommandRegistry => new HermesCommandRegistry() );
        }
        if ( ! $registry->has( 'hermes_operations_registry' ) ) {
            $registry->singleton( 'hermes_operations_registry', static fn (): HermesOperationsRegistry => new HermesOperationsRegistry(
                Application::service( 'hermes_command_registry' ),
                Application::service( 'hermes_tool_registry' ),
                Application::service( 'hermes_intent_registry' )
            ) );
        }
        if ( ! $registry->has( 'hermes_conversational_parser' ) ) {
            $registry->singleton( 'hermes_conversational_parser', static fn (): ConversationalParser => new ConversationalParser(
                Application::service( 'hermes_command_registry' ),
                Application::service( 'hermes_entity_resolver' ),
                Application::service( 'hermes_memory_store' ),
                Application::service( 'hermes_intent_parser' ),
                Application::service( 'hermes_intent_registry' )
            ) );
        }
        if ( ! $registry->has( 'hermes_intent_registry' ) ) {
            $registry->singleton( 'hermes_intent_registry', static fn (): HermesIntentRegistry => new HermesIntentRegistry() );
        }
        if ( ! $registry->has( 'hermes_attribute_registry' ) ) {
            $registry->singleton( 'hermes_attribute_registry', static fn (): HermesAttributeRegistry => new HermesAttributeRegistry() );
        }
        if ( ! $registry->has( 'hermes_intent_parser' ) ) {
            $registry->singleton( 'hermes_intent_parser', static fn (): HermesIntentParser => new HermesIntentParser(
                Application::service( 'hermes_command_registry' ),
                Application::service( 'hermes_entity_registry' ),
                Application::service( 'hermes_intent_registry' ),
                Application::service( 'hermes_attribute_registry' )
            ) );
        }
        if ( ! $registry->has( 'hermes_context_pack_loader' ) ) {
            $registry->singleton( 'hermes_context_pack_loader', static fn (): HermesContextPackLoader => new HermesContextPackLoader( Application::service( 'hermes_library' ) ) );
        }
        if ( ! $registry->has( 'hermes_intent_router' ) ) {
            $registry->singleton( 'hermes_intent_router', static fn (): HermesIntentRouter => new HermesIntentRouter(
                Application::service( 'hermes_conversational_parser' ),
                Application::service( 'hermes_command_registry' ),
                Application::service( 'hermes_intent_parser' ),
                Application::service( 'hermes_intent_registry' )
            ) );
        }
        if ( ! $registry->has( 'hermes_permission_validator' ) ) {
            $registry->singleton( 'hermes_permission_validator', static fn (): HermesPermissionValidator => new HermesPermissionValidator( Application::service( 'permissions' ) ) );
        }
        if ( ! $registry->has( 'hermes_execution_engine' ) ) {
            $registry->singleton( 'hermes_execution_engine', static fn (): HermesExecutionEngine => new HermesExecutionEngine(
                Application::service( 'hermes_tool_executor' )
            ) );
        }
        if ( ! $registry->has( 'hermes_tool_executor' ) ) {
            $registry->singleton( 'hermes_tool_executor', static fn (): HermesToolExecutor => new HermesToolExecutor(
                Application::service( 'hermes_tool_registry' ),
                Application::service( 'hermes_permission_validator' )
            ) );
        }
        if ( ! $registry->has( 'hermes_response_renderer' ) ) {
            $registry->singleton( 'hermes_response_renderer', static fn (): HermesResponseRenderer => new HermesResponseRenderer() );
        }
        if ( ! $registry->has( 'hermes_operational_engine' ) ) {
            $registry->singleton( 'hermes_entity_resolver', static fn (): EntityResolver => new EntityResolver(
                Application::service( 'entity_resolver_service' )
            ) );
        }
        if ( ! $registry->has( 'hermes_attribute_resolver' ) ) {
            $registry->singleton( 'hermes_attribute_resolver', static fn (): AttributeResolver => new AttributeResolver(
                Application::service( 'hermes_attribute_registry' )
            ) );
        }
        if ( ! $registry->has( 'hermes_debug_logger' ) ) {
            $registry->singleton( 'hermes_debug_logger', static fn (): HermesDebugLogger => new HermesDebugLogger() );
        }
        if ( ! $registry->has( 'hermes_conversation_store' ) ) {
            $registry->singleton( 'hermes_conversation_store', static fn (): ConversationStore => new ConversationStore(
                Application::service( 'hermes_repository' ),
                Application::service( 'hermes_memory_store' )
            ) );
        }
        if ( ! $registry->has( 'hermes_conversation_resolver' ) ) {
            $registry->singleton( 'hermes_conversation_resolver', static fn (): ConversationResolver => new ConversationResolver(
                Application::service( 'hermes_conversation_store' )
            ) );
        }
        if ( ! $registry->has( 'hermes_conversation_state_manager' ) ) {
            $registry->singleton( 'hermes_conversation_state_manager', static fn (): ConversationStateManager => new ConversationStateManager(
                Application::service( 'hermes_conversation_store' ),
                Application::service( 'hermes_conversation_resolver' )
            ) );
        }
        if ( ! $registry->has( 'hermes_conversation_state' ) ) {
            $registry->singleton( 'hermes_conversation_state', static fn (): HermesConversationStateEngine => new HermesConversationStateEngine(
                Application::service( 'hermes_conversation_state_manager' )
            ) );
        }
        if ( ! $registry->has( 'hermes_operational_engine' ) ) {
            $registry->singleton( 'hermes_operational_engine', static fn (): HermesOperationalEngine => new HermesOperationalEngine(
                Application::service( 'hermes_intent_router' ),
                Application::service( 'hermes_context_pack_loader' ),
                Application::service( 'hermes_command_registry' ),
                Application::service( 'hermes_permission_validator' ),
                Application::service( 'hermes_execution_engine' ),
                Application::service( 'hermes_response_renderer' ),
                Application::service( 'hermes_entity_resolver' ),
                Application::service( 'hermes_attribute_resolver' ),
                Application::service( 'hermes_debug_logger' )
            ) );
        }
        if ( ! $registry->has( 'hermes_capabilities' ) ) {
            $registry->singleton( 'hermes_capabilities', static fn (): \Metis\Services\HermesCapabilityService => new \Metis\Services\HermesCapabilityService(
                Application::service( 'db' ),
                Application::service( 'hermes_directory' ),
                Application::service( 'hermes_user_admin' ),
                Application::service( 'hermes_system_ops' ),
                function_exists( 'metis_job_queue' ) ? \metis_job_queue() : null,
                Application::service( 'hermes_help_issue_resolver' )
            ) );
        }
        if ( ! $registry->has( 'hermes_context_builder' ) ) {
            $registry->singleton( 'hermes_context_builder', static fn (): HermesContextBuilder => new HermesContextBuilder(
                Application::service( 'hermes_library' ),
                Application::service( 'hermes_memory_store' ),
                Application::service( 'hermes_knowledge' )
            ) );
        }
        if ( ! $registry->has( 'hermes_diagnostic_engine' ) ) {
            $registry->singleton( 'hermes_diagnostic_engine', static fn (): HermesDiagnosticEngine => new HermesDiagnosticEngine( Application::service( 'hermes_repository' ) ) );
        }
        // Chunk 5: universal action registry + playbook validator
        if ( ! $registry->has( 'hermes_universal_actions' ) ) {
            $registry->singleton( 'hermes_universal_actions', static fn (): HermesUniversalActionRegistry => new HermesUniversalActionRegistry() );
        }
        if ( ! $registry->has( 'hermes_playbook_validator' ) ) {
            $registry->singleton( 'hermes_playbook_validator', static fn (): HermesPlaybookValidator => new HermesPlaybookValidator(
                Application::service( 'hermes_universal_actions' ),
                Application::service( 'hermes_safety_governor' ),
                Application::service( 'hermes_library' )
            ) );
        }
        if ( ! $registry->has( 'hermes_playbook_engine' ) ) {
            $registry->singleton( 'hermes_playbook_engine', static fn (): HermesPlaybookEngine => new HermesPlaybookEngine(
                Application::service( 'hermes_library' ),
                Application::service( 'hermes_playbook_validator' )
            ) );
        }
        if ( ! $registry->has( 'hermes_mission_engine' ) ) {
            $registry->singleton( 'hermes_mission_engine', static fn (): HermesMissionEngine => new HermesMissionEngine( Application::service( 'hermes_library' ) ) );
        }
        if ( ! $registry->has( 'hermes_action_preview' ) ) {
            $registry->singleton( 'hermes_action_preview', static fn (): HermesActionPreview => new HermesActionPreview() );
        }
        if ( ! $registry->has( 'hermes_action_executor' ) ) {
            $registry->singleton( 'hermes_action_executor', static fn (): HermesActionExecutor => new HermesActionExecutor(
                Application::service( 'hermes_repository' ),
                Application::service( 'hermes_operational_engine' ),
                Application::service( 'hermes_audit_logger' ),
                Application::service( 'hermes_reasoner' ),
                Application::service( 'hermes_memory_store' ),
                Application::service( 'hermes_mission_engine' ),
                Application::service( 'hermes_walkthrough_resolver' ),
                Application::service( 'hermes_help_resolver' )
            ) );
        }
        if ( ! $registry->has( 'hermes_approval_engine' ) ) {
            $registry->singleton( 'hermes_approval_engine', static fn (): HermesApprovalEngine => new HermesApprovalEngine(
                Application::service( 'hermes_repository' ),
                Application::service( 'hermes_action_preview' ),
                Application::service( 'hermes_operational_engine' ),
                Application::service( 'hermes_audit_logger' )
            ) );
        }
        if ( ! $registry->has( 'hermes_workflow_continuation' ) ) {
            $registry->singleton( 'hermes_workflow_continuation', static fn (): HermesWorkflowContinuationEngine => new HermesWorkflowContinuationEngine(
                Application::service( 'hermes_repository' ),
                Application::service( 'hermes_approval_engine' ),
                Application::service( 'hermes_action_executor' ),
                Application::service( 'hermes_audit_logger' )
            ) );
        }
        if ( ! $registry->has( 'hermes_pending_workflow' ) ) {
            $registry->singleton( 'hermes_pending_workflow', static fn (): HermesPendingWorkflowEngine => new HermesPendingWorkflowEngine(
                Application::service( 'hermes_memory_store' ),
                Application::service( 'hermes_command_registry' ),
                Application::service( 'hermes_response_renderer' )
            ) );
        }
        if ( ! $registry->has( 'hermes_disambiguation' ) ) {
            $registry->singleton( 'hermes_disambiguation', static fn (): HermesDisambiguationEngine => new HermesDisambiguationEngine(
                Application::service( 'hermes_memory_store' )
            ) );
        }
        if ( ! $registry->has( 'hermes_action_planner' ) ) {
            $registry->singleton( 'hermes_action_planner', static fn (): HermesActionPlanner => new HermesActionPlanner(
                Application::service( 'hermes_action_preview' ),
                Application::service( 'hermes_tool_registry' )
            ) );
        }
        if ( ! $registry->has( 'hermes_reasoner' ) ) {
            $registry->singleton( 'hermes_reasoner', static fn (): HermesReasoner => new HermesReasoner(
                Application::service( 'hermes_context_builder' ),
                Application::service( 'hermes_diagnostic_engine' ),
                Application::service( 'hermes_playbook_engine' ),
                Application::service( 'hermes_mission_engine' ),
                Application::service( 'hermes_action_planner' ),
                Application::service( 'hermes_grounding_validator' )
            ) );
        }
        if ( ! $registry->has( 'hermes_gateway' ) ) {
            $registry->singleton( 'hermes_gateway', static fn (): HermesGateway => new HermesGateway(
                Application::service( 'hermes_repository' ),
                Application::service( 'hermes_reasoner' ),
                Application::service( 'hermes_action_preview' ),
                Application::service( 'hermes_tool_registry' ),
                Application::service( 'hermes_diagnostic_engine' ),
                Application::service( 'hermes_mission_engine' ),
                Application::service( 'hermes_knowledge' ),
                Application::service( 'hermes_memory_store' ),
                Application::service( 'hermes_audit_logger' ),
                Application::service( 'hermes_operational_engine' ),
                Application::service( 'hermes_conversation_state' ),
                Application::service( 'hermes_operations_registry' ),
                Application::service( 'hermes_approval_engine' ),
                Application::service( 'hermes_action_executor' ),
                Application::service( 'hermes_workflow_continuation' ),
                Application::service( 'hermes_pending_workflow' ),
                Application::service( 'hermes_disambiguation' )
            ) );
        }
        if ( ! $registry->has( 'hermes_worker_manager' ) ) {
            $registry->singleton( 'hermes_worker_manager', static fn (): HermesWorkerManager => new HermesWorkerManager(
                Application::service( 'hermes_gateway' )
            ) );
        }

        // Chunk 2: entity registry + capability map
        if ( ! $registry->has( 'hermes_entity_registry' ) ) {
            $registry->singleton( 'hermes_entity_registry', static fn (): EntityRegistryBuilder => new EntityRegistryBuilder() );
        }
        if ( ! $registry->has( 'hermes_data_capability' ) ) {
            $registry->singleton( 'hermes_data_capability', static fn (): DataCapabilityBuilder => new DataCapabilityBuilder(
                Application::service( 'hermes_entity_registry' )
            ) );
        }

        // Chunk 3: safety governor + query builder
        if ( ! $registry->has( 'hermes_safety_governor' ) ) {
            $registry->singleton( 'hermes_safety_governor', static fn (): HermesSafetyGovernor => new HermesSafetyGovernor(
                Application::service( 'hermes_audit_logger' )
            ) );
        }
        if ( ! $registry->has( 'hermes_query_builder' ) ) {
            $registry->singleton( 'hermes_query_builder', static fn (): HermesQueryBuilder => new HermesQueryBuilder(
                Application::service( 'hermes_entity_registry' ),
                Application::service( 'hermes_data_capability' ),
                Application::service( 'hermes_safety_governor' ),
                Application::service( 'hermes_permission_validator' ),
                Application::service( 'hermes_audit_logger' )
            ) );
        }

        // Chunk 4: reporting service
        if ( ! $registry->has( 'hermes_reporting' ) ) {
            $registry->singleton( 'hermes_reporting', static fn (): HermesReportingService => new HermesReportingService(
                Application::service( 'hermes_query_builder' ),
                Application::service( 'hermes_entity_registry' ),
                Application::service( 'hermes_data_capability' ),
                Application::service( 'hermes_safety_governor' ),
                Application::service( 'hermes_audit_logger' )
            ) );
        }
    }
}
