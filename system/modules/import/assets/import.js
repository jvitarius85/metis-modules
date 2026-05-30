/**
 * Import Module JavaScript
 */
(function($) {
'use strict';

var currentStep = 1;
var selectedFile = null;
var selectedPageIds = [];
var selectedPostIds = [];
var openingFileDialog = false;

function notify(message, level) {
    if (window.Metis && Metis.ui && Metis.ui.toast && typeof Metis.ui.toast[level || 'info'] === 'function') {
        Metis.ui.toast[level || 'info'](String(message || ''));
        return;
    }
    if (window.console && typeof window.console.log === 'function') {
        window.console.log('[Import] ' + String(message || ''));
    }
}

function extractAjaxError(xhr, fallback) {
    var msg = '';
    var r = xhr && xhr.responseJSON ? xhr.responseJSON : null;
    if (r && r.data) {
        if (typeof r.data === 'string') {
            msg = r.data;
        } else if (r.data && typeof r.data.message === 'string') {
            msg = r.data.message;
        }
    }
    if (!msg && r && typeof r.message === 'string') {
        msg = r.message;
    }
    if (!msg && xhr && typeof xhr.responseText === 'string') {
        try {
            var parsed = JSON.parse(xhr.responseText);
            if (parsed && parsed.data) {
                if (typeof parsed.data === 'string') {
                    msg = parsed.data;
                } else if (parsed.data && typeof parsed.data.message === 'string') {
                    msg = parsed.data.message;
                }
            }
        } catch (e) {}
    }
    if (!msg) {
        msg = fallback || 'Request failed.';
    }
    return msg;
}

function getImportAjaxConfig() {
    var cfg = (window.metisImportAjax && typeof window.metisImportAjax === 'object') ? window.metisImportAjax : {};
    var core = (window.metisAjax && typeof window.metisAjax === 'object') ? window.metisAjax : {};
    var actionNonces = (cfg.action_nonces && typeof cfg.action_nonces === 'object')
        ? cfg.action_nonces
        : ((core.action_nonces && typeof core.action_nonces === 'object') ? core.action_nonces : {});
    var defaultActionNonce = actionNonces.metis_import_upload_parse || actionNonces.metis_import_confirm || '';
    return {
        ajax_url: cfg.ajax_url || core.ajax_url || '/api/ajax',
        nonce: cfg.nonce || defaultActionNonce || '',
        action_nonces: actionNonces
    };
}

function openFilePicker() {
    if (openingFileDialog) return;
    var input = document.getElementById('metis-import-file-input');
    if (!input) return;
    openingFileDialog = true;
    if (typeof input.click === 'function') {
        input.click();
    } else {
        $('#metis-import-file-input').trigger('click');
    }
    setTimeout(function() {
        openingFileDialog = false;
    }, 0);
}

function nativeSelectFileDisplay(f) {
    var nameEl = document.getElementById('metis-import-file-name');
    var rowEl = document.getElementById('metis-import-file-selected');
    var zoneEl = document.getElementById('metis-import-drop-zone');
    if (nameEl) {
        nameEl.textContent = f.name + ' (' + (f.size / 1024).toFixed(0) + ' KB)';
    }
    if (rowEl) {
        rowEl.style.display = 'flex';
    }
    if (zoneEl) {
        zoneEl.style.display = 'none';
    }
}

function setStep(n) {
    currentStep = n;
    for (var i = 1; i <= 4; i++) {
        var $step = $('.metis-import-step[data-step="' + i + '"]');
        $step.removeClass('is-active is-done');
        $step.removeAttr('aria-current');
        if (i === n) {
            $step.addClass('is-active');
            $step.attr('aria-current', 'step');
        }
        if (i < n)  $step.addClass('is-done');
        $('#metis-import-step-' + i).hide();
    }
    $('#metis-import-step-' + n).show();
}

// File selection via input (delegated: import view can be loaded dynamically)
$(document).off('change.metisImport', '#metis-import-file-input').on('change.metisImport', '#metis-import-file-input', function() {
    var f = this.files[0];
    if (!f) return;
    selectFile(f);
});

// Drag and drop (delegated)
$(document).off('click.metisImport', '#metis-import-drop-zone').on('click.metisImport', '#metis-import-drop-zone', function(e) {
    var $target = $(e.target);
    if ($target.closest('#metis-import-file-input').length) return;
    if ($target.closest('button,a,input,label,select,textarea').length) return;
    openFilePicker();
});

$(document).off('keydown.metisImport', '#metis-import-drop-zone').on('keydown.metisImport', '#metis-import-drop-zone', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    openFilePicker();
});

$(document).off('click.metisImport', '#metis-import-choose-file-btn').on('click.metisImport', '#metis-import-choose-file-btn', function() {
    openFilePicker();
});

$(document).off('dragover.metisImport', '#metis-import-drop-zone').on('dragover.metisImport', '#metis-import-drop-zone', function(e) {
    e.preventDefault();
    $(this).css('border-color', 'var(--metis-primary,#0d6efd)');
});

$(document).off('dragleave.metisImport', '#metis-import-drop-zone').on('dragleave.metisImport', '#metis-import-drop-zone', function() {
    $(this).css('border-color', '');
});

$(document).off('drop.metisImport', '#metis-import-drop-zone').on('drop.metisImport', '#metis-import-drop-zone', function(e) {
    e.preventDefault();
    $(this).css('border-color', '');
    var f = e.originalEvent.dataTransfer.files[0];
    if (f) selectFile(f);
});

function selectFile(f) {
    selectedFile = f;
    nativeSelectFileDisplay(f);
    $('#metis-import-file-name').text(f.name + ' (' + (f.size / 1024).toFixed(0) + ' KB)');
    $('#metis-import-file-selected').css('display', 'flex');
    $('#metis-import-drop-zone').hide();
}

window.metisImportHandleFileInput = function(inputEl) {
    if (!inputEl || !inputEl.files || !inputEl.files[0]) return;
    selectFile(inputEl.files[0]);
};

function bindNativeImportFallbacks() {
    var input = document.getElementById('metis-import-file-input');
    if (input && !input.dataset.metisImportBound) {
        input.dataset.metisImportBound = '1';
        input.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                selectFile(this.files[0]);
            }
        });
        input.addEventListener('click', function() {
            this.value = '';
        });
    }

    var zone = document.getElementById('metis-import-drop-zone');
    if (zone && !zone.dataset.metisImportDndBound) {
        zone.dataset.metisImportDndBound = '1';
        zone.addEventListener('dragover', function(e) {
            e.preventDefault();
            zone.style.borderColor = 'var(--metis-primary,#0d6efd)';
        });
        zone.addEventListener('dragleave', function() {
            zone.style.borderColor = '';
        });
        zone.addEventListener('drop', function(e) {
            e.preventDefault();
            zone.style.borderColor = '';
            var files = (e.dataTransfer && e.dataTransfer.files) ? e.dataTransfer.files : null;
            if (files && files[0]) {
                selectFile(files[0]);
            }
        });
    }
}

