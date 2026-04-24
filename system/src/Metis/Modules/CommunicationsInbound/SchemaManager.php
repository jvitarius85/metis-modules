<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

final class SchemaManager {
    private static bool $done = false;

    public static function ensureSchema(): void {
        if ( self::$done ) {
            return;
        }

        $db = \metis_db();
        $charset_collate = $db->connection()->get_charset_collate();

        $mailboxes = \Metis_Tables::get( 'communications_inbound_mailboxes' );
        $messages = \Metis_Tables::get( 'communications_inbound_messages' );
        $attachments = \Metis_Tables::get( 'communications_inbound_attachments' );
        $events = \Metis_Tables::get( 'communications_inbound_events' );
        $links = \Metis_Tables::get( 'communications_inbound_links' );

        \metis_db_delta( "CREATE TABLE {$mailboxes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            mailbox_key VARCHAR(64) NOT NULL,
            provider VARCHAR(32) NOT NULL DEFAULT 'gmail',
            mailbox_email VARCHAR(191) NOT NULL,
            display_name VARCHAR(191) DEFAULT NULL,
            delegated_user VARCHAR(191) DEFAULT NULL,
            topic_name VARCHAR(255) DEFAULT NULL,
            label_ids_json LONGTEXT DEFAULT NULL,
            label_filter_behavior VARCHAR(24) DEFAULT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            current_history_id VARCHAR(64) DEFAULT NULL,
            last_watch_history_id VARCHAR(64) DEFAULT NULL,
            watch_expiration_at DATETIME DEFAULT NULL,
            last_watch_requested_at DATETIME DEFAULT NULL,
            last_sync_requested_at DATETIME DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            last_message_received_at DATETIME DEFAULT NULL,
            sync_status VARCHAR(24) NOT NULL DEFAULT 'idle',
            last_error TEXT DEFAULT NULL,
            settings_hash VARCHAR(64) DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY mailbox_key (mailbox_key),
            UNIQUE KEY provider_mailbox_email (provider, mailbox_email),
            KEY enabled_sync_status (enabled, sync_status),
            KEY watch_expiration_at (watch_expiration_at),
            KEY last_synced_at (last_synced_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$messages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            mailbox_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(32) NOT NULL DEFAULT 'gmail',
            provider_message_id VARCHAR(191) NOT NULL,
            provider_thread_id VARCHAR(191) DEFAULT NULL,
            provider_history_id VARCHAR(64) DEFAULT NULL,
            rfc_message_id VARCHAR(191) DEFAULT NULL,
            dedupe_key VARCHAR(191) NOT NULL,
            processing_status VARCHAR(24) NOT NULL DEFAULT 'received',
            classification VARCHAR(64) DEFAULT NULL,
            classification_confidence DECIMAL(5,4) NOT NULL DEFAULT 0,
            parser_key VARCHAR(64) DEFAULT NULL,
            handler_key VARCHAR(64) DEFAULT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            from_name VARCHAR(191) DEFAULT NULL,
            from_email VARCHAR(191) DEFAULT NULL,
            sender_email VARCHAR(191) DEFAULT NULL,
            to_json LONGTEXT DEFAULT NULL,
            cc_json LONGTEXT DEFAULT NULL,
            reply_to_json LONGTEXT DEFAULT NULL,
            canonical_recipient_emails_json LONGTEXT DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            received_at DATETIME DEFAULT NULL,
            text_body LONGTEXT DEFAULT NULL,
            html_body LONGTEXT DEFAULT NULL,
            raw_headers_json LONGTEXT DEFAULT NULL,
            attachments_json LONGTEXT DEFAULT NULL,
            raw_provider_payload_json LONGTEXT DEFAULT NULL,
            parser_metadata_json LONGTEXT DEFAULT NULL,
            handling_metadata_json LONGTEXT DEFAULT NULL,
            error_code VARCHAR(64) DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            first_processed_at DATETIME DEFAULT NULL,
            last_processed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            UNIQUE KEY provider_mailbox_message (provider, mailbox_id, provider_message_id),
            KEY mailbox_status_received (mailbox_id, processing_status, received_at),
            KEY classification (classification),
            KEY handler_key (handler_key),
            KEY provider_thread_id (provider_thread_id),
            KEY rfc_message_id (rfc_message_id),
            KEY sender_email (sender_email)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$attachments} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            mailbox_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(32) NOT NULL DEFAULT 'gmail',
            provider_message_id VARCHAR(191) NOT NULL,
            provider_attachment_id VARCHAR(191) DEFAULT NULL,
            part_id VARCHAR(64) DEFAULT NULL,
            dedupe_key VARCHAR(191) NOT NULL,
            file_name VARCHAR(255) DEFAULT NULL,
            mime_type VARCHAR(191) DEFAULT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            storage_status VARCHAR(24) NOT NULL DEFAULT 'pending',
            media_token VARCHAR(64) DEFAULT NULL,
            media_url VARCHAR(512) DEFAULT NULL,
            storage_path VARCHAR(512) DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY message_status (message_id, storage_status),
            KEY mailbox_status (mailbox_id, storage_status),
            KEY provider_attachment_id (provider_attachment_id),
            KEY media_token (media_token)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            mailbox_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED DEFAULT NULL,
            event_type VARCHAR(64) NOT NULL,
            event_status VARCHAR(24) NOT NULL DEFAULT 'received',
            parser_key VARCHAR(64) DEFAULT NULL,
            handler_key VARCHAR(64) DEFAULT NULL,
            dedupe_key VARCHAR(191) DEFAULT NULL,
            payload_json LONGTEXT DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY mailbox_type_status (mailbox_id, event_type, event_status),
            KEY message_id (message_id),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        \metis_db_delta( "CREATE TABLE {$links} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            module_slug VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            link_type VARCHAR(64) NOT NULL,
            metadata_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY link_unique (message_id, module_slug, entity_type, entity_id, link_type),
            KEY entity_ref (module_slug, entity_type, entity_id),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        self::$done = true;
    }
}
