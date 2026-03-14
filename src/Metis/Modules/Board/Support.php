<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class Support {
    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'board' ), '/' );
    }

    public static function meetingUrl( string $meeting_code ): string {
        return \metis_portal_url( 'board', 'meeting' ) . '?meeting=' . rawurlencode( $meeting_code );
    }

    public static function formatDatetime( string $mysql_datetime, string $format = 'M j, Y g:i a' ): string {
        $mysql_datetime = trim( $mysql_datetime );
        if ( $mysql_datetime === '' ) {
            return '—';
        }

        $ts = strtotime( $mysql_datetime );
        if ( ! $ts ) {
            return '—';
        }

        return \metis_date( $format, $ts, \metis_timezone() );
    }

    public static function currentPersonId(): int {
        if ( ! \metis_user_logged_in() ) {
            return 0;
        }

        if ( function_exists( 'metis_people_get_current_person_id' ) ) {
            return (int) \metis_people_get_current_person_id();
        }

        return 0;
    }

    public static function generateCode( string $prefix, string $table, string $column ): string {
        if ( function_exists( 'metis_generate_code' ) ) {
            return \metis_generate_code( $prefix, $table, $column );
        }

        return strtoupper( $prefix ) . \metis_rand( 100000, 999999 );
    }

    public static function docTypeLabel( string $doc_type ): string {
        $map = [
            'board_packet'       => 'Board Packet',
            'agenda'             => 'Agenda',
            'minutes'            => 'Minutes',
            'supporting_doc'     => 'Supporting Doc',
            'financial_report'   => 'Financial Report',
            'policy'             => 'Policy',
            'agenda_attachment'  => 'Agenda Attachment',
            'minutes_attachment' => 'Minutes Attachment',
            'other'              => 'Other',
        ];

        $key = \sanitize_key( $doc_type );
        return $map[ $key ] ?? ucwords( str_replace( '_', ' ', $key !== '' ? $key : 'document' ) );
    }

    public static function extractAgendaDecisionPoints( array $agenda ): array {
        $points = [];
        foreach ( $agenda as $idx => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $section_title = trim( (string) ( $row['section_name'] ?? ( $row['custom_title'] ?? ( $row['section'] ?? ( 'Section ' . ( $idx + 1 ) ) ) ) ) );
            $items         = isset( $row['items'] ) && is_array( $row['items'] ) ? $row['items'] : [];
            $item_lookup   = [];
            foreach ( $items as $item ) {
                $item_text = trim( (string) $item );
                if ( $item_text !== '' ) {
                    $item_lookup[ strtolower( $item_text ) ] = $item_text;
                }
            }

            $decision_points = isset( $row['decision_points'] ) && is_array( $row['decision_points'] ) ? $row['decision_points'] : [];
            if ( empty( $decision_points ) ) {
                $legacy_title = trim( (string) ( $row['decision_title'] ?? '' ) );
                if ( $legacy_title !== '' ) {
                    $decision_points[] = [ 'decision_title' => $legacy_title ];
                }
            }

            foreach ( $decision_points as $point ) {
                if ( ! is_array( $point ) ) {
                    continue;
                }

                $decision_title = trim( (string) ( $point['decision_title'] ?? '' ) );
                if ( $decision_title === '' ) {
                    continue;
                }

                $item_title = trim( (string) ( $point['item_title'] ?? '' ) );
                if ( $item_title !== '' && isset( $item_lookup[ strtolower( $item_title ) ] ) ) {
                    $item_title = $item_lookup[ strtolower( $item_title ) ];
                }

                $points[] = [
                    'section_title'  => $section_title,
                    'item_title'     => $item_title,
                    'decision_title' => $decision_title,
                    'point_hash'     => md5( strtolower( $section_title . '|' . $item_title . '|' . $decision_title ) ),
                ];
            }
        }

        return $points;
    }

    public static function syncDecisionPoints( int $meeting_id, array $agenda ): int {
        if ( $meeting_id < 1 ) {
            return 0;
        }

        global $wpdb;
        $decisions_table = \Metis_Tables::get( 'board_decisions' );
        $points          = self::extractAgendaDecisionPoints( $agenda );
        if ( empty( $points ) ) {
            return 0;
        }

        $created = 0;
        foreach ( $points as $point ) {
            $title         = (string) ( $point['decision_title'] ?? '' );
            $section_title = (string) ( $point['section_title'] ?? '' );
            $item_title    = (string) ( $point['item_title'] ?? '' );
            $point_hash    = (string) ( $point['point_hash'] ?? '' );
            if ( $title === '' ) {
                continue;
            }

            $existing_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$decisions_table}
                     WHERE meeting_id = %d
                       AND (
                         (agenda_point_hash IS NOT NULL AND agenda_point_hash = %s)
                         OR (LOWER(title) = LOWER(%s) AND COALESCE(agenda_section_title,'') = %s AND COALESCE(agenda_item_title,'') = %s)
                       )
                     LIMIT 1",
                    $meeting_id,
                    $point_hash,
                    $title,
                    $section_title,
                    $item_title
                )
            );

            if ( $existing_id > 0 ) {
                $wpdb->update(
                    $decisions_table,
                    [
                        'title'                => $title,
                        'agenda_section_title' => $section_title !== '' ? $section_title : null,
                        'agenda_item_title'    => $item_title !== '' ? $item_title : null,
                        'agenda_point_hash'    => $point_hash !== '' ? $point_hash : null,
                    ],
                    [ 'id' => $existing_id ],
                    [ '%s', '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                continue;
            }

            $ok = $wpdb->insert(
                $decisions_table,
                [
                    'decision_code'       => self::generateCode( 'BDC', $decisions_table, 'decision_code' ),
                    'meeting_id'          => $meeting_id,
                    'title'               => $title,
                    'agenda_section_title'=> $section_title !== '' ? $section_title : null,
                    'agenda_item_title'   => $item_title !== '' ? $item_title : null,
                    'agenda_point_hash'   => $point_hash !== '' ? $point_hash : null,
                    'decision_text'       => '',
                    'outcome'             => 'pending',
                    'votes_for'           => 0,
                    'votes_against'       => 0,
                    'votes_abstain'       => 0,
                    'passed'              => 0,
                    'passed_at'           => null,
                ],
                [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' ]
            );

            if ( $ok ) {
                $created++;
            }
        }

        return $created;
    }
}
