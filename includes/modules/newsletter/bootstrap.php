<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __DIR__, 2 ) . '/core/autoload.php';

\Metis\Modules\Newsletter\NewsletterModule::boot();

function metis_newsletter_can_view(): bool { return \Metis\Modules\Newsletter\NewsletterModule::canView(); }
function metis_newsletter_can_manage(): bool { return \Metis\Modules\Newsletter\NewsletterModule::canManage(); }
function metis_newsletter_base_url(): string { return \Metis\Modules\Newsletter\NewsletterModule::baseUrl(); }
function metis_newsletter_table_exists(string $table): bool { return \Metis\Modules\Newsletter\NewsletterModule::tableExists( $table ); }
function metis_newsletter_column_exists(string $table, string $column): bool { return \Metis\Modules\Newsletter\NewsletterModule::columnExists( $table, $column ); }
function metis_newsletter_add_column_if_missing(string $table, string $column, string $definition): void { \Metis\Modules\Newsletter\NewsletterModule::addColumnIfMissing( $table, $column, $definition ); }
function metis_newsletter_add_index_if_missing(string $table, string $index_name, string $index_def): void { \Metis\Modules\Newsletter\NewsletterModule::addIndexIfMissing( $table, $index_name, $index_def ); }
function metis_newsletter_ensure_schema(): void { \Metis\Modules\Newsletter\NewsletterModule::ensureSchema(); }
function metis_newsletter_resolved_timezone(): DateTimeZone { return \Metis\Modules\Newsletter\NewsletterModule::resolvedTimezone(); }
function metis_newsletter_format_datetime(string $mysql_datetime, string $format = 'm/d/y g:ia'): string { return \Metis\Modules\Newsletter\NewsletterModule::formatDatetime( $mysql_datetime, $format ); }
function metis_newsletter_render_template(string $html, array $contact): string { return \Metis\Modules\Newsletter\NewsletterModule::renderTemplate( $html, $contact ); }
function metis_newsletter_ensure_email_container(string $html): string { return \Metis\Modules\Newsletter\NewsletterModule::ensureEmailContainer( $html ); }
function metis_newsletter_b64url(string $value): string { return \Metis\Modules\Newsletter\NewsletterModule::b64url( $value ); }
function metis_newsletter_plain_text_from_html(string $html): string { return \Metis\Modules\Newsletter\NewsletterModule::plainTextFromHtml( $html ); }
function metis_newsletter_gmail_send(string $to_email, string $subject, string $html_body, array $message_opts = []): array { return \Metis\Modules\Newsletter\NewsletterModule::gmailSend( $to_email, $subject, $html_body, $message_opts ); }
function metis_newsletter_queue_campaign_messages(int $campaign_id): array { return \Metis\Modules\Newsletter\NewsletterModule::queueCampaignMessages( $campaign_id ); }
function metis_newsletter_process_queue(int $limit = 100): array { return \Metis\Modules\Newsletter\NewsletterModule::processQueue( $limit ); }
function metis_newsletter_google_usage_daily_limit(): int { return \Metis\Modules\Newsletter\NewsletterModule::googleUsageDailyLimit(); }
function metis_newsletter_google_sync_usage_for_date(string $date_ymd = ''): array { return \Metis\Modules\Newsletter\NewsletterModule::googleSyncUsageForDate( $date_ymd ); }
function metis_newsletter_handle_open_route( Metis_Http_Request $request ): Metis_Http_Response { return \Metis\Modules\Newsletter\NewsletterModule::handleOpenRoute( $request ); }
function metis_newsletter_handle_click_route( Metis_Http_Request $request ): Metis_Http_Response { return \Metis\Modules\Newsletter\NewsletterModule::handleClickRoute( $request ); }
function metis_newsletter_handle_unsubscribe_route( Metis_Http_Request $request ): Metis_Http_Response { return \Metis\Modules\Newsletter\NewsletterModule::handleUnsubscribeRoute( $request ); }
function metis_newsletter_handle_manage_route( Metis_Http_Request $request ): Metis_Http_Response { return \Metis\Modules\Newsletter\NewsletterModule::handleManageRoute( $request ); }
