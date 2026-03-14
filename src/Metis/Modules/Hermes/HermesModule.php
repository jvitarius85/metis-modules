<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes;

use Metis\Core\Application;
use Metis\Hermes\HermesActionPlanner;
use Metis\Hermes\HermesActionPreview;
use Metis\Hermes\HermesAuditLogger;
use Metis\Hermes\HermesCommandRegistry;
use Metis\Hermes\HermesContextBuilder;
use Metis\Hermes\HermesContextPackLoader;
use Metis\Hermes\HermesDiagnosticEngine;
use Metis\Hermes\HermesDocumentationIndex;
use Metis\Hermes\HermesExecutionEngine;
use Metis\Hermes\HermesGateway;
use Metis\Hermes\HermesGroundingValidator;
use Metis\Hermes\HermesHelpResolver;
use Metis\Hermes\HermesIntentParser;
use Metis\Hermes\HermesKnowledgeService;
use Metis\Hermes\HermesMemoryStore;
use Metis\Hermes\HermesMissionEngine;
use Metis\Hermes\HermesOperationalEngine;
use Metis\Hermes\HermesPlaybookEngine;
use Metis\Hermes\HermesPermissionValidator;
use Metis\Hermes\HermesReasoner;
use Metis\Hermes\HermesRepository;
use Metis\Hermes\HermesResponseRenderer;
use Metis\Hermes\HermesToolRegistry;
use Metis\Hermes\HermesWalkthroughResolver;
use Metis\Hermes\HermesWorkerManager;

final class HermesModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        self::registerServices();

        \metis_add_action( 'init', [ SchemaManager::class, 'ensureSchema' ], 5 );
        \metis_add_action( 'init', static function (): void {
            Application::service( 'hermes_worker_manager' )->register();
        }, 8 );

        $enclave = \metis_security_enclave();
        if ( ! $enclave->has_policy( 'hermes.action.execute' ) ) {
            $enclave->register_policy( new \Metis_Security_Policy( 'hermes.action.execute', 'hermes', 'edit', true, true, true, 'metis_ajax:metis_hermes_execute_action', 60, 60 ) );
        }

        \Metis_Logger::info( 'Hermes bootstrap loaded' );
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
        if ( ! $registry->has( 'hermes_walkthrough_resolver' ) ) {
            $registry->singleton( 'hermes_walkthrough_resolver', static fn (): HermesWalkthroughResolver => new HermesWalkthroughResolver( Application::service( 'walkthroughs' ) ) );
        }
        if ( ! $registry->has( 'hermes_grounding_validator' ) ) {
            $registry->singleton( 'hermes_grounding_validator', static fn (): HermesGroundingValidator => new HermesGroundingValidator() );
        }
        if ( ! $registry->has( 'hermes_knowledge' ) ) {
            $registry->singleton( 'hermes_knowledge', static fn (): HermesKnowledgeService => new HermesKnowledgeService(
                Application::service( 'hermes_documentation_index' ),
                Application::service( 'hermes_help_resolver' ),
                Application::service( 'hermes_walkthrough_resolver' ),
                Application::service( 'hermes_grounding_validator' )
            ) );
        }
        if ( ! $registry->has( 'hermes_tool_registry' ) ) {
            $registry->singleton( 'hermes_tool_registry', static fn (): HermesToolRegistry => new HermesToolRegistry() );
        }
        if ( ! $registry->has( 'hermes_command_registry' ) ) {
            $registry->singleton( 'hermes_command_registry', static fn (): HermesCommandRegistry => new HermesCommandRegistry() );
        }
        if ( ! $registry->has( 'hermes_intent_parser' ) ) {
            $registry->singleton( 'hermes_intent_parser', static fn (): HermesIntentParser => new HermesIntentParser( Application::service( 'hermes_command_registry' ) ) );
        }
        if ( ! $registry->has( 'hermes_context_pack_loader' ) ) {
            $registry->singleton( 'hermes_context_pack_loader', static fn (): HermesContextPackLoader => new HermesContextPackLoader( Application::service( 'hermes_library' ) ) );
        }
        if ( ! $registry->has( 'hermes_permission_validator' ) ) {
            $registry->singleton( 'hermes_permission_validator', static fn (): HermesPermissionValidator => new HermesPermissionValidator( Application::service( 'permissions' ) ) );
        }
        if ( ! $registry->has( 'hermes_execution_engine' ) ) {
            $registry->singleton( 'hermes_execution_engine', static fn (): HermesExecutionEngine => new HermesExecutionEngine() );
        }
        if ( ! $registry->has( 'hermes_response_renderer' ) ) {
            $registry->singleton( 'hermes_response_renderer', static fn (): HermesResponseRenderer => new HermesResponseRenderer() );
        }
        if ( ! $registry->has( 'hermes_operational_engine' ) ) {
            $registry->singleton( 'hermes_operational_engine', static fn (): HermesOperationalEngine => new HermesOperationalEngine(
                Application::service( 'hermes_intent_parser' ),
                Application::service( 'hermes_context_pack_loader' ),
                Application::service( 'hermes_command_registry' ),
                Application::service( 'hermes_permission_validator' ),
                Application::service( 'hermes_execution_engine' ),
                Application::service( 'hermes_response_renderer' )
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
        if ( ! $registry->has( 'hermes_playbook_engine' ) ) {
            $registry->singleton( 'hermes_playbook_engine', static fn (): HermesPlaybookEngine => new HermesPlaybookEngine( Application::service( 'hermes_library' ) ) );
        }
        if ( ! $registry->has( 'hermes_mission_engine' ) ) {
            $registry->singleton( 'hermes_mission_engine', static fn (): HermesMissionEngine => new HermesMissionEngine( Application::service( 'hermes_library' ) ) );
        }
        if ( ! $registry->has( 'hermes_action_preview' ) ) {
            $registry->singleton( 'hermes_action_preview', static fn (): HermesActionPreview => new HermesActionPreview() );
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
                Application::service( 'hermes_operational_engine' )
            ) );
        }
        if ( ! $registry->has( 'hermes_worker_manager' ) ) {
            $registry->singleton( 'hermes_worker_manager', static fn (): HermesWorkerManager => new HermesWorkerManager(
                Application::service( 'hermes_gateway' )
            ) );
        }
    }
}
