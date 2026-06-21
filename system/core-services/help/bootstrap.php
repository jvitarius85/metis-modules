<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once dirname( __DIR__, 2 ) . '/src/Metis/Modules/Help/HelpModule.php';

\Metis\Modules\Help\HelpModule::boot();
