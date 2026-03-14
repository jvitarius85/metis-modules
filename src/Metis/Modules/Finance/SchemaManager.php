<?php
declare(strict_types=1);

namespace Metis\Modules\Finance;

final class SchemaManager {
    private static bool $schema_ready = false;
    private static ?array $account_map = null;

    public static function ensureSchema(): void {
        if ( self::$schema_ready ) {
            return;
        }

        self::$schema_ready = true;

        global $wpdb;
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate  = $wpdb->get_charset_collate();
        $accounts_table   = \Metis_Tables::get( 'finance_accounts' );
        $events_table     = \Metis_Tables::get( 'finance_events' );
        $funds_table      = \Metis_Tables::get( 'finance_funds' );
        $tags_table       = \Metis_Tables::get( 'finance_tags' );
        $event_tags_table = \Metis_Tables::get( 'finance_event_tags' );
        $ledger_table     = \Metis_Tables::get( 'finance_ledger' );
        $recons_table     = \Metis_Tables::get( 'finance_reconciliations' );

        $accounts_sql = "CREATE TABLE {$accounts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_key VARCHAR(64) NOT NULL,
            label VARCHAR(191) NOT NULL,
            category VARCHAR(64) NOT NULL,
            normal_balance VARCHAR(16) NOT NULL DEFAULT 'debit',
            is_system TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY account_key (account_key),
            KEY category (category),
            KEY is_active (is_active)
        ) {$charset_collate};";

