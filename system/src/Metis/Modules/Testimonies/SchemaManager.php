<?php
declare(strict_types=1);

namespace Metis\Modules\Testimonies;

final class SchemaManager {
    private static bool $ready = false;

    public static function ensureSchema(): void {
        if ( self::$ready ) {
            return;
        }

        $db = \metis_db();
        $charset = $db->get_charset_collate();

        $testimonies = \Metis_Tables::get( 'testimonies' );
        $categories  = \Metis_Tables::get( 'testimony_categories' );
        $map         = \Metis_Tables::get( 'testimony_category_map' );

        \metis_db_delta(
            "CREATE TABLE {$testimonies} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                testimony_uid VARCHAR(32) NOT NULL,
                testimony_code VARCHAR(32) NOT NULL,
                speaker_name VARCHAR(191) NOT NULL,
                speaker_title VARCHAR(191) DEFAULT NULL,
                speaker_company VARCHAR(191) DEFAULT NULL,
                quote_text LONGTEXT NOT NULL,
                source_notes TEXT DEFAULT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'draft',
                is_featured TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY testimony_uid (testimony_uid),
                UNIQUE KEY testimony_code (testimony_code),
                KEY testimony_status_sort (status, is_featured, sort_order, updated_at),
                KEY testimony_speaker (speaker_name)
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$categories} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                testimony_category_uid VARCHAR(32) NOT NULL,
                category_code VARCHAR(32) NOT NULL,
                name VARCHAR(191) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY testimony_category_uid (testimony_category_uid),
                UNIQUE KEY category_code (category_code),
                UNIQUE KEY testimony_category_slug (slug),
                KEY testimony_category_active (is_active, sort_order, name)
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$map} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                testimony_id BIGINT UNSIGNED NOT NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY testimony_category_unique (testimony_id, category_id),
                KEY testimony_category_lookup (category_id, testimony_id),
                KEY testimony_lookup (testimony_id)
            ) {$charset};"
        );

        if ( \function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->ensureSchema();
        }

        self::$ready = true;
    }
}
