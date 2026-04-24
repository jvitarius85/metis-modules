<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;
use Metis\Services\DatabaseService;

final class InboundMessageRepository {
    public function __construct( private readonly DatabaseService $db ) {}

    public static function dedupeKey( string $provider, string $mailbox_email, string $provider_message_id ): string {
        return sha1( strtolower( trim( $provider ) ) . '|' . strtolower( trim( $mailbox_email ) ) . '|' . trim( $provider_message_id ) );
    }

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function persistNormalizedMessage(
        array $mailbox,
        NormalizedInboundMessage $message,
        array $raw_payload
    ): array {
        $table = \Metis_Tables::get( 'communications_inbound_messages' );
        $provider = $message->provider();
        $mailbox_id = (int) ( $mailbox['id'] ?? 0 );
        $provider_message_id = $message->providerMessageId();
        $existing = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE provider = %s AND mailbox_id = %d AND provider_message_id = %s LIMIT 1",
            [ $provider, $mailbox_id, $provider_message_id ]
        );

        if ( is_array( $existing ) ) {
            return [
                'duplicate' => true,
                'row'       => $existing,
            ];
        }

        $from = (array) $message->get( 'from', [] );
        $to = (array) $message->get( 'to', [] );
        $cc = (array) $message->get( 'cc', [] );
        $reply_to = (array) $message->get( 'reply_to', [] );
        $raw_headers = (array) $message->get( 'raw_headers', [] );
        $attachments = (array) $message->get( 'attachments', [] );
        $canonical_recipients = (array) $message->get( 'canonical_recipient_emails', [] );
        $dedupe_key = self::dedupeKey( $provider, (string) ( $mailbox['mailbox_email'] ?? '' ), $provider_message_id );

        $payload = [
            'mailbox_id'                    => $mailbox_id,
            'provider'                      => $provider,
            'provider_message_id'           => $provider_message_id,
            'provider_thread_id'            => $message->providerThreadId(),
            'provider_history_id'           => (string) $message->get( 'provider_history_id', '' ),
            'rfc_message_id'                => (string) $message->get( 'rfc_message_id', '' ),
            'dedupe_key'                    => $dedupe_key,
            'processing_status'             => 'normalized',
            'classification'                => null,
            'classification_confidence'     => 0,
            'parser_key'                    => null,
            'handler_key'                   => null,
            'subject'                       => $message->subject(),
            'from_name'                     => (string) ( $from[0]['name'] ?? '' ),
            'from_email'                    => (string) ( $from[0]['email'] ?? '' ),
            'sender_email'                  => $message->senderEmail(),
            'to_json'                       => \metis_json_encode( $to ),
            'cc_json'                       => \metis_json_encode( $cc ),
            'reply_to_json'                 => \metis_json_encode( $reply_to ),
            'canonical_recipient_emails_json'=> \metis_json_encode( $canonical_recipients ),
            'sent_at'                       => $message->get( 'sent_at' ),
            'received_at'                   => $message->get( 'received_at' ),
            'text_body'                     => $message->textBody(),
            'html_body'                     => $message->htmlBody(),
            'raw_headers_json'              => \metis_json_encode( $raw_headers ),
            'attachments_json'              => \metis_json_encode( $attachments ),
            'raw_provider_payload_json'     => \metis_json_encode( $raw_payload ),
            'parser_metadata_json'          => null,
            'handling_metadata_json'        => null,
            'error_code'                    => null,
            'error_message'                 => null,
            'first_processed_at'            => \metis_current_time( 'mysql' ),
            'last_processed_at'             => \metis_current_time( 'mysql' ),
            'created_at'                    => \metis_current_time( 'mysql' ),
            'updated_at'                    => \metis_current_time( 'mysql' ),
        ];

        $this->db->insert(
            $table,
            $payload,
            [
                '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s',
            ]
        );

        $inserted = $this->findById( (int) $this->db->lastInsertId() );

