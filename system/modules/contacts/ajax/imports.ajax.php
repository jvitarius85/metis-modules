<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_contacts_merge_duplicates', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contacts_merge_duplicates' ),
    ] );
    metis_ajax_register_controller( 'metis_contacts_cleanup_merge_notes', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contacts_cleanup_merge_notes' ),
    ] );
}

metis_ajax_register_handler( 'metis_contacts_merge_duplicates', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $primary_cid = isset( metis_request_post()['primary_cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['primary_cid'] ) ) : '';
    $duplicate_cids = [];
    if ( isset( metis_request_post()['duplicate_cids'] ) ) {
        $decoded = json_decode( (string) metis_runtime_unslash( metis_request_post()['duplicate_cids'] ), true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $item ) {
                $candidate = metis_text_clean( (string) $item );
                if ( $candidate !== '' ) {
                    $duplicate_cids[] = $candidate;
                }
            }
        }
    }
    if ( empty( $duplicate_cids ) && isset( metis_request_post()['duplicate_cid'] ) ) {
        $candidate = metis_text_clean( metis_runtime_unslash( metis_request_post()['duplicate_cid'] ) );
        if ( $candidate !== '' ) {
            $duplicate_cids[] = $candidate;
        }
    }
    metis_runtime_send_json_success(
        \Metis\Modules\Contacts\MergeService::mergeDuplicates( $primary_cid, $duplicate_cids )
    );
} );

metis_ajax_register_handler( 'metis_contacts_cleanup_merge_notes', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    metis_contacts_ensure_schema();
    $result = metis_contacts_cleanup_merge_notes();

    metis_runtime_send_json_success( [
        'groups_consolidated' => (int) ( $result['groups_consolidated'] ?? 0 ),
        'notes_created'       => (int) ( $result['notes_created'] ?? 0 ),
        'notes_deleted'       => (int) ( $result['notes_deleted'] ?? 0 ),
    ] );
} );
