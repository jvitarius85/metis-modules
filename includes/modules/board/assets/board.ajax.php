<?php
if (!defined('ABSPATH')) exit;

function metis_board_ajax_verify(bool $manage = true): void {
    check_ajax_referer('metis_board', 'nonce');
    if (!metis_board_can_view()) {
        metis_send_json_error('Unauthorized', 403);
    }
    if ($manage && !metis_board_can_manage()) {
        metis_send_json_error('Unauthorized', 403);
    }
    metis_board_ensure_schema();
}

function metis_board_fetch_meeting_documents(int $meeting_id): array {
    global $wpdb;
    if ($meeting_id < 1) return [];
    $documents_table = Metis_Tables::get('board_documents');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, title, doc_type, google_file_id, google_drive_url, mime_type, file_size, updated_at
         FROM {$documents_table}
         WHERE meeting_id = %d
         ORDER BY updated_at DESC, id DESC",
        $meeting_id
    ), ARRAY_A) ?: [];
    $docs = [];
    foreach ($rows as $row) {
        $doc_type = (string) ($row['doc_type'] ?? '');
        $docs[] = [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'doc_type' => $doc_type,
            'doc_type_label' => function_exists('metis_board_doc_type_label') ? metis_board_doc_type_label($doc_type) : $doc_type,
            'google_file_id' => (string) ($row['google_file_id'] ?? ''),
            'google_drive_url' => (string) ($row['google_drive_url'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'file_size' => (int) ($row['file_size'] ?? 0),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
    return $docs;
}

metis_add_action('wp_ajax_metis_board_save_committee', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $table = Metis_Tables::get('board_committees');
    $committee_id = (int) ($_POST['committee_id'] ?? 0);
    $name = sanitize_text_field(metis_unslash($_POST['name'] ?? ''));
    $description = metis_kses_post(metis_unslash($_POST['description'] ?? ''));
    $chair_person_id = (int) ($_POST['chair_person_id'] ?? 0);
    $is_active = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

    if ($name === '') metis_send_json_error('Committee name is required.', 422);

    $payload = [
        'name' => $name,
        'description' => $description,
        'chair_person_id' => $chair_person_id > 0 ? $chair_person_id : null,
        'is_active' => $is_active,
    ];

    if ($committee_id > 0) {
        $ok = $wpdb->update($table, $payload, ['id' => $committee_id], ['%s', '%s', '%d', '%d'], ['%d']);
        if ($ok === false) metis_send_json_error('Failed to update committee.', 500);
    } else {
        $payload['committee_code'] = metis_board_generate_code('BC', $table, 'committee_code');
        $ok = $wpdb->insert($table, $payload, ['%s', '%s', '%d', '%d', '%s']);
        if (!$ok) metis_send_json_error('Failed to create committee.', 500);
        $committee_id = (int) $wpdb->insert_id;
    }

    metis_send_json_success(['committee_id' => $committee_id]);
});

metis_add_action('wp_ajax_metis_board_save_meeting', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $table = Metis_Tables::get('board_meetings');
    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    $title = sanitize_text_field(metis_unslash($_POST['title'] ?? ''));
    $committee_id = (int) ($_POST['committee_id'] ?? 0);
    $meeting_date_raw = sanitize_text_field(metis_unslash($_POST['meeting_date'] ?? ''));
    $meeting_type = sanitize_key(metis_unslash($_POST['meeting_type'] ?? 'board'));
    $location = sanitize_text_field(metis_unslash($_POST['location'] ?? ''));
    $status = sanitize_key(metis_unslash($_POST['status'] ?? 'draft'));
    $calendar_event_id = sanitize_text_field(metis_unslash($_POST['google_calendar_event_id'] ?? ''));
    $drive_folder_id = sanitize_text_field(metis_unslash($_POST['google_drive_folder_id'] ?? ''));
    $agenda_json_raw = trim((string) metis_unslash($_POST['agenda_json'] ?? ''));
    $minutes_html = metis_kses_post(metis_unslash($_POST['minutes_html'] ?? ''));

    if ($title === '') metis_send_json_error('Meeting title is required.', 422);
    if ($meeting_date_raw === '') metis_send_json_error('Meeting date is required.', 422);

    $meeting_ts = strtotime($meeting_date_raw);
    if (!$meeting_ts) metis_send_json_error('Invalid meeting date.', 422);
    $meeting_date = gmdate('Y-m-d H:i:s', $meeting_ts);

    $agenda_json = null;
    if ($agenda_json_raw !== '') {
        json_decode($agenda_json_raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            metis_send_json_error('Agenda JSON is invalid.', 422);
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
        $ok = $wpdb->update(
            $table,
            $payload,
            ['id' => $meeting_id],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        if ($ok === false) metis_send_json_error('Failed to update meeting.', 500);
    } else {
        $payload['meeting_code'] = metis_board_generate_code('BM', $table, 'meeting_code');
        $payload['created_by_person_id'] = metis_board_current_person_id();
        $ok = $wpdb->insert(
            $table,
            $payload,
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );
        if (!$ok) metis_send_json_error('Failed to create meeting.', 500);
        $meeting_id = (int) $wpdb->insert_id;
    }

    metis_send_json_success(['meeting_id' => $meeting_id]);
});

metis_add_action('wp_ajax_metis_board_save_decision', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $table = Metis_Tables::get('board_decisions');
    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    $title = sanitize_text_field(metis_unslash($_POST['title'] ?? ''));
    $agenda_section_title = sanitize_text_field(metis_unslash($_POST['agenda_section_title'] ?? ''));
    $agenda_item_title = sanitize_text_field(metis_unslash($_POST['agenda_item_title'] ?? ''));
    $decision_text = metis_kses_post(metis_unslash($_POST['decision_text'] ?? ''));
    $outcome = sanitize_key(metis_unslash($_POST['outcome'] ?? 'pending'));
    $votes_for = max(0, (int) ($_POST['votes_for'] ?? 0));
    $votes_against = max(0, (int) ($_POST['votes_against'] ?? 0));
    $votes_abstain = max(0, (int) ($_POST['votes_abstain'] ?? 0));

    if ($meeting_id < 1) metis_send_json_error('Meeting is required.', 422);
    if ($title === '') metis_send_json_error('Decision title is required.', 422);
    if (!in_array($outcome, ['pending', 'approved', 'rejected', 'tabled'], true)) $outcome = 'pending';

    $passed = $outcome === 'approved' ? 1 : 0;
    $passed_at = $passed ? current_time('mysql') : null;

    $payload = [
        'decision_code' => metis_board_generate_code('BD', $table, 'decision_code'),
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

    $ok = $wpdb->insert($table, $payload, ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s']);
    if (!$ok) metis_send_json_error('Failed to save decision.', 500);

    metis_send_json_success(['decision_id' => (int) $wpdb->insert_id]);
});

metis_add_action('wp_ajax_metis_board_save_action_item', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $table = Metis_Tables::get('board_action_items');
    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    $owner_person_id = (int) ($_POST['owner_person_id'] ?? 0);
    $title = sanitize_text_field(metis_unslash($_POST['title'] ?? ''));
    $description = metis_kses_post(metis_unslash($_POST['description'] ?? ''));
    $due_date = sanitize_text_field(metis_unslash($_POST['due_date'] ?? ''));
    $priority = sanitize_key(metis_unslash($_POST['priority'] ?? 'normal'));
    $status = sanitize_key(metis_unslash($_POST['status'] ?? 'open'));

    if ($title === '') metis_send_json_error('Action title is required.', 422);
    if (!in_array($priority, ['low', 'normal', 'high', 'critical'], true)) $priority = 'normal';
    if (!in_array($status, ['open', 'in_progress', 'blocked', 'done'], true)) $status = 'open';

    $due_date_sql = null;
    if ($due_date !== '') {
        $ts = strtotime($due_date);
        if (!$ts) metis_send_json_error('Invalid due date.', 422);
        $due_date_sql = gmdate('Y-m-d', $ts);
    }

    $payload = [
        'action_code' => metis_board_generate_code('BA', $table, 'action_code'),
        'meeting_id' => $meeting_id > 0 ? $meeting_id : null,
        'owner_person_id' => $owner_person_id > 0 ? $owner_person_id : null,
        'title' => $title,
        'description' => $description,
        'due_date' => $due_date_sql,
        'priority' => $priority,
        'status' => $status,
        'completed_at' => $status === 'done' ? current_time('mysql') : null,
    ];

    $ok = $wpdb->insert($table, $payload, ['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']);
    if (!$ok) metis_send_json_error('Failed to save action item.', 500);

    metis_send_json_success(['action_item_id' => (int) $wpdb->insert_id]);
});

metis_add_action('wp_ajax_metis_board_save_announcement', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $table = Metis_Tables::get('board_announcements');
    $title = sanitize_text_field(metis_unslash($_POST['title'] ?? ''));
    $body_html = metis_kses_post(metis_unslash($_POST['body_html'] ?? ''));
    $status = sanitize_key(metis_unslash($_POST['status'] ?? 'draft'));
    $publish_at_raw = sanitize_text_field(metis_unslash($_POST['publish_at'] ?? ''));

    if ($title === '') metis_send_json_error('Announcement title is required.', 422);
    if (!in_array($status, ['draft', 'published'], true)) $status = 'draft';

    $publish_at = null;
    if ($publish_at_raw !== '') {
        $ts = strtotime($publish_at_raw);
        if (!$ts) metis_send_json_error('Invalid publish date.', 422);
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

    $ok = $wpdb->insert($table, $payload, ['%s', '%s', '%s', '%s', '%s', '%d']);
    if (!$ok) metis_send_json_error('Failed to save announcement.', 500);

    metis_send_json_success(['announcement_id' => (int) $wpdb->insert_id]);
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
    return trim((string) Core_Settings_Service::get('workspace_shared_drive_id', ''));
}

function metis_board_drive_ensure_named_folder(string $name, string $parent_id, string $shared_drive_id): array {
    $name = trim($name);
    if ($name === '' || $parent_id === '' || $shared_drive_id === '') {
        return ['ok' => false, 'error' => 'Folder name, parent, and drive are required.'];
    }

    $q = "trashed = false and mimeType = 'application/vnd.google-apps.folder' and '" . str_replace("'", "\\'", $parent_id) . "' in parents and name = '" . str_replace("'", "\\'", $name) . "'";
    $lookup_url = add_query_arg([
        'q' => $q,
        'corpora' => 'drive',
        'driveId' => $shared_drive_id,
        'includeItemsFromAllDrives' => 'true',
        'supportsAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'pageSize' => 1,
        'fields' => 'files(id,name,webViewLink)',
    ], 'https://www.googleapis.com/drive/v3/files');
    $lookup = metis_board_drive_request('GET', $lookup_url, null);
    if (empty($lookup['ok'])) {
        return ['ok' => false, 'error' => (string) ($lookup['error'] ?? 'Failed to find folder.')];
    }
    $match = (array) (($lookup['body']['files'][0] ?? []));
    if (!empty($match['id'])) {
        $folder_id = (string) $match['id'];
        return [
            'ok' => true,
            'folder_id' => $folder_id,
            'folder_url' => 'https://drive.google.com/drive/folders/' . rawurlencode($folder_id),
            'created' => false,
        ];
    }

    $create_payload = [
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parent_id],
        'driveId' => $shared_drive_id,
    ];
    $create_url = add_query_arg(['supportsAllDrives' => 'true'], 'https://www.googleapis.com/drive/v3/files');
    $created = metis_board_drive_request('POST', $create_url, $create_payload);
    if (empty($created['ok']) || empty($created['body']['id'])) {
        return ['ok' => false, 'error' => (string) ($created['error'] ?? 'Failed to create folder.')];
    }
    $folder_id = (string) $created['body']['id'];
    return [
        'ok' => true,
        'folder_id' => $folder_id,
        'folder_url' => 'https://drive.google.com/drive/folders/' . rawurlencode($folder_id),
        'created' => true,
    ];
}

function metis_board_prepare_workspace_folders(int $meeting_id): array {
    global $wpdb;
    $meetings_table = Metis_Tables::get('board_meetings');
    $meeting = $wpdb->get_row($wpdb->prepare("SELECT id, meeting_code, title, meeting_date FROM {$meetings_table} WHERE id = %d LIMIT 1", $meeting_id), ARRAY_A);
    if (!$meeting) {
        return ['ok' => false, 'error' => 'Meeting not found.'];
    }

    $shared_drive_id = metis_board_shared_drive_id();
    if ($shared_drive_id === '') {
        return ['ok' => false, 'error' => 'Shared Drive ID is not configured in Settings.'];
    }

    $meeting_ts = strtotime((string) ($meeting['meeting_date'] ?? ''));
    if (!$meeting_ts) {
        return ['ok' => false, 'error' => 'Meeting date is invalid.'];
    }
    $year = metis_date('Y', $meeting_ts, metis_timezone());
    $month = metis_date('Y-m', $meeting_ts, metis_timezone());

    $root = metis_board_drive_ensure_named_folder('01 Board Meetings', $shared_drive_id, $shared_drive_id);
    if (empty($root['ok'])) return $root;
    $year_folder = metis_board_drive_ensure_named_folder($year, (string) $root['folder_id'], $shared_drive_id);
    if (empty($year_folder['ok'])) return $year_folder;
    $month_folder = metis_board_drive_ensure_named_folder($month, (string) $year_folder['folder_id'], $shared_drive_id);
    if (empty($month_folder['ok'])) return $month_folder;
    $agenda_folder = metis_board_drive_ensure_named_folder('Agenda', (string) $month_folder['folder_id'], $shared_drive_id);
    if (empty($agenda_folder['ok'])) return $agenda_folder;
    $packet_folder = metis_board_drive_ensure_named_folder('Packet', (string) $month_folder['folder_id'], $shared_drive_id);
    if (empty($packet_folder['ok'])) return $packet_folder;
    $minutes_folder = metis_board_drive_ensure_named_folder('Minutes', (string) $month_folder['folder_id'], $shared_drive_id);
    if (empty($minutes_folder['ok'])) return $minutes_folder;
    $supporting_folder = metis_board_drive_ensure_named_folder('Supporting Docs', (string) $month_folder['folder_id'], $shared_drive_id);
    if (empty($supporting_folder['ok'])) return $supporting_folder;

    $wpdb->update(
        $meetings_table,
        [
            'google_drive_folder_id' => (string) $month_folder['folder_id'],
            'google_drive_folder_url' => (string) $month_folder['folder_url'],
        ],
        ['id' => $meeting_id],
        ['%s', '%s'],
        ['%d']
    );

    return [
        'ok' => true,
        'meeting' => $meeting,
        'root' => $root,
        'year' => $year_folder,
        'month' => $month_folder,
        'agenda' => $agenda_folder,
        'packet' => $packet_folder,
        'minutes' => $minutes_folder,
        'supporting' => $supporting_folder,
    ];
}

function metis_board_drive_upload_bytes(string $filename, string $mime, string $bytes, string $folder_id): array {
    $cfg = metis_board_drive_cfg();
    if (empty($cfg['ok'])) return ['ok' => false, 'error' => (string) ($cfg['error'] ?? 'Workspace config missing.')];
    $token = metis_board_google_access_token($cfg);
    if (empty($token['ok'])) return ['ok' => false, 'error' => (string) ($token['error'] ?? 'Workspace token error.')];

    $meta = [
        'name' => sanitize_file_name($filename),
        'parents' => [$folder_id],
    ];
    $boundary = 'metis_board_pdf_' . metis_generate_password(12, false, false);
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= metis_json_encode($meta) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n";
    $body .= $bytes . "\r\n";
    $body .= "--{$boundary}--";

    $upload_url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,mimeType,size,webViewLink';
    $upload_resp = metis_remote_post($upload_url, [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . (string) $token['access_token'],
            'Content-Type' => 'multipart/related; boundary=' . $boundary,
        ],
        'body' => $body,
    ]);
    if (metis_is_error($upload_resp)) return ['ok' => false, 'error' => $upload_resp->get_error_message()];
    $code = (int) metis_remote_retrieve_response_code($upload_resp);
    $raw = (string) metis_remote_retrieve_body($upload_resp);
    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded) || empty($decoded['id'])) {
        $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
        if ($msg === '') $msg = 'Failed to upload generated PDF.';
        return ['ok' => false, 'error' => $msg];
    }
    return ['ok' => true, 'file' => $decoded];
}

