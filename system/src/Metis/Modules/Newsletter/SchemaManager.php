<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class SchemaManager {
    private static bool $schema_ready = false;

    public static function tableExists( string $table ): bool {
        $db = self::db();
        $exists = $db->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
        return $exists === $table;
    }

    public static function columnExists( string $table, string $column ): bool {
        $db = self::db();
        if ( ! self::tableExists( $table ) ) {
            return false;
        }
        $exists = $db->scalar( "SHOW COLUMNS FROM {$table} LIKE %s", [ $column ] );
        return ! empty( $exists );
    }

    public static function addColumnIfMissing( string $table, string $column, string $definition ): void {
        $db = self::db();
        if ( ! self::tableExists( $table ) ) {
            return;
        }
        if ( self::columnExists( $table, $column ) ) {
            return;
        }
        $db->execute( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
    }

    public static function addIndexIfMissing( string $table, string $index_name, string $index_def ): void {
        $db = self::db();
        if ( ! self::tableExists( $table ) ) {
            return;
        }
        $exists = $db->scalar( "SHOW INDEX FROM {$table} WHERE Key_name = %s", [ $index_name ] );
        if ( $exists !== null ) {
            return;
        }
        $db->execute( "ALTER TABLE {$table} ADD {$index_def}" );
    }

    public static function ensureSchema(): void {
        if ( self::$schema_ready ) {
            return;
        }

        $db = self::db();

        $charset_collate   = $db->get_charset_collate();
        $lists_table       = \Metis_Tables::get( 'newsletter_lists' );
        $subs_table        = \Metis_Tables::get( 'newsletter_subs' );
        $templates_table   = \Metis_Tables::get( 'newsletter_templates' );
        $campaigns_table   = \Metis_Tables::get( 'newsletter_campaigns' );
        $campaign_lists    = \Metis_Tables::get( 'newsletter_campaign_lists' );
        $messages_table    = \Metis_Tables::get( 'newsletter_messages' );
        $events_table      = \Metis_Tables::get( 'newsletter_events' );
        $revisions_table   = \Metis_Tables::get( 'newsletter_revisions' );
        $audit_table       = \Metis_Tables::get( 'newsletter_audit' );
        $suppressions      = \Metis_Tables::get( 'newsletter_suppressions' );
        $usage_table       = \Metis_Tables::get( 'newsletter_google_usage_daily' );

        \metis_db_delta( "CREATE TABLE {$lists_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            list_key VARCHAR(32) DEFAULT NULL,
            legacy_lid VARCHAR(50) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY list_key (list_key),
            UNIQUE KEY legacy_lid (legacy_lid)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$subs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            list_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'subscribed',
            source VARCHAR(40) DEFAULT NULL,
            subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            unsubscribed_at DATETIME DEFAULT NULL,
            bounce_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_event_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY combo (contact_id, list_id),
            KEY list_id (list_id),
            KEY status (status)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$templates_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_code VARCHAR(16) NOT NULL,
            name VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            from_name VARCHAR(191) DEFAULT NULL,
            from_email VARCHAR(191) DEFAULT NULL,
            reply_to VARCHAR(191) DEFAULT NULL,
            doc_json LONGTEXT DEFAULT NULL,
            html_body LONGTEXT NOT NULL,
            text_body LONGTEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY template_code (template_code),
            KEY is_active (is_active)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$campaigns_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_code VARCHAR(16) NOT NULL,
            campaign_type VARCHAR(32) NOT NULL DEFAULT 'campaign',
            template_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            from_name VARCHAR(191) DEFAULT NULL,
            from_email VARCHAR(191) DEFAULT NULL,
            reply_to VARCHAR(191) DEFAULT NULL,
            preheader VARCHAR(255) DEFAULT NULL,
            doc_json LONGTEXT DEFAULT NULL,
            editor_body_html LONGTEXT DEFAULT NULL,
            html_body LONGTEXT DEFAULT NULL,
            text_body LONGTEXT DEFAULT NULL,
            audience_json LONGTEXT DEFAULT NULL,
            attachments_json LONGTEXT DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            test_sent_at DATETIME DEFAULT NULL,
            scheduled_at DATETIME DEFAULT NULL,
            queued_at DATETIME DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            bounced_count INT UNSIGNED NOT NULL DEFAULT 0,
            rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_code (campaign_code),
            KEY campaign_type (campaign_type),
            KEY status (status),
            KEY scheduled_at (scheduled_at)
        ) {$charset_collate};" );

        self::addColumnIfMissing( $campaigns_table, 'campaign_type', "VARCHAR(32) NOT NULL DEFAULT 'campaign'" );
        self::addColumnIfMissing( $campaigns_table, 'doc_json', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $campaigns_table, 'editor_body_html', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $campaigns_table, 'html_body', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $campaigns_table, 'text_body', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $campaigns_table, 'audience_json', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $campaigns_table, 'attachments_json', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $campaigns_table, 'test_sent_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $campaigns_table, 'archived_at', 'DATETIME DEFAULT NULL' );
        self::addIndexIfMissing( $campaigns_table, 'campaign_type', 'KEY campaign_type (campaign_type)' );
        self::addIndexIfMissing( $campaigns_table, 'status_scheduled', 'KEY status_scheduled (status, scheduled_at)' );
        self::addColumnIfMissing( $templates_table, 'doc_json', 'LONGTEXT DEFAULT NULL' );

        \metis_db_delta( "CREATE TABLE {$campaign_lists} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id BIGINT UNSIGNED NOT NULL,
            list_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_list (campaign_id, list_id),
            KEY list_id (list_id)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$messages_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_code VARCHAR(16) NOT NULL,
            campaign_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(191) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'queued',
            provider VARCHAR(40) DEFAULT 'gmail_api',
            provider_message_id VARCHAR(191) DEFAULT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            queued_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            delivered_at DATETIME DEFAULT NULL,
            bounced_at DATETIME DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            opened_at DATETIME DEFAULT NULL,
            clicked_at DATETIME DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY message_code (message_code),
            UNIQUE KEY campaign_contact (campaign_id, contact_id),
            KEY status (status),
            KEY email (email)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_code VARCHAR(16) NOT NULL,
            message_id BIGINT UNSIGNED DEFAULT NULL,
            campaign_id BIGINT UNSIGNED DEFAULT NULL,
            contact_id BIGINT UNSIGNED DEFAULT NULL,
            email VARCHAR(191) DEFAULT NULL,
            event_type VARCHAR(32) NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            source VARCHAR(40) DEFAULT NULL,
            event_at DATETIME DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY event_code (event_code),
            KEY message_id (message_id),
            KEY campaign_id (campaign_id),
            KEY event_type (event_type),
            KEY email (email)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$revisions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            revision_code VARCHAR(16) NOT NULL,
            entity_type VARCHAR(24) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            summary VARCHAR(255) DEFAULT NULL,
            doc_json LONGTEXT DEFAULT NULL,
            html_body LONGTEXT DEFAULT NULL,
            text_body LONGTEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY revision_code (revision_code),
            KEY entity_ref (entity_type, entity_id),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$audit_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            audit_code VARCHAR(16) NOT NULL,
            action VARCHAR(40) NOT NULL,
            entity_type VARCHAR(24) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            meta_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY audit_code (audit_code),
            KEY entity_action (entity_type, entity_id, action),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$suppressions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            suppression_code VARCHAR(16) NOT NULL,
            contact_id BIGINT UNSIGNED DEFAULT NULL,
            email VARCHAR(191) NOT NULL,
            reason VARCHAR(64) DEFAULT NULL,
            source VARCHAR(40) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY suppression_code (suppression_code),
            KEY email_active (email, is_active),
            KEY contact_active (contact_id, is_active)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$usage_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            usage_date DATE NOT NULL,
            workspace_email VARCHAR(191) NOT NULL,
            sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(40) DEFAULT 'google_reports',
            workspace_user_id BIGINT UNSIGNED DEFAULT NULL,
            person_id BIGINT UNSIGNED DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY usage_email_date (usage_date, workspace_email),
            KEY usage_date (usage_date),
            KEY sent_count (sent_count),
            KEY workspace_user_id (workspace_user_id),
            KEY person_id (person_id)
        ) {$charset_collate};" );

        self::addColumnIfMissing( $lists_table, 'list_key', 'VARCHAR(32) DEFAULT NULL' );
        self::addColumnIfMissing( $lists_table, 'newsletter_list_uid', 'VARCHAR(16) DEFAULT NULL' );
        self::addColumnIfMissing( $lists_table, 'description', 'TEXT DEFAULT NULL' );
        self::addColumnIfMissing( $lists_table, 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1' );
        self::addColumnIfMissing( $lists_table, 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' );
        self::addColumnIfMissing( $lists_table, 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' );
        self::addIndexIfMissing( $lists_table, 'list_key', 'UNIQUE KEY list_key (list_key)' );

        self::addColumnIfMissing( $subs_table, 'status', "VARCHAR(24) NOT NULL DEFAULT 'subscribed'" );
        self::addColumnIfMissing( $subs_table, 'source', 'VARCHAR(40) DEFAULT NULL' );
        self::addColumnIfMissing( $subs_table, 'subscribed_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' );
        self::addColumnIfMissing( $subs_table, 'unsubscribed_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $subs_table, 'bounce_count', 'INT UNSIGNED NOT NULL DEFAULT 0' );
        self::addColumnIfMissing( $subs_table, 'last_event_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $subs_table, 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' );
        self::addColumnIfMissing( $subs_table, 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' );
        self::addIndexIfMissing( $subs_table, 'combo', 'UNIQUE KEY combo (contact_id, list_id)' );
        self::addIndexIfMissing( $subs_table, 'list_id', 'KEY list_id (list_id)' );
        self::addIndexIfMissing( $subs_table, 'status', 'KEY status (status)' );

        $has_lists = (int) $db->scalar( "SELECT COUNT(*) FROM {$lists_table}" );
        if ( $has_lists < 1 ) {
            $db->insert(
                $lists_table,
                [
                    'list_key'     => 'NL_MAIN',
                    'name'         => 'Newsletter',
                    'description'  => 'Primary newsletter audience',
                    'is_active'    => 1,
                ],
                [ '%s', '%s', '%s', '%d' ]
            );
        }

        if ( function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->ensureSchema();
        }
    }

    private static function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }
}
