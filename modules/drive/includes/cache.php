<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_drive_cached_folder_children( string $drive_id, string $folder_id, string $search = '', bool $folders_only = false ): array {
    metis_drive_ensure_schema();
    $db        = metis_db();
    $table     = Metis_Tables::get( 'drive_items' );
    $folder_id = metis_drive_normalize_parent_id( $drive_id, $folder_id );
    $where     = [ 'drive_id = %s', 'parent_id = %s' ];
    $args      = [ $drive_id, $folder_id ];
    if ( $folders_only ) $where[] = 'is_folder = 1';
    if ( $search !== '' ) {
        $where[] = 'item_name LIKE %s';
        $args[]  = '%' . $db->escapeLike( $search ) . '%';
    }
    $sql  = "SELECT drive_id, item_id, parent_id, item_name, mime_type, modified_time, size_bytes, web_view_link,
                   CASE WHEN is_folder = 1 AND EXISTS (
                       SELECT 1 FROM {$table} child
                       WHERE child.drive_id = {$table}.drive_id AND child.parent_id = {$table}.item_id AND child.is_folder = 1 LIMIT 1
                   ) THEN 1 ELSE 0 END AS has_children
            FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY is_folder DESC, item_name ASC';
    $rows = $db->fetchAll( $sql, $args ) ?: [];
    return array_values( array_map( static fn ( array $r ): array => [
        'id' => (string) ( $r['item_id'] ?? '' ), 'name' => (string) ( $r['item_name'] ?? '' ),
        'mimeType' => (string) ( $r['mime_type'] ?? '' ),
        'modifiedTime' => ! empty( $r['modified_time'] ) ? gmdate( 'c', strtotime( (string) $r['modified_time'] ) ) : '',
        'size' => isset( $r['size_bytes'] ) ? (string) $r['size_bytes'] : '',
        'webViewLink' => (string) ( $r['web_view_link'] ?? '' ),
        'parents' => [ (string) ( $r['parent_id'] ?? '' ) ],
        'driveId' => (string) ( $r['drive_id'] ?? '' ),
        'hasChildren' => ! empty( $r['has_children'] ),
    ], $rows ) );
}

function metis_drive_cached_search_results( string $drive_id, string $search, bool $folders_only = false ): array {
    metis_drive_ensure_schema();
    $db     = metis_db();
    $search = trim( $search );
    if ( $drive_id === '' || $search === '' ) return [];
    $table = Metis_Tables::get( 'drive_items' );
    $where = [ 'drive_id = %s', 'item_name LIKE %s' ];
    $args  = [ $drive_id, '%' . $db->escapeLike( $search ) . '%' ];
    if ( $folders_only ) $where[] = 'is_folder = 1';
    $sql  = "SELECT drive_id, item_id, parent_id, item_name, mime_type, modified_time, size_bytes, web_view_link,
                   CASE WHEN is_folder = 1 AND EXISTS (
                       SELECT 1 FROM {$table} child
                       WHERE child.drive_id = {$table}.drive_id AND child.parent_id = {$table}.item_id AND child.is_folder = 1 LIMIT 1
                   ) THEN 1 ELSE 0 END AS has_children
            FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY is_folder DESC, item_name ASC LIMIT 250';
    $rows = $db->fetchAll( $sql, $args ) ?: [];
    return array_values( array_map( static fn ( array $r ): array => [
        'id' => (string) ( $r['item_id'] ?? '' ), 'name' => (string) ( $r['item_name'] ?? '' ),
        'mimeType' => (string) ( $r['mime_type'] ?? '' ),
        'modifiedTime' => ! empty( $r['modified_time'] ) ? gmdate( 'c', strtotime( (string) $r['modified_time'] ) ) : '',
        'size' => isset( $r['size_bytes'] ) ? (string) $r['size_bytes'] : '',
        'webViewLink' => (string) ( $r['web_view_link'] ?? '' ),
        'parents' => [ (string) ( $r['parent_id'] ?? '' ) ],
        'driveId' => (string) ( $r['drive_id'] ?? '' ),
        'hasChildren' => ! empty( $r['has_children'] ),
    ], $rows ) );
}

function metis_drive_replace_cached_children( string $drive_id, string $folder_id, array $items ): void {
    metis_drive_ensure_schema();
    $db        = metis_db();
    $table     = Metis_Tables::get( 'drive_items' );
    $folder_id = metis_drive_normalize_parent_id( $drive_id, $folder_id );
    $seen_ids  = [];
    foreach ( $items as $item ) {
        $item_id = trim( (string) ( $item['id'] ?? '' ) );
        if ( $item_id === '' ) continue;
        $parent_id  = metis_drive_normalize_parent_id( $drive_id, (string) ( ( $item['parents'][0] ?? '' ) ?: $folder_id ) );
        $seen_ids[] = $item_id;
        $db->replace( $table, [
            'drive_id' => $drive_id, 'item_id' => $item_id, 'parent_id' => $parent_id,
            'item_name' => (string) ( $item['name'] ?? '' ), 'mime_type' => (string) ( $item['mimeType'] ?? '' ),
            'is_folder' => (string) ( $item['mimeType'] ?? '' ) === 'application/vnd.google-apps.folder' ? 1 : 0,
            'modified_time' => metis_drive_datetime_from_google( (string) ( $item['modifiedTime'] ?? '' ) ),
            'size_bytes' => isset( $item['size'] ) && $item['size'] !== '' ? (string) $item['size'] : null,
            'web_view_link' => (string) ( $item['webViewLink'] ?? '' ),
            'raw_json' => metis_json_encode( $item ), 'synced_at' => metis_current_time( 'mysql' ),
        ] );
    }
    if ( empty( $seen_ids ) ) {
        $db->delete( $table, [ 'drive_id' => $drive_id, 'parent_id' => $folder_id ] );
        return;
    }
    $placeholders = implode( ',', array_fill( 0, count( $seen_ids ), '%s' ) );
    $db->execute( $db->prepare(
        "DELETE FROM {$table} WHERE drive_id = %s AND parent_id = %s AND item_id NOT IN ({$placeholders})",
        array_merge( [ $drive_id, $folder_id ], $seen_ids )
    ) );
}
