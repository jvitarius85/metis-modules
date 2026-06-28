<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

use Metis\Modules\GrandyStash\GrandyStashRepository;

function metis_grandys_stash_ajax_guard( bool|string $manage_required = false ): void {
    $permission = '';
    if ( is_string( $manage_required ) ) {
        $permission = $manage_required;
    } elseif ( $manage_required ) {
        $permission = 'grandys_stash.edit';
    } else {
        $permission = 'grandys_stash.view';
    }

    if ( ! function_exists( 'metis_security_user_can' ) || ! metis_security_user_can( $permission ) ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

function metis_grandys_stash_error_status( $status ): int {
    $code = (int) $status;
    return in_array( $code, [ 400, 401, 403, 404, 409, 422, 429 ], true ) ? $code : 500;
}

function metis_grandys_stash_ticket_payload( int $ticket_id ): array {
    $detail = GrandyStashRepository::getTicketDetailData( $ticket_id );
    return [
        'state' => GrandyStashRepository::dashboardData(),
        'detail' => $detail,
    ];
}

function metis_grandys_stash_register_ajax_controllers(): void {
    $actions = [
        'metis_grandys_stash_state' => 'view',
        'metis_grandys_stash_save_ticket' => 'assign',
        'metis_grandys_stash_update_item_status' => 'inventory',
        'metis_grandys_stash_add_note' => 'comment',
        'metis_grandys_stash_send_reply' => 'reply',
        'metis_grandys_stash_ticket_detail' => 'view',
        'metis_grandys_stash_unlink_group' => 'assign',
        'metis_grandys_stash_create_ticket' => 'create',
        'metis_grandys_stash_contact_search' => 'view',
        'metis_grandys_stash_search_groups' => 'view',
        'metis_grandys_stash_link_group' => 'assign',
        'metis_grandys_stash_merge_groups' => 'assign',
        'metis_grandys_stash_link_organization_ticket' => 'settings',
        'metis_grandys_stash_merge_organizations' => 'settings',
        'metis_grandys_stash_move_organization_to_independent' => 'settings',
        'metis_grandys_stash_resolve_org_candidate' => 'settings',
        'metis_grandys_stash_resolve_item_candidate' => 'settings',
        'metis_grandys_stash_save_group' => 'assign',
        'metis_grandys_stash_save_organization' => 'settings',
        'metis_grandys_stash_get_inventory' => 'view',
        'metis_grandys_stash_update_inventory' => 'inventory',
        'metis_grandys_stash_set_email_pref' => 'settings',
        'metis_grandys_stash_export' => 'export',
        'metis_grandys_stash_delete_ticket' => 'delete',
        'metis_grandys_stash_delete_tickets' => 'view',
        'metis_grandys_stash_save_routing_defaults' => 'settings',
        'metis_grandys_stash_save_legacy_import_settings' => 'settings',
        'metis_grandys_stash_preview_legacy' => 'settings',
        'metis_grandys_stash_audit_legacy_types' => 'settings',
        'metis_grandys_stash_import_legacy' => 'settings',
        'metis_grandys_stash_wipe_legacy_imports' => 'settings',
        'metis_grandys_stash_repair_legacy_item_rows' => 'settings',
        'metis_grandys_stash_report' => 'view',
        'metis_grandys_stash_get_email_prefs' => 'settings',
    ];

    foreach ( $actions as $action => $permission ) {
        metis_ajax_register_controller( $action, [
            'module' => 'grandys_stash',
            'permission' => $permission,
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
}

metis_grandys_stash_register_ajax_controllers();

function metis_grandys_stash_report_request_args(): array {
    return [
        'from'           => metis_text_clean( (string) ( metis_request_post()['from'] ?? '' ) ),
        'to'             => metis_text_clean( (string) ( metis_request_post()['to'] ?? '' ) ),
        'page'           => max( 1, (int) ( metis_request_post()['page'] ?? 1 ) ),
        'per_page'       => (int) ( metis_request_post()['per_page'] ?? 25 ),
        'search'         => metis_text_clean( (string) ( metis_runtime_unslash( metis_request_post()['search'] ?? '' ) ) ),
        'category'       => metis_key_clean( (string) ( metis_request_post()['category'] ?? '' ) ),
        'item'           => metis_text_clean( (string) ( metis_runtime_unslash( metis_request_post()['item'] ?? '' ) ) ),
        'organization'   => metis_text_clean( (string) ( metis_runtime_unslash( metis_request_post()['organization'] ?? '' ) ) ),
        'person'         => metis_text_clean( (string) ( metis_runtime_unslash( metis_request_post()['person'] ?? '' ) ) ),
        'urgency'        => metis_key_clean( (string) ( metis_request_post()['urgency'] ?? '' ) ),
        'type'           => metis_key_clean( (string) ( metis_request_post()['type'] ?? '' ) ),
        'status'         => strtoupper( metis_key_clean( (string) ( metis_request_post()['status'] ?? '' ) ) ),
        'assigned'       => metis_text_clean( (string) ( metis_runtime_unslash( metis_request_post()['assigned'] ?? '' ) ) ),
        'sort_field'     => metis_key_clean( (string) ( metis_request_post()['sort_field'] ?? 'submitted_at' ) ),
        'sort_direction' => strtolower( (string) ( metis_request_post()['sort_direction'] ?? 'desc' ) ) === 'asc' ? 'asc' : 'desc',
    ];
}

function metis_grandys_stash_report_badge_class( string $kind, string $value ): string {
    $value = strtolower( trim( $value ) );
    if ( $kind === 'type' ) {
        return 'metis-stash-type-badge metis-stash-type-' . ( $value === 'donation' ? 'donation' : 'request' );
    }

    $allowed = [ 'new', 'reviewing', 'waitlist', 'ready', 'completed', 'closed', 'pending', 'available', 'fulfilled', 'unavailable' ];
    if ( ! in_array( $value, $allowed, true ) ) {
        $value = 'completed';
    }

    return 'metis-stash-status-badge metis-stash-status-' . $value;
}

function metis_grandys_stash_report_trend_svg( array $monthly ): string {
    if ( $monthly === [] ) {
        return '<div style="color:#667085;font-size:12px;">Not enough data to graph a trend for this range.</div>';
    }

    $points = array_reverse( array_slice( $monthly, 0, 12 ) );
    $max = 1;
    foreach ( $points as $point ) {
        $max = max(
            $max,
            (int) ( $point['tickets'] ?? 0 ),
            (int) ( $point['requests'] ?? 0 ),
            (int) ( $point['donations'] ?? 0 ),
            (int) ( $point['completed'] ?? 0 )
        );
    }

    $width  = 760;
    $height = 240;
    $left   = 46;
    $right  = 18;
    $top    = 20;
    $bottom = 42;
    $plot_w = $width - $left - $right;
    $plot_h = $height - $top - $bottom;
    $count  = max( 1, count( $points ) - 1 );

    $build_polyline = static function ( string $key ) use ( $points, $count, $left, $top, $plot_w, $plot_h, $max ): string {
        $coords = [];
        foreach ( $points as $index => $point ) {
            $x = $left + ( $count === 0 ? 0 : ( $plot_w * ( $index / $count ) ) );
            $value = (int) ( $point[ $key ] ?? 0 );
            $y = $top + $plot_h - ( $plot_h * ( $value / $max ) );
            $coords[] = round( $x, 2 ) . ',' . round( $y, 2 );
        }
        return implode( ' ', $coords );
    };

    $labels = [];
    foreach ( $points as $index => $point ) {
        $x = $left + ( $count === 0 ? 0 : ( $plot_w * ( $index / $count ) ) );
        $labels[] = '<text x="' . round( $x, 2 ) . '" y="' . ( $height - 16 ) . '" text-anchor="middle" font-size="10" fill="#667085">' . metis_escape_html( (string) ( $point['month'] ?? '' ) ) . '</text>';
    }

    $grid = [];
    for ( $step = 0; $step <= 4; $step++ ) {
        $y = $top + ( $plot_h * ( $step / 4 ) );
        $value = (int) round( $max - ( $max * ( $step / 4 ) ) );
        $grid[] = '<line x1="' . $left . '" y1="' . round( $y, 2 ) . '" x2="' . ( $width - $right ) . '" y2="' . round( $y, 2 ) . '" stroke="#e4e7ec" stroke-width="1" />';
        $grid[] = '<text x="' . ( $left - 8 ) . '" y="' . round( $y + 4, 2 ) . '" text-anchor="end" font-size="10" fill="#667085">' . $value . '</text>';
    }

    $legend = [
        [ '#175cd3', 'Tickets' ],
        [ '#3d5a1e', 'Requests' ],
        [ '#8e4b10', 'Donations' ],
        [ '#344054', 'Completed' ],
    ];

    $legend_html = [];
    foreach ( $legend as $index => $row ) {
        $x = $left + ( $index * 150 );
        $legend_html[] = '<rect x="' . $x . '" y="4" width="12" height="12" rx="6" fill="' . $row[0] . '" />';
        $legend_html[] = '<text x="' . ( $x + 18 ) . '" y="14" font-size="11" fill="#344054">' . metis_escape_html( $row[1] ) . '</text>';
    }

    return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="240" role="img" aria-label="Monthly ticket trends">' .
        implode( '', $legend_html ) .
        implode( '', $grid ) .
        '<line x1="' . $left . '" y1="' . ( $top + $plot_h ) . '" x2="' . ( $width - $right ) . '" y2="' . ( $top + $plot_h ) . '" stroke="#98a2b3" stroke-width="1.2" />' .
        '<polyline fill="none" stroke="#175cd3" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="' . $build_polyline( 'tickets' ) . '" />' .
        '<polyline fill="none" stroke="#3d5a1e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="' . $build_polyline( 'requests' ) . '" />' .
        '<polyline fill="none" stroke="#8e4b10" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="' . $build_polyline( 'donations' ) . '" />' .
        '<polyline fill="none" stroke="#344054" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="' . $build_polyline( 'completed' ) . '" />' .
        implode( '', $labels ) .
    '</svg>';
}

function metis_grandys_stash_report_pdf_html( array $report, array $rows, array $filters ): string {
    $summary = is_array( $report['summary'] ?? null ) ? $report['summary'] : [];
    $range   = trim( (string) ( $filters['from'] ?? '' ) ) !== '' || trim( (string) ( $filters['to'] ?? '' ) ) !== ''
        ? ( trim( (string) ( $filters['from'] ?? '' ) ) ?: 'Start' ) . ' to ' . ( trim( (string) ( $filters['to'] ?? '' ) ) ?: 'Today' )
        : 'All available ticket history';

    $meta_parts = [ 'Range: ' . $range ];
    foreach ( [ 'category', 'item', 'organization', 'person', 'urgency', 'type', 'status', 'assigned', 'search' ] as $key ) {
        $value = trim( (string) ( $filters[ $key ] ?? '' ) );
        if ( $value !== '' ) {
            $meta_parts[] = ucfirst( str_replace( '_', ' ', $key ) ) . ': ' . $value;
        }
    }

    $kpis = [
        [ 'Total Tickets', (int) ( $summary['total_tickets'] ?? 0 ) ],
        [ 'People Served', (int) ( $report['people_served'] ?? 0 ) ],
        [ 'Items Fulfilled', (int) ( $report['items_fulfilled'] ?? 0 ) ],
        [ 'Completed', (int) ( $summary['completed'] ?? 0 ) ],
        [ 'Open', (int) ( $summary['open_tickets'] ?? 0 ) ],
        [ 'Avg Days', (string) ( $report['avg_days_to_complete'] ?? '0' ) ],
    ];

    $kpi_html = '';
    foreach ( $kpis as $card ) {
        $kpi_html .= '<td style="width:16.66%;padding:0 8px 0 0;vertical-align:top;">' .
            '<div style="border:1px solid #d0d5dd;border-radius:14px;padding:14px 16px;background:#f8fafc;">' .
                '<div style="font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#667085;">' . metis_escape_html( (string) $card[0] ) . '</div>' .
                '<div style="margin-top:8px;font-size:24px;font-weight:700;color:#101828;">' . metis_escape_html( (string) $card[1] ) . '</div>' .
            '</div>' .
        '</td>';
    }

    $row_html = '';
    foreach ( $rows as $index => $row ) {
        $bg = $index % 2 === 0 ? '#ffffff' : '#f8fafc';
        $type_label = strtolower( (string) ( $row['type'] ?? 'request' ) ) === 'donation' ? 'Donation' : 'Request';
        $status_label = strtoupper( (string) ( $row['status'] ?? 'NEW' ) );
        $row_html .= '<tr style="background:' . $bg . ';">' .
            '<td style="padding:8px 10px;border:1px solid #e4e7ec;font-size:11px;">' . metis_escape_html( metis_runtime_format_date( (string) ( $row['submitted_at'] ?? '' ) ) ) . '</td>' .
            '<td style="padding:8px 10px;border:1px solid #e4e7ec;font-size:11px;font-weight:700;">' . metis_escape_html( (string) ( $row['code'] ?? '' ) ) . '</td>' .
            '<td style="padding:8px 10px;border:1px solid #e4e7ec;font-size:11px;">' . metis_escape_html( (string) ( $row['submit_name'] ?? 'Unknown' ) ) . '</td>' .
            '<td style="padding:8px 10px;border:1px solid #e4e7ec;font-size:11px;">' . metis_escape_html( (string) ( $row['organization_label'] ?? 'Independent' ) ) . '</td>' .
            '<td style="padding:8px 10px;border:1px solid #e4e7ec;font-size:11px;">' . metis_escape_html( (string) ( $row['assigned_label'] ?? '—' ) ) . '</td>' .
            '<td style="padding:8px 10px;border:1px solid #e4e7ec;font-size:11px;"><span class="' . metis_escape_attr( metis_grandys_stash_report_badge_class( 'type', (string) ( $row['type'] ?? 'request' ) ) ) . '">' . metis_escape_html( $type_label ) . '</span></td>' .
            '<td style="padding:8px 10px;border:1px solid #e4e7ec;font-size:11px;">' . metis_escape_html( ucfirst( (string) ( $row['urgency'] ?? 'standard' ) ) ) . '</td>' .
            '<td style="padding:8px 10px;border:1px solid #e4e7ec;font-size:11px;"><span class="' . metis_escape_attr( metis_grandys_stash_report_badge_class( 'status', $status_label ) ) . '">' . metis_escape_html( $status_label ) . '</span></td>' .
            '<td style="padding:8px 10px;border:1px solid #e4e7ec;font-size:11px;">' . metis_escape_html( (string) ( $row['items_summary'] ?? '—' ) ) . '</td>' .
        '</tr>';
    }

    if ( $row_html === '' ) {
        $row_html = '<tr><td colspan="9" style="padding:12px;border:1px solid #e4e7ec;font-size:12px;color:#667085;">No tickets matched the selected filters.</td></tr>';
    }

    $css = '
        body{font-family:Figtree,DejaVu Sans,sans-serif;color:#101828;font-size:12px;}
        h1{font-size:28px;margin:0 0 8px;}
        h2{font-size:16px;margin:22px 0 10px;}
        p{margin:0 0 8px;}
        .meta{color:#475467;font-size:12px;line-height:1.5;}
        .metis-stash-type-badge,.metis-stash-status-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:10px;font-weight:700;line-height:1.4;}
        .metis-stash-type-request{background:#edf6e3;border:1px solid #b7d08f;color:#3d5a1e;}
        .metis-stash-type-donation{background:#f4ecd9;border:1px solid #d1bb84;color:#5a4a22;}
        .metis-stash-status-new{background:#eff8ff;border:1px solid #b2ddff;color:#175cd3;}
        .metis-stash-status-reviewing{background:#fef3f2;border:1px solid #fecdca;color:#b42318;}
        .metis-stash-status-waitlist{background:#fffaeb;border:1px solid #fedf89;color:#b54708;}
        .metis-stash-status-ready{background:#ecfdf3;border:1px solid #abefc6;color:#067647;}
        .metis-stash-status-completed{background:#f0f2f5;border:1px solid #d0d5dd;color:#344054;}
        .metis-stash-status-closed{background:#f0f2f5;border:1px solid #d0d5dd;color:#667085;}
        table{border-collapse:collapse;width:100%;}
    ';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>' .
        '<h1>Grandy&apos;s Stash Report</h1>' .
        '<p class="meta">' . metis_escape_html( implode( ' | ', $meta_parts ) ) . '</p>' .
        '<table style="width:100%;margin-top:18px;"><tr>' . $kpi_html . '</tr></table>' .
        '<h2>Monthly Trends</h2>' .
        metis_grandys_stash_report_trend_svg( (array) ( $report['monthly'] ?? [] ) ) .
        '<h2>Filtered Tickets</h2>' .
        '<table style="margin-top:10px;"><thead><tr>' .
            '<th style="padding:8px 10px;border:1px solid #d0d5dd;background:#eef2ff;font-size:11px;text-align:left;">Submitted</th>' .
            '<th style="padding:8px 10px;border:1px solid #d0d5dd;background:#eef2ff;font-size:11px;text-align:left;">Code</th>' .
            '<th style="padding:8px 10px;border:1px solid #d0d5dd;background:#eef2ff;font-size:11px;text-align:left;">Name</th>' .
            '<th style="padding:8px 10px;border:1px solid #d0d5dd;background:#eef2ff;font-size:11px;text-align:left;">Organization</th>' .
            '<th style="padding:8px 10px;border:1px solid #d0d5dd;background:#eef2ff;font-size:11px;text-align:left;">Assigned</th>' .
            '<th style="padding:8px 10px;border:1px solid #d0d5dd;background:#eef2ff;font-size:11px;text-align:left;">Type</th>' .
            '<th style="padding:8px 10px;border:1px solid #d0d5dd;background:#eef2ff;font-size:11px;text-align:left;">Urgency</th>' .
            '<th style="padding:8px 10px;border:1px solid #d0d5dd;background:#eef2ff;font-size:11px;text-align:left;">Status</th>' .
            '<th style="padding:8px 10px;border:1px solid #d0d5dd;background:#eef2ff;font-size:11px;text-align:left;">Items</th>' .
        '</tr></thead><tbody>' . $row_html . '</tbody></table>' .
    '</body></html>';
}

// ─── Dashboard state ─────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_state', function (): void {
    metis_grandys_stash_ajax_guard();
    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
    ] );
} );

// ─── Save ticket (status, assignment, urgency) ───────

metis_ajax_register_handler( 'metis_grandys_stash_save_ticket', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.assign' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid ticket payload.', 422 );
    }
    $result = GrandyStashRepository::saveTicket( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to save ticket.', metis_grandys_stash_error_status( $result['status'] ?? 500 ) );
    }
    $ticket_id = (int) ( $result['ticket']['id'] ?? 0 );
    metis_runtime_send_json_success(
        $ticket_id > 0
            ? metis_grandys_stash_ticket_payload( $ticket_id )
            : [ 'state' => GrandyStashRepository::dashboardData() ]
    );
} );

// ─── Update ticket item status ───────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_update_item_status', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.inventory' );
    $item_id = (int) ( metis_request_post()['item_id'] ?? 0 );
    $status  = (string) ( metis_request_post()['status'] ?? '' );
    if ( $item_id < 1 || $status === '' ) {
        metis_runtime_send_json_error( 'Item ID and status are required.', 422 );
    }
    $result = GrandyStashRepository::updateTicketItemStatus( $item_id, $status );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to update item.', metis_grandys_stash_error_status( $result['status'] ?? 500 ) );
    }
    $ticket_id = (int) ( $result['ticket_id'] ?? 0 );
    metis_runtime_send_json_success(
        $ticket_id > 0
            ? metis_grandys_stash_ticket_payload( $ticket_id )
            : [ 'state' => GrandyStashRepository::dashboardData() ]
    );
} );

// ─── Add note ────────────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_add_note', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.comment' );
    $ticket_id = (int) ( metis_request_post()['ticket_id'] ?? 0 );
    $content   = metis_textarea_clean( metis_runtime_unslash( metis_request_post()['content'] ?? '' ) );
    if ( $ticket_id < 1 || trim( $content ) === '' ) {
        metis_runtime_send_json_error( 'Ticket ID and note content are required.', 422 );
    }
    $result = GrandyStashRepository::addNote( $ticket_id, $content );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to add note.', metis_grandys_stash_error_status( $result['status'] ?? 500 ) );
    }
    metis_runtime_send_json_success( [
        'notes'    => GrandyStashRepository::getTicketNotes( $ticket_id ),
        'activity' => GrandyStashRepository::getTicketActivity( $ticket_id ),
    ] );
} );

// ─── Send reply ───────────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_send_reply', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.reply' );
    $ticket_id = (int) ( metis_request_post()['ticket_id'] ?? 0 );
    $content   = metis_textarea_clean( metis_runtime_unslash( metis_request_post()['content'] ?? '' ) );
    $subject   = metis_text_clean( metis_runtime_unslash( metis_request_post()['subject'] ?? '' ) );
    if ( $ticket_id < 1 || trim( $content ) === '' ) {
        metis_runtime_send_json_error( 'Ticket ID and reply content are required.', 422 );
    }

    $result = GrandyStashRepository::sendTicketReply( $ticket_id, $content, $subject );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to send reply.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'messages' => GrandyStashRepository::getTicketMessages( $ticket_id ),
        'activity' => GrandyStashRepository::getTicketActivity( $ticket_id ),
    ] );
} );

