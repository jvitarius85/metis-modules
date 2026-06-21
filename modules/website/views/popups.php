<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'popups' ) ) {
    return;
}

use Metis\Modules\Website\Services\EditorOptionsService;
use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\PopupService;

$popups = PopupService::getAll();
$site_timezone = function_exists( 'metis_runtime_timezone_name' ) ? (string) metis_runtime_timezone_name() : 'UTC';
$branding_colors = class_exists( 'Core_Settings_Service' ) ? \Core_Settings_Service::get( 'theme_colors', [] ) : [];
if ( ! is_array( $branding_colors ) ) {
    $branding_colors = [];
}

$trigger_types = [
    'click' => 'Button click',
    'delay' => 'Time delay',
    'load' => 'Page load',
    'scroll' => 'Scroll depth',
    'exit' => 'Exit intent',
];

$audience_options = [
    'site_wide' => 'Entire website',
    'selected_pages' => 'Selected pages',
    'all_pages' => 'All pages',
    'all_posts' => 'All posts',
];

$launcher_positions = [
    'top_left' => 'Top left',
    'top_right' => 'Top right',
    'bottom_left' => 'Bottom left',
    'bottom_right' => 'Bottom right',
];

$launcher_color_options = [
    'metis_primary' => 'Primary brand',
    'metis_accent' => 'Accent brand',
    'metis_text' => 'Text',
    'metis_surface' => 'Surface',
];

$launcher_text_color_options = [
    '' => 'Automatic',
    'metis_primary' => 'Primary brand',
    'metis_accent' => 'Accent brand',
    'metis_text' => 'Text',
    'metis_surface' => 'Surface',
];

$launcher_style_options = [
    'solid' => 'Solid',
    'soft' => 'Soft',
    'outline' => 'Outline',
];

$launcher_layout_options = [
    'full' => 'Label and icon',
    'icon' => 'Icon only circle',
];

$launcher_shape_options = [
    'pill' => 'Pill',
    'rounded' => 'Rounded',
    'square' => 'Square',
];

$launcher_border_width_options = [
    'none' => 'No border',
    'thin' => 'Thin border',
    'regular' => 'Regular border',
    'thick' => 'Thick border',
];

$launcher_border_effect_options = [
    'single' => 'Single ring',
    'double_ring' => 'Double ring',
];

$launcher_icon_options = [
    '' => 'No icon',
    'currency-dollar' => 'Dollar',
    'favorite-filled' => 'Heart',
    'email-new' => 'Email',
    'calendar' => 'Calendar',
    'chat' => 'Chat',
    'event-schedule' => 'Schedule',
    'forum' => 'Conversation',
    'checkmark-filled' => 'Checkmark',
    'add-filled' => 'Plus',
    'arrow-right' => 'Arrow right',
    'accessibility' => 'Accessibility',
];

$branding_field_defs = [
    'metis_primary' => [ 'label' => 'Primary brand', 'default' => '#485bc7' ],
    'metis_accent' => [ 'label' => 'Accent brand', 'default' => '#ff7542' ],
    'metis_text' => [ 'label' => 'Text', 'default' => '#1f2330' ],
    'metis_surface' => [ 'label' => 'Surface', 'default' => '#ffffff' ],
];
$launcher_color_palette = [];
foreach ( $branding_field_defs as $key => $field ) {
    $default = (string) ( $field['default'] ?? '#000000' );
    $raw = (string) ( $branding_colors[ $key ] ?? $default );
    $launcher_color_palette[ $key ] = metis_hex_color_clean( $raw ) ?: $default;
}

$content_modes = [
    'message' => 'Message and button',
    'form' => 'Sign-up or standard form',
    'donation_form' => 'Donation form',
];

$page_options = [];
foreach ( PageService::getAll( [ 'status' => 'published', 'fetch_all' => true ] ) as $page ) {
    if ( ! is_object( $page ) ) {
        continue;
    }
    $id = (int) ( $page->id ?? 0 );
    if ( $id < 1 ) {
        continue;
    }
    $path = method_exists( PageService::class, 'publishedPathForPage' ) ? (string) PageService::publishedPathForPage( $page ) : '';
    $title = trim( (string) ( $page->title ?? '' ) );
    $page_options[] = [
        'id' => $id,
        'path' => $path !== '' ? $path : '/',
        'slug' => trim( (string) ( $page->slug ?? '' ) ),
        'label' => $title !== '' ? $title : ( 'Page #' . $id ),
    ];
}

$form_options = [];
if ( class_exists( '\Metis\Modules\Newsletter\SubscriptionService' ) ) {
    $form_options[] = [
        'value' => \Metis\Modules\Newsletter\SubscriptionService::DEFAULT_SIGNUP_FORM_REF,
        'label' => 'Default newsletter sign-up',
    ];
}
if ( class_exists( '\Metis\Modules\Forms\Repository' ) ) {
    foreach ( \Metis\Modules\Forms\Repository::listForms() as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $id = (int) ( $row['id'] ?? 0 );
        if ( $id < 1 ) {
            continue;
        }
        if ( strtolower( trim( (string) ( $row['status'] ?? 'draft' ) ) ) !== 'published' ) {
            continue;
        }
        $label = trim( (string) ( $row['name'] ?? '' ) );
        if ( $label === '' ) {
            $label = trim( (string) ( $row['slug'] ?? '' ) );
        }
        if ( $label === '' ) {
            $label = 'Form #' . $id;
        }
        $form_options[] = [
            'value' => (string) $id,
            'label' => $label,
        ];
    }
}

