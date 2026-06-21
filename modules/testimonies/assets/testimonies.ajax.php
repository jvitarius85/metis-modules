<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $metis_testimonies_ajax_permissions = [
        'metis_testimonies_save' => 'edit',
        'metis_testimonies_delete' => 'delete',
        'metis_testimony_categories_save' => 'edit',
        'metis_testimony_categories_delete' => 'delete',
    ];

    foreach ( $metis_testimonies_ajax_permissions as $action => $permission ) {
        metis_ajax_register_controller( $action, [
            'module' => 'testimonies',
            'permission' => $permission,
        ] );
    }
}

function metis_testimonies_ajax_current_user_id(): int {
    return function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0;
}

function metis_testimonies_ajax_post_value( string $key, mixed $default = '' ): mixed {
    if ( ! isset( metis_request_post()[ $key ] ) ) {
        return $default;
    }
    return metis_runtime_unslash( metis_request_post()[ $key ] );
}

function metis_testimonies_ajax_json_array( string $key ): array {
    $raw = metis_testimonies_ajax_post_value( $key, '' );
    if ( is_array( $raw ) ) {
        return $raw;
    }
    if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
        return [];
    }
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

function metis_testimonies_ajax_verify_nonce( string $action ): void {
    $token = trim( (string) metis_testimonies_ajax_post_value( 'nonce', '' ) );
    $action_nonce = trim( (string) metis_testimonies_ajax_post_value( 'metis_action_nonce', '' ) );
    $valid = false;

    if ( $action !== '' && function_exists( 'metis_runtime_verify_nonce' ) && function_exists( 'metis_ajax_nonce_action' ) ) {
        $nonce_action = metis_ajax_nonce_action( $action );
        if ( $action_nonce !== '' ) {
            $valid = metis_runtime_verify_nonce( $action_nonce, $nonce_action );
        }
        if ( ! $valid && $token !== '' ) {
            $valid = metis_runtime_verify_nonce( $token, $nonce_action );
        }
    }

    if ( ! $valid && $token !== '' && function_exists( 'metis_runtime_verify_nonce' ) ) {
        $valid = metis_runtime_verify_nonce( $token, 'metis_testimonies' );
    }

    if ( ! $valid ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
}

function metis_testimonies_ajax_send_result( array $result, string $fallback ): void {
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( [ 'message' => (string) ( $result['error'] ?? $fallback ), 'result' => $result ], (int) ( $result['status'] ?? 422 ) );
    }
    metis_runtime_send_json_success( $result );
}

metis_ajax_register_handler( 'metis_testimonies_save', static function (): void {
    metis_testimonies_ajax_verify_nonce( 'metis_testimonies_save' );
    if ( ! metis_testimonies_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Testimonies\Repository::saveTestimony( metis_testimonies_ajax_json_array( 'testimony' ), metis_testimonies_ajax_current_user_id() );
    metis_testimonies_ajax_send_result( $result, 'Testimony save failed.' );
} );

metis_ajax_register_handler( 'metis_testimonies_delete', static function (): void {
    metis_testimonies_ajax_verify_nonce( 'metis_testimonies_delete' );
    if ( ! metis_testimonies_can_delete() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Testimonies\Repository::deleteTestimony( max( 0, (int) metis_testimonies_ajax_post_value( 'testimony_id', 0 ) ) );
    metis_testimonies_ajax_send_result( $result, 'Testimony delete failed.' );
} );

metis_ajax_register_handler( 'metis_testimony_categories_save', static function (): void {
    metis_testimonies_ajax_verify_nonce( 'metis_testimony_categories_save' );
    if ( ! metis_testimonies_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Testimonies\Repository::saveCategory( metis_testimonies_ajax_json_array( 'category' ), metis_testimonies_ajax_current_user_id() );
    metis_testimonies_ajax_send_result( $result, 'Category save failed.' );
} );

metis_ajax_register_handler( 'metis_testimony_categories_delete', static function (): void {
    metis_testimonies_ajax_verify_nonce( 'metis_testimony_categories_delete' );
    if ( ! metis_testimonies_can_delete() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Testimonies\Repository::deleteCategory( max( 0, (int) metis_testimonies_ajax_post_value( 'category_id', 0 ) ) );
    metis_testimonies_ajax_send_result( $result, 'Category delete failed.' );
} );