// ─── Get ticket detail (messages + notes + activity + items) ────

metis_ajax_register_handler( 'metis_grandys_stash_ticket_detail', function (): void {
    metis_grandys_stash_ajax_guard();
    $ticket_id = (int) ( metis_request_post()['ticket_id'] ?? 0 );
    if ( $ticket_id < 1 ) {
        metis_runtime_send_json_error( 'Ticket ID is required.', 422 );
    }
    $detail = GrandyStashRepository::getTicketDetailData( $ticket_id );
    if ( ! $detail ) {
        metis_runtime_send_json_error( 'Ticket not found.', 404 );
    }
    metis_runtime_send_json_success( $detail );
} );

// ─── Unlink ticket from group ────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_unlink_group', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.assign' );
    $ticket_id = (int) ( metis_request_post()['ticket_id'] ?? 0 );
    if ( $ticket_id < 1 ) {
        metis_runtime_send_json_error( 'Ticket ID is required.', 422 );
    }
    $result = GrandyStashRepository::unlinkTicketFromGroup( $ticket_id );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to unlink.', metis_grandys_stash_error_status( $result['status'] ?? 500 ) );
    }
    metis_runtime_send_json_success( metis_grandys_stash_ticket_payload( $ticket_id ) );
} );


// ─── Contact search ─────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_contact_search', function (): void {
    metis_grandys_stash_ajax_guard();
    $query = isset( metis_request_post()['query'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['query'] ) ) : '';
    metis_runtime_send_json_success( [ 'contacts' => GrandyStashRepository::searchContacts( $query ) ] );
} );

