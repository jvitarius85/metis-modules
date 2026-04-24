<?php
if (!defined('METIS_ROOT')) exit;

require_once dirname( __DIR__, 2 ) . '/portal/views/_dashboard_data.php';

function metis_people_ajax_verify(): void {
    metis_people_ensure_schema();
    metis_people_seed_permissions_and_roles();
}

function metis_people_workspace_ajax_verify(): void {
    metis_people_ensure_schema();
    metis_people_seed_permissions_and_roles();
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $metis_people_actions = [
        'metis_people_save_person' => 'edit',
        'metis_people_save_avatar' => 'edit',
        'metis_people_offboard_person' => 'edit',
        'metis_people_get_positions' => 'view',
        'metis_people_save_position' => 'edit',
        'metis_people_delete_position' => 'edit',
        'metis_people_add_document' => 'edit',
        'metis_people_grant_emergency_access' => 'edit',
        'metis_people_delete_document' => 'delete',
        'metis_people_revoke_emergency_access' => 'edit',
        'metis_people_search_person' => 'view',
        'metis_people_search_donor' => 'view',
        'metis_people_get_activity_page' => 'view',
    ];
    foreach ( $metis_people_actions as $action => $permission ) {
        metis_ajax_register_controller( $action, [
            'module' => 'people',
            'permission' => $permission,
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
}

function metis_people_normalize_position_group( string $group ): string {
    $normalized = metis_key_clean( $group );
    if ( ! in_array( $normalized, [ 'board', 'staff', 'volunteer' ], true ) ) {
        return '';
    }
    return $normalized;
}

function metis_people_position_payload( array $row ): array {
    return [
        'id' => (int) ( $row['id'] ?? 0 ),
        'group_key' => (string) ( $row['group_key'] ?? '' ),
        'position_key' => (string) ( $row['position_key'] ?? '' ),
        'position_label' => (string) ( $row['position_label'] ?? '' ),
        'sort_order' => (int) ( $row['sort_order'] ?? 0 ),
    ];
}

function metis_people_totp_base32_chars(): string {
    return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
}

function metis_people_totp_generate_secret(int $length = 32): string {
    $chars = metis_people_totp_base32_chars();
    $max = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

function metis_people_totp_base32_decode(string $input): string {
    $input = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input));
    $alphabet = metis_people_totp_base32_chars();
    $buffer = 0;
    $bits = 0;
    $output = '';
    $len = strlen($input);
    for ($i = 0; $i < $len; $i++) {
        $v = strpos($alphabet, $input[$i]);
        if ($v === false) continue;
        $buffer = ($buffer << 5) | $v;
        $bits += 5;
        while ($bits >= 8) {
            $bits -= 8;
            $output .= chr(($buffer >> $bits) & 0xFF);
        }
    }
    return $output;
}

function metis_people_totp_now(string $secret, int $period = 30, int $digits = 6, ?int $time = null): string {
    $time = $time ?? time();
    $counter = intdiv($time, $period);
    $binaryCounter = pack('N*', 0) . pack('N*', $counter);
    $key = metis_people_totp_base32_decode($secret);
    if ($key === '') return '';
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $slice = substr($hash, $offset, 4);
    $value = unpack('N', $slice)[1] & 0x7FFFFFFF;
    $mod = 10 ** $digits;
    $otp = (string) ($value % $mod);
    return str_pad($otp, $digits, '0', STR_PAD_LEFT);
}

function metis_people_encrypt_secret(string $plain): string {
    if ($plain === '') return '';
    if (function_exists('metis_auth_secret_key_bytes')) {
        $key = metis_auth_secret_key_bytes();
    } else {
        $auth_key = defined('AUTH_KEY') ? (string) AUTH_KEY : (string) metis_runtime_config_get('app_key', 'metis-local-key');
        $secure_auth_key = defined('SECURE_AUTH_KEY') ? (string) SECURE_AUTH_KEY : $auth_key;
        $key = hash('sha256', $auth_key . $secure_auth_key, true);
    }
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return '';
    return base64_encode($iv . $cipher);
}

function metis_people_b64url_encode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function metis_people_b64url_decode(string $b64url): string {
    $b64 = strtr($b64url, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($b64, true);
    return $decoded === false ? '' : $decoded;
}

function metis_people_create_challenge(?int $person_id, string $purpose, int $ttl_seconds = 600): array {
    $db = metis_db();
    $table = Metis_Tables::get('people_auth_challenges');
    $challenge_key = bin2hex(random_bytes(16));
    $challenge_raw = random_bytes(32);
    $challenge_value = metis_people_b64url_encode($challenge_raw);
    $expires = gmdate('Y-m-d H:i:s', time() + max(60, $ttl_seconds));
    $db->insert($table, [
        'person_id' => $person_id > 0 ? $person_id : null,
        'challenge_key' => $challenge_key,
        'challenge_value' => $challenge_value,
        'purpose' => $purpose,
        'expires_at' => $expires,
    ], ['%d', '%s', '%s', '%s', '%s']);
    return [
        'challenge_key' => $challenge_key,
        'challenge_value' => $challenge_value,
        'expires_at' => $expires,
    ];
}

function metis_people_consume_challenge(string $challenge_key, string $purpose, ?int $person_id = null): ?array {
    $db = metis_db();
    $table = Metis_Tables::get('people_auth_challenges');
    $row = $db->fetchOne(
        "SELECT * FROM {$table}
         WHERE challenge_key = %s
           AND purpose = %s
           AND consumed_at IS NULL
           AND expires_at >= UTC_TIMESTAMP()
         LIMIT 1",
        [ $challenge_key, $purpose ]
    );
    if (!$row) return null;
    if ($person_id !== null && (int) $row['person_id'] !== $person_id) return null;
    $db->update($table, ['consumed_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $row['id']], ['%s'], ['%d']);
    return $row;
}

function metis_people_origin_allowed(string $origin): bool {
    if ($origin === '') return false;
    $site = metis_home_url();
    $site_parts = metis_runtime_parse_url($site);
    $origin_parts = metis_runtime_parse_url($origin);
    if (!$site_parts || !$origin_parts) return false;
    $site_host = strtolower((string) ($site_parts['host'] ?? ''));
    $origin_host = strtolower((string) ($origin_parts['host'] ?? ''));
    $site_scheme = strtolower((string) ($site_parts['scheme'] ?? 'https'));
    $origin_scheme = strtolower((string) ($origin_parts['scheme'] ?? 'https'));
    return $site_host !== '' && $site_host === $origin_host && $site_scheme === $origin_scheme;
}

function metis_people_parse_lines_to_json(?string $raw): ?string {
    if ($raw === null) {
        return null;
    }
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    if (!is_array($lines)) {
        return null;
    }
    $clean = [];
    foreach ($lines as $line) {
        $item = metis_text_clean((string) $line);
        if ($item === '') continue;
        if (!in_array($item, $clean, true)) {
            $clean[] = $item;
        }
    }
    return metis_json_encode($clean);
}

function metis_people_table_has_column(string $column): bool {
    static $cache = [];

    $key = trim($column);
    if ($key === '') {
        return false;
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $people_table = Metis_Tables::get('people');
    $exists = metis_db()->scalar("SHOW COLUMNS FROM {$people_table} LIKE %s", [ $key ]);
    $cache[$key] = !empty($exists);
    return $cache[$key];
}

function metis_people_identity_select_fields(): string {
    return 'id, pid';
}

if (!function_exists('metis_people_autocreate_drive_folder_for_person')) {
    function metis_people_autocreate_drive_folder_for_person(int $person_id, string $pid = ''): array {
        if ($person_id < 1) {
            return ['ok' => false, 'created' => false, 'error' => 'Invalid person id.'];
        }
        if (
            !function_exists('metis_drive_workspace_settings')
            || !function_exists('metis_drive_find_or_create_user_folder')
            || !function_exists('metis_drive_ensure_schema')
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
            return ['ok' => false, 'created' => false, 'error' => 'Failed to resolve Drive folder.'];
        }

        if (!empty($folder['created']) && function_exists('metis_drive_log_action')) {
            metis_drive_log_action($cfg, 'create_user_folder', [
                'folder_id' => (string) ($folder['folder_id'] ?? ''),
                'item_name' => (string) ($folder['folder_name'] ?? ''),
                'item_type' => 'folder',
                'details' => [
                    'person_id' => $person_id,
                    'pid' => $pid,
                    'source' => 'people_save_person_autocreate',
                ],
            ]);
        }

        $folder_url = '';
        if (function_exists('metis_portal_url')) {
            $folder_url = metis_add_query_arg(
                ['folder_id' => (string) ($folder['folder_id'] ?? '')],
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

function metis_people_resolve_person_record(int $person_id = 0, string $pid = ''): array {
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    if (!$people_table) {
        return ['ok' => false, 'error' => 'People table is not available.', 'status' => 500];
    }

    $pid = trim($pid);
    $person_by_pid = null;
    $person_by_id = null;

    $pid_lookup_mode = 'none';
    $select_fields = metis_people_identity_select_fields();
    if ($pid !== '') {
        $person_by_pid = $db->fetchOne("SELECT {$select_fields} FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ]);
        if ($person_by_pid) {
            $pid_lookup_mode = 'exact';
        }
        if (!$person_by_pid) {
            $person_by_pid = $db->fetchOne("SELECT {$select_fields} FROM {$people_table} WHERE UPPER(pid) = UPPER(%s) LIMIT 1", [ $pid ]);
            if ($person_by_pid) {
                $pid_lookup_mode = 'case_insensitive';
            }
        }
    }

    if ($person_id > 0) {
        $person_by_id = $db->fetchOne("SELECT {$select_fields} FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ]);
    }

    if ($person_by_pid && $person_by_id && (int) ($person_by_pid['id'] ?? 0) !== (int) ($person_by_id['id'] ?? 0)) {
        return ['ok' => false, 'error' => 'Person identifier mismatch.', 'status' => 409];
    }

    $person = $person_by_pid ?: $person_by_id;
    if (!$person) {
        $row_count = (int) $db->scalar("SELECT COUNT(*) FROM {$people_table}");
        $sample_row = $db->fetchOne("SELECT id, pid FROM {$people_table} ORDER BY id ASC LIMIT 1");
        $details = [];
        if ($person_id > 0) {
            $details[] = 'person_id=' . $person_id;
            $details[] = 'by_id=no';
        }
        if ($pid !== '') {
            $details[] = 'pid=' . $pid;
            $details[] = 'by_pid=no';
        }
        $details[] = 'table=' . $people_table;
        $details[] = 'rows=' . $row_count;
        $details[] = 'db=' . (string) metis_runtime_config_get('db_name', '');
        $details[] = 'host=' . (string) metis_runtime_config_get('db_host', '');
        if (is_array($sample_row) && $sample_row !== []) {
            $details[] = 'sample_id=' . (string) ($sample_row['id'] ?? '');
            $details[] = 'sample_pid=' . (string) ($sample_row['pid'] ?? '');
        }
        return [
            'ok' => false,
            'error' => 'Person not found. (' . implode(', ', $details) . ')',
            'status' => 404,
            'debug' => [
                'table' => $people_table,
                'person_id' => $person_id,
                'pid' => $pid,
                'pid_lookup_mode' => $pid_lookup_mode,
            ],
        ];
    }

    return ['ok' => true, 'person' => $person];
}

function metis_people_active_permission_keys_for_person(int $person_id): array {
    if ($person_id < 1) return [];
    $roles_table = Metis_Tables::get('people_roles');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $role_perms_table = Metis_Tables::get('people_role_perms');
    $perms_table = Metis_Tables::get('people_permissions');
    $rows = metis_db()->column(
        "SELECT DISTINCT p.permission_key
         FROM {$user_roles_table} ur
         INNER JOIN {$roles_table} r ON r.id = ur.role_id
         INNER JOIN {$role_perms_table} rp ON rp.role_id = ur.role_id AND rp.allow_access = 1
         INNER JOIN {$perms_table} p ON p.id = rp.permission_id
         WHERE ur.person_id = %d
           AND (ur.start_at IS NULL OR ur.start_at <= NOW())
           AND (ur.end_at IS NULL OR ur.end_at >= NOW())",
        [ $person_id ]
    );
    $out = [];
    foreach ($rows as $permission_key) {
        $key = metis_key_clean((string) $permission_key);
        if ($key !== '') $out[$key] = true;
    }
    return array_keys($out);
}

function metis_people_workspace_queue_job(string $job_type, string $entity_type, ?int $entity_id, ?int $requested_by_person_id, array $payload = []): int {
    $db = metis_db();
    $table = Metis_Tables::get('people_workspace_sync_jobs');
    $job_type = metis_key_clean($job_type);
    $entity_type = metis_key_clean($entity_type);
    $entity_id = ($entity_id && $entity_id > 0) ? (int) $entity_id : null;
    $requested_by_person_id = ($requested_by_person_id && $requested_by_person_id > 0) ? (int) $requested_by_person_id : null;
    if ($entity_id !== null && in_array($job_type, ['stripe_user_upsert', 'stripe_user_disable'], true)) {
        $existing = $db->fetchOne(
            "SELECT id, status
             FROM {$table}
             WHERE entity_type = %s
               AND entity_id = %d
               AND job_type IN ('stripe_user_upsert', 'stripe_user_disable')
               AND status IN ('queued', 'processing')
             ORDER BY id DESC
             LIMIT 1",
            [ $entity_type, $entity_id ]
        );
        $payload_json = !empty($payload) ? metis_json_encode($payload) : null;
        if ($existing && (int) ($existing['id'] ?? 0) > 0) {
            $existing_id = (int) $existing['id'];
            if ((string) ($existing['status'] ?? '') === 'queued') {
                $update_payload = [
                    'job_type' => $job_type,
                    'payload_json' => $payload_json,
                    'last_error' => null,
                    'status' => 'queued',
                ];
                $update_format = ['%s', '%s', '%s', '%s'];
                if ($requested_by_person_id !== null) {
                    $update_payload['requested_by_person_id'] = $requested_by_person_id;
                    $update_format[] = '%d';
                }
                $db->update(
                    $table,
                    $update_payload,
                    ['id' => $existing_id],
                    $update_format,
                    ['%d']
                );
            }
            return $existing_id;
        }
    }
    $ok = $db->insert($table, [
        'job_type' => $job_type,
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'requested_by_person_id' => $requested_by_person_id,
        'payload_json' => !empty($payload) ? metis_json_encode($payload) : null,
        'status' => 'queued',
    ], ['%s', '%s', '%d', '%d', '%s', '%s']);
    if (!$ok) return 0;
    return (int) $db->lastInsertId();
}

function metis_people_workspace_sync_settings(): array {
    $service = function_exists( 'metis_workspace_service_account_payload' ) ? metis_workspace_service_account_payload() : [];
    $impersonation_admin = strtolower(trim((string) Core_Settings_Service::get('workspace_impersonation_admin', '')));
    $workspace_domain = strtolower(trim((string) Core_Settings_Service::get('workspace_domain', '')));
    $customer_id = trim((string) Core_Settings_Service::get('workspace_customer_id', ''));
    $stripe_schema_name = trim((string) Core_Settings_Service::get('workspace_stripe_sso_schema', ''));
    $stripe_field_name = trim((string) Core_Settings_Service::get('workspace_stripe_sso_field', ''));
    $stripe_access_group_email = strtolower(trim((string) Core_Settings_Service::get('workspace_stripe_access_group_email', '')));
    if ( empty( $service ) || !metis_email_is_valid($impersonation_admin)) {
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
        'domain' => $workspace_domain,
        'customer_id' => $customer_id,
        'stripe_sso_schema' => $stripe_schema_name,
        'stripe_sso_field' => $stripe_field_name,
        'stripe_access_group_email' => $stripe_access_group_email,
        'scopes' => [
            'https://www.googleapis.com/auth/admin.directory.user',
            'https://www.googleapis.com/auth/admin.directory.user.security',
            'https://www.googleapis.com/auth/admin.directory.group',
            'https://www.googleapis.com/auth/admin.directory.group.member',
            'https://www.googleapis.com/auth/admin.directory.rolemanagement',
        ],
    ];
}

function metis_people_workspace_google_access_token(array $cfg): array {
    $service = (array) ($cfg['service'] ?? []);
    $client_email = (string) ($service['client_email'] ?? '');
    $private_key = (string) ($service['private_key'] ?? '');
    $token_uri = (string) ($service['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    $subject = (string) ($cfg['subject'] ?? '');
    $scopes = (array) ($cfg['scopes'] ?? []);
    if ($client_email === '' || $private_key === '' || $subject === '' || empty($scopes)) {
        return ['ok' => false, 'error' => 'Workspace OAuth configuration is incomplete.'];
    }
    $cache_key = 'metis_ws_token_' . md5($client_email . '|' . $subject . '|' . implode(' ', $scopes));
    $cached = metis_get_transient($cache_key);
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
    $jwt_input = metis_people_b64url_encode(metis_json_encode($header)) . '.' . metis_people_b64url_encode(metis_json_encode($claims));
    $signature = '';
    $signed = openssl_sign($jwt_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
    if (!$signed) return ['ok' => false, 'error' => 'Could not sign Workspace JWT assertion.'];
    $assertion = $jwt_input . '.' . metis_people_b64url_encode($signature);
    $response = metis_runtime_remote_post($token_uri, [
        'timeout' => 20,
        'body' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ],
    ]);
    if (metis_runtime_is_error($response)) return ['ok' => false, 'error' => 'Workspace token request failed.'];
    $code = (int) metis_runtime_remote_retrieve_response_code($response);
    $body = json_decode((string) metis_runtime_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['access_token'])) {
        return ['ok' => false, 'error' => 'Workspace token request failed (' . $code . ').'];
    }
    $access_token = (string) $body['access_token'];
    $ttl = max(120, ((int) ($body['expires_in'] ?? 3600)) - 60);
    metis_set_transient($cache_key, ['access_token' => $access_token], $ttl);
    return ['ok' => true, 'access_token' => $access_token];
}

function metis_people_workspace_google_request(string $method, string $path, ?array $body, array $cfg): array {
    $token = metis_people_workspace_google_access_token($cfg);
    if (empty($token['ok'])) {
        return [
            'ok' => false,
            'error' => 'Workspace token error.',
            'method' => strtoupper($method),
            'path' => $path,
            'subject' => (string) ($cfg['subject'] ?? ''),
        ];
    }
    $url = str_starts_with($path, 'http') ? $path : ('https://admin.googleapis.com/admin/directory/v1/' . ltrim($path, '/'));
    $args = [
        'method' => strtoupper($method),
        'timeout' => 25,
        'headers' => [
            'Authorization' => 'Bearer ' . (string) $token['access_token'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
    ];
    if ($body !== null) {
        $args['body'] = metis_json_encode($body);
    }
    $response = metis_runtime_remote_request($url, $args);
    if (metis_runtime_is_error($response)) {
        return [
            'ok' => false,
            'error' => 'Google API request failed.',
            'method' => strtoupper($method),
            'url' => $url,
            'path' => $path,
            'subject' => (string) ($cfg['subject'] ?? ''),
        ];
    }
    $code = (int) metis_runtime_remote_retrieve_response_code($response);
    $raw = (string) metis_runtime_remote_retrieve_body($response);
    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = 'Google API request failed (' . $code . ').';
        return [
            'ok' => false,
            'error' => $msg,
            'status' => $code,
            'method' => strtoupper($method),
            'url' => $url,
            'path' => $path,
            'subject' => (string) ($cfg['subject'] ?? ''),
            'raw' => $raw,
        ];
    }
    if (!is_array($decoded)) {
        $trimmed_raw = trim($raw);
        if ($trimmed_raw !== '') {
            $excerpt = preg_replace('/\s+/', ' ', $trimmed_raw);
            $excerpt = substr((string) $excerpt, 0, 240);
            return [
                'ok' => false,
                'error' => 'Google API returned non-JSON response.',
                'status' => $code,
                'method' => strtoupper($method),
                'url' => $url,
                'path' => $path,
                'subject' => (string) ($cfg['subject'] ?? ''),
                'raw' => $excerpt,
            ];
        }
    }
    return [
        'ok' => true,
        'status' => $code,
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => $raw,
        'url' => $url,
    ];
}

function metis_people_workspace_normalize_role_key(string $value): string {
    $key = metis_key_clean(str_replace(['-', ' '], '_', strtolower(trim($value))));
    $key = preg_replace('/^_+/', '', (string) $key);
    $key = preg_replace('/_role$/', '', (string) $key);
    return $key;
}

function metis_people_workspace_role_aliases(): array {
    return [
        'seed_admin' => ['key' => 'super_admin', 'label' => 'Super Admin'],
        'super_admin' => ['key' => 'super_admin', 'label' => 'Super Admin'],
        'groups_admin' => ['key' => 'groups_admin', 'label' => 'Groups Admin'],
        'groups_reader' => ['key' => 'groups_reader', 'label' => 'Groups Reader'],
        'groups_editor' => ['key' => 'groups_editor', 'label' => 'Groups Editor'],
        'user_management_admin' => ['key' => 'user_management_admin', 'label' => 'User Management Admin'],
        'help_desk_admin' => ['key' => 'help_desk_admin', 'label' => 'Help Desk Admin'],
        'services_admin' => ['key' => 'services_admin', 'label' => 'Services Admin'],
        'readonly_admin' => ['key' => 'readonly_admin', 'label' => 'Read-only Admin'],
        'read_only_admin' => ['key' => 'readonly_admin', 'label' => 'Read-only Admin'],
        'storage_admin' => ['key' => 'storage_admin', 'label' => 'Storage Admin'],
        'directory_sync_admin' => ['key' => 'directory_sync_admin', 'label' => 'Directory Sync Admin'],
        'mobile_admin' => ['key' => 'mobile_admin', 'label' => 'Mobile Admin'],
        'inventory_reporting_admin' => ['key' => 'inventory_reporting_admin', 'label' => 'Inventory Reporting Admin'],
        'google_workspace_migrate_drive_admin' => ['key' => 'google_workspace_migrate_drive_admin', 'label' => 'Google Workspace Migrate Drive Admin'],
        'migration_drive_admin' => ['key' => 'google_workspace_migrate_drive_admin', 'label' => 'Google Workspace Migrate Drive Admin'],
        'third_party_device_management_admin' => ['key' => 'third_party_device_management_admin', 'label' => 'Third Party Device Management Admin'],
        'gcds_directory_management' => ['key' => 'gcds_directory_management', 'label' => 'GCDS Directory Management'],
    ];
}

function metis_people_workspace_resolve_role_meta(string $google_role_name, string $google_role_description = ''): array {
    $role_name = trim($google_role_name);
    $role_desc = trim($google_role_description);
    $normalized = metis_people_workspace_normalize_role_key($role_name);
    if ($normalized === '' && $role_desc !== '') {
        $normalized = metis_people_workspace_normalize_role_key($role_desc);
    }
    $aliases = metis_people_workspace_role_aliases();
    $alias = $aliases[$normalized] ?? null;
    if (!$alias && str_contains(strtolower($role_desc), 'administrator seed role')) {
        $alias = $aliases['seed_admin'];
    }
    if (is_array($alias) && !empty($alias['key']) && !empty($alias['label'])) {
        return [
            'role_key' => (string) $alias['key'],
            'role_label' => (string) $alias['label'],
            'normalized_source' => $normalized,
        ];
    }
    $fallback_key = $normalized !== '' ? $normalized : metis_people_workspace_normalize_role_key($role_name !== '' ? $role_name : $role_desc);
    $fallback_label = $role_name !== '' ? $role_name : $fallback_key;
    $fallback_label = preg_replace('/^_+/', '', (string) $fallback_label);
    $fallback_label = preg_replace('/_ROLE$/i', '', (string) $fallback_label);
    $fallback_label = trim(str_replace('_', ' ', (string) $fallback_label));
    $fallback_label = ucwords(strtolower((string) $fallback_label));
    if ($fallback_label === '') $fallback_label = 'Workspace Role';
    return [
        'role_key' => $fallback_key,
        'role_label' => $fallback_label,
        'normalized_source' => $normalized,
    ];
}

function metis_people_workspace_group_permissions_sanitize(array $input): array {
    $out = [];
    $join = strtoupper(trim((string) ($input['whoCanJoin'] ?? '')));
    $view_members = strtoupper(trim((string) ($input['whoCanViewMembership'] ?? '')));
    $post = strtoupper(trim((string) ($input['whoCanPostMessage'] ?? '')));
    $allow_external = !empty($input['allowExternalMembers']) ? 'true' : 'false';

    $join_allowed = ['INVITED_CAN_JOIN', 'CAN_REQUEST_TO_JOIN', 'ANYONE_CAN_JOIN'];
    $view_allowed = ['ALL_MANAGERS_CAN_VIEW', 'ALL_MEMBERS_CAN_VIEW', 'ALL_IN_DOMAIN_CAN_VIEW', 'ANYONE_CAN_VIEW'];
    $post_allowed = ['NONE_CAN_POST', 'ALL_MANAGERS_CAN_POST', 'ALL_MEMBERS_CAN_POST', 'ALL_IN_DOMAIN_CAN_POST', 'ANYONE_CAN_POST'];

    if (!in_array($join, $join_allowed, true)) $join = 'INVITED_CAN_JOIN';
    if (!in_array($view_members, $view_allowed, true)) $view_members = 'ALL_MEMBERS_CAN_VIEW';
    if (!in_array($post, $post_allowed, true)) $post = 'ALL_MEMBERS_CAN_POST';

    $out['whoCanJoin'] = $join;
    $out['whoCanViewMembership'] = $view_members;
    $out['whoCanPostMessage'] = $post;
    $out['allowExternalMembers'] = $allow_external;
    return $out;
}

function metis_people_workspace_random_password(int $length = 20): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
    $max = strlen($chars) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

function metis_people_workspace_normalize_schema_or_field(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    return preg_replace('/[^A-Za-z0-9_]/', '', $value);
}

function metis_people_workspace_detect_stripe_custom_field(array $custom_schemas, array $cfg): array {
    $configured_schema = trim((string) ($cfg['stripe_sso_schema'] ?? ''));
    $configured_field = trim((string) ($cfg['stripe_sso_field'] ?? ''));
    if ($configured_schema !== '') {
        foreach ($custom_schemas as $schema_name => $schema_fields) {
            if (!is_array($schema_fields)) continue;
            $raw_schema = (string) $schema_name;
            if (strcasecmp($raw_schema, $configured_schema) !== 0) continue;
            if ($configured_field !== '') {
                foreach ($schema_fields as $field_name => $field_value) {
                    $raw_field = (string) $field_name;
                    if (strcasecmp($raw_field, $configured_field) === 0) {
                        return ['schema' => $raw_schema, 'field' => $raw_field, 'source' => 'configured_existing_exact'];
                    }
                }
            }
            foreach ($schema_fields as $field_name => $field_value) {
                $raw_field = (string) $field_name;
                $field_norm = strtolower($raw_field);
                if (str_contains($field_norm, 'stripe') && str_contains($field_norm, 'role')) {
                    return ['schema' => $raw_schema, 'field' => $raw_field, 'source' => 'configured_existing_detected_field'];
                }
            }
            if ($configured_field !== '') {
                return ['schema' => $raw_schema, 'field' => $configured_field, 'source' => 'configured_existing_schema_only'];
            }
        }
    }
    if ($configured_schema !== '' && $configured_field !== '') {
        return ['schema' => $configured_schema, 'field' => $configured_field, 'source' => 'configured_raw'];
    }

    foreach ($custom_schemas as $schema_name => $schema_fields) {
        if (!is_array($schema_fields)) continue;
        foreach ($schema_fields as $field_name => $field_value) {
            $raw_schema = (string) $schema_name;
            $raw_field = (string) $field_name;
            $schema_norm = strtolower($raw_schema);
            $field_norm = strtolower($raw_field);
            if (
                str_contains($schema_norm, 'single')
                && str_contains($schema_norm, 'sign')
                && str_contains($field_norm, 'stripe')
                && str_contains($field_norm, 'role')
            ) {
                if ($raw_schema !== '' && $raw_field !== '') {
                    return ['schema' => $raw_schema, 'field' => $raw_field, 'source' => 'detected'];
                }
            }
            if ((is_string($field_value) || is_numeric($field_value)) && str_contains(strtolower((string) $field_value), 'admin')) {
                if (str_contains($field_norm, 'stripe') && str_contains($field_norm, 'role')) {
                    if ($raw_schema !== '' && $raw_field !== '') {
                        return ['schema' => $raw_schema, 'field' => $raw_field, 'source' => 'value_match'];
                    }
                }
            }
        }
    }

    $fallback_pairs = [
        ['schema' => 'SingleSignOn', 'field' => 'StripeRole'],
        ['schema' => 'SingleSignOn', 'field' => 'stripeRole'],
        ['schema' => 'Single_Sign-On', 'field' => 'Stripe_Role'],
        ['schema' => 'Single_Sign-On', 'field' => 'stripe_role'],
        ['schema' => 'Single_Sign_On', 'field' => 'Stripe_Role'],
        ['schema' => 'Single_Sign_On', 'field' => 'stripe_role'],
    ];
    foreach ($fallback_pairs as $pair) {
        foreach ($custom_schemas as $schema_name => $schema_fields) {
            if (!is_array($schema_fields)) continue;
            $raw_schema = (string) $schema_name;
            if (strcasecmp($raw_schema, (string) $pair['schema']) !== 0) continue;
            foreach ($schema_fields as $field_name => $field_value) {
                $raw_field = (string) $field_name;
                if (strcasecmp($raw_field, (string) $pair['field']) === 0) {
                    return ['schema' => $raw_schema, 'field' => $raw_field, 'source' => 'fallback'];
                }
            }
        }
    }
    if ($configured_schema !== '') {
        $fallback_field = $configured_field !== '' ? $configured_field : 'StripeRole';
        return ['schema' => $configured_schema, 'field' => $fallback_field, 'source' => 'configured_schema_only'];
    }
    return ['schema' => '', 'field' => '', 'source' => 'none'];
}

function metis_people_workspace_discover_schema_field(array $cfg, string $configured_schema = '', string $configured_field = ''): array {
    $customer = trim((string) ($cfg['customer_id'] ?? ''));
    if ($customer === '') $customer = 'my_customer';
    $cfg_schemas = $cfg;
    $cfg_schemas['scopes'] = array_values(array_unique(array_merge(
        (array) ($cfg['scopes'] ?? []),
        ['https://www.googleapis.com/auth/admin.directory.userschema.readonly']
    )));
    $resp = metis_people_workspace_google_request('GET', 'customer/' . rawurlencode($customer) . '/schemas', null, $cfg_schemas);
    if (empty($resp['ok'])) {
        return ['ok' => false, 'error' => 'Failed to fetch Workspace user schemas.'];
    }
    $schemas = (array) ($resp['body']['schemas'] ?? []);
    if (empty($schemas)) return ['ok' => false, 'error' => 'No Workspace custom user schemas found.'];

    $cfg_schema = trim($configured_schema);
    $cfg_field = trim($configured_field);
    $schema_by_name = [];
    foreach ($schemas as $schema) {
        $schema_name = trim((string) ($schema['schemaName'] ?? ''));
        if ($schema_name === '') continue;
        $schema_by_name[$schema_name] = $schema;
    }
    if ($cfg_schema !== '' && isset($schema_by_name[$cfg_schema])) {
        $fields = (array) ($schema_by_name[$cfg_schema]['fields'] ?? []);
        if ($cfg_field !== '') {
            foreach ($fields as $field) {
                $field_name = trim((string) ($field['fieldName'] ?? ''));
                if ($field_name !== '' && strcasecmp($field_name, $cfg_field) === 0) {
                    return ['ok' => true, 'schema' => $cfg_schema, 'field' => $field_name, 'source' => 'schema_api_configured_exact'];
                }
            }
        }
        foreach ($fields as $field) {
            $field_name = trim((string) ($field['fieldName'] ?? ''));
            $field_display = strtolower(trim((string) ($field['displayName'] ?? '')));
            $field_name_l = strtolower($field_name);
            if ($field_name !== '' && str_contains($field_name_l, 'stripe') && str_contains($field_name_l, 'role')) {
                return ['ok' => true, 'schema' => $cfg_schema, 'field' => $field_name, 'source' => 'schema_api_configured_schema_field_match'];
            }
            if (str_contains($field_display, 'stripe') && str_contains($field_display, 'role')) {
                return ['ok' => true, 'schema' => $cfg_schema, 'field' => $field_name, 'source' => 'schema_api_configured_schema_display_match'];
            }
        }
    }

    foreach ($schemas as $schema) {
        $schema_name = trim((string) ($schema['schemaName'] ?? ''));
        if ($schema_name === '') continue;
        $fields = (array) ($schema['fields'] ?? []);
        foreach ($fields as $field) {
            $field_name = trim((string) ($field['fieldName'] ?? ''));
            $field_display = strtolower(trim((string) ($field['displayName'] ?? '')));
            $field_name_l = strtolower($field_name);
            if ($field_name !== '' && str_contains($field_name_l, 'stripe') && str_contains($field_name_l, 'role')) {
                return ['ok' => true, 'schema' => $schema_name, 'field' => $field_name, 'source' => 'schema_api_global_field_match'];
            }
            if (str_contains($field_display, 'stripe') && str_contains($field_display, 'role')) {
                return ['ok' => true, 'schema' => $schema_name, 'field' => $field_name, 'source' => 'schema_api_global_display_match'];
            }
        }
    }
    return ['ok' => false, 'error' => 'Could not locate a Stripe role custom field in Workspace schemas.'];
}

function metis_people_workspace_apply_stripe_sso_role(string $workspace_email, ?string $stripe_role_key, array $cfg): array {
    $workspace_email = strtolower(trim($workspace_email));
    if (!metis_email_is_valid($workspace_email)) {
        return ['ok' => false, 'error' => 'Workspace email is invalid for Stripe SSO sync.'];
    }

    $get_user = metis_people_workspace_google_request('GET', 'users/' . rawurlencode($workspace_email) . '?projection=full', null, $cfg);
    if (empty($get_user['ok'])) {
        return ['ok' => false, 'error' => 'Failed to load Workspace user for Stripe sync.'];
    }
    $user_body = (array) ($get_user['body'] ?? []);
    $custom_schemas = [];
    if (!empty($user_body['customSchemas']) && is_array($user_body['customSchemas'])) {
        $custom_schemas = $user_body['customSchemas'];
    }

    $detected = metis_people_workspace_detect_stripe_custom_field($custom_schemas, $cfg);
    $schema_name = (string) ($detected['schema'] ?? '');
    $field_name = (string) ($detected['field'] ?? '');
    if ($schema_name === '' || $field_name === '') {
        $schema_lookup = metis_people_workspace_discover_schema_field(
            $cfg,
            (string) ($cfg['stripe_sso_schema'] ?? ''),
            (string) ($cfg['stripe_sso_field'] ?? '')
        );
        if (!empty($schema_lookup['ok'])) {
            $schema_name = (string) ($schema_lookup['schema'] ?? '');
            $field_name = (string) ($schema_lookup['field'] ?? '');
            $detected['source'] = (string) ($schema_lookup['source'] ?? 'schema_api');
        }
    }
    if ($schema_name === '' || $field_name === '') {
        $available_schemas = array_keys($custom_schemas);
        return [
            'ok' => false,
            'error' => 'Stripe SSO custom field was not found. Set Workspace Stripe schema/field in Settings.',
            'available_schemas' => $available_schemas,
        ];
    }
    if (!isset($custom_schemas[$schema_name]) || !is_array($custom_schemas[$schema_name])) {
        $custom_schemas[$schema_name] = [];
    }
    if ($stripe_role_key === null || $stripe_role_key === '') {
        $custom_schemas[$schema_name][$field_name] = '';
    } else {
        $custom_schemas[$schema_name][$field_name] = metis_key_clean($stripe_role_key);
    }

    $patch = metis_people_workspace_google_request('PATCH', 'users/' . rawurlencode($workspace_email), [
        'customSchemas' => $custom_schemas,
    ], $cfg);
    if (empty($patch['ok'])) {
        return ['ok' => false, 'error' => 'Failed to update Workspace Stripe role attribute.'];
    }
    return [
        'ok' => true,
        'schema' => $schema_name,
        'field' => $field_name,
        'value' => $stripe_role_key === null ? '' : $stripe_role_key,
        'source' => (string) ($detected['source'] ?? ''),
    ];
}

function metis_people_workspace_set_group_membership(string $group_email, string $member_email, bool $include, array $cfg): array {
    $group_email = strtolower(trim($group_email));
    $member_email = strtolower(trim($member_email));
    if (!metis_email_is_valid($group_email) || !metis_email_is_valid($member_email)) {
        return ['ok' => false, 'error' => 'Invalid group/member email for Workspace group membership update.'];
    }
    if ($include) {
        $payload = ['email' => $member_email, 'role' => 'MEMBER'];
        $create = metis_people_workspace_google_request('POST', 'groups/' . rawurlencode($group_email) . '/members', $payload, $cfg);
        if (!empty($create['ok'])) return ['ok' => true];
        $upsert = metis_people_workspace_google_request('PUT', 'groups/' . rawurlencode($group_email) . '/members/' . rawurlencode($member_email), $payload, $cfg);
        if (!empty($upsert['ok'])) return ['ok' => true];
        return ['ok' => false, 'error' => 'Failed to add member to Stripe access group.'];
    }
    $delete = metis_people_workspace_google_request('DELETE', 'groups/' . rawurlencode($group_email) . '/members/' . rawurlencode($member_email), null, $cfg);
    if (!empty($delete['ok']) || ((int) ($delete['status'] ?? 0) === 404)) {
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'Failed to remove member from Stripe access group.'];
}

function metis_people_workspace_execute_job(array $job, array $cfg, bool $dry_run = false): array {
    $db = metis_db();
    $users_table = Metis_Tables::get('people_workspace_users');
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $members_table = Metis_Tables::get('people_workspace_group_members');
    $actions_table = Metis_Tables::get('people_workspace_security_actions');
    $job_type = (string) ($job['job_type'] ?? '');
    $entity_id = (int) ($job['entity_id'] ?? 0);
    $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
    if (!is_array($payload)) $payload = [];

    if (in_array($job_type, ['stripe_user_upsert', 'stripe_user_disable'], true)) {
        $workspace_email = strtolower(trim((string) ($payload['workspace_email'] ?? '')));
        $stripe_role = metis_key_clean((string) ($payload['stripe_role'] ?? ''));
        $stripe_access_group_email = strtolower(trim((string) ($cfg['stripe_access_group_email'] ?? '')));
        if ($entity_id > 0) {
            $workspace_users_table = Metis_Tables::get('people_workspace_users');
            $linked_workspace_email = strtolower(trim((string) $db->scalar(
                "SELECT primary_email FROM {$workspace_users_table} WHERE person_id = %d ORDER BY id ASC LIMIT 1",
                [ $entity_id ]
            )));
            if (metis_email_is_valid($linked_workspace_email)) {
                $workspace_email = $linked_workspace_email;
            } elseif (!metis_email_is_valid($workspace_email)) {
                $people_table = Metis_Tables::get('people');
                $workspace_email = strtolower(trim((string) $db->scalar(
                    "SELECT workspace_email FROM {$people_table} WHERE id = %d LIMIT 1",
                    [ $entity_id ]
                )));
            }
        }
        if (!metis_email_is_valid($workspace_email)) {
            return ['ok' => true];
        }
        if ($dry_run) {
            return ['ok' => true, 'message' => 'Dry run: would sync Stripe SSO role for ' . $workspace_email];
        }
        if ($job_type === 'stripe_user_disable') {
            $result = metis_people_workspace_apply_stripe_sso_role($workspace_email, null, $cfg);
            if (empty($result['ok'])) {
                return ['ok' => false, 'error' => 'Failed to disable Stripe access in Workspace.'];
            }
            if (metis_email_is_valid($stripe_access_group_email)) {
                $membership = metis_people_workspace_set_group_membership($stripe_access_group_email, $workspace_email, false, $cfg);
                if (empty($membership['ok'])) {
                    return ['ok' => false, 'error' => 'Failed to remove Stripe access group membership.'];
                }
            }
            return ['ok' => true, 'message' => 'Disabled Stripe access via Workspace (role cleared' . (metis_email_is_valid($stripe_access_group_email) ? ', group removed' : '') . ') for ' . $workspace_email];
        }
        if ($stripe_role === '') {
            return ['ok' => true];
        }
        $result = metis_people_workspace_apply_stripe_sso_role($workspace_email, $stripe_role, $cfg);
        if (empty($result['ok'])) {
            return ['ok' => false, 'error' => 'Failed to apply Stripe role in Workspace.'];
        }
        if (metis_email_is_valid($stripe_access_group_email)) {
            $membership = metis_people_workspace_set_group_membership($stripe_access_group_email, $workspace_email, true, $cfg);
            if (empty($membership['ok'])) {
                return ['ok' => false, 'error' => 'Failed to add Stripe access group membership.'];
            }
        }
        return ['ok' => true, 'message' => 'Enabled Stripe access via Workspace (role set' . (metis_email_is_valid($stripe_access_group_email) ? ', group added' : '') . ') for ' . $workspace_email];
    }

    if (in_array($job_type, ['workspace_user_create', 'workspace_user_upsert'], true)) {
        $row = $db->fetchOne("SELECT * FROM {$users_table} WHERE id = %d LIMIT 1", [ $entity_id ]);
        if (!$row) return ['ok' => false, 'error' => 'Workspace user row not found.'];
        $primary_email = strtolower(trim((string) ($row['primary_email'] ?? '')));
        $previous_primary_email = strtolower(trim((string) ($payload['previous_primary_email'] ?? '')));
        $add_alias_email = strtolower(trim((string) ($payload['add_alias_email'] ?? '')));
        if (!metis_email_is_valid($primary_email)) return ['ok' => false, 'error' => 'Workspace user email is invalid.'];
        $user_body = [
            'primaryEmail' => $primary_email,
            'name' => [
                'givenName' => (string) ($row['first_name'] ?? ''),
                'familyName' => (string) ($row['last_name'] ?? ''),
            ],
            'recoveryEmail' => (string) ($row['recovery_email'] ?? ''),
            'orgUnitPath' => (string) ($row['org_unit_path'] ?? '/'),
            'suspended' => !empty($row['is_suspended']),
        ];
        if ($dry_run) return ['ok' => true, 'message' => 'Dry run: would upsert Workspace user ' . $primary_email];
        $lookup_email = metis_email_is_valid($previous_primary_email) ? $previous_primary_email : $primary_email;
        $existing = metis_people_workspace_google_request('GET', 'users/' . rawurlencode($lookup_email), null, $cfg);
        if (empty($existing['ok']) && $lookup_email !== $primary_email) {
            $existing = metis_people_workspace_google_request('GET', 'users/' . rawurlencode($primary_email), null, $cfg);
        }
        if (!empty($existing['ok'])) {
            $user_key = $lookup_email;
            if (empty($existing['body']['primaryEmail']) || strtolower((string) ($existing['body']['primaryEmail'] ?? '')) === $primary_email) {
                $user_key = $primary_email;
            }
            $resp = metis_people_workspace_google_request('PUT', 'users/' . rawurlencode($user_key), $user_body, $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => 'Failed to update workspace user.'];
            $google_id = (string) (($resp['body']['id'] ?? '') ?: ($existing['body']['id'] ?? ''));
            $db->update($users_table, ['workspace_user_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced'], ['id' => $entity_id], ['%s', '%s'], ['%d']);
            if (metis_email_is_valid($add_alias_email) && $add_alias_email !== $primary_email) {
                $alias_resp = metis_people_workspace_google_request(
                    'POST',
                    'users/' . rawurlencode($primary_email) . '/aliases',
                    ['alias' => $add_alias_email],
                    $cfg
                );
                $alias_status = (int) ($alias_resp['status'] ?? 0);
                if (empty($alias_resp['ok']) && !in_array($alias_status, [409, 412], true)) {
                    return ['ok' => false, 'error' => 'Updated user but failed to add old email alias.'];
                }
            }
            return ['ok' => true, 'message' => 'Updated Workspace user ' . $primary_email];
        }
        $user_body['password'] = metis_people_workspace_random_password(20);
        $user_body['changePasswordAtNextLogin'] = true;
        $create = metis_people_workspace_google_request('POST', 'users', $user_body, $cfg);
        if (empty($create['ok'])) return ['ok' => false, 'error' => 'Failed to create workspace user.'];
        $google_id = (string) ($create['body']['id'] ?? '');
        $db->update($users_table, ['workspace_user_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced'], ['id' => $entity_id], ['%s', '%s'], ['%d']);
        return ['ok' => true, 'message' => 'Created Workspace user ' . $primary_email];
    }

    if ($job_type === 'workspace_group_upsert') {
        $row = $db->fetchOne("SELECT * FROM {$groups_table} WHERE id = %d LIMIT 1", [ $entity_id ]);
        if (!$row) return ['ok' => false, 'error' => 'Workspace group row not found.'];
        $group_email = strtolower(trim((string) ($row['group_email'] ?? '')));
        if (!metis_email_is_valid($group_email)) return ['ok' => false, 'error' => 'Workspace group email is invalid.'];
        $group_body = [
            'email' => $group_email,
            'name' => (string) ($row['group_name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
        ];
        if ($dry_run) return ['ok' => true, 'message' => 'Dry run: would upsert Workspace group ' . $group_email];
        $existing = metis_people_workspace_google_request('GET', 'groups/' . rawurlencode($group_email), null, $cfg);
        if (!empty($existing['ok'])) {
            $resp = metis_people_workspace_google_request('PUT', 'groups/' . rawurlencode($group_email), $group_body, $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => 'Failed to update workspace group.'];
            $google_id = (string) (($resp['body']['id'] ?? '') ?: ($existing['body']['id'] ?? ''));
            $db->update($groups_table, ['workspace_group_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced'], ['id' => $entity_id], ['%s', '%s'], ['%d']);
            return ['ok' => true, 'message' => 'Updated Workspace group ' . $group_email];
        }
        $create = metis_people_workspace_google_request('POST', 'groups', $group_body, $cfg);
        if (empty($create['ok'])) return ['ok' => false, 'error' => 'Failed to create workspace group.'];
        $google_id = (string) ($create['body']['id'] ?? '');
        $db->update($groups_table, ['workspace_group_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced'], ['id' => $entity_id], ['%s', '%s'], ['%d']);
        return ['ok' => true, 'message' => 'Created Workspace group ' . $group_email];
    }

    if (in_array($job_type, ['workspace_group_member_upsert', 'workspace_group_members_bulk_sync'], true)) {
        $group_email = strtolower(trim((string) ($payload['group_email'] ?? '')));
        if (!metis_email_is_valid($group_email) && $entity_id > 0) {
            $group_email = (string) $db->scalar("SELECT group_email FROM {$groups_table} WHERE id = %d LIMIT 1", [ $entity_id ]);
            $group_email = strtolower(trim($group_email));
        }
        if (!metis_email_is_valid($group_email)) return ['ok' => false, 'error' => 'Group email not found for membership sync.'];
        $members = [];
        if ($job_type === 'workspace_group_member_upsert') {
            $member_email = strtolower(trim((string) ($payload['member_email'] ?? '')));
            if (!metis_email_is_valid($member_email)) return ['ok' => false, 'error' => 'Member email invalid for group member sync.'];
            $members[] = ['email' => $member_email, 'role' => (string) ($payload['member_role'] ?? 'MEMBER')];
        } else {
            $rows = $db->fetchAll(
                "SELECT wu.primary_email, gm.member_role
                 FROM {$members_table} gm
                 INNER JOIN {$users_table} wu ON wu.id = gm.workspace_user_id
                 WHERE gm.group_id = %d",
                [ $entity_id ]
            );
            foreach ($rows as $row) {
                $member_email = strtolower(trim((string) ($row['primary_email'] ?? '')));
                if (!metis_email_is_valid($member_email)) continue;
                $members[] = ['email' => $member_email, 'role' => (string) ($row['member_role'] ?? 'member')];
            }
        }
        if ($dry_run) return ['ok' => true, 'message' => 'Dry run: would sync ' . count($members) . ' members for ' . $group_email];
        if ($job_type === 'workspace_group_members_bulk_sync') {
            $desired_member_emails = [];
            foreach ($members as $member) {
                $member_email = strtolower(trim((string) ($member['email'] ?? '')));
                if (metis_email_is_valid($member_email)) $desired_member_emails[$member_email] = true;
            }
            $page_token = '';
            $page_guard = 0;
            while ($page_guard < 20) {
                $page_guard++;
                $remote_query = 'groups/' . rawurlencode($group_email) . '/members?maxResults=100';
                if ($page_token !== '') {
                    $remote_query .= '&pageToken=' . rawurlencode($page_token);
                }
                $remote = metis_people_workspace_google_request('GET', $remote_query, null, $cfg);
                if (empty($remote['ok'])) break;
                $remote_members = (array) ($remote['body']['members'] ?? []);
                foreach ($remote_members as $remote_member) {
                    $remote_email = strtolower(trim((string) ($remote_member['email'] ?? '')));
                    $remote_type = strtolower(trim((string) ($remote_member['type'] ?? '')));
                    if (!metis_email_is_valid($remote_email) || $remote_type === 'group') continue;
                    if (isset($desired_member_emails[$remote_email])) continue;
                    metis_people_workspace_google_request(
                        'DELETE',
                        'groups/' . rawurlencode($group_email) . '/members/' . rawurlencode($remote_email),
                        null,
                        $cfg
                    );
                }
                $page_token = trim((string) ($remote['body']['nextPageToken'] ?? ''));
                if ($page_token === '') break;
            }
        }
        foreach ($members as $member) {
            $member_email = strtolower(trim((string) ($member['email'] ?? '')));
            $role = strtoupper((string) ($member['role'] ?? 'MEMBER'));
            if ($role === 'OWNER') $role = 'OWNER';
            elseif ($role === 'MANAGER') $role = 'MANAGER';
            else $role = 'MEMBER';
            $payload_body = ['email' => $member_email, 'role' => $role];
            $create = metis_people_workspace_google_request('POST', 'groups/' . rawurlencode($group_email) . '/members', $payload_body, $cfg);
            if (empty($create['ok'])) {
                $upsert = metis_people_workspace_google_request('PUT', 'groups/' . rawurlencode($group_email) . '/members/' . rawurlencode($member_email), $payload_body, $cfg);
                if (empty($upsert['ok'])) return ['ok' => false, 'error' => 'Failed to sync group member.'];
            }
        }
        if ($entity_id > 0) {
            $db->update($groups_table, ['sync_status' => 'synced'], ['id' => $entity_id], ['%s'], ['%d']);
        }
        return ['ok' => true, 'message' => 'Synced group members for ' . $group_email];
    }

    if ($job_type === 'workspace_security_action') {
        $row = $db->fetchOne("SELECT * FROM {$users_table} WHERE id = %d LIMIT 1", [ $entity_id ]);
        if (!$row) return ['ok' => false, 'error' => 'Workspace user not found for security action.'];
        $user_email = strtolower(trim((string) ($row['primary_email'] ?? '')));
        if (!metis_email_is_valid($user_email)) return ['ok' => false, 'error' => 'Workspace user email invalid for security action.'];
        $action_type = metis_key_clean((string) ($payload['action_type'] ?? ''));
        if ($action_type === '') return ['ok' => false, 'error' => 'Security action type is missing.'];
        if ($dry_run) return ['ok' => true, 'message' => 'Dry run: would run security action ' . $action_type . ' for ' . $user_email];
        if ($action_type === 'reset_password') {
            $resp = metis_people_workspace_google_request('PUT', 'users/' . rawurlencode($user_email), [
                'password' => metis_people_workspace_random_password(20),
                'changePasswordAtNextLogin' => true,
            ], $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => 'Password reset failed.'];
        } elseif ($action_type === 'revoke_sessions') {
            $resp = metis_people_workspace_google_request('POST', 'users/' . rawurlencode($user_email) . '/signOut', [], $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => 'Session revoke failed.'];
        } elseif ($action_type === 'force_2fa_reenroll') {
            $resp = metis_people_workspace_google_request('POST', 'users/' . rawurlencode($user_email) . '/twoStepVerification/turnOff', [], $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => '2FA re-enroll reset failed.'];
        } elseif ($action_type === 'suspend_account' || $action_type === 'unsuspend_account') {
            $suspended = $action_type === 'suspend_account';
            $resp = metis_people_workspace_google_request('PUT', 'users/' . rawurlencode($user_email), ['suspended' => $suspended], $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => 'Suspend/unsuspend failed.'];
            $db->update($users_table, ['is_suspended' => $suspended ? 1 : 0, 'sync_status' => 'synced'], ['id' => $entity_id], ['%d', '%s'], ['%d']);
        } else {
            return ['ok' => false, 'error' => 'Unsupported security action type: ' . $action_type];
        }
        $db->execute($db->prepare(
            "UPDATE {$actions_table}
             SET status = 'completed', completed_at = NOW(), updated_at = NOW()
             WHERE workspace_user_id = %d
               AND action_type = %s
               AND status IN ('pending','queued')
             ORDER BY id DESC
             LIMIT 1",
            $entity_id,
            $action_type
        ));
        return ['ok' => true, 'message' => 'Completed security action ' . $action_type . ' for ' . $user_email];
    }

    return ['ok' => false, 'error' => 'Unsupported job type: ' . $job_type];
}

function metis_people_workspace_process_jobs(int $limit = 10, bool $dry_run = false, int $specific_job_id = 0): array {
    $db = metis_db();
    $jobs_table = Metis_Tables::get('people_workspace_sync_jobs');
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok']) && !$dry_run) {
        return ['processed' => 0, 'completed' => 0, 'failed' => 0, 'error' => 'Workspace configuration missing.'];
    }
    $rows = [];
    if ($specific_job_id > 0) {
        $rows = $db->fetchAll(
            "SELECT * FROM {$jobs_table} WHERE id = %d AND status IN ('queued','failed') LIMIT 1",
            [ $specific_job_id ]
        );
    } else {
        $rows = $db->fetchAll(
            "SELECT * FROM {$jobs_table}
             WHERE status = 'queued'
             ORDER BY created_at ASC, id ASC
             LIMIT %d",
            [ max(1, min(100, $limit)) ]
        );
    }
    $processed = 0;
    $completed = 0;
    $failed = 0;
    $messages = [];
    foreach ($rows as $job) {
        $job_id = (int) ($job['id'] ?? 0);
        if ($job_id < 1) continue;
        $claimed = (int) $db->execute($db->prepare(
            "UPDATE {$jobs_table}
             SET status = 'processing', updated_at = NOW()
             WHERE id = %d AND status IN ('queued','failed')",
            $job_id
        ));
        if ($claimed < 1) continue;
        $processed++;
        $result = metis_people_workspace_execute_job($job, $cfg, $dry_run);
        if (!empty($result['ok'])) {
            $completed++;
            $db->update($jobs_table, [
                'status' => 'completed',
                'last_error' => null,
                'processed_at' => metis_current_time('mysql'),
            ], ['id' => $job_id], ['%s', '%s', '%s'], ['%d']);
            if (!empty($result['message'])) $messages[] = (string) $result['message'];
        } else {
            $failed++;
            $error = isset($result['error']) && is_scalar($result['error']) && trim((string) $result['error']) !== ''
                ? (string) $result['error']
                : 'Workspace sync job failed.';
            $db->update($jobs_table, [
                'status' => 'failed',
                'last_error' => $error,
                'processed_at' => metis_current_time('mysql'),
            ], ['id' => $job_id], ['%s', '%s', '%s'], ['%d']);
            $messages[] = $error;
        }
    }
    return ['processed' => $processed, 'completed' => $completed, 'failed' => $failed, 'messages' => $messages];
}

Metis_Cron_Manager::register_task(
    'people_workspace_sync',
    static function (): array {
        metis_people_ensure_schema();
        return metis_people_workspace_process_jobs( 8, false, 0 );
    },
    [
        'label'    => 'Workspace Sync',
        'interval' => 5 * MINUTE_IN_SECONDS,
        'lock_ttl' => 10 * MINUTE_IN_SECONDS,
        'module'   => 'people',
    ]
);

metis_ajax_register_handler( 'metis_people_get_positions', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $positions_table = Metis_Tables::get( 'people_positions' );
    $rows = $db->fetchAll(
        "SELECT id, group_key, position_key, position_label, sort_order
         FROM {$positions_table}
         WHERE is_active = 1
         ORDER BY group_key ASC, sort_order ASC, position_label ASC"
    ) ?: [];
    $grouped = [
        'board' => [],
        'staff' => [],
        'volunteer' => [],
    ];
    foreach ( $rows as $row ) {
        $group_key = metis_people_normalize_position_group( (string) ( $row['group_key'] ?? '' ) );
        if ( $group_key === '' ) {
            continue;
        }
        $grouped[ $group_key ][] = metis_people_position_payload( (array) $row );
    }
    metis_runtime_send_json_success(
        [
            'positions' => $grouped,
        ],
        200
    );
} );

metis_ajax_register_handler( 'metis_people_save_position', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $positions_table = Metis_Tables::get( 'people_positions' );

    $group_key = isset( $_POST['group_key'] ) ? metis_people_normalize_position_group( (string) metis_runtime_unslash( $_POST['group_key'] ) ) : '';
    $position_label = isset( $_POST['position_label'] ) ? metis_text_clean( (string) metis_runtime_unslash( $_POST['position_label'] ) ) : '';
    $sort_order = isset( $_POST['sort_order'] ) ? (int) metis_runtime_unslash( $_POST['sort_order'] ) : 0;
    if ( $group_key === '' ) {
        metis_runtime_send_json_error( 'Invalid position group.', 400 );
    }
    $position_label = trim( $position_label );
    if ( $position_label === '' ) {
        metis_runtime_send_json_error( 'Position name is required.', 400 );
    }
    $position_key = metis_key_clean( strtolower( str_replace( ' ', '_', $position_label ) ) );
    if ( $position_key === '' ) {
        metis_runtime_send_json_error( 'Position name is invalid.', 400 );
    }

    $existing = $db->fetchOne(
        "SELECT id
         FROM {$positions_table}
         WHERE group_key = %s AND position_key = %s
         LIMIT 1",
        [ $group_key, $position_key ]
    );
    if ( $existing ) {
        $db->update(
            $positions_table,
            [
                'position_label' => $position_label,
                'sort_order' => max( 0, $sort_order ),
                'is_active' => 1,
            ],
            [ 'id' => (int) $existing['id'] ],
            [ '%s', '%d', '%d' ],
            [ '%d' ]
        );
    } else {
        $db->insert(
            $positions_table,
            [
                'group_key' => $group_key,
                'position_key' => $position_key,
                'position_label' => $position_label,
                'sort_order' => max( 0, $sort_order ),
                'is_active' => 1,
            ],
            [ '%s', '%s', '%s', '%d', '%d' ]
        );
    }

    $saved = $db->fetchOne(
        "SELECT id, group_key, position_key, position_label, sort_order
         FROM {$positions_table}
         WHERE group_key = %s AND position_key = %s
         LIMIT 1",
        [ $group_key, $position_key ]
    );
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(
        [
            'position' => $saved ? metis_people_position_payload( (array) $saved ) : null,
        ],
        200
    );
} );

metis_ajax_register_handler( 'metis_people_delete_position', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $positions_table = Metis_Tables::get( 'people_positions' );
    $position_id = isset( $_POST['position_id'] ) ? (int) metis_runtime_unslash( $_POST['position_id'] ) : 0;
    if ( $position_id < 1 ) {
        metis_runtime_send_json_error( 'Position id is required.', 400 );
    }
    $db->update(
        $positions_table,
        [
            'is_active' => 0,
        ],
        [ 'id' => $position_id ],
        [ '%d' ],
        [ '%d' ]
    );
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(
        [
            'position_id' => $position_id,
        ],
        200
    );
} );

metis_ajax_register_handler( 'metis_people_save_person', function () {
    metis_people_ajax_verify();

    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $contacts_table = Metis_Tables::get('contacts');
    $workspace_users_table = Metis_Tables::get('people_workspace_users');
    $workspace_groups_table = Metis_Tables::get('people_workspace_groups');
    $workspace_members_table = Metis_Tables::get('people_workspace_group_members');
    $person_id = isset($_POST['person_id']) ? (int) metis_runtime_unslash($_POST['person_id']) : 0;
    $pid = isset($_POST['pid']) ? trim(metis_text_clean(metis_runtime_unslash($_POST['pid']))) : '';
    $first_name = isset($_POST['first_name']) ? metis_text_clean(metis_runtime_unslash($_POST['first_name'])) : '';
    $last_name = isset($_POST['last_name']) ? metis_text_clean(metis_runtime_unslash($_POST['last_name'])) : '';
    $display_name = isset($_POST['display_name']) ? metis_text_clean(metis_runtime_unslash($_POST['display_name'])) : '';
    $email = isset($_POST['email']) ? metis_email_clean(metis_runtime_unslash($_POST['email'])) : '';
    $auth_provider = isset($_POST['auth_provider']) ? metis_key_clean(metis_runtime_unslash($_POST['auth_provider'])) : 'metis';
    $is_workspace_user = !empty($_POST['is_workspace_user']) ? 1 : 0;
    $workspace_email = isset($_POST['workspace_email']) ? metis_email_clean(metis_runtime_unslash($_POST['workspace_email'])) : '';
    $workspace_role = isset($_POST['workspace_role']) ? metis_key_clean(metis_runtime_unslash($_POST['workspace_role'])) : '';
    $workspace_is_protected = !empty($_POST['workspace_is_protected']) ? 1 : 0;
    $workspace_groups_json = isset($_POST['workspace_groups_json']) ? (string) metis_runtime_unslash($_POST['workspace_groups_json']) : '[]';
    $stripe_role = isset($_POST['stripe_role']) ? metis_key_clean(metis_runtime_unslash($_POST['stripe_role'])) : '';
    $linked_donor_id_raw = isset($_POST['linked_donor_id']) ? metis_text_clean(metis_runtime_unslash($_POST['linked_donor_id'])) : '';
    $manager_pid = isset($_POST['manager_pid']) ? metis_text_clean(metis_runtime_unslash($_POST['manager_pid'])) : '';
    $department = isset($_POST['department']) ? metis_text_clean(metis_runtime_unslash($_POST['department'])) : '';
    $board_term_start = isset($_POST['board_term_start']) ? metis_text_clean(metis_runtime_unslash($_POST['board_term_start'])) : '';
    $board_term_end = isset($_POST['board_term_end']) ? metis_text_clean(metis_runtime_unslash($_POST['board_term_end'])) : '';
    $volunteer_area = isset($_POST['volunteer_area']) ? metis_text_clean(metis_runtime_unslash($_POST['volunteer_area'])) : '';
    $lifecycle_status = isset($_POST['lifecycle_status']) ? metis_key_clean(metis_runtime_unslash($_POST['lifecycle_status'])) : 'active';
    $email_notifications = isset($_POST['email_notifications']) ? (!empty($_POST['email_notifications']) ? 1 : 0) : 1;
    $sms_notifications = 0;
    $requires_2fa = !empty($_POST['requires_2fa']) ? 1 : 0;
    $mfa_method = isset($_POST['mfa_method']) ? metis_key_clean(metis_runtime_unslash($_POST['mfa_method'])) : 'none';
    $is_staff = !empty($_POST['is_staff']) ? 1 : 0;
    $is_board = !empty($_POST['is_board']) ? 1 : 0;
    $board_position = isset($_POST['board_position']) ? metis_text_clean(metis_runtime_unslash($_POST['board_position'])) : '';
    $staff_position = isset($_POST['staff_position']) ? metis_text_clean(metis_runtime_unslash($_POST['staff_position'])) : '';
    $volunteer_position = isset($_POST['volunteer_position']) ? metis_text_clean(metis_runtime_unslash($_POST['volunteer_position'])) : '';
    $is_volunteer = !empty($_POST['is_volunteer']) ? 1 : 0;
    $status = isset($_POST['status']) ? metis_key_clean(metis_runtime_unslash($_POST['status'])) : 'active';
    $current_person = null;
    if ($person_id > 0 || $pid !== '') {
        $resolved_person = metis_people_resolve_person_record($person_id, $pid);
        if (empty($resolved_person['ok'])) {
            $status = (int) ($resolved_person['status'] ?? 404);
            $status = in_array($status, [400, 401, 403, 404, 409, 422, 429], true) ? $status : 404;
            metis_runtime_send_json_error('Person record was not found.', $status);
        }
        $current_person = (array) ($resolved_person['person'] ?? []);
        $person_id = (int) ($current_person['id'] ?? 0);
    }
    $roles = [];
    if (isset($_POST['roles'])) {
        $decoded_roles = json_decode((string) metis_runtime_unslash($_POST['roles']), true);
        if (is_array($decoded_roles)) {
            foreach ($decoded_roles as $role_key) {
                $rk = metis_key_clean((string) $role_key);
                if ($rk !== '') $roles[] = $rk;
            }
        }
    }
    $roles = array_values(array_unique($roles));
    $workspace_group_emails = [];
    $decoded_workspace_groups = json_decode($workspace_groups_json, true);
    if (is_array($decoded_workspace_groups)) {
        foreach ($decoded_workspace_groups as $workspace_group_value) {
            $workspace_group_email = strtolower(trim((string) $workspace_group_value));
            if (!metis_email_is_valid($workspace_group_email)) continue;
            $workspace_group_emails[] = $workspace_group_email;
        }
    }
    $workspace_group_emails = array_values(array_unique($workspace_group_emails));
    $role_windows = [];
    if (isset($_POST['role_windows'])) {
        $decoded_windows = json_decode((string) metis_runtime_unslash($_POST['role_windows']), true);
        if (is_array($decoded_windows)) {
            foreach ($decoded_windows as $role_key => $window) {
                $rk = metis_key_clean((string) $role_key);
                if ($rk === '' || !is_array($window)) continue;
                $start_at = isset($window['start_at']) ? metis_text_clean((string) $window['start_at']) : '';
                $end_at = isset($window['end_at']) ? metis_text_clean((string) $window['end_at']) : '';
                if ($start_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $start_at)) {
                    $start_at = '';
                }
                if ($end_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $end_at)) {
                    $end_at = '';
                }
                if ($start_at !== '' && strlen($start_at) === 16) $start_at .= ':00';
                if ($end_at !== '' && strlen($end_at) === 16) $end_at .= ':00';
                $start_at = str_replace('T', ' ', $start_at);
                $end_at = str_replace('T', ' ', $end_at);
                $role_windows[$rk] = ['start_at' => $start_at, 'end_at' => $end_at];
            }
        }
    }
    $notification_prefs_json = null;
    if (isset($_POST['notification_prefs_json'])) {
        $decoded_notify = json_decode((string) metis_runtime_unslash($_POST['notification_prefs_json']), true);
        if (is_array($decoded_notify)) {
            $clean_notify = [];
            foreach ($decoded_notify as $event_key => $channels) {
                $ek = metis_key_clean((string) $event_key);
                if ($ek === '' || !is_array($channels)) continue;
                $clean_notify[$ek] = [
                    'email' => !empty($channels['email']),
                    'in_app' => !empty($channels['in_app']),
                ];
            }
            $notification_prefs_json = metis_json_encode($clean_notify);
        }
    }

    $email = strtolower(trim($email));
    $workspace_email = strtolower(trim($workspace_email));
    $linked_donor_id = '';
    if ($linked_donor_id_raw !== '') {
        if (preg_match('/\\b(MW[A-Z0-9]+)\\b/i', $linked_donor_id_raw, $m)) {
            $linked_donor_id = strtoupper((string) $m[1]);
        } else {
            $linked_donor_id = strtoupper(trim($linked_donor_id_raw));
        }
    }
    $manager_pid = strtoupper(trim($manager_pid));

    if ($display_name === '') {
        $display_name = trim($first_name . ' ' . $last_name);
    }
    if ($is_workspace_user === 1) {
        if ($workspace_email === '' && metis_email_is_valid($email)) {
            $workspace_email = $email;
        }
        if (metis_email_is_valid($workspace_email)) {
            $email = $workspace_email;
        }
    }
    if ($display_name === '' || !metis_email_is_valid($email)) {
        metis_runtime_send_json_error('First/last or display name and valid email are required.', 400);
    }
    if (!in_array($auth_provider, ['workspace', 'metis'], true)) {
        $auth_provider = 'metis';
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }
    if (!in_array($lifecycle_status, ['candidate', 'active', 'leave', 'alumni'], true)) {
        $lifecycle_status = 'active';
    }
    if (!in_array($mfa_method, ['none', 'totp', 'passkey', 'passkey_or_totp', 'passkey_and_totp'], true)) {
        $mfa_method = 'none';
    }
    if ($board_term_start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $board_term_start)) {
        $board_term_start = '';
    }
    if ($board_term_end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $board_term_end)) {
        $board_term_end = '';
    }
    if ($is_workspace_user && !metis_email_is_valid($workspace_email)) {
        metis_runtime_send_json_error('Workspace users require a valid Google Workspace email.', 400);
    }
    if ($is_workspace_user === 0) {
        $workspace_group_emails = [];
    }
    if ($linked_donor_id !== '') {
        $donor_exists = (int) $db->scalar(
            "SELECT id FROM {$contacts_table} WHERE did = %s LIMIT 1",
            [ $linked_donor_id ]
        );
        if ($donor_exists < 1) {
            metis_runtime_send_json_error('Linked donor ID was not found in Contacts.', 400);
        }
        $donor_conflict = (int) $db->scalar(
            "SELECT id FROM {$people_table} WHERE linked_donor_id = %s AND id <> %d LIMIT 1",
            [ $linked_donor_id, $person_id ]
        );
        if ($donor_conflict > 0) {
            metis_runtime_send_json_error('That donor is already linked to another person profile.', 400);
        }
    }
    if ($manager_pid !== '') {
        $manager_person_id = (int) $db->scalar(
            "SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1",
            [ $manager_pid ]
        );
        if ($manager_person_id < 1) {
            metis_runtime_send_json_error('Manager PID was not found.', 400);
        }
        if ($person_id > 0 && $manager_person_id === $person_id) {
            metis_runtime_send_json_error('A person cannot be their own manager.', 400);
        }
    }
    if ($workspace_role !== '') {
        $valid_workspace_role = (int) $db->scalar(
            "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'workspace' LIMIT 1",
            [ $workspace_role ]
        );
        if ($valid_workspace_role < 1) {
            metis_runtime_send_json_error('Invalid Google Workspace role selected.', 400);
        }
    }
    if ($stripe_role !== '') {
        $valid_stripe_role = (int) $db->scalar(
            "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'stripe' LIMIT 1",
            [ $stripe_role ]
        );
        if ($valid_stripe_role < 1) {
            metis_runtime_send_json_error('Invalid Stripe role selected.', 400);
        }
    }

    $email_conflict = (int) $db->scalar(
        "SELECT id FROM {$people_table} WHERE email = %s AND id <> %d LIMIT 1",
        [ $email, $person_id ]
    );
    if ($email_conflict > 0) {
        metis_runtime_send_json_error('Email already exists in People.', 400);
    }
    if ($person_id > 0 && $is_workspace_user === 0) {
        $protected_workspace_user_id = (int) $db->scalar(
            "SELECT id
             FROM {$workspace_users_table}
             WHERE person_id = %d
               AND is_protected = 1
             LIMIT 1",
            [ $person_id ]
        );
        if ($protected_workspace_user_id > 0) {
            metis_runtime_send_json_error('This profile is linked to a protected Workspace account and cannot be removed from Workspace.', 400);
        }
    }

    $payload = [
        'auth_provider' => $auth_provider,
        'email' => $email,
        'first_name' => $first_name !== '' ? $first_name : null,
        'last_name' => $last_name !== '' ? $last_name : null,
        'display_name' => $display_name,
        'linked_donor_id' => $linked_donor_id !== '' ? strtoupper($linked_donor_id) : null,
        'is_workspace_user' => $is_workspace_user,
        'workspace_email' => $workspace_email !== '' ? $workspace_email : null,
        'workspace_role' => $workspace_role !== '' ? $workspace_role : null,
        'stripe_role' => $stripe_role !== '' ? $stripe_role : null,
        'manager_pid' => $manager_pid !== '' ? $manager_pid : null,
        'department' => $department !== '' ? $department : null,
        'board_term_start' => $board_term_start !== '' ? $board_term_start : null,
        'board_term_end' => $board_term_end !== '' ? $board_term_end : null,
        'volunteer_area' => $volunteer_area !== '' ? $volunteer_area : null,
        'lifecycle_status' => $lifecycle_status,
        'email_notifications' => $email_notifications,
        'sms_notifications' => $sms_notifications,
        'notification_prefs_json' => $notification_prefs_json,
        'requires_2fa' => $requires_2fa,
        'mfa_method' => $mfa_method,
        'is_staff' => $is_staff,
        'is_board' => $is_board,
        'board_position' => ($is_board === 1 && trim($board_position) !== '') ? trim($board_position) : null,
        'staff_position' => ($is_staff === 1 && trim($staff_position) !== '') ? trim($staff_position) : null,
        'is_volunteer' => $is_volunteer,
        'volunteer_position' => ($is_volunteer === 1 && trim($volunteer_position) !== '') ? trim($volunteer_position) : null,
        'status' => $status,
        'offboarded_at' => ($status === 'inactive' || $lifecycle_status === 'alumni') ? metis_current_time('mysql') : null,
    ];
    $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s'];

    $previous_person = null;
    $previous_workspace_email = '';
    if ($person_id > 0) {
        $previous_person = $db->fetchOne(
            "SELECT id, pid, is_workspace_user, workspace_email, stripe_role, status, lifecycle_status FROM {$people_table} WHERE id = %d LIMIT 1",
            [ $person_id ]
        );
        $previous_workspace_email = strtolower(trim((string) ($previous_person['workspace_email'] ?? '')));
        if (!metis_email_is_valid($previous_workspace_email)) {
            $previous_workspace_email = strtolower(trim((string) $db->scalar(
                "SELECT primary_email FROM {$workspace_users_table} WHERE person_id = %d ORDER BY id ASC LIMIT 1",
                [ $person_id ]
            )));
        }
        $ok = $db->update($people_table, $payload, ['id' => $person_id], $format, ['%d']);
        if ($ok === false) {
            metis_runtime_send_json_error('Failed to update person.', 500);
        }
    } else {
        if ( function_exists( 'metis_entity_id_service' ) ) {
            $payload = metis_entity_id_service()->assignForInsert( 'person', $payload );
            $format[] = '%s';
        } else {
            $payload['pid'] = metis_generate_code('PE', $people_table, 'pid');
        }
        $format[] = '%s';
        $ok = $db->insert($people_table, $payload, $format);
        if (!$ok) {
            metis_runtime_send_json_error('Failed to create person.', 500);
        }
        $person_id = (int) $db->lastInsertId();
        if ( $person_id > 0 && function_exists( 'metis_entity_id_service' ) ) {
            metis_entity_id_service()->register( 'person', $person_id, (string) ( $payload['person_uid'] ?? $payload['pid'] ?? '' ) );
        }
    }

    $db->delete($user_roles_table, ['person_id' => $person_id], ['%d']);

    if (!empty($roles)) {
        foreach ($roles as $role_key) {
            $role_id = (int) $db->scalar("SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1", [ $role_key ]);
            if ($role_id < 1) continue;
            $window = $role_windows[$role_key] ?? ['start_at' => '', 'end_at' => ''];
            $db->insert(
                $user_roles_table,
                [
                    'person_id' => $person_id,
                    'role_id' => $role_id,
                    'start_at' => $window['start_at'] !== '' ? $window['start_at'] : null,
                    'end_at' => $window['end_at'] !== '' ? $window['end_at'] : null,
                ],
                ['%d', '%d', '%s', '%s']
            );
        }
    }

    $person_pid = (string) $db->scalar( "SELECT pid FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ] );
    $actor = metis_people_get_current_person_id();
    $stripe_payload = [
        'person_id' => $person_id,
        'pid' => $person_pid,
        'workspace_email' => $workspace_email,
        'stripe_role' => $stripe_role,
    ];
    $can_stripe_provision = ($is_workspace_user === 1 && $workspace_email !== '' && $status === 'active' && $lifecycle_status !== 'alumni');
    $had_stripe_before = !empty($previous_person['stripe_role']);
    if ($can_stripe_provision && $stripe_role !== '') {
        metis_people_workspace_queue_job(
            'stripe_user_upsert',
            'person',
            $person_id,
            $actor > 0 ? $actor : null,
            $stripe_payload
        );
    } elseif ($had_stripe_before || $stripe_role === '' || !$can_stripe_provision) {
        metis_people_workspace_queue_job(
            'stripe_user_disable',
            'person',
            $person_id,
            $actor > 0 ? $actor : null,
            array_merge($stripe_payload, [
                'reason' => $status !== 'active' || $lifecycle_status === 'alumni' ? 'person_inactive' : 'role_or_workspace_removed',
            ])
        );
    }

    $linked_workspace_user_id = 0;
    if ($is_workspace_user === 1 && $workspace_email !== '') {
        $linked_workspace_user_id = (int) $db->scalar(
            "SELECT id FROM {$workspace_users_table} WHERE person_id = %d OR primary_email = %s LIMIT 1",
            [ $person_id, $workspace_email ]
        );
        if ($linked_workspace_user_id > 0) {
            $db->update(
                $workspace_users_table,
                ['person_id' => $person_id, 'is_protected' => $workspace_is_protected, 'sync_status' => 'queued'],
                ['id' => $linked_workspace_user_id],
                ['%d', '%d', '%s'],
                ['%d']
            );
            metis_people_workspace_queue_job(
                'workspace_user_upsert',
                'workspace_user',
                $linked_workspace_user_id,
                $actor > 0 ? $actor : null,
                [
                    'person_id' => $person_id,
                    'workspace_email' => $workspace_email,
                    'workspace_is_protected' => $workspace_is_protected,
                    'workspace_role' => $workspace_role,
                    'stripe_role' => $stripe_role,
                    'previous_primary_email' => $previous_workspace_email,
                    'add_alias_email' => ($previous_workspace_email !== '' && $previous_workspace_email !== $workspace_email) ? $previous_workspace_email : '',
                ]
            );
        }
    }

    if ($linked_workspace_user_id > 0) {
        $available_group_ids_by_email = [];
        if (!empty($workspace_group_emails)) {
            $placeholders = implode(',', array_fill(0, count($workspace_group_emails), '%s'));
            $group_rows = $db->fetchAll(
                "SELECT id, group_email
                 FROM {$workspace_groups_table}
                 WHERE group_email IN ({$placeholders})",
                $workspace_group_emails
            );
            foreach ($group_rows as $group_row) {
                $group_id = (int) ($group_row['id'] ?? 0);
                $group_email = strtolower(trim((string) ($group_row['group_email'] ?? '')));
                if ($group_id < 1 || !metis_email_is_valid($group_email)) continue;
                $available_group_ids_by_email[$group_email] = $group_id;
            }
        }

        $desired_group_ids = array_values(array_unique(array_map('intval', array_values($available_group_ids_by_email))));
        $existing_group_ids = $db->column(
            "SELECT group_id
             FROM {$workspace_members_table}
             WHERE workspace_user_id = %d",
            [ $linked_workspace_user_id ]
        );
        $existing_group_ids = array_values(array_unique(array_map('intval', $existing_group_ids)));

        $to_add = array_values(array_diff($desired_group_ids, $existing_group_ids));
        $to_remove = array_values(array_diff($existing_group_ids, $desired_group_ids));
        $touched_group_ids = [];

        foreach ($to_add as $group_id) {
            if ($group_id < 1) continue;
            $ok = $db->insert(
                $workspace_members_table,
                [
                    'group_id' => $group_id,
                    'workspace_user_id' => $linked_workspace_user_id,
                    'member_role' => 'member',
                ],
                ['%d', '%d', '%s']
            );
            if ($ok) $touched_group_ids[$group_id] = true;
        }
        foreach ($to_remove as $group_id) {
            if ($group_id < 1) continue;
            $deleted = $db->delete(
                $workspace_members_table,
                ['group_id' => $group_id, 'workspace_user_id' => $linked_workspace_user_id],
                ['%d', '%d']
            );
            if ($deleted !== false) $touched_group_ids[$group_id] = true;
        }

        if (!empty($touched_group_ids)) {
            $actor_for_groups = $actor > 0 ? $actor : null;
            foreach (array_keys($touched_group_ids) as $group_id) {
                $group_id = (int) $group_id;
                if ($group_id < 1) continue;
                $db->execute($db->prepare(
                    "UPDATE {$workspace_groups_table}
                     SET direct_members_count = (SELECT COUNT(*) FROM {$workspace_members_table} WHERE group_id = %d),
                         sync_status = 'queued'
                     WHERE id = %d",
                    $group_id,
                    $group_id
                ));
                $group_email = strtolower(trim((string) $db->scalar(
                    "SELECT group_email FROM {$workspace_groups_table} WHERE id = %d LIMIT 1",
                    [ $group_id ]
                )));
                metis_people_workspace_queue_job(
                    'workspace_group_members_bulk_sync',
                    'workspace_group',
                    $group_id,
                    $actor_for_groups,
                    [
                        'group_email' => $group_email,
                        'member_count' => (int) $db->scalar(
                            "SELECT COUNT(*) FROM {$workspace_members_table} WHERE group_id = %d",
                            [ $group_id ]
                        ),
                    ]
                );
            }
        }
    }

    $drive_folder = null;
    if ($is_workspace_user === 1 && $workspace_email !== '') {
        $drive_folder = metis_people_autocreate_drive_folder_for_person($person_id, $person_pid);
    }

    metis_people_log_activity($person_id, 'person_saved', 'Updated person profile', [
        'pid' => $person_pid,
        'status' => $status,
        'lifecycle_status' => $lifecycle_status,
    ]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'person_id' => $person_id,
        'pid' => $person_pid,
        'workspace_groups_count' => count($workspace_group_emails),
        'drive_folder' => $drive_folder,
    ]);
});


metis_ajax_register_handler( 'metis_people_save_avatar', function () {
    metis_people_ajax_verify();
    $people_table = Metis_Tables::get('people');
    $person_id = isset($_POST['person_id']) ? (int) metis_runtime_unslash($_POST['person_id']) : 0;
    $pid = isset($_POST['pid']) ? trim(metis_text_clean(metis_runtime_unslash($_POST['pid']))) : '';
    $base64 = isset($_POST['avatar_base64']) ? (string) metis_runtime_unslash($_POST['avatar_base64']) : '';
    if (($person_id < 1 && $pid === '') || $base64 === '') {
        metis_runtime_send_json_error('Image data is required.', 400);
    }
    $person = metis_people_resolve_person_record($person_id, $pid);
    $person_id = (int) ($person['id'] ?? 0);
    $pid = (string) ($person['pid'] ?? $pid);
    if ($person_id < 1 || $pid === '') {
        metis_runtime_send_json_error('Person not found.', 404);
    }
    $decoded = metis_avatar_decode_base64_payload($base64);
    if (empty($decoded['ok'])) {
        metis_runtime_send_json_error('Invalid image payload.', 400);
    }
    $upload = metis_avatar_store_cropped_image($pid, (string) ($decoded['binary'] ?? ''));
    if (empty($upload['ok'])) {
        metis_runtime_send_json_error('Failed to store image.', 500);
    }
    $url = isset($upload['url']) ? metis_url_clean((string) ($upload['url'] ?? '')) : '';
    if ($url === '') {
        metis_runtime_send_json_error('Image URL not available.', 500);
    }
    metis_db()->update($people_table, ['avatar_url' => $url], ['id' => $person_id], ['%s'], ['%d']);
    metis_people_log_activity($person_id, 'avatar_updated', 'Updated profile photo', []);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['avatar_url' => $url]);
});

metis_ajax_register_handler( 'metis_people_offboard_person', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $workspace_users_table = Metis_Tables::get('people_workspace_users');

    $pid = isset($_POST['pid']) ? metis_text_clean(metis_runtime_unslash($_POST['pid'])) : '';
    if ($pid === '') {
        metis_runtime_send_json_error('PID is required.', 400);
    }
    $person = $db->fetchOne("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ]);
    if (!$person) {
        metis_runtime_send_json_error('Person not found.', 404);
    }
    $person_id = (int) $person['id'];
    $person_before = $db->fetchOne(
        "SELECT id, pid, workspace_email, stripe_role FROM {$people_table} WHERE id = %d LIMIT 1",
        [ $person_id ]
    );
    $protected_workspace_user_id = (int) $db->scalar(
        "SELECT id
         FROM {$workspace_users_table}
         WHERE person_id = %d
           AND is_protected = 1
         LIMIT 1",
        [ $person_id ]
    );
    if ($protected_workspace_user_id > 0) {
        metis_runtime_send_json_error('This person has a protected Workspace account and cannot be offboarded from Metis.', 400);
    }
    $db->update(
        $people_table,
        [
            'status' => 'inactive',
            'lifecycle_status' => 'alumni',
            'is_workspace_user' => 0,
            'workspace_email' => null,
            'workspace_role' => null,
            'stripe_role' => null,
            'offboarded_at' => metis_current_time('mysql'),
        ],
        ['id' => $person_id],
        ['%s', '%s', '%d', '%s', '%s', '%s', '%s'],
        ['%d']
    );
    $db->delete($user_roles_table, ['person_id' => $person_id], ['%d']);
    $actor = metis_people_get_current_person_id();
    $workspace_email = strtolower(trim((string) ($person_before['workspace_email'] ?? '')));
    if (metis_email_is_valid($workspace_email)) {
        $workspace_user_id = (int) $db->scalar(
            "SELECT id FROM {$workspace_users_table} WHERE person_id = %d OR primary_email = %s LIMIT 1",
            [ $person_id, $workspace_email ]
        );
        if ($workspace_user_id > 0) {
            $db->update($workspace_users_table, ['is_suspended' => 1, 'sync_status' => 'queued'], ['id' => $workspace_user_id], ['%d', '%s'], ['%d']);
            metis_people_workspace_queue_job(
                'workspace_security_action',
                'workspace_user',
                $workspace_user_id,
                $actor > 0 ? $actor : null,
                ['action_type' => 'suspend_account', 'reason' => 'person_offboarded']
            );
        }
    }
    metis_people_workspace_queue_job(
        'stripe_user_disable',
        'person',
        $person_id,
        $actor > 0 ? $actor : null,
        [
            'person_id' => $person_id,
            'pid' => (string) ($person_before['pid'] ?? $pid),
            'workspace_email' => $workspace_email,
            'stripe_role' => (string) ($person_before['stripe_role'] ?? ''),
            'reason' => 'person_offboarded',
        ]
    );
    metis_people_log_activity($person_id, 'offboarded', 'Ran offboarding checklist', ['pid' => $pid]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['pid' => $pid]);
});

metis_ajax_register_handler( 'metis_people_add_document', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $documents_table = Metis_Tables::get('people_documents');
    $pid = isset($_POST['pid']) ? metis_text_clean(metis_runtime_unslash($_POST['pid'])) : '';
    $doc_type = isset($_POST['doc_type']) ? metis_key_clean(metis_runtime_unslash($_POST['doc_type'])) : '';
    $doc_label = isset($_POST['doc_label']) ? metis_text_clean(metis_runtime_unslash($_POST['doc_label'])) : '';
    $storage_ref = isset($_POST['storage_ref']) ? metis_text_clean(metis_runtime_unslash($_POST['storage_ref'])) : '';
    $remind_at = isset($_POST['remind_at']) ? metis_text_clean(metis_runtime_unslash($_POST['remind_at'])) : '';
    $expires_at = isset($_POST['expires_at']) ? metis_text_clean(metis_runtime_unslash($_POST['expires_at'])) : '';
    if ($pid === '' || $doc_type === '' || $doc_label === '') {
        metis_runtime_send_json_error('PID, document type, and label are required.', 400);
    }
    if ($remind_at !== '' && strlen($remind_at) === 16) $remind_at .= ':00';
    if ($expires_at !== '' && strlen($expires_at) === 16) $expires_at .= ':00';
    $remind_at = str_replace('T', ' ', $remind_at);
    $expires_at = str_replace('T', ' ', $expires_at);
    if ($remind_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $remind_at)) {
        $remind_at = '';
    }
    if ($expires_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires_at)) {
        $expires_at = '';
    }
    $person_id = (int) $db->scalar("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", [ $pid ]);
    if ($person_id < 1) metis_runtime_send_json_error('Person not found.', 404);
    $actor = metis_people_get_current_person_id();
    $lifecycle_status = ($expires_at !== '' && strtotime($expires_at) < time()) ? 'expired' : 'active';
    $db->insert($documents_table, [
        'person_id' => $person_id,
        'doc_type' => $doc_type,
        'doc_label' => $doc_label,
        'storage_ref' => $storage_ref !== '' ? $storage_ref : null,
        'remind_at' => $remind_at !== '' ? $remind_at : null,
        'expires_at' => $expires_at !== '' ? $expires_at : null,
        'lifecycle_status' => $lifecycle_status,
        'created_by_person_id' => $actor > 0 ? $actor : null,
    ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d']);
    metis_people_log_activity($person_id, 'document_added', 'Added document reference', ['doc_type' => $doc_type, 'doc_label' => $doc_label]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'ok' => 1,
        'doc_id' => (int) $db->lastInsertId(),
        'row' => [
            'doc_type' => $doc_type,
            'doc_label' => $doc_label,
            'storage_ref' => $storage_ref,
            'remind_at' => $remind_at,
            'expires_at' => $expires_at,
            'lifecycle_status' => $lifecycle_status,
        ],
    ]);
});

metis_ajax_register_handler( 'metis_people_grant_emergency_access', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');
    $emergency_table = Metis_Tables::get('people_emergency_access');

    $pid = isset($_POST['pid']) ? metis_text_clean(metis_runtime_unslash($_POST['pid'])) : '';
    $role_key = isset($_POST['role_key']) ? metis_key_clean(metis_runtime_unslash($_POST['role_key'])) : '';
    $hours = isset($_POST['hours']) ? (int) metis_runtime_unslash($_POST['hours']) : 4;
    $reason = isset($_POST['reason']) ? sanitize_textarea_field(metis_runtime_unslash($_POST['reason'])) : '';
    if ($pid === '' || $role_key === '') {
        metis_runtime_send_json_error('PID and role key are required.', 400);
    }
    if ($hours < 1) $hours = 1;
    if ($hours > 72) $hours = 72;
    $person_id = (int) $db->scalar("SELECT id FROM {$people_table} WHERE pid=%s LIMIT 1", [ $pid ]);
    $role_id = (int) $db->scalar("SELECT id FROM {$roles_table} WHERE role_key=%s AND role_domain='metis' LIMIT 1", [ $role_key ]);
    if ($person_id < 1 || $role_id < 1) {
        metis_runtime_send_json_error('Invalid PID or role key.', 400);
    }
    $actor = metis_people_get_current_person_id();
    $starts = metis_current_time('mysql');
    $ends = gmdate('Y-m-d H:i:s', strtotime($starts . ' +' . $hours . ' hours'));
    $db->insert($emergency_table, [
        'person_id' => $person_id,
        'granted_role_id' => $role_id,
        'reason' => $reason !== '' ? $reason : null,
        'granted_by_person_id' => $actor > 0 ? $actor : null,
        'starts_at' => $starts,
        'ends_at' => $ends,
    ], ['%d', '%d', '%s', '%d', '%s', '%s']);

    // Ensure role assignment exists for emergency window.
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $existing = (int) $db->scalar("SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1", [ $person_id, $role_id ]);
    if ($existing < 1) {
        $db->insert($user_roles_table, [
            'person_id' => $person_id,
            'role_id' => $role_id,
            'start_at' => $starts,
            'end_at' => $ends,
        ], ['%d', '%d', '%s', '%s']);
    }
    metis_people_log_activity($person_id, 'emergency_access_granted', 'Granted emergency access', ['role_key' => $role_key, 'hours' => $hours]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['ok' => 1]);
});

metis_ajax_register_handler( 'metis_people_delete_document', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $documents_table = Metis_Tables::get('people_documents');
    $doc_id = isset($_POST['doc_id']) ? (int) metis_runtime_unslash($_POST['doc_id']) : 0;
    if ($doc_id < 1) {
        metis_runtime_send_json_error('Invalid document id.', 400);
    }
    $doc = $db->fetchOne("SELECT id, person_id, doc_label FROM {$documents_table} WHERE id = %d LIMIT 1", [ $doc_id ]);
    if (!$doc) {
        metis_runtime_send_json_error('Document not found.', 404);
    }
    $db->delete($documents_table, ['id' => $doc_id], ['%d']);
    metis_people_log_activity((int) $doc['person_id'], 'document_deleted', 'Deleted document reference', ['doc_label' => (string) ($doc['doc_label'] ?? '')]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['ok' => 1]);
});

metis_ajax_register_handler( 'metis_people_revoke_emergency_access', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $emergency_table = Metis_Tables::get('people_emergency_access');
    $entry_id = isset($_POST['entry_id']) ? (int) metis_runtime_unslash($_POST['entry_id']) : 0;
    if ($entry_id < 1) {
        metis_runtime_send_json_error('Invalid emergency entry id.', 400);
    }
    $entry = $db->fetchOne("SELECT id, person_id, revoked_at FROM {$emergency_table} WHERE id = %d LIMIT 1", [ $entry_id ]);
    if (!$entry) {
        metis_runtime_send_json_error('Emergency entry not found.', 404);
    }
    if (!empty($entry['revoked_at'])) {
        metis_runtime_send_json_error('Entry already revoked.', 400);
    }
    $db->update($emergency_table, ['revoked_at' => metis_current_time('mysql')], ['id' => $entry_id], ['%s'], ['%d']);
    metis_people_log_activity((int) $entry['person_id'], 'emergency_access_revoked', 'Revoked emergency access', ['entry_id' => $entry_id]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['ok' => 1]);
});




metis_ajax_register_handler( 'metis_people_search_person', function () {
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    if (!$people_table) {
        metis_runtime_send_json_success(['people' => []]);
    }

    $q = isset($_POST['q']) ? metis_text_clean(metis_runtime_unslash($_POST['q'])) : '';
    $q = trim($q);
    if ($q === '') {
        metis_runtime_send_json_success(['people' => []]);
    }

    $like = '%' . $db->escapeLike($q) . '%';
    $rows = $db->fetchAll(
        "SELECT pid, first_name, last_name, display_name, email
         FROM {$people_table}
         WHERE pid LIKE %s
            OR first_name LIKE %s
            OR last_name LIKE %s
            OR display_name LIKE %s
            OR email LIKE %s
         ORDER BY first_name ASC, last_name ASC, display_name ASC, email ASC
         LIMIT 12",
        [ $like, $like, $like, $like, $like ]
    ) ?: [];

    $people = [];
    foreach ($rows as $row) {
        $pid = trim((string) ($row['pid'] ?? ''));
        if ($pid === '') continue;
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['display_name'] ?? ''));
        }
        if ($name === '') {
            $name = (string) ($row['email'] ?? $pid);
        }
        $email = trim((string) ($row['email'] ?? ''));
        $label = $name . ' (' . $pid . ')';
        if ($email !== '') {
            $label .= ' - ' . $email;
        }
        $people[] = [
            'pid' => $pid,
            'name' => $name,
            'email' => $email,
            'label' => $label,
        ];
    }

    metis_runtime_send_json_success(['people' => $people]);
});

