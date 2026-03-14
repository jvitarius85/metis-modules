<?php
if (!defined('ABSPATH')) exit;
Metis_Logger::info( 'Drive bootstrap loaded' );

function metis_drive_ensure_schema(): void {
    static $done = false;
    if ($done) return;

    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $audit_table = Metis_Tables::get('drive_audit');
    $user_folders_table = Metis_Tables::get('drive_user_folders');
    $items_table = Metis_Tables::get('drive_items');
    $sync_state_table = Metis_Tables::get('drive_sync_state');

    if ( ! function_exists( 'dbDelta' ) ) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }

    $audit_sql = "CREATE TABLE {$audit_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        drive_id VARCHAR(191) NOT NULL,
        folder_id VARCHAR(191) DEFAULT NULL,
        file_id VARCHAR(191) DEFAULT NULL,
        item_name VARCHAR(255) DEFAULT NULL,
        item_type VARCHAR(64) DEFAULT NULL,
        action_key VARCHAR(64) NOT NULL,
        actor_person_id BIGINT UNSIGNED DEFAULT NULL,
        details_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY drive_id (drive_id),
        KEY folder_id (folder_id),
        KEY file_id (file_id),
        KEY action_key (action_key),
        KEY actor_person_id (actor_person_id)
    ) {$charset};";
    dbDelta($audit_sql);

    $user_folders_sql = "CREATE TABLE {$user_folders_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        drive_id VARCHAR(191) NOT NULL,
        person_id BIGINT UNSIGNED DEFAULT NULL,
        folder_id VARCHAR(191) NOT NULL,
        folder_name VARCHAR(255) DEFAULT NULL,
        parent_folder_id VARCHAR(191) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY drive_person (drive_id, person_id),
        KEY person_id (person_id),
        KEY folder_id (folder_id)
    ) {$charset};";
    dbDelta($user_folders_sql);

    $items_sql = "CREATE TABLE {$items_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        drive_id VARCHAR(191) NOT NULL,
        item_id VARCHAR(191) NOT NULL,
        parent_id VARCHAR(191) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(191) NOT NULL,
        is_folder TINYINT(1) NOT NULL DEFAULT 0,
        modified_time DATETIME DEFAULT NULL,
        size_bytes BIGINT UNSIGNED DEFAULT NULL,
        web_view_link TEXT DEFAULT NULL,
        raw_json LONGTEXT DEFAULT NULL,
        synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY drive_item (drive_id, item_id),
        KEY drive_parent (drive_id, parent_id),
        KEY drive_parent_folder (drive_id, parent_id, is_folder),
        KEY item_name (item_name)
    ) {$charset};";
    dbDelta($items_sql);

    $sync_state_sql = "CREATE TABLE {$sync_state_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        drive_id VARCHAR(191) NOT NULL,
        folder_id VARCHAR(191) NOT NULL,
        parent_folder_id VARCHAR(191) DEFAULT NULL,
        folder_name VARCHAR(255) DEFAULT NULL,
        last_synced_at DATETIME DEFAULT NULL,
        last_requested_at DATETIME DEFAULT NULL,
        sync_status VARCHAR(32) NOT NULL DEFAULT 'idle',
        sync_depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        item_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY drive_folder (drive_id, folder_id),
        KEY sync_status (sync_status),
        KEY last_synced_at (last_synced_at)
    ) {$charset};";
    dbDelta($sync_state_sql);

    $done = true;
}

function metis_drive_current_person_id(): int {
    if (function_exists('metis_auth_current_person_id')) {
        $person_id = (int) metis_auth_current_person_id();
        if ($person_id > 0) {
            return $person_id;
        }
    }

    if (function_exists('metis_people_get_current_person_id')) {
        $person_id = (int) metis_people_get_current_person_id();
        if ($person_id > 0) {
            return $person_id;
        }
    }

    $current_user = function_exists('metis_current_user') ? metis_current_user() : null;
    $email = is_object($current_user) ? strtolower(trim((string) ($current_user->user_email ?? ''))) : '';
    if ($email !== '' && is_email($email)) {
        global $wpdb;

        if (Metis_Tables::has('auth_users')) {
            $auth_table = Metis_Tables::get('auth_users');
            $person_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT person_id FROM {$auth_table} WHERE user_email = %s LIMIT 1",
                    $email
                )
            );
            if ($person_id > 0) {
                return $person_id;
            }
        }

        if (Metis_Tables::has('people')) {
            $people_table = Metis_Tables::get('people');
            $person_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$people_table} WHERE email = %s LIMIT 1",
                    $email
                )
            );
            if ($person_id > 0) {
                return $person_id;
            }
        }
    }

    return 0;
}

function metis_drive_log_action(array $cfg, string $action_key, array $payload = []): void {
    metis_drive_ensure_schema();
    global $wpdb;

    $table = Metis_Tables::get('drive_audit');
    $person_id = metis_drive_current_person_id();

    $wpdb->insert(
        $table,
        [
            'drive_id' => (string) ($cfg['shared_drive_id'] ?? ''),
            'folder_id' => isset($payload['folder_id']) ? (string) $payload['folder_id'] : null,
            'file_id' => isset($payload['file_id']) ? (string) $payload['file_id'] : null,
            'item_name' => isset($payload['item_name']) ? (string) $payload['item_name'] : null,
            'item_type' => isset($payload['item_type']) ? (string) $payload['item_type'] : null,
            'action_key' => sanitize_key($action_key),
            'actor_person_id' => $person_id > 0 ? $person_id : null,
            'details_json' => !empty($payload['details']) ? metis_json_encode($payload['details']) : null,
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
    );

    metis_audit_log_activity('drive_' . sanitize_key($action_key), [
        'module' => 'drive',
        'resource' => [
            'type' => !empty($payload['item_type']) ? (string) $payload['item_type'] : 'drive_item',
            'id' => !empty($payload['file_id']) ? (string) $payload['file_id'] : (string) ($payload['folder_id'] ?? ''),
            'label' => (string) ($payload['item_name'] ?? $action_key),
        ],
        'context' => [
            'drive_id' => (string) ($cfg['shared_drive_id'] ?? ''),
            'folder_id' => (string) ($payload['folder_id'] ?? ''),
            'file_id' => (string) ($payload['file_id'] ?? ''),
            'details' => (array) ($payload['details'] ?? []),
        ],
    ]);
}

function metis_drive_users_root_folder_name(): string {
    return 'Users';
}

function metis_drive_person_folder_display_name(int $person_id = 0, int $account_id = 0): string {
    global $wpdb;

    $name = '';
    if ($person_id > 0) {
        $people_table = Metis_Tables::get('people');
        if ($people_table) {
            $person = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT first_name, last_name, display_name, email
                     FROM {$people_table}
                     WHERE id = %d
                     LIMIT 1",
                    $person_id
                ),
                ARRAY_A
            );
            if ($person) {
                $name = trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? ''));
                if ($name === '') {
                    $name = trim((string) ($person['display_name'] ?? ''));
                }
                if ($name === '') {
                    $name = trim((string) ($person['email'] ?? ''));
                }
            }
        }
    }

    if ($name === '') {
        $name = 'User ' . (string) max(1, $person_id > 0 ? $person_id : $account_id);
    }

    $name = trim((string) preg_replace('/\s+/', ' ', $name));
    $name = trim((string) preg_replace('/[^A-Za-z0-9\.\'\-\s]/', '', $name));
    if ($name === '') {
        $name = 'User ' . (string) max(1, $person_id > 0 ? $person_id : $account_id);
    }

    return $name;
}

function metis_drive_get_file_meta(array $cfg, string $file_id, string $fields = 'id,name,mimeType,parents,driveId,webViewLink'): array {
    if ($file_id === '') {
        return ['ok' => false, 'error' => 'File ID is required.'];
    }
    $url = add_query_arg([
        'fields' => $fields,
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
    ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id));
    return metis_drive_google_request('GET', $url, null, $cfg);
}

