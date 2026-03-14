<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Metis' ) ) {
    require_once __DIR__ . '/bootstrap.php';
    metis_core_bootstrap( 'service_registry' );
}

final class Metis_Runtime_Security_Logger implements Metis_Security_Audit_Logger_Interface {
    public function audit( string $event, array $context = [] ): void {
        $meta   = (array) ( $context['meta'] ?? [] );
        $actor  = (array) ( $context['actor'] ?? [] );
        $policy = $context['policy'] ?? null;

        metis_audit_log_activity( $event, [
            'user_id'    => isset( $actor['id'] ) ? (int) $actor['id'] : null,
            'module'     => $policy instanceof Metis_Security_Policy ? (string) ( $policy->module ?? 'security' ) : 'security',
            'request_id' => (string) ( $meta['request_id'] ?? metis_audit_request_id() ),
            'ip_address' => (string) ( $meta['ip'] ?? metis_audit_ip_address() ),
            'user_agent' => (string) ( $meta['user_agent'] ?? metis_audit_user_agent() ),
            'resource'   => [
                'type'  => 'operation',
                'id'    => (string) ( $context['operation'] ?? '' ),
                'label' => $policy instanceof Metis_Security_Policy ? (string) ( $policy->permission ?? '' ) : '',
            ],
            'context'    => $context,
        ] );

        Metis_Logger::info( 'AUDIT ' . $event, $context );
    }

    public function security( string $event, array $context = [] ): void {
        $meta   = (array) ( $context['meta'] ?? [] );
        $actor  = (array) ( $context['actor'] ?? [] );
        $policy = $context['policy'] ?? null;

        metis_audit_log_security( $event, [
            'user_id'    => isset( $actor['id'] ) ? (int) $actor['id'] : null,
            'module'     => $policy instanceof Metis_Security_Policy ? (string) ( $policy->module ?? 'security' ) : 'security',
            'request_id' => (string) ( $meta['request_id'] ?? metis_audit_request_id() ),
            'ip_address' => (string) ( $meta['ip'] ?? metis_audit_ip_address() ),
            'user_agent' => (string) ( $meta['user_agent'] ?? metis_audit_user_agent() ),
            'severity'   => 'warning',
            'outcome'    => 'blocked',
            'resource'   => [
                'type'  => 'operation',
                'id'    => (string) ( $context['operation'] ?? '' ),
                'label' => $policy instanceof Metis_Security_Policy ? (string) ( $policy->permission ?? '' ) : '',
            ],
            'context'    => $context,
        ] );

        Metis_Logger::warn( 'SECURITY ' . $event, $context );
    }
}

final class Metis_Runtime_Session_Store implements Metis_Security_Session_Store_Interface {
    public function is_valid( string $session_id, array $actor, array $context = [] ): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        if ( function_exists( 'wp_get_session_token' ) ) {
            $current = (string) wp_get_session_token();
            return $current !== '' && hash_equals( $current, $session_id );
        }

        return $session_id !== '';
    }
}

final class Metis_Runtime_Rate_Limiter implements Metis_Security_Rate_Limiter_Interface {
    public function consume( string $bucket, int $limit, int $window_seconds ): bool {
        $window_seconds = max( 1, $window_seconds );
        $window_start   = (int) floor( time() / $window_seconds ) * $window_seconds;
        $transient_key  = 'metis_rl_' . md5( $bucket . '|' . $window_start );
        $count          = (int) get_transient( $transient_key );
        $count++;
        set_transient( $transient_key, $count, $window_seconds );
        return $count <= max( 1, $limit );
    }
}

final class Metis_Runtime_Nonce_Verifier implements Metis_Security_Nonce_Verifier_Interface {
    public function is_valid( string $nonce, string $nonce_key, array $context = [] ): bool {
        return (bool) wp_verify_nonce( $nonce, $nonce_key );
    }
}

final class Metis_Runtime_Database_Gateway implements Metis_Security_Database_Gateway_Interface {
    public function select( string $statement, array $params = [], array $context = [] ): mixed {
        global $wpdb;
        $query = ! empty( $params ) ? $wpdb->prepare( $statement, ...$params ) : $statement;
        return $wpdb->get_results( $query, ARRAY_A );
    }

    public function insert( string $target, array $payload, array $context = [] ): mixed {
        global $wpdb;
        return $wpdb->insert( $target, $payload );
    }

    public function update( string $target, array $payload, array $where, array $context = [] ): mixed {
        global $wpdb;
        return $wpdb->update( $target, $payload, $where );
    }

    public function delete( string $target, array $where, array $context = [] ): mixed {
        global $wpdb;
        return $wpdb->delete( $target, $where );
    }

    public function execute( string $statement, array $params = [], array $context = [] ): mixed {
        global $wpdb;
        $query = ! empty( $params ) ? $wpdb->prepare( $statement, ...$params ) : $statement;
        return $wpdb->query( $query );
    }
}

