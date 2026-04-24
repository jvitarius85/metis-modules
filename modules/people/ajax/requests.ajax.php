<?php
if (!defined('METIS_ROOT')) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_ajax_register_controller( 'metis_people_create_access_request', [
        'module' => 'people',
        'permission' => 'view',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_create_access_request' ),
    ] );
    metis_ajax_register_controller( 'metis_people_resolve_access_request', [
        'module' => 'people',
        'permission' => 'edit',
        'nonce_action' => metis_ajax_nonce_action( 'metis_people_resolve_access_request' ),
    ] );
}

metis_ajax_register_handler( 'metis_people_create_access_request', function () {
    $db = metis_db();
    $requests_table = Metis_Tables::get('people_access_requests');
    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');

    $target_pid = isset($_POST['target_pid']) ? metis_text_clean(metis_runtime_unslash($_POST['target_pid'])) : '';
    $role_key = isset($_POST['role_key']) ? metis_key_clean(metis_runtime_unslash($_POST['role_key'])) : '';
    $reason = isset($_POST['reason']) ? sanitize_textarea_field(metis_runtime_unslash($_POST['reason'])) : '';
    $requested_start_at = isset($_POST['requested_start_at']) ? metis_text_clean(metis_runtime_unslash($_POST['requested_start_at'])) : '';
    $requested_end_at = isset($_POST['requested_end_at']) ? metis_text_clean(metis_runtime_unslash($_POST['requested_end_at'])) : '';
    $expires_at = isset($_POST['expires_at']) ? metis_text_clean(metis_runtime_unslash($_POST['expires_at'])) : '';
    $required_approvals = isset($_POST['required_approvals']) ? (int) metis_runtime_unslash($_POST['required_approvals']) : 2;
    $role_id = (int) $db->scalar("SELECT id FROM {$roles_table} WHERE role_key=%s AND role_domain='metis' LIMIT 1", [ $role_key ]);
    $target_person_id = (int) $db->scalar("SELECT id FROM {$people_table} WHERE pid=%s LIMIT 1", [ $target_pid ]);
    if ($role_id < 1 || $target_person_id < 1 || trim($reason) === '') {
        metis_runtime_send_json_error('Target person and role are required.', 400);
    }
    $required_approvals = max(1, min(3, $required_approvals));
    if ($requested_start_at !== '' && strlen($requested_start_at) === 16) $requested_start_at .= ':00';
    if ($requested_end_at !== '' && strlen($requested_end_at) === 16) $requested_end_at .= ':00';
    if ($expires_at !== '' && strlen($expires_at) === 16) $expires_at .= ':00';
    $requested_start_at = str_replace('T', ' ', $requested_start_at);
    $requested_end_at = str_replace('T', ' ', $requested_end_at);
    $expires_at = str_replace('T', ' ', $expires_at);
    if ($requested_start_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $requested_start_at)) {
        $requested_start_at = '';
    }
    if ($requested_end_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $requested_end_at)) {
        $requested_end_at = '';
    }
    if ($expires_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires_at)) {
        $expires_at = '';
    }
    if ($requested_start_at !== '' && $requested_end_at !== '' && strtotime($requested_end_at) < strtotime($requested_start_at)) {
        metis_runtime_send_json_error('Requested end must be after requested start.', 400);
    }
    $requester = metis_people_get_current_person_id();
    $code = metis_generate_code('AR', $requests_table, 'request_code');
    $db->insert($requests_table, [
        'request_code' => $code,
        'requester_person_id' => $requester > 0 ? $requester : null,
        'target_person_id' => $target_person_id,
        'role_id' => $role_id,
        'status' => 'pending',
        'reason' => $reason !== '' ? $reason : null,
        'required_approvals' => $required_approvals,
        'approval_count' => 0,
        'approval_log_json' => metis_json_encode([]),
        'requested_start_at' => $requested_start_at !== '' ? $requested_start_at : null,
        'requested_end_at' => $requested_end_at !== '' ? $requested_end_at : null,
        'expires_at' => $expires_at !== '' ? $expires_at : null,
    ], ['%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']);
    $target_row = $db->fetchOne(
        "SELECT pid, display_name, first_name, last_name FROM {$people_table} WHERE id = %d LIMIT 1",
        [ $target_person_id ]
    ) ?: [];
    $target_name = trim((string) ($target_row['first_name'] ?? '') . ' ' . (string) ($target_row['last_name'] ?? ''));
    if ($target_name === '') $target_name = (string) ($target_row['display_name'] ?? '');
    $role_name = (string) $db->scalar("SELECT role_name FROM {$roles_table} WHERE id = %d LIMIT 1", [ $role_id ]);
    metis_people_log_activity($target_person_id, 'access_request_created', 'Created access request', ['request_code' => $code, 'role_key' => $role_key, 'required_approvals' => $required_approvals]);
    metis_runtime_send_json_success([
        'request_code' => $code,
        'status' => 'pending',
        'target_pid' => (string) ($target_row['pid'] ?? ''),
        'target_name' => $target_name,
        'role_name' => $role_name,
        'reason' => $reason,
        'approval_count' => 0,
        'required_approvals' => $required_approvals,
        'requested_start_at' => $requested_start_at !== '' ? $requested_start_at : '',
        'requested_end_at' => $requested_end_at !== '' ? $requested_end_at : '',
        'expires_at' => $expires_at !== '' ? $expires_at : '',
    ]);
});

