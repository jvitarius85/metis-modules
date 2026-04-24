<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

final class SchemaManager {
    private static bool $ready = false;

    public static function ensureSchema(): void {
        if ( self::$ready ) {
            return;
        }

        $db = \metis_db();
        $charset = $db->connection()->get_charset_collate();

        $forms = \Metis_Tables::get( 'forms' );
        $versions = \Metis_Tables::get( 'form_versions' );
        $submissions = \Metis_Tables::get( 'form_submissions' );
        $sessions = \Metis_Tables::get( 'form_payment_sessions' );

        \metis_db_delta(
            "CREATE TABLE {$forms} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                form_uuid VARCHAR(32) NOT NULL,
                slug VARCHAR(160) NOT NULL,
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
                KEY status_updated (status, updated_at)
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$versions} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                form_id BIGINT UNSIGNED NOT NULL,
                version_number INT UNSIGNED NOT NULL,
                schema_json LONGTEXT NOT NULL,
                checksum VARCHAR(64) NOT NULL,
                notes TEXT DEFAULT NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY form_version (form_id, version_number),
                KEY form_created (form_id, created_at),
                KEY form_published (form_id, is_published, created_at)
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$submissions} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                form_id BIGINT UNSIGNED NOT NULL,
                version_id BIGINT UNSIGNED DEFAULT NULL,
                submission_key VARCHAR(32) NOT NULL,
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
                KEY payment_lookup (payment_intent_id(191)),
                KEY email_lookup (submitter_email(191))
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$sessions} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_key VARCHAR(32) NOT NULL,
                form_id BIGINT UNSIGNED NOT NULL,
                payment_intent_id VARCHAR(191) NOT NULL,
                source_url VARCHAR(255) DEFAULT NULL,
                payload_json LONGTEXT DEFAULT NULL,
                normalized_json LONGTEXT DEFAULT NULL,
                totals_json LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY session_key (session_key),
                UNIQUE KEY payment_lookup (payment_intent_id(191)),
                KEY form_created (form_id, created_at)
            ) {$charset};"
        );

        if ( \function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->ensureSchema();
        }

        self::$ready = true;
    }
}
