<?php
if (!defined('METIS_ROOT')) exit;

require_once dirname( __DIR__, 2 ) . '/portal/views/_dashboard_data.php';

function metis_people_ajax_request_input(): array {
    return function_exists( 'metis_request_post' ) ? (array) metis_request_post() : [];
}

function metis_people_ajax_request_action( array $input = [] ): string {
    $input = $input !== [] ? $input : metis_people_ajax_request_input();
    return metis_key_clean( (string) ( $input['metis_action'] ?? $input['action'] ?? '' ) );
}

function metis_people_ajax_permission_for_action( string $action, string $fallback = 'edit' ): string {
    $action = metis_key_clean( $action );
    if ( $action === '' ) {
        return metis_key_clean( $fallback ) ?: 'edit';
    }

    if (
        str_starts_with( $action, 'metis_people_workspace_' )
        || str_starts_with( $action, 'metis_people_bulk_workspace_' )
        || in_array( $action, [
            'metis_people_attach_drive_folder',
            'metis_people_drive_folder_picker',
            'metis_people_attach_drive_folder_selection',
        ], true )
    ) {
        return 'workspace_manage';
    }

    if ( in_array( $action, [
        'metis_people_get_positions',
        'metis_people_search_person',
        'metis_people_search_donor',
        'metis_people_get_activity_page',
        'metis_people_create_access_request',
    ], true ) ) {
        return 'view';
    }

    if ( in_array( $action, [
        'metis_people_delete_position',
        'metis_people_delete_document',
    ], true ) ) {
        return 'delete';
    }

    return metis_key_clean( $fallback ) ?: 'edit';
}