function metis_board_drive_copy_file(string $source_file_id, string $new_name, string $target_folder_id): array {
    $cfg = metis_board_drive_cfg();
    if (empty($cfg['ok'])) return ['ok' => false, 'error' => (string) ($cfg['error'] ?? 'Workspace config missing.')];

    $source_file_id = trim($source_file_id);
    $target_folder_id = trim($target_folder_id);
    if ($source_file_id === '' || $target_folder_id === '') {
        return ['ok' => false, 'error' => 'Source file and target folder are required.'];
    }

    $payload = ['parents' => [$target_folder_id]];
    if (trim($new_name) !== '') {
        $payload['name'] = sanitize_file_name($new_name);
    }
    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($source_file_id) . '/copy?supportsAllDrives=true&fields=id,name,mimeType,size,webViewLink';
    $resp = metis_board_google_request('POST', $url, $payload, $cfg);
    if (empty($resp['ok'])) {
        return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Failed to copy file into packet folder.')];
    }
    return ['ok' => true, 'file' => (array) ($resp['body'] ?? [])];
}

function metis_board_packet_html(array $meeting, array $agenda, array $decisions, array $actions, array $attendance, array $extras = []): string {
    $title = esc_html((string) ($meeting['title'] ?? 'Board Meeting'));
    $meeting_date = esc_html(metis_board_format_datetime((string) ($meeting['meeting_date'] ?? '')));
    $minutes_html = (string) ($meeting['minutes_html'] ?? '');
    $prior_minutes_title = trim((string) ($extras['prior_minutes_title'] ?? ''));
    $prior_minutes_html = (string) ($extras['prior_minutes_html'] ?? '');
    $financial_title = trim((string) ($extras['financial_title'] ?? ''));
    $financial_link = trim((string) ($extras['financial_link'] ?? ''));
    $linked_docs = isset($extras['linked_docs']) && is_array($extras['linked_docs']) ? $extras['linked_docs'] : [];
    $rows = [];
    foreach ($agenda as $idx => $section) {
        $section_name = is_array($section) ? (string) ($section['section_name'] ?? ($section['custom_title'] ?? ($section['section'] ?? ('Section ' . ($idx + 1))))) : ('Section ' . ($idx + 1));
        $items = is_array($section) && !empty($section['items']) && is_array($section['items']) ? $section['items'] : [];
        $decision_label = is_array($section) ? (string) ($section['decision_title'] ?? '') : '';
        $decision_points = is_array($section) && isset($section['decision_points']) && is_array($section['decision_points']) ? $section['decision_points'] : [];
        $notes = is_array($section) ? (string) ($section['notes'] ?? '') : '';
        $row = '<section class="block"><h3>' . esc_html($section_name) . '</h3>';
        if (!empty($items)) {
            $row .= '<ul>';
            foreach ($items as $item) $row .= '<li>' . esc_html((string) $item) . '</li>';
            $row .= '</ul>';
        }
        $decision_lines = [];
        if (!empty($decision_points)) {
            foreach ($decision_points as $point) {
                if (!is_array($point)) continue;
                $point_title = trim((string) ($point['decision_title'] ?? ''));
                if ($point_title !== '') $decision_lines[] = $point_title;
            }
        }
        if (!empty($decision_lines)) {
            $row .= '<p><strong>Decision points:</strong></p><ul>';
            foreach ($decision_lines as $line) $row .= '<li>' . esc_html($line) . '</li>';
            $row .= '</ul>';
        } elseif ($decision_label !== '') {
            $row .= '<p><strong>Decision point:</strong> ' . esc_html($decision_label) . '</p>';
        }
        if ($notes !== '') $row .= '<p><em>' . esc_html($notes) . '</em></p>';
        $row .= '</section>';
        $rows[] = $row;
    }

    $decision_rows = '';
    foreach ($decisions as $d) {
        $decision_rows .= '<tr><td>' . esc_html((string) ($d['title'] ?? '')) . '</td><td>' . esc_html((string) ($d['outcome'] ?? 'pending')) . '</td><td>' . (int) ($d['votes_for'] ?? 0) . '</td><td>' . (int) ($d['votes_against'] ?? 0) . '</td><td>' . (int) ($d['votes_abstain'] ?? 0) . '</td></tr>';
    }

    $action_rows = '';
    foreach ($actions as $a) {
        $action_rows .= '<tr><td>' . esc_html((string) ($a['title'] ?? '')) . '</td><td>' . esc_html((string) ($a['owner_name'] ?? 'Unassigned')) . '</td><td>' . esc_html((string) ($a['status'] ?? 'open')) . '</td><td>' . esc_html((string) ($a['due_date'] ?? '—')) . '</td></tr>';
    }

    $attendance_rows = '';
    foreach ($attendance as $at) {
        $attendance_rows .= '<tr><td>' . esc_html((string) ($at['display_name'] ?? '')) . '</td><td>' . esc_html((string) ($at['role_label'] ?? '')) . '</td><td>' . esc_html((string) ($at['status'] ?? 'absent')) . '</td><td>' . esc_html((string) ($at['notes'] ?? '')) . '</td></tr>';
    }

    $linked_doc_rows = '';
    foreach ($linked_docs as $doc) {
        if (!is_array($doc)) continue;
        $doc_title = (string) ($doc['title'] ?? 'Document');
        $doc_type = (string) ($doc['doc_type_label'] ?? ($doc['doc_type'] ?? 'Document'));
        $doc_link = (string) ($doc['google_drive_url'] ?? '');
        $linked_doc_rows .= '<tr><td>' . esc_html($doc_title) . '</td><td>' . esc_html($doc_type) . '</td><td>' . ($doc_link !== '' ? ('<a href="' . esc_url($doc_link) . '" target="_blank" rel="noopener">Open</a>') : '—') . '</td></tr>';
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
        <h2>Minutes</h2>
        ' . ($minutes_html !== '' ? metis_kses_post($minutes_html) : '<p>No minutes recorded.</p>') . '
        ' . ($prior_minutes_html !== '' ? ('<h2>Prior Meeting Minutes</h2><h3>' . esc_html($prior_minutes_title !== '' ? $prior_minutes_title : 'Prior Meeting') . '</h3>' . metis_kses_post($prior_minutes_html)) : '') . '
        ' . ($financial_title !== '' ? ('<h2>Financial Report</h2><p><strong>Selected file:</strong> ' . esc_html($financial_title) . '</p>' . ($financial_link !== '' ? ('<p><a href="' . esc_url($financial_link) . '" target="_blank" rel="noopener">Open financial document</a></p>') : '')) : '') . '
        <h2>Decisions</h2>
        <table><thead><tr><th>Decision</th><th>Outcome</th><th>For</th><th>Against</th><th>Abstain</th></tr></thead><tbody>' . ($decision_rows !== '' ? $decision_rows : '<tr><td colspan="5">No decisions recorded.</td></tr>') . '</tbody></table>
        <h2>Action Items</h2>
        <table><thead><tr><th>Action</th><th>Owner</th><th>Status</th><th>Due</th></tr></thead><tbody>' . ($action_rows !== '' ? $action_rows : '<tr><td colspan="4">No action items recorded.</td></tr>') . '</tbody></table>
        <h2>Attendance</h2>
        <table><thead><tr><th>Member</th><th>Role</th><th>Status</th><th>Notes</th></tr></thead><tbody>' . ($attendance_rows !== '' ? $attendance_rows : '<tr><td colspan="4">No attendance recorded.</td></tr>') . '</tbody></table>
        <h2>Linked Documents</h2>
        <table><thead><tr><th>Document</th><th>Type</th><th>Link</th></tr></thead><tbody>' . ($linked_doc_rows !== '' ? $linked_doc_rows : '<tr><td colspan="3">No linked documents.</td></tr>') . '</tbody></table>
    </body></html>';
}

metis_add_action('wp_ajax_metis_board_prepare_meeting_workspace', function () {
    metis_board_ajax_verify(true);
    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    if ($meeting_id < 1) {
        metis_send_json_error('Meeting is required.', 422);
    }
    $prepared = metis_board_prepare_workspace_folders($meeting_id);
    if (empty($prepared['ok'])) {
        metis_send_json_error((string) ($prepared['error'] ?? 'Failed to prepare meeting workspace.'), 500);
    }
    metis_send_json_success($prepared);
});

metis_add_action('wp_ajax_metis_board_generate_packet_pdf', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    if ($meeting_id < 1) metis_send_json_error('Meeting is required.', 422);

    $prepared = metis_board_prepare_workspace_folders($meeting_id);
    if (empty($prepared['ok'])) {
        metis_send_json_error((string) ($prepared['error'] ?? 'Failed to prepare meeting folders.'), 500);
    }

    $meetings_table = Metis_Tables::get('board_meetings');
    $decisions_table = Metis_Tables::get('board_decisions');
    $actions_table = Metis_Tables::get('board_action_items');
    $attendance_table = Metis_Tables::get('board_attendance');
    $documents_table = Metis_Tables::get('board_documents');
    $people_table = Metis_Tables::get('people');

    $meeting = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$meetings_table} WHERE id = %d LIMIT 1", $meeting_id), ARRAY_A);
    if (!$meeting) metis_send_json_error('Meeting not found.', 404);

    $selected_prior_meeting_id = (int) ($_POST['packet_source_minutes_meeting_id'] ?? ($meeting['packet_source_minutes_meeting_id'] ?? 0));
    $selected_financial_doc_id = (int) ($_POST['packet_financial_document_id'] ?? ($meeting['packet_financial_document_id'] ?? 0));
    $wpdb->update(
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
    $decisions = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$decisions_table} WHERE meeting_id = %d ORDER BY id ASC", $meeting_id), ARRAY_A) ?: [];
    $actions = $wpdb->get_results($wpdb->prepare("SELECT a.*, p.display_name AS owner_name FROM {$actions_table} a LEFT JOIN {$people_table} p ON p.id = a.owner_person_id WHERE a.meeting_id = %d ORDER BY a.id ASC", $meeting_id), ARRAY_A) ?: [];
    $attendance = $wpdb->get_results($wpdb->prepare("SELECT atn.*, p.display_name FROM {$attendance_table} atn LEFT JOIN {$people_table} p ON p.id = atn.person_id WHERE atn.meeting_id = %d ORDER BY p.display_name ASC", $meeting_id), ARRAY_A) ?: [];
    $linked_docs_for_packet = array_values(array_filter(metis_board_fetch_meeting_documents($meeting_id), function ($doc) {
        $type = strtolower((string) ($doc['doc_type'] ?? ''));
        return !in_array($type, ['board_packet', 'agenda', 'minutes'], true);
    }));

    if (!class_exists('Core_PDF_Service')) {
        metis_send_json_error('PDF service is not available.', 500);
    }

    $extra_packet_data = [];
    if ($selected_prior_meeting_id > 0) {
        $prior = $wpdb->get_row($wpdb->prepare(
            "SELECT id, meeting_code, title, meeting_date, minutes_html
             FROM {$meetings_table}
             WHERE id = %d
             LIMIT 1",
            $selected_prior_meeting_id
        ), ARRAY_A);
        if ($prior && trim((string) ($prior['minutes_html'] ?? '')) !== '') {
            $extra_packet_data['prior_minutes_title'] = (string) ($prior['meeting_code'] ?? '') . ' · ' . (string) ($prior['title'] ?? 'Prior meeting') . ' · ' . metis_board_format_datetime((string) ($prior['meeting_date'] ?? ''));
            $extra_packet_data['prior_minutes_html'] = (string) ($prior['minutes_html'] ?? '');
        }
    }

    $financial_doc = null;
    if ($selected_financial_doc_id > 0) {
        $financial_doc = $wpdb->get_row($wpdb->prepare(
            "SELECT id, title, google_file_id, google_drive_url, mime_type
             FROM {$documents_table}
             WHERE id = %d
             LIMIT 1",
            $selected_financial_doc_id
        ), ARRAY_A);
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

        $agenda_html = metis_board_packet_html($meeting, $agenda, [], [], []);
        $agenda_bytes = $pdf->render_with_footer($agenda_html, 'Mobilize Waco — Agenda', ['paper' => 'letter', 'orientation' => 'portrait']);
        $minutes_html = '<html><body><h1>' . esc_html((string) ($meeting['title'] ?? 'Meeting')) . ' - Minutes</h1><div>' . metis_kses_post((string) ($meeting['minutes_html'] ?? '')) . '</div></body></html>';
        $minutes_bytes = $pdf->render_with_footer($minutes_html, 'Mobilize Waco — Minutes', ['paper' => 'letter', 'orientation' => 'portrait']);

        $packet_name = sanitize_file_name($meeting_code . '-Packet.pdf');
        $agenda_name = sanitize_file_name($meeting_code . '-Agenda.pdf');
        $minutes_name = sanitize_file_name($meeting_code . '-Minutes.pdf');

        $packet_upload = metis_board_drive_upload_bytes($packet_name, 'application/pdf', $packet_bytes, (string) $prepared['packet']['folder_id']);
        if (empty($packet_upload['ok'])) metis_send_json_error((string) ($packet_upload['error'] ?? 'Failed to upload packet PDF.'), 500);

        $agenda_upload = metis_board_drive_upload_bytes($agenda_name, 'application/pdf', $agenda_bytes, (string) $prepared['agenda']['folder_id']);
        if (empty($agenda_upload['ok'])) metis_send_json_error((string) ($agenda_upload['error'] ?? 'Failed to upload agenda PDF.'), 500);

        $minutes_upload = metis_board_drive_upload_bytes($minutes_name, 'application/pdf', $minutes_bytes, (string) $prepared['minutes']['folder_id']);
        if (empty($minutes_upload['ok'])) metis_send_json_error((string) ($minutes_upload['error'] ?? 'Failed to upload minutes PDF.'), 500);

        $financial_copy = [];
        if ($financial_doc && !empty($financial_doc['google_file_id'])) {
            $copy_name = sanitize_file_name($meeting_code . '-Financial-' . (string) ($financial_doc['title'] ?? 'Report'));
            $copy = metis_board_drive_copy_file((string) $financial_doc['google_file_id'], $copy_name, (string) $prepared['packet']['folder_id']);
            if (!empty($copy['ok']) && !empty($copy['file']) && is_array($copy['file'])) {
                $financial_copy = (array) $copy['file'];
                $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$documents_table} WHERE meeting_id = %d AND google_file_id = %s LIMIT 1",
                    $meeting_id,
                    (string) ($financial_copy['id'] ?? '')
                ));
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
                    $wpdb->update($documents_table, $payload, ['id' => $existing_id], ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s'], ['%d']);
                } else {
                    $payload['document_code'] = metis_board_generate_code('BF', $documents_table, 'document_code');
                    $wpdb->insert($documents_table, $payload, ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
                }
            }
        }

        $uploads = [
            'board_packet' => (array) ($packet_upload['file'] ?? []),
            'agenda' => (array) ($agenda_upload['file'] ?? []),
            'minutes' => (array) ($minutes_upload['file'] ?? []),
        ];
        foreach ($uploads as $doc_type => $file) {
            $file_id = (string) ($file['id'] ?? '');
            if ($file_id === '') continue;
            $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$documents_table} WHERE meeting_id = %d AND google_file_id = %s LIMIT 1",
                $meeting_id,
                $file_id
            ));
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
                $wpdb->update($documents_table, $payload, ['id' => $existing_id], ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s'], ['%d']);
            } else {
                $payload['document_code'] = metis_board_generate_code('BF', $documents_table, 'document_code');
                $wpdb->insert($documents_table, $payload, ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
            }
        }
    } catch (Throwable $e) {
        metis_send_json_error('Packet generation failed: ' . $e->getMessage(), 500);
    }

    metis_send_json_success([
        'meeting_id' => $meeting_id,
        'folders' => $prepared,
        'uploads' => [
            'packet' => $packet_upload['file'] ?? [],
            'agenda' => $agenda_upload['file'] ?? [],
            'minutes' => $minutes_upload['file'] ?? [],
            'financial' => $financial_copy,
        ],
    ]);
});