metis_ajax_register_handler( 'metis_people_resolve_access_request', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $requests_table = Metis_Tables::get('people_access_requests');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $request_id = isset($_POST['request_id']) ? (int) metis_runtime_unslash($_POST['request_id']) : 0;
    $decision = isset($_POST['decision']) ? metis_key_clean(metis_runtime_unslash($_POST['decision'])) : '';
    $decision_note = isset($_POST['decision_note']) ? sanitize_textarea_field(metis_runtime_unslash($_POST['decision_note'])) : '';
    if ($request_id < 1 || !in_array($decision, ['approved', 'rejected'], true) || trim($decision_note) === '') {
        metis_runtime_send_json_error('Invalid request or decision.', 400);
    }
    $req = $db->fetchOne("SELECT * FROM {$requests_table} WHERE id = %d LIMIT 1", [ $request_id ]);
    if (!$req || (string) ($req['status'] ?? '') !== 'pending') {
        metis_runtime_send_json_error('Request not found or already resolved.', 404);
    }
    if (!empty($req['expires_at']) && strtotime((string) $req['expires_at']) < time()) {
        metis_runtime_send_json_error('Request has expired and cannot be resolved.', 400);
    }
    $resolver = metis_people_get_current_person_id();
    $required_approvals = max(1, (int) ($req['required_approvals'] ?? 2));
    $approval_count = max(0, (int) ($req['approval_count'] ?? 0));
    $approval_log = json_decode((string) ($req['approval_log_json'] ?? ''), true);
    if (!is_array($approval_log)) $approval_log = [];

    if ($decision === 'rejected') {
        $db->update($requests_table, [
            'status' => 'rejected',
            'decision_note' => $decision_note,
            'resolver_person_id' => $resolver > 0 ? $resolver : null,
            'resolved_at' => metis_current_time('mysql'),
        ], ['id' => $request_id], ['%s', '%s', '%d', '%s'], ['%d']);
        metis_people_log_activity((int) $req['target_person_id'], 'access_request_resolved', 'Rejected access request', ['request_id' => $request_id, 'decision_note' => $decision_note]);
        metis_runtime_send_json_success(['status' => 'rejected']);
    }

    $already = false;
    foreach ($approval_log as $entry) {
        if ((int) ($entry['resolver_person_id'] ?? 0) === $resolver && $resolver > 0) {
            $already = true;
            break;
        }
    }
    if ($already) {
        metis_runtime_send_json_error('You already approved this request. Another approver is required.', 400);
    }
    $approval_log[] = [
        'resolver_person_id' => $resolver > 0 ? $resolver : null,
        'decision_note' => $decision_note,
        'approved_at' => metis_current_time('mysql'),
    ];
    $approval_count++;
    $status = $approval_count >= $required_approvals ? 'approved' : 'pending';
    $resolver_person_id = $status === 'approved' ? ($resolver > 0 ? $resolver : null) : null;
    $resolved_at = $status === 'approved' ? metis_current_time('mysql') : null;
    $db->update($requests_table, [
        'status' => $status,
        'approval_count' => $approval_count,
        'approval_log_json' => metis_json_encode($approval_log),
        'decision_note' => $decision_note,
        'resolver_person_id' => $resolver_person_id,
        'resolved_at' => $resolved_at,
    ], ['id' => $request_id], ['%s', '%d', '%s', '%s', '%d', '%s'], ['%d']);
    if ($status === 'approved') {
        $exists = (int) $db->scalar(
            "SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1",
            [ (int) $req['target_person_id'], (int) $req['role_id'] ]
        );
        if ($exists < 1) {
            $db->insert($user_roles_table, [
                'person_id' => (int) $req['target_person_id'],
                'role_id' => (int) $req['role_id'],
                'start_at' => !empty($req['requested_start_at']) ? (string) $req['requested_start_at'] : null,
                'end_at' => !empty($req['requested_end_at']) ? (string) $req['requested_end_at'] : null,
            ], ['%d', '%d', '%s', '%s']);
        }
        metis_people_log_activity((int) $req['target_person_id'], 'access_request_resolved', 'Approved access request', ['request_id' => $request_id, 'approvals' => $approval_count]);
        metis_runtime_send_json_success(['status' => 'approved', 'approval_count' => $approval_count, 'required_approvals' => $required_approvals]);
    }
    metis_people_log_activity((int) $req['target_person_id'], 'access_request_resolved', 'Recorded approval on access request', ['request_id' => $request_id, 'approval_count' => $approval_count, 'required_approvals' => $required_approvals]);
    metis_runtime_send_json_success(['status' => 'pending', 'approval_count' => $approval_count, 'required_approvals' => $required_approvals]);
});
