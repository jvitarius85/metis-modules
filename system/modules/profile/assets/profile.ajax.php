<?php
if (!defined('METIS_ROOT')) exit;

function metis_profile_ajax_verify(): void {
    if (function_exists('metis_people_ensure_schema')) {
        metis_people_ensure_schema();
    }
    if (function_exists('metis_people_seed_permissions_and_roles')) {
        metis_people_seed_permissions_and_roles();
    }
}

function metis_profile_current_person(): ?array {
    static $loaded = false;
    static $cached = null;

    if ($loaded) {
        return is_array($cached) ? $cached : null;
    }

    if (!metis_user_logged_in()) {
        $loaded = true;
        return null;
    }

    if (function_exists('metis_auth_current_person_id')) {
        $person_id = (int) metis_auth_current_person_id();
        if ($person_id > 0) {
            $person = \Metis\Modules\People\PersonProfileService::getById($person_id);
            if (is_array($person)) {
                $cached = $person;
                $loaded = true;
                return $person;
            }
        }
    }

    $person_id = function_exists('metis_people_get_current_person_id') ? (int) metis_people_get_current_person_id() : 0;
    if ($person_id > 0) {
        $person = \Metis\Modules\People\PersonProfileService::getById($person_id);
        if (is_array($person)) {
            $cached = $person;
            $loaded = true;
            return $person;
        }
    }

    $loaded = true;
    return null;
}

function metis_profile_person_payload(array $person): array {
    $auth_user = function_exists('metis_auth_find_user') ? metis_auth_find_user('person_id', (int) ($person['id'] ?? 0)) : null;
    $local_password_available = function_exists('metis_auth_password_hash_for_authentication')
        && is_array($auth_user)
        && metis_auth_password_hash_for_authentication($auth_user, $person) !== '';
    $workspace_password_available = !empty($person['is_workspace_user']) || metis_email_is_valid((string) ($person['workspace_email'] ?? ''));
    $passkeys = Metis_Tables::has('people_passkeys')
        ? \Metis\Modules\People\MfaService::activePasskeys((int) ($person['id'] ?? 0))
        : [];

    $notification_prefs = [];
    if (!empty($person['notification_prefs_json'])) {
        $decoded = json_decode((string) $person['notification_prefs_json'], true);
        if (is_array($decoded)) {
            $notification_prefs = $decoded;
        }
    }

    $avatar_name = trim((string) ($person['display_name'] ?? ''));
    if ($avatar_name === '') {
        $avatar_name = trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? ''));
    }
    $avatar_src = metis_avatar_url($avatar_name, (string) ($person['avatar_url'] ?? ''), 160, (string) ($person['pid'] ?? ''));
    $carddav_tokens = function_exists('metis_contacts_carddav_list_tokens')
        ? (array) metis_contacts_carddav_list_tokens(metis_current_user_id())
        : [];
    $carddav_endpoint = function_exists('metis_contacts_carddav_endpoint_url')
        ? (string) metis_contacts_carddav_endpoint_url('addressbooks/')
        : '';
    $current_user = function_exists('metis_runtime_current_user') ? metis_runtime_current_user() : null;
    $carddav_username = $current_user instanceof MetisUser
        ? (string) $current_user->user_login
        : (string) ($person['email'] ?? '');

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
        'has_metis_password' => $local_password_available,
        'has_workspace_password' => $workspace_password_available,
        'passkeys' => $passkeys,
        'notification_prefs' => $notification_prefs,
        'avatar_url' => $avatar_src,
        'updated_at' => (string) ($person['updated_at'] ?? ''),
        'carddav_tokens' => $carddav_tokens,
        'carddav_endpoint' => $carddav_endpoint,
        'carddav_username' => $carddav_username,
    ];
}

