<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! class_exists( 'Metis' ) ) {
    require_once dirname( __DIR__ ) . '/Bootstrap.php';
    metis_core_bootstrap( 'service_registry' );
}

final class Metis_Runtime_Security_Logger implements Metis_Security_Audit_Logger_Interface {
    public function audit( string $event, array $context = [] ): void {
        if ( $this->isRoutineApprovalEvent( $event ) && ! $this->verboseOperationalAuditEnabled() ) {
            return;
        }

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

    private function isRoutineApprovalEvent( string $event ): bool {
        $normalized = \function_exists( 'metis_key_clean' ) ? \metis_key_clean( $event ) : strtolower( preg_replace( '/[^a-z0-9_]+/i', '', $event ) ?? '' );
        return \in_array( $normalized, [ 'enclaveapproved', 'ajaxactionauthorized' ], true );
    }

    private function verboseOperationalAuditEnabled(): bool {
        if ( ! \class_exists( 'Core_Settings_Service' ) ) {
            return false;
        }

        $value = \Core_Settings_Service::get( 'audit_verbose_operational_events', false );
        if ( \is_bool( $value ) ) {
            return $value;
        }

        return \in_array( \strtolower( \trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
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
        if ( ! metis_user_logged_in() ) {
            return false;
        }

        $current = (string) metis_runtime_session_token();
        if ( $current !== '' ) {
            return hash_equals( $current, $session_id );
        }

        return $session_id !== '';
    }
}

final class Metis_Runtime_Rate_Limiter implements Metis_Security_Rate_Limiter_Interface {
    public function consume( string $bucket, int $limit, int $window_seconds ): bool {
        $window_seconds = max( 1, $window_seconds );
        $window_start   = (int) floor( time() / $window_seconds ) * $window_seconds;
        $transient_key  = 'metis_rl_' . md5( $bucket . '|' . $window_start );
        $count          = (int) metis_get_transient( $transient_key );
        $count++;
        metis_set_transient( $transient_key, $count, $window_seconds );
        return $count <= max( 1, $limit );
    }
}

final class Metis_Runtime_Nonce_Verifier implements Metis_Security_Nonce_Verifier_Interface {
    public function is_valid( string $nonce, string $nonce_key, array $context = [] ): bool {
        return metis_runtime_verify_nonce( $nonce, $nonce_key );
    }
}

final class Metis_Runtime_Database_Gateway implements Metis_Security_Database_Gateway_Interface {
    public function select( string $statement, array $params = [], array $context = [] ): mixed {
        return metis_db()->fetchAll( $statement, $params );
    }

    public function insert( string $target, array $payload, array $context = [] ): mixed {
        return metis_db()->insert( $target, $payload );
    }

    public function update( string $target, array $payload, array $where, array $context = [] ): mixed {
        return metis_db()->update( $target, $payload, $where );
    }

    public function delete( string $target, array $where, array $context = [] ): mixed {
        return metis_db()->delete( $target, $where );
    }

    public function execute( string $statement, array $params = [], array $context = [] ): mixed {
        return metis_db()->executePrepared( $statement, $params );
    }
}

final class Metis_Runtime_File_Gateway implements Metis_Security_File_Gateway_Interface {
    public function read( string $path, array $context = [] ): string {
        $this->assert_path( $path );
        return Metis::service( 'files' )->read( $path );
    }

    public function write( string $path, string $contents, array $context = [] ): void {
        $this->assert_path( $path );
        Metis::service( 'files' )->write( $path, $contents );
    }

    public function delete( string $path, array $context = [] ): void {
        $this->assert_path( $path );
        Metis::service( 'files' )->remove( $path );
    }

    public function exists( string $path, array $context = [] ): bool {
        $this->assert_path( $path );
        return Metis::service( 'files' )->exists( $path );
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
        $callable = sprintf( 'metis_%s_%s', metis_key_clean( $module ), metis_key_clean( $action ) );
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
    $user = metis_runtime_current_user();

    return [
        'actor' => [
            'id'          => metis_user_logged_in() ? (string) metis_current_user_id() : '',
            'roles'       => $user instanceof MetisUser ? array_map( 'strval', (array) $user->roles ) : [],
            'permissions' => [],
            'session_id'  => metis_runtime_session_token(),
        ],
        'meta' => [
            'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
            'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            'request_id' => metis_runtime_generate_uuid(),
            'auth_method' => function_exists( 'metis_auth_current_method' ) ? (string) metis_auth_current_method() : '',
        ],
        'input' => $input,
    ];
}

function metis_security_register_module_policies( string $slug, array $config ): void {
    $slug       = metis_key_clean( $slug );
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

function metis_security_register_ajax_policies(): void {
    if ( ! function_exists( 'metis_ajax_registry' ) ) {
        return;
    }

    $enclave = metis_security_enclave();
    foreach ( metis_ajax_registry()->all() as $ajax_action => $controller ) {
        $action = metis_key_clean( (string) $ajax_action );
        if ( $action === '' || ! str_starts_with( $action, 'metis_' ) ) {
            continue;
        }

        $controller = is_array( $controller ) ? $controller : [];
        $module = metis_key_clean( (string) ( $controller['module'] ?? '' ) );
        if ( $module === '' ) {
            $module = (string) metis_security_infer_module_from_ajax_action( $action );
        }

        if ( $module === '' ) {
            Metis_Logger::warn( 'Skipping AJAX policy registration: module unresolved', [
                'action' => $action,
            ] );
            continue;
        }

        $permission = metis_key_clean( (string) ( $controller['permission'] ?? '' ) );
        if ( $permission === '' ) {
            $permission = metis_security_infer_permission_from_ajax_action( $action );
        }

        $nonce_key = (string) ( $controller['nonce_action'] ?? '' );
        if ( $nonce_key === '' ) {
            $nonce_key = metis_ajax_nonce_action( $action );
        }

        $rate_limit = (int) ( $controller['rate_limit'] ?? 0 );
        if ( $rate_limit < 1 ) {
            $rate_limit = $permission === 'view' ? 180 : 90;
        }

        $rate_window = (int) ( $controller['rate_window_seconds'] ?? 60 );
        if ( $rate_window < 1 ) {
            $rate_window = 60;
        }

        $operation = sprintf( 'ajax.%s.%s', $module, $action );
        if ( $enclave->has_policy( $operation ) ) {
            continue;
        }

        $enclave->register_policy(
            new Metis_Security_Policy(
                $operation,
                $module,
                $permission,
                true,
                true,
                true,
                $nonce_key,
                $rate_limit,
                $rate_window
            )
        );
    }
}

function metis_security_register_route_policies(): void {
    $enclave = metis_security_enclave();

    $register = static function ( Metis_Security_Policy $policy ) use ( $enclave ): void {
        if ( ! $enclave->has_policy( $policy->operation ) ) {
            $enclave->register_policy( $policy );
        }
    };

    $register( new Metis_Security_Policy( 'route.assets_runtime', null, 'view', false, false, false, null, 600, 60 ) );
    $register( new Metis_Security_Policy( 'route.assets_module', null, 'view', false, false, false, null, 600, 60 ) );
    $register( new Metis_Security_Policy( 'route.assets_svg', null, 'view', false, false, false, null, 600, 60 ) );
    $register( new Metis_Security_Policy( 'route.system_version', null, 'view', false, false, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.system_cron', null, 'view', false, false, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.forms_public', null, 'create', false, false, false, null, 120, 60 ) );
    $register( new Metis_Security_Policy( 'route.newsletter_public_signup', null, 'create', false, false, false, null, 60, 60 ) );
    $register( new Metis_Security_Policy( 'route.manage_profile', null, 'view', false, false, false, null, 60, 60 ) );
    $register( new Metis_Security_Policy( 'route.manage_profile', null, 'create', false, false, false, null, 20, 60 ) );
    $register( new Metis_Security_Policy( 'route.manage_access', null, 'view', false, false, false, null, 60, 60 ) );
    $register( new Metis_Security_Policy( 'route.manage_access', null, 'create', false, false, false, null, 30, 60 ) );
    $register( new Metis_Security_Policy( 'route.manage_statement', null, 'view', false, false, false, null, 60, 60 ) );
    $register( new Metis_Security_Policy( 'route.donations_recurring_manage', null, 'view', false, false, false, null, 60, 60 ) );
    $register( new Metis_Security_Policy( 'route.donations_recurring_manage', null, 'create', false, false, false, null, 30, 60 ) );
    $register( new Metis_Security_Policy( 'route.webhook_gateway', null, 'create', false, false, false, null, 180, 60 ) );
    $register( new Metis_Security_Policy( 'route.auth_resolve', null, 'create', false, false, false, null, 180, 60 ) );
    $register( new Metis_Security_Policy( 'route.auth_passkeys_begin', null, 'create', false, false, false, null, 180, 60 ) );
    $register( new Metis_Security_Policy( 'route.auth_passkeys_complete', null, 'create', false, false, false, null, 180, 60 ) );
    $register( new Metis_Security_Policy( 'route.auth_session_keepalive', null, 'view', true, true, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.newsletter_open', null, 'view', false, false, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.newsletter_click', null, 'view', false, false, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.newsletter_unsubscribe', null, 'view', false, false, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.website_theme_css', null, 'view', false, false, false, null, 300, 60 ) );
    $register( new Metis_Security_Policy( 'route.website_homepage', null, 'view', false, false, false, null, 300, 60 ) );
    $register( new Metis_Security_Policy( 'route.website_page', null, 'view', false, false, false, null, 300, 60 ) );

    foreach ( [ 'view', 'edit', 'delete' ] as $permission ) {
        $register( new Metis_Security_Policy( 'route.contacts_carddav.contacts.' . $permission, 'contacts', $permission, true, false, false, null, 300, 60 ) );
    }

    $modules = [ 'portal' => true ];
    if ( function_exists( 'metis_get_modules' ) ) {
        foreach ( (array) metis_get_modules() as $slug => $module ) {
            $slug = metis_key_clean( (string) $slug );
            if ( $slug !== '' ) {
                $modules[ $slug ] = true;
            }
        }
    }

    foreach ( array_keys( $modules ) as $slug ) {
        $register( new Metis_Security_Policy( 'route.portal_page.' . $slug . '.view', $slug, 'view', true, true, false, null, 360, 60 ) );
        $register( new Metis_Security_Policy( 'route.batch_api.' . $slug . '.create', $slug, 'create', true, true, true, null, 120, 60 ) );
    }

    $register( new Metis_Security_Policy( 'route.help_index.help.view', 'help', 'view', true, true, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.help_search.help.view', 'help', 'view', true, true, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.help_article.help.view', 'help', 'view', true, true, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.help_category.help.view', 'help', 'view', true, true, false, null, 240, 60 ) );
    $register( new Metis_Security_Policy( 'route.help_admin_articles.help.manage', 'help', 'manage', true, true, false, null, 180, 60 ) );
    $register( new Metis_Security_Policy( 'route.help_admin_create.help.manage', 'help', 'manage', true, true, false, null, 180, 60 ) );
    $register( new Metis_Security_Policy( 'route.help_admin_edit.help.manage', 'help', 'manage', true, true, false, null, 180, 60 ) );
}

function metis_security_infer_module_from_ajax_action( string $ajax_action ): ?string {
    $action  = preg_replace( '/^metis_/', '', metis_key_clean( $ajax_action ) );
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
    $action = metis_key_clean( preg_replace( '/^metis_/', '', $ajax_action ) );

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

function metis_security_ajax_failure_response(
    string $ajax_action,
    string $module,
    int $status,
    string $message,
    string $error_code,
    string $outcome = 'blocked'
): never {
    $request_id = metis_audit_request_id();
    $endpoint = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/api/ajax' ), PHP_URL_PATH ) ?? '/api/ajax' );
    $action_key = metis_key_clean( $ajax_action );
    $code_key = metis_key_clean( $error_code );
    $status_code = max( 100, $status );

    metis_audit_log_security( 'ajax_action_failed', [
        'module'   => metis_key_clean( $module ),
        'severity' => $status_code >= 500 ? 'error' : 'warning',
        'outcome'  => metis_key_clean( $outcome ) ?: 'blocked',
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
            'error_message' => $message,
            'request_id'    => $request_id,
        ],
    ] );

    header( 'X-Metis-Request-Id: ' . $request_id, true );
    metis_runtime_send_json_error(
        [
            'message' => $message,
            'code'    => $code_key,
        ],
        $status_code
    );
}

function metis_security_enforce_ajax_request(): void {
    if ( ! metis_runtime_doing_ajax() ) {
        return;
    }

    $action_source = metis_request_post()['action'] ?? metis_request_get()['action'] ?? '';
    $ajax_action = is_string( $action_source ) ? metis_key_clean( metis_runtime_unslash( $action_source ) ) : '';
    if ( $ajax_action === '' || strpos( $ajax_action, 'metis_' ) !== 0 ) {
        return;
    }

    $controller = metis_ajax_registry()->get( $ajax_action );
    $module = metis_key_clean( (string) ( is_array( $controller ) ? ( $controller['module'] ?? '' ) : '' ) );
    if ( $module === '' ) {
        $module = (string) metis_security_infer_module_from_ajax_action( $ajax_action );
    }

    if ( $module === '' ) {
        metis_security_ajax_failure_response(
            $ajax_action,
            $module,
            403,
            'Unregistered enclave action.',
            'ajax_module_missing',
            'blocked'
        );
    }

    $permission = metis_key_clean( (string) ( is_array( $controller ) ? ( $controller['permission'] ?? '' ) : '' ) );
    if ( $permission === '' ) {
        $permission = metis_security_infer_permission_from_ajax_action( $ajax_action );
    }
    $operation  = sprintf( 'ajax.%s.%s', $module, $ajax_action );
    $enclave    = metis_security_enclave();
    metis_security_register_ajax_policies();

    if ( ! $enclave->has_policy( $operation ) ) {
        metis_security_ajax_failure_response(
            $ajax_action,
            $module,
            403,
            'Unregistered enclave action.',
            'operation_not_registered',
            'blocked'
        );
    }

    $input = array_merge( metis_runtime_unslash( metis_request_get() ), metis_runtime_unslash( metis_request_post() ) );

    try {
        $enclave->execute(
            $operation,
            metis_security_runtime_request_context( $input ),
            static function ( array $input, array $context, Metis_Security_Gateway_Bundle $gateways ) {
                return true;
            }
        );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        $public_message = 'Security policy blocked this request.';
        if ( (int) $e->status() === 429 ) {
            $public_message = 'Too many requests. Please try again shortly.';
        } elseif ( (int) $e->status() === 401 ) {
            $public_message = 'Authentication required.';
        }
        metis_security_ajax_failure_response(
            $ajax_action,
            $module,
            (int) $e->status(),
            $public_message,
            (string) $e->code_name(),
            'blocked'
        );
    }

    $log_success = true;
    if ( \class_exists( 'Core_Settings_Service', false ) ) {
        $log_success = (bool) \Core_Settings_Service::get( 'audit_log_successful_ajax_authorizations', false );
    }
    if ( $log_success ) {
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
}

function metis_security_authorize_view( string $domain, string $view ): void {
    $slug = metis_key_clean( $domain );
    if ( $slug === '' ) {
        $slug = 'portal';
    }
    $operation = 'module.view.' . $slug;
    $enclave = metis_security_enclave();

    if ( $slug !== '' && ! $enclave->has_policy( $operation ) ) {
        $module = function_exists( 'metis_get_module' ) ? metis_get_module( $slug ) : null;
        $config = is_array( $module ) && isset( $module['config'] ) && is_array( $module['config'] )
            ? (array) $module['config']
            : [];

        if ( $config !== [] ) {
            metis_security_register_module_policies( $slug, $config );
        }

        if ( ! $enclave->has_policy( $operation ) ) {
            $nonce_key = ! empty( $config['assets']['nonce_action'] )
                ? (string) $config['assets']['nonce_action']
                : 'metis_' . $slug;

            $enclave->register_policy(
                new Metis_Security_Policy(
                    $operation,
                    $slug,
                    'view',
                    true,
                    true,
                    false,
                    $nonce_key
                )
            );
        }
    }

    $enclave->execute(
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
metis_on( 'metis_admin_init', 'metis_security_enforce_ajax_request', 0 );
