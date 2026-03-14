<?php
if (!defined('ABSPATH')) exit;
error_log('MWTOOLS: stripe-link-transactions.php loaded');

use Stripe\BalanceTransaction;

function mwtools_stripe_link_transactions(array $args = []) {
     error_log('MWTOOLS: stripe link function START');
    global $wpdb;

if (!class_exists('\Stripe\BalanceTransaction')) {
    error_log('MWTOOLS: Stripe BalanceTransaction class missing');
    return;
}

error_log('MWTOOLS: Stripe SDK available');

    $defaults = [
        'limit_deposits' => 10,
        'dry_run'        => true,
        'log'            => false,
    ];
    $a = array_merge($defaults, $args);

    $deposits_table = $wpdb->prefix . 'mwtools_deposits';
    $tx_table       = $wpdb->prefix . 'mwtools_transactions';

    // Pull recent Stripe deposits
    $deposits = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, provider_ref
             FROM {$deposits_table}
             WHERE provider = %s
             ORDER BY deposit_date DESC
             LIMIT %d",
            'stripe',
            (int) $a['limit_deposits']
        )
    );
    error_log('MWTOOLS: Deposits found: ' . count($deposits));

    foreach ($deposits as $d) {
        $payout_id = $d->provider_ref;

        // List balance transactions for this payout
        $bt_list = BalanceTransaction::all([
            'payout' => $payout_id,
            'limit'  => 100,
        ]);

        foreach ($bt_list->data as $bt) {

            // We only care about charges & refunds
            if (!in_array($bt->type, ['charge', 'refund'], true)) {
                continue;
            }

            $balance_txn_id = $bt->id;        // txn_*
            $source_id      = $bt->source;    // ch_* or re_*

            if (!$balance_txn_id || !$source_id) {
                continue;
            }

            // Find local transaction by Stripe charge ID
            $local_tx_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$tx_table}
                     WHERE stripe_charge_id = %s
                     LIMIT 1",
                    $source_id
                )
            );

            if (!$local_tx_id) {
                continue;
            }

            if ($a['dry_run']) {
                if ($a['log']) {
                    error_log("LINK (dry): tx {$local_tx_id} ← {$balance_txn_id}");
                }
                continue;
            }

            // Update local transaction
            $wpdb->update(
                $tx_table,
                [
                    'stripe_balance_txn' => $balance_txn_id,
                    'deposit_batch_id'   => $payout_id, // TEMP mapping, refined later
                ],
                ['id' => $local_tx_id]
            );
        }
    }
}