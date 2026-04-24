<?php
if (!defined('METIS_ROOT')) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $actions = [
        'metis_people_workspace_save_user',
        'metis_people_workspace_set_user_flags',
        'metis_people_workspace_delete_user',
        'metis_people_workspace_create_metis_user',
        'metis_people_workspace_run_security_action',
        'metis_people_bulk_workspace_user_action',
        'metis_people_attach_drive_folder',
        'metis_people_drive_folder_picker',
        'metis_people_attach_drive_folder_selection',
        'metis_people_workspace_process_queue',
        'metis_people_workspace_import_directory_users',
        'metis_people_workspace_full_sync_directory',
        'metis_people_workspace_get_role_map',
        'metis_people_workspace_inspect_user_attributes',
        'metis_people_workspace_get_activity_page',
    ];
    foreach ( $actions as $action ) {
        metis_ajax_register_controller( $action, [
            'module' => 'people',
            'permission' => 'edit',
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
}

if (!function_exists('metis_people_workspace_role_keys_for_user')) {
    function metis_people_workspace_role_keys_for_user(int $workspace_user_id): array {
        if ($workspace_user_id < 1) return [];
        $db = metis_db();
        $user_roles_table = Metis_Tables::get('people_workspace_user_roles');
        $rows = $db->fetchAll(
            "SELECT role_key
             FROM {$user_roles_table}
             WHERE workspace_user_id = %d",
            [ $workspace_user_id ]
        ) ?: [];
        $keys = [];
        foreach ($rows as $row) {
            $role_key = metis_key_clean((string) ($row['role_key'] ?? ''));
            if ($role_key !== '') $keys[] = $role_key;
        }
        return array_values(array_unique($keys));
    }
}

if (!function_exists('metis_people_workspace_log_type_labels')) {
    function metis_people_workspace_log_type_labels(): array {
        return [
            'job' => [
                'workspace_user_create' => 'Create Workspace user',
                'workspace_user_upsert' => 'Update Workspace user',
                'workspace_security_action' => 'Run security action',
                'workspace_group_create' => 'Create Workspace group',
                'workspace_group_upsert' => 'Update Workspace group',
                'workspace_group_member_upsert' => 'Update group member',
                'workspace_group_members_sync' => 'Sync group members',
                'workspace_group_members_bulk_sync' => 'Sync group members',
                'workspace_group_permissions_sync' => 'Sync group permissions',
                'workspace_directory_import' => 'Import directory users',
                'stripe_user_upsert' => 'Apply Stripe access',
                'stripe_user_disable' => 'Remove Stripe access',
            ],
            'security' => [
                'reset_password' => 'Force password reset',
                'revoke_sessions' => 'Revoke sessions',
                'force_2fa_reenroll' => 'Reset 2FA enrollment',
                'suspend_account' => 'Suspend account',
                'unsuspend_account' => 'Unsuspend account',
            ],
            'status' => [
                'queued' => 'Queued',
                'processing' => 'In Progress',
                'pending' => 'Pending',
                'completed' => 'Completed',
                'synced' => 'Completed',
                'failed' => 'Failed',
            ],
        ];
    }
}

if (!function_exists('metis_people_workspace_status_chip_class')) {
    function metis_people_workspace_status_chip_class(string $status): string {
        $key = strtolower(trim($status));
        if (in_array($key, ['completed', 'synced'], true)) return 'mw-chip-success';
        if ($key === 'failed') return 'mw-chip-danger';
        return '';
    }
}

if (!function_exists('metis_people_workspace_format_status')) {
    function metis_people_workspace_format_status(string $status, array $status_labels): string {
        $key = strtolower(trim($status));
        if ($key === '') return 'Unknown';
        return (string) ($status_labels[$key] ?? ucwords(str_replace(['_', '-'], ' ', $key)));
    }
}

if (!function_exists('metis_people_workspace_format_time')) {
    function metis_people_workspace_format_time(string $value): string {
        $raw = trim($value);
        if ($raw === '') return 'Unknown time';
        $ts = strtotime($raw);
        if (!$ts) return $raw;
        return date('M j, Y g:i a', $ts);
    }
}

if (!function_exists('metis_people_workspace_activity_payload')) {
    function metis_people_workspace_activity_payload(int $sync_page = 1, int $security_page = 1, int $sync_page_size = 12, int $security_page_size = 12): array {
        $db = metis_db();
        $workspace_users_table = Metis_Tables::get('people_workspace_users');
        $workspace_groups_table = Metis_Tables::get('people_workspace_groups');
        $workspace_security_actions_table = Metis_Tables::get('people_workspace_security_actions');
        $workspace_sync_jobs_table = Metis_Tables::get('people_workspace_sync_jobs');
        $people_table = Metis_Tables::get('people');
        $labels = metis_people_workspace_log_type_labels();
        $job_type_labels = (array) ($labels['job'] ?? []);
        $security_action_labels = (array) ($labels['security'] ?? []);
        $status_labels = (array) ($labels['status'] ?? []);

        if ($sync_page < 1) $sync_page = 1;
        if ($security_page < 1) $security_page = 1;
        if ($sync_page_size < 1) $sync_page_size = 12;
        if ($security_page_size < 1) $security_page_size = 12;

        $sync_total = (int) $db->scalar("SELECT COUNT(*) FROM {$workspace_sync_jobs_table}");
        $security_total = (int) $db->scalar("SELECT COUNT(*) FROM {$workspace_security_actions_table}");
        $sync_total_pages = max(1, (int) ceil($sync_total / $sync_page_size));
        $security_total_pages = max(1, (int) ceil($security_total / $security_page_size));
        if ($sync_page > $sync_total_pages) $sync_page = $sync_total_pages;
        if ($security_page > $security_total_pages) $security_page = $security_total_pages;
        $sync_offset = ($sync_page - 1) * $sync_page_size;
        $security_offset = ($security_page - 1) * $security_page_size;

        $sync_jobs = $db->fetchAll(
            "SELECT *
             FROM {$workspace_sync_jobs_table}
             ORDER BY created_at DESC
             LIMIT {$sync_page_size} OFFSET {$sync_offset}"
        ) ?: [];
        $security_actions = $db->fetchAll(
            "SELECT sa.*, wu.primary_email, wu.display_name, wu.person_id,
                    p.pid AS person_pid, p.display_name AS person_display_name
             FROM {$workspace_security_actions_table} sa
             INNER JOIN {$workspace_users_table} wu ON wu.id = sa.workspace_user_id
             LEFT JOIN {$people_table} p ON p.id = wu.person_id
             ORDER BY sa.created_at DESC
             LIMIT {$security_page_size} OFFSET {$security_offset}"
        ) ?: [];

        $normalize_person_name = static function (string $name): string {
            $trimmed = trim($name);
            if ($trimmed === '') return '';
            return trim((string) preg_replace('/\s*\([A-Z]{2,6}-\d+\)\s*$/', '', $trimmed));
        };

        $sync_workspace_user_names = [];
        $sync_workspace_group_names = [];
        $sync_person_names = [];
        $sync_workspace_user_urls = [];
        $sync_person_urls = [];
        $sync_workspace_user_ids = [];
        $sync_workspace_group_ids = [];
        $sync_person_ids = [];
        foreach ($sync_jobs as $sync_job_row) {
            $entity_type = strtolower(trim((string) ($sync_job_row['entity_type'] ?? '')));
            $entity_id = (int) ($sync_job_row['entity_id'] ?? 0);
            if ($entity_id < 1) continue;
            if ($entity_type === 'workspace_user') $sync_workspace_user_ids[$entity_id] = true;
            if ($entity_type === 'workspace_group') $sync_workspace_group_ids[$entity_id] = true;
            if ($entity_type === 'person') $sync_person_ids[$entity_id] = true;
        }
        if (!empty($sync_workspace_user_ids)) {
            $ids = implode(',', array_map('intval', array_keys($sync_workspace_user_ids)));
            $rows = $db->fetchAll(
                "SELECT wu.id, wu.primary_email, wu.display_name, wu.first_name, wu.last_name,
                        p.pid AS person_pid, p.display_name AS person_display_name,
                        p.first_name AS person_first_name, p.last_name AS person_last_name
                 FROM {$workspace_users_table} wu
                 LEFT JOIN {$people_table} p ON p.id = wu.person_id
                 WHERE wu.id IN ({$ids})"
            ) ?: [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) continue;
                $person_pid = trim((string) ($row['person_pid'] ?? ''));
                $person_display = $normalize_person_name(trim((string) ($row['person_display_name'] ?? '')));
                $person_name = trim((string) ($row['person_first_name'] ?? '') . ' ' . (string) ($row['person_last_name'] ?? ''));
                if ($person_name === '') $person_name = $person_display;
                if ($person_display !== '') {
                    $sync_workspace_user_names[$id] = $person_name;
                    if ($person_pid !== '') $sync_workspace_user_urls[$id] = metis_people_person_url($person_pid);
                    continue;
                }
                $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                if ($name === '') $name = trim((string) ($row['display_name'] ?? ''));
                if ($name === '') $name = 'Workspace user';
                $sync_workspace_user_names[$id] = $name;
            }
        }
        if (!empty($sync_workspace_group_ids)) {
            $ids = implode(',', array_map('intval', array_keys($sync_workspace_group_ids)));
            $rows = $db->fetchAll(
                "SELECT id, group_name, group_email
                 FROM {$workspace_groups_table}
                 WHERE id IN ({$ids})"
            ) ?: [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) continue;
                $group_name = trim((string) ($row['group_name'] ?? ''));
                $group_email = trim((string) ($row['group_email'] ?? ''));
                if ($group_name === '') $group_name = $group_email;
                $sync_workspace_group_names[$id] = $group_name !== '' ? $group_name : 'Workspace group';
            }
        }
        if (!empty($sync_person_ids)) {
            $ids = implode(',', array_map('intval', array_keys($sync_person_ids)));
            $rows = $db->fetchAll(
                "SELECT id, pid, display_name, first_name, last_name
                 FROM {$people_table}
                 WHERE id IN ({$ids})"
            ) ?: [];
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id < 1) continue;
                $display_name = $normalize_person_name(trim((string) ($row['display_name'] ?? '')));
                $person_name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                if ($person_name === '') $person_name = $display_name;
                $pid = trim((string) ($row['pid'] ?? ''));
                if ($person_name === '') $person_name = 'Person';
                $sync_person_names[$id] = $person_name;
                if ($pid !== '') $sync_person_urls[$id] = metis_people_person_url($pid);
            }
        }

        $format_entity = static function (?string $entity_type, $entity_id) use ($sync_workspace_user_names, $sync_workspace_group_names, $sync_person_names): string {
            $type = strtolower(trim((string) $entity_type));
            $id = (int) $entity_id;
            if ($type === 'workspace_user') return $id > 0 ? ($sync_workspace_user_names[$id] ?? 'Workspace user') : 'Workspace user';
            if ($type === 'workspace_group') return $id > 0 ? ($sync_workspace_group_names[$id] ?? 'Workspace group') : 'Workspace group';
            if ($type === 'person') return $id > 0 ? ($sync_person_names[$id] ?? 'Person') : 'Person';
            return $type !== '' ? ucwords(str_replace('_', ' ', $type)) : 'Entity';
        };
        $format_entity_url = static function (?string $entity_type, $entity_id) use ($sync_workspace_user_urls, $sync_person_urls): string {
            $type = strtolower(trim((string) $entity_type));
            $id = (int) $entity_id;
            if ($id < 1) return '';
            if ($type === 'person') return (string) ($sync_person_urls[$id] ?? '');
            if ($type === 'workspace_user') return (string) ($sync_workspace_user_urls[$id] ?? '');
            return '';
        };

        $sync_rows_out = [];
        foreach ($sync_jobs as $j) {
            $job_type = strtolower(trim((string) ($j['job_type'] ?? '')));
            $job_status = strtolower(trim((string) ($j['status'] ?? 'queued')));
            $job_title = (string) ($job_type_labels[$job_type] ?? ucwords(str_replace('_', ' ', $job_type)));
            $job_time = metis_people_workspace_format_time((string) ($j['created_at'] ?? ''));
            $job_error = trim((string) ($j['last_error'] ?? ''));
            $is_stripe_skip = $job_type === 'stripe_user_disable' && $job_status === 'failed' && stripos($job_error, 'workspace email not set') !== false;
            $effective_job_status = $is_stripe_skip && $job_status === 'failed' ? 'completed' : $job_status;
            if ($is_stripe_skip) $job_error = '';
            $sync_rows_out[] = [
                'title' => $job_title,
                'error' => $job_error !== '' ? ('Failed: ' . substr($job_error, 0, 160)) : '',
                'entity_label' => $format_entity((string) ($j['entity_type'] ?? ''), (int) ($j['entity_id'] ?? 0)),
                'entity_url' => $format_entity_url((string) ($j['entity_type'] ?? ''), (int) ($j['entity_id'] ?? 0)),
                'status_label' => metis_people_workspace_format_status($effective_job_status, $status_labels),
                'status_class' => metis_people_workspace_status_chip_class($effective_job_status),
                'time' => $job_time,
            ];
        }

        $security_rows_out = [];
        foreach ($security_actions as $s) {
            $action_type = strtolower(trim((string) ($s['action_type'] ?? '')));
            $action_status = strtolower(trim((string) ($s['status'] ?? 'pending')));
            $action_title = (string) ($security_action_labels[$action_type] ?? ucwords(str_replace('_', ' ', $action_type)));
            $security_name = trim((string) ($s['person_display_name'] ?? ''));
            if ($security_name === '') $security_name = trim((string) ($s['display_name'] ?? ''));
            $security_name = $normalize_person_name($security_name);
            if ($security_name === '') $security_name = 'Workspace user';
            $security_pid = trim((string) ($s['person_pid'] ?? ''));
            $security_url = $security_pid !== '' ? metis_people_person_url($security_pid) : '';
            $reason = trim((string) ($s['reason'] ?? ''));
            if ($reason === 'bulk_workspace_action') $reason = 'Bulk update';
            if ($reason === 'person_offboarded') $reason = 'Offboarding';
            if ($reason === 'workspace_or_status_ineligible') $reason = '';
            $security_rows_out[] = [
                'title' => $action_title,
                'reason' => $reason,
                'user_name' => $security_name,
                'user_url' => $security_url,
                'status_label' => metis_people_workspace_format_status($action_status, $status_labels),
                'status_class' => metis_people_workspace_status_chip_class($action_status),
                'time' => metis_people_workspace_format_time((string) ($s['created_at'] ?? '')),
            ];
        }

        return [
            'sync' => [
                'rows' => $sync_rows_out,
                'page' => $sync_page,
                'total_pages' => $sync_total_pages,
                'has_prev' => $sync_page > 1,
                'has_next' => $sync_page < $sync_total_pages,
                'prev_page' => $sync_page > 1 ? ($sync_page - 1) : 1,
                'next_page' => $sync_page < $sync_total_pages ? ($sync_page + 1) : $sync_total_pages,
            ],
            'security' => [
                'rows' => $security_rows_out,
                'page' => $security_page,
                'total_pages' => $security_total_pages,
                'has_prev' => $security_page > 1,
                'has_next' => $security_page < $security_total_pages,
                'prev_page' => $security_page > 1 ? ($security_page - 1) : 1,
                'next_page' => $security_page < $security_total_pages ? ($security_page + 1) : $security_total_pages,
            ],
        ];
    }
}