function metis_people_ajax_require( string $fallback_permission = 'edit' ): void {
    metis_people_ensure_schema();
    metis_people_seed_permissions_and_roles();

    $input = metis_people_ajax_request_input();
    $action = metis_people_ajax_request_action( $input );
    $permission = metis_people_ajax_permission_for_action( $action, $fallback_permission );
    $nonce = (string) ( $input['metis_action_nonce'] ?? $input['nonce'] ?? '' );

    if ( ! function_exists( 'metis_user_logged_in' ) || ! metis_user_logged_in() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    if ( $action === '' || $nonce === '' || ! function_exists( 'metis_runtime_verify_nonce' ) || ! metis_runtime_verify_nonce( $nonce, metis_ajax_nonce_action( $action ) ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid request.' ], 403 );
    }

    if ( ! function_exists( 'metis_security_user_can' ) || ! metis_security_user_can( 'people.' . $permission ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
}

function metis_people_ajax_verify(): void {
    metis_people_ajax_require( 'edit' );
}

function metis_people_workspace_ajax_verify(): void {
    metis_people_ajax_require( 'workspace_manage' );
}

function metis_people_quick_action_person_form( array $action = [] ): array {
    unset( $action );

    $html = '<form class="metis-form-grid metis-quick-action-form" data-quick-action-form="people_add_person">'
        . '<div class="metis-field metis-field-half"><label for="qa-person-first-name">First Name</label><input id="qa-person-first-name" name="first_name" class="metis-input" type="text" required></div>'
        . '<div class="metis-field metis-field-half"><label for="qa-person-last-name">Last Name</label><input id="qa-person-last-name" name="last_name" class="metis-input" type="text" required></div>'
        . '<div class="metis-field metis-field-full"><label for="qa-person-email">Email</label><input id="qa-person-email" name="email" class="metis-input" type="email" required></div>'
        . '<input type="hidden" name="status" value="active">'
        . '<input type="hidden" name="lifecycle_status" value="active">'
        . '</form>';

    return [
        'title' => 'Add Person',
        'html' => $html,
        'submit_action' => 'metis_people_save_person',
        'submit_nonce_action' => function_exists( 'metis_ajax_nonce_action' ) ? metis_ajax_nonce_action( 'metis_people_save_person' ) : 'metis_people_save_person',
        'submit_label' => 'Create Person',
        'success_message' => 'Person created.',
        'redirect' => function_exists( 'metis_portal_url' ) ? (string) metis_portal_url( 'people', 'people_list' ) : '',
    ];
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $metis_people_actions = [
        'metis_people_save_person' => 'edit',
        'metis_people_save_avatar' => 'edit',
        'metis_people_offboard_person' => 'edit',
        'metis_people_get_positions' => 'view',
        'metis_people_save_position' => 'edit',
        'metis_people_delete_position' => 'delete',
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
        $auth_key = defined('AUTH_KEY') ? (string) AUTH_KEY : metis_runtime_require_app_key('people MFA secret encryption');
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
    return \Metis\Modules\People\PersonIdentityService::consumeChallenge($challenge_key, $purpose, $person_id);
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
    return \Metis\Modules\People\PersonIdentityService::resolvePersonRecord($person_id, $pid);
}

function metis_people_active_permission_keys_for_person(int $person_id): array {
    if ($person_id < 1) return [];
    return \Metis\Modules\People\AccessManager::activePermissionKeysForPerson($person_id);
}

function metis_people_workspace_queue_job(string $job_type, string $entity_type, ?int $entity_id, ?int $requested_by_person_id, array $payload = []): int {
    return \Metis\Modules\People\WorkspaceSyncJobService::queueJob($job_type, $entity_type, $entity_id, $requested_by_person_id, $payload);
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
    $delete = metis_people_workspace_google_request('delete', 'groups/' . rawurlencode($group_email) . '/members/' . rawurlencode($member_email), null, $cfg);
    if (!empty($delete['ok']) || ((int) ($delete['status'] ?? 0) === 404)) {
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'Failed to remove member from Stripe access group.'];
}

function metis_people_workspace_execute_job(array $job, array $cfg, bool $dry_run = false): array {
    return \Metis\Modules\People\WorkspaceSyncJobService::executeJob($job, $cfg, $dry_run);
}

function metis_people_workspace_process_jobs(int $limit = 10, bool $dry_run = false, int $specific_job_id = 0): array {
    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok']) && !$dry_run) {
        return ['processed' => 0, 'completed' => 0, 'failed' => 0, 'error' => 'Workspace configuration missing.'];
    }
    return \Metis\Modules\People\WorkspaceSyncJobService::processJobs($cfg, $limit, $dry_run, $specific_job_id);
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
    $rows = \Metis\Modules\People\PositionService::activePositions();
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

    $group_key = isset( metis_request_post()['group_key'] ) ? metis_people_normalize_position_group( (string) metis_runtime_unslash( metis_request_post()['group_key'] ) ) : '';
    $position_label = isset( metis_request_post()['position_label'] ) ? metis_text_clean( (string) metis_runtime_unslash( metis_request_post()['position_label'] ) ) : '';
    $sort_order = isset( metis_request_post()['sort_order'] ) ? (int) metis_runtime_unslash( metis_request_post()['sort_order'] ) : 0;
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

    $saved = \Metis\Modules\People\PositionService::savePosition( $group_key, $position_key, $position_label, $sort_order );
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
    $position_id = isset( metis_request_post()['position_id'] ) ? (int) metis_runtime_unslash( metis_request_post()['position_id'] ) : 0;
    if ( $position_id < 1 ) {
        metis_runtime_send_json_error( 'Position id is required.', 400 );
    }
    \Metis\Modules\People\PositionService::deactivatePosition( $position_id );
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
    $person_id = isset(metis_request_post()['person_id']) ? (int) metis_runtime_unslash(metis_request_post()['person_id']) : 0;
    $pid = isset(metis_request_post()['pid']) ? trim(metis_text_clean(metis_runtime_unslash(metis_request_post()['pid']))) : '';
    $first_name = isset(metis_request_post()['first_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['first_name'])) : '';
    $last_name = isset(metis_request_post()['last_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['last_name'])) : '';
    $display_name = isset(metis_request_post()['display_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['display_name'])) : '';
    $email = isset(metis_request_post()['email']) ? metis_email_clean(metis_runtime_unslash(metis_request_post()['email'])) : '';
    $auth_provider = isset(metis_request_post()['auth_provider']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['auth_provider'])) : 'metis';
    $is_workspace_user = !empty(metis_request_post()['is_workspace_user']) ? 1 : 0;
    $workspace_email = isset(metis_request_post()['workspace_email']) ? metis_email_clean(metis_runtime_unslash(metis_request_post()['workspace_email'])) : '';
    $workspace_role = isset(metis_request_post()['workspace_role']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['workspace_role'])) : '';
    $workspace_is_protected = !empty(metis_request_post()['workspace_is_protected']) ? 1 : 0;
    $workspace_groups_json = isset(metis_request_post()['workspace_groups_json']) ? (string) metis_runtime_unslash(metis_request_post()['workspace_groups_json']) : '[]';
    $stripe_role = isset(metis_request_post()['stripe_role']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['stripe_role'])) : '';
    $linked_donor_id_raw = isset(metis_request_post()['linked_donor_id']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['linked_donor_id'])) : '';
    $manager_pid = isset(metis_request_post()['manager_pid']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['manager_pid'])) : '';
    $department = isset(metis_request_post()['department']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['department'])) : '';
    $board_term_start = isset(metis_request_post()['board_term_start']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['board_term_start'])) : '';
    $board_term_end = isset(metis_request_post()['board_term_end']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['board_term_end'])) : '';
    $volunteer_area = isset(metis_request_post()['volunteer_area']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['volunteer_area'])) : '';
    $public_slug = isset(metis_request_post()['public_slug']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['public_slug'])) : '';
    $public_tagline = isset(metis_request_post()['public_tagline']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['public_tagline'])) : '';
    $public_visibility = isset(metis_request_post()['public_visibility']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['public_visibility'])) : 'private';
    $public_sort_order = isset(metis_request_post()['public_sort_order']) ? (int) metis_runtime_unslash(metis_request_post()['public_sort_order']) : 0;
    $public_bio_html = isset(metis_request_post()['public_bio_html']) ? (string) metis_runtime_unslash(metis_request_post()['public_bio_html']) : '';
    $lifecycle_status = isset(metis_request_post()['lifecycle_status']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['lifecycle_status'])) : 'active';
    $email_notifications = isset(metis_request_post()['email_notifications']) ? (!empty(metis_request_post()['email_notifications']) ? 1 : 0) : 1;
    $sms_notifications = 0;
    $requires_2fa = !empty(metis_request_post()['requires_2fa']) ? 1 : 0;
    $mfa_method = isset(metis_request_post()['mfa_method']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['mfa_method'])) : 'none';
    $is_staff = !empty(metis_request_post()['is_staff']) ? 1 : 0;
    $is_board = !empty(metis_request_post()['is_board']) ? 1 : 0;
    $board_position = isset(metis_request_post()['board_position']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['board_position'])) : '';
    $staff_position = isset(metis_request_post()['staff_position']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['staff_position'])) : '';
    $volunteer_position = isset(metis_request_post()['volunteer_position']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['volunteer_position'])) : '';
    $is_volunteer = !empty(metis_request_post()['is_volunteer']) ? 1 : 0;
    $status = isset(metis_request_post()['status']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['status'])) : 'active';
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
    if (isset(metis_request_post()['roles'])) {
        $decoded_roles = json_decode((string) metis_runtime_unslash(metis_request_post()['roles']), true);
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
    if (isset(metis_request_post()['role_windows'])) {
        $decoded_windows = json_decode((string) metis_runtime_unslash(metis_request_post()['role_windows']), true);
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
    if (isset(metis_request_post()['notification_prefs_json'])) {
        $decoded_notify = json_decode((string) metis_runtime_unslash(metis_request_post()['notification_prefs_json']), true);
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
    $actor = metis_people_get_current_person_id();
    $save_result = \Metis\Modules\People\PersonProfileService::saveProfile([
        'person_id' => $person_id,
        'auth_provider' => $auth_provider,
        'email' => $email,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => $display_name,
        'linked_donor_id' => $linked_donor_id,
        'is_workspace_user' => $is_workspace_user,
        'workspace_email' => $workspace_email,
        'workspace_role' => $workspace_role,
        'workspace_is_protected' => $workspace_is_protected,
        'stripe_role' => $stripe_role,
        'manager_pid' => $manager_pid,
        'department' => $department,
        'board_term_start' => $board_term_start,
        'board_term_end' => $board_term_end,
        'volunteer_area' => $volunteer_area,
        'public_slug' => $public_slug,
        'public_tagline' => $public_tagline,
        'public_visibility' => $public_visibility,
        'public_sort_order' => $public_sort_order,
        'public_bio_html' => $public_bio_html,
        'lifecycle_status' => $lifecycle_status,
        'email_notifications' => $email_notifications,
        'sms_notifications' => $sms_notifications,
        'notification_prefs_json' => $notification_prefs_json,
        'requires_2fa' => $requires_2fa,
        'mfa_method' => $mfa_method,
        'is_staff' => $is_staff,
        'is_board' => $is_board,
        'board_position' => $board_position,
        'staff_position' => $staff_position,
        'is_volunteer' => $is_volunteer,
        'volunteer_position' => $volunteer_position,
        'status' => $status,
    ], $roles, $role_windows, $workspace_group_emails, $actor > 0 ? $actor : null);
    $person_id = (int) ($save_result['person_id'] ?? 0);
    $person_pid = (string) ($save_result['pid'] ?? '');
    metis_people_log_activity($person_id, 'person_saved', 'Updated person profile', [
        'pid' => $person_pid,
        'status' => (string) ($save_result['status'] ?? $status),
        'lifecycle_status' => (string) ($save_result['lifecycle_status'] ?? $lifecycle_status),
    ]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'person_id' => $person_id,
        'pid' => $person_pid,
        'workspace_groups_count' => (int) ($save_result['workspace_groups_count'] ?? 0),
        'drive_folder' => $save_result['drive_folder'] ?? null,
    ]);
});


