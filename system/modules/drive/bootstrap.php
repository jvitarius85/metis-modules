<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Drive\DriveModule::boot();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/sync_utils.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/folders.php';
require_once __DIR__ . '/includes/sync.php';
