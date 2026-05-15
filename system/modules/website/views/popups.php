<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'popups' ) ) {
    return;
}

use Metis\Modules\Website\Services\PopupService;

$popups = PopupService::getAll();

$trigger_types = [
    'click'     => 'Button Click',
    'delay'     => 'Time Delay',
    'load'      => 'Page Load',
    'scroll'    => 'Scroll Threshold',
    'exit'      => 'Exit Intent',
];
?>
<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Popups</h1>
        <p class="metis-subtitle"><?php echo count( $popups ); ?> popup<?php echo count( $popups ) !== 1 ? 's' : ''; ?> configured with trigger and display rules.</p>
    </div>
    <div class="metis-page-header-right">
        <button class="metis-btn metis-btn-primary" id="metis-create-popup-btn">
            <svg class="metis-btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Popup
        </button>
    </div>
</div>

<div class="metis-table-wrap">
    <?php if ( empty( $popups ) ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#9741;</div>
            <h2>No popups yet</h2>
            <p>Create a popup to display modals triggered by buttons or page events.</p>
            <button class="metis-btn metis-btn-primary" id="metis-create-popup-btn-empty">New Popup</button>
        </div>
    <?php else : ?>
        <table class="metis-premium-table metis-popup-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Name</th>
                    <th class="metis-premium-cell" scope="col">Trigger</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $popups as $popup ) : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><strong><?php echo metis_escape_html( $popup['name'] ?? '' ); ?></strong></td>
                        <td class="metis-premium-cell metis-table-meta-cell"><?php echo metis_escape_html( $trigger_types[ $popup['trigger_type'] ?? 'click' ] ?? ucfirst( $popup['trigger_type'] ?? '' ) ); ?></td>
                        <td class="metis-premium-cell"><span class="metis-status metis-status-<?php echo metis_escape_attr( $popup['status'] ?? 'draft' ); ?>"><?php echo metis_escape_html( ucfirst( $popup['status'] ?? 'draft' ) ); ?></span></td>
                        <td class="metis-premium-cell metis-col-right">
                            <div class="metis-table-actions">
                                <button class="metis-action-btn metis-edit-popup"
                                    data-id="<?php echo metis_escape_attr( $popup['id'] ?? '' ); ?>"
                                    data-name="<?php echo metis_escape_attr( $popup['name'] ?? '' ); ?>"
                                    data-trigger="<?php echo metis_escape_attr( $popup['trigger_type'] ?? 'click' ); ?>"
                                    data-trigger-config="<?php echo metis_escape_attr( $popup['trigger_config_json'] ?? '{}' ); ?>"
                                    data-display-rules="<?php echo metis_escape_attr( $popup['display_rules_json'] ?? '{}' ); ?>"
                                    data-layout="<?php echo metis_escape_attr( $popup['layout_json'] ?? '' ); ?>"
                                    data-status="<?php echo metis_escape_attr( $popup['status'] ?? 'draft' ); ?>"
                                    title="Edit">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button class="metis-action-btn metis-action-btn-danger metis-delete-popup" data-id="<?php echo metis_escape_attr( $popup['id'] ?? '' ); ?>" title="Delete">
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

