<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class ReadService {
    public static function meetingDocuments( int $meeting_id ): array {
        if ( $meeting_id < 1 ) {
            return [];
        }

        $documents_table = \Metis_Tables::get( 'board_documents' );
        $rows = \metis_db()->fetchAll(
            "SELECT id, title, doc_type, google_file_id, google_drive_url, mime_type, file_size, updated_at
             FROM {$documents_table}
             WHERE meeting_id = %d
             ORDER BY updated_at DESC, id DESC",
            [ $meeting_id ]
        ) ?: [];

        $docs = [];
        foreach ( $rows as $row ) {
            $doc_type = (string) ( $row['doc_type'] ?? '' );
            $docs[] = [
                'id' => (int) ( $row['id'] ?? 0 ),
                'title' => (string) ( $row['title'] ?? '' ),
                'doc_type' => $doc_type,
                'doc_type_label' => Support::docTypeLabel( $doc_type ),
                'google_file_id' => (string) ( $row['google_file_id'] ?? '' ),
                'google_drive_url' => (string) ( $row['google_drive_url'] ?? '' ),
                'mime_type' => (string) ( $row['mime_type'] ?? '' ),
                'file_size' => (int) ( $row['file_size'] ?? 0 ),
                'updated_at' => (string) ( $row['updated_at'] ?? '' ),
            ];
        }

        return $docs;
    }

    public static function meetingSummary( int $meeting_id ): array {
        if ( $meeting_id < 1 ) {
            return [];
        }

        $meetings_table = \Metis_Tables::get( 'board_meetings' );
        $row = \metis_db()->fetchOne(
            "SELECT m.agenda_json, m.minutes_html
             FROM {$meetings_table} m
             WHERE m.id = %d
             LIMIT 1",
            [ $meeting_id ]
        );

        $summary = null;
        foreach ( \metis_board_fetch_dashboard_meetings( 300 ) as $candidate ) {
            if ( (int) ( $candidate['id'] ?? 0 ) === $meeting_id ) {
                $summary = $candidate;
                break;
            }
        }

        if ( ! is_array( $summary ) || ! is_array( $row ) ) {
            return [];
        }

        $meeting_code = (string) ( $summary['meeting_code'] ?? '' );

        return [
            'id' => (int) ( $summary['id'] ?? 0 ),
            'meeting_code' => $meeting_code,
            'title' => (string) ( $summary['title'] ?? '' ),
            'committee_id' => (int) ( $summary['committee_id'] ?? 0 ),
            'meeting_date' => (string) ( $summary['meeting_date'] ?? '' ),
            'meeting_type' => (string) ( $summary['meeting_type'] ?? 'board' ),
            'location' => (string) ( $summary['location'] ?? '' ),
            'status' => (string) ( $summary['status'] ?? 'draft' ),
            'updated_at' => (string) ( $summary['updated_at'] ?? '' ),
            'google_calendar_event_id' => (string) ( $summary['google_calendar_event_id'] ?? '' ),
            'google_drive_folder_id' => (string) ( $summary['google_drive_folder_id'] ?? '' ),
            'agenda_json' => (string) ( $row['agenda_json'] ?? '' ),
            'minutes_html' => (string) ( $row['minutes_html'] ?? '' ),
            'committee_name' => (string) ( $summary['committee_name'] ?? '' ),
            'open_actions' => (int) ( $summary['open_actions'] ?? 0 ),
            'decisions_count' => (int) ( $summary['decisions_count'] ?? 0 ),
            'meeting_url' => $meeting_code !== '' ? Support::meetingUrl( $meeting_code ) : '',
        ];
    }

    public static function decisionSummary( int $decision_id ): array {
        if ( $decision_id < 1 ) {
            return [];
        }

        $table = \Metis_Tables::get( 'board_decisions' );
        $row = \metis_db()->fetchOne(
            "SELECT d.*
             FROM {$table} d
             WHERE d.id = %d
             LIMIT 1",
            [ $decision_id ]
        );

        if ( ! is_array( $row ) ) {
            return [];
        }

        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'meeting_id' => (int) ( $row['meeting_id'] ?? 0 ),
            'title' => (string) ( $row['title'] ?? '' ),
            'agenda_section_title' => (string) ( $row['agenda_section_title'] ?? '' ),
            'agenda_item_title' => (string) ( $row['agenda_item_title'] ?? '' ),
            'decision_text' => (string) ( $row['decision_text'] ?? '' ),
            'outcome' => (string) ( $row['outcome'] ?? 'pending' ),
            'votes_for' => (int) ( $row['votes_for'] ?? 0 ),
            'votes_against' => (int) ( $row['votes_against'] ?? 0 ),
            'votes_abstain' => (int) ( $row['votes_abstain'] ?? 0 ),
            'decision_votes_json' => (string) ( $row['decision_votes_json'] ?? '{"for":[],"against":[],"abstain":[]}' ),
        ];
    }

    public static function announcementSummary( int $announcement_id ): array {
        if ( $announcement_id < 1 ) {
            return [];
        }

        $table = \Metis_Tables::get( 'board_announcements' );
        $row = \metis_db()->fetchOne(
            "SELECT id, announcement_code, title, status, publish_at, updated_at
             FROM {$table}
             WHERE id = %d
             LIMIT 1",
            [ $announcement_id ]
        );

        if ( ! is_array( $row ) ) {
            return [];
        }

        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'announcement_code' => (string) ( $row['announcement_code'] ?? '' ),
            'title' => (string) ( $row['title'] ?? '' ),
            'status' => (string) ( $row['status'] ?? 'draft' ),
            'publish_at' => (string) ( $row['publish_at'] ?? '' ),
            'updated_at' => (string) ( $row['updated_at'] ?? '' ),
        ];
    }

    public static function actionItemSummary( int $action_item_id ): array {
        if ( $action_item_id < 1 ) {
            return [];
        }

        $table = \Metis_Tables::get( 'board_action_items' );
        $people_table = \Metis_Tables::get( 'people' );
        $meetings_table = \Metis_Tables::get( 'board_meetings' );
        $row = \metis_db()->fetchOne(
            "SELECT a.id, a.meeting_id, a.owner_person_id, a.title, a.due_date, a.status,
                    p.display_name AS owner_name,
                    m.meeting_code, m.title AS meeting_title
             FROM {$table} a
             LEFT JOIN {$people_table} p ON p.id = a.owner_person_id
             LEFT JOIN {$meetings_table} m ON m.id = a.meeting_id
             WHERE a.id = %d
             LIMIT 1",
            [ $action_item_id ]
        );

        if ( ! is_array( $row ) ) {
            return [];
        }

        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'meeting_id' => (int) ( $row['meeting_id'] ?? 0 ),
            'owner_person_id' => (int) ( $row['owner_person_id'] ?? 0 ),
            'title' => (string) ( $row['title'] ?? '' ),
            'owner_name' => (string) ( $row['owner_name'] ?? '' ),
            'meeting_code' => (string) ( $row['meeting_code'] ?? '' ),
            'meeting_title' => (string) ( $row['meeting_title'] ?? '' ),
            'due_date' => (string) ( $row['due_date'] ?? '' ),
            'status' => (string) ( $row['status'] ?? 'open' ),
        ];
    }

    public static function bylawsSummary( int $bylaw_id = 0 ): array {
        $table = \Metis_Tables::get( 'board_bylaws' );
        if ( ! self::tableExists( $table ) ) {
            return [];
        }

        if ( $bylaw_id > 0 ) {
            $row = \metis_db()->fetchOne(
                "SELECT id, bylaw_code, title, source_text, formatted_html, signed_pdf_file_id, signed_pdf_url,
                        signed_pdf_title, status, approval_stage, version_number, document_hash, pdf_hash, generated_pdf_path,
                        meeting_id, decision_id, action_item_id, secretary_person_id, secretary_signature_name,
                        secretary_certified_at, secretary_context_json, president_person_id, president_signature_name,
                        president_approved_at, president_context_json, board_vote_context_json,
                        approved_by_person_id, approved_signature_name, approval_context_json, change_summary,
                        effective_date, approved_at, updated_at
                 FROM {$table}
                 WHERE id = %d
                 LIMIT 1",
                [ $bylaw_id ]
            );
        } else {
            $row = \metis_db()->fetchOne(
                "SELECT id, bylaw_code, title, source_text, formatted_html, signed_pdf_file_id, signed_pdf_url,
                        signed_pdf_title, status, approval_stage, version_number, document_hash, pdf_hash, generated_pdf_path,
                        meeting_id, decision_id, action_item_id, secretary_person_id, secretary_signature_name,
                        secretary_certified_at, secretary_context_json, president_person_id, president_signature_name,
                        president_approved_at, president_context_json, board_vote_context_json,
                        approved_by_person_id, approved_signature_name, approval_context_json, change_summary,
                        effective_date, approved_at, updated_at
                 FROM {$table}
                 WHERE status = 'active'
                 ORDER BY version_number DESC, (effective_date IS NULL), effective_date DESC, updated_at DESC, id DESC
                 LIMIT 1"
            );
        }

        if ( ! is_array( $row ) ) {
            return [];
        }

        $effective_date = (string) ( $row['effective_date'] ?? '' );
        $approved_at = (string) ( $row['approved_at'] ?? '' );
        $updated_at = (string) ( $row['updated_at'] ?? '' );

        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'bylaw_code' => (string) ( $row['bylaw_code'] ?? '' ),
            'title' => (string) ( $row['title'] ?? 'Bylaws' ),
            'source_text' => (string) ( $row['source_text'] ?? '' ),
            'formatted_html' => (string) ( $row['formatted_html'] ?? '' ),
            'signed_pdf_file_id' => (string) ( $row['signed_pdf_file_id'] ?? '' ),
            'signed_pdf_url' => (string) ( $row['signed_pdf_url'] ?? '' ),
            'signed_pdf_title' => (string) ( $row['signed_pdf_title'] ?? '' ),
            'status' => (string) ( $row['status'] ?? 'active' ),
            'approval_stage' => (string) ( $row['approval_stage'] ?? ( $row['status'] ?? 'active' ) ),
            'version_number' => (int) ( $row['version_number'] ?? 1 ),
            'document_hash' => (string) ( $row['document_hash'] ?? '' ),
            'pdf_hash' => (string) ( $row['pdf_hash'] ?? '' ),
            'generated_pdf_path' => (string) ( $row['generated_pdf_path'] ?? '' ),
            'meeting_id' => (int) ( $row['meeting_id'] ?? 0 ),
            'decision_id' => (int) ( $row['decision_id'] ?? 0 ),
            'action_item_id' => (int) ( $row['action_item_id'] ?? 0 ),
            'secretary_person_id' => (int) ( $row['secretary_person_id'] ?? 0 ),
            'secretary_signature_name' => (string) ( $row['secretary_signature_name'] ?? '' ),
            'secretary_certified_at' => (string) ( $row['secretary_certified_at'] ?? '' ),
            'secretary_certified_at_label' => ! empty( $row['secretary_certified_at'] ) ? Support::formatDatetime( (string) $row['secretary_certified_at'] ) : '—',
            'president_person_id' => (int) ( $row['president_person_id'] ?? 0 ),
            'president_signature_name' => (string) ( $row['president_signature_name'] ?? '' ),
            'president_approved_at' => (string) ( $row['president_approved_at'] ?? '' ),
            'president_approved_at_label' => ! empty( $row['president_approved_at'] ) ? Support::formatDatetime( (string) $row['president_approved_at'] ) : '—',
            'approved_by_person_id' => (int) ( $row['approved_by_person_id'] ?? 0 ),
            'approved_signature_name' => (string) ( $row['approved_signature_name'] ?? '' ),
            'approval_context_json' => (string) ( $row['approval_context_json'] ?? '' ),
            'change_summary' => (string) ( $row['change_summary'] ?? '' ),
            'effective_date' => $effective_date,
            'effective_date_label' => $effective_date !== '' && \function_exists( 'metis_runtime_format_date' ) ? \metis_runtime_format_date( $effective_date, null, null, null, '—' ) : ( $effective_date !== '' ? $effective_date : '—' ),
            'approved_at' => $approved_at,
            'approved_at_label' => $approved_at !== '' ? Support::formatDatetime( $approved_at ) : '—',
            'updated_at' => $updated_at,
            'updated_at_label' => $updated_at !== '' ? Support::formatDatetime( $updated_at ) : '—',
        ];
    }

    public static function bylawsHistory( int $limit = 20 ): array {
        $table = \Metis_Tables::get( 'board_bylaws' );
        if ( ! self::tableExists( $table ) ) {
            return [];
        }

        $rows = \metis_db()->fetchAll(
            "SELECT id, bylaw_code, title, signed_pdf_url, signed_pdf_title, status, approval_stage, version_number,
                    document_hash, pdf_hash, approved_signature_name, president_signature_name, secretary_signature_name, change_summary,
                    effective_date, approved_at, updated_at
             FROM {$table}
             WHERE status IN ('active', 'archived')
             ORDER BY version_number DESC, approved_at DESC, updated_at DESC, id DESC
             LIMIT %d",
            [ max( 1, min( 100, $limit ) ) ]
        ) ?: [];

        $out = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $effective_date = (string) ( $row['effective_date'] ?? '' );
            $approved_at = (string) ( $row['approved_at'] ?? '' );
            $updated_at = (string) ( $row['updated_at'] ?? '' );
            $out[] = [
                'id' => (int) ( $row['id'] ?? 0 ),
                'bylaw_code' => (string) ( $row['bylaw_code'] ?? '' ),
                'title' => (string) ( $row['title'] ?? 'Bylaws' ),
                'signed_pdf_url' => (string) ( $row['signed_pdf_url'] ?? '' ),
                'signed_pdf_title' => (string) ( $row['signed_pdf_title'] ?? '' ),
                'status' => (string) ( $row['status'] ?? '' ),
                'approval_stage' => (string) ( $row['approval_stage'] ?? '' ),
                'version_number' => (int) ( $row['version_number'] ?? 1 ),
                'document_hash' => (string) ( $row['document_hash'] ?? '' ),
                'pdf_hash' => (string) ( $row['pdf_hash'] ?? '' ),
                'approved_signature_name' => (string) ( $row['approved_signature_name'] ?? '' ),
                'president_signature_name' => (string) ( $row['president_signature_name'] ?? '' ),
                'secretary_signature_name' => (string) ( $row['secretary_signature_name'] ?? '' ),
                'change_summary' => (string) ( $row['change_summary'] ?? '' ),
                'effective_date_label' => $effective_date !== '' && \function_exists( 'metis_runtime_format_date' ) ? \metis_runtime_format_date( $effective_date, null, null, null, '—' ) : ( $effective_date !== '' ? $effective_date : '—' ),
                'approved_at_label' => $approved_at !== '' ? Support::formatDatetime( $approved_at ) : '—',
                'updated_at_label' => $updated_at !== '' ? Support::formatDatetime( $updated_at ) : '—',
            ];
        }

        return $out;
    }

    public static function bylawsSignatureName(): string {
        $person_id = Support::currentPersonId();
        if ( $person_id > 0 ) {
            $people_table = \Metis_Tables::get( 'people' );
            $name = (string) \metis_db()->scalar( "SELECT display_name FROM {$people_table} WHERE id = %d LIMIT 1", [ $person_id ] );
            if ( trim( $name ) !== '' ) {
                return trim( $name );
            }
        }

        $user = \function_exists( 'metis_runtime_current_user' ) ? \metis_runtime_current_user() : null;
        if ( is_object( $user ) ) {
            $name = trim( (string) ( $user->display_name ?? $user->user_login ?? '' ) );
            if ( $name !== '' ) {
                return $name;
            }
        }

        return 'Metis approver';
    }

    public static function bylawsRow( int $bylaw_id ): array {
        if ( $bylaw_id < 1 ) {
            return [];
        }

        $table = \Metis_Tables::get( 'board_bylaws' );
        if ( ! self::tableExists( $table ) ) {
            return [];
        }

        $row = \metis_db()->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $bylaw_id ] );
        return is_array( $row ) ? $row : [];
    }

    public static function bylawsDecision( int $decision_id ): array {
        if ( $decision_id < 1 ) {
            return [];
        }

        $table = \Metis_Tables::get( 'board_decisions' );
        if ( ! self::tableExists( $table ) ) {
            return [];
        }

        $row = \metis_db()->fetchOne(
            "SELECT id, decision_code, meeting_id, title, outcome, votes_for, votes_against, votes_abstain, passed, passed_at
             FROM {$table}
             WHERE id = %d
             LIMIT 1",
            [ $decision_id ]
        );

        return is_array( $row ) ? $row : [];
    }

    public static function bylawsActionItem( int $action_item_id ): array {
        if ( $action_item_id < 1 ) {
            return [];
        }

        $table = \Metis_Tables::get( 'board_action_items' );
        if ( ! self::tableExists( $table ) ) {
            return [];
        }

        $row = \metis_db()->fetchOne(
            "SELECT id, action_code, meeting_id, decision_id, title, status
             FROM {$table}
             WHERE id = %d
             LIMIT 1",
            [ $action_item_id ]
        );

        return is_array( $row ) ? $row : [];
    }

    private static function tableExists( string $table ): bool {
        return \function_exists( 'metis_board_table_exists' ) ? \metis_board_table_exists( $table ) : false;
    }
}
