<?php
declare(strict_types=1);

namespace Metis\Modules;

final class GrandyStashSchemaManager {
    private static bool $done = false;

    public static function ensureSchema(): void {
        if ( self::$done ) {
            return;
        }

        global $wpdb;

        $catalog_table = \Metis_Tables::get( 'grandys_stash_catalog' );
        $items_table = \Metis_Tables::get( 'grandys_stash_items' );
        $cases_table = \Metis_Tables::get( 'grandys_stash_cases' );
        $distributions_table = \Metis_Tables::get( 'grandys_stash_distributions' );
        $charset_collate = $wpdb->get_charset_collate();

        if ( ! \function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        \dbDelta( "CREATE TABLE {$catalog_table} (
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

        \dbDelta( "CREATE TABLE {$items_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            equipment_code VARCHAR(16) NOT NULL,
            name VARCHAR(191) NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT 'mobility_aids',
            condition_status VARCHAR(32) NOT NULL DEFAULT 'good',
            status VARCHAR(32) NOT NULL DEFAULT 'available',
            storage_location VARCHAR(191) DEFAULT NULL,
            serial_number VARCHAR(120) DEFAULT NULL,
            donor_contact_cid VARCHAR(16) DEFAULT NULL,
            source_case_id BIGINT UNSIGNED DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY equipment_code (equipment_code),
            KEY category_status (category, status),
            KEY donor_contact_cid (donor_contact_cid)
        ) {$charset_collate};" );

        \dbDelta( "CREATE TABLE {$cases_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            case_code VARCHAR(16) NOT NULL,
            intake_type VARCHAR(24) NOT NULL DEFAULT 'request',
            status VARCHAR(24) NOT NULL DEFAULT 'new',
            contact_cid VARCHAR(16) DEFAULT NULL,
            assignee_user_id BIGINT UNSIGNED DEFAULT NULL,
            assignee_name VARCHAR(191) DEFAULT NULL,
            urgency VARCHAR(24) NOT NULL DEFAULT 'standard',
            pickup_delivery VARCHAR(24) DEFAULT NULL,
            requested_categories_json LONGTEXT DEFAULT NULL,
            requested_items_json LONGTEXT DEFAULT NULL,
            offered_items_json LONGTEXT DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            internal_notes TEXT DEFAULT NULL,
            scheduled_for DATETIME DEFAULT NULL,
            form_id BIGINT UNSIGNED DEFAULT NULL,
            form_submission_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY case_code (case_code),
            UNIQUE KEY form_submission_id (form_submission_id),
            KEY intake_status (intake_type, status),
            KEY contact_cid (contact_cid),
            KEY assignee_user_id (assignee_user_id)
        ) {$charset_collate};" );

        \dbDelta( "CREATE TABLE {$distributions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            distribution_code VARCHAR(16) NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            case_id BIGINT UNSIGNED DEFAULT NULL,
            recipient_cid VARCHAR(16) DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'assigned',
            fulfillment_method VARCHAR(24) DEFAULT NULL,
            scheduled_for DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY distribution_code (distribution_code),
            KEY item_id (item_id),
            KEY case_id (case_id),
            KEY recipient_cid (recipient_cid),
            KEY status (status)
        ) {$charset_collate};" );

        self::$done = true;
    }
}
