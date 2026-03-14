<?php
if (!defined('ABSPATH')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view newsletter.</div>';
    return;
}

metis_newsletter_ensure_schema();

global $wpdb;

$can_manage = metis_newsletter_can_manage();

$lists_table = Metis_Tables::get('newsletter_lists');
$templates_table = Metis_Tables::get('newsletter_templates');
$campaigns_table = Metis_Tables::get('newsletter_campaigns');
$campaign_lists_table = Metis_Tables::get('newsletter_campaign_lists');

$dashboard_url = metis_portal_url('newsletter', 'dashboard');
$campaigns_url = metis_portal_url('newsletter', 'campaigns');
$templates_url = metis_portal_url('newsletter', 'templates');
$lists_url = metis_portal_url('newsletter', 'lists');
$subscribers_url = metis_portal_url('newsletter', 'subscribers');
$campaign_view = sanitize_key((string) ($_GET['view'] ?? 'active'));
if (!in_array($campaign_view, ['active', 'archived', 'all'], true)) $campaign_view = 'active';
$edit_campaign_id = isset($_GET['campaign_id']) ? (int) $_GET['campaign_id'] : 0;
$compose_mode = (isset($_GET['compose']) && (string) $_GET['compose'] === '1') || $edit_campaign_id > 0;

$default_from_name = (string) Core_Settings_Service::get('newsletter_default_from_name', '');
$default_from_email = (string) Core_Settings_Service::get('newsletter_default_from_email', '');
$default_reply_to = (string) Core_Settings_Service::get('newsletter_default_reply_to', '');

$lists = $wpdb->get_results(
    "SELECT id, name, description, is_active FROM {$lists_table} WHERE is_active = 1 ORDER BY name ASC",
    ARRAY_A
) ?: [];

$templates = $wpdb->get_results(
    "SELECT id, name, subject, from_name, from_email, reply_to, doc_json, html_body FROM {$templates_table} WHERE is_active = 1 ORDER BY updated_at DESC, id DESC",
    ARRAY_A
) ?: [];

$campaign_where = "WHERE 1=1";
if ($campaign_view === 'active') {
    $campaign_where .= " AND c.status <> 'archived'";
} elseif ($campaign_view === 'archived') {
    $campaign_where .= " AND c.status = 'archived'";
}
$campaigns = $wpdb->get_results(
    "SELECT c.*, t.name AS template_name
     FROM {$campaigns_table} c
     LEFT JOIN {$templates_table} t ON t.id = c.template_id
     {$campaign_where}
     ORDER BY c.created_at DESC, c.id DESC
     LIMIT 200",
    ARRAY_A
) ?: [];

$campaign_lists = $wpdb->get_results(
    "SELECT cl.campaign_id, cl.list_id, l.name
     FROM {$campaign_lists_table} cl
     INNER JOIN {$lists_table} l ON l.id = cl.list_id
     ORDER BY cl.campaign_id ASC, l.name ASC",
    ARRAY_A
) ?: [];

$campaign_lists_map = [];
foreach ($campaign_lists as $cl) {
    $cid = (int) ($cl['campaign_id'] ?? 0);
    if ($cid < 1) continue;
    if (!isset($campaign_lists_map[$cid])) $campaign_lists_map[$cid] = [];
    $campaign_lists_map[$cid][] = [
        'id' => (int) ($cl['list_id'] ?? 0),
        'name' => (string) ($cl['name'] ?? ''),
    ];
}

$selected_campaign = null;
if ($compose_mode && $edit_campaign_id > 0) {
    $selected_campaign = $wpdb->get_row($wpdb->prepare(
        "SELECT c.*, t.name AS template_name
         FROM {$campaigns_table} c
         LEFT JOIN {$templates_table} t ON t.id = c.template_id
         WHERE c.id = %d
         LIMIT 1",
        $edit_campaign_id
    ), ARRAY_A);
    if (!$selected_campaign) {
        $edit_campaign_id = 0;
    }
}
?>

