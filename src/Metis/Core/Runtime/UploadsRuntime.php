<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_runtime_make_dir' ) ) {
    function metis_runtime_make_dir( string $target ): bool {
        return is_dir( $target ) || mkdir( $target, 0775, true );
    }
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
        $extension = strtolower( pathinfo( sanitize_file_name( $original_name ), PATHINFO_EXTENSION ) );
    }

    $filename = bin2hex( random_bytes( 16 ) );
    if ( $extension !== '' ) {
        $filename .= '.' . $extension;
    }

    return $filename;
}

function metis_upload_dir(): array {
    $subdir = '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
    $basedir = dirname( __DIR__, 4 ) . '/storage/uploads';
    $baseurl = metis_home_url( '/storage/uploads' );
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
    $uploads = metis_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
        return [
            'file' => '',
            'url' => '',
            'error' => (string) $uploads['error'],
        ];
    }

    $detected_mime = metis_detect_binary_mime_type( $bits );
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

    return [
        'file' => $path,
        'url' => $url,
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

    $uploads = metis_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
        return [
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => (string) $uploads['error'],
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

    return [
        'file' => $path,
        'url' => $url,
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
        return [ 'error' => (string) ( $validation['error'] ?? 'Uploaded file is not allowed.' ) ];
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

    $uploads = metis_upload_dir();
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

    return [
        'file' => $destination,
        'url' => metis_trailingslashit( (string) $uploads['url'] ) . basename( $destination ),
        'type' => $detected_mime,
    ];
}
