<?php
declare(strict_types=1);

namespace Metis\Modules\Resources\Policies;

use Metis\Modules\Resources\Support;

final class ResourcesPolicy {
    public static function canView(): bool {
        return Support::canView();
    }

    public static function canManage(): bool {
        return Support::canManage();
    }

    public static function canDelete(): bool {
        return Support::canDelete();
    }
}
