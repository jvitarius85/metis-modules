<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

use Metis\Core\Integrations\StripeApiClient;
use Metis\Modules\Contacts\ContactMutationService;

final class StripeReconciliationService {
    private const MAX_PAYOUT_PAGES = 5;
    private const MAX_BALANCE_TRANSACTION_PAGES = 5;

    public static function ensureSchema(): void {
        $table = \Metis_Tables::get( 'transactions' );
        if ( $table === '' ) {
            return;
        }

        try {
            $columns = self::tableColumns( $table );
            if ( ! isset( $columns['donor_email'] ) ) {
                \metis_db()->execute( "ALTER TABLE {$table} ADD COLUMN donor_email VARCHAR(191) DEFAULT NULL AFTER did" );
            }
            if ( ! isset( $columns['stripe_customer_id'] ) ) {
                \metis_db()->execute( "ALTER TABLE {$table} ADD COLUMN stripe_customer_id VARCHAR(191) DEFAULT NULL AFTER stripe_charge_id" );
            }

            self::addIndexIfMissing( $table, 'idx_transactions_donor_email', 'ADD KEY idx_transactions_donor_email (donor_email)' );
            self::addIndexIfMissing( $table, 'idx_transactions_stripe_customer_id', 'ADD KEY idx_transactions_stripe_customer_id (stripe_customer_id)' );
            self::addIndexIfMissing( $table, 'idx_transactions_stripe_payout_id', 'ADD KEY idx_transactions_stripe_payout_id (stripe_payout_id)' );
            self::addIndexIfMissing( $table, 'idx_transactions_stripe_balance_txn', 'ADD KEY idx_transactions_stripe_balance_txn (stripe_balance_txn)' );
            self::addIndexIfMissing( $table, 'idx_transactions_stripe_pay_int', 'ADD KEY idx_transactions_stripe_pay_int (stripe_pay_int)' );
            self::addIndexIfMissing( $table, 'idx_transactions_stripe_charge_id', 'ADD KEY idx_transactions_stripe_charge_id (stripe_charge_id)' );
        } catch ( \Throwable $e ) {
            \Metis_Logger::warn( 'donations.stripe_reconciliation_schema_failed', [
                'error' => $e->getMessage(),
            ] );
        }
    }

    public static function runNightly( int $transaction_limit = 250, int $payout_limit = 100 ): array {
        self::ensureSchema();

        $stripe = \function_exists( 'metis_stripe_client' ) ? \metis_stripe_client() : null;
        if ( ! $stripe instanceof StripeApiClient ) {
            return [
                'status' => 'skipped',
                'message' => 'Stripe is not configured.',
            ];
        }

        $summary = [
            'transactions_imported' => 0,
            'transactions_skipped' => 0,
            'transactions_scanned' => 0,
            'transactions_updated' => 0,
            'emails_captured' => 0,
            'donors_linked' => 0,
            'donors_created' => 0,
            'payouts_scanned' => 0,
            'deposits_created' => 0,
            'deposits_updated' => 0,
            'deposit_links_updated' => 0,
            'deposit_totals_refreshed' => 0,
            'errors' => [],
        ];

        self::importRecentTransactions( $stripe, $summary, $transaction_limit );
        self::reconcileTransactions( $stripe, $summary, $transaction_limit );
        self::reconcilePayouts( $stripe, $summary, $payout_limit );

        $status = $summary['errors'] === [] ? 'ok' : 'partial';

        return [
            'status' => $status,
            'message' => sprintf(
                'Stripe reconciliation scanned %d transactions and %d payouts.',
                (int) $summary['transactions_scanned'],
                (int) $summary['payouts_scanned']
            ),
            'summary' => $summary,
        ];
    }

