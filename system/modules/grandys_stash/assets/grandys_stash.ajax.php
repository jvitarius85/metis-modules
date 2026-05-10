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
        'metis_grandys_stash_get_inventory' => 'view',
        'metis_grandys_stash_update_inventory' => 'inventory',
        'metis_grandys_stash_set_email_pref' => 'settings',
        'metis_grandys_stash_export' => 'export',
        'metis_grandys_stash_save_routing_defaults' => 'settings',
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

// ─── Dashboard state ─────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_state', function (): void {
    metis_grandys_stash_ajax_guard();
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
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
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
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
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
} );

// ─── Add note ────────────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_add_note', function (): void {
    metis_grandys_stash_ajax_guard( 'grandys_stash.comment' );
    $ticket_id = (int) ( metis_request_post()['ticket_id'] ?? 0 );
    $content   = (string) ( metis_request_post()['content'] ?? '' );
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
    $content   = (string) ( metis_request_post()['content'] ?? '' );
    $subject   = (string) ( metis_request_post()['subject'] ?? '' );
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
    metis_runtime_send_json_success( [ 'state' => GrandyStashRepository::dashboardData() ] );
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
    $filters = [
        'date_from' => isset( metis_request_post()['date_from'] ) ? metis_text_clean( metis_runtime_unslash( metis_request_post()['date_from'] ) ) : '',
        'date_to'   => isset( metis_request_post()['date_to'] )   ? metis_text_clean( metis_runtime_unslash( metis_request_post()['date_to'] ) )   : '',
        'type'      => isset( metis_request_post()['type'] )       ? metis_key_clean( metis_request_post()['type'] )                                : '',
        'status'    => isset( metis_request_post()['status'] )     ? strtoupper( metis_key_clean( metis_request_post()['status'] ) )               : '',
    ];
    $rows = GrandyStashRepository::exportTickets( $filters );
    metis_runtime_send_json_success( [ 'rows' => $rows, 'count' => count( $rows ) ] );
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


// ─── Reports ─────────────────────────────────────────

metis_ajax_register_handler( 'metis_grandys_stash_report', function (): void {
    metis_grandys_stash_ajax_guard();
    $from = metis_text_clean( (string) ( metis_request_post()['from'] ?? '' ) );
    $to   = metis_text_clean( (string) ( metis_request_post()['to'] ?? '' ) );
    metis_runtime_send_json_success( [ 'report' => GrandyStashRepository::reportData( $from, $to ) ] );
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
