<?php
if (!defined('METIS_ROOT')) exit;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $actions = [
        'metis_people_generate_totp_secret',
        'metis_people_verify_totp_secret',
        'metis_people_reset_metis_password',
        'metis_people_begin_passkey_registration',
        'metis_people_complete_passkey_registration',
        'metis_people_revoke_passkey',
        'metis_people_reset_mfa',
    ];
    foreach ( $actions as $action ) {
        metis_ajax_register_controller( $action, [
            'module' => 'people',
            'permission' => 'edit',
            'nonce_action' => metis_ajax_nonce_action( $action ),
        ] );
    }
}

metis_ajax_register_handler( 'metis_people_generate_totp_secret', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $person_id = isset($_POST['person_id']) ? (int) metis_runtime_unslash($_POST['person_id']) : 0;
    if ($person_id < 1) {
        metis_runtime_send_json_error('Invalid person id.', 400);
    }
    $person = $db->fetchOne("SELECT email, display_name FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ]);
    $email = strtolower(trim((string) ($person['email'] ?? '')));
    $label = trim((string) ($person['display_name'] ?? ''));
    if ($label === '' && $email !== '') {
        $label = $email;
    }
    $issuer = 'Metis';
    $secret = metis_people_totp_generate_secret(32);
    $provisioning_uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $label) . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    metis_runtime_send_json_success([
        'secret' => $secret,
        'provisioning_uri' => $provisioning_uri,
    ]);
});

