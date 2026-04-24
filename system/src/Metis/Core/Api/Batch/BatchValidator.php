<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

final class Metis_Batch_Validator {
    private array $schema;

    public function __construct( ?array $schema = null ) {
        $this->schema = is_array( $schema ) ? $schema : $this->load_schema();
    }

    public function resolve_context( string $module, string $action ): ?array {
        $key = metis_key_clean( $module ) . ':' . metis_key_clean( $action );
        $context = $this->schema[ $key ] ?? null;
        return is_array( $context ) ? $context : null;
    }

    public function validate_rows( array $rows, array $context ): array {
        $validated_rows = [];
        $valid_rows = [];
        $invalid_count = 0;

        foreach ( $rows as $index => $row ) {
            $row_data = is_array( $row ) ? $row : [];
            $row_id = (string) ( $row_data['row_id'] ?? 'row_' . ( $index + 1 ) );
            $row_data['row_id'] = $row_id;

            $errors = [];
            $sanitized = $this->sanitize_row( $row_data, $context, $errors );

            foreach ( (array) ( $context['row_validators'] ?? [] ) as $callback ) {
                if ( ! is_string( $callback ) || ! function_exists( $callback ) ) {
                    continue;
                }
                $extra_errors = $callback( $sanitized, $context );
                if ( is_array( $extra_errors ) ) {
                    foreach ( $extra_errors as $error ) {
                        $message = trim( metis_text_clean( (string) $error ) );
                        if ( $message !== '' ) {
                            $errors[] = $message;
                        }
                    }
                }
            }

            $status = empty( $errors ) ? 'valid' : 'invalid';
            if ( $status !== 'valid' ) {
                $invalid_count++;
            }

            $validated_rows[] = [
                'row_id' => $row_id,
                'index' => $index,
                'status' => $status,
                'errors' => $errors,
                'row' => $sanitized,
            ];

            if ( $status === 'valid' ) {
                $valid_rows[] = $sanitized;
            }
        }

        $this->validate_batch_uniques( $validated_rows, $context );

        $valid_rows = [];
        $invalid_count = 0;
        foreach ( $validated_rows as $entry ) {
            if ( $entry['status'] === 'valid' ) {
                $valid_rows[] = $entry['row'];
            } else {
                $invalid_count++;
            }
        }

        return [
            'rows' => $validated_rows,
            'valid_rows' => $valid_rows,
            'invalid_count' => $invalid_count,
        ];
    }

    private function sanitize_row( array $row, array $context, array &$errors ): array {
        $fields = (array) ( $context['fields'] ?? [] );
        $sanitized = [ 'row_id' => (string) ( $row['row_id'] ?? '' ) ];

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            $key = metis_key_clean( (string) ( $field['key'] ?? '' ) );
            if ( $key === '' ) {
                continue;
            }

            $label = (string) ( $field['label'] ?? $key );
            $type = metis_key_clean( (string) ( $field['type'] ?? 'text' ) );
            $required = ! empty( $field['required'] );
            $raw = $row[ $key ] ?? '';
            $value = $this->sanitize_field_value( $type, $raw );

            if ( is_string( $value ) ) {
                $max_length = isset( $field['max_length'] ) ? (int) $field['max_length'] : 0;
                if ( $max_length > 0 && mb_strlen( $value ) > $max_length ) {
                    $errors[] = sprintf( '%s exceeds %d characters.', $label, $max_length );
                    $value = mb_substr( $value, 0, $max_length );
                }
            }

            if ( $required && $this->is_empty( $value ) ) {
                $errors[] = sprintf( '%s is required.', $label );
            }

            if ( $type === 'email' && ! $this->is_empty( $value ) && ! metis_email_is_valid( (string) $value ) ) {
                $errors[] = sprintf( '%s must be a valid email address.', $label );
            }

            if ( $type === 'number' && ! $this->is_empty( $value ) && ! is_numeric( (string) $value ) ) {
                $errors[] = sprintf( '%s must be numeric.', $label );
            }

            if ( $type === 'select' && ! $this->is_empty( $value ) ) {
                $allowed_values = array_values( array_filter( array_map( 'strval', (array) ( $field['allowed_values'] ?? [] ) ) ) );
                if ( $allowed_values !== [] && ! in_array( (string) $value, $allowed_values, true ) ) {
                    $errors[] = sprintf( '%s contains an invalid option.', $label );
                }
            }

            if ( $type === 'date' && ! $this->is_empty( $value ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ) {
                $errors[] = sprintf( '%s must be YYYY-MM-DD.', $label );
            }

            $sanitized[ $key ] = $value;
        }

        return $sanitized;
    }

