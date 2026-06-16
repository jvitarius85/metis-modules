<?php
if (!defined('METIS_ROOT')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view newsletter.</div>';
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
$campaigns_root_url = rtrim($campaigns_url, '/') . '/';
$campaign_compose_url = $campaigns_root_url . 'new/';
$campaign_edit_base_url = $campaigns_root_url;
$campaign_view_urls = [
    'active' => $campaigns_url,
    'archived' => $campaigns_root_url . 'archived/',
    'all' => $campaigns_root_url . 'all/',
];
\Metis\Core\Editor\BlockRegistry::boot();
$newsletter_allowed_defs = \Metis\Core\Editor\EditorContextPolicy::filterRegistry(
    \Metis\Core\Editor\BlockRegistry::all(),
    'newsletter',
    'email_safe'
);
$newsletter_allowed_blocks = array_values( array_keys( $newsletter_allowed_defs ) );
$newsletter_allowed_blocks = array_values(array_intersect($newsletter_allowed_blocks, ['text', 'feature_grid', 'cta']));
$current_view = metis_key_clean((string) metis_get_query_var('metis_view'));
$campaign_view = 'active';
$edit_campaign_id = 0;
$edit_campaign_code = '';
$compose_mode = false;
$compose_campaign_type = \Metis\Modules\Newsletter\CampaignService::TYPE_CAMPAIGN;
$compose_preset = metis_key_clean((string) (metis_request_get()['preset'] ?? ''));
$requested_campaign_type = \Metis\Modules\Newsletter\CampaignService::normalizeType((string) (metis_request_get()['campaign_type'] ?? 'campaign'));

$legacy_campaign_id = isset(metis_request_get()['campaign_id']) ? (int) metis_request_get()['campaign_id'] : 0;
$legacy_compose = isset(metis_request_get()['compose']) && (string) metis_request_get()['compose'] === '1';
$legacy_view = metis_key_clean((string) (metis_request_get()['view'] ?? ''));

if ($legacy_compose || $legacy_campaign_id > 0) {
    $legacy_ref = $legacy_campaign_id > 0
        ? \Metis\Modules\Newsletter\ReadService::legacyCampaignRef($legacy_campaign_id)
        : '';
    $legacy_target = $legacy_campaign_id > 0
        ? ($campaign_edit_base_url . $legacy_ref . '/edit/')
        : $campaign_compose_url;
    metis_runtime_redirect($legacy_target);
    return;
}

if (in_array($legacy_view, ['active', 'archived', 'all'], true)) {
    metis_runtime_redirect((string) $campaign_view_urls[$legacy_view]);
    return;
}

$request_path = function_exists('metis_request_path_relative_to_site')
    ? (string) metis_request_path_relative_to_site()
    : (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$path_parts = array_values(array_filter(explode('/', trim($request_path, '/')), static fn($part) => $part !== ''));
$portal_slug = function_exists('metis_portal_slug') ? metis_key_clean((string) metis_portal_slug()) : 'admin';
if (
    count($path_parts) >= 3
    && metis_key_clean((string) ($path_parts[0] ?? '')) === $portal_slug
    && metis_key_clean((string) ($path_parts[1] ?? '')) === 'newsletter'
    && metis_key_clean((string) ($path_parts[2] ?? '')) === 'campaigns'
) {
    $path_token = metis_key_clean((string) ($path_parts[3] ?? ''));
    if (in_array($path_token, ['active', 'archived', 'all'], true)) {
        $campaign_view = $path_token;
    } elseif ($path_token === 'new') {
        $compose_mode = true;
        $edit_campaign_id = 0;
        $compose_campaign_type = $requested_campaign_type;
    } elseif (
        preg_match('/^[A-Za-z0-9_-]+$/', (string) ($path_parts[3] ?? ''))
        && metis_key_clean((string) ($path_parts[4] ?? '')) === 'edit'
    ) {
        $compose_mode = true;
        $edit_ref = (string) $path_parts[3];
        if (ctype_digit($edit_ref)) {
            $edit_campaign_id = (int) $edit_ref;
        } else {
            $edit_campaign_code = $edit_ref;
        }
    }
}

$default_from_name = (string) Core_Settings_Service::get('newsletter_default_from_name', '');
$default_from_email = (string) Core_Settings_Service::get('newsletter_default_from_email', '');
$default_reply_to = (string) Core_Settings_Service::get('newsletter_default_reply_to', '');
$active_theme = \Metis\Modules\Website\Services\ThemeService::getActiveNormalized();
$active_theme_colors = is_array($active_theme['colors'] ?? null) ? $active_theme['colors'] : [];
$newsletter_color_map = ['transparent' => 'transparent'];
foreach ($active_theme_colors as $color_key => $color_value) {
    $newsletter_color_map[(string) $color_key] = (string) $color_value;
}
$newsletter_color_map['border'] = '#dfe6f3';
$newsletter_theme_defaults = [
    'header_html' => metis_newsletter_clean_html((string) Core_Settings_Service::get('newsletter_theme_header_html', '')),
    'personalized_html' => metis_newsletter_clean_html((string) Core_Settings_Service::get('newsletter_theme_personalized_html', '')),
    'closing_html' => metis_newsletter_clean_html((string) Core_Settings_Service::get('newsletter_theme_closing_html', '')),
    'footer_html' => metis_newsletter_clean_html((string) Core_Settings_Service::get('newsletter_theme_footer_html', '')),
    'canvas_bg' => (string) Core_Settings_Service::get('newsletter_theme_canvas_bg', 'transparent'),
    'text_color' => (string) Core_Settings_Service::get('newsletter_theme_text_color', 'text'),
    'font_size' => (int) Core_Settings_Service::get('newsletter_theme_font_size', 16),
    'content_width_mode' => (string) Core_Settings_Service::get('newsletter_theme_content_width_mode', 'normal'),
    'content_width' => max(520, min(820, (int) Core_Settings_Service::get('newsletter_theme_content_width', 680))),
    'divider_color' => (string) Core_Settings_Service::get('newsletter_theme_divider_color', 'border'),
    'divider_style' => (string) Core_Settings_Service::get('newsletter_theme_divider_style', 'solid'),
    'divider_weight' => max(1, min(6, (int) Core_Settings_Service::get('newsletter_theme_divider_weight', 1))),
    'header_bg' => (string) Core_Settings_Service::get('newsletter_theme_header_bg', 'transparent'),
    'header_text_color' => (string) Core_Settings_Service::get('newsletter_theme_header_text_color', 'text'),
    'header_padding' => (string) Core_Settings_Service::get('newsletter_theme_header_padding', '24px 28px 12px 28px'),
    'personalized_bg' => (string) Core_Settings_Service::get('newsletter_theme_personalized_bg', 'transparent'),
    'personalized_text_color' => (string) Core_Settings_Service::get('newsletter_theme_personalized_text_color', 'text'),
    'personalized_padding' => (string) Core_Settings_Service::get('newsletter_theme_personalized_padding', '0 28px 8px 28px'),
    'closing_bg' => (string) Core_Settings_Service::get('newsletter_theme_closing_bg', 'transparent'),
    'closing_text_color' => (string) Core_Settings_Service::get('newsletter_theme_closing_text_color', 'text'),
    'closing_padding' => (string) Core_Settings_Service::get('newsletter_theme_closing_padding', '12px 28px 8px 28px'),
    'footer_bg' => (string) Core_Settings_Service::get('newsletter_theme_footer_bg', 'transparent'),
    'footer_text_color' => (string) Core_Settings_Service::get('newsletter_theme_footer_text_color', 'muted'),
    'footer_padding' => (string) Core_Settings_Service::get('newsletter_theme_footer_padding', '16px 28px 28px 28px'),
];

$campaign_snapshot = \Metis\Modules\Newsletter\ReadService::campaignsSnapshot(
    $campaign_view,
    $compose_mode,
    $edit_campaign_id,
    $edit_campaign_code
);
$lists = $campaign_snapshot['lists'] ?? [];
$templates = $campaign_snapshot['templates'] ?? [];
$campaigns = $campaign_snapshot['campaigns'] ?? [];
$campaign_lists_map = $campaign_snapshot['campaign_lists_map'] ?? [];
$selected_campaign = $campaign_snapshot['selected_campaign'] ?? null;
if ($compose_mode && ($edit_campaign_code !== '' || $edit_campaign_id > 0) && !$selected_campaign) {
    $edit_campaign_id = 0;
    $edit_campaign_code = '';
}
if (is_array($selected_campaign) && $selected_campaign !== []) {
    $compose_campaign_type = \Metis\Modules\Newsletter\CampaignService::normalizeType((string) ($selected_campaign['campaign_type'] ?? 'campaign'));
}
$is_announcement_blast = $compose_campaign_type === \Metis\Modules\Newsletter\CampaignService::TYPE_ANNOUNCEMENT_BLAST;
$announcement_blast_url = rtrim($announcements_url, '/') . '/?compose=1';
$blast_date_label = (new DateTimeImmutable('now', metis_newsletter_resolved_timezone()))->format('m/d/Y');
$default_blast_name = 'Announcement Blast ' . $blast_date_label;
$default_list_ids = $is_announcement_blast ? array_values(array_map(static fn($list) => (int) ($list['id'] ?? 0), $lists)) : [];

$editor_nonce = function_exists( 'metis_runtime_create_nonce' ) ? (string) metis_runtime_create_nonce( 'metis_newsletter' ) : '';
$simple_editor_css = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/assets/js/editor/simple-editor.css' ) : '/assets/js/editor/simple-editor.css';
$simple_editor_js  = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/assets/js/editor/simple-editor.js' ) : '/assets/js/editor/simple-editor.js';
$newsletter_media_options = function_exists( 'metis_website_ajax_media_options' ) ? metis_website_ajax_media_options() : [];
$newsletter_theme_preview_contact = function_exists('metis_newsletter_preview_contact_payload')
    ? metis_newsletter_preview_contact_payload()
    : [];
$newsletter_test_email_default = (string) ($newsletter_theme_preview_contact['email'] ?? $default_reply_to ?: $default_from_email);
$simple_editor_css_version = (string) @filemtime( METIS_ASSETS_PATH . 'js/editor/simple-editor.css' );
$simple_editor_js_version = (string) @filemtime( METIS_ASSETS_PATH . 'js/editor/simple-editor.js' );
if ( $simple_editor_css_version !== '' ) {
    $simple_editor_css .= ( strpos( $simple_editor_css, '?' ) === false ? '?' : '&' ) . 'v=' . rawurlencode( $simple_editor_css_version );
}
if ( $simple_editor_js_version !== '' ) {
    $simple_editor_js .= ( strpos( $simple_editor_js, '?' ) === false ? '?' : '&' ) . 'v=' . rawurlencode( $simple_editor_js_version );
}
?>

<div class="metis-newsletter" data-can-manage="<?php echo metis_escape_attr($can_manage ? '1' : '0'); ?>">
    <?php if (!$compose_mode) : ?>
    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Newsletter Campaigns' ) ); ?></h1>
    <p class="metis-subtitle">Create and queue campaigns with a dedicated full-length composer.</p>
    <?php endif; ?>

    <div id="metis-newsletter-alert" class="metis-alert" style="display:none;"></div>

    <?php if ($compose_mode && $can_manage) : ?>
    <link rel="stylesheet" href="<?php echo metis_escape_attr( $simple_editor_css ); ?>">
    <div id="metis-editor-inline-root"></div>
    <div
        id="metis-editor-bootstrap"
        data-editor-key="<?php echo metis_escape_attr( $edit_campaign_code ); ?>"
        data-editor-new="<?php echo metis_escape_attr( $edit_campaign_code === '' ? 'newsletter_campaign' : '' ); ?>"
        data-editor-id="<?php echo metis_escape_attr( (string) ((int) ($selected_campaign['id'] ?? 0)) ); ?>"
        data-editor-nonce="<?php echo metis_escape_attr( $editor_nonce ); ?>"
        data-editor-context="newsletter"
        data-editor-kind="campaign"
        data-editor-campaign-type="<?php echo metis_escape_attr($compose_campaign_type); ?>"
        data-editor-initial-options="<?php echo metis_escape_attr( metis_json_encode([
            'defaults' => [
                'name' => $is_announcement_blast ? $default_blast_name : '',
                'from_name' => $default_from_name,
                'from_email' => $default_from_email,
                'reply_to' => $default_reply_to,
                'campaign_type' => $compose_campaign_type,
                'list_ids' => $default_list_ids,
            ],
            'media' => $newsletter_media_options,
            'theme_defaults' => $newsletter_theme_defaults,
            'theme_preview_contact' => $newsletter_theme_preview_contact,
            'theme_color_map' => $newsletter_color_map,
            'test_email_default' => $newsletter_test_email_default,
            'compose' => [
                'campaign_type' => $compose_campaign_type,
                'preset' => $compose_preset,
                'default_list_ids' => $default_list_ids,
            ],
        ]) ); ?>"
    ></div>
    <div id="metis-editor-boot-status" class="metis-editor-boot-status">
        <div class="metis-editor-boot-card">
            <div class="metis-editor-boot-title">Loading Editor</div>
            <div class="metis-editor-boot-copy">Preparing newsletter editor...</div>
        </div>
    </div>
    <script src="<?php echo metis_escape_attr( $simple_editor_js ); ?>"></script>
    <?php else : ?>
    <div class="metis-list-layout">

    <!-- Sidebar -->
    <aside class="metis-list-sidebar">
        <div class="metis-list-sidebar-section">
            <div class="metis-list-sidebar-label">Newsletter</div>
            <nav class="metis-list-sidebar-nav">
                <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($dashboard_url); ?>">Dashboard</a>
                <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($announcements_url); ?>">Announcements</a>
                <a class="metis-list-sidebar-nav-item is-active" href="<?php echo metis_escape_url($campaigns_url); ?>">Campaigns</a>
                <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($templates_url); ?>">Theme</a>
                <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($lists_url); ?>">Lists</a>
                <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url($subscribers_url); ?>">Subscribers</a>
            </nav>
        </div>
        <?php if (!$compose_mode) : ?>
        <div class="metis-list-sidebar-section">
            <div class="metis-list-sidebar-label">Filter</div>
            <nav class="metis-list-sidebar-nav">
                <a class="metis-list-sidebar-nav-item <?php echo $campaign_view === 'active' ? 'is-active' : ''; ?>" href="<?php echo metis_escape_url((string) $campaign_view_urls['active']); ?>">Active</a>
                <a class="metis-list-sidebar-nav-item <?php echo $campaign_view === 'archived' ? 'is-active' : ''; ?>" href="<?php echo metis_escape_url((string) $campaign_view_urls['archived']); ?>">Archived</a>
                <a class="metis-list-sidebar-nav-item <?php echo $campaign_view === 'all' ? 'is-active' : ''; ?>" href="<?php echo metis_escape_url((string) $campaign_view_urls['all']); ?>">All</a>
            </nav>
        </div>
        <div class="metis-list-sidebar-section">
            <div class="metis-list-sidebar-label">Search</div>
            <input id="metis-newsletter-search" class="metis-input" type="text" placeholder="Name, subject, or list">
        </div>
        <?php endif; ?>
        <?php if ($can_manage) : ?>
        <div class="metis-list-sidebar-actions">
            <?php if ($compose_mode) : ?>
                <a href="<?php echo metis_escape_url($campaigns_url); ?>" class="metis-btn metis-btn-xs metis-btn-ghost">&larr; Back to Campaigns</a>
            <?php else : ?>
                <button id="metis-newsletter-new-campaign" type="button" class="metis-btn metis-btn-xs">New Campaign</button>
                <a href="<?php echo metis_escape_url($announcement_blast_url); ?>" class="metis-btn metis-btn-xs metis-btn-ghost">Announcement Blast</a>
                <button id="metis-newsletter-run-queue" type="button" class="metis-btn metis-btn-xs metis-btn-ghost">Run Send Queue</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main content -->
    <div class="metis-list-content">
    <table class="metis-premium-table metis-newsletter-table<?php echo $can_manage ? '' : ' metis-newsletter-table--readonly'; ?>" id="metis-newsletter-campaigns-panel">
        <thead>
            <tr class="metis-premium-row metis-premium-header">
                <th class="metis-premium-cell" scope="col">Campaign</th>
                <th class="metis-premium-cell" scope="col">Subject</th>
                <th class="metis-premium-cell" scope="col">Lists</th>
                <th class="metis-premium-cell" scope="col">Status</th>
                <th class="metis-premium-cell" scope="col">Recipients</th>
                <th class="metis-premium-cell" scope="col">Updated</th>
                <?php if ($can_manage) : ?><th class="metis-premium-cell" scope="col">Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody id="metis-newsletter-campaign-rows">
            <?php foreach ($campaigns as $campaign) :
                $campaign_id = (int) ($campaign['id'] ?? 0);
                $status = (string) ($campaign['status'] ?? 'draft');
                $is_sentish = in_array($status, ['sent', 'sending', 'archived'], true);
                $is_unsent = !$is_sentish;
                $is_imported_archive = \Metis\Modules\Newsletter\CampaignService::isWordPressArchiveImport(is_array($campaign) ? $campaign : []);
                $row_lists = $campaign_lists_map[$campaign_id] ?? [];
                $search_blob = strtolower(trim(implode(' ', [
                    (string) ($campaign['name'] ?? ''),
                    (string) ($campaign['subject'] ?? ''),
                    implode(' ', array_map(static fn($x) => (string) ($x['name'] ?? ''), $row_lists)),
                ])));
            ?>
                <tr class="metis-premium-row metis-newsletter-row <?php echo $is_unsent ? 'is-draft' : ''; ?>" data-search="<?php echo metis_escape_attr($search_blob); ?>"
                     data-campaign-id="<?php echo metis_escape_attr((string) $campaign_id); ?>"
                     data-campaign-code="<?php echo metis_escape_attr((string) ($campaign['campaign_code'] ?? '')); ?>"
                     data-campaign-status="<?php echo metis_escape_attr($status); ?>"
                     data-imported-archive="<?php echo $is_imported_archive ? '1' : '0'; ?>"
                     data-open-details="1"
                     data-campaign-json="<?php echo metis_escape_attr(metis_json_encode([
                         'id' => $campaign_id,
                         'campaign_code' => (string) ($campaign['campaign_code'] ?? ''),
                         'campaign_type' => \Metis\Modules\Newsletter\CampaignService::normalizeType((string) ($campaign['campaign_type'] ?? 'campaign')),
                         'name' => (string) ($campaign['name'] ?? ''),
                         'subject' => (string) ($campaign['subject'] ?? ''),
                         'template_id' => (int) ($campaign['template_id'] ?? 0),
                         'from_name' => (string) ($campaign['from_name'] ?? ''),
                         'from_email' => (string) ($campaign['from_email'] ?? ''),
                         'reply_to' => (string) ($campaign['reply_to'] ?? ''),
                         'preheader' => (string) ($campaign['preheader'] ?? ''),
                         'scheduled_at' => (string) ($campaign['scheduled_at'] ?? ''),
                         'status' => $status,
                         'is_imported_archive' => $is_imported_archive ? 1 : 0,
                         'doc_json' => (string) ($campaign['doc_json'] ?? ''),
                         'html_body' => (string) ($campaign['html_body'] ?? ''),
                         'audience_json' => (string) ($campaign['audience_json'] ?? ''),
                         'attachments_json' => (string) ($campaign['attachments_json'] ?? ''),
                         'list_ids' => array_values(array_map(static fn($x) => (int) ($x['id'] ?? 0), $row_lists)),
                     ])); ?>">
                    <td class="metis-premium-cell">
                        <div><strong><?php echo metis_escape_html((string) ($campaign['name'] ?? '')); ?></strong></div>
                        <div class="metis-muted"><?php echo metis_escape_html((string) ($campaign['campaign_code'] ?? '')); ?></div>
                    </td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html((string) ($campaign['subject'] ?? '')); ?></td>
                    <td class="metis-premium-cell">
                        <?php if (!empty($row_lists)) : ?>
                            <div class="metis-newsletter-chip-wrap">
                                <?php foreach ($row_lists as $list_row) : ?>
                                    <span class="metis-chip"><?php echo metis_escape_html((string) ($list_row['name'] ?? '')); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?><span class="metis-muted">—</span><?php endif; ?>
                    </td>
                    <td class="metis-premium-cell"><span class="metis-chip"><?php echo metis_escape_html($status); ?></span></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html((string) ((int) ($campaign['total_recipients'] ?? 0))); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html(metis_newsletter_format_datetime((string) ($campaign['updated_at'] ?? ''))); ?></td>
                    <?php if ($can_manage) : ?>
                        <td class="metis-premium-cell metis-newsletter-actions-cell">
                            <?php if ($status !== 'archived') : ?>
                                <button class="metis-btn-xs metis-newsletter-test-campaign" type="button" data-campaign-id="<?php echo metis_escape_attr((string) $campaign_id); ?>">Test</button>
                            <?php endif; ?>
                            <?php if ($is_imported_archive) : ?>
                                <button class="metis-btn-xs metis-newsletter-manage-import-lists" type="button" data-campaign-id="<?php echo metis_escape_attr((string) $campaign_id); ?>">Lists</button>
                            <?php endif; ?>
                            <?php if (!$is_sentish && $status !== 'queued' && $status !== 'scheduled') : ?>
                                <button class="metis-btn-xs metis-newsletter-edit-campaign" type="button" data-campaign-code="<?php echo metis_escape_attr((string) ($campaign['campaign_code'] ?? '')); ?>" data-campaign-id="<?php echo metis_escape_attr((string) $campaign_id); ?>">Edit</button>
                            <?php endif; ?>
                            <?php if (!$is_sentish) : ?>
                                <button class="metis-btn-xs metis-newsletter-queue-campaign" type="button" data-campaign-id="<?php echo metis_escape_attr((string) $campaign_id); ?>">Send</button>
                                <button class="metis-btn-xs metis-btn-danger metis-newsletter-delete-campaign" type="button" data-campaign-id="<?php echo metis_escape_attr((string) $campaign_id); ?>">Delete</button>
                            <?php elseif ($status === 'sent') : ?>
                                <button class="metis-btn-xs metis-btn-danger metis-newsletter-archive-campaign" type="button" data-campaign-id="<?php echo metis_escape_attr((string) $campaign_id); ?>">Archive</button>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($campaigns)) : ?><tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="<?php echo $can_manage ? '7' : '6'; ?>">No campaigns yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div><!-- /metis-list-content -->
    </div><!-- /metis-list-layout -->
    <?php endif; ?>

    <div id="metis-newsletter-campaign-detail-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal metis-newsletter-modal-inner">
            <h3 class="metis-modal-title" id="metis-newsletter-campaign-detail-title">Campaign Details</h3>
            <div class="metis-newsletter-progress-wrap">
                <div class="metis-newsletter-progress-head">
                    <div id="metis-newsletter-progress-summary" class="metis-muted">No campaign selected.</div>
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

    <div id="metis-newsletter-test-send-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal metis-newsletter-modal-inner">
            <h3 class="metis-modal-title">Send Test Campaign</h3>
            <div class="metis-form-grid">
                <input type="hidden" id="metis-newsletter-test-campaign-id" value="0">
                <div class="metis-field metis-field-full">
                    <label>Recipient</label>
                    <div class="metis-newsletter-audience-mode">
                        <label><input type="radio" name="metis-test-target-mode" value="contact" checked> Existing Contact</label>
                        <label><input type="radio" name="metis-test-target-mode" value="email"> Custom Email</label>
                    </div>
                </div>
                <div class="metis-field metis-field-half metis-test-target-panel" data-test-panel="contact">
                    <label for="metis-newsletter-test-contact-search">Existing Contact</label>
                    <input id="metis-newsletter-test-contact-search" class="metis-input" type="text" autocomplete="off" placeholder="Search name, email, or CID">
                    <div id="metis-newsletter-test-contact-results" class="metis-newsletter-search-results"></div>
                </div>
                <div class="metis-field metis-field-half metis-test-target-panel" data-test-panel="email" style="display:none;">
                    <label for="metis-newsletter-test-email">Custom Email</label>
                    <input id="metis-newsletter-test-email" class="metis-input" type="email" maxlength="191" placeholder="name@example.org">
                </div>
            </div>
            <div class="metis-form-actions">
                <button type="button" class="metis-btn metis-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" id="metis-newsletter-send-test-confirm" class="metis-btn">Send Test</button>
            </div>
        </div>
    </div>

    <div id="metis-newsletter-import-lists-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal metis-newsletter-modal-inner">
            <h3 class="metis-modal-title">Imported Newsletter Lists</h3>
            <div class="metis-form-grid">
                <input type="hidden" id="metis-newsletter-import-lists-campaign-id" value="0">
                <div class="metis-field metis-field-full">
                    <label>Lists</label>
                    <div id="metis-newsletter-import-lists-options" class="metis-newsletter-audience-mode"></div>
                </div>
            </div>
            <div class="metis-form-actions">
                <button type="button" class="metis-btn metis-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" id="metis-newsletter-import-lists-save" class="metis-btn">Save Lists</button>
            </div>
        </div>
    </div>

    <div id="metis-newsletter-html-editor-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal metis-newsletter-modal-inner metis-newsletter-modal-compact">
            <h3 class="metis-modal-title">Edit HTML Block</h3>
            <div class="metis-form-grid">
                <div class="metis-field metis-field-full">
                    <label for="metis-newsletter-html-editor-input">HTML</label>
                    <textarea id="metis-newsletter-html-editor-input" class="metis-input" rows="10"></textarea>
                </div>
            </div>
            <div class="metis-form-actions">
                <button type="button" class="metis-btn metis-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" id="metis-newsletter-html-editor-save" class="metis-btn">Apply HTML</button>
            </div>
        </div>
    </div>

    <div id="metis-newsletter-video-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
        <div class="metis-modal metis-newsletter-modal-inner metis-newsletter-modal-compact">
            <h3 class="metis-modal-title">Video Block</h3>
            <div class="metis-form-grid">
                <div class="metis-field metis-field-full">
                    <label for="metis-newsletter-video-url">Video URL</label>
                    <input id="metis-newsletter-video-url" class="metis-input" type="url" placeholder="https://www.youtube.com/watch?v=">
                </div>
                <div class="metis-field metis-field-half">
                    <label for="metis-newsletter-video-label">Button Label</label>
                    <input id="metis-newsletter-video-label" class="metis-input" type="text" value="Watch video" maxlength="191">
                </div>
                <div class="metis-field metis-field-half">
                    <label for="metis-newsletter-video-thumb">Thumbnail URL (optional)</label>
                    <input id="metis-newsletter-video-thumb" class="metis-input" type="url" placeholder="https://...">
                </div>
            </div>
            <div class="metis-form-actions">
                <button type="button" class="metis-btn metis-btn-ghost metis-newsletter-cancel">Cancel</button>
                <button type="button" id="metis-newsletter-video-save" class="metis-btn">Save Video</button>
            </div>
        </div>
    </div>

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'lists' => $lists,
        'templates' => $templates,
        'ui' => [
            'compose_mode' => $compose_mode ? 1 : 0,
            'campaigns_url' => $campaigns_url,
            'compose_url' => $campaign_compose_url,
            'edit_url_base' => $campaign_edit_base_url,
            'allowed_blocks' => $newsletter_allowed_blocks,
        ],
        'defaults' => [
            'name' => '',
            'from_name' => $default_from_name,
            'from_email' => $default_from_email,
            'reply_to' => $default_reply_to,
            'campaign_type' => \Metis\Modules\Newsletter\CampaignService::TYPE_CAMPAIGN,
        ],
        'theme_defaults' => $newsletter_theme_defaults,
        'templates_by_id' => array_values(array_map(static fn($t) => [
            'id' => (int) ($t['id'] ?? 0),
            'template_code' => (string) ($t['template_code'] ?? ''),
            'subject' => (string) ($t['subject'] ?? ''),
            'from_name' => (string) ($t['from_name'] ?? ''),
            'from_email' => (string) ($t['from_email'] ?? ''),
            'reply_to' => (string) ($t['reply_to'] ?? ''),
            'doc_json' => (string) ($t['doc_json'] ?? ''),
            'html_body' => (string) ($t['html_body'] ?? ''),
        ], $templates)),
    ]); ?></script>
</div>
