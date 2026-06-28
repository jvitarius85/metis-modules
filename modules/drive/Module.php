<?php
declare(strict_types=1);

namespace Metis\Modules\Drive;

final class DriveModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Drive bootstrap loaded' );
        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );
    }

    public static function ensureSchema(): void {
        if ( function_exists( 'metis_drive_ensure_schema' ) ) {
            \metis_drive_ensure_schema();
        }
    }

    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'drive_schema',
                [ __FILE__, __DIR__ . '/includes/schema.php' ],
                static function (): void {
                    self::ensureSchema();
                }
            );
            return;
        }

        self::ensureSchema();
    }
}
