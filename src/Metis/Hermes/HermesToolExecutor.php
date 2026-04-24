<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesToolExecutor {
    public function __construct(
        private readonly HermesToolRegistry $tools,
        private readonly HermesPermissionValidator $permissions
    ) {}

    public function execute( string $tool_key, array $payload = [] ): array {
        $tool = $this->tools->definition( $tool_key );
        if ( $tool === [] ) {
            return [
                'status' => 'error',
                'error_code' => 'TOOL_NOT_FOUND',
                'message' => sprintf( 'Tool [%s] is not registered.', $tool_key ),
            ];
        }

        try {
            $this->assertPayloadMatchesSchema( $payload, (array) ( $tool['input_schema'] ?? [] ) );
        } catch ( \Throwable $throwable ) {
            return [
                'status' => 'error',
                'error_code' => 'INVALID_INPUT',
                'message' => $throwable->getMessage(),
            ];
        }

        $permission_command = [
            'permission' => (string) ( (array) ( $tool['required_permissions'] ?? [] ) )[0] ?? '',
            'read_only' => ! (bool) ( $tool['requires_approval'] ?? false ),
            'permission_check' => [
                'module' => (string) ( $tool['module'] ?? '' ),
                'action' => ! empty( $tool['requires_approval'] ) ? 'edit' : 'view',
            ],
        ];
        $permission = $this->permissions->validate( $permission_command );
        if ( (string) ( $permission['status'] ?? '' ) !== 'granted' ) {
            return [
                'status' => 'error',
                'error_code' => 'PERMISSION_DENIED',
                'message' => (string) ( $permission['reason'] ?? 'Permission denied.' ),
            ];
        }

        require_once METIS_PATH . 'core/enclave/execute.php';

        return \metis_core_enclave_execute_tool( $tool, $payload );
    }

    private function assertPayloadMatchesSchema( array $payload, array $schema, string $path = 'payload' ): void {
        $type = \metis_key_clean( (string) ( $schema['type'] ?? 'object' ) );

        if ( $type !== 'object' ) {
            return;
        }

        foreach ( (array) ( $schema['required'] ?? [] ) as $field ) {
            $field = (string) $field;
            if ( ! array_key_exists( $field, $payload ) ) {
                throw new \RuntimeException( sprintf( 'Missing required field [%s].', $path . '.' . $field ) );
            }
        }

        foreach ( (array) ( $schema['properties'] ?? [] ) as $field => $fieldSchema ) {
            $field = (string) $field;
            if ( ! array_key_exists( $field, $payload ) || ! is_array( $fieldSchema ) ) {
                continue;
            }

            $this->assertValueMatchesSchema( $payload[ $field ], $fieldSchema, $path . '.' . $field );
        }
    }

    private function assertValueMatchesSchema( mixed $value, array $schema, string $path ): void {
        $type = \metis_key_clean( (string) ( $schema['type'] ?? '' ) );
        if ( $type === 'string' && ! is_string( $value ) ) {
            throw new \RuntimeException( sprintf( 'Field [%s] must be a string.', $path ) );
        }
        if ( $type === 'integer' && ! is_int( $value ) ) {
            throw new \RuntimeException( sprintf( 'Field [%s] must be an integer.', $path ) );
        }
        if ( $type === 'boolean' && ! is_bool( $value ) ) {
            throw new \RuntimeException( sprintf( 'Field [%s] must be a boolean.', $path ) );
        }
        if ( $type === 'array' ) {
            if ( ! is_array( $value ) ) {
                throw new \RuntimeException( sprintf( 'Field [%s] must be an array.', $path ) );
            }
            $itemSchema = is_array( $schema['items'] ?? null ) ? (array) $schema['items'] : [];
            foreach ( $value as $index => $item ) {
                if ( $itemSchema !== [] ) {
                    $this->assertValueMatchesSchema( $item, $itemSchema, $path . '[' . (string) $index . ']' );
                }
            }
        }
    }
}
