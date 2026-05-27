<?php
if (!defined('METIS_ROOT')) exit;

require_once dirname( __DIR__ ) . '/includes/dashboard_data.php';
require_once dirname( __DIR__, 2 ) . '/portal/views/_dashboard_data.php';

use Metis\Modules\Board\PacketRecipientService;
use Metis\Modules\Board\BylawsService;
use Metis\Modules\Board\CalendarLinkService;
use Metis\Modules\Board\DecisionAttendanceService;
use Metis\Modules\Board\DocumentService;
use Metis\Modules\Board\ReadService;
use Metis\Modules\Board\WorkflowTemplateService;

function metis_board_ajax_verify(bool $manage = true): void {
    $allowed = $manage
        ? ( function_exists( 'metis_board_can_manage' ) && metis_board_can_manage() )
        : ( function_exists( 'metis_board_can' ) && metis_board_can( 'view' ) );
    if ( ! $allowed ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
    metis_board_ensure_schema();
}

function metis_board_fetch_meeting_documents(int $meeting_id): array {
    return ReadService::meetingDocuments($meeting_id);
}

function metis_board_fetch_committee_summary(int $committee_id): array {
    if ($committee_id < 1) return [];
    $rows = metis_board_fetch_dashboard_committees(true);
    $row = null;
    foreach ( $rows as $candidate ) {
        if ( (int) ( $candidate['id'] ?? 0 ) === $committee_id ) {
            $row = $candidate;
            break;
        }
    }
    if (!$row) return [];
    return [
        'id' => (int) ($row['id'] ?? 0),
        'committee_code' => (string) ($row['committee_code'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'chair_person_id' => (int) ($row['chair_person_id'] ?? 0),
        'chair_name' => (string) ($row['chair_name'] ?? ''),
        'newsletter_list_id' => (int) ($row['newsletter_list_id'] ?? 0),
        'newsletter_list_name' => (string) ($row['newsletter_list_name'] ?? ''),
        'is_active' => (int) ($row['is_active'] ?? 0),
        'meeting_count' => (int) ($row['meeting_count'] ?? 0),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function metis_board_newsletter_list_exists(int $list_id): bool {
    return PacketRecipientService::newsletterListExists($list_id);
}

function metis_board_collect_newsletter_list_recipients(int $list_id): array {
    return PacketRecipientService::collectNewsletterListRecipients($list_id);
}

function metis_board_resolve_packet_recipients(array $meeting): array {
    $committee = [];
    if ((int) ($meeting['committee_id'] ?? 0) > 0) {
        $committee = metis_board_fetch_committee_summary((int) ($meeting['committee_id'] ?? 0));
    }

    return PacketRecipientService::resolvePacketRecipients($meeting, $committee);
}

function metis_board_fetch_meeting_summary(int $meeting_id): array {
    return ReadService::meetingSummary($meeting_id);
}

function metis_board_fetch_decision_summary(int $decision_id): array {
    return ReadService::decisionSummary($decision_id);
}

function metis_board_fetch_announcement_summary(int $announcement_id): array {
    return ReadService::announcementSummary($announcement_id);
}

function metis_board_fetch_action_item_summary(int $action_item_id): array {
    return ReadService::actionItemSummary($action_item_id);
}

function metis_board_can_resolve_action_item(array $action_item): bool {
    if (function_exists('metis_board_can_manage') && metis_board_can_manage()) {
        return true;
    }

    $current_person_id = function_exists('metis_board_current_person_id')
        ? (int) metis_board_current_person_id()
        : 0;
    if ($current_person_id < 1) {
        return false;
    }

    return $current_person_id === (int) ($action_item['owner_person_id'] ?? 0);
}

function metis_board_fetch_bylaws_summary(int $bylaw_id = 0): array {
    return ReadService::bylawsSummary($bylaw_id);
}

function metis_board_fetch_bylaws_history(int $limit = 20): array {
    return ReadService::bylawsHistory($limit);
}

function metis_board_bylaws_signature_name(): string {
    return ReadService::bylawsSignatureName();
}

function metis_board_render_bylaws_pdf_bytes(array $formatted, array $metadata): array {
    if (!class_exists('Core_PDF_Service')) {
        return ['ok' => false, 'error' => 'PDF service is not available.'];
    }
    $title = metis_escape_html((string) ($metadata['title'] ?? 'Bylaws'));
    $signature = metis_escape_html((string) ($metadata['signature'] ?? ''));
    $approved = metis_escape_html((string) ($metadata['approved_at'] ?? ''));
    $document_hash = metis_escape_html((string) ($metadata['document_hash'] ?? ''));
    $body = (string) ($formatted['html'] ?? '');
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,Arial,sans-serif;color:#111827;font-size:12px;line-height:1.55;}h1,h2,h3,h4{color:#111827;}h1{font-size:22px;margin:0 0 8px;}h2{font-size:18px;}h3{font-size:16px;margin-top:22px;border-top:1px solid #e5e7eb;padding-top:12px;}h4{font-size:14px;margin-top:16px;}p{margin:0 0 10px;}ol,ul{margin:0 0 10px 24px;}li{margin-bottom:6px;}.metis-board-bylaws-document>h2{display:none;}.signature{margin-top:32px;border-top:1px solid #cbd5e1;padding-top:12px;font-size:11px;color:#475569;}</style></head><body><h1>' . $title . '</h1>' . $body . '<div class="signature"><strong>Approved by:</strong> ' . $signature . '<br><strong>Approved at:</strong> ' . $approved . '<br><strong>Document SHA-256:</strong> ' . $document_hash . '</div></body></html>';
    $pdf = new Core_PDF_Service(['defaultFont' => 'DejaVu Sans']);
    return ['ok' => true, 'bytes' => $pdf->render_with_footer($html, 'Metis Board Bylaws', ['paper' => 'letter', 'orientation' => 'portrait'])];
}

function metis_board_store_bylaws_pdf(string $bylaw_code, string $pdf_bytes): string {
    $dir = METIS_ROOT . '/storage/private-records/board/bylaws';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return '';
    }
    $path = $dir . '/' . metis_filename_clean($bylaw_code . '.pdf');
    file_put_contents($path, $pdf_bytes, LOCK_EX);
    return $path;
}

function metis_board_normalize_bylaws_date(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if (!$ts) {
        metis_runtime_send_json_error('Effective date is invalid.', 422);
    }
    return gmdate('Y-m-d', $ts);
}

function metis_board_normalize_bylaws_datetime(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if (!$ts) {
        metis_runtime_send_json_error('Approved date is invalid.', 422);
    }
    return gmdate('Y-m-d H:i:s', $ts);
}

function metis_board_normalize_pdf_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        metis_runtime_send_json_error('Signed PDF link must be a valid URL.', 422);
    }
    return $url;
}

function metis_board_bylaws_audit_context(string $stage, string $document_hash = '', string $pdf_hash = ''): array {
    return [
        'stage' => $stage,
        'person_id' => metis_board_current_person_id(),
        'signature_name' => metis_board_bylaws_signature_name(),
        'ip_address' => function_exists('metis_audit_ip_address') ? metis_audit_ip_address() : '',
        'user_agent' => function_exists('metis_audit_user_agent') ? metis_audit_user_agent() : '',
        'request_id' => function_exists('metis_audit_request_id') ? metis_audit_request_id() : '',
        'document_hash' => $document_hash,
        'pdf_hash' => $pdf_hash,
    ];
}

function metis_board_fetch_bylaws_row(int $bylaw_id): array {
    return ReadService::bylawsRow($bylaw_id);
}

function metis_board_fetch_bylaws_decision(int $decision_id): array {
    return ReadService::bylawsDecision($decision_id);
}

function metis_board_fetch_bylaws_action_item(int $action_item_id): array {
    return ReadService::bylawsActionItem($action_item_id);
}

function metis_board_register_ajax_controllers(): void {
    $actions = [
        'metis_board_save_committee' => 'edit',
        'metis_board_save_meeting' => 'edit',
        'metis_board_save_decision' => 'edit',
        'metis_board_save_action_item' => 'edit',
        'metis_board_resolve_action_item' => 'view',
        'metis_board_save_announcement' => 'edit',
        'metis_board_format_bylaws' => 'edit',
        'metis_board_save_bylaws' => 'edit',
        'metis_board_secretary_certify_bylaws' => 'edit',
        'metis_board_president_approve_bylaws' => 'edit',
        'metis_board_list_bylaws_pdf_options' => 'edit',
        'metis_board_prepare_meeting_workspace' => 'edit',
        'metis_board_generate_packet_pdf' => 'edit',
        'metis_board_get_workflow_templates' => 'view',
        'metis_board_save_agenda_template' => 'edit',
        'metis_board_delete_agenda_template' => 'delete',
        'metis_board_save_decision_template' => 'edit',
        'metis_board_delete_decision_template' => 'delete',
        'metis_board_drive_list' => 'view',
        'metis_board_drive_create_folder' => 'edit',
        'metis_board_drive_upload' => 'edit',
        'metis_board_drive_set_meeting_folder' => 'edit',
        'metis_board_drive_link_document' => 'edit',
        'metis_board_drive_unlink_document' => 'edit',
        'metis_board_drive_delete_file' => 'delete',
        'metis_board_get_meeting_documents' => 'view',
        'metis_board_get_workspace_links_summary' => 'view',
        'metis_board_list_calendar_events' => 'view',
        'metis_board_list_drive_folders' => 'view',
        'metis_board_assign_calendar_event' => 'edit',
        'metis_board_generate_calendar_event' => 'edit',
        'metis_board_sync_decision_points' => 'edit',
        'metis_board_update_meeting_detail' => 'edit',
        'metis_board_resend_packet_email' => 'edit',
        'metis_board_update_decision' => 'edit',
        'metis_board_upsert_attendance' => 'edit',
    ];

    foreach ($actions as $action => $permission) {
        metis_ajax_register_controller($action, [
            'module' => 'board',
            'permission' => $permission,
            'nonce_action' => metis_ajax_nonce_action($action),
        ]);
    }
}

metis_board_register_ajax_controllers();

metis_ajax_register_handler( 'metis_board_save_committee', function () {
    metis_board_ajax_verify(true);
    $db = metis_db();

    $table = Metis_Tables::get('board_committees');
    $committee_id = (int) (metis_request_post()['committee_id'] ?? 0);
    $name = metis_text_clean(metis_runtime_unslash(metis_request_post()['name'] ?? ''));
    $description = metis_runtime_kses_post(metis_runtime_unslash(metis_request_post()['description'] ?? ''));
    $chair_person_id = (int) (metis_request_post()['chair_person_id'] ?? 0);
    $newsletter_list_id = (int) (metis_request_post()['newsletter_list_id'] ?? 0);
    $is_active = (int) (metis_request_post()['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($name === '') metis_runtime_send_json_error('Committee name is required.', 422);
    if ($newsletter_list_id > 0 && !metis_board_newsletter_list_exists($newsletter_list_id)) {
        metis_runtime_send_json_error('Select a valid active newsletter list.', 422);
    }

    $payload = [
        'name' => $name,
        'description' => $description,
        'chair_person_id' => $chair_person_id > 0 ? $chair_person_id : null,
        'newsletter_list_id' => $newsletter_list_id > 0 ? $newsletter_list_id : null,
        'is_active' => $is_active,
    ];

    if ($committee_id > 0) {
        $ok = $db->update($table, $payload, ['id' => $committee_id], ['%s', '%s', '%d', '%d', '%d'], ['%d']);
        if ($ok === false) metis_runtime_send_json_error('Failed to update committee.', 500);
    } else {
        $payload['committee_code'] = metis_board_generate_code('BC', $table, 'committee_code');
        $ok = $db->insert($table, $payload, ['%s', '%s', '%d', '%d', '%d', '%s']);
        if (!$ok) metis_runtime_send_json_error('Failed to create committee.', 500);
        $committee_id = (int) $db->lastInsertId();
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'committee_id' => $committee_id,
        'committee' => metis_board_fetch_committee_summary($committee_id),
    ]);
});

metis_ajax_register_handler( 'metis_board_save_meeting', function () {
    metis_board_ajax_verify(true);
    $db = metis_db();

    $table = Metis_Tables::get('board_meetings');
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    $title = metis_text_clean(metis_runtime_unslash(metis_request_post()['title'] ?? ''));
    $committee_id = (int) (metis_request_post()['committee_id'] ?? 0);
    $meeting_date_raw = metis_text_clean(metis_runtime_unslash(metis_request_post()['meeting_date'] ?? ''));
    $meeting_type = metis_key_clean(metis_runtime_unslash(metis_request_post()['meeting_type'] ?? 'board'));
    $location = metis_text_clean(metis_runtime_unslash(metis_request_post()['location'] ?? ''));
    $status = metis_key_clean(metis_runtime_unslash(metis_request_post()['status'] ?? 'draft'));
    $calendar_event_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['google_calendar_event_id'] ?? ''));
    $drive_folder_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['google_drive_folder_id'] ?? ''));
    $agenda_json_raw = trim((string) metis_runtime_unslash(metis_request_post()['agenda_json'] ?? ''));
    $minutes_html = metis_runtime_kses_post(metis_runtime_unslash(metis_request_post()['minutes_html'] ?? ''));

    if ($title === '') metis_runtime_send_json_error('Meeting title is required.', 422);
    if ($meeting_date_raw === '') metis_runtime_send_json_error('Meeting date is required.', 422);

    $meeting_ts = strtotime($meeting_date_raw);
    if (!$meeting_ts) metis_runtime_send_json_error('Invalid meeting date.', 422);
    $meeting_date = gmdate('Y-m-d H:i:s', $meeting_ts);

    $agenda_json = null;
    if ($agenda_json_raw !== '') {
        json_decode($agenda_json_raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            metis_runtime_send_json_error('Agenda JSON is invalid.', 422);
        }
        $agenda_json = $agenda_json_raw;
    }

    if (!in_array($meeting_type, ['board', 'committee', 'special'], true)) $meeting_type = 'board';
    if (!in_array($status, ['draft', 'scheduled', 'completed', 'cancelled'], true)) $status = 'draft';

    $payload = [
        'title' => $title,
        'committee_id' => $committee_id > 0 ? $committee_id : null,
        'meeting_date' => $meeting_date,
        'meeting_type' => $meeting_type,
        'location' => $location,
        'status' => $status,
        'agenda_json' => $agenda_json,
        'minutes_html' => $minutes_html,
        'google_calendar_event_id' => $calendar_event_id,
        'google_drive_folder_id' => $drive_folder_id,
    ];

    if ($meeting_id > 0) {
        $ok = $db->update(
            $table,
            $payload,
            ['id' => $meeting_id],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        if ($ok === false) metis_runtime_send_json_error('Failed to update meeting.', 500);
    } else {
        if (function_exists('metis_entity_id_service')) {
            $payload = metis_entity_id_service()->assignForInsert('meeting', $payload);
        } else {
            $payload['meeting_code'] = metis_board_generate_code('BM', $table, 'meeting_code');
        }
        $payload['created_by_person_id'] = metis_board_current_person_id();
        $ok = $db->insert(
            $table,
            $payload,
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );
        if (!$ok) metis_runtime_send_json_error('Failed to create meeting.', 500);
        $meeting_id = (int) $db->lastInsertId();
        if ($meeting_id > 0 && function_exists('metis_entity_id_service')) {
            metis_entity_id_service()->register('meeting', $meeting_id, (string) ($payload['meeting_uid'] ?? $payload['meeting_code'] ?? ''));
        }
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'meeting_id' => $meeting_id,
        'meeting' => metis_board_fetch_meeting_summary($meeting_id),
    ]);
});

