<?php
declare(strict_types=1);

namespace Metis\Hermes;

/**
 * HermesQueryBuilder
 *
 * Translates a validated, structured Hermes interpretation into a safe,
 * executable database query. Never produces raw SQL from user input.
 *
 * All queries are:
 *   - validated against the entity registry and capability map
 *   - checked against the safety governor before execution
 *   - permission-checked against the requesting actor
 *   - routed through Metis_Tables for table name resolution
 *   - parameterized via the DatabaseService prepared statement interface
 *
 * No SQL strings are ever constructed from user-supplied field names,
 * filter values, or entity aliases. Every field name is validated against
 * the entity definition before inclusion.
 */
final class HermesQueryBuilder {

    private EntityRegistryBuilder    $entityRegistry;
    private DataCapabilityBuilder   $capability;
    private HermesSafetyGovernor    $governor;
    private HermesPermissionValidator $permissions;
    private ?HermesAuditLogger      $audit;

    public function __construct(
        EntityRegistryBuilder    $entityRegistry,
        DataCapabilityBuilder    $capability,
        HermesSafetyGovernor     $governor,
        HermesPermissionValidator $permissions,
        ?HermesAuditLogger       $audit = null
    ) {
        $this->entityRegistry = $entityRegistry;
        $this->capability     = $capability;
        $this->governor       = $governor;
        $this->permissions    = $permissions;
        $this->audit          = $audit;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Builds and executes a read query from a structured interpretation.
     *
     * @param  array $interpretation  Structured query from the intent pipeline
     * @param  array $actor           { user_id, roles }
     * @return array{ ok: bool, data?: array, total?: int, message?: string }
     */
    public function query( array $interpretation, array $actor ): array {
        $entity = strtolower( trim( (string) ( $interpretation['entity'] ?? '' ) ) );

        // 1. Resolve entity
        $resolvedKey = $this->entityRegistry->resolve( $entity );
        if ( $resolvedKey === null ) {
            return $this->error( "Unknown entity '{$entity}'." );
        }

        $definition = $this->entityRegistry->definition( $resolvedKey );
        $capability = $this->capability->forEntity( $resolvedKey );
        if ( $definition === null || $capability === null ) {
            return $this->error( "Entity '{$resolvedKey}' has no registered capability map." );
        }

        // 2. Permission check
        $viewPermission = (string) ( $capability['permissions']['view'] ?? '' );
        if ( $viewPermission !== '' ) {
            $permCheck = $this->permissions->validate(
                [ 'permission' => $viewPermission, 'permission_requirements' => [ [
                    'type'            => 'role_permission',
                    'permission_key'  => $viewPermission,
                    'allowed_roles'   => [],   // empty = any authenticated user
                ] ] ],
                $actor
            );
            if ( (string) ( $permCheck['status'] ?? '' ) !== 'granted' ) {
                return $this->error( "Permission denied: {$viewPermission} required." );
            }
        }

        // 3. Safety governor
        $safety = $this->governor->validateQuery( $interpretation );
        if ( ! $safety['ok'] ) {
            return $this->error( (string) ( $safety['message'] ?? 'Safety policy violation.' ) );
        }

        // 4. Validate requested fields
        $requestedFields = $this->resolveFields( $interpretation, $definition, $capability );
        if ( isset( $requestedFields['error'] ) ) {
            return $this->error( $requestedFields['error'] );
        }

        // 5. Validate and build filters
        $filters = $this->resolveFilters( (array) ( $interpretation['filters'] ?? [] ), $capability );
        if ( isset( $filters['error'] ) ) {
            return $this->error( $filters['error'] );
        }

        // 6. Validate sort
        $sort = $this->resolveSort( $interpretation, $capability );

        // 7. Validate group-by
        $groupBy = $this->resolveGroupBy( (array) ( $interpretation['group_by'] ?? [] ), $capability );
        if ( isset( $groupBy['error'] ) ) {
            return $this->error( $groupBy['error'] );
        }

        // 8. Validate aggregates
        $aggregates = $this->resolveAggregates(
            (array) ( $interpretation['aggregate'] ?? [] ), $capability
        );
        if ( isset( $aggregates['error'] ) ) {
            return $this->error( $aggregates['error'] );
        }

        // 9. Execute
        return $this->execute( $resolvedKey, $definition, $capability, [
            'fields'     => $requestedFields,
            'filters'    => $filters,
            'sort'       => $sort,
            'group_by'   => $groupBy,
            'aggregates' => $aggregates,
            'limit'      => min(
                max( 1, (int) ( $interpretation['limit'] ?? 50 ) ),
                (int) ( $this->governor->policy()['query_limits']['max_page_size'] ?? 500 )
            ),
            'offset'     => max( 0, (int) ( $interpretation['offset'] ?? 0 ) ),
        ] );
    }

    /**
     * Builds and executes an aggregate-only report query.
     *
     * @param  array $interpretation  Must include entity + aggregate operations
     * @param  array $actor           { user_id, roles }
     * @return array{ ok: bool, data?: array, message?: string }
     */
    public function aggregate( array $interpretation, array $actor ): array {
        // Ensure aggregate key is present
        if ( empty( $interpretation['aggregate'] ) ) {
            return $this->error( 'Aggregate query requires at least one aggregate operation.' );
        }
        return $this->query( $interpretation, $actor );
    }


    // ------------------------------------------------------------------
    // Field / filter / sort / group / aggregate resolution
    // ------------------------------------------------------------------

    /** Returns validated list of safe column names, or ['error' => ...]. */
    private function resolveFields( array $interpretation, array $definition, array $capability ): array {
        $requested = (array) ( $interpretation['fields_requested'] ?? [] );
        $allFields = array_keys( (array) ( $definition['fields'] ?? [] ) );

        if ( $requested === [] ) {
            return $allFields; // default: all defined fields
        }

        $safe = [];
        foreach ( $requested as $field ) {
            $field = (string) $field;
            if ( ! in_array( $field, $allFields, true ) ) {
                return [ 'error' => "Unknown field '{$field}' on entity." ];
            }
            $safe[] = $field;
        }

        return $safe;
    }

    /**
     * Returns validated filter list: [ ['field', 'op', 'value', 'type'], ... ]
     * or ['error' => ...].
     */
    private function resolveFilters( array $rawFilters, array $capability ): array {
        $filterable = (array) ( $capability['filterable_fields'] ?? [] );
        $fieldDefs  = (array) ( $capability['fields'] ?? [] );
        $safe       = [];
        $allowedOps = [ '=', '!=', '<', '<=', '>', '>=', 'LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL' ];

        foreach ( $rawFilters as $filterKey => $filterValue ) {
            // Support both keyed ['field' => value] and structured ['field', 'op', 'value']
            if ( is_string( $filterKey ) ) {
                $field = $filterKey;
                $op    = '=';
                $value = $filterValue;
            } elseif ( is_array( $filterValue ) ) {
                $field = (string) ( $filterValue['field'] ?? '' );
                $op    = strtoupper( trim( (string) ( $filterValue['op'] ?? '=' ) ) );
                $value = $filterValue['value'] ?? null;
            } else {
                continue;
            }

            if ( ! in_array( $field, $filterable, true ) ) {
                return [ 'error' => "Field '{$field}' is not filterable on this entity." ];
            }

            if ( ! in_array( $op, $allowedOps, true ) ) {
                return [ 'error' => "Operator '{$op}' is not permitted." ];
            }

            $fieldType = (string) ( $fieldDefs[ $field ]['type'] ?? 'string' );
            $safe[] = [ 'field' => $field, 'op' => $op, 'value' => $value, 'type' => $fieldType ];
        }

        return $safe;
    }

    /** Returns ['field' => string, 'dir' => 'asc'|'desc']. */
    private function resolveSort( array $interpretation, array $capability ): array {
        $sortable = (array) ( $capability['sortable_fields'] ?? [] );
        $field = (string) ( $interpretation['sort'] ?? $capability['default_sort'] ?? 'id' );
        $dir   = strtolower( (string) ( $interpretation['sort_dir'] ?? $capability['default_sort_dir'] ?? 'desc' ) );

        if ( ! in_array( $field, $sortable, true ) ) {
            $field = (string) ( $capability['default_sort'] ?? 'id' );
        }

        return [ 'field' => $field, 'dir' => $dir === 'asc' ? 'asc' : 'desc' ];
    }

    /** Returns validated group-by fields or ['error' => ...]. */
    private function resolveGroupBy( array $rawGroupBy, array $capability ): array {
        $groupable = (array) ( $capability['groupable_fields'] ?? [] );
        $safe = [];
        foreach ( $rawGroupBy as $field ) {
            $field = (string) $field;
            if ( ! in_array( $field, $groupable, true ) ) {
                return [ 'error' => "Field '{$field}' cannot be used in GROUP BY on this entity." ];
            }
            $safe[] = $field;
        }
        return $safe;
    }

    /**
     * Returns validated aggregate list: [ ['field', 'operation', 'alias'], ... ]
     * or ['error' => ...].
     */
    private function resolveAggregates( array $rawAggregates, array $capability ): array {
        $aggregatable = (array) ( $capability['aggregate_fields'] ?? [] );
        $allowedOps   = (array) ( $this->governor->policy()['aggregate_limits']['allowed_operations']
            ?? [ 'count', 'sum', 'avg', 'min', 'max' ] );
        $safe = [];

        foreach ( $rawAggregates as $agg ) {
            if ( ! is_array( $agg ) ) {
                continue;
            }
            $field = (string) ( $agg['field'] ?? '' );
            $op    = strtolower( trim( (string) ( $agg['operation'] ?? 'count' ) ) );
            $alias = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $agg['alias'] ?? "{$op}_{$field}" ) ) );

