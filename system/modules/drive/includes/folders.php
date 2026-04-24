<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_drive_users_root_folder_name(): string { return 'Users'; }

function metis_drive_person_folder_display_name( int $person_id = 0, int $account_id = 0 ): string {
    $name = '';
    if ( $person_id > 0 && Metis_Tables::has( 'people' ) ) {
        $person = metis_db()->fetchOne( 'SELECT first_name, last_name, display_name, email FROM ' . Metis_Tables::get( 'people' ) . ' WHERE id = %d LIMIT 1', [ $person_id ] );
        if ( $person ) {
            $name = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
            if ( $name === '' ) $name = trim( (string) ( $person['display_name'] ?? '' ) );
            if ( $name === '' ) $name = trim( (string) ( $person['email'] ?? '' ) );
        }
    }
    if ( $name === '' ) $name = 'User ' . (string) max( 1, $person_id > 0 ? $person_id : $account_id );
    $name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );
    $name = trim( (string) preg_replace( '/[^A-Za-z0-9\.\'\-\s]/', '', $name ) );
    if ( $name === '' ) $name = 'User ' . (string) max( 1, $person_id > 0 ? $person_id : $account_id );
    return $name;
}

function metis_drive_get_file_meta( array $cfg, string $file_id, string $fields = 'id,name,mimeType,parents,driveId,webViewLink' ): array {
    if ( $file_id === '' ) return [ 'ok' => false, 'error' => 'File ID is required.' ];
    $url = metis_add_query_arg( [ 'fields' => $fields, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'useDomainAdminAccess' => 'true' ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) );
    return metis_drive_google_request( 'GET', $url, null, $cfg );
}

function metis_drive_get_users_root_folder_cached( array $cfg ): array {
    $drive_id  = (string) ( $cfg['shared_drive_id'] ?? '' );
    if ( $drive_id === '' ) return [ 'ok' => false, 'error' => 'Shared Drive ID is missing.' ];
    $cache_key = 'metis_drive_users_root_' . md5( $drive_id );
    $cached    = metis_get_transient( $cache_key );
    if ( is_array( $cached ) && ! empty( $cached['folder_id'] ) ) {
        return [ 'ok' => true, 'folder_id' => (string) ( $cached['folder_id'] ?? '' ), 'folder_name' => (string) ( $cached['folder_name'] ?? metis_drive_users_root_folder_name() ), 'created' => false, 'folder' => $cached ];
    }
    $folder_name = metis_drive_users_root_folder_name();
    $items_table = Metis_Tables::get( 'drive_items' );
    $row = metis_db()->fetchOne( "SELECT item_id, item_name FROM {$items_table} WHERE drive_id = %s AND parent_id = %s AND is_folder = 1 AND item_name = %s LIMIT 1", [ $drive_id, $drive_id, $folder_name ] );
    if ( is_array( $row ) && ! empty( $row['item_id'] ) ) {
        $payload = [ 'folder_id' => (string) ( $row['item_id'] ?? '' ), 'folder_name' => (string) ( $row['item_name'] ?? $folder_name ) ];
        metis_set_transient( $cache_key, $payload, 10 * MINUTE_IN_SECONDS );
        return [ 'ok' => true, 'folder_id' => $payload['folder_id'], 'folder_name' => $payload['folder_name'], 'created' => false, 'folder' => $payload ];
    }
    return [ 'ok' => false, 'error' => 'Users folder not found in cache.' ];
}

