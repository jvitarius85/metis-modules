<?php
declare(strict_types=1);

namespace Metis\Modules\Profile;

final class ProfileModule extends \Metis\Modules\LegacyModule {
    protected static function bootModule(): void {
        \Metis_Logger::info( 'Profile bootstrap loaded' );
    }
}