        return [
            'duplicate' => false,
            'row'       => is_array( $inserted ) ? $inserted : $payload,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById( int $message_id ): ?array {
        $table = \Metis_Tables::get( 'communications_inbound_messages' );
        $row = $this->db->fetchOne( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", [ $message_id ] );
        return is_array( $row ) ? $row : null;
    }

    public function markDuplicate( int $message_id ): void {
        $this->updateMessage(
            $message_id,
            [
                'processing_status'  => 'duplicate',
                'last_processed_at'  => \metis_current_time( 'mysql' ),
            ]
        );
    }

    public function markParsed( int $message_id, ParseResult $result, array $parser_errors = [] ): void {
        $metadata = $result->metadata();
        if ( $parser_errors !== [] ) {
            $metadata['parser_errors'] = $parser_errors;
        }

        $this->updateMessage(
            $message_id,
            [
                'processing_status'         => 'parsed',
                'classification'            => $result->classification(),
                'classification_confidence' => $result->confidence(),
                'parser_key'                => $result->parserKey(),
                'handler_key'               => $result->handlerKey() !== '' ? $result->handlerKey() : null,
                'parser_metadata_json'      => \metis_json_encode( $metadata ),
                'last_processed_at'         => \metis_current_time( 'mysql' ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markHandled( int $message_id, string $handler_key, array $metadata = [] ): void {
        $this->updateMessage(
            $message_id,
            [
                'processing_status'     => 'handled',
                'handler_key'           => $handler_key,
                'handling_metadata_json'=> \metis_json_encode( $metadata ),
                'last_processed_at'     => \metis_current_time( 'mysql' ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markUnknown( int $message_id, array $metadata = [] ): void {
        $this->updateMessage(
            $message_id,
            [
                'processing_status'     => 'unknown',
                'handling_metadata_json'=> \metis_json_encode( $metadata ),
                'last_processed_at'     => \metis_current_time( 'mysql' ),
            ]
        );
    }

    public function markFailed( int $message_id, string $error_code, string $error_message ): void {
        $this->updateMessage(
            $message_id,
            [
                'processing_status' => 'failed',
                'error_code'        => $error_code,
                'error_message'     => $error_message,
                'last_processed_at' => \metis_current_time( 'mysql' ),
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function recordEvent(
        int $mailbox_id,
        ?int $message_id,
        string $event_type,
        string $event_status,
        string $dedupe_key,
        array $payload = [],
        string $parser_key = '',
        string $handler_key = '',
        string $error_message = ''
    ): void {
        $table = \Metis_Tables::get( 'communications_inbound_events' );
        if ( $dedupe_key !== '' ) {
            $existing = $this->db->fetchOne( "SELECT id FROM {$table} WHERE dedupe_key = %s LIMIT 1", [ $dedupe_key ] );
            if ( is_array( $existing ) ) {
                return;
            }
        }

        $this->db->insert(
            $table,
            [
                'mailbox_id'     => $mailbox_id,
                'message_id'     => $message_id,
                'event_type'     => $event_type,
                'event_status'   => $event_status,
                'parser_key'     => $parser_key !== '' ? $parser_key : null,
                'handler_key'    => $handler_key !== '' ? $handler_key : null,
                'dedupe_key'     => $dedupe_key !== '' ? $dedupe_key : null,
                'payload_json'   => $payload !== [] ? \metis_json_encode( $payload ) : null,
                'error_message'  => $error_message !== '' ? $error_message : null,
                'created_at'     => \metis_current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public function linkEntity(
        int $message_id,
        string $module_slug,
        string $entity_type,
        int $entity_id,
        string $link_type,
        array $metadata = []
    ): void {
        $table = \Metis_Tables::get( 'communications_inbound_links' );
        $existing = $this->db->fetchOne(
            "SELECT id FROM {$table} WHERE message_id = %d AND module_slug = %s AND entity_type = %s AND entity_id = %d AND link_type = %s LIMIT 1",
            [ $message_id, $module_slug, $entity_type, $entity_id, $link_type ]
        );
        if ( is_array( $existing ) ) {
            return;
        }

        $this->db->insert(
            $table,
            [
                'message_id'    => $message_id,
                'module_slug'   => $module_slug,
                'entity_type'   => $entity_type,
                'entity_id'     => $entity_id,
                'link_type'     => $link_type,
                'metadata_json' => $metadata !== [] ? \metis_json_encode( $metadata ) : null,
                'created_at'    => \metis_current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateMessage( int $message_id, array $payload ): void {
        $table = \Metis_Tables::get( 'communications_inbound_messages' );
        $payload['updated_at'] = \metis_current_time( 'mysql' );
        $formats = array_fill( 0, count( $payload ), '%s' );
        $this->db->update( $table, $payload, [ 'id' => $message_id ], $formats, [ '%d' ] );
    }
}