function metis_profile_register_ajax_controllers(): void {
    $actions = [
        'metis_profile_get' => 'view',
        'metis_profile_save' => 'edit',
        'metis_profile_change_workspace_password' => 'edit',
        'metis_profile_change_password' => 'edit',
        'metis_profile_save_avatar' => 'edit',
        'metis_profile_generate_totp_secret' => 'edit',
        'metis_profile_verify_totp_secret' => 'edit',
        'metis_profile_begin_passkey_registration' => 'edit',
        'metis_profile_complete_passkey_registration' => 'edit',
        'metis_profile_revoke_passkey' => 'edit',
        'metis_profile_carddav_issue_token' => 'edit',
        'metis_profile_carddav_revoke_token' => 'edit',
    ];

    foreach ($actions as $action => $permission) {
        metis_ajax_register_controller($action, [
            'module' => 'profile',
            'permission' => $permission,
            'nonce_action' => metis_ajax_nonce_action($action),
        ]);
    }
}

metis_profile_register_ajax_controllers();

metis_ajax_register_handler( 'metis_profile_get', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    metis_runtime_send_json_success([
        'person' => metis_profile_person_payload($person),
    ]);
});

metis_ajax_register_handler( 'metis_profile_carddav_issue_token', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }
    if (!function_exists('metis_contacts_carddav_issue_token')) {
        metis_runtime_send_json_error('CardDAV token service unavailable.', 500);
    }

    $label = isset(metis_request_post()['label']) ? metis_text_clean((string) metis_runtime_unslash(metis_request_post()['label'])) : 'CardDAV device';
    $label = trim($label) !== '' ? trim($label) : 'CardDAV device';
    $issued = metis_contacts_carddav_issue_token(metis_current_user_id(), $label);
    if (empty($issued['ok'])) {
        metis_runtime_send_json_error('Unable to generate CardDAV token.', 500);
    }

    metis_runtime_send_json_success([
        'issued' => $issued,
        'person' => metis_profile_person_payload($person),
    ]);
});

metis_ajax_register_handler( 'metis_profile_carddav_revoke_token', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }
    if (!function_exists('metis_contacts_carddav_revoke_token')) {
        metis_runtime_send_json_error('CardDAV token service unavailable.', 500);
    }

    $token_id = isset(metis_request_post()['token_id']) ? (int) metis_runtime_unslash(metis_request_post()['token_id']) : 0;
    if ($token_id < 1 || !metis_contacts_carddav_revoke_token($token_id, metis_current_user_id())) {
        metis_runtime_send_json_error('Unable to revoke CardDAV token.', 400);
    }

    metis_runtime_send_json_success([
        'person' => metis_profile_person_payload($person),
    ]);
});

metis_ajax_register_handler( 'metis_profile_save', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    $first_name = isset(metis_request_post()['first_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['first_name'])) : '';
    $last_name = isset(metis_request_post()['last_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['last_name'])) : '';
    $display_name = isset(metis_request_post()['display_name']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['display_name'])) : '';
    $email_notifications = !empty(metis_request_post()['email_notifications']) ? 1 : 0;
    $requires_2fa = !empty(metis_request_post()['requires_2fa']) ? 1 : 0;
    $mfa_method = isset(metis_request_post()['mfa_method']) ? metis_key_clean(metis_runtime_unslash(metis_request_post()['mfa_method'])) : 'none';
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
        metis_runtime_send_json_error('Display name is required.', 400);
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

    $updated = \Metis\Modules\People\PersonProfileService::updateSelfProfile((int) $person['id'], [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => $display_name,
        'email_notifications' => $email_notifications,
        'requires_2fa' => $requires_2fa,
        'mfa_method' => $mfa_method,
        'notification_prefs_json' => $notification_prefs_json,
    ]);

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) $person['id'], 'profile_saved', 'Updated self profile settings', []);
    }

    metis_runtime_send_json_success([
        'person' => metis_profile_person_payload($updated ?: $person),
    ]);
});

