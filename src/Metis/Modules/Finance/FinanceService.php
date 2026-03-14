<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class FinanceService {
    public static function eventCreate( array $data, bool $auto_post = true ): int|\WP_Error {
        SchemaManager::ensureSchema();

        global $wpdb;

        $events_table = \Metis_Tables::get( 'finance_events' );
        $event_type   = \sanitize_key( (string) ( $data['event_type'] ?? '' ) );
        $provider     = \sanitize_key( (string) ( $data['provider'] ?? 'manual' ) );
        $reference_id = \sanitize_text_field( (string) ( $data['reference_id'] ?? '' ) );
        $currency     = strtolower( \sanitize_text_field( (string) ( $data['currency'] ?? 'usd' ) ) );
        $notes        = \sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) );
        $occurred_at  = \sanitize_text_field( (string) ( $data['occurred_at'] ?? \current_time( 'mysql' ) ) );
        $amount       = round( abs( (float) ( $data['amount'] ?? 0 ) ), 2 );
        $metadata     = is_array( $data['metadata_json'] ?? null )
            ? (array) $data['metadata_json']
            : Support::decodeJson( $data['metadata_json'] ?? '' );

        if ( ! in_array( $event_type, Support::eventTypes(), true ) ) {
            return new \WP_Error( 'invalid_event_type', 'Finance event type is not supported.' );
        }

        if ( $reference_id === '' ) {
            $reference_id = strtoupper( \metis_generate_code( 'FE' ) );
        }

        if ( $amount <= 0 ) {
            return new \WP_Error( 'invalid_amount', 'Finance event amount must be greater than zero.' );
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $occurred_at ) ) {
            $occurred_at = \current_time( 'mysql' );
        }

        $fund_id     = SchemaManager::findFundId( $data );
        $campaign_id = SchemaManager::resolveCampaignId( $data );

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$events_table} WHERE event_type = %s AND provider = %s AND reference_id = %s LIMIT 1",
                $event_type,
                $provider,
                $reference_id
            )
        );

        $payload = [
            'event_type'    => $event_type,
            'provider'      => $provider,
            'reference_id'  => $reference_id,
            'amount'        => $amount,
            'currency'      => $currency !== '' ? $currency : 'usd',
            'fund_id'       => $fund_id,
            'campaign_id'   => $campaign_id,
            'notes'         => $notes !== '' ? $notes : null,
            'metadata_json' => ! empty( $metadata ) ? \metis_json_encode( $metadata ) : null,
            'occurred_at'   => strlen( $occurred_at ) === 10 ? $occurred_at . ' 00:00:00' : $occurred_at,
            'updated_at'    => \current_time( 'mysql' ),
        ];

        if ( $existing_id > 0 ) {
            $wpdb->update( $events_table, $payload, [ 'id' => $existing_id ], [ '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s', '%s', '%s' ], [ '%d' ] );
            $event_id = $existing_id;
        } else {
            $payload['created_at'] = \current_time( 'mysql' );
            $wpdb->insert( $events_table, $payload, [ '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ] );
            $event_id = (int) $wpdb->insert_id;
        }

        if ( $event_id <= 0 ) {
            return new \WP_Error( 'finance_event_save_failed', 'Finance event could not be saved.' );
        }

        if ( $auto_post ) {
            self::eventPost( $event_id );
        }

        return $event_id;
    }

    public static function eventPost( int $event_id ): int|\WP_Error {
        if ( $event_id <= 0 ) {
            return new \WP_Error( 'invalid_event', 'Finance event ID is required.' );
        }

        $generated = LedgerService::generateLedger( [ 'event_id' => $event_id ] );
        LedgerService::syncReconciliations();

        return $generated;
    }

    public static function createManualActivity( array $data ): int|\WP_Error {
        SchemaManager::ensureSchema();

        if ( ! Access::canManage() ) {
            return new \WP_Error( 'forbidden', 'You do not have permission to record financial activity.' );
        }

        $activity_type = \sanitize_key( (string) ( $data['activity_type'] ?? '' ) );
        $amount        = round( abs( (float) ( $data['amount'] ?? 0 ) ), 2 );
        $occurred_at   = \sanitize_text_field( (string) ( $data['occurred_at'] ?? \current_time( 'Y-m-d' ) ) );
        $notes         = \sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) );
        $campaign_code = \sanitize_text_field( (string) ( $data['campaign_code'] ?? '' ) );
        $fund_id       = isset( $data['fund_id'] ) ? (int) $data['fund_id'] : 0;
        $from_account  = \sanitize_key( (string) ( $data['from_account_key'] ?? 'operating_cash' ) );
        $to_account    = \sanitize_key( (string) ( $data['to_account_key'] ?? '' ) );
        $direction     = \sanitize_key( (string) ( $data['direction'] ?? 'inflow' ) );

        if ( ! isset( Support::activityTypeOptions()[ $activity_type ] ) ) {
            return new \WP_Error( 'invalid_activity_type', 'Choose the kind of activity you want to record.' );
        }
        if ( $amount <= 0 ) {
            return new \WP_Error( 'invalid_amount', 'Enter an amount greater than zero.' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $occurred_at ) ) {
            return new \WP_Error( 'invalid_date', 'Choose a valid activity date.' );
        }
        if ( $from_account === '' ) {
            $from_account = 'operating_cash';
        }

        $reference_id = 'manual:' . $activity_type . ':' . gmdate( 'YmdHis' ) . ':' . \metis_rand( 100, 999 );
        $metadata     = [
            'created_via'         => 'activity_form',
            'user_id'             => \metis_current_user_id(),
            'bank_account_key'    => $from_account,
            'expense_account_key' => in_array( $activity_type, [ 'manual_expense', 'vendor_payment', 'check_written' ], true ) ? 'vendor_expense' : null,
        ];

        if ( $activity_type === 'transfer' ) {
            if ( $to_account === '' ) {
                return new \WP_Error( 'missing_destination', 'Choose where the money is moving to.' );
            }
            if ( $to_account === $from_account ) {
                return new \WP_Error( 'same_accounts', 'Choose two different accounts for a transfer.' );
            }
            $metadata['from_account_key'] = $from_account;
            $metadata['to_account_key']   = $to_account;
            unset( $metadata['bank_account_key'], $metadata['expense_account_key'] );
        }

        if ( $activity_type === 'adjustment' ) {
            $metadata['account_key']        = $from_account;
            $metadata['offset_account_key'] = $to_account !== '' ? $to_account : 'contributions_revenue';
            $metadata['direction']          = in_array( $direction, [ 'inflow', 'outflow' ], true ) ? $direction : 'inflow';
            unset( $metadata['bank_account_key'], $metadata['expense_account_key'] );
        }

        return self::eventCreate( [
            'event_type'    => $activity_type,
            'provider'      => 'manual',
            'reference_id'  => $reference_id,
            'amount'        => $amount,
            'currency'      => 'usd',
            'fund_id'       => $fund_id > 0 ? $fund_id : SchemaManager::defaultFundId(),
            'campaign_code' => $campaign_code,
            'notes'         => $notes !== '' ? $notes : Support::activityLabel( $activity_type ),
            'occurred_at'   => $occurred_at . ' 00:00:00',
            'metadata_json' => $metadata,
        ] );
    }

    public static function createManualLedgerEntry( array $data ): int|\WP_Error {
        SchemaManager::ensureSchema();

        if ( ! Access::canManage() ) {
            return new \WP_Error( 'forbidden', 'You do not have permission to add ledger entries.' );
        }

        global $wpdb;

        $ledger_table   = \Metis_Tables::get( 'finance_ledger' );
        $accounts_table = \Metis_Tables::get( 'finance_accounts' );

        $account_key = \sanitize_key( (string) ( $data['account_key'] ?? '' ) );
        $direction   = \sanitize_key( (string) ( $data['direction'] ?? '' ) );
        $status      = \sanitize_key( (string) ( $data['status'] ?? 'posted' ) );
        $memo        = \sanitize_text_field( (string) ( $data['memo'] ?? '' ) );
        $entry_date  = \sanitize_text_field( (string) ( $data['entry_date'] ?? \current_time( 'Y-m-d' ) ) );
        $amount      = round( (float) ( $data['amount'] ?? 0 ), 2 );

        if ( $account_key === '' ) {
            return new \WP_Error( 'missing_account', 'Choose an account for the ledger entry.' );
        }
        if ( ! in_array( $direction, [ 'inflow', 'outflow' ], true ) ) {
            return new \WP_Error( 'invalid_direction', 'Choose whether the entry is an inflow or outflow.' );
        }
        if ( ! in_array( $status, [ 'posted', 'pending', 'review' ], true ) ) {
            $status = 'posted';
        }
        if ( $amount <= 0 ) {
            return new \WP_Error( 'invalid_amount', 'Enter an amount greater than zero.' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $entry_date ) ) {
            return new \WP_Error( 'invalid_date', 'Choose a valid entry date.' );
        }

        $account_exists = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$accounts_table} WHERE account_key = %s AND is_active = 1", $account_key )
        );
        if ( $account_exists < 1 ) {
            return new \WP_Error( 'invalid_account', 'The selected account is not available.' );
        }

        $source_ref = 'MAN-' . gmdate( 'YmdHis' ) . '-' . \metis_rand( 100, 999 );

        $inserted = $wpdb->insert(
            $ledger_table,
            [
                'entry_date'  => $entry_date,
                'account_key' => $account_key,
                'source_type' => 'manual',
                'source_ref'  => $source_ref,
                'direction'   => $direction,
                'amount'      => abs( $amount ),
                'memo'        => $memo,
                'status'      => $status,
                'meta'        => \metis_json_encode( [ 'created_via' => 'legacy_ledger_form', 'user_id' => \metis_current_user_id() ] ),
                'created_at'  => \current_time( 'mysql' ),
                'updated_at'  => \current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return new \WP_Error( 'insert_failed', 'The ledger entry could not be saved.' );
        }

        return (int) $wpdb->insert_id;
    }

    public static function backfillEventsFromLegacy( bool $regenerate_ledger = true ): array {
        SchemaManager::ensureSchema();

        global $wpdb;

        $events_created = 0;
        $events_table   = \Metis_Tables::get( 'finance_events' );
        $transactions   = \Metis_Tables::get( 'transactions' );
        $refunds_table  = \Metis_Tables::get( 'transaction_refunds' );
        $deposits_table = \Metis_Tables::get( 'deposits' );

        if ( Support::tableExists( $transactions ) ) {
            $rows = $wpdb->get_results(
                "SELECT id, tid, tran_date, amount, fee, platform, payment_method, stripe_pay_int, stripe_charge_id, stripe_balance_txn, stripe_payout_id, campaign_code, deposit_batch_id, did
                 FROM {$transactions}
                 WHERE status NOT IN ('failed', 'voided')"
            ) ?: [];

            foreach ( $rows as $row ) {
                $provider   = strtolower( (string) ( $row->platform ?? '' ) );
                $campaign   = (string) ( $row->campaign_code ?? '' );
                $occurred   = ! empty( $row->tran_date ) ? (string) $row->tran_date : \current_time( 'mysql' );
                $gross      = round( (float) $row->amount + (float) $row->fee, 2 );
                $fee_amount = round( abs( (float) ( $row->fee ?? 0 ) ), 2 );

                if ( $provider === 'stripe' || $provider === 'st' || ! empty( $row->stripe_charge_id ) ) {
                    $charge_ref = (string) ( $row->stripe_charge_id ?: $row->tid );
                    $created    = self::eventCreate( [
                        'event_type'    => 'stripe_charge',
                        'provider'      => 'stripe',
                        'reference_id'  => $charge_ref,
                        'amount'        => $gross > 0 ? $gross : (float) $row->amount,
                        'currency'      => 'usd',
                        'campaign_code' => $campaign,
                        'notes'         => 'Backfilled from donation transaction ' . (string) $row->tid,
                        'occurred_at'   => $occurred,
                        'metadata_json' => [
                            'legacy_transaction_id' => (int) $row->id,
                            'tid'                   => (string) $row->tid,
                            'did'                   => (string) ( $row->did ?? '' ),
                            'stripe_pay_int'        => (string) ( $row->stripe_pay_int ?? '' ),
                            'stripe_balance_txn'    => (string) ( $row->stripe_balance_txn ?? '' ),
                            'stripe_payout_id'      => (string) ( $row->stripe_payout_id ?? '' ),
                            'deposit_batch_id'      => (string) ( $row->deposit_batch_id ?? '' ),
                            'payment_method'        => (string) ( $row->payment_method ?? '' ),
                            'legacy_source'         => 'transactions',
                        ],
                    ] );
                    if ( ! \metis_is_error( $created ) ) {
                        $events_created++;
                    }

                    if ( $fee_amount > 0 ) {
                        $fee_ref  = (string) ( $row->stripe_balance_txn ?: $charge_ref . ':fee' );
                        $created  = self::eventCreate( [
                            'event_type'    => 'stripe_fee',
                            'provider'      => 'stripe',
                            'reference_id'  => $fee_ref,
                            'amount'        => $fee_amount,
                            'currency'      => 'usd',
                            'campaign_code' => $campaign,
                            'notes'         => 'Backfilled Stripe fee for ' . (string) $row->tid,
                            'occurred_at'   => $occurred,
                            'metadata_json' => [
                                'legacy_transaction_id' => (int) $row->id,
                                'tid'                   => (string) $row->tid,
                                'stripe_charge_id'      => $charge_ref,
                                'legacy_source'         => 'transactions',
                            ],
                        ] );
                        if ( ! \metis_is_error( $created ) ) {
                            $events_created++;
                        }
                    }
                }
            }
        }

        if ( Support::tableExists( $refunds_table ) ) {
            $rows = $wpdb->get_results(
                "SELECT r.id, r.tid, r.refund_date, r.amount, r.reason, r.notes, r.source, r.stripe_refund_id, tx.campaign_code
                 FROM {$refunds_table} r
                 LEFT JOIN {$transactions} tx ON tx.tid = r.tid"
            ) ?: [];

            foreach ( $rows as $row ) {
                $created = self::eventCreate( [
                    'event_type'    => 'stripe_refund',
                    'provider'      => (string) ( $row->source ?: 'stripe' ),
                    'reference_id'  => (string) ( $row->stripe_refund_id ?: $row->tid . ':refund:' . (int) $row->id ),
                    'amount'        => (float) $row->amount,
                    'currency'      => 'usd',
                    'campaign_code' => (string) ( $row->campaign_code ?? '' ),
                    'notes'         => (string) ( $row->reason ?: $row->notes ?: 'Backfilled refund' ),
                    'occurred_at'   => ! empty( $row->refund_date ) ? (string) $row->refund_date . ' 00:00:00' : \current_time( 'mysql' ),
                    'metadata_json' => [
                        'refund_row_id'    => (int) $row->id,
                        'tid'              => (string) $row->tid,
                        'stripe_refund_id' => (string) ( $row->stripe_refund_id ?? '' ),
                        'legacy_source'    => 'transaction_refunds',
                    ],
                ] );
                if ( ! \metis_is_error( $created ) ) {
                    $events_created++;
                }
            }
        }

        if ( Support::tableExists( $deposits_table ) ) {
            $rows = $wpdb->get_results(
                "SELECT id, provider, provider_ref, deposit_date, total_amount, gross_total, fee_total, net_total, currency, status, meta
                 FROM {$deposits_table}
                 ORDER BY deposit_date ASC, id ASC"
            ) ?: [];

            foreach ( $rows as $row ) {
                $meta      = Support::decodeJson( $row->meta ?? '' );
                $payout_id = \sanitize_text_field( (string) ( $meta['payout_id'] ?? '' ) );
                $provider  = \sanitize_key( (string) ( $row->provider ?? 'manual' ) );
                $amount    = round( (float) ( $row->net_total ?? $row->total_amount ?? 0 ), 2 );
                $occurred  = ! empty( $row->deposit_date ) ? (string) $row->deposit_date . ' 00:00:00' : \current_time( 'mysql' );

                if ( $amount <= 0 ) {
                    continue;
                }

                if ( $provider === 'stripe' || $payout_id !== '' ) {
                    $created = self::eventCreate( [
                        'event_type'    => 'stripe_payout',
                        'provider'      => 'stripe',
                        'reference_id'  => $payout_id !== '' ? $payout_id : (string) $row->provider_ref,
                        'amount'        => $amount,
                        'currency'      => strtolower( (string) ( $row->currency ?: 'usd' ) ),
                        'notes'         => 'Backfilled settlement for deposit ' . (string) $row->provider_ref,
                        'occurred_at'   => $occurred,
                        'metadata_json' => [
                            'deposit_id'    => (int) $row->id,
                            'deposit_code'  => (string) $row->provider_ref,
                            'gross_total'   => (float) ( $row->gross_total ?? 0 ),
                            'fee_total'     => (float) ( $row->fee_total ?? 0 ),
                            'net_total'     => $amount,
                            'status'        => (string) ( $row->status ?? '' ),
                            'legacy_source' => 'deposits',
                        ],
                    ] );
                    if ( ! \metis_is_error( $created ) ) {
                        $events_created++;
                    }
                }
            }
        }

        $event_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" );

        if ( $regenerate_ledger ) {
            LedgerService::generateLedger();
            LedgerService::syncReconciliations();
        }

        return [
            'events_created' => $events_created,
            'event_count'    => $event_count,
        ];
    }
}
