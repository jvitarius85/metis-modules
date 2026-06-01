<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

final class TransactionMutationService {
    public static function ensureSupportingTables(): void {
        $db = \metis_db();
        $charset = function_exists( 'metis_core_db_charset_collate' ) ? \metis_core_db_charset_collate() : 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        $notes_table = \Metis_Tables::get( 'transaction_notes' );
        $refunds_table = \Metis_Tables::get( 'transaction_refunds' );

        $db->execute( "CREATE TABLE IF NOT EXISTS {$notes_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tid VARCHAR(32) NOT NULL,
            note TEXT NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tid (tid)
        ) {$charset}" );

        $db->execute( "CREATE TABLE IF NOT EXISTS {$refunds_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tid VARCHAR(32) NOT NULL,
            refund_date DATE NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'manual',
            stripe_refund_id VARCHAR(64) DEFAULT NULL,
            refunded_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tid (tid),
            KEY stripe_refund_id (stripe_refund_id)
        ) {$charset}" );

        $note_columns = $db->column( "SHOW COLUMNS FROM {$notes_table}" );
        if ( ! in_array( 'updated_at', $note_columns, true ) ) {
            $db->execute( "ALTER TABLE {$notes_table} ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" );
        }

        $refund_columns = $db->column( "SHOW COLUMNS FROM {$refunds_table}" );
        $refund_alters = [
            'refund_date' => "ADD COLUMN refund_date DATE NULL AFTER tid",
            'notes' => "ADD COLUMN notes TEXT DEFAULT NULL AFTER reason",
            'source' => "ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'manual' AFTER notes",
            'stripe_refund_id' => "ADD COLUMN stripe_refund_id VARCHAR(64) DEFAULT NULL AFTER source",
            'refunded_by' => "ADD COLUMN refunded_by BIGINT UNSIGNED DEFAULT NULL AFTER stripe_refund_id",
            'updated_at' => "ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        foreach ( $refund_alters as $column => $alter_sql ) {
            if ( ! in_array( $column, $refund_columns, true ) ) {
                $db->execute( "ALTER TABLE {$refunds_table} {$alter_sql}" );
            }
        }
    }

    public static function addTransactionNote( string $tid, string $note, ?int $user_id ): array {
        $now = \metis_current_time( 'mysql' );
        $ok = \metis_db()->insert( \Metis_Tables::get( 'transaction_notes' ), [
            'tid'        => $tid,
            'note'       => $note,
            'user_id'    => $user_id,
            'created_at' => $now,
            'updated_at' => $now,
        ] );

        if ( ! $ok ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Failed to save note.' ];
        }

        return [
            'ok' => true,
            'note' => [
                'id' => (int) \metis_db()->lastInsertId(),
                'note' => $note,
                'display_name' => 'You',
                'created_at' => $now,
            ],
        ];
    }