// ─── Group operations ────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_search_groups', function (): void {
    metis_grandys_stash_ajax_guard();
    $query = isset( metis_request_post()['query'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['query'] ) ) : '';
    metis_runtime_send_json_success( [ 'groups' => GrandyStashRepository::searchGroups( $query ) ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_link_group', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.assign' );
    $ticket_id = (int) ( metis_request_post()['ticket_id'] ?? 0 );
    $group_id  = (int) ( metis_request_post()['group_id'] ?? 0 );
    if ( $ticket_id < 1 || $group_id < 1 ) {
        metis_runtime_send_json_error( 'Ticket ID and Group ID are required.', 422 );
    }
    $result = GrandyStashRepository::linkTicketToGroup( $ticket_id, $group_id );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to link group.', 500 );
    }
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_merge_groups', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.assign' );
    $source_id = (int) ( metis_request_post()['source_id'] ?? 0 );
    $target_id = (int) ( metis_request_post()['target_id'] ?? 0 );
    if ( $source_id < 1 || $target_id < 1 ) {
        metis_runtime_send_json_error( 'Source and target group IDs are required.', 422 );
    }
    $result = GrandyStashRepository::mergeGroups( $source_id, $target_id );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to merge groups.', 500 );
    }
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_link_organization_ticket', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $organization_id = (int) ( metis_request_post()['organization_id'] ?? 0 );
    $ticket_code = metis_text_clean( metis_runtime_unslash( metis_request_post()['ticket_code'] ?? '' ) );
    if ( $organization_id < 1 || trim( $ticket_code ) === '' ) {
        metis_runtime_send_json_error( 'Organization and ticket code are required.', 422 );
    }

    $result = GrandyStashRepository::linkTicketToOrganizationByCode( $ticket_code, $organization_id );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to link ticket.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_merge_organizations', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $source_id = (int) ( metis_request_post()['source_id'] ?? 0 );
    $target_code = metis_text_clean( metis_runtime_unslash( metis_request_post()['target_code'] ?? '' ) );
    if ( $source_id < 1 || trim( $target_code ) === '' ) {
        metis_runtime_send_json_error( 'Source organization and destination organization are required.', 422 );
    }

    $result = GrandyStashRepository::mergeOrganizationIntoByCode( $source_id, $target_code );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to merge organizations.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
        'merge' => $result,
    ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_move_organization_to_independent', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $organization_id = (int) ( metis_request_post()['organization_id'] ?? 0 );
    if ( $organization_id < 1 ) {
        metis_runtime_send_json_error( 'Organization is required.', 422 );
    }

    $result = GrandyStashRepository::moveOrganizationToIndependent( $organization_id );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to move organization to Independent.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
        'move' => $result,
    ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_resolve_org_candidate', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid organization resolution payload.', 422 );
    }

    $result = GrandyStashRepository::resolveOrganizationCandidate( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to resolve organization.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
        'resolution' => $result,
    ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_resolve_item_candidate', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid item resolution payload.', 422 );
    }

    $result = GrandyStashRepository::resolveItemCandidate( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to resolve item.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
        'resolution' => $result,
    ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_repair_legacy_item_rows', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        $payload = [];
    }

    $result = GrandyStashRepository::repairLegacyItemRows( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to repair legacy item rows.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
        'repair' => $result,
    ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_save_group', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.assign' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid group payload.', 422 );
    }
    $result = GrandyStashRepository::saveGroup( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( (string) ( $result['error'] ?? 'Unable to save group.' ), metis_grandys_stash_error_status( $result['status'] ?? 500 ) );
    }
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_save_organization', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid organization payload.', 422 );
    }
    $result = GrandyStashRepository::saveOrganization( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( (string) ( $result['error'] ?? 'Unable to save organization.' ), metis_grandys_stash_error_status( $result['status'] ?? 500 ) );
    }
    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
        'organization_id' => (int) ( $result['organization_id'] ?? 0 ),
    ] );
} );

