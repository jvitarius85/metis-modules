<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class BylawsService {
    public static function saveDraft( array $post ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'board_bylaws' );

        $bylaw_id = (int) ( $post['bylaw_id'] ?? 0 );
        $title = \metis_text_clean( \metis_runtime_unslash( $post['title'] ?? 'Bylaws' ) );
        $source_text = \metis_text_raw_clean( \metis_runtime_unslash( $post['source_text'] ?? '' ) );
        $signed_pdf_url = \metis_board_normalize_pdf_url( (string) \metis_runtime_unslash( $post['signed_pdf_url'] ?? '' ) );
        $signed_pdf_file_id = \metis_text_clean( \metis_runtime_unslash( $post['signed_pdf_file_id'] ?? '' ) );
        $signed_pdf_title = \metis_text_clean( \metis_runtime_unslash( $post['signed_pdf_title'] ?? 'Signed bylaws PDF' ) );
        $change_summary = \metis_textarea_clean( \metis_runtime_unslash( $post['change_summary'] ?? '' ) );
        $effective_date = \metis_board_normalize_bylaws_date( \metis_text_clean( \metis_runtime_unslash( $post['effective_date'] ?? '' ) ) );
        $meeting_id = max( 0, (int) ( $post['meeting_id'] ?? 0 ) );
        $decision_id = max( 0, (int) ( $post['decision_id'] ?? 0 ) );
        $action_item_id = max( 0, (int) ( $post['action_item_id'] ?? 0 ) );

        if ( $title === '' ) {
            $title = 'Bylaws';
        }
        if ( $source_text === '' ) {
            \metis_runtime_send_json_error( 'Bylaws text is required.', 422 );
        }
        if ( strlen( $source_text ) > 500000 ) {
            \metis_runtime_send_json_error( 'Bylaws text is too large to save safely.', 422 );
        }
        if ( $signed_pdf_file_id === '' && $signed_pdf_url !== '' ) {
            $signed_pdf_file_id = \metis_board_extract_google_id( $signed_pdf_url, 'drive_file' );
        }
        if ( $signed_pdf_title === '' ) {
            $signed_pdf_title = 'Signed bylaws PDF';
        }

        $formatted = BylawsFormatter::format( $source_text, $title );
        $person_id = Support::currentPersonId();
        $document_hash = hash( 'sha256', $title . "\n" . $source_text . "\n" . (string) ( $formatted['html'] ?? '' ) );

        $current = ReadService::bylawsRow( $bylaw_id );
        $can_update_existing = $current
            && in_array( (string) ( $current['status'] ?? '' ), [ 'draft', 'pending_president' ], true )
            && ! in_array( (string) ( $current['approval_stage'] ?? '' ), [ 'active', 'archived' ], true );
        $next_version = $can_update_existing
            ? (int) ( $current['version_number'] ?? 1 )
            : (int) $db->scalar( "SELECT COALESCE(MAX(version_number), 0) + 1 FROM {$table}" );
        if ( $next_version < 1 ) {
            $next_version = 1;
        }

        $payload = [
            'title' => $title,
            'source_text' => $source_text,
            'formatted_html' => (string) ( $formatted['html'] ?? '' ),
            'signed_pdf_file_id' => $signed_pdf_file_id !== '' ? $signed_pdf_file_id : null,
            'signed_pdf_url' => $signed_pdf_url !== '' ? $signed_pdf_url : null,
            'signed_pdf_title' => $signed_pdf_title,
            'status' => 'draft',
            'approval_stage' => 'draft',
            'version_number' => $next_version,
            'document_hash' => $document_hash,
            'meeting_id' => $meeting_id > 0 ? $meeting_id : null,
            'decision_id' => $decision_id > 0 ? $decision_id : null,
            'action_item_id' => $action_item_id > 0 ? $action_item_id : null,
            'change_summary' => $change_summary !== '' ? $change_summary : null,
            'effective_date' => $effective_date,
        ];

        if ( $can_update_existing ) {
            $ok = $db->update(
                $table,
                $payload,
                [ 'id' => $bylaw_id ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' ],
                [ '%d' ]
            );
        } else {
            $payload['bylaw_code'] = Support::generateCode( 'BB', $table, 'bylaw_code' );
            $payload['created_by_person_id'] = $person_id > 0 ? $person_id : null;
            $ok = $db->insert(
                $table,
                $payload,
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
            );
            if ( $ok ) {
                $bylaw_id = (int) $db->lastInsertId();
            }
        }

        if ( ! $ok ) {
            \metis_runtime_send_json_error( 'Failed to save bylaws.', 500 );
        }

        if ( \function_exists( 'metis_audit_log_activity' ) ) {
            \metis_audit_log_activity( 'board_bylaws_draft_saved', [
                'bylaw_id' => $bylaw_id,
                'version_number' => $next_version,
                'document_hash' => $document_hash,
                'meeting_id' => $meeting_id,
                'decision_id' => $decision_id,
                'action_item_id' => $action_item_id,
                'has_signed_pdf' => $signed_pdf_url !== '' || $signed_pdf_file_id !== '',
                'request_id' => \function_exists( 'metis_audit_request_id' ) ? \metis_audit_request_id() : '',
            ] );
        }

        \metis_portal_dashboard_forget_all();

        return [
            'bylaw_id' => $bylaw_id,
            'bylaws' => ReadService::bylawsSummary( $bylaw_id ),
            'history' => ReadService::bylawsHistory( 20 ),
        ];
    }

    public static function secretaryCertify( int $bylaw_id ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'board_bylaws' );
        $row = ReadService::bylawsRow( $bylaw_id );
        if ( ! $row ) {
            \metis_runtime_send_json_error( 'Bylaws draft not found.', 404 );
        }
        if ( ! in_array( (string) ( $row['status'] ?? '' ), [ 'draft', 'pending_president' ], true ) ) {
            \metis_runtime_send_json_error( 'Only a draft bylaws version can be certified by the secretary.', 422 );
        }

        $person_id = Support::currentPersonId();
        $signature_name = ReadService::bylawsSignatureName();
        $document_hash = (string) ( $row['document_hash'] ?? '' );
        $context = \metis_board_bylaws_audit_context( 'secretary_certification', $document_hash );
        $now = \metis_current_time( 'mysql' );
        $ok = $db->update(
            $table,
            [
                'status' => 'pending_president',
                'approval_stage' => 'secretary_certified',
                'secretary_person_id' => $person_id > 0 ? $person_id : null,
                'secretary_signature_name' => $signature_name,
                'secretary_certified_at' => $now,
                'secretary_context_json' => \metis_json_encode( $context ),
            ],
            [ 'id' => $bylaw_id ],
            [ '%s', '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            \metis_runtime_send_json_error( 'Failed to certify bylaws.', 500 );
        }

        if ( \function_exists( 'metis_audit_log_activity' ) ) {
            \metis_audit_log_activity( 'board_bylaws_secretary_certified', [
                'bylaw_id' => $bylaw_id,
                'document_hash' => $document_hash,
                'secretary_signature_name' => $signature_name,
                'request_id' => \function_exists( 'metis_audit_request_id' ) ? \metis_audit_request_id() : '',
            ] );
        }

        \metis_portal_dashboard_forget_all();

        return [
            'bylaw_id' => $bylaw_id,
            'bylaws' => ReadService::bylawsSummary( $bylaw_id ),
            'history' => ReadService::bylawsHistory( 20 ),
        ];
    }

    public static function presidentApprove( int $bylaw_id ): array {
        $db = \metis_db();
        $table = \Metis_Tables::get( 'board_bylaws' );
        $row = ReadService::bylawsRow( $bylaw_id );
        if ( ! $row ) {
            \metis_runtime_send_json_error( 'Bylaws draft not found.', 404 );
        }
        if ( (string) ( $row['approval_stage'] ?? '' ) !== 'secretary_certified' ) {
            \metis_runtime_send_json_error( 'Secretary certification is required before president approval.', 422 );
        }

        $decision_id = (int) ( $row['decision_id'] ?? 0 );
        $action_item_id = (int) ( $row['action_item_id'] ?? 0 );
        if ( $decision_id < 1 ) {
            \metis_runtime_send_json_error( 'Link an approved board vote before president approval.', 422 );
        }
        if ( $action_item_id < 1 ) {
            \metis_runtime_send_json_error( 'Link the bylaws approval to a board action item before president approval.', 422 );
        }

        $decision = ReadService::bylawsDecision( $decision_id );
        if ( ! $decision || (int) ( $decision['passed'] ?? 0 ) !== 1 || (string) ( $decision['outcome'] ?? '' ) !== 'approved' ) {
            \metis_runtime_send_json_error( 'The linked board vote must be approved before president approval.', 422 );
        }
        $action_item = ReadService::bylawsActionItem( $action_item_id );
        if ( ! $action_item ) {
            \metis_runtime_send_json_error( 'The linked board action item could not be found.', 422 );
        }

        $person_id = Support::currentPersonId();
        $secretary_person_id = (int) ( $row['secretary_person_id'] ?? 0 );
        if ( $person_id > 0 && $secretary_person_id > 0 && $person_id === $secretary_person_id ) {
            \metis_runtime_send_json_error( 'President approval must be performed by a different signer than secretary certification.', 422 );
        }

        $title = (string) ( $row['title'] ?? 'Bylaws' );
        $source_text = (string) ( $row['source_text'] ?? '' );
        $formatted = BylawsFormatter::format( $source_text, $title );
        $document_hash = hash( 'sha256', $title . "\n" . $source_text . "\n" . (string) ( $formatted['html'] ?? '' ) );
        $approved_at = \metis_current_time( 'mysql' );
        $signature_name = ReadService::bylawsSignatureName();
        $pdf_hash = '';
        $generated_pdf_path = '';
        $pdf_result = \metis_board_render_bylaws_pdf_bytes( $formatted, [
            'title' => $title,
            'signature' => 'Secretary: ' . (string) ( $row['secretary_signature_name'] ?? '' ) . ' | President: ' . $signature_name,
            'approved_at' => $approved_at,
            'document_hash' => $document_hash,
        ] );
        if ( ! empty( $pdf_result['ok'] ) ) {
            $pdf_bytes = (string) ( $pdf_result['bytes'] ?? '' );
            $pdf_hash = $pdf_bytes !== '' ? hash( 'sha256', $pdf_bytes ) : '';
            $generated_pdf_path = $pdf_bytes !== '' ? \metis_board_store_bylaws_pdf( (string) ( $row['bylaw_code'] ?? ( 'BB' . $bylaw_id ) ), $pdf_bytes ) : '';
        }

        $vote_context = [
            'meeting_id' => (int) ( $decision['meeting_id'] ?? 0 ),
            'decision_id' => $decision_id,
            'decision_code' => (string) ( $decision['decision_code'] ?? '' ),
            'decision_title' => (string) ( $decision['title'] ?? '' ),
            'votes_for' => (int) ( $decision['votes_for'] ?? 0 ),
            'votes_against' => (int) ( $decision['votes_against'] ?? 0 ),
            'votes_abstain' => (int) ( $decision['votes_abstain'] ?? 0 ),
            'passed_at' => (string) ( $decision['passed_at'] ?? '' ),
            'action_item_id' => $action_item_id,
            'action_item_code' => (string) ( $action_item['action_code'] ?? '' ),
            'action_item_title' => (string) ( $action_item['title'] ?? '' ),
        ];
        $president_context = \metis_board_bylaws_audit_context( 'president_approval', $document_hash, $pdf_hash );
        $approval_context = [
            'approval_model' => 'metis_two_step_bylaws_approval',
            'secretary_context' => json_decode( (string) ( $row['secretary_context_json'] ?? '{}' ), true ) ?: [],
            'president_context' => $president_context,
            'board_vote_context' => $vote_context,
        ];

        $db->execute( "UPDATE {$table} SET status = 'archived', approval_stage = 'archived' WHERE status = 'active'" );
        $ok = $db->update(
            $table,
            [
                'status' => 'active',
                'approval_stage' => 'active',
                'formatted_html' => (string) ( $formatted['html'] ?? '' ),
                'document_hash' => $document_hash,
                'pdf_hash' => $pdf_hash !== '' ? $pdf_hash : null,
                'generated_pdf_path' => $generated_pdf_path !== '' ? $generated_pdf_path : null,
                'president_person_id' => $person_id > 0 ? $person_id : null,
                'president_signature_name' => $signature_name,
                'president_approved_at' => $approved_at,
                'president_context_json' => \metis_json_encode( $president_context ),
                'board_vote_context_json' => \metis_json_encode( $vote_context ),
                'approved_by_person_id' => $person_id > 0 ? $person_id : null,
                'approved_signature_name' => $signature_name,
                'approval_context_json' => \metis_json_encode( $approval_context ),
                'approved_at' => $approved_at,
            ],
            [ 'id' => $bylaw_id ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ],
            [ '%d' ]
        );
        if ( $ok === false ) {
            \metis_runtime_send_json_error( 'Failed to approve bylaws.', 500 );
        }

        if ( \function_exists( 'metis_audit_log_activity' ) ) {
            \metis_audit_log_activity( 'board_bylaws_president_approved', [
                'bylaw_id' => $bylaw_id,
                'document_hash' => $document_hash,
                'pdf_hash' => $pdf_hash,
                'decision_id' => $decision_id,
                'action_item_id' => $action_item_id,
                'president_signature_name' => $signature_name,
                'request_id' => \function_exists( 'metis_audit_request_id' ) ? \metis_audit_request_id() : '',
            ] );
        }

        \metis_portal_dashboard_forget_all();

        return [
            'bylaw_id' => $bylaw_id,
            'bylaws' => ReadService::bylawsSummary( $bylaw_id ),
            'history' => ReadService::bylawsHistory( 20 ),
        ];
    }

    public static function pdfOptions(): array {
        $db = \metis_db();
        $options = [];
        $drive_enabled = false;

        if ( \function_exists( 'metis_drive_workspace_settings' ) ) {
            $cfg = \metis_drive_workspace_settings();
            $drive_enabled = ! empty( $cfg['ok'] );
        }

        if ( $drive_enabled && \Metis_Tables::has( 'drive_items' ) ) {
            $items_table = \Metis_Tables::get( 'drive_items' );
            $drive_rows = $db->fetchAll(
                "SELECT item_id, item_name, mime_type, web_view_link, drive_id, modified_time
                 FROM {$items_table}
                 WHERE is_folder = 0
                   AND (mime_type = 'application/pdf' OR item_name LIKE '%.pdf')
                 ORDER BY modified_time DESC, synced_at DESC
                 LIMIT 100"
            ) ?: [];
            foreach ( $drive_rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $options[] = [
                    'source' => 'drive',
                    'id' => (string) ( $row['item_id'] ?? '' ),
                    'title' => (string) ( $row['item_name'] ?? 'Drive PDF' ),
                    'url' => (string) ( $row['web_view_link'] ?? '' ),
                    'meta' => 'Google Drive',
                ];
            }
        }

        if ( ! $drive_enabled && \Metis_Tables::has( 'board_documents' ) ) {
            $docs_table = \Metis_Tables::get( 'board_documents' );
            $doc_rows = $db->fetchAll(
                "SELECT id, title, google_file_id, google_drive_url, mime_type, updated_at
                 FROM {$docs_table}
                 WHERE status = 'active'
                   AND (mime_type = 'application/pdf' OR title LIKE '%.pdf')
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 100"
            ) ?: [];
            foreach ( $doc_rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $options[] = [
                    'source' => 'upload',
                    'id' => (string) ( $row['google_file_id'] ?? ( 'doc:' . (int) ( $row['id'] ?? 0 ) ) ),
                    'title' => (string) ( $row['title'] ?? 'Uploaded PDF' ),
                    'url' => (string) ( $row['google_drive_url'] ?? '' ),
                    'meta' => 'Uploaded board document',
                ];
            }
        }

        return [
            'drive_enabled' => $drive_enabled,
            'options' => $options,
        ];
    }
}
