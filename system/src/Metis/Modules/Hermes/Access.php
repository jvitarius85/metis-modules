<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes;

final class Access {
    public static function canView(): bool {
        static $allowed = null;
        if ( is_bool( $allowed ) ) {
            return $allowed;
        }

        if ( \function_exists( 'metis_people_can' ) ) {
            $allowed = \metis_people_can( 'hermes', 'view' );
            return $allowed;
        }

        $allowed = \metis_user_logged_in();
        return $allowed;
    }

    public static function canManage(): bool {
        static $allowed = null;
        if ( is_bool( $allowed ) ) {
            return $allowed;
        }

        if ( \function_exists( 'metis_people_can' ) ) {
            $allowed = \metis_people_can( 'hermes', 'edit' ) || \metis_people_can( 'hermes', 'create' );
            return $allowed;
        }

        $allowed = \metis_current_user_can( 'manage_options' );
        return $allowed;
    }
}
