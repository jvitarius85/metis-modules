<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;

final class InboundProcessor {
    public function __construct(
        private readonly GmailClient $gmail,
        private readonly MailboxRepository $mailboxes,
        private readonly InboundMessageRepository $messages,
        private readonly InboundAttachmentRepository $attachments,
        private readonly InboundMessageNormalizer $normalizer,
        private readonly ParserEngine $parser_engine,
        private readonly HandlerRegistry $handlers,
        private readonly AttachmentStorageService $attachment_storage
    ) {}

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function queueMailboxSyncFromWebhook( array $event ): array {
        $payload = (array) ( $event['payload'] ?? [] );
        $notification = (array) ( $payload['notification'] ?? [] );
        $mailbox_email = strtolower( trim( (string) ( $notification['emailAddress'] ?? '' ) ) );
        $history_id = trim( (string) ( $notification['historyId'] ?? '' ) );
        if ( $mailbox_email === '' || $history_id === '' ) {
            throw new \RuntimeException( 'Inbound Gmail notification is missing mailbox context.' );
        }

        $mailbox = $this->mailboxes->findByEmail( $mailbox_email );
        if ( ! is_array( $mailbox ) ) {
            throw new \RuntimeException( 'Inbound Gmail mailbox is not configured: ' . $mailbox_email );
        }

        $this->mailboxes->updateState(
            (int) $mailbox['id'],
            [
                'last_sync_requested_at' => \metis_current_time( 'mysql' ),
                'sync_status'            => 'queued',
                'last_error'             => '',
            ]
        );

        $job = \metis_job_queue()->enqueue(
            'communications_inbound.sync_mailbox',
            [
                'mailbox_email'     => $mailbox_email,
                'notification_id'   => (string) ( $event['event_id'] ?? '' ),
                'notification_history_id' => $history_id,
            ],
            [
                'queue'        => 'communications',
                'dedupe_key'   => 'communications_inbound.sync:' . $mailbox_email . ':' . $history_id,
                'max_attempts' => 3,
                'priority'     => 20,
            ]
        );

        $this->messages->recordEvent(
            (int) $mailbox['id'],
            null,
            'webhook_sync_queued',
            'queued',
            'communications_inbound.webhook:' . (string) ( $event['event_id'] ?? '' ),
            [
                'mailbox_email' => $mailbox_email,
                'history_id'    => $history_id,
                'job'           => $job,
            ]
        );

        return [
            'provider'      => 'gmail',
            'mailbox_email' => $mailbox_email,
            'history_id'    => $history_id,
            'job'           => $job,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function syncMailboxByEmail( string $mailbox_email, array $options = [] ): array {
        $mailbox = $this->mailboxes->findByEmail( $mailbox_email );
        if ( ! is_array( $mailbox ) ) {
            return [ 'ok' => false, 'error' => 'Mailbox is not configured.' ];
        }

        $force_full = ! empty( $options['force_full'] );
        $start_history_id = trim( (string) ( $mailbox['current_history_id'] ?? '' ) );
        $notification_history_id = trim( (string) ( $options['notification_history_id'] ?? '' ) );
        if ( $notification_history_id !== '' && ( $start_history_id === '' || strcmp( $notification_history_id, $start_history_id ) > 0 ) ) {
            $start_history_id = $start_history_id;
        }

        $this->mailboxes->updateState(
            (int) $mailbox['id'],
            [
                'sync_status' => 'syncing',
                'last_error'  => '',
            ]
        );

        $result = $this->gmail->collectChangedMessages( $mailbox, $start_history_id, $force_full );
        if ( empty( $result['ok'] ) ) {
            $this->mailboxes->updateState(
                (int) $mailbox['id'],
                [
                    'sync_status' => 'error',
                    'last_error'  => (string) ( $result['error'] ?? 'Mailbox sync failed.' ),
                ]
            );

            return $result;
        }

        $processed = 0;
        $duplicates = 0;
        $unknown = 0;
        $failed = 0;

        foreach ( (array) ( $result['messages'] ?? [] ) as $raw_message ) {
            if ( ! is_array( $raw_message ) ) {
                continue;
            }

            $message_result = $this->processRawGmailMessage( $mailbox, $raw_message );
            $status = (string) ( $message_result['status'] ?? '' );
            if ( $status === 'duplicate' ) {
                $duplicates++;
                continue;
            }
            if ( $status === 'unknown' ) {
                $unknown++;
            } elseif ( $status === 'failed' ) {
                $failed++;
            } else {
                $processed++;
            }
        }

        $latest_history_id = trim( (string) ( $result['latest_history_id'] ?? '' ) );
        $this->mailboxes->updateState(
            (int) $mailbox['id'],
            [
                'current_history_id'       => $latest_history_id !== '' ? $latest_history_id : (string) ( $mailbox['current_history_id'] ?? '' ),
                'last_synced_at'           => \metis_current_time( 'mysql' ),
                'sync_status'              => 'idle',
                'last_error'               => '',
                'last_message_received_at' => \metis_current_time( 'mysql' ),
            ]
        );

        return [
            'ok'                 => true,
            'mailbox_email'      => $mailbox_email,
            'mode'               => (string) ( $result['mode'] ?? 'history' ),
            'processed'          => $processed,
            'duplicates'         => $duplicates,
            'unknown'            => $unknown,
            'failed'             => $failed,
            'latest_history_id'  => $latest_history_id,
        ];
    }

    /**
     * @param array<string, mixed> $mailbox
     * @param array<string, mixed> $raw_message
     * @return array<string, mixed>
     */
    public function processRawGmailMessage( array $mailbox, array $raw_message ): array {
        $normalized = $this->normalizer->normalizeGmailMessage( $mailbox, $raw_message );
        $persisted = $this->messages->persistNormalizedMessage( $mailbox, $normalized, $raw_message );
        $message_row = (array) ( $persisted['row'] ?? [] );
        $message_id = (int) ( $message_row['id'] ?? 0 );
        $mailbox_id = (int) ( $mailbox['id'] ?? 0 );

        if ( ! empty( $persisted['duplicate'] ) ) {
            $this->messages->recordEvent(
                $mailbox_id,
                $message_id > 0 ? $message_id : null,
                'message_duplicate',
                'skipped',
                'communications_inbound.duplicate:' . (string) ( $message_row['dedupe_key'] ?? '' ),
                [ 'provider_message_id' => $normalized->providerMessageId() ]
            );

            return [
                'ok'     => true,
                'status' => 'duplicate',
            ];
        }

        $attachment_result = $this->attachment_storage->storeForMessage( $mailbox, $message_row, $normalized, $raw_message );
        if ( ! empty( $attachment_result['stored'] ) || ! empty( $attachment_result['failed'] ) || ! empty( $attachment_result['skipped'] ) ) {
            $this->messages->recordEvent(
                $mailbox_id,
                $message_id > 0 ? $message_id : null,
                'attachments_processed',
                ! empty( $attachment_result['failed'] ) ? 'failed' : 'handled',
                'communications_inbound.attachments:' . $message_id,
                $attachment_result
            );
        }

        $parse = $this->parser_engine->evaluate( $normalized );
        $result = $parse['result'];
        $errors = (array) ( $parse['errors'] ?? [] );
        $this->messages->markParsed( $message_id, $result, $errors );

        if ( ! $result->matchedMessage() ) {
            $this->messages->markUnknown( $message_id, [ 'reason' => 'No parser matched the message.' ] );
            $this->messages->recordEvent(
                $mailbox_id,
                $message_id,
                'message_unknown',
                'unknown',
                'communications_inbound.unknown:' . $message_id,
                [ 'provider_message_id' => $normalized->providerMessageId() ]
            );

            return [ 'ok' => true, 'status' => 'unknown' ];
        }

        $handler = $this->handlers->resolve( $result->handlerKey() );
        if ( $handler === null ) {
            $this->messages->markUnknown(
                $message_id,
                [
                    'reason'      => 'No enabled handler is registered for this classification.',
                    'handler_key' => $result->handlerKey(),
                ]
            );

            return [ 'ok' => true, 'status' => 'unknown' ];
        }

        try {
            $handled = $handler->handle( $message_row, $normalized, $result );
        } catch ( \Throwable $e ) {
            $this->messages->markFailed( $message_id, 'handler_failed', $e->getMessage() );
            $this->messages->recordEvent(
                $mailbox_id,
                $message_id,
                'handler_failed',
                'failed',
                'communications_inbound.handler_failed:' . $message_id . ':' . $handler->key(),
                [],
                $result->parserKey(),
                $handler->key(),
                $e->getMessage()
            );

            return [ 'ok' => false, 'status' => 'failed', 'error' => $e->getMessage() ];
        }

        $status = (string) ( $handled['status'] ?? 'handled' );
        $metadata = is_array( $handled['metadata'] ?? null ) ? (array) $handled['metadata'] : [];

        foreach ( (array) ( $handled['links'] ?? [] ) as $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }
            $this->messages->linkEntity(
                $message_id,
                (string) ( $link['module_slug'] ?? '' ),
                (string) ( $link['entity_type'] ?? '' ),
                (int) ( $link['entity_id'] ?? 0 ),
                (string) ( $link['link_type'] ?? '' ),
                is_array( $link['metadata'] ?? null ) ? (array) $link['metadata'] : []
            );
        }

        if ( $status === 'unknown' || empty( $handled['handled'] ) ) {
            $this->messages->markUnknown( $message_id, $metadata );
            return [ 'ok' => true, 'status' => 'unknown' ];
        }

        $this->messages->markHandled( $message_id, $handler->key(), $metadata );
        $this->messages->recordEvent(
            $mailbox_id,
            $message_id,
            'handler_completed',
            'handled',
            'communications_inbound.handler:' . $message_id . ':' . $handler->key(),
            $metadata,
            $result->parserKey(),
            $handler->key()
        );

        return [ 'ok' => true, 'status' => 'handled' ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reprocessMessage( int $message_id, bool $force = false ): array {
        $config = Settings::config();
        if ( ! $force && empty( $config['allow_reprocess'] ) ) {
            return [ 'ok' => false, 'error' => 'Inbound message reprocessing is disabled.' ];
        }

        $message_row = $this->messages->findById( $message_id );
        if ( ! is_array( $message_row ) ) {
            return [ 'ok' => false, 'error' => 'Inbound message was not found.' ];
        }

        $mailbox = $this->mailboxes->findByEmail( (string) ( $message_row['sender_email'] ?? '' ) );
        if ( ! is_array( $mailbox ) ) {
            $mailbox = $this->mailboxes->findByEmail( (string) ( json_decode( (string) ( $message_row['raw_provider_payload_json'] ?? '' ), true )['provider_mailbox'] ?? '' ) );
        }
        if ( ! is_array( $mailbox ) ) {
            $mailbox = $this->mailboxes->findByEmail( (string) ( Settings::mailboxes()[0]['mailbox_email'] ?? '' ) );
        }

        $raw_payload = json_decode( (string) ( $message_row['raw_provider_payload_json'] ?? '' ), true );
        if ( ! is_array( $raw_payload ) ) {
            return [ 'ok' => false, 'error' => 'Stored provider payload is unavailable for replay.' ];
        }

        $normalized = $this->normalizer->normalizeGmailMessage( is_array( $mailbox ) ? $mailbox : [], $raw_payload );
        $this->attachment_storage->storeForMessage( is_array( $mailbox ) ? $mailbox : [], $message_row, $normalized, $raw_payload );
        $parse = $this->parser_engine->evaluate( $normalized );
        $result = $parse['result'];
        $errors = (array) ( $parse['errors'] ?? [] );
        $this->messages->markParsed( $message_id, $result, $errors );

        if ( ! $result->matchedMessage() ) {
            $this->messages->markUnknown( $message_id, [ 'reason' => 'No parser matched the message during replay.' ] );
            return [
                'ok'             => true,
                'message_id'     => $message_id,
                'classification' => '',
                'status'         => 'unknown',
            ];
        }

        $handler = $this->handlers->resolve( $result->handlerKey() );
        if ( $handler === null ) {
            $this->messages->markUnknown(
                $message_id,
                [
                    'reason'      => 'No enabled handler is registered for this classification during replay.',
                    'handler_key' => $result->handlerKey(),
                ]
            );

            return [
                'ok'             => true,
                'message_id'     => $message_id,
                'classification' => $result->classification(),
                'status'         => 'unknown',
            ];
        }

        try {
            $handled = $handler->handle( $message_row, $normalized, $result );
        } catch ( \Throwable $e ) {
            $this->messages->markFailed( $message_id, 'handler_failed', $e->getMessage() );
            return [
                'ok'             => false,
                'message_id'     => $message_id,
                'classification' => $result->classification(),
                'status'         => 'failed',
                'error'          => $e->getMessage(),
            ];
        }

        $status = (string) ( $handled['status'] ?? 'handled' );
        $metadata = is_array( $handled['metadata'] ?? null ) ? (array) ( $handled['metadata'] ?? [] ) : [];

        foreach ( (array) ( $handled['links'] ?? [] ) as $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }
            $this->messages->linkEntity(
                $message_id,
                (string) ( $link['module_slug'] ?? '' ),
                (string) ( $link['entity_type'] ?? '' ),
                (int) ( $link['entity_id'] ?? 0 ),
                (string) ( $link['link_type'] ?? '' ),
                is_array( $link['metadata'] ?? null ) ? (array) ( $link['metadata'] ?? [] ) : []
            );
        }

        if ( $status === 'unknown' || empty( $handled['handled'] ) ) {
            $this->messages->markUnknown( $message_id, $metadata );
        } else {
            $this->messages->markHandled( $message_id, $handler->key(), $metadata );
        }

        return [
            'ok'            => true,
            'message_id'    => $message_id,
            'classification'=> $result->classification(),
            'status'        => $status,
            'metadata'      => $metadata,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function attachmentsForMessage( int $message_id ): array {
        return $this->attachments->attachmentsForMessage( $message_id );
    }
}
