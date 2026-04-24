<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Calendar\CalendarModule::boot();

function metis_calendar_can_view(): bool { return \Metis\Modules\Calendar\CalendarModule::canView(); }
function metis_calendar_can_manage(): bool { return \Metis\Modules\Calendar\CalendarModule::canManage(); }
function metis_calendar_workspace_base_settings(): array { return \Metis\Modules\Calendar\CalendarModule::workspaceBaseSettings(); }
function metis_calendar_setting_rows(): array { return \Metis\Modules\Calendar\CalendarModule::settingRows(); }
function metis_calendar_default_setting(): array { return \Metis\Modules\Calendar\CalendarModule::defaultSetting(); }
function metis_calendar_setting_map(): array { return \Metis\Modules\Calendar\CalendarModule::settingMap(); }
function metis_calendar_settings_by_ids(array $calendar_ids): array { return \Metis\Modules\Calendar\CalendarModule::settingsByIds( $calendar_ids ); }
function metis_calendar_setting_config(array $setting): array { return \Metis\Modules\Calendar\CalendarModule::settingConfig( $setting ); }
function metis_calendar_workspace_settings(): array { return \Metis\Modules\Calendar\CalendarModule::workspaceSettings(); }
function metis_calendar_workspace_settings_all(): array { return \Metis\Modules\Calendar\CalendarModule::workspaceSettingsAll(); }
function metis_calendar_b64url_encode(string $value): string { return \Metis\Modules\Calendar\CalendarModule::b64urlEncode( $value ); }
function metis_calendar_google_access_token(array $cfg): array { return \Metis\Modules\Calendar\CalendarModule::googleAccessToken( $cfg ); }
function metis_calendar_google_request(string $method, string $url, ?string $raw_body, array $cfg): array { return \Metis\Modules\Calendar\CalendarModule::googleRequest( $method, $url, $raw_body, $cfg ); }
function metis_calendar_cached_calendar_meta(array $cfg): array { return \Metis\Modules\Calendar\CalendarModule::cachedCalendarMeta( $cfg ); }
function metis_calendar_get_calendar_meta(array $cfg, bool $allow_remote = false): array { return \Metis\Modules\Calendar\CalendarModule::getCalendarMeta( $cfg, $allow_remote ); }
function metis_calendar_list_calendars(array $cfg): array { return \Metis\Modules\Calendar\CalendarModule::listCalendars( $cfg ); }
function metis_calendar_ensure_schema(): void { \Metis\Modules\Calendar\CalendarModule::ensureSchema(); }
function metis_calendar_sync_interval(): int { return \Metis\Modules\Calendar\CalendarModule::syncInterval(); }
function metis_calendar_background_sync_interval(): int { return \Metis\Modules\Calendar\CalendarModule::backgroundSyncInterval(); }
function metis_calendar_sync_lookback_days(): int { return \Metis\Modules\Calendar\CalendarModule::syncLookbackDays(); }
function metis_calendar_sync_lookahead_days(): int { return \Metis\Modules\Calendar\CalendarModule::syncLookaheadDays(); }
function metis_calendar_sync_window_start_ts(): int { return \Metis\Modules\Calendar\CalendarModule::syncWindowStartTs(); }
function metis_calendar_sync_window_end_ts(): int { return \Metis\Modules\Calendar\CalendarModule::syncWindowEndTs(); }
function metis_calendar_sync_service_key(string $calendar_id): string { return \Metis\Modules\Calendar\CalendarModule::syncServiceKey( $calendar_id ); }
function metis_calendar_datetime_from_google(?string $value): ?string { return \Metis\Modules\Calendar\CalendarModule::datetimeFromGoogle( $value ); }
function metis_calendar_event_start_datetime(array $item): ?string { return \Metis\Modules\Calendar\CalendarModule::eventStartDatetime( $item ); }
function metis_calendar_event_end_datetime(array $item): ?string { return \Metis\Modules\Calendar\CalendarModule::eventEndDatetime( $item ); }
function metis_calendar_sync_state(string $calendar_id): array { return \Metis\Modules\Calendar\CalendarModule::syncState( $calendar_id ); }
function metis_calendar_update_sync_state(string $calendar_id, array $data): void { \Metis\Modules\Calendar\CalendarModule::updateSyncState( $calendar_id, $data ); }
function metis_calendar_mark_requested(string $calendar_id, string $calendar_name = ''): void { \Metis\Modules\Calendar\CalendarModule::markRequested( $calendar_id, $calendar_name ); }
function metis_calendar_sync_needs_refresh(string $calendar_id, int $max_age): bool { return \Metis\Modules\Calendar\CalendarModule::syncNeedsRefresh( $calendar_id, $max_age ); }
function metis_calendar_sync_lock_key(string $calendar_id): string { return \Metis\Modules\Calendar\CalendarModule::syncLockKey( $calendar_id ); }
function metis_calendar_acquire_sync_lock(string $calendar_id, int $ttl = 300): bool { return \Metis\Modules\Calendar\CalendarModule::acquireSyncLock( $calendar_id, $ttl ); }
function metis_calendar_release_sync_lock(string $calendar_id): void { \Metis\Modules\Calendar\CalendarModule::releaseSyncLock( $calendar_id ); }
function metis_calendar_google_list_events(array $cfg, string $sync_token = ''): array { return \Metis\Modules\Calendar\CalendarModule::googleListEvents( $cfg, $sync_token ); }
function metis_calendar_delete_cached_event(string $calendar_id, string $event_id): void { \Metis\Modules\Calendar\CalendarModule::deleteCachedEvent( $calendar_id, $event_id ); }
function metis_calendar_store_event(string $calendar_id, array $item): void { \Metis\Modules\Calendar\CalendarModule::storeEvent( $calendar_id, $item ); }
function metis_calendar_replace_cached_events(string $calendar_id, array $items): void { \Metis\Modules\Calendar\CalendarModule::replaceCachedEvents( $calendar_id, $items ); }
function metis_calendar_sync_worker(array $cfg, bool $force = false): array { return \Metis\Modules\Calendar\CalendarModule::syncWorker( $cfg, $force ); }
function metis_calendar_sync_calendar_events(array $cfg, bool $force = false): array { return \Metis\Modules\Calendar\CalendarModule::syncCalendarEvents( $cfg, $force ); }
function metis_calendar_schedule_background_sync(array $cfg, bool $force = false): void { \Metis\Modules\Calendar\CalendarModule::scheduleBackgroundSync( $cfg, $force ); }
function metis_calendar_cached_events(array $cfg, int $start_ts, int $end_ts, string $search = ''): array { return \Metis\Modules\Calendar\CalendarModule::cachedEvents( $cfg, $start_ts, $end_ts, $search ); }
function metis_calendar_sync_all_configured_calendars(): array { return \Metis\Modules\Calendar\CalendarModule::syncAllConfiguredCalendars(); }
