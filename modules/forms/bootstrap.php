<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Forms\FormsModule::boot();

function metis_forms_can_view(): bool { return \Metis\Modules\Forms\FormsModule::canView(); }
function metis_forms_can( string $action ): bool {
    $action = metis_key_clean( $action );
    if ( $action === '' ) {
        return false;
    }

    if ( function_exists( 'metis_security_user_can' ) ) {
        return metis_security_user_can( 'forms.' . $action );
    }

    return $action === 'view' ? metis_forms_can_view() : metis_forms_can_manage();
}
function metis_forms_can_manage(): bool { return \Metis\Modules\Forms\FormsModule::canManage(); }
function metis_forms_can_delete(): bool { return \Metis\Modules\Forms\FormsModule::canDelete(); }
function metis_forms_can_publish(): bool { return metis_forms_can( 'publish' ); }
function metis_forms_can_export(): bool { return metis_forms_can( 'export' ); }
function metis_forms_base_url(): string { return \Metis\Modules\Forms\FormsModule::baseUrl(); }
function metis_forms_public_url( string $slug = '' ): string { return \Metis\Modules\Forms\FormsModule::publicUrl( $slug ); }
function metis_forms_detail_url( int $form_id = 0 ): string { return \Metis\Modules\Forms\FormsModule::detailUrl( $form_id ); }
function metis_forms_build_url( int $form_id = 0 ): string { return \Metis\Modules\Forms\FormsModule::buildUrl( $form_id ); }
function metis_forms_entries_url( int $form_id = 0 ): string { return \Metis\Modules\Forms\FormsModule::entriesUrl( $form_id ); }
function metis_forms_settings_url( int $form_id = 0 ): string { return \Metis\Modules\Forms\FormsModule::settingsUrl( $form_id ); }
function metis_forms_ensure_schema(): void { \Metis\Modules\Forms\FormsModule::ensureSchema(); }
function metis_forms_handle_public_route( Metis_Http_Request $request ): Metis_Http_Response { return \Metis\Modules\Forms\FormsModule::handlePublicRoute( $request ); }
function metis_forms_published_options(): array {
    $rows = \Metis\Modules\Forms\Repository::listForms();
    $options = [];
    foreach ( (array) $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $id = (int) ( $row['id'] ?? 0 );
        if ( $id < 1 ) {
            continue;
        }
        if ( strtolower( trim( (string) ( $row['status'] ?? 'draft' ) ) ) !== 'published' ) {
            continue;
        }
        $name = trim( (string) ( $row['name'] ?? '' ) );
        $slug = trim( (string) ( $row['slug'] ?? '' ) );
        $label = $name !== '' ? $name : ( $slug !== '' ? $slug : ( 'Form #' . $id ) );
        $options[] = [ 'value' => (string) $id, 'label' => $label ];
    }

    return $options;
}
function metis_forms_find_published_payment_form_ref( array $candidate_ids ): string {
    $candidate_ids = array_values( array_filter( array_unique( array_map(
        static fn( mixed $value ): string => trim( (string) $value ),
        $candidate_ids
    ) ) ) );
    if ( $candidate_ids === [] ) {
        return '';
    }

    static $resolved = [];
    $cache_key = implode( '|', $candidate_ids );
    if ( array_key_exists( $cache_key, $resolved ) ) {
        return $resolved[ $cache_key ];
    }

    $match = '';
    foreach ( \Metis\Modules\Forms\Repository::listForms( 250 ) as $summary ) {
        $form_id = (int) ( $summary['id'] ?? 0 );
        if ( $form_id < 1 ) {
            continue;
        }

        $form = \Metis\Modules\Forms\Repository::getFormById( $form_id, true );
        if ( ! is_array( $form ) ) {
            continue;
        }

        foreach ( (array) ( $form['schema'] ?? [] ) as $field ) {
            if ( ! is_array( $field ) || ( $field['type'] ?? '' ) !== 'payment' ) {
                continue;
            }

            $campaign_code = trim( (string) ( $field['payment']['campaign_code'] ?? '' ) );
            if ( $campaign_code !== '' && in_array( $campaign_code, $candidate_ids, true ) ) {
                $match = (string) $form_id;
                break 2;
            }
        }
    }

    $resolved[ $cache_key ] = $match;
    return $match;
}
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

    return \Metis\Modules\Forms\FormRenderer::renderEmbedHtml( $form, $options );
}
