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
        $entity = strtolower( trim( (string) ( $interpretation['entity'] ?? '' ) ) );
        if ( $entity === 'donation_transaction' ) {
            return $this->listDonationTransactions( $interpretation, $actor );
        }
        if ( $entity === 'donation_campaign' ) {
            return $this->listDonationCampaigns( $interpretation, $actor );
        }

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
        $entity = strtolower( trim( (string) ( $interpretation['entity'] ?? '' ) ) );
        if ( $entity === 'donor' ) {
            return $this->topDonors( $interpretation, $actor );
        }
        if ( $entity === 'donation_campaign' ) {
            return $this->topCampaigns( $interpretation, $actor );
        }

        $n = max( 1, min( (int) ( $interpretation['top_n'] ?? 10 ), 100 ) );

        $interpretation = $this->applyDateRange( $interpretation );
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

    private function listDonationTransactions( array $interpretation, array $actor ): array {
        $viewPermission = 'donations.view';
        $permCheck = $this->validateViewPermission( $viewPermission, $actor );
        if ( $permCheck !== null ) {
            return $permCheck;
        }

        $db = \Metis\Core\Application::service( 'db' );
        $transactionsTable = \Metis_Tables::get( 'transactions' );
        $contactsTable = \Metis_Tables::get( 'contacts' );
        $campaignsTable = \Metis_Tables::get( 'campaigns' );

        $limit = min( max( 1, (int) ( $interpretation['limit'] ?? 50 ) ), 100 );
        $offset = max( 0, (int) ( $interpretation['offset'] ?? 0 ) );

        $whereParts = [];
        $params = [];
        $this->applyDonationTransactionFilters( $whereParts, $params, (array) ( $interpretation['filters'] ?? [] ) );
        $this->applyDonationTransactionDateRange( $whereParts, $params, (array) ( $interpretation['date_range'] ?? [] ) );
        $whereClause = $whereParts !== [] ? 'WHERE ' . implode( ' AND ', $whereParts ) : '';

        $total = (int) $db->scalar(
            "SELECT COUNT(*) FROM {$transactionsTable} t {$whereClause}",
            $params
        );

        $rows = $db->fetchAll(
            "SELECT
                t.id,
                t.tid AS transaction_uid,
                t.tran_date AS transaction_date,
                t.amount,
                t.status,
                t.payment_method,
                t.did AS donor_code,
                t.campaign_code,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, ''))), ''), d.email, t.did, 'Unknown donor') AS donor_name,
                COALESCE(c.cname, t.campaign_code, '') AS campaign_name
             FROM {$transactionsTable} t
             LEFT JOIN {$contactsTable} d ON d.did = t.did
             LEFT JOIN {$campaignsTable} c
                ON c.cid = t.campaign_code
                OR COALESCE(c.campaign_uid, '') = t.campaign_code
                OR CAST(c.id AS CHAR) = t.campaign_code
             {$whereClause}
             ORDER BY t.tran_date DESC, t.id DESC
             LIMIT %d OFFSET %d",
            array_merge( $params, [ $limit, $offset ] )
        ) ?: [];

        $data = array_map( static function ( array $row ): array {
            return [
                'donor_name' => (string) ( $row['donor_name'] ?? '' ),
                'transaction_date' => (string) ( $row['transaction_date'] ?? '' ),
                'campaign_name' => (string) ( $row['campaign_name'] ?? '' ),
                'amount' => isset( $row['amount'] ) ? (float) $row['amount'] : 0.0,
                'status' => (string) ( $row['status'] ?? '' ),
                'payment_method' => (string) ( $row['payment_method'] ?? '' ),
                'transaction_uid' => (string) ( $row['transaction_uid'] ?? '' ),
            ];
        }, $rows );

        return $this->response( 'list', [
            'ok' => true,
            'entity' => 'donation_transaction',
            'data' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ] );
    }

    private function listDonationCampaigns( array $interpretation, array $actor ): array {
        $viewPermission = 'donations.view';
        $permCheck = $this->validateViewPermission( $viewPermission, $actor );
        if ( $permCheck !== null ) {
            return $permCheck;
        }

        $db = \Metis\Core\Application::service( 'db' );
        $campaignsTable = \Metis_Tables::get( 'campaigns' );

        $limit = min( max( 1, (int) ( $interpretation['limit'] ?? 50 ) ), 100 );
        $offset = max( 0, (int) ( $interpretation['offset'] ?? 0 ) );
        $whereParts = [];
        $params = [];
        $this->applyDonationCampaignFilters( $whereParts, $params, (array) ( $interpretation['filters'] ?? [] ) );
        $whereClause = $whereParts !== [] ? 'WHERE ' . implode( ' AND ', $whereParts ) : '';

        $total = (int) $db->scalar( "SELECT COUNT(*) FROM {$campaignsTable} {$whereClause}", $params );
        $rows = $db->fetchAll(
            "SELECT
                cid AS campaign_uid,
                cname AS name,
                active,
                goal,
                created_at
             FROM {$campaignsTable}
             {$whereClause}
             ORDER BY active DESC, created_at DESC, id DESC
             LIMIT %d OFFSET %d",
            array_merge( $params, [ $limit, $offset ] )
        ) ?: [];

        $data = array_map( static function ( array $row ): array {
            return [
                'name' => (string) ( $row['name'] ?? '' ),
                'campaign_uid' => (string) ( $row['campaign_uid'] ?? '' ),
                'status' => ! empty( $row['active'] ) ? 'active' : 'inactive',
                'goal_amount' => isset( $row['goal'] ) ? (float) $row['goal'] : 0.0,
                'created_at' => (string) ( $row['created_at'] ?? '' ),
            ];
        }, $rows );

        return $this->response( 'list', [
            'ok' => true,
            'entity' => 'donation_campaign',
            'data' => $data,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ] );
    }

    private function topDonors( array $interpretation, array $actor ): array {
        $viewPermission = 'donations.view';
        $permCheck = $this->validateViewPermission( $viewPermission, $actor );
        if ( $permCheck !== null ) {
            return $permCheck;
        }

        $db = \Metis\Core\Application::service( 'db' );
        $transactionsTable = \Metis_Tables::get( 'transactions' );
        $contactsTable = \Metis_Tables::get( 'contacts' );

        $n = max( 1, min( (int) ( $interpretation['top_n'] ?? $interpretation['limit'] ?? 10 ), 100 ) );
        $whereParts = [
            "t.did IS NOT NULL",
            "t.did <> ''",
        ];
        $params = [];
        $this->applyDonationTransactionDateRange( $whereParts, $params, (array) ( $interpretation['date_range'] ?? [] ) );
        $whereClause = 'WHERE ' . implode( ' AND ', $whereParts );

        $rows = $db->fetchAll(
            "SELECT
                t.did,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''), c.email, t.did) AS donor_name,
                c.email,
                COUNT(*) AS gift_count,
                COALESCE(SUM(t.amount), 0) AS total_raised,
                MAX(t.tran_date) AS last_gift_date
             FROM {$transactionsTable} t
             LEFT JOIN {$contactsTable} c ON c.did = t.did
             {$whereClause}
             GROUP BY t.did
             ORDER BY total_raised DESC, last_gift_date DESC
             LIMIT %d",
            array_merge( $params, [ $n ] )
        ) ?: [];

        $data = array_map( static function ( array $row ): array {
            return [
                'did' => (string) ( $row['did'] ?? '' ),
                'donor_name' => (string) ( $row['donor_name'] ?? '' ),
                'email' => (string) ( $row['email'] ?? '' ),
                'gift_count' => (int) ( $row['gift_count'] ?? 0 ),
                'total_raised' => isset( $row['total_raised'] ) ? (float) $row['total_raised'] : 0.0,
                'last_gift_date' => (string) ( $row['last_gift_date'] ?? '' ),
            ];
        }, $rows );

        return $this->response( 'top', [
            'ok' => true,
            'entity' => 'donor',
            'data' => $data,
            'total' => count( $data ),
            'limit' => $n,
            'offset' => 0,
            'top_n' => $n,
        ] );
    }

    private function topCampaigns( array $interpretation, array $actor ): array {
        $viewPermission = 'donations.view';
        $permCheck = $this->validateViewPermission( $viewPermission, $actor );
        if ( $permCheck !== null ) {
            return $permCheck;
        }

        $db = \Metis\Core\Application::service( 'db' );
        $transactionsTable = \Metis_Tables::get( 'transactions' );
        $campaignsTable = \Metis_Tables::get( 'campaigns' );

        $n = max( 1, min( (int) ( $interpretation['top_n'] ?? $interpretation['limit'] ?? 10 ), 100 ) );
        $whereParts = [
            "COALESCE(t.campaign_code, '') <> ''",
        ];
        $params = [];
        $this->applyDonationTransactionDateRange( $whereParts, $params, (array) ( $interpretation['date_range'] ?? [] ) );
        $whereClause = 'WHERE ' . implode( ' AND ', $whereParts );

        $rows = $db->fetchAll(
            "SELECT
                COALESCE(c.cid, t.campaign_code) AS campaign_uid,
                COALESCE(NULLIF(TRIM(c.cname), ''), t.campaign_code, 'Unknown campaign') AS name,
                COUNT(*) AS gift_count,
                COALESCE(SUM(t.amount), 0) AS total_raised,
                MAX(t.tran_date) AS last_gift_date
             FROM {$transactionsTable} t
             LEFT JOIN {$campaignsTable} c
                ON c.cid = t.campaign_code
                OR COALESCE(c.campaign_uid, '') = t.campaign_code
                OR CAST(c.id AS CHAR) = t.campaign_code
             {$whereClause}
             GROUP BY COALESCE(c.cid, t.campaign_code), COALESCE(NULLIF(TRIM(c.cname), ''), t.campaign_code, 'Unknown campaign')
             ORDER BY total_raised DESC, last_gift_date DESC
             LIMIT %d",
            array_merge( $params, [ $n ] )
        ) ?: [];

        $data = array_map( static function ( array $row ): array {
            return [
                'campaign_uid' => (string) ( $row['campaign_uid'] ?? '' ),
                'name' => (string) ( $row['name'] ?? '' ),
                'gift_count' => (int) ( $row['gift_count'] ?? 0 ),
                'total_raised' => isset( $row['total_raised'] ) ? (float) $row['total_raised'] : 0.0,
                'last_gift_date' => (string) ( $row['last_gift_date'] ?? '' ),
            ];
        }, $rows );

        return $this->response( 'top', [
            'ok' => true,
            'entity' => 'donation_campaign',
            'data' => $data,
            'total' => count( $data ),
            'limit' => $n,
            'offset' => 0,
            'top_n' => $n,
        ] );
    }

    private function validateViewPermission( string $viewPermission, array $actor ): ?array {
        $permCheck = \Metis\Core\Application::service( 'hermes_permission_validator' )->validate(
            [ 'permission' => $viewPermission ],
            $actor
        );
        if ( (string) ( $permCheck['status'] ?? '' ) === 'granted' ) {
            return null;
        }

        return $this->error( "Permission denied: {$viewPermission} required." );
    }

    private function applyDonationTransactionFilters( array &$whereParts, array &$params, array $filters ): void {
        foreach ( $filters as $filter ) {
            if ( ! is_array( $filter ) ) {
                continue;
            }

            $field = (string) ( $filter['field'] ?? '' );
            $op = strtoupper( trim( (string) ( $filter['op'] ?? '=' ) ) );
            $value = $filter['value'] ?? null;

            $column = match ( $field ) {
                'status' => 't.status',
                'type' => 't.payment_method',
                'campaign_id' => 't.campaign_code',
                'deposit_id' => 't.deposit_batch_id',
                default => '',
            };

            if ( $column === '' || ! in_array( $op, [ '=', '!=', '>=', '<=', '>', '<' ], true ) ) {
                continue;
            }

            $whereParts[] = "{$column} {$op} %s";
            $params[] = $value;
        }
    }

    private function applyDonationTransactionDateRange( array &$whereParts, array &$params, array $dateRange ): void {
        if ( $dateRange === [] ) {
            return;
        }

        [ $from, $to ] = $this->resolveDateRange( (string) ( $dateRange['preset'] ?? '' ), $dateRange );
        if ( $from !== '' ) {
            $whereParts[] = 't.tran_date >= %s';
            $params[] = $from;
        }
        if ( $to !== '' ) {
            $whereParts[] = 't.tran_date <= %s';
            $params[] = $to;
        }
    }

    private function applyDonationCampaignFilters( array &$whereParts, array &$params, array $filters ): void {
        foreach ( $filters as $filter ) {
            if ( ! is_array( $filter ) ) {
                continue;
            }

            $field = (string) ( $filter['field'] ?? '' );
            $op = strtolower( trim( (string) ( $filter['op'] ?? '=' ) ) );
            $value = trim( (string) ( $filter['value'] ?? '' ) );

            if ( $field === 'name' && $value !== '' && in_array( $op, [ '=', 'contains' ], true ) ) {
                if ( $op === 'contains' ) {
                    $whereParts[] = 'cname LIKE %s';
                    $params[] = '%' . $value . '%';
                } else {
                    $whereParts[] = 'cname = %s';
                    $params[] = $value;
                }
                continue;
            }

            if ( $field === 'status' && $value !== '' ) {
                $whereParts[] = 'active = %d';
                $params[] = $value === 'active' ? 1 : 0;
            }
        }
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
