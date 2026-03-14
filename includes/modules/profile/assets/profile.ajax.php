<?php
if (!defined('ABSPATH')) exit;

function metis_profile_ajax_verify(): void {
    check_ajax_referer('metis_profile', 'nonce');
    if (!metis_user_logged_in()) {
        metis_send_json_error('Unauthorized', 403);
    }
    if (function_exists('metis_people_ensure_schema')) {
        metis_people_ensure_schema();
    }
    if (function_exists('metis_people_seed_permissions_and_roles')) {
        metis_people_seed_permissions_and_roles();
    }
}

function metis_profile_current_person(): ?array {
    if (!metis_user_logged_in()) {
        return null;
    }

    if (function_exists('metis_auth_current_person_id')) {
        $person_id = (int) metis_auth_current_person_id();
        if ($person_id > 0) {
            global $wpdb;
            $people_table = Metis_Tables::get('people');
            $person = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1",
                $person_id
            ), ARRAY_A);
            if (is_array($person)) {
                return $person;
            }
        }
    }

    $person_id = function_exists('metis_people_get_current_person_id') ? (int) metis_people_get_current_person_id() : 0;
    if ($person_id > 0) {
        global $wpdb;
        $people_table = Metis_Tables::get('people');
        $person = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1",
            $person_id
        ), ARRAY_A);
        if (is_array($person)) {
            return $person;
        }
    }

    return null;
}

function metis_profile_person_payload(array $person): array {
    global $wpdb;

    $passkeys = [];
    if (Metis_Tables::has('people_passkeys')) {
        $passkeys_table = Metis_Tables::get('people_passkeys');
        $passkeys = $wpdb->get_results($wpdb->prepare(
            "SELECT id, label, created_at, last_used_at
             FROM {$passkeys_table}
             WHERE person_id = %d AND revoked_at IS NULL
             ORDER BY created_at DESC",
            (int) ($person['id'] ?? 0)
        ), ARRAY_A) ?: [];
    }

    $notification_prefs = [];
    if (!empty($person['notification_prefs_json'])) {
        $decoded = json_decode((string) $person['notification_prefs_json'], true);
        if (is_array($decoded)) {
            $notification_prefs = $decoded;
        }
    }

    $avatar_src = '';
    if (!empty($person['avatar_url'])) {
        $avatar_src = (string) $person['avatar_url'];
    } else {
        $avatar_src = metis_avatar_fallback_url((string) ($person['email'] ?? ''), 160);
    }

    return [
        'id' => (int) ($person['id'] ?? 0),
        'pid' => (string) ($person['pid'] ?? ''),
        'first_name' => (string) ($person['first_name'] ?? ''),
        'last_name' => (string) ($person['last_name'] ?? ''),
        'display_name' => (string) ($person['display_name'] ?? ''),
        'email' => (string) ($person['email'] ?? ''),
        'auth_provider' => (string) ($person['auth_provider'] ?? 'metis'),
        'department' => (string) ($person['department'] ?? ''),
        'manager_pid' => (string) ($person['manager_pid'] ?? ''),
        'lifecycle_status' => (string) ($person['lifecycle_status'] ?? 'active'),
        'email_notifications' => !isset($person['email_notifications']) || (int) $person['email_notifications'] === 1,
        'requires_2fa' => !empty($person['requires_2fa']),
        'mfa_method' => (string) ($person['mfa_method'] ?? 'none'),
        'totp_enabled' => !empty($person['totp_enabled']),
        'passkey_enabled' => !empty($person['passkey_enabled']),
        'passkeys' => $passkeys,
        'notification_prefs' => $notification_prefs,
        'avatar_url' => $avatar_src,
        'updated_at' => (string) ($person['updated_at'] ?? ''),
    ];
}

metis_add_action('wp_ajax_metis_profile_get', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_send_json_error('Profile not found.', 404);
    }

    metis_send_json_success([
        'person' => metis_profile_person_payload($person),
    ]);
});