if (!function_exists('metis_people_workspace_autocreate_drive_folder')) {
    function metis_people_workspace_autocreate_drive_folder(int $person_id): array {
        if ($person_id < 1) {
            return ['ok' => false, 'created' => false, 'error' => 'Invalid person id.'];
        }
        if (
            !function_exists('metis_drive_workspace_settings')
            || !function_exists('metis_drive_find_or_create_user_folder')
            || !function_exists('metis_drive_ensure_schema')
            || !function_exists('metis_drive_log_action')
        ) {
            return ['ok' => false, 'created' => false, 'error' => 'Drive module is not available.'];
        }

        $cfg = metis_drive_workspace_settings();
        if (empty($cfg['ok'])) {
            return ['ok' => false, 'created' => false, 'error' => 'Drive is not configured.'];
        }

        metis_drive_ensure_schema();
        $folder = metis_drive_find_or_create_user_folder($cfg, $person_id, true);
        if (empty($folder['ok']) || empty($folder['folder_id'])) {
            return ['ok' => false, 'created' => false, 'error' => 'Failed to create Drive folder.'];
        }

        if (!empty($folder['created'])) {
            metis_drive_log_action($cfg, 'create_user_folder', [
                'folder_id' => (string) ($folder['folder_id'] ?? ''),
                'item_name' => (string) ($folder['folder_name'] ?? ''),
                'item_type' => 'folder',
                'details' => ['person_id' => $person_id, 'source' => 'workspace_autocreate'],
            ]);
        }

        $folder_url = '';
        if (function_exists('metis_portal_url')) {
            $folder_url = metis_add_query_arg(
                ['folder_id' => (string) $folder['folder_id']],
                metis_portal_url('drive', 'dashboard')
            );
        }

        return [
            'ok' => true,
            'created' => !empty($folder['created']),
            'folder_id' => (string) ($folder['folder_id'] ?? ''),
            'folder_name' => (string) ($folder['folder_name'] ?? ''),
            'folder_url' => $folder_url,
        ];
    }
}

