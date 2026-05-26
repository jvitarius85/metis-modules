<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

use DateTime;
use Exception;

final class DonorIntelligenceService {
    public static function buildData( array $args ): array {
        $db = \metis_db();

        $lifetime = ! empty( $args['lifetime'] );
        $start = isset( $args['start'] ) ? (string) $args['start'] : '';
        $end = isset( $args['end'] ) ? (string) $args['end'] : '';
        $platform = $args['platform'] ?? null;
        $status = $args['status'] ?? null;

        $service = new \Core_Reports_Service( \Metis_Tables::get( 'transactions' ) );
        $result = $service->run_donor_intelligence( [
            'start' => $lifetime ? null : ( $start !== '' ? $start : null ),
            'end' => $lifetime ? null : ( $end !== '' ? $end : null ),
            'platform' => $platform,
            'status' => $status,
            'limit' => 200,
            'lifetime' => $lifetime,
        ] );

        $rows = is_array( $result['rows'] ?? null ) ? $result['rows'] : [];
        $kpis = is_array( $result['kpis'] ?? null ) ? $result['kpis'] : [];
        $contact_map = self::contactMapForRows( $db, $rows );
        $now = new DateTime();
        $segments = [ 'recurring' => 0, 'returning' => 0, 'one-time' => 0, 'lapsed' => 0 ];

        foreach ( $rows as &$row ) {
            $did = (string) ( $row['did'] ?? '' );
            $contact = $contact_map[ $did ] ?? null;
            $full_name = $contact
                ? trim( (string) ( $contact['first_name'] ?? '' ) . ' ' . (string) ( $contact['last_name'] ?? '' ) )
                : '';

            $row['display_name'] = $full_name !== '' ? $full_name : $did;
            $row['email'] = (string) ( $contact['email'] ?? '' );
            $row['gross'] = (float) ( $row['gross'] ?? 0 );
            $row['fee'] = (float) ( $row['fee'] ?? 0 );
            $row['net'] = (float) ( $row['net'] ?? 0 );
            $row['donation_count'] = (int) ( $row['donation_count'] ?? 0 );
            $row['avg_gift'] = $row['donation_count'] > 0
                ? $row['gross'] / $row['donation_count']
                : 0;

            $segment = self::segmentForRow( $row, $now );
            $row['segment'] = $segment;
            if ( isset( $segments[ $segment ] ) ) {
                $segments[ $segment ]++;
            }
        }
        unset( $row );

        return [
            'rows' => $rows,
            'kpis' => $kpis,
            'segments' => $segments,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private static function contactMapForRows( object $db, array $rows ): array {
        $dids = array_values( array_filter( array_map(
            static fn( array $row ): string => trim( (string) ( $row['did'] ?? '' ) ),
            $rows
        ) ) );
        if ( $dids === [] ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $dids ), '%s' ) );
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $contact_rows = $db->fetchAll(
            "SELECT did, first_name, last_name, email FROM {$contacts_table} WHERE did IN ({$placeholders})",
            $dids
        );

        $contact_map = [];
        foreach ( $contact_rows as $contact ) {
            $did = trim( (string) ( $contact['did'] ?? '' ) );
            if ( $did !== '' ) {
                $contact_map[ $did ] = $contact;
            }
        }

        return $contact_map;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function segmentForRow( array $row, DateTime $now ): string {
        $months_since = 999;
        if ( ! empty( $row['last_gift'] ) ) {
            try {
                $last_date = new DateTime( (string) $row['last_gift'] );
                $months_since = (int) floor( ( (int) $now->diff( $last_date )->days ) / 30 );
            } catch ( Exception ) {
                $months_since = 999;
            }
        }

        $donation_count = (int) ( $row['donation_count'] ?? 0 );
        if ( $months_since > 12 ) {
            return 'lapsed';
        }
        if ( $donation_count >= 5 ) {
            return 'recurring';
        }
        if ( $donation_count >= 2 ) {
            return 'returning';
        }

        return 'one-time';
    }
}
