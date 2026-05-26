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

    $cid = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $related_cid = isset( metis_request_post()['related_cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['related_cid'] ) ) : '';
    $relation_type = isset( metis_request_post()['relation_type'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['relation_type'] ) ) : '';
    $notes = isset( metis_request_post()['notes'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['notes'] ) ) : '';

    if ( $cid === '' || $related_cid === '' || $relation_type === '' ) {
        metis_runtime_send_json_error( 'Missing relationship payload.', 400 );
    }
    metis_runtime_send_json_success(
        \Metis\Modules\Contacts\AssociationService::removeRelationship( $cid, $related_cid, $relation_type, $notes )
    );
} );
