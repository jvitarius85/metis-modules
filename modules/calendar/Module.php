<?php
declare(strict_types=1);

namespace Metis\Modules\Calendar;

final class CalendarModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Calendar bootstrap loaded' );
        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );

        if ( \Metis\Core\Application::has_service( 'job_workers' ) ) {
            \metis_job_workers()->register(
                'calendar.sync',
                static function ( array $payload ): array {
                    $cfg = (array) ( $payload['cfg'] ?? [] );
                    $force = ! empty( $payload['force'] );
                    return self::syncWorker( $cfg, $force );
                }
            );
        }

        if ( class_exists( 'Metis_Cron_Manager' ) ) {
            \Metis_Cron_Manager::register_task(
                'calendar_listing_sync',
                static function (): array {
                    return self::syncAllConfiguredCalendars();
                },
                [
                    'label'    => 'Calendar Listing Sync',
                    'interval' => SyncStore::syncInterval(),
                    'lock_ttl' => 4 * MINUTE_IN_SECONDS,
                    'module'   => 'calendar',
                ]
            );
        }
    }

    public static function canView(): bool { return Access::canView(); }
    public static function canManage(): bool { return Access::canManage(); }
    public static function workspaceBaseSettings(): array { return Settings::workspaceBaseSettings(); }
    public static function settingRows(): array { return Settings::settingRows(); }
    public static function defaultSetting(): array { return Settings::defaultSetting(); }
    public static function settingMap(): array { return Settings::settingMap(); }
    public static function settingsByIds( array $calendar_ids ): array { return Settings::settingsByIds( $calendar_ids ); }
    public static function settingConfig( array $setting ): array { return Settings::settingConfig( $setting ); }
    public static function workspaceSettings(): array { return Settings::workspaceSettings(); }
    public static function workspaceSettingsAll(): array { return Settings::workspaceSettingsAll(); }
    public static function b64urlEncode( string $value ): string { return GoogleCalendarService::b64urlEncode( $value ); }
    public static function googleAccessToken( array $cfg ): array { return GoogleCalendarService::googleAccessToken( $cfg ); }
    public static function googleRequest( string $method, string $url, ?string $raw_body, array $cfg ): array { return GoogleCalendarService::googleRequest( $method, $url, $raw_body, $cfg ); }
    public static function cachedCalendarMeta( array $cfg ): array { return SyncStore::cachedCalendarMeta( $cfg ); }
    public static function getCalendarMeta( array $cfg, bool $allow_remote = false ): array { return GoogleCalendarService::getCalendarMeta( $cfg, $allow_remote ); }
    public static function listCalendars( array $cfg ): array { return GoogleCalendarService::listCalendars( $cfg ); }
    public static function ensureSchema(): void { SyncStore::ensureSchema(); }
    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'calendar_schema',
                [ __FILE__, __DIR__ . '/SyncStore.php' ],
                static function (): void {
                    SyncStore::ensureSchema();
                }
            );
            return;
        }

        self::ensureSchema();
    }
    public static function syncInterval(): int { return SyncStore::syncInterval(); }
    public static function backgroundSyncInterval(): int { return SyncStore::backgroundSyncInterval(); }
    public static function syncLookbackDays(): int { return SyncStore::syncLookbackDays(); }
    public static function syncLookaheadDays(): int { return SyncStore::syncLookaheadDays(); }
    public static function syncWindowStartTs(): int { return SyncStore::syncWindowStartTs(); }
    public static function syncWindowEndTs(): int { return SyncStore::syncWindowEndTs(); }
    public static function syncServiceKey( string $calendar_id ): string { return SyncStore::syncServiceKey( $calendar_id ); }
    public static function datetimeFromGoogle( ?string $value ): ?string { return SyncStore::datetimeFromGoogle( $value ); }
    public static function eventStartDatetime( array $item ): ?string { return SyncStore::eventStartDatetime( $item ); }
    public static function eventEndDatetime( array $item ): ?string { return SyncStore::eventEndDatetime( $item ); }
    public static function syncState( string $calendar_id ): array { return SyncStore::syncState( $calendar_id ); }
    public static function updateSyncState( string $calendar_id, array $data ): void { SyncStore::updateSyncState( $calendar_id, $data ); }
    public static function markRequested( string $calendar_id, string $calendar_name = '' ): void { SyncStore::markRequested( $calendar_id, $calendar_name ); }
    public static function syncNeedsRefresh( string $calendar_id, int $max_age ): bool { return SyncStore::syncNeedsRefresh( $calendar_id, $max_age ); }
    public static function syncLockKey( string $calendar_id ): string { return SyncStore::syncLockKey( $calendar_id ); }
    public static function acquireSyncLock( string $calendar_id, int $ttl = 300 ): bool { return SyncStore::acquireSyncLock( $calendar_id, $ttl ); }
    public static function releaseSyncLock( string $calendar_id ): void { SyncStore::releaseSyncLock( $calendar_id ); }
    public static function googleListEvents( array $cfg, string $sync_token = '' ): array { return GoogleCalendarService::googleListEvents( $cfg, $sync_token ); }
    public static function deleteCachedEvent( string $calendar_id, string $event_id ): void { SyncStore::deleteCachedEvent( $calendar_id, $event_id ); }
    public static function storeEvent( string $calendar_id, array $item ): void { SyncStore::storeEvent( $calendar_id, $item ); }
    public static function replaceCachedEvents( string $calendar_id, array $items ): void { SyncStore::replaceCachedEvents( $calendar_id, $items ); }
    public static function cachedEvents( array $cfg, int $start_ts, int $end_ts, string $search = '' ): array { return SyncStore::cachedEvents( $cfg, $start_ts, $end_ts, $search ); }

    public static function syncWorker( array $cfg, bool $force = false ): array {
        SyncStore::ensureSchema();

        $calendar_id = (string) ( $cfg['calendar_id'] ?? '' );
        if ( $calendar_id === '' ) {
            return [ 'ok' => false, 'error' => 'Calendar ID is missing.' ];
        }

        if ( ! $force && ! SyncStore::syncNeedsRefresh( $calendar_id, SyncStore::backgroundSyncInterval() ) ) {
            return [ 'ok' => true, 'status' => 'fresh' ];
        }

        if ( ! SyncStore::acquireSyncLock( $calendar_id, SyncStore::syncInterval() ) ) {
            return [ 'ok' => true, 'status' => 'locked' ];
        }

        try {
            $calendar_meta  = GoogleCalendarService::getCalendarMeta( $cfg, true );
            $calendar_name  = (string) ( $calendar_meta['summary'] ?? ( $cfg['calendar_name'] ?? '' ) );
            $existing_state = SyncStore::syncState( $calendar_id );
            $service_state  = \metis_sync_state_get( SyncStore::syncServiceKey( $calendar_id ) );
            $sync_token     = ! $force ? trim( (string) ( $service_state['sync_token'] ?? '' ) ) : '';

            SyncStore::updateSyncState( $calendar_id, [
                'calendar_name'     => $calendar_name,
                'last_synced_at'    => (string) ( $existing_state['last_synced_at'] ?? '' ),
                'last_requested_at' => \metis_current_time( 'mysql' ),
                'sync_status'       => 'running',
                'item_count'        => 0,
                'last_error'        => '',
            ] );

            $listing = GoogleCalendarService::googleListEvents( $cfg, $sync_token );
            if ( empty( $listing['ok'] ) && (int) ( $listing['status'] ?? 0 ) === 410 && $sync_token !== '' ) {
                \metis_sync_state_update( SyncStore::syncServiceKey( $calendar_id ), [
                    'last_sync'  => (string) ( $service_state['last_sync'] ?? '' ),
                    'sync_token' => '',
                ] );
                $sync_token = '';
                $listing    = GoogleCalendarService::googleListEvents( $cfg, '' );
            }

            if ( empty( $listing['ok'] ) ) {
                SyncStore::updateSyncState( $calendar_id, [
                    'calendar_name'     => $calendar_name,
                    'last_synced_at'    => \metis_current_time( 'mysql' ),
                    'last_requested_at' => \metis_current_time( 'mysql' ),
                    'sync_status'       => 'error',
                    'item_count'        => 0,
                    'last_error'        => 'Failed to sync calendar events.',
                ] );
                return $listing;
            }

            $items = (array) ( $listing['items'] ?? [] );
            if ( $sync_token === '' ) {
                SyncStore::replaceCachedEvents( $calendar_id, $items );
            } else {
                foreach ( $items as $item ) {
                    $event    = (array) $item;
                    $event_id = trim( (string) ( $event['id'] ?? '' ) );
                    $status   = \metis_key_clean( (string) ( $event['status'] ?? 'confirmed' ) ) ?: 'confirmed';
                    if ( $event_id === '' ) {
                        continue;
                    }
                    if ( $status === 'cancelled' ) {
                        SyncStore::deleteCachedEvent( $calendar_id, $event_id );
                        continue;
                    }
                    SyncStore::storeEvent( $calendar_id, $event );
                }
            }

            $events_table = \Metis_Tables::get( 'calendar_events' );
            $cached_count = (int) \metis_db()->scalar( "SELECT COUNT(1) FROM {$events_table} WHERE calendar_id = %s", [ $calendar_id ] );

            \metis_sync_state_update( SyncStore::syncServiceKey( $calendar_id ), [
                'last_sync'  => \metis_current_time( 'mysql' ),
                'sync_token' => (string) ( $listing['next_sync_token'] ?? $sync_token ),
            ] );

            SyncStore::updateSyncState( $calendar_id, [
                'calendar_name'     => $calendar_name,
                'last_synced_at'    => \metis_current_time( 'mysql' ),
                'last_requested_at' => \metis_current_time( 'mysql' ),
                'sync_status'       => 'idle',
                'item_count'        => $cached_count,
                'last_error'        => '',
            ] );

            return [ 'ok' => true, 'status' => 'synced', 'calendar_id' => $calendar_id, 'item_count' => $cached_count ];
        } finally {
            SyncStore::releaseSyncLock( $calendar_id );
        }
    }

    public static function syncCalendarEvents( array $cfg, bool $force = false ): array {
        return self::syncWorker( $cfg, $force );
    }

    public static function scheduleBackgroundSync( array $cfg, bool $force = false ): void {
        static $scheduled = [];

        $calendar_id = (string) ( $cfg['calendar_id'] ?? '' );
        if ( $calendar_id === '' ) {
            return;
        }

        $key = $calendar_id . '|' . ( $force ? '1' : '0' );
        if ( isset( $scheduled[ $key ] ) ) {
            return;
        }
        $scheduled[ $key ] = true;

        if ( ! $force && ! SyncStore::syncNeedsRefresh( $calendar_id, SyncStore::backgroundSyncInterval() ) ) {
            return;
        }

        if ( \Metis\Core\Application::has_service( 'jobs' ) ) {
            \metis_job_queue()->enqueue(
                'calendar.sync',
                [
                    'cfg'   => $cfg,
                    'force' => $force,
                ],
                [
                    'queue'        => 'calendar',
                    'dedupe_key'   => 'calendar.sync:' . $calendar_id,
                    'max_attempts' => 2,
                    'priority'     => 30,
                ]
            );
            return;
        }

        register_shutdown_function(
            static function () use ( $cfg, $calendar_id, $force ): void {
                self::syncWorker( $cfg, $force );
            }
        );
    }

    public static function syncAllConfiguredCalendars(): array {
        $workspace = Settings::workspaceSettingsAll();
        if ( empty( $workspace['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace config missing.' ];
        }

        $results = [];
        foreach ( (array) ( $workspace['calendars'] ?? [] ) as $cfg ) {
            $calendar_id = (string) ( $cfg['calendar_id'] ?? '' );
            if ( $calendar_id === '' ) {
                continue;
            }
            $results[ $calendar_id ] = self::syncWorker( $cfg, true );
        }

        return [ 'ok' => true, 'count' => count( $results ), 'calendars' => $results ];
    }

    public static function dashboardWidgets( array $context = [] ): array {
        return [
            [
                'key' => 'calendar',
                'title' => 'Calendar',
                'desc' => 'Workspace calendar readiness and connected listings.',
                'url' => \metis_portal_url( 'calendar', 'dashboard' ),
                'metrics' => (array) ( $context['calendar_metrics'] ?? [] ),
                'priority' => 70,
                'updated' => \metis_current_datetime()->format( 'M j, g:i a' ),
            ],
        ];
    }
}
