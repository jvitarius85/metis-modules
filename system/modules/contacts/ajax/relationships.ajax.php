<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_contact_remove_relationship', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contact_remove_relationship' ),
    ] );
}

metis_ajax_register_handler( 'metis_contact_remove_relationship', function () {

    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();

    $db = metis_db();
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $cid = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $related_cid = isset( metis_request_post()['related_cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['related_cid'] ) ) : '';
    $relation_type = isset( metis_request_post()['relation_type'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['relation_type'] ) ) : '';
    $notes = isset( metis_request_post()['notes'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['notes'] ) ) : '';

    if ( $cid === '' || $related_cid === '' || $relation_type === '' ) {
        metis_runtime_send_json_error( 'Missing relationship payload.', 400 );
    }

    $contact_row_data = $db->fetchOne(
        "SELECT id, cid, did FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        [ $cid ]
    );
    $contact_row = is_array( $contact_row_data ) ? (object) $contact_row_data : null;
    if ( ! $contact_row ) {
        metis_runtime_send_json_error( 'Contact not found.', 404 );
    }

    $id = (int) $contact_row->id;
    $did = (string) ( $contact_row->did ?? '' );
    $detail_rows = metis_contacts_collect_linked_detail_rows( $details_table, $cid, $id, $did );

    $removed = 0;
    foreach ( $detail_rows as $detail_row ) {
        if ( ! isset( $detail_row->id ) ) {
            continue;
        }
        $decoded = json_decode( (string) ( $detail_row->relationships_json ?? '[]' ), true );
        $decoded = is_array( $decoded ) ? $decoded : [];
        $normalized = metis_contacts_normalize_relationships( $decoded, $cid );
        $filtered = array_values( array_filter( $normalized, static function ( array $entry ) use ( $related_cid, $relation_type, $notes ): bool {
            return ! (
                (string) ( $entry['related_contact_cid'] ?? '' ) === $related_cid &&
                (string) ( $entry['relation_type'] ?? '' ) === $relation_type &&
                (string) ( $entry['notes'] ?? '' ) === $notes
            );
        } ) );

        if ( count( $filtered ) === count( $normalized ) ) {
            continue;
        }

        $payload = [ 'relationships_json' => metis_json_encode( $filtered ) ];
        $format = [ '%s' ];
        if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
            $payload['updated_at'] = metis_current_time( 'mysql' );
            $format[] = '%s';
        }
        $ok = $db->update(
            $details_table,
            $payload,
            [ 'id' => (int) $detail_row->id ],
            $format,
            [ '%d' ]
        );
        if ( $ok === false ) {
            metis_runtime_send_json_error( 'Failed to remove relationship.', 500 );
        }
        $removed++;
    }

    $other_data = $db->fetchOne(
        "SELECT id, cid, did FROM {$contacts_table} WHERE cid = %s LIMIT 1",
        [ $related_cid ]
    );
    $other = is_array( $other_data ) ? (object) $other_data : null;
    if ( $other ) {
        $other_rows = metis_contacts_collect_linked_detail_rows( $details_table, (string) $other->cid, (int) $other->id, (string) ( $other->did ?? '' ) );
        foreach ( $other_rows as $other_row ) {
            if ( ! isset( $other_row->id ) ) {
                continue;
            }
            $decoded = json_decode( (string) ( $other_row->relationships_json ?? '[]' ), true );
            $decoded = is_array( $decoded ) ? $decoded : [];
            $normalized = metis_contacts_normalize_relationships( $decoded, (string) $other->cid );
            $filtered = array_values( array_filter( $normalized, static function ( array $entry ) use ( $cid, $relation_type, $notes ): bool {
                return ! (
                    (string) ( $entry['related_contact_cid'] ?? '' ) === $cid &&
                    (string) ( $entry['relation_type'] ?? '' ) === $relation_type &&
                    (string) ( $entry['notes'] ?? '' ) === $notes
                );
            } ) );
            if ( count( $filtered ) === count( $normalized ) ) {
                continue;
            }
            $payload = [ 'relationships_json' => metis_json_encode( $filtered ) ];
            $format = [ '%s' ];
            if ( metis_contacts_column_exists( $details_table, 'updated_at' ) ) {
                $payload['updated_at'] = metis_current_time( 'mysql' );
                $format[] = '%s';
            }
            $db->update(
                $details_table,
                $payload,
                [ 'id' => (int) $other_row->id ],
                $format,
                [ '%d' ]
            );
        }
    }

    metis_runtime_send_json_success( [
        'removed' => $removed,
    ] );
} );
