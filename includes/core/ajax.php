<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class Metis_Ajax_Die_Exception extends RuntimeException {
    public function __construct(
        string $message = '',
        private readonly int $status = 200
    ) {
        parent::__construct( $message );
    }

    public function status(): int {
        return $this->status;
    }
}

final class Metis_Ajax_Controller_Registry {
    private static ?self $instance = null;
    private array $controllers = [];
    private bool $bootstrapped = false;

    public static function instance(): self {
        if ( self::$instance instanceof self ) {
            return self::$instance;
        }

        self::$instance = new self();
        return self::$instance;
    }

    public function register( string $action, array $definition = [] ): void {
        $action = sanitize_key( $action );
        if ( $action === '' || ! str_starts_with( $action, 'metis_' ) ) {
            return;
        }

        $module = sanitize_key( (string) ( $definition['module'] ?? metis_security_infer_module_from_ajax_action( $action ) ?? '' ) );

        $this->controllers[ $action ] = array_merge(
            [
                'action' => $action,
                'module' => $module,
                'permission' => (string) ( $definition['permission'] ?? metis_security_infer_permission_from_ajax_action( $action ) ),
                'methods' => [ 'POST' ],
                'nonce_action' => metis_ajax_nonce_action( $action ),
                'rate_limit' => (int) ( $definition['rate_limit'] ?? 0 ),
                'rate_window_seconds' => (int) ( $definition['rate_window_seconds'] ?? 60 ),
                'allow_additional_fields' => array_key_exists( 'allow_additional_fields', $definition ) ? (bool) $definition['allow_additional_fields'] : true,
                'schema' => [],
            ],
            $definition
        );

        $this->controllers[ $action ]['schema'] = $this->normalize_schema(
            $this->controllers[ $action ]['schema'],
            (bool) $this->controllers[ $action ]['allow_additional_fields']
        );
        $this->controllers[ $action ]['schema']['fields']['action']['enum'] = [ $action ];
    }

    public function has( string $action ): bool {
        $this->bootstrap();
        return isset( $this->controllers[ sanitize_key( $action ) ] );
    }

    public function get( string $action ): ?array {
        $this->bootstrap();
        $action = sanitize_key( $action );
        return $this->controllers[ $action ] ?? null;
    }

    public function all(): array {
        $this->bootstrap();
        ksort( $this->controllers );
        return $this->controllers;
    }

    private function bootstrap(): void {
        if ( $this->bootstrapped ) {
            return;
        }

        $this->bootstrapped = true;
        $hooks = array_merge(
            array_keys( (array) $GLOBALS['wp_filter'] ),
            array_keys( (array) $GLOBALS['merged_filters'] )
        );

        foreach ( array_unique( $hooks ) as $hook ) {
            if ( ! is_string( $hook ) || ! str_starts_with( $hook, 'wp_ajax_' ) ) {
                continue;
            }

            $action = sanitize_key( substr( $hook, strlen( 'wp_ajax_' ) ) );
            if ( $action === '' || ! str_starts_with( $action, 'metis_' ) || isset( $this->controllers[ $action ] ) ) {
                continue;
            }

            $this->register( $action );
        }
    }

    private function normalize_schema( mixed $schema, bool $allow_additional_fields ): array {
        $fields = [];
        if ( is_array( $schema ) && isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
            $fields = $schema['fields'];
            $allow_additional_fields = array_key_exists( 'allow_additional_fields', $schema )
                ? (bool) $schema['allow_additional_fields']
                : $allow_additional_fields;
        } elseif ( is_array( $schema ) ) {
            $fields = $schema;
        }

        $normalized = [
            'fields' => [
                'action' => [
                    'type' => 'string',
                    'required' => true,
                    'enum' => [],
                ],
                'nonce' => [
                    'type' => 'string',
                    'required' => true,
                ],
            ],
            'allow_additional_fields' => $allow_additional_fields,
        ];

        foreach ( $fields as $field => $definition ) {
            if ( ! is_string( $field ) || $field === '' ) {
                continue;
            }

            $normalized['fields'][ $field ] = $this->normalize_field( $definition );
        }

        return $normalized;
    }

