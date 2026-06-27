<?php
declare(strict_types=1);

namespace Metis\Modules\Website;

use Metis\Core\Application;

/**
 * Website Module Schema Manager
 * 
 * Manages database schema for pages, posts, blocks, menus, popups, and themes.
 */
final class SchemaManager {
    private static bool $schema_ready = false;

    public static function ensureSchema(): void {
        if ( self::$schema_ready ) {
            return;
        }

        $db = self::db();
        $charset_collate = $db->get_charset_collate();

        // Get table names from registry
        $pages_table = \Metis_Tables::get( 'website_pages' );
        $posts_table = \Metis_Tables::get( 'website_posts' );
        $post_categories_table = \Metis_Tables::get( 'website_post_categories' );
        $post_category_map_table = \Metis_Tables::get( 'website_post_category_map' );
        $post_tags_table = \Metis_Tables::get( 'website_post_tags' );
        $post_tag_map_table = \Metis_Tables::get( 'website_post_tag_map' );
        $global_layouts_table = \Metis_Tables::get( 'website_global_layouts' );
        $menus_table = \Metis_Tables::get( 'website_menus' );
        $banners_table = \Metis_Tables::get( 'website_banners' );
        $popups_table = \Metis_Tables::get( 'website_popups' );
        $theme_config_table = \Metis_Tables::get( 'website_theme_config' );
        $templates_table = \Metis_Tables::get( 'website_templates' );
        $web_parts_table = \Metis_Tables::get( 'website_web_parts' );
        $blocks_table = \Metis_Tables::get( 'website_blocks' );
        $revisions_table = \Metis_Tables::get( 'website_revisions' );
        $redirects_table = \Metis_Tables::get( 'website_redirects' );

        // Pages table
        \metis_db_delta( "CREATE TABLE {$pages_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            page_code VARCHAR(16) DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            layout_json LONGTEXT DEFAULT NULL,
            draft_layout_json LONGTEXT DEFAULT NULL,
            published_layout_json LONGTEXT DEFAULT NULL,
            seo_meta_json TEXT DEFAULT NULL,
            page_type VARCHAR(24) NOT NULL DEFAULT 'page',
            template_key VARCHAR(64) DEFAULT NULL,
            parent_id BIGINT UNSIGNED DEFAULT NULL,
            menu_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            published_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY page_code (page_code),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY page_type (page_type),
            KEY parent_id (parent_id),
            KEY menu_order (menu_order),
            KEY created_by (created_by),
            KEY published_at (published_at)
        ) {$charset_collate};" );

        // Posts table
        \metis_db_delta( "CREATE TABLE {$posts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_code VARCHAR(16) DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            excerpt TEXT DEFAULT NULL,
            content_json LONGTEXT DEFAULT NULL,
            draft_content_json LONGTEXT DEFAULT NULL,
            published_content_json LONGTEXT DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            publish_date DATETIME DEFAULT NULL,
            seo_meta_json TEXT DEFAULT NULL,
            page_type VARCHAR(24) NOT NULL DEFAULT 'post',
            content_format VARCHAR(24) NOT NULL DEFAULT 'standard',
            template_key VARCHAR(64) DEFAULT NULL,
            post_category_id BIGINT UNSIGNED DEFAULT NULL,
            parent_page_id BIGINT UNSIGNED DEFAULT NULL,
            author_id BIGINT UNSIGNED DEFAULT NULL,
            featured_image_id BIGINT UNSIGNED DEFAULT NULL,
            featured_image_caption TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_code (post_code),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY page_type (page_type),
            KEY publish_date (publish_date),
            KEY template_key (template_key),
            KEY post_category_id (post_category_id),
            KEY parent_page_id (parent_page_id),
            KEY author_id (author_id),
            KEY created_by (created_by)
        ) {$charset_collate};" );

        // Post categories table
        \metis_db_delta( "CREATE TABLE {$post_categories_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_code VARCHAR(16) DEFAULT NULL,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            parent_id BIGINT UNSIGNED DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY category_code (category_code),
            UNIQUE KEY slug (slug),
            KEY parent_id (parent_id),
            KEY status (status),
            KEY sort_order (sort_order),
            KEY name (name)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$post_category_map_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_category_unique (post_id, category_id),
            KEY post_id (post_id),
            KEY category_id (category_id),
            KEY primary_lookup (post_id, is_primary)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$post_tags_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tag_code VARCHAR(16) DEFAULT NULL,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY tag_code (tag_code),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY sort_order (sort_order),
            KEY name (name)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$post_tag_map_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_tag_unique (post_id, tag_id),
            KEY post_id (post_id),
            KEY tag_id (tag_id),
            KEY post_tag_lookup (tag_id, post_id)
        ) {$charset_collate};" );

        // Global layouts (headers, footers)
        \metis_db_delta( "CREATE TABLE {$global_layouts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            layout_code VARCHAR(16) DEFAULT NULL,
            type VARCHAR(24) NOT NULL,
            name VARCHAR(255) NOT NULL,
            layout_json LONGTEXT DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY layout_code (layout_code),
            KEY type (type),
            KEY status (status),
            KEY is_default (is_default)
        ) {$charset_collate};" );

        // Menus table
        \metis_db_delta( "CREATE TABLE {$menus_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            menu_code VARCHAR(16) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            location VARCHAR(64) DEFAULT NULL,
            items_json LONGTEXT DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'active',
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY menu_code (menu_code),
            KEY location (location),
            KEY status (status)
        ) {$charset_collate};" );

        // Banners table
        \metis_db_delta( "CREATE TABLE {$banners_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            banner_code VARCHAR(16) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(32) NOT NULL DEFAULT 'top_banner',
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            content_json LONGTEXT DEFAULT NULL,
            targeting_json TEXT DEFAULT NULL,
            dismiss_mode VARCHAR(24) NOT NULL DEFAULT 'session',
            start_at DATETIME DEFAULT NULL,
            end_at DATETIME DEFAULT NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY banner_code (banner_code),
            KEY status (status),
            KEY type (type),
            KEY start_at (start_at),
            KEY end_at (end_at),
            KEY sort_order (sort_order)
        ) {$charset_collate};" );

        // Popups table
        \metis_db_delta( "CREATE TABLE {$popups_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            popup_code VARCHAR(16) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL DEFAULT 'click',
            trigger_config_json TEXT DEFAULT NULL,
            layout_json LONGTEXT DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            display_rules_json TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY popup_code (popup_code),
            KEY status (status),
            KEY trigger_type (trigger_type)
        ) {$charset_collate};" );

        // Theme configuration table
        \metis_db_delta( "CREATE TABLE {$theme_config_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            global_styles_json TEXT DEFAULT NULL,
            typography_json TEXT DEFAULT NULL,
            color_palette_json TEXT DEFAULT NULL,
            spacing_json TEXT DEFAULT NULL,
            custom_tokens_json TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY is_active (is_active)
        ) {$charset_collate};" );

        // Template system table (page/post/archive structural layouts)
        \metis_db_delta( "CREATE TABLE {$templates_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL,
            template_type VARCHAR(24) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'published',
            structure_json LONGTEXT DEFAULT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY template_key (template_key),
            KEY type_status_default (template_type, status, is_default),
            KEY status (status)
        ) {$charset_collate};" );

        // Web parts table (reusable components attached by target/placement rules)
        \metis_db_delta( "CREATE TABLE {$web_parts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            part_code VARCHAR(16) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            part_type VARCHAR(64) NOT NULL DEFAULT 'custom',
            render_mode VARCHAR(24) NOT NULL DEFAULT 'blocks',
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            content_json LONGTEXT DEFAULT NULL,
            config_json TEXT DEFAULT NULL,
            visibility_json TEXT DEFAULT NULL,
            target_scope VARCHAR(24) NOT NULL DEFAULT 'site',
            target_ref VARCHAR(128) DEFAULT NULL,
            region VARCHAR(24) NOT NULL DEFAULT 'main',
            slot VARCHAR(24) NOT NULL DEFAULT 'append',
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY part_code (part_code),
            KEY target_lookup (status, target_scope, target_ref),
            KEY placement_lookup (region, slot, sort_order),
            KEY type_lookup (part_type, status)
        ) {$charset_collate};" );

        // Block registry table (for reusable blocks)
        \metis_db_delta( "CREATE TABLE {$blocks_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            block_code VARCHAR(16) DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(64) NOT NULL,
            block_json LONGTEXT DEFAULT NULL,
            category VARCHAR(64) DEFAULT NULL,
            is_global TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY block_code (block_code),
            KEY type (type),
            KEY category (category),
            KEY is_global (is_global)
        ) {$charset_collate};" );

        // Revisions table (for pages and posts)
        \metis_db_delta( "CREATE TABLE {$revisions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            revision_data LONGTEXT NOT NULL,
            revision_note TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entity_lookup (entity_type, entity_id),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        // Redirects table (path-based redirect rules resolved before 404)
        \metis_db_delta( "CREATE TABLE {$redirects_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_path VARCHAR(512) NOT NULL,
            destination_path VARCHAR(512) NOT NULL,
            redirect_type VARCHAR(3) NOT NULL DEFAULT '301',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_path (source_path),
            KEY active_type (is_active, redirect_type),
            KEY updated_at (updated_at)
        ) {$charset_collate};" );

        self::addColumnIfMissing( $pages_table, 'page_type', "VARCHAR(24) NOT NULL DEFAULT 'page'" );
        self::addColumnIfMissing( $posts_table, 'page_type', "VARCHAR(24) NOT NULL DEFAULT 'post'" );
        self::addColumnIfMissing( $posts_table, 'content_format', "VARCHAR(24) NOT NULL DEFAULT 'standard'" );
        self::addColumnIfMissing( $posts_table, 'post_category_id', 'BIGINT UNSIGNED DEFAULT NULL' );
        self::addColumnIfMissing( $posts_table, 'parent_page_id', 'BIGINT UNSIGNED DEFAULT NULL' );
        self::addColumnIfMissing( $post_categories_table, 'parent_id', 'BIGINT UNSIGNED DEFAULT NULL' );
        self::addIndexIfMissing( $pages_table, 'page_type', 'page_type' );
        self::addIndexIfMissing( $posts_table, 'page_type', 'page_type' );
        self::addIndexIfMissing( $posts_table, 'post_category_id', 'post_category_id' );
        self::addIndexIfMissing( $posts_table, 'parent_page_id', 'parent_page_id' );
        self::addIndexIfMissing( $post_categories_table, 'parent_id', 'parent_id' );
        self::addIndexIfMissing( $post_category_map_table, 'post_id', 'post_id' );
        self::addIndexIfMissing( $post_category_map_table, 'category_id', 'category_id' );

        if ( self::tableExists( $post_category_map_table ) && self::tableExists( $posts_table ) ) {
            $db->execute(
                "INSERT IGNORE INTO {$post_category_map_table} (post_id, category_id, is_primary)
                 SELECT id, post_category_id, 1
                 FROM {$posts_table}
                 WHERE post_category_id IS NOT NULL AND post_category_id > 0"
            );
            $db->execute(
                "UPDATE {$post_category_map_table} m
                 INNER JOIN {$posts_table} p ON p.id = m.post_id
                 SET m.is_primary = CASE WHEN p.post_category_id = m.category_id THEN 1 ELSE 0 END"
            );
        }

        if ( function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->ensureSchema();
        }

        self::$schema_ready = true;
        \Metis_Logger::info( 'Website schema initialized' );
    }

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

    public static function indexExists( string $table, string $index ): bool {
        $db = self::db();
        if ( ! self::tableExists( $table ) ) {
            return false;
        }
        $rows = $db->fetchAll( "SHOW INDEX FROM {$table} WHERE Key_name = %s", [ $index ] );
        return is_array( $rows ) && $rows !== [];
    }

    public static function addIndexIfMissing( string $table, string $index, string $column ): void {
        $db = self::db();
        if ( ! self::tableExists( $table ) ) {
            return;
        }
        if ( self::indexExists( $table, $index ) ) {
            return;
        }
        $db->execute( "ALTER TABLE {$table} ADD INDEX {$index} ({$column})" );
    }

    private static function db(): object {
        return Application::service( 'db' );
    }
}
