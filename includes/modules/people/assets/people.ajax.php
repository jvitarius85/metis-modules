<?php
if (!defined('ABSPATH')) exit;

function metis_people_ajax_verify(): void {
    check_ajax_referer('metis_people', 'nonce');
    if (!metis_people_can_manage()) {
        metis_send_json_error('Unauthorized', 403);
    }
    metis_people_ensure_schema();
    metis_people_seed_permissions_and_roles();
}

function metis_people_workspace_ajax_verify(): void {
    check_ajax_referer('metis_people', 'nonce');
    if (!metis_people_can_workspace_manage()) {
        metis_send_json_error('Unauthorized', 403);
    }
    metis_people_ensure_schema();
    metis_people_seed_permissions_and_roles();
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
    global $wpdb;
    $table = Metis_Tables::get('people_auth_challenges');
    $challenge_key = bin2hex(random_bytes(16));
    $challenge_raw = random_bytes(32);
    $challenge_value = metis_people_b64url_encode($challenge_raw);
    $expires = gmdate('Y-m-d H:i:s', time() + max(60, $ttl_seconds));
    $wpdb->insert($table, [
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
    global $wpdb;
    $table = Metis_Tables::get('people_auth_challenges');
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE challenge_key = %s
           AND purpose = %s
           AND consumed_at IS NULL
           AND expires_at >= UTC_TIMESTAMP()
         LIMIT 1",
        $challenge_key,
        $purpose
    ), ARRAY_A);
    if (!$row) return null;
    if ($person_id !== null && (int) $row['person_id'] !== $person_id) return null;
    $wpdb->update($table, ['consumed_at' => gmdate('Y-m-d H:i:s')], ['id' => (int) $row['id']], ['%s'], ['%d']);
    return $row;
}

function metis_people_origin_allowed(string $origin): bool {
    if ($origin === '') return false;
    $site = home_url();
    $site_parts = metis_parse_url($site);
    $origin_parts = metis_parse_url($origin);
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
        $item = sanitize_text_field((string) $line);
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

    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$people_table} LIKE %s", $key));
    $cache[$key] = !empty($exists);
    return $cache[$key];
}

function metis_people_identity_select_fields(): string {
    return 'id, pid';
}

function metis_people_resolve_person_record(int $person_id = 0, string $pid = ''): array {
    global $wpdb;

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
        $person_by_pid = $wpdb->get_row(
            $wpdb->prepare("SELECT {$select_fields} FROM {$people_table} WHERE pid = %s LIMIT 1", $pid),
            ARRAY_A
        );
        if ($person_by_pid) {
            $pid_lookup_mode = 'exact';
        }
        if (!$person_by_pid) {
            $person_by_pid = $wpdb->get_row(
                $wpdb->prepare("SELECT {$select_fields} FROM {$people_table} WHERE UPPER(pid) = UPPER(%s) LIMIT 1", $pid),
                ARRAY_A
            );
            if ($person_by_pid) {
                $pid_lookup_mode = 'case_insensitive';
            }
        }
    }

    if ($person_id > 0) {
        $person_by_id = $wpdb->get_row(
            $wpdb->prepare("SELECT {$select_fields} FROM {$people_table} WHERE id = %d LIMIT 1", $person_id),
            ARRAY_A
        );
    }

    if ($person_by_pid && $person_by_id && (int) ($person_by_pid['id'] ?? 0) !== (int) ($person_by_id['id'] ?? 0)) {
        return ['ok' => false, 'error' => 'Person identifier mismatch.', 'status' => 409];
    }

    $person = $person_by_pid ?: $person_by_id;
    if (!$person) {
        $row_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$people_table}");
        $sample_row = $wpdb->get_row("SELECT id, pid FROM {$people_table} ORDER BY id ASC LIMIT 1", ARRAY_A);
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
    global $wpdb;
    if ($person_id < 1) return [];
    $roles_table = Metis_Tables::get('people_roles');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $role_perms_table = Metis_Tables::get('people_role_perms');
    $perms_table = Metis_Tables::get('people_permissions');
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.permission_key
         FROM {$user_roles_table} ur
         INNER JOIN {$roles_table} r ON r.id = ur.role_id
         INNER JOIN {$role_perms_table} rp ON rp.role_id = ur.role_id AND rp.allow_access = 1
         INNER JOIN {$perms_table} p ON p.id = rp.permission_id
         WHERE ur.person_id = %d
           AND (ur.start_at IS NULL OR ur.start_at <= NOW())
           AND (ur.end_at IS NULL OR ur.end_at >= NOW())",
        $person_id
    )) ?: [];
    $out = [];
    foreach ($rows as $permission_key) {
        $key = sanitize_key((string) $permission_key);
        if ($key !== '') $out[$key] = true;
    }
    return array_keys($out);
}

function metis_people_workspace_queue_job(string $job_type, string $entity_type, ?int $entity_id, ?int $requested_by_person_id, array $payload = []): int {
    global $wpdb;
    $table = Metis_Tables::get('people_workspace_sync_jobs');
    $ok = $wpdb->insert($table, [
        'job_type' => sanitize_key($job_type),
        'entity_type' => sanitize_key($entity_type),
        'entity_id' => $entity_id && $entity_id > 0 ? $entity_id : null,
        'requested_by_person_id' => $requested_by_person_id && $requested_by_person_id > 0 ? $requested_by_person_id : null,
        'payload_json' => !empty($payload) ? metis_json_encode($payload) : null,
        'status' => 'queued',
    ], ['%s', '%s', '%d', '%d', '%s', '%s']);
    if (!$ok) return 0;
    return (int) $wpdb->insert_id;
}

