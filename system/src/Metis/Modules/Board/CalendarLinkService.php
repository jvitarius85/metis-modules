<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class CalendarLinkService {
    public static function workspaceLinksSummary( int $meeting_id ): array {
        if ( $meeting_id < 1 ) {
            \metis_runtime_send_json_error( 'Meeting is required.', 422 );
        }

        $db = \metis_db();
        $meetings_table = \Metis_Tables::get( 'board_meetings' );
        $has_calendar_name = \metis_board_table_has_column( $meetings_table, 'google_calendar_event_name' );
        $has_drive_name = \metis_board_table_has_column( $meetings_table, 'google_drive_folder_name' );
        $select_fields = 'id, google_calendar_event_id, google_calendar_html_link, google_drive_folder_id, google_drive_folder_url';
        if ( $has_calendar_name ) {
            $select_fields .= ', google_calendar_event_name';
        }
        if ( $has_drive_name ) {
            $select_fields .= ', google_drive_folder_name';
        }

        $meeting = $db->fetchOne(
            "SELECT {$select_fields}
             FROM {$meetings_table}
             WHERE id = %d
             LIMIT 1",
            [ $meeting_id ]
        );
        if ( ! $meeting ) {
            \metis_runtime_send_json_error( 'Meeting not found.', 404 );
        }

        $calendar_id = trim( (string) ( $meeting['google_calendar_event_id'] ?? '' ) );
        $calendar_link = trim( (string) ( $meeting['google_calendar_html_link'] ?? '' ) );
        $calendar_name = trim( (string) ( $meeting['google_calendar_event_name'] ?? '' ) );
        if ( $calendar_name === '' ) {
            $calendar_name = $calendar_id !== '' ? 'Linked calendar event' : 'Not linked';
        }

        $folder_id = trim( (string) ( $meeting['google_drive_folder_id'] ?? '' ) );
        $folder_link = trim( (string) ( $meeting['google_drive_folder_url'] ?? '' ) );
        $folder_name = trim( (string) ( $meeting['google_drive_folder_name'] ?? '' ) );
        if ( $folder_name === '' ) {
            $folder_name = $folder_id !== '' ? 'Linked Drive folder' : 'Not linked';
        }

        return [
            'meeting_id' => $meeting_id,
            'calendar' => [
                'id' => $calendar_id,
                'name' => $calendar_name,
                'url' => $calendar_link,
            ],
            'folder' => [
                'id' => $folder_id,
                'name' => $folder_name,
                'url' => $folder_link,
            ],
        ];
    }

    public static function listCalendarEvents( int $meeting_id ): array {
        if ( $meeting_id < 1 ) {
            \metis_runtime_send_json_error( 'Meeting is required.', 422 );
        }

        $db = \metis_db();
        $events_table = \Metis_Tables::get( 'calendar_events' );
        $rows = [];
        $seen = [];

        $calendar_ids = [];
        if ( \function_exists( 'metis_calendar_workspace_settings_all' ) ) {
            $workspace = \metis_calendar_workspace_settings_all();
            if ( ! empty( $workspace['ok'] ) && ! empty( $workspace['calendars'] ) && is_array( $workspace['calendars'] ) ) {
                foreach ( $workspace['calendars'] as $cfg ) {
                    if ( ! is_array( $cfg ) ) {
                        continue;
                    }
                    $calendar_id = trim( (string) ( $cfg['calendar_id'] ?? '' ) );
                    if ( $calendar_id === '' ) {
                        continue;
                    }
                    $label = strtolower( trim( (string) ( ( $cfg['calendar_label'] ?? '' ) ?: ( $cfg['calendar_name'] ?? '' ) ) ) );
                    $is_board_calendar = str_contains( $label, 'board' ) || str_contains( strtolower( $calendar_id ), 'board' );
                    if ( $is_board_calendar ) {
                        $calendar_ids[] = $calendar_id;
                    }
                }
            }
        }
        if ( empty( $calendar_ids ) && \function_exists( 'metis_calendar_workspace_settings' ) ) {
            $cfg = \metis_calendar_workspace_settings();
            if ( ! empty( $cfg['ok'] ) ) {
                $calendar_id = trim( (string) ( $cfg['calendar_id'] ?? '' ) );
                if ( $calendar_id !== '' ) {
                    $calendar_ids[] = $calendar_id;
                }
            }
        }

        $calendar_ids = array_values( array_unique( $calendar_ids ) );
        if ( ! empty( $calendar_ids ) && $events_table !== '' ) {
            $now_utc = gmdate( 'Y-m-d H:i:s' );
            $placeholders = implode( ',', array_fill( 0, count( $calendar_ids ), '%s' ) );
            $calendar_source = $db->fetchAll(
                "SELECT event_id, summary, event_start
                 FROM {$events_table}
                 WHERE calendar_id IN ({$placeholders})
                   AND event_id IS NOT NULL AND event_id <> ''
                   AND event_start IS NOT NULL
                   AND event_start >= %s
                   AND COALESCE(event_status, 'confirmed') <> 'cancelled'
                 ORDER BY event_start ASC, id ASC
                 LIMIT 500",
                array_merge( $calendar_ids, [ $now_utc ] )
            ) ?: [];
            foreach ( $calendar_source as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $event_id = trim( (string) ( $item['event_id'] ?? '' ) );
                if ( $event_id === '' || isset( $seen[ $event_id ] ) ) {
                    continue;
                }
                $seen[ $event_id ] = true;
                $summary = trim( (string) ( $item['summary'] ?? '' ) );
                $event_start = trim( (string) ( $item['event_start'] ?? '' ) );
                $label = $summary !== '' ? $summary : 'Calendar event';
                if ( $event_start !== '' ) {
                    $label .= ' · ' . $event_start;
                }
                $rows[] = [ 'id' => $event_id, 'name' => $label ];
            }
        }

        return [
            'meeting_id' => $meeting_id,
            'events' => $rows,
        ];
    }

    public static function listDriveFolders( int $meeting_id ): array {
        if ( $meeting_id < 1 ) {
            \metis_runtime_send_json_error( 'Meeting is required.', 422 );
        }

        $db = \metis_db();
        $items_table = \Metis_Tables::get( 'drive_items' );
        $rows = [];
        $seen = [];

        $drive_ids = [];
        if ( \function_exists( 'metis_drive_configured_drives' ) ) {
            $configured = \metis_drive_configured_drives();
            if ( is_array( $configured ) ) {
                foreach ( $configured as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }
                    $drive_id = trim( (string) ( $row['drive_id'] ?? '' ) );
                    if ( $drive_id !== '' ) {
                        $drive_ids[] = $drive_id;
                    }
                }
            }
        } elseif ( \function_exists( 'metis_drive_workspace_settings' ) ) {
            $cfg = \metis_drive_workspace_settings();
            if ( ! empty( $cfg['ok'] ) ) {
                $drive_id = trim( (string) ( $cfg['shared_drive_id'] ?? '' ) );
                if ( $drive_id !== '' ) {
                    $drive_ids[] = $drive_id;
                }
            }
        }

        $drive_ids = array_values( array_unique( $drive_ids ) );
        if ( ! empty( $drive_ids ) && $items_table !== '' ) {
            $year = gmdate( 'Y' );
            $placeholders = implode( ',', array_fill( 0, count( $drive_ids ), '%s' ) );
            $board_root_source = $db->fetchAll(
                "SELECT item_id, item_name, drive_id
                 FROM {$items_table}
                 WHERE drive_id IN ({$placeholders})
                   AND is_folder = 1
                   AND item_name = %s
                 ORDER BY id DESC
                 LIMIT 100",
                array_merge( $drive_ids, [ '01 Board Meetings' ] )
            ) ?: [];
            $board_root_ids = [];
            foreach ( $board_root_source as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $folder_id = trim( (string) ( $item['item_id'] ?? '' ) );
                if ( $folder_id === '' || isset( $seen[ $folder_id ] ) ) {
                    continue;
                }
                $seen[ $folder_id ] = true;
                $board_root_ids[] = $folder_id;
            }

            if ( ! empty( $board_root_ids ) ) {
                $root_placeholders = implode( ',', array_fill( 0, count( $board_root_ids ), '%s' ) );
                $year_source = $db->fetchAll(
                    "SELECT item_id
                     FROM {$items_table}
                     WHERE is_folder = 1
                       AND item_name = %s
                       AND parent_id IN ({$root_placeholders})
                     ORDER BY id DESC
                     LIMIT 100",
                    array_merge( [ $year ], $board_root_ids )
                ) ?: [];

                $year_ids = [];
                foreach ( $year_source as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $folder_id = trim( (string) ( $item['item_id'] ?? '' ) );
                    if ( $folder_id !== '' ) {
                        $year_ids[] = $folder_id;
                    }
                }

                if ( ! empty( $year_ids ) ) {
                    $year_ids = array_values( array_unique( $year_ids ) );
                    $year_placeholders = implode( ',', array_fill( 0, count( $year_ids ), '%s' ) );
                    $month_source = $db->fetchAll(
                        "SELECT item_id, item_name
                         FROM {$items_table}
                         WHERE is_folder = 1
                           AND parent_id IN ({$year_placeholders})
                           AND item_id IS NOT NULL AND item_id <> ''
                         ORDER BY item_name ASC, id ASC
                         LIMIT 200",
                        $year_ids
                    ) ?: [];
                    foreach ( $month_source as $item ) {
                        if ( ! is_array( $item ) ) {
                            continue;
                        }
                        $folder_id = trim( (string) ( $item['item_id'] ?? '' ) );
                        if ( $folder_id === '' || isset( $seen[ $folder_id ] ) ) {
                            continue;
                        }
                        $seen[ $folder_id ] = true;
                        $name = trim( (string) ( $item['item_name'] ?? '' ) );
                        $rows[] = [ 'id' => $folder_id, 'name' => $name !== '' ? $name : $folder_id ];
                    }
                }
            }

            if ( empty( $rows ) ) {
                $drive_source = $db->fetchAll(
                    "SELECT item_id, item_name, modified_time
                     FROM {$items_table}
                     WHERE drive_id IN ({$placeholders})
                       AND is_folder = 1
                       AND item_id IS NOT NULL AND item_id <> ''
                     ORDER BY modified_time DESC, id DESC
                     LIMIT 50",
                    $drive_ids
                ) ?: [];
                foreach ( $drive_source as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $folder_id = trim( (string) ( $item['item_id'] ?? '' ) );
                    if ( $folder_id === '' || isset( $seen[ $folder_id ] ) ) {
                        continue;
                    }
                    $seen[ $folder_id ] = true;
                    $name = trim( (string) ( $item['item_name'] ?? '' ) );
                    if ( $name === '' ) {
                        $name = 'Drive folder';
                    }
                    $rows[] = [ 'id' => $folder_id, 'name' => $name ];
                }
            }
        }

        return [
            'meeting_id' => $meeting_id,
            'folders' => $rows,
        ];
    }

    public static function assignCalendarEvent( int $meeting_id, string $event_input, string $event_name ): array {
        if ( $meeting_id < 1 || trim( $event_input ) === '' ) {
            \metis_runtime_send_json_error( 'Meeting and calendar event are required.', 422 );
        }

        $event_id = \metis_board_extract_google_id( $event_input, 'calendar_event' );
        if ( $event_id === '' ) {
            \metis_runtime_send_json_error( 'Invalid calendar event ID or URL.', 422 );
        }
        if ( trim( $event_name ) === '' ) {
            $event_name = 'Linked calendar event';
        }

        $db = \metis_db();
        $ok = $db->update(
            \Metis_Tables::get( 'board_meetings' ),
            [
                'google_calendar_event_id' => (string) $event_id,
                'google_calendar_event_name' => (string) $event_name,
            ],
            [ 'id' => $meeting_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            \Metis_Logger::error( 'Board calendar assign failed', [
                'meeting_id' => $meeting_id,
                'event_id' => $event_id,
                'db_error' => $db->lastError(),
            ] );
            \metis_runtime_send_json_error( 'Failed to assign calendar event.', 500 );
        }

        return [
            'meeting_id' => $meeting_id,
            'calendar' => [
                'id' => (string) $event_id,
                'name' => (string) $event_name,
            ],
        ];
    }

    public static function generateCalendarEvent( int $meeting_id ): array {
        if ( $meeting_id < 1 ) {
            \metis_runtime_send_json_error( 'Meeting is required.', 422 );
        }

        $db = \metis_db();
        $meetings_table = \Metis_Tables::get( 'board_meetings' );
        $meeting = $db->fetchOne(
            "SELECT id, meeting_code, title, meeting_date, location, status, google_calendar_event_id
             FROM {$meetings_table}
             WHERE id = %d
             LIMIT 1",
            [ $meeting_id ]
        );
        if ( ! $meeting ) {
            \metis_runtime_send_json_error( 'Meeting not found.', 404 );
        }

        $calendar = \metis_board_upsert_calendar_event_for_meeting( $meeting, true );
        if ( empty( $calendar['ok'] ) || trim( (string) ( $calendar['id'] ?? '' ) ) === '' ) {
            \metis_runtime_send_json_error( 'Failed to generate calendar event.', 500 );
        }

        $db->update(
            $meetings_table,
            [
                'google_calendar_event_id' => (string) $calendar['id'],
                'google_calendar_event_name' => (string) ( $calendar['name'] ?? 'Linked calendar event' ),
                'google_calendar_html_link' => (string) ( $calendar['url'] ?? '' ),
            ],
            [ 'id' => $meeting_id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return [
            'meeting_id' => $meeting_id,
            'calendar' => [
                'id' => (string) $calendar['id'],
                'name' => (string) ( $calendar['name'] ?? 'Linked calendar event' ),
                'url' => (string) ( $calendar['url'] ?? '' ),
            ],
        ];
    }
}
