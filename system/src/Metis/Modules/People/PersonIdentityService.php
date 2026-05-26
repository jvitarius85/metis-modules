<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class PersonIdentityService {
    public static function consumeChallenge( string $challenge_key, string $purpose, ?int $person_id = null ): ?array {
        $table = \Metis_Tables::get( 'people_auth_challenges' );
        $row = \metis_db()->fetchOne(
            "SELECT * FROM {$table}
             WHERE challenge_key = %s
               AND purpose = %s
               AND consumed_at IS NULL
               AND expires_at >= UTC_TIMESTAMP()
             LIMIT 1",
            [ $challenge_key, $purpose ]
        );
        if ( ! $row ) {
            return null;
        }
        if ( $person_id !== null && (int) $row['person_id'] !== $person_id ) {
            return null;
        }
        \metis_db()->update(
            $table,
            [ 'consumed_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'id' => (int) $row['id'] ],
            [ '%s' ],
            [ '%d' ]
        );

        return $row;
    }

    public static function resolvePersonRecord( int $person_id = 0, string $pid = '' ): array {
        $db = \metis_db();
        $people_table = \Metis_Tables::get( 'people' );
        if ( ! $people_table ) {
            return [ 'ok' => false, 'error' => 'People table is not available.', 'status' => 500 ];
        }

        $pid = trim( $pid );
        $person_by_pid = null;
        $person_by_id = null;
        $pid_lookup_mode = 'none';
        $select_fields = 'id, pid';

        if ( $pid !== '' ) {
            $person_by_pid = $db->fetchOne( "SELECT {$select_fields} FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ] );
            if ( $person_by_pid ) {
                $pid_lookup_mode = 'exact';
            }
            if ( ! $person_by_pid ) {
                $person_by_pid = $db->fetchOne( "SELECT {$select_fields} FROM {$people_table} WHERE UPPER(pid) = UPPER(%s) LIMIT 1", [ $pid ] );
                if ( $person_by_pid ) {
                    $pid_lookup_mode = 'case_insensitive';
                }
            }
        }

        if ( $person_id > 0 ) {
            $person_by_id = $db->fetchOne( "SELECT {$select_fields} FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ] );
        }

        if ( $person_by_pid && $person_by_id && (int) ( $person_by_pid['id'] ?? 0 ) !== (int) ( $person_by_id['id'] ?? 0 ) ) {
            return [ 'ok' => false, 'error' => 'Person identifier mismatch.', 'status' => 409 ];
        }

        $person = $person_by_pid ?: $person_by_id;
        if ( ! $person ) {
            $row_count = (int) $db->scalar( "SELECT COUNT(*) FROM {$people_table}" );
            $sample_row = $db->fetchOne( "SELECT id, pid FROM {$people_table} ORDER BY id ASC LIMIT 1" );
            $details = [];
            if ( $person_id > 0 ) {
                $details[] = 'person_id=' . $person_id;
                $details[] = 'by_id=no';
            }
            if ( $pid !== '' ) {
                $details[] = 'pid=' . $pid;
                $details[] = 'by_pid=no';
            }
            $details[] = 'table=' . $people_table;
            $details[] = 'rows=' . $row_count;
            $details[] = 'db=' . (string) \metis_runtime_config_get( 'db_name', '' );
            $details[] = 'host=' . (string) \metis_runtime_config_get( 'db_host', '' );
            if ( is_array( $sample_row ) && $sample_row !== [] ) {
                $details[] = 'sample_id=' . (string) ( $sample_row['id'] ?? '' );
                $details[] = 'sample_pid=' . (string) ( $sample_row['pid'] ?? '' );
            }
            return [
                'ok' => false,
                'error' => 'Person not found. (' . implode( ', ', $details ) . ')',
                'status' => 404,
                'debug' => [
                    'table' => $people_table,
                    'person_id' => $person_id,
                    'pid' => $pid,
                    'pid_lookup_mode' => $pid_lookup_mode,
                ],
            ];
        }

        return [ 'ok' => true, 'person' => $person ];
    }
}