    private static function importRecentTransactions( StripeApiClient $stripe, array &$summary, int $limit ): void {
        $tx_table = \Metis_Tables::get( 'transactions' );
        if ( $tx_table === '' ) {
            return;
        }

        $page_limit = max( 1, min( 100, $limit ) );
        $remaining = max( 1, $limit );
        $starting_after = '';
        $email_to_did = ContactMutationService::emailDidMap();
        $payout_to_deposit = StripeDepositService::payoutToDepositMap();
        $deposit_date_cache = [];
        $existing_charge_ids = TransactionRecordService::existingStripeChargeIds();
        $existing_pi_ids = TransactionRecordService::existingStripePaymentIntentIds();
        $pages = 0;

        $params = [
            'limit' => $page_limit,
            'created' => [
                'gte' => self::transactionImportStartTimestamp(),
                'lte' => time(),
            ],
            'expand' => [
                'data.latest_charge',
                'data.latest_charge.balance_transaction',
                'data.customer',
            ],
        ];

        while ( $remaining > 0 && $pages < self::MAX_PAYOUT_PAGES ) {
            $pages++;
            if ( $starting_after !== '' ) {
                $params['starting_after'] = $starting_after;
            } else {
                unset( $params['starting_after'] );
            }

            $response = $stripe->listPaymentIntents( $params );
            $payment_intents = (array) ( $response->data ?? [] );
            if ( $payment_intents === [] ) {
                break;
            }

            foreach ( $payment_intents as $intent ) {
                if ( ! is_object( $intent ) || $remaining < 1 ) {
                    continue;
                }

                $pi_id = trim( (string) ( $intent->id ?? '' ) );
                $charge = $intent->latest_charge ?? null;
                if ( $pi_id === '' || ! is_object( $charge ) || trim( (string) ( $intent->status ?? '' ) ) !== 'succeeded' ) {
                    $summary['transactions_skipped']++;
                    continue;
                }

                $charge_id = trim( (string) ( $charge->id ?? '' ) );
                if ( $charge_id === '' ) {
                    $summary['transactions_skipped']++;
                    continue;
                }

                if ( isset( $existing_charge_ids[ $charge_id ] ) || isset( $existing_pi_ids[ $pi_id ] ) ) {
                    $summary['transactions_skipped']++;
                    continue;
                }

                $customer = self::resolveCustomer( $stripe, $intent, $charge, '' );
                $balance = self::resolveBalanceTransaction( $stripe, $charge, '' );
                $profile = self::extractDonorProfile( $intent, $charge, $customer );
                $did = '';
                if ( $profile['email'] !== '' ) {
                    $did = trim( (string) ( $email_to_did[ $profile['email'] ] ?? '' ) );
                    if ( $did === '' ) {
                        $contact = ContactMutationService::resolveOrCreateDonorContact(
                            $profile['email'],
                            $profile['first_name'],
                            $profile['last_name']
                        );
                        $did = trim( (string) ( $contact['did'] ?? '' ) );
                        if ( $did !== '' ) {
                            $summary['donors_linked']++;
                            $email_to_did[ $profile['email'] ] = $did;
                            if ( ! empty( $contact['created'] ) ) {
                                $summary['donors_created']++;
                            }
                        }
                    }
                }

                $bt_id = is_object( $balance ) ? trim( (string) ( $balance->id ?? '' ) ) : '';
                $payout_id = is_object( $balance ) ? trim( (string) ( $balance->payout ?? '' ) ) : '';
                $customer_id = is_object( $customer ) ? trim( (string) ( $customer->id ?? '' ) ) : trim( (string) ( $intent->customer ?? $charge->customer ?? '' ) );
                $deposit_batch_id = null;
                $deposit_date = null;
                if ( $payout_id !== '' && isset( $payout_to_deposit[ $payout_id ] ) ) {
                    $deposit_batch_id = (string) $payout_to_deposit[ $payout_id ];
                    if ( ! isset( $deposit_date_cache[ $deposit_batch_id ] ) ) {
                        $deposit_date_cache[ $deposit_batch_id ] = StripeDepositService::depositDateByCode( $deposit_batch_id );
                    }
                    $deposit_date = $deposit_date_cache[ $deposit_batch_id ];
                }

                $amount_cents = (int) ( $charge->amount ?? 0 );
                $fee_cents = is_object( $balance ) ? (int) ( $balance->fee ?? 0 ) : 0;
                $method_details = DonationsModule::stripePaymentMethodDetails( $charge );
                $payload = [
                    'did' => $did !== '' ? $did : null,
                    'donor_email' => $profile['email'] !== '' ? $profile['email'] : null,
                    'tran_date' => gmdate( 'Y-m-d H:i:s', (int) ( $charge->created ?? time() ) ),
                    'amount' => round( ( $amount_cents - $fee_cents ) / 100, 2 ),
                    'fee' => round( $fee_cents / 100, 2 ),
                    'payout' => round( ( $amount_cents - $fee_cents ) / 100, 2 ),
                    'platform' => 'ST',
                    'payment_method' => (string) ( $method_details['payment_method'] ?? 'cc' ),
                    'card_brand' => $method_details['card_brand'] ?? null,
                    'card_last4' => $method_details['card_last4'] ?? null,
                    'status' => 'Completed',
                    'stripe_pay_int' => $pi_id,
                    'stripe_charge_id' => $charge_id,
                    'stripe_customer_id' => $customer_id !== '' ? $customer_id : null,
                    'stripe_balance_txn' => $bt_id !== '' ? $bt_id : null,
                    'stripe_payout_id' => $payout_id !== '' ? $payout_id : null,
                    'deposit_batch_id' => $deposit_batch_id,
                    'deposit_date' => $deposit_date,
                    'created_at' => \metis_current_time( 'mysql' ),
                    'updated_at' => \metis_current_time( 'mysql' ),
                ];

                if ( \function_exists( 'metis_entity_id_service' ) ) {
                    $payload = \metis_entity_id_service()->assignForInsert( 'donation_transaction', $payload );
                } else {
                    $payload['tid'] = \metis_generate_code( 'TR', $tx_table, 'tid' );
                }

                if ( \metis_db()->insert( $tx_table, $payload ) ) {
                    if ( \function_exists( 'metis_entity_id_service' ) ) {
                        \metis_entity_id_service()->register( 'donation_transaction', \metis_db()->lastInsertId(), (string) ( $payload['transaction_uid'] ?? $payload['tid'] ?? '' ) );
                    }
                    $existing_charge_ids[ $charge_id ] = true;
                    $existing_pi_ids[ $pi_id ] = true;
                    $summary['transactions_imported']++;
                    $remaining--;
                } else {
                    $summary['errors'][] = [
                        'scope' => 'transaction_import',
                        'payment_intent_id' => $pi_id,
                        'charge_id' => $charge_id,
                        'error' => 'Could not insert Stripe transaction.',
                    ];
                }
            }

            $last = end( $payment_intents );
            $starting_after = is_object( $last ) ? trim( (string) ( $last->id ?? '' ) ) : '';
            if ( empty( $response->has_more ) || $starting_after === '' ) {
                break;
            }
        }
    }

