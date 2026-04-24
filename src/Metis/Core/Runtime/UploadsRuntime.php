<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_runtime_make_dir' ) ) {
    function metis_runtime_make_dir( string $target ): bool {
        if ( is_dir( $target ) ) {
            return true;
        }

        if ( @mkdir( $target, 0775, true ) ) {
            return true;
        }

        return is_dir( $target );
    }
}

function metis_media_type_from_mime( string $mime_type ): string {
    if ( str_starts_with( $mime_type, 'image/' ) ) {
        return 'images';
    }
    if ( str_starts_with( $mime_type, 'video/' ) ) {
        return 'videos';
    }
    if ( str_starts_with( $mime_type, 'audio/' ) ) {
        return 'audio';
    }
    if ( str_contains( $mime_type, 'pdf' ) || str_contains( $mime_type, 'document' ) || str_contains( $mime_type, 'sheet' ) || str_contains( $mime_type, 'text/' ) ) {
        return 'docs';
    }

    return 'files';
}

function metis_media_table_name(): string {
    if ( class_exists( 'Metis_Tables' ) && method_exists( 'Metis_Tables', 'has' ) && \Metis_Tables::has( 'media_files' ) ) {
        return \Metis_Tables::get( 'media_files' );
    }

    return 'metis_media_files';
}