function metis_drive_get_users_root_folder(array $cfg, bool $create = false): array {
    $shared_drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    if ($shared_drive_id === '') {
        return ['ok' => false, 'error' => 'Shared Drive ID is missing.'];
    }
    $root_name = metis_drive_users_root_folder_name();
    $cache_key = 'metis_drive_users_root_' . md5($shared_drive_id);

    $cached = metis_drive_get_users_root_folder_cached($cfg);
    if (!$create && !empty($cached['ok'])) {
        return $cached;
    }

    $find_url = add_query_arg([
        'corpora' => 'drive',
        'driveId' => $shared_drive_id,
        'includeItemsFromAllDrives' => 'true',
        'supportsAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'q' => "trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace("'", "\\'", $root_name) . "' and 'root' in parents",
        'fields' => 'files(id,name,parents,driveId,webViewLink)',
        'pageSize' => 5,
    ], 'https://www.googleapis.com/drive/v3/files');
    $find = metis_drive_google_request('GET', $find_url, null, $cfg);
    // Fallback for tenants where root alias does not resolve in shared-drive corpora.
    if (!empty($find['ok']) && empty($find['body']['files'])) {
        $fallback_find_url = add_query_arg([
            'corpora' => 'drive',
            'driveId' => $shared_drive_id,
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives' => 'true',
            'useDomainAdminAccess' => 'true',
            'q' => "trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace("'", "\\'", $root_name) . "' and '" . str_replace("'", "\\'", $shared_drive_id) . "' in parents",
            'fields' => 'files(id,name,parents,driveId,webViewLink)',
            'pageSize' => 5,
        ], 'https://www.googleapis.com/drive/v3/files');
        $fallback_find = metis_drive_google_request('GET', $fallback_find_url, null, $cfg);
        if (!empty($fallback_find['ok'])) {
            $find = $fallback_find;
        }
    }
    if (empty($find['ok'])) {
        return ['ok' => false, 'error' => (string) ($find['error'] ?? 'Failed to lookup Users folder.')];
    }
    $existing = (array) (($find['body']['files'][0] ?? []));
    if (!empty($existing['id'])) {
        set_transient($cache_key, [
            'folder_id' => (string) $existing['id'],
            'folder_name' => (string) ($existing['name'] ?? $root_name),
        ], 10 * MINUTE_IN_SECONDS);
        return [
            'ok' => true,
            'folder_id' => (string) $existing['id'],
            'folder_name' => (string) ($existing['name'] ?? $root_name),
            'created' => false,
            'folder' => $existing,
        ];
    }

    if (!$create) {
        return ['ok' => false, 'error' => 'Users folder not found.'];
    }

    $create_url = add_query_arg([
        'supportsAllDrives' => 'true',
        'includeItemsFromAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'fields' => 'id,name,parents,driveId,webViewLink',
    ], 'https://www.googleapis.com/drive/v3/files');
    $create_resp = metis_drive_google_request('POST', $create_url, metis_json_encode([
        'name' => $root_name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$shared_drive_id],
    ]), $cfg);
    if (empty($create_resp['ok']) || empty($create_resp['body']['id'])) {
        return ['ok' => false, 'error' => (string) ($create_resp['error'] ?? 'Failed to create Users folder.')];
    }
    set_transient($cache_key, [
        'folder_id' => (string) ($create_resp['body']['id'] ?? ''),
        'folder_name' => (string) ($create_resp['body']['name'] ?? $root_name),
    ], 10 * MINUTE_IN_SECONDS);

    return [
        'ok' => true,
        'folder_id' => (string) $create_resp['body']['id'],
        'folder_name' => (string) ($create_resp['body']['name'] ?? $root_name),
        'created' => true,
        'folder' => (array) ($create_resp['body'] ?? []),
    ];
}

function metis_drive_get_users_root_folder_cached(array $cfg): array {
    $shared_drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    if ($shared_drive_id === '') {
        return ['ok' => false, 'error' => 'Shared Drive ID is missing.'];
    }

    $cache_key = 'metis_drive_users_root_' . md5($shared_drive_id);
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached['folder_id'])) {
        return [
            'ok' => true,
            'folder_id' => (string) ($cached['folder_id'] ?? ''),
            'folder_name' => (string) ($cached['folder_name'] ?? metis_drive_users_root_folder_name()),
            'created' => false,
            'folder' => $cached,
        ];
    }

    $folder_name = metis_drive_users_root_folder_name();

    global $wpdb;
    $items_table = Metis_Tables::get('drive_items');
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT item_id, item_name FROM {$items_table}
             WHERE drive_id = %s
               AND parent_id = %s
               AND is_folder = 1
               AND item_name = %s
             LIMIT 1",
            $shared_drive_id,
            $shared_drive_id,
            $folder_name
        ),
        ARRAY_A
    );

    if (is_array($row) && !empty($row['item_id'])) {
        $payload = [
            'folder_id' => (string) ($row['item_id'] ?? ''),
            'folder_name' => (string) ($row['item_name'] ?? $folder_name),
        ];
        set_transient($cache_key, $payload, 10 * MINUTE_IN_SECONDS);
        return [
            'ok' => true,
            'folder_id' => (string) $payload['folder_id'],
            'folder_name' => (string) $payload['folder_name'],
            'created' => false,
            'folder' => $payload,
        ];
    }

    return ['ok' => false, 'error' => 'Users folder not found in cache.'];
}

function metis_drive_get_user_folder_mapping(array $cfg, int $person_id = 0): ?array {
    global $wpdb;
    $table = Metis_Tables::get('drive_user_folders');
    if (!$table) {
        return null;
    }

    if ($person_id < 1) {
        $person_id = metis_drive_current_person_id();
    }

    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    if ($drive_id === '') {
        return null;
    }

    $row = null;
    if ($person_id > 0) {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE drive_id = %s AND person_id = %d LIMIT 1",
                $drive_id,
                $person_id
            ),
            ARRAY_A
        );
    }

    return is_array($row) ? $row : null;
}

function metis_drive_get_user_folder_mapping_any(int $person_id = 0, string $preferred_drive_id = ''): ?array {
    global $wpdb;
    $table = Metis_Tables::get('drive_user_folders');
    if (!$table) {
        return null;
    }

    if ($person_id < 1) {
        $person_id = metis_drive_current_person_id();
    }
    if ($person_id < 1) {
        return null;
    }

    if ($preferred_drive_id !== '') {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE person_id = %d AND drive_id = %s LIMIT 1",
                $person_id,
                $preferred_drive_id
            ),
            ARRAY_A
        );
        if (is_array($row)) {
            return $row;
        }
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE person_id = %d
             ORDER BY updated_at DESC, id DESC
             LIMIT 1",
            $person_id
        ),
        ARRAY_A
    );

    return is_array($row) ? $row : null;
}

function metis_drive_resolve_user_folder_context(int $person_id = 0): array {
    if ($person_id < 1) {
        $person_id = metis_drive_current_person_id();
    }
    if ($person_id < 1) {
        return ['ok' => false, 'error' => 'Person could not be resolved.'];
    }

    $users_home = metis_drive_users_home_setting();
    $preferred_drive_id = trim((string) ($users_home['drive_id'] ?? ''));
    $mapping = metis_drive_get_user_folder_mapping_any($person_id, $preferred_drive_id);
    if (!is_array($mapping) || empty($mapping['drive_id'])) {
        return ['ok' => false, 'error' => 'No mapped folder was found for this person.'];
    }

    $cfg = metis_drive_workspace_settings((string) $mapping['drive_id']);
    if (empty($cfg['ok'])) {
        return ['ok' => false, 'error' => (string) ($cfg['error'] ?? 'Drive config missing.')];
    }

    return [
        'ok' => true,
        'cfg' => $cfg,
        'mapping' => $mapping,
    ];
}

function metis_drive_upsert_user_folder_mapping(array $cfg, int $person_id, string $folder_id, string $folder_name, string $parent_folder_id): void {
    global $wpdb;
    $table = Metis_Tables::get('drive_user_folders');
    if (!$table) {
        return;
    }

    $existing_id = 0;
    if ($person_id > 0) {
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE drive_id = %s AND person_id = %d LIMIT 1",
                (string) ($cfg['shared_drive_id'] ?? ''),
                $person_id
            )
        );
    }

    $payload = [
        'drive_id' => (string) ($cfg['shared_drive_id'] ?? ''),
        'person_id' => $person_id > 0 ? $person_id : null,
        'folder_id' => $folder_id,
        'folder_name' => $folder_name,
        'parent_folder_id' => $parent_folder_id,
        'updated_at' => current_time('mysql'),
    ];

    if ($existing_id > 0) {
        $wpdb->update(
            $table,
            $payload,
            ['id' => $existing_id],
            ['%s', '%d', '%s', '%s', '%s', '%s'],
            ['%d']
        );
        return;
    }

    $payload['created_at'] = current_time('mysql');
    $wpdb->insert(
        $table,
        $payload,
        ['%s', '%d', '%s', '%s', '%s', '%s', '%s']
    );
}

