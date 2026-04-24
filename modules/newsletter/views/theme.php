<?php
if (!defined('METIS_ROOT')) exit;

if (!metis_newsletter_can_view()) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view newsletter.</div>';
    return;
}

metis_newsletter_ensure_schema();

$can_manage = metis_newsletter_can_manage();
$dashboard_url = metis_portal_url('newsletter', 'dashboard');
$campaigns_url = metis_portal_url('newsletter', 'campaigns');
$theme_url = metis_portal_url('newsletter', 'theme');
$lists_url = metis_portal_url('newsletter', 'lists');
$subscribers_url = metis_portal_url('newsletter', 'subscribers');

$newsletter_theme_defaults = [
    'header_html' => (string) Core_Settings_Service::get('newsletter_theme_header_html', ''),
    'personalized_html' => (string) Core_Settings_Service::get('newsletter_theme_personalized_html', ''),
    'closing_html' => (string) Core_Settings_Service::get('newsletter_theme_closing_html', ''),
    'footer_html' => (string) Core_Settings_Service::get('newsletter_theme_footer_html', ''),
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

$active_theme = \Metis\Modules\Website\Services\ThemeService::getActiveNormalized();
$active_theme_colors = is_array($active_theme['colors'] ?? null) ? $active_theme['colors'] : [];
$newsletter_color_map = ['transparent' => 'transparent'];
$newsletter_color_options = ['transparent' => 'Transparent'];
foreach ($active_theme_colors as $color_key => $color_value) {
    $newsletter_color_options[(string) $color_key] = ucwords(str_replace(['_', '-'], ' ', (string) $color_key));
    $newsletter_color_map[(string) $color_key] = (string) $color_value;
}
$newsletter_divider_color_map = $newsletter_color_map;
$newsletter_divider_color_options = $newsletter_color_options;
$newsletter_divider_color_map['border'] = '#dfe6f3';
$newsletter_divider_color_options['border'] = 'Border';

$newsletter_theme_preview_doc = [
    'version' => 1,
    'settings' => [
        'header_html' => (string) ($newsletter_theme_defaults['header_html'] ?? ''),
        'personalized_html' => (string) ($newsletter_theme_defaults['personalized_html'] ?? ''),
        'closing_html' => (string) ($newsletter_theme_defaults['closing_html'] ?? ''),
        'footer_html' => (string) ($newsletter_theme_defaults['footer_html'] ?? ''),
        'canvas_bg' => (string) ($newsletter_theme_defaults['canvas_bg'] ?? 'transparent'),
        'text_color' => (string) ($newsletter_theme_defaults['text_color'] ?? 'text'),
        'font_size' => (int) ($newsletter_theme_defaults['font_size'] ?? 16),
        'content_width_mode' => (string) ($newsletter_theme_defaults['content_width_mode'] ?? 'normal'),
        'content_width' => max(520, min(820, (int) ($newsletter_theme_defaults['content_width'] ?? 680))),
        'divider_color' => (string) ($newsletter_theme_defaults['divider_color'] ?? 'border'),
        'divider_style' => (string) ($newsletter_theme_defaults['divider_style'] ?? 'solid'),
        'divider_weight' => (int) ($newsletter_theme_defaults['divider_weight'] ?? 1),
        'header_bg' => (string) ($newsletter_theme_defaults['header_bg'] ?? 'transparent'),
        'header_text_color' => (string) ($newsletter_theme_defaults['header_text_color'] ?? 'text'),
        'header_padding' => (string) ($newsletter_theme_defaults['header_padding'] ?? '24px 28px 12px 28px'),
        'personalized_bg' => (string) ($newsletter_theme_defaults['personalized_bg'] ?? 'transparent'),
        'personalized_text_color' => (string) ($newsletter_theme_defaults['personalized_text_color'] ?? 'text'),
        'personalized_padding' => (string) ($newsletter_theme_defaults['personalized_padding'] ?? '0 28px 8px 28px'),
        'closing_bg' => (string) ($newsletter_theme_defaults['closing_bg'] ?? 'transparent'),
        'closing_text_color' => (string) ($newsletter_theme_defaults['closing_text_color'] ?? 'text'),
        'closing_padding' => (string) ($newsletter_theme_defaults['closing_padding'] ?? '12px 28px 8px 28px'),
        'footer_bg' => (string) ($newsletter_theme_defaults['footer_bg'] ?? 'transparent'),
        'footer_text_color' => (string) ($newsletter_theme_defaults['footer_text_color'] ?? 'muted'),
        'footer_padding' => (string) ($newsletter_theme_defaults['footer_padding'] ?? '16px 28px 28px 28px'),
    ],
    'blocks' => [[
        'id' => 'preview-text',
        'type' => 'text',
        'data' => [
            'body' => '<p>Hello {{first_name}}, here is a bright little update from Mobilize Waco with one good thing, one useful link, and one next step to keep the momentum moving.</p>',
        ],
    ]],
];
$newsletter_merge_tags = [
    'First Name' => '{{first_name}}',
    'Last Name' => '{{last_name}}',
    'Full Name' => '{{full_name}}',
    'Preferred Name' => '{{name}}',
    'Email' => '{{email}}',
    'City' => '{{city}}',
    'State' => '{{state}}',
    'Campaign Name' => '{{campaign_name}}',
    'Unsubscribe Link' => '{{unsubscribe_url}}',
    'Manage Subscription Link' => '{{manage_subscription_url}}',
    'View Online Link' => '{{view_online_url}}',
];
$newsletter_media_options = function_exists('metis_website_ajax_media_options')
    ? metis_website_ajax_media_options()
    : [];
$newsletter_theme_preview_contact = function_exists('metis_newsletter_preview_contact_payload')
    ? metis_newsletter_preview_contact_payload()
    : [];
$newsletter_theme_preview_body_html = (string) ($newsletter_theme_preview_doc['blocks'][0]['data']['body'] ?? '<p>Hello {{first_name}}, here is a bright little update from Mobilize Waco with one good thing, one useful link, and one next step to keep the momentum moving.</p>');
$newsletter_theme_preview_body_html = function_exists('metis_newsletter_render_template')
    ? metis_newsletter_render_template($newsletter_theme_preview_body_html, $newsletter_theme_preview_contact)
    : $newsletter_theme_preview_body_html;
$newsletter_theme_preview_regions = [];
foreach (['header', 'personalized', 'closing', 'footer'] as $newsletter_theme_preview_region_key) {
    $newsletter_theme_preview_regions[$newsletter_theme_preview_region_key] = (string) ($newsletter_theme_defaults[$newsletter_theme_preview_region_key . '_html'] ?? '');
    if (function_exists('metis_newsletter_render_template')) {
        $newsletter_theme_preview_regions[$newsletter_theme_preview_region_key] = metis_newsletter_render_template(
            $newsletter_theme_preview_regions[$newsletter_theme_preview_region_key],
            $newsletter_theme_preview_contact
        );
    }
}
?>
<div class="metis-newsletter" data-can-manage="<?php echo $can_manage ? '1' : '0'; ?>">
    <h1 class="mw-page-title">Newsletter Theme</h1>

    <section class="mw-premium-wrap metis-newsletter-theme-card" id="metis-newsletter-theme-card">
        <div class="metis-newsletter-theme-head">
            <div>
                <div class="metis-newsletter-theme-title">Shared Newsletter Controls</div>
            </div>
            <?php if ($can_manage) : ?><button type="button" class="mw-btn" id="metis-newsletter-save-theme-defaults">Save & Activate</button><?php endif; ?>
        </div>
        <div class="metis-newsletter-theme-shell">
            <div class="metis-newsletter-theme-main">
                <section class="metis-newsletter-theme-section-card metis-newsletter-theme-section-card--general" id="metis-newsletter-theme-general">
                    <div class="metis-newsletter-theme-section-head">General</div>
                    <div class="metis-newsletter-theme-form metis-newsletter-theme-form--two">
                        <div class="mw-field">
                            <label>Content Width</label>
                            <div class="metis-newsletter-theme-width-presets" role="group" aria-label="Content width presets">
                                <?php foreach (['narrow' => ['Narrow', 560], 'normal' => ['Normal', 680], 'wide' => ['Wide', 760]] as $width_mode => [$width_label, $width_px]) : ?>
                                    <button
                                        type="button"
                                        class="metis-newsletter-theme-width-preset<?php echo ((string) ($newsletter_theme_defaults['content_width_mode'] ?? 'normal') === $width_mode) ? ' is-active' : ''; ?>"
                                        data-theme-width-mode="<?php echo metis_escape_attr($width_mode); ?>"
                                        data-theme-width-value="<?php echo metis_escape_attr((string) $width_px); ?>"
                                    >
                                        <span><?php echo metis_escape_html($width_label); ?></span>
                                        <small><?php echo metis_escape_html((string) $width_px); ?>px</small>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                            <input id="metis-newsletter-theme-width-mode" type="hidden" value="<?php echo metis_escape_attr((string) ($newsletter_theme_defaults['content_width_mode'] ?? 'normal')); ?>">
                            <input id="metis-newsletter-theme-width" type="hidden" value="<?php echo metis_escape_attr((string) ((int) $newsletter_theme_defaults['content_width'])); ?>">
                            <div class="metis-newsletter-theme-range-copy"><span id="metis-newsletter-theme-width-value"><?php echo metis_escape_html((string) ((int) $newsletter_theme_defaults['content_width'])); ?></span>px</div>
                        </div>
                        <div class="mw-field">
                            <label for="metis-newsletter-theme-font-size">Base Font Size</label>
                            <input id="metis-newsletter-theme-font-size" class="mw-input mw-input-wide" type="range" min="10" max="28" step="1" value="<?php echo metis_escape_attr((string) $newsletter_theme_defaults['font_size']); ?>">
                            <div class="metis-newsletter-theme-range-copy"><span id="metis-newsletter-theme-font-size-value"><?php echo metis_escape_html((string) ((int) $newsletter_theme_defaults['font_size'])); ?></span>px</div>
                        </div>
                        <div class="mw-field">
                            <label for="metis-newsletter-theme-canvas-bg">Canvas Background</label>
                            <select id="metis-newsletter-theme-canvas-bg" class="mw-input mw-input-wide">
                                <?php foreach ($newsletter_color_options as $value => $label) : ?>
                                    <option value="<?php echo metis_escape_attr($value); ?>" data-color="<?php echo metis_escape_attr((string) ($newsletter_color_map[$value] ?? 'transparent')); ?>"<?php echo ((string) ($newsletter_theme_defaults['canvas_bg'] ?? 'transparent') === (string) $value) ? ' selected' : ''; ?>><?php echo metis_escape_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mw-field">
                            <label for="metis-newsletter-theme-text-color">Body Text Color</label>
                            <select id="metis-newsletter-theme-text-color" class="mw-input mw-input-wide">
                                <?php foreach ($newsletter_color_options as $value => $label) : ?>
                                    <option value="<?php echo metis_escape_attr($value); ?>" data-color="<?php echo metis_escape_attr((string) ($newsletter_color_map[$value] ?? 'transparent')); ?>"<?php echo ((string) ($newsletter_theme_defaults['text_color'] ?? 'text') === (string) $value) ? ' selected' : ''; ?>><?php echo metis_escape_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mw-field">
                            <label for="metis-newsletter-theme-divider-color">Divider Color</label>
                            <select id="metis-newsletter-theme-divider-color" class="mw-input mw-input-wide">
                                <?php foreach ($newsletter_divider_color_options as $value => $label) : ?>
                                    <option value="<?php echo metis_escape_attr($value); ?>" data-color="<?php echo metis_escape_attr((string) ($newsletter_divider_color_map[$value] ?? 'transparent')); ?>"<?php echo ((string) ($newsletter_theme_defaults['divider_color'] ?? 'border') === (string) $value) ? ' selected' : ''; ?>><?php echo metis_escape_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mw-field">
                            <label for="metis-newsletter-theme-divider-style">Divider Style</label>
                            <select id="metis-newsletter-theme-divider-style" class="mw-input mw-input-wide">
                                <?php foreach (['solid' => 'Solid', 'dashed' => 'Dashed', 'dotted' => 'Dotted', 'double' => 'Double'] as $value => $label) : ?>
                                    <option value="<?php echo metis_escape_attr($value); ?>"<?php echo ((string) ($newsletter_theme_defaults['divider_style'] ?? 'solid') === (string) $value) ? ' selected' : ''; ?>><?php echo metis_escape_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mw-field">
                            <label for="metis-newsletter-theme-divider-weight">Divider Weight</label>
                            <input id="metis-newsletter-theme-divider-weight" class="mw-input mw-input-wide" type="range" min="1" max="6" step="1" value="<?php echo metis_escape_attr((string) ((int) ($newsletter_theme_defaults['divider_weight'] ?? 1))); ?>">
                            <div class="metis-newsletter-theme-range-copy"><span id="metis-newsletter-theme-divider-weight-value"><?php echo metis_escape_html((string) ((int) ($newsletter_theme_defaults['divider_weight'] ?? 1))); ?></span>px</div>
                        </div>
                    </div>
                </section>
                <?php foreach (['header' => 'Header', 'personalized' => 'Personalized Line', 'closing' => 'Closing Line', 'footer' => 'Footer'] as $section_key => $section_label) : ?>
                    <section class="metis-newsletter-theme-section-card" id="metis-newsletter-theme-<?php echo metis_escape_attr($section_key); ?>">
                        <div class="metis-newsletter-theme-section-head"><?php echo metis_escape_html($section_label); ?></div>
                        <div class="metis-newsletter-theme-form metis-newsletter-theme-form--two">
                            <div class="mw-field">
                                <label for="metis-newsletter-theme-<?php echo metis_escape_attr($section_key); ?>-bg">Background</label>
                                <select id="metis-newsletter-theme-<?php echo metis_escape_attr($section_key); ?>-bg" class="mw-input mw-input-wide">
                                    <?php foreach ($newsletter_color_options as $value => $label) : ?>
                                        <option value="<?php echo metis_escape_attr($value); ?>" data-color="<?php echo metis_escape_attr((string) ($newsletter_color_map[$value] ?? 'transparent')); ?>"<?php echo ((string) ($newsletter_theme_defaults[$section_key . '_bg'] ?? 'transparent') === (string) $value) ? ' selected' : ''; ?>><?php echo metis_escape_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mw-field">
                                <label for="metis-newsletter-theme-<?php echo metis_escape_attr($section_key); ?>-text-color">Text Color</label>
                                <select id="metis-newsletter-theme-<?php echo metis_escape_attr($section_key); ?>-text-color" class="mw-input mw-input-wide">
                                    <?php foreach ($newsletter_color_options as $value => $label) : ?>
                                        <option value="<?php echo metis_escape_attr($value); ?>" data-color="<?php echo metis_escape_attr((string) ($newsletter_color_map[$value] ?? 'transparent')); ?>"<?php echo ((string) ($newsletter_theme_defaults[$section_key . '_text_color'] ?? 'text') === (string) $value) ? ' selected' : ''; ?>><?php echo metis_escape_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php $pad = preg_split('/\s+/', trim((string) ($newsletter_theme_defaults[$section_key . '_padding'] ?? '0 0 0 0'))) ?: ['0','0','0','0']; while (count($pad) < 4) { $pad[] = '0'; } $linked = ($pad[0] === $pad[1] && $pad[1] === $pad[2] && $pad[2] === $pad[3]); ?>
                            <div class="mw-field metis-newsletter-theme-padding-group">
                                <label><?php echo metis_escape_html($section_label); ?> Padding</label>
                                <div class="metis-theme-box4 metis-newsletter-theme-box4" data-padding-group="<?php echo metis_escape_attr($section_key); ?>">
                                    <button type="button" class="metis-theme-box4-link<?php echo $linked ? ' is-linked' : ''; ?>" data-linked="<?php echo $linked ? '1' : '0'; ?>" title="Link all sides" aria-label="Toggle linked sides"><span class="metis-theme-box4-link-icon" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M11.8 8.2a3 3 0 0 1 4.2 4.2l-2.1 2.1a3 3 0 0 1-4.2 0l-.7-.7 1.4-1.4.7.7a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 1 0-1.4-1.4l-.9.9-1.4-1.4.9-.9ZM8.2 11.8a3 3 0 0 1-4.2-4.2l2.1-2.1a3 3 0 0 1 4.2 0l.7.7-1.4 1.4-.7-.7a1 1 0 0 0-1.4 0L5.4 9.1a1 1 0 1 0 1.4 1.4l.9-.9 1.4 1.4-.9.9Zm.6-2.8 2.2-2.2 1.4 1.4-2.2 2.2-1.4-1.4Z"/></svg></span><span class="metis-theme-box4-link-text">Linked</span></button>
                                    <div class="metis-theme-box4-grid">
                                        <input type="text" class="mw-input metis-theme-box4-input" data-side="top" placeholder="T" value="<?php echo metis_escape_attr((string) $pad[0]); ?>">
                                        <input type="text" class="mw-input metis-theme-box4-input" data-side="right" placeholder="R" value="<?php echo metis_escape_attr((string) $pad[1]); ?>">
                                        <input type="text" class="mw-input metis-theme-box4-input" data-side="bottom" placeholder="B" value="<?php echo metis_escape_attr((string) $pad[2]); ?>">
                                        <input type="text" class="mw-input metis-theme-box4-input" data-side="left" placeholder="L" value="<?php echo metis_escape_attr((string) $pad[3]); ?>">
                                    </div>
                                </div>
                                <input type="hidden" id="metis-newsletter-theme-<?php echo metis_escape_attr($section_key); ?>-padding" value="<?php echo metis_escape_attr((string) ($newsletter_theme_defaults[$section_key . '_padding'] ?? '0 0 0 0')); ?>">
                            </div>
                        </div>
                        <div class="mw-field">
                            <label for="metis-newsletter-theme-<?php echo metis_escape_attr($section_key); ?>-html"><?php echo metis_escape_html($section_label); ?> Content</label>
                            <div class="metis-newsletter-theme-editor" data-theme-editor="<?php echo metis_escape_attr($section_key); ?>">
                                <div class="metis-se-rich-tools" data-theme-toolbar="<?php echo metis_escape_attr($section_key); ?>"></div>
                                <div
                                    id="metis-newsletter-theme-<?php echo metis_escape_attr($section_key); ?>-editor"
                                    class="metis-newsletter-theme-editor-surface metis-se-rich-editor metis-se-rich-editor-child"
                                    contenteditable="true"
                                    data-theme-editor-surface="<?php echo metis_escape_attr($section_key); ?>"
                                ><?php echo (string) ($newsletter_theme_defaults[$section_key . '_html'] ?? ''); ?></div>
                                <input type="hidden" id="metis-newsletter-theme-<?php echo metis_escape_attr($section_key); ?>-html" value="<?php echo metis_escape_attr((string) ($newsletter_theme_defaults[$section_key . '_html'] ?? '')); ?>">
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
            <aside class="metis-newsletter-theme-preview">
                <div class="metis-newsletter-theme-preview-head">Live Preview</div>
                <div class="metis-newsletter-theme-preview-frame" aria-label="Newsletter Theme Preview">
                    <div class="metis-newsletter-theme-preview-loading is-active" id="metis-newsletter-theme-preview-loading">
                        <div class="metis-newsletter-theme-preview-loading-copy">Loading preview…</div>
                    </div>
                    <div id="metis-newsletter-theme-preview-canvas" class="metis-newsletter-theme-preview-canvas">
                        <div class="metis-newsletter-theme-preview-shell" data-metis-newsletter-shell="1">
                            <div class="metis-newsletter-theme-preview-inner" data-newsletter-preview-inner>
                                <div class="metis-newsletter-theme-preview-region" data-metis-newsletter-region="header"><?php echo (string) ($newsletter_theme_preview_regions['header'] ?? ''); ?></div>
                                <div class="metis-newsletter-theme-preview-region" data-metis-newsletter-region="personalized"><?php echo (string) ($newsletter_theme_preview_regions['personalized'] ?? ''); ?></div>
                                <div class="metis-newsletter-theme-preview-region metis-newsletter-theme-preview-region--body" data-metis-newsletter-region="body"><?php echo $newsletter_theme_preview_body_html; ?></div>
                                <div class="metis-newsletter-theme-preview-region" data-metis-newsletter-region="closing"><?php echo (string) ($newsletter_theme_preview_regions['closing'] ?? ''); ?></div>
                                <div class="metis-newsletter-theme-preview-region" data-metis-newsletter-region="footer"><?php echo (string) ($newsletter_theme_preview_regions['footer'] ?? ''); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <div id="metis-newsletter-theme-inline-image-modal" class="mw-modal-backdrop metis-featured-image-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-label="Inline Image Picker">
        <div class="mw-modal metis-featured-image-modal__dialog">
            <div class="mw-modal-header">
                <div>
                    <h2 class="mw-modal-title">Insert Image</h2>
                    <div id="metis-newsletter-theme-inline-image-count" class="metis-featured-image-modal__count">Loading images...</div>
                </div>
                <button type="button" class="mw-modal-close" id="metis-newsletter-theme-inline-image-close" aria-label="Close">&times;</button>
            </div>
            <div class="mw-modal-body">
                <div class="metis-featured-image-modal__toolbar">
                    <input id="metis-newsletter-theme-inline-image-search" class="mw-input" type="search" placeholder="Search images by name or type">
                    <select id="metis-newsletter-theme-inline-image-mime" class="mw-input">
                        <option value="">All image types</option>
                    </select>
                </div>
                <div id="metis-newsletter-theme-inline-image-list" class="metis-media-grid metis-featured-image-modal__grid"></div>
            </div>
            <div class="mw-modal-footer">
                <button type="button" class="mw-btn" id="metis-newsletter-theme-inline-image-cancel">Close</button>
            </div>
        </div>
    </div>

    <script id="metis-newsletter-data" type="application/json"><?php echo metis_json_encode([
        'ui' => [
            'theme_url' => $theme_url,
            'allowed_blocks' => ['text','feature_grid','cta'],
        ],
        'media' => $newsletter_media_options,
        'theme_defaults' => $newsletter_theme_defaults,
        'theme_preview_doc' => $newsletter_theme_preview_doc,
        'theme_preview_contact' => $newsletter_theme_preview_contact,
    ]); ?></script>
</div>
