<?php
declare(strict_types=1);

$metis_composer_autoload = dirname( __DIR__, 3 ) . '/vendor/autoload.php';
if ( is_file( $metis_composer_autoload ) ) {
    require_once $metis_composer_autoload;
}

if ( ! function_exists( 'metis_json_encode' ) ) {
    function metis_json_encode( mixed $value, int $flags = 0 ): string|false {
        if ( function_exists( 'metis_runtime_json_encode' ) ) {
            return metis_runtime_json_encode( $value, $flags );
        }

        return json_encode( $value, $flags | JSON_UNESCAPED_SLASHES );
    }
}

if ( ! function_exists( 'metis_home_url' ) ) {
    function metis_home_url( string $path = '' ): string {
        if ( function_exists( 'metis_runtime_home_url' ) ) {
            return metis_runtime_home_url( $path );
        }

        return $path;
    }
}

if ( ! function_exists( 'metis_sanitize_key' ) ) {
    function metis_sanitize_key( string $value ): string {
        if ( function_exists( 'metis_runtime_sanitize_key' ) ) {
            return metis_runtime_sanitize_key( $value );
        }

        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_]/', '', str_replace( '-', '_', $value ) ) ?? '';
    }
}

if ( ! function_exists( 'metis_user_logged_in' ) ) {
    function metis_user_logged_in(): bool {
        return function_exists( 'metis_runtime_user_logged_in' ) ? metis_runtime_user_logged_in() : false;
    }
}

if ( ! function_exists( 'metis_current_user_id' ) ) {
    function metis_current_user_id(): int {
        return function_exists( 'metis_runtime_current_user_id' ) ? metis_runtime_current_user_id() : 0;
    }
}

if ( ! function_exists( 'metis_current_user_can' ) ) {
    function metis_current_user_can( string $capability ): bool {
        return function_exists( 'metis_runtime_current_user_can' )
            ? metis_runtime_current_user_can( $capability )
            : false;
    }
}

if ( ! function_exists( 'metis_is_admin' ) ) {
    function metis_is_admin(): bool {
        return function_exists( 'metis_runtime_is_admin' ) ? metis_runtime_is_admin() : false;
    }
}

if ( ! function_exists( 'metis_generate_password' ) ) {
    function metis_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
        return function_exists( 'metis_runtime_generate_password' )
            ? metis_runtime_generate_password( $length, $special_chars, $extra_special_chars )
            : bin2hex( random_bytes( max( 4, (int) ceil( $length / 2 ) ) ) );
    }
}

if ( ! function_exists( 'metis_rand' ) ) {
    function metis_rand( int $min = 0, int $max = PHP_INT_MAX ): int {
        return random_int( $min, $max );
    }
}

if ( ! function_exists( 'metis_safe_redirect' ) ) {
    function metis_safe_redirect( string $location, int $status = 302 ): never {
        if ( function_exists( 'metis_runtime_redirect' ) ) {
            metis_runtime_redirect( $location, $status );
        }

        http_response_code( $status );
        header( 'Location: ' . $location );
        exit;
    }
}

if ( ! function_exists( 'metis_sanitize_html' ) ) {
    function metis_sanitize_html( string $html ): string {
        return function_exists( 'metis_runtime_kses_post' ) ? metis_runtime_kses_post( $html ) : $html;
    }
}

if ( ! function_exists( 'metis_site_url' ) ) {
    function metis_site_url( string $path = '' ): string {
        if ( function_exists( 'metis_runtime_site_url' ) ) {
            return metis_runtime_site_url( $path );
        }

        return metis_home_url( $path );
    }
}

if ( ! function_exists( 'metis_admin_url' ) ) {
    function metis_admin_url( string $path = '' ): string {
        if ( function_exists( 'metis_runtime_admin_url' ) ) {
            return metis_runtime_admin_url( $path );
        }

        return metis_home_url( $path );
    }
}

