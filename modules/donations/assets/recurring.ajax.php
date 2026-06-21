<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Donations\RecurringDonationsService;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    foreach ( [ 'metis_recurring_donation_status', 'metis_recurring_donations_process_now', 'metis_recurring_migrate_stripe_subscriptions', 'metis_recurring_import_reviewed_stripe_subscription' ] as $action ) {
        metis_ajax_register_controller( $action, [
            'module' => 'donations',
            'permission' => 'edit',
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
}

function metis_donations_recurring_ajax_verify( string $action ): void {
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

metis_ajax_register_handler( 'metis_recurring_donation_status', static function (): void {
    metis_donations_recurring_ajax_verify( 'metis_recurring_donation_status' );

    $id = max( 0, (int) ( metis_request_post()['id'] ?? 0 ) );
    $status = metis_key_clean( (string) ( metis_request_post()['status'] ?? '' ) );
    if ( $id < 1 || ! in_array( $status, [ 'active', 'paused', 'cancelled' ], true ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid recurring donation update.' ], 400 );
    }

    $ok = RecurringDonationsService::updateStatus( $id, $status );
    if ( ! $ok ) {
        metis_runtime_send_json_error( [ 'message' => 'Recurring donation could not be updated.' ], 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Recurring donation updated.' ] );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_recurring_donations_process_now', static function (): void {
    metis_donations_recurring_ajax_verify( 'metis_recurring_donations_process_now' );

    $result = RecurringDonationsService::processDue( true, 25 );
    metis_runtime_send_json_success( $result );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_recurring_migrate_stripe_subscriptions', static function (): void {
    metis_donations_recurring_ajax_verify( 'metis_recurring_migrate_stripe_subscriptions' );

    $cancel = ! empty( metis_request_post()['cancel_stripe_subscriptions'] );
    $result = RecurringDonationsService::migrateStripeSubscriptions( $cancel, 100 );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Migration failed.' ) ], 500 );
    }
    metis_runtime_send_json_success( $result );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_recurring_import_reviewed_stripe_subscription', static function (): void {
    metis_donations_recurring_ajax_verify( 'metis_recurring_import_reviewed_stripe_subscription' );

    $post = metis_request_post();
    $subscriptionId = (string) ( $post['subscription_id'] ?? '' );
    $cancel = ! empty( $post['cancel_stripe_subscription'] );
    $result = RecurringDonationsService::importReviewedStripeSubscription( $subscriptionId, [
        'campaign_code' => (string) ( $post['campaign_code'] ?? '' ),
        'donor_email' => (string) ( $post['donor_email'] ?? '' ),
        'donor_name' => (string) ( $post['donor_name'] ?? '' ),
    ], $cancel );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Subscription could not be imported.' ) ], 422 );
    }
    metis_runtime_send_json_success( $result );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );
