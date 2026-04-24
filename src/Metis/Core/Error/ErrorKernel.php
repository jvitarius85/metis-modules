<?php
declare(strict_types=1);

namespace Metis\Core\Error;

use Metis\Core\Cache\CacheService;
use Metis\Http\Request;
use Metis\Http\Response;

final class ErrorKernel {
    private bool $installed = false;
    private bool $handling = false;
    private ?Request $request = null;

    public function __construct(
        private readonly TraceIdGenerator $traceIds,
        private readonly ErrorClassifier $classifier,
        private readonly ErrorLogger $logger,
        private readonly RecoveryManager $recovery,
        private readonly ErrorResponder $responder,
        private readonly CircuitBreaker $circuits
    ) {}

    public function install(): void {
        if ( $this->installed ) {
            return;
        }

        set_error_handler( new ErrorHandler( $this ) );
        set_exception_handler( new ExceptionHandler( $this ) );
        register_shutdown_function( new ShutdownHandler( $this ) );
        $this->installed = true;
    }

    public function execute( callable $callback ): mixed {
        try {
            return $callback();
        } catch ( \Throwable $throwable ) {
            $response = $this->handleThrowable( $throwable );
            if ( $response instanceof Response ) {
                $this->emitResponse( $response );
                exit;
            }

            throw $throwable;
        }
    }

    public function captureRequest( ?Request $request ): void {
        $this->request = $request;
    }

    public function contextForThrowable( \Throwable $throwable, array $context = [] ): ErrorContext {
        $request = $context['request'] ?? $this->request;
        $requestType = $this->detectRequestType( $request );
        $responseType = $requestType;
        $statusCode = (int) ( $context['status_code'] ?? 0 );

        $classified = $this->classifier->classifyThrowable( $throwable, $context + [ 'status_code' => $statusCode ] );
        $safeMessage = $this->safeMessageFor( $classified['classification'], $context );

        return new ErrorContext( array_replace(
            $classified,
            [
                'trace_id' => $this->traceIds->generate(),
                'request_type' => $requestType,
                'response_type' => $responseType,
                'request_uri' => $request instanceof Request ? $request->uri() : (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
                'request_method' => $request instanceof Request ? $request->method() : (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ),
                'route' => $request instanceof Request ? (string) $request->attribute( 'route_name', '' ) : (string) ( $context['route'] ?? '' ),
                'message' => $throwable->getMessage(),
                'safe_message' => $safeMessage,
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
                'throwable' => $throwable,
                'status_code' => $statusCode > 0 ? $statusCode : (int) $classified['status_code'],
                'module' => (string) ( $context['module'] ?? '' ),
                'service' => (string) ( $context['service'] ?? '' ),
                'boundary' => (string) ( $context['boundary'] ?? '' ),
                'fatal' => (bool) ( $context['fatal'] ?? false ),
                'user_id' => function_exists( 'metis_current_user_id' ) ? (int) \metis_current_user_id() : 0,
                'meta' => (array) ( $context['meta'] ?? [] ),
                'headers' => (array) ( $context['headers'] ?? [] ),
            ]
        ) );
    }

    public function handleThrowable( \Throwable $throwable, array $context = [] ): Response|array|null {
        if ( $this->handling ) {
            return null;
        }

        $this->handling = true;

        try {
            $error = $this->contextForThrowable( $throwable, $context );
            $error->set( 'final_response_type', $error->responseType() );

            if ( $error->isRecoverable() && ! $error->isSecuritySensitive() ) {
                $result = $this->recovery->attempt( $error, $context );
                if ( (bool) ( $result['recovered'] ?? false ) && array_key_exists( 'response', $result ) ) {
                    $this->recordHealthSignals( $error );
                    return $result['response'];
                }
            }

            $this->logger->log( $error );
            $this->recordHealthSignals( $error );
            $response = $this->responder->respond( $error );
            if ( $response instanceof Response ) {
                return $response;
            }

            return $response;
        } finally {
            $this->handling = false;
        }
    }

    public function handleShutdown(): void {
        $error = error_get_last();
        if ( ! is_array( $error ) ) {
            return;
        }

        if ( ! in_array( (int) $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true ) ) {
            return;
        }

        $classified = $this->classifier->classifyShutdownError( $error, [ 'fatal' => true ] );
        $context = new ErrorContext( array_replace(
            $classified,
            [
                'trace_id' => $this->traceIds->generate(),
                'fatal' => true,
                'request_type' => $this->detectRequestType( $this->request ),
                'response_type' => $this->detectRequestType( $this->request ),
                'message' => (string) ( $error['message'] ?? 'Fatal shutdown error.' ),
                'safe_message' => 'Metis could not complete the request.',
                'exception_class' => 'ShutdownError',
                'exception_message' => (string) ( $error['message'] ?? '' ),
                'meta' => [
                    'file' => (string) ( $error['file'] ?? '' ),
                    'line' => (int) ( $error['line'] ?? 0 ),
                ],
            ]
        ) );

        $this->logger->log( $context );
        $this->recordHealthSignals( $context );

        if ( headers_sent() ) {
            return;
        }

        $response = $this->responder->respond( $context );
        if ( $response instanceof Response ) {
            $this->emitResponse( $response );
        }
    }

    public function healthSignals(): array {
        try {
            $signals = CacheService::get( 'error.health.signals' );
            return is_array( $signals ) ? $signals : [];
        } catch ( \Throwable ) {
            return [];
        }
    }

    private function recordHealthSignals( ErrorContext $context ): void {
        $signals = $this->healthSignals();
        $service = (string) $context->get( 'service', '' );
        $module = (string) $context->get( 'module', '' );
        $degraded = (bool) $context->get( 'degraded', false );

        $signals['last_error_trace_id'] = $context->traceId();
        $signals['last_error_at'] = gmdate( 'c' );
        $signals['classification'] = $context->classification();
        $signals['degraded_dependency_state'] = $degraded ? [
            'service' => $service,
            'module' => $module,
            'classification' => $context->classification(),
        ] : ( $signals['degraded_dependency_state'] ?? [] );
        $signals['open_circuits'][ $service !== '' ? $service : 'system' ] = $service !== '' ? $this->circuits->state( $service ) : [];

        try {
            CacheService::set( 'error.health.signals', $signals, 3600 );
        } catch ( \Throwable ) {
        }
    }

    private function detectRequestType( mixed $request ): string {
        if ( PHP_SAPI === 'cli' ) {
            return 'cli';
        }

        if ( $request instanceof Request ) {
            $accept = strtolower( $request->header( 'accept', '' ) );
            $requestedWith = strtolower( $request->header( 'x-requested-with', '' ) );
            if ( str_contains( $accept, 'application/json' ) || $requestedWith === 'xmlhttprequest' ) {
                return str_contains( $accept, 'application/json' ) ? 'json' : 'ajax';
            }
        }

        $accept = strtolower( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) );
        $requestedWith = strtolower( (string) ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) );
        if ( str_contains( $accept, 'application/json' ) ) {
            return 'json';
        }
        if ( $requestedWith === 'xmlhttprequest' ) {
            return 'ajax';
        }

