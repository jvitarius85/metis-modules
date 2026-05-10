<?php
if (!defined('METIS_ROOT')) exit;

function metis_drive_ajax_verify(bool $manage = false): void {
    $allowed = $manage
        ? ( function_exists( 'metis_drive_can_manage' ) && metis_drive_can_manage() )
        : ( function_exists( 'metis_drive_can' ) && metis_drive_can( 'view' ) );
    if ( ! $allowed ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

function metis_drive_ajax_cfg_from_request(): array {
    $requested_drive_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['drive_id'] ?? ''));
    return metis_drive_workspace_settings($requested_drive_id);
}

function metis_drive_ajax_users_home_cfg(): array {
    $users_home = metis_drive_users_home_setting();
    $drive_id = trim((string) ($users_home['drive_id'] ?? ''));
    if ($drive_id === '') {
        return metis_drive_workspace_settings();
    }
    return metis_drive_workspace_settings($drive_id);
}

function metis_drive_cache_version(): int {
    return max(1, (int) metis_get_option('metis_drive_cache_version', 1));
}

function metis_drive_bump_cache_version(): void {
    metis_update_option('metis_drive_cache_version', metis_drive_cache_version() + 1, false);
}

function metis_drive_list_cache_key(string $kind, string $drive_id, string $folder_id, string $search = ''): string {
    $is_manage = metis_drive_can_manage() ? 'm1' : 'm0';
    $uid = (int) metis_current_user_id();
    $version = metis_drive_cache_version();
    return 'metis_drive_' . $kind . '_' . md5($version . '|' . $is_manage . '|' . $uid . '|' . $drive_id . '|' . $folder_id . '|' . strtolower($search));
}

function metis_drive_drive_name(array $cfg): string {
    $label = trim((string) ($cfg['shared_drive_label'] ?? ''));
    if ($label !== '') return $label;
    $name = trim((string) ($cfg['shared_drive_name'] ?? ''));
    if ($name !== '') return $name;
    return (string) ($cfg['shared_drive_id'] ?? '');
}

