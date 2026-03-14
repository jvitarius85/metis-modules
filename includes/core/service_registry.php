<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'METIS_STANDALONE' ) ) {
    define( 'METIS_STANDALONE', true );
}

require_once __DIR__ . '/autoload.php';

function metis_service_registry(): \Metis\Core\ServiceRegistry {
    return \Metis\Core\Application::registry();
}

function metis_service( string $name ): mixed {
    return \Metis\Core\Application::service( $name );
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

    if ( ! $registry->has( 'permissions' ) ) {
        $registry->singleton( 'permissions', static fn (): \Metis\Services\PermissionsService => new \Metis\Services\PermissionsService() );
    }

    if ( ! $registry->has( 'router' ) ) {
        $registry->singleton( 'router', static fn (): \Metis\Core\RouterService => new \Metis\Core\RouterService() );
    }

    if ( function_exists( 'metis_build_http_router' ) ) {
        \Metis\Core\Application::service( 'router' )->set_builder( 'metis_build_http_router' );
    }

    if ( ! $registry->has( 'security' ) && function_exists( 'metis_security_enclave' ) ) {
        $registry->singleton( 'security', static fn (): \Metis_Security_Enclave => \metis_security_enclave() );
    }
}