function metis_people_workspace_sync_settings(): array {
    $service = function_exists( 'metis_workspace_service_account_payload' ) ? metis_workspace_service_account_payload() : [];
    $impersonation_admin = strtolower(trim((string) Core_Settings_Service::get('workspace_impersonation_admin', '')));
    $workspace_domain = strtolower(trim((string) Core_Settings_Service::get('workspace_domain', '')));
    $customer_id = trim((string) Core_Settings_Service::get('workspace_customer_id', ''));
    $stripe_schema_name = trim((string) Core_Settings_Service::get('workspace_stripe_sso_schema', ''));
    $stripe_field_name = trim((string) Core_Settings_Service::get('workspace_stripe_sso_field', ''));
    $stripe_access_group_email = strtolower(trim((string) Core_Settings_Service::get('workspace_stripe_access_group_email', '')));
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
    $jwt_input = metis_people_b64url_encode(metis_json_encode($header)) . '.' . metis_people_b64url_encode(metis_json_encode($claims));
    $signature = '';
    $signed = openssl_sign($jwt_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
    if (!$signed) return ['ok' => false, 'error' => 'Could not sign Workspace JWT assertion.'];
    $assertion = $jwt_input . '.' . metis_people_b64url_encode($signature);
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

function metis_people_workspace_google_request(string $method, string $path, ?array $body, array $cfg): array {
    $token = metis_people_workspace_google_access_token($cfg);
    if (empty($token['ok'])) {
        return [
            'ok' => false,
            'error' => (string) ($token['error'] ?? 'Workspace token error.'),
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
        ],
    ];
    if ($body !== null) {
        $args['body'] = metis_json_encode($body);
    }
    $response = metis_remote_request($url, $args);
    if (metis_is_error($response)) {
        return [
            'ok' => false,
            'error' => $response->get_error_message(),
            'method' => strtoupper($method),
            'url' => $url,
            'path' => $path,
            'subject' => (string) ($cfg['subject'] ?? ''),
        ];
    }
    $code = (int) metis_remote_retrieve_response_code($response);
    $raw = (string) metis_remote_retrieve_body($response);
    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = '';
        $reason = '';
        if (is_array($decoded)) {
            $msg = (string) ($decoded['error']['message'] ?? '');
            $reason = (string) ($decoded['error']['errors'][0]['reason'] ?? '');
        }
        if ($msg === '') $msg = 'Google API request failed (' . $code . ').';
        $detail = sprintf(
            '%s %s failed (%d). %s%s Subject: %s',
            strtoupper($method),
            $url,
            $code,
            $msg,
            $reason !== '' ? ' [reason=' . $reason . ']' : '',
            (string) ($cfg['subject'] ?? '')
        );
        return [
            'ok' => false,
            'error' => $detail,
            'status' => $code,
            'method' => strtoupper($method),
            'url' => $url,
            'path' => $path,
            'subject' => (string) ($cfg['subject'] ?? ''),
            'google_error_message' => $msg,
            'google_error_reason' => $reason,
            'raw' => $raw,
        ];
    }
    return ['ok' => true, 'status' => $code, 'body' => is_array($decoded) ? $decoded : []];
}

function metis_people_workspace_normalize_role_key(string $value): string {
    $key = sanitize_key(str_replace(['-', ' '], '_', strtolower(trim($value))));
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
        return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Failed to fetch Workspace user schemas.')];
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
    if (!is_email($workspace_email)) {
        return ['ok' => false, 'error' => 'Workspace email is invalid for Stripe SSO sync.'];
    }

    $get_user = metis_people_workspace_google_request('GET', 'users/' . rawurlencode($workspace_email) . '?projection=full', null, $cfg);
    if (empty($get_user['ok'])) {
        return ['ok' => false, 'error' => (string) ($get_user['error'] ?? 'Failed to load Workspace user for Stripe sync.')];
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
        $discover_error = '';
        if (isset($schema_lookup) && is_array($schema_lookup) && !empty($schema_lookup['error'])) {
            $discover_error = ' ' . (string) $schema_lookup['error'];
        }
        return [
            'ok' => false,
            'error' => 'Stripe SSO custom field was not found.' . $discover_error . ' Set Workspace Stripe schema/field in Settings.',
            'available_schemas' => $available_schemas,
        ];
    }
    if (!isset($custom_schemas[$schema_name]) || !is_array($custom_schemas[$schema_name])) {
        $custom_schemas[$schema_name] = [];
    }
    if ($stripe_role_key === null || $stripe_role_key === '') {
        $custom_schemas[$schema_name][$field_name] = '';
    } else {
        $custom_schemas[$schema_name][$field_name] = sanitize_key($stripe_role_key);
    }

    $patch = metis_people_workspace_google_request('PATCH', 'users/' . rawurlencode($workspace_email), [
        'customSchemas' => $custom_schemas,
    ], $cfg);
    if (empty($patch['ok'])) {
        return ['ok' => false, 'error' => (string) ($patch['error'] ?? 'Failed to update Workspace Stripe role attribute.')];
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
    if (!is_email($group_email) || !is_email($member_email)) {
        return ['ok' => false, 'error' => 'Invalid group/member email for Workspace group membership update.'];
    }
    if ($include) {
        $payload = ['email' => $member_email, 'role' => 'MEMBER'];
        $create = metis_people_workspace_google_request('POST', 'groups/' . rawurlencode($group_email) . '/members', $payload, $cfg);
        if (!empty($create['ok'])) return ['ok' => true];
        $upsert = metis_people_workspace_google_request('PUT', 'groups/' . rawurlencode($group_email) . '/members/' . rawurlencode($member_email), $payload, $cfg);
        if (!empty($upsert['ok'])) return ['ok' => true];
        return ['ok' => false, 'error' => (string) ($upsert['error'] ?? $create['error'] ?? 'Failed to add member to Stripe access group.')];
    }
    $delete = metis_people_workspace_google_request('DELETE', 'groups/' . rawurlencode($group_email) . '/members/' . rawurlencode($member_email), null, $cfg);
    if (!empty($delete['ok']) || ((int) ($delete['status'] ?? 0) === 404)) {
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => (string) ($delete['error'] ?? 'Failed to remove member from Stripe access group.')];
}

function metis_people_workspace_execute_job(array $job, array $cfg, bool $dry_run = false): array {
    global $wpdb;
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
        $stripe_role = sanitize_key((string) ($payload['stripe_role'] ?? ''));
        $stripe_access_group_email = strtolower(trim((string) ($cfg['stripe_access_group_email'] ?? '')));
        if ($entity_id > 0) {
            $workspace_users_table = Metis_Tables::get('people_workspace_users');
            $linked_workspace_email = strtolower(trim((string) $wpdb->get_var($wpdb->prepare(
                "SELECT primary_email FROM {$workspace_users_table} WHERE person_id = %d ORDER BY id ASC LIMIT 1",
                $entity_id
            ))));
            if (is_email($linked_workspace_email)) {
                $workspace_email = $linked_workspace_email;
            } elseif (!is_email($workspace_email)) {
                $people_table = Metis_Tables::get('people');
                $workspace_email = strtolower(trim((string) $wpdb->get_var($wpdb->prepare(
                    "SELECT workspace_email FROM {$people_table} WHERE id = %d LIMIT 1",
                    $entity_id
                ))));
            }
        }
        if (!is_email($workspace_email)) {
            return ['ok' => false, 'error' => 'Stripe sync skipped: workspace email not set.'];
        }
        if ($dry_run) {
            return ['ok' => true, 'message' => 'Dry run: would sync Stripe SSO role for ' . $workspace_email];
        }
        if ($job_type === 'stripe_user_disable') {
            $result = metis_people_workspace_apply_stripe_sso_role($workspace_email, null, $cfg);
            if (empty($result['ok'])) {
                return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Failed to disable Stripe access in Workspace.')];
            }
            if (is_email($stripe_access_group_email)) {
                $membership = metis_people_workspace_set_group_membership($stripe_access_group_email, $workspace_email, false, $cfg);
                if (empty($membership['ok'])) {
                    return ['ok' => false, 'error' => (string) ($membership['error'] ?? 'Failed to remove Stripe access group membership.')];
                }
            }
            return ['ok' => true, 'message' => 'Disabled Stripe access via Workspace (role cleared' . (is_email($stripe_access_group_email) ? ', group removed' : '') . ') for ' . $workspace_email];
        }
        if ($stripe_role === '') {
            return ['ok' => false, 'error' => 'Stripe sync skipped: role is empty.'];
        }
        $result = metis_people_workspace_apply_stripe_sso_role($workspace_email, $stripe_role, $cfg);
        if (empty($result['ok'])) {
            return ['ok' => false, 'error' => (string) ($result['error'] ?? 'Failed to apply Stripe role in Workspace.')];
        }
        if (is_email($stripe_access_group_email)) {
            $membership = metis_people_workspace_set_group_membership($stripe_access_group_email, $workspace_email, true, $cfg);
            if (empty($membership['ok'])) {
                return ['ok' => false, 'error' => (string) ($membership['error'] ?? 'Failed to add Stripe access group membership.')];
            }
        }
        return ['ok' => true, 'message' => 'Enabled Stripe access via Workspace (role set' . (is_email($stripe_access_group_email) ? ', group added' : '') . ') for ' . $workspace_email];
    }

    if (in_array($job_type, ['workspace_user_create', 'workspace_user_upsert'], true)) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$users_table} WHERE id = %d LIMIT 1", $entity_id), ARRAY_A);
        if (!$row) return ['ok' => false, 'error' => 'Workspace user row not found.'];
        $primary_email = strtolower(trim((string) ($row['primary_email'] ?? '')));
        $previous_primary_email = strtolower(trim((string) ($payload['previous_primary_email'] ?? '')));
        $add_alias_email = strtolower(trim((string) ($payload['add_alias_email'] ?? '')));
        if (!is_email($primary_email)) return ['ok' => false, 'error' => 'Workspace user email is invalid.'];
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
        $lookup_email = is_email($previous_primary_email) ? $previous_primary_email : $primary_email;
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
            if (empty($resp['ok'])) return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Failed to update workspace user.')];
            $google_id = (string) (($resp['body']['id'] ?? '') ?: ($existing['body']['id'] ?? ''));
            $wpdb->update($users_table, ['workspace_user_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced'], ['id' => $entity_id], ['%s', '%s'], ['%d']);
            if (is_email($add_alias_email) && $add_alias_email !== $primary_email) {
                $alias_resp = metis_people_workspace_google_request(
                    'POST',
                    'users/' . rawurlencode($primary_email) . '/aliases',
                    ['alias' => $add_alias_email],
                    $cfg
                );
                $alias_status = (int) ($alias_resp['status'] ?? 0);
                if (empty($alias_resp['ok']) && !in_array($alias_status, [409, 412], true)) {
                    return ['ok' => false, 'error' => (string) ($alias_resp['error'] ?? 'Updated user but failed to add old email alias.')];
                }
            }
            return ['ok' => true, 'message' => 'Updated Workspace user ' . $primary_email];
        }
        $user_body['password'] = metis_people_workspace_random_password(20);
        $user_body['changePasswordAtNextLogin'] = true;
        $create = metis_people_workspace_google_request('POST', 'users', $user_body, $cfg);
        if (empty($create['ok'])) return ['ok' => false, 'error' => (string) ($create['error'] ?? 'Failed to create workspace user.')];
        $google_id = (string) ($create['body']['id'] ?? '');
        $wpdb->update($users_table, ['workspace_user_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced'], ['id' => $entity_id], ['%s', '%s'], ['%d']);
        return ['ok' => true, 'message' => 'Created Workspace user ' . $primary_email];
    }

    if ($job_type === 'workspace_group_upsert') {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$groups_table} WHERE id = %d LIMIT 1", $entity_id), ARRAY_A);
        if (!$row) return ['ok' => false, 'error' => 'Workspace group row not found.'];
        $group_email = strtolower(trim((string) ($row['group_email'] ?? '')));
        if (!is_email($group_email)) return ['ok' => false, 'error' => 'Workspace group email is invalid.'];
        $group_body = [
            'email' => $group_email,
            'name' => (string) ($row['group_name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
        ];
        if ($dry_run) return ['ok' => true, 'message' => 'Dry run: would upsert Workspace group ' . $group_email];
        $existing = metis_people_workspace_google_request('GET', 'groups/' . rawurlencode($group_email), null, $cfg);
        if (!empty($existing['ok'])) {
            $resp = metis_people_workspace_google_request('PUT', 'groups/' . rawurlencode($group_email), $group_body, $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Failed to update workspace group.')];
            $google_id = (string) (($resp['body']['id'] ?? '') ?: ($existing['body']['id'] ?? ''));
            $wpdb->update($groups_table, ['workspace_group_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced'], ['id' => $entity_id], ['%s', '%s'], ['%d']);
            return ['ok' => true, 'message' => 'Updated Workspace group ' . $group_email];
        }
        $create = metis_people_workspace_google_request('POST', 'groups', $group_body, $cfg);
        if (empty($create['ok'])) return ['ok' => false, 'error' => (string) ($create['error'] ?? 'Failed to create workspace group.')];
        $google_id = (string) ($create['body']['id'] ?? '');
        $wpdb->update($groups_table, ['workspace_group_id' => $google_id !== '' ? $google_id : null, 'sync_status' => 'synced'], ['id' => $entity_id], ['%s', '%s'], ['%d']);
        return ['ok' => true, 'message' => 'Created Workspace group ' . $group_email];
    }

    if (in_array($job_type, ['workspace_group_member_upsert', 'workspace_group_members_bulk_sync'], true)) {
        $group_email = strtolower(trim((string) ($payload['group_email'] ?? '')));
        if (!is_email($group_email) && $entity_id > 0) {
            $group_email = (string) $wpdb->get_var($wpdb->prepare("SELECT group_email FROM {$groups_table} WHERE id = %d LIMIT 1", $entity_id));
            $group_email = strtolower(trim($group_email));
        }
        if (!is_email($group_email)) return ['ok' => false, 'error' => 'Group email not found for membership sync.'];
        $members = [];
        if ($job_type === 'workspace_group_member_upsert') {
            $member_email = strtolower(trim((string) ($payload['member_email'] ?? '')));
            if (!is_email($member_email)) return ['ok' => false, 'error' => 'Member email invalid for group member sync.'];
            $members[] = ['email' => $member_email, 'role' => (string) ($payload['member_role'] ?? 'MEMBER')];
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT wu.primary_email, gm.member_role
                 FROM {$members_table} gm
                 INNER JOIN {$users_table} wu ON wu.id = gm.workspace_user_id
                 WHERE gm.group_id = %d",
                $entity_id
            ), ARRAY_A) ?: [];
            foreach ($rows as $row) {
                $member_email = strtolower(trim((string) ($row['primary_email'] ?? '')));
                if (!is_email($member_email)) continue;
                $members[] = ['email' => $member_email, 'role' => (string) ($row['member_role'] ?? 'member')];
            }
        }
        if ($dry_run) return ['ok' => true, 'message' => 'Dry run: would sync ' . count($members) . ' members for ' . $group_email];
        if ($job_type === 'workspace_group_members_bulk_sync') {
            $desired_member_emails = [];
            foreach ($members as $member) {
                $member_email = strtolower(trim((string) ($member['email'] ?? '')));
                if (is_email($member_email)) $desired_member_emails[$member_email] = true;
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
                    if (!is_email($remote_email) || $remote_type === 'group') continue;
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
                if (empty($upsert['ok'])) return ['ok' => false, 'error' => (string) ($upsert['error'] ?? $create['error'] ?? 'Failed to sync group member.')];
            }
        }
        if ($entity_id > 0) {
            $wpdb->update($groups_table, ['sync_status' => 'synced'], ['id' => $entity_id], ['%s'], ['%d']);
        }
        return ['ok' => true, 'message' => 'Synced group members for ' . $group_email];
    }

    if ($job_type === 'workspace_security_action') {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$users_table} WHERE id = %d LIMIT 1", $entity_id), ARRAY_A);
        if (!$row) return ['ok' => false, 'error' => 'Workspace user not found for security action.'];
        $user_email = strtolower(trim((string) ($row['primary_email'] ?? '')));
        if (!is_email($user_email)) return ['ok' => false, 'error' => 'Workspace user email invalid for security action.'];
        $action_type = sanitize_key((string) ($payload['action_type'] ?? ''));
        if ($action_type === '') return ['ok' => false, 'error' => 'Security action type is missing.'];
        if ($dry_run) return ['ok' => true, 'message' => 'Dry run: would run security action ' . $action_type . ' for ' . $user_email];
        if ($action_type === 'reset_password') {
            $resp = metis_people_workspace_google_request('PUT', 'users/' . rawurlencode($user_email), [
                'password' => metis_people_workspace_random_password(20),
                'changePasswordAtNextLogin' => true,
            ], $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Password reset failed.')];
        } elseif ($action_type === 'revoke_sessions') {
            $resp = metis_people_workspace_google_request('POST', 'users/' . rawurlencode($user_email) . '/signOut', [], $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Session revoke failed.')];
        } elseif ($action_type === 'force_2fa_reenroll') {
            $resp = metis_people_workspace_google_request('POST', 'users/' . rawurlencode($user_email) . '/twoStepVerification/turnOff', [], $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => (string) ($resp['error'] ?? '2FA re-enroll reset failed.')];
        } elseif ($action_type === 'suspend_account' || $action_type === 'unsuspend_account') {
            $suspended = $action_type === 'suspend_account';
            $resp = metis_people_workspace_google_request('PUT', 'users/' . rawurlencode($user_email), ['suspended' => $suspended], $cfg);
            if (empty($resp['ok'])) return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Suspend/unsuspend failed.')];
            $wpdb->update($users_table, ['is_suspended' => $suspended ? 1 : 0, 'sync_status' => 'synced'], ['id' => $entity_id], ['%d', '%s'], ['%d']);
        } else {
            return ['ok' => false, 'error' => 'Unsupported security action type: ' . $action_type];
        }
        $wpdb->query($wpdb->prepare(
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
    global $wpdb;
    $jobs_table = Metis_Tables::get('people_workspace_sync_jobs');
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok']) && !$dry_run) {
        return ['processed' => 0, 'completed' => 0, 'failed' => 0, 'error' => (string) ($cfg['error'] ?? 'Workspace configuration missing.')];
    }
    $rows = [];
    if ($specific_job_id > 0) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$jobs_table} WHERE id = %d AND status IN ('queued','failed') LIMIT 1",
            $specific_job_id
        ), ARRAY_A) ?: [];
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$jobs_table}
             WHERE status = 'queued'
             ORDER BY created_at ASC, id ASC
             LIMIT %d",
            max(1, min(100, $limit))
        ), ARRAY_A) ?: [];
    }
    $processed = 0;
    $completed = 0;
    $failed = 0;
    $messages = [];
    foreach ($rows as $job) {
        $job_id = (int) ($job['id'] ?? 0);
        if ($job_id < 1) continue;
        $claimed = (int) $wpdb->query($wpdb->prepare(
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
            $wpdb->update($jobs_table, [
                'status' => 'completed',
                'last_error' => null,
                'processed_at' => current_time('mysql'),
            ], ['id' => $job_id], ['%s', '%s', '%s'], ['%d']);
            if (!empty($result['message'])) $messages[] = (string) $result['message'];
        } else {
            $failed++;
            $error = (string) ($result['error'] ?? 'Unknown workspace sync error.');
            $wpdb->update($jobs_table, [
                'status' => 'failed',
                'last_error' => $error,
                'processed_at' => current_time('mysql'),
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

metis_add_action('wp_ajax_metis_people_save_person', function () {
    metis_people_ajax_verify();

    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $contacts_table = Metis_Tables::get('contacts');
    $workspace_users_table = Metis_Tables::get('people_workspace_users');
    $workspace_groups_table = Metis_Tables::get('people_workspace_groups');
    $workspace_members_table = Metis_Tables::get('people_workspace_group_members');
    $person_id = isset($_POST['person_id']) ? (int) metis_unslash($_POST['person_id']) : 0;
    $pid = isset($_POST['pid']) ? trim(sanitize_text_field(metis_unslash($_POST['pid']))) : '';
    $first_name = isset($_POST['first_name']) ? sanitize_text_field(metis_unslash($_POST['first_name'])) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field(metis_unslash($_POST['last_name'])) : '';
    $display_name = isset($_POST['display_name']) ? sanitize_text_field(metis_unslash($_POST['display_name'])) : '';
    $email = isset($_POST['email']) ? sanitize_email(metis_unslash($_POST['email'])) : '';
    $auth_provider = isset($_POST['auth_provider']) ? sanitize_key(metis_unslash($_POST['auth_provider'])) : 'metis';
    $is_workspace_user = !empty($_POST['is_workspace_user']) ? 1 : 0;
    $workspace_email = isset($_POST['workspace_email']) ? sanitize_email(metis_unslash($_POST['workspace_email'])) : '';
    $workspace_role = isset($_POST['workspace_role']) ? sanitize_key(metis_unslash($_POST['workspace_role'])) : '';
    $workspace_is_protected = !empty($_POST['workspace_is_protected']) ? 1 : 0;
    $workspace_groups_json = isset($_POST['workspace_groups_json']) ? (string) metis_unslash($_POST['workspace_groups_json']) : '[]';
    $stripe_role = isset($_POST['stripe_role']) ? sanitize_key(metis_unslash($_POST['stripe_role'])) : '';
    $linked_donor_id_raw = isset($_POST['linked_donor_id']) ? sanitize_text_field(metis_unslash($_POST['linked_donor_id'])) : '';
    $manager_pid = isset($_POST['manager_pid']) ? sanitize_text_field(metis_unslash($_POST['manager_pid'])) : '';
    $department = isset($_POST['department']) ? sanitize_text_field(metis_unslash($_POST['department'])) : '';
    $board_term_start = isset($_POST['board_term_start']) ? sanitize_text_field(metis_unslash($_POST['board_term_start'])) : '';
    $board_term_end = isset($_POST['board_term_end']) ? sanitize_text_field(metis_unslash($_POST['board_term_end'])) : '';
    $volunteer_area = isset($_POST['volunteer_area']) ? sanitize_text_field(metis_unslash($_POST['volunteer_area'])) : '';
    $lifecycle_status = isset($_POST['lifecycle_status']) ? sanitize_key(metis_unslash($_POST['lifecycle_status'])) : 'active';
    $email_notifications = isset($_POST['email_notifications']) ? (!empty($_POST['email_notifications']) ? 1 : 0) : 1;
    $sms_notifications = 0;
    $requires_2fa = !empty($_POST['requires_2fa']) ? 1 : 0;
    $mfa_method = isset($_POST['mfa_method']) ? sanitize_key(metis_unslash($_POST['mfa_method'])) : 'none';
    $is_staff = !empty($_POST['is_staff']) ? 1 : 0;
    $is_board = !empty($_POST['is_board']) ? 1 : 0;
    $is_volunteer = !empty($_POST['is_volunteer']) ? 1 : 0;
    $status = isset($_POST['status']) ? sanitize_key(metis_unslash($_POST['status'])) : 'active';
    $current_person = null;
    if ($person_id > 0 || $pid !== '') {
        $resolved_person = metis_people_resolve_person_record($person_id, $pid);
        if (empty($resolved_person['ok'])) {
            metis_send_json_error((string) ($resolved_person['error'] ?? 'Person record was not found.'), (int) ($resolved_person['status'] ?? 404));
        }
        $current_person = (array) ($resolved_person['person'] ?? []);
        $person_id = (int) ($current_person['id'] ?? 0);
    }
    $roles = [];
    if (isset($_POST['roles'])) {
        $decoded_roles = json_decode((string) metis_unslash($_POST['roles']), true);
        if (is_array($decoded_roles)) {
            foreach ($decoded_roles as $role_key) {
                $rk = sanitize_key((string) $role_key);
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
            if (!is_email($workspace_group_email)) continue;
            $workspace_group_emails[] = $workspace_group_email;
        }
    }
    $workspace_group_emails = array_values(array_unique($workspace_group_emails));
    $role_windows = [];
    if (isset($_POST['role_windows'])) {
        $decoded_windows = json_decode((string) metis_unslash($_POST['role_windows']), true);
        if (is_array($decoded_windows)) {
            foreach ($decoded_windows as $role_key => $window) {
                $rk = sanitize_key((string) $role_key);
                if ($rk === '' || !is_array($window)) continue;
                $start_at = isset($window['start_at']) ? sanitize_text_field((string) $window['start_at']) : '';
                $end_at = isset($window['end_at']) ? sanitize_text_field((string) $window['end_at']) : '';
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
        $decoded_notify = json_decode((string) metis_unslash($_POST['notification_prefs_json']), true);
        if (is_array($decoded_notify)) {
            $clean_notify = [];
            foreach ($decoded_notify as $event_key => $channels) {
                $ek = sanitize_key((string) $event_key);
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
        if ($workspace_email === '' && is_email($email)) {
            $workspace_email = $email;
        }
        if (is_email($workspace_email)) {
            $email = $workspace_email;
        }
    }
    if ($display_name === '' || !is_email($email)) {
        metis_send_json_error('First/last or display name and valid email are required.', 400);
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
    if ($is_workspace_user && !is_email($workspace_email)) {
        metis_send_json_error('Workspace users require a valid Google Workspace email.', 400);
    }
    if ($is_workspace_user === 0) {
        $workspace_group_emails = [];
    }
    if ($linked_donor_id !== '') {
        $donor_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$contacts_table} WHERE did = %s LIMIT 1",
            $linked_donor_id
        ));
        if ($donor_exists < 1) {
            metis_send_json_error('Linked donor ID was not found in Contacts.', 400);
        }
        $donor_conflict = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$people_table} WHERE linked_donor_id = %s AND id <> %d LIMIT 1",
            $linked_donor_id,
            $person_id
        ));
        if ($donor_conflict > 0) {
            metis_send_json_error('That donor is already linked to another person profile.', 400);
        }
    }
    if ($manager_pid !== '') {
        $manager_person_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1",
            $manager_pid
        ));
        if ($manager_person_id < 1) {
            metis_send_json_error('Manager PID was not found.', 400);
        }
        if ($person_id > 0 && $manager_person_id === $person_id) {
            metis_send_json_error('A person cannot be their own manager.', 400);
        }
    }
    if ($workspace_role !== '') {
        $valid_workspace_role = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'workspace' LIMIT 1",
            $workspace_role
        ));
        if ($valid_workspace_role < 1) {
            metis_send_json_error('Invalid Google Workspace role selected.', 400);
        }
    }
    if ($stripe_role !== '') {
        $valid_stripe_role = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'stripe' LIMIT 1",
            $stripe_role
        ));
        if ($valid_stripe_role < 1) {
            metis_send_json_error('Invalid Stripe role selected.', 400);
        }
    }

    $email_conflict = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$people_table} WHERE email = %s AND id <> %d LIMIT 1",
        $email,
        $person_id
    ));
    if ($email_conflict > 0) {
        metis_send_json_error('Email already exists in People.', 400);
    }
    if ($person_id > 0 && $is_workspace_user === 0) {
        $protected_workspace_user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id
             FROM {$workspace_users_table}
             WHERE person_id = %d
               AND is_protected = 1
             LIMIT 1",
            $person_id
        ));
        if ($protected_workspace_user_id > 0) {
            metis_send_json_error('This profile is linked to a protected Workspace account and cannot be removed from Workspace.', 400);
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
        'is_volunteer' => $is_volunteer,
        'status' => $status,
        'offboarded_at' => ($status === 'inactive' || $lifecycle_status === 'alumni') ? current_time('mysql') : null,
    ];
    $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s'];

    $previous_person = null;
    $previous_workspace_email = '';
    if ($person_id > 0) {
        $previous_person = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, pid, is_workspace_user, workspace_email, stripe_role, status, lifecycle_status FROM {$people_table} WHERE id = %d LIMIT 1",
                $person_id
            ),
            ARRAY_A
        );
        $previous_workspace_email = strtolower(trim((string) ($previous_person['workspace_email'] ?? '')));
        if (!is_email($previous_workspace_email)) {
            $previous_workspace_email = strtolower(trim((string) $wpdb->get_var($wpdb->prepare(
                "SELECT primary_email FROM {$workspace_users_table} WHERE person_id = %d ORDER BY id ASC LIMIT 1",
                $person_id
            ))));
        }
        $ok = $wpdb->update($people_table, $payload, ['id' => $person_id], $format, ['%d']);
        if ($ok === false) {
            metis_send_json_error('Failed to update person.', 500);
        }
    } else {
        $payload['pid'] = metis_generate_code('PE', $people_table, 'pid');
        $format[] = '%s';
        $ok = $wpdb->insert($people_table, $payload, $format);
        if (!$ok) {
            metis_send_json_error('Failed to create person.', 500);
        }
        $person_id = (int) $wpdb->insert_id;
    }

    $wpdb->delete($user_roles_table, ['person_id' => $person_id], ['%d']);

    if (!empty($roles)) {
        foreach ($roles as $role_key) {
            $role_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1", $role_key));
            if ($role_id < 1) continue;
            $window = $role_windows[$role_key] ?? ['start_at' => '', 'end_at' => ''];
            $wpdb->insert(
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

    $person_pid = (string) $wpdb->get_var(
        $wpdb->prepare( "SELECT pid FROM {$people_table} WHERE id = %d LIMIT 1", $person_id )
    );
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
        $linked_workspace_user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$workspace_users_table} WHERE person_id = %d OR primary_email = %s LIMIT 1",
            $person_id,
            $workspace_email
        ));
        if ($linked_workspace_user_id > 0) {
            $wpdb->update(
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
            $group_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, group_email
                     FROM {$workspace_groups_table}
                     WHERE group_email IN ({$placeholders})",
                    ...$workspace_group_emails
                ),
                ARRAY_A
            ) ?: [];
            foreach ($group_rows as $group_row) {
                $group_id = (int) ($group_row['id'] ?? 0);
                $group_email = strtolower(trim((string) ($group_row['group_email'] ?? '')));
                if ($group_id < 1 || !is_email($group_email)) continue;
                $available_group_ids_by_email[$group_email] = $group_id;
            }
        }

        $desired_group_ids = array_values(array_unique(array_map('intval', array_values($available_group_ids_by_email))));
        $existing_group_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT group_id
             FROM {$workspace_members_table}
             WHERE workspace_user_id = %d",
            $linked_workspace_user_id
        )) ?: [];
        $existing_group_ids = array_values(array_unique(array_map('intval', $existing_group_ids)));

        $to_add = array_values(array_diff($desired_group_ids, $existing_group_ids));
        $to_remove = array_values(array_diff($existing_group_ids, $desired_group_ids));
        $touched_group_ids = [];

        foreach ($to_add as $group_id) {
            if ($group_id < 1) continue;
            $ok = $wpdb->insert(
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
            $deleted = $wpdb->delete(
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
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$workspace_groups_table}
                     SET direct_members_count = (SELECT COUNT(*) FROM {$workspace_members_table} WHERE group_id = %d),
                         sync_status = 'queued'
                     WHERE id = %d",
                    $group_id,
                    $group_id
                ));
                $group_email = strtolower(trim((string) $wpdb->get_var($wpdb->prepare(
                    "SELECT group_email FROM {$workspace_groups_table} WHERE id = %d LIMIT 1",
                    $group_id
                ))));
                metis_people_workspace_queue_job(
                    'workspace_group_members_bulk_sync',
                    'workspace_group',
                    $group_id,
                    $actor_for_groups,
                    [
                        'group_email' => $group_email,
                        'member_count' => (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$workspace_members_table} WHERE group_id = %d",
                            $group_id
                        )),
                    ]
                );
            }
        }
    }

    metis_people_log_activity($person_id, 'person_saved', 'Updated person profile', [
        'pid' => $person_pid,
        'status' => $status,
        'lifecycle_status' => $lifecycle_status,
    ]);
    metis_send_json_success([
        'person_id' => $person_id,
        'pid' => $person_pid,
        'workspace_groups_count' => count($workspace_group_emails),
    ]);
});