metis_ajax_register_handler( 'metis_people_save_avatar', function () {
    metis_people_ajax_verify();
    $people_table = Metis_Tables::get('people');
    $person_id = isset(metis_request_post()['person_id']) ? (int) metis_runtime_unslash(metis_request_post()['person_id']) : 0;
    $pid = isset(metis_request_post()['pid']) ? trim(metis_text_clean(metis_runtime_unslash(metis_request_post()['pid']))) : '';
    $base64 = isset(metis_request_post()['avatar_base64']) ? (string) metis_runtime_unslash(metis_request_post()['avatar_base64']) : '';
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
    $pid = isset(metis_request_post()['pid']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['pid'])) : '';
    if ($pid === '') {
        metis_runtime_send_json_error('PID is required.', 400);
    }
    $actor = metis_people_get_current_person_id();
    $offboard_result = \Metis\Modules\People\PersonProfileService::offboardByPid($pid, $actor > 0 ? $actor : null);
    $person_id = (int) ($offboard_result['person_id'] ?? 0);
    $pid = (string) ($offboard_result['pid'] ?? $pid);
    metis_people_log_activity($person_id, 'offboarded', 'Ran offboarding checklist', ['pid' => $pid]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['pid' => $pid]);
});

metis_ajax_register_handler( 'metis_people_add_document', function () {
    metis_people_ajax_verify();
    $pid = isset(metis_request_post()['pid']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['pid'])) : '';
    $doc_type = isset(metis_request_post()['doc_type']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['doc_type'])) : '';
    $doc_label = isset(metis_request_post()['doc_label']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['doc_label'])) : '';
    $storage_ref = isset(metis_request_post()['storage_ref']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['storage_ref'])) : '';
    $remind_at = isset(metis_request_post()['remind_at']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['remind_at'])) : '';
    $expires_at = isset(metis_request_post()['expires_at']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['expires_at'])) : '';
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
    $actor = metis_people_get_current_person_id();
    $document = \Metis\Modules\People\DirectorySupportService::addDocument($pid, $doc_type, $doc_label, $storage_ref, $remind_at, $expires_at, $actor > 0 ? $actor : null);
    $person_id = (int) ($document['person_id'] ?? 0);
    metis_people_log_activity($person_id, 'document_added', 'Added document reference', ['doc_type' => $doc_type, 'doc_label' => $doc_label]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success([
        'ok' => 1,
        'doc_id' => (int) ($document['doc_id'] ?? 0),
        'row' => (array) ($document['row'] ?? []),
    ]);
});

