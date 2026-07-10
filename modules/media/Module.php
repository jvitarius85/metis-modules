<?php
declare(strict_types=1);

namespace Metis\Modules\Media;

final class MediaModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
    }

    public static function canView(): bool {
        return \Metis\Modules\Media\Policies\MediaPolicy::canView();
    }

    public static function canManage(): bool {
        return \Metis\Modules\Media\Policies\MediaPolicy::canManage();
    }

    public static function baseUrl(): string {
        if ( function_exists( 'metis_portal_url' ) ) {
            return (string) metis_portal_url( 'media', 'library' );
        }

        if ( function_exists( 'metis_admin_url' ) ) {
            return (string) metis_admin_url( 'media/library' );
        }

        return '/media/library/';
    }
}
