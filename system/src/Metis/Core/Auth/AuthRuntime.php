<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

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

function metis_auth_db(): \Metis\Services\DatabaseService {
    return function_exists( 'metis_db' ) ? metis_db() : new \Metis\Services\DatabaseService();
}

function metis_auth_password_security_service(): \Metis\Core\Auth\PasswordSecurityService {
    if ( class_exists( '\Metis\Core\Application' ) && \Metis\Core\Application::has_service( 'password_security' ) ) {
        return \Metis\Core\Application::service( 'password_security' );
    }

    return new \Metis\Core\Auth\PasswordSecurityService();
}

function metis_auth_protection_service(): \Metis\Core\Security\AuthProtectionService {
    if ( class_exists( '\Metis\Core\Application' ) && \Metis\Core\Application::has_service( 'auth_protection' ) ) {
        return \Metis\Core\Application::service( 'auth_protection' );
    }

    $threat_store = new \Metis\Core\Security\ThreatScoreStore();

    return new \Metis\Core\Security\AuthProtectionService(
        new \Metis\Core\Security\SecurityKernel(
            new \Metis\Core\Security\NonceManager(),
            new \Metis\Core\Security\CsrfManager(),
            new \Metis\Core\Security\RateLimiter(),
            new \Metis\Core\Security\RequestFingerprint(),
            new \Metis\Core\Security\ThreatScoreEngine( $threat_store ),
            $threat_store,
            new \Metis\Core\Security\AuditLogger(),
            new \Metis\Core\Security\BehaviorProfiler()
        )
    );
}

function metis_auth_set_flash_notice( string $message, string $type = 'info' ): void {
    $_SESSION['metis_auth_notice'] = [
        'message' => metis_text_clean( $message ),
        'type' => metis_key_clean( $type ) ?: 'info',
    ];
}

function metis_auth_consume_flash_notice(): ?array {
    $notice = $_SESSION['metis_auth_notice'] ?? null;
    unset( $_SESSION['metis_auth_notice'] );

    return is_array( $notice ) ? $notice : null;
}

function metis_auth_roles_default(): array {
    return [ 'administrator', 'board', 'donor_admin', 'newsletter_admin', 'workspace_manager' ];
}

function metis_auth_decode_roles( mixed $value ): array {
    if ( is_array( $value ) ) {
        return array_values( array_filter( array_map( 'metis_key_clean', $value ) ) );
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
    return (int) metis_auth_db()->scalar( 'SELECT COUNT(*) FROM ' . metis_auth_table() ) > 0;
}

function metis_auth_table_exists( string $table ): bool {
    $found = metis_auth_db()->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
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
    return metis_auth_password_check_result( $password, $hash, $user_id )['valid'];
}

function metis_auth_password_check_result( string $password, string $hash, int|string $user_id = '' ): array {
    $result = [
        'valid' => false,
        'legacy' => false,
        'algorithm' => '',
    ];

    if ( $hash === '' ) {
        return $result;
    }

    if ( password_verify( $password, $hash ) ) {
        $result['valid'] = true;
        $result['algorithm'] = 'password_hash';
        return $result;
    }

    if ( metis_auth_check_portable_phpass( $password, $hash ) ) {
        $result['valid'] = true;
        $result['legacy'] = true;
        $result['algorithm'] = 'phpass';
        return $result;
    }

    if ( strlen( $hash ) === 32 && ctype_xdigit( $hash ) ) {
        $result['valid'] = hash_equals( strtolower( $hash ), md5( $password ) );
        $result['legacy'] = $result['valid'];
        $result['algorithm'] = $result['valid'] ? 'md5' : '';
        return $result;
    }

    return $result;
}

function metis_auth_password_hash_for_storage( string $password ): string {
    return metis_auth_password_security_service()->hash( $password );
}

function metis_auth_identifier_digest( string $value ): string {
    return hash( 'sha256', trim( $value ) );
}

function metis_auth_identifier_equals( string $left, string $right ): bool {
    return hash_equals( metis_auth_identifier_digest( $left ), metis_auth_identifier_digest( $right ) );
}

function metis_auth_normalize_identifier( string $identifier ): array {
    $identifier = trim( $identifier );
    $email = strtolower( trim( metis_email_clean( $identifier ) ) );
    $login = metis_key_clean( $identifier );
    $pid = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $identifier ) ?? '' );

    return [
        'raw' => $identifier,
        'email' => $email !== '' && metis_email_is_valid( $email ) ? $email : '',
        'login' => $login,
        'pid' => $pid,
    ];
}

function metis_auth_find_user_by_identifier( string $identifier ): ?array {
    metis_auth_ensure_schema();

    $table = metis_auth_table();
    $normalized = metis_auth_normalize_identifier( $identifier );
    $candidates = [];

    if ( $normalized['email'] !== '' ) {
        foreach ( metis_auth_db()->fetchAll(
            "SELECT * FROM {$table} WHERE LOWER(user_email) = LOWER(%s) LIMIT 3",
            [ $normalized['email'] ]
        ) as $row ) {
            if ( is_array( $row ) ) {
                $candidates[] = $row;
            }
        }
    }

    if ( $normalized['login'] !== '' ) {
        foreach ( metis_auth_db()->fetchAll(
            "SELECT * FROM {$table} WHERE user_login = %s LIMIT 3",
            [ $normalized['login'] ]
        ) as $row ) {
            if ( is_array( $row ) ) {
                $candidates[] = $row;
            }
        }
    }

    foreach ( $candidates as $row ) {
        $login = metis_key_clean( (string) ( $row['user_login'] ?? '' ) );
        $email = strtolower( trim( (string) ( $row['user_email'] ?? '' ) ) );

        if ( $normalized['email'] !== '' && $email !== '' && metis_auth_identifier_equals( $normalized['email'], $email ) ) {
            return $row;
        }

        if ( $normalized['login'] !== '' && $login !== '' && metis_auth_identifier_equals( $normalized['login'], $login ) ) {
            return $row;
        }
    }

    return null;
}

function metis_auth_person_password_hash( ?array $person ): string {
    if ( ! is_array( $person ) ) {
        return '';
    }

    $hash = trim( (string) ( $person['password_hash'] ?? '' ) );
    return str_starts_with( $hash, '$' ) ? $hash : '';
}

function metis_auth_password_hash_for_authentication( ?array $user, ?array $person = null ): string {
    $person = is_array( $person ) ? $person : ( is_array( $user ) ? metis_auth_get_person( (int) ( $user['person_id'] ?? 0 ) ) : null );
    $person_hash = metis_auth_person_password_hash( $person );
    if ( $person_hash !== '' ) {
        return $person_hash;
    }

    if ( ! is_array( $user ) ) {
        return '';
    }

    $auth_hash = trim( (string) ( $user['password_hash'] ?? '' ) );
    return $auth_hash !== '' ? $auth_hash : '';
}

