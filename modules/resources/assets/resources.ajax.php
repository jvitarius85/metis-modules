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

function metis_resources_ajax_verify_nonce( string $action ): void {
    $nonce = trim( (string) ( isset( metis_request_post()['nonce'] ) ? metis_runtime_unslash( metis_request_post()['nonce'] ) : '' ) );
    $action_nonce = trim( (string) ( isset( metis_request_post()['metis_action_nonce'] ) ? metis_runtime_unslash( metis_request_post()['metis_action_nonce'] ) : '' ) );
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

metis_ajax_register_handler( 'metis_resources_type_save', [ \Metis\Modules\Resources\Controllers\AjaxController::class, 'saveType' ] );
metis_ajax_register_handler( 'metis_resources_category_save', [ \Metis\Modules\Resources\Controllers\AjaxController::class, 'saveCategory' ] );
metis_ajax_register_handler( 'metis_resources_tag_save', [ \Metis\Modules\Resources\Controllers\AjaxController::class, 'saveTag' ] );
metis_ajax_register_handler( 'metis_resources_resource_save', [ \Metis\Modules\Resources\Controllers\AjaxController::class, 'saveResource' ] );
metis_ajax_register_handler( 'metis_resources_type_delete', [ \Metis\Modules\Resources\Controllers\AjaxController::class, 'deleteType' ] );
metis_ajax_register_handler( 'metis_resources_category_delete', [ \Metis\Modules\Resources\Controllers\AjaxController::class, 'deleteCategory' ] );
metis_ajax_register_handler( 'metis_resources_tag_delete', [ \Metis\Modules\Resources\Controllers\AjaxController::class, 'deleteTag' ] );
metis_ajax_register_handler( 'metis_resources_resource_delete', [ \Metis\Modules\Resources\Controllers\AjaxController::class, 'deleteResource' ] );
