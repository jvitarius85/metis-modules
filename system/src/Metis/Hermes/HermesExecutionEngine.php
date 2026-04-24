<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesExecutionEngine {
    public function __construct(
        private readonly HermesToolExecutor $tools
    ) {}

    public function execute( array $command, array $payload = [] ): array {
        $this->assertPayloadMatchesSchema( $payload, (array) ( $command['input_schema'] ?? [] ) );

        $tool_key = trim( (string) ( $command['tool_key'] ?? '' ) );
        if ( $tool_key === '' ) {
            return [
                'status' => 'error',
                'error_code' => 'TOOL_NOT_FOUND',
                'message' => 'Command is missing a tool mapping.',
            ];
        }

        return $this->tools->execute( $tool_key, $payload );
    }

    private function assertPayloadMatchesSchema( array $payload, array $schema, string $path = 'payload' ): void {
        $type = \metis_key_clean( (string) ( $schema['type'] ?? 'object' ) );

        if ( $type === 'object' ) {
            $required = array_values( array_filter( array_map( 'strval', (array) ( $schema['required'] ?? [] ) ) ) );
            foreach ( $required as $field ) {
                if ( ! array_key_exists( $field, $payload ) ) {
                    throw new \RuntimeException( sprintf( 'Hermes command payload is missing required field [%s].', $path . '.' . $field ) );
                }
            }

            foreach ( (array) ( $schema['properties'] ?? [] ) as $field => $fieldSchema ) {
                if ( ! array_key_exists( (string) $field, $payload ) ) {
                    continue;
                }

                if ( is_array( $fieldSchema ) ) {
                    $this->assertValueMatchesSchema( $payload[ (string) $field ], $fieldSchema, $path . '.' . (string) $field );
                }
            }
        }
    }

    private function assertValueMatchesSchema( mixed $value, array $schema, string $path ): void {
        $type = \metis_key_clean( (string) ( $schema['type'] ?? '' ) );

        if ( $type === 'string' && ! is_string( $value ) ) {
            throw new \RuntimeException( sprintf( 'Hermes command payload field [%s] must be a string.', $path ) );
        }

        if ( $type === 'array' ) {
            if ( ! is_array( $value ) ) {
                throw new \RuntimeException( sprintf( 'Hermes command payload field [%s] must be an array.', $path ) );
            }

            $itemSchema = is_array( $schema['items'] ?? null ) ? (array) $schema['items'] : [];
            foreach ( $value as $index => $item ) {
                if ( $itemSchema !== [] ) {
                    $this->assertValueMatchesSchema( $item, $itemSchema, $path . '[' . (string) $index . ']' );
                }
            }
        }

        if ( $type === 'integer' && ! is_int( $value ) ) {
            throw new \RuntimeException( sprintf( 'Hermes command payload field [%s] must be an integer.', $path ) );
        }

        if ( $type === 'boolean' && ! is_bool( $value ) ) {
            throw new \RuntimeException( sprintf( 'Hermes command payload field [%s] must be a boolean.', $path ) );
        }

        if ( $type === 'object' ) {
            if ( ! is_array( $value ) ) {
                throw new \RuntimeException( sprintf( 'Hermes command payload field [%s] must be an object.', $path ) );
            }

            $this->assertPayloadMatchesSchema( $value, $schema, $path );
        }
    }
}
