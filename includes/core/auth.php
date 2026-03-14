<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function metis_auth_log( string $event, array $context = [] ): void {
    if ( class_exists( 'Metis_Logger' ) ) {
        Metis_Logger::info( 'auth_' . $event, $context );
        return;
    }

    if ( function_exists( 'metis_standalone_boot_log' ) ) {
        metis_standalone_boot_log( 'auth_' . $event, $context );
    }
}

function metis_auth_table(): string {
    return Metis_Tables::get( 'auth_users' );
}

function metis_auth_people_table(): string {
    return Metis_Tables::get( 'people' );
}

function metis_auth_roles_default(): array {
    return [ 'administrator', 'board', 'donor_admin', 'newsletter_admin', 'workspace_manager' ];
}

function metis_auth_decode_roles( mixed $value ): array {
    if ( is_array( $value ) ) {
        return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
    }

    if ( is_string( $value ) && $value !== '' ) {
        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) {
            return metis_auth_decode_roles( $decoded );
        }
    }

    return [];
}

function metis_auth_ensure_schema(): void {
    if ( function_exists( 'metis_install_db' ) ) {
        metis_install_db();
    }
}

function metis_auth_has_users(): bool {
    metis_auth_ensure_schema();
    global $wpdb;
    return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . metis_auth_table() ) > 0;
}

function metis_auth_table_exists( string $table ): bool {
    global $wpdb;
    $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    return is_string( $found ) && $found === $table;
}

function metis_auth_legacy_people_available(): bool {
    return Metis_Tables::has( 'people' ) && metis_auth_table_exists( metis_auth_people_table() );
}

function metis_auth_portable_phpass_itoa64(): string {
    return './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
}

function metis_auth_portable_phpass_encode64( string $input, int $count ): string {
    $itoa64 = metis_auth_portable_phpass_itoa64();
    $output = '';
    $i = 0;

    do {
        $value = ord( $input[ $i ] );
        $output .= $itoa64[ $value & 0x3f ];

        if ( $i + 1 < $count ) {
            $value |= ord( $input[ $i + 1 ] ) << 8;
        }
        $output .= $itoa64[ ( $value >> 6 ) & 0x3f ];

        if ( $i + 1 >= $count ) {
            break;
        }

        if ( $i + 2 < $count ) {
            $value |= ord( $input[ $i + 2 ] ) << 16;
        }
        $output .= $itoa64[ ( $value >> 12 ) & 0x3f ];

        if ( $i + 2 >= $count ) {
            break;
        }

        $output .= $itoa64[ ( $value >> 18 ) & 0x3f ];
        $i += 3;
    } while ( $i < $count );

    return $output;
}

function metis_auth_check_portable_phpass( string $password, string $hash ): bool {
    if ( strlen( $hash ) < 34 ) {
        return false;
    }

    $prefix = substr( $hash, 0, 3 );
    if ( $prefix !== '$P$' && $prefix !== '$H$' ) {
        return false;
    }

    $itoa64 = metis_auth_portable_phpass_itoa64();
    $count_log2 = strpos( $itoa64, $hash[3] );
    if ( $count_log2 === false || $count_log2 < 7 || $count_log2 > 30 ) {
        return false;
    }

    $count = 1 << $count_log2;
    $salt = substr( $hash, 4, 8 );
    if ( strlen( $salt ) !== 8 ) {
        return false;
    }

    $digest = md5( $salt . $password, true );
    do {
        $digest = md5( $digest . $password, true );
    } while ( --$count );

    $encoded = substr( $hash, 0, 12 ) . metis_auth_portable_phpass_encode64( $digest, 16 );
    return hash_equals( $encoded, $hash );
}

function metis_auth_check_password( string $password, string $hash, int|string $user_id = '' ): bool {
    if ( $hash === '' ) {
        return false;
    }

    if ( password_verify( $password, $hash ) ) {
        return true;
    }

    if ( metis_auth_check_portable_phpass( $password, $hash ) ) {
        return true;
    }

    if ( strlen( $hash ) === 32 && ctype_xdigit( $hash ) ) {
        return hash_equals( strtolower( $hash ), md5( $password ) );
    }

    return hash_equals( $hash, $password );
}

function metis_auth_password_hash_for_storage( string $password ): string {
    return password_hash( $password, PASSWORD_DEFAULT );
}

