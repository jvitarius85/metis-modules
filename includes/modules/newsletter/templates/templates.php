<?php
if (!defined('ABSPATH')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view newsletter.</div>';
    return;
}

metis_newsletter_ensure_schema();

global $wpdb;

$can_manage = metis_newsletter_can_manage();
$templates_table = Metis_Tables::get('newsletter_templates');
$dashboard_url = metis_portal_url('newsletter', 'dashboard');
$campaigns_url = metis_portal_url('newsletter', 'campaigns');
$templates_url = metis_portal_url('newsletter', 'templates');
$lists_url = metis_portal_url('newsletter', 'lists');
$subscribers_url = metis_portal_url('newsletter', 'subscribers');
$edit_template_id = isset($_GET['template_id']) ? (int) $_GET['template_id'] : 0;
$editor_mode = (isset($_GET['editor']) && (string) $_GET['editor'] === '1') || $edit_template_id > 0;

$default_from_name = (string) Core_Settings_Service::get('newsletter_default_from_name', '');
$default_from_email = (string) Core_Settings_Service::get('newsletter_default_from_email', '');
$default_reply_to = (string) Core_Settings_Service::get('newsletter_default_reply_to', '');

$templates = $wpdb->get_results(
    "SELECT id, template_code, name, subject, from_name, from_email, reply_to, doc_json, html_body, is_active, updated_at
     FROM {$templates_table}
     ORDER BY updated_at DESC, id DESC
     LIMIT 200",
    ARRAY_A
) ?: [];

$selected_template = null;
if ($editor_mode && $edit_template_id > 0) {
    foreach ($templates as $tpl) {
        if ((int) ($tpl['id'] ?? 0) === $edit_template_id) {
            $selected_template = $tpl;
            break;
        }
    }
}
?>

