<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

/**
 * Donations Reports AJAX Handlers
 *
 * Endpoints:
 *   metis_ajax_metis_donations_report
 *   metis_ajax_metis_donations_report_pdf
 *   metis_ajax_metis_donations_report_save
 *   metis_ajax_metis_donations_report_list
 *   metis_ajax_metis_donations_report_delete
 *
 * Note: Donor Intelligence is handled in donor_intelligence.ajax.php
 */

Metis_Logger::info( 'Donations Reports AJAX loaded' );

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_donations_report', [
        'module' => 'donations',
        'permission' => 'view',
        'nonce_action' => metis_ajax_nonce_action( 'metis_donations_report' ),
    ] );
    metis_ajax_register_controller( 'metis_donations_report_pdf', [
        'module' => 'donations',
        'permission' => 'export',
        'nonce_action' => metis_ajax_nonce_action( 'metis_donations_report_pdf' ),
    ] );
    metis_ajax_register_controller( 'metis_donations_report_save', [
        'module' => 'donations',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_donations_report_save' ),
    ] );
    metis_ajax_register_controller( 'metis_donations_report_list', [
        'module' => 'donations',
        'permission' => 'view',
        'nonce_action' => metis_ajax_nonce_action( 'metis_donations_report_list' ),
    ] );
    metis_ajax_register_controller( 'metis_donations_report_delete', [
        'module' => 'donations',
        'permission' => 'delete',
        'nonce_action' => metis_ajax_nonce_action( 'metis_donations_report_delete' ),
    ] );
}