function metis_drive_cached_folder_payload(array $cfg, string $folder_id, string $search, string $users_root_id, string $own_folder_id): array {
    $shared_drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    $folder_id = metis_drive_normalize_parent_id($shared_drive_id, $folder_id);
    $root_mode = ($folder_id === $shared_drive_id);
    $search = trim($search);
    $is_search_mode = ($search !== '');
    $files = $is_search_mode
        ? metis_drive_cached_search_results($shared_drive_id, $search, false)
        : metis_drive_cached_folder_children($shared_drive_id, $folder_id, '', false);
    $sync_state = metis_drive_sync_state($shared_drive_id, $folder_id);
    $users_root_name = strtolower(metis_drive_users_root_folder_name());

    if ($root_mode && !$is_search_mode) {
        $files = array_values(array_filter($files, static function ($item) use ($users_root_id, $users_root_name): bool {
            $id = (string) ($item['id'] ?? '');
            $name = strtolower((string) ($item['name'] ?? ''));
            $is_folder = (string) ($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder';
            if ($users_root_id !== '' && $id === $users_root_id) {
                return false;
            }
            if ($is_folder && $name === $users_root_name) {
                return false;
            }
            return true;
        }));
    }

    $parent_id = '';
    $folder_name = '';
    if (!$root_mode) {
        $folder_item = metis_drive_get_cached_item($shared_drive_id, $folder_id);
        $folder_state = metis_drive_sync_state($shared_drive_id, $folder_id);
        $parent_id = (string) (($folder_item['parent_id'] ?? '') ?: ($folder_state['parent_folder_id'] ?? ''));
        $folder_name = (string) (($folder_item['item_name'] ?? '') ?: ($folder_state['folder_name'] ?? ''));

        if ($users_root_id !== '' && $parent_id === $users_root_id) {
            $parent_id = $shared_drive_id;
        }
        if ($own_folder_id !== '' && $folder_id === $own_folder_id) {
            $parent_id = $shared_drive_id;
        }
    }

    $drive_name = metis_drive_drive_name($cfg);
    if ($folder_name === '') {
        $folder_name = $drive_name;
    }
    if ($is_search_mode) {
        $folder_name = 'Search Results';
    }

    return [
        'shared_drive_id' => $shared_drive_id,
        'shared_drive_name' => $drive_name,
        'shared_drive_label' => (string) ($cfg['shared_drive_label'] ?? $drive_name),
        'folder_id' => $folder_id,
        'folder_name' => $folder_name,
        'parent_id' => $is_search_mode ? $folder_id : $parent_id,
        'own_folder_id' => $own_folder_id,
        'users_root_id' => $users_root_id,
        'search' => $search,
        'is_search_results' => $is_search_mode,
        'cache' => [
            'status' => (string) ($sync_state['sync_status'] ?? 'idle'),
            'last_synced_at' => (string) ($sync_state['last_synced_at'] ?? ''),
            'is_cold' => empty($sync_state['last_synced_at']),
        ],
        'files' => $files,
    ];
}

function metis_drive_cached_tree_payload(array $cfg, string $folder_id, string $users_root_id, string $own_folder_id): array {
    $shared_drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    $folder_id = metis_drive_normalize_parent_id($shared_drive_id, $folder_id);
    $folders = metis_drive_cached_folder_children($shared_drive_id, $folder_id, '', true);
    $sync_state = metis_drive_sync_state($shared_drive_id, $folder_id);
    $users_root_name = strtolower(metis_drive_users_root_folder_name());

    if ($folder_id === $shared_drive_id) {
        $folders = array_values(array_filter($folders, static function ($item) use ($users_root_id, $users_root_name): bool {
            $id = (string) ($item['id'] ?? '');
            $name = strtolower((string) ($item['name'] ?? ''));
            if ($users_root_id !== '' && $id === $users_root_id) {
                return false;
            }
            if ($name === $users_root_name) {
                return false;
            }
            return true;
        }));
    }

    return [
        'folder_id' => $folder_id,
        'shared_drive_id' => $shared_drive_id,
        'own_folder_id' => $own_folder_id,
        'users_root_id' => $users_root_id,
        'cache' => [
            'status' => (string) ($sync_state['sync_status'] ?? 'idle'),
            'last_synced_at' => (string) ($sync_state['last_synced_at'] ?? ''),
            'is_cold' => empty($sync_state['last_synced_at']),
        ],
        'folders' => array_values(array_map(static function (array $folder): array {
            return [
                'id' => (string) ($folder['id'] ?? ''),
                'name' => (string) ($folder['name'] ?? 'Folder'),
                'parent_id' => (string) (($folder['parents'][0] ?? '')),
                'has_children' => !empty($folder['hasChildren']),
            ];
        }, $folders)),
    ];
}

function metis_drive_guard_in_shared_drive(string $file_id, array $cfg): array {
    if ($file_id === '') return ['ok' => false, 'error' => 'File ID is required.'];
    $url = metis_add_query_arg([
        'fields' => 'id,driveId,mimeType,parents,name,webViewLink,modifiedTime,size',
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
    ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id));
    $resp = metis_drive_google_request('GET', $url, null, $cfg);
    if (empty($resp['ok'])) return $resp;
    $body = (array) ($resp['body'] ?? []);
    $drive_id = (string) ($body['driveId'] ?? '');
    if ($drive_id !== (string) ($cfg['shared_drive_id'] ?? '')) {
        return ['ok' => false, 'error' => 'That file is not in the configured Shared Drive.'];
    }
    return ['ok' => true, 'file' => $body];
}

function metis_drive_guard_in_shared_drive_cached(string $file_id, array $cfg): array {
    if ($file_id === '') return ['ok' => false, 'error' => 'File ID is required.'];
    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    $cached = metis_drive_cached_item_meta($drive_id, $file_id);
    if (empty($cached['ok'])) {
        $own_folder = metis_drive_user_folder_mapping_fast($cfg, 0);
        if (!empty($own_folder['ok']) && (string) ($own_folder['folder_id'] ?? '') === $file_id) {
            $cached = [
                'ok' => true,
                'file' => [
                    'id' => (string) ($own_folder['folder_id'] ?? ''),
                    'name' => (string) ($own_folder['folder_name'] ?? ''),
                    'mimeType' => 'application/vnd.google-apps.folder',
                    'parents' => [(string) ($own_folder['parent_folder_id'] ?? '')],
                    'driveId' => $drive_id,
                    'webViewLink' => (string) ($own_folder['web_view_link'] ?? ''),
                    'modifiedTime' => '',
                    'size' => '',
                ],
            ];
        } else {
            return ['ok' => false, 'error' => 'Item is not cached.'];
        }
    }
    $body = (array) ($cached['file'] ?? []);
    if ((string) ($body['driveId'] ?? '') !== $drive_id) {
        return ['ok' => false, 'error' => 'That file is not in the configured Shared Drive.'];
    }
    return ['ok' => true, 'file' => $body];
}

function metis_drive_ajax_guard_target_folder(array $cfg, string $folder_id): array {
    $shared_drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    if ($folder_id === '' || $folder_id === $shared_drive_id) {
        return [
            'ok' => true,
            'folder' => [
                'id' => $shared_drive_id,
                'name' => metis_drive_drive_name($cfg),
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [],
                'driveId' => $shared_drive_id,
            ],
        ];
    }

    $guard = metis_drive_guard_in_shared_drive($folder_id, $cfg);
    if (empty($guard['ok'])) {
        return ['ok' => false, 'error' => 'Invalid target folder.'];
    }
    if ((string) (($guard['file']['mimeType'] ?? '')) !== 'application/vnd.google-apps.folder') {
        return ['ok' => false, 'error' => 'Target item is not a folder.'];
    }

    return [
        'ok' => true,
        'folder' => (array) ($guard['file'] ?? []),
    ];
}

function metis_drive_register_ajax_controllers(): void {
    $actions = [
        'metis_drive_list' => 'view',
        'metis_drive_my_folder' => 'view',
        'metis_drive_tree_children' => 'view',
        'metis_drive_sync_worker' => 'view',
        'metis_drive_create_folder' => 'edit',
        'metis_drive_upload_file' => 'edit',
        'metis_drive_create_google_file' => 'edit',
        'metis_drive_move_item' => 'edit',
        'metis_drive_copy_item' => 'edit',
        'metis_drive_rename' => 'edit',
        'metis_drive_trash' => 'edit',
    ];

    foreach ($actions as $action => $permission) {
        metis_ajax_register_controller($action, [
            'module' => 'drive',
            'permission' => $permission,
            'nonce_action' => metis_ajax_nonce_action($action),
        ]);
    }
}

metis_drive_register_ajax_controllers();

metis_ajax_register_handler( 'metis_drive_list', function () {
    metis_drive_ajax_verify(false);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);
    $can_manage = metis_drive_can_manage();

    $folder_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_id'] ?? ''));
    $search = metis_text_clean(metis_runtime_unslash(metis_request_post()['search'] ?? ''));
    if ($folder_id === '') $folder_id = (string) $cfg['shared_drive_id'];
    $shared_drive_id = (string) $cfg['shared_drive_id'];

    $users_root_id = '';
    $users_root_resp = metis_drive_get_users_root_folder_cached($cfg);
    if (!empty($users_root_resp['ok'])) {
        $users_root_id = (string) ($users_root_resp['folder_id'] ?? '');
    }
    $own_folder_id = '';
    $own_folder = metis_drive_user_folder_mapping_fast($cfg, 0, 0);
    if (!empty($own_folder['ok'])) {
        $own_folder_id = (string) ($own_folder['folder_id'] ?? '');
    }

    if ($folder_id !== $shared_drive_id) {
        $guard = metis_drive_guard_in_shared_drive_cached($folder_id, $cfg);
        if (empty($guard['ok'])) metis_runtime_send_json_error('Invalid folder.', 400);
        if ((string) (($guard['file']['mimeType'] ?? '')) !== 'application/vnd.google-apps.folder') {
            metis_runtime_send_json_error('Selected item is not a folder.', 400);
        }
        if ($users_root_id !== '' && $folder_id === $users_root_id) {
            metis_runtime_send_json_error('You cannot open the Users container directly.', 403);
        }
        if (!$can_manage && $users_root_id !== '') {
            $inside_users_root = metis_drive_folder_is_descendant_of_cached($cfg, $folder_id, $users_root_id);
            if ($inside_users_root) {
                if ($own_folder_id === '') {
                    metis_runtime_send_json_error('Your personal folder is not attached yet.', 403);
                }
                $is_own_or_child = ($folder_id === $own_folder_id) || metis_drive_folder_is_descendant_of_cached($cfg, $folder_id, $own_folder_id);
                if (!$is_own_or_child) {
                    metis_runtime_send_json_error('You cannot access another user folder.', 403);
                }
            }
        }
    }

    $root_mode = ($folder_id === $shared_drive_id);
    $cache_key = metis_drive_list_cache_key('list', $shared_drive_id, $folder_id, $search);
    $cached = metis_get_transient($cache_key);
    if (is_array($cached) && !empty($cached['folder_id'])) {
        metis_runtime_send_json_success($cached);
    }

    metis_drive_mark_folder_requested($shared_drive_id, $folder_id);
    $payload = metis_drive_cached_folder_payload($cfg, $folder_id, $search, $users_root_id, $own_folder_id);
    metis_set_transient($cache_key, $payload, 20);
    metis_runtime_send_json_success($payload);
});

