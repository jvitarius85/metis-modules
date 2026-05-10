<?php
if (!defined('METIS_ROOT')) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $actions = [
        'metis_people_bulk_workspace_group_action',
        'metis_people_workspace_save_group',
        'metis_people_workspace_add_group_member',
        'metis_people_workspace_get_group_members_matrix',
        'metis_people_workspace_save_group_members_bulk',
        'metis_people_workspace_get_group_permissions',
        'metis_people_workspace_save_group_permissions',
        'metis_people_workspace_get_group_permission_templates',
        'metis_people_workspace_capture_group_permission_templates',
        'metis_people_workspace_delete_group',
    ];
    foreach ( $actions as $action ) {
        metis_ajax_register_controller( $action, [
            'module' => 'people',
            'permission' => 'workspace_manage',
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
}

if (!function_exists('metis_people_workspace_permission_template_settings_key')) {
    function metis_people_workspace_permission_template_settings_key(): string {
        return 'people_workspace_group_permission_templates';
    }
}

if (!function_exists('metis_people_workspace_permission_template_defaults')) {
    function metis_people_workspace_permission_template_defaults(): array {
        return [
            'board' => [
                'label' => 'Board',
                'permissions' => [
                    'whoCanJoin' => 'INVITED_CAN_JOIN',
                    'whoCanViewMembership' => 'ALL_MEMBERS_CAN_VIEW',
                    'whoCanPostMessage' => 'ALL_MEMBERS_CAN_POST',
                    'allowExternalMembers' => 'false',
                ],
                'permissions_full' => [
                    'whoCanJoin' => 'INVITED_CAN_JOIN',
                    'whoCanViewMembership' => 'ALL_MEMBERS_CAN_VIEW',
                    'whoCanPostMessage' => 'ALL_MEMBERS_CAN_POST',
                    'allowExternalMembers' => 'false',
                ],
                'source' => '',
                'captured_at' => '',
            ],
            'supplies' => [
                'label' => 'Supplies',
                'permissions' => [
                    'whoCanJoin' => 'INVITED_CAN_JOIN',
                    'whoCanViewMembership' => 'ALL_MEMBERS_CAN_VIEW',
                    'whoCanPostMessage' => 'ALL_MEMBERS_CAN_POST',
                    'allowExternalMembers' => 'false',
                ],
                'permissions_full' => [
                    'whoCanJoin' => 'INVITED_CAN_JOIN',
                    'whoCanViewMembership' => 'ALL_MEMBERS_CAN_VIEW',
                    'whoCanPostMessage' => 'ALL_MEMBERS_CAN_POST',
                    'allowExternalMembers' => 'false',
                ],
                'source' => '',
                'captured_at' => '',
            ],
        ];
    }
}

if (!function_exists('metis_people_workspace_group_permissions_full_sanitize')) {
    function metis_people_workspace_group_permissions_full_sanitize(array $input): array {
        $out = [];
        foreach ($input as $key => $value) {
            $k = trim((string) $key);
            if ($k === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]{1,120}$/', $k)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if (is_bool($value)) {
                $out[$k] = $value ? 'true' : 'false';
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $out[$k] = (string) $value;
                continue;
            }
            $v = trim((string) $value);
            if ($v === '') {
                continue;
            }
            if (strlen($v) > 1024) {
                $v = substr($v, 0, 1024);
            }
            $out[$k] = $v;
        }

        $basic = metis_people_workspace_group_permissions_sanitize($out);
        foreach ($basic as $k => $v) {
            $out[$k] = $v;
        }

        return $out;
    }
}

if (!function_exists('metis_people_workspace_group_permissions_full_for_read')) {
    function metis_people_workspace_group_permissions_full_for_read(array $input): array {
        $out = [];
        foreach ($input as $key => $value) {
            $k = trim((string) $key);
            if ($k === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]{1,120}$/', $k)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if (is_bool($value)) {
                $out[$k] = $value ? 'true' : 'false';
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $out[$k] = (string) $value;
                continue;
            }
            $v = (string) $value;
            if (strlen($v) > 1024) {
                $v = substr($v, 0, 1024);
            }
            $out[$k] = $v;
        }
        return $out;
    }
}

