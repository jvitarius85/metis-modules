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

function metis_testimonies_ajax_verify_nonce( string $action ): void {
    $token = trim( (string) ( isset( metis_request_post()['nonce'] ) ? metis_runtime_unslash( metis_request_post()['nonce'] ) : '' ) );
    $action_nonce = trim( (string) ( isset( metis_request_post()['metis_action_nonce'] ) ? metis_runtime_unslash( metis_request_post()['metis_action_nonce'] ) : '' ) );
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

metis_ajax_register_handler( 'metis_testimonies_save', [ \Metis\Modules\Testimonies\Controllers\AjaxController::class, 'saveTestimony' ] );
metis_ajax_register_handler( 'metis_testimonies_delete', [ \Metis\Modules\Testimonies\Controllers\AjaxController::class, 'deleteTestimony' ] );
metis_ajax_register_handler( 'metis_testimony_categories_save', [ \Metis\Modules\Testimonies\Controllers\AjaxController::class, 'saveCategory' ] );
metis_ajax_register_handler( 'metis_testimony_categories_delete', [ \Metis\Modules\Testimonies\Controllers\AjaxController::class, 'deleteCategory' ] );
