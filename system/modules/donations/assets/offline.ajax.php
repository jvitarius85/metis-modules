<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

use Metis\Modules\Donations\CampaignService;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_donations_lookup_donors', [
        'module' => 'donations',
        'permission' => 'view',
        'nonce_action' => metis_ajax_nonce_action( 'metis_donations_lookup_donors' ),
    ] );
    metis_ajax_register_controller( 'metis_donations_record_offline_donation', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_donations_record_offline_donation' ),
    ] );
}

function metis_donations_offline_ajax_verify( string $action, string $permission = 'edit' ): void {
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

    $allowed = $permission === 'view'
        ? ( function_exists( 'metis_donations_can' ) && metis_donations_can( 'view' ) )
        : ( function_exists( 'metis_donations_can_manage' ) && metis_donations_can_manage() );

    if ( ! $allowed ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
}

function metis_donations_quick_action_offline_donation_form( array $action = [] ): array {
    unset( $action );

    $campaignOptions = '<option value="">Select campaign</option>';
    foreach ( CampaignService::getActiveCampaignOptions( 500 ) as $campaign ) {
        $campaignOptions .= '<option value="' . metis_escape_attr( (string) ( $campaign['value'] ?? '' ) ) . '">' . metis_escape_html( (string) ( $campaign['label'] ?? '' ) ) . '</option>';
    }

    $html = '<form class="metis-offline-donation-form metis-quick-action-form" data-quick-action-form="donations_record_offline_donation">'
        . '<input type="hidden" name="donor_did" value="">'
        . '<div class="metis-offline-donation-grid">'
        . '<label class="metis-offline-field"><span>Date</span><input type="date" name="tran_date" class="metis-input" value="' . metis_escape_attr( date( 'Y-m-d' ) ) . '" required></label>'
        . '<label class="metis-offline-field"><span>Amount</span><input type="number" name="amount" class="metis-input" min="0.01" step="0.01" placeholder="0.00" required></label>'
        . '<label class="metis-offline-field"><span>Campaign</span><select name="campaign_code" class="metis-select" required>' . $campaignOptions . '</select></label>'
        . '<label class="metis-offline-field"><span>Payment Method</span><select name="payment_method" class="metis-select"><option value="ck">Check</option><option value="cash">Cash</option><option value="ach">ACH</option><option value="other">Other</option></select></label>'
        . '<label class="metis-offline-field"><span>Check Number</span><input type="text" name="chk_num" class="metis-input" placeholder="Optional"></label>'
        . '<label class="metis-offline-field"><span>First Name</span><input type="text" name="first_name" class="metis-input" placeholder="Donor first name"></label>'
        . '<label class="metis-offline-field"><span>Last Name</span><input type="text" name="last_name" class="metis-input" placeholder="Donor last name"></label>'
        . '<label class="metis-offline-field"><span>Email</span><input type="email" name="email" class="metis-input" placeholder="donor@example.org"></label>'
        . '<label class="metis-offline-field"><span>Phone</span><input type="text" name="phone" class="metis-input" placeholder="Optional"></label>'
        . '<label class="metis-offline-field metis-offline-field-notes"><span>Notes</span><textarea name="notes" class="metis-input" rows="3" placeholder="Internal notes or source details"></textarea></label>'
        . '</div>'
        . '</form>';

    return [
        'title' => 'Record Offline Donation',
        'html' => $html,
        'submit_action' => 'metis_donations_record_offline_donation',
        'submit_nonce_action' => function_exists( 'metis_ajax_nonce_action' ) ? metis_ajax_nonce_action( 'metis_donations_record_offline_donation' ) : 'metis_donations_record_offline_donation',
        'submit_label' => 'Record Donation',
        'success_message' => 'Offline donation recorded.',
        'redirect' => function_exists( 'metis_portal_url' ) ? (string) metis_portal_url( 'donations', 'transactions' ) : '',
    ];
}

metis_ajax_register_handler( 'metis_donations_lookup_donors', static function (): void {
    metis_donations_offline_ajax_verify( 'metis_donations_lookup_donors', 'view' );

    $query = trim( metis_text_clean( (string) ( metis_request_post()['q'] ?? '' ) ) );
    if ( $query === '' || strlen( $query ) < 2 ) {
        metis_runtime_send_json_success( [ 'matches' => [] ] );
    }

    try {
        $matches = \Metis\Modules\Donations\DonationsModule::lookupOfflineDonors( $query, 8 );
        metis_runtime_send_json_success( [ 'matches' => $matches ] );
    } catch ( Throwable $e ) {
        Metis_Logger::error( 'Offline donor lookup failed', [ 'error' => $e->getMessage() ] );
        metis_runtime_send_json_error( [ 'message' => 'Donor lookup failed.' ], 500 );
    }
} );

metis_ajax_register_handler( 'metis_donations_record_offline_donation', static function (): void {
    metis_donations_offline_ajax_verify( 'metis_donations_record_offline_donation', 'edit' );

    $fields = [
        'donor_did' => '',
        'tran_date' => date( 'Y-m-d' ),
        'amount' => '',
        'campaign_code' => '',
        'payment_method' => 'ck',
        'chk_num' => '',
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'phone' => '',
        'notes' => '',
    ];

    foreach ( $fields as $key => $default ) {
        $fields[ $key ] = is_string( metis_request_post()[ $key ] ?? null )
            ? trim( (string) metis_runtime_unslash( metis_request_post()[ $key ] ) )
            : $default;
    }

    $result = \Metis\Modules\Donations\DonationsModule::recordOfflineDonation( $fields, (int) metis_current_user_id() );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( (string) ( $result['message'] ?? 'Unable to record the offline donation.' ), 422 );
    }

    metis_runtime_send_json_success( [
        'message' => sprintf( 'Offline donation %s recorded.', (string) ( $result['tid'] ?? '' ) ),
        'tid' => (string) ( $result['tid'] ?? '' ),
    ] );
} );
