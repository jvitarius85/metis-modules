<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Campaigns AJAX handlers
 *
 * Actions:
 *   metis_campaign_save_goal  — save/delete an annual goal
 *   metis_campaign_save_desc  — save description
 *   metis_campaign_save_info  — save campaign info fields
 */

// -------------------------------------------------------------------------
// Save annual goal
// -------------------------------------------------------------------------

metis_add_action( 'wp_ajax_metis_campaign_save_goal', function () {

    if ( ! check_ajax_referer( 'metis_campaign_edit', 'nonce', false ) ) {
        metis_send_json_error( 'Invalid nonce', 403 );
    }

    if ( ! metis_user_logged_in() ) {
        metis_send_json_error( 'Not logged in', 403 );
    }

    global $wpdb;
    $table = Metis_Tables::get( 'campaigns' );

    $cid    = sanitize_text_field( $_POST['cid']    ?? '' );
    $year   = (int) ( $_POST['year']   ?? 0 );
    $amount = (float) ( $_POST['amount'] ?? -1 );

    if ( ! $cid || ! $year ) {
        metis_send_json_error( 'Missing required fields' );
    }

    $campaign = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, goals FROM {$table} WHERE cid = %s LIMIT 1", $cid
    ) );

    if ( ! $campaign ) {
        metis_send_json_error( 'Campaign not found' );
    }

    // Parse existing goals
    $goals = [];
    if ( $campaign->goals ) {
        foreach ( explode( '|', $campaign->goals ) as $entry ) {
            $parts = explode( ':', $entry, 2 );
            if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) ) {
                $goals[ (int) $parts[0] ] = (float) $parts[1];
            }
        }
    }

    if ( $amount <= 0 ) {
        // Remove goal for this year
        unset( $goals[ $year ] );
    } else {
        $goals[ $year ] = round( $amount, 2 );
    }

    // Re-serialize: year:amount|year:amount ...
    krsort( $goals );
    $goals_str = implode( '|', array_map(
        fn( $y, $a ) => "{$y}:{$a}",
        array_keys( $goals ),
        array_values( $goals )
    ) );

    $updated = $wpdb->update(
        $table,
        [ 'goals' => $goals_str ?: null ],
        [ 'cid'   => $cid ],
        [ '%s' ],
        [ '%s' ]
    );

    if ( $updated === false ) {
        metis_send_json_error( 'Database update failed' );
    }

    Metis_Logger::info( 'Campaign goal saved', [ 'cid' => $cid, 'year' => $year, 'amount' => $amount ] );

    metis_send_json_success( [ 'goals_str' => $goals_str ] );
} );

// -------------------------------------------------------------------------
// Save description
// -------------------------------------------------------------------------

metis_add_action( 'wp_ajax_metis_campaign_save_desc', function () {

    if ( ! check_ajax_referer( 'metis_campaign_edit', 'nonce', false ) ) {
        metis_send_json_error( 'Invalid nonce', 403 );
    }

    if ( ! metis_user_logged_in() ) {
        metis_send_json_error( 'Not logged in', 403 );
    }

    global $wpdb;
    $table = Metis_Tables::get( 'campaigns' );

    $cid  = sanitize_text_field( $_POST['cid'] ?? '' );
    $desc = metis_kses_post( metis_unslash( $_POST['desc'] ?? '' ) ); // allow safe HTML from WYSIWYG

    if ( ! $cid ) {
        metis_send_json_error( 'Missing campaign ID' );
    }

    $updated = $wpdb->update(
        $table,
        [ 'cdesc' => $desc ?: null ],
        [ 'cid'   => $cid ],
        [ '%s' ],
        [ '%s' ]
    );

    if ( $updated === false ) {
        metis_send_json_error( 'Database update failed' );
    }

    Metis_Logger::info( 'Campaign description saved', [ 'cid' => $cid ] );

    metis_send_json_success();
} );

// -------------------------------------------------------------------------
// Save campaign info
// -------------------------------------------------------------------------

metis_add_action( 'wp_ajax_metis_campaign_save_info', function () {

    if ( ! check_ajax_referer( 'metis_campaign_edit', 'nonce', false ) ) {
        metis_send_json_error( 'Invalid nonce', 403 );
    }

    if ( ! metis_user_logged_in() ) {
        metis_send_json_error( 'Not logged in', 403 );
    }

    global $wpdb;
    $table = Metis_Tables::get( 'campaigns' );

    $cid    = sanitize_text_field( $_POST['cid']    ?? '' );
    $cname  = sanitize_text_field( $_POST['cname']  ?? '' );
    $type   = sanitize_text_field( $_POST['type']   ?? '' );
    $url    = sanitize_text_field( $_POST['url']    ?? '' );
    $active = isset( $_POST['active'] ) ? (int) $_POST['active'] : 1;
    $public = isset( $_POST['public'] ) ? (int) $_POST['public'] : 1;

    if ( ! $cid || ! $cname ) {
        metis_send_json_error( 'Campaign name is required' );
    }

    $allowed_types = [ 'Ongoing', 'Project' ];
    if ( ! in_array( $type, $allowed_types, true ) ) {
        $type = 'Ongoing';
    }

    $updated = $wpdb->update(
        $table,
        [
            'cname'  => $cname,
            'type'   => $type,
            'url'    => $url ?: null,
            'active' => $active ? 1 : 0,
            'public' => $public ? 1 : 0,
        ],
        [ 'cid' => $cid ],
        [ '%s', '%s', '%s', '%d', '%d' ],
        [ '%s' ]
    );

    if ( $updated === false ) {
        metis_send_json_error( 'Database update failed' );
    }

    Metis_Logger::info( 'Campaign info saved', [ 'cid' => $cid, 'cname' => $cname ] );

    metis_send_json_success();
} );
