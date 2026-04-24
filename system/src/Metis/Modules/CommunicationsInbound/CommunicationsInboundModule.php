<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Core\Application;
use Metis\Modules\CommunicationsInbound\Handlers\BounceHandler;
use Metis\Modules\CommunicationsInbound\Handlers\GrandyStashHandler;
use Metis\Modules\CommunicationsInbound\Handlers\UnsubscribeHandler;
use Metis\Modules\CommunicationsInbound\Parsers\BounceParser;
use Metis\Modules\CommunicationsInbound\Parsers\GrandyStashParser;
use Metis\Modules\CommunicationsInbound\Parsers\UnsubscribeParser;

final class CommunicationsInboundModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        \Metis_Logger::info( 'Communications inbound core loaded' );

        self::registerServices();
        self::registerParsersAndHandlers();

        if ( Application::has_service( 'job_workers' ) ) {
            \metis_job_workers()->register(
                'communications_inbound.sync_mailbox',
                static function ( array $payload ): array {
                    return self::syncMailbox( (string) ( $payload['mailbox_email'] ?? '' ), $payload );
                }
            );

            \metis_job_workers()->register(
                'communications_inbound.renew_watch',
                static function (): array {
                    return self::renewDueWatches();
                }
            );
        }

        if ( \class_exists( 'Metis_Cron_Manager' ) ) {
            \Metis_Cron_Manager::register_task(
                'communications_inbound_watch_renewal',
                static function (): array {
                    return self::renewDueWatches();
                },
                [
                    'label'    => 'Inbound Email Watch Renewal',
                    'interval' => 6 * HOUR_IN_SECONDS,
                    'lock_ttl' => 15 * MINUTE_IN_SECONDS,
                    'module'   => 'communications_inbound',
                ]
            );
        }

        if ( \function_exists( 'metis_webhook_register_provider' ) ) {
            \metis_webhook_register_provider(
                'gmail_pubsub',
                [
                    'verify'  => [ self::class, 'verifyWebhookRequest' ],
                    'process' => [ self::class, 'processWebhookEvent' ],
                ]
            );
        }

        self::$booted = true;
    }

    public static function ensureSchema(): void {
        SchemaManager::ensureSchema();
        if ( \class_exists( '\Metis\Modules\GrandyStash\GrandyStashSchemaManager' ) ) {
            \Metis\Modules\GrandyStash\GrandyStashSchemaManager::ensureSchema();
        }
    }

    public static function ensureMailboxes(): void {
        self::ensureSchema();
        self::mailboxRepository()->ensureConfiguredMailboxes();
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(): array {
        return Settings::config();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function mailboxes(): array {
        return Settings::mailboxes();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function syncMailbox( string $mailbox_email, array $options = [] ): array {
        self::ensureSchema();
        return self::processor()->syncMailboxByEmail( $mailbox_email, $options );
    }

    /**
     * @return array<string, mixed>
     */
    public static function watchMailbox( string $mailbox_email, bool $force = false ): array {
        self::ensureSchema();
        return self::watchManager()->watchMailboxByEmail( $mailbox_email, $force );
    }

    /**
     * @return array<string, mixed>
     */
    public static function renewDueWatches(): array {
        self::ensureSchema();
        self::ensureMailboxes();
        return self::watchManager()->renewDueWatches();
    }

    /**
     * @return array<string, mixed>
     */
    public static function reprocessMessage( int $message_id, bool $force = false ): array {
        self::ensureSchema();
        return self::processor()->reprocessMessage( $message_id, $force );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function attachmentsForMessage( int $message_id ): array {
        self::ensureSchema();
        return self::processor()->attachmentsForMessage( $message_id );
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function processWebhookEvent( array $event, ?\Metis_Http_Request $request = null ): array {
        self::ensureSchema();
        return self::processor()->queueMailboxSyncFromWebhook( $event );
    }

    /**
     * @return array<string, mixed>
     */
    public static function verifyWebhookRequest( \Metis_Http_Request $request ): array {
        self::ensureSchema();
        return self::verifier()->verifyRequest( $request );
    }

    private static function registerServices(): void {
        $registry = Application::registry();

        if ( ! $registry->has( 'communications_inbound.google' ) ) {
            $registry->singleton( 'communications_inbound.google', static fn (): WorkspaceGoogleService => new WorkspaceGoogleService() );
        }

        if ( ! $registry->has( 'communications_inbound.gmail_client' ) ) {
            $registry->singleton(
                'communications_inbound.gmail_client',
                static fn (): GmailClient => new GmailClient( Application::service( 'communications_inbound.google' ) )
            );
        }

        if ( ! $registry->has( 'communications_inbound.mailboxes' ) ) {
            $registry->singleton(
                'communications_inbound.mailboxes',
                static fn (): MailboxRepository => new MailboxRepository( Application::service( 'db' ) )
            );
        }

        if ( ! $registry->has( 'communications_inbound.messages' ) ) {
            $registry->singleton(
                'communications_inbound.messages',
                static fn (): InboundMessageRepository => new InboundMessageRepository( Application::service( 'db' ) )
            );
        }

        if ( ! $registry->has( 'communications_inbound.attachments' ) ) {
            $registry->singleton(
                'communications_inbound.attachments',
                static fn (): InboundAttachmentRepository => new InboundAttachmentRepository( Application::service( 'db' ) )
            );
        }

        if ( ! $registry->has( 'communications_inbound.normalizer' ) ) {
            $registry->singleton( 'communications_inbound.normalizer', static fn (): InboundMessageNormalizer => new InboundMessageNormalizer() );
        }

        if ( ! $registry->has( 'communications_inbound.parsers' ) ) {
            $registry->singleton( 'communications_inbound.parsers', static fn (): ParserRegistry => new ParserRegistry() );
        }

        if ( ! $registry->has( 'communications_inbound.handlers' ) ) {
            $registry->singleton( 'communications_inbound.handlers', static fn (): HandlerRegistry => new HandlerRegistry() );
        }

        if ( ! $registry->has( 'communications_inbound.parser_engine' ) ) {
            $registry->singleton(
                'communications_inbound.parser_engine',
                static fn (): ParserEngine => new ParserEngine( Application::service( 'communications_inbound.parsers' ) )
            );
        }

        if ( ! $registry->has( 'communications_inbound.verifier' ) ) {
            $registry->singleton( 'communications_inbound.verifier', static fn (): PubSubPushVerifier => new PubSubPushVerifier() );
        }

        if ( ! $registry->has( 'communications_inbound.processor' ) ) {
            $registry->singleton(
                'communications_inbound.processor',
                static fn (): InboundProcessor => new InboundProcessor(
                    Application::service( 'communications_inbound.gmail_client' ),
                    Application::service( 'communications_inbound.mailboxes' ),
                    Application::service( 'communications_inbound.messages' ),
                    Application::service( 'communications_inbound.attachments' ),
                    Application::service( 'communications_inbound.normalizer' ),
                    Application::service( 'communications_inbound.parser_engine' ),
                    Application::service( 'communications_inbound.handlers' ),
                    new AttachmentStorageService(
                        Application::service( 'communications_inbound.gmail_client' ),
                        Application::service( 'communications_inbound.attachments' )
                    )
                )
            );
        }

        if ( ! $registry->has( 'communications_inbound.watch_manager' ) ) {
            $registry->singleton(
                'communications_inbound.watch_manager',
                static fn (): WatchManager => new WatchManager(
                    Application::service( 'communications_inbound.gmail_client' ),
                    Application::service( 'communications_inbound.mailboxes' )
                )
            );
        }
    }

    private static function registerParsersAndHandlers(): void {
        /** @var ParserRegistry $parsers */
        $parsers = Application::service( 'communications_inbound.parsers' );
        $parsers->register( new BounceParser() );
        $parsers->register( new GrandyStashParser() );
        $parsers->register( new UnsubscribeParser() );

        /** @var HandlerRegistry $handlers */
        $handlers = Application::service( 'communications_inbound.handlers' );
        $config = [
            'enable_bounce_handler'      => false,
            'enable_unsubscribe_handler' => false,
            'enable_grandys_stash_handler' => false,
        ];

        try {
            $config = array_merge( $config, Settings::config() );
        } catch ( \Throwable $e ) {
            \Metis_Logger::warn(
                'Communications inbound settings unavailable during bootstrap; skipping handler registration',
                [
                    'module'  => 'communications_inbound',
                    'service' => 'bootstrap',
                    'error'   => $e->getMessage(),
                ]
            );
            return;
        }

        if ( ! empty( $config['enable_bounce_handler'] ) ) {
            $handlers->register( new BounceHandler() );
        }
        if ( ! empty( $config['enable_unsubscribe_handler'] ) ) {
            $handlers->register( new UnsubscribeHandler() );
        }
        if ( ! empty( $config['enable_grandys_stash_handler'] ) ) {
            $handlers->register( new GrandyStashHandler() );
        }
    }

    private static function verifier(): PubSubPushVerifier {
        /** @var PubSubPushVerifier $service */
        $service = Application::service( 'communications_inbound.verifier' );
        return $service;
    }

    private static function processor(): InboundProcessor {
        /** @var InboundProcessor $service */
        $service = Application::service( 'communications_inbound.processor' );
        return $service;
    }

    private static function watchManager(): WatchManager {
        /** @var WatchManager $service */
        $service = Application::service( 'communications_inbound.watch_manager' );
        return $service;
    }

    private static function mailboxRepository(): MailboxRepository {
        /** @var MailboxRepository $service */
        $service = Application::service( 'communications_inbound.mailboxes' );
        return $service;
    }
}