metis_add_action('wp_ajax_metis_profile_save', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_send_json_error('Profile not found.', 404);
    }

    $first_name = isset($_POST['first_name']) ? sanitize_text_field(metis_unslash($_POST['first_name'])) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field(metis_unslash($_POST['last_name'])) : '';
    $display_name = isset($_POST['display_name']) ? sanitize_text_field(metis_unslash($_POST['display_name'])) : '';
    $email_notifications = !empty($_POST['email_notifications']) ? 1 : 0;
    $requires_2fa = !empty($_POST['requires_2fa']) ? 1 : 0;
    $mfa_method = isset($_POST['mfa_method']) ? sanitize_key(metis_unslash($_POST['mfa_method'])) : 'none';
    $allow_name_edit = (int) Core_Settings_Service::get('profile_allow_name_edit', 0) === 1;

    if (!in_array($mfa_method, ['none', 'totp', 'passkey', 'passkey_or_totp', 'passkey_and_totp'], true)) {
        $mfa_method = (string) ($person['mfa_method'] ?? 'none');
    }

    if (!$allow_name_edit) {
        $first_name = (string) ($person['first_name'] ?? '');
        $last_name = (string) ($person['last_name'] ?? '');
        $display_name = (string) ($person['display_name'] ?? '');
    } elseif ($display_name === '') {
        $display_name = trim($first_name . ' ' . $last_name);
    }

    if ($display_name === '') {
        metis_send_json_error('Display name is required.', 400);
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

    global $wpdb;
    $people_table = Metis_Tables::get('people');

    $ok = $wpdb->update(
        $people_table,
        [
            'first_name' => $first_name !== '' ? $first_name : null,
            'last_name' => $last_name !== '' ? $last_name : null,
            'display_name' => $display_name,
            'email_notifications' => $email_notifications,
            'requires_2fa' => $requires_2fa,
            'mfa_method' => $mfa_method,
            'notification_prefs_json' => $notification_prefs_json,
            'updated_at' => current_time('mysql'),
        ],
        ['id' => (int) $person['id']],
        ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s'],
        ['%d']
    );

    if ($ok === false) {
        metis_send_json_error('Failed to save profile.', 500);
    }

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) $person['id'], 'profile_saved', 'Updated self profile settings', []);
    }

    $updated = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$people_table} WHERE id = %d LIMIT 1",
        (int) $person['id']
    ), ARRAY_A);

    metis_send_json_success([
        'person' => metis_profile_person_payload($updated ?: $person),
    ]);
});

metis_add_action('wp_ajax_metis_profile_change_workspace_password', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_send_json_error('Profile not found.', 404);
    }

    $new_password = isset($_POST['new_password']) ? (string) metis_unslash($_POST['new_password']) : '';
    $confirm_password = isset($_POST['confirm_password']) ? (string) metis_unslash($_POST['confirm_password']) : '';
    if (strlen($new_password) < 12) {
        metis_send_json_error('Password must be at least 12 characters.', 400);
    }
    if (!hash_equals($new_password, $confirm_password)) {
        metis_send_json_error('Password confirmation does not match.', 400);
    }

    $workspace_email = strtolower(trim((string) ($person['workspace_email'] ?? '')));
    if (!is_email($workspace_email) && !empty($person['is_workspace_user'])) {
        $workspace_email = strtolower(trim((string) ($person['email'] ?? '')));
    }
    if (!is_email($workspace_email)) {
        metis_send_json_error('No linked Workspace account found for this profile.', 400);
    }

    if (!function_exists('metis_people_workspace_sync_settings') || !function_exists('metis_people_workspace_google_request')) {
        metis_send_json_error('Workspace integration is not available.', 500);
    }

    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_send_json_error((string) ($cfg['error'] ?? 'Workspace is not configured.'), 500);
    }

    $resp = metis_people_workspace_google_request('PUT', 'users/' . rawurlencode($workspace_email), [
        'password' => $new_password,
        'changePasswordAtNextLogin' => false,
    ], $cfg);

    if (empty($resp['ok'])) {
        metis_send_json_error((string) ($resp['error'] ?? 'Workspace password update failed.'), 500);
    }

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) ($person['id'] ?? 0), 'workspace_password_changed_self', 'Changed own Workspace password', [
            'workspace_email' => $workspace_email,
        ]);
    }

    metis_send_json_success(['ok' => 1]);
});