metis_ajax_register_handler( 'metis_drive_my_folder', function () {
    metis_drive_ajax_verify(false);
    metis_drive_ensure_schema();
    $person_id = metis_drive_current_person_id();
    $resolved = function_exists('metis_drive_resolve_user_folder_context')
        ? metis_drive_resolve_user_folder_context($person_id)
        : ['ok' => false];

    if (!empty($resolved['ok'])) {
        $cfg = (array) ($resolved['cfg'] ?? []);
        $mapping = (array) ($resolved['mapping'] ?? []);
        metis_runtime_send_json_success([
            'drive_id' => (string) ($cfg['shared_drive_id'] ?? ''),
            'shared_drive_label' => (string) ($cfg['shared_drive_label'] ?? ''),
            'folder_id' => (string) ($mapping['folder_id'] ?? ''),
            'folder_name' => (string) ($mapping['folder_name'] ?? ''),
        ]);
    }

    $cfg = metis_drive_ajax_users_home_cfg();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);
    $folder = metis_drive_find_or_create_user_folder($cfg, $person_id, true);
    if (empty($folder['ok'])) {
        metis_runtime_send_json_error('Failed to find or create your folder.', 500);
    }
    if (!empty($folder['created'])) {
        metis_drive_log_action($cfg, 'create_user_folder', [
            'folder_id' => (string) ($folder['folder_id'] ?? ''),
            'item_name' => (string) ($folder['folder_name'] ?? ''),
            'item_type' => 'folder',
            'details' => ['parent_folder_id' => (string) ($folder['parent_folder_id'] ?? '')],
        ]);
    }

    metis_runtime_send_json_success([
        'drive_id' => (string) ($cfg['shared_drive_id'] ?? ''),
        'shared_drive_label' => (string) ($cfg['shared_drive_label'] ?? ''),
        'folder_id' => (string) ($folder['folder_id'] ?? ''),
        'folder_name' => (string) ($folder['folder_name'] ?? ''),
    ]);
});

