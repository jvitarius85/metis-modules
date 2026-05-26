<?php
declare(strict_types=1);

function metis_runtime_is_https_request(): bool {
    $https = strtolower( (string) ( $_SERVER['HTTPS'] ?? '' ) );
    if ( $https !== '' && $https !== 'off' ) {
        return true;
    }

    if ( (int) ( $_SERVER['SERVER_PORT'] ?? 0 ) === 443 ) {
        return true;
    }

    $forwarded = trim( (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) );
    if ( $forwarded !== '' ) {
        $first = strtolower( trim( explode( ',', $forwarded )[0] ?? '' ) );
        if ( $first === 'https' ) {
            return true;
        }
    }

    return false;
}

function metis_runtime_env_bool( string $name, bool $default ): bool {
    $value = getenv( $name );
    if ( $value === false ) {
        return $default;
    }

    $value = trim( (string) $value );
    if ( $value === '' ) {
        return $default;
    }

    $parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
    return $parsed === null ? $default : $parsed;
}

function metis_runtime_hsts_header_value(): string {
    $directives = [ 'max-age=31536000' ];

    if ( metis_runtime_env_bool( 'METIS_HSTS_INCLUDE_SUBDOMAINS', true ) ) {
        $directives[] = 'includeSubDomains';
    }

    if ( metis_runtime_env_bool( 'METIS_HSTS_PRELOAD', false ) ) {
        $directives[] = 'preload';
    }

    return implode( '; ', $directives );
}

function metis_runtime_emit_security_headers(): void {
    $headers = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Permissions-Policy'     => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
        'Content-Security-Policy'=> "base-uri 'self'; frame-ancestors 'self'; object-src 'none'",
    ];

    if ( metis_runtime_is_https_request() ) {
        $headers['Strict-Transport-Security'] = metis_runtime_hsts_header_value();
    }

    foreach ( $headers as $name => $value ) {
        header( $name . ': ' . $value, true );
    }
}

if ( ! function_exists( 'metis_send_status' ) ) {
function metis_send_status( int $code ): void {
    $text = match ( $code ) {
        200 => 'OK',
        201 => 'Created',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        410 => 'Gone',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
        default => '',
    };
    $header = sprintf( 'HTTP/1.1 %d %s', $code, $text );
    $header = metis_runtime_filter( 'metis_status_header', $header, $code, $text );
    header( $header, true, $code );
}
}

if ( ! function_exists( 'nocache_headers' ) ) {
function nocache_headers(): void {
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
    header( 'Pragma: no-cache', true );
}
}

function metis_runtime_emit_request_id_header(): void {
    if ( ! function_exists( 'metis_audit_request_id' ) ) {
        return;
    }

    $request_id = trim( (string) metis_audit_request_id() );
    if ( $request_id === '' ) {
        return;
    }

    header( 'X-Metis-Request-Id: ' . $request_id, true );
}

function metis_runtime_die( string $message = '', string $title = '', array $args = [] ): never {
    $handler = metis_runtime_filter( 'metis_die_handler', null );
    if ( is_string( $handler ) && function_exists( $handler ) ) {
        $handler( $message, $title, $args );
    }

    $handler = metis_runtime_filter( 'metis_die_ajax_handler', null );
    if ( is_string( $handler ) && function_exists( $handler ) ) {
        $handler( $message, $title, $args );
    }

    $status = isset( $args['response'] ) ? (int) $args['response'] : 500;
    metis_send_status( $status );
    metis_runtime_emit_security_headers();
    metis_runtime_emit_request_id_header();
    exit( $message );
}

function metis_runtime_send_json( array $payload, int $status_code = 200 ): never {
    $response = Metis_Http_Response::json( $payload, $status_code );
    metis_send_status( $response->status() );
    metis_runtime_emit_security_headers();
    metis_runtime_emit_request_id_header();
    foreach ( $response->headers() as $name => $value ) {
        header( $name . ': ' . $value, true );
    }
    echo $response->body();
    exit;
}

function metis_runtime_send_json_success( array $data = [], int $status_code = 200 ): never {
    $message = null;

    if ( isset( $data['message'] ) && is_string( $data['message'] ) && trim( $data['message'] ) !== '' ) {
        $message = (string) $data['message'];
        unset( $data['message'] );
    }

    $request_id = function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : '';

    metis_runtime_send_json(
        [
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
            'errors'  => [],
            'request_id' => $request_id !== '' ? $request_id : null,
            'success' => true,
        ],
        $status_code
    );
}

function metis_runtime_send_json_error( mixed $data = [], int $status_code = 400 ): never {
    $message = 'Operation failed';
    $errors  = [];
    $payload = [];

    if ( is_string( $data ) ) {
        $trimmed = trim( $data );
        if ( $trimmed !== '' ) {
            $message = $trimmed;
        }
        $payload = [ 'message' => $message ];
    } elseif ( is_array( $data ) ) {
        if ( isset( $data['message'] ) && is_string( $data['message'] ) && trim( $data['message'] ) !== '' ) {
            $message = (string) $data['message'];
        }

        if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
            $errors = $data['errors'];
        } elseif ( ! isset( $data['message'] ) ) {
            $errors = $data;
        }

        $payload = $data;
    } else {
        $payload = [ 'message' => $message ];
    }

    $request_id = function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : '';

    metis_runtime_send_json(
        [
            'status'  => 'error',
            'message' => $message,
            'errors'  => $errors,
            'data'    => $payload,
            'request_id' => $request_id !== '' ? $request_id : null,
            'success' => false,
        ],
        $status_code
    );
}

function metis_runtime_redirect( string $location, int $status = 302 ): never {
    $response = Metis_Http_Response::redirect( $location, $status );
    metis_send_status( $response->status() );
    metis_runtime_emit_security_headers();
    metis_runtime_emit_request_id_header();
    foreach ( $response->headers() as $name => $value ) {
        header( $name . ': ' . $value, true );
    }
    exit;
}