function metis_drive_find_or_create_user_folder(array $cfg, int $person_id = 0, bool $create = true): array {
    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    if ($drive_id === '') {
        return ['ok' => false, 'error' => 'Shared Drive ID is missing.'];
    }
    if ($person_id < 1) {
        $person_id = metis_drive_current_person_id();
    }

    $users_root = metis_drive_get_users_root_folder_cached($cfg);
    $users_root_id = !empty($users_root['ok']) ? (string) ($users_root['folder_id'] ?? '') : '';
    $expected_parent_id = $users_root_id !== '' ? $users_root_id : $drive_id;

    $existing = metis_drive_get_user_folder_mapping($cfg, $person_id);
    if ($existing && !empty($existing['folder_id'])) {
        $folder_id = (string) $existing['folder_id'];
        $meta = metis_drive_get_file_meta($cfg, $folder_id, 'id,name,mimeType,parents,driveId,webViewLink');
        if (!empty($meta['ok'])) {
            $body = (array) ($meta['body'] ?? []);
            $parent_id = (string) (($body['parents'][0] ?? '') ?: ($existing['parent_folder_id'] ?? ''));
            $is_valid_parent = ($parent_id === $expected_parent_id);
            if ((string) ($body['driveId'] ?? '') === $drive_id
                && (string) ($body['mimeType'] ?? '') === 'application/vnd.google-apps.folder'
                && $is_valid_parent) {
                return [
                    'ok' => true,
                    'folder_id' => $folder_id,
                    'folder_name' => (string) (($body['name'] ?? '') ?: ($existing['folder_name'] ?? '')),
                    'parent_folder_id' => $parent_id,
                    'created' => false,
                    'source' => 'mapping',
                    'web_view_link' => (string) ($body['webViewLink'] ?? ''),
                ];
            }
        }
    }

    $folder_name = metis_drive_person_folder_display_name($person_id, 0);
    $cached_folder = metis_drive_find_cached_user_folder($cfg, $person_id, $users_root_id);
    if (!empty($cached_folder['ok'])) {
        metis_drive_upsert_user_folder_mapping(
            $cfg,
            $person_id,
            (string) ($cached_folder['folder_id'] ?? ''),
            (string) ($cached_folder['folder_name'] ?? $folder_name),
            (string) ($cached_folder['parent_folder_id'] ?? ($users_root_id !== '' ? $users_root_id : $drive_id))
        );
        return $cached_folder;
    }

    $find_url = add_query_arg([
        'corpora' => 'drive',
        'driveId' => $drive_id,
        'includeItemsFromAllDrives' => 'true',
        'supportsAllDrives' => 'true',
        'useDomainAdminAccess' => 'true',
        'q' => "trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace("'", "\\'", $folder_name) . "' and '" . str_replace("'", "\\'", $expected_parent_id) . "' in parents",
        'fields' => 'files(id,name,parents,driveId,webViewLink)',
        'pageSize' => 5,
    ], 'https://www.googleapis.com/drive/v3/files');
    $find = metis_drive_google_request('GET', $find_url, null, $cfg);
    if (!empty($find['ok']) && empty($find['body']['files']) && $expected_parent_id !== $drive_id) {
        $fallback_find_url = add_query_arg([
            'corpora' => 'drive',
            'driveId' => $drive_id,
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives' => 'true',
            'useDomainAdminAccess' => 'true',
            'q' => "trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace("'", "\\'", $folder_name) . "' and '" . str_replace("'", "\\'", $drive_id) . "' in parents",
            'fields' => 'files(id,name,parents,driveId,webViewLink)',
            'pageSize' => 5,
        ], 'https://www.googleapis.com/drive/v3/files');
        $fallback_find = metis_drive_google_request('GET', $fallback_find_url, null, $cfg);
        if (!empty($fallback_find['ok'])) {
            $find = $fallback_find;
        }
    }
    if (empty($find['ok'])) {
        return ['ok' => false, 'error' => (string) ($find['error'] ?? 'Failed to lookup user folder.')];
    }
    $folder = (array) (($find['body']['files'][0] ?? []));
    $created = false;
    if (empty($folder['id'])) {
        if (!$create) {
            return ['ok' => false, 'error' => 'User folder not found.'];
        }
        $create_url = add_query_arg([
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
            'useDomainAdminAccess' => 'true',
            'fields' => 'id,name,parents,driveId,webViewLink',
        ], 'https://www.googleapis.com/drive/v3/files');
        $create_resp = metis_drive_google_request('POST', $create_url, metis_json_encode([
            'name' => $folder_name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$expected_parent_id],
        ]), $cfg);
        if (empty($create_resp['ok']) || empty($create_resp['body']['id'])) {
            return ['ok' => false, 'error' => (string) ($create_resp['error'] ?? 'Failed to create user folder.')];
        }
        $folder = (array) ($create_resp['body'] ?? []);
        $created = true;
    }

    $folder_id = (string) ($folder['id'] ?? '');
    $resolved_name = (string) (($folder['name'] ?? '') ?: $folder_name);
    $resolved_parent_id = (string) (($folder['parents'][0] ?? '') ?: $expected_parent_id);
    metis_drive_upsert_user_folder_mapping($cfg, $person_id, $folder_id, $resolved_name, $resolved_parent_id);

    return [
        'ok' => true,
        'folder_id' => $folder_id,
        'folder_name' => $resolved_name,
        'parent_folder_id' => $resolved_parent_id,
        'created' => $created,
        'source' => $created ? 'created' : 'lookup',
        'web_view_link' => (string) ($folder['webViewLink'] ?? ''),
    ];
}

function metis_drive_find_cached_user_folder(array $cfg, int $person_id = 0, string $users_root_id = ''): array {
    metis_drive_ensure_schema();
    global $wpdb;

    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    if ($drive_id === '') {
        return ['ok' => false, 'error' => 'Shared Drive ID is missing.'];
    }
    if ($person_id < 1) {
        $person_id = metis_drive_current_person_id();
    }

    $folder_name = metis_drive_person_folder_display_name($person_id, 0);
    $items_table = Metis_Tables::get('drive_items');
    $args = [$drive_id, $folder_name];
    $where = [
        'drive_id = %s',
        'item_name = %s',
        "mime_type = 'application/vnd.google-apps.folder'",
    ];

    if ($users_root_id !== '') {
        $where[] = 'parent_id = %s';
        $args[] = $users_root_id;
    } else {
        $where[] = '(parent_id = %s OR parent_id = %s)';
        $args[] = $drive_id;
        $args[] = '';
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT item_id, item_name, parent_id, web_view_link
             FROM {$items_table}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY item_id ASC
             LIMIT 1",
            ...$args
        ),
        ARRAY_A
    );

    if (!is_array($row) || empty($row['item_id'])) {
        return ['ok' => false, 'error' => 'User folder is not cached yet.'];
    }

    return [
        'ok' => true,
        'folder_id' => (string) ($row['item_id'] ?? ''),
        'folder_name' => (string) ($row['item_name'] ?? $folder_name),
        'parent_folder_id' => (string) ($row['parent_id'] ?? ($users_root_id !== '' ? $users_root_id : $drive_id)),
        'created' => false,
        'source' => 'cache',
        'web_view_link' => (string) ($row['web_view_link'] ?? ''),
    ];
}

function metis_drive_folder_is_descendant_of(array $cfg, string $folder_id, string $ancestor_id): bool {
    if ($folder_id === '' || $ancestor_id === '') {
        return false;
    }
    if ($folder_id === $ancestor_id) {
        return true;
    }

    $shared_drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    $cache_key = 'metis_drive_desc_' . md5($shared_drive_id . '|' . $folder_id . '|' . $ancestor_id);
    $cached = get_transient($cache_key);
    if (is_string($cached)) {
        return $cached === '1';
    }

    $current = $folder_id;
    $seen = [];
    for ($depth = 0; $depth < 25; $depth++) {
        if ($current === '' || isset($seen[$current])) {
            set_transient($cache_key, '0', 5 * MINUTE_IN_SECONDS);
            return false;
        }
        $seen[$current] = true;
        $meta = metis_drive_get_file_meta($cfg, $current, 'id,parents,driveId,mimeType');
        if (empty($meta['ok'])) {
            set_transient($cache_key, '0', MINUTE_IN_SECONDS);
            return false;
        }
        $body = (array) ($meta['body'] ?? []);
        if ((string) ($body['driveId'] ?? '') !== $shared_drive_id) {
            set_transient($cache_key, '0', 5 * MINUTE_IN_SECONDS);
            return false;
        }
        $parents = (array) ($body['parents'] ?? []);
        $parent = !empty($parents) ? (string) $parents[0] : '';
        if ($parent === '') {
            set_transient($cache_key, '0', 5 * MINUTE_IN_SECONDS);
            return false;
        }
        if ($parent === $ancestor_id) {
            set_transient($cache_key, '1', 5 * MINUTE_IN_SECONDS);
            return true;
        }
        if ($parent === $shared_drive_id) {
            set_transient($cache_key, '0', 5 * MINUTE_IN_SECONDS);
            return false;
        }
        $current = $parent;
    }

    set_transient($cache_key, '0', 5 * MINUTE_IN_SECONDS);
    return false;
}

function metis_drive_can_view(): bool {
    if (function_exists('metis_people_can')) {
        return metis_people_can('drive', 'view');
    }
    return metis_user_logged_in();
}

function metis_drive_can_manage(): bool {
    if (function_exists('metis_people_can')) {
        return metis_people_can('drive', 'edit') || metis_people_can('drive', 'create') || metis_people_can('drive', 'delete');
    }
    return metis_current_user_can('manage_options');
}

function metis_drive_workspace_base_settings(): array {
    $service = function_exists( 'metis_workspace_service_account_payload' ) ? metis_workspace_service_account_payload() : [];
    $impersonation_admin = strtolower(trim((string) Core_Settings_Service::get('workspace_impersonation_admin', '')));
    if ( empty( $service ) || !is_email($impersonation_admin)) {
        return ['ok' => false, 'error' => 'Workspace service account JSON or impersonation admin is not configured.'];
    }
    $service_error = function_exists( 'metis_workspace_service_account_error' ) ? metis_workspace_service_account_error( $service ) : '';
    if ( $service_error !== '' ) {
        return [ 'ok' => false, 'error' => $service_error ];
    }
    return [
        'ok' => true,
        'service' => $service,
        'subject' => $impersonation_admin,
        'scopes' => ['https://www.googleapis.com/auth/drive'],
    ];
}