function metis_donations_reports_ajax_verify( string $action, string $permission = 'view' ): void {
    $nonce = '';
    foreach ( [ 'metis_action_nonce', 'nonce' ] as $field ) {
        $value = metis_request_post()[ $field ] ?? '';
        if ( is_scalar( $value ) ) {
            $nonce = trim( (string) metis_runtime_unslash( $value ) );
            if ( $nonce !== '' ) {
                break;
            }
        }
    }

    $nonce_action = function_exists( 'metis_ajax_nonce_action' )
        ? metis_ajax_nonce_action( $action )
        : $action;

    if ( $nonce === '' || ! function_exists( 'metis_runtime_verify_nonce' ) || ! metis_runtime_verify_nonce( $nonce, $nonce_action ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }

    $allowed = match ( $permission ) {
        'delete' => function_exists( 'metis_donations_can_delete' ) && metis_donations_can_delete(),
        'export' => function_exists( 'metis_donations_can_export' ) && metis_donations_can_export(),
        'edit' => function_exists( 'metis_donations_can_manage' ) && metis_donations_can_manage(),
        default => function_exists( 'metis_donations_can' ) && metis_donations_can( 'view' ),
    };

    if ( ! $allowed ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
}

metis_ajax_register_handler( 'metis_donations_report',        'metis_ajax_donations_report' );
metis_ajax_register_handler( 'metis_donations_report_pdf',    'metis_ajax_donations_report_pdf' );
metis_ajax_register_handler( 'metis_donations_report_save',   'metis_ajax_report_save' );
metis_ajax_register_handler( 'metis_donations_report_list',   'metis_ajax_report_list' );
metis_ajax_register_handler( 'metis_donations_report_delete', 'metis_ajax_report_delete' );

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function metis_reports_cache_key( array $payload ): string {
    $norm = $payload;
    if ( isset( $norm['metrics'] ) && is_array( $norm['metrics'] ) ) sort( $norm['metrics'] );
    if ( isset( $norm['filters'] ) && is_array( $norm['filters'] ) ) ksort( $norm['filters'] );
    ksort( $norm );
    return 'metis_report_' . md5( metis_json_encode( $norm ) );
}

function metis_reports_clear_cache(): void {
    \Metis\Core\Cache\CacheService::clearByPrefix( 'metis_report_' );
}

function metis_reports_is_date( string $s ): bool {
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ) return false;
    return strtotime( $s . ' 00:00:00' ) !== false;
}

function metis_reports_group_sql( string $group ): array {
    switch ( $group ) {
        case 'day':   return [ "DATE(tran_date)",                    'day'   ];
        case 'week':  return [ "DATE_FORMAT(tran_date, '%x-W%v')",  'week'  ];
        case 'year':  return [ "YEAR(tran_date)",                    'year'  ];
        case 'month':
        default:      return [ "DATE_FORMAT(tran_date, '%Y-%m')",   'month' ];
    }
}

function metis_reports_table_exists( string $table ): bool {
    $db = metis_db();
    return ! empty( $db->scalar( "SHOW TABLES LIKE %s", [ $db->escapeLike( $table ) ] ) );
}

// -------------------------------------------------------------------------
// Core report builder
// -------------------------------------------------------------------------

function metis_build_donations_report_data( array $input ): array {

    $db = metis_db();

    $transactions_table = Metis_Tables::get( 'transactions' );
    $campaigns_table    = Metis_Tables::get( 'campaigns' );

    $start    = metis_text_clean( $input['start']  ?? '' );
    $end      = metis_text_clean( $input['end']    ?? '' );
    $group    = metis_key_clean(        $input['group']  ?? 'month' );
    $metrics  = $input['metrics'] ?? [];
    $filters  = $input['filters'] ?? [];
    $lifetime = ! empty( $input['lifetime'] );

    if ( ! is_array( $metrics ) ) $metrics = [];
    $metrics = array_values( array_unique( array_map( 'metis_key_clean', $metrics ) ) );
    if ( empty( $metrics ) ) $metrics = [ 'gross', 'fee', 'net', 'count' ];

    $allowed_metrics = [ 'gross', 'fee', 'net', 'count', 'avg', 'fee_pct' ];
    $metrics = array_values( array_intersect( $metrics, $allowed_metrics ) );
    if ( empty( $metrics ) ) $metrics = [ 'gross', 'fee', 'net', 'count' ];

    // --- Group / join setup ---
    $join_sql    = '';
    $group_sql   = '';
    $group_by    = '';
    $group_label = $group;

    if ( $group === 'campaign' ) {
        $join_sql    = "LEFT JOIN {$campaigns_table} c ON t.campaign_code = c.cid";
        $group_sql   = "COALESCE(c.cname, 'Unassigned')";
        $group_by    = "COALESCE(c.cname, 'Unassigned')";
        $group_label = 'campaign';
    } elseif ( $group === 'pay_method' ) {
        $group_sql   = "COALESCE(NULLIF(t.pay_method,''), 'Unknown')";
        $group_by    = "COALESCE(NULLIF(t.pay_method,''), 'Unknown')";
        $group_label = 'pay_method';
    } else {
        [ $date_group_sql, $group_label ] = metis_reports_group_sql( $group );
        $group_sql = $date_group_sql;
        $group_by  = $date_group_sql;
    }

    // --- WHERE clause ---
    $where  = "WHERE 1=1 AND t.amount IS NOT NULL";
    $params = [];

    if ( ! $lifetime ) {
        if ( $start !== '' && metis_reports_is_date( $start ) ) {
            $where   .= " AND t.tran_date >= %s";
            $params[] = $start . ' 00:00:00';
        }
        if ( $end !== '' && metis_reports_is_date( $end ) ) {
            $where   .= " AND t.tran_date <= %s";
            $params[] = $end . ' 23:59:59';
        }
    }

    if ( ! empty( $filters['platform'] ) && $filters['platform'] !== 'ALL' ) {
        $pf = strtoupper( metis_key_clean( $filters['platform'] ) );
        if ( in_array( strtolower( $pf ), [ 'st', 'gb', 'ol' ], true ) ) {
            $where   .= " AND t.platform = %s";
            $params[] = $pf;
        }
        if ( $pf === 'GB' ) {
            $where   .= " AND t.tran_date <= %s";
            $params[] = '2025-04-30 23:59:59';
        }
    }

    if ( ! empty( $filters['status'] ) && $filters['status'] !== 'ALL' ) {
        $status         = strtolower( metis_key_clean( $filters['status'] ) );
        $allowed_status = class_exists( 'Core_Settings_Service' )
            ? Core_Settings_Service::get( 'payment_statuses', [] )
            : [];
        if ( is_array( $allowed_status ) && in_array( $status, $allowed_status, true ) ) {
            $where   .= " AND LOWER(t.status) = %s";
            $params[] = $status;
        }
    }

    if ( ! empty( $filters['pay_method'] ) && $filters['pay_method'] !== 'ALL' ) {
        $pm = metis_key_clean( $filters['pay_method'] );
        if ( in_array( $pm, [ 'cc', 'ach', 'cash', 'ck', 'other' ], true ) ) {
            $where   .= " AND t.pay_method = %s";
            $params[] = $pm;
        }
    }

    // --- Main series query ---
    $sql = "
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
    ";

    $series = $db->fetchAll( $sql, $params );

    $gross = 0; $fee = 0; $net = 0; $count = 0;

    foreach ( $series as &$row ) {
        $row['gross'] = (float) $row['gross'];
        $row['fee']   = (float) $row['fee'];
        $row['net']   = (float) $row['net'];
        $row['count'] = (int)   $row['count'];

        $gross += $row['gross'];
        $fee   += $row['fee'];
        $net   += $row['net'];
        $count += $row['count'];

        $row['avg']     = $row['count'] > 0 ? ( $row['gross'] / $row['count'] ) : 0;
        $row['fee_pct'] = $row['gross'] > 0 ? ( ( $row['fee'] / $row['gross'] ) * 100 ) : 0;
    }
    unset( $row );

    $avg     = $count > 0 ? ( $gross / $count ) : 0;
    $fee_pct = $gross > 0 ? ( ( $fee / $gross ) * 100 ) : 0;

    // --- Top N donors (always included — JS decides whether to show) ---
    $top_donors = metis_build_top_donors( $transactions_table, $where, $params, 10 );

    // --- Comparison ---
    $comparison = [];

    if (
        ! empty( $input['compare'] ) &&
        in_array( $input['compare'], [ 'mom', 'yoy', 'qoq' ], true ) &&
        ! in_array( $group, [ 'campaign', 'pay_method' ], true )
    ) {
        $periods     = [ 'mom' => 1, 'qoq' => 3, 'yoy' => 12 ];
        $months_back = $periods[ $input['compare'] ] ?? 0;

        if ( ! $lifetime && metis_reports_is_date( $start ) && metis_reports_is_date( $end ) ) {
            $current_start     = $start . ' 00:00:00';
            $current_end       = $end   . ' 23:59:59';
            $range_seconds     = strtotime( $current_end ) - strtotime( $current_start );
            $previous_end_ts   = strtotime( $current_start ) - 1;
            $previous_start_ts = $previous_end_ts - $range_seconds;
            $previous_start    = date( 'Y-m-d H:i:s', $previous_start_ts );
            $previous_end      = date( 'Y-m-d H:i:s', $previous_end_ts );
        } else {
            $latest_date = $db->scalar( "SELECT MAX(tran_date) FROM {$transactions_table} WHERE amount IS NOT NULL" );
            if ( ! $latest_date ) return [];
            $current_end    = date( 'Y-m-d 23:59:59', strtotime( $latest_date ) );
            $current_start  = date( 'Y-m-d 00:00:00', strtotime( "-{$months_back} months +1 day", strtotime( $current_end ) ) );
            $previous_end   = date( 'Y-m-d 23:59:59', strtotime( '-1 day', strtotime( $current_start ) ) );
            $previous_start = date( 'Y-m-d 00:00:00', strtotime( "-{$months_back} months +1 day", strtotime( $previous_end ) ) );
        }

        $base_where  = $where;
        $base_params = $params;

        if ( ! $lifetime ) {
            if ( metis_reports_is_date( $start ) ) {
                $base_where = preg_replace( '/ AND t\.tran_date >= %s/', '', $base_where, 1 );
                array_shift( $base_params );
            }
            if ( metis_reports_is_date( $end ) ) {
                $base_where = preg_replace( '/ AND t\.tran_date <= %s/', '', $base_where, 1 );
                array_shift( $base_params );
            }
        }

        $build_range = function ( string $rs, string $re ) use ( $transactions_table, $join_sql, $base_where, $base_params, $db ): ?array {
            $sql = "
                SELECT
                    SUM(COALESCE(t.amount,0))                       AS gross,
                    SUM(COALESCE(t.fee,0))                          AS fee,
                    SUM(COALESCE(t.amount,0) - COALESCE(t.fee,0))  AS net,
                    COUNT(t.id)                                     AS count
                FROM {$transactions_table} t
                {$join_sql}
                {$base_where} AND t.tran_date BETWEEN %s AND %s
            ";
            return $db->fetchOne( $sql, array_merge( $base_params, [ $rs, $re ] ) );
        };

        $current_row  = $build_range( $current_start, $current_end );
        $previous_row = $build_range( $previous_start, $previous_end );

        if ( $current_row && $previous_row ) {
            foreach ( [ 'gross', 'fee', 'net', 'count' ] as $f ) {
                $current_row[ $f ]  = (float) ( $current_row[ $f ]  ?? 0 );
                $previous_row[ $f ] = (float) ( $previous_row[ $f ] ?? 0 );
            }
            $comparison = [
                'current'   => $current_row,
                'previous'  => $previous_row,
                'delta'     => [
                    'gross' => $current_row['gross'] - $previous_row['gross'],
                    'net'   => $current_row['net']   - $previous_row['net'],
                    'count' => $current_row['count'] - $previous_row['count'],
                ],
                'delta_pct' => [
                    'gross' => $previous_row['gross'] > 0 ? ( ( $current_row['gross'] - $previous_row['gross'] ) / $previous_row['gross'] ) * 100 : 0,
                    'net'   => $previous_row['net']   > 0 ? ( ( $current_row['net']   - $previous_row['net']   ) / $previous_row['net']   ) * 100 : 0,
                    'count' => $previous_row['count'] > 0 ? ( ( $current_row['count'] - $previous_row['count'] ) / $previous_row['count'] ) * 100 : 0,
                ],
            ];
        }
    }

    return [
        'meta'       => [
            'start'    => $start,
            'end'      => $end,
            'group'    => $group_label,
            'metrics'  => $metrics,
            'filters'  => $filters,
            'lifetime' => $lifetime,
            'compare'  => $input['compare'] ?? 'none',
        ],
        'kpis'       => [
            'gross'   => $gross,
            'fee'     => $fee,
            'net'     => $net,
            'count'   => $count,
            'avg'     => $avg,
            'fee_pct' => $fee_pct,
        ],
        'comparison' => $comparison,
        'series'     => $series,
        'top_donors' => $top_donors,
    ];
}

// -------------------------------------------------------------------------
// Top N donors helper
// -------------------------------------------------------------------------

function metis_build_top_donors( string $transactions_table, string $where, array $params, int $limit = 10 ): array {

    $db = metis_db();

    // Join contacts table — contacts with a did are donors
    $contacts_table = Metis_Tables::get( 'contacts' );

    $sql = "
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
    ";

    $final_params = array_merge( $params, [ $limit ] );

    $rows = $db->fetchAll( $sql, $final_params );

    foreach ( $rows as &$r ) {
        $r['gross']      = (float) $r['gross'];
        $r['net']        = (float) $r['net'];
        $r['gift_count'] = (int)   $r['gift_count'];
        $r['avg_gift']   = (float) $r['avg_gift'];
        // first_gift / last_gift remain as date strings (Y-m-d)
    }
    unset( $r );

    return $rows ?: [];
}

// -------------------------------------------------------------------------
// Report endpoint
// -------------------------------------------------------------------------

function metis_ajax_donations_report(): void {
    metis_donations_reports_ajax_verify( 'metis_donations_report', 'view' );

    $filters_raw = metis_request_post()['filters'] ?? null;
    $filters     = [];

    if ( is_string( $filters_raw ) && $filters_raw !== '' ) {
        $decoded = json_decode( stripslashes( $filters_raw ), true );
        if ( is_array( $decoded ) ) $filters = $decoded;
    } elseif ( is_array( $filters_raw ) ) {
        $filters = $filters_raw;
    }

    $data = metis_build_donations_report_data( [
        'start'    => metis_text_clean( metis_request_post()['start']  ?? '' ),
        'end'      => metis_text_clean( metis_request_post()['end']    ?? '' ),
        'group'    => metis_key_clean(        metis_request_post()['group']  ?? 'month' ),
        'metrics'  => metis_request_post()['metrics'] ?? [],
        'filters'  => $filters,
        'lifetime' => ! empty( metis_request_post()['lifetime'] ),
        'compare'  => metis_key_clean( metis_request_post()['compare'] ?? 'none' ),
    ] );

    metis_runtime_send_json_success( $data );
}

// -------------------------------------------------------------------------
// PDF export
// -------------------------------------------------------------------------

function metis_ajax_donations_report_pdf(): void {
    metis_donations_reports_ajax_verify( 'metis_donations_report_pdf', 'export' );

    $money = function( $v ) { return '$' . number_format( (float) $v, 2 ); };
    $ints  = function( $v ) { return number_format( (int) $v ); };

    $org_name    = Core_Settings_Service::get( 'org_name',    'Mobilize Waco' );
    $portal_name = Core_Settings_Service::get( 'portal_name', 'Metis Portal'  );

    $current_user = metis_runtime_current_user();
    $generated_by = $current_user->display_name ?: $current_user->user_login ?: 'Unknown';
    $generated    = date( 'F j, Y' );

    $footer_left = "{$org_name} \xE2\x80\x94 {$portal_name} \xE2\x80\x94 Confidential";

    $filters_raw = metis_request_post()['filters'] ?? null;
    $filters     = [];
    if ( is_string( $filters_raw ) && $filters_raw !== '' ) {
        $decoded = json_decode( stripslashes( $filters_raw ), true );
        if ( is_array( $decoded ) ) $filters = $decoded;
    } elseif ( is_array( $filters_raw ) ) {
        $filters = $filters_raw;
    }

    $metrics_raw = metis_request_post()['metrics'] ?? '';
    $metrics     = [];
    if ( is_string( $metrics_raw ) && $metrics_raw !== '' ) {
        $decoded = json_decode( stripslashes( $metrics_raw ), true );
        if ( is_array( $decoded ) ) $metrics = $decoded;
    } elseif ( is_array( $metrics_raw ) ) {
        $metrics = $metrics_raw;
    }

    $group    = metis_key_clean( metis_request_post()['group'] ?? 'month' );
    $lifetime = ! empty( metis_request_post()['lifetime'] );
    $start    = metis_text_clean( metis_request_post()['start'] ?? '' );
    $end      = metis_text_clean( metis_request_post()['end']   ?? '' );

    $report_data = [];
    $raw_rd = metis_request_post()['report_data'] ?? '';
    if ( is_string( $raw_rd ) && $raw_rd !== '' ) {
        $decoded = json_decode( stripslashes( $raw_rd ), true );
        if ( is_array( $decoded ) ) $report_data = $decoded;
    }

    $period_label = ( $lifetime || ( $start === '' && $end === '' ) )
        ? 'All Time'
        : metis_escape_html( $start ) . ' to ' . metis_escape_html( $end );

    $meta_base = "Period: {$period_label} &nbsp;&bull;&nbsp; Generated: {$generated} &nbsp;&bull;&nbsp; Generated By: " . metis_escape_html( $generated_by );

    $css = "
        body       { font-family: DejaVu Sans, sans-serif; color: #111827; margin: 0; padding: 0; font-size: 13px; }
        h1         { font-size: 22px; font-weight: 700; color: #1e3a5f; margin: 0 0 4px; }
        .meta      { font-size: 12px; color: #6b7280; margin-bottom: 24px; }
        .section   { font-size: 13px; font-weight: 700; color: #374151; margin: 24px 0 8px;
                     border-bottom: 2px solid #e5e7eb; padding-bottom: 4px; }
        .kpi-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    ";

    // =========================================================
    // MODE: Donor Intelligence
    // =========================================================
    if ( $group === 'donor' ) {

        $di   = $report_data;
        $k    = $di['kpis']     ?? [];
        $segs = $di['segments'] ?? [];
        $rows = $di['rows']     ?? [];

        $kpi_cells = '';
        foreach ( [
            [ 'Total Gross',        $money( $k['gross']    ?? 0 ) ],
            [ 'Total Net',          $money( $k['net']      ?? 0 ) ],
            [ 'Donors',             $ints(  $k['donors']   ?? 0 ) ],
            [ 'Total Gifts',        $ints(  $k['gifts']    ?? 0 ) ],
            [ 'Avg Gift',           $money( $k['avg_gift'] ?? 0 ) ],
            [ 'Avg Lifetime Value', $money( $k['avg_ltv']  ?? 0 ) ],
            [ 'Avg Frequency',      number_format( (float)( $k['frequency'] ?? 0 ), 2 ) . '&times;' ],
        ] as [ $lbl, $val ] ) {
            $kpi_cells .= "<td style='padding:10px 12px;text-align:center;border:1px solid #e5e7eb;'>
                <div style='font-size:15px;font-weight:700;color:#1e3a5f;'>{$val}</div>
                <div style='font-size:10px;color:#6b7280;margin-top:2px;'>{$lbl}</div>
            </td>";
        }

        $seg_colors = [ 'recurring' => '#16a34a', 'returning' => '#2563eb', 'one-time' => '#6b7280', 'lapsed' => '#dc2626' ];
        $seg_row = '';
        foreach ( [ 'recurring' => 'Recurring', 'returning' => 'Returning', 'one-time' => 'One-Time', 'lapsed' => 'Lapsed' ] as $key => $label ) {
            $cnt   = (int) ( $segs[ $key ] ?? 0 );
            $color = $seg_colors[ $key ];
            $seg_row .= "<td style='padding:8px 14px;text-align:center;border:1px solid #e5e7eb;'>
                <div style='font-size:18px;font-weight:700;color:{$color};'>{$cnt}</div>
                <div style='font-size:11px;color:#6b7280;'>{$label}</div>
            </td>";
        }

        $body = ''; $zebra = false;
        foreach ( $rows as $row ) {
            $bg        = $zebra ? '#f9fafb' : '#ffffff'; $zebra = ! $zebra;
            $name      = metis_escape_html( $row['display_name'] ?? $row['did'] ?? '' );
            $did       = metis_escape_html( $row['did'] ?? '' );
            $gross     = $money( $row['gross']          ?? 0 );
            $net       = $money( $row['net']            ?? 0 );
            $gifts     = $ints(  $row['donation_count'] ?? 0 );
            $avg       = $money( $row['avg_gift']       ?? 0 );
            $first     = metis_escape_html( substr( $row['first_gift'] ?? '', 0, 10 ) ?: '—' );
            $last      = metis_escape_html( substr( $row['last_gift']  ?? '', 0, 10 ) ?: '—' );
            $seg_label = ucfirst( $row['segment'] ?? '' );
            $seg_color = $seg_colors[ $row['segment'] ?? '' ] ?? '#6b7280';
            $body .= "<tr style='background:{$bg};'>
                <td style='padding:6px 10px;border:1px solid #e5e7eb;font-size:11px;'>{$name}<br><span style='color:#9ca3af;font-size:10px;'>{$did}</span></td>
                <td style='padding:6px 10px;border:1px solid #e5e7eb;text-align:right;font-size:11px;'>{$gross}</td>
                <td style='padding:6px 10px;border:1px solid #e5e7eb;text-align:right;font-size:11px;'>{$net}</td>
                <td style='padding:6px 10px;border:1px solid #e5e7eb;text-align:center;font-size:11px;'>{$gifts}</td>
                <td style='padding:6px 10px;border:1px solid #e5e7eb;text-align:right;font-size:11px;'>{$avg}</td>
                <td style='padding:6px 10px;border:1px solid #e5e7eb;text-align:center;font-size:11px;'>{$first}</td>
                <td style='padding:6px 10px;border:1px solid #e5e7eb;text-align:center;font-size:11px;'>{$last}</td>
                <td style='padding:6px 10px;border:1px solid #e5e7eb;text-align:center;font-size:11px;color:{$seg_color};font-weight:600;'>{$seg_label}</td>
            </tr>";
        }

        $th_s = "padding:7px 10px;background:#f3f4f6;border:1px solid #d1d5db;font-size:11px;";
        $donor_table = empty( $body ) ? '<p style="color:#6b7280;">No donor data found.</p>'
            : "<table width='100%' style='border-collapse:collapse;margin-top:12px;'>
                <thead><tr>
                    <th style='{$th_s}text-align:left;'>Name / DID</th>
                    <th style='{$th_s}text-align:right;'>Gross</th>
                    <th style='{$th_s}text-align:right;'>Net</th>
                    <th style='{$th_s}text-align:center;'>Gifts</th>
                    <th style='{$th_s}text-align:right;'>Avg Gift</th>
                    <th style='{$th_s}text-align:center;'>First Gift</th>
                    <th style='{$th_s}text-align:center;'>Last Gift</th>
                    <th style='{$th_s}text-align:center;'>Segment</th>
                </tr></thead>
                <tbody>{$body}</tbody>
            </table>";

        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>{$css}</style></head>
<body>
    <h1>Donor Intelligence Report</h1>
    <div class="meta">{$meta_base}</div>
    <div class="section">Summary</div>
    <table class="kpi-table"><tr>{$kpi_cells}</tr></table>
    <div class="section">Segments</div>
    <table width="100%" style="border-collapse:collapse;margin-bottom:16px;"><tr>{$seg_row}</tr></table>
    <div class="section">Donors</div>
    {$donor_table}
</body></html>
HTML;

    // =========================================================
    // MODE: Campaign Analytics
    // =========================================================
    } elseif ( $group === 'campaign' ) {

        $kpis   = $report_data['kpis']   ?? [];
        $series = $report_data['series'] ?? [];

        $kpi_cells = '';
        foreach ( [
            [ 'Total Gross', $money( $kpis['gross'] ?? 0 ) ],
            [ 'Total Fees',  $money( $kpis['fee']   ?? 0 ) ],
            [ 'Total Net',   $money( $kpis['net']   ?? 0 ) ],
            [ 'Donations',   $ints(  $kpis['count'] ?? 0 ) ],
        ] as [ $lbl, $val ] ) {
            $kpi_cells .= "<td style='padding:12px 16px;text-align:center;border:1px solid #e5e7eb;'>
                <div style='font-size:17px;font-weight:700;color:#1e3a5f;'>{$val}</div>
                <div style='font-size:11px;color:#6b7280;margin-top:3px;'>{$lbl}</div>
            </td>";
        }

        $body = ''; $zebra = false;
        $sorted = $series;
        usort( $sorted, fn( $a, $b ) => (float)( $b['gross'] ?? 0 ) <=> (float)( $a['gross'] ?? 0 ) );
        foreach ( $sorted as $row ) {
            $bg    = $zebra ? '#f9fafb' : '#ffffff'; $zebra = ! $zebra;
            $avg   = (int)( $row['count'] ?? 0 ) > 0
                ? $money( (float)( $row['gross'] ?? 0 ) / (int)( $row['count'] ?? 1 ) ) : '$0.00';
            $body .= "<tr style='background:{$bg};'>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;font-size:12px;'>" . metis_escape_html( $row['period'] ?? '' ) . "</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-size:12px;'>" . $money( $row['gross'] ?? 0 ) . "</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-size:12px;'>" . $money( $row['fee']   ?? 0 ) . "</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-size:12px;'>" . $money( $row['net']   ?? 0 ) . "</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:center;font-size:12px;'>" . $ints( $row['count'] ?? 0 ) . "</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-size:12px;'>{$avg}</td>
            </tr>";
        }

        $th_s = "padding:8px 12px;background:#f3f4f6;border:1px solid #d1d5db;font-size:12px;";
        $campaign_table = empty( $body ) ? '<p style="color:#6b7280;font-size:13px;">No campaign data found.</p>'
            : "<table width='100%' style='border-collapse:collapse;margin-top:16px;'>
                <thead><tr>
                    <th style='{$th_s}text-align:left;'>Campaign</th>
                    <th style='{$th_s}text-align:right;'>Gross</th>
                    <th style='{$th_s}text-align:right;'>Fees</th>
                    <th style='{$th_s}text-align:right;'>Net</th>
                    <th style='{$th_s}text-align:center;'>Count</th>
                    <th style='{$th_s}text-align:right;'>Avg Gift</th>
                </tr></thead>
                <tbody>{$body}</tbody>
            </table>";

        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>{$css}</style></head>
<body>
    <h1>Campaign Analytics Report</h1>
    <div class="meta">{$meta_base}</div>
    <div class="section">Summary</div>
    <table class="kpi-table"><tr>{$kpi_cells}</tr></table>
    <div class="section">Campaign Breakdown</div>
    {$campaign_table}
</body></html>
HTML;

    // =========================================================
    // MODE: Pay Method breakdown (PDF same structure as campaign)
    // =========================================================
    } elseif ( $group === 'pay_method' ) {

        $kpis   = $report_data['kpis']   ?? [];
        $series = $report_data['series'] ?? [];

        $kpi_cells = '';
        foreach ( [
            [ 'Total Gross', $money( $kpis['gross'] ?? 0 ) ],
            [ 'Total Fees',  $money( $kpis['fee']   ?? 0 ) ],
            [ 'Total Net',   $money( $kpis['net']   ?? 0 ) ],
            [ 'Donations',   $ints(  $kpis['count'] ?? 0 ) ],
        ] as [ $lbl, $val ] ) {
            $kpi_cells .= "<td style='padding:12px 16px;text-align:center;border:1px solid #e5e7eb;'>
                <div style='font-size:17px;font-weight:700;color:#1e3a5f;'>{$val}</div>
                <div style='font-size:11px;color:#6b7280;margin-top:3px;'>{$lbl}</div>
            </td>";
        }

        $pm_labels = [ 'cc' => 'Credit Card', 'ach' => 'ACH', 'cash' => 'Cash', 'ck' => 'Check', 'other' => 'Other', 'Unknown' => 'Unknown' ];

        $body = ''; $zebra = false;
        $sorted = $series;
        usort( $sorted, fn( $a, $b ) => (float)( $b['gross'] ?? 0 ) <=> (float)( $a['gross'] ?? 0 ) );
        foreach ( $sorted as $row ) {
            $bg    = $zebra ? '#f9fafb' : '#ffffff'; $zebra = ! $zebra;
            $label = $pm_labels[ $row['period'] ?? '' ] ?? metis_escape_html( $row['period'] ?? '' );
            $avg   = (int)( $row['count'] ?? 0 ) > 0
                ? $money( (float)( $row['gross'] ?? 0 ) / (int)( $row['count'] ?? 1 ) ) : '$0.00';
            $pct   = (float)( $kpis['gross'] ?? 0 ) > 0
                ? number_format( (float)( $row['gross'] ?? 0 ) / (float)( $kpis['gross'] ) * 100, 1 ) . '%' : '—';
            $body .= "<tr style='background:{$bg};'>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;font-size:12px;'>{$label}</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-size:12px;'>" . $money( $row['gross'] ?? 0 ) . "</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:center;font-size:12px;'>{$pct}</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-size:12px;'>" . $money( $row['net'] ?? 0 ) . "</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:center;font-size:12px;'>" . $ints( $row['count'] ?? 0 ) . "</td>
                <td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-size:12px;'>{$avg}</td>
            </tr>";
        }

        $th_s = "padding:8px 12px;background:#f3f4f6;border:1px solid #d1d5db;font-size:12px;";
        $pm_table = empty( $body ) ? '<p style="color:#6b7280;font-size:13px;">No payment data found.</p>'
            : "<table width='100%' style='border-collapse:collapse;margin-top:16px;'>
                <thead><tr>
                    <th style='{$th_s}text-align:left;'>Method</th>
                    <th style='{$th_s}text-align:right;'>Gross</th>
                    <th style='{$th_s}text-align:center;'>% of Total</th>
                    <th style='{$th_s}text-align:right;'>Net</th>
                    <th style='{$th_s}text-align:center;'>Count</th>
                    <th style='{$th_s}text-align:right;'>Avg Gift</th>
                </tr></thead>
                <tbody>{$body}</tbody>
            </table>";

        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>{$css}</style></head>
<body>
    <h1>Payment Method Report</h1>
    <div class="meta">{$meta_base}</div>
    <div class="section">Summary</div>
    <table class="kpi-table"><tr>{$kpi_cells}</tr></table>
    <div class="section">Breakdown by Payment Method</div>
    {$pm_table}
</body></html>
HTML;

    // =========================================================
    // MODE: Time-series (default — day / week / month / year)
    // =========================================================
    } else {

        $kpis        = $report_data['kpis']   ?? [];
        $series      = $report_data['series'] ?? [];
        $meta        = $report_data['meta']   ?? [];
        $group_label = ucfirst( $meta['group'] ?? $group );

        $chart_html = '';
        $raw_img    = metis_request_post()['chart_image'] ?? '';
        if ( is_string( $raw_img ) && str_starts_with( $raw_img, 'data:image/png;base64,' ) ) {
            $chart_html = '<img src="' . metis_escape_attr( $raw_img ) . '" style="width:100%;max-width:680px;margin:20px 0;display:block;">';
        }

        $kpi_cells = '';
        foreach ( [
            [ 'Total Gross',  $money( $kpis['gross']   ?? 0 ) ],
            [ 'Total Fees',   $money( $kpis['fee']     ?? 0 ) ],
            [ 'Total Net',    $money( $kpis['net']     ?? 0 ) ],
            [ 'Donations',    $ints(  $kpis['count']   ?? 0 ) ],
            [ 'Avg Donation', $money( $kpis['avg']     ?? 0 ) ],
            [ 'Fee Rate',     number_format( (float)( $kpis['fee_pct'] ?? 0 ), 2 ) . '%' ],
        ] as [ $lbl, $val ] ) {
            $kpi_cells .= "<td style='padding:12px 16px;text-align:center;border:1px solid #e5e7eb;'>
                <div style='font-size:17px;font-weight:700;color:#1e3a5f;'>{$val}</div>
                <div style='font-size:11px;color:#6b7280;margin-top:3px;'>{$lbl}</div>
            </td>";
        }

        $allowed_cols = [ 'gross', 'fee', 'net', 'count', 'avg', 'fee_pct' ];
        $col_labels   = [ 'gross' => 'Gross', 'fee' => 'Fees', 'net' => 'Net',
                          'count' => 'Count', 'avg' => 'Avg Gift', 'fee_pct' => 'Fee %' ];
        $active_cols  = array_values( array_intersect(
            empty( $metrics ) ? [ 'gross', 'fee', 'net' ] : $metrics, $allowed_cols
        ) );

        $th_s = "padding:8px 12px;background:#f3f4f6;border:1px solid #d1d5db;font-size:12px;";
        $th   = "<th style='{$th_s}text-align:left;'>Period</th>";
        foreach ( $active_cols as $m ) {
            $th .= "<th style='{$th_s}text-align:right;'>{$col_labels[$m]}</th>";
        }

        $body = ''; $zebra = false;
        foreach ( $series as $row ) {
            $bg    = $zebra ? '#f9fafb' : '#ffffff'; $zebra = ! $zebra;
            $td    = "<td style='padding:7px 12px;border:1px solid #e5e7eb;font-size:12px;'>" . metis_escape_html( $row['period'] ?? '' ) . '</td>';
            foreach ( $active_cols as $m ) {
                $val = match ( $m ) {
                    'gross', 'fee', 'net', 'avg' => $money( $row[$m] ?? 0 ),
                    'count'   => $ints( $row[$m] ?? 0 ),
                    'fee_pct' => number_format( (float)( $row[$m] ?? 0 ), 2 ) . '%',
                    default   => metis_escape_html( (string)( $row[$m] ?? '' ) ),
                };
                $td .= "<td style='padding:7px 12px;border:1px solid #e5e7eb;text-align:right;font-size:12px;'>{$val}</td>";
            }
            $body .= "<tr style='background:{$bg};'>{$td}</tr>";
        }

        $data_table = empty( $body ) ? '<p style="color:#6b7280;font-size:13px;">No data for this period.</p>'
            : "<table width='100%' style='border-collapse:collapse;margin-top:16px;'>
                   <thead><tr>{$th}</tr></thead>
                   <tbody>{$body}</tbody>
               </table>";

        $ts_meta = "{$meta_base} &nbsp;&bull;&nbsp; Group By: {$group_label}";

        $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><style>{$css}</style></head>
<body>
    <h1>Donation Report</h1>
    <div class="meta">{$ts_meta}</div>
    <div class="section">Summary</div>
    <table class="kpi-table"><tr>{$kpi_cells}</tr></table>
    {$chart_html}
    <div class="section">Breakdown</div>
    {$data_table}
</body></html>
HTML;
    }

    // --- Stream PDF with canvas-drawn footer on every page ---
    try {
        $filename = match( $group ) {
            'donor'      => 'donor-intelligence-'  . date( 'Y-m-d' ) . '.pdf',
            'campaign'   => 'campaign-analytics-'  . date( 'Y-m-d' ) . '.pdf',
            'pay_method' => 'payment-method-'      . date( 'Y-m-d' ) . '.pdf',
            default      => 'donations-report-'    . date( 'Y-m-d' ) . '.pdf',
        };
        $pdf = new Core_PDF_Service();
        $pdf->download_with_footer( $html, $footer_left, $filename );
    } catch ( Exception $e ) {
        Metis_Logger::error( 'PDF export failed', [ 'error' => $e->getMessage() ] );
        metis_runtime_die( 'PDF generation failed.' );
    }
}

// -------------------------------------------------------------------------
// Saved reports
// -------------------------------------------------------------------------

function metis_ajax_report_save(): void {
    metis_donations_reports_ajax_verify( 'metis_donations_report_save', 'edit' );

    $db = metis_db();
    $table = Metis_Tables::get( 'reports' );

    if ( ! metis_reports_table_exists( $table ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Reports table missing. Run DB install first.' ], 500 );
    }

    $id    = isset( metis_request_post()['id'] ) ? (int) metis_request_post()['id'] : 0;
    $name  = metis_text_clean( metis_request_post()['name']   ?? '' );
    $cfg_s = metis_request_post()['config'] ?? '';

    if ( $name === '' || $cfg_s === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing name or config' ], 400 );
    }

    if ( is_string( $cfg_s ) ) {
        $cfg = json_decode( $cfg_s, true );
        if ( $cfg === null ) $cfg = json_decode( stripslashes( $cfg_s ), true );
    } elseif ( is_array( $cfg_s ) ) {
        $cfg = $cfg_s;
    } else {
        $cfg = null;
    }
    if ( ! is_array( $cfg ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid config JSON' ], 400 );
    }

    $now  = metis_current_time( 'mysql' );
    $data = [
        'name'        => $name,
        'config_json' => metis_json_encode( $cfg ),
        'updated_at'  => $now,
    ];

    if ( $id > 0 ) {
        $ok = $db->update( $table, $data, [ 'id' => $id ], [ '%s', '%s', '%s' ], [ '%d' ] );
        if ( $ok === false ) {
            metis_runtime_send_json_error( [ 'message' => 'Update failed.' ], 500 );
        }
        metis_reports_clear_cache();
        metis_runtime_send_json_success( [ 'id' => $id ] );
    }

    $data['created_by'] = metis_current_user_id();
    $data['created_at'] = $now;

    $ok = $db->insert( $table, $data, [ '%s', '%s', '%s', '%d', '%s' ] );
    if ( ! $ok ) {
        metis_runtime_send_json_error( [ 'message' => 'Insert failed.' ], 500 );
    }

    metis_reports_clear_cache();
    metis_runtime_send_json_success( [ 'id' => $db->lastInsertId() ] );
}

function metis_ajax_report_list(): void {
    metis_donations_reports_ajax_verify( 'metis_donations_report_list', 'view' );

    $db = metis_db();
    $table = Metis_Tables::get( 'reports' );

    if ( ! metis_reports_table_exists( $table ) ) {
        metis_runtime_send_json_success( [ 'items' => [] ] );
        return;
    }

    $rows = $db->fetchAll(
        "SELECT id, name, config_json, created_by, created_at, updated_at FROM {$table} ORDER BY updated_at DESC, id DESC",
    );

    metis_runtime_send_json_success( [ 'items' => $rows ?: [] ] );
}

function metis_ajax_report_delete(): void {
    metis_donations_reports_ajax_verify( 'metis_donations_report_delete', 'delete' );

    $db = metis_db();
    $table = Metis_Tables::get( 'reports' );

    if ( ! metis_reports_table_exists( $table ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Reports table missing' ], 500 );
    }

    $id = isset( metis_request_post()['id'] ) ? (int) metis_request_post()['id'] : 0;
    if ( $id <= 0 ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing id' ], 400 );
    }

    $ok = $db->delete( $table, [ 'id' => $id ], [ '%d' ] );
    if ( $ok === false ) {
        metis_runtime_send_json_error( [ 'message' => 'Delete failed.' ], 500 );
    }

    metis_reports_clear_cache();
    metis_runtime_send_json_success( [ 'deleted' => true ] );
}
