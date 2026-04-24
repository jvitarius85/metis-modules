<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Services\DatabaseService;

final class InboundAttachmentRepository {
    public function __construct( private readonly DatabaseService $db ) {}

    public static function dedupeKey( int $message_id, string $provider_attachment_id, string $part_id, string $file_name ): string {
        return sha1(
            $message_id
            . '|'
            . trim( $provider_attachment_id )
            . '|'
            . trim( $part_id )
            . '|'
            . strtolower( trim( $file_name ) )
        );
    }

    /**
     * @param array<int, array<string, mixed>> $attachments
     * @return array<int, array<string, mixed>>
     */
    public function syncDiscoveredAttachments(
        int $message_id,
        int $mailbox_id,
        string $provider,
        string $provider_message_id,
        array $attachments
    ): array {
        $table = \Metis_Tables::get( 'communications_inbound_attachments' );
        $rows = [];

        foreach ( $attachments as $attachment ) {
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $provider_attachment_id = trim( (string) ( $attachment['attachment_id'] ?? '' ) );
            $part_id = trim( (string) ( $attachment['part_id'] ?? '' ) );
            $file_name = trim( (string) ( $attachment['filename'] ?? '' ) );
            $mime_type = strtolower( trim( (string) ( $attachment['mime_type'] ?? '' ) ) );
            $size_bytes = max( 0, (int) ( $attachment['size'] ?? 0 ) );
            $dedupe_key = self::dedupeKey( $message_id, $provider_attachment_id, $part_id, $file_name );

            $existing = $this->db->fetchOne(
                "SELECT * FROM {$table} WHERE dedupe_key = %s LIMIT 1",
                [ $dedupe_key ]
            );
            if ( is_array( $existing ) ) {
                $rows[] = $existing;
                continue;
            }

            $this->db->insert(
                $table,
                [
                    'message_id'              => $message_id,
                    'mailbox_id'              => $mailbox_id,
                    'provider'                => $provider,
                    'provider_message_id'     => $provider_message_id,
                    'provider_attachment_id'  => $provider_attachment_id !== '' ? $provider_attachment_id : null,
                    'part_id'                 => $part_id !== '' ? $part_id : null,
                    'dedupe_key'              => $dedupe_key,
                    'file_name'               => $file_name !== '' ? $file_name : null,
                    'mime_type'               => $mime_type !== '' ? $mime_type : null,
                    'size_bytes'              => $size_bytes,
                    'storage_status'          => 'pending',
                    'media_token'             => null,
                    'media_url'               => null,
                    'storage_path'            => null,
                    'error_message'           => null,
                    'metadata_json'           => \metis_json_encode( $attachment ),
                    'created_at'              => \metis_current_time( 'mysql' ),
                    'updated_at'              => \metis_current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            $rows[] = $this->findById( (int) $this->db->lastInsertId() ) ?? [];
        }

        return array_values( array_filter( $rows, 'is_array' ) );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function attachmentsForMessage( int $message_id ): array {
        $table = \Metis_Tables::get( 'communications_inbound_attachments' );
        $rows = $this->db->fetchAll(
            "SELECT *
             FROM {$table}
             WHERE message_id = %d
             ORDER BY id ASC",
            [ $message_id ]
        ) ?: [];

        return array_map( [ $this, 'hydrateAttachment' ], array_values( array_filter( $rows, 'is_array' ) ) );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingAttachmentsForMessage( int $message_id ): array {
        $table = \Metis_Tables::get( 'communications_inbound_attachments' );
        $rows = $this->db->fetchAll(
            "SELECT *
             FROM {$table}
             WHERE message_id = %d
               AND storage_status IN ('pending', 'failed')
             ORDER BY id ASC",
            [ $message_id ]
        ) ?: [];

        return array_map( [ $this, 'hydrateAttachment' ], array_values( array_filter( $rows, 'is_array' ) ) );
    }

    public function markStored( int $attachment_id, array $payload ): void {
        $this->updateAttachment(
            $attachment_id,
            [
                'storage_status' => 'stored',
                'media_token'    => (string) ( $payload['media_token'] ?? '' ) ?: null,
                'media_url'      => (string) ( $payload['media_url'] ?? '' ) ?: null,
                'storage_path'   => (string) ( $payload['storage_path'] ?? '' ) ?: null,
                'mime_type'      => (string) ( $payload['mime_type'] ?? '' ) ?: null,
                'size_bytes'     => max( 0, (int) ( $payload['size_bytes'] ?? 0 ) ),
                'error_message'  => null,
            ]
        );
    }

    public function markFailed( int $attachment_id, string $error_message, string $status = 'failed' ): void {
        $this->updateAttachment(
            $attachment_id,
            [
                'storage_status' => $status,
                'error_message'  => $error_message,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById( int $attachment_id ): ?array {
        $table = \Metis_Tables::get( 'communications_inbound_attachments' );
        $row = $this->db->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $attachment_id ] );
        return is_array( $row ) ? $this->hydrateAttachment( $row ) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateAttachment( array $row ): array {
        $row['size_bytes'] = (int) ( $row['size_bytes'] ?? 0 );
        $row['metadata'] = json_decode( (string) ( $row['metadata_json'] ?? '' ), true ) ?: [];
        $row['download_url'] = (string) ( $row['media_url'] ?? '' );
        return $row;
    }

    private function updateAttachment( int $attachment_id, array $payload ): void {
        $table = \Metis_Tables::get( 'communications_inbound_attachments' );
        $payload['updated_at'] = \metis_current_time( 'mysql' );
        $formats = array_fill( 0, count( $payload ), '%s' );
        $this->db->update( $table, $payload, [ 'id' => $attachment_id ], $formats, [ '%d' ] );
    }
}