metis_add_action('wp_ajax_metis_board_get_workflow_templates', function () {
    metis_board_ajax_verify(false);
    global $wpdb;
    $agenda_table = Metis_Tables::get('board_agenda_templates');
    $decision_table = Metis_Tables::get('board_decision_templates');
    $agenda = $wpdb->get_results("SELECT id, template_code, name, description, default_items_json, sort_order, is_required, is_active FROM {$agenda_table} ORDER BY sort_order ASC, id ASC", ARRAY_A) ?: [];
    $decisions = $wpdb->get_results("SELECT id, template_code, title, description, default_outcome, sort_order, is_active FROM {$decision_table} ORDER BY sort_order ASC, id ASC", ARRAY_A) ?: [];
    metis_send_json_success(['agenda' => $agenda, 'decisions' => $decisions]);
});

metis_add_action('wp_ajax_metis_board_save_agenda_template', function () {
    metis_board_ajax_verify(true);
    global $wpdb;
    $table = Metis_Tables::get('board_agenda_templates');
    $template_id = (int) ($_POST['template_id'] ?? 0);
    $name = sanitize_text_field(metis_unslash($_POST['name'] ?? ''));
    $description = sanitize_text_field(metis_unslash($_POST['description'] ?? ''));
    $sort_order = max(0, (int) ($_POST['sort_order'] ?? 0));
    $is_required = (int) ($_POST['is_required'] ?? 0) === 1 ? 1 : 0;
    $is_active = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
    $default_items_raw = (string) metis_unslash($_POST['default_items_json'] ?? '[]');
    $default_items = json_decode($default_items_raw, true);
    if (!is_array($default_items)) $default_items = [];
    if ($name === '') metis_send_json_error('Template name is required.', 422);
    $payload = [
        'name' => $name,
        'description' => $description,
        'default_items_json' => metis_json_encode(array_values(array_filter(array_map('strval', $default_items), function ($v) { return trim($v) !== ''; }))),
        'sort_order' => $sort_order,
        'is_required' => $is_required,
        'is_active' => $is_active,
    ];
    if ($template_id > 0) {
        $ok = $wpdb->update($table, $payload, ['id' => $template_id], ['%s', '%s', '%s', '%d', '%d', '%d'], ['%d']);
        if ($ok === false) metis_send_json_error('Failed to update agenda template.', 500);
    } else {
        $payload['template_code'] = metis_board_generate_code('BS', $table, 'template_code');
        $ok = $wpdb->insert($table, $payload, ['%s', '%s', '%s', '%d', '%d', '%d', '%s']);
        if (!$ok) metis_send_json_error('Failed to save agenda template.', 500);
        $template_id = (int) $wpdb->insert_id;
    }
    metis_send_json_success(['template_id' => $template_id]);
});

