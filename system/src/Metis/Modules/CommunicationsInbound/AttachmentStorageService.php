<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;

final class AttachmentStorageService {
    private const MAX_ATTACHMENT_BYTES = 26214400; // 25 MB

    public function __construct(
        private readonly GmailClient $gmail,
        private readonly InboundAttachmentRepository $attachments
    ) {}

    /**
     * @param array<string, mixed> $mailbox
     * @param array<string, mixed> $message_row
     * @param array<string, mixed> $raw_message
     * @return array<string, mixed>
     */
    public function storeForMessage(
        array $mailbox,
        array $message_row,
        NormalizedInboundMessage $normalized,
        array $raw_message
    ): array {
        $message_id = (int) ( $message_row['id'] ?? 0 );
        if ( $message_id < 1 ) {
            return [ 'ok' => false, 'error' => 'Inbound message id is required for attachment storage.' ];
        }

        $mailbox_id = (int) ( $message_row['mailbox_id'] ?? $mailbox['id'] ?? 0 );
        $provider = (string) ( $message_row['provider'] ?? $normalized->provider() );
        $provider_message_id = (string) ( $message_row['provider_message_id'] ?? $normalized->providerMessageId() );
        $attachment_rows = $this->attachments->syncDiscoveredAttachments(
            $message_id,
            $mailbox_id,
            $provider,
            $provider_message_id,
            (array) $normalized->get( 'attachments', [] )
        );

        if ( $attachment_rows === [] ) {
            return [ 'ok' => true, 'stored' => 0, 'failed' => 0, 'skipped' => 0 ];
        }

        $stored = 0;
        $failed = 0;
        $skipped = 0;

        foreach ( $this->attachments->pendingAttachmentsForMessage( $message_id ) as $attachment ) {
            $attachment_id = (int) ( $attachment['id'] ?? 0 );
            if ( $attachment_id < 1 ) {
                continue;
            }

            $expected_size = max( 0, (int) ( $attachment['size_bytes'] ?? 0 ) );
            if ( $expected_size > self::MAX_ATTACHMENT_BYTES ) {
                $this->attachments->markFailed( $attachment_id, 'Attachment exceeds the allowed size limit.', 'skipped' );
                $skipped++;
                continue;
            }

            try {
                $bytes = $this->resolveAttachmentBytes( $mailbox, $attachment, $raw_message );
                if ( $bytes === '' ) {
                    throw new \RuntimeException( 'Attachment content could not be resolved.' );
                }

                if ( strlen( $bytes ) > self::MAX_ATTACHMENT_BYTES ) {
                    $this->attachments->markFailed( $attachment_id, 'Attachment exceeds the allowed size limit.', 'skipped' );
                    $skipped++;
                    continue;
                }

                $filename = $this->attachmentFileName( $attachment );
                $stored_media = \metis_store_protected_media(
                    $filename,
                    $bytes,
                    self::allowedMimeMap(),
                    [
                        'access_ttl_seconds' => 30 * DAY_IN_SECONDS,
                        'folder_path' => 'communications/inbound',
                        'category_key' => 'communications_inbound',
                        'retention_key' => 'communications_inbound_attachment',
                    ]
                );
                if ( ! empty( $stored_media['error'] ) ) {
                    $status = str_contains( strtolower( (string) $stored_media['error'] ), 'not allowed' ) ? 'skipped' : 'failed';
                    $this->attachments->markFailed( $attachment_id, (string) $stored_media['error'], $status );
                    if ( $status === 'skipped' ) {
                        $skipped++;
                    } else {
                        $failed++;
                    }
                    continue;
                }

                $media = isset( $stored_media['token'] ) ? \metis_media_find_by_token( (string) $stored_media['token'] ) : null;
                if ( is_array( $media ) && \function_exists( 'metis_media_update_metadata' ) ) {
                    \metis_media_update_metadata(
                        (string) ( $media['public_token'] ?? $stored_media['token'] ?? '' ),
                        'communications/inbound',
                        'communications_inbound'
                    );
                }

                $this->attachments->markStored(
                    $attachment_id,
                    [
                        'media_token'  => (string) ( $stored_media['token'] ?? '' ),
                        'media_url'    => (string) ( $stored_media['url'] ?? '' ),
                        'storage_path' => (string) ( $media['storage_path'] ?? '' ),
                        'mime_type'    => (string) ( $media['mime_type'] ?? $attachment['mime_type'] ?? '' ),
                        'size_bytes'   => (int) ( $media['size'] ?? strlen( $bytes ) ),
                    ]
                );
                $stored++;
            } catch ( \Throwable $e ) {
                $this->attachments->markFailed( $attachment_id, $e->getMessage() );
                $failed++;
            }
        }

        return [
            'ok'      => true,
            'stored'  => $stored,
            'failed'  => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $mailbox
     * @param array<string, mixed> $attachment
     * @param array<string, mixed> $raw_message
     */
    private function resolveAttachmentBytes( array $mailbox, array $attachment, array $raw_message ): string {
        $provider_attachment_id = trim( (string) ( $attachment['provider_attachment_id'] ?? '' ) );
        if ( $provider_attachment_id !== '' ) {
            $response = $this->gmail->fetchAttachment(
                $mailbox,
                (string) ( $attachment['provider_message_id'] ?? '' ),
                $provider_attachment_id
            );
            if ( empty( $response['ok'] ) ) {
                throw new \RuntimeException( (string) ( $response['error'] ?? 'Attachment download failed.' ) );
            }

            return (string) ( $response['bytes'] ?? '' );
        }

        $part_id = trim( (string) ( $attachment['part_id'] ?? '' ) );
        if ( $part_id === '' ) {
            return '';
        }

        $part = $this->findPayloadPartById( (array) ( $raw_message['payload'] ?? [] ), $part_id );
        if ( ! is_array( $part ) ) {
            return '';
        }

        $body = is_array( $part['body'] ?? null ) ? (array) $part['body'] : [];
        $data = (string) ( $body['data'] ?? '' );
        return $data !== '' ? WorkspaceGoogleService::b64urlDecode( $data ) : '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function findPayloadPartById( array $payload, string $part_id ): ?array {
        if ( trim( (string) ( $payload['partId'] ?? '' ) ) === $part_id ) {
            return $payload;
        }

        foreach ( (array) ( $payload['parts'] ?? [] ) as $part ) {
            if ( ! is_array( $part ) ) {
                continue;
            }

            $found = $this->findPayloadPartById( $part, $part_id );
            if ( is_array( $found ) ) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attachment
     */
    private function attachmentFileName( array $attachment ): string {
        $file_name = trim( (string) ( $attachment['file_name'] ?? '' ) );
        if ( $file_name !== '' ) {
            return $file_name;
        }

        $mime_type = trim( (string) ( $attachment['mime_type'] ?? '' ) );
        $extension = \function_exists( 'metis_extension_for_mime_type' ) ? \metis_extension_for_mime_type( $mime_type, self::allowedMimeMap() ) : '';

        return 'email-attachment' . ( $extension !== '' ? '.' . $extension : '' );
    }

    /**
     * @return array<string, string>
     */
    private static function allowedMimeMap(): array {
        return [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'gif'      => 'image/gif',
            'webp'     => 'image/webp',
            'heic'     => 'image/heic',
            'pdf'      => 'application/pdf',
            'txt'      => 'text/plain',
            'csv'      => 'text/csv',
            'json'     => 'application/json',
            'doc'      => 'application/msword',
            'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'      => 'application/vnd.ms-excel',
            'xlsx'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'      => 'application/vnd.ms-powerpoint',
            'pptx'     => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'rtf'      => 'application/rtf',
            'zip'      => 'application/zip',
            'mp3'      => 'audio/mpeg',
            'wav'      => 'audio/wav',
            'm4a'      => 'audio/mp4',
            'mp4'      => 'video/mp4',
            'mov'      => 'video/quicktime',
        ];
    }
}
