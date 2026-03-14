<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class LedgerService {
    public static function ledgerDirection( string $account_key, string $entry_side ): string {
        $accounts = SchemaManager::accountMap();
        $account  = $accounts[ $account_key ] ?? null;
        $normal   = strtolower( (string) ( $account['normal_balance'] ?? 'debit' ) );
        $side     = strtolower( $entry_side );

        return $side === $normal ? 'inflow' : 'outflow';
    }

    public static function sourceRefForEvent( object $event ): string {
        return 'event:' . (int) $event->id . ':' . \sanitize_key( (string) $event->event_type );
    }

    public static function upsertLedgerEntry(
        string $account_key,
        string $source_type,
        string $source_ref,
        string $direction,
        float $amount,
        string $entry_date,
        string $status,
        string $memo,
        array $meta = [],
        array $extra = []
    ): void {
        global $wpdb;

        $ledger_table = \Metis_Tables::get( 'finance_ledger' );
        if ( ! Support::tableExists( $ledger_table ) ) {
            return;
        }

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$ledger_table} WHERE account_key = %s AND source_type = %s AND source_ref = %s AND direction = %s LIMIT 1",
                $account_key,
                $source_type,
                $source_ref,
                $direction
            )
        );

        $payload = [
            'entry_date'         => $entry_date,
            'account_key'        => $account_key,
            'source_type'        => $source_type,
            'source_ref'         => $source_ref,
            'direction'          => $direction,
            'entry_side'         => $extra['entry_side'] ?? null,
            'contra_account_key' => $extra['contra_account_key'] ?? null,
            'event_id'           => isset( $extra['event_id'] ) ? (int) $extra['event_id'] : null,
            'fund_id'            => isset( $extra['fund_id'] ) ? (int) $extra['fund_id'] : null,
            'campaign_id'        => isset( $extra['campaign_id'] ) ? (int) $extra['campaign_id'] : null,
            'amount'             => round( abs( $amount ), 2 ),
            'memo'               => $memo,
            'status'             => $status,
            'meta'               => \metis_json_encode( $meta ),
            'updated_at'         => \current_time( 'mysql' ),
        ];

        $formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s' ];

        if ( $existing_id > 0 ) {
            $wpdb->update( $ledger_table, $payload, [ 'id' => $existing_id ], $formats, [ '%d' ] );
            return;
        }

        $payload['created_at'] = \current_time( 'mysql' );
        $formats[]             = '%s';

        $wpdb->insert( $ledger_table, $payload, $formats );
    }

    public static function eventLedgerLines( object $event ): array {
        $metadata   = Support::decodeJson( $event->metadata_json ?? '' );
        $amount     = round( abs( (float) ( $event->amount ?? 0 ) ), 2 );
        $event_type = \sanitize_key( (string) ( $event->event_type ?? '' ) );
        $base_meta  = [
            'provider'     => (string) ( $event->provider ?? '' ),
            'reference_id' => (string) ( $event->reference_id ?? '' ),
            'metadata'     => $metadata,
        ];

        if ( $amount <= 0 ) {
            return [];
        }

        switch ( $event_type ) {
            case 'stripe_charge':
                return [
                    [ 'account_key' => 'stripe_clearing',       'entry_side' => 'debit',  'memo' => 'Stripe charge clearing',        'meta' => $base_meta ],
                    [ 'account_key' => 'contributions_revenue', 'entry_side' => 'credit', 'memo' => 'Donations revenue',             'meta' => $base_meta ],
                ];
            case 'stripe_fee':
                return [
                    [ 'account_key' => 'processing_fees', 'entry_side' => 'debit',  'memo' => 'Stripe processing fee',       'meta' => $base_meta ],
                    [ 'account_key' => 'stripe_clearing', 'entry_side' => 'credit', 'memo' => 'Stripe fee clearing',         'meta' => $base_meta ],
                ];
            case 'stripe_refund':
                return [
                    [ 'account_key' => 'refund_expense',  'entry_side' => 'debit',  'memo' => 'Stripe refund expense',       'meta' => $base_meta ],
                    [ 'account_key' => 'stripe_clearing', 'entry_side' => 'credit', 'memo' => 'Stripe refund clearing',      'meta' => $base_meta ],
                ];
            case 'stripe_payout':
                return [
                    [ 'account_key' => 'operating_cash',  'entry_side' => 'debit',  'memo' => 'Stripe payout settled to bank','meta' => $base_meta ],
                    [ 'account_key' => 'stripe_clearing', 'entry_side' => 'credit', 'memo' => 'Stripe payout cleared',        'meta' => $base_meta ],
                ];
            case 'manual_expense':
            case 'vendor_payment':
            case 'check_written':
                $expense_account = \sanitize_key( (string) ( $metadata['expense_account_key'] ?? 'vendor_expense' ) );
                $bank_account    = \sanitize_key( (string) ( $metadata['bank_account_key'] ?? 'operating_cash' ) );
                if ( $expense_account === '' ) {
                    $expense_account = 'vendor_expense';
                }
                if ( $bank_account === '' ) {
                    $bank_account = 'operating_cash';
                }
                return [
                    [ 'account_key' => $expense_account, 'entry_side' => 'debit',  'memo' => (string) ( $event->notes ?: ucfirst( str_replace( '_', ' ', $event_type ) ) ), 'meta' => $base_meta ],
                    [ 'account_key' => $bank_account,    'entry_side' => 'credit', 'memo' => 'Bank disbursement',                                                      'meta' => $base_meta ],
                ];
            case 'transfer':
                $from_account = \sanitize_key( (string) ( $metadata['from_account_key'] ?? 'stripe_clearing' ) );
                $to_account   = \sanitize_key( (string) ( $metadata['to_account_key'] ?? 'operating_cash' ) );
                if ( $from_account === '' || $to_account === '' || $from_account === $to_account ) {
                    return [];
                }
                return [
                    [ 'account_key' => $to_account,   'entry_side' => 'debit',  'memo' => (string) ( $event->notes ?: 'Transfer in' ),  'meta' => $base_meta ],
                    [ 'account_key' => $from_account, 'entry_side' => 'credit', 'memo' => (string) ( $event->notes ?: 'Transfer out' ), 'meta' => $base_meta ],
                ];
            case 'adjustment':
                $account_key        = \sanitize_key( (string) ( $metadata['account_key'] ?? '' ) );
                $offset_account_key = \sanitize_key( (string) ( $metadata['offset_account_key'] ?? '' ) );
                $direction          = \sanitize_key( (string) ( $metadata['direction'] ?? 'inflow' ) );
                if ( $account_key === '' || $offset_account_key === '' || $account_key === $offset_account_key ) {
                    return [];
                }
                if ( $direction === 'outflow' ) {
                    return [
                        [ 'account_key' => $offset_account_key, 'entry_side' => 'debit',  'memo' => (string) ( $event->notes ?: 'Adjustment offset' ), 'meta' => $base_meta ],
                        [ 'account_key' => $account_key,        'entry_side' => 'credit', 'memo' => (string) ( $event->notes ?: 'Adjustment' ),        'meta' => $base_meta ],
                    ];
                }
                return [
                    [ 'account_key' => $account_key,        'entry_side' => 'debit',  'memo' => (string) ( $event->notes ?: 'Adjustment' ),        'meta' => $base_meta ],
                    [ 'account_key' => $offset_account_key, 'entry_side' => 'credit', 'memo' => (string) ( $event->notes ?: 'Adjustment offset' ), 'meta' => $base_meta ],
                ];
        }

        return [];
    }

    public static function generateLedger( array $args = [] ): int {
        SchemaManager::ensureSchema();

        global $wpdb;

        $events_table = \Metis_Tables::get( 'finance_events' );
        $ledger_table = \Metis_Tables::get( 'finance_ledger' );

        if ( ! Support::tableExists( $events_table ) ) {
            return 0;
        }

        $event_id = isset( $args['event_id'] ) ? (int) $args['event_id'] : 0;
        $where    = '';
        $params   = [];

        if ( $event_id > 0 ) {
            $where    = 'WHERE id = %d';
            $params[] = $event_id;
        }

        $sql    = "SELECT * FROM {$events_table} {$where} ORDER BY occurred_at ASC, id ASC";
        $events = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
        $events = $events ?: [];

        $generated = 0;

        foreach ( $events as $event ) {
            $source_ref = self::sourceRefForEvent( $event );

            $wpdb->delete( $ledger_table, [ 'source_type' => 'finance_event', 'source_ref' => $source_ref ], [ '%s', '%s' ] );

            $lines = self::eventLedgerLines( $event );
            if ( empty( $lines ) ) {
                continue;
            }

            foreach ( $lines as $line ) {
                $direction = self::ledgerDirection( (string) $line['account_key'], (string) $line['entry_side'] );
                $meta      = is_array( $line['meta'] ?? null ) ? $line['meta'] : [];

                self::upsertLedgerEntry(
                    (string) $line['account_key'],
                    'finance_event',
                    $source_ref,
                    $direction,
                    (float) $event->amount,
                    ! empty( $event->occurred_at ) ? substr( (string) $event->occurred_at, 0, 10 ) : \current_time( 'Y-m-d' ),
                    'posted',
                    (string) ( $line['memo'] ?? $event->notes ?? '' ),
                    $meta,
                    [
                        'entry_side'         => (string) $line['entry_side'],
                        'contra_account_key' => count( $lines ) === 2
                            ? ( (string) ( $lines[0]['account_key'] ) === (string) $line['account_key'] ? (string) $lines[1]['account_key'] : (string) $lines[0]['account_key'] )
                            : null,
                        'event_id'           => (int) $event->id,
                        'fund_id'            => (int) $event->fund_id,
                        'campaign_id'        => ! empty( $event->campaign_id ) ? (int) $event->campaign_id : null,
                    ]
                );
                $generated++;
            }
        }

        return $generated;
    }

    public static function syncLedgerFromDeposits(): void {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        SchemaManager::ensureSchema();

        global $wpdb;
        $events_table = \Metis_Tables::get( 'finance_events' );
        $event_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table}" );

        if ( $event_count === 0 ) {
            FinanceService::backfillEventsFromLegacy( false );
        }

        self::generateLedger();
    }

    public static function syncReconciliations(): void {
        SchemaManager::ensureSchema();

        global $wpdb;

        $ledger_table = \Metis_Tables::get( 'finance_ledger' );
        $recons_table = \Metis_Tables::get( 'finance_reconciliations' );

        if ( ! Support::tableExists( $ledger_table ) || ! Support::tableExists( $recons_table ) ) {
            return;
        }

        $periods = $wpdb->get_results(
            "SELECT
                account_key,
                DATE_FORMAT(entry_date, '%Y-%m-01') AS period_start,
                LAST_DAY(entry_date) AS period_end,
                COUNT(DISTINCT source_ref) AS matched_count,
                COALESCE(SUM(CASE WHEN direction = 'outflow' THEN -amount ELSE amount END), 0) AS book_balance
             FROM {$ledger_table}
             WHERE status = 'posted'
               AND account_key IN ('operating_cash', 'stripe_clearing')
             GROUP BY account_key, DATE_FORMAT(entry_date, '%Y-%m-01')
             ORDER BY period_start DESC"
        ) ?: [];

        foreach ( $periods as $period ) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, statement_balance, notes FROM {$recons_table} WHERE account_key = %s AND period_start = %s AND period_end = %s LIMIT 1",
                    (string) $period->account_key,
                    (string) $period->period_start,
                    (string) $period->period_end
                )
            );

            $statement_balance = $existing && $existing->statement_balance !== null ? (float) $existing->statement_balance : null;
            $book_balance      = round( (float) $period->book_balance, 2 );
            $variance          = $statement_balance === null ? null : round( $statement_balance - $book_balance, 2 );
            $status            = $statement_balance === null ? 'open' : ( abs( (float) $variance ) < 0.01 ? 'matched' : 'review' );

            $payload = [
                'account_key'       => (string) $period->account_key,
                'period_start'      => (string) $period->period_start,
                'period_end'        => (string) $period->period_end,
                'book_balance'      => $book_balance,
                'statement_balance' => $statement_balance,
                'variance'          => $variance,
                'matched_count'     => (int) $period->matched_count,
                'status'            => $status,
                'notes'             => $existing->notes ?? null,
                'last_synced_at'    => \current_time( 'mysql' ),
                'updated_at'        => \current_time( 'mysql' ),
            ];

            if ( $existing ) {
                $wpdb->update( $recons_table, $payload, [ 'id' => (int) $existing->id ], [ '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s' ], [ '%d' ] );
            } else {
                $payload['created_at'] = \current_time( 'mysql' );
                $wpdb->insert( $recons_table, $payload, [ '%s', '%s', '%s', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s' ] );
            }
        }
    }
}
