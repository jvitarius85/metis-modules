<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_audit_table_name( string $channel ): string {
    return $channel === 'security'
        ? Metis_Tables::get( 'audit_security' )
        : Metis_Tables::get( 'audit_activity' );
}

function metis_audit_request_id(): string {
    static $request_id = null;

    if ( is_string( $request_id ) ) {
        return $request_id;
    }

    $header = isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ? metis_text_clean( (string) $_SERVER['HTTP_X_REQUEST_ID'] ) : '';
    $request_id = $header !== '' ? $header : metis_runtime_generate_uuid();

    return $request_id;
}

function metis_audit_ip_address(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ( $candidates as $candidate ) {
        if ( ! is_string( $candidate ) || trim( $candidate ) === '' ) {
            continue;
        }

        $candidate = trim( explode( ',', $candidate )[0] );
        if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
            return $candidate;
        }
    }

    return '';
}

function metis_audit_user_agent(): string {
    $agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    return substr( metis_text_clean( $agent ), 0, 512 );
}

function metis_audit_current_user_id(): ?int {
    $user_id = metis_current_user_id();
    return $user_id > 0 ? $user_id : null;
}

function metis_audit_normalize_resource( array $resource ): array {
    $type  = metis_key_clean( (string) ( $resource['type'] ?? '' ) );
    $id    = isset( $resource['id'] ) ? substr( metis_text_clean( (string) $resource['id'] ), 0, 191 ) : '';
    $label = isset( $resource['label'] ) ? substr( metis_text_clean( (string) $resource['label'] ), 0, 255 ) : '';

    return [
        'type'  => $type,
        'id'    => $id,
        'label' => $label,
    ];
}

function metis_audit_sanitize_context( array $context ): array {
    $clean = [];

    foreach ( $context as $key => $value ) {
        $key = metis_key_clean( (string) $key );
        if ( $key === '' ) {
            continue;
        }

        if ( is_scalar( $value ) || $value === null ) {
            $clean[ $key ] = $value;
            continue;
        }

        if ( is_array( $value ) ) {
            $clean[ $key ] = $value;
            continue;
        }

        if ( is_object( $value ) ) {
            $clean[ $key ] = method_exists( $value, '__toString' ) ? (string) $value : get_class( $value );
        }
    }

    return $clean;
}

function metis_audit_ensure_schema(): void {
    static $done = false;

    if ( $done ) {
        return;
    }

    $connection      = metis_db()->connection();
    $charset_collate = method_exists( $connection, 'get_charset_collate' ) ? (string) $connection->get_charset_collate() : '';
    $activity_table  = Metis_Tables::get( 'audit_activity' );
    $security_table  = Metis_Tables::get( 'audit_security' );

    $activity_sql = "CREATE TABLE {$activity_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id VARCHAR(64) DEFAULT NULL,
        user_id BIGINT UNSIGNED DEFAULT NULL,
        action_type VARCHAR(100) NOT NULL,
        module_slug VARCHAR(64) DEFAULT NULL,
        resource_type VARCHAR(64) DEFAULT NULL,
        resource_id VARCHAR(191) DEFAULT NULL,
        resource_label VARCHAR(255) DEFAULT NULL,
        ip_address VARCHAR(64) DEFAULT NULL,
        user_agent VARCHAR(512) DEFAULT NULL,
        context_json LONGTEXT DEFAULT NULL,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY action_type (action_type),
        KEY module_slug (module_slug),
        KEY resource_type (resource_type),
        KEY resource_id (resource_id),
        KEY occurred_at (occurred_at)
    ) {$charset_collate};";

    $security_sql = "CREATE TABLE {$security_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id VARCHAR(64) DEFAULT NULL,
        user_id BIGINT UNSIGNED DEFAULT NULL,
        action_type VARCHAR(100) NOT NULL,
        severity VARCHAR(24) NOT NULL DEFAULT 'warning',
        outcome VARCHAR(24) NOT NULL DEFAULT 'blocked',
        module_slug VARCHAR(64) DEFAULT NULL,
        resource_type VARCHAR(64) DEFAULT NULL,
        resource_id VARCHAR(191) DEFAULT NULL,
        resource_label VARCHAR(255) DEFAULT NULL,
        ip_address VARCHAR(64) DEFAULT NULL,
        user_agent VARCHAR(512) DEFAULT NULL,
        context_json LONGTEXT DEFAULT NULL,
        occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY action_type (action_type),
        KEY severity (severity),
        KEY module_slug (module_slug),
        KEY resource_type (resource_type),
        KEY resource_id (resource_id),
        KEY occurred_at (occurred_at)
    ) {$charset_collate};";

    metis_db_delta( $activity_sql );
    metis_db_delta( $security_sql );

    $done = true;
}

