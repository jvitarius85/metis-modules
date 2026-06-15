<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

final class FormConditionEvaluator {
    public static function conditionsPass( array $conditions, array $context ): bool {
        if ( $conditions === [] ) {
            return true;
        }

        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) ) {
                continue;
            }

            if ( ! self::conditionPasses( $condition, $context ) ) {
                return false;
            }
        }

        return true;
    }

    public static function conditionPasses( array $condition, array $context ): bool {
        $field = (string) ( $condition['field'] ?? '' );
        $operator = (string) ( $condition['operator'] ?? 'equals' );
        $expected = $condition['value'] ?? '';
        $actual = $field !== '' ? ( $context[ $field ] ?? null ) : null;

        if ( in_array( $operator, [ 'equals', 'not_equals', 'contains' ], true ) && self::isEmpty( $actual ) ) {
            return false;
        }

        return match ( $operator ) {
            'not_equals' => ! self::valuesEqual( $actual, $expected ),
            'contains'   => self::containsValue( $actual, $expected ),
            'empty'      => self::isEmpty( $actual ),
            'not_empty'  => ! self::isEmpty( $actual ),
            default      => self::valuesEqual( $actual, $expected ),
        };
    }

    public static function startsVisible( array $conditions ): bool {
        return $conditions === [];
    }

    private static function valuesEqual( mixed $actual, mixed $expected ): bool {
        if ( is_array( $actual ) || is_array( $expected ) ) {
            return self::normalizeList( $actual ) === self::normalizeList( $expected );
        }

        return self::normalizeScalar( $actual ) === self::normalizeScalar( $expected );
    }

    private static function containsValue( mixed $actual, mixed $expected ): bool {
        if ( is_array( $actual ) ) {
            $expected_value = self::normalizeScalar( $expected );
            if ( $expected_value === '' ) {
                return false;
            }

            return in_array( $expected_value, self::normalizeList( $actual ), true );
        }

        $haystack = self::normalizeScalar( $actual );
        $needle = self::normalizeScalar( $expected );
        if ( $haystack === '' || $needle === '' ) {
            return false;
        }

        return str_contains( strtolower( $haystack ), strtolower( $needle ) );
    }

    private static function isEmpty( mixed $value ): bool {
        if ( is_array( $value ) ) {
            return self::normalizeList( $value ) === [];
        }

        return self::normalizeScalar( $value ) === '';
    }

    /**
     * @return array<int,string>
     */
    private static function normalizeList( mixed $value ): array {
        if ( ! is_array( $value ) ) {
            $scalar = self::normalizeScalar( $value );
            return $scalar === '' ? [] : [ $scalar ];
        }

        $normalized = [];
        foreach ( $value as $item ) {
            $scalar = self::normalizeScalar( $item );
            if ( $scalar !== '' ) {
                $normalized[] = $scalar;
            }
        }

        return array_values( $normalized );
    }

    private static function normalizeScalar( mixed $value ): string {
        if ( is_bool( $value ) ) {
            return $value ? '1' : '';
        }

        if ( is_scalar( $value ) ) {
            return trim( (string) $value );
        }

        return '';
    }
}
