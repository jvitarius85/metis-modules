<?php
declare(strict_types=1);

namespace Metis\Modules\Portal;

final class PortalModule extends \Metis\Modules\LegacyModule {
    protected static function bootModule(): void {
        \Metis_Logger::info( 'Portal bootstrap loaded' );
    }
}
