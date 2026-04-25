<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

final class Metis_Webhook_Exception extends RuntimeException {

    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly string $code_name = 'webhook_error',
        private readonly array $context = []
    ) {
        parent::__construct( $message );
    }

    public function status(): int {
        return $this->status;
    }

    public function code_name(): string {
        return $this->code_name;
    }

    public function context(): array {
        return $this->context;
    }
}

function metis_webhook_base_path(): string {
    $path = Core_Settings_Service::get( 'webhook_base_path', 'metis-webhooks' );
    if ( is_array( $path ) ) {
        $path = reset( $path );
    }

    $path = metis_slug_clean( (string) $path );

    return $path !== '' ? $path : 'metis-webhooks';
}

function metis_webhook_base_url(): string {
    return metis_trailingslashit( metis_site_url( '/' . metis_webhook_base_path() ) );
}

function metis_webhook_url( string $provider ): string {
    return metis_trailingslashit( metis_webhook_base_url() . metis_key_clean( $provider ) );
}

function metis_parse_webhook_path( string $path ): ?array {
    $base = trim( metis_webhook_base_path(), '/' );
    $path = trim( $path, '/' );

    if ( $base === '' || $path === '' ) {
        return null;
    }

    if ( ! preg_match( '#^' . preg_quote( $base, '#' ) . '/([a-z0-9_-]+)$#', $path, $matches ) ) {
        return null;
    }

    $provider = metis_key_clean( (string) $matches[1] );
    if ( $provider === '' ) {
        return null;
    }

    return [ 'provider' => $provider ];
}

function metis_is_webhook_request(): bool {
    if ( php_sapi_name() === 'cli' ) {
        return false;
    }

    return metis_parse_webhook_path( metis_request_path_relative_to_site() ) !== null;
}

function metis_webhook_registry(): array {
    return isset( $GLOBALS['metis_webhook_providers'] ) && is_array( $GLOBALS['metis_webhook_providers'] )
        ? $GLOBALS['metis_webhook_providers']
        : [];
}

function metis_webhook_register_provider( string $provider, array $config ): void {
    $provider = metis_key_clean( $provider );
    if ( $provider === '' ) {
        return;
    }

    $providers = metis_webhook_registry();
    $providers[ $provider ] = $config;
    $GLOBALS['metis_webhook_providers'] = $providers;
}

function metis_webhook_provider( string $provider ): ?array {
    $provider = metis_key_clean( $provider );
    $providers = metis_webhook_registry();

    return isset( $providers[ $provider ] ) && is_array( $providers[ $provider ] ) ? $providers[ $provider ] : null;
}

function metis_webhook_ensure_schema(): void {
    static $done = false;

    if ( $done ) {
        return;
    }

    $table = Metis_Tables::get( 'webhook_events' );
    $connection = metis_db()->connection();
    $charset_collate = method_exists( $connection, 'get_charset_collate' ) ? (string) $connection->get_charset_collate() : '';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        provider VARCHAR(64) NOT NULL,
        event_id VARCHAR(191) NOT NULL,
        event_type VARCHAR(191) DEFAULT NULL,
        resource_id VARCHAR(191) DEFAULT NULL,
        request_id VARCHAR(64) DEFAULT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'received',
        attempts INT UNSIGNED NOT NULL DEFAULT 1,
        signature_status VARCHAR(24) NOT NULL DEFAULT 'verified',
        payload_json LONGTEXT DEFAULT NULL,
        headers_json LONGTEXT DEFAULT NULL,
        last_error TEXT DEFAULT NULL,
        processed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY provider_event_id (provider, event_id),
        KEY provider (provider),
        KEY event_type (event_type),
        KEY status (status),
        KEY resource_id (resource_id),
        KEY processed_at (processed_at),
        KEY created_at (created_at)
    ) {$charset_collate};";

    metis_db_delta( $sql );

    $done = true;
}

function metis_webhook_storage_headers( Metis_Http_Request $request ): array {
    $headers = [];

    foreach ( [ 'content-type', 'user-agent' ] as $name ) {
        $value = $request->header( $name );
        if ( $value !== '' ) {
            $headers[ $name ] = $value;
        }
    }

    foreach ( [ 'stripe-signature', 'x-signature', 'x-webhook-signature', 'x-webhook-token', 'authorization' ] as $name ) {
        if ( $request->header( $name ) !== '' ) {
            $headers[ $name ] = '[present]';
        }
    }

    return $headers;
}