metis_ajax_register_handler( 'metis_board_save_decision', function () {
    metis_board_ajax_verify(true);
    $db = metis_db();

    $table = Metis_Tables::get('board_decisions');
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    $title = metis_text_clean(metis_runtime_unslash(metis_request_post()['title'] ?? ''));
    $agenda_section_title = metis_text_clean(metis_runtime_unslash(metis_request_post()['agenda_section_title'] ?? ''));
    $agenda_item_title = metis_text_clean(metis_runtime_unslash(metis_request_post()['agenda_item_title'] ?? ''));
    $decision_text = metis_runtime_kses_post(metis_runtime_unslash(metis_request_post()['decision_text'] ?? ''));
    $outcome = metis_key_clean(metis_runtime_unslash(metis_request_post()['outcome'] ?? 'pending'));
    $votes_for = max(0, (int) (metis_request_post()['votes_for'] ?? 0));
    $votes_against = max(0, (int) (metis_request_post()['votes_against'] ?? 0));
    $votes_abstain = max(0, (int) (metis_request_post()['votes_abstain'] ?? 0));

    if ($meeting_id < 1) metis_runtime_send_json_error('Meeting is required.', 422);
    if ($title === '') metis_runtime_send_json_error('Decision title is required.', 422);
    if (!in_array($outcome, ['pending', 'approved', 'rejected', 'tabled'], true)) $outcome = 'pending';

    $passed = $outcome === 'approved' ? 1 : 0;
    $passed_at = $passed ? metis_current_time('mysql') : null;

    $payload = [
        'meeting_id' => $meeting_id,
        'title' => $title,
        'agenda_section_title' => $agenda_section_title !== '' ? $agenda_section_title : null,
        'agenda_item_title' => $agenda_item_title !== '' ? $agenda_item_title : null,
        'agenda_point_hash' => ($agenda_section_title !== '' || $agenda_item_title !== '') ? md5(strtolower($agenda_section_title . '|' . $agenda_item_title . '|' . $title)) : null,
        'decision_text' => $decision_text,
        'outcome' => $outcome,
        'votes_for' => $votes_for,
        'votes_against' => $votes_against,
        'votes_abstain' => $votes_abstain,
        'passed' => $passed,
        'passed_at' => $passed_at,
    ];
    if (function_exists('metis_entity_id_service')) {
        $payload = metis_entity_id_service()->assignForInsert('board_decision_point', $payload);
    } else {
        $payload['decision_code'] = metis_board_generate_code('BD', $table, 'decision_code');
    }

    $ok = $db->insert($table, $payload, ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s']);
    if (!$ok) metis_runtime_send_json_error('Failed to save decision.', 500);
    $decision_id = (int) $db->lastInsertId();
    if (function_exists('metis_entity_id_service')) {
        metis_entity_id_service()->register('board_decision_point', $decision_id, (string) ($payload['board_decision_uid'] ?? $payload['decision_code'] ?? ''));
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'decision_id' => $decision_id,
        'decision' => metis_board_fetch_decision_summary($decision_id),
    ]);
});

metis_ajax_register_handler( 'metis_board_save_action_item', function () {
    metis_board_ajax_verify(true);
    $db = metis_db();

    $table = Metis_Tables::get('board_action_items');
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    $owner_person_id = (int) (metis_request_post()['owner_person_id'] ?? 0);
    $title = metis_text_clean(metis_runtime_unslash(metis_request_post()['title'] ?? ''));
    $description = metis_runtime_kses_post(metis_runtime_unslash(metis_request_post()['description'] ?? ''));
    $due_date = metis_text_clean(metis_runtime_unslash(metis_request_post()['due_date'] ?? ''));
    $priority = metis_key_clean(metis_runtime_unslash(metis_request_post()['priority'] ?? 'normal'));
    $status = metis_key_clean(metis_runtime_unslash(metis_request_post()['status'] ?? 'open'));

    if ($title === '') metis_runtime_send_json_error('Action title is required.', 422);
    if (!in_array($priority, ['low', 'normal', 'high', 'critical'], true)) $priority = 'normal';
    if (!in_array($status, ['open', 'in_progress', 'blocked', 'done'], true)) $status = 'open';

    $due_date_sql = null;
    if ($due_date !== '') {
        $ts = strtotime($due_date);
        if (!$ts) metis_runtime_send_json_error('Invalid due date.', 422);
        $due_date_sql = gmdate('Y-m-d', $ts);
    }

    $payload = [
        'meeting_id' => $meeting_id > 0 ? $meeting_id : null,
        'owner_person_id' => $owner_person_id > 0 ? $owner_person_id : null,
        'title' => $title,
        'description' => $description,
        'due_date' => $due_date_sql,
        'priority' => $priority,
        'status' => $status,
        'completed_at' => $status === 'done' ? metis_current_time('mysql') : null,
    ];
    if (function_exists('metis_entity_id_service')) {
        $payload = metis_entity_id_service()->assignForInsert('board_action_item', $payload);
    } else {
        $payload['action_code'] = metis_board_generate_code('BA', $table, 'action_code');
    }

    $ok = $db->insert($table, $payload, ['%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']);
    if (!$ok) metis_runtime_send_json_error('Failed to save action item.', 500);
    $new_id = (int) $db->lastInsertId();
    if (function_exists('metis_entity_id_service')) {
        metis_entity_id_service()->register('board_action_item', $new_id, (string) ($payload['board_action_uid'] ?? $payload['action_code'] ?? ''));
    }
    $saved = metis_board_fetch_action_item_summary($new_id);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'action_item_id' => $new_id,
        'action_item' => [
            'id' => (int) ($saved['id'] ?? $new_id),
            'meeting_id' => (int) ($saved['meeting_id'] ?? $meeting_id),
            'owner_person_id' => (int) ($saved['owner_person_id'] ?? $owner_person_id),
            'title' => (string) ($saved['title'] ?? $title),
            'owner_name' => (string) ($saved['owner_name'] ?? ''),
            'meeting_code' => (string) ($saved['meeting_code'] ?? ''),
            'due_date' => (string) ($saved['due_date'] ?? ''),
            'status' => (string) ($saved['status'] ?? $status),
        ],
    ]);
});

metis_ajax_register_handler( 'metis_board_resolve_action_item', function () {
    metis_board_ajax_verify(false);
    $db = metis_db();
    $table = Metis_Tables::get('board_action_items');
    $action_item_id = (int) (metis_request_post()['action_item_id'] ?? 0);

    if ($action_item_id < 1) metis_runtime_send_json_error('Action item is required.', 422);

    $current = metis_board_fetch_action_item_summary($action_item_id);
    if (!$current) metis_runtime_send_json_error('Action item not found.', 404);
    if (!metis_board_can_resolve_action_item($current)) {
        metis_runtime_send_json_error('You are not allowed to resolve this action item.', 403);
    }

    $was_open = strtolower((string) ($current['status'] ?? 'open')) !== 'done';
    if ($was_open) {
        $ok = $db->update(
            $table,
            [
                'status' => 'done',
                'completed_at' => metis_current_time('mysql'),
            ],
            [ 'id' => $action_item_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        if (!$ok) metis_runtime_send_json_error('Failed to resolve action item.', 500);
    }

    $saved = metis_board_fetch_action_item_summary($action_item_id);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'action_item_id' => $action_item_id,
        'open_count_delta' => $was_open ? -1 : 0,
        'action_item' => $saved,
    ]);
});

