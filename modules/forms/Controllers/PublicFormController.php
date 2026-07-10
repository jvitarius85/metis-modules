<?php
declare(strict_types=1);

namespace Metis\Modules\Forms\Controllers;

use Metis\Core\Error\ErrorPageRenderer;
use Metis\Http\Response;
use Metis\Modules\Forms\FormRenderer;
use Metis\Modules\Forms\FormsModule;
use Metis\Modules\Forms\Repository;
use Metis\Modules\Forms\Requests\PublicFormRequest;

final class PublicFormController {
    public static function handle( \Metis\Http\Request $request ): Response {
        FormsModule::ensureSchema();

        $formRequest = PublicFormRequest::fromRequest( $request );
        $form = Repository::getFormBySlug( $formRequest->slug(), true );
        if ( ! is_array( $form ) ) {
            return self::notFoundResponse();
        }

        if ( $formRequest->isPost() ) {
            $availability = Repository::publicAvailability( $form, $formRequest->payload() );
            if ( empty( $availability['ok'] ) ) {
                return FormsModule::respondPublic( $formRequest->expectsJson(), $form, $availability, (int) ( $availability['status'] ?? 403 ) );
            }

            if ( $formRequest->mode() === 'prepare_payment' ) {
                $result = Repository::preparePublicPayment( $form, $formRequest->payload(), $request->files(), $request->uri() );
            } elseif ( $formRequest->mode() === 'finalize_payment' ) {
                $result = Repository::finalizePaymentSession(
                    $formRequest->paymentSession(),
                    $formRequest->paymentIntentId()
                );
            } else {
                $result = Repository::submitForm( $form, $formRequest->payload(), $request->files(), $request->uri() );
            }

            return FormsModule::respondPublic( $formRequest->expectsJson(), $form, $result, (int) ( $result['status'] ?? 200 ) );
        }

        $availability = Repository::publicAvailability( $form, $formRequest->payload() );
        if ( empty( $availability['ok'] ) ) {
            return FormRenderer::render( $form, $availability );
        }

        $result = [];
        if ( $formRequest->isPaymentReturn() ) {
            $result = Repository::finalizePaymentSession(
                $formRequest->paymentSession(),
                $formRequest->paymentIntent()
            );
        }

        return FormRenderer::render( $form, $result );
    }

    private static function notFoundResponse(): Response {
        $trace_id = function_exists( 'metis_audit_request_id' ) ? (string) \metis_audit_request_id() : '';
        if ( class_exists( ErrorPageRenderer::class ) ) {
            return Response::html(
                ( new ErrorPageRenderer() )->render( 404, $trace_id, 'The requested public form is not published or does not exist.', 'Form Not Found' ),
                404
            );
        }

        return Response::html( '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Form Not Found</title></head><body><main><h1>Form Not Found</h1><p>The requested public form is not published or does not exist.</p></main></body></html>', 404 );
    }
}
