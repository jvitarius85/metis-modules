<?php
declare(strict_types=1);

namespace Metis\Hermes;

/**
 * HermesReportingService
 *
 * Handles all data-oriented Hermes requests: list, search, count,
 * aggregate, group_by, date_range, top_results, and export.
 *
 * Every request is:
 *   1. Validated against the entity registry and capability map
 *   2. Checked by the safety governor
 *   3. Permission-validated for the requesting actor
 *   4. Executed through HermesQueryBuilder (no raw SQL)
 *   5. Formatted into a typed response for the UI
 *
 * This service is the bridge between the intent pipeline and the
 * query builder. It also handles date-range sugar, top-N shorthand,
 * and the export path.
 */
final class HermesReportingService {

    private HermesQueryBuilder    $queryBuilder;
    private EntityRegistryBuilder $entityRegistry;
    private DataCapabilityBuilder $capability;
    private HermesSafetyGovernor  $governor;
    private HermesAuditLogger     $audit;

    public function __construct(
        HermesQueryBuilder    $queryBuilder,
        EntityRegistryBuilder $entityRegistry,
        DataCapabilityBuilder $capability,
        HermesSafetyGovernor  $governor,
        HermesAuditLogger     $audit
    ) {
        $this->queryBuilder   = $queryBuilder;
        $this->entityRegistry = $entityRegistry;
        $this->capability     = $capability;
        $this->governor       = $governor;
        $this->audit          = $audit;
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Entry point for all data report requests from the intent pipeline.
     *
     * @param  array $interpretation  Structured data intent
     * @param  array $actor           { user_id: int, roles: string[] }
     * @return array Report response payload
     */
    public function handle( array $interpretation, array $actor ): array {
        $intent = strtolower( trim( (string) ( $interpretation['intent'] ?? 'list' ) ) );

        return match ( $intent ) {
            'count'     => $this->count( $interpretation, $actor ),
            'aggregate' => $this->aggregate( $interpretation, $actor ),
            'top'       => $this->top( $interpretation, $actor ),
            'export'    => $this->export( $interpretation, $actor ),
            default     => $this->list( $interpretation, $actor ),
        };
    }

    // ------------------------------------------------------------------
    // Intent handlers
    // ------------------------------------------------------------------

    /** Returns a paginated list or search result. */
    public function list( array $interpretation, array $actor ): array {
        $result = $this->queryBuilder->query( $interpretation, $actor );
        if ( ! $result['ok'] ) {
            return $this->error( $result['message'] ?? 'Query failed.' );
        }

        $this->audit->conversation( 'report_list', [
            'entity' => $interpretation['entity'] ?? '',
            'total'  => $result['total'] ?? 0,
        ] );

        return $this->response( 'list', $result );
    }

    /** Returns a scalar count. */
    public function count( array $interpretation, array $actor ): array {
        // Force count aggregate
        $interpretation['aggregate'] = [ [ 'field' => '', 'operation' => 'count', 'alias' => 'total' ] ];
        $interpretation['limit']     = 1;
        unset( $interpretation['sort'] );

        $result = $this->queryBuilder->aggregate( $interpretation, $actor );
        if ( ! $result['ok'] ) {
            return $this->error( $result['message'] ?? 'Count failed.' );
        }

        $count = (int) ( $result['data'][0]['total'] ?? 0 );

        $this->audit->conversation( 'report_count', [
            'entity' => $interpretation['entity'] ?? '',
            'count'  => $count,
        ] );

        return $this->response( 'count', [
            'ok'     => true,
            'entity' => $result['entity'] ?? '',
            'count'  => $count,
            'data'   => [ [ 'total' => $count ] ],
            'total'  => 1,
        ] );
    }

    /**
     * Runs one or more aggregate operations (sum, avg, min, max, count)
     * with optional group_by and date_range.
     */
    public function aggregate( array $interpretation, array $actor ): array {
        // Apply date range sugar before hitting the builder
        $interpretation = $this->applyDateRange( $interpretation );

        $result = $this->queryBuilder->aggregate( $interpretation, $actor );
        if ( ! $result['ok'] ) {
            return $this->error( $result['message'] ?? 'Aggregate query failed.' );
        }

        $this->audit->conversation( 'report_aggregate', [
            'entity'     => $interpretation['entity'] ?? '',
            'operations' => array_column( (array) ( $interpretation['aggregate'] ?? [] ), 'operation' ),
            'row_count'  => count( $result['data'] ?? [] ),
        ] );

        return $this->response( 'aggregate', $result );
    }

    /** Returns the top N records sorted by a given field descending. */
    public function top( array $interpretation, array $actor ): array {
        $n = max( 1, min( (int) ( $interpretation['top_n'] ?? 10 ), 100 ) );

        $interpretation['limit']    = $n;
        $interpretation['offset']   = 0;
        $interpretation['sort_dir'] = 'desc';

        // Default sort to first aggregate field if not specified
        if ( empty( $interpretation['sort'] ) && ! empty( $interpretation['aggregate'][0]['field'] ) ) {
            $interpretation['sort'] = (string) $interpretation['aggregate'][0]['field'];
        }

        $result = $this->queryBuilder->query( $interpretation, $actor );
        if ( ! $result['ok'] ) {
            return $this->error( $result['message'] ?? 'Top-N query failed.' );
        }

        $this->audit->conversation( 'report_top', [
            'entity' => $interpretation['entity'] ?? '',
            'top_n'  => $n,
        ] );

        return $this->response( 'top', array_merge( $result, [ 'top_n' => $n ] ) );
    }

    /**
     * Runs the query and returns results formatted for CSV export.
     * Enforces a hard cap of 10,000 rows.
     */
    public function export( array $interpretation, array $actor ): array {
        $exportCap = 10000;
        $interpretation['limit']  = min( (int) ( $interpretation['limit'] ?? $exportCap ), $exportCap );
        $interpretation['offset'] = 0;

        $result = $this->queryBuilder->query( $interpretation, $actor );
        if ( ! $result['ok'] ) {
            return $this->error( $result['message'] ?? 'Export query failed.' );
        }

        $this->audit->conversation( 'report_export', [
            'entity'     => $interpretation['entity'] ?? '',
            'row_count'  => count( $result['data'] ?? [] ),
        ] );

        return array_merge(
            $this->response( 'export', $result ),
            [ 'export_format' => 'csv', 'export_cap' => $exportCap ]
        );
    }

    // ------------------------------------------------------------------
    // Date range sugar
    // ------------------------------------------------------------------

    /**
     * Translates human date-range keywords into concrete filter conditions.
     * Supports: today, this_week, this_month, last_month, this_year, last_year,
     * and ISO date pairs { from, to }.
     */
    private function applyDateRange( array $interpretation ): array {
        $dateRange = (array) ( $interpretation['date_range'] ?? [] );
        if ( $dateRange === [] ) {
            return $interpretation;
        }

        $entity    = strtolower( trim( (string) ( $interpretation['entity'] ?? '' ) ) );
        $cap       = $this->capability->forEntity(
            $this->entityRegistry->resolve( $entity ) ?? $entity
        );
        $dateFields = (array) ( $cap['date_fields'] ?? [] );

        // Pick the field: explicit > first date field in capability map
        $field = (string) ( $dateRange['field'] ?? $dateFields[0] ?? '' );
        if ( $field === '' || ! in_array( $field, $dateFields, true ) ) {
            return $interpretation; // can't apply — skip silently
        }

        [ $from, $to ] = $this->resolveDateRange( (string) ( $dateRange['preset'] ?? '' ), $dateRange );

        $filters = (array) ( $interpretation['filters'] ?? [] );
        if ( $from !== '' ) {
            $filters[] = [ 'field' => $field, 'op' => '>=', 'value' => $from, 'type' => 'datetime' ];
        }
        if ( $to !== '' ) {
            $filters[] = [ 'field' => $field, 'op' => '<=', 'value' => $to,   'type' => 'datetime' ];
        }

        $interpretation['filters'] = $filters;
        return $interpretation;
    }

    private function resolveDateRange( string $preset, array $dateRange ): array {
        $now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

        return match ( $preset ) {
            'today'        => [ $now->format( 'Y-m-d 00:00:00' ), $now->format( 'Y-m-d 23:59:59' ) ],
            'this_week'    => [
                $now->modify( 'monday this week' )->format( 'Y-m-d 00:00:00' ),
                $now->modify( 'sunday this week' )->format( 'Y-m-d 23:59:59' ),
            ],
            'this_month'   => [
                $now->format( 'Y-m-01 00:00:00' ),
                $now->format( 'Y-m-t 23:59:59' ),
            ],
            'last_month'   => [
                $now->modify( 'first day of last month' )->format( 'Y-m-01 00:00:00' ),
                $now->modify( 'last day of last month' )->format( 'Y-m-t 23:59:59' ),
            ],
            'this_year'    => [ $now->format( 'Y-01-01 00:00:00' ), $now->format( 'Y-12-31 23:59:59' ) ],
            'last_year'    => [
                ( (int) $now->format( 'Y' ) - 1 ) . '-01-01 00:00:00',
                ( (int) $now->format( 'Y' ) - 1 ) . '-12-31 23:59:59',
            ],
            'ytd'          => [ $now->format( 'Y-01-01 00:00:00' ), $now->format( 'Y-m-d 23:59:59' ) ],
            default        => [
                (string) ( $dateRange['from'] ?? '' ),
                (string) ( $dateRange['to']   ?? '' ),
            ],
        };
    }

    // ------------------------------------------------------------------
    // Response shaping
    // ------------------------------------------------------------------

    private function response( string $type, array $result ): array {
        return [
            'ok'            => true,
            'report_type'   => $type,
            'status'        => 'success',
            'entity'        => (string) ( $result['entity'] ?? '' ),
            'data'          => (array)  ( $result['data']   ?? [] ),
            'total'         => (int)    ( $result['total']  ?? count( $result['data'] ?? [] ) ),
            'limit'         => (int)    ( $result['limit']  ?? 0 ),
            'offset'        => (int)    ( $result['offset'] ?? 0 ),
            'meta'          => array_diff_key( $result, array_flip( [ 'ok', 'data', 'entity', 'total', 'limit', 'offset', 'message' ] ) ),
        ];
    }

    private function error( string $message ): array {
        return [
            'ok'          => false,
            'report_type' => 'error',
            'status'      => 'error',
            'message'     => $message,
            'data'        => [],
            'total'       => 0,
        ];
    }
}
