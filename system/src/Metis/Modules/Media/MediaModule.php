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
        return function_exists( 'metis_security_user_can' ) && metis_security_user_can( 'media.view' );
    }

    public static function canManage(): bool {
        return function_exists( 'metis_security_user_can' ) && metis_security_user_can( 'media.edit' );
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
