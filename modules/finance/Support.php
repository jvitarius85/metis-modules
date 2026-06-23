<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class Support {
    public static function baseUrl(): string {
        return rtrim( (string) \metis_portal_url( 'finance' ), '/' );
    }

    public static function tableExists( string $table ): bool {
        $table = trim( $table );
        if ( $table === '' ) {
            return false;
        }

        return \metis_db()->scalar( 'SHOW TABLES LIKE %s', [ $table ] ) !== null;
    }

    public static function now(): string {
        return \metis_current_time( 'mysql' );
    }

    public static function asJson( array $value ): string {
        return \metis_json_encode( $value ) ?: '{}';
    }

    public static function decodeJson( string $value ): array {
        $value = trim( $value );
        if ( $value === '' ) {
            return [];
        }

        $decoded = \metis_json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    public static function currency( float $amount ): string {
        $prefix = '$';
        return $prefix . \metis_number_format( $amount, 2 );
    }
}
