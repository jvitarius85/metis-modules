<?php
declare(strict_types=1);

namespace Metis\Hermes;

/**
 * HermesSafetyGovernor
 *
 * Validates every query and action request against the safety policy before
 * execution. If a request violates any policy rule, the governor rejects it,
 * logs the violation, and returns a user-safe error message.
 *
 * Policy is loaded from config/hermes/safety_policy.json.
 * All limits are soft-configurable without code changes.
 */
final class HermesSafetyGovernor {

    private static ?array $policy = null;

    private HermesAuditLogger $audit;

    public function __construct( HermesAuditLogger $audit ) {
        $this->audit = $audit;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Validates a structured query interpretation before it reaches the builder.
     *
     * @param  array $interpretation  Output of HermesIntentParser / data intent path
     * @return array{ ok: bool, violation?: string, message?: string }
     */
    public function validateQuery( array $interpretation ): array {
        $policy = $this->policy();

        // Filter condition count
        $filters = (array) ( $interpretation['filters'] ?? [] );
        $maxFilters = (int) ( $policy['query_limits']['max_filter_conditions'] ?? 10 );
        if ( count( $filters ) > $maxFilters ) {
            return $this->reject( 'too_many_filters',
                "Query contains " . count( $filters ) . " filter conditions (max {$maxFilters})." );
        }

        // Group-by field count
        $groupBy = (array) ( $interpretation['group_by'] ?? [] );
        $maxGroups = (int) ( $policy['query_limits']['max_group_by_fields'] ?? 3 );
        if ( count( $groupBy ) > $maxGroups ) {
            return $this->reject( 'too_many_group_by_fields',
                "Query groups by " . count( $groupBy ) . " fields (max {$maxGroups})." );
        }

        // Aggregate field count
        $aggregates = (array) ( $interpretation['aggregate'] ?? [] );
        $maxAggregates = (int) ( $policy['aggregate_limits']['max_aggregate_fields'] ?? 5 );
        if ( count( $aggregates ) > $maxAggregates ) {
            return $this->reject( 'too_many_aggregate_fields',
                "Query requests " . count( $aggregates ) . " aggregate operations (max {$maxAggregates})." );
        }

        // Aggregate operation whitelist
        $allowed = (array) ( $policy['aggregate_limits']['allowed_operations'] ?? [] );
        foreach ( $aggregates as $agg ) {
            $op = strtolower( trim( (string) ( $agg['operation'] ?? '' ) ) );
            if ( $allowed !== [] && ! in_array( $op, $allowed, true ) ) {
                return $this->reject( 'unsupported_aggregate_operation',
                    "Aggregate operation '{$op}' is not permitted." );
            }
        }

        // Page size
        $limit = (int) ( $interpretation['limit'] ?? 0 );
        $maxPageSize = (int) ( $policy['query_limits']['max_page_size'] ?? 500 );
        if ( $limit > $maxPageSize ) {
            return $this->reject( 'page_size_exceeded',
                "Requested limit {$limit} exceeds maximum page size {$maxPageSize}." );
        }

        return [ 'ok' => true ];
    }

    /**
     * Validates a write or action request.
     *
     * @param  string $entity    Entity being mutated
     * @param  string $operation Operation name (create, update, delete, bulk_*)
     * @param  array  $payload   Request payload
     * @return array{ ok: bool, violation?: string, message?: string }
     */
    public function validateWrite( string $entity, string $operation, array $payload = [] ): array {
        $policy = $this->policy();

        // Blocked entities
        $blocked = (array) ( $policy['write_restrictions']['blocked_entities_for_write'] ?? [] );
        if ( in_array( $entity, $blocked, true ) ) {
            return $this->reject( 'entity_write_blocked',
                "Write operations on '{$entity}' are not permitted through Hermes." );
        }

        // Bulk limits
        if ( str_starts_with( $operation, 'bulk_' ) ) {
            $count = count( (array) ( $payload['ids'] ?? $payload['items'] ?? [] ) );
            $maxBulk = (int) ( $policy['bulk_limits']['max_bulk_operations'] ?? 1000 );
            if ( $count > $maxBulk ) {
                return $this->reject( 'bulk_limit_exceeded',
                    "Bulk operation targets {$count} records (max {$maxBulk})." );
            }
        }

        // Write approval requirement (informational — actual approval enforced by enclave)
        $requireApproval = (bool) ( $policy['write_restrictions']['require_approval_for_write'] ?? true );
        if ( $requireApproval ) {
            return [ 'ok' => true, 'requires_approval' => true ];
        }

        return [ 'ok' => true ];
    }

    /**
     * Validates a playbook execution request.
     *
     * @param  array $playbook  Playbook definition
     * @param  int   $depth     Current recursion depth
     * @return array{ ok: bool, violation?: string, message?: string }
     */
    public function validatePlaybook( array $playbook, int $depth = 0 ): array {
        $policy = $this->policy();

        $maxDepth = (int) ( $policy['playbook_limits']['max_recursion_depth'] ?? 3 );
        if ( $depth > $maxDepth ) {
            return $this->reject( 'playbook_recursion_exceeded',
                "Playbook recursion depth {$depth} exceeds maximum {$maxDepth}." );
        }

        $steps = (array) ( $playbook['steps'] ?? [] );
        $maxSteps = (int) ( $policy['playbook_limits']['max_steps_per_playbook'] ?? 20 );
        if ( count( $steps ) > $maxSteps ) {
            return $this->reject( 'playbook_too_many_steps',
                "Playbook defines " . count( $steps ) . " steps (max {$maxSteps})." );
        }

        return [ 'ok' => true ];
    }

    /** Returns the loaded policy array. */
    public function policy(): array {
        if ( self::$policy !== null ) {
            return self::$policy;
        }
        return $this->loadPolicy();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function reject( string $violation, string $detail = '' ): array {
        $policy = $this->policy();
        $userMessage = (string) ( $policy['violation_policy']['user_message']
            ?? 'This request exceeds Hermes operational limits and cannot be processed.' );

        if ( (bool) ( $policy['violation_policy']['log_violations'] ?? true ) ) {
            $this->audit->violation( $violation, $detail );
        }

        return [
            'ok'        => false,
            'violation' => $violation,
            'detail'    => $detail,
            'message'   => $userMessage,
        ];
    }

    private function loadPolicy(): array {
        $path = defined( 'METIS_ROOT' )
            ? rtrim( METIS_ROOT, '/' ) . '/config/hermes/safety_policy.json'
            : '';

        if ( $path !== '' && is_readable( $path ) ) {
            $raw = file_get_contents( $path );
            if ( $raw !== false ) {
                $decoded = json_decode( $raw, true );
                if ( is_array( $decoded ) ) {
                    self::$policy = $decoded;
                    return self::$policy;
                }
            }
        }

        // Hardcoded fallback if file is missing
        self::$policy = [
            'query_limits'    => [ 'max_query_results' => 5000, 'max_group_by_fields' => 3,
                                   'max_filter_conditions' => 10, 'max_page_size' => 500 ],
            'bulk_limits'     => [ 'max_bulk_operations' => 1000 ],
            'aggregate_limits'=> [ 'max_aggregate_fields' => 5,
                                   'allowed_operations' => [ 'count', 'sum', 'avg', 'min', 'max' ] ],
            'write_restrictions' => [ 'require_approval_for_write' => true, 'blocked_entities_for_write' => [] ],
            'playbook_limits' => [ 'max_recursion_depth' => 3, 'max_steps_per_playbook' => 20 ],
            'violation_policy'=> [ 'log_violations' => true, 'reject_on_violation' => true,
                                   'user_message' => 'This request exceeds Hermes operational limits.' ],
        ];

        return self::$policy;
    }
}
