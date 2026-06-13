<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Resources\ResourcesModule::boot();

function metis_resources_can_view(): bool { return \Metis\Modules\Resources\ResourcesModule::canView(); }
function metis_resources_can_manage(): bool { return \Metis\Modules\Resources\ResourcesModule::canManage(); }
function metis_resources_can_delete(): bool { return \Metis\Modules\Resources\ResourcesModule::canDelete(); }
function metis_resources_base_url(): string { return \Metis\Modules\Resources\ResourcesModule::baseUrl(); }
function metis_resources_ensure_schema(): void { \Metis\Modules\Resources\ResourcesModule::ensureSchema(); }

require_once __DIR__ . '/assets/resources.ajax.php';