function metis_webhook_exception_context_for_audit( array $context ): array {
    $sanitized = metis_audit_sanitize_context( $context );
    $safe = [];

    foreach ( $sanitized as $key => $value ) {
        $clean_key = metis_key_clean( (string) $key );
        if ( $clean_key === '' ) {
            continue;
        }

        // Avoid persisting provider exception message/details verbatim.
        if ( in_array( $clean_key, [ 'provider_error', 'error', 'exception_message', 'message' ], true ) ) {
            continue;
        }

        $safe[ $clean_key ] = $value;
    }

    return $safe;
}

function metis_webhook_normalize_event( string $provider, mixed $event ): array {
    if ( ! is_array( $event ) ) {
        throw new Metis_Webhook_Exception( 'Webhook payload is invalid.', 400, 'webhook_payload_invalid' );
    }

    $normalized = [
        'provider'    => metis_key_clean( $provider ),
        'event_id'    => substr( metis_text_clean( (string) ( $event['event_id'] ?? '' ) ), 0, 191 ),
        'event_type'  => substr( metis_text_clean( (string) ( $event['event_type'] ?? '' ) ), 0, 191 ),
        'resource_id' => substr( metis_text_clean( (string) ( $event['resource_id'] ?? '' ) ), 0, 191 ),
        'payload'     => is_array( $event['payload'] ?? null ) ? (array) $event['payload'] : [],
    ];

    if ( $normalized['event_id'] === '' || $normalized['event_type'] === '' || empty( $normalized['payload'] ) ) {
        throw new Metis_Webhook_Exception( 'Webhook payload is missing required fields.', 400, 'webhook_payload_invalid' );
    }

    return $normalized;
}

