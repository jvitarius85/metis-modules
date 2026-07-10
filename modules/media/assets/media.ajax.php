<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

foreach ( \Metis\Modules\Media\Policies\MediaPolicy::ajaxControllers() as $action => $config ) {
    metis_ajax_register_controller( $action, [
        'module' => 'media',
        'permission' => $config['permission'],
        'nonce_action' => $config['nonce_action'],
        'allow_additional_fields' => $config['allow_additional_fields'],
        'schema' => $config['schema'],
    ] );
}

function metis_media_ajax_verify_nonce(): void {
    $valid = metis_check_ajax_referer( 'metis_media', 'nonce', false )
        || metis_check_ajax_referer( 'metis_core', 'nonce', false );

    if ( ! $valid ) {
        metis_runtime_send_json_error( 'Invalid nonce.', 403 );
    }
}

function metis_media_ajax_require_permission( string $key ): void {
    if ( ! metis_security_user_can( $key ) ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

metis_ajax_register_handler( 'metis_media_library_upload', [ \Metis\Modules\Media\Controllers\AjaxController::class, 'upload' ] );
metis_ajax_register_handler( 'metis_media_library_list', [ \Metis\Modules\Media\Controllers\AjaxController::class, 'list' ] );
metis_ajax_register_handler( 'metis_media_library_facets', [ \Metis\Modules\Media\Controllers\AjaxController::class, 'facets' ] );
metis_ajax_register_handler( 'metis_media_library_update_meta', [ \Metis\Modules\Media\Controllers\AjaxController::class, 'updateMeta' ] );
metis_ajax_register_handler( 'metis_media_library_delete', [ \Metis\Modules\Media\Controllers\AjaxController::class, 'delete' ] );
