<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    define( 'METIS_STANDALONE', true );
}

require_once __DIR__ . '/Autoload.php';
require_once __DIR__ . '/HelpService.php';
require_once __DIR__ . '/WalkthroughService.php';

function metis_service_registry(): \Metis\Core\ServiceRegistry {
    return \Metis\Core\Application::registry();
}

function metis_service( string $name ): mixed {
    return \Metis\Core\Application::service( $name );
}

function metis_error_kernel(): \Metis\Core\Error\ErrorKernel {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'error_kernel' );
}

function metis_failure_isolation(): \Metis\Core\Error\FailureIsolation {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'failure_isolation' );
}

function metis_db(): \Metis\Services\DatabaseService {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'db' );
}

function metis_resolve_db_service(): \Metis\Services\DatabaseService {
    if ( function_exists( 'metis_db' ) ) {
        return metis_db();
    }

    if ( class_exists( \Metis\Core\Application::class ) ) {
        return \Metis\Core\Application::service( 'db' );
    }

    return new \Metis\Services\DatabaseService();
}

function metis_job_queue(): \Metis\Core\Jobs\JobQueue {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'jobs' );
}

function metis_job_workers(): \Metis\Core\Jobs\JobWorkerRegistry {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'job_workers' );
}

function metis_event_bus(): \Metis\Core\Events\EventBus {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'events' );
}

function metis_operations(): \Metis\Core\Services\OperationsService {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'operations' );
}

function metis_entity_id_service(): \Metis\Core\EntityId {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'entity_ids' );
}

function metis_entity_resolver(): \Metis\Core\EntityResolver {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'entity_resolver' );
}

function metis_entity_resolution_service(): \Metis\Core\Services\EntityResolverService {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'entity_resolver_service' );
}

function metis_navigation_service(): \Metis\Core\Services\NavigationService {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'navigation' );
}

function metis_quick_actions_service(): \Metis\Core\Services\QuickActionsRegistryService {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'quick_actions' );
}

function metis_subscribe_event( string $event, callable $listener, int $priority = 10 ): void {
    metis_event_bus()->subscribe( $event, $listener, $priority );
}

function metis_publish_event( string $event, array $payload = [], array $context = [] ): \Metis\Core\Events\Event {
    return metis_event_bus()->publish( $event, $payload, $context );
}

