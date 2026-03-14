<?php
declare(strict_types=1);

namespace Metis\Modules\Website;

final class WebsiteModule extends \Metis\Modules\LegacyModule {
    protected static function bootModule(): void {
        \Metis_Logger::info( 'Website bootstrap loaded' );
    }
}