metis_add_action('wp_ajax_metis_board_delete_agenda_template', function () {
    metis_board_ajax_verify(true);
    global $wpdb;
    $template_id = (int) ($_POST['template_id'] ?? 0);
    if ($template_id < 1) metis_send_json_error('Template is required.', 422);
    $table = Metis_Tables::get('board_agenda_templates');
    $ok = $wpdb->update($table, ['is_active' => 0], ['id' => $template_id], ['%d'], ['%d']);
    if ($ok === false) metis_send_json_error('Failed to deactivate template.', 500);
    metis_send_json_success(['template_id' => $template_id]);
});

metis_add_action('wp_ajax_metis_board_save_decision_template', function () {
    metis_board_ajax_verify(true);
    global $wpdb;
    $table = Metis_Tables::get('board_decision_templates');
    $template_id = (int) ($_POST['template_id'] ?? 0);
    $title = sanitize_text_field(metis_unslash($_POST['title'] ?? ''));
    $description = sanitize_text_field(metis_unslash($_POST['description'] ?? ''));
    $sort_order = max(0, (int) ($_POST['sort_order'] ?? 0));
    $default_outcome = sanitize_key(metis_unslash($_POST['default_outcome'] ?? 'pending'));
    if (!in_array($default_outcome, ['pending', 'approved', 'rejected', 'tabled'], true)) $default_outcome = 'pending';
    $is_active = (int) ($_POST['is_active'] ?? 1) === 1 ? 1 : 0;
    if ($title === '') metis_send_json_error('Decision template title is required.', 422);
    $payload = [
        'title' => $title,
        'description' => $description,
        'sort_order' => $sort_order,
        'default_outcome' => $default_outcome,
        'is_active' => $is_active,
    ];
    if ($template_id > 0) {
        $ok = $wpdb->update($table, $payload, ['id' => $template_id], ['%s', '%s', '%d', '%s', '%d'], ['%d']);
        if ($ok === false) metis_send_json_error('Failed to update decision template.', 500);
    } else {
        $payload['template_code'] = metis_board_generate_code('BT', $table, 'template_code');
        $ok = $wpdb->insert($table, $payload, ['%s', '%s', '%d', '%s', '%d', '%s']);
        if (!$ok) metis_send_json_error('Failed to save decision template.', 500);
        $template_id = (int) $wpdb->insert_id;
    }
    metis_send_json_success(['template_id' => $template_id]);
});

