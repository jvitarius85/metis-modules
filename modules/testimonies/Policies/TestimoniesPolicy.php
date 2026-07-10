<?php
declare(strict_types=1);

namespace Metis\Modules\Testimonies\Policies;

use Metis\Modules\Testimonies\Support;

final class TestimoniesPolicy {
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
