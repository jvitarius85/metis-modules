<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_kernel_root' ) ) {
    function metis_kernel_root(): string {
        return dirname( __DIR__, 4 ) . '/';
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
    }
}

if ( ! function_exists( 'metis_kernel_load_autoloaders' ) ) {
    function metis_kernel_load_autoloaders(): void {
        $composer_autoload = metis_kernel_root() . 'vendor/autoload.php';
        if ( is_file( $composer_autoload ) ) {
            require_once $composer_autoload;
        }

        require_once metis_kernel_root() . 'src/Metis/Core/CoreBootstrap.php';
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
        metis_core_bootstrap( [ 'standalone_bootstrap', 'auth', 'router', 'security_runtime_bridge' ] );
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
    function metis_kernel_handle_public_storage_request(): void {
        $request_path   = metis_kernel_request_path();
        $script_dir     = rtrim( metis_kernel_public_base_path(), '/' );
        $storage_prefix = ( $script_dir === '' || $script_dir === '/' ? '' : $script_dir ) . '/storage/uploads/';

        if ( ! str_starts_with( $request_path, $storage_prefix ) ) {
            return;
        }

        $relative_path = ltrim( substr( $request_path, strlen( $storage_prefix ) ), '/' );
        $base_storage  = realpath( METIS_PATH . 'storage/uploads' );
        $target_path   = realpath( METIS_PATH . 'storage/uploads/' . $relative_path );

        if (
            is_string( $base_storage )
            && is_string( $target_path )
            && str_starts_with( $target_path, $base_storage )
            && is_file( $target_path )
            && is_readable( $target_path )
        ) {
            $mime = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $target_path ) : '';
            if ( $mime === '' ) {
                $mime = 'application/octet-stream';
            }

            header( 'Content-Type: ' . $mime );
            header( 'Content-Length: ' . (string) filesize( $target_path ) );
            header( 'Cache-Control: public, max-age=31536000, immutable' );
            readfile( $target_path );
            exit;
        }

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

        if ( ! is_file( $install_lock ) && $request_path !== $install_path ) {
            header( 'Location: ' . $install_path, true, 302 );
            exit;
        }
    }
}