<div id="metis-popup-form-wrap" class="metis-form-card metis-is-hidden">
    <h2 class="metis-form-card-title">Popup Editor</h2>
    <input type="hidden" id="metis-popup-id" value="">
    <div class="metis-form-grid metis-form-grid-3">
        <label>Name<input id="metis-popup-name" type="text" class="metis-editor-input"></label>
        <label>Trigger
            <select id="metis-popup-trigger" class="metis-editor-input">
                <?php foreach ( $trigger_types as $trigger_key => $trigger_label ) : ?>
                    <option value="<?php echo metis_escape_attr( $trigger_key ); ?>"><?php echo metis_escape_html( $trigger_label ); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Status
            <select id="metis-popup-status" class="metis-editor-input">
                <option value="draft">Draft</option>
                <option value="published">Published</option>
            </select>
        </label>
        <label>Delay (ms)<input id="metis-popup-delay" type="number" min="0" step="100" class="metis-editor-input" value="1500"></label>
        <label>Scroll (%)<input id="metis-popup-scroll" type="number" min="1" max="100" step="1" class="metis-editor-input" value="50"></label>
        <label>Frequency
            <select id="metis-popup-frequency" class="metis-editor-input">
                <option value="session">Once per session</option>
                <option value="persisted">Persisted</option>
                <option value="always">Every trigger</option>
            </select>
        </label>
    </div>
    <div class="metis-form-grid metis-form-grid-2 metis-form-grid-top">
        <label>Headline<input id="metis-popup-headline" type="text" class="metis-editor-input"></label>
        <label>Button Label<input id="metis-popup-button-label" type="text" class="metis-editor-input"></label>
        <label>Button URL<input id="metis-popup-button-url" type="url" class="metis-editor-input" placeholder="https://example.org"></label>
        <label>Target Paths<input id="metis-popup-target-paths" type="text" class="metis-editor-input" placeholder="/,/about"></label>
        <label>Message<textarea id="metis-popup-message" class="metis-editor-input" rows="4"></textarea></label>
        <label>Target Slugs<textarea id="metis-popup-target-slugs" class="metis-editor-input" rows="4" placeholder="home,about-us"></textarea></label>
    </div>
    <label class="metis-inline-toggle"><input id="metis-popup-site-wide" type="checkbox" checked> Site-wide</label>
    <div class="metis-form-actions">
        <button id="metis-popup-save-btn" class="metis-btn metis-btn-primary">Save Popup</button>
        <button id="metis-popup-cancel-btn" class="metis-btn metis-btn-ghost">Cancel</button>
    </div>
</div>

