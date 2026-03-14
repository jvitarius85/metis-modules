<?php
declare(strict_types=1);

namespace Metis\Modules;

final class GrandyStashModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \metis_add_action( 'init', [ self::class, 'ensureReady' ], 6 );
    }

    public static function ensureReady(): void {
        GrandyStashRepository::ensureModuleReady();
    }

    public static function canView(): bool {
        return GrandyStashSupport::canView();
    }

    public static function canManage(): bool {
        return GrandyStashSupport::canManage();
    }

    public static function baseUrl(): string {
        return GrandyStashSupport::baseUrl();
    }
}
