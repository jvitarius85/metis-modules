<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

use Metis\Modules\Donations\DonationsReportService;

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
    return DonationsReportService::tableExists( $table );
}

// -------------------------------------------------------------------------
// Core report builder
// -------------------------------------------------------------------------

function metis_build_donations_report_data( array $input ): array {
    return DonationsReportService::buildReportData( $input );
}

// -------------------------------------------------------------------------
// Top N donors helper
// -------------------------------------------------------------------------

function metis_build_top_donors( string $transactions_table, string $where, array $params, int $limit = 10 ): array {
    return [];
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

    $table = Metis_Tables::get( 'reports' );
    if ( ! DonationsReportService::tableExists( $table ) ) {
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

    $saved_id = DonationsReportService::saveSavedReport( $id, $name, $cfg );
    if ( $saved_id <= 0 ) {
        metis_runtime_send_json_error( [ 'message' => $id > 0 ? 'Update failed.' : 'Insert failed.' ], 500 );
    }

    metis_reports_clear_cache();
    metis_runtime_send_json_success( [ 'id' => $saved_id ] );
}

function metis_ajax_report_list(): void {
    metis_donations_reports_ajax_verify( 'metis_donations_report_list', 'view' );

    $table = Metis_Tables::get( 'reports' );

    if ( ! metis_reports_table_exists( $table ) ) {
        metis_runtime_send_json_success( [ 'items' => [] ] );
        return;
    }

    metis_runtime_send_json_success( [ 'items' => DonationsReportService::listSavedReports() ] );
}

function metis_ajax_report_delete(): void {
    metis_donations_reports_ajax_verify( 'metis_donations_report_delete', 'delete' );

    $table = Metis_Tables::get( 'reports' );

    if ( ! DonationsReportService::tableExists( $table ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Reports table missing' ], 500 );
    }

    $id = isset( metis_request_post()['id'] ) ? (int) metis_request_post()['id'] : 0;
    if ( $id <= 0 ) {
        metis_runtime_send_json_error( [ 'message' => 'Missing id' ], 400 );
    }

    if ( ! DonationsReportService::deleteSavedReport( $id ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Delete failed.' ], 500 );
    }

    metis_reports_clear_cache();
    metis_runtime_send_json_success( [ 'deleted' => true ] );
}
