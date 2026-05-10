<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class SchemaManager {
    private static bool $schemaReady = false;

    public static function ensureSchema(): void {
        if ( self::$schemaReady ) {
            return;
        }

        self::$schemaReady = true;

        $db = \metis_db();
        $charsetCollate = $db->get_charset_collate();

        $orgModeTable = \Metis_Tables::get( 'finance_v2_org_mode' );
        $fiscalSettingsTable = \Metis_Tables::get( 'finance_v2_fiscal_settings' );
        $fiscalPeriodsTable = \Metis_Tables::get( 'finance_v2_fiscal_periods' );
        $modeSwitchJobsTable = \Metis_Tables::get( 'finance_v2_mode_switch_jobs' );
        $accountsTable = \Metis_Tables::get( 'finance_v2_accounts' );
        $categoriesTable = \Metis_Tables::get( 'finance_v2_categories' );
        $glEntriesTable = \Metis_Tables::get( 'finance_v2_gl_entries' );
        $reconParseRunsTable = \Metis_Tables::get( 'finance_v2_recon_parse_runs' );
        $reconMappingsTable = \Metis_Tables::get( 'finance_v2_recon_column_mappings' );
        $reconReviewQueueTable = \Metis_Tables::get( 'finance_v2_recon_review_queue' );
        $reconMonthsTable = \Metis_Tables::get( 'finance_v2_recon_months' );
        $reconMonthItemsTable = \Metis_Tables::get( 'finance_v2_recon_month_items' );
        $reconMonthAuditTable = \Metis_Tables::get( 'finance_v2_recon_month_audit' );
        $budgetVersionsTable = \Metis_Tables::get( 'finance_v2_budget_versions' );
        $budgetLinesTable = \Metis_Tables::get( 'finance_v2_budget_lines' );
        $invoicesTable = \Metis_Tables::get( 'finance_v2_invoices' );
        $invoiceLinesTable = \Metis_Tables::get( 'finance_v2_invoice_lines' );
        $reportRequestsTable = \Metis_Tables::get( 'finance_v2_report_requests' );
        $kpiCacheTable = \Metis_Tables::get( 'finance_v2_kpi_cache' );
        $stripeEventsTable = \Metis_Tables::get( 'finance_v2_stripe_clearing_events' );
        $stripePayoutsTable = \Metis_Tables::get( 'finance_v2_stripe_payouts' );
        $bankLinesTable = \Metis_Tables::get( 'finance_v2_bank_lines' );
        $reconMatchesTable = \Metis_Tables::get( 'finance_v2_recon_matches' );

        \metis_db_delta( "CREATE TABLE {$orgModeTable} (
            org_id BIGINT UNSIGNED NOT NULL,
            current_mode VARCHAR(32) NOT NULL DEFAULT 'finance',
            effective_at DATETIME DEFAULT NULL,
            switched_at DATETIME DEFAULT NULL,
            switch_status VARCHAR(32) NOT NULL DEFAULT 'idle',
            switch_job_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (org_id),
            KEY current_mode (current_mode),
            KEY effective_at (effective_at)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$fiscalSettingsTable} (
            org_id BIGINT UNSIGNED NOT NULL,
            fiscal_year_start_month TINYINT UNSIGNED NOT NULL DEFAULT 1,
            timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
            active_period_id BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (org_id),
            KEY fiscal_year_start_month (fiscal_year_start_month)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$fiscalPeriodsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(128) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_period_unique (org_id, start_date, end_date),
            KEY org_status (org_id, status)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$modeSwitchJobsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            target_mode VARCHAR(32) NOT NULL,
            effective_at DATETIME NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            preflight_result_json LONGTEXT DEFAULT NULL,
            rollback_result_json LONGTEXT DEFAULT NULL,
            queue_job_id BIGINT UNSIGNED DEFAULT NULL,
            queue_job_code VARCHAR(64) DEFAULT NULL,
            started_at DATETIME DEFAULT NULL,
            finished_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_status_effective (org_id, status, effective_at),
            KEY queue_job_id (queue_job_id)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$accountsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            account_code VARCHAR(64) NOT NULL,
            account_name VARCHAR(191) NOT NULL,
            account_type VARCHAR(32) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_account_code (org_id, account_code),
            KEY org_type_active (org_id, account_type, is_active, sort_order)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$categoriesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            category_code VARCHAR(64) NOT NULL,
            category_name VARCHAR(191) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_category_code (org_id, category_code),
            KEY org_active_sort (org_id, is_active, sort_order)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$glEntriesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            entry_date DATE NOT NULL,
            account_code VARCHAR(64) NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount_signed DECIMAL(14,2) NOT NULL,
            amount_abs DECIMAL(14,2) NOT NULL,
            dc_type VARCHAR(16) NOT NULL,
            category_code VARCHAR(64) DEFAULT NULL,
            source_type VARCHAR(32) NOT NULL DEFAULT 'manual',
            reconciliation_status VARCHAR(32) NOT NULL DEFAULT 'unmatched',
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_entry_date_id (org_id, entry_date, id),
            KEY org_account_date (org_id, account_code, entry_date),
            KEY org_recon_status (org_id, reconciliation_status, entry_date),
            KEY org_source_date (org_id, source_type, entry_date)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$reconParseRunsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            import_type VARCHAR(16) NOT NULL,
            file_name VARCHAR(191) NOT NULL,
            confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            confidence_band VARCHAR(16) NOT NULL,
            mapping_json LONGTEXT DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_created (org_id, created_at),
            KEY org_band_status (org_id, confidence_band, status)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$reconMappingsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            import_type VARCHAR(16) NOT NULL,
            mapping_name VARCHAR(128) NOT NULL,
            mapping_json LONGTEXT NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_mapping_name (org_id, import_type, mapping_name),
            KEY org_import_default (org_id, import_type, is_default)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$reconReviewQueueTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            recon_parse_run_id BIGINT UNSIGNED NOT NULL,
            confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            confidence_band VARCHAR(16) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending_confirmation',
            decision VARCHAR(32) DEFAULT NULL,
            decision_notes VARCHAR(255) DEFAULT NULL,
            decided_by BIGINT UNSIGNED DEFAULT NULL,
            decided_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_status_created (org_id, status, created_at),
            KEY org_run (org_id, recon_parse_run_id)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$reconMonthsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            recon_month DATE NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'open',
            starting_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            statement_ending_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            expected_ending_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            difference_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            statement_file_name VARCHAR(191) DEFAULT NULL,
            statement_media_token VARCHAR(128) DEFAULT NULL,
            statement_media_url VARCHAR(255) DEFAULT NULL,
            finalized_at DATETIME DEFAULT NULL,
            finalized_by BIGINT UNSIGNED DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_month_unique (org_id, recon_month),
            KEY org_status_month (org_id, status, recon_month)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$reconMonthItemsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            recon_month_id BIGINT UNSIGNED NOT NULL,
            gl_entry_id BIGINT UNSIGNED NOT NULL,
            is_cleared TINYINT(1) NOT NULL DEFAULT 0,
            cleared_at DATETIME DEFAULT NULL,
            cleared_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_month_entry_unique (org_id, recon_month_id, gl_entry_id),
            KEY org_month_cleared (org_id, recon_month_id, is_cleared)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$reconMonthAuditTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            recon_month_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            reason_text VARCHAR(255) DEFAULT NULL,
            actor_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_month_event (org_id, recon_month_id, event_type, created_at)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$budgetVersionsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            version_label VARCHAR(128) NOT NULL,
            fiscal_year SMALLINT UNSIGNED NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            source_version_id BIGINT UNSIGNED DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_year_period (org_id, fiscal_year, period_start),
            KEY org_locked_created (org_id, is_locked, created_at)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$budgetLinesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            budget_version_id BIGINT UNSIGNED NOT NULL,
            account_code VARCHAR(64) NOT NULL,
            planned_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_version_account (org_id, budget_version_id, account_code),
            KEY org_version (org_id, budget_version_id)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$invoicesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            invoice_number VARCHAR(64) NOT NULL,
            customer_name VARCHAR(191) NOT NULL,
            customer_email VARCHAR(191) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'draft',
            currency VARCHAR(12) NOT NULL DEFAULT 'usd',
            issued_date DATE NOT NULL,
            due_date DATE NOT NULL,
            paid_date DATE DEFAULT NULL,
            subtotal_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            stripe_payment_intent_id VARCHAR(128) DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_invoice_number (org_id, invoice_number),
            KEY org_status_due (org_id, status, due_date),
            KEY org_issued (org_id, issued_date),
            KEY org_customer_email (org_id, customer_email)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$invoiceLinesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            invoice_id BIGINT UNSIGNED NOT NULL,
            description VARCHAR(255) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
            unit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_invoice_sort (org_id, invoice_id, sort_order),
            KEY org_invoice (org_id, invoice_id)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$reportRequestsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            report_type VARCHAR(64) NOT NULL,
            period_code VARCHAR(16) NOT NULL,
            orientation VARCHAR(16) NOT NULL DEFAULT 'landscape',
            include_prev_month TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'completed',
            payload_json LONGTEXT DEFAULT NULL,
            generated_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_report_created (org_id, report_type, created_at),
            KEY org_period_created (org_id, period_code, created_at)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$kpiCacheTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            cache_key VARCHAR(191) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_cache_key (org_id, cache_key),
            KEY org_expiry (org_id, expires_at)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$stripeEventsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            event_date DATE NOT NULL,
            reference_id VARCHAR(128) DEFAULT NULL,
            amount_signed DECIMAL(14,2) NOT NULL,
            currency VARCHAR(12) NOT NULL DEFAULT 'usd',
            description VARCHAR(255) DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_date_type (org_id, event_date, event_type),
            KEY org_ref (org_id, reference_id)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$stripePayoutsTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            payout_id VARCHAR(128) NOT NULL,
            payout_date DATE NOT NULL,
            expected_deposit_amount DECIMAL(14,2) NOT NULL,
            currency VARCHAR(12) NOT NULL DEFAULT 'usd',
            bank_account_label VARCHAR(191) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'expected',
            matched_bank_line_id BIGINT UNSIGNED DEFAULT NULL,
            matched_at DATETIME DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY org_payout_id (org_id, payout_id),
            KEY org_status_date (org_id, status, payout_date),
            KEY org_matched_line (org_id, matched_bank_line_id)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$bankLinesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            recon_parse_run_id BIGINT UNSIGNED DEFAULT NULL,
            source_type VARCHAR(32) NOT NULL DEFAULT 'manual',
            line_date DATE NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount_signed DECIMAL(14,2) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'unmatched',
            matched_payout_id BIGINT UNSIGNED DEFAULT NULL,
            matched_gl_entry_id BIGINT UNSIGNED DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_status_date (org_id, status, line_date),
            KEY org_amount_date (org_id, amount_signed, line_date),
            KEY org_recon_run (org_id, recon_parse_run_id),
            KEY org_gl_match (org_id, matched_gl_entry_id)
        ) {$charsetCollate};" );

        \metis_db_delta( "CREATE TABLE {$reconMatchesTable} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            org_id BIGINT UNSIGNED NOT NULL,
            match_type VARCHAR(32) NOT NULL,
            payout_record_id BIGINT UNSIGNED DEFAULT NULL,
            bank_line_id BIGINT UNSIGNED DEFAULT NULL,
            gl_entry_id BIGINT UNSIGNED DEFAULT NULL,
            match_amount DECIMAL(14,2) NOT NULL,
            confidence_score DECIMAL(5,2) NOT NULL DEFAULT 100,
            notes VARCHAR(255) DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY org_type_created (org_id, match_type, created_at),
            KEY org_payout (org_id, payout_record_id),
            KEY org_bank_line (org_id, bank_line_id),
            KEY org_gl_entry (org_id, gl_entry_id)
        ) {$charsetCollate};" );

        self::ensureOrgDefaults();
        self::ensureCatalogDefaults();
    }

    private static function ensureOrgDefaults(): void {
        $db = \metis_db();
        $orgModeTable = \Metis_Tables::get( 'finance_v2_org_mode' );
        $fiscalSettingsTable = \Metis_Tables::get( 'finance_v2_fiscal_settings' );

        $orgId = ModeService::orgId();

        $exists = (int) $db->scalar( "SELECT COUNT(*) FROM {$orgModeTable} WHERE org_id = %d", [ $orgId ] );
        if ( $exists < 1 ) {
            $db->insert(
                $orgModeTable,
                [
                    'org_id' => $orgId,
                    'current_mode' => ModeService::MODE_FINANCE,
                    'switch_status' => 'idle',
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%s', '%s', '%s', '%s' ]
            );
        }

        $settingsExists = (int) $db->scalar( "SELECT COUNT(*) FROM {$fiscalSettingsTable} WHERE org_id = %d", [ $orgId ] );
        if ( $settingsExists < 1 ) {
            $db->insert(
                $fiscalSettingsTable,
                [
                    'org_id' => $orgId,
                    'fiscal_year_start_month' => 1,
                    'timezone' => 'UTC',
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%d', '%s', '%s', '%s' ]
            );
        }
    }

    private static function ensureCatalogDefaults(): void {
        $db = \metis_db();
        $orgId = ModeService::orgId();
        $accountsTable = \Metis_Tables::get( 'finance_v2_accounts' );
        $categoriesTable = \Metis_Tables::get( 'finance_v2_categories' );

        $accountSeeds = [
            [ 'code' => 'operating_cash', 'name' => 'Operating Cash', 'type' => 'asset', 'sort' => 10 ],
            [ 'code' => 'stripe_clearing', 'name' => 'Stripe Clearing', 'type' => 'asset', 'sort' => 20 ],
            [ 'code' => 'donations_income', 'name' => 'Donations Income', 'type' => 'income', 'sort' => 30 ],
            [ 'code' => 'processing_fees', 'name' => 'Processing Fees', 'type' => 'expense', 'sort' => 40 ],
            [ 'code' => 'program_expense', 'name' => 'Program Expense', 'type' => 'expense', 'sort' => 50 ],
        ];

        foreach ( $accountSeeds as $seed ) {
            $code = metis_key_clean( (string) $seed['code'] );
            $exists = (int) $db->scalar(
                "SELECT COUNT(*) FROM {$accountsTable} WHERE org_id = %d AND account_code = %s",
                [ $orgId, $code ]
            );

            if ( $exists > 0 ) {
                continue;
            }

            $db->insert(
                $accountsTable,
                [
                    'org_id' => $orgId,
                    'account_code' => $code,
                    'account_name' => metis_text_clean( (string) $seed['name'] ),
                    'account_type' => metis_key_clean( (string) $seed['type'] ),
                    'is_active' => 1,
                    'sort_order' => (int) $seed['sort'],
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
            );
        }

        $categorySeeds = [
            [ 'code' => 'general', 'name' => 'General', 'sort' => 10 ],
            [ 'code' => 'donations', 'name' => 'Donations', 'sort' => 20 ],
            [ 'code' => 'fees', 'name' => 'Fees', 'sort' => 30 ],
            [ 'code' => 'operations', 'name' => 'Operations', 'sort' => 40 ],
        ];

        foreach ( $categorySeeds as $seed ) {
            $code = metis_key_clean( (string) $seed['code'] );
            $exists = (int) $db->scalar(
                "SELECT COUNT(*) FROM {$categoriesTable} WHERE org_id = %d AND category_code = %s",
                [ $orgId, $code ]
            );

            if ( $exists > 0 ) {
                continue;
            }

            $db->insert(
                $categoriesTable,
                [
                    'org_id' => $orgId,
                    'category_code' => $code,
                    'category_name' => metis_text_clean( (string) $seed['name'] ),
                    'is_active' => 1,
                    'sort_order' => (int) $seed['sort'],
                    'created_at' => Support::now(),
                    'updated_at' => Support::now(),
                ],
                [ '%d', '%s', '%s', '%d', '%d', '%s', '%s' ]
            );
        }
    }
}
