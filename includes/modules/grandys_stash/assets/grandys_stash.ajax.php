<?php
if ( ! defined( 'ABSPATH' ) ) exit;

use Metis\Modules\GrandyStashRepository;

function metis_grandys_stash_ajax_guard( bool $manage_required = false ): void {
    check_ajax_referer( 'metis_grandys_stash', 'nonce' );
    if ( ! metis_grandys_stash_can_view() || ( $manage_required && ! metis_grandys_stash_can_manage() ) ) {
        metis_send_json_error( 'Unauthorized', 403 );
    }
    GrandyStashRepository::ensureModuleReady();
}

metis_add_action( 'wp_ajax_metis_grandys_stash_state', function (): void {
    metis_grandys_stash_ajax_guard();
    metis_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_add_action( 'wp_ajax_metis_grandys_stash_save_item', function (): void {
    metis_grandys_stash_ajax_guard( true );
    $payload = json_decode( (string) ( $_POST['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_send_json_error( 'Invalid item payload.', 422 );
    }
    $result = GrandyStashRepository::saveItem( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( (string) ( $result['error'] ?? 'Unable to save item.' ), (int) ( $result['status'] ?? 500 ) );
    }
    metis_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_add_action( 'wp_ajax_metis_grandys_stash_save_case', function (): void {
    metis_grandys_stash_ajax_guard( true );
    $payload = json_decode( (string) ( $_POST['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_send_json_error( 'Invalid case payload.', 422 );
    }
    $result = GrandyStashRepository::saveCase( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( (string) ( $result['error'] ?? 'Unable to save case.' ), (int) ( $result['status'] ?? 500 ) );
    }
    metis_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_add_action( 'wp_ajax_metis_grandys_stash_save_routing_defaults', function (): void {
    metis_grandys_stash_ajax_guard( true );
    $payload = json_decode( (string) ( $_POST['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_send_json_error( 'Invalid routing defaults payload.', 422 );
    }
    $result = GrandyStashRepository::saveRoutingDefaults( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( (string) ( $result['error'] ?? 'Unable to save routing defaults.' ), (int) ( $result['status'] ?? 500 ) );
    }
    metis_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_add_action( 'wp_ajax_metis_grandys_stash_assign_item', function (): void {
    metis_grandys_stash_ajax_guard( true );
    $payload = json_decode( (string) ( $_POST['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_send_json_error( 'Invalid assignment payload.', 422 );
    }
    $result = GrandyStashRepository::assignItem( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( (string) ( $result['error'] ?? 'Unable to assign item.' ), (int) ( $result['status'] ?? 500 ) );
    }
    metis_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_add_action( 'wp_ajax_metis_grandys_stash_contact_search', function (): void {
    metis_grandys_stash_ajax_guard();
    $query = isset( $_POST['query'] ) ? sanitize_text_field( metis_unslash( $_POST['query'] ) ) : '';
    metis_send_json_success( [ 'contacts' => GrandyStashRepository::searchContacts( $query ) ] );
} );
