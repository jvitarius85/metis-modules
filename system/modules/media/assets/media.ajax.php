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

    if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
        metis_runtime_send_json_error( 'File is required.', 400 );
    }

    $file = $_FILES['file'];
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
    ] );

    if ( ! is_array( $uploaded ) || ! empty( $uploaded['error'] ) ) {
        metis_runtime_send_json_error( 'Upload failed.', 500 );
    }

    $token = isset( $uploaded['token'] ) ? metis_text_clean( (string) $uploaded['token'] ) : '';
    $folder_path = isset( $_POST['folder_path'] ) ? (string) metis_runtime_unslash( $_POST['folder_path'] ) : '';
    $category_key = isset( $_POST['category_key'] ) ? (string) metis_runtime_unslash( $_POST['category_key'] ) : '';
    if ( $token !== '' && function_exists( 'metis_media_update_metadata' ) ) {
        metis_media_update_metadata( $token, $folder_path, $category_key );
    }

    metis_runtime_send_json_success( [
        'url' => isset( $uploaded['url'] ) ? metis_url_clean( (string) $uploaded['url'] ) : '',
        'token' => $token,
        'file_name' => metis_filename_clean( (string) ( $file['name'] ?? '' ) ),
        'size' => $size,
    ] );
} );

metis_ajax_register_handler( 'metis_media_library_list', function (): void {
    metis_media_ajax_verify_nonce();
    metis_media_ajax_require_permission( 'media.view' );

    if ( function_exists( 'metis_media_ensure_schema' ) ) {
        metis_media_ensure_schema();
    }

    $db = metis_db();
    $table = function_exists( 'metis_media_table_name' ) ? metis_media_table_name() : 'metis_media_files';

    $search = isset( $_POST['search'] ) ? trim( metis_text_clean( metis_runtime_unslash( $_POST['search'] ) ) ) : '';
    $type = isset( $_POST['type'] ) ? trim( metis_key_clean( metis_runtime_unslash( $_POST['type'] ) ) ) : '';
    $folder = isset( $_POST['folder'] ) ? (string) metis_runtime_unslash( $_POST['folder'] ) : '';
    $category = isset( $_POST['category'] ) ? (string) metis_runtime_unslash( $_POST['category'] ) : '';
    $sort = isset( $_POST['sort'] ) ? trim( metis_key_clean( metis_runtime_unslash( $_POST['sort'] ) ) ) : 'created_desc';
    $folder = function_exists( 'metis_media_normalize_folder_path' ) ? metis_media_normalize_folder_path( $folder ) : metis_slug_clean( $folder );
    $category = function_exists( 'metis_media_normalize_category_key' ) ? metis_media_normalize_category_key( $category ) : metis_key_clean( $category );
    $limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 80;
    $limit = max( 1, min( 200, $limit ) );
    $sort_sql = match ( $sort ) {
        'created_asc' => 'created_at ASC, id ASC',
        'name_asc' => 'file_name ASC, id DESC',
        'name_desc' => 'file_name DESC, id DESC',
        'size_asc' => 'size ASC, id DESC',
        'size_desc' => 'size DESC, id DESC',
        default => 'id DESC',
    };

    $where = [];
    $params = [];

    if ( $search !== '' ) {
        $where[] = 'file_name LIKE %s';
        $params[] = '%' . $db->escapeLike( $search ) . '%';
    }

    if ( $type !== '' ) {
        $where[] = 'mime_type LIKE %s';
        $params[] = $type . '/%';
    }
    if ( $folder !== '' ) {
        $where[] = 'folder_path = %s';
        $params[] = $folder;
    }
    if ( $category !== '' ) {
        $where[] = 'category_key = %s';
        $params[] = $category;
    }

    $where_sql = $where !== [] ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
    $sql = "SELECT id, public_token, file_name, mime_type, size, folder_path, category_key, created_at FROM {$table} {$where_sql} ORDER BY {$sort_sql} LIMIT %d";
    $params[] = $limit;

    $rows = $db->fetchAll( $sql, $params );
    $items = array_map( static function ( array $row ): array {
        $token = (string) ( $row['public_token'] ?? '' );
        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'token' => $token,
            'url' => $token !== '' ? metis_home_url( '/media/' . $token ) : '',
            'file_name' => (string) ( $row['file_name'] ?? '' ),
            'mime_type' => (string) ( $row['mime_type'] ?? '' ),
            'size' => (int) ( $row['size'] ?? 0 ),
            'folder_path' => (string) ( $row['folder_path'] ?? '' ),
            'category_key' => (string) ( $row['category_key'] ?? '' ),
            'created_at' => (string) ( $row['created_at'] ?? '' ),
        ];
    }, is_array( $rows ) ? $rows : [] );

    metis_runtime_send_json_success( [ 'items' => $items ] );
} );

