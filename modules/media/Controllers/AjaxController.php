<?php
declare(strict_types=1);

namespace Metis\Modules\Media\Controllers;

use Metis\Modules\Media\MediaLibraryService;
use Metis\Modules\Media\Policies\MediaPolicy;
use Metis\Modules\Media\Requests\DeleteMediaRequest;
use Metis\Modules\Media\Requests\ListItemsRequest;
use Metis\Modules\Media\Requests\UpdateMetadataRequest;
use Metis\Modules\Media\Requests\UploadMediaRequest;
use Metis\Modules\Media\Services\MediaMutationService;

final class AjaxController {
    public static function upload(): void {
        self::verifyNonce();
        self::requireManage();

        if ( empty( \metis_request_files()['file'] ) || ! is_array( \metis_request_files()['file'] ) ) {
            \metis_runtime_send_json_error( 'File is required.', 400 );
        }

        $request = UploadMediaRequest::fromGlobals();
        $result = MediaMutationService::upload( \metis_request_files()['file'], $request->folderPath(), $request->categoryKey() );
        if ( empty( $result['ok'] ) ) {
            \metis_runtime_send_json_error( $result['message'] ?? 'Upload failed.', (int) ( $result['status'] ?? 500 ) );
        }

        unset( $result['ok'] );
        \metis_runtime_send_json_success( $result );
    }

    public static function list(): void {
        self::verifyNonce();
        self::requireView();

        $request = ListItemsRequest::fromGlobals();
        $items = MediaLibraryService::listItems(
            $request->search(),
            $request->type(),
            $request->folder(),
            $request->category(),
            $request->sort(),
            $request->limit()
        );

        \metis_runtime_send_json_success( [ 'items' => $items ] );
    }

    public static function facets(): void {
        self::verifyNonce();
        self::requireView();

        \metis_runtime_send_json_success( MediaLibraryService::facets() );
    }

    public static function updateMeta(): void {
        self::verifyNonce();
        self::requireManage();

        $request = UpdateMetadataRequest::fromGlobals();
        if ( ! self::isValidToken( $request->token() ) ) {
            \metis_runtime_send_json_error( 'Invalid media token.', 400 );
        }

        $result = MediaMutationService::updateMetadata( $request->token(), $request->folderPath(), $request->categoryKey() );
        if ( empty( $result['ok'] ) ) {
            \metis_runtime_send_json_error( $result['message'] ?? 'Unable to update media metadata.', (int) ( $result['status'] ?? 500 ) );
        }

        \metis_runtime_send_json_success( [ 'updated' => true ] );
    }

    public static function delete(): void {
        self::verifyNonce();
        self::requireDelete();

        $request = DeleteMediaRequest::fromGlobals();
        if ( ! self::isValidToken( $request->token() ) ) {
            \metis_runtime_send_json_error( 'Invalid media token.', 400 );
        }

        $result = MediaMutationService::deleteByToken( $request->token() );
        if ( empty( $result['ok'] ) ) {
            \metis_runtime_send_json_error( $result['message'] ?? 'Failed to delete media item.', (int) ( $result['status'] ?? 500 ) );
        }

        \metis_runtime_send_json_success( [ 'deleted' => true ] );
    }

    private static function verifyNonce(): void {
        $valid = \metis_check_ajax_referer( 'metis_media', 'nonce', false )
            || \metis_check_ajax_referer( 'metis_core', 'nonce', false );

        if ( ! $valid ) {
            \metis_runtime_send_json_error( 'Invalid nonce.', 403 );
        }
    }

    private static function requireView(): void {
        if ( ! MediaPolicy::canView() ) {
            \metis_runtime_send_json_error( 'Unauthorized', 403 );
        }
    }

    private static function requireManage(): void {
        if ( ! MediaPolicy::canManage() ) {
            \metis_runtime_send_json_error( 'Unauthorized', 403 );
        }
    }

    private static function requireDelete(): void {
        if ( ! MediaPolicy::canDelete() ) {
            \metis_runtime_send_json_error( 'Unauthorized', 403 );
        }
    }

    private static function isValidToken( string $token ): bool {
        return $token !== '' && preg_match( '/^[a-f0-9]{24,64}$/', $token ) === 1;
    }
}