function metis_webhook_claim_event( string $provider, array $event, Metis_Http_Request $request ): array {
    metis_webhook_ensure_schema();

    $db = metis_db();
    $table = Metis_Tables::get( 'webhook_events' );
    $now = metis_current_time( 'mysql' );
    $request_id = metis_audit_request_id();
    $headers_json = metis_json_encode( metis_webhook_storage_headers( $request ) );
    $payload_json = metis_json_encode( $event['payload'] );

    $inserted = $db->insert(
        $table,
        [
            'provider'         => $provider,
            'event_id'         => $event['event_id'],
            'event_type'       => $event['event_type'],
            'resource_id'      => $event['resource_id'] !== '' ? $event['resource_id'] : null,
            'request_id'       => $request_id,
            'status'           => 'processing',
            'attempts'         => 1,
            'signature_status' => 'verified',
            'payload_json'     => $payload_json ?: null,
            'headers_json'     => $headers_json ?: null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
    );

    if ( $inserted !== false ) {
        return [
            'state' => 'claimed',
            'id'    => (int) $db->lastInsertId(),
        ];
    }

    $row = $db->fetchOne(
        "SELECT * FROM {$table} WHERE provider = %s AND event_id = %s LIMIT 1",
        [ $provider, $event['event_id'] ]
    );

    if ( ! is_array( $row ) ) {
        throw new Metis_Webhook_Exception( 'Webhook event state could not be resolved.', 500, 'webhook_storage_error' );
    }

    $status = (string) ( $row['status'] ?? 'received' );

    if ( $status === 'processed' ) {
        return [
            'state'  => 'duplicate_processed',
            'record' => $row,
        ];
    }

    if ( $status === 'processing' ) {
        return [
            'state'  => 'duplicate_processing',
            'record' => $row,
        ];
    }

    $db->update(
        $table,
        [
            'event_type'       => $event['event_type'],
            'resource_id'      => $event['resource_id'] !== '' ? $event['resource_id'] : null,
            'request_id'       => $request_id,
            'status'           => 'processing',
            'attempts'         => max( 1, (int) ( $row['attempts'] ?? 0 ) ) + 1,
            'signature_status' => 'verified',
            'payload_json'     => $payload_json ?: null,
            'headers_json'     => $headers_json ?: null,
            'last_error'       => null,
            'updated_at'       => $now,
        ],
        [ 'id' => (int) $row['id'] ],
        [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ],
        [ '%d' ]
    );

    return [
        'state' => 'reclaimed',
        'id'    => (int) $row['id'],
    ];
}

function metis_webhook_mark_processed( int $id ): void {
    metis_webhook_ensure_schema();

    metis_db()->update(
        Metis_Tables::get( 'webhook_events' ),
        [
            'status'       => 'processed',
            'last_error'   => null,
            'processed_at' => metis_current_time( 'mysql' ),
            'updated_at'   => metis_current_time( 'mysql' ),
        ],
        [ 'id' => $id ],
        [ '%s', '%s', '%s', '%s' ],
        [ '%d' ]
    );
}

function metis_webhook_mark_failed( int $id, Throwable $error ): void {
    metis_webhook_ensure_schema();

    metis_db()->update(
        Metis_Tables::get( 'webhook_events' ),
        [
            'status'     => 'failed',
            'last_error' => 'Webhook processing failed. Review logs for details.',
            'updated_at' => metis_current_time( 'mysql' ),
        ],
        [ 'id' => $id ],
        [ '%s', '%s', '%s' ],
        [ '%d' ]
    );
}

function metis_webhook_env_int( string $name, int $default ): int {
    $value = getenv( $name );
    if ( $value === false ) {
        return $default;
    }

    $value = trim( (string) $value );
    if ( $value === '' ) {
        return $default;
    }

    if ( ! preg_match( '/^-?\d+$/', $value ) ) {
        return $default;
    }

    return (int) $value;
}

function metis_webhook_replay_window_seconds(): int {
    $seconds = metis_webhook_env_int( 'METIS_WEBHOOK_REPLAY_WINDOW_SECONDS', 300 );
    return max( 30, min( 3600, $seconds ) );
}

function metis_webhook_rate_limit_default(): int {
    return max( 10, min( 10000, metis_webhook_env_int( 'METIS_WEBHOOK_RATE_LIMIT_PER_MINUTE', 120 ) ) );
}

function metis_webhook_rate_window_seconds_default(): int {
    return max( 10, min( 3600, metis_webhook_env_int( 'METIS_WEBHOOK_RATE_WINDOW_SECONDS', 60 ) ) );
}

function metis_webhook_provider_failure_window_seconds(): int {
    return max( 60, min( 86400, metis_webhook_env_int( 'METIS_WEBHOOK_PROVIDER_FAILURE_WINDOW_SECONDS', 900 ) ) );
}

function metis_webhook_provider_failure_threshold(): int {
    return max( 1, min( 100, metis_webhook_env_int( 'METIS_WEBHOOK_PROVIDER_FAILURE_THRESHOLD', 6 ) ) );
}

function metis_webhook_provider_quarantine_seconds(): int {
    return max( 60, min( 86400, metis_webhook_env_int( 'METIS_WEBHOOK_PROVIDER_QUARANTINE_SECONDS', 600 ) ) );
}

function metis_webhook_timestamp_required(): bool {
    $raw = getenv( 'METIS_WEBHOOK_REQUIRE_TIMESTAMP' );
    if ( $raw === false ) {
        return false;
    }

    $parsed = filter_var( trim( (string) $raw ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
    return $parsed === true;
}

function metis_webhook_value_fingerprint( string $value ): string {
    $value = trim( $value );
    if ( $value === '' ) {
        return '';
    }

    return substr( hash( 'sha256', $value ), 0, 16 );
}

function metis_webhook_extract_timestamp( Metis_Http_Request $request, array $header_names ): ?int {
    foreach ( $header_names as $header_name ) {
        $header_name = metis_key_clean( (string) $header_name );
        if ( $header_name === '' ) {
            continue;
        }

        $value = trim( $request->header( $header_name ) );
        if ( $value === '' ) {
            continue;
        }

        if ( preg_match( '/^-?\d+$/', $value ) ) {
            return (int) $value;
        }
    }

    return null;
}

function metis_webhook_provider_quarantine_cache_key( string $provider ): string {
    return 'webhook.provider.quarantine.' . sha1( metis_key_clean( $provider ) );
}

function metis_webhook_provider_failures_cache_key( string $provider ): string {
    return 'webhook.provider.failures.' . sha1( metis_key_clean( $provider ) );
}

function metis_webhook_rate_limit_bucket( string $provider, string $endpoint, string $actor ): string {
    return 'webhook.rate.' . sha1( metis_key_clean( $provider ) . '|' . $endpoint . '|' . $actor );
}

function metis_webhook_config_list( mixed $value ): array {
    if ( is_string( $value ) ) {
        $value = array_filter( array_map( 'trim', explode( ',', $value ) ) );
    }

    if ( ! is_array( $value ) ) {
        return [];
    }

    $items = [];
    foreach ( $value as $item ) {
        $item = trim( (string) $item );
        if ( $item === '' ) {
            continue;
        }
        $items[] = $item;
    }

    return array_values( array_unique( $items ) );
}

function metis_webhook_ip_in_cidr( string $ip, string $cidr ): bool {
    $ip = trim( $ip );
    $cidr = trim( $cidr );
    if ( $ip === '' || $cidr === '' || strpos( $cidr, '/' ) === false ) {
        return false;
    }

    [ $network, $prefix ] = explode( '/', $cidr, 2 );
    $network = trim( $network );
    $prefix = trim( $prefix );
    if ( ! preg_match( '/^\d+$/', $prefix ) ) {
        return false;
    }

    $ip_bin = @inet_pton( $ip );
    $network_bin = @inet_pton( $network );
    if ( $ip_bin === false || $network_bin === false ) {
        return false;
    }

    if ( strlen( $ip_bin ) !== strlen( $network_bin ) ) {
        return false;
    }

    $bits = (int) $prefix;
    $max_bits = strlen( $network_bin ) * 8;
    if ( $bits < 0 || $bits > $max_bits ) {
        return false;
    }

    $full_bytes = intdiv( $bits, 8 );
    $remaining_bits = $bits % 8;

    if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $network_bin, 0, $full_bytes ) ) {
        return false;
    }

    if ( $remaining_bits === 0 ) {
        return true;
    }

    $mask = ( 0xFF << ( 8 - $remaining_bits ) ) & 0xFF;
    $ip_byte = ord( $ip_bin[ $full_bytes ] );
    $network_byte = ord( $network_bin[ $full_bytes ] );

    return ( $ip_byte & $mask ) === ( $network_byte & $mask );
}

function metis_webhook_enforce_provider_allowlist( string $provider, array $provider_config, Metis_Http_Request $request, string $endpoint ): void {
    $ip = trim( metis_audit_ip_address() );
    $allow_ips = metis_webhook_config_list( $provider_config['allow_ips'] ?? [] );
    $allow_cidrs = metis_webhook_config_list( $provider_config['allow_cidrs'] ?? [] );
    $required_headers = $provider_config['required_headers'] ?? [];

    if ( ! empty( $allow_ips ) || ! empty( $allow_cidrs ) ) {
        $ip_allowed = false;

        if ( $ip !== '' ) {
            foreach ( $allow_ips as $allowed_ip ) {
                if ( hash_equals( $allowed_ip, $ip ) ) {
                    $ip_allowed = true;
                    break;
                }
            }

            if ( ! $ip_allowed ) {
                foreach ( $allow_cidrs as $cidr ) {
                    if ( metis_webhook_ip_in_cidr( $ip, $cidr ) ) {
                        $ip_allowed = true;
                        break;
                    }
                }
            }
        }

        if ( ! $ip_allowed ) {
            throw new Metis_Webhook_Exception( 'Webhook source is not allowlisted.', 403, 'webhook_source_not_allowlisted', [
                'provider' => metis_key_clean( $provider ),
                'endpoint' => $endpoint,
                'source_ip' => $ip,
                'allowlist_ip_count' => count( $allow_ips ),
                'allowlist_cidr_count' => count( $allow_cidrs ),
            ] );
        }
    }

    if ( is_string( $required_headers ) ) {
        $required_headers = [ $required_headers ];
    }

    if ( ! is_array( $required_headers ) ) {
        return;
    }

    foreach ( $required_headers as $header_key => $expected_value ) {
        $header_name = '';
        if ( is_int( $header_key ) ) {
            $header_name = metis_key_clean( (string) $expected_value );
            $expected_value = null;
        } else {
            $header_name = metis_key_clean( (string) $header_key );
        }

        if ( $header_name === '' ) {
            continue;
        }

        $actual = trim( $request->header( $header_name ) );
        if ( $actual === '' ) {
            throw new Metis_Webhook_Exception( 'Webhook required header is missing.', 401, 'webhook_required_header_missing', [
                'provider' => metis_key_clean( $provider ),
                'endpoint' => $endpoint,
                'header' => $header_name,
            ] );
        }

        if ( is_string( $expected_value ) && trim( $expected_value ) !== '' ) {
            if ( ! hash_equals( trim( $expected_value ), $actual ) ) {
                throw new Metis_Webhook_Exception( 'Webhook required header value is invalid.', 401, 'webhook_required_header_invalid', [
                    'provider' => metis_key_clean( $provider ),
                    'endpoint' => $endpoint,
                    'header' => $header_name,
                    'header_fingerprint' => metis_webhook_value_fingerprint( $actual ),
                ] );
            }
        }
    }
}

function metis_webhook_provider_quarantined_until( string $provider ): int {
    return max( 0, (int) \Metis\Core\Cache\CacheService::get( metis_webhook_provider_quarantine_cache_key( $provider ) ) );
}

function metis_webhook_provider_failure_timestamps( string $provider ): array {
    $window = metis_webhook_provider_failure_window_seconds();
    $now = time();
    $entries = array_map( 'intval', (array) \Metis\Core\Cache\CacheService::get( metis_webhook_provider_failures_cache_key( $provider ) ) );

    return array_values( array_filter(
        $entries,
        static fn ( int $timestamp ): bool => $timestamp > ( $now - $window )
    ) );
}

function metis_webhook_mark_provider_healthy( string $provider ): void {
    \Metis\Core\Cache\CacheService::forget( metis_webhook_provider_failures_cache_key( $provider ) );
    \Metis\Core\Cache\CacheService::forget( metis_webhook_provider_quarantine_cache_key( $provider ) );
}

function metis_webhook_record_provider_failure( string $provider, string $reason = '' ): array {
    $provider = metis_key_clean( $provider );
    if ( $provider === '' ) {
        return [ 'count' => 0, 'quarantined_until' => 0 ];
    }

    $entries = metis_webhook_provider_failure_timestamps( $provider );
    $entries[] = time();
    $ttl = max( 60, metis_webhook_provider_failure_window_seconds() );
    \Metis\Core\Cache\CacheService::set( metis_webhook_provider_failures_cache_key( $provider ), $entries, $ttl );

    $count = count( $entries );
    $threshold = metis_webhook_provider_failure_threshold();
    $quarantined_until = 0;
    if ( $count >= $threshold ) {
        $quarantine_seconds = metis_webhook_provider_quarantine_seconds();
        $quarantined_until = time() + $quarantine_seconds;
        \Metis\Core\Cache\CacheService::set( metis_webhook_provider_quarantine_cache_key( $provider ), $quarantined_until, $quarantine_seconds );

        metis_audit_log_security( 'webhook_provider_quarantined', [
            'module'   => 'webhooks',
            'severity' => 'warning',
            'outcome'  => 'blocked',
            'resource' => [
                'type'  => 'webhook_provider',
                'id'    => $provider,
                'label' => $provider,
            ],
            'context' => [
                'provider' => $provider,
                'reason' => metis_key_clean( $reason ),
                'failure_count' => $count,
                'failure_threshold' => $threshold,
                'failure_window_seconds' => metis_webhook_provider_failure_window_seconds(),
                'quarantine_seconds' => $quarantine_seconds,
                'quarantined_until_unix' => $quarantined_until,
                'request_id' => metis_audit_request_id(),
            ],
        ] );
    }

    return [
        'count' => $count,
        'quarantined_until' => $quarantined_until,
    ];
}

function metis_webhook_assert_provider_available( string $provider ): void {
    $provider = metis_key_clean( $provider );
    if ( $provider === '' ) {
        return;
    }

    $quarantined_until = metis_webhook_provider_quarantined_until( $provider );
    if ( $quarantined_until <= time() ) {
        return;
    }

    throw new Metis_Webhook_Exception( 'Webhook provider is temporarily unavailable.', 503, 'webhook_provider_quarantined', [
        'provider' => $provider,
        'quarantined_until_unix' => $quarantined_until,
        'retry_after_seconds' => max( 1, $quarantined_until - time() ),
    ] );
}

function metis_webhook_enforce_rate_limit( string $provider, array $provider_config, Metis_Http_Request $request, string $endpoint ): void {
    $limit = (int) ( $provider_config['rate_limit'] ?? 0 );
    if ( $limit < 1 ) {
        $limit = metis_webhook_rate_limit_default();
    }

    $window = (int) ( $provider_config['rate_window_seconds'] ?? 0 );
    if ( $window < 1 ) {
        $window = metis_webhook_rate_window_seconds_default();
    }

    $ip = trim( metis_audit_ip_address() );
    $actor = $ip !== '' ? $ip : 'unknown_ip';
    $bucket = metis_webhook_rate_limit_bucket( $provider, $endpoint, $actor );

    if ( ( new \Metis\Core\Security\RateLimiter() )->consume( $bucket, $limit, $window ) ) {
        return;
    }

    throw new Metis_Webhook_Exception( 'Webhook rate limit exceeded.', 429, 'webhook_rate_limited', [
        'provider' => metis_key_clean( $provider ),
        'endpoint' => $endpoint,
        'actor' => $actor,
        'limit' => $limit,
        'window_seconds' => $window,
        'retry_after_seconds' => $window,
    ] );
}

function metis_webhook_error_response_headers( string $request_id, ?Metis_Webhook_Exception $error = null ): array {
    $headers = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'X-Metis-Request-Id' => $request_id,
    ];

    if ( ! $error instanceof Metis_Webhook_Exception ) {
        return $headers;
    }

    $context = $error->context();
    $retry_after = 0;
    if ( isset( $context['retry_after_seconds'] ) ) {
        $retry_after = (int) $context['retry_after_seconds'];
    } elseif ( metis_key_clean( $error->code_name() ) === 'webhook_rate_limited' ) {
        $retry_after = (int) ( $context['window_seconds'] ?? 0 );
    }

    if ( $retry_after > 0 && in_array( $error->status(), [ 429, 503 ], true ) ) {
        $headers['Retry-After'] = (string) max( 1, $retry_after );
    }

    return $headers;
}

