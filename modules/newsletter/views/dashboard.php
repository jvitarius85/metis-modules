<?php
if (!defined('METIS_ROOT')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view newsletter.</div>';
    return;
}

metis_newsletter_ensure_schema();
$db = metis_db();

$lists_table = Metis_Tables::get('newsletter_lists');
$subs_table = Metis_Tables::get('newsletter_subs');
$campaigns_table = Metis_Tables::get('newsletter_campaigns');
$messages_table = Metis_Tables::get('newsletter_messages');
$contacts_table = Metis_Tables::get('contacts');

$dashboard_url = metis_portal_url('newsletter', 'dashboard');
$campaigns_url = metis_portal_url('newsletter', 'campaigns');
$templates_url = metis_portal_url('newsletter', 'theme');
$lists_url = metis_portal_url('newsletter', 'lists');
$subscribers_url = metis_portal_url('newsletter', 'subscribers');
$contact_url_base = metis_portal_url('contacts', 'contact');
$portal_usage_url = metis_portal_url('settings', 'email');

$kpi_lists = (int) $db->scalar( "SELECT COUNT(*) FROM {$lists_table} WHERE is_active = 1" );
$kpi_campaigns = (int) $db->scalar( "SELECT COUNT(*) FROM {$campaigns_table}" );
$kpi_subscribers = (int) $db->scalar( "SELECT COUNT(*) FROM {$subs_table} WHERE status = 'subscribed'" );
$kpi_queued = (int) $db->scalar( "SELECT COUNT(*) FROM {$messages_table} WHERE status = 'queued'" );
$kpi_sent_total = (int) $db->scalar( "SELECT COUNT(*) FROM {$messages_table} WHERE status = 'sent'" );
$kpi_30d = (int) $db->scalar(
    "SELECT COUNT(*) FROM {$messages_table} WHERE status='sent' AND sent_at >= %s",
    [ ( new DateTimeImmutable( 'now', metis_newsletter_resolved_timezone() ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' ) ]
);

$recent_subscribers = $db->fetchAll(
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
) ?: [];

$recent_campaigns = $db->fetchAll(
    "SELECT c.id, c.campaign_code, c.name, c.status, c.updated_at, c.sent_count, c.total_recipients, c.open_count, c.click_count
     FROM {$campaigns_table} c
     ORDER BY c.updated_at DESC, c.id DESC
     LIMIT 7",
) ?: [];
?>

<div class="metis-newsletter" data-can-manage="<?php echo metis_escape_attr(metis_newsletter_can_manage() ? '1' : '0'); ?>">
    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_label( 'Newsletter' ) ); ?></h1>
    <p class="mw-subtitle">Campaign operations, audience growth, and delivery monitoring.</p>
<?php metis_render_sidebar_layout([
    'sidebar' => static function () use ($dashboard_url, $campaigns_url, $templates_url, $lists_url, $subscribers_url) { ?>
        <?php if ( metis_people_can_manage('settings','edit' ) ) : ?>
        <div class="mw-list-sidebar-actions">
            <div class="mw-list-sidebar-label">Newsletter</div>
            <nav class="mw-list-sidebar-nav">
                <a class="mw-list-sidebar-nav-item is-active" href="<?php echo metis_escape_url($dashboard_url); ?>">Dashboard</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url($campaigns_url); ?>">Campaigns</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url($templates_url); ?>">Theme</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url($lists_url); ?>">Lists</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo metis_escape_url($subscribers_url); ?>">Subscribers</a>
            </nav>
        </div>
        <?php endif; ?>
    <?php },
    'content' => static function () use ($kpi_subscribers, $kpi_30d, $kpi_queued, $kpi_sent_total, $kpi_lists, $kpi_campaigns, $lists_url, $portal_usage_url, $recent_subscribers, $contact_url_base, $recent_campaigns, $campaigns_url, $subscribers_url) { ?>
    <div class="kpi-card-grid">
        <article class="kpi-card"><div class="kpi-label">Subscribers</div><div class="kpi-value"><?php echo metis_escape_html((string) $kpi_subscribers); ?></div><div class="kpi-trend">Confirmed subscribers</div></article>
        <article class="kpi-card"><div class="kpi-label">Last 30 days</div><div class="kpi-value"><?php echo metis_escape_html((string) $kpi_30d); ?></div><div class="kpi-trend">Delivered messages</div></article>
        <article class="kpi-card"><div class="kpi-label">Queued emails</div><div class="kpi-value"><?php echo metis_escape_html((string) $kpi_queued); ?></div><div class="kpi-trend">Waiting in send queue</div></article>
        <article class="kpi-card"><div class="kpi-label">Total sent emails</div><div class="kpi-value"><?php echo metis_escape_html((string) $kpi_sent_total); ?></div><div class="kpi-trend">All-time newsletter sends</div></article>
        <article class="kpi-card"><div class="kpi-label">Active lists</div><div class="kpi-value"><?php echo metis_escape_html((string) $kpi_lists); ?></div><div class="kpi-trend"><a href="<?php echo metis_escape_url($lists_url); ?>">Manage lists</a></div></article>
        <article class="kpi-card"><div class="kpi-label">Campaigns</div><div class="kpi-value"><?php echo metis_escape_html((string) $kpi_campaigns); ?></div><div class="kpi-trend"><a href="<?php echo metis_escape_url($portal_usage_url); ?>">Email settings + usage</a></div></article>
    </div>

    <div class="metis-newsletter-grid metis-newsletter-grid-halves">
        <section class="mw-premium-wrap metis-newsletter-dashboard-card">
            <div class="metis-newsletter-card-head">
                <h3 class="metis-people-section-title">Subscribers</h3>
                <a class="metis-newsletter-folder-link" href="<?php echo metis_escape_url($subscribers_url); ?>" title="Open subscribers">📁</a>
            </div>
            <div class="metis-newsletter-dashboard-list">
                <?php foreach ($recent_subscribers as $sub) :
                    $name = trim((string) ($sub['first_name'] ?? '') . ' ' . (string) ($sub['last_name'] ?? ''));
                    $name = $name !== '' ? $name : (string) ($sub['email'] ?? '');
                    $cid = (string) ($sub['cid'] ?? '');
                    $list_names = array_values(array_filter(array_map('trim', explode('||', (string) ($sub['list_names'] ?? ''))), static fn($x) => $x !== ''));
                    $href = $cid !== '' ? ($contact_url_base . '?cid=' . rawurlencode($cid)) : '';
                ?>
                    <div class="metis-newsletter-dashboard-row" <?php if ($href !== '') : ?>data-row-href="<?php echo metis_escape_url($href); ?>"<?php endif; ?>>
                        <div class="metis-newsletter-dashboard-main">
                            <div class="metis-newsletter-dashboard-title"><?php echo metis_escape_html($name); ?></div>
                            <div class="mw-muted"><?php echo metis_escape_html((string) ($sub['email'] ?? '')); ?></div>
                            <?php if (!empty($list_names)) : ?>
                                <div class="metis-newsletter-chip-wrap" style="margin-top:4px;">
                                    <?php foreach ($list_names as $list_name) : ?><span class="mw-chip"><?php echo metis_escape_html($list_name); ?></span><?php endforeach; ?>
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
                <a class="metis-newsletter-folder-link" href="<?php echo metis_escape_url($campaigns_url); ?>" title="Open campaigns">📁</a>
            </div>
            <div class="metis-newsletter-dashboard-list">
                <?php foreach ($recent_campaigns as $campaign) :
                    $campaign_id = (int) ($campaign['id'] ?? 0);
                    $campaign_code = (string) ($campaign['campaign_code'] ?? '');
                    $status = (string) ($campaign['status'] ?? 'draft');
                    $campaign_ref = $campaign_code !== '' ? $campaign_code : (string) $campaign_id;
                    $href = rtrim($campaigns_url, '/') . '/' . rawurlencode($campaign_ref) . '/edit/';
                    $denominator = max(1, (int) ($campaign['sent_count'] ?? 0));
                    $engagement = (int) round((((int) ($campaign['open_count'] ?? 0)) + ((int) ($campaign['click_count'] ?? 0))) / $denominator * 100);
                    $engagement = max(0, min(100, $engagement));
                ?>
                    <div class="metis-newsletter-dashboard-row" data-row-href="<?php echo metis_escape_url($href); ?>">
                        <div class="metis-newsletter-dashboard-main">
                            <div class="metis-newsletter-dashboard-title"><?php echo metis_escape_html((string) ($campaign['name'] ?? '')); ?></div>
                            <div class="mw-muted"><?php echo metis_escape_html(metis_newsletter_format_datetime((string) ($campaign['updated_at'] ?? ''))); ?></div>
                        </div>
                        <div class="metis-newsletter-dashboard-status">
                            <span class="mw-chip <?php echo $status === 'sent' ? 'mw-chip-success' : ''; ?>"><?php echo metis_escape_html(strtoupper($status)); ?></span>
                            <span class="metis-newsletter-rate-chip"><?php echo metis_escape_html((string) $engagement); ?>%</span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($recent_campaigns)) : ?><div class="mw-muted">No campaigns yet.</div><?php endif; ?>
            </div>
        </section>
    </div>
    <?php },
]); ?>

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'ui' => ['view' => 'dashboard'],
    ]); ?></script>
</div>