metis_add_action('wp_ajax_metis_board_delete_decision_template', function () {
    metis_board_ajax_verify(true);
    global $wpdb;
    $template_id = (int) ($_POST['template_id'] ?? 0);
    if ($template_id < 1) metis_send_json_error('Template is required.', 422);
    $table = Metis_Tables::get('board_decision_templates');
    $ok = $wpdb->update($table, ['is_active' => 0], ['id' => $template_id], ['%d'], ['%d']);
    if ($ok === false) metis_send_json_error('Failed to deactivate template.', 500);
    metis_send_json_success(['template_id' => $template_id]);
});

metis_add_action('wp_ajax_metis_board_drive_list', function () {
    metis_board_ajax_verify(false);
    global $wpdb;

    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    $folder_id = sanitize_text_field(metis_unslash($_POST['folder_id'] ?? ''));
    $search = sanitize_text_field(metis_unslash($_POST['search'] ?? ''));

    $meetings_table = Metis_Tables::get('board_meetings');
    if ($folder_id === '' && $meeting_id > 0) {
        $folder_id = (string) $wpdb->get_var($wpdb->prepare("SELECT google_drive_folder_id FROM {$meetings_table} WHERE id = %d LIMIT 1", $meeting_id));
    }
    if ($folder_id === '') $folder_id = 'root';

    $q_parts = ["trashed = false"];
    if ($folder_id !== '') {
        $q_parts[] = "'" . str_replace("'", "\\'", $folder_id) . "' in parents";
    }
    if ($search !== '') {
        $q_parts[] = "name contains '" . str_replace("'", "\\'", $search) . "'";
    }
    $query = implode(' and ', $q_parts);
    $url = add_query_arg([
        'q' => $query,
        'pageSize' => 200,
        'orderBy' => 'folder,name',
        'fields' => 'files(id,name,mimeType,webViewLink,modifiedTime,size,parents)',
        'includeItemsFromAllDrives' => 'true',
        'supportsAllDrives' => 'true',
    ], 'https://www.googleapis.com/drive/v3/files');

    $resp = metis_board_drive_request('GET', $url, null);
    if (empty($resp['ok'])) {
        metis_send_json_error((string) ($resp['error'] ?? 'Failed to list Drive files.'), 500);
    }

    $files = [];
    foreach ((array) (($resp['body']['files'] ?? [])) as $file) {
        if (!is_array($file)) continue;
        $files[] = [
            'id' => (string) ($file['id'] ?? ''),
            'name' => (string) ($file['name'] ?? ''),
            'mimeType' => (string) ($file['mimeType'] ?? ''),
            'isFolder' => ((string) ($file['mimeType'] ?? '')) === 'application/vnd.google-apps.folder',
            'webViewLink' => (string) ($file['webViewLink'] ?? ''),
            'modifiedTime' => (string) ($file['modifiedTime'] ?? ''),
            'size' => (string) ($file['size'] ?? ''),
        ];
    }

    $parent_id = '';
    if ($folder_id !== '' && $folder_id !== 'root') {
        $folder_url = add_query_arg([
            'fields' => 'id,name,parents',
            'supportsAllDrives' => 'true',
        ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($folder_id));
        $parent_resp = metis_board_drive_request('GET', $folder_url, null);
        if (!empty($parent_resp['ok'])) {
            $parents = (array) (($parent_resp['body']['parents'] ?? []));
            $parent_id = !empty($parents) ? (string) $parents[0] : '';
        }
    }

    metis_send_json_success([
        'meeting_id' => $meeting_id,
        'folder_id' => $folder_id,
        'parent_id' => $parent_id,
        'files' => $files,
    ]);
});

metis_add_action('wp_ajax_metis_board_drive_create_folder', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    $parent_id = sanitize_text_field(metis_unslash($_POST['parent_id'] ?? 'root'));
    $folder_name = sanitize_text_field(metis_unslash($_POST['folder_name'] ?? ''));
    $set_as_meeting = (int) ($_POST['set_as_meeting'] ?? 0) === 1;
    if ($folder_name === '') metis_send_json_error('Folder name is required.', 422);

    $payload = [
        'name' => $folder_name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parent_id !== '' ? $parent_id : 'root'],
    ];
    $url = add_query_arg(['supportsAllDrives' => 'true'], 'https://www.googleapis.com/drive/v3/files');
    $resp = metis_board_drive_request('POST', $url, $payload);
    if (empty($resp['ok'])) {
        metis_send_json_error((string) ($resp['error'] ?? 'Failed to create folder.'), 500);
    }

    $folder_id = (string) (($resp['body']['id'] ?? ''));
    $folder_link = 'https://drive.google.com/drive/folders/' . rawurlencode($folder_id);
    if ($set_as_meeting && $meeting_id > 0 && $folder_id !== '') {
        $meetings_table = Metis_Tables::get('board_meetings');
        $wpdb->update(
            $meetings_table,
            ['google_drive_folder_id' => $folder_id, 'google_drive_folder_url' => $folder_link],
            ['id' => $meeting_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    metis_send_json_success([
        'folder_id' => $folder_id,
        'folder_link' => $folder_link,
        'name' => $folder_name,
    ]);
});

metis_add_action('wp_ajax_metis_board_drive_upload', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    $folder_id = sanitize_text_field(metis_unslash($_POST['folder_id'] ?? ''));
    $doc_type = sanitize_key(metis_unslash($_POST['doc_type'] ?? 'board_packet'));
    $item_title = sanitize_text_field(metis_unslash($_POST['item_title'] ?? ''));
    if ($folder_id === '') $folder_id = 'root';
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        metis_send_json_error('Upload file is required.', 422);
    }
    $file = $_FILES['file'];
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = sanitize_file_name((string) ($file['name'] ?? ''));
    if ($tmp === '' || !file_exists($tmp) || $name === '') {
        metis_send_json_error('Invalid upload payload.', 422);
    }
    $bytes = file_get_contents($tmp);
    if ($bytes === false) metis_send_json_error('Failed to read uploaded file.', 500);
    $mime = (string) ($file['type'] ?? 'application/octet-stream');

    $meta = [
        'name' => $name,
        'parents' => [$folder_id],
    ];
    $boundary = 'metis_' . metis_generate_password(12, false, false);
    $multipart_body = "--{$boundary}\r\n";
    $multipart_body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $multipart_body .= metis_json_encode($meta) . "\r\n";
    $multipart_body .= "--{$boundary}\r\n";
    $multipart_body .= "Content-Type: {$mime}\r\n\r\n";
    $multipart_body .= $bytes . "\r\n";
    $multipart_body .= "--{$boundary}--";

    $cfg = metis_board_drive_cfg();
    if (empty($cfg['ok'])) metis_send_json_error((string) ($cfg['error'] ?? 'Workspace config missing.'), 400);
    $token = metis_board_google_access_token($cfg);
    if (empty($token['ok'])) metis_send_json_error((string) ($token['error'] ?? 'Workspace token error.'), 500);

    $upload_url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,mimeType,size,webViewLink';
    $upload_resp = metis_remote_post($upload_url, [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . (string) $token['access_token'],
            'Content-Type' => 'multipart/related; boundary=' . $boundary,
        ],
        'body' => $multipart_body,
    ]);
    if (metis_is_error($upload_resp)) metis_send_json_error($upload_resp->get_error_message(), 500);
    $code = (int) metis_remote_retrieve_response_code($upload_resp);
    $raw = (string) metis_remote_retrieve_body($upload_resp);
    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded) || empty($decoded['id'])) {
        $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
        if ($msg === '') $msg = 'Failed to upload file to Drive.';
        metis_send_json_error($msg, 500);
    }

    $docs_table = Metis_Tables::get('board_documents');
    if ($meeting_id > 0) {
        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$docs_table} WHERE meeting_id = %d AND google_file_id = %s LIMIT 1",
            $meeting_id,
            (string) $decoded['id']
        ));
        $doc_payload = [
            'meeting_id' => $meeting_id,
            'title' => $item_title !== '' ? $item_title : (string) ($decoded['name'] ?? $name),
            'doc_type' => $doc_type !== '' ? $doc_type : 'board_packet',
            'google_file_id' => (string) ($decoded['id'] ?? ''),
            'google_drive_url' => (string) ($decoded['webViewLink'] ?? ''),
            'mime_type' => (string) ($decoded['mimeType'] ?? $mime),
            'file_size' => isset($decoded['size']) ? (int) $decoded['size'] : (int) filesize($tmp),
            'status' => 'active',
        ];
        if ($existing_id > 0) {
            $wpdb->update($docs_table, $doc_payload, ['id' => $existing_id], ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s'], ['%d']);
        } else {
            $doc_payload['document_code'] = metis_board_generate_code('BF', $docs_table, 'document_code');
            $wpdb->insert($docs_table, $doc_payload, ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
        }
    }

    metis_send_json_success([
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

metis_add_action('wp_ajax_metis_board_drive_set_meeting_folder', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    $folder_id = sanitize_text_field(metis_unslash($_POST['folder_id'] ?? ''));
    if ($meeting_id < 1 || $folder_id === '') metis_send_json_error('Meeting and folder are required.', 422);
    $folder_url = 'https://drive.google.com/drive/folders/' . rawurlencode($folder_id);
    $meetings_table = Metis_Tables::get('board_meetings');
    $ok = $wpdb->update(
        $meetings_table,
        ['google_drive_folder_id' => $folder_id, 'google_drive_folder_url' => $folder_url],
        ['id' => $meeting_id],
        ['%s', '%s'],
        ['%d']
    );
    if ($ok === false) metis_send_json_error('Failed to set meeting folder.', 500);
    metis_send_json_success(['folder_id' => $folder_id, 'folder_url' => $folder_url]);
});

metis_add_action('wp_ajax_metis_board_drive_link_document', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    $file_id = sanitize_text_field(metis_unslash($_POST['file_id'] ?? ''));
    $title = sanitize_text_field(metis_unslash($_POST['title'] ?? ''));
    $doc_type = sanitize_key(metis_unslash($_POST['doc_type'] ?? 'board_packet'));
    $mime_type = sanitize_text_field(metis_unslash($_POST['mime_type'] ?? ''));
    $web_view_link = esc_url_raw((string) metis_unslash($_POST['web_view_link'] ?? ''));
    $size = (int) ($_POST['size'] ?? 0);
    if ($meeting_id < 1 || $file_id === '') metis_send_json_error('Meeting and file are required.', 422);
    if ($title === '') $title = 'Drive file';
    if ($doc_type === '') $doc_type = 'board_packet';

    $docs_table = Metis_Tables::get('board_documents');
    $existing_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$docs_table} WHERE meeting_id = %d AND google_file_id = %s LIMIT 1",
        $meeting_id,
        $file_id
    ));
    $payload = [
        'meeting_id' => $meeting_id,
        'title' => $title,
        'doc_type' => $doc_type,
        'google_file_id' => $file_id,
        'google_drive_url' => $web_view_link,
        'mime_type' => $mime_type,
        'file_size' => $size > 0 ? $size : null,
        'status' => 'active',
    ];
    if ($existing_id > 0) {
        $ok = $wpdb->update($docs_table, $payload, ['id' => $existing_id], ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s'], ['%d']);
        if ($ok === false) metis_send_json_error('Failed to update linked document.', 500);
        metis_send_json_success([
            'document_id' => $existing_id,
            'documents' => metis_board_fetch_meeting_documents($meeting_id),
        ]);
        return;
    }
    $payload['document_code'] = metis_board_generate_code('BF', $docs_table, 'document_code');
    $ok = $wpdb->insert($docs_table, $payload, ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
    if (!$ok) metis_send_json_error('Failed to link document.', 500);
    metis_send_json_success([
        'document_id' => (int) $wpdb->insert_id,
        'documents' => metis_board_fetch_meeting_documents($meeting_id),
    ]);
});