metis_ajax_register_handler( 'metis_people_workspace_save_user', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $users_table = Metis_Tables::get('people_workspace_users');
    $user_roles_table = Metis_Tables::get('people_workspace_user_roles');
    $people_table = Metis_Tables::get('people');

    $workspace_user_id = isset($_POST['workspace_user_id']) ? (int) metis_runtime_unslash($_POST['workspace_user_id']) : 0;
    $primary_email = strtolower(trim((string) (isset($_POST['primary_email']) ? metis_email_clean(metis_runtime_unslash($_POST['primary_email'])) : '')));
    $first_name = isset($_POST['first_name']) ? metis_text_clean(metis_runtime_unslash($_POST['first_name'])) : '';
    $last_name = isset($_POST['last_name']) ? metis_text_clean(metis_runtime_unslash($_POST['last_name'])) : '';
    $display_name = isset($_POST['display_name']) ? metis_text_clean(metis_runtime_unslash($_POST['display_name'])) : '';
    $org_unit_path = isset($_POST['org_unit_path']) ? metis_text_clean(metis_runtime_unslash($_POST['org_unit_path'])) : '/';
    $secondary_email = strtolower(trim((string) (isset($_POST['secondary_email']) ? metis_email_clean(metis_runtime_unslash($_POST['secondary_email'])) : '')));
    $recovery_email = strtolower(trim((string) (isset($_POST['recovery_email']) ? metis_email_clean(metis_runtime_unslash($_POST['recovery_email'])) : '')));
    if ($recovery_email === '' && $secondary_email !== '') {
        $recovery_email = $secondary_email;
    }
    $linked_pid = strtoupper(trim((string) (isset($_POST['linked_pid']) ? metis_text_clean(metis_runtime_unslash($_POST['linked_pid'])) : '')));
    $is_suspended = !empty($_POST['is_suspended']) ? 1 : 0;
    $is_protected = !empty($_POST['is_protected']) ? 1 : 0;
    $is_hidden = !empty($_POST['is_hidden']) ? 1 : 0;

    $role_keys = [];
    if (isset($_POST['role_keys'])) {
        $decoded = json_decode((string) metis_runtime_unslash($_POST['role_keys']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $key) {
                $rk = metis_key_clean((string) $key);
                if ($rk !== '') $role_keys[] = $rk;
            }
        }
    }
    $role_keys = array_values(array_unique($role_keys));

    if (!metis_email_is_valid($primary_email)) {
        metis_runtime_send_json_error('Valid primary email is required.', 400);
    }
    if ($recovery_email !== '' && !metis_email_is_valid($recovery_email)) {
        metis_runtime_send_json_error('Recovery email is invalid.', 400);
    }
    if ($secondary_email !== '' && !metis_email_is_valid($secondary_email)) {
        metis_runtime_send_json_error('Secondary email is invalid.', 400);
    }
    if ($org_unit_path === '') $org_unit_path = '/';
    if ($display_name === '') {
        $display_name = trim($first_name . ' ' . $last_name);
    }
    if ($display_name === '') $display_name = $primary_email;

    $person_id = null;
    if ($linked_pid !== '') {
        $person_id = (int) $db->scalar("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", [ $linked_pid ]);
        if ($person_id < 1) {
            metis_runtime_send_json_error('Linked PID was not found.', 400);
        }
    }

    $email_conflict = (int) $db->scalar(
        "SELECT id FROM {$users_table} WHERE primary_email = %s AND id <> %d LIMIT 1",
        [ $primary_email, $workspace_user_id ]
    );
    if ($email_conflict > 0) {
        metis_runtime_send_json_error('Primary email already exists in workspace users.', 400);
    }
    if ($person_id !== null && $person_id > 0) {
        $person_email_conflict = (int) $db->scalar(
            "SELECT id FROM {$people_table} WHERE email = %s AND id <> %d LIMIT 1",
            [ $primary_email, $person_id ]
        );
        if ($person_email_conflict > 0) {
            metis_runtime_send_json_error('Email is already used by a different Metis profile.', 400);
        }
    }

    $payload = [
        'person_id' => $person_id ?: null,
        'primary_email' => $primary_email,
        'first_name' => $first_name !== '' ? $first_name : null,
        'last_name' => $last_name !== '' ? $last_name : null,
        'display_name' => $display_name,
        'org_unit_path' => $org_unit_path,
        'recovery_email' => $recovery_email !== '' ? $recovery_email : null,
        'is_suspended' => $is_suspended,
        'is_protected' => $is_protected,
        'sync_status' => 'queued',
    ];
    $previous_primary_email = '';
    $existing_metadata = [];
    if ($workspace_user_id > 0) {
        $existing_row = $db->fetchOne(
            "SELECT primary_email, metadata_json
             FROM {$users_table}
             WHERE id = %d
             LIMIT 1",
            [ $workspace_user_id ]
        );
        $previous_primary_email = strtolower(trim((string) ($existing_row['primary_email'] ?? '')));
        $existing_metadata = json_decode((string) ($existing_row['metadata_json'] ?? ''), true);
        if (!is_array($existing_metadata)) $existing_metadata = [];
    }
    if ($is_hidden) {
        $existing_metadata['ui_hidden'] = 1;
    } else {
        unset($existing_metadata['ui_hidden']);
    }
    if ($secondary_email !== '') {
        $existing_metadata['secondary_email'] = $secondary_email;
    } else {
        unset($existing_metadata['secondary_email']);
    }
    $payload['metadata_json'] = metis_json_encode($existing_metadata);
    $is_new_user = $workspace_user_id < 1;
    if ($workspace_user_id > 0) {
        $ok = $db->update($users_table, $payload, ['id' => $workspace_user_id], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'], ['%d']);
        if ($ok === false) {
            metis_runtime_send_json_error('Failed to update workspace user.', 500);
        }
    } else {
        $ok = $db->insert($users_table, $payload, ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
        if (!$ok) {
            metis_runtime_send_json_error('Failed to create workspace user.', 500);
        }
        $workspace_user_id = (int) $db->lastInsertId();
    }

    $db->delete($user_roles_table, ['workspace_user_id' => $workspace_user_id], ['%d']);
    foreach ($role_keys as $role_key) {
        $db->insert($user_roles_table, [
            'workspace_user_id' => $workspace_user_id,
            'role_key' => $role_key,
        ], ['%d', '%s']);
    }

    $sync_payload = [
        'primary_email' => $primary_email,
        'roles' => $role_keys,
        'is_suspended' => $is_suspended,
        'previous_primary_email' => $previous_primary_email,
        'add_alias_email' => ($previous_primary_email !== '' && $previous_primary_email !== $primary_email) ? $previous_primary_email : '',
    ];
    $job_type = $is_new_user ? 'workspace_user_create' : 'workspace_user_upsert';
    $job_id = 0;
    if (function_exists('metis_people_workspace_sync_settings') && function_exists('metis_people_workspace_execute_job')) {
        $cfg = metis_people_workspace_sync_settings();
        if (empty($cfg['ok'])) {
            $db->update($users_table, ['sync_status' => 'failed'], ['id' => $workspace_user_id], ['%s'], ['%d']);
            metis_runtime_send_json_error('Workspace settings are not configured. Save was applied locally only.', 400);
        }
        $sync_result = metis_people_workspace_execute_job([
            'job_type' => $job_type,
            'entity_type' => 'workspace_user',
            'entity_id' => $workspace_user_id,
            'payload_json' => metis_json_encode($sync_payload),
        ], $cfg, false);
        if (empty($sync_result['ok'])) {
            $db->update($users_table, ['sync_status' => 'failed'], ['id' => $workspace_user_id], ['%s'], ['%d']);
            $sync_error = trim((string) ($sync_result['error'] ?? ''));
            if ($sync_error === '') $sync_error = 'Failed to push Workspace update.';
            metis_runtime_send_json_error($sync_error, 400);
        }
    } else {
        $actor = metis_people_get_current_person_id();
        $job_id = metis_people_workspace_queue_job(
            $job_type,
            'workspace_user',
            $workspace_user_id,
            $actor > 0 ? $actor : null,
            $sync_payload
        );
    }

    metis_people_log_activity($person_id ?: null, 'workspace_user_saved', 'Saved workspace user profile', [
        'workspace_user_id' => $workspace_user_id,
        'primary_email' => $primary_email,
        'job_id' => $job_id,
    ]);

    $linked_pid_out = '';
    $linked_name_out = '';
    $person_url_out = '';
    if ($person_id !== null && $person_id > 0) {
        $person_update = [
            'auth_provider' => 'workspace',
            'email' => $primary_email,
            'first_name' => $first_name !== '' ? $first_name : null,
            'last_name' => $last_name !== '' ? $last_name : null,
            'display_name' => $display_name,
            'is_workspace_user' => 1,
            'workspace_email' => $primary_email,
        ];
        $person_update_ok = $db->update(
            $people_table,
            $person_update,
            ['id' => (int) $person_id],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s'],
            ['%d']
        );
        if ($person_update_ok === false) {
            metis_runtime_send_json_error('Workspace user saved, but Metis profile update failed.', 500);
        }

        $person_row = $db->fetchOne(
            "SELECT pid, display_name, first_name, last_name
             FROM {$people_table}
             WHERE id = %d
             LIMIT 1",
            [ (int) $person_id ]
        );
        if ($person_row) {
            $linked_pid_out = (string) ($person_row['pid'] ?? '');
            $linked_name_out = trim((string) ($person_row['first_name'] ?? '') . ' ' . (string) ($person_row['last_name'] ?? ''));
            if ($linked_name_out === '') $linked_name_out = trim((string) ($person_row['display_name'] ?? ''));
            if ($linked_pid_out !== '' && function_exists('metis_people_person_url')) {
                $person_url_out = (string) metis_people_person_url($linked_pid_out);
            }
        }
    }

    $drive_folder = null;
    if ($person_id !== null && $person_id > 0) {
        $drive_folder = metis_people_workspace_autocreate_drive_folder((int) $person_id);
    }

    metis_runtime_send_json_success([
        'workspace_user_id' => $workspace_user_id,
        'job_id' => $job_id,
        'drive_folder' => $drive_folder,
        'user' => [
            'id' => $workspace_user_id,
            'primary_email' => $primary_email,
            'display_name' => $display_name,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'org_unit_path' => $org_unit_path,
            'recovery_email' => $recovery_email,
            'secondary_email' => $secondary_email,
            'linked_pid' => $linked_pid_out !== '' ? $linked_pid_out : $linked_pid,
            'linked_name' => $linked_name_out,
            'person_url' => $person_url_out,
            'is_suspended' => $is_suspended,
            'is_protected' => $is_protected,
            'is_hidden' => $is_hidden,
            'role_keys' => $role_keys,
        ],
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_set_user_flags', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $users_table = Metis_Tables::get('people_workspace_users');

    $workspace_user_id = isset($_POST['workspace_user_id']) ? (int) metis_runtime_unslash($_POST['workspace_user_id']) : 0;
    if ($workspace_user_id < 1) {
        metis_runtime_send_json_error('Workspace user is required.', 400);
    }

    $has_hidden = isset($_POST['is_hidden']);
    $has_protected = isset($_POST['is_protected']);
    if (!$has_hidden && !$has_protected) {
        metis_runtime_send_json_error('No flag update was provided.', 400);
    }

    $row = $db->fetchOne(
        "SELECT id, person_id, primary_email, is_suspended, is_protected, metadata_json
         FROM {$users_table}
         WHERE id = %d
         LIMIT 1",
        [ $workspace_user_id ]
    );
    if (!$row) {
        metis_runtime_send_json_error('Workspace user not found.', 404);
    }

    if ((int) ($row['person_id'] ?? 0) > 0) {
        metis_runtime_send_json_error('Only non-Metis email users can be hidden or protected here.', 400);
    }

    $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
    if (!is_array($metadata)) $metadata = [];

    $is_hidden = !empty($metadata['ui_hidden']) ? 1 : 0;
    $is_protected = !empty($row['is_protected']) ? 1 : 0;
    if ($has_hidden) {
        $is_hidden = !empty($_POST['is_hidden']) ? 1 : 0;
    }
    if ($has_protected) {
        $is_protected = !empty($_POST['is_protected']) ? 1 : 0;
    }

    if ($is_hidden) {
        $metadata['ui_hidden'] = 1;
    } else {
        unset($metadata['ui_hidden']);
    }

    $ok = $db->update(
        $users_table,
        [
            'is_protected' => $is_protected,
            'metadata_json' => metis_json_encode($metadata),
        ],
        ['id' => $workspace_user_id],
        ['%d', '%s'],
        ['%d']
    );
    if ($ok === false) {
        metis_runtime_send_json_error('Failed to update user flags.', 500);
    }

    metis_people_log_activity(null, 'workspace_user_flags_updated', 'Updated workspace email user flags', [
        'workspace_user_id' => $workspace_user_id,
        'primary_email' => (string) ($row['primary_email'] ?? ''),
        'is_hidden' => $is_hidden,
        'is_protected' => $is_protected,
    ]);

    metis_runtime_send_json_success([
        'workspace_user_id' => $workspace_user_id,
        'user' => [
            'is_hidden' => $is_hidden,
            'is_protected' => $is_protected,
            'is_suspended' => !empty($row['is_suspended']) ? 1 : 0,
        ],
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_create_metis_user', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $users_table = Metis_Tables::get('people_workspace_users');
    $people_table = Metis_Tables::get('people');

    $workspace_user_id = isset($_POST['workspace_user_id']) ? (int) metis_runtime_unslash($_POST['workspace_user_id']) : 0;
    if ($workspace_user_id < 1) {
        metis_runtime_send_json_error('Workspace user is required.', 400);
    }

    $workspace_user = $db->fetchOne(
        "SELECT id, person_id, primary_email, first_name, last_name, display_name
         FROM {$users_table}
         WHERE id = %d
         LIMIT 1",
        [ $workspace_user_id ]
    );
    if (!$workspace_user) {
        metis_runtime_send_json_error('Workspace user not found.', 404);
    }

    $primary_email = strtolower(trim((string) ($workspace_user['primary_email'] ?? '')));
    if (!metis_email_is_valid($primary_email)) {
        metis_runtime_send_json_error('Workspace user email is invalid.', 400);
    }

    $linked_person_id = (int) ($workspace_user['person_id'] ?? 0);
    if ($linked_person_id > 0) {
        $pid = (string) $db->scalar("SELECT pid FROM {$people_table} WHERE id = %d LIMIT 1", [ $linked_person_id ]);
        $drive_folder = metis_people_workspace_autocreate_drive_folder($linked_person_id);
        metis_runtime_send_json_success([
            'person_id' => $linked_person_id,
            'pid' => $pid,
            'already_linked' => 1,
            'person_url' => $pid !== '' ? metis_people_person_url($pid) : '',
            'drive_folder' => $drive_folder,
        ]);
    }

    $existing_person = $db->fetchOne(
        "SELECT id, pid, display_name, first_name, last_name, is_workspace_user, workspace_email
         FROM {$people_table}
         WHERE workspace_email = %s OR email = %s
         ORDER BY id ASC
         LIMIT 1",
        [ $primary_email, $primary_email ]
    );
    $person_id = (int) ($existing_person['id'] ?? 0);

    $first_name = trim((string) ($workspace_user['first_name'] ?? ''));
    $last_name = trim((string) ($workspace_user['last_name'] ?? ''));
    $display_name = $first_name;
    if ($display_name === '') $display_name = trim((string) ($workspace_user['display_name'] ?? ''));
    if ($display_name === '') $display_name = trim($first_name . ' ' . $last_name);
    if ($display_name === '') {
        $display_name = $primary_email;
    }

    if ($person_id > 0) {
        $update_payload = [
            'auth_provider' => 'workspace',
            'is_workspace_user' => 1,
            'workspace_email' => $primary_email,
        ];
        $update_format = ['%s', '%d', '%s'];

        if (trim((string) ($existing_person['display_name'] ?? '')) === '' && $display_name !== '') {
            $update_payload['display_name'] = $display_name;
            $update_format[] = '%s';
        }
        if (trim((string) ($existing_person['first_name'] ?? '')) === '' && $first_name !== '') {
            $update_payload['first_name'] = $first_name;
            $update_format[] = '%s';
        }
        if (trim((string) ($existing_person['last_name'] ?? '')) === '' && $last_name !== '') {
            $update_payload['last_name'] = $last_name;
            $update_format[] = '%s';
        }

        $updated = $db->update($people_table, $update_payload, ['id' => $person_id], $update_format, ['%d']);
        if ($updated === false) {
            metis_runtime_send_json_error('Failed to link existing person.', 500);
        }
    } else {
        $payload = [
            'auth_provider' => 'workspace',
            'email' => $primary_email,
            'first_name' => $first_name !== '' ? $first_name : null,
            'last_name' => $last_name !== '' ? $last_name : null,
            'display_name' => $display_name,
            'is_workspace_user' => 1,
            'workspace_email' => $primary_email,
        ];
        $format = ['%s', '%s', '%s', '%s', '%s', '%d', '%s'];
        if (function_exists('metis_entity_id_service')) {
            $payload = metis_entity_id_service()->assignForInsert('person', $payload);
            $format[] = '%s';
        } else {
            $payload['pid'] = metis_generate_code('PE', $people_table, 'pid');
            $format[] = '%s';
        }
        $ok = $db->insert($people_table, $payload, $format);
        if (!$ok) {
            metis_runtime_send_json_error('Failed to create Metis user.', 500);
        }
        $person_id = (int) $db->lastInsertId();
        if ($person_id > 0 && function_exists('metis_entity_id_service')) {
            metis_entity_id_service()->register('person', $person_id, (string) ($payload['person_uid'] ?? $payload['pid'] ?? ''));
        }
    }

    $pid = (string) $db->scalar("SELECT pid FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ]);
    $link_ok = $db->update(
        $users_table,
        ['person_id' => $person_id, 'sync_status' => 'synced'],
        ['id' => $workspace_user_id],
        ['%d', '%s'],
        ['%d']
    );
    if ($link_ok === false) {
        metis_runtime_send_json_error('Metis user was created but linking failed.', 500);
    }

    metis_people_log_activity($person_id, 'workspace_user_linked_to_person', 'Linked workspace user to Metis person', [
        'workspace_user_id' => $workspace_user_id,
        'primary_email' => $primary_email,
        'pid' => $pid,
    ]);

    $drive_folder = metis_people_workspace_autocreate_drive_folder($person_id);

    metis_runtime_send_json_success([
        'person_id' => $person_id,
        'pid' => $pid,
        'person_url' => $pid !== '' ? metis_people_person_url($pid) : '',
        'workspace_user_id' => $workspace_user_id,
        'drive_folder' => $drive_folder,
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_delete_user', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $users_table = Metis_Tables::get('people_workspace_users');
    $user_roles_table = Metis_Tables::get('people_workspace_user_roles');
    $group_members_table = Metis_Tables::get('people_workspace_group_members');
    $security_actions_table = Metis_Tables::get('people_workspace_security_actions');

    $workspace_user_id = isset($_POST['workspace_user_id']) ? (int) metis_runtime_unslash($_POST['workspace_user_id']) : 0;
    if ($workspace_user_id < 1) {
        metis_runtime_send_json_error('Workspace user is required.', 400);
    }

    $user_row = $db->fetchOne(
        "SELECT id, person_id, primary_email, is_protected
         FROM {$users_table}
         WHERE id = %d
         LIMIT 1",
        [ $workspace_user_id ]
    );
    if (!$user_row) {
        metis_runtime_send_json_error('Workspace user not found.', 404);
    }
    if (!empty($user_row['is_protected'])) {
        metis_runtime_send_json_error('Protected workspace users cannot be deleted.', 400);
    }
    if ((int) ($user_row['person_id'] ?? 0) > 0) {
        metis_runtime_send_json_error('Linked Metis users cannot be deleted here.', 400);
    }

    $primary_email = strtolower(trim((string) ($user_row['primary_email'] ?? '')));
    if (!metis_email_is_valid($primary_email)) {
        metis_runtime_send_json_error('Workspace email is invalid.', 400);
    }

    $cfg = metis_people_workspace_sync_settings();
    if (!empty($cfg['ok'])) {
        $remote = metis_people_workspace_google_request('DELETE', 'users/' . rawurlencode($primary_email), null, $cfg);
        if (empty($remote['ok'])) {
            metis_runtime_send_json_error('Failed to delete workspace account in Google.', 400);
        }
    }

    $db->delete($group_members_table, ['workspace_user_id' => $workspace_user_id], ['%d']);
    $db->delete($user_roles_table, ['workspace_user_id' => $workspace_user_id], ['%d']);
    $db->delete($security_actions_table, ['workspace_user_id' => $workspace_user_id], ['%d']);
    $deleted = $db->delete($users_table, ['id' => $workspace_user_id], ['%d']);
    if ($deleted === false) {
        metis_runtime_send_json_error('Failed to delete workspace user record.', 500);
    }

    metis_people_log_activity(null, 'workspace_user_deleted', 'Deleted workspace email user', [
        'workspace_user_id' => $workspace_user_id,
        'primary_email' => $primary_email,
    ]);
    metis_runtime_send_json_success(['workspace_user_id' => $workspace_user_id]);
});

metis_ajax_register_handler( 'metis_people_workspace_run_security_action', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $users_table = Metis_Tables::get('people_workspace_users');
    $actions_table = Metis_Tables::get('people_workspace_security_actions');

    $workspace_user_id = isset($_POST['workspace_user_id']) ? (int) metis_runtime_unslash($_POST['workspace_user_id']) : 0;
    $action_type = isset($_POST['action_type']) ? metis_key_clean(metis_runtime_unslash($_POST['action_type'])) : '';
    $reason = isset($_POST['reason']) ? sanitize_textarea_field(metis_runtime_unslash($_POST['reason'])) : '';
    $allowed_actions = ['reset_password', 'revoke_sessions', 'force_2fa_reenroll', 'suspend_account', 'unsuspend_account'];
    if ($workspace_user_id < 1 || !in_array($action_type, $allowed_actions, true) || trim($reason) === '') {
        metis_runtime_send_json_error('Valid user, action, and reason are required.', 400);
    }
    $user_row = $db->fetchOne("SELECT id, person_id, primary_email FROM {$users_table} WHERE id = %d LIMIT 1", [ $workspace_user_id ]);
    if (!$user_row) metis_runtime_send_json_error('Workspace user not found.', 404);
    $actor = metis_people_get_current_person_id();
    $db->insert($actions_table, [
        'workspace_user_id' => $workspace_user_id,
        'action_type' => $action_type,
        'requested_by_person_id' => $actor > 0 ? $actor : null,
        'status' => 'pending',
        'reason' => $reason,
    ], ['%d', '%s', '%d', '%s', '%s']);
    if ($action_type === 'suspend_account' || $action_type === 'unsuspend_account') {
        $db->update($users_table, [
            'is_suspended' => $action_type === 'suspend_account' ? 1 : 0,
            'sync_status' => 'queued',
        ], ['id' => $workspace_user_id], ['%d', '%s'], ['%d']);
    }
    $job_id = metis_people_workspace_queue_job(
        'workspace_security_action',
        'workspace_user',
        $workspace_user_id,
        $actor > 0 ? $actor : null,
        ['action_type' => $action_type, 'reason' => $reason]
    );
    metis_people_log_activity((int) ($user_row['person_id'] ?? 0) ?: null, 'workspace_security_action', 'Queued workspace security action', [
        'workspace_user_id' => $workspace_user_id,
        'primary_email' => (string) ($user_row['primary_email'] ?? ''),
        'action_type' => $action_type,
        'job_id' => $job_id,
    ]);
    metis_runtime_send_json_success(['ok' => 1, 'job_id' => $job_id, 'action_type' => $action_type]);
});

metis_ajax_register_handler( 'metis_people_workspace_get_activity_page', function () {
    metis_people_workspace_ajax_verify();
    $sync_page = isset($_POST['sync_page']) ? (int) metis_runtime_unslash($_POST['sync_page']) : 1;
    $security_page = isset($_POST['security_page']) ? (int) metis_runtime_unslash($_POST['security_page']) : 1;
    if ($sync_page < 1) $sync_page = 1;
    if ($security_page < 1) $security_page = 1;
    $payload = metis_people_workspace_activity_payload($sync_page, $security_page, 12, 12);
    metis_runtime_send_json_success($payload);
});

metis_ajax_register_handler( 'metis_people_bulk_workspace_user_action', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $users_table = Metis_Tables::get('people_workspace_users');
    $workspace_actions_table = Metis_Tables::get('people_workspace_security_actions');
    $user_roles_table = Metis_Tables::get('people_user_roles');

    $action_type = isset($_POST['workspace_action']) ? metis_key_clean(metis_runtime_unslash($_POST['workspace_action'])) : '';
    $org_unit_path = isset($_POST['org_unit_path']) ? metis_text_clean(metis_runtime_unslash($_POST['org_unit_path'])) : '/';
    $person_pids = [];
    if (isset($_POST['person_pids'])) {
        $decoded = json_decode((string) metis_runtime_unslash($_POST['person_pids']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $pid) {
                $clean = strtoupper(trim((string) metis_text_clean((string) $pid)));
                if ($clean !== '') $person_pids[] = $clean;
            }
        }
    }
    $person_pids = array_values(array_unique($person_pids));
    if (empty($person_pids)) {
        metis_runtime_send_json_error('Select at least one person.', 400);
    }

    $allowed_actions = [
        'set_org_unit',
        'suspend_account',
        'unsuspend_account',
        'reset_password',
        'set_hidden',
        'clear_hidden',
        'create_drive_folder',
        'sync_now',
    ];
    if (!in_array($action_type, $allowed_actions, true)) {
        metis_runtime_send_json_error('Invalid workspace action.', 400);
    }
    if ($action_type === 'set_org_unit') {
        $org_unit_path = trim($org_unit_path);
        if ($org_unit_path === '') $org_unit_path = '/';
        if (substr($org_unit_path, 0, 1) !== '/') {
            metis_runtime_send_json_error('Org Unit must start with "/".', 400);
        }
    }

    $needs_remote = in_array($action_type, ['set_org_unit', 'suspend_account', 'unsuspend_account', 'reset_password', 'sync_now'], true);
    $cfg = [];
    if ($needs_remote) {
        $cfg = metis_people_workspace_sync_settings();
        if (empty($cfg['ok'])) {
            metis_runtime_send_json_error('Workspace settings are not configured.', 400);
        }
    }

    $actor = metis_people_get_current_person_id();
    $updated = 0;
    $skipped = 0;
    $failed = 0;
    $errors = [];

    foreach ($person_pids as $pid) {
        $person = $db->fetchOne(
            "SELECT id, pid, display_name, email, workspace_email
             FROM {$people_table}
             WHERE pid = %s
             LIMIT 1",
            [ $pid ]
        );
        if (!$person) {
            $skipped++;
            continue;
        }

        $person_id = (int) ($person['id'] ?? 0);
        $workspace_email = strtolower(trim((string) ($person['workspace_email'] ?? '')));
        if (!metis_email_is_valid($workspace_email)) {
            $workspace_email = strtolower(trim((string) ($person['email'] ?? '')));
        }
        $workspace_user = null;
        if ($person_id > 0) {
            $workspace_user = $db->fetchOne(
                "SELECT id, person_id, primary_email, org_unit_path, is_suspended, metadata_json
                 FROM {$users_table}
                 WHERE person_id = %d
                 LIMIT 1",
                [ $person_id ]
            );
        }
        if (!$workspace_user && metis_email_is_valid($workspace_email)) {
            $workspace_user = $db->fetchOne(
                "SELECT id, person_id, primary_email, org_unit_path, is_suspended, metadata_json
                 FROM {$users_table}
                 WHERE primary_email = %s
                 LIMIT 1",
                [ $workspace_email ]
            );
        }

        if ($action_type === 'create_drive_folder') {
            if ($person_id < 1) {
                $skipped++;
                continue;
            }
            $created = metis_people_workspace_autocreate_drive_folder($person_id);
            if (empty($created['ok'])) {
                $failed++;
                $errors[] = (string) ($person['display_name'] ?? $pid) . ': ' . (string) ($created['error'] ?? 'Drive folder creation failed.');
                continue;
            }
            $updated++;
            continue;
        }

        if (!$workspace_user) {
            $skipped++;
            continue;
        }
        $workspace_user_id = (int) ($workspace_user['id'] ?? 0);
        if ($workspace_user_id < 1) {
            $skipped++;
            continue;
        }

        if ($action_type === 'set_hidden' || $action_type === 'clear_hidden') {
            $metadata = json_decode((string) ($workspace_user['metadata_json'] ?? ''), true);
            if (!is_array($metadata)) $metadata = [];
            if ($action_type === 'set_hidden') {
                $metadata['ui_hidden'] = 1;
            } else {
                unset($metadata['ui_hidden']);
            }
            $ok = $db->update(
                $users_table,
                ['metadata_json' => metis_json_encode($metadata), 'updated_at' => metis_current_time('mysql')],
                ['id' => $workspace_user_id],
                ['%s', '%s'],
                ['%d']
            );
            if ($ok === false) {
                $failed++;
                $errors[] = (string) ($person['display_name'] ?? $pid) . ': failed to update local hidden flag.';
                continue;
            }
            $updated++;
            continue;
        }

        if ($action_type === 'set_org_unit') {
            $db->update(
                $users_table,
                ['org_unit_path' => $org_unit_path, 'sync_status' => 'queued', 'updated_at' => metis_current_time('mysql')],
                ['id' => $workspace_user_id],
                ['%s', '%s', '%s'],
                ['%d']
            );
            $job_payload = [
                'primary_email' => (string) ($workspace_user['primary_email'] ?? ''),
                'roles' => metis_people_workspace_role_keys_for_user($workspace_user_id),
                'is_suspended' => !empty($workspace_user['is_suspended']) ? 1 : 0,
                'reason' => 'bulk_set_org_unit',
            ];
            $result = metis_people_workspace_execute_job([
                'job_type' => 'workspace_user_upsert',
                'entity_type' => 'workspace_user',
                'entity_id' => $workspace_user_id,
                'payload_json' => metis_json_encode($job_payload),
            ], $cfg, false);
            if (empty($result['ok'])) {
                $failed++;
                $errors[] = (string) ($person['display_name'] ?? $pid) . ': ' . (string) ($result['error'] ?? 'Org Unit update failed.');
                continue;
            }
            $updated++;
            continue;
        }

        if ($action_type === 'sync_now') {
            $job_payload = [
                'primary_email' => (string) ($workspace_user['primary_email'] ?? ''),
                'roles' => metis_people_workspace_role_keys_for_user($workspace_user_id),
                'is_suspended' => !empty($workspace_user['is_suspended']) ? 1 : 0,
                'reason' => 'bulk_sync_now',
            ];
            $result = metis_people_workspace_execute_job([
                'job_type' => 'workspace_user_upsert',
                'entity_type' => 'workspace_user',
                'entity_id' => $workspace_user_id,
                'payload_json' => metis_json_encode($job_payload),
            ], $cfg, false);
            if (empty($result['ok'])) {
                $failed++;
                $errors[] = (string) ($person['display_name'] ?? $pid) . ': ' . (string) ($result['error'] ?? 'Sync failed.');
                continue;
            }
            $updated++;
            continue;
        }

        if (in_array($action_type, ['suspend_account', 'unsuspend_account', 'reset_password'], true)) {
            $db->insert($workspace_actions_table, [
                'workspace_user_id' => $workspace_user_id,
                'action_type' => $action_type,
                'requested_by_person_id' => $actor > 0 ? $actor : null,
                'status' => 'pending',
                'reason' => 'bulk_workspace_action',
            ], ['%d', '%s', '%d', '%s', '%s']);
            if ($action_type === 'suspend_account' || $action_type === 'unsuspend_account') {
                $db->update(
                    $users_table,
                    ['is_suspended' => $action_type === 'suspend_account' ? 1 : 0, 'sync_status' => 'queued', 'updated_at' => metis_current_time('mysql')],
                    ['id' => $workspace_user_id],
                    ['%d', '%s', '%s'],
                    ['%d']
                );
            }
            $result = metis_people_workspace_execute_job([
                'job_type' => 'workspace_security_action',
                'entity_type' => 'workspace_user',
                'entity_id' => $workspace_user_id,
                'payload_json' => metis_json_encode(['action_type' => $action_type, 'reason' => 'bulk_workspace_action']),
            ], $cfg, false);
            if (empty($result['ok'])) {
                $failed++;
                $errors[] = (string) ($person['display_name'] ?? $pid) . ': ' . (string) ($result['error'] ?? 'Workspace security action failed.');
                continue;
            }
            $updated++;
            continue;
        }
    }

    metis_people_log_activity(null, 'bulk_workspace_user_action', 'Ran bulk workspace user action', [
        'workspace_action' => $action_type,
        'org_unit_path' => $action_type === 'set_org_unit' ? $org_unit_path : '',
        'updated' => $updated,
        'skipped' => $skipped,
        'failed' => $failed,
    ]);

    metis_runtime_send_json_success([
        'updated' => $updated,
        'skipped' => $skipped,
        'failed' => $failed,
        'errors' => array_slice($errors, 0, 5),
    ]);
});

metis_ajax_register_handler( 'metis_people_attach_drive_folder', function () {
    metis_people_ajax_verify();
    if (
        !function_exists('metis_drive_workspace_settings')
        || !function_exists('metis_drive_find_or_create_user_folder')
        || !function_exists('metis_drive_ensure_schema')
        || !function_exists('metis_drive_log_action')
    ) {
        metis_runtime_send_json_error('Drive module is not available.', 400);
    }

    $person_id = isset($_POST['person_id']) ? (int) metis_runtime_unslash($_POST['person_id']) : 0;
    $pid = isset($_POST['pid']) ? trim(metis_text_clean(metis_runtime_unslash($_POST['pid']))) : '';
    if ($person_id < 1 && $pid === '') {
        metis_runtime_send_json_error('Person identifier is required.', 422);
    }

    $resolved_person = metis_people_resolve_person_record($person_id, $pid);
    if (empty($resolved_person['ok'])) {
        metis_runtime_send_json_error('Person not found.', (int) ($resolved_person['status'] ?? 404));
    }
    $person = (array) ($resolved_person['person'] ?? []);

    $cfg = metis_drive_workspace_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Drive is not configured.', 400);
    }

    metis_drive_ensure_schema();
    $folder = metis_drive_find_or_create_user_folder($cfg, (int) $person['id'], true);
    if (empty($folder['ok']) || empty($folder['folder_id'])) {
        metis_runtime_send_json_error('Failed to attach user folder.', 500);
    }

    if (!empty($folder['created'])) {
        metis_drive_log_action($cfg, 'create_user_folder', [
            'folder_id' => (string) ($folder['folder_id'] ?? ''),
            'item_name' => (string) ($folder['folder_name'] ?? ''),
            'item_type' => 'folder',
            'details' => [
                'person_id' => (int) $person['id'],
                'pid' => (string) ($person['pid'] ?? ''),
            ],
        ]);
    }

    $folder_url = '';
    if (function_exists('metis_portal_url')) {
        $folder_url = metis_add_query_arg(
            ['folder_id' => (string) $folder['folder_id']],
            metis_portal_url('drive', 'dashboard')
        );
    }

    metis_runtime_send_json_success([
        'folder_id' => (string) ($folder['folder_id'] ?? ''),
        'folder_name' => (string) ($folder['folder_name'] ?? ''),
        'folder_url' => $folder_url,
        'created' => !empty($folder['created']) ? 1 : 0,
    ]);
});

metis_ajax_register_handler( 'metis_people_drive_folder_picker', function () {
    metis_people_ajax_verify();
    if (
        !function_exists('metis_drive_workspace_settings')
        || !function_exists('metis_drive_get_users_root_folder')
        || !function_exists('metis_drive_sync_folder_listing')
        || !function_exists('metis_drive_cached_folder_children')
        || !function_exists('metis_drive_get_file_meta')
        || !function_exists('metis_drive_folder_is_descendant_of')
    ) {
        metis_runtime_send_json_error('Drive module is not available.', 400);
    }

    $cfg = metis_drive_workspace_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Drive is not configured.', 400);
    }

    $users_root = metis_drive_get_users_root_folder($cfg, false);
    $users_root_id = (string) ($users_root['folder_id'] ?? '');
    if ($users_root_id === '') {
        metis_runtime_send_json_error('Users folder could not be resolved.', 400);
    }

    $folder_id = metis_text_clean(metis_runtime_unslash($_POST['folder_id'] ?? ''));
    if ($folder_id === '') {
        $folder_id = $users_root_id;
    }

    $folder_name = (string) ($users_root['folder_name'] ?? 'Users');
    $parent_id = '';

    if ($folder_id !== $users_root_id) {
        $meta = metis_drive_get_file_meta($cfg, $folder_id, 'id,name,mimeType,parents,driveId');
        if (empty($meta['ok'])) {
            metis_runtime_send_json_error('Invalid folder.', 400);
        }
        $body = (array) ($meta['body'] ?? []);
        if ((string) ($body['driveId'] ?? '') !== (string) ($cfg['shared_drive_id'] ?? '')) {
            metis_runtime_send_json_error('That folder is not in the configured Shared Drive.', 400);
        }
        if ((string) ($body['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
            metis_runtime_send_json_error('Selected item is not a folder.', 400);
        }
        if (!metis_drive_folder_is_descendant_of($cfg, $folder_id, $users_root_id)) {
            metis_runtime_send_json_error('Selected folder is not inside the Users container.', 403);
        }
        $folder_name = (string) ($body['name'] ?? $folder_name);
        $parent_id = (string) (($body['parents'][0] ?? '') ?: '');
        if ($parent_id === (string) ($cfg['shared_drive_id'] ?? '')) {
            $parent_id = $users_root_id;
        }
    }

    metis_drive_sync_folder_listing($cfg, $folder_id, 0, true);
    $folders = metis_drive_cached_folder_children((string) ($cfg['shared_drive_id'] ?? ''), $folder_id, '', true);
    $items = [];
    foreach ((array) $folders as $folder) {
        $id = (string) ($folder['id'] ?? '');
        if ($id === '' || $id === $users_root_id) {
            continue;
        }
        $items[] = [
            'id' => $id,
            'name' => (string) ($folder['name'] ?? 'Folder'),
            'parent_id' => (string) (($folder['parents'][0] ?? '') ?: $folder_id),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    metis_runtime_send_json_success([
        'folder_id' => $folder_id,
        'folder_name' => $folder_name,
        'parent_id' => $folder_id === $users_root_id ? '' : $parent_id,
        'users_root_id' => $users_root_id,
        'users_root_name' => (string) ($users_root['folder_name'] ?? 'Users'),
        'folders' => $items,
    ]);
});

metis_ajax_register_handler( 'metis_people_attach_drive_folder_selection', function () {
    metis_people_ajax_verify();
    if (
        !function_exists('metis_drive_workspace_settings')
        || !function_exists('metis_drive_get_users_root_folder')
        || !function_exists('metis_drive_get_file_meta')
        || !function_exists('metis_drive_folder_is_descendant_of')
        || !function_exists('metis_drive_upsert_user_folder_mapping')
        || !function_exists('metis_drive_log_action')
    ) {
        metis_runtime_send_json_error('Drive module is not available.', 400);
    }

    $person_id = isset($_POST['person_id']) ? (int) metis_runtime_unslash($_POST['person_id']) : 0;
    $pid = isset($_POST['pid']) ? trim(metis_text_clean(metis_runtime_unslash($_POST['pid']))) : '';
    $folder_id = metis_text_clean(metis_runtime_unslash($_POST['folder_id'] ?? ''));
    if ($folder_id === '') {
        metis_runtime_send_json_error('Folder is required.', 422);
    }

    $resolved_person = metis_people_resolve_person_record($person_id, $pid);
    if (empty($resolved_person['ok'])) {
        metis_runtime_send_json_error('Person not found.', (int) ($resolved_person['status'] ?? 404));
    }
    $person = (array) ($resolved_person['person'] ?? []);

    $cfg = metis_drive_workspace_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Drive is not configured.', 400);
    }

    $users_root = metis_drive_get_users_root_folder($cfg, false);
    $users_root_id = (string) ($users_root['folder_id'] ?? '');
    if ($users_root_id === '') {
        metis_runtime_send_json_error('Users folder could not be resolved.', 400);
    }

    $meta = metis_drive_get_file_meta($cfg, $folder_id, 'id,name,mimeType,parents,driveId,webViewLink');
    if (empty($meta['ok'])) {
        metis_runtime_send_json_error('Invalid folder.', 400);
    }
    $body = (array) ($meta['body'] ?? []);
    if ((string) ($body['driveId'] ?? '') !== (string) ($cfg['shared_drive_id'] ?? '')) {
        metis_runtime_send_json_error('That folder is not in the configured Shared Drive.', 400);
    }
    if ((string) ($body['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
        metis_runtime_send_json_error('Selected item is not a folder.', 400);
    }
    if ($folder_id === $users_root_id || !metis_drive_folder_is_descendant_of($cfg, $folder_id, $users_root_id)) {
        metis_runtime_send_json_error('Selected folder must be inside the Users container.', 403);
    }

    $folder_name = (string) ($body['name'] ?? $folder_id);
    $parent_id = (string) (($body['parents'][0] ?? '') ?: $users_root_id);

    $person_id_value = (int) ($person['id'] ?? 0);
    $expected_folder_name = '';
    if ($person_id_value > 0 && function_exists('metis_drive_person_folder_display_name')) {
        $expected_folder_name = (string) metis_drive_person_folder_display_name($person_id_value, 0);
    }
    if ($expected_folder_name === '' && $person_id_value > 0) {
        $people_table = Metis_Tables::get('people');
        if ($people_table) {
            $person_row = metis_db()->fetchOne(
                "SELECT first_name, last_name, display_name, email
                 FROM {$people_table}
                 WHERE id = %d
                 LIMIT 1",
                [ $person_id_value ]
            );
            if (is_array($person_row)) {
                $expected_folder_name = trim((string) ($person_row['first_name'] ?? '') . ' ' . (string) ($person_row['last_name'] ?? ''));
                if ($expected_folder_name === '') $expected_folder_name = trim((string) ($person_row['display_name'] ?? ''));
                if ($expected_folder_name === '') $expected_folder_name = trim((string) ($person_row['email'] ?? ''));
            }
        }
    }
    $normalize_folder_name = static function (string $value): string {
        $name = trim((string) preg_replace('/\s+/', ' ', $value));
        $name = trim((string) preg_replace('/[^A-Za-z0-9\.\'\-\s]/', '', $name));
        return strtolower($name);
    };
    if ($expected_folder_name !== '') {
        $actual_name_norm = $normalize_folder_name($folder_name);
        $expected_name_norm = $normalize_folder_name($expected_folder_name);
        if ($actual_name_norm === '' || $expected_name_norm === '' || $actual_name_norm !== $expected_name_norm) {
            metis_runtime_send_json_error('Selected folder name must match this user name: ' . $expected_folder_name, 400);
        }
    }

    metis_drive_upsert_user_folder_mapping($cfg, (int) ($person['id'] ?? 0), $folder_id, $folder_name, $parent_id);
    metis_drive_log_action($cfg, 'attach_user_folder', [
        'folder_id' => $folder_id,
        'item_name' => $folder_name,
        'item_type' => 'folder',
        'details' => [
            'person_id' => (int) ($person['id'] ?? 0),
            'pid' => (string) ($person['pid'] ?? ''),
            'parent_folder_id' => $parent_id,
            'source' => 'manual_picker',
        ],
    ]);

    $folder_url = '';
    if (function_exists('metis_portal_url')) {
        $folder_url = metis_add_query_arg(
            ['folder_id' => $folder_id],
            metis_portal_url('drive', 'dashboard')
        );
    }

    metis_runtime_send_json_success([
        'folder_id' => $folder_id,
        'folder_name' => $folder_name,
        'folder_url' => $folder_url,
        'created' => 0,
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_process_queue', function () {
    metis_people_workspace_ajax_verify();
    $limit = isset($_POST['limit']) ? (int) metis_runtime_unslash($_POST['limit']) : 10;
    $job_id = isset($_POST['job_id']) ? (int) metis_runtime_unslash($_POST['job_id']) : 0;
    $dry_run = !empty($_POST['dry_run']) ? true : false;
    $run_all = !empty($_POST['run_all']) ? true : false;
    $limit = max(1, min(100, $limit));
    if (!$run_all || $job_id > 0) {
        $result = metis_people_workspace_process_jobs($limit, $dry_run, $job_id);
        if (!empty($result['error'])) {
            metis_runtime_send_json_error('Workspace queue processing failed.', 400);
        }
        metis_runtime_send_json_success($result);
        return;
    }
    $total = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'messages' => []];
    $loops = 0;
    $max_loops = 20;
    while ($loops < $max_loops) {
        $loops++;
        $result = metis_people_workspace_process_jobs($limit, $dry_run, 0);
        if (!empty($result['error'])) {
            metis_runtime_send_json_error('Workspace queue processing failed.', 400);
        }
        $processed = (int) ($result['processed'] ?? 0);
        $total['processed'] += $processed;
        $total['completed'] += (int) ($result['completed'] ?? 0);
        $total['failed'] += (int) ($result['failed'] ?? 0);
        $messages = (array) ($result['messages'] ?? []);
        if (!empty($messages)) {
            $total['messages'] = array_merge($total['messages'], array_map('strval', $messages));
        }
        if ($processed < 1) break;
    }
    $jobs_table = Metis_Tables::get('people_workspace_sync_jobs');
    $remaining = (int) metis_db()->scalar("SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'queued'");
    $total['remaining_queued'] = $remaining;
    $total['loops'] = $loops;
    metis_runtime_send_json_success($total);
});

function metis_people_workspace_import_directory_snapshot(array $cfg, int $limit = 500, bool $include_groups = false, int $groups_limit = 300): array {
    $db = metis_db();
    $limit = max(1, min(2000, $limit));
    $groups_limit = max(1, min(1000, $groups_limit));

    $users_table = Metis_Tables::get('people_workspace_users');
    $user_roles_table = Metis_Tables::get('people_workspace_user_roles');
    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $group_members_table = Metis_Tables::get('people_workspace_group_members');

    $imported = 0;
    $created = 0;
    $updated = 0;
    $linked = 0;
    $imported_workspace_user_ids = [];
    $local_workspace_user_by_google_id = [];
    $customer = trim((string) ($cfg['customer_id'] ?? ''));
    if ($customer === '') $customer = 'my_customer';
    $customer_query_value = rawurlencode($customer);
    $page_token = '';
    $pages = 0;
    while ($imported < $limit && $pages < 20) {
        $pages++;
        $remaining = $limit - $imported;
        $page_size = min(100, $remaining);
        $query = 'users?customer=' . $customer_query_value . '&maxResults=' . $page_size . '&orderBy=email&projection=full';
        if ($page_token !== '') {
            $query .= '&pageToken=' . rawurlencode($page_token);
        }
        $resp = metis_people_workspace_google_request('GET', $query, null, $cfg);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'error' => 'Failed to fetch users from Workspace.'];
        }
        $users = (array) ($resp['body']['users'] ?? []);
        if (empty($users)) break;
        foreach ($users as $google_user) {
            if ($imported >= $limit) break;
            $primary_email = strtolower(trim((string) ($google_user['primaryEmail'] ?? '')));
            if (!metis_email_is_valid($primary_email)) continue;
            $google_id = (string) ($google_user['id'] ?? '');
            $first_name = (string) ($google_user['name']['givenName'] ?? '');
            $last_name = (string) ($google_user['name']['familyName'] ?? '');
            $display_name = (string) ($google_user['name']['fullName'] ?? '');
            if ($display_name === '') $display_name = trim($first_name . ' ' . $last_name);
            if ($display_name === '') $display_name = $primary_email;
            $org_unit_path = (string) ($google_user['orgUnitPath'] ?? '/');
            if ($org_unit_path === '') $org_unit_path = '/';
            $recovery_email = strtolower(trim((string) ($google_user['recoveryEmail'] ?? '')));
            if (!metis_email_is_valid($recovery_email)) $recovery_email = '';
            $is_suspended = !empty($google_user['suspended']) ? 1 : 0;

            $person_id = (int) $db->scalar(
                "SELECT id FROM {$people_table}
                 WHERE workspace_email = %s OR email = %s
                 ORDER BY id ASC
                 LIMIT 1",
                [ $primary_email, $primary_email ]
            );
            if ($person_id > 0) $linked++;

            $existing_id = (int) $db->scalar(
                "SELECT id FROM {$users_table} WHERE primary_email = %s LIMIT 1",
                [ $primary_email ]
            );
            $payload = [
                'person_id' => $person_id > 0 ? $person_id : null,
                'workspace_user_id' => $google_id !== '' ? $google_id : null,
                'primary_email' => $primary_email,
                'first_name' => $first_name !== '' ? $first_name : null,
                'last_name' => $last_name !== '' ? $last_name : null,
                'display_name' => $display_name,
                'org_unit_path' => $org_unit_path,
                'recovery_email' => $recovery_email !== '' ? $recovery_email : null,
                'is_suspended' => $is_suspended,
                'sync_status' => 'synced',
            ];
            $fmt = ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'];
            if ($existing_id > 0) {
                $ok = $db->update($users_table, $payload, ['id' => $existing_id], $fmt, ['%d']);
                if ($ok !== false) {
                    $updated++;
                    $local_workspace_user_id = $existing_id;
                } else {
                    $local_workspace_user_id = 0;
                }
            } else {
                $ok = $db->insert($users_table, $payload, $fmt);
                if ($ok) {
                    $created++;
                    $local_workspace_user_id = (int) $db->lastInsertId();
                } else {
                    $local_workspace_user_id = 0;
                }
            }
            if ($local_workspace_user_id > 0) {
                $imported_workspace_user_ids[] = $local_workspace_user_id;
                if ($google_id !== '') {
                    $local_workspace_user_by_google_id[$google_id] = $local_workspace_user_id;
                }
            }
            $imported++;
        }
        $page_token = (string) ($resp['body']['nextPageToken'] ?? '');
        if ($page_token === '') break;
    }

    $roles_synced = 0;
    $role_assignments_seen = 0;
    $role_sync_error = '';
    if (!empty($local_workspace_user_by_google_id) && !empty($imported_workspace_user_ids)) {
        $customer = $customer_query_value;

        $role_key_by_google_role_id = [];
        $roles_page_token = '';
        $roles_pages = 0;
        while ($roles_pages < 20) {
            $roles_pages++;
            $roles_query = "customer/{$customer}/roles?maxResults=100";
            if ($roles_page_token !== '') {
                $roles_query .= '&pageToken=' . rawurlencode($roles_page_token);
            }
            $roles_resp = metis_people_workspace_google_request('GET', $roles_query, null, $cfg);
            if (empty($roles_resp['ok'])) {
                $role_sync_error = 'Failed to fetch Workspace roles.';
                break;
            }
            $google_roles = (array) ($roles_resp['body']['items'] ?? []);
            foreach ($google_roles as $google_role) {
                $google_role_id = trim((string) ($google_role['roleId'] ?? ''));
                $google_role_name = trim((string) ($google_role['roleName'] ?? ''));
                $google_role_description = trim((string) ($google_role['roleDescription'] ?? ''));
                if ($google_role_id === '' || $google_role_name === '') continue;
                $resolved_role = metis_people_workspace_resolve_role_meta($google_role_name, $google_role_description);
                $role_key = (string) ($resolved_role['role_key'] ?? '');
                $role_label = (string) ($resolved_role['role_label'] ?? $google_role_name);
                if ($role_key === '') continue;
                $role_key_by_google_role_id[$google_role_id] = $role_key;

                $existing_role_id = (int) $db->scalar(
                    "SELECT id FROM {$roles_table} WHERE role_domain = 'workspace' AND role_key = %s LIMIT 1",
                    [ $role_key ]
                );
                if ($existing_role_id > 0) {
                    $db->update(
                        $roles_table,
                        ['role_name' => $role_label, 'description' => $google_role_description !== '' ? $google_role_description : null, 'is_system' => 1],
                        ['id' => $existing_role_id],
                        ['%s', '%s', '%d'],
                        ['%d']
                    );
                } else {
                    $db->insert($roles_table, [
                        'role_key' => $role_key,
                        'role_domain' => 'workspace',
                        'role_name' => $role_label,
                        'description' => $google_role_description !== '' ? $google_role_description : 'Imported from Google Workspace admin roles.',
                        'is_system' => 1,
                    ], ['%s', '%s', '%s', '%s', '%d']);
                }
            }
            $roles_page_token = trim((string) ($roles_resp['body']['nextPageToken'] ?? ''));
            if ($roles_page_token === '') break;
        }

        if ($role_sync_error === '') {
            $assignments_by_workspace_user = [];
            $assign_page_token = '';
            $assign_pages = 0;
            while ($assign_pages < 50) {
                $assign_pages++;
                $assign_query = "customer/{$customer}/roleassignments?maxResults=100";
                if ($assign_page_token !== '') {
                    $assign_query .= '&pageToken=' . rawurlencode($assign_page_token);
                }
                $assign_resp = metis_people_workspace_google_request('GET', $assign_query, null, $cfg);
                if (empty($assign_resp['ok'])) {
                    $role_sync_error = 'Failed to fetch Workspace role assignments.';
                    break;
                }
                $assignments = (array) ($assign_resp['body']['items'] ?? []);
                foreach ($assignments as $assignment) {
                    $role_assignments_seen++;
                    $assigned_to = trim((string) ($assignment['assignedTo'] ?? ''));
                    $google_role_id = trim((string) ($assignment['roleId'] ?? ''));
                    if ($assigned_to === '' || $google_role_id === '') continue;
                    if (!isset($local_workspace_user_by_google_id[$assigned_to])) continue;
                    if (!isset($role_key_by_google_role_id[$google_role_id])) continue;
                    $local_workspace_user_id = (int) $local_workspace_user_by_google_id[$assigned_to];
                    if ($local_workspace_user_id < 1) continue;
                    $role_key = (string) $role_key_by_google_role_id[$google_role_id];
                    if (!isset($assignments_by_workspace_user[$local_workspace_user_id])) {
                        $assignments_by_workspace_user[$local_workspace_user_id] = [];
                    }
                    $assignments_by_workspace_user[$local_workspace_user_id][$role_key] = true;
                }
                $assign_page_token = trim((string) ($assign_resp['body']['nextPageToken'] ?? ''));
                if ($assign_page_token === '') break;
            }

            if ($role_sync_error === '') {
                $imported_workspace_user_ids = array_values(array_unique(array_map('intval', $imported_workspace_user_ids)));
                foreach ($imported_workspace_user_ids as $local_workspace_user_id) {
                    if ($local_workspace_user_id < 1) continue;
                    $db->delete($user_roles_table, ['workspace_user_id' => $local_workspace_user_id], ['%d']);
                    $role_keys = array_keys((array) ($assignments_by_workspace_user[$local_workspace_user_id] ?? []));
                    foreach ($role_keys as $role_key) {
                        $inserted = $db->insert($user_roles_table, [
                            'workspace_user_id' => $local_workspace_user_id,
                            'role_key' => $role_key,
                        ], ['%d', '%s']);
                        if ($inserted) $roles_synced++;
                    }
                }
            }
        }
    }

    $groups_imported = 0;
    $groups_created = 0;
    $groups_updated = 0;
    $groups_removed = 0;
    $group_members_synced = 0;
    $group_sync_error = '';
    $seen_workspace_group_emails = [];
    if ($include_groups) {
        $workspace_user_email_rows = $db->fetchAll(
            "SELECT id, primary_email FROM {$users_table} WHERE primary_email IS NOT NULL AND primary_email <> ''"
        ) ?: [];
        $workspace_user_id_by_email = [];
        foreach ($workspace_user_email_rows as $row) {
            $email_key = strtolower(trim((string) ($row['primary_email'] ?? '')));
            $wid = (int) ($row['id'] ?? 0);
            if ($email_key === '' || $wid < 1) continue;
            $workspace_user_id_by_email[$email_key] = $wid;
        }

        $group_page_token = '';
        $group_pages = 0;
        while ($groups_imported < $groups_limit && $group_pages < 20) {
            $group_pages++;
            $remaining = $groups_limit - $groups_imported;
            $page_size = min(100, $remaining);
            $group_query = 'groups?customer=' . $customer_query_value . '&maxResults=' . $page_size . '&orderBy=email';
            if ($group_page_token !== '') {
                $group_query .= '&pageToken=' . rawurlencode($group_page_token);
            }
            $group_resp = metis_people_workspace_google_request('GET', $group_query, null, $cfg);
            if (empty($group_resp['ok'])) {
                $group_sync_error = 'Failed to fetch groups from Workspace.';
                break;
            }
            $groups = (array) ($group_resp['body']['groups'] ?? []);
            if (empty($groups)) break;

            foreach ($groups as $group_row) {
                if ($groups_imported >= $groups_limit) break;
                $group_email = strtolower(trim((string) ($group_row['email'] ?? '')));
                if (!metis_email_is_valid($group_email)) continue;
                $seen_workspace_group_emails[$group_email] = true;
                $group_name = trim((string) ($group_row['name'] ?? ''));
                if ($group_name === '') $group_name = $group_email;
                $google_group_id = trim((string) ($group_row['id'] ?? ''));
                $description = trim((string) ($group_row['description'] ?? ''));

                $existing_group_id = (int) $db->scalar(
                    "SELECT id FROM {$groups_table} WHERE group_email = %s LIMIT 1",
                    [ $group_email ]
                );
                $group_payload = [
                    'workspace_group_id' => $google_group_id !== '' ? $google_group_id : null,
                    'group_email' => $group_email,
                    'group_name' => $group_name,
                    'description' => $description !== '' ? $description : null,
                    'source' => 'workspace',
                    'sync_status' => 'synced',
                ];
                if ($existing_group_id > 0) {
                    $ok = $db->update(
                        $groups_table,
                        $group_payload,
                        ['id' => $existing_group_id],
                        ['%s', '%s', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );
                    $local_group_id = $existing_group_id;
                    if ($ok !== false) $groups_updated++;
                } else {
                    $ok = $db->insert(
                        $groups_table,
                        $group_payload,
                        ['%s', '%s', '%s', '%s', '%s', '%s']
                    );
                    $local_group_id = $ok ? (int) $db->lastInsertId() : 0;
                    if ($ok) $groups_created++;
                }

                if ($local_group_id > 0) {
                    $member_ids = [];
                    $member_page_token = '';
                    $member_pages = 0;
                    while ($member_pages < 20) {
                        $member_pages++;
                        $members_query = 'groups/' . rawurlencode($group_email) . '/members?maxResults=100';
                        if ($member_page_token !== '') {
                            $members_query .= '&pageToken=' . rawurlencode($member_page_token);
                        }
                        $members_resp = metis_people_workspace_google_request('GET', $members_query, null, $cfg);
                        if (empty($members_resp['ok'])) {
                            break;
                        }
                        $members = (array) ($members_resp['body']['members'] ?? []);
                        foreach ($members as $member_row) {
                            $member_email = strtolower(trim((string) ($member_row['email'] ?? '')));
                            $member_type = strtolower(trim((string) ($member_row['type'] ?? '')));
                            if ($member_email === '' || $member_type === 'group') continue;
                            $workspace_member_id = (int) ($workspace_user_id_by_email[$member_email] ?? 0);
                            if ($workspace_member_id < 1) continue;
                            $member_ids[$workspace_member_id] = strtolower(trim((string) ($member_row['role'] ?? 'member')));
                        }
                        $member_page_token = trim((string) ($members_resp['body']['nextPageToken'] ?? ''));
                        if ($member_page_token === '') break;
                    }

                    $db->delete($group_members_table, ['group_id' => $local_group_id], ['%d']);
                    $inserted_members = 0;
                    foreach ($member_ids as $workspace_member_id => $member_role) {
                        if (!in_array($member_role, ['member', 'manager', 'owner'], true)) {
                            $member_role = 'member';
                        }
                        $inserted = $db->insert($group_members_table, [
                            'group_id' => $local_group_id,
                            'workspace_user_id' => (int) $workspace_member_id,
                            'member_role' => $member_role,
                        ], ['%d', '%d', '%s']);
                        if ($inserted) $inserted_members++;
                    }
                    $group_members_synced += $inserted_members;
                    $db->update(
                        $groups_table,
                        ['direct_members_count' => $inserted_members, 'sync_status' => 'synced'],
                        ['id' => $local_group_id],
                        ['%d', '%s'],
                        ['%d']
                    );
                }
                $groups_imported++;
            }

            $group_page_token = trim((string) ($group_resp['body']['nextPageToken'] ?? ''));
            if ($group_page_token === '') break;
        }

        // Reconcile deletions: remove Workspace-sourced groups that no longer exist in Google.
        if ($group_sync_error === '') {
            $existing_workspace_groups = $db->fetchAll(
                "SELECT id, group_email
                 FROM {$groups_table}
                 WHERE source = 'workspace'
                    OR (workspace_group_id IS NOT NULL AND workspace_group_id <> '')"
            ) ?: [];
            foreach ($existing_workspace_groups as $existing_group) {
                $existing_group_id = (int) ($existing_group['id'] ?? 0);
                $existing_group_email = strtolower(trim((string) ($existing_group['group_email'] ?? '')));
                if ($existing_group_id < 1 || $existing_group_email === '') continue;
                if (isset($seen_workspace_group_emails[$existing_group_email])) continue;
                $db->delete($group_members_table, ['group_id' => $existing_group_id], ['%d']);
                $deleted_group = $db->delete($groups_table, ['id' => $existing_group_id, 'source' => 'workspace'], ['%d', '%s']);
                if ($deleted_group) $groups_removed++;
            }
        }
    }

    metis_people_log_activity(null, 'workspace_directory_import', 'Imported existing Google Workspace users', [
        'imported' => $imported,
        'created' => $created,
        'updated' => $updated,
        'linked' => $linked,
        'roles_synced' => $roles_synced,
        'role_assignments_seen' => $role_assignments_seen,
        'role_sync_error' => $role_sync_error,
        'groups_imported' => $groups_imported,
        'groups_created' => $groups_created,
        'groups_updated' => $groups_updated,
        'groups_removed' => $groups_removed,
        'group_members_synced' => $group_members_synced,
        'group_sync_error' => $group_sync_error,
        'full_sync' => $include_groups ? 1 : 0,
    ]);

    return [
        'ok' => true,
        'imported' => $imported,
        'created' => $created,
        'updated' => $updated,
        'linked' => $linked,
        'roles_synced' => $roles_synced,
        'role_assignments_seen' => $role_assignments_seen,
        'role_sync_error' => $role_sync_error,
        'groups_imported' => $groups_imported,
        'groups_created' => $groups_created,
        'groups_updated' => $groups_updated,
        'groups_removed' => $groups_removed,
        'group_members_synced' => $group_members_synced,
        'group_sync_error' => $group_sync_error,
        'full_sync' => $include_groups ? 1 : 0,
    ];
}

metis_ajax_register_handler( 'metis_people_workspace_import_directory_users', function () {
    metis_people_workspace_ajax_verify();
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Workspace configuration is missing.', 400);
    }
    $limit = isset($_POST['limit']) ? (int) metis_runtime_unslash($_POST['limit']) : 500;
    $result = metis_people_workspace_import_directory_snapshot($cfg, $limit, false, 0);
    if (empty($result['ok'])) {
        metis_runtime_send_json_error('Import failed.', 400);
    }
    unset($result['ok']);
    metis_runtime_send_json_success($result);
});

metis_ajax_register_handler( 'metis_people_workspace_full_sync_directory', function () {
    metis_people_workspace_ajax_verify();
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Workspace configuration is missing.', 400);
    }
    $user_limit = isset($_POST['user_limit']) ? (int) metis_runtime_unslash($_POST['user_limit']) : 800;
    $group_limit = isset($_POST['group_limit']) ? (int) metis_runtime_unslash($_POST['group_limit']) : 400;
    $result = metis_people_workspace_import_directory_snapshot($cfg, $user_limit, true, $group_limit);
    if (empty($result['ok'])) {
        metis_runtime_send_json_error('Full sync failed.', 400);
    }
    unset($result['ok']);
    metis_runtime_send_json_success($result);
});

metis_ajax_register_handler( 'metis_people_workspace_get_role_map', function () {
    metis_people_workspace_ajax_verify();
    $db = metis_db();
    $roles_table = Metis_Tables::get('people_roles');
    $workspace_users_table = Metis_Tables::get('people_workspace_users');
    $workspace_user_roles_table = Metis_Tables::get('people_workspace_user_roles');

    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Workspace configuration is missing.', 400);
    }
    $customer = trim((string) ($cfg['customer_id'] ?? ''));
    if ($customer === '') $customer = 'my_customer';

    $roles = [];
    $page_token = '';
    $page_guard = 0;
    while ($page_guard < 20) {
        $page_guard++;
        $query = "customer/{$customer}/roles?maxResults=100";
        if ($page_token !== '') {
            $query .= '&pageToken=' . rawurlencode($page_token);
        }
        $resp = metis_people_workspace_google_request('GET', $query, null, $cfg);
        if (empty($resp['ok'])) {
            metis_runtime_send_json_error('Failed to fetch workspace roles.', 400);
        }
        $items = (array) ($resp['body']['items'] ?? []);
        foreach ($items as $role_row) {
            $roles[] = $role_row;
        }
        $page_token = trim((string) ($resp['body']['nextPageToken'] ?? ''));
        if ($page_token === '') break;
    }

    $assignments_by_role_id = [];
    $assign_page_token = '';
    $assign_guard = 0;
    while ($assign_guard < 50) {
        $assign_guard++;
        $assign_query = "customer/{$customer}/roleassignments?maxResults=100";
        if ($assign_page_token !== '') {
            $assign_query .= '&pageToken=' . rawurlencode($assign_page_token);
        }
        $assign_resp = metis_people_workspace_google_request('GET', $assign_query, null, $cfg);
        if (empty($assign_resp['ok'])) {
            break;
        }
        $assign_items = (array) ($assign_resp['body']['items'] ?? []);
        foreach ($assign_items as $assign_row) {
            $rid = trim((string) ($assign_row['roleId'] ?? ''));
            if ($rid === '') continue;
            if (!isset($assignments_by_role_id[$rid])) $assignments_by_role_id[$rid] = 0;
            $assignments_by_role_id[$rid]++;
        }
        $assign_page_token = trim((string) ($assign_resp['body']['nextPageToken'] ?? ''));
        if ($assign_page_token === '') break;
    }

    $out = [];
    foreach ($roles as $role_row) {
        $google_role_id = trim((string) ($role_row['roleId'] ?? ''));
        $google_role_name = trim((string) ($role_row['roleName'] ?? ''));
        $google_role_description = trim((string) ($role_row['roleDescription'] ?? ''));
        if ($google_role_id === '' || $google_role_name === '') continue;
        $resolved = metis_people_workspace_resolve_role_meta($google_role_name, $google_role_description);
        $metis_role_key = (string) ($resolved['role_key'] ?? '');
        $friendly_name = (string) ($resolved['role_label'] ?? $google_role_name);
        if ($metis_role_key === '') continue;

        $existing_role_id = (int) $db->scalar(
            "SELECT id FROM {$roles_table} WHERE role_domain = 'workspace' AND role_key = %s LIMIT 1",
            [ $metis_role_key ]
        );
        if ($existing_role_id > 0) {
            $db->update(
                $roles_table,
                ['role_name' => $friendly_name, 'description' => $google_role_description !== '' ? $google_role_description : null, 'is_system' => 1],
                ['id' => $existing_role_id],
                ['%s', '%s', '%d'],
                ['%d']
            );
        } else {
            $db->insert($roles_table, [
                'role_key' => $metis_role_key,
                'role_domain' => 'workspace',
                'role_name' => $friendly_name,
                'description' => $google_role_description !== '' ? $google_role_description : 'Imported from Google Workspace admin roles.',
                'is_system' => 1,
            ], ['%s', '%s', '%s', '%s', '%d']);
        }

        $out[] = [
            'friendly_name' => $friendly_name,
            'google_role_name' => $google_role_name,
            'google_role_id' => $google_role_id,
            'metis_role_key' => $metis_role_key,
            'description' => $google_role_description,
            'assigned_count' => (int) ($assignments_by_role_id[$google_role_id] ?? 0),
        ];
    }

    usort($out, static function ($a, $b) {
        return strcasecmp((string) ($a['friendly_name'] ?? ''), (string) ($b['friendly_name'] ?? ''));
    });

    // Keep local per-user role rows in sync for known users.
    $user_rows = $db->fetchAll("SELECT id, workspace_user_id FROM {$workspace_users_table} WHERE workspace_user_id IS NOT NULL AND workspace_user_id <> ''") ?: [];
    $local_workspace_user_by_google_id = [];
    foreach ($user_rows as $user_row) {
        $local_id = (int) ($user_row['id'] ?? 0);
        $google_id = trim((string) ($user_row['workspace_user_id'] ?? ''));
        if ($local_id < 1 || $google_id === '') continue;
        $local_workspace_user_by_google_id[$google_id] = $local_id;
    }
    if (!empty($local_workspace_user_by_google_id)) {
        $assignments_by_local_user = [];
        $assign_page_token = '';
        $assign_guard = 0;
        while ($assign_guard < 50) {
            $assign_guard++;
            $assign_query = "customer/{$customer}/roleassignments?maxResults=100";
            if ($assign_page_token !== '') $assign_query .= '&pageToken=' . rawurlencode($assign_page_token);
            $assign_resp = metis_people_workspace_google_request('GET', $assign_query, null, $cfg);
            if (empty($assign_resp['ok'])) break;
            $assign_items = (array) ($assign_resp['body']['items'] ?? []);
            foreach ($assign_items as $assign_row) {
                $assigned_to = trim((string) ($assign_row['assignedTo'] ?? ''));
                $rid = trim((string) ($assign_row['roleId'] ?? ''));
                if ($assigned_to === '' || $rid === '') continue;
                $local_user_id = (int) ($local_workspace_user_by_google_id[$assigned_to] ?? 0);
                if ($local_user_id < 1) continue;
                $google_role_name = '';
                $google_role_desc = '';
                foreach ($roles as $rrow) {
                    if (trim((string) ($rrow['roleId'] ?? '')) !== $rid) continue;
                    $google_role_name = trim((string) ($rrow['roleName'] ?? ''));
                    $google_role_desc = trim((string) ($rrow['roleDescription'] ?? ''));
                    break;
                }
                $resolved = metis_people_workspace_resolve_role_meta($google_role_name, $google_role_desc);
                $role_key = (string) ($resolved['role_key'] ?? '');
                if ($role_key === '') continue;
                if (!isset($assignments_by_local_user[$local_user_id])) $assignments_by_local_user[$local_user_id] = [];
                $assignments_by_local_user[$local_user_id][$role_key] = true;
            }
            $assign_page_token = trim((string) ($assign_resp['body']['nextPageToken'] ?? ''));
            if ($assign_page_token === '') break;
        }

        foreach ($local_workspace_user_by_google_id as $google_user_id => $local_user_id) {
            $local_user_id = (int) $local_user_id;
            if ($local_user_id < 1) continue;
            $db->delete($workspace_user_roles_table, ['workspace_user_id' => $local_user_id], ['%d']);
            $role_keys = array_keys((array) ($assignments_by_local_user[$local_user_id] ?? []));
            foreach ($role_keys as $role_key) {
                if ($role_key === '') continue;
                $db->insert($workspace_user_roles_table, [
                    'workspace_user_id' => $local_user_id,
                    'role_key' => $role_key,
                ], ['%d', '%s']);
            }
        }
    }

    metis_runtime_send_json_success([
        'roles' => $out,
        'total_roles' => count($out),
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_inspect_user_attributes', function () {
    metis_people_workspace_ajax_verify();
    $email = strtolower(trim((string) (isset($_POST['email']) ? metis_email_clean(metis_runtime_unslash($_POST['email'])) : '')));
    if (!metis_email_is_valid($email)) {
        metis_runtime_send_json_error('A valid user email is required.', 400);
    }
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Workspace configuration is missing.', 400);
    }
    $user_resp = metis_people_workspace_google_request('GET', 'users/' . rawurlencode($email) . '?projection=full', null, $cfg);
    if (empty($user_resp['ok'])) {
        metis_runtime_send_json_error('Failed to load workspace user.', 400);
    }
    $user_body = (array) ($user_resp['body'] ?? []);
    $custom_schemas = (array) ($user_body['customSchemas'] ?? []);

    $schema_resp_data = ['ok' => false, 'error' => '', 'schemas' => []];
    $customer = trim((string) ($cfg['customer_id'] ?? ''));
    if ($customer === '') $customer = 'my_customer';
    $cfg_schemas = $cfg;
    $cfg_schemas['scopes'] = array_values(array_unique(array_merge(
        (array) ($cfg['scopes'] ?? []),
        ['https://www.googleapis.com/auth/admin.directory.userschema.readonly']
    )));
    $schema_resp = metis_people_workspace_google_request('GET', 'customer/' . rawurlencode($customer) . '/schemas', null, $cfg_schemas);
    if (!empty($schema_resp['ok'])) {
        $schemas = (array) ($schema_resp['body']['schemas'] ?? []);
        $out = [];
        foreach ($schemas as $schema) {
            $schema_name = (string) ($schema['schemaName'] ?? '');
            if ($schema_name === '') continue;
            $fields = [];
            foreach ((array) ($schema['fields'] ?? []) as $field) {
                $field_name = (string) ($field['fieldName'] ?? '');
                if ($field_name === '') continue;
                $fields[] = [
                    'fieldName' => $field_name,
                    'displayName' => (string) ($field['displayName'] ?? ''),
                    'fieldType' => (string) ($field['fieldType'] ?? ''),
                    'readAccessType' => (string) ($field['readAccessType'] ?? ''),
                ];
            }
            $out[] = ['schemaName' => $schema_name, 'fields' => $fields];
        }
        $schema_resp_data['ok'] = true;
        $schema_resp_data['schemas'] = $out;
    } else {
        $schema_resp_data['error'] = 'Schema read failed.';
    }

    metis_runtime_send_json_success([
        'email' => $email,
        'customSchemas' => $custom_schemas,
        'schemaDirectory' => $schema_resp_data,
    ]);
});
