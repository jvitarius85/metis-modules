<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    define( 'METIS_STANDALONE', true );
}

use Metis\Core\Application;

/**
 * Central Hermes enclave execution entrypoint.
 *
 * Hermes code must call this function instead of invoking services directly.
 *
 * @param array<string,mixed> $tool
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function metis_core_enclave_execute_tool( array $tool, array $payload = [], array $options = [] ): array {
    $tool_key = trim( (string) ( $tool['tool_key'] ?? '' ) );
    if ( $tool_key === '' ) {
        return [
            'status' => 'error',
            'error_code' => 'TOOL_NOT_FOUND',
            'message' => 'Tool definition is missing a tool key.',
        ];
    }

    if ( ! function_exists( 'metis_security_enclave' ) ) {
        return [
            'status' => 'read_only',
            'error_code' => 'ENCLAVE_UNAVAILABLE',
            'message' => 'Secure Enclave is unavailable. Hermes is operating in read-only mode.',
        ];
    }

    $operation = trim( (string) ( $tool['enclave_action'] ?? '' ) );
    if ( $operation === '' ) {
        $operation = 'hermes.tool.execute';
    }

    $request = function_exists( 'metis_security_runtime_request_context' )
        ? metis_security_runtime_request_context( [
            'tool_key' => $tool_key,
            'payload' => $payload,
            'options' => $options,
        ] )
        : [
            'actor' => [],
            'meta' => [ 'request_id' => '' ],
            'input' => [
                'tool_key' => $tool_key,
                'payload' => $payload,
                'options' => $options,
            ],
        ];

    try {
        $result = metis_security_enclave()->execute(
            $operation,
            $request,
            static function ( array $input, array $context ) use ( $tool, $tool_key ): array {
                $dispatch = is_array( $tool['dispatch'] ?? null ) ? $tool['dispatch'] : [];
                $service_name = trim( (string) ( $dispatch['service'] ?? '' ) );
                $method = trim( (string) ( $dispatch['method'] ?? '' ) );

                if ( $service_name === '' || $method === '' ) {
                    throw new RuntimeException( sprintf( 'Tool [%s] is missing its dispatch target.', $tool_key ) );
                }

                if ( ! Application::has_service( $service_name ) ) {
                    throw new RuntimeException( sprintf( 'Service [%s] is not available for tool [%s].', $service_name, $tool_key ) );
                }

                $service = Application::service( $service_name );
                if ( ! is_object( $service ) || ! method_exists( $service, $method ) ) {
                    throw new RuntimeException( sprintf( 'Dispatch method [%s::%s] is unavailable.', $service_name, $method ) );
                }

                $arguments = [];
                foreach ( (array) ( $dispatch['arguments'] ?? [] ) as $argument ) {
                    $arguments[] = $argument;
                }

                if ( ! empty( $dispatch['pass_payload'] ) ) {
                    $arguments[] = (array) ( $input['payload'] ?? [] );
                }

                if ( ! empty( $dispatch['pass_context'] ) ) {
                    $arguments[] = $context;
                }

                $output = call_user_func_array( [ $service, $method ], $arguments );

                return is_array( $output )
                    ? $output
                    : [
                        'status' => 'success',
                        'result' => $output,
                    ];
            }
        );
    } catch ( Throwable $throwable ) {
        return [
            'status' => 'error',
            'error_code' => 'EXECUTION_FAILED',
            'message' => $throwable->getMessage() !== ''
                ? $throwable->getMessage()
                : 'Tool execution failed.',
        ];
    }

    return is_array( $result )
        ? $result
        : [
            'status' => 'success',
            'result' => $result,
        ];
}
