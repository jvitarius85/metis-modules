<?php
declare(strict_types=1);

namespace Metis\Modules\Resources;

final class SchemaManager {
    private static bool $ready = false;

    public static function ensureSchema(): void {
        if ( self::$ready ) {
            return;
        }

        $db = \metis_db();
        $charset = $db->get_charset_collate();
        $types = \Metis_Tables::get( 'resource_types' );
        $categories = \Metis_Tables::get( 'resource_categories' );
        $tags = \Metis_Tables::get( 'resource_tags' );
        $resources = \Metis_Tables::get( 'resources' );
        $resource_categories = \Metis_Tables::get( 'resource_category_map' );
        $resource_tags = \Metis_Tables::get( 'resource_tag_map' );

        \metis_db_delta(
            "CREATE TABLE {$types} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                resource_type_uid VARCHAR(32) NOT NULL,
                resource_type_code VARCHAR(32) NOT NULL,
                name VARCHAR(191) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                intro_html LONGTEXT DEFAULT NULL,
                seo_title VARCHAR(191) DEFAULT NULL,
                seo_description TEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY resource_type_uid (resource_type_uid),
                UNIQUE KEY resource_type_code (resource_type_code),
                UNIQUE KEY resource_type_slug (slug),
                KEY resource_type_sort (is_active, sort_order, name)
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$categories} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                resource_category_uid VARCHAR(32) NOT NULL,
                resource_category_code VARCHAR(32) NOT NULL,
                resource_type_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(191) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                intro_html LONGTEXT DEFAULT NULL,
                seo_title VARCHAR(191) DEFAULT NULL,
                seo_description TEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY resource_category_uid (resource_category_uid),
                UNIQUE KEY resource_category_code (resource_category_code),
                UNIQUE KEY resource_type_slug (resource_type_id, slug),
                KEY resource_category_sort (resource_type_id, is_active, sort_order, name)
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$tags} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                resource_tag_uid VARCHAR(32) NOT NULL,
                resource_tag_code VARCHAR(32) NOT NULL,
                resource_type_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(191) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED DEFAULT NULL,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY resource_tag_uid (resource_tag_uid),
                UNIQUE KEY resource_tag_code (resource_tag_code),
                UNIQUE KEY resource_type_tag_slug (resource_type_id, slug),
                KEY resource_tag_sort (resource_type_id, is_active, sort_order, name)
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$resources} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                resource_uid VARCHAR(32) NOT NULL,
                resource_code VARCHAR(32) NOT NULL,
                resource_type_id BIGINT UNSIGNED NOT NULL,
                primary_category_id BIGINT UNSIGNED DEFAULT NULL,
                title VARCHAR(191) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                organization_name VARCHAR(191) DEFAULT NULL,
                summary TEXT DEFAULT NULL,
                description_html LONGTEXT DEFAULT NULL,
                website_url VARCHAR(255) DEFAULT NULL,
                phone VARCHAR(80) DEFAULT NULL,
                email VARCHAR(191) DEFAULT NULL,
                logo_media_token VARCHAR(64) DEFAULT NULL,
                logo_url VARCHAR(255) DEFAULT NULL,
                attachments_json LONGTEXT DEFAULT NULL,
                eligibility_notes LONGTEXT DEFAULT NULL,
                address_line1 VARCHAR(191) DEFAULT NULL,
                city VARCHAR(120) DEFAULT NULL,
                state_code VARCHAR(32) DEFAULT NULL,
                county VARCHAR(120) DEFAULT NULL,
                postal_code VARCHAR(32) DEFAULT NULL,
                service_radius VARCHAR(120) DEFAULT NULL,
                is_online TINYINT(1) NOT NULL DEFAULT 0,
                review_due_at DATETIME DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                is_featured TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                status VARCHAR(24) NOT NULL DEFAULT 'draft',
                created_by BIGINT UNSIGNED DEFAULT NULL,
                updated_by BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY resource_uid (resource_uid),
                UNIQUE KEY resource_code (resource_code),
                UNIQUE KEY resource_type_resource_slug (resource_type_id, slug),
                KEY resource_listing (resource_type_id, primary_category_id, status, is_featured, sort_order, title),
                KEY resource_geo (state_code, city, county, postal_code, is_online),
                KEY resource_review (status, review_due_at, expires_at)
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$resource_categories} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                resource_id BIGINT UNSIGNED NOT NULL,
                category_id BIGINT UNSIGNED NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY resource_category_unique (resource_id, category_id),
                KEY category_lookup (category_id, resource_id),
                KEY resource_lookup (resource_id)
            ) {$charset};"
        );

        \metis_db_delta(
            "CREATE TABLE {$resource_tags} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                resource_id BIGINT UNSIGNED NOT NULL,
                tag_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY resource_tag_unique (resource_id, tag_id),
                KEY tag_lookup (tag_id, resource_id),
                KEY resource_lookup (resource_id)
            ) {$charset};"
        );

        if ( \function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->ensureSchema();
        }

        self::$ready = true;
    }
}
