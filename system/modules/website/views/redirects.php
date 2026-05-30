<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'redirects' ) ) {
    return;
}

use Metis\Modules\Website\Services\RedirectService;

$redirects = RedirectService::all();
?>
<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Redirects</h1>
        <p class="metis-subtitle"><?php echo count( $redirects ); ?> redirect<?php echo count( $redirects ) !== 1 ? 's' : ''; ?> configured for website routing.</p>
    </div>
    <div class="metis-page-header-right">
        <button type="button" class="metis-btn metis-btn-primary" id="metis-create-redirect-btn">
            <svg style="width:14px;height:14px;margin-right:6px;vertical-align:-2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Redirect
        </button>
    </div>
</div>

<div class="metis-table-wrap">
    <?php if ( empty( $redirects ) ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#8640;</div>
            <h2>No redirects yet</h2>
            <p>Add a redirect when a published page or post moves so old URLs fail safely.</p>
            <button type="button" class="metis-btn metis-btn-primary" id="metis-create-redirect-btn-empty">New Redirect</button>
        </div>
    <?php else : ?>
        <table class="metis-premium-table metis-redirects-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Source</th>
                    <th class="metis-premium-cell" scope="col">Destination</th>
                    <th class="metis-premium-cell" scope="col">Type</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell" scope="col">Notes</th>
                    <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $redirects as $redirect ) : ?>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell"><strong><?php echo metis_escape_html( (string) ( $redirect['source_path'] ?? '' ) ); ?></strong></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $redirect['destination_path'] ?? '' ) ); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $redirect['redirect_type'] ?? '301' ) ); ?></td>
                    <td class="metis-premium-cell">
                        <span class="metis-status metis-status-<?php echo ! empty( $redirect['is_active'] ) ? 'published' : 'draft'; ?>">
                            <?php echo ! empty( $redirect['is_active'] ) ? 'Active' : 'Inactive'; ?>
                        </span>
                    </td>
                    <td class="metis-premium-cell metis-table-note-cell">
                        <?php echo metis_escape_html( (string) ( $redirect['notes'] ?? '' ) ); ?>
                    </td>
                    <td class="metis-premium-cell metis-col-right">
                        <div class="metis-table-actions">
                            <button
                                type="button"
                                class="metis-action-btn metis-edit-redirect"
                                data-id="<?php echo metis_escape_attr( (string) ( $redirect['id'] ?? 0 ) ); ?>"
                                data-source="<?php echo metis_escape_attr( (string) ( $redirect['source_path'] ?? '' ) ); ?>"
                                data-destination="<?php echo metis_escape_attr( (string) ( $redirect['destination_path'] ?? '' ) ); ?>"
                                data-type="<?php echo metis_escape_attr( (string) ( $redirect['redirect_type'] ?? '301' ) ); ?>"
                                data-active="<?php echo ! empty( $redirect['is_active'] ) ? '1' : '0'; ?>"
                                data-notes="<?php echo metis_escape_attr( (string) ( $redirect['notes'] ?? '' ) ); ?>"
                                title="Edit"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button
                                type="button"
                                class="metis-action-btn metis-action-btn-danger metis-delete-redirect"
                                data-id="<?php echo metis_escape_attr( (string) ( $redirect['id'] ?? 0 ) ); ?>"
                                title="Delete"
                            >
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

<div id="metis-redirect-modal" class="metis-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-label="Redirect Editor">
    <div class="metis-modal" style="max-width:640px;width:95%;">
        <div class="metis-modal-header">
            <h2 class="metis-modal-title" id="metis-redirect-modal-title">New Redirect</h2>
            <button type="button" class="metis-modal-close" id="metis-redirect-modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="metis-modal-body" style="padding:20px;">
            <div class="metis-field" style="margin-bottom:14px;">
                <label class="metis-label">Source Path <span style="color:#dc3545;">*</span></label>
                <input type="text" id="metis-redirect-source" class="metis-input" placeholder="/old-path">
            </div>
            <div class="metis-field" style="margin-bottom:14px;">
                <label class="metis-label">Destination Path <span style="color:#dc3545;">*</span></label>
                <input type="text" id="metis-redirect-destination" class="metis-input" placeholder="/new-path">
            </div>
            <div class="metis-website-form-grid">
                <div class="metis-field" style="margin:0;">
                    <label class="metis-label">Redirect Type</label>
                    <select id="metis-redirect-type" class="metis-input">
                        <option value="301">301</option>
                        <option value="302">302</option>
                    </select>
                </div>
                <div class="metis-field" style="margin:0;display:flex;align-items:flex-end;">
                    <label class="metis-label" style="display:flex;align-items:center;gap:8px;margin:0;">
                        <input type="checkbox" id="metis-redirect-active" value="1" checked>
                        Active
                    </label>
                </div>
            </div>
            <div class="metis-field" style="margin-top:14px;">
                <label class="metis-label">Notes</label>
                <textarea id="metis-redirect-notes" class="metis-input" rows="4" placeholder="Internal note for why this redirect exists"></textarea>
            </div>
            <input type="hidden" id="metis-redirect-id" value="">
        </div>
        <div class="metis-modal-footer">
            <button type="button" class="metis-btn metis-btn-ghost" id="metis-redirect-cancel-btn">Cancel</button>
            <button type="button" class="metis-btn metis-btn-primary" id="metis-redirect-save-btn">Save Redirect</button>
        </div>
    </div>