metis_add_action('wp_ajax_metis_people_generate_totp_secret', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $person_id = isset($_POST['person_id']) ? (int) metis_unslash($_POST['person_id']) : 0;
    if ($person_id < 1) {
        metis_send_json_error('Invalid person id.', 400);
    }
    $person = $wpdb->get_row($wpdb->prepare("SELECT email, display_name FROM {$people_table} WHERE id = %d LIMIT 1", $person_id), ARRAY_A);
    $email = strtolower(trim((string) ($person['email'] ?? '')));
    $label = trim((string) ($person['display_name'] ?? ''));
    if ($label === '' && $email !== '') {
        $label = $email;
    }
    $issuer = 'Metis';
    $secret = metis_people_totp_generate_secret(32);
    $provisioning_uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $label) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    metis_send_json_success([
        'secret' => $secret,
        'provisioning_uri' => $provisioning_uri,
    ]);
});

metis_add_action('wp_ajax_metis_people_verify_totp_secret', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $person_id = isset($_POST['person_id']) ? (int) metis_unslash($_POST['person_id']) : 0;
    $secret = isset($_POST['secret']) ? strtoupper(sanitize_text_field(metis_unslash($_POST['secret']))) : '';
    $code = isset($_POST['code']) ? preg_replace('/\D+/', '', (string) metis_unslash($_POST['code'])) : '';
    if ($person_id < 1 || $secret === '' || strlen($code) !== 6) {
        metis_send_json_error('Secret and 6-digit code are required.', 400);
    }
    $valid = false;
    $now = time();
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(metis_people_totp_now($secret, 30, 6, $now + ($i * 30)), $code)) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        metis_send_json_error('Code is not valid for this secret.', 400);
    }
    $enc = metis_people_encrypt_secret($secret);
    if ($enc === '') {
        metis_send_json_error('Failed to secure secret.', 500);
    }
    $wpdb->update(
        $people_table,
        [
            'totp_secret_enc' => $enc,
            'totp_enabled' => 1,
            'requires_2fa' => 1,
            'mfa_method' => 'totp',
        ],
        ['id' => $person_id],
        ['%s', '%d', '%d', '%s'],
        ['%d']
    );
    metis_people_log_activity($person_id, 'totp_enabled', 'Enabled authenticator app MFA', []);
    metis_send_json_success(['ok' => 1]);
});

metis_add_action('wp_ajax_metis_people_save_avatar', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $person_id = isset($_POST['person_id']) ? (int) metis_unslash($_POST['person_id']) : 0;
    $base64 = isset($_POST['avatar_base64']) ? (string) metis_unslash($_POST['avatar_base64']) : '';
    if ($person_id < 1 || $base64 === '') {
        metis_send_json_error('Image data is required.', 400);
    }
    if (!preg_match('/^data:image\/(png|jpeg);base64,/', $base64)) {
        metis_send_json_error('Invalid image format.', 400);
    }
    $raw = preg_replace('/^data:image\/(png|jpeg);base64,/', '', $base64);
    $bin = base64_decode($raw, true);
    if ($bin === false || strlen($bin) < 100) {
        metis_send_json_error('Invalid image payload.', 400);
    }
    $upload = metis_store_upload_bits(
        'person-avatar-' . $person_id . '-' . time(),
        $bin,
        [
            'png' => 'image/png',
            'jpg|jpeg' => 'image/jpeg',
        ]
    );
    if (!empty($upload['error'])) {
        metis_send_json_error('Failed to store image.', 500);
    }
    $url = isset($upload['url']) ? esc_url_raw((string) $upload['url']) : '';
    if ($url === '') {
        metis_send_json_error('Image URL not available.', 500);
    }
    $wpdb->update($people_table, ['avatar_url' => $url], ['id' => $person_id], ['%s'], ['%d']);
    metis_people_log_activity($person_id, 'avatar_updated', 'Updated profile photo', []);
    metis_send_json_success(['avatar_url' => $url]);
});

metis_add_action('wp_ajax_metis_people_begin_passkey_registration', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $person_id = isset($_POST['person_id']) ? (int) metis_unslash($_POST['person_id']) : 0;
    if ($person_id < 1) {
        metis_send_json_error('Invalid person id.', 400);
    }
    $person = $wpdb->get_row($wpdb->prepare("SELECT id, email, display_name FROM {$people_table} WHERE id = %d LIMIT 1", $person_id), ARRAY_A);
    if (!$person) {
        metis_send_json_error('Person not found.', 404);
    }
    $challenge = metis_people_create_challenge($person_id, 'passkey_register', 600);
    $exclude = $wpdb->get_col($wpdb->prepare(
        "SELECT credential_id FROM {$passkeys_table} WHERE person_id = %d AND revoked_at IS NULL",
        $person_id
    )) ?: [];
    $exclude_credentials = [];
    foreach ($exclude as $cred_id) {
        $exclude_credentials[] = [
            'id' => (string) $cred_id,
            'type' => 'public-key',
        ];
    }
    $display_name = trim((string) ($person['display_name'] ?? ''));
    if ($display_name === '') {
        $display_name = (string) ($person['email'] ?? '');
    }
    $user_handle = metis_people_b64url_encode('metis-person-' . (string) $person_id);
    metis_send_json_success([
        'challenge_key' => $challenge['challenge_key'],
        'public_key' => [
            'rp' => [
                'name' => 'Metis',
                'id' => metis_parse_url(home_url(), PHP_URL_HOST),
            ],
            'user' => [
                'id' => $user_handle,
                'name' => (string) ($person['email'] ?? ''),
                'displayName' => $display_name,
            ],
            'challenge' => $challenge['challenge_value'],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'excludeCredentials' => $exclude_credentials,
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
        ],
    ]);
});

metis_add_action('wp_ajax_metis_people_complete_passkey_registration', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $person_id = isset($_POST['person_id']) ? (int) metis_unslash($_POST['person_id']) : 0;
    $challenge_key = isset($_POST['challenge_key']) ? sanitize_text_field(metis_unslash($_POST['challenge_key'])) : '';
    $credential_id = isset($_POST['credential_id']) ? sanitize_text_field(metis_unslash($_POST['credential_id'])) : '';
    $client_data_json_b64 = isset($_POST['client_data_json']) ? (string) metis_unslash($_POST['client_data_json']) : '';
    $attestation_object_b64 = isset($_POST['attestation_object']) ? (string) metis_unslash($_POST['attestation_object']) : '';
    $transports_json = isset($_POST['transports_json']) ? sanitize_text_field(metis_unslash($_POST['transports_json'])) : '';
    $label = isset($_POST['label']) ? sanitize_text_field(metis_unslash($_POST['label'])) : '';
    if ($person_id < 1 || $challenge_key === '' || $credential_id === '' || $client_data_json_b64 === '' || $attestation_object_b64 === '') {
        metis_send_json_error('Missing registration payload.', 400);
    }
    $person = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$people_table} WHERE id = %d LIMIT 1", $person_id), ARRAY_A);
    if (!$person) {
        metis_send_json_error('Person not found.', 404);
    }
    $challenge = metis_people_consume_challenge($challenge_key, 'passkey_register', $person_id);
    if (!$challenge) {
        metis_send_json_error('Registration challenge expired or invalid.', 400);
    }
    $client_data_json = metis_people_b64url_decode($client_data_json_b64);
    if ($client_data_json === '') {
        metis_send_json_error('Invalid client data.', 400);
    }
    $client_data = json_decode($client_data_json, true);
    if (!is_array($client_data)) {
        metis_send_json_error('Malformed client data payload.', 400);
    }
    $type = (string) ($client_data['type'] ?? '');
    $origin = (string) ($client_data['origin'] ?? '');
    $challenge_value = (string) ($client_data['challenge'] ?? '');
    if ($type !== 'webauthn.create') {
        metis_send_json_error('Unexpected WebAuthn response type.', 400);
    }
    if (!metis_people_origin_allowed($origin)) {
        metis_send_json_error('Passkey origin mismatch.', 400);
    }
    if (!hash_equals((string) $challenge['challenge_value'], $challenge_value)) {
        metis_send_json_error('Challenge mismatch.', 400);
    }
    $existing = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$passkeys_table} WHERE credential_id = %s LIMIT 1",
        $credential_id
    ));
    if ($existing > 0) {
        metis_send_json_error('Passkey already registered.', 400);
    }
    $actor_id = metis_people_get_current_person_id();
    $ok = $wpdb->insert($passkeys_table, [
        'person_id' => $person_id,
        'credential_id' => $credential_id,
        'credential_public_key' => $attestation_object_b64,
        'sign_count' => 0,
        'transports_json' => $transports_json !== '' ? $transports_json : null,
        'label' => $label !== '' ? $label : 'Passkey',
        'created_by_person_id' => $actor_id > 0 ? $actor_id : null,
    ], ['%d', '%s', '%s', '%d', '%s', '%s', '%d']);
    if (!$ok) {
        metis_send_json_error('Failed to persist passkey.', 500);
    }
    $passkey_id = (int) $wpdb->insert_id;
    $wpdb->query($wpdb->prepare(
        "UPDATE {$people_table}
         SET passkey_enabled = 1,
             requires_2fa = 1,
             mfa_method = CASE WHEN mfa_method = 'none' THEN 'passkey' ELSE mfa_method END
         WHERE id = %d",
        $person_id
    ));
    $label_out = $label !== '' ? $label : 'Passkey';
    metis_people_log_activity($person_id, 'passkey_registered', 'Registered passkey credential', ['label' => $label_out]);
    metis_send_json_success([
        'ok' => 1,
        'passkey' => [
            'id' => $passkey_id,
            'label' => $label_out,
            'created_at' => current_time('mysql'),
        ],
    ]);
});

metis_add_action('wp_ajax_metis_people_revoke_passkey', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $passkey_id = isset($_POST['passkey_id']) ? (int) metis_unslash($_POST['passkey_id']) : 0;
    if ($passkey_id < 1) {
        metis_send_json_error('Invalid passkey id.', 400);
    }
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, person_id, label, revoked_at FROM {$passkeys_table} WHERE id = %d LIMIT 1",
        $passkey_id
    ), ARRAY_A);
    if (!$row) {
        metis_send_json_error('Passkey not found.', 404);
    }
    if (!empty($row['revoked_at'])) {
        metis_send_json_error('Passkey already revoked.', 400);
    }
    $wpdb->update($passkeys_table, ['revoked_at' => current_time('mysql')], ['id' => $passkey_id], ['%s'], ['%d']);
    $active_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$passkeys_table} WHERE person_id = %d AND revoked_at IS NULL",
        (int) $row['person_id']
    ));
    if ($active_count < 1) {
        $wpdb->update($people_table, ['passkey_enabled' => 0], ['id' => (int) $row['person_id']], ['%d'], ['%d']);
    }
    metis_people_log_activity((int) $row['person_id'], 'passkey_revoked', 'Revoked passkey credential', ['label' => (string) ($row['label'] ?? '')]);
    metis_send_json_success(['ok' => 1, 'active_count' => $active_count]);
});