metis_ajax_register_handler( 'metis_people_search_donor', function () {
    $db = metis_db();
    $contacts_table = Metis_Tables::get('contacts');
    if (!$contacts_table) {
        metis_runtime_send_json_success(['donors' => []]);
    }

    $q = isset($_POST['q']) ? metis_text_clean(metis_runtime_unslash($_POST['q'])) : '';
    $q = trim($q);
    if ($q === '') {
        metis_runtime_send_json_success(['donors' => []]);
    }

    $like = '%' . $db->escapeLike($q) . '%';
    $rows = $db->fetchAll(
        "SELECT did, first_name, last_name, email
         FROM {$contacts_table}
         WHERE did IS NOT NULL
           AND did <> ''
           AND (did LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)
         ORDER BY first_name ASC, last_name ASC, did ASC
         LIMIT 12",
        [ $like, $like, $like, $like ]
    ) ?: [];

    $donors = [];
    foreach ($rows as $row) {
        $did = trim((string) ($row['did'] ?? ''));
        if ($did === '') continue;
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($name === '') $name = (string) ($row['email'] ?? $did);
        $donors[] = [
            'did' => $did,
            'name' => $name,
            'email' => (string) ($row['email'] ?? ''),
            'label' => $name . ' (' . $did . ')',
        ];
    }

    metis_runtime_send_json_success(['donors' => $donors]);
});

