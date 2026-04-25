<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class NewsletterModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \Metis_Logger::info( 'Newsletter bootstrap loaded' );

        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );

        if ( \class_exists( '\Metis_Cron_Manager' ) ) {
            \Metis_Cron_Manager::register_task(
                'newsletter_email_processing',
                static function (): array {
                    return QueueService::processQueue( 100 );
                },
                [
                    'label'    => 'Newsletter Email Processing',
                    'interval' => 60,
                    'lock_ttl' => 5 * MINUTE_IN_SECONDS,
                    'module'   => 'newsletter',
                ]
            );

            \Metis_Cron_Manager::register_task(
                'newsletter_analytics_updates',
                static function (): array {
                    return DeliveryService::googleSyncUsageForDate( '' );
                },
                [
                    'label'    => 'Newsletter Analytics Updates',
                    'interval' => HOUR_IN_SECONDS,
                    'lock_ttl' => 15 * MINUTE_IN_SECONDS,
                    'module'   => 'newsletter',
                ]
            );
        } else {
            \Metis_Logger::warn( 'Newsletter cron tasks skipped: Metis_Cron_Manager unavailable' );
        }
    }

    public static function canView(): bool { return Access::canView(); }
    public static function canManage(): bool { return Access::canManage(); }
    public static function baseUrl(): string { return Support::baseUrl(); }
    public static function tableExists( string $table ): bool { return SchemaManager::tableExists( $table ); }
    public static function columnExists( string $table, string $column ): bool { return SchemaManager::columnExists( $table, $column ); }
    public static function addColumnIfMissing( string $table, string $column, string $definition ): void { SchemaManager::addColumnIfMissing( $table, $column, $definition ); }
    public static function addIndexIfMissing( string $table, string $index_name, string $index_def ): void { SchemaManager::addIndexIfMissing( $table, $index_name, $index_def ); }
    public static function ensureSchema(): void { SchemaManager::ensureSchema(); }
    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'newsletter_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        self::ensureSchema();
    }
    public static function resolvedTimezone(): \DateTimeZone { return Support::resolvedTimezone(); }
    public static function formatDatetime( string $mysql_datetime, string $format = 'm/d/y g:ia' ): string { return Support::formatDatetime( $mysql_datetime, $format ); }
    public static function renderTemplate( string $html, array $contact ): string { return Support::renderTemplate( $html, $contact ); }
    public static function ensureEmailContainer( string $html ): string { return Support::ensureEmailContainer( $html ); }
    public static function b64url( string $value ): string { return Support::b64url( $value ); }
    public static function plainTextFromHtml( string $html ): string { return Support::plainTextFromHtml( $html ); }
    public static function gmailSend( string $to_email, string $subject, string $html_body, array $message_opts = [] ): array { return DeliveryService::gmailSend( $to_email, $subject, $html_body, $message_opts ); }
    public static function queueCampaignMessages( int $campaign_id ): array { return QueueService::queueCampaignMessages( $campaign_id ); }
    public static function processQueue( int $limit = 100 ): array { return QueueService::processQueue( $limit ); }
    public static function googleUsageDailyLimit(): int { return Support::googleUsageDailyLimit(); }
    public static function googleSyncUsageForDate( string $date_ymd = '' ): array { return DeliveryService::googleSyncUsageForDate( $date_ymd ); }
    public static function handleOpenRoute( \Metis_Http_Request $request ): \Metis_Http_Response { return DeliveryService::handleOpenRoute( $request ); }
    public static function handleClickRoute( \Metis_Http_Request $request ): \Metis_Http_Response { return DeliveryService::handleClickRoute( $request ); }
    public static function handleUnsubscribeRoute( \Metis_Http_Request $request ): \Metis_Http_Response { return DeliveryService::handleUnsubscribeRoute( $request ); }
    public static function handleManageRoute( \Metis_Http_Request $request ): \Metis_Http_Response { return DeliveryService::handleManageRoute( $request ); }
    public static function handlePublicUnsubscribeRoute( \Metis_Http_Request $request ): \Metis_Http_Response { return DeliveryService::handlePublicUnsubscribeRoute( $request ); }
    public static function handlePublicManageRoute( \Metis_Http_Request $request ): \Metis_Http_Response { return DeliveryService::handlePublicManageRoute( $request ); }
    public static function handlePublicViewRoute( \Metis_Http_Request $request ): \Metis_Http_Response { return DeliveryService::handlePublicViewRoute( $request ); }
}
