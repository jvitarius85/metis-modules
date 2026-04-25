<?php
declare(strict_types=1);

namespace Metis\Core\Error;

final class ErrorLogger {
    public function log( ErrorContext $context ): void {
        $payload = $this->redact( $context->toLogArray() );
        $payload['timestamp'] = gmdate( 'c' );
        $this->auditHandledError( $context, $payload );

        if ( class_exists( 'Metis_Logger' ) ) {
            $summary = (string) ( $payload['message_summary'] ?? 'Handled error' );
            \Metis_Logger::write_level(
                strtoupper( (string) $context->severity() ) === 'WARN' ? 'WARN' : 'ERROR',
                '[trace:' . $context->traceId() . '] ' . $summary,
                [
                    'classification' => $context->classification(),
                    'trace_id' => (string) $context->traceId(),
                    'module' => (string) $context->get( 'module', '' ),
                    'service' => (string) $context->get( 'service', '' ),
                    'route' => (string) $context->get( 'route', '' ),
                    'recovery_attempted' => (bool) $context->get( 'recovery_attempted', false ),
                    'recovery_result' => (string) $context->get( 'recovery_result', 'none' ),
                ]
            );

            // Keep full structured payload in the unified logger file.
            \Metis_Logger::write_level(
                strtoupper( (string) $context->severity() ) === 'WARN' ? 'WARN' : 'ERROR',
                'error.trace',
                $payload
            );
            return;
        }

        $encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES );
        if ( is_string( $encoded ) ) {
            @error_log( '[metis.error.trace] ' . $encoded );
        }
    }

    private function auditHandledError( ErrorContext $context, array $payload ): void {
        if ( ! \function_exists( 'metis_audit_log_security' ) ) {
            return;
        }

        $trace_id = trim( (string) $context->traceId() );
        $route = (string) ( $payload['route'] ?? '' );
        $endpoint = (string) ( $payload['request_uri'] ?? '' );
        $module = (string) ( $payload['module'] ?? '' );
        $classification = (string) $context->classification();
        $status_code = (int) ( $payload['status_code'] ?? $context->statusCode() );
        $safe_message = (string) ( $payload['safe_message'] ?? 'Metis could not complete the request.' );

        \metis_audit_log_security( 'system_error_handled', [
            'module'   => \metis_key_clean( $module ),
            'severity' => $status_code >= 500 ? 'error' : 'warning',
            'outcome'  => 'failed',
            'resource' => [
                'type'  => 'system_error',
                'id'    => \metis_key_clean( $classification ),
                'label' => (string) ( $payload['exception_class'] ?? '' ),
            ],
            'context'  => [
                'route'         => $route,
                'endpoint'      => $endpoint,
                'status_code'   => $status_code,
                'error_code'    => \metis_key_clean( $classification ),
                'error_message' => $safe_message,
                'request_id'    => $trace_id,
            ],
        ] );
    }

    private function redact( array $payload ): array {
        $redacted = [];

        foreach ( $payload as $key => $value ) {
            $normalized = strtolower( (string) $key );
            if ( is_array( $value ) ) {
                $redacted[ $key ] = $this->redact( $value );
                continue;
            }

            if (
                str_contains( $normalized, 'password' )
                || str_contains( $normalized, 'token' )
                || str_contains( $normalized, 'secret' )
                || str_contains( $normalized, 'authorization' )
            ) {
                $redacted[ $key ] = '[redacted]';
                continue;
            }

            if ( $normalized === 'body' || $normalized === 'request_body' ) {
                $redacted[ $key ] = '[omitted]';
                continue;
            }

            $redacted[ $key ] = $value;
        }

        return $redacted;
    }
}