function metis_auth_find_user( string $field, string|int $value ): ?array {
    metis_auth_ensure_schema();
    global $wpdb;

    $field = match ( $field ) {
        'id' => 'id',
        'login' => 'user_login',
        'email' => 'user_email',
        'person_id' => 'person_id',
        default => '',
    };

    if ( $field === '' ) {
        return null;
    }

    $placeholder = in_array( $field, [ 'id', 'person_id' ], true ) ? '%d' : '%s';
    $row = $wpdb->get_row(
        $wpdb->prepare( 'SELECT * FROM ' . metis_auth_table() . " WHERE {$field} = {$placeholder} LIMIT 1", $value ),
        ARRAY_A
    );

    return is_array( $row ) ? $row : null;
}

function metis_auth_person_roles( int $person_id ): array {
    if ( $person_id < 1 || ! Metis_Tables::has( 'people_roles' ) || ! Metis_Tables::has( 'people_user_roles' ) ) {
        return [];
    }

    global $wpdb;
    $roles_table = Metis_Tables::get( 'people_roles' );
    $user_roles_table = Metis_Tables::get( 'people_user_roles' );
    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT r.role_key
             FROM {$user_roles_table} ur
             INNER JOIN {$roles_table} r ON r.id = ur.role_id
             WHERE ur.person_id = %d
               AND (ur.start_at IS NULL OR ur.start_at <= NOW())
               AND (ur.end_at IS NULL OR ur.end_at >= NOW())",
            $person_id
        )
    ) ?: [];

    $roles = [];
    foreach ( $rows as $row ) {
        $role = sanitize_key( (string) $row );
        if ( $role !== '' ) {
            $roles[ $role ] = true;
        }
    }

    return array_keys( $roles );
}

function metis_auth_get_person( int $person_id ): ?array {
    if ( $person_id < 1 || ! metis_auth_legacy_people_available() ) {
        return null;
    }

    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare( 'SELECT * FROM ' . metis_auth_people_table() . ' WHERE id = %d LIMIT 1', $person_id ),
        ARRAY_A
    );

    return is_array( $row ) ? $row : null;
}

function metis_auth_current_person_id(): int {
    return (int) ( $_SESSION['metis_person_id'] ?? 0 );
}

function metis_auth_person_row_to_auth_payload( array $person, ?array $wp_user = null ): array {
    $email = strtolower( trim( (string) ( $person['email'] ?? '' ) ) );
    if ( $email === '' && is_array( $wp_user ) ) {
        $email = strtolower( trim( (string) ( $wp_user['user_email'] ?? '' ) ) );
    }

    $login = '';
    if ( is_array( $wp_user ) ) {
        $login = sanitize_key( (string) ( $wp_user['user_login'] ?? '' ) );
    }
    if ( $login === '' ) {
        $login = sanitize_key( (string) strstr( $email, '@', true ) );
    }
    if ( $login === '' ) {
        $login = 'person' . (int) ( $person['id'] ?? 0 );
    }

    $display = trim( (string) ( $person['display_name'] ?? '' ) );
    if ( $display === '' ) {
        $display = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
    }
    if ( $display === '' ) {
        $display = $email !== '' ? $email : $login;
    }

    return [
        'person_id' => (int) ( $person['id'] ?? 0 ),
        'user_login' => $login,
        'user_email' => $email,
        'display_name' => $display,
        'first_name' => (string) ( $person['first_name'] ?? '' ),
        'last_name' => (string) ( $person['last_name'] ?? '' ),
        'roles_json' => metis_json_encode( metis_auth_person_roles( (int) ( $person['id'] ?? 0 ) ) ),
        'is_active' => (string) ( $person['status'] ?? 'active' ) !== 'inactive' ? 1 : 0,
    ];
}

function metis_auth_find_person_by_identifier( string $identifier ): ?array {
    if ( ! metis_auth_legacy_people_available() ) {
        return null;
    }

    global $wpdb;
    $people_table = metis_auth_people_table();
    $identifier = trim( $identifier );
    $email = strtolower( sanitize_email( $identifier ) );
    if ( $email !== '' && is_email( $email ) ) {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$people_table}
                 WHERE email = %s
                    OR workspace_email = %s
                 LIMIT 1",
                $email,
                $email
            ),
            ARRAY_A
        );
        if ( is_array( $row ) ) {
            return $row;
        }
    }

    $pid = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $identifier ) ?? '' );
    if ( $pid !== '' ) {
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                 FROM {$people_table}
                 WHERE pid = %s
                 LIMIT 1",
                $pid
            ),
            ARRAY_A
        );
        if ( is_array( $row ) ) {
            return $row;
        }
    }

    return null;
}