metis_ajax_register_handler( 'metis_board_save_announcement', function () {
    metis_board_ajax_verify(true);
    $db = metis_db();

    $table = Metis_Tables::get('board_announcements');
    $title = metis_text_clean(metis_runtime_unslash(metis_request_post()['title'] ?? ''));
    $body_html = metis_runtime_kses_post(metis_runtime_unslash(metis_request_post()['body_html'] ?? ''));
    $status = metis_key_clean(metis_runtime_unslash(metis_request_post()['status'] ?? 'draft'));
    $publish_at_raw = metis_text_clean(metis_runtime_unslash(metis_request_post()['publish_at'] ?? ''));

    if ($title === '') metis_runtime_send_json_error('Announcement title is required.', 422);
    if (!in_array($status, ['draft', 'published'], true)) $status = 'draft';

    $publish_at = null;
    if ($publish_at_raw !== '') {
        $ts = strtotime($publish_at_raw);
        if (!$ts) metis_runtime_send_json_error('Invalid publish date.', 422);
        $publish_at = gmdate('Y-m-d H:i:s', $ts);
    }

    $payload = [
        'announcement_code' => metis_board_generate_code('BN', $table, 'announcement_code'),
        'title' => $title,
        'body_html' => $body_html,
        'status' => $status,
        'publish_at' => $publish_at,
        'published_by_person_id' => metis_board_current_person_id(),
    ];

    $ok = $db->insert($table, $payload, ['%s', '%s', '%s', '%s', '%s', '%d']);
    if (!$ok) metis_runtime_send_json_error('Failed to save announcement.', 500);

    $announcement_id = (int) $db->lastInsertId();
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'announcement_id' => $announcement_id,
        'announcement' => metis_board_fetch_announcement_summary($announcement_id),
    ]);
});

metis_ajax_register_handler( 'metis_board_format_bylaws', function () {
    metis_board_ajax_verify(true);

    $post = metis_request_post();
    $title = metis_text_clean(metis_runtime_unslash($post['title'] ?? 'Bylaws'));
    $source_text = trim((string) metis_runtime_unslash($post['source_text'] ?? ''));
    if ($source_text === '') {
        metis_runtime_send_json_error('Paste bylaws text before formatting.', 422);
    }
    if (strlen($source_text) > 500000) {
        metis_runtime_send_json_error('Bylaws text is too large to format safely.', 422);
    }

    $formatted = \Metis\Modules\Board\BylawsFormatter::format($source_text, $title !== '' ? $title : 'Bylaws');
    metis_runtime_send_json_success([
        'formatted' => $formatted,
    ]);
});

metis_ajax_register_handler( 'metis_board_save_bylaws', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(BylawsService::saveDraft(metis_request_post()));
});

metis_ajax_register_handler( 'metis_board_secretary_certify_bylaws', function () {
    metis_board_ajax_verify(true);
    $bylaw_id = (int) (metis_request_post()['bylaw_id'] ?? 0);
    metis_runtime_send_json_success(BylawsService::secretaryCertify($bylaw_id));
});

metis_ajax_register_handler( 'metis_board_president_approve_bylaws', function () {
    metis_board_ajax_verify(true);
    $bylaw_id = (int) (metis_request_post()['bylaw_id'] ?? 0);
    metis_runtime_send_json_success(BylawsService::presidentApprove($bylaw_id));
});

metis_ajax_register_handler( 'metis_board_list_bylaws_pdf_options', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(BylawsService::pdfOptions());
});

function metis_board_drive_cfg(): array {
    $cfg = metis_board_workspace_settings();
    if (empty($cfg['ok'])) return $cfg;
    return $cfg;
}

function metis_board_drive_request(string $method, string $path_or_url, ?array $body = null, array $extra_headers = []): array {
    $cfg = metis_board_drive_cfg();
    if (empty($cfg['ok'])) return $cfg;
    $url = str_starts_with($path_or_url, 'http') ? $path_or_url : ('https://www.googleapis.com/drive/v3/' . ltrim($path_or_url, '/'));
    return metis_board_google_request($method, $url, $body, $cfg, $extra_headers);
}

function metis_board_shared_drive_id(): string {
    return \Metis\Modules\Board\WorkspaceService::sharedDriveId();
}

function metis_board_calendar_cfg(): array {
    $cfg = metis_board_workspace_settings();
    if (empty($cfg['ok'])) return $cfg;
    $cfg['scopes'] = [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/drive',
    ];
    return $cfg;
}

function metis_board_calendar_request(string $method, string $path_or_url, ?array $body = null): array {
    $cfg = metis_board_calendar_cfg();
    if (empty($cfg['ok'])) return $cfg;
    $url = str_starts_with($path_or_url, 'http') ? $path_or_url : ('https://www.googleapis.com/calendar/v3/' . ltrim($path_or_url, '/'));
    return metis_board_google_request($method, $url, $body, $cfg);
}

function metis_board_extract_google_id(string $input, string $kind = ''): string {
    $value = trim($input);
    if ($value === '') return '';
    if (!str_contains($value, '://')) return $value;

    $parts = parse_url($value);
    if (!is_array($parts)) return '';
    $path = trim((string) ($parts['path'] ?? ''), '/');
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);

    if ($kind === 'drive_folder') {
        if (preg_match('#/folders/([^/?]+)#', (string) ($parts['path'] ?? ''), $m)) {
            return trim((string) ($m[1] ?? ''));
        }
        if (!empty($query['id'])) {
            return trim((string) $query['id']);
        }
    }

    if ($kind === 'drive_file') {
        if (preg_match('#/file/d/([^/?]+)#', (string) ($parts['path'] ?? ''), $m)) {
            return trim((string) ($m[1] ?? ''));
        }
        if (!empty($query['id'])) {
            return trim((string) $query['id']);
        }
    }

    if ($kind === 'calendar_event' && !empty($query['eid'])) {
        $eid_raw = strtr((string) $query['eid'], '-_', '+/');
        $padded = str_pad($eid_raw, (int) ceil(strlen($eid_raw) / 4) * 4, '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);
        if (is_string($decoded) && $decoded !== '') {
            $segments = preg_split('/\s+/', $decoded) ?: [];
            $candidate = trim((string) ($segments[0] ?? ''));
            if ($candidate !== '') return $candidate;
        }
    }

    if ($kind === 'calendar_event' && preg_match('#/events/([^/?]+)#', (string) ($parts['path'] ?? ''), $m)) {
        return trim((string) ($m[1] ?? ''));
    }

    $path_segments = array_values(array_filter(explode('/', $path), function ($segment) {
        return trim((string) $segment) !== '';
    }));
    if (!empty($path_segments)) {
        return trim((string) end($path_segments));
    }

    return '';
}

function metis_board_table_columns(string $table): array {
    static $cache = [];
    if ($table === '') return [];
    if (isset($cache[$table])) return $cache[$table];
    $db = metis_db();
    $rows = $db->fetchAll("SHOW COLUMNS FROM {$table}") ?: [];
    $cols = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $name = trim((string) ($row['Field'] ?? ''));
        if ($name !== '') $cols[$name] = true;
    }
    $cache[$table] = $cols;
    return $cols;
}

function metis_board_table_has_column(string $table, string $column): bool {
    $column = trim($column);
    if ($table === '' || $column === '') return false;
    $cols = metis_board_table_columns($table);
    return isset($cols[$column]);
}

function metis_board_first_document_uid(array $payload): string {
    foreach (['board_packet_uid', 'board_financial_uid', 'board_minutes_uid'] as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

function metis_board_doc_type_workspace_key(string $doc_type): string {
    $type = metis_key_clean($doc_type);
    if (in_array($type, ['financial_report'], true)) return 'financials';
    if (in_array($type, ['agenda', 'agenda_attachment'], true)) return 'agenda';
    if (in_array($type, ['minutes', 'minutes_attachment'], true)) return 'minutes';
    if (in_array($type, ['board_packet'], true)) return 'packet';
    return 'supporting';
}

function metis_board_meeting_calendar_payload(array $meeting): array {
    $title = trim((string) ($meeting['title'] ?? 'Board Meeting'));
    if ($title === '') {
        $title = trim((string) ($meeting['meeting_code'] ?? 'Board Meeting'));
    }

    $start_ts = strtotime((string) ($meeting['meeting_date'] ?? ''));
    if (!$start_ts) $start_ts = time();
    $end_ts = $start_ts + 3600;
    $tz = 'UTC';
    if (function_exists('metis_runtime_timezone')) {
        $runtime_tz = metis_runtime_timezone();
        if ($runtime_tz instanceof DateTimeZone) {
            $tz = (string) $runtime_tz->getName();
        } else {
            $candidate = trim((string) $runtime_tz);
            if ($candidate !== '') $tz = $candidate;
        }
    }

    return [
        'summary' => $title,
        'location' => trim((string) ($meeting['location'] ?? '')),
        'description' => 'Board meeting ' . trim((string) ($meeting['meeting_code'] ?? '')),
        'start' => [
            'dateTime' => gmdate('c', $start_ts),
            'timeZone' => $tz,
        ],
        'end' => [
            'dateTime' => gmdate('c', $end_ts),
            'timeZone' => $tz,
        ],
    ];
}

function metis_board_fetch_calendar_event_summary(string $event_id): array {
    $id = trim($event_id);
    if ($id === '') return ['ok' => false, 'error' => 'Calendar event is required.'];
    $url = 'calendars/primary/events/' . rawurlencode($id) . '?fields=id,summary,htmlLink,status';
    $resp = metis_board_calendar_request('GET', $url, null);
    if (empty($resp['ok'])) return ['ok' => false, 'error' => 'Failed to fetch calendar event.'];
    $event = (array) ($resp['body'] ?? []);
    return [
        'ok' => true,
        'id' => (string) ($event['id'] ?? $id),
        'name' => trim((string) ($event['summary'] ?? '')) !== '' ? trim((string) $event['summary']) : 'Calendar event',
        'url' => (string) ($event['htmlLink'] ?? ''),
        'status' => (string) ($event['status'] ?? ''),
    ];
}

function metis_board_upsert_calendar_event_for_meeting(array $meeting, bool $create_if_missing = false): array {
    $event_id = trim((string) ($meeting['google_calendar_event_id'] ?? ''));
    $payload = metis_board_meeting_calendar_payload($meeting);

    if ($event_id !== '') {
        $patch_url = 'calendars/primary/events/' . rawurlencode($event_id) . '?conferenceDataVersion=0';
        $patched = metis_board_calendar_request('PATCH', $patch_url, $payload);
        if (!empty($patched['ok'])) {
            $body = (array) ($patched['body'] ?? []);
            return [
                'ok' => true,
                'id' => (string) ($body['id'] ?? $event_id),
                'name' => trim((string) ($body['summary'] ?? '')) !== '' ? trim((string) $body['summary']) : trim((string) ($meeting['title'] ?? 'Board Meeting')),
                'url' => (string) ($body['htmlLink'] ?? ''),
            ];
        }
        if (!$create_if_missing) {
            return ['ok' => false, 'error' => 'Failed to update calendar event.'];
        }
    }

    $created = metis_board_calendar_request('POST', 'calendars/primary/events?conferenceDataVersion=0', $payload);
    if (empty($created['ok'])) {
        return ['ok' => false, 'error' => 'Failed to create calendar event.'];
    }
    $body = (array) ($created['body'] ?? []);
    return [
        'ok' => true,
        'id' => (string) ($body['id'] ?? ''),
        'name' => trim((string) ($body['summary'] ?? '')) !== '' ? trim((string) $body['summary']) : trim((string) ($meeting['title'] ?? 'Board Meeting')),
        'url' => (string) ($body['htmlLink'] ?? ''),
    ];
}

function metis_board_fetch_drive_folder_summary(string $folder_id): array {
    return \Metis\Modules\Board\WorkspaceService::fetchDriveFolderSummary($folder_id);
}

function metis_board_drive_ensure_named_folder(string $name, string $parent_id, string $shared_drive_id): array {
    return \Metis\Modules\Board\WorkspaceService::ensureNamedFolder($name, $parent_id, $shared_drive_id);
}

function metis_board_prepare_workspace_folders(int $meeting_id): array {
    return \Metis\Modules\Board\WorkspaceService::prepareWorkspaceFolders($meeting_id);
}

function metis_board_drive_upload_bytes(string $filename, string $mime, string $bytes, string $folder_id): array {
    $cfg = metis_board_drive_cfg();
    if (empty($cfg['ok'])) return ['ok' => false, 'error' => 'Workspace config missing.'];
    $token = metis_board_google_access_token($cfg);
    if (empty($token['ok'])) return ['ok' => false, 'error' => 'Workspace token error.'];

    $meta = [
        'name' => metis_filename_clean($filename),
        'parents' => [$folder_id],
    ];
    $boundary = 'metis_board_pdf_' . metis_runtime_generate_password(12, false, false);
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= metis_json_encode($meta) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n";
    $body .= $bytes . "\r\n";
    $body .= "--{$boundary}--";

    $upload_url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,mimeType,size,webViewLink';
    $upload_resp = metis_runtime_remote_post($upload_url, [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . (string) $token['access_token'],
            'Content-Type' => 'multipart/related; boundary=' . $boundary,
        ],
        'body' => $body,
    ]);
    if (metis_runtime_is_error($upload_resp)) return ['ok' => false, 'error' => 'Drive upload request failed.'];
    $code = (int) metis_runtime_remote_retrieve_response_code($upload_resp);
    $raw = (string) metis_runtime_remote_retrieve_body($upload_resp);
    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded) || empty($decoded['id'])) {
        return ['ok' => false, 'error' => 'Failed to upload generated PDF.'];
    }
    return ['ok' => true, 'file' => $decoded];
}

