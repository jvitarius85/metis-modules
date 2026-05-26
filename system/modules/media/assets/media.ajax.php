<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

metis_ajax_register_controller( 'metis_media_library_list', [
    'module' => 'media',
    'permission' => 'view',
    'nonce_action' => metis_ajax_nonce_action( 'metis_media_library_list' ),
] );
metis_ajax_register_controller( 'metis_media_library_upload', [
    'module' => 'media',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_media_library_upload' ),
] );
metis_ajax_register_controller( 'metis_media_library_facets', [
    'module' => 'media',
    'permission' => 'view',
    'nonce_action' => metis_ajax_nonce_action( 'metis_media_library_facets' ),
] );
metis_ajax_register_controller( 'metis_media_library_update_meta', [
    'module' => 'media',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_media_library_update_meta' ),
] );
metis_ajax_register_controller( 'metis_media_library_delete', [
    'module' => 'media',
    'permission' => 'delete',
    'nonce_action' => metis_ajax_nonce_action( 'metis_media_library_delete' ),
] );

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

metis_ajax_register_handler( 'metis_media_library_upload', function (): void {
    metis_media_ajax_verify_nonce();
    metis_media_ajax_require_permission( 'media.edit' );

    if ( empty( metis_request_files()['file'] ) || ! is_array( metis_request_files()['file'] ) ) {
        metis_runtime_send_json_error( 'File is required.', 400 );
    }

    $file = metis_request_files()['file'];
    $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
    if ( $size < 1 ) {
        metis_runtime_send_json_error( 'Uploaded file is empty.', 400 );
    }
    if ( $size > 25 * 1024 * 1024 ) {
        metis_runtime_send_json_error( 'File must be 25MB or smaller.', 400 );
    }

    $uploaded = metis_handle_upload( $file, [
        'policy' => 'media_library',
        'test_form' => false,
        'optimize_images' => true,
        'image_max_dimension' => 2400,
        'image_quality' => 82,
    ] );

    if ( ! is_array( $uploaded ) || ! empty( $uploaded['error'] ) ) {
        metis_runtime_send_json_error( 'Upload failed.', 500 );
    }

    $token = isset( $uploaded['token'] ) ? metis_text_clean( (string) $uploaded['token'] ) : '';
    $folder_path = isset( metis_request_post()['folder_path'] ) ? (string) metis_runtime_unslash( metis_request_post()['folder_path'] ) : '';
    $category_key = isset( metis_request_post()['category_key'] ) ? (string) metis_runtime_unslash( metis_request_post()['category_key'] ) : '';
    if ( $token !== '' && function_exists( 'metis_media_update_metadata' ) ) {
        metis_media_update_metadata( $token, $folder_path, $category_key );
    }

    metis_runtime_send_json_success( [
        'url' => isset( $uploaded['url'] ) ? metis_url_clean( (string) $uploaded['url'] ) : '',
        'token' => $token,
        'file_name' => metis_filename_clean( (string) ( $file['name'] ?? '' ) ),
        'size' => isset( $uploaded['optimization']['optimized_size'] )
            ? (int) $uploaded['optimization']['optimized_size']
            : $size,
        'optimized' => ! empty( $uploaded['optimized'] ),
    ] );
} );

metis_ajax_register_handler( 'metis_media_library_list', function (): void {
    metis_media_ajax_verify_nonce();
    metis_media_ajax_require_permission( 'media.view' );

    $search = isset( metis_request_post()['search'] ) ? trim( metis_text_clean( metis_runtime_unslash( metis_request_post()['search'] ) ) ) : '';
    $type = isset( metis_request_post()['type'] ) ? trim( metis_key_clean( metis_runtime_unslash( metis_request_post()['type'] ) ) ) : '';
    $folder = isset( metis_request_post()['folder'] ) ? (string) metis_runtime_unslash( metis_request_post()['folder'] ) : '';
    $category = isset( metis_request_post()['category'] ) ? (string) metis_runtime_unslash( metis_request_post()['category'] ) : '';
    $sort = isset( metis_request_post()['sort'] ) ? trim( metis_key_clean( metis_runtime_unslash( metis_request_post()['sort'] ) ) ) : 'created_desc';
    $folder = function_exists( 'metis_media_normalize_folder_path' ) ? metis_media_normalize_folder_path( $folder ) : metis_slug_clean( $folder );
    $category = function_exists( 'metis_media_normalize_category_key' ) ? metis_media_normalize_category_key( $category ) : metis_key_clean( $category );
    $limit = isset( metis_request_post()['limit'] ) ? (int) metis_request_post()['limit'] : 80;
    $items = \Metis\Modules\Media\MediaLibraryService::listItems( $search, $type, $folder, $category, $sort, $limit );

    metis_runtime_send_json_success( [ 'items' => $items ] );
} );