</div>

<script>
(function bootRedirectEditor() {
'use strict';

if (!window.jQuery) {
    window.setTimeout(bootRedirectEditor, 50);
    return;
}

var $ = window.jQuery;

function ajaxConfig() {
    var cfg = window.metisWebsiteAjax || {};
    return {
        ajax_url: String(cfg.ajax_url || '/api/ajax'),
        nonce: String(cfg.nonce || ''),
        action_nonces: cfg.action_nonces && typeof cfg.action_nonces === 'object' ? cfg.action_nonces : {}
    };
}

function nonceFor(action) {
    var cfg = ajaxConfig();
    return cfg.action_nonces[action] ? String(cfg.action_nonces[action]) : cfg.nonce;
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function closeModal() {
    $('#metis-redirect-modal').hide();
}

function openModal(title) {
    $('#metis-redirect-modal-title').text(title);
    $('#metis-redirect-modal').show();
}

function resetForm() {
    $('#metis-redirect-id').val('');
    $('#metis-redirect-source').val('');
    $('#metis-redirect-destination').val('');
    $('#metis-redirect-type').val('301');
    $('#metis-redirect-active').prop('checked', true);
    $('#metis-redirect-notes').val('');
}

function openCreate() {
    resetForm();
    openModal('New Redirect');
}

function openEdit(btn) {
    var $btn = $(btn);
    $('#metis-redirect-id').val(String($btn.data('id') || ''));
    $('#metis-redirect-source').val(String($btn.data('source') || ''));
    $('#metis-redirect-destination').val(String($btn.data('destination') || ''));
    $('#metis-redirect-type').val(String($btn.data('type') || '301'));
    $('#metis-redirect-active').prop('checked', String($btn.data('active') || '0') === '1');
    $('#metis-redirect-notes').val(String($btn.data('notes') || ''));
    openModal('Edit Redirect');
}

function renderRedirectTable(redirects) {
    var list = Array.isArray(redirects) ? redirects : [];
    var $wrap = $('.metis-table-wrap').first();
    $('.metis-page-header .metis-subtitle').first().text(list.length + ' redirect' + (list.length === 1 ? '' : 's') + ' configured for website routing.');
    $wrap.empty();

    if (!list.length) {
        $wrap.append(
            '<div class="metis-empty-state">' +
                '<div class="metis-empty-state-icon">&#8640;</div>' +
                '<h2>No redirects yet</h2>' +
                '<p>Add a redirect when a published page or post moves so old URLs fail safely.</p>' +
                '<button type="button" class="metis-btn metis-btn-primary" id="metis-create-redirect-btn-empty">New Redirect</button>' +
            '</div>'
        );
        return;
    }

    var rows = list.map(function(redirect) {
        var isActive = !!redirect.is_active;
        var type = String(redirect.redirect_type || '301');
        return '<tr class="metis-premium-row">'
            + '<td class="metis-premium-cell"><strong>' + escapeHtml(String(redirect.source_path || '')) + '</strong></td>'
            + '<td class="metis-premium-cell">' + escapeHtml(String(redirect.destination_path || '')) + '</td>'
            + '<td class="metis-premium-cell">' + escapeHtml(type) + '</td>'
            + '<td class="metis-premium-cell"><span class="metis-status metis-status-' + (isActive ? 'published' : 'draft') + '">' + (isActive ? 'Active' : 'Inactive') + '</span></td>'
            + '<td class="metis-premium-cell metis-table-note-cell">' + escapeHtml(String(redirect.notes || '')) + '</td>'
            + '<td class="metis-premium-cell metis-col-right"><div class="metis-table-actions">'
            + '<button type="button" class="metis-action-btn metis-edit-redirect" data-id="' + escapeHtml(String(redirect.id || '0')) + '" data-source="' + escapeHtml(String(redirect.source_path || '')) + '" data-destination="' + escapeHtml(String(redirect.destination_path || '')) + '" data-type="' + escapeHtml(type) + '" data-active="' + (isActive ? '1' : '0') + '" data-notes="' + escapeHtml(String(redirect.notes || '')) + '" title="Edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>'
            + '<button type="button" class="metis-action-btn metis-action-btn-danger metis-delete-redirect" data-id="' + escapeHtml(String(redirect.id || '0')) + '" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>'
            + '</div></td>'
            + '</tr>';
    }).join('');

    $wrap.append(
        '<table class="metis-premium-table metis-redirects-table">' +
            '<thead><tr class="metis-premium-row metis-premium-header">' +
                '<th class="metis-premium-cell" scope="col">Source</th>' +
                '<th class="metis-premium-cell" scope="col">Destination</th>' +
                '<th class="metis-premium-cell" scope="col">Type</th>' +
                '<th class="metis-premium-cell" scope="col">Status</th>' +
                '<th class="metis-premium-cell" scope="col">Notes</th>' +
                '<th class="metis-premium-cell metis-col-right" scope="col">Actions</th>' +
            '</tr></thead><tbody>' +
            rows +
        '</tbody></table>'
    );
}

function saveRedirect() {
    var payload = {
        action: 'metis_website_redirect_save',
        nonce: nonceFor('metis_website_redirect_save'),
        id: String($('#metis-redirect-id').val() || ''),
        source_path: String($('#metis-redirect-source').val() || '').trim(),
        destination_path: String($('#metis-redirect-destination').val() || '').trim(),
        redirect_type: String($('#metis-redirect-type').val() || '301'),
        is_active: $('#metis-redirect-active').is(':checked') ? '1' : '0',
        notes: String($('#metis-redirect-notes').val() || '').trim()
    };

    function toast(message, level) {
        if (window.Metis && Metis.ui && Metis.ui.toast && typeof Metis.ui.toast[level || 'info'] === 'function') {
            Metis.ui.toast[level || 'info'](String(message || ''));
        }
    }

    function confirmAction(message, onConfirm, options) {
        if (window.Metis && Metis.ui && Metis.ui.confirm && typeof Metis.ui.confirm.open === 'function') {
            Metis.ui.confirm.open(Object.assign({}, options || {}, {
                message: String(message || 'Confirm?')
            })).then(function(confirmed) {
                if (confirmed && typeof onConfirm === 'function') {
                    onConfirm();
                }
            });
        }
    }

    if (!payload.source_path || !payload.destination_path) {
        toast('Source path and destination path are required.', 'error');
        return;
    }

    $.post(ajaxConfig().ajax_url, payload).done(function(resp) {
        if (resp && resp.success) {
            toast('Redirect saved.', 'success');
            renderRedirectTable((resp.data && resp.data.redirects) || []);
            closeModal();
            return;
        }
        var message = resp && resp.data && (resp.data.message || resp.data) ? String(resp.data.message || resp.data) : 'Failed to save redirect.';
        toast(message, 'error');
    }).fail(function() {
        toast('Failed to save redirect.', 'error');
    });
}

function deleteRedirect(id) {
    confirmAction('Delete this redirect?', function() {
        $.post(ajaxConfig().ajax_url, {
            action: 'metis_website_redirect_delete',
            nonce: nonceFor('metis_website_redirect_delete'),
            id: String(id || '')
        }).done(function(resp) {
            if (resp && resp.success) {
                toast('Redirect deleted.', 'success');
                renderRedirectTable((resp.data && resp.data.redirects) || []);
                return;
            }
            var message = resp && resp.data && (resp.data.message || resp.data) ? String(resp.data.message || resp.data) : 'Failed to delete redirect.';
            toast(message, 'error');
        }).fail(function() {
            toast('Failed to delete redirect.', 'error');
        });
    }, {
        title: 'Delete Redirect',
        confirmLabel: 'Delete',
        tone: 'danger'
    });
}

$(document).on('click', '#metis-create-redirect-btn, #metis-create-redirect-btn-empty', openCreate);
$(document).on('click', '.metis-edit-redirect', function() { openEdit(this); });
$(document).on('click', '.metis-delete-redirect', function() { deleteRedirect($(this).data('id')); });
$(document).on('click', '#metis-redirect-modal-close, #metis-redirect-cancel-btn', closeModal);
$(document).on('click', '#metis-redirect-save-btn', saveRedirect);
$(document).on('click', '#metis-redirect-modal', function(event) {
    if (event.target === this) {
        closeModal();
    }
});
})( );
</script>
