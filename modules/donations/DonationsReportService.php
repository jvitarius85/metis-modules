<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

final class DonationsReportService {
    public static function tableExists( string $table ): bool {
        $db = \metis_db();
        return ! empty( $db->scalar( 'SHOW TABLES LIKE %s', [ $db->escapeLike( $table ) ] ) );
    }

    public static function buildReportData( array $input ): array {
        $db = \metis_db();

        $transactions_table = \Metis_Tables::get( 'transactions' );
        $campaigns_table = \Metis_Tables::get( 'campaigns' );

        $start = \metis_text_clean( $input['start'] ?? '' );
        $end = \metis_text_clean( $input['end'] ?? '' );
        $group = \metis_key_clean( $input['group'] ?? 'month' );
        $metrics = $input['metrics'] ?? [];
        $filters = $input['filters'] ?? [];
        $lifetime = ! empty( $input['lifetime'] );

        if ( ! is_array( $metrics ) ) {
            $metrics = [];
        }
        $metrics = array_values( array_unique( array_map( '\metis_key_clean', $metrics ) ) );
        if ( $metrics === [] ) {
            $metrics = [ 'gross', 'fee', 'net', 'count' ];
        }

        $allowed_metrics = [ 'gross', 'fee', 'net', 'count', 'avg', 'fee_pct' ];
        $metrics = array_values( array_intersect( $metrics, $allowed_metrics ) );
        if ( $metrics === [] ) {
            $metrics = [ 'gross', 'fee', 'net', 'count' ];
        }

        $join_sql = '';
        $group_sql = '';
        $group_by = '';
        $group_label = $group;

        if ( $group === 'campaign' ) {
            $join_sql = "LEFT JOIN {$campaigns_table} c ON t.campaign_code = c.cid";
            $group_sql = "COALESCE(c.cname, 'Unassigned')";
            $group_by = "COALESCE(c.cname, 'Unassigned')";
            $group_label = 'campaign';
        } elseif ( $group === 'pay_method' ) {
            $group_sql = "COALESCE(NULLIF(t.pay_method,''), 'Unknown')";
            $group_by = "COALESCE(NULLIF(t.pay_method,''), 'Unknown')";
            $group_label = 'pay_method';
        } else {
            [ $date_group_sql, $group_label ] = \metis_reports_group_sql( $group );
            $group_sql = $date_group_sql;
            $group_by = $date_group_sql;
        }

        $where = 'WHERE 1=1 AND t.amount IS NOT NULL';
        $params = [];

        if ( ! $lifetime ) {
            if ( $start !== '' && \metis_reports_is_date( $start ) ) {
                $where .= ' AND t.tran_date >= %s';
                $params[] = $start . ' 00:00:00';
            }
            if ( $end !== '' && \metis_reports_is_date( $end ) ) {
                $where .= ' AND t.tran_date <= %s';
                $params[] = $end . ' 23:59:59';
            }
        }

        if ( ! empty( $filters['platform'] ) && $filters['platform'] !== 'ALL' ) {
            $platform = strtoupper( \metis_key_clean( $filters['platform'] ) );
            if ( in_array( strtolower( $platform ), [ 'st', 'gb', 'ol' ], true ) ) {
                $where .= ' AND t.platform = %s';
                $params[] = $platform;
            }
            if ( $platform === 'GB' ) {
                $where .= ' AND t.tran_date <= %s';
                $params[] = '2025-04-30 23:59:59';
            }
        }

        if ( ! empty( $filters['status'] ) && $filters['status'] !== 'ALL' ) {
            $status = strtolower( \metis_key_clean( $filters['status'] ) );
            $allowed_status = class_exists( '\Core_Settings_Service' )
                ? \Core_Settings_Service::get( 'payment_statuses', [] )
                : [];
            if ( is_array( $allowed_status ) && in_array( $status, $allowed_status, true ) ) {
                $where .= ' AND LOWER(t.status) = %s';
                $params[] = $status;
            }
        }

        if ( ! empty( $filters['pay_method'] ) && $filters['pay_method'] !== 'ALL' ) {
            $pay_method = \metis_key_clean( $filters['pay_method'] );
            if ( in_array( $pay_method, [ 'cc', 'ach', 'cash', 'ck', 'other' ], true ) ) {
                $where .= ' AND t.pay_method = %s';
                $params[] = $pay_method;
            }
        }

        $series = $db->fetchAll(
            "
            SELECT
                {$group_sql} AS period,
                SUM(COALESCE(t.amount,0))                        AS gross,
                SUM(COALESCE(t.fee,0))                           AS fee,
                SUM(COALESCE(t.amount,0) - COALESCE(t.fee,0))   AS net,
                COUNT(t.id)                                      AS count
            FROM {$transactions_table} t
            {$join_sql}
            {$where}
            GROUP BY {$group_by}
            ORDER BY period ASC
            ",
            $params
        );

        $gross = 0.0;
        $fee = 0.0;
        $net = 0.0;
        $count = 0;

        foreach ( $series as &$row ) {
            $row['gross'] = (float) $row['gross'];
            $row['fee'] = (float) $row['fee'];
            $row['net'] = (float) $row['net'];
            $row['count'] = (int) $row['count'];

            $gross += $row['gross'];
            $fee += $row['fee'];
            $net += $row['net'];
            $count += $row['count'];

            $row['avg'] = $row['count'] > 0 ? ( $row['gross'] / $row['count'] ) : 0;
            $row['fee_pct'] = $row['gross'] > 0 ? ( ( $row['fee'] / $row['gross'] ) * 100 ) : 0;
        }
        unset( $row );

        $avg = $count > 0 ? ( $gross / $count ) : 0;
        $fee_pct = $gross > 0 ? ( ( $fee / $gross ) * 100 ) : 0;
        $top_donors = self::buildTopDonors( $transactions_table, $where, $params, 10 );
        $comparison = self::buildComparison( $db, $input, $transactions_table, $join_sql, $where, $params, $lifetime, $start, $end, $group );

        return [
            'meta' => [
                'start' => $start,
                'end' => $end,
                'group' => $group_label,
                'metrics' => $metrics,
                'filters' => $filters,
                'lifetime' => $lifetime,
                'compare' => $input['compare'] ?? 'none',
            ],
            'kpis' => [
                'gross' => $gross,
                'fee' => $fee,
                'net' => $net,
                'count' => $count,
                'avg' => $avg,
                'fee_pct' => $fee_pct,
            ],
            'comparison' => $comparison,
            'series' => $series,
            'top_donors' => $top_donors,
        ];
    }

