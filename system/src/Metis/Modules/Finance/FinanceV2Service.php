<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class FinanceV2Service {
    public static function bootstrapData( int $entriesLimit = 25 ): array {
        SchemaManager::ensureSchema();

        return [
            'mode' => ModeService::currentMode(),
            'switch' => ModeService::switchStatus(),
            'summary' => self::summary(),
            'accounts' => self::accounts(),
            'categories' => self::categories(),
            'categories_all' => self::categories( true ),
            'entries' => self::entries( max( 1, min( 100, $entriesLimit ) ) ),
            'reconciliation' => self::manualReconciliationWorkflow(),
            'reconciliation_runs' => self::reconciliationRuns( 20 ),
            'reconciliation_mappings' => self::reconciliationMappings( '' ),
            'reconciliation_review_queue' => self::reconciliationReviewQueue( 20 ),
            'budget' => self::budgetSnapshot( 0 ),
            'invoices' => self::invoiceSnapshot( 20 ),
            'fiscal' => self::fiscalSettingsSnapshot(),
            'reports' => self::reportSnapshot(),
            'performance' => self::performanceSnapshot(),
            'stripe_overview' => self::stripeOverview(),
            'stripe_suggestions' => self::stripeClearingSuggestions(),
            'stripe_payouts' => self::stripePayouts( 20 ),
            'bank_lines' => self::bankLines( 20 ),
        ];
    }

    public static function summary(): array {
        $db = \metis_db();
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );
        $reconRunsTable = \Metis_Tables::get( 'finance_v2_recon_parse_runs' );
        $payoutsTable = \Metis_Tables::get( 'finance_v2_stripe_payouts' );
        $bankLinesTable = \Metis_Tables::get( 'finance_v2_bank_lines' );
        $orgId = ModeService::orgId();

        $entriesSummary = $db->fetchOne(
            "SELECT
                COUNT(*) AS total_entries,
                COALESCE(SUM(CASE WHEN amount_signed >= 0 THEN amount_signed ELSE 0 END), 0) AS total_debits,
                COALESCE(SUM(CASE WHEN amount_signed < 0 THEN ABS(amount_signed) ELSE 0 END), 0) AS total_credits,
                COALESCE(SUM(CASE WHEN reconciliation_status = 'unmatched' THEN 1 ELSE 0 END), 0) AS unmatched_entries,
                COALESCE(SUM(CASE WHEN entry_date >= DATE_FORMAT(UTC_DATE(), '%Y-%m-01') THEN 1 ELSE 0 END), 0) AS mtd_entries
             FROM {$entriesTable}
             WHERE org_id = %d",
            [ $orgId ]
        ) ?: [];

        $reconSummary = $db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN confidence_band = 'autosuggest' THEN 1 ELSE 0 END), 0) AS autosuggest_count,
                COALESCE(SUM(CASE WHEN confidence_band = 'review' THEN 1 ELSE 0 END), 0) AS review_count,
                COALESCE(SUM(CASE WHEN confidence_band = 'manual' THEN 1 ELSE 0 END), 0) AS manual_count
             FROM {$reconRunsTable}
             WHERE org_id = %d",
            [ $orgId ]
        ) ?: [];

        $stripeSummary = $db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'expected' THEN 1 ELSE 0 END), 0) AS expected_payouts,
                COALESCE(SUM(CASE WHEN status = 'matched' THEN 1 ELSE 0 END), 0) AS matched_payouts
             FROM {$payoutsTable}
             WHERE org_id = %d",
            [ $orgId ]
        ) ?: [];

        $bankSummary = $db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'unmatched' THEN 1 ELSE 0 END), 0) AS unmatched_bank_lines
             FROM {$bankLinesTable}
             WHERE org_id = %d",
            [ $orgId ]
        ) ?: [];

        return [
            'total_entries' => (int) ( $entriesSummary['total_entries'] ?? 0 ),
            'total_debits' => (float) ( $entriesSummary['total_debits'] ?? 0 ),
            'total_credits' => (float) ( $entriesSummary['total_credits'] ?? 0 ),
            'unmatched_entries' => (int) ( $entriesSummary['unmatched_entries'] ?? 0 ),
            'mtd_entries' => (int) ( $entriesSummary['mtd_entries'] ?? 0 ),
            'autosuggest_count' => (int) ( $reconSummary['autosuggest_count'] ?? 0 ),
            'review_count' => (int) ( $reconSummary['review_count'] ?? 0 ),
            'manual_count' => (int) ( $reconSummary['manual_count'] ?? 0 ),
            'expected_payouts' => (int) ( $stripeSummary['expected_payouts'] ?? 0 ),
            'matched_payouts' => (int) ( $stripeSummary['matched_payouts'] ?? 0 ),
            'unmatched_bank_lines' => (int) ( $bankSummary['unmatched_bank_lines'] ?? 0 ),
        ];
    }

    public static function performanceSnapshot(): array {
        $orgId = ModeService::orgId();
        $db = \metis_db();
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );
        $invoicesTable = \Metis_Tables::get( 'finance_v2_invoices' );

        $months = [];
        $monthKeys = [];
        $cursor = new \DateTimeImmutable( 'first day of this month', new \DateTimeZone( 'UTC' ) );
        for ( $i = 11; $i >= 0; $i-- ) {
            $point = $cursor->modify( '-' . $i . ' months' );
            $key = $point->format( 'Y-m' );
            $months[ $key ] = [
                'month_key' => $key,
                'month_label' => $point->format( 'M y' ),
            ];
            $monthKeys[] = $key;
        }

        $startMonth = reset( $monthKeys );
        if ( ! is_string( $startMonth ) || $startMonth === '' ) {
            $startMonth = gmdate( 'Y-m' );
        }
        $startDate = $startMonth . '-01';

        $glRows = $db->fetchAll(
            "SELECT
                DATE_FORMAT(entry_date, '%%Y-%%m') AS month_key,
                COALESCE(SUM(CASE WHEN amount_signed >= 0 THEN amount_signed ELSE 0 END), 0) AS debit_amount,
                COALESCE(SUM(CASE WHEN amount_signed < 0 THEN ABS(amount_signed) ELSE 0 END), 0) AS credit_amount,
                COALESCE(SUM(amount_signed), 0) AS net_amount
             FROM {$entriesTable}
             WHERE org_id = %d
               AND entry_date >= %s
             GROUP BY DATE_FORMAT(entry_date, '%%Y-%%m')
             ORDER BY month_key ASC",
            [ $orgId, $startDate ]
        );

        $glByMonth = [];
        foreach ( $glRows as $row ) {
            $key = (string) ( $row['month_key'] ?? '' );
            if ( $key === '' ) {
                continue;
            }
            $glByMonth[ $key ] = [
                'debit_amount' => round( (float) ( $row['debit_amount'] ?? 0 ), 2 ),
                'credit_amount' => round( (float) ( $row['credit_amount'] ?? 0 ), 2 ),
                'net_amount' => round( (float) ( $row['net_amount'] ?? 0 ), 2 ),
            ];
        }

        $invoiceRows = $db->fetchAll(
            "SELECT
                DATE_FORMAT(issued_date, '%%Y-%%m') AS month_key,
                COUNT(*) AS invoice_count,
                COALESCE(SUM(total_amount), 0) AS total_amount
             FROM {$invoicesTable}
             WHERE org_id = %d
               AND issued_date >= %s
             GROUP BY DATE_FORMAT(issued_date, '%%Y-%%m')
             ORDER BY month_key ASC",
            [ $orgId, $startDate ]
        );

        $invoiceByMonth = [];
        foreach ( $invoiceRows as $row ) {
            $key = (string) ( $row['month_key'] ?? '' );
            if ( $key === '' ) {
                continue;
            }
            $invoiceByMonth[ $key ] = [
                'invoice_count' => (int) ( $row['invoice_count'] ?? 0 ),
                'total_amount' => round( (float) ( $row['total_amount'] ?? 0 ), 2 ),
            ];
        }

        $monthlyNet = [];
        $monthlyInvoices = [];
        foreach ( $months as $key => $meta ) {
            $gl = $glByMonth[ $key ] ?? [ 'debit_amount' => 0.0, 'credit_amount' => 0.0, 'net_amount' => 0.0 ];
            $inv = $invoiceByMonth[ $key ] ?? [ 'invoice_count' => 0, 'total_amount' => 0.0 ];

            $monthlyNet[] = [
                'month_key' => $meta['month_key'],
                'month_label' => $meta['month_label'],
                'debit_amount' => $gl['debit_amount'],
                'credit_amount' => $gl['credit_amount'],
                'net_amount' => $gl['net_amount'],
            ];

            $monthlyInvoices[] = [
                'month_key' => $meta['month_key'],
                'month_label' => $meta['month_label'],
                'invoice_count' => $inv['invoice_count'],
                'total_amount' => $inv['total_amount'],
            ];
        }

        return [
            'monthly_net_activity' => $monthlyNet,
            'monthly_invoice_totals' => $monthlyInvoices,
        ];
    }

    public static function accounts(): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_accounts' );
        $rows = $db->fetchAll(
            "SELECT account_code, account_name, account_type
             FROM {$table}
             WHERE org_id = %d AND is_active = 1
             ORDER BY sort_order ASC, account_name ASC",
            [ ModeService::orgId() ]
        );

        return array_map(
            static fn( array $row ): array => [
                'account_code' => (string) ( $row['account_code'] ?? '' ),
                'account_name' => (string) ( $row['account_name'] ?? '' ),
                'account_type' => (string) ( $row['account_type'] ?? '' ),
            ],
            $rows
        );
    }

    public static function categories( bool $includeInactive = false ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_categories' );
        $where = $includeInactive ? 'org_id = %d' : 'org_id = %d AND is_active = 1';
        $rows = $db->fetchAll(
            "SELECT category_code, category_name
             FROM {$table}
             WHERE {$where}
             ORDER BY sort_order ASC, category_name ASC",
            [ ModeService::orgId() ]
        );

        return array_map(
            static fn( array $row ): array => [
                'category_code' => (string) ( $row['category_code'] ?? '' ),
                'category_name' => (string) ( $row['category_name'] ?? '' ),
            ],
            $rows
        );
    }

    public static function saveCategory( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_categories' );
        $orgId = ModeService::orgId();

        $categoryName = metis_text_clean( (string) ( $input['category_name'] ?? '' ) );
        if ( $categoryName === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Category name is required.' ];
        }

        $categoryCode = metis_key_clean( (string) ( $input['category_code'] ?? '' ) );
        if ( $categoryCode === '' ) {
            $categoryCode = metis_key_clean( strtolower( preg_replace( '/[^a-z0-9]+/i', '_', $categoryName ) ?? '' ) );
        }
        if ( $categoryCode === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Category code is invalid.' ];
        }

        $isActive = isset( $input['is_active'] ) ? ( (int) $input['is_active'] === 1 ? 1 : 0 ) : 1;
        $sortOrder = isset( $input['sort_order'] ) ? (int) $input['sort_order'] : 0;

        $existing = $db->fetchOne(
            "SELECT id FROM {$table} WHERE org_id = %d AND category_code = %s LIMIT 1",
            [ $orgId, $categoryCode ]
        );

        if ( is_array( $existing ) && (int) ( $existing['id'] ?? 0 ) > 0 ) {
            $db->update(
                $table,
                [
                    'category_name' => $categoryName,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                    'updated_at' => Support::now(),
                ],
                [ 'id' => (int) $existing['id'], 'org_id' => $orgId ],
                [ '%s', '%d', '%d', '%s' ],
                [ '%d', '%d' ]
            );
        } else {
            $db->insert(
                $table,
                [
                    'org_id' => $orgId,
                    'category_code' => $categoryCode,
                    'category_name' => $categoryName,
                    'is_active' => $isActive,
                    'sort_order' => $sortOrder,
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
            );
        }

        return [
            'ok' => true,
            'categories' => self::categories(),
            'categories_all' => self::categories( true ),
        ];
    }

    public static function entries( int $limit = 50 ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_gl_entries' );

        $rows = $db->fetchAll(
            "SELECT id, entry_date, account_code, description, amount_signed, amount_abs, dc_type, category_code, source_type, reconciliation_status, created_at
             FROM {$table}
             WHERE org_id = %d
             ORDER BY entry_date DESC, id DESC
             LIMIT %d",
            [ ModeService::orgId(), max( 1, min( 200, $limit ) ) ]
        );

        return array_map( [ self::class, 'mapEntryRow' ], $rows );
    }

    public static function createEntry( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $orgId = ModeService::orgId();
        $db = \metis_db();
        $accountsTable = \Metis_Tables::get( 'finance_v2_accounts' );
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );

        $entryDate = metis_text_clean( (string) ( $input['entry_date'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $entryDate ) ) {
            $entryDate = gmdate( 'Y-m-d' );
        }
        if ( self::isPostingBlockedByFinalizedReconciliation( $entryDate ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'This month is finalized. Reopen reconciliation before adding entries.' ];
        }

        $accountCode = metis_key_clean( (string) ( $input['account_code'] ?? '' ) );
        if ( $accountCode === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Account is required.' ];
        }

        $accountExists = (int) $db->scalar(
            "SELECT COUNT(*) FROM {$accountsTable} WHERE org_id = %d AND account_code = %s AND is_active = 1",
            [ $orgId, $accountCode ]
        );

        if ( $accountExists < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Selected account is unavailable.' ];
        }

        $description = metis_text_clean( (string) ( $input['description'] ?? '' ) );
        if ( $description === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Description is required.' ];
        }

        $rawAmount = round( (float) ( $input['amount'] ?? 0 ), 2 );
        $dcType = metis_key_clean( (string) ( $input['dc_type'] ?? '' ) );
        if ( ! in_array( $dcType, [ 'debit', 'credit' ], true ) ) {
            $dcType = $rawAmount < 0 ? 'credit' : 'debit';
        }

        $amountAbs = round( abs( $rawAmount ), 2 );
        if ( $amountAbs <= 0 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Amount must be greater than zero.' ];
        }

        if ( $rawAmount < 0 ) {
            $amountSigned = 0 - $amountAbs;
        } else {
            $amountSigned = $dcType === 'credit' ? ( 0 - $amountAbs ) : $amountAbs;
        }

        $categoryCode = metis_key_clean( (string) ( $input['category_code'] ?? '' ) );
        if ( $categoryCode === '' ) {
            $categoryCode = null;
        }

        $inserted = $db->insert(
            $entriesTable,
            [
                'org_id' => $orgId,
                'entry_date' => $entryDate,
                'account_code' => $accountCode,
                'description' => $description,
                'amount_signed' => $amountSigned,
                'amount_abs' => $amountAbs,
                'dc_type' => $dcType,
                'category_code' => $categoryCode,
                'source_type' => 'manual',
                'reconciliation_status' => 'unmatched',
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not save entry.' ];
        }

        $entryId = (int) $db->lastInsertId();
        $entryRow = $db->fetchOne(
            "SELECT id, entry_date, account_code, description, amount_signed, amount_abs, dc_type, category_code, source_type, reconciliation_status, created_at
             FROM {$entriesTable}
             WHERE id = %d LIMIT 1",
            [ $entryId ]
        );

        return [
            'ok' => true,
            'entry' => self::mapEntryRow( is_array( $entryRow ) ? $entryRow : [] ),
            'summary' => self::summary(),
        ];
    }

    public static function createReconciliationImportRun( array $input, int $requestedBy = 0, array $files = [] ): array {
        SchemaManager::ensureSchema();
        $orgId = ModeService::orgId();
        $db = \metis_db();
        $monthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );

        $month = self::normalizeReconMonth( (string) ( $input['recon_month'] ?? '' ) );
        if ( $month === '' ) {
            $month = self::defaultReconMonth();
        }
        $firstDay = $month . '-01';

        $statementEndingRaw = trim( (string) ( $input['statement_ending_balance'] ?? '' ) );
        if ( $statementEndingRaw === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Statement ending balance is required.' ];
        }
        $statementEnding = round( (float) $statementEndingRaw, 2 );

        $existing = $db->fetchOne(
            "SELECT * FROM {$monthsTable} WHERE org_id = %d AND recon_month = %s LIMIT 1",
            [ $orgId, $firstDay ]
        );
        if ( is_array( $existing ) && (string) ( $existing['status'] ?? '' ) === 'finalized' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'This month is finalized. Reopen it before updating.' ];
        }

        $activeOpen = $db->fetchOne(
            "SELECT id, recon_month
             FROM {$monthsTable}
             WHERE org_id = %d
               AND status IN ('open', 'reopened')
             ORDER BY recon_month DESC, id DESC
             LIMIT 1",
            [ $orgId ]
        );
        if ( is_array( $activeOpen ) ) {
            $activeId = (int) ( $activeOpen['id'] ?? 0 );
            $existingId = is_array( $existing ) ? (int) ( $existing['id'] ?? 0 ) : 0;
            if ( $activeId > 0 && $activeId !== $existingId ) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'message' => 'An unfinished reconciliation already exists. Delete it before starting a new month.',
                ];
            }
        }

        $startingBalanceRaw = trim( (string) ( $input['starting_balance'] ?? '' ) );
        $startingBalance = $startingBalanceRaw !== ''
            ? round( (float) $startingBalanceRaw, 2 )
            : self::startingBalanceForMonth( $firstDay );
        $upload = self::extractUploadedFile( $files, 'recon_file' );
        $statementFileName = '';
        $statementToken = '';
        $statementUrl = '';
        if ( is_array( $upload ) && ! empty( $upload['tmp_name'] ) ) {
            $result = self::storeReconciliationStatement( $upload );
            if ( empty( $result['ok'] ) ) {
                return [ 'ok' => false, 'status' => 422, 'message' => (string) ( $result['message'] ?? 'Failed to upload statement PDF.' ) ];
            }
            $statementFileName = (string) ( $result['file_name'] ?? '' );
            $statementToken = (string) ( $result['token'] ?? '' );
            $statementUrl = (string) ( $result['url'] ?? '' );
        }

        if ( is_array( $existing ) ) {
            $monthId = (int) ( $existing['id'] ?? 0 );
            $update = [
                'starting_balance' => $startingBalance,
                'statement_ending_balance' => $statementEnding,
                'updated_at' => Support::now(),
            ];
            $formats = [ '%f', '%f', '%s' ];
            if ( $statementToken !== '' ) {
                $update['statement_file_name'] = $statementFileName;
                $update['statement_media_token'] = $statementToken;
                $update['statement_media_url'] = $statementUrl;
                $formats[] = '%s';
                $formats[] = '%s';
                $formats[] = '%s';
            }
            $db->update( $monthsTable, $update, [ 'id' => $monthId, 'org_id' => $orgId ], $formats, [ '%d', '%d' ] );
        } else {
            $db->insert(
                $monthsTable,
                [
                    'org_id' => $orgId,
                    'recon_month' => $firstDay,
                    'status' => 'open',
                    'starting_balance' => $startingBalance,
                    'statement_ending_balance' => $statementEnding,
                    'expected_ending_balance' => $startingBalance,
                    'difference_amount' => round( $statementEnding - $startingBalance, 2 ),
                    'statement_file_name' => $statementFileName !== '' ? $statementFileName : null,
                    'statement_media_token' => $statementToken !== '' ? $statementToken : null,
                    'statement_media_url' => $statementUrl !== '' ? $statementUrl : null,
                    'created_by' => $requestedBy > 0 ? $requestedBy : null,
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );
            $monthId = (int) $db->lastInsertId();
        }

        if ( $monthId < 1 ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not start reconciliation month.' ];
        }

        self::ensureReconMonthItems( $monthId );
        self::recalculateReconMonth( $monthId );
        self::logReconMonthAudit( $monthId, 'started', '', $requestedBy );

        return [
            'ok' => true,
            'message' => 'Reconciliation month prepared.',
            'reconciliation' => self::manualReconciliationWorkflow( $monthId ),
            'summary' => self::summary(),
        ];
    }

    public static function manualReconciliationWorkflow( int $monthId = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $monthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );
        $itemsTable = \Metis_Tables::get( 'finance_v2_recon_month_items' );
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );
        $auditTable = \Metis_Tables::get( 'finance_v2_recon_month_audit' );

        $selected = null;
        if ( $monthId > 0 ) {
            $selected = $db->fetchOne(
                "SELECT * FROM {$monthsTable} WHERE org_id = %d AND id = %d LIMIT 1",
                [ $orgId, $monthId ]
            );
        }
        if ( ! is_array( $selected ) ) {
            $selected = $db->fetchOne(
                "SELECT * FROM {$monthsTable} WHERE org_id = %d ORDER BY recon_month DESC, id DESC LIMIT 1",
                [ $orgId ]
            );
        }

        $selectedMonthId = is_array( $selected ) ? (int) ( $selected['id'] ?? 0 ) : 0;
        if ( $selectedMonthId > 0 ) {
            self::ensureReconMonthItems( $selectedMonthId );
            self::recalculateReconMonth( $selectedMonthId );
            $selected = $db->fetchOne(
                "SELECT * FROM {$monthsTable} WHERE org_id = %d AND id = %d LIMIT 1",
                [ $orgId, $selectedMonthId ]
            );
        }

        $historyRows = $db->fetchAll(
            "SELECT id, recon_month, status, statement_ending_balance, expected_ending_balance, difference_amount, statement_file_name, statement_media_url, finalized_at
             FROM {$monthsTable}
             WHERE org_id = %d
             ORDER BY recon_month DESC, id DESC
             LIMIT 24",
            [ $orgId ]
        );

        $items = [];
        $auditRows = [];
        $actorNames = [];
        if ( is_array( $selected ) ) {
            $selectedMonthId = (int) ( $selected['id'] ?? 0 );
            $items = $db->fetchAll(
                "SELECT
                    i.id,
                    i.gl_entry_id,
                    i.is_cleared,
                    i.cleared_at,
                    e.entry_date,
                    e.account_code,
                    e.description,
                    e.amount_signed,
                    e.category_code
                 FROM {$itemsTable} i
                 INNER JOIN {$entriesTable} e
                    ON e.id = i.gl_entry_id
                   AND e.org_id = i.org_id
                 WHERE i.org_id = %d
                   AND i.recon_month_id = %d
                 ORDER BY e.entry_date ASC, e.id ASC",
                [ $orgId, $selectedMonthId ]
            );
            $auditRows = $db->fetchAll(
                "SELECT event_type, reason_text, actor_id, created_at
                 FROM {$auditTable}
                 WHERE org_id = %d AND recon_month_id = %d
                 ORDER BY id DESC
                 LIMIT 40",
                [ $orgId, $selectedMonthId ]
            );

            $actorIds = [];
            foreach ( is_array( $auditRows ) ? $auditRows : [] as $row ) {
                $actorId = (int) ( $row['actor_id'] ?? 0 );
                if ( $actorId > 0 ) {
                    $actorIds[ $actorId ] = true;
                }
            }
            if ( $actorIds !== [] ) {
                $peopleTable = \Metis_Tables::get( 'people' );
                $idList = array_keys( $actorIds );
                $placeholders = implode( ',', array_fill( 0, count( $idList ), '%d' ) );
                $nameRows = $db->fetchAll(
                    "SELECT id, display_name, first_name, last_name, email
                     FROM {$peopleTable}
                     WHERE id IN ({$placeholders})",
                    $idList
                );
                foreach ( is_array( $nameRows ) ? $nameRows : [] as $nameRow ) {
                    $id = (int) ( $nameRow['id'] ?? 0 );
                    if ( $id < 1 ) {
                        continue;
                    }
                    $display = trim( (string) ( $nameRow['display_name'] ?? '' ) );
                    $full = trim( (string) ( $nameRow['first_name'] ?? '' ) . ' ' . (string) ( $nameRow['last_name'] ?? '' ) );
                    $email = trim( (string) ( $nameRow['email'] ?? '' ) );
                    $actorNames[ $id ] = $display !== '' ? $display : ( $full !== '' ? $full : ( $email !== '' ? $email : '' ) );
                }
            }
        }

        $defaultMonth = self::defaultReconMonth();
        $defaultStart = $defaultMonth . '-01';

        $monthSummary = is_array( $selected ) ? [
            'id' => (int) ( $selected['id'] ?? 0 ),
            'recon_month' => (string) ( $selected['recon_month'] ?? $defaultStart ),
            'recon_month_key' => substr( (string) ( $selected['recon_month'] ?? $defaultStart ), 0, 7 ),
            'status' => (string) ( $selected['status'] ?? 'open' ),
            'starting_balance' => round( (float) ( $selected['starting_balance'] ?? 0 ), 2 ),
            'statement_ending_balance' => round( (float) ( $selected['statement_ending_balance'] ?? 0 ), 2 ),
            'expected_ending_balance' => round( (float) ( $selected['expected_ending_balance'] ?? 0 ), 2 ),
            'difference_amount' => round( (float) ( $selected['difference_amount'] ?? 0 ), 2 ),
            'statement_file_name' => (string) ( $selected['statement_file_name'] ?? '' ),
            'statement_media_url' => (string) ( $selected['statement_media_url'] ?? '' ),
            'finalized_at' => (string) ( $selected['finalized_at'] ?? '' ),
        ] : [
            'id' => 0,
            'recon_month' => $defaultStart,
            'recon_month_key' => $defaultMonth,
            'status' => 'not_started',
            'starting_balance' => self::startingBalanceForMonth( $defaultStart ),
            'statement_ending_balance' => 0.0,
            'expected_ending_balance' => 0.0,
            'difference_amount' => 0.0,
            'statement_file_name' => '',
            'statement_media_url' => '',
            'finalized_at' => '',
        ];

        $itemRows = array_map(
            static fn( array $row ): array => [
                'item_id' => (int) ( $row['id'] ?? 0 ),
                'gl_entry_id' => (int) ( $row['gl_entry_id'] ?? 0 ),
                'is_cleared' => (int) ( $row['is_cleared'] ?? 0 ) === 1,
                'cleared_at' => (string) ( $row['cleared_at'] ?? '' ),
                'entry_date' => (string) ( $row['entry_date'] ?? '' ),
                'account_code' => (string) ( $row['account_code'] ?? '' ),
                'description' => (string) ( $row['description'] ?? '' ),
                'amount_signed' => round( (float) ( $row['amount_signed'] ?? 0 ), 2 ),
                'category_code' => (string) ( $row['category_code'] ?? '' ),
            ],
            is_array( $items ) ? $items : []
        );

        $clearedCount = 0;
        $clearedAmount = 0.0;
        $unClearedAmount = 0.0;
        foreach ( $itemRows as $row ) {
            if ( ! empty( $row['is_cleared'] ) ) {
                $clearedCount++;
                $clearedAmount += (float) ( $row['amount_signed'] ?? 0 );
            } else {
                $unClearedAmount += (float) ( $row['amount_signed'] ?? 0 );
            }
        }

        $history = array_map(
            static function ( array $row ): array {
                $status = (string) ( $row['status'] ?? '' );
                return [
                    'id' => (int) ( $row['id'] ?? 0 ),
                    'recon_month' => (string) ( $row['recon_month'] ?? '' ),
                    'status' => $status,
                    'statement_ending_balance' => round( (float) ( $row['statement_ending_balance'] ?? 0 ), 2 ),
                    'expected_ending_balance' => round( (float) ( $row['expected_ending_balance'] ?? 0 ), 2 ),
                    'difference_amount' => round( (float) ( $row['difference_amount'] ?? 0 ), 2 ),
                    'statement_file_name' => (string) ( $row['statement_file_name'] ?? '' ),
                    'statement_media_url' => (string) ( $row['statement_media_url'] ?? '' ),
                    'finalized_at' => (string) ( $row['finalized_at'] ?? '' ),
                    'can_delete' => in_array( $status, [ 'open', 'reopened' ], true ),
                ];
            },
            is_array( $historyRows ) ? $historyRows : []
        );

        $audit = array_map(
            static function ( array $row ) use ( $actorNames ): array {
                $actorId = (int) ( $row['actor_id'] ?? 0 );
                return [
                    'event_type' => (string) ( $row['event_type'] ?? '' ),
                    'reason_text' => (string) ( $row['reason_text'] ?? '' ),
                    'actor_id' => $actorId,
                    'actor_name' => $actorId > 0 ? (string) ( $actorNames[ $actorId ] ?? '' ) : '',
                    'created_at' => (string) ( $row['created_at'] ?? '' ),
                ];
            },
            is_array( $auditRows ) ? $auditRows : []
        );

        $difference = round( (float) ( $monthSummary['difference_amount'] ?? 0 ), 2 );
        $isBalanced = abs( $difference ) < 0.01;
        $status = (string) ( $monthSummary['status'] ?? 'not_started' );
        $canFinalize = ( $status === 'open' || $status === 'reopened' ) && $isBalanced;
        $canReopen = $status === 'finalized' && ModeService::currentUserHasFinanceRole();

        return [
            'current_month' => $monthSummary,
            'totals' => [
                'entry_count' => count( $itemRows ),
                'cleared_count' => $clearedCount,
                'cleared_net' => round( $clearedAmount, 2 ),
                'uncleared_net' => round( $unClearedAmount, 2 ),
            ],
            'is_balanced' => $isBalanced,
            'can_finalize' => $canFinalize,
            'can_reopen' => $canReopen,
            'items' => $itemRows,
            'history' => $history,
            'audit' => $audit,
        ];
    }

    public static function toggleReconciliationItem( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $monthId = (int) ( $input['month_id'] ?? 0 );
        $itemId = (int) ( $input['item_id'] ?? 0 );
        $isCleared = (int) ( $input['is_cleared'] ?? 0 ) === 1 ? 1 : 0;
        if ( $monthId < 1 || $itemId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Reconciliation item is required.' ];
        }

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $monthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );
        $itemsTable = \Metis_Tables::get( 'finance_v2_recon_month_items' );

        $month = $db->fetchOne(
            "SELECT id, status FROM {$monthsTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $monthId ]
        );
        if ( ! is_array( $month ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Reconciliation month not found.' ];
        }

        if ( (string) ( $month['status'] ?? '' ) === 'finalized' ) {
            return [ 'ok' => false, 'status' => 409, 'message' => 'Month is finalized. Reopen before changes.' ];
        }

        $item = $db->fetchOne(
            "SELECT id FROM {$itemsTable} WHERE org_id = %d AND recon_month_id = %d AND id = %d LIMIT 1",
            [ $orgId, $monthId, $itemId ]
        );
        if ( ! is_array( $item ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Reconciliation item not found.' ];
        }

        $db->update(
            $itemsTable,
            [
                'is_cleared' => $isCleared,
                'cleared_at' => $isCleared === 1 ? Support::now() : null,
                'cleared_by' => $isCleared === 1 ? ( $requestedBy > 0 ? $requestedBy : null ) : null,
                'updated_at' => Support::now(),
            ],
            [ 'id' => $itemId, 'org_id' => $orgId, 'recon_month_id' => $monthId ],
            [ '%d', '%s', '%d', '%s' ],
            [ '%d', '%d', '%d' ]
        );

        self::recalculateReconMonth( $monthId );

        return [
            'ok' => true,
            'reconciliation' => self::manualReconciliationWorkflow( $monthId ),
            'summary' => self::summary(),
        ];
    }

    public static function finalizeReconciliationMonth( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $monthId = (int) ( $input['month_id'] ?? 0 );
        if ( $monthId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Reconciliation month is required.' ];
        }

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $monthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );
        $itemsTable = \Metis_Tables::get( 'finance_v2_recon_month_items' );
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );

        $month = $db->fetchOne(
            "SELECT id, status, difference_amount FROM {$monthsTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $monthId ]
        );
        if ( ! is_array( $month ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Reconciliation month not found.' ];
        }
        if ( (string) ( $month['status'] ?? '' ) === 'finalized' ) {
            return [ 'ok' => false, 'status' => 409, 'message' => 'Month is already finalized.' ];
        }

        self::recalculateReconMonth( $monthId );
        $difference = (float) $db->scalar(
            "SELECT difference_amount FROM {$monthsTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $monthId ]
        );
        if ( abs( $difference ) >= 0.01 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Reconciliation is not balanced. Clear transactions before finalizing.' ];
        }

        $db->execute(
            $db->prepare(
                "UPDATE {$entriesTable} e
                 INNER JOIN {$itemsTable} i
                    ON i.gl_entry_id = e.id
                   AND i.org_id = e.org_id
                 SET e.reconciliation_status = CASE WHEN i.is_cleared = 1 THEN 'matched' ELSE 'unmatched' END,
                     e.updated_at = %s
                 WHERE i.org_id = %d
                   AND i.recon_month_id = %d",
                Support::now(),
                $orgId,
                $monthId
            )
        );

        $db->update(
            $monthsTable,
            [
                'status' => 'finalized',
                'finalized_at' => Support::now(),
                'finalized_by' => $requestedBy > 0 ? $requestedBy : null,
                'updated_at' => Support::now(),
            ],
            [ 'id' => $monthId, 'org_id' => $orgId ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d', '%d' ]
        );

        self::logReconMonthAudit( $monthId, 'finalized', '', $requestedBy );

        return [
            'ok' => true,
            'message' => 'Reconciliation month finalized.',
            'reconciliation' => self::manualReconciliationWorkflow( $monthId ),
            'summary' => self::summary(),
        ];
    }

    public static function reopenReconciliationMonth( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        if ( ! ModeService::currentUserHasFinanceRole() ) {
            return [ 'ok' => false, 'status' => 403, 'message' => 'Only finance role users can reopen reconciliation months.' ];
        }

        $monthId = (int) ( $input['month_id'] ?? 0 );
        $reason = metis_text_clean( (string) ( $input['reason'] ?? '' ) );
        if ( $monthId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Reconciliation month is required.' ];
        }
        if ( $reason === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Reason is required to reopen a month.' ];
        }

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $monthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );
        $month = $db->fetchOne(
            "SELECT id, status FROM {$monthsTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $monthId ]
        );
        if ( ! is_array( $month ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Reconciliation month not found.' ];
        }
        if ( (string) ( $month['status'] ?? '' ) !== 'finalized' ) {
            return [ 'ok' => false, 'status' => 409, 'message' => 'Only finalized months can be reopened.' ];
        }

        $db->update(
            $monthsTable,
            [
                'status' => 'reopened',
                'finalized_at' => null,
                'finalized_by' => null,
                'updated_at' => Support::now(),
            ],
            [ 'id' => $monthId, 'org_id' => $orgId ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d', '%d' ]
        );
        self::logReconMonthAudit( $monthId, 'reopened', $reason, $requestedBy );

        return [
            'ok' => true,
            'message' => 'Reconciliation month reopened.',
            'reconciliation' => self::manualReconciliationWorkflow( $monthId ),
            'summary' => self::summary(),
        ];
    }

    public static function deleteReconciliationMonth( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $monthId = (int) ( $input['month_id'] ?? 0 );
        if ( $monthId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Reconciliation month is required.' ];
        }

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $monthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );
        $itemsTable = \Metis_Tables::get( 'finance_v2_recon_month_items' );
        $auditTable = \Metis_Tables::get( 'finance_v2_recon_month_audit' );

        $month = $db->fetchOne(
            "SELECT id, status FROM {$monthsTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $monthId ]
        );
        if ( ! is_array( $month ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Reconciliation month not found.' ];
        }
        if ( (string) ( $month['status'] ?? '' ) === 'finalized' ) {
            return [ 'ok' => false, 'status' => 409, 'message' => 'Finalized months cannot be deleted. Reopen instead.' ];
        }

        $db->execute(
            $db->prepare(
                "DELETE FROM {$itemsTable} WHERE org_id = %d AND recon_month_id = %d",
                $orgId,
                $monthId
            )
        );
        $db->execute(
            $db->prepare(
                "DELETE FROM {$auditTable} WHERE org_id = %d AND recon_month_id = %d",
                $orgId,
                $monthId
            )
        );
        $db->execute(
            $db->prepare(
                "DELETE FROM {$monthsTable} WHERE org_id = %d AND id = %d LIMIT 1",
                $orgId,
                $monthId
            )
        );

        return [
            'ok' => true,
            'message' => 'Reconciliation month deleted.',
            'reconciliation' => self::manualReconciliationWorkflow( 0 ),
            'summary' => self::summary(),
        ];
    }

    public static function reconciliationStatementLines( int $runId = 0, int $limit = 50 ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_bank_lines' );
        $orgId = ModeService::orgId();
        $limit = max( 1, min( 200, $limit ) );

        if ( $runId > 0 ) {
            $rows = $db->fetchAll(
                "SELECT id, recon_parse_run_id, source_type, line_date, description, amount_signed, status, matched_payout_id, matched_gl_entry_id, metadata_json, created_at
                 FROM {$table}
                 WHERE org_id = %d AND recon_parse_run_id = %d
                 ORDER BY line_date DESC, id DESC
                 LIMIT %d",
                [ $orgId, $runId, $limit ]
            );
        } else {
            $rows = $db->fetchAll(
                "SELECT id, recon_parse_run_id, source_type, line_date, description, amount_signed, status, matched_payout_id, matched_gl_entry_id, metadata_json, created_at
                 FROM {$table}
                 WHERE org_id = %d AND source_type = 'statement_import'
                 ORDER BY line_date DESC, id DESC
                 LIMIT %d",
                [ $orgId, $limit ]
            );
        }

        return array_map(
            static fn( array $row ): array => [
                'id' => (int) ( $row['id'] ?? 0 ),
                'recon_parse_run_id' => (int) ( $row['recon_parse_run_id'] ?? 0 ),
                'source_type' => (string) ( $row['source_type'] ?? '' ),
                'line_date' => (string) ( $row['line_date'] ?? '' ),
                'description' => (string) ( $row['description'] ?? '' ),
                'amount_signed' => (float) ( $row['amount_signed'] ?? 0 ),
                'status' => (string) ( $row['status'] ?? '' ),
                'matched_payout_id' => (int) ( $row['matched_payout_id'] ?? 0 ),
                'matched_gl_entry_id' => (int) ( $row['matched_gl_entry_id'] ?? 0 ),
                'metadata' => Support::decodeJson( (string) ( $row['metadata_json'] ?? '' ) ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
            ],
            $rows
        );
    }

    public static function reconciliationRuns( int $limit = 20 ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_recon_parse_runs' );

        $rows = $db->fetchAll(
            "SELECT id, import_type, file_name, confidence_score, confidence_band, status, created_at
             FROM {$table}
             WHERE org_id = %d
             ORDER BY created_at DESC, id DESC
             LIMIT %d",
            [ ModeService::orgId(), max( 1, min( 100, $limit ) ) ]
        );

        return array_map(
            static fn( array $row ): array => [
                'id' => (int) ( $row['id'] ?? 0 ),
                'import_type' => (string) ( $row['import_type'] ?? '' ),
                'file_name' => (string) ( $row['file_name'] ?? '' ),
                'confidence_score' => (float) ( $row['confidence_score'] ?? 0 ),
                'confidence_band' => (string) ( $row['confidence_band'] ?? '' ),
                'status' => (string) ( $row['status'] ?? '' ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
            ],
            $rows
        );
    }

    private static function reconLog( string $level, string $message, array $context = [] ): void {
        if ( ! class_exists( '\\Metis_Logger' ) ) {
            return;
        }
        $method = match ( strtolower( $level ) ) {
            'error' => 'error',
            'warn', 'warning' => 'warn',
            default => 'info',
        };
        if ( method_exists( '\\Metis_Logger', $method ) ) {
            \Metis_Logger::{$method}( $message, $context );
        }
    }

    public static function reconciliationMappings( string $importType = '' ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_recon_column_mappings' );
        $orgId = ModeService::orgId();

        $importType = metis_key_clean( $importType );
        $params = [ $orgId ];
        $where = 'org_id = %d';

        if ( $importType !== '' && in_array( $importType, [ 'csv', 'pdf' ], true ) ) {
            $where .= ' AND import_type = %s';
            $params[] = $importType;
        }

        $rows = $db->fetchAll(
            "SELECT id, import_type, mapping_name, mapping_json, is_default, created_at
             FROM {$table}
             WHERE {$where}
             ORDER BY import_type ASC, is_default DESC, mapping_name ASC",
            $params
        );

        return array_map(
            static fn( array $row ): array => [
                'id' => (int) ( $row['id'] ?? 0 ),
                'import_type' => (string) ( $row['import_type'] ?? '' ),
                'mapping_name' => (string) ( $row['mapping_name'] ?? '' ),
                'mapping' => Support::decodeJson( (string) ( $row['mapping_json'] ?? '' ) ),
                'is_default' => (int) ( $row['is_default'] ?? 0 ) === 1,
                'created_at' => (string) ( $row['created_at'] ?? '' ),
            ],
            $rows
        );
    }

    public static function saveReconciliationMapping( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_recon_column_mappings' );
        $orgId = ModeService::orgId();

        $importType = metis_key_clean( (string) ( $input['import_type'] ?? '' ) );
        if ( ! in_array( $importType, [ 'csv', 'pdf' ], true ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Import type must be csv or pdf.' ];
        }

        $mappingName = metis_text_clean( (string) ( $input['mapping_name'] ?? '' ) );
        if ( $mappingName === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Mapping name is required.' ];
        }

        $rawMapping = isset( $input['mapping'] ) && is_array( $input['mapping'] ) ? $input['mapping'] : [];
        $mapping = [
            'date_column' => metis_text_clean( (string) ( $rawMapping['date_column'] ?? '' ) ),
            'description_column' => metis_text_clean( (string) ( $rawMapping['description_column'] ?? '' ) ),
            'amount_column' => metis_text_clean( (string) ( $rawMapping['amount_column'] ?? '' ) ),
        ];

        if ( $mapping['date_column'] === '' || $mapping['description_column'] === '' || $mapping['amount_column'] === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Date, description, and amount columns are required.' ];
        }

        $isDefault = ! empty( $input['is_default'] ) ? 1 : 0;

        if ( $isDefault === 1 ) {
            $db->execute(
                $db->prepare(
                    "UPDATE {$table} SET is_default = 0, updated_at = %s WHERE org_id = %d AND import_type = %s",
                    Support::now(),
                    $orgId,
                    $importType
                )
            );
        }

        $existingId = (int) $db->scalar(
            "SELECT id FROM {$table} WHERE org_id = %d AND import_type = %s AND mapping_name = %s LIMIT 1",
            [ $orgId, $importType, $mappingName ]
        );

        if ( $existingId > 0 ) {
            $db->update(
                $table,
                [
                    'mapping_json' => Support::asJson( $mapping ),
                    'is_default' => $isDefault,
                    'updated_at' => Support::now(),
                ],
                [ 'id' => $existingId, 'org_id' => $orgId ],
                [ '%s', '%d', '%s' ],
                [ '%d', '%d' ]
            );
        } else {
            $inserted = $db->insert(
                $table,
                [
                    'org_id' => $orgId,
                    'import_type' => $importType,
                    'mapping_name' => $mappingName,
                    'mapping_json' => Support::asJson( $mapping ),
                    'is_default' => $isDefault,
                    'created_by' => $requestedBy > 0 ? $requestedBy : null,
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
            );

            if ( ! $inserted ) {
                return [ 'ok' => false, 'status' => 500, 'message' => 'Could not save column mapping.' ];
            }
        }

        return [
            'ok' => true,
            'reconciliation_mappings' => self::reconciliationMappings( $importType ),
        ];
    }

    public static function reconciliationReviewQueue( int $limit = 20 ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_recon_review_queue' );

        $rows = $db->fetchAll(
            "SELECT id, recon_parse_run_id, confidence_score, confidence_band, status, decision, decision_notes, created_at, updated_at
             FROM {$table}
             WHERE org_id = %d
             ORDER BY status = 'pending_confirmation' DESC, created_at DESC, id DESC
             LIMIT %d",
            [ ModeService::orgId(), max( 1, min( 100, $limit ) ) ]
        );

        return array_map(
            static fn( array $row ): array => [
                'id' => (int) ( $row['id'] ?? 0 ),
                'recon_parse_run_id' => (int) ( $row['recon_parse_run_id'] ?? 0 ),
                'confidence_score' => (float) ( $row['confidence_score'] ?? 0 ),
                'confidence_band' => (string) ( $row['confidence_band'] ?? '' ),
                'status' => (string) ( $row['status'] ?? '' ),
                'decision' => (string) ( $row['decision'] ?? '' ),
                'decision_notes' => (string) ( $row['decision_notes'] ?? '' ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
                'updated_at' => (string) ( $row['updated_at'] ?? '' ),
            ],
            $rows
        );
    }

    public static function applyReconciliationReviewDecision( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $queueTable = \Metis_Tables::get( 'finance_v2_recon_review_queue' );
        $runsTable = \Metis_Tables::get( 'finance_v2_recon_parse_runs' );
        $orgId = ModeService::orgId();

        $queueId = (int) ( $input['review_queue_id'] ?? 0 );
        $decision = metis_key_clean( (string) ( $input['decision'] ?? '' ) );
        $decisionNotes = metis_text_clean( (string) ( $input['decision_notes'] ?? '' ) );

        if ( $queueId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Review queue item is required.' ];
        }

        if ( ! in_array( $decision, [ 'approve', 'manual_confirmed', 'reject' ], true ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Decision must be approve, manual_confirmed, or reject.' ];
        }

        $queueRow = $db->fetchOne(
            "SELECT id, recon_parse_run_id, status FROM {$queueTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $queueId ]
        );
        if ( ! is_array( $queueRow ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Review queue item not found.' ];
        }

        $queueStatus = (string) ( $queueRow['status'] ?? '' );
        if ( $queueStatus !== 'pending_confirmation' ) {
            return [ 'ok' => false, 'status' => 409, 'message' => 'Review item is already finalized.' ];
        }

        $runId = (int) ( $queueRow['recon_parse_run_id'] ?? 0 );
        $runStatus = match ( $decision ) {
            'approve' => 'review_confirmed',
            'manual_confirmed' => 'manual_confirmed',
            default => 'rejected',
        };

        $db->update(
            $queueTable,
            [
                'status' => 'completed',
                'decision' => $decision,
                'decision_notes' => $decisionNotes !== '' ? $decisionNotes : null,
                'decided_by' => $requestedBy > 0 ? $requestedBy : null,
                'decided_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ 'id' => $queueId, 'org_id' => $orgId ],
            [ '%s', '%s', '%s', '%d', '%s', '%s' ],
            [ '%d', '%d' ]
        );

        if ( $runId > 0 ) {
            $db->update(
                $runsTable,
                [
                    'status' => $runStatus,
                    'updated_at' => Support::now(),
                ],
                [ 'id' => $runId, 'org_id' => $orgId ],
                [ '%s', '%s' ],
                [ '%d', '%d' ]
            );
        }

        return [
            'ok' => true,
            'reconciliation_runs' => self::reconciliationRuns( 20 ),
            'reconciliation_review_queue' => self::reconciliationReviewQueue( 20 ),
            'statement_lines' => self::reconciliationStatementLines( 0, 50 ),
            'reconciliation_suggestions' => self::reconciliationSuggestions( 0, 50 ),
            'summary' => self::summary(),
        ];
    }

    public static function reconciliationSuggestions( int $runId = 0, int $limit = 50 ): array {
        $lines = self::reconciliationStatementLines( $runId, $limit );
        if ( $lines === [] ) {
            return [];
        }

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );
        $payoutsTable = \Metis_Tables::get( 'finance_v2_stripe_payouts' );

        $entries = $db->fetchAll(
            "SELECT id, entry_date, description, amount_signed, reconciliation_status
             FROM {$entriesTable}
             WHERE org_id = %d AND reconciliation_status <> 'matched'
             ORDER BY entry_date DESC, id DESC
             LIMIT 800",
            [ $orgId ]
        );
        $payouts = $db->fetchAll(
            "SELECT id, payout_id, payout_date, expected_deposit_amount, status
             FROM {$payoutsTable}
             WHERE org_id = %d AND status <> 'matched'
             ORDER BY payout_date DESC, id DESC
             LIMIT 400",
            [ $orgId ]
        );

        $suggestions = [];
        foreach ( $lines as $line ) {
            $bankLineId = (int) ( $line['id'] ?? 0 );
            $lineAmount = round( abs( (float) ( $line['amount_signed'] ?? 0 ) ), 2 );
            $lineDateTs = strtotime( (string) ( $line['line_date'] ?? '' ) );
            $lineDesc = strtolower( (string) ( $line['description'] ?? '' ) );

            if ( $bankLineId < 1 || $lineAmount <= 0 || $lineDateTs === false ) {
                continue;
            }

            $best = null;
            $bestScore = -1.0;

            foreach ( $entries as $entry ) {
                $entryAmount = round( abs( (float) ( $entry['amount_signed'] ?? 0 ) ), 2 );
                if ( abs( $entryAmount - $lineAmount ) > 0.01 ) {
                    continue;
                }
                $entryDateTs = strtotime( (string) ( $entry['entry_date'] ?? '' ) );
                if ( $entryDateTs === false ) {
                    continue;
                }
                $dateDistance = (int) floor( abs( $lineDateTs - $entryDateTs ) / DAY_IN_SECONDS );
                if ( $dateDistance > 7 ) {
                    continue;
                }
                $descScore = self::descriptionSimilarity( $lineDesc, strtolower( (string) ( $entry['description'] ?? '' ) ) );
                $score = 100 - ( $dateDistance * 5 ) + $descScore;
                if ( $score > $bestScore ) {
                    $bestScore = $score;
                    $best = [
                        'bank_line_id' => $bankLineId,
                        'suggested_type' => 'gl_entry',
                        'suggested_id' => (int) ( $entry['id'] ?? 0 ),
                        'suggested_label' => 'GL #' . (int) ( $entry['id'] ?? 0 ) . ' · ' . (string) ( $entry['description'] ?? '' ),
                        'confidence_score' => round( max( 0, min( 99, $score ) ), 2 ),
                    ];
                }
            }

            foreach ( $payouts as $payout ) {
                $payoutAmount = round( abs( (float) ( $payout['expected_deposit_amount'] ?? 0 ) ), 2 );
                if ( abs( $payoutAmount - $lineAmount ) > 0.01 ) {
                    continue;
                }
                $payoutDateTs = strtotime( (string) ( $payout['payout_date'] ?? '' ) );
                if ( $payoutDateTs === false ) {
                    continue;
                }
                $dateDistance = (int) floor( abs( $lineDateTs - $payoutDateTs ) / DAY_IN_SECONDS );
                if ( $dateDistance > 7 ) {
                    continue;
                }
                $score = 98 - ( $dateDistance * 4 );
                if ( $score > $bestScore ) {
                    $bestScore = $score;
                    $best = [
                        'bank_line_id' => $bankLineId,
                        'suggested_type' => 'stripe_payout',
                        'suggested_id' => (int) ( $payout['id'] ?? 0 ),
                        'suggested_label' => 'Payout ' . (string) ( $payout['payout_id'] ?? (string) ( $payout['id'] ?? '' ) ),
                        'confidence_score' => round( max( 0, min( 99, $score ) ), 2 ),
                    ];
                }
            }

            if ( is_array( $best ) ) {
                $suggestions[] = $best;
            }
        }

        return $suggestions;
    }

    public static function reconcileBankStatementLine( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $bankLineId = (int) ( $input['bank_line_id'] ?? 0 );
        $matchType = metis_key_clean( (string) ( $input['match_type'] ?? '' ) );
        $matchId = (int) ( $input['match_id'] ?? 0 );
        $runId = (int) ( $input['run_id'] ?? 0 );

        if ( $bankLineId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Bank statement line is required.' ];
        }
        if ( ! in_array( $matchType, [ 'gl_entry', 'stripe_payout' ], true ) || $matchId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'A valid match target is required.' ];
        }

        if ( $matchType === 'stripe_payout' ) {
            $result = self::matchPayoutToBankLine(
                [ 'payout_record_id' => $matchId, 'bank_line_id' => $bankLineId ],
                $requestedBy
            );
            if ( empty( $result['ok'] ) ) {
                return $result;
            }
        } else {
            $db = \metis_db();
            $orgId = ModeService::orgId();
            $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );
            $bankLinesTable = \Metis_Tables::get( 'finance_v2_bank_lines' );
            $matchesTable = \Metis_Tables::get( 'finance_v2_recon_matches' );

            $entry = $db->fetchOne(
                "SELECT id, amount_signed, reconciliation_status FROM {$entriesTable} WHERE org_id = %d AND id = %d LIMIT 1",
                [ $orgId, $matchId ]
            );
            $line = $db->fetchOne(
                "SELECT id, amount_signed, status FROM {$bankLinesTable} WHERE org_id = %d AND id = %d LIMIT 1",
                [ $orgId, $bankLineId ]
            );
            if ( ! is_array( $entry ) || ! is_array( $line ) ) {
                return [ 'ok' => false, 'status' => 404, 'message' => 'Reconciliation target not found.' ];
            }
            if ( (string) ( $entry['reconciliation_status'] ?? '' ) === 'matched' || (string) ( $line['status'] ?? '' ) === 'matched' ) {
                return [ 'ok' => false, 'status' => 422, 'message' => 'Selected items are already matched.' ];
            }

            $entryAmount = round( abs( (float) ( $entry['amount_signed'] ?? 0 ) ), 2 );
            $lineAmount = round( abs( (float) ( $line['amount_signed'] ?? 0 ) ), 2 );
            if ( abs( $entryAmount - $lineAmount ) > 0.01 ) {
                return [ 'ok' => false, 'status' => 422, 'message' => 'GL entry amount does not match statement line amount.' ];
            }

            $db->update(
                $entriesTable,
                [ 'reconciliation_status' => 'matched', 'updated_at' => Support::now() ],
                [ 'id' => $matchId, 'org_id' => $orgId ],
                [ '%s', '%s' ],
                [ '%d', '%d' ]
            );
            $db->update(
                $bankLinesTable,
                [ 'status' => 'matched', 'matched_gl_entry_id' => $matchId, 'updated_at' => Support::now() ],
                [ 'id' => $bankLineId, 'org_id' => $orgId ],
                [ '%s', '%d', '%s' ],
                [ '%d', '%d' ]
            );
            $db->insert(
                $matchesTable,
                [
                    'org_id' => $orgId,
                    'match_type' => 'bank_line_to_gl_entry',
                    'bank_line_id' => $bankLineId,
                    'gl_entry_id' => $matchId,
                    'match_amount' => $lineAmount,
                    'confidence_score' => 95,
                    'notes' => null,
                    'created_by' => $requestedBy > 0 ? $requestedBy : null,
                    'created_at' => Support::now(),
                ],
                [ '%d', '%s', '%d', '%d', '%f', '%f', '%s', '%d', '%s' ]
            );
        }

        return [
            'ok' => true,
            'statement_lines' => self::reconciliationStatementLines( $runId, 50 ),
            'reconciliation_suggestions' => self::reconciliationSuggestions( $runId, 50 ),
            'reconciliation_runs' => self::reconciliationRuns( 20 ),
            'summary' => self::summary(),
        ];
    }

    public static function stripeOverview(): array {
        $db = \metis_db();
        $eventsTable = \Metis_Tables::get( 'finance_v2_stripe_clearing_events' );
        $payoutsTable = \Metis_Tables::get( 'finance_v2_stripe_payouts' );
        $orgId = ModeService::orgId();

        $eventSummary = $db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN event_type = 'donation' THEN amount_signed ELSE 0 END), 0) AS donations_total,
                COALESCE(SUM(CASE WHEN event_type = 'fee' THEN amount_signed ELSE 0 END), 0) AS fees_total,
                COALESCE(SUM(amount_signed), 0) AS clearing_balance
             FROM {$eventsTable}
             WHERE org_id = %d",
            [ $orgId ]
        ) ?: [];

        $payoutSummary = $db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'expected' THEN expected_deposit_amount ELSE 0 END), 0) AS expected_total,
                COALESCE(SUM(CASE WHEN status = 'matched' THEN expected_deposit_amount ELSE 0 END), 0) AS matched_total
             FROM {$payoutsTable}
             WHERE org_id = %d",
            [ $orgId ]
        ) ?: [];

        return [
            'donations_total' => (float) ( $eventSummary['donations_total'] ?? 0 ),
            'fees_total' => (float) ( $eventSummary['fees_total'] ?? 0 ),
            'clearing_balance' => (float) ( $eventSummary['clearing_balance'] ?? 0 ),
            'expected_total' => (float) ( $payoutSummary['expected_total'] ?? 0 ),
            'matched_total' => (float) ( $payoutSummary['matched_total'] ?? 0 ),
        ];
    }

    public static function stripeClearingSuggestions( int $limit = 12 ): array {
        $orgId = ModeService::orgId();
        $db = \metis_db();
        $depositsTable = \Metis_Tables::get( 'deposits' );

        $exists = (int) $db->scalar(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            [ $depositsTable ]
        );
        if ( $exists < 1 ) {
            return [ 'recent_deposits' => [] ];
        }

        $rows = $db->fetchAll(
            "SELECT provider_ref, deposit_date, total_amount, status
             FROM {$depositsTable}
             WHERE org_id = %d
             ORDER BY deposit_date DESC, id DESC
             LIMIT %d",
            [ $orgId, max( 1, min( 50, $limit ) ) ]
        );

        return [
            'recent_deposits' => array_map(
                static fn( array $row ): array => [
                    'provider_ref' => (string) ( $row['provider_ref'] ?? '' ),
                    'deposit_date' => (string) ( $row['deposit_date'] ?? '' ),
                    'total_amount' => round( (float) ( $row['total_amount'] ?? 0 ), 2 ),
                    'status' => (string) ( $row['status'] ?? '' ),
                ],
                $rows
            ),
        ];
    }

    public static function recordStripeClearingEvent( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $eventsTable = \Metis_Tables::get( 'finance_v2_stripe_clearing_events' );

        $eventType = metis_key_clean( (string) ( $input['event_type'] ?? '' ) );
        if ( ! in_array( $eventType, [ 'donation', 'fee', 'refund' ], true ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Event type must be donation, fee, or refund.' ];
        }

        $eventDate = metis_text_clean( (string) ( $input['event_date'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $eventDate ) ) {
            $eventDate = gmdate( 'Y-m-d' );
        }

        $referenceId = metis_text_clean( (string) ( $input['reference_id'] ?? '' ) );
        $description = metis_text_clean( (string) ( $input['description'] ?? '' ) );
        $rawAmount = round( (float) ( $input['amount'] ?? 0 ), 2 );
        $amountAbs = round( abs( $rawAmount ), 2 );
        if ( $amountAbs <= 0 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Amount must be greater than zero.' ];
        }

        $amountSigned = $eventType === 'donation' ? $amountAbs : ( 0 - $amountAbs );
        if ( $rawAmount < 0 ) {
            $amountSigned = 0 - $amountAbs;
        }

        $inserted = $db->insert(
            $eventsTable,
            [
                'org_id' => $orgId,
                'event_type' => $eventType,
                'event_date' => $eventDate,
                'reference_id' => $referenceId !== '' ? $referenceId : null,
                'amount_signed' => $amountSigned,
                'currency' => 'usd',
                'description' => $description !== '' ? $description : null,
                'metadata_json' => Support::asJson( [] ),
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not save Stripe clearing event.' ];
        }

        self::createGlEntryForStripeEvent( $eventType, $eventDate, $description, $amountSigned, $requestedBy );

        return [
            'ok' => true,
            'stripe_overview' => self::stripeOverview(),
            'summary' => self::summary(),
        ];
    }

    public static function recordOfflineDonationReceipt( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $eventDate = metis_text_clean( (string) ( $input['event_date'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $eventDate ) ) {
            $eventDate = gmdate( 'Y-m-d' );
        }

        $referenceId = metis_text_clean( (string) ( $input['reference_id'] ?? '' ) );
        $description = metis_text_clean( (string) ( $input['description'] ?? '' ) );
        $amount = round( abs( (float) ( $input['amount'] ?? 0 ) ), 2 );
        if ( $amount <= 0 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Amount must be greater than zero.' ];
        }

        $memo = $description !== '' ? $description : 'Offline donation receipt';
        if ( $referenceId !== '' ) {
            $memo .= ' (' . $referenceId . ')';
        }

        self::insertSystemGlEntry( $eventDate, 'operating_cash', $memo, $amount, 'debit', 'donations', 'offline_donation', $requestedBy );
        self::insertSystemGlEntry( $eventDate, 'donations_income', $memo, $amount, 'credit', 'donations', 'offline_donation', $requestedBy );

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Offline donation receipt recorded.',
        ];
    }

    public static function createExpectedPayout( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $table = \Metis_Tables::get( 'finance_v2_stripe_payouts' );

        $payoutId = metis_text_clean( (string) ( $input['payout_id'] ?? '' ) );
        if ( $payoutId === '' ) {
            $payoutId = 'auto-' . gmdate( 'Ymd' ) . '-' . metis_rand( 1000, 9999 );
        }

        $payoutDate = metis_text_clean( (string) ( $input['payout_date'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $payoutDate ) ) {
            $payoutDate = gmdate( 'Y-m-d' );
        }

        $amount = round( abs( (float) ( $input['expected_deposit_amount'] ?? 0 ) ), 2 );
        if ( $amount <= 0 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Expected deposit amount must be greater than zero.' ];
        }

        $bankLabel = metis_text_clean( (string) ( $input['bank_account_label'] ?? '' ) );

        $inserted = $db->insert(
            $table,
            [
                'org_id' => $orgId,
                'payout_id' => $payoutId,
                'payout_date' => $payoutDate,
                'expected_deposit_amount' => $amount,
                'currency' => 'usd',
                'bank_account_label' => $bankLabel !== '' ? $bankLabel : null,
                'status' => 'expected',
                'metadata_json' => Support::asJson( [] ),
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not save expected payout.' ];
        }

        return [
            'ok' => true,
            'stripe_payouts' => self::stripePayouts( 20 ),
            'stripe_overview' => self::stripeOverview(),
            'stripe_suggestions' => self::stripeClearingSuggestions(),
            'summary' => self::summary(),
        ];
    }

    public static function recordBankLine( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $table = \Metis_Tables::get( 'finance_v2_bank_lines' );

        $lineDate = metis_text_clean( (string) ( $input['line_date'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $lineDate ) ) {
            $lineDate = gmdate( 'Y-m-d' );
        }

        $description = metis_text_clean( (string) ( $input['description'] ?? '' ) );
        if ( $description === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Bank line description is required.' ];
        }

        $amountSigned = round( (float) ( $input['amount_signed'] ?? 0 ), 2 );
        if ( abs( $amountSigned ) < 0.01 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Bank line amount cannot be zero.' ];
        }

        $inserted = $db->insert(
            $table,
            [
                'org_id' => $orgId,
                'recon_parse_run_id' => isset( $input['recon_parse_run_id'] ) ? (int) $input['recon_parse_run_id'] : null,
                'source_type' => metis_key_clean( (string) ( $input['source_type'] ?? 'manual' ) ),
                'line_date' => $lineDate,
                'description' => $description,
                'amount_signed' => $amountSigned,
                'status' => 'unmatched',
                'metadata_json' => Support::asJson( [] ),
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not save bank line.' ];
        }

        return [
            'ok' => true,
            'bank_lines' => self::bankLines( 20 ),
            'summary' => self::summary(),
        ];
    }

    public static function matchPayoutToBankLine( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $payoutsTable = \Metis_Tables::get( 'finance_v2_stripe_payouts' );
        $bankLinesTable = \Metis_Tables::get( 'finance_v2_bank_lines' );
        $matchesTable = \Metis_Tables::get( 'finance_v2_recon_matches' );

        $payoutRecordId = (int) ( $input['payout_record_id'] ?? 0 );
        $bankLineId = (int) ( $input['bank_line_id'] ?? 0 );
        if ( $payoutRecordId < 1 || $bankLineId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Payout and bank line are required.' ];
        }

        $payout = $db->fetchOne(
            "SELECT id, expected_deposit_amount, status FROM {$payoutsTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $payoutRecordId ]
        );
        if ( ! is_array( $payout ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Payout record was not found.' ];
        }
        if ( (string) ( $payout['status'] ?? '' ) === 'matched' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Payout record is already matched.' ];
        }

        $bankLine = $db->fetchOne(
            "SELECT id, amount_signed, status FROM {$bankLinesTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $bankLineId ]
        );
        if ( ! is_array( $bankLine ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Bank line was not found.' ];
        }
        if ( (string) ( $bankLine['status'] ?? '' ) === 'matched' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Bank line is already matched.' ];
        }

        $payoutAmount = round( abs( (float) ( $payout['expected_deposit_amount'] ?? 0 ) ), 2 );
        $bankAmount = round( abs( (float) ( $bankLine['amount_signed'] ?? 0 ) ), 2 );
        $delta = abs( $payoutAmount - $bankAmount );
        if ( $delta > 0.01 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Amounts do not match for reconciliation.' ];
        }

        $db->update(
            $payoutsTable,
            [
                'status' => 'matched',
                'matched_bank_line_id' => $bankLineId,
                'matched_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ 'id' => $payoutRecordId, 'org_id' => $orgId ],
            [ '%s', '%d', '%s', '%s' ],
            [ '%d', '%d' ]
        );

        $db->update(
            $bankLinesTable,
            [
                'status' => 'matched',
                'matched_payout_id' => $payoutRecordId,
                'updated_at' => Support::now(),
            ],
            [ 'id' => $bankLineId, 'org_id' => $orgId ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%d' ]
        );

        $db->insert(
            $matchesTable,
            [
                'org_id' => $orgId,
                'match_type' => 'stripe_payout_to_bank_line',
                'payout_record_id' => $payoutRecordId,
                'bank_line_id' => $bankLineId,
                'match_amount' => $payoutAmount,
                'confidence_score' => 100,
                'notes' => null,
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
            ],
            [ '%d', '%s', '%d', '%d', '%f', '%f', '%s', '%d', '%s' ]
        );

        self::createGlEntryForStripePayoutMatch( $payoutRecordId, $requestedBy );

        return [
            'ok' => true,
            'stripe_payouts' => self::stripePayouts( 20 ),
            'bank_lines' => self::bankLines( 20 ),
            'stripe_overview' => self::stripeOverview(),
            'summary' => self::summary(),
        ];
    }

    public static function autoMatchStripeClearing( array $input = [], int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $payoutsTable = \Metis_Tables::get( 'finance_v2_stripe_payouts' );
        $bankLinesTable = \Metis_Tables::get( 'finance_v2_bank_lines' );

        $windowDays = (int) ( $input['date_window_days'] ?? 5 );
        if ( $windowDays < 0 ) {
            $windowDays = 0;
        }
        if ( $windowDays > 14 ) {
            $windowDays = 14;
        }

        $payoutRows = $db->fetchAll(
            "SELECT id, payout_date, expected_deposit_amount
             FROM {$payoutsTable}
             WHERE org_id = %d AND status <> 'matched'
             ORDER BY payout_date ASC, id ASC
             LIMIT 250",
            [ $orgId ]
        );

        $bankRows = $db->fetchAll(
            "SELECT id, line_date, amount_signed
             FROM {$bankLinesTable}
             WHERE org_id = %d AND status <> 'matched'
             ORDER BY line_date ASC, id ASC
             LIMIT 500",
            [ $orgId ]
        );

        $bankByAmount = [];
        foreach ( $bankRows as $row ) {
            $amountCents = (int) round( abs( (float) ( $row['amount_signed'] ?? 0 ) ) * 100 );
            if ( ! isset( $bankByAmount[ $amountCents ] ) ) {
                $bankByAmount[ $amountCents ] = [];
            }
            $bankByAmount[ $amountCents ][] = $row;
        }

        $usedBankLineIds = [];
        $matchedCount = 0;

        foreach ( $payoutRows as $payout ) {
            $payoutId = (int) ( $payout['id'] ?? 0 );
            if ( $payoutId < 1 ) {
                continue;
            }

            $amountCents = (int) round( abs( (float) ( $payout['expected_deposit_amount'] ?? 0 ) ) * 100 );
            if ( $amountCents < 1 || empty( $bankByAmount[ $amountCents ] ) ) {
                continue;
            }

            $bestBankLineId = 0;
            $bestDateDistance = PHP_INT_MAX;
            $payoutDateTs = strtotime( (string) ( $payout['payout_date'] ?? '' ) );

            foreach ( $bankByAmount[ $amountCents ] as $candidate ) {
                $candidateId = (int) ( $candidate['id'] ?? 0 );
                if ( $candidateId < 1 || isset( $usedBankLineIds[ $candidateId ] ) ) {
                    continue;
                }

                $lineDateTs = strtotime( (string) ( $candidate['line_date'] ?? '' ) );
                if ( $payoutDateTs === false || $lineDateTs === false ) {
                    continue;
                }

                $distance = (int) floor( abs( $lineDateTs - $payoutDateTs ) / DAY_IN_SECONDS );
                if ( $distance > $windowDays ) {
                    continue;
                }

                if ( $distance < $bestDateDistance || ( $distance === $bestDateDistance && $candidateId < $bestBankLineId ) ) {
                    $bestDateDistance = $distance;
                    $bestBankLineId = $candidateId;
                }
            }

            if ( $bestBankLineId < 1 ) {
                continue;
            }

            $matchResult = self::matchPayoutToBankLine(
                [
                    'payout_record_id' => $payoutId,
                    'bank_line_id' => $bestBankLineId,
                ],
                $requestedBy
            );
            if ( ! empty( $matchResult['ok'] ) ) {
                $matchedCount++;
                $usedBankLineIds[ $bestBankLineId ] = true;
            }
        }

        return [
            'ok' => true,
            'matched_count' => $matchedCount,
            'stripe_payouts' => self::stripePayouts( 20 ),
            'bank_lines' => self::bankLines( 20 ),
            'stripe_overview' => self::stripeOverview(),
            'summary' => self::summary(),
        ];
    }

    public static function stripePayouts( int $limit = 20 ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_stripe_payouts' );
        $rows = $db->fetchAll(
            "SELECT id, payout_id, payout_date, expected_deposit_amount, status, matched_bank_line_id, bank_account_label, created_at
             FROM {$table}
             WHERE org_id = %d
             ORDER BY payout_date DESC, id DESC
             LIMIT %d",
            [ ModeService::orgId(), max( 1, min( 100, $limit ) ) ]
        );

        return array_map(
            static fn ( array $row ): array => [
                'id' => (int) ( $row['id'] ?? 0 ),
                'payout_id' => (string) ( $row['payout_id'] ?? '' ),
                'payout_date' => (string) ( $row['payout_date'] ?? '' ),
                'expected_deposit_amount' => (float) ( $row['expected_deposit_amount'] ?? 0 ),
                'status' => (string) ( $row['status'] ?? '' ),
                'matched_bank_line_id' => (int) ( $row['matched_bank_line_id'] ?? 0 ),
                'bank_account_label' => (string) ( $row['bank_account_label'] ?? '' ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
            ],
            $rows
        );
    }

    public static function bankLines( int $limit = 20 ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_bank_lines' );
        $rows = $db->fetchAll(
            "SELECT id, recon_parse_run_id, source_type, line_date, description, amount_signed, status, matched_payout_id, matched_gl_entry_id, metadata_json, created_at
             FROM {$table}
             WHERE org_id = %d
             ORDER BY line_date DESC, id DESC
             LIMIT %d",
            [ ModeService::orgId(), max( 1, min( 100, $limit ) ) ]
        );

        return array_map(
            static fn ( array $row ): array => [
                'id' => (int) ( $row['id'] ?? 0 ),
                'recon_parse_run_id' => (int) ( $row['recon_parse_run_id'] ?? 0 ),
                'source_type' => (string) ( $row['source_type'] ?? '' ),
                'line_date' => (string) ( $row['line_date'] ?? '' ),
                'description' => (string) ( $row['description'] ?? '' ),
                'amount_signed' => (float) ( $row['amount_signed'] ?? 0 ),
                'status' => (string) ( $row['status'] ?? '' ),
                'matched_payout_id' => (int) ( $row['matched_payout_id'] ?? 0 ),
                'matched_gl_entry_id' => (int) ( $row['matched_gl_entry_id'] ?? 0 ),
                'metadata' => Support::decodeJson( (string) ( $row['metadata_json'] ?? '' ) ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
            ],
            $rows
        );
    }

    public static function budgetSnapshot( int $versionId = 0 ): array {
        SchemaManager::ensureSchema();

        $versions = self::budgetVersions( 24 );
        $selectedVersionId = $versionId > 0 ? $versionId : self::activeBudgetVersionId();
        if ( $selectedVersionId < 1 && ! empty( $versions ) ) {
            $selectedVersionId = (int) ( $versions[0]['id'] ?? 0 );
        }

        $selectedVersion = null;
        foreach ( $versions as $version ) {
            if ( (int) ( $version['id'] ?? 0 ) === $selectedVersionId ) {
                $selectedVersion = $version;
                break;
            }
        }

        $lines = $selectedVersionId > 0 ? self::budgetLines( $selectedVersionId ) : [];

        return [
            'versions' => $versions,
            'selected_version_id' => $selectedVersionId,
            'selected_version' => $selectedVersion,
            'lines' => $lines,
            'can_edit' => is_array( $selectedVersion ) ? empty( $selectedVersion['is_locked'] ) : false,
        ];
    }

    public static function createBudgetVersion( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $versionsTable = \Metis_Tables::get( 'finance_v2_budget_versions' );
        $linesTable = \Metis_Tables::get( 'finance_v2_budget_lines' );

        $label = metis_text_clean( (string) ( $input['version_label'] ?? '' ) );
        if ( $label === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Version label is required.' ];
        }

        $fiscalYear = (int) ( $input['fiscal_year'] ?? gmdate( 'Y' ) );
        if ( $fiscalYear < 2000 || $fiscalYear > 3000 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Fiscal year is invalid.' ];
        }

        $periodStart = metis_text_clean( (string) ( $input['period_start'] ?? '' ) );
        $periodEnd = metis_text_clean( (string) ( $input['period_end'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $periodStart ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $periodEnd ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Period start and end dates are required.' ];
        }
        if ( $periodStart > $periodEnd ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Period start must be before period end.' ];
        }

        $sourceVersionId = (int) ( $input['source_version_id'] ?? 0 );
        if ( $sourceVersionId < 1 ) {
            $sourceVersionId = (int) $db->scalar(
                "SELECT id FROM {$versionsTable}
                 WHERE org_id = %d
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1",
                [ $orgId ]
            );
        }

        // Historical versions remain read-only once a new version becomes active.
        $db->execute(
            $db->prepare(
                "UPDATE {$versionsTable}
                 SET is_locked = 1, updated_at = %s
                 WHERE org_id = %d AND is_locked = 0",
                Support::now(),
                $orgId
            )
        );

        $inserted = $db->insert(
            $versionsTable,
            [
                'org_id' => $orgId,
                'version_label' => $label,
                'fiscal_year' => $fiscalYear,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'is_locked' => 0,
                'source_version_id' => $sourceVersionId > 0 ? $sourceVersionId : null,
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not create budget version.' ];
        }

        $newVersionId = (int) $db->lastInsertId();

        if ( $sourceVersionId > 0 ) {
            $sourceLines = $db->fetchAll(
                "SELECT account_code, planned_amount
                 FROM {$linesTable}
                 WHERE org_id = %d AND budget_version_id = %d",
                [ $orgId, $sourceVersionId ]
            );

            foreach ( $sourceLines as $line ) {
                $db->insert(
                    $linesTable,
                    [
                        'org_id' => $orgId,
                        'budget_version_id' => $newVersionId,
                        'account_code' => metis_key_clean( (string) ( $line['account_code'] ?? '' ) ),
                        'planned_amount' => round( (float) ( $line['planned_amount'] ?? 0 ), 2 ),
                        'created_by' => $requestedBy > 0 ? $requestedBy : null,
                        'created_at' => Support::now(),
                        'updated_at' => Support::now(),
                    ],
                    [ '%d', '%d', '%s', '%f', '%d', '%s', '%s' ]
                );
            }
        } else {
            $accounts = self::accounts();
            foreach ( $accounts as $account ) {
                $accountCode = metis_key_clean( (string) ( $account['account_code'] ?? '' ) );
                if ( $accountCode === '' ) {
                    continue;
                }

                $db->insert(
                    $linesTable,
                    [
                        'org_id' => $orgId,
                        'budget_version_id' => $newVersionId,
                        'account_code' => $accountCode,
                        'planned_amount' => 0,
                        'created_by' => $requestedBy > 0 ? $requestedBy : null,
                        'created_at' => Support::now(),
                        'updated_at' => Support::now(),
                    ],
                    [ '%d', '%d', '%s', '%f', '%d', '%s', '%s' ]
                );
            }
        }

        return [
            'ok' => true,
            'budget' => self::budgetSnapshot( $newVersionId ),
            'summary' => self::summary(),
        ];
    }

    public static function saveBudgetLines( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $versionsTable = \Metis_Tables::get( 'finance_v2_budget_versions' );
        $linesTable = \Metis_Tables::get( 'finance_v2_budget_lines' );

        $versionId = (int) ( $input['budget_version_id'] ?? 0 );
        if ( $versionId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Budget version is required.' ];
        }

        $version = $db->fetchOne(
            "SELECT id, is_locked FROM {$versionsTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $versionId ]
        );
        if ( ! is_array( $version ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Budget version not found.' ];
        }
        if ( (int) ( $version['is_locked'] ?? 0 ) === 1 ) {
            return [ 'ok' => false, 'status' => 409, 'message' => 'Historical budget versions are read-only.' ];
        }

        $lines = isset( $input['lines'] ) && is_array( $input['lines'] ) ? $input['lines'] : [];
        if ( $lines === [] ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Budget lines are required.' ];
        }

        foreach ( $lines as $line ) {
            if ( ! is_array( $line ) ) {
                continue;
            }

            $accountCode = metis_key_clean( (string) ( $line['account_code'] ?? '' ) );
            if ( $accountCode === '' ) {
                continue;
            }

            $plannedAmount = round( (float) ( $line['planned_amount'] ?? 0 ), 2 );
            $existingId = (int) $db->scalar(
                "SELECT id FROM {$linesTable}
                 WHERE org_id = %d AND budget_version_id = %d AND account_code = %s
                 LIMIT 1",
                [ $orgId, $versionId, $accountCode ]
            );

            if ( $existingId > 0 ) {
                $db->update(
                    $linesTable,
                    [
                        'planned_amount' => $plannedAmount,
                        'updated_at' => Support::now(),
                    ],
                    [ 'id' => $existingId, 'org_id' => $orgId ],
                    [ '%f', '%s' ],
                    [ '%d', '%d' ]
                );
            } else {
                $db->insert(
                    $linesTable,
                    [
                        'org_id' => $orgId,
                        'budget_version_id' => $versionId,
                        'account_code' => $accountCode,
                        'planned_amount' => $plannedAmount,
                        'created_by' => $requestedBy > 0 ? $requestedBy : null,
                        'created_at' => Support::now(),
                        'updated_at' => Support::now(),
                    ],
                    [ '%d', '%d', '%s', '%f', '%d', '%s', '%s' ]
                );
            }
        }

        return [
            'ok' => true,
            'budget' => self::budgetSnapshot( $versionId ),
        ];
    }

    public static function budgetVersions( int $limit = 24 ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_budget_versions' );
        $rows = $db->fetchAll(
            "SELECT id, version_label, fiscal_year, period_start, period_end, is_locked, source_version_id, created_at
             FROM {$table}
             WHERE org_id = %d
             ORDER BY period_start DESC, id DESC
             LIMIT %d",
            [ ModeService::orgId(), max( 1, min( 100, $limit ) ) ]
        );

        return array_map(
            static fn( array $row ): array => [
                'id' => (int) ( $row['id'] ?? 0 ),
                'version_label' => (string) ( $row['version_label'] ?? '' ),
                'fiscal_year' => (int) ( $row['fiscal_year'] ?? 0 ),
                'period_start' => (string) ( $row['period_start'] ?? '' ),
                'period_end' => (string) ( $row['period_end'] ?? '' ),
                'is_locked' => (int) ( $row['is_locked'] ?? 0 ) === 1,
                'source_version_id' => (int) ( $row['source_version_id'] ?? 0 ),
                'created_at' => (string) ( $row['created_at'] ?? '' ),
            ],
            $rows
        );
    }

    public static function budgetLines( int $versionId ): array {
        $db = \metis_db();
        $versionsTable = \Metis_Tables::get( 'finance_v2_budget_versions' );
        $linesTable = \Metis_Tables::get( 'finance_v2_budget_lines' );
        $accountsTable = \Metis_Tables::get( 'finance_v2_accounts' );
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );
        $orgId = ModeService::orgId();

        $version = $db->fetchOne(
            "SELECT id, period_start, period_end
             FROM {$versionsTable}
             WHERE org_id = %d AND id = %d
             LIMIT 1",
            [ $orgId, $versionId ]
        );
        if ( ! is_array( $version ) ) {
            return [];
        }

        $lineRows = $db->fetchAll(
            "SELECT l.account_code, l.planned_amount, a.account_name
             FROM {$linesTable} l
             LEFT JOIN {$accountsTable} a
               ON a.org_id = l.org_id
              AND a.account_code = l.account_code
             WHERE l.org_id = %d
               AND l.budget_version_id = %d
             ORDER BY l.account_code ASC",
            [ $orgId, $versionId ]
        );

        $actualRows = $db->fetchAll(
            "SELECT account_code, COALESCE(SUM(amount_signed), 0) AS actual_amount
             FROM {$entriesTable}
             WHERE org_id = %d
               AND entry_date BETWEEN %s AND %s
             GROUP BY account_code",
            [ $orgId, (string) $version['period_start'], (string) $version['period_end'] ]
        );

        $actualByAccount = [];
        foreach ( $actualRows as $actualRow ) {
            $actualByAccount[ (string) ( $actualRow['account_code'] ?? '' ) ] = (float) ( $actualRow['actual_amount'] ?? 0 );
        }

        return array_map(
            static function ( array $row ) use ( $actualByAccount ): array {
                $accountCode = (string) ( $row['account_code'] ?? '' );
                $planned = round( (float) ( $row['planned_amount'] ?? 0 ), 2 );
                $actual = round( (float) ( $actualByAccount[ $accountCode ] ?? 0 ), 2 );

                return [
                    'account_code' => $accountCode,
                    'account_name' => (string) ( $row['account_name'] ?? $accountCode ),
                    'planned_amount' => $planned,
                    'actual_amount' => $actual,
                    'variance_amount' => round( $actual - $planned, 2 ),
                ];
            },
            $lineRows
        );
    }

    public static function invoiceSnapshot( int $limit = 20 ): array {
        SchemaManager::ensureSchema();

        return [
            'rows' => self::invoices( $limit ),
            'aging' => self::invoiceAging(),
            'can_edit' => Access::canManage(),
        ];
    }

    public static function createInvoice( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $invoicesTable = \Metis_Tables::get( 'finance_v2_invoices' );
        $linesTable = \Metis_Tables::get( 'finance_v2_invoice_lines' );

        $customerName = metis_text_clean( (string) ( $input['customer_name'] ?? '' ) );
        if ( $customerName === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Customer name is required.' ];
        }

        $customerEmail = metis_email_clean( (string) ( $input['customer_email'] ?? '' ) );
        if ( $customerEmail === '' || ! metis_email_is_valid( $customerEmail ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'A valid customer email is required.' ];
        }

        $issuedDate = metis_text_clean( (string) ( $input['issued_date'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issuedDate ) ) {
            $issuedDate = gmdate( 'Y-m-d' );
        }

        $dueDate = metis_text_clean( (string) ( $input['due_date'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dueDate ) ) {
            $dueDate = $issuedDate;
        }
        if ( $dueDate < $issuedDate ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Due date must be on or after issue date.' ];
        }

        $currency = metis_key_clean( (string) ( $input['currency'] ?? 'usd' ) );
        if ( $currency === '' ) {
            $currency = 'usd';
        }
        $currency = substr( $currency, 0, 12 );

        $notes = metis_text_clean( (string) ( $input['notes'] ?? '' ) );
        if ( $notes === '' ) {
            $notes = null;
        }

        $linesInput = isset( $input['lines'] ) && is_array( $input['lines'] ) ? $input['lines'] : [];
        if ( $linesInput === [] ) {
            $desc = metis_text_clean( (string) ( $input['line_description'] ?? '' ) );
            if ( $desc !== '' ) {
                $linesInput[] = [
                    'description' => $desc,
                    'quantity' => $input['line_quantity'] ?? 1,
                    'unit_amount' => $input['line_unit_amount'] ?? 0,
                ];
            }
        }
        if ( $linesInput === [] ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'At least one invoice line is required.' ];
        }

        $normalizedLines = [];
        $subtotal = 0.0;
        foreach ( $linesInput as $idx => $line ) {
            if ( ! is_array( $line ) ) {
                continue;
            }

            $description = metis_text_clean( (string) ( $line['description'] ?? '' ) );
            if ( $description === '' ) {
                continue;
            }

            $quantity = round( (float) ( $line['quantity'] ?? 1 ), 2 );
            if ( $quantity <= 0 ) {
                $quantity = 1;
            }

            $unitAmount = round( (float) ( $line['unit_amount'] ?? 0 ), 2 );
            if ( $unitAmount < 0 ) {
                $unitAmount = 0;
            }

            $lineTotal = round( $quantity * $unitAmount, 2 );
            if ( $lineTotal <= 0 ) {
                continue;
            }

            $normalizedLines[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_amount' => $unitAmount,
                'line_total' => $lineTotal,
                'sort_order' => count( $normalizedLines ) + 1,
            ];
            $subtotal += $lineTotal;
        }

        $subtotal = round( $subtotal, 2 );
        if ( $normalizedLines === [] || $subtotal <= 0 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Invoice total must be greater than zero.' ];
        }

        $tempInvoiceNumber = 'INV-TMP-' . gmdate( 'YmdHis' ) . '-' . (string) metis_rand( 1000, 9999 );
        $inserted = $db->insert(
            $invoicesTable,
            [
                'org_id' => $orgId,
                'invoice_number' => $tempInvoiceNumber,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'status' => 'draft',
                'currency' => $currency,
                'issued_date' => $issuedDate,
                'due_date' => $dueDate,
                'paid_date' => null,
                'subtotal_amount' => $subtotal,
                'total_amount' => $subtotal,
                'stripe_payment_intent_id' => null,
                'sent_at' => null,
                'notes' => $notes,
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not create invoice.' ];
        }

        $invoiceId = (int) $db->lastInsertId();
        $invoiceNumber = 'INV-' . gmdate( 'Y' ) . '-' . str_pad( (string) $invoiceId, 6, '0', STR_PAD_LEFT );
        $db->update(
            $invoicesTable,
            [ 'invoice_number' => $invoiceNumber, 'updated_at' => Support::now() ],
            [ 'id' => $invoiceId, 'org_id' => $orgId ],
            [ '%s', '%s' ],
            [ '%d', '%d' ]
        );

        foreach ( $normalizedLines as $line ) {
            $db->insert(
                $linesTable,
                [
                    'org_id' => $orgId,
                    'invoice_id' => $invoiceId,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_amount' => $line['unit_amount'],
                    'line_total' => $line['line_total'],
                    'sort_order' => $line['sort_order'],
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%d', '%s', '%f', '%f', '%f', '%d', '%s', '%s' ]
            );
        }

        return [
            'ok' => true,
            'invoices' => self::invoiceSnapshot( 20 ),
        ];
    }

    public static function sendInvoice( int $invoiceId, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        if ( $invoiceId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Invoice is required.' ];
        }

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $invoicesTable = \Metis_Tables::get( 'finance_v2_invoices' );
        $invoiceLinesTable = \Metis_Tables::get( 'finance_v2_invoice_lines' );

        $invoice = $db->fetchOne(
            "SELECT id, invoice_number, customer_name, customer_email, status, currency, issued_date, due_date, total_amount, notes
             FROM {$invoicesTable}
             WHERE org_id = %d AND id = %d
             LIMIT 1",
            [ $orgId, $invoiceId ]
        );
        if ( ! is_array( $invoice ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Invoice not found.' ];
        }

        $status = (string) ( $invoice['status'] ?? '' );
        if ( $status === 'paid' ) {
            return [ 'ok' => false, 'status' => 409, 'message' => 'Paid invoices cannot be re-sent.' ];
        }

        $customerEmail = metis_email_clean( (string) ( $invoice['customer_email'] ?? '' ) );
        if ( $customerEmail === '' || ! metis_email_is_valid( $customerEmail ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Invoice customer email is invalid.' ];
        }

        $lineRows = $db->fetchAll(
            "SELECT description, quantity, unit_amount, line_total
             FROM {$invoiceLinesTable}
             WHERE org_id = %d AND invoice_id = %d
             ORDER BY sort_order ASC, id ASC",
            [ $orgId, $invoiceId ]
        );

        $lineHtml = '';
        foreach ( $lineRows as $line ) {
            $lineHtml .= '<tr>'
                . '<td style="padding:6px;border-bottom:1px solid #eee;">' . metis_escape_html( (string) ( $line['description'] ?? '' ) ) . '</td>'
                . '<td style="padding:6px;border-bottom:1px solid #eee;text-align:right;">' . metis_escape_html( number_format( (float) ( $line['quantity'] ?? 0 ), 2 ) ) . '</td>'
                . '<td style="padding:6px;border-bottom:1px solid #eee;text-align:right;">' . metis_escape_html( number_format( (float) ( $line['unit_amount'] ?? 0 ), 2 ) ) . '</td>'
                . '<td style="padding:6px;border-bottom:1px solid #eee;text-align:right;">' . metis_escape_html( number_format( (float) ( $line['line_total'] ?? 0 ), 2 ) ) . '</td>'
                . '</tr>';
        }

        $subject = 'Invoice ' . (string) ( $invoice['invoice_number'] ?? '' );
        $body = '<p>Hello ' . metis_escape_html( (string) ( $invoice['customer_name'] ?? '' ) ) . ',</p>'
            . '<p>Your invoice is ready.</p>'
            . '<p><strong>Invoice:</strong> ' . metis_escape_html( (string) ( $invoice['invoice_number'] ?? '' ) ) . '<br>'
            . '<strong>Issued:</strong> ' . metis_escape_html( (string) ( $invoice['issued_date'] ?? '' ) ) . '<br>'
            . '<strong>Due:</strong> ' . metis_escape_html( (string) ( $invoice['due_date'] ?? '' ) ) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;">'
            . '<thead><tr><th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;">Description</th><th style="text-align:right;padding:6px;border-bottom:1px solid #ddd;">Qty</th><th style="text-align:right;padding:6px;border-bottom:1px solid #ddd;">Unit</th><th style="text-align:right;padding:6px;border-bottom:1px solid #ddd;">Total</th></tr></thead>'
            . '<tbody>' . $lineHtml . '</tbody>'
            . '</table>'
            . '<p style="margin-top:12px;"><strong>Total:</strong> ' . metis_escape_html( number_format( (float) ( $invoice['total_amount'] ?? 0 ), 2 ) ) . ' ' . metis_escape_html( strtoupper( (string) ( $invoice['currency'] ?? 'usd' ) ) ) . '</p>';

        $notes = (string) ( $invoice['notes'] ?? '' );
        if ( $notes !== '' ) {
            $body .= '<p>' . metis_escape_html( $notes ) . '</p>';
        }

        $body .= '<p>Payment method: Stripe.</p>';

        $sendResult = \Metis\Core\Services\EmailService::sendHtml(
            $customerEmail,
            $subject,
            $body,
            [ 'module' => 'finance' ]
        );

        if ( empty( $sendResult['ok'] ) ) {
            return [ 'ok' => false, 'status' => 502, 'message' => 'Invoice email failed to send.' ];
        }

        $today = gmdate( 'Y-m-d' );
        $nextStatus = (string) ( $invoice['due_date'] ?? '' ) < $today ? 'overdue' : 'sent';
        $db->update(
            $invoicesTable,
            [
                'status' => $nextStatus,
                'sent_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ 'id' => $invoiceId, 'org_id' => $orgId ],
            [ '%s', '%s', '%s' ],
            [ '%d', '%d' ]
        );

        return [
            'ok' => true,
            'invoices' => self::invoiceSnapshot( 20 ),
        ];
    }

    public static function markInvoicePaid( int $invoiceId, array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        if ( $invoiceId < 1 ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Invoice is required.' ];
        }

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $invoicesTable = \Metis_Tables::get( 'finance_v2_invoices' );

        $invoice = $db->fetchOne(
            "SELECT id, status FROM {$invoicesTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $invoiceId ]
        );
        if ( ! is_array( $invoice ) ) {
            return [ 'ok' => false, 'status' => 404, 'message' => 'Invoice not found.' ];
        }
        if ( (string) ( $invoice['status'] ?? '' ) === 'paid' ) {
            return [ 'ok' => false, 'status' => 409, 'message' => 'Invoice is already paid.' ];
        }

        $paidDate = metis_text_clean( (string) ( $input['paid_date'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $paidDate ) ) {
            $paidDate = gmdate( 'Y-m-d' );
        }
        $stripePaymentIntentId = metis_text_clean( (string) ( $input['stripe_payment_intent_id'] ?? '' ) );
        if ( $stripePaymentIntentId === '' ) {
            $stripePaymentIntentId = null;
        }

        $db->update(
            $invoicesTable,
            [
                'status' => 'paid',
                'paid_date' => $paidDate,
                'stripe_payment_intent_id' => $stripePaymentIntentId,
                'updated_at' => Support::now(),
            ],
            [ 'id' => $invoiceId, 'org_id' => $orgId ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d', '%d' ]
        );

        return [
            'ok' => true,
            'invoices' => self::invoiceSnapshot( 20 ),
        ];
    }

    public static function invoices( int $limit = 20 ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_invoices' );
        $rows = $db->fetchAll(
            "SELECT id, invoice_number, customer_name, customer_email, status, currency, issued_date, due_date, paid_date, total_amount,
                    stripe_payment_intent_id, sent_at, created_at
             FROM {$table}
             WHERE org_id = %d
             ORDER BY issued_date DESC, id DESC
             LIMIT %d",
            [ ModeService::orgId(), max( 1, min( 100, $limit ) ) ]
        );

        $today = gmdate( 'Y-m-d' );
        return array_map(
            static function ( array $row ) use ( $today ): array {
                $status = (string) ( $row['status'] ?? '' );
                $dueDate = (string) ( $row['due_date'] ?? '' );
                $displayStatus = $status;
                if ( $status !== 'paid' && $status !== 'draft' && $dueDate !== '' && $dueDate < $today ) {
                    $displayStatus = 'overdue';
                }

                return [
                    'id' => (int) ( $row['id'] ?? 0 ),
                    'invoice_number' => (string) ( $row['invoice_number'] ?? '' ),
                    'customer_name' => (string) ( $row['customer_name'] ?? '' ),
                    'customer_email' => (string) ( $row['customer_email'] ?? '' ),
                    'status' => $displayStatus,
                    'currency' => (string) ( $row['currency'] ?? 'usd' ),
                    'issued_date' => (string) ( $row['issued_date'] ?? '' ),
                    'due_date' => $dueDate,
                    'paid_date' => (string) ( $row['paid_date'] ?? '' ),
                    'total_amount' => (float) ( $row['total_amount'] ?? 0 ),
                    'stripe_payment_intent_id' => (string) ( $row['stripe_payment_intent_id'] ?? '' ),
                    'sent_at' => (string) ( $row['sent_at'] ?? '' ),
                    'created_at' => (string) ( $row['created_at'] ?? '' ),
                ];
            },
            $rows
        );
    }

    public static function invoiceAging(): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_invoices' );
        $orgId = ModeService::orgId();

        $openSummary = $db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN due_date >= UTC_DATE() THEN total_amount ELSE 0 END), 0) AS current_amount,
                COALESCE(SUM(CASE WHEN due_date < UTC_DATE() AND DATEDIFF(UTC_DATE(), due_date) BETWEEN 1 AND 30 THEN total_amount ELSE 0 END), 0) AS overdue_1_30_amount,
                COALESCE(SUM(CASE WHEN DATEDIFF(UTC_DATE(), due_date) BETWEEN 31 AND 60 THEN total_amount ELSE 0 END), 0) AS overdue_31_60_amount,
                COALESCE(SUM(CASE WHEN DATEDIFF(UTC_DATE(), due_date) > 60 THEN total_amount ELSE 0 END), 0) AS overdue_61_plus_amount,
                COALESCE(SUM(total_amount), 0) AS open_total,
                COUNT(*) AS open_count
             FROM {$table}
             WHERE org_id = %d
               AND status IN ('sent', 'overdue')",
            [ $orgId ]
        ) ?: [];

        $draftSummary = $db->fetchOne(
            "SELECT COUNT(*) AS draft_count, COALESCE(SUM(total_amount), 0) AS draft_total
             FROM {$table}
             WHERE org_id = %d AND status = 'draft'",
            [ $orgId ]
        ) ?: [];

        return [
            'current_amount' => (float) ( $openSummary['current_amount'] ?? 0 ),
            'overdue_1_30_amount' => (float) ( $openSummary['overdue_1_30_amount'] ?? 0 ),
            'overdue_31_60_amount' => (float) ( $openSummary['overdue_31_60_amount'] ?? 0 ),
            'overdue_61_plus_amount' => (float) ( $openSummary['overdue_61_plus_amount'] ?? 0 ),
            'open_total' => (float) ( $openSummary['open_total'] ?? 0 ),
            'open_count' => (int) ( $openSummary['open_count'] ?? 0 ),
            'draft_count' => (int) ( $draftSummary['draft_count'] ?? 0 ),
            'draft_total' => (float) ( $draftSummary['draft_total'] ?? 0 ),
        ];
    }

    public static function fiscalSettingsSnapshot(): array {
        SchemaManager::ensureSchema();

        $db = \metis_db();
        $settingsTable = \Metis_Tables::get( 'finance_v2_fiscal_settings' );
        $periodsTable = \Metis_Tables::get( 'finance_v2_fiscal_periods' );
        $orgId = ModeService::orgId();

        $settings = $db->fetchOne(
            "SELECT fiscal_year_start_month, timezone, active_period_id
             FROM {$settingsTable}
             WHERE org_id = %d
             LIMIT 1",
            [ $orgId ]
        ) ?: [];

        $periods = $db->fetchAll(
            "SELECT id, label, start_date, end_date, status, created_at
             FROM {$periodsTable}
             WHERE org_id = %d
             ORDER BY start_date DESC, id DESC
             LIMIT 24",
            [ $orgId ]
        );

        return [
            'fiscal_year_start_month' => (int) ( $settings['fiscal_year_start_month'] ?? 1 ),
            'timezone' => self::platformTimezoneName(),
            'active_period_id' => (int) ( $settings['active_period_id'] ?? 0 ),
            'periods' => array_map(
                static fn( array $row ): array => [
                    'id' => (int) ( $row['id'] ?? 0 ),
                    'label' => (string) ( $row['label'] ?? '' ),
                    'start_date' => (string) ( $row['start_date'] ?? '' ),
                    'end_date' => (string) ( $row['end_date'] ?? '' ),
                    'status' => (string) ( $row['status'] ?? '' ),
                    'created_at' => (string) ( $row['created_at'] ?? '' ),
                ],
                $periods
            ),
        ];
    }

    public static function updateFiscalSettings( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $month = (int) ( $input['fiscal_year_start_month'] ?? 1 );
        $month = max( 1, min( 12, $month ) );
        $timezone = self::platformTimezoneName();

        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_fiscal_settings' );
        $orgId = ModeService::orgId();
        $exists = (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE org_id = %d", [ $orgId ] );

        if ( $exists > 0 ) {
            $db->update(
                $table,
                [
                    'fiscal_year_start_month' => $month,
                    'timezone' => $timezone,
                    'updated_by' => $requestedBy > 0 ? $requestedBy : null,
                    'updated_at' => Support::now(),
                ],
                [ 'org_id' => $orgId ],
                [ '%d', '%s', '%d', '%s' ],
                [ '%d' ]
            );
        } else {
            $db->insert(
                $table,
                [
                    'org_id' => $orgId,
                    'fiscal_year_start_month' => $month,
                    'timezone' => $timezone,
                    'updated_by' => $requestedBy > 0 ? $requestedBy : null,
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%d', '%s', '%d', '%s', '%s' ]
            );
        }

        return [
            'ok' => true,
            'fiscal' => self::fiscalSettingsSnapshot(),
        ];
    }

    private static function platformTimezoneName(): string {
        if ( class_exists( '\Core_Settings_Service' ) ) {
            $tz = trim( (string) \Core_Settings_Service::get( 'timezone', \Core_Settings_Service::get( 'site_timezone', 'UTC' ) ) );
            if ( $tz !== '' && in_array( $tz, timezone_identifiers_list(), true ) ) {
                return $tz;
            }
        }

        if ( function_exists( 'metis_runtime_timezone' ) ) {
            try {
                $tz = metis_runtime_timezone();
                if ( $tz instanceof \DateTimeZone ) {
                    return $tz->getName();
                }
            } catch ( \Throwable ) {
            }
        }

        return 'UTC';
    }

    public static function migrateFiscalPeriod( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $label = metis_text_clean( (string) ( $input['label'] ?? '' ) );
        $startDate = metis_text_clean( (string) ( $input['start_date'] ?? '' ) );
        $endDate = metis_text_clean( (string) ( $input['end_date'] ?? '' ) );
        if ( $label === '' ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Period label is required.' ];
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $startDate ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $endDate ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Period dates are required.' ];
        }
        if ( $startDate > $endDate ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Start date must be before end date.' ];
        }

        $db = \metis_db();
        $settingsTable = \Metis_Tables::get( 'finance_v2_fiscal_settings' );
        $periodsTable = \Metis_Tables::get( 'finance_v2_fiscal_periods' );
        $orgId = ModeService::orgId();

        $overlap = (int) $db->scalar(
            "SELECT COUNT(*)
             FROM {$periodsTable}
             WHERE org_id = %d
               AND start_date <= %s
               AND end_date >= %s",
            [ $orgId, $endDate, $startDate ]
        );
        if ( $overlap > 0 ) {
            return [ 'ok' => false, 'status' => 409, 'message' => 'Fiscal period overlaps an existing period.' ];
        }

        $db->execute(
            $db->prepare(
                "UPDATE {$periodsTable}
                 SET status = %s, updated_at = %s
                 WHERE org_id = %d AND status = %s",
                'closed',
                Support::now(),
                $orgId,
                'active'
            )
        );

        $created = $db->insert(
            $periodsTable,
            [
                'org_id' => $orgId,
                'label' => $label,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'active',
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $created ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not create fiscal period.' ];
        }

        $periodId = (int) $db->lastInsertId();
        $db->update(
            $settingsTable,
            [
                'active_period_id' => $periodId,
                'updated_by' => $requestedBy > 0 ? $requestedBy : null,
                'updated_at' => Support::now(),
            ],
            [ 'org_id' => $orgId ],
            [ '%d', '%d', '%s' ],
            [ '%d' ]
        );

        return [
            'ok' => true,
            'fiscal' => self::fiscalSettingsSnapshot(),
        ];
    }

    public static function reportSnapshot(): array {
        return [
            'types' => [
                [ 'code' => 'balance_sheet', 'label' => 'Balance Sheet' ],
                [ 'code' => 'cash_flow', 'label' => 'Cash Flow' ],
                [ 'code' => 'treasury_summary', 'label' => 'Treasury Summary' ],
                [ 'code' => 'budget_vs_actual', 'label' => 'Budget vs Actual' ],
            ],
            'periods' => [
                [ 'code' => 'mtd', 'label' => 'MTD' ],
                [ 'code' => 'qtd', 'label' => 'QTD' ],
                [ 'code' => 'ytd', 'label' => 'YTD' ],
                [ 'code' => 'trailing_12', 'label' => 'Trailing 12' ],
            ],
            'default_type' => 'treasury_summary',
            'default_period' => 'mtd',
            'default_orientation' => 'landscape',
        ];
    }

    public static function renderReportData( string $reportType, string $periodCode, bool $includePreviousMonth = false ): array {
        SchemaManager::ensureSchema();

        $reportType = metis_key_clean( $reportType );
        $periodCode = metis_key_clean( $periodCode );
        if ( ! in_array( $reportType, [ 'balance_sheet', 'cash_flow', 'treasury_summary', 'budget_vs_actual' ], true ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Invalid report type.' ];
        }

        if ( ! in_array( $periodCode, [ 'mtd', 'qtd', 'ytd', 'trailing_12' ], true ) ) {
            return [ 'ok' => false, 'status' => 422, 'message' => 'Invalid period.' ];
        }

        $range = self::reportPeriodRange( $periodCode );
        $periodStart = (string) $range['start'];
        $periodEnd = (string) $range['end'];
        $label = (string) $range['label'];
        $orgId = ModeService::orgId();
        $db = \metis_db();
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );
        $accountsTable = \Metis_Tables::get( 'finance_v2_accounts' );
        $invoicesTable = \Metis_Tables::get( 'finance_v2_invoices' );
        $payoutsTable = \Metis_Tables::get( 'finance_v2_stripe_payouts' );

        $reportRows = [];
        $totals = [];
        $previous = null;

        if ( $reportType === 'balance_sheet' ) {
            $rows = $db->fetchAll(
                "SELECT a.account_type, e.account_code, a.account_name, COALESCE(SUM(e.amount_signed), 0) AS balance_amount
                 FROM {$entriesTable} e
                 INNER JOIN {$accountsTable} a
                   ON a.org_id = e.org_id
                  AND a.account_code = e.account_code
                 WHERE e.org_id = %d
                   AND e.entry_date <= %s
                 GROUP BY a.account_type, e.account_code, a.account_name
                 ORDER BY a.account_type ASC, e.account_code ASC",
                [ $orgId, $periodEnd ]
            );

            $byType = [];
            foreach ( $rows as $row ) {
                $type = (string) ( $row['account_type'] ?? 'other' );
                if ( ! isset( $byType[ $type ] ) ) {
                    $byType[ $type ] = [ 'total' => 0.0, 'rows' => [] ];
                }
                $amount = round( (float) ( $row['balance_amount'] ?? 0 ), 2 );
                $byType[ $type ]['total'] += $amount;
                $byType[ $type ]['rows'][] = [
                    'account_code' => (string) ( $row['account_code'] ?? '' ),
                    'account_name' => (string) ( $row['account_name'] ?? '' ),
                    'amount' => $amount,
                ];
            }
            $reportRows = $byType;
            $totals = [
                'assets' => round( (float) ( $byType['asset']['total'] ?? 0 ), 2 ),
                'liabilities' => round( (float) ( $byType['liability']['total'] ?? 0 ), 2 ),
                'equity' => round( (float) ( $byType['equity']['total'] ?? 0 ), 2 ),
            ];
        } elseif ( $reportType === 'cash_flow' ) {
            $rows = $db->fetchAll(
                "SELECT category_code,
                        COALESCE(SUM(CASE WHEN amount_signed > 0 THEN amount_signed ELSE 0 END), 0) AS inflow_amount,
                        COALESCE(SUM(CASE WHEN amount_signed < 0 THEN ABS(amount_signed) ELSE 0 END), 0) AS outflow_amount,
                        COALESCE(SUM(amount_signed), 0) AS net_amount
                 FROM {$entriesTable}
                 WHERE org_id = %d
                   AND entry_date BETWEEN %s AND %s
                 GROUP BY category_code
                 ORDER BY category_code ASC",
                [ $orgId, $periodStart, $periodEnd ]
            );
            $inflows = 0.0;
            $outflows = 0.0;
            $net = 0.0;
            $reportRows = [];
            foreach ( $rows as $row ) {
                $inAmount = round( (float) ( $row['inflow_amount'] ?? 0 ), 2 );
                $outAmount = round( (float) ( $row['outflow_amount'] ?? 0 ), 2 );
                $netAmount = round( (float) ( $row['net_amount'] ?? 0 ), 2 );
                $inflows += $inAmount;
                $outflows += $outAmount;
                $net += $netAmount;
                $reportRows[] = [
                    'category_code' => (string) ( $row['category_code'] ?? 'general' ),
                    'inflow_amount' => $inAmount,
                    'outflow_amount' => $outAmount,
                    'net_amount' => $netAmount,
                ];
            }
            $totals = [
                'inflows' => round( $inflows, 2 ),
                'outflows' => round( $outflows, 2 ),
                'net' => round( $net, 2 ),
            ];
        } elseif ( $reportType === 'treasury_summary' ) {
            $cashMovement = (float) $db->scalar(
                "SELECT COALESCE(SUM(amount_signed), 0)
                 FROM {$entriesTable}
                 WHERE org_id = %d
                   AND account_code = %s
                   AND entry_date BETWEEN %s AND %s",
                [ $orgId, 'operating_cash', $periodStart, $periodEnd ]
            );
            $clearingMovement = (float) $db->scalar(
                "SELECT COALESCE(SUM(amount_signed), 0)
                 FROM {$entriesTable}
                 WHERE org_id = %d
                   AND account_code = %s
                   AND entry_date BETWEEN %s AND %s",
                [ $orgId, 'stripe_clearing', $periodStart, $periodEnd ]
            );
            $openAr = (float) $db->scalar(
                "SELECT COALESCE(SUM(total_amount), 0)
                 FROM {$invoicesTable}
                 WHERE org_id = %d
                   AND status IN ('sent', 'overdue')",
                [ $orgId ]
            );
            $expectedDeposits = (float) $db->scalar(
                "SELECT COALESCE(SUM(expected_deposit_amount), 0)
                 FROM {$payoutsTable}
                 WHERE org_id = %d
                   AND status = 'expected'",
                [ $orgId ]
            );
            $matchedDeposits = (float) $db->scalar(
                "SELECT COALESCE(SUM(expected_deposit_amount), 0)
                 FROM {$payoutsTable}
                 WHERE org_id = %d
                   AND status = 'matched'",
                [ $orgId ]
            );
            $reportRows = [
                [ 'metric' => 'Operating cash movement', 'amount' => round( $cashMovement, 2 ) ],
                [ 'metric' => 'Stripe clearing movement', 'amount' => round( $clearingMovement, 2 ) ],
                [ 'metric' => 'Open receivables', 'amount' => round( $openAr, 2 ) ],
                [ 'metric' => 'Expected deposits', 'amount' => round( $expectedDeposits, 2 ) ],
                [ 'metric' => 'Matched deposits', 'amount' => round( $matchedDeposits, 2 ) ],
            ];
            $totals = [
                'cash_movement' => round( $cashMovement, 2 ),
                'clearing_movement' => round( $clearingMovement, 2 ),
                'open_receivables' => round( $openAr, 2 ),
                'expected_deposits' => round( $expectedDeposits, 2 ),
                'matched_deposits' => round( $matchedDeposits, 2 ),
            ];

            if ( $includePreviousMonth ) {
                $prevMonthStart = gmdate( 'Y-m-01', strtotime( gmdate( 'Y-m-01' ) . ' -1 month' ) );
                $prevMonthEnd = gmdate( 'Y-m-t', strtotime( $prevMonthStart ) );
                $prevCash = (float) $db->scalar(
                    "SELECT COALESCE(SUM(amount_signed), 0)
                     FROM {$entriesTable}
                     WHERE org_id = %d
                       AND account_code = %s
                       AND entry_date BETWEEN %s AND %s",
                    [ $orgId, 'operating_cash', $prevMonthStart, $prevMonthEnd ]
                );
                $prevClear = (float) $db->scalar(
                    "SELECT COALESCE(SUM(amount_signed), 0)
                     FROM {$entriesTable}
                     WHERE org_id = %d
                       AND account_code = %s
                       AND entry_date BETWEEN %s AND %s",
                    [ $orgId, 'stripe_clearing', $prevMonthStart, $prevMonthEnd ]
                );
                $previous = [
                    'label' => gmdate( 'F Y', strtotime( $prevMonthStart ) ),
                    'cash_movement' => round( $prevCash, 2 ),
                    'clearing_movement' => round( $prevClear, 2 ),
                ];
            }
        } else {
            $activeVersionId = self::activeBudgetVersionId();
            $budgetRows = $activeVersionId > 0 ? self::budgetLines( $activeVersionId ) : [];

            $actuals = $db->fetchAll(
                "SELECT account_code, COALESCE(SUM(amount_signed), 0) AS actual_amount
                 FROM {$entriesTable}
                 WHERE org_id = %d
                   AND entry_date BETWEEN %s AND %s
                 GROUP BY account_code",
                [ $orgId, $periodStart, $periodEnd ]
            );
            $actualByAccount = [];
            foreach ( $actuals as $row ) {
                $actualByAccount[ (string) ( $row['account_code'] ?? '' ) ] = round( (float) ( $row['actual_amount'] ?? 0 ), 2 );
            }

            $plannedTotal = 0.0;
            $actualTotal = 0.0;
            $varianceTotal = 0.0;
            $reportRows = [];
            foreach ( $budgetRows as $row ) {
                $accountCode = (string) ( $row['account_code'] ?? '' );
                $planned = round( (float) ( $row['planned_amount'] ?? 0 ), 2 );
                $actual = round( (float) ( $actualByAccount[ $accountCode ] ?? 0 ), 2 );
                $variance = round( $actual - $planned, 2 );
                $plannedTotal += $planned;
                $actualTotal += $actual;
                $varianceTotal += $variance;
                $reportRows[] = [
                    'account_code' => $accountCode,
                    'account_name' => (string) ( $row['account_name'] ?? $accountCode ),
                    'planned_amount' => $planned,
                    'actual_amount' => $actual,
                    'variance_amount' => $variance,
                ];
            }
            $totals = [
                'planned_total' => round( $plannedTotal, 2 ),
                'actual_total' => round( $actualTotal, 2 ),
                'variance_total' => round( $varianceTotal, 2 ),
            ];
        }

        return [
            'ok' => true,
            'report' => [
                'type' => $reportType,
                'period' => $periodCode,
                'label' => $label,
                'range_start' => $periodStart,
                'range_end' => $periodEnd,
                'rows' => $reportRows,
                'totals' => $totals,
                'previous_month' => $previous,
            ],
        ];
    }

    public static function generateReportPdf( array $input, int $requestedBy = 0 ): array {
        SchemaManager::ensureSchema();

        $reportType = metis_key_clean( (string) ( $input['report_type'] ?? '' ) );
        $periodCode = metis_key_clean( (string) ( $input['period_code'] ?? '' ) );
        $orientation = metis_key_clean( (string) ( $input['orientation'] ?? 'landscape' ) );
        $includePrevMonth = ! empty( $input['include_previous_month'] );
        if ( ! in_array( $orientation, [ 'portrait', 'landscape' ], true ) ) {
            $orientation = 'landscape';
        }

        $data = self::renderReportData( $reportType, $periodCode, $includePrevMonth );
        if ( empty( $data['ok'] ) ) {
            return $data;
        }

        $report = is_array( $data['report'] ?? null ) ? $data['report'] : [];
        $html = self::renderReportHtml( $report, $orientation, $includePrevMonth );
        $filename = 'finance-' . $reportType . '-' . gmdate( 'Ymd-His' ) . '.pdf';

        try {
            $pdf = new \Core_PDF_Service();
            $binary = $pdf->render( $html, [ 'paper' => 'letter', 'orientation' => $orientation ] );
        } catch ( \Throwable $error ) {
            return [ 'ok' => false, 'status' => 500, 'message' => 'Could not generate PDF report.' ];
        }

        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_report_requests' );
        $db->insert(
            $table,
            [
                'org_id' => ModeService::orgId(),
                'report_type' => $reportType,
                'period_code' => $periodCode,
                'orientation' => $orientation,
                'include_prev_month' => $includePrevMonth ? 1 : 0,
                'status' => 'completed',
                'payload_json' => Support::asJson( $report ),
                'generated_at' => Support::now(),
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        return [
            'ok' => true,
            'filename' => $filename,
            'content_base64' => base64_encode( $binary ),
            'mime_type' => 'application/pdf',
            'report' => $report,
        ];
    }

    private static function renderReportHtml( array $report, string $orientation, bool $includePrevMonth ): string {
        $titleByType = [
            'balance_sheet' => 'Balance Sheet',
            'cash_flow' => 'Cash Flow',
            'treasury_summary' => 'Treasury Summary',
            'budget_vs_actual' => 'Budget vs Actual',
        ];
        $type = (string) ( $report['type'] ?? '' );
        $title = $titleByType[ $type ] ?? 'Finance Report';
        $label = metis_escape_html( (string) ( $report['label'] ?? '' ) );
        $rows = is_array( $report['rows'] ?? null ) ? $report['rows'] : [];
        $totals = is_array( $report['totals'] ?? null ) ? $report['totals'] : [];
        $previous = is_array( $report['previous_month'] ?? null ) ? $report['previous_month'] : null;

        $head = '<style>
            body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#111827;font-size:12px;}
            h1{font-size:20px;margin:0 0 6px;} .meta{color:#4b5563;margin:0 0 14px;}
            table{width:100%;border-collapse:collapse;} th,td{padding:8px 10px;text-align:left;border-bottom:1px solid #e5e7eb;}
            th{font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;}
            td.money{text-align:right;font-variant-numeric:tabular-nums;} .strong{font-weight:700;}
            .totals{margin-top:12px;padding:10px;border:1px solid #e5e7eb;border-radius:6px;background:#f9fafb;}
            .treasury-card{border:1px solid #d1d5db;border-radius:8px;padding:10px;margin:8px 0;background:#fff;}
            .treasury-row{display:flex;justify-content:space-between;padding:5px 0;}
            .treasury-row .label{color:#374151;} .treasury-row .value{font-weight:600;font-variant-numeric:tabular-nums;}
            .muted{color:#6b7280;font-size:11px;}
        </style>';

        $html = '<html><body>' . $head
            . '<h1>' . metis_escape_html( $title ) . '</h1>'
            . '<p class="meta">' . $label . ' | ' . metis_escape_html( (string) ( $report['range_start'] ?? '' ) ) . ' to ' . metis_escape_html( (string) ( $report['range_end'] ?? '' ) ) . ' | ' . metis_escape_html( ucfirst( $orientation ) ) . '</p>';

        if ( $type === 'treasury_summary' ) {
            $html .= '<div class="treasury-card">';
            foreach ( $rows as $row ) {
                $metric = metis_escape_html( (string) ( $row['metric'] ?? '' ) );
                $amount = number_format( (float) ( $row['amount'] ?? 0 ), 2 );
                $html .= '<div class="treasury-row"><span class="label">' . $metric . '</span><span class="value">' . metis_escape_html( $amount ) . '</span></div>';
            }
            $html .= '</div>';
            if ( $includePrevMonth && is_array( $previous ) ) {
                $html .= '<p class="muted">Previous month reference (' . metis_escape_html( (string) ( $previous['label'] ?? '' ) ) . '): '
                    . 'Cash ' . metis_escape_html( number_format( (float) ( $previous['cash_movement'] ?? 0 ), 2 ) ) . ', '
                    . 'Clearing ' . metis_escape_html( number_format( (float) ( $previous['clearing_movement'] ?? 0 ), 2 ) ) . '</p>';
            }
        } elseif ( $type === 'balance_sheet' ) {
            foreach ( [ 'asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity' ] as $group => $labelName ) {
                $groupRows = is_array( $rows[ $group ]['rows'] ?? null ) ? $rows[ $group ]['rows'] : [];
                $html .= '<h3>' . metis_escape_html( $labelName ) . '</h3><table><thead><tr><th>Account</th><th>Code</th><th>Amount</th></tr></thead><tbody>';
                foreach ( $groupRows as $row ) {
                    $html .= '<tr><td>' . metis_escape_html( (string) ( $row['account_name'] ?? '' ) ) . '</td><td>' . metis_escape_html( (string) ( $row['account_code'] ?? '' ) ) . '</td><td class="money">' . metis_escape_html( number_format( (float) ( $row['amount'] ?? 0 ), 2 ) ) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
        } elseif ( $type === 'cash_flow' ) {
            $html .= '<table><thead><tr><th>Category</th><th>Inflows</th><th>Outflows</th><th>Net</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $html .= '<tr>'
                    . '<td>' . metis_escape_html( (string) ( $row['category_code'] ?? '' ) ) . '</td>'
                    . '<td class="money">' . metis_escape_html( number_format( (float) ( $row['inflow_amount'] ?? 0 ), 2 ) ) . '</td>'
                    . '<td class="money">' . metis_escape_html( number_format( (float) ( $row['outflow_amount'] ?? 0 ), 2 ) ) . '</td>'
                    . '<td class="money">' . metis_escape_html( number_format( (float) ( $row['net_amount'] ?? 0 ), 2 ) ) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<table><thead><tr><th>Account</th><th>Planned</th><th>Actual</th><th>Variance</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $html .= '<tr>'
                    . '<td>' . metis_escape_html( (string) ( $row['account_name'] ?? '' ) ) . '</td>'
                    . '<td class="money">' . metis_escape_html( number_format( (float) ( $row['planned_amount'] ?? 0 ), 2 ) ) . '</td>'
                    . '<td class="money">' . metis_escape_html( number_format( (float) ( $row['actual_amount'] ?? 0 ), 2 ) ) . '</td>'
                    . '<td class="money">' . metis_escape_html( number_format( (float) ( $row['variance_amount'] ?? 0 ), 2 ) ) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        }

        if ( $totals !== [] ) {
            $html .= '<div class="totals">';
            foreach ( $totals as $key => $value ) {
                $labelText = ucwords( str_replace( '_', ' ', (string) $key ) );
                $html .= '<div class="treasury-row"><span class="label">' . metis_escape_html( $labelText ) . '</span><span class="value">' . metis_escape_html( number_format( (float) $value, 2 ) ) . '</span></div>';
            }
            $html .= '</div>';
        }

        return $html . '</body></html>';
    }

    private static function reportPeriodRange( string $periodCode ): array {
        $today = gmdate( 'Y-m-d' );
        $periodCode = metis_key_clean( $periodCode );
        $start = $today;
        $end = $today;
        $label = strtoupper( $periodCode );
        $fiscalStartMonth = self::fiscalYearStartMonth();
        $currentTs = strtotime( $today . ' 00:00:00 UTC' );
        $year = (int) gmdate( 'Y', $currentTs );
        $month = (int) gmdate( 'n', $currentTs );

        if ( $periodCode === 'mtd' ) {
            $start = gmdate( 'Y-m-01', $currentTs );
            $label = 'Month To Date';
        } elseif ( $periodCode === 'qtd' ) {
            $fiscalOffset = ( ( $month - $fiscalStartMonth ) + 12 ) % 12;
            $quarterOffset = (int) floor( $fiscalOffset / 3 ) * 3;
            $quarterStartMonth = $fiscalStartMonth + $quarterOffset;
            $quarterYear = $year;
            while ( $quarterStartMonth > 12 ) {
                $quarterStartMonth -= 12;
                $quarterYear++;
            }
            while ( $quarterStartMonth < 1 ) {
                $quarterStartMonth += 12;
                $quarterYear--;
            }
            $start = gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $quarterStartMonth, 1, $quarterYear ) );
            $label = 'Quarter To Date';
        } elseif ( $periodCode === 'ytd' ) {
            $fyYear = $month >= $fiscalStartMonth ? $year : ( $year - 1 );
            $start = gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $fiscalStartMonth, 1, $fyYear ) );
            $label = 'Year To Date';
        } elseif ( $periodCode === 'trailing_12' ) {
            $start = gmdate( 'Y-m-01', strtotime( gmdate( 'Y-m-01', $currentTs ) . ' -11 months' ) );
            $label = 'Trailing 12 Months';
        }

        return [ 'start' => $start, 'end' => $end, 'label' => $label ];
    }

    private static function fiscalYearStartMonth(): int {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_fiscal_settings' );
        $month = (int) $db->scalar(
            "SELECT fiscal_year_start_month FROM {$table} WHERE org_id = %d LIMIT 1",
            [ ModeService::orgId() ]
        );
        return max( 1, min( 12, $month > 0 ? $month : 1 ) );
    }

    private static function activeBudgetVersionId(): int {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_budget_versions' );
        $orgId = ModeService::orgId();

        $id = (int) $db->scalar(
            "SELECT id
             FROM {$table}
             WHERE org_id = %d AND is_locked = 0
             ORDER BY period_start DESC, id DESC
             LIMIT 1",
            [ $orgId ]
        );

        if ( $id > 0 ) {
            return $id;
        }

        return (int) $db->scalar(
            "SELECT id
             FROM {$table}
             WHERE org_id = %d
             ORDER BY period_start DESC, id DESC
             LIMIT 1",
            [ $orgId ]
        );
    }

    private static function createGlEntryForStripeEvent( string $eventType, string $eventDate, string $description, float $amountSigned, int $requestedBy ): void {
        if ( $eventType === 'donation' ) {
            self::insertSystemGlEntry( $eventDate, 'stripe_clearing', 'Stripe donation clearing', abs( $amountSigned ), 'debit', 'donations', 'stripe_event', $requestedBy );
            self::insertSystemGlEntry( $eventDate, 'donations_income', $description !== '' ? $description : 'Stripe donation income', abs( $amountSigned ), 'credit', 'donations', 'stripe_event', $requestedBy );
            return;
        }

        self::insertSystemGlEntry( $eventDate, 'processing_fees', $description !== '' ? $description : 'Stripe fee', abs( $amountSigned ), 'debit', 'fees', 'stripe_event', $requestedBy );
        self::insertSystemGlEntry( $eventDate, 'stripe_clearing', 'Stripe clearing reduction', abs( $amountSigned ), 'credit', 'fees', 'stripe_event', $requestedBy );
    }

    private static function createGlEntryForStripePayoutMatch( int $payoutRecordId, int $requestedBy ): void {
        $db = \metis_db();
        $payoutsTable = \Metis_Tables::get( 'finance_v2_stripe_payouts' );
        $row = $db->fetchOne(
            "SELECT payout_id, payout_date, expected_deposit_amount FROM {$payoutsTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ ModeService::orgId(), $payoutRecordId ]
        );
        if ( ! is_array( $row ) ) {
            return;
        }

        $amount = round( abs( (float) ( $row['expected_deposit_amount'] ?? 0 ) ), 2 );
        if ( $amount <= 0 ) {
            return;
        }

        $entryDate = (string) ( $row['payout_date'] ?? gmdate( 'Y-m-d' ) );
        $payoutId = (string) ( $row['payout_id'] ?? '' );
        $memo = $payoutId !== '' ? 'Stripe payout ' . $payoutId : 'Stripe payout matched';

        self::insertSystemGlEntry( $entryDate, 'operating_cash', $memo, $amount, 'debit', 'general', 'stripe_payout_match', $requestedBy );
        self::insertSystemGlEntry( $entryDate, 'stripe_clearing', $memo, $amount, 'credit', 'general', 'stripe_payout_match', $requestedBy );
    }

    private static function insertSystemGlEntry(
        string $entryDate,
        string $accountCode,
        string $description,
        float $amountAbs,
        string $dcType,
        string $categoryCode,
        string $sourceType,
        int $requestedBy
    ): void {
        $db = \metis_db();
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );
        $orgId = ModeService::orgId();

        $amountAbs = round( abs( $amountAbs ), 2 );
        if ( $amountAbs <= 0 ) {
            return;
        }

        if ( self::isPostingBlockedByFinalizedReconciliation( $entryDate ) ) {
            self::reconLog( 'warn', 'finance.v2.gl_entry.blocked_finalized_month', [
                'entry_date' => $entryDate,
                'account_code' => $accountCode,
                'source_type' => $sourceType,
            ] );
            return;
        }

        $dcType = in_array( $dcType, [ 'debit', 'credit' ], true ) ? $dcType : 'debit';
        $amountSigned = $dcType === 'credit' ? ( 0 - $amountAbs ) : $amountAbs;

        $db->insert(
            $entriesTable,
            [
                'org_id' => $orgId,
                'entry_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $entryDate ) ? $entryDate : gmdate( 'Y-m-d' ),
                'account_code' => metis_key_clean( $accountCode ),
                'description' => metis_text_clean( $description ),
                'amount_signed' => $amountSigned,
                'amount_abs' => $amountAbs,
                'dc_type' => $dcType,
                'category_code' => metis_key_clean( $categoryCode ),
                'source_type' => metis_key_clean( $sourceType ),
                'reconciliation_status' => 'unmatched',
                'created_by' => $requestedBy > 0 ? $requestedBy : null,
                'created_at' => Support::now(),
                'updated_at' => Support::now(),
            ],
            [ '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    public static function processQueuedPdfOcr( array $payload ): array {
        SchemaManager::ensureSchema();

        $runId = (int) ( $payload['run_id'] ?? 0 );
        $orgId = (int) ( $payload['org_id'] ?? 0 );
        $filePath = (string) ( $payload['file_path'] ?? '' );
        $fileHash = strtolower( trim( (string) ( $payload['file_hash'] ?? '' ) ) );
        $requestedBy = (int) ( $payload['requested_by'] ?? 0 );
        $finalStatus = metis_key_clean( (string) ( $payload['final_status'] ?? 'queued' ) );

        if ( $runId < 1 || $orgId < 1 || $filePath === '' || $fileHash === '' ) {
            throw new \RuntimeException( 'Invalid OCR payload.' );
        }
        if ( ! self::validateQueuedOcrFilePath( $filePath, $orgId ) ) {
            throw new \RuntimeException( 'OCR file path is not allowed.' );
        }
        if ( ! is_file( $filePath ) ) {
            throw new \RuntimeException( 'OCR file missing.' );
        }
        if ( strtolower( pathinfo( $filePath, PATHINFO_EXTENSION ) ) !== 'pdf' ) {
            throw new \RuntimeException( 'OCR input must be PDF.' );
        }

        $size = (int) @filesize( $filePath );
        if ( $size < 1 || $size > ( 25 * 1024 * 1024 ) ) {
            throw new \RuntimeException( 'OCR file size is invalid.' );
        }

        $actualHash = hash_file( 'sha256', $filePath );
        if ( ! is_string( $actualHash ) || strtolower( trim( $actualHash ) ) !== $fileHash ) {
            throw new \RuntimeException( 'OCR file hash mismatch.' );
        }

        $db = \metis_db();
        $runsTable = \Metis_Tables::get( 'finance_v2_recon_parse_runs' );
        $run = $db->fetchOne(
            "SELECT id, org_id FROM {$runsTable} WHERE id = %d LIMIT 1",
            [ $runId ]
        );
        if ( ! is_array( $run ) || (int) ( $run['org_id'] ?? 0 ) !== $orgId ) {
            throw new \RuntimeException( 'OCR run record not found.' );
        }

        $db->update(
            $runsTable,
            [ 'status' => 'ocr_processing', 'updated_at' => Support::now() ],
            [ 'id' => $runId, 'org_id' => $orgId ],
            [ '%s', '%s' ],
            [ '%d', '%d' ]
        );

        $imported = self::importStatementLinesFromUpload( $filePath, 'pdf', [], $runId, $requestedBy, $orgId );
        $newStatus = $imported > 0 ? ( $finalStatus !== '' ? $finalStatus : 'queued' ) : 'manual_match_required';
        $db->update(
            $runsTable,
            [ 'status' => $newStatus, 'updated_at' => Support::now() ],
            [ 'id' => $runId, 'org_id' => $orgId ],
            [ '%s', '%s' ],
            [ '%d', '%d' ]
        );

        if ( is_file( $filePath ) ) {
            @unlink( $filePath );
        }

        self::reconLog( 'info', 'finance.v2.recon_import.ocr_completed', [
            'run_id' => $runId,
            'org_id' => $orgId,
            'imported_statement_lines' => $imported,
            'status' => $newStatus,
        ] );

        return [
            'run_id' => $runId,
            'org_id' => $orgId,
            'imported_statement_lines' => $imported,
            'status' => $newStatus,
        ];
    }

    private static function validateQueuedOcrFilePath( string $path, int $orgId ): bool {
        $files = \metis_service( 'files' );
        $base = $files->rootPath( 'storage/runtime/finance/recon_uploads/org_' . $orgId );
        $baseReal = realpath( $base );
        $pathReal = realpath( $path );
        if ( ! is_string( $baseReal ) || $baseReal === '' || ! is_string( $pathReal ) || $pathReal === '' ) {
            return false;
        }
        $baseNorm = rtrim( str_replace( '\\', '/', $baseReal ), '/' ) . '/';
        $pathNorm = str_replace( '\\', '/', $pathReal );
        return str_starts_with( $pathNorm, $baseNorm );
    }

    private static function importStatementLinesFromUpload( string $tmpPath, string $importType, array $mapping, int $runId, int $requestedBy, int $orgIdOverride = 0 ): int {
        if ( $tmpPath === '' || ! is_file( $tmpPath ) ) {
            return 0;
        }

        $lines = $importType === 'csv'
            ? self::parseCsvStatementLines( $tmpPath, $mapping )
            : self::parsePdfStatementLines( $tmpPath );

        if ( $lines === [] ) {
            return 0;
        }

        $db = \metis_db();
        $orgId = $orgIdOverride > 0 ? $orgIdOverride : ModeService::orgId();
        $table = \Metis_Tables::get( 'finance_v2_bank_lines' );
        $inserted = 0;

        foreach ( $lines as $line ) {
            $lineDate = self::normalizeDateValue( (string) ( $line['line_date'] ?? '' ) );
            $description = metis_text_clean( (string) ( $line['description'] ?? '' ) );
            $amountSigned = round( (float) ( $line['amount_signed'] ?? 0 ), 2 );
            if ( $lineDate === '' || $description === '' || abs( $amountSigned ) < 0.01 ) {
                continue;
            }

            $ok = $db->insert(
                $table,
                [
                    'org_id' => $orgId,
                    'recon_parse_run_id' => $runId,
                    'source_type' => 'statement_import',
                    'line_date' => $lineDate,
                    'description' => $description,
                    'amount_signed' => $amountSigned,
                    'status' => 'unmatched',
                    'metadata_json' => Support::asJson( [ 'source' => 'statement_import' ] ),
                    'created_by' => $requestedBy > 0 ? $requestedBy : null,
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s', '%s' ]
            );
            if ( $ok ) {
                $inserted++;
            }
        }

        return $inserted;
    }

    private static function queueReconciliationPdfOcr( string $tmpPath, int $runId, string $fileName, int $requestedBy, string $finalStatus ): array {
        try {
            $db = \metis_db();
            $table = \Metis_Tables::get( 'finance_v2_recon_parse_runs' );
            $orgId = ModeService::orgId();

            $stored = self::storeReconciliationUploadFile( $tmpPath, $runId, $fileName );
            if ( empty( $stored['ok'] ) ) {
                return [ 'ok' => false, 'message' => (string) ( $stored['message'] ?? 'Could not persist PDF for OCR processing.' ) ];
            }

            $payload = [
                'run_id' => $runId,
                'org_id' => $orgId,
                'file_path' => (string) ( $stored['path'] ?? '' ),
                'file_hash' => (string) ( $stored['sha256'] ?? '' ),
                'requested_by' => $requestedBy,
                'final_status' => $finalStatus,
            ];

            $queued = \metis_job_queue()->enqueue(
                'finance_v2.recon_pdf_ocr',
                $payload,
                [
                    'queue' => 'finance',
                    'priority' => 30,
                    'max_attempts' => 2,
                    'dedupe_key' => 'finance_v2.recon_pdf_ocr:' . $runId,
                    'created_by' => $requestedBy > 0 ? $requestedBy : null,
                ]
            );

            if ( empty( $queued['ok'] ) ) {
                return [ 'ok' => false, 'message' => 'OCR queue request failed.' ];
            }

            $db->update(
                $table,
                [ 'status' => 'ocr_pending', 'updated_at' => Support::now() ],
                [ 'id' => $runId, 'org_id' => $orgId ],
                [ '%s', '%s' ],
                [ '%d', '%d' ]
            );

            self::reconLog( 'info', 'finance.v2.recon_import.ocr_queued', [
                'run_id' => $runId,
                'org_id' => $orgId,
                'job_code' => (string) ( $queued['job_code'] ?? '' ),
                'file_path' => (string) ( $stored['path'] ?? '' ),
            ] );

            return [ 'ok' => true, 'job_code' => (string) ( $queued['job_code'] ?? '' ) ];
        } catch ( \Throwable $e ) {
            self::reconLog( 'error', 'finance.v2.recon_import.ocr_queue_failed', [
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ] );
            return [ 'ok' => false, 'message' => 'OCR queue request failed.' ];
        }
    }

    private static function storeReconciliationUploadFile( string $tmpPath, int $runId, string $fileName ): array {
        if ( $tmpPath === '' || ! is_file( $tmpPath ) ) {
            return [ 'ok' => false, 'message' => 'Uploaded file could not be read for OCR queue.' ];
        }

        $files = \metis_service( 'files' );
        $orgId = ModeService::orgId();
        $runtimeDir = $files->ensureDirectory(
            $files->rootPath( 'storage/runtime/finance/recon_uploads/org_' . $orgId )
        );
        $safeBase = preg_replace( '/[^a-z0-9_-]+/i', '-', pathinfo( $fileName, PATHINFO_FILENAME ) ?: 'statement' ) ?: 'statement';
        $targetPath = rtrim( $runtimeDir, '/\\' ) . '/run_' . $runId . '_' . strtolower( $safeBase ) . '.pdf';
        $files->copy( $tmpPath, $targetPath );

        $sha = hash_file( 'sha256', $targetPath );
        if ( ! is_string( $sha ) || trim( $sha ) === '' ) {
            $files->remove( $targetPath );
            return [ 'ok' => false, 'message' => 'Could not fingerprint uploaded PDF.' ];
        }

        return [
            'ok' => true,
            'path' => $targetPath,
            'sha256' => strtolower( trim( $sha ) ),
        ];
    }

    private static function parseCsvStatementLines( string $tmpPath, array $mapping ): array {
        $delimiter = self::detectCsvDelimiter( $tmpPath );
        $fh = @fopen( $tmpPath, 'rb' );
        if ( $fh === false ) {
            return [];
        }

        $rows = [];
        $headers = [];
        $headerMap = [];
        $headerCount = 0;
        $parsedCount = 0;
        $dateIndex = -1;
        $descIndex = -1;
        $amountIndex = -1;
        $debitIndex = -1;
        $creditIndex = -1;

        while ( ( $row = fgetcsv( $fh, 0, $delimiter ) ) !== false ) {
            if ( $headers === [] ) {
                $headerCount = count( $row );
                foreach ( $row as $idx => $value ) {
                    $header = trim( (string) $value );
                    if ( $idx === 0 ) {
                        $header = preg_replace( '/^\xEF\xBB\xBF/u', '', $header ) ?: $header;
                    }
                    $headers[ $idx ] = $header;
                    $normalized = self::normalizeCsvHeaderKey( $header );
                    if ( $normalized !== '' ) {
                        $headerMap[ $normalized ] = $idx;
                    }
                }
                $dateIndex = self::resolveCsvColumnIndex(
                    $mapping,
                    'date_column',
                    $headers,
                    $headerMap,
                    [ 'date', 'postingdate', 'transactiondate', 'valuedate' ]
                );
                $descIndex = self::resolveCsvColumnIndex(
                    $mapping,
                    'description_column',
                    $headers,
                    $headerMap,
                    [ 'description', 'memo', 'details', 'narrative', 'payee', 'merchant', 'transaction' ]
                );
                $amountIndex = self::resolveCsvColumnIndex(
                    $mapping,
                    'amount_column',
                    $headers,
                    $headerMap,
                    [ 'amount', 'transactionamount', 'netamount', 'value', 'amt' ]
                );
                $debitIndex = self::resolveCsvColumnIndex(
                    $mapping,
                    'debit_column',
                    $headers,
                    $headerMap,
                    [ 'debit', 'withdrawal', 'debits', 'moneyout' ]
                );
                $creditIndex = self::resolveCsvColumnIndex(
                    $mapping,
                    'credit_column',
                    $headers,
                    $headerMap,
                    [ 'credit', 'deposit', 'credits', 'moneyin' ]
                );
                continue;
            }
            if ( $row === [] || $headerCount < 1 ) {
                continue;
            }

            $line = array_pad( $row, $headerCount, '' );
            if ( count( $line ) < $headerCount ) {
                continue;
            }

            $dateValue = $dateIndex >= 0 ? (string) ( $line[ $dateIndex ] ?? '' ) : '';
            $descValue = $descIndex >= 0 ? (string) ( $line[ $descIndex ] ?? '' ) : '';
            $amountValue = $amountIndex >= 0 ? (string) ( $line[ $amountIndex ] ?? '' ) : '';

            $normalizedDate = self::normalizeDateValue( $dateValue );
            $normalizedDesc = metis_text_clean( trim( $descValue ) );
            if ( $normalizedDesc === '' ) {
                $normalizedDesc = self::deriveCsvDescription( $line, [ $dateIndex, $amountIndex, $debitIndex, $creditIndex ] );
            }
            $normalizedAmount = self::normalizeAmountValue( $amountValue );

            if ( abs( $normalizedAmount ) < 0.01 ) {
                $debitAmount = $debitIndex >= 0 ? abs( self::normalizeAmountValue( (string) ( $line[ $debitIndex ] ?? '' ) ) ) : 0.0;
                $creditAmount = $creditIndex >= 0 ? abs( self::normalizeAmountValue( (string) ( $line[ $creditIndex ] ?? '' ) ) ) : 0.0;
                if ( $creditAmount > 0 || $debitAmount > 0 ) {
                    $normalizedAmount = round( $creditAmount - $debitAmount, 2 );
                }
            }

            if ( $normalizedDate === '' || $normalizedDesc === '' || abs( $normalizedAmount ) < 0.01 ) {
                continue;
            }

            $rows[] = [
                'line_date' => $normalizedDate,
                'description' => $normalizedDesc,
                'amount_signed' => $normalizedAmount,
            ];
            $parsedCount++;
        }
        fclose( $fh );

        self::reconLog( 'info', 'finance.v2.recon_import.csv_parsed', [
            'delimiter' => $delimiter,
            'headers' => array_values( $headers ),
            'parsed_rows' => $parsedCount,
        ] );

        return $rows;
    }

    private static function parsePdfStatementLines( string $tmpPath ): array {
        $text = self::extractPdfTextContent( $tmpPath );
        $rows = self::parseStatementRowsFromText( $text );
        if ( $rows !== [] ) {
            self::reconLog( 'info', 'finance.v2.recon_import.pdf_parsed', [
                'parsed_rows' => count( $rows ),
                'source' => 'embedded_text',
            ] );
            return $rows;
        }

        $ocr = self::extractPdfTextViaOcrPipeline( $tmpPath );
        if ( $ocr['text'] !== '' ) {
            $rows = self::parseStatementRowsFromText( $ocr['text'] );
        }

        self::reconLog( 'info', 'finance.v2.recon_import.pdf_parsed', [
            'parsed_rows' => count( $rows ),
            'source' => $ocr['source'] !== '' ? $ocr['source'] : 'none',
            'tooling' => $ocr['tooling'],
        ] );
        return $rows;
    }

    private static function parseStatementRowsFromText( string $text ): array {
        if ( trim( $text ) === '' ) {
            return [];
        }

        $rows = [];
        $dateContext = self::inferStatementDateContext( $text );
        $lines = preg_split( '/\\r\\n|\\r|\\n/', $text ) ?: [];
        foreach ( $lines as $line ) {
            $line = trim( preg_replace( '/\\s+/', ' ', (string) $line ) ?: '' );
            if ( $line === '' ) {
                continue;
            }

            if ( preg_match( '/(\\d{1,2}[\\/\\-]\\d{1,2}(?:[\\/\\-]\\d{2,4})?)/', $line, $dateMatch ) !== 1 ) {
                continue;
            }
            if ( preg_match( '/((?:\\(|-)?\\$?\\d[\\d,]*\\.\\d{2}(?:\\))?(?:\\s*(?:cr|dr))?)/i', $line, $amountMatch ) !== 1 ) {
                continue;
            }

            $rawDate = (string) $dateMatch[1];
            $rawAmount = (string) $amountMatch[1];
            $normalizedDate = self::normalizeStatementDateWithContext( $rawDate, $dateContext );
            $normalizedAmount = self::normalizeAmountValue( $rawAmount );
            if ( preg_match( '/\\bdr\\b/i', $rawAmount ) ) {
                $normalizedAmount = 0 - abs( $normalizedAmount );
            } elseif ( preg_match( '/\\bcr\\b/i', $rawAmount ) ) {
                $normalizedAmount = abs( $normalizedAmount );
            }

            $desc = str_replace( [ $rawDate, $rawAmount ], '', $line );
            $desc = metis_text_clean( trim( preg_replace( '/\\s+/', ' ', $desc ) ?: '' ) );
            if ( $normalizedDate === '' || $desc === '' || abs( $normalizedAmount ) < 0.01 ) {
                continue;
            }

            $rows[] = [
                'line_date' => $normalizedDate,
                'description' => $desc,
                'amount_signed' => $normalizedAmount,
            ];
        }

        return $rows;
    }

    private static function inferStatementDateContext( string $text ): array {
        $context = [
            'start_year' => 0,
            'start_month' => 0,
            'end_year' => 0,
            'end_month' => 0,
        ];

        if ( preg_match( '/Statement\\s+Dates\\s+([0-9]{1,2}[\\/\\-][0-9]{1,2}[\\/\\-][0-9]{2,4})\\s+thru\\s+([0-9]{1,2}[\\/\\-][0-9]{1,2}[\\/\\-][0-9]{2,4})/i', $text, $m ) === 1 ) {
            $start = self::normalizeDateValue( (string) $m[1] );
            $end = self::normalizeDateValue( (string) $m[2] );
            if ( $start !== '' && $end !== '' ) {
                $context['start_year'] = (int) substr( $start, 0, 4 );
                $context['start_month'] = (int) substr( $start, 5, 2 );
                $context['end_year'] = (int) substr( $end, 0, 4 );
                $context['end_month'] = (int) substr( $end, 5, 2 );
            }
        }

        if ( $context['end_year'] < 1 ) {
            $context['end_year'] = (int) gmdate( 'Y' );
        }

        return $context;
    }

    private static function normalizeStatementDateWithContext( string $rawDate, array $context ): string {
        $normalized = self::normalizeDateValue( $rawDate );
        if ( $normalized !== '' ) {
            return $normalized;
        }

        if ( preg_match( '/^(\\d{1,2})[\\/\\-](\\d{1,2})$/', trim( $rawDate ), $m ) !== 1 ) {
            return '';
        }

        $month = (int) $m[1];
        $day = (int) $m[2];
        if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
            return '';
        }

        $startYear = (int) ( $context['start_year'] ?? 0 );
        $startMonth = (int) ( $context['start_month'] ?? 0 );
        $endYear = (int) ( $context['end_year'] ?? (int) gmdate( 'Y' ) );
        $endMonth = (int) ( $context['end_month'] ?? 0 );

        $year = $endYear;
        if ( $startYear > 0 && $endYear > 0 && $startYear !== $endYear && $startMonth > 0 && $endMonth > 0 ) {
            $year = $month >= $startMonth ? $startYear : $endYear;
        }

        return sprintf( '%04d-%02d-%02d', $year, $month, $day );
    }

    private static function detectCsvDelimiter( string $tmpPath ): string {
        $sample = (string) @file_get_contents( $tmpPath, false, null, 0, 4096 );
        if ( $sample === '' ) {
            return ',';
        }
        $candidates = [ ',', ';', "\t", '|' ];
        $best = ',';
        $score = -1;
        foreach ( $candidates as $candidate ) {
            $count = substr_count( $sample, $candidate );
            if ( $count > $score ) {
                $score = $count;
                $best = $candidate;
            }
        }
        return $best;
    }

    private static function normalizeCsvHeaderKey( string $header ): string {
        $header = strtolower( trim( $header ) );
        if ( $header === '' ) {
            return '';
        }
        return preg_replace( '/[^a-z0-9]+/', '', $header ) ?: '';
    }

    private static function resolveCsvColumnIndex( array $mapping, string $mappingKey, array $headers, array $headerMap, array $fallbackNames ): int {
        $mapped = self::normalizeCsvHeaderKey( (string) ( $mapping[ $mappingKey ] ?? '' ) );
        if ( $mapped !== '' ) {
            if ( array_key_exists( $mapped, $headerMap ) ) {
                return (int) $headerMap[ $mapped ];
            }
            foreach ( $headers as $idx => $label ) {
                if ( self::normalizeCsvHeaderKey( (string) $label ) === $mapped ) {
                    return (int) $idx;
                }
            }
        }
        foreach ( $fallbackNames as $name ) {
            $needle = self::normalizeCsvHeaderKey( (string) $name );
            if ( $needle !== '' && array_key_exists( $needle, $headerMap ) ) {
                return (int) $headerMap[ $needle ];
            }
        }
        return -1;
    }

    private static function deriveCsvDescription( array $line, array $skipIndexes ): string {
        $parts = [];
        $skip = array_fill_keys(
            array_values(
                array_filter(
                    array_map( static fn( mixed $idx ): int => (int) $idx, $skipIndexes ),
                    static fn( int $idx ): bool => $idx >= 0
                )
            ),
            true
        );
        foreach ( $line as $idx => $value ) {
            $idx = (int) $idx;
            if ( isset( $skip[ $idx ] ) ) {
                continue;
            }
            $text = trim( (string) $value );
            if ( $text === '' ) {
                continue;
            }
            if ( self::normalizeDateValue( $text ) !== '' ) {
                continue;
            }
            if ( abs( self::normalizeAmountValue( $text ) ) >= 0.01 ) {
                continue;
            }
            $parts[] = $text;
            if ( count( $parts ) >= 2 ) {
                break;
            }
        }
        return metis_text_clean( trim( implode( ' ', $parts ) ) );
    }

    private static function extractPdfTextContent( string $tmpPath ): string {
        $content = @file_get_contents( $tmpPath );
        if ( ! is_string( $content ) || $content === '' ) {
            return '';
        }

        $chunks = [ $content ];
        if ( preg_match_all( '/stream\\s*(.*?)\\s*endstream/s', $content, $streamMatches ) ) {
            foreach ( $streamMatches[1] as $streamRaw ) {
                $stream = (string) $streamRaw;
                $candidates = [
                    @gzuncompress( $stream ),
                    @gzinflate( $stream ),
                    @gzinflate( substr( $stream, 2 ) ),
                ];
                foreach ( $candidates as $decoded ) {
                    if ( is_string( $decoded ) && $decoded !== '' ) {
                        $chunks[] = $decoded;
                    }
                }
            }
        }

        $text = '';
        foreach ( $chunks as $chunk ) {
            if ( preg_match_all( '/\\((?:\\\\.|[^\\\\)])*\\)/s', $chunk, $literalMatches ) ) {
                foreach ( $literalMatches[0] as $literal ) {
                    $value = (string) $literal;
                    $value = substr( $value, 1, -1 );
                    $value = str_replace( [ '\\(', '\\)', '\\\\', '\\n', '\\r', '\\t' ], [ '(', ')', '\\', "\n", "\n", ' ' ], $value );
                    $text .= $value . "\n";
                }
            }
        }

        if ( trim( $text ) === '' ) {
            $text = preg_replace( '/[^\\x20-\\x7E\\n\\r]/', ' ', $content ) ?: '';
        }

        $normalized = str_replace( [ "\r\n", "\r" ], "\n", $text );
        $normalized = preg_replace( "/[ \t]+/", ' ', $normalized ) ?: $normalized;
        return (string) preg_replace( "/\n{3,}/", "\n\n", $normalized );
    }

    private static function extractPdfTextViaOcrPipeline( string $tmpPath ): array {
        $tooling = [
            'python3' => false,
            'pypdf' => false,
            'rapidocr_onnxruntime' => false,
            'pdf_renderer' => false,
        ];
        if ( ! self::pythonRuntimeExecutable() ) {
            self::reconLog( 'warn', 'finance.v2.recon_import.ocr_unavailable_or_empty', [
                'reason' => 'python_execution_unavailable',
            ] );
            return [ 'text' => '', 'source' => '', 'tooling' => $tooling ];
        }
        $python = self::resolvePythonExecutable();
        if ( $python === '' ) {
            self::reconLog( 'warn', 'finance.v2.recon_import.ocr_unavailable_or_empty', [
                'reason' => 'python_missing',
            ] );
            return [ 'text' => '', 'source' => '', 'tooling' => $tooling ];
        }

        $tooling['python3'] = true;

        $scriptPath = rtrim( sys_get_temp_dir(), '/\\' ) . '/metis_finance_ocr_' . uniqid( '', true ) . '.py';
        $script = <<<'PY'
import json
import importlib.util
import sys
import traceback

result = {
    "text": "",
    "source": "",
    "tooling": {
        "pypdf": False,
        "rapidocr_onnxruntime": False,
        "pdf_renderer": False,
    },
    "errors": [],
}

if len(sys.argv) < 2:
    print(json.dumps(result))
    raise SystemExit(0)

pdf_path = sys.argv[1]

try:
    if importlib.util.find_spec("pypdf"):
        from pypdf import PdfReader
        result["tooling"]["pypdf"] = True
        reader = PdfReader(pdf_path)
        chunks = []
        for page in reader.pages:
            chunks.append((page.extract_text() or "").strip())
        text = "\n".join([c for c in chunks if c])
        if text.strip():
            result["text"] = text
            result["source"] = "pypdf_text"
except Exception as exc:
    result["errors"].append("pypdf:" + str(exc))

if not result["text"].strip():
    try:
        if importlib.util.find_spec("rapidocr_onnxruntime"):
            from rapidocr_onnxruntime import RapidOCR
            result["tooling"]["rapidocr_onnxruntime"] = True
            ocr = RapidOCR()

            pages = []
            if importlib.util.find_spec("fitz"):
                import fitz
                import numpy as np
                result["tooling"]["pdf_renderer"] = "fitz"
                doc = fitz.open(pdf_path)
                for page in doc:
                    pix = page.get_pixmap(dpi=200)
                    arr = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width, pix.n)
                    pages.append(arr)
            elif importlib.util.find_spec("pypdfium2"):
                import pypdfium2 as pdfium
                result["tooling"]["pdf_renderer"] = "pypdfium2"
                doc = pdfium.PdfDocument(pdf_path)
                for idx in range(len(doc)):
                    page = doc[idx]
                    bitmap = page.render(scale=2).to_numpy()
                    pages.append(bitmap)
                    page.close()
                doc.close()
            else:
                result["errors"].append("renderer:missing(fitz|pypdfium2)")

            if pages:
                lines = []
                for page_img in pages:
                    ocr_result, _ = ocr(page_img)
                    if not ocr_result:
                        continue
                    for item in ocr_result:
                        if not isinstance(item, (list, tuple)) or len(item) < 2:
                            continue
                        txt_info = item[1]
                        if isinstance(txt_info, (list, tuple)) and len(txt_info) > 0:
                            txt = str(txt_info[0]).strip()
                            if txt:
                                lines.append(txt)
                joined = "\n".join(lines).strip()
                if joined:
                    result["text"] = joined
                    result["source"] = "rapidocr"
    except Exception as exc:
        result["errors"].append("rapidocr:" + str(exc))
        result["errors"].append(traceback.format_exc()[:600])

print(json.dumps(result))
PY;

        @file_put_contents( $scriptPath, $script );
        $run = self::runProcess( [ $python, $scriptPath, $tmpPath ], null );
        if ( is_file( $scriptPath ) ) {
            @unlink( $scriptPath );
        }

        $stdout = trim( (string) ( $run['stdout'] ?? '' ) );
        $payload = Support::decodeJson( $stdout );
        if ( ! is_array( $payload ) ) {
            self::reconLog( 'warn', 'finance.v2.recon_import.ocr_unavailable_or_empty', [
                'reason' => 'python_invalid_output',
                'exit_code' => (int) ( $run['exit_code'] ?? 1 ),
                'stderr' => substr( (string) ( $run['stderr'] ?? '' ), 0, 300 ),
                'stdout' => substr( $stdout, 0, 300 ),
            ] );
            return [ 'text' => '', 'source' => '', 'tooling' => $tooling ];
        }

        $pythonTooling = is_array( $payload['tooling'] ?? null ) ? $payload['tooling'] : [];
        $tooling['pypdf'] = ! empty( $pythonTooling['pypdf'] );
        $tooling['rapidocr_onnxruntime'] = ! empty( $pythonTooling['rapidocr_onnxruntime'] );
        $tooling['pdf_renderer'] = ! empty( $pythonTooling['pdf_renderer'] );

        $text = trim( (string) ( $payload['text'] ?? '' ) );
        $source = trim( (string) ( $payload['source'] ?? '' ) );
        if ( $text !== '' ) {
            return [
                'text' => $text,
                'source' => $source !== '' ? $source : 'python_ocr',
                'tooling' => $tooling,
            ];
        }

        self::reconLog( 'warn', 'finance.v2.recon_import.ocr_unavailable_or_empty', [
            'reason' => 'python_empty_text',
            'source' => $source,
            'errors' => is_array( $payload['errors'] ?? null ) ? array_slice( $payload['errors'], 0, 4 ) : [],
            'exit_code' => (int) ( $run['exit_code'] ?? 1 ),
            'stderr' => substr( (string) ( $run['stderr'] ?? '' ), 0, 300 ),
        ] );

        return [ 'text' => '', 'source' => '', 'tooling' => $tooling ];
    }

    private static function runProcess( array $command, ?string $cwd = null ): array {
        return ( new \Metis\Core\Services\ProcessRunner() )->run( $command, $cwd, [ 'service' => 'finance.reconciliation' ] );
    }

    private static function processExecutionAvailable(): bool {
        static $available = null;
        if ( $available !== null ) {
            return $available;
        }
        if ( ! function_exists( 'proc_open' ) ) {
            $available = false;
            return false;
        }
        $probe = self::runProcess( [ 'true' ], null );
        if ( (int) ( $probe['exit_code'] ?? 1 ) === 0 ) {
            $available = true;
            return true;
        }
        $probe = self::runProcess( [ '/bin/true' ], null );
        $available = (int) ( $probe['exit_code'] ?? 1 ) === 0;
        return $available;
    }

    private static function toolExists( string $command ): bool {
        $probe = self::runProcess( [ $command, '--version' ], null );
        if ( (int) ( $probe['exit_code'] ?? 1 ) === 0 ) {
            return true;
        }

        $probe = self::runProcess( [ $command, '-v' ], null );
        if ( (int) ( $probe['exit_code'] ?? 1 ) === 0 ) {
            return true;
        }

        $stderr = strtolower( trim( (string) ( $probe['stderr'] ?? '' ) ) );
        return $stderr !== '' && ! str_contains( $stderr, 'not found' );
    }

    private static function resolvePythonExecutable(): string {
        if ( self::toolExists( 'python3' ) ) {
            return 'python3';
        }
        $candidates = [
            '/usr/local/AppCentral/python3/bin/python3',
            '/usr/bin/python3',
        ];
        foreach ( $candidates as $candidate ) {
            if ( is_file( $candidate ) && is_executable( $candidate ) ) {
                return $candidate;
            }
        }
        return '';
    }

    private static function pythonModuleExists( string $module ): bool {
        if ( ! self::pythonRuntimeExecutable() ) {
            return false;
        }
        $python = self::resolvePythonExecutable();
        if ( $python === '' ) {
            return false;
        }
        $probe = self::runProcess(
            [ $python, '-c', "import importlib.util,sys;sys.exit(0 if importlib.util.find_spec('{$module}') else 1)" ],
            null
        );
        return (int) ( $probe['exit_code'] ?? 1 ) === 0;
    }

    private static function pdfImportWarning(): string {
        if ( ! self::pythonRuntimeExecutable() ) {
            return 'No statement lines were detected from this PDF. This web runtime cannot execute the approved Python OCR process. OCR must run via queued worker.';
        }
        $hasPython = self::resolvePythonExecutable() !== '';
        $hasPypdf = self::pythonModuleExists( 'pypdf' );
        $hasRapid = self::pythonModuleExists( 'rapidocr_onnxruntime' );
        $hasRenderer = self::pythonModuleExists( 'fitz' ) || self::pythonModuleExists( 'pypdfium2' );

        if ( ! $hasPython ) {
            return 'No statement lines were detected from this PDF. Python 3 is not available for OCR parsing.';
        }
        if ( ! $hasPypdf ) {
            return 'No statement lines were detected from this PDF. Install Python package `pypdf` for PDF text extraction.';
        }
        if ( ! $hasRapid || ! $hasRenderer ) {
            return 'No statement lines were detected from this PDF. For scanned PDFs, install `rapidocr-onnxruntime` and a PDF renderer package (`pymupdf` or `pypdfium2`).';
        }
        return 'No statement lines were detected from this PDF. If this is a scanned statement, install/configure OCR and re-upload.';
    }

    private static function pythonRuntimeExecutable(): bool {
        static $ready = null;
        if ( $ready !== null ) {
            return $ready;
        }

        if ( ! self::processExecutionAvailable() ) {
            $ready = false;
            return false;
        }

        $python = self::resolvePythonExecutable();
        if ( $python === '' ) {
            $ready = false;
            return false;
        }

        $probe = self::runProcess(
            [ $python, '-c', 'import sys; sys.stdout.write("ok")' ],
            null
        );
        $ready = (int) ( $probe['exit_code'] ?? 1 ) === 0 && trim( (string) ( $probe['stdout'] ?? '' ) ) === 'ok';
        return $ready;
    }

    private static function normalizeDateValue( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return '';
        }
        $candidates = [ 'Y-m-d', 'm/d/Y', 'm/d/y', 'n/j/Y', 'n/j/y', 'm-d-Y', 'm-d-y' ];
        foreach ( $candidates as $fmt ) {
            $dt = \DateTimeImmutable::createFromFormat( $fmt, $raw );
            if ( $dt instanceof \DateTimeImmutable ) {
                return $dt->format( 'Y-m-d' );
            }
        }
        $ts = strtotime( $raw );
        return $ts === false ? '' : gmdate( 'Y-m-d', $ts );
    }

    private static function normalizeAmountValue( string $raw ): float {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return 0.0;
        }
        $neg = str_contains( $raw, '(' ) && str_contains( $raw, ')' );
        $clean = str_replace( [ '$', ',', '(', ')', ' ' ], '', $raw );
        $amount = (float) $clean;
        if ( $neg && $amount > 0 ) {
            $amount = 0 - $amount;
        }
        return round( $amount, 2 );
    }

    private static function descriptionSimilarity( string $left, string $right ): float {
        if ( $left === '' || $right === '' ) {
            return 0.0;
        }
        $leftTokens = array_values( array_filter( preg_split( '/[^a-z0-9]+/', $left ) ?: [] ) );
        $rightTokens = array_values( array_filter( preg_split( '/[^a-z0-9]+/', $right ) ?: [] ) );
        if ( $leftTokens === [] || $rightTokens === [] ) {
            return 0.0;
        }
        $leftSet = array_fill_keys( $leftTokens, true );
        $rightSet = array_fill_keys( $rightTokens, true );
        $common = array_intersect_key( $leftSet, $rightSet );
        return (float) ( count( $common ) * 4 );
    }

    private static function defaultReconciliationMapping( string $importType ): ?array {
        $importType = metis_key_clean( $importType );
        if ( ! in_array( $importType, [ 'csv', 'pdf' ], true ) ) {
            return null;
        }

        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_recon_column_mappings' );
        $row = $db->fetchOne(
            "SELECT mapping_json
             FROM {$table}
             WHERE org_id = %d AND import_type = %s AND is_default = 1
             ORDER BY id DESC
             LIMIT 1",
            [ ModeService::orgId(), $importType ]
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $mapping = Support::decodeJson( (string) ( $row['mapping_json'] ?? '' ) );
        return is_array( $mapping ) ? $mapping : null;
    }

    private static function calculateReconciliationConfidence( string $importType, string $fileName, array $mapping, ?array $uploadedMeta = null ): float {
        $score = $importType === 'csv' ? 78.0 : 74.0;

        $mapDate = metis_text_clean( (string) ( $mapping['date_column'] ?? '' ) );
        $mapDescription = metis_text_clean( (string) ( $mapping['description_column'] ?? '' ) );
        $mapAmount = metis_text_clean( (string) ( $mapping['amount_column'] ?? '' ) );
        if ( $mapDate !== '' ) {
            $score += 6.0;
        }
        if ( $mapDescription !== '' ) {
            $score += 6.0;
        }
        if ( $mapAmount !== '' ) {
            $score += 6.0;
        }

        $name = strtolower( trim( $fileName ) );
        if ( $name !== '' && preg_match( '/(statement|bank|payout|stripe|recon)/', $name ) ) {
            $score += 2.0;
        }

        if ( is_array( $uploadedMeta ) ) {
            $mime = strtolower( trim( (string) ( $uploadedMeta['mime_type'] ?? '' ) ) );
            if ( $mime !== '' ) {
                if ( $importType === 'pdf' && $mime === 'application/pdf' ) {
                    $score += 6.0;
                } elseif ( $importType === 'csv' && in_array( $mime, [ 'text/csv', 'text/plain', 'application/vnd.ms-excel' ], true ) ) {
                    $score += 6.0;
                } else {
                    $score -= 10.0;
                }
            }
        }

        if ( $score < 45.0 ) {
            $score = 45.0;
        }
        if ( $score > 98.0 ) {
            $score = 98.0;
        }

        return round( $score, 2 );
    }

    private static function extractUploadedFile( array $files, string $key ): ?array {
        if ( ! isset( $files[ $key ] ) || ! is_array( $files[ $key ] ) ) {
            return null;
        }

        $file = $files[ $key ];
        if ( isset( $file['tmp_name'] ) && is_array( $file['tmp_name'] ) ) {
            $first = array_key_first( $file['tmp_name'] );
            if ( $first === null ) {
                return null;
            }
            return [
                'name' => (string) ( $file['name'][ $first ] ?? '' ),
                'type' => (string) ( $file['type'][ $first ] ?? '' ),
                'tmp_name' => (string) ( $file['tmp_name'][ $first ] ?? '' ),
                'error' => (int) ( $file['error'][ $first ] ?? UPLOAD_ERR_NO_FILE ),
                'size' => (int) ( $file['size'][ $first ] ?? 0 ),
            ];
        }

        return [
            'name' => (string) ( $file['name'] ?? '' ),
            'type' => (string) ( $file['type'] ?? '' ),
            'tmp_name' => (string) ( $file['tmp_name'] ?? '' ),
            'error' => (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ),
            'size' => (int) ( $file['size'] ?? 0 ),
        ];
    }

    private static function normalizeReconMonth( string $value ): string {
        $value = trim( $value );
        if ( preg_match( '/^\d{4}-\d{2}$/', $value ) ) {
            return $value;
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return substr( $value, 0, 7 );
        }
        return '';
    }

    private static function defaultReconMonth(): string {
        return gmdate( 'Y-m', strtotime( gmdate( 'Y-m-01' ) . ' -1 month' ) );
    }

    private static function startingBalanceForMonth( string $monthFirstDay ): float {
        $db = \metis_db();
        $monthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );
        $orgId = ModeService::orgId();
        $monthFirstDay = preg_match( '/^\d{4}-\d{2}-01$/', $monthFirstDay ) ? $monthFirstDay : ( self::defaultReconMonth() . '-01' );
        $prevFirstDay = gmdate( 'Y-m-01', strtotime( $monthFirstDay . ' -1 month' ) );

        $direct = $db->scalar(
            "SELECT expected_ending_balance
             FROM {$monthsTable}
             WHERE org_id = %d
               AND recon_month = %s
               AND status = 'finalized'
             LIMIT 1",
            [ $orgId, $prevFirstDay ]
        );
        if ( $direct !== null ) {
            return round( (float) $direct, 2 );
        }

        $latest = $db->scalar(
            "SELECT expected_ending_balance
             FROM {$monthsTable}
             WHERE org_id = %d
               AND recon_month < %s
             ORDER BY recon_month DESC, id DESC
             LIMIT 1",
            [ $orgId, $monthFirstDay ]
        );

        return $latest !== null ? round( (float) $latest, 2 ) : 0.0;
    }

    private static function storeReconciliationStatement( array $upload ): array {
        $tmp = (string) ( $upload['tmp_name'] ?? '' );
        $name = metis_filename_clean( (string) ( $upload['name'] ?? '' ) );
        $error = (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE );

        if ( $tmp === '' || $name === '' || $error !== UPLOAD_ERR_OK || ! is_file( $tmp ) ) {
            return [ 'ok' => false, 'message' => 'Statement upload failed.' ];
        }
        if ( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) !== 'pdf' ) {
            return [ 'ok' => false, 'message' => 'Statement must be a PDF file.' ];
        }

        $uploaded = \metis_handle_upload( $upload, [
            'policy' => 'attachments',
            'test_form' => false,
            'mimes' => [ 'pdf' => 'application/pdf' ],
            'max_size' => 15 * 1024 * 1024,
        ] );
        if ( ! is_array( $uploaded ) || ! empty( $uploaded['error'] ) ) {
            $error = is_array( $uploaded ) ? metis_text_clean( (string) ( $uploaded['error'] ?? '' ) ) : '';
            return [ 'ok' => false, 'message' => $error !== '' ? $error : 'Statement upload failed.' ];
        }

        return [
            'ok' => true,
            'file_name' => $name,
            'token' => metis_text_clean( (string) ( $uploaded['token'] ?? '' ) ),
            'url' => metis_url_clean( (string) ( $uploaded['url'] ?? '' ) ),
        ];
    }

    private static function ensureReconMonthItems( int $monthId ): void {
        if ( $monthId < 1 ) {
            return;
        }

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $monthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );
        $itemsTable = \Metis_Tables::get( 'finance_v2_recon_month_items' );
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );

        $month = $db->fetchOne(
            "SELECT recon_month FROM {$monthsTable} WHERE org_id = %d AND id = %d LIMIT 1",
            [ $orgId, $monthId ]
        );
        if ( ! is_array( $month ) ) {
            return;
        }

        $monthFirst = (string) ( $month['recon_month'] ?? '' );
        if ( ! preg_match( '/^\d{4}-\d{2}-01$/', $monthFirst ) ) {
            return;
        }
        $monthEnd = gmdate( 'Y-m-t', strtotime( $monthFirst ) );

        $db->execute(
            $db->prepare(
                "INSERT INTO {$itemsTable} (org_id, recon_month_id, gl_entry_id, is_cleared, created_at, updated_at)
                 SELECT %d, %d, e.id, 0, %s, %s
                 FROM {$entriesTable} e
                 LEFT JOIN {$itemsTable} i
                    ON i.org_id = e.org_id
                   AND i.recon_month_id = %d
                   AND i.gl_entry_id = e.id
                 WHERE e.org_id = %d
                   AND e.entry_date BETWEEN %s AND %s
                   AND i.id IS NULL",
                $orgId,
                $monthId,
                Support::now(),
                Support::now(),
                $monthId,
                $orgId,
                $monthFirst,
                $monthEnd
            )
        );
    }

    private static function recalculateReconMonth( int $monthId ): void {
        if ( $monthId < 1 ) {
            return;
        }

        $db = \metis_db();
        $orgId = ModeService::orgId();
        $monthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );
        $itemsTable = \Metis_Tables::get( 'finance_v2_recon_month_items' );
        $entriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );

        $month = $db->fetchOne(
            "SELECT id, starting_balance, statement_ending_balance
             FROM {$monthsTable}
             WHERE org_id = %d AND id = %d
             LIMIT 1",
            [ $orgId, $monthId ]
        );
        if ( ! is_array( $month ) ) {
            return;
        }

        $clearedNet = (float) $db->scalar(
            "SELECT COALESCE(SUM(e.amount_signed), 0)
             FROM {$itemsTable} i
             INNER JOIN {$entriesTable} e
                ON e.id = i.gl_entry_id
               AND e.org_id = i.org_id
             WHERE i.org_id = %d
               AND i.recon_month_id = %d
               AND i.is_cleared = 1",
            [ $orgId, $monthId ]
        );

        $starting = round( (float) ( $month['starting_balance'] ?? 0 ), 2 );
        $statementEnding = round( (float) ( $month['statement_ending_balance'] ?? 0 ), 2 );
        $expectedEnding = round( $starting + $clearedNet, 2 );
        $difference = round( $statementEnding - $expectedEnding, 2 );

        $db->update(
            $monthsTable,
            [
                'expected_ending_balance' => $expectedEnding,
                'difference_amount' => $difference,
                'updated_at' => Support::now(),
            ],
            [ 'id' => $monthId, 'org_id' => $orgId ],
            [ '%f', '%f', '%s' ],
            [ '%d', '%d' ]
        );
    }

    private static function logReconMonthAudit( int $monthId, string $eventType, string $reason, int $actorId = 0 ): void {
        if ( $monthId < 1 ) {
            return;
        }

        $db = \metis_db();
        $table = \Metis_Tables::get( 'finance_v2_recon_month_audit' );
        $db->insert(
            $table,
            [
                'org_id' => ModeService::orgId(),
                'recon_month_id' => $monthId,
                'event_type' => metis_key_clean( $eventType ),
                'reason_text' => $reason !== '' ? metis_text_clean( $reason ) : null,
                'actor_id' => $actorId > 0 ? $actorId : null,
                'created_at' => Support::now(),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s' ]
        );
    }

    private static function isPostingBlockedByFinalizedReconciliation( string $entryDate ): bool {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $entryDate ) ) {
            return false;
        }
        $monthFirst = substr( $entryDate, 0, 7 ) . '-01';
        $status = (string) \metis_db()->scalar(
            "SELECT status
             FROM " . \Metis_Tables::get( 'finance_v2_recon_months' ) . "
             WHERE org_id = %d
               AND recon_month = %s
             LIMIT 1",
            [ ModeService::orgId(), $monthFirst ]
        );
        return $status === 'finalized';
    }

    private static function mapEntryRow( array $row ): array {
        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'entry_date' => (string) ( $row['entry_date'] ?? '' ),
            'account_code' => (string) ( $row['account_code'] ?? '' ),
            'description' => (string) ( $row['description'] ?? '' ),
            'amount_signed' => (float) ( $row['amount_signed'] ?? 0 ),
            'amount_abs' => (float) ( $row['amount_abs'] ?? 0 ),
            'dc_type' => (string) ( $row['dc_type'] ?? '' ),
            'category_code' => (string) ( $row['category_code'] ?? '' ),
            'source_type' => (string) ( $row['source_type'] ?? '' ),
            'reconciliation_status' => (string) ( $row['reconciliation_status'] ?? '' ),
            'created_at' => (string) ( $row['created_at'] ?? '' ),
        ];
    }
}
