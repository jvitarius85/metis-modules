<?php
declare(strict_types=1);

namespace Metis\Modules\Testimonies\Controllers;

use Metis\Modules\Testimonies\Policies\TestimoniesPolicy;
use Metis\Modules\Testimonies\Repository;
use Metis\Modules\Testimonies\Requests\DeleteRecordRequest;
use Metis\Modules\Testimonies\Requests\SavePayloadRequest;

final class AjaxController {
    public static function saveTestimony(): void {
        \metis_testimonies_ajax_verify_nonce( 'metis_testimonies_save' );
        if ( ! TestimoniesPolicy::canManage() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $request = SavePayloadRequest::forKey( 'testimony' );
        $result = Repository::saveTestimony( $request->payload(), $request->userId() );
        \metis_testimonies_ajax_send_result( $result, 'Testimony save failed.' );
    }

    public static function deleteTestimony(): void {
        \metis_testimonies_ajax_verify_nonce( 'metis_testimonies_delete' );
        if ( ! TestimoniesPolicy::canDelete() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $request = DeleteRecordRequest::fromPostKey( 'testimony_id' );
        $result = Repository::deleteTestimony( $request->recordId() );
        \metis_testimonies_ajax_send_result( $result, 'Testimony delete failed.' );
    }

    public static function saveCategory(): void {
        \metis_testimonies_ajax_verify_nonce( 'metis_testimony_categories_save' );
        if ( ! TestimoniesPolicy::canManage() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $request = SavePayloadRequest::forKey( 'category' );
        $result = Repository::saveCategory( $request->payload(), $request->userId() );
        \metis_testimonies_ajax_send_result( $result, 'Category save failed.' );
    }

    public static function deleteCategory(): void {
        \metis_testimonies_ajax_verify_nonce( 'metis_testimony_categories_delete' );
        if ( ! TestimoniesPolicy::canDelete() ) {
            \metis_runtime_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $request = DeleteRecordRequest::fromPostKey( 'category_id' );
        $result = Repository::deleteCategory( $request->recordId() );
        \metis_testimonies_ajax_send_result( $result, 'Category delete failed.' );
    }
}