// ─── Create ticket (manual staff intake) ────────────

metis_ajax_register_handler( 'metis_grandys_stash_create_ticket', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.create' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid payload.', 422 );
    }
    $result = GrandyStashRepository::createTicket( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to create ticket.', metis_grandys_stash_error_status( $result['status'] ?? 500 ) );
    }
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

// ─── Inventory management ───────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_get_inventory', function (): void {
    metis_grandys_stash_ajax_guard();
    metis_runtime_send_json_success( [ 'inventory' => GrandyStashRepository::getInventory() ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_update_inventory', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.inventory' );
    $catalog_item_id = (int) ( metis_request_post()['catalog_item_id'] ?? 0 );
    $qty             = (int) ( metis_request_post()['qty'] ?? 0 );
    if ( $catalog_item_id < 1 ) {
        metis_runtime_send_json_error( 'Catalog item ID is required.', 422 );
    }
    $result = GrandyStashRepository::setInventoryQty( $catalog_item_id, $qty );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to update inventory.', 500 );
    }
    metis_runtime_send_json_success( [ 'inventory' => GrandyStashRepository::getInventory() ] );
} );

// ─── Export tickets ─────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_export', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.export' );
    $format = strtolower( metis_key_clean( (string) ( metis_request_post()['format'] ?? 'json' ) ) );
    $args   = metis_grandys_stash_report_request_args();

    if ( $format === 'pdf' ) {
        $report = GrandyStashRepository::reportData( (string) $args['from'], (string) $args['to'] );
        $rows   = GrandyStashRepository::reportTicketExportRows( $args );
        $html   = metis_grandys_stash_report_pdf_html( $report, $rows, $args );
        $pdf    = new Core_PDF_Service();
        $pdf->download_with_footer(
            $html,
            'Mobilize Waco - Grandy\'s Stash Report',
            'grandys-stash-report-' . date( 'Y-m-d' ) . '.pdf',
            [ 'paper' => 'letter', 'orientation' => 'landscape' ]
        );
        return;
    }

    $page = GrandyStashRepository::reportTicketPage( $args );
    metis_runtime_send_json_success( [
        'rows'       => (array) ( $page['rows'] ?? [] ),
        'pagination' => (array) ( $page['pagination'] ?? [] ),
        'count'      => (int) ( $page['pagination']['total'] ?? 0 ),
    ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_delete_ticket', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.delete' );
    $ticket_id = (int) ( metis_request_post()['ticket_id'] ?? 0 );
    if ( $ticket_id < 1 ) {
        metis_runtime_send_json_error( 'Ticket ID is required.', 422 );
    }
    $result = GrandyStashRepository::deleteTicket( $ticket_id );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( (string) ( $result['error'] ?? 'Unable to delete ticket.' ), metis_grandys_stash_error_status( $result['status'] ?? 500 ) );
    }
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData(), 'deleted_code' => $result['deleted_code'] ?? '' ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_delete_tickets', function (): void {
    metis_grandys_stash_ajax_guard();
    if ( ! function_exists( 'metis_grandys_stash_is_system_admin' ) || ! metis_grandys_stash_is_system_admin() ) {
        metis_runtime_send_json_error( 'Only system administrators can bulk delete tickets.', 403 );
    }

    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    $ticket_ids = is_array( $payload['ticket_ids'] ?? null ) ? (array) $payload['ticket_ids'] : [];
    $result = GrandyStashRepository::deleteTickets( $ticket_ids );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to delete tickets.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
        'deleted_count' => (int) ( $result['deleted_count'] ?? 0 ),
        'deleted_codes' => (array) ( $result['deleted_codes'] ?? [] ),
    ] );
} );

