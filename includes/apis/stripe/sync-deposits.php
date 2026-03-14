<?php
use Stripe\Payout;

function mwtools_sync_stripe_deposits($limit = 50) {
    global $wpdb;

    mwtools_stripe_init();

    $table = $wpdb->prefix . 'mwtools_deposits';

    $payouts = Payout::all([
        'limit' => $limit,
        'expand' => ['data.balance_transaction']
    ]);

    foreach ($payouts->data as $payout) {

        $external_id = $payout->id;

        // Convert cents → dollars
        $amount = $payout->amount / 100;

        $deposit_date = date('Y-m-d H:i:s', $payout->arrival_date);

        $status = match ($payout->status) {
            'paid'     => 'deposited',
            'pending'  => 'pending',
            'failed'   => 'failed',
            default    => 'pending'
        };

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE external_id = %s",
                $external_id
            )
        );

        $data = [
            'provider'      => 'stripe',
            'source'        => 'digital',
            'external_id'   => $external_id,
            'status'        => $status,
            'deposit_date'  => $deposit_date,
            'total_amount'  => $amount,
            'raw'           => metis_json_encode($payout),
            'updated_at'    => current_time('mysql'),
        ];

        if ($exists) {
            $wpdb->update(
                $table,
                $data,
                ['external_id' => $external_id]
            );
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
        }
    }
}
