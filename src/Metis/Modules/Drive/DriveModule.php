<?php
declare(strict_types=1);

namespace Metis\Modules\Drive;

final class DriveModule extends \Metis\Modules\LegacyModule {
    protected static function bootModule(): void {
        require_once METIS_PATH . 'includes/modules/drive/legacy.php';
    }
}