metis_add_action('wp_ajax_metis_board_drive_unlink_document', function () {
    metis_board_ajax_verify(true);
    global $wpdb;
    $document_id = (int) ($_POST['document_id'] ?? 0);
    if ($document_id < 1) metis_send_json_error('Document is required.', 422);
    $docs_table = Metis_Tables::get('board_documents');
    $meeting_id = (int) $wpdb->get_var($wpdb->prepare("SELECT meeting_id FROM {$docs_table} WHERE id = %d LIMIT 1", $document_id));
    $deleted = $wpdb->delete($docs_table, ['id' => $document_id], ['%d']);
    if (!$deleted) metis_send_json_error('Failed to unlink document.', 500);
    metis_send_json_success([
        'document_id' => $document_id,
        'documents' => $meeting_id > 0 ? metis_board_fetch_meeting_documents($meeting_id) : [],
    ]);
});

metis_add_action('wp_ajax_metis_board_get_meeting_documents', function () {
    metis_board_ajax_verify(false);
    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    if ($meeting_id < 1) metis_send_json_error('Meeting is required.', 422);
    metis_send_json_success([
        'meeting_id' => $meeting_id,
        'documents' => metis_board_fetch_meeting_documents($meeting_id),
    ]);
});

metis_add_action('wp_ajax_metis_board_sync_decision_points', function () {
    metis_board_ajax_verify(true);
    global $wpdb;
    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    if ($meeting_id < 1) metis_send_json_error('Meeting is required.', 422);

    $agenda = null;
    if (array_key_exists('agenda_json', $_POST)) {
        $agenda_json_raw = trim((string) metis_unslash($_POST['agenda_json'] ?? ''));
        if ($agenda_json_raw !== '') {
            $decoded = json_decode($agenda_json_raw, true);
            if (!is_array($decoded)) metis_send_json_error('Agenda JSON is invalid.', 422);
            $agenda = $decoded;
        } else {
            $agenda = [];
        }
    } else {
        $meetings_table = Metis_Tables::get('board_meetings');
        $agenda_json_raw = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT agenda_json FROM {$meetings_table} WHERE id = %d LIMIT 1",
            $meeting_id
        ));
        $decoded = json_decode($agenda_json_raw, true);
        $agenda = is_array($decoded) ? $decoded : [];
    }

    $created = function_exists('metis_board_sync_decision_points') ? metis_board_sync_decision_points($meeting_id, $agenda) : 0;
    metis_send_json_success(['meeting_id' => $meeting_id, 'created_decisions' => $created]);
});