metis_ajax_register_handler( 'metis_drive_tree_children', function () {
    metis_drive_ajax_verify(false);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);
    $can_manage = metis_drive_can_manage();

    $shared_drive_id = (string) $cfg['shared_drive_id'];
    $folder_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_id'] ?? ''));
    if ($folder_id === '') $folder_id = $shared_drive_id;

    $users_root_id = '';
    $users_root_resp = metis_drive_get_users_root_folder_cached($cfg);
    if (!empty($users_root_resp['ok'])) {
        $users_root_id = (string) ($users_root_resp['folder_id'] ?? '');
    }
    $own_folder_id = '';
    $own_folder = metis_drive_user_folder_mapping_fast($cfg, 0, 0);
    if (!empty($own_folder['ok'])) {
        $own_folder_id = (string) ($own_folder['folder_id'] ?? '');
    }

    if ($folder_id !== $shared_drive_id) {
        $guard = metis_drive_guard_in_shared_drive_cached($folder_id, $cfg);
        if (empty($guard['ok'])) metis_runtime_send_json_error('Invalid folder.', 400);
        if ((string) (($guard['file']['mimeType'] ?? '')) !== 'application/vnd.google-apps.folder') {
            metis_runtime_send_json_error('Selected item is not a folder.', 400);
        }
        if ($users_root_id !== '' && $folder_id === $users_root_id) {
            metis_runtime_send_json_error('You cannot open the Users container directly.', 403);
        }
        if (!$can_manage && $users_root_id !== '') {
            $inside_users_root = metis_drive_folder_is_descendant_of_cached($cfg, $folder_id, $users_root_id);
            if ($inside_users_root) {
                if ($own_folder_id === '') {
                    metis_runtime_send_json_error('Your personal folder is not attached yet.', 403);
                }
                $is_own_or_child = ($folder_id === $own_folder_id) || metis_drive_folder_is_descendant_of_cached($cfg, $folder_id, $own_folder_id);
                if (!$is_own_or_child) {
                    metis_runtime_send_json_error('You cannot access another user folder.', 403);
                }
            }
        }
    }

    $cache_key = metis_drive_list_cache_key('tree', $shared_drive_id, $folder_id, '');
    $cached = metis_get_transient($cache_key);
    if (is_array($cached) && !empty($cached['folder_id'])) {
        metis_runtime_send_json_success($cached);
    }

    metis_drive_mark_folder_requested($shared_drive_id, $folder_id);
    $payload = metis_drive_cached_tree_payload($cfg, $folder_id, $users_root_id, $own_folder_id);
    metis_set_transient($cache_key, $payload, 20);
    metis_runtime_send_json_success($payload);
});