metis_ajax_register_handler( 'metis_profile_change_workspace_password', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    $new_password = isset(metis_request_post()['new_password']) ? (string) metis_runtime_unslash(metis_request_post()['new_password']) : '';
    $confirm_password = isset(metis_request_post()['confirm_password']) ? (string) metis_runtime_unslash(metis_request_post()['confirm_password']) : '';
    if (strlen($new_password) < 12) {
        metis_runtime_send_json_error('Password must be at least 12 characters.', 400);
    }
    if (!hash_equals($new_password, $confirm_password)) {
        metis_runtime_send_json_error('Password confirmation does not match.', 400);
    }

    $workspace_email = strtolower(trim((string) ($person['workspace_email'] ?? '')));
    if (!metis_email_is_valid($workspace_email) && !empty($person['is_workspace_user'])) {
        $workspace_email = strtolower(trim((string) ($person['email'] ?? '')));
    }
    if (!metis_email_is_valid($workspace_email)) {
        metis_runtime_send_json_error('No linked Workspace account found for this profile.', 400);
    }

    if (!function_exists('metis_people_workspace_sync_settings') || !function_exists('metis_people_workspace_google_request')) {
        metis_runtime_send_json_error('Workspace integration is not available.', 500);
    }

    $cfg = metis_people_workspace_sync_settings();
    if (empty($cfg['ok'])) {
        metis_runtime_send_json_error('Workspace integration is not configured.', 500);
    }

    $resp = metis_people_workspace_google_request('PUT', 'users/' . rawurlencode($workspace_email), [
        'password' => $new_password,
        'changePasswordAtNextLogin' => false,
    ], $cfg);

    if (empty($resp['ok'])) {
        metis_runtime_send_json_error('Workspace password update failed.', 500);
    }

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) ($person['id'] ?? 0), 'workspace_password_changed_self', 'Changed own Workspace password', [
            'workspace_email' => $workspace_email,
        ]);
    }

    metis_runtime_send_json_success(['ok' => 1]);
});

metis_ajax_register_handler( 'metis_profile_change_password', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    try {
        $person_id = (int) ($person['id'] ?? 0);
        $current_password = isset(metis_request_post()['current_password']) ? (string) metis_runtime_unslash(metis_request_post()['current_password']) : '';
        $new_password = isset(metis_request_post()['new_password']) ? (string) metis_runtime_unslash(metis_request_post()['new_password']) : '';
        $confirm_password = isset(metis_request_post()['confirm_password']) ? (string) metis_runtime_unslash(metis_request_post()['confirm_password']) : '';
        $auth_user = function_exists('metis_auth_find_user') ? metis_auth_find_user('person_id', $person_id) : null;
        $has_password = function_exists('metis_auth_password_hash_for_authentication')
            && is_array($auth_user)
            && metis_auth_password_hash_for_authentication($auth_user, $person) !== '';
        $auth_method = function_exists('metis_auth_current_method') ? metis_auth_current_method() : '';
        $can_set_from_session = in_array($auth_method, ['passkey', 'google_workspace', 'password_mfa'], true);

        $result = ($has_password && !$can_set_from_session)
            ? metis_auth_change_password_for_person(
                $person_id,
                $current_password,
                $new_password,
                $confirm_password
            )
            : ($has_password
                ? metis_auth_set_session_password_for_person(
                    $person_id,
                    $new_password,
                    $confirm_password
                )
                : metis_auth_set_initial_password_for_person(
                    $person_id,
                    $new_password,
                    $confirm_password
                ));

        if (function_exists('metis_auth_set_flash_notice')) {
            metis_auth_set_flash_notice(($has_password && !$can_set_from_session) ? 'Password updated. Please sign in again.' : 'Password set. Please sign in again.', 'success');
        }

        if (function_exists('metis_auth_logout')) {
            metis_auth_logout();
        }

        metis_runtime_send_json_success([
            'reauthenticate' => true,
            'redirect_url' => function_exists('metis_auth_login_url') ? metis_auth_login_url() : '/',
            'user_id' => (int) ($result['user']['id'] ?? 0),
            'created' => !$has_password,
            'session_set' => $has_password && $can_set_from_session,
        ]);
    } catch (Throwable $throwable) {
        if (class_exists('Metis_Logger')) {
            Metis_Logger::warn('profile.password_change_failed', [
                'error' => $throwable->getMessage(),
            ]);
        }
        metis_runtime_send_json_error('Unable to change password right now.', 400);
    }
});