metis_add_action('wp_ajax_metis_board_update_meeting_detail', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    if ($meeting_id < 1) {
        metis_send_json_error('Meeting is required.', 422);
    }

    $table = Metis_Tables::get('board_meetings');
    $payload = [];
    $formats = [];

    if (array_key_exists('agenda_json', $_POST)) {
        $agenda_json_raw = trim((string) metis_unslash($_POST['agenda_json'] ?? ''));
        if ($agenda_json_raw === '') {
            $payload['agenda_json'] = null;
            $formats[] = '%s';
        } else {
            json_decode($agenda_json_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                metis_send_json_error('Agenda JSON is invalid.', 422);
            }
            $payload['agenda_json'] = $agenda_json_raw;
            $formats[] = '%s';
        }
    }

    if (array_key_exists('minutes_html', $_POST)) {
        $payload['minutes_html'] = metis_kses_post(metis_unslash($_POST['minutes_html'] ?? ''));
        $formats[] = '%s';
    }

    if (array_key_exists('board_packet_notes', $_POST)) {
        $payload['board_packet_notes'] = metis_kses_post(metis_unslash($_POST['board_packet_notes'] ?? ''));
        $formats[] = '%s';
    }

    if (array_key_exists('packet_source_minutes_meeting_id', $_POST)) {
        $val = (int) ($_POST['packet_source_minutes_meeting_id'] ?? 0);
        $payload['packet_source_minutes_meeting_id'] = $val > 0 ? $val : null;
        $formats[] = '%d';
    }

    if (array_key_exists('packet_financial_document_id', $_POST)) {
        $val = (int) ($_POST['packet_financial_document_id'] ?? 0);
        $payload['packet_financial_document_id'] = $val > 0 ? $val : null;
        $formats[] = '%d';
    }

    if (array_key_exists('packet_published', $_POST)) {
        $packet_published = (int) ($_POST['packet_published'] ?? 0) === 1;
        $payload['published_at'] = $packet_published ? current_time('mysql') : null;
        $formats[] = '%s';
    }

    if (array_key_exists('attendance_locked', $_POST)) {
        $payload['attendance_locked'] = (int) ($_POST['attendance_locked'] ?? 0) === 1 ? 1 : 0;
        $formats[] = '%d';
    }

    if (empty($payload)) {
        metis_send_json_error('No meeting fields to update.', 422);
    }

    $ok = $wpdb->update($table, $payload, ['id' => $meeting_id], $formats, ['%d']);
    if ($ok === false) {
        metis_send_json_error('Failed to update meeting detail.', 500);
    }

    metis_send_json_success([
        'meeting_id' => $meeting_id,
        'payload' => $payload,
        'created_decisions' => 0,
    ]);
});

