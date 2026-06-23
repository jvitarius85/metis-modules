<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class PacketService {
    public static function loadPacketContext( int $meeting_id, array $post ): array {
        $db = \metis_db();
        $meetings_table = \Metis_Tables::get( 'board_meetings' );
        $decisions_table = \Metis_Tables::get( 'board_decisions' );
        $actions_table = \Metis_Tables::get( 'board_action_items' );
        $attendance_table = \Metis_Tables::get( 'board_attendance' );
        $documents_table = \Metis_Tables::get( 'board_documents' );
        $people_table = \Metis_Tables::get( 'people' );

        $meeting = $db->fetchOne( "SELECT * FROM {$meetings_table} WHERE id = %d LIMIT 1", [ $meeting_id ] );
        if ( ! $meeting ) {
            \metis_runtime_send_json_error( 'Meeting not found.', 404 );
        }

        $minutes_reference_raw = \metis_text_clean( \metis_runtime_unslash( $post['packet_source_minutes_reference'] ?? '' ) );
        if ( $minutes_reference_raw === '' ) {
            $minutes_reference_raw = \metis_text_clean( \metis_runtime_unslash( $post['packet_source_minutes_meeting_id'] ?? '' ) );
        }
        $selected_prior_doc_id = 0;
        $selected_prior_meeting_id = 0;
        if ( preg_match( '/^doc:(\d+)$/', $minutes_reference_raw, $minutes_match ) ) {
            $selected_prior_doc_id = (int) ( $minutes_match[1] ?? 0 );
        } else {
            $selected_prior_meeting_id = (int) ( $minutes_reference_raw !== '' ? $minutes_reference_raw : (string) ( $meeting['packet_source_minutes_meeting_id'] ?? 0 ) );
        }
        $selected_financial_doc_id = (int) ( $post['packet_financial_document_id'] ?? ( $meeting['packet_financial_document_id'] ?? 0 ) );
        $db->update(
            $meetings_table,
            [
                'packet_source_minutes_meeting_id' => $selected_prior_meeting_id > 0 ? $selected_prior_meeting_id : null,
                'packet_financial_document_id' => $selected_financial_doc_id > 0 ? $selected_financial_doc_id : null,
            ],
            [ 'id' => $meeting_id ],
            [ '%d', '%d' ],
            [ '%d' ]
        );

        $agenda = json_decode( (string) ( $meeting['agenda_json'] ?? '' ), true );
        if ( ! is_array( $agenda ) ) {
            $agenda = [];
        }
        $decisions = $db->fetchAll( "SELECT * FROM {$decisions_table} WHERE meeting_id = %d ORDER BY id ASC", [ $meeting_id ] ) ?: [];
        $actions = $db->fetchAll(
            "SELECT a.*, p.display_name AS owner_name
             FROM {$actions_table} a
             LEFT JOIN {$people_table} p ON p.id = a.owner_person_id
             WHERE a.meeting_id = %d
             ORDER BY a.id ASC",
            [ $meeting_id ]
        ) ?: [];
        $attendance = $db->fetchAll(
            "SELECT atn.*, p.display_name
             FROM {$attendance_table} atn
             LEFT JOIN {$people_table} p ON p.id = atn.person_id
             WHERE atn.meeting_id = %d
             ORDER BY p.display_name ASC",
            [ $meeting_id ]
        ) ?: [];
        $linked_docs_for_packet = array_values( array_filter( ReadService::meetingDocuments( $meeting_id ), static function ( array $doc ): bool {
            $type = strtolower( (string) ( $doc['doc_type'] ?? '' ) );
            return in_array( $type, [ 'supporting_doc', 'agenda_attachment', 'minutes_attachment', 'policy', 'other' ], true );
        } ) );

        $extra_packet_data = [];
        $prior = [];
        $prior_doc = [];
        if ( $selected_prior_meeting_id > 0 ) {
            $prior = $db->fetchOne(
                "SELECT id, meeting_code, title, meeting_date, minutes_html
                 FROM {$meetings_table}
                 WHERE id = %d
                 LIMIT 1",
                [ $selected_prior_meeting_id ]
            );
            if ( $prior && trim( (string) ( $prior['minutes_html'] ?? '' ) ) !== '' ) {
                $extra_packet_data['prior_minutes_title'] = (string) ( $prior['meeting_code'] ?? '' ) . ' · ' . (string) ( $prior['title'] ?? 'Prior meeting' ) . ' · ' . \metis_board_format_datetime( (string) ( $prior['meeting_date'] ?? '' ) );
                $extra_packet_data['prior_minutes_html'] = (string) ( $prior['minutes_html'] ?? '' );
            }
        } elseif ( $selected_prior_doc_id > 0 ) {
            $prior_doc = $db->fetchOne(
                "SELECT id, title, google_file_id, google_drive_url, mime_type
                 FROM {$documents_table}
                 WHERE id = %d
                 LIMIT 1",
                [ $selected_prior_doc_id ]
            );
            if ( $prior_doc ) {
                $prior_title = trim( (string) ( $prior_doc['title'] ?? 'Legacy Meeting Minutes' ) );
                $prior_link = trim( (string) ( $prior_doc['google_drive_url'] ?? '' ) );
                $extra_packet_data['prior_minutes_title'] = $prior_title !== '' ? $prior_title : 'Legacy Meeting Minutes';
                if ( $prior_link !== '' ) {
                    $extra_packet_data['prior_minutes_html'] =
                        '<p><strong>Legacy minutes document:</strong> <a href="' .
                        \metis_escape_url( $prior_link ) .
                        '" target="_blank" rel="noopener">' .
                        \metis_escape_html( $extra_packet_data['prior_minutes_title'] ) .
                        '</a></p>';
                } else {
                    $extra_packet_data['prior_minutes_html'] = '<p><strong>Legacy minutes document:</strong> ' . \metis_escape_html( $extra_packet_data['prior_minutes_title'] ) . '</p>';
                }
            }
        }

        $financial_doc = null;
        if ( $selected_financial_doc_id > 0 ) {
            $financial_doc = $db->fetchOne(
                "SELECT id, title, google_file_id, google_drive_url, mime_type
                 FROM {$documents_table}
                 WHERE id = %d
                 LIMIT 1",
                [ $selected_financial_doc_id ]
            );
            if ( $financial_doc ) {
                $extra_packet_data['financial_title'] = (string) ( $financial_doc['title'] ?? 'Financial Report' );
                $extra_packet_data['financial_link'] = (string) ( $financial_doc['google_drive_url'] ?? '' );
            }
        }

        return [
            'meeting' => $meeting,
            'agenda' => $agenda,
            'decisions' => $decisions,
            'actions' => $actions,
            'attendance' => $attendance,
            'linked_docs_for_packet' => $linked_docs_for_packet,
            'extra_packet_data' => $extra_packet_data,
            'selected_prior_doc_id' => $selected_prior_doc_id,
            'selected_prior_meeting_id' => $selected_prior_meeting_id,
            'selected_financial_doc_id' => $selected_financial_doc_id,
            'prior' => is_array( $prior ) ? $prior : [],
            'prior_doc' => is_array( $prior_doc ) ? $prior_doc : [],
            'financial_doc' => is_array( $financial_doc ) ? $financial_doc : null,
        ];
    }

    public static function persistGeneratedPacketDocuments( int $meeting_id, array $uploads, array $financial_copy, ?array $financial_doc ): void {
        foreach ( $uploads as $doc_type => $file ) {
            $file_id = trim( (string) ( $file['id'] ?? '' ) );
            if ( $file_id === '' ) {
                continue;
            }
            DocumentService::upsertMeetingDocument( [
                'meeting_id' => $meeting_id,
                'title' => (string) ( $file['name'] ?? ucfirst( (string) $doc_type ) ),
                'doc_type' => (string) $doc_type,
                'google_file_id' => $file_id,
                'google_drive_url' => (string) ( $file['webViewLink'] ?? '' ),
                'mime_type' => (string) ( $file['mimeType'] ?? 'application/pdf' ),
                'file_size' => isset( $file['size'] ) ? (int) $file['size'] : 0,
                'status' => 'active',
            ] );
        }

        if ( ! empty( $financial_copy['id'] ) ) {
            DocumentService::upsertMeetingDocument( [
                'meeting_id' => $meeting_id,
                'title' => (string) ( $financial_copy['name'] ?? 'Financial Report' ),
                'doc_type' => 'financial_report',
                'google_file_id' => (string) ( $financial_copy['id'] ?? '' ),
                'google_drive_url' => (string) ( $financial_copy['webViewLink'] ?? '' ),
                'mime_type' => (string) ( $financial_copy['mimeType'] ?? (string) ( $financial_doc['mime_type'] ?? 'application/octet-stream' ) ),
                'file_size' => isset( $financial_copy['size'] ) ? (int) $financial_copy['size'] : 0,
                'status' => 'active',
            ] );
        }
    }
}
