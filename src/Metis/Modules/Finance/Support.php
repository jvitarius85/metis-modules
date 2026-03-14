<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class Support {
    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'finance' ), '/' );
    }

    public static function tableExists( string $table ): bool {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return $exists === $table;
    }

    public static function tableHasColumn( string $table, string $column ): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
        return $found === $column;
    }

    public static function currency( float $amount ): string {
        return '$' . \number_format_i18n( $amount, 2 );
    }

    public static function shortDate( ?string $date ): string {
        if ( empty( $date ) ) {
            return '—';
        }

        $ts = strtotime( $date );
        return $ts ? \metis_date( 'M j, Y', $ts ) : '—';
    }

    public static function signedAmount( string $direction, float $amount ): float {
        return strtolower( $direction ) === 'outflow' ? -1 * abs( $amount ) : abs( $amount );
    }

    public static function eventTypes(): array {
        return [
            'stripe_charge',
            'stripe_fee',
            'stripe_refund',
            'stripe_payout',
            'manual_expense',
            'vendor_payment',
            'check_written',
            'transfer',
            'adjustment',
        ];
    }

    public static function systemAccountSeed(): array {
        return [
            [ 'account_key' => 'operating_cash',        'label' => 'Bank Account',      'category' => 'asset',   'normal_balance' => 'debit' ],
            [ 'account_key' => 'stripe_clearing',       'label' => 'Stripe Clearing',   'category' => 'asset',   'normal_balance' => 'debit' ],
            [ 'account_key' => 'contributions_revenue', 'label' => 'Donations Revenue', 'category' => 'revenue', 'normal_balance' => 'credit' ],
            [ 'account_key' => 'processing_fees',       'label' => 'Processing Fees',   'category' => 'expense', 'normal_balance' => 'debit' ],
            [ 'account_key' => 'vendor_expense',        'label' => 'Vendor Expense',    'category' => 'expense', 'normal_balance' => 'debit' ],
            [ 'account_key' => 'refund_expense',        'label' => 'Refund Expense',    'category' => 'expense', 'normal_balance' => 'debit' ],
        ];
    }

    public static function decodeJson( mixed $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }

        if ( ! is_string( $value ) || $value === '' ) {
            return [];
        }

        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    public static function activityTypeOptions(): array {
        return [
            'manual_expense' => 'Expense',
            'vendor_payment' => 'Vendor Payment',
            'check_written'  => 'Check',
            'transfer'       => 'Transfer',
            'adjustment'     => 'Adjustment',
        ];
    }

    public static function activityLabel( string $type ): string {
        $options = self::activityTypeOptions() + [
            'stripe_charge' => 'Donation Received',
            'stripe_fee'    => 'Processing Fee',
            'stripe_refund' => 'Refund',
            'stripe_payout' => 'Settlement',
            'manual'        => 'Legacy Manual Entry',
        ];

        return $options[ \sanitize_key( $type ) ] ?? ucwords( str_replace( '_', ' ', \sanitize_key( $type ) ) );
    }
}
