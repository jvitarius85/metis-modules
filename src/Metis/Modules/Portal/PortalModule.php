<?php
declare(strict_types=1);

namespace Metis\Modules\Portal;

final class PortalModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Portal bootstrap loaded' );
    }
}
