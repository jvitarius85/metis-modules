<?php
if (!defined('METIS_ROOT')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view newsletter lists.</div>';
    return;
}

metis_newsletter_ensure_schema();
$db = metis_db();

$can_manage = metis_newsletter_can_manage();

$lists_table = Metis_Tables::get('newsletter_lists');
$subs_table = Metis_Tables::get('newsletter_subs');
$contacts_table = Metis_Tables::get('contacts');

$dashboard_url = metis_portal_url('newsletter', 'dashboard');
$campaigns_url = metis_portal_url('newsletter', 'campaigns');
$templates_url = metis_portal_url('newsletter', 'theme');
$lists_url = metis_portal_url('newsletter', 'lists');
$subscribers_url = metis_portal_url('newsletter', 'subscribers');
$contact_url_base = metis_portal_url('contacts', 'contact');

$selected_list_id = isset(metis_request_get()['list_id']) ? max(0, (int) metis_request_get()['list_id']) : 0;

$lists = $db->fetchAll(
    "SELECT l.id, l.list_key, l.name, l.description, l.is_active, l.updated_at,
            COALESCE(SUM(CASE WHEN s.status='subscribed' THEN 1 ELSE 0 END), 0) AS subscribed_count,
            COALESCE(SUM(CASE WHEN s.status IN ('bounced','rejected') THEN 1 ELSE 0 END), 0) AS blocked_count
     FROM {$lists_table} l
     LEFT JOIN {$subs_table} s ON s.list_id = l.id
     GROUP BY l.id
     ORDER BY l.name ASC",
) ?: [];

$selected_list = null;
foreach ($lists as $list_row) {
    if ((int) ($list_row['id'] ?? 0) === $selected_list_id) {
        $selected_list = $list_row;
        break;
    }
}

$list_subscribers = [];
if ($selected_list_id > 0) {
    $list_subscribers = $db->fetchAll(
        "SELECT s.id, s.status, s.updated_at, c.cid, c.first_name, c.last_name, c.email
         FROM {$subs_table} s
         INNER JOIN {$contacts_table} c ON c.id = s.contact_id
         WHERE s.list_id = %d
           AND s.status = 'subscribed'
         ORDER BY c.first_name ASC, c.last_name ASC, c.email ASC
         LIMIT 500",
        [ $selected_list_id ]
    ) ?: [];
}
?>