if (!function_exists('metis_people_workspace_fetch_group_permissions_full')) {
    function metis_people_workspace_fetch_group_permissions_full(string $group_email): array {
        $group_email = strtolower(trim($group_email));
        if (!metis_email_is_valid($group_email)) {
            return ['ok' => false, 'permissions' => [], 'error' => 'Invalid group email.'];
        }
        $cfg = metis_people_workspace_sync_settings();
        if (empty($cfg['ok'])) {
            return ['ok' => false, 'permissions' => [], 'error' => 'Workspace settings are missing.'];
        }
        $cfg_groups = $cfg;
        $cfg_groups['scopes'] = array_values(array_unique(array_merge(
            (array) ($cfg['scopes'] ?? []),
            ['https://www.googleapis.com/auth/apps.groups.settings']
        )));
        $urls = [
            'https://groupssettings.googleapis.com/groups/v1/groups/' . rawurlencode($group_email) . '?alt=json',
            'https://www.googleapis.com/groups/v1/groups/' . rawurlencode($group_email) . '?alt=json',
        ];
        $last_error = 'Failed to fetch group settings from Google.';
        foreach ($urls as $url) {
            $remote = metis_people_workspace_google_request('GET', $url, null, $cfg_groups);
            if (empty($remote['ok'])) {
                $last_error = metis_text_clean((string) ($remote['error'] ?? $last_error));
                continue;
            }
            $raw_body = is_array($remote['body'] ?? null) ? (array) $remote['body'] : [];
            $raw_permissions = metis_people_workspace_group_permissions_full_for_read($raw_body);
            if (!empty($raw_body) || !empty($raw_permissions)) {
                return [
                    'ok' => true,
                    'permissions' => $raw_permissions,
                    'error' => '',
                ];
            }
            $raw_excerpt = trim((string) ($remote['raw'] ?? ''));
            if ($raw_excerpt !== '') {
                $raw_excerpt = preg_replace('/\s+/', ' ', $raw_excerpt);
                $raw_excerpt = substr($raw_excerpt, 0, 160);
                $last_error = 'Groups Settings API returned empty JSON body. Raw: ' . $raw_excerpt;
            } else {
                $last_error = 'Groups Settings API returned an empty body.';
            }
        }
        return [
            'ok' => false,
            'permissions' => [],
            'error' => $last_error,
        ];
    }
}

if (!function_exists('metis_people_workspace_read_group_permission_templates')) {
    function metis_people_workspace_read_group_permission_templates(): array {
        $defaults = metis_people_workspace_permission_template_defaults();
        $stored = [];
        if (class_exists('Core_Settings_Service')) {
            $raw = Core_Settings_Service::get(metis_people_workspace_permission_template_settings_key(), []);
            if (is_array($raw)) {
                $stored = $raw;
            }
        }
        foreach ($defaults as $key => $base) {
            $row = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $permissions = metis_people_workspace_group_permissions_sanitize((array) ($row['permissions'] ?? []));
            $permissions_full = metis_people_workspace_group_permissions_full_sanitize((array) ($row['permissions_full'] ?? $row['permissions'] ?? []));
            $defaults[$key] = [
                'label' => (string) ($base['label'] ?? ucfirst($key)),
                'permissions' => $permissions,
                'permissions_full' => !empty($permissions_full) ? $permissions_full : $permissions,
                'source' => metis_text_clean((string) ($row['source'] ?? '')),
                'captured_at' => metis_text_clean((string) ($row['captured_at'] ?? '')),
            ];
        }
        return $defaults;
    }
}

if (!function_exists('metis_people_workspace_write_group_permission_templates')) {
    function metis_people_workspace_write_group_permission_templates(array $templates): bool {
        if (!class_exists('Core_Settings_Service')) {
            return false;
        }
        return Core_Settings_Service::set(metis_people_workspace_permission_template_settings_key(), $templates, true);
    }
}

if (!function_exists('metis_people_workspace_find_template_group_row')) {
    function metis_people_workspace_find_template_group_row(string $template_key): ?array {
        $db = metis_db();
        $groups_table = Metis_Tables::get('people_workspace_groups');
        $needle = $template_key === 'board' ? 'board' : 'suppl';
        $row = $db->fetchOne(
            "SELECT id, group_name, group_email, metadata_json
             FROM {$groups_table}
             WHERE LOWER(group_name) LIKE %s OR LOWER(group_email) LIKE %s
             ORDER BY id ASC
             LIMIT 1",
            [ '%' . $needle . '%', '%' . $needle . '%' ]
        );
        return is_array($row) ? $row : null;
    }
}

