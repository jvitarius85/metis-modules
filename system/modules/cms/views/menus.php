<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_cms_require_view_permission( 'menus' ) ) {
    return;
}

use Metis\Modules\Cms\Services\MenuService;

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
?>
<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Menus</h1>
        <p class="metis-subtitle"><?php echo count( $menus ); ?> menu<?php echo count( $menus ) !== 1 ? 's' : ''; ?> available for cms navigation.</p>
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
        <table class="metis-premium-table metis-menus-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Name</th>
                    <th class="metis-premium-cell" scope="col">Location</th>
                    <th class="metis-premium-cell" scope="col">Items</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $menus as $menu ) :
                    $items = MenuService::getItems( $menu );
                ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><strong><?php echo metis_escape_html( $menu['name'] ?? '' ); ?></strong></td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( $locations[ $menu['location'] ?? '' ] ?? ( $menu['location'] ?? '—' ) ); ?></td>
                        <td class="metis-premium-cell metis-table-meta-cell"><?php echo count( $items ); ?> item<?php echo count( $items ) !== 1 ? 's' : ''; ?></td>
                        <td class="metis-premium-cell"><span class="metis-status metis-status-<?php echo metis_escape_attr( $menu['status'] ?? 'active' ); ?>"><?php echo metis_escape_html( ucfirst( $menu['status'] ?? 'active' ) ); ?></span></td>
                        <td class="metis-premium-cell metis-col-right">
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
<div id="metis-menu-modal" class="metis-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-label="Menu Editor">
    <div class="metis-modal" style="max-width:640px;width:95%;">
        <div class="metis-modal-header">
            <h2 class="metis-modal-title" id="metis-menu-modal-title">New Menu</h2>
            <button type="button" class="metis-modal-close" id="metis-menu-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body" style="padding:20px;">

            <div class="metis-field" style="margin-bottom:14px;">
                <label class="metis-label">Menu Name <span style="color:#dc3545;">*</span></label>
                <input type="text" id="metis-menu-name" class="metis-input" placeholder="e.g. Main Navigation">
            </div>

            <div class="metis-field" style="margin-bottom:20px;">
                <label class="metis-label">Location</label>
                <select id="metis-menu-location" class="metis-input">
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
                    <label class="metis-label" style="font-size:11px;">Label</label>
                    <input type="text" id="metis-menu-item-label" class="metis-input metis-input-sm" placeholder="Link label">
                </div>
                <div class="metis-field" style="flex:2;min-width:200px;margin:0;">
                    <label class="metis-label" style="font-size:11px;">URL</label>
                    <input type="text" id="metis-menu-item-url" class="metis-input metis-input-sm" placeholder="https:// or /page-slug">
                </div>
                <div class="metis-field" style="margin:0;">
                    <label class="metis-label" style="font-size:11px;">Opens in</label>
                    <select id="metis-menu-item-target" class="metis-input metis-input-sm" style="width:110px;">
                        <option value="">Same tab</option>
                        <option value="_blank">New tab</option>
                    </select>
                </div>
                <div class="metis-field" style="margin:0;display:flex;align-items:center;gap:6px;">
                    <label class="metis-label" style="font-size:11px;margin:0;">Button</label>
                    <input type="checkbox" id="metis-menu-item-as-button" value="1">
                </div>
                <div class="metis-field" style="margin:0;">
                    <label class="metis-label" style="font-size:11px;">Button Color</label>
                    <select id="metis-menu-item-button-color" class="metis-input metis-input-sm" style="width:150px;">
                        <?php foreach ( $menu_button_colors as $color_key => $color_hex ) : ?>
                            <option value="<?php echo metis_escape_attr( $color_key ); ?>"><?php echo metis_escape_html( strtoupper( str_replace( 'metis_', '', $color_key ) ) . ' (' . $color_hex . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="metis-btn metis-btn-secondary metis-btn-sm" id="metis-menu-add-item-btn" style="flex-shrink:0;align-self:flex-end;">+ Add Item</button>
            </div>

            <input type="hidden" id="metis-menu-id" value="">

        </div>
        <div class="metis-modal-footer">
            <button type="button" class="metis-btn metis-btn-ghost" id="metis-menu-cancel-btn">Cancel</button>
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

var menuItems = [];
var editingMenuId = null;
var editingItemId = null;
var locationLabels = {};

$('#metis-menu-location option').each(function() {
    locationLabels[String($(this).val() || '')] = String($(this).text() || '');
});

function nonceFor(action) {
    var cfg = window.metisCmsAjax || {};
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
            button_color_key: String(obj.button_color_key || 'metis_primary').trim() || 'metis_primary'
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
    $('.metis-page-header .metis-subtitle').first().text(list.length + ' menu' + (list.length === 1 ? '' : 's') + ' available for cms navigation.');
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
        return '<tr class="metis-premium-row">'
            + '<td class="metis-premium-cell"><strong>' + escapeHtml(String(menu && menu.name ? menu.name : '')) + '</strong></td>'
            + '<td class="metis-premium-cell">' + escapeHtml(locationLabel) + '</td>'
            + '<td class="metis-premium-cell metis-table-meta-cell">' + escapeHtml(String(itemCount)) + ' item' + (itemCount === 1 ? '' : 's') + '</td>'
            + '<td class="metis-premium-cell"><span class="metis-status metis-status-' + escapeHtml(status) + '">' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</span></td>'
            + '<td class="metis-premium-cell metis-col-right"><div class="metis-table-actions">'
            + '<button type="button" class="metis-action-btn metis-edit-menu" data-id="' + escapeHtml(String(menu && menu.id ? menu.id : '')) + '" data-name="' + escapeHtml(String(menu && menu.name ? menu.name : '')) + '" data-location="' + escapeHtml(location) + '" data-items="' + escapeHtml(itemsRaw) + '" title="Edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>'
            + '<button type="button" class="metis-action-btn metis-action-btn-danger metis-delete-menu" data-id="' + escapeHtml(String(menu && menu.id ? menu.id : '')) + '" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>'
            + '</div></td>'
            + '</tr>';
    }).join('');

    $wrap.append(
        '<table class="metis-premium-table metis-menus-table">' +
            '<thead><tr class="metis-premium-row metis-premium-header">' +
                '<th class="metis-premium-cell" scope="col">Name</th>' +
                '<th class="metis-premium-cell" scope="col">Location</th>' +
                '<th class="metis-premium-cell" scope="col">Items</th>' +
                '<th class="metis-premium-cell" scope="col">Status</th>' +
                '<th class="metis-premium-cell metis-col-right" scope="col">Actions</th>' +
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
    $('#metis-menu-item-label, #metis-menu-item-url').val('');
    $('#metis-menu-item-target').val('');
    renderItems();
    var $modal = $('#metis-menu-modal');
    $modal.css('display', 'flex').hide().fadeIn(150);
    setTimeout(function() { $('#metis-menu-name').focus(); }, 100);
}

