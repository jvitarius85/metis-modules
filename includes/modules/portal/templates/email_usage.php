<?php if (!defined('ABSPATH')) exit; ?>
<?php
global $wpdb;

$usage_table = Metis_Tables::get('newsletter_google_usage_daily');
$messages_table = Metis_Tables::get('newsletter_messages');
$campaigns_table = Metis_Tables::get('newsletter_campaigns');

$today = metis_date('Y-m-d', time(), metis_newsletter_resolved_timezone());
$daily_limit = function_exists('metis_newsletter_google_usage_daily_limit') ? metis_newsletter_google_usage_daily_limit() : 2000;
$today_sent = 0;
$daily_rows = [];

if (function_exists('metis_newsletter_table_exists') && metis_newsletter_table_exists($usage_table)) {
    $today_sent = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(sent_count), 0) FROM {$usage_table} WHERE usage_date = %s",
        $today
    ));
    $daily_rows = $wpdb->get_results(
        "SELECT usage_date, COALESCE(SUM(sent_count), 0) AS sent_total
         FROM {$usage_table}
         GROUP BY usage_date
         ORDER BY usage_date DESC
         LIMIT 30",
        ARRAY_A
    ) ?: [];
}

$newsletter_sent_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$messages_table} WHERE status='sent'");
$newsletter_30 = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$messages_table} WHERE status='sent' AND sent_at >= %s",
        (new DateTimeImmutable('now', metis_newsletter_resolved_timezone()))->modify('-30 days')->format('Y-m-d H:i:s')
    )
);
$newsletter_campaigns = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$campaigns_table}");
$today_pct = $daily_limit > 0 ? min(100, max(0, (int) round(($today_sent / $daily_limit) * 100))) : 0;
?>

<h1 class="mw-page-title">Email Usage</h1>
<p class="mw-subtitle">System-wide email usage and per-module send volume.</p>

<div class="metis-newsletter-stats">
    <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Today (Google)</div><div class="metis-newsletter-stat-value"><?php echo esc_html(number_format_i18n($today_sent)); ?></div><div class="mw-muted"><?php echo esc_html((string) $today_pct); ?>% of <?php echo esc_html(number_format_i18n($daily_limit)); ?></div></article>
    <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Newsletter Total Sent</div><div class="metis-newsletter-stat-value"><?php echo esc_html(number_format_i18n($newsletter_sent_total)); ?></div><div class="mw-muted">All time</div></article>
    <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Newsletter Last 30 Days</div><div class="metis-newsletter-stat-value"><?php echo esc_html(number_format_i18n($newsletter_30)); ?></div><div class="mw-muted">Sent messages</div></article>
    <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Newsletter Campaigns</div><div class="metis-newsletter-stat-value"><?php echo esc_html(number_format_i18n($newsletter_campaigns)); ?></div><div class="mw-muted">Tracked campaigns</div></article>
</div>

<section class="mw-premium-table metis-newsletter-table">
    <div class="mw-premium-row mw-premium-header" style="grid-template-columns:1.4fr 0.9fr 0.9fr 0.9fr;">
        <div class="mw-premium-cell">Module</div>
        <div class="mw-premium-cell">Today</div>
        <div class="mw-premium-cell">Last 30 days</div>
        <div class="mw-premium-cell">All time</div>
    </div>
    <div class="mw-premium-row" style="grid-template-columns:1.4fr 0.9fr 0.9fr 0.9fr;">
        <div class="mw-premium-cell"><strong>Newsletter</strong></div>
        <div class="mw-premium-cell"><?php echo esc_html(number_format_i18n($today_sent)); ?></div>
        <div class="mw-premium-cell"><?php echo esc_html(number_format_i18n($newsletter_30)); ?></div>
        <div class="mw-premium-cell"><?php echo esc_html(number_format_i18n($newsletter_sent_total)); ?></div>
    </div>
</section>

<section class="mw-premium-table metis-newsletter-table">
    <div class="mw-premium-row mw-premium-header" style="grid-template-columns:1fr 1fr;">
        <div class="mw-premium-cell">Date</div>
        <div class="mw-premium-cell">Google Send Count</div>
    </div>
    <?php if (!empty($daily_rows)) : ?>
        <?php foreach ($daily_rows as $d) : ?>
            <div class="mw-premium-row" style="grid-template-columns:1fr 1fr;">
                <div class="mw-premium-cell"><?php echo esc_html((string) ($d['usage_date'] ?? '')); ?></div>
                <div class="mw-premium-cell"><?php echo esc_html(number_format_i18n((int) ($d['sent_total'] ?? 0))); ?></div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <div class="mw-premium-row" style="grid-template-columns:1fr;">
            <div class="mw-premium-cell mw-muted">No synced usage data yet.</div>
        </div>
    <?php endif; ?>
</section>