    private static function reconcileTransactions( StripeApiClient $stripe, array &$summary, int $limit ): void {
        $rows = TransactionRecordService::listStripeTransactionsNeedingReconciliation( $limit );

        foreach ( $rows as $row ) {
            $summary['transactions_scanned']++;

            try {
                $intent = self::resolvePaymentIntent( $stripe, (string) ( $row->stripe_pay_int ?? '' ) );
                $charge = self::resolveCharge( $stripe, $intent, (string) ( $row->stripe_charge_id ?? '' ) );
                $customer = self::resolveCustomer( $stripe, $intent, $charge, (string) ( $row->stripe_customer_id ?? '' ) );
                $balance = self::resolveBalanceTransaction( $stripe, $charge, (string) ( $row->stripe_balance_txn ?? '' ) );

                $payload = [];
                $captured_email = strtolower( trim( (string) ( $row->donor_email ?? '' ) ) );
                if ( is_object( $charge ) ) {
                    $charge_id = trim( (string) ( $charge->id ?? '' ) );
                    if ( $charge_id !== '' && trim( (string) ( $row->stripe_charge_id ?? '' ) ) === '' ) {
                        $payload['stripe_charge_id'] = $charge_id;
                    }
                }
                if ( is_object( $customer ) ) {
                    $customer_id = trim( (string) ( $customer->id ?? '' ) );
                    if ( $customer_id !== '' && trim( (string) ( $row->stripe_customer_id ?? '' ) ) === '' ) {
                        $payload['stripe_customer_id'] = $customer_id;
                    }
                }
                if ( is_object( $balance ) ) {
                    $balance_id = trim( (string) ( $balance->id ?? '' ) );
                    $payout_id = trim( (string) ( $balance->payout ?? '' ) );
                    if ( $balance_id !== '' && trim( (string) ( $row->stripe_balance_txn ?? '' ) ) === '' ) {
                        $payload['stripe_balance_txn'] = $balance_id;
                    }
                    if ( $payout_id !== '' && trim( (string) ( $row->stripe_payout_id ?? '' ) ) === '' ) {
                        $payload['stripe_payout_id'] = $payout_id;
                    }
                }

                $profile = self::extractDonorProfile( $intent, $charge, $customer );
                if ( $profile['email'] !== '' && $captured_email === '' ) {
                    $payload['donor_email'] = $profile['email'];
                    $summary['emails_captured']++;
                    $captured_email = $profile['email'];
                }

                if ( trim( (string) ( $row->did ?? '' ) ) === '' && $captured_email !== '' ) {
                    $contact = ContactMutationService::resolveOrCreateDonorContact(
                        $captured_email,
                        (string) ( $profile['first_name'] ?? '' ),
                        (string) ( $profile['last_name'] ?? '' )
                    );
                    $did = trim( (string) ( $contact['did'] ?? '' ) );
                    if ( $did !== '' ) {
                        $payload['did'] = $did;
                        $summary['donors_linked']++;
                        if ( ! empty( $contact['created'] ) ) {
                            $summary['donors_created']++;
                        }
                    }
                }

                if ( $payload !== [] && TransactionRecordService::updateStripeReconciliationFields( (int) $row->id, $payload ) ) {
                    $summary['transactions_updated']++;
                }
            } catch ( \Throwable $e ) {
                $summary['errors'][] = [
                    'scope' => 'transaction',
                    'tid' => (string) ( $row->tid ?? '' ),
                    'error' => $e->getMessage(),
                ];
                \Metis_Logger::warn( 'donations.stripe_transaction_reconciliation_failed', [
                    'transaction_id' => (int) ( $row->id ?? 0 ),
                    'tid' => (string) ( $row->tid ?? '' ),
                    'error' => $e->getMessage(),
                ] );
            }
        }
    }

