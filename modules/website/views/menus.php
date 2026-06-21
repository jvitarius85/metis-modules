<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'menus' ) ) {
    return;
}

use Metis\Modules\Website\Services\MenuService;
use Metis\Modules\Website\Services\PageService;

$menus = MenuService::getAll();

$locations = [
    ''         => '— None —',
    'primary'  => 'Primary Navigation',
    'header'   => 'Header',
    'footer'   => 'Footer',
    'sidebar'  => 'Sidebar',
];

$theme_defaults = [
    'metis_primary' => '#485bc7',
    'metis_accent' => '#ff7542',
    'metis_text' => '#1f2330',
    'metis_surface' => '#ffffff',
];
$theme_saved = class_exists( 'Core_Settings_Service' ) ? \Core_Settings_Service::get( 'theme_colors', [] ) : [];
if ( ! is_array( $theme_saved ) ) {
    $theme_saved = [];
}
$menu_button_colors = [
    'metis_primary' => metis_hex_color_clean( (string) ( $theme_saved['metis_primary'] ?? $theme_defaults['metis_primary'] ) ) ?: $theme_defaults['metis_primary'],
    'metis_accent' => metis_hex_color_clean( (string) ( $theme_saved['metis_accent'] ?? $theme_defaults['metis_accent'] ) ) ?: $theme_defaults['metis_accent'],
    'metis_text' => metis_hex_color_clean( (string) ( $theme_saved['metis_text'] ?? $theme_defaults['metis_text'] ) ) ?: $theme_defaults['metis_text'],
    'metis_surface' => metis_hex_color_clean( (string) ( $theme_saved['metis_surface'] ?? $theme_defaults['metis_surface'] ) ) ?: $theme_defaults['metis_surface'],
];
$menu_button_color_labels = [
    'metis_primary' => 'Primary',
    'metis_accent' => 'Accent',
    'metis_text' => 'Text',
    'metis_surface' => 'Surface',
];
$published_pages = PageService::getAll(
    [
        'status' => 'published',
        'fetch_all' => true,
    ]
);
$published_page_options = [];
foreach ( $published_pages as $page ) {
    if ( ! $page instanceof \Metis\Modules\Website\Entities\Page ) {
        continue;
    }
    $page_id = (int) ( $page->id ?? 0 );
    if ( $page_id < 1 ) {
        continue;
    }
    $page_path = method_exists( PageService::class, 'publishedPathForPage' )
        ? (string) PageService::publishedPathForPage( $page )
        : '';
    if ( $page_path === '' ) {
        continue;
    }
    $published_page_options[] = [
        'id' => $page_id,
        'title' => (string) ( $page->title ?? '' ),
        'path' => $page_path,
    ];
}
?>
<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Menus</h1>
        <p class="metis-subtitle"><?php echo count( $menus ); ?> menu<?php echo count( $menus ) !== 1 ? 's' : ''; ?> available for website navigation.</p>
    </div>
    <div class="metis-page-header-right">
        <button type="button" class="metis-btn metis-btn-primary" id="metis-create-menu-btn">
            <svg style="width:14px;height:14px;margin-right:6px;vertical-align:-2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Menu
        </button>
    </div>
</div>

<div class="metis-table-wrap">
    <?php if ( empty( $menus ) ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#9776;</div>
            <h2>No menus yet</h2>
            <p>Create a navigation menu to assign to your header, footer, or blocks.</p>
            <button type="button" class="metis-btn metis-btn-primary" id="metis-create-menu-btn-empty">New Menu</button>
        </div>
    <?php else : ?>
        <table class="metis-table metis-menus-table">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Location</th>
                    <th scope="col">Items</th>
                    <th scope="col">Status</th>
                    <th scope="col" style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $menus as $menu ) :
                    $items = MenuService::getItems( $menu );
                ?>
                    <tr>
                        <td><strong><?php echo metis_escape_html( $menu['name'] ?? '' ); ?></strong></td>
                        <td><?php echo metis_escape_html( $locations[ $menu['location'] ?? '' ] ?? ( $menu['location'] ?? '—' ) ); ?></td>
                        <td class="metis-table-meta-cell"><?php echo count( $items ); ?> item<?php echo count( $items ) !== 1 ? 's' : ''; ?></td>
                        <td><span class="metis-status metis-status-<?php echo metis_escape_attr( $menu['status'] ?? 'active' ); ?>"><?php echo metis_escape_html( ucfirst( $menu['status'] ?? 'active' ) ); ?></span></td>
                        <td style="text-align:right;">
                            <div class="metis-table-actions">
                                <button type="button" class="metis-action-btn metis-edit-menu"
                                    data-id="<?php echo metis_escape_attr( $menu['id'] ?? '' ); ?>"
                                    data-name="<?php echo metis_escape_attr( $menu['name'] ?? '' ); ?>"
                                    data-location="<?php echo metis_escape_attr( $menu['location'] ?? '' ); ?>"
                                    data-items="<?php echo metis_escape_attr( $menu['items_json'] ?? '[]' ); ?>"
                                    title="Edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button type="button" class="metis-action-btn metis-action-btn-danger metis-delete-menu" data-id="<?php echo metis_escape_attr( $menu['id'] ?? '' ); ?>" title="Delete">
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

