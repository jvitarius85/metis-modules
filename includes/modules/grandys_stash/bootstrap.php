<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __DIR__, 2 ) . '/core/autoload.php';

\Metis\Modules\GrandyStashModule::boot();

function metis_grandys_stash_can_view(): bool { return \Metis\Modules\GrandyStashModule::canView(); }
function metis_grandys_stash_can_manage(): bool { return \Metis\Modules\GrandyStashModule::canManage(); }
function metis_grandys_stash_base_url(): string { return \Metis\Modules\GrandyStashModule::baseUrl(); }