metis_add_action('wp_ajax_metis_profile_save_avatar', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_send_json_error('Profile not found.', 404);
    }

    $base64 = isset($_POST['avatar_base64']) ? (string) metis_unslash($_POST['avatar_base64']) : '';
    if ($base64 === '') {
        metis_send_json_error('Image data is required.', 400);
    }
    if (!preg_match('/^data:image\/(png|jpeg);base64,/', $base64)) {
        metis_send_json_error('Invalid image format.', 400);
    }

    $raw = preg_replace('/^data:image\/(png|jpeg);base64,/', '', $base64);
    $bin = base64_decode((string) $raw, true);
    if ($bin === false || strlen($bin) < 100) {
        metis_send_json_error('Invalid image payload.', 400);
    }

    $upload = metis_store_upload_bits(
        'profile-avatar-' . (int) $person['id'] . '-' . time(),
        $bin,
        [
            'png' => 'image/png',
            'jpg|jpeg' => 'image/jpeg',
        ]
    );
    if (!empty($upload['error'])) {
        metis_send_json_error('Failed to store image.', 500);
    }

    $avatar_url = isset($upload['url']) ? esc_url_raw((string) $upload['url']) : '';
    if ($avatar_url === '') {
        metis_send_json_error('Image URL unavailable.', 500);
    }

    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $wpdb->update($people_table, ['avatar_url' => $avatar_url], ['id' => (int) $person['id']], ['%s'], ['%d']);

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) $person['id'], 'avatar_updated', 'Updated self profile photo', []);
    }

    metis_send_json_success(['avatar_url' => $avatar_url]);
});

metis_add_action('wp_ajax_metis_profile_generate_totp_secret', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_send_json_error('Profile not found.', 404);
    }

    if (!function_exists('metis_people_totp_generate_secret')) {
        metis_send_json_error('TOTP service unavailable.', 500);
    }

    $label = trim((string) ($person['display_name'] ?? ''));
    if ($label === '') {
        $label = (string) ($person['email'] ?? '');
    }
    $issuer = 'Metis';
    $secret = metis_people_totp_generate_secret(32);
    $uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';

    metis_send_json_success([
        'secret' => $secret,
        'provisioning_uri' => $uri,
    ]);
});

metis_add_action('wp_ajax_metis_profile_verify_totp_secret', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_send_json_error('Profile not found.', 404);
    }

    if (!function_exists('metis_people_totp_now') || !function_exists('metis_people_encrypt_secret')) {
        metis_send_json_error('TOTP service unavailable.', 500);
    }

    $secret = isset($_POST['secret']) ? strtoupper(sanitize_text_field(metis_unslash($_POST['secret']))) : '';
    $code = isset($_POST['code']) ? preg_replace('/\D+/', '', (string) metis_unslash($_POST['code'])) : '';
    if ($secret === '' || strlen((string) $code) !== 6) {
        metis_send_json_error('Secret and 6-digit code are required.', 400);
    }

    $valid = false;
    $now = time();
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(metis_people_totp_now($secret, 30, 6, $now + ($i * 30)), (string) $code)) {
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

    global $wpdb;
    $people_table = Metis_Tables::get('people');
    $wpdb->update(
        $people_table,
        [
            'totp_secret_enc' => $enc,
            'totp_enabled' => 1,
            'requires_2fa' => 1,
            'mfa_method' => 'totp',
        ],
        ['id' => (int) $person['id']],
        ['%s', '%d', '%d', '%s'],
        ['%d']
    );

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) $person['id'], 'totp_enabled', 'Enabled authenticator app MFA (self)', []);
    }

    metis_send_json_success(['ok' => 1]);
});

metis_add_action('wp_ajax_metis_profile_begin_passkey_registration', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_send_json_error('Profile not found.', 404);
    }

    if (!function_exists('metis_people_create_challenge') || !function_exists('metis_people_b64url_encode')) {
        metis_send_json_error('Passkey service unavailable.', 500);
    }

    global $wpdb;
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $person_id = (int) $person['id'];

    $challenge = metis_people_create_challenge($person_id, 'passkey_register', 600);
    $exclude_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT credential_id FROM {$passkeys_table} WHERE person_id = %d AND revoked_at IS NULL",
        $person_id
    )) ?: [];

    $exclude = [];
    foreach ($exclude_ids as $credential_id) {
        $exclude[] = [
            'id' => (string) $credential_id,
            'type' => 'public-key',
        ];
    }

    $display_name = trim((string) ($person['display_name'] ?? ''));
    if ($display_name === '') {
        $display_name = (string) ($person['email'] ?? '');
    }

    $user_handle = metis_people_b64url_encode('metis-person-' . $person_id);

    metis_send_json_success([
        'challenge_key' => (string) ($challenge['challenge_key'] ?? ''),
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
            'challenge' => (string) ($challenge['challenge_value'] ?? ''),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'excludeCredentials' => $exclude,
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
        ],
    ]);
});