metis_ajax_register_handler( 'metis_people_bulk_workspace_group_action', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();

    $people_table = Metis_Tables::get('people');
    $workspace_groups_table = Metis_Tables::get('people_workspace_groups');
    $workspace_users_table = Metis_Tables::get('people_workspace_users');
    $workspace_members_table = Metis_Tables::get('people_workspace_group_members');

    $action_type = isset(metis_request_post()['bulk_action']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['bulk_action'])) : '';
    $group_email = strtolower(trim((string) (isset(metis_request_post()['group_email']) ? metis_email_clean(metis_runtime_unslash(metis_request_post()['group_email'])) : '')));
    $member_role = isset(metis_request_post()['member_role']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['member_role'])) : 'member';
    if (!in_array($member_role, ['member', 'manager', 'owner'], true)) $member_role = 'member';
    $person_pids = [];
    if (isset(metis_request_post()['person_pids'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['person_pids']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $pid) {
                $clean = metis_text_clean((string) $pid);
                if ($clean !== '') $person_pids[] = $clean;
            }
        }
    }
    $person_pids = array_values(array_unique($person_pids));
    if (!in_array($action_type, ['assign', 'remove'], true) || !metis_email_is_valid($group_email) || empty($person_pids)) {
        metis_runtime_send_json_error('Group, action, and people are required.', 400);
    }
    $group_id = (int) $db->scalar("SELECT id FROM {$workspace_groups_table} WHERE group_email = %s LIMIT 1", [ $group_email ]);
    if ($group_id < 1) {
        metis_runtime_send_json_error('Workspace group not found.', 404);
    }

    $updated = 0;
    $skipped = 0;
    foreach ($person_pids as $pid) {
        $person = $db->fetchOne("SELECT id, workspace_email FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ]);
        $workspace_email = strtolower(trim((string) ($person['workspace_email'] ?? '')));
        if (!$person || !metis_email_is_valid($workspace_email)) {
            $skipped++;
            continue;
        }
        $workspace_user_id = (int) $db->scalar(
            "SELECT id FROM {$workspace_users_table} WHERE person_id = %d OR primary_email = %s LIMIT 1",
            [ (int) ($person['id'] ?? 0), $workspace_email ]
        );
        if ($workspace_user_id < 1) {
            $skipped++;
            continue;
        }
        if ($action_type === 'assign') {
            $existing_member_id = (int) $db->scalar(
                "SELECT id FROM {$workspace_members_table} WHERE group_id = %d AND workspace_user_id = %d LIMIT 1",
                [ $group_id, $workspace_user_id ]
            );
            if ($existing_member_id > 0) {
                $ok = $db->update(
                    $workspace_members_table,
                    ['member_role' => $member_role],
                    ['id' => $existing_member_id],
                    ['%s'],
                    ['%d']
                );
                if ($ok !== false) $updated++;
            } else {
                $ok = $db->insert($workspace_members_table, [
                    'group_id' => $group_id,
                    'workspace_user_id' => $workspace_user_id,
                    'member_role' => $member_role,
                ], ['%d', '%d', '%s']);
                if ($ok) $updated++;
            }
        } else {
            $ok = $db->delete($workspace_members_table, [
                'group_id' => $group_id,
                'workspace_user_id' => $workspace_user_id,
            ], ['%d', '%d']);
            if ($ok) $updated += (int) $ok;
        }
    }

    $db->execute(
        "UPDATE {$workspace_groups_table}
         SET direct_members_count = (SELECT COUNT(*) FROM {$workspace_members_table} WHERE group_id = %d),
             sync_status = 'queued'
         WHERE id = %d",
        [ $group_id, $group_id ]
    );
    $actor = metis_people_get_current_person_id();
    $job_id = metis_people_workspace_queue_job(
        'workspace_group_members_bulk_sync',
        'workspace_group',
        $group_id,
        $actor > 0 ? $actor : null,
        ['group_email' => $group_email, 'bulk_action' => $action_type, 'member_role' => $member_role, 'pids' => $person_pids]
    );
    metis_people_log_activity(null, 'bulk_workspace_group_action', 'Ran bulk Workspace group action', [
        'group_email' => $group_email,
        'bulk_action' => $action_type,
        'member_role' => $member_role,
        'updated' => $updated,
        'skipped' => $skipped,
        'job_id' => $job_id,
    ]);
    metis_runtime_send_json_success(['updated' => $updated, 'skipped' => $skipped, 'job_id' => $job_id]);
});

