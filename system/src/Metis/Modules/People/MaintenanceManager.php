<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class MaintenanceManager {
    private static bool $done = false;

    public static function runMaintenance(): void {
        if ( self::$done ) {
            return;
        }

        self::$done = true;
        $db = \metis_db();

        $key = 'metis_people_maintenance_last_run';
        $last = (int) \metis_get_option( $key, 0 );
        $now = time();
        $now_mysql = \function_exists( 'metis_current_time' ) ? \metis_current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        if ( $last > 0 && ( $now - $last ) < 300 ) {
            return;
        }

        \metis_update_option( $key, $now, false );
        $requests_table = \Metis_Tables::get( 'people_access_requests' );
        $documents_table = \Metis_Tables::get( 'people_documents' );

        $expired_requests = (int) $db->executePrepared(
            "UPDATE {$requests_table}
             SET status = 'expired', updated_at = %s
             WHERE status = 'pending'
               AND expires_at IS NOT NULL
               AND expires_at < %s",
            [ $now_mysql, $now_mysql ]
        );
        if ( $expired_requests > 0 ) {
            ActivityService::logActivity( null, 'requests_expired', 'Expired pending access requests', [ 'count' => $expired_requests ] );
        }

        $expired_docs = (int) $db->executePrepared(
            "UPDATE {$documents_table}
             SET lifecycle_status = 'expired'
             WHERE lifecycle_status <> 'expired'
               AND expires_at IS NOT NULL
               AND expires_at < %s",
            [ $now_mysql ]
        );
        if ( $expired_docs > 0 ) {
            ActivityService::logActivity( null, 'documents_expired', 'Updated expired document statuses', [ 'count' => $expired_docs ] );
        }
    }
}
