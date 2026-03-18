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

    $path = sanitize_title_with_dashes( (string) $path );

    return $path !== '' ? $path : 'metis-webhooks';
}

function metis_webhook_base_url(): string {
    return metis_trailingslashit( metis_site_url( '/' . metis_webhook_base_path() ) );
}

function metis_webhook_url( string $provider ): string {
    return metis_trailingslashit( metis_webhook_base_url() . sanitize_key( $provider ) );
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

    $provider = sanitize_key( (string) $matches[1] );
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
    $provider = sanitize_key( $provider );
    if ( $provider === '' ) {
        return;
    }

    $providers = metis_webhook_registry();
    $providers[ $provider ] = $config;
    $GLOBALS['metis_webhook_providers'] = $providers;
}

function metis_webhook_provider( string $provider ): ?array {
    $provider = sanitize_key( $provider );
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

function metis_webhook_normalize_event( string $provider, mixed $event ): array {
    if ( ! is_array( $event ) ) {
        throw new Metis_Webhook_Exception( 'Webhook payload is invalid.', 400, 'webhook_payload_invalid' );
    }

    $normalized = [
        'provider'    => sanitize_key( $provider ),
        'event_id'    => substr( sanitize_text_field( (string) ( $event['event_id'] ?? '' ) ), 0, 191 ),
        'event_type'  => substr( sanitize_text_field( (string) ( $event['event_type'] ?? '' ) ), 0, 191 ),
        'resource_id' => substr( sanitize_text_field( (string) ( $event['resource_id'] ?? '' ) ), 0, 191 ),
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
            'last_error' => substr( $error->getMessage(), 0, 65535 ),
            'updated_at' => metis_current_time( 'mysql' ),
        ],
        [ 'id' => $id ],
        [ '%s', '%s', '%s' ],
        [ '%d' ]
    );
}

function metis_webhook_verify_bearer_token( Metis_Http_Request $request, string $secret, string $header = 'authorization' ): void {
    $provided = trim( $request->header( $header ) );
    $expected = trim( $secret );

    if ( $expected === '' ) {
        throw new Metis_Webhook_Exception( 'Webhook secret is not configured.', 503, 'webhook_secret_missing' );
    }

    if ( ! hash_equals( 'Bearer ' . $expected, $provided ) ) {
        throw new Metis_Webhook_Exception( 'Webhook token is invalid.', 401, 'webhook_signature_invalid' );
    }
}

function metis_webhook_verify_hmac_sha256( Metis_Http_Request $request, string $secret, string $header = 'x-signature' ): void {
    $provided = trim( $request->header( $header ) );
    $expected = trim( $secret );

    if ( $expected === '' ) {
        throw new Metis_Webhook_Exception( 'Webhook secret is not configured.', 503, 'webhook_secret_missing' );
    }

    $computed = hash_hmac( 'sha256', $request->body(), $expected );

    if ( ! hash_equals( $computed, $provided ) ) {
        throw new Metis_Webhook_Exception( 'Webhook signature is invalid.', 401, 'webhook_signature_invalid' );
    }
}

function metis_webhook_handle_router_request( Metis_Http_Request $request ): Metis_Http_Response {
    $provider_name = sanitize_key( (string) $request->attribute( 'provider', '' ) );
    $provider = metis_webhook_provider( $provider_name );

    if ( ! is_array( $provider ) ) {
        return Metis_Http_Response::json(
            [ 'error' => 'Unknown webhook provider.' ],
            404,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    try {
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
                    'route'    => 'webhook.gateway',
                    'reason'   => $e->code_name(),
                ],
                $e->context()
            ),
        ] );

        Metis_Logger::warn( 'Webhook rejected', [
            'provider' => $provider_name,
            'error'    => $e->getMessage(),
            'code'     => $e->code_name(),
        ] );

        return Metis_Http_Response::json(
            [ 'error' => $e->getMessage(), 'code' => $e->code_name() ],
            $e->status(),
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    try {
        $claim = metis_webhook_claim_event( $provider_name, $event, $request );
    } catch ( Throwable $e ) {
        Metis_Logger::error( 'Webhook claim failed', [
            'provider'   => $provider_name,
            'event_id'   => $event['event_id'],
            'event_type' => $event['event_type'],
            'error'      => $e->getMessage(),
        ] );

        return Metis_Http_Response::json(
            [ 'error' => 'Webhook persistence failed.' ],
            500,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
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
                'route'    => 'webhook.gateway',
                'status'   => 'processed',
            ],
        ] );

        return Metis_Http_Response::json(
            [ 'received' => true, 'duplicate' => true ],
            200,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    if ( $claim['state'] === 'duplicate_processing' ) {
        return Metis_Http_Response::json(
            [ 'received' => true, 'duplicate' => true, 'processing' => true ],
            202,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
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
            'route'    => 'webhook.gateway',
        ],
    ] );

    try {
        $result = call_user_func( $provider['process'], $event, $request );

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
                    'route'    => 'webhook.gateway',
                ],
                is_array( $result ) ? metis_audit_sanitize_context( $result ) : []
            ),
        ] );

        Metis_Logger::info( 'Webhook processed', [
            'provider'   => $provider_name,
            'event_id'   => $event['event_id'],
            'event_type' => $event['event_type'],
        ] );

        return Metis_Http_Response::json(
            [ 'received' => true ],
            200,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    } catch ( Throwable $e ) {
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
                'route'    => 'webhook.gateway',
                'error'    => $e->getMessage(),
            ],
        ] );

        Metis_Logger::error( 'Webhook processing failed', [
            'provider'   => $provider_name,
            'event_id'   => $event['event_id'],
            'event_type' => $event['event_type'],
            'error'      => $e->getMessage(),
        ] );

        return Metis_Http_Response::json(
            [ 'error' => 'Webhook processing failed.' ],
            500,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }
}
