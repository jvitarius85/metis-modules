<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class Access {
    public static function canView(): bool {
        if ( function_exists( 'metis_people_can' ) ) {
            return \metis_people_can( 'board', 'view' );
        }

        return \metis_user_logged_in();
    }

    public static function canManage(): bool {
        if ( function_exists( 'metis_people_can' ) ) {
            return \metis_people_can( 'board', 'edit' ) || \metis_people_can( 'board', 'create' );
        }

        return \metis_current_user_can( 'manage_options' );
    }
}