function metis_auth_legacy_people_without_auth_count(): int {
    if ( ! metis_auth_legacy_people_available() ) {
        return 0;
    }

    global $wpdb;
    $people_table = metis_auth_people_table();
    $auth_table = metis_auth_table();

    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$people_table} p
         LEFT JOIN {$auth_table} a ON a.person_id = p.id
         WHERE a.id IS NULL"
    );
}

function metis_auth_upsert_user_from_person( array $person, ?array $wp_user = null, string $password_hash = '' ): array {
    metis_auth_ensure_schema();
    global $wpdb;

    $payload = metis_auth_person_row_to_auth_payload( $person, $wp_user );
    $existing = metis_auth_find_user( 'person_id', (int) $payload['person_id'] );
    if ( ! $existing && $payload['user_email'] !== '' ) {
        $existing = metis_auth_find_user( 'email', $payload['user_email'] );
    }
    if ( ! $existing && $payload['user_login'] !== '' ) {
        $existing = metis_auth_find_user( 'login', $payload['user_login'] );
    }

    if ( $password_hash === '' && is_array( $existing ) ) {
        $password_hash = (string) ( $existing['password_hash'] ?? '' );
    }
    if ( $password_hash === '' && is_array( $wp_user ) ) {
        $password_hash = (string) ( $wp_user['user_pass'] ?? '' );
    }

    $payload['password_hash'] = $password_hash;

    if ( is_array( $existing ) ) {
        metis_auth_log( 'upsert_update', [
            'auth_user_id' => (int) $existing['id'],
            'person_id' => (int) $payload['person_id'],
            'login' => (string) $payload['user_login'],
            'email' => (string) $payload['user_email'],
        ] );
        $wpdb->update(
            metis_auth_table(),
            $payload,
            [ 'id' => (int) $existing['id'] ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );
        return metis_auth_find_user( 'id', (int) $existing['id'] ) ?? $existing;
    }

    $wpdb->insert(
        metis_auth_table(),
        $payload,
        [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
    );

    $row = metis_auth_find_user( 'id', (int) $wpdb->insert_id );
    if ( ! is_array( $row ) ) {
        throw new RuntimeException( 'Failed to persist auth user.' );
    }

    metis_auth_log( 'upsert_insert', [
        'auth_user_id' => (int) $row['id'],
        'person_id' => (int) $payload['person_id'],
        'login' => (string) $payload['user_login'],
        'email' => (string) $payload['user_email'],
    ] );

    return $row;
}

function metis_auth_secret_key_bytes(): string {
    return hash( 'sha256', (string) metis_runtime_config_get( 'app_key', 'metis-local-key' ), true );
}

function metis_auth_legacy_secret_key_bytes(): string {
    $auth_key = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : (string) metis_runtime_config_get( 'app_key', 'metis-local-key' );
    $secure_auth_key = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : $auth_key;
    return hash( 'sha256', $auth_key . $secure_auth_key, true );
}

function metis_auth_decrypt_secret( string $encoded ): string {
    if ( $encoded === '' ) {
        return '';
    }

    $raw = base64_decode( $encoded, true );
    if ( $raw === false || strlen( $raw ) <= 16 ) {
        return '';
    }

    $iv = substr( $raw, 0, 16 );
    $cipher = substr( $raw, 16 );
    $keys = [
        metis_auth_secret_key_bytes(),
        metis_auth_legacy_secret_key_bytes(),
    ];

    foreach ( $keys as $key ) {
        $plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        if ( is_string( $plain ) && $plain !== '' ) {
            return $plain;
        }
    }

    return '';
}

function metis_auth_verify_totp_code( array $person, string $code ): bool {
    $code = preg_replace( '/\D+/', '', $code );
    if ( $code === null || strlen( $code ) !== 6 ) {
        return false;
    }

    if ( ! function_exists( 'metis_people_totp_now' ) ) {
        return false;
    }

    $secret = metis_auth_decrypt_secret( (string) ( $person['totp_secret_enc'] ?? '' ) );
    if ( $secret === '' ) {
        metis_auth_log( 'totp_secret_unavailable', [ 'person_id' => (int) ( $person['id'] ?? 0 ) ] );
        return false;
    }

    $now = time();
    for ( $i = -2; $i <= 2; $i++ ) {
        if ( hash_equals( metis_people_totp_now( $secret, 30, 6, $now + ( $i * 30 ) ), $code ) ) {
            metis_auth_log( 'totp_ok', [ 'person_id' => (int) ( $person['id'] ?? 0 ) ] );
            return true;
        }
    }

    metis_auth_log( 'totp_failed', [ 'person_id' => (int) ( $person['id'] ?? 0 ) ] );
    return false;
}

function metis_auth_user_row_to_session( array $row ): array {
    $roles = metis_auth_decode_roles( $row['roles_json'] ?? [] );
    if ( $roles === [] ) {
        $roles = metis_auth_roles_default();
    }

    $person = metis_auth_get_person( (int) ( $row['person_id'] ?? 0 ) );
    $display = (string) ( $row['display_name'] ?? '' );
    if ( $display === '' && is_array( $person ) ) {
        $display = (string) ( $person['display_name'] ?? '' );
    }

    return [
        'ID' => (int) ( $row['id'] ?? 0 ),
        'person_id' => (int) ( $row['person_id'] ?? 0 ),
        'user_login' => (string) ( $row['user_login'] ?? '' ),
        'user_email' => (string) ( $row['user_email'] ?? '' ),
        'display_name' => $display,
        'first_name' => (string) ( $row['first_name'] ?? '' ),
        'last_name' => (string) ( $row['last_name'] ?? '' ),
        'roles' => $roles,
        'user_pass' => (string) ( $row['password_hash'] ?? '' ),
    ];
}

function metis_auth_refresh_session(): void {
    $user_id = (int) ( $_SESSION['metis_auth_user_id'] ?? 0 );
    if ( $user_id < 1 ) {
        unset( $_SESSION['metis_user'], $_SESSION['metis_person_id'], $_SESSION['metis_session_integrity'] );
        return;
    }

    $row = metis_auth_find_user( 'id', $user_id );
    if ( ! is_array( $row ) || empty( $row['is_active'] ) ) {
        metis_auth_logout();
        return;
    }

    $_SESSION['metis_user'] = metis_auth_user_row_to_session( $row );
    $_SESSION['metis_person_id'] = (int) ( $row['person_id'] ?? 0 );
    metis_auth_refresh_session_integrity();
}

function metis_auth_current_user_row(): ?array {
    $user_id = (int) ( $_SESSION['metis_auth_user_id'] ?? 0 );
    if ( $user_id < 1 ) {
        return null;
    }

    return metis_auth_find_user( 'id', $user_id );
}

function metis_auth_current_person(): ?array {
    return metis_auth_get_person( metis_auth_current_person_id() );
}

function metis_auth_session_integrity_fingerprint(): string {
    $session_token = (string) ( $_SESSION['metis_session_token'] ?? '' );
    if ( $session_token === '' && function_exists( 'wp_get_session_token' ) ) {
        $session_token = (string) wp_get_session_token();
    }

    if ( $session_token === '' ) {
        return '';
    }

    $payload = [
        'auth_user_id' => (int) ( $_SESSION['metis_auth_user_id'] ?? 0 ),
        'person_id'    => (int) ( $_SESSION['metis_person_id'] ?? 0 ),
        'session'      => $session_token,
        'user_agent'   => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
    ];

    return hash_hmac( 'sha256', metis_json_encode( $payload ) ?: '', metis_auth_secret_key_bytes() );
}

function metis_auth_refresh_session_integrity(): void {
    if ( ! metis_user_logged_in() ) {
        unset( $_SESSION['metis_session_integrity'] );
        return;
    }

    $fingerprint = metis_auth_session_integrity_fingerprint();
    if ( $fingerprint === '' ) {
        unset( $_SESSION['metis_session_integrity'] );
        return;
    }

    $_SESSION['metis_session_integrity'] = $fingerprint;
}

function metis_auth_session_integrity_is_valid(): bool {
    if ( ! metis_user_logged_in() ) {
        return false;
    }

    $fingerprint = metis_auth_session_integrity_fingerprint();
    if ( $fingerprint === '' ) {
        return false;
    }

    $stored = (string) ( $_SESSION['metis_session_integrity'] ?? '' );
    if ( $stored === '' ) {
        $_SESSION['metis_session_integrity'] = $fingerprint;
        return true;
    }

    return hash_equals( $stored, $fingerprint );
}

function metis_auth_finalize_login( array $row ): void {
    $_SESSION['metis_auth_user_id'] = (int) $row['id'];
    $_SESSION['metis_person_id'] = (int) ( $row['person_id'] ?? 0 );
    $_SESSION['metis_session_token'] = bin2hex( random_bytes( 16 ) );
    $_SESSION['metis_user'] = metis_auth_user_row_to_session( $row );
    metis_auth_refresh_session_integrity();
    unset( $_SESSION['metis_pending_auth'] );

    global $wpdb;
    $wpdb->update(
        metis_auth_table(),
        [ 'last_login_at' => current_time( 'mysql' ) ],
        [ 'id' => (int) $row['id'] ],
        [ '%s' ],
        [ '%d' ]
    );

    metis_auth_log( 'login_ok', [
        'auth_user_id' => (int) $row['id'],
        'person_id' => (int) ( $row['person_id'] ?? 0 ),
        'login' => (string) ( $row['user_login'] ?? '' ),
    ] );
    metis_do_action( 'metis_login', (string) $row['user_login'], metis_current_user() );
}

function metis_auth_pending_login_start( array $row, string $redirect = '' ): void {
    $_SESSION['metis_pending_auth'] = [
        'auth_user_id' => (int) $row['id'],
        'person_id' => (int) ( $row['person_id'] ?? 0 ),
        'started_at' => time(),
        'redirect_to' => $redirect,
    ];
    metis_auth_log( 'mfa_required', [
        'auth_user_id' => (int) $row['id'],
        'person_id' => (int) ( $row['person_id'] ?? 0 ),
    ] );
}

function metis_auth_pending_login_row(): ?array {
    $pending = $_SESSION['metis_pending_auth'] ?? null;
    if ( ! is_array( $pending ) ) {
        return null;
    }

    if ( time() - (int) ( $pending['started_at'] ?? 0 ) > 600 ) {
        unset( $_SESSION['metis_pending_auth'] );
        return null;
    }

    $row = metis_auth_find_user( 'id', (int) ( $pending['auth_user_id'] ?? 0 ) );
    return is_array( $row ) ? $row : null;
}

function metis_auth_pending_login_redirect(): string {
    $pending = $_SESSION['metis_pending_auth'] ?? null;
    if ( ! is_array( $pending ) ) {
        return '';
    }

    return (string) ( $pending['redirect_to'] ?? '' );
}

function metis_auth_person_requires_totp( ?array $person ): bool {
    return is_array( $person ) && ! empty( $person['totp_enabled'] ) && ! empty( $person['requires_2fa'] );
}

function metis_auth_register_first_user( array $input ): array {
    metis_auth_ensure_schema();
    global $wpdb;

    $login = sanitize_key( (string) ( $input['user_login'] ?? '' ) );
    $email = sanitize_email( (string) ( $input['user_email'] ?? '' ) );
    $password = (string) ( $input['password'] ?? '' );
    $display = sanitize_text_field( (string) ( $input['display_name'] ?? '' ) );
    $first = sanitize_text_field( (string) ( $input['first_name'] ?? '' ) );
    $last = sanitize_text_field( (string) ( $input['last_name'] ?? '' ) );

    if ( $login === '' || $email === '' || ! is_email( $email ) ) {
        throw new InvalidArgumentException( 'Login and a valid email are required.' );
    }

    if ( strlen( $password ) < 12 ) {
        throw new InvalidArgumentException( 'Password must be at least 12 characters.' );
    }

    if ( metis_auth_has_users() ) {
        throw new RuntimeException( 'Initial account already exists.' );
    }

    if ( $display === '' ) {
        $display = trim( $first . ' ' . $last );
    }
    if ( $display === '' ) {
        $display = $login;
    }

    $wpdb->insert(
        metis_auth_table(),
        [
            'user_login' => $login,
            'user_email' => $email,
            'password_hash' => metis_auth_password_hash_for_storage( $password ),
            'display_name' => $display,
            'first_name' => $first,
            'last_name' => $last,
            'roles_json' => metis_json_encode( metis_auth_roles_default() ),
            'is_active' => 1,
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
    );

    $row = metis_auth_find_user( 'id', (int) $wpdb->insert_id );
    if ( ! is_array( $row ) ) {
        throw new RuntimeException( 'Failed to create initial account.' );
    }

    return $row;
}

function metis_auth_activate_existing_person( string $identifier, string $password, string $password_confirm ): array {
    $identifier = trim( $identifier );
    if ( $identifier === '' ) {
        throw new InvalidArgumentException( 'Email is required.' );
    }
    if ( strlen( $password ) < 12 ) {
        throw new InvalidArgumentException( 'Password must be at least 12 characters.' );
    }
    if ( ! hash_equals( $password, $password_confirm ) ) {
        throw new InvalidArgumentException( 'Passwords do not match.' );
    }

    $person = metis_auth_find_person_by_identifier( $identifier );
    if ( ! is_array( $person ) || empty( $person['id'] ) ) {
        metis_auth_log( 'activate_person_missing', [ 'identifier' => $identifier ] );
        throw new RuntimeException( 'No existing Metis account matched that email or login.' );
    }

    $row = metis_auth_upsert_user_from_person( $person, null, metis_auth_password_hash_for_storage( $password ) );
    metis_auth_log( 'activate_ok', [
        'identifier' => $identifier,
        'person_id' => (int) $person['id'],
        'auth_user_id' => (int) $row['id'],
    ] );
    return [
        'user' => $row,
        'person' => $person,
    ];
}

function metis_auth_authenticate_primary( string $identifier, string $password ): ?array {
    $identifier = trim( $identifier );
    if ( $identifier === '' || $password === '' ) {
        metis_auth_log( 'primary_empty', [ 'identifier_present' => $identifier !== '' ] );
        return null;
    }

    $user = is_email( $identifier )
        ? metis_auth_find_user( 'email', sanitize_email( $identifier ) )
        : metis_auth_find_user( 'login', sanitize_key( $identifier ) );

    if ( is_array( $user ) && ! empty( $user['is_active'] ) && metis_auth_check_password( $password, (string) ( $user['password_hash'] ?? '' ), (int) ( $user['id'] ?? 0 ) ) ) {
        $person = metis_auth_get_person( (int) ( $user['person_id'] ?? 0 ) );
        metis_auth_log( 'primary_auth_user_ok', [
            'identifier' => $identifier,
            'auth_user_id' => (int) $user['id'],
            'person_id' => (int) ( $user['person_id'] ?? 0 ),
        ] );
        return [
            'user' => $user,
            'person' => $person,
        ];
    }

    if ( is_array( $user ) ) {
        metis_auth_log( 'primary_auth_user_failed', [
            'identifier' => $identifier,
            'auth_user_id' => (int) $user['id'],
            'active' => ! empty( $user['is_active'] ),
        ] );
    } else {
        metis_auth_log( 'primary_auth_user_missing', [ 'identifier' => $identifier ] );
    }

    $person = metis_auth_find_person_by_identifier( $identifier );
    if ( ! is_array( $person ) || empty( $person['id'] ) ) {
        metis_auth_log( 'primary_person_missing', [ 'identifier' => $identifier ] );
        return null;
    }

    metis_auth_log( 'primary_link_required', [
        'identifier' => $identifier,
        'person_id' => (int) $person['id'],
    ] );

    metis_auth_log( 'primary_password_failed', [
        'identifier' => $identifier,
        'person_id' => (int) $person['id'],
    ] );
    return null;
}

function metis_auth_login( string $identifier, string $password ): bool {
    $auth = metis_auth_authenticate_primary( $identifier, $password );
    if ( ! is_array( $auth ) || ! is_array( $auth['user'] ?? null ) ) {
        return false;
    }

    metis_auth_finalize_login( $auth['user'] );
    return true;
}

function metis_auth_logout(): void {
    metis_do_action( 'metis_logout' );
    unset( $_SESSION['metis_auth_user_id'], $_SESSION['metis_person_id'], $_SESSION['metis_session_token'], $_SESSION['metis_session_integrity'], $_SESSION['metis_user'], $_SESSION['metis_pending_auth'] );
}

function metis_auth_login_path(): string {
    return '/login';
}

function metis_auth_login_url( string $redirect = '' ): string {
    $url = home_url( metis_auth_login_path() );
    if ( $redirect !== '' ) {
        $url = add_query_arg( [ 'redirect_to' => $redirect ], $url );
    }
    return $url;
}

function metis_auth_mfa_url( string $redirect = '' ): string {
    return add_query_arg( [ 'step' => 'mfa' ], metis_auth_login_url( $redirect ) );
}

function metis_auth_logout_path(): string {
    return '/logout';
}

function metis_auth_logout_url(): string {
    return home_url( metis_auth_logout_path() );
}

function metis_auth_render_shell( string $title, string $body, int $status = 200 ): never {
    status_header( $status );
    header( 'Content-Type: text/html; charset=UTF-8' );
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . esc_html( $title ) . '</title>';
    echo '<style>body{margin:0;font-family:Georgia,serif;background:linear-gradient(140deg,#edf2f7,#f7f2e7);color:#1f2330}.wrap{max-width:620px;margin:48px auto;padding:32px;background:rgba(255,255,255,.95);border:1px solid #d7dee7;box-shadow:0 20px 60px rgba(26,34,50,.08)}h1{margin:0 0 6px}h2{font-size:1.1rem;margin:28px 0 8px}p{line-height:1.5}label{display:block;font-weight:600;margin:12px 0 6px}input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #bcc8d6;background:#fff}button{margin-top:18px;padding:12px 18px;border:0;background:#1f3556;color:#fff;cursor:pointer}.error{margin:0 0 12px;padding:10px 12px;background:#fff2f0;border:1px solid #e1b0a9;color:#8a2a1f}.muted{color:#6b7280}.split{display:grid;gap:26px}.card{padding-top:6px;border-top:1px solid #e5e7eb}</style>';
    echo '</head><body><div class="wrap">' . $body . '</div></body></html>';
    exit;
}

function metis_auth_handle_request( Metis_Http_Request $request ): bool {
    $path = rtrim( $request->path(), '/' );
    if ( $path === '' ) {
        $path = '/';
    }

    if ( $path === metis_auth_logout_path() ) {
        metis_auth_logout();
        metis_redirect( metis_auth_login_url() );
    }

    if ( $path !== metis_auth_login_path() ) {
        return false;
    }

    $error = '';
    $pending = metis_auth_pending_login_row();
    $pending_person = $pending ? metis_auth_get_person( (int) ( $pending['person_id'] ?? 0 ) ) : null;
    $step = sanitize_key( (string) ( $_GET['step'] ?? '' ) );
    $redirect = isset( $_GET['redirect_to'] ) ? (string) $_GET['redirect_to'] : metis_auth_pending_login_redirect();
    if ( $redirect === '' ) {
        $redirect = metis_portal_url();
    }
    $show_mfa = $step === 'mfa' && is_array( $pending ) && is_array( $pending_person );
    $needs_bootstrap = ! metis_auth_has_users() && metis_auth_legacy_people_without_auth_count() === 0;

    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        $mode = sanitize_key( (string) ( $_POST['mode'] ?? 'login' ) );

        try {
            if ( $mode === 'bootstrap' && $needs_bootstrap ) {
                $user = metis_auth_register_first_user( [
                    'user_login' => (string) ( $_POST['user_login'] ?? '' ),
                    'user_email' => (string) ( $_POST['user_email'] ?? '' ),
                    'password' => (string) ( $_POST['password'] ?? '' ),
                    'display_name' => (string) ( $_POST['display_name'] ?? '' ),
                    'first_name' => (string) ( $_POST['first_name'] ?? '' ),
                    'last_name' => (string) ( $_POST['last_name'] ?? '' ),
                ] );
                metis_auth_finalize_login( $user );
                metis_redirect( $redirect );
            } elseif ( $mode === 'activate' || $mode === 'create_linked' ) {
                $result = metis_auth_activate_existing_person(
                    (string) ( $_POST['identifier'] ?? '' ),
                    (string) ( $_POST['password'] ?? '' ),
                    (string) ( $_POST['password_confirm'] ?? '' )
                );

                if ( metis_auth_person_requires_totp( $result['person'] ?? null ) ) {
                    metis_auth_pending_login_start( $result['user'], $redirect );
                    metis_redirect( metis_auth_mfa_url( $redirect ) );
                } else {
                    metis_auth_finalize_login( $result['user'] );
                    metis_redirect( $redirect );
                }
            } elseif ( $mode === 'mfa_totp' ) {
                if ( ! is_array( $pending ) || ! is_array( $pending_person ) ) {
                    throw new RuntimeException( 'Your login session expired. Please sign in again.' );
                }
                if ( ! metis_auth_verify_totp_code( $pending_person, (string) ( $_POST['code'] ?? '' ) ) ) {
                    throw new RuntimeException( 'Authenticator code was not valid.' );
                }
                metis_auth_finalize_login( $pending );
                metis_redirect( $redirect );
            } else {
                $result = metis_auth_authenticate_primary(
                    (string) ( $_POST['identifier'] ?? '' ),
                    (string) ( $_POST['password'] ?? '' )
                );
                if ( ! is_array( $result ) || ! is_array( $result['user'] ?? null ) ) {
                    metis_do_action( 'metis_login_failed', (string) ( $_POST['identifier'] ?? '' ) );
                    throw new RuntimeException( 'Invalid login or password.' );
                }

                if ( metis_auth_person_requires_totp( $result['person'] ?? null ) ) {
                    metis_auth_pending_login_start( $result['user'], $redirect );
                    metis_redirect( metis_auth_mfa_url( $redirect ) );
                } else {
                    metis_auth_finalize_login( $result['user'] );
                    metis_redirect( $redirect );
                }
            }
        } catch ( Throwable $e ) {
            $error = $e->getMessage();
            $pending = metis_auth_pending_login_row();
            $pending_person = $pending ? metis_auth_get_person( (int) ( $pending['person_id'] ?? 0 ) ) : null;
        }
    }

    if ( $show_mfa ) {
        $body = '<h1>Verify your sign in</h1><p class="muted">Enter the 6-digit code from your authenticator app to finish signing in.</p>';
        if ( $error !== '' ) {
            $body .= '<div class="error">' . esc_html( $error ) . '</div>';
        }
        $body .= '<form method="post"><input type="hidden" name="mode" value="mfa_totp">';
        $body .= '<label for="code">Authenticator code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>';
        $body .= '<button type="submit">Verify</button></form>';
        $body .= '<p><a href="' . esc_url( metis_auth_login_url( $redirect ) ) . '">Back to sign in</a></p>';
        if ( ! empty( $pending_person['passkey_enabled'] ) ) {
            $body .= '<p class="muted">Passkey sign-in is not wired yet in standalone mode, but your existing passkeys remain stored.</p>';
        }
        metis_auth_render_shell( 'Verify Sign In', $body );
    }

    if ( metis_auth_has_users() || metis_auth_legacy_people_without_auth_count() > 0 ) {
        $body = '<h1>Sign in to Metis</h1><p class="muted">Standalone Metis signs in native accounts and linked Metis profiles.</p>';
        if ( $error !== '' ) {
            $body .= '<div class="error">' . esc_html( $error ) . '</div>';
        }
        $body .= '<div class="split">';
        $body .= '<div class="card"><form method="post"><input type="hidden" name="mode" value="login">';
        $body .= '<label for="identifier">Email or profile ID</label><input id="identifier" name="identifier" required autofocus>';
        $body .= '<label for="password">Password</label><input id="password" name="password" type="password" required>';
        $body .= '<button type="submit">Sign In</button></form></div>';
        $body .= '<div class="card"><h2>Create linked account</h2><p class="muted">If your people profile already exists, use your profile email or profile ID here. Metis will create a standalone account linked to that profile.</p>';
        $body .= '<form method="post"><input type="hidden" name="mode" value="create_linked">';
        $body .= '<label for="activate_identifier">Profile email or ID</label><input id="activate_identifier" name="identifier" required>';
        $body .= '<label for="activate_password">New password</label><input id="activate_password" name="password" type="password" minlength="12" required>';
        $body .= '<label for="activate_password_confirm">Confirm password</label><input id="activate_password_confirm" name="password_confirm" type="password" minlength="12" required>';
        $body .= '<button type="submit">Create Linked Account</button></form></div>';
        $body .= '</div>';
        metis_auth_render_shell( 'Metis Login', $body );
    }

    $body = '<h1>Create the first Metis account</h1>';
    if ( $error !== '' ) {
        $body .= '<div class="error">' . esc_html( $error ) . '</div>';
    }
    $body .= '<form method="post"><input type="hidden" name="mode" value="bootstrap">';
    $body .= '<label for="user_login">Login</label><input id="user_login" name="user_login" required autofocus>';
    $body .= '<label for="user_email">Email</label><input id="user_email" name="user_email" type="email" required>';
    $body .= '<label for="display_name">Display Name</label><input id="display_name" name="display_name">';
    $body .= '<label for="first_name">First Name</label><input id="first_name" name="first_name">';
    $body .= '<label for="last_name">Last Name</label><input id="last_name" name="last_name">';
    $body .= '<label for="password">Password</label><input id="password" name="password" type="password" minlength="12" required>';
    $body .= '<button type="submit">Create Admin Account</button></form>';
    metis_auth_render_shell( 'Create Metis Account', $body );
}
