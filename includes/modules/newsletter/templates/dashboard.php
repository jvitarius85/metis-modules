<?php
if (!defined('ABSPATH')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view newsletter.</div>';
    return;
}

metis_newsletter_ensure_schema();
global $wpdb;

$lists_table = Metis_Tables::get('newsletter_lists');
$subs_table = Metis_Tables::get('newsletter_subs');
$campaigns_table = Metis_Tables::get('newsletter_campaigns');
$messages_table = Metis_Tables::get('newsletter_messages');
$contacts_table = Metis_Tables::get('contacts');

$dashboard_url = metis_portal_url('newsletter', 'dashboard');
$campaigns_url = metis_portal_url('newsletter', 'campaigns');
$templates_url = metis_portal_url('newsletter', 'templates');
$lists_url = metis_portal_url('newsletter', 'lists');
$subscribers_url = metis_portal_url('newsletter', 'subscribers');
$contact_url_base = metis_portal_url('contacts', 'contact');
$portal_usage_url = metis_portal_url('portal', 'email_usage');

$kpi_lists = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$lists_table} WHERE is_active = 1");
$kpi_campaigns = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$campaigns_table}");
$kpi_subscribers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$subs_table} WHERE status = 'subscribed'");
$kpi_queued = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$messages_table} WHERE status = 'queued'");
$kpi_sent_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$messages_table} WHERE status = 'sent'");
$kpi_30d = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$messages_table} WHERE status='sent' AND sent_at >= %s",
        (new DateTimeImmutable('now', metis_newsletter_resolved_timezone()))->modify('-30 days')->format('Y-m-d H:i:s')
    )
);

$recent_subscribers = $wpdb->get_results(
    "SELECT
        c.cid,
        c.first_name,
        c.last_name,
        c.email,
        GROUP_CONCAT(DISTINCT l.name ORDER BY l.name SEPARATOR '||') AS list_names,
        MAX(s.updated_at) AS updated_at
     FROM {$subs_table} s
     INNER JOIN {$contacts_table} c ON c.id = s.contact_id
     INNER JOIN {$lists_table} l ON l.id = s.list_id
     WHERE s.status = 'subscribed' AND l.is_active = 1
     GROUP BY c.id, c.cid, c.first_name, c.last_name, c.email
     ORDER BY updated_at DESC
     LIMIT 7",
    ARRAY_A
) ?: [];

$recent_campaigns = $wpdb->get_results(
    "SELECT c.id, c.name, c.status, c.updated_at, c.sent_count, c.total_recipients, c.open_count, c.click_count
     FROM {$campaigns_table} c
     ORDER BY c.updated_at DESC, c.id DESC
     LIMIT 7",
    ARRAY_A
) ?: [];
?>

<div class="metis-newsletter" data-can-manage="<?php echo esc_attr(metis_newsletter_can_manage() ? '1' : '0'); ?>">
    <h1 class="mw-page-title">Newsletter</h1>
    <p class="mw-subtitle">Campaign operations, audience growth, and delivery monitoring.</p>
