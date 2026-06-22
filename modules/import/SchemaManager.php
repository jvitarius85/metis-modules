<?php
declare(strict_types=1);

namespace Metis\Modules\Import;

final class SchemaManager {
    private static bool $ready = false;

    public static function ensureSchema(): void {
        if ( self::$ready ) {
            return;
        }

        if ( ! class_exists( '\\Metis_Tables' ) ) {
            return;
        }

        self::$ready = true;
    }
}