function metis_board_drive_copy_file(string $source_file_id, string $new_name, string $target_folder_id): array {
    $cfg = metis_board_drive_cfg();
    if (empty($cfg['ok'])) return ['ok' => false, 'error' => 'Workspace Drive configuration is unavailable.'];

    $source_file_id = trim($source_file_id);
    $target_folder_id = trim($target_folder_id);
    if ($source_file_id === '' || $target_folder_id === '') {
        return ['ok' => false, 'error' => 'Source file and target folder are required.'];
    }

    $payload = ['parents' => [$target_folder_id]];
    if (trim($new_name) !== '') {
        $payload['name'] = metis_filename_clean($new_name);
    }
    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($source_file_id) . '/copy?supportsAllDrives=true&fields=id,name,mimeType,size,webViewLink';
    $resp = metis_board_google_request('POST', $url, $payload, $cfg);
    if (empty($resp['ok'])) {
        return ['ok' => false, 'error' => 'Failed to copy file into packet folder.'];
    }
    return ['ok' => true, 'file' => (array) ($resp['body'] ?? [])];
}

function metis_board_packet_html(array $meeting, array $agenda, array $decisions, array $actions, array $attendance, array $extras = []): string {
    $agenda_only = !empty($extras['agenda_only']);
    $agenda_compact = !empty($extras['agenda_compact']);
    $title = metis_escape_html((string) ($meeting['title'] ?? 'Board Meeting'));
    $meeting_date = metis_escape_html(metis_board_format_datetime((string) ($meeting['meeting_date'] ?? '')));
    $prior_minutes_title = trim((string) ($extras['prior_minutes_title'] ?? ''));
    $prior_minutes_html = (string) ($extras['prior_minutes_html'] ?? '');
    $financial_title = trim((string) ($extras['financial_title'] ?? ''));
    $financial_link = trim((string) ($extras['financial_link'] ?? ''));
    $linked_docs = isset($extras['linked_docs']) && is_array($extras['linked_docs']) ? $extras['linked_docs'] : [];

    $rows = [];
    foreach ($agenda as $idx => $section) {
        $section_name = is_array($section) ? (string) ($section['section_name'] ?? ($section['custom_title'] ?? ($section['section'] ?? ('Section ' . ($idx + 1))))) : ('Section ' . ($idx + 1));
        $items = is_array($section) && !empty($section['items']) && is_array($section['items']) ? $section['items'] : [];
        $decision_points = is_array($section) && isset($section['decision_points']) && is_array($section['decision_points']) ? $section['decision_points'] : [];
        $row = '<section class="block"><h3>' . metis_escape_html($section_name) . '</h3>';

        $point_items = [];
        if (!empty($decision_points)) {
            foreach ($decision_points as $point) {
                if (!is_array($point)) continue;
                $it = trim((string) ($point['item_title'] ?? ''));
                if ($it === '') {
                    $it = trim((string) ($point['agenda_item_title'] ?? ''));
                }
                if ($it === '') {
                    $it = trim((string) ($point['decision_title'] ?? ''));
                }
                if ($it !== '') $point_items[] = $it;
            }
        }
        $merged_items = [];
        $seen_items = [];
        foreach (($agenda_compact ? array_merge($items, $point_items) : $items) as $agenda_item) {
            $label = trim((string) $agenda_item);
            if ($label === '') continue;
            $key = strtolower($label);
            if (isset($seen_items[$key])) continue;
            $seen_items[$key] = true;
            $merged_items[] = $label;
        }

        if (!empty($merged_items)) {
            $row .= '<ul>';
            foreach ($merged_items as $item) $row .= '<li>' . metis_escape_html((string) $item) . '</li>';
            $row .= '</ul>';
        } else {
            $row .= '<p>No agenda items listed.</p>';
        }
        $row .= '</section>';
        $rows[] = $row;
    }

    $linked_doc_rows = '';
    foreach ($linked_docs as $doc) {
        if (!is_array($doc)) continue;
        $doc_title = (string) ($doc['title'] ?? 'Document');
        $doc_type = (string) ($doc['doc_type_label'] ?? ($doc['doc_type'] ?? 'Document'));
        $doc_link = (string) ($doc['google_drive_url'] ?? '');
        $linked_doc_rows .= '<tr><td>' . metis_escape_html($doc_title) . '</td><td>' . metis_escape_html($doc_type) . '</td><td>' . ($doc_link !== '' ? ('<a href="' . metis_escape_url($doc_link) . '" target="_blank" rel="noopener">Open</a>') : '—') . '</td></tr>';
    }

    if ($agenda_only) {
        return '<html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,Arial,sans-serif;color:#1f2937;font-size:12px;line-height:1.45;}
        h1{font-size:22px;margin:0 0 2px;}
        h2{font-size:16px;margin:18px 0 8px;border-bottom:1px solid #e5e7eb;padding-bottom:4px;}
        h3{font-size:13px;margin:10px 0 6px;}
        p{margin:6px 0;}
        ul{margin:0 0 0 18px;padding:0;}
        li{margin:2px 0;}
        .sub{color:#6b7280;}
        .block{margin-bottom:8px;}
    </style></head><body>
        <h1>' . $title . '</h1>
        <p class="sub">' . $meeting_date . '</p>
        <h2>Agenda</h2>
        ' . (!empty($rows) ? implode('', $rows) : '<p>No agenda sections recorded.</p>') . '
    </body></html>';
    }

    return '<html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,Arial,sans-serif;color:#1f2937;font-size:12px;line-height:1.45;}
        h1{font-size:22px;margin:0 0 2px;}
        h2{font-size:16px;margin:18px 0 8px;border-bottom:1px solid #e5e7eb;padding-bottom:4px;}
        h3{font-size:13px;margin:10px 0 6px;}
        p{margin:6px 0;}
        ul{margin:0 0 0 18px;padding:0;}
        li{margin:2px 0;}
        .sub{color:#6b7280;}
        .block{margin-bottom:8px;}
        table{width:100%;border-collapse:collapse;}
        th,td{border:1px solid #e5e7eb;padding:6px;vertical-align:top;}
        th{background:#f8fafc;text-align:left;font-weight:700;}
    </style></head><body>
        <h1>' . $title . '</h1>
        <p class="sub">' . $meeting_date . '</p>
        <h2>Agenda</h2>
        ' . (!empty($rows) ? implode('', $rows) : '<p>No agenda sections recorded.</p>') . '
        ' . ($prior_minutes_html !== '' ? ('<h2>Prior Meeting Minutes</h2><h3>' . metis_escape_html($prior_minutes_title !== '' ? $prior_minutes_title : 'Prior Meeting') . '</h3>' . metis_runtime_kses_post($prior_minutes_html)) : '') . '
        ' . ($financial_title !== '' ? ('<h2>Financial Report</h2><p><strong>Selected file:</strong> ' . metis_escape_html($financial_title) . '</p>' . ($financial_link !== '' ? ('<p><a href="' . metis_escape_url($financial_link) . '" target="_blank" rel="noopener">Open financial document</a></p>') : '')) : '') . '
        <h2>Supporting Documents</h2>
        <table><thead><tr><th>Document</th><th>Type</th><th>Link</th></tr></thead><tbody>' . ($linked_doc_rows !== '' ? $linked_doc_rows : '<tr><td colspan="3">No linked documents.</td></tr>') . '</tbody></table>
    </body></html>';
}

metis_ajax_register_handler( 'metis_board_prepare_meeting_workspace', function () {
    metis_board_ajax_verify(true);
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    if ($meeting_id < 1) {
        metis_runtime_send_json_error('Meeting is required.', 422);
    }
    $prepared = metis_board_prepare_workspace_folders($meeting_id);
    if (empty($prepared['ok'])) {
        metis_runtime_send_json_error('Failed to prepare meeting workspace.', 500);
    }
    metis_runtime_send_json_success($prepared);
});

metis_ajax_register_handler( 'metis_board_generate_packet_pdf', function () {
    metis_board_ajax_verify(true);
    $db = metis_db();

    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    if ($meeting_id < 1) metis_runtime_send_json_error('Meeting is required.', 422);

    $prepared = metis_board_prepare_workspace_folders($meeting_id);
    if (empty($prepared['ok'])) {
        metis_runtime_send_json_error('Failed to prepare meeting folders.', 500);
    }

    $meetings_table = Metis_Tables::get('board_meetings');
    $decisions_table = Metis_Tables::get('board_decisions');
    $actions_table = Metis_Tables::get('board_action_items');
    $attendance_table = Metis_Tables::get('board_attendance');
    $documents_table = Metis_Tables::get('board_documents');
    $people_table = Metis_Tables::get('people');

    $meeting = $db->fetchOne("SELECT * FROM {$meetings_table} WHERE id = %d LIMIT 1", [ $meeting_id ]);
    if (!$meeting) metis_runtime_send_json_error('Meeting not found.', 404);

    $minutes_reference_raw = metis_text_clean(metis_runtime_unslash(metis_request_post()['packet_source_minutes_reference'] ?? ''));
    if ($minutes_reference_raw === '') {
        $minutes_reference_raw = metis_text_clean(metis_runtime_unslash(metis_request_post()['packet_source_minutes_meeting_id'] ?? ''));
    }
    $selected_prior_doc_id = 0;
    $selected_prior_meeting_id = 0;
    if (preg_match('/^doc:(\d+)$/', $minutes_reference_raw, $minutes_match)) {
        $selected_prior_doc_id = (int) ($minutes_match[1] ?? 0);
    } else {
        $selected_prior_meeting_id = (int) ($minutes_reference_raw !== '' ? $minutes_reference_raw : (string) ($meeting['packet_source_minutes_meeting_id'] ?? 0));
    }
    $selected_financial_doc_id = (int) (metis_request_post()['packet_financial_document_id'] ?? ($meeting['packet_financial_document_id'] ?? 0));
    $db->update(
        $meetings_table,
        [
            'packet_source_minutes_meeting_id' => $selected_prior_meeting_id > 0 ? $selected_prior_meeting_id : null,
            'packet_financial_document_id' => $selected_financial_doc_id > 0 ? $selected_financial_doc_id : null,
        ],
        ['id' => $meeting_id],
        ['%d', '%d'],
        ['%d']
    );

    $agenda = json_decode((string) ($meeting['agenda_json'] ?? ''), true);
    if (!is_array($agenda)) $agenda = [];
    $decisions = $db->fetchAll("SELECT * FROM {$decisions_table} WHERE meeting_id = %d ORDER BY id ASC", [ $meeting_id ]) ?: [];
    $actions = $db->fetchAll("SELECT a.*, p.display_name AS owner_name FROM {$actions_table} a LEFT JOIN {$people_table} p ON p.id = a.owner_person_id WHERE a.meeting_id = %d ORDER BY a.id ASC", [ $meeting_id ]) ?: [];
    $attendance = $db->fetchAll("SELECT atn.*, p.display_name FROM {$attendance_table} atn LEFT JOIN {$people_table} p ON p.id = atn.person_id WHERE atn.meeting_id = %d ORDER BY p.display_name ASC", [ $meeting_id ]) ?: [];
    $linked_docs_for_packet = array_values(array_filter(metis_board_fetch_meeting_documents($meeting_id), function ($doc) {
        $type = strtolower((string) ($doc['doc_type'] ?? ''));
        return in_array($type, ['supporting_doc', 'agenda_attachment', 'minutes_attachment', 'policy', 'other'], true);
    }));

    if (!class_exists('Core_PDF_Service')) {
        metis_runtime_send_json_error('PDF service is not available.', 500);
    }

    $extra_packet_data = [];
    $prior = [];
    $prior_doc = [];
    if ($selected_prior_meeting_id > 0) {
        $prior = $db->fetchOne(
            "SELECT id, meeting_code, title, meeting_date, minutes_html
             FROM {$meetings_table}
             WHERE id = %d
             LIMIT 1",
            [ $selected_prior_meeting_id ]
        );
        if ($prior && trim((string) ($prior['minutes_html'] ?? '')) !== '') {
            $extra_packet_data['prior_minutes_title'] = (string) ($prior['meeting_code'] ?? '') . ' · ' . (string) ($prior['title'] ?? 'Prior meeting') . ' · ' . metis_board_format_datetime((string) ($prior['meeting_date'] ?? ''));
            $extra_packet_data['prior_minutes_html'] = (string) ($prior['minutes_html'] ?? '');
        }
    } elseif ($selected_prior_doc_id > 0) {
        $prior_doc = $db->fetchOne(
            "SELECT id, title, google_file_id, google_drive_url, mime_type
             FROM {$documents_table}
             WHERE id = %d
             LIMIT 1",
            [ $selected_prior_doc_id ]
        );
        if ($prior_doc) {
            $prior_title = trim((string) ($prior_doc['title'] ?? 'Legacy Meeting Minutes'));
            $prior_link = trim((string) ($prior_doc['google_drive_url'] ?? ''));
            $extra_packet_data['prior_minutes_title'] = $prior_title !== '' ? $prior_title : 'Legacy Meeting Minutes';
            if ($prior_link !== '') {
                $extra_packet_data['prior_minutes_html'] =
                    '<p><strong>Legacy minutes document:</strong> <a href="' .
                    metis_escape_url($prior_link) .
                    '" target="_blank" rel="noopener">' .
                    metis_escape_html($extra_packet_data['prior_minutes_title']) .
                    '</a></p>';
            } else {
                $extra_packet_data['prior_minutes_html'] = '<p><strong>Legacy minutes document:</strong> ' . metis_escape_html($extra_packet_data['prior_minutes_title']) . '</p>';
            }
        }
    }

    $financial_doc = null;
    if ($selected_financial_doc_id > 0) {
        $financial_doc = $db->fetchOne(
            "SELECT id, title, google_file_id, google_drive_url, mime_type
             FROM {$documents_table}
             WHERE id = %d
             LIMIT 1",
            [ $selected_financial_doc_id ]
        );
        if ($financial_doc) {
            $extra_packet_data['financial_title'] = (string) ($financial_doc['title'] ?? 'Financial Report');
            $extra_packet_data['financial_link'] = (string) ($financial_doc['google_drive_url'] ?? '');
        }
    }

    try {
        $pdf = new Core_PDF_Service(['defaultFont' => 'DejaVu Sans']);
        $meeting_code = (string) ($meeting['meeting_code'] ?? ('meeting-' . $meeting_id));
        $extra_packet_data['linked_docs'] = $linked_docs_for_packet;
        $packet_html = metis_board_packet_html($meeting, $agenda, $decisions, $actions, $attendance, $extra_packet_data);
        $packet_bytes = $pdf->render_with_footer($packet_html, 'Mobilize Waco — Board Packet', ['paper' => 'letter', 'orientation' => 'portrait']);

        $agenda_html = metis_board_packet_html($meeting, $agenda, [], [], [], [
            'agenda_only' => true,
            'agenda_compact' => true,
        ]);
        $agenda_bytes = $pdf->render_with_footer($agenda_html, 'Mobilize Waco — Agenda', ['paper' => 'letter', 'orientation' => 'portrait']);

        $packet_name = metis_filename_clean($meeting_code . '-Packet.pdf');
        $agenda_name = metis_filename_clean($meeting_code . '-Agenda.pdf');
        $minutes_name = metis_filename_clean($meeting_code . '-Prior-Minutes.pdf');

        $packet_upload = metis_board_drive_upload_bytes($packet_name, 'application/pdf', $packet_bytes, (string) $prepared['packet']['folder_id']);
        if (empty($packet_upload['ok'])) metis_runtime_send_json_error('Failed to upload packet PDF.', 500);

        $agenda_upload = metis_board_drive_upload_bytes($agenda_name, 'application/pdf', $agenda_bytes, (string) $prepared['agenda']['folder_id']);
        if (empty($agenda_upload['ok'])) metis_runtime_send_json_error('Failed to upload agenda PDF.', 500);

        $minutes_file = [];
        if ($selected_prior_meeting_id > 0 && !empty($prior['minutes_html'])) {
            $prior_minutes_html_full = '<html><body><h1>' . metis_escape_html((string) ($prior['title'] ?? 'Prior Meeting')) . ' - Minutes</h1><div>' . metis_runtime_kses_post((string) ($prior['minutes_html'] ?? '')) . '</div></body></html>';
            $minutes_bytes = $pdf->render_with_footer($prior_minutes_html_full, 'Mobilize Waco — Prior Meeting Minutes', ['paper' => 'letter', 'orientation' => 'portrait']);
            $minutes_upload = metis_board_drive_upload_bytes($minutes_name, 'application/pdf', $minutes_bytes, (string) $prepared['minutes']['folder_id']);
            if (!empty($minutes_upload['ok']) && !empty($minutes_upload['file']) && is_array($minutes_upload['file'])) {
                $minutes_file = (array) $minutes_upload['file'];
            }
        } elseif ($selected_prior_doc_id > 0 && !empty($prior_doc['google_file_id'])) {
            $minutes_copy_name = metis_filename_clean($meeting_code . '-Prior-Minutes-' . (string) ($prior_doc['title'] ?? 'Minutes'));
            $minutes_copy = metis_board_drive_copy_file((string) $prior_doc['google_file_id'], $minutes_copy_name, (string) $prepared['minutes']['folder_id']);
            if (!empty($minutes_copy['ok']) && !empty($minutes_copy['file']) && is_array($minutes_copy['file'])) {
                $minutes_file = (array) $minutes_copy['file'];
            }
        }

        $financial_copy = [];
        if ($financial_doc && !empty($financial_doc['google_file_id'])) {
            $copy_name = metis_filename_clean($meeting_code . '-Financial-' . (string) ($financial_doc['title'] ?? 'Report'));
            $copy = metis_board_drive_copy_file((string) $financial_doc['google_file_id'], $copy_name, (string) $prepared['financials']['folder_id']);
            if (!empty($copy['ok']) && !empty($copy['file']) && is_array($copy['file'])) {
                $financial_copy = (array) $copy['file'];
                $existing_id = (int) $db->scalar(
                    "SELECT id FROM {$documents_table} WHERE meeting_id = %d AND google_file_id = %s LIMIT 1",
                    [ $meeting_id, (string) ($financial_copy['id'] ?? '') ]
                );
                $payload = [
                    'meeting_id' => $meeting_id,
                    'title' => (string) ($financial_copy['name'] ?? 'Financial Report'),
                    'doc_type' => 'financial_report',
                    'google_file_id' => (string) ($financial_copy['id'] ?? ''),
                    'google_drive_url' => (string) ($financial_copy['webViewLink'] ?? ''),
                    'mime_type' => (string) ($financial_copy['mimeType'] ?? (string) ($financial_doc['mime_type'] ?? 'application/octet-stream')),
                    'file_size' => isset($financial_copy['size']) ? (int) $financial_copy['size'] : 0,
                    'status' => 'active',
                ];
                if ($existing_id > 0) {
                    $db->update($documents_table, $payload, ['id' => $existing_id], ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s'], ['%d']);
                } else {
                    $entity_type = \Metis\Modules\Board\Support::documentEntityType((string) ($payload['doc_type'] ?? ''));
                    if ($entity_type !== '' && function_exists('metis_entity_id_service')) {
                        $payload = metis_entity_id_service()->assignForInsert($entity_type, $payload, false);
                        $db->insert($documents_table, $payload, ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']);
                        metis_entity_id_service()->register($entity_type, (int) $db->lastInsertId(), metis_board_first_document_uid($payload));
                    } else {
                        $payload['document_code'] = metis_board_generate_code('BF', $documents_table, 'document_code');
                        $db->insert($documents_table, $payload, ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
                    }
                }
            }
        }

        $uploads = [
            'board_packet' => (array) ($packet_upload['file'] ?? []),
            'agenda' => (array) ($agenda_upload['file'] ?? []),
            'minutes' => (array) $minutes_file,
        ];
        foreach ($uploads as $doc_type => $file) {
            $file_id = (string) ($file['id'] ?? '');
            if ($file_id === '') continue;
            $existing_id = (int) $db->scalar(
                "SELECT id FROM {$documents_table} WHERE meeting_id = %d AND google_file_id = %s LIMIT 1",
                [ $meeting_id, $file_id ]
            );
            $payload = [
                'meeting_id' => $meeting_id,
                'title' => (string) ($file['name'] ?? ucfirst($doc_type)),
                'doc_type' => $doc_type,
                'google_file_id' => $file_id,
                'google_drive_url' => (string) ($file['webViewLink'] ?? ''),
                'mime_type' => (string) ($file['mimeType'] ?? 'application/pdf'),
                'file_size' => isset($file['size']) ? (int) $file['size'] : 0,
                'status' => 'active',
            ];
            if ($existing_id > 0) {
                $db->update($documents_table, $payload, ['id' => $existing_id], ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s'], ['%d']);
            } else {
                $entity_type = \Metis\Modules\Board\Support::documentEntityType((string) ($payload['doc_type'] ?? ''));
                if ($entity_type !== '' && function_exists('metis_entity_id_service')) {
                    $payload = metis_entity_id_service()->assignForInsert($entity_type, $payload, false);
                    $db->insert($documents_table, $payload, ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']);
                    metis_entity_id_service()->register($entity_type, (int) $db->lastInsertId(), metis_board_first_document_uid($payload));
                } else {
                    $payload['document_code'] = metis_board_generate_code('BF', $documents_table, 'document_code');
                    $db->insert($documents_table, $payload, ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
                }
            }
        }
    } catch (Throwable $e) {
        if (class_exists('Metis_Logger')) {
            Metis_Logger::warn('board.packet_generation_failed', [
                'meeting_id' => $meeting_id,
                'error' => $e->getMessage(),
            ]);
        }
        metis_runtime_send_json_error('Packet generation failed. Please try again.', 500);
    }

    metis_runtime_send_json_success([
        'meeting_id' => $meeting_id,
        'folders' => $prepared,
        'uploads' => [
            'packet' => $packet_upload['file'] ?? [],
            'agenda' => $agenda_upload['file'] ?? [],
            'minutes' => $minutes_file,
            'financial' => $financial_copy,
        ],
    ]);
});

metis_ajax_register_handler( 'metis_board_get_workflow_templates', function () {
    metis_board_ajax_verify(false);
    metis_runtime_send_json_success(WorkflowTemplateService::listTemplates());
});

metis_ajax_register_handler( 'metis_board_save_agenda_template', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(WorkflowTemplateService::saveAgendaTemplate(metis_request_post()));
});

metis_ajax_register_handler( 'metis_board_delete_agenda_template', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(WorkflowTemplateService::deactivateAgendaTemplate((int) (metis_request_post()['template_id'] ?? 0)));
});

metis_ajax_register_handler( 'metis_board_save_decision_template', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(WorkflowTemplateService::saveDecisionTemplate(metis_request_post()));
});

metis_ajax_register_handler( 'metis_board_delete_decision_template', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(WorkflowTemplateService::deactivateDecisionTemplate((int) (metis_request_post()['template_id'] ?? 0)));
});

metis_ajax_register_handler( 'metis_board_drive_list', function () {
    metis_board_ajax_verify(false);
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    $folder_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_id'] ?? ''));
    $search = metis_text_clean(metis_runtime_unslash(metis_request_post()['search'] ?? ''));
    metis_runtime_send_json_success(\Metis\Modules\Board\WorkspaceService::listDriveFiles($meeting_id, $folder_id, $search));
});

metis_ajax_register_handler( 'metis_board_drive_create_folder', function () {
    metis_board_ajax_verify(true);
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    $parent_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['parent_id'] ?? 'root'));
    $folder_name = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_name'] ?? ''));
    $set_as_meeting = (int) (metis_request_post()['set_as_meeting'] ?? 0) === 1;
    metis_runtime_send_json_success(\Metis\Modules\Board\WorkspaceService::createDriveFolder($meeting_id, $parent_id, $folder_name, $set_as_meeting));
});

metis_ajax_register_handler( 'metis_board_drive_upload', function () {
    metis_board_ajax_verify(true);
    $db = metis_db();

    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    $folder_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_id'] ?? ''));
    $doc_type = metis_key_clean(metis_runtime_unslash(metis_request_post()['doc_type'] ?? 'board_packet'));
    $item_title = metis_text_clean(metis_runtime_unslash(metis_request_post()['item_title'] ?? ''));
    if ($meeting_id > 0) {
        $workspace = metis_board_prepare_workspace_folders($meeting_id);
        if (!empty($workspace['ok'])) {
            $bucket = metis_board_doc_type_workspace_key($doc_type);
            $target = (array) ($workspace[$bucket] ?? []);
            $target_id = trim((string) ($target['folder_id'] ?? ''));
            if ($target_id !== '') $folder_id = $target_id;
        }
    }
    if ($folder_id === '') $folder_id = 'root';
    if (empty(metis_request_files()['file']) || !is_array(metis_request_files()['file'])) {
        metis_runtime_send_json_error('Upload file is required.', 422);
    }
    $file = metis_request_files()['file'];
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = metis_filename_clean((string) ($file['name'] ?? ''));
    $size = (int) ($file['size'] ?? 0);
    $max_bytes = 25 * 1024 * 1024;
    if ($tmp === '' || !file_exists($tmp) || $name === '' || !is_uploaded_file($tmp)) {
        metis_runtime_send_json_error('Invalid upload payload.', 422);
    }
    if ($size < 1 || $size > $max_bytes) {
        metis_runtime_send_json_error('Uploaded file exceeds the 25MB limit.', 413);
    }
    $bytes = file_get_contents($tmp);
    if ($bytes === false) metis_runtime_send_json_error('Failed to read uploaded file.', 500);
    $mime = function_exists('mime_content_type') ? (string) mime_content_type($tmp) : '';
    if ($mime === '') $mime = (string) ($file['type'] ?? 'application/octet-stream');

    $meta = [
        'name' => $name,
        'parents' => [$folder_id],
    ];
    $boundary = 'metis_' . metis_runtime_generate_password(12, false, false);
    $multipart_body = "--{$boundary}\r\n";
    $multipart_body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $multipart_body .= metis_json_encode($meta) . "\r\n";
    $multipart_body .= "--{$boundary}\r\n";
    $multipart_body .= "Content-Type: {$mime}\r\n\r\n";
    $multipart_body .= $bytes . "\r\n";
    $multipart_body .= "--{$boundary}--";

    $cfg = metis_board_drive_cfg();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);
    $token = metis_board_google_access_token($cfg);
    if (empty($token['ok'])) metis_runtime_send_json_error('Workspace token error.', 500);

    $upload_url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,mimeType,size,webViewLink';
    $upload_resp = metis_runtime_remote_post($upload_url, [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . (string) $token['access_token'],
            'Content-Type' => 'multipart/related; boundary=' . $boundary,
        ],
        'body' => $multipart_body,
    ]);
    if (metis_runtime_is_error($upload_resp)) metis_runtime_send_json_error('Drive upload request failed.', 500);
    $code = (int) metis_runtime_remote_retrieve_response_code($upload_resp);
    $raw = (string) metis_runtime_remote_retrieve_body($upload_resp);
    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded) || empty($decoded['id'])) {
        metis_runtime_send_json_error('Failed to upload file to Drive.', 500);
    }

    if ($meeting_id > 0) {
        $document_id = DocumentService::upsertMeetingDocument([
            'meeting_id' => $meeting_id,
            'title' => $item_title !== '' ? $item_title : (string) ($decoded['name'] ?? $name),
            'doc_type' => $doc_type !== '' ? $doc_type : 'board_packet',
            'google_file_id' => (string) ($decoded['id'] ?? ''),
            'google_drive_url' => (string) ($decoded['webViewLink'] ?? ''),
            'mime_type' => (string) ($decoded['mimeType'] ?? $mime),
            'file_size' => isset($decoded['size']) ? (int) $decoded['size'] : (int) filesize($tmp),
            'status' => 'active',
        ]);
    }

    metis_runtime_send_json_success([
        'file' => [
            'id' => (string) ($decoded['id'] ?? ''),
            'name' => (string) ($decoded['name'] ?? $name),
            'mimeType' => (string) ($decoded['mimeType'] ?? $mime),
            'isFolder' => false,
            'webViewLink' => (string) ($decoded['webViewLink'] ?? ''),
            'size' => (string) ($decoded['size'] ?? ''),
            'modifiedTime' => '',
        ],
        'documents' => $meeting_id > 0 ? metis_board_fetch_meeting_documents($meeting_id) : [],
    ]);
});

metis_ajax_register_handler( 'metis_board_drive_set_meeting_folder', function () {
    metis_board_ajax_verify(true);
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    $folder_input = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_id'] ?? ''));
    $folder_name_input = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_name'] ?? ''));
    $folder_id = metis_board_extract_google_id($folder_input, 'drive_folder');
    metis_runtime_send_json_success(\Metis\Modules\Board\WorkspaceService::assignMeetingFolder($meeting_id, $folder_id, $folder_name_input));
});

metis_ajax_register_handler( 'metis_board_drive_link_document', function () {
    metis_board_ajax_verify(true);
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    $file_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['file_id'] ?? ''));
    $title = metis_text_clean(metis_runtime_unslash(metis_request_post()['title'] ?? ''));
    $doc_type = metis_key_clean(metis_runtime_unslash(metis_request_post()['doc_type'] ?? 'board_packet'));
    $mime_type = metis_text_clean(metis_runtime_unslash(metis_request_post()['mime_type'] ?? ''));
    $web_view_link = metis_escape_url((string) metis_runtime_unslash(metis_request_post()['web_view_link'] ?? ''));
    $size = (int) (metis_request_post()['size'] ?? 0);
    if ($meeting_id < 1 || $file_id === '') metis_runtime_send_json_error('Meeting and file are required.', 422);
    if ($title === '') $title = 'Drive file';
    if ($doc_type === '') $doc_type = 'board_packet';

    $document_id = DocumentService::upsertMeetingDocument([
        'meeting_id' => $meeting_id,
        'title' => $title,
        'doc_type' => $doc_type,
        'google_file_id' => $file_id,
        'google_drive_url' => $web_view_link,
        'mime_type' => $mime_type,
        'file_size' => $size > 0 ? $size : null,
        'status' => 'active',
    ]);
    metis_runtime_send_json_success([
        'document_id' => $document_id,
        'documents' => metis_board_fetch_meeting_documents($meeting_id),
    ]);
});

