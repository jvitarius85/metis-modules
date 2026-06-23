<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class MeetingWorkflowService {
    public static function syncDecisionPoints( int $meeting_id, array $post ): array {
        if ( $meeting_id < 1 ) {
            \metis_runtime_send_json_error( 'Meeting is required.', 422 );
        }

        $agenda = null;
        if ( array_key_exists( 'agenda_json', $post ) ) {
            $agenda_json_raw = trim( (string) \metis_runtime_unslash( $post['agenda_json'] ?? '' ) );
            if ( $agenda_json_raw !== '' ) {
                $decoded = json_decode( $agenda_json_raw, true );
                if ( ! is_array( $decoded ) ) {
                    \metis_runtime_send_json_error( 'Agenda JSON is invalid.', 422 );
                }
                $agenda = $decoded;
            } else {
                $agenda = [];
            }
        } else {
            $meetings_table = \Metis_Tables::get( 'board_meetings' );
            $agenda_json_raw = (string) \metis_db()->scalar(
                "SELECT agenda_json FROM {$meetings_table} WHERE id = %d LIMIT 1",
                [ $meeting_id ]
            );
            $decoded = json_decode( $agenda_json_raw, true );
            $agenda = is_array( $decoded ) ? $decoded : [];
        }

        $created = \function_exists( 'metis_board_sync_decision_points' ) ? \metis_board_sync_decision_points( $meeting_id, $agenda ) : 0;
        return [
            'meeting_id' => $meeting_id,
            'created_decisions' => $created,
        ];
    }

    public static function updateMeetingDetail( int $meeting_id, array $post ): array {
        if ( $meeting_id < 1 ) {
            \metis_runtime_send_json_error( 'Meeting is required.', 422 );
        }

        $db = \metis_db();
        $table = \Metis_Tables::get( 'board_meetings' );
        $existing_meeting = $db->fetchOne( "SELECT id, published_at FROM {$table} WHERE id = %d LIMIT 1", [ $meeting_id ] );
        if ( ! $existing_meeting ) {
            \metis_runtime_send_json_error( 'Meeting not found.', 404 );
        }
        $was_published = trim( (string) ( $existing_meeting['published_at'] ?? '' ) ) !== '';
        $payload = [];
        $formats = [];
        $setup_changed = false;
        $send_publish_email = false;

        if ( array_key_exists( 'title', $post ) ) {
            $title = \metis_text_clean( \metis_runtime_unslash( $post['title'] ?? '' ) );
            if ( $title === '' ) {
                \metis_runtime_send_json_error( 'Meeting title is required.', 422 );
            }
            $payload['title'] = $title;
            $formats[] = '%s';
            $setup_changed = true;
        }

        if ( array_key_exists( 'meeting_date', $post ) ) {
            $meeting_date_raw = \metis_text_clean( \metis_runtime_unslash( $post['meeting_date'] ?? '' ) );
            if ( $meeting_date_raw === '' ) {
                \metis_runtime_send_json_error( 'Meeting date is required.', 422 );
            }
            $meeting_ts = strtotime( $meeting_date_raw );
            if ( ! $meeting_ts ) {
                \metis_runtime_send_json_error( 'Invalid meeting date.', 422 );
            }
            $payload['meeting_date'] = gmdate( 'Y-m-d H:i:s', $meeting_ts );
            $formats[] = '%s';
            $setup_changed = true;
        }

        if ( array_key_exists( 'meeting_type', $post ) ) {
            $meeting_type = \metis_key_clean( \metis_runtime_unslash( $post['meeting_type'] ?? 'board' ) );
            if ( ! in_array( $meeting_type, [ 'board', 'committee', 'special' ], true ) ) {
                $meeting_type = 'board';
            }
            $payload['meeting_type'] = $meeting_type;
            $formats[] = '%s';
            $setup_changed = true;
        }

        if ( array_key_exists( 'location', $post ) ) {
            $payload['location'] = \metis_text_clean( \metis_runtime_unslash( $post['location'] ?? '' ) );
            $formats[] = '%s';
            $setup_changed = true;
        }

        if ( array_key_exists( 'status', $post ) ) {
            $status = \metis_key_clean( \metis_runtime_unslash( $post['status'] ?? 'draft' ) );
            if ( ! in_array( $status, [ 'draft', 'scheduled', 'completed', 'cancelled' ], true ) ) {
                $status = 'draft';
            }
            $payload['status'] = $status;
            $formats[] = '%s';
            $setup_changed = true;
        }

        if ( array_key_exists( 'agenda_json', $post ) ) {
            $agenda_json_raw = trim( (string) \metis_runtime_unslash( $post['agenda_json'] ?? '' ) );
            if ( $agenda_json_raw === '' ) {
                $payload['agenda_json'] = null;
                $formats[] = '%s';
            } else {
                json_decode( $agenda_json_raw, true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    \metis_runtime_send_json_error( 'Agenda JSON is invalid.', 422 );
                }
                $payload['agenda_json'] = $agenda_json_raw;
                $formats[] = '%s';
            }
        }

        if ( array_key_exists( 'minutes_html', $post ) ) {
            $payload['minutes_html'] = \metis_runtime_kses_post( \metis_runtime_unslash( $post['minutes_html'] ?? '' ) );
            $formats[] = '%s';
        }

        if ( array_key_exists( 'board_packet_notes', $post ) ) {
            $payload['board_packet_notes'] = \metis_runtime_kses_post( \metis_runtime_unslash( $post['board_packet_notes'] ?? '' ) );
            $formats[] = '%s';
        }

        if ( array_key_exists( 'packet_source_minutes_meeting_id', $post ) ) {
            $val = (int) ( $post['packet_source_minutes_meeting_id'] ?? 0 );
            $payload['packet_source_minutes_meeting_id'] = $val > 0 ? $val : null;
            $formats[] = '%d';
        }

        if ( array_key_exists( 'packet_financial_document_id', $post ) ) {
            $val = (int) ( $post['packet_financial_document_id'] ?? 0 );
            $payload['packet_financial_document_id'] = $val > 0 ? $val : null;
            $formats[] = '%d';
        }

        if ( array_key_exists( 'packet_published', $post ) ) {
            $packet_published = (int) ( $post['packet_published'] ?? 0 ) === 1;
            $payload['published_at'] = $packet_published ? \metis_current_time( 'mysql' ) : null;
            $formats[] = '%s';
            if ( $packet_published && ! $was_published ) {
                $send_publish_email = true;
            }
        }

        if ( array_key_exists( 'attendance_locked', $post ) ) {
            $payload['attendance_locked'] = (int) ( $post['attendance_locked'] ?? 0 ) === 1 ? 1 : 0;
            $formats[] = '%d';
        }

        if ( empty( $payload ) ) {
            \metis_runtime_send_json_error( 'No meeting fields to update.', 422 );
        }

        $ok = $db->update( $table, $payload, [ 'id' => $meeting_id ], $formats, [ '%d' ] );
        if ( $ok === false ) {
            \metis_runtime_send_json_error( 'Failed to update meeting detail.', 500 );
        }

        $calendar_sync = null;
        $publish_email = null;
        if ( $setup_changed ) {
            $sync_calendar = ! array_key_exists( 'sync_calendar_event', $post ) || (int) ( $post['sync_calendar_event'] ?? 1 ) === 1;
            if ( $sync_calendar ) {
                $meeting_row = $db->fetchOne(
                    "SELECT id, meeting_code, title, meeting_date, location, status, google_calendar_event_id
                     FROM {$table}
                     WHERE id = %d
                     LIMIT 1",
                    [ $meeting_id ]
                );
                if ( $meeting_row && trim( (string) ( $meeting_row['google_calendar_event_id'] ?? '' ) ) !== '' ) {
                    $calendar = \metis_board_upsert_calendar_event_for_meeting( $meeting_row, false );
                    if ( ! empty( $calendar['ok'] ) && trim( (string) ( $calendar['id'] ?? '' ) ) !== '' ) {
                        $calendar_sync = [
                            'ok' => true,
                            'id' => (string) $calendar['id'],
                            'name' => (string) ( $calendar['name'] ?? 'Linked calendar event' ),
                            'url' => (string) ( $calendar['url'] ?? '' ),
                        ];
                        $db->update(
                            $table,
                            [
                                'google_calendar_event_name' => (string) ( $calendar['name'] ?? 'Linked calendar event' ),
                                'google_calendar_html_link' => (string) ( $calendar['url'] ?? '' ),
                            ],
                            [ 'id' => $meeting_id ],
                            [ '%s', '%s' ],
                            [ '%d' ]
                        );
                    } else {
                        $calendar_sync = [
                            'ok' => false,
                            'error' => (string) ( $calendar['error'] ?? 'Failed to update linked calendar event.' ),
                        ];
                    }
                }
            }
        }

        if ( $send_publish_email ) {
            $publish_email = \metis_board_send_packet_publish_email( $meeting_id );
        }

        \metis_portal_dashboard_forget_all();
        return [
            'meeting_id' => $meeting_id,
            'payload' => $payload,
            'created_decisions' => 0,
            'calendar_sync' => $calendar_sync,
            'publish_email' => $publish_email,
        ];
    }
}
