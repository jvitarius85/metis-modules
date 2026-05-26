<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class DirectorySupportService {
    public static function addDocument( string $pid, string $doc_type, string $doc_label, string $storage_ref, string $remind_at, string $expires_at, ?int $actor_id ): array {
        $people_table = \Metis_Tables::get( 'people' );
        $documents_table = \Metis_Tables::get( 'people_documents' );
        $person_id = (int) \metis_db()->scalar( "SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ] );
        if ( $person_id < 1 ) {
            \metis_runtime_send_json_error( 'Person not found.', 404 );
        }

        $lifecycle_status = ( $expires_at !== '' && strtotime( $expires_at ) < time() ) ? 'expired' : 'active';
        \metis_db()->insert( $documents_table, [
            'person_id' => $person_id,
            'doc_type' => $doc_type,
            'doc_label' => $doc_label,
            'storage_ref' => $storage_ref !== '' ? $storage_ref : null,
            'remind_at' => $remind_at !== '' ? $remind_at : null,
            'expires_at' => $expires_at !== '' ? $expires_at : null,
            'lifecycle_status' => $lifecycle_status,
            'created_by_person_id' => $actor_id,
        ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ] );

        return [
            'person_id' => $person_id,
            'doc_id' => (int) \metis_db()->lastInsertId(),
            'row' => [
                'doc_type' => $doc_type,
                'doc_label' => $doc_label,
                'storage_ref' => $storage_ref,
                'remind_at' => $remind_at,
                'expires_at' => $expires_at,
                'lifecycle_status' => $lifecycle_status,
            ],
        ];
    }

    public static function grantEmergencyAccess( string $pid, string $role_key, int $hours, string $reason, ?int $actor_id ): int {
        $people_table = \Metis_Tables::get( 'people' );
        $roles_table = \Metis_Tables::get( 'people_roles' );
        $emergency_table = \Metis_Tables::get( 'people_emergency_access' );
        $user_roles_table = \Metis_Tables::get( 'people_user_roles' );

        $person_id = (int) \metis_db()->scalar( "SELECT id FROM {$people_table} WHERE pid=%s LIMIT 1", [ $pid ] );
        $role_id = (int) \metis_db()->scalar( "SELECT id FROM {$roles_table} WHERE role_key=%s AND role_domain='metis' LIMIT 1", [ $role_key ] );
        if ( $person_id < 1 || $role_id < 1 ) {
            \metis_runtime_send_json_error( 'Invalid PID or role key.', 400 );
        }

        $starts = \metis_current_time( 'mysql' );
        $ends = gmdate( 'Y-m-d H:i:s', strtotime( $starts . ' +' . $hours . ' hours' ) );
        \metis_db()->insert( $emergency_table, [
            'person_id' => $person_id,
            'granted_role_id' => $role_id,
            'reason' => $reason !== '' ? $reason : null,
            'granted_by_person_id' => $actor_id,
            'starts_at' => $starts,
            'ends_at' => $ends,
        ], [ '%d', '%d', '%s', '%d', '%s', '%s' ] );

        $existing = (int) \metis_db()->scalar(
            "SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1",
            [ $person_id, $role_id ]
        );
        if ( $existing < 1 ) {
            \metis_db()->insert( $user_roles_table, [
                'person_id' => $person_id,
                'role_id' => $role_id,
                'start_at' => $starts,
                'end_at' => $ends,
            ], [ '%d', '%d', '%s', '%s' ] );
        }

        return $person_id;
    }

    public static function deleteDocument( int $doc_id ): array {
        $documents_table = \Metis_Tables::get( 'people_documents' );
        $doc = \metis_db()->fetchOne( "SELECT id, person_id, doc_label FROM {$documents_table} WHERE id = %d LIMIT 1", [ $doc_id ] );
        if ( ! $doc ) {
            \metis_runtime_send_json_error( 'Document not found.', 404 );
        }

        \metis_db()->delete( $documents_table, [ 'id' => $doc_id ], [ '%d' ] );
        return is_array( $doc ) ? $doc : [];
    }

    public static function revokeEmergencyAccess( int $entry_id ): array {
        $emergency_table = \Metis_Tables::get( 'people_emergency_access' );
        $entry = \metis_db()->fetchOne( "SELECT id, person_id, revoked_at FROM {$emergency_table} WHERE id = %d LIMIT 1", [ $entry_id ] );
        if ( ! $entry ) {
            \metis_runtime_send_json_error( 'Emergency entry not found.', 404 );
        }
        if ( ! empty( $entry['revoked_at'] ) ) {
            \metis_runtime_send_json_error( 'Entry already revoked.', 400 );
        }

        \metis_db()->update( $emergency_table, [ 'revoked_at' => \metis_current_time( 'mysql' ) ], [ 'id' => $entry_id ], [ '%s' ], [ '%d' ] );
        return is_array( $entry ) ? $entry : [];
    }

    public static function searchPeople( string $query ): array {
        $people_table = \Metis_Tables::get( 'people' );
        if ( ! $people_table ) {
            return [];
        }

        $like = '%' . \metis_db()->escapeLike( $query ) . '%';
        return \metis_db()->fetchAll(
            "SELECT pid, first_name, last_name, display_name, email
             FROM {$people_table}
             WHERE pid LIKE %s
                OR first_name LIKE %s
                OR last_name LIKE %s
                OR display_name LIKE %s
                OR email LIKE %s
             ORDER BY first_name ASC, last_name ASC, display_name ASC, email ASC
             LIMIT 12",
            [ $like, $like, $like, $like, $like ]
        ) ?: [];
    }

    public static function searchDonors( string $query ): array {
        $contacts_table = \Metis_Tables::get( 'contacts' );
        if ( ! $contacts_table ) {
            return [];
        }

        $like = '%' . \metis_db()->escapeLike( $query ) . '%';
        return \metis_db()->fetchAll(
            "SELECT did, first_name, last_name, email
             FROM {$contacts_table}
             WHERE did IS NOT NULL
               AND did <> ''
               AND (did LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)
             ORDER BY first_name ASC, last_name ASC, did ASC
             LIMIT 12",
            [ $like, $like, $like, $like ]
        ) ?: [];
    }

    public static function activityPage( int $page, int $page_size = 15, string $query = '' ): array {
        $activity_table = \Metis_Tables::get( 'people_activity' );
        $people_table = \Metis_Tables::get( 'people' );
        if ( $page < 1 ) {
            $page = 1;
        }
        if ( $page_size < 1 ) {
            $page_size = 50;
        }
        $query = trim( $query );
        $where_sql = '';
        $where_args = [];
        if ( $query !== '' ) {
            $like = '%' . \metis_db()->escapeLike( $query ) . '%';
            $where_sql = " WHERE (
                a.activity_type LIKE %s
                OR a.summary LIKE %s
                OR p.display_name LIKE %s
                OR p.pid LIKE %s
                OR ap.display_name LIKE %s
                OR ap.pid LIKE %s
            )";
            $where_args = [ $like, $like, $like, $like, $like, $like ];
        }
        $total_sql = "SELECT COUNT(*)
                      FROM {$activity_table} a
                      LEFT JOIN {$people_table} p ON p.id = a.person_id
                      LEFT JOIN {$people_table} ap ON ap.id = a.actor_person_id" . $where_sql;
        $total = (int) ( $where_sql !== '' ? \metis_db()->scalar( $total_sql, $where_args ) : \metis_db()->scalar( $total_sql ) );
        $total_pages = max( 1, (int) ceil( $total / $page_size ) );
        if ( $page > $total_pages ) {
            $page = $total_pages;
        }
        $offset = ( $page - 1 ) * $page_size;
        $rows_sql =
            "SELECT a.id, a.activity_type, a.summary, a.details, a.created_at,
                    p.pid AS target_pid, p.display_name AS target_name,
                    ap.pid AS actor_pid, ap.display_name AS actor_name
             FROM {$activity_table} a
             LEFT JOIN {$people_table} p ON p.id = a.person_id
             LEFT JOIN {$people_table} ap ON ap.id = a.actor_person_id
             {$where_sql}
             ORDER BY a.created_at DESC
             LIMIT {$page_size} OFFSET {$offset}";
        $rows = $where_sql !== '' ? \metis_db()->fetchAll( $rows_sql, $where_args ) : \metis_db()->fetchAll( $rows_sql );
        if ( ! is_array( $rows ) ) {
            $rows = [];
        }

        return [
            'rows' => $rows,
            'page' => $page,
            'total_pages' => $total_pages,
            'has_prev' => $page > 1,
            'has_next' => $page < $total_pages,
            'prev_page' => $page > 1 ? ( $page - 1 ) : 1,
            'next_page' => $page < $total_pages ? ( $page + 1 ) : $total_pages,
        ];
    }
}