$campaign_options = EditorOptionsService::donationCampaignOptions();
?>
<style>
.metis-campaign-editor{display:grid;gap:20px}
.metis-campaign-editor__toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.metis-modal.metis-campaign-modal{width:min(980px,94vw);max-width:980px}
.metis-modal.metis-campaign-modal .metis-modal-body{display:grid;gap:18px;padding:24px}
.metis-campaign-section{border:1px solid #dbe2f0;border-radius:18px;padding:18px;background:#f8fbff}
.metis-campaign-section__head{display:grid;gap:4px;margin-bottom:14px}
.metis-campaign-section__title{margin:0;font-size:18px;font-weight:700;color:#22304a}
.metis-campaign-section__help{margin:0;color:#60708d;font-size:14px;line-height:1.45}
.metis-campaign-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 16px}
.metis-campaign-grid--single{grid-template-columns:minmax(0,1fr)}
.metis-campaign-field{display:grid;gap:6px}
.metis-campaign-field label{font-size:13px;font-weight:700;color:#2b3954}
.metis-campaign-field .metis-input,.metis-campaign-field .metis-ui-select,.metis-campaign-field textarea.metis-input{width:100%}
.metis-campaign-field textarea{min-height:104px;resize:vertical}
.metis-campaign-field-help{font-size:12px;color:#6a7890;line-height:1.4}
.metis-campaign-choice-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.metis-campaign-choice{display:grid;gap:6px;padding:14px;border:1px solid #dbe2f0;border-radius:14px;background:#fff;cursor:pointer}
.metis-campaign-choice input{margin:0}
.metis-campaign-choice strong{font-size:14px;color:#22304a}
.metis-campaign-choice span{font-size:12px;color:#6a7890;line-height:1.35}
.metis-campaign-audience-search{margin-bottom:12px}
.metis-campaign-checklist{display:grid;gap:8px;max-height:240px;overflow:auto;padding:4px}
.metis-campaign-check{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #dbe2f0;border-radius:12px;background:#fff}
.metis-campaign-check input{margin-top:2px}
.metis-campaign-check strong{display:block;font-size:14px;color:#22304a}
.metis-campaign-check span{display:block;font-size:12px;color:#6a7890}
.metis-campaign-inline-note{padding:12px 14px;border-radius:12px;background:#eef4ff;color:#35508a;font-size:13px;line-height:1.45}
.metis-campaign-kpi-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.metis-campaign-kpi{padding:14px;border-radius:14px;background:#fff;border:1px solid #dbe2f0}
.metis-campaign-kpi strong{display:block;font-size:13px;color:#60708d;margin-bottom:4px}
.metis-campaign-kpi span{font-size:15px;color:#22304a;font-weight:700}
.metis-campaign-inline-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.metis-campaign-emoji-preview{display:inline-flex;align-items:center;justify-content:center;min-width:40px;min-height:40px;padding:0 10px;border:1px solid #dbe2f0;border-radius:12px;background:#fff;font-size:20px}
.metis-campaign-row-hidden{display:none !important}
@media (max-width: 860px){
  .metis-campaign-grid,.metis-campaign-choice-row,.metis-campaign-kpi-row{grid-template-columns:minmax(0,1fr)}
}
</style>

<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Popups</h1>
        <p class="metis-subtitle" id="metis-popup-subtitle"><?php echo count( $popups ); ?> popup<?php echo count( $popups ) !== 1 ? 's' : ''; ?> ready for guided website triggers.</p>
    </div>
    <div class="metis-page-header-right">
        <button class="metis-btn metis-btn-primary" id="metis-create-popup-btn">
            <svg class="metis-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Popup
        </button>
    </div>
</div>

<div class="metis-table-wrap" id="metis-popup-list-region">
    <?php if ( empty( $popups ) ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#9741;</div>
            <h2>No popups yet</h2>
            <p>Create popups that open from buttons, time delay, scroll depth, or exit intent.</p>
            <button class="metis-btn metis-btn-primary" id="metis-create-popup-btn-empty">New Popup</button>
        </div>
    <?php else : ?>
        <table class="metis-premium-table metis-popup-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Popup</th>
                    <th class="metis-premium-cell" scope="col">Trigger</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $popups as $popup ) : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><strong><?php echo metis_escape_html( (string) ( $popup['name'] ?? '' ) ); ?></strong></td>
                        <td class="metis-premium-cell metis-table-meta-cell"><?php echo metis_escape_html( $trigger_types[ $popup['trigger_type'] ?? 'click' ] ?? ucfirst( (string) ( $popup['trigger_type'] ?? '' ) ) ); ?></td>
                        <td class="metis-premium-cell"><span class="metis-status metis-status-<?php echo metis_escape_attr( (string) ( $popup['status'] ?? 'draft' ) ); ?>"><?php echo metis_escape_html( ucfirst( (string) ( $popup['status'] ?? 'draft' ) ) ); ?></span></td>
                        <td class="metis-premium-cell metis-col-right">
                            <div class="metis-table-actions">
                                <button class="metis-action-btn metis-edit-popup"
                                    data-id="<?php echo metis_escape_attr( (string) ( $popup['id'] ?? '' ) ); ?>"
                                    data-name="<?php echo metis_escape_attr( (string) ( $popup['name'] ?? '' ) ); ?>"
                                    data-trigger="<?php echo metis_escape_attr( (string) ( $popup['trigger_type'] ?? 'click' ) ); ?>"
                                    data-trigger-config="<?php echo metis_escape_attr( (string) ( $popup['trigger_config_json'] ?? '{}' ) ); ?>"
                                    data-display-rules="<?php echo metis_escape_attr( (string) ( $popup['display_rules_json'] ?? '{}' ) ); ?>"
                                    data-layout="<?php echo metis_escape_attr( (string) ( $popup['layout_json'] ?? '{}' ) ); ?>"
                                    data-status="<?php echo metis_escape_attr( (string) ( $popup['status'] ?? 'draft' ) ); ?>"
                                    title="Edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button class="metis-action-btn metis-action-btn-danger metis-delete-popup" data-id="<?php echo metis_escape_attr( (string) ( $popup['id'] ?? '' ) ); ?>" data-name="<?php echo metis_escape_attr( (string) ( $popup['name'] ?? '' ) ); ?>" title="Delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script id="metis-popup-page-options" type="application/json"><?php echo metis_json_encode( $page_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
<script id="metis-popup-form-options" type="application/json"><?php echo metis_json_encode( $form_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
<script id="metis-popup-campaign-options" type="application/json"><?php echo metis_json_encode( $campaign_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>

<div id="metis-popup-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal metis-campaign-modal">
        <div class="metis-modal-header">
            <div>
                <h2 class="metis-modal-title" id="metis-popup-modal-title">New Popup</h2>
                <p class="metis-campaign-section__help">Set how it opens, what it shows, and where it appears without raw slugs or layout JSON.</p>
            </div>
            <button type="button" class="metis-modal-close" data-modal-close="metis-popup-modal" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body">
            <input type="hidden" id="metis-popup-id" value="">

            <section class="metis-campaign-section">
                <div class="metis-campaign-section__head">
                    <h3 class="metis-campaign-section__title">Basics</h3>
                    <p class="metis-campaign-section__help">Give the popup a clear internal name and decide whether it is ready for the public site.</p>
                </div>
                <div class="metis-campaign-grid">
                    <div class="metis-campaign-field">
                        <label for="metis-popup-name">Internal name</label>
                        <input id="metis-popup-name" type="text" class="metis-input" placeholder="Summer donation ask">
                    </div>
                    <div class="metis-campaign-field">
                        <label for="metis-popup-status">Status</label>
                        <select id="metis-popup-status" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>
                </div>
            </section>

            <section class="metis-campaign-section">
                <div class="metis-campaign-section__head">
                    <h3 class="metis-campaign-section__title">How It Opens</h3>
                    <p class="metis-campaign-section__help">Choose the trigger first. Only the relevant settings appear.</p>
                </div>
                <div class="metis-campaign-grid">
                    <div class="metis-campaign-field">
                        <label for="metis-popup-trigger">Trigger</label>
                        <select id="metis-popup-trigger" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $trigger_types as $trigger_key => $trigger_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $trigger_key ); ?>"><?php echo metis_escape_html( $trigger_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field">
                        <label for="metis-popup-frequency">Repeat behavior</label>
                        <select id="metis-popup-frequency" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <option value="session">Once per session</option>
                            <option value="persisted">Remember across visits</option>
                            <option value="always">Every time</option>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-delay-row">
                        <label for="metis-popup-delay-seconds">Delay in seconds</label>
                        <input id="metis-popup-delay-seconds" type="number" min="0" step="0.5" class="metis-input" value="1.5">
                    </div>
                    <div class="metis-campaign-field metis-popup-scroll-row">
                        <label for="metis-popup-scroll">Scroll depth percent</label>
                        <input id="metis-popup-scroll" type="number" min="1" max="100" step="1" class="metis-input" value="50">
                    </div>
                    <div class="metis-campaign-field metis-popup-click-row">
                        <label for="metis-popup-click-mode">Button click source</label>
                        <select id="metis-popup-click-mode" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <option value="page_button">Use page or post buttons</option>
                            <option value="floating_button">Use a floating corner button</option>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row">
                        <label for="metis-popup-launcher-label">Floating button label</label>
                        <input id="metis-popup-launcher-label" type="text" class="metis-input" value="Open popup">
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row">
                        <label for="metis-popup-launcher-position">Floating button position</label>
                        <select id="metis-popup-launcher-position" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $launcher_positions as $position_key => $position_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $position_key ); ?>"><?php echo metis_escape_html( $position_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row">
                        <label for="metis-popup-launcher-color">Floating button color</label>
                        <select id="metis-popup-launcher-color" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input" data-metis-select-variant="theme-binding">
                            <?php foreach ( $launcher_color_options as $color_key => $color_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $color_key ); ?>"
                                    data-metis-select-color="<?php echo metis_escape_attr( (string) ( $launcher_color_palette[ $color_key ] ?? '#ffffff' ) ); ?>">
                                    <?php echo metis_escape_html( $color_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row">
                        <label for="metis-popup-launcher-text-color">Floating button font color</label>
                        <select id="metis-popup-launcher-text-color" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input" data-metis-select-variant="theme-binding">
                            <?php foreach ( $launcher_text_color_options as $color_key => $color_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $color_key ); ?>"<?php echo $color_key !== '' ? ' data-metis-select-color="' . metis_escape_attr( (string) ( $launcher_color_palette[ $color_key ] ?? '#ffffff' ) ) . '"' : ''; ?>>
                                    <?php echo metis_escape_html( $color_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row">
                        <label for="metis-popup-launcher-layout">Floating button layout</label>
                        <select id="metis-popup-launcher-layout" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $launcher_layout_options as $layout_key => $layout_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $layout_key ); ?>"><?php echo metis_escape_html( $layout_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row">
                        <label for="metis-popup-launcher-style">Floating button style</label>
                        <select id="metis-popup-launcher-style" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $launcher_style_options as $style_key => $style_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $style_key ); ?>"><?php echo metis_escape_html( $style_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row metis-popup-launcher-shape-row">
                        <label for="metis-popup-launcher-shape">Floating button corners</label>
                        <select id="metis-popup-launcher-shape" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $launcher_shape_options as $shape_key => $shape_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $shape_key ); ?>"><?php echo metis_escape_html( $shape_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row">
                        <label for="metis-popup-launcher-border-width">Floating button border</label>
                        <select id="metis-popup-launcher-border-width" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $launcher_border_width_options as $border_key => $border_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $border_key ); ?>"><?php echo metis_escape_html( $border_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row">
                        <label for="metis-popup-launcher-border-effect">Floating button border effect</label>
                        <select id="metis-popup-launcher-border-effect" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $launcher_border_effect_options as $border_key => $border_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $border_key ); ?>"><?php echo metis_escape_html( $border_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="metis-campaign-field metis-popup-launcher-row">
                        <label for="metis-popup-launcher-icon">Floating button icon</label>
                        <div class="metis-campaign-inline-actions">
                            <select id="metis-popup-launcher-icon" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                                <?php foreach ( $launcher_icon_options as $icon_key => $icon_label ) : ?>
                                    <option value="<?php echo metis_escape_attr( $icon_key ); ?>"><?php echo metis_escape_html( $icon_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span id="metis-popup-launcher-icon-preview" class="metis-campaign-emoji-preview" aria-hidden="true">+</span>
                        </div>
                        <div class="metis-campaign-field-help">Choose a shared icon from the Metis icon library.</div>
                    </div>
                </div>
                <div class="metis-campaign-inline-note metis-popup-button-note">
                    Button-triggered popups can now be opened from normal page and post button blocks by choosing <strong>Open a popup</strong> in button settings.
                </div>
            </section>

            <section class="metis-campaign-section">
                <div class="metis-campaign-section__head">
                    <h3 class="metis-campaign-section__title">What It Shows</h3>
                    <p class="metis-campaign-section__help">Choose a content type that matches the job instead of building raw popup markup.</p>
                </div>
                <div class="metis-campaign-choice-row">
                    <?php foreach ( $content_modes as $content_key => $content_label ) : ?>
                        <label class="metis-campaign-choice">
                            <input type="radio" name="metis-popup-content-mode" value="<?php echo metis_escape_attr( $content_key ); ?>" <?php echo $content_key === 'message' ? 'checked' : ''; ?>>
                            <strong><?php echo metis_escape_html( $content_label ); ?></strong>
                            <span>
                                <?php if ( $content_key === 'message' ) : ?>
                                    Simple headline, text, and optional button.
                                <?php elseif ( $content_key === 'form' ) : ?>
                                    Use the default newsletter sign-up or another published form.
                                <?php else : ?>
                                    Embed a donations form tied to a campaign.
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="metis-campaign-grid metis-campaign-grid--single" style="margin-top:14px">
                    <div class="metis-campaign-field">
                        <label for="metis-popup-headline">Headline</label>
                        <input id="metis-popup-headline" type="text" class="metis-input" placeholder="Support our summer programs">
                    </div>
                    <div class="metis-campaign-field metis-popup-copy-row">
                        <label for="metis-popup-message">Supporting text</label>
                        <textarea id="metis-popup-message" class="metis-input" rows="5" placeholder="Add a short, direct message."></textarea>
                    </div>
                    <div class="metis-campaign-grid metis-popup-message-actions">
                        <div class="metis-campaign-field">
                            <label for="metis-popup-button-label">Button label</label>
                            <input id="metis-popup-button-label" type="text" class="metis-input" placeholder="Learn more">
                        </div>
                        <div class="metis-campaign-field">
                            <label for="metis-popup-button-url">Button link</label>
                            <input id="metis-popup-button-url" type="url" class="metis-input" placeholder="https://example.org">
                        </div>
                    </div>
                    <div class="metis-campaign-field metis-popup-form-row metis-campaign-row-hidden">
                        <label for="metis-popup-form-id">Sign-up or standard form</label>
                        <select id="metis-popup-form-id" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <option value="">Select form</option>
                            <?php foreach ( $form_options as $option ) : ?>
                                <option value="<?php echo metis_escape_attr( (string) $option['value'] ); ?>"><?php echo metis_escape_html( (string) $option['label'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="metis-campaign-field-help">The default newsletter sign-up collects first name, last name, and email, then subscribes the person to the Newsletter list.</div>
                    </div>
                    <div class="metis-campaign-field metis-popup-form-row metis-campaign-row-hidden">
                        <label for="metis-popup-submit-label">Submit button label</label>
                        <input id="metis-popup-submit-label" type="text" class="metis-input" value="Submit">
                    </div>
                    <div class="metis-campaign-field metis-popup-donation-row metis-campaign-row-hidden">
                        <label for="metis-popup-campaign-id">Donation campaign</label>
                        <select id="metis-popup-campaign-id" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <option value="">Select campaign</option>
                            <?php foreach ( $campaign_options as $option ) : ?>
                                <option value="<?php echo metis_escape_attr( (string) $option['value'] ); ?>"><?php echo metis_escape_html( (string) $option['label'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="metis-campaign-field-help">Donation popups use the standard Metis donation form embed and campaign settings.</div>
                    </div>
                </div>
            </section>

            <section class="metis-campaign-section">
                <div class="metis-campaign-section__head">
                    <h3 class="metis-campaign-section__title">Where It Appears</h3>
                    <p class="metis-campaign-section__help">Choose an audience rule without raw paths or slugs.</p>
                </div>
                <div class="metis-campaign-grid">
                    <div class="metis-campaign-field">
                        <label for="metis-popup-audience-mode">Audience</label>
                        <select id="metis-popup-audience-mode" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                            <?php foreach ( $audience_options as $audience_key => $audience_label ) : ?>
                                <option value="<?php echo metis_escape_attr( $audience_key ); ?>"><?php echo metis_escape_html( $audience_label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="metis-popup-page-targets" class="metis-campaign-row-hidden" style="margin-top:14px">
                    <div class="metis-campaign-field metis-campaign-audience-search">
                        <label for="metis-popup-page-search">Filter pages</label>
                        <input id="metis-popup-page-search" type="search" class="metis-input" placeholder="Search by page title">
                    </div>
                    <div id="metis-popup-page-checklist" class="metis-campaign-checklist"></div>
                </div>
            </section>
        </div>
        <div class="metis-modal-footer">
            <button id="metis-popup-save-btn" class="metis-btn metis-btn-primary">Save Popup</button>
            <button class="metis-btn metis-btn-ghost" type="button" data-modal-close="metis-popup-modal">Cancel</button>
        </div>
    </div>
</div>

<script>
(function(){
'use strict';
if (!window.jQuery) {
    document.addEventListener('DOMContentLoaded', function(){
        if (window.jQuery && !window.__metisPopupViewInit) {
            window.__metisPopupViewInit = true;
            init(window.jQuery);
        }
    });
    return;
}
if (window.__metisPopupViewInit) { return; }
window.__metisPopupViewInit = true;
init(window.jQuery);

function init($) {
    var pageOptions = parseJson($('#metis-popup-page-options').text(), []);
    var formOptions = parseJson($('#metis-popup-form-options').text(), []);
    var campaignOptions = parseJson($('#metis-popup-campaign-options').text(), []);
    var ajaxConfig = window.metisWebsiteAjax || {};

    function parseJson(raw, fallback) {
        try {
            var parsed = JSON.parse(String(raw || ''));
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (err) {
            return fallback;
        }
    }

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function escapeHtml(value) {
        return esc(value);
    }

    function nonceFor(action) {
        if (window.Metis && Metis.ajax && typeof Metis.ajax.nonceFor === 'function') {
            return String(Metis.ajax.nonceFor(action, String(ajaxConfig.nonce || '')) || '');
        }
        var map = ajaxConfig.action_nonces && typeof ajaxConfig.action_nonces === 'object' ? ajaxConfig.action_nonces : {};
        return String(map[action] || ajaxConfig.nonce || '');
    }

    function extractErrorMessage(xhr, fallback) {
        var message = '';
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            message = String(xhr.responseJSON.data.message || '');
        } else if (xhr && typeof xhr.responseText === 'string' && xhr.responseText) {
            try {
                var parsed = JSON.parse(xhr.responseText);
                if (parsed && parsed.data && parsed.data.message) {
                    message = String(parsed.data.message || '');
                }
            } catch (err) {}
        }
        return message || fallback || 'Request failed.';
    }

    function plainTextFromHtml(value) {
        return String(value || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function textToParagraphHtml(value) {
        return String(value || '').split(/\n{2,}/).map(function(chunk) {
            var line = chunk.trim();
            if (!line) return '';
            return '<p>' + escapeHtml(line).replace(/\n/g, '<br>') + '</p>';
        }).filter(Boolean).join('');
    }

    function currentContentMode() {
        return $('input[name="metis-popup-content-mode"]:checked').val() || 'message';
    }

    function launcherIconUrl(slug) {
        var key = String(slug || '').trim();
        if (!key) return '';
        if (window.Metis && Metis.ui && Metis.ui.richText && typeof Metis.ui.richText.iconUrl === 'function') {
            return String(Metis.ui.richText.iconUrl(key) || '');
        }
        return '';
    }

    function launcherIconFallbackUrl(slug) {
        var key = String(slug || '').trim();
        if (!key) return '';
        if (window.Metis && Metis.ui && Metis.ui.richText && typeof Metis.ui.richText.iconFallbackUrl === 'function') {
            return String(Metis.ui.richText.iconFallbackUrl(key) || '');
        }
        return '';
    }

    function updateLauncherIconPreview() {
        var slug = String($('#metis-popup-launcher-icon').val() || '').trim();
        var $preview = $('#metis-popup-launcher-icon-preview');
        if (!slug) {
            $preview.html('<span aria-hidden="true">+</span>');
            return;
        }
        var src = launcherIconUrl(slug);
        var fallback = launcherIconFallbackUrl(slug);
        if (!src) {
            $preview.html('<span aria-hidden="true">+</span>');
            return;
        }
        var attrs = ' src="' + esc(src) + '" alt="" aria-hidden="true"';
        if (fallback) {
            attrs += ' data-icon-fallback="' + esc(fallback) + '"';
        }
        $preview.html('<img' + attrs + '>');
        if (window.Metis && Metis.ui && Metis.ui.richText && typeof Metis.ui.richText.bindIconFallbacks === 'function') {
            Metis.ui.richText.bindIconFallbacks($preview.get(0));
        }
    }

    function normalizePopupLayoutForSave(layout) {
        var out = layout && typeof layout === 'object' ? layout : { version: 1, blocks: [] };
        var blocks = Array.isArray(out.blocks) ? out.blocks : [];
        out.blocks = blocks.map(function(block) {
            if (!block || typeof block !== 'object') return block;
            var next = $.extend(true, {}, block);
            if (String(next.type || '') === 'donation_form') {
                next.type = 'donation_form_block';
            }
            return next;
        });
        return out;
    }

    function selectedPagePaths() {
        return $('#metis-popup-page-checklist input[type="checkbox"]:checked').map(function(){ return String(this.value || ''); }).get();
    }

    function setSelectedPagePaths(paths) {
        var selected = {};
        (Array.isArray(paths) ? paths : []).forEach(function(path){ selected[String(path || '')] = true; });
        $('#metis-popup-page-checklist input[type="checkbox"]').each(function(){
            this.checked = !!selected[String(this.value || '')];
        });
    }

    function renderPageChecklist(filter) {
        var query = String(filter || '').trim().toLowerCase();
        var html = '';
        pageOptions.forEach(function(page){
            var label = String(page.label || '');
            var path = String(page.path || '/');
            var haystack = (label + ' ' + path).toLowerCase();
            if (query && haystack.indexOf(query) === -1) return;
            html += '<label class="metis-campaign-check">'
                + '<input type="checkbox" value="' + esc(path) + '">'
                + '<span><strong>' + esc(label) + '</strong><span>' + esc(path) + '</span></span>'
                + '</label>';
        });
        if (!html) {
            html = '<div class="metis-campaign-inline-note">No pages match that filter.</div>';
        }
        $('#metis-popup-page-checklist').html(html);
    }

    function updateTriggerFields() {
        var trigger = String($('#metis-popup-trigger').val() || 'click');
        var clickMode = String($('#metis-popup-click-mode').val() || 'page_button');
        var layoutMode = String($('#metis-popup-launcher-layout').val() || 'full');
        $('.metis-popup-delay-row').toggleClass('metis-campaign-row-hidden', !(trigger === 'delay' || trigger === 'load'));
        $('.metis-popup-scroll-row').toggleClass('metis-campaign-row-hidden', trigger !== 'scroll');
        $('.metis-popup-click-row, .metis-popup-button-note').toggleClass('metis-campaign-row-hidden', trigger !== 'click');
        $('.metis-popup-launcher-row').toggleClass('metis-campaign-row-hidden', !(trigger === 'click' && clickMode === 'floating_button'));
        $('.metis-popup-launcher-shape-row').toggleClass('metis-campaign-row-hidden', !(trigger === 'click' && clickMode === 'floating_button' && layoutMode !== 'icon'));
    }

    function updateContentFields() {
        var mode = currentContentMode();
        $('.metis-popup-form-row').toggleClass('metis-campaign-row-hidden', mode !== 'form');
        $('.metis-popup-donation-row').toggleClass('metis-campaign-row-hidden', mode !== 'donation_form');
        $('.metis-popup-message-actions').toggleClass('metis-campaign-row-hidden', mode !== 'message');
    }

    function updateAudienceFields() {
        var mode = String($('#metis-popup-audience-mode').val() || 'site_wide');
        $('#metis-popup-page-targets').toggleClass('metis-campaign-row-hidden', mode !== 'selected_pages');
    }

    function buildDisplayRules() {
        var audienceMode = String($('#metis-popup-audience-mode').val() || 'site_wide');
        if (audienceMode === 'site_wide') {
            return { site_wide: true, paths: [], slugs: [], content_types: [] };
        }
        if (audienceMode === 'all_pages') {
            return { site_wide: false, paths: [], slugs: [], content_types: ['page'] };
        }
        if (audienceMode === 'all_posts') {
            return { site_wide: false, paths: [], slugs: [], content_types: ['post'] };
        }
        return { site_wide: false, paths: selectedPagePaths(), slugs: [], content_types: [] };
    }

    function buildTriggerConfig() {
        return {
            frequency: String($('#metis-popup-frequency').val() || 'session'),
            click_mode: String($('#metis-popup-click-mode').val() || 'page_button'),
            launcher_label: String($('#metis-popup-launcher-label').val() || 'Open popup').trim() || 'Open popup',
            launcher_position: String($('#metis-popup-launcher-position').val() || 'bottom_right'),
            launcher_color_key: String($('#metis-popup-launcher-color').val() || 'metis_primary'),
            launcher_text_color_key: String($('#metis-popup-launcher-text-color').val() || ''),
            launcher_layout: String($('#metis-popup-launcher-layout').val() || 'full'),
            launcher_style: String($('#metis-popup-launcher-style').val() || 'solid'),
            launcher_shape: String($('#metis-popup-launcher-shape').val() || 'pill'),
            launcher_border_width: String($('#metis-popup-launcher-border-width').val() || 'thin'),
            launcher_border_effect: String($('#metis-popup-launcher-border-effect').val() || 'single'),
            launcher_icon: String($('#metis-popup-launcher-icon').val() || '').trim(),
            delay_ms: Math.max(0, Math.round(parseFloat($('#metis-popup-delay-seconds').val() || '0') * 1000)),
            scroll_percent: Math.max(1, Math.min(100, parseInt($('#metis-popup-scroll').val() || '50', 10) || 50))
        };
    }

    function buildLayoutJson() {
        var mode = currentContentMode();
        var blocks = [];
        var headline = String($('#metis-popup-headline').val() || '').trim();
        var message = String($('#metis-popup-message').val() || '').trim();
        if (headline) {
            blocks.push({ type: 'heading', data: { content: headline, level: 'h2' }, style: {} });
        }
        if (message) {
            blocks.push({ type: 'text', data: { content: textToParagraphHtml(message), tag: 'div' }, style: {} });
        }
        if (mode === 'message') {
            var buttonLabel = String($('#metis-popup-button-label').val() || '').trim();
            var buttonUrl = String($('#metis-popup-button-url').val() || '').trim();
            if (buttonLabel && buttonUrl) {
                blocks.push({ type: 'button', data: { label: buttonLabel, url: buttonUrl, action_type: 'url' }, style: {} });
            }
        } else if (mode === 'form') {
            blocks.push({
                type: 'form',
                data: {
                    form_id: String($('#metis-popup-form-id').val() || ''),
                    submit_label: String($('#metis-popup-submit-label').val() || 'Submit').trim() || 'Submit'
                },
                style: {}
            });
        } else if (mode === 'donation_form') {
            blocks.push({
                type: 'donation_form_block',
                data: {
                    campaign_id: String($('#metis-popup-campaign-id').val() || ''),
                    preset_amounts: [25, 50, 100],
                    allow_custom_amount: true,
                    mode: 'both',
                    show_name: true,
                    show_email: true,
                    show_phone: false
                },
                style: {}
            });
        }
        return JSON.stringify(normalizePopupLayoutForSave({ version: 1, blocks: blocks }));
    }

    function inferContentState(layoutRaw) {
        var layout = parseJson(layoutRaw, {});
        var blocks = Array.isArray(layout.blocks)
            ? layout.blocks
            : (layout.sections && layout.sections[0] && Array.isArray(layout.sections[0].blocks) ? layout.sections[0].blocks : []);
        var state = {
            mode: 'message',
            headline: '',
            message: '',
            buttonLabel: '',
            buttonUrl: '',
            formId: '',
            submitLabel: 'Submit',
            campaignId: ''
        };
        blocks.forEach(function(block){
            if (!block || typeof block !== 'object') return;
            var type = String(block.type || '').toLowerCase();
            var data = block.data && typeof block.data === 'object' ? block.data : {};
            if (!state.headline && type === 'heading') {
                state.headline = plainTextFromHtml(data.content || '');
            } else if (!state.message && type === 'text') {
                state.message = plainTextFromHtml(data.content || '');
            } else if (type === 'button') {
                state.mode = 'message';
                state.buttonLabel = String(data.label || '');
                state.buttonUrl = String(data.url || '');
            } else if (type === 'form' || type === 'form_block') {
                state.mode = 'form';
                state.formId = String(data.form_id || '');
                state.submitLabel = String(data.submit_label || 'Submit');
            } else if (type === 'donation_form' || type === 'donation_form_block') {
                state.mode = 'donation_form';
                state.campaignId = String(data.campaign_id || '');
            }
        });
        return state;
    }

    function openPopupEditor(data) {
        data = data || {};
        var triggerConfig = parseJson(data.trigger_config_json || '{}', {});
        var displayRules = parseJson(data.display_rules_json || '{}', {});
        var contentState = inferContentState(data.layout_json || '{}');
        var audienceMode = 'site_wide';
        if (displayRules && !displayRules.site_wide) {
            var contentTypes = Array.isArray(displayRules.content_types) ? displayRules.content_types : [];
            if (contentTypes.length === 1 && contentTypes[0] === 'page') audienceMode = 'all_pages';
            else if (contentTypes.length === 1 && contentTypes[0] === 'post') audienceMode = 'all_posts';
            else audienceMode = 'selected_pages';
        }

        $('#metis-popup-modal-title').text(data.id ? 'Edit Popup' : 'New Popup');
        $('#metis-popup-id').val(data.id || '');
        $('#metis-popup-name').val(data.name || '');
        $('#metis-popup-status').val(data.status || 'draft');
        $('#metis-popup-trigger').val(data.trigger_type || data.trigger || 'click');
        $('#metis-popup-frequency').val(triggerConfig.frequency || 'session');
        $('#metis-popup-click-mode').val(triggerConfig.click_mode || 'page_button');
        $('#metis-popup-launcher-label').val(triggerConfig.launcher_label || 'Open popup');
        $('#metis-popup-launcher-position').val(triggerConfig.launcher_position || 'bottom_right');
        $('#metis-popup-launcher-color').val(triggerConfig.launcher_color_key || 'metis_primary');
        $('#metis-popup-launcher-text-color').val(triggerConfig.launcher_text_color_key || '');
        $('#metis-popup-launcher-layout').val(triggerConfig.launcher_layout || 'full');
        $('#metis-popup-launcher-style').val(triggerConfig.launcher_style || 'solid');
        $('#metis-popup-launcher-shape').val(triggerConfig.launcher_shape || 'pill');
        $('#metis-popup-launcher-border-width').val(triggerConfig.launcher_border_width || 'thin');
        $('#metis-popup-launcher-border-effect').val(triggerConfig.launcher_border_effect || 'single');
        $('#metis-popup-launcher-icon').val(triggerConfig.launcher_icon || '');
        $('#metis-popup-delay-seconds').val(((Number(triggerConfig.delay_ms || 1500) / 1000) || 1.5).toString());
        $('#metis-popup-scroll').val(triggerConfig.scroll_percent || 50);
        $('input[name="metis-popup-content-mode"][value="' + contentState.mode + '"]').prop('checked', true);
        $('#metis-popup-headline').val(contentState.headline || '');
        $('#metis-popup-message').val(contentState.message || '');
        $('#metis-popup-button-label').val(contentState.buttonLabel || '');
        $('#metis-popup-button-url').val(contentState.buttonUrl || '');
        $('#metis-popup-form-id').val(contentState.formId || '');
        $('#metis-popup-submit-label').val(contentState.submitLabel || 'Submit');
        $('#metis-popup-campaign-id').val(contentState.campaignId || '');
        $('#metis-popup-audience-mode').val(audienceMode);
        renderPageChecklist($('#metis-popup-page-search').val());
        setSelectedPagePaths(Array.isArray(displayRules.paths) ? displayRules.paths : []);
        updateTriggerFields();
        updateContentFields();
        updateAudienceFields();
        updateLauncherIconPreview();
        if (window.Metis && Metis.ui && Metis.ui.select) {
            Metis.ui.select.refresh(document.getElementById('metis-popup-modal'));
        }
        if (window.Metis && Metis.ui && Metis.ui.modal) {
            Metis.ui.modal.form('metis-popup-modal');
        }
    }

    var popupTriggerLabels = <?php echo metis_json_encode( $trigger_types, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>;
    var popupEditIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    var popupDeleteIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';

    function popupTriggerLabel(trigger) {
        var key = String(trigger || 'click');
        return String(popupTriggerLabels[key] || key.replace(/_/g, ' '));
    }

    function popupDataAttrs(popup) {
        return [
            'data-id="' + esc(String(popup.id || '')) + '"',
            'data-name="' + esc(String(popup.name || '')) + '"',
            'data-trigger="' + esc(String(popup.trigger_type || 'click')) + '"',
            'data-trigger-config="' + esc(String(popup.trigger_config_json || '{}')) + '"',
            'data-display-rules="' + esc(String(popup.display_rules_json || '{}')) + '"',
            'data-layout="' + esc(String(popup.layout_json || '{}')) + '"',
            'data-status="' + esc(String(popup.status || 'draft')) + '"'
        ].join(' ');
    }

    function renderPopupRows(popups) {
        return (Array.isArray(popups) ? popups : []).map(function(popup) {
            var status = String(popup.status || 'draft');
            return '<tr class="metis-premium-row">'
                + '<td class="metis-premium-cell"><strong>' + escapeHtml(String(popup.name || '')) + '</strong></td>'
                + '<td class="metis-premium-cell metis-table-meta-cell">' + escapeHtml(popupTriggerLabel(popup.trigger_type || 'click')) + '</td>'
                + '<td class="metis-premium-cell"><span class="metis-status metis-status-' + esc(status) + '">' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</span></td>'
                + '<td class="metis-premium-cell metis-col-right"><div class="metis-table-actions">'
                + '<button class="metis-action-btn metis-edit-popup" ' + popupDataAttrs(popup) + ' title="Edit">' + popupEditIcon + '</button>'
                + '<button class="metis-action-btn metis-action-btn-danger metis-delete-popup" data-id="' + esc(String(popup.id || '')) + '" data-name="' + esc(String(popup.name || '')) + '" title="Delete">' + popupDeleteIcon + '</button>'
                + '</div></td></tr>';
        }).join('');
    }

    function renderPopupList(popups) {
        var items = Array.isArray(popups) ? popups : [];
        $('#metis-popup-subtitle').text(items.length + ' popup' + (items.length === 1 ? '' : 's') + ' ready for guided website triggers.');
        if (!items.length) {
            $('#metis-popup-list-region').html(
                '<div class="metis-empty-state">'
                + '<div class="metis-empty-state-icon">&#9741;</div>'
                + '<h2>No popups yet</h2>'
                + '<p>Create popups that open from buttons, time delay, scroll depth, or exit intent.</p>'
                + '<button class="metis-btn metis-btn-primary" id="metis-create-popup-btn-empty">New Popup</button>'
                + '</div>'
            );
            return;
        }
        $('#metis-popup-list-region').html(
            '<table class="metis-premium-table metis-popup-table">'
            + '<thead><tr class="metis-premium-row metis-premium-header">'
            + '<th class="metis-premium-cell" scope="col">Popup</th>'
            + '<th class="metis-premium-cell" scope="col">Trigger</th>'
            + '<th class="metis-premium-cell" scope="col">Status</th>'
            + '<th class="metis-premium-cell metis-col-right" scope="col">Actions</th>'
            + '</tr></thead>'
            + '<tbody>' + renderPopupRows(items) + '</tbody></table>'
        );
    }

    $(document).on('click', '#metis-create-popup-btn, #metis-create-popup-btn-empty', function() {
        openPopupEditor({});
    });

    $(document).on('click', '.metis-edit-popup', function() {
        var $btn = $(this);
        openPopupEditor({
            id: Number($btn.data('id') || 0),
            name: String($btn.data('name') || ''),
            trigger_type: String($btn.data('trigger') || 'click'),
            trigger_config_json: $btn.attr('data-trigger-config') || '{}',
            display_rules_json: $btn.attr('data-display-rules') || '{}',
            layout_json: $btn.attr('data-layout') || '{}',
            status: String($btn.data('status') || 'draft')
        });
    });

    $(document).on('change', '#metis-popup-trigger, #metis-popup-click-mode, #metis-popup-launcher-layout', updateTriggerFields);
    $(document).on('change', 'input[name="metis-popup-content-mode"]', updateContentFields);
    $(document).on('change', '#metis-popup-audience-mode', updateAudienceFields);
    $(document).on('change', '#metis-popup-launcher-icon', updateLauncherIconPreview);
    $(document).on('input', '#metis-popup-page-search', function() {
        var selected = selectedPagePaths();
        renderPageChecklist($(this).val());
        setSelectedPagePaths(selected);
    });
    $(document).on('click', '#metis-popup-save-btn', function() {
        var $saveButton = $(this);
        var id = Number($('#metis-popup-id').val() || 0);
        var name = String($('#metis-popup-name').val() || '').trim();
        var contentMode = currentContentMode();
        var launcherLayout = String($('#metis-popup-launcher-layout').val() || 'full');
        var launcherIcon = String($('#metis-popup-launcher-icon').val() || '').trim();
        if (!name) {
            Metis.ui.toast.error('Add an internal name for this popup.');
            return;
        }
        if (String($('#metis-popup-trigger').val() || 'click') === 'click' && String($('#metis-popup-click-mode').val() || 'page_button') === 'floating_button' && launcherLayout === 'icon' && !launcherIcon) {
            Metis.ui.toast.error('Choose a repo icon for an icon-only floating button.');
            return;
        }
        if (contentMode === 'message' && !String($('#metis-popup-message').val() || '').trim() && !String($('#metis-popup-headline').val() || '').trim()) {
            Metis.ui.toast.error('Add a headline or supporting text for the popup.');
            return;
        }
        if (contentMode === 'form' && !String($('#metis-popup-form-id').val() || '').trim()) {
            Metis.ui.toast.error('Choose a published form to embed.');
            return;
        }
        if (contentMode === 'donation_form' && !String($('#metis-popup-campaign-id').val() || '').trim()) {
            Metis.ui.toast.error('Choose a donation campaign.');
            return;
        }
        if (String($('#metis-popup-audience-mode').val() || 'site_wide') === 'selected_pages' && !selectedPagePaths().length) {
            Metis.ui.toast.error('Choose at least one page for this popup.');
            return;
        }

        var payload = {
            action: 'metis_website_popup_save',
            nonce: nonceFor('metis_website_popup_save'),
            metis_action_nonce: nonceFor('metis_website_popup_save'),
            name: name,
            trigger_type: String($('#metis-popup-trigger').val() || 'click'),
            status: String($('#metis-popup-status').val() || 'draft'),
            trigger_config_json: JSON.stringify(buildTriggerConfig()),
            display_rules_json: JSON.stringify(buildDisplayRules()),
            layout_json: buildLayoutJson()
        };
        if (id > 0) payload.id = id;

        $saveButton.prop('disabled', true).text('Saving...');
        $.post(metisWebsiteAjax.ajax_url, payload).done(function(response) {
            if (response && response.success) {
                Metis.ui.toast.success('Popup saved.');
                renderPopupList(response.data && response.data.popups ? response.data.popups : []);
                Metis.modal.close('metis-popup-modal');
                return;
            }
            Metis.ui.toast.error((response && response.data && response.data.message) || 'Save failed.');
        }).fail(function(xhr) {
            Metis.ui.toast.error(extractErrorMessage(xhr, 'Request failed.'));
        }).always(function() {
            $saveButton.prop('disabled', false).text('Save Popup');
        });
    });

    $(document).on('click', '.metis-delete-popup', function() {
        var id = Number($(this).data('id') || 0);
        var name = String($(this).data('name') || 'this popup');
        if (id < 1) return;
        Metis.ui.confirm.open({ message: 'Delete popup "' + name + '"?', confirmLabel: 'Delete', tone: 'danger' }).then(function(confirmed) {
            if (!confirmed) return;
            $.post(metisWebsiteAjax.ajax_url, {
                action: 'metis_website_popup_delete',
                nonce: nonceFor('metis_website_popup_delete'),
                metis_action_nonce: nonceFor('metis_website_popup_delete'),
                id: id
            }).done(function(response) {
                if (response && response.success) {
                    Metis.ui.toast.success('Popup deleted.');
                    renderPopupList(response.data && response.data.popups ? response.data.popups : []);
                    Metis.modal.close('metis-popup-modal');
                    return;
                }
                Metis.ui.toast.error((response && response.data && response.data.message) || 'Delete failed.');
            }).fail(function(xhr) {
                Metis.ui.toast.error(extractErrorMessage(xhr, 'Request failed.'));
            });
        });
    });

    renderPageChecklist('');
    updateTriggerFields();
    updateContentFields();
    updateAudienceFields();
    updateLauncherIconPreview();
}
})();
</script>
