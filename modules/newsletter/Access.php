<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class Access {
    public static function canView(): bool {
        if ( function_exists( 'metis_people_can' ) ) {
            return \metis_people_can( 'newsletter', 'view' );
        }

        return \metis_user_logged_in();
    }

    public static function canManage(): bool {
        if ( function_exists( 'metis_people_can' ) ) {
            return \metis_people_can( 'newsletter', 'edit' )
                || \metis_people_can( 'newsletter', 'create' )
                || \metis_people_can( 'newsletter', 'delete' );
        }

        return \metis_current_user_can( 'manage_options' );
    }
}
