<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_kernel_enable_php_error_logging' ) ) {
    function metis_kernel_ensure_log_directory( string $dir ): void {
        if ( $dir === '' ) {
            return;
        }

        if ( ! is_dir( $dir ) ) {
            @mkdir( $dir, 0775, true );
        }

        if ( is_dir( $dir ) ) {
            @chmod( $dir, 0775 );
        }
    }

    function metis_kernel_ensure_log_file( string $path ): void {
        if ( $path === '' ) {
            return;
        }

        metis_kernel_ensure_log_directory( dirname( $path ) );

        if ( ! file_exists( $path ) ) {
            @touch( $path );
        }

        if ( file_exists( $path ) ) {
            @chmod( $path, 0664 );
        }
    }

    function metis_kernel_resolve_unified_log_path( string $root ): string {
        if ( class_exists( 'Metis_Logger' ) ) {
            Metis_Logger::init();
            $path = (string) Metis_Logger::log_path();
            if ( $path !== '' ) {
                return $path;
            }
        }

        $logs_dir = rtrim( $root, '/\\' ) . '/storage/logs';
        metis_kernel_ensure_log_directory( $logs_dir );

        $year = date( 'Y' );
        $month = date( 'm' );
        $year_dir = $logs_dir . '/' . $year;
        metis_kernel_ensure_log_directory( $year_dir );

        $base = $year_dir . '/metis-' . $year . '-' . $month . '.log';
        metis_kernel_ensure_log_file( $base );
        if ( ! file_exists( $base ) ) {
            return $base;
        }

        $max_bytes = 5 * 1024 * 1024;
        clearstatcache( true, $base );
        $size = (int) @filesize( $base );
        if ( $size < $max_bytes ) {
            return $base;
        }

        for ( $part = 2; $part <= 999; $part++ ) {
            $candidate = substr( $base, 0, -4 ) . '-part' . str_pad( (string) $part, 2, '0', STR_PAD_LEFT ) . '.log';
            if ( ! file_exists( $candidate ) ) {
                return $candidate;
            }

            clearstatcache( true, $candidate );
            $candidate_size = (int) @filesize( $candidate );
            if ( $candidate_size < $max_bytes ) {
                return $candidate;
            }
        }

        return $base;
    }

    function metis_kernel_enable_php_error_logging(): void {
        static $enabled = false;

        if ( $enabled ) {
            return;
        }

        $enabled = true;

        $root = dirname( __DIR__, 4 );
        $log_file = metis_kernel_resolve_unified_log_path( $root );

        @ini_set( 'log_errors', '1' );
        @ini_set( 'display_errors', '0' );
        @ini_set( 'error_log', $log_file );

        register_shutdown_function(
            static function () use ( $root ): void {
                $error = error_get_last();
                if ( ! is_array( $error ) ) {
                    return;
                }

                $fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
                if ( ! in_array( (int) ( $error['type'] ?? 0 ), $fatal_types, true ) ) {
                    return;
                }

                $entry = [
                    'timestamp' => gmdate( 'c' ),
                    'type' => (int) ( $error['type'] ?? 0 ),
                    'message' => (string) ( $error['message'] ?? '' ),
                    'file' => (string) ( $error['file'] ?? '' ),
                    'line' => (int) ( $error['line'] ?? 0 ),
                    'request_uri' => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
                    'method' => (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ),
                ];

                $line = sprintf(
                    '[%s] [ERROR] PHP Fatal %s',
                    gmdate( 'Y-m-d H:i:s' ),
                    json_encode( $entry, JSON_UNESCAPED_SLASHES ) ?: print_r( $entry, true )
                );
                $log_file = metis_kernel_resolve_unified_log_path( $root );
                metis_kernel_ensure_log_file( $log_file );
                $written = @file_put_contents( $log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
                if ( $written === false ) {
                    @error_log( '[metis.kernel.write_failed] ' . $line );
                }
            }
        );
    }
}

metis_kernel_enable_php_error_logging();

if ( ! function_exists( 'metis_kernel_parse_ini_bytes' ) ) {
    function metis_kernel_parse_ini_bytes( string $value ): int {
        $value = trim( strtolower( $value ) );
        if ( $value === '' ) {
            return 0;
        }
        if ( $value === '-1' ) {
            return -1;
        }
        if ( ! preg_match( '/^([0-9]+)([kmg])?$/', $value, $matches ) ) {
            return 0;
        }

        $bytes = (int) $matches[1];
        $unit = $matches[2] ?? '';
        return match ( $unit ) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }
}