    private static function reconcilePayouts( StripeApiClient $stripe, array &$summary, int $limit ): void {
        $page_limit = max( 1, min( 100, $limit ) );
        $starting_after = '';
        $pages = 0;

        while ( $pages < self::MAX_PAYOUT_PAGES ) {
            $pages++;
            $params = [
                'limit' => $page_limit,
                'status' => 'paid',
            ];
            if ( $starting_after !== '' ) {
                $params['starting_after'] = $starting_after;
            }

            $payouts = $stripe->listPayouts( $params );
            $data = (array) ( $payouts->data ?? [] );
            if ( $data === [] ) {
                break;
            }

            foreach ( $data as $payout ) {
                if ( ! is_object( $payout ) ) {
                    continue;
                }

                $summary['payouts_scanned']++;
                $payout_id = trim( (string) ( $payout->id ?? '' ) );
                if ( $payout_id === '' ) {
                    continue;
                }

                try {
                    $deposit = StripeDepositService::syncFromStripePayout( [
                        'payout_id' => $payout_id,
                        'arrival_date' => self::stripeUnixDateToMysqlDate( (int) ( $payout->arrival_date ?? 0 ) ),
                        'net_amount' => round( ( (float) ( $payout->amount ?? 0 ) ) / 100, 2 ),
                        'currency' => strtolower( trim( (string) ( $payout->currency ?? 'usd' ) ) ),
                        'status' => trim( (string) ( $payout->status ?? 'paid' ) ),
                        'generated_via' => 'stripe-reconciliation',
                    ] );

                    if ( ! empty( $deposit['created'] ) ) {
                        $summary['deposits_created']++;
                    } else {
                        $summary['deposits_updated']++;
                    }

                    $deposit_code = trim( (string) ( $deposit['provider_ref'] ?? '' ) );
                    if ( $deposit_code === '' ) {
                        continue;
                    }

                    $summary['deposit_links_updated'] += TransactionRecordService::linkOrphanedByPayoutId( $deposit_code, $payout_id );
                    $summary['deposit_links_updated'] += self::linkDepositTransactionsFromBalanceTransactions( $stripe, $payout_id, $deposit_code );

                    StripeDepositService::refreshTransactionCount( $deposit_code );
                    $totals = TransactionRecordService::localTotalsByDepositCode( $deposit_code );
                    if ( is_array( $totals ) ) {
                        $deposit_id = (int) ( $deposit['id'] ?? 0 );
                        if ( $deposit_id > 0 ) {
                            StripeDepositService::updateFinancialTotals(
                                $deposit_id,
                                round( (float) ( $totals['fees'] ?? 0 ), 2 ),
                                round( (float) ( $totals['gross'] ?? 0 ), 2 ),
                                round( (float) ( $totals['net'] ?? 0 ), 2 )
                            );
                            $summary['deposit_totals_refreshed']++;
                        }
                    }
                } catch ( \Throwable $e ) {
                    $summary['errors'][] = [
                        'scope' => 'payout',
                        'payout_id' => $payout_id,
                        'error' => $e->getMessage(),
                    ];
                    \Metis_Logger::warn( 'donations.stripe_payout_reconciliation_failed', [
                        'payout_id' => $payout_id,
                        'error' => $e->getMessage(),
                    ] );
                }
            }

            $last = end( $data );
            $starting_after = is_object( $last ) ? trim( (string) ( $last->id ?? '' ) ) : '';
            if ( empty( $payouts->has_more ) || $starting_after === '' ) {
                break;
            }
        }
    }

