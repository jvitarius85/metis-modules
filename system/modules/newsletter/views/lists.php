<?php
if (!defined('METIS_ROOT')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view newsletter lists.</div>';
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
$contact_url_base = metis_portal_url('contacts', 'contact');

$selected_list_id = isset(metis_request_get()['list_id']) ? max(0, (int) metis_request_get()['list_id']) : 0;

$snapshot = \Metis\Modules\Newsletter\ReadService::listsSnapshot( $selected_list_id );
$lists = is_array($snapshot['lists'] ?? null) ? $snapshot['lists'] : [];
?>

<div class="metis-newsletter" data-can-manage="<?php echo metis_escape_attr($can_manage ? '1' : '0'); ?>">
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Newsletter Lists' ) ); ?></h1>
    <p class="metis-subtitle">Manage list definitions and list membership from contacts.</p>

    <div id="metis-newsletter-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-list-layout">
        <aside class="metis-list-sidebar">
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Newsletter</div>
                <nav class="metis-list-sidebar-nav">
                    <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($dashboard_url); ?>">Dashboard</a>
                    <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($announcements_url); ?>">Announcements</a>
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
                    ?>
                        <tr class="metis-premium-row metis-newsletter-row"
                            data-list-id="<?php echo metis_escape_attr((string) $list_id); ?>"
                            data-search="<?php echo metis_escape_attr($search_blob); ?>">
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

            <section class="metis-premium-wrap">
                <div class="metis-muted">Click a list row to edit it and manage subscribers in a modal.</div>
            </section>
        </div>
    </div>

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'ui' => [
            'view' => 'lists',
            'lists_url' => $lists_url,
            'selected_list_id' => $selected_list_id,
            'contact_url_base' => $contact_url_base,
        ],
    ]); ?></script>

    <?php if ($can_manage) : ?>
    <div id="metis-newsletter-list-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal metis-newsletter-modal-inner">
            <h3 class="metis-modal-title" id="metis-newsletter-selected-list-title">Newsletter List</h3>
            <div class="metis-form-grid">
                <div class="metis-field metis-field-third">
                    <label>List Name</label>
                    <input id="metis-newsletter-list-name" class="metis-input" type="text" maxlength="191" value="">
                </div>
                <div class="metis-field metis-field-third">
                    <label>Description</label>
                    <input id="metis-newsletter-list-description" class="metis-input" type="text" maxlength="255" value="">
                </div>
                <div class="metis-field metis-field-third">
                    <label>Status</label>
                    <select id="metis-newsletter-list-active" class="metis-select">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="metis-newsletter-bulk-head" style="margin-top:12px;">
                <div class="metis-muted" id="metis-newsletter-list-stats">Loading…</div>
                <div class="metis-newsletter-bulk-actions">
                    <button type="button" class="metis-btn metis-btn-xs" id="metis-newsletter-open-bulk-modal">Add Contacts</button>
                </div>
            </div>

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
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell metis-muted" colspan="4">Select a list to load subscribers.</td>
                    </tr>
                </tbody>
            </table>

            <div class="metis-form-actions">
                <button type="button" class="metis-btn metis-btn-ghost metis-newsletter-cancel">Close</button>
                <button type="button" class="metis-btn metis-btn-danger" id="metis-newsletter-delete-selected-list" data-list-id="0">Delete List</button>
                <button type="button" class="metis-btn" id="metis-newsletter-save-selected-list" data-list-id="0">Save List</button>
            </div>
        </div>
    </div>

    <div id="metis-newsletter-bulk-add-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal metis-newsletter-modal-inner">
            <h3 class="metis-modal-title">Add Contacts To List</h3>
            <p class="metis-muted" style="margin:0 0 12px;">Browse contacts with email addresses that are not already subscribed to this list.</p>

            <div class="metis-newsletter-bulk-toolbar">
                <div class="metis-field" style="margin:0;">
                    <label for="metis-newsletter-bulk-contact-search">Search Contacts</label>
                    <input id="metis-newsletter-bulk-contact-search" class="metis-input" type="text" placeholder="Name or email">
                </div>
                <div class="metis-newsletter-bulk-summary" id="metis-newsletter-bulk-summary">Loading contacts…</div>
            </div>

            <table class="metis-premium-table metis-newsletter-table" id="metis-newsletter-bulk-contacts-panel">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">
                            <label class="metis-newsletter-checkbox">
                                <input type="checkbox" id="metis-newsletter-bulk-select-page">
                            </label>
                        </th>
                        <th class="metis-premium-cell" scope="col">Contact</th>
                        <th class="metis-premium-cell" scope="col">Email</th>
                    </tr>
                </thead>
                <tbody id="metis-newsletter-bulk-contact-rows">
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell metis-muted" colspan="3">Select a list to load contacts.</td>
                    </tr>
                </tbody>
            </table>

            <div class="metis-newsletter-bulk-pagination">
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-newsletter-bulk-prev">Prev</button>
                <span class="metis-muted" id="metis-newsletter-bulk-page-label">Page 1 of 1</span>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-newsletter-bulk-next">Next</button>
            </div>

            <div class="metis-form-actions">
                <button type="button" class="metis-btn metis-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" class="metis-btn metis-btn-ghost" id="metis-newsletter-bulk-clear-selection">Clear Selection</button>
                <button type="button" class="metis-btn" id="metis-newsletter-bulk-add-selected" data-list-id="0">Add Selected Contacts</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