metis_ajax_register_handler( 'metis_people_workspace_save_group', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $group_id = isset(metis_request_post()['group_id']) ? (int) metis_runtime_unslash(metis_request_post()['group_id']) : 0;
    $group_email = strtolower(trim((string) (isset(metis_request_post()['group_email']) ? metis_email_clean(metis_runtime_unslash(metis_request_post()['group_email'])) : '')));
    $group_name = isset(metis_request_post()['group_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['group_name'])) : '';
    $description = isset(metis_request_post()['description']) ? metis_textarea_clean(metis_runtime_unslash(metis_request_post()['description'])) : '';
    if (!metis_email_is_valid($group_email) || $group_name === '') {
        metis_runtime_send_json_error('Group name and valid group email are required.', 400);
    }
    $conflict = (int) $db->scalar(
        "SELECT id FROM {$groups_table} WHERE group_email = %s AND id <> %d LIMIT 1",
        [ $group_email, $group_id ]
    );
    if ($conflict > 0) {
        metis_runtime_send_json_error('Group email already exists.', 400);
    }
    $payload = [
        'group_email' => $group_email,
        'group_name' => $group_name,
        'description' => $description !== '' ? $description : null,
        'sync_status' => 'queued',
    ];
    if ($group_id > 0) {
        $ok = $db->update($groups_table, $payload, ['id' => $group_id], ['%s', '%s', '%s', '%s'], ['%d']);
        if ($ok === false) metis_runtime_send_json_error('Failed to update group.', 500);
    } else {
        $ok = $db->insert($groups_table, $payload, ['%s', '%s', '%s', '%s']);
        if (!$ok) metis_runtime_send_json_error('Failed to create group.', 500);
        $group_id = (int) $db->lastInsertId();
    }
    $actor = metis_people_get_current_person_id();
    $job_id = metis_people_workspace_queue_job(
        'workspace_group_upsert',
        'workspace_group',
        $group_id,
        $actor > 0 ? $actor : null,
        ['group_email' => $group_email, 'group_name' => $group_name]
    );
    metis_people_log_activity(null, 'workspace_group_saved', 'Saved workspace group', [
        'group_id' => $group_id,
        'group_email' => $group_email,
        'job_id' => $job_id,
    ]);
    metis_runtime_send_json_success([
        'group_id' => $group_id,
        'job_id' => $job_id,
        'group' => [
            'id' => $group_id,
            'group_email' => $group_email,
            'group_name' => $group_name,
            'description' => $description,
        ],
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_add_group_member', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $users_table = Metis_Tables::get('people_workspace_users');
    $members_table = Metis_Tables::get('people_workspace_group_members');

    $group_id = isset(metis_request_post()['group_id']) ? (int) metis_runtime_unslash(metis_request_post()['group_id']) : 0;
    $member_email = strtolower(trim((string) (isset(metis_request_post()['member_email']) ? metis_email_clean(metis_runtime_unslash(metis_request_post()['member_email'])) : '')));
    $member_role = isset(metis_request_post()['member_role']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['member_role'])) : 'member';
    if (!in_array($member_role, ['member', 'manager', 'owner'], true)) $member_role = 'member';
    if ($group_id < 1 || !metis_email_is_valid($member_email)) {
        metis_runtime_send_json_error('Group and member email are required.', 400);
    }
    $group_exists = (int) $db->scalar("SELECT id FROM {$groups_table} WHERE id = %d LIMIT 1", [ $group_id ]);
    if ($group_exists < 1) metis_runtime_send_json_error('Group not found.', 404);
    $workspace_user_id = (int) $db->scalar("SELECT id FROM {$users_table} WHERE primary_email = %s LIMIT 1", [ $member_email ]);
    if ($workspace_user_id < 1) {
        metis_runtime_send_json_error('Workspace user not found for that email.', 404);
    }
    $exists = (int) $db->scalar(
        "SELECT id FROM {$members_table} WHERE group_id = %d AND workspace_user_id = %d LIMIT 1",
        [ $group_id, $workspace_user_id ]
    );
    if ($exists > 0) {
        $db->update($members_table, ['member_role' => $member_role], ['id' => $exists], ['%s'], ['%d']);
    } else {
        $db->insert($members_table, [
            'group_id' => $group_id,
            'workspace_user_id' => $workspace_user_id,
            'member_role' => $member_role,
        ], ['%d', '%d', '%s']);
    }
    $db->execute(
        "UPDATE {$groups_table}
         SET direct_members_count = (SELECT COUNT(*) FROM {$members_table} WHERE group_id = %d),
             sync_status = 'queued'
         WHERE id = %d",
        [ $group_id, $group_id ]
    );
    $actor = metis_people_get_current_person_id();
    $job_id = metis_people_workspace_queue_job(
        'workspace_group_member_upsert',
        'workspace_group',
        $group_id,
        $actor > 0 ? $actor : null,
        ['member_email' => $member_email, 'member_role' => $member_role]
    );
    metis_people_log_activity(null, 'workspace_group_member_saved', 'Saved workspace group member', [
        'group_id' => $group_id,
        'member_email' => $member_email,
        'member_role' => $member_role,
        'job_id' => $job_id,
    ]);
    metis_runtime_send_json_success(['group_id' => $group_id, 'job_id' => $job_id]);
});

metis_ajax_register_handler( 'metis_people_workspace_get_group_members_matrix', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $users_table = Metis_Tables::get('people_workspace_users');
    $members_table = Metis_Tables::get('people_workspace_group_members');

    $group_id = isset(metis_request_post()['group_id']) ? (int) metis_runtime_unslash(metis_request_post()['group_id']) : 0;
    if ($group_id < 1) {
        metis_runtime_send_json_error('Group is required.', 400);
    }
    $group = $db->fetchOne(
        "SELECT id, group_name, group_email, description
         FROM {$groups_table}
         WHERE id = %d
         LIMIT 1",
        [ $group_id ]
    );
    if (!$group) {
        metis_runtime_send_json_error('Group not found.', 404);
    }

    $users = $db->fetchAll(
        "SELECT id, primary_email, first_name, last_name, display_name, metadata_json
         FROM {$users_table}
         ORDER BY display_name ASC, first_name ASC, last_name ASC, primary_email ASC"
    ) ?: [];
    $roles_by_user_id = [];
    $member_rows = $db->fetchAll(
        "SELECT workspace_user_id, member_role
         FROM {$members_table}
         WHERE group_id = %d",
        [ $group_id ]
    ) ?: [];
    foreach ($member_rows as $row) {
        $workspace_user_id = (int) ($row['workspace_user_id'] ?? 0);
        $member_role = strtolower(trim((string) ($row['member_role'] ?? 'member')));
        if ($workspace_user_id < 1) continue;
        if (!in_array($member_role, ['member', 'manager', 'owner'], true)) $member_role = 'member';
        $roles_by_user_id[$workspace_user_id] = $member_role;
    }

    $remote_role_by_email = [];
    $cfg = metis_people_workspace_sync_settings();
    $group_email = strtolower(trim((string) ($group['group_email'] ?? '')));
    if (!empty($cfg['ok']) && metis_email_is_valid($group_email)) {
        $page_token = '';
        $page_guard = 0;
        while ($page_guard < 30) {
            $page_guard++;
            $query = 'groups/' . rawurlencode($group_email) . '/members?maxResults=100';
            if ($page_token !== '') $query .= '&pageToken=' . rawurlencode($page_token);
            $remote = metis_people_workspace_google_request('GET', $query, null, $cfg);
            if (empty($remote['ok'])) break;
            $items = (array) ($remote['body']['members'] ?? []);
            foreach ($items as $item) {
                $email = strtolower(trim((string) ($item['email'] ?? '')));
                $type = strtolower(trim((string) ($item['type'] ?? 'user')));
                if (!metis_email_is_valid($email) || $type === 'group') continue;
                $role = strtolower(trim((string) ($item['role'] ?? 'member')));
                if (!in_array($role, ['member', 'manager', 'owner'], true)) $role = 'member';
                $remote_role_by_email[$email] = $role;
            }
            $page_token = trim((string) ($remote['body']['nextPageToken'] ?? ''));
            if ($page_token === '') break;
        }
    }

    $out_users = [];
    foreach ($users as $user) {
        $workspace_user_id = (int) ($user['id'] ?? 0);
        if ($workspace_user_id < 1) continue;
        $metadata = json_decode((string) ($user['metadata_json'] ?? ''), true);
        if (is_array($metadata) && !empty($metadata['ui_hidden'])) continue;
        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        if ($name === '') $name = trim((string) ($user['display_name'] ?? ''));
        $primary_email = strtolower(trim((string) ($user['primary_email'] ?? '')));
        if ($name === '') $name = $primary_email;
        $remote_role = $primary_email !== '' ? (string) ($remote_role_by_email[$primary_email] ?? '') : '';
        $local_role = (string) ($roles_by_user_id[$workspace_user_id] ?? '');
        $resolved_role = $remote_role !== '' ? $remote_role : ($local_role !== '' ? $local_role : 'member');
        $in_group = $resolved_role !== '';
        if ($primary_email !== '' && isset($remote_role_by_email[$primary_email])) unset($remote_role_by_email[$primary_email]);
        $out_users[] = [
            'workspace_user_id' => $workspace_user_id,
            'name' => $name,
            'primary_email' => (string) ($user['primary_email'] ?? ''),
            'in_group' => $in_group ? 1 : 0,
            'member_role' => $resolved_role !== '' ? $resolved_role : 'member',
        ];
    }

    $external_members = [];
    $external_emails = array_keys($remote_role_by_email);
    $contact_name_by_email = [];
    $contact_cid_by_email = [];
    if (!empty($external_emails)) {
        $contacts_table = Metis_Tables::get('contacts');
        $in_placeholders = implode(',', array_fill(0, count($external_emails), '%s'));
        $contact_rows = $db->fetchAll(
            "SELECT email, first_name, last_name, cid
             FROM {$contacts_table}
             WHERE email IN ({$in_placeholders})",
            $external_emails
        ) ?: [];
        foreach ($contact_rows as $contact_row) {
            $email_key = strtolower(trim((string) ($contact_row['email'] ?? '')));
            if ($email_key === '') continue;
            $contact_name = trim((string) ($contact_row['first_name'] ?? '') . ' ' . (string) ($contact_row['last_name'] ?? ''));
            if ($contact_name !== '') $contact_name_by_email[$email_key] = $contact_name;
            $contact_cid = trim((string) ($contact_row['cid'] ?? ''));
            if ($contact_cid !== '') $contact_cid_by_email[$email_key] = $contact_cid;
        }
    }
    foreach ($remote_role_by_email as $email => $role) {
        $external_members[] = [
            'member_email' => (string) $email,
            'member_role' => (string) $role,
            'resolved_name' => (string) ($contact_name_by_email[$email] ?? ''),
            'contact_cid' => (string) ($contact_cid_by_email[$email] ?? ''),
        ];
    }

    metis_runtime_send_json_success([
        'group' => [
            'id' => (int) ($group['id'] ?? 0),
            'group_name' => (string) ($group['group_name'] ?? ''),
            'group_email' => (string) ($group['group_email'] ?? ''),
            'description' => (string) ($group['description'] ?? ''),
        ],
        'users' => $out_users,
        'external_members' => $external_members,
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_save_group_members_bulk', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $users_table = Metis_Tables::get('people_workspace_users');
    $members_table = Metis_Tables::get('people_workspace_group_members');

    $group_id = isset(metis_request_post()['group_id']) ? (int) metis_runtime_unslash(metis_request_post()['group_id']) : 0;
    if ($group_id < 1) metis_runtime_send_json_error('Group is required.', 400);
    $group = $db->fetchOne(
        "SELECT id, group_email
         FROM {$groups_table}
         WHERE id = %d
         LIMIT 1",
        [ $group_id ]
    );
    if (!$group) metis_runtime_send_json_error('Group not found.', 404);

    $members_json = isset(metis_request_post()['members']) ? (string) metis_runtime_unslash(metis_request_post()['members']) : '[]';
    $decoded_members = json_decode($members_json, true);
    if (!is_array($decoded_members)) $decoded_members = [];

    $valid_user_ids = [];
    $email_by_user_id = [];
    $candidate_user_ids = [];
    foreach ($decoded_members as $member) {
        if (!is_array($member)) continue;
        $workspace_user_id = isset($member['workspace_user_id']) ? (int) $member['workspace_user_id'] : 0;
        if ($workspace_user_id > 0) $candidate_user_ids[] = $workspace_user_id;
    }
    $candidate_user_ids = array_values(array_unique($candidate_user_ids));
    if (!empty($candidate_user_ids)) {
        $placeholders = implode(',', array_fill(0, count($candidate_user_ids), '%d'));
        $rows = $db->fetchAll(
            "SELECT id, primary_email FROM {$users_table} WHERE id IN ({$placeholders})",
            $candidate_user_ids
        ) ?: [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) continue;
            $valid_user_ids[$id] = true;
            $email = strtolower(trim((string) ($row['primary_email'] ?? '')));
            if (metis_email_is_valid($email)) $email_by_user_id[$id] = $email;
        }
    }

    $to_insert = [];
    $desired_members = [];
    foreach ($decoded_members as $member) {
        if (!is_array($member)) continue;
        $workspace_user_id = isset($member['workspace_user_id']) ? (int) $member['workspace_user_id'] : 0;
        $member_email = strtolower(trim((string) ($member['member_email'] ?? '')));
        $member_role = strtolower(trim((string) ($member['member_role'] ?? 'member')));
        if (!in_array($member_role, ['member', 'manager', 'owner'], true)) $member_role = 'member';
        if ($workspace_user_id > 0 && !empty($valid_user_ids[$workspace_user_id])) {
            $to_insert[$workspace_user_id] = $member_role;
            $email_for_user = strtolower(trim((string) ($email_by_user_id[$workspace_user_id] ?? '')));
            if (metis_email_is_valid($email_for_user)) $desired_members[$email_for_user] = $member_role;
            continue;
        }
        if (metis_email_is_valid($member_email)) {
            $desired_members[$member_email] = $member_role;
        }
    }

    $db->delete($members_table, ['group_id' => $group_id], ['%d']);
    $inserted_count = 0;
    foreach ($to_insert as $workspace_user_id => $member_role) {
        $inserted = $db->insert($members_table, [
            'group_id' => $group_id,
            'workspace_user_id' => (int) $workspace_user_id,
            'member_role' => $member_role,
        ], ['%d', '%d', '%s']);
        if ($inserted) $inserted_count++;
    }
    $db->update(
        $groups_table,
        ['direct_members_count' => $inserted_count, 'sync_status' => 'queued'],
        ['id' => $group_id],
        ['%d', '%s'],
        ['%d']
    );

    $cfg = metis_people_workspace_sync_settings();
    $group_email = strtolower(trim((string) ($group['group_email'] ?? '')));
    if (!empty($cfg['ok']) && metis_email_is_valid($group_email)) {
        $remote_existing = [];
        $page_token = '';
        $page_guard = 0;
        while ($page_guard < 30) {
            $page_guard++;
            $query = 'groups/' . rawurlencode($group_email) . '/members?maxResults=100';
            if ($page_token !== '') $query .= '&pageToken=' . rawurlencode($page_token);
            $remote = metis_people_workspace_google_request('GET', $query, null, $cfg);
            if (empty($remote['ok'])) break;
            $rows = (array) ($remote['body']['members'] ?? []);
            foreach ($rows as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $type = strtolower(trim((string) ($row['type'] ?? 'user')));
                if (!metis_email_is_valid($email) || $type === 'group') continue;
                $remote_existing[$email] = true;
            }
            $page_token = trim((string) ($remote['body']['nextPageToken'] ?? ''));
            if ($page_token === '') break;
        }
        foreach (array_keys($remote_existing) as $remote_email) {
            if (isset($desired_members[$remote_email])) continue;
            metis_people_workspace_google_request(
                'DELETE',
                'groups/' . rawurlencode($group_email) . '/members/' . rawurlencode($remote_email),
                null,
                $cfg
            );
        }
        foreach ($desired_members as $desired_email => $desired_role) {
            $role = strtoupper($desired_role);
            if (!in_array($role, ['MEMBER', 'MANAGER', 'OWNER'], true)) $role = 'MEMBER';
            $payload_body = ['email' => $desired_email, 'role' => $role];
            $create = metis_people_workspace_google_request('POST', 'groups/' . rawurlencode($group_email) . '/members', $payload_body, $cfg);
            if (empty($create['ok'])) {
                metis_people_workspace_google_request('PUT', 'groups/' . rawurlencode($group_email) . '/members/' . rawurlencode($desired_email), $payload_body, $cfg);
            }
        }
        $db->update($groups_table, ['sync_status' => 'synced'], ['id' => $group_id], ['%s'], ['%d']);
    }

    $actor = metis_people_get_current_person_id();
    $job_id = metis_people_workspace_queue_job(
        'workspace_group_members_bulk_sync',
        'workspace_group',
        $group_id,
        $actor > 0 ? $actor : null,
        ['group_email' => (string) ($group['group_email'] ?? ''), 'member_count' => $inserted_count]
    );
    metis_people_log_activity(null, 'workspace_group_members_bulk_saved', 'Saved workspace group members in bulk', [
        'group_id' => $group_id,
        'member_count' => $inserted_count,
        'job_id' => $job_id,
    ]);
    metis_runtime_send_json_success(['group_id' => $group_id, 'member_count' => $inserted_count, 'job_id' => $job_id]);
});

metis_ajax_register_handler( 'metis_people_workspace_get_group_permissions', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $group_id = isset(metis_request_post()['group_id']) ? (int) metis_runtime_unslash(metis_request_post()['group_id']) : 0;
    if ($group_id < 1) metis_runtime_send_json_error('Group is required.', 400);

    $group = $db->fetchOne("SELECT id, group_email, metadata_json FROM {$groups_table} WHERE id = %d LIMIT 1", [ $group_id ]);
    if (!$group) metis_runtime_send_json_error('Group not found.', 404);

    $permissions = [
        'whoCanJoin' => 'INVITED_CAN_JOIN',
        'whoCanViewMembership' => 'ALL_MEMBERS_CAN_VIEW',
        'whoCanPostMessage' => 'ALL_MEMBERS_CAN_POST',
        'allowExternalMembers' => 'false',
    ];
    $permissions_full = $permissions;
    $metadata = json_decode((string) ($group['metadata_json'] ?? ''), true);
    if (is_array($metadata)) {
        if (!empty($metadata['permissions_full']) && is_array($metadata['permissions_full'])) {
            $permissions_full = metis_people_workspace_group_permissions_full_sanitize((array) $metadata['permissions_full']);
            $permissions = metis_people_workspace_group_permissions_sanitize($permissions_full);
        } elseif (!empty($metadata['permissions']) && is_array($metadata['permissions'])) {
            $permissions = metis_people_workspace_group_permissions_sanitize((array) $metadata['permissions']);
            $permissions_full = $permissions;
        }
    }
    $source = !empty($permissions_full) ? 'metadata' : 'default';
    $load_warning = '';
    $remote_full = metis_people_workspace_fetch_group_permissions_full((string) ($group['group_email'] ?? ''));
    if (!empty($remote_full['ok'])) {
        $remote_payload = !empty($remote_full['permissions']) && is_array($remote_full['permissions'])
            ? (array) $remote_full['permissions']
            : [];
        $permissions_full = $remote_payload;
        $source = 'remote';
        $permissions = metis_people_workspace_group_permissions_sanitize($permissions_full);
    } elseif (!empty($remote_full['error'])) {
        $load_warning = (string) $remote_full['error'] . ' Using last saved local settings.';
        $source = !empty($permissions_full) ? 'metadata_fallback' : 'default_fallback';
    }

    metis_runtime_send_json_success([
        'permissions' => $permissions,
        'permissions_full' => $permissions_full,
        'source' => $source,
        'load_warning' => $load_warning,
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_save_group_permissions', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $group_id = isset(metis_request_post()['group_id']) ? (int) metis_runtime_unslash(metis_request_post()['group_id']) : 0;
    if ($group_id < 1) metis_runtime_send_json_error('Group is required.', 400);
    $group = $db->fetchOne("SELECT id, group_email, metadata_json FROM {$groups_table} WHERE id = %d LIMIT 1", [ $group_id ]);
    if (!$group) metis_runtime_send_json_error('Group not found.', 404);

    $permissions_payload = [];
    if (isset(metis_request_post()['permissions'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['permissions']), true);
        if (is_array($decoded)) $permissions_payload = $decoded;
    }
    $permissions = metis_people_workspace_group_permissions_sanitize($permissions_payload);
    $permissions_full_payload = [];
    if (isset(metis_request_post()['permissions_full'])) {
        $decoded_full = json_decode((string) metis_runtime_unslash(metis_request_post()['permissions_full']), true);
        if (is_array($decoded_full)) {
            $permissions_full_payload = $decoded_full;
        }
    }
    $permissions_full = metis_people_workspace_group_permissions_full_sanitize($permissions_full_payload);
    if (empty($permissions_full)) {
        $permissions_full = $permissions;
    } else {
        foreach ($permissions as $k => $v) {
            $permissions_full[$k] = $v;
        }
    }

    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Workspace configuration is missing.', 400);
    }
    $cfg_groups = $cfg;
    $cfg_groups['scopes'] = array_values(array_unique(array_merge(
        (array) ($cfg['scopes'] ?? []),
        ['https://www.googleapis.com/auth/apps.groups.settings']
    )));
    $group_email = strtolower(trim((string) ($group['group_email'] ?? '')));
    if (!metis_email_is_valid($group_email)) metis_runtime_send_json_error('Group email is invalid.', 400);

    $remote = metis_people_workspace_google_request(
        'PUT',
        'https://www.googleapis.com/groups/v1/groups/' . rawurlencode($group_email),
        $permissions_full,
        $cfg_groups
    );
    if (empty($remote['ok'])) {
        metis_runtime_send_json_error('Failed to update group permissions in Workspace.', 400);
    }

    $metadata = json_decode((string) ($group['metadata_json'] ?? ''), true);
    if (!is_array($metadata)) $metadata = [];
    $metadata['permissions'] = $permissions;
    $metadata['permissions_full'] = $permissions_full;
    $db->update(
        $groups_table,
        ['metadata_json' => metis_json_encode($metadata), 'sync_status' => 'synced'],
        ['id' => $group_id],
        ['%s', '%s'],
        ['%d']
    );
    metis_runtime_send_json_success(['group_id' => $group_id, 'permissions' => $permissions, 'permissions_full' => $permissions_full]);
});

metis_ajax_register_handler( 'metis_people_workspace_get_group_permission_templates', function () {
    metis_people_workspace_ajax_verify();
    metis_runtime_send_json_success([
        'templates' => metis_people_workspace_read_group_permission_templates(),
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_capture_group_permission_templates', function () {
    metis_people_workspace_ajax_verify();
    $templates = metis_people_workspace_read_group_permission_templates();
    $captured = [];
    $missing = [];
    $target_keys = ['board', 'supplies'];

    foreach ($target_keys as $key) {
        $group = metis_people_workspace_find_template_group_row($key);
        if (!$group) {
            $missing[] = $key;
            continue;
        }
        $metadata = json_decode((string) ($group['metadata_json'] ?? ''), true);
        $permissions = [];
        if (is_array($metadata) && is_array($metadata['permissions'] ?? null)) {
            $permissions = (array) $metadata['permissions'];
        }
        if (is_array($metadata) && is_array($metadata['permissions_full'] ?? null)) {
            $permissions = (array) $metadata['permissions_full'];
        }
        if (empty($permissions)) {
            $remote_full = metis_people_workspace_fetch_group_permissions_full((string) ($group['group_email'] ?? ''));
            if (!empty($remote_full['ok']) && !empty($remote_full['permissions']) && is_array($remote_full['permissions'])) {
                $permissions = (array) $remote_full['permissions'];
            }
        }
        $permissions_full = metis_people_workspace_group_permissions_full_sanitize((array) $permissions);
        $permissions_basic = metis_people_workspace_group_permissions_sanitize($permissions_full);
        $templates[$key]['permissions'] = metis_people_workspace_group_permissions_sanitize($permissions);
        $templates[$key]['permissions_full'] = !empty($permissions_full) ? $permissions_full : $permissions_basic;
        $templates[$key]['source'] = trim((string) ($group['group_name'] ?? '')) . ' <' . trim((string) ($group['group_email'] ?? '')) . '>';
        $templates[$key]['captured_at'] = metis_current_time('mysql');
        $captured[] = $key;
    }

    if (!metis_people_workspace_write_group_permission_templates($templates)) {
        metis_runtime_send_json_error('Failed to save templates.', 500);
    }

    metis_runtime_send_json_success([
        'templates' => $templates,
        'captured' => $captured,
        'missing' => $missing,
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_delete_group', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $members_table = Metis_Tables::get('people_workspace_group_members');
    $group_id = isset(metis_request_post()['group_id']) ? (int) metis_runtime_unslash(metis_request_post()['group_id']) : 0;
    if ($group_id < 1) metis_runtime_send_json_error('Group is required.', 400);
    $group = $db->fetchOne("SELECT id, group_email FROM {$groups_table} WHERE id = %d LIMIT 1", [ $group_id ]);
    if (!$group) metis_runtime_send_json_error('Group not found.', 404);
    $group_email = strtolower(trim((string) ($group['group_email'] ?? '')));

    $cfg = metis_people_workspace_sync_settings();
    if (!empty($cfg['ok']) && metis_email_is_valid($group_email)) {
        metis_people_workspace_google_request('DELETE', 'groups/' . rawurlencode($group_email), null, $cfg);
    }
    $db->delete($members_table, ['group_id' => $group_id], ['%d']);
    $deleted = $db->delete($groups_table, ['id' => $group_id], ['%d']);
    if (!$deleted) metis_runtime_send_json_error('Failed to delete group.', 500);
    metis_people_log_activity(null, 'workspace_group_deleted', 'Deleted workspace group', [
        'group_id' => $group_id,
        'group_email' => $group_email,
    ]);
    metis_runtime_send_json_success(['group_id' => $group_id]);
});
