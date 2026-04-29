<?php
declare(strict_types=1);

namespace Metis\Modules\Cms;

use Metis\Core\Application;

/**
 * CMS Module
 * 
 * Manages cms pages, posts, menus, popups, and themes.
 * Provides visual builder and import capabilities.
 */
final class CmsModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );

        // Boot block registry (no DB dependency)
        BlockRegistry::boot();

        \Metis_Logger::info( 'CMS module booted' );
    }

    /**
     * Check if user can view CMS module
     */
    public static function canView(): bool {
        if ( ! function_exists( 'metis_security_user_can' ) ) {
            return false;
        }

        return \metis_security_user_can( 'cms.view' );
    }

    /**
     * Check if user can manage CMS content
     */
    public static function canManage(): bool {
        if ( ! function_exists( 'metis_security_user_can' ) ) {
            return false;
        }

        return \metis_security_user_can( 'cms.edit' );
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
                'cms_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        SchemaManager::ensureSchema();
    }

    /**
     * Get base URL for CMS module
     */
    public static function baseUrl(): string {
        if ( function_exists( 'metis_portal_url' ) ) {
            return (string) metis_portal_url( 'cms' );
        }

        return function_exists( 'metis_admin_url' ) ? (string) metis_admin_url( 'admin.php?page=cms' ) : '/cms/';
    }
}
