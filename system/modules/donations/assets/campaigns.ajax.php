<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Campaigns AJAX handlers
 *
 * Actions:
 *   metis_campaign_save_goal  — save/delete an annual goal
 *   metis_campaign_save_desc  — save description
 *   metis_campaign_save_info  — save campaign info fields
 */

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_campaign_save_goal', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_campaign_save_goal' ),
    ] );
    metis_ajax_register_controller( 'metis_campaign_save_desc', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_campaign_save_desc' ),
    ] );
    metis_ajax_register_controller( 'metis_campaign_save_info', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_campaign_save_info' ),
    ] );
}

// -------------------------------------------------------------------------
// Save annual goal
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_campaign_save_goal', function () {
    $db = metis_db();
    $table = Metis_Tables::get( 'campaigns' );

    $cid    = metis_text_clean( $_POST['cid']    ?? '' );
    $year   = (int) ( $_POST['year']   ?? 0 );
    $amount = (float) ( $_POST['amount'] ?? -1 );

    if ( ! $cid || ! $year ) {
        metis_runtime_send_json_error( 'Missing required fields' );
    }

    $campaign = $db->fetchOne( "SELECT id, goals FROM {$table} WHERE cid = %s LIMIT 1", [ $cid ] );
    $campaign = $campaign ? (object) $campaign : null;

    if ( ! $campaign ) {
        metis_runtime_send_json_error( 'Campaign not found' );
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

    $updated = $db->update(
        $table,
        [ 'goals' => $goals_str ?: null ],
        [ 'cid'   => $cid ]
    );

    if ( $updated === false ) {
        metis_runtime_send_json_error( 'Database update failed' );
    }

    Metis_Logger::info( 'Campaign goal saved', [ 'cid' => $cid, 'year' => $year, 'amount' => $amount ] );

    metis_runtime_send_json_success( [ 'goals_str' => $goals_str ] );
} );

// -------------------------------------------------------------------------
// Save description
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_campaign_save_desc', function () {
    $db = metis_db();
    $table = Metis_Tables::get( 'campaigns' );

    $cid  = metis_text_clean( $_POST['cid'] ?? '' );
    $desc = metis_runtime_kses_post( metis_runtime_unslash( $_POST['desc'] ?? '' ) ); // allow safe HTML from WYSIWYG

    if ( ! $cid ) {
        metis_runtime_send_json_error( 'Missing campaign ID' );
    }

    $updated = $db->update(
        $table,
        [ 'cdesc' => $desc ?: null ],
        [ 'cid'   => $cid ]
    );

    if ( $updated === false ) {
        metis_runtime_send_json_error( 'Database update failed' );
    }

    Metis_Logger::info( 'Campaign description saved', [ 'cid' => $cid ] );

    metis_runtime_send_json_success();
} );

// -------------------------------------------------------------------------
// Save campaign info
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_campaign_save_info', function () {
    $db = metis_db();
    $table = Metis_Tables::get( 'campaigns' );

    $cid    = metis_text_clean( $_POST['cid']    ?? '' );
    $cname  = metis_text_clean( $_POST['cname']  ?? '' );
    $type   = metis_text_clean( $_POST['type']   ?? '' );
    $url    = metis_text_clean( $_POST['url']    ?? '' );
    $active = isset( $_POST['active'] ) ? (int) $_POST['active'] : 1;
    $public = isset( $_POST['public'] ) ? (int) $_POST['public'] : 1;

    if ( ! $cid || ! $cname ) {
        metis_runtime_send_json_error( 'Campaign name is required' );
    }

    $allowed_types = [ 'Ongoing', 'Project' ];
    if ( ! in_array( $type, $allowed_types, true ) ) {
        $type = 'Ongoing';
    }

    $updated = $db->update(
        $table,
        [
            'cname'  => $cname,
            'type'   => $type,
            'url'    => $url ?: null,
            'active' => $active ? 1 : 0,
            'public' => $public ? 1 : 0,
        ],
        [ 'cid' => $cid ]
    );

    if ( $updated === false ) {
        metis_runtime_send_json_error( 'Database update failed' );
    }

    Metis_Logger::info( 'Campaign info saved', [ 'cid' => $cid, 'cname' => $cname ] );

    metis_runtime_send_json_success();
} );