final class Metis_Runtime_File_Gateway implements Metis_Security_File_Gateway_Interface {
    public function read( string $path, array $context = [] ): string {
        $this->assert_path( $path );
        $contents = file_get_contents( $path );
        if ( $contents === false ) {
            throw new Metis_Security_Enclave_Exception( 'Failed to read file.', 'file_read_failed', 500, [ 'path' => $path ] );
        }
        return $contents;
    }

    public function write( string $path, string $contents, array $context = [] ): void {
        $this->assert_path( $path );
        $result = file_put_contents( $path, $contents, LOCK_EX );
        if ( $result === false ) {
            throw new Metis_Security_Enclave_Exception( 'Failed to write file.', 'file_write_failed', 500, [ 'path' => $path ] );
        }
    }

    public function delete( string $path, array $context = [] ): void {
        $this->assert_path( $path );
        if ( file_exists( $path ) && ! unlink( $path ) ) {
            throw new Metis_Security_Enclave_Exception( 'Failed to delete file.', 'file_delete_failed', 500, [ 'path' => $path ] );
        }
    }

    public function exists( string $path, array $context = [] ): bool {
        $this->assert_path( $path );
        return file_exists( $path );
    }

    private function assert_path( string $path ): void {
        $base = realpath( METIS_PATH );
        $real = file_exists( $path ) ? realpath( $path ) : realpath( dirname( $path ) );

        if ( $base === false || $real === false || strpos( $real, $base ) !== 0 ) {
            throw new Metis_Security_Enclave_Exception( 'File path is outside enclave boundary.', 'invalid_file_path', 403, [ 'path' => $path ] );
        }
    }
}

final class Metis_Runtime_Module_Gateway implements Metis_Security_Module_Gateway_Interface {
    public function dispatch( string $module, string $action, array $payload = [], array $context = [] ): mixed {
        $callable = sprintf( 'metis_%s_%s', sanitize_key( $module ), sanitize_key( $action ) );
        if ( ! is_callable( $callable ) ) {
            throw new Metis_Security_Enclave_Exception(
                'Trusted module action not found.',
                'module_action_missing',
                404,
                [ 'module' => $module, 'action' => $action ]
            );
        }

        return call_user_func( $callable, $payload, $context );
    }
}

function metis_security_runtime_bootstrap(): void {
    $permission_resolver = static function ( Metis_Security_Policy $policy, array $actor, array $meta ): bool {
        return Metis::service( 'permissions' )->can( $policy->module, $policy->permission, $actor );
    };

    Metis_Security_Enclave_Container::set(
        new Metis_Security_Enclave(
            new Metis_Runtime_Security_Logger(),
            new Metis_Runtime_Session_Store(),
            new Metis_Runtime_Rate_Limiter(),
            new Metis_Runtime_Nonce_Verifier(),
            new Metis_Security_Gateway_Bundle(
                new Metis_Runtime_Database_Gateway(),
                new Metis_Runtime_File_Gateway(),
                new Metis_Runtime_Module_Gateway()
            ),
            $permission_resolver
        )
    );
}

function metis_security_runtime_request_context( array $input = [] ): array {
    $user = wp_get_current_user();

    return [
        'actor' => [
            'id'          => is_user_logged_in() ? (string) get_current_user_id() : '',
            'roles'       => $user instanceof WP_User ? array_map( 'strval', (array) $user->roles ) : [],
            'permissions' => [],
            'session_id'  => function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '',
        ],
        'meta' => [
            'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
            'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            'request_id' => wp_generate_uuid4(),
        ],
        'input' => $input,
    ];
}

function metis_security_register_module_policies( string $slug, array $config ): void {
    $slug       = sanitize_key( $slug );
    $enclave    = metis_security_enclave();
    $nonce_key  = ! empty( $config['assets']['nonce_action'] ) ? (string) $config['assets']['nonce_action'] : 'metis_' . $slug;
    $view_key   = 'module.view.' . $slug;
    $edit_key   = 'module.edit.' . $slug;
    $create_key = 'module.create.' . $slug;
    $delete_key = 'module.delete.' . $slug;

    foreach ( [
        $view_key   => 'view',
        $edit_key   => 'edit',
        $create_key => 'create',
        $delete_key => 'delete',
    ] as $operation => $permission ) {
        if ( $enclave->has_policy( $operation ) ) {
            continue;
        }

        $enclave->register_policy(
            new Metis_Security_Policy(
                $operation,
                $slug,
                $permission,
                true,
                true,
                $permission !== 'view',
                $nonce_key
            )
        );
    }
}

