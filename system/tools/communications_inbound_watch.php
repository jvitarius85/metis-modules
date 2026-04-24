<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

define( 'METIS_STANDALONE', true );
define( 'METIS_PATH', dirname( __DIR__, 2 ) . '/' );

require_once dirname( __DIR__ ) . '/src/Metis/Core/CoreBootstrap.php';
metis_core_bootstrap( 'standalone_bootstrap' );
metis_standalone_boot();

/**
 * @return mixed
 */
function metis_cli_json_safe_value( mixed $value ): mixed {
    if ( is_array( $value ) ) {
        $normalized = [];
        foreach ( $value as $key => $item ) {
            $normalized[ $key ] = metis_cli_json_safe_value( $item );
        }

        return $normalized;
    }

    if ( is_object( $value ) ) {
        if ( method_exists( $value, '__toString' ) ) {
            return metis_cli_json_safe_string( (string) $value );
        }

        return [
            '__class' => $value::class,
        ];
    }

    if ( is_resource( $value ) ) {
        return '[resource]';
    }

    if ( is_string( $value ) ) {
        return metis_cli_json_safe_string( $value );
    }

    return $value;
}

function metis_cli_json_safe_string( string $value ): string {
    if ( $value === '' || preg_match( '//u', $value ) === 1 ) {
        return $value;
    }

    if ( function_exists( 'mb_convert_encoding' ) ) {
        $converted = @mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
        if ( is_string( $converted ) && preg_match( '//u', $converted ) === 1 ) {
            return $converted;
        }
    }

    if ( function_exists( 'iconv' ) ) {
        $converted = @iconv( 'UTF-8', 'UTF-8//IGNORE', $value );
        if ( is_string( $converted ) && preg_match( '//u', $converted ) === 1 ) {
            return $converted;
        }
    }

    return '[non-utf8-string]';
}

$args = $argv;
array_shift( $args );
$command = $args[0] ?? 'status';

$options = [];
foreach ( $args as $arg ) {
    if ( ! str_starts_with( (string) $arg, '--' ) ) {
        continue;
    }

    $pair = explode( '=', substr( (string) $arg, 2 ), 2 );
    $options[ $pair[0] ] = $pair[1] ?? '1';
}

