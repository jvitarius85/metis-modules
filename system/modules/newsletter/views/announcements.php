<?php
if (!defined('METIS_ROOT')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view announcements.</div>';
    return;
}

metis_newsletter_ensure_schema();
$can_manage = metis_newsletter_can_manage();

$dashboard_url = metis_portal_url('newsletter', 'dashboard');
$announcements_url = metis_portal_url('newsletter', 'announcements');
$campaigns_url = metis_portal_url('newsletter', 'campaigns');
$templates_url = metis_portal_url('newsletter', 'theme');
$lists_url = metis_portal_url('newsletter', 'lists');
$subscribers_url = metis_portal_url('newsletter', 'subscribers');
$compose_mode = isset(metis_request_get()['compose']) && (string) metis_request_get()['compose'] === '1';

$snapshot = \Metis\Modules\Newsletter\ReadService::announcementsSnapshot();
$lists = is_array($snapshot['lists'] ?? null) ? $snapshot['lists'] : [];
$announcements = is_array($snapshot['announcements'] ?? null) ? $snapshot['announcements'] : [];
$announcement_lists_map = is_array($snapshot['announcement_lists_map'] ?? null) ? $snapshot['announcement_lists_map'] : [];
?>

<div class="metis-newsletter" data-can-manage="<?php echo metis_escape_attr($can_manage ? '1' : '0'); ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Announcement Blasts' ) ); ?></h1>
    <p class="metis-subtitle">Send fast branded announcement blasts and review blast history separately from campaigns.</p>

    <div id="metis-newsletter-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-list-layout">
        <aside class="metis-list-sidebar">
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Newsletter</div>
                <nav class="metis-list-sidebar-nav">
                    <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($dashboard_url); ?>">Dashboard</a>
                    <a class="metis-list-sidebar-nav-item is-active" href="<?php echo metis_escape_url($announcements_url); ?>">Announcements</a>
                    <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($campaigns_url); ?>">Campaigns</a>
                    <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($templates_url); ?>">Theme</a>
                    <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($lists_url); ?>">Lists</a>
                    <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($subscribers_url); ?>">Subscribers</a>
                </nav>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Search</div>
                <input id="metis-newsletter-announcement-search" class="metis-input" type="text" placeholder="Subject or list">
            </div>
            <?php if ($can_manage) : ?>
            <div class="metis-list-sidebar-actions">
                <button id="metis-newsletter-open-announcement-modal" type="button" class="metis-btn metis-btn-xs">Announcement Blast</button>
            </div>
            <?php endif; ?>
        </aside>

        <div class="metis-list-content">
            <table class="metis-premium-table metis-newsletter-table" id="metis-newsletter-announcements-panel">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Subject</th>
                        <th class="metis-premium-cell" scope="col">Lists</th>
                        <th class="metis-premium-cell" scope="col">Status</th>
                        <th class="metis-premium-cell" scope="col">Recipients</th>
                        <th class="metis-premium-cell" scope="col">Sent</th>
                        <th class="metis-premium-cell" scope="col">Updated</th>
                    </tr>
                </thead>
                <tbody id="metis-newsletter-announcement-rows">
                    <?php foreach ($announcements as $announcement) :
                        $announcement_id = (int) ($announcement['id'] ?? 0);
                        $status = (string) ($announcement['status'] ?? 'draft');
                        $row_lists = $announcement_lists_map[$announcement_id] ?? [];
                        $search_blob = strtolower(trim(implode(' ', [
                            (string) ($announcement['subject'] ?? ''),
                            (string) ($announcement['name'] ?? ''),
                            implode(' ', array_map(static fn($x) => (string) ($x['name'] ?? ''), $row_lists)),
                        ])));
                    ?>
                        <tr class="metis-premium-row metis-newsletter-row"
                            data-search="<?php echo metis_escape_attr($search_blob); ?>"
                            data-campaign-id="<?php echo metis_escape_attr((string) $announcement_id); ?>"
                            data-campaign-code="<?php echo metis_escape_attr((string) ($announcement['campaign_code'] ?? '')); ?>"
                            data-campaign-status="<?php echo metis_escape_attr($status); ?>"
                            data-open-details="1">
                            <td class="metis-premium-cell">
                                <div><strong><?php echo metis_escape_html((string) ($announcement['subject'] ?? '')); ?></strong></div>
                                <div class="metis-muted"><?php echo metis_escape_html((string) ($announcement['name'] ?? '')); ?></div>
                            </td>
                            <td class="metis-premium-cell">
                                <?php if (!empty($row_lists)) : ?>
                                    <div class="metis-newsletter-chip-wrap">
                                        <?php foreach ($row_lists as $list_row) : ?>
                                            <span class="metis-chip"><?php echo metis_escape_html((string) ($list_row['name'] ?? '')); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else : ?><span class="metis-muted">—</span><?php endif; ?>
                            </td>
                            <td class="metis-premium-cell"><span class="metis-chip <?php echo $status === 'sent' ? 'metis-chip-success' : ''; ?>"><?php echo metis_escape_html($status); ?></span></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html((string) ((int) ($announcement['total_recipients'] ?? 0))); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html((string) ((int) ($announcement['sent_count'] ?? 0))); ?></td>
                            <td class="metis-premium-cell"><?php echo metis_escape_html(metis_newsletter_format_datetime((string) ($announcement['updated_at'] ?? ''))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($announcements)) : ?><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="6">No announcement blasts sent yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'lists' => $lists,
        'ui' => [
            'view' => 'announcements',
            'compose' => $compose_mode ? 1 : 0,
            'announcements_url' => $announcements_url,
            'announcements_compose_url' => rtrim($announcements_url, '/') . '/?compose=1',
        ],
    ]); ?></script>

    <div id="metis-newsletter-campaign-detail-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal metis-newsletter-modal-inner">
            <h3 class="metis-modal-title" id="metis-newsletter-campaign-detail-title">Announcement Details</h3>
            <div class="metis-newsletter-progress-wrap">
                <div class="metis-newsletter-progress-head">
                    <div id="metis-newsletter-progress-summary" class="metis-muted">No announcement selected.</div>
                    <div id="metis-newsletter-progress-current" class="metis-muted"></div>
                </div>
                <div class="metis-newsletter-usage-bar"><div id="metis-newsletter-progress-bar" class="metis-newsletter-usage-bar-fill" style="width:0%;"></div></div>
            </div>
            <table class="metis-premium-table metis-newsletter-table" id="metis-newsletter-campaign-detail-panel">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Recipient</th>
                        <th class="metis-premium-cell" scope="col">Email</th>
                        <th class="metis-premium-cell" scope="col">CID</th>
                        <th class="metis-premium-cell" scope="col">Status</th>
                        <th class="metis-premium-cell" scope="col">Opened</th>
                        <th class="metis-premium-cell" scope="col">Clicked</th>
                    </tr>
                </thead>
                <tbody id="metis-newsletter-campaign-detail-rows"></tbody>
            </table>
            <div class="metis-form-actions">
                <button type="button" class="metis-btn metis-btn-ghost metis-newsletter-cancel">Close</button>
            </div>
        </div>
    </div>

    <?php if ($can_manage) : ?>
    <div id="metis-newsletter-announcement-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal metis-newsletter-modal-inner">
            <h3 class="metis-modal-title">Send Announcement Blast</h3>
            <p class="metis-muted" style="margin:0 0 12px;">The organization logo/header, closing line, and footer links are applied automatically.</p>
            <div class="metis-form-grid">
                <div class="metis-field metis-field-full">
                    <label for="metis-newsletter-announcement-subject">Subject</label>
                    <input id="metis-newsletter-announcement-subject" class="metis-input" type="text" maxlength="191" placeholder="Announcement subject">
                </div>
                <div class="metis-field metis-field-full">
                    <label for="metis-newsletter-announcement-body">Body</label>
                    <textarea id="metis-newsletter-announcement-body" class="metis-input" rows="10" placeholder="Write the announcement body here. Plain text or simple HTML is allowed."></textarea>
                </div>
                <div class="metis-field metis-field-full">
                    <label>Target Lists</label>
                    <div class="metis-newsletter-audience-mode" id="metis-newsletter-announcement-lists">
                        <?php foreach ($lists as $list) :
                            $list_id = (int) ($list['id'] ?? 0);
                            if ($list_id < 1) continue;
                        ?>
                            <label><input type="checkbox" data-announcement-list-id="<?php echo metis_escape_attr((string) $list_id); ?>"> <?php echo metis_escape_html((string) ($list['name'] ?? '')); ?></label>
                        <?php endforeach; ?>
                        <?php if (empty($lists)) : ?><div class="metis-muted">No active lists available.</div><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="metis-form-actions">
                <button type="button" class="metis-btn metis-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" class="metis-btn" id="metis-newsletter-send-announcement">Send Announcement Blast</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