metis_ajax_register_handler( 'metis_profile_save_avatar', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    $base64 = isset(metis_request_post()['avatar_base64']) ? (string) metis_runtime_unslash(metis_request_post()['avatar_base64']) : '';
    $decoded = metis_avatar_decode_base64_payload($base64);
    if (empty($decoded['ok'])) {
        metis_runtime_send_json_error('Invalid image payload.', 400);
    }

    $upload = metis_avatar_store_cropped_image((string) ($person['pid'] ?? ''), (string) ($decoded['binary'] ?? ''));
    if (empty($upload['ok'])) {
        metis_runtime_send_json_error('Failed to store image.', 500);
    }

    $avatar_url = isset($upload['url']) ? metis_url_clean((string) $upload['url']) : '';
    if ($avatar_url === '') {
        metis_runtime_send_json_error('Image URL unavailable.', 500);
    }

    \Metis\Modules\People\PersonProfileService::updateAvatar((int) $person['id'], $avatar_url);

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) $person['id'], 'avatar_updated', 'Updated self profile photo', []);
    }

    metis_runtime_send_json_success(['avatar_url' => $avatar_url]);
});

metis_ajax_register_handler( 'metis_profile_generate_totp_secret', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    if (!function_exists('metis_people_totp_generate_secret')) {
        metis_runtime_send_json_error('TOTP service unavailable.', 500);
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

    metis_runtime_send_json_success([
        'secret' => $secret,
        'provisioning_uri' => $uri,
    ]);
});

metis_ajax_register_handler( 'metis_profile_verify_totp_secret', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    if (!function_exists('metis_people_totp_now') || !function_exists('metis_people_encrypt_secret')) {
        metis_runtime_send_json_error('TOTP service unavailable.', 500);
    }

    $secret = isset(metis_request_post()['secret']) ? strtoupper(metis_text_clean(metis_runtime_unslash(metis_request_post()['secret']))) : '';
    $code = isset(metis_request_post()['code']) ? preg_replace('/\D+/', '', (string) metis_runtime_unslash(metis_request_post()['code'])) : '';
    if ($secret === '' || strlen((string) $code) !== 6) {
        metis_runtime_send_json_error('Secret and 6-digit code are required.', 400);
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
        metis_runtime_send_json_error('Code is not valid for this secret.', 400);
    }

    $enc = metis_people_encrypt_secret($secret);
    if ($enc === '') {
        metis_runtime_send_json_error('Failed to secure secret.', 500);
    }

    \Metis\Modules\People\MfaService::storeTotpSecret((int) $person['id'], $enc);

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) $person['id'], 'totp_enabled', 'Enabled authenticator app MFA (self)', []);
    }

    metis_runtime_send_json_success(['ok' => 1]);
});