// ─── Save routing defaults ──────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_save_routing_defaults', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid routing defaults payload.', 422 );
    }
    $result = GrandyStashRepository::saveRoutingDefaults( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to save routing defaults.', metis_grandys_stash_error_status( $result['status'] ?? 500 ) );
    }
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_save_legacy_import_settings', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid legacy import settings payload.', 422 );
    }

    $result = GrandyStashRepository::saveLegacyImportSettings( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to save legacy import settings.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_preview_legacy', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid legacy preview payload.', 422 );
    }

    $result = GrandyStashRepository::previewLegacyGravityForms( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to preview legacy tickets.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'preview' => [
            'mode' => (string) ( $result['mode'] ?? 'remote' ),
            'form_id' => (int) ( $result['form_id'] ?? 0 ),
            'data' => (array) ( $result['preview'] ?? [] ),
        ],
    ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_audit_legacy_types', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid legacy audit payload.', 422 );
    }

    $result = GrandyStashRepository::auditLegacyImportedTypes( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to audit legacy ticket types.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'audit' => (array) ( $result['audit'] ?? [] ),
    ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_import_legacy', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $payload = json_decode( (string) ( metis_request_post()['payload'] ?? '' ), true );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Invalid legacy import payload.', 422 );
    }

    $result = GrandyStashRepository::importLegacyGravityForms( $payload );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to import legacy tickets.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
        'import' => [
            'imported' => (int) ( $result['imported'] ?? 0 ),
            'skipped' => (int) ( $result['skipped'] ?? 0 ),
            'errors' => array_values( array_map( 'strval', (array) ( $result['errors'] ?? [] ) ) ),
            'summary' => (string) ( $result['summary'] ?? '' ),
        ],
    ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_wipe_legacy_imports', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $result = GrandyStashRepository::wipeLegacyImportedTickets();
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            (string) ( $result['error'] ?? 'Unable to wipe legacy imports.' ),
            metis_grandys_stash_error_status( $result['status'] ?? 500 )
        );
    }

    metis_runtime_send_json_success( [
        'state' => GrandyStashRepository::dashboardData(),
        'wipe' => [
            'deleted' => (int) ( $result['deleted'] ?? 0 ),
            'pruned_groups' => (int) ( $result['pruned_groups'] ?? 0 ),
            'pruned_organizations' => (int) ( $result['pruned_organizations'] ?? 0 ),
        ],
    ] );
} );


