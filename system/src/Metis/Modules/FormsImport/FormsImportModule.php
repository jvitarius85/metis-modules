<?php
declare(strict_types=1);

namespace Metis\Modules\FormsImport;

final class FormsImportModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        if ( class_exists( Module::class ) ) {
            Module::boot();
        }

        if ( function_exists( 'metis_on' ) ) {
            metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 6 );
        } else {
            SchemaManager::ensureSchema();
        }
    }

    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'forms_import_schema',
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
