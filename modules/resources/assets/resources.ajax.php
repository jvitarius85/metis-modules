<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $permissions = [
        'metis_resources_type_save' => 'edit',
        'metis_resources_type_delete' => 'delete',
        'metis_resources_category_save' => 'edit',
        'metis_resources_category_delete' => 'delete',
        'metis_resources_tag_save' => 'edit',
        'metis_resources_tag_delete' => 'delete',
        'metis_resources_resource_save' => 'edit',
        'metis_resources_resource_delete' => 'delete',
    ];
    foreach ( $permissions as $action => $permission ) {
        metis_ajax_register_controller( $action, [
            'module' => 'resources',
            'permission' => $permission,
        ] );
    }
}

function metis_resources_ajax_post_value( string $key, mixed $default = '' ): mixed {
    if ( ! isset( metis_request_post()[ $key ] ) ) {
        return $default;
    }
    return metis_runtime_unslash( metis_request_post()[ $key ] );
}

function metis_resources_ajax_json_array( string $key ): array {
    $raw = metis_resources_ajax_post_value( $key, '' );
    if ( is_array( $raw ) ) {
        return $raw;
    }
    if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
        return [];
    }
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

function metis_resources_ajax_verify_nonce( string $action ): void {
    $nonce = trim( (string) metis_resources_ajax_post_value( 'nonce', '' ) );
    $action_nonce = trim( (string) metis_resources_ajax_post_value( 'metis_action_nonce', '' ) );
    $valid = false;

    if ( $action !== '' && function_exists( 'metis_runtime_verify_nonce' ) && function_exists( 'metis_ajax_nonce_action' ) ) {
        $nonce_action = metis_ajax_nonce_action( $action );
        if ( $action_nonce !== '' ) {
            $valid = metis_runtime_verify_nonce( $action_nonce, $nonce_action );
        }
        if ( ! $valid && $nonce !== '' ) {
            $valid = metis_runtime_verify_nonce( $nonce, $nonce_action );
        }
    }

    if ( ! $valid && $nonce !== '' && function_exists( 'metis_runtime_verify_nonce' ) ) {
        $valid = metis_runtime_verify_nonce( $nonce, 'metis_resources' );
    }

    if ( ! $valid ) {
        metis_runtime_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 );
    }
}

function metis_resources_ajax_send_result( array $result, string $fallback ): void {
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error(
            [ 'message' => (string) ( $result['error'] ?? $fallback ), 'result' => $result ],
            (int) ( $result['status'] ?? 422 )
        );
    }

    $result['snapshot'] = \Metis\Modules\Resources\Repository::listSnapshot();
    metis_runtime_send_json_success( $result );
}

function metis_resources_ajax_current_user_id(): int {
    return function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0;
}

metis_ajax_register_handler( 'metis_resources_type_save', static function (): void {
    metis_resources_ajax_verify_nonce( 'metis_resources_type_save' );
    if ( ! metis_resources_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Resources\Repository::saveType( metis_resources_ajax_json_array( 'type' ), metis_resources_ajax_current_user_id() );
    metis_resources_ajax_send_result( $result, 'Type save failed.' );
} );

metis_ajax_register_handler( 'metis_resources_category_save', static function (): void {
    metis_resources_ajax_verify_nonce( 'metis_resources_category_save' );
    if ( ! metis_resources_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Resources\Repository::saveCategory( metis_resources_ajax_json_array( 'category' ), metis_resources_ajax_current_user_id() );
    metis_resources_ajax_send_result( $result, 'Category save failed.' );
} );

metis_ajax_register_handler( 'metis_resources_tag_save', static function (): void {
    metis_resources_ajax_verify_nonce( 'metis_resources_tag_save' );
    if ( ! metis_resources_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Resources\Repository::saveTag( metis_resources_ajax_json_array( 'tag' ), metis_resources_ajax_current_user_id() );
    metis_resources_ajax_send_result( $result, 'Tag save failed.' );
} );

metis_ajax_register_handler( 'metis_resources_resource_save', static function (): void {
    metis_resources_ajax_verify_nonce( 'metis_resources_resource_save' );
    if ( ! metis_resources_can_manage() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Resources\Repository::saveResource(
        metis_resources_ajax_json_array( 'resource' ),
        metis_request_files(),
        metis_resources_ajax_current_user_id()
    );
    metis_resources_ajax_send_result( $result, 'Resource save failed.' );
} );

metis_ajax_register_handler( 'metis_resources_type_delete', static function (): void {
    metis_resources_ajax_verify_nonce( 'metis_resources_type_delete' );
    if ( ! metis_resources_can_delete() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Resources\Repository::deleteRecord( 'type', max( 0, (int) metis_resources_ajax_post_value( 'id', 0 ) ) );
    metis_resources_ajax_send_result( $result, 'Type delete failed.' );
} );

metis_ajax_register_handler( 'metis_resources_category_delete', static function (): void {
    metis_resources_ajax_verify_nonce( 'metis_resources_category_delete' );
    if ( ! metis_resources_can_delete() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Resources\Repository::deleteRecord( 'category', max( 0, (int) metis_resources_ajax_post_value( 'id', 0 ) ) );
    metis_resources_ajax_send_result( $result, 'Category delete failed.' );
} );

metis_ajax_register_handler( 'metis_resources_tag_delete', static function (): void {
    metis_resources_ajax_verify_nonce( 'metis_resources_tag_delete' );
    if ( ! metis_resources_can_delete() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Resources\Repository::deleteRecord( 'tag', max( 0, (int) metis_resources_ajax_post_value( 'id', 0 ) ) );
    metis_resources_ajax_send_result( $result, 'Tag delete failed.' );
} );

metis_ajax_register_handler( 'metis_resources_resource_delete', static function (): void {
    metis_resources_ajax_verify_nonce( 'metis_resources_resource_delete' );
    if ( ! metis_resources_can_delete() ) {
        metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }
    $result = \Metis\Modules\Resources\Repository::deleteRecord( 'resource', max( 0, (int) metis_resources_ajax_post_value( 'id', 0 ) ) );
    metis_resources_ajax_send_result( $result, 'Resource delete failed.' );
} );