function metis_drive_setting_rows(): array {
    $rows = Core_Settings_Service::get('workspace_drive_configs', []);
    $normalized = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $drive_id = trim((string) ($row['drive_id'] ?? ''));
            if ($drive_id === '') {
                continue;
            }
            $normalized[] = [
                'label' => trim((string) ($row['label'] ?? '')),
                'drive_id' => $drive_id,
                'drive_name' => trim((string) ($row['drive_name'] ?? '')),
                'is_default' => !empty($row['is_default']) ? 1 : 0,
                'is_users_home' => !empty($row['is_users_home']) ? 1 : 0,
            ];
        }
    }

    if (empty($normalized)) {
        $legacy_id = trim((string) Core_Settings_Service::get('workspace_shared_drive_id', ''));
        if ($legacy_id !== '') {
            $normalized[] = [
                'label' => 'Primary Drive',
                'drive_id' => $legacy_id,
                'drive_name' => '',
                'is_default' => 1,
                'is_users_home' => 0,
            ];
        }
    }

    return $normalized;
}

function metis_drive_default_setting(): array {
    $rows = metis_drive_setting_rows();
    if (empty($rows)) {
        return [];
    }
    foreach ($rows as $row) {
        if (!empty($row['is_default'])) {
            return $row;
        }
    }
    return $rows[0];
}

function metis_drive_setting_by_id(string $drive_id): array {
    $drive_id = trim($drive_id);
    if ($drive_id === '') {
        return [];
    }
    foreach (metis_drive_setting_rows() as $row) {
        if ((string) ($row['drive_id'] ?? '') === $drive_id) {
            return $row;
        }
    }
    return [];
}

function metis_drive_configured_drives(): array {
    $drives = [];
    foreach (metis_drive_setting_rows() as $row) {
        $drive_id = trim((string) ($row['drive_id'] ?? ''));
        if ($drive_id === '') {
            continue;
        }
        $label = trim((string) ($row['label'] ?? ''));
        $drive_name = trim((string) ($row['drive_name'] ?? ''));
        $drives[] = [
            'drive_id' => $drive_id,
            'label' => $label !== '' ? $label : ($drive_name !== '' ? $drive_name : $drive_id),
            'drive_name' => $drive_name,
            'is_default' => !empty($row['is_default']) ? 1 : 0,
            'is_users_home' => !empty($row['is_users_home']) ? 1 : 0,
        ];
    }
    return $drives;
}

function metis_drive_users_home_setting(): array {
    $rows = metis_drive_setting_rows();
    foreach ($rows as $row) {
        if (!empty($row['is_users_home'])) {
            return $row;
        }
    }
    return [];
}

function metis_drive_workspace_settings(?string $drive_id = null): array {
    $base = metis_drive_workspace_base_settings();
    if (empty($base['ok'])) {
        return $base;
    }

    $selected = $drive_id ? metis_drive_setting_by_id($drive_id) : [];
    if (empty($selected)) {
        $selected = metis_drive_default_setting();
    }
    $shared_drive_id = trim((string) ($selected['drive_id'] ?? ''));
    if ($shared_drive_id === '') {
        return ['ok' => false, 'error' => 'No default shared drive is configured in Settings.'];
    }

    $base['shared_drive_id'] = $shared_drive_id;
    $base['shared_drive_name'] = trim((string) ($selected['drive_name'] ?? ''));
    $base['shared_drive_label'] = trim((string) ($selected['label'] ?? ''));
    return $base;
}

function metis_drive_b64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function metis_drive_google_access_token(array $cfg): array {
    $service = (array) ($cfg['service'] ?? []);
    $client_email = (string) ($service['client_email'] ?? '');
    $private_key = (string) ($service['private_key'] ?? '');
    $token_uri = (string) ($service['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    $subject = (string) ($cfg['subject'] ?? '');
    $scopes = (array) ($cfg['scopes'] ?? []);
    if ($client_email === '' || $private_key === '' || $subject === '' || empty($scopes)) {
        return ['ok' => false, 'error' => 'Workspace OAuth configuration is incomplete.'];
    }
    $cache_key = 'metis_drive_token_' . md5($client_email . '|' . $subject . '|' . implode(' ', $scopes));
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached['access_token'])) {
        return ['ok' => true, 'access_token' => (string) $cached['access_token']];
    }

    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $now = time();
    $claims = [
        'iss' => $client_email,
        'scope' => implode(' ', $scopes),
        'aud' => $token_uri,
        'iat' => $now,
        'exp' => $now + 3600,
        'sub' => $subject,
    ];
    $jwt_input = metis_drive_b64url_encode(metis_json_encode($header)) . '.' . metis_drive_b64url_encode(metis_json_encode($claims));
    $signature = '';
    $signed = openssl_sign($jwt_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
    if (!$signed) return ['ok' => false, 'error' => 'Could not sign Workspace JWT assertion.'];

    $assertion = $jwt_input . '.' . metis_drive_b64url_encode($signature);
    $response = metis_remote_post($token_uri, [
        'timeout' => 20,
        'body' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ],
    ]);

    if (metis_is_error($response)) return ['ok' => false, 'error' => $response->get_error_message()];
    $code = (int) metis_remote_retrieve_response_code($response);
    $body = json_decode((string) metis_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['access_token'])) {
        return ['ok' => false, 'error' => 'Workspace token request failed (' . $code . ').'];
    }

    $access_token = (string) $body['access_token'];
    $ttl = max(120, ((int) ($body['expires_in'] ?? 3600)) - 60);
    set_transient($cache_key, ['access_token' => $access_token], $ttl);
    return ['ok' => true, 'access_token' => $access_token];
}

function metis_drive_google_request(string $method, string $url, ?string $raw_body, array $cfg, array $headers = []): array {
    $token = metis_drive_google_access_token($cfg);
    if (empty($token['ok'])) return ['ok' => false, 'error' => (string) ($token['error'] ?? 'Workspace token error.')];

    $request_headers = array_merge([
        'Authorization' => 'Bearer ' . (string) $token['access_token'],
        'Content-Type' => 'application/json',
    ], $headers);

    $args = [
        'method' => strtoupper($method),
        'timeout' => 45,
        'headers' => $request_headers,
    ];
    if ($raw_body !== null) {
        $args['body'] = $raw_body;
    }

    $response = metis_remote_request($url, $args);
    if (metis_is_error($response)) {
        return ['ok' => false, 'error' => $response->get_error_message()];
    }

    $code = (int) metis_remote_retrieve_response_code($response);
    $raw = (string) metis_remote_retrieve_body($response);
    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
        if ($msg === '') $msg = 'Google Drive API request failed (' . $code . ').';
        return ['ok' => false, 'error' => $msg, 'status' => $code, 'raw' => $raw];
    }

    return ['ok' => true, 'status' => $code, 'body' => is_array($decoded) ? $decoded : []];
}

function metis_drive_list_shared_drives(array $cfg): array {
    $drives = [];
    $page_token = '';

    do {
        $params = [
            'pageSize' => 100,
            'fields' => 'drives(id,name),nextPageToken',
            'useDomainAdminAccess' => 'true',
        ];
        if ($page_token !== '') {
            $params['pageToken'] = $page_token;
        }

        $url = add_query_arg($params, 'https://www.googleapis.com/drive/v3/drives');
        $resp = metis_drive_google_request('GET', $url, null, $cfg);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Failed to load shared drives.')];
        }

        $body_drives = (array) (($resp['body']['drives'] ?? []));
        foreach ($body_drives as $drive) {
            $id = (string) ($drive['id'] ?? '');
            $name = (string) ($drive['name'] ?? '');
            if ($id === '' || $name === '') continue;
            $drives[] = ['id' => $id, 'name' => $name];
        }

        $page_token = (string) (($resp['body']['nextPageToken'] ?? ''));
    } while ($page_token !== '');

    return ['ok' => true, 'drives' => $drives];
}

function metis_drive_sync_interval(): int {
    return HOUR_IN_SECONDS;
}

function metis_drive_cron_interval(): int {
    return 30 * MINUTE_IN_SECONDS;
}

function metis_drive_cron_stagger_interval(): int {
    return 4 * MINUTE_IN_SECONDS;
}

function metis_drive_background_sync_interval(): int {
    return 10 * MINUTE_IN_SECONDS;
}

function metis_drive_sync_max_depth(): int {
    return 2;
}

function metis_drive_bump_response_cache_version(): void {
    update_option('metis_drive_cache_version', max(1, (int) get_option('metis_drive_cache_version', 1)) + 1, false);
}

function metis_drive_sync_service_key(string $drive_id): string {
    return 'drive:' . trim($drive_id);
}

function metis_drive_cron_task_slug(string $drive_id): string {
    return 'drive_listing_sync_' . substr(md5(trim($drive_id)), 0, 12);
}