metis_ajax_register_handler( 'metis_people_verify_totp_secret', function () {
    metis_people_ajax_verify();
    $people_table = Metis_Tables::get('people');
    $db = metis_db();
    $person_id = isset($_POST['person_id']) ? (int) metis_runtime_unslash($_POST['person_id']) : 0;
    $secret = isset($_POST['secret']) ? strtoupper(metis_text_clean(metis_runtime_unslash($_POST['secret']))) : '';
    $code = isset($_POST['code']) ? preg_replace('/\D+/', '', (string) metis_runtime_unslash($_POST['code'])) : '';
    if ($person_id < 1 || $secret === '' || strlen($code) !== 6) {
        metis_runtime_send_json_error('Secret and 6-digit code are required.', 400);
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
        metis_runtime_send_json_error('Code is not valid for this secret.', 400);
    }
    $enc = metis_people_encrypt_secret($secret);
    if ($enc === '') {
        metis_runtime_send_json_error('Failed to secure secret.', 500);
    }
    $db->update(
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
    metis_runtime_send_json_success(['ok' => 1]);
});

metis_ajax_register_handler( 'metis_people_reset_metis_password', function () {
    metis_people_ajax_verify();

    if (
        ! function_exists( 'metis_auth_get_person' )
        || ! function_exists( 'metis_auth_find_user' )
        || ! function_exists( 'metis_auth_set_initial_password_for_person' )
        || ! function_exists( 'metis_auth_admin_reset_password' )
    ) {
        metis_runtime_send_json_error( 'Metis password management is not available.', 500 );
    }

    $person_id = isset( $_POST['person_id'] ) ? (int) metis_runtime_unslash( $_POST['person_id'] ) : 0;
    if ( $person_id < 1 ) {
        metis_runtime_send_json_error( 'Invalid person id.', 400 );
    }

    $person = metis_auth_get_person( $person_id );
    if ( ! is_array( $person ) ) {
        metis_runtime_send_json_error( 'Person not found.', 404 );
    }

    $generated_password = function_exists( 'metis_people_workspace_random_password' )
        ? metis_people_workspace_random_password( 20 )
        : metis_generate_password( 20, true, true );
    if ( ! is_string( $generated_password ) || strlen( $generated_password ) < 12 ) {
        $generated_password = metis_generate_password( 20, true, true );
    }

    try {
        $admin_person_id = function_exists( 'metis_people_get_current_person_id' ) ? (int) metis_people_get_current_person_id() : 0;
        $auth_user = metis_auth_find_user( 'person_id', $person_id );
        if ( is_array( $auth_user ) && ! empty( $auth_user['id'] ) ) {
            metis_auth_admin_reset_password( max( 1, $admin_person_id ), $person_id, $generated_password );
        } else {
            metis_auth_set_initial_password_for_person( $person_id, $generated_password, $generated_password );
        }
    } catch ( Throwable $e ) {
        if ( class_exists( 'Metis_Logger' ) ) {
            Metis_Logger::warn( 'people.password_reset_failed', [
                'person_id' => $person_id,
                'error' => $e->getMessage(),
            ] );
        }
        metis_runtime_send_json_error( 'Password reset could not be completed.', 400 );
    }

    $auth_user = metis_auth_find_user( 'person_id', $person_id );
    metis_runtime_send_json_success( [
        'ok' => 1,
        'person_id' => $person_id,
        'user_login' => (string) ( $auth_user['user_login'] ?? '' ),
        'user_email' => (string) ( $auth_user['user_email'] ?? ( $person['email'] ?? '' ) ),
        'password' => $generated_password,
    ] );
});

metis_ajax_register_handler( 'metis_people_begin_passkey_registration', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $person_id = isset($_POST['person_id']) ? (int) metis_runtime_unslash($_POST['person_id']) : 0;
    if ($person_id < 1) {
        metis_runtime_send_json_error('Invalid person id.', 400);
    }
    $person = $db->fetchOne("SELECT id, email, display_name FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ]);
    if (!$person) {
        metis_runtime_send_json_error('Person not found.', 404);
    }
    $challenge = metis_people_create_challenge($person_id, 'passkey_register', 600);
    $exclude = $db->column(
        "SELECT credential_id FROM {$passkeys_table} WHERE person_id = %d AND revoked_at IS NULL",
        [ $person_id ]
    );
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
    metis_runtime_send_json_success([
        'challenge_key' => $challenge['challenge_key'],
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

metis_ajax_register_handler( 'metis_people_complete_passkey_registration', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $person_id = isset($_POST['person_id']) ? (int) metis_runtime_unslash($_POST['person_id']) : 0;
    $challenge_key = isset($_POST['challenge_key']) ? metis_text_clean(metis_runtime_unslash($_POST['challenge_key'])) : '';
    $credential_id = isset($_POST['credential_id']) ? metis_text_clean(metis_runtime_unslash($_POST['credential_id'])) : '';
    $client_data_json_b64 = isset($_POST['client_data_json']) ? (string) metis_runtime_unslash($_POST['client_data_json']) : '';
    $attestation_object_b64 = isset($_POST['attestation_object']) ? (string) metis_runtime_unslash($_POST['attestation_object']) : '';
    $transports_json = isset($_POST['transports_json']) ? metis_text_clean(metis_runtime_unslash($_POST['transports_json'])) : '';
    $label = isset($_POST['label']) ? metis_text_clean(metis_runtime_unslash($_POST['label'])) : '';
    if ($person_id < 1 || $challenge_key === '' || $credential_id === '' || $client_data_json_b64 === '' || $attestation_object_b64 === '') {
        metis_runtime_send_json_error('Missing registration payload.', 400);
    }
    $person = $db->fetchOne("SELECT id FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ]);
    if (!$person) {
        metis_runtime_send_json_error('Person not found.', 404);
    }
    $challenge = metis_people_consume_challenge($challenge_key, 'passkey_register', $person_id);
    if (!$challenge) {
        metis_runtime_send_json_error('Registration challenge expired or invalid.', 400);
    }
    $client_data_json = metis_people_b64url_decode($client_data_json_b64);
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
    if (!hash_equals((string) $challenge['challenge_value'], $challenge_value)) {
        metis_runtime_send_json_error('Challenge mismatch.', 400);
    }
    $existing = (int) $db->scalar(
        "SELECT id FROM {$passkeys_table} WHERE credential_id = %s LIMIT 1",
        [ $credential_id ]
    );
    if ($existing > 0) {
        metis_runtime_send_json_error('Passkey already registered.', 400);
    }
    $actor_id = metis_people_get_current_person_id();
    $ok = $db->insert($passkeys_table, [
        'person_id' => $person_id,
        'credential_id' => $credential_id,
        'credential_public_key' => $attestation_object_b64,
        'sign_count' => 0,
        'transports_json' => $transports_json !== '' ? $transports_json : null,
        'label' => $label !== '' ? $label : 'Passkey',
        'created_by_person_id' => $actor_id > 0 ? $actor_id : null,
    ], ['%d', '%s', '%s', '%d', '%s', '%s', '%d']);
    if (!$ok) {
        metis_runtime_send_json_error('Failed to persist passkey.', 500);
    }
    $passkey_id = (int) $db->lastInsertId();
    $db->execute($db->prepare(
        "UPDATE {$people_table}
         SET passkey_enabled = 1,
             requires_2fa = 1,
             mfa_method = CASE WHEN mfa_method = 'none' THEN 'passkey' ELSE mfa_method END
         WHERE id = %d",
        $person_id
    ));
    $label_out = $label !== '' ? $label : 'Passkey';
    metis_people_log_activity($person_id, 'passkey_registered', 'Registered passkey credential', ['label' => $label_out]);
    metis_runtime_send_json_success([
        'ok' => 1,
        'passkey' => [
            'id' => $passkey_id,
            'label' => $label_out,
            'created_at' => metis_current_time('mysql'),
        ],
    ]);
});

metis_ajax_register_handler( 'metis_people_revoke_passkey', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $passkey_id = isset($_POST['passkey_id']) ? (int) metis_runtime_unslash($_POST['passkey_id']) : 0;
    if ($passkey_id < 1) {
        metis_runtime_send_json_error('Invalid passkey id.', 400);
    }
    $row = $db->fetchOne(
        "SELECT id, person_id, label, revoked_at FROM {$passkeys_table} WHERE id = %d LIMIT 1",
        [ $passkey_id ]
    );
    if (!$row) {
        metis_runtime_send_json_error('Passkey not found.', 404);
    }
    if (!empty($row['revoked_at'])) {
        metis_runtime_send_json_error('Passkey already revoked.', 400);
    }
    $db->update($passkeys_table, ['revoked_at' => metis_current_time('mysql')], ['id' => $passkey_id], ['%s'], ['%d']);
    $active_count = (int) $db->scalar(
        "SELECT COUNT(*) FROM {$passkeys_table} WHERE person_id = %d AND revoked_at IS NULL",
        [ (int) $row['person_id'] ]
    );
    if ($active_count < 1) {
        $db->update($people_table, ['passkey_enabled' => 0], ['id' => (int) $row['person_id']], ['%d'], ['%d']);
    }
    metis_people_log_activity((int) $row['person_id'], 'passkey_revoked', 'Revoked passkey credential', ['label' => (string) ($row['label'] ?? '')]);
    metis_runtime_send_json_success(['ok' => 1, 'active_count' => $active_count]);
});

metis_ajax_register_handler( 'metis_people_reset_mfa', function () {
    metis_people_ajax_verify();
    $db = metis_db();
    $people_table = Metis_Tables::get('people');
    $passkeys_table = Metis_Tables::get('people_passkeys');
    $person_id = isset($_POST['person_id']) ? (int) metis_runtime_unslash($_POST['person_id']) : 0;
    if ($person_id < 1) {
        metis_runtime_send_json_error('Invalid person id.', 400);
    }

    $person = $db->fetchOne(
        "SELECT id, pid, email, display_name FROM {$people_table} WHERE id = %d LIMIT 1",
        [ $person_id ]
    );
    if (!$person) {
        metis_runtime_send_json_error('Person not found.', 404);
    }

    $revoked_passkeys = 0;
    if (Metis_Tables::has('people_passkeys')) {
        $active_passkey_rows = $db->fetchAll(
            "SELECT id, label
             FROM {$passkeys_table}
             WHERE person_id = %d AND revoked_at IS NULL",
            [ $person_id ]
        );

        foreach ($active_passkey_rows as $passkey_row) {
            $updated = $db->update(
                $passkeys_table,
                ['revoked_at' => metis_current_time('mysql')],
                ['id' => (int) ($passkey_row['id'] ?? 0)],
                ['%s'],
                ['%d']
            );
            if ($updated !== false) {
                $revoked_passkeys++;
            }
        }
    }

    $updated = $db->update(
        $people_table,
        [
            'requires_2fa' => 0,
            'mfa_method' => 'none',
            'totp_enabled' => 0,
            'passkey_enabled' => 0,
            'totp_secret_enc' => null,
            'updated_at' => metis_current_time('mysql'),
        ],
        ['id' => $person_id],
        ['%d', '%s', '%d', '%d', '%s', '%s'],
        ['%d']
    );

    if ($updated === false) {
        metis_runtime_send_json_error('Failed to reset MFA.', 500);
    }

    metis_people_log_activity($person_id, 'mfa_reset', 'Reset MFA configuration', [
        'revoked_passkeys' => $revoked_passkeys,
    ]);

    metis_runtime_send_json_success([
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