metis_ajax_register_handler( 'metis_board_drive_unlink_document', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(DocumentService::unlinkDocument((int) (metis_request_post()['document_id'] ?? 0)));
});

metis_ajax_register_handler( 'metis_board_drive_delete_file', function () {
    metis_board_ajax_verify(true);
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    $file_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['file_id'] ?? ''));
    if ($file_id === '') metis_runtime_send_json_error('File is required.', 422);

    $url = metis_add_query_arg(['supportsAllDrives' => 'true'], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id));
    $resp = metis_board_drive_request('DELETE', $url, null);
    if (empty($resp['ok'])) {
        metis_runtime_send_json_error('Failed to delete file from Drive.', 500);
    }

    metis_runtime_send_json_success(DocumentService::deleteMeetingDocumentByFileId($meeting_id, $file_id));
});

metis_ajax_register_handler( 'metis_board_get_meeting_documents', function () {
    metis_board_ajax_verify(false);
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    if ($meeting_id < 1) metis_runtime_send_json_error('Meeting is required.', 422);
    metis_runtime_send_json_success([
        'meeting_id' => $meeting_id,
        'documents' => metis_board_fetch_meeting_documents($meeting_id),
    ]);
});

metis_ajax_register_handler( 'metis_board_get_workspace_links_summary', function () {
    metis_board_ajax_verify(false);
    metis_runtime_send_json_success(CalendarLinkService::workspaceLinksSummary((int) (metis_request_post()['meeting_id'] ?? 0)));
});

