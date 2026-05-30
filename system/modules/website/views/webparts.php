<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'webparts' ) ) {
    return;
}
?>
<div id="metis-webparts-view" class="metis-config-view">
    <div class="metis-page-header">
        <div class="metis-page-header-left">
            <h1 class="metis-page-title">Web Parts</h1>
            <p class="metis-subtitle">Manage reusable components attached to templates, pages, and posts.</p>
        </div>
        <div class="metis-page-header-right">
            <select id="metis-webpart-status-filter" class="metis-input metis-input-sm">
                <option value="">All Statuses</option>
                <option value="published">Published</option>
                <option value="draft">Draft</option>
            </select>
            <button class="metis-btn metis-btn-primary" id="metis-webpart-create-btn">New Web Part</button>
        </div>
    </div>

    <div class="metis-table-wrap">
        <table class="metis-premium-table metis-webparts-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Name</th>
                    <th class="metis-premium-cell" scope="col">Type</th>
                    <th class="metis-premium-cell" scope="col">Target</th>
                    <th class="metis-premium-cell" scope="col">Region / Slot</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell metis-col-right" scope="col">Manage</th>
                </tr>
            </thead>
            <tbody id="metis-webpart-table-body"></tbody>
        </table>

        <div id="metis-webpart-empty-state" class="metis-empty-state" hidden>
            <div class="metis-empty-state-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="52" height="52" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="4" y="4" width="16" height="16" rx="3"></rect>
                    <path d="M8 9h8M8 13h5M8 17h8"></path>
                </svg>
            </div>
            <h2>No web parts yet</h2>
            <p>Create reusable blocks and attach them to template, page, or post regions.</p>
            <button class="metis-btn metis-btn-primary" id="metis-webpart-create-btn-empty">New Web Part</button>
        </div>
    </div>

    <div id="metis-webpart-modal" class="metis-modal-overlay" hidden role="dialog" aria-modal="true" aria-label="Web Part Editor">
        <div class="metis-modal metis-config-modal">
            <div class="metis-modal-header">
                <h2 class="metis-modal-title" id="metis-webpart-modal-title">New Web Part</h2>
                <button class="metis-modal-close" id="metis-webpart-modal-close" aria-label="Close">&times;</button>
            </div>
            <div class="metis-modal-body metis-config-modal-body">
                <div class="metis-form-grid metis-form-grid-3">
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-name">Name</label>
                        <input id="metis-webpart-name" class="metis-input" type="text" placeholder="Web part name">
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-type">Part Type</label>
                        <select id="metis-webpart-type" class="metis-input">
                            <option value="custom">Custom</option>
                            <option value="banner">Banner</option>
                            <option value="form_embed">Form Embed</option>
                            <option value="donation_progress">Donation Progress</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-status">Status</label>
                        <select id="metis-webpart-status" class="metis-input">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-render-mode">Render Mode</label>
                        <select id="metis-webpart-render-mode" class="metis-input">
                            <option value="blocks">Blocks</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-target-scope">Target Scope</label>
                        <select id="metis-webpart-target-scope" class="metis-input">
                            <option value="site">Site</option>
                            <option value="template">Template</option>
                            <option value="page">Page</option>
                            <option value="post">Post</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-target-ref">Target Ref</label>
                        <input id="metis-webpart-target-ref" class="metis-input" type="text" placeholder="template key, slug, code, or id">
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-region">Region</label>
                        <select id="metis-webpart-region" class="metis-input">
                            <option value="main">Main</option>
                            <option value="header">Header</option>
                            <option value="footer">Footer</option>
                            <option value="sidebar">Sidebar</option>
                            <option value="banners">Banners</option>
                        </select>
                    </div>
                    <div class="metis-field">
                        <label class="metis-label" for="metis-webpart-slot">Slot</label>
                        <select id="metis-webpart-slot" class="metis-input">
                            <option value="append">Append</option>
                            <option value="prepend">Prepend</option>
                            <option value="before">Before</option>
                            <option value="after">After</option>
                        </select>
                    </div>
                <div class="metis-field">
                    <label class="metis-label" for="metis-webpart-sort-order">Sort Order</label>
                    <input id="metis-webpart-sort-order" class="metis-input" type="number" step="1" value="0">
                </div>
                </div>

                <div class="metis-field">
                    <label class="metis-label" for="metis-webpart-content">Content</label>
                    <textarea id="metis-webpart-content" class="metis-input metis-webpart-content-input" rows="7" placeholder="Write reusable content for this web part."></textarea>
                    <p class="metis-field-help">Content is stored as a Website text block and can use approved dynamic tokens.</p>
                </div>
                <div class="metis-field">
                    <label class="metis-label">Visibility Rules</label>
                    <label class="metis-inline-toggle"><input id="metis-webpart-site-wide" type="checkbox" checked> <span>Site-wide</span></label>
                    <div class="metis-form-grid metis-form-grid-3">
                        <div class="metis-field">
                            <label class="metis-label" for="metis-webpart-visibility-paths">Paths (comma separated)</label>
                            <input id="metis-webpart-visibility-paths" class="metis-input" type="text" placeholder="/,/about">
                        </div>
                        <div class="metis-field">
                            <label class="metis-label" for="metis-webpart-visibility-slugs">Slugs (comma separated)</label>
                            <input id="metis-webpart-visibility-slugs" class="metis-input" type="text" placeholder="home,about">
                        </div>
                        <div class="metis-field">
                            <label class="metis-label" for="metis-webpart-visibility-types">Content Types</label>
                            <input id="metis-webpart-visibility-types" class="metis-input" type="text" placeholder="page,post">
                        </div>
                    </div>
                </div>
                <input id="metis-webpart-id" type="hidden" value="">
            </div>
            <div class="metis-modal-footer">
                <button class="metis-btn metis-btn-ghost" id="metis-webpart-cancel-btn">Cancel</button>
                <button class="metis-btn metis-btn-primary" id="metis-webpart-save-btn">Save Web Part</button>
            </div>
        </div>
    </div>
