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

    public static function can(string $action): bool {
        $action = strtolower(trim($action));
        if ($action === '') {
            return false;
        }

        if ($action === 'view') {
            return self::canView();
        }

        if (function_exists('metis_people_can') && \metis_people_can('finance', $action)) {
            return true;
        }

        return $action === 'export' ? self::canView() : self::canManage();
    }

    public static function canExport(): bool {
        return self::can('export');
    }
}
