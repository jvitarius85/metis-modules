<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Testimonies\TestimoniesModule::boot();

function metis_testimonies_can_view(): bool { return \Metis\Modules\Testimonies\TestimoniesModule::canView(); }
function metis_testimonies_can_manage(): bool { return \Metis\Modules\Testimonies\TestimoniesModule::canManage(); }
function metis_testimonies_can_delete(): bool { return \Metis\Modules\Testimonies\TestimoniesModule::canDelete(); }
function metis_testimonies_base_url(): string { return \Metis\Modules\Testimonies\TestimoniesModule::baseUrl(); }
function metis_testimonies_ensure_schema(): void { \Metis\Modules\Testimonies\TestimoniesModule::ensureSchema(); }

require_once __DIR__ . '/assets/testimonies.ajax.php';