<div class="metis-newsletter" data-can-manage="<?php echo metis_escape_attr($can_manage ? '1' : '0'); ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Newsletter Lists' ) ); ?></h1>
    <p class="metis-subtitle">Manage list definitions and list membership from contacts.</p>

    <div id="metis-newsletter-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-list-layout">

    <!-- Sidebar -->
    <aside class="metis-list-sidebar">
        <div class="metis-list-sidebar-section">
            <div class="metis-list-sidebar-label">Newsletter</div>
            <nav class="metis-list-sidebar-nav">
                <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($dashboard_url); ?>">Dashboard</a>
                <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($campaigns_url); ?>">Campaigns</a>
                <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($templates_url); ?>">Theme</a>
                <a class="metis-list-sidebar-nav-item is-active" href="<?php echo metis_escape_url($lists_url); ?>">Lists</a>
                <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($subscribers_url); ?>">Subscribers</a>
            </nav>
        </div>
        <div class="metis-list-sidebar-section">
            <div class="metis-list-sidebar-label">Search</div>
            <input id="metis-newsletter-list-search" class="metis-input" type="text" placeholder="List name or description">
        </div>
        <?php if ($can_manage) : ?>
        <div class="metis-list-sidebar-actions">
            <button id="metis-newsletter-new-list" type="button" class="metis-btn metis-btn-xs">New List</button>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content -->
    <div class="metis-list-content">
    <table class="metis-premium-table metis-newsletter-table" id="metis-newsletter-lists-panel">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell" scope="col">List</th>
                <th class="metis-premium-cell" scope="col">Subscribers</th>
                <th class="metis-premium-cell" scope="col">Blocked</th>
                <th class="metis-premium-cell" scope="col">Status</th>
                <th class="metis-premium-cell" scope="col">Updated</th>
            </tr>
        </thead>
        <tbody id="metis-newsletter-list-rows">
            <?php foreach ($lists as $list) :
                $list_id = (int) ($list['id'] ?? 0);
                $search_blob = strtolower(trim(implode(' ', [
                    (string) ($list['name'] ?? ''),
                    (string) ($list['description'] ?? ''),
                ])));
                $href = metis_add_query_arg(['list_id' => $list_id], $lists_url);
            ?>
                <tr class="metis-premium-row metis-newsletter-row" data-search="<?php echo metis_escape_attr($search_blob); ?>" data-row-href="<?php echo metis_escape_url($href); ?>">
                    <td class="metis-premium-cell">
                        <div><strong><?php echo metis_escape_html((string) ($list['name'] ?? '')); ?></strong></div>
                        <div class="metis-muted"><?php echo metis_escape_html((string) ($list['description'] ?? '')); ?></div>
                    </td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html((string) ((int) ($list['subscribed_count'] ?? 0))); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html((string) ((int) ($list['blocked_count'] ?? 0))); ?></td>
                    <td class="metis-premium-cell"><span class="metis-chip <?php echo !empty($list['is_active']) ? 'metis-chip-success' : ''; ?>"><?php echo !empty($list['is_active']) ? 'active' : 'inactive'; ?></span></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html(metis_newsletter_format_datetime((string) ($list['updated_at'] ?? ''))); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($lists)) : ?><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="5">No lists yet.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <?php if ($selected_list) : ?>
        <section class="metis-premium-wrap">
            <h3 class="metis-people-section-title" style="margin:0 0 12px;"><?php echo metis_escape_html((string) ($selected_list['name'] ?? '')); ?>: Subscribers</h3>
            <div class="metis-form-grid">
                <div class="metis-field metis-field-third">
                    <label>List Name</label>
                    <input id="metis-newsletter-list-name" class="metis-input" type="text" maxlength="191" value="<?php echo metis_escape_attr((string) ($selected_list['name'] ?? '')); ?>">
                </div>
                <div class="metis-field metis-field-third">
                    <label>Description</label>
                    <input id="metis-newsletter-list-description" class="metis-input" type="text" maxlength="255" value="<?php echo metis_escape_attr((string) ($selected_list['description'] ?? '')); ?>">
                </div>
                <div class="metis-field metis-field-third">
                    <label>Status</label>
                    <select id="metis-newsletter-list-active" class="metis-select">
                        <option value="1" <?php metis_attr_selected((int) ($selected_list['is_active'] ?? 0), 1); ?>>Active</option>
                        <option value="0" <?php metis_attr_selected((int) ($selected_list['is_active'] ?? 0), 0); ?>>Inactive</option>
                    </select>
                </div>
            </div>
            <?php if ($can_manage) : ?>
                <div class="metis-form-actions" style="margin-top:10px;">
                    <button type="button" class="metis-btn" id="metis-newsletter-save-selected-list" data-list-id="<?php echo metis_escape_attr((string) $selected_list_id); ?>">Save List</button>
                </div>
            <?php endif; ?>

            <table class="metis-premium-table metis-newsletter-table" id="metis-newsletter-selected-list-subs-panel" style="margin-top:10px;">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Subscriber</th>
                        <th class="metis-premium-cell" scope="col">Email</th>
                        <th class="metis-premium-cell" scope="col">CID</th>
                        <th class="metis-premium-cell" scope="col">Updated</th>
                    </tr>
                </thead>
                <tbody id="metis-newsletter-selected-list-subs-rows">
                    <?php foreach ($list_subscribers as $sub_row) :
                        $cid = (string) ($sub_row['cid'] ?? '');
                        $name = trim((string) ($sub_row['first_name'] ?? '') . ' ' . (string) ($sub_row['last_name'] ?? ''));
                        $href = $cid !== '' ? ($contact_url_base . '?cid=' . rawurlencode($cid)) : '';
                        $email = strtolower(trim((string) ($sub_row['email'] ?? '')));
                    ?>
                        <tr class="metis-premium-row metis-newsletter-row">
                            <td class="metis-premium-cell"><?php echo metis_escape_html($name !== '' ? $name : '—'); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html($email); ?></td>
                            <td class="metis-premium-cell">
                                <?php if ($href !== '') : ?><a href="<?php echo metis_escape_url($href); ?>"><?php echo metis_escape_html($cid); ?></a><?php else : ?>—<?php endif; ?>
                            </td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html(metis_newsletter_format_datetime((string) ($sub_row['updated_at'] ?? ''))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($list_subscribers)) : ?><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="4">No active subscribers in this list.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    </div><!-- /metis-list-content -->
    </div><!-- /metis-list-layout -->

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'ui' => [
            'view' => 'lists',
            'lists_url' => $lists_url,
        ],
    ]); ?></script>
</div>
