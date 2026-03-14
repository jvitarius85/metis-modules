<?php
declare(strict_types=1);

namespace Metis\Modules\Settings;

final class SettingsModule extends \Metis\Modules\LegacyModule {
    protected static function bootModule(): void {
        \Metis_Logger::info( 'Settings bootstrap loaded' );
    }
}
