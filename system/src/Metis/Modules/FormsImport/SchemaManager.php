<?php
declare(strict_types=1);

namespace Metis\Modules\FormsImport;

final class SchemaManager {
    private static bool $ready = false;

    public static function ensureSchema(): void {
        if ( self::$ready ) {
            return;
        }

        if ( ! class_exists( '\\Metis_Tables' ) ) {
            return;
        }

        if ( class_exists( '\\Metis\\Modules\\Forms\\SchemaManager' ) ) {
            \Metis\Modules\Forms\SchemaManager::ensureSchema();
        }

        self::$ready = true;
    }
}