function metis_drive_get_users_root_folder( array $cfg, bool $create = false ): array {
    $drive_id  = (string) ( $cfg['shared_drive_id'] ?? '' );
    if ( $drive_id === '' ) return [ 'ok' => false, 'error' => 'Shared Drive ID is missing.' ];
    $root_name = metis_drive_users_root_folder_name();
    $cache_key = 'metis_drive_users_root_' . md5( $drive_id );
    $cached    = metis_drive_get_users_root_folder_cached( $cfg );
    if ( ! $create && ! empty( $cached['ok'] ) ) return $cached;
    $q = "trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace( "'", "\\'", $root_name ) . "' and 'root' in parents";
    $find_url  = metis_add_query_arg( [ 'corpora' => 'drive', 'driveId' => $drive_id, 'includeItemsFromAllDrives' => 'true', 'supportsAllDrives' => 'true', 'useDomainAdminAccess' => 'true', 'q' => $q, 'fields' => 'files(id,name,parents,driveId,webViewLink)', 'pageSize' => 5 ], 'https://www.googleapis.com/drive/v3/files' );
    $find = metis_drive_google_request( 'GET', $find_url, null, $cfg );
    if ( ! empty( $find['ok'] ) && empty( $find['body']['files'] ) ) {
        $q2 = "trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace( "'", "\\'", $root_name ) . "' and '" . str_replace( "'", "\\'", $drive_id ) . "' in parents";
        $fallback = metis_drive_google_request( 'GET', metis_add_query_arg( [ 'corpora' => 'drive', 'driveId' => $drive_id, 'includeItemsFromAllDrives' => 'true', 'supportsAllDrives' => 'true', 'useDomainAdminAccess' => 'true', 'q' => $q2, 'fields' => 'files(id,name,parents,driveId,webViewLink)', 'pageSize' => 5 ], 'https://www.googleapis.com/drive/v3/files' ), null, $cfg );
        if ( ! empty( $fallback['ok'] ) ) $find = $fallback;
    }
    if ( empty( $find['ok'] ) ) return [ 'ok' => false, 'error' => 'Failed to lookup Users folder.' ];
    $existing = (array) ( $find['body']['files'][0] ?? [] );
    if ( ! empty( $existing['id'] ) ) {
        metis_set_transient( $cache_key, [ 'folder_id' => (string) $existing['id'], 'folder_name' => (string) ( $existing['name'] ?? $root_name ) ], 10 * MINUTE_IN_SECONDS );
        return [ 'ok' => true, 'folder_id' => (string) $existing['id'], 'folder_name' => (string) ( $existing['name'] ?? $root_name ), 'created' => false, 'folder' => $existing ];
    }
    if ( ! $create ) return [ 'ok' => false, 'error' => 'Users folder not found.' ];
    $create_resp = metis_drive_google_request( 'POST', metis_add_query_arg( [ 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'useDomainAdminAccess' => 'true', 'fields' => 'id,name,parents,driveId,webViewLink' ], 'https://www.googleapis.com/drive/v3/files' ), metis_json_encode( [ 'name' => $root_name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [ $drive_id ] ] ), $cfg );
    if ( empty( $create_resp['ok'] ) || empty( $create_resp['body']['id'] ) ) return [ 'ok' => false, 'error' => 'Failed to create Users folder.' ];
    metis_set_transient( $cache_key, [ 'folder_id' => (string) ( $create_resp['body']['id'] ?? '' ), 'folder_name' => (string) ( $create_resp['body']['name'] ?? $root_name ) ], 10 * MINUTE_IN_SECONDS );
    return [ 'ok' => true, 'folder_id' => (string) $create_resp['body']['id'], 'folder_name' => (string) ( $create_resp['body']['name'] ?? $root_name ), 'created' => true, 'folder' => (array) ( $create_resp['body'] ?? [] ) ];
}

function metis_drive_get_user_folder_mapping( array $cfg, int $person_id = 0 ): ?array {
    $table = Metis_Tables::get( 'drive_user_folders' );
    if ( ! $table ) return null;
    if ( $person_id < 1 ) $person_id = metis_drive_current_person_id();
    $drive_id = (string) ( $cfg['shared_drive_id'] ?? '' );
    if ( $drive_id === '' ) return null;
    $row = $person_id > 0 ? metis_db()->fetchOne( "SELECT * FROM {$table} WHERE drive_id = %s AND person_id = %d LIMIT 1", [ $drive_id, $person_id ] ) : null;
    return is_array( $row ) ? $row : null;
}

function metis_drive_get_user_folder_mapping_any( int $person_id = 0, string $preferred_drive_id = '' ): ?array {
    $table = Metis_Tables::get( 'drive_user_folders' );
    if ( ! $table ) return null;
    if ( $person_id < 1 ) $person_id = metis_drive_current_person_id();
    if ( $person_id < 1 ) return null;
    $db = metis_db();
    if ( $preferred_drive_id !== '' ) {
        $row = $db->fetchOne( "SELECT * FROM {$table} WHERE person_id = %d AND drive_id = %s LIMIT 1", [ $person_id, $preferred_drive_id ] );
        if ( is_array( $row ) ) return $row;
    }
    $row = $db->fetchOne( "SELECT * FROM {$table} WHERE person_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1", [ $person_id ] );
    return is_array( $row ) ? $row : null;
}

