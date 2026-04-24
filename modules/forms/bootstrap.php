<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

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
function metis_forms_render_embed( string $form_ref, array $options = [] ): string {
    $form_ref = trim( $form_ref );
    if ( $form_ref === '' ) {
        return '';
    }

    $form = null;
    if ( ctype_digit( $form_ref ) ) {
        $form = \Metis\Modules\Forms\Repository::getFormById( (int) $form_ref );
    } else {
        $form = \Metis\Modules\Forms\Repository::getFormBySlug( $form_ref, false );
    }

    if ( ! is_array( $form ) ) {
        return '';
    }

    $slug = trim( (string) ( $form['slug'] ?? '' ) );
    if ( $slug === '' ) {
        return '';
    }

    // Public embeds should only expose published forms.
    $status = strtolower( trim( (string) ( $form['status'] ?? 'draft' ) ) );
    if ( $status !== 'published' ) {
        return '';
    }

    $action = metis_escape_url( \Metis\Modules\Forms\FormsModule::publicUrl( $slug ) );
    if ( $action === '' ) {
        return '';
    }
    $frame_title = trim( (string) ( $options['title'] ?? $form['name'] ?? 'Form' ) );
    $height      = max( 640, (int) ( $options['height'] ?? 960 ) );

    return '<div class="metis-forms-embed-frame">'
        . '<iframe'
        . ' src="' . $action . '"'
        . ' title="' . metis_escape_attr( $frame_title ) . '"'
        . ' loading="lazy"'
        . ' style="width:100%;min-height:' . metis_escape_attr( (string) $height ) . 'px;border:0;border-radius:20px;background:#fff;"'
        . '></iframe>'
        . '</div>';
}
