<?php
if (!defined('ABSPATH')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view subscribers.</div>';
    return;
}

metis_newsletter_ensure_schema();
global $wpdb;

$can_manage = metis_newsletter_can_manage();

$lists_table = Metis_Tables::get('newsletter_lists');
$subs_table = Metis_Tables::get('newsletter_subs');
$contacts_table = Metis_Tables::get('contacts');

$dashboard_url = metis_portal_url('newsletter', 'dashboard');
$campaigns_url = metis_portal_url('newsletter', 'campaigns');
$templates_url = metis_portal_url('newsletter', 'templates');
$lists_url = metis_portal_url('newsletter', 'lists');
$subscribers_url = metis_portal_url('newsletter', 'subscribers');
$contact_url_base = metis_portal_url('contacts', 'contact');

$rows = $wpdb->get_results(
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
     LIMIT 1000",
    ARRAY_A
) ?: [];
?>

<div class="metis-newsletter" data-can-manage="<?php echo esc_attr($can_manage ? '1' : '0'); ?>">
    <h1 class="mw-page-title">Newsletter Subscribers</h1>
    <p class="mw-subtitle">Review subscription status by contact and list.</p>

    <div id="metis-newsletter-alert" class="mw-alert" style="display:none;"></div>

    <div class="mw-list-layout">

    <!-- Sidebar -->
    <aside class="mw-list-sidebar">
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Newsletter</div>
            <nav class="mw-list-sidebar-nav">
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($dashboard_url); ?>">Dashboard</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($campaigns_url); ?>">Campaigns</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($templates_url); ?>">Templates</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($lists_url); ?>">Lists</a>
                <a class="mw-list-sidebar-nav-item is-active" href="<?php echo esc_url($subscribers_url); ?>">Subscribers</a>
            </nav>
        </div>
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Search</div>
            <input id="metis-newsletter-subscriber-search" class="mw-input" type="text" placeholder="Name, email, list, or CID">
        </div>
    </aside>

    <!-- Main content -->
    <div class="mw-list-content">
    <section class="mw-premium-table metis-newsletter-table" id="metis-newsletter-subscribers-panel">
        <div class="mw-premium-row mw-premium-header">
            <div class="mw-premium-cell">Subscriber</div>
            <div class="mw-premium-cell">Lists</div>
            <div class="mw-premium-cell">CID</div>
            <div class="mw-premium-cell">Updated</div>
        </div>
        <div id="metis-newsletter-subscriber-rows">
            <?php foreach ($rows as $row) :
                $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                $cid = (string) ($row['cid'] ?? '');
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $list_names = array_values(array_filter(array_map('trim', explode('||', (string) ($row['list_names'] ?? ''))), static fn($x) => $x !== ''));
                $href = $cid !== '' ? ($contact_url_base . '?cid=' . rawurlencode($cid)) : '';
                $search_blob = strtolower(trim(implode(' ', [
                    $name,
                    $email,
                    $cid,
                    implode(' ', $list_names),
                ])));
            ?>
                <div class="mw-premium-row metis-newsletter-row" data-search="<?php echo esc_attr($search_blob); ?>" <?php if ($href !== '') : ?>data-row-href="<?php echo esc_url($href); ?>"<?php endif; ?>>
                    <div class="mw-premium-cell">
                        <strong><?php echo esc_html($name !== '' ? $name : '—'); ?></strong>
                        <?php if ($email !== '') : ?><div class="mw-muted"><?php echo esc_html($email); ?></div><?php endif; ?>
                    </div>
                    <div class="mw-premium-cell">
                        <div class="metis-newsletter-chip-wrap">
                            <?php foreach ($list_names as $list_name) : ?><span class="mw-chip"><?php echo esc_html($list_name); ?></span><?php endforeach; ?>
                            <?php if (empty($list_names)) : ?><span class="mw-muted">—</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="mw-premium-cell"><?php echo esc_html($cid !== '' ? $cid : '—'); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html(metis_newsletter_format_datetime((string) ($row['updated_at'] ?? ''))); ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No subscribers found.</div></div><?php endif; ?>
        </div>
    </section>

    </div><!-- /mw-list-content -->
    </div><!-- /mw-list-layout -->

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'ui' => [
            'view' => 'subscribers',
        ],
    ]); ?></script>
</div>