function metis_webhook_enforce_replay_window( Metis_Http_Request $request, array $timestamp_headers = [ 'x-webhook-timestamp', 'x-signature-timestamp' ] ): void {
    $timestamp = metis_webhook_extract_timestamp( $request, $timestamp_headers );
    $required = metis_webhook_timestamp_required();

    if ( $timestamp === null ) {
        if ( $required ) {
            throw new Metis_Webhook_Exception( 'Webhook timestamp is missing.', 401, 'webhook_timestamp_missing', [
                'timestamp_required' => true,
                'timestamp_headers'  => array_values( $timestamp_headers ),
            ] );
        }

        return;
    }

    $now = time();
    $window = metis_webhook_replay_window_seconds();
    $skew = abs( $now - $timestamp );

    if ( $skew > $window ) {
        throw new Metis_Webhook_Exception( 'Webhook timestamp is outside replay window.', 401, 'webhook_replay_window_exceeded', [
            'timestamp_unix'  => $timestamp,
            'now_unix'        => $now,
            'skew_seconds'    => $skew,
            'window_seconds'  => $window,
            'timestamp_headers' => array_values( $timestamp_headers ),
        ] );
    }
}

function metis_webhook_verify_bearer_token( Metis_Http_Request $request, string $secret, string $header = 'authorization' ): void {
    $provided = trim( $request->header( $header ) );
    $expected = trim( $secret );

    if ( $expected === '' ) {
        throw new Metis_Webhook_Exception( 'Webhook secret is not configured.', 503, 'webhook_secret_missing' );
    }

    $provided_token = preg_match( '/^Bearer\s+(.+)$/i', $provided, $matches ) === 1
        ? trim( (string) ( $matches[1] ?? '' ) )
        : $provided;

    metis_webhook_enforce_replay_window( $request, [ 'x-webhook-timestamp', 'x-signature-timestamp', 'x-auth-timestamp' ] );

    if ( $provided_token === '' || ! hash_equals( $expected, $provided_token ) ) {
        throw new Metis_Webhook_Exception( 'Webhook token is invalid.', 401, 'webhook_signature_invalid', [
            'signature_header' => metis_key_clean( $header ),
            'signature_present' => $provided !== '',
            'signature_fingerprint' => metis_webhook_value_fingerprint( $provided_token ),
        ] );
    }
}

