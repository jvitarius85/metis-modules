<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

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
        $action = metis_key_clean( $action );
        if ( $action === '' || ! str_starts_with( $action, 'metis_' ) ) {
            return;
        }

        $module = metis_key_clean(
            (string) (
                $definition['module']
                ?? ( function_exists( 'metis_security_infer_module_from_ajax_action' )
                    ? metis_security_infer_module_from_ajax_action( $action )
                    : '' )
                ?? ''
            )
        );

        $this->controllers[ $action ] = array_merge(
            [
                'action' => $action,
                'module' => $module,
                'permission' => (string) (
                    $definition['permission']
                    ?? ( function_exists( 'metis_security_infer_permission_from_ajax_action' )
                        ? metis_security_infer_permission_from_ajax_action( $action )
                        : '' )
                ),
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
        return isset( $this->controllers[ metis_key_clean( $action ) ] );
    }

    public function get( string $action ): ?array {
        $this->bootstrap();
        $action = metis_key_clean( $action );
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
        foreach ( metis_ajax_handler_registry()->actions() as $action ) {
            if ( ! isset( $this->controllers[ $action ] ) ) {
                $this->register( $action );
            }
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

final class Metis_Ajax_Handler_Registry {
    private static ?self $instance = null;
    private array $handlers = [];

    public static function instance(): self {
        if ( self::$instance instanceof self ) {
            return self::$instance;
        }

        self::$instance = new self();
        return self::$instance;
    }

    public function register( string $action, callable|string $handler ): void {
        $action = metis_key_clean( $action );
        if ( $action === '' || ! str_starts_with( $action, 'metis_' ) ) {
            return;
        }

        $this->handlers[ $action ] = $handler;
    }

    public function has( string $action ): bool {
        return array_key_exists( metis_key_clean( $action ), $this->handlers );
    }

    public function get( string $action ): callable|string|null {
        $action = metis_key_clean( $action );
        return $this->handlers[ $action ] ?? null;
    }

    public function actions(): array {
        return array_keys( $this->handlers );
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

function metis_ajax_handler_registry(): Metis_Ajax_Handler_Registry {
    if ( function_exists( 'metis_help_register_ajax_handlers' ) ) {
        metis_help_register_ajax_handlers();
    }
    if ( function_exists( 'metis_walkthrough_register_ajax_handlers' ) ) {
        metis_walkthrough_register_ajax_handlers();
    }
    return Metis_Ajax_Handler_Registry::instance();
}

function metis_ajax_register_handler( string $action, callable|string $handler, array $definition = [] ): void {
    $action = metis_key_clean( $action );
    metis_ajax_handler_registry()->register( $action, $handler );

    if ( $definition !== [] ) {
        metis_ajax_register_controller( $action, $definition );
    } elseif ( ! metis_ajax_registry()->has( $action ) ) {
        metis_ajax_register_controller( $action );
    }
}

function metis_ajax_nonce_action( string $action ): string {
    return 'metis_ajax:' . metis_key_clean( $action );
}

function metis_ajax_endpoint_path(): string {
    return '/api/ajax';
}

function metis_ajax_endpoint_url(): string {
    return metis_home_url( metis_ajax_endpoint_path() );
}

function metis_ajax_request_matches( Metis_Http_Request $request ): bool {
    return metis_untrailingslashit( $request->path() ) === metis_ajax_endpoint_path();
}

function metis_ajax_request_action( Metis_Http_Request $request ): string {
    $input = $request->input();
    return metis_key_clean( isset( $input['action'] ) ? (string) $input['action'] : '' );
}

function metis_ajax_action_nonces(): array {
    $nonces = [];
    foreach ( metis_ajax_registry()->all() as $action => $controller ) {
        $nonces[ $action ] = metis_runtime_create_nonce( (string) ( $controller['nonce_action'] ?? metis_ajax_nonce_action( $action ) ) );
    }

    return $nonces;
}

function metis_ajax_verify_same_origin( Metis_Http_Request $request ): void {
    if ( strtoupper( $request->method() ) !== 'POST' ) {
        throw new Metis_Security_Enclave_Exception( 'Unsupported method.', 'invalid_method', 405 );
    }

    $site_host = strtolower( (string) metis_runtime_parse_url( metis_home_url( '/' ), PHP_URL_HOST ) );
    $trusted_hosts = [];
    if ( $site_host !== '' ) {
        $trusted_hosts[] = $site_host;
    }

    $request_server = $request->server();
    $request_host = strtolower( trim( (string) ( $request_server['HTTP_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '' ) ) );
    if ( $request_host !== '' ) {
        $request_host = preg_replace( '/:\d+$/', '', $request_host ) ?: $request_host;
        if ( $request_host !== '' ) {
            $trusted_hosts[] = $request_host;
        }
    }

    $forwarded_host = strtolower( trim( (string) ( $request_server['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '' ) ) );
    if ( $forwarded_host !== '' ) {
        $primary_forwarded = trim( explode( ',', $forwarded_host )[0] ?? '' );
        $primary_forwarded = preg_replace( '/:\d+$/', '', $primary_forwarded ) ?: $primary_forwarded;
        if ( $primary_forwarded !== '' ) {
            $trusted_hosts[] = $primary_forwarded;
        }
    }

    $trusted_hosts = array_values( array_unique( array_filter( $trusted_hosts, static fn ( $host ): bool => is_string( $host ) && $host !== '' ) ) );
    if ( empty( $trusted_hosts ) ) {
        return;
    }

    foreach ( [ 'origin', 'referer' ] as $header_name ) {
        $header_value = trim( $request->header( $header_name ) );
        if ( $header_value === '' ) {
            continue;
        }

        $header_host = strtolower( (string) metis_runtime_parse_url( $header_value, PHP_URL_HOST ) );
        if ( $header_host !== '' ) {
            foreach ( $trusted_hosts as $trusted_host ) {
                if ( hash_equals( (string) $trusted_host, $header_host ) ) {
                    return;
                }
            }
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

function metis_ajax_capture_die_handler( string $message, string $title = '', array $args = [] ): never {
    $status = isset( $args['response'] ) && is_numeric( $args['response'] ) ? (int) $args['response'] : 200;
    throw new Metis_Ajax_Die_Exception( $message, $status );
}

function metis_ajax_log_dispatch_failure( string $action, int $status_code, string $message, string $error_code = 'ajax_dispatch_failed' ): void {
    $request_id = function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : '';
    $endpoint = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/api/ajax' ), PHP_URL_PATH ) ?? '/api/ajax' );
    $action_key = metis_key_clean( $action );
    $code_key = metis_key_clean( $error_code );
    $safe_message = trim( $message ) !== '' ? $message : 'Request failed.';

    metis_audit_log_security( 'ajax_action_failed', [
        'module'   => (string) ( metis_security_infer_module_from_ajax_action( $action_key ) ?? '' ),
        'severity' => $status_code >= 500 ? 'error' : 'warning',
        'outcome'  => 'failed',
        'resource' => [
            'type'  => 'ajax_action',
            'id'    => $action_key,
            'label' => $code_key,
        ],
        'context'  => [
            'route'         => 'ajax.metis.api',
            'endpoint'      => $endpoint,
            'status_code'   => $status_code,
            'error_code'    => $code_key,
            'error_message' => $safe_message,
            'request_id'    => $request_id,
        ],
    ] );
}

function metis_ajax_dispatch_handler( string $action ): array {
    $handler = metis_ajax_handler_registry()->get( $action );
    if ( $handler === null ) {
        metis_ajax_log_dispatch_failure( $action, 404, 'Request failed.', 'ajax_handler_missing' );
        throw new Metis_Security_Enclave_Exception( 'AJAX controller not found.', 'ajax_handler_missing', 404 );
    }

    if ( ! defined( 'DOING_AJAX' ) ) {
        define( 'DOING_AJAX', true );
    }

    $body = '';
    $status_code = 200;
    $failure_logged = false;

    ob_start();
    try {
        call_user_func( $handler );
        $body = (string) ob_get_clean();
    } catch ( Metis_Ajax_Die_Exception $e ) {
        $body        = (string) ob_get_clean();
        $status_code = $e->status() > 0 ? $e->status() : $status_code;
        if ( $body === '' && $e->getMessage() !== '' ) {
            $body = $status_code >= 400
                ? 'Request failed.'
                : 'Request completed.';
        }
        if ( $status_code >= 400 ) {
            metis_ajax_log_dispatch_failure( $action, $status_code, 'Request failed.', 'ajax_dispatch_failed' );
            $failure_logged = true;
        }
    }

    $decoded = json_decode( $body, true );
    if ( is_array( $decoded ) ) {
        if ( $status_code >= 400 && ! $failure_logged ) {
            $message = (string) ( $decoded['message'] ?? ( $decoded['data']['message'] ?? 'Request failed.' ) );
            metis_ajax_log_dispatch_failure( $action, $status_code, $message, 'ajax_dispatch_failed' );
            $failure_logged = true;
        }
        return [
            'status' => $status_code,
            'body' => $decoded,
        ];
    }

    if ( $status_code >= 400 && ! $failure_logged ) {
        $safe_message = $body !== '' ? 'Request failed.' : 'Empty AJAX response.';
        metis_ajax_log_dispatch_failure( $action, $status_code, $safe_message, 'ajax_dispatch_failed' );
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

function metis_core_register_ajax_controllers(): void {
    static $registered = false;

    if ( $registered || ! function_exists( 'metis_ajax_register_controller' ) ) {
        return;
    }

    $registered = true;

    metis_ajax_register_controller( 'metis_resolve_code', [
        'module' => 'portal',
        'permission' => 'view',
        'schema' => [
            'code' => [ 'type' => 'string', 'required' => true ],
        ],
    ] );

    metis_ajax_register_controller( 'metis_quick_action_form', [
        'module' => 'core',
        'permission' => 'view',
        'nonce_action' => 'metis_core',
        'schema' => [
            'key' => [ 'type' => 'string', 'required' => true ],
        ],
    ] );
}

metis_ajax_register_handler( 'metis_resolve_code', function () {
    if ( ! metis_user_logged_in() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    $code = strtoupper( trim( metis_text_clean( metis_runtime_unslash( $_POST['code'] ?? '' ) ) ) );
    $fuzzy = ! empty( $_POST['fuzzy'] );
    if ( $code === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'A lookup code is required.' ], 422 );
    }

    $max_candidates = 24;
    $max_results = 5;
    $matches = [];
    $seen_codes = [];
    $append_match = static function ( ?array $entry ) use ( &$matches, &$seen_codes ): void {
        if ( ! is_array( $entry ) ) {
            return;
        }

        $entry_code = strtoupper( trim( (string) ( $entry['code'] ?? '' ) ) );
        if ( $entry_code === '' || isset( $seen_codes[ $entry_code ] ) ) {
            return;
        }

        $seen_codes[ $entry_code ] = true;
        $matches[] = [
            'code' => $entry_code,
            'entity_uid' => $entry_code,
            'entity_type' => (string) ( $entry['entity_type'] ?? '' ),
            'label' => (string) ( $entry['label'] ?? $entry_code ),
            'url' => (string) ( $entry['resolve_url'] ?? $entry['url'] ?? '' ),
        ];
    };

    $score_match = static function ( array $match, string $query ): int {
        $candidate_code = strtoupper( trim( (string) ( $match['code'] ?? '' ) ) );
        $query = strtoupper( trim( $query ) );
        if ( $candidate_code === '' || $query === '' ) {
            return 9999;
        }

        $candidate_norm = preg_replace( '/[^A-Z0-9]/', '', $candidate_code ) ?? '';
        $query_norm = preg_replace( '/[^A-Z0-9*]/', '', $query ) ?? '';
        $query_plain = str_replace( '*', '', $query_norm );
        $score = 1000;

        if ( $candidate_code === $query ) {
            return 0;
        }
        if ( $query_plain !== '' && $candidate_norm === $query_plain ) {
            return 10;
        }

        if ( strpos( $query, '*' ) !== false ) {
            $pattern = '/^' . str_replace( '\*', '.*', preg_quote( $query_norm, '/' ) ) . '$/';
            if ( preg_match( $pattern, $candidate_norm ) === 1 ) {
                $score -= 250;
            }
        }

        if ( $query_plain !== '' ) {
            $pos = strpos( $candidate_norm, $query_plain );
            if ( $pos === 0 ) {
                $score -= 220;
            } elseif ( $pos !== false ) {
                $score -= (150 - min( 120, (int) $pos ));
            }
        }

        if ( preg_match( '/^([A-Z]{2,8})-(\d{6})$/', $candidate_code, $cm ) ) {
            $cand_prefix = (string) ( $cm[1] ?? '' );
            $cand_suffix = (string) ( $cm[2] ?? '' );
            $query_prefix = '';
            $query_digits = preg_replace( '/\D/', '', $query ) ?? '';

            if ( preg_match( '/^([A-Z0-9]{2,8})-?[0-9]*$/', $query, $qm ) ) {
                $query_prefix = strtoupper( (string) ( $qm[1] ?? '' ) );
            }

            if ( $query_prefix !== '' && $query_prefix === $cand_prefix ) {
                $score -= 140;
            }

            if ( $query_digits !== '' ) {
                $suffix_pos = strpos( $cand_suffix, $query_digits );
                if ( $suffix_pos === 0 ) {
                    $score -= 110;
                } elseif ( $suffix_pos !== false ) {
                    $score -= (80 - min( 60, (int) $suffix_pos ));
                }
            }
        }

        if ( $query_plain !== '' ) {
            $score += abs( strlen( $candidate_norm ) - strlen( $query_plain ) );
        }

        return $score;
    };

    $resolved = class_exists( 'Metis_Code_Registry' ) ? Metis_Code_Registry::resolve( $code ) : null;
    $append_match( is_array( $resolved ) ? $resolved : null );
    if ( ! is_array( $resolved ) && class_exists( 'Metis_Tables' ) ) {
        $people_table = Metis_Tables::get( 'people' );
        if ( is_string( $people_table ) && $people_table !== '' ) {
            $db = metis_db();
            $person = $db->fetchOne(
                "SELECT id, pid, person_uid, display_name, first_name, last_name, email
                 FROM {$people_table}
                 WHERE pid = %s OR person_uid = %s
                 LIMIT 1",
                [ $code, $code ]
            );

            if ( ! is_array( $person ) ) {
                $person = $db->fetchOne(
                    "SELECT id, pid, person_uid, display_name, first_name, last_name, email
                     FROM {$people_table}
                     WHERE UPPER(COALESCE(pid, '')) = %s
                        OR UPPER(COALESCE(person_uid, '')) = %s
                     LIMIT 1",
                    [ $code, $code ]
                );
            }

            if ( is_array( $person ) ) {
                $person_pid = trim( (string) ( $person['pid'] ?? $person['person_uid'] ?? $code ) );
                $label = trim( (string) ( $person['display_name'] ?? '' ) );
                if ( $label === '' ) {
                    $label = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
                }
                if ( $label === '' ) {
                    $label = trim( (string) ( $person['email'] ?? '' ) );
                }
                if ( $label === '' ) {
                    $label = $person_pid;
                }

                $resolved = [
                    'code' => $person_pid !== '' ? $person_pid : $code,
                    'entity_type' => 'person',
                    'label' => $label,
                    'resolve_url' => function_exists( 'metis_people_person_url' ) ? metis_people_person_url( $person_pid ) : '',
                ];
                $append_match( $resolved );
            }
        }
    }

    if ( ! is_array( $resolved ) ) {
        $registry_table = class_exists( 'Metis_Tables' ) ? Metis_Tables::get( 'entity_registry' ) : '';
        if ( $fuzzy && $registry_table !== '' ) {
            $db = metis_db();
            $build_patterns = static function ( string $input ): array {
                $input = strtoupper( trim( $input ) );
                $input = preg_replace( '/\s+/', '', $input ) ?? '';
                $input = preg_replace( '/[^A-Z0-9-]/', '', $input ) ?? '';
                if ( $input === '' ) {
                    return [];
                }

                $variants = [ $input ];
                $prefix_hint = '';
                $normalize_prefix = static function ( string $prefix ): string {
                    return strtr( strtoupper( $prefix ), [
                        '0' => 'O',
                        '1' => 'L',
                        '2' => 'Z',
                        '5' => 'S',
                        '8' => 'B',
                    ] );
                };

                if ( preg_match( '/^([A-Z0-9]{2,8})-?([0-9]{1,6})$/', $input, $matches ) ) {
                    $prefix = $normalize_prefix( (string) ( $matches[1] ?? '' ) );
                    $number = (string) ( $matches[2] ?? '' );
                    if ( $prefix !== '' && $number !== '' ) {
                        $prefix_hint = $prefix;
                        $variants[] = $prefix . '-' . $number;
                    }
                } elseif ( strpos( $input, '-' ) !== false ) {
                    [ $prefix, $suffix ] = array_pad( explode( '-', $input, 2 ), 2, '' );
                    $fixed_prefix = $normalize_prefix( (string) $prefix );
                    if ( $fixed_prefix !== '' && $suffix !== '' ) {
                        $prefix_hint = $fixed_prefix;
                        $variants[] = $fixed_prefix . '-' . $suffix;
                    }
                }

                $number_fragment = preg_replace( '/\D/', '', $input ) ?? '';
                $patterns = [];
                foreach ( array_values( array_unique( $variants ) ) as $variant ) {
                    $patterns[] = $variant . '%';
                    if ( preg_match( '/^([A-Z]{2,8})-([0-9]{1,6})$/', $variant, $m ) ) {
                        $prefix = (string) ( $m[1] ?? '' );
                        $num = (string) ( $m[2] ?? '' );
                        $patterns[] = $prefix . '-' . str_pad( $num, 6, '0', STR_PAD_LEFT ) . '%';
                    }
                    if ( preg_match( '/^[0-9]{1,6}$/', $variant ) ) {
                        $patterns[] = '%-' . str_pad( $variant, 6, '0', STR_PAD_LEFT );
                        if ( strlen( $variant ) >= 2 ) {
                            $patterns[] = '%-' . $variant . '%';
                        }
                    }
                }

                if ( $number_fragment !== '' ) {
                    $trimmed_fragment = ltrim( $number_fragment, '0' );
                    if ( $trimmed_fragment === '' ) {
                        $trimmed_fragment = '0';
                    }

                    $patterns[] = '%-' . $number_fragment . '%';
                    if ( $trimmed_fragment !== $number_fragment ) {
                        $patterns[] = '%-' . $trimmed_fragment . '%';
                    }

                    if ( $prefix_hint !== '' ) {
                        $patterns[] = $prefix_hint . '-%' . $number_fragment . '%';
                        if ( $trimmed_fragment !== $number_fragment ) {
                            $patterns[] = $prefix_hint . '-%' . $trimmed_fragment . '%';
                        }
                    }
                }

                return array_values( array_unique( $patterns ) );
            };

            foreach ( $build_patterns( $code ) as $pattern ) {
                $rows = $db->fetchAll(
                    "SELECT entity_uid
                     FROM {$registry_table}
                     WHERE UPPER(entity_uid) LIKE %s
                     ORDER BY entity_uid ASC
                     LIMIT 24",
                    [ strtoupper( $pattern ) ]
                );

                foreach ( (array) $rows as $row ) {
                    if ( ! is_array( $row ) || empty( $row['entity_uid'] ) || ! class_exists( 'Metis_Code_Registry' ) ) {
                        continue;
                    }

                    $candidate = Metis_Code_Registry::resolve( strtoupper( trim( (string) $row['entity_uid'] ) ) );
                    if ( is_array( $candidate ) ) {
                        $append_match( $candidate );
                        if ( ! is_array( $resolved ) ) {
                            $resolved = $candidate;
                        }
                    }
                }

                if ( count( $matches ) >= $max_candidates ) {
                    break;
                }
            }

            if ( count( $matches ) < $max_candidates ) {
                $normalized_lookup = strtoupper( preg_replace( '/[^A-Z0-9*]/', '', $code ) ?? '' );
                if ( $normalized_lookup !== '' && strlen( str_replace( '*', '', $normalized_lookup ) ) >= 3 ) {
                    $normalized_pattern = str_replace( '*', '%', $normalized_lookup );
                    if ( strpos( $normalized_pattern, '%' ) === false ) {
                        $normalized_pattern = '%' . $normalized_pattern . '%';
                    }

                    $rows = $db->fetchAll(
                        "SELECT entity_uid
                         FROM {$registry_table}
                         WHERE REPLACE(UPPER(entity_uid), '-', '') LIKE %s
                         ORDER BY entity_uid ASC
                         LIMIT 24",
                        [ $normalized_pattern ]
                    );

                    foreach ( (array) $rows as $row ) {
                        if ( ! is_array( $row ) || empty( $row['entity_uid'] ) || ! class_exists( 'Metis_Code_Registry' ) ) {
                            continue;
                        }

                        $candidate = Metis_Code_Registry::resolve( strtoupper( trim( (string) $row['entity_uid'] ) ) );
                        if ( is_array( $candidate ) ) {
                            $append_match( $candidate );
                            if ( ! is_array( $resolved ) ) {
                                $resolved = $candidate;
                            }
                        }

                        if ( count( $matches ) >= $max_candidates ) {
                            break;
                        }
                    }
                }
            }
        }
    }

    if ( $matches !== [] ) {
        foreach ( $matches as $index => $entry ) {
            $matches[ $index ]['_score'] = $score_match( is_array( $entry ) ? $entry : [], $code );
        }

        usort( $matches, static function ( array $a, array $b ): int {
            $left = (int) ( $a['_score'] ?? 9999 );
            $right = (int) ( $b['_score'] ?? 9999 );
            if ( $left === $right ) {
                return strcmp( (string) ( $a['code'] ?? '' ), (string) ( $b['code'] ?? '' ) );
            }
            return $left <=> $right;
        } );

        $matches = array_map( static function ( array $entry ): array {
            unset( $entry['_score'] );
            return $entry;
        }, $matches );
    }

    if ( ! is_array( $resolved ) && $matches !== [] ) {
        $resolved = [
            'code' => (string) ( $matches[0]['code'] ?? $code ),
            'entity_type' => (string) ( $matches[0]['entity_type'] ?? '' ),
            'label' => (string) ( $matches[0]['label'] ?? $code ),
            'resolve_url' => (string) ( $matches[0]['url'] ?? '' ),
        ];
    }

    if ( ! is_array( $resolved ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Code not found.' ], 404 );
    }

    metis_runtime_send_json_success( [
        'code' => (string) ( $resolved['code'] ?? $code ),
        'entity_uid' => (string) ( $resolved['code'] ?? $code ),
        'entity_type' => (string) ( $resolved['entity_type'] ?? '' ),
        'label' => (string) ( $resolved['label'] ?? ( $resolved['code'] ?? $code ) ),
        'url' => (string) ( $resolved['resolve_url'] ?? '' ),
        'matches' => array_slice( $matches, 0, $max_results ),
    ] );
} );

metis_ajax_register_handler( 'metis_quick_action_form', function () {
    if ( ! function_exists( 'metis_quick_actions_service' ) ) {
        metis_runtime_send_json_error( 'Quick actions are unavailable.', 404 );
    }

    $nonce = isset( $_POST['nonce'] ) ? metis_text_clean( (string) metis_runtime_unslash( $_POST['nonce'] ) ) : '';
    $actionNonce = isset( $_POST['metis_action_nonce'] ) ? metis_text_clean( (string) metis_runtime_unslash( $_POST['metis_action_nonce'] ) ) : '';
    $valid = metis_runtime_verify_nonce( $nonce, 'metis_core' )
        || metis_runtime_verify_nonce( $actionNonce, 'metis_core' )
        || ( function_exists( 'metis_ajax_nonce_action' ) && metis_runtime_verify_nonce( $actionNonce, metis_ajax_nonce_action( 'metis_quick_action_form' ) ) )
        || ( function_exists( 'metis_ajax_nonce_action' ) && metis_runtime_verify_nonce( $nonce, metis_ajax_nonce_action( 'metis_quick_action_form' ) ) );
    if ( ! $valid ) {
        metis_runtime_send_json_error( 'Invalid nonce.', 403 );
    }

    $key = metis_key_clean( (string) ( $_POST['key'] ?? '' ) );
    if ( $key === '' ) {
        metis_runtime_send_json_error( 'Quick action key is required.', 422 );
    }

    $payload = metis_quick_actions_service()->modalPayload( $key );
    if ( $payload === null ) {
        metis_runtime_send_json_error( 'Quick action modal is unavailable.', 404 );
    }

    metis_runtime_send_json_success( $payload );
} );

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_core_register_ajax_controllers();
}