function metis_audit_write( string $channel, string $action_type, array $args = [] ): void {
    metis_audit_ensure_schema();

    $channel     = $channel === 'security' ? 'security' : 'activity';
    $table       = metis_audit_table_name( $channel );
    $resource    = metis_audit_normalize_resource( (array) ( $args['resource'] ?? [] ) );
    $context     = metis_audit_sanitize_context( (array) ( $args['context'] ?? [] ) );
    $module_slug = metis_key_clean( (string) ( $args['module'] ?? '' ) );
    $action_type = metis_key_clean( $action_type );
    $user_id     = isset( $args['user_id'] ) ? (int) $args['user_id'] : (int) ( metis_audit_current_user_id() ?? 0 );

    if ( $action_type === '' ) {
        return;
    }

    $payload = [
        'request_id'     => substr( metis_text_clean( (string) ( $args['request_id'] ?? metis_audit_request_id() ) ), 0, 64 ),
        'user_id'        => $user_id > 0 ? $user_id : null,
        'action_type'    => $action_type,
        'module_slug'    => $module_slug !== '' ? $module_slug : null,
        'resource_type'  => $resource['type'] !== '' ? $resource['type'] : null,
        'resource_id'    => $resource['id'] !== '' ? $resource['id'] : null,
        'resource_label' => $resource['label'] !== '' ? $resource['label'] : null,
        'ip_address'     => substr( (string) ( $args['ip_address'] ?? metis_audit_ip_address() ), 0, 64 ),
        'user_agent'     => substr( (string) ( $args['user_agent'] ?? metis_audit_user_agent() ), 0, 512 ),
        'context_json'   => ! empty( $context ) ? metis_json_encode( $context ) : null,
        'occurred_at'    => metis_current_time( 'mysql' ),
    ];

    if ( $channel === 'security' ) {
        $payload['severity'] = substr( metis_key_clean( (string) ( $args['severity'] ?? 'warning' ) ), 0, 24 );
        $payload['outcome']  = substr( metis_key_clean( (string) ( $args['outcome'] ?? 'blocked' ) ), 0, 24 );
    }

    $formats = $channel === 'security'
        ? [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        : [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

    $db     = metis_db();
    $result = $db->insert( $table, $payload, $formats );

    if ( $result === false ) {
        Metis_Logger::error( 'Audit log write failed', [
            'channel' => $channel,
            'action'  => $action_type,
            'db_error'=> (string) ( $db->connection()->last_error ?? '' ),
        ] );
    }
}

function metis_audit_log_activity( string $action_type, array $args = [] ): void {
    metis_audit_write( 'activity', $action_type, $args );
}

function metis_audit_log_security( string $action_type, array $args = [] ): void {
    metis_audit_write( 'security', $action_type, $args );
}

function metis_audit_recent_activity( int $limit = 100 ): array {
    metis_audit_ensure_schema();

    $table = Metis_Tables::get( 'audit_activity' );
    $limit = max( 1, min( 500, $limit ) );

    return metis_db()->fetchAll(
        "SELECT * FROM {$table} ORDER BY occurred_at DESC, id DESC LIMIT %d",
        [ $limit ]
    );
}

function metis_audit_recent_security_events( int $limit = 100 ): array {
    metis_audit_ensure_schema();

    $table = Metis_Tables::get( 'audit_security' );
    $limit = max( 1, min( 500, $limit ) );

    return metis_db()->fetchAll(
        "SELECT * FROM {$table} ORDER BY occurred_at DESC, id DESC LIMIT %d",
        [ $limit ]
    );
}

function metis_audit_log_login_success( string $user_login, MetisUser $user ): void {
    metis_audit_log_activity( 'login', [
        'user_id'  => (int) $user->ID,
        'module'   => 'core',
        'resource' => [
            'type'  => 'user',
            'id'    => (string) $user->ID,
            'label' => $user_login,
        ],
        'context'  => [
            'event'      => 'authentication',
            'user_login' => $user_login,
        ],
    ] );
}
metis_on( 'metis_login', 'metis_audit_log_login_success', 10, 2 );

function metis_audit_log_login_failure( string $username ): void {
    $user = get_user_by( 'login', $username );
    if ( ! $user && metis_email_is_valid( $username ) ) {
        $user = get_user_by( 'email', $username );
    }

    metis_audit_log_security( 'login_failed', [
        'user_id'  => $user instanceof MetisUser ? (int) $user->ID : null,
        'module'   => 'core',
        'severity' => 'warning',
        'outcome'  => 'failed',
        'resource' => [
            'type'  => 'user',
            'id'    => $user instanceof MetisUser ? (string) $user->ID : '',
            'label' => $username,
        ],
        'context'  => [
            'event'    => 'authentication',
            'username' => $username,
        ],
    ] );
}
metis_on( 'metis_login_failed', 'metis_audit_log_login_failure' );

function metis_audit_log_logout(): void {
    $user = metis_runtime_current_user();

    metis_audit_log_activity( 'logout', [
        'user_id'  => $user instanceof MetisUser ? (int) $user->ID : null,
        'module'   => 'core',
        'resource' => [
            'type'  => 'user',
            'id'    => $user instanceof MetisUser ? (string) $user->ID : '',
            'label' => $user instanceof MetisUser ? (string) $user->user_login : '',
        ],
        'context'  => [
            'event' => 'authentication',
        ],
    ] );
}
metis_on( 'metis_logout', 'metis_audit_log_logout' );

function metis_audit_log_profile_update( int $user_id, MetisUser $old_user_data, array $userdata ): void {
    $changed = [];

    foreach ( [ 'user_email', 'display_name', 'first_name', 'last_name', 'role' ] as $key ) {
        $old = $old_user_data->{$key} ?? '';
        $new = $userdata[ $key ] ?? '';
        if ( $new !== '' && (string) $old !== (string) $new ) {
            $changed[] = $key;
        }
    }

    if ( empty( $changed ) ) {
        return;
    }

    metis_audit_log_activity( 'user_profile_updated', [
        'user_id'  => metis_audit_current_user_id() ?? $user_id,
        'module'   => 'people',
        'resource' => [
            'type'  => 'user',
            'id'    => (string) $user_id,
            'label' => (string) ( $userdata['user_login'] ?? $old_user_data->user_login ?? '' ),
        ],
        'context'  => [
            'changed_fields' => $changed,
        ],
    ] );
}
metis_on( 'profile_update', 'metis_audit_log_profile_update', 10, 3 );

function metis_audit_log_user_role_change( int $user_id, string $role, array $old_roles ): void {
    metis_audit_log_activity( 'role_changed', [
        'module'   => 'people',
        'resource' => [
            'type'  => 'user',
            'id'    => (string) $user_id,
            'label' => (string) $user_id,
        ],
        'context'  => [
            'new_role'  => $role,
            'old_roles' => array_values( array_map( 'strval', $old_roles ) ),
        ],
    ] );
}
metis_on( 'set_user_role', 'metis_audit_log_user_role_change', 10, 3 );

function metis_audit_log_media_upload( array $upload ): array {
    if ( empty( $upload['file'] ) ) {
        return $upload;
    }

    metis_audit_log_activity( 'file_uploaded', [
        'module'   => 'core',
        'resource' => [
            'type'  => 'file',
            'id'    => (string) basename( (string) $upload['file'] ),
            'label' => (string) basename( (string) $upload['file'] ),
        ],
        'context'  => [
            'path' => (string) $upload['file'],
            'url'  => (string) ( $upload['url'] ?? '' ),
            'mime' => (string) ( $upload['type'] ?? '' ),
        ],
    ] );

    return $upload;
}
metis_add_filter( 'metis_handle_upload', 'metis_audit_log_media_upload' );