metis_ajax_register_handler( 'metis_board_list_calendar_events', function () {
    metis_board_ajax_verify(false);
    metis_runtime_send_json_success(CalendarLinkService::listCalendarEvents((int) (metis_request_post()['meeting_id'] ?? 0)));
});

metis_ajax_register_handler( 'metis_board_list_drive_folders', function () {
    metis_board_ajax_verify(false);
    metis_runtime_send_json_success(CalendarLinkService::listDriveFolders((int) (metis_request_post()['meeting_id'] ?? 0)));
});

metis_ajax_register_handler( 'metis_board_assign_calendar_event', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(CalendarLinkService::assignCalendarEvent(
        (int) (metis_request_post()['meeting_id'] ?? 0),
        metis_text_clean(metis_runtime_unslash(metis_request_post()['event_id'] ?? '')),
        metis_text_clean(metis_runtime_unslash(metis_request_post()['event_name'] ?? ''))
    ));
});

metis_ajax_register_handler( 'metis_board_generate_calendar_event', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(CalendarLinkService::generateCalendarEvent((int) (metis_request_post()['meeting_id'] ?? 0)));
});

metis_ajax_register_handler( 'metis_board_sync_decision_points', function () {
    metis_board_ajax_verify(true);
    $db = metis_db();
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    if ($meeting_id < 1) metis_runtime_send_json_error('Meeting is required.', 422);

    $agenda = null;
    if (array_key_exists('agenda_json', metis_request_post())) {
        $agenda_json_raw = trim((string) metis_runtime_unslash(metis_request_post()['agenda_json'] ?? ''));
        if ($agenda_json_raw !== '') {
            $decoded = json_decode($agenda_json_raw, true);
            if (!is_array($decoded)) metis_runtime_send_json_error('Agenda JSON is invalid.', 422);
            $agenda = $decoded;
        } else {
            $agenda = [];
        }
    } else {
        $meetings_table = Metis_Tables::get('board_meetings');
        $agenda_json_raw = (string) $db->scalar(
            "SELECT agenda_json FROM {$meetings_table} WHERE id = %d LIMIT 1",
            [ $meeting_id ]
        );
        $decoded = json_decode($agenda_json_raw, true);
        $agenda = is_array($decoded) ? $decoded : [];
    }

    $created = function_exists('metis_board_sync_decision_points') ? metis_board_sync_decision_points($meeting_id, $agenda) : 0;
    metis_runtime_send_json_success(['meeting_id' => $meeting_id, 'created_decisions' => $created]);
});

