<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_kernel_root' ) ) {
    function metis_kernel_root(): string {
        $dir = __DIR__;
        for ( $i = 0; $i < 8; $i++ ) {
            $candidate = rtrim( $dir, '/\\' ) . '/';
            if ( is_file( $candidate . 'composer.json' ) || is_dir( $candidate . '.git' ) ) {
                return $candidate;
            }

            $parent = dirname( $dir );
            if ( $parent === $dir ) {
                break;
            }
            $dir = $parent;
        }

        return dirname( __DIR__, 4 ) . '/';
    }
}

if ( ! function_exists( 'metis_kernel_system_path' ) ) {
    function metis_kernel_system_path( string $path = '' ): string {
        return metis_kernel_root() . 'system/' . ltrim( $path, '/\\' );
    }
}

if ( ! function_exists( 'metis_kernel_define_constants' ) ) {
    function metis_kernel_define_constants(): void {
        if ( ! defined( 'METIS_STANDALONE' ) ) {
            define( 'METIS_STANDALONE', true );
        }

        if ( ! defined( 'METIS_PREFIX' ) ) {
            define( 'METIS_PREFIX', 'metis' );
        }

        if ( ! defined( 'METIS_ROOT' ) ) {
            define( 'METIS_ROOT', metis_kernel_root() );
        }

        if ( ! defined( 'METIS_PATH' ) ) {
            define( 'METIS_PATH', metis_kernel_root() );
        }

        $system_path = metis_kernel_system_path();
        $paths = [
            'METIS_SYSTEM_PATH'     => $system_path,
            'METIS_SRC_PATH'        => $system_path . 'src/',
            'METIS_ASSETS_PATH'     => $system_path . 'assets/',
            'METIS_CONFIG_PATH'     => $system_path . 'config/',
            'METIS_MODULES_PATH'    => $system_path . 'modules/',
            'METIS_DOCS_PATH'       => $system_path . 'docs/',
            'METIS_TOOLS_PATH'      => $system_path . 'tools/',
            'METIS_TESTS_PATH'      => $system_path . 'tests/',
            'METIS_CLOUDFLARE_PATH' => $system_path . 'cloudflare/',
            'METIS_VENDOR_PATH'     => $system_path . 'vendor/',
        ];
        foreach ( $paths as $constant => $value ) {
            if ( ! defined( $constant ) ) {
                define( $constant, $value );
            }
        }

        if ( ! defined( 'FORCE_SSL_ADMIN' ) ) {
            $force_ssl_admin_env = getenv( 'METIS_FORCE_SSL_ADMIN' );
            $force_ssl_admin = true;
            if ( $force_ssl_admin_env !== false && trim( (string) $force_ssl_admin_env ) !== '' ) {
                $parsed = filter_var( $force_ssl_admin_env, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                $force_ssl_admin = $parsed !== null ? $parsed : true;
            }
            define( 'FORCE_SSL_ADMIN', $force_ssl_admin );
        }
    }
}

if ( ! function_exists( 'metis_kernel_load_autoloaders' ) ) {
    function metis_kernel_load_autoloaders(): void {
        $composer_autoload = metis_kernel_system_path( 'vendor/autoload.php' );
        if ( is_file( $composer_autoload ) ) {
            require_once $composer_autoload;
        }

        require_once metis_kernel_system_path( 'src/Metis/Core/CoreBootstrap.php' );
    }
}

if ( ! function_exists( 'metis_kernel_bootstrap' ) ) {
    function metis_kernel_bootstrap( string $entry = 'web' ): void {
        static $booted = false;

        if ( $booted ) {
            return;
        }

        metis_kernel_define_constants();
        metis_kernel_load_autoloaders();

        metis_define_system_version( metis_kernel_root() );
        metis_core_bootstrap( [ 'standalone_bootstrap', 'communications_inbound_runtime', 'auth', 'ajax', 'router', 'security_runtime_bridge', 'release' ] );
        metis_register_core_services();
        metis_error_kernel()->install();

        if ( ! defined( 'METIS_URL' ) ) {
            $base_path = metis_kernel_public_base_path();
            define( 'METIS_URL', metis_trailingslashit( metis_runtime_base_url( $base_path === '/' ? '' : $base_path ) ) );
        }

        $booted = true;
    }
}

if ( ! function_exists( 'metis_kernel_public_base_path' ) ) {
    function metis_kernel_public_base_path(): string {
        $base_path = rtrim( dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '/index.php' ) ), '/' );

        if ( $base_path === '' ) {
            return '/';
        }

        if ( $base_path === '/system' ) {
            return '/';
        }

        if ( str_ends_with( $base_path, '/system' ) ) {
            $base_path = substr( $base_path, 0, -7 );
            return $base_path === '' ? '/' : $base_path;
        }

        return $base_path;
    }
}