    private function validate_batch_uniques( array &$validated_rows, array $context ): void {
        $fields = (array) ( $context['fields'] ?? [] );

        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) || empty( $field['unique_in_batch'] ) ) {
                continue;
            }

            $key = metis_key_clean( (string) ( $field['key'] ?? '' ) );
            $label = (string) ( $field['label'] ?? $key );
            if ( $key === '' ) {
                continue;
            }

            $seen = [];
            foreach ( $validated_rows as &$entry ) {
                if ( (string) ( $entry['status'] ?? '' ) !== 'valid' ) {
                    continue;
                }

                $value = $entry['row'][ $key ] ?? '';
                if ( $this->is_empty( $value ) ) {
                    continue;
                }

                $normalized = strtolower( trim( (string) $value ) );
                if ( isset( $seen[ $normalized ] ) ) {
                    $entry['status'] = 'invalid';
                    $entry['errors'][] = sprintf( '%s is duplicated in this batch.', $label );
                    continue;
                }

                $seen[ $normalized ] = true;
            }
            unset( $entry );
        }
    }

    private function sanitize_field_value( string $type, mixed $value ): mixed {
        $raw = is_scalar( $value ) ? (string) $value : '';

        return match ( $type ) {
            'email' => strtolower( trim( metis_email_clean( $raw ) ) ),
            'number' => trim( $raw ) === '' ? '' : (float) $raw,
            'date' => metis_text_clean( trim( $raw ) ),
            'select' => metis_text_clean( trim( $raw ) ),
            default => metis_text_clean( trim( $raw ) ),
        };
    }

    private function is_empty( mixed $value ): bool {
        if ( is_string( $value ) ) {
            return trim( $value ) === '';
        }

        return $value === null || $value === '';
    }

    private function load_schema(): array {
        $path = METIS_SRC_PATH . 'Metis/Core/Config/batch-entry.schema.php';
        if ( ! is_file( $path ) ) {
            return [];
        }

        $schema = require $path;
        return is_array( $schema ) ? $schema : [];
    }
}

if ( ! function_exists( 'metis_batch_validate_contacts_create_row' ) ) {
    function metis_batch_validate_contacts_create_row( array $row, array $context = [] ): array {
        $errors = [];
        $first = trim( (string) ( $row['first_name'] ?? '' ) );
        $last = trim( (string) ( $row['last_name'] ?? '' ) );

        if ( $first === '' || $last === '' ) {
            $errors[] = 'First name and last name are required.';
        }

        return $errors;
    }
}

if ( ! function_exists( 'metis_batch_validate_newsletter_subscription_row' ) ) {
    function metis_batch_validate_newsletter_subscription_row( array $row, array $context = [] ): array {
        static $active_list_ids = null;

        $errors = [];
        $list_id = (int) ( $row['list_id'] ?? 0 );

        if ( $list_id < 1 ) {
            $errors[] = 'A list selection is required.';
            return $errors;
        }

        if ( ! is_array( $active_list_ids ) ) {
            $lists_table = Metis_Tables::get( 'newsletter_lists' );
            $active_list_ids = array_map(
                'intval',
                (array) metis_db()->column( "SELECT id FROM {$lists_table} WHERE is_active = 1" )
            );
        }

        if ( ! in_array( $list_id, $active_list_ids, true ) ) {
            $errors[] = 'Selected newsletter list is unavailable.';
        }

        return $errors;
    }
}
