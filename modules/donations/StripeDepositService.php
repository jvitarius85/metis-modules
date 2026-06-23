<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

final class StripeDepositService {
    public static function findByPayoutId( string $payout_id ): ?array {
        $payout_id = trim( $payout_id );
        if ( $payout_id === '' ) {
            return null;
        }

        $table = \Metis_Tables::get( 'deposits' );
        $row = \metis_db()->fetchOne(
            "SELECT id, provider_ref FROM {$table}
             WHERE provider = 'stripe'
               AND ( provider_ref = %s OR meta LIKE %s )
             LIMIT 1",
            [ $payout_id, '%' . \metis_db()->escapeLike( '"payout_id":"' . $payout_id . '"' ) . '%' ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function findByDateWindow( string $start_date, string $end_date ): ?array {
        if ( trim( $start_date ) === '' || trim( $end_date ) === '' ) {
            return null;
        }

        $table = \Metis_Tables::get( 'deposits' );
        $row = \metis_db()->fetchOne(
            "SELECT id, provider_ref FROM {$table}
             WHERE provider = 'stripe'
               AND deposit_date >= %s
               AND deposit_date <= %s
             ORDER BY deposit_date ASC LIMIT 1",
            [ $start_date, $end_date ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function syncFromStripePayout( array $data ): array {
        $table = \Metis_Tables::get( 'deposits' );
        $payout_id = trim( (string) ( $data['payout_id'] ?? '' ) );
        $arrival_date = trim( (string) ( $data['arrival_date'] ?? '' ) );
        $net_amount = (float) ( $data['net_amount'] ?? 0 );
        $currency = strtolower( trim( (string) ( $data['currency'] ?? 'usd' ) ) );
        $status = trim( (string) ( $data['status'] ?? 'paid' ) );
        $generated_via = trim( (string) ( $data['generated_via'] ?? 'stripe-sync' ) );

        $existing = self::findByPayoutId( $payout_id );
        if ( $existing ) {
            \metis_db()->update(
                $table,
                [
                    'deposit_date' => $arrival_date,
                    'status' => $status === 'paid' ? 'deposited' : $status,
                    'updated_at' => \metis_current_time( 'mysql' ),
                ],
                [ 'id' => (int) $existing['id'] ]
            );

            return [
                'id' => (int) ( $existing['id'] ?? 0 ),
                'provider_ref' => (string) ( $existing['provider_ref'] ?? '' ),
                'created' => false,
            ];
        }

        $payload = [
            'provider' => 'stripe',
            'deposit_type' => 'stripe',
            'source' => 'automatic',
            'status' => $status === 'paid' ? 'deposited' : $status,
            'deposit_date' => $arrival_date,
            'expected_date' => $arrival_date,
            'total_amount' => $net_amount,
            'currency' => $currency,
            'batch_count' => 1,
            'transaction_count' => 0,
            'meta' => \metis_json_encode( [
                'payout_id' => $payout_id,
                'arrival_date' => $arrival_date,
                'generated_via' => $generated_via,
            ] ),
            'created_at' => \metis_current_time( 'mysql' ),
            'updated_at' => \metis_current_time( 'mysql' ),
        ];

        if ( function_exists( 'metis_entity_id_service' ) ) {
            $payload = \metis_entity_id_service()->assignForInsert( 'donation_deposit', $payload );
        } else {
            $payload['provider_ref'] = \metis_generate_code( 'DP', $table, 'provider_ref' );
        }

        $provider_ref = (string) ( $payload['deposit_uid'] ?? $payload['provider_ref'] ?? '' );
        $ok = \metis_db()->insert( $table, $payload );
        $id = $ok ? (int) \metis_db()->lastInsertId() : 0;
        if ( $id > 0 && function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( 'donation_deposit', $id, $provider_ref );
        }

        return [
            'id' => $id,
            'provider_ref' => $provider_ref,
            'created' => $id > 0,
        ];
    }

    public static function listMissingTotals(): array {
        $table = \Metis_Tables::get( 'deposits' );
        return array_map(
            static fn( array $row ): object => (object) $row,
            \metis_db()->fetchAll(
                "SELECT id, provider_ref, meta
                 FROM {$table}
                 WHERE provider = 'stripe'
                   AND (
                       fee_total IS NULL OR gross_total IS NULL OR net_total IS NULL
                       OR ( net_total = 0 AND gross_total IS NOT NULL AND meta LIKE '%payout_id%' )
                   )
                 ORDER BY deposit_date DESC"
            )
        );
    }

    public static function listWithPayoutMeta(): array {
        $table = \Metis_Tables::get( 'deposits' );
        return array_map(
            static fn( array $row ): object => (object) $row,
            \metis_db()->fetchAll(
                "SELECT id, provider_ref, meta, deposit_date
                 FROM {$table}
                 WHERE provider = 'stripe' AND meta LIKE '%payout_id%'
                 ORDER BY deposit_date DESC"
            )
        );
    }

    /**
     * @return array<string,string>
     */
    public static function payoutToDepositMap(): array {
        $map = [];
        foreach ( self::listAllStripeMetaRows() as $row ) {
            $meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
            $payout_id = is_array( $meta ) ? trim( (string) ( $meta['payout_id'] ?? '' ) ) : '';
            $provider_ref = trim( (string) ( $row['provider_ref'] ?? '' ) );
            if ( $payout_id !== '' && $provider_ref !== '' ) {
                $map[ $payout_id ] = $provider_ref;
            }
        }

        return $map;
    }

    public static function depositDateByCode( string $deposit_code ): ?string {
        $deposit_code = trim( $deposit_code );
        if ( $deposit_code === '' ) {
            return null;
        }

        $table = \Metis_Tables::get( 'deposits' );
        $value = \metis_db()->scalar(
            "SELECT deposit_date FROM {$table} WHERE provider_ref = %s",
            [ $deposit_code ]
        );

        return is_scalar( $value ) && (string) $value !== '' ? (string) $value : null;
    }

    public static function financialSnapshot( int $deposit_id ): ?array {
        if ( $deposit_id <= 0 ) {
            return null;
        }

        $table = \Metis_Tables::get( 'deposits' );
        $row = \metis_db()->fetchOne(
            "SELECT fee_total, gross_total, net_total, transaction_count FROM {$table} WHERE id = %d",
            [ $deposit_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function updateFinancialTotals( int $deposit_id, float $fee_total, float $gross_total, float $net_total ): void {
        if ( $deposit_id <= 0 ) {
            return;
        }

        \metis_db()->update(
            \Metis_Tables::get( 'deposits' ),
            [
                'fee_total' => $fee_total,
                'gross_total' => $gross_total,
                'net_total' => $net_total,
                'updated_at' => \metis_current_time( 'mysql' ),
            ],
            [ 'id' => $deposit_id ]
        );
    }

    public static function refreshTransactionCount( string $deposit_code ): int {
        $deposit_code = trim( $deposit_code );
        if ( $deposit_code === '' ) {
            return 0;
        }

        $tx_table = \Metis_Tables::get( 'transactions' );
        $deposits_table = \Metis_Tables::get( 'deposits' );
        $count = (int) \metis_db()->scalar(
            "SELECT COUNT(*) FROM {$tx_table} WHERE deposit_batch_id = %s",
            [ $deposit_code ]
        );

        \metis_db()->update(
            $deposits_table,
            [ 'transaction_count' => $count, 'updated_at' => \metis_current_time( 'mysql' ) ],
            [ 'provider_ref' => $deposit_code ]
        );

        return $count;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function listAllStripeMetaRows(): array {
        $table = \Metis_Tables::get( 'deposits' );
        return \metis_db()->fetchAll(
            "SELECT provider_ref, meta FROM {$table} WHERE provider = 'stripe'"
        );
    }
}