function metis_drive_prime_cron_task_state(string $slug, int $interval, int $offset): void {
    $slug = sanitize_key($slug);
    if ($slug === '') {
        return;
    }

    $option_key = 'metis_cron_task_state_' . $slug;
    $state = get_option($option_key, []);
    if (!is_array($state)) {
        $state = [];
    }
    if (!empty($state['last_finished_at'])) {
        return;
    }

    $interval = max(60, $interval);
    $offset = max(0, min($interval - 60, $offset));
    $base_timestamp = current_time('timestamp') - $interval + $offset;
    if ($base_timestamp < 1) {
        $base_timestamp = time() - $interval + $offset;
    }

    $state['last_finished_at'] = date('Y-m-d H:i:s', $base_timestamp);
    $state['last_status'] = 'ok';
    $state['running'] = false;
    update_option($option_key, $state, false);
}

function metis_drive_normalize_parent_id(string $drive_id, string $parent_id): string {
    $drive_id = trim($drive_id);
    $parent_id = trim($parent_id);
    return $parent_id !== '' ? $parent_id : $drive_id;
}

function metis_drive_datetime_from_google(?string $value): ?string {
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    return gmdate('Y-m-d H:i:s', $timestamp);
}

function metis_drive_sync_state(string $drive_id, string $folder_id): array {
    metis_drive_ensure_schema();
    global $wpdb;

    $table = Metis_Tables::get('drive_sync_state');
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE drive_id = %s AND folder_id = %s LIMIT 1",
            $drive_id,
            $folder_id
        ),
        ARRAY_A
    );

    return is_array($row) ? $row : [];
}

function metis_drive_update_sync_state(string $drive_id, string $folder_id, array $data): void {
    metis_drive_ensure_schema();
    global $wpdb;

    $table = Metis_Tables::get('drive_sync_state');
    $payload = [
        'drive_id' => $drive_id,
        'folder_id' => $folder_id,
        'parent_folder_id' => array_key_exists('parent_folder_id', $data) ? (string) ($data['parent_folder_id'] ?? '') : null,
        'folder_name' => array_key_exists('folder_name', $data) ? (string) ($data['folder_name'] ?? '') : null,
        'last_synced_at' => array_key_exists('last_synced_at', $data) ? ($data['last_synced_at'] ?: null) : null,
        'last_requested_at' => array_key_exists('last_requested_at', $data) ? ($data['last_requested_at'] ?: null) : null,
        'sync_status' => sanitize_key((string) ($data['sync_status'] ?? 'idle')) ?: 'idle',
        'sync_depth' => max(0, (int) ($data['sync_depth'] ?? 0)),
        'item_count' => max(0, (int) ($data['item_count'] ?? 0)),
        'last_error' => array_key_exists('last_error', $data) ? (string) ($data['last_error'] ?? '') : null,
        'updated_at' => current_time('mysql'),
    ];

    $existing = metis_drive_sync_state($drive_id, $folder_id);
    if (!empty($existing['id'])) {
        $wpdb->update(
            $table,
            $payload,
            ['id' => (int) $existing['id']],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s'],
            ['%d']
        );
        return;
    }

    $wpdb->insert(
        $table,
        $payload,
        ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
    );
}

function metis_drive_mark_folder_requested(string $drive_id, string $folder_id, string $folder_name = '', string $parent_folder_id = ''): void {
    $state = metis_drive_sync_state($drive_id, $folder_id);
    metis_drive_update_sync_state($drive_id, $folder_id, [
        'parent_folder_id' => $parent_folder_id !== '' ? $parent_folder_id : (string) ($state['parent_folder_id'] ?? ''),
        'folder_name' => $folder_name !== '' ? $folder_name : (string) ($state['folder_name'] ?? ''),
        'last_synced_at' => (string) ($state['last_synced_at'] ?? ''),
        'last_requested_at' => current_time('mysql'),
        'sync_status' => (string) ($state['sync_status'] ?? 'idle'),
        'sync_depth' => (int) ($state['sync_depth'] ?? 0),
        'item_count' => (int) ($state['item_count'] ?? 0),
        'last_error' => (string) ($state['last_error'] ?? ''),
    ]);
}

function metis_drive_sync_needs_refresh(string $drive_id, string $folder_id, int $max_age): bool {
    $state = metis_drive_sync_state($drive_id, $folder_id);
    $last_synced_at = (string) ($state['last_synced_at'] ?? '');
    if ($last_synced_at === '') {
        return true;
    }

    $timestamp = strtotime($last_synced_at);
    if ($timestamp === false) {
        return true;
    }

    return (time() - $timestamp) >= max(60, $max_age);
}

function metis_drive_sync_lock_key(string $drive_id, string $folder_id): string {
    return 'metis_drive_sync_lock_' . md5($drive_id . '|' . $folder_id);
}

function metis_drive_acquire_sync_lock(string $drive_id, string $folder_id, int $ttl = 300): bool {
    $key = metis_drive_sync_lock_key($drive_id, $folder_id);
    if (get_transient($key)) {
        return false;
    }

    set_transient($key, 1, max(60, $ttl));
    return true;
}

function metis_drive_release_sync_lock(string $drive_id, string $folder_id): void {
    delete_transient(metis_drive_sync_lock_key($drive_id, $folder_id));
}

function metis_drive_get_cached_item(string $drive_id, string $item_id): array {
    metis_drive_ensure_schema();
    global $wpdb;

    $table = Metis_Tables::get('drive_items');
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE drive_id = %s AND item_id = %s LIMIT 1",
            $drive_id,
            $item_id
        ),
        ARRAY_A
    );

    return is_array($row) ? $row : [];
}

function metis_drive_cached_item_meta(string $drive_id, string $item_id): array {
    $item = metis_drive_get_cached_item($drive_id, $item_id);
    if (!empty($item)) {
        return [
            'ok' => true,
            'file' => [
                'id' => (string) ($item['item_id'] ?? ''),
                'name' => (string) ($item['item_name'] ?? ''),
                'mimeType' => (string) ($item['mime_type'] ?? ''),
                'parents' => [(string) ($item['parent_id'] ?? '')],
                'driveId' => (string) ($item['drive_id'] ?? ''),
                'webViewLink' => (string) ($item['web_view_link'] ?? ''),
                'modifiedTime' => !empty($item['modified_time']) ? gmdate('c', strtotime((string) $item['modified_time'])) : '',
                'size' => isset($item['size_bytes']) ? (string) $item['size_bytes'] : '',
            ],
        ];
    }

    global $wpdb;
    $mapping_table = Metis_Tables::get('drive_user_folders');
    $mapping = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT folder_id, folder_name, parent_folder_id
             FROM {$mapping_table}
             WHERE drive_id = %s AND folder_id = %s
             LIMIT 1",
            $drive_id,
            $item_id
        ),
        ARRAY_A
    );
    if (is_array($mapping) && !empty($mapping['folder_id'])) {
        metis_drive_update_sync_state($drive_id, (string) $mapping['folder_id'], [
            'parent_folder_id' => (string) ($mapping['parent_folder_id'] ?? ''),
            'folder_name' => (string) ($mapping['folder_name'] ?? ''),
            'last_synced_at' => (string) (metis_drive_sync_state($drive_id, (string) $mapping['folder_id'])['last_synced_at'] ?? ''),
            'last_requested_at' => (string) (metis_drive_sync_state($drive_id, (string) $mapping['folder_id'])['last_requested_at'] ?? ''),
            'sync_status' => (string) (metis_drive_sync_state($drive_id, (string) $mapping['folder_id'])['sync_status'] ?? 'idle'),
            'sync_depth' => (int) (metis_drive_sync_state($drive_id, (string) $mapping['folder_id'])['sync_depth'] ?? 0),
            'item_count' => (int) (metis_drive_sync_state($drive_id, (string) $mapping['folder_id'])['item_count'] ?? 0),
            'last_error' => (string) (metis_drive_sync_state($drive_id, (string) $mapping['folder_id'])['last_error'] ?? ''),
        ]);

        return [
            'ok' => true,
            'file' => [
                'id' => (string) ($mapping['folder_id'] ?? ''),
                'name' => (string) ($mapping['folder_name'] ?? ''),
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [(string) ($mapping['parent_folder_id'] ?? '')],
                'driveId' => $drive_id,
                'webViewLink' => '',
                'modifiedTime' => '',
                'size' => '',
            ],
        ];
    }

    $state = metis_drive_sync_state($drive_id, $item_id);
    if (!empty($state['folder_id'])) {
        return [
            'ok' => true,
            'file' => [
                'id' => (string) ($state['folder_id'] ?? ''),
                'name' => (string) ($state['folder_name'] ?? ''),
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [(string) ($state['parent_folder_id'] ?? '')],
                'driveId' => $drive_id,
                'webViewLink' => '',
            ],
        ];
    }

    return ['ok' => false, 'error' => 'Item is not cached.'];
}

