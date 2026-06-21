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
            'permission' => 'workspace_manage',
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
}

if (!function_exists('metis_people_workspace_role_keys_for_user')) {
    function metis_people_workspace_role_keys_for_user(int $workspace_user_id): array {
        return \Metis\Modules\People\WorkspaceUserService::roleKeysForUser($workspace_user_id);
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
        if (in_array($key, ['completed', 'synced'], true)) return 'metis-chip-success';
        if ($key === 'failed') return 'metis-chip-danger';
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
        return \Metis\Modules\People\WorkspaceActivityService::payload($sync_page, $security_page, $sync_page_size, $security_page_size);
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

if (!function_exists('metis_people_workspace_send_welcome_email')) {
    function metis_people_workspace_send_welcome_email(string $workspace_email, string $recovery_email, string $display_name, string $temporary_password): array {
        $workspace_email = strtolower(trim($workspace_email));
        $recovery_email = strtolower(trim($recovery_email));
        $temporary_password = (string) $temporary_password;
        if (!metis_email_is_valid($workspace_email)) {
            return ['ok' => false, 'error' => 'Workspace email is invalid.'];
        }
        if (!metis_email_is_valid($recovery_email)) {
            return ['ok' => false, 'error' => 'Recovery email is required for welcome delivery.'];
        }
        if ($temporary_password === '') {
            return ['ok' => false, 'error' => 'Temporary password was not available for welcome delivery.'];
        }
        if (!class_exists('\Metis\Core\Services\EmailService')) {
            $email_service_path = dirname(__DIR__, 3) . '/src/Metis/Core/Services/EmailService.php';
            if (is_readable($email_service_path)) {
                require_once $email_service_path;
            }
        }
        if (!class_exists('\Metis\Core\Services\EmailService')) {
            return ['ok' => false, 'error' => 'Metis email service is not available.'];
        }

        $organization = 'Metis';
        $accent_color = '#4657d9';
        if (class_exists('Core_Settings_Service')) {
            $configured = trim((string) Core_Settings_Service::get('login_organization_name', ''));
            if ($configured !== '') {
                $organization = $configured;
            }
            $theme_colors = Core_Settings_Service::get('theme_colors', []);
            if (is_array($theme_colors)) {
                $candidate = metis_hex_color_clean((string) ($theme_colors['primary'] ?? $theme_colors['accent'] ?? ''));
                if ($candidate !== '') {
                    $accent_color = $candidate;
                }
            }
        }
        $login_url = function_exists('metis_auth_google_callback_url')
            ? metis_auth_google_callback_url()
            : (function_exists('metis_auth_login_url') ? metis_auth_login_url() : metis_home_url('/login'));
        $logo_url = function_exists('metis_portal_logo_url') ? trim((string) metis_portal_logo_url()) : '';
        if ($logo_url !== '' && str_starts_with($logo_url, '/')) {
            $logo_url = metis_home_url($logo_url);
        }
        $recipient_name = trim($display_name) !== '' ? trim($display_name) : $workspace_email;
        $subject = 'Your ' . $organization . ' Workspace account is ready';
        $logo_html = $logo_url !== ''
            ? '<tr><td style="padding:0 0 22px;"><img src="' . metis_escape_url($logo_url) . '" alt="' . metis_escape_attr($organization) . '" style="display:block;max-width:220px;max-height:82px;width:auto;height:auto;border:0;"></td></tr>'
            : '';
        $html = '<div style="margin:0;padding:0;background:#f6f8fc;font-family:Arial,Helvetica,sans-serif;color:#172033;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f6f8fc;margin:0;padding:0;">'
            . '<tr><td align="center" style="padding:32px 16px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;max-width:640px;background:#ffffff;border:1px solid #d9e0ee;border-radius:14px;overflow:hidden;">'
            . '<tr><td style="padding:34px 36px 30px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;">'
            . $logo_html
            . '<tr><td style="padding:0 0 8px;color:' . metis_escape_attr($accent_color) . ';font-size:13px;line-height:18px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">Workspace account ready</td></tr>'
            . '<tr><td style="padding:0 0 14px;font-size:28px;line-height:34px;font-weight:800;color:#172033;">Welcome, ' . metis_escape_html($recipient_name) . '</td></tr>'
            . '<tr><td style="padding:0 0 22px;font-size:16px;line-height:24px;color:#4f5f78;">Your Workspace account has been created. Start with Google sign-in so Google can guide you through changing your temporary password.</td></tr>'
            . '<tr><td style="padding:18px 20px;background:#f8faff;border:1px solid #dfe6f5;border-radius:12px;">'
            . '<div style="font-size:12px;line-height:16px;font-weight:700;color:#697791;text-transform:uppercase;letter-spacing:.8px;">Workspace email</div>'
            . '<div style="padding:4px 0 16px;font-size:17px;line-height:24px;font-weight:700;color:#172033;">' . metis_escape_html($workspace_email) . '</div>'
            . '<div style="font-size:12px;line-height:16px;font-weight:700;color:#697791;text-transform:uppercase;letter-spacing:.8px;">Temporary password</div>'
            . '<div style="padding:6px 10px;margin-top:5px;display:inline-block;background:#ffffff;border:1px solid #d9e0ee;border-radius:8px;font-family:Consolas,Menlo,monospace;font-size:16px;line-height:22px;font-weight:700;color:#172033;">' . metis_escape_html($temporary_password) . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:24px 0 8px;"><a href="' . metis_escape_url($login_url) . '" style="display:inline-block;background:' . metis_escape_attr($accent_color) . ';color:#ffffff;text-decoration:none;font-size:16px;line-height:20px;font-weight:700;padding:13px 20px;border-radius:10px;">Sign in with Google</a></td></tr>'
            . '<tr><td style="padding:10px 0 0;font-size:14px;line-height:21px;color:#697791;">If the button does not open, go to your Metis login page and choose <strong>Sign in with Google</strong>.</td></tr>'
            . '<tr><td style="padding:20px 0 0;font-size:13px;line-height:20px;color:#8a96aa;">If you were not expecting this account, contact your system administrator.</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</div>';

        return \Metis\Core\Services\EmailService::sendHtml($recovery_email, $subject, $html, [
            'module' => 'people',
            'internal_reference' => 'workspace_welcome',
            'from_name' => $organization,
        ]);
    }
}

metis_ajax_register_handler( 'metis_people_workspace_save_user', function () {
    metis_people_workspace_ajax_verify();
    $workspace_user_id = isset(metis_request_post()['workspace_user_id']) ? (int) metis_runtime_unslash(metis_request_post()['workspace_user_id']) : 0;
    $primary_email = strtolower(trim((string) (isset(metis_request_post()['primary_email']) ? metis_email_clean(metis_runtime_unslash(metis_request_post()['primary_email'])) : '')));
    $first_name = isset(metis_request_post()['first_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['first_name'])) : '';
    $last_name = isset(metis_request_post()['last_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['last_name'])) : '';
    $display_name = isset(metis_request_post()['display_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['display_name'])) : '';
    $org_unit_path = isset(metis_request_post()['org_unit_path']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['org_unit_path'])) : '/';
    $secondary_email = strtolower(trim((string) (isset(metis_request_post()['secondary_email']) ? metis_email_clean(metis_runtime_unslash(metis_request_post()['secondary_email'])) : '')));
    $recovery_email = strtolower(trim((string) (isset(metis_request_post()['recovery_email']) ? metis_email_clean(metis_runtime_unslash(metis_request_post()['recovery_email'])) : '')));
    if ($recovery_email === '' && $secondary_email !== '') {
        $recovery_email = $secondary_email;
    }
    $linked_pid = strtoupper(trim((string) (isset(metis_request_post()['linked_pid']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['linked_pid'])) : '')));
    $is_suspended = !empty(metis_request_post()['is_suspended']) ? 1 : 0;
    $is_protected = !empty(metis_request_post()['is_protected']) ? 1 : 0;
    $is_hidden = !empty(metis_request_post()['is_hidden']) ? 1 : 0;
    $create_metis_user = !empty(metis_request_post()['create_metis_user']);
    $create_drive_folder = !empty(metis_request_post()['create_drive_folder']);

    $role_keys = [];
    if (isset(metis_request_post()['role_keys'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['role_keys']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $key) {
                $rk = metis_key_clean((string) $key);
                if ($rk !== '') $role_keys[] = $rk;
            }
        }
    }
    $role_keys = array_values(array_unique($role_keys));
    $group_ids = [];
    if (isset(metis_request_post()['group_ids'])) {
        $decoded_groups = json_decode((string) metis_runtime_unslash(metis_request_post()['group_ids']), true);
        if (is_array($decoded_groups)) {
            foreach ($decoded_groups as $gid) {
                $group_id = (int) $gid;
                if ($group_id > 0) $group_ids[] = $group_id;
            }
        }
    }
    $group_ids = array_values(array_unique($group_ids));
    $actor = metis_people_get_current_person_id();
    $save = \Metis\Modules\People\WorkspaceUserService::saveUser([
        'workspace_user_id' => $workspace_user_id,
        'primary_email' => $primary_email,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => $display_name,
        'org_unit_path' => $org_unit_path,
        'secondary_email' => $secondary_email,
        'recovery_email' => $recovery_email,
        'linked_pid' => $linked_pid,
        'is_suspended' => $is_suspended,
        'is_protected' => $is_protected,
        'is_hidden' => $is_hidden,
        'create_metis_user' => $create_metis_user,
        'create_drive_folder' => $create_drive_folder,
        'role_keys' => $role_keys,
        'group_ids' => $group_ids,
    ], $actor > 0 ? $actor : null);

    if (!empty($save['existing'])) {
        metis_runtime_send_json_success([
            'workspace_user_id' => (int) ($save['workspace_user_id'] ?? 0),
            'sync_warning' => (string) ($save['sync_warning'] ?? ''),
            'user' => $save['user'] ?? [],
        ]);
    }

    $person_id = isset($save['person_id']) ? (int) $save['person_id'] : null;
    metis_people_log_activity($person_id ?: null, 'workspace_user_saved', 'Saved workspace user profile', [
        'workspace_user_id' => (int) ($save['workspace_user_id'] ?? 0),
        'primary_email' => (string) (($save['user']['primary_email'] ?? $primary_email)),
        'job_id' => (int) ($save['job_id'] ?? 0),
    ]);

    metis_runtime_send_json_success([
        'workspace_user_id' => (int) ($save['workspace_user_id'] ?? 0),
        'job_id' => (int) ($save['job_id'] ?? 0),
        'metis_user' => $save['metis_user'] ?? null,
        'drive_folder' => $save['drive_folder'] ?? null,
        'sync_warning' => (string) ($save['sync_warning'] ?? ''),
        'user' => $save['user'] ?? [],
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_set_user_flags', function () {
    metis_people_workspace_ajax_verify();
    $workspace_user_id = isset(metis_request_post()['workspace_user_id']) ? (int) metis_runtime_unslash(metis_request_post()['workspace_user_id']) : 0;
    if ($workspace_user_id < 1) {
        metis_runtime_send_json_error('Workspace user is required.', 400);
    }

    $has_hidden = isset(metis_request_post()['is_hidden']);
    $has_protected = isset(metis_request_post()['is_protected']);
    $result = \Metis\Modules\People\WorkspaceUserService::updateFlags(
        $workspace_user_id,
        $has_hidden,
        $has_protected,
        !empty(metis_request_post()['is_hidden']) ? 1 : 0,
        !empty(metis_request_post()['is_protected']) ? 1 : 0
    );

    metis_people_log_activity(null, 'workspace_user_flags_updated', 'Updated workspace email user flags', [
        'workspace_user_id' => (int) ($result['workspace_user_id'] ?? $workspace_user_id),
        'primary_email' => (string) ($result['primary_email'] ?? ''),
        'is_hidden' => (int) ($result['is_hidden'] ?? 0),
        'is_protected' => (int) ($result['is_protected'] ?? 0),
    ]);

    metis_runtime_send_json_success([
        'workspace_user_id' => $workspace_user_id,
        'user' => [
            'is_hidden' => (int) ($result['is_hidden'] ?? 0),
            'is_protected' => (int) ($result['is_protected'] ?? 0),
            'is_suspended' => (int) ($result['is_suspended'] ?? 0),
        ],
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_create_metis_user', function () {
    metis_people_workspace_ajax_verify();
    $workspace_user_id = isset(metis_request_post()['workspace_user_id']) ? (int) metis_runtime_unslash(metis_request_post()['workspace_user_id']) : 0;
    if ($workspace_user_id < 1) {
        metis_runtime_send_json_error('Workspace user is required.', 400);
    }
    $result = \Metis\Modules\People\WorkspaceUserService::createMetisUser($workspace_user_id);

    metis_runtime_send_json_success([
        'person_id' => (int) ($result['person_id'] ?? 0),
        'pid' => (string) ($result['pid'] ?? ''),
        'already_linked' => !empty($result['already_linked']) ? 1 : 0,
        'person_url' => (string) ($result['person_url'] ?? ''),
        'workspace_user_id' => $workspace_user_id,
        'drive_folder' => $result['drive_folder'] ?? null,
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_delete_user', function () {
    metis_people_workspace_ajax_verify();
    $workspace_user_id = isset(metis_request_post()['workspace_user_id']) ? (int) metis_runtime_unslash(metis_request_post()['workspace_user_id']) : 0;
    if ($workspace_user_id < 1) {
        metis_runtime_send_json_error('Workspace user is required.', 400);
    }
    $result = \Metis\Modules\People\WorkspaceUserService::deleteUser($workspace_user_id);
    metis_people_log_activity(null, 'workspace_user_deleted', 'Deleted workspace email user', [
        'workspace_user_id' => (int) ($result['workspace_user_id'] ?? $workspace_user_id),
        'primary_email' => (string) ($result['primary_email'] ?? ''),
    ]);
    metis_runtime_send_json_success(['workspace_user_id' => $workspace_user_id]);
});

metis_ajax_register_handler( 'metis_people_workspace_run_security_action', function () {
    metis_people_workspace_ajax_verify();
    $workspace_user_id = isset(metis_request_post()['workspace_user_id']) ? (int) metis_runtime_unslash(metis_request_post()['workspace_user_id']) : 0;
    $action_type = isset(metis_request_post()['action_type']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['action_type'])) : '';
    $reason = isset(metis_request_post()['reason']) ? metis_textarea_clean(metis_runtime_unslash(metis_request_post()['reason'])) : '';
    $actor = metis_people_get_current_person_id();
    $result = \Metis\Modules\People\WorkspaceUserService::queueSecurityAction(
        $workspace_user_id,
        $action_type,
        $reason,
        $actor > 0 ? $actor : null
    );
    metis_people_log_activity((int) ($result['person_id'] ?? 0) ?: null, 'workspace_security_action', 'Queued workspace security action', [
        'workspace_user_id' => (int) ($result['workspace_user_id'] ?? $workspace_user_id),
        'primary_email' => (string) ($result['primary_email'] ?? ''),
        'action_type' => (string) ($result['action_type'] ?? $action_type),
        'job_id' => (int) ($result['job_id'] ?? 0),
    ]);
    metis_runtime_send_json_success([
        'ok' => 1,
        'job_id' => (int) ($result['job_id'] ?? 0),
        'action_type' => (string) ($result['action_type'] ?? $action_type),
    ]);
});

metis_ajax_register_handler( 'metis_people_workspace_get_activity_page', function () {
    metis_people_workspace_ajax_verify();
    $sync_page = isset(metis_request_post()['sync_page']) ? (int) metis_runtime_unslash(metis_request_post()['sync_page']) : 1;
    $security_page = isset(metis_request_post()['security_page']) ? (int) metis_runtime_unslash(metis_request_post()['security_page']) : 1;
    if ($sync_page < 1) $sync_page = 1;
    if ($security_page < 1) $security_page = 1;
    $payload = metis_people_workspace_activity_payload($sync_page, $security_page, 12, 12);
    metis_runtime_send_json_success($payload);
});

metis_ajax_register_handler( 'metis_people_bulk_workspace_user_action', function () {
    metis_people_workspace_ajax_verify();
    $action_type = isset(metis_request_post()['workspace_action']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['workspace_action'])) : '';
    $org_unit_path = isset(metis_request_post()['org_unit_path']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['org_unit_path'])) : '/';
    $person_pids = [];
    if (isset(metis_request_post()['person_pids'])) {
        $decoded = json_decode((string) metis_runtime_unslash(metis_request_post()['person_pids']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $pid) {
                $clean = strtoupper(trim((string) metis_text_clean((string) $pid)));
                if ($clean !== '') $person_pids[] = $clean;
            }
        }
    }
    $person_pids = array_values(array_unique($person_pids));
    $actor = metis_people_get_current_person_id();
    $result = \Metis\Modules\People\WorkspaceUserService::bulkAction(
        $action_type,
        $org_unit_path,
        $person_pids,
        $actor > 0 ? $actor : null
    );

    metis_people_log_activity(null, 'bulk_workspace_user_action', 'Ran bulk workspace user action', [
        'workspace_action' => (string) ($result['workspace_action'] ?? $action_type),
        'org_unit_path' => (string) ($result['org_unit_path'] ?? ''),
        'updated' => (int) ($result['updated'] ?? 0),
        'skipped' => (int) ($result['skipped'] ?? 0),
        'failed' => (int) ($result['failed'] ?? 0),
    ]);

    metis_runtime_send_json_success([
        'updated' => (int) ($result['updated'] ?? 0),
        'skipped' => (int) ($result['skipped'] ?? 0),
        'failed' => (int) ($result['failed'] ?? 0),
        'errors' => (array) ($result['errors'] ?? []),
    ]);
});

metis_ajax_register_handler( 'metis_people_attach_drive_folder', function () {
    metis_people_workspace_ajax_verify();
    if (
        !function_exists('metis_drive_workspace_settings')
        || !function_exists('metis_drive_find_or_create_user_folder')
        || !function_exists('metis_drive_ensure_schema')
        || !function_exists('metis_drive_log_action')
    ) {
        metis_runtime_send_json_error('Drive module is not available.', 400);
    }

    $person_id = isset(metis_request_post()['person_id']) ? (int) metis_runtime_unslash(metis_request_post()['person_id']) : 0;
    $pid = isset(metis_request_post()['pid']) ? trim(metis_text_clean(metis_runtime_unslash(metis_request_post()['pid']))) : '';
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
    metis_people_workspace_ajax_verify();
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

    $folder_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_id'] ?? ''));
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
    metis_people_workspace_ajax_verify();
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

    $person_id = isset(metis_request_post()['person_id']) ? (int) metis_runtime_unslash(metis_request_post()['person_id']) : 0;
    $pid = isset(metis_request_post()['pid']) ? trim(metis_text_clean(metis_runtime_unslash(metis_request_post()['pid']))) : '';
    $folder_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_id'] ?? ''));
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
    if ($person_id_value > 0) {
        $expected_folder_name = \Metis\Modules\People\PersonProfileService::expectedFolderName($person_id_value);
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
    $limit = isset(metis_request_post()['limit']) ? (int) metis_runtime_unslash(metis_request_post()['limit']) : 10;
    $job_id = isset(metis_request_post()['job_id']) ? (int) metis_runtime_unslash(metis_request_post()['job_id']) : 0;
    $dry_run = !empty(metis_request_post()['dry_run']) ? true : false;
    $run_all = !empty(metis_request_post()['run_all']) ? true : false;
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
    $total['remaining_queued'] = \Metis\Modules\People\WorkspaceActivityService::countQueuedJobs();
    $total['loops'] = $loops;
    metis_runtime_send_json_success($total);
});

function metis_people_workspace_import_directory_snapshot(array $cfg, int $limit = 500, bool $include_groups = false, int $groups_limit = 300): array {
    return \Metis\Modules\People\WorkspaceDirectoryService::importDirectorySnapshot($cfg, $limit, $include_groups, $groups_limit);
}

metis_ajax_register_handler( 'metis_people_workspace_import_directory_users', function () {
    metis_people_workspace_ajax_verify();
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Workspace configuration is missing.', 400);
    }
    $limit = isset(metis_request_post()['limit']) ? (int) metis_runtime_unslash(metis_request_post()['limit']) : 500;
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
    $user_limit = isset(metis_request_post()['user_limit']) ? (int) metis_runtime_unslash(metis_request_post()['user_limit']) : 800;
    $group_limit = isset(metis_request_post()['group_limit']) ? (int) metis_runtime_unslash(metis_request_post()['group_limit']) : 400;
    $result = metis_people_workspace_import_directory_snapshot($cfg, $user_limit, true, $group_limit);
    if (empty($result['ok'])) {
        metis_runtime_send_json_error('Full sync failed.', 400);
    }
    unset($result['ok']);
    metis_runtime_send_json_success($result);
});

metis_ajax_register_handler( 'metis_people_workspace_get_role_map', function () {
    metis_people_workspace_ajax_verify();
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Workspace configuration is missing.', 400);
    }
    $result = \Metis\Modules\People\WorkspaceDirectoryService::roleMap($cfg);
    if (empty($result['ok'])) {
        metis_runtime_send_json_error((string) ($result['error'] ?? 'Failed to fetch workspace roles.'), 400);
    }
    unset($result['ok'], $result['error']);
    metis_runtime_send_json_success($result);
});

metis_ajax_register_handler( 'metis_people_workspace_inspect_user_attributes', function () {
    metis_people_workspace_ajax_verify();
    $email = strtolower(trim((string) (isset(metis_request_post()['email']) ? metis_email_clean(metis_runtime_unslash(metis_request_post()['email'])) : '')));
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