if (!function_exists('metis_people_activity_type_labels')) {
    function metis_people_activity_type_labels(): array {
        return [
            'person_saved' => 'Profile updated',
            'avatar_updated' => 'Profile photo updated',
            'offboarded' => 'Offboarding completed',
            'bulk_profile_action' => 'Bulk position update',
            'bulk_role_action' => 'Bulk Metis role update',
            'bulk_stripe_role_action' => 'Bulk Stripe access update',
            'bulk_workspace_group_action' => 'Bulk Workspace group update',
            'bulk_workspace_user_action' => 'Bulk Workspace user update',
            'workspace_user_saved' => 'Workspace user saved',
            'workspace_user_flags_updated' => 'Workspace user flags updated',
            'workspace_user_linked_to_person' => 'Workspace user linked',
            'workspace_user_deleted' => 'Workspace user deleted',
            'workspace_security_action' => 'Workspace security action',
            'workspace_group_saved' => 'Workspace group saved',
            'workspace_group_member_saved' => 'Workspace group member updated',
            'workspace_group_members_bulk_saved' => 'Workspace group members updated',
            'workspace_group_deleted' => 'Workspace group deleted',
            'workspace_directory_import' => 'Workspace directory import',
            'template_applied' => 'Template applied',
            'role_saved' => 'Role saved',
            'totp_enabled' => 'MFA enabled',
            'mfa_reset' => 'MFA reset',
        ];
    }
}

