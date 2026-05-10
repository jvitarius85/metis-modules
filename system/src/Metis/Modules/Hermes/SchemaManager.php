<?php
declare(strict_types=1);

namespace Metis\Modules\Hermes;

final class SchemaManager {
    private static bool $done = false;

    public static function ensureSchema(): void {
        if ( self::$done ) {
            return;
        }

        $charset_collate = \metis_db()->get_charset_collate();
        $sessions_table  = \Metis_Tables::get( 'hermes_sessions' );
        $messages_table  = \Metis_Tables::get( 'hermes_messages' );
        $actions_table   = \Metis_Tables::get( 'hermes_actions' );
        $reports_table   = \Metis_Tables::get( 'hermes_reports' );
        $memory_table    = \Metis_Tables::get( 'hermes_memory' );
        $logs_table      = \Metis_Tables::get( 'hermes_command_logs' );
        $help_logs_table = \Metis_Tables::get( 'hermes_help_issue_logs' );

        \metis_db_delta( "CREATE TABLE {$sessions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_code VARCHAR(32) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            title VARCHAR(191) DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'open',
            last_intent VARCHAR(64) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_code (session_code),
            KEY user_updated (user_id, updated_at),
            KEY status_updated (status, updated_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$messages_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            role_name VARCHAR(24) NOT NULL,
            message_hash VARCHAR(64) DEFAULT NULL,
            content LONGTEXT NOT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_created (session_id, created_at),
            KEY role_created (role_name, created_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$actions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED DEFAULT NULL,
            action_code VARCHAR(32) NOT NULL,
            action_type VARCHAR(64) NOT NULL,
            title VARCHAR(191) NOT NULL,
            approval_status VARCHAR(24) NOT NULL DEFAULT 'pending',
            payload_json LONGTEXT NOT NULL,
            preview_json LONGTEXT DEFAULT NULL,
            approved_by BIGINT UNSIGNED DEFAULT NULL,
            approval_note TEXT DEFAULT NULL,
            executed_at DATETIME DEFAULT NULL,
            result_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY action_code (action_code),
            KEY session_status (session_id, approval_status, created_at),
            KEY status_created (approval_status, created_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$reports_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_code VARCHAR(32) NOT NULL,
            session_id BIGINT UNSIGNED DEFAULT NULL,
            report_type VARCHAR(64) NOT NULL,
            subject_key VARCHAR(100) DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'ready',
            summary_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY report_code (report_code),
            KEY type_subject (report_type, subject_key),
            KEY status_updated (status, updated_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$memory_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            memory_key VARCHAR(120) NOT NULL,
            memory_type VARCHAR(64) NOT NULL,
            scope_key VARCHAR(120) DEFAULT NULL,
            contents_json LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY memory_key (memory_key),
            KEY type_scope (memory_type, scope_key),
            KEY updated_at (updated_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_code VARCHAR(32) DEFAULT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            raw_input LONGTEXT DEFAULT NULL,
            normalized_input LONGTEXT DEFAULT NULL,
            selected_intent VARCHAR(64) DEFAULT NULL,
            tool_key VARCHAR(120) DEFAULT NULL,
            confidence_score DECIMAL(5,4) DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            enclave_request_id VARCHAR(64) DEFAULT NULL,
            result_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_created (session_code, created_at),
            KEY user_created (user_id, created_at),
            KEY intent_created (selected_intent, created_at),
            KEY tool_created (tool_key, created_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$help_logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_code VARCHAR(32) DEFAULT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            raw_message LONGTEXT DEFAULT NULL,
            normalized_issue LONGTEXT DEFAULT NULL,
            classification VARCHAR(32) DEFAULT NULL,
            module_key VARCHAR(64) DEFAULT NULL,
            module_label VARCHAR(120) DEFAULT NULL,
            action_key VARCHAR(120) DEFAULT NULL,
            confidence_label VARCHAR(16) DEFAULT NULL,
            confidence_score DECIMAL(5,4) DEFAULT NULL,
            help_articles_json LONGTEXT DEFAULT NULL,
            diagnostics_json LONGTEXT DEFAULT NULL,
            proposed_actions_json LONGTEXT DEFAULT NULL,
            executed_actions_json LONGTEXT DEFAULT NULL,
            result_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_created (user_id, created_at),
            KEY session_created (session_code, created_at),
            KEY classification_created (classification, created_at),
            KEY module_action_created (module_key, action_key, created_at),
            KEY confidence_created (confidence_label, created_at)
        ) {$charset_collate};" );

        if ( function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->ensureSchema();
        }

        self::$done = true;
    }
}