// Parse file
$(document).off('click.metisImport', '#metis-import-parse-btn').on('click.metisImport', '#metis-import-parse-btn', function() {
    if (!selectedFile) return;
    var ajaxCfg = getImportAjaxConfig();
    if (!ajaxCfg.nonce) {
        notify('Import security token is missing. Please refresh this page.', 'error');
        return;
    }
    $('#metis-import-file-selected').hide();
    $('#metis-import-parsing').show();

    var fd = new FormData();
    var action = 'metis_import_upload_parse';
    var actionNonce = ajaxCfg.action_nonces[action] || '';
    fd.append('action', action);
    fd.append('nonce', ajaxCfg.nonce);
    if (actionNonce) fd.append('metis_action_nonce', actionNonce);
    fd.append('metis_csrf_action', actionNonce ? ('metis_ajax:' + action) : 'metis_import');
    fd.append('import_file', selectedFile);

    $.ajax({
        url: ajaxCfg.ajax_url,
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function(r) {
            $('#metis-import-parsing').hide();
            if (r.success) {
                renderPreview(r.data.preview);
                setStep(2);
            } else {
                $('#metis-import-file-selected').show();
                notify((r.data && r.data.message) || 'Parse failed.', 'error');
            }
        },
        error: function(xhr) {
            $('#metis-import-parsing').hide();
            $('#metis-import-file-selected').show();
            notify(extractAjaxError(xhr, 'Upload failed. Please try again.'), 'error');
        }
    });
});