metis_add_action('wp_ajax_metis_people_reset_mfa', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $person_id = isset($_POST['person_id']) ? (int) metis_unslash($_POST['person_id']) : 0;
    if ($person_id < 1) {
        metis_send_json_error('Invalid person id.', 400);
    }

    $person = $wpdb->get_row($wpdb->prepare(
        "SELECT id, pid, email, display_name FROM {$people_table} WHERE id = %d LIMIT 1",
        $person_id
    ), ARRAY_A);
    if (!$person) {
        metis_send_json_error('Person not found.', 404);
    }

    $revoked_passkeys = 0;
    if (Metis_Tables::has('people_passkeys')) {
        $active_passkey_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, label
             FROM {$passkeys_table}
             WHERE person_id = %d AND revoked_at IS NULL",
            $person_id
        ), ARRAY_A) ?: [];

        foreach ($active_passkey_rows as $passkey_row) {
            $updated = $wpdb->update(
                $passkeys_table,
                ['revoked_at' => current_time('mysql')],
                ['id' => (int) ($passkey_row['id'] ?? 0)],
                ['%s'],
                ['%d']
            );
            if ($updated !== false) {
                $revoked_passkeys++;
            }
        }
    }

    $updated = $wpdb->update(
        $people_table,
        [
            'requires_2fa' => 0,
            'mfa_method' => 'none',
            'totp_enabled' => 0,
            'passkey_enabled' => 0,
            'totp_secret_enc' => null,
            'updated_at' => current_time('mysql'),
        ],
        ['id' => $person_id],
        ['%d', '%s', '%d', '%d', '%s', '%s'],
        ['%d']
    );

    if ($updated === false) {
        metis_send_json_error('Failed to reset MFA.', 500);
    }

    metis_people_log_activity($person_id, 'mfa_reset', 'Reset MFA configuration', [
        'revoked_passkeys' => $revoked_passkeys,
    ]);

    metis_send_json_success([
        'ok' => 1,
        'revoked_passkeys' => $revoked_passkeys,
        'person' => [
            'id' => (int) ($person['id'] ?? 0),
            'display_name' => (string) ($person['display_name'] ?? ''),
            'email' => (string) ($person['email'] ?? ''),
            'pid' => (string) ($person['pid'] ?? ''),
        ],
    ]);
});

metis_add_action('wp_ajax_metis_people_save_role', function () {
    metis_people_ajax_verify();

    global $wpdb;
    $roles_table = Metis_Tables::get('people_roles');
    $perms_table = Metis_Tables::get('people_permissions');
    $role_perms_table = Metis_Tables::get('people_role_perms');

    $role_id = isset($_POST['role_id']) ? (int) metis_unslash($_POST['role_id']) : 0;
    $role_key = isset($_POST['role_key']) ? sanitize_key(metis_unslash($_POST['role_key'])) : '';
    $role_domain = isset($_POST['role_domain']) ? sanitize_key(metis_unslash($_POST['role_domain'])) : 'metis';
    $role_name = isset($_POST['role_name']) ? sanitize_text_field(metis_unslash($_POST['role_name'])) : '';
    $description = isset($_POST['description']) ? sanitize_textarea_field(metis_unslash($_POST['description'])) : '';

    $permissions = [];
    if (isset($_POST['permissions'])) {
        $decoded = json_decode((string) metis_unslash($_POST['permissions']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $perm_key) {
                $pk = sanitize_text_field((string) $perm_key);
                if ($pk !== '') $permissions[] = $pk;
            }
        }
    }
    $permissions = array_values(array_unique($permissions));

    if ($role_key === '' || $role_name === '') {
        metis_send_json_error('Role key and role name are required.', 400);
    }

    if (!in_array($role_domain, ['metis', 'stripe', 'workspace'], true)) {
        $role_domain = 'metis';
    }

    $conflict = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = %s AND id <> %d LIMIT 1",
        $role_key,
        $role_domain,
        $role_id
    ));
    if ($conflict > 0) {
        metis_send_json_error('Role key already exists.', 400);
    }

    if ($role_id > 0) {
        $ok = $wpdb->update(
            $roles_table,
            ['role_key' => $role_key, 'role_domain' => $role_domain, 'role_name' => $role_name, 'description' => $description],
            ['id' => $role_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
        if ($ok === false) metis_send_json_error('Failed to update role.', 500);
    } else {
        $ok = $wpdb->insert(
            $roles_table,
            ['role_key' => $role_key, 'role_domain' => $role_domain, 'role_name' => $role_name, 'description' => $description, 'is_system' => 0],
            ['%s', '%s', '%s', '%s', '%d']
        );
        if (!$ok) metis_send_json_error('Failed to create role.', 500);
        $role_id = (int) $wpdb->insert_id;
    }

    $wpdb->delete($role_perms_table, ['role_id' => $role_id], ['%d']);

    foreach ($permissions as $perm_key) {
        $perm_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$perms_table} WHERE permission_key = %s LIMIT 1", $perm_key));
        if ($perm_id < 1) continue;
        $wpdb->insert($role_perms_table, [
            'role_id' => $role_id,
            'permission_id' => $perm_id,
            'allow_access' => 1,
        ], ['%d', '%d', '%d']);
    }
    metis_people_log_activity(null, 'role_saved', 'Saved role definition', [
        'role_key' => $role_key,
        'role_domain' => $role_domain,
        'permission_count' => count($permissions),
    ]);

    metis_send_json_success([
        'role_id' => $role_id,
        'role_key' => $role_key,
    ]);
});

metis_add_action('wp_ajax_metis_people_bulk_role_action', function () {
    metis_people_ajax_verify();
    global $wpdb;

    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');
    $user_roles_table = Metis_Tables::get('people_user_roles');

    $action_type = isset($_POST['bulk_action']) ? sanitize_key(metis_unslash($_POST['bulk_action'])) : '';
    $role_key = isset($_POST['role_key']) ? sanitize_key(metis_unslash($_POST['role_key'])) : '';
    $person_pids = [];
    if (isset($_POST['person_pids'])) {
        $decoded = json_decode((string) metis_unslash($_POST['person_pids']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $pid) {
                $clean = sanitize_text_field((string) $pid);
                if ($clean !== '') $person_pids[] = $clean;
            }
        }
    }
    $person_pids = array_values(array_unique($person_pids));
    if (!in_array($action_type, ['assign', 'remove'], true) || $role_key === '' || empty($person_pids)) {
        metis_send_json_error('Role, action, and people are required.', 400);
    }

    $role_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1",
        $role_key
    ));
    if ($role_id < 1) {
        metis_send_json_error('Invalid Metis role.', 400);
    }

    $updated = 0;
    foreach ($person_pids as $pid) {
        $person_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", $pid));
        if ($person_id < 1) continue;
        if ($action_type === 'assign') {
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1", $person_id, $role_id));
            if ($exists > 0) continue;
            $ok = $wpdb->insert($user_roles_table, ['person_id' => $person_id, 'role_id' => $role_id], ['%d', '%d']);
            if ($ok) $updated++;
        } else {
            $ok = $wpdb->delete($user_roles_table, ['person_id' => $person_id, 'role_id' => $role_id], ['%d', '%d']);
            if ($ok) $updated += (int) $ok;
        }
    }

    metis_people_log_activity(null, 'bulk_role_action', 'Ran bulk role action', [
        'bulk_action' => $action_type,
        'role_key' => $role_key,
        'count' => $updated,
    ]);
    metis_send_json_success(['updated' => $updated]);
});

metis_add_action('wp_ajax_metis_people_bulk_workspace_group_action', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;

    $people_table = Metis_Tables::get('people');
    $workspace_groups_table = Metis_Tables::get('people_workspace_groups');
    $workspace_users_table = Metis_Tables::get('people_workspace_users');
    $workspace_members_table = Metis_Tables::get('people_workspace_group_members');

    $action_type = isset($_POST['bulk_action']) ? sanitize_key(metis_unslash($_POST['bulk_action'])) : '';
    $group_email = strtolower(trim((string) (isset($_POST['group_email']) ? sanitize_email(metis_unslash($_POST['group_email'])) : '')));
    $member_role = isset($_POST['member_role']) ? sanitize_key(metis_unslash($_POST['member_role'])) : 'member';
    if (!in_array($member_role, ['member', 'manager', 'owner'], true)) $member_role = 'member';
    $person_pids = [];
    if (isset($_POST['person_pids'])) {
        $decoded = json_decode((string) metis_unslash($_POST['person_pids']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $pid) {
                $clean = sanitize_text_field((string) $pid);
                if ($clean !== '') $person_pids[] = $clean;
            }
        }
    }
    $person_pids = array_values(array_unique($person_pids));
    if (!in_array($action_type, ['assign', 'remove'], true) || !is_email($group_email) || empty($person_pids)) {
        metis_send_json_error('Group, action, and people are required.', 400);
    }
    $group_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$workspace_groups_table} WHERE group_email = %s LIMIT 1", $group_email));
    if ($group_id < 1) {
        metis_send_json_error('Workspace group not found.', 404);
    }

    $updated = 0;
    $skipped = 0;
    foreach ($person_pids as $pid) {
        $person = $wpdb->get_row($wpdb->prepare(
            "SELECT id, workspace_email FROM {$people_table} WHERE pid = %s LIMIT 1",
            $pid
        ), ARRAY_A);
        $workspace_email = strtolower(trim((string) ($person['workspace_email'] ?? '')));
        if (!$person || !is_email($workspace_email)) {
            $skipped++;
            continue;
        }
        $workspace_user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$workspace_users_table} WHERE person_id = %d OR primary_email = %s LIMIT 1",
            (int) ($person['id'] ?? 0),
            $workspace_email
        ));
        if ($workspace_user_id < 1) {
            $skipped++;
            continue;
        }
        if ($action_type === 'assign') {
            $existing_member_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$workspace_members_table} WHERE group_id = %d AND workspace_user_id = %d LIMIT 1",
                $group_id,
                $workspace_user_id
            ));
            if ($existing_member_id > 0) {
                $ok = $wpdb->update(
                    $workspace_members_table,
                    ['member_role' => $member_role],
                    ['id' => $existing_member_id],
                    ['%s'],
                    ['%d']
                );
                if ($ok !== false) $updated++;
            } else {
                $ok = $wpdb->insert($workspace_members_table, [
                    'group_id' => $group_id,
                    'workspace_user_id' => $workspace_user_id,
                    'member_role' => $member_role,
                ], ['%d', '%d', '%s']);
                if ($ok) $updated++;
            }
        } else {
            $ok = $wpdb->delete($workspace_members_table, [
                'group_id' => $group_id,
                'workspace_user_id' => $workspace_user_id,
            ], ['%d', '%d']);
            if ($ok) $updated += (int) $ok;
        }
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$workspace_groups_table}
         SET direct_members_count = (SELECT COUNT(*) FROM {$workspace_members_table} WHERE group_id = %d),
             sync_status = 'queued'
         WHERE id = %d",
        $group_id,
        $group_id
    ));
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
    metis_send_json_success(['updated' => $updated, 'skipped' => $skipped, 'job_id' => $job_id]);
});

metis_add_action('wp_ajax_metis_people_bulk_stripe_role_action', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;

    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');

    $action_type = isset($_POST['bulk_action']) ? sanitize_key(metis_unslash($_POST['bulk_action'])) : '';
    $stripe_role = isset($_POST['stripe_role']) ? sanitize_key(metis_unslash($_POST['stripe_role'])) : '';
    $person_pids = [];
    if (isset($_POST['person_pids'])) {
        $decoded = json_decode((string) metis_unslash($_POST['person_pids']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $pid) {
                $clean = sanitize_text_field((string) $pid);
                if ($clean !== '') $person_pids[] = $clean;
            }
        }
    }
    $person_pids = array_values(array_unique($person_pids));
    if (!in_array($action_type, ['set', 'clear'], true) || empty($person_pids)) {
        metis_send_json_error('Action and people are required.', 400);
    }
    if ($action_type === 'set') {
        if ($stripe_role === '') metis_send_json_error('Stripe role is required for set action.', 400);
        $role_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'stripe' LIMIT 1",
            $stripe_role
        ));
        if ($role_exists < 1) {
            metis_send_json_error('Invalid Stripe role.', 400);
        }
    }

    $actor = metis_people_get_current_person_id();
    $updated = 0;
    $queued = 0;
    foreach ($person_pids as $pid) {
        $person = $wpdb->get_row($wpdb->prepare(
            "SELECT id, pid, workspace_email, is_workspace_user, status, lifecycle_status FROM {$people_table} WHERE pid = %s LIMIT 1",
            $pid
        ), ARRAY_A);
        if (!$person) continue;
        $person_id = (int) ($person['id'] ?? 0);
        if ($person_id < 1) continue;
        $new_role = $action_type === 'set' ? $stripe_role : null;
        $ok = $wpdb->update(
            $people_table,
            ['stripe_role' => $new_role, 'updated_at' => current_time('mysql')],
            ['id' => $person_id],
            ['%s', '%s'],
            ['%d']
        );
        if ($ok === false) continue;
        $updated++;

        $workspace_email = strtolower(trim((string) ($person['workspace_email'] ?? '')));
        $is_workspace_user = !empty($person['is_workspace_user']);
        $status = (string) ($person['status'] ?? 'active');
        $lifecycle = (string) ($person['lifecycle_status'] ?? 'active');
        $can_provision = $is_workspace_user && is_email($workspace_email) && $status === 'active' && $lifecycle !== 'alumni';
        $job_type = ($action_type === 'set' && $can_provision) ? 'stripe_user_upsert' : 'stripe_user_disable';
        $job_id = metis_people_workspace_queue_job(
            $job_type,
            'person',
            $person_id,
            $actor > 0 ? $actor : null,
            [
                'person_id' => $person_id,
                'pid' => (string) ($person['pid'] ?? ''),
                'workspace_email' => $workspace_email,
                'stripe_role' => $action_type === 'set' ? $stripe_role : '',
                'reason' => $job_type === 'stripe_user_disable' ? ($action_type === 'clear' ? 'bulk_cleared' : 'workspace_or_status_ineligible') : '',
            ]
        );
        if ($job_id > 0) $queued++;
    }

    metis_people_log_activity(null, 'bulk_stripe_role_action', 'Ran bulk Stripe role action', [
        'bulk_action' => $action_type,
        'stripe_role' => $stripe_role,
        'updated' => $updated,
        'queued' => $queued,
    ]);
    metis_send_json_success(['updated' => $updated, 'queued' => $queued]);
});

metis_add_action('wp_ajax_metis_people_offboard_person', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $workspace_users_table = Metis_Tables::get('people_workspace_users');

    $pid = isset($_POST['pid']) ? sanitize_text_field(metis_unslash($_POST['pid'])) : '';
    if ($pid === '') {
        metis_send_json_error('PID is required.', 400);
    }
    $person = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", $pid), ARRAY_A);
    if (!$person) {
        metis_send_json_error('Person not found.', 404);
    }
    $person_id = (int) $person['id'];
    $person_before = $wpdb->get_row($wpdb->prepare(
        "SELECT id, pid, workspace_email, stripe_role FROM {$people_table} WHERE id = %d LIMIT 1",
        $person_id
    ), ARRAY_A);
    $protected_workspace_user_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id
         FROM {$workspace_users_table}
         WHERE person_id = %d
           AND is_protected = 1
         LIMIT 1",
        $person_id
    ));
    if ($protected_workspace_user_id > 0) {
        metis_send_json_error('This person has a protected Workspace account and cannot be offboarded from Metis.', 400);
    }
    $wpdb->update(
        $people_table,
        [
            'status' => 'inactive',
            'lifecycle_status' => 'alumni',
            'is_workspace_user' => 0,
            'workspace_email' => null,
            'workspace_role' => null,
            'stripe_role' => null,
            'offboarded_at' => current_time('mysql'),
        ],
        ['id' => $person_id],
        ['%s', '%s', '%d', '%s', '%s', '%s', '%s'],
        ['%d']
    );
    $wpdb->delete($user_roles_table, ['person_id' => $person_id], ['%d']);
    $actor = metis_people_get_current_person_id();
    $workspace_email = strtolower(trim((string) ($person_before['workspace_email'] ?? '')));
    if (is_email($workspace_email)) {
        $workspace_user_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$workspace_users_table} WHERE person_id = %d OR primary_email = %s LIMIT 1",
            $person_id,
            $workspace_email
        ));
        if ($workspace_user_id > 0) {
            $wpdb->update($workspace_users_table, ['is_suspended' => 1, 'sync_status' => 'queued'], ['id' => $workspace_user_id], ['%d', '%s'], ['%d']);
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
    metis_send_json_success(['pid' => $pid]);
});