    private function normalize_field( mixed $definition ): array {
        if ( is_string( $definition ) ) {
            $definition = [ 'type' => $definition ];
        }

        if ( ! is_array( $definition ) ) {
            $definition = [];
        }

        return [
            'type' => (string) ( $definition['type'] ?? 'string' ),
            'required' => ! empty( $definition['required'] ),
            'enum' => array_values( array_map( 'strval', (array) ( $definition['enum'] ?? [] ) ) ),
        ];
    }
}

function metis_ajax_registry(): Metis_Ajax_Controller_Registry {
    if ( function_exists( 'metis_help_register_ajax_controllers' ) ) {
        metis_help_register_ajax_controllers();
    }
    if ( function_exists( 'metis_walkthrough_register_ajax_controllers' ) ) {
        metis_walkthrough_register_ajax_controllers();
    }

    return Metis_Ajax_Controller_Registry::instance();
}

function metis_ajax_register_controller( string $action, array $definition = [] ): void {
    metis_ajax_registry()->register( $action, $definition );
}

function metis_ajax_nonce_action( string $action ): string {
    return 'metis_ajax:' . sanitize_key( $action );
}

function metis_ajax_endpoint_path(): string {
    return '/api/ajax';
}

function metis_ajax_endpoint_url(): string {
    return home_url( metis_ajax_endpoint_path() );
}

function metis_ajax_request_matches( Metis_Http_Request $request ): bool {
    return untrailingslashit( $request->path() ) === metis_ajax_endpoint_path();
}

function metis_ajax_request_action( Metis_Http_Request $request ): string {
    $input = $request->input();
    return sanitize_key( isset( $input['action'] ) ? (string) $input['action'] : '' );
}

function metis_ajax_action_nonces(): array {
    $nonces = [];
    foreach ( metis_ajax_registry()->all() as $action => $controller ) {
        $nonces[ $action ] = metis_create_nonce( (string) ( $controller['nonce_action'] ?? metis_ajax_nonce_action( $action ) ) );
    }

    return $nonces;
}

function metis_ajax_verify_same_origin( Metis_Http_Request $request ): void {
    if ( strtoupper( $request->method() ) !== 'POST' ) {
        throw new Metis_Security_Enclave_Exception( 'Unsupported method.', 'invalid_method', 405 );
    }

    $site_host = strtolower( (string) metis_parse_url( home_url( '/' ), PHP_URL_HOST ) );
    if ( $site_host === '' ) {
        return;
    }

    foreach ( [ 'origin', 'referer' ] as $header_name ) {
        $header_value = trim( $request->header( $header_name ) );
        if ( $header_value === '' ) {
            continue;
        }

        $header_host = strtolower( (string) metis_parse_url( $header_value, PHP_URL_HOST ) );
        if ( $header_host !== '' && hash_equals( $site_host, $header_host ) ) {
            return;
        }

        throw new Metis_Security_Enclave_Exception( 'Cross-site request rejected.', 'csrf_failed', 403 );
    }

    throw new Metis_Security_Enclave_Exception( 'Missing origin context.', 'csrf_context_missing', 403 );
}

function metis_ajax_validate_request( Metis_Http_Request $request, array $controller ): array {
    $input  = $request->input();
    $schema = (array) ( $controller['schema'] ?? [] );
    $fields = (array) ( $schema['fields'] ?? [] );

    $errors = [];
    foreach ( $fields as $field => $definition ) {
        $definition = is_array( $definition ) ? $definition : [];
        $required   = ! empty( $definition['required'] );
        $exists     = array_key_exists( $field, $input );
        $value      = $exists ? $input[ $field ] : null;

        if ( $required && ! $exists ) {
            $errors[] = sprintf( 'Missing required field: %s.', $field );
            continue;
        }

        if ( ! $exists ) {
            continue;
        }

        $type = (string) ( $definition['type'] ?? 'string' );
        if ( ! metis_ajax_value_matches_type( $value, $type ) ) {
            $errors[] = sprintf( 'Invalid field type for %s.', $field );
            continue;
        }

        $enum = array_values( array_map( 'strval', (array) ( $definition['enum'] ?? [] ) ) );
        if ( ! empty( $enum ) && ! in_array( (string) $value, $enum, true ) ) {
            $errors[] = sprintf( 'Invalid value for %s.', $field );
        }
    }

    if ( empty( $schema['allow_additional_fields'] ) ) {
        $unknown = array_diff( array_keys( $input ), array_keys( $fields ) );
        foreach ( $unknown as $field ) {
            $errors[] = sprintf( 'Unexpected field: %s.', (string) $field );
        }
    }

    if ( ! empty( $errors ) ) {
        throw new Metis_Security_Enclave_Exception(
            'Payload validation failed.',
            'invalid_payload',
            422,
            [ 'errors' => $errors ]
        );
    }

    return $input;
}

