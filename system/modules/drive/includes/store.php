<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_drive_sync_state( string $drive_id, string $folder_id ): array {
    metis_drive_ensure_schema();
    $row = metis_db()->fetchOne( 'SELECT * FROM ' . Metis_Tables::get( 'drive_sync_state' ) . ' WHERE drive_id = %s AND folder_id = %s LIMIT 1', [ $drive_id, $folder_id ] );
    return is_array( $row ) ? $row : [];
}

function metis_drive_update_sync_state( string $drive_id, string $folder_id, array $data ): void {
    metis_drive_ensure_schema();
    $db      = metis_db();
    $table   = Metis_Tables::get( 'drive_sync_state' );
    $payload = [
        'drive_id'          => $drive_id,
        'folder_id'         => $folder_id,
        'parent_folder_id'  => array_key_exists( 'parent_folder_id', $data ) ? (string) ( $data['parent_folder_id'] ?? '' ) : null,
        'folder_name'       => array_key_exists( 'folder_name', $data ) ? (string) ( $data['folder_name'] ?? '' ) : null,
        'last_synced_at'    => array_key_exists( 'last_synced_at', $data ) ? ( $data['last_synced_at'] ?: null ) : null,
        'last_requested_at' => array_key_exists( 'last_requested_at', $data ) ? ( $data['last_requested_at'] ?: null ) : null,
        'sync_status'       => metis_key_clean( (string) ( $data['sync_status'] ?? 'idle' ) ) ?: 'idle',
        'sync_depth'        => max( 0, (int) ( $data['sync_depth'] ?? 0 ) ),
        'item_count'        => max( 0, (int) ( $data['item_count'] ?? 0 ) ),
        'last_error'        => array_key_exists( 'last_error', $data ) ? (string) ( $data['last_error'] ?? '' ) : null,
        'updated_at'        => metis_current_time( 'mysql' ),
    ];
    $existing = metis_drive_sync_state( $drive_id, $folder_id );
    if ( ! empty( $existing['id'] ) ) {
        $db->update( $table, $payload, [ 'id' => (int) $existing['id'] ] );
        return;
    }
    $db->insert( $table, $payload );
}

function metis_drive_mark_folder_requested( string $drive_id, string $folder_id, string $folder_name = '', string $parent_folder_id = '' ): void {
    $state = metis_drive_sync_state( $drive_id, $folder_id );
    metis_drive_update_sync_state( $drive_id, $folder_id, [
        'parent_folder_id'  => $parent_folder_id !== '' ? $parent_folder_id : (string) ( $state['parent_folder_id'] ?? '' ),
        'folder_name'       => $folder_name !== '' ? $folder_name : (string) ( $state['folder_name'] ?? '' ),
        'last_synced_at'    => (string) ( $state['last_synced_at'] ?? '' ),
        'last_requested_at' => metis_current_time( 'mysql' ),
        'sync_status'       => (string) ( $state['sync_status'] ?? 'idle' ),
        'sync_depth'        => (int) ( $state['sync_depth'] ?? 0 ),
        'item_count'        => (int) ( $state['item_count'] ?? 0 ),
        'last_error'        => (string) ( $state['last_error'] ?? '' ),
    ] );
}

function metis_drive_sync_needs_refresh( string $drive_id, string $folder_id, int $max_age ): bool {
    $state          = metis_drive_sync_state( $drive_id, $folder_id );
    $last_synced_at = (string) ( $state['last_synced_at'] ?? '' );
    if ( $last_synced_at === '' ) return true;
    $timestamp = strtotime( $last_synced_at );
    return $timestamp === false || ( time() - $timestamp ) >= max( 60, $max_age );
}

function metis_drive_get_cached_item( string $drive_id, string $item_id ): array {
    metis_drive_ensure_schema();
    $row = metis_db()->fetchOne( 'SELECT * FROM ' . Metis_Tables::get( 'drive_items' ) . ' WHERE drive_id = %s AND item_id = %s LIMIT 1', [ $drive_id, $item_id ] );
    return is_array( $row ) ? $row : [];
}