    private static function linkDepositTransactionsFromBalanceTransactions( StripeApiClient $stripe, string $payout_id, string $deposit_code ): int {
        $updated = 0;
        $starting_after = '';
        $pages = 0;

        while ( $pages < self::MAX_BALANCE_TRANSACTION_PAGES ) {
            $pages++;
            $params = [
                'payout' => $payout_id,
                'limit' => 100,
            ];
            if ( $starting_after !== '' ) {
                $params['starting_after'] = $starting_after;
            }

            $response = $stripe->listBalanceTransactions( $params );
            $rows = (array) ( $response->data ?? [] );
            if ( $rows === [] ) {
                break;
            }

            foreach ( $rows as $balance_row ) {
                if ( ! is_object( $balance_row ) ) {
                    continue;
                }

                $balance_txn_id = trim( (string) ( $balance_row->id ?? '' ) );
                $charge_id = trim( (string) ( $balance_row->source ?? '' ) );
                $candidate = $balance_txn_id !== ''
                    ? TransactionRecordService::findDepositLinkCandidateByBalanceTransaction( $balance_txn_id )
                    : null;

                if ( ! is_array( $candidate ) && $charge_id !== '' ) {
                    $candidate = TransactionRecordService::findDepositLinkCandidateByChargeId( $charge_id );
                }

                if ( ! is_array( $candidate ) ) {
                    continue;
                }

                $payload = [];
                if ( trim( (string) ( $candidate['deposit_batch_id'] ?? '' ) ) === '' ) {
                    $payload['deposit_batch_id'] = $deposit_code;
                }
                $payload['stripe_payout_id'] = $payout_id;
                if ( $balance_txn_id !== '' ) {
                    $payload['stripe_balance_txn'] = $balance_txn_id;
                }
                if ( $charge_id !== '' ) {
                    $payload['stripe_charge_id'] = $charge_id;
                }

                if ( TransactionRecordService::updateStripeReconciliationFields( (int) ( $candidate['id'] ?? 0 ), $payload ) ) {
                    $updated++;
                }
            }

            $last = end( $rows );
            $starting_after = is_object( $last ) ? trim( (string) ( $last->id ?? '' ) ) : '';
            if ( empty( $response->has_more ) || $starting_after === '' ) {
                break;
            }
        }

        return $updated;
    }