function metis_auth_store_password_hash( array $user, ?array $person, string $password_hash, bool $invalidate_sessions = false ): array {
    $auth_user_id = (int) ( $user['id'] ?? 0 );
    $person_id = (int) ( $user['person_id'] ?? ( $person['id'] ?? 0 ) );

    if ( $auth_user_id > 0 ) {
        metis_auth_db()->update(
            metis_auth_table(),
            [ 'password_hash' => $password_hash ],
            [ 'id' => $auth_user_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    if ( $person_id > 0 && metis_auth_legacy_people_available() ) {
        if ( function_exists( 'metis_people_ensure_schema' ) ) {
            metis_people_ensure_schema();
        }
        metis_auth_db()->update(
            metis_auth_people_table(),
            [
                'password_hash' => $password_hash,
                'updated_at' => metis_current_time( 'mysql' ),
            ],
            [ 'id' => $person_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    if ( $invalidate_sessions && $auth_user_id > 0 ) {
        metis_auth_protection_service()->invalidateUserSessions( $auth_user_id );
    }

    $updated = $auth_user_id > 0 ? metis_auth_find_user( 'id', $auth_user_id ) : null;
    if ( is_array( $updated ) ) {
        $updated['password_hash'] = $password_hash;
        return $updated;
    }

    $user['password_hash'] = $password_hash;
    return $user;
}

function metis_auth_should_nudge_passkey( ?array $person ): bool {
    if ( ! is_array( $person ) || empty( $person['id'] ) || ! empty( $person['passkey_enabled'] ) ) {
        return false;
    }

    if ( ! class_exists( 'Metis_Tables' ) || ! Metis_Tables::has( 'people_passkeys' ) ) {
        return true;
    }

    $count = (int) metis_auth_db()->scalar(
        'SELECT COUNT(*) FROM ' . Metis_Tables::get( 'people_passkeys' ) . ' WHERE person_id = %d AND revoked_at IS NULL',
        [ (int) $person['id'] ]
    );

    return $count < 1;
}

function metis_auth_find_user( string $field, string|int $value ): ?array {
    metis_auth_ensure_schema();

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
    $row = metis_auth_db()->fetchOne( 'SELECT * FROM ' . metis_auth_table() . " WHERE {$field} = {$placeholder} LIMIT 1", [ $value ] );

    return is_array( $row ) ? $row : null;
}

function metis_auth_person_roles( int $person_id ): array {
    if ( $person_id < 1 || ! Metis_Tables::has( 'people_roles' ) || ! Metis_Tables::has( 'people_user_roles' ) ) {
        return [];
    }

    $roles_table = Metis_Tables::get( 'people_roles' );
    $user_roles_table = Metis_Tables::get( 'people_user_roles' );
    $rows = metis_auth_db()->column(
        "SELECT DISTINCT r.role_key
             FROM {$user_roles_table} ur
             INNER JOIN {$roles_table} r ON r.id = ur.role_id
             WHERE ur.person_id = %d
               AND (ur.start_at IS NULL OR ur.start_at <= NOW())
               AND (ur.end_at IS NULL OR ur.end_at >= NOW())",
        [ $person_id ]
    ) ?: [];

    $roles = [];
    foreach ( $rows as $row ) {
        $role = metis_key_clean( (string) $row );
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

    $row = metis_auth_db()->fetchOne( 'SELECT * FROM ' . metis_auth_people_table() . ' WHERE id = %d LIMIT 1', [ $person_id ] );

    return is_array( $row ) ? $row : null;
}

function metis_auth_current_person_id(): int {
    return (int) ( $_SESSION['metis_person_id'] ?? 0 );
}

function metis_auth_person_row_to_auth_payload( array $person, ?array $auth_user = null ): array {
    $email = strtolower( trim( (string) ( $person['email'] ?? '' ) ) );
    if ( $email === '' && is_array( $auth_user ) ) {
        $email = strtolower( trim( (string) ( $auth_user['user_email'] ?? '' ) ) );
    }

    $login = '';
    if ( is_array( $auth_user ) ) {
        $login = metis_key_clean( (string) ( $auth_user['user_login'] ?? '' ) );
    }
    if ( $login === '' ) {
        $login = metis_key_clean( (string) strstr( $email, '@', true ) );
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

    $people_table = metis_auth_people_table();
    $normalized = metis_auth_normalize_identifier( $identifier );
    $candidates = [];

    if ( $normalized['email'] !== '' ) {
        foreach ( metis_auth_db()->fetchAll(
            "SELECT * FROM {$people_table}
             WHERE LOWER(email) = LOWER(%s)
                OR LOWER(workspace_email) = LOWER(%s)
             LIMIT 3",
            [ $normalized['email'], $normalized['email'] ]
        ) as $row ) {
            if ( is_array( $row ) ) {
                $candidates[] = $row;
            }
        }
    }

    if ( $normalized['pid'] !== '' ) {
        foreach ( metis_auth_db()->fetchAll(
            "SELECT * FROM {$people_table} WHERE UPPER(pid) = UPPER(%s) LIMIT 3",
            [ $normalized['pid'] ]
        ) as $row ) {
            if ( is_array( $row ) ) {
                $candidates[] = $row;
            }
        }
    }

    foreach ( $candidates as $row ) {
        $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
        $workspace_email = strtolower( trim( (string) ( $row['workspace_email'] ?? '' ) ) );
        $pid = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) ( $row['pid'] ?? '' ) ) ?? '' );

        if ( $normalized['email'] !== '' ) {
            if ( $email !== '' && metis_auth_identifier_equals( $normalized['email'], $email ) ) {
                return $row;
            }
            if ( $workspace_email !== '' && metis_auth_identifier_equals( $normalized['email'], $workspace_email ) ) {
                return $row;
            }
        }

        if ( $normalized['pid'] !== '' && $pid !== '' && metis_auth_identifier_equals( $normalized['pid'], $pid ) ) {
            return $row;
        }
    }

    return null;
}

function metis_auth_legacy_people_without_auth_count(): int {
    if ( ! metis_auth_legacy_people_available() ) {
        return 0;
    }

    $people_table = metis_auth_people_table();
    $auth_table = metis_auth_table();

    return (int) metis_auth_db()->scalar(
        "SELECT COUNT(*)
         FROM {$people_table} p
         LEFT JOIN {$auth_table} a ON a.person_id = p.id
         WHERE a.id IS NULL"
    );
}

function metis_auth_upsert_user_from_person( array $person, ?array $auth_user = null, string $password_hash = '' ): array {
    metis_auth_ensure_schema();

    $payload = metis_auth_person_row_to_auth_payload( $person, $auth_user );
    $existing = metis_auth_find_user( 'person_id', (int) $payload['person_id'] );
    if ( ! $existing && $payload['user_email'] !== '' ) {
        $existing = metis_auth_find_user( 'email', $payload['user_email'] );
    }
    if ( ! $existing && $payload['user_login'] !== '' ) {
        $existing = metis_auth_find_user( 'login', $payload['user_login'] );
    }

    if ( $password_hash === '' && is_array( $existing ) ) {
        $password_hash = metis_auth_password_hash_for_authentication( $existing, $person );
    }
    if ( $password_hash === '' && is_array( $auth_user ) ) {
        $password_hash = (string) ( $auth_user['user_pass'] ?? '' );
    }
    if ( $password_hash === '' ) {
        $password_hash = metis_auth_person_password_hash( $person );
    }

    $payload['password_hash'] = $password_hash;

    if ( is_array( $existing ) ) {
        metis_auth_log( 'upsert_update', [
            'auth_user_id' => (int) $existing['id'],
            'person_id' => (int) $payload['person_id'],
            'login' => (string) $payload['user_login'],
            'email' => (string) $payload['user_email'],
        ] );
        metis_auth_db()->update(
            metis_auth_table(),
            $payload,
            [ 'id' => (int) $existing['id'] ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );
        $updated = metis_auth_find_user( 'id', (int) $existing['id'] ) ?? $existing;
        if ( $password_hash !== '' ) {
            $updated = metis_auth_store_password_hash( $updated, $person, $password_hash );
        }
        return $updated;
    }

    metis_auth_db()->insert(
        metis_auth_table(),
        $payload,
        [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
    );

    $row = metis_auth_find_user( 'id', metis_auth_db()->lastInsertId() );
    if ( ! is_array( $row ) ) {
        throw new RuntimeException( 'Failed to persist auth user.' );
    }

    if ( $password_hash !== '' ) {
        $row = metis_auth_store_password_hash( $row, $person, $password_hash );
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
    return hash( 'sha256', metis_runtime_require_app_key( 'auth secret encryption' ), true );
}

function metis_auth_legacy_secret_key_bytes(): string {
    $auth_key = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : metis_runtime_require_app_key( 'legacy auth secret encryption' );
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
        'user_pass' => metis_auth_password_hash_for_authentication( $row, $person ),
    ];
}

function metis_auth_current_method(): string {
    return metis_key_clean( (string) ( $_SESSION['metis_auth_method'] ?? '' ) );
}

function metis_auth_session_ttl_env_int( string $env_key, int $fallback ): int {
    $raw = getenv( $env_key );
    if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
        return max( 60, $fallback );
    }

    $value = (int) trim( $raw );
    return $value > 0 ? $value : max( 60, $fallback );
}

function metis_auth_session_absolute_ttl_seconds(): int {
    return metis_auth_session_ttl_env_int( 'METIS_AUTH_SESSION_ABSOLUTE_TTL', 12 * HOUR_IN_SECONDS );
}

function metis_auth_session_idle_ttl_seconds(): int {
    return metis_auth_session_ttl_env_int( 'METIS_AUTH_SESSION_IDLE_TTL', 30 * MINUTE_IN_SECONDS );
}

function metis_auth_session_is_expired( int $now = 0 ): bool {
    if ( ! metis_user_logged_in() ) {
        return false;
    }

    $now = $now > 0 ? $now : time();
    $issued_at = (int) ( $_SESSION['metis_auth_issued_at'] ?? 0 );
    $last_activity = (int) ( $_SESSION['metis_auth_last_activity_at'] ?? $issued_at );

    if ( $issued_at > 0 && ( $now - $issued_at ) > metis_auth_session_absolute_ttl_seconds() ) {
        return true;
    }

    if ( $last_activity > 0 && ( $now - $last_activity ) > metis_auth_session_idle_ttl_seconds() ) {
        return true;
    }

    return false;
}

function metis_auth_refresh_session(): void {
    $user_id = (int) ( $_SESSION['metis_auth_user_id'] ?? 0 );
    if ( $user_id < 1 ) {
        unset( $_SESSION['metis_user'], $_SESSION['metis_person_id'], $_SESSION['metis_session_integrity'], $_SESSION['metis_auth_issued_at'], $_SESSION['metis_auth_last_activity_at'] );
        return;
    }

    if ( metis_auth_session_is_expired() ) {
        metis_auth_log( 'session_expired', [
            'auth_user_id' => $user_id,
            'request_id' => function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : '',
        ] );
        metis_auth_logout();
        return;
    }

    $row = metis_auth_find_user( 'id', $user_id );
    if ( ! is_array( $row ) || empty( $row['is_active'] ) || metis_auth_session_is_revoked( $user_id ) ) {
        metis_auth_logout();
        return;
    }

    $_SESSION['metis_user'] = metis_auth_user_row_to_session( $row );
    $_SESSION['metis_person_id'] = (int) ( $row['person_id'] ?? 0 );
    if ( ! isset( $_SESSION['metis_auth_method'] ) ) {
        $_SESSION['metis_auth_method'] = 'session';
    }
    $_SESSION['metis_auth_last_activity_at'] = time();
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
    if ( class_exists( '\Metis\Core\Application' ) && \Metis\Core\Application::has_service( 'security_kernel' ) ) {
        return \Metis\Core\Application::service( 'security_kernel' )->fingerprints()->sessionIntegrityFingerprint(
            \Metis\Core\Application::service( 'security_kernel' )->buildContext(
                'auth.session.integrity',
                [ 'auth_method' => 'session' ],
                [ 'auth_method' => 'session' ]
            )
        );
    }

    $session_token = (string) ( $_SESSION['metis_session_token'] ?? '' );
    if ( $session_token === '' ) {
        $session_token = (string) metis_runtime_session_token();
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

    $user_id = (int) ( $_SESSION['metis_auth_user_id'] ?? 0 );
    if ( $user_id > 0 && metis_auth_session_is_revoked( $user_id ) ) {
        metis_auth_logout();
        return false;
    }

    if ( class_exists( '\Metis\Core\Application' ) && \Metis\Core\Application::has_service( 'security_authorization_gate' ) ) {
        $context = \Metis\Core\Application::service( 'security_kernel' )->buildContext(
            'auth.session.integrity',
            [ 'auth_method' => 'session' ],
            [ 'auth_method' => 'session' ]
        );

        return \Metis\Core\Application::service( 'security_authorization_gate' )->hasValidSession( $context );
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

function metis_auth_session_is_revoked( int $auth_user_id ): bool {
    if ( $auth_user_id < 1 ) {
        return false;
    }

    $issued_at = (int) ( $_SESSION['metis_auth_issued_at'] ?? 0 );
    $invalid_after = metis_auth_protection_service()->sessionInvalidAfter( $auth_user_id );

    return $invalid_after > 0 && $issued_at > 0 && $issued_at <= $invalid_after;
}

function metis_auth_finalize_login( array $row, string $auth_method = 'password' ): void {
    if ( \Metis\Core\Application::has_service( 'auth_sessions' ) ) {
        \Metis\Core\Application::service( 'auth_sessions' )->createSession( $row, $auth_method );
        return;
    }

    if ( \Metis\Core\Application::has_service( 'session_security' ) ) {
        \Metis\Core\Application::service( 'session_security' )->regenerateId();
    }

    $_SESSION['metis_auth_user_id'] = (int) $row['id'];
    $_SESSION['metis_person_id'] = (int) ( $row['person_id'] ?? 0 );
    $_SESSION['metis_auth_issued_at'] = time();
    $_SESSION['metis_auth_last_activity_at'] = (int) $_SESSION['metis_auth_issued_at'];
    $_SESSION['metis_session_token'] = bin2hex( random_bytes( 16 ) );
    $_SESSION['metis_user'] = metis_auth_user_row_to_session( $row );
    $_SESSION['metis_auth_method'] = metis_key_clean( $auth_method );
    metis_auth_refresh_session_integrity();
    unset( $_SESSION['metis_pending_auth'], $_SESSION['metis_auth_password_verified_at'] );
}

function metis_auth_pending_login_start( array $row, string $redirect = '' ): void {
    if ( \Metis\Core\Application::has_service( 'auth_mfa' ) ) {
        $person = metis_auth_get_person( (int) ( $row['person_id'] ?? 0 ) );
        \Metis\Core\Application::service( 'auth_mfa' )->beginPasswordChallenge( $row, $person, $redirect );
        return;
    }

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
    if ( \Metis\Core\Application::has_service( 'auth_mfa' ) ) {
        return \Metis\Core\Application::service( 'auth_mfa' )->pendingLoginRow();
    }

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
    if ( \Metis\Core\Application::has_service( 'auth_mfa' ) ) {
        return \Metis\Core\Application::service( 'auth_mfa' )->pendingRedirect();
    }

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

    $login = metis_key_clean( (string) ( $input['user_login'] ?? '' ) );
    $email = metis_email_clean( (string) ( $input['user_email'] ?? '' ) );
    $password = (string) ( $input['password'] ?? '' );
    $display = metis_text_clean( (string) ( $input['display_name'] ?? '' ) );
    $first = metis_text_clean( (string) ( $input['first_name'] ?? '' ) );
    $last = metis_text_clean( (string) ( $input['last_name'] ?? '' ) );

    if ( $login === '' || $email === '' || ! metis_email_is_valid( $email ) ) {
        throw new InvalidArgumentException( 'Login and a valid email are required.' );
    }

    if ( strlen( $password ) < 12 ) {
        throw new InvalidArgumentException( 'Password must be at least 12 characters.' );
    }

    metis_auth_password_security_service()->assertPasswordAllowed( $password );

    if ( metis_auth_has_users() ) {
        throw new RuntimeException( 'Initial account already exists.' );
    }

    if ( $display === '' ) {
        $display = trim( $first . ' ' . $last );
    }
    if ( $display === '' ) {
        $display = $login;
    }

    metis_auth_db()->insert(
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

    $row = metis_auth_find_user( 'id', metis_auth_db()->lastInsertId() );
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

    metis_auth_password_security_service()->assertPasswordAllowed( $password );

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

    $user = metis_auth_find_user_by_identifier( $identifier );
    $person = metis_auth_find_person_by_identifier( $identifier );

    if ( ! is_array( $user ) && is_array( $person ) ) {
        $user = metis_auth_find_user( 'person_id', (int) ( $person['id'] ?? 0 ) );
        if ( ! is_array( $user ) ) {
            $person_hash = metis_auth_person_password_hash( $person );
            if ( $person_hash !== '' ) {
                $user = metis_auth_upsert_user_from_person( $person, null, $person_hash );
            }
        }
    }

    if ( ! is_array( $person ) && is_array( $user ) ) {
        $person = metis_auth_get_person( (int) ( $user['person_id'] ?? 0 ) );
    }

    $hash = metis_auth_password_hash_for_authentication( $user, $person );

    $password_check = is_array( $user )
        ? metis_auth_password_check_result( $password, $hash, (int) ( $user['id'] ?? 0 ) )
        : [ 'valid' => false, 'legacy' => false, 'algorithm' => '' ];

    if ( is_array( $user ) && ! empty( $user['is_active'] ) && ! empty( $password_check['valid'] ) ) {
        if ( is_array( $person ) && metis_auth_person_password_hash( $person ) === '' && str_starts_with( $hash, '$' ) ) {
            $user = metis_auth_store_password_hash( $user, $person, $hash );
            $person['password_hash'] = $hash;
        }

        $rehash = '';
        if ( ! empty( $password_check['legacy'] ) ) {
            $rehash = metis_auth_password_hash_for_storage( $password );
        } else {
            $rehash = (string) ( metis_auth_password_security_service()->rehashIfNeeded( $password, $hash ) ?? '' );
        }

        if ( $rehash !== '' ) {
            $user = metis_auth_store_password_hash( $user, $person, $rehash );
            metis_auth_log( ! empty( $password_check['legacy'] ) ? 'legacy_password_migrated' : 'password_rehashed', [
                'identifier' => $identifier,
                'auth_user_id' => (int) $user['id'],
                'algorithm' => (string) ( $password_check['algorithm'] ?? '' ),
            ] );
        }

        $_SESSION['metis_auth_password_verified_at'] = time();
        metis_auth_log( 'primary_auth_user_ok', [
            'identifier' => sha1( strtolower( trim( $identifier ) ) ),
            'auth_user_id' => (int) $user['id'],
            'person_id' => (int) ( $user['person_id'] ?? 0 ),
            'algorithm' => (string) ( $password_check['algorithm'] ?? '' ),
        ] );
        return [
            'user' => $user,
            'person' => $person,
        ];
    }

    if ( is_array( $user ) ) {
        metis_auth_log( 'primary_auth_user_failed', [
            'identifier' => sha1( strtolower( trim( $identifier ) ) ),
            'auth_user_id' => (int) $user['id'],
            'active' => ! empty( $user['is_active'] ),
        ] );
    }

    metis_auth_log( 'primary_password_failed', [
        'identifier' => sha1( strtolower( trim( $identifier ) ) ),
        'person_id' => (int) ( $person['id'] ?? 0 ),
    ] );
    return null;
}

function metis_auth_login( string $identifier, string $password ): bool {
    metis_auth_protection_service()->assertLoginAllowed( $identifier );
    $auth = metis_auth_authenticate_primary( $identifier, $password );
    if ( ! is_array( $auth ) || ! is_array( $auth['user'] ?? null ) ) {
        metis_auth_protection_service()->recordFailedLogin( $identifier );
        return false;
    }

    metis_auth_protection_service()->clearFailedLogins( $identifier, (array) $auth['user'] );
    metis_auth_finalize_login( $auth['user'] );
    return true;
}

function metis_auth_change_password_for_person( int $person_id, string $current_password, string $new_password, string $confirm_password ): array {
    if ( $person_id < 1 ) {
        throw new RuntimeException( 'Profile not found.' );
    }

    $person = metis_auth_get_person( $person_id );
    $user = metis_auth_find_user( 'person_id', $person_id );
    if ( ! is_array( $user ) || ! is_array( $person ) ) {
        throw new RuntimeException( 'Password sign-in is not available for this profile.' );
    }

    if ( $current_password === '' ) {
        throw new RuntimeException( 'Current password is required.' );
    }
    if ( strlen( $new_password ) < 12 ) {
        throw new RuntimeException( 'Password must be at least 12 characters.' );
    }
    if ( ! hash_equals( $new_password, $confirm_password ) ) {
        throw new RuntimeException( 'Password confirmation does not match.' );
    }

    $hash = metis_auth_password_hash_for_authentication( $user, $person );
    $check = metis_auth_password_check_result( $current_password, $hash, (int) ( $user['id'] ?? 0 ) );
    if ( empty( $check['valid'] ) ) {
        throw new RuntimeException( 'Current password was not valid.' );
    }

    metis_auth_password_security_service()->assertPasswordAllowed( $new_password );

    $updated = metis_auth_store_password_hash(
        $user,
        $person,
        metis_auth_password_hash_for_storage( $new_password ),
        true
    );

    if ( function_exists( 'metis_audit_log_activity' ) ) {
        metis_audit_log_activity( 'auth_password_changed', [
            'user_id' => (int) ( $updated['id'] ?? 0 ),
            'module' => 'core',
            'request_id' => metis_audit_request_id(),
            'context' => [
                'auth_user_id' => (int) ( $updated['id'] ?? 0 ),
                'person_id' => $person_id,
                'event' => 'password_change',
            ],
        ] );
    }

    return [
        'user' => $updated,
        'person' => $person,
    ];
}

function metis_auth_set_initial_password_for_person( int $person_id, string $new_password, string $confirm_password ): array {
    if ( $person_id < 1 ) {
        throw new RuntimeException( 'Profile not found.' );
    }

    $person = metis_auth_get_person( $person_id );
    if ( ! is_array( $person ) ) {
        throw new RuntimeException( 'Profile not found.' );
    }

    if ( strlen( $new_password ) < 12 ) {
        throw new RuntimeException( 'Password must be at least 12 characters.' );
    }
    if ( ! hash_equals( $new_password, $confirm_password ) ) {
        throw new RuntimeException( 'Password confirmation does not match.' );
    }

    metis_auth_password_security_service()->assertPasswordAllowed( $new_password );

    $user = metis_auth_find_user( 'person_id', $person_id );
    if ( ! is_array( $user ) ) {
        $user = metis_auth_upsert_user_from_person(
            $person,
            null,
            metis_auth_password_hash_for_storage( $new_password )
        );
    } else {
        $existing_hash = metis_auth_password_hash_for_authentication( $user, $person );
        if ( $existing_hash !== '' ) {
            throw new RuntimeException( 'A Metis password already exists for this profile.' );
        }

        $user = metis_auth_store_password_hash(
            $user,
            $person,
            metis_auth_password_hash_for_storage( $new_password ),
            true
        );
    }

    if ( function_exists( 'metis_audit_log_activity' ) ) {
        metis_audit_log_activity( 'auth_password_created', [
            'user_id' => (int) ( $user['id'] ?? 0 ),
            'module' => 'core',
            'request_id' => metis_audit_request_id(),
            'context' => [
                'auth_user_id' => (int) ( $user['id'] ?? 0 ),
                'person_id' => $person_id,
                'event' => 'password_create',
            ],
        ] );
    }

    return [
        'user' => $user,
        'person' => $person,
    ];
}

function metis_auth_set_session_password_for_person( int $person_id, string $new_password, string $confirm_password ): array {
    if ( $person_id < 1 ) {
        throw new RuntimeException( 'Profile not found.' );
    }

    $person = metis_auth_get_person( $person_id );
    if ( ! is_array( $person ) ) {
        throw new RuntimeException( 'Profile not found.' );
    }

    if ( strlen( $new_password ) < 12 ) {
        throw new RuntimeException( 'Password must be at least 12 characters.' );
    }
    if ( ! hash_equals( $new_password, $confirm_password ) ) {
        throw new RuntimeException( 'Password confirmation does not match.' );
    }

    metis_auth_password_security_service()->assertPasswordAllowed( $new_password );

    $user = metis_auth_find_user( 'person_id', $person_id );
    if ( ! is_array( $user ) ) {
        $user = metis_auth_upsert_user_from_person(
            $person,
            null,
            metis_auth_password_hash_for_storage( $new_password )
        );
    } else {
        $user = metis_auth_store_password_hash(
            $user,
            $person,
            metis_auth_password_hash_for_storage( $new_password ),
            true
        );
    }

    if ( function_exists( 'metis_audit_log_activity' ) ) {
        metis_audit_log_activity( 'auth_password_set_from_session', [
            'user_id' => (int) ( $user['id'] ?? 0 ),
            'module' => 'core',
            'request_id' => metis_audit_request_id(),
            'context' => [
                'auth_user_id' => (int) ( $user['id'] ?? 0 ),
                'person_id' => $person_id,
                'auth_method' => metis_auth_current_method(),
                'event' => 'password_set_from_session',
            ],
        ] );
    }

    return [
        'user' => $user,
        'person' => $person,
    ];
}

function metis_auth_admin_reset_password( int $admin_person_id, int $target_person_id, string $new_password ): array {
    if ( $admin_person_id < 1 || $target_person_id < 1 ) {
        throw new RuntimeException( 'Password reset target is invalid.' );
    }

    $person = metis_auth_get_person( $target_person_id );
    $user = metis_auth_find_user( 'person_id', $target_person_id );
    if ( ! is_array( $person ) || ! is_array( $user ) ) {
        throw new RuntimeException( 'Password sign-in is not available for this profile.' );
    }

    metis_auth_password_security_service()->assertPasswordAllowed( $new_password );
    $updated = metis_auth_store_password_hash(
        $user,
        $person,
        metis_auth_password_hash_for_storage( $new_password ),
        true
    );

    if ( function_exists( 'metis_audit_log_activity' ) ) {
        metis_audit_log_activity( 'auth_admin_password_reset', [
            'user_id' => (int) ( $updated['id'] ?? 0 ),
            'module' => 'core',
            'request_id' => metis_audit_request_id(),
            'context' => [
                'admin_person_id' => $admin_person_id,
                'target_person_id' => $target_person_id,
                'auth_user_id' => (int) ( $updated['id'] ?? 0 ),
                'event' => 'admin_password_reset',
            ],
        ] );
    }

    return [
        'user' => $updated,
        'person' => $person,
    ];
}

function metis_auth_logout(): void {
    metis_do_action( 'metis_logout' );
    unset( $_SESSION['metis_auth_user_id'], $_SESSION['metis_person_id'], $_SESSION['metis_auth_issued_at'], $_SESSION['metis_auth_last_activity_at'], $_SESSION['metis_session_token'], $_SESSION['metis_session_integrity'], $_SESSION['metis_user'], $_SESSION['metis_pending_auth'], $_SESSION['metis_auth_password_verified_at'] );
    if ( \Metis\Core\Application::has_service( 'session_security' ) ) {
        \Metis\Core\Application::service( 'session_security' )->regenerateId();
    }
}

function metis_auth_login_path(): string {
    return '/login';
}

function metis_auth_login_url( string $redirect = '' ): string {
    $url = metis_home_url( metis_auth_login_path() );
    $redirect = metis_auth_normalize_redirect( $redirect, '' );
    if ( $redirect !== '' ) {
        $url = metis_add_query_arg( [ 'redirect_to' => $redirect ], $url );
    }
    return $url;
}

function metis_auth_google_callback_url(): string {
    return metis_add_query_arg( [ 'provider' => 'google_workspace' ], metis_auth_login_url() );
}

function metis_auth_session_keepalive_path(): string {
    return '/api/auth/session/keepalive';
}

function metis_auth_session_keepalive_url(): string {
    return metis_home_url( metis_auth_session_keepalive_path() );
}

function metis_auth_mfa_url( string $redirect = '' ): string {
    return metis_add_query_arg( [ 'step' => 'mfa' ], metis_auth_login_url( $redirect ) );
}

function metis_auth_logout_path(): string {
    return '/logout';
}

function metis_auth_logout_url(): string {
    return metis_home_url( metis_auth_logout_path() );
}

function metis_auth_normalize_redirect( string $redirect, string $default = '' ): string {
    $default = trim( $default );
    $candidate = trim( str_replace( [ "\r", "\n", "\0" ], '', $redirect ) );
    if ( $candidate === '' ) {
        return $default;
    }

    $candidate = str_replace( '\\', '/', $candidate );
    if ( str_starts_with( $candidate, '//' ) ) {
        return $default;
    }

    $parts = metis_runtime_parse_url( $candidate );
    if ( ! is_array( $parts ) ) {
        return $default;
    }

    $site = metis_runtime_parse_url( metis_home_url( '/' ) );
    if ( ! is_array( $site ) ) {
        return $default;
    }

    $path = (string) ( $parts['path'] ?? '' );
    $query = isset( $parts['query'] ) && $parts['query'] !== '' ? '?' . (string) $parts['query'] : '';
    $fragment = isset( $parts['fragment'] ) && $parts['fragment'] !== '' ? '#' . (string) $parts['fragment'] : '';
    $has_authority = isset( $parts['scheme'] ) || isset( $parts['host'] );

    if ( $has_authority ) {
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        if ( $scheme !== '' && ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return $default;
        }

        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        $site_host = strtolower( (string) ( $site['host'] ?? '' ) );
        if ( $host === '' || $site_host === '' || $host !== $site_host ) {
            return $default;
        }

        $port = (int) ( $parts['port'] ?? 0 );
        $site_port = (int) ( $site['port'] ?? 0 );
        if ( $port > 0 && $site_port > 0 && $port !== $site_port ) {
            return $default;
        }
    }

    if ( $path === '' ) {
        $path = '/';
    } elseif ( ! str_starts_with( $path, '/' ) ) {
        $path = '/' . ltrim( $path, '/' );
    }

    if ( str_starts_with( $path, '//' ) ) {
        return $default;
    }

    return $path . $query . $fragment;
}

function metis_auth_login_customization(): array {
    static $resolved = null;
    if ( is_array( $resolved ) ) {
        return $resolved;
    }

    $defaults = [
        'logo' => null,
        'background_image' => null,
        'background_color' => '#edf2f7',
        'welcome_text' => 'Use a passkey first, Google Workspace next, or your local password if needed.',
        'organization_name' => 'Metis',
        'footer_text' => 'Secure access powered by Metis.',
    ];

    $config_path = ( defined( 'METIS_CONFIG_PATH' ) ? rtrim( (string) METIS_CONFIG_PATH, '/\\' ) : dirname( __DIR__, 4 ) . '/config' ) . '/login.php';
    if ( is_file( $config_path ) ) {
        $config = require $config_path;
        if ( is_array( $config ) ) {
            $defaults = array_merge( $defaults, $config );
        }
    }

    $asset_to_data_uri = static function( $asset ): string {
        if ( ! is_array( $asset ) ) {
            return '';
        }
        $mime = (string) ( $asset['mime_type'] ?? '' );
        $data = (string) ( $asset['data_base64'] ?? '' );
        if ( $mime === '' || $data === '' ) {
            return '';
        }
        return 'data:' . $mime . ';base64,' . $data;
    };

    $resolved = [
        'logo' => $defaults['logo'],
        'background_image' => $defaults['background_image'],
        'background_color' => metis_hex_color_clean( (string) ( $defaults['background_color'] ?? '#edf2f7' ) ) ?: '#edf2f7',
        'welcome_text' => (string) ( $defaults['welcome_text'] ?? '' ),
        'organization_name' => (string) ( $defaults['organization_name'] ?? 'Metis' ),
        'footer_text' => (string) ( $defaults['footer_text'] ?? '' ),
    ];

    if ( class_exists( 'Core_Settings_Service' ) ) {
        $logo = $asset_to_data_uri( Core_Settings_Service::get( 'login_logo', [] ) );
        $background_image = $asset_to_data_uri( Core_Settings_Service::get( 'login_background_image', [] ) );
        if ( $logo !== '' ) {
            $resolved['logo'] = $logo;
        }
        if ( $background_image !== '' ) {
            $resolved['background_image'] = $background_image;
        }
        $resolved['background_color'] = metis_hex_color_clean( (string) Core_Settings_Service::get( 'login_background_color', $resolved['background_color'] ) ) ?: $resolved['background_color'];
        $resolved['welcome_text'] = (string) Core_Settings_Service::get( 'login_welcome_text', $resolved['welcome_text'] );
        $resolved['organization_name'] = (string) Core_Settings_Service::get( 'login_organization_name', $resolved['organization_name'] );
        $resolved['footer_text'] = (string) Core_Settings_Service::get( 'login_footer_text', $resolved['footer_text'] );
    }

    return $resolved;
}

function metis_auth_render_shell( string $title, string $body, int $status = 200 ): never {
    $custom = metis_auth_login_customization();
    $css_path = ( defined( 'METIS_PATH' ) ? rtrim( (string) METIS_PATH, '/\\' ) : dirname( __DIR__, 4 ) ) . '/system/assets/css/auth-shell.css';
    $js_path = ( defined( 'METIS_PATH' ) ? rtrim( (string) METIS_PATH, '/\\' ) : dirname( __DIR__, 4 ) ) . '/system/assets/js/auth-passkey-client.js';
    $inline_css = is_readable( $css_path ) ? (string) file_get_contents( $css_path ) : '';
    $inline_js = is_readable( $js_path ) ? (string) file_get_contents( $js_path ) : '';
    $background_style = 'background:' . metis_escape_attr( (string) $custom['background_color'] ) . ';';
    if ( ! empty( $custom['background_image'] ) ) {
        $background_style = 'background:' . metis_escape_attr( (string) $custom['background_color'] ) . ' url(' . metis_escape_url( (string) $custom['background_image'] ) . ') center/cover no-repeat;';
    }
    $brand = trim( (string) ( $custom['organization_name'] ?? 'Metis' ) );
    $welcome = trim( (string) ( $custom['welcome_text'] ?? '' ) );
    $footer = trim( (string) ( $custom['footer_text'] ?? '' ) );
    $logo = trim( (string) ( $custom['logo'] ?? '' ) );

    metis_send_status( $status );
    header( 'Content-Type: text/html; charset=UTF-8' );
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . metis_escape_html( $title ) . '</title>';
    if ( $inline_css !== '' ) {
        echo '<style>' . $inline_css . '</style>';
    } else {
        echo '<link rel="stylesheet" href="' . metis_escape_url( metis_site_url( '/assets/css/auth-shell.css' ) ) . '">';
    }
    if ( $inline_js === '' ) {
        echo '<script src="' . metis_escape_url( metis_site_url( '/assets/js/auth-passkey-client.js' ) ) . '" defer></script>';
    }
    echo '</head><body class="metis-auth-shell" style="' . $background_style . '"><div class="wrap">';
    echo '<div class="auth-brand">';
    if ( $logo !== '' ) {
        echo '<img src="' . metis_escape_url( $logo ) . '" alt="' . metis_escape_attr( $brand ) . '" style="max-height:64px;max-width:220px;width:auto;height:auto;">';
    }
    if ( $brand !== '' ) {
        echo '<div class="auth-brand-name">' . metis_escape_html( $brand ) . '</div>';
    }
    if ( $welcome !== '' ) {
        echo '<p class="muted">' . metis_escape_html( $welcome ) . '</p>';
    }
    echo '</div>';
    echo $body;
    if ( $footer !== '' ) {
        echo '<div class="auth-footer">' . metis_escape_html( $footer ) . '</div>';
    }
    if ( $inline_js !== '' ) {
        echo '<script>' . $inline_js . '</script>';
    }
    echo '</div></body></html>';
    exit;
}

function metis_auth_passkey_begin_nonce_action(): string {
    return 'metis_auth_passkey_begin';
}

function metis_auth_passkey_complete_nonce_action(): string {
    return 'metis_auth_passkey_complete';
}

function metis_auth_form_nonce_action( string $mode ): string {
    return match ( $mode ) {
        'bootstrap' => 'metis_auth_bootstrap',
        'mfa_totp' => 'metis_auth_mfa_totp',
        'google_workspace_start' => 'metis_auth_google_workspace_start',
        default => 'metis_auth_login',
    };
}

function metis_auth_build_passkey_client( string $redirect ): string {
    $complete_url = metis_url_clean( metis_home_url( '/api/auth/passkeys/complete' ) );
    $resolve_url = metis_url_clean( metis_home_url( '/api/auth/resolve' ) );
    $resolve_nonce = metis_runtime_create_nonce( 'metis_auth_resolve' );
    $begin_nonce = metis_runtime_create_nonce( metis_auth_passkey_begin_nonce_action() );
    $complete_nonce = metis_runtime_create_nonce( metis_auth_passkey_complete_nonce_action() );

    return ' data-resolve-url="' . metis_escape_attr( $resolve_url ) . '"'
        . ' data-complete-url="' . metis_escape_attr( $complete_url ) . '"'
        . ' data-resolve-nonce="' . metis_escape_attr( $resolve_nonce ) . '"'
        . ' data-begin-nonce="' . metis_escape_attr( $begin_nonce ) . '"'
        . ' data-complete-nonce="' . metis_escape_attr( $complete_nonce ) . '"'
        . ' data-complete-action="' . metis_escape_attr( metis_auth_passkey_complete_nonce_action() ) . '"'
        . ' data-redirect="' . metis_escape_attr( $redirect ) . '"';
}

function metis_auth_api_error_response(
    string $event,
    string $code,
    string $message,
    Throwable $exception,
    int $status = 400,
    ?Metis_Http_Request $request = null,
    string $action = ''
): Metis_Http_Response {
    $request_id = metis_audit_request_id();
    $endpoint = $request instanceof Metis_Http_Request
        ? '/' . ltrim( (string) $request->path(), '/' )
        : '/api/auth';
    $action_key = metis_key_clean( $action !== '' ? $action : $code );
    $code_key = metis_key_clean( $code );

    metis_auth_log( $event, [
        'exception' => get_class( $exception ),
        'message' => $exception->getMessage(),
        'status' => $status,
        'action' => $action_key,
        'endpoint' => $endpoint,
        'request_id' => $request_id,
    ] );

    try {
        metis_audit_log_security( 'auth_action_failed', [
            'module'   => 'core',
            'severity' => $status >= 500 ? 'error' : 'warning',
            'outcome'  => 'failed',
            'resource' => [
                'type'  => 'auth_action',
                'id'    => $action_key,
                'label' => $code_key,
            ],
            'context'  => [
                'route'         => 'auth.api',
                'endpoint'      => $endpoint,
                'status_code'   => $status,
                'error_code'    => $code_key,
                'error_message' => $message,
                'request_id'    => $request_id,
            ],
        ] );
    } catch ( Throwable $audit_error ) {
        metis_auth_log( 'api_error_audit_failed', [
            'exception' => get_class( $audit_error ),
            'message' => $audit_error->getMessage(),
            'request_id' => $request_id,
        ] );
    }

    return Metis_Http_Response::json(
        [
            'success' => false,
            'data' => [
                'code' => $code_key,
                'message' => $message,
            ],
        ],
        $status,
        [ 'X-Metis-Request-Id' => $request_id ]
    );
}

function metis_auth_ui_error_message( Throwable $exception, string $context = 'login' ): string {
    $safe_context = metis_key_clean( $context ) ?: 'login';
    metis_auth_log( 'ui_error_' . $safe_context, [
        'exception' => get_class( $exception ),
        'message' => $exception->getMessage(),
    ] );

    return match ( $safe_context ) {
        'google_workspace' => 'Google sign-in could not be completed. Please try again.',
        'mfa' => 'Verification failed. Please try again.',
        default => 'Sign-in failed. Please verify your credentials and try again.',
    };
}

function metis_router_handle_auth_resolve_request( Metis_Http_Request $request ): Metis_Http_Response {
    $identifier = '';
    $redirect = metis_portal_url();

    try {
        try {
            metis_auth_rate_limit_check( 'auth_resolve', 20, 300 );
        } catch ( Throwable $rate_limit_error ) {
            if ( str_contains( $rate_limit_error->getMessage(), 'Too many sign-in attempts' ) ) {
                return metis_auth_api_error_response( 'resolve_rate_limited', 'auth_resolve_rate_limited', $rate_limit_error->getMessage(), $rate_limit_error, 429, $request, 'auth_resolve' );
            }

            metis_auth_log( 'resolve_rate_limit_unavailable', [
                'exception' => get_class( $rate_limit_error ),
                'message' => $rate_limit_error->getMessage(),
            ] );
        }

        metis_register_core_services();
        $input = $request->input();
        $identifier = trim( (string) ( $input['identifier'] ?? '' ) );
        $redirect = metis_auth_normalize_redirect( (string) ( $input['redirect_to'] ?? '' ), metis_portal_url() );
        if ( $identifier === '' ) {
            throw new InvalidArgumentException( 'Email is required.' );
        }

        $result = \Metis\Core\Application::service( 'auth_core' )->resolve( $identifier, $redirect );

        return Metis_Http_Response::json( [ 'success' => true, 'data' => $result ], 200 );
    } catch ( Throwable $e ) {
        if ( $identifier !== '' ) {
            metis_auth_log( 'resolve_degraded_to_password', [
                'exception' => get_class( $e ),
                'message' => $e->getMessage(),
            ] );

            return Metis_Http_Response::json(
                [
                    'success' => true,
                    'data' => [
                        'method' => 'password',
                        'identifier' => $identifier,
                        'password_fallback' => true,
                        'methods' => [ 'password' ],
                        'resolve_degraded' => true,
                    ],
                ],
                200
            );
        }

        return metis_auth_api_error_response( 'resolve_failed', 'auth_resolve_failed', 'Unable to start sign-in.', $e, 400, $request, 'auth_resolve' );
    }
}

function metis_router_handle_auth_passkey_begin_request( Metis_Http_Request $request ): Metis_Http_Response {
    try {
        metis_auth_rate_limit_check( 'passkey_begin' );
        metis_register_core_services();
        $identifier = trim( (string) ( $request->input()['identifier'] ?? '' ) );
        $result = \Metis\Core\Application::service( 'passkeys' )->beginAuthentication( $identifier );
        return Metis_Http_Response::json( [ 'success' => true, 'data' => $result ], 200 );
    } catch ( Throwable $e ) {
        return metis_auth_api_error_response( 'passkey_begin_failed', 'passkey_begin_failed', 'Unable to start passkey sign-in.', $e, 400, $request, 'auth_passkey_begin' );
    }
}

function metis_router_handle_auth_passkey_complete_request( Metis_Http_Request $request ): Metis_Http_Response {
    try {
        metis_auth_rate_limit_check( 'passkey_complete' );
        metis_register_core_services();

        $input = $request->input();
        $result = \Metis\Core\Application::service( 'auth_core' )->completePasskeyLogin( [
            'challenge_key' => (string) ( $input['challenge_key'] ?? '' ),
            'credential_id' => (string) ( $input['credential_id'] ?? '' ),
            'client_data_json' => (string) ( $input['client_data_json'] ?? '' ),
            'authenticator_data' => (string) ( $input['authenticator_data'] ?? '' ),
            'signature' => (string) ( $input['signature'] ?? '' ),
            'user_handle' => (string) ( $input['user_handle'] ?? '' ),
        ], metis_auth_normalize_redirect( (string) ( $input['redirect_to'] ?? '' ), metis_portal_url() ) );

        return Metis_Http_Response::json( [
            'success' => true,
            'data' => [
                'redirect_url' => (string) ( $result['redirect_url'] ?? metis_portal_url() ),
                'person_id' => (int) ( $result['person_id'] ?? 0 ),
            ],
        ], 200 );
    } catch ( Throwable $e ) {
        return metis_auth_api_error_response( 'passkey_complete_failed', 'passkey_complete_failed', 'Unable to complete passkey sign-in.', $e, 400, $request, 'auth_passkey_complete' );
    }
}

function metis_router_handle_auth_session_keepalive_request( Metis_Http_Request $request ): Metis_Http_Response {
    metis_register_core_services();
    metis_auth_rate_limit_check( 'auth_session_keepalive', 180, 60 );

    if ( ! metis_user_logged_in() ) {
        return Metis_Http_Response::json(
            [
                'success' => false,
                'data' => [
                    'code' => 'authentication_required',
                    'message' => 'Authentication required.',
                ],
            ],
            401,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    if ( ! metis_auth_session_integrity_is_valid() ) {
        metis_auth_logout();
        return Metis_Http_Response::json(
            [
                'success' => false,
                'data' => [
                    'code' => 'invalid_session',
                    'message' => 'Invalid session.',
                ],
            ],
            401,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    $now = time();
    $issued_at = (int) ( $_SESSION['metis_auth_issued_at'] ?? 0 );
    $last_activity_at = (int) ( $_SESSION['metis_auth_last_activity_at'] ?? 0 );
    $idle_ttl = max( 60, metis_auth_session_idle_ttl_seconds() );
    $absolute_ttl = max( 60, metis_auth_session_absolute_ttl_seconds() );

    $idle_expires_at = $last_activity_at > 0 ? ( $last_activity_at + $idle_ttl ) : 0;
    $absolute_expires_at = $issued_at > 0 ? ( $issued_at + $absolute_ttl ) : 0;

    return Metis_Http_Response::json(
        [
            'success' => true,
            'data' => [
                'authenticated' => true,
                'server_time' => $now,
                'issued_at' => $issued_at,
                'last_activity_at' => $last_activity_at,
                'idle_ttl' => $idle_ttl,
                'absolute_ttl' => $absolute_ttl,
                'idle_expires_at' => $idle_expires_at,
                'absolute_expires_at' => $absolute_expires_at,
            ],
        ],
        200,
        [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
    );
}

function metis_auth_rate_limit_check( string $bucket, int $limit = 10, int $window = 300 ): void {
    metis_auth_protection_service()->assertRequestRateLimit( metis_key_clean( $bucket ), max( 1, $limit ), max( 1, $window ) );
}

function metis_auth_handle_request( Metis_Http_Request $request ): bool {
    $path = rtrim( $request->path(), '/' );
    if ( $path === '' ) {
        $path = '/';
    }

    if ( $path === metis_auth_logout_path() ) {
        metis_auth_logout();
        metis_runtime_redirect( metis_auth_login_url() );
    }

    if ( $path !== metis_auth_login_path() ) {
        return false;
    }

    $error = '';
    $pending = metis_auth_pending_login_row();
    $pending_person = \Metis\Core\Application::has_service( 'auth_mfa' )
        ? \Metis\Core\Application::service( 'auth_mfa' )->pendingPerson()
        : ( $pending ? metis_auth_get_person( (int) ( $pending['person_id'] ?? 0 ) ) : null );
    $step = metis_key_clean( (string) ( $_GET['step'] ?? '' ) );
    $redirect = metis_auth_normalize_redirect(
        isset( $_GET['redirect_to'] ) ? (string) $_GET['redirect_to'] : metis_auth_pending_login_redirect(),
        metis_portal_url()
    );
    $show_mfa = $step === 'mfa' && is_array( $pending ) && is_array( $pending_person );
    $needs_bootstrap = ! metis_auth_has_users() && metis_auth_legacy_people_without_auth_count() === 0;

    if ( (string) ( $_GET['provider'] ?? '' ) === 'google_workspace' && isset( $_GET['code'] ) && \Metis\Core\Application::has_service( 'auth_core' ) ) {
        try {
            metis_auth_rate_limit_check( 'google_workspace' );
            $result = \Metis\Core\Application::service( 'auth_core' )->finishGoogleWorkspaceLogin(
                (string) $_GET['code'],
                (string) ( $_GET['state'] ?? '' ),
                $redirect
            );
            metis_runtime_redirect( (string) ( $result['redirect_url'] ?? $redirect ) );
        } catch ( Throwable $e ) {
            $error = metis_auth_ui_error_message( $e, 'google_workspace' );
        }
    }

    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        $mode = metis_key_clean( (string) ( $_POST['mode'] ?? 'login' ) );

        try {
            \Metis\Core\Application::service( 'csrf' )->requireValidToken( $_POST, metis_auth_form_nonce_action( $mode ) );

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
                metis_runtime_redirect( $redirect );
            } elseif ( $mode === 'mfa_totp' ) {
                $result = \Metis\Core\Application::service( 'auth_core' )->verifyPasswordMfa( (string) ( $_POST['code'] ?? '' ) );
                metis_runtime_redirect( (string) ( $result['redirect_url'] ?? $redirect ) );
            } elseif ( $mode === 'google_workspace_start' ) {
                metis_auth_rate_limit_check( 'google_workspace' );
                $auth_url = \Metis\Core\Application::service( 'auth_core' )->beginGoogleWorkspaceLogin( $redirect );
                metis_runtime_redirect( $auth_url );
            } else {
                $result = \Metis\Core\Application::service( 'auth_core' )->authenticatePassword(
                    (string) ( $_POST['identifier'] ?? '' ),
                    (string) ( $_POST['password'] ?? '' ),
                    $redirect
                );
                metis_runtime_redirect( (string) ( $result['redirect_url'] ?? metis_auth_mfa_url( $redirect ) ) );
            }
        } catch ( Throwable $e ) {
            $error = $mode === 'mfa_totp'
                ? metis_auth_ui_error_message( $e, 'mfa' )
                : metis_auth_ui_error_message( $e, 'login' );
            $pending = metis_auth_pending_login_row();
            $pending_person = \Metis\Core\Application::has_service( 'auth_mfa' )
                ? \Metis\Core\Application::service( 'auth_mfa' )->pendingPerson()
                : ( $pending ? metis_auth_get_person( (int) ( $pending['person_id'] ?? 0 ) ) : null );
        }
    }

    if ( $show_mfa ) {
        $body = '<h1>Verify your sign in</h1><p class="muted">Enter the 6-digit code from your authenticator app to finish signing in.</p>';
        if ( $error !== '' ) {
            $body .= '<div class="error">' . metis_escape_html( $error ) . '</div>';
        }
        $body .= '<form method="post"><input type="hidden" name="mode" value="mfa_totp">' . \Metis\Core\Application::service( 'csrf' )->hiddenFields( metis_auth_form_nonce_action( 'mfa_totp' ) );
        $body .= '<label for="code">Authenticator code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>';
        $body .= '<button type="submit">Verify</button></form>';
        $body .= '<p><a href="' . metis_escape_url( metis_auth_login_url( $redirect ) ) . '">Back to sign in</a></p>';
        metis_auth_render_shell( 'Verify Sign In', $body );
    }

    if ( metis_auth_has_users() || metis_auth_legacy_people_without_auth_count() > 0 ) {
        $body = '<h1>Sign in to Metis</h1>';
        if ( $error !== '' ) {
            $body .= '<div class="error">' . metis_escape_html( $error ) . '</div>';
        }
        $body .= '<p class="muted">Enter your username or email to use passkey or password sign-in. Google Workspace is always available below.</p>';
        $body .= '<div class="split">';
        $body .= '<div class="card"><form id="metis-auth-resolve-form"' . metis_auth_build_passkey_client( $redirect ) . '>';
        $body .= '<label for="identifier">Username or email</label><input id="identifier" name="identifier" required autofocus autocomplete="username webauthn">';
        $body .= '<div class="auth-actions">';
        $body .= '<button type="submit" id="metis-auth-resolve-button">Continue</button>';
        $body .= '<span class="auth-or">OR</span>';
        $body .= '<button type="submit" id="metis-google-sso-button" class="metis-btn-secondary" form="metis-google-sso-form">Sign in with Google</button>';
        $body .= '</div></form>';
        $body .= '<form method="post" id="metis-google-sso-form" hidden><input type="hidden" name="mode" value="google_workspace_start">' . \Metis\Core\Application::service( 'csrf' )->hiddenFields( metis_auth_form_nonce_action( 'google_workspace_start' ) ) . '</form>';
        $body .= '<p id="metis-auth-status" class="muted" aria-live="polite"></p>';
        $body .= '<p id="metis-passkey-unsupported" class="muted" hidden>Your browser does not support passkey sign-in.</p></div>';
        $body .= '<div class="card" id="metis-password-card" hidden><h2>Password</h2><form method="post" id="metis-password-form"><input type="hidden" name="mode" value="login">' . \Metis\Core\Application::service( 'csrf' )->hiddenFields( metis_auth_form_nonce_action( 'login' ) );
        $body .= '<input type="hidden" name="identifier" id="metis-password-identifier">';
        $body .= '<label for="metis-password-input">Password</label><input id="metis-password-input" name="password" type="password" required autocomplete="current-password">';
        $body .= '<button type="submit">Sign In With Password</button></form></div>';
        $body .= '</div>';
        metis_auth_render_shell( 'Metis Login', $body );
    }

    $body = '<h1>Create the first Metis account</h1>';
    if ( $error !== '' ) {
        $body .= '<div class="error">' . metis_escape_html( $error ) . '</div>';
    }
    $body .= '<form method="post"><input type="hidden" name="mode" value="bootstrap">' . \Metis\Core\Application::service( 'csrf' )->hiddenFields( metis_auth_form_nonce_action( 'bootstrap' ) );
    $body .= '<label for="user_login">Login</label><input id="user_login" name="user_login" required autofocus>';
    $body .= '<label for="user_email">Email</label><input id="user_email" name="user_email" type="email" required>';
    $body .= '<label for="display_name">Display Name</label><input id="display_name" name="display_name">';
    $body .= '<label for="first_name">First Name</label><input id="first_name" name="first_name">';
    $body .= '<label for="last_name">Last Name</label><input id="last_name" name="last_name">';
    $body .= '<label for="password">Password</label><input id="password" name="password" type="password" minlength="12" required>';
    $body .= '<button type="submit">Create Admin Account</button></form>';
    metis_auth_render_shell( 'Create Metis Account', $body );
}
