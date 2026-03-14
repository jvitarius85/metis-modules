<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __DIR__, 2 ) . '/core/autoload.php';

\Metis\Modules\Forms\FormsModule::boot();

function metis_forms_can_view(): bool { return \Metis\Modules\Forms\FormsModule::canView(); }
function metis_forms_can_manage(): bool { return \Metis\Modules\Forms\FormsModule::canManage(); }
function metis_forms_can_delete(): bool { return \Metis\Modules\Forms\FormsModule::canDelete(); }
function metis_forms_base_url(): string { return \Metis\Modules\Forms\FormsModule::baseUrl(); }
function metis_forms_public_url( string $slug = '' ): string { return \Metis\Modules\Forms\FormsModule::publicUrl( $slug ); }
function metis_forms_detail_url( int $form_id = 0 ): string { return \Metis\Modules\Forms\FormsModule::detailUrl( $form_id ); }
function metis_forms_build_url( int $form_id = 0 ): string { return \Metis\Modules\Forms\FormsModule::buildUrl( $form_id ); }
function metis_forms_entries_url( int $form_id = 0 ): string { return \Metis\Modules\Forms\FormsModule::entriesUrl( $form_id ); }
function metis_forms_settings_url( int $form_id = 0 ): string { return \Metis\Modules\Forms\FormsModule::settingsUrl( $form_id ); }
function metis_forms_ensure_schema(): void { \Metis\Modules\Forms\FormsModule::ensureSchema(); }
function metis_forms_handle_public_route( Metis_Http_Request $request ): Metis_Http_Response { return \Metis\Modules\Forms\FormsModule::handlePublicRoute( $request ); }
