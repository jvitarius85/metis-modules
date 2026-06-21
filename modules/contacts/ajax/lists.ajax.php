<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_contact_add_newsletter', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contact_add_newsletter' ),
    ] );
    metis_ajax_register_controller( 'metis_contact_remove_newsletter', [
        'module' => 'contacts',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_contact_remove_newsletter' ),
    ] );
}

metis_ajax_register_handler( 'metis_contact_add_newsletter', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $db = metis_db();
    $contacts_table = Metis_Tables::get( 'contacts' );
    $newsletter_subs_table = Metis_Tables::get( 'newsletter_subs' );
    $newsletter_lists_table = Metis_Tables::get( 'newsletter_lists' );

    $cid = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $list_id = isset( metis_request_post()['list_id'] ) ? (int) metis_runtime_unslash( metis_request_post()['list_id'] ) : 0;
    if ( $cid === '' || $list_id < 1 ) {
        metis_runtime_send_json_error( 'CID and list are required.', 400 );
    }
    $result = \Metis\Modules\Contacts\AssociationService::addNewsletterSubscription( $cid, $list_id );

    if ( function_exists( 'metis_contacts_carddav_fetch_contact' ) ) {
        $carddav_entry = metis_contacts_carddav_fetch_contact( $cid );
        if ( is_array( $carddav_entry ) ) {
            metis_contacts_carddav_log_change(
                $cid,
                'upsert',
                metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
            );
        }
    }

    metis_runtime_send_json_success( $result );
} );

metis_ajax_register_handler( 'metis_contact_remove_newsletter', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $cid = isset( metis_request_post()['cid'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['cid'] ) ) : '';
    $list_id = isset( metis_request_post()['list_id'] ) ? (int) metis_runtime_unslash( metis_request_post()['list_id'] ) : 0;
    if ( $cid === '' || $list_id < 1 ) {
        metis_runtime_send_json_error( 'CID and list are required.', 400 );
    }
    $result = \Metis\Modules\Contacts\AssociationService::removeNewsletterSubscription( $cid, $list_id );

    if ( function_exists( 'metis_contacts_carddav_fetch_contact' ) ) {
        $carddav_entry = metis_contacts_carddav_fetch_contact( $cid );
        if ( is_array( $carddav_entry ) ) {
            metis_contacts_carddav_log_change(
                $cid,
                'upsert',
                metis_contacts_carddav_book_slugs_for_contact( $carddav_entry['contact'], $carddav_entry['details'] ),
                metis_contacts_carddav_contact_etag( $carddav_entry['contact'], $carddav_entry['details'] )
            );
        }
    }

    metis_runtime_send_json_success( $result );
} );