metis_ajax_register_handler( 'metis_drive_sync_worker', function () {
    metis_drive_ajax_verify(false);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);

    $folder_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_id'] ?? ''));
    if ($folder_id === '') {
        $folder_id = (string) ($cfg['shared_drive_id'] ?? '');
    }

    $force = !empty(metis_request_post()['force']);
    $depth = max(0, min(metis_drive_sync_max_depth(), (int) (metis_request_post()['depth'] ?? metis_drive_sync_max_depth())));
    if (!$force && !metis_drive_sync_needs_refresh((string) ($cfg['shared_drive_id'] ?? ''), $folder_id, metis_drive_background_sync_interval())) {
        metis_runtime_send_json_success(['status' => 'fresh']);
    }

    $result = metis_drive_sync_worker($cfg, $folder_id, $depth, $force);
    if (empty($result['ok'])) {
        metis_runtime_send_json_error('Drive sync failed.', 500);
    }

    metis_runtime_send_json_success($result);
});

metis_ajax_register_handler( 'metis_drive_create_folder', function () {
    metis_drive_ajax_verify(true);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);

    $parent_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['parent_id'] ?? ''));
    $folder_name = metis_text_clean(metis_runtime_unslash(metis_request_post()['folder_name'] ?? ''));
    if ($parent_id === '') $parent_id = (string) $cfg['shared_drive_id'];
    if ($folder_name === '') metis_runtime_send_json_error('Folder name is required.', 422);

    if ($parent_id !== (string) $cfg['shared_drive_id']) {
        $guard = metis_drive_guard_in_shared_drive($parent_id, $cfg);
        if (empty($guard['ok'])) metis_runtime_send_json_error('Invalid parent folder.', 400);
    }

    $payload = [
        'name' => $folder_name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parent_id],
    ];
    $url = metis_add_query_arg([
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'fields' => 'id,name,mimeType,webViewLink,parents,driveId',
    ], 'https://www.googleapis.com/drive/v3/files');

    $resp = metis_drive_google_request('POST', $url, metis_json_encode($payload), $cfg);
    if (empty($resp['ok'])) metis_runtime_send_json_error('Failed to create folder.', 500);
    metis_drive_log_action($cfg, 'create_folder', [
        'folder_id' => (string) (($resp['body']['id'] ?? '') ?: $parent_id),
        'file_id' => (string) ($resp['body']['id'] ?? ''),
        'item_name' => (string) ($resp['body']['name'] ?? $folder_name),
        'item_type' => 'folder',
        'details' => ['parent_id' => $parent_id],
    ]);
    metis_drive_bump_cache_version();
    metis_drive_sync_folder_listing($cfg, $parent_id, 0, true);
    metis_runtime_send_json_success(['file' => (array) ($resp['body'] ?? [])]);
});