    private static function resolvePaymentIntent( StripeApiClient $stripe, string $payment_intent_id ): ?object {
        $payment_intent_id = trim( $payment_intent_id );
        if ( $payment_intent_id === '' ) {
            return null;
        }

        return $stripe->retrievePaymentIntent( $payment_intent_id, [
            'expand' => [ 'latest_charge.balance_transaction', 'customer' ],
        ] );
    }

    private static function resolveCharge( StripeApiClient $stripe, ?object $intent, string $charge_id ): ?object {
        $charge = is_object( $intent ) ? ( $intent->latest_charge ?? null ) : null;
        if ( is_object( $charge ) ) {
            return $charge;
        }

        $resolved_charge_id = trim( (string) $charge_id );
        if ( $resolved_charge_id === '' && is_object( $intent ) ) {
            $resolved_charge_id = trim( (string) ( $intent->latest_charge ?? '' ) );
        }
        if ( $resolved_charge_id === '' ) {
            return null;
        }

        return $stripe->retrieveCharge( $resolved_charge_id, [
            'expand' => [ 'balance_transaction' ],
        ] );
    }

    private static function resolveCustomer( StripeApiClient $stripe, ?object $intent, ?object $charge, string $customer_id ): ?object {
        $customer = is_object( $intent ) ? ( $intent->customer ?? null ) : null;
        if ( is_object( $customer ) ) {
            return $customer;
        }

        $resolved_customer_id = trim( $customer_id );
        if ( $resolved_customer_id === '' && is_object( $intent ) ) {
            $resolved_customer_id = trim( (string) ( $intent->customer ?? '' ) );
        }
        if ( $resolved_customer_id === '' && is_object( $charge ) ) {
            $resolved_customer_id = trim( (string) ( $charge->customer ?? '' ) );
        }
        if ( $resolved_customer_id === '' ) {
            return null;
        }

        return $stripe->retrieveCustomer( $resolved_customer_id );
    }

    private static function resolveBalanceTransaction( StripeApiClient $stripe, ?object $charge, string $balance_txn_id ): ?object {
        $balance = is_object( $charge ) ? ( $charge->balance_transaction ?? null ) : null;
        if ( is_object( $balance ) ) {
            return $balance;
        }

        $resolved_balance_id = trim( $balance_txn_id );
        if ( $resolved_balance_id === '' && is_object( $charge ) ) {
            $resolved_balance_id = trim( (string) ( $charge->balance_transaction ?? '' ) );
        }
        if ( $resolved_balance_id === '' ) {
            return null;
        }

        return $stripe->retrieveBalanceTransaction( $resolved_balance_id );
    }