metis_ajax_register_handler( 'metis_media_library_facets', function (): void {
    metis_media_ajax_verify_nonce();
    metis_media_ajax_require_permission( 'media.view' );

    metis_runtime_send_json_success( \Metis\Modules\Media\MediaLibraryService::facets() );
} );

metis_ajax_register_handler( 'metis_media_library_update_meta', function (): void {
    metis_media_ajax_verify_nonce();
    metis_media_ajax_require_permission( 'media.edit' );

    $token = isset( metis_request_post()['token'] ) ? strtolower( trim( metis_text_clean( metis_runtime_unslash( metis_request_post()['token'] ) ) ) ) : '';
    if ( $token === '' || ! preg_match( '/^[a-f0-9]{24,64}$/', $token ) ) {
        metis_runtime_send_json_error( 'Invalid media token.', 400 );
    }

    $folder_path = isset( metis_request_post()['folder_path'] ) ? (string) metis_runtime_unslash( metis_request_post()['folder_path'] ) : '';
    $category_key = isset( metis_request_post()['category_key'] ) ? (string) metis_runtime_unslash( metis_request_post()['category_key'] ) : '';

    if ( ! function_exists( 'metis_media_update_metadata' ) || ! metis_media_update_metadata( $token, $folder_path, $category_key ) ) {
        metis_runtime_send_json_error( 'Unable to update media metadata.', 500 );
    }

    metis_runtime_send_json_success( [ 'updated' => true ] );
} );

metis_ajax_register_handler( 'metis_media_library_delete', function (): void {
    metis_media_ajax_verify_nonce();
    metis_media_ajax_require_permission( 'media.delete' );

    $token = isset( metis_request_post()['token'] ) ? strtolower( trim( metis_text_clean( metis_runtime_unslash( metis_request_post()['token'] ) ) ) ) : '';
    if ( $token === '' || ! preg_match( '/^[a-f0-9]{24,64}$/', $token ) ) {
        metis_runtime_send_json_error( 'Invalid media token.', 400 );
    }

    $row = \Metis\Modules\Media\MediaLibraryService::findByToken( $token );
    if ( ! is_array( $row ) ) {
        metis_runtime_send_json_error( 'Media item not found.', 404 );
    }

    $relative_path = ltrim( (string) ( $row['storage_path'] ?? '' ), '/' );
    $storage_class = metis_key_clean( (string) ( $row['storage_class'] ?? 'legacy' ) );
    $resolved = function_exists( 'metis_media_resolve_registered_path' )
        ? metis_media_resolve_registered_path( $storage_class, $relative_path, true )
        : null;
    if ( is_array( $resolved ) && is_file( (string) ( $resolved['path'] ?? '' ) ) ) {
        @unlink( (string) $resolved['path'] );
        if ( function_exists( 'metis_audit_log_activity' ) ) {
            metis_audit_log_activity( 'media_storage_deleted', [
                'module' => 'media',
                'resource' => [ 'type' => 'media', 'id' => $token ],
                'context' => [ 'storage_class' => $storage_class ],
            ] );
        }
    }

    $deleted = metis_db()->delete(
        function_exists( 'metis_media_table_name' ) ? metis_media_table_name() : 'metis_media_files',
        [ 'id' => (int) ( $row['id'] ?? 0 ) ],
        [ '%d' ]
    );
    if ( ! $deleted ) {
        metis_runtime_send_json_error( 'Failed to delete media item.', 500 );
    }

    metis_runtime_send_json_success( [ 'deleted' => true ] );
} );