if ( ! function_exists( 'metis_kernel_enforce_memory_limit_floor' ) ) {
    function metis_kernel_enforce_memory_limit_floor(): void {
        $target = (string) getenv( 'METIS_PHP_MEMORY_LIMIT' );
        if ( trim( $target ) === '' ) {
            $target = '256M';
        }

        $current = (string) ini_get( 'memory_limit' );
        $current_bytes = metis_kernel_parse_ini_bytes( $current );
        $target_bytes = metis_kernel_parse_ini_bytes( $target );
        if ( $target_bytes < 1 ) {
            return;
        }

        if ( $current_bytes > 0 && $current_bytes < $target_bytes ) {
            @ini_set( 'memory_limit', $target );
        }
    }
}

metis_kernel_enforce_memory_limit_floor();

require_once __DIR__ . '/Bootstrap.php';

if ( ! function_exists( 'metis_kernel_force_request_uri' ) ) {
    function metis_kernel_force_request_uri( string $path ): void {
        $path = '/' . ltrim( $path, '/' );
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['PATH_INFO']   = $path;
        $_SERVER['ORIG_PATH_INFO'] = $path;
        $_SERVER['REDIRECT_URL'] = $path;
    }
}

if ( ! function_exists( 'metis_kernel_prepare_entry' ) ) {
    function metis_kernel_prepare_entry( string $entry, array $attributes = [] ): array {
        switch ( $entry ) {
            case 'ajax':
                if ( function_exists( 'metis_ajax_endpoint_path' ) ) {
                    metis_kernel_force_request_uri( metis_ajax_endpoint_path() );
                }
                break;

            case 'cron':
                if ( class_exists( 'Metis_Cron_Manager' ) ) {
                    metis_kernel_force_request_uri( Metis_Cron_Manager::endpoint_path() );
                }
                break;

            case 'webhook':
                $provider = metis_key_clean( (string) ( $attributes['provider'] ?? $_GET['provider'] ?? '' ) );
                if ( $provider !== '' && function_exists( 'metis_webhook_base_path' ) ) {
                    metis_kernel_force_request_uri( '/' . trim( metis_webhook_base_path(), '/' ) . '/' . $provider );
                    $attributes['provider'] = $provider;
                }
                break;
        }

        return $attributes;
    }
}

if ( ! function_exists( 'metis_kernel_route_request' ) ) {
    function metis_kernel_route_request( string $entry, Metis_Http_Request $request, array $attributes = [] ): void {
        if ( Metis::service( 'auth' )->handle_request( $request ) ) {
            return;
        }

        if ( $entry === 'webhook' ) {
            metis_router_emit_response(
                Metis::service( 'router' )->dispatch(
                    $request
                        ->with_attribute( 'transport', 'webhook' )
                        ->with_attribute( 'provider', metis_key_clean( (string) ( $attributes['provider'] ?? '' ) ) )
                )
            );
            return;
        }

        if ( $entry === 'web' ) {
            if ( function_exists( 'metis_contacts_carddav_is_request' ) && metis_contacts_carddav_is_request( $request ) ) {
                metis_router_emit_response( Metis::service( 'router' )->dispatch( $request ) );
                return;
            }

            if ( Metis_Cron_Manager::matches_request( $request ) ) {
                metis_router_emit_response( Metis::service( 'router' )->dispatch( $request ) );
                return;
            }

            if ( function_exists( 'metis_module_asset_match_request' ) && metis_module_asset_match_request( $request ) ) {
                metis_router_emit_response( Metis::service( 'router' )->dispatch( $request ) );
                return;
            }

            if ( metis_ajax_request_matches( $request ) ) {
                metis_router_emit_response( Metis::service( 'router' )->dispatch( $request ) );
                return;
            }

            if ( metis_is_webhook_request() ) {
                metis_router_emit_response(
                    Metis::service( 'router' )->dispatch(
                        $request->with_attribute( 'transport', 'webhook' )
                    )
                );
                return;
            }

            $path = rtrim( $request->path(), '/' );
            $slug = function_exists( 'metis_portal_slug' ) ? trim( (string) metis_portal_slug(), '/' ) : 'admin';
            if ( $slug === '' ) {
                $slug = 'admin';
            }

            $admin_base = '/' . $slug;
            $is_admin_request = ( $path === $admin_base ) || str_starts_with( $path, $admin_base . '/' );

            metis_router_emit_response(
                Metis::service( 'router' )->dispatch(
                    $is_admin_request
                        ? $request->with_attribute( 'transport', 'portal' )
                        : $request
                )
            );
            return;
        }

        metis_router_emit_response( Metis::service( 'router' )->dispatch( $request ) );
    }
}

