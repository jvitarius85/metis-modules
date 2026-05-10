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

function metis_media_storage_roots( bool $include_legacy = true ): array {
    $root = rtrim( (string) METIS_PATH, '/\\' );
    $roots = [
        'public' => $root . '/storage/public-media',
        'protected' => $root . '/storage/protected-media',
        'private' => $root . '/storage/private-records',
    ];

    if ( $include_legacy ) {
        $roots['legacy_uploads'] = $root . '/storage/uploads';
        $roots['legacy_media'] = $root . '/storage/media';
    }

    return $roots;
}

function metis_media_storage_class_for_path( string $absolute_path ): ?array {
    $real_path = realpath( $absolute_path );
    if ( ! is_string( $real_path ) ) {
        return null;
    }

    foreach ( metis_media_storage_roots( true ) as $storage_class => $candidate_root ) {
        $candidate_real = realpath( $candidate_root );
        if ( ! is_string( $candidate_real ) ) {
            continue;
        }

        $normalized_path = rtrim( str_replace( '\\', '/', $real_path ), '/' );
        $normalized_root = rtrim( str_replace( '\\', '/', $candidate_real ), '/' ) . '/';
        if ( str_starts_with( $normalized_path, $normalized_root ) ) {
            return [
                'class' => str_starts_with( $storage_class, 'legacy_' ) ? 'legacy' : $storage_class,
                'root' => rtrim( $candidate_real, '/\\' ),
                'path' => $real_path,
            ];
        }
    }

    return null;
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
        storage_class VARCHAR(32) NOT NULL DEFAULT 'legacy',
        storage_path VARCHAR(512) NOT NULL,
        access_expires_at DATETIME DEFAULT NULL,
        file_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(191) NOT NULL,
        size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        folder_path VARCHAR(255) NOT NULL DEFAULT '',
        category_key VARCHAR(80) NOT NULL DEFAULT '',
        uploaded_by BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY public_token (public_token),
        KEY storage_class (storage_class),
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

    if ( ! in_array( 'storage_class', $existing, true ) ) {
        metis_db()->execute( "ALTER TABLE {$table} ADD COLUMN storage_class VARCHAR(32) NOT NULL DEFAULT 'legacy' AFTER public_token" );
        metis_db()->execute( "ALTER TABLE {$table} ADD KEY storage_class (storage_class)" );
    }

    if ( ! in_array( 'access_expires_at', $existing, true ) ) {
        metis_db()->execute( "ALTER TABLE {$table} ADD COLUMN access_expires_at DATETIME DEFAULT NULL AFTER storage_path" );
    }

    if ( ! in_array( 'category_key', $existing, true ) ) {
        metis_db()->execute( "ALTER TABLE {$table} ADD COLUMN category_key VARCHAR(80) NOT NULL DEFAULT '' AFTER folder_path" );
        metis_db()->execute( "ALTER TABLE {$table} ADD KEY category_key (category_key)" );
    }

    $ready = true;
}

