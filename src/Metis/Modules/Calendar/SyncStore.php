<?php
declare(strict_types=1);

namespace Metis\Modules\Calendar;

final class SyncStore {
    private static bool $schema_ready = false;

    public static function ensureSchema(): void {
        if ( self::$schema_ready ) {
            return;
        }

        global $wpdb;
        $charset          = $wpdb->get_charset_collate();
        $events_table     = \Metis_Tables::get( 'calendar_events' );
        $sync_state_table = \Metis_Tables::get( 'calendar_sync_state' );

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $events_sql = "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            calendar_id VARCHAR(191) NOT NULL,
            event_id VARCHAR(191) NOT NULL,
            event_status VARCHAR(32) NOT NULL DEFAULT 'confirmed',
            summary TEXT DEFAULT NULL,
            location TEXT DEFAULT NULL,
            description LONGTEXT DEFAULT NULL,
            event_start DATETIME DEFAULT NULL,
            event_end DATETIME DEFAULT NULL,
            is_all_day TINYINT(1) NOT NULL DEFAULT 0,
            event_type VARCHAR(64) NOT NULL DEFAULT 'general',
            event_module VARCHAR(64) NOT NULL DEFAULT 'general',
            etag VARCHAR(191) DEFAULT NULL,
            google_updated_at DATETIME DEFAULT NULL,
            html_link TEXT DEFAULT NULL,
            raw_json LONGTEXT DEFAULT NULL,
            synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY calendar_event (calendar_id, event_id),
            KEY calendar_start (calendar_id, event_start),
            KEY event_start (event_start),
            KEY google_updated_at (google_updated_at)
        ) {$charset};";
        \dbDelta( $events_sql );