    public static function recordTransactionRefund( array $transaction, string $tid, float $amount, string $reason, string $notes, string $source, ?int $user_id ): array {
        $db = \metis_db();
        $refunds_table = \Metis_Tables::get( 'transaction_refunds' );
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $now = \metis_current_time( 'mysql' );
        $refund_date = \metis_current_time( 'Y-m-d' );
        $stripe_refund_id = null;
        $stripe_charge_id = trim( (string) ( $transaction['stripe_charge_id'] ?? '' ) );
        $stripe_payment_intent = trim( (string) ( $transaction['stripe_pay_int'] ?? '' ) );
        $existing_refunded = TransactionRecordService::refundedAmount( $tid );
        $remaining = max( 0, (float) ( $transaction['amount'] ?? 0 ) - $existing_refunded );

        if ( $amount > $remaining + 0.0001 ) {
            return [ 'ok' => false, 'status' => 400, 'message' => 'Refund amount exceeds the remaining refundable amount.' ];
        }

        if ( $source === 'stripe' ) {
            $stripe = function_exists( 'metis_stripe_client' ) ? \metis_stripe_client() : null;
            if ( ! $stripe ) {
                return [ 'ok' => false, 'status' => 500, 'message' => 'Stripe is not configured.' ];
            }
            if ( $stripe_charge_id === '' && $stripe_payment_intent === '' ) {
                return [ 'ok' => false, 'status' => 400, 'message' => 'This transaction is not linked to a Stripe charge or payment intent.' ];
            }

            $stripe_payload = [
                'amount' => (int) round( $amount * 100 ),
                'metadata' => [
                    'metis_tid' => $tid,
                    'metis_reason' => $reason,
                ],
            ];
            if ( $stripe_charge_id !== '' ) {
                $stripe_payload['charge'] = $stripe_charge_id;
            } else {
                $stripe_payload['payment_intent'] = $stripe_payment_intent;
            }
            if ( $reason !== '' ) {
                $stripe_payload['reason'] = in_array( $reason, [ 'duplicate', 'fraudulent', 'requested_by_customer' ], true )
                    ? $reason
                    : 'requested_by_customer';
            }

            try {
                $stripe_refund = $stripe->createRefund(
                    $stripe_payload,
                    [ 'idempotency_key' => 'metis-refund-' . $tid . '-' . hash( 'sha256', $amount . '|' . $reason . '|' . $notes . '|' . $now ) ]
                );
                $stripe_refund_id = (string) ( $stripe_refund->id ?? '' );
            } catch ( \Throwable $e ) {
                return [ 'ok' => false, 'status' => 502, 'message' => 'Stripe refund failed: ' . $e->getMessage() ];
            }
        }

        $ok = $db->insert( $refunds_table, [
            'tid'         => $tid,
            'refund_date' => $refund_date,
            'amount'      => $amount,
            'reason'      => $reason !== '' ? $reason : null,
            'notes'       => $notes !== '' ? $notes : null,
            'source'      => $source,
            'stripe_refund_id' => $stripe_refund_id !== '' ? $stripe_refund_id : null,
            'refunded_by' => $user_id,
            'created_at'  => $now,
            'updated_at'  => $now,
        ] );

        if ( ! $ok ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Failed to record refund.' ];
        }

        $total_refunded = TransactionRecordService::refundedAmount( $tid );
        $tx_columns = $db->column( "SHOW COLUMNS FROM {$transactions_table}" );
        $update = [ 'updated_at' => $now ];
        if ( $stripe_refund_id !== null && $stripe_refund_id !== '' && in_array( 'stripe_refund_id', $tx_columns, true ) ) {
            $update['stripe_refund_id'] = $stripe_refund_id;
        }
        if ( $total_refunded >= (float) ( $transaction['amount'] ?? 0 ) ) {
            $update['status'] = 'Refunded';
            if ( in_array( 'refunded', $tx_columns, true ) ) {
                $update['refunded'] = 1;
            }
            if ( in_array( 'refunded_at', $tx_columns, true ) ) {
                $update['refunded_at'] = $now;
            }
        }
        $db->update( $transactions_table, $update, [ 'tid' => $tid ] );

        return [
            'ok' => true,
            'refund' => [
                'id' => (int) $db->lastInsertId(),
                'amount' => $amount,
                'reason' => $reason,
                'notes' => $notes,
                'source' => $source,
                'stripe_refund_id' => $stripe_refund_id,
                'display_name' => 'You',
                'created_at' => $now,
            ],
            'total_refunded' => $total_refunded,
            'net_after_refunds' => max( 0, (float) ( $transaction['amount'] ?? 0 ) - $total_refunded ),
        ];
    }

    public static function updateTransactionCampaign( string $tid, string $campaign_code ): array {
        $campaign_row = \metis_db()->fetchOne(
            'SELECT cid, cname FROM ' . \Metis_Tables::get( 'campaigns' ) . ' WHERE cid = %s LIMIT 1',
            [ $campaign_code ]
        );
        if ( ! is_array( $campaign_row ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Campaign not found.' ];
        }

        $updated = \metis_db()->update(
            \Metis_Tables::get( 'transactions' ),
            [ 'campaign_code' => $campaign_code ],
            [ 'tid' => $tid ]
        );
        if ( $updated === false ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Unable to update transaction campaign.' ];
        }

        return [
            'ok' => true,
            'campaign_code' => $campaign_code,
            'campaign_name' => (string) ( $campaign_row['cname'] ?? $campaign_code ),
        ];
    }
}
