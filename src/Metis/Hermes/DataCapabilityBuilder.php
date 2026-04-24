<?php
declare(strict_types=1);

namespace Metis\Hermes;

/**
 * DataCapabilityBuilder
 *
 * Derives the data capability map from the compiled entity registry.
 * Produces a structured map that Hermes uses to validate whether a
 * reporting or aggregation request is actually supported before
 * attempting to build a query.
 *
 * Output shape per entity:
 *   aggregate_fields  – fields that support sum/avg/count operations
 *   groupable_fields  – fields valid as GROUP BY targets
 *   filterable_fields – fields that can appear in WHERE clauses
 *   date_fields       – datetime fields valid for date-range filters
 *   sortable_fields   – fields valid as ORDER BY targets
 *   default_sort      – recommended default sort field
 *   default_sort_dir  – asc | desc
 *   permissions       – view/edit permission keys
 *   table_key         – TablesRegistry key for query construction
 *   primary_key       – primary key column name
 *   uid_column        – human-readable UID column, if any
 *   where_clause      – optional base WHERE restriction
 */
final class DataCapabilityBuilder {

    private static ?array $map = null;

    private EntityRegistryBuilder $registry;

    public function __construct( EntityRegistryBuilder $registry ) {
        $this->registry = $registry;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /** Returns the full capability map, keyed by entity name. */
    public function map(): array {
        if ( self::$map !== null ) {
            return self::$map;
        }
        return $this->build();
    }

    /** Returns the capability entry for a single entity, or null. */
    public function forEntity( string $entity ): ?array {
        $map = $this->map();
        $key = strtolower( trim( $entity ) );
        return $map[ $key ] ?? null;
    }

    /**
     * Returns true when the given field is valid for the requested operation
     * on the given entity.
     *
     * @param string $operation  One of: aggregate, group, filter, sort, date
     */
    public function fieldAllowed( string $entity, string $field, string $operation ): bool {
        $cap = $this->forEntity( $entity );
        if ( $cap === null ) {
            return false;
        }

        $listKey = match ( $operation ) {
            'aggregate' => 'aggregate_fields',
            'group'     => 'groupable_fields',
            'filter'    => 'filterable_fields',
            'sort'      => 'sortable_fields',
            'date'      => 'date_fields',
            default     => null,
        };

        if ( $listKey === null ) {
            return false;
        }

        return in_array( $field, (array) ( $cap[ $listKey ] ?? [] ), true );
    }

    /** Clears the in-process cache (call after registry flush). */
    public function flush(): void {
        self::$map = null;
    }

    // ------------------------------------------------------------------
    // Build
    // ------------------------------------------------------------------

    private function build(): array {
        $map = [];

        foreach ( $this->registry->registry() as $entityKey => $definition ) {
            $fields = (array) ( $definition['fields'] ?? [] );
            $reporting = (array) ( $definition['reporting'] ?? [] );

            // Derive sortable_fields directly from field definitions
            $sortable = [];
            foreach ( $fields as $fieldName => $meta ) {
                if ( ! empty( $meta['sortable'] ) ) {
                    $sortable[] = $fieldName;
                }
            }

            $map[ $entityKey ] = [
                'entity'           => $entityKey,
                'label'            => (string) ( $definition['label'] ?? $entityKey ),
                'plural'           => (string) ( $definition['plural'] ?? $entityKey ),
                'module'           => (string) ( $definition['module'] ?? '' ),
                'table_key'        => (string) ( $definition['table_key'] ?? '' ),
                'primary_key'      => (string) ( $definition['primary_key'] ?? 'id' ),
                'uid_column'       => isset( $definition['uid_column'] ) && $definition['uid_column'] !== null
                    ? (string) $definition['uid_column']
                    : null,
                'where_clause'     => (string) ( $definition['where_clause'] ?? '' ),
                'aggregate_fields' => (array) ( $reporting['aggregate_fields'] ?? [] ),
                'groupable_fields' => (array) ( $reporting['groupable_fields'] ?? [] ),
                'filterable_fields'=> (array) ( $reporting['filterable_fields'] ?? [] ),
                'date_fields'      => (array) ( $reporting['date_fields'] ?? [] ),
                'sortable_fields'  => $sortable,
                'default_sort'     => (string) ( $reporting['default_sort'] ?? 'id' ),
                'default_sort_dir' => (string) ( $reporting['default_sort_dir'] ?? 'desc' ),
                'permissions'      => (array) ( $definition['permissions'] ?? [] ),
                'relationships'    => (array) ( $definition['relationships'] ?? [] ),
                'fields'           => $fields,
            ];
        }

        self::$map = $map;
        return $map;
    }
}