metis_ajax_register_handler( 'metis_profile_begin_passkey_registration', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    if (!function_exists('metis_people_create_challenge') || !function_exists('metis_people_b64url_encode')) {
        metis_runtime_send_json_error('Passkey service unavailable.', 500);
    }

    $person_id = (int) $person['id'];

    $challenge = metis_people_create_challenge($person_id, 'passkey_register', 600);
    $exclude_ids = \Metis\Modules\People\MfaService::activePasskeyCredentialIds($person_id);

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

    metis_runtime_send_json_success([
        'challenge_key' => (string) ($challenge['challenge_key'] ?? ''),
        'public_key' => [
            'rp' => [
                'name' => 'Metis',
                'id' => metis_runtime_parse_url(metis_home_url(), PHP_URL_HOST),
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

metis_ajax_register_handler( 'metis_profile_complete_passkey_registration', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    if (!function_exists('metis_people_consume_challenge') || !function_exists('metis_people_origin_allowed')) {
        metis_runtime_send_json_error('Passkey service unavailable.', 500);
    }

    $challenge_key = isset(metis_request_post()['challenge_key']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['challenge_key'])) : '';
    $credential_id = isset(metis_request_post()['credential_id']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['credential_id'])) : '';
    $client_data_json = isset(metis_request_post()['client_data_json']) ? (string) metis_runtime_unslash(metis_request_post()['client_data_json']) : '';
    $attestation_object = isset(metis_request_post()['attestation_object']) ? (string) metis_runtime_unslash(metis_request_post()['attestation_object']) : '';
    $transports_json = isset(metis_request_post()['transports_json']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['transports_json'])) : '';
    $label = isset(metis_request_post()['label']) ? metis_text_clean(metis_runtime_unslash(metis_request_post()['label'])) : '';

    if ($challenge_key === '' || $credential_id === '' || $client_data_json === '' || $attestation_object === '') {
        metis_runtime_send_json_error('Missing registration payload.', 400);
    }

    $person_id = (int) $person['id'];
    $challenge = metis_people_consume_challenge($challenge_key, 'passkey_register', $person_id);
    if (!$challenge) {
        metis_runtime_send_json_error('Registration challenge expired or invalid.', 400);
    }

    if (function_exists('metis_people_b64url_decode')) {
        $client_data_json = metis_people_b64url_decode($client_data_json);
    } else {
        $client_data_json = base64_decode(strtr($client_data_json, '-_', '+/'), true) ?: '';
    }

    if ($client_data_json === '') {
        metis_runtime_send_json_error('Invalid client data.', 400);
    }

    $client_data = json_decode($client_data_json, true);
    if (!is_array($client_data)) {
        metis_runtime_send_json_error('Malformed client data payload.', 400);
    }

    $type = (string) ($client_data['type'] ?? '');
    $origin = (string) ($client_data['origin'] ?? '');
    $challenge_value = (string) ($client_data['challenge'] ?? '');
    if ($type !== 'webauthn.create') {
        metis_runtime_send_json_error('Unexpected WebAuthn response type.', 400);
    }
    if (!metis_people_origin_allowed($origin)) {
        metis_runtime_send_json_error('Passkey origin mismatch.', 400);
    }
    if (!hash_equals((string) ($challenge['challenge_value'] ?? ''), $challenge_value)) {
        metis_runtime_send_json_error('Challenge mismatch.', 400);
    }

    if (\Metis\Modules\People\MfaService::passkeyExistsByCredentialId($credential_id)) {
        metis_runtime_send_json_error('Passkey already registered.', 400);
    }

    $passkey = \Metis\Modules\People\MfaService::registerPasskey($person_id, $credential_id, $attestation_object, $transports_json, $label, $person_id);

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity($person_id, 'passkey_registered', 'Registered passkey credential (self)', ['label' => $label !== '' ? $label : 'Passkey']);
    }

    metis_runtime_send_json_success([
        'passkey' => $passkey,
    ]);
});

metis_ajax_register_handler( 'metis_profile_revoke_passkey', function () {
    metis_profile_ajax_verify();

    $person = metis_profile_current_person();
    if (!$person) {
        metis_runtime_send_json_error('Profile not found.', 404);
    }

    $passkey_id = isset(metis_request_post()['passkey_id']) ? (int) metis_runtime_unslash(metis_request_post()['passkey_id']) : 0;
    if ($passkey_id < 1) {
        metis_runtime_send_json_error('Invalid passkey id.', 400);
    }

    $row = \Metis\Modules\People\MfaService::getPasskeyById($passkey_id);

    if (!$row || (int) ($row['person_id'] ?? 0) !== (int) $person['id']) {
        metis_runtime_send_json_error('Passkey not found.', 404);
    }
    if (!empty($row['revoked_at'])) {
        metis_runtime_send_json_error('Passkey already revoked.', 400);
    }

    \Metis\Modules\People\MfaService::revokePasskey($passkey_id);

    $active_count = \Metis\Modules\People\MfaService::activePasskeyCount((int) $person['id']);
    if ($active_count < 1) {
        \Metis\Modules\People\MfaService::disablePasskeyFlag((int) $person['id']);
    }

    if (function_exists('metis_people_log_activity')) {
        metis_people_log_activity((int) $person['id'], 'passkey_revoked', 'Revoked passkey credential (self)', ['label' => (string) ($row['label'] ?? '')]);
    }

    metis_runtime_send_json_success(['active_count' => $active_count]);
});