function metis_media_ensure_schema(): void {
    static $ready = false;
    if ( $ready ) {
        return;
    }

    if ( ! function_exists( 'metis_db_delta' ) || ! function_exists( 'metis_db' ) ) {
        return;
    }

    $table = metis_media_table_name();
    $charset_collate = '';
    $conn = metis_db()->connection();
    if ( is_object( $conn ) && method_exists( $conn, 'get_charset_collate' ) ) {
        $charset_collate = (string) $conn->get_charset_collate();
    }
    if ( $charset_collate === '' ) {
        $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    metis_db_delta( "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        public_token VARCHAR(64) NOT NULL,
        storage_path VARCHAR(512) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(191) NOT NULL,
        size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        folder_path VARCHAR(255) NOT NULL DEFAULT '',
        category_key VARCHAR(80) NOT NULL DEFAULT '',
        uploaded_by BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY public_token (public_token),
        KEY created_at (created_at),
        KEY mime_type (mime_type),
        KEY folder_path (folder_path),
        KEY category_key (category_key),
        KEY uploaded_by (uploaded_by)
    ) {$charset_collate};" );

    $columns = metis_db()->fetchAll( 'SHOW COLUMNS FROM ' . $table );
    $existing = [];
    foreach ( is_array( $columns ) ? $columns : [] as $column ) {
        if ( is_array( $column ) && isset( $column['Field'] ) ) {
            $existing[] = (string) $column['Field'];
        }
    }

    if ( ! in_array( 'folder_path', $existing, true ) ) {
        metis_db()->execute( "ALTER TABLE {$table} ADD COLUMN folder_path VARCHAR(255) NOT NULL DEFAULT '' AFTER size" );
        metis_db()->execute( "ALTER TABLE {$table} ADD KEY folder_path (folder_path)" );
    }

    if ( ! in_array( 'category_key', $existing, true ) ) {
        metis_db()->execute( "ALTER TABLE {$table} ADD COLUMN category_key VARCHAR(80) NOT NULL DEFAULT '' AFTER folder_path" );
        metis_db()->execute( "ALTER TABLE {$table} ADD KEY category_key (category_key)" );
    }

    $ready = true;
}

function metis_media_register_file( string $absolute_path, string $file_name, string $mime_type, int $size, ?int $uploaded_by = null ): ?array {
    if ( ! function_exists( 'metis_db' ) || ! is_file( $absolute_path ) ) {
        return null;
    }

    $storage_root = null;
    $real_path = realpath( $absolute_path );
    foreach ( [ dirname( __DIR__, 4 ) . '/storage/uploads', dirname( __DIR__, 4 ) . '/storage/media' ] as $candidate_root ) {
        $candidate_real = realpath( $candidate_root );
        if ( is_string( $candidate_real ) && is_string( $real_path ) && str_starts_with( $real_path, $candidate_real ) ) {
            $storage_root = $candidate_real;
            break;
        }
    }
    if ( ! is_string( $storage_root ) || ! is_string( $real_path ) || ! str_starts_with( $real_path, $storage_root ) ) {
        return null;
    }

    $relative_storage_path = ltrim( str_replace( '\\', '/', substr( $real_path, strlen( $storage_root ) ) ), '/' );
    $public_token = bin2hex( random_bytes( 16 ) );

    metis_media_ensure_schema();
    $db = metis_db();
    $inserted = $db->insert(
        metis_media_table_name(),
        [
            'public_token' => $public_token,
            'storage_path' => $relative_storage_path,
            'file_name' => metis_filename_clean( $file_name !== '' ? $file_name : basename( $real_path ) ),
            'mime_type' => strtolower( trim( $mime_type ) ),
            'size' => max( 0, $size ),
            'uploaded_by' => $uploaded_by,
        ],
        [ '%s', '%s', '%s', '%s', '%d', '%d' ]
    );

    if ( ! $inserted ) {
        return null;
    }

    return [
        'token' => $public_token,
        'url' => metis_home_url( '/media/' . $public_token ),
        'storage_path' => $relative_storage_path,
    ];
}

function metis_media_find_by_token( string $token ): ?array {
    $token = strtolower( trim( $token ) );
    if ( $token === '' || ! preg_match( '/^[a-f0-9]{24,64}$/', $token ) ) {
        return null;
    }

    if ( ! function_exists( 'metis_db' ) ) {
        return null;
    }

    try {
        $row = metis_db()->fetchOne(
            'SELECT id, public_token, storage_path, file_name, mime_type, size, folder_path, category_key, uploaded_by, created_at FROM ' . metis_media_table_name() . ' WHERE public_token = %s LIMIT 1',
            [ $token ]
        );
    } catch ( Throwable ) {
        return null;
    }

    return is_array( $row ) ? $row : null;
}

function metis_media_normalize_folder_path( string $value ): string {
    $parts = array_values( array_filter( array_map( static function ( string $segment ): string {
        return metis_slug_clean( $segment );
    }, explode( '/', str_replace( '\\', '/', strtolower( trim( $value ) ) ) ) ) ) );

    return implode( '/', $parts );
}

function metis_media_normalize_category_key( string $value ): string {
    return metis_key_clean( strtolower( trim( $value ) ) );
}

function metis_media_update_metadata( string $token, string $folder_path = '', string $category_key = '' ): bool {
    if ( ! function_exists( 'metis_db' ) ) {
        return false;
    }

    $token = strtolower( trim( $token ) );
    if ( $token === '' || ! preg_match( '/^[a-f0-9]{24,64}$/', $token ) ) {
        return false;
    }

    metis_media_ensure_schema();

    $folder_path = metis_media_normalize_folder_path( $folder_path );
    $category_key = metis_media_normalize_category_key( $category_key );

    return (bool) metis_db()->update(
        metis_media_table_name(),
        [
            'folder_path' => $folder_path,
            'category_key' => $category_key,
        ],
        [ 'public_token' => $token ],
        [ '%s', '%s' ],
        [ '%s' ]
    );
}

function metis_normalize_mime_map( array $mimes ): array {
    $normalized = [];
    foreach ( $mimes as $extensions => $mime ) {
        $mime = strtolower( trim( (string) $mime ) );
        if ( $mime === '' ) {
            continue;
        }

        foreach ( explode( '|', strtolower( (string) $extensions ) ) as $extension ) {
            $extension = trim( $extension, ". \t\n\r\0\x0B" );
            if ( $extension === '' ) {
                continue;
            }
            $normalized[ $extension ] = $mime;
        }
    }

    return $normalized;
}

function metis_detect_file_mime_type( string $path ): string {
    if ( ! is_file( $path ) ) {
        return '';
    }

    if ( function_exists( 'finfo_open' ) ) {
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        if ( $finfo ) {
            $mime = finfo_file( $finfo, $path );
            if ( is_string( $mime ) && $mime !== '' ) {
                return strtolower( $mime );
            }
        }
    }

    if ( function_exists( 'mime_content_type' ) ) {
        $mime = mime_content_type( $path );
        if ( is_string( $mime ) && $mime !== '' ) {
            return strtolower( $mime );
        }
    }

    return '';
}

function metis_detect_binary_mime_type( string $contents ): string {
    if ( $contents === '' ) {
        return '';
    }

    if ( function_exists( 'finfo_open' ) ) {
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        if ( $finfo ) {
            $mime = finfo_buffer( $finfo, $contents );
            if ( is_string( $mime ) && $mime !== '' ) {
                return strtolower( $mime );
            }
        }
    }

    return '';
}

function metis_extension_for_mime_type( string $mime_type, ?array $mimes = null ): string {
    $mime_type = strtolower( trim( $mime_type ) );
    if ( $mime_type === '' ) {
        return '';
    }

    $normalized = metis_normalize_mime_map( $mimes ?? [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip',
    ] );

    foreach ( $normalized as $extension => $candidate_mime ) {
        if ( $candidate_mime === $mime_type ) {
            return $extension;
        }
    }

    return '';
}

function metis_generate_upload_filename( string $original_name = '', string $mime_type = '', ?array $mimes = null ): string {
    $extension = '';

    if ( $mime_type !== '' ) {
        $extension = metis_extension_for_mime_type( $mime_type, $mimes );
    }

    if ( $extension === '' ) {
        $extension = strtolower( pathinfo( metis_filename_clean( $original_name ), PATHINFO_EXTENSION ) );
    }

    $filename = bin2hex( random_bytes( 16 ) );
    if ( $extension !== '' ) {
        $filename .= '.' . $extension;
    }

    return $filename;
}

function metis_upload_dir( string $mime_type = '' ): array {
    $type = metis_media_type_from_mime( strtolower( trim( $mime_type ) ) );
    $subdir = '/' . $type . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
    $basedir = dirname( __DIR__, 4 ) . '/storage/uploads';
    $baseurl = metis_home_url( '/media/raw' );
    $path = $basedir . $subdir;
    $url = rtrim( $baseurl, '/' ) . $subdir;

    if ( ! metis_runtime_make_dir( $path ) ) {
        return [
            'path' => $path,
            'url' => $url,
            'subdir' => $subdir,
            'basedir' => $basedir,
            'baseurl' => $baseurl,
            'error' => 'Failed to create upload directory.',
        ];
    }

    return [
        'path' => $path,
        'url' => $url,
        'subdir' => $subdir,
        'basedir' => $basedir,
        'baseurl' => $baseurl,
        'error' => false,
    ];
}

function metis_upload_bits( string $name, ?string $deprecated, string $bits, ?string $time = null ): array {
    $detected_mime = metis_detect_binary_mime_type( $bits );
    $uploads = metis_upload_dir( $detected_mime );
    if ( ! empty( $uploads['error'] ) ) {
        return [
            'file' => '',
            'url' => '',
            'error' => (string) $uploads['error'],
        ];
    }

    $filename = metis_generate_upload_filename( $name, $detected_mime );

    $path = rtrim( (string) $uploads['path'], '/' ) . '/' . $filename;
    $url = rtrim( (string) $uploads['url'], '/' ) . '/' . rawurlencode( $filename );
    $suffix = 1;
    while ( file_exists( $path ) ) {
        $extension = pathinfo( $filename, PATHINFO_EXTENSION );
        $stem = pathinfo( $filename, PATHINFO_FILENAME );
        $candidate = $stem . '-' . $suffix;
        if ( $extension !== '' ) {
            $candidate .= '.' . $extension;
        }
        $path = rtrim( (string) $uploads['path'], '/' ) . '/' . $candidate;
        $url = rtrim( (string) $uploads['url'], '/' ) . '/' . rawurlencode( $candidate );
        $suffix++;
    }

    $written = file_put_contents( $path, $bits );
    if ( $written === false ) {
        return [
            'file' => '',
            'url' => '',
            'error' => 'Failed to write upload.',
        ];
    }

    $uploaded_by = function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : null;
    $media = metis_media_register_file( $path, $name, $detected_mime, strlen( $bits ), $uploaded_by );

    return [
        'file' => $path,
        'url' => $media['url'] ?? $url,
        'token' => $media['token'] ?? null,
        'error' => false,
    ];
}

function metis_check_filetype( string $filename, ?array $mimes = null ): array {
    $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    $types = metis_normalize_mime_map( $mimes ?? [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip',
    ] );

    return [
        'ext' => $extension,
        'type' => $types[ $extension ] ?? '',
    ];
}

function metis_store_upload_bits( string $name, string $bits, array $allowed_mimes = [] ): array {
    if ( $bits === '' ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => 'Uploaded file is empty.',
        ];
    }

    $normalized_mimes = metis_normalize_mime_map( $allowed_mimes );
    $detected_mime = metis_detect_binary_mime_type( $bits );
    if ( $detected_mime === '' ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => 'Unable to determine uploaded file type.',
        ];
    }

    if ( ! empty( $normalized_mimes ) && ! in_array( $detected_mime, $normalized_mimes, true ) ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => 'Uploaded file type is not allowed.',
        ];
    }

    $uploads = metis_upload_dir( $detected_mime );
    if ( ! empty( $uploads['error'] ) ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => (string) $uploads['error'],
        ];
    }

    $filename = metis_generate_upload_filename( $name, $detected_mime, $normalized_mimes );
    $path = rtrim( (string) $uploads['path'], '/' ) . '/' . $filename;
    $url = rtrim( (string) $uploads['url'], '/' ) . '/' . rawurlencode( $filename );
    $written = file_put_contents( $path, $bits );
    if ( $written === false ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => 'Failed to write upload.',
        ];
    }

    @chmod( $path, 0644 );

    $uploaded_by = function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : null;
    $media = metis_media_register_file( $path, $name, $detected_mime, strlen( $bits ), $uploaded_by );

    return [
        'file' => $path,
        'url' => $media['url'] ?? $url,
        'token' => $media['token'] ?? null,
        'type' => $detected_mime,
        'error' => false,
    ];
}