function metis_webhook_verify_hmac_sha256( Metis_Http_Request $request, string $secret, string $header = 'x-signature' ): void {
    $provided = trim( $request->header( $header ) );
    $expected = trim( $secret );

    if ( $expected === '' ) {
        throw new Metis_Webhook_Exception( 'Webhook secret is not configured.', 503, 'webhook_secret_missing' );
    }

    metis_webhook_enforce_replay_window( $request );

    $computed = hash_hmac( 'sha256', $request->body(), $expected );

    if ( ! hash_equals( $computed, $provided ) ) {
        throw new Metis_Webhook_Exception( 'Webhook signature is invalid.', 401, 'webhook_signature_invalid', [
            'signature_header' => metis_key_clean( $header ),
            'signature_present' => $provided !== '',
            'signature_fingerprint' => metis_webhook_value_fingerprint( $provided ),
        ] );
    }
}

function metis_webhook_handle_router_request( Metis_Http_Request $request ): Metis_Http_Response {
    $provider_name = metis_key_clean( (string) $request->attribute( 'provider', '' ) );
    $provider = metis_webhook_provider( $provider_name );
    $request_id = metis_audit_request_id();
    $endpoint = '/' . ltrim( (string) $request->path(), '/' );
    $route_name = 'webhook.gateway';

    if ( ! is_array( $provider ) ) {
        metis_audit_log_security( 'webhook_rejected', [
            'module'   => 'webhooks',
            'severity' => 'warning',
            'outcome'  => 'blocked',
            'resource' => [
                'type'  => 'webhook_provider',
                'id'    => $provider_name,
                'label' => $provider_name,
            ],
            'context'  => [
                'provider'      => $provider_name,
                'route'         => $route_name,
                'endpoint'      => $endpoint,
                'status_code'   => 404,
                'error_code'    => 'webhook_provider_unknown',
                'error_message' => 'Unknown webhook provider.',
                'request_id'    => $request_id,
            ],
        ] );

        return Metis_Http_Response::json(
            [ 'error' => 'Unknown webhook provider.', 'code' => 'webhook_provider_unknown' ],
            404,
            metis_webhook_error_response_headers( $request_id )
        );
    }

    try {
        metis_webhook_assert_provider_available( $provider_name );
        metis_webhook_enforce_provider_allowlist( $provider_name, $provider, $request, $endpoint );
        metis_webhook_enforce_rate_limit( $provider_name, $provider, $request, $endpoint );

        if ( ! is_callable( $provider['verify'] ?? null ) ) {
            throw new Metis_Webhook_Exception( 'Webhook verifier is not configured.', 500, 'webhook_verifier_missing' );
        }

        if ( ! is_callable( $provider['process'] ?? null ) ) {
            throw new Metis_Webhook_Exception( 'Webhook handler is not configured.', 500, 'webhook_handler_missing' );
        }

        $event = metis_webhook_normalize_event(
            $provider_name,
            call_user_func( $provider['verify'], $request )
        );
    } catch ( Metis_Webhook_Exception $e ) {
        if ( $e->status() >= 500 ) {
            metis_webhook_record_provider_failure( $provider_name, 'verify_failed' );
        }

        metis_audit_log_security( 'webhook_rejected', [
            'module'   => 'webhooks',
            'severity' => $e->status() >= 500 ? 'error' : 'warning',
            'outcome'  => 'blocked',
            'resource' => [
                'type'  => 'webhook_provider',
                'id'    => $provider_name,
                'label' => $provider_name,
            ],
            'context'  => array_merge(
                [
                    'provider' => $provider_name,
                    'route'    => $route_name,
                    'reason'   => $e->code_name(),
                    'endpoint' => $endpoint,
                    'request_id' => $request_id,
                ],
                metis_webhook_exception_context_for_audit( $e->context() )
            ),
        ] );

        Metis_Logger::warn( 'Webhook rejected', [
            'provider' => $provider_name,
            'error'    => $e->getMessage(),
            'code'     => $e->code_name(),
            'endpoint' => $endpoint,
            'request_id' => $request_id,
        ] );

        return Metis_Http_Response::json(
            [ 'error' => 'Webhook request rejected.', 'code' => $e->code_name() ],
            $e->status(),
            metis_webhook_error_response_headers( $request_id, $e )
        );
    }

    try {
        $claim = metis_webhook_claim_event( $provider_name, $event, $request );
    } catch ( Throwable $e ) {
        metis_audit_log_security( 'webhook_claim_failed', [
            'module'   => 'webhooks',
            'severity' => 'error',
            'outcome'  => 'failed',
            'resource' => [
                'type'  => 'webhook_event',
                'id'    => (string) ( $event['event_id'] ?? '' ),
                'label' => (string) ( $event['event_type'] ?? '' ),
            ],
            'context'  => [
                'provider'      => $provider_name,
                'route'         => $route_name,
                'endpoint'      => $endpoint,
                'status_code'   => 500,
                'error_message' => 'Webhook persistence failed.',
                'error_class'   => get_class( $e ),
                'request_id'    => $request_id,
            ],
        ] );

        Metis_Logger::error( 'Webhook claim failed', [
            'provider'   => $provider_name,
            'event_id'   => $event['event_id'],
            'event_type' => $event['event_type'],
            'error'      => $e->getMessage(),
            'endpoint'   => $endpoint,
            'request_id' => $request_id,
        ] );

        return Metis_Http_Response::json(
            [ 'error' => 'Webhook persistence failed.' ],
            500,
            metis_webhook_error_response_headers( $request_id )
        );
    }

    if ( $claim['state'] === 'duplicate_processed' ) {
        metis_audit_log_activity( 'webhook_duplicate_ignored', [
            'module'   => 'webhooks',
            'resource' => [
                'type'  => 'webhook_event',
                'id'    => $event['event_id'],
                'label' => $event['event_type'],
            ],
            'context'  => [
                'provider' => $provider_name,
                'route'    => $route_name,
                'status'   => 'processed',
                'endpoint' => $endpoint,
                'request_id' => $request_id,
            ],
        ] );

        return Metis_Http_Response::json(
            [ 'received' => true, 'duplicate' => true ],
            200,
            metis_webhook_error_response_headers( $request_id )
        );
    }

    if ( $claim['state'] === 'duplicate_processing' ) {
        return Metis_Http_Response::json(
            [ 'received' => true, 'duplicate' => true, 'processing' => true ],
            202,
            metis_webhook_error_response_headers( $request_id )
        );
    }

    $event_row_id = (int) $claim['id'];

    metis_audit_log_activity( 'webhook_received', [
        'module'   => 'webhooks',
        'resource' => [
            'type'  => 'webhook_event',
            'id'    => $event['event_id'],
            'label' => $event['event_type'],
        ],
        'context'  => [
            'provider' => $provider_name,
            'route'    => $route_name,
            'endpoint' => $endpoint,
            'request_id' => $request_id,
        ],
    ] );

    try {
        $result = call_user_func( $provider['process'], $event, $request );

        metis_webhook_mark_provider_healthy( $provider_name );
        metis_webhook_mark_processed( $event_row_id );
        metis_audit_log_activity( 'webhook_processed', [
            'module'   => 'webhooks',
            'resource' => [
                'type'  => 'webhook_event',
                'id'    => $event['event_id'],
                'label' => $event['event_type'],
            ],
            'context'  => array_merge(
                [
                    'provider' => $provider_name,
                    'route'    => $route_name,
                    'endpoint' => $endpoint,
                    'request_id' => $request_id,
                ],
                is_array( $result ) ? metis_audit_sanitize_context( $result ) : []
            ),
        ] );

        Metis_Logger::info( 'Webhook processed', [
            'provider'   => $provider_name,
            'event_id'   => $event['event_id'],
            'event_type' => $event['event_type'],
            'endpoint'   => $endpoint,
            'request_id' => $request_id,
        ] );

        return Metis_Http_Response::json(
            [ 'received' => true ],
            200,
            metis_webhook_error_response_headers( $request_id )
        );
    } catch ( Throwable $e ) {
        $provider_failure = metis_webhook_record_provider_failure( $provider_name, 'processing_failed' );
        metis_webhook_mark_failed( $event_row_id, $e );
        metis_audit_log_security( 'webhook_processing_failed', [
            'module'   => 'webhooks',
            'severity' => 'error',
            'outcome'  => 'failed',
            'resource' => [
                'type'  => 'webhook_event',
                'id'    => $event['event_id'],
                'label' => $event['event_type'],
            ],
            'context'  => [
                'provider' => $provider_name,
                'route'    => $route_name,
                'endpoint' => $endpoint,
                'status_code' => 500,
                'error_message' => 'Webhook processing failed.',
                'error_class' => get_class( $e ),
                'provider_failure_count' => (int) ( $provider_failure['count'] ?? 0 ),
                'provider_quarantined_until_unix' => (int) ( $provider_failure['quarantined_until'] ?? 0 ),
                'request_id' => $request_id,
            ],
        ] );

        Metis_Logger::error( 'Webhook processing failed', [
            'provider'   => $provider_name,
            'event_id'   => $event['event_id'],
            'event_type' => $event['event_type'],
            'error'      => $e->getMessage(),
            'endpoint'   => $endpoint,
            'request_id' => $request_id,
        ] );

        return Metis_Http_Response::json(
            [ 'error' => 'Webhook processing failed.' ],
            500,
            metis_webhook_error_response_headers( $request_id )
        );
    }
}