function metis_board_portal_logo_url(): string {
    if (function_exists('metis_portal_logo_url')) {
        $url = trim((string) metis_portal_logo_url());
        if ($url !== '') return metis_escape_url($url);
    }
    if (!class_exists('Core_Settings_Service')) return '';
    $logo = Core_Settings_Service::get('portal_logo', []);
    if (is_string($logo)) {
        return metis_escape_url(trim($logo));
    }
    if (!is_array($logo)) return '';
    foreach (['url', 'src', 'public_url'] as $key) {
        $value = trim((string) ($logo[$key] ?? ''));
        if ($value !== '') return metis_escape_url($value);
    }
    return '';
}

function metis_board_email_inline_logo_from_data_uri(string $logo_url): array {
    $logo_url = trim($logo_url);
    if ($logo_url === '' || stripos($logo_url, 'data:') !== 0) {
        return [];
    }
    if (!preg_match('/^data:([a-z0-9.+\\/-]+);base64,(.*)$/i', $logo_url, $m)) {
        return [];
    }
    $mime = strtolower(trim((string) ($m[1] ?? '')));
    $payload = (string) ($m[2] ?? '');
    if ($mime === '' || $payload === '') {
        return [];
    }
    $bytes = base64_decode($payload, true);
    if (!is_string($bytes) || $bytes === '') {
        return [];
    }
    $ext = 'bin';
    if ($mime === 'image/png') $ext = 'png';
    elseif ($mime === 'image/jpeg') $ext = 'jpg';
    elseif ($mime === 'image/gif') $ext = 'gif';
    elseif ($mime === 'image/webp') $ext = 'webp';
    elseif ($mime === 'image/svg+xml') $ext = 'svg';

    return [
        'cid' => 'metis-board-logo',
        'mime' => $mime,
        'name' => 'logo.' . $ext,
        'data' => $bytes,
    ];
}

function metis_board_drive_download_file_payload(string $file_id): array {
    $file_id = trim($file_id);
    if ($file_id === '') return ['ok' => false, 'error' => 'Drive file ID is required.'];
    $cfg = metis_board_drive_cfg();
    if (empty($cfg['ok'])) return ['ok' => false, 'error' => 'Workspace Drive configuration is unavailable.'];
    $token = metis_board_google_access_token($cfg);
    if (empty($token['ok'])) return ['ok' => false, 'error' => 'Workspace token error.'];

    $filename = 'board-packet.pdf';
    $mime = 'application/pdf';
    $meta = metis_board_drive_request('GET', 'files/' . rawurlencode($file_id) . '?fields=id,name,mimeType');
    if (!empty($meta['ok'])) {
        $meta_body = (array) ($meta['body'] ?? []);
        $filename = trim((string) ($meta_body['name'] ?? $filename));
        $mime = trim((string) ($meta_body['mimeType'] ?? $mime));
    }
    if ($filename === '') $filename = 'board-packet.pdf';
    if ($mime === '') $mime = 'application/pdf';

    $download_url = metis_add_query_arg([
        'alt' => 'media',
        'supportsAllDrives' => 'true',
    ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id));
    $resp = metis_runtime_remote_get($download_url, [
        'timeout' => 45,
        'headers' => [
            'Authorization' => 'Bearer ' . (string) ($token['access_token'] ?? ''),
        ],
    ]);
    if (metis_runtime_is_error($resp)) {
        return ['ok' => false, 'error' => 'Failed to download Drive file.'];
    }
    $code = (int) metis_runtime_remote_retrieve_response_code($resp);
    $bytes = (string) metis_runtime_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300 || $bytes === '') {
        return ['ok' => false, 'error' => 'Downloaded Drive file is empty or inaccessible.'];
    }

    $tmp_dir = rtrim((string) sys_get_temp_dir(), '/\\');
    $tmp = $tmp_dir . '/metis-board-packet-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $file_id) . '-' . uniqid('', true) . '.pdf';
    if (@file_put_contents($tmp, $bytes) === false) {
        return ['ok' => false, 'error' => 'Failed to stage packet attachment.'];
    }

    return [
        'ok' => true,
        'path' => $tmp,
        'name' => $filename,
        'mime' => $mime,
    ];
}

function metis_board_send_packet_publish_email(int $meeting_id): array {
    if ($meeting_id < 1) return ['ok' => false, 'error' => 'Meeting is required.'];
    if (!class_exists('\Metis\Core\Services\EmailService')) {
        return ['ok' => false, 'error' => 'Email delivery service is unavailable.'];
    }

    $db = metis_db();
    $meetings_table = Metis_Tables::get('board_meetings');
    $documents_table = Metis_Tables::get('board_documents');
    $people_table = Metis_Tables::get('people');

    $committees_table = Metis_Tables::get('board_committees');
    $meeting = $db->fetchOne(
        "SELECT m.id, m.title, m.meeting_code, m.meeting_date, m.location, m.board_packet_notes, m.meeting_type, m.committee_id,
                c.name AS committee_name
         FROM {$meetings_table} m
         LEFT JOIN {$committees_table} c ON c.id = m.committee_id
         WHERE m.id = %d
         LIMIT 1",
        [ $meeting_id ]
    );
    if (!$meeting) return ['ok' => false, 'error' => 'Meeting not found.'];

    $president = $db->fetchOne(
        "SELECT id, email, display_name, first_name, last_name
         FROM {$people_table}
         WHERE is_board = 1
           AND status = 'active'
           AND email <> ''
           AND LOWER(TRIM(COALESCE(board_position, ''))) IN ('president', 'board president')
         ORDER BY id ASC
         LIMIT 1"
    );
    $packet_doc = $db->fetchOne(
        "SELECT id, title, google_file_id, google_drive_url
         FROM {$documents_table}
         WHERE meeting_id = %d
           AND doc_type = 'board_packet'
           AND google_file_id IS NOT NULL
           AND google_file_id <> ''
         ORDER BY updated_at DESC, id DESC
         LIMIT 1",
        [ $meeting_id ]
    );
    if (!$packet_doc) {
        return ['ok' => false, 'error' => 'Board packet document is not available yet.'];
    }

    $recipient_context = metis_board_resolve_packet_recipients($meeting);
    if (empty($recipient_context['ok'])) {
        return ['ok' => false, 'error' => (string) ($recipient_context['error'] ?? 'No packet recipients found.')];
    }
    $recipients = (array) ($recipient_context['recipients'] ?? []);
    $audience_label = (string) ($recipient_context['audience_label'] ?? 'recipients');
    $packet_heading = (string) ($recipient_context['packet_heading'] ?? 'Meeting Packet');

    $download = metis_board_drive_download_file_payload((string) ($packet_doc['google_file_id'] ?? ''));
    if (empty($download['ok'])) {
        return ['ok' => false, 'error' => (string) ($download['error'] ?? 'Unable to attach board packet.')];
    }

    $meeting_title = trim((string) ($meeting['title'] ?? 'Board Meeting'));
    if ($meeting_title === '') $meeting_title = 'Board Meeting';
    $meeting_date = metis_board_format_datetime((string) ($meeting['meeting_date'] ?? ''));
    $meeting_code = trim((string) ($meeting['meeting_code'] ?? ''));
    $packet_notes = trim((string) ($meeting['board_packet_notes'] ?? ''));
    $meeting_location = trim((string) ($meeting['location'] ?? ''));
    $logo_url = metis_board_portal_logo_url();

    $settings_from_name = class_exists('Core_Settings_Service') ? trim((string) Core_Settings_Service::get('newsletter_default_from_name', '')) : '';
    $settings_from_email = class_exists('Core_Settings_Service') ? strtolower(trim((string) Core_Settings_Service::get('newsletter_default_from_email', ''))) : '';
    $settings_reply_to = class_exists('Core_Settings_Service') ? strtolower(trim((string) Core_Settings_Service::get('newsletter_default_reply_to', ''))) : '';
    $org_name = class_exists('Core_Settings_Service') ? trim((string) Core_Settings_Service::get('org_name', '')) : '';

    $president_email = strtolower(trim((string) ($president['email'] ?? '')));
    $from_email = metis_email_is_valid($settings_from_email) ? $settings_from_email : $president_email;
    if (!metis_email_is_valid($from_email)) {
        return ['ok' => false, 'error' => 'A default sender email or Board President email is required before sending packet emails.'];
    }
    $reply_to = metis_email_is_valid($settings_reply_to) ? $settings_reply_to : $from_email;

    $from_name = $settings_from_name;
    if ($from_name === '') {
        $from_name = trim((string) ($president['display_name'] ?? ''));
    }
    if ($from_name === '') {
        $from_name = trim((string) ($president['first_name'] ?? '') . ' ' . (string) ($president['last_name'] ?? ''));
    }
    if ($from_name === '' && $org_name !== '') {
        $from_name = $org_name;
    }
    if ($from_name === '') $from_name = 'Board Office';
    $subject = $packet_heading . ': ' . $meeting_title . ($meeting_date !== '' ? (' - ' . $meeting_date) : '');

    $sent = 0;
    $failed = [];
    foreach ($recipients as $recipient) {
        $greeting_name = trim((string) ($recipient['first_name'] ?? ''));
        if ($greeting_name === '') {
            $greeting_name = trim((string) ($recipient['display_name'] ?? ''));
        }
        if ($greeting_name === '') $greeting_name = $audience_label === 'committee members' ? 'Committee Member' : 'Board Member';
        $safe_notes = $packet_notes !== '' ? metis_runtime_kses_post($packet_notes) : '<p>No additional packet notes were provided.</p>';
        // Normalize common non-breaking-space/mojibake artifacts that Apple Mail can render as stray "Â".
        $safe_notes = str_replace(["\xC2\xA0", '&nbsp;', '&#160;'], ' ', $safe_notes);
        $safe_notes = preg_replace('/Â(?=\s|<|$)/u', '', (string) $safe_notes) ?? (string) $safe_notes;
        $safe_title = metis_escape_html($meeting_title);
        $safe_date = metis_escape_html($meeting_date);
        $safe_code = metis_escape_html($meeting_code);
        $safe_location = metis_escape_html($meeting_location);
        $safe_greeting = metis_escape_html($greeting_name);
        $safe_sender = metis_escape_html($from_name);
        $inline_images = [];
        $logo_src = trim($logo_url);
        if (stripos($logo_src, 'data:') === 0) {
            $inline_logo = metis_board_email_inline_logo_from_data_uri($logo_src);
            if (!empty($inline_logo)) {
                $inline_images[] = $inline_logo;
                $logo_src = 'cid:' . (string) ($inline_logo['cid'] ?? 'metis-board-logo');
            } else {
                $logo_src = '';
            }
        }
        $logo_markup = '';
        if ($logo_src !== '') {
            $logo_markup =
                '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">'
                . '<tr><td align="center" style="text-align:center;">'
                . '<img src="' . metis_escape_attr($logo_src) . '" alt="Organization logo" width="120" style="display:block;border:0;outline:none;text-decoration:none;max-width:120px;max-height:64px;height:auto;width:auto;">'
                . '</td></tr>'
                . '<tr><td height="20" style="line-height:20px;font-size:20px;">&nbsp;</td></tr>'
                . '</table>';
        }
        $location_markup = $safe_location !== '' ? '<p style="margin:0 0 16px;color:#334155;"><strong>Location:</strong> ' . $safe_location . '</p>' : '';
        $html = '<div style="font-family:Arial,sans-serif;background:#f5f7fb;padding:20px;">'
            . '<div style="max-width:700px;margin:0 auto;background:#fff;border:1px solid #dfe5f2;border-radius:12px;padding:24px;">'
            . $logo_markup
            . '<h2 style="margin:0 0 8px;color:#1f2937;">' . metis_escape_html($packet_heading) . '</h2>'
            . '<p style="margin:0 0 14px;color:#334155;">' . $safe_title . '</p>'
            . '<p style="margin:0 0 16px;color:#334155;"><strong>Date:</strong> ' . $safe_date . ($safe_code !== '' ? (' &nbsp;|&nbsp; <strong>Code:</strong> ' . $safe_code) : '') . '</p>'
            . $location_markup
            . '<p style="margin:0 0 14px;color:#1f2937;">Hi ' . $safe_greeting . ',</p>'
            . '<div style="margin:0 0 16px;color:#1f2937;line-height:1.55;">' . $safe_notes . '</div>'
            . '<p style="margin:18px 0 0;color:#334155;">Thank you,<br><strong>' . $safe_sender . '</strong></p>'
            . '</div></div>';

        $result = \Metis\Core\Services\EmailService::sendHtml(
            (string) $recipient['email'],
            $subject,
            $html,
            [
                'from_name' => $from_name,
                'from_email' => $from_email,
                'reply_to' => $reply_to,
                'inline_images' => $inline_images,
                'attachments' => [
                    [
                        'path' => (string) ($download['path'] ?? ''),
                        'name' => (string) ($download['name'] ?? 'Board-Packet.pdf'),
                        'mime' => (string) ($download['mime'] ?? 'application/pdf'),
                    ],
                ],
            ]
        );
        if (!empty($result['ok'])) {
            $sent++;
        } else {
            $failed[] = [
                'email' => (string) ($recipient['email'] ?? ''),
                'error' => (string) ($result['error'] ?? 'Email send failed.'),
            ];
        }
    }

    $tmp = (string) ($download['path'] ?? '');
    if ($tmp !== '' && file_exists($tmp)) {
        @unlink($tmp);
    }

    return [
        'ok' => $sent > 0,
        'total' => count($recipients),
        'sent' => $sent,
        'failed' => $failed,
        'audience_label' => $audience_label,
        'packet_heading' => $packet_heading,
    ];
}

