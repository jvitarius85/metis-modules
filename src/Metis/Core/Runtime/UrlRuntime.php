<?php
declare(strict_types=1);

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

function metis_runtime_config_base_url(): string {
    $base_url = metis_runtime_config_get( 'base_url', '' );
    if ( is_array( $base_url ) ) {
        $base_url = reset( $base_url );
    }

    $base_url = trim( (string) $base_url );
    if ( $base_url === '' ) {
        return '';
    }

    $parsed = parse_url( $base_url );
    if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
        return '';
    }

    $scheme = strtolower( (string) $parsed['scheme'] );
    if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
        return '';
    }

    $host = strtolower( (string) $parsed['host'] );
    $path = isset( $parsed['path'] ) ? '/' . trim( (string) $parsed['path'], '/' ) : '';
    if ( $path === '/' ) {
        $path = '';
    }

    $authority = $host;
    if ( isset( $parsed['port'] ) && is_int( $parsed['port'] ) ) {
        $authority .= ':' . $parsed['port'];
    }

    return $scheme . '://' . $authority . $path;
}

function metis_runtime_forwarded_value( string $header ): string {
    $value = trim( (string) ( $_SERVER[ $header ] ?? '' ) );
    if ( $value === '' ) {
        return '';
    }

    $parts = explode( ',', $value );
    return trim( (string) reset( $parts ) );
}

function metis_runtime_forwarded_proto(): string {
    $forwarded = metis_runtime_forwarded_value( 'HTTP_X_FORWARDED_PROTO' );
    if ( $forwarded !== '' ) {
        $proto = strtolower( trim( explode( ',', $forwarded )[0] ?? '' ) );
        if ( in_array( $proto, [ 'http', 'https' ], true ) ) {
            return $proto;
        }
    }

    $forwarded_header = trim( (string) ( $_SERVER['HTTP_FORWARDED'] ?? '' ) );
    if ( $forwarded_header !== '' && preg_match( '/proto=([^;,\s]+)/i', $forwarded_header, $matches ) ) {
        $proto = strtolower( trim( $matches[1], "\"'" ) );
        if ( in_array( $proto, [ 'http', 'https' ], true ) ) {
            return $proto;
        }
    }

    if ( strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '' ) ) === 'on' ) {
        return 'https';
    }

    if ( strtolower( (string) ( $_SERVER['HTTP_FRONT_END_HTTPS'] ?? '' ) ) === 'on' ) {
        return 'https';
    }

    $request_scheme = strtolower( (string) ( $_SERVER['REQUEST_SCHEME'] ?? '' ) );
    if ( in_array( $request_scheme, [ 'http', 'https' ], true ) ) {
        return $request_scheme;
    }

    return ( ! empty( $_SERVER['HTTPS'] ) && strtolower( (string) $_SERVER['HTTPS'] ) !== 'off' ) ? 'https' : 'http';
}

function metis_runtime_normalize_host( string $host ): string {
    $host = trim( $host );
    if ( $host === '' ) {
        return '';
    }

    if ( str_contains( $host, '/' ) || str_contains( $host, '\\' ) || preg_match( '/[\r\n\t ]/', $host ) ) {
        return '';
    }

    return $host;
}

function metis_runtime_host(): string {
    $configured = metis_runtime_config_base_url();
    if ( $configured !== '' ) {
        return (string) parse_url( $configured, PHP_URL_HOST ) . ( parse_url( $configured, PHP_URL_PORT ) ? ':' . parse_url( $configured, PHP_URL_PORT ) : '' );
    }

    $forwarded_host = metis_runtime_normalize_host( metis_runtime_forwarded_value( 'HTTP_X_FORWARDED_HOST' ) );
    if ( $forwarded_host !== '' ) {
        return $forwarded_host;
    }

    $host = metis_runtime_normalize_host( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
    if ( $host !== '' ) {
        return $host;
    }

    $server_name = metis_runtime_normalize_host( (string) ( $_SERVER['SERVER_NAME'] ?? '' ) );
    if ( $server_name !== '' ) {
        $port = (string) ( $_SERVER['SERVER_PORT'] ?? '' );
        if ( $port !== '' && ! in_array( $port, [ '80', '443' ], true ) ) {
            return $server_name . ':' . $port;
        }

        return $server_name;
    }

    return 'localhost';
}

function metis_runtime_base_path( string $fallback = '' ): string {
    $configured = metis_runtime_config_base_url();
    if ( $configured !== '' ) {
        $path = (string) parse_url( $configured, PHP_URL_PATH );
        return $path === '/' ? '' : rtrim( $path, '/' );
    }

    $base = trim( (string) metis_runtime_config_get( 'base_path', '' ) );
    if ( $base !== '' ) {
        return $base === '/' ? '' : rtrim( $base, '/' );
    }

    $fallback = trim( $fallback );
    if ( $fallback === '' || $fallback === '/' ) {
        return '';
    }

    return rtrim( $fallback, '/' );
}

function metis_runtime_base_url( string $fallback_base_path = '' ): string {
    $configured = metis_runtime_config_base_url();
    if ( $configured !== '' ) {
        return $configured;
    }

    return metis_runtime_forwarded_proto() . '://' . metis_runtime_host() . metis_runtime_base_path( $fallback_base_path );
}

function metis_runtime_home_url( string $path = '' ): string {
    $url = metis_runtime_base_url();
    return $path === '' ? $url : rtrim( $url, '/' ) . '/' . ltrim( $path, '/' );
}

function metis_runtime_site_url( string $path = '' ): string {
    return metis_runtime_home_url( $path );
}

function metis_runtime_admin_url( string $path = '' ): string {
    return metis_runtime_home_url( $path );
}

function metis_runtime_plugin_dir_path( string $file ): string {
    return metis_runtime_trailingslashit( dirname( $file ) );
}

function metis_runtime_plugin_dir_url( string $file ): string {
    return metis_runtime_trailingslashit( dirname( metis_runtime_home_url( basename( dirname( $file ) ) . '/' . basename( $file ) ) ) );
}

function metis_runtime_add_query_arg( string|array $args, mixed $value = null, string $url = '' ): string {
    if ( is_array( $args ) ) {
        $query_args = $args;
        $target_url = is_string( $value ) ? $value : '';
    } else {
        $query_args = [ $args => $value ];
        $target_url = $url;
    }

    if ( $target_url === '' ) {
        $target_url = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
    }

    $parts = parse_url( $target_url );
    $query = [];
    if ( ! empty( $parts['query'] ) ) {
        parse_str( $parts['query'], $query );
    }
    foreach ( $query_args as $key => $value ) {
        $query[ (string) $key ] = $value;
    }
    $parts['query'] = http_build_query( $query );
    if ( isset( $parts['scheme'], $parts['host'] ) ) {
        $result = $parts['scheme'] . '://' . $parts['host'];
    } elseif ( isset( $parts['host'] ) ) {
        $result = '//' . $parts['host'];
    } else {
        $result = '';
    }
    if ( isset( $parts['port'] ) ) {
        $result .= ':' . $parts['port'];
    }
    $result .= $parts['path'] ?? '';
    if ( $parts['query'] !== '' ) {
        $result .= '?' . $parts['query'];
    }
    if ( isset( $parts['fragment'] ) && $parts['fragment'] !== '' ) {
        $result .= '#' . $parts['fragment'];
    }
    return $result;
}