if ( ! function_exists( 'metis_get_query_var' ) ) {
    function metis_get_query_var( string $key, mixed $default = '' ): mixed {
        return function_exists( 'metis_runtime_get_query_var' ) ? metis_runtime_get_query_var( $key, $default ) : $default;
    }
}

if ( ! function_exists( 'metis_set_query_var' ) ) {
    function metis_set_query_var( string $key, mixed $value ): void {
        if ( function_exists( 'metis_runtime_set_query_var' ) ) {
            metis_runtime_set_query_var( $key, $value );
        }
    }
}

if ( ! function_exists( 'metis_add_query_arg' ) ) {
    function metis_add_query_arg( string|array $args, mixed $value = null, string $url = '' ): string {
        return function_exists( 'metis_runtime_add_query_arg' )
            ? metis_runtime_add_query_arg( $args, $value, $url )
            : ( is_string( $value ) ? $value : $url );
    }
}

if ( ! function_exists( 'metis_security_user_can' ) ) {
    function metis_security_user_can( string $permission_key ): bool {
        if ( function_exists( 'metis_current_user_can' ) && metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        $permission_key = metis_sanitize_key( $permission_key );
        if ( $permission_key === '' ) {
            return false;
        }

        $parts = explode( '.', $permission_key, 2 );
        $module = metis_sanitize_key( (string) ( $parts[0] ?? '' ) );
        $permission = metis_sanitize_key( (string) ( $parts[1] ?? 'view' ) );
        if ( $permission === '' ) {
            $permission = 'view';
        }

        if ( $module !== '' && function_exists( 'metis_people_can' ) ) {
            return (bool) metis_people_can( $module, $permission );
        }

        if (
            class_exists( '\Metis\Core\Application' )
            && \Metis\Core\Application::has_service( 'permissions' )
        ) {
            return (bool) \Metis\Core\Application::service( 'permissions' )->can( $module, $permission );
        }

        return false;
    }
}

if ( ! function_exists( 'metis_trailingslashit' ) ) {
    function metis_trailingslashit( string $value ): string {
        return function_exists( 'metis_runtime_trailingslashit' )
            ? metis_runtime_trailingslashit( $value )
            : rtrim( $value, '/' ) . '/';
    }
}

if ( ! function_exists( 'metis_untrailingslashit' ) ) {
    function metis_untrailingslashit( string $value ): string {
        return function_exists( 'metis_runtime_untrailingslashit' )
            ? metis_runtime_untrailingslashit( $value )
            : rtrim( $value, '/' );
    }
}

if ( ! function_exists( 'metis_number_format' ) ) {
    function metis_number_format( float|int|string $number, int $decimals = 0 ): string {
        return function_exists( 'metis_runtime_number_format' )
            ? metis_runtime_number_format( $number, $decimals )
            : number_format( (float) $number, $decimals, '.', ',' );
    }
}

if ( ! function_exists( 'metis_plugin_dir_path' ) ) {
    function metis_plugin_dir_path( string $file ): string {
        return function_exists( 'metis_runtime_plugin_dir_path' )
            ? metis_runtime_plugin_dir_path( $file )
            : metis_trailingslashit( dirname( $file ) );
    }
}

if ( ! function_exists( 'metis_plugin_dir_url' ) ) {
    function metis_plugin_dir_url( string $file ): string {
        return function_exists( 'metis_runtime_plugin_dir_url' )
            ? metis_runtime_plugin_dir_url( $file )
            : metis_trailingslashit( dirname( metis_home_url( basename( dirname( $file ) ) . '/' . basename( $file ) ) ) );
    }
}

if ( ! function_exists( 'metis_get_option' ) ) {
    function metis_get_option( string $key, mixed $default = false ): mixed {
        return function_exists( 'metis_runtime_get_option' ) ? metis_runtime_get_option( $key, $default ) : $default;
    }
}

if ( ! function_exists( 'metis_update_option' ) ) {
    function metis_update_option( string $key, mixed $value, bool $autoload = true ): bool {
        return function_exists( 'metis_runtime_update_option' ) ? metis_runtime_update_option( $key, $value, $autoload ) : false;
    }
}

if ( ! function_exists( 'metis_delete_option' ) ) {
    function metis_delete_option( string $key ): bool {
        return function_exists( 'metis_runtime_delete_option' ) ? metis_runtime_delete_option( $key ) : false;
    }
}

if ( ! function_exists( 'metis_get_transient' ) ) {
    function metis_get_transient( string $key ): mixed {
        if ( function_exists( 'metis_runtime_get_transient' ) ) {
            return metis_runtime_get_transient( $key );
        }

        return false;
    }
}

if ( ! function_exists( 'metis_set_transient' ) ) {
    function metis_set_transient( string $key, mixed $value, int $expiration ): bool {
        if ( function_exists( 'metis_runtime_set_transient' ) ) {
            return metis_runtime_set_transient( $key, $value, $expiration );
        }

        return false;
    }
}

if ( ! function_exists( 'metis_delete_transient' ) ) {
    function metis_delete_transient( string $key ): bool {
        if ( function_exists( 'metis_runtime_delete_transient' ) ) {
            return metis_runtime_delete_transient( $key );
        }

        return false;
    }
}

if ( ! function_exists( 'metis_current_time' ) ) {
    function metis_current_time( string $type = 'mysql' ): string|int {
        if ( function_exists( 'metis_runtime_current_time' ) ) {
            return metis_runtime_current_time( $type );
        }

        if ( $type === 'timestamp' ) {
            return time();
        }

        return gmdate( 'Y-m-d H:i:s' );
    }
}

if ( ! function_exists( 'metis_current_datetime' ) ) {
    function metis_current_datetime(): DateTimeImmutable {
        if ( function_exists( 'metis_runtime_current_datetime' ) ) {
            return metis_runtime_current_datetime();
        }

        return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
    }
}

if ( ! function_exists( 'metis_check_ajax_referer' ) ) {
    function metis_check_ajax_referer( string $action = '-1', string|bool $query_arg = false, bool $stop = true ): bool {
        if ( function_exists( 'metis_runtime_check_ajax_referer' ) ) {
            return metis_runtime_check_ajax_referer( $action, $query_arg, $stop );
        }

        $field = is_string( $query_arg ) && $query_arg !== '' ? $query_arg : '_wpnonce';
        $nonce = isset( $_REQUEST[ $field ] ) ? (string) $_REQUEST[ $field ] : '';
        $valid = function_exists( 'metis_runtime_verify_nonce' )
            ? metis_runtime_verify_nonce( $nonce, $action )
            : $nonce !== '';

        if ( ! $valid && $stop ) {
            http_response_code( 403 );
            exit;
        }

        return $valid;
    }
}

if ( ! function_exists( 'metis_flush_rewrite_rules' ) ) {
    function metis_flush_rewrite_rules( bool $hard = true ): void {
        if ( function_exists( 'metis_runtime_flush_rewrite_rules' ) ) {
            metis_runtime_flush_rewrite_rules( $hard );
        }
    }
}

if ( ! function_exists( 'metis_add_rewrite_tag' ) ) {
    function metis_add_rewrite_tag( string $tag, string $regex ): void {
        if ( function_exists( 'metis_runtime_add_rewrite_tag' ) ) {
            metis_runtime_add_rewrite_tag( $tag, $regex );
        }
    }
}

if ( ! function_exists( 'metis_add_rewrite_rule' ) ) {
    function metis_add_rewrite_rule( string $regex, string $query, string $position = 'bottom' ): void {
        if ( function_exists( 'metis_runtime_add_rewrite_rule' ) ) {
            metis_runtime_add_rewrite_rule( $regex, $query, $position );
        }
    }
}

if ( ! function_exists( 'metis_register_activation_hook' ) ) {
    function metis_register_activation_hook( string $file, callable|string $callback ): void {
        if ( function_exists( 'metis_runtime_register_activation_hook' ) ) {
            metis_runtime_register_activation_hook( $file, $callback );
        }
    }
}

if ( ! function_exists( 'metis_register_deactivation_hook' ) ) {
    function metis_register_deactivation_hook( string $file, callable|string $callback ): void {
        if ( function_exists( 'metis_runtime_register_deactivation_hook' ) ) {
            metis_runtime_register_deactivation_hook( $file, $callback );
        }
    }
}

if ( ! function_exists( 'metis_show_admin_bar' ) ) {
    function metis_show_admin_bar( bool $show ): bool {
        return function_exists( 'metis_runtime_show_admin_bar' )
            ? metis_runtime_show_admin_bar( $show )
            : $show;
    }
}

if ( ! function_exists( 'metis_db_delta' ) ) {
    function metis_db_delta( string $sql ): void {
        if ( function_exists( 'dbDelta' ) ) {
            dbDelta( $sql );
            return;
        }

        if ( function_exists( 'metis_db' ) ) {
            $db = metis_db();
            $statements = array_filter( array_map( 'trim', explode( ';', $sql ) ) );
            foreach ( $statements as $statement ) {
                $db->execute( $statement );
            }
            return;
        }

        $db_connection = $GLOBALS['metis_db_connection'] ?? null;
        if ( ! is_object( $db_connection ) || ! method_exists( $db_connection, 'query' ) ) {
            throw new RuntimeException( 'Metis schema installer is unavailable.' );
        }

        $statements = array_filter( array_map( 'trim', explode( ';', $sql ) ) );
        foreach ( $statements as $statement ) {
            $db_connection->query( $statement );
        }
    }
}

if ( ! function_exists( 'metis_environment_type' ) ) {
    function metis_environment_type(): string {
        foreach ( [ 'METIS_ENV', 'APP_ENV', 'METIS_ENVIRONMENT_TYPE' ] as $key ) {
            $value = trim( (string) getenv( $key ) );
            if ( $value !== '' ) {
                return metis_sanitize_key( $value );
            }
        }

        return 'production';
    }
}

if ( ! function_exists( 'metis_error' ) ) {
    function metis_error( string $code = 'error', string $message = '', mixed $data = null ): object {
        if ( class_exists( 'MetisError' ) ) {
            return new MetisError( $code, $message, $data );
        }

        return (object) [
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ];
    }
}

if ( ! function_exists( 'metis_runtime_trailingslashit' ) ) {
    function metis_runtime_trailingslashit( string $value ): string {
        return rtrim( $value, '/' ) . '/';
    }
}

if ( ! function_exists( 'metis_runtime_untrailingslashit' ) ) {
    function metis_runtime_untrailingslashit( string $value ): string {
        return rtrim( $value, '/' );
    }
}

if ( ! function_exists( 'metis_runtime_json_encode' ) ) {
    function metis_runtime_json_encode( mixed $value, int $flags = 0 ): string|false {
        return json_encode( $value, $flags | JSON_UNESCAPED_SLASHES );
    }
}

if ( ! function_exists( 'metis_core_bootstrap' ) ) {
    /**
     * Load procedural core files through a single dependency-aware bootstrap.
     *
     * This keeps bootstrap code declarative while the PSR-4 autoloader handles namespaced classes.
     *
     * @param string|array<int, string> $components
     */
    function metis_core_bootstrap( string|array $components ): void {
        static $manifest = null;
        static $loaded = [];

        if ( $manifest === null ) {
            $manifest = [
                'autoload' => [
                    'path' => __DIR__ . '/Autoload.php',
                    'requires' => [],
                ],
                'standalone_runtime' => [
                    'path' => __DIR__ . '/Runtime/StandaloneRuntime.php',
                    'requires' => [],
                ],
                'http' => [
                    'path' => __DIR__ . '/Runtime/HttpBootstrap.php',
                    'requires' => [ 'autoload' ],
                ],
                'log' => [
                    'path' => __DIR__ . '/LoggerRuntime.php',
                    'requires' => [ 'standalone_runtime' ],
                ],
                'service_registry' => [
                    'path' => __DIR__ . '/ServiceRegistryRuntime.php',
                    'requires' => [ 'autoload' ],
                ],
                'communications_inbound_runtime' => [
                    'path' => __DIR__ . '/CommunicationsInboundRuntime.php',
                    'requires' => [ 'autoload' ],
                ],
                'router' => [
                    'path' => __DIR__ . '/Routing/RouterRuntime.php',
                    'requires' => [ 'standalone_runtime', 'service_registry', 'auth', 'log' ],
                ],
                'modules' => [
                    'path' => __DIR__ . '/Modules/ModulesRuntime.php',
                    'requires' => [ 'service_registry' ],
                ],
                'security_enclave' => [
                    'path' => __DIR__ . '/Security/SecurityEnclave.php',
                    'requires' => [],
                ],
                'security_runtime_bridge' => [
                    'path' => __DIR__ . '/Security/SecurityRuntimeBridge.php',
                    'requires' => [ 'security_enclave', 'service_registry' ],
                ],
                'auth' => [
                    'path' => __DIR__ . '/Auth/AuthRuntime.php',
                    'requires' => [ 'standalone_runtime' ],
                ],
                'ajax' => [
                    'path' => __DIR__ . '/Ajax/AjaxRuntime.php',
                    'requires' => [ 'standalone_runtime' ],
                ],
                'cron' => [
                    'path' => __DIR__ . '/Cron/CronRuntime.php',
                    'requires' => [ 'standalone_runtime', 'log', 'service_registry' ],
                ],
                'integrity' => [
                    'path' => __DIR__ . '/IntegrityRuntime.php',
                    'requires' => [ 'standalone_runtime', 'log' ],
                ],
                'release' => [
                    'path' => __DIR__ . '/ReleaseRuntime.php',
                    'requires' => [ 'service_registry', 'integrity' ],
                ],
                'standalone_bootstrap' => [
                    'path' => __DIR__ . '/Runtime/StandaloneApplicationBootstrap.php',
                    'requires' => [ 'standalone_runtime', 'http', 'log', 'service_registry' ],
                ],
            ];
        }

        $queue = is_array( $components ) ? $components : [ $components ];

        foreach ( $queue as $component ) {
            $component = (string) $component;
            if ( $component === '' || isset( $loaded[ $component ] ) ) {
                continue;
            }

            if ( ! isset( $manifest[ $component ] ) ) {
                throw new InvalidArgumentException( 'Unknown Metis bootstrap component: ' . $component );
            }

            foreach ( $manifest[ $component ]['requires'] as $dependency ) {
                metis_core_bootstrap( $dependency );
            }

            require_once $manifest[ $component ]['path'];
            $loaded[ $component ] = true;
        }
    }
}

if ( ! function_exists( 'metis_system_version_source' ) ) {
    function metis_system_version_source( ?string $root = null ): string {
        require_once __DIR__ . '/Version.php';
        return \Metis\Core\Version::sourcePath( $root );
    }
}

if ( ! function_exists( 'metis_read_system_version' ) ) {
    function metis_read_system_version( ?string $root = null ): string {
        require_once __DIR__ . '/Version.php';
        return \Metis\Core\Version::current( $root );
    }
}

if ( ! function_exists( 'metis_define_system_version' ) ) {
    function metis_define_system_version( ?string $root = null ): void {
        if ( \defined( 'METIS_VERSION' ) ) {
            return;
        }

        \define( 'METIS_VERSION', metis_read_system_version( $root ) );
    }
}
