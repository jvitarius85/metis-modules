<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class Access {
    public static function canView(): bool {
        if ( function_exists( 'metis_people_can' ) ) {
            return \metis_people_can( 'finance', 'view' );
        }

        return \metis_user_logged_in();
    }

    public static function canManage(): bool {
        if ( ! self::canView() ) {
            return false;
        }

        $hasFinancePermission = false;
        if ( function_exists( 'metis_people_can' ) ) {
            $hasFinancePermission = \metis_people_can( 'finance', 'edit' )
                || \metis_people_can( 'finance', 'create' )
                || \metis_people_can( 'finance', 'delete' );
        }

        if ( ModeService::currentMode() === ModeService::MODE_FINANCE ) {
            return ModeService::currentUserHasFinanceRole() || $hasFinancePermission;
        }

        return $hasFinancePermission;
    }
}