function renderPreview(preview) {
    var html = '';
    selectedPageIds = Array.isArray(preview.pages) ? preview.pages.map(function(p){ return Number(p.post_id || 0); }).filter(function(id){ return id > 0; }) : [];
    selectedPostIds = Array.isArray(preview.posts) ? preview.posts.map(function(p){ return Number(p.post_id || 0); }).filter(function(id){ return id > 0; }) : [];

    // Site info
    if (preview.site_info && preview.site_info.title) {
        html += '<div style="margin-bottom:16px;padding:12px 16px;background:var(--metis-surface-alt,#f7f8fa);border-radius:6px;font-size:13px;">';
        html += '<strong>Source:</strong> ' + $('<div>').text(preview.site_info.title).html();
        if (preview.site_info.link) html += ' &mdash; <a href="' + preview.site_info.link + '" target="_blank" rel="noopener">' + $('<div>').text(preview.site_info.link).html() + '</a>';
        html += '</div>';
    }

    // Stats overview
    html += '<div class="metis-import-stats-grid">';
    var stats = [
        { label: 'Pages',  count: preview.stats.pages,   key: 'pages' },
        { label: 'Posts',  count: preview.stats.posts,   key: 'posts' },
        { label: 'Media',  count: preview.stats.media,   key: 'media' },
        { label: 'Menus',  count: preview.menus_count || 0, key: 'menus' },
    ];
    stats.forEach(function(s) {
        html += '<div style="text-align:center;padding:12px;background:var(--metis-surface,#fff);border:1px solid var(--metis-border,#e2e6ea);border-radius:6px;">';
        html += '<div style="font-size:24px;font-weight:700;color:var(--metis-primary,#0d6efd);">' + s.count + '</div>';
        html += '<div style="font-size:12px;color:var(--metis-text-muted,#888);">' + s.label + '</div>';
        html += '</div>';
    });
    html += '</div>';

    // Import options
    html += '<div class="metis-import-section">';
    html += '<div class="metis-import-section-title">What to Import</div>';

    if (preview.stats.pages > 0) {
        html += '<label style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;cursor:pointer;">';
        html += '<input type="checkbox" id="metis-import-pages-cb" checked style="margin-top:2px;width:16px;height:16px;">';
        html += '<div><strong style="font-size:13px;">Pages</strong> <span class="metis-import-section-count">' + preview.stats.pages + '</span><div style="font-size:12px;color:#888;margin-top:2px;">Imported as drafts — review before publishing</div></div>';
        html += '</label>';
    }
    if (preview.stats.posts > 0) {
        html += '<label style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;cursor:pointer;">';
        html += '<input type="checkbox" id="metis-import-posts-cb" checked style="margin-top:2px;width:16px;height:16px;">';
        html += '<div><strong style="font-size:13px;">Posts</strong> <span class="metis-import-section-count">' + preview.stats.posts + '</span><div style="font-size:12px;color:#888;margin-top:2px;">Imported as drafts — review before publishing</div></div>';
        html += '</label>';
    }
    if (preview.menus_count > 0) {
        html += '<label style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;cursor:pointer;">';
        html += '<input type="checkbox" id="metis-import-menus-cb" checked style="margin-top:2px;width:16px;height:16px;">';
        html += '<div><strong style="font-size:13px;">Menus</strong> <span class="metis-import-section-count">' + preview.menus_count + '</span><div style="font-size:12px;color:#888;margin-top:2px;">Navigation menus will be recreated</div></div>';
        html += '</label>';
    }
    if (preview.stats.media > 0) {
        html += '<div style="padding:8px 0;color:#888;font-size:13px;">&#9432; <strong>Media files</strong> (' + preview.stats.media + ') — media download is not yet supported. Image references will be preserved as external URLs.</div>';
    }

    html += '</div>';

    // Page list preview
    if (preview.pages && preview.pages.length > 0) {
        html += '<div class="metis-import-section"><div class="metis-import-section-title">Pages Preview <span class="metis-import-section-count">' + preview.pages.length + (preview.stats.pages > preview.pages.length ? '+' : '') + '</span></div>';
        html += '<div class="metis-import-item-list">';
        preview.pages.forEach(function(p) {
            var pid = Number(p.post_id || 0);
            var pageLabelId = 'metis-import-page-item-' + pid;
            html += '<div class="metis-import-item"><label for="' + pageLabelId + '" style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input id="' + pageLabelId + '" class="metis-import-page-item-cb" type="checkbox" data-id="' + pid + '" checked> <span>' + $('<div>').text(p.title).html() + '</span></label>';
            html += '<span style="display:flex;gap:6px;">';
            html += '<span style="font-size:11px;color:#888;">/' + $('<div>').text(p.slug).html() + '</span>';
            if (p.has_bb) html += '<span style="font-size:10px;background:#fff3cd;color:#856404;padding:1px 6px;border-radius:10px;">Beaver Builder</span>';
            html += '</span></div>';
        });
        html += '</div></div>';
    }

    if (preview.posts && preview.posts.length > 0) {
        html += '<div class="metis-import-section"><div class="metis-import-section-title">Posts Preview <span class="metis-import-section-count">' + preview.posts.length + (preview.stats.posts > preview.posts.length ? '+' : '') + '</span></div>';
        html += '<div class="metis-import-item-list">';
        preview.posts.forEach(function(p) {
            var pid = Number(p.post_id || 0);
            var postLabelId = 'metis-import-post-item-' + pid;
            html += '<div class="metis-import-item"><label for="' + postLabelId + '" style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input id="' + postLabelId + '" class="metis-import-post-item-cb" type="checkbox" data-id="' + pid + '" checked> <span>' + $('<div>').text(p.title).html() + '</span></label>';
            html += '<span style="display:flex;gap:6px;">';
            html += '<span style="font-size:11px;color:#888;">/' + $('<div>').text(p.slug).html() + '</span>';
            html += '</span></div>';
        });
        html += '</div></div>';
    }

    $('#metis-import-preview-content').html(html);
    if (window.Metis && Metis.a11y && typeof Metis.a11y.enhance === 'function') {
        Metis.a11y.enhance(document.getElementById('metis-import-preview-content'));
    }
}