    public static function listSavedReports(): array {
        $table = \Metis_Tables::get( 'reports' );
        if ( ! self::tableExists( $table ) ) {
            return [];
        }

        $rows = \metis_db()->fetchAll(
            "SELECT id, name, config_json, created_by, created_at, updated_at FROM {$table} ORDER BY updated_at DESC, id DESC"
        );

        return $rows ?: [];
    }

    public static function saveSavedReport( int $id, string $name, array $config ): int {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'reports' );
        if ( ! self::tableExists( $table ) ) {
            return 0;
        }

        $now = \metis_current_time( 'mysql' );
        $data = [
            'name' => $name,
            'config_json' => \metis_json_encode( $config ),
            'updated_at' => $now,
        ];

        if ( $id > 0 ) {
            $ok = $db->update( $table, $data, [ 'id' => $id ], [ '%s', '%s', '%s' ], [ '%d' ] );
            return $ok === false ? 0 : $id;
        }

        $data['created_by'] = \metis_current_user_id();
        $data['created_at'] = $now;
        $ok = $db->insert( $table, $data, [ '%s', '%s', '%s', '%d', '%s' ] );

        return $ok ? (int) $db->lastInsertId() : 0;
    }

    public static function deleteSavedReport( int $id ): bool {
        if ( $id <= 0 ) {
            return false;
        }

        $table = \Metis_Tables::get( 'reports' );
        if ( ! self::tableExists( $table ) ) {
            return false;
        }

        $ok = \metis_db()->delete( $table, [ 'id' => $id ], [ '%d' ] );
        return $ok !== false;
    }

    private static function buildTopDonors( string $transactions_table, string $where, array $params, int $limit = 10 ): array {
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $rows = \metis_db()->fetchAll(
            "
            SELECT
                t.did                                                           AS did,
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))),
                    ''),
                    t.did
                )                                                               AS display_name,
                SUM(COALESCE(t.amount,0))                                       AS gross,
                SUM(COALESCE(t.amount,0) - COALESCE(t.fee,0))                  AS net,
                COUNT(t.id)                                                     AS gift_count,
                AVG(COALESCE(t.amount,0))                                       AS avg_gift,
                MIN(DATE(t.tran_date))                                          AS first_gift,
                MAX(DATE(t.tran_date))                                          AS last_gift
            FROM {$transactions_table} t
            LEFT JOIN {$contacts_table} c ON c.did = t.did
            {$where}
            GROUP BY t.did
            ORDER BY gross DESC
            LIMIT %d
            ",
            array_merge( $params, [ $limit ] )
        );

        foreach ( $rows as &$row ) {
            $row['gross'] = (float) $row['gross'];
            $row['net'] = (float) $row['net'];
            $row['gift_count'] = (int) $row['gift_count'];
            $row['avg_gift'] = (float) $row['avg_gift'];
        }
        unset( $row );

        return $rows ?: [];
    }

    private static function buildComparison( object $db, array $input, string $transactions_table, string $join_sql, string $where, array $params, bool $lifetime, string $start, string $end, string $group ): array {
        if (
            empty( $input['compare'] ) ||
            ! in_array( $input['compare'], [ 'mom', 'yoy', 'qoq' ], true ) ||
            in_array( $group, [ 'campaign', 'pay_method' ], true )
        ) {
            return [];
        }

        $periods = [ 'mom' => 1, 'qoq' => 3, 'yoy' => 12 ];
        $months_back = $periods[ $input['compare'] ] ?? 0;

        if ( ! $lifetime && \metis_reports_is_date( $start ) && \metis_reports_is_date( $end ) ) {
            $current_start = $start . ' 00:00:00';
            $current_end = $end . ' 23:59:59';
            $range_seconds = strtotime( $current_end ) - strtotime( $current_start );
            $previous_end_ts = strtotime( $current_start ) - 1;
            $previous_start_ts = $previous_end_ts - $range_seconds;
            $previous_start = date( 'Y-m-d H:i:s', $previous_start_ts );
            $previous_end = date( 'Y-m-d H:i:s', $previous_end_ts );
        } else {
            $latest_date = $db->scalar( "SELECT MAX(tran_date) FROM {$transactions_table} WHERE amount IS NOT NULL" );
            if ( ! $latest_date ) {
                return [];
            }
            $current_end = date( 'Y-m-d 23:59:59', strtotime( (string) $latest_date ) );
            $current_start = date( 'Y-m-d 00:00:00', strtotime( "-{$months_back} months +1 day", strtotime( $current_end ) ) );
            $previous_end = date( 'Y-m-d 23:59:59', strtotime( '-1 day', strtotime( $current_start ) ) );
            $previous_start = date( 'Y-m-d 00:00:00', strtotime( "-{$months_back} months +1 day", strtotime( $previous_end ) ) );
        }

        $base_where = $where;
        $base_params = $params;
        if ( ! $lifetime ) {
            if ( \metis_reports_is_date( $start ) ) {
                $base_where = preg_replace( '/ AND t\\.tran_date >= %s/', '', $base_where, 1 ) ?? $base_where;
                array_shift( $base_params );
            }
            if ( \metis_reports_is_date( $end ) ) {
                $base_where = preg_replace( '/ AND t\\.tran_date <= %s/', '', $base_where, 1 ) ?? $base_where;
                array_shift( $base_params );
            }
        }

        $build_range = static function ( string $range_start, string $range_end ) use ( $db, $transactions_table, $join_sql, $base_where, $base_params ): ?array {
            return $db->fetchOne(
                "
                SELECT
                    SUM(COALESCE(t.amount,0))                       AS gross,
                    SUM(COALESCE(t.fee,0))                          AS fee,
                    SUM(COALESCE(t.amount,0) - COALESCE(t.fee,0))  AS net,
                    COUNT(t.id)                                     AS count
                FROM {$transactions_table} t
                {$join_sql}
                {$base_where} AND t.tran_date BETWEEN %s AND %s
                ",
                array_merge( $base_params, [ $range_start, $range_end ] )
            );
        };

        $current_row = $build_range( $current_start, $current_end );
        $previous_row = $build_range( $previous_start, $previous_end );
        if ( ! $current_row || ! $previous_row ) {
            return [];
        }

        foreach ( [ 'gross', 'fee', 'net', 'count' ] as $field ) {
            $current_row[ $field ] = (float) ( $current_row[ $field ] ?? 0 );
            $previous_row[ $field ] = (float) ( $previous_row[ $field ] ?? 0 );
        }

        return [
            'current' => $current_row,
            'previous' => $previous_row,
            'delta' => [
                'gross' => $current_row['gross'] - $previous_row['gross'],
                'net' => $current_row['net'] - $previous_row['net'],
                'count' => $current_row['count'] - $previous_row['count'],
            ],
            'delta_pct' => [
                'gross' => $previous_row['gross'] > 0 ? ( ( $current_row['gross'] - $previous_row['gross'] ) / $previous_row['gross'] ) * 100 : 0,
                'net' => $previous_row['net'] > 0 ? ( ( $current_row['net'] - $previous_row['net'] ) / $previous_row['net'] ) * 100 : 0,
                'count' => $previous_row['count'] > 0 ? ( ( $current_row['count'] - $previous_row['count'] ) / $previous_row['count'] ) * 100 : 0,
            ],
        ];
    }
}