function metis_security_infer_module_from_ajax_action( string $ajax_action ): ?string {
    $action  = preg_replace( '/^metis_/', '', sanitize_key( $ajax_action ) );
    $modules = function_exists( 'metis_get_modules' ) ? metis_get_modules() : [];
    $aliases = [];

    foreach ( $modules as $slug => $module ) {
        $aliases[ $slug ] = $slug;
        if ( str_ends_with( $slug, 's' ) ) {
            $aliases[ substr( $slug, 0, -1 ) ] = $slug;
        }
    }

    $aliases = array_merge( $aliases, [
        'campaign' => 'donations',
        'campaigns' => 'donations',
        'charge' => 'donations',
        'charges' => 'donations',
        'deposit' => 'donations',
        'deposits' => 'donations',
        'donor' => 'donations',
        'donors' => 'donations',
        'payout' => 'donations',
        'payouts' => 'donations',
        'stripe' => 'donations',
        'transaction' => 'donations',
        'transactions' => 'donations',
        'report' => 'donations',
        'reports' => 'donations',
        'contact' => 'contacts',
        'board' => 'board',
        'calendar' => 'calendar',
        'newsletter' => 'newsletter',
        'profile' => 'profile',
        'people' => 'people',
        'drive' => 'drive',
        'backup' => 'settings',
        'settings' => 'settings',
    ] );

    foreach ( $aliases as $prefix => $module_slug ) {
        if ( $action === $prefix || strpos( $action, $prefix . '_' ) === 0 ) {
            return $module_slug;
        }
    }

    $tokens = array_values( array_filter( explode( '_', $action ), 'strlen' ) );
    foreach ( $tokens as $token ) {
        if ( isset( $aliases[ $token ] ) ) {
            return $aliases[ $token ];
        }
    }

    return null;
}

function metis_security_infer_permission_from_ajax_action( string $ajax_action ): string {
    $action = sanitize_key( preg_replace( '/^metis_/', '', $ajax_action ) );

    foreach ( [ 'get_', 'list_', 'search_', 'status', 'resolve_', 'inspect_' ] as $prefix ) {
        if ( strpos( $action, $prefix ) === 0 || str_contains( $action, '_' . rtrim( $prefix, '_' ) ) ) {
            return 'view';
        }
    }

    foreach ( [ 'delete', 'remove', 'revoke', 'trash', 'archive', 'offboard' ] as $verb ) {
        if ( str_contains( $action, $verb ) ) {
            return 'delete';
        }
    }

    foreach ( [ 'create', 'upload', 'begin', 'generate', 'add' ] as $verb ) {
        if ( str_contains( $action, $verb ) ) {
            return 'create';
        }
    }

    return 'edit';
}

function metis_security_enforce_ajax_request(): void {
    if ( ! wp_doing_ajax() ) {
        return;
    }

    $ajax_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
    if ( $ajax_action === '' || strpos( $ajax_action, 'metis_' ) !== 0 ) {
        return;
    }

    $module = metis_security_infer_module_from_ajax_action( $ajax_action );
    if ( $module === null ) {
        wp_send_json_error( [ 'message' => 'Unregistered enclave action.' ], 403 );
    }

    $permission = metis_security_infer_permission_from_ajax_action( $ajax_action );
    $operation  = sprintf( 'ajax.%s.%s', $module, $ajax_action );
    $nonce_key  = 'metis_' . $module;
    $enclave    = metis_security_enclave();

    if ( ! $enclave->has_policy( $operation ) ) {
        $enclave->register_policy(
            new Metis_Security_Policy(
                $operation,
                $module,
                $permission,
                true,
                true,
                true,
                $nonce_key,
                $permission === 'view' ? 180 : 90,
                60
            )
        );
    }

    $input = array_merge( wp_unslash( $_GET ), wp_unslash( $_POST ) );

    try {
        $enclave->handle(
            $operation,
            metis_security_runtime_request_context( $input ),
            static function ( array $input, array $context, Metis_Security_Gateway_Bundle $gateways ) {
                return true;
            }
        );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        wp_send_json_error(
            [
                'message' => $e->getMessage(),
                'code'    => $e->code_name(),
            ],
            $e->status()
        );
    }

    metis_audit_log_activity( 'ajax_action_authorized', [
        'module'   => $module,
        'resource' => [
            'type'  => 'ajax_action',
            'id'    => $ajax_action,
            'label' => $permission,
        ],
        'context'  => [
            'operation'  => $operation,
            'permission' => $permission,
        ],
    ] );
}

function metis_security_authorize_view( string $domain, string $view ): void {
    $operation = 'module.view.' . sanitize_key( $domain );

    metis_security_enclave()->handle(
        $operation,
        metis_security_runtime_request_context( [
            'domain' => $domain,
            'view'   => $view,
            'nonce'  => '',
        ] ),
        static function ( array $input, array $context, Metis_Security_Gateway_Bundle $gateways ) {
            return true;
        }
    );
}

function metis_security_trusted_include( string $path, bool $once = true ): void {
    $real = realpath( $path );
    $base = realpath( METIS_PATH );

    if ( $real === false || $base === false || strpos( $real, $base ) !== 0 ) {
        throw new Metis_Security_Enclave_Exception( 'Trusted include blocked.', 'trusted_include_blocked', 403, [ 'path' => $path ] );
    }

    if ( $once ) {
        require_once $real;
        return;
    }

    include $real;
}

if ( function_exists( 'metis_register_core_services' ) ) {
    metis_register_core_services();
}

metis_security_runtime_bootstrap();
add_action( 'admin_init', 'metis_security_enforce_ajax_request', 0 );
