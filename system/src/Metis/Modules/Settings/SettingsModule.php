<?php
declare(strict_types=1);

namespace Metis\Modules\Settings;

final class SettingsModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Settings bootstrap loaded' );
    }
}