metis_add_action('wp_ajax_metis_people_add_document', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $documents_table = Metis_Tables::get('people_documents');
    $pid = isset($_POST['pid']) ? sanitize_text_field(metis_unslash($_POST['pid'])) : '';
    $doc_type = isset($_POST['doc_type']) ? sanitize_key(metis_unslash($_POST['doc_type'])) : '';
    $doc_label = isset($_POST['doc_label']) ? sanitize_text_field(metis_unslash($_POST['doc_label'])) : '';
    $storage_ref = isset($_POST['storage_ref']) ? sanitize_text_field(metis_unslash($_POST['storage_ref'])) : '';
    $remind_at = isset($_POST['remind_at']) ? sanitize_text_field(metis_unslash($_POST['remind_at'])) : '';
    $expires_at = isset($_POST['expires_at']) ? sanitize_text_field(metis_unslash($_POST['expires_at'])) : '';
    if ($pid === '' || $doc_type === '' || $doc_label === '') {
        metis_send_json_error('PID, document type, and label are required.', 400);
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
    $person_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", $pid));
    if ($person_id < 1) metis_send_json_error('Person not found.', 404);
    $actor = metis_people_get_current_person_id();
    $lifecycle_status = ($expires_at !== '' && strtotime($expires_at) < time()) ? 'expired' : 'active';
    $wpdb->insert($documents_table, [
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
    metis_send_json_success([
        'ok' => 1,
        'doc_id' => (int) $wpdb->insert_id,
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

metis_add_action('wp_ajax_metis_people_grant_emergency_access', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');
    $emergency_table = Metis_Tables::get('people_emergency_access');

    $pid = isset($_POST['pid']) ? sanitize_text_field(metis_unslash($_POST['pid'])) : '';
    $role_key = isset($_POST['role_key']) ? sanitize_key(metis_unslash($_POST['role_key'])) : '';
    $hours = isset($_POST['hours']) ? (int) metis_unslash($_POST['hours']) : 4;
    $reason = isset($_POST['reason']) ? sanitize_textarea_field(metis_unslash($_POST['reason'])) : '';
    if ($pid === '' || $role_key === '') {
        metis_send_json_error('PID and role key are required.', 400);
    }
    if ($hours < 1) $hours = 1;
    if ($hours > 72) $hours = 72;
    $person_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people_table} WHERE pid=%s LIMIT 1", $pid));
    $role_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles_table} WHERE role_key=%s AND role_domain='metis' LIMIT 1", $role_key));
    if ($person_id < 1 || $role_id < 1) {
        metis_send_json_error('Invalid PID or role key.', 400);
    }
    $actor = metis_people_get_current_person_id();
    $starts = current_time('mysql');
    $ends = gmdate('Y-m-d H:i:s', strtotime($starts . ' +' . $hours . ' hours'));
    $wpdb->insert($emergency_table, [
        'person_id' => $person_id,
        'granted_role_id' => $role_id,
        'reason' => $reason !== '' ? $reason : null,
        'granted_by_person_id' => $actor > 0 ? $actor : null,
        'starts_at' => $starts,
        'ends_at' => $ends,
    ], ['%d', '%d', '%s', '%d', '%s', '%s']);

    // Ensure role assignment exists for emergency window.
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $existing = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1", $person_id, $role_id));
    if ($existing < 1) {
        $wpdb->insert($user_roles_table, [
            'person_id' => $person_id,
            'role_id' => $role_id,
            'start_at' => $starts,
            'end_at' => $ends,
        ], ['%d', '%d', '%s', '%s']);
    }
    metis_people_log_activity($person_id, 'emergency_access_granted', 'Granted emergency access', ['role_key' => $role_key, 'hours' => $hours]);
    metis_send_json_success(['ok' => 1]);
});

metis_add_action('wp_ajax_metis_people_delete_document', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $documents_table = Metis_Tables::get('people_documents');
    $doc_id = isset($_POST['doc_id']) ? (int) metis_unslash($_POST['doc_id']) : 0;
    if ($doc_id < 1) {
        metis_send_json_error('Invalid document id.', 400);
    }
    $doc = $wpdb->get_row($wpdb->prepare("SELECT id, person_id, doc_label FROM {$documents_table} WHERE id = %d LIMIT 1", $doc_id), ARRAY_A);
    if (!$doc) {
        metis_send_json_error('Document not found.', 404);
    }
    $wpdb->delete($documents_table, ['id' => $doc_id], ['%d']);
    metis_people_log_activity((int) $doc['person_id'], 'document_deleted', 'Deleted document reference', ['doc_label' => (string) ($doc['doc_label'] ?? '')]);
    metis_send_json_success(['ok' => 1]);
});

metis_add_action('wp_ajax_metis_people_revoke_emergency_access', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $emergency_table = Metis_Tables::get('people_emergency_access');
    $entry_id = isset($_POST['entry_id']) ? (int) metis_unslash($_POST['entry_id']) : 0;
    if ($entry_id < 1) {
        metis_send_json_error('Invalid emergency entry id.', 400);
    }
    $entry = $wpdb->get_row($wpdb->prepare("SELECT id, person_id, revoked_at FROM {$emergency_table} WHERE id = %d LIMIT 1", $entry_id), ARRAY_A);
    if (!$entry) {
        metis_send_json_error('Emergency entry not found.', 404);
    }
    if (!empty($entry['revoked_at'])) {
        metis_send_json_error('Entry already revoked.', 400);
    }
    $wpdb->update($emergency_table, ['revoked_at' => current_time('mysql')], ['id' => $entry_id], ['%s'], ['%d']);
    metis_people_log_activity((int) $entry['person_id'], 'emergency_access_revoked', 'Revoked emergency access', ['entry_id' => $entry_id]);
    metis_send_json_success(['ok' => 1]);
});

metis_add_action('wp_ajax_metis_people_save_template', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $templates_table = Metis_Tables::get('people_role_templates');
    $template_roles_table = Metis_Tables::get('people_template_roles');
    $roles_table = Metis_Tables::get('people_roles');

    $template_key = isset($_POST['template_key']) ? sanitize_key(metis_unslash($_POST['template_key'])) : '';
    $template_name = isset($_POST['template_name']) ? sanitize_text_field(metis_unslash($_POST['template_name'])) : '';
    $description = isset($_POST['description']) ? sanitize_text_field(metis_unslash($_POST['description'])) : '';
    $checklist_json = null;
    if (isset($_POST['checklist_json'])) {
        $decoded_checklist = json_decode((string) metis_unslash($_POST['checklist_json']), true);
        if (is_array($decoded_checklist)) {
            $items = [];
            foreach ($decoded_checklist as $item) {
                $label = sanitize_text_field((string) $item);
                if ($label === '') continue;
                if (!in_array($label, $items, true)) $items[] = $label;
            }
            $checklist_json = metis_json_encode($items);
        }
    }
    if ($checklist_json === null && isset($_POST['checklist_text'])) {
        $checklist_json = metis_people_parse_lines_to_json((string) metis_unslash($_POST['checklist_text']));
    }
    $role_keys = [];
    if (isset($_POST['role_keys'])) {
        $decoded = json_decode((string) metis_unslash($_POST['role_keys']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $k) {
                $rk = sanitize_key((string) $k);
                if ($rk !== '') $role_keys[] = $rk;
            }
        }
    }
    $role_keys = array_values(array_unique($role_keys));
    if ($template_key === '' || $template_name === '') {
        metis_send_json_error('Template key and name are required.', 400);
    }
    $template_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$templates_table} WHERE template_key = %s LIMIT 1", $template_key));
    $actor_id = metis_people_get_current_person_id();
    if ($template_id > 0) {
        $wpdb->update($templates_table, [
            'template_name' => $template_name,
            'description' => $description,
            'checklist_json' => $checklist_json,
        ], ['id' => $template_id], ['%s', '%s', '%s'], ['%d']);
    } else {
        $wpdb->insert($templates_table, [
            'template_key' => $template_key,
            'template_name' => $template_name,
            'description' => $description,
            'checklist_json' => $checklist_json,
            'created_by_person_id' => $actor_id > 0 ? $actor_id : null,
        ], ['%s', '%s', '%s', '%s', '%d']);
        $template_id = (int) $wpdb->insert_id;
    }
    $wpdb->delete($template_roles_table, ['template_id' => $template_id], ['%d']);
    foreach ($role_keys as $role_key) {
        $role_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles_table} WHERE role_key = %s AND role_domain = 'metis' LIMIT 1", $role_key));
        if ($role_id < 1) continue;
        $wpdb->insert($template_roles_table, ['template_id' => $template_id, 'role_id' => $role_id], ['%d', '%d']);
    }
    metis_people_log_activity(null, 'template_saved', 'Saved role template', ['template_key' => $template_key]);
    metis_send_json_success(['template_key' => $template_key]);
});

metis_add_action('wp_ajax_metis_people_apply_template', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $templates_table = Metis_Tables::get('people_role_templates');
    $template_roles_table = Metis_Tables::get('people_template_roles');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $tasks_table = Metis_Tables::get('people_lifecycle_tasks');

    $pid = isset($_POST['pid']) ? sanitize_text_field(metis_unslash($_POST['pid'])) : '';
    $template_key = isset($_POST['template_key']) ? sanitize_key(metis_unslash($_POST['template_key'])) : '';
    if ($pid === '' || $template_key === '') {
        metis_send_json_error('PID and template are required.', 400);
    }
    $person_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", $pid));
    $template_row = $wpdb->get_row($wpdb->prepare("SELECT id, checklist_json FROM {$templates_table} WHERE template_key = %s LIMIT 1", $template_key), ARRAY_A);
    $template_id = (int) ($template_row['id'] ?? 0);
    if ($person_id < 1 || $template_id < 1) {
        metis_send_json_error('Invalid PID or template.', 400);
    }
    $role_ids = $wpdb->get_col($wpdb->prepare("SELECT role_id FROM {$template_roles_table} WHERE template_id = %d", $template_id)) ?: [];
    $added = 0;
    foreach ($role_ids as $rid) {
        $role_id = (int) $rid;
        if ($role_id < 1) continue;
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1", $person_id, $role_id));
        if ($exists > 0) continue;
        $ok = $wpdb->insert($user_roles_table, ['person_id' => $person_id, 'role_id' => $role_id], ['%d', '%d']);
        if ($ok) $added++;
    }
    $checklist = json_decode((string) ($template_row['checklist_json'] ?? ''), true);
    if (!is_array($checklist)) $checklist = [];
    $tasks_added = 0;
    foreach ($checklist as $task_label_raw) {
        $task_label = sanitize_text_field((string) $task_label_raw);
        if ($task_label === '') continue;
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$tasks_table}
             WHERE person_id = %d
               AND phase = 'onboarding'
               AND task_label = %s
               AND status IN ('pending','in_progress')
             LIMIT 1",
            $person_id,
            $task_label
        ));
        if ($exists > 0) continue;
        $ok = $wpdb->insert($tasks_table, [
            'person_id' => $person_id,
            'phase' => 'onboarding',
            'task_label' => $task_label,
            'status' => 'pending',
        ], ['%d', '%s', '%s', '%s']);
        if ($ok) $tasks_added++;
    }
    metis_people_log_activity($person_id, 'template_applied', 'Applied role template to person', [
        'template_key' => $template_key,
        'roles_added' => $added,
        'tasks_added' => $tasks_added,
    ]);
    metis_send_json_success(['added' => $added, 'tasks_added' => $tasks_added]);
});

metis_add_action('wp_ajax_metis_people_add_lifecycle_task', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $tasks_table = Metis_Tables::get('people_lifecycle_tasks');
    $pid = isset($_POST['pid']) ? sanitize_text_field(metis_unslash($_POST['pid'])) : '';
    $phase = isset($_POST['phase']) ? sanitize_key(metis_unslash($_POST['phase'])) : 'onboarding';
    $task_label = isset($_POST['task_label']) ? sanitize_text_field(metis_unslash($_POST['task_label'])) : '';
    $due_at = isset($_POST['due_at']) ? sanitize_text_field(metis_unslash($_POST['due_at'])) : '';
    if ($pid === '' || $task_label === '') {
        metis_send_json_error('PID and task label are required.', 400);
    }
    if (!in_array($phase, ['onboarding', 'offboarding'], true)) {
        $phase = 'onboarding';
    }
    if ($due_at !== '' && strlen($due_at) === 16) $due_at .= ':00';
    $due_at = str_replace('T', ' ', $due_at);
    if ($due_at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $due_at)) {
        $due_at = '';
    }
    $person_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", $pid));
    if ($person_id < 1) {
        metis_send_json_error('Person not found.', 404);
    }
    $wpdb->insert($tasks_table, [
        'person_id' => $person_id,
        'phase' => $phase,
        'task_label' => $task_label,
        'status' => 'pending',
        'due_at' => $due_at !== '' ? $due_at : null,
    ], ['%d', '%s', '%s', '%s', '%s']);
    $task_id = (int) $wpdb->insert_id;
    metis_people_log_activity($person_id, 'lifecycle_task_added', 'Added lifecycle task', ['task_id' => $task_id, 'phase' => $phase]);
    metis_send_json_success([
        'task' => [
            'id' => $task_id,
            'phase' => $phase,
            'task_label' => $task_label,
            'status' => 'pending',
            'due_at' => $due_at,
        ],
    ]);
});

metis_add_action('wp_ajax_metis_people_complete_lifecycle_task', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $tasks_table = Metis_Tables::get('people_lifecycle_tasks');
    $task_id = isset($_POST['task_id']) ? (int) metis_unslash($_POST['task_id']) : 0;
    if ($task_id < 1) {
        metis_send_json_error('Invalid task id.', 400);
    }
    $task = $wpdb->get_row($wpdb->prepare("SELECT id, person_id, status FROM {$tasks_table} WHERE id = %d LIMIT 1", $task_id), ARRAY_A);
    if (!$task) {
        metis_send_json_error('Task not found.', 404);
    }
    if ((string) ($task['status'] ?? '') === 'completed') {
        metis_send_json_success(['already_completed' => 1]);
    }
    $wpdb->update($tasks_table, [
        'status' => 'completed',
        'completed_at' => current_time('mysql'),
    ], ['id' => $task_id], ['%s', '%s'], ['%d']);
    metis_people_log_activity((int) $task['person_id'], 'lifecycle_task_completed', 'Completed lifecycle task', ['task_id' => $task_id]);
    metis_send_json_success(['task_id' => $task_id, 'status' => 'completed']);
});

metis_add_action('wp_ajax_metis_people_simulate_permission', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $role_perms_table = Metis_Tables::get('people_role_perms');
    $perms_table = Metis_Tables::get('people_permissions');
    $pid = isset($_POST['pid']) ? sanitize_text_field(metis_unslash($_POST['pid'])) : '';
    $module = isset($_POST['module']) ? sanitize_key(metis_unslash($_POST['module'])) : '';
    $action = isset($_POST['action']) ? sanitize_key(metis_unslash($_POST['action'])) : '';
    if ($pid === '' || $module === '' || $action === '') {
        metis_send_json_error('PID, module, and action are required.', 400);
    }
    $person_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", $pid));
    if ($person_id < 1) {
        metis_send_json_error('Person not found.', 404);
    }
    $permission_key = $module . '.' . $action;
    $permission_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$perms_table} WHERE permission_key = %s LIMIT 1",
        $permission_key
    ));
    if ($permission_id < 1) {
        metis_send_json_error('Permission key not found.', 404);
    }
    $source_roles = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT r.role_key, r.role_name, ur.start_at, ur.end_at
         FROM {$user_roles_table} ur
         INNER JOIN {$roles_table} r ON r.id = ur.role_id
         INNER JOIN {$role_perms_table} rp ON rp.role_id = ur.role_id AND rp.permission_id = %d AND rp.allow_access = 1
         WHERE ur.person_id = %d
           AND (ur.start_at IS NULL OR ur.start_at <= NOW())
           AND (ur.end_at IS NULL OR ur.end_at >= NOW())
         ORDER BY r.role_name ASC",
        $permission_id,
        $person_id
    ), ARRAY_A) ?: [];
    metis_send_json_success([
        'permission_key' => $permission_key,
        'allowed' => !empty($source_roles),
        'source_roles' => $source_roles,
    ]);
});

