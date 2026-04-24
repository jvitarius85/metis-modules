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

function metis_request_nonce_candidates( string|bool $query_arg = false ): array {
    $fields = [];
    if ( is_string( $query_arg ) && $query_arg !== '' ) {
        $fields[] = $query_arg;
    }

    $fields = array_merge(
        $fields,
        [ '_ajax_nonce', 'metis_action_nonce', 'nonce', 'security', '_wpnonce' ]
    );

    $candidates = [];
    foreach ( $fields as $field ) {
        if ( ! is_string( $field ) || $field === '' || ! isset( $_REQUEST[ $field ] ) ) {
            continue;
        }

        $value = (string) $_REQUEST[ $field ];
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
        $request_action = metis_key_clean( (string) ( $_REQUEST['action'] ?? '' ) );
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
