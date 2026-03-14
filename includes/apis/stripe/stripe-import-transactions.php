<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stripe Import Transactions
 *
 * Pulls historical PaymentIntents from Stripe one page at a time.
 * Client calls repeatedly with a cursor until done=true.
 *
 * Action: metis_import_stripe_transactions
 * Params: cursor (optional Stripe PI ID to start after)
 */

metis_add_action( 'wp_ajax_metis_import_stripe_transactions', 'metis_ajax_import_stripe_transactions' );

function metis_ajax_import_stripe_transactions(): void {

    check_ajax_referer( 'metis_donations', '_ajax_nonce' );

    if ( ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    if ( ! class_exists( '\Stripe\Stripe' ) ) {
        metis_send_json_error( [ 'message' => 'Stripe SDK not loaded.' ] );
    }

    $stripe_key = \Stripe\Stripe::getApiKey();
    if ( ! $stripe_key ) {
        metis_stripe_init();
        $stripe_key = \Stripe\Stripe::getApiKey();
    }
    if ( ! $stripe_key ) {
        metis_send_json_error( [ 'message' => 'Stripe secret key not configured.' ] );
    }

    global $wpdb;
    $contacts_table     = Metis_Tables::get( 'contacts' );
    $transactions_table = Metis_Tables::get( 'transactions' );
    $deposits_table     = Metis_Tables::get( 'deposits' );

    $cursor = isset( $_POST['cursor'] ) ? sanitize_text_field( $_POST['cursor'] ) : '';

    // ── Build indexes for this request ─────────────────────────────────────

    $existing_pi_set = array_flip( $wpdb->get_col(
        "SELECT stripe_pay_int FROM {$transactions_table}
         WHERE stripe_pay_int IS NOT NULL AND stripe_pay_int <> ''"
    ) );

    $existing_ch_set = array_flip( $wpdb->get_col(
        "SELECT stripe_charge_id FROM {$transactions_table}
         WHERE stripe_charge_id IS NOT NULL AND stripe_charge_id <> ''"
    ) );

    $email_to_did = [];
    foreach ( $wpdb->get_results( "SELECT did, email FROM {$contacts_table} WHERE email IS NOT NULL AND email <> ''", ARRAY_A ) as $row ) {
        $email_to_did[ strtolower( trim( $row['email'] ) ) ] = $row['did'];
    }

    $payout_to_deposit = [];
    foreach ( $wpdb->get_results( "SELECT provider_ref, meta FROM {$deposits_table} WHERE provider = 'stripe' AND meta IS NOT NULL", ARRAY_A ) as $dr ) {
        $m = json_decode( $dr['meta'], true );
        if ( ! empty( $m['payout_id'] ) ) {
            $payout_to_deposit[ $m['payout_id'] ] = $dr['provider_ref'];
        }
    }

    // ── Fetch one page of PaymentIntents ───────────────────────────────────

    $params = [
        'limit'  => 100,
        'expand' => [ 'data.latest_charge', 'data.latest_charge.balance_transaction' ],
    ];
    if ( $cursor ) {
        $params['starting_after'] = $cursor;
    }

    try {
        $page = \Stripe\PaymentIntent::all( $params );
    } catch ( \Exception $e ) {
        metis_send_json_error( [ 'message' => 'Stripe API error: ' . $e->getMessage() ] );
    }

    $imported      = 0;
    $skipped       = 0;
    $contacts_made = 0;
    $errors        = [];
    $rows          = [];
    $last_id       = '';

    foreach ( $page->data as $pi ) {

        $last_id = (string) $pi->id;

        // Only process succeeded payments
        if ( ( $pi->status ?? '' ) !== 'succeeded' ) {
            $skipped++;
            continue;
        }

        $pi_id     = (string) $pi->id;
        $charge    = $pi->latest_charge ?? null;
        $charge_id = $charge ? (string) $charge->id : '';

        // Skip already-imported
        if ( isset( $existing_pi_set[ $pi_id ] ) || ( $charge_id && isset( $existing_ch_set[ $charge_id ] ) ) ) {
            $skipped++;
            continue;
        }

        // ── Amounts ───────────────────────────────────────────────────────

        $btxn    = $charge ? $charge->balance_transaction : null;
        $btxn_id = $btxn ? (string) $btxn->id : '';

        $amount = round( (int) ( $btxn->net    ?? $pi->amount ?? 0 ) / 100, 2 );
        $fee    = round( (int) ( $btxn->fee    ?? 0 )                / 100, 2 );
        $gross  = round( (int) ( $btxn->amount ?? $pi->amount ?? 0 ) / 100, 2 );

        $tran_date = gmdate( 'Y-m-d H:i:s', (int) $pi->created );

        // ── Payout / deposit ──────────────────────────────────────────────

        $payout_id    = '';
        $deposit_code = '';
        if ( $btxn && ! empty( $btxn->payout ) ) {
            $payout_id = is_string( $btxn->payout ) ? $btxn->payout : ( $btxn->payout->id ?? '' );
        }
        if ( $payout_id && isset( $payout_to_deposit[ $payout_id ] ) ) {
            $deposit_code = $payout_to_deposit[ $payout_id ];
        }

        // ── Payment method ────────────────────────────────────────────────

        $pm_type = 'card';
        if ( $charge && ! empty( $charge->payment_method_details->type ) ) {
            $pm_type = (string) $charge->payment_method_details->type;
        }

        // ── Resolve donor ─────────────────────────────────────────────────

        $billing   = $charge ? ( $charge->billing_details ?? null ) : null;
        $raw_email = $billing ? ( $billing->email ?? '' ) : '';
        $raw_name  = $billing ? ( $billing->name  ?? '' ) : '';
        $email     = strtolower( trim( $raw_email ) );

        // did is NOT NULL — skip charges with no email
        if ( ! $email ) {
            $skipped++;
            continue;
        }

        $did         = '';
        $new_contact = false;

        if ( isset( $email_to_did[ $email ] ) ) {
            // Matched existing contact
            $did = $email_to_did[ $email ];

        } else {
            // Auto-create contact from Stripe billing data
            $name_parts = metis_split_name( $raw_name );
            $new_did    = metis_generate_code( 'MW', $contacts_table, 'did' );

            $inserted_contact = $wpdb->insert(
                $contacts_table,
                [
                    'did'        => $new_did,
                    'first_name' => $name_parts['first'],
                    'last_name'  => $name_parts['last'],
                    'email'      => $email,
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                ]
            );

            if ( $inserted_contact ) {
                $did                  = $new_did;
                $email_to_did[$email] = $did;
                $contacts_made++;
                $new_contact = true;
                Metis_Logger::info( 'Stripe import: contact created', [ 'did' => $did, 'email' => $email ] );

            } else {
                // Insert failed — probably duplicate email index. Fetch existing.
                $existing_did = $wpdb->get_var( $wpdb->prepare(
                    "SELECT did FROM {$contacts_table} WHERE email = %s LIMIT 1",
                    $email
                ) );
                if ( $existing_did ) {
                    $did                  = $existing_did;
                    $email_to_did[$email] = $did;
                } else {
                    $errors[] = "Contact unavailable for {$email}: " . $wpdb->last_error;
                }
            }
        }

        // Skip if we still have no did (contact creation fully failed)
        if ( ! $did ) {
            $skipped++;
            continue;
        }

        // ── Insert transaction ────────────────────────────────────────────

        $tid    = metis_generate_code( 'TR', $transactions_table, 'tid' );
        $result = $wpdb->insert(
            $transactions_table,
            [
                'tid'                => $tid,
                'did'                => $did,
                'tran_date'          => $tran_date,
                'amount'             => $amount,
                'fee'                => $fee,
                'payout'             => $amount,
                'platform'           => 'stripe',
                'payment_method'     => $pm_type,
                'status'             => 'completed',
                'stripe_pay_int'     => $pi_id,
                'stripe_charge_id'   => $charge_id  ?: null,
                'stripe_balance_txn' => $btxn_id    ?: null,
                'stripe_payout_id'   => $payout_id  ?: null,
                'deposit_batch_id'   => $deposit_code ?: null,
                'deposit_date'       => $deposit_code
                    ? gmdate( 'Y-m-d', (int) ( $btxn->available_on ?? $pi->created ) )
                    : null,
            ]
        );

        if ( $result ) {
            $imported++;
            $existing_pi_set[ $pi_id ] = true;
            if ( $charge_id ) $existing_ch_set[ $charge_id ] = true;

            if ( function_exists( 'finance_event_create' ) ) {
                finance_event_create( [
                    'event_type'   => 'stripe_charge',
                    'provider'     => 'stripe',
                    'reference_id' => $charge_id ?: $pi_id,
                    'amount'       => $gross > 0 ? $gross : $amount,
                    'currency'     => 'usd',
                    'notes'        => 'Imported from Stripe payment intent ' . $pi_id,
                    'occurred_at'  => $tran_date,
                    'metadata_json'=> [
                        'tid'                => $tid,
                        'did'                => $did,
                        'stripe_pay_int'     => $pi_id,
                        'stripe_charge_id'   => $charge_id,
                        'stripe_balance_txn' => $btxn_id,
                        'stripe_payout_id'   => $payout_id,
                        'deposit_batch_id'   => $deposit_code,
                        'payment_method'     => $pm_type,
                        'source'             => 'stripe_import',
                    ],
                ] );

                if ( $fee > 0 ) {
                    finance_event_create( [
                        'event_type'   => 'stripe_fee',
                        'provider'     => 'stripe',
                        'reference_id' => $btxn_id ?: ( $charge_id ?: $pi_id ) . ':fee',
                        'amount'       => $fee,
                        'currency'     => 'usd',
                        'notes'        => 'Imported Stripe fee for ' . $pi_id,
                        'occurred_at'  => $tran_date,
                        'metadata_json'=> [
                            'tid'              => $tid,
                            'stripe_pay_int'   => $pi_id,
                            'stripe_charge_id' => $charge_id,
                            'source'           => 'stripe_import',
                        ],
                    ] );
                }
            }

            $rows[] = [
                'tid'         => $tid,
                'email'       => $email,
                'did'         => $did,
                'amount'      => '$' . number_format( $gross,  2 ),
                'net'         => '$' . number_format( $amount, 2 ),
                'date'        => gmdate( 'Y-m-d', (int) $pi->created ),
                'deposit'     => $deposit_code ?: '—',
                'new_contact' => $new_contact,
                'status'      => 'imported',
            ];

            Metis_Logger::info( 'Stripe import: transaction created', [
                'tid' => $tid, 'pi' => $pi_id, 'email' => $email,
            ] );

        } else {
            $errors[] = "Insert failed for PI {$pi_id}: " . $wpdb->last_error;
            $rows[] = [
                'tid'         => '—',
                'email'       => $email,
                'did'         => $did,
                'amount'      => '$' . number_format( $gross, 2 ),
                'net'         => '—',
                'date'        => gmdate( 'Y-m-d', (int) $pi->created ),
                'deposit'     => '—',
                'new_contact' => false,
                'status'      => 'error',
            ];
        }
    }

    metis_send_json_success( [
        'imported'      => $imported,
        'skipped'       => $skipped,
        'contacts_made' => $contacts_made,
        'errors'        => $errors,
        'rows'          => $rows,
        'has_more'      => (bool) $page->has_more,
        'next_cursor'   => $page->has_more ? $last_id : '',
    ] );
}

// ── Helper: split full name into first / last ───────────────────────────────

if ( ! function_exists( 'metis_split_name' ) ) {
    function metis_split_name( string $full_name ): array {
        $full_name = trim( $full_name );
        if ( $full_name === '' ) return [ 'first' => '', 'last' => '' ];
        $parts = preg_split( '/\s+/', $full_name, 2 );
        return [ 'first' => $parts[0] ?? '', 'last' => $parts[1] ?? '' ];
    }
}