function metis_ajax_value_matches_type( mixed $value, string $type ): bool {
    return match ( $type ) {
        'string' => is_scalar( $value ) || $value === null,
        'int', 'integer' => is_numeric( $value ) || $value === null,
        'bool', 'boolean' => is_bool( $value ) || $value === '0' || $value === '1' || $value === 0 || $value === 1,
        'array' => is_array( $value ),
        'json' => is_string( $value ) && ( $value === '' || json_decode( $value, true ) !== null || $value === 'null' ),
        default => true,
    };
}

function metis_ajax_capture_wp_die_handler( string $message, string $title = '', array $args = [] ): never {
    $status = isset( $args['response'] ) && is_numeric( $args['response'] ) ? (int) $args['response'] : 200;
    throw new Metis_Ajax_Die_Exception( $message, $status );
}

function metis_ajax_dispatch_legacy_action( string $action ): array {
    if ( ! defined( 'DOING_AJAX' ) ) {
        define( 'DOING_AJAX', true );
    }

    $hooks = [];
    $hooks[] = metis_user_logged_in() ? 'wp_ajax_' . $action : 'wp_ajax_nopriv_' . $action;
    $hooks[] = metis_user_logged_in() ? 'wp_ajax_nopriv_' . $action : 'wp_ajax_' . $action;
    $hook = '';

    foreach ( $hooks as $candidate ) {
        if ( metis_has_action( $candidate ) ) {
            $hook = $candidate;
            break;
        }
    }

    if ( $hook === '' ) {
        throw new Metis_Security_Enclave_Exception( 'AJAX controller not found.', 'ajax_handler_missing', 404 );
    }

    $body        = '';
    $status_code = 200;
    $status_hook = static function ( string $status_header, int $code ) use ( &$status_code ): string {
        $status_code = $code;
        return $status_header;
    };
    $die_hook    = static function (): string {
        return 'metis_ajax_capture_wp_die_handler';
    };

    ob_start();
    metis_add_filter( 'wp_die_ajax_handler', $die_hook, PHP_INT_MAX );
    metis_add_filter( 'wp_die_handler', $die_hook, PHP_INT_MAX );
    metis_add_filter( 'status_header', $status_hook, PHP_INT_MAX, 2 );

    try {
        metis_do_action( $hook );
        $body = (string) ob_get_clean();
    } catch ( Metis_Ajax_Die_Exception $e ) {
        $body        = (string) ob_get_clean();
        $status_code = $e->status() > 0 ? $e->status() : $status_code;
        if ( $body === '' && $e->getMessage() !== '' ) {
            $body = $e->getMessage();
        }
    } finally {
        metis_remove_filter( 'wp_die_ajax_handler', $die_hook, PHP_INT_MAX );
        metis_remove_filter( 'wp_die_handler', $die_hook, PHP_INT_MAX );
        metis_remove_filter( 'status_header', $status_hook, PHP_INT_MAX );
    }

    $decoded = json_decode( $body, true );
    if ( is_array( $decoded ) ) {
        return [
            'status' => $status_code,
            'body' => $decoded,
        ];
    }

    return [
        'status' => $status_code,
        'body' => [
            'success' => $status_code < 400,
            'data' => [
                'message' => $body !== '' ? $body : 'Empty AJAX response.',
            ],
        ],
    ];
}