function metis_drive_store_cached_item(string $drive_id, array $item, string $fallback_parent_id = ''): void {
    metis_drive_ensure_schema();
    global $wpdb;

    $table = Metis_Tables::get('drive_items');
    $item_id = trim((string) ($item['id'] ?? ''));
    if ($drive_id === '' || $item_id === '') {
        return;
    }

    $parent_id = metis_drive_normalize_parent_id($drive_id, (string) (($item['parents'][0] ?? '') ?: $fallback_parent_id ?: $drive_id));
    $wpdb->replace(
        $table,
        [
            'drive_id' => $drive_id,
            'item_id' => $item_id,
            'parent_id' => $parent_id,
            'item_name' => (string) ($item['name'] ?? ''),
            'mime_type' => (string) ($item['mimeType'] ?? ''),
            'is_folder' => (string) ($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder' ? 1 : 0,
            'modified_time' => metis_drive_datetime_from_google((string) ($item['modifiedTime'] ?? '')),
            'size_bytes' => isset($item['size']) && $item['size'] !== '' ? (string) $item['size'] : null,
            'web_view_link' => (string) ($item['webViewLink'] ?? ''),
            'raw_json' => metis_json_encode($item),
            'synced_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
    );

    if ((string) ($item['name'] ?? '') === metis_drive_users_root_folder_name()
        && (string) ($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder'
        && $parent_id === $drive_id) {
        set_transient('metis_drive_users_root_' . md5($drive_id), [
            'folder_id' => $item_id,
            'folder_name' => (string) ($item['name'] ?? ''),
        ], 10 * MINUTE_IN_SECONDS);
    }
}

function metis_drive_delete_cached_item(string $drive_id, string $item_id): void {
    metis_drive_ensure_schema();
    global $wpdb;

    if ($drive_id === '' || $item_id === '') {
        return;
    }

    $table = Metis_Tables::get('drive_items');
    $wpdb->delete($table, ['drive_id' => $drive_id, 'item_id' => $item_id], ['%s', '%s']);
}

function metis_drive_google_start_page_token(array $cfg): array {
    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    $url = add_query_arg([
        'supportsAllDrives' => 'true',
        'driveId' => $drive_id,
    ], 'https://www.googleapis.com/drive/v3/changes/startPageToken');
    return metis_drive_google_request('GET', $url, null, $cfg);
}

function metis_drive_google_list_changes(array $cfg, string $page_token): array {
    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    $all_changes = [];
    $next_page = $page_token;
    $new_token = $page_token;

    do {
        $url = add_query_arg([
            'pageToken' => $next_page,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
            'includeRemoved' => 'true',
            'restrictToMyDrive' => 'false',
            'driveId' => $drive_id,
            'spaces' => 'drive',
            'fields' => 'changes(fileId,removed,file(id,name,mimeType,modifiedTime,size,webViewLink,parents,driveId,trashed)),nextPageToken,newStartPageToken',
        ], 'https://www.googleapis.com/drive/v3/changes');
        $resp = metis_drive_google_request('GET', $url, null, $cfg);
        if (empty($resp['ok'])) {
            return [
                'ok' => false,
                'error' => (string) ($resp['error'] ?? 'Failed to load drive changes.'),
                'status' => (int) ($resp['status'] ?? 0),
            ];
        }

        foreach ((array) ($resp['body']['changes'] ?? []) as $change) {
            if (is_array($change)) {
                $all_changes[] = $change;
            }
        }

        $next_page = trim((string) ($resp['body']['nextPageToken'] ?? ''));
        $new_token = trim((string) ($resp['body']['newStartPageToken'] ?? $new_token));
    } while ($next_page !== '');

    return ['ok' => true, 'changes' => $all_changes, 'next_sync_token' => $new_token];
}

function metis_drive_user_folder_mapping_fast(array $cfg, int $person_id = 0): array {
    $existing = metis_drive_get_user_folder_mapping($cfg, $person_id);
    if (!$existing || empty($existing['folder_id'])) {
        $users_root = metis_drive_get_users_root_folder_cached($cfg);
        $cached = metis_drive_find_cached_user_folder($cfg, $person_id, (string) ($users_root['folder_id'] ?? ''));
        if (empty($cached['ok'])) {
            return ['ok' => false, 'error' => 'User folder is not cached yet.'];
        }

        $resolved_person_id = $person_id > 0 ? $person_id : metis_drive_current_person_id();
        if ($resolved_person_id > 0) {
            metis_drive_upsert_user_folder_mapping(
                $cfg,
                $resolved_person_id,
                (string) ($cached['folder_id'] ?? ''),
                (string) ($cached['folder_name'] ?? ''),
                (string) ($cached['parent_folder_id'] ?? '')
            );
        }

        return $cached;
    }

    return [
        'ok' => true,
        'folder_id' => (string) ($existing['folder_id'] ?? ''),
        'folder_name' => (string) ($existing['folder_name'] ?? ''),
        'parent_folder_id' => (string) ($existing['parent_folder_id'] ?? ((string) ($cfg['shared_drive_id'] ?? ''))),
        'created' => false,
        'source' => 'mapping',
        'web_view_link' => '',
    ];
}

function metis_drive_folder_is_descendant_of_cached(array $cfg, string $folder_id, string $ancestor_id): bool {
    if ($folder_id === '' || $ancestor_id === '') {
        return false;
    }
    if ($folder_id === $ancestor_id) {
        return true;
    }

    $shared_drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    $current = $folder_id;
    $seen = [];

    for ($depth = 0; $depth < 25; $depth++) {
        if ($current === '' || isset($seen[$current])) {
            return false;
        }
        $seen[$current] = true;

        $meta = metis_drive_cached_item_meta($shared_drive_id, $current);
        if (empty($meta['ok'])) {
            return false;
        }

        $body = (array) ($meta['file'] ?? []);
        if ((string) ($body['driveId'] ?? '') !== $shared_drive_id) {
            return false;
        }

        $parent = (string) (($body['parents'][0] ?? ''));
        if ($parent === '') {
            return false;
        }
        if ($parent === $ancestor_id) {
            return true;
        }
        if ($parent === $shared_drive_id) {
            return false;
        }
        $current = $parent;
    }

    return false;
}

function metis_drive_cached_folder_children(string $drive_id, string $folder_id, string $search = '', bool $folders_only = false): array {
    metis_drive_ensure_schema();
    global $wpdb;

    $table = Metis_Tables::get('drive_items');
    $folder_id = metis_drive_normalize_parent_id($drive_id, $folder_id);
    $where = ["drive_id = %s", "parent_id = %s"];
    $args = [$drive_id, $folder_id];

    if ($folders_only) {
        $where[] = 'is_folder = 1';
    }
    if ($search !== '') {
        $where[] = 'item_name LIKE %s';
        $args[] = '%' . $wpdb->esc_like($search) . '%';
    }

    $sql = "SELECT drive_id, item_id, parent_id, item_name, mime_type, modified_time, size_bytes, web_view_link,
                   CASE
                       WHEN is_folder = 1 AND EXISTS (
                           SELECT 1
                           FROM {$table} child
                           WHERE child.drive_id = {$table}.drive_id
                             AND child.parent_id = {$table}.item_id
                             AND child.is_folder = 1
                           LIMIT 1
                       ) THEN 1
                       ELSE 0
                   END AS has_children
            FROM {$table}
            WHERE " . implode(' AND ', $where) . '
            ORDER BY is_folder DESC, item_name ASC';

    $prepared = $wpdb->prepare($sql, ...$args);
    $rows = $wpdb->get_results($prepared, ARRAY_A) ?: [];

    return array_values(array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['item_id'] ?? ''),
            'name' => (string) ($row['item_name'] ?? ''),
            'mimeType' => (string) ($row['mime_type'] ?? ''),
            'modifiedTime' => !empty($row['modified_time']) ? gmdate('c', strtotime((string) $row['modified_time'])) : '',
            'size' => isset($row['size_bytes']) ? (string) $row['size_bytes'] : '',
            'webViewLink' => (string) ($row['web_view_link'] ?? ''),
            'parents' => [(string) ($row['parent_id'] ?? '')],
            'driveId' => (string) ($row['drive_id'] ?? ''),
            'hasChildren' => !empty($row['has_children']),
        ];
    }, $rows));
}

