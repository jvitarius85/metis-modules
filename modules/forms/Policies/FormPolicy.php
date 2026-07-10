<?php
declare(strict_types=1);

namespace Metis\Modules\Forms\Policies;

use Metis\Modules\Forms\Support;

final class FormPolicy {
    public static function canView(): bool {
        return Support::canView();
    }

    public static function canManage(): bool {
        return Support::canManage();
    }

    public static function canDelete(): bool {
        return Support::canDelete();
    }

    public static function canAction( string $action ): bool {
        $action = \metis_key_clean( $action );
        if ( $action === '' ) {
            return false;
        }

        if ( function_exists( 'metis_security_user_can' ) ) {
            return \metis_security_user_can( 'forms.' . $action );
        }

        return $action === 'view' ? self::canView() : self::canManage();
    }
}