function metis_media_register_file( string $absolute_path, string $file_name, string $mime_type, int $size, ?int $uploaded_by = null, ?int $access_ttl_seconds = null ): ?array {
    if ( ! function_exists( 'metis_db' ) || ! is_file( $absolute_path ) ) {
        return null;
    }

    $storage = metis_media_storage_class_for_path( $absolute_path );
    if ( ! is_array( $storage ) ) {
        return null;
    }

    $real_path = (string) $storage['path'];
    $storage_root = (string) $storage['root'];
    $storage_class = (string) $storage['class'];
    $relative_storage_path = ltrim( str_replace( '\\', '/', substr( $real_path, strlen( $storage_root ) ) ), '/' );
    $public_token = bin2hex( random_bytes( 16 ) );
    $expires_at = null;
    if ( in_array( $storage_class, [ 'protected', 'private' ], true ) && $access_ttl_seconds !== null ) {
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + max( 60, $access_ttl_seconds ) );
    }

    metis_media_ensure_schema();
    $db = metis_db();
    $inserted = $db->insert(
        metis_media_table_name(),
        [
            'public_token' => $public_token,
            'storage_class' => $storage_class,
            'storage_path' => $relative_storage_path,
            'access_expires_at' => $expires_at,
            'file_name' => metis_filename_clean( $file_name !== '' ? $file_name : basename( $real_path ) ),
            'mime_type' => strtolower( trim( $mime_type ) ),
            'size' => max( 0, $size ),
            'uploaded_by' => $uploaded_by,
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ]
    );

    if ( ! $inserted ) {
        return null;
    }

    return [
        'token' => $public_token,
        'url' => metis_home_url( '/media/' . $public_token ),
        'storage_class' => $storage_class,
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
        metis_media_ensure_schema();
        $row = metis_db()->fetchOne(
            'SELECT id, public_token, storage_class, storage_path, access_expires_at, file_name, mime_type, size, folder_path, category_key, uploaded_by, created_at FROM ' . metis_media_table_name() . ' WHERE public_token = %s LIMIT 1',
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
    $basedir = rtrim( (string) METIS_PATH, '/\\' ) . '/storage/public-media';
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

function metis_uploads_destroy_gd_image( $image ): void {
    if ( PHP_VERSION_ID >= 80000 || ! is_resource( $image ) || ! function_exists( 'imagedestroy' ) ) {
        return;
    }

    @imagedestroy( $image );
}

function metis_optimize_uploaded_image_file( string $path, string $mime_type, array $overrides = [] ): array {
    if ( empty( $overrides['optimize_images'] ) || ! is_file( $path ) ) {
        return [ 'optimized' => false ];
    }

    $mime_type = strtolower( trim( $mime_type ) );
    if ( ! in_array( $mime_type, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
        return [ 'optimized' => false ];
    }

    if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagecopyresampled' ) ) {
        return [ 'optimized' => false ];
    }

    $info = @getimagesize( $path );
    $width = is_array( $info ) ? (int) ( $info[0] ?? 0 ) : 0;
    $height = is_array( $info ) ? (int) ( $info[1] ?? 0 ) : 0;
    if ( $width < 1 || $height < 1 ) {
        return [ 'optimized' => false ];
    }

    $max_dimension = isset( $overrides['image_max_dimension'] ) ? (int) $overrides['image_max_dimension'] : 2400;
    $max_dimension = max( 640, min( 6000, $max_dimension ) );
    $quality = isset( $overrides['image_quality'] ) ? (int) $overrides['image_quality'] : 82;
    $quality = max( 50, min( 92, $quality ) );

    $source = null;
    if ( $mime_type === 'image/jpeg' && function_exists( 'imagecreatefromjpeg' ) ) {
        $source = @imagecreatefromjpeg( $path );
    } elseif ( $mime_type === 'image/png' && function_exists( 'imagecreatefrompng' ) ) {
        $source = @imagecreatefrompng( $path );
    } elseif ( $mime_type === 'image/webp' && function_exists( 'imagecreatefromwebp' ) && function_exists( 'imagewebp' ) ) {
        $source = @imagecreatefromwebp( $path );
    }

    if ( ! is_resource( $source ) && ! ( $source instanceof \GdImage ) ) {
        return [ 'optimized' => false ];
    }

    $scale = min( 1.0, $max_dimension / max( $width, $height ) );
    $target_width = max( 1, (int) round( $width * $scale ) );
    $target_height = max( 1, (int) round( $height * $scale ) );
    $target = imagecreatetruecolor( $target_width, $target_height );
    if ( ! $target ) {
        metis_uploads_destroy_gd_image( $source );
        return [ 'optimized' => false ];
    }

    if ( $mime_type === 'image/png' || $mime_type === 'image/webp' ) {
        imagealphablending( $target, false );
        imagesavealpha( $target, true );
        $transparent = imagecolorallocatealpha( $target, 0, 0, 0, 127 );
        if ( $transparent !== false ) {
            imagefill( $target, 0, 0, $transparent );
        }
    } else {
        imagealphablending( $target, true );
        imagesavealpha( $target, false );
        $white = imagecolorallocate( $target, 255, 255, 255 );
        if ( $white !== false ) {
            imagefill( $target, 0, 0, $white );
        }
    }

    imagecopyresampled( $target, $source, 0, 0, 0, 0, $target_width, $target_height, $width, $height );

    $tmp = tempnam( dirname( $path ), 'metis-img-' );
    if ( ! is_string( $tmp ) || $tmp === '' ) {
        metis_uploads_destroy_gd_image( $source );
        metis_uploads_destroy_gd_image( $target );
        return [ 'optimized' => false ];
    }

    $saved = false;
    if ( $mime_type === 'image/jpeg' && function_exists( 'imagejpeg' ) ) {
        if ( function_exists( 'imageinterlace' ) ) {
            imageinterlace( $target, true );
        }
        $saved = imagejpeg( $target, $tmp, $quality );
    } elseif ( $mime_type === 'image/png' && function_exists( 'imagepng' ) ) {
        $saved = imagepng( $target, $tmp, 6 );
    } elseif ( $mime_type === 'image/webp' && function_exists( 'imagewebp' ) ) {
        $saved = imagewebp( $target, $tmp, $quality );
    }

    metis_uploads_destroy_gd_image( $source );
    metis_uploads_destroy_gd_image( $target );

    if ( ! $saved || ! is_file( $tmp ) ) {
        @unlink( $tmp );
        return [ 'optimized' => false ];
    }

    $original_size = (int) @filesize( $path );
    $optimized_size = (int) @filesize( $tmp );
    $resized = $target_width !== $width || $target_height !== $height;
    if ( $optimized_size < 1 || ( ! $resized && $original_size > 0 && $optimized_size >= $original_size ) ) {
        @unlink( $tmp );
        return [
            'optimized' => false,
            'width' => $width,
            'height' => $height,
            'size' => $original_size,
        ];
    }

    if ( ! @rename( $tmp, $path ) ) {
        if ( ! @copy( $tmp, $path ) ) {
            @unlink( $tmp );
            return [ 'optimized' => false ];
        }
        @unlink( $tmp );
    }
    @chmod( $path, 0644 );

    return [
        'optimized' => true,
        'original_size' => $original_size,
        'optimized_size' => (int) @filesize( $path ),
        'original_width' => $width,
        'original_height' => $height,
        'width' => $target_width,
        'height' => $target_height,
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
    $optimization = metis_optimize_uploaded_image_file( $destination, $detected_mime, $overrides );

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
        'optimized' => ! empty( $optimization['optimized'] ),
        'optimization' => $optimization,
    ];
}
