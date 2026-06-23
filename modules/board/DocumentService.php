<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class DocumentService {
    public static function upsertMeetingDocument( array $payload ): int {
        $meeting_id = (int) ( $payload['meeting_id'] ?? 0 );
        $file_id = trim( (string) ( $payload['google_file_id'] ?? '' ) );
        if ( $meeting_id < 1 || $file_id === '' ) {
            \metis_runtime_send_json_error( 'Meeting and file are required.', 422 );
        }

        $db = \metis_db();
        $docs_table = \Metis_Tables::get( 'board_documents' );
        $existing_id = (int) $db->scalar(
            "SELECT id FROM {$docs_table} WHERE meeting_id = %d AND google_file_id = %s LIMIT 1",
            [ $meeting_id, $file_id ]
        );

        $document_payload = [
            'meeting_id' => $meeting_id,
            'title' => (string) ( $payload['title'] ?? 'Document' ),
            'doc_type' => (string) ( $payload['doc_type'] ?? 'board_packet' ),
            'google_file_id' => $file_id,
            'google_drive_url' => (string) ( $payload['google_drive_url'] ?? '' ),
            'mime_type' => (string) ( $payload['mime_type'] ?? '' ),
            'file_size' => isset( $payload['file_size'] ) ? (int) $payload['file_size'] : null,
            'status' => (string) ( $payload['status'] ?? 'active' ),
        ];

        if ( $existing_id > 0 ) {
            $ok = $db->update( $docs_table, $document_payload, [ 'id' => $existing_id ], [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ], [ '%d' ] );
            if ( $ok === false ) {
                \metis_runtime_send_json_error( 'Failed to update linked document.', 500 );
            }
            return $existing_id;
        }

        $entity_type = Support::documentEntityType( (string) ( $document_payload['doc_type'] ?? '' ) );
        if ( $entity_type !== '' && \function_exists( 'metis_entity_id_service' ) ) {
            $document_payload = \metis_entity_id_service()->assignForInsert( $entity_type, $document_payload, false );
            $ok = $db->insert( $docs_table, $document_payload, [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ] );
        } else {
            $document_payload['document_code'] = Support::generateCode( 'BF', $docs_table, 'document_code' );
            $ok = $db->insert( $docs_table, $document_payload, [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ] );
        }

        if ( ! $ok ) {
            \metis_runtime_send_json_error( 'Failed to link document.', 500 );
        }

        $document_id = (int) $db->lastInsertId();
        if ( $entity_type !== '' && \function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( $entity_type, $document_id, \metis_board_first_document_uid( $document_payload ) );
        }

        return $document_id;
    }

    public static function unlinkDocument( int $document_id ): array {
        if ( $document_id < 1 ) {
            \metis_runtime_send_json_error( 'Document is required.', 422 );
        }

        $db = \metis_db();
        $docs_table = \Metis_Tables::get( 'board_documents' );
        $meeting_id = (int) $db->scalar( "SELECT meeting_id FROM {$docs_table} WHERE id = %d LIMIT 1", [ $document_id ] );
        $deleted = $db->delete( $docs_table, [ 'id' => $document_id ], [ '%d' ] );
        if ( ! $deleted ) {
            \metis_runtime_send_json_error( 'Failed to unlink document.', 500 );
        }

        return [
            'document_id' => $document_id,
            'documents' => $meeting_id > 0 ? ReadService::meetingDocuments( $meeting_id ) : [],
        ];
    }

    public static function deleteMeetingDocumentByFileId( int $meeting_id, string $file_id ): array {
        $file_id = trim( $file_id );
        if ( $file_id === '' ) {
            \metis_runtime_send_json_error( 'File is required.', 422 );
        }

        if ( $meeting_id > 0 ) {
            \metis_db()->delete(
                \Metis_Tables::get( 'board_documents' ),
                [ 'meeting_id' => $meeting_id, 'google_file_id' => $file_id ],
                [ '%d', '%s' ]
            );
        }

        return [
            'file_id' => $file_id,
            'documents' => $meeting_id > 0 ? ReadService::meetingDocuments( $meeting_id ) : [],
        ];
    }
}