metis_add_action('wp_ajax_metis_people_create_access_request', function () {
    check_ajax_referer('metis_people', 'nonce');
    if (!metis_user_logged_in() || !metis_people_can_view()) {
        metis_send_json_error('Unauthorized', 403);
    }
    global $wpdb;
    $requests_table = Metis_Tables::get('people_access_requests');
    $people_table = Metis_Tables::get('people');
    $roles_table = Metis_Tables::get('people_roles');

    $target_pid = isset($_POST['target_pid']) ? sanitize_text_field(metis_unslash($_POST['target_pid'])) : '';
    $role_key = isset($_POST['role_key']) ? sanitize_key(metis_unslash($_POST['role_key'])) : '';
    $reason = isset($_POST['reason']) ? sanitize_textarea_field(metis_unslash($_POST['reason'])) : '';
    $requested_start_at = isset($_POST['requested_start_at']) ? sanitize_text_field(metis_unslash($_POST['requested_start_at'])) : '';
    $requested_end_at = isset($_POST['requested_end_at']) ? sanitize_text_field(metis_unslash($_POST['requested_end_at'])) : '';
    $expires_at = isset($_POST['expires_at']) ? sanitize_text_field(metis_unslash($_POST['expires_at'])) : '';
    $required_approvals = isset($_POST['required_approvals']) ? (int) metis_unslash($_POST['required_approvals']) : 2;
    $role_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$roles_table} WHERE role_key=%s AND role_domain='metis' LIMIT 1", $role_key));
    $target_person_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people_table} WHERE pid=%s LIMIT 1", $target_pid));
    if ($role_id < 1 || $target_person_id < 1 || trim($reason) === '') {
        metis_send_json_error('Target person and role are required.', 400);
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
        metis_send_json_error('Requested end must be after requested start.', 400);
    }
    $requester = metis_people_get_current_person_id();
    $code = metis_generate_code('AR', $requests_table, 'request_code');
    $wpdb->insert($requests_table, [
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
    $target_row = $wpdb->get_row($wpdb->prepare(
        "SELECT pid, display_name, first_name, last_name FROM {$people_table} WHERE id = %d LIMIT 1",
        $target_person_id
    ), ARRAY_A) ?: [];
    $target_name = trim((string) ($target_row['first_name'] ?? '') . ' ' . (string) ($target_row['last_name'] ?? ''));
    if ($target_name === '') $target_name = (string) ($target_row['display_name'] ?? '');
    $role_name = (string) $wpdb->get_var($wpdb->prepare("SELECT role_name FROM {$roles_table} WHERE id = %d LIMIT 1", $role_id));
    metis_people_log_activity($target_person_id, 'access_request_created', 'Created access request', ['request_code' => $code, 'role_key' => $role_key, 'required_approvals' => $required_approvals]);
    metis_send_json_success([
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

metis_add_action('wp_ajax_metis_people_resolve_access_request', function () {
    metis_people_ajax_verify();
    global $wpdb;
    $requests_table = Metis_Tables::get('people_access_requests');
    $user_roles_table = Metis_Tables::get('people_user_roles');
    $request_id = isset($_POST['request_id']) ? (int) metis_unslash($_POST['request_id']) : 0;
    $decision = isset($_POST['decision']) ? sanitize_key(metis_unslash($_POST['decision'])) : '';
    $decision_note = isset($_POST['decision_note']) ? sanitize_textarea_field(metis_unslash($_POST['decision_note'])) : '';
    if ($request_id < 1 || !in_array($decision, ['approved', 'rejected'], true) || trim($decision_note) === '') {
        metis_send_json_error('Invalid request or decision.', 400);
    }
    $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$requests_table} WHERE id = %d LIMIT 1", $request_id), ARRAY_A);
    if (!$req || (string) ($req['status'] ?? '') !== 'pending') {
        metis_send_json_error('Request not found or already resolved.', 404);
    }
    if (!empty($req['expires_at']) && strtotime((string) $req['expires_at']) < time()) {
        metis_send_json_error('Request has expired and cannot be resolved.', 400);
    }
    $resolver = metis_people_get_current_person_id();
    $required_approvals = max(1, (int) ($req['required_approvals'] ?? 2));
    $approval_count = max(0, (int) ($req['approval_count'] ?? 0));
    $approval_log = json_decode((string) ($req['approval_log_json'] ?? ''), true);
    if (!is_array($approval_log)) $approval_log = [];

    if ($decision === 'rejected') {
        $wpdb->update($requests_table, [
            'status' => 'rejected',
            'decision_note' => $decision_note,
            'resolver_person_id' => $resolver > 0 ? $resolver : null,
            'resolved_at' => current_time('mysql'),
        ], ['id' => $request_id], ['%s', '%s', '%d', '%s'], ['%d']);
        metis_people_log_activity((int) $req['target_person_id'], 'access_request_resolved', 'Rejected access request', ['request_id' => $request_id, 'decision_note' => $decision_note]);
        metis_send_json_success(['status' => 'rejected']);
    }

    $already = false;
    foreach ($approval_log as $entry) {
        if ((int) ($entry['resolver_person_id'] ?? 0) === $resolver && $resolver > 0) {
            $already = true;
            break;
        }
    }
    if ($already) {
        metis_send_json_error('You already approved this request. Another approver is required.', 400);
    }
    $approval_log[] = [
        'resolver_person_id' => $resolver > 0 ? $resolver : null,
        'decision_note' => $decision_note,
        'approved_at' => current_time('mysql'),
    ];
    $approval_count++;
    $status = $approval_count >= $required_approvals ? 'approved' : 'pending';
    $resolver_person_id = $status === 'approved' ? ($resolver > 0 ? $resolver : null) : null;
    $resolved_at = $status === 'approved' ? current_time('mysql') : null;
    $wpdb->update($requests_table, [
        'status' => $status,
        'approval_count' => $approval_count,
        'approval_log_json' => metis_json_encode($approval_log),
        'decision_note' => $decision_note,
        'resolver_person_id' => $resolver_person_id,
        'resolved_at' => $resolved_at,
    ], ['id' => $request_id], ['%s', '%d', '%s', '%s', '%d', '%s'], ['%d']);
    if ($status === 'approved') {
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$user_roles_table} WHERE person_id = %d AND role_id = %d LIMIT 1", (int) $req['target_person_id'], (int) $req['role_id']));
        if ($exists < 1) {
            $wpdb->insert($user_roles_table, [
                'person_id' => (int) $req['target_person_id'],
                'role_id' => (int) $req['role_id'],
                'start_at' => !empty($req['requested_start_at']) ? (string) $req['requested_start_at'] : null,
                'end_at' => !empty($req['requested_end_at']) ? (string) $req['requested_end_at'] : null,
            ], ['%d', '%d', '%s', '%s']);
        }
        metis_people_log_activity((int) $req['target_person_id'], 'access_request_resolved', 'Approved access request', ['request_id' => $request_id, 'approvals' => $approval_count]);
        metis_send_json_success(['status' => 'approved', 'approval_count' => $approval_count, 'required_approvals' => $required_approvals]);
    }
    metis_people_log_activity((int) $req['target_person_id'], 'access_request_resolved', 'Recorded approval on access request', ['request_id' => $request_id, 'approval_count' => $approval_count, 'required_approvals' => $required_approvals]);
    metis_send_json_success(['status' => 'pending', 'approval_count' => $approval_count, 'required_approvals' => $required_approvals]);
});

metis_add_action('wp_ajax_metis_people_search_person', function () {
    check_ajax_referer('metis_people', 'nonce');
    if (!metis_user_logged_in() || !metis_people_can_view()) {
        metis_send_json_error('Unauthorized', 403);
    }

    global $wpdb;
    $people_table = Metis_Tables::get('people');
    if (!$people_table) {
        metis_send_json_success(['people' => []]);
    }

    $q = isset($_POST['q']) ? sanitize_text_field(metis_unslash($_POST['q'])) : '';
    $q = trim($q);
    if ($q === '') {
        metis_send_json_success(['people' => []]);
    }

    $like = '%' . $wpdb->esc_like($q) . '%';
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT pid, first_name, last_name, display_name, email
             FROM {$people_table}
             WHERE pid LIKE %s
                OR first_name LIKE %s
                OR last_name LIKE %s
                OR display_name LIKE %s
                OR email LIKE %s
             ORDER BY first_name ASC, last_name ASC, display_name ASC, email ASC
             LIMIT 12",
            $like,
            $like,
            $like,
            $like,
            $like
        ),
        ARRAY_A
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

    metis_send_json_success(['people' => $people]);
});

metis_add_action('wp_ajax_metis_people_search_donor', function () {
    check_ajax_referer('metis_people', 'nonce');
    if (!metis_user_logged_in() || !metis_people_can_view()) {
        metis_send_json_error('Unauthorized', 403);
    }

    global $wpdb;
    $contacts_table = Metis_Tables::get('contacts');
    if (!$contacts_table) {
        metis_send_json_success(['donors' => []]);
    }

    $q = isset($_POST['q']) ? sanitize_text_field(metis_unslash($_POST['q'])) : '';
    $q = trim($q);
    if ($q === '') {
        metis_send_json_success(['donors' => []]);
    }

    $like = '%' . $wpdb->esc_like($q) . '%';
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT did, first_name, last_name, email
             FROM {$contacts_table}
             WHERE did IS NOT NULL
               AND did <> ''
               AND (did LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)
             ORDER BY first_name ASC, last_name ASC, did ASC
             LIMIT 12",
            $like,
            $like,
            $like,
            $like
        ),
        ARRAY_A
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

    metis_send_json_success(['donors' => $donors]);
});

metis_add_action('wp_ajax_metis_people_attach_drive_folder', function () {
    metis_people_ajax_verify();
    if (
        !function_exists('metis_drive_workspace_settings')
        || !function_exists('metis_drive_find_or_create_user_folder')
        || !function_exists('metis_drive_ensure_schema')
        || !function_exists('metis_drive_log_action')
    ) {
        metis_send_json_error('Drive module is not available.', 400);
    }

    $person_id = isset($_POST['person_id']) ? (int) metis_unslash($_POST['person_id']) : 0;
    $pid = isset($_POST['pid']) ? trim(sanitize_text_field(metis_unslash($_POST['pid']))) : '';
    if ($person_id < 1 && $pid === '') {
        metis_send_json_error('Person identifier is required.', 422);
    }

    $resolved_person = metis_people_resolve_person_record($person_id, $pid);
    if (empty($resolved_person['ok'])) {
        metis_send_json_error((string) ($resolved_person['error'] ?? 'Person not found.'), (int) ($resolved_person['status'] ?? 404));
    }
    $person = (array) ($resolved_person['person'] ?? []);

    $cfg = metis_drive_workspace_settings();
    if (empty($cfg['ok'])) {
        metis_send_json_error((string) ($cfg['error'] ?? 'Drive is not configured.'), 400);
    }

    metis_drive_ensure_schema();
    $folder = metis_drive_find_or_create_user_folder($cfg, (int) $person['id'], true);
    if (empty($folder['ok']) || empty($folder['folder_id'])) {
        metis_send_json_error((string) ($folder['error'] ?? 'Failed to attach user folder.'), 500);
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
        $folder_url = add_query_arg(
            ['folder_id' => (string) $folder['folder_id']],
            metis_portal_url('drive', 'dashboard')
        );
    }

    metis_send_json_success([
        'folder_id' => (string) ($folder['folder_id'] ?? ''),
        'folder_name' => (string) ($folder['folder_name'] ?? ''),
        'folder_url' => $folder_url,
        'created' => !empty($folder['created']) ? 1 : 0,
    ]);
});

