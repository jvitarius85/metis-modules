<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_drive_ensure_schema(): void {
    static $done = false;
    if ( $done ) return;
    $charset          = metis_db()->get_charset_collate();
    $audit_table      = Metis_Tables::get( 'drive_audit' );
    $user_folders     = Metis_Tables::get( 'drive_user_folders' );
    $items_table      = Metis_Tables::get( 'drive_items' );
    $sync_state_table = Metis_Tables::get( 'drive_sync_state' );
    metis_db_delta( "CREATE TABLE {$audit_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        drive_id VARCHAR(191) NOT NULL, folder_id VARCHAR(191) DEFAULT NULL,
        file_id VARCHAR(191) DEFAULT NULL, item_name VARCHAR(255) DEFAULT NULL,
        item_type VARCHAR(64) DEFAULT NULL, action_key VARCHAR(64) NOT NULL,
        actor_person_id BIGINT UNSIGNED DEFAULT NULL, details_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY drive_id (drive_id), KEY folder_id (folder_id),
        KEY file_id (file_id), KEY action_key (action_key), KEY actor_person_id (actor_person_id)
    ) {$charset};" );
    metis_db_delta( "CREATE TABLE {$user_folders} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        drive_id VARCHAR(191) NOT NULL, person_id BIGINT UNSIGNED DEFAULT NULL,
        folder_id VARCHAR(191) NOT NULL, folder_name VARCHAR(255) DEFAULT NULL,
        parent_folder_id VARCHAR(191) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY drive_person (drive_id, person_id),
        KEY person_id (person_id), KEY folder_id (folder_id)
    ) {$charset};" );
    metis_db_delta( "CREATE TABLE {$items_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        drive_id VARCHAR(191) NOT NULL, item_id VARCHAR(191) NOT NULL,
        parent_id VARCHAR(191) NOT NULL, item_name VARCHAR(255) NOT NULL,
        mime_type VARCHAR(191) NOT NULL, is_folder TINYINT(1) NOT NULL DEFAULT 0,
        modified_time DATETIME DEFAULT NULL, size_bytes BIGINT UNSIGNED DEFAULT NULL,
        web_view_link TEXT DEFAULT NULL, raw_json LONGTEXT DEFAULT NULL,
        synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY drive_item (drive_id, item_id),
        KEY drive_parent (drive_id, parent_id),
        KEY drive_parent_folder (drive_id, parent_id, is_folder), KEY item_name (item_name)
    ) {$charset};" );
    metis_db_delta( "CREATE TABLE {$sync_state_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        drive_id VARCHAR(191) NOT NULL, folder_id VARCHAR(191) NOT NULL,
        parent_folder_id VARCHAR(191) DEFAULT NULL, folder_name VARCHAR(255) DEFAULT NULL,
        last_synced_at DATETIME DEFAULT NULL, last_requested_at DATETIME DEFAULT NULL,
        sync_status VARCHAR(32) NOT NULL DEFAULT 'idle',
        sync_depth SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        item_count INT UNSIGNED NOT NULL DEFAULT 0, last_error TEXT DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY drive_folder (drive_id, folder_id),
        KEY sync_status (sync_status), KEY last_synced_at (last_synced_at)
    ) {$charset};" );
    $done = true;
}

function metis_drive_current_person_id(): int {
    if ( function_exists( 'metis_auth_current_person_id' ) ) {
        $id = (int) metis_auth_current_person_id();
        if ( $id > 0 ) return $id;
    }
    if ( function_exists( 'metis_people_get_current_person_id' ) ) {
        $id = (int) metis_people_get_current_person_id();
        if ( $id > 0 ) return $id;
    }
    $user  = function_exists( 'metis_runtime_current_user' ) ? metis_runtime_current_user() : null;
    $email = is_object( $user ) ? strtolower( trim( (string) ( $user->user_email ?? '' ) ) ) : '';
    if ( $email !== '' && metis_email_is_valid( $email ) ) {
        $db = metis_db();
        if ( Metis_Tables::has( 'auth_users' ) ) {
            $id = (int) $db->scalar( 'SELECT person_id FROM ' . Metis_Tables::get( 'auth_users' ) . ' WHERE user_email = %s LIMIT 1', [ $email ] );
            if ( $id > 0 ) return $id;
        }
        if ( Metis_Tables::has( 'people' ) ) {
            $id = (int) $db->scalar( 'SELECT id FROM ' . Metis_Tables::get( 'people' ) . ' WHERE email = %s LIMIT 1', [ $email ] );
            if ( $id > 0 ) return $id;
        }
    }
    return 0;
}

function metis_drive_log_action( array $cfg, string $action_key, array $payload = [] ): void {
    metis_drive_ensure_schema();
    $db        = metis_db();
    $table     = Metis_Tables::get( 'drive_audit' );
    $person_id = metis_drive_current_person_id();
    $db->insert( $table, [
        'drive_id'         => (string) ( $cfg['shared_drive_id'] ?? '' ),
        'folder_id'        => isset( $payload['folder_id'] ) ? (string) $payload['folder_id'] : null,
        'file_id'          => isset( $payload['file_id'] ) ? (string) $payload['file_id'] : null,
        'item_name'        => isset( $payload['item_name'] ) ? (string) $payload['item_name'] : null,
        'item_type'        => isset( $payload['item_type'] ) ? (string) $payload['item_type'] : null,
        'action_key'       => metis_key_clean( $action_key ),
        'actor_person_id'  => $person_id > 0 ? $person_id : null,
        'details_json'     => ! empty( $payload['details'] ) ? metis_json_encode( $payload['details'] ) : null,
        'created_at'       => metis_current_time( 'mysql' ),
    ] );
    metis_audit_log_activity( 'drive_' . metis_key_clean( $action_key ), [
        'module'   => 'drive',
        'resource' => [
            'type'  => ! empty( $payload['item_type'] ) ? (string) $payload['item_type'] : 'drive_item',
            'id'    => ! empty( $payload['file_id'] ) ? (string) $payload['file_id'] : (string) ( $payload['folder_id'] ?? '' ),
            'label' => (string) ( $payload['item_name'] ?? $action_key ),
        ],
        'context'  => [
            'drive_id'  => (string) ( $cfg['shared_drive_id'] ?? '' ),
            'folder_id' => (string) ( $payload['folder_id'] ?? '' ),
            'file_id'   => (string) ( $payload['file_id'] ?? '' ),
            'details'   => (array) ( $payload['details'] ?? [] ),
        ],
    ] );
}