function metis_drive_cached_search_results(string $drive_id, string $search, bool $folders_only = false): array {
    metis_drive_ensure_schema();
    global $wpdb;

    $search = trim($search);
    if ($drive_id === '' || $search === '') {
        return [];
    }

    $table = Metis_Tables::get('drive_items');
    $where = ["drive_id = %s", "item_name LIKE %s"];
    $args = [$drive_id, '%' . $wpdb->esc_like($search) . '%'];

    if ($folders_only) {
        $where[] = 'is_folder = 1';
    }

    $sql = "SELECT drive_id, item_id, parent_id, item_name, mime_type, modified_time, size_bytes, web_view_link,
                   CASE
                       WHEN is_folder = 1 AND EXISTS (
                           SELECT 1
                           FROM {$table} child
                           WHERE child.drive_id = {$table}.drive_id
                             AND child.parent_id = {$table}.item_id
                             AND child.is_folder = 1
                           LIMIT 1
                       ) THEN 1
                       ELSE 0
                   END AS has_children
            FROM {$table}
            WHERE " . implode(' AND ', $where) . '
            ORDER BY is_folder DESC, item_name ASC
            LIMIT 250';

    $prepared = $wpdb->prepare($sql, ...$args);
    $rows = $wpdb->get_results($prepared, ARRAY_A) ?: [];

    return array_values(array_map(static function (array $row): array {
        return [
            'id' => (string) ($row['item_id'] ?? ''),
            'name' => (string) ($row['item_name'] ?? ''),
            'mimeType' => (string) ($row['mime_type'] ?? ''),
            'modifiedTime' => !empty($row['modified_time']) ? gmdate('c', strtotime((string) $row['modified_time'])) : '',
            'size' => isset($row['size_bytes']) ? (string) $row['size_bytes'] : '',
            'webViewLink' => (string) ($row['web_view_link'] ?? ''),
            'parents' => [(string) ($row['parent_id'] ?? '')],
            'driveId' => (string) ($row['drive_id'] ?? ''),
            'hasChildren' => !empty($row['has_children']),
        ];
    }, $rows));
}