        $sync_state_sql = "CREATE TABLE {$sync_state_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            calendar_id VARCHAR(191) NOT NULL,
            calendar_name VARCHAR(255) DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            last_requested_at DATETIME DEFAULT NULL,
            sync_status VARCHAR(32) NOT NULL DEFAULT 'idle',
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY calendar_id (calendar_id),
            KEY sync_status (sync_status),
            KEY last_synced_at (last_synced_at)
        ) {$charset};";
        \dbDelta( $sync_state_sql );

        self::$schema_ready = true;
    }

    public static function syncInterval(): int {
        return 5 * MINUTE_IN_SECONDS;
    }

    public static function backgroundSyncInterval(): int {
        return 5 * MINUTE_IN_SECONDS;
    }

    public static function syncLookbackDays(): int {
        return 60;
    }

    public static function syncLookaheadDays(): int {
        return 365;
    }

    public static function syncWindowStartTs(): int {
        return strtotime( '-' . self::syncLookbackDays() . ' days midnight' ) ?: time();
    }

    public static function syncWindowEndTs(): int {
        return strtotime( '+' . self::syncLookaheadDays() . ' days 23:59:59' ) ?: time();
    }

    public static function syncServiceKey( string $calendar_id ): string {
        return 'calendar:' . trim( $calendar_id );
    }

    public static function datetimeFromGoogle( ?string $value ): ?string {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( $value === '' ) {
            return null;
        }

        $timestamp = strtotime( $value );
        if ( $timestamp === false ) {
            return null;
        }

        return gmdate( 'Y-m-d H:i:s', $timestamp );
    }

    public static function eventStartDatetime( array $item ): ?string {
        $start     = (array) ( $item['start'] ?? [] );
        $date_time = trim( (string) ( $start['dateTime'] ?? '' ) );
        if ( $date_time !== '' ) {
            return self::datetimeFromGoogle( $date_time );
        }

        $date = trim( (string) ( $start['date'] ?? '' ) );
        if ( $date === '' ) {
            return null;
        }

        return self::datetimeFromGoogle( $date . ' 00:00:00' );
    }

    public static function eventEndDatetime( array $item ): ?string {
        $end       = (array) ( $item['end'] ?? [] );
        $date_time = trim( (string) ( $end['dateTime'] ?? '' ) );
        if ( $date_time !== '' ) {
            return self::datetimeFromGoogle( $date_time );
        }

        $date = trim( (string) ( $end['date'] ?? '' ) );
        if ( $date === '' ) {
            return null;
        }

        return self::datetimeFromGoogle( $date . ' 00:00:00' );
    }

    public static function syncState( string $calendar_id ): array {
        self::ensureSchema();
        global $wpdb;

        $table = \Metis_Tables::get( 'calendar_sync_state' );
        $row   = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE calendar_id = %s LIMIT 1", $calendar_id ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : [];
    }

    public static function updateSyncState( string $calendar_id, array $data ): void {
        self::ensureSchema();
        global $wpdb;

        $table   = \Metis_Tables::get( 'calendar_sync_state' );
        $payload = [
            'calendar_id'       => $calendar_id,
            'calendar_name'     => array_key_exists( 'calendar_name', $data ) ? (string) ( $data['calendar_name'] ?? '' ) : null,
            'last_synced_at'    => array_key_exists( 'last_synced_at', $data ) ? ( $data['last_synced_at'] ?: null ) : null,
            'last_requested_at' => array_key_exists( 'last_requested_at', $data ) ? ( $data['last_requested_at'] ?: null ) : null,
            'sync_status'       => \sanitize_key( (string) ( $data['sync_status'] ?? 'idle' ) ) ?: 'idle',
            'item_count'        => max( 0, (int) ( $data['item_count'] ?? 0 ) ),
            'last_error'        => array_key_exists( 'last_error', $data ) ? (string) ( $data['last_error'] ?? '' ) : null,
            'updated_at'        => \current_time( 'mysql' ),
        ];

        $existing = self::syncState( $calendar_id );
        if ( ! empty( $existing['id'] ) ) {
            $wpdb->update( $table, $payload, [ 'id' => (int) $existing['id'] ], [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ], [ '%d' ] );
            return;
        }

        $wpdb->insert( $table, $payload, [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );
    }

    public static function markRequested( string $calendar_id, string $calendar_name = '' ): void {
        $state = self::syncState( $calendar_id );
        self::updateSyncState( $calendar_id, [
            'calendar_name'     => $calendar_name !== '' ? $calendar_name : (string) ( $state['calendar_name'] ?? '' ),
            'last_synced_at'    => (string) ( $state['last_synced_at'] ?? '' ),
            'last_requested_at' => \current_time( 'mysql' ),
            'sync_status'       => (string) ( $state['sync_status'] ?? 'idle' ),
            'item_count'        => (int) ( $state['item_count'] ?? 0 ),
            'last_error'        => (string) ( $state['last_error'] ?? '' ),
        ] );
    }

    public static function syncNeedsRefresh( string $calendar_id, int $max_age ): bool {
        $state          = self::syncState( $calendar_id );
        $last_synced_at = (string) ( $state['last_synced_at'] ?? '' );
        if ( $last_synced_at === '' ) {
            return true;
        }

        $timestamp = strtotime( $last_synced_at );
        if ( $timestamp === false ) {
            return true;
        }

        return ( time() - $timestamp ) >= max( 60, $max_age );
    }

    public static function syncLockKey( string $calendar_id ): string {
        return 'metis_calendar_sync_lock_' . md5( $calendar_id );
    }

    public static function acquireSyncLock( string $calendar_id, int $ttl = 300 ): bool {
        $key = self::syncLockKey( $calendar_id );
        if ( \get_transient( $key ) ) {
            return false;
        }

        \set_transient( $key, 1, max( 60, $ttl ) );
        return true;
    }

    public static function releaseSyncLock( string $calendar_id ): void {
        \delete_transient( self::syncLockKey( $calendar_id ) );
    }

    public static function deleteCachedEvent( string $calendar_id, string $event_id ): void {
        self::ensureSchema();
        global $wpdb;

        $table = \Metis_Tables::get( 'calendar_events' );
        if ( $calendar_id === '' || $event_id === '' ) {
            return;
        }

        $wpdb->delete( $table, [ 'calendar_id' => $calendar_id, 'event_id' => $event_id ], [ '%s', '%s' ] );
    }

    public static function storeEvent( string $calendar_id, array $item ): void {
        self::ensureSchema();
        global $wpdb;

        $table    = \Metis_Tables::get( 'calendar_events' );
        $now      = \current_time( 'mysql' );
        $event_id = trim( (string) ( $item['id'] ?? '' ) );
        if ( $calendar_id === '' || $event_id === '' ) {
            return;
        }

        $status = \sanitize_key( (string) ( $item['status'] ?? 'confirmed' ) ) ?: 'confirmed';
        if ( $status === 'cancelled' ) {
            self::deleteCachedEvent( $calendar_id, $event_id );
            return;
        }

        $private = (array) ( ( $item['extendedProperties']['private'] ?? [] ) );
        $wpdb->replace(
            $table,
            [
                'calendar_id'       => $calendar_id,
                'event_id'          => $event_id,
                'event_status'      => $status,
                'summary'           => (string) ( $item['summary'] ?? '' ),
                'location'          => (string) ( $item['location'] ?? '' ),
                'description'       => (string) ( $item['description'] ?? '' ),
                'event_start'       => self::eventStartDatetime( $item ),
                'event_end'         => self::eventEndDatetime( $item ),
                'is_all_day'        => ! empty( ( $item['start']['date'] ?? '' ) ) ? 1 : 0,
                'event_type'        => \sanitize_key( (string) ( $private['metis_type'] ?? 'general' ) ) ?: 'general',
                'event_module'      => \sanitize_key( (string) ( $private['metis_module'] ?? 'general' ) ) ?: 'general',
                'etag'              => (string) ( $item['etag'] ?? '' ),
                'google_updated_at' => self::datetimeFromGoogle( (string) ( $item['updated'] ?? '' ) ),
                'html_link'         => (string) ( $item['htmlLink'] ?? '' ),
                'raw_json'          => \metis_json_encode( $item ),
                'synced_at'         => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public static function replaceCachedEvents( string $calendar_id, array $items ): void {
        self::ensureSchema();
        global $wpdb;

        $table = \Metis_Tables::get( 'calendar_events' );
        $wpdb->delete( $table, [ 'calendar_id' => $calendar_id ], [ '%s' ] );

        foreach ( $items as $item ) {
            self::storeEvent( $calendar_id, (array) $item );
        }
    }

    public static function cachedCalendarMeta( array $cfg ): array {
        $calendar_id = (string) ( $cfg['calendar_id'] ?? '' );
        $state       = $calendar_id !== '' ? self::syncState( $calendar_id ) : [];
        $summary     = trim( (string) ( $state['calendar_name'] ?? $cfg['calendar_name'] ?? $cfg['calendar_label'] ?? $calendar_id ) );

        return [
            'ok'        => $summary !== '',
            'summary'   => $summary !== '' ? $summary : $calendar_id,
            'time_zone' => '',
        ];
    }

    public static function cachedEvents( array $cfg, int $start_ts, int $end_ts, string $search = '' ): array {
        self::ensureSchema();
        global $wpdb;

        $table       = \Metis_Tables::get( 'calendar_events' );
        $calendar_id = (string) ( $cfg['calendar_id'] ?? '' );
        if ( $calendar_id === '' ) {
            return [];
        }

        $where = [ 'calendar_id = %s' ];
        $args  = [ $calendar_id ];

        if ( $start_ts > 0 ) {
            $where[] = 'event_start >= %s';
            $args[]  = gmdate( 'Y-m-d H:i:s', $start_ts );
        }

        if ( $end_ts > 0 ) {
            $where[] = 'event_start <= %s';
            $args[]  = gmdate( 'Y-m-d H:i:s', $end_ts );
        }

        if ( $search !== '' ) {
            $where[] = '(summary LIKE %s OR location LIKE %s OR description LIKE %s)';
            $needle  = '%' . $wpdb->esc_like( $search ) . '%';
            $args[]  = $needle;
            $args[]  = $needle;
            $args[]  = $needle;
        }

        $sql      = "SELECT raw_json, event_type, event_module FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY event_start ASC';
        $prepared = $wpdb->prepare( $sql, ...$args );
        $rows     = $wpdb->get_results( $prepared, ARRAY_A ) ?: [];

        $calendar_meta  = self::cachedCalendarMeta( $cfg );
        $calendar_name  = (string) ( $calendar_meta['summary'] ?? ( $cfg['calendar_name'] ?? '' ) );
        $calendar_label = (string) ( $cfg['calendar_label'] ?? '' );
        $items          = [];

        foreach ( $rows as $row ) {
            $item = json_decode( (string) ( $row['raw_json'] ?? '' ), true );
            if ( ! is_array( $item ) ) {
                continue;
            }

            $item['metis_type']    = \sanitize_key( (string) ( $row['event_type'] ?? ( $item['metis_type'] ?? 'general' ) ) );
            $item['metis_module']  = \sanitize_key( (string) ( $row['event_module'] ?? ( $item['metis_module'] ?? 'general' ) ) );
            $item['calendar_id']   = $calendar_id;
            $item['calendar_label']= $calendar_label;
            $item['calendar_name'] = $calendar_name;
            $items[]               = $item;
        }

        return $items;
    }
}