$print = static function ( array $payload ): void {
    $normalized = metis_cli_json_safe_value( $payload );
    $json = json_encode(
        $normalized,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    if ( $json === false ) {
        $json = json_encode(
            [
                'ok'    => false,
                'error' => 'CLI output encoding failed.',
                'data'  => print_r( $normalized, true ),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    fwrite( STDOUT, (string) $json . PHP_EOL );
};

if ( ! function_exists( 'metis_communications_inbound_boot' ) ) {
    fwrite( STDERR, "Inbound email core is unavailable.\n" );
    exit( 1 );
}

metis_communications_inbound_boot();
\Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::ensureSchema();
\Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::ensureMailboxes();

switch ( $command ) {
    case 'status':
        /** @var \Metis\Modules\CommunicationsInbound\MailboxRepository $mailboxes */
        $mailboxes = \Metis\Core\Application::service( 'communications_inbound.mailboxes' );
        $print(
            [
                'ok'        => true,
                'mailboxes' => $mailboxes->enabledMailboxes(),
            ]
        );
        exit( 0 );

    case 'messages':
        $mailbox_email = strtolower( trim( (string) ( $options['mailbox'] ?? '' ) ) );
        $limit = max( 1, min( 100, (int) ( $options['limit'] ?? 20 ) ) );
        $table = \Metis_Tables::get( 'communications_inbound_messages' );
        $params = [];
        $sql = "SELECT id, mailbox_id, provider, provider_message_id, provider_thread_id, rfc_message_id, subject,
                       from_email, sender_email, processing_status, classification, parser_key, handler_key,
                       received_at, sent_at, error_code, error_message, parser_metadata_json, handling_metadata_json
                FROM {$table}";
        if ( $mailbox_email !== '' ) {
            $sql .= " WHERE LOWER(sender_email) = %s
                      OR LOWER(from_email) = %s
                      OR mailbox_id IN (
                            SELECT id FROM " . \Metis_Tables::get( 'communications_inbound_mailboxes' ) . " WHERE LOWER(mailbox_email) = %s
                      )";
            $params[] = $mailbox_email;
            $params[] = $mailbox_email;
            $params[] = $mailbox_email;
        }
        $sql .= " ORDER BY COALESCE(received_at, sent_at, created_at, updated_at) DESC, id DESC LIMIT %d";
        $params[] = $limit;
        $rows = \metis_db()->fetchAll( $sql, $params ) ?: [];
        $messages = array_map(
            static function ( array $row ): array {
                $message_id = (int) ( $row['id'] ?? 0 );
                return [
                    'id'                 => $message_id,
                    'provider'           => (string) ( $row['provider'] ?? '' ),
                    'provider_message_id'=> (string) ( $row['provider_message_id'] ?? '' ),
                    'provider_thread_id' => (string) ( $row['provider_thread_id'] ?? '' ),
                    'rfc_message_id'     => (string) ( $row['rfc_message_id'] ?? '' ),
                    'subject'            => (string) ( $row['subject'] ?? '' ),
                    'from_email'         => (string) ( $row['from_email'] ?? '' ),
                    'sender_email'       => (string) ( $row['sender_email'] ?? '' ),
                    'processing_status'  => (string) ( $row['processing_status'] ?? '' ),
                    'classification'     => (string) ( $row['classification'] ?? '' ),
                    'parser_key'         => (string) ( $row['parser_key'] ?? '' ),
                    'handler_key'        => (string) ( $row['handler_key'] ?? '' ),
                    'received_at'        => (string) ( $row['received_at'] ?? '' ),
                    'sent_at'            => (string) ( $row['sent_at'] ?? '' ),
                    'error_code'         => (string) ( $row['error_code'] ?? '' ),
                    'error_message'      => (string) ( $row['error_message'] ?? '' ),
                    'parser_metadata'    => json_decode( (string) ( $row['parser_metadata_json'] ?? '' ), true ) ?: [],
                    'handling_metadata'  => json_decode( (string) ( $row['handling_metadata_json'] ?? '' ), true ) ?: [],
                    'attachments'        => \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::attachmentsForMessage( $message_id ),
                ];
            },
            array_values( array_filter( $rows, 'is_array' ) )
        );
        $print(
            [
                'ok'       => true,
                'mailbox'  => $mailbox_email,
                'limit'    => $limit,
                'messages' => $messages,
            ]
        );
        exit( 0 );

    case 'sent-events':
        $recipient = strtolower( trim( (string) ( $options['to'] ?? '' ) ) );
        $module = trim( (string) ( $options['module'] ?? '' ) );
        $limit = max( 1, min( 100, (int) ( $options['limit'] ?? 20 ) ) );
        $table = \Metis_Tables::get( 'email_send_events' );
        $sql = "SELECT id, event_at, module_slug, status, provider, to_email, subject, error_message, meta_json
                FROM {$table}";
        $params = [];
        $where = [];

        if ( $recipient !== '' ) {
            $where[] = 'LOWER(to_email) = %s';
            $params[] = $recipient;
        }

        if ( $module !== '' ) {
            $where[] = 'module_slug = %s';
            $params[] = metis_key_clean( $module );
        }

        if ( $where !== [] ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }

        $sql .= ' ORDER BY event_at DESC, id DESC LIMIT %d';
        $params[] = $limit;

        $rows = \metis_db()->fetchAll( $sql, $params ) ?: [];
        $events = array_map(
            static function ( array $row ): array {
                $meta = json_decode( (string) ( $row['meta_json'] ?? '' ), true );
                $meta = is_array( $meta ) ? $meta : [];

                return [
                    'id'                 => (int) ( $row['id'] ?? 0 ),
                    'event_at'           => (string) ( $row['event_at'] ?? '' ),
                    'module'             => (string) ( $row['module_slug'] ?? '' ),
                    'status'             => (string) ( $row['status'] ?? '' ),
                    'provider'           => (string) ( $row['provider'] ?? '' ),
                    'to_email'           => (string) ( $row['to_email'] ?? '' ),
                    'subject'            => (string) ( $row['subject'] ?? '' ),
                    'error_message'      => (string) ( $row['error_message'] ?? '' ),
                    'from_name'          => (string) ( $meta['from_name'] ?? '' ),
                    'from_email'         => (string) ( $meta['from_email'] ?? '' ),
                    'reply_to'           => (string) ( $meta['reply_to'] ?? '' ),
                    'internal_reference' => (string) ( $meta['internal_reference'] ?? '' ),
                    'fallback'           => (string) ( $meta['fallback'] ?? '' ),
                ];
            },
            array_values( array_filter( $rows, 'is_array' ) )
        );

        $print(
            [
                'ok'        => true,
                'recipient' => $recipient,
                'module'    => $module,
                'limit'     => $limit,
                'events'    => $events,
            ]
        );
        exit( 0 );

    case 'test':
        $mailbox_email = trim( (string) ( $options['mailbox'] ?? '' ) );
        if ( $mailbox_email === '' ) {
            fwrite( STDERR, "--mailbox=email@example.org is required for test.\n" );
            exit( 1 );
        }
        try {
            /** @var \Metis\Modules\CommunicationsInbound\MailboxRepository $mailboxes */
            $mailboxes = \Metis\Core\Application::service( 'communications_inbound.mailboxes' );
            $mailbox = $mailboxes->findByEmail( $mailbox_email );
            if ( ! is_array( $mailbox ) ) {
                $print( [ 'ok' => false, 'error' => 'Mailbox is not configured.' ] );
                exit( 0 );
            }

            /** @var \Metis\Modules\CommunicationsInbound\WorkspaceGoogleService $google */
            $google = \Metis\Core\Application::service( 'communications_inbound.google' );
            $diagnostic = $google->diagnoseMailbox( $mailbox );
            if ( empty( $diagnostic['ok'] ) ) {
                $print( $diagnostic );
                exit( 0 );
            }

            /** @var \Metis\Modules\CommunicationsInbound\GmailClient $gmail */
            $gmail = \Metis\Core\Application::service( 'communications_inbound.gmail_client' );
            $profile = $gmail->getProfile( $mailbox );
            if ( empty( $profile['ok'] ) ) {
                $print(
                    array_merge(
                        $diagnostic,
                        [
                            'ok'    => false,
                            'stage' => (string) ( $profile['stage'] ?? 'gmail_profile' ),
                            'error' => (string) ( $profile['error'] ?? 'Gmail profile request failed.' ),
                            'gmail' => $profile,
                        ]
                    )
                );
                exit( 0 );
            }

            $print(
                array_merge(
                    $diagnostic,
                    [
                        'ok'               => true,
                        'authenticated_as' => (string) ( $profile['emailAddress'] ?? '' ),
                        'history_id'       => (string) ( $profile['historyId'] ?? '' ),
                        'watch_ready'      => true,
                    ]
                )
            );
            exit( 0 );
        } catch ( Throwable $e ) {
            $print(
                [
                    'ok'        => false,
                    'stage'     => 'cli_test',
                    'error'     => $e->getMessage(),
                    'exception' => $e::class,
                ]
            );
            exit( 1 );
        }

    case 'watch':
        $mailbox = trim( (string) ( $options['mailbox'] ?? '' ) );
        if ( $mailbox === '' ) {
            fwrite( STDERR, "--mailbox=email@example.org is required for watch.\n" );
            exit( 1 );
        }
        $print( \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::watchMailbox( $mailbox, ! empty( $options['force'] ) ) );
        exit( 0 );

    case 'renew-due':
        $print( \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::renewDueWatches() );
        exit( 0 );

    case 'sync':
        $mailbox = trim( (string) ( $options['mailbox'] ?? '' ) );
        if ( $mailbox === '' ) {
            fwrite( STDERR, "--mailbox=email@example.org is required for sync.\n" );
            exit( 1 );
        }
        $print(
            \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::syncMailbox(
                $mailbox,
                [ 'force_full' => ! empty( $options['full'] ) ]
            )
        );
        exit( 0 );

    case 'reprocess':
        $message_id = (int) ( $options['message-id'] ?? 0 );
        if ( $message_id < 1 ) {
            fwrite( STDERR, "--message-id=123 is required for reprocess.\n" );
            exit( 1 );
        }
        $print( \Metis\Modules\CommunicationsInbound\CommunicationsInboundModule::reprocessMessage( $message_id, ! empty( $options['force'] ) ) );
        exit( 0 );
}

fwrite(
    STDERR,
    "Usage:\n"
    . "  php tools/communications_inbound_watch.php status\n"
    . "  php tools/communications_inbound_watch.php sent-events [--to=user@example.org] [--module=forms] [--limit=10]\n"
    . "  php tools/communications_inbound_watch.php test --mailbox=newsletter@example.org\n"
    . "  php tools/communications_inbound_watch.php watch --mailbox=newsletter@example.org [--force]\n"
    . "  php tools/communications_inbound_watch.php renew-due\n"
    . "  php tools/communications_inbound_watch.php sync --mailbox=newsletter@example.org [--full]\n"
    . "  php tools/communications_inbound_watch.php reprocess --message-id=123 [--force]\n"
);
exit( 1 );