function closeMenuModal() {
    $('#metis-menu-modal').fadeOut(150);
}

function resetItemForm() {
    editingItemId = null;
    $('#metis-menu-item-label, #metis-menu-item-url').val('');
    $('#metis-menu-item-target').val('');
    $('#metis-menu-item-as-button').prop('checked', false);
    $('#metis-menu-item-button-color').val('metis_primary');
    $('#metis-menu-add-item-btn').text('+ Add Item');
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
            '    <div style="font-size:11px;color:var(--metis-text-muted,#888);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + $('<div>').text(item.url).html() + (item.target === '_blank' ? ' ↗' : '') + '</div>' + (hasParent ? '<div style="font-size:10px;color:#5f6b86;margin-top:2px;">Sub item</div>' : ''),
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
                metis_toast('Cannot nest an item under its own child.', 'warning');
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
$(document).on('click', '#metis-menu-modal-close, #metis-menu-cancel-btn', closeMenuModal);
$(document).on('click', '#metis-menu-modal', function(e) { if (e.target === this) closeMenuModal(); });

$(document).on('click', '#metis-menu-add-item-btn', function() {
    var label = $('#metis-menu-item-label').val().trim();
    var url   = $('#metis-menu-item-url').val().trim();
    if (!label) { metis_toast('Item label is required.', 'warning'); $('#metis-menu-item-label').focus(); return; }
    if (!url)   { metis_toast('Item URL is required.', 'warning'); $('#metis-menu-item-url').focus(); return; }
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
            button_color_key: colorKey
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
    $('#metis-menu-item-label').val(String(item.label || ''));
    $('#metis-menu-item-url').val(String(item.url || ''));
    $('#metis-menu-item-target').val(String(item.target || ''));
    $('#metis-menu-item-as-button').prop('checked', !!item.as_button);
    $('#metis-menu-item-button-color').val(String(item.button_color_key || 'metis_primary'));
    $('#metis-menu-add-item-btn').text('Update Item');
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

$(document).on('click', '#metis-menu-save-btn', function() {
    var name = $('#metis-menu-name').val().trim();
    if (!name) { metis_toast('Menu name is required.', 'warning'); $('#metis-menu-name').focus(); return; }
    var id   = $('#metis-menu-id').val();
    var data = {
        action:     'metis_cms_menu_save',
        nonce:      nonceFor('metis_cms_menu_save'),
        metis_action_nonce: nonceFor('metis_cms_menu_save'),
        name:       name,
        location:   $('#metis-menu-location').val(),
        items_json: JSON.stringify(menuItems)
    };
    if (id) data.id = id;
    $('#metis-menu-save-btn').prop('disabled', true).text('Saving…');
    $.ajax({
        url: metisCmsAjax.ajax_url, type: 'POST', data: data,
        success: function(r) {
            $('#metis-menu-save-btn').prop('disabled', false).text('Save Menu');
            if (r && r.success) { metis_toast('Menu saved.', 'success'); closeMenuModal(); renderMenusTable((r.data && r.data.menus) || []); }
            else { metis_toast((r.data && r.data.message) || 'Save failed.', 'error'); }
        },
        error: function() { $('#metis-menu-save-btn').prop('disabled', false).text('Save Menu'); metis_toast('Request failed.', 'error'); }
    });
});

$(document).on('click', '.metis-delete-menu', function() {
    var id = $(this).data('id');
    var name = $(this).closest('.metis-premium-row').find('.metis-premium-cell:first strong').text();
    metis_confirm('Delete menu "' + name + '"?', function() {
        $.ajax({
            url: metisCmsAjax.ajax_url, type: 'POST',
            data: {
                action: 'metis_cms_menu_delete',
                nonce: nonceFor('metis_cms_menu_delete'),
                metis_action_nonce: nonceFor('metis_cms_menu_delete'),
                id: id
            },
            success: function(r) {
                if (r && r.success) { metis_toast('Menu deleted.', 'success'); renderMenusTable((r.data && r.data.menus) || []); }
                else { metis_toast((r.data && r.data.message) || 'Delete failed.', 'error'); }
            },
            error: function() { metis_toast('Request failed.', 'error'); }
        });
    });
});

})();
</script>