metis_add_action('wp_ajax_metis_people_drive_folder_picker', function () {
    metis_people_ajax_verify();
    if (
        !function_exists('metis_drive_workspace_settings')
        || !function_exists('metis_drive_get_users_root_folder')
        || !function_exists('metis_drive_sync_folder_listing')
        || !function_exists('metis_drive_cached_folder_children')
        || !function_exists('metis_drive_get_file_meta')
        || !function_exists('metis_drive_folder_is_descendant_of')
    ) {
        metis_send_json_error('Drive module is not available.', 400);
    }

    $cfg = metis_drive_workspace_settings();
    if (empty($cfg['ok'])) {
        metis_send_json_error((string) ($cfg['error'] ?? 'Drive is not configured.'), 400);
    }

    $users_root = metis_drive_get_users_root_folder($cfg, false);
    $users_root_id = (string) ($users_root['folder_id'] ?? '');
    if ($users_root_id === '') {
        metis_send_json_error('Users folder could not be resolved.', 400);
    }

    $folder_id = sanitize_text_field(metis_unslash($_POST['folder_id'] ?? ''));
    if ($folder_id === '') {
        $folder_id = $users_root_id;
    }

    $folder_name = (string) ($users_root['folder_name'] ?? 'Users');
    $parent_id = '';

    if ($folder_id !== $users_root_id) {
        $meta = metis_drive_get_file_meta($cfg, $folder_id, 'id,name,mimeType,parents,driveId');
        if (empty($meta['ok'])) {
            metis_send_json_error((string) ($meta['error'] ?? 'Invalid folder.'), 400);
        }
        $body = (array) ($meta['body'] ?? []);
        if ((string) ($body['driveId'] ?? '') !== (string) ($cfg['shared_drive_id'] ?? '')) {
            metis_send_json_error('That folder is not in the configured Shared Drive.', 400);
        }
        if ((string) ($body['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
            metis_send_json_error('Selected item is not a folder.', 400);
        }
        if (!metis_drive_folder_is_descendant_of($cfg, $folder_id, $users_root_id)) {
            metis_send_json_error('Selected folder is not inside the Users container.', 403);
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

    metis_send_json_success([
        'folder_id' => $folder_id,
        'folder_name' => $folder_name,
        'parent_id' => $folder_id === $users_root_id ? '' : $parent_id,
        'users_root_id' => $users_root_id,
        'users_root_name' => (string) ($users_root['folder_name'] ?? 'Users'),
        'folders' => $items,
    ]);
});

metis_add_action('wp_ajax_metis_people_attach_drive_folder_selection', function () {
    metis_people_ajax_verify();
    if (
        !function_exists('metis_drive_workspace_settings')
        || !function_exists('metis_drive_get_users_root_folder')
        || !function_exists('metis_drive_get_file_meta')
        || !function_exists('metis_drive_folder_is_descendant_of')
        || !function_exists('metis_drive_upsert_user_folder_mapping')
        || !function_exists('metis_drive_log_action')
    ) {
        metis_send_json_error('Drive module is not available.', 400);
    }

    $person_id = isset($_POST['person_id']) ? (int) metis_unslash($_POST['person_id']) : 0;
    $pid = isset($_POST['pid']) ? trim(sanitize_text_field(metis_unslash($_POST['pid']))) : '';
    $folder_id = sanitize_text_field(metis_unslash($_POST['folder_id'] ?? ''));
    if ($folder_id === '') {
        metis_send_json_error('Folder is required.', 422);
    }

    $resolved_person = metis_people_resolve_person_record($person_id, $pid);
    if (empty($resolved_person['ok'])) {
        metis_send_json_error((string) ($resolved_person['error'] ?? 'Person not found.'), (int) ($resolved_person['status'] ?? 404));
    }
    $person = (array) ($resolved_person['person'] ?? []);

    $cfg = metis_drive_workspace_settings();
    if (empty($cfg['ok'])) {
        metis_send_json_error((string) ($cfg['error'] ?? 'Drive is not configured.'), 400);
    }

    $users_root = metis_drive_get_users_root_folder($cfg, false);
    $users_root_id = (string) ($users_root['folder_id'] ?? '');
    if ($users_root_id === '') {
        metis_send_json_error('Users folder could not be resolved.', 400);
    }

    $meta = metis_drive_get_file_meta($cfg, $folder_id, 'id,name,mimeType,parents,driveId,webViewLink');
    if (empty($meta['ok'])) {
        metis_send_json_error((string) ($meta['error'] ?? 'Invalid folder.'), 400);
    }
    $body = (array) ($meta['body'] ?? []);
    if ((string) ($body['driveId'] ?? '') !== (string) ($cfg['shared_drive_id'] ?? '')) {
        metis_send_json_error('That folder is not in the configured Shared Drive.', 400);
    }
    if ((string) ($body['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
        metis_send_json_error('Selected item is not a folder.', 400);
    }
    if ($folder_id === $users_root_id || !metis_drive_folder_is_descendant_of($cfg, $folder_id, $users_root_id)) {
        metis_send_json_error('Selected folder must be inside the Users container.', 403);
    }

    $folder_name = (string) ($body['name'] ?? $folder_id);
    $parent_id = (string) (($body['parents'][0] ?? '') ?: $users_root_id);
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
        $folder_url = add_query_arg(
            ['folder_id' => $folder_id],
            metis_portal_url('drive', 'dashboard')
        );
    }

    metis_send_json_success([
        'folder_id' => $folder_id,
        'folder_name' => $folder_name,
        'folder_url' => $folder_url,
        'created' => 0,
    ]);
});

metis_add_action('wp_ajax_metis_people_workspace_save_user', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $users_table = Metis_Tables::get('people_workspace_users');
    $user_roles_table = Metis_Tables::get('people_workspace_user_roles');
    $people_table = Metis_Tables::get('people');

    $workspace_user_id = isset($_POST['workspace_user_id']) ? (int) metis_unslash($_POST['workspace_user_id']) : 0;
    $primary_email = strtolower(trim((string) (isset($_POST['primary_email']) ? sanitize_email(metis_unslash($_POST['primary_email'])) : '')));
    $first_name = isset($_POST['first_name']) ? sanitize_text_field(metis_unslash($_POST['first_name'])) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field(metis_unslash($_POST['last_name'])) : '';
    $display_name = isset($_POST['display_name']) ? sanitize_text_field(metis_unslash($_POST['display_name'])) : '';
    $org_unit_path = isset($_POST['org_unit_path']) ? sanitize_text_field(metis_unslash($_POST['org_unit_path'])) : '/';
    $recovery_email = strtolower(trim((string) (isset($_POST['recovery_email']) ? sanitize_email(metis_unslash($_POST['recovery_email'])) : '')));
    $linked_pid = strtoupper(trim((string) (isset($_POST['linked_pid']) ? sanitize_text_field(metis_unslash($_POST['linked_pid'])) : '')));
    $is_suspended = !empty($_POST['is_suspended']) ? 1 : 0;
    $is_protected = !empty($_POST['is_protected']) ? 1 : 0;

    $role_keys = [];
    if (isset($_POST['role_keys'])) {
        $decoded = json_decode((string) metis_unslash($_POST['role_keys']), true);
        if (is_array($decoded)) {
            foreach ($decoded as $key) {
                $rk = sanitize_key((string) $key);
                if ($rk !== '') $role_keys[] = $rk;
            }
        }
    }
    $role_keys = array_values(array_unique($role_keys));

    if (!is_email($primary_email)) {
        metis_send_json_error('Valid primary email is required.', 400);
    }
    if ($recovery_email !== '' && !is_email($recovery_email)) {
        metis_send_json_error('Recovery email is invalid.', 400);
    }
    if ($org_unit_path === '') $org_unit_path = '/';
    if ($display_name === '') {
        $display_name = trim($first_name . ' ' . $last_name);
    }
    if ($display_name === '') $display_name = $primary_email;

    $person_id = null;
    if ($linked_pid !== '') {
        $person_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$people_table} WHERE pid = %s LIMIT 1", $linked_pid));
        if ($person_id < 1) {
            metis_send_json_error('Linked PID was not found.', 400);
        }
    }

    $email_conflict = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$users_table} WHERE primary_email = %s AND id <> %d LIMIT 1",
        $primary_email,
        $workspace_user_id
    ));
    if ($email_conflict > 0) {
        metis_send_json_error('Primary email already exists in workspace users.', 400);
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
    if ($workspace_user_id > 0) {
        $previous_primary_email = strtolower(trim((string) $wpdb->get_var($wpdb->prepare(
            "SELECT primary_email FROM {$users_table} WHERE id = %d LIMIT 1",
            $workspace_user_id
        ))));
    }
    $is_new_user = $workspace_user_id < 1;
    if ($workspace_user_id > 0) {
        $ok = $wpdb->update($users_table, $payload, ['id' => $workspace_user_id], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'], ['%d']);
        if ($ok === false) {
            metis_send_json_error('Failed to update workspace user.', 500);
        }
    } else {
        $ok = $wpdb->insert($users_table, $payload, ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']);
        if (!$ok) {
            metis_send_json_error('Failed to create workspace user.', 500);
        }
        $workspace_user_id = (int) $wpdb->insert_id;
    }

    $wpdb->delete($user_roles_table, ['workspace_user_id' => $workspace_user_id], ['%d']);
    foreach ($role_keys as $role_key) {
        $wpdb->insert($user_roles_table, [
            'workspace_user_id' => $workspace_user_id,
            'role_key' => $role_key,
        ], ['%d', '%s']);
    }

    $actor = metis_people_get_current_person_id();
    $job_id = metis_people_workspace_queue_job(
        $is_new_user ? 'workspace_user_create' : 'workspace_user_upsert',
        'workspace_user',
        $workspace_user_id,
        $actor > 0 ? $actor : null,
        [
            'primary_email' => $primary_email,
            'roles' => $role_keys,
            'is_suspended' => $is_suspended,
            'previous_primary_email' => $previous_primary_email,
            'add_alias_email' => ($previous_primary_email !== '' && $previous_primary_email !== $primary_email) ? $previous_primary_email : '',
        ]
    );

    metis_people_log_activity($person_id ?: null, 'workspace_user_saved', 'Saved workspace user profile', [
        'workspace_user_id' => $workspace_user_id,
        'primary_email' => $primary_email,
        'job_id' => $job_id,
    ]);

    metis_send_json_success([
        'workspace_user_id' => $workspace_user_id,
        'job_id' => $job_id,
        'user' => [
            'id' => $workspace_user_id,
            'primary_email' => $primary_email,
            'display_name' => $display_name,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'org_unit_path' => $org_unit_path,
            'recovery_email' => $recovery_email,
            'linked_pid' => $linked_pid,
            'is_suspended' => $is_suspended,
            'is_protected' => $is_protected,
            'role_keys' => $role_keys,
        ],
    ]);
});

metis_add_action('wp_ajax_metis_people_workspace_save_group', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $group_id = isset($_POST['group_id']) ? (int) metis_unslash($_POST['group_id']) : 0;
    $group_email = strtolower(trim((string) (isset($_POST['group_email']) ? sanitize_email(metis_unslash($_POST['group_email'])) : '')));
    $group_name = isset($_POST['group_name']) ? sanitize_text_field(metis_unslash($_POST['group_name'])) : '';
    $description = isset($_POST['description']) ? sanitize_textarea_field(metis_unslash($_POST['description'])) : '';
    if (!is_email($group_email) || $group_name === '') {
        metis_send_json_error('Group name and valid group email are required.', 400);
    }
    $conflict = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$groups_table} WHERE group_email = %s AND id <> %d LIMIT 1",
        $group_email,
        $group_id
    ));
    if ($conflict > 0) {
        metis_send_json_error('Group email already exists.', 400);
    }
    $payload = [
        'group_email' => $group_email,
        'group_name' => $group_name,
        'description' => $description !== '' ? $description : null,
        'sync_status' => 'queued',
    ];
    if ($group_id > 0) {
        $ok = $wpdb->update($groups_table, $payload, ['id' => $group_id], ['%s', '%s', '%s', '%s'], ['%d']);
        if ($ok === false) metis_send_json_error('Failed to update group.', 500);
    } else {
        $ok = $wpdb->insert($groups_table, $payload, ['%s', '%s', '%s', '%s']);
        if (!$ok) metis_send_json_error('Failed to create group.', 500);
        $group_id = (int) $wpdb->insert_id;
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
    metis_send_json_success([
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

metis_add_action('wp_ajax_metis_people_workspace_add_group_member', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $users_table = Metis_Tables::get('people_workspace_users');
    $members_table = Metis_Tables::get('people_workspace_group_members');

    $group_id = isset($_POST['group_id']) ? (int) metis_unslash($_POST['group_id']) : 0;
    $member_email = strtolower(trim((string) (isset($_POST['member_email']) ? sanitize_email(metis_unslash($_POST['member_email'])) : '')));
    $member_role = isset($_POST['member_role']) ? sanitize_key(metis_unslash($_POST['member_role'])) : 'member';
    if (!in_array($member_role, ['member', 'manager', 'owner'], true)) $member_role = 'member';
    if ($group_id < 1 || !is_email($member_email)) {
        metis_send_json_error('Group and member email are required.', 400);
    }
    $group_exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$groups_table} WHERE id = %d LIMIT 1", $group_id));
    if ($group_exists < 1) metis_send_json_error('Group not found.', 404);
    $workspace_user_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$users_table} WHERE primary_email = %s LIMIT 1", $member_email));
    if ($workspace_user_id < 1) {
        metis_send_json_error('Workspace user not found for that email.', 404);
    }
    $exists = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$members_table} WHERE group_id = %d AND workspace_user_id = %d LIMIT 1",
        $group_id,
        $workspace_user_id
    ));
    if ($exists > 0) {
        $wpdb->update($members_table, ['member_role' => $member_role], ['id' => $exists], ['%s'], ['%d']);
    } else {
        $wpdb->insert($members_table, [
            'group_id' => $group_id,
            'workspace_user_id' => $workspace_user_id,
            'member_role' => $member_role,
        ], ['%d', '%d', '%s']);
    }
    $wpdb->query($wpdb->prepare(
        "UPDATE {$groups_table}
         SET direct_members_count = (SELECT COUNT(*) FROM {$members_table} WHERE group_id = %d),
             sync_status = 'queued'
         WHERE id = %d",
        $group_id,
        $group_id
    ));
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
    metis_send_json_success(['group_id' => $group_id, 'job_id' => $job_id]);
});

metis_add_action('wp_ajax_metis_people_workspace_get_group_members_matrix', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $users_table = Metis_Tables::get('people_workspace_users');
    $members_table = Metis_Tables::get('people_workspace_group_members');

    $group_id = isset($_POST['group_id']) ? (int) metis_unslash($_POST['group_id']) : 0;
    if ($group_id < 1) {
        metis_send_json_error('Group is required.', 400);
    }
    $group = $wpdb->get_row($wpdb->prepare(
        "SELECT id, group_name, group_email, description
         FROM {$groups_table}
         WHERE id = %d
         LIMIT 1",
        $group_id
    ), ARRAY_A);
    if (!$group) {
        metis_send_json_error('Group not found.', 404);
    }

    $users = $wpdb->get_results(
        "SELECT id, primary_email, first_name, last_name, display_name
         FROM {$users_table}
         ORDER BY display_name ASC, first_name ASC, last_name ASC, primary_email ASC",
        ARRAY_A
    ) ?: [];
    $roles_by_user_id = [];
    $member_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT workspace_user_id, member_role
         FROM {$members_table}
         WHERE group_id = %d",
        $group_id
    ), ARRAY_A) ?: [];
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
    if (!empty($cfg['ok']) && is_email($group_email)) {
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
                if (!is_email($email) || $type === 'group') continue;
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
        $prepared = $wpdb->prepare(
            "SELECT email, first_name, last_name, cid
             FROM {$contacts_table}
             WHERE email IN ({$in_placeholders})",
            ...$external_emails
        );
        $contact_rows = $wpdb->get_results($prepared, ARRAY_A) ?: [];
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

    metis_send_json_success([
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

metis_add_action('wp_ajax_metis_people_workspace_save_group_members_bulk', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $users_table = Metis_Tables::get('people_workspace_users');
    $members_table = Metis_Tables::get('people_workspace_group_members');

    $group_id = isset($_POST['group_id']) ? (int) metis_unslash($_POST['group_id']) : 0;
    if ($group_id < 1) metis_send_json_error('Group is required.', 400);
    $group = $wpdb->get_row($wpdb->prepare(
        "SELECT id, group_email
         FROM {$groups_table}
         WHERE id = %d
         LIMIT 1",
        $group_id
    ), ARRAY_A);
    if (!$group) metis_send_json_error('Group not found.', 404);

    $members_json = isset($_POST['members']) ? (string) metis_unslash($_POST['members']) : '[]';
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
        $query = $wpdb->prepare(
            "SELECT id, primary_email FROM {$users_table} WHERE id IN ({$placeholders})",
            ...$candidate_user_ids
        );
        $rows = $wpdb->get_results($query, ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) continue;
            $valid_user_ids[$id] = true;
            $email = strtolower(trim((string) ($row['primary_email'] ?? '')));
            if (is_email($email)) $email_by_user_id[$id] = $email;
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
            if (is_email($email_for_user)) $desired_members[$email_for_user] = $member_role;
            continue;
        }
        if (is_email($member_email)) {
            $desired_members[$member_email] = $member_role;
        }
    }

    $wpdb->delete($members_table, ['group_id' => $group_id], ['%d']);
    $inserted_count = 0;
    foreach ($to_insert as $workspace_user_id => $member_role) {
        $inserted = $wpdb->insert($members_table, [
            'group_id' => $group_id,
            'workspace_user_id' => (int) $workspace_user_id,
            'member_role' => $member_role,
        ], ['%d', '%d', '%s']);
        if ($inserted) $inserted_count++;
    }
    $wpdb->update(
        $groups_table,
        ['direct_members_count' => $inserted_count, 'sync_status' => 'queued'],
        ['id' => $group_id],
        ['%d', '%s'],
        ['%d']
    );

    $cfg = metis_people_workspace_sync_settings();
    $group_email = strtolower(trim((string) ($group['group_email'] ?? '')));
    if (!empty($cfg['ok']) && is_email($group_email)) {
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
                if (!is_email($email) || $type === 'group') continue;
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
        $wpdb->update($groups_table, ['sync_status' => 'synced'], ['id' => $group_id], ['%s'], ['%d']);
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
    metis_send_json_success(['group_id' => $group_id, 'member_count' => $inserted_count, 'job_id' => $job_id]);
});

metis_add_action('wp_ajax_metis_people_workspace_get_group_permissions', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $group_id = isset($_POST['group_id']) ? (int) metis_unslash($_POST['group_id']) : 0;
    if ($group_id < 1) metis_send_json_error('Group is required.', 400);

    $group = $wpdb->get_row($wpdb->prepare("SELECT id, group_email, metadata_json FROM {$groups_table} WHERE id = %d LIMIT 1", $group_id), ARRAY_A);
    if (!$group) metis_send_json_error('Group not found.', 404);

    $permissions = [
        'whoCanJoin' => 'INVITED_CAN_JOIN',
        'whoCanViewMembership' => 'ALL_MEMBERS_CAN_VIEW',
        'whoCanPostMessage' => 'ALL_MEMBERS_CAN_POST',
        'allowExternalMembers' => 'false',
    ];
    $metadata = json_decode((string) ($group['metadata_json'] ?? ''), true);
    if (is_array($metadata) && !empty($metadata['permissions']) && is_array($metadata['permissions'])) {
        $permissions = metis_people_workspace_group_permissions_sanitize((array) $metadata['permissions']);
    }

    $cfg = metis_people_workspace_sync_settings();
    if (!empty($cfg['ok'])) {
        $cfg_groups = $cfg;
        $cfg_groups['scopes'] = array_values(array_unique(array_merge(
            (array) ($cfg['scopes'] ?? []),
            ['https://www.googleapis.com/auth/apps.groups.settings']
        )));
        $group_email = strtolower(trim((string) ($group['group_email'] ?? '')));
        if (is_email($group_email)) {
            $remote = metis_people_workspace_google_request(
                'GET',
                'https://www.googleapis.com/groups/v1/groups/' . rawurlencode($group_email),
                null,
                $cfg_groups
            );
            if (!empty($remote['ok']) && is_array($remote['body'])) {
                $permissions = metis_people_workspace_group_permissions_sanitize((array) $remote['body']);
            }
        }
    }

    metis_send_json_success(['permissions' => $permissions]);
});

metis_add_action('wp_ajax_metis_people_workspace_save_group_permissions', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $group_id = isset($_POST['group_id']) ? (int) metis_unslash($_POST['group_id']) : 0;
    if ($group_id < 1) metis_send_json_error('Group is required.', 400);
    $group = $wpdb->get_row($wpdb->prepare("SELECT id, group_email, metadata_json FROM {$groups_table} WHERE id = %d LIMIT 1", $group_id), ARRAY_A);
    if (!$group) metis_send_json_error('Group not found.', 404);

    $permissions_payload = [];
    if (isset($_POST['permissions'])) {
        $decoded = json_decode((string) metis_unslash($_POST['permissions']), true);
        if (is_array($decoded)) $permissions_payload = $decoded;
    }
    $permissions = metis_people_workspace_group_permissions_sanitize($permissions_payload);

    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_send_json_error((string) ($cfg['error'] ?? 'Workspace configuration is missing.'), 400);
    }
    $cfg_groups = $cfg;
    $cfg_groups['scopes'] = array_values(array_unique(array_merge(
        (array) ($cfg['scopes'] ?? []),
        ['https://www.googleapis.com/auth/apps.groups.settings']
    )));
    $group_email = strtolower(trim((string) ($group['group_email'] ?? '')));
    if (!is_email($group_email)) metis_send_json_error('Group email is invalid.', 400);

    $remote = metis_people_workspace_google_request(
        'PUT',
        'https://www.googleapis.com/groups/v1/groups/' . rawurlencode($group_email),
        $permissions,
        $cfg_groups
    );
    if (empty($remote['ok'])) {
        metis_send_json_error((string) ($remote['error'] ?? 'Failed to update group permissions in Workspace.'), 400);
    }

    $metadata = json_decode((string) ($group['metadata_json'] ?? ''), true);
    if (!is_array($metadata)) $metadata = [];
    $metadata['permissions'] = $permissions;
    $wpdb->update(
        $groups_table,
        ['metadata_json' => metis_json_encode($metadata), 'sync_status' => 'synced'],
        ['id' => $group_id],
        ['%s', '%s'],
        ['%d']
    );
    metis_send_json_success(['group_id' => $group_id, 'permissions' => $permissions]);
});

