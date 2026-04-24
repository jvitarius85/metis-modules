<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class ErrorContext {
    /** @var array<string, mixed> */
    private array $data;

    public function __construct( array $data = [] ) {
        $defaults = [
            'trace_id' => '',
            'timestamp' => gmdate( 'c' ),
            'classification' => ErrorClassifier::SYSTEM_ERROR,
            'severity' => 'error',
            'status_code' => 500,
            'recoverable' => false,
            'retryable' => false,
            'background_repair_allowed' => false,
            'response_type' => 'html',
            'request_type' => 'html',
            'request_method' => (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ),
            'request_uri' => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
            'route' => '',
            'module' => '',
            'service' => '',
            'boundary' => '',
            'message' => 'An unexpected error occurred.',
            'safe_message' => 'Something went wrong while processing the request.',
            'exception_class' => '',
            'exception_message' => '',
            'user_id' => 0,
            'degraded' => false,
            'recovery_attempted' => false,
            'recovery_result' => 'none',
            'final_response_type' => '',
            'fatal' => false,
            'security_sensitive' => false,
            'headers' => [],
            'meta' => [],
            'throwable' => null,
        ];

        $this->data = array_replace( $defaults, $data );
    }

    public function get( string $key, mixed $default = null ): mixed {
        return $this->data[ $key ] ?? $default;
    }

    public function set( string $key, mixed $value ): self {
        $this->data[ $key ] = $value;
        return $this;
    }

    public function merge( array $data ): self {
        $this->data = array_replace( $this->data, $data );
        return $this;
    }

    public function traceId(): string {
        return (string) $this->get( 'trace_id', '' );
    }

    public function throwable(): ?\Throwable {
        $throwable = $this->get( 'throwable' );
        return $throwable instanceof \Throwable ? $throwable : null;
    }

    public function statusCode(): int {
        return (int) $this->get( 'status_code', 500 );
    }

    public function classification(): string {
        return (string) $this->get( 'classification', ErrorClassifier::SYSTEM_ERROR );
    }

    public function severity(): string {
        return (string) $this->get( 'severity', 'error' );
    }

    public function responseType(): string {
        return (string) $this->get( 'response_type', 'html' );
    }

    public function requestType(): string {
        return (string) $this->get( 'request_type', 'html' );
    }

    public function meta(): array {
        $meta = $this->get( 'meta', [] );
        return is_array( $meta ) ? $meta : [];
    }

    public function isRecoverable(): bool {
        return (bool) $this->get( 'recoverable', false );
    }

    public function isRetryable(): bool {
        return (bool) $this->get( 'retryable', false );
    }

    public function isSecuritySensitive(): bool {
        return (bool) $this->get( 'security_sensitive', false );
    }

    public function toArray(): array {
        return $this->data;
    }

    public function toLogArray(): array {
        $data = $this->data;
        $throwable = $this->throwable();

        unset( $data['throwable'] );
        $data['message_summary'] = (string) $data['message'];
        $data['exception_message'] = (string) $data['exception_message'];

        if ( $throwable instanceof \Throwable ) {
            $data['stack_trace'] = $throwable->getTraceAsString();
        }

        return $data;
    }
}
