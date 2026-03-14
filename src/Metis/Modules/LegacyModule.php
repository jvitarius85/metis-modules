<?php
declare(strict_types=1);

namespace Metis\Modules;

abstract class LegacyModule {
    private static array $booted = [];

    final public static function boot(): void {
        $class = static::class;
        if ( ! empty( self::$booted[ $class ] ) ) {
            return;
        }

        self::$booted[ $class ] = true;
        static::bootModule();
    }

    abstract protected static function bootModule(): void;
}