<div class="metis-newsletter" data-can-manage="<?php echo esc_attr($can_manage ? '1' : '0'); ?>">
    <h1 class="mw-page-title">Newsletter Templates</h1>
    <p class="mw-subtitle">Build reusable newsletter layouts. Default sender settings are managed in Settings.</p>

    <div id="metis-newsletter-alert" class="mw-alert" style="display:none;"></div>

    <?php if (!$editor_mode) : ?>
    <div class="mw-list-layout">

    <!-- Sidebar -->
    <aside class="mw-list-sidebar">
        <div class="mw-list-sidebar-section">
            <div class="mw-list-sidebar-label">Newsletter</div>
            <nav class="mw-list-sidebar-nav">
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($dashboard_url); ?>">Dashboard</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($campaigns_url); ?>">Campaigns</a>
                <a class="mw-list-sidebar-nav-item is-active" href="<?php echo esc_url($templates_url); ?>">Templates</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($lists_url); ?>">Lists</a>
                <a class="mw-list-sidebar-nav-item" href="<?php echo esc_url($subscribers_url); ?>">Subscribers</a>
            </nav>
        </div>
        <?php if ($can_manage) : ?>
        <div class="mw-list-sidebar-actions">
            <button id="metis-newsletter-new-template" type="button" class="mw-btn mw-btn-xs">New Template</button>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content -->
    <div class="mw-list-content">
    <section class="mw-premium-table metis-newsletter-table" id="metis-newsletter-templates-panel">
        <div class="mw-premium-row mw-premium-header">
            <div class="mw-premium-cell">Template</div>
            <div class="mw-premium-cell">Subject</div>
            <div class="mw-premium-cell">From</div>
            <div class="mw-premium-cell">Updated</div>
            <?php if ($can_manage) : ?><div class="mw-premium-cell">Actions</div><?php endif; ?>
        </div>
        <div id="metis-newsletter-template-rows">
            <?php foreach ($templates as $template) :
                $search_blob = strtolower(trim(implode(' ', [
                    (string) ($template['name'] ?? ''),
                    (string) ($template['subject'] ?? ''),
                    (string) ($template['from_email'] ?? ''),
                ])));
            ?>
                <div class="mw-premium-row metis-newsletter-row" data-search="<?php echo esc_attr($search_blob); ?>"
                     data-template-json="<?php echo esc_attr(metis_json_encode($template)); ?>">
                    <div class="mw-premium-cell"><strong><?php echo esc_html((string) ($template['name'] ?? '')); ?></strong></div>
                    <div class="mw-premium-cell"><?php echo esc_html((string) ($template['subject'] ?? '')); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html(trim((string) ($template['from_name'] ?? '') . ' <' . (string) ($template['from_email'] ?? '') . '>')); ?></div>
                    <div class="mw-premium-cell"><?php echo esc_html(metis_newsletter_format_datetime((string) ($template['updated_at'] ?? ''))); ?></div>
                    <?php if ($can_manage) : ?><div class="mw-premium-cell"><button class="mw-btn-xs metis-newsletter-edit-template" type="button" data-template-id="<?php echo esc_attr((string) ((int) ($template['id'] ?? 0))); ?>">Edit</button></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (empty($templates)) : ?><div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No templates yet.</div></div><?php endif; ?>
        </div>
    </section>
    </div><!-- /mw-list-content -->
    </div><!-- /mw-list-layout -->
    <?php endif; ?>

    <?php if ($can_manage && $editor_mode) : ?>
        <?php /* MEBE block editor — editor.js/editor.css loaded globally via newsletter.json assets */ ?>

        <section class="mw-premium-wrap metis-nl-editor-wrap">
            <h3 id="metis-newsletter-template-title" class="metis-people-section-title" style="margin:0 0 12px;">Template Editor</h3>
            <form id="metis-newsletter-template-form" class="metis-contact-form">
                <input type="hidden" id="metis-newsletter-template-id" value="0">
                <div class="metis-contact-field metis-contact-field-half"><label for="metis-newsletter-template-name">Name</label><input id="metis-newsletter-template-name" class="mw-input" type="text" maxlength="255" required></div>
                <div class="metis-contact-field metis-contact-field-half"><label for="metis-newsletter-template-subject">Default Subject</label><input id="metis-newsletter-template-subject" class="mw-input" type="text" maxlength="255" required></div>
                <div class="metis-contact-field metis-contact-field-third"><label for="metis-newsletter-template-from-name">From Name</label><input id="metis-newsletter-template-from-name" class="mw-input" type="text" maxlength="191"></div>
                <div class="metis-contact-field metis-contact-field-third"><label for="metis-newsletter-template-from-email">From Email</label><input id="metis-newsletter-template-from-email" class="mw-input" type="email" maxlength="191"></div>
                <div class="metis-contact-field metis-contact-field-third"><label for="metis-newsletter-template-reply-to">Reply-To</label><input id="metis-newsletter-template-reply-to" class="mw-input" type="email" maxlength="191"></div>

                <div class="metis-contact-field metis-contact-field-full">
                    <label>Template Content</label>
                    <!-- Merge tags are now in the MEBE toolbar dropdown -->
                    <div id="metis-nl-editor-template"></div>
                    <textarea id="metis-newsletter-template-html" class="metis-nl-hidden-store" aria-hidden="true"></textarea>
                    <textarea id="metis-newsletter-template-doc-json" class="metis-nl-hidden-store" aria-hidden="true"></textarea>
                </div>

                <div class="metis-contact-actions">
                    <button id="metis-newsletter-new-template" type="button" class="mw-btn mw-btn-ghost">New Template</button>
                    <button type="submit" class="mw-btn mebe-save-trigger">Save Template</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'templates' => $templates,
        'ui' => [
            'template_editor_mode' => $editor_mode ? 1 : 0,
            'templates_url' => $templates_url,
            'template_editor_url' => add_query_arg(['editor' => '1'], $templates_url),
        ],
        'editing_template' => $selected_template ? [
            'id' => (int) ($selected_template['id'] ?? 0),
            'name' => (string) ($selected_template['name'] ?? ''),
            'subject' => (string) ($selected_template['subject'] ?? ''),
            'from_name' => (string) ($selected_template['from_name'] ?? ''),
            'from_email' => (string) ($selected_template['from_email'] ?? ''),
            'reply_to' => (string) ($selected_template['reply_to'] ?? ''),
            'doc_json' => (string) ($selected_template['doc_json'] ?? ''),
            'html_body' => (string) ($selected_template['html_body'] ?? ''),
        ] : null,
        'defaults' => [
            'from_name' => $default_from_name,
            'from_email' => $default_from_email,
            'reply_to' => $default_reply_to,
        ],
    ]); ?></script>
</div>