function metis_drive_resolve_user_folder_context( int $person_id = 0 ): array {
    if ( $person_id < 1 ) $person_id = metis_drive_current_person_id();
    if ( $person_id < 1 ) return [ 'ok' => false, 'error' => 'Person could not be resolved.' ];
    $preferred = trim( (string) ( metis_drive_users_home_setting()['drive_id'] ?? '' ) );
    $mapping   = metis_drive_get_user_folder_mapping_any( $person_id, $preferred );
    if ( ! is_array( $mapping ) || empty( $mapping['drive_id'] ) ) return [ 'ok' => false, 'error' => 'No mapped folder was found for this person.' ];
    $cfg = metis_drive_workspace_settings( (string) $mapping['drive_id'] );
    if ( empty( $cfg['ok'] ) ) return [ 'ok' => false, 'error' => 'Drive config missing.' ];
    return [ 'ok' => true, 'cfg' => $cfg, 'mapping' => $mapping ];
}

function metis_drive_upsert_user_folder_mapping( array $cfg, int $person_id, string $folder_id, string $folder_name, string $parent_folder_id ): void {
    $table = Metis_Tables::get( 'drive_user_folders' );
    if ( ! $table ) return;
    $db          = metis_db();
    $existing_id = $person_id > 0 ? (int) $db->scalar( "SELECT id FROM {$table} WHERE drive_id = %s AND person_id = %d LIMIT 1", [ (string) ( $cfg['shared_drive_id'] ?? '' ), $person_id ] ) : 0;
    $payload = [
        'drive_id' => (string) ( $cfg['shared_drive_id'] ?? '' ), 'person_id' => $person_id > 0 ? $person_id : null,
        'folder_id' => $folder_id, 'folder_name' => $folder_name, 'parent_folder_id' => $parent_folder_id, 'updated_at' => metis_current_time( 'mysql' ),
    ];
    if ( $existing_id > 0 ) { $db->update( $table, $payload, [ 'id' => $existing_id ] ); return; }
    $payload['created_at'] = metis_current_time( 'mysql' );
    $db->insert( $table, $payload );
}