            if ( $op !== 'count' && ! in_array( $field, $aggregatable, true ) ) {
                return [ 'error' => "Field '{$field}' does not support aggregation on this entity." ];
            }

            if ( ! in_array( $op, $allowedOps, true ) ) {
                return [ 'error' => "Aggregate operation '{$op}' is not permitted." ];
            }

            $safe[] = [ 'field' => $field, 'operation' => $op, 'alias' => $alias ];
        }

        return $safe;
    }


    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    private function execute(
        string $entityKey,
        array  $definition,
        array  $capability,
        array  $query
    ): array {
        $db = \Metis\Core\Application::service( 'db' );

        $tableKey = (string) ( $capability['table_key'] ?? '' );
        if ( $tableKey === '' ) {
            return $this->error( "Entity '{$entityKey}' has no mapped table." );
        }

        try {
            $table = \Metis_Tables::get( $tableKey );
        } catch ( \InvalidArgumentException $e ) {
            return $this->error( "Table for entity '{$entityKey}' is not registered." );
        }

        $params = [];
        $isAggregate = ! empty( $query['aggregates'] ) || ! empty( $query['group_by'] );

        // SELECT clause
        if ( $isAggregate ) {
            $selectParts = [];
            foreach ( $query['group_by'] as $gField ) {
                $selectParts[] = "`{$gField}`";
            }
            foreach ( $query['aggregates'] as $agg ) {
                $op    = strtoupper( $agg['operation'] );
                $field = $agg['field'] !== '' ? "`{$agg['field']}`" : '*';
                $alias = $agg['alias'];
                $selectParts[] = "{$op}({$field}) AS `{$alias}`";
            }
            $select = implode( ', ', $selectParts );
        } else {
            $fields = array_map( static fn ( string $f ): string => "`{$f}`", $query['fields'] );
            $select = implode( ', ', $fields );
        }

        // FROM + base WHERE
        $baseWhere = (string) ( $capability['where_clause'] ?? '' );
        $whereParts = $baseWhere !== '' ? [ "({$baseWhere})" ] : [];

        // Dynamic WHERE from filters
        foreach ( $query['filters'] as $filter ) {
            $field = $filter['field'];
            $op    = $filter['op'];

            if ( $op === 'IS NULL' || $op === 'IS NOT NULL' ) {
                $whereParts[] = "`{$field}` {$op}";
            } elseif ( $op === 'IN' || $op === 'NOT IN' ) {
                $values = (array) ( $filter['value'] ?? [] );
                if ( $values === [] ) {
                    continue;
                }
                $placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
                $whereParts[] = "`{$field}` {$op} ({$placeholders})";
                foreach ( $values as $v ) {
                    $params[] = $v;
                }
            } elseif ( $op === 'LIKE' ) {
                $whereParts[] = "`{$field}` LIKE %s";
                $params[] = $filter['value'];
            } else {
                $placeholder = $filter['type'] === 'integer' ? '%d'
                    : ( $filter['type'] === 'decimal' ? '%f' : '%s' );
                $whereParts[] = "`{$field}` {$op} {$placeholder}";
                $params[] = $filter['value'];
            }
        }

        $whereClause = $whereParts !== [] ? 'WHERE ' . implode( ' AND ', $whereParts ) : '';

        // GROUP BY
        $groupClause = '';
        if ( $query['group_by'] !== [] ) {
            $groupParts  = array_map( static fn ( string $f ): string => "`{$f}`", $query['group_by'] );
            $groupClause = 'GROUP BY ' . implode( ', ', $groupParts );
        }

        // ORDER BY (skip for pure aggregate without group-by)
        $orderClause = '';
        if ( ! $isAggregate || $query['group_by'] !== [] ) {
            $sortField   = $query['sort']['field'];
            $sortDir     = strtoupper( $query['sort']['dir'] );
            $orderClause = "ORDER BY `{$sortField}` {$sortDir}";
        }

        // LIMIT / OFFSET
        $limit  = (int) $query['limit'];
        $offset = (int) $query['offset'];
        $limitClause = "LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $sql = trim( "SELECT {$select} FROM `{$table}` {$whereClause} {$groupClause} {$orderClause} {$limitClause}" );

        // Count query (skip for aggregate reports)
        $total = 0;
        if ( ! $isAggregate ) {
            $countSql = trim( "SELECT COUNT(*) FROM `{$table}` {$whereClause}" );
            $countParams = array_slice( $params, 0, count( $params ) - 2 );
            $total = (int) $db->scalar( $countSql, $countParams );
        }

        $rows = $db->fetchAll( $sql, $params ) ?: [];

        $result = [
            'ok'     => true,
            'entity' => $entityKey,
            'data'   => $rows,
            'total'  => $total > 0 ? $total : count( $rows ),
            'limit'  => $limit,
            'offset' => $offset,
        ];

        $this->audit?->queryExecuted( $entityKey, $isAggregate ? 'aggregate' : 'query', count( $rows ) );

        return $result;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function error( string $message ): array {
        return [ 'ok' => false, 'message' => $message, 'data' => [] ];
    }
}