if (!function_exists('metis_people_activity_fetch_page')) {
    function metis_people_activity_fetch_page(int $page, int $page_size = 15, string $query = ''): array {
        $db = metis_db();
        $activity_table = Metis_Tables::get('people_activity');
        $people_table = Metis_Tables::get('people');
        if ($page < 1) $page = 1;
        if ($page_size < 1) $page_size = 50;
        $query = trim($query);
        $where_sql = '';
        $where_args = [];
        if ($query !== '') {
            $like = '%' . $db->escapeLike($query) . '%';
            $where_sql = " WHERE (
                a.activity_type LIKE %s
                OR a.summary LIKE %s
                OR p.display_name LIKE %s
                OR p.pid LIKE %s
                OR ap.display_name LIKE %s
                OR ap.pid LIKE %s
            )";
            $where_args = [$like, $like, $like, $like, $like, $like];
        }
        $total_sql = "SELECT COUNT(*)
                      FROM {$activity_table} a
                      LEFT JOIN {$people_table} p ON p.id = a.person_id
                      LEFT JOIN {$people_table} ap ON ap.id = a.actor_person_id" . $where_sql;
        $total = (int) ($where_sql !== '' ? $db->scalar($total_sql, $where_args) : $db->scalar($total_sql));
        $total_pages = max(1, (int) ceil($total / $page_size));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $page_size;
        $rows_sql = 
            "SELECT a.id, a.activity_type, a.summary, a.details, a.created_at,
                    p.pid AS target_pid, p.display_name AS target_name,
                    ap.pid AS actor_pid, ap.display_name AS actor_name
             FROM {$activity_table} a
             LEFT JOIN {$people_table} p ON p.id = a.person_id
             LEFT JOIN {$people_table} ap ON ap.id = a.actor_person_id
             {$where_sql}
             ORDER BY a.created_at DESC
             LIMIT {$page_size} OFFSET {$offset}";
        $rows = $where_sql !== '' ? $db->fetchAll($rows_sql, $where_args) : $db->fetchAll($rows_sql);
        if (!is_array($rows)) $rows = [];
        return [
            'rows' => $rows,
            'page' => $page,
            'total_pages' => $total_pages,
            'has_prev' => $page > 1,
            'has_next' => $page < $total_pages,
            'prev_page' => $page > 1 ? ($page - 1) : 1,
            'next_page' => $page < $total_pages ? ($page + 1) : $total_pages,
        ];
    }
}