        return 'html';
    }

    private function safeMessageFor( string $classification, array $context ): string {
        return match ( $classification ) {
            ErrorClassifier::SECURITY_ERROR => 'The request could not be authorized.',
            ErrorClassifier::VALIDATION_ERROR => 'The request data was not valid.',
            ErrorClassifier::DATABASE_ERROR => 'Metis is temporarily unable to reach a required data service.',
            ErrorClassifier::CACHE_ERROR => 'Metis is temporarily operating with reduced cache capacity.',
            ErrorClassifier::INTEGRATION_ERROR => 'A dependent service is temporarily unavailable.',
            default => (string) ( $context['safe_message'] ?? 'Metis could not complete the request.' ),
        };
    }

    private function emitResponse( Response $response ): void {
        if ( function_exists( 'metis_send_status' ) ) {
            \metis_send_status( $response->status() );
        } else {
            http_response_code( $response->status() );
        }

        if ( \function_exists( 'metis_runtime_emit_security_headers' ) ) {
            \metis_runtime_emit_security_headers();
        }

        $headers = $response->headers();
        $hasRequestId = false;
        foreach ( array_keys( $headers ) as $name ) {
            if ( strtolower( (string) $name ) === 'x-metis-request-id' ) {
                $hasRequestId = true;
                break;
            }
        }

        if ( ! $hasRequestId && \function_exists( 'metis_audit_request_id' ) ) {
            $requestId = trim( (string) \metis_audit_request_id() );
            if ( $requestId !== '' ) {
                header( 'X-Metis-Request-Id: ' . $requestId, true );
            }
        }

        foreach ( $headers as $name => $value ) {
            header( $name . ': ' . $value, true );
        }

        echo $response->body();
    }
}