<div class="metis-newsletter" data-can-manage="<?php echo esc_attr($can_manage ? '1' : '0'); ?>">
    <h1 class="mw-page-title">Newsletter Campaigns</h1>
    <p class="mw-subtitle">Create and queue campaigns with a dedicated full-length composer.</p>

    <div id="metis-newsletter-alert" class="mw-alert" style="display:none;"></div>

    <?php if (!$compose_mode) : ?>
    <div class="mw-list-layout">

    <!-- Sidebar -->
    <aside class="mw-list-sidebar">
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Newsletter</div>
            <nav class="mw-list-sidebar-nav">
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($dashboard_url); ?>">Dashboard</a>
                <a class="mw-list-sidebar-nav-item is-active" href="<?php echo esc_url($campaigns_url); ?>">Campaigns</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($templates_url); ?>">Templates</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($lists_url); ?>">Lists</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($subscribers_url); ?>">Subscribers</a>
            </nav>
        </div>
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Filter</div>
            <nav class="mw-list-sidebar-nav">
                <a class="mw-list-sidebar-nav-item <?php echo $campaign_view === 'active' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['view' => 'active'], $campaigns_url)); ?>">Active</a>
                <a class="mw-list-sidebar-nav-item <?php echo $campaign_view === 'archived' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['view' => 'archived'], $campaigns_url)); ?>">Archived</a>
                <a class="mw-list-sidebar-nav-item <?php echo $campaign_view === 'all' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['view' => 'all'], $campaigns_url)); ?>">All</a>
            </nav>
        </div>
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Search</div>
            <input id="metis-newsletter-search" class="mw-input" type="text" placeholder="Name, subject, or list">
        </div>
        <?php if ($can_manage) : ?>
        <div class="mw-list-sidebar-actions">
            <button id="metis-newsletter-new-campaign" type="button" class="mw-btn mw-btn-xs">New Campaign</button>
            <button id="metis-newsletter-run-queue" type="button" class="mw-btn mw-btn-xs mw-btn-ghost">Run Send Queue</button>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content -->
    <div class="mw-list-content">
    <section class="mw-premium-table metis-newsletter-table" id="metis-newsletter-campaigns-panel">
        <div class="mw-premium-row mw-premium-header">
            <div class="mw-premium-cell">Campaign</div>
            <div class="mw-premium-cell">Subject</div>
            <div class="mw-premium-cell">Lists</div>
            <div class="mw-premium-cell">Status</div>
            <div class="mw-premium-cell">Recipients</div>
            <div class="mw-premium-cell">Updated</div>
            <?php if ($can_manage) : ?><div class="mw-premium-cell">Actions</div><?php endif; ?>
        </div>
        <div id="metis-newsletter-campaign-rows">
            <?php foreach ($campaigns as $campaign) :
                $campaign_id = (int) ($campaign['id'] ?? 0);
                $status = (string) ($campaign['status'] ?? 'draft');
                $is_sentish = in_array($status, ['sent', 'sending', 'archived'], true);
                $is_unsent = !$is_sentish;
                $row_lists = $campaign_lists_map[$campaign_id] ?? [];
                $search_blob = strtolower(trim(implode(' ', [
                    (string) ($campaign['name'] ?? ''),
                    (string) ($campaign['subject'] ?? ''),
                    implode(' ', array_map(static fn($x) => (string) ($x['name'] ?? ''), $row_lists)),
                ])));
            ?>
                <div class="mw-premium-row metis-newsletter-row <?php echo $is_unsent ? 'is-draft' : ''; ?>" data-search="<?php echo esc_attr($search_blob); ?>"
                     data-campaign-id="<?php echo esc_attr((string) $campaign_id); ?>"
                     data-campaign-status="<?php echo esc_attr($status); ?>"
                     data-open-details="1"
                     data-campaign-json="<?php echo esc_attr(metis_json_encode([
                         'id' => $campaign_id,
                         'name' => (string) ($campaign['name'] ?? ''),
                         'subject' => (string) ($campaign['subject'] ?? ''),
                         'template_id' => (int) ($campaign['template_id'] ?? 0),
                         'from_name' => (string) ($campaign['from_name'] ?? ''),
                         'from_email' => (string) ($campaign['from_email'] ?? ''),
                         'reply_to' => (string) ($campaign['reply_to'] ?? ''),
                         'preheader' => (string) ($campaign['preheader'] ?? ''),
                         'scheduled_at' => (string) ($campaign['scheduled_at'] ?? ''),
                         'status' => $status,
                         'doc_json' => (string) ($campaign['doc_json'] ?? ''),
                         'html_body' => (string) ($campaign['html_body'] ?? ''),
                         'audience_json' => (string) ($campaign['audience_json'] ?? ''),
                         'attachments_json' => (string) ($campaign['attachments_json'] ?? ''),
                         'list_ids' => array_values(array_map(static fn($x) => (int) ($x['id'] ?? 0), $row_lists)),
                     ])); ?>">
                    <div class="mw-premium-cell">
                        <div><strong><?php echo esc_html((string) ($campaign['name'] ?? '')); ?></strong></div>
                        <div class="mw-muted"><?php echo esc_html((string) ($campaign['campaign_code'] ?? '')); ?></div>
                    </div>
                    <div class="mw-premium-cell"><?php echo esc_html((string) ($campaign['subject'] ?? '')); ?></div>
                    <div class="mw-premium-cell">
                        <?php if (!empty($row_lists)) : ?>
                            <div class="metis-newsletter-chip-wrap">
                                <?php foreach ($row_lists as $list_row) : ?>
                                    <span class="mw-chip"><?php echo esc_html((string) ($list_row['name'] ?? '')); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?><span class="mw-muted">—</span><?php endif; ?>
                    </div>
                    <div class="mw-premium-cell"><span class="mw-chip"><?php echo esc_html($status); ?></span></div>
                    <div class="mw-premium-cell"><?php echo esc_html((string) ((int) ($campaign['total_recipients'] ?? 0))); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html(metis_newsletter_format_datetime((string) ($campaign['updated_at'] ?? ''))); ?></div>
                    <?php if ($can_manage) : ?>
                        <div class="mw-premium-cell metis-newsletter-actions-cell">
                            <?php if ($status !== 'archived') : ?>
                                <button class="mw-btn-xs metis-newsletter-test-campaign" type="button" data-campaign-id="<?php echo esc_attr((string) $campaign_id); ?>">Test</button>
                            <?php endif; ?>
                            <?php if (!$is_sentish && $status !== 'queued' && $status !== 'scheduled') : ?>
                                <button class="mw-btn-xs metis-newsletter-edit-campaign" type="button" data-campaign-id="<?php echo esc_attr((string) $campaign_id); ?>">Edit</button>
                            <?php endif; ?>
                            <?php if (!$is_sentish) : ?>
                                <button class="mw-btn-xs metis-newsletter-queue-campaign" type="button" data-campaign-id="<?php echo esc_attr((string) $campaign_id); ?>">Send</button>
                                <button class="mw-btn-xs mw-btn-danger metis-newsletter-delete-campaign" type="button" data-campaign-id="<?php echo esc_attr((string) $campaign_id); ?>">Delete</button>
                            <?php elseif ($status === 'sent') : ?>
                                <button class="mw-btn-xs mw-btn-danger metis-newsletter-archive-campaign" type="button" data-campaign-id="<?php echo esc_attr((string) $campaign_id); ?>">Archive</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (empty($campaigns)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No campaigns yet.</div></div><?php endif; ?>
        </div>
    </section>
    </div><!-- /mw-list-content -->
    </div><!-- /mw-list-layout -->
    <?php endif; ?>

    <?php if ($can_manage && $compose_mode) : ?>
        <?php /* MEBE block editor — editor.js/editor.css loaded globally via newsletter.json assets */ ?>
        <section class="mw-premium-wrap">
            <h3 id="metis-newsletter-campaign-title" class="metis-people-section-title" style="margin:0 0 12px;">Campaign Composer</h3>
            <p class="mw-muted" style="margin:0 0 14px;">Flow: choose a template, customize campaign content, select audience, then schedule and save.</p>
            <div id="metis-newsletter-step-tabs" class="metis-newsletter-step-tabs">
                <button type="button" class="mw-btn metis-newsletter-step-tab is-active" data-step-tab="1">Basics</button>
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-step-tab is-locked" data-step-tab="2" disabled>Content</button>
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-step-tab is-locked" data-step-tab="3" disabled>Audience</button>
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-step-tab is-locked" data-step-tab="4" disabled>Schedule</button>
            </div>
            <form id="metis-newsletter-campaign-form" class="metis-contact-form">
                <input type="hidden" id="metis-newsletter-campaign-id" value="0">
                <input type="hidden" id="metis-newsletter-campaign-attachments-json" value="[]">
                <div class="metis-newsletter-step metis-contact-field-full metis-newsletter-step-panel is-active" data-step="1">
                    <h4 class="metis-newsletter-step-title">Step 1. Campaign Basics</h4>
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-newsletter-campaign-name">Campaign Name</label><input id="metis-newsletter-campaign-name" class="mw-input" type="text" maxlength="255" required></div>
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-newsletter-campaign-template">Template</label><select id="metis-newsletter-campaign-template" class="mw-select"><option value="">None</option><?php foreach ($templates as $tpl) : ?><option value="<?php echo esc_attr((string) ($tpl['id'] ?? 0)); ?>"><?php echo esc_html((string) ($tpl['name'] ?? '')); ?></option><?php endforeach; ?></select></div>
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-newsletter-campaign-subject">Subject</label><input id="metis-newsletter-campaign-subject" class="mw-input" type="text" maxlength="255" required></div>
                    <div class="metis-contact-field metis-contact-field-half"><label for="metis-newsletter-campaign-preheader">Preheader</label><input id="metis-newsletter-campaign-preheader" class="mw-input" type="text" maxlength="255"></div>
                    <div class="metis-contact-field metis-contact-field-quarter"><label for="metis-newsletter-campaign-from-name">From Name</label><input id="metis-newsletter-campaign-from-name" class="mw-input" type="text" maxlength="191"></div>
                    <div class="metis-contact-field metis-contact-field-quarter"><label for="metis-newsletter-campaign-from-email">From Email</label><input id="metis-newsletter-campaign-from-email" class="mw-input" type="email" maxlength="191"></div>
                    <div class="metis-contact-field metis-contact-field-quarter"><label for="metis-newsletter-campaign-reply-to">Reply-To</label><input id="metis-newsletter-campaign-reply-to" class="mw-input" type="email" maxlength="191"></div>
                    <div class="metis-contact-field metis-contact-field-quarter"><label for="metis-newsletter-campaign-scheduled">Schedule</label><input id="metis-newsletter-campaign-scheduled" class="mw-input" type="datetime-local"></div>
                </div>

                <div class="metis-newsletter-step metis-contact-field-full metis-newsletter-step-panel" data-step="2">
                    <h4 class="metis-newsletter-step-title">Step 2. Campaign Content</h4>
                    <div class="metis-contact-field metis-contact-field-full">
                        <label>Campaign Content</label>
                        <p class="mw-muted metis-newsletter-builder-help">Use a template as a base then customize. Changes here apply to this campaign only.</p>
                        <div class="metis-nl-grapes-toolbar">
                            <button type="button" id="metis-newsletter-campaign-load-template" class="mw-btn-xs mw-btn-ghost">Load Template</button>
                            <select id="metis-nl-campaign-merge-select" class="mw-select">
                                <option value="">Insert merge tag…</option>
                                <option value="{{first_name}}">First Name</option>
                                <option value="{{last_name}}">Last Name</option>
                                <option value="{{full_name}}">Full Name</option>
                                <option value="{{email}}">Email</option>
                                <option value="{{campaign_name}}">Campaign Name</option>
                                <option value="{{contact_cid}}">Contact CID</option>
                                <option value="{{unsubscribe_url}}">Unsubscribe URL</option>
                                <option value="{{manage_subscription_url}}">Manage Subscription URL</option>
                            </select>
                        </div>
                        <div id="metis-nl-editor-campaign"></div>
                        <textarea id="metis-newsletter-campaign-html" class="metis-nl-hidden-store" aria-hidden="true"></textarea>
                        <textarea id="metis-newsletter-campaign-doc-json" class="metis-nl-hidden-store" aria-hidden="true"></textarea>
                    </div>
                </div>

                <div class="metis-newsletter-step metis-contact-field-full metis-newsletter-step-panel" data-step="3">
                    <h4 class="metis-newsletter-step-title">Step 3. Audience</h4>
                    <div class="metis-contact-field metis-contact-field-full">
                        <label>Audience Mode</label>
                        <div class="metis-newsletter-audience-mode">
                            <label><input type="radio" name="metis-newsletter-audience-mode" value="list" checked> Use Existing Lists</label>
                            <label><input type="radio" name="metis-newsletter-audience-mode" value="custom"> Custom Search Segment</label>
                        </div>
                    </div>
                    <div class="metis-contact-field metis-contact-field-full">
                        <label>Target Lists</label>
                        <div id="metis-newsletter-campaign-lists" class="metis-newsletter-check-grid metis-newsletter-audience-list-section">
                            <?php foreach ($lists as $list) : ?>
                                <label><input type="checkbox" value="<?php echo esc_attr((string) ($list['id'] ?? 0)); ?>"> <?php echo esc_html((string) ($list['name'] ?? '')); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="metis-newsletter-audience-custom metis-contact-field-full">
                        <div class="metis-contact-field metis-contact-field-full">
                            <label>Rule Builder</label>
                            <div id="metis-newsletter-segment-rules" class="metis-newsletter-rule-builder"></div>
                            <div class="metis-contact-actions" style="justify-content:flex-start;margin-top:6px;">
                                <button type="button" class="mw-btn mw-btn-ghost" id="metis-newsletter-add-rule">Add Rule</button>
                            </div>
                        </div>
                        <div class="metis-contact-field metis-contact-field-third"><label for="metis-newsletter-seg-opened-days">Opened within days</label><input id="metis-newsletter-seg-opened-days" class="mw-input" type="number" min="0" max="3650" value="0"></div>
                        <div class="metis-contact-field metis-contact-field-third"><label for="metis-newsletter-seg-clicked-days">Clicked within days</label><input id="metis-newsletter-seg-clicked-days" class="mw-input" type="number" min="0" max="3650" value="0"></div>
                        <div class="metis-contact-field metis-contact-field-third"><label for="metis-newsletter-seg-rule-match">Rule Match</label><select id="metis-newsletter-seg-rule-match" class="mw-select"><option value="all">All rules</option><option value="any">Any rule</option></select></div>
                    </div>
                </div>

                <div class="metis-newsletter-step metis-contact-field-full metis-newsletter-step-panel" data-step="4">
                    <h4 class="metis-newsletter-step-title">Step 4. Review + Send</h4>
                    <div class="metis-contact-field metis-contact-field-full">
                        <label>Attachments</label>
                        <div id="metis-newsletter-attachments-list" class="metis-newsletter-chip-wrap"></div>
                        <div class="metis-contact-actions" style="margin-top:8px;">
                            <button type="button" id="metis-newsletter-add-attachment" class="mw-btn mw-btn-ghost">Add Attachment</button>
                        </div>
                    </div>
                    <div class="metis-contact-actions">
                        <button id="metis-newsletter-campaign-test-send" type="button" class="mw-btn mw-btn-ghost">Test Send</button>
                        <button type="submit" class="mw-btn">Save Campaign</button>
                    </div>
                </div>
                <div class="metis-newsletter-step-pager metis-contact-field-full">
                    <button type="button" class="mw-btn mw-btn-ghost" id="metis-newsletter-step-prev" disabled>Previous</button>
                    <span class="mw-muted" id="metis-newsletter-step-indicator">Step 1 of 4</span>
                    <span class="mw-muted metis-newsletter-autosave" id="metis-newsletter-autosave-status"></span>
                    <button type="button" class="mw-btn" id="metis-newsletter-step-next">Next</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <div id="metis-newsletter-campaign-detail-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-newsletter-modal-inner">
            <h3 class="metis-contacts-modal-title" id="metis-newsletter-campaign-detail-title">Campaign Details</h3>
            <div class="metis-newsletter-progress-wrap">
                <div class="metis-newsletter-progress-head">
                    <div id="metis-newsletter-progress-summary" class="mw-muted">No campaign selected.</div>
                    <div id="metis-newsletter-progress-current" class="mw-muted"></div>
                </div>
                <div class="metis-newsletter-usage-bar"><div id="metis-newsletter-progress-bar" class="metis-newsletter-usage-bar-fill" style="width:0%;"></div></div>
            </div>
            <section class="mw-premium-table metis-newsletter-table" id="metis-newsletter-campaign-detail-panel">
                <div class="mw-premium-row mw-premium-header">
                    <div class="mw-premium-cell">Recipient</div>
                    <div class="mw-premium-cell">Email</div>
                    <div class="mw-premium-cell">CID</div>
                    <div class="mw-premium-cell">Status</div>
                    <div class="mw-premium-cell">Opened</div>
                    <div class="mw-premium-cell">Clicked</div>
                </div>
                <div id="metis-newsletter-campaign-detail-rows"></div>
            </section>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-cancel">Close</button>
            </div>
        </div>
    </div>

    <div id="metis-newsletter-test-send-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-newsletter-modal-inner">
            <h3 class="metis-contacts-modal-title">Send Test Campaign</h3>
            <div class="metis-contact-form">
                <input type="hidden" id="metis-newsletter-test-campaign-id" value="0">
                <div class="metis-contact-field metis-contact-field-full">
                    <label>Recipient</label>
                    <div class="metis-newsletter-audience-mode">
                        <label><input type="radio" name="metis-test-target-mode" value="contact" checked> Existing Contact</label>
                        <label><input type="radio" name="metis-test-target-mode" value="email"> Custom Email</label>
                    </div>
                </div>
                <div class="metis-contact-field metis-contact-field-half metis-test-target-panel" data-test-panel="contact">
                    <label for="metis-newsletter-test-contact-search">Existing Contact</label>
                    <input id="metis-newsletter-test-contact-search" class="mw-input" type="text" autocomplete="off" placeholder="Search name, email, or CID">
                    <div id="metis-newsletter-test-contact-results" class="metis-newsletter-search-results"></div>
                </div>
                <div class="metis-contact-field metis-contact-field-half metis-test-target-panel" data-test-panel="email" style="display:none;">
                    <label for="metis-newsletter-test-email">Custom Email</label>
                    <input id="metis-newsletter-test-email" class="mw-input" type="email" maxlength="191" placeholder="name@example.org">
                </div>
            </div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" id="metis-newsletter-send-test-confirm" class="mw-btn">Send Test</button>
            </div>
        </div>
    </div>

    <div id="metis-newsletter-html-editor-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-newsletter-modal-inner metis-newsletter-modal-compact">
            <h3 class="metis-contacts-modal-title">Edit HTML Block</h3>
            <div class="metis-contact-form">
                <div class="metis-contact-field metis-contact-field-full">
                    <label for="metis-newsletter-html-editor-input">HTML</label>
                    <textarea id="metis-newsletter-html-editor-input" class="mw-input" rows="10"></textarea>
                </div>
            </div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" id="metis-newsletter-html-editor-save" class="mw-btn">Apply HTML</button>
            </div>
        </div>
    </div>

    <div id="metis-newsletter-media-picker-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-newsletter-modal-inner metis-newsletter-modal-compact">
            <h3 class="metis-contacts-modal-title">Select Image</h3>
            <div class="metis-newsletter-step-tabs">
                <button type="button" class="mw-btn metis-newsletter-media-tab is-active" data-tab="media">Media</button>
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-media-tab" data-tab="upload">Upload</button>
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-media-tab" data-tab="klipy">Klipy</button>
            </div>
            <div class="metis-newsletter-media-panel is-active" data-panel="media">
                <div class="metis-contact-field"><label for="metis-newsletter-media-url">Image URL</label><input id="metis-newsletter-media-url" class="mw-input" type="url" placeholder="https://"></div>
                <div id="metis-newsletter-media-recent" class="metis-newsletter-media-recent"></div>
            </div>
            <div class="metis-newsletter-media-panel" data-panel="upload">
                <div class="metis-contact-field"><label for="metis-newsletter-media-upload-file">Upload image</label><input id="metis-newsletter-media-upload-file" class="mw-input" type="file" accept="image/*"></div>
            </div>
            <div class="metis-newsletter-media-panel" data-panel="klipy">
                <div class="metis-contact-field metis-contact-field-full"><label for="metis-newsletter-klipy-query">Search Klipy</label><input id="metis-newsletter-klipy-query" class="mw-input" type="text" placeholder="Search GIFs"></div>
                <div class="metis-contact-actions" style="justify-content:flex-start;margin-top:6px;"><button type="button" class="mw-btn mw-btn-ghost" id="metis-newsletter-klipy-search">Search</button></div>
                <div id="metis-newsletter-klipy-results" class="metis-newsletter-giphy-grid"></div>
                <div class="metis-contact-field"><label for="metis-newsletter-klipy-url">Selected Klipy URL</label><input id="metis-newsletter-klipy-url" class="mw-input" type="url" placeholder="https://..."></div>
            </div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" id="metis-newsletter-media-picker-save" class="mw-btn">Use Image</button>
            </div>
        </div>
    </div>

    <div id="metis-newsletter-video-modal" class="metis-contacts-modal" aria-hidden="true">
        <div class="metis-contacts-modal-inner metis-newsletter-modal-inner metis-newsletter-modal-compact">
            <h3 class="metis-contacts-modal-title">Video Block</h3>
            <div class="metis-contact-form">
                <div class="metis-contact-field metis-contact-field-full">
                    <label for="metis-newsletter-video-url">Video URL</label>
                    <input id="metis-newsletter-video-url" class="mw-input" type="url" placeholder="https://www.youtube.com/watch?v=">
                </div>
                <div class="metis-contact-field metis-contact-field-half">
                    <label for="metis-newsletter-video-label">Button Label</label>
                    <input id="metis-newsletter-video-label" class="mw-input" type="text" value="Watch video" maxlength="191">
                </div>
                <div class="metis-contact-field metis-contact-field-half">
                    <label for="metis-newsletter-video-thumb">Thumbnail URL (optional)</label>
                    <input id="metis-newsletter-video-thumb" class="mw-input" type="url" placeholder="https://...">
                </div>
            </div>
            <div class="metis-contact-actions">
                <button type="button" class="mw-btn mw-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" id="metis-newsletter-video-save" class="mw-btn">Save Video</button>
            </div>
        </div>
    </div>

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'lists' => $lists,
        'templates' => $templates,
        'ui' => [
            'compose_mode' => $compose_mode ? 1 : 0,
            'campaigns_url' => $campaigns_url,
            'compose_url' => add_query_arg(['compose' => '1'], $campaigns_url),
        ],
        'editing_campaign' => $selected_campaign ? [
            'id' => (int) ($selected_campaign['id'] ?? 0),
            'name' => (string) ($selected_campaign['name'] ?? ''),
            'subject' => (string) ($selected_campaign['subject'] ?? ''),
            'template_id' => (int) ($selected_campaign['template_id'] ?? 0),
            'from_name' => (string) ($selected_campaign['from_name'] ?? ''),
            'from_email' => (string) ($selected_campaign['from_email'] ?? ''),
            'reply_to' => (string) ($selected_campaign['reply_to'] ?? ''),
            'preheader' => (string) ($selected_campaign['preheader'] ?? ''),
            'scheduled_at' => (string) ($selected_campaign['scheduled_at'] ?? ''),
            'status' => (string) ($selected_campaign['status'] ?? 'draft'),
            'doc_json' => (string) ($selected_campaign['doc_json'] ?? ''),
            'html_body' => (string) ($selected_campaign['html_body'] ?? ''),
            'audience_json' => (string) ($selected_campaign['audience_json'] ?? ''),
            'attachments_json' => (string) ($selected_campaign['attachments_json'] ?? ''),
            'list_ids' => array_values(array_map(static fn($x) => (int) ($x['id'] ?? 0), $campaign_lists_map[(int) ($selected_campaign['id'] ?? 0)] ?? [])),
        ] : null,
        'defaults' => [
            'from_name' => $default_from_name,
            'from_email' => $default_from_email,
            'reply_to' => $default_reply_to,
        ],
        'templates_by_id' => array_values(array_map(static fn($t) => [
            'id' => (int) ($t['id'] ?? 0),
            'subject' => (string) ($t['subject'] ?? ''),
            'from_name' => (string) ($t['from_name'] ?? ''),
            'from_email' => (string) ($t['from_email'] ?? ''),
            'reply_to' => (string) ($t['reply_to'] ?? ''),
            'doc_json' => (string) ($t['doc_json'] ?? ''),
            'html_body' => (string) ($t['html_body'] ?? ''),
        ], $templates)),
    ]); ?></script>
</div>
