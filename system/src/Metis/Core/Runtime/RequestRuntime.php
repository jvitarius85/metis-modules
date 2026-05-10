<?php
declare(strict_types=1);

function metis_runtime_get_query_var( string $key, mixed $default = '' ): mixed {
    return $GLOBALS['metis_query_vars'][ $key ] ?? $default;
}

function metis_runtime_set_query_var( string $key, mixed $value ): void {
    $GLOBALS['metis_query_vars'][ $key ] = $value;
}

function metis_runtime_doing_ajax(): bool {
    return defined( 'DOING_AJAX' ) && DOING_AJAX;
}

function metis_sapi_input_array( int $type ): array {
    $input = filter_input_array( $type, FILTER_UNSAFE_RAW );
    if ( is_array( $input ) ) {
        return $input;
    }

    $fallbacks = [
        INPUT_GET => '_GET',
        INPUT_POST => '_POST',
        INPUT_COOKIE => '_COOKIE',
    ];
    $fallback_key = $fallbacks[ $type ] ?? '';
    $fallback = $fallback_key !== '' ? ( $GLOBALS[ $fallback_key ] ?? [] ) : [];
    return is_array( $fallback ) ? $fallback : [];
}

function metis_sapi_files_array(): array {
    $files = $GLOBALS['_FILES'] ?? [];
    return is_array( $files ) ? $files : [];
}

function metis_request_get(): array {
    return metis_sapi_input_array( INPUT_GET );
}

function metis_request_post(): array {
    return metis_sapi_input_array( INPUT_POST );
}

function metis_request_files(): array {
    return metis_sapi_files_array();
}

function metis_request_cookie(): array {
    return metis_sapi_input_array( INPUT_COOKIE );
}

function metis_request_raw_body(): string {
    $raw = file_get_contents( 'php://input' );
    return is_string( $raw ) ? $raw : '';
}

function metis_request_input_value( string $field, mixed $default = '' ): mixed {
    if ( $field === '' ) {
        return $default;
    }

    $post = metis_request_post();
    if ( array_key_exists( $field, $post ) ) {
        return $post[ $field ];
    }

    $get = metis_request_get();
    if ( array_key_exists( $field, $get ) ) {
        return $get[ $field ];
    }

    return $default;
}

function metis_request_scalar( string $field, mixed $default = '', string $source = 'request' ): mixed {
    $source = metis_key_clean( $source );
    $value = $default;

    if ( $field === '' ) {
        return $default;
    }

    if ( $source === 'post' ) {
        $post = metis_request_post();
        $value = array_key_exists( $field, $post ) ? $post[ $field ] : $default;
    } elseif ( $source === 'get' ) {
        $get = metis_request_get();
        $value = array_key_exists( $field, $get ) ? $get[ $field ] : $default;
    } else {
        $value = metis_request_input_value( $field, $default );
    }

    if ( is_array( $value ) || is_object( $value ) ) {
        return $default;
    }

    return is_string( $value ) && function_exists( 'metis_runtime_unslash' )
        ? metis_runtime_unslash( $value )
        : $value;
}

function metis_request_id( string $field, int $default = 0, string $source = 'request' ): int {
    $value = metis_request_scalar( $field, $default, $source );
    if ( is_int( $value ) ) {
        return max( 0, $value );
    }

    $value = trim( (string) $value );
    if ( $value === '' || ! preg_match( '/^[0-9]+$/', $value ) ) {
        return $default;
    }

    return max( 0, (int) $value );
}

function metis_request_object_code( string $field, string $default = '', string $source = 'request' ): string {
    $value = strtoupper( trim( metis_text_clean( (string) metis_request_scalar( $field, $default, $source ) ) ) );
    if ( $value === '' ) {
        return $default;
    }

    return preg_match( '/^[A-Z][A-Z0-9_-]{1,63}$/', $value ) ? $value : $default;
}

function metis_request_enum( string $field, array $allowed, string $default = '', string $source = 'request' ): string {
    $allowed = array_values( array_unique( array_map( static fn ( mixed $item ): string => metis_key_clean( (string) $item ), $allowed ) ) );
    $value = metis_key_clean( (string) metis_request_scalar( $field, $default, $source ) );
    if ( $value === '' ) {
        return $default;
    }

    return in_array( $value, $allowed, true ) ? $value : $default;
}

function metis_request_json( string $field, array $default = [], string $source = 'request', int $max_bytes = 65536 ): array {
    $raw = (string) metis_request_scalar( $field, '', $source );
    if ( $raw === '' || strlen( $raw ) > max( 1024, $max_bytes ) ) {
        return $default;
    }

    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : $default;
}

function metis_request_date( string $field, string $default = '', string $source = 'request' ): string {
    $value = trim( (string) metis_request_scalar( $field, $default, $source ) );
    if ( $value === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
        return $default;
    }

    $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
    return $date instanceof DateTimeImmutable && $date->format( 'Y-m-d' ) === $value ? $value : $default;
}

function metis_request_bool( string $field, bool $default = false, string $source = 'request' ): bool {
    $value = metis_request_scalar( $field, $default ? '1' : '0', $source );
    if ( is_bool( $value ) ) {
        return $value;
    }

    $parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
    return $parsed === null ? $default : $parsed;
}

function metis_request_file( string $field ): ?array {
    $files = metis_request_files();
    if ( $field === '' || empty( $files[ $field ] ) || ! is_array( $files[ $field ] ) ) {
        return null;
    }

    return $files[ $field ];
}

function metis_request_nonce_candidates( string|bool $query_arg = false ): array {
    $fields = [];
    if ( is_string( $query_arg ) && $query_arg !== '' ) {
        $fields[] = $query_arg;
    }

    $fields = array_merge(
        $fields,
        [ 'metis_action_nonce', 'nonce', 'security' ]
    );

    $candidates = [];
    foreach ( $fields as $field ) {
        if ( ! is_string( $field ) || $field === '' ) {
            continue;
        }

        $value = (string) metis_request_input_value( $field, '' );
        if ( $value !== '' ) {
            $candidates[] = $value;
        }
    }

    return array_values( array_unique( $candidates ) );
}

function metis_runtime_check_ajax_referer( string $action = '-1', string|bool $query_arg = false, bool $stop = true ): bool {
    $candidates = metis_request_nonce_candidates( $query_arg );
    $valid = false;

    foreach ( $candidates as $candidate ) {
        if ( metis_runtime_verify_nonce( $candidate, $action ) ) {
            $valid = true;
            break;
        }
    }

    if ( ! $valid ) {
        $request_action = metis_key_clean( (string) metis_request_input_value( 'action', '' ) );
        if ( $request_action !== '' ) {
            $ajax_nonce_action = function_exists( 'metis_ajax_nonce_action' )
                ? metis_ajax_nonce_action( $request_action )
                : 'metis_ajax:' . $request_action;

            if ( $ajax_nonce_action !== '' && $ajax_nonce_action !== $action ) {
                foreach ( $candidates as $candidate ) {
                    if ( metis_runtime_verify_nonce( $candidate, $ajax_nonce_action ) ) {
                        $valid = true;
                        break;
                    }
                }
            }
        }
    }

    if ( ! $valid && $stop ) {
        metis_runtime_die( 'Invalid nonce.', 'Error', [ 'response' => 403 ] );
    }
    return $valid;
}
