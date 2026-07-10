<?php
declare(strict_types=1);

namespace Metis\Modules\Media\Services;

use Metis\Modules\Media\MediaLibraryService;

final class MediaMutationService {
    public static function upload( array $file, string $folder_path, string $category_key ): array {
        $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
        if ( $size < 1 ) {
            return [ 'ok' => false, 'status' => 400, 'message' => 'Uploaded file is empty.' ];
        }
        if ( $size > 25 * 1024 * 1024 ) {
            return [ 'ok' => false, 'status' => 400, 'message' => 'File must be 25MB or smaller.' ];
        }

        $uploaded = \metis_handle_upload( $file, [
            'policy' => 'media_library',
            'test_form' => false,
            'optimize_images' => true,
            'image_max_dimension' => 2400,
            'image_quality' => 82,
        ] );

        if ( ! is_array( $uploaded ) || ! empty( $uploaded['error'] ) ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Upload failed.' ];
        }

        $token = isset( $uploaded['token'] ) ? \metis_text_clean( (string) $uploaded['token'] ) : '';
        if ( $token !== '' && function_exists( 'metis_media_update_metadata' ) ) {
            \metis_media_update_metadata( $token, $folder_path, $category_key );
        }

        return [
            'ok' => true,
            'url' => isset( $uploaded['url'] ) ? \metis_url_clean( (string) $uploaded['url'] ) : '',
            'token' => $token,
            'file_name' => \metis_filename_clean( (string) ( $file['name'] ?? '' ) ),
            'size' => isset( $uploaded['optimization']['optimized_size'] )
                ? (int) $uploaded['optimization']['optimized_size']
                : $size,
            'optimized' => ! empty( $uploaded['optimized'] ),
        ];
    }

    public static function updateMetadata( string $token, string $folder_path, string $category_key ): array {
        if ( ! function_exists( 'metis_media_update_metadata' ) || ! \metis_media_update_metadata( $token, $folder_path, $category_key ) ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Unable to update media metadata.' ];
        }

        return [ 'ok' => true, 'updated' => true ];
    }

    public static function deleteByToken( string $token ): array {
        $row = MediaLibraryService::findByToken( $token );
        if ( ! is_array( $row ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Media item not found.' ];
        }

        $relative_path = ltrim( (string) ( $row['storage_path'] ?? '' ), '/' );
        $storage_class = \metis_key_clean( (string) ( $row['storage_class'] ?? 'legacy' ) );
        $resolved = function_exists( 'metis_media_resolve_registered_path' )
            ? \metis_media_resolve_registered_path( $storage_class, $relative_path, true )
            : null;
        if ( is_array( $resolved ) && is_file( (string) ( $resolved['path'] ?? '' ) ) ) {
            @unlink( (string) $resolved['path'] );
            if ( function_exists( 'metis_audit_log_activity' ) ) {
                \metis_audit_log_activity( 'media_storage_deleted', [
                    'module' => 'media',
                    'resource' => [ 'type' => 'media', 'id' => $token ],
                    'context' => [ 'storage_class' => $storage_class ],
                ] );
            }
        }

        $deleted = \metis_db()->delete(
            function_exists( 'metis_media_table_name' ) ? \metis_media_table_name() : 'metis_media_files',
            [ 'id' => (int) ( $row['id'] ?? 0 ) ],
            [ '%d' ]
        );
        if ( ! $deleted ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Failed to delete media item.' ];
        }

        return [ 'ok' => true, 'deleted' => true ];
    }
}
