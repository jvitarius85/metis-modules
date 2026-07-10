<?php
declare(strict_types=1);

namespace Metis\Modules\Resources\Controllers;

use Metis\Modules\Resources\Policies\ResourcesPolicy;
use Metis\Modules\Resources\Repository;
use Metis\Modules\Resources\Requests\DeleteRecordRequest;
use Metis\Modules\Resources\Requests\SavePayloadRequest;

final class AjaxController {
    public static function saveType(): void {
        \metis_resources_ajax_verify_nonce( 'metis_resources_type_save' );
        if ( ! ResourcesPolicy::canManage() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $request = SavePayloadRequest::forKey( 'type' );
        $result = Repository::saveType( $request->payload(), $request->userId() );
        \metis_resources_ajax_send_result( $result, 'Type save failed.' );
    }

    public static function saveCategory(): void {
        \metis_resources_ajax_verify_nonce( 'metis_resources_category_save' );
        if ( ! ResourcesPolicy::canManage() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $request = SavePayloadRequest::forKey( 'category' );
        $result = Repository::saveCategory( $request->payload(), $request->userId() );
        \metis_resources_ajax_send_result( $result, 'Category save failed.' );
    }

    public static function saveTag(): void {
        \metis_resources_ajax_verify_nonce( 'metis_resources_tag_save' );
        if ( ! ResourcesPolicy::canManage() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $request = SavePayloadRequest::forKey( 'tag' );
        $result = Repository::saveTag( $request->payload(), $request->userId() );
        \metis_resources_ajax_send_result( $result, 'Tag save failed.' );
    }

    public static function saveResource(): void {
        \metis_resources_ajax_verify_nonce( 'metis_resources_resource_save' );
        if ( ! ResourcesPolicy::canManage() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $request = SavePayloadRequest::forKey( 'resource' );
        $result = Repository::saveResource( $request->payload(), $request->files(), $request->userId() );
        \metis_resources_ajax_send_result( $result, 'Resource save failed.' );
    }

    public static function deleteType(): void {
        self::deleteRecord( 'metis_resources_type_delete', 'type', 'Type delete failed.' );
    }

    public static function deleteCategory(): void {
        self::deleteRecord( 'metis_resources_category_delete', 'category', 'Category delete failed.' );
    }

    public static function deleteTag(): void {
        self::deleteRecord( 'metis_resources_tag_delete', 'tag', 'Tag delete failed.' );
    }

    public static function deleteResource(): void {
        self::deleteRecord( 'metis_resources_resource_delete', 'resource', 'Resource delete failed.' );
    }

    private static function deleteRecord( string $nonceAction, string $type, string $fallback ): void {
        \metis_resources_ajax_verify_nonce( $nonceAction );
        if ( ! ResourcesPolicy::canDelete() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $request = DeleteRecordRequest::fromPostKey();
        $result = Repository::deleteRecord( $type, $request->recordId() );
        \metis_resources_ajax_send_result( $result, $fallback );
    }
}
