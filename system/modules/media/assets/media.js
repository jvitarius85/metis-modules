(function ($) {
    'use strict';
    var viewMode = 'grid';
    var facetState = { folders: [], categories: [] };
    var canEdit = false;
    var canDelete = false;
    var uploadInProgress = false;

    function ensureToastWrap() {
        var id = 'metis-toast-wrap';
        var node = document.getElementById(id);
        if (node) {
            return node;
        }
        node = document.createElement('div');
        node.id = id;
        node.className = 'metis-toast-wrap';
        document.body.appendChild(node);
        return node;
    }

    function toast(message, type) {
        if (typeof window.metisNotify === 'function') {
            window.metisNotify(message, type || 'info');
            return;
        }
        if (typeof window.metisShowToast === 'function') {
            window.metisShowToast(message, type || 'info');
            return;
        }
        var wrap = ensureToastWrap();
        var item = document.createElement('div');
        item.className = 'metis-toast is-' + (type || 'info');
        item.textContent = String(message || '');
        wrap.appendChild(item);
        window.setTimeout(function () {
            if (item.parentNode) {
                item.parentNode.removeChild(item);
            }
        }, 3600);
    }

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function isImage(mime) {
        return String(mime || '').indexOf('image/') === 0;
    }

    function formatBytes(bytes) {
        var value = Number(bytes || 0);
        if (!value || value < 1) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var unitIndex = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        var adjusted = value / Math.pow(1024, unitIndex);
        return adjusted.toFixed(adjusted >= 10 || unitIndex === 0 ? 0 : 1) + ' ' + units[unitIndex];
    }

    function formatDate(raw) {
        if (window.Metis && Metis.time && typeof Metis.time.format === 'function') {
            return Metis.time.format(raw, { empty: '' });
        }
        var date = raw ? new Date(raw) : null;
        if (!date || Number.isNaN(date.getTime())) {
            return '';
        }
        return date.toLocaleString();
    }

    function card(item) {
        var name = esc(item.file_name || 'file');
        var mime = esc(item.mime_type || '');
        var url = esc(item.url || '');
        var token = esc(item.token || '');
        var folder = esc(item.folder_path || '');
        var category = esc(item.category_key || '');
        var size = esc(formatBytes(item.size || 0));
        var createdAt = esc(formatDate(item.created_at || ''));
        var thumb = isImage(item.mime_type)
            ? '<img class="metis-media-thumb" src="' + url + '" alt="' + name + '">'
            : '<div class="metis-media-thumb-fallback">' + mime + '</div>';
        var taxonomy = '';
        if (folder || category) {
            taxonomy += '<div class="metis-media-taxonomy">';
            if (folder) taxonomy += '<span class="metis-media-chip">Folder: ' + folder + '</span>';
            if (category) taxonomy += '<span class="metis-media-chip">Category: ' + category + '</span>';
            taxonomy += '</div>';
        }

        var actionButtons = [
            '      <button type="button" class="metis-action-btn metis-media-preview" data-url="' + url + '" data-name="' + name + '" data-mime="' + mime + '">Preview</button>',
            '      <button type="button" class="metis-action-btn metis-media-copy" data-url="' + url + '">Copy URL</button>'
        ];
        if (canEdit) {
            actionButtons.push('      <button type="button" class="metis-action-btn metis-media-move" data-token="' + token + '" data-folder="' + folder + '" data-category="' + category + '">Organize</button>');
        }
        if (canDelete) {
            actionButtons.push('      <button type="button" class="metis-action-btn metis-action-btn-danger metis-media-delete" data-token="' + token + '">Delete</button>');
        }

        return [
            '<article class="metis-media-card">',
            '  <div class="metis-media-thumb-wrap">' + thumb + '</div>',
            '  <div class="metis-media-meta">',
            '    <div class="metis-media-name" title="' + name + '">' + name + '</div>',
            '    <div class="metis-media-mime">' + mime + '</div>',
            '    <div class="metis-media-meta-row"><span>' + size + '</span><span>' + createdAt + '</span></div>',
            taxonomy,
            '    <div class="metis-media-actions">',
            actionButtons.join(''),
            '    </div>',
            '  </div>',
            '</article>'
        ].join('');
    }

    function ajaxBase() {
        return window.metisMediaAjax || window.metisAjax || {};
    }

    function actionNonce(action) {
        var cfg = ajaxBase();
        var nonces = cfg && cfg.action_nonces && typeof cfg.action_nonces === 'object' ? cfg.action_nonces : {};
        if (action && nonces[action]) {
            return String(nonces[action]);
        }
        if (window.Metis && Metis.ajax && typeof Metis.ajax.nonceFor === 'function') {
            return String(Metis.ajax.nonceFor(action, (cfg && cfg.nonce) ? cfg.nonce : ''));
        }
        return String((cfg && cfg.nonce) ? cfg.nonce : '');
    }

    function loadMedia() {
        var cfg = ajaxBase();
        var $grid = $('#metis-media-grid');
        $grid.toggleClass('is-list', viewMode === 'list');

        $.ajax({
            url: cfg.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'metis_media_library_list',
                nonce: cfg.nonce,
                metis_action_nonce: actionNonce('metis_media_library_list'),
                search: ($('#metis-media-search').val() || '').trim(),
                type: ($('#metis-media-filter').val() || '').trim(),
                folder: ($('#metis-media-folder-filter').val() || '').trim(),
                category: ($('#metis-media-category-filter').val() || '').trim(),
                sort: ($('#metis-media-sort').val() || 'created_desc').trim(),
                limit: 120
            }
        }).done(function (response) {
            var items = response && response.success && response.data ? (response.data.items || []) : [];
            if (!items.length) {
                $grid.html('<div class="metis-media-empty">No media files found.</div>');
                return;
            }
            $grid.html(items.map(card).join(''));
        }).fail(function () {
            $grid.html('<div class="metis-media-empty">Failed to load media.</div>');
        });
    }

    function populateDatalist(id, items) {
        var node = document.getElementById(id);
        if (!node) return;
        node.innerHTML = (items || []).map(function (value) {
            return '<option value="' + esc(value) + '"></option>';
        }).join('');
    }

    function renderFacetList(listId, items, activeValue) {
        var node = document.getElementById(listId);
        if (!node) return;
        var values = Array.isArray(items) ? items : [];
        if (!values.length) {
            node.innerHTML = '<span class="metis-media-organizer-empty">No items</span>';
            return;
        }
        node.innerHTML = values.map(function (item) {
            var value = esc(item && item.value ? item.value : '');
            var count = Number(item && item.count ? item.count : 0);
            var active = String(activeValue || '') === String(item && item.value ? item.value : '') ? ' is-active' : '';
            return '<button type="button" class="metis-media-organizer-item' + active + '" data-value="' + value + '">' + value + ' (' + count + ')</button>';
        }).join('');
    }

    function normalizeFacetValue(type, value) {
        var raw = String(value || '').trim();
        if (!raw) return '';
        if (type === 'folder') {
            return raw.toLowerCase().replace(/\\/g, '/').replace(/[^a-z0-9/_-]+/g, '-').replace(/\/+/g, '/').replace(/^\/|\/$/g, '');
        }
        return raw.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');
    }

    function upsertFacet(type, value) {
        var normalized = normalizeFacetValue(type, value);
        if (!normalized) {
            return '';
        }
        var key = type === 'folder' ? 'folders' : 'categories';
        if (facetState[key].indexOf(normalized) === -1) {
            facetState[key].push(normalized);
            facetState[key].sort();
        }
        populateDatalist(type === 'folder' ? 'metis-media-folder-options' : 'metis-media-category-options', facetState[key]);
        return normalized;
    }

    function setUploadControlsBusy(isBusy) {
        $('#metis-media-upload-btn, #metis-media-upload-input, #metis-media-upload-folder, #metis-media-upload-category').prop('disabled', !!isBusy);
        $('#metis-media-dropzone')
            .prop('disabled', !!isBusy)
            .toggleClass('is-uploading', !!isBusy)
            .toggleClass('is-drag-over', false)
            .attr('aria-disabled', isBusy ? 'true' : 'false');
    }

    function setUploadProgress(isActive, percent, copy, title) {
        var pct = Math.max(0, Math.min(100, parseInt(String(percent || '0'), 10) || 0));
        var $wrap = $('#metis-media-upload-progress');
        if (!$wrap.length) return;
        $wrap.prop('hidden', !isActive);
        $wrap.attr('aria-busy', isActive ? 'true' : 'false');
        $('#metis-media-upload-progress-title').text(title || 'Uploading media');
        $('#metis-media-upload-progress-copy').text(copy || (isActive ? 'Uploading...' : 'Ready.'));
        $('#metis-media-upload-progress-bar').css('width', pct + '%');
        $wrap.find('[role="progressbar"]').attr('aria-valuenow', String(pct));
    }

    function openModal(id) {
        if (window.Metis && Metis.ui && Metis.ui.modal && typeof Metis.ui.modal.form === 'function') {
            Metis.ui.modal.form(id);
        }
    }

    function closeModal(id) {
        if (window.Metis && Metis.ui && Metis.ui.modal && typeof Metis.ui.modal.close === 'function') {
            Metis.ui.modal.close(id);
        }
    }

    function loadFacets() {
        var cfg = ajaxBase();
        $.ajax({
            url: cfg.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'metis_media_library_facets',
                nonce: cfg.nonce,
                metis_action_nonce: actionNonce('metis_media_library_facets')
            }
        }).done(function (response) {
            var data = response && response.success && response.data ? response.data : {};
            facetState.folders = Array.isArray(data.folders) ? data.folders.slice(0) : [];
            facetState.categories = Array.isArray(data.categories) ? data.categories.slice(0) : [];
            populateDatalist('metis-media-folder-options', facetState.folders);
            populateDatalist('metis-media-category-options', facetState.categories);
            renderFacetList('metis-media-folder-list', data.folder_counts || [], ($('#metis-media-folder-filter').val() || '').trim());
            renderFacetList('metis-media-category-list', data.category_counts || [], ($('#metis-media-category-filter').val() || '').trim());
        });
    }

    function uploadFiles(files) {
        if (!canEdit) {
            toast('You do not have permission to upload media.', 'error');
            return;
        }
        if (!files || !files.length) {
            return;
        }
        if (uploadInProgress) {
            toast('Upload already in progress.', 'info');
            return;
        }

        var cfg = ajaxBase();
        var queue = Array.prototype.slice.call(files);
        var total = queue.length;
        var completed = 0;
        var failed = 0;
        uploadInProgress = true;
        setUploadControlsBusy(true);
        setUploadProgress(true, 0, total === 1 ? 'Preparing 1 file...' : 'Preparing ' + total + ' files...');

        function responseErrorMessage(xhr, fallback) {
            var fallbackMessage = fallback || 'Upload failed.';
            if (!xhr) return fallbackMessage;
            var payload = xhr.responseJSON;
            if (payload && payload.data && payload.data.message) {
                return String(payload.data.message);
            }
            var raw = typeof xhr.responseText === 'string' ? xhr.responseText.trim() : '';
            if (!raw) return fallbackMessage;
            try {
                var parsed = JSON.parse(raw);
                if (parsed && parsed.data && parsed.data.message) {
                    return String(parsed.data.message);
                }
                if (parsed && parsed.message) {
                    return String(parsed.message);
                }
            } catch (e) {
                // Keep raw response as a fallback for non-JSON 500 pages.
            }
            return raw.slice(0, 240) || fallbackMessage;
        }

        function next() {
            if (!queue.length) {
                var successCount = Math.max(0, completed - failed);
                setUploadProgress(true, 100, successCount + ' uploaded' + (failed ? ', ' + failed + ' failed.' : '.'), 'Upload complete');
                uploadInProgress = false;
                setUploadControlsBusy(false);
                window.setTimeout(function () {
                    if (!uploadInProgress) setUploadProgress(false, 0, '');
                }, 1400);
                loadFacets();
                loadMedia();
                return;
            }

            var file = queue.shift();
            var currentNumber = completed + 1;
            var form = new FormData();
            form.append('action', 'metis_media_library_upload');
            form.append('nonce', cfg.nonce || '');
            form.append('metis_action_nonce', actionNonce('metis_media_library_upload'));
            form.append('folder_path', ($('#metis-media-upload-folder').val() || '').trim());
            form.append('category_key', ($('#metis-media-upload-category').val() || '').trim());
            form.append('file', file);
            setUploadProgress(true, Math.round((completed / total) * 100), 'Uploading ' + currentNumber + ' of ' + total + ': ' + (file.name || 'file'));

            $.ajax({
                url: cfg.ajax_url,
                method: 'POST',
                processData: false,
                contentType: false,
                dataType: 'json',
                data: form,
                xhr: function () {
                    var xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) {
                        xhr.upload.addEventListener('progress', function (event) {
                            if (!event.lengthComputable) return;
                            var fileRatio = event.total > 0 ? (event.loaded / event.total) : 0;
                            var batchPercent = Math.max(1, Math.min(99, Math.round(((completed + fileRatio) / total) * 100)));
                            setUploadProgress(true, batchPercent, 'Uploading ' + currentNumber + ' of ' + total + ': ' + (file.name || 'file'));
                        });
                    }
                    return xhr;
                }
            }).done(function (response, _statusText, xhr) {
                if (!(response && response.success)) {
                    failed += 1;
                    var doneMessage = responseErrorMessage(xhr, 'Upload failed for ' + (file.name || 'file') + '.');
                    toast(doneMessage, 'error');
                    if (window.console && typeof window.console.error === 'function') {
                        console.error('[Media Upload] failed response', response || xhr);
                    }
                }
            }).fail(function (xhr) {
                failed += 1;
                var failMessage = responseErrorMessage(xhr, 'Upload failed for ' + (file.name || 'file') + '.');
                toast(failMessage, 'error');
                if (window.console && typeof window.console.error === 'function') {
                    console.error('[Media Upload] xhr error', {
                        status: xhr && xhr.status,
                        responseText: xhr && xhr.responseText ? String(xhr.responseText).slice(0, 2000) : '',
                        responseJSON: xhr && xhr.responseJSON ? xhr.responseJSON : null
                    });
                }
            }).always(function () {
                completed += 1;
                next();
            });
        }

        next();
    }

    function bindEvents() {
        $('#metis-media-refresh-btn').on('click', loadMedia);

        $('#metis-media-search').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadMedia();
            }
        });

        $('#metis-media-filter, #metis-media-sort').on('change', loadMedia);
        $('#metis-media-folder-filter, #metis-media-category-filter').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadMedia();
                loadFacets();
            }
        });
        $('#metis-media-folder-filter, #metis-media-category-filter').on('change', loadFacets);

        $(document).on('click', '#metis-media-folder-list .metis-media-organizer-item', function () {
            $('#metis-media-folder-filter').val(String($(this).data('value') || ''));
            loadMedia();
            loadFacets();
        });

        $(document).on('click', '#metis-media-category-list .metis-media-organizer-item', function () {
            $('#metis-media-category-filter').val(String($(this).data('value') || ''));
            loadMedia();
            loadFacets();
        });

        $('#metis-media-clear-folder-filter').on('click', function () {
            $('#metis-media-folder-filter').val('');
            loadMedia();
            loadFacets();
        });

        $('#metis-media-clear-category-filter').on('click', function () {
            $('#metis-media-category-filter').val('');
            loadMedia();
            loadFacets();
        });

        $('#metis-media-upload-btn').on('click', function () {
            if (!canEdit || uploadInProgress) return;
            $('#metis-media-upload-input').trigger('click');
        });

        $('#metis-media-upload-input').on('change', function () {
            uploadFiles(this.files);
            $(this).val('');
        });

        $('#metis-media-view-grid').on('click', function () {
            viewMode = 'grid';
            $('#metis-media-view-list').removeClass('is-active');
            $('#metis-media-view-grid').addClass('is-active');
            $('#metis-media-view-grid').attr('aria-pressed', 'true');
            $('#metis-media-view-list').attr('aria-pressed', 'false');
            loadMedia();
        });

        $('#metis-media-view-list').on('click', function () {
            viewMode = 'list';
            $('#metis-media-view-grid').removeClass('is-active');
            $('#metis-media-view-list').addClass('is-active');
            $('#metis-media-view-list').attr('aria-pressed', 'true');
            $('#metis-media-view-grid').attr('aria-pressed', 'false');
            loadMedia();
        });

        $('#metis-media-dropzone').on('click', function (e) {
            e.preventDefault();
            if (!canEdit || uploadInProgress) return;
            $('#metis-media-upload-input').trigger('click');
        });

        $('#metis-media-dropzone').on('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (uploadInProgress) return;
            $(this).addClass('is-drag-over');
        });

        $('#metis-media-dropzone').on('dragleave', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('is-drag-over');
        });

        $('#metis-media-dropzone').on('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('is-drag-over');
            if (uploadInProgress) {
                toast('Upload already in progress.', 'info');
                return;
            }
            var files = (e.originalEvent && e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files) ? e.originalEvent.dataTransfer.files : null;
            uploadFiles(files);
        });

        $(document).on('click', '.metis-media-copy', function () {
            var url = $(this).data('url');
            if (!url) {
                return;
            }

            navigator.clipboard.writeText(String(url)).then(function () {
                toast('Media URL copied.', 'success');
            }, function () {
                toast('Unable to copy URL.', 'error');
            });
        });

        $(document).on('click', '.metis-media-delete', function () {
            if (!canDelete) return;
            var token = String($(this).data('token') || '');
            if (!token) {
                return;
            }
            $('#metis-media-delete-token').val(token);
            openModal('metis-media-confirm-modal');
        });

        $(document).on('click', '.metis-media-move', function () {
            if (!canEdit) return;
            var token = String($(this).data('token') || '');
            var currentFolder = String($(this).data('folder') || '');
            var currentCategory = String($(this).data('category') || '');

            if (!token) {
                return;
            }
            $('#metis-media-organize-token').val(token);
            $('#metis-media-organize-folder').val(currentFolder);
            $('#metis-media-organize-category').val(currentCategory);
            openModal('metis-media-organize-modal');
        });

        $(document).on('click', '.metis-media-preview', function () {
            var name = String($(this).data('name') || 'Preview');
            var url = String($(this).data('url') || '');
            var mime = String($(this).data('mime') || '');
            if (!url) {
                return;
            }
            $('#metis-media-preview-title').text(name);
            var body = '';
            if (isImage(mime)) {
                body = '<img src="' + esc(url) + '" alt="' + esc(name) + '" class="metis-media-preview-image">';
            } else {
                body = '<div class="metis-media-preview-non-image"><p>' + esc(mime || 'File preview unavailable') + '</p><a class="metis-btn metis-btn-primary" href="' + esc(url) + '" target="_blank" rel="noopener noreferrer">Open File</a></div>';
            }
            $('#metis-media-preview-body').html(body);
            openModal('metis-media-preview-modal');
        });

        $('#metis-media-preview-modal').on('click', function (e) {
            if (e.target === this) {
                closeModal('metis-media-preview-modal');
                $('#metis-media-preview-body').empty();
            }
        });

        $('#metis-media-new-folder-btn').on('click', function () {
            if (!canEdit) return;
            var value = $('#metis-media-upload-folder').val() || $('#metis-media-folder-filter').val() || '';
            var normalized = upsertFacet('folder', value);
            if (!normalized) {
                toast('Enter a folder value first (example: campaigns/2026).', 'error');
                return;
            }
            $('#metis-media-upload-folder').val(normalized);
            $('#metis-media-folder-filter').val(normalized);
            toast('Folder ready: ' + normalized, 'success');
        });

        $('#metis-media-new-category-btn').on('click', function () {
            if (!canEdit) return;
            var value = $('#metis-media-upload-category').val() || $('#metis-media-category-filter').val() || '';
            var normalized = upsertFacet('category', value);
            if (!normalized) {
                toast('Enter a category value first (example: hero).', 'error');
                return;
            }
            $('#metis-media-upload-category').val(normalized);
            $('#metis-media-category-filter').val(normalized);
            toast('Category ready: ' + normalized, 'success');
        });

        $('#metis-media-organize-modal').on('click', function (e) {
            if (e.target === this) {
                closeModal('metis-media-organize-modal');
            }
        });

        $('#metis-media-delete-cancel, #metis-media-confirm-modal').on('click', function (e) {
            if (e.target === this || e.target.id === 'metis-media-delete-cancel') {
                closeModal('metis-media-confirm-modal');
            }
        });

        $('#metis-media-organize-save').on('click', function () {
            if (!canEdit) return;
            var token = String($('#metis-media-organize-token').val() || '');
            var folder = upsertFacet('folder', $('#metis-media-organize-folder').val());
            var category = upsertFacet('category', $('#metis-media-organize-category').val());
            var cfg = ajaxBase();
            if (!token) {
                closeModal('metis-media-organize-modal');
                return;
            }
            $.ajax({
                url: cfg.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'metis_media_library_update_meta',
                    nonce: cfg.nonce,
                    metis_action_nonce: actionNonce('metis_media_library_update_meta'),
                    token: token,
                    folder_path: String(folder || ''),
                    category_key: String(category || '')
                }
            }).done(function (response) {
                if (response && response.success) {
                    closeModal('metis-media-organize-modal');
                    loadFacets();
                    loadMedia();
                    toast('Media organization updated.', 'success');
                    return;
                }
                toast('Unable to update media organization.', 'error');
            }).fail(function () {
                toast('Unable to update media organization.', 'error');
            });
        });

        $('#metis-media-delete-confirm').on('click', function () {
            if (!canDelete) return;
            var token = String($('#metis-media-delete-token').val() || '');
            var cfg = ajaxBase();
            if (!token) {
                closeModal('metis-media-confirm-modal');
                return;
            }
            $.ajax({
                url: cfg.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'metis_media_library_delete',
                    nonce: cfg.nonce,
                    metis_action_nonce: actionNonce('metis_media_library_delete'),
                    token: token
                }
            }).done(function (response) {
                if (response && response.success) {
                    closeModal('metis-media-confirm-modal');
                    loadFacets();
                    loadMedia();
                    toast('Media deleted.', 'success');
                    return;
                }
                toast('Unable to delete media.', 'error');
            }).fail(function () {
                toast('Unable to delete media.', 'error');
            });
        });
    }

    $(function () {
        if (!document.getElementById('metis-media-grid')) {
            return;
        }

        var wrap = document.querySelector('.metis-table-wrap');
        canEdit = !!(wrap && wrap.getAttribute('data-can-edit') === '1');
        canDelete = !!(wrap && wrap.getAttribute('data-can-delete') === '1');

        bindEvents();
        loadFacets();
        loadMedia();
    });
})(jQuery);
