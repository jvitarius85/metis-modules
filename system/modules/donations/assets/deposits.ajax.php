<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

require_once dirname( __DIR__, 2 ) . '/portal/views/_dashboard_data.php';

/**
 * Donations Deposits AJAX Handler
 *
 * Endpoints:
 *   metis_ajax_metis_sync_deposits
 */

Metis_Logger::info( 'Donations Deposits AJAX loaded' );

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $metis_deposit_actions = [
        'metis_sync_deposits',
        'metis_backfill_deposit_totals',
        'metis_link_stripe_payouts',
        'metis_verify_deposit_links',
        'metis_import_stripe_charges',
        'metis_import_stripe_transactions',
        'metis_backfill_deposit_adjustments',
    ];

    foreach ( $metis_deposit_actions as $action ) {
        metis_ajax_register_controller( $action, [
            'module' => 'donations',
            'permission' => 'edit',
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
}

metis_ajax_register_handler( 'metis_sync_deposits',           'metis_ajax_sync_deposits' );
metis_ajax_register_handler( 'metis_backfill_deposit_totals', 'metis_ajax_backfill_deposit_totals' );
metis_ajax_register_handler( 'metis_link_stripe_payouts',     'metis_ajax_link_stripe_payouts' );
metis_ajax_register_handler( 'metis_verify_deposit_links',    'metis_ajax_verify_deposit_links' );
metis_ajax_register_handler( 'metis_import_stripe_charges',   'metis_ajax_import_stripe_charges' );
metis_ajax_register_handler( 'metis_import_stripe_transactions', 'metis_ajax_import_stripe_charges' );
metis_ajax_register_handler( 'metis_backfill_deposit_adjustments', 'metis_ajax_backfill_deposit_adjustments' );

function metis_donations_stripe_client_or_error(): \Metis\Core\Integrations\StripeApiClient {
    $stripe = function_exists( 'metis_stripe_client' ) ? metis_stripe_client() : null;
    if ( ! $stripe ) {
        metis_runtime_send_json_error( [ 'message' => 'Stripe is not configured.' ], 500 );
    }
    return $stripe;
}

function metis_donations_deposits_ajax_verify( string $action ): void {
    $request = function_exists( 'metis_request_post' ) ? (array) metis_request_post() : [];
    $nonce = (string) ( $request['metis_action_nonce'] ?? $request['nonce'] ?? '' );

    if ( $nonce === '' || ! function_exists( 'metis_runtime_verify_nonce' ) || ! metis_runtime_verify_nonce( $nonce, metis_ajax_nonce_action( $action ) ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid request.' ], 403 );
    }

    if ( ! function_exists( 'metis_donations_can_manage' ) || ! metis_donations_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
}

function metis_ajax_sync_deposits(): void {
    metis_donations_deposits_ajax_verify( 'metis_sync_deposits' );

    $stripe = metis_donations_stripe_client_or_error();

    $db = metis_db();
    $deposits_table = Metis_Tables::get( 'deposits' );

    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;
    $errors   = [];

    // ── Pull payouts directly from Stripe API ──────────────────────────────
    // Look back far enough to cover all unlinked transactions (~1 year)
    try {
        $now    = time();
        $since  = $now - ( 400 * DAY_IN_SECONDS ); // ~13 months back
        $params = [ 'limit' => 100, 'created' => [ 'gte' => $since, 'lte' => $now ] ];
        $page   = 0;

        while ( true ) {
            $page++;
            if ( $page > 20 ) { $errors[] = 'Stopped: exceeded 20 pages.'; break; }

            $payouts = $stripe->listPayouts( $params );
            if ( empty( $payouts->data ) ) break;

            foreach ( $payouts->data as $payout ) {
                $payout_id    = (string) $payout->id;
                $arrival_date = gmdate( 'Y-m-d', (int) ( $payout->arrival_date ?? $payout->created ) );
                $net_amount   = (float) $payout->amount / 100.0;
                $currency     = strtolower( (string) ( $payout->currency ?? 'usd' ) );
                $status       = (string) ( $payout->status ?? 'paid' );

                // Check if deposit already exists for this payout
                $existing_data = $db->fetchOne(
                    "SELECT id, provider_ref FROM {$deposits_table}
                     WHERE provider = 'stripe'
                       AND ( provider_ref = %s OR meta LIKE %s )
                     LIMIT 1",
                    [ $payout_id, '%' . $db->escapeLike( '"payout_id":"' . $payout_id . '"' ) . '%' ]
                );
                $existing = is_array( $existing_data ) ? (object) $existing_data : null;

                if ( $existing ) {
                    // Ensure arrival_date and status are up to date
                    $db->update(
                        $deposits_table,
                        [ 'deposit_date' => $arrival_date, 'status' => $status === 'paid' ? 'deposited' : $status, 'updated_at' => metis_current_time( 'mysql' ) ],
                        [ 'id' => (int) $existing->id ]
                    );
                    $updated++;
                    continue;
                }

                // Create new deposit record
                $meta = metis_json_encode( [
                    'payout_id'     => $payout_id,
                    'arrival_date'  => $arrival_date,
                    'generated_via' => 'sync-deposits',
                ] );

                $deposit_payload = [
                    'provider'          => 'stripe',
                    'deposit_type'      => 'stripe',
                    'source'            => 'automatic',
                    'status'            => $status === 'paid' ? 'deposited' : $status,
                    'deposit_date'      => $arrival_date,
                    'expected_date'     => $arrival_date,
                    'total_amount'      => $net_amount,
                    'currency'          => $currency,
                    'batch_count'       => 1,
                    'transaction_count' => 0,
                    'meta'              => $meta,
                    'created_at'        => metis_current_time( 'mysql' ),
                    'updated_at'        => metis_current_time( 'mysql' ),
                ];
                if ( function_exists( 'metis_entity_id_service' ) ) {
                    $deposit_payload = metis_entity_id_service()->assignForInsert( 'donation_deposit', $deposit_payload );
                } else {
                    $deposit_payload['provider_ref'] = metis_generate_code( 'DP', $deposits_table, 'provider_ref' );
                }
                $deposit_code = (string) ( $deposit_payload['deposit_uid'] ?? $deposit_payload['provider_ref'] ?? '' );

                $ok = $db->insert( $deposits_table, $deposit_payload );

                if ( $ok ) {
                    if ( function_exists( 'metis_entity_id_service' ) ) {
                        metis_entity_id_service()->register( 'donation_deposit', (int) $db->lastInsertId(), $deposit_code );
                    }
                    $inserted++;
                }
                else { $errors[] = "Insert failed for payout {$payout_id}."; }
            }

            $last = end( $payouts->data );
            if ( ! $payouts->has_more || ! $last ) break;
            $params['starting_after'] = $last->id;
        }

    } catch ( \Exception $e ) {
        if ( class_exists( 'Metis_Logger' ) ) {
            Metis_Logger::warn( 'donations.deposits_sync_failed', [ 'error' => $e->getMessage() ] );
        }
        metis_runtime_send_json_error( [ 'message' => 'Stripe sync failed. Please try again.' ] );
        return;
    }

    metis_reports_clear_cache();
    metis_portal_dashboard_forget_all();

    metis_runtime_send_json_success( [
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'message'  => "Sync complete: {$inserted} new deposit(s), {$updated} updated.",
    ] );
}

// -------------------------------------------------------------------------
// metis_ajax_backfill_deposit_totals
//
// For Stripe deposits where fee_total / gross_total / net_total are NULL,
// fetch real numbers from Stripe's balance transaction API and write them back.
//
// Also links any transactions that have a matching stripe_payout_id stored
// in the deposit's meta but still have no deposit_batch_id.
// -------------------------------------------------------------------------

function metis_ajax_backfill_deposit_totals(): void {
    metis_donations_deposits_ajax_verify( 'metis_backfill_deposit_totals' );

    $stripe = metis_donations_stripe_client_or_error();

    $db = metis_db();

    $deposits_table     = Metis_Tables::get( 'deposits' );
    $transactions_table = Metis_Tables::get( 'transactions' );

    $filled  = 0;
    $linked  = 0;
    $local   = 0;
    $skipped = 0;
    $errors  = [];
    $rows    = []; // per-deposit result rows for the UI

    // Find all Stripe deposits missing or clearly bad financial totals.
    // net_total = 0 with a known payout_id is a sign of the payout-included-in-sum bug.
    $deposits = array_map(
        static fn( array $row ): object => (object) $row,
        $db->fetchAll(
        "SELECT id, provider_ref, meta
         FROM {$deposits_table}
         WHERE provider = 'stripe'
           AND (
               fee_total IS NULL OR gross_total IS NULL OR net_total IS NULL
               OR ( net_total = 0 AND gross_total IS NOT NULL AND meta LIKE '%payout_id%' )
           )
         ORDER BY deposit_date DESC"
        )
    );

    if ( empty( $deposits ) ) {
        metis_runtime_send_json_success( [
            'filled'  => 0,
            'linked'  => 0,
            'local'   => 0,
            'skipped' => 0,
            'message' => 'No deposits need backfilling.',
        ] );
        return;
    }

    foreach ( $deposits as $dep ) {

        $meta       = json_decode( (string) $dep->meta, true );
        $payout_id  = is_array( $meta ) ? ( $meta['payout_id'] ?? '' ) : '';
        $dep_code   = $dep->provider_ref;

        // Capture before state for reporting
        $before_data = $db->fetchOne(
            "SELECT fee_total, gross_total, net_total, transaction_count FROM {$deposits_table} WHERE id = %d",
            [ (int) $dep->id ]
        );
        $before = is_array( $before_data ) ? (object) $before_data : null;

        // ── Attempt 1: pull totals directly from Stripe ──────────────────────
        $fee_total   = null;
        $gross_total = null;
        $net_total   = null;

        if ( $payout_id ) {
            try {
                // Charge/payment types that contribute positively to the payout
                $charge_types = [ 'charge', 'payment', 'payment_reversal' ];
                // Refund/adjustment types that reduce the payout
                $refund_types = [ 'refund', 'refund_failure', 'adjustment', 'dispute', 'dispute_reversal' ];

                $sum_charges_gross = 0.0; // gross of charges only (before fees)
                $sum_charges_fee   = 0.0; // processing fees on charges
                $sum_refunds       = 0.0; // net returned from refunds (positive value)
                $sum_stripe_fees   = 0.0; // stripe billing/application fees
                $payout_net        = 0.0; // authoritative payout net (set from payout btxn or sum)

                $params         = [ 'payout' => $payout_id, 'limit' => 100 ];
                $has_more       = true;
                $starting_after = null;

                while ( $has_more ) {
                    if ( $starting_after ) $params['starting_after'] = $starting_after;
                    else unset( $params['starting_after'] );

                    $bt_page = $stripe->listBalanceTransactions( $params );

                    foreach ( $bt_page->data as $bt ) {
                        $starting_after = $bt->id;
                        $bt_type   = (string) ( $bt->type ?? '' );
                        $bt_amount = (float) ( $bt->amount ?? 0 ) / 100.0;
                        $bt_fee    = (float) ( $bt->fee    ?? 0 ) / 100.0;
                        $bt_net    = (float) ( $bt->net    ?? 0 ) / 100.0;

                        if ( $bt_type === 'payout' ) {
                            // Authoritative payout net — store as positive value
                            $payout_net = abs( $bt_net );
                            continue;
                        }

                        if ( in_array( $bt_type, $charge_types, true ) ) {
                            $sum_charges_gross += $bt_amount; // charge gross (positive)
                            $sum_charges_fee   += $bt_fee;    // processing fee (positive)
                        } elseif ( $bt_type === 'refund' ) {
                            // Stripe refund btxns: amount is negative (returned from balance)
                            // fee is $0 (Stripe keeps the original processing fee)
                            $sum_refunds += abs( $bt_amount ); // store as positive deduction
                        } elseif ( in_array( $bt_type, [ 'stripe_fee', 'application_fee' ], true ) ) {
                            $sum_stripe_fees += abs( $bt_amount );
                        }
                        // Other types (disputes etc.) are edge cases — ignored for now
                    }

                    $has_more = (bool) ( $bt_page->has_more ?? false );
                    if ( ! $starting_after ) break;
                }

                // gross_total = charges gross (what donors were charged, pre-fee)
                // fee_total   = processing fees on charges (kept by Stripe)
                // net_total   = authoritative payout amount from Stripe
                // Note: refunds and stripe_fees are stored in deposit meta (adjustments),
                //       not in gross/fee/net — they are visible in the detail table.
                $gross_total = round( $sum_charges_gross, 2 );
                $fee_total   = round( $sum_charges_fee,   2 );
                // If we got the payout btxn, use it; otherwise derive
                $net_total   = $payout_net > 0
                    ? round( $payout_net, 2 )
                    : round( $sum_charges_gross - $sum_charges_fee - $sum_refunds - $sum_stripe_fees, 2 );

            } catch ( Exception $e ) {
                $errors[] = "Stripe fetch failed for deposit {$dep_code} (payout {$payout_id}).";
                Metis_Logger::error( 'Backfill: Stripe fetch failed', [ 'deposit' => $dep_code, 'payout' => $payout_id, 'error' => $e->getMessage() ] );
            }
        }

        // ── Attempt 2: sum from local transactions ────────────────────────────
        if ( $fee_total === null || $gross_total === null || $net_total === null ) {
            $sums_data = $db->fetchOne(
                "SELECT
                    SUM( amount + IFNULL(fee, 0) )  AS gross,
                    SUM( IFNULL(fee, 0) )           AS fees,
                    SUM( amount )                   AS net
                 FROM {$transactions_table}
                 WHERE deposit_batch_id = %s",
                [ $dep_code ]
            );
            $sums = is_array( $sums_data ) ? (object) $sums_data : null;

            if ( $sums && $sums->gross !== null ) {
                $fee_total   = round( (float) $sums->fees,  2 );
                $gross_total = round( (float) $sums->gross, 2 );
                $net_total   = round( (float) $sums->net,   2 );
                $local++;
            } else {
                $skipped++;
                $rows[] = [
                    'code'    => $dep_code,
                    'status'  => 'skipped',
                    'reason'  => 'No payout_id in meta and no linked transactions found',
                    'before'  => null,
                    'after'   => null,
                    'linked'  => 0,
                ];
                continue;
            }
        }

        // ── Write totals back ─────────────────────────────────────────────────
        $db->update(
            $deposits_table,
            [
                'fee_total'   => $fee_total,
                'gross_total' => $gross_total,
                'net_total'   => $net_total,
                'updated_at'  => metis_current_time( 'mysql' ),
            ],
            [ 'id' => (int) $dep->id ]
        );

        $filled++;
        Metis_Logger::info( 'Backfill: deposit totals updated', [ 'deposit' => $dep_code, 'gross' => $gross_total, 'fees' => $fee_total, 'net' => $net_total ] );

        // ── Link orphaned transactions ────────────────────────────────────────
        $rows_linked_here = 0;
        if ( $payout_id ) {
            $prepared = $db->prepare(
                "UPDATE {$transactions_table}
                 SET    deposit_batch_id = %s,
                        deposit_date     = deposit_date
                 WHERE  stripe_payout_id = %s
                   AND  ( deposit_batch_id IS NULL OR deposit_batch_id = '' )",
                $dep_code,
                $payout_id
            );
            $rows_linked_result = $db->execute( $prepared );
            if ( $rows_linked_result ) {
                $rows_linked_here = (int) $rows_linked_result;
                $linked += $rows_linked_here;
            }
        }

        $source = ( $payout_id && $fee_total !== null ) ? 'stripe' : 'local';
        $rows[] = [
            'code'   => $dep_code,
            'status' => $source === 'stripe' ? 'filled_stripe' : 'filled_local',
            'source' => $source,
            'before' => [
                'gross' => $before->gross_total !== null ? (float) $before->gross_total : null,
                'fees'  => $before->fee_total   !== null ? (float) $before->fee_total   : null,
                'net'   => $before->net_total   !== null ? (float) $before->net_total   : null,
            ],
            'after'  => [
                'gross' => $gross_total,
                'fees'  => $fee_total,
                'net'   => $net_total,
            ],
            'linked' => $rows_linked_here,
        ];
    }

    metis_reports_clear_cache();
    metis_portal_dashboard_forget_all();

    metis_runtime_send_json_success( [
        'filled'  => $filled,
        'linked'  => $linked,
        'local'   => $local,
        'skipped' => $skipped,
        'errors'  => $errors,
        'rows'    => $rows,
    ] );
}

// =========================================================================
// metis_ajax_link_stripe_payouts
//
// Historical backfill: for every Stripe transaction that has stripe_pay_int
// but no stripe_payout_id, walk the chain:
//
//   PaymentIntent (pi_xxx)
//     → latest_charge.id        → stored as stripe_charge_id
//     → latest_charge.balance_transaction → stored as stripe_balance_txn
//       → BalanceTransaction.payout (po_xxx) → stored as stripe_payout_id
//         → match or create deposit record
//         → set deposit_batch_id, deposit_date
// =========================================================================

// =========================================================================
// metis_ajax_backfill_deposit_adjustments
//
// For all Stripe deposits with a payout_id, fetch non-charge balance
// transactions (refunds, stripe_fee, etc.) and store them as 'adjustments'
// in the deposit meta. The deposit detail page renders these as named rows.
//
// Safe to re-run — overwrites adjustments on each run.
// =========================================================================

function metis_ajax_backfill_deposit_adjustments(): void {
    metis_donations_deposits_ajax_verify( 'metis_backfill_deposit_adjustments' );

    $stripe = metis_donations_stripe_client_or_error();

    $db = metis_db();
    $deposits_table = Metis_Tables::get( 'deposits' );

    $updated = 0;
    $skipped = 0;
    $errors  = [];
    $rows    = [];

    $deposits = array_map(
        static fn( array $row ): object => (object) $row,
        $db->fetchAll(
        "SELECT id, provider_ref, meta FROM {$deposits_table}
         WHERE provider = 'stripe' AND meta LIKE '%payout_id%'
         ORDER BY deposit_date DESC"
        )
    );

    foreach ( $deposits as $dep ) {

        $meta      = json_decode( (string) $dep->meta, true ) ?: [];
        $payout_id = $meta['payout_id'] ?? '';

        if ( ! $payout_id ) { $skipped++; continue; }

        try {
            $adjustments    = [];
            $params         = [ 'payout' => $payout_id, 'limit' => 100 ];
            $starting_after = null;
            $has_more       = true;

            while ( $has_more ) {
                if ( $starting_after ) $params['starting_after'] = $starting_after;
                else unset( $params['starting_after'] );

                $bt_page = $stripe->listBalanceTransactions( $params );

                foreach ( $bt_page->data as $bt ) {
                    $starting_after = $bt->id;
                    $type = (string) ( $bt->type ?? '' );
                    // Skip charges (those are our transactions) and the payout itself
                    if ( $type === 'charge' || $type === 'payout' ) continue;

                    $adjustments[] = [
                        'btxn'        => (string) $bt->id,
                        'type'        => $type,
                        'amount_cents' => (int) ( $bt->net ?? 0 ), // net in cents (negative = deduction)
                        'description' => (string) ( $bt->description ?? '' ),
                    ];
                }

                $has_more = (bool) ( $bt_page->has_more ?? false );
                if ( ! $starting_after ) break;
            }

            // Write adjustments back into meta (preserve existing keys)
            $meta['adjustments'] = $adjustments;
            $db->update(
                $deposits_table,
                [ 'meta' => metis_json_encode( $meta ), 'updated_at' => metis_current_time( 'mysql' ) ],
                [ 'id'   => (int) $dep->id ]
            );

            // ── Mark refunded transactions + insert refund records ─────────────
            // For each refund adjustment, find the transaction by its charge_id,
            // mark it Refunded, and insert a transaction_refunds record if not present.
            $refunds_marked   = 0;
            $refund_ids_found = []; // track charge_ids that have refunds (to filter adjustments)
            $tx_table         = Metis_Tables::get( 'transactions' );
            $refunds_table    = Metis_Tables::get( 'transaction_refunds' );

            foreach ( $adjustments as &$adj ) {
                if ( ( $adj['type'] ?? '' ) !== 'refund' ) continue;

                try {
                    $bt        = $stripe->retrieveBalanceTransaction( (string) $adj['btxn'], [ 'expand' => [ 'source' ] ] );
                    $charge_id = (string) ( $bt->source->charge ?? '' );
                    $refund_id = (string) ( $bt->source->id     ?? '' );

                    if ( ! $charge_id ) continue;

                    $refund_ids_found[] = $charge_id;

                    // Find the local transaction
                    $tx_data = $db->fetchOne(
                        "SELECT tid, tran_date, amount FROM {$tx_table} WHERE stripe_charge_id = %s LIMIT 1",
                        [ $charge_id ]
                    );
                    $tx = is_array( $tx_data ) ? (object) $tx_data : null;

                    if ( ! $tx ) continue;

                    // Mark transaction as Refunded
                    $db->update(
                        $tx_table,
                        [ 'status' => 'Refunded', 'stripe_refund_id' => $refund_id ?: null, 'updated_at' => metis_current_time( 'mysql' ) ],
                        [ 'stripe_charge_id' => $charge_id ]
                    );

                    // Store charge_id on adj for deposit detail filtering
                    $adj['charge_id'] = $charge_id;

                    // Insert transaction_refunds record if not already present
                    $existing_refund = $refund_id ? $db->scalar(
                        "SELECT id FROM {$refunds_table} WHERE stripe_refund_id = %s LIMIT 1",
                        [ $refund_id ]
                    ) : null;

                    if ( ! $existing_refund ) {
                        $refund_amount = abs( (float) $adj['amount_cents'] / 100.0 );
                        $db->insert( $refunds_table, [
                            'tid'              => $tx->tid,
                            'refund_date'      => metis_current_time( 'Y-m-d' ),
                            'stripe_refund_id' => $refund_id ?: null,
                            'amount'           => $refund_amount,
                            'reason'           => $adj['description'] ?? null,
                            'source'           => 'stripe',
                            'refunded_by'      => function_exists( 'metis_current_user_id' ) ? metis_current_user_id() : null,
                            'created_at'       => metis_current_time( 'mysql' ),
                        ] );
                        Metis_Logger::info( 'Adjustments backfill: refund record inserted', [ 'tid' => $tx->tid, 'refund' => $refund_id, 'amount' => $refund_amount ] );

                        if ( function_exists( 'finance_event_create' ) ) {
                            finance_event_create( [
                                'event_type'   => 'stripe_refund',
                                'provider'     => 'stripe',
                                'reference_id' => $refund_id ?: ( $tx->tid . ':refund:' . (string) $adj['btxn'] ),
                                'amount'       => $refund_amount,
                                'currency'     => 'usd',
                                'notes'        => (string) ( $adj['description'] ?? 'Stripe refund' ),
                                'occurred_at'  => ! empty( $tx->tran_date ) ? (string) $tx->tran_date : metis_current_time( 'mysql' ),
                                'metadata_json'=> [
                                    'tid'              => (string) $tx->tid,
                                    'stripe_charge_id' => $charge_id,
                                    'stripe_refund_id' => $refund_id,
                                    'balance_txn_id'   => (string) $adj['btxn'],
                                    'source'           => 'deposit_adjustment_backfill',
                                ],
                            ] );
                        }
                    }

                    $refunds_marked++;

                } catch ( \Exception $e ) {
                    $errors[] = "Refund mark failed for btxn {$adj['btxn']}.";
                }
            }
            unset( $adj );

            // ── Filter out `payment` adjustments already covered by a linked transaction ──
            // Stripe sometimes returns Link/ACH payments as type=payment instead of charge.
            // If a transaction with the same net amount already exists for this deposit, exclude it.
            $adjustments = array_filter( $adjustments, function( $adj ) use ( $db, $tx_table, $dep ) {
                if ( ( $adj['type'] ?? '' ) !== 'payment' ) return true;
                $adj_net = (int) ( $adj['amount_cents'] ?? 0 );
                // Check if any linked transaction's payout amount (in cents) matches
                $exists = $db->scalar(
                    "SELECT COUNT(*) FROM {$tx_table}
                     WHERE deposit_batch_id = %s
                       AND ROUND( payout * 100 ) = %d",
                    [ $dep->provider_ref, $adj_net ]
                );
                return ! ( $exists > 0 );
            } );
            $adjustments = array_values( $adjustments );

            $updated++;
            $rows[] = [
                'deposit'        => $dep->provider_ref,
                'adjustments'    => count( $adjustments ),
                'refunds_marked' => $refunds_marked,
                'items'          => $adjustments,
            ];

        } catch ( \Exception $e ) {
            $errors[] = "Failed for {$dep->provider_ref}.";
        }
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success( [
        'updated' => $updated,
        'skipped' => $skipped,
        'errors'  => $errors,
        'rows'    => $rows,
    ] );
}

function metis_ajax_link_stripe_payouts(): void {
    metis_donations_deposits_ajax_verify( 'metis_link_stripe_payouts' );

    $stripe = metis_donations_stripe_client_or_error();

    $db             = metis_db();
    $tx_table       = Metis_Tables::get( 'transactions' );
    $deposits_table = Metis_Tables::get( 'deposits' );

    $linked         = 0;  // transactions successfully linked to a deposit
    $deposits_made  = 0;  // new deposit records auto-created
    $skipped        = 0;  // transactions skipped (no PI, or Stripe error)
    $errors         = [];
    $rows           = []; // per-transaction result for the UI

    // Find all Stripe transactions with a payment intent but no payout ID yet
    $transactions = array_map(
        static fn( array $row ): object => (object) $row,
        $db->fetchAll(
        "SELECT id, tid, stripe_pay_int, stripe_charge_id, stripe_balance_txn, stripe_payout_id, deposit_batch_id, tran_date
         FROM {$tx_table}
         WHERE platform = 'ST'
           AND stripe_pay_int  IS NOT NULL
           AND stripe_pay_int  <> ''
           AND ( stripe_payout_id IS NULL OR stripe_payout_id = '' )
         ORDER BY tran_date ASC"
        )
    );

    if ( empty( $transactions ) ) {
        metis_runtime_send_json_success( [
            'linked'        => 0,
            'deposits_made' => 0,
            'skipped'       => 0,
            'rows'          => [],
            'message'       => 'All Stripe transactions are already linked to payouts.',
        ] );
        return;
    }

    // Cache payout → deposit_code lookups to avoid repeat Stripe + DB calls
    $payout_cache = []; // po_xxx => deposit_code

    foreach ( $transactions as $tx ) {

        $pi_id = (string) $tx->stripe_pay_int;

        $row_result = [
            'tid'        => $tx->tid,
            'pi'         => $pi_id,
            'charge_id'  => null,
            'balance_txn'=> null,
            'payout_id'  => null,
            'deposit'    => null,
            'status'     => 'error',
            'note'       => '',
        ];

        try {

            // ── Step 1: Resolve charge + balance transaction from PaymentIntent ───

            $charge_id   = (string) ( $tx->stripe_charge_id    ?? '' );
            $balance_txn = (string) ( $tx->stripe_balance_txn  ?? '' );

            // Edge case: old code stored a payout-like ID in stripe_charge_id by mistake.
            // po_xxx = real payout ID → try deposit match directly.
            // py_xxx = legacy Stripe payment ID format → fall through to date-match.
            if ( str_starts_with( $charge_id, 'po_' ) ) {
                $payout_id               = $charge_id;
                $row_result['charge_id'] = $charge_id;
                $row_result['payout_id'] = $payout_id;
                goto deposit_match;
            }

            // py_xxx in charge_id is stale — clear it so we re-fetch from Stripe
            if ( str_starts_with( $charge_id, 'py_' ) ) {
                $charge_id   = '';
                $balance_txn = '';
            }

            if ( $charge_id === '' || $balance_txn === '' ) {
                // Fetch from Stripe
                $pi = $stripe->retrievePaymentIntent( $pi_id, [ 'expand' => [ 'latest_charge' ] ] );

                $charge    = $pi->latest_charge ?? null;
                $charge_id = $charge ? (string) $charge->id : '';

                if ( $charge_id === '' ) {
                    $row_result['note']   = 'No charge on PaymentIntent';
                    $row_result['status'] = 'skipped';
                    $rows[]   = $row_result;
                    $skipped++;
                    continue;
                }

                $balance_txn = $charge->balance_transaction
                    ? ( is_string( $charge->balance_transaction )
                        ? $charge->balance_transaction
                        : (string) $charge->balance_transaction->id )
                    : '';

                if ( $balance_txn === '' ) {
                    $row_result['note']   = 'No balance_transaction on charge';
                    $row_result['status'] = 'skipped';
                    $rows[]   = $row_result;
                    $skipped++;
                    continue;
                }

                // Persist charge + balance txn immediately
                $db->update(
                    $tx_table,
                    [ 'stripe_charge_id' => $charge_id, 'stripe_balance_txn' => $balance_txn ],
                    [ 'id' => (int) $tx->id ]
                );
            }

            $row_result['charge_id']   = $charge_id;
            $row_result['balance_txn'] = $balance_txn;

            // ── Step 2: Resolve payout ID from BalanceTransaction ──────────────

            $bt = $stripe->retrieveBalanceTransaction( $balance_txn );

            $payout_id = '';
            if ( ! empty( $bt->payout ) ) {
                $payout_id = is_string( $bt->payout )
                    ? $bt->payout
                    : (string) $bt->payout->id;
            }

            // If payout field is absent (GiveButter connected-app charges don't set it),
            // fall back to date matching: find the deposit whose arrival_date equals
            // this balance transaction's available_on date.
            if ( $payout_id === '' ) {
                $available_on = isset( $bt->available_on )
                    ? gmdate( 'Y-m-d', (int) $bt->available_on )
                    : null;

                if ( $available_on ) {
                    $window_end = gmdate( 'Y-m-d', strtotime( $available_on . ' +10 days' ) );
                    $date_match = $db->fetchOne(
                        "SELECT id, provider_ref FROM {$deposits_table}
                         WHERE provider = 'stripe'
                           AND deposit_date >= %s
                           AND deposit_date <= %s
                         ORDER BY deposit_date ASC LIMIT 1",
                        [ $available_on, $window_end ]
                    );

                    if ( $date_match ) {
                        $payout_id                  = 'date:' . $available_on;
                        $payout_cache[ $payout_id ] = (string) $date_match['provider_ref'];
                        $row_result['payout_id']    = $available_on . ' (date match)';
                        Metis_Logger::info( 'Link payouts: date-matched transaction', [ 'tid' => $tx->tid, 'available_on' => $available_on, 'deposit' => $date_match['provider_ref'] ] );
                    } else {
                        $row_result['note']   = 'No payout field and no deposit found for available_on ' . $available_on . ' — run Sync Deposits first';
                        $row_result['status'] = 'skipped';
                        $rows[]   = $row_result;
                        $skipped++;
                        continue;
                    }
                } else {
                    $row_result['note']   = 'No payout field on BalanceTransaction';
                    $row_result['status'] = 'skipped';
                    $rows[]   = $row_result;
                    $skipped++;
                    continue;
                }
            }

            $row_result['payout_id'] = $payout_id;

            // ── Step 3: Match or create deposit record ─────────────────────────
            deposit_match:

            if ( isset( $payout_cache[ $payout_id ] ) ) {
                $deposit_code = $payout_cache[ $payout_id ];
            } else {
                $existing = $db->fetchOne(
                    "SELECT id, provider_ref FROM {$deposits_table}
                     WHERE provider = 'stripe'
                       AND ( provider_ref = %s OR meta LIKE %s )
                     LIMIT 1",
                    [ $payout_id, '%' . $db->escapeLike( '"payout_id":"' . $payout_id . '"' ) . '%' ]
                );

                if ( $existing ) {
                    $deposit_code = (string) $existing['provider_ref'];
                } else {
                    $payout_obj   = $stripe->retrievePayout( $payout_id );
                    $arrival_date = ! empty( $payout_obj->arrival_date )
                        ? gmdate( 'Y-m-d', (int) $payout_obj->arrival_date )
                        : gmdate( 'Y-m-d', (int) $payout_obj->created );
                    $net_amount   = (float) ( $payout_obj->amount ?? 0 ) / 100.0;
                    $currency     = strtolower( (string) ( $payout_obj->currency ?? 'usd' ) );
                    $po_status    = (string) ( $payout_obj->status ?? 'paid' );

                    $meta = metis_json_encode( [
                        'payout_id'     => $payout_id,
                        'generated_via' => 'link-payouts-backfill',
                        'arrival_date'  => $arrival_date,
                    ] );

                    $deposit_payload = [
                        'provider'          => 'stripe',
                        'deposit_type'      => 'stripe',
                        'source'            => 'automatic',
                        'status'            => $po_status === 'paid' ? 'deposited' : $po_status,
                        'deposit_date'      => $arrival_date,
                        'expected_date'     => $arrival_date,
                        'total_amount'      => $net_amount,
                        'currency'          => $currency,
                        'batch_count'       => 1,
                        'transaction_count' => 0,
                        'meta'              => $meta,
                        'created_at'        => metis_current_time( 'mysql' ),
                        'updated_at'        => metis_current_time( 'mysql' ),
                    ];
                    if ( function_exists( 'metis_entity_id_service' ) ) {
                        $deposit_payload = metis_entity_id_service()->assignForInsert( 'donation_deposit', $deposit_payload );
                    } else {
                        $deposit_payload['provider_ref'] = metis_generate_code( 'DP', $deposits_table, 'provider_ref' );
                    }
                    $deposit_code = (string) ( $deposit_payload['deposit_uid'] ?? $deposit_payload['provider_ref'] ?? '' );

                    $db->insert( $deposits_table, $deposit_payload );
                    if ( $db->lastInsertId() > 0 && function_exists( 'metis_entity_id_service' ) ) {
                        metis_entity_id_service()->register( 'donation_deposit', $db->lastInsertId(), $deposit_code );
                    }

                    $deposits_made++;
                    Metis_Logger::info( 'Link payouts: deposit auto-created', [ 'code' => $deposit_code, 'payout' => $payout_id ] );
                }

                $payout_cache[ $payout_id ] = $deposit_code;
            }

            $row_result['deposit'] = $deposit_code;

            // ── Step 4: Write deposit link back to transaction ────────────────
            $real_payout_id = str_starts_with( $payout_id, 'date:' ) ? null : $payout_id;

            $update_data = [
                'deposit_batch_id' => $deposit_code,
                'deposit_date'     => metis_current_time( 'mysql' ),
            ];
            if ( $real_payout_id ) {
                $update_data['stripe_payout_id'] = $real_payout_id;
            }

            $db->update( $tx_table, $update_data, [ 'id' => (int) $tx->id ] );

            $linked++;
            $row_result['status'] = 'linked';
            Metis_Logger::info( 'Link payouts: transaction linked', [ 'tid' => $tx->tid, 'payout' => $payout_id, 'deposit' => $deposit_code ] );

        } catch ( \Exception $e ) {
            $errors[]             = "TX {$tx->tid}: Stripe API request failed.";
            $row_result['note']   = 'Stripe API request failed.';
            $row_result['status'] = 'error';
            Metis_Logger::error( 'Link payouts: Stripe API error', [ 'tid' => $tx->tid, 'pi' => $pi_id, 'error' => $e->getMessage() ] );
        }

        $rows[] = $row_result;
    }

    // Update transaction_count on any newly-created deposits
    if ( $deposits_made > 0 ) {
        foreach ( array_unique( array_values( $payout_cache ) ) as $dep_code ) {
            $count = (int) $db->scalar(
                "SELECT COUNT(*) FROM {$tx_table} WHERE deposit_batch_id = %s",
                [ $dep_code ]
            );
            if ( $count > 0 ) {
                $db->update(
                    $deposits_table,
                    [ 'transaction_count' => $count, 'updated_at' => metis_current_time( 'mysql' ) ],
                    [ 'provider_ref' => $dep_code ]
                );
            }
        }
    }

    metis_reports_clear_cache();
    metis_portal_dashboard_forget_all();

    metis_runtime_send_json_success( [
        'linked'        => $linked,
        'deposits_made' => $deposits_made,
        'skipped'       => $skipped,
        'errors'        => $errors,
        'rows'          => $rows,
    ] );
}

// =========================================================================
// metis_ajax_verify_deposit_links
//
// Authoritative verification: for each Stripe deposit with a po_xxx payout ID,
// fetch the exact list of balance transactions from Stripe that belong to that
// payout, then match them to local transactions by stripe_charge_id or
// stripe_balance_txn and write the correct deposit_batch_id.
// =========================================================================

function metis_ajax_verify_deposit_links(): void {
    metis_donations_deposits_ajax_verify( 'metis_verify_deposit_links' );

    $stripe = metis_donations_stripe_client_or_error();

    $db             = metis_db();
    $tx_table       = Metis_Tables::get( 'transactions' );
    $deposits_table = Metis_Tables::get( 'deposits' );

    $verified   = 0;
    $corrected  = 0;
    $linked     = 0;
    $unmatched  = 0;
    $skipped    = 0;
    $errors     = [];
    $rows       = [];

    $deposits = array_map(
        static fn( array $row ): object => (object) $row,
        $db->fetchAll(
        "SELECT id, provider_ref, deposit_date, meta
         FROM {$deposits_table}
         WHERE provider = 'stripe'
         ORDER BY deposit_date ASC"
        )
    );

    if ( empty( $deposits ) ) {
        metis_runtime_send_json_success( [ 'message' => 'No Stripe deposits found. Run Sync Stripe Deposits first.', 'verified' => 0, 'corrected' => 0, 'linked' => 0, 'rows' => [] ] );
        return;
    }

    foreach ( $deposits as $dep ) {

        $dep_code  = $dep->provider_ref;
        $meta      = json_decode( (string) $dep->meta, true );
        $payout_id = is_array( $meta ) ? ( $meta['payout_id'] ?? '' ) : '';

        if ( ! $payout_id || ! str_starts_with( $payout_id, 'po_' ) ) {
            $skipped++;
            continue;
        }

        $stripe_btxns = [];

        try {
            $params         = [ 'payout' => $payout_id, 'limit' => 100, 'expand' => [ 'data.source' ] ];
            $starting_after = null;

            while ( true ) {
                if ( $starting_after ) $params['starting_after'] = $starting_after;
                else unset( $params['starting_after'] );

                $page = $stripe->listBalanceTransactions( $params );

                foreach ( $page->data as $bt ) {
                    if ( ( $bt->type ?? '' ) !== 'charge' ) continue;

                    $charge_id   = '';
                    $balance_txn = (string) $bt->id;

                    if ( ! empty( $bt->source ) ) {
                        $src       = $bt->source;
                        $charge_id = is_string( $src ) ? $src : (string) ( $src->id ?? '' );
                    }

                    $stripe_btxns[] = [
                        'btxn'      => $balance_txn,
                        'charge_id' => $charge_id,
                        'net'       => (float) ( $bt->net ?? 0 ) / 100.0,
                    ];

                    $starting_after = $bt->id;
                }

                if ( ! $page->has_more ) break;
            }

        } catch ( \Exception $e ) {
            $errors[] = "Stripe fetch failed for deposit {$dep_code} (payout {$payout_id}).";
            Metis_Logger::error( 'Verify links: Stripe fetch failed', [ 'deposit' => $dep_code, 'payout' => $payout_id, 'error' => $e->getMessage() ] );
            continue;
        }

        if ( empty( $stripe_btxns ) ) {
            Metis_Logger::info( 'Verify links: no charge-type btxns in payout', [ 'deposit' => $dep_code, 'payout' => $payout_id ] );
            continue;
        }

        foreach ( $stripe_btxns as $sbt ) {

            $local_tx = null;

            if ( $sbt['btxn'] ) {
                $local_tx = $db->fetchOne(
                    "SELECT id, tid, deposit_batch_id FROM {$tx_table}
                     WHERE stripe_balance_txn = %s LIMIT 1",
                    [ $sbt['btxn'] ]
                );
            }

            if ( ! $local_tx && $sbt['charge_id'] ) {
                $local_tx = $db->fetchOne(
                    "SELECT id, tid, deposit_batch_id FROM {$tx_table}
                     WHERE stripe_charge_id = %s LIMIT 1",
                    [ $sbt['charge_id'] ]
                );
            }

            if ( ! $local_tx ) {
                $unmatched++;
                $rows[] = [
                    'tid'       => null,
                    'btxn'      => $sbt['btxn'],
                    'charge_id' => $sbt['charge_id'],
                    'deposit'   => $dep_code,
                    'was'       => null,
                    'status'    => 'unmatched',
                    'note'      => 'No local transaction found for this Stripe charge',
                ];
                continue;
            }

            $current_deposit = (string) ( $local_tx['deposit_batch_id'] ?? '' );

            if ( $current_deposit === $dep_code ) {
                $verified++;
                $rows[] = [
                    'tid'     => $local_tx['tid'],
                    'btxn'    => $sbt['btxn'],
                    'deposit' => $dep_code,
                    'was'     => $dep_code,
                    'status'  => 'verified',
                    'note'    => '',
                ];
                continue;
            }

            $db->update(
                $tx_table,
                [
                    'deposit_batch_id'   => $dep_code,
                    'stripe_balance_txn' => $sbt['btxn']      ?: null,
                    'stripe_charge_id'   => $sbt['charge_id'] ?: null,
                    'deposit_date'       => $dep->deposit_date,
                ],
                [ 'id' => (int) $local_tx['id'] ]
            );

            if ( $current_deposit === '' || $current_deposit === null ) {
                $linked++;
                $status = 'linked';
            } else {
                $corrected++;
                $status = 'corrected';
            }

            $rows[] = [
                'tid'     => $local_tx['tid'],
                'btxn'    => $sbt['btxn'],
                'deposit' => $dep_code,
                'was'     => $current_deposit ?: null,
                'status'  => $status,
                'note'    => $status === 'corrected' ? "Was linked to {$current_deposit}" : '',
            ];

            Metis_Logger::info( 'Verify links: transaction ' . $status, [ 'tid' => $local_tx['tid'], 'deposit' => $dep_code, 'was' => $current_deposit ] );
        }
    }

    // Update transaction_count on all deposits
    foreach ( $deposits as $dep ) {
        $count = (int) $db->scalar(
            "SELECT COUNT(*) FROM {$tx_table} WHERE deposit_batch_id = %s",
            [ $dep->provider_ref ]
        );
        $db->update(
            $deposits_table,
            [ 'transaction_count' => $count, 'updated_at' => metis_current_time( 'mysql' ) ],
            [ 'id' => (int) $dep->id ]
        );
    }

    metis_reports_clear_cache();
    metis_portal_dashboard_forget_all();

    metis_runtime_send_json_success( [
        'verified'  => $verified,
        'corrected' => $corrected,
        'linked'    => $linked,
        'unmatched' => $unmatched,
        'skipped'   => $skipped,
        'errors'    => $errors,
        'rows'      => $rows,
    ] );
}

// ── Import Stripe Transactions ───────────────────────────────────────────────
// Iterates PaymentIntent::all() instead of Charge::all() so that Link payments
// (py_xxx charge IDs) are captured alongside standard card charges (ch_xxx).
//
// For each PI we pull latest_charge (expanded), which gives us the real charge
// object regardless of prefix. Idempotent: skips any stripe_charge_id OR
// stripe_pay_int already present in the transactions table.
//
// Platform: ST  |  Donor prefix: MW  |  Transaction prefix: TR

function metis_ajax_import_stripe_charges(): void {
    $request = function_exists( 'metis_request_post' ) ? (array) metis_request_post() : [];
    $action = (string) ( $request['metis_action'] ?? '' ) === 'metis_import_stripe_transactions'
        ? 'metis_import_stripe_transactions'
        : 'metis_import_stripe_charges';
    metis_donations_deposits_ajax_verify( $action );

    $stripe = metis_donations_stripe_client_or_error();

    $db       = metis_db();
    $tx_table  = Metis_Tables::get( 'transactions' );
    $ct_table  = Metis_Tables::get( 'contacts' );
    $dep_table = Metis_Tables::get( 'deposits' );
    $has_donations_module = class_exists( '\Metis\Modules\Donations\DonationsModule' );
    if ( $has_donations_module ) {
        \Metis\Modules\Donations\DonationsModule::ensureTransactionPaymentDetailSchema();
    }

    // ── Build po_xxx → deposit_code lookup ────────────────────────────────────
    $payout_to_deposit = [];
    foreach ( $db->fetchAll( "SELECT provider_ref, meta FROM {$dep_table} WHERE provider = 'stripe'" ) as $dr ) {
        $m = json_decode( $dr['meta'] ?? '', true );
        if ( ! empty( $m['payout_id'] ) ) {
            $payout_to_deposit[ $m['payout_id'] ] = $dr['provider_ref'];
        }
    }

    // Cache deposit_date values to avoid repeated DB queries
    $deposit_date_cache = [];

    // ── Build dedupe sets ─────────────────────────────────────────────────────
    // Skip if we already have a transaction with this charge ID (ch_ or py_)
    $existing_charge_ids = array_flip( $db->column(
        "SELECT stripe_charge_id FROM {$tx_table}
         WHERE stripe_charge_id IS NOT NULL AND stripe_charge_id <> ''"
    ) );

    // Also skip if we already have a transaction for this Payment Intent ID
    // (handles the case where the charge ID changed but the PI is the same)
    $existing_pi_ids = array_flip( $db->column(
        "SELECT stripe_pay_int FROM {$tx_table}
         WHERE stripe_pay_int IS NOT NULL AND stripe_pay_int <> ''"
    ) );

    // ── Build email → did lookup ──────────────────────────────────────────────
    $email_to_did = [];
    foreach ( $db->fetchAll( "SELECT did, email FROM {$ct_table} WHERE email IS NOT NULL AND email <> ''" ) as $cr ) {
        $key = strtolower( trim( (string) $cr['email'] ) );
        $email_to_did[ $key ]                = $cr['did'];
        $email_to_did[ trim( (string) $cr['email'] ) ] = $cr['did']; // also index raw case
    }

    $imported   = 0;
    $skipped    = 0;
    $new_donors = 0;
    $errors     = [];
    $rows       = [];

    // ── Paginate through ALL PaymentIntents ───────────────────────────────────
    // Expand latest_charge and its balance_transaction in one API call.
    $params   = [
        'limit'  => 100,
        'expand' => [
            'data.latest_charge',
            'data.latest_charge.balance_transaction',
        ],
    ];
    $has_more = true;

    while ( $has_more ) {

        try {
            $response = $stripe->listPaymentIntents( $params );
        } catch ( \Exception $e ) {
            if ( class_exists( 'Metis_Logger' ) ) {
                Metis_Logger::warn( 'donations.payment_intents_sync_failed', [ 'error' => $e->getMessage() ] );
            }
            metis_runtime_send_json_error( [ 'message' => 'Stripe API request failed. Please try again.' ] );
            return;
        }

        foreach ( $response->data as $pi ) {

            $pi_id = (string) $pi->id;

            // Only process succeeded PIs with a charge
            if ( $pi->status !== 'succeeded' ) {
                $skipped++;
                continue;
            }

            $charge = $pi->latest_charge ?? null;
            if ( ! $charge || ! isset( $charge->id ) ) {
                $skipped++;
                continue;
            }

            $charge_id = (string) $charge->id;

            // ── Dedupe: skip if charge ID or PI already in DB ──────────────────
            if ( isset( $existing_charge_ids[ $charge_id ] ) || isset( $existing_pi_ids[ $pi_id ] ) ) {
                if ( $has_donations_module ) {
                    $existing_tx_id = (int) $db->scalar(
                        "SELECT id FROM {$tx_table}
                         WHERE stripe_charge_id = %s OR stripe_pay_int = %s
                         LIMIT 1",
                        [ $charge_id, $pi_id ]
                    );
                    if ( $existing_tx_id > 0 ) {
                        $method_details = \Metis\Modules\Donations\DonationsModule::stripePaymentMethodDetails( $charge );
                        $db->update( $tx_table, [
                            'payment_method' => (string) ( $method_details['payment_method'] ?? 'cc' ),
                            'card_brand'     => $method_details['card_brand'] ?? null,
                            'card_last4'     => $method_details['card_last4'] ?? null,
                            'updated_at'     => metis_current_time( 'mysql' ),
                        ], [ 'id' => $existing_tx_id ] );
                    }
                }
                $skipped++;
                continue;
            }

            // ── Extract fields from charge ─────────────────────────────────────
            $email      = strtolower( trim( (string) ( $charge->billing_details->email ?? $charge->receipt_email ?? '' ) ) );
            $name_raw   = (string) ( $charge->billing_details->name ?? '' );
            $name_parts = array_pad( explode( ' ', trim( $name_raw ), 2 ), 2, '' );
            $first_name = trim( $name_parts[0] );
            $last_name  = trim( $name_parts[1] );

            $amount_cents = (int) $charge->amount;
            $fee_cents    = 0;
            $bt_id        = null;
            $payout_id    = null;
            $tran_date    = gmdate( 'Y-m-d H:i:s', (int) $charge->created );
            $currency     = strtolower( (string) ( $charge->currency ?? 'usd' ) );

            $bt = $charge->balance_transaction ?? null;
            if ( $bt && is_object( $bt ) && isset( $bt->id ) ) {
                $bt_id     = (string) $bt->id;
                $fee_cents = (int) ( $bt->fee ?? 0 );
                $payout_id = ! empty( $bt->payout ) ? (string) $bt->payout : null;
            }

            $amount_dollars = $amount_cents / 100.0;
            $fee_dollars    = $fee_cents    / 100.0;
            $net_dollars    = ( $amount_cents - $fee_cents ) / 100.0;

            // ── Detect payment method ──────────────────────────────────────────
            if ( $has_donations_module ) {
                $method_details = \Metis\Modules\Donations\DonationsModule::stripePaymentMethodDetails( $charge );
            } else {
                $method_details = [ 'payment_method' => 'cc', 'card_brand' => null, 'card_last4' => null ];
            }
            $pay_method = (string) ( $method_details['payment_method'] ?? 'cc' );

            // ── Resolve or create contact ──────────────────────────────────────
            $did          = null;
            $donor_status = 'existing';

            if ( $email ) {
                $did = $email_to_did[ $email ] ?? $email_to_did[ strtolower( $email ) ] ?? null;
            }

            if ( $email && ! $did ) {
                // Contact might exist but have no did (null stored in cache) — look up directly
                $existing_ct = $db->fetchOne(
                    "SELECT id, did FROM {$ct_table} WHERE email = %s LIMIT 1",
                    [ $email ]
                );

                if ( $existing_ct ) {
                    // Contact exists — assign a did if missing
                    if ( ! empty( $existing_ct['did'] ) ) {
                        $did = $existing_ct['did'];
                    } else {
                        $did = metis_generate_code( 'MW', $ct_table, 'did' );
                        $db->update( $ct_table, [ 'did' => $did ], [ 'id' => (int) $existing_ct['id'] ] );
                        Metis_Logger::info( 'Stripe import: did assigned to existing contact', [ 'did' => $did, 'email' => $email ] );
                    }
                    $email_to_did[ $email ]              = $did;
                    $email_to_did[ strtolower( $email ) ] = $did;
                } else {
                    // Truly new contact
                    $did = metis_generate_code( 'MW', $ct_table, 'did' );
                    $ok  = $db->insert( $ct_table, [
                        'did'        => $did,
                        'first_name' => $first_name ?: null,
                        'last_name'  => $last_name  ?: null,
                        'email'      => $email,
                        'created_at' => metis_current_time( 'mysql' ),
                        'updated_at' => metis_current_time( 'mysql' ),
                    ] );
                    if ( $ok ) {
                        $email_to_did[ $email ]              = $did;
                        $email_to_did[ strtolower( $email ) ] = $did;
                        $donor_status = 'created';
                        $new_donors++;
                        Metis_Logger::info( 'Stripe import: contact created', [ 'did' => $did, 'email' => $email ] );
                    } else {
                        $errors[] = "Contact insert failed for {$email}.";
                        $did = null;
                    }
                }
            }

            // ── Resolve deposit link ───────────────────────────────────────────
            $deposit_batch_id = null;
            $deposit_date     = null;

            if ( $payout_id && isset( $payout_to_deposit[ $payout_id ] ) ) {
                $deposit_batch_id = $payout_to_deposit[ $payout_id ];
                if ( ! isset( $deposit_date_cache[ $deposit_batch_id ] ) ) {
                    $deposit_date_cache[ $deposit_batch_id ] = $db->scalar(
                        "SELECT deposit_date FROM {$dep_table} WHERE provider_ref = %s",
                        [ $deposit_batch_id ]
                    ) ?: null;
                }
                $deposit_date = $deposit_date_cache[ $deposit_batch_id ];
            }

            // ── Insert transaction ─────────────────────────────────────────────
            $transaction_payload = [
                'did'                => $did,
                'tran_date'          => $tran_date,
                'amount'             => $net_dollars,
                'fee'                => $fee_dollars,
                'payout'             => $net_dollars,
                'platform'           => 'ST',
                'payment_method'     => $pay_method,
                'card_brand'         => $method_details['card_brand'] ?? null,
                'card_last4'         => $method_details['card_last4'] ?? null,
                'status'             => 'Completed',
                'stripe_pay_int'     => $pi_id,
                'stripe_charge_id'   => $charge_id,
                'stripe_balance_txn' => $bt_id     ?: null,
                'stripe_payout_id'   => $payout_id ?: null,
                'deposit_batch_id'   => $deposit_batch_id,
                'deposit_date'       => $deposit_date,
                'created_at'         => metis_current_time( 'mysql' ),
                'updated_at'         => metis_current_time( 'mysql' ),
            ];
            if ( function_exists( 'metis_entity_id_service' ) ) {
                $transaction_payload = metis_entity_id_service()->assignForInsert( 'donation_transaction', $transaction_payload );
            } else {
                $transaction_payload['tid'] = metis_generate_code( 'TR', $tx_table, 'tid' );
            }
            $tid = (string) ( $transaction_payload['transaction_uid'] ?? $transaction_payload['tid'] ?? '' );

            $inserted_tx = $db->insert( $tx_table, $transaction_payload );

            $amount_display = '$' . number_format( $amount_dollars, 2 );
            if ( $inserted_tx ) {
                if ( function_exists( 'metis_entity_id_service' ) ) {
                    metis_entity_id_service()->register( 'donation_transaction', $db->lastInsertId(), $tid );
                }
                $imported++;
                $existing_charge_ids[ $charge_id ] = true;
                $existing_pi_ids[ $pi_id ]         = true;
                $rows[] = [
                    'tid'          => $tid,
                    'charge_id'    => $charge_id,
                    'email'        => $email        ?: '—',
                    'did'          => $did          ?: '—',
                    'donor_status' => $donor_status,
                    'new_contact'  => $donor_status === 'created',
                    'amount'       => $amount_display,
                    'net'          => '$' . number_format( $net_dollars, 2 ),
                    'deposit'      => $deposit_batch_id ?: '—',
                    'date'         => date( 'm/d/y', strtotime( $tran_date ) ),
                    'status'       => 'imported',
                ];
                Metis_Logger::info( 'Stripe import: transaction created', [ 'tid' => $tid, 'pi' => $pi_id, 'charge' => $charge_id ] );
            } else {
                $errors[] = "TX insert failed for PI {$pi_id} / charge {$charge_id}.";
                $rows[] = [
                    'tid'          => '—',
                    'charge_id'    => $charge_id,
                    'email'        => $email        ?: '—',
                    'did'          => '—',
                    'donor_status' => $donor_status,
                    'new_contact'  => $donor_status === 'created',
                    'amount'       => $amount_display,
                    'net'          => '$' . number_format( $net_dollars, 2 ),
                    'deposit'      => '—',
                    'date'         => date( 'm/d/y', strtotime( $tran_date ) ),
                    'status'       => 'error',
                ];
            }
        }

        $has_more = $response->has_more;
        if ( $has_more ) {
            $params['starting_after'] = end( $response->data )->id;
        }
    }

    metis_reports_clear_cache();
    metis_portal_dashboard_forget_all();

    metis_runtime_send_json_success( [
        'imported'   => $imported,
        'skipped'    => $skipped,
        'contacts_made' => $new_donors,
        'new_donors' => $new_donors,
        'errors'     => $errors,
        'rows'       => $rows,
    ] );
}
