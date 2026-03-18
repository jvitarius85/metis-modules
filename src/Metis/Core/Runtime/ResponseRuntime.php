<?php
declare(strict_types=1);

function status_header( int $code ): void {
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

function nocache_headers(): void {
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true );
    header( 'Pragma: no-cache', true );
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
    status_header( $status );
    exit( $message );
}

function metis_runtime_send_json( array $payload, int $status_code = 200 ): never {
    $response = Metis_Http_Response::json( $payload, $status_code );
    status_header( $response->status() );
    foreach ( $response->headers() as $name => $value ) {
        header( $name . ': ' . $value, true );
    }
    echo $response->body();
    exit;
}

function metis_runtime_send_json_success( array $data = [], int $status_code = 200 ): never {
    metis_runtime_send_json( [ 'success' => true, 'data' => $data ], $status_code );
}

function metis_runtime_send_json_error( mixed $data = [], int $status_code = 400 ): never {
    metis_runtime_send_json( [ 'success' => false, 'data' => $data ], $status_code );
}

function metis_runtime_redirect( string $location, int $status = 302 ): never {
    $response = Metis_Http_Response::redirect( $location, $status );
    status_header( $response->status() );
    foreach ( $response->headers() as $name => $value ) {
        header( $name . ': ' . $value, true );
    }
    exit;
}
