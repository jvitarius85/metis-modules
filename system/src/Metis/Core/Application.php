<?php
declare(strict_types=1);

namespace Metis\Core;

final class Application {
    private static ?ServiceRegistry $registry = null;

    public static function set_registry( ServiceRegistry $registry ): ServiceRegistry {
        self::$registry = $registry;
        return self::$registry;
    }

    public static function registry(): ServiceRegistry {
        if ( ! self::$registry instanceof ServiceRegistry ) {
            self::$registry = new ServiceRegistry();
        }

        return self::$registry;
    }

    public static function singleton( string $name, callable $factory ): void {
        self::registry()->singleton( $name, $factory );
    }

    public static function instance( string $name, mixed $service ): void {
        self::registry()->instance( $name, $service );
    }

    public static function has_service( string $name ): bool {
        return self::registry()->has( $name );
    }

    public static function service( string $name ): mixed {
        return self::registry()->get( $name );
    }
}
