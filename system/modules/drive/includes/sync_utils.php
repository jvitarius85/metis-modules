<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_drive_sync_interval(): int          { return HOUR_IN_SECONDS; }
function metis_drive_cron_interval(): int          { return 30 * MINUTE_IN_SECONDS; }
function metis_drive_cron_stagger_interval(): int  { return 4 * MINUTE_IN_SECONDS; }
function metis_drive_background_sync_interval(): int { return 10 * MINUTE_IN_SECONDS; }
function metis_drive_sync_max_depth(): int         { return 2; }

function metis_drive_bump_response_cache_version(): void {
    metis_update_option( 'metis_drive_cache_version', max( 1, (int) metis_get_option( 'metis_drive_cache_version', 1 ) ) + 1, false );
}

function metis_drive_sync_service_key( string $drive_id ): string {
    return 'drive:' . trim( $drive_id );
}

function metis_drive_cron_task_slug( string $drive_id ): string {
    return 'drive_listing_sync_' . substr( md5( trim( $drive_id ) ), 0, 12 );
}

function metis_drive_prime_cron_task_state( string $slug, int $interval, int $offset ): void {
    $slug = metis_key_clean( $slug );
    if ( $slug === '' ) return;
    $option_key = 'metis_cron_task_state_' . $slug;
    $state      = metis_get_option( $option_key, [] );
    if ( ! is_array( $state ) ) $state = [];
    if ( ! empty( $state['last_finished_at'] ) ) return;
    $interval       = max( 60, $interval );
    $offset         = max( 0, min( $interval - 60, $offset ) );
    $base_timestamp = metis_current_time( 'timestamp' ) - $interval + $offset;
    if ( $base_timestamp < 1 ) $base_timestamp = time() - $interval + $offset;
    $state['last_finished_at'] = date( 'Y-m-d H:i:s', $base_timestamp );
    $state['last_status']      = 'ok';
    $state['running']          = false;
    metis_update_option( $option_key, $state, false );
}

function metis_drive_normalize_parent_id( string $drive_id, string $parent_id ): string {
    $drive_id  = trim( $drive_id );
    $parent_id = trim( $parent_id );
    return $parent_id !== '' ? $parent_id : $drive_id;
}

function metis_drive_datetime_from_google( ?string $value ): ?string {
    $value = is_string( $value ) ? trim( $value ) : '';
    if ( $value === '' ) return null;
    $timestamp = strtotime( $value );
    return $timestamp === false ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
}

function metis_drive_sync_lock_key( string $drive_id, string $folder_id ): string {
    return 'metis_drive_sync_lock_' . md5( $drive_id . '|' . $folder_id );
}

function metis_drive_acquire_sync_lock( string $drive_id, string $folder_id, int $ttl = 300 ): bool {
    $key = metis_drive_sync_lock_key( $drive_id, $folder_id );
    if ( metis_get_transient( $key ) ) return false;
    metis_set_transient( $key, 1, max( 60, $ttl ) );
    return true;
}

function metis_drive_release_sync_lock( string $drive_id, string $folder_id ): void {
    metis_delete_transient( metis_drive_sync_lock_key( $drive_id, $folder_id ) );
}