function metis_drive_replace_cached_children(string $drive_id, string $folder_id, array $items): void {
    metis_drive_ensure_schema();
    global $wpdb;

    $table = Metis_Tables::get('drive_items');
    $folder_id = metis_drive_normalize_parent_id($drive_id, $folder_id);

    $seen_ids = [];
    foreach ($items as $item) {
        $item_id = trim((string) ($item['id'] ?? ''));
        if ($item_id === '') {
            continue;
        }

        $parent_id = metis_drive_normalize_parent_id($drive_id, (string) (($item['parents'][0] ?? '') ?: $folder_id));
        $seen_ids[] = $item_id;
        $wpdb->replace(
            $table,
            [
                'drive_id' => $drive_id,
                'item_id' => $item_id,
                'parent_id' => $parent_id,
                'item_name' => (string) ($item['name'] ?? ''),
                'mime_type' => (string) ($item['mimeType'] ?? ''),
                'is_folder' => (string) ($item['mimeType'] ?? '') === 'application/vnd.google-apps.folder' ? 1 : 0,
                'modified_time' => metis_drive_datetime_from_google((string) ($item['modifiedTime'] ?? '')),
                'size_bytes' => isset($item['size']) && $item['size'] !== '' ? (string) $item['size'] : null,
                'web_view_link' => (string) ($item['webViewLink'] ?? ''),
                'raw_json' => metis_json_encode($item),
                'synced_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    if (empty($seen_ids)) {
        $wpdb->delete(
            $table,
            [
                'drive_id' => $drive_id,
                'parent_id' => $folder_id,
            ],
            ['%s', '%s']
        );
        return;
    }

    $placeholders = implode(',', array_fill(0, count($seen_ids), '%s'));
    $args = array_merge([$drive_id, $folder_id], $seen_ids);
    $sql = "DELETE FROM {$table}
            WHERE drive_id = %s
              AND parent_id = %s
              AND item_id NOT IN ({$placeholders})";
    $wpdb->query($wpdb->prepare($sql, ...$args));
}

function metis_drive_google_list_folder(array $cfg, string $folder_id, bool $folders_only = false): array {
    $shared_drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    if ($shared_drive_id === '') {
        return ['ok' => false, 'error' => 'Shared Drive ID is missing.'];
    }

    $all_files = [];
    $page_token = '';
    $root_mode = ($folder_id === '' || $folder_id === $shared_drive_id);

    do {
        $q_parts = ["trashed = false"];
        if ($folders_only) {
            $q_parts[] = "mimeType = 'application/vnd.google-apps.folder'";
        }
        if ($root_mode) {
            $q_parts[] = "'root' in parents";
        } else {
            $q_parts[] = "'" . str_replace("'", "\\'", $folder_id) . "' in parents";
        }

        $params = [
            'corpora' => 'drive',
            'driveId' => $shared_drive_id,
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives' => 'true',
            'useDomainAdminAccess' => 'true',
            'q' => implode(' and ', $q_parts),
            'orderBy' => $folders_only ? 'name' : 'folder,name',
            'pageSize' => 200,
            'fields' => 'files(id,name,mimeType,modifiedTime,size,webViewLink,parents,driveId),nextPageToken',
        ];
        if ($page_token !== '') {
            $params['pageToken'] = $page_token;
        }

        $url = add_query_arg($params, 'https://www.googleapis.com/drive/v3/files');
        $resp = metis_drive_google_request('GET', $url, null, $cfg);

        if ($root_mode && !empty($resp['ok']) && empty($resp['body']['files']) && $page_token === '') {
            $fallback_params = $params;
            $fallback_params['q'] = implode(' and ', array_filter([
                'trashed = false',
                $folders_only ? "mimeType = 'application/vnd.google-apps.folder'" : '',
                "'" . str_replace("'", "\\'", $shared_drive_id) . "' in parents",
            ]));
            $fallback_url = add_query_arg($fallback_params, 'https://www.googleapis.com/drive/v3/files');
            $fallback = metis_drive_google_request('GET', $fallback_url, null, $cfg);
            if (!empty($fallback['ok'])) {
                $resp = $fallback;
            }
        }

        if (empty($resp['ok'])) {
            return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Failed to list folder contents.')];
        }

        foreach ((array) ($resp['body']['files'] ?? []) as $file) {
            if (is_array($file)) {
                $all_files[] = $file;
            }
        }

        $page_token = (string) ($resp['body']['nextPageToken'] ?? '');
    } while ($page_token !== '');

    return ['ok' => true, 'files' => $all_files];
}

function metis_drive_sync_folder_listing(array $cfg, string $folder_id, int $depth = 0, bool $force = false, bool $full = false): array {
    metis_drive_ensure_schema();

    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    $folder_id = metis_drive_normalize_parent_id($drive_id, $folder_id);
    $depth = $full ? max(0, $depth) : max(0, min(metis_drive_sync_max_depth(), $depth));
    $sync_depth_value = $full ? 999 : $depth;

    if ($drive_id === '') {
        return ['ok' => false, 'error' => 'Shared Drive ID is missing.'];
    }

    if (!$force && !metis_drive_sync_needs_refresh($drive_id, $folder_id, metis_drive_background_sync_interval())) {
        return ['ok' => true, 'status' => 'fresh'];
    }

    if (!metis_drive_acquire_sync_lock($drive_id, $folder_id)) {
        return ['ok' => true, 'status' => 'locked'];
    }

    try {
        $folder_name = '';
        $parent_folder_id = $drive_id;
        if ($folder_id !== $drive_id) {
            $meta = metis_drive_get_file_meta($cfg, $folder_id, 'id,name,mimeType,parents,driveId,webViewLink');
            if (empty($meta['ok'])) {
                metis_drive_update_sync_state($drive_id, $folder_id, [
                    'folder_name' => '',
                    'parent_folder_id' => '',
                    'last_synced_at' => current_time('mysql'),
                    'last_requested_at' => current_time('mysql'),
                    'sync_status' => 'error',
                    'sync_depth' => $sync_depth_value,
                    'item_count' => 0,
                    'last_error' => (string) ($meta['error'] ?? 'Failed to load folder metadata.'),
                ]);
                return $meta;
            }

            $body = (array) ($meta['body'] ?? []);
            $folder_name = (string) ($body['name'] ?? '');
            $parent_folder_id = metis_drive_normalize_parent_id($drive_id, (string) ($body['parents'][0] ?? $drive_id));
        }

        metis_drive_update_sync_state($drive_id, $folder_id, [
            'folder_name' => $folder_name,
            'parent_folder_id' => $parent_folder_id,
            'last_synced_at' => (string) (metis_drive_sync_state($drive_id, $folder_id)['last_synced_at'] ?? ''),
            'last_requested_at' => current_time('mysql'),
            'sync_status' => 'running',
            'sync_depth' => $sync_depth_value,
            'item_count' => 0,
            'last_error' => '',
        ]);

        $listing = metis_drive_google_list_folder($cfg, $folder_id, false);
        if (empty($listing['ok'])) {
            metis_drive_update_sync_state($drive_id, $folder_id, [
                'folder_name' => $folder_name,
                'parent_folder_id' => $parent_folder_id,
                'last_synced_at' => current_time('mysql'),
                'last_requested_at' => current_time('mysql'),
                'sync_status' => 'error',
                'sync_depth' => $sync_depth_value,
                'item_count' => 0,
                'last_error' => (string) ($listing['error'] ?? 'Failed to sync folder listing.'),
            ]);
            return $listing;
        }

        $files = (array) ($listing['files'] ?? []);
        metis_drive_replace_cached_children($drive_id, $folder_id, $files);
        metis_drive_bump_response_cache_version();

        $synced_at = current_time('mysql');
        metis_drive_update_sync_state($drive_id, $folder_id, [
            'folder_name' => $folder_name,
            'parent_folder_id' => $parent_folder_id,
            'last_synced_at' => $synced_at,
            'last_requested_at' => $synced_at,
            'sync_status' => 'idle',
            'sync_depth' => $sync_depth_value,
            'item_count' => count($files),
            'last_error' => '',
        ]);

        $synced_children = 0;
        if ($full || $depth > 0) {
            foreach ($files as $file) {
                if ((string) ($file['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
                    continue;
                }
                $child_id = (string) ($file['id'] ?? '');
                if ($child_id === '') {
                    continue;
                }
                $child_result = metis_drive_sync_folder_listing($cfg, $child_id, max(0, $depth - 1), $force, $full);
                if (!empty($child_result['ok'])) {
                    $synced_children++;
                }
            }
        }

        return [
            'ok' => true,
            'status' => 'synced',
            'folder_id' => $folder_id,
            'item_count' => count($files),
            'synced_children' => $synced_children,
        ];
    } finally {
        metis_drive_release_sync_lock($drive_id, $folder_id);
    }
}

function metis_drive_sync_entire_drive(array $cfg): array {
    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    if ($drive_id === '') {
        return ['ok' => false, 'error' => 'Shared Drive ID is missing.'];
    }

    $result = metis_drive_sync_folder_listing($cfg, $drive_id, 0, true, true);
    if (empty($result['ok'])) {
        return $result;
    }

    $token_resp = metis_drive_google_start_page_token($cfg);
    if (!empty($token_resp['ok'])) {
        metis_sync_state_update(metis_drive_sync_service_key($drive_id), [
            'last_sync' => current_time('mysql'),
            'sync_token' => (string) (($token_resp['body']['startPageToken'] ?? '')),
        ]);
    }

    return $result;
}

function metis_drive_sync_worker(array $cfg, string $folder_id = '', int $depth = 0, bool $force = false): array {
    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    if ($drive_id === '') {
        return ['ok' => false, 'error' => 'Shared Drive ID is missing.'];
    }

    $service_key = metis_drive_sync_service_key($drive_id);
    $service_state = metis_sync_state_get($service_key);
    $sync_token = !$force ? trim((string) ($service_state['sync_token'] ?? '')) : '';

    if ($sync_token === '') {
        $result = metis_drive_sync_folder_listing($cfg, $folder_id !== '' ? $folder_id : $drive_id, max($depth, metis_drive_sync_max_depth()), true);
        if (empty($result['ok'])) {
            return $result;
        }

        $token_resp = metis_drive_google_start_page_token($cfg);
        if (!empty($token_resp['ok'])) {
            metis_sync_state_update($service_key, [
                'last_sync' => current_time('mysql'),
                'sync_token' => (string) (($token_resp['body']['startPageToken'] ?? '')),
            ]);
        }

        return $result;
    }

    $changes = metis_drive_google_list_changes($cfg, $sync_token);
    if (empty($changes['ok']) && (int) ($changes['status'] ?? 0) === 410) {
        metis_sync_state_update($service_key, [
            'last_sync' => (string) ($service_state['last_sync'] ?? ''),
            'sync_token' => '',
        ]);
        return metis_drive_sync_worker($cfg, $folder_id, $depth, true);
    }
    if (empty($changes['ok'])) {
        return $changes;
    }

    $touched_parents = [];
    foreach ((array) ($changes['changes'] ?? []) as $change) {
        $removed = !empty($change['removed']);
        $file_id = trim((string) ($change['fileId'] ?? ''));
        $file = (array) ($change['file'] ?? []);
        $parent_id = metis_drive_normalize_parent_id($drive_id, (string) (($file['parents'][0] ?? '') ?: ''));

        if ($removed || !empty($file['trashed'])) {
            if ($file_id !== '') {
                $cached = metis_drive_cached_item_meta($drive_id, $file_id);
                if (!empty($cached['ok'])) {
                    $cached_parent = metis_drive_normalize_parent_id($drive_id, (string) (($cached['file']['parents'][0] ?? '') ?: ''));
                    if ($cached_parent !== '') {
                        $touched_parents[$cached_parent] = true;
                    }
                }
                metis_drive_delete_cached_item($drive_id, $file_id);
            }
            continue;
        }

        if ((string) ($file['driveId'] ?? $drive_id) !== $drive_id) {
            continue;
        }

        metis_drive_store_cached_item($drive_id, $file, $parent_id);
        if ($parent_id !== '') {
            $touched_parents[$parent_id] = true;
        }
        if ($file_id !== '' && (string) ($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
            metis_drive_update_sync_state($drive_id, $file_id, [
                'parent_folder_id' => $parent_id,
                'folder_name' => (string) ($file['name'] ?? ''),
                'last_synced_at' => current_time('mysql'),
                'last_requested_at' => (string) (metis_drive_sync_state($drive_id, $file_id)['last_requested_at'] ?? ''),
                'sync_status' => 'idle',
                'sync_depth' => 0,
                'item_count' => (int) (metis_drive_sync_state($drive_id, $file_id)['item_count'] ?? 0),
                'last_error' => '',
            ]);
        }
    }

    $synced_at = current_time('mysql');
    foreach (array_keys($touched_parents) as $parent_id) {
        $count = count(metis_drive_cached_folder_children($drive_id, $parent_id, '', false));
        $state = metis_drive_sync_state($drive_id, $parent_id);
        metis_drive_update_sync_state($drive_id, $parent_id, [
            'parent_folder_id' => (string) ($state['parent_folder_id'] ?? ''),
            'folder_name' => (string) ($state['folder_name'] ?? ''),
            'last_synced_at' => $synced_at,
            'last_requested_at' => (string) ($state['last_requested_at'] ?? ''),
            'sync_status' => 'idle',
            'sync_depth' => (int) ($state['sync_depth'] ?? 0),
            'item_count' => $count,
            'last_error' => '',
        ]);
    }

    metis_sync_state_update($service_key, [
        'last_sync' => $synced_at,
        'sync_token' => (string) ($changes['next_sync_token'] ?? $sync_token),
    ]);

    if (!empty($touched_parents) || !empty($changes['changes'])) {
        metis_drive_bump_response_cache_version();
    }

    return [
        'ok' => true,
        'status' => 'synced',
        'folder_id' => $folder_id !== '' ? $folder_id : $drive_id,
        'item_count' => count((array) ($changes['changes'] ?? [])),
    ];
}

function metis_drive_schedule_background_sync(array $cfg, string $folder_id, int $depth = 2, bool $force = false): void {
    static $scheduled = [];

    $drive_id = (string) ($cfg['shared_drive_id'] ?? '');
    $folder_id = metis_drive_normalize_parent_id($drive_id, $folder_id);
    if ($drive_id === '' || $folder_id === '') {
        return;
    }

    $depth = max(0, min(metis_drive_sync_max_depth(), $depth));
    $key = $drive_id . '|' . $folder_id . '|' . $depth . '|' . ($force ? '1' : '0');
    if (isset($scheduled[$key])) {
        return;
    }
    $scheduled[$key] = true;

    register_shutdown_function(static function () use ($cfg, $drive_id, $folder_id, $depth, $force): void {
        if (!$force && !metis_drive_sync_needs_refresh($drive_id, $folder_id, metis_drive_background_sync_interval())) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        metis_drive_sync_worker($cfg, $folder_id, $depth, $force);
    });
}

function metis_drive_sync_all_configured_drives(): array {
    $results = [];
    foreach (metis_drive_configured_drives() as $drive) {
        $drive_id = (string) ($drive['drive_id'] ?? '');
        if ($drive_id === '') {
            continue;
        }

        $cfg = metis_drive_workspace_settings($drive_id);
        if (empty($cfg['ok'])) {
            $results[$drive_id] = [
                'ok' => false,
                'error' => (string) ($cfg['error'] ?? 'Drive config missing.'),
            ];
            continue;
        }

        $results[$drive_id] = metis_drive_sync_entire_drive($cfg);
    }

    return [
        'drives' => $results,
        'count' => count($results),
    ];
}

if (class_exists('Metis_Cron_Manager')) {
    $drive_index = 0;
    $interval = metis_drive_cron_interval();
    $stagger = metis_drive_cron_stagger_interval();

    foreach (metis_drive_configured_drives() as $drive) {
        $drive_id = (string) ($drive['drive_id'] ?? '');
        if ($drive_id === '') {
            continue;
        }

        $task_slug = metis_drive_cron_task_slug($drive_id);
        $drive_label = trim((string) ($drive['label'] ?? $drive['drive_name'] ?? $drive_id));

        Metis_Cron_Manager::register_task(
            $task_slug,
            static function () use ($drive_id): array {
                $cfg = metis_drive_workspace_settings($drive_id);
                if (empty($cfg['ok'])) {
                    return [
                        'ok' => false,
                        'error' => (string) ($cfg['error'] ?? 'Drive config missing.'),
                    ];
                }

                return metis_drive_sync_entire_drive($cfg);
            },
            [
                'label' => 'Drive Listing Sync: ' . $drive_label,
                'interval' => $interval,
                'lock_ttl' => 25 * MINUTE_IN_SECONDS,
                'module' => 'drive',
            ]
        );

        metis_drive_prime_cron_task_state(
            $task_slug,
            $interval,
            ($drive_index * $stagger) % $interval
        );

        $drive_index++;
    }
}
