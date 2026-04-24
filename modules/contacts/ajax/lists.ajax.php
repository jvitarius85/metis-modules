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

    $cid = isset( $_POST['cid'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['cid'] ) ) : '';
    $list_id = isset( $_POST['list_id'] ) ? (int) metis_runtime_unslash( $_POST['list_id'] ) : 0;
    if ( $cid === '' || $list_id < 1 ) {
        metis_runtime_send_json_error( 'CID and list are required.', 400 );
    }

    $contact_id = (int) $db->scalar( "SELECT id FROM {$contacts_table} WHERE cid = %s LIMIT 1", [ $cid ] );
    if ( $contact_id < 1 ) {
        metis_runtime_send_json_error( 'Contact not found.', 404 );
    }

    $list_name = (string) $db->scalar(
        "SELECT name FROM {$newsletter_lists_table} WHERE id = %d AND name IS NOT NULL AND TRIM(name) <> '' LIMIT 1",
        [ $list_id ]
    );
    if ( $list_name === '' ) {
        metis_runtime_send_json_error( 'Invalid newsletter list.', 400 );
    }

    $exists = (int) $db->scalar(
        "SELECT id FROM {$newsletter_subs_table} WHERE contact_id = %d AND list_id = %d LIMIT 1",
        [ $contact_id, $list_id ]
    );
    if ( $exists < 1 ) {
        $ok = $db->insert( $newsletter_subs_table, [ 'contact_id' => $contact_id, 'list_id' => $list_id ], [ '%d', '%d' ] );
        if ( $ok === false ) {
            metis_runtime_send_json_error( 'Failed to add newsletter subscription.', 500 );
        }
    }

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

    metis_runtime_send_json_success( [ 'list_id' => $list_id, 'name' => $list_name ] );
} );

metis_ajax_register_handler( 'metis_contact_remove_newsletter', function () {
    metis_contacts_ajax_verify_nonce();

    if ( ! metis_contacts_can_manage() ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $db = metis_db();
    $contacts_table = Metis_Tables::get( 'contacts' );
    $newsletter_subs_table = Metis_Tables::get( 'newsletter_subs' );

    $cid = isset( $_POST['cid'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['cid'] ) ) : '';
    $list_id = isset( $_POST['list_id'] ) ? (int) metis_runtime_unslash( $_POST['list_id'] ) : 0;
    if ( $cid === '' || $list_id < 1 ) {
        metis_runtime_send_json_error( 'CID and list are required.', 400 );
    }

    $contact_id = (int) $db->scalar( "SELECT id FROM {$contacts_table} WHERE cid = %s LIMIT 1", [ $cid ] );
    if ( $contact_id < 1 ) {
        metis_runtime_send_json_error( 'Contact not found.', 404 );
    }

    $ok = $db->delete( $newsletter_subs_table, [ 'contact_id' => $contact_id, 'list_id' => $list_id ], [ '%d', '%d' ] );
    if ( $ok === false ) {
        metis_runtime_send_json_error( 'Failed to remove newsletter subscription.', 500 );
    }

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

    metis_runtime_send_json_success( [ 'list_id' => $list_id ] );
} );
