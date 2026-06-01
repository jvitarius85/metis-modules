<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

use Metis\Modules\Donations\CampaignService;

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

function metis_donations_campaigns_ajax_verify( string $action ): void {
    $nonce = '';
    foreach ( [ 'metis_action_nonce', 'nonce' ] as $field ) {
        $value = metis_request_post()[ $field ] ?? '';
        if ( is_scalar( $value ) ) {
            $nonce = trim( (string) metis_runtime_unslash( $value ) );
            if ( $nonce !== '' ) {
                break;
            }
        }
    }

    $nonce_action = function_exists( 'metis_ajax_nonce_action' )
        ? metis_ajax_nonce_action( $action )
        : $action;

    if ( $nonce === '' || ! function_exists( 'metis_runtime_verify_nonce' ) || ! metis_runtime_verify_nonce( $nonce, $nonce_action ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    if ( ! function_exists( 'metis_donations_can_manage' ) || ! metis_donations_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
}

// -------------------------------------------------------------------------
// Save annual goal
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_campaign_save_goal', function () {
    metis_donations_campaigns_ajax_verify( 'metis_campaign_save_goal' );

    $db = metis_db();
    $table = Metis_Tables::get( 'campaigns' );

    $cid    = metis_text_clean( metis_request_post()['cid']    ?? '' );
    $year   = (int) ( metis_request_post()['year']   ?? 0 );
    $amount = (float) ( metis_request_post()['amount'] ?? -1 );

    if ( ! $cid || ! $year ) {
        metis_runtime_send_json_error( 'Missing required fields' );
    }

    $goals_str = CampaignService::goalStringForCampaign( $cid, $year, $amount );
    if ( $goals_str === null ) {
        metis_runtime_send_json_error( 'Campaign not found' );
    }

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
    metis_donations_campaigns_ajax_verify( 'metis_campaign_save_desc' );

    $db = metis_db();
    $table = Metis_Tables::get( 'campaigns' );

    $cid  = metis_text_clean( metis_request_post()['cid'] ?? '' );
    $desc = CampaignService::normalizeDescriptionHtml(
        metis_runtime_kses_post( metis_runtime_unslash( metis_request_post()['desc'] ?? '' ) )
    );

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

    metis_runtime_send_json_success( [ 'desc' => $desc ] );
} );

// -------------------------------------------------------------------------
// Save campaign info
// -------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_campaign_save_info', function () {
    metis_donations_campaigns_ajax_verify( 'metis_campaign_save_info' );

    $db = metis_db();
    $table = Metis_Tables::get( 'campaigns' );

    $cid    = metis_text_clean( metis_request_post()['cid']    ?? '' );
    $cname  = metis_text_clean( metis_request_post()['cname']  ?? '' );
    $type   = metis_text_clean( metis_request_post()['type']   ?? '' );
    $url    = metis_text_clean( metis_request_post()['url']    ?? '' );
    $active = isset( metis_request_post()['active'] ) ? (int) metis_request_post()['active'] : 1;
    $public = isset( metis_request_post()['public'] ) ? (int) metis_request_post()['public'] : 1;

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