metis_add_action('wp_ajax_metis_board_update_decision', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $decision_id = (int) ($_POST['decision_id'] ?? 0);
    if ($decision_id < 1) {
        metis_send_json_error('Decision is required.', 422);
    }

    $outcome = sanitize_key(metis_unslash($_POST['outcome'] ?? 'pending'));
    if (!in_array($outcome, ['pending', 'approved', 'rejected', 'tabled'], true)) {
        $outcome = 'pending';
    }

    $votes_for = max(0, (int) ($_POST['votes_for'] ?? 0));
    $votes_against = max(0, (int) ($_POST['votes_against'] ?? 0));
    $votes_abstain = max(0, (int) ($_POST['votes_abstain'] ?? 0));

    $payload = [
        'outcome' => $outcome,
        'votes_for' => $votes_for,
        'votes_against' => $votes_against,
        'votes_abstain' => $votes_abstain,
        'passed' => $outcome === 'approved' ? 1 : 0,
        'passed_at' => $outcome === 'approved' ? current_time('mysql') : null,
    ];

    $table = Metis_Tables::get('board_decisions');
    $ok = $wpdb->update($table, $payload, ['id' => $decision_id], ['%s', '%d', '%d', '%d', '%d', '%s'], ['%d']);
    if ($ok === false) {
        metis_send_json_error('Failed to update decision.', 500);
    }

    metis_send_json_success([
        'decision_id' => $decision_id,
        'outcome' => $outcome,
        'votes_for' => $votes_for,
        'votes_against' => $votes_against,
        'votes_abstain' => $votes_abstain,
    ]);
});

metis_add_action('wp_ajax_metis_board_upsert_attendance', function () {
    metis_board_ajax_verify(true);
    global $wpdb;

    $meeting_id = (int) ($_POST['meeting_id'] ?? 0);
    $person_id = (int) ($_POST['person_id'] ?? 0);
    if ($meeting_id < 1 || $person_id < 1) {
        metis_send_json_error('Meeting and member are required.', 422);
    }

    $meetings_table = Metis_Tables::get('board_meetings');
    $locked = (int) $wpdb->get_var($wpdb->prepare("SELECT attendance_locked FROM {$meetings_table} WHERE id = %d LIMIT 1", $meeting_id));
    if ($locked === 1) {
        metis_send_json_error('Attendance is locked for this meeting.', 423);
    }

    $status = sanitize_key(metis_unslash($_POST['status'] ?? 'absent'));
    if (!in_array($status, ['present', 'remote', 'absent', 'excused'], true)) {
        $status = 'absent';
    }

    $role_label = sanitize_text_field(metis_unslash($_POST['role_label'] ?? ''));
    $notes = sanitize_text_field(metis_unslash($_POST['notes'] ?? ''));

    $table = Metis_Tables::get('board_attendance');
    $existing_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE meeting_id = %d AND person_id = %d LIMIT 1",
        $meeting_id,
        $person_id
    ));

    $payload = [
        'meeting_id' => $meeting_id,
        'person_id' => $person_id,
        'role_label' => $role_label,
        'status' => $status,
        'checkin_at' => in_array($status, ['present', 'remote'], true) ? current_time('mysql') : null,
        'notes' => $notes,
    ];

    if ($existing_id > 0) {
        $ok = $wpdb->update($table, $payload, ['id' => $existing_id], ['%d', '%d', '%s', '%s', '%s', '%s'], ['%d']);
        if ($ok === false) {
            metis_send_json_error('Failed to update attendance.', 500);
        }
    } else {
        $ok = $wpdb->insert($table, $payload, ['%d', '%d', '%s', '%s', '%s', '%s']);
        if (!$ok) {
            metis_send_json_error('Failed to save attendance.', 500);
        }
        $existing_id = (int) $wpdb->insert_id;
    }

    $people_table = Metis_Tables::get('people');
    $board_eligible = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table} WHERE status = 'active' AND is_board = 1");
    $present_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE meeting_id = %d AND status IN ('present','remote')",
        $meeting_id
    ));
    if ($board_eligible < 1) {
        $board_eligible = max(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE meeting_id = %d", $meeting_id)));
    }
    $required = (int) floor($board_eligible / 2) + 1;

    metis_send_json_success([
        'attendance_id' => $existing_id,
        'present_count' => $present_count,
        'eligible_count' => $board_eligible,
        'required_count' => $required,
        'quorum_met' => $present_count >= $required,
    ]);
});
