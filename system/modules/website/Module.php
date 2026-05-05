<?php
declare(strict_types=1);

use Metis\Modules\Website\WebsiteModule;

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( class_exists( WebsiteModule::class ) ) {
    WebsiteModule::boot();
}