metis_ajax_register_handler( 'metis_drive_upload_file', function () {
    metis_drive_ajax_verify(true);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);

    $parent_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['parent_id'] ?? ''));
    if ($parent_id === '') $parent_id = (string) $cfg['shared_drive_id'];
    if ($parent_id !== (string) $cfg['shared_drive_id']) {
        $guard = metis_drive_guard_in_shared_drive($parent_id, $cfg);
        if (empty($guard['ok'])) metis_runtime_send_json_error('Invalid parent folder.', 400);
    }

    if (empty(metis_request_files()['file']) || !is_array(metis_request_files()['file'])) metis_runtime_send_json_error('File is required.', 422);
    $file = metis_request_files()['file'];
    $tmp = (string) ($file['tmp_name'] ?? '');
    $name = metis_filename_clean((string) ($file['name'] ?? ''));
    $size = (int) ($file['size'] ?? 0);
    $max_bytes = 25 * 1024 * 1024;
    if ($tmp === '' || $name === '' || !file_exists($tmp) || !is_uploaded_file($tmp)) metis_runtime_send_json_error('Invalid upload payload.', 422);
    if ($size < 1 || $size > $max_bytes) metis_runtime_send_json_error('Uploaded file exceeds the 25MB limit.', 413);
    $bytes = file_get_contents($tmp);
    if ($bytes === false) metis_runtime_send_json_error('Could not read uploaded file.', 500);
    $mime = function_exists('mime_content_type') ? (string) mime_content_type($tmp) : '';
    if ($mime === '') $mime = (string) ($file['type'] ?? 'application/octet-stream');

    $token = metis_drive_google_access_token($cfg);
    if (empty($token['ok'])) metis_runtime_send_json_error('Workspace token error.', 500);

    $meta = ['name' => $name, 'parents' => [$parent_id], 'driveId' => (string) $cfg['shared_drive_id']];
    $boundary = 'metis_drive_' . metis_runtime_generate_password(12, false, false);
    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= metis_json_encode($meta) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mime}\r\n\r\n";
    $body .= $bytes . "\r\n";
    $body .= "--{$boundary}--";

    $upload_url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&includeItemsFromAllDrives=true&fields=id,name,mimeType,modifiedTime,size,webViewLink,parents,driveId';
    $upload_url = metis_add_query_arg(['useDomainAdminAccess' => 'true'], $upload_url);
    $upload = metis_runtime_remote_post($upload_url, [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . (string) $token['access_token'],
            'Content-Type' => 'multipart/related; boundary=' . $boundary,
        ],
        'body' => $body,
    ]);
    if (metis_runtime_is_error($upload)) metis_runtime_send_json_error('Drive upload request failed.', 500);
    $code = (int) metis_runtime_remote_retrieve_response_code($upload);
    $raw = (string) metis_runtime_remote_retrieve_body($upload);
    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300 || !is_array($decoded) || empty($decoded['id'])) {
        metis_runtime_send_json_error('Failed to upload file to Drive.', 500);
    }
    metis_drive_log_action($cfg, 'upload_file', [
        'folder_id' => $parent_id,
        'file_id' => (string) ($decoded['id'] ?? ''),
        'item_name' => (string) ($decoded['name'] ?? $name),
        'item_type' => (string) ($decoded['mimeType'] ?? $mime),
        'details' => ['size' => (string) ($decoded['size'] ?? '')],
    ]);
    metis_drive_bump_cache_version();
    metis_drive_sync_folder_listing($cfg, $parent_id, 0, true);
    metis_runtime_send_json_success(['file' => $decoded]);
});

metis_ajax_register_handler( 'metis_drive_create_google_file', function () {
    metis_drive_ajax_verify(true);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);

    $parent_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['parent_id'] ?? ''));
    if ($parent_id === '') $parent_id = (string) $cfg['shared_drive_id'];
    if ($parent_id !== (string) $cfg['shared_drive_id']) {
        $guard = metis_drive_guard_in_shared_drive($parent_id, $cfg);
        if (empty($guard['ok'])) metis_runtime_send_json_error('Invalid parent folder.', 400);
    }

    $name = metis_text_clean(metis_runtime_unslash(metis_request_post()['name'] ?? ''));
    $type = metis_key_clean(metis_runtime_unslash(metis_request_post()['google_type'] ?? ''));
    $type_map = [
        'doc' => 'application/vnd.google-apps.document',
        'sheet' => 'application/vnd.google-apps.spreadsheet',
        'slides' => 'application/vnd.google-apps.presentation',
        'form' => 'application/vnd.google-apps.form',
    ];
    $mime_type = (string) ($type_map[$type] ?? '');
    if ($mime_type === '') {
        metis_runtime_send_json_error('Invalid Google file type.', 422);
    }
    if ($name === '') {
        $name = ucfirst($type) . ' ' . gmdate('Y-m-d H:i');
    }

    $url = metis_add_query_arg([
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'fields' => 'id,name,mimeType,modifiedTime,size,webViewLink,parents,driveId',
    ], 'https://www.googleapis.com/drive/v3/files');
    $resp = metis_drive_google_request('POST', $url, metis_json_encode([
        'name' => $name,
        'mimeType' => $mime_type,
        'parents' => [$parent_id],
    ]), $cfg);
    if (empty($resp['ok'])) {
        metis_runtime_send_json_error('Failed to create Google file.', 500);
    }
    metis_drive_log_action($cfg, 'create_google_file', [
        'folder_id' => $parent_id,
        'file_id' => (string) ($resp['body']['id'] ?? ''),
        'item_name' => (string) ($resp['body']['name'] ?? $name),
        'item_type' => (string) ($resp['body']['mimeType'] ?? $mime_type),
        'details' => ['google_type' => $type],
    ]);
    metis_drive_bump_cache_version();
    metis_drive_sync_folder_listing($cfg, $parent_id, 0, true);
    metis_runtime_send_json_success(['file' => (array) ($resp['body'] ?? [])]);
});