</div>
<script>
(function($) {
    'use strict';
    if (!$ || !document.getElementById('metis-webparts-view')) {
        return;
    }

    var state = { webparts: [] };

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function toast(message, type) {
        if (window.Metis && Metis.ui && Metis.ui.toast && typeof Metis.ui.toast[type || 'info'] === 'function') {
            Metis.ui.toast[type || 'info'](String(message || ''));
        }
    }

    function csrf(action) {
        if (window.MetisWebsite && typeof window.MetisWebsite._csrfPayload === 'function') {
            return window.MetisWebsite._csrfPayload(action);
        }
        var website = window.metisWebsiteAjax || {};
        var actionNonces = website.action_nonces && typeof website.action_nonces === 'object' ? website.action_nonces : {};
        var nonce = actionNonces[action] || website.nonce || '';
        return {
            nonce: nonce,
            metis_action_nonce: nonce,
            metis_csrf_action: 'metis_ajax:' + action
        };
    }

    function request(action, payload, onSuccess) {
        var ajax = window.metisWebsiteAjax || {};
        $.ajax({
            url: ajax.ajax_url || '/api/ajax',
            method: 'POST',
            data: Object.assign({}, payload || {}, csrf(action), { action: action }),
            success: function(response) {
                if (response && response.success) {
                    if (typeof onSuccess === 'function') {
                        onSuccess(response.data || {});
                    }
                    return;
                }
                toast((response && response.data && response.data.message) ? response.data.message : 'Website request failed.', 'error');
            },
            error: function(xhr) {
                var data = xhr && xhr.responseJSON ? xhr.responseJSON.data : null;
                toast((data && data.message) ? data.message : 'Website request failed.', 'error');
            }
        });
    }

    function splitCsv(value) {
        return String(value || '').split(',').map(function(item) {
            return item.trim();
        }).filter(Boolean);
    }

    function contentJsonFromText(value) {
        var text = String(value || '').trim();
        var html = text === '' ? '' : text
            .split(/\n{2,}/)
            .map(function(part) { return '<p>' + esc(part).replace(/\n/g, '<br>') + '</p>'; })
            .join('');
        return JSON.stringify({
            version: 1,
            blocks: html === '' ? [] : [{
                id: 'webpart_text_1',
                type: 'text',
                data: { content: html, tag: 'div' },
                style: {}
            }]
        });
    }

    function textFromContentJson(raw) {
        var decoded = {};
        try {
            decoded = raw ? JSON.parse(String(raw)) : {};
        } catch (e) {
            decoded = {};
        }
        var blocks = Array.isArray(decoded.blocks) ? decoded.blocks : [];
        var text = blocks.map(function(block) {
            var data = block && block.data ? block.data : {};
            return $('<div>').html(String(data.content || '')).text().trim();
        }).filter(Boolean).join("\n\n");
        return text;
    }

    function visibilityFromForm() {
        return JSON.stringify({
            site_wide: $('#metis-webpart-site-wide').is(':checked'),
            paths: splitCsv($('#metis-webpart-visibility-paths').val()),
            slugs: splitCsv($('#metis-webpart-visibility-slugs').val()),
            content_types: splitCsv($('#metis-webpart-visibility-types').val())
        });
    }

    function fillVisibility(raw) {
        var rules = {};
        try {
            rules = raw ? JSON.parse(String(raw)) : {};
        } catch (e) {
            rules = {};
        }
        $('#metis-webpart-site-wide').prop('checked', rules.site_wide !== false);
        $('#metis-webpart-visibility-paths').val(Array.isArray(rules.paths) ? rules.paths.join(', ') : '');
        $('#metis-webpart-visibility-slugs').val(Array.isArray(rules.slugs) ? rules.slugs.join(', ') : '');
        $('#metis-webpart-visibility-types').val(Array.isArray(rules.content_types) ? rules.content_types.join(', ') : '');
    }

    function renderRows() {
        var filter = String($('#metis-webpart-status-filter').val() || '');
        var rows = state.webparts.filter(function(part) {
            return filter === '' || String(part.status || '') === filter;
        });
        var html = rows.map(function(part) {
            var target = String(part.target_scope || 'site');
            var targetRef = String(part.target_ref || '');
            return [
                '<tr class="metis-premium-row" data-id="' + esc(part.id) + '">',
                '<td class="metis-premium-cell"><strong>' + esc(part.name || 'Untitled Web Part') + '</strong><span>' + esc(part.part_code || '') + '</span></td>',
                '<td class="metis-premium-cell">' + esc(part.part_type || 'custom') + '</td>',
                '<td class="metis-premium-cell">' + esc(targetRef ? target + ': ' + targetRef : target) + '</td>',
                '<td class="metis-premium-cell">' + esc((part.region || 'main') + ' / ' + (part.slot || 'append')) + '</td>',
                '<td class="metis-premium-cell"><span class="metis-status-pill">' + esc(part.status || 'draft') + '</span></td>',
                '<td class="metis-premium-cell metis-col-right"><button type="button" class="metis-btn-xs metis-webpart-edit" data-id="' + esc(part.id) + '">Edit</button> <button type="button" class="metis-btn-xs metis-webpart-delete" data-id="' + esc(part.id) + '">Delete</button></td>',
                '</tr>'
            ].join('');
        }).join('');
        $('#metis-webpart-table-body').html(html);
        $('#metis-webpart-empty-state').prop('hidden', rows.length > 0);
    }

    function loadList() {
        request('metis_website_webparts_list', {}, function(data) {
            state.webparts = Array.isArray(data.webparts) ? data.webparts : [];
            renderRows();
        });
    }

    function resetModal() {
        $('#metis-webpart-id').val('');
        $('#metis-webpart-name').val('');
        $('#metis-webpart-type').val('custom');
        $('#metis-webpart-status').val('draft');
        $('#metis-webpart-render-mode').val('blocks');
        $('#metis-webpart-target-scope').val('site');
        $('#metis-webpart-target-ref').val('');
        $('#metis-webpart-region').val('main');
        $('#metis-webpart-slot').val('append');
        $('#metis-webpart-sort-order').val('0');
        $('#metis-webpart-content').val('');
        fillVisibility('{}');
    }

    function openModal(part) {
        resetModal();
        if (part) {
            $('#metis-webpart-modal-title').text('Edit Web Part');
            $('#metis-webpart-id').val(part.id || '');
            $('#metis-webpart-name').val(part.name || '');
            $('#metis-webpart-type').val(part.part_type || 'custom');
            $('#metis-webpart-status').val(part.status || 'draft');
            $('#metis-webpart-target-scope').val(part.target_scope || 'site');
            $('#metis-webpart-target-ref').val(part.target_ref || '');
            $('#metis-webpart-region').val(part.region || 'main');
            $('#metis-webpart-slot').val(part.slot || 'append');
            $('#metis-webpart-sort-order').val(part.sort_order || '0');
            $('#metis-webpart-content').val(textFromContentJson(part.content_json || ''));
            fillVisibility(part.visibility_json || '{}');
        } else {
            $('#metis-webpart-modal-title').text('New Web Part');
        }
        $('#metis-webpart-modal').prop('hidden', false);
        $('#metis-webpart-name').trigger('focus');
    }

    function closeModal() {
        $('#metis-webpart-modal').prop('hidden', true);
    }

    function saveWebPart() {
        var payload = {
            id: $('#metis-webpart-id').val(),
            name: $('#metis-webpart-name').val(),
            part_type: $('#metis-webpart-type').val(),
            status: $('#metis-webpart-status').val(),
            render_mode: 'blocks',
            target_scope: $('#metis-webpart-target-scope').val(),
            target_ref: $('#metis-webpart-target-ref').val(),
            region: $('#metis-webpart-region').val(),
            slot: $('#metis-webpart-slot').val(),
            sort_order: $('#metis-webpart-sort-order').val(),
            content_json: contentJsonFromText($('#metis-webpart-content').val()),
            visibility_json: visibilityFromForm(),
            config_json: '{}'
        };
        request('metis_website_webpart_save', payload, function(data) {
            state.webparts = Array.isArray(data.webparts) ? data.webparts : [];
            renderRows();
            closeModal();
            toast(data.message || 'Web part saved.', 'success');
        });
    }

    $(document).on('click.metisWebsiteWebParts', '#metis-webpart-create-btn, #metis-webpart-create-btn-empty', function(e) {
        e.preventDefault();
        openModal(null);
    });
    $(document).on('click.metisWebsiteWebPartClose', '#metis-webpart-modal-close, #metis-webpart-cancel-btn', function(e) {
        e.preventDefault();
        closeModal();
    });
    $(document).on('change.metisWebsiteWebParts', '#metis-webpart-status-filter', renderRows);
    $(document).on('click.metisWebsiteWebPartEdit', '.metis-webpart-edit', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var id = String($(this).attr('data-id') || '');
        var part = state.webparts.find(function(item) { return String(item.id) === id; });
        if (part) {
            openModal(part);
        }
    });
    $(document).on('click.metisWebsiteWebPartDelete', '.metis-webpart-delete', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var id = String($(this).attr('data-id') || '');
        var remove = function() {
            request('metis_website_webpart_delete', { id: id }, function(data) {
                state.webparts = Array.isArray(data.webparts) ? data.webparts : [];
                renderRows();
                toast(data.message || 'Web part deleted.', 'success');
            });
        };
        if (window.MetisWebsite && typeof window.MetisWebsite._confirm === 'function') {
            window.MetisWebsite._confirm('Delete this web part?', remove, { title: 'Delete Web Part', confirmLabel: 'Delete' });
        } else {
            remove();
        }
    });
    $(document).on('click.metisWebsiteWebPartSave', '#metis-webpart-save-btn', function(e) {
        e.preventDefault();
        saveWebPart();
    });
    $(document).on('click.metisWebsiteWebPartRow', '.metis-webparts-table tbody tr', function() {
        var id = String($(this).attr('data-id') || '');
        var part = state.webparts.find(function(item) { return String(item.id) === id; });
        if (part) {
            openModal(part);
        }
    });

    loadList();
})(window.jQuery);
</script>
