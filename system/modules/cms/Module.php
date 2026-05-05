<?php
declare(strict_types=1);

use Metis\Modules\Cms\CmsModule;

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( class_exists( CmsModule::class ) ) {
    CmsModule::boot();
}
