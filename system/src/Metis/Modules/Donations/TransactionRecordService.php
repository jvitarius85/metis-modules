<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

final class TransactionRecordService {
    public static function findByTransactionId( string $transaction_id ): ?array {
        $transaction_id = trim( $transaction_id );
        if ( $transaction_id === '' ) {
            return null;
        }

        $row = \metis_db()->fetchOne(
            'SELECT tid, amount, stripe_charge_id, stripe_pay_int, stripe_refund_id FROM ' . \Metis_Tables::get( 'transactions' ) . ' WHERE tid = %s LIMIT 1',
            [ $transaction_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function refundedAmount( string $transaction_id ): float {
        $transaction_id = trim( $transaction_id );
        if ( $transaction_id === '' ) {
            return 0.0;
        }

        return (float) \metis_db()->scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM ' . \Metis_Tables::get( 'transaction_refunds' ) . ' WHERE tid = %s',
            [ $transaction_id ]
        );
    }

    public static function findByStripeChargeId( string $charge_id ): ?array {
        $charge_id = trim( $charge_id );
        if ( $charge_id === '' ) {
            return null;
        }

        $row = \metis_db()->fetchOne(
            'SELECT tid, tran_date, amount FROM ' . \Metis_Tables::get( 'transactions' ) . ' WHERE stripe_charge_id = %s LIMIT 1',
            [ $charge_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function hasStripeRefundRecord( string $refund_id ): bool {
        $refund_id = trim( $refund_id );
        if ( $refund_id === '' ) {
            return false;
        }

        return (int) \metis_db()->scalar(
            'SELECT id FROM ' . \Metis_Tables::get( 'transaction_refunds' ) . ' WHERE stripe_refund_id = %s LIMIT 1',
            [ $refund_id ]
        ) > 0;
    }

    public static function countByDepositBatchId( string $deposit_code ): int {
        $deposit_code = trim( $deposit_code );
        if ( $deposit_code === '' ) {
            return 0;
        }

        return (int) \metis_db()->scalar(
            'SELECT COUNT(*) FROM ' . \Metis_Tables::get( 'transactions' ) . ' WHERE deposit_batch_id = %s',
            [ $deposit_code ]
        );
    }

    /**
     * @return array<int,object>
     */
    public static function listStripeTransactionsMissingPayout(): array {
        $table = \Metis_Tables::get( 'transactions' );
        return array_map(
            static fn( array $row ): object => (object) $row,
            \metis_db()->fetchAll(
                "SELECT id, tid, stripe_pay_int, stripe_charge_id, stripe_balance_txn, stripe_payout_id, deposit_batch_id, tran_date
                 FROM {$table}
                 WHERE platform = 'ST'
                   AND stripe_pay_int  IS NOT NULL
                   AND stripe_pay_int  <> ''
                   AND ( stripe_payout_id IS NULL OR stripe_payout_id = '' )
                 ORDER BY tran_date ASC"
            )
        );
    }

    public static function findDepositLinkCandidateByBalanceTransaction( string $balance_txn_id ): ?array {
        $balance_txn_id = trim( $balance_txn_id );
        if ( $balance_txn_id === '' ) {
            return null;
        }

        $row = \metis_db()->fetchOne(
            'SELECT id, tid, deposit_batch_id FROM ' . \Metis_Tables::get( 'transactions' ) . ' WHERE stripe_balance_txn = %s LIMIT 1',
            [ $balance_txn_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function findDepositLinkCandidateByChargeId( string $charge_id ): ?array {
        $charge_id = trim( $charge_id );
        if ( $charge_id === '' ) {
            return null;
        }

        $row = \metis_db()->fetchOne(
            'SELECT id, tid, deposit_batch_id FROM ' . \Metis_Tables::get( 'transactions' ) . ' WHERE stripe_charge_id = %s LIMIT 1',
            [ $charge_id ]
        );

        return is_array( $row ) ? $row : null;
    }

    /**
     * @return array<string,bool>
     */
    public static function existingStripeChargeIds(): array {
        return array_flip( \metis_db()->column(
            'SELECT stripe_charge_id FROM ' . \Metis_Tables::get( 'transactions' ) . " WHERE stripe_charge_id IS NOT NULL AND stripe_charge_id <> ''"
        ) );
    }

    /**
     * @return array<string,bool>
     */
    public static function existingStripePaymentIntentIds(): array {
        return array_flip( \metis_db()->column(
            'SELECT stripe_pay_int FROM ' . \Metis_Tables::get( 'transactions' ) . " WHERE stripe_pay_int IS NOT NULL AND stripe_pay_int <> ''"
        ) );
    }

    public static function idByStripeChargeOrPaymentIntent( string $charge_id, string $payment_intent_id ): int {
        $charge_id = trim( $charge_id );
        $payment_intent_id = trim( $payment_intent_id );
        if ( $charge_id === '' && $payment_intent_id === '' ) {
            return 0;
        }

        return (int) \metis_db()->scalar(
            'SELECT id FROM ' . \Metis_Tables::get( 'transactions' ) . ' WHERE stripe_charge_id = %s OR stripe_pay_int = %s LIMIT 1',
            [ $charge_id, $payment_intent_id ]
        );
    }

    public static function localTotalsByDepositCode( string $deposit_code ): ?array {
        $deposit_code = trim( $deposit_code );
        if ( $deposit_code === '' ) {
            return null;
        }

        $row = \metis_db()->fetchOne(
            'SELECT SUM( amount + IFNULL(fee, 0) ) AS gross, SUM( IFNULL(fee, 0) ) AS fees, SUM( amount ) AS net FROM ' . \Metis_Tables::get( 'transactions' ) . ' WHERE deposit_batch_id = %s',
            [ $deposit_code ]
        );

        return is_array( $row ) ? $row : null;
    }

    public static function linkOrphanedByPayoutId( string $deposit_code, string $payout_id ): int {
        $deposit_code = trim( $deposit_code );
        $payout_id = trim( $payout_id );
        if ( $deposit_code === '' || $payout_id === '' ) {
            return 0;
        }

        $table = \Metis_Tables::get( 'transactions' );
        $prepared = \metis_db()->prepare(
            "UPDATE {$table}
             SET deposit_batch_id = %s,
                 deposit_date = deposit_date
             WHERE stripe_payout_id = %s
               AND ( deposit_batch_id IS NULL OR deposit_batch_id = '' )",
            $deposit_code,
            $payout_id
        );

        $result = \metis_db()->execute( $prepared );
        return $result ? (int) $result : 0;
    }

    public static function hasPayoutAmountForDeposit( string $deposit_code, int $amount_cents ): bool {
        $deposit_code = trim( $deposit_code );
        if ( $deposit_code === '' ) {
            return false;
        }

        return (int) \metis_db()->scalar(
            'SELECT COUNT(*) FROM ' . \Metis_Tables::get( 'transactions' ) . ' WHERE deposit_batch_id = %s AND ROUND( payout * 100 ) = %d',
            [ $deposit_code, $amount_cents ]
        ) > 0;
    }
}
