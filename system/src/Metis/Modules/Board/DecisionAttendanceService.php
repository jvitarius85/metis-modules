<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class DecisionAttendanceService {
    public static function updateDecision( int $decision_id, array $post ): array {
        if ( $decision_id < 1 ) {
            \metis_runtime_send_json_error( 'Decision is required.', 422 );
        }

        $db = \metis_db();
        $table = \Metis_Tables::get( 'board_decisions' );
        $decision_row = $db->fetchOne( "SELECT id, meeting_id FROM {$table} WHERE id = %d LIMIT 1", [ $decision_id ] );
        if ( ! $decision_row ) {
            \metis_runtime_send_json_error( 'Decision not found.', 404 );
        }

        $votes_for = max( 0, (int) ( $post['votes_for'] ?? 0 ) );
        $votes_against = max( 0, (int) ( $post['votes_against'] ?? 0 ) );
        $votes_abstain = max( 0, (int) ( $post['votes_abstain'] ?? 0 ) );
        $vote_assignments_payload = null;

        if ( array_key_exists( 'vote_assignments_json', $post ) ) {
            $raw = trim( (string) \metis_runtime_unslash( $post['vote_assignments_json'] ?? '' ) );
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $people_table = \Metis_Tables::get( 'people' );
                $valid_ids = $db->fetchAll( "SELECT id FROM {$people_table} WHERE status = 'active' AND is_board = 1" ) ?: [];
                $valid_lookup = [];
                foreach ( $valid_ids as $row ) {
                    $pid = (int) ( $row['id'] ?? 0 );
                    if ( $pid > 0 ) {
                        $valid_lookup[ $pid ] = true;
                    }
                }
                $used = [];
                $normalized = [ 'for' => [], 'against' => [], 'abstain' => [] ];
                foreach ( [ 'for', 'against', 'abstain' ] as $bucket ) {
                    $list = isset( $decoded[ $bucket ] ) && is_array( $decoded[ $bucket ] ) ? $decoded[ $bucket ] : [];
                    foreach ( $list as $id_val ) {
                        $pid = (int) $id_val;
                        if ( $pid < 1 || isset( $used[ $pid ] ) || ! isset( $valid_lookup[ $pid ] ) ) {
                            continue;
                        }
                        $used[ $pid ] = true;
                        $normalized[ $bucket ][] = $pid;
                    }
                }
                $votes_for = count( $normalized['for'] );
                $votes_against = count( $normalized['against'] );
                $votes_abstain = count( $normalized['abstain'] );
                $encoded = \metis_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                $vote_assignments_payload = is_string( $encoded ) ? $encoded : '{"for":[],"against":[],"abstain":[]}';
            }
        }

        $eligible_count = self::eligibleBoardCount();
        $required_count = (int) floor( $eligible_count / 2 ) + 1;
        $outcome = 'pending';
        if ( $votes_for >= $required_count ) {
            $outcome = 'approved';
        } elseif ( $votes_against >= $required_count ) {
            $outcome = 'rejected';
        }

        $payload = [
            'outcome' => $outcome,
            'votes_for' => $votes_for,
            'votes_against' => $votes_against,
            'votes_abstain' => $votes_abstain,
            'passed' => $outcome === 'approved' ? 1 : 0,
            'passed_at' => $outcome === 'approved' ? \metis_current_time( 'mysql' ) : null,
        ];
        $formats = [ '%s', '%d', '%d', '%d', '%d', '%s' ];
        if ( $vote_assignments_payload !== null ) {
            $payload['decision_votes_json'] = $vote_assignments_payload;
            $formats[] = '%s';
        }

        $ok = $db->update( $table, $payload, [ 'id' => $decision_id ], $formats, [ '%d' ] );
        if ( $ok === false ) {
            \Metis_Logger::error( 'Board decision update failed', [
                'decision_id' => $decision_id,
                'payload' => $payload,
                'db_error' => $db->lastError(),
            ] );
            \metis_runtime_send_json_error( 'Failed to update decision.', 500 );
        }

        \metis_portal_dashboard_forget_all();
        return [
            'decision_id' => $decision_id,
            'outcome' => $outcome,
            'votes_for' => $votes_for,
            'votes_against' => $votes_against,
            'votes_abstain' => $votes_abstain,
        ];
    }

    public static function upsertAttendance( int $meeting_id, int $person_id, array $post ): array {
        if ( $meeting_id < 1 || $person_id < 1 ) {
            \metis_runtime_send_json_error( 'Meeting and member are required.', 422 );
        }

        $db = \metis_db();
        $meetings_table = \Metis_Tables::get( 'board_meetings' );
        $locked = (int) $db->scalar( "SELECT attendance_locked FROM {$meetings_table} WHERE id = %d LIMIT 1", [ $meeting_id ] );
        if ( $locked === 1 ) {
            \metis_runtime_send_json_error( 'Attendance is locked for this meeting.', 423 );
        }

        $status = \metis_key_clean( \metis_runtime_unslash( $post['status'] ?? 'absent' ) );
        if ( ! in_array( $status, [ 'present', 'remote', 'absent', 'excused' ], true ) ) {
            $status = 'absent';
        }

        $role_label = \metis_text_clean( \metis_runtime_unslash( $post['role_label'] ?? '' ) );
        $notes = \metis_textarea_clean( \metis_runtime_unslash( $post['notes'] ?? '' ) );

        $table = \Metis_Tables::get( 'board_attendance' );
        $existing_id = (int) $db->scalar(
            "SELECT id FROM {$table} WHERE meeting_id = %d AND person_id = %d LIMIT 1",
            [ $meeting_id, $person_id ]
        );

        $payload = [
            'meeting_id' => $meeting_id,
            'person_id' => $person_id,
            'role_label' => $role_label,
            'status' => $status,
            'checkin_at' => in_array( $status, [ 'present', 'remote' ], true ) ? \metis_current_time( 'mysql' ) : null,
            'notes' => $notes,
        ];

        if ( $existing_id > 0 ) {
            $ok = $db->update( $table, $payload, [ 'id' => $existing_id ], [ '%d', '%d', '%s', '%s', '%s', '%s' ], [ '%d' ] );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update attendance.', 500 );
            }
        } else {
            $ok = $db->insert( $table, $payload, [ '%d', '%d', '%s', '%s', '%s', '%s' ] );
            if ( ! $ok ) {
                \metis_runtime_send_json_error( 'Failed to save attendance.', 500 );
            }
            $existing_id = (int) $db->lastInsertId();
        }

        $board_eligible = self::eligibleBoardCount();
        $present_count = (int) $db->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE meeting_id = %d AND status IN ('present','remote')",
            [ $meeting_id ]
        );
        if ( $board_eligible < 1 ) {
            $board_eligible = max( 1, (int) $db->scalar( "SELECT COUNT(*) FROM {$table} WHERE meeting_id = %d", [ $meeting_id ] ) );
        }
        $required = (int) floor( $board_eligible / 2 ) + 1;

        \metis_portal_dashboard_forget_all();
        return [
            'attendance_id' => $existing_id,
            'present_count' => $present_count,
            'eligible_count' => $board_eligible,
            'required_count' => $required,
            'quorum_met' => $present_count >= $required,
        ];
    }

    private static function eligibleBoardCount(): int {
        $people_table = \Metis_Tables::get( 'people' );
        $count = (int) \metis_db()->scalar( "SELECT COUNT(1) FROM {$people_table} WHERE status = 'active' AND is_board = 1" );
        return $count < 1 ? 1 : $count;
    }
}