function metis_register_core_services(): void {
    $registry = \Metis\Core\Application::registry();

    if ( ! $registry->has( 'file_cache' ) ) {
        $registry->singleton( 'file_cache', static fn (): \Metis\Core\Cache\FileCache => new \Metis\Core\Cache\FileCache() );
    }

    if ( ! $registry->has( 'cache' ) ) {
        $registry->singleton(
            'cache',
            static fn (): \Metis\Core\Cache\CacheService => new \Metis\Core\Cache\CacheService(
                \Metis\Core\Application::service( 'file_cache' )
            )
        );
    }

    if ( ! $registry->has( 'files' ) ) {
        $registry->singleton( 'files', static fn (): \Metis\Core\Services\FileService => new \Metis\Core\Services\FileService() );
        $registry->singleton( 'file_service', static fn (): \Metis\Core\Services\FileService => \Metis\Core\Application::service( 'files' ) );
    }

    if ( ! $registry->has( 'config_service' ) ) {
        $registry->singleton(
            'config_service',
            static fn (): \Metis\Core\Services\ConfigService => new \Metis\Core\Services\ConfigService(
                \Metis\Core\Application::service( 'files' )
            )
        );
    }

    if ( ! $registry->has( 'logger_core' ) ) {
        $registry->singleton( 'logger_core', static fn (): \Metis\Core\Services\LoggerService => new \Metis\Core\Services\LoggerService() );
    }

    if ( ! $registry->has( 'runtime_assets' ) ) {
        $registry->singleton( 'runtime_assets', static fn (): \Metis\Core\Services\RuntimeAssetService => new \Metis\Core\Services\RuntimeAssetService() );
    }

    if ( ! $registry->has( 'trace_ids' ) ) {
        $registry->singleton( 'trace_ids', static fn (): \Metis\Core\Error\TraceIdGenerator => new \Metis\Core\Error\TraceIdGenerator() );
    }

    if ( ! $registry->has( 'error_classifier' ) ) {
        $registry->singleton( 'error_classifier', static fn (): \Metis\Core\Error\ErrorClassifier => new \Metis\Core\Error\ErrorClassifier() );
    }

    if ( ! $registry->has( 'error_logger' ) ) {
        $registry->singleton( 'error_logger', static fn (): \Metis\Core\Error\ErrorLogger => new \Metis\Core\Error\ErrorLogger() );
    }

    if ( ! $registry->has( 'error_pages' ) ) {
        $registry->singleton( 'error_pages', static fn (): \Metis\Core\Error\ErrorPageRenderer => new \Metis\Core\Error\ErrorPageRenderer() );
    }

    if ( ! $registry->has( 'fallbacks' ) ) {
        $registry->singleton( 'fallbacks', static fn (): \Metis\Core\Error\FallbackResolver => new \Metis\Core\Error\FallbackResolver() );
    }

    if ( ! $registry->has( 'circuit_breaker' ) ) {
        $registry->singleton( 'circuit_breaker', static fn (): \Metis\Core\Error\CircuitBreaker => new \Metis\Core\Error\CircuitBreaker() );
    }

    if ( ! $registry->has( 'recovery_registry' ) ) {
        $registry->singleton(
            'recovery_registry',
            static function (): \Metis\Core\Error\RecoveryRegistry {
                $registry = new \Metis\Core\Error\RecoveryRegistry();
                $fallbacks = \Metis\Core\Application::service( 'fallbacks' );

                $registry->register( new \Metis\Core\Error\CacheRecoveryStrategy() );
                $registry->register( new \Metis\Core\Error\DatabaseRecoveryStrategy() );
                $registry->register( new \Metis\Core\Error\IntegrationRecoveryStrategy( $fallbacks ) );
                $registry->register( new \Metis\Core\Error\OptionalBoundaryRecoveryStrategy( $fallbacks ) );
                $registry->register( new \Metis\Core\Error\BackgroundJobRecoveryStrategy() );

                return $registry;
            }
        );
    }

    if ( ! $registry->has( 'recovery_manager' ) ) {
        $registry->singleton(
            'recovery_manager',
            static fn (): \Metis\Core\Error\RecoveryManager => new \Metis\Core\Error\RecoveryManager(
                \Metis\Core\Application::service( 'recovery_registry' ),
                \Metis\Core\Application::service( 'error_logger' )
            )
        );
    }

    if ( ! $registry->has( 'error_responder' ) ) {
        $registry->singleton(
            'error_responder',
            static fn (): \Metis\Core\Error\ErrorResponder => new \Metis\Core\Error\ErrorResponder(
                \Metis\Core\Application::service( 'error_pages' )
            )
        );
    }

    if ( ! $registry->has( 'error_kernel' ) ) {
        $registry->singleton(
            'error_kernel',
            static fn (): \Metis\Core\Error\ErrorKernel => new \Metis\Core\Error\ErrorKernel(
                \Metis\Core\Application::service( 'trace_ids' ),
                \Metis\Core\Application::service( 'error_classifier' ),
                \Metis\Core\Application::service( 'error_logger' ),
                \Metis\Core\Application::service( 'recovery_manager' ),
                \Metis\Core\Application::service( 'error_responder' ),
                \Metis\Core\Application::service( 'circuit_breaker' )
            )
        );
    }

    if ( ! $registry->has( 'failure_isolation' ) ) {
        $registry->singleton(
            'failure_isolation',
            static fn (): \Metis\Core\Error\FailureIsolation => new \Metis\Core\Error\FailureIsolation(
                \Metis\Core\Application::service( 'error_kernel' ),
                \Metis\Core\Application::service( 'recovery_manager' ),
                \Metis\Core\Application::service( 'error_logger' )
            )
        );
    }

    if ( ! $registry->has( 'audit_log' ) ) {
        $registry->singleton( 'audit_log', static fn (): \Metis\Core\Services\AuditLogService => new \Metis\Core\Services\AuditLogService() );
    }

    if ( ! $registry->has( 'csrf' ) ) {
        $registry->singleton(
            'csrf',
            static fn (): \Metis\Core\Services\CsrfService => new \Metis\Core\Services\CsrfService(
                \Metis\Core\Application::service( 'audit_log' )
            )
        );
    }

    if ( ! $registry->has( 'session_security' ) ) {
        $registry->singleton( 'session_security', static fn (): \Metis\Core\Services\SessionSecurityService => new \Metis\Core\Services\SessionSecurityService() );
    }

    if ( ! $registry->has( 'password_security' ) ) {
        $registry->singleton(
            'password_security',
            static fn (): \Metis\Core\Auth\PasswordSecurityService => new \Metis\Core\Auth\PasswordSecurityService(
                \Metis\Core\Application::service( 'config_service' ),
                \Metis\Core\Application::service( 'http' ),
                \Metis\Core\Application::service( 'audit_log' )
            )
        );
    }

    if ( ! $registry->has( 'security_audit' ) ) {
        $registry->singleton(
            'security_audit',
            static fn (): \Metis\Core\Security\AuditLogger => new \Metis\Core\Security\AuditLogger(
                \Metis\Core\Application::service( 'audit_log' ),
                \Metis\Core\Application::service( 'logger_core' )
            )
        );
    }

    if ( ! $registry->has( 'security_csrf' ) ) {
        $registry->singleton(
            'security_csrf',
            static fn (): \Metis\Core\Security\CsrfManager => new \Metis\Core\Security\CsrfManager(
                \Metis\Core\Application::service( 'csrf' )
            )
        );
    }

    if ( ! $registry->has( 'security_nonce' ) ) {
        $registry->singleton(
            'security_nonce',
            static fn (): \Metis\Core\Security\NonceManager => new \Metis\Core\Security\NonceManager(
                \Metis\Core\Application::service( 'security_csrf' )
            )
        );
    }

    if ( ! $registry->has( 'security_rate_limiter' ) ) {
        $registry->singleton( 'security_rate_limiter', static fn (): \Metis\Core\Security\RateLimiter => new \Metis\Core\Security\RateLimiter() );
    }

    if ( ! $registry->has( 'security_fingerprint' ) ) {
        $registry->singleton( 'security_fingerprint', static fn (): \Metis\Core\Security\RequestFingerprint => new \Metis\Core\Security\RequestFingerprint() );
    }

    if ( ! $registry->has( 'security_threat_store' ) ) {
        $registry->singleton( 'security_threat_store', static fn (): \Metis\Core\Security\ThreatScoreStore => new \Metis\Core\Security\ThreatScoreStore() );
    }

    if ( ! $registry->has( 'security_threat_engine' ) ) {
        $registry->singleton(
            'security_threat_engine',
            static fn (): \Metis\Core\Security\ThreatScoreEngine => new \Metis\Core\Security\ThreatScoreEngine(
                \Metis\Core\Application::service( 'security_threat_store' )
            )
        );
    }

    if ( ! $registry->has( 'security_behavior' ) ) {
        $registry->singleton( 'security_behavior', static fn (): \Metis\Core\Security\BehaviorProfiler => new \Metis\Core\Security\BehaviorProfiler() );
    }

    if ( ! $registry->has( 'security_kernel' ) ) {
        $registry->singleton(
            'security_kernel',
            static fn (): \Metis\Core\Security\SecurityKernel => new \Metis\Core\Security\SecurityKernel(
                \Metis\Core\Application::service( 'security_nonce' ),
                \Metis\Core\Application::service( 'security_csrf' ),
                \Metis\Core\Application::service( 'security_rate_limiter' ),
                \Metis\Core\Application::service( 'security_fingerprint' ),
                \Metis\Core\Application::service( 'security_threat_engine' ),
                \Metis\Core\Application::service( 'security_threat_store' ),
                \Metis\Core\Application::service( 'security_audit' ),
                \Metis\Core\Application::service( 'security_behavior' ),
                \Metis\Core\Application::service( 'config_service' )
            )
        );
    }

    if ( ! $registry->has( 'auth_protection' ) ) {
        $registry->singleton(
            'auth_protection',
            static fn (): \Metis\Core\Security\AuthProtectionService => new \Metis\Core\Security\AuthProtectionService(
                \Metis\Core\Application::service( 'security_kernel' ),
                \Metis\Core\Application::service( 'config_service' )
            )
        );
    }

    if ( ! $registry->has( 'security_authorization_gate' ) ) {
        $registry->singleton(
            'security_authorization_gate',
            static fn (): \Metis\Core\Security\SecureEnclave\AuthorizationGate => new \Metis\Core\Security\SecureEnclave\AuthorizationGate(
                \Metis\Core\Application::service( 'permissions' ),
                \Metis\Core\Application::service( 'security_fingerprint' )
            )
        );
    }

    if ( ! $registry->has( 'security_pipeline' ) ) {
        $registry->singleton(
            'security_pipeline',
            static fn (): \Metis\Core\Security\Pipeline\SecurityPipeline => new \Metis\Core\Security\Pipeline\SecurityPipeline( [
                new \Metis\Core\Security\Guards\PolicyGuard( \Metis\Core\Application::service( 'security_authorization_gate' ) ),
                new \Metis\Core\Security\Guards\ProgressiveDelayGuard(),
                new \Metis\Core\Security\Guards\NonceGuard( \Metis\Core\Application::service( 'security_kernel' ) ),
                new \Metis\Core\Security\Guards\RateLimitGuard( \Metis\Core\Application::service( 'security_kernel' ) ),
                new \Metis\Core\Security\Guards\BehaviorGuard( \Metis\Core\Application::service( 'security_kernel' ) ),
                new \Metis\Core\Security\Guards\ThreatScoreGuard( \Metis\Core\Application::service( 'security_kernel' ) ),
                new \Metis\Core\Security\Guards\PermissionGuard(
                    \Metis\Core\Application::service( 'security_authorization_gate' ),
                    \Metis\Core\Application::service( 'security_kernel' )
                ),
            ] )
        );
    }

    if ( ! $registry->has( 'secure_enclave' ) ) {
        $registry->singleton(
            'secure_enclave',
            static fn (): \Metis\Core\Security\SecureEnclave\SecureEnclave => new \Metis\Core\Security\SecureEnclave\SecureEnclave(
                \Metis\Core\Application::service( 'security_kernel' ),
                \Metis\Core\Application::service( 'security_pipeline' ),
                \Metis\Core\Application::service( 'security_authorization_gate' )
            )
        );
    }

    if ( ! $registry->has( 'upload_policy' ) ) {
        $registry->singleton( 'upload_policy', static fn (): \Metis\Core\Services\UploadPolicyService => new \Metis\Core\Services\UploadPolicyService() );
    }

    if ( ! $registry->has( 'maintenance' ) ) {
        $registry->singleton(
            'maintenance',
            static fn (): \Metis\Core\Services\MaintenanceService => new \Metis\Core\Services\MaintenanceService(
                \Metis\Core\Application::service( 'csrf' ),
                \Metis\Core\Application::service( 'audit_log' )
            )
        );
    }

    if ( ! $registry->has( 'release_execution' ) ) {
        $registry->singleton(
            'release_execution',
            static fn (): \Metis\Core\Services\ReleaseExecutionService => new \Metis\Core\Services\ReleaseExecutionService(
                \Metis\Core\Application::service( 'audit_log' )
            )
        );
    }

    if ( ! $registry->has( 'http' ) ) {
        $registry->singleton( 'http', static fn (): \Metis\Core\Services\HttpClient => new \Metis\Core\Services\HttpClient() );
        $registry->singleton( 'http_client', static fn (): \Metis\Core\Services\HttpClient => \Metis\Core\Application::service( 'http' ) );
    }

    if ( ! $registry->has( 'github' ) ) {
        $registry->singleton(
            'github',
            static fn (): \Metis\Core\Services\GitHubClient => new \Metis\Core\Services\GitHubClient(
                \Metis\Core\Application::service( 'http' )
            )
        );
    }

    if ( ! $registry->has( 'integrity_service' ) ) {
        $registry->singleton( 'integrity_service', static fn (): \Metis\Core\Services\IntegrityService => new \Metis\Core\Services\IntegrityService() );
    }

    if ( ! $registry->has( 'github_update' ) ) {
        $registry->singleton(
            'github_update',
            static fn (): \Metis\Core\Services\GitHubUpdateService => new \Metis\Core\Services\GitHubUpdateService(
                \Metis\Core\Application::service( 'github' ),
                \Metis\Core\Application::service( 'config_service' ),
                \Metis\Core\Application::service( 'files' ),
                \Metis\Core\Application::service( 'logger_core' )
            )
        );
    }

    // Register the DB abstraction before settings/logging adapters may request it during boot.
    if ( ! $registry->has( 'db' ) ) {
        $registry->singleton( 'db', static fn (): \Metis\Services\DatabaseService => new \Metis\Services\DatabaseService() );
        $registry->singleton( 'tables', static fn (): \Metis\Services\DatabaseService => \Metis\Core\Application::service( 'db' ) );
    }

    if ( ! $registry->has( 'settings' ) && class_exists( 'Core_Settings_Service' ) ) {
        $registry->singleton( 'settings', static fn (): \Metis\Services\SettingsServiceAdapter => new \Metis\Services\SettingsServiceAdapter() );
    }

    if ( ! $registry->has( 'logger' ) && class_exists( 'Metis_Logger' ) ) {
        $registry->singleton( 'logger', static fn (): \Metis\Services\LoggerService => new \Metis\Services\LoggerService() );
    }

    if ( ! $registry->has( 'entity_ids' ) ) {
        $registry->singleton( 'entity_ids', static fn (): \Metis\Core\EntityId => new \Metis\Core\EntityId() );
    }

    if ( ! $registry->has( 'entity_resolver' ) ) {
        $registry->singleton( 'entity_resolver', static fn (): \Metis\Core\EntityResolver => new \Metis\Core\EntityResolver() );
    }

    if ( ! $registry->has( 'entity_resolver_service' ) ) {
        $registry->singleton(
            'entity_resolver_service',
            static fn (): \Metis\Core\Services\EntityResolverService => new \Metis\Core\Services\EntityResolverService()
        );
    }

    if ( ! $registry->has( 'navigation' ) ) {
        $registry->singleton(
            'navigation',
            static fn (): \Metis\Core\Services\NavigationService => new \Metis\Core\Services\NavigationService(
                \Metis\Core\Application::service( 'db' )
            )
        );
    }

    if ( ! $registry->has( 'quick_actions' ) ) {
        $registry->singleton( 'quick_actions', static fn (): \Metis\Core\Services\QuickActionsRegistryService => new \Metis\Core\Services\QuickActionsRegistryService() );
    }

    if ( ! $registry->has( 'auth' ) && function_exists( 'metis_auth_ensure_schema' ) ) {
        $registry->singleton( 'auth', static fn (): \Metis\Services\AuthService => new \Metis\Services\AuthService() );
    }

    if ( ! $registry->has( 'modules' ) ) {
        $registry->singleton( 'modules', static fn (): \Metis\Core\ModuleLoader => new \Metis\Core\ModuleLoader() );
        $registry->singleton( 'module_loader', static fn (): \Metis\Core\ModuleLoader => \Metis\Core\Application::service( 'modules' ) );
    }

    if ( ! $registry->has( 'events' ) ) {
        $registry->singleton( 'events', static fn (): \Metis\Core\Events\EventBus => new \Metis\Core\Events\EventBus() );
        $registry->singleton( 'event_bus', static fn (): \Metis\Core\Events\EventBus => \Metis\Core\Application::service( 'events' ) );
    }

    if ( ! $registry->has( 'job_workers' ) ) {
        $registry->singleton( 'job_workers', static fn (): \Metis\Core\Jobs\JobWorkerRegistry => new \Metis\Core\Jobs\JobWorkerRegistry() );
    }

    if ( ! $registry->has( 'jobs' ) ) {
        $registry->singleton(
            'jobs',
            static fn (): \Metis\Core\Jobs\JobQueue => new \Metis\Core\Jobs\JobQueue( \Metis\Core\Application::service( 'job_workers' ), \Metis\Core\Application::service( 'db' ) )
        );
        $registry->singleton( 'job_queue', static fn (): \Metis\Core\Jobs\JobQueue => \Metis\Core\Application::service( 'jobs' ) );
    }

    if ( ! $registry->has( 'operations' ) ) {
        $registry->singleton(
            'operations',
            static fn (): \Metis\Core\Services\OperationsService => new \Metis\Core\Services\OperationsService(
                \Metis\Core\Application::service( 'db' ),
                \Metis\Core\Application::service( 'jobs' ),
                \Metis\Core\Application::service( 'job_workers' )
            )
        );
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
            static fn (): \Metis\Services\SecurityDiagnosticsService => new \Metis\Services\SecurityDiagnosticsService(
                \Metis\Core\Application::service( 'permissions' ),
                \Metis\Core\Application::service( 'db' )
            )
        );
    }

    if ( ! $registry->has( 'hermes_directory' ) ) {
        $registry->singleton(
            'hermes_directory',
            static fn (): \Metis\Services\HermesDirectoryService => new \Metis\Services\HermesDirectoryService(
                \Metis\Core\Application::service( 'db' )
            )
        );
    }

    if ( ! $registry->has( 'hermes_user_admin' ) ) {
        $registry->singleton(
            'hermes_user_admin',
            static fn (): \Metis\Services\HermesUserAdminService => new \Metis\Services\HermesUserAdminService(
                \Metis\Core\Application::service( 'hermes_directory' ),
                \Metis\Core\Application::service( 'db' )
            )
        );
    }

    if ( ! $registry->has( 'hermes_contact_admin' ) ) {
        $registry->singleton(
            'hermes_contact_admin',
            static fn (): \Metis\Services\HermesContactAdminService => new \Metis\Services\HermesContactAdminService(
                \Metis\Core\Application::service( 'db' ),
                \Metis\Core\Application::service( 'entity_resolver_service' )
            )
        );
    }

    if ( ! $registry->has( 'hermes_website_admin' ) ) {
        $registry->singleton(
            'hermes_website_admin',
            static fn (): \Metis\Services\HermesWebsiteAdminService => new \Metis\Services\HermesWebsiteAdminService()
        );
    }

    if ( ! $registry->has( 'hermes_cms_admin' ) ) {
        $registry->singleton(
            'hermes_cms_admin',
            static fn (): \Metis\Services\HermesCmsAdminService => new \Metis\Services\HermesCmsAdminService()
        );
    }

    if ( ! $registry->has( 'hermes_system_ops' ) ) {
        $registry->singleton(
            'hermes_system_ops',
            static fn (): \Metis\Services\HermesSystemOperationsService => new \Metis\Services\HermesSystemOperationsService()
        );
    }

    if ( ! $registry->has( 'hermes_library' ) ) {
        $registry->singleton( 'hermes_library', static fn (): \Metis\Services\HermesDefinitionLibrary => new \Metis\Services\HermesDefinitionLibrary() );
    }

    if ( ! $registry->has( 'system_version' ) ) {
        $registry->singleton( 'system_version', static fn (): \Metis\Services\SystemVersionService => new \Metis\Services\SystemVersionService() );
    }

    if ( ! $registry->has( 'backup' ) ) {
        $registry->singleton( 'backup', static fn (): \Metis\Backup\BackupManager => new \Metis\Backup\BackupManager() );
        $registry->singleton( 'backup_manager', static fn (): \Metis\Backup\BackupManager => \Metis\Core\Application::service( 'backup' ) );
    }

    if ( ! $registry->has( 'release' ) ) {
        $registry->singleton( 'release', static fn (): \Metis\Release\ReleaseManager => new \Metis\Release\ReleaseManager() );
    }

    if ( ! $registry->has( 'updates' ) ) {
        $registry->singleton(
            'updates',
            static fn (): \Metis\Core\Services\UpdateService => new \Metis\Core\Services\UpdateService(
                \Metis\Core\Application::service( 'github_update' ),
                \Metis\Core\Application::service( 'integrity_service' ),
                \Metis\Core\Application::service( 'files' ),
                \Metis\Core\Application::service( 'logger_core' )
            )
        );
        $registry->singleton( 'update_service', static fn (): \Metis\Core\Services\UpdateService => \Metis\Core\Application::service( 'updates' ) );
    }

    if ( ! $registry->has( 'self_healing' ) ) {
        $registry->singleton(
            'self_healing',
            static fn (): \Metis\Core\Services\SelfHealingService => new \Metis\Core\Services\SelfHealingService(
                \Metis\Core\Application::service( 'integrity_service' ),
                \Metis\Core\Application::service( 'updates' ),
                \Metis\Core\Application::service( 'logger_core' )
            )
        );
    }

    if ( ! $registry->has( 'scheduler' ) ) {
        $registry->singleton( 'scheduler', static fn (): \Metis\Core\Services\SchedulerService => new \Metis\Core\Services\SchedulerService() );
    }

    if ( ! $registry->has( 'passkeys' ) ) {
        $registry->singleton( 'passkeys', static fn (): \Metis\Auth\PasskeyService => new \Metis\Auth\PasskeyService( \Metis\Core\Application::service( 'logger_core' ), \Metis\Core\Application::service( 'files' ), \Metis\Core\Application::service( 'db' ) ) );
    }

    if ( ! $registry->has( 'google_workspace' ) ) {
        $registry->singleton(
            'google_workspace',
            static fn (): \Metis\Auth\GoogleWorkspaceProvider => new \Metis\Auth\GoogleWorkspaceProvider(
                \Metis\Core\Application::service( 'config_service' ),
                \Metis\Core\Application::service( 'http' )
            )
        );
    }

    if ( ! $registry->has( 'auth_sessions' ) ) {
        $registry->singleton(
            'auth_sessions',
            static fn (): \Metis\Auth\AuthSessionManager => new \Metis\Auth\AuthSessionManager(
                \Metis\Core\Application::service( 'auth_protection' ),
                \Metis\Core\Application::service( 'logger_core' ),
                \Metis\Core\Application::service( 'security_kernel' )
            )
        );
    }

    if ( ! $registry->has( 'auth_mfa' ) ) {
        $registry->singleton( 'auth_mfa', static fn (): \Metis\Auth\MfaService => new \Metis\Auth\MfaService( \Metis\Core\Application::service( 'logger_core' ) ) );
    }

    if ( ! $registry->has( 'auth_sso' ) ) {
        $registry->singleton(
            'auth_sso',
            static fn (): \Metis\Auth\SsoService => new \Metis\Auth\SsoService(
                \Metis\Core\Application::service( 'google_workspace' ),
                \Metis\Core\Application::service( 'logger_core' )
            )
        );
    }

    if ( ! $registry->has( 'auth_resolver' ) ) {
        $registry->singleton(
            'auth_resolver',
            static fn (): \Metis\Auth\AuthResolver => new \Metis\Auth\AuthResolver(
                \Metis\Core\Application::service( 'auth_sso' ),
                \Metis\Core\Application::service( 'db' ),
                \Metis\Core\Application::service( 'logger_core' )
            )
        );
    }

    if ( ! $registry->has( 'auth_core' ) ) {
        $registry->singleton(
            'auth_core',
            static fn (): \Metis\Auth\AuthService => new \Metis\Auth\AuthService(
                \Metis\Core\Application::service( 'auth_resolver' ),
                \Metis\Core\Application::service( 'auth_mfa' ),
                \Metis\Core\Application::service( 'passkeys' ),
                \Metis\Core\Application::service( 'auth_sso' ),
                \Metis\Core\Application::service( 'auth_sessions' ),
                \Metis\Core\Application::service( 'auth_protection' ),
                \Metis\Core\Application::service( 'logger_core' ),
                \Metis\Core\Application::service( 'secure_enclave' ),
                \Metis\Core\Application::service( 'security_kernel' )
            )
        );
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

    // hermes_entity_registry, hermes_data_capability, hermes_safety_governor, and
    // hermes_query_builder are registered by HermesModule::boot() alongside all
    // other Hermes services, ensuring correct dependency ordering.
}
