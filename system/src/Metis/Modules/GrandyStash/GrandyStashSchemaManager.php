<?php
declare(strict_types=1);

namespace Metis\Modules\GrandyStash;

final class GrandyStashSchemaManager {
    private static bool $done = false;

    public static function ensureSchema(): void {
        if ( self::$done ) {
            return;
        }

        $charset_collate = \metis_db()->connection()->get_charset_collate();

        // New tables must come first (tickets references facilities)
        self::createFacilitiesTable( $charset_collate );

        // Existing tables
        self::createGroupsTable( $charset_collate );
        self::createTicketsTable( $charset_collate );
        self::createTicketItemsTable( $charset_collate );
        self::createNotesTable( $charset_collate );
        self::createActivityTable( $charset_collate );
        self::createMessagesTable( $charset_collate );
        self::createEmailPrefsTable( $charset_collate );
        self::createCatalogTable( $charset_collate );

        // New inventory table (depends on catalog)
        self::createInventoryTable( $charset_collate );

        // Safe column migrations for existing installs
        self::migrateExistingTables();

        self::$done = true;
    }

    // ─── New tables ──────────────────────────────────────

    private static function createFacilitiesTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_facilities' );

        \metis_db_delta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(16) NOT NULL,
            name VARCHAR(191) NOT NULL,
            address TEXT DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            email VARCHAR(200) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_code (code),
            KEY idx_name (name(100)),
            KEY idx_active (is_active)
        ) {$charset_collate};" );
    }

    private static function createInventoryTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_inventory' );

        \metis_db_delta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            catalog_item_id BIGINT UNSIGNED NOT NULL,
            qty_available INT UNSIGNED NOT NULL DEFAULT 0,
            qty_pending INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_catalog_item (catalog_item_id),
            KEY idx_qty_available (qty_available)
        ) {$charset_collate};" );
    }

    // ─── Existing tables (unchanged) ─────────────────────

    private static function createGroupsTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_groups' );

        \metis_db_delta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(16) NOT NULL,
            name VARCHAR(200) NOT NULL,
            email VARCHAR(200) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            contact_cid VARCHAR(16) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_code (code),
            KEY idx_email (email),
            KEY idx_phone (phone),
            KEY idx_name (name(100)),
            KEY idx_contact_cid (contact_cid)
        ) {$charset_collate};" );
    }

    private static function createTicketsTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_tickets' );

        \metis_db_delta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(16) NOT NULL,
            group_id BIGINT UNSIGNED DEFAULT NULL,
            type VARCHAR(16) NOT NULL DEFAULT 'request',
            status VARCHAR(16) NOT NULL DEFAULT 'NEW',
            assigned_to BIGINT UNSIGNED DEFAULT NULL,
            assigned_name VARCHAR(191) DEFAULT NULL,
            source VARCHAR(50) DEFAULT 'web',
            urgency VARCHAR(16) NOT NULL DEFAULT 'standard',
            pickup_delivery VARCHAR(16) DEFAULT NULL,
            submit_name VARCHAR(200) NOT NULL,
            submit_email VARCHAR(200) DEFAULT NULL,
            submit_phone VARCHAR(30) DEFAULT NULL,
            submit_address TEXT DEFAULT NULL,
            submit_notes TEXT DEFAULT NULL,
            form_id BIGINT UNSIGNED DEFAULT NULL,
            form_submission_id BIGINT UNSIGNED DEFAULT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            closed_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_code (code),
            UNIQUE KEY uk_form_submission (form_submission_id),
            KEY idx_group (group_id),
            KEY idx_status (status),
            KEY idx_type (type),
            KEY idx_assigned (assigned_to),
            KEY idx_submitted (submitted_at)
        ) {$charset_collate};" );
    }

    private static function createTicketItemsTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_ticket_items' );

        \metis_db_delta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            catalog_item_id BIGINT UNSIGNED DEFAULT NULL,
            category VARCHAR(120) NOT NULL,
            item_name VARCHAR(255) DEFAULT NULL,
            description TEXT DEFAULT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            condition_status VARCHAR(32) DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            waitlist_at DATETIME DEFAULT NULL,
            fulfilled_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_ticket (ticket_id),
            KEY idx_category (category),
            KEY idx_status (status),
            KEY idx_waitlist (waitlist_at)
        ) {$charset_collate};" );
    }

    private static function createNotesTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_notes' );

        \metis_db_delta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            content TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ticket (ticket_id)
        ) {$charset_collate};" );
    }

    private static function createActivityTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_activity' );

        \metis_db_delta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            action VARCHAR(50) NOT NULL,
            detail TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ticket (ticket_id),
            KEY idx_action (action),
            KEY idx_created (created_at)
        ) {$charset_collate};" );
    }

    private static function createMessagesTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_messages' );

        \metis_db_delta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            inbound_message_id BIGINT UNSIGNED DEFAULT NULL,
            provider_message_id VARCHAR(191) DEFAULT NULL,
            provider_thread_id VARCHAR(191) DEFAULT NULL,
            mailbox_email VARCHAR(191) DEFAULT NULL,
            direction VARCHAR(24) NOT NULL DEFAULT 'inbound',
            subject VARCHAR(255) DEFAULT NULL,
            sender_email VARCHAR(191) DEFAULT NULL,
            sender_name VARCHAR(191) DEFAULT NULL,
            sender_user_id BIGINT UNSIGNED DEFAULT NULL,
            recipient_email VARCHAR(191) DEFAULT NULL,
            recipients_json LONGTEXT DEFAULT NULL,
            attachments_json LONGTEXT DEFAULT NULL,
            rfc_message_id VARCHAR(191) DEFAULT NULL,
            in_reply_to VARCHAR(191) DEFAULT NULL,
            references_header LONGTEXT DEFAULT NULL,
            text_body LONGTEXT DEFAULT NULL,
            html_body LONGTEXT DEFAULT NULL,
            delivery_status VARCHAR(24) NOT NULL DEFAULT 'received',
            error_message TEXT DEFAULT NULL,
            message_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            received_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY inbound_message_id (inbound_message_id),
            KEY ticket_received (ticket_id, received_at),
            KEY ticket_message_at (ticket_id, message_at),
            KEY provider_message_id (provider_message_id),
            KEY provider_thread_id (provider_thread_id),
            KEY rfc_message_id (rfc_message_id),
            KEY sender_email (sender_email),
            KEY mailbox_email (mailbox_email),
            KEY direction_delivery_status (direction, delivery_status)
        ) {$charset_collate};" );
    }

    private static function createEmailPrefsTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_email_prefs' );

        \metis_db_delta( "CREATE TABLE {$table} (
            user_id BIGINT UNSIGNED NOT NULL,
            receive_grandys_summary TINYINT(1) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) {$charset_collate};" );
    }

    private static function createCatalogTable( string $charset_collate ): void {
        $table = \Metis_Tables::get( 'grandys_stash_catalog' );

        \metis_db_delta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            catalog_code VARCHAR(16) NOT NULL,
            item_name VARCHAR(191) NOT NULL,
            item_slug VARCHAR(191) NOT NULL,
            category_name VARCHAR(120) NOT NULL,
            category_slug VARCHAR(120) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY catalog_code (catalog_code),
            UNIQUE KEY item_slug (item_slug),
            KEY category_slug (category_slug),
            KEY is_active_sort (is_active, sort_order)
        ) {$charset_collate};" );
    }

    // ─── Migrations (safe for existing installs) ─────────

    private static function migrateExistingTables(): void {
        // grandys_stash_groups: add facility_id
        $groups = \Metis_Tables::get( 'grandys_stash_groups' );
        self::addColumnIfMissing( $groups, 'facility_id', 'BIGINT UNSIGNED DEFAULT NULL' );
        self::addIndexIfMissing( $groups, 'idx_facility', 'KEY idx_facility (facility_id)' );

        // grandys_stash_tickets: add facility_id + facility_name
        $tickets = \Metis_Tables::get( 'grandys_stash_tickets' );
        self::addColumnIfMissing( $tickets, 'facility_id', 'BIGINT UNSIGNED DEFAULT NULL' );
        self::addColumnIfMissing( $tickets, 'facility_name', 'VARCHAR(191) DEFAULT NULL' );
        self::addIndexIfMissing( $tickets, 'idx_facility', 'KEY idx_facility (facility_id)' );

        // grandys_stash_ticket_items: add waitlist_position
        $items = \Metis_Tables::get( 'grandys_stash_ticket_items' );
        self::addColumnIfMissing( $items, 'waitlist_position', 'INT UNSIGNED DEFAULT NULL' );

        // grandys_stash_messages: conversation metadata for two-way email threads
        $messages = \Metis_Tables::get( 'grandys_stash_messages' );
        self::addColumnIfMissing( $messages, 'mailbox_email', 'VARCHAR(191) DEFAULT NULL' );
        self::addColumnIfMissing( $messages, 'sender_user_id', 'BIGINT UNSIGNED DEFAULT NULL' );
        self::addColumnIfMissing( $messages, 'recipient_email', 'VARCHAR(191) DEFAULT NULL' );
        self::addColumnIfMissing( $messages, 'rfc_message_id', 'VARCHAR(191) DEFAULT NULL' );
        self::addColumnIfMissing( $messages, 'in_reply_to', 'VARCHAR(191) DEFAULT NULL' );
        self::addColumnIfMissing( $messages, 'references_header', 'LONGTEXT DEFAULT NULL' );
        self::addColumnIfMissing( $messages, 'delivery_status', "VARCHAR(24) NOT NULL DEFAULT 'received'" );
        self::addColumnIfMissing( $messages, 'error_message', 'TEXT DEFAULT NULL' );
        self::addColumnIfMissing( $messages, 'message_at', 'DATETIME DEFAULT NULL' );
        self::addColumnIfMissing( $messages, 'sent_at', 'DATETIME DEFAULT NULL' );
        self::addIndexIfMissing( $messages, 'ticket_message_at', 'KEY ticket_message_at (ticket_id, message_at)' );
        self::addIndexIfMissing( $messages, 'rfc_message_id', 'KEY rfc_message_id (rfc_message_id)' );
        self::addIndexIfMissing( $messages, 'sender_email', 'KEY sender_email (sender_email)' );
        self::addIndexIfMissing( $messages, 'mailbox_email', 'KEY mailbox_email (mailbox_email)' );
        self::addIndexIfMissing( $messages, 'direction_delivery_status', 'KEY direction_delivery_status (direction, delivery_status)' );
    }

    // ─── Schema helpers ───────────────────────────────────

    private static function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }

    private static function addColumnIfMissing( string $table, string $column, string $definition ): void {
        $db     = self::db();
        $exists = (int) $db->scalar(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = %s
               AND COLUMN_NAME  = %s',
            [ $table, $column ]
        );
        if ( $exists === 0 ) {
            $db->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" );
        }
    }

    private static function addIndexIfMissing( string $table, string $index_name, string $definition ): void {
        $db     = self::db();
        $exists = (int) $db->scalar(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = %s
               AND INDEX_NAME   = %s',
            [ $table, $index_name ]
        );
        if ( $exists === 0 ) {
            $db->query( "ALTER TABLE `{$table}` ADD {$definition}" );
        }
    }
}

\class_alias( __NAMESPACE__ . '\\GrandyStashSchemaManager', 'Metis\\Modules\\GrandyStashSchemaManager' );
