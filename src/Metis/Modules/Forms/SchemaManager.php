<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

final class SchemaManager {
    private static bool $done = false;

    public static function ensureSchema(): void {
        if ( self::$done ) {
            return;
        }

        global $wpdb;

        $forms_table = \Metis_Tables::get( 'forms' );
        $versions_table = \Metis_Tables::get( 'form_versions' );
        $submissions_table = \Metis_Tables::get( 'form_submissions' );
        $charset_collate = $wpdb->get_charset_collate();

        if ( ! \function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        \dbDelta( "CREATE TABLE {$forms_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_uuid VARCHAR(16) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            latest_version_id BIGINT UNSIGNED DEFAULT NULL,
            published_version_id BIGINT UNSIGNED DEFAULT NULL,
            payment_enabled TINYINT(1) NOT NULL DEFAULT 0,
            settings_json LONGTEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY form_uuid (form_uuid),
            UNIQUE KEY slug (slug),
            KEY status (status)
        ) {$charset_collate};" );

        \dbDelta( "CREATE TABLE {$versions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            version_number INT UNSIGNED NOT NULL DEFAULT 1,
            schema_json LONGTEXT NOT NULL,
            checksum VARCHAR(64) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY form_version (form_id, version_number),
            KEY form_published (form_id, is_published)
        ) {$charset_collate};" );

        \dbDelta( "CREATE TABLE {$submissions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id BIGINT UNSIGNED NOT NULL,
            version_id BIGINT UNSIGNED DEFAULT NULL,
            submission_key VARCHAR(16) NOT NULL,
            submission_status VARCHAR(24) NOT NULL DEFAULT 'submitted',
            payment_status VARCHAR(24) NOT NULL DEFAULT 'not_required',
            payment_intent_id VARCHAR(191) DEFAULT NULL,
            amount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(8) NOT NULL DEFAULT 'usd',
            submitter_email VARCHAR(191) DEFAULT NULL,
            source_url VARCHAR(255) DEFAULT NULL,
            payload_json LONGTEXT NOT NULL,
            normalized_json LONGTEXT DEFAULT NULL,
            totals_json LONGTEXT DEFAULT NULL,
            automation_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY submission_key (submission_key),
            KEY form_created (form_id, created_at),
            KEY payment_intent_id (payment_intent_id(191))
        ) {$charset_collate};" );

        self::$done = true;
    }
}