metis_ajax_register_handler( 'metis_people_get_activity_page', function () {
    $page = isset($_POST['page']) ? (int) metis_runtime_unslash($_POST['page']) : 1;
    $query = isset($_POST['q']) ? metis_text_clean(metis_runtime_unslash($_POST['q'])) : '';
    if ($page < 1) $page = 1;
    $payload = metis_people_activity_fetch_page($page, 15, $query);
    $labels = metis_people_activity_type_labels();
    $rows_out = [];
    foreach ((array) ($payload['rows'] ?? []) as $row) {
        $activity_key = strtolower(trim((string) ($row['activity_type'] ?? '')));
        $type_label = (string) ($labels[$activity_key] ?? ucwords(str_replace('_', ' ', $activity_key)));
        $created_raw = trim((string) ($row['created_at'] ?? ''));
        $created = $created_raw;
        $ts = strtotime($created_raw);
        if ($ts) $created = date('M j, Y g:i a', $ts);
        $target_name = trim((string) ($row['target_name'] ?? ''));
        $target_pid = trim((string) ($row['target_pid'] ?? ''));
        $target_label = $target_name !== '' ? ($target_name . ($target_pid !== '' ? (' (' . $target_pid . ')') : '')) : '—';
        $actor_name = trim((string) ($row['actor_name'] ?? ''));
        $actor_pid = trim((string) ($row['actor_pid'] ?? ''));
        $actor_label = $actor_name !== '' ? ($actor_name . ($actor_pid !== '' ? (' (' . $actor_pid . ')') : '')) : 'System';
        $rows_out[] = [
            'time' => $created,
            'type' => $type_label,
            'summary' => (string) ($row['summary'] ?? ''),
            'target' => $target_label,
            'actor' => $actor_label,
        ];
    }
    metis_runtime_send_json_success([
        'rows' => $rows_out,
        'page' => (int) ($payload['page'] ?? 1),
        'total_pages' => (int) ($payload['total_pages'] ?? 1),
        'has_prev' => !empty($payload['has_prev']) ? 1 : 0,
        'has_next' => !empty($payload['has_next']) ? 1 : 0,
        'prev_page' => (int) ($payload['prev_page'] ?? 1),
        'next_page' => (int) ($payload['next_page'] ?? 1),
        'q' => $query,
    ]);
});