function metis_handle_upload( array $file, array $overrides = [] ): array {
    if ( empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
        return [ 'error' => 'Invalid upload payload.' ];
    }

    $upload_policy = new \Metis\Core\Services\UploadPolicyService();
    $validation = $upload_policy->validateFile( $file, $overrides );
    if ( empty( $validation['ok'] ) ) {
        return [ 'error' => 'Uploaded file is not allowed.' ];
    }

    $tmp = (string) $file['tmp_name'];
    $is_uploaded_file = function_exists( 'is_uploaded_file' ) && is_uploaded_file( $tmp );
    if ( ! $is_uploaded_file && ! is_file( $tmp ) ) {
        return [ 'error' => 'Uploaded file not found.' ];
    }

    $normalized_mimes = metis_normalize_mime_map( (array) ( $validation['options']['mimes'] ?? [] ) );
    $detected_mime = metis_detect_file_mime_type( $tmp );
    if ( $detected_mime === '' ) {
        return [ 'error' => 'Unable to determine uploaded file type.' ];
    }
    if ( ! in_array( $detected_mime, $normalized_mimes, true ) ) {
        return [ 'error' => 'Uploaded file type is not allowed.' ];
    }

    $uploads = metis_upload_dir( $detected_mime );
    if ( ! empty( $uploads['error'] ) ) {
        return [ 'error' => (string) $uploads['error'] ];
    }

    $filename = metis_generate_upload_filename( (string) $file['name'], $detected_mime, $normalized_mimes );
    $destination = metis_trailingslashit( (string) $uploads['path'] ) . $filename;
    $path_info = pathinfo( $destination );
    $counter = 1;
    while ( is_file( $destination ) ) {
        $base = (string) ( $path_info['filename'] ?? 'upload' );
        $ext = isset( $path_info['extension'] ) ? '.' . $path_info['extension'] : '';
        $destination = metis_trailingslashit( (string) $uploads['path'] ) . $base . '-' . $counter . $ext;
        $counter++;
    }

    $moved = $is_uploaded_file ? @move_uploaded_file( $tmp, $destination ) : @rename( $tmp, $destination );
    if ( ! $moved && ! @copy( $tmp, $destination ) ) {
        return [ 'error' => 'Failed to move uploaded file.' ];
    }

    @chmod( $destination, 0644 );

    $uploaded_by = function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : null;
    $media = metis_media_register_file(
        $destination,
        (string) ( $file['name'] ?? basename( $destination ) ),
        $detected_mime,
        (int) @filesize( $destination ),
        $uploaded_by
    );

    return [
        'file' => $destination,
        'url' => $media['url'] ?? ( metis_trailingslashit( (string) $uploads['url'] ) . basename( $destination ) ),
        'token' => $media['token'] ?? null,
        'type' => $detected_mime,
    ];
}