<!-- Menu Editor Modal -->
<div id="metis-menu-modal" class="metis-modal-backdrop" aria-hidden="true" hidden>
    <div class="metis-modal" style="max-width:640px;width:95%;">
        <div class="metis-modal-header">
            <h2 class="metis-modal-title" id="metis-menu-modal-title">New Menu</h2>
            <button type="button" class="metis-modal-close" data-modal-close="metis-menu-modal" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body" style="padding:20px;">

            <div class="metis-field" style="margin-bottom:14px;">
                <label class="metis-label" for="metis-menu-name">Menu Name <span style="color:#dc3545;">*</span></label>
                <input type="text" id="metis-menu-name" class="metis-input" placeholder="e.g. Main Navigation">
            </div>

            <div class="metis-field" style="margin-bottom:20px;">
                <label class="metis-label" for="metis-menu-location">Location</label>
                <select id="metis-menu-location" class="metis-input" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input">
                    <?php foreach ( $locations as $val => $label ) : ?>
                        <option value="<?php echo metis_escape_attr( $val ); ?>"><?php echo metis_escape_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:8px;font-weight:600;font-size:13px;color:var(--metis-text,#1a1f2b);">Menu Items</div>

            <div id="metis-menu-items-list" style="min-height:60px;border:1px solid var(--metis-border,#e2e6ea);border-radius:6px;padding:4px;margin-bottom:12px;background:var(--metis-surface-alt,#f7f8fa);">
                <div id="metis-menu-items-empty" style="text-align:center;padding:20px;color:var(--metis-text-muted,#aaa);font-size:13px;">No items yet — add one below.</div>
            </div>

            <div style="display:flex;gap:8px;align-items:flex-end;padding:12px;border:1px solid var(--metis-border,#e2e6ea);border-radius:6px;background:var(--metis-surface,#fff);flex-wrap:wrap;">
                <div class="metis-field" style="flex:1;min-width:160px;margin:0;">
                    <label class="metis-label" for="metis-menu-item-label" style="font-size:11px;">Label</label>
                    <input type="text" id="metis-menu-item-label" class="metis-input metis-input-sm" placeholder="Link label">
                </div>
                <div class="metis-field" style="margin:0;">
                    <label class="metis-label" for="metis-menu-item-source" style="font-size:11px;">Source</label>
                    <select id="metis-menu-item-source" class="metis-input metis-input-sm" style="width:150px;" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input metis-input-sm">
                        <option value="page">Published page</option>
                        <option value="custom">Custom URL</option>
                    </select>
                </div>
                <div class="metis-field" id="metis-menu-item-page-field" style="flex:2;min-width:240px;margin:0;">
                    <label class="metis-label" for="metis-menu-item-page" style="font-size:11px;">Published page</label>
                    <select id="metis-menu-item-page" class="metis-input metis-input-sm" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input metis-input-sm">
                        <option value="">Select published page…</option>
                        <?php foreach ( $published_page_options as $page_option ) : ?>
                            <option value="<?php echo metis_escape_attr( (string) ( $page_option['id'] ?? '' ) ); ?>"
                                data-title="<?php echo metis_escape_attr( (string) ( $page_option['title'] ?? '' ) ); ?>"
                                data-path="<?php echo metis_escape_attr( (string) ( $page_option['path'] ?? '' ) ); ?>">
                                <?php echo metis_escape_html( (string) ( $page_option['title'] ?? '' ) . ' (' . (string) ( $page_option['path'] ?? '' ) . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="metis-menu-item-page-path" style="margin-top:6px;font-size:11px;color:var(--metis-text-muted,#6b7280);">Select a published page to use its live path.</div>
                </div>
                <div class="metis-field" id="metis-menu-item-url-field" style="flex:2;min-width:200px;margin:0;display:none;">
                    <label class="metis-label" for="metis-menu-item-url" style="font-size:11px;">URL</label>
                    <input type="text" id="metis-menu-item-url" class="metis-input metis-input-sm" placeholder="https:// or /page-slug">
                </div>
                <div class="metis-field" style="margin:0;">
                    <label class="metis-label" for="metis-menu-item-target" style="font-size:11px;">Opens in</label>
                    <select id="metis-menu-item-target" class="metis-input metis-input-sm" style="width:110px;" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input metis-input-sm">
                        <option value="">Same tab</option>
                        <option value="_blank">New tab</option>
                    </select>
                </div>
                <div class="metis-field" style="margin:0;display:flex;align-items:center;gap:6px;">
                    <label class="metis-label" for="metis-menu-item-as-button" style="font-size:11px;margin:0;">Button</label>
                    <input type="checkbox" id="metis-menu-item-as-button" value="1">
                </div>
                <div class="metis-field" style="margin:0;">
                    <label class="metis-label" for="metis-menu-item-button-color" style="font-size:11px;">Button Color</label>
                    <select id="metis-menu-item-button-color" class="metis-input metis-input-sm" style="width:150px;" data-metis-ui-select="1" data-metis-select-trigger-class="metis-input metis-input-sm" data-metis-select-variant="theme-binding">
                        <?php foreach ( $menu_button_colors as $color_key => $color_hex ) : ?>
                            <option value="<?php echo metis_escape_attr( $color_key ); ?>"
                                data-metis-select-color="<?php echo metis_escape_attr( $color_hex ); ?>">
                                <?php echo metis_escape_html( (string) ( $menu_button_color_labels[ $color_key ] ?? $color_key ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" id="metis-menu-add-item-btn" style="flex-shrink:0;align-self:flex-end;">+ Add Item</button>
            </div>

            <input type="hidden" id="metis-menu-id" value="">

        </div>
        <div class="metis-modal-footer">
            <button type="button" class="metis-btn metis-btn-ghost" id="metis-menu-cancel-btn" data-modal-close="metis-menu-modal">Cancel</button>
            <button type="button" class="metis-btn metis-btn-primary" id="metis-menu-save-btn">Save Menu</button>
        </div>
    </div>
</div>

<script>
(function bootMenuEditor() {
'use strict';

if (!window.jQuery) {
    window.setTimeout(bootMenuEditor, 50);
    return;
}

var $ = window.jQuery;
var publishedPages = <?php
if ( function_exists( 'metis_json_encode' ) ) {
    echo metis_json_encode( $published_page_options, JSON_UNESCAPED_UNICODE );
} else {
    $encoded = json_encode( $published_page_options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    echo is_string( $encoded ) ? $encoded : '[]';
}
?>;
var menuButtonPalette = <?php
if ( function_exists( 'metis_json_encode' ) ) {
    echo metis_json_encode( $menu_button_colors, JSON_UNESCAPED_UNICODE );
} else {
    $encoded = json_encode( $menu_button_colors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    echo is_string( $encoded ) ? $encoded : '{}';
}
?>;

var menuItems = [];
var editingMenuId = null;
var editingItemId = null;
var itemLabelTouched = false;
var locationLabels = {};

function refreshMenuSelects(scope) {
    if (!(window.Metis && Metis.ui && Metis.ui.select && typeof Metis.ui.select.refresh === 'function')) {
        return;
    }
    var $scope = scope ? $(scope) : $(document);
    var seen = [];
    $scope.find('select[data-metis-ui-select="1"]').addBack('select[data-metis-ui-select="1"]').each(function() {
        var select = this;
        if (!(select instanceof HTMLSelectElement)) return;
        if (seen.indexOf(select) !== -1) return;
        seen.push(select);

        if (select.id === 'metis-menu-item-button-color') {
            select.dataset.metisSelectVariant = 'theme-binding';
            $(select).find('option').each(function() {
                var key = String(this.value || '').trim();
                var color = String(this.dataset.metisSelectColor || menuButtonPalette[key] || '#ffffff');
                this.dataset.metisSelectColor = color;
            });
        } else {
            delete select.dataset.metisSelectVariant;
            $(select).find('option').each(function() {
                delete this.dataset.metisSelectColor;
                delete this.dataset.metisSelectFontFamily;
            });
        }

        Metis.ui.select.refresh(select);
    });
}

$('#metis-menu-location option').each(function() {
    locationLabels[String($(this).val() || '')] = String($(this).text() || '');
});

function nonceFor(action) {
    var cfg = window.metisWebsiteAjax || {};
    var map = cfg && cfg.action_nonces && typeof cfg.action_nonces === 'object' ? cfg.action_nonces : {};
    if (action && map[action]) {
        return String(map[action]);
    }
    return String(cfg.nonce || '');
}

function parseMenuItems(raw) {
    var source = [];
    if (Array.isArray(raw)) {
        source = raw;
    } else if (!raw || typeof raw !== 'object') {
        var value = String(raw || '').trim();
        if (value) {
            try {
                var decoded = JSON.parse(value);
                source = Array.isArray(decoded) ? decoded : [];
            } catch (e) {
                source = [];
            }
        }
    }

    var used = {};
    var next = 1;
    var normalized = source.map(function(item) {
        var obj = item && typeof item === 'object' ? item : {};
        var candidateId = String(obj.id || '').trim();
        if (!candidateId || used[candidateId]) {
            candidateId = 'mitem_' + (next++);
        }
        used[candidateId] = true;
        return {
            id: candidateId,
            parent_id: String(obj.parent_id || '').trim(),
            label: String(obj.label || '').trim(),
            url: String(obj.url || '').trim(),
            target: String(obj.target || ''),
            external: String(obj.target || '') === '_blank' || !!obj.external,
            as_button: !!obj.as_button,
            button_color_key: String(obj.button_color_key || 'metis_primary').trim() || 'metis_primary',
            link_type: String(obj.link_type || (obj.page_id ? 'page' : 'custom')).trim() === 'page' ? 'page' : 'custom',
            page_id: parseInt(obj.page_id, 10) > 0 ? parseInt(obj.page_id, 10) : 0
        };
    }).filter(function(item) {
        return item.label !== '' && item.url !== '';
    });

    var valid = {};
    normalized.forEach(function(item) { valid[item.id] = true; });
    normalized.forEach(function(item) {
        if (!item.parent_id || !valid[item.parent_id] || item.parent_id === item.id) {
            item.parent_id = '';
        }
    });
    return normalized;
}

function publishedPageById(id) {
    var target = String(id || '');
    for (var i = 0; i < publishedPages.length; i += 1) {
        var page = publishedPages[i] && typeof publishedPages[i] === 'object' ? publishedPages[i] : {};
        if (String(page.id || '') === target) {
            return {
                id: parseInt(page.id, 10) || 0,
                title: String(page.title || '').trim(),
                path: String(page.path || '').trim()
            };
        }
    }
    return null;
}

function updatePublishedPagePathHint() {
    var source = String($('#metis-menu-item-source').val() || 'page');
    var $hint = $('#metis-menu-item-page-path');
    if (!$hint.length) {
        return;
    }
    if (source !== 'page') {
        $hint.text('Choose a custom URL for this item.');
        return;
    }
    var page = publishedPageById($('#metis-menu-item-page').val());
    $hint.text(page && page.path ? ('Live path: ' + page.path) : 'Select a published page to use its live path.');
}

function syncMenuItemSourceControls() {
    var source = String($('#metis-menu-item-source').val() || 'page');
    var isPage = source === 'page';
    $('#metis-menu-item-page-field').toggle(isPage);
    $('#metis-menu-item-url-field').toggle(!isPage);
    updatePublishedPagePathHint();
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderMenusTable(menus) {
    var list = Array.isArray(menus) ? menus : [];
    var $wrap = $('.metis-table-wrap').first();
    $('.metis-page-header .metis-subtitle').first().text(list.length + ' menu' + (list.length === 1 ? '' : 's') + ' available for website navigation.');
    $wrap.empty();

    if (!list.length) {
        $wrap.append(
            '<div class="metis-empty-state">' +
                '<div class="metis-empty-state-icon">&#9776;</div>' +
                '<h2>No menus yet</h2>' +
                '<p>Create a navigation menu to assign to your header, footer, or blocks.</p>' +
                '<button type="button" class="metis-btn metis-btn-primary" id="metis-create-menu-btn-empty">New Menu</button>' +
            '</div>'
        );
        return;
    }

    var rows = list.map(function(menu) {
        var itemsRaw = String(menu && menu.items_json ? menu.items_json : '[]');
        var itemCount = parseMenuItems(itemsRaw).length;
        var location = String(menu && menu.location ? menu.location : '');
        var locationLabel = locationLabels[location] || location || '—';
        var status = String(menu && menu.status ? menu.status : 'active');
        return '<tr>'
            + '<td><strong>' + escapeHtml(String(menu && menu.name ? menu.name : '')) + '</strong></td>'
            + '<td>' + escapeHtml(locationLabel) + '</td>'
            + '<td class="metis-table-meta-cell">' + escapeHtml(String(itemCount)) + ' item' + (itemCount === 1 ? '' : 's') + '</td>'
            + '<td><span class="metis-status metis-status-' + escapeHtml(status) + '">' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</span></td>'
            + '<td style="text-align:right;"><div class="metis-table-actions">'
            + '<button type="button" class="metis-action-btn metis-edit-menu" data-id="' + escapeHtml(String(menu && menu.id ? menu.id : '')) + '" data-name="' + escapeHtml(String(menu && menu.name ? menu.name : '')) + '" data-location="' + escapeHtml(location) + '" data-items="' + escapeHtml(itemsRaw) + '" title="Edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>'
            + '<button type="button" class="metis-action-btn metis-action-btn-danger metis-delete-menu" data-id="' + escapeHtml(String(menu && menu.id ? menu.id : '')) + '" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>'
            + '</div></td>'
            + '</tr>';
    }).join('');

    $wrap.append(
        '<table class="metis-table metis-menus-table">' +
            '<thead><tr>' +
                '<th scope="col">Name</th>' +
                '<th scope="col">Location</th>' +
                '<th scope="col">Items</th>' +
                '<th scope="col">Status</th>' +
                '<th scope="col" style="text-align:right;">Actions</th>' +
            '</tr></thead><tbody>' +
            rows +
        '</tbody></table>'
    );
}

function itemById(id) {
    for (var i = 0; i < menuItems.length; i += 1) {
        if (String(menuItems[i].id || '') === String(id || '')) {
            return menuItems[i];
        }
    }
    return null;
}

function isDescendant(candidateId, ancestorId) {
    var current = itemById(candidateId);
    var guard = 0;
    while (current && current.parent_id && guard < 60) {
        if (String(current.parent_id) === String(ancestorId)) {
            return true;
        }
        current = itemById(current.parent_id);
        guard += 1;
    }
    return false;
}

function flattenForRender(parentId, depth, out) {
    menuItems.forEach(function(item) {
        if (String(item.parent_id || '') !== String(parentId || '')) {
            return;
        }
        out.push({ item: item, depth: depth });
        flattenForRender(item.id, depth + 1, out);
    });
}

function subtreeEndIndex(itemId) {
    var index = -1;
    for (var i = 0; i < menuItems.length; i += 1) {
        if (String(menuItems[i].id || '') === String(itemId || '')) {
            index = i;
            break;
        }
    }
    if (index < 0) return -1;
    var end = index;
    for (var j = index + 1; j < menuItems.length; j += 1) {
        if (isDescendant(menuItems[j].id, itemId)) {
            end = j;
        }
    }
    return end;
}

function openMenuModal(id, name, location, items) {
    editingMenuId = id || null;
    menuItems = parseMenuItems(items);
    resetItemForm();
    $('#metis-menu-modal-title').text(id ? 'Edit Menu' : 'New Menu');
    $('#metis-menu-id').val(id || '');
    $('#metis-menu-name').val(name || '');
    $('#metis-menu-location').val(location || '');
    refreshMenuSelects($('#metis-menu-modal'));
    renderItems();
    if (window.Metis && Metis.ui && Metis.ui.modal) {
        Metis.ui.modal.form('metis-menu-modal');
    }
    setTimeout(function() { $('#metis-menu-name').focus(); }, 100);
}

function closeMenuModal() {
    if (window.Metis && Metis.ui && Metis.ui.modal) {
        Metis.ui.modal.close('metis-menu-modal');
    }
}

function resetItemForm() {
    editingItemId = null;
    itemLabelTouched = false;
    $('#metis-menu-item-label, #metis-menu-item-url').val('');
    $('#metis-menu-item-source').val('page');
    $('#metis-menu-item-page').val('');
    $('#metis-menu-item-target').val('');
    $('#metis-menu-item-as-button').prop('checked', false);
    $('#metis-menu-item-button-color').val('metis_primary');
    $('#metis-menu-add-item-btn').text('+ Add Item');
    syncMenuItemSourceControls();
    refreshMenuSelects($('#metis-menu-modal'));
}

function renderItems() {
    var $list = $('#metis-menu-items-list');
    var $empty = $('#metis-menu-items-empty');
    $list.find('.metis-menu-item-row').remove();
    if (menuItems.length === 0) { $empty.show(); return; }
    $empty.hide();
    var flat = [];
    flattenForRender('', 0, flat);
    flat.forEach(function(entry, i) {
        var item = entry.item;
        var depth = Math.max(0, Math.min(6, Number(entry.depth || 0)));
        var hasParent = String(item.parent_id || '') !== '';
        var indent = depth * 20;
        var rowClass = depth > 0 ? ' metis-menu-item-child' : '';
        var row = $([
            '<div class="metis-menu-item-row' + rowClass + '" data-index="' + i + '" data-item-id="' + $('<div>').text(item.id).html() + '" style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#fff;border:1px solid var(--metis-border,#e2e6ea);border-radius:5px;margin-bottom:4px;padding-left:' + (10 + indent) + 'px;">',
            '  <span class="metis-menu-drag-handle" style="cursor:grab;color:#bbb;font-size:14px;flex-shrink:0;">⣿</span>',
            '  <div style="flex:1;min-width:0;">',
            '    <div style="font-weight:600;font-size:13px;">' + $('<div>').text(item.label).html() + '</div>',
            '    <div style="font-size:11px;color:var(--metis-text-muted,#888);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + (item.link_type === 'page' ? 'Published page • ' : 'Custom URL • ') + $('<div>').text(item.url).html() + (item.target === '_blank' ? ' ↗' : '') + '</div>' + (hasParent ? '<div style="font-size:10px;color:#5f6b86;margin-top:2px;">Sub item</div>' : ''),
            item.as_button ? '    <div style="font-size:10px;color:#2f3c5a;margin-top:2px;">Button: ' + $('<div>').text(String(item.button_color_key || 'metis_primary')).html() + '</div>' : '',
            '  </div>',
            hasParent ? '  <button type="button" class="metis-menu-item-outdent metis-action-btn" data-item-id="' + $('<div>').text(item.id).html() + '" title="Make top-level" style="flex-shrink:0;">↰</button>' : '',
            '  <button type="button" class="metis-menu-item-delete metis-action-btn metis-action-btn-danger" data-item-id="' + $('<div>').text(item.id).html() + '" title="Remove" style="flex-shrink:0;">',
            '    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
            '  </button>',
            '  <button type="button" class="metis-menu-item-edit metis-action-btn" data-item-id="' + $('<div>').text(item.id).html() + '" title="Edit Item" style="flex-shrink:0;">✎</button>',
            '</div>'
        ].join(''));
        $list.append(row);
    });

    // Sortable via drag
    $list.find('.metis-menu-item-row').each(function() {
        var $row = $(this);
        var rowItemId = String($row.data('itemId') || '');
        $row.attr('draggable', 'true');
        $row.on('dragstart', function(e) { e.originalEvent.dataTransfer.setData('text/plain', rowItemId); $row.css('opacity', '.5'); });
        $row.on('dragend', function() { $row.css('opacity', ''); });
        $row.on('dragover', function(e) { e.preventDefault(); $row.css('outline', '2px solid var(--metis-primary,#0d6efd)'); });
        $row.on('dragleave', function() { $row.css('outline', ''); });
        $row.on('drop', function(e) {
            e.preventDefault();
            $row.css('outline', '');
            var fromId = String(e.originalEvent.dataTransfer.getData('text/plain') || '');
            var toId = rowItemId;
            if (!fromId || !toId || fromId === toId) return;
            if (isDescendant(toId, fromId)) {
                Metis.ui.toast.warning('Cannot nest an item under its own child.');
                return;
            }
            var movedIndex = -1;
            for (var i = 0; i < menuItems.length; i += 1) {
                if (String(menuItems[i].id || '') === fromId) {
                    movedIndex = i;
                    break;
                }
            }
            if (movedIndex < 0) return;
            var moved = menuItems.splice(movedIndex, 1)[0];
            moved.parent_id = toId;
            var insertAt = subtreeEndIndex(toId);
            if (insertAt < 0) {
                menuItems.push(moved);
            } else {
                menuItems.splice(insertAt + 1, 0, moved);
            }
            renderItems();
        });
    });
}

$(document).on('click', '#metis-create-menu-btn, #metis-create-menu-btn-empty', function() { openMenuModal(); });
$(document).on('input', '#metis-menu-item-label', function() {
    itemLabelTouched = true;
});
$(document).on('change', '#metis-menu-item-source', function() {
    syncMenuItemSourceControls();
});
$(document).on('change', '#metis-menu-item-page', function() {
    var page = publishedPageById($(this).val());
    updatePublishedPagePathHint();
    if (!page) return;
    var currentLabel = $('#metis-menu-item-label').val().trim();
    if (!itemLabelTouched || currentLabel === '') {
        $('#metis-menu-item-label').val(page.title || '');
    }
});
$(document).on('click', '#metis-menu-add-item-btn', function() {
    var label = $('#metis-menu-item-label').val().trim();
    var source = String($('#metis-menu-item-source').val() || 'page');
    var pageId = 0;
    var url = '';
    if (source === 'page') {
        var page = publishedPageById($('#metis-menu-item-page').val());
        if (!page || !page.id || !page.path) {
            Metis.ui.toast.warning('Select a published page for this item.');
            $('#metis-menu-item-page').focus();
            return;
        }
        pageId = page.id;
        url = page.path;
        if (!label) {
            label = page.title || '';
            $('#metis-menu-item-label').val(label);
        }
    } else {
        url = $('#metis-menu-item-url').val().trim();
    }
    if (!label) { Metis.ui.toast.warning('Item label is required.'); $('#metis-menu-item-label').focus(); return; }
    if (!url)   { Metis.ui.toast.warning('Item URL is required.'); $('#metis-menu-item-url').focus(); return; }
    var target = $('#metis-menu-item-target').val() || '';
    var asButton = $('#metis-menu-item-as-button').is(':checked');
    var colorKey = String($('#metis-menu-item-button-color').val() || 'metis_primary');
    if (editingItemId) {
        var existing = itemById(editingItemId);
        if (existing) {
            existing.label = label;
            existing.url = url;
            existing.target = target;
            existing.external = target === '_blank';
            existing.as_button = asButton;
            existing.button_color_key = colorKey;
            existing.link_type = source === 'page' ? 'page' : 'custom';
            existing.page_id = source === 'page' ? pageId : 0;
        }
    } else {
        menuItems.push({
            id: 'mitem_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8),
            parent_id: '',
            label: label,
            url: url,
            target: target,
            external: target === '_blank',
            as_button: asButton,
            button_color_key: colorKey,
            link_type: source === 'page' ? 'page' : 'custom',
            page_id: source === 'page' ? pageId : 0
        });
    }
    resetItemForm();
    renderItems();
    $('#metis-menu-item-label').focus();
});

$(document).on('keydown', '#metis-menu-item-url', function(e) {
    if (e.key === 'Enter') $('#metis-menu-add-item-btn').trigger('click');
});

$(document).on('click', '.metis-menu-item-delete', function() {
    var id = String($(this).data('itemId') || '');
    if (!id) return;
    menuItems = menuItems.filter(function(item) {
        var itemId = String(item.id || '');
        return itemId !== id && !isDescendant(itemId, id);
    });
    if (editingItemId === id) {
        resetItemForm();
    }
    renderItems();
});

$(document).on('click', '.metis-menu-item-edit', function() {
    var id = String($(this).data('itemId') || '');
    if (!id) return;
    var item = itemById(id);
    if (!item) return;
    editingItemId = id;
    itemLabelTouched = false;
    $('#metis-menu-item-label').val(String(item.label || ''));
    $('#metis-menu-item-url').val(String(item.url || ''));
    $('#metis-menu-item-source').val(String(item.link_type || (item.page_id ? 'page' : 'custom')) === 'page' ? 'page' : 'custom');
    $('#metis-menu-item-page').val(item.page_id ? String(item.page_id) : '');
    $('#metis-menu-item-target').val(String(item.target || ''));
    $('#metis-menu-item-as-button').prop('checked', !!item.as_button);
    $('#metis-menu-item-button-color').val(String(item.button_color_key || 'metis_primary'));
    $('#metis-menu-add-item-btn').text('Update Item');
    syncMenuItemSourceControls();
    refreshMenuSelects($('#metis-menu-modal'));
    $('#metis-menu-item-label').focus();
});

$(document).on('click', '.metis-menu-item-outdent', function() {
    var id = String($(this).data('itemId') || '');
    var item = itemById(id);
    if (!item) return;
    item.parent_id = '';
    renderItems();
});

$(document).on('click', '.metis-edit-menu', function() {
    var $b = $(this);
    resetItemForm();
    openMenuModal($b.data('id'), $b.data('name'), $b.data('location'), $b.data('items') || '[]');
});

syncMenuItemSourceControls();
refreshMenuSelects($('#metis-menu-modal'));

$(document).on('click', '#metis-menu-save-btn', function() {
    var name = $('#metis-menu-name').val().trim();
    if (!name) { Metis.ui.toast.warning('Menu name is required.'); $('#metis-menu-name').focus(); return; }
    var id   = $('#metis-menu-id').val();
    var data = {
        action:     'metis_website_menu_save',
        nonce:      nonceFor('metis_website_menu_save'),
        metis_action_nonce: nonceFor('metis_website_menu_save'),
        name:       name,
        location:   $('#metis-menu-location').val(),
        items_json: JSON.stringify(menuItems)
    };
    if (id) data.id = id;
    $('#metis-menu-save-btn').prop('disabled', true).text('Saving…');
    $.ajax({
        url: metisWebsiteAjax.ajax_url, type: 'POST', data: data,
        success: function(r) {
            $('#metis-menu-save-btn').prop('disabled', false).text('Save Menu');
            if (r && r.success) { Metis.ui.toast.success('Menu saved.'); closeMenuModal(); renderMenusTable((r.data && r.data.menus) || []); }
            else { Metis.ui.toast.error((r.data && r.data.message) || 'Save failed.'); }
        },
        error: function() { $('#metis-menu-save-btn').prop('disabled', false).text('Save Menu'); Metis.ui.toast.error('Request failed.'); }
    });
});

$(document).on('click', '.metis-delete-menu', function() {
    var id = $(this).data('id');
    var name = $(this).closest('.metis-premium-row').find('.metis-premium-cell:first strong').text();
    Metis.ui.confirm.open({ message: 'Delete menu "' + name + '"?', confirmLabel: 'Delete', tone: 'danger' }).then(function(confirmed) {
        if (!confirmed) return;
        $.ajax({
            url: metisWebsiteAjax.ajax_url, type: 'POST',
            data: {
                action: 'metis_website_menu_delete',
                nonce: nonceFor('metis_website_menu_delete'),
                metis_action_nonce: nonceFor('metis_website_menu_delete'),
                id: id
            },
            success: function(r) {
                if (r && r.success) { Metis.ui.toast.success('Menu deleted.'); renderMenusTable((r.data && r.data.menus) || []); }
                else { Metis.ui.toast.error((r.data && r.data.message) || 'Delete failed.'); }
            },
            error: function() { Metis.ui.toast.error('Request failed.'); }
        });
    });
});

})();
</script>