metis_ajax_register_handler( 'metis_board_resend_packet_email', function () {
    metis_board_ajax_verify(true);
    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    if ($meeting_id < 1) {
        metis_runtime_send_json_error('Meeting is required.', 422);
    }
    $result = metis_board_send_packet_publish_email($meeting_id);
    if (empty($result['ok'])) {
        metis_runtime_send_json_error((string) ($result['error'] ?? 'Failed to send packet email.'), 500);
    }
    metis_runtime_send_json_success([
        'meeting_id' => $meeting_id,
        'publish_email' => $result,
    ]);
});

metis_ajax_register_handler( 'metis_board_update_meeting_detail', function () {
    metis_board_ajax_verify(true);
    $db = metis_db();

    $meeting_id = (int) (metis_request_post()['meeting_id'] ?? 0);
    if ($meeting_id < 1) {
        metis_runtime_send_json_error('Meeting is required.', 422);
    }

    $table = Metis_Tables::get('board_meetings');
    $existing_meeting = $db->fetchOne("SELECT id, published_at FROM {$table} WHERE id = %d LIMIT 1", [ $meeting_id ]);
    if (!$existing_meeting) {
        metis_runtime_send_json_error('Meeting not found.', 404);
    }
    $was_published = trim((string) ($existing_meeting['published_at'] ?? '')) !== '';
    $payload = [];
    $formats = [];
    $setup_changed = false;
    $send_publish_email = false;

    if (array_key_exists('title', metis_request_post())) {
        $title = metis_text_clean(metis_runtime_unslash(metis_request_post()['title'] ?? ''));
        if ($title === '') {
            metis_runtime_send_json_error('Meeting title is required.', 422);
        }
        $payload['title'] = $title;
        $formats[] = '%s';
        $setup_changed = true;
    }

    if (array_key_exists('meeting_date', metis_request_post())) {
        $meeting_date_raw = metis_text_clean(metis_runtime_unslash(metis_request_post()['meeting_date'] ?? ''));
        if ($meeting_date_raw === '') {
            metis_runtime_send_json_error('Meeting date is required.', 422);
        }
        $meeting_ts = strtotime($meeting_date_raw);
        if (!$meeting_ts) {
            metis_runtime_send_json_error('Invalid meeting date.', 422);
        }
        $payload['meeting_date'] = gmdate('Y-m-d H:i:s', $meeting_ts);
        $formats[] = '%s';
        $setup_changed = true;
    }

    if (array_key_exists('meeting_type', metis_request_post())) {
        $meeting_type = metis_key_clean(metis_runtime_unslash(metis_request_post()['meeting_type'] ?? 'board'));
        if (!in_array($meeting_type, ['board', 'committee', 'special'], true)) {
            $meeting_type = 'board';
        }
        $payload['meeting_type'] = $meeting_type;
        $formats[] = '%s';
        $setup_changed = true;
    }

    if (array_key_exists('location', metis_request_post())) {
        $payload['location'] = metis_text_clean(metis_runtime_unslash(metis_request_post()['location'] ?? ''));
        $formats[] = '%s';
        $setup_changed = true;
    }

    if (array_key_exists('status', metis_request_post())) {
        $status = metis_key_clean(metis_runtime_unslash(metis_request_post()['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'scheduled', 'completed', 'cancelled'], true)) {
            $status = 'draft';
        }
        $payload['status'] = $status;
        $formats[] = '%s';
        $setup_changed = true;
    }

    if (array_key_exists('agenda_json', metis_request_post())) {
        $agenda_json_raw = trim((string) metis_runtime_unslash(metis_request_post()['agenda_json'] ?? ''));
        if ($agenda_json_raw === '') {
            $payload['agenda_json'] = null;
            $formats[] = '%s';
        } else {
            json_decode($agenda_json_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                metis_runtime_send_json_error('Agenda JSON is invalid.', 422);
            }
            $payload['agenda_json'] = $agenda_json_raw;
            $formats[] = '%s';
        }
    }

    if (array_key_exists('minutes_html', metis_request_post())) {
        $payload['minutes_html'] = metis_runtime_kses_post(metis_runtime_unslash(metis_request_post()['minutes_html'] ?? ''));
        $formats[] = '%s';
    }

    if (array_key_exists('board_packet_notes', metis_request_post())) {
        $payload['board_packet_notes'] = metis_runtime_kses_post(metis_runtime_unslash(metis_request_post()['board_packet_notes'] ?? ''));
        $formats[] = '%s';
    }

    if (array_key_exists('packet_source_minutes_meeting_id', metis_request_post())) {
        $val = (int) (metis_request_post()['packet_source_minutes_meeting_id'] ?? 0);
        $payload['packet_source_minutes_meeting_id'] = $val > 0 ? $val : null;
        $formats[] = '%d';
    }

    if (array_key_exists('packet_financial_document_id', metis_request_post())) {
        $val = (int) (metis_request_post()['packet_financial_document_id'] ?? 0);
        $payload['packet_financial_document_id'] = $val > 0 ? $val : null;
        $formats[] = '%d';
    }

    if (array_key_exists('packet_published', metis_request_post())) {
        $packet_published = (int) (metis_request_post()['packet_published'] ?? 0) === 1;
        $payload['published_at'] = $packet_published ? metis_current_time('mysql') : null;
        $formats[] = '%s';
        if ($packet_published && !$was_published) {
            $send_publish_email = true;
        }
    }

    if (array_key_exists('attendance_locked', metis_request_post())) {
        $payload['attendance_locked'] = (int) (metis_request_post()['attendance_locked'] ?? 0) === 1 ? 1 : 0;
        $formats[] = '%d';
    }

    if (empty($payload)) {
        metis_runtime_send_json_error('No meeting fields to update.', 422);
    }

    $ok = $db->update($table, $payload, ['id' => $meeting_id], $formats, ['%d']);
    if ($ok === false) {
        metis_runtime_send_json_error('Failed to update meeting detail.', 500);
    }

    $calendar_sync = null;
    $publish_email = null;
    if ($setup_changed) {
        $sync_calendar = !array_key_exists('sync_calendar_event', metis_request_post()) || (int) (metis_request_post()['sync_calendar_event'] ?? 1) === 1;
        if ($sync_calendar) {
            $meeting_row = $db->fetchOne(
                "SELECT id, meeting_code, title, meeting_date, location, status, google_calendar_event_id
                 FROM {$table}
                 WHERE id = %d
                 LIMIT 1",
                [ $meeting_id ]
            );
            if ($meeting_row && trim((string) ($meeting_row['google_calendar_event_id'] ?? '')) !== '') {
                $calendar = metis_board_upsert_calendar_event_for_meeting($meeting_row, false);
                if (!empty($calendar['ok']) && trim((string) ($calendar['id'] ?? '')) !== '') {
                    $calendar_sync = [
                        'ok' => true,
                        'id' => (string) $calendar['id'],
                        'name' => (string) ($calendar['name'] ?? 'Linked calendar event'),
                        'url' => (string) ($calendar['url'] ?? ''),
                    ];
                    $db->update(
                        $table,
                        [
                            'google_calendar_event_name' => (string) ($calendar['name'] ?? 'Linked calendar event'),
                            'google_calendar_html_link' => (string) ($calendar['url'] ?? ''),
                        ],
                        ['id' => $meeting_id],
                        ['%s', '%s'],
                        ['%d']
                    );
                } else {
                    $calendar_sync = [
                        'ok' => false,
                        'error' => (string) ($calendar['error'] ?? 'Failed to update linked calendar event.'),
                    ];
                }
            }
        }
    }

    if ($send_publish_email) {
        $publish_email = metis_board_send_packet_publish_email($meeting_id);
    }

    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'meeting_id' => $meeting_id,
        'payload' => $payload,
        'created_decisions' => 0,
        'calendar_sync' => $calendar_sync,
        'publish_email' => $publish_email,
    ]);
});

metis_ajax_register_handler( 'metis_board_update_decision', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(DecisionAttendanceService::updateDecision(
        (int) (metis_request_post()['decision_id'] ?? 0),
        metis_request_post()
    ));
});

metis_ajax_register_handler( 'metis_board_upsert_attendance', function () {
    metis_board_ajax_verify(true);
    metis_runtime_send_json_success(DecisionAttendanceService::upsertAttendance(
        (int) (metis_request_post()['meeting_id'] ?? 0),
        (int) (metis_request_post()['person_id'] ?? 0),
        metis_request_post()
    ));
});
