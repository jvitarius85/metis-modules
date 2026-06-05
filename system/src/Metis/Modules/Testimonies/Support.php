<?php
declare(strict_types=1);

namespace Metis\Modules\Testimonies;

final class Support {
    public static function canView(): bool {
        return \function_exists( 'metis_security_user_can' ) && \metis_security_user_can( 'testimonies.view' );
    }

    public static function canManage(): bool {
        return \function_exists( 'metis_security_user_can' ) && \metis_security_user_can( 'testimonies.edit' );
    }

    public static function canDelete(): bool {
        return \function_exists( 'metis_security_user_can' ) && \metis_security_user_can( 'testimonies.delete' );
    }

    public static function baseUrl(): string {
        if ( \function_exists( 'metis_portal_url' ) ) {
            return (string) \metis_portal_url( 'testimonies' );
        }

        return \function_exists( 'metis_admin_url' ) ? (string) \metis_admin_url( 'admin.php?page=testimonies' ) : '/testimonies/';
    }
}
