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

        if ( function_exists( 'home_url' ) ) {
            return home_url( $path );
        }

        return $path;
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
                return function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : $value;
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

if ( ! function_exists( 'metis_runtime_parse_url' ) ) {
    function metis_runtime_parse_url( string $url, int $component = -1 ): mixed {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'metis_runtime_make_dir' ) ) {
    function metis_runtime_make_dir( string $target ): bool {
        return is_dir( $target ) || mkdir( $target, 0775, true );
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
                    'path' => __DIR__ . '/LegacyAutoload.php',
                    'requires' => [],
                ],
                'standalone_runtime' => [
                    'path' => __DIR__ . '/Runtime/LegacyStandaloneRuntime.php',
                    'requires' => [],
                ],
                'http' => [
                    'path' => __DIR__ . '/Runtime/LegacyHttpBootstrap.php',
                    'requires' => [ 'autoload' ],
                ],
                'log' => [
                    'path' => __DIR__ . '/LegacyLoggerRuntime.php',
                    'requires' => [],
                ],
                'service_registry' => [
                    'path' => __DIR__ . '/LegacyServiceRegistry.php',
                    'requires' => [ 'autoload' ],
                ],
                'router' => [
                    'path' => __DIR__ . '/Routing/LegacyRouterRuntime.php',
                    'requires' => [ 'service_registry', 'auth' ],
                ],
                'modules' => [
                    'path' => __DIR__ . '/Modules/LegacyModulesRuntime.php',
                    'requires' => [ 'service_registry' ],
                ],
                'security_enclave' => [
                    'path' => __DIR__ . '/Security/LegacySecurityEnclave.php',
                    'requires' => [],
                ],
                'security_runtime_bridge' => [
                    'path' => __DIR__ . '/Security/LegacySecurityRuntimeBridge.php',
                    'requires' => [ 'security_enclave', 'service_registry' ],
                ],
                'auth' => [
                    'path' => __DIR__ . '/Auth/LegacyAuthRuntime.php',
                    'requires' => [],
                ],
                'ajax' => [
                    'path' => __DIR__ . '/Ajax/LegacyAjaxRuntime.php',
                    'requires' => [],
                ],
                'cron' => [
                    'path' => __DIR__ . '/Cron/LegacyCronRuntime.php',
                    'requires' => [],
                ],
                'integrity' => [
                    'path' => __DIR__ . '/LegacyIntegrityRuntime.php',
                    'requires' => [],
                ],
                'release' => [
                    'path' => __DIR__ . '/LegacyReleaseRuntime.php',
                    'requires' => [ 'service_registry', 'integrity' ],
                ],
                'standalone_bootstrap' => [
                    'path' => __DIR__ . '/Runtime/LegacyStandaloneApplicationBootstrap.php',
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