function metis_drive_find_cached_user_folder( array $cfg, int $person_id = 0, string $users_root_id = '' ): array {
    metis_drive_ensure_schema();
    $drive_id = (string) ( $cfg['shared_drive_id'] ?? '' );
    if ( $drive_id === '' ) return [ 'ok' => false, 'error' => 'Shared Drive ID is missing.' ];
    if ( $person_id < 1 ) $person_id = metis_drive_current_person_id();
    $folder_name  = metis_drive_person_folder_display_name( $person_id, 0 );
    $items_table  = Metis_Tables::get( 'drive_items' );
    $args  = [ $drive_id, $folder_name ];
    $where = [ 'drive_id = %s', 'item_name = %s', "mime_type = 'application/vnd.google-apps.folder'" ];
    if ( $users_root_id !== '' ) { $where[] = 'parent_id = %s'; $args[] = $users_root_id; }
    else { $where[] = '(parent_id = %s OR parent_id = %s)'; $args[] = $drive_id; $args[] = ''; }
    $row = metis_db()->fetchOne( "SELECT item_id, item_name, parent_id, web_view_link FROM {$items_table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY item_id ASC LIMIT 1', $args );
    if ( ! is_array( $row ) || empty( $row['item_id'] ) ) return [ 'ok' => false, 'error' => 'User folder is not cached yet.' ];
    return [ 'ok' => true, 'folder_id' => (string) ( $row['item_id'] ?? '' ), 'folder_name' => (string) ( $row['item_name'] ?? $folder_name ), 'parent_folder_id' => (string) ( $row['parent_id'] ?? ( $users_root_id !== '' ? $users_root_id : $drive_id ) ), 'created' => false, 'source' => 'cache', 'web_view_link' => (string) ( $row['web_view_link'] ?? '' ) ];
}

function metis_drive_folder_is_descendant_of( array $cfg, string $folder_id, string $ancestor_id ): bool {
    if ( $folder_id === '' || $ancestor_id === '' ) return false;
    if ( $folder_id === $ancestor_id ) return true;
    $drive_id  = (string) ( $cfg['shared_drive_id'] ?? '' );
    $cache_key = 'metis_drive_desc_' . md5( $drive_id . '|' . $folder_id . '|' . $ancestor_id );
    $cached    = metis_get_transient( $cache_key );
    if ( is_string( $cached ) ) return $cached === '1';
    $current = $folder_id;
    $seen    = [];
    for ( $depth = 0; $depth < 25; $depth++ ) {
        if ( $current === '' || isset( $seen[ $current ] ) ) { metis_set_transient( $cache_key, '0', 5 * MINUTE_IN_SECONDS ); return false; }
        $seen[ $current ] = true;
        $meta = metis_drive_get_file_meta( $cfg, $current, 'id,parents,driveId,mimeType' );
        if ( empty( $meta['ok'] ) ) { metis_set_transient( $cache_key, '0', MINUTE_IN_SECONDS ); return false; }
        $body   = (array) ( $meta['body'] ?? [] );
        if ( (string) ( $body['driveId'] ?? '' ) !== $drive_id ) { metis_set_transient( $cache_key, '0', 5 * MINUTE_IN_SECONDS ); return false; }
        $parent = (string) ( (array) ( $body['parents'] ?? [] ) )[0] ?? '';
        if ( $parent === '' ) { metis_set_transient( $cache_key, '0', 5 * MINUTE_IN_SECONDS ); return false; }
        if ( $parent === $ancestor_id ) { metis_set_transient( $cache_key, '1', 5 * MINUTE_IN_SECONDS ); return true; }
        if ( $parent === $drive_id ) { metis_set_transient( $cache_key, '0', 5 * MINUTE_IN_SECONDS ); return false; }
        $current = $parent;
    }
    metis_set_transient( $cache_key, '0', 5 * MINUTE_IN_SECONDS );
    return false;
}

function metis_drive_folder_is_descendant_of_cached( array $cfg, string $folder_id, string $ancestor_id ): bool {
    if ( $folder_id === '' || $ancestor_id === '' ) return false;
    if ( $folder_id === $ancestor_id ) return true;
    $drive_id = (string) ( $cfg['shared_drive_id'] ?? '' );
    $current  = $folder_id;
    $seen     = [];
    for ( $depth = 0; $depth < 25; $depth++ ) {
        if ( $current === '' || isset( $seen[ $current ] ) ) return false;
        $seen[ $current ] = true;
        $meta  = metis_drive_cached_item_meta( $drive_id, $current );
        if ( empty( $meta['ok'] ) ) return false;
        $body   = (array) ( $meta['file'] ?? [] );
        if ( (string) ( $body['driveId'] ?? '' ) !== $drive_id ) return false;
        $parent = (string) ( $body['parents'][0] ?? '' );
        if ( $parent === '' ) return false;
        if ( $parent === $ancestor_id ) return true;
        if ( $parent === $drive_id ) return false;
        $current = $parent;
    }
    return false;
}

function metis_drive_user_folder_mapping_fast( array $cfg, int $person_id = 0 ): array {
    $existing = metis_drive_get_user_folder_mapping( $cfg, $person_id );
    if ( ! $existing || empty( $existing['folder_id'] ) ) {
        $users_root = metis_drive_get_users_root_folder_cached( $cfg );
        $cached     = metis_drive_find_cached_user_folder( $cfg, $person_id, (string) ( $users_root['folder_id'] ?? '' ) );
        if ( empty( $cached['ok'] ) ) return [ 'ok' => false, 'error' => 'User folder is not cached yet.' ];
        $resolved_person_id = $person_id > 0 ? $person_id : metis_drive_current_person_id();
        if ( $resolved_person_id > 0 ) {
            metis_drive_upsert_user_folder_mapping( $cfg, $resolved_person_id, (string) ( $cached['folder_id'] ?? '' ), (string) ( $cached['folder_name'] ?? '' ), (string) ( $cached['parent_folder_id'] ?? '' ) );
        }
        return $cached;
    }
    return [ 'ok' => true, 'folder_id' => (string) ( $existing['folder_id'] ?? '' ), 'folder_name' => (string) ( $existing['folder_name'] ?? '' ), 'parent_folder_id' => (string) ( $existing['parent_folder_id'] ?? ( (string) ( $cfg['shared_drive_id'] ?? '' ) ) ), 'created' => false, 'source' => 'mapping', 'web_view_link' => '' ];
}

function metis_drive_find_or_create_user_folder( array $cfg, int $person_id = 0, bool $create = true ): array {
    $drive_id = (string) ( $cfg['shared_drive_id'] ?? '' );
    if ( $drive_id === '' ) return [ 'ok' => false, 'error' => 'Shared Drive ID is missing.' ];
    if ( $person_id < 1 ) $person_id = metis_drive_current_person_id();
    $users_root        = metis_drive_get_users_root_folder_cached( $cfg );
    $users_root_id     = ! empty( $users_root['ok'] ) ? (string) ( $users_root['folder_id'] ?? '' ) : '';
    $expected_parent_id = $users_root_id !== '' ? $users_root_id : $drive_id;
    $existing = metis_drive_get_user_folder_mapping( $cfg, $person_id );
    if ( $existing && ! empty( $existing['folder_id'] ) ) {
        $folder_id = (string) $existing['folder_id'];
        $meta = metis_drive_get_file_meta( $cfg, $folder_id, 'id,name,mimeType,parents,driveId,webViewLink' );
        if ( ! empty( $meta['ok'] ) ) {
            $body      = (array) ( $meta['body'] ?? [] );
            $parent_id = (string) ( ( $body['parents'][0] ?? '' ) ?: ( $existing['parent_folder_id'] ?? '' ) );
            if ( (string) ( $body['driveId'] ?? '' ) === $drive_id && (string) ( $body['mimeType'] ?? '' ) === 'application/vnd.google-apps.folder' && $parent_id === $expected_parent_id ) {
                return [ 'ok' => true, 'folder_id' => $folder_id, 'folder_name' => (string) ( ( $body['name'] ?? '' ) ?: ( $existing['folder_name'] ?? '' ) ), 'parent_folder_id' => $parent_id, 'created' => false, 'source' => 'mapping', 'web_view_link' => (string) ( $body['webViewLink'] ?? '' ) ];
            }
        }
    }
    $folder_name   = metis_drive_person_folder_display_name( $person_id, 0 );
    $cached_folder = metis_drive_find_cached_user_folder( $cfg, $person_id, $users_root_id );
    if ( ! empty( $cached_folder['ok'] ) ) {
        metis_drive_upsert_user_folder_mapping( $cfg, $person_id, (string) ( $cached_folder['folder_id'] ?? '' ), (string) ( $cached_folder['folder_name'] ?? $folder_name ), (string) ( $cached_folder['parent_folder_id'] ?? ( $users_root_id !== '' ? $users_root_id : $drive_id ) ) );
        return $cached_folder;
    }
    $q = "trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '" . str_replace( "'", "\\'", $folder_name ) . "' and '" . str_replace( "'", "\\'", $expected_parent_id ) . "' in parents";
    $find = metis_drive_google_request( 'GET', metis_add_query_arg( [ 'corpora' => 'drive', 'driveId' => $drive_id, 'includeItemsFromAllDrives' => 'true', 'supportsAllDrives' => 'true', 'useDomainAdminAccess' => 'true', 'q' => $q, 'fields' => 'files(id,name,parents,driveId,webViewLink)', 'pageSize' => 5 ], 'https://www.googleapis.com/drive/v3/files' ), null, $cfg );
    if ( empty( $find['ok'] ) ) return [ 'ok' => false, 'error' => 'Failed to lookup user folder.' ];
    $folder  = (array) ( $find['body']['files'][0] ?? [] );
    $created = false;
    if ( empty( $folder['id'] ) ) {
        if ( ! $create ) return [ 'ok' => false, 'error' => 'User folder not found.' ];
        $create_resp = metis_drive_google_request( 'POST', metis_add_query_arg( [ 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'useDomainAdminAccess' => 'true', 'fields' => 'id,name,parents,driveId,webViewLink' ], 'https://www.googleapis.com/drive/v3/files' ), metis_json_encode( [ 'name' => $folder_name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [ $expected_parent_id ] ] ), $cfg );
        if ( empty( $create_resp['ok'] ) || empty( $create_resp['body']['id'] ) ) return [ 'ok' => false, 'error' => 'Failed to create user folder.' ];
        $folder  = (array) ( $create_resp['body'] ?? [] );
        $created = true;
    }
    $folder_id         = (string) ( $folder['id'] ?? '' );
    $resolved_name     = (string) ( ( $folder['name'] ?? '' ) ?: $folder_name );
    $resolved_parent   = (string) ( ( $folder['parents'][0] ?? '' ) ?: $expected_parent_id );
    metis_drive_upsert_user_folder_mapping( $cfg, $person_id, $folder_id, $resolved_name, $resolved_parent );
    return [ 'ok' => true, 'folder_id' => $folder_id, 'folder_name' => $resolved_name, 'parent_folder_id' => $resolved_parent, 'created' => $created, 'source' => $created ? 'created' : 'lookup', 'web_view_link' => (string) ( $folder['webViewLink'] ?? '' ) ];
}