metis_ajax_register_handler( 'metis_drive_move_item', function () {
    metis_drive_ajax_verify(true);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);

    $file_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['file_id'] ?? ''));
    $target_parent_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['target_parent_id'] ?? ''));
    if ($file_id === '') metis_runtime_send_json_error('File is required.', 422);
    if ($target_parent_id === '') $target_parent_id = (string) ($cfg['shared_drive_id'] ?? '');

    $guard = metis_drive_guard_in_shared_drive($file_id, $cfg);
    if (empty($guard['ok'])) metis_runtime_send_json_error('Invalid file.', 400);
    $file = (array) ($guard['file'] ?? []);

    $target_guard = metis_drive_ajax_guard_target_folder($cfg, $target_parent_id);
    if (empty($target_guard['ok'])) metis_runtime_send_json_error('Invalid target folder.', 400);

    $source_parent_id = (string) (($file['parents'][0] ?? '') ?: (string) ($cfg['shared_drive_id'] ?? ''));
    if ($source_parent_id === $target_parent_id) {
        metis_runtime_send_json_success([
            'file' => $file,
            'noop' => true,
        ]);
    }

    if ((string) ($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
        if ($file_id === $target_parent_id) {
            metis_runtime_send_json_error('A folder cannot be moved into itself.', 422);
        }
        if (metis_drive_folder_is_descendant_of_cached($cfg, $target_parent_id, $file_id)) {
            metis_runtime_send_json_error('A folder cannot be moved into one of its descendants.', 422);
        }
    }

    $url = metis_add_query_arg([
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'addParents' => $target_parent_id,
        'removeParents' => $source_parent_id,
        'fields' => 'id,name,mimeType,modifiedTime,size,webViewLink,parents,driveId',
    ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id));

    $resp = metis_drive_google_request('PATCH', $url, metis_json_encode((object) []), $cfg);
    if (empty($resp['ok'])) metis_runtime_send_json_error('Failed to move item.', 500);

    metis_drive_log_action($cfg, 'move_item', [
        'folder_id' => $target_parent_id,
        'file_id' => $file_id,
        'item_name' => (string) ($resp['body']['name'] ?? ($file['name'] ?? '')),
        'item_type' => (string) ($resp['body']['mimeType'] ?? ($file['mimeType'] ?? '')),
        'details' => [
            'source_parent_id' => $source_parent_id,
            'target_parent_id' => $target_parent_id,
        ],
    ]);
    metis_drive_bump_cache_version();
    metis_drive_sync_folder_listing($cfg, $source_parent_id, 0, true);
    if ($target_parent_id !== $source_parent_id) {
        metis_drive_sync_folder_listing($cfg, $target_parent_id, 0, true);
    }

    metis_runtime_send_json_success(['file' => (array) ($resp['body'] ?? [])]);
});

metis_ajax_register_handler( 'metis_drive_copy_item', function () {
    metis_drive_ajax_verify(true);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);

    $file_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['file_id'] ?? ''));
    $target_parent_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['target_parent_id'] ?? ''));
    $name = metis_text_clean(metis_runtime_unslash(metis_request_post()['name'] ?? ''));
    if ($file_id === '') metis_runtime_send_json_error('File is required.', 422);
    if ($target_parent_id === '') $target_parent_id = (string) ($cfg['shared_drive_id'] ?? '');

    $guard = metis_drive_guard_in_shared_drive($file_id, $cfg);
    if (empty($guard['ok'])) metis_runtime_send_json_error('Invalid file.', 400);
    $file = (array) ($guard['file'] ?? []);
    if ((string) ($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
        metis_runtime_send_json_error('Folders cannot be copied yet.', 422);
    }

    $target_guard = metis_drive_ajax_guard_target_folder($cfg, $target_parent_id);
    if (empty($target_guard['ok'])) metis_runtime_send_json_error('Invalid target folder.', 400);

    $payload = ['parents' => [$target_parent_id]];
    if ($name !== '') {
        $payload['name'] = $name;
    }

    $url = metis_add_query_arg([
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'fields' => 'id,name,mimeType,modifiedTime,size,webViewLink,parents,driveId',
    ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '/copy');

    $resp = metis_drive_google_request('POST', $url, metis_json_encode($payload), $cfg);
    if (empty($resp['ok'])) metis_runtime_send_json_error('Failed to copy item.', 500);

    metis_drive_log_action($cfg, 'copy_item', [
        'folder_id' => $target_parent_id,
        'file_id' => (string) ($resp['body']['id'] ?? ''),
        'item_name' => (string) ($resp['body']['name'] ?? ($name !== '' ? $name : ($file['name'] ?? ''))),
        'item_type' => (string) ($resp['body']['mimeType'] ?? ($file['mimeType'] ?? '')),
        'details' => [
            'source_file_id' => $file_id,
            'target_parent_id' => $target_parent_id,
        ],
    ]);
    metis_drive_bump_cache_version();
    metis_drive_sync_folder_listing($cfg, $target_parent_id, 0, true);

    metis_runtime_send_json_success(['file' => (array) ($resp['body'] ?? [])]);
});

