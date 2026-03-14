<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'METIS_STANDALONE' ) ) {
    define( 'METIS_STANDALONE', true );
}

require_once __DIR__ . '/autoload.php';
require_once dirname( __DIR__, 2 ) . '/core/services/help.service.php';
require_once dirname( __DIR__, 2 ) . '/core/services/walkthrough.service.php';

function metis_service_registry(): \Metis\Core\ServiceRegistry {
    return \Metis\Core\Application::registry();
}

function metis_service( string $name ): mixed {
    return \Metis\Core\Application::service( $name );
}

function metis_job_queue(): \Metis\Core\JobQueue {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'jobs' );
}

function metis_job_workers(): \Metis\Core\JobWorkerRegistry {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'job_workers' );
}

function metis_event_bus(): \Metis\Core\EventBus {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'events' );
}

function metis_subscribe_event( string $event, callable $listener, int $priority = 10 ): void {
    metis_event_bus()->subscribe( $event, $listener, $priority );
}

function metis_publish_event( string $event, array $payload = [], array $context = [] ): \Metis\Core\Event {
    return metis_event_bus()->publish( $event, $payload, $context );
}

function metis_register_core_services(): void {
    $registry = \Metis\Core\Application::registry();

    if ( ! $registry->has( 'settings' ) && class_exists( 'Core_Settings_Service' ) ) {
        $registry->singleton( 'settings', static fn (): \Metis\Services\SettingsServiceAdapter => new \Metis\Services\SettingsServiceAdapter() );
    }

    if ( ! $registry->has( 'logger' ) && class_exists( 'Metis_Logger' ) ) {
        $registry->singleton( 'logger', static fn (): \Metis\Services\LoggerService => new \Metis\Services\LoggerService() );
    }

    if ( ! $registry->has( 'db' ) && class_exists( 'Metis_Tables' ) ) {
        $registry->singleton( 'db', static fn (): \Metis\Services\DatabaseService => new \Metis\Services\DatabaseService() );
        $registry->singleton( 'tables', static fn (): \Metis\Services\DatabaseService => \Metis\Core\Application::service( 'db' ) );
    }

    if ( ! $registry->has( 'auth' ) && function_exists( 'metis_auth_ensure_schema' ) ) {
        $registry->singleton( 'auth', static fn (): \Metis\Services\AuthService => new \Metis\Services\AuthService() );
    }

    if ( ! $registry->has( 'modules' ) ) {
        $registry->singleton( 'modules', static fn (): \Metis\Core\ModuleLoader => new \Metis\Core\ModuleLoader() );
        $registry->singleton( 'module_loader', static fn (): \Metis\Core\ModuleLoader => \Metis\Core\Application::service( 'modules' ) );
    }

    if ( ! $registry->has( 'events' ) ) {
        $registry->singleton( 'events', static fn (): \Metis\Core\EventBus => new \Metis\Core\EventBus() );
        $registry->singleton( 'event_bus', static fn (): \Metis\Core\EventBus => \Metis\Core\Application::service( 'events' ) );
    }

    if ( ! $registry->has( 'job_workers' ) ) {
        $registry->singleton( 'job_workers', static fn (): \Metis\Core\JobWorkerRegistry => new \Metis\Core\JobWorkerRegistry() );
    }

    if ( ! $registry->has( 'jobs' ) ) {
        $registry->singleton(
            'jobs',
            static fn (): \Metis\Core\JobQueue => new \Metis\Core\JobQueue( \Metis\Core\Application::service( 'job_workers' ) )
        );
        $registry->singleton( 'job_queue', static fn (): \Metis\Core\JobQueue => \Metis\Core\Application::service( 'jobs' ) );
    }

    if ( ! $registry->has( 'permissions' ) ) {
        $registry->singleton( 'permissions', static fn (): \Metis\Services\PermissionsService => new \Metis\Services\PermissionsService() );
    }

    if ( ! $registry->has( 'communications' ) ) {
        $registry->singleton( 'communications', static fn (): \Metis\Services\CommunicationsService => new \Metis\Services\CommunicationsService() );
    }

    if ( ! $registry->has( 'security_diagnostics' ) ) {
        $registry->singleton(
            'security_diagnostics',
            static fn (): \Metis\Services\SecurityDiagnosticsService => new \Metis\Services\SecurityDiagnosticsService( \Metis\Core\Application::service( 'permissions' ) )
        );
    }

    if ( ! $registry->has( 'hermes_library' ) ) {
        $registry->singleton( 'hermes_library', static fn (): \Metis\Services\HermesDefinitionLibrary => new \Metis\Services\HermesDefinitionLibrary() );
    }

    if ( ! $registry->has( 'backup' ) ) {
        $registry->singleton( 'backup', static fn (): \Metis\Backup\BackupService => new \Metis\Backup\BackupService() );
    }

    if ( ! $registry->has( 'release' ) ) {
        $registry->singleton( 'release', static fn (): \Metis\Release\ReleaseManager => new \Metis\Release\ReleaseManager() );
    }

    if ( ! $registry->has( 'router' ) ) {
        $registry->singleton( 'router', static fn (): \Metis\Core\RouterService => new \Metis\Core\RouterService() );
    }

    if ( ! $registry->has( 'help' ) && class_exists( 'Metis_Help_Service' ) ) {
        $registry->singleton( 'help', static fn (): \Metis_Help_Service => new \Metis_Help_Service() );
    }

    if ( ! $registry->has( 'walkthroughs' ) && class_exists( 'Metis_Walkthrough_Service' ) ) {
        $registry->singleton(
            'walkthroughs',
            static fn (): \Metis_Walkthrough_Service => new \Metis_Walkthrough_Service( \Metis\Core\Application::service( 'help' ) )
        );
        $registry->singleton( 'walkthrough', static fn (): \Metis_Walkthrough_Service => \Metis\Core\Application::service( 'walkthroughs' ) );
    }

    if ( function_exists( 'metis_build_http_router' ) ) {
        \Metis\Core\Application::service( 'router' )->set_builder( 'metis_build_http_router' );
    }

    if ( ! $registry->has( 'security' ) && function_exists( 'metis_security_enclave' ) ) {
        $registry->singleton( 'security', static fn (): \Metis_Security_Enclave => \metis_security_enclave() );
    }
}