metis_ajax_register_handler( 'metis_people_grant_emergency_access', function () {
    metis_people_ajax_verify();

    $pid = isset(metis_request_post()['pid']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['pid'])) : '';
    $role_key = isset(metis_request_post()['role_key']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['role_key'])) : '';
    $hours = isset(metis_request_post()['hours']) ? (int) metis_runtime_unslash(metis_request_post()['hours']) : 4;
    $reason = isset(metis_request_post()['reason']) ? metis_textarea_clean(metis_runtime_unslash(metis_request_post()['reason'])) : '';
    if ($pid === '' || $role_key === '') {
        metis_runtime_send_json_error('PID and role key are required.', 400);
    }
    if ($hours < 1) $hours = 1;
    if ($hours > 72) $hours = 72;
    $actor = metis_people_get_current_person_id();
    $person_id = \Metis\Modules\People\DirectorySupportService::grantEmergencyAccess($pid, $role_key, $hours, $reason, $actor > 0 ? $actor : null);
    metis_people_log_activity($person_id, 'emergency_access_granted', 'Granted emergency access', ['role_key' => $role_key, 'hours' => $hours]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['ok' => 1]);
});

metis_ajax_register_handler( 'metis_people_delete_document', function () {
    metis_people_ajax_verify();
    $doc_id = isset(metis_request_post()['doc_id']) ? (int) metis_runtime_unslash(metis_request_post()['doc_id']) : 0;
    if ($doc_id < 1) {
        metis_runtime_send_json_error('Invalid document id.', 400);
    }
    $doc = \Metis\Modules\People\DirectorySupportService::deleteDocument($doc_id);
    metis_people_log_activity((int) $doc['person_id'], 'document_deleted', 'Deleted document reference', ['doc_label' => (string) ($doc['doc_label'] ?? '')]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['ok' => 1]);
});