function metis_drive_cached_item_meta( string $drive_id, string $item_id ): array {
    $item = metis_drive_get_cached_item( $drive_id, $item_id );
    if ( ! empty( $item ) ) {
        return [ 'ok' => true, 'file' => [
            'id'           => (string) ( $item['item_id'] ?? '' ),
            'name'         => (string) ( $item['item_name'] ?? '' ),
            'mimeType'     => (string) ( $item['mime_type'] ?? '' ),
            'parents'      => [ (string) ( $item['parent_id'] ?? '' ) ],
            'driveId'      => (string) ( $item['drive_id'] ?? '' ),
            'webViewLink'  => (string) ( $item['web_view_link'] ?? '' ),
            'modifiedTime' => ! empty( $item['modified_time'] ) ? gmdate( 'c', strtotime( (string) $item['modified_time'] ) ) : '',
            'size'         => isset( $item['size_bytes'] ) ? (string) $item['size_bytes'] : '',
        ] ];
    }
    $db      = metis_db();
    $mapping = $db->fetchOne(
        'SELECT folder_id, folder_name, parent_folder_id FROM ' . Metis_Tables::get( 'drive_user_folders' ) . ' WHERE drive_id = %s AND folder_id = %s LIMIT 1',
        [ $drive_id, $item_id ]
    );
    if ( is_array( $mapping ) && ! empty( $mapping['folder_id'] ) ) {
        return [ 'ok' => true, 'file' => [
            'id'          => (string) ( $mapping['folder_id'] ?? '' ),
            'name'        => (string) ( $mapping['folder_name'] ?? '' ),
            'mimeType'    => 'application/vnd.google-apps.folder',
            'parents'     => [ (string) ( $mapping['parent_folder_id'] ?? '' ) ],
            'driveId'     => $drive_id,
            'webViewLink' => '',
        ] ];
    }
    $state = metis_drive_sync_state( $drive_id, $item_id );
    if ( ! empty( $state['folder_id'] ) ) {
        return [ 'ok' => true, 'file' => [
            'id'          => (string) ( $state['folder_id'] ?? '' ),
            'name'        => (string) ( $state['folder_name'] ?? '' ),
            'mimeType'    => 'application/vnd.google-apps.folder',
            'parents'     => [ (string) ( $state['parent_folder_id'] ?? '' ) ],
            'driveId'     => $drive_id,
            'webViewLink' => '',
        ] ];
    }
    return [ 'ok' => false, 'error' => 'Item is not cached.' ];
}

function metis_drive_store_cached_item( string $drive_id, array $item, string $fallback_parent_id = '' ): void {
    metis_drive_ensure_schema();
    $item_id = trim( (string) ( $item['id'] ?? '' ) );
    if ( $drive_id === '' || $item_id === '' ) return;
    $parent_id = metis_drive_normalize_parent_id( $drive_id, (string) ( ( $item['parents'][0] ?? '' ) ?: $fallback_parent_id ?: $drive_id ) );
    metis_db()->replace( Metis_Tables::get( 'drive_items' ), [
        'drive_id'      => $drive_id,
        'item_id'       => $item_id,
        'parent_id'     => $parent_id,
        'item_name'     => (string) ( $item['name'] ?? '' ),
        'mime_type'     => (string) ( $item['mimeType'] ?? '' ),
        'is_folder'     => (string) ( $item['mimeType'] ?? '' ) === 'application/vnd.google-apps.folder' ? 1 : 0,
        'modified_time' => metis_drive_datetime_from_google( (string) ( $item['modifiedTime'] ?? '' ) ),
        'size_bytes'    => isset( $item['size'] ) && $item['size'] !== '' ? (string) $item['size'] : null,
        'web_view_link' => (string) ( $item['webViewLink'] ?? '' ),
        'raw_json'      => metis_json_encode( $item ),
        'synced_at'     => metis_current_time( 'mysql' ),
    ] );
    if ( (string) ( $item['name'] ?? '' ) === metis_drive_users_root_folder_name()
        && (string) ( $item['mimeType'] ?? '' ) === 'application/vnd.google-apps.folder'
        && $parent_id === $drive_id ) {
        metis_set_transient( 'metis_drive_users_root_' . md5( $drive_id ), [ 'folder_id' => $item_id, 'folder_name' => (string) ( $item['name'] ?? '' ) ], 10 * MINUTE_IN_SECONDS );
    }
}

function metis_drive_delete_cached_item( string $drive_id, string $item_id ): void {
    metis_drive_ensure_schema();
    if ( $drive_id === '' || $item_id === '' ) return;
    metis_db()->delete( Metis_Tables::get( 'drive_items' ), [ 'drive_id' => $drive_id, 'item_id' => $item_id ] );
}
