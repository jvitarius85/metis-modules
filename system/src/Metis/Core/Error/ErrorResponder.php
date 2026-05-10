<?php
declare(strict_types=1);

namespace Metis\Core\Error;

use Metis\Http\Response;

// @metis-governance ajax-security: responder formats AJAX failures after upstream nonce, csrf, permission, and SecureEnclave checks.
final class ErrorResponder {
    public function __construct(
        private readonly ErrorPageRenderer $pages
    ) {}

    public function respond( ErrorContext $context ): Response|array {
        $status = $context->statusCode();
        $traceId = $context->traceId();
        $safeMessage = (string) $context->get( 'safe_message', 'Something went wrong while processing the request.' );
        $headers = array_merge(
            [
                'X-Metis-Trace-Id' => $traceId,
                'X-Metis-Request-Id' => $traceId,
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ],
            (array) $context->get( 'headers', [] )
        );

        if ( in_array( $context->responseType(), [ 'json', 'ajax' ], true ) ) {
            return Response::json(
                [
                    'success' => false,
                    'error' => [
                        'message' => $safeMessage,
                        'classification' => $context->classification(),
                        'trace_id' => $traceId,
                    ],
                ],
                $status,
                $headers
            );
        }

        if ( $context->responseType() === 'cli' ) {
            return [
                'status' => $status,
                'trace_id' => $traceId,
                'message' => $safeMessage,
                'classification' => $context->classification(),
            ];
        }

        return Response::html(
            $this->pages->render( $status, $traceId, $safeMessage ),
            $status,
            $headers
        );
    }
}
