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
            metis_on( 'init', [ SchemaManager::class, 'ensureSchema' ], 6 );
        } else {
            SchemaManager::ensureSchema();
        }
    }
}