$(document).off('change.metisImport', '.metis-import-page-item-cb').on('change.metisImport', '.metis-import-page-item-cb', function() {
    var id = Number($(this).data('id') || 0);
    if (id < 1) return;
    if ($(this).is(':checked')) {
        if (selectedPageIds.indexOf(id) === -1) selectedPageIds.push(id);
    } else {
        selectedPageIds = selectedPageIds.filter(function(x){ return x !== id; });
    }
});

$(document).off('change.metisImport', '.metis-import-post-item-cb').on('change.metisImport', '.metis-import-post-item-cb', function() {
    var id = Number($(this).data('id') || 0);
    if (id < 1) return;
    if ($(this).is(':checked')) {
        if (selectedPostIds.indexOf(id) === -1) selectedPostIds.push(id);
    } else {
        selectedPostIds = selectedPostIds.filter(function(x){ return x !== id; });
    }
});

// Back to upload
$(document).off('click.metisImport', '#metis-import-back-btn').on('click.metisImport', '#metis-import-back-btn', function() {
    selectedFile = null;
    $('#metis-import-drop-zone').show().css('border-color', '');
    $('#metis-import-file-selected').hide();
    setStep(1);
});

// Confirm import
$(document).off('click.metisImport', '#metis-import-confirm-btn').on('click.metisImport', '#metis-import-confirm-btn', function() {
    var ajaxCfg = getImportAjaxConfig();
    if (!ajaxCfg.nonce) {
        notify('Import security token is missing. Please refresh this page.', 'error');
        return;
    }
    var importPages = $('#metis-import-pages-cb').is(':checked') ? 1 : 0;
    var importPosts = $('#metis-import-posts-cb').is(':checked') ? 1 : 0;
    var importMenus = $('#metis-import-menus-cb').is(':checked') ? 1 : 0;

    if (!importPages && !importPosts && !importMenus) {
        notify('Please select at least one item type to import.', 'warning');
        return;
    }

    setStep(3);

    $.ajax({
        url: ajaxCfg.ajax_url,
        type: 'POST',
        data: {
            action: 'metis_import_confirm',
            nonce: ajaxCfg.nonce,
            metis_action_nonce: ajaxCfg.action_nonces.metis_import_confirm || '',
            metis_csrf_action: (ajaxCfg.action_nonces.metis_import_confirm || '') ? 'metis_ajax:metis_import_confirm' : 'metis_import',
            import_pages: importPages,
            import_posts: importPosts,
            import_menus: importMenus,
            selected_page_ids: JSON.stringify(selectedPageIds),
            selected_post_ids: JSON.stringify(selectedPostIds)
        },
        success: function(r) {
            if (r.success) {
                var res = r.data.results;
                var summary = [];
                if (res.pages)   summary.push(res.pages + ' page' + (res.pages !== 1 ? 's' : '') + ' created');
                if (res.posts)   summary.push(res.posts + ' post' + (res.posts !== 1 ? 's' : '') + ' created');
                if (res.menus)   summary.push(res.menus + ' menu' + (res.menus !== 1 ? 's' : '') + ' created');
                if (res.skipped) summary.push(res.skipped + ' skipped (may already exist)');
                $('#metis-import-results-summary').text(summary.join(' · ') || 'Nothing was imported.');

                if (res.errors && res.errors.length > 0) {
                    var errHtml = '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:12px;font-size:12px;">';
                    errHtml += '<div style="font-weight:700;margin-bottom:6px;color:#dc2626;">' + res.errors.length + ' error' + (res.errors.length !== 1 ? 's' : '') + ':</div>';
                    res.errors.forEach(function(e) { errHtml += '<div style="margin-bottom:3px;">• ' + $('<div>').text(e).html() + '</div>'; });
                    errHtml += '</div>';
                    $('#metis-import-errors').html(errHtml).show();
                }
                setStep(4);
            } else {
                setStep(2);
                notify((r.data && r.data.message) || 'Import failed.', 'error');
            }
        },
        error: function(xhr) {
            setStep(2);
            notify(extractAjaxError(xhr, 'Import request failed.'), 'error');
        }
    });
});

$(document).off('click.metisImport', '#metis-import-restart-btn').on('click.metisImport', '#metis-import-restart-btn', function() {
    selectedFile = null;
    $('#metis-import-drop-zone').show().css('border-color', '');
    $('#metis-import-file-selected').hide();
    $('#metis-import-preview-content').html('');
    $('#metis-import-errors').hide();
    setStep(1);
});

// Init
setStep(1);
bindNativeImportFallbacks();

})(jQuery);