<div class="mw-list-layout">
    <!-- Sidebar -->
    <aside class="mw-list-sidebar">
        <?php if ( metis_people_can_manage('settings','edit' ) ) : ?>
        <div class="mw-list-sidebar-actions">
            <div class="mw-list-sidebar-label">Newsletter</div>
            <nav class="mw-list-sidebar-nav">
                <a class="mw-list-sidebar-nav-item is-active" href="<?php echo esc_url($dashboard_url); ?>">Dashboard</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($campaigns_url); ?>">Campaigns</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($templates_url); ?>">Templates</a>
                <a class="mw-list-sidebar-nav-item " href="<?php echo esc_url($lists_url); ?>">Lists</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($subscribers_url); ?>">Subscribers</a>
            </nav>
        </div>
        <?php endif; ?>
    </aside>
    
    <div class="mw-list-content">
    <div class="metis-newsletter-stats">
        <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Subscribers</div><div class="metis-newsletter-stat-value"><?php echo esc_html((string) $kpi_subscribers); ?></div><div class="mw-muted">Confirmed subscribers</div></article>
        <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Last 30 days</div><div class="metis-newsletter-stat-value"><?php echo esc_html((string) $kpi_30d); ?></div><div class="mw-muted">Delivered messages</div></article>
        <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Queued emails</div><div class="metis-newsletter-stat-value"><?php echo esc_html((string) $kpi_queued); ?></div><div class="mw-muted">Waiting in send queue</div></article>
        <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Total sent emails</div><div class="metis-newsletter-stat-value"><?php echo esc_html((string) $kpi_sent_total); ?></div><div class="mw-muted">All-time newsletter sends</div></article>
        <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Active lists</div><div class="metis-newsletter-stat-value"><?php echo esc_html((string) $kpi_lists); ?></div><div class="mw-muted"><a href="<?php echo esc_url($lists_url); ?>">Manage lists</a></div></article>
        <article class="metis-newsletter-stat"><div class="metis-newsletter-stat-label">Campaigns</div><div class="metis-newsletter-stat-value"><?php echo esc_html((string) $kpi_campaigns); ?></div><div class="mw-muted"><a href="<?php echo esc_url($portal_usage_url); ?>">Email usage by module</a></div></article>
    </div>

    <div class="metis-newsletter-grid metis-newsletter-grid-halves">
        <section class="mw-premium-wrap metis-newsletter-dashboard-card">
            <div class="metis-newsletter-card-head">
                <h3 class="metis-people-section-title">Subscribers</h3>
                <a class="metis-newsletter-folder-link" href="<?php echo esc_url($subscribers_url); ?>" title="Open subscribers">📁</a>
            </div>
            <div class="metis-newsletter-dashboard-list">
                <?php foreach ($recent_subscribers as $sub) :
                    $name = trim((string) ($sub['first_name'] ?? '') . ' ' . (string) ($sub['last_name'] ?? ''));
                    $name = $name !== '' ? $name : (string) ($sub['email'] ?? '');
                    $cid = (string) ($sub['cid'] ?? '');
                    $list_names = array_values(array_filter(array_map('trim', explode('||', (string) ($sub['list_names'] ?? ''))), static fn($x) => $x !== ''));
                    $href = $cid !== '' ? ($contact_url_base . '?cid=' . rawurlencode($cid)) : '';
                ?>
                    <div class="metis-newsletter-dashboard-row" <?php if ($href !== '') : ?>data-row-href="<?php echo esc_url($href); ?>"<?php endif; ?>>
                        <div class="metis-newsletter-dashboard-main">
                            <div class="metis-newsletter-dashboard-title"><?php echo esc_html($name); ?></div>
                            <div class="mw-muted"><?php echo esc_html((string) ($sub['email'] ?? '')); ?></div>
                            <?php if (!empty($list_names)) : ?>
                                <div class="metis-newsletter-chip-wrap" style="margin-top:4px;">
                                    <?php foreach ($list_names as $list_name) : ?><span class="mw-chip"><?php echo esc_html($list_name); ?></span><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div><span class="mw-chip mw-chip-success">ACTIVE</span></div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($recent_subscribers)) : ?><div class="mw-muted">No subscribers yet.</div><?php endif; ?>
            </div>
        </section>

        <section class="mw-premium-wrap metis-newsletter-dashboard-card">
            <div class="metis-newsletter-card-head">
                <h3 class="metis-people-section-title">Newsletters</h3>
                <a class="metis-newsletter-folder-link" href="<?php echo esc_url($campaigns_url); ?>" title="Open campaigns">📁</a>
            </div>
            <div class="metis-newsletter-dashboard-list">
                <?php foreach ($recent_campaigns as $campaign) :
                    $campaign_id = (int) ($campaign['id'] ?? 0);
                    $status = (string) ($campaign['status'] ?? 'draft');
                    $href = add_query_arg(['compose' => '1', 'campaign_id' => $campaign_id], $campaigns_url);
                    $denominator = max(1, (int) ($campaign['sent_count'] ?? 0));
                    $engagement = (int) round((((int) ($campaign['open_count'] ?? 0)) + ((int) ($campaign['click_count'] ?? 0))) / $denominator * 100);
                    $engagement = max(0, min(100, $engagement));
                ?>
                    <div class="metis-newsletter-dashboard-row" data-row-href="<?php echo esc_url($href); ?>">
                        <div class="metis-newsletter-dashboard-main">
                            <div class="metis-newsletter-dashboard-title"><?php echo esc_html((string) ($campaign['name'] ?? '')); ?></div>
                            <div class="mw-muted"><?php echo esc_html(metis_newsletter_format_datetime((string) ($campaign['updated_at'] ?? ''))); ?></div>
                        </div>
                        <div class="metis-newsletter-dashboard-status">
                            <span class="mw-chip <?php echo $status === 'sent' ? 'mw-chip-success' : ''; ?>"><?php echo esc_html(strtoupper($status)); ?></span>
                            <span class="metis-newsletter-rate-chip"><?php echo esc_html((string) $engagement); ?>%</span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($recent_campaigns)) : ?><div class="mw-muted">No campaigns yet.</div><?php endif; ?>
            </div>
        </section>
    </div>
    </div>
    </div>

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'ui' => ['view' => 'dashboard'],
    ]); ?></script>
</div>
