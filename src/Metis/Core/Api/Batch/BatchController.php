<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

final class Metis_Batch_Controller {
    private Metis_Batch_Validator $validator;
    private Metis_Batch_Processor $processor;

    public function __construct( ?Metis_Batch_Validator $validator = null, ?Metis_Batch_Processor $processor = null ) {
        $this->validator = $validator instanceof Metis_Batch_Validator ? $validator : new Metis_Batch_Validator();
        $this->processor = $processor instanceof Metis_Batch_Processor ? $processor : new Metis_Batch_Processor();
    }

    public function handle( Metis_Http_Request $request ): Metis_Http_Response {
        $request_id = metis_audit_request_id();
        $endpoint   = '/' . ltrim( (string) $request->path(), '/' );
        $route_name = 'batch.api';

        if ( strtoupper( $request->method() ) !== 'POST' ) {
            return $this->failure_response( 405, 'Method not allowed.', 'invalid_method', '', '', $request_id, $endpoint, $route_name );
        }

        $route_module = metis_key_clean( (string) $request->attribute( 'batch_module', '' ) );
        $route_action = metis_key_clean( (string) $request->attribute( 'batch_action', '' ) );
        if ( $route_module === '' || $route_action === '' ) {
            return $this->failure_response( 404, 'Invalid batch route.', 'batch_route_invalid', $route_module, $route_action, $request_id, $endpoint, $route_name );
        }

        $context = $this->validator->resolve_context( $route_module, $route_action );
        if ( ! is_array( $context ) ) {
            return $this->failure_response( 404, 'Batch context is not configured.', 'batch_context_missing', $route_module, $route_action, $request_id, $endpoint, $route_name );
        }

        if ( ! $this->check_permission( $context ) ) {
            return $this->failure_response( 403, 'Unauthorized.', 'permission_denied', $route_module, $route_action, $request_id, $endpoint, $route_name );
        }

        $payload = $this->parse_payload( $request );
        if ( ! is_array( $payload ) ) {
            return $this->failure_response( 400, 'Invalid request payload.', 'invalid_payload', $route_module, $route_action, $request_id, $endpoint, $route_name );
        }

        $nonce = metis_text_clean( (string) ( $payload['nonce'] ?? $payload['metis_action_nonce'] ?? '' ) );
        if ( ! $this->verify_nonce( $nonce, $context ) ) {
            return $this->failure_response( 403, 'Invalid nonce.', 'invalid_nonce', $route_module, $route_action, $request_id, $endpoint, $route_name );
        }

        $payload_module = metis_key_clean( (string) ( $payload['module'] ?? $route_module ) );
        $payload_action = metis_key_clean( (string) ( $payload['action'] ?? $route_action ) );
        if ( $payload_module !== $route_module || $payload_action !== $route_action ) {
            return $this->failure_response( 400, 'Route and payload mismatch.', 'route_payload_mismatch', $route_module, $route_action, $request_id, $endpoint, $route_name );
        }

        $rows = (array) ( $payload['rows'] ?? [] );
        if ( $rows === [] ) {
            return $this->failure_response( 400, 'No rows submitted.', 'no_rows_submitted', $route_module, $route_action, $request_id, $endpoint, $route_name );
        }

        $validation = $this->validator->validate_rows( $rows, $context );
        $validated_rows = (array) ( $validation['rows'] ?? [] );
        $valid_rows = (array) ( $validation['valid_rows'] ?? [] );
        $invalid_count = (int) ( $validation['invalid_count'] ?? 0 );

        $process_rows = [];
        foreach ( $validated_rows as $entry ) {
            if ( (string) ( $entry['status'] ?? '' ) !== 'valid' ) {
                continue;
            }
            $process_rows[] = $entry;
        }

        $processed = $this->processor->process( $process_rows, $context );

        $results = (array) ( $processed['results'] ?? [] );
        foreach ( $validated_rows as $entry ) {
            if ( (string) ( $entry['status'] ?? '' ) === 'valid' ) {
                continue;
            }

            $results[] = [
                'row_id' => (string) ( $entry['row_id'] ?? '' ),
                'status' => 'error',
                'message' => implode( ' ', (array) ( $entry['errors'] ?? [ 'Invalid row.' ] ) ),
            ];
        }

        $saved = (int) ( $processed['saved'] ?? 0 );
        $failed = (int) ( $processed['failed'] ?? 0 ) + $invalid_count;

        $response = Metis_Batch_Response::build(
            true,
            $saved,
            $failed,
            $results,
            count( $rows ),
            count( $valid_rows ),
            $invalid_count
        );

        metis_audit_log_activity( 'batch_entry_processed', [
            'module' => $route_module,
            'resource' => [
                'type' => 'batch_action',
                'id' => $route_action,
                'label' => $route_module . ':' . $route_action,
            ],
            'context' => [
                'total_received' => count( $rows ),
                'valid_submitted' => count( $valid_rows ),
                'invalid_skipped' => $invalid_count,
                'saved' => $saved,
                'failed' => $failed,
                'route' => $route_name,
                'endpoint' => $endpoint,
                'request_id' => $request_id,
            ],
        ] );

        if ( $failed > 0 ) {
            metis_audit_log_security( 'batch_action_failed', [
                'module'   => $route_module,
                'severity' => $saved > 0 ? 'warning' : 'error',
                'outcome'  => 'failed',
                'resource' => [
                    'type'  => 'batch_action',
                    'id'    => $route_action,
                    'label' => $route_module . ':' . $route_action,
                ],
                'context'  => [
                    'route'       => $route_name,
                    'endpoint'    => $endpoint,
                    'status_code' => 200,
                    'saved'       => $saved,
                    'failed'      => $failed,
                    'request_id'  => $request_id,
                ],
            ] );
        }

        return Metis_Http_Response::json( $response, 200, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Metis-Request-Id' => $request_id,
        ] );
    }

    private function failure_response(
        int $status,
        string $message,
        string $error_code,
        string $module,
        string $action,
        string $request_id,
        string $endpoint,
        string $route_name
    ): Metis_Http_Response {
        metis_audit_log_security( 'batch_action_failed', [
            'module'   => metis_key_clean( $module ),
            'severity' => $status >= 500 ? 'error' : 'warning',
            'outcome'  => 'blocked',
            'resource' => [
                'type'  => 'batch_action',
                'id'    => metis_key_clean( $action ),
                'label' => metis_key_clean( $error_code ),
            ],
            'context'  => [
                'route'         => $route_name,
                'endpoint'      => $endpoint,
                'status_code'   => $status,
                'error_code'    => metis_key_clean( $error_code ),
                'error_message' => $message,
                'request_id'    => $request_id,
            ],
        ] );

        return Metis_Http_Response::json(
            [ 'success' => false, 'message' => $message, 'code' => metis_key_clean( $error_code ) ],
            $status,
            [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Metis-Request-Id' => $request_id,
            ]
        );
    }

    private function parse_payload( Metis_Http_Request $request ): ?array {
        $input = $request->input();
        if ( isset( $input['rows'] ) && is_array( $input['rows'] ) ) {
            return $input;
        }

        $body = trim( $request->body() );
        if ( $body !== '' ) {
            $decoded = json_decode( $body, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        return null;
    }

    private function check_permission( array $context ): bool {
        $callback = (string) ( $context['permission_callback'] ?? '' );
        if ( $callback === '' || ! function_exists( $callback ) ) {
            return false;
        }

        return (bool) $callback();
    }

    private function verify_nonce( string $nonce, array $context ): bool {
        if ( $nonce === '' ) {
            return false;
        }

        $actions = [ 'metis_batch_api', (string) ( $context['nonce_action'] ?? '' ) ];
        foreach ( $actions as $action ) {
            $action = trim( $action );
            if ( $action === '' ) {
                continue;
            }

            if ( metis_runtime_verify_nonce( $nonce, $action ) ) {
                return true;
            }
        }

        return false;
    }
}