metis_add_action('wp_ajax_metis_people_workspace_delete_group', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $groups_table = Metis_Tables::get('people_workspace_groups');
    $members_table = Metis_Tables::get('people_workspace_group_members');
    $group_id = isset($_POST['group_id']) ? (int) metis_unslash($_POST['group_id']) : 0;
    if ($group_id < 1) metis_send_json_error('Group is required.', 400);
    $group = $wpdb->get_row($wpdb->prepare("SELECT id, group_email FROM {$groups_table} WHERE id = %d LIMIT 1", $group_id), ARRAY_A);
    if (!$group) metis_send_json_error('Group not found.', 404);
    $group_email = strtolower(trim((string) ($group['group_email'] ?? '')));

    $cfg = metis_people_workspace_sync_settings();
    if (!empty($cfg['ok']) && is_email($group_email)) {
        metis_people_workspace_google_request('DELETE', 'groups/' . rawurlencode($group_email), null, $cfg);
    }
    $wpdb->delete($members_table, ['group_id' => $group_id], ['%d']);
    $deleted = $wpdb->delete($groups_table, ['id' => $group_id], ['%d']);
    if (!$deleted) metis_send_json_error('Failed to delete group.', 500);
    metis_people_log_activity(null, 'workspace_group_deleted', 'Deleted workspace group', [
        'group_id' => $group_id,
        'group_email' => $group_email,
    ]);
    metis_send_json_success(['group_id' => $group_id]);
});

metis_add_action('wp_ajax_metis_people_workspace_run_security_action', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $users_table = Metis_Tables::get('people_workspace_users');
    $actions_table = Metis_Tables::get('people_workspace_security_actions');

    $workspace_user_id = isset($_POST['workspace_user_id']) ? (int) metis_unslash($_POST['workspace_user_id']) : 0;
    $action_type = isset($_POST['action_type']) ? sanitize_key(metis_unslash($_POST['action_type'])) : '';
    $reason = isset($_POST['reason']) ? sanitize_textarea_field(metis_unslash($_POST['reason'])) : '';
    $allowed_actions = ['reset_password', 'revoke_sessions', 'force_2fa_reenroll', 'suspend_account', 'unsuspend_account'];
    if ($workspace_user_id < 1 || !in_array($action_type, $allowed_actions, true) || trim($reason) === '') {
        metis_send_json_error('Valid user, action, and reason are required.', 400);
    }
    $user_row = $wpdb->get_row($wpdb->prepare("SELECT id, person_id, primary_email FROM {$users_table} WHERE id = %d LIMIT 1", $workspace_user_id), ARRAY_A);
    if (!$user_row) metis_send_json_error('Workspace user not found.', 404);
    $actor = metis_people_get_current_person_id();
    $wpdb->insert($actions_table, [
        'workspace_user_id' => $workspace_user_id,
        'action_type' => $action_type,
        'requested_by_person_id' => $actor > 0 ? $actor : null,
        'status' => 'pending',
        'reason' => $reason,
    ], ['%d', '%s', '%d', '%s', '%s']);
    if ($action_type === 'suspend_account' || $action_type === 'unsuspend_account') {
        $wpdb->update($users_table, [
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
    metis_send_json_success(['ok' => 1, 'job_id' => $job_id, 'action_type' => $action_type]);
});

metis_add_action('wp_ajax_metis_people_workspace_process_queue', function () {
    metis_people_workspace_ajax_verify();
    $limit = isset($_POST['limit']) ? (int) metis_unslash($_POST['limit']) : 10;
    $job_id = isset($_POST['job_id']) ? (int) metis_unslash($_POST['job_id']) : 0;
    $dry_run = !empty($_POST['dry_run']) ? true : false;
    $run_all = !empty($_POST['run_all']) ? true : false;
    $limit = max(1, min(100, $limit));
    if (!$run_all || $job_id > 0) {
        $result = metis_people_workspace_process_jobs($limit, $dry_run, $job_id);
        if (!empty($result['error'])) {
            metis_send_json_error((string) $result['error'], 400);
        }
        metis_send_json_success($result);
        return;
    }
    $total = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'messages' => []];
    $loops = 0;
    $max_loops = 20;
    while ($loops < $max_loops) {
        $loops++;
        $result = metis_people_workspace_process_jobs($limit, $dry_run, 0);
        if (!empty($result['error'])) {
            metis_send_json_error((string) $result['error'], 400);
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
    global $wpdb;
    $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'queued'");
    $total['remaining_queued'] = $remaining;
    $total['loops'] = $loops;
    metis_send_json_success($total);
});

function metis_people_workspace_import_directory_snapshot(array $cfg, int $limit = 500, bool $include_groups = false, int $groups_limit = 300): array {
    global $wpdb;
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
            return ['ok' => false, 'error' => (string) ($resp['error'] ?? 'Failed to fetch users from Workspace.')];
        }
        $users = (array) ($resp['body']['users'] ?? []);
        if (empty($users)) break;
        foreach ($users as $google_user) {
            if ($imported >= $limit) break;
            $primary_email = strtolower(trim((string) ($google_user['primaryEmail'] ?? '')));
            if (!is_email($primary_email)) continue;
            $google_id = (string) ($google_user['id'] ?? '');
            $first_name = (string) ($google_user['name']['givenName'] ?? '');
            $last_name = (string) ($google_user['name']['familyName'] ?? '');
            $display_name = (string) ($google_user['name']['fullName'] ?? '');
            if ($display_name === '') $display_name = trim($first_name . ' ' . $last_name);
            if ($display_name === '') $display_name = $primary_email;
            $org_unit_path = (string) ($google_user['orgUnitPath'] ?? '/');
            if ($org_unit_path === '') $org_unit_path = '/';
            $recovery_email = strtolower(trim((string) ($google_user['recoveryEmail'] ?? '')));
            if (!is_email($recovery_email)) $recovery_email = '';
            $is_suspended = !empty($google_user['suspended']) ? 1 : 0;

            $person_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$people_table}
                 WHERE workspace_email = %s OR email = %s
                 ORDER BY id ASC
                 LIMIT 1",
                $primary_email,
                $primary_email
            ));
            if ($person_id > 0) $linked++;

            $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$users_table} WHERE primary_email = %s LIMIT 1",
                $primary_email
            ));
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
                $ok = $wpdb->update($users_table, $payload, ['id' => $existing_id], $fmt, ['%d']);
                if ($ok !== false) {
                    $updated++;
                    $local_workspace_user_id = $existing_id;
                } else {
                    $local_workspace_user_id = 0;
                }
            } else {
                $ok = $wpdb->insert($users_table, $payload, $fmt);
                if ($ok) {
                    $created++;
                    $local_workspace_user_id = (int) $wpdb->insert_id;
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
                $role_sync_error = (string) ($roles_resp['error'] ?? 'Failed to fetch Workspace roles.');
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

                $existing_role_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$roles_table} WHERE role_domain = 'workspace' AND role_key = %s LIMIT 1",
                    $role_key
                ));
                if ($existing_role_id > 0) {
                    $wpdb->update(
                        $roles_table,
                        ['role_name' => $role_label, 'description' => $google_role_description !== '' ? $google_role_description : null, 'is_system' => 1],
                        ['id' => $existing_role_id],
                        ['%s', '%s', '%d'],
                        ['%d']
                    );
                } else {
                    $wpdb->insert($roles_table, [
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
                    $role_sync_error = (string) ($assign_resp['error'] ?? 'Failed to fetch Workspace role assignments.');
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
                    $wpdb->delete($user_roles_table, ['workspace_user_id' => $local_workspace_user_id], ['%d']);
                    $role_keys = array_keys((array) ($assignments_by_workspace_user[$local_workspace_user_id] ?? []));
                    foreach ($role_keys as $role_key) {
                        $inserted = $wpdb->insert($user_roles_table, [
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
        $workspace_user_email_rows = $wpdb->get_results(
            "SELECT id, primary_email FROM {$users_table} WHERE primary_email IS NOT NULL AND primary_email <> ''",
            ARRAY_A
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
                $group_sync_error = (string) ($group_resp['error'] ?? 'Failed to fetch groups from Workspace.');
                break;
            }
            $groups = (array) ($group_resp['body']['groups'] ?? []);
            if (empty($groups)) break;

            foreach ($groups as $group_row) {
                if ($groups_imported >= $groups_limit) break;
                $group_email = strtolower(trim((string) ($group_row['email'] ?? '')));
                if (!is_email($group_email)) continue;
                $seen_workspace_group_emails[$group_email] = true;
                $group_name = trim((string) ($group_row['name'] ?? ''));
                if ($group_name === '') $group_name = $group_email;
                $google_group_id = trim((string) ($group_row['id'] ?? ''));
                $description = trim((string) ($group_row['description'] ?? ''));

                $existing_group_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$groups_table} WHERE group_email = %s LIMIT 1",
                    $group_email
                ));
                $group_payload = [
                    'workspace_group_id' => $google_group_id !== '' ? $google_group_id : null,
                    'group_email' => $group_email,
                    'group_name' => $group_name,
                    'description' => $description !== '' ? $description : null,
                    'source' => 'workspace',
                    'sync_status' => 'synced',
                ];
                if ($existing_group_id > 0) {
                    $ok = $wpdb->update(
                        $groups_table,
                        $group_payload,
                        ['id' => $existing_group_id],
                        ['%s', '%s', '%s', '%s', '%s', '%s'],
                        ['%d']
                    );
                    $local_group_id = $existing_group_id;
                    if ($ok !== false) $groups_updated++;
                } else {
                    $ok = $wpdb->insert(
                        $groups_table,
                        $group_payload,
                        ['%s', '%s', '%s', '%s', '%s', '%s']
                    );
                    $local_group_id = $ok ? (int) $wpdb->insert_id : 0;
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

                    $wpdb->delete($group_members_table, ['group_id' => $local_group_id], ['%d']);
                    $inserted_members = 0;
                    foreach ($member_ids as $workspace_member_id => $member_role) {
                        if (!in_array($member_role, ['member', 'manager', 'owner'], true)) {
                            $member_role = 'member';
                        }
                        $inserted = $wpdb->insert($group_members_table, [
                            'group_id' => $local_group_id,
                            'workspace_user_id' => (int) $workspace_member_id,
                            'member_role' => $member_role,
                        ], ['%d', '%d', '%s']);
                        if ($inserted) $inserted_members++;
                    }
                    $group_members_synced += $inserted_members;
                    $wpdb->update(
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
            $existing_workspace_groups = $wpdb->get_results(
                "SELECT id, group_email
                 FROM {$groups_table}
                 WHERE source = 'workspace'
                    OR (workspace_group_id IS NOT NULL AND workspace_group_id <> '')",
                ARRAY_A
            ) ?: [];
            foreach ($existing_workspace_groups as $existing_group) {
                $existing_group_id = (int) ($existing_group['id'] ?? 0);
                $existing_group_email = strtolower(trim((string) ($existing_group['group_email'] ?? '')));
                if ($existing_group_id < 1 || $existing_group_email === '') continue;
                if (isset($seen_workspace_group_emails[$existing_group_email])) continue;
                $wpdb->delete($group_members_table, ['group_id' => $existing_group_id], ['%d']);
                $deleted_group = $wpdb->delete($groups_table, ['id' => $existing_group_id, 'source' => 'workspace'], ['%d', '%s']);
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

metis_add_action('wp_ajax_metis_people_workspace_import_directory_users', function () {
    metis_people_workspace_ajax_verify();
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_send_json_error((string) ($cfg['error'] ?? 'Workspace configuration is missing.'), 400);
    }
    $limit = isset($_POST['limit']) ? (int) metis_unslash($_POST['limit']) : 500;
    $result = metis_people_workspace_import_directory_snapshot($cfg, $limit, false, 0);
    if (empty($result['ok'])) {
        metis_send_json_error((string) ($result['error'] ?? 'Import failed.'), 400);
    }
    unset($result['ok']);
    metis_send_json_success($result);
});

metis_add_action('wp_ajax_metis_people_workspace_full_sync_directory', function () {
    metis_people_workspace_ajax_verify();
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_send_json_error((string) ($cfg['error'] ?? 'Workspace configuration is missing.'), 400);
    }
    $user_limit = isset($_POST['user_limit']) ? (int) metis_unslash($_POST['user_limit']) : 800;
    $group_limit = isset($_POST['group_limit']) ? (int) metis_unslash($_POST['group_limit']) : 400;
    $result = metis_people_workspace_import_directory_snapshot($cfg, $user_limit, true, $group_limit);
    if (empty($result['ok'])) {
        metis_send_json_error((string) ($result['error'] ?? 'Full sync failed.'), 400);
    }
    unset($result['ok']);
    metis_send_json_success($result);
});

metis_add_action('wp_ajax_metis_people_workspace_get_role_map', function () {
    metis_people_workspace_ajax_verify();
    global $wpdb;
    $roles_table = Metis_Tables::get('people_roles');
    $workspace_users_table = Metis_Tables::get('people_workspace_users');
    $workspace_user_roles_table = Metis_Tables::get('people_workspace_user_roles');

    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_send_json_error((string) ($cfg['error'] ?? 'Workspace configuration is missing.'), 400);
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
            metis_send_json_error((string) ($resp['error'] ?? 'Failed to fetch workspace roles.'), 400);
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

        $existing_role_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$roles_table} WHERE role_domain = 'workspace' AND role_key = %s LIMIT 1",
            $metis_role_key
        ));
        if ($existing_role_id > 0) {
            $wpdb->update(
                $roles_table,
                ['role_name' => $friendly_name, 'description' => $google_role_description !== '' ? $google_role_description : null, 'is_system' => 1],
                ['id' => $existing_role_id],
                ['%s', '%s', '%d'],
                ['%d']
            );
        } else {
            $wpdb->insert($roles_table, [
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
    $user_rows = $wpdb->get_results("SELECT id, workspace_user_id FROM {$workspace_users_table} WHERE workspace_user_id IS NOT NULL AND workspace_user_id <> ''", ARRAY_A) ?: [];
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
            $wpdb->delete($workspace_user_roles_table, ['workspace_user_id' => $local_user_id], ['%d']);
            $role_keys = array_keys((array) ($assignments_by_local_user[$local_user_id] ?? []));
            foreach ($role_keys as $role_key) {
                if ($role_key === '') continue;
                $wpdb->insert($workspace_user_roles_table, [
                    'workspace_user_id' => $local_user_id,
                    'role_key' => $role_key,
                ], ['%d', '%s']);
            }
        }
    }

    metis_send_json_success([
        'roles' => $out,
        'total_roles' => count($out),
    ]);
});

metis_add_action('wp_ajax_metis_people_workspace_inspect_user_attributes', function () {
    metis_people_workspace_ajax_verify();
    $email = strtolower(trim((string) (isset($_POST['email']) ? sanitize_email(metis_unslash($_POST['email'])) : '')));
    if (!is_email($email)) {
        metis_send_json_error('A valid user email is required.', 400);
    }
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_send_json_error((string) ($cfg['error'] ?? 'Workspace configuration is missing.'), 400);
    }
    $user_resp = metis_people_workspace_google_request('GET', 'users/' . rawurlencode($email) . '?projection=full', null, $cfg);
    if (empty($user_resp['ok'])) {
        metis_send_json_error((string) ($user_resp['error'] ?? 'Failed to load workspace user.'), 400);
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
        $schema_resp_data['error'] = (string) ($schema_resp['error'] ?? 'Schema read failed.');
    }

    metis_send_json_success([
        'email' => $email,
        'customSchemas' => $custom_schemas,
        'schemaDirectory' => $schema_resp_data,
    ]);
});
