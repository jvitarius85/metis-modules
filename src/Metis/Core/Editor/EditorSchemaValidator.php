<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

final class EditorSchemaValidator {
    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $payload
     * @return array{valid:bool,errors:array<int,string>}
     */
    public static function validateBlockPayload( array $schema, array $payload ): array {
        $errors = [];
        $required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : [];
        $properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];

        foreach ( $required as $key ) {
            $field = is_string( $key ) ? $key : '';
            if ( $field === '' ) {
                continue;
            }
            if ( ! array_key_exists( $field, $payload ) ) {
                $errors[] = 'Required field missing: ' . $field;
            }
        }

        foreach ( $payload as $key => $value ) {
            $field = is_string( $key ) ? $key : '';
            if ( $field === '' ) {
                continue;
            }
            if ( ! isset( $properties[ $field ] ) || ! is_array( $properties[ $field ] ) ) {
                $errors[] = 'Unexpected field: ' . $field;
                continue;
            }
            $expected_type = isset( $properties[ $field ]['type'] ) ? (string) $properties[ $field ]['type'] : '';
            if ( ! self::matchesType( $expected_type, $value ) ) {
                $errors[] = 'Invalid field type for ' . $field . ': expected ' . $expected_type;
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    private static function matchesType( string $expected, mixed $value ): bool {
        if ( $expected === '' ) {
            return true;
        }
        return match ( $expected ) {
            'string' => is_string( $value ),
            'number', 'integer' => is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ),
            'boolean' => is_bool( $value ) || $value === 0 || $value === 1 || $value === '0' || $value === '1',
            'array' => is_array( $value ),
            'object' => is_array( $value ),
            default => true,
        };
    }
}
