<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Hermes\HermesModule::boot();

function metis_hermes_can_view(): bool { return \Metis\Modules\Hermes\Access::canView(); }
function metis_hermes_can_manage(): bool { return \Metis\Modules\Hermes\Access::canManage(); }
function metis_hermes_base_url(): string { return \Metis\Modules\Hermes\Support::baseUrl(); }
function metis_hermes_ensure_schema(): void { \Metis\Modules\Hermes\SchemaManager::ensureSchema(); }
function metis_hermes_gateway(): \Metis\Hermes\HermesGateway { return \Metis\Core\Application::service( 'hermes_gateway' ); }
function metis_hermes_dashboard_payload(): array { return metis_hermes_gateway()->dashboardPayload(); }