        $funds_sql = "CREATE TABLE {$funds_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fund_key VARCHAR(64) NOT NULL,
            fund_name VARCHAR(191) NOT NULL,
            restriction_type VARCHAR(32) NOT NULL DEFAULT 'unrestricted',
            description TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY fund_key (fund_key),
            KEY restriction_type (restriction_type)
        ) {$charset_collate};";

        $events_sql = "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(64) NOT NULL,
            provider VARCHAR(64) DEFAULT NULL,
            reference_id VARCHAR(128) NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'usd',
            fund_id BIGINT UNSIGNED NOT NULL,
            campaign_id BIGINT UNSIGNED DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            occurred_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_ref (event_type, provider, reference_id),
            KEY occurred_at (occurred_at),
            KEY fund_id (fund_id),
            KEY campaign_id (campaign_id),
            KEY provider (provider)
        ) {$charset_collate};";

        $tags_sql = "CREATE TABLE {$tags_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tag_key VARCHAR(64) NOT NULL,
            tag_name VARCHAR(191) NOT NULL,
            tag_type VARCHAR(32) NOT NULL DEFAULT 'program',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tag_key (tag_key),
            KEY tag_type (tag_type)
        ) {$charset_collate};";

        $event_tags_sql = "CREATE TABLE {$event_tags_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_tag (event_id, tag_id),
            KEY tag_id (tag_id)
        ) {$charset_collate};";

        $ledger_sql = "CREATE TABLE {$ledger_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_date DATE NOT NULL,
            account_key VARCHAR(64) NOT NULL,
            source_type VARCHAR(64) NOT NULL,
            source_ref VARCHAR(128) NOT NULL,
            direction VARCHAR(16) NOT NULL DEFAULT 'inflow',
            entry_side VARCHAR(16) DEFAULT NULL,
            contra_account_key VARCHAR(64) DEFAULT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            fund_id BIGINT UNSIGNED DEFAULT NULL,
            campaign_id BIGINT UNSIGNED DEFAULT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            memo VARCHAR(255) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'posted',
            meta LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_account (account_key, source_type, source_ref, direction),
            KEY entry_date (entry_date),
            KEY status (status),
            KEY account_key (account_key),
            KEY event_id (event_id),
            KEY fund_id (fund_id)
        ) {$charset_collate};";

        $recons_sql = "CREATE TABLE {$recons_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_key VARCHAR(64) NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            book_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            statement_balance DECIMAL(14,2) DEFAULT NULL,
            variance DECIMAL(14,2) DEFAULT NULL,
            matched_count INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'open',
            notes LONGTEXT DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY account_period (account_key, period_start, period_end),
            KEY status (status)
        ) {$charset_collate};";

        \dbDelta( $accounts_sql );
        \dbDelta( $funds_sql );
        \dbDelta( $events_sql );
        \dbDelta( $tags_sql );
        \dbDelta( $event_tags_sql );
        \dbDelta( $ledger_sql );
        \dbDelta( $recons_sql );

        self::seedAccounts();
        self::seedFunds();
    }

    public static function seedAccounts(): void {
        global $wpdb;

        $accounts_table = \Metis_Tables::get( 'finance_accounts' );
        if ( ! Support::tableExists( $accounts_table ) ) {
            return;
        }

        foreach ( Support::systemAccountSeed() as $row ) {
            $wpdb->replace(
                $accounts_table,
                [
                    'account_key'    => $row['account_key'],
                    'label'          => $row['label'],
                    'category'       => $row['category'],
                    'normal_balance' => $row['normal_balance'],
                    'is_system'      => 1,
                    'is_active'      => 1,
                    'updated_at'     => \current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s', '%s', '%d', '%d', '%s' ]
            );
        }

        self::$account_map = null;
    }

    public static function seedFunds(): void {
        global $wpdb;

        $funds_table = \Metis_Tables::get( 'finance_funds' );
        if ( ! Support::tableExists( $funds_table ) ) {
            return;
        }

        $wpdb->replace(
            $funds_table,
            [
                'fund_key'         => 'general',
                'fund_name'        => 'General Fund',
                'restriction_type' => 'unrestricted',
                'description'      => 'Default unrestricted operating fund.',
                'updated_at'       => \current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public static function defaultFundId(): int {
        self::ensureSchema();

        global $wpdb;
        $funds_table = \Metis_Tables::get( 'finance_funds' );
        $fund_id     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$funds_table} WHERE fund_key = %s LIMIT 1", 'general' ) );

        if ( $fund_id > 0 ) {
            return $fund_id;
        }

        self::seedFunds();

        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$funds_table} WHERE fund_key = %s LIMIT 1", 'general' ) );
    }

    public static function findFundId( array $data ): int {
        global $wpdb;

        $fund_id = (int) ( $data['fund_id'] ?? 0 );
        if ( $fund_id > 0 ) {
            return $fund_id;
        }

        $fund_key = \sanitize_key( (string) ( $data['fund_key'] ?? '' ) );
        if ( $fund_key !== '' ) {
            $funds_table = \Metis_Tables::get( 'finance_funds' );
            $found       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$funds_table} WHERE fund_key = %s LIMIT 1", $fund_key ) );
            if ( $found > 0 ) {
                return $found;
            }
        }

        return self::defaultFundId();
    }

    public static function resolveCampaignId( array $data ): ?int {
        global $wpdb;

        $campaign_id = isset( $data['campaign_id'] ) ? (int) $data['campaign_id'] : 0;
        if ( $campaign_id > 0 ) {
            return $campaign_id;
        }

        $campaign_code = \sanitize_text_field( (string) ( $data['campaign_code'] ?? '' ) );
        if ( $campaign_code === '' || ! \Metis_Tables::has( 'campaigns' ) ) {
            return null;
        }

        $campaigns_table = \Metis_Tables::get( 'campaigns' );
        if ( ! Support::tableExists( $campaigns_table ) ) {
            return null;
        }

        $found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$campaigns_table} WHERE cid = %s LIMIT 1", $campaign_code ) );
        return $found > 0 ? $found : null;
    }

    public static function accountMap(): array {
        if ( is_array( self::$account_map ) ) {
            return self::$account_map;
        }

        self::ensureSchema();

        global $wpdb;
        $accounts_table = \Metis_Tables::get( 'finance_accounts' );
        $rows           = $wpdb->get_results(
            "SELECT account_key, category, normal_balance, label
             FROM {$accounts_table}
             WHERE is_active = 1",
            ARRAY_A
        ) ?: [];

        self::$account_map = [];
        foreach ( $rows as $row ) {
            self::$account_map[ (string) $row['account_key'] ] = $row;
        }

        return self::$account_map;
    }
}