    /**
     * @return array{email:string,first_name:string,last_name:string}
     */
    private static function extractDonorProfile( ?object $intent, ?object $charge, ?object $customer ): array {
        $email_candidates = [
            is_object( $intent ) ? (string) ( $intent->receipt_email ?? '' ) : '',
            is_object( $intent ) && is_object( $intent->metadata ?? null ) ? (string) ( $intent->metadata->donor_email ?? $intent->metadata->email ?? '' ) : '',
            is_object( $charge ) ? (string) ( $charge->receipt_email ?? '' ) : '',
            is_object( $charge ) && is_object( $charge->billing_details ?? null ) ? (string) ( $charge->billing_details->email ?? '' ) : '',
            is_object( $customer ) ? (string) ( $customer->email ?? '' ) : '',
        ];
        $email = '';
        foreach ( $email_candidates as $candidate ) {
            $candidate = strtolower( trim( \metis_email_clean( $candidate ) ) );
            if ( $candidate !== '' && \metis_email_is_valid( $candidate ) ) {
                $email = $candidate;
                break;
            }
        }

        $name_candidates = [
            is_object( $charge ) && is_object( $charge->billing_details ?? null ) ? (string) ( $charge->billing_details->name ?? '' ) : '',
            is_object( $customer ) ? (string) ( $customer->name ?? '' ) : '',
            is_object( $intent ) && is_object( $intent->metadata ?? null ) ? (string) ( $intent->metadata->donor_name ?? $intent->metadata->name ?? '' ) : '',
        ];
        $name = '';
        foreach ( $name_candidates as $candidate ) {
            $candidate = trim( \metis_text_clean( $candidate ) );
            if ( $candidate !== '' ) {
                $name = $candidate;
                break;
            }
        }

        [ $first_name, $last_name ] = self::splitName( $name );

        return [
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function splitName( string $name ): array {
        $name = trim( preg_replace( '/\s+/', ' ', \metis_text_clean( $name ) ) ?? $name );
        if ( $name === '' ) {
            return [ '', '' ];
        }

        $parts = preg_split( '/\s+/', $name ) ?: [];
        if ( count( $parts ) < 2 ) {
            return [ $name, '' ];
        }

        $last_name = (string) array_pop( $parts );
        return [ trim( implode( ' ', $parts ) ), $last_name ];
    }

    private static function stripeUnixDateToMysqlDate( int $timestamp ): string {
        if ( $timestamp < 1 ) {
            return \metis_current_time( 'Y-m-d' );
        }

        return gmdate( 'Y-m-d', $timestamp );
    }

    private static function transactionImportStartTimestamp(): int {
        $fallback = time() - ( 45 * DAY_IN_SECONDS );
        $latest = (string) \metis_db()->scalar(
            'SELECT MAX(tran_date) FROM ' . \Metis_Tables::get( 'transactions' ) . " WHERE platform IN ('ST', 'stripe')"
        );
        $latest_ts = $latest !== '' ? strtotime( $latest . ' UTC' ) : false;
        if ( $latest_ts === false || $latest_ts < 1 ) {
            return time() - ( 400 * DAY_IN_SECONDS );
        }

        return max( $fallback, (int) $latest_ts - ( 7 * DAY_IN_SECONDS ) );
    }

    /**
     * @return array<string,bool>
     */
    private static function tableColumns( string $table ): array {
        $columns = [];
        foreach ( \metis_db()->fetchAll( "SHOW COLUMNS FROM {$table}" ) as $row ) {
            $field = strtolower( trim( (string) ( $row['Field'] ?? '' ) ) );
            if ( $field !== '' ) {
                $columns[ $field ] = true;
            }
        }

        return $columns;
    }

    private static function addIndexIfMissing( string $table, string $index_name, string $alter_fragment ): void {
        $index_name = trim( $index_name );
        if ( $index_name === '' ) {
            return;
        }

        $existing = \metis_db()->fetchAll( "SHOW INDEX FROM {$table}" );
        foreach ( $existing as $row ) {
            if ( strtolower( trim( (string) ( $row['Key_name'] ?? '' ) ) ) === strtolower( $index_name ) ) {
                return;
            }
        }

        \metis_db()->execute( "ALTER TABLE {$table} {$alter_fragment}" );
    }
}