if ( ! function_exists( 'metis_kernel_dispatch' ) ) {
    function metis_kernel_cli_command_catalog(): array {
        $catalog = [
            [ 'command' => 'help', 'description' => 'Show available CLI commands.' ],
            [ 'command' => 'login', 'description' => 'Authenticate and persist a local CLI session.' ],
            [ 'command' => 'logout', 'description' => 'Clear the local CLI session.' ],
            [ 'command' => 'whoami', 'description' => 'Show the authenticated Metis CLI identity.' ],
            [ 'command' => 'auth status', 'description' => 'Show CLI authentication status for the current session.' ],
            [ 'command' => 'route </path>', 'description' => 'Dispatch a Metis route through the runtime kernel.' ],
            [ 'command' => 'cron', 'description' => 'Run the cron endpoint through the runtime kernel.' ],
            [ 'command' => 'webhook <provider>', 'description' => 'Dispatch a webhook request for a provider.' ],
            [ 'command' => 'run <approved command>', 'description' => 'Queue an approved background operation.' ],
        ];

        if ( class_exists( 'Metis' ) && Metis::has_service( 'operations' ) ) {
            foreach ( metis_operations()->commandCatalog() as $operation ) {
                $catalog[] = [
                    'command' => 'run ' . (string) ( $operation['command'] ?? '' ),
                    'description' => (string) ( $operation['description'] ?? '' ),
                ];
            }
        }

        return $catalog;
    }

    function metis_kernel_cli_print_help(): void {
        echo "Metis CLI\n\n";
        echo "Usage:\n";
        echo "  php system/shell.php help\n";
        echo "  php system/shell.php login\n";
        echo "  php system/shell.php logout\n";
        echo "  php system/shell.php whoami\n";
        echo "  php system/shell.php auth status\n";
        echo "  php system/shell.php route </path>\n";
        echo "  php system/shell.php cron\n";
        echo "  php system/shell.php webhook <provider>\n";
        echo "  php system/shell.php run <approved command>\n";
        echo "  php system/shell.php </path>\n\n";

        echo "Available commands:\n";
        foreach ( metis_kernel_cli_command_catalog() as $entry ) {
            printf( "  %-36s %s\n", (string) $entry['command'], (string) $entry['description'] );
        }

        echo "\nCLI auth:\n";
        echo "  CLI auth is enabled by default.\n";
        echo "  Use `php system/shell.php login` to persist a local CLI session.\n";
        echo "  Set METIS_CLI_AUTH_REQUIRED=1 or cli_auth_required=true to require login.\n";
        echo "  Supply credentials via prompts or METIS_CLI_USER, METIS_CLI_PASSWORD, and METIS_CLI_TOTP.\n";
    }

    function metis_kernel_cli_is_interactive(): bool {
        return defined( 'STDIN' ) && function_exists( 'stream_isatty' ) && stream_isatty( STDIN );
    }

    function metis_kernel_cli_prompt( string $label, bool $secret = false ): string {
        if ( ! defined( 'STDIN' ) ) {
            return '';
        }

        echo $label;
        if ( $secret ) {
            echo PHP_EOL;
        }

        $value = fgets( STDIN );
        return is_string( $value ) ? trim( $value ) : '';
    }

    function metis_kernel_cli_parse( array $argv ): array {
        $tokens = array_values( array_slice( $argv, 1 ) );
        if ( $tokens === [] ) {
            return [ 'type' => 'help' ];
        }

        $first = strtolower( trim( (string) $tokens[0] ) );
        $raw   = trim( implode( ' ', array_map( static fn ( mixed $token ): string => trim( (string) $token ), $tokens ) ) );

        return match ( $first ) {
            '', 'help', '--help', '-h', 'list' => [ 'type' => 'help' ],
            'login' => [ 'type' => 'login' ],
            'logout' => [ 'type' => 'logout' ],
            'whoami' => [ 'type' => 'identity' ],
            'auth' => strtolower( trim( (string) ( $tokens[1] ?? '' ) ) ) === 'status'
                ? [ 'type' => 'identity' ]
                : [ 'type' => 'help' ],
            'cron' => [ 'type' => 'cron' ],
            'webhook' => [ 'type' => 'webhook', 'provider' => (string) ( $tokens[1] ?? '' ) ],
            'route' => [ 'type' => 'route', 'path' => (string) ( $tokens[1] ?? '' ) ],
            'run', 'command' => [ 'type' => 'operation', 'command' => trim( implode( ' ', array_slice( $tokens, 1 ) ) ) ],
            default => (
                str_starts_with( $raw, '/' )
                || str_starts_with( $raw, 'api/' )
                || str_starts_with( $raw, 'ajax/' )
                || str_starts_with( $raw, 'webhook/' )
            )
                ? [ 'type' => 'route', 'path' => str_starts_with( $raw, '/' ) ? $raw : '/' . $raw ]
                : [ 'type' => 'operation', 'command' => $raw ],
        };
    }

    function metis_kernel_cli_auth_cache_path(): string {
        return Metis::service( 'files' )->rootPath( 'storage/runtime/cli_auth/session.json' );
    }

    function metis_kernel_cli_auth_cache_ttl(): int {
        return 12 * HOUR_IN_SECONDS;
    }

    function metis_kernel_cli_auth_cache_context(): array {
        return [
            'os_user' => (string) ( getenv( 'USER' ) ?: getenv( 'USERNAME' ) ?: '' ),
            'host' => (string) ( gethostname() ?: '' ),
            'php_sapi' => PHP_SAPI,
        ];
    }

    function metis_kernel_cli_clear_cached_auth(): void {
        $files = Metis::service( 'files' );
        $path = metis_kernel_cli_auth_cache_path();
        if ( $files->exists( $path ) ) {
            $files->remove( $path );
        }
    }

    function metis_kernel_cli_load_cached_auth(): bool {
        $files = Metis::service( 'files' );
        $path = metis_kernel_cli_auth_cache_path();
        if ( ! $files->exists( $path ) ) {
            return false;
        }

        $payload = $files->readJson( $path, [] );
        if ( ! is_array( $payload ) || $payload === [] ) {
            return false;
        }

        $expiresAt = (int) ( $payload['expires_at'] ?? 0 );
        $userId = (int) ( $payload['auth_user_id'] ?? 0 );
        $context = (array) ( $payload['context'] ?? [] );

        if ( $userId < 1 || $expiresAt < time() || $context !== metis_kernel_cli_auth_cache_context() ) {
            metis_kernel_cli_clear_cached_auth();
            return false;
        }

        if ( ! function_exists( 'metis_auth_find_user' ) || ! function_exists( 'metis_auth_finalize_login' ) ) {
            return false;
        }

        $user = metis_auth_find_user( 'id', $userId );
        if ( ! is_array( $user ) || empty( $user['is_active'] ) ) {
            metis_kernel_cli_clear_cached_auth();
            return false;
        }

        $method = metis_key_clean( (string) ( $payload['auth_method'] ?? 'cli_cached' ) );
        metis_auth_finalize_login( $user, $method !== '' ? $method : 'cli_cached' );
        $_SESSION['metis_auth_password_verified_at'] = (int) ( $payload['password_verified_at'] ?? time() );

        return true;
    }

    function metis_kernel_cli_store_cached_auth( array $result ): void {
        $user = is_array( $result['user'] ?? null ) ? (array) $result['user'] : [];
        if ( $user === [] ) {
            return;
        }

        $payload = [
            'auth_user_id' => (int) ( $user['id'] ?? 0 ),
            'person_id' => (int) ( $user['person_id'] ?? 0 ),
            'auth_method' => (string) ( $_SESSION['metis_auth_method'] ?? 'cli_password' ),
            'password_verified_at' => (int) ( $_SESSION['metis_auth_password_verified_at'] ?? time() ),
            'issued_at' => time(),
            'expires_at' => time() + metis_kernel_cli_auth_cache_ttl(),
            'context' => metis_kernel_cli_auth_cache_context(),
        ];

        $files = Metis::service( 'files' );
        $path = metis_kernel_cli_auth_cache_path();
        $files->writeJson( $path, $payload );
        @chmod( $path, 0600 );
    }

    function metis_kernel_cli_authenticate_interactive( bool $persist = false ): array {
        $auth = Metis::service( 'auth' );

        $identifier = trim( (string) ( getenv( 'METIS_CLI_USER' ) ?: '' ) );
        $password   = (string) ( getenv( 'METIS_CLI_PASSWORD' ) ?: '' );
        $totp       = trim( (string) ( getenv( 'METIS_CLI_TOTP' ) ?: '' ) );

        if ( $identifier === '' && metis_kernel_cli_is_interactive() ) {
            $identifier = metis_kernel_cli_prompt( 'Metis user or email: ' );
        }

        if ( $password === '' && metis_kernel_cli_is_interactive() ) {
            $password = metis_kernel_cli_prompt( 'Password: ', true );
        }

        $result = $auth->authenticate_cli( $identifier, $password, $totp !== '' ? $totp : null );
        if ( empty( $result['ok'] ) && ! empty( $result['mfa_required'] ) && $totp === '' && metis_kernel_cli_is_interactive() ) {
            $totp = metis_kernel_cli_prompt( 'Authenticator code: ' );
            $result = $auth->authenticate_cli( $identifier, $password, $totp !== '' ? $totp : null );
        }

        if ( empty( $result['ok'] ) ) {
            throw new RuntimeException( (string) ( $result['message'] ?? 'CLI authentication failed.' ) );
        }

        if ( $persist ) {
            metis_kernel_cli_store_cached_auth( $result );
        }

        return $result;
    }

    function metis_kernel_cli_require_authentication( array $command ): void {
        $type = (string) ( $command['type'] ?? '' );
        if ( in_array( $type, [ 'help', 'login', 'logout' ], true ) ) {
            return;
        }

        $auth = Metis::service( 'auth' );
        if ( ! method_exists( $auth, 'cli_auth_required' ) || ! $auth->cli_auth_required() ) {
            return;
        }

        if ( metis_kernel_cli_load_cached_auth() ) {
            return;
        }

        metis_kernel_cli_authenticate_interactive( true );
    }

    function metis_kernel_cli_run_operation( string $command ): void {
        $command = trim( $command );
        if ( $command === '' ) {
            throw new RuntimeException( 'An approved command is required.' );
        }

        $queued = metis_operations()->queueCommand(
            $command,
            function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0
        );
        if ( empty( $queued['ok'] ) ) {
            throw new RuntimeException( (string) ( $queued['message'] ?? 'The command could not be queued.' ) );
        }

        $status = ! empty( $queued['duplicate'] ) ? 'duplicate' : 'queued';
        $normalized = (string) ( $queued['normalized_command'] ?? $command );

        echo sprintf(
            "[%s] %s (%s)\n",
            strtoupper( $status ),
            $normalized,
            (string) ( $queued['job_code'] ?? 'job pending' )
        );
    }

    function metis_kernel_cli_print_identity(): void {
        $auth = Metis::service( 'auth' );
        $user = $auth->user();
        $cliAuthRequired = method_exists( $auth, 'cli_auth_required' ) ? $auth->cli_auth_required() : false;
        $cached = Metis::service( 'files' )->exists( metis_kernel_cli_auth_cache_path() );

        if ( ! is_array( $user ) ) {
            echo "CLI authentication status\n";
            echo "  Enforced: " . ( $cliAuthRequired ? 'yes' : 'no' ) . "\n";
            echo "  Authenticated: no\n";
            echo "  Cached session: " . ( $cached ? 'present' : 'none' ) . "\n";
            return;
        }

        $roles = array_values( array_filter( array_map( static fn ( mixed $role ): string => trim( (string) $role ), (array) ( $user['roles'] ?? [] ) ) ) );
        $person = function_exists( 'metis_auth_get_person' )
            ? metis_auth_get_person( (int) ( $user['person_id'] ?? 0 ) )
            : null;
        $totpRequired = method_exists( $auth, 'cli_totp_required' ) ? $auth->cli_totp_required( is_array( $person ) ? $person : null ) : false;

        echo "CLI authentication status\n";
        echo "  Enforced: " . ( $cliAuthRequired ? 'yes' : 'no' ) . "\n";
        echo "  Authenticated: yes\n";
        echo "  Cached session: " . ( $cached ? 'present' : 'none' ) . "\n";
        echo "  Auth method: " . (string) ( $_SESSION['metis_auth_method'] ?? '' ) . "\n";
        echo "  User ID: " . (int) ( $user['ID'] ?? 0 ) . "\n";
        echo "  Person ID: " . (int) ( $user['person_id'] ?? 0 ) . "\n";
        echo "  Login: " . (string) ( $user['user_login'] ?? '' ) . "\n";
        echo "  Email: " . (string) ( $user['user_email'] ?? '' ) . "\n";
        echo "  Display name: " . (string) ( $user['display_name'] ?? '' ) . "\n";
        echo "  Roles: " . ( $roles !== [] ? implode( ', ', $roles ) : 'none' ) . "\n";
        echo "  TOTP required: " . ( $totpRequired ? 'yes' : 'no' ) . "\n";
        echo "  Password verified at: " . (int) ( $_SESSION['metis_auth_password_verified_at'] ?? 0 ) . "\n";
    }

    function metis_kernel_cli_login(): void {
        metis_kernel_cli_authenticate_interactive( true );
        echo "CLI login successful.\n";
    }

    function metis_kernel_cli_logout(): void {
        metis_kernel_cli_clear_cached_auth();
        if ( function_exists( 'metis_auth_logout' ) ) {
            metis_auth_logout();
        }
        echo "CLI login cleared.\n";
    }

    function metis_kernel_dispatch( string $entry = 'web', array $attributes = [] ): void {
        $attributes = metis_kernel_prepare_entry( $entry, $attributes );

        if ( $entry === 'cli' ) {
            $argv    = (array) ( $GLOBALS['argv'] ?? [] );
            $command = metis_kernel_cli_parse( $argv );

            if ( (string) ( $command['type'] ?? '' ) === 'help' ) {
                metis_kernel_cli_print_help();
                return;
            }

            metis_kernel_cli_require_authentication( $command );

            switch ( (string) ( $command['type'] ?? '' ) ) {
                case 'login':
                    metis_kernel_cli_login();
                    return;

                case 'logout':
                    metis_kernel_cli_logout();
                    return;

                case 'cron':
                    metis_kernel_dispatch( 'cron', $attributes );
                    return;

                case 'identity':
                    metis_kernel_cli_print_identity();
                    return;

                case 'webhook':
                    metis_kernel_dispatch( 'webhook', [
                        'provider' => (string) ( $command['provider'] ?? '' ),
                    ] );
                    return;

                case 'operation':
                    metis_kernel_cli_run_operation( (string) ( $command['command'] ?? '' ) );
                    return;

                case 'route':
                    $path = trim( (string) ( $command['path'] ?? '' ) );
                    if ( $path === '' ) {
                        throw new RuntimeException( 'A route path is required.' );
                    }

                    metis_kernel_force_request_uri( $path );
                    $normalized = str_starts_with( $path, '/api/' ) ? 'api' : 'web';
                    metis_kernel_dispatch( $normalized, $attributes );
                    return;
            }

            metis_kernel_cli_print_help();
            return;
        }

        $request = metis_router_build_request();
        metis_error_kernel()->captureRequest( $request );
        metis_kernel_route_request( $entry, $request, $attributes );
    }
}

if ( ! function_exists( 'metis_kernel_execute' ) ) {
    function metis_kernel_execute( string $entry = 'web', array $attributes = [] ): void {
        if ( $entry === 'cli' ) {
            $argv = (array) ( $GLOBALS['argv'] ?? [] );
            $command = metis_kernel_cli_parse( $argv );
            if ( (string) ( $command['type'] ?? '' ) === 'help' ) {
                metis_kernel_cli_print_help();
                return;
            }
        }

        metis_kernel_bootstrap( $entry );

        if ( $entry !== 'cli' ) {
            metis_kernel_handle_public_storage_request();
            metis_kernel_handle_install_redirect();
        }

        metis_error_kernel()->execute(
            static function () use ( $entry, $attributes ): void {
                metis_standalone_boot();
                metis_kernel_dispatch( $entry, $attributes );
            }
        );
    }
}