metis_ajax_register_handler( 'metis_people_revoke_emergency_access', function () {
    metis_people_ajax_verify();
    $entry_id = isset(metis_request_post()['entry_id']) ? (int) metis_runtime_unslash(metis_request_post()['entry_id']) : 0;
    if ($entry_id < 1) {
        metis_runtime_send_json_error('Invalid emergency entry id.', 400);
    }
    $entry = \Metis\Modules\People\DirectorySupportService::revokeEmergencyAccess($entry_id);
    metis_people_log_activity((int) $entry['person_id'], 'emergency_access_revoked', 'Revoked emergency access', ['entry_id' => $entry_id]);
    metis_portal_dashboard_forget_all();
    metis_runtime_send_json_success(['ok' => 1]);
});




metis_ajax_register_handler( 'metis_people_search_person', function () {
    $q = isset(metis_request_post()['q']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['q'])) : '';
    $q = trim($q);
    if ($q === '') {
        metis_runtime_send_json_success(['people' => []]);
    }

    $rows = \Metis\Modules\People\DirectorySupportService::searchPeople($q);

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
    $q = isset(metis_request_post()['q']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['q'])) : '';
    $q = trim($q);
    if ($q === '') {
        metis_runtime_send_json_success(['donors' => []]);
    }

    $rows = \Metis\Modules\People\DirectorySupportService::searchDonors($q);

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
        return \Metis\Modules\People\DirectorySupportService::activityPage($page, $page_size, $query);
    }
}

metis_ajax_register_handler( 'metis_people_get_activity_page', function () {
    $page = isset(metis_request_post()['page']) ? (int) metis_runtime_unslash(metis_request_post()['page']) : 1;
    $query = isset(metis_request_post()['q']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['q'])) : '';
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