metis_ajax_register_handler( 'metis_media_library_facets', function (): void {
    metis_media_ajax_verify_nonce();
    metis_media_ajax_require_permission( 'media.view' );

    if ( function_exists( 'metis_media_ensure_schema' ) ) {
        metis_media_ensure_schema();
    }

    $db = metis_db();
    $table = function_exists( 'metis_media_table_name' ) ? metis_media_table_name() : 'metis_media_files';

    $folder_rows = $db->fetchAll( "SELECT folder_path, COUNT(*) AS item_count FROM {$table} WHERE folder_path <> '' GROUP BY folder_path ORDER BY folder_path ASC LIMIT 300" );
    $category_rows = $db->fetchAll( "SELECT category_key, COUNT(*) AS item_count FROM {$table} WHERE category_key <> '' GROUP BY category_key ORDER BY category_key ASC LIMIT 300" );

    $folders = [];
    $folder_counts = [];
    foreach ( is_array( $folder_rows ) ? $folder_rows : [] as $row ) {
        if ( is_array( $row ) && isset( $row['folder_path'] ) ) {
            $value = (string) $row['folder_path'];
            if ( $value !== '' ) {
                $folders[] = $value;
                $folder_counts[] = [
                    'value' => $value,
                    'count' => (int) ( $row['item_count'] ?? 0 ),
                ];
            }
        }
    }

    $categories = [];
    $category_counts = [];
    foreach ( is_array( $category_rows ) ? $category_rows : [] as $row ) {
        if ( is_array( $row ) && isset( $row['category_key'] ) ) {
            $value = (string) $row['category_key'];
            if ( $value !== '' ) {
                $categories[] = $value;
                $category_counts[] = [
                    'value' => $value,
                    'count' => (int) ( $row['item_count'] ?? 0 ),
                ];
            }
        }
    }

    metis_runtime_send_json_success( [
        'folders' => array_values( array_unique( $folders ) ),
        'categories' => array_values( array_unique( $categories ) ),
        'folder_counts' => $folder_counts,
        'category_counts' => $category_counts,
    ] );
} );

metis_ajax_register_handler( 'metis_media_library_update_meta', function (): void {
    metis_media_ajax_verify_nonce();
    metis_media_ajax_require_permission( 'media.edit' );

    $token = isset( $_POST['token'] ) ? strtolower( trim( metis_text_clean( metis_runtime_unslash( $_POST['token'] ) ) ) ) : '';
    if ( $token === '' || ! preg_match( '/^[a-f0-9]{24,64}$/', $token ) ) {
        metis_runtime_send_json_error( 'Invalid media token.', 400 );
    }

    $folder_path = isset( $_POST['folder_path'] ) ? (string) metis_runtime_unslash( $_POST['folder_path'] ) : '';
    $category_key = isset( $_POST['category_key'] ) ? (string) metis_runtime_unslash( $_POST['category_key'] ) : '';

    if ( ! function_exists( 'metis_media_update_metadata' ) || ! metis_media_update_metadata( $token, $folder_path, $category_key ) ) {
        metis_runtime_send_json_error( 'Unable to update media metadata.', 500 );
    }

    metis_runtime_send_json_success( [ 'updated' => true ] );
} );

metis_ajax_register_handler( 'metis_media_library_delete', function (): void {
    metis_media_ajax_verify_nonce();
    metis_media_ajax_require_permission( 'media.delete' );

    $token = isset( $_POST['token'] ) ? strtolower( trim( metis_text_clean( metis_runtime_unslash( $_POST['token'] ) ) ) ) : '';
    if ( $token === '' || ! preg_match( '/^[a-f0-9]{24,64}$/', $token ) ) {
        metis_runtime_send_json_error( 'Invalid media token.', 400 );
    }

    if ( function_exists( 'metis_media_ensure_schema' ) ) {
        metis_media_ensure_schema();
    }

    $db = metis_db();
    $table = function_exists( 'metis_media_table_name' ) ? metis_media_table_name() : 'metis_media_files';

    $row = $db->fetchOne( "SELECT id, storage_path FROM {$table} WHERE public_token = %s LIMIT 1", [ $token ] );
    if ( ! is_array( $row ) ) {
        metis_runtime_send_json_error( 'Media item not found.', 404 );
    }

    $relative_path = ltrim( (string) ( $row['storage_path'] ?? '' ), '/' );
    foreach ( [ METIS_PATH . 'storage/uploads', METIS_PATH . 'storage/media' ] as $root_path ) {
        $base = realpath( $root_path );
        $target = $base && $relative_path !== '' ? realpath( $base . '/' . $relative_path ) : false;
        if ( is_string( $base ) && is_string( $target ) && str_starts_with( $target, $base ) && is_file( $target ) ) {
            @unlink( $target );
            break;
        }
    }

    $deleted = $db->delete( $table, [ 'id' => (int) ( $row['id'] ?? 0 ) ], [ '%d' ] );
    if ( ! $deleted ) {
        metis_runtime_send_json_error( 'Failed to delete media item.', 500 );
    }

    metis_runtime_send_json_success( [ 'deleted' => true ] );
} );
