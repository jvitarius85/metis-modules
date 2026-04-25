<?php
declare(strict_types=1);

namespace Metis\Modules\Import;

use Metis\Core\Application;

/**
 * Import Module
 * 
 * Handles WXR XML and Beaver Builder imports.
 * Provides preview and correction workflow.
 */
final class ImportModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );

        \Metis_Logger::info( 'Import module booted' );
    }

    /**
     * Check if user can view import module
     */
    public static function canView(): bool {
        if ( ! function_exists( 'metis_security_user_can' ) ) {
            return false;
        }

        return \metis_security_user_can( 'import.view' );
    }

    /**
     * Check if user can execute imports
     */
    public static function canExecute(): bool {
        if ( ! function_exists( 'metis_security_user_can' ) ) {
            return false;
        }

        return \metis_security_user_can( 'import.execute' );
    }

    /**
     * Get database service
     */
    public static function db(): object {
        return Application::service( 'db' );
    }

    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'import_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        SchemaManager::ensureSchema();
    }
}
