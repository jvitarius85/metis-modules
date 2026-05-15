<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Donations\RecurringDonationsService;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    foreach ( [ 'metis_recurring_donation_status', 'metis_recurring_donations_process_now', 'metis_recurring_migrate_stripe_subscriptions' ] as $action ) {
        metis_ajax_register_controller( $action, [
            'module' => 'donations',
            'permission' => 'edit',
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
}

metis_ajax_register_handler( 'metis_recurring_donation_status', static function (): void {
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
    $result = RecurringDonationsService::processDue( true, 25 );
    metis_runtime_send_json_success( $result );
}, [
    'module' => 'donations',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_recurring_migrate_stripe_subscriptions', static function (): void {
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
