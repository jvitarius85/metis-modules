<?php
declare(strict_types=1);

namespace Metis\Modules\Testimonies;

final class TestimoniesModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );
    }

    public static function ensureSchema(): void {
        SchemaManager::ensureSchema();
    }

    public static function ensureRuntimeSchema(): void {
        if ( \function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'testimonies_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        self::ensureSchema();
    }

    public static function canView(): bool {
        return Support::canView();
    }

    public static function canManage(): bool {
        return Support::canManage();
    }

    public static function canDelete(): bool {
        return Support::canDelete();
    }

    public static function baseUrl(): string {
        return Support::baseUrl();
    }
}
