<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Media\MediaModule::boot();

if ( ! function_exists( 'metis_media_find_by_token' ) ) {
    function metis_media_find_by_token( string $token ): ?array {
        $media = \Metis\Modules\Media\MediaLibraryService::findByToken( $token );
        return is_array( $media ) ? $media : null;
    }
}

if ( ! function_exists( 'metis_media_find_by_filename' ) ) {
    function metis_media_find_by_filename( string $filename ): ?array {
        $media = \Metis\Modules\Media\MediaLibraryService::findByFilename( $filename );
        return is_array( $media ) ? $media : null;
    }
}