if ( ! function_exists( 'metis_kernel_request_path' ) ) {
    function metis_kernel_request_path(): string {
        $path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
        return is_string( $path ) && $path !== '' ? $path : '/';
    }
}

if ( ! function_exists( 'metis_kernel_handle_public_storage_request' ) ) {
    function metis_kernel_media_storage_roots( bool $include_legacy = true ): array {
        $roots = [
            'public' => METIS_PATH . 'storage/public-media',
            'protected' => METIS_PATH . 'storage/protected-media',
            'private' => METIS_PATH . 'storage/private-records',
        ];

        if ( $include_legacy ) {
            $roots['legacy_uploads'] = METIS_PATH . 'storage/uploads';
            $roots['legacy_media'] = METIS_PATH . 'storage/media';
        }

        return $roots;
    }

    function metis_kernel_media_safe_extension( string $path ): bool {
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( $extension === '' ) {
            return false;
        }

        $blocked = [
            'bak', 'backup', 'conf', 'config', 'env', 'gz', 'ini', 'log', 'old', 'phar', 'php', 'phtml',
            'sql', 'sqlite', 'sqlite3', 'tar', 'tmp', 'yaml', 'yml', 'zip',
        ];

        return ! in_array( $extension, $blocked, true );
    }

    function metis_kernel_media_mime_for_path( string $path ): string {
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $mime = finfo_file( $finfo, $path );
                finfo_close( $finfo );
                if ( is_string( $mime ) && $mime !== '' ) {
                    return strtolower( $mime );
                }
            }
        }

        $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : '';
        return is_string( $mime ) && $mime !== '' ? strtolower( $mime ) : 'application/octet-stream';
    }

    function metis_kernel_media_is_public_raw( string $base_storage, string $target_path, string $mime ): bool {
        $base_storage = rtrim( str_replace( '\\', '/', $base_storage ), '/' ) . '/';
        $target_path = str_replace( '\\', '/', $target_path );
        if ( ! str_starts_with( $target_path, $base_storage ) || is_link( $target_path ) || ! metis_kernel_media_safe_extension( $target_path ) ) {
            return false;
        }

        $relative = ltrim( substr( $target_path, strlen( $base_storage ) ), '/' );
        if ( $relative === '' || str_starts_with( $relative, '.' ) || preg_match( '#(^|/)(private|protected|documents|docs|files|backups|logs|tmp|temp|runtime|config|database)(/|$)#i', $relative ) === 1 ) {
            return false;
        }

        return str_starts_with( $mime, 'image/' ) || str_starts_with( $mime, 'video/' ) || str_starts_with( $mime, 'audio/' );
    }

    function metis_kernel_handle_public_storage_request(): void {
        $request_path   = metis_kernel_request_path();
        $script_dir     = rtrim( metis_kernel_public_base_path(), '/' );
        $prefix = ( $script_dir === '' || $script_dir === '/' ? '' : $script_dir );
        $blocked_storage_prefix = $prefix . '/storage/';
        $media_prefix = $prefix . '/media/';
        $resolve_public_storage = static function ( string $relative_path, string $storage_class = 'legacy' ): ?array {
            $relative_path = ltrim( $relative_path, '/' );
            if ( $relative_path === '' || str_contains( $relative_path, '..' ) || str_contains( $relative_path, "\0" ) ) {
                return null;
            }

            $all_roots = metis_kernel_media_storage_roots( true );
            $roots = match ( $storage_class ) {
                'public' => [ 'public' => $all_roots['public'] ],
                'protected' => [ 'protected' => $all_roots['protected'] ],
                'private' => [ 'private' => $all_roots['private'] ],
                'legacy' => [
                    'legacy_uploads' => $all_roots['legacy_uploads'],
                    'legacy_media' => $all_roots['legacy_media'],
                ],
                default => [],
            };
            foreach ( $roots as $class => $root ) {
                $base_storage = realpath( $root );
                if ( ! is_string( $base_storage ) ) {
                    continue;
                }

                $target_path = realpath( $base_storage . '/' . $relative_path );
                $normalized_base = rtrim( str_replace( '\\', '/', $base_storage ), '/' ) . '/';
                $normalized_target = is_string( $target_path ) ? str_replace( '\\', '/', $target_path ) : '';
                if (
                    is_string( $target_path )
                    && str_starts_with( $normalized_target, $normalized_base )
                    && is_file( $target_path )
                    && is_readable( $target_path )
                    && ! is_link( $target_path )
                    && metis_kernel_media_safe_extension( $target_path )
                ) {
                    $mime = metis_kernel_media_mime_for_path( $target_path );
                    if ( $storage_class === 'public' && ! metis_kernel_media_is_public_raw( $base_storage, $target_path, $mime ) ) {
                        continue;
                    }

                    return [
                        'base' => $base_storage,
                        'storage_class' => $class,
                        'path' => $target_path,
                        'mime' => $mime,
                    ];
                }
            }

            return null;
        };

        if ( str_starts_with( $request_path, $blocked_storage_prefix ) ) {
            http_response_code( 404 );
            exit;
        }

        if ( ! str_starts_with( $request_path, $media_prefix ) ) {
            return;
        }

        $media_path = trim( substr( $request_path, strlen( $media_prefix ) ), '/' );
        if ( str_starts_with( $media_path, 'raw/' ) ) {
            $relative_path = ltrim( substr( $media_path, 4 ), '/' );
            $resolved = $resolve_public_storage( $relative_path, 'public' );
            if ( ! is_array( $resolved ) ) {
                http_response_code( 404 );
                exit;
            }

            $target_path = (string) $resolved['path'];
            $mime = (string) ( $resolved['mime'] ?? metis_kernel_media_mime_for_path( $target_path ) );

            $is_svg = strtolower( trim( $mime ) ) === 'image/svg+xml';
            header( 'Content-Type: ' . $mime );
            header( 'Content-Length: ' . (string) filesize( $target_path ) );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Content-Disposition: ' . ( $is_svg ? 'attachment' : 'inline' ) . '; filename="' . str_replace( '"', '', basename( $target_path ) ) . '"' );
            header( 'Cache-Control: public, max-age=31536000, immutable' );
            readfile( $target_path );
            exit;
        }

        $token = $media_path;
        if ( $token === '' || ! preg_match( '/^[a-f0-9]{24,64}$/', $token ) ) {
            http_response_code( 404 );
            exit;
        }

        if ( ! function_exists( 'metis_media_find_by_token' ) ) {
            http_response_code( 404 );
            exit;
        }

        // Public token lookups require DB-backed token resolution.
        // This request path runs before the normal kernel dispatch boot sequence,
        // so ensure standalone boot has initialized the runtime connection.
        $db_available = function_exists( 'metis_resolve_db_service' ) && metis_resolve_db_service()->isAvailable();
        if ( ! $db_available && function_exists( 'metis_standalone_boot' ) ) {
            metis_standalone_boot();
        }

        $media = metis_media_find_by_token( $token );
        if ( ! is_array( $media ) ) {
            http_response_code( 404 );
            exit;
        }

        $relative_path = ltrim( (string) ( $media['storage_path'] ?? '' ), '/' );
        $storage_class = metis_key_clean( (string) ( $media['storage_class'] ?? 'legacy' ) );
        if ( ! in_array( $storage_class, [ 'public', 'protected', 'private', 'legacy' ], true ) ) {
            http_response_code( 404 );
            exit;
        }

        if ( in_array( $storage_class, [ 'protected', 'private' ], true ) ) {
            $expires_at = trim( (string) ( $media['access_expires_at'] ?? '' ) );
            if ( $expires_at === '' || strtotime( $expires_at . ' UTC' ) < time() ) {
                if ( function_exists( 'metis_audit_log_security' ) ) {
                    metis_audit_log_security( 'media_token_expired', [
                        'module' => 'media',
                        'resource' => [ 'type' => 'media', 'id' => $token ],
                        'severity' => 'warning',
                        'outcome' => 'blocked',
                    ] );
                }
                http_response_code( 404 );
                exit;
            }

            if ( ! function_exists( 'metis_user_logged_in' ) || ! metis_user_logged_in() || ! function_exists( 'metis_security_user_can' ) || ! metis_security_user_can( 'media.view' ) ) {
                if ( function_exists( 'metis_audit_log_security' ) ) {
                    metis_audit_log_security( 'media_access_denied', [
                        'module' => 'media',
                        'resource' => [ 'type' => 'media', 'id' => $token ],
                        'severity' => 'warning',
                        'outcome' => 'blocked',
                    ] );
                }
                http_response_code( 404 );
                exit;
            }
        }

        $resolved = $resolve_public_storage( $relative_path, $storage_class );
        if ( ! is_array( $resolved ) ) {
            http_response_code( 404 );
            exit;
        }

        if ( in_array( $storage_class, [ 'protected', 'private' ], true ) && function_exists( 'metis_audit_log_activity' ) ) {
            metis_audit_log_activity( 'media_access_granted', [
                'module' => 'media',
                'resource' => [ 'type' => 'media', 'id' => $token, 'label' => (string) ( $media['file_name'] ?? '' ) ],
                'context' => [ 'storage_class' => $storage_class ],
            ] );
        }

        $target_path = (string) $resolved['path'];
        $mime = strtolower( trim( (string) ( $media['mime_type'] ?? '' ) ) );
        $detected_mime = (string) ( $resolved['mime'] ?? metis_kernel_media_mime_for_path( $target_path ) );
        if ( $mime === '' || $mime === 'application/octet-stream' ) {
            $mime = $detected_mime;
        }

        $file_name = metis_filename_clean( (string) ( $media['file_name'] ?? basename( $target_path ) ) );
        $is_svg = strtolower( trim( $mime ) ) === 'image/svg+xml';
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . (string) filesize( $target_path ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: ' . ( $is_svg ? 'attachment' : 'inline' ) . '; filename="' . str_replace( '"', '', $file_name ) . '"' );
        header( 'Cache-Control: ' . ( in_array( $storage_class, [ 'protected', 'private' ], true ) ? 'private, no-store, max-age=0' : 'public, max-age=31536000, immutable' ) );
        readfile( $target_path );
        exit;

        http_response_code( 404 );
        exit;
    }
}

if ( ! function_exists( 'metis_kernel_handle_install_redirect' ) ) {
    function metis_kernel_handle_install_redirect(): void {
        $install_lock  = METIS_PATH . 'storage/install.lock';
        $request_path  = metis_kernel_request_path();
        $script_dir    = rtrim( metis_kernel_public_base_path(), '/' );
        $install_path  = ( $script_dir === '' || $script_dir === '/' ? '' : $script_dir ) . '/install';
        $request_is_install = rtrim( $request_path, '/' ) === rtrim( $install_path, '/' );
        if ( ! is_file( $install_lock ) && ! $request_is_install ) {
            header( 'Location: ' . $install_path, true, 302 );
            exit;
        }
    }
}