// ─── Reports ─────────────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_report', function (): void {
    metis_grandys_stash_ajax_guard();
    $args = metis_grandys_stash_report_request_args();
    metis_runtime_send_json_success( [
        'report'        => GrandyStashRepository::reportData( (string) $args['from'], (string) $args['to'] ),
        'reportPage'    => GrandyStashRepository::reportTicketPage( $args ),
        'reportOptions' => GrandyStashRepository::reportBuilderOptions( (string) $args['from'], (string) $args['to'] ),
        'filters'       => [
            'from' => (string) $args['from'],
            'to'   => (string) $args['to'],
        ],
    ] );
} );

// ─── Email preferences ──────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_get_email_prefs', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    metis_runtime_send_json_success( [ 'prefs' => GrandyStashRepository::getEmailPrefs() ] );
} );

metis_ajax_register_handler( 'metis_grandys_stash_set_email_pref', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.settings' );
    $user_id = (int) ( metis_request_post()['user_id'] ?? 0 );
    $enabled = ( metis_request_post()['enabled'] ?? '0' ) === '1';
    if ( $user_id < 1 ) {
        metis_runtime_send_json_error( 'User ID is required.', 422 );
    }
    $result = GrandyStashRepository::setEmailPref( $user_id, $enabled );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( 'Unable to update.', 500 );
    }
    metis_runtime_send_json_success( [ 'prefs' => GrandyStashRepository::getEmailPrefs() ] );
} );
