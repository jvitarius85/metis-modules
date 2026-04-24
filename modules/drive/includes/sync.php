<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_drive_google_start_page_token( array $cfg ): array {
    $drive_id = (string) ( $cfg['shared_drive_id'] ?? '' );
    $url = metis_add_query_arg( [ 'supportsAllDrives' => 'true', 'driveId' => $drive_id ], 'https://www.googleapis.com/drive/v3/changes/startPageToken' );
    return metis_drive_google_request( 'GET', $url, null, $cfg );
}

function metis_drive_google_list_folder_children( array $cfg, string $folder_id, string $drive_id ): array {
    $folder_id  = metis_drive_normalize_parent_id( $drive_id, $folder_id );
    $items      = [];
    $page_token = '';

    do {
        $params = [
            'corpora'                   => 'drive',
            'driveId'                   => $drive_id,
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives'         => 'true',
            'useDomainAdminAccess'      => 'true',
            'pageSize'                  => 1000,
            'orderBy'                   => 'folder,name_natural',
            'fields'                    => 'nextPageToken,files(id,name,mimeType,parents,driveId,modifiedTime,size,webViewLink)',
            'q'                         => "trashed = false and '" . str_replace( "'", "\\'", $folder_id ) . "' in parents",
        ];
        if ( $page_token !== '' ) {
            $params['pageToken'] = $page_token;
        }

        $url  = metis_add_query_arg( $params, 'https://www.googleapis.com/drive/v3/files' );
        $resp = metis_drive_google_request( 'GET', $url, null, $cfg );
        if ( empty( $resp['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $resp['error'] ?? 'Google Drive listing request failed.' ) ];
        }

        foreach ( (array) ( $resp['body']['files'] ?? [] ) as $item ) {
            $item_id = trim( (string) ( $item['id'] ?? '' ) );
            if ( $item_id === '' ) {
                continue;
            }

            if ( empty( $item['parents'] ) || ! is_array( $item['parents'] ) ) {
                $item['parents'] = [ $folder_id ];
            }
            if ( empty( $item['driveId'] ) ) {
                $item['driveId'] = $drive_id;
            }

            $items[] = (array) $item;
        }

        $page_token = (string) ( $resp['body']['nextPageToken'] ?? '' );
    } while ( $page_token !== '' );

    return [ 'ok' => true, 'items' => $items ];
}

function metis_drive_sync_worker( array $cfg, string $folder_id, int $depth, bool $force = false ): array {
    metis_drive_ensure_schema();

    if ( empty( $cfg['ok'] ) ) {
        return [ 'ok' => false, 'error' => 'Workspace Drive configuration is unavailable.' ];
    }

    $drive_id = trim( (string) ( $cfg['shared_drive_id'] ?? '' ) );
    if ( $drive_id === '' ) {
        return [ 'ok' => false, 'error' => 'Shared Drive ID is missing.' ];
    }

    $folder_id = metis_drive_normalize_parent_id( $drive_id, $folder_id );
    $max_depth = function_exists( 'metis_drive_sync_max_depth' ) ? max( 0, (int) metis_drive_sync_max_depth() ) : 2;
    $depth     = max( 0, min( $max_depth, (int) $depth ) );

    if ( ! $force ) {
        $max_age = function_exists( 'metis_drive_background_sync_interval' ) ? max( 60, (int) metis_drive_background_sync_interval() ) : ( 10 * MINUTE_IN_SECONDS );
        if ( ! metis_drive_sync_needs_refresh( $drive_id, $folder_id, $max_age ) ) {
            return [
                'ok'         => true,
                'status'     => 'fresh',
                'drive_id'   => $drive_id,
                'folder_id'  => $folder_id,
                'depth'      => $depth,
                'item_count' => 0,
                'folders'    => 0,
            ];
        }
    }

    if ( ! metis_drive_acquire_sync_lock( $drive_id, $folder_id, 300 + ( $depth * 90 ) ) ) {
        return [
            'ok'        => true,
            'status'    => 'locked',
            'drive_id'  => $drive_id,
            'folder_id' => $folder_id,
            'depth'     => $depth,
        ];
    }

    $service_key    = metis_drive_sync_service_key( $drive_id );
    $synced_folders = 0;
    $synced_items   = 0;
    $errors         = [];
    $queue          = [
        [
            'folder_id'        => $folder_id,
            'parent_folder_id' => $drive_id,
            'folder_name'      => (string) ( $cfg['shared_drive_label'] ?? ( $cfg['shared_drive_name'] ?? $drive_id ) ),
            'level'            => 0,
        ],
    ];

    if ( function_exists( 'metis_sync_state_update' ) ) {
        metis_sync_state_update( $service_key, [
            'status'          => 'running',
            'last_started_at' => metis_current_time( 'mysql' ),
            'last_error'      => '',
        ] );
    }

    try {
        for ( $cursor = 0; $cursor < count( $queue ); $cursor++ ) {
            $current = (array) $queue[ $cursor ];

            $current_folder_id = metis_drive_normalize_parent_id( $drive_id, (string) ( $current['folder_id'] ?? '' ) );
            $current_parent_id = metis_drive_normalize_parent_id( $drive_id, (string) ( $current['parent_folder_id'] ?? $drive_id ) );
            $current_name      = (string) ( $current['folder_name'] ?? '' );
            $current_level     = max( 0, (int) ( $current['level'] ?? 0 ) );

            metis_drive_update_sync_state( $drive_id, $current_folder_id, [
                'parent_folder_id'  => $current_parent_id,
                'folder_name'       => $current_name,
                'last_synced_at'    => null,
                'last_requested_at' => metis_current_time( 'mysql' ),
                'sync_status'       => 'running',
                'sync_depth'        => $current_level,
                'item_count'        => 0,
                'last_error'        => '',
            ] );

            $listing = metis_drive_google_list_folder_children( $cfg, $current_folder_id, $drive_id );
            if ( empty( $listing['ok'] ) ) {
                $error_message = (string) ( $listing['error'] ?? 'Drive listing sync failed.' );
                $errors[] = [
                    'folder_id' => $current_folder_id,
                    'error'     => $error_message,
                ];
                metis_drive_update_sync_state( $drive_id, $current_folder_id, [
                    'parent_folder_id'  => $current_parent_id,
                    'folder_name'       => $current_name,
                    'last_synced_at'    => null,
                    'last_requested_at' => metis_current_time( 'mysql' ),
                    'sync_status'       => 'error',
                    'sync_depth'        => $current_level,
                    'item_count'        => 0,
                    'last_error'        => $error_message,
                ] );
                continue;
            }

            $items = (array) ( $listing['items'] ?? [] );
            metis_drive_replace_cached_children( $drive_id, $current_folder_id, $items );

            $synced_folders++;
            $synced_items += count( $items );

            metis_drive_update_sync_state( $drive_id, $current_folder_id, [
                'parent_folder_id'  => $current_parent_id,
                'folder_name'       => $current_name,
                'last_synced_at'    => metis_current_time( 'mysql' ),
                'last_requested_at' => metis_current_time( 'mysql' ),
                'sync_status'       => 'idle',
                'sync_depth'        => $current_level,
                'item_count'        => count( $items ),
                'last_error'        => '',
            ] );

            if ( $current_level >= $depth ) {
                continue;
            }

            foreach ( $items as $item ) {
                if ( (string) ( $item['mimeType'] ?? '' ) !== 'application/vnd.google-apps.folder' ) {
                    continue;
                }

                $child_id = trim( (string) ( $item['id'] ?? '' ) );
                if ( $child_id === '' ) {
                    continue;
                }

                $queue[] = [
                    'folder_id'        => $child_id,
                    'parent_folder_id' => $current_folder_id,
                    'folder_name'      => (string) ( $item['name'] ?? '' ),
                    'level'            => $current_level + 1,
                ];
            }
        }

        if ( function_exists( 'metis_drive_bump_response_cache_version' ) ) {
            metis_drive_bump_response_cache_version();
        }

        $status = empty( $errors ) ? 'synced' : 'partial';

        if ( function_exists( 'metis_sync_state_update' ) ) {
            metis_sync_state_update( $service_key, [
                'status'           => $status,
                'last_finished_at' => metis_current_time( 'mysql' ),
                'last_error'       => empty( $errors ) ? '' : (string) ( $errors[0]['error'] ?? '' ),
            ] );
        }

        return [
            'ok'          => true,
            'status'      => $status,
            'drive_id'    => $drive_id,
            'folder_id'   => $folder_id,
            'depth'       => $depth,
            'folders'     => $synced_folders,
            'item_count'  => $synced_items,
            'error_count' => count( $errors ),
            'errors'      => $errors,
        ];
    } catch ( Throwable $e ) {
        $message = $e->getMessage();
        if ( function_exists( 'metis_sync_state_update' ) ) {
            metis_sync_state_update( $service_key, [
                'status'           => 'error',
                'last_finished_at' => metis_current_time( 'mysql' ),
                'last_error'       => $message,
            ] );
        }
        metis_drive_update_sync_state( $drive_id, $folder_id, [
            'parent_folder_id'  => $drive_id,
            'folder_name'       => (string) ( $cfg['shared_drive_label'] ?? ( $cfg['shared_drive_name'] ?? $drive_id ) ),
            'last_synced_at'    => null,
            'last_requested_at' => metis_current_time( 'mysql' ),
            'sync_status'       => 'error',
            'sync_depth'        => 0,
            'item_count'        => 0,
            'last_error'        => $message,
        ] );
        return [ 'ok' => false, 'error' => $message ];
    } finally {
        metis_drive_release_sync_lock( $drive_id, $folder_id );
    }
}

function metis_drive_sync_folder_listing( array $cfg, string $folder_id = '', int $depth = 0, bool $force = false ): array {
    $drive_id = (string) ( $cfg['shared_drive_id'] ?? '' );
    if ( $folder_id === '' ) {
        $folder_id = $drive_id;
    }
    return metis_drive_sync_worker( $cfg, $folder_id, $depth, $force );
}

function metis_drive_sync_all_configured_drives(): array {
    metis_drive_ensure_schema();

    $configured = metis_drive_configured_drives();
    if ( empty( $configured ) ) {
        return [ 'ok' => false, 'error' => 'No shared drives are configured.' ];
    }

    $results = [];
    foreach ( $configured as $drive ) {
        $drive_id = trim( (string) ( $drive['drive_id'] ?? '' ) );
        if ( $drive_id === '' ) {
            continue;
        }

        $cfg = metis_drive_workspace_settings( $drive_id );
        if ( empty( $cfg['ok'] ) ) {
            $results[ $drive_id ] = [
                'ok'    => false,
                'error' => (string) ( $cfg['error'] ?? 'Drive configuration is unavailable.' ),
            ];
            continue;
        }

        $depth = function_exists( 'metis_drive_sync_max_depth' ) ? (int) metis_drive_sync_max_depth() : 2;
        $results[ $drive_id ] = metis_drive_sync_worker( $cfg, $drive_id, $depth, true );
    }

    $ok = true;
    foreach ( $results as $result ) {
        if ( empty( $result['ok'] ) ) {
            $ok = false;
            break;
        }
    }

    return [
        'ok'     => $ok,
        'count'  => count( $results ),
        'drives' => $results,
    ];
}