metis_ajax_register_handler( 'metis_drive_rename', function () {
    metis_drive_ajax_verify(true);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);

    $file_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['file_id'] ?? ''));
    $name = metis_text_clean(metis_runtime_unslash(metis_request_post()['name'] ?? ''));
    if ($file_id === '' || $name === '') metis_runtime_send_json_error('File and new name are required.', 422);
    $guard = metis_drive_guard_in_shared_drive($file_id, $cfg);
    if (empty($guard['ok'])) metis_runtime_send_json_error('Invalid file.', 400);

    $url = metis_add_query_arg([
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'fields' => 'id,name,mimeType,modifiedTime,size,webViewLink,parents,driveId',
    ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id));

    $resp = metis_drive_google_request('PATCH', $url, metis_json_encode(['name' => $name]), $cfg);
    if (empty($resp['ok'])) metis_runtime_send_json_error('Failed to rename file.', 500);
    metis_drive_log_action($cfg, 'rename_item', [
        'folder_id' => (string) (($guard['file']['parents'][0] ?? '') ?: ''),
        'file_id' => $file_id,
        'item_name' => (string) ($resp['body']['name'] ?? $name),
        'item_type' => (string) ($resp['body']['mimeType'] ?? ($guard['file']['mimeType'] ?? '')),
        'details' => ['old_name' => (string) ($guard['file']['name'] ?? '')],
    ]);
    metis_drive_bump_cache_version();
    metis_drive_sync_folder_listing($cfg, (string) (($guard['file']['parents'][0] ?? '') ?: (string) $cfg['shared_drive_id']), 0, true);
    metis_runtime_send_json_success(['file' => (array) ($resp['body'] ?? [])]);
});

metis_ajax_register_handler( 'metis_drive_trash', function () {
    metis_drive_ajax_verify(true);
    metis_drive_ensure_schema();
    $cfg = metis_drive_ajax_cfg_from_request();
    if (empty($cfg['ok'])) metis_runtime_send_json_error('Workspace Drive configuration is unavailable.', 400);

    $file_id = metis_text_clean(metis_runtime_unslash(metis_request_post()['file_id'] ?? ''));
    if ($file_id === '') metis_runtime_send_json_error('File is required.', 422);
    $guard = metis_drive_guard_in_shared_drive($file_id, $cfg);
    if (empty($guard['ok'])) metis_runtime_send_json_error('Invalid file.', 400);

    $url = metis_add_query_arg([
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'fields' => 'id,trashed',
    ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id));
    $resp = metis_drive_google_request('PATCH', $url, metis_json_encode(['trashed' => true]), $cfg);
    if (empty($resp['ok'])) metis_runtime_send_json_error('Failed to move file to trash.', 500);
    metis_drive_log_action($cfg, 'trash_item', [
        'folder_id' => (string) (($guard['file']['parents'][0] ?? '') ?: ''),
        'file_id' => $file_id,
        'item_name' => (string) ($guard['file']['name'] ?? ''),
        'item_type' => (string) ($guard['file']['mimeType'] ?? ''),
    ]);
    metis_drive_bump_cache_version();
    metis_drive_sync_folder_listing($cfg, (string) (($guard['file']['parents'][0] ?? '') ?: (string) $cfg['shared_drive_id']), 0, true);
    metis_runtime_send_json_success(['file_id' => $file_id]);
});