metis_add_action('wp_ajax_metis_profile_complete_passkey_registration', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_send_json_error('Profile not found.', 404);
    }

    if (!function_exists('metis_people_consume_challenge') || !function_exists('metis_people_origin_allowed')) {
        metis_send_json_error('Passkey service unavailable.', 500);
    }

    $challenge_key = isset($_POST['challenge_key']) ? sanitize_text_field(metis_unslash($_POST['challenge_key'])) : '';
    $credential_id = isset($_POST['credential_id']) ? sanitize_text_field(metis_unslash($_POST['credential_id'])) : '';
    $client_data_json = isset($_POST['client_data_json']) ? (string) metis_unslash($_POST['client_data_json']) : '';
    $attestation_object = isset($_POST['attestation_object']) ? (string) metis_unslash($_POST['attestation_object']) : '';
    $transports_json = isset($_POST['transports_json']) ? sanitize_text_field(metis_unslash($_POST['transports_json'])) : '';
    $label = isset($_POST['label']) ? sanitize_text_field(metis_unslash($_POST['label'])) : '';

    if ($challenge_key === '' || $credential_id === '' || $client_data_json === '' || $attestation_object === '') {
        metis_send_json_error('Missing registration payload.', 400);
    }

    $person_id = (int) $person['id'];
    $challenge = metis_people_consume_challenge($challenge_key, 'passkey_register', $person_id);
    if (!$challenge) {
        metis_send_json_error('Registration challenge expired or invalid.', 400);
    }

    if (function_exists('metis_people_b64url_decode')) {
        $client_data_json = metis_people_b64url_decode($client_data_json);
    } else {
        $client_data_json = base64_decode(strtr($client_data_json, '-_', '+/'), true) ?: '';
    }

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
    if (!hash_equals((string) ($challenge['challenge_value'] ?? ''), $challenge_value)) {
        metis_send_json_error('Challenge mismatch.', 400);
    }

    global $wpdb;
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $people_table = Metis_Tables::get('people');

    $existing = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$passkeys_table} WHERE credential_id = %s LIMIT 1",
        $credential_id
    ));
    if ($existing > 0) {
        metis_send_json_error('Passkey already registered.', 400);
    }

    $ok = $wpdb->insert($passkeys_table, [
        'person_id' => $person_id,
        'credential_id' => $credential_id,
        'credential_public_key' => $attestation_object,
        'sign_count' => 0,
        'transports_json' => $transports_json !== '' ? $transports_json : null,
        'label' => $label !== '' ? $label : 'Passkey',
        'created_by_person_id' => $person_id,
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

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity($person_id, 'passkey_registered', 'Registered passkey credential (self)', ['label' => $label !== '' ? $label : 'Passkey']);
    }

    metis_send_json_success([
        'passkey' => [
            'id' => $passkey_id,
            'label' => $label !== '' ? $label : 'Passkey',
            'created_at' => current_time('mysql'),
        ],
    ]);
});

metis_add_action('wp_ajax_metis_profile_revoke_passkey', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_send_json_error('Profile not found.', 404);
    }

    $passkey_id = isset($_POST['passkey_id']) ? (int) metis_unslash($_POST['passkey_id']) : 0;
    if ($passkey_id < 1) {
        metis_send_json_error('Invalid passkey id.', 400);
    }

    global $wpdb;
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $people_table = Metis_Tables::get('people');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, person_id, label, revoked_at FROM {$passkeys_table} WHERE id = %d LIMIT 1",
        $passkey_id
    ), ARRAY_A);

    if (!$row || (int) ($row['person_id'] ?? 0) !== (int) $person['id']) {
        metis_send_json_error('Passkey not found.', 404);
    }
    if (!empty($row['revoked_at'])) {
        metis_send_json_error('Passkey already revoked.', 400);
    }

    $wpdb->update($passkeys_table, ['revoked_at' => current_time('mysql')], ['id' => $passkey_id], ['%s'], ['%d']);

    $active_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$passkeys_table} WHERE person_id = %d AND revoked_at IS NULL",
        (int) $person['id']
    ));
    if ($active_count < 1) {
        $wpdb->update($people_table, ['passkey_enabled' => 0], ['id' => (int) $person['id']], ['%d'], ['%d']);
    }

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) $person['id'], 'passkey_revoked', 'Revoked passkey credential (self)', ['label' => (string) ($row['label'] ?? '')]);
    }

    metis_send_json_success(['active_count' => $active_count]);
});
