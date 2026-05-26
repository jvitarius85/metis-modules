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
}