<script>
(function($) {
'use strict';

function updatePopupSubtitle() {
    var count = $('.metis-popup-table .metis-premium-row').not('.metis-premium-header').length;
    $('.metis-page-header .metis-subtitle').first().text(count + ' popup' + (count === 1 ? '' : 's') + ' configured with trigger and display rules.');
}

function splitCsv(value) {
    return String(value || '').split(',').map(function(v){ return v.trim(); }).filter(Boolean);
}

function esc(value) {
    return String(value || '').replace(/[&<>"']/g, function(ch) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
}

function parseJson(raw, fallback) {
    if (raw && typeof raw === 'object') return raw;
    try { return JSON.parse(String(raw || '')); } catch (e) { return fallback; }
}

function popupLayout(headline, message, buttonLabel, buttonUrl) {
    var blocks = [];
    if (headline) {
        blocks.push({ type: 'heading', data: { content: esc(headline), level: 'h2' }, style: {} });
    }
    blocks.push({ type: 'text', data: { content: esc(message || 'Popup content'), tag: 'p' }, style: {} });
    if (buttonLabel && buttonUrl) {
        blocks.push({ type: 'button', data: { label: buttonLabel, url: buttonUrl, size: 'medium' }, style: {} });
    }
    return JSON.stringify({ version: 1, blocks: blocks });
}

function openPopupEditor(data) {
    data = data || {};
    var triggerConfig = parseJson(data.trigger_config_json || data.triggerConfig || '{}', {});
    var displayRules = parseJson(data.display_rules_json || data.displayRules || '{}', {});
    var layout = parseJson(data.layout_json || data.layout || '{}', {});
    var blocks = Array.isArray(layout.blocks) ? layout.blocks : ((layout.sections && layout.sections[0] && layout.sections[0].blocks) || []);
    var heading = '', message = '', buttonLabel = '', buttonUrl = '';
    blocks.forEach(function(block) {
        if (!block || !block.data) return;
        if (!heading && block.type === 'heading') heading = String(block.data.content || '').replace(/<[^>]+>/g, '');
        if (!message && block.type === 'text') message = String(block.data.content || '').replace(/<[^>]+>/g, '');
        if (!buttonLabel && block.type === 'button') {
            buttonLabel = String(block.data.label || '');
            buttonUrl = String(block.data.url || '');
        }
    });
    $('#metis-popup-id').val(data.id || '');
    $('#metis-popup-name').val(data.name || '');
    $('#metis-popup-trigger').val(data.trigger_type || data.trigger || 'click');
    $('#metis-popup-status').val(data.status || 'draft');
    $('#metis-popup-delay').val(triggerConfig.delay_ms || 1500);
    $('#metis-popup-scroll').val(triggerConfig.scroll_percent || 50);
    $('#metis-popup-frequency').val(triggerConfig.frequency || 'session');
    $('#metis-popup-headline').val(heading);
    $('#metis-popup-message').val(message);
    $('#metis-popup-button-label').val(buttonLabel);
    $('#metis-popup-button-url').val(buttonUrl);
    $('#metis-popup-site-wide').prop('checked', displayRules.site_wide !== false);
    $('#metis-popup-target-paths').val((displayRules.paths || []).join(', '));
    $('#metis-popup-target-slugs').val((displayRules.slugs || []).join(', '));
    $('#metis-popup-form-wrap').slideDown(120);
}

$(document).on('click', '#metis-create-popup-btn, #metis-create-popup-btn-empty', function() {
    openPopupEditor({});
});

$(document).on('click', '.metis-edit-popup', function() {
    var $b = $(this);
    openPopupEditor({
        id: $b.data('id'),
        name: $b.data('name'),
        trigger_type: $b.data('trigger'),
        trigger_config_json: $b.attr('data-trigger-config'),
        display_rules_json: $b.attr('data-display-rules'),
        layout_json: $b.attr('data-layout'),
        status: $b.data('status')
    });
});

$(document).on('click', '#metis-popup-cancel-btn', function() {
    $('#metis-popup-form-wrap').slideUp(100);
});

$(document).on('click', '#metis-popup-save-btn', function() {
    var id = Number($('#metis-popup-id').val() || 0);
    var name = $('#metis-popup-name').val().trim();
    var message = $('#metis-popup-message').val().trim();
    if (!name || !message) {
        metis_toast('Name and message are required.', 'error');
        return;
    }
    var payload = {
        action: 'metis_website_popup_save',
        nonce: metisWebsiteAjax.nonce,
        name: name,
        trigger_type: $('#metis-popup-trigger').val(),
        status: $('#metis-popup-status').val(),
        trigger_config_json: JSON.stringify({
            delay_ms: Number($('#metis-popup-delay').val() || 1500),
            scroll_percent: Number($('#metis-popup-scroll').val() || 50),
            frequency: $('#metis-popup-frequency').val()
        }),
        display_rules_json: JSON.stringify({
            site_wide: $('#metis-popup-site-wide').is(':checked'),
            paths: splitCsv($('#metis-popup-target-paths').val()),
            slugs: splitCsv($('#metis-popup-target-slugs').val()),
            content_types: []
        }),
        layout_json: popupLayout($('#metis-popup-headline').val(), message, $('#metis-popup-button-label').val(), $('#metis-popup-button-url').val())
    };
    if (id > 0) payload.id = id;

    $.post(metisWebsiteAjax.ajax_url, payload).done(function(r) {
        if (r && r.success) {
            metis_toast('Popup saved.', 'success');
            window.location.reload();
            return;
        }
        metis_toast((r && r.data && r.data.message) || 'Save failed.', 'error');
    }).fail(function() {
        metis_toast('Request failed.', 'error');
    });
});

$(document).on('click', '.metis-delete-popup', function() {
    var id = $(this).data('id');
    var name = $(this).closest('.metis-premium-row').find('.metis-premium-cell:first strong').text();
    metis_confirm('Delete popup "' + name + '"?', function() {
        $.ajax({
            url: metisWebsiteAjax.ajax_url, type: 'POST',
            data: { action: 'metis_website_popup_delete', nonce: metisWebsiteAjax.nonce, id: id },
            success: function(r) {
                if (r && r.success) {
                    var $row = $('.metis-delete-popup[data-id="' + String(id || '') + '"]').first().closest('.metis-premium-row');
                    $row.remove();
                    updatePopupSubtitle();
                    metis_toast('Popup deleted.', 'success');
                }
                else { metis_toast((r.data && r.data.message) || 'Delete failed.', 'error'); }
            },
            error: function() { metis_toast('Request failed.', 'error'); }
        });
    });
});

})(jQuery);
</script>
