(function (window, document) {
    'use strict';

    function s(v) {
        if (typeof v === 'string') return v;
        if (typeof v === 'number' || typeof v === 'boolean') return String(v);
        return '';
    }
    function j(raw, fallback) { try { return JSON.parse(raw); } catch (_e) { return fallback; } }
    function esc(v) {
        return s(v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
    function formatConfiguredDateTime(value, fallback) {
        if (window.Metis && Metis.time && typeof Metis.time.format === 'function') {
            return Metis.time.format(value, { empty: fallback || '' }) || fallback || '';
        }
        var raw = value instanceof Date ? value : new Date(s(value).replace(' ', 'T'));
        return raw && !isNaN(raw.getTime()) ? raw.toLocaleString() : (fallback || '');
    }
    function formatConfiguredTime(value, fallback) {
        if (window.Metis && Metis.time && typeof Metis.time.formatTime === 'function') {
            return Metis.time.formatTime(value, { empty: fallback || '' }) || fallback || '';
        }
        var raw = value instanceof Date ? value : new Date(s(value).replace(' ', 'T'));
        return raw && !isNaN(raw.getTime()) ? raw.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : (fallback || '');
    }
    function uid() { return 'sec_' + Date.now() + '_' + Math.floor(Math.random() * 10000); }
    function slugifyValue(value) {
        return s(value)
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .trim()
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');
    }
    function normalizePathSlugValue(value) {
        var raw = s(value).trim().replace(/^\/+|\/+$/g, '');
        return slugifyValue(raw);
    }
    function toDateTimeInputValue(value) {
        var raw = s(value).trim();
        if (!raw) return '';
        return raw.replace(' ', 'T').slice(0, 16);
    }
    function fromDateTimeInputValue(value) {
        var raw = s(value).trim();
        if (!raw) return '';
        return raw.replace('T', ' ') + ':00';
    }
    function inlineImageSizeClass(value) {
        var raw = s(value).toLowerCase().trim();
        if (raw === 'small' || raw === 'medium' || raw === 'large' || raw === 'full') return raw;
        return 'medium';
    }
    var sharedRichSelections = {};
    var sharedRichColorSelections = {};
    var SHARED_EMOJI_SHORTCODES = {
        ':wave:': '👋',
        ':sparkles:': '✨',
        ':heart:': '❤️',
        ':star:': '⭐',
        ':fire:': '🔥',
        ':check:': '✔️',
        ':point_right:': '👉',
        ':point_left:': '👈',
        ':ear:': '👂',
        ':microphone:': '🎤',
        ':calendar:': '📅',
        ':tada:': '🎉',
        ':smile:': '😊'
    };
    function emojiValueFromInput(value) {
        var raw = s(value || '').trim();
        if (!raw) return '';
        return SHARED_EMOJI_SHORTCODES[raw.toLowerCase()] || raw;
    }
    function requestPrompt(options) {
        if (typeof window.metis_prompt === 'function') {
            return window.metis_prompt(options || {});
        }
        if (window.Metis && Metis.prompt && typeof Metis.prompt.open === 'function') {
            return Metis.prompt.open(options || {});
        }
        return Promise.resolve(null);
    }
    function requestLinkUrl() {
        return requestPrompt({
            title: 'Insert Link',
            label: 'URL',
            message: 'Enter link URL.',
            defaultValue: 'https://',
            placeholder: 'https://',
            required: true,
            confirmLabel: 'Insert'
        }).then(function(value) {
            return s(value).trim();
        });
    }
    function requestEmoji() {
        return requestPrompt({
            title: 'Insert Emoji',
            label: 'Emoji or shortcode',
            message: 'Enter an emoji or shortcode like :sparkles:.',
            defaultValue: ':sparkles:',
            placeholder: '✨ or :sparkles:',
            required: true,
            confirmLabel: 'Insert'
        }).then(function(value) {
            return emojiValueFromInput(value);
        });
    }
    function requestInlineImageSize() {
        return requestPrompt({
            title: 'Inline Image Size',
            label: 'Size',
            message: 'Enter small, medium, large, or full.',
            defaultValue: 'medium',
            placeholder: 'medium',
            required: true,
            confirmLabel: 'Apply'
        }).then(function(value) {
            if (value == null) return null;
            return inlineImageSizeClass(value);
        });
    }
    function saveRichSelection(target) {
        if (!target || !target.id) return;
        var sel = window.getSelection ? window.getSelection() : null;
        if (!sel || !sel.rangeCount) return;
        var range = sel.getRangeAt(0);
        if (!target.contains(range.commonAncestorContainer)) return;
        sharedRichSelections[target.id] = range.cloneRange();
    }
    function restoreRichSelection(target) {
        if (!target || !target.id) return false;
        var sel = window.getSelection ? window.getSelection() : null;
        var range = sharedRichSelections[target.id];
        if (!sel || !range) return false;
        try {
            sel.removeAllRanges();
            sel.addRange(range);
            return true;
        } catch (_err) {
            return false;
        }
    }
    function selectedHtmlFromRange(range) {
        if (!range) return '';
        var wrap = document.createElement('div');
        wrap.appendChild(range.cloneContents());
        return wrap.innerHTML;
    }
    function normalizeStyledSelectionHtml(html, prefix) {
        var wrap = document.createElement('div');
        wrap.innerHTML = s(html || '');
        wrap.querySelectorAll('span').forEach(function (node) {
            Array.prototype.slice.call(node.classList || []).forEach(function (className) {
                if (s(className || '').indexOf(prefix) === 0) {
                    node.classList.remove(className);
                }
            });
            if (!node.className.trim()) {
                while (node.firstChild) node.parentNode.insertBefore(node.firstChild, node);
                node.parentNode.removeChild(node);
            }
        });
        return wrap.innerHTML;
    }
    function insertHtmlAtSelection(target, html) {
        if (!target) return;
        target.focus();
        restoreRichSelection(target);
        if (document.execCommand) {
            document.execCommand('insertHTML', false, html);
            saveRichSelection(target);
        }
    }
    function applySpanPreset(target, prefix, value) {
        if (!target) return;
        target.focus();
        restoreRichSelection(target);
        var sel = window.getSelection ? window.getSelection() : null;
        if (!sel || !sel.rangeCount) return;
        var range = sel.getRangeAt(0);
        if (range.collapsed || !target.contains(range.commonAncestorContainer)) return;
        var html = normalizeStyledSelectionHtml(selectedHtmlFromRange(range), prefix);
        if (s(value || '') !== 'default') {
            html = '<span class="' + prefix + s(value || '') + '">' + html + '</span>';
        }
        range.deleteContents();
        document.execCommand('insertHTML', false, html);
        saveRichSelection(target);
    }
    function wrapSelectionWithStyle(target, styleText) {
        if (!target || !styleText) return false;
        target.focus();
        restoreRichSelection(target);
        var sel = window.getSelection ? window.getSelection() : null;
        if (!sel || !sel.rangeCount) return false;
        var range = sel.getRangeAt(0);
        if (range.collapsed || !target.contains(range.commonAncestorContainer)) return false;
        var html = selectedHtmlFromRange(range);
        range.deleteContents();
        document.execCommand('insertHTML', false, '<span style="' + esc(styleText) + '">' + html + '</span>');
        saveRichSelection(target);
        return true;
    }
    function resolveEditorThemeColor(value, fallback) {
        var raw = s(value || '').trim();
        if (!raw || raw === 'default') return s(fallback || '');
        if (/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(raw)) return raw;
        if (/^(rgb|hsl)a?\(/i.test(raw)) return raw;
        var cssVar = raw.indexOf('metis_') === 0 ? '--' + raw.replace(/_/g, '-') : raw;
        var computed = window.getComputedStyle ? window.getComputedStyle(document.documentElement).getPropertyValue(cssVar) : '';
        computed = s(computed || '').trim();
        return computed || s(fallback || '');
    }
    function imageMediaOptions() {
        return (state.options.media || []).filter(function (row) {
            return s(row && (row.mime || row.mime_type) || '').toLowerCase().indexOf('image/') === 0;
        });
    }
    function normalizeIdList(raw) {
        var list = raw;
        if (typeof list === 'string') {
            try {
                list = JSON.parse(list);
            } catch (_err) {
                list = list.split(',').map(function (value) { return s(value).trim(); }).filter(Boolean);
            }
        }
        if (!Array.isArray(list)) return [];
        var seen = {};
        return list.map(function (value) { return parseInt(s(value || '0'), 10) || 0; }).filter(function (value) {
            if (value < 1 || seen[value]) return false;
            seen[value] = true;
            return true;
        });
    }
    function categoryOptions() {
        return Array.isArray(state.options.categories) ? state.options.categories : [];
    }
    function categoryLabelById(id) {
        var targetId = parseInt(s(id || '0'), 10) || 0;
        var label = '';
        categoryOptions().some(function (row) {
            var rowId = parseInt(s(row && row.value || '0'), 10) || 0;
            if (rowId === targetId) {
                label = s(row && (row.name || row.label) || '');
                return true;
            }
            return false;
        });
        return label.replace(/^[-–—\s]+/, '');
    }
    function categoryOptionById(id) {
        var targetId = parseInt(s(id || '0'), 10) || 0;
        var found = null;
        categoryOptions().some(function (row) {
            var rowId = parseInt(s(row && row.value || '0'), 10) || 0;
            if (rowId === targetId) {
                found = row || null;
                return true;
            }
            return false;
        });
        return found;
    }
    function slugTitlePreviewPath() {
        if (!isPostContext()) return '';
        var ids = selectedCategoryIds('metis-v2-category-ids');
        var primary = ids.length ? categoryOptionById(ids[0]) : null;
        var segments = [];
        if (primary) {
            var parentSlug = s(primary.parent_slug || '');
            var slug = s(primary.slug || '');
            if (parentSlug) segments.push(parentSlug);
            if (slug) segments.push(slug);
        }
        var publishedEl = document.getElementById('metis-v2-published-date');
        var publishedValue = s(publishedEl && publishedEl.value || '');
        var year = '';
        var iso = s(publishedValue).trim();
        if (iso) iso = iso.replace('T', ' ') + ':00';
        if (iso && /^\d{4}/.test(iso)) {
            year = iso.slice(0, 4);
        }
        if (!year) {
            var entityDate = s(state.entity && (state.entity.published_date || state.entity.publish_date) || '');
            if (/^\d{4}/.test(entityDate)) year = entityDate.slice(0, 4);
        }
        if (!year) year = String((new Date()).getFullYear());
        var slugEl = document.getElementById('metis-v2-slug');
        var slug = slugifyValue(s(slugEl && slugEl.value || '').trim().replace(/^\/+|\/+$/g, ''));
        if (slug) segments.push(year, slug);
        return segments.length ? '/' + segments.join('/') : '';
    }
    function renderPostPathPreview() {
        var el = document.getElementById('metis-v2-post-path-preview');
        if (!el || !isPostContext()) return;
        var path = slugTitlePreviewPath();
        el.textContent = path || 'Select a primary category to generate the public path.';
    }
    function syncPublishedDateField() {
        var statusEl = document.getElementById('metis-v2-status');
        if (statusEl) {
            if (hasPublishedVersion()) {
                statusEl.value = 'published';
                statusEl.disabled = true;
                statusEl.title = 'This item is already live. Autosave keeps draft changes separate until you publish the current draft.';
            } else {
                statusEl.disabled = false;
                statusEl.title = '';
            }
        }
        var el = document.getElementById('metis-v2-published-date');
        if (!el || !isPostContext()) return;
        var status = s(statusEl && statusEl.value || state.entity && state.entity.status || 'draft');
        var existingPublished = s(state.entity && (state.entity.published_date || state.entity.publish_date) || '') !== '';
        var lock = status === 'published' && existingPublished;
        el.readOnly = lock;
        el.disabled = lock;
        el.title = lock ? 'Published Date is fixed after first publish.' : '';
    }
    function hasPublishedVersion() {
        var entity = state.entity && typeof state.entity === 'object' ? state.entity : null;
        if (!entity) return false;
        if (s(entity.status || '') === 'published') return true;
        if (s(entity.published_date || entity.publish_date || '') !== '') return true;
        if (s(entity.published_content_json || entity.published_layout_json || '') !== '') return true;
        return false;
    }
    function updateTopTitle(value) {
        var el = document.getElementById('metis-se-top-title');
        if (!el) return;
        var label = s(value || '').trim() || s(state.entity && state.entity.title || '').trim() || (isPostContext() ? 'Post Name' : 'Page Name');
        el.textContent = label;
    }
    function categoryChipField(fieldId, selectedIds, emptyText) {
        var ids = normalizeIdList(selectedIds);
        var options = categoryOptions();
        var chips = options.map(function (row) {
            var rowId = parseInt(s(row && row.value || '0'), 10) || 0;
            var active = ids.indexOf(rowId) !== -1;
            var label = s(row && (row.name || row.label) || 'Category ' + rowId);
            return '<button type="button" class="metis-se-chip' + (active ? ' is-selected' : '') + '" data-category-chip-field="' + esc(fieldId) + '" data-category-chip-id="' + esc(String(rowId)) + '" aria-pressed="' + (active ? 'true' : 'false') + '">' + esc(label) + '</button>';
        }).join('');
        return '<div class="metis-se-chip-selector">' +
            '<input type="hidden" id="' + esc(fieldId) + '" value="' + esc(JSON.stringify(ids)) + '">' +
            '<div class="metis-se-chip-list">' + (chips || '<div class="metis-se-chip-empty">' + esc(emptyText || 'No categories available.') + '</div>') + '</div>' +
        '</div>';
    }
    function selectedCategoryIds(fieldId) {
        var el = document.getElementById(fieldId);
        return normalizeIdList(el && el.value || '[]');
    }
    function setSelectedCategoryIds(fieldId, ids) {
        var el = document.getElementById(fieldId);
            if (el) el.value = JSON.stringify(normalizeIdList(ids));
        var host = el && el.closest ? el.closest('.metis-se-chip-selector') : null;
        if (!host) return;
        host.querySelectorAll('[data-category-chip-id]').forEach(function (chip) {
            var chipId = parseInt(s(chip.getAttribute('data-category-chip-id') || '0'), 10) || 0;
            var active = normalizeIdList(ids).indexOf(chipId) !== -1;
            chip.classList.toggle('is-selected', active);
            chip.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        renderPostPathPreview();
    }
    function featuredImageMediaById(id) {
        var targetId = parseInt(s(id || '0'), 10) || 0;
        var found = null;
        if (targetId < 1) return null;
        imageMediaOptions().some(function (row) {
            var rowId = parseInt(s(row && (row.id || row.media_id || '0') || '0'), 10) || 0;
            if (rowId === targetId) {
                found = row;
                return true;
            }
            return false;
        });
        return found;
    }
    function uniqueImageMimeOptions(rows) {
        var seen = {};
        var options = [];
        (rows || []).forEach(function (row) {
            var mime = s(row && (row.mime || row.mime_type) || '').toLowerCase();
            if (!mime || seen[mime]) return;
            seen[mime] = true;
            options.push(mime);
        });
        return options.sort();
    }

    function normalizeMediaOption(row) {
        var data = row && typeof row === 'object' ? row : {};
        var id = parseInt(s(data.id || data.media_id || '0'), 10) || 0;
        var label = s(data.label || data.file_name || data.name || 'Image');
        var mime = s(data.mime || data.mime_type || data.type || '');
        var url = s(data.url || data.src || '');
        var token = s(data.value || data.token || data.public_token || '');
        return {
            id: id,
            media_id: id,
            value: token,
            label: label,
            file_name: label,
            url: url,
            mime: mime,
            mime_type: mime
        };
    }

    var boot = document.getElementById('metis-editor-bootstrap');
    if (!boot) return;
    var bootSignature = [
        s(window.location.pathname || ''),
        s(boot.getAttribute('data-editor-context') || ''),
        s(boot.getAttribute('data-editor-kind') || ''),
        s(boot.getAttribute('data-editor-key') || ''),
        s(boot.getAttribute('data-editor-id') || ''),
        s(boot.getAttribute('data-editor-new') || '')
    ].join('|');
    if (window.__metisSimpleEditorBooted && window.__metisSimpleEditorBootSignature === bootSignature) {
        return;
    }
    window.__metisSimpleEditorBooted = true;
    window.__metisSimpleEditorBootSignature = bootSignature;

    var root = document.getElementById('metis-simple-editor-root');
    if (root && s(root.getAttribute('data-editor-boot-signature')) !== bootSignature) {
        if (root.parentNode) {
            root.parentNode.removeChild(root);
        }
        root = null;
    }
    if (!root) {
        root = document.createElement('div');
        root.id = 'metis-simple-editor-root';
        var inline = document.getElementById('metis-editor-inline-root');
        if (inline) inline.appendChild(root);
        else document.body.appendChild(root);
    }
    root.setAttribute('data-editor-boot-signature', bootSignature);

    function hideBootStatus() {
        var bootStatus = document.getElementById('metis-editor-boot-status');
        if (!bootStatus) return;
        bootStatus.classList.add('metis-u-hidden');
        bootStatus.style.display = 'none';
        if (bootStatus.parentNode) {
            bootStatus.parentNode.removeChild(bootStatus);
        }
    }
    hideBootStatus();

    var state = {
        context: s(boot.getAttribute('data-editor-context') || 'website').toLowerCase(),
        kind: s(boot.getAttribute('data-editor-kind') || '').toLowerCase(),
        key: s(boot.getAttribute('data-editor-key') || '').trim(),
        id: parseInt(s(boot.getAttribute('data-editor-id') || '0'), 10) || 0,
        nonce: s(boot.getAttribute('data-editor-nonce') || '').trim(),
        actionNonces: j(s(boot.getAttribute('data-editor-action-nonces') || '{}'), {}),
        entity: null,
        entityLoaded: false,
        saving: false,
        queuedSave: null,
        autosaveTimer: null,
        dirty: false,
        step: 1,
        activeSection: 0,
        sections: [],
        postContent: '',
        postBlocks: [],
        options: {
            parentPages: [],
            authors: [],
            categories: [],
            forms: [],
            donationCampaigns: [],
            calendarSources: [],
            media: [],
            templates: [],
            activeTemplate: { key: '', label: '' },
            defaultTemplateKey: ''
        },
        featuredImageFilter: {
            search: '',
            mime: ''
        },
        inlineImageFilter: {
            search: '',
            mime: ''
        },
        inlineImageTargetId: '',
        mediaUpload: {
            active: false,
            context: '',
            percent: 0
        },
        selectedTemplateKey: '',
        blockLibrarySearch: '',
        blockPickerIndex: null,
        activeRichTargetId: '',
        pendingBlockType: 'text',
        slugManuallyEdited: false,
        loadingProperties: false,
        contentTouched: false,
        sectionsTouched: false,
        canEdit: s(boot.getAttribute('data-editor-can-edit') || '1') !== '0',
        canPublish: s(boot.getAttribute('data-editor-can-publish') || '1') !== '0',
        canCreate: s(boot.getAttribute('data-editor-can-create') || '1') !== '0',
        canManageMedia: s(boot.getAttribute('data-editor-can-manage-media') || '0') === '1'
    };

    var initialEntity = j(s(boot.getAttribute('data-editor-initial-entity') || ''), null);
    var initialOptions = j(s(boot.getAttribute('data-editor-initial-options') || ''), null);
    if (initialEntity && typeof initialEntity === 'object') {
        state.entity = initialEntity;
        state.entityLoaded = true;
        state.id = parseInt(s(initialEntity.id || state.id || '0'), 10) || state.id;
        if (isPostContext()) {
            state.key = s(initialEntity.post_code || state.key);
        } else if (isPageContext()) {
            state.key = s(initialEntity.page_code || state.key);
        }
    }
    if (initialOptions && typeof initialOptions === 'object') {
        state.options = {
            parentPages: Array.isArray(initialOptions.parent_pages) ? initialOptions.parent_pages : [],
            authors: Array.isArray(initialOptions.authors) ? initialOptions.authors : [],
            categories: Array.isArray(initialOptions.categories) ? initialOptions.categories : [],
            forms: Array.isArray(initialOptions.forms) ? initialOptions.forms : [],
            donationCampaigns: Array.isArray(initialOptions.donation_campaigns) ? initialOptions.donation_campaigns : [],
            calendarSources: Array.isArray(initialOptions.calendar_sources) ? initialOptions.calendar_sources : [],
            media: Array.isArray(initialOptions.media) ? initialOptions.media : [],
            templates: Array.isArray(initialOptions.templates) ? initialOptions.templates : [],
            activeTemplate: initialOptions.active_template && typeof initialOptions.active_template === 'object'
                ? { key: s(initialOptions.active_template.key || ''), label: s(initialOptions.active_template.label || '') }
                : { key: '', label: '' },
            defaultTemplateKey: s(initialOptions.default_template_key || '')
        };
    }

    if (!state.actionNonces || typeof state.actionNonces !== 'object') {
        state.actionNonces = {};
    }

    function isPageContext() { return state.context === 'website' || state.context === 'website_page'; }
    function isPostContext() { return state.context === 'post' || state.context === 'website_post'; }
    function isManagedContentContext() { return isPageContext() || isPostContext(); }
    function isTemplateContext() { return state.context === 'template'; }
    function isNewsletterContext() { return state.context.indexOf('newsletter') === 0; }
    function isNewEntityRoute() { return !state.key && (!state.id || state.id < 1); }
    function contentModuleSlug() { return 'website'; }
    function contentAction(suffix) { return 'metis_website_' + suffix; }
    function contentLabel() { return 'Website'; }
    function contentTypeLabel() { return isPostContext() ? 'Post' : 'Page'; }
    function contentSettingsLabel() { return contentTypeLabel() + ' Settings'; }
    function inferRefFromPath() {
        var pathname = s(window.location.pathname || '');
        if (!pathname) return { key: '', id: 0 };
        var match = null;
        if (isPostContext()) {
            match = pathname.match(/\/website\/posts\/editor\/([A-Za-z0-9_-]+)\/?$/i);
        } else if (isPageContext()) {
            match = pathname.match(/\/website\/pages\/editor\/([A-Za-z0-9_-]+)\/?$/i);
        }
        if (!match || !match[1]) return { key: '', id: 0 };
        var ref = s(match[1]).trim();
        if (!ref || /^new$/i.test(ref)) return { key: '', id: 0 };
        if (/^\d+$/.test(ref)) return { key: '', id: parseInt(ref, 10) || 0 };
        return { key: ref, id: 0 };
    }

    function hydrateRefFromPathIfMissing() {
        if (state.key || (state.id && state.id > 0)) return;
        var inferred = inferRefFromPath();
        if (inferred.id > 0) state.id = inferred.id;
        if (inferred.key) state.key = inferred.key;
    }

    function appBasePath() {
        var website = editorModuleAjaxConfig();
        var core = window.metisAjax || {};
        var metisAjax = window.Metis && window.Metis.ajax ? window.Metis.ajax : null;
        var ajax = s((metisAjax && metisAjax.url) || website.ajax_url || core.ajax_url || '');
        if (ajax) {
            try {
                var url = new URL(ajax, window.location.origin);
                var path = s(url.pathname || '').replace(/\/api\/ajax\/?$/i, '');
                return path.replace(/\/+$/, '');
            } catch (_err) {}
        }
        var pathname = s(window.location.pathname || '');
        var adminPos = pathname.toLowerCase().indexOf('/admin/');
        if (adminPos > -1) return pathname.slice(0, adminPos).replace(/\/+$/, '');
        return '';
    }

    function resolveAjaxUrl() {
        var website = editorModuleAjaxConfig();
        var core = window.metisAjax || {};
        var metisAjax = window.Metis && window.Metis.ajax ? window.Metis.ajax : null;
        var ajax = s((metisAjax && metisAjax.url) || website.ajax_url || core.ajax_url || '');
        if (ajax) {
            try {
                var url = new URL(ajax, window.location.href);
                return window.location.origin + s(url.pathname || '/api/ajax') + s(url.search || '');
            } catch (_err) {
                return ajax;
            }
        }
        return appBasePath() + '/api/ajax';
    }

    function iconUrl(slug) {
        return appBasePath() + '/svg/' + encodeURIComponent(s(slug || '').replace(/_/g, '-'));
    }

    function iconFallbackUrl(slug) {
        return appBasePath() + '/assets/Images/icons/' + encodeURIComponent(s(slug || '')) + '.svg';
    }

    function bindIconFallbacks(scope) {
        var rootNode = scope && scope.querySelectorAll ? scope : document;
        rootNode.querySelectorAll('img[data-icon-fallback]').forEach(function (img) {
            if (img.getAttribute('data-fallback-bound') === '1') return;
            img.setAttribute('data-fallback-bound', '1');
            img.addEventListener('error', function () {
                var fallback = s(img.getAttribute('data-icon-fallback') || '');
                if (!fallback || img.getAttribute('src') === fallback) return;
                img.setAttribute('src', fallback);
            });
        });
    }

    function defaultCsrfAction() {
        if (isNewsletterContext()) return 'metis_newsletter';
        return 'metis_website';
    }

    function editorModuleAjaxConfig() {
        return window.metisWebsiteAjax || {};
    }

    function ajaxConfig(action) {
        var website = editorModuleAjaxConfig();
        var core = window.metisAjax || {};
        var metisAjax = window.Metis && window.Metis.ajax ? window.Metis.ajax : null;
        var nonces = {};
        var websiteNonces = website.action_nonces && typeof website.action_nonces === 'object' ? website.action_nonces : {};
        var coreNonces = core.action_nonces && typeof core.action_nonces === 'object' ? core.action_nonces : {};
        Object.keys(coreNonces).forEach(function (k) { nonces[k] = coreNonces[k]; });
        Object.keys(websiteNonces).forEach(function (k) { nonces[k] = websiteNonces[k]; });
        Object.keys(state.actionNonces).forEach(function (k) { nonces[k] = state.actionNonces[k]; });

        var runtimeActionNonce = '';
        if (metisAjax && typeof metisAjax.nonceFor === 'function') runtimeActionNonce = s(metisAjax.nonceFor(action, ''));
        var actionNonce = s(nonces[action] || runtimeActionNonce || '');
        var baseNonce = s(website.nonce || core.nonce || (metisAjax && metisAjax.nonce) || state.nonce || '');
        var hasActionNonce = actionNonce !== '';

        return {
            ajax_url: resolveAjaxUrl(),
            nonce: baseNonce,
            action_nonce: hasActionNonce ? actionNonce : s(state.nonce || baseNonce),
            csrf_action: hasActionNonce ? ('metis_ajax:' + action) : defaultCsrfAction()
        };
    }

    function request(action, payload) {
        var cfg = ajaxConfig(action);
        var data = payload || {};
        data.action = action;
        data.nonce = cfg.nonce;
        data.metis_action_nonce = cfg.action_nonce || '';
        data.metis_csrf_action = cfg.csrf_action || defaultCsrfAction();

        function errObj(message, code) {
            var err = new Error(s(message) || 'Request failed.');
            if (code) err.metisCode = s(code);
            return err;
        }

        function parseFail(raw) {
            if (!raw || typeof raw !== 'object') return { message: 'Request failed.', code: '' };
            var d = raw.data && typeof raw.data === 'object' ? raw.data : null;
            var dataMessage = typeof raw.data === 'string' ? s(raw.data) : '';
            var msg = d && d.message ? s(d.message) : (dataMessage || (raw.message ? s(raw.message) : 'Request failed.'));
            var code = d && d.code ? s(d.code) : (raw.code ? s(raw.code) : '');
            return { message: msg || 'Request failed.', code: code };
        }

        function bodyValue(v) {
            if (typeof v === 'string') return v;
            if (typeof v === 'number' || typeof v === 'boolean') return String(v);
            if (v === null || typeof v === 'undefined') return '';
            if (typeof v === 'object') {
                try { return JSON.stringify(v); } catch (_e) { return ''; }
            }
            return '';
        }

        return new Promise(function (resolve, reject) {
            if (window.jQuery && window.jQuery.ajax) {
                window.jQuery.ajax({
                    url: cfg.ajax_url,
                    type: 'POST',
                    timeout: 45000,
                    data: data,
                    success: function (resp) {
                        if (typeof resp === 'string' && resp.trim().length) {
                            try { resp = JSON.parse(resp); } catch (_e) {}
                        }
                        if (!resp || !resp.success) {
                            var fail = parseFail(resp || {});
                            reject(errObj(fail.message, fail.code));
                            return;
                        }
                        resolve(resp.data || {});
                    },
                    error: function (xhr, textStatus) {
                        var text = s(xhr && xhr.responseText || '');
                        if (textStatus === 'timeout') { reject(errObj('Request timed out.', 'timeout')); return; }
                        if (text) {
                            try {
                                var parsed = JSON.parse(text);
                                if (parsed) {
                                    var fail = parseFail(parsed);
                                    reject(errObj(fail.message, fail.code));
                                    return;
                                }
                            } catch (_e) {}
                        }
                        reject(errObj('Network request failed.', 'network_error'));
                    }
                });
                return;
            }

            var body = new URLSearchParams();
            Object.keys(data).forEach(function (key) { body.set(key, bodyValue(data[key])); });
            fetch(cfg.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (res) {
                return res.text();
            }).then(function (text) {
                var parsed = null;
                try { parsed = JSON.parse(s(text)); } catch (_err) {}
                if (!parsed || !parsed.success) {
                    var fail = parseFail(parsed || {});
                    reject(errObj(fail.message, fail.code));
                    return;
                }
                resolve(parsed.data || {});
            }).catch(function () {
                reject(errObj('Network request failed.', 'network_error'));
            });
        });
    }

    function setStatus(text, kind) {
        var el = document.getElementById('metis-se-save-status');
        if (!el) return;
        var clean = s(text);
        if (kind === 'ok' && (clean === 'Ready' || clean === 'Loaded')) {
            clean = '';
        }
        el.textContent = clean;
        el.classList.remove('is-error', 'is-ok', 'is-saving');
        el.style.display = clean ? 'inline-flex' : 'none';
        if (kind === 'error') el.classList.add('is-error');
        else if (kind === 'saving') el.classList.add('is-saving');
        else if (kind === 'ok') el.classList.add('is-ok');
    }

    function detectContext() {
        var p = s(window.location.pathname || '').toLowerCase();
        if (p.indexOf('/newsletter/editor/') !== -1 || state.context.indexOf('newsletter') === 0) {
            if (p.indexOf('/template/') !== -1 || state.kind === 'template' || state.context === 'newsletter_template') return 'newsletter_template';
            return 'newsletter';
        }
        if (
            state.context === 'website_post' ||
            p.indexOf('/website/posts/editor/') !== -1 ||
            p.indexOf('/editor/post/') !== -1 ||
            state.context === 'post'
        ) {
            return 'post';
        }
        if (
            state.context === 'website_page' ||
            p.indexOf('/website/pages/editor/') !== -1 ||
            p.indexOf('/editor/page/') !== -1 ||
            state.context === 'website'
        ) {
            return 'website';
        }
        if (p.indexOf('/editor/template/') !== -1 || state.context === 'template') return 'template';
        return 'website';
    }
    state.context = detectContext();
    hydrateRefFromPathIfMissing();

    function bootNewsletterEditor() {
        var isTemplate = state.kind === 'template' || state.context === 'newsletter_template';
        var newsletterData = j(s(boot.getAttribute('data-editor-initial-options') || '{}'), {});
        var previewTimer = null;
        var newsletterPreviewBooted = false;
        var MERGE_TAGS = [
            '{{first_name}}', '{{last_name}}', '{{full_name}}', '{{name}}', '{{email}}',
            '{{campaign_name}}', '{{city}}', '{{state}}', '{{unsubscribe_url}}', '{{manage_subscription_url}}'
        ];
        var NEWSLETTER_AUDIENCE_FIELDS = [
            ['city', 'City'],
            ['state', 'State'],
            ['email', 'Email'],
            ['first_name', 'First Name'],
            ['last_name', 'Last Name']
        ];
        var NEWSLETTER_AUDIENCE_OPERATORS = [
            ['is', 'Is'],
            ['is_not', 'Is Not'],
            ['contains', 'Contains'],
            ['starts_with', 'Starts With']
        ];
        var newsletterThemeColorOptions = [
            ['default', 'Default'],
            ['metis_primary', 'Primary'],
            ['metis_primary_dark', 'Primary Dark'],
            ['metis_accent', 'Accent'],
            ['metis_bg', 'Background'],
            ['metis_surface', 'Surface'],
            ['metis_border', 'Border'],
            ['metis_text', 'Text'],
            ['metis_text_muted', 'Muted Text'],
            ['metis_header_bg', 'Header Background'],
            ['metis_row_odd_bg', 'Row Odd Background'],
            ['metis_row_even_bg', 'Row Even Background'],
            ['metis_row_hover_bg', 'Row Hover Background'],
            ['metis_sidebar_bg', 'Sidebar Background'],
            ['metis_sidebar_icon_color', 'Sidebar Icon'],
            ['metis_sidebar_active_color', 'Sidebar Active']
        ];

        function defaultNewsletterThemeState() {
            return {
                header_html: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.header_html || ''),
                personalized_html: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.personalized_html || ''),
                closing_html: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.closing_html || ''),
                footer_html: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.footer_html || ''),
                canvas_bg: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.canvas_bg || '#ffffff'),
                text_color: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.text_color || '#1f2937'),
                font_size: parseInt(s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.font_size || '16'), 10) || 16,
                content_width_mode: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.content_width_mode || 'normal'),
                content_width: parseInt(s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.content_width || '680'), 10) || 680,
                divider_color: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.divider_color || 'border'),
                divider_style: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.divider_style || 'solid'),
                divider_weight: parseInt(s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.divider_weight || '1'), 10) || 1,
                header_bg: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.header_bg || 'transparent'),
                header_text_color: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.header_text_color || 'text'),
                header_padding: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.header_padding || '24px 28px 12px 28px'),
                personalized_bg: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.personalized_bg || 'transparent'),
                personalized_text_color: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.personalized_text_color || 'text'),
                personalized_padding: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.personalized_padding || '0 28px 8px 28px'),
                closing_bg: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.closing_bg || 'transparent'),
                closing_text_color: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.closing_text_color || 'text'),
                closing_padding: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.closing_padding || '12px 28px 8px 28px'),
                footer_bg: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.footer_bg || 'transparent'),
                footer_text_color: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.footer_text_color || 'muted'),
                footer_padding: s(newsletterData && newsletterData.theme_defaults && newsletterData.theme_defaults.footer_padding || '16px 28px 28px 28px')
            };
        }

        function defaultNewsletterAudienceRule() {
            return { field: 'city', operator: 'is', value: '' };
        }

        function normalizeNewsletterAudienceState(raw) {
            var source = raw && typeof raw === 'object' ? raw : {};
            var mode = s(source.mode || 'lists').toLowerCase();
            return {
                mode: mode === 'custom' ? 'custom' : 'lists',
                rules: (Array.isArray(source.rules) ? source.rules : []).map(function (rule) {
                    return {
                        field: s(rule && rule.field || 'city') || 'city',
                        operator: s(rule && rule.operator || 'is') || 'is',
                        value: s(rule && rule.value || '')
                    };
                }).filter(function (rule) {
                    return rule.value.trim() !== '';
                })
            };
        }

        state.step = 2;
        state.dirty = false;
        state.newsletterFocusedEditor = 'metis-nl-body-editor';
        state.newsletterTheme = defaultNewsletterThemeState();
        state.newsletterDoc = {
            body_html: '<p>Add your newsletter content here.</p>'
        };
        state.newsletter = {
            id: 0,
            code: '',
            name: s(newsletterData && newsletterData.defaults && newsletterData.defaults.name || ''),
            subject: '',
            from_name: s(newsletterData && newsletterData.defaults && newsletterData.defaults.from_name || ''),
            from_email: s(newsletterData && newsletterData.defaults && newsletterData.defaults.from_email || ''),
            reply_to: s(newsletterData && newsletterData.defaults && newsletterData.defaults.reply_to || ''),
            preheader: '',
            status: 'draft',
            template_id: 0,
            list_ids: [],
            scheduled_at: ''
        };
        state.newsletterAudience = normalizeNewsletterAudienceState({});
        state.newsletterTestEmail = s(newsletterData && newsletterData.test_email_default || newsletterData && newsletterData.theme_preview_contact && newsletterData.theme_preview_contact.email || '');
        state.step = 2;
        state.newsletterDetailsExpanded = isNewEntityRoute();

        function parseNewsletterDoc(raw) {
            if (!raw) return {};
            if (typeof raw === 'object') return raw;
            try { return JSON.parse(s(raw)); } catch (_err) { return {}; }
        }

        function hydrateNewsletterFromInputs() {
            var pairs = [
                ['name', 'metis-nl-name'],
                ['subject', 'metis-nl-subject'],
                ['from_name', 'metis-nl-from-name'],
                ['from_email', 'metis-nl-from-email'],
                ['reply_to', 'metis-nl-reply-to'],
                ['preheader', 'metis-nl-preheader']
            ];
            pairs.forEach(function (pair) {
                var el = document.getElementById(pair[1]);
                if (el) state.newsletter[pair[0]] = s(el.value || '');
            });
            var sendScheduledEl = document.getElementById('metis-nl-send-scheduled');
            var scheduledEl = document.getElementById('metis-nl-scheduled-at');
            var audienceModeEl = root.querySelector('input[name="metis-nl-audience-mode"]:checked');
            state.newsletter.status = sendScheduledEl && sendScheduledEl.checked ? 'scheduled' : 'draft';
            state.newsletter.scheduled_at = fromDateTimeInputValue(s(scheduledEl && scheduledEl.value || ''));
            state.newsletterAudience.mode = s(audienceModeEl && audienceModeEl.value || state.newsletterAudience.mode || 'lists');
            state.newsletter.list_ids = [];
            root.querySelectorAll('input[data-nl-list-id]:checked').forEach(function (checkbox) {
                var listId = parseInt(s(checkbox.value || '0'), 10) || 0;
                if (listId > 0) state.newsletter.list_ids.push(listId);
            });
            state.newsletter.list_ids = normalizeIdList(state.newsletter.list_ids);
            state.newsletterAudience.rules = [];
            root.querySelectorAll('[data-nl-rule-row]').forEach(function (row) {
                var fieldEl = row.querySelector('[data-nl-rule-field]');
                var operatorEl = row.querySelector('[data-nl-rule-operator]');
                var valueEl = row.querySelector('[data-nl-rule-value]');
                var value = s(valueEl && valueEl.value || '').trim();
                if (!value) return;
                state.newsletterAudience.rules.push({
                    field: s(fieldEl && fieldEl.value || 'city') || 'city',
                    operator: s(operatorEl && operatorEl.value || 'is') || 'is',
                    value: value
                });
            });

            syncNewsletterBodyFromEditor();
        }

        function normalizeNewsletterEditorHtml(html) {
            var raw = s(html || '');
            if (!raw.trim()) return '';
            if (window.Metis && Metis.util && typeof Metis.util.sanitizeHtmlFragment === 'function') {
                raw = Metis.util.sanitizeHtmlFragment(raw);
            }
            var template = document.createElement('template');
            template.innerHTML = raw;
            var frag = template.content;
            Array.prototype.slice.call(frag.querySelectorAll('script,style')).forEach(function (node) { node.remove(); });
            var text = s(frag.textContent || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
            var hasVisibleContent = !!frag.querySelector('img,hr,.metis-inline-divider,.metis-inline-image,iframe,video,table,ul,ol,blockquote,pre');
            if (!text && !hasVisibleContent) return '';
            return template.innerHTML;
        }

        function markNewsletterEditorEmpty(target) {
            if (!target) return;
            var text = s(target.textContent || '').replace(/\u00a0/g, ' ').trim();
            var html = normalizeNewsletterEditorHtml(target.innerHTML || '');
            if (!text && !html) target.setAttribute('data-empty', 'true');
            else target.removeAttribute('data-empty');
        }
        function syncNewsletterBodyFromEditor() {
            var bodyEl = document.getElementById('metis-nl-body-editor');
            if (!bodyEl) return state.newsletterDoc.body_html || '';
            state.newsletterDoc.body_html = normalizeNewsletterEditorHtml(bodyEl.innerHTML || '');
            return state.newsletterDoc.body_html;
        }

        function extractNewsletterBodyRegionHtml(html) {
            var raw = s(html || '');
            if (!raw.trim()) return '';
            if (window.Metis && Metis.util && typeof Metis.util.sanitizeHtmlFragment === 'function') {
                raw = Metis.util.sanitizeHtmlFragment(raw);
            }
            var template = document.createElement('template');
            template.innerHTML = raw;
            var bodyRegion = template.content.querySelector('[data-metis-newsletter-region="body"]');
            if (!bodyRegion) return '';
            return normalizeNewsletterEditorHtml(bodyRegion.innerHTML || '');
        }

        function newsletterBodyHtmlFromEntity(entity, settings) {
            var explicitEditorBody = normalizeNewsletterEditorHtml(entity && entity.editor_body_html || '');
            if (explicitEditorBody) return explicitEditorBody;
            var resolvedSettings = settings && typeof settings === 'object' ? settings : {};
            var settingsBody = normalizeNewsletterEditorHtml(resolvedSettings.body_html || '');
            if (settingsBody) return settingsBody;
            var extractedBody = extractNewsletterBodyRegionHtml(entity && entity.html_body || '');
            if (extractedBody) return extractedBody;
            return normalizeNewsletterEditorHtml(entity && entity.html_body || '');
        }

        function hydrateNewsletterEntity(entity) {
            var resolved = entity && typeof entity === 'object' ? entity : {};
            var doc = parseNewsletterDoc(resolved.doc_json || '');
            var settings = doc && typeof doc.settings === 'object' ? doc.settings : {};
            var audience = normalizeNewsletterAudienceState(parseNewsletterDoc(resolved.audience_json || ''));
            state.newsletter.id = parseInt(s(resolved.id || '0'), 10) || 0;
            state.newsletter.code = s(resolved.template_code || resolved.campaign_code || '');
            state.newsletter.name = s(resolved.name || '');
            state.newsletter.subject = s(resolved.subject || '');
            state.newsletter.from_name = s(resolved.from_name || '');
            state.newsletter.from_email = s(resolved.from_email || '');
            state.newsletter.reply_to = s(resolved.reply_to || '');
            state.newsletter.preheader = s(resolved.preheader || resolved.preview_text || '');
            state.newsletter.status = s(resolved.status || 'draft');
            state.newsletter.template_id = parseInt(s(resolved.template_id || '0'), 10) || 0;
            state.newsletter.list_ids = Array.isArray(resolved.list_ids) ? resolved.list_ids : [];
            state.newsletter.scheduled_at = s(resolved.scheduled_at || '');
            state.newsletterAudience = audience;
            state.newsletterTheme = defaultNewsletterThemeState();
            state.newsletterDoc = {
                body_html: newsletterBodyHtmlFromEntity(resolved, settings)
            };
        }
        function newsletterPreviewCardHtml() {
            return '' +
                '<div class="metis-se-card metis-nl-preview-card">' +
                '<div class="metis-se-card-head">Preview</div>' +
                '<div class="metis-nl-preview-meta">' +
                '<div class="metis-nl-preview-subject" id="metis-nl-preview-subject">Untitled Newsletter</div>' +
                '<div class="metis-nl-preview-from" id="metis-nl-preview-from">Sender</div>' +
                '</div>' +
                '<div id="metis-nl-preview-frame" class="metis-nl-preview-frame">' +
                '<div id="metis-nl-preview-loading" class="metis-nl-preview-loading is-active"><div class="metis-nl-preview-loading-copy">Loading Preview</div></div>' +
                '<div id="metis-nl-preview-canvas" class="metis-nl-preview-canvas">' +
                '<div class="metis-nl-preview-shell" data-metis-newsletter-shell="1">' +
                '<div class="metis-nl-preview-inner" data-newsletter-preview-inner="1">' +
                '<div class="metis-nl-preview-region" data-metis-newsletter-region="header"></div>' +
                '<div class="metis-nl-preview-region" data-metis-newsletter-region="personalized"></div>' +
                '<div class="metis-nl-preview-region metis-nl-preview-region--body" data-metis-newsletter-region="body"></div>' +
                '<div class="metis-nl-preview-region" data-metis-newsletter-region="closing"></div>' +
                '<div class="metis-nl-preview-region" data-metis-newsletter-region="footer"></div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>';
        }

        function syncNewsletterEditorEmptyState() {
            ['metis-nl-body-editor'].forEach(function (id) {
                markNewsletterEditorEmpty(document.getElementById(id));
            });
        }

        function newsletterDocPayload() {
            hydrateNewsletterFromInputs();
            return {
                version: 1,
                settings: {
                    header_html: state.newsletterTheme.header_html,
                    personalized_html: state.newsletterTheme.personalized_html,
                    body_html: state.newsletterDoc.body_html,
                    closing_html: state.newsletterTheme.closing_html,
                    footer_html: state.newsletterTheme.footer_html,
                    canvas_bg: state.newsletterTheme.canvas_bg,
                    text_color: state.newsletterTheme.text_color,
                    font_size: state.newsletterTheme.font_size,
                    content_width_mode: state.newsletterTheme.content_width_mode,
                    content_width: state.newsletterTheme.content_width,
                    divider_color: state.newsletterTheme.divider_color,
                    divider_style: state.newsletterTheme.divider_style,
                    divider_weight: state.newsletterTheme.divider_weight,
                    header_bg: state.newsletterTheme.header_bg,
                    header_text_color: state.newsletterTheme.header_text_color,
                    header_padding: state.newsletterTheme.header_padding,
                    personalized_bg: state.newsletterTheme.personalized_bg,
                    personalized_text_color: state.newsletterTheme.personalized_text_color,
                    personalized_padding: state.newsletterTheme.personalized_padding,
                    closing_bg: state.newsletterTheme.closing_bg,
                    closing_text_color: state.newsletterTheme.closing_text_color,
                    closing_padding: state.newsletterTheme.closing_padding,
                    footer_bg: state.newsletterTheme.footer_bg,
                    footer_text_color: state.newsletterTheme.footer_text_color,
                    footer_padding: state.newsletterTheme.footer_padding
                },
                blocks: [
                    {
                        id: uid(),
                        type: 'text',
                        data: {
                            body: state.newsletterDoc.body_html || '<p></p>'
                        }
                    }
                ]
            };
        }

        function newsletterAudiencePayload() {
            hydrateNewsletterFromInputs();
            return {
                mode: state.newsletterAudience.mode,
                list_ids: state.newsletter.list_ids.slice(),
                rules: state.newsletterAudience.mode === 'custom' ? state.newsletterAudience.rules.slice() : []
            };
        }

        function newsletterRequiredFieldsReady() {
            hydrateNewsletterFromInputs();
            return !!(s(state.newsletter.name).trim() && s(state.newsletter.subject).trim());
        }

        function newsletterAudienceReady() {
            hydrateNewsletterFromInputs();
            if (!state.newsletter.list_ids.length) return false;
            if (state.newsletterAudience.mode !== 'custom') return true;
            return state.newsletterAudience.rules.length > 0;
        }

        function newsletterEditUrl(code) {
            var clean = s(code || '').trim();
            if (!clean) return '';
            return appBasePath() + '/admin/newsletter/campaigns/' + encodeURIComponent(clean) + '/edit/';
        }

        function applyNewsletterSaveResponse(resp) {
            var entity = isTemplate ? (resp.template || {}) : (resp.campaign || {});
            if (entity && typeof entity === 'object') {
                hydrateNewsletterEntity(entity);
            } else {
                state.newsletter.id = parseInt(s(resp && resp.campaign_id || state.newsletter.id || '0'), 10) || state.newsletter.id;
                state.newsletter.code = s(resp && resp.campaign_code || state.newsletter.code || '');
            }
            state.key = state.newsletter.code || state.key;
            state.id = state.newsletter.id || state.id;
            if (!isTemplate && state.newsletter.code) {
                var nextUrl = newsletterEditUrl(state.newsletter.code);
                if (nextUrl && window.history && window.history.replaceState) {
                    window.history.replaceState({}, '', nextUrl);
                }
            }
        }

        function renderPreviewMeta() {
            var subjectEl = document.getElementById('metis-nl-preview-subject');
            var fromEl = document.getElementById('metis-nl-preview-from');
            if (subjectEl) subjectEl.textContent = s(state.newsletter.subject || state.newsletter.name || 'Untitled Newsletter');
            if (fromEl) {
                var bits = [];
                if (state.newsletter.from_name) bits.push(s(state.newsletter.from_name));
                if (state.newsletter.from_email) bits.push('<' + s(state.newsletter.from_email) + '>');
                fromEl.textContent = bits.length ? bits.join(' ') : 'Sender';
            }
        }

        function newsletterPreviewContactValue(key) {
            var contact = newsletterData && newsletterData.theme_preview_contact && typeof newsletterData.theme_preview_contact === 'object'
                ? newsletterData.theme_preview_contact
                : {};
            return s(contact && contact[key] || '');
        }

        function resolveNewsletterThemeColor(value, fallback) {
            var raw = s(value || '').trim();
            if (!raw || raw === 'default') return s(fallback || '');
            if (raw === 'transparent') return 'transparent';
            var normalized = raw.toLowerCase();
            var colorMap = newsletterData && newsletterData.theme_color_map && typeof newsletterData.theme_color_map === 'object'
                ? newsletterData.theme_color_map
                : {};
            if (Object.prototype.hasOwnProperty.call(colorMap, raw)) {
                var mapped = s(colorMap[raw] || '').trim();
                if (mapped) return mapped;
            }
            if (Object.prototype.hasOwnProperty.call(colorMap, normalized)) {
                var normalizedMapped = s(colorMap[normalized] || '').trim();
                if (normalizedMapped) return normalizedMapped;
            }
            var aliasMap = {
                text: '--metis-text',
                muted: '--metis-text-muted',
                accent: '--metis-accent',
                background: '--metis-bg',
                bg: '--metis-bg',
                surface: '--metis-surface',
                card_bg: '--metis-surface',
                border: '--metis-border',
                card_border: '--metis-border',
                primary: '--metis-primary',
                primary_dark: '--metis-primary-dark',
                header_bg: '--metis-header-bg',
                row_odd_bg: '--metis-row-odd-bg',
                row_even_bg: '--metis-row-even-bg',
                row_hover_bg: '--metis-row-hover-bg',
                sidebar_bg: '--metis-sidebar-bg',
                sidebar_icon_color: '--metis-sidebar-icon-color',
                sidebar_active_color: '--metis-sidebar-active-color'
            };
            if (Object.prototype.hasOwnProperty.call(aliasMap, normalized) && window.getComputedStyle) {
                var aliasColor = s(window.getComputedStyle(document.documentElement).getPropertyValue(aliasMap[normalized]) || '').trim();
                if (aliasColor) return aliasColor;
                if (normalized === 'border') return '#dfe6f3';
            }
            return resolveEditorThemeColor(normalized, fallback) || resolveEditorThemeColor(raw, fallback);
        }

        function renderNewsletterMergeTags(html) {
            var template = s(html || '');
            if (!template) return '';
            var replacements = {
                first_name: newsletterPreviewContactValue('first_name'),
                last_name: newsletterPreviewContactValue('last_name'),
                full_name: newsletterPreviewContactValue('full_name'),
                name: newsletterPreviewContactValue('name'),
                email: newsletterPreviewContactValue('email'),
                city: newsletterPreviewContactValue('city'),
                state: newsletterPreviewContactValue('state'),
                campaign_name: s(state.newsletter.name || state.newsletter.subject || 'Newsletter Campaign'),
                unsubscribe_url: '#',
                manage_subscription_url: '#',
                view_online_url: '#',
                view_newsletter_url: '#'
            };
            return template.replace(/\{\{\s*([a-z_]+)\s*\}\}/gi, function (_match, token) {
                var key = s(token || '').toLowerCase();
                return Object.prototype.hasOwnProperty.call(replacements, key) ? replacements[key] : '';
            });
        }

        function newsletterPreviewSectionStyle(key) {
            var bg = resolveNewsletterThemeColor(state.newsletterTheme[key + '_bg'], 'transparent');
            var color = resolveNewsletterThemeColor(state.newsletterTheme[key + '_text_color'], '#1f2937');
            var padding = s(state.newsletterTheme[key + '_padding'] || '0 0 0 0').trim() || '0 0 0 0';
            return {
                background: bg,
                color: color,
                padding: padding,
                display: ''
            };
        }

        function normalizeNewsletterPreviewImages(scope) {
            if (!scope || !scope.querySelectorAll) return;
            scope.querySelectorAll('.metis-inline-image').forEach(function (node) {
                if (!node.classList.contains('is-small') &&
                    !node.classList.contains('is-medium') &&
                    !node.classList.contains('is-large') &&
                    !node.classList.contains('is-full')) {
                    node.classList.add('is-medium');
                }
                var align = s(node.getAttribute('data-align') || '').toLowerCase();
                node.style.textAlign = align === 'right' ? 'right' : (align === 'left' ? 'left' : 'center');
                if (align === 'right') {
                    node.style.margin = '18px 0 18px auto';
                } else if (align === 'left') {
                    node.style.margin = '18px auto 18px 0';
                } else {
                    node.style.margin = '18px auto';
                }
            });
            scope.querySelectorAll('.metis-inline-image img').forEach(function (img) {
                var figure = img.closest('.metis-inline-image');
                var maxWidth = '520px';
                if (figure) {
                    if (figure.classList.contains('is-small')) maxWidth = '280px';
                    else if (figure.classList.contains('is-large')) maxWidth = '720px';
                    else if (figure.classList.contains('is-full')) maxWidth = '100%';
                }
                img.style.width = maxWidth === '100%' ? '100%' : 'auto';
                img.style.maxWidth = maxWidth;
                img.style.height = 'auto';
                img.style.display = 'inline-block';
            });
            scope.querySelectorAll('.metis-nl-preview-region img').forEach(function (img) {
                if (img.closest('.metis-inline-image')) return;
                img.style.maxWidth = '100%';
                img.style.height = 'auto';
            });
        }

        function renderNewsletterPreview() {
            hydrateNewsletterFromInputs();
            renderPreviewMeta();
            var canvas = document.getElementById('metis-nl-preview-canvas');
            var loading = document.getElementById('metis-nl-preview-loading');
            if (!canvas) return;
            var inner = canvas.querySelector('[data-newsletter-preview-inner="1"]');
            var shell = canvas.querySelector('[data-metis-newsletter-shell="1"]');
            if (!inner || !shell) return;

            var width = Math.max(520, Math.min(820, parseInt(s(state.newsletterTheme.content_width || '680'), 10) || 680));
            var canvasBg = resolveNewsletterThemeColor(state.newsletterTheme.canvas_bg, '#ffffff');
            var textColor = resolveNewsletterThemeColor(state.newsletterTheme.text_color, '#1f2937');
            var fontSize = parseInt(s(state.newsletterTheme.font_size || '16'), 10) || 16;

            shell.style.width = 'max-content';
            shell.style.minWidth = 'max-content';
            shell.style.background = canvasBg;
            shell.style.color = textColor;
            inner.style.width = String(width) + 'px';
            inner.style.minWidth = String(width) + 'px';
            inner.style.maxWidth = String(width) + 'px';
            inner.style.background = '#ffffff';
            inner.style.fontSize = String(fontSize) + 'px';
            inner.style.color = textColor;

            [
                ['header', state.newsletterTheme.header_html],
                ['personalized', state.newsletterTheme.personalized_html],
                ['closing', state.newsletterTheme.closing_html],
                ['footer', state.newsletterTheme.footer_html]
            ].forEach(function (pair) {
                var key = pair[0];
                var node = canvas.querySelector('[data-metis-newsletter-region="' + key + '"]');
                if (!node) return;
                var html = renderNewsletterMergeTags(pair[1] || '');
                node.innerHTML = html;
                node.style.background = '';
                node.style.color = '';
                node.style.padding = '';
                node.style.fontSize = '';
                node.style.lineHeight = '';
                node.style.display = html.trim() ? '' : 'none';
                var sectionStyle = newsletterPreviewSectionStyle(key);
                Object.keys(sectionStyle).forEach(function (styleKey) {
                    node.style[styleKey] = sectionStyle[styleKey];
                });
            });

            var bodyNode = canvas.querySelector('[data-metis-newsletter-region="body"]');
            if (bodyNode) {
                var bodyHtml = renderNewsletterMergeTags(normalizeNewsletterEditorHtml(state.newsletterDoc.body_html || ''));
                bodyNode.innerHTML = bodyHtml;
                bodyNode.style.color = textColor;
                if (bodyHtml.trim()) {
                    bodyNode.style.fontSize = String(fontSize) + 'px';
                    bodyNode.style.lineHeight = '';
                    bodyNode.style.display = '';
                    bodyNode.style.padding = '';
                    bodyNode.style.minHeight = '';
                    bodyNode.style.overflow = '';
                } else {
                    bodyNode.innerHTML = '';
                    bodyNode.style.fontSize = '';
                    bodyNode.style.lineHeight = '0';
                    bodyNode.style.display = 'none';
                    bodyNode.style.padding = '0';
                    bodyNode.style.minHeight = '0';
                    bodyNode.style.overflow = 'hidden';
                }
            }

            canvas.querySelectorAll('.metis-inline-divider').forEach(function (node) {
                node.style.border = '0';
                node.style.borderTopColor = resolveNewsletterThemeColor(state.newsletterTheme.divider_color, '#dfe6f3');
                node.style.borderTopStyle = s(state.newsletterTheme.divider_style || 'solid') || 'solid';
                node.style.borderTopWidth = String(parseInt(s(state.newsletterTheme.divider_weight || '1'), 10) || 1) + 'px';
                node.style.margin = '18px 0';
            });

            normalizeNewsletterPreviewImages(canvas);

            canvas.classList.add('is-ready');
            if (loading) loading.classList.remove('is-active');
            newsletterPreviewBooted = true;
        }

        function queueNewsletterPreview() {
            renderPreviewMeta();
            if (previewTimer) window.clearTimeout(previewTimer);
            previewTimer = window.setTimeout(function () {
                renderNewsletterPreview();
            }, 180);
        }

        function saveNewsletter() {
            var doc = newsletterDocPayload();
            var audience = newsletterAudiencePayload();
            var payload = {
                name: state.newsletter.name,
                subject: state.newsletter.subject,
                from_name: state.newsletter.from_name,
                from_email: state.newsletter.from_email,
                reply_to: state.newsletter.reply_to,
                preheader: state.newsletter.preheader,
                status: state.newsletter.status,
                doc_json: JSON.stringify(doc),
                editor_body_html: state.newsletterDoc.body_html || '',
                html_body: state.newsletterDoc.body_html || ''
            };
            if (isTemplate) {
                if (state.newsletter.code) payload.template_code = state.newsletter.code;
                if (state.newsletter.id) payload.template_id = state.newsletter.id;
                return request('metis_newsletter_save_template', payload);
            }
            if (state.newsletter.code) payload.campaign_code = state.newsletter.code;
            if (state.newsletter.id) payload.campaign_id = state.newsletter.id;
            if (state.newsletter.template_id) payload.template_id = state.newsletter.template_id;
            if (Array.isArray(state.newsletter.list_ids)) payload.list_ids = JSON.stringify(state.newsletter.list_ids);
            payload.audience_json = JSON.stringify(audience);
            if (state.newsletter.scheduled_at) payload.scheduled_at = state.newsletter.scheduled_at;
            return request('metis_newsletter_save_campaign', payload);
        }

        function saveNewsletterState(opts) {
            var options = opts && typeof opts === 'object' ? opts : {};
            var autosave = !!options.autosave;
            syncNewsletterBodyFromEditor();
            if (!isTemplate && !newsletterRequiredFieldsReady()) {
                if (!autosave && options.showError !== false) {
                    setStatus('Campaign name and subject are required before saving.', 'error');
                }
                return Promise.resolve(null);
            }
            if (state.saving) {
                state.queuedSave = options;
                return Promise.resolve(null);
            }
            state.saving = true;
            setStatus(autosave ? 'Autosaving...' : 'Saving...', 'saving');
            return saveNewsletter().then(function (resp) {
                applyNewsletterSaveResponse(resp || {});
                state.dirty = false;
                setStatus('Saved at ' + formatConfiguredTime(new Date(), ''), 'ok');
                return resp;
            }).catch(function (err) {
                setStatus('Save failed: ' + s(err && err.message || 'Request failed.'), 'error');
                throw err;
            }).finally(function () {
                state.saving = false;
                if (state.queuedSave) {
                    var queued = state.queuedSave;
                    state.queuedSave = null;
                    saveNewsletterState(queued);
                }
            });
        }

        function scheduleNewsletterAutosave() {
            state.dirty = true;
            if (!newsletterRequiredFieldsReady()) {
                if (state.autosaveTimer) window.clearTimeout(state.autosaveTimer);
                if (s(state.newsletter.name).trim() || s(state.newsletter.subject).trim()) {
                    setStatus('Enter campaign name and subject to start autosave.', 'saving');
                }
                return;
            }
            setStatus('Unsaved changes', 'saving');
            if (state.autosaveTimer) window.clearTimeout(state.autosaveTimer);
            state.autosaveTimer = window.setTimeout(function () {
                saveNewsletterState({ autosave: true, showError: false });
            }, 1400);
        }

        function openNewsletterTestModal() {
            var modal = document.getElementById('metis-nl-test-modal');
            var input = document.getElementById('metis-nl-test-email');
            if (!modal) return;
            if (input && !s(input.value).trim()) input.value = state.newsletterTestEmail || '';
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            if (input) input.focus();
        }

        function closeNewsletterTestModal() {
            var modal = document.getElementById('metis-nl-test-modal');
            if (!modal) return;
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }

        function ensureNewsletterPersisted() {
            if (state.newsletter.id > 0 && state.newsletter.code) return Promise.resolve(true);
            return saveNewsletterState({ autosave: false }).then(function () {
                return !!(state.newsletter.id > 0);
            });
        }

        function sendNewsletterTest() {
            syncNewsletterBodyFromEditor();
            var emailEl = document.getElementById('metis-nl-test-email');
            var email = s(emailEl && emailEl.value || state.newsletterTestEmail || '').trim();
            if (!email) {
                setStatus('Choose a test email address.', 'error');
                return;
            }
            state.newsletterTestEmail = email;
            saveNewsletterState({ autosave: false }).then(function () {
                setStatus('Sending test...', 'saving');
                var payload = {
                    campaign_id: state.newsletter.id,
                    test_email: email
                };
                if (typeof window.metisAjax === 'function') {
                    return new Promise(function (resolve, reject) {
                        window.metisAjax('metis_newsletter_test_send_campaign', payload, function () {
                            resolve();
                        }, function (msg) {
                            reject(new Error(s(msg || 'Request failed.')));
                        });
                    }).then(function () {
                        setStatus('Test email sent.', 'ok');
                        closeNewsletterTestModal();
                    });
                }
                return request('metis_newsletter_test_send_campaign', payload).then(function () {
                    setStatus('Test email sent.', 'ok');
                    closeNewsletterTestModal();
                });
            }).catch(function (err) {
                setStatus('Test send failed: ' + s(err && err.message || 'Request failed.'), 'error');
            });
        }

        function executeNewsletterDelivery() {
            syncNewsletterBodyFromEditor();
            hydrateNewsletterFromInputs();
            if (!newsletterRequiredFieldsReady()) {
                setStatus('Campaign name and subject are required before sending.', 'error');
                state.newsletterDetailsExpanded = true;
                state.step = 2;
                renderStep1();
                renderStep2();
                syncStepUi();
                return;
            }
            if (!newsletterAudienceReady()) {
                setStatus('Choose at least one list. Custom audience also needs at least one rule.', 'error');
                state.step = 1;
                renderStep1();
                renderStep2();
                syncStepUi();
                return;
            }
            if (state.newsletter.status === 'scheduled' && !state.newsletter.scheduled_at) {
                setStatus('Choose a send date and time.', 'error');
                return;
            }
            saveNewsletterState({ autosave: false }).then(function () {
                if (state.newsletter.status === 'scheduled') {
                    setStatus('Campaign scheduled.', 'ok');
                    return;
                }
                setStatus('Queueing campaign...', 'saving');
                return request('metis_newsletter_queue_campaign', { campaign_id: state.newsletter.id }).then(function () {
                    state.newsletter.status = 'queued';
                    setStatus('Campaign queued.', 'ok');
                    renderStep1();
                    renderStep2();
                    syncStepUi();
                });
            }).catch(function (err) {
                setStatus('Delivery setup failed: ' + s(err && err.message || 'Request failed.'), 'error');
            });
        }

        function syncStepUi() {
            var step1 = document.getElementById('metis-nl-step-1');
            var step2 = document.getElementById('metis-nl-step-2');
            var previewPane = document.getElementById('metis-nl-preview-pane');
            var deliveryStep = state.step === 1;
            if (step1) step1.style.display = deliveryStep ? '' : 'none';
            if (step2) step2.style.display = deliveryStep ? 'none' : '';
            if (previewPane) previewPane.style.display = deliveryStep ? 'none' : '';
            var prev = document.getElementById('metis-nl-prev');
            var next = document.getElementById('metis-nl-next');
            if (prev) {
                prev.style.display = deliveryStep ? '' : 'none';
                prev.textContent = 'Back to Editor';
            }
            if (next) {
                next.style.display = deliveryStep ? 'none' : '';
                next.textContent = isTemplate ? 'Save Template' : 'Prepare to Send';
            }
        }

        function newsletterTopTitle() {
            var name = s(state.newsletter.name || '').trim();
            if (name) return name;
            return isTemplate ? 'Newsletter Template' : 'Newsletter Campaign';
        }

        function renderShell() {
            var backHref = appBasePath() + (isTemplate ? '/admin/newsletter/templates/' : '/admin/newsletter/campaigns/');
            root.innerHTML = '' +
                '<div class="metis-se-shell">' +
                '<div class="metis-se-topbar">' +
                '<div class="metis-se-top-left"><a class="metis-se-nav-btn" href="' + esc(backHref) + '">&larr; ' + esc(isTemplate ? 'Back to Templates' : 'Back to Campaigns') + '</a></div>' +
                '<div class="metis-se-top-center"><div id="metis-se-top-title" class="metis-se-top-title">' + esc(newsletterTopTitle()) + '</div></div>' +
                '<div class="metis-se-top-right"><span id="metis-se-save-status" class="metis-se-save-status"></span><button id="metis-nl-prev" type="button" class="metis-se-nav-btn">Back to Details</button><button id="metis-nl-next" type="button" class="metis-se-nav-btn">Continue to Content</button></div>' +
                '</div>' +
                '<div class="metis-se-body metis-nl-body">' +
                '<div class="metis-nl-pane metis-nl-pane--editor">' +
                '<div id="metis-nl-step-1" class="metis-se-step"></div>' +
                '<div id="metis-nl-step-2" class="metis-se-step"></div>' +
                '</div>' +
                '<div id="metis-nl-preview-pane" class="metis-nl-pane metis-nl-pane--preview">' +
                newsletterPreviewCardHtml() +
                '</div>' +
                '</div>' +
                '<div id="metis-nl-test-modal" class="metis-modal-overlay metis-nl-modal" style="display:none;" role="dialog" aria-modal="true" aria-hidden="true" aria-label="Send Test Email">' +
                '<div class="metis-modal metis-nl-modal__dialog">' +
                '<div class="metis-modal-header"><h2 class="metis-modal-title">Send Test Email</h2><button type="button" class="metis-modal-close" id="metis-nl-test-close" aria-label="Close">&times;</button></div>' +
                '<div class="metis-modal-body">' +
                '<div class="metis-se-field-row metis-nl-details-row"><label for="metis-nl-test-email">Recipient Email</label><input id="metis-nl-test-email" class="metis-se-input" type="email" value="' + esc(state.newsletterTestEmail || '') + '"></div>' +
                '</div>' +
                '<div class="metis-modal-footer"><button type="button" class="metis-btn metis-btn-ghost" id="metis-nl-test-cancel">Cancel</button><button type="button" class="metis-btn" id="metis-nl-test-send">Send Test</button></div>' +
                '</div>' +
                '</div>' +
                '</div>';
        }

        function renderStep1() {
            var wrap = document.getElementById('metis-nl-step-1');
            if (!wrap) return;
            var listOptions = (Array.isArray(newsletterData && newsletterData.lists) ? newsletterData.lists : []).map(function (row) {
                var listId = parseInt(s(row && row.id || '0'), 10) || 0;
                if (listId < 1) return '';
                var checked = state.newsletter.list_ids.indexOf(listId) !== -1;
                return '<label class="metis-nl-check-item"><input type="checkbox" data-nl-list-id="1" value="' + esc(String(listId)) + '"' + (checked ? ' checked' : '') + '> <span>' + esc(s(row && row.name || 'List ' + listId)) + '</span></label>';
            }).join('');
            var ruleRows = state.newsletterAudience.rules.length ? state.newsletterAudience.rules : [defaultNewsletterAudienceRule()];
            var rulesHtml = ruleRows.map(function (rule, index) {
                return '' +
                    '<div class="metis-nl-rule-row" data-nl-rule-row="' + esc(String(index)) + '">' +
                    '<select class="metis-se-select" data-nl-rule-field>' +
                        NEWSLETTER_AUDIENCE_FIELDS.map(function (option) {
                            return '<option value="' + esc(option[0]) + '"' + (rule.field === option[0] ? ' selected' : '') + '>' + esc(option[1]) + '</option>';
                        }).join('') +
                    '</select>' +
                    '<select class="metis-se-select" data-nl-rule-operator>' +
                        NEWSLETTER_AUDIENCE_OPERATORS.map(function (option) {
                            return '<option value="' + esc(option[0]) + '"' + (rule.operator === option[0] ? ' selected' : '') + '>' + esc(option[1]) + '</option>';
                        }).join('') +
                    '</select>' +
                    '<input class="metis-se-input" data-nl-rule-value type="text" value="' + esc(rule.value || '') + '" placeholder="Value">' +
                    '<button type="button" class="metis-se-nav-btn" data-nl-rule-remove="' + esc(String(index)) + '">Remove</button>' +
                    '</div>';
            }).join('');
            wrap.innerHTML = '' +
                '<div class="metis-nl-step-stack">' +
                '<div class="metis-se-card metis-nl-delivery-card is-expanded">' +
                '<div class="metis-se-card-head"><span>Prepare to Send</span></div>' +
                '<div class="metis-se-field-grid metis-nl-details-grid">' +
                '<div class="metis-se-field-row metis-nl-details-row"><label>Send Timing</label><div class="metis-nl-choice-row"><label class="metis-nl-choice"><input id="metis-nl-send-now" type="radio" name="metis-nl-send-mode" value="now"' + (state.newsletter.status === 'scheduled' ? '' : ' checked') + '> Send now</label><label class="metis-nl-choice"><input id="metis-nl-send-scheduled" type="radio" name="metis-nl-send-mode" value="scheduled"' + (state.newsletter.status === 'scheduled' ? ' checked' : '') + '> Schedule send</label></div></div>' +
                '<div class="metis-se-field-row metis-nl-details-row metis-nl-schedule-row"' + (state.newsletter.status === 'scheduled' ? '' : ' hidden') + '><label for="metis-nl-scheduled-at">Scheduled Time</label><input id="metis-nl-scheduled-at" class="metis-se-input" type="datetime-local" value="' + esc(toDateTimeInputValue(state.newsletter.scheduled_at || '')) + '"></div>' +
                '<div class="metis-se-field-row metis-nl-details-row"><label>Audience</label><div class="metis-nl-choice-row"><label class="metis-nl-choice"><input type="radio" name="metis-nl-audience-mode" value="lists"' + (state.newsletterAudience.mode === 'lists' ? ' checked' : '') + '> Current lists</label><label class="metis-nl-choice"><input type="radio" name="metis-nl-audience-mode" value="custom"' + (state.newsletterAudience.mode === 'custom' ? ' checked' : '') + '> Custom audience</label></div></div>' +
                '<div class="metis-se-field-row metis-nl-details-row"><label>Lists</label><div class="metis-nl-check-grid">' + (listOptions || '<div class="metis-muted">No active lists available.</div>') + '</div></div>' +
                '<div class="metis-se-field-row metis-nl-details-row metis-nl-rules-row"' + (state.newsletterAudience.mode === 'custom' ? '' : ' hidden') + '><label>Rules</label><div class="metis-nl-rules-wrap"><div class="metis-nl-rules-list">' + rulesHtml + '</div><button type="button" class="metis-se-nav-btn" id="metis-nl-add-rule">Add Rule</button></div></div>' +
                '<div class="metis-se-field-row metis-nl-details-row"><label>Actions</label><div class="metis-nl-delivery-actions"><button type="button" class="metis-se-nav-btn" id="metis-nl-open-test">Send Test Email</button><button type="button" class="metis-btn" id="metis-nl-deliver">' + (state.newsletter.status === 'scheduled' ? 'Schedule Campaign' : 'Send Now') + '</button></div></div>' +
                '</div>' +
                '</div>' +
                '</div>';
        }

        function newsletterToolbarDropdown(label, icon, menuHtml, extraClass) {
            return '<div class="metis-se-rich-dropdown ' + esc(extraClass || '') + '">' +
                '<button type="button" class="metis-se-toolbtn metis-se-rich-menu-trigger metis-se-rich-icon-btn" data-rich-toggle="menu" title="' + esc(label) + '" aria-label="' + esc(label) + '">' +
                    '<img src="' + esc(iconUrl(icon)) + '" data-icon-fallback="' + esc(iconFallbackUrl(icon)) + '" alt="" aria-hidden="true">' +
                '</button>' +
                '<div class="metis-se-rich-menu">' + menuHtml + '</div>' +
            '</div>';
        }

        function newsletterRichToolbar(targetId) {
            var cmds = [
                { c: 'italic', icon: 'italic', label: 'Italic' },
                { c: 'underline', icon: 'text-underline', label: 'Underline' },
                { c: 'strikeThrough', icon: 'text-strikethrough', label: 'Strike Through' },
                { c: 'justifyLeft', icon: 'text-align-left', label: 'Align Left' },
                { c: 'justifyCenter', icon: 'text-align-center', label: 'Align Center' },
                { c: 'justifyRight', icon: 'text-align-right', label: 'Align Right' },
                { c: 'insertImagePrompt', icon: 'image', label: 'Insert Image' },
                { c: 'insertEmojiPrompt', icon: 'emoji', label: 'Insert Emoji' },
                { c: 'insertUnorderedList', icon: 'list-bulleted', label: 'Bulleted List' },
                { c: 'insertOrderedList', icon: 'list-boxes', label: 'Numbered List' },
                { c: 'outdent', icon: 'text-indent-less', label: 'Decrease List Level' },
                { c: 'indent', icon: 'text-indent-more', label: 'Increase List Level' },
                { c: 'createLink', icon: 'link', label: 'Insert Link' },
                { c: 'unlink', icon: 'close-outline', label: 'Remove Link' },
                { c: 'insertDivider', icon: 'divider', label: 'Insert Divider' },
                { c: 'removeFormat', icon: 'text-clear-format', label: 'Clear Format' },
                { c: 'undo', icon: 'undo', label: 'Undo' },
                { c: 'redo', icon: 'redo', label: 'Redo' }
            ];
            var blockMenu = [
                ['P', 'Paragraph'],
                ['H1', 'Heading 1'],
                ['H2', 'Heading 2'],
                ['H3', 'Heading 3'],
                ['H4', 'Heading 4'],
                ['PRE', 'Code']
            ].map(function (row) {
                return '<button type="button" class="metis-se-rich-menu-item" data-rich-action="block" data-rich-target="' + esc(targetId) + '" data-rich-value="' + esc(row[0]) + '">' + esc(row[1]) + '</button>';
            }).join('');
            var sizeMenu = [
                ['default', 'Default'],
                ['sm', 'Small'],
                ['lg', 'Large'],
                ['xl', 'Large+']
            ].map(function (row) {
                return '<button type="button" class="metis-se-rich-menu-item" data-rich-action="size" data-rich-target="' + esc(targetId) + '" data-rich-value="' + esc(row[0]) + '">' + esc(row[1]) + '</button>';
            }).join('');
            var colorMenu = newsletterThemeColorOptions.map(function (row) {
                return '<button type="button" class="metis-se-rich-menu-item metis-se-rich-menu-item--color" data-rich-action="color" data-rich-target="' + esc(targetId) + '" data-rich-value="' + esc(row[0]) + '">' +
                    '<span class="metis-se-color-swatch metis-se-color-swatch--' + esc(row[0]) + '"></span><span>' + esc(row[1]) + '</span></button>';
            }).join('');
            var weightMenu = [
                ['600', 'Semi Bold'],
                ['700', 'Bold'],
                ['800', 'Extra Bold']
            ].map(function (row) {
                return '<button type="button" class="metis-se-rich-menu-item" data-rich-action="weight" data-rich-target="' + esc(targetId) + '" data-rich-value="' + esc(row[0]) + '">' + esc(row[1]) + '</button>';
            }).join('');
            var mergeMenu = [
                ['First Name', '{{first_name}}'],
                ['Last Name', '{{last_name}}'],
                ['Full Name', '{{full_name}}'],
                ['Preferred Name', '{{name}}'],
                ['Email', '{{email}}'],
                ['Campaign Name', '{{campaign_name}}'],
                ['City', '{{city}}'],
                ['State', '{{state}}'],
                ['Unsubscribe Link', '{{unsubscribe_url}}'],
                ['Manage Subscription Link', '{{manage_subscription_url}}'],
                ['View Online Link', '{{view_online_url}}']
            ].map(function (row) {
                return '<button type="button" class="metis-se-rich-menu-item" data-rich-action="merge" data-rich-target="' + esc(targetId) + '" data-rich-value="' + esc(row[1]) + '">' + esc(row[0]) + '</button>';
            }).join('');
            return '<div class="metis-se-rich-tools">' +
                '<div class="metis-se-rich-toolbar">' +
                    '<div class="metis-se-rich-group metis-se-rich-group--actions">' +
                        newsletterToolbarDropdown('Paragraph', 'h1', blockMenu, 'metis-se-rich-dropdown--format') +
                        newsletterToolbarDropdown('Text Size', 'text-scale', sizeMenu, 'metis-se-rich-dropdown--size') +
                        newsletterToolbarDropdown('Text Color', 'text-color', colorMenu, 'metis-se-rich-dropdown--color') +
                        newsletterToolbarDropdown('Weight', 'text-bold', weightMenu, 'metis-se-rich-dropdown--weight') +
                        cmds.map(function (item) {
                            return '<button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="' + esc(item.c) + '" data-rich-target="' + esc(targetId) + '" title="' + esc(item.label) + '" aria-label="' + esc(item.label) + '">' +
                                '<img src="' + esc(iconUrl(item.icon)) + '" data-icon-fallback="' + esc(iconFallbackUrl(item.icon)) + '" alt="" aria-hidden="true">' +
                            '</button>';
                        }).join('') +
                        newsletterToolbarDropdown('Merge Tags', 'code', mergeMenu, 'metis-se-rich-dropdown--merge') +
                    '</div>' +
                '</div>' +
            '</div>';
        }

        function richEditorCard(id, title, html, copy) {
            return '' +
                '<div class="metis-se-card">' +
                '<div class="metis-se-card-head">' + esc(title) + '</div>' +
                (copy ? '<p class="metis-se-sections-copy">' + esc(copy) + '</p>' : '') +
                '<div class="metis-se-editor-pane metis-nl-editor-pane">' +
                newsletterRichToolbar(id) +
                '<div id="' + esc(id) + '" class="metis-se-rich-editor metis-nl-rich-editor" contenteditable="true" data-placeholder="Start typing..." data-nl-editor="' + esc(id) + '">' + s(html || '<p></p>') + '</div>' +
                '</div>' +
                '</div>';
        }

        function renderStep2() {
            var wrap = document.getElementById('metis-nl-step-2');
            if (!wrap) return;
            var detailsExpanded = !!state.newsletterDetailsExpanded;
            wrap.innerHTML = '' +
                '<div class="metis-nl-step-stack">' +
                '<div class="metis-se-card metis-nl-details-card' + (detailsExpanded ? ' is-expanded' : ' is-collapsed') + '">' +
                '<div class="metis-se-card-head metis-nl-details-head"><span>Details</span><button id="metis-nl-toggle-details" type="button" class="metis-se-nav-btn metis-nl-details-toggle">' + (detailsExpanded ? 'Collapse' : 'Edit Details') + '</button></div>' +
                '<div class="metis-se-field-grid metis-nl-details-grid"' + (detailsExpanded ? '' : ' hidden') + '>' +
                '<div class="metis-se-field-row metis-nl-details-row"><label for="metis-nl-name">Name</label><input id="metis-nl-name" class="metis-se-input" type="text" value="' + esc(state.newsletter.name) + '"></div>' +
                '<div class="metis-se-field-row metis-nl-details-row"><label for="metis-nl-subject">Subject</label><input id="metis-nl-subject" class="metis-se-input" type="text" value="' + esc(state.newsletter.subject) + '"></div>' +
                (!isTemplate ? '<div class="metis-se-field-row metis-nl-details-row"><label for="metis-nl-preheader">Preview Text</label><input id="metis-nl-preheader" class="metis-se-input" type="text" value="' + esc(state.newsletter.preheader) + '"></div>' : '') +
                '<div class="metis-se-field-row metis-nl-details-row"><label for="metis-nl-from-name">Sender Name</label><input id="metis-nl-from-name" class="metis-se-input" type="text" value="' + esc(state.newsletter.from_name) + '"></div>' +
                '<div class="metis-se-field-row metis-nl-details-row"><label for="metis-nl-from-email">Sender Email</label><input id="metis-nl-from-email" class="metis-se-input" type="email" value="' + esc(state.newsletter.from_email) + '"></div>' +
                '<div class="metis-se-field-row metis-nl-details-row"><label for="metis-nl-reply-to">Reply-To</label><input id="metis-nl-reply-to" class="metis-se-input" type="email" value="' + esc(state.newsletter.reply_to) + '"></div>' +
                '</div>' +
                '</div>' +
                '<div class="metis-se-card metis-nl-body-card">' +
                '<div class="metis-se-card-head">Body</div>' +
                '<div class="metis-se-editor-pane metis-nl-editor-pane metis-nl-editor-pane--body">' +
                newsletterRichToolbar('metis-nl-body-editor') +
                '<div id="metis-nl-body-editor" class="metis-se-rich-editor metis-nl-rich-editor" contenteditable="true" data-placeholder="Start writing the newsletter body..." data-nl-editor="metis-nl-body-editor">' + s(normalizeNewsletterEditorHtml(state.newsletterDoc.body_html || '')) + '</div>' +
                '</div>' +
                '</div>' +
                '</div>';
            syncNewsletterEditorEmptyState();
        }

        function renderStep3() {
            return;
        }

        function loadNewsletterEntity() {
            renderShell();
            renderStep1();
            renderStep2();
            renderStep3();
            syncStepUi();
            if (isNewEntityRoute()) {
                queueNewsletterPreview();
                syncNewsletterEditorEmptyState();
                return Promise.resolve();
            }
            var action = isTemplate ? 'metis_newsletter_template_get' : 'metis_newsletter_campaign_get';
            var payload = {};
            if (/^\d+$/.test(state.key)) payload[isTemplate ? 'template_id' : 'campaign_id'] = parseInt(state.key, 10);
            else if (state.key) payload[isTemplate ? 'template_code' : 'campaign_code'] = state.key;
            else if (state.id > 0) payload[isTemplate ? 'template_id' : 'campaign_id'] = state.id;
            return request(action, payload).then(function (resp) {
                var entity = isTemplate ? (resp.template || {}) : (resp.campaign || {});
                state.newsletterDetailsExpanded = false;
                hydrateNewsletterEntity(entity);
                renderShell();
                renderStep1();
                renderStep2();
                renderStep3();
                syncStepUi();
                queueNewsletterPreview();
                syncNewsletterEditorEmptyState();
            }).catch(function (err) {
                setStatus('Load failed: ' + s(err && err.message || 'Request failed.'), 'error');
                queueNewsletterPreview();
            });
        }

        function wire() {
            root.addEventListener('click', function (e) {
                var richBtn = e.target.closest('button[data-rich-cmd]');
                var richToggle = e.target.closest('button[data-rich-toggle="menu"]');
                var richAction = e.target.closest('[data-rich-action]');
                if (!richToggle && !richAction && !e.target.closest('.metis-se-rich-dropdown')) {
                    root.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function (node) { node.classList.remove('is-open'); });
                }
                if (richToggle) {
                    e.preventDefault();
                    var dropdown = richToggle.closest('.metis-se-rich-dropdown');
                    root.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function (node) { if (node !== dropdown) node.classList.remove('is-open'); });
                    if (dropdown) dropdown.classList.toggle('is-open');
                    return;
                }
                var toggleDetails = e.target.closest('#metis-nl-toggle-details');
                if (toggleDetails) {
                    e.preventDefault();
                    state.newsletterDetailsExpanded = !state.newsletterDetailsExpanded;
                    renderStep2();
                    return;
                }
                var addRule = e.target.closest('#metis-nl-add-rule');
                if (addRule) {
                    e.preventDefault();
                    state.newsletterAudience.rules.push(defaultNewsletterAudienceRule());
                    renderStep1();
                    syncStepUi();
                    return;
                }
                var removeRule = e.target.closest('[data-nl-rule-remove]');
                if (removeRule) {
                    e.preventDefault();
                    var removeIndex = parseInt(s(removeRule.getAttribute('data-nl-rule-remove') || '-1'), 10);
                    if (removeIndex > -1) state.newsletterAudience.rules.splice(removeIndex, 1);
                    if (!state.newsletterAudience.rules.length) state.newsletterAudience.rules = [defaultNewsletterAudienceRule()];
                    renderStep1();
                    syncStepUi();
                    return;
                }
                var openTest = e.target.closest('#metis-nl-open-test');
                if (openTest) {
                    e.preventDefault();
                    openNewsletterTestModal();
                    return;
                }
                var closeTest = e.target.closest('#metis-nl-test-close, #metis-nl-test-cancel');
                if (closeTest) {
                    e.preventDefault();
                    closeNewsletterTestModal();
                    return;
                }
                var sendTest = e.target.closest('#metis-nl-test-send');
                if (sendTest) {
                    e.preventDefault();
                    sendNewsletterTest();
                    return;
                }
                var deliverBtn = e.target.closest('#metis-nl-deliver');
                if (deliverBtn) {
                    e.preventDefault();
                    executeNewsletterDelivery();
                    return;
                }
                if (richAction) {
                    var action = s(richAction.getAttribute('data-rich-action') || '');
                    if (action === 'color-picker') {
                        return;
                    }
                    e.preventDefault();
                    var actionTargetId = s(richAction.getAttribute('data-rich-target') || '');
                    var actionValue = s(richAction.getAttribute('data-rich-value') || '');
                    var actionTarget = actionTargetId ? document.getElementById(actionTargetId) : null;
                    if (actionTarget) {
                        state.newsletterFocusedEditor = actionTargetId;
                        actionTarget.focus();
                        if (action === 'block') {
                            restoreRichSelection(actionTarget);
                            document.execCommand('formatBlock', false, actionValue === 'P' ? '<p>' : '<' + s(actionValue || 'p').toLowerCase() + '>');
                        } else if (action === 'size') {
                            var sizeMap = { sm: '0.92rem', lg: '1.12rem', xl: '1.28rem' };
                            if (actionValue === 'default') {
                                restoreRichSelection(actionTarget);
                                document.execCommand('removeFormat', false, null);
                            } else {
                                wrapSelectionWithStyle(actionTarget, 'font-size:' + (sizeMap[actionValue] || '1rem'));
                            }
                        } else if (action === 'color') {
                            var resolvedColor = resolveEditorThemeColor(actionValue, resolveEditorThemeColor('metis_text', '#1f2330'));
                            if (resolvedColor) wrapSelectionWithStyle(actionTarget, 'color:' + resolvedColor);
                        } else if (action === 'weight') {
                            wrapSelectionWithStyle(actionTarget, 'font-weight:' + (actionValue || '700'));
                        } else if (action === 'merge') {
                            restoreRichSelection(actionTarget);
                            document.execCommand('insertText', false, actionValue || '');
                        }
                        saveRichSelection(actionTarget);
                        hydrateNewsletterFromInputs();
                        queueNewsletterPreview();
                    }
                    root.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function (node) { node.classList.remove('is-open'); });
                    return;
                }
                if (richBtn) {
                    e.preventDefault();
                    var cmd = s(richBtn.getAttribute('data-rich-cmd') || '');
                    var targetId = s(richBtn.getAttribute('data-rich-target') || '');
                    var target = targetId ? document.getElementById(targetId) : null;
                    if (target) {
                        state.newsletterFocusedEditor = targetId;
                        target.focus();
                        restoreRichSelection(target);
                    }
                    if (cmd === 'createLink') {
                        requestLinkUrl().then(function(url) {
                            if (url) {
                                document.execCommand('createLink', false, url);
                            }
                            if (target) saveRichSelection(target);
                            hydrateNewsletterFromInputs();
                            queueNewsletterPreview();
                        });
                        return;
                    } else if (cmd === 'insertImagePrompt') {
                        if (target) {
                            saveRichSelection(target);
                            openInlineImageModal(target.id);
                        }
                    } else if (cmd === 'insertEmojiPrompt') {
                        requestEmoji().then(function(emojiValue) {
                            if (emojiValue && target) {
                                target.focus();
                                document.execCommand('insertText', false, emojiValue);
                            }
                            if (target) saveRichSelection(target);
                            hydrateNewsletterFromInputs();
                            queueNewsletterPreview();
                        });
                        return;
                    } else if (cmd === 'insertDivider') {
                        if (target) insertHtmlAtSelection(target, '<hr class="metis-inline-divider">');
                    } else if (cmd) {
                        document.execCommand(cmd, false, null);
                    }
                    if (target) saveRichSelection(target);
                    hydrateNewsletterFromInputs();
                    queueNewsletterPreview();
                    return;
                }
                var prev = e.target.closest('#metis-nl-prev');
                var next = e.target.closest('#metis-nl-next');
                if (prev) {
                    syncStepUi();
                    return;
                }
                if (next) {
                    e.preventDefault();
                    if (!newsletterRequiredFieldsReady()) {
                        state.newsletterDetailsExpanded = true;
                        state.step = 2;
                        renderStep1();
                        renderStep2();
                        syncStepUi();
                        setStatus('Campaign name and subject are required before you can prepare delivery.', 'error');
                        return;
                    }
                    saveNewsletterState({ autosave: false }).then(function () {
                        state.step = 1;
                        renderStep1();
                        renderStep2();
                        syncStepUi();
                        var deliveryStep = document.getElementById('metis-nl-step-1');
                        if (deliveryStep && deliveryStep.scrollIntoView) {
                            deliveryStep.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    }).catch(function () {});
                    return;
                }
                if (prev) {
                    e.preventDefault();
                    state.step = 2;
                    syncStepUi();
                    return;
                }
            });

            root.addEventListener('focusin', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.metis-nl-rich-editor') : null;
                if (target && target.id) state.newsletterFocusedEditor = target.id;
                if (target) markNewsletterEditorEmpty(target);
            });
            root.addEventListener('mouseup', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.metis-se-rich-editor') : null;
                if (target) {
                    saveRichSelection(target);
                    if (target.id === 'metis-nl-body-editor') {
                        state.newsletterDoc.body_html = normalizeNewsletterEditorHtml(target.innerHTML || '');
                    }
                }
            });
            root.addEventListener('keyup', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.metis-se-rich-editor') : null;
                if (target) {
                    saveRichSelection(target);
                    if (target.id === 'metis-nl-body-editor') {
                        state.newsletterDoc.body_html = normalizeNewsletterEditorHtml(target.innerHTML || '');
                    }
                }
            });
            root.addEventListener('focusout', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.metis-se-rich-editor') : null;
                if (target && target.id === 'metis-nl-body-editor') {
                    state.newsletterDoc.body_html = normalizeNewsletterEditorHtml(target.innerHTML || '');
                }
            });

            root.addEventListener('input', function (e) {
                var target = e.target;
                if (target && target.classList && target.classList.contains('metis-se-rich-editor')) {
                    saveRichSelection(target);
                    markNewsletterEditorEmpty(target);
                    if (target.id === 'metis-nl-body-editor') {
                        state.newsletterDoc.body_html = normalizeNewsletterEditorHtml(target.innerHTML || '');
                    }
                }
                if (target && target.id === 'metis-nl-name') {
                    var titleEl = document.getElementById('metis-se-top-title');
                    if (titleEl) titleEl.textContent = newsletterTopTitle();
                }
                hydrateNewsletterFromInputs();
                queueNewsletterPreview();
                scheduleNewsletterAutosave();
            });

            root.addEventListener('change', function () {
                hydrateNewsletterFromInputs();
                queueNewsletterPreview();
                scheduleNewsletterAutosave();
                var scheduleRow = root.querySelector('.metis-nl-schedule-row');
                if (scheduleRow) scheduleRow.hidden = state.newsletter.status !== 'scheduled';
                var rulesRow = root.querySelector('.metis-nl-rules-row');
                if (rulesRow) rulesRow.hidden = state.newsletterAudience.mode !== 'custom';
                var deliverBtn = document.getElementById('metis-nl-deliver');
                if (deliverBtn) deliverBtn.textContent = state.newsletter.status === 'scheduled' ? 'Schedule Campaign' : 'Send Now';
            });
        }

        renderShell();
        renderStep1();
        renderStep2();
        renderStep3();
        syncStepUi();
        renderPreviewMeta();
        wire();
        loadNewsletterEntity();
        hideBootStatus();
    }

    function bootStructuredEditorV2() {
        var PAGE_SECTION_TYPES = ['heading', 'text', 'image', 'button', 'columns', 'hero', 'feature_grid', 'card_grid', 'html', 'cta', 'events', 'form', 'donation_form', 'donation_progress', 'campaign_summary', 'divider', 'spacer', 'posts_list'];
        var POST_SECTION_TYPES = ['heading', 'text', 'image', 'button', 'columns', 'feature_grid', 'card_grid', 'html', 'transcript', 'cta', 'events', 'form', 'donation_form', 'donation_progress', 'campaign_summary', 'divider', 'spacer', 'posts_list'];
        var HERO_STYLES = ['split', 'centered', 'overlay'];

        state.sections = [];
        state.activeSection = -1;
        state.hero = {
            enabled: false,
            style: 'split',
            headline: '',
            subtext: '',
            primary_cta_label: '',
            primary_cta_link: '',
            media_url: ''
        };
        state.revisions = [];
        state.reusableBlocks = [];
        state.recoveryAvailable = false;
        state.recoveryKey = '';

        function formatLastEditValue(value) {
            var raw = s(value).trim();
            if (!raw) return '—';
            var normalized = raw.replace(' ', 'T');
            var dt = new Date(normalized);
            if (isNaN(dt.getTime())) return raw;
            return formatConfiguredDateTime(dt, raw);
        }

        var EMOJI_SHORTCODES = {
            ':wave:': '👋',
            ':sparkles:': '✨',
            ':heart:': '❤️',
            ':star:': '⭐',
            ':fire:': '🔥',
            ':check:': '✔️',
            ':point_right:': '👉',
            ':point_left:': '👈',
            ':ear:': '👂',
            ':microphone:': '🎤',
            ':calendar:': '📅',
            ':tada:': '🎉',
            ':smile:': '😊'
        };
        var richSelections = {};

        function emojiValueFromInput(value) {
            var raw = s(value || '').trim();
            if (!raw) return '';
            return EMOJI_SHORTCODES[raw.toLowerCase()] || raw;
        }

        function saveRichSelection(target) {
            if (!target || !target.id) return;
            var sel = window.getSelection ? window.getSelection() : null;
            if (!sel || !sel.rangeCount) return;
            var range = sel.getRangeAt(0);
            if (!target.contains(range.commonAncestorContainer)) return;
            richSelections[target.id] = range.cloneRange();
        }

        function restoreRichSelection(target) {
            if (!target || !target.id) return false;
            var sel = window.getSelection ? window.getSelection() : null;
            var range = richSelections[target.id];
            if (!sel || !range) return false;
            try {
                sel.removeAllRanges();
                sel.addRange(range);
                return true;
            } catch (_err) {
                return false;
            }
        }

        function richEditorFromSelection() {
            var sel = window.getSelection ? window.getSelection() : null;
            if (!sel || !sel.rangeCount) return null;
            var node = sel.getRangeAt(0).commonAncestorContainer;
            var element = node && node.nodeType === 1 ? node : node && node.parentElement;
            return element && element.closest ? element.closest('.metis-se-rich-editor') : null;
        }

        function richEditorForControl(control) {
            if (!control) return null;
            var targetHolder = control.closest('[data-rich-target]');
            var targetId = s((targetHolder && targetHolder.getAttribute('data-rich-target')) || control.getAttribute('data-rich-target') || '');
            var target = targetId ? document.getElementById(targetId) : null;
            if (target) return target;
            target = richEditorFromSelection();
            if (target) return target;
            if (document.activeElement && document.activeElement.closest) {
                target = document.activeElement.closest('.metis-se-rich-editor');
                if (target) return target;
            }
            return state.activeRichTargetId ? document.getElementById(state.activeRichTargetId) : null;
        }

        function syncRichEditorToSection(target) {
            if (!target || !target.closest) return;
            var inlineBlock = target.closest('[data-v2-inline]');
            if (inlineBlock) {
                var inlineIndex = parseInt(s(inlineBlock.getAttribute('data-inline-index') || '-1'), 10);
                var inlineField = s(inlineBlock.getAttribute('data-v2-inline') || '');
                var inlineSection = inlineIndex >= 0 && state.sections[inlineIndex] ? state.sections[inlineIndex] : null;
                if (!inlineSection) return;
                inlineSection.content = inlineSection.content && typeof inlineSection.content === 'object' ? inlineSection.content : {};
                if (inlineField === 'text_body') inlineSection.content.body = inlineBlock.innerHTML;
                return;
            }
            var inlineCol = target.closest('[data-v2-inline-col]');
            if (inlineCol) {
                var blockIndex = parseInt(s(inlineCol.getAttribute('data-inline-index') || '-1'), 10);
                var colIndex = parseInt(s(inlineCol.getAttribute('data-v2-inline-col') || '-1'), 10);
                var colSection = blockIndex >= 0 && state.sections[blockIndex] ? state.sections[blockIndex] : null;
                if (colSection && Array.isArray(colSection.content && colSection.content.columns) && colSection.content.columns[colIndex]) {
                    colSection.content.columns[colIndex].body = inlineCol.innerHTML;
                }
            }
        }

        function selectedHtmlFromRange(range) {
            if (!range) return '';
            var wrap = document.createElement('div');
            wrap.appendChild(range.cloneContents());
            return wrap.innerHTML;
        }

        function normalizeStyledSelectionHtml(html, prefix) {
            var wrap = document.createElement('div');
            wrap.innerHTML = s(html || '');
            wrap.querySelectorAll('span').forEach(function (node) {
                Array.prototype.slice.call(node.classList || []).forEach(function (className) {
                    if (s(className || '').indexOf(prefix) === 0) {
                        node.classList.remove(className);
                    }
                });
                if (!node.className.trim()) {
                    while (node.firstChild) node.parentNode.insertBefore(node.firstChild, node);
                    node.parentNode.removeChild(node);
                }
            });
            return wrap.innerHTML;
        }

        function sanitizeRichTextColor(value) {
            var raw = s(value || '').trim();
            if (/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(raw)) return raw;
            return '';
        }

        function removeInlineTextColor(html) {
            var wrap = document.createElement('div');
            wrap.innerHTML = s(html || '');
            wrap.querySelectorAll('[style]').forEach(function (node) {
                var style = s(node.getAttribute('style') || '').split(';').filter(function (rule) {
                    return s(rule || '').trim().toLowerCase().indexOf('color:') !== 0;
                }).join('; ');
                if (style) node.setAttribute('style', style);
                else node.removeAttribute('style');
            });
            return wrap.innerHTML;
        }

        function captureRichColorSelection(picker) {
            if (!picker) return;
            var targetId = s(picker.getAttribute('data-rich-target') || '');
            var target = (targetId ? document.getElementById(targetId) : null) || richEditorForControl(picker);
            if (!target || !target.id) return;
            var sel = window.getSelection ? window.getSelection() : null;
            if (sel && sel.rangeCount) {
                var liveRange = sel.getRangeAt(0);
                if (!liveRange.collapsed && target.contains(liveRange.commonAncestorContainer)) {
                    sharedRichSelections[target.id] = liveRange.cloneRange();
                    sharedRichColorSelections[target.id] = liveRange.cloneRange();
                    return;
                }
            }
            if (sharedRichSelections[target.id]) {
                sharedRichColorSelections[target.id] = sharedRichSelections[target.id].cloneRange();
            }
        }

        function applyTextColor(target, value) {
            var color = sanitizeRichTextColor(value);
            if (!target || !target.id || !color) return;
            var savedRange = sharedRichColorSelections[target.id]
                ? sharedRichColorSelections[target.id].cloneRange()
                : (sharedRichSelections[target.id] ? sharedRichSelections[target.id].cloneRange() : null);
            target.focus();
            if (savedRange) {
                var sel = window.getSelection ? window.getSelection() : null;
                if (sel) {
                    try {
                        sel.removeAllRanges();
                        sel.addRange(savedRange);
                    } catch (_err) {}
                }
            } else {
                restoreRichSelection(target);
            }
            var range = savedRange;
            var liveSel = window.getSelection ? window.getSelection() : null;
            if ((!range || !target.contains(range.commonAncestorContainer)) && liveSel && liveSel.rangeCount) {
                range = liveSel.getRangeAt(0);
            }
            if (!range || range.collapsed || !target.contains(range.commonAncestorContainer)) return;
            var html = normalizeStyledSelectionHtml(selectedHtmlFromRange(range), 'metis-text-color-');
            html = removeInlineTextColor(html);
            range.deleteContents();
            var wrap = document.createElement('span');
            wrap.setAttribute('style', 'color: ' + color + ' !important;');
            wrap.innerHTML = html;
            range.insertNode(wrap);
            var selected = document.createRange();
            selected.selectNode(wrap);
            sharedRichSelections[target.id] = selected.cloneRange();
            sharedRichColorSelections[target.id] = selected.cloneRange();
            if (liveSel) {
                liveSel.removeAllRanges();
                liveSel.addRange(selected);
            }
        }

        function insertHtmlAtSelection(target, html) {
            if (!target) return;
            target.focus();
            restoreRichSelection(target);
            if (document.execCommand) {
                document.execCommand('insertHTML', false, html);
                saveRichSelection(target);
            }
        }

        function normalizeEditorLinkUrl(url) {
            var raw = s(url || '').trim();
            if (!raw) return '';
            if (/^(https?:|mailto:|tel:|\/|#)/i.test(raw)) return raw;
            return 'https://' + raw;
        }

        function unwrapLinksFromHtml(html) {
            var wrap = document.createElement('div');
            wrap.innerHTML = s(html || '');
            wrap.querySelectorAll('a').forEach(function (node) {
                while (node.firstChild) node.parentNode.insertBefore(node.firstChild, node);
                node.parentNode.removeChild(node);
            });
            return wrap.innerHTML;
        }

        function applyLinkAtSelection(target, url) {
            var linkUrl = normalizeEditorLinkUrl(url);
            if (!target || !linkUrl) return false;
            target.focus();
            restoreRichSelection(target);
            var sel = window.getSelection ? window.getSelection() : null;
            if (!sel || !sel.rangeCount) return false;
            var range = sel.getRangeAt(0);
            if (!target.contains(range.commonAncestorContainer)) return false;
            var selectedHtml = range.collapsed ? esc(linkUrl) : unwrapLinksFromHtml(selectedHtmlFromRange(range));
            range.deleteContents();
            document.execCommand('insertHTML', false, '<a href="' + esc(linkUrl) + '">' + selectedHtml + '</a>');
            saveRichSelection(target);
            return true;
        }

        function applySpanPreset(target, prefix, value) {
            if (!target) return;
            target.focus();
            restoreRichSelection(target);
            var sel = window.getSelection ? window.getSelection() : null;
            if (!sel || !sel.rangeCount) return;
            var range = sel.getRangeAt(0);
            if (range.collapsed || !target.contains(range.commonAncestorContainer)) return;
            var html = normalizeStyledSelectionHtml(selectedHtmlFromRange(range), prefix);
            if (s(value || '') !== 'default') {
                html = '<span class="' + prefix + s(value || '') + '">' + html + '</span>';
            }
            range.deleteContents();
            document.execCommand('insertHTML', false, html);
            saveRichSelection(target);
        }

        function richAlignmentValue(cmd) {
            if (cmd === 'justifyLeft') return 'left';
            if (cmd === 'justifyCenter') return 'center';
            if (cmd === 'justifyRight') return 'right';
            return '';
        }

        function removeClassPrefix(node, prefix) {
            Array.prototype.slice.call(node && node.classList ? node.classList : []).forEach(function (className) {
                if (s(className || '').indexOf(prefix) === 0) {
                    node.classList.remove(className);
                }
            });
        }

        function richBlockNodesInRange(target, range) {
            var selector = 'p,div,h1,h2,h3,h4,h5,h6,li,blockquote';
            var nodes = [];
            function add(node) {
                if (!node || node === target || !node.matches || !node.matches(selector) || nodes.indexOf(node) !== -1) return;
                if (target.contains(node)) nodes.push(node);
            }
            function nearestBlock(node) {
                var el = node && node.nodeType === 1 ? node : node && node.parentElement;
                while (el && el !== target) {
                    if (el.matches && el.matches(selector)) return el;
                    el = el.parentElement;
                }
                return null;
            }
            add(nearestBlock(range.startContainer));
            add(nearestBlock(range.endContainer));
            if (!range.collapsed && target.querySelectorAll && range.intersectsNode) {
                target.querySelectorAll(selector).forEach(function (node) {
                    try {
                        if (range.intersectsNode(node)) add(node);
                    } catch (_err) {}
                });
            }
            return nodes;
        }

        function applyBlockClassPreset(target, prefix, value) {
            if (!target) return;
            target.focus();
            restoreRichSelection(target);
            var sel = window.getSelection ? window.getSelection() : null;
            if (!sel || !sel.rangeCount) return;
            var range = sel.getRangeAt(0);
            if (!target.contains(range.commonAncestorContainer)) return;
            var nodes = richBlockNodesInRange(target, range);
            if (!nodes.length && document.execCommand) {
                document.execCommand('formatBlock', false, 'P');
                sel = window.getSelection ? window.getSelection() : null;
                if (sel && sel.rangeCount) nodes = richBlockNodesInRange(target, sel.getRangeAt(0));
            }
            nodes.forEach(function (node) {
                removeClassPrefix(node, prefix);
                if (s(value || '') !== 'default') {
                    node.classList.add(prefix + s(value || ''));
                }
            });
            saveRichSelection(target);
        }

        function richToolbarDropdown(label, icon, menuHtml, extraClass) {
            return '<div class="metis-se-rich-dropdown ' + esc(extraClass || '') + '">' +
                '<button type="button" class="metis-se-toolbtn metis-se-rich-menu-trigger metis-se-rich-icon-btn" data-rich-toggle="menu" title="' + esc(label) + '" aria-label="' + esc(label) + '">' +
                    '<img src="' + esc(iconUrl(icon)) + '" data-icon-fallback="' + esc(iconFallbackUrl(icon)) + '" alt="" aria-hidden="true">' +
                '</button>' +
                '<div class="metis-se-rich-menu">' + menuHtml + '</div>' +
            '</div>';
        }

        function richToolbarV2(targetId) {
            var cmds = [
                { c: 'italic', icon: 'italic', label: 'Italic' },
                { c: 'underline', icon: 'text-underline', label: 'Underline' },
                { c: 'strikeThrough', icon: 'text-strikethrough', label: 'Strike Through' },
                { c: 'justifyLeft', icon: 'text-align-left', label: 'Align Left' },
                { c: 'justifyCenter', icon: 'text-align-center', label: 'Align Center' },
                { c: 'justifyRight', icon: 'text-align-right', label: 'Align Right' },
                { c: 'insertImagePrompt', icon: 'image', label: 'Insert Image' },
                { c: 'insertEmojiPrompt', icon: 'emoji', label: 'Insert Emoji' },
                { c: 'insertUnorderedList', icon: 'list-bulleted', label: 'Bulleted List' },
                { c: 'insertOrderedList', icon: 'list-boxes', label: 'Numbered List' },
                { c: 'outdent', icon: 'text-indent-less', label: 'Decrease List Level' },
                { c: 'indent', icon: 'text-indent-more', label: 'Increase List Level' },
                { c: 'createLink', icon: 'link', label: 'Insert Link' },
                { c: 'unlink', icon: 'close-outline', label: 'Remove Link' },
                { c: 'insertDivider', icon: 'divider', label: 'Insert Divider' },
                { c: 'removeFormat', icon: 'text-clear-format', label: 'Clear Format' },
                { c: 'undo', icon: 'undo', label: 'Undo' },
                { c: 'redo', icon: 'redo', label: 'Redo' }
            ];
            var blockMenu = [
                ['P', 'Paragraph'],
                ['H1', 'Heading 1'],
                ['H2', 'Heading 2'],
                ['H3', 'Heading 3'],
                ['H4', 'Heading 4'],
                ['PRE', 'Code']
            ].map(function (row) {
                return '<button type="button" class="metis-se-rich-menu-item" data-rich-action="block" data-rich-target="' + esc(targetId) + '" data-rich-value="' + esc(row[0]) + '">' + esc(row[1]) + '</button>';
            }).join('');
            var sizeMenu = [
                ['default', 'Default'],
                ['sm', 'Small'],
                ['lg', 'Large'],
                ['xl', 'Large+']
            ].map(function (row) {
                return '<button type="button" class="metis-se-rich-menu-item" data-rich-action="size" data-rich-target="' + esc(targetId) + '" data-rich-value="' + esc(row[0]) + '">' + esc(row[1]) + '</button>';
            }).join('');
            var weightMenu = [
                ['600', 'Semi Bold'],
                ['700', 'Bold'],
                ['800', 'Extra Bold']
            ].map(function (row) {
                return '<button type="button" class="metis-se-rich-menu-item" data-rich-action="weight" data-rich-target="' + esc(targetId) + '" data-rich-value="' + esc(row[0]) + '">' + esc(row[1]) + '</button>';
            }).join('');
            return '<div class="metis-se-rich-tools">' +
                '<div class="metis-se-rich-toolbar">' +
                    '<div class="metis-se-rich-group metis-se-rich-group--actions">' +
                        richToolbarDropdown('Paragraph', 'h1', blockMenu, 'metis-se-rich-dropdown--format') +
                        richToolbarDropdown('Text Size', 'text-scale', sizeMenu, 'metis-se-rich-dropdown--size') +
                        '<label class="metis-se-toolbtn metis-se-rich-icon-btn metis-se-rich-color-picker" title="Text Color" aria-label="Text Color">' +
                            '<img src="' + esc(iconUrl('text-color')) + '" data-icon-fallback="' + esc(iconFallbackUrl('text-color')) + '" alt="" aria-hidden="true">' +
                            '<input type="color" data-rich-action="color-picker" data-rich-target="' + esc(targetId) + '" value="#1f2330">' +
                        '</label>' +
                        richToolbarDropdown('Weight', 'text-bold', weightMenu, 'metis-se-rich-dropdown--weight') +
                        cmds.map(function (item) {
                            var valAttr = item.v ? ' data-rich-val="' + esc(item.v) + '"' : '';
                            return '<button type="button" class="metis-se-toolbtn metis-se-rich-icon-btn" data-rich-cmd="' + esc(item.c) + '"' + valAttr + ' data-rich-target="' + esc(targetId) + '" title="' + esc(item.label) + '" aria-label="' + esc(item.label) + '">' +
                                '<img src="' + esc(iconUrl(item.icon)) + '" data-icon-fallback="' + esc(iconFallbackUrl(item.icon)) + '" alt="" aria-hidden="true">' +
                            '</button>';
                        }).join('') +
                    '</div>' +
                '</div>' +
            '</div>';
        }

        function sectionTypeLabel(type) {
            if (type === 'section_header') return 'Section Header';
            if (type === 'feature_grid') return 'Feature Grid';
            if (type === 'card_grid') return 'Card Grid';
            if (type === 'posts_list') return 'Posts List';
            if (type === 'transcript') return 'Transcript';
            if (type === 'html') return 'Embed / HTML';
            if (type === 'cta') return 'CTA';
            if (type === 'donation_form') return 'Donation Form';
            if (type === 'donation_progress') return 'Donation Progress';
            if (type === 'campaign_summary') return 'Campaign Summary';
            return s(type || 'text').replace('_', ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
        }

        function sectionDescription(type) {
            var map = {
                section_header: 'Full-width H1 section band',
                heading: 'Page heading or section title',
                text: 'Rich text content',
                image: 'Single responsive image',
                button: 'Link button',
                columns: 'Two to four text columns',
                hero: 'Lead panel with media and CTA',
                feature_grid: 'Icon feature cards',
                card_grid: 'Simple card grid',
                html: 'Sanitized embed or HTML',
                cta: 'Call-to-action panel',
                events: 'Events listing',
                form: 'Embedded Metis form',
                donation_form: 'Donation form for a campaign',
                donation_progress: 'Campaign progress bar',
                campaign_summary: 'Campaign content panel',
                divider: 'Horizontal rule',
                spacer: 'Vertical spacing',
                posts_list: 'Post listing',
                transcript: 'Speaker transcript'
            };
            return map[s(type || '')] || 'Content block';
        }

        function availableSectionTypes() {
            return isPostContext() ? POST_SECTION_TYPES : PAGE_SECTION_TYPES;
        }

        function maxSteps() {
            return isPageContext() ? 4 : 3;
        }

        function sectionEditorStepNumber() {
            return isPageContext() ? 3 : 2;
        }

        function previewStepNumber() {
            return isPageContext() ? 4 : 3;
        }

        function defaultHeroState() {
            return {
                enabled: false,
                style: 'split',
                headline: '',
                subtext: '',
                primary_cta_label: '',
                primary_cta_link: '',
                media_url: ''
            };
        }

        function normalizeHeroState(hero, isHomepage) {
            var src = hero && typeof hero === 'object' ? hero : {};
            var style = HERO_STYLES.indexOf(s(src.style || src.type || 'split')) !== -1 ? s(src.style || src.type || 'split') : 'split';
            return {
                enabled: !!src.enabled && !!isHomepage,
                style: style,
                headline: s(src.headline || '').trim(),
                subtext: s(src.subtext || '').trim(),
                primary_cta_label: s(src.primary_cta_label || '').trim(),
                primary_cta_link: s(src.primary_cta_link || '').trim(),
                media_url: s(src.media_url || '').trim()
            };
        }

        function defaultSectionByType(type) {
            var allowed = availableSectionTypes();
            var t = allowed.indexOf(s(type)) === -1 ? 'text' : s(type);
            var base = {
                id: uid(),
                type: t,
                header: null,
                subtext: null,
                settings: normalizeSectionSettings({}),
                content: {}
            };
            if (t === 'transcript') base.content = { source: '', rows: [] };
            else if (t === 'heading') base.content = { text: 'Heading', level: 'h2', align: 'left', vertical_align: 'top' };
            else if (t === 'image') base.content = { src: '', alt: '', caption: '', width: '', height: '' };
            else if (t === 'button') base.content = { label: 'Learn more', url: '#', align: 'left' };
            else if (t === 'hero') base.content = { title: 'Hero Title', subtitle: '', cta_label: 'Learn More', cta_url: '#', image_src: '' };
            else if (t === 'html') base.content = { html: '<div></div>' };
            else if (t === 'columns') base.content = { columns: [{ width: '50%', body: '<p></p>' }, { width: '50%', body: '<p></p>' }] };
            else if (t === 'feature_grid') base.content = { columns: 3, items: [{ icon: '', title: 'Feature', text: '', cta: { label: '', url: '#' } }] };
            else if (t === 'card_grid') base.content = { columns: 3, items: [{ icon: '', title: 'Card', text: '', cta: { label: '', url: '#' } }] };
            else if (t === 'cta') base.content = { layout: 'single', items: [{ title: 'Call to Action', text: '', button: { label: 'Learn More', url: '#' } }] };
            else if (t === 'events') base.content = { source: 'calendar', limit: 5 };
            else if (t === 'form') base.content = { form_id: '', submit_label: 'Submit' };
            else if (t === 'donation_form') base.content = { campaign_id: '', preset_amounts: [25, 50, 100], allow_custom_amount: true, mode: 'both', show_name: true, show_email: true, show_phone: false };
            else if (t === 'donation_progress') base.content = { campaign_id: '', goal_amount: '', raised_amount: '', percent: '' };
            else if (t === 'campaign_summary') base.content = { campaign_id: '', title: '', content: '<p></p>', image: '' };
            else if (t === 'divider') base.content = { label: '', style: 'solid' };
            else if (t === 'spacer') base.content = { height: 'medium' };
            else if (t === 'posts_list') base.content = { source: 'this_page', specific_page: 0, category_ids: [], limit: 5, sort: 'latest' };
            else base.content = { body: '<p></p>' };
            return base;
        }

        function defaultSectionFromLibraryType(type) {
            var t = s(type || 'text');
            if (t === 'section_header') {
                var header = defaultSectionByType('heading');
                header.content = {
                    text: 'Section Header',
                    level: 'h1',
                    align: 'center',
                    vertical_align: 'middle',
                    variant: 'section_header'
                };
                header.settings = normalizeSectionSettings({ background: 'muted' });
                return header;
            }
            return defaultSectionByType(t);
        }

        function normalizeImageDimension(value) {
            var raw = s(value || '').trim();
            if (!raw) return '';
            var number = parseInt(raw.replace(/[^0-9]/g, ''), 10) || 0;
            if (number < 1 || number > 4000) return '';
            return String(number);
        }

        function normalizeDecimalString(value, maxValue) {
            var raw = s(value || '').trim();
            if (!raw) return '';
            var n = parseFloat(raw.replace(/[^0-9.]/g, ''));
            if (!isFinite(n) || n < 0) return '';
            n = Math.min(n, maxValue || 1000000000);
            return String(Math.round(n * 100) / 100).replace(/\.0+$/, '').replace(/(\.\d*[1-9])0+$/, '$1');
        }

        function normalizePercentString(value) {
            var raw = normalizeDecimalString(value, 100);
            return raw;
        }

        function normalizePresetAmounts(value) {
            var source = Array.isArray(value) ? value : s(value || '').split(/[,\s]+/);
            var seen = {};
            var out = [];
            source.forEach(function (item) {
                var n = parseFloat(s(item || '').replace(/[^0-9.]/g, ''));
                if (!isFinite(n) || n <= 0 || n > 1000000) return;
                var rounded = Math.round(n * 100) / 100;
                var key = String(rounded);
                if (seen[key]) return;
                seen[key] = true;
                out.push(rounded);
            });
            return out.slice(0, 8);
        }

        function presetAmountsInputValue(value) {
            var amounts = normalizePresetAmounts(value);
            if (!amounts.length) amounts = [25, 50, 100];
            return amounts.map(function (amount) { return String(amount); }).join(', ');
        }

        function optionList(options, selected, emptyLabel) {
            var html = '<option value="">' + esc(emptyLabel || 'Select') + '</option>';
            (Array.isArray(options) ? options : []).forEach(function (row) {
                var value = s(row && row.value || '');
                if (!value) return;
                html += '<option value="' + esc(value) + '"' + (value === s(selected || '') ? ' selected' : '') + '>' + esc(s(row && row.label || value)) + '</option>';
            });
            return html;
        }

        function optionLabel(options, selected, fallback) {
            var value = s(selected || '');
            var match = (Array.isArray(options) ? options : []).filter(function (row) {
                return s(row && row.value || '') === value;
            })[0];
            return match ? s(match.label || value) : (fallback || '');
        }

        function repairMojibakeText(value) {
            var current = s(value || '');
            if (!current) return '';
            var replacements = {
                'Ã¢ÂÂ': '’',
                'Ã¢ÂÂ': '‘',
                'Ã¢ÂÂ': '“',
                'Ã¢ÂÂ': '”',
                'Ã¢ÂÂ¦': '…',
                'Ã¢ÂÂ': '–',
                'Ã¢ÂÂ': '—',
                'â': '’',
                'â': '‘',
                'â': '“',
                'â': '”',
                'â¦': '…',
                'â': '–',
                'â': '—',
                'ÃÂ ': ' ',
                'Â ': ' '
            };
            Object.keys(replacements).forEach(function (key) {
                current = current.split(key).join(replacements[key]);
            });
            current = current.replace(/&Acirc;(&nbsp;|&#160;|&#xA0;|&#xa0;)/gi, ' ');
            current = current.replace(/Â(&nbsp;|&#160;|&#xA0;|&#xa0;)/gi, ' ');
            current = current.replace(/Ã+(?=\s|$)/g, '');
            current = current.replace(/Ã(?=[A-Za-z0-9])/g, '');
            current = current.replace(/\u00c2+(?=\s|$)/g, '');
            current = current.replace(/\u00c2(?=[A-Za-z0-9])/g, '');
            current = current.replace(/\u00a0/g, ' ');
            current = current.replace(/\uFFFD+/g, '');
            return current;
        }

        function repairMojibakeHtml(html) {
            var raw = repairMojibakeText(html || '');
            if (!raw || raw.indexOf('<') === -1) return raw;
            var template = document.createElement('template');
            template.innerHTML = raw;
            Array.prototype.slice.call(template.content.querySelectorAll('*')).forEach(function (node) {
                Array.prototype.slice.call(node.childNodes).forEach(function (child) {
                    if (child.nodeType === 3) child.nodeValue = repairMojibakeText(child.nodeValue || '');
                });
            });
            return template.innerHTML;
        }

        function normalizeSectionSettings(settings) {
            var src = settings && typeof settings === 'object' ? settings : {};
            var bg = s(src.background || 'default');
            if (['default', 'surface', 'muted', 'primary_tint', 'accent_tint'].indexOf(bg) === -1) bg = 'default';
            return { background: bg };
        }

        function backgroundOptions(selected) {
            var current = s(selected || 'default');
            var rows = [
                ['default', 'Default'],
                ['surface', 'Surface'],
                ['muted', 'Muted Surface'],
                ['primary_tint', 'Primary Tint'],
                ['accent_tint', 'Accent Tint']
            ];
            return rows.map(function (row) {
                return '<option value="' + esc(row[0]) + '"' + (current === row[0] ? ' selected' : '') + '>' + esc(row[1]) + '</option>';
            }).join('');
        }

        function blockBackgroundClass(section) {
            var settings = normalizeSectionSettings(section && section.settings || {});
            return settings.background === 'default' ? '' : ' is-bg-' + settings.background.replace('_', '-');
        }

        function blockVariantClass(section) {
            var type = s(section && section.type || '');
            var content = section && section.content && typeof section.content === 'object' ? section.content : {};
            if (type === 'heading' && s(content.variant || '') === 'section_header') {
                return ' is-heading-section-header';
            }
            return '';
        }

        function blockImageTargetFromId(raw) {
            var match = s(raw || '').match(/^block-image:(\d+):(src|image_src|image)$/);
            if (!match) return null;
            return {
                index: parseInt(match[1], 10),
                field: match[2]
            };
        }

        function openBlockImagePicker(index, field) {
            openInlineImageModal('block-image:' + String(index) + ':' + s(field || 'src'));
        }

        function sanitizeTranscriptSource(value) {
            return s(value || '')
                .replace(/\r\n?/g, '\n')
                .split('\n')
                .map(function (line) { return s(line).replace(/[ \t]+/g, ' ').trim(); })
                .join('\n')
                .trim();
        }

        function sanitizeTranscriptSpeaker(value) {
            var speaker = s(value || '').replace(/\s+/g, ' ').trim();
            if (!speaker || speaker.length > 48) return '';
            if (!/^[A-Za-z0-9 .,&()'"/-]+:?$/.test(speaker)) return '';
            return speaker.replace(/:+$/, '');
        }

        function normalizeTranscriptRows(rows) {
            var out = [];
            (Array.isArray(rows) ? rows : []).forEach(function (row) {
                if (!row || typeof row !== 'object') return;
                var type = s(row.type || 'message') === 'cue' ? 'cue' : 'message';
                var text = sanitizeTranscriptSource(row.text || '');
                if (!text) return;
                if (type === 'cue') {
                    out.push({ type: 'cue', text: text });
                    return;
                }
                var speaker = sanitizeTranscriptSpeaker(row.speaker || '');
                if (!speaker) return;
                out.push({ type: 'message', speaker: speaker, text: text });
            });
            return out.slice(0, 800);
        }

        function parseTranscriptSource(source) {
            var text = sanitizeTranscriptSource(source);
            if (!text) return [];
            var rows = [];
            var current = null;
            text.split('\n').forEach(function (line) {
                var trimmed = s(line).trim();
                var match;
                if (!trimmed) {
                    if (current) current.text += '\n\n';
                    return;
                }
                match = trimmed.match(/^\(([^()]{1,180})\)$/);
                if (match) {
                    if (current && current.text.trim()) rows.push({ type: 'message', speaker: current.speaker, text: current.text.trim() });
                    current = null;
                    rows.push({ type: 'cue', text: sanitizeTranscriptSource(match[1] || '') });
                    return;
                }
                match = trimmed.match(/^([A-Za-z0-9 .,&()'"\/-]{1,48}):\s*(.*)$/);
                if (match) {
                    if (current && current.text.trim()) rows.push({ type: 'message', speaker: current.speaker, text: current.text.trim() });
                    current = { speaker: sanitizeTranscriptSpeaker(match[1] || ''), text: sanitizeTranscriptSource(match[2] || '') };
                    if (!current.speaker) current = null;
                    return;
                }
                if (current) {
                    current.text += (current.text ? '\n' : '') + sanitizeTranscriptSource(trimmed);
                    return;
                }
                rows.push({ type: 'cue', text: sanitizeTranscriptSource(trimmed) });
            });
            if (current && current.text.trim()) rows.push({ type: 'message', speaker: current.speaker, text: current.text.trim() });
            return normalizeTranscriptRows(rows);
        }

        function transcriptSourceFromRows(rows) {
            var lines = [];
            normalizeTranscriptRows(rows).forEach(function (row) {
                if (row.type === 'cue') {
                    lines.push('(' + row.text + ')');
                    return;
                }
                var parts = s(row.text || '').split('\n');
                lines.push(row.speaker + ': ' + s(parts.shift() || '').trim());
                parts.forEach(function (part) { lines.push(s(part || '').trim()); });
            });
            return sanitizeTranscriptSource(lines.join('\n'));
        }

        function normalizeSection(section, idx) {
            var src = section && typeof section === 'object' ? section : {};
            var out = defaultSectionByType(s(src.type || 'text'));
            out.id = s(src.id || ('section_' + idx)) || ('section_' + idx);
            out.order = parseInt(s(src.order || idx), 10) || idx;
            out.metadata = src.metadata && typeof src.metadata === 'object' ? src.metadata : {};
            out.settings = normalizeSectionSettings(src.settings || {});
            out.header = repairMojibakeText(src.header || '').trim() || null;
            out.subtext = repairMojibakeText(src.subtext || '').trim() || null;
            var content = src.content && typeof src.content === 'object' ? src.content : {};
            if (out.type === 'heading') {
                var lvl = s(content.level || 'h2').toLowerCase();
                var headingAlign = s(content.align || 'left');
                var headingVertical = s(content.vertical_align || 'top');
                var headingVariant = s(content.variant || 'default') === 'section_header' ? 'section_header' : 'default';
                out.content.level = ['h1', 'h2', 'h3', 'h4'].indexOf(lvl) === -1 ? 'h2' : lvl;
                out.content.text = repairMojibakeText(content.text || src.header || 'Heading');
                out.content.align = ['left', 'center', 'right'].indexOf(headingAlign) === -1 ? 'left' : headingAlign;
                out.content.vertical_align = ['top', 'middle', 'bottom'].indexOf(headingVertical) === -1 ? 'top' : headingVertical;
                out.content.variant = headingVariant;
            } else if (out.type === 'text') {
                out.content.body = repairMojibakeHtml(content.body || '<p></p>') || '<p></p>';
            } else if (out.type === 'image') {
                out.content.src = s(content.src || '');
                out.content.alt = repairMojibakeText(content.alt || '');
                out.content.caption = repairMojibakeText(content.caption || '');
                out.content.width = normalizeImageDimension(content.width || '');
                out.content.height = normalizeImageDimension(content.height || '');
            } else if (out.type === 'button') {
                out.content.label = repairMojibakeText(content.label || 'Learn more');
                out.content.url = s(content.url || '#') || '#';
                out.content.align = ['left', 'center', 'right'].indexOf(s(content.align || 'left')) === -1 ? 'left' : s(content.align || 'left');
            } else if (out.type === 'hero') {
                out.content.title = repairMojibakeText(content.title || 'Hero Title');
                out.content.subtitle = repairMojibakeText(content.subtitle || '');
                out.content.cta_label = repairMojibakeText(content.cta_label || 'Learn More');
                out.content.cta_url = s(content.cta_url || '#') || '#';
                out.content.image_src = s(content.image_src || '');
            } else if (out.type === 'html') {
                out.content.html = s(content.html || '');
            } else if (out.type === 'transcript') {
                out.content.rows = normalizeTranscriptRows(content.rows);
                out.content.source = sanitizeTranscriptSource(content.source || '');
                if (!out.content.rows.length && out.content.source) out.content.rows = parseTranscriptSource(out.content.source);
                if (!out.content.source && out.content.rows.length) out.content.source = transcriptSourceFromRows(out.content.rows);
            } else if (out.type === 'columns') {
                var count = Math.max(1, Math.min(4, parseInt(s(content.count || ''), 10) || (Array.isArray(content.columns) ? content.columns.length : 2) || 2));
                var widths = ['100%', '50%', '33%', '25%'];
                var cols = [];
                for (var i = 0; i < count; i += 1) {
                    var row = Array.isArray(content.columns) && content.columns[i] && typeof content.columns[i] === 'object' ? content.columns[i] : {};
                    cols.push({ width: widths[count - 1], body: s(row.body || '<p></p>') || '<p></p>' });
                }
                out.content.columns = cols;
            } else if (out.type === 'feature_grid' || out.type === 'card_grid') {
                var fgCols = parseInt(s(content.columns || '3'), 10) || 3;
                if (fgCols < 2) fgCols = 2;
                if (fgCols > 4) fgCols = 4;
                out.content.columns = fgCols;
                out.content.items = (Array.isArray(content.items) ? content.items : []).map(function (item) {
                    var row = item && typeof item === 'object' ? item : {};
                    var cta = row.cta && typeof row.cta === 'object' ? row.cta : {};
                    return {
                        icon: s(row.icon || ''),
                        title: repairMojibakeText(row.title || ''),
                        text: repairMojibakeText(row.text || ''),
                        cta: { label: repairMojibakeText(cta.label || ''), url: s(cta.url || '#') || '#' }
                    };
                }).filter(function (_row, i) { return i < 16; });
                if (!out.content.items.length) out.content.items = [{ icon: '', title: out.type === 'card_grid' ? 'Card' : 'Feature', text: '', cta: { label: '', url: '#' } }];
            } else if (out.type === 'cta') {
                var layout = s(content.layout || 'single');
                if (layout !== 'split') layout = 'single';
                out.content.layout = layout;
                out.content.items = (Array.isArray(content.items) ? content.items : []).map(function (item) {
                    var row = item && typeof item === 'object' ? item : {};
                    var btn = row.button && typeof row.button === 'object' ? row.button : {};
                    return {
                        title: repairMojibakeText(row.title || ''),
                        text: repairMojibakeText(row.text || ''),
                        button: { label: repairMojibakeText(btn.label || ''), url: s(btn.url || '#') || '#' }
                    };
                }).filter(function (_row, i) { return i < 2; });
                if (!out.content.items.length) out.content.items = [{ title: 'Call to Action', text: '', button: { label: 'Learn More', url: '#' } }];
                if (layout === 'single') out.content.items = [out.content.items[0]];
                if (layout === 'split' && out.content.items.length < 2) out.content.items.push({ title: 'Secondary Action', text: '', button: { label: '', url: '#' } });
            } else if (out.type === 'events') {
                var source = s(content.source || 'calendar');
                out.content.source = source === 'manual' ? 'manual' : 'calendar';
                out.content.limit = Math.max(1, Math.min(50, parseInt(s(content.limit || '5'), 10) || 5));
            } else if (out.type === 'form') {
                out.content.form_id = s(content.form_id || '');
                out.content.submit_label = repairMojibakeText(content.submit_label || 'Submit') || 'Submit';
            } else if (out.type === 'donation_form') {
                var mode = s(content.mode || 'both');
                out.content.campaign_id = s(content.campaign_id || '');
                out.content.preset_amounts = normalizePresetAmounts(content.preset_amounts || [25, 50, 100]);
                if (!out.content.preset_amounts.length) out.content.preset_amounts = [25, 50, 100];
                out.content.allow_custom_amount = content.allow_custom_amount === undefined ? true : !!content.allow_custom_amount;
                out.content.mode = ['one_time', 'monthly', 'both'].indexOf(mode) === -1 ? 'both' : mode;
                out.content.show_name = content.show_name === undefined ? true : !!content.show_name;
                out.content.show_email = content.show_email === undefined ? true : !!content.show_email;
                out.content.show_phone = !!content.show_phone;
            } else if (out.type === 'donation_progress') {
                out.content.campaign_id = s(content.campaign_id || '');
                out.content.goal_amount = normalizeDecimalString(content.goal_amount || '', 1000000000);
                out.content.raised_amount = normalizeDecimalString(content.raised_amount || '', 1000000000);
                out.content.percent = normalizePercentString(content.percent || '');
            } else if (out.type === 'campaign_summary') {
                out.content.campaign_id = s(content.campaign_id || '');
                out.content.title = repairMojibakeText(content.title || '');
                out.content.content = repairMojibakeHtml(content.content || '<p></p>') || '<p></p>';
                out.content.image = s(content.image || '');
            } else if (out.type === 'divider') {
                var dividerStyle = s(content.style || 'solid');
                out.content.label = repairMojibakeText(content.label || '');
                out.content.style = ['solid', 'dashed', 'dotted'].indexOf(dividerStyle) === -1 ? 'solid' : dividerStyle;
            } else if (out.type === 'spacer') {
                var height = s(content.height || 'medium');
                out.content.height = ['small', 'medium', 'large'].indexOf(height) !== -1 ? height : 'medium';
            } else if (out.type === 'posts_list') {
                var listSource = s(content.source || 'this_page');
                out.content.source = listSource === 'specific_page' ? 'specific_page' : 'this_page';
                out.content.specific_page = Math.max(0, parseInt(s(content.specific_page || '0'), 10) || 0);
                out.content.category_ids = normalizeIdList(content.category_ids || []);
                if (out.content.source !== 'specific_page') out.content.specific_page = 0;
                out.content.limit = Math.max(1, Math.min(50, parseInt(s(content.limit || '5'), 10) || 5));
                out.content.sort = 'latest';
            }
            return out;
        }

        function normalizeSections(list, forcePost) {
            var arr = Array.isArray(list) ? list : [];
            var out = arr.map(function (sec, idx) { return normalizeSection(sec, idx); }).filter(Boolean);
            if (!out.length) out = [defaultSectionByType('text')];
            if (out.length > 24) out = out.slice(0, 24);
            return out;
        }

        function legacyHtmlFromLayout(parsed) {
            var meta = parsed && parsed.editor_meta && typeof parsed.editor_meta === 'object' ? parsed.editor_meta : {};
            var candidates = [
                s(meta.simple_html || ''),
                s(meta.html || ''),
                s(parsed && parsed.html || ''),
                s(parsed && parsed.content_html || '')
            ];
            for (var i = 0; i < candidates.length; i++) {
                var html = s(candidates[i] || '').trim();
                var text = html
                    .replace(/<[^>]+>/g, ' ')
                    .replace(/&nbsp;/gi, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
                if (text) return html;
            }
            return '';
        }

        function layoutModelFromLayout(raw, forcePost) {
            var parsed = j(s(raw || '{}'), {});
            var sb = parsed && parsed.editor_meta && parsed.editor_meta.structured_builder && typeof parsed.editor_meta.structured_builder === 'object'
                ? parsed.editor_meta.structured_builder
                : {};
            var pageTypeRaw = s(sb.page_type || '');
            var pageType = forcePost ? 'post' : (pageTypeRaw === 'homepage' ? 'homepage' : 'page');
            var hero = normalizeHeroState(sb.hero, pageType === 'homepage' && !forcePost);
            var templateKey = s(sb.template_key || '').trim().toLowerCase();

            if (Array.isArray(sb.sections) && sb.sections.length) {
                return {
                    page_type: pageType,
                    template_key: templateKey,
                    hero: hero,
                    sections: normalizeSections(sb.sections, !!forcePost)
                };
            }
            var sectionsRoot = parsed && Array.isArray(parsed.sections) ? parsed.sections : [];
            if (sectionsRoot.length) {
                var direct = sectionsRoot.filter(function (sec) {
                    return sec && typeof sec === 'object' && typeof sec.type === 'string';
                });
                if (direct.length) {
                    return {
                        page_type: pageType,
                        template_key: templateKey,
                        hero: hero,
                        sections: normalizeSections(direct, !!forcePost)
                    };
                }
            }
            var legacyHtml = legacyHtmlFromLayout(parsed);
            if (legacyHtml) {
                return {
                    page_type: pageType,
                    template_key: templateKey,
                    hero: hero,
                    sections: normalizeSections([
                        {
                            id: 'section_0',
                            type: 'text',
                            content: { body: legacyHtml }
                        }
                    ], !!forcePost)
                };
            }
            return {
                page_type: pageType,
                template_key: templateKey,
                hero: hero,
                sections: normalizeSections([], !!forcePost)
            };
        }

        function currentPageType() {
            if (isPostContext()) return 'post';
            var home = document.getElementById('metis-v2-homepage');
            return home && home.checked ? 'homepage' : 'page';
        }

        function activeSection() {
            if (!Array.isArray(state.sections) || !state.sections.length) state.sections = normalizeSections([], isPostContext());
            if (state.activeSection < 0) return state.sections[0];
            if (state.activeSection >= state.sections.length) state.activeSection = state.sections.length - 1;
            return state.sections[state.activeSection];
        }

        function sectionSummary(sec) {
            var title = s(sec && sec.header || '').trim();
            if (title) return title;
            return sectionTypeLabel(s(sec && sec.type || 'text'));
        }

        function availableTemplateOptions() {
            return state.options && Array.isArray(state.options.templates) ? state.options.templates : [];
        }

        function templateKeyExists(key) {
            var needle = s(key || '').trim().toLowerCase();
            if (!needle) return false;
            return availableTemplateOptions().some(function (row) {
                return s(row && row.value || '').trim().toLowerCase() === needle;
            });
        }

        function defaultTemplateKeyForPageType(pageType) {
            var preferred = s(state.options && state.options.activeTemplate && state.options.activeTemplate.key || '').trim().toLowerCase();
            if (templateKeyExists(preferred)) return preferred;
            if (!availableTemplateOptions().length && preferred) return preferred;
            preferred = s(state.options && state.options.defaultTemplateKey || '').trim().toLowerCase();
            if (templateKeyExists(preferred)) return preferred;
            if (!availableTemplateOptions().length && preferred) return preferred;
            if (pageType === 'homepage') return 'hero_split_glass';
            if (pageType === 'post') return 'editorial_focus';
            return 'centered_stack_marketing';
        }

        function normalizeSelectedTemplateKey(raw, pageType) {
            var resolvedPageType = s(pageType || currentPageType() || 'page').trim().toLowerCase();
            var candidate = s(raw || '').trim().toLowerCase();
            if (templateKeyExists(candidate)) return candidate;
            candidate = s(state.selectedTemplateKey || '').trim().toLowerCase();
            if (templateKeyExists(candidate)) return candidate;
            return defaultTemplateKeyForPageType(resolvedPageType);
        }

        function selectedTemplateKey(pageType) {
            var resolvedPageType = s(pageType || currentPageType() || 'page').trim().toLowerCase();
            var select = document.getElementById('metis-v2-template-key');
            var normalized = normalizeSelectedTemplateKey(select ? select.value : state.selectedTemplateKey, resolvedPageType);
            state.selectedTemplateKey = normalized;
            if (select && select.value !== normalized) select.value = normalized;
            return normalized;
        }

        function templateLabelForKey(key) {
            var needle = s(key || '').trim().toLowerCase();
            var found = availableTemplateOptions().filter(function (row) {
                return s(row && row.value || '').trim().toLowerCase() === needle;
            })[0];
            return s(found && found.label || needle || contentLabel() + ' default');
        }

        function templateSelectInnerHtml(selectedKey, pageType) {
            var value = normalizeSelectedTemplateKey(selectedKey, pageType);
            var html = availableTemplateOptions().map(function (row) {
                var optionValue = s(row && row.value || '').trim().toLowerCase();
                if (!optionValue) return '';
                var optionLabel = s(row && row.label || optionValue);
                return '<option value="' + esc(optionValue) + '"' + (optionValue === value ? ' selected' : '') + '>' + esc(optionLabel) + '</option>';
            }).join('');
            return html || '<option value="' + esc(value) + '">' + esc(templateLabelForKey(value)) + '</option>';
        }

        function syncTemplateField() {
            var pageType = currentPageType();
            var select = document.getElementById('metis-v2-template-key');
            var display = document.getElementById('metis-v2-template-display');
            var selected = selectedTemplateKey(pageType);
            if (select) {
                var html = templateSelectInnerHtml(selected, pageType);
                if (select.innerHTML !== html) select.innerHTML = html;
                if (select.value !== selected) select.value = selected;
            }
            if (display) display.textContent = templateLabelForKey(selected);
        }

        function layoutJsonFromState() {
            var pageType = currentPageType();
            var sections = normalizeSections(state.sections, isPostContext());
            sections.forEach(function (section, index) {
                section.order = index;
                section.metadata = section.metadata && typeof section.metadata === 'object' ? section.metadata : {};
                section.metadata.updated_at = (new Date()).toISOString();
            });
            var hero = normalizeHeroState(state.hero, pageType === 'homepage' && isPageContext());
            var templateKey = selectedTemplateKey(pageType);
            state.sections = sections;
            state.hero = hero;
            return JSON.stringify({
                version: 2,
                editor_meta: {
                    builder: 'structured_v1',
                    saved_at: (new Date()).toISOString(),
                    block_count: sections.length,
                    structured_builder: {
                        version: 1,
                        page_type: pageType,
                        template_key: templateKey,
                        template_override: templateKey !== defaultTemplateKeyForPageType(pageType),
                        hero: hero,
                        sections: sections
                    }
                },
                sections: []
            });
        }

        function blockLibraryTypes() {
            var allowed = availableSectionTypes();
            var preferred = isPostContext()
                ? ['section_header', 'heading', 'text', 'button', 'html', 'transcript', 'form', 'image', 'columns', 'feature_grid', 'card_grid', 'cta', 'divider', 'spacer', 'posts_list', 'events', 'donation_form', 'donation_progress', 'campaign_summary']
                : ['section_header', 'heading', 'text', 'button', 'html', 'form', 'image', 'hero', 'columns', 'feature_grid', 'card_grid', 'cta', 'divider', 'spacer', 'posts_list', 'events', 'donation_form', 'donation_progress', 'campaign_summary'];
            return preferred.filter(function (type) {
                if (type === 'section_header') return allowed.indexOf('heading') !== -1;
                return allowed.indexOf(type) !== -1;
            });
        }

        function blockCategory(type) {
            if (type === 'section_header' || type === 'heading' || type === 'text' || type === 'button' || type === 'html' || type === 'transcript') return 'Content';
            if (type === 'image' || type === 'hero') return 'Media';
            if (type === 'columns' || type === 'card_grid' || type === 'feature_grid' || type === 'cta' || type === 'divider' || type === 'spacer') return 'Layout';
            if (type === 'posts_list' || type === 'events' || type === 'form' || type === 'donation_form' || type === 'donation_progress' || type === 'campaign_summary') return 'Dynamic';
            return 'Blocks';
        }

        function blockIconName(type) {
            var map = {
                section_header: 'h1',
                heading: 'h1',
                text: 'txt',
                button: 'link',
                html: 'code',
                transcript: 'phrase-sentiment',
                image: 'image',
                hero: 'website',
                columns: 'distribute-horizontal-center',
                feature_grid: 'grid',
                card_grid: 'cards',
                cta: 'arrow-right',
                divider: 'divider',
                spacer: 'distribute-vertical-center',
                posts_list: 'list',
                events: 'event-schedule',
                form: 'document',
                donation_form: 'hand-donation',
                donation_progress: 'progress-bar',
                campaign_summary: 'report'
            };
            return map[s(type || '')] || 'box';
        }

        function filteredBlockLibraryTypes() {
            var query = s(state.blockLibrarySearch || '').trim().toLowerCase();
            return blockLibraryTypes().filter(function (type) {
                if (!query) return true;
                return (
                    sectionTypeLabel(type).toLowerCase().indexOf(query) !== -1 ||
                    sectionDescription(type).toLowerCase().indexOf(query) !== -1 ||
                    blockCategory(type).toLowerCase().indexOf(query) !== -1
                );
            });
        }

        function insertSection(type, index) {
            if (!state.canEdit && !(state.id < 1 && state.canCreate)) return;
            if (!Array.isArray(state.sections)) state.sections = [];
            var libraryTypes = blockLibraryTypes();
            var blockType = libraryTypes.indexOf(s(type || 'text')) === -1 ? 'text' : s(type || 'text');
            var insertAt = typeof index === 'number'
                ? index
                : (state.activeSection >= 0 ? state.activeSection + 1 : state.sections.length);
            insertAt = Math.max(0, Math.min(state.sections.length, insertAt));
            state.sections.splice(insertAt, 0, defaultSectionFromLibraryType(blockType));
            state.activeSection = insertAt;
            renderSectionList();
            syncStepUi();
            setDirtyAutosave();
        }

        function plainTextFromHtml(html) {
            var wrap = document.createElement('div');
            wrap.innerHTML = s(html || '');
            return s(wrap.textContent || wrap.innerText || '').replace(/\s+/g, ' ').trim();
        }

        function emptyText(value, fallback) {
            var raw = s(value || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            return raw || fallback;
        }

        function editableAttr(index, field, extra) {
            if (!state.canEdit && !(state.id < 1 && state.canCreate)) return '';
            return ' contenteditable="true" spellcheck="true" data-v2-inline="' + esc(field) + '" data-inline-index="' + esc(String(index)) + '"' + (extra || '');
        }

        function imageDimensionAttrs(content) {
            var width = normalizeImageDimension(content && content.width || '');
            var height = normalizeImageDimension(content && content.height || '');
            var attrs = '';
            var style = [];
            if (width) {
                attrs += ' width="' + esc(width) + '"';
                style.push('width:' + width + 'px');
            }
            if (height) {
                attrs += ' height="' + esc(height) + '"';
                style.push('height:' + height + 'px');
            }
            if (style.length) attrs += ' style="' + esc(style.join(';')) + '"';
            return attrs;
        }

        function renderCanvasToolbar(index) {
            if (!state.canEdit && !(state.id < 1 && state.canCreate)) return '';
            return '<div class="metis-builder-block-tools" aria-label="Block controls">' +
                '<button type="button" class="metis-builder-icon-btn" data-v2-list-act="up" title="Move up" aria-label="Move up">&uarr;</button>' +
                '<button type="button" class="metis-builder-icon-btn" data-v2-list-act="down" title="Move down" aria-label="Move down">&darr;</button>' +
                '<button type="button" class="metis-builder-tool-btn" data-v2-list-act="duplicate">Duplicate</button>' +
                '<button type="button" class="metis-builder-tool-btn" data-v2-list-act="delete">Delete</button>' +
                (state.canEdit ? '<button type="button" class="metis-builder-tool-btn" data-save-reusable="1">Save reusable</button>' : '') +
            '</div>';
        }

        function richToolbarTargetForBlock(sec, index) {
            var type = s(sec && sec.type || 'text');
            if (type === 'text') return 'metis-v2-canvas-rich-' + String(index);
            if (type === 'columns') {
                var active = s(state.activeRichTargetId || '');
                if (active.indexOf('metis-v2-canvas-col-' + String(index) + '-') === 0) return active;
                return 'metis-v2-canvas-col-' + String(index) + '-0';
            }
            return '';
        }

        function renderContextToolbar(sec, index) {
            if (!state.canEdit && !(state.id < 1 && state.canCreate)) return '';
            var targetId = richToolbarTargetForBlock(sec, index);
            if (!targetId) return '';
            return '<div class="metis-builder-context-toolbar">' + richToolbarV2(targetId) + '</div>';
        }

        function retargetContextToolbar(targetId) {
            var target = targetId ? document.getElementById(targetId) : null;
            var block = target && target.closest ? target.closest('.metis-builder-block') : null;
            var toolbar = block ? block.querySelector('.metis-builder-context-toolbar') : root.querySelector('.metis-builder-context-toolbar');
            if (!toolbar || !targetId) return;
            toolbar.querySelectorAll('[data-rich-target]').forEach(function (node) {
                node.setAttribute('data-rich-target', targetId);
            });
        }

        function renderVisualBlock(sec, index) {
            var type = s(sec && sec.type || 'text');
            var content = sec && sec.content && typeof sec.content === 'object' ? sec.content : {};
            var selected = index === state.activeSection;
            var body = '';
            if (type === 'heading') {
                var headingVariant = s(content.variant || '') === 'section_header' ? 'section_header' : 'default';
                var level = headingVariant === 'section_header' ? 'h1' : (['h1', 'h2', 'h3', 'h4'].indexOf(s(content.level || 'h2')) !== -1 ? s(content.level || 'h2') : 'h2');
                var headingAlign = ['left', 'center', 'right'].indexOf(s(content.align || 'left')) === -1 ? 'left' : s(content.align || 'left');
                var headingVertical = ['top', 'middle', 'bottom'].indexOf(s(content.vertical_align || 'top')) === -1 ? 'top' : s(content.vertical_align || 'top');
                body = '<div class="metis-builder-heading-wrap is-valign-' + esc(headingVertical) + (headingVariant === 'section_header' ? ' is-section-header' : '') + '"><' + level + ' class="metis-builder-heading is-align-' + esc(headingAlign) + '"' + editableAttr(index, 'heading_text') + '>' + esc(s(content.text || 'Heading')) + '</' + level + '></div>';
            } else if (type === 'text') {
                body = '<div class="metis-builder-richtext metis-se-rich-editor" data-v2-rich="canvas_text" id="metis-v2-canvas-rich-' + esc(String(index)) + '"' + editableAttr(index, 'text_body') + '>' + s(content.body || '<p>Start typing...</p>') + '</div>';
            } else if (type === 'image') {
                body = '<figure class="metis-builder-image">' +
                    (content.src ? '<img src="' + esc(s(content.src || '')) + '" alt="' + esc(s(content.alt || '')) + '"' + imageDimensionAttrs(content) + '>' : '<div class="metis-builder-media-empty">Choose an image in settings.</div>') +
                    '<figcaption' + editableAttr(index, 'image_caption') + '>' + esc(s(content.caption || '')) + '</figcaption>' +
                '</figure>';
            } else if (type === 'button') {
                body = '<div class="metis-builder-button-row is-' + esc(s(content.align || 'left')) + '">' +
                    '<a class="metis-builder-button" href="' + esc(s(content.url || '#')) + '" data-builder-link="1"' + editableAttr(index, 'button_label') + '>' + esc(s(content.label || 'Learn more')) + '</a>' +
                '</div>';
            } else if (type === 'hero') {
                body = '<div class="metis-builder-hero">' +
                    '<div class="metis-builder-hero-copy">' +
                        '<h1' + editableAttr(index, 'hero_title') + '>' + esc(s(content.title || 'Hero Title')) + '</h1>' +
                        '<p' + editableAttr(index, 'hero_subtitle') + '>' + esc(s(content.subtitle || 'Add a concise supporting message.')) + '</p>' +
                        '<a class="metis-builder-button" href="' + esc(s(content.cta_url || '#')) + '" data-builder-link="1"' + editableAttr(index, 'hero_cta_label') + '>' + esc(s(content.cta_label || 'Learn More')) + '</a>' +
                    '</div>' +
                    '<div class="metis-builder-hero-media">' + (content.image_src ? '<img src="' + esc(s(content.image_src || '')) + '" alt="">' : '<span>Hero media</span>') + '</div>' +
                '</div>';
            } else if (type === 'columns') {
                var cols = Array.isArray(content.columns) ? content.columns : [];
                body = '<div class="metis-builder-columns" style="--metis-builder-cols:' + esc(String(Math.max(1, cols.length || 2))) + ';">' + cols.map(function (col, colIndex) {
                    return '<div class="metis-builder-column metis-se-rich-editor" id="metis-v2-canvas-col-' + esc(String(index)) + '-' + esc(String(colIndex)) + '" contenteditable="' + ((state.canEdit || (state.id < 1 && state.canCreate)) ? 'true' : 'false') + '" spellcheck="true" data-v2-inline-col="' + esc(String(colIndex)) + '" data-inline-index="' + esc(String(index)) + '">' + s(col.body || '<p>Column text</p>') + '</div>';
                }).join('') + '</div>';
            } else if (type === 'feature_grid' || type === 'card_grid') {
                var items = Array.isArray(content.items) ? content.items : [];
                var columns = Math.max(2, Math.min(4, parseInt(s(content.columns || '3'), 10) || 3));
                body = '<div class="metis-builder-card-grid" style="--metis-builder-cols:' + esc(String(columns)) + ';">' + items.map(function (item, itemIndex) {
                    return '<article class="metis-builder-card-item">' +
                        (item.icon ? '<div class="metis-builder-card-icon">' + esc(s(item.icon || '')) + '</div>' : '') +
                        '<h3 contenteditable="' + ((state.canEdit || (state.id < 1 && state.canCreate)) ? 'true' : 'false') + '" data-v2-inline-item="' + esc(String(itemIndex)) + '" data-inline-item-field="title" data-inline-index="' + esc(String(index)) + '">' + esc(s(item.title || 'Card')) + '</h3>' +
                        '<p contenteditable="' + ((state.canEdit || (state.id < 1 && state.canCreate)) ? 'true' : 'false') + '" data-v2-inline-item="' + esc(String(itemIndex)) + '" data-inline-item-field="text" data-inline-index="' + esc(String(index)) + '">' + esc(s(item.text || 'Add supporting text.')) + '</p>' +
                    '</article>';
                }).join('') + '</div>';
            } else if (type === 'posts_list') {
                body = '<div class="metis-builder-posts-list"><div><strong>Posts list</strong><span>' + esc(String(parseInt(s(content.limit || '5'), 10) || 5)) + ' items from ' + esc(s(content.source || 'this_page').replace('_', ' ')) + '</span></div></div>';
            } else if (type === 'html') {
                body = '<pre class="metis-builder-code-preview">' + esc(emptyText(content.html || '', '<div></div>')) + '</pre>';
            } else if (type === 'cta') {
                var ctaItems = Array.isArray(content.items) ? content.items : [];
                body = '<div class="metis-builder-cta">' + ctaItems.map(function (item, ctaIndex) {
                    var btn = item.button && typeof item.button === 'object' ? item.button : {};
                    return '<div><h3 contenteditable="' + ((state.canEdit || (state.id < 1 && state.canCreate)) ? 'true' : 'false') + '" data-v2-inline-item="' + esc(String(ctaIndex)) + '" data-inline-item-field="title" data-inline-index="' + esc(String(index)) + '">' + esc(s(item.title || 'Call to Action')) + '</h3><p>' + esc(s(item.text || '')) + '</p>' + (btn.label ? '<a class="metis-builder-button" data-builder-link="1" href="' + esc(s(btn.url || '#')) + '">' + esc(s(btn.label || 'Learn More')) + '</a>' : '') + '</div>';
                }).join('') + '</div>';
            } else if (type === 'events') {
                body = '<div class="metis-builder-posts-list"><div><strong>Events</strong><span>' + esc(String(parseInt(s(content.limit || '5'), 10) || 5)) + ' upcoming items</span></div></div>';
            } else if (type === 'form') {
                body = '<div class="metis-builder-dynamic-card"><strong>Form</strong><span>' + esc(optionLabel(state.options.forms, content.form_id, 'Select a form in settings.')) + '</span><small>Submit button: ' + esc(s(content.submit_label || 'Submit')) + '</small></div>';
            } else if (type === 'donation_form') {
                body = '<div class="metis-builder-dynamic-card"><strong>Donation form</strong><span>' + esc(optionLabel(state.options.donationCampaigns, content.campaign_id, 'Select a campaign in settings.')) + '</span><small>Amounts: ' + esc(presetAmountsInputValue(content.preset_amounts || [25, 50, 100])) + '</small></div>';
            } else if (type === 'donation_progress') {
                var pct = parseFloat(s(content.percent || ''));
                if (!isFinite(pct)) pct = 40;
                pct = Math.max(0, Math.min(100, pct));
                body = '<div class="metis-builder-dynamic-card"><strong>Donation progress</strong><span>' + esc(optionLabel(state.options.donationCampaigns, content.campaign_id, 'Manual progress')) + '</span><div class="metis-builder-progress"><span style="width:' + esc(String(pct)) + '%"></span></div><small>' + esc(String(Math.round(pct))) + '% preview</small></div>';
            } else if (type === 'campaign_summary') {
                body = '<div class="metis-builder-campaign-summary">' +
                    (content.image ? '<div class="metis-builder-campaign-media"><img src="' + esc(s(content.image || '')) + '" alt=""></div>' : '') +
                    '<div><strong' + editableAttr(index, 'campaign_title') + '>' + esc(s(content.title || optionLabel(state.options.donationCampaigns, content.campaign_id, 'Campaign summary'))) + '</strong><p>' + esc(plainTextFromHtml(s(content.content || '')).slice(0, 180) || 'Campaign content appears here.') + '</p></div>' +
                '</div>';
            } else if (type === 'divider') {
                body = '<div class="metis-builder-divider"><hr class="is-' + esc(s(content.style || 'solid')) + '"><span' + editableAttr(index, 'divider_label') + '>' + esc(s(content.label || '')) + '</span></div>';
            } else if (type === 'spacer') {
                body = '<div class="metis-builder-spacer is-' + esc(s(content.height || 'medium')) + '"></div>';
            } else if (type === 'transcript') {
                body = '<div class="metis-builder-transcript"><strong>Transcript</strong><p>' + esc(plainTextFromHtml(s(content.source || '')).slice(0, 220) || 'Paste transcript text in settings.') + '</p></div>';
            } else {
                body = '<div class="metis-builder-richtext">' + s(content.body || '<p>Content block</p>') + '</div>';
            }
            return '<section class="metis-builder-block' + (selected ? ' is-selected' : '') + blockBackgroundClass(sec) + blockVariantClass(sec) + '" data-builder-block-index="' + esc(String(index)) + '" data-index="' + esc(String(index)) + '" tabindex="0" aria-label="' + esc(sectionTypeLabel(type)) + ' block">' +
                '<div class="metis-builder-block-chrome"><span class="metis-builder-block-label">' + esc(sectionTypeLabel(type)) + '</span>' + renderCanvasToolbar(index) + '</div>' +
                renderContextToolbar(sec, index) +
                '<div class="metis-builder-block-body">' + body + '</div>' +
            '</section>';
        }

        function renderBuilderCanvas() {
            var canvas = document.getElementById('metis-v2-canvas');
            if (!canvas) return;
            var sections = Array.isArray(state.sections) ? state.sections : [];
            function dropZone(index) {
                var expanded = state.blockPickerIndex === index ? 'true' : 'false';
                return '<div class="metis-builder-insert-wrap" data-builder-drop-index="' + esc(String(index)) + '">' +
                    '<button type="button" class="metis-builder-drop-zone" data-builder-insert-toggle="' + esc(String(index)) + '" aria-expanded="' + expanded + '" aria-label="Add block"><span>+</span></button>' +
                '</div>';
            }
            if (!sections.length) {
                canvas.innerHTML = dropZone(0) + '<div class="metis-builder-empty"><strong>No blocks yet.</strong><span>Use the plus button to add the first block.</span></div>';
                return;
            }
            canvas.innerHTML = dropZone(0) + sections.map(function (sec, index) {
                return renderVisualBlock(sec, index) + dropZone(index + 1);
            }).join('');
            bindIconFallbacks(canvas);
        }

        function renderBuilderOutline() {
            var host = document.getElementById('metis-v2-outline');
            if (!host) return;
            var rows = '<button type="button" class="metis-builder-outline-item' + (state.activeSection < 0 ? ' is-selected' : '') + '" data-panel-target="page"><span>Page</span><small>Settings</small></button>';
            (state.sections || []).forEach(function (sec, index) {
                rows += '<button type="button" class="metis-builder-outline-item' + (index === state.activeSection ? ' is-selected' : '') + '" data-outline-index="' + esc(String(index)) + '"><span>' + esc(sectionSummary(sec)) + '</span><small>' + esc(sectionTypeLabel(sec.type)) + '</small></button>';
            });
            host.innerHTML = rows;
        }

        function renderBlockLibrary() {
            var host = document.getElementById('metis-v2-block-library');
            if (!host) return;
            if (!state.canEdit && !(state.id < 1 && state.canCreate)) {
                host.innerHTML = '<div class="metis-se-meta-value">View only.</div>';
                return;
            }
            var grouped = {};
            filteredBlockLibraryTypes().forEach(function (type) {
                var group = blockCategory(type);
                if (!grouped[group]) grouped[group] = [];
                grouped[group].push(type);
            });
            var html = '<input id="metis-v2-block-search" class="metis-se-input metis-builder-block-search" type="search" aria-label="Search blocks" placeholder="Search blocks" value="' + esc(state.blockLibrarySearch || '') + '">';
            Object.keys(grouped).forEach(function (group) {
                html += '<div class="metis-builder-library-group"><div class="metis-builder-library-group-title">' + esc(group) + '</div><div class="metis-builder-library-grid">';
                grouped[group].forEach(function (type) {
                    html += '<button type="button" class="metis-content-library-item metis-builder-block-tile" draggable="true" data-add-block-type="' + esc(type) + '">' +
                        '<span class="metis-builder-block-tile-icon" aria-hidden="true"><img src="' + esc(iconUrl(blockIconName(type))) + '" data-icon-fallback="' + esc(iconFallbackUrl(blockIconName(type))) + '" alt=""></span>' +
                        '<strong>' + esc(sectionTypeLabel(type)) + '</strong>' +
                        '<small>' + esc(sectionDescription(type)) + '</small>' +
                    '</button>';
                });
                html += '</div></div>';
            });
            if (Object.keys(grouped).length === 0) {
                html += '<div class="metis-se-meta-value">No matching blocks.</div>';
            }
            host.innerHTML = html;
            bindIconFallbacks(host);
        }

        function insertTargetLabel(index) {
            var total = Array.isArray(state.sections) ? state.sections.length : 0;
            var insertAt = Math.max(0, Math.min(total, parseInt(s(index || '0'), 10) || 0));
            if (total === 0) return 'Adding first block';
            if (insertAt === 0) return 'Adding before first block';
            if (insertAt >= total) return 'Adding after last block';
            return 'Adding between blocks ' + String(insertAt) + ' and ' + String(insertAt + 1);
        }

        function openBlockInserter(index) {
            if (!state.canEdit && !(state.id < 1 && state.canCreate)) return;
            state.blockPickerIndex = Math.max(0, Math.min(state.sections.length, parseInt(s(index || '0'), 10) || 0));
            renderBuilderCanvas();
            renderBlockLibrary();
            renderReusableBlocks();
            var overlay = document.getElementById('metis-builder-block-overlay');
            var label = document.getElementById('metis-builder-block-insert-target');
            var search = document.getElementById('metis-v2-block-search');
            if (label) label.textContent = insertTargetLabel(state.blockPickerIndex);
            if (overlay) {
                overlay.hidden = false;
                overlay.setAttribute('aria-hidden', 'false');
            }
            if (search) {
                window.setTimeout(function () { search.focus(); }, 0);
            }
        }

        function closeBlockInserter() {
            state.blockPickerIndex = null;
            var overlay = document.getElementById('metis-builder-block-overlay');
            if (overlay) {
                overlay.hidden = true;
                overlay.setAttribute('aria-hidden', 'true');
            }
            renderBuilderCanvas();
        }

        function clearCanvasDropTargets() {
            var canvas = document.getElementById('metis-v2-canvas');
            if (!canvas) return;
            canvas.classList.remove('is-dragging');
            canvas.querySelectorAll('.metis-builder-drop-zone.is-active').forEach(function (node) {
                node.classList.remove('is-active');
            });
        }

        function dropIndexFromEvent(event) {
            var dropZone = event.target && event.target.closest ? event.target.closest('[data-builder-drop-index]') : null;
            if (!dropZone) {
                var block = event.target && event.target.closest ? event.target.closest('[data-builder-block-index]') : null;
                if (block) {
                    var blockIndex = parseInt(s(block.getAttribute('data-builder-block-index') || '-1'), 10);
                    return Math.max(0, blockIndex + 1);
                }
                return state.sections.length;
            }
            return Math.max(0, Math.min(state.sections.length, parseInt(s(dropZone.getAttribute('data-builder-drop-index') || '0'), 10) || 0));
        }

        function renderBuilderSettings() {
            var page = document.getElementById('metis-builder-settings-page');
            var block = document.getElementById('metis-builder-settings-block');
            if (!page || !block) return;
            var blockSelected = state.activeSection >= 0 && state.activeSection < state.sections.length;
            page.hidden = blockSelected;
            block.hidden = !blockSelected;
            if (blockSelected) {
                renderStep2Editor();
            } else {
                var settings = document.getElementById('metis-v2-section-settings');
                var content = document.getElementById('metis-v2-section-content');
                if (settings) settings.innerHTML = '';
                if (content) content.innerHTML = '';
            }
        }

        function syncBuilderChrome() {
            var statusEl = document.getElementById('metis-v2-status');
            var titleEl = document.getElementById('metis-v2-title');
            var topStatus = document.getElementById('metis-builder-top-status');
            var publishBtn = document.getElementById('metis-v2-publish');
            if (topStatus) {
                var status = s(statusEl && statusEl.value || state.entity && state.entity.status || 'draft') || 'draft';
                topStatus.textContent = status.charAt(0).toUpperCase() + status.slice(1);
            }
            if (publishBtn) {
                var selectedStatus = s(statusEl && statusEl.value || '');
                if (state.canPublish && selectedStatus === 'scheduled') {
                    publishBtn.textContent = 'Schedule';
                } else {
                    publishBtn.textContent = state.canPublish ? (hasPublishedVersion() ? 'Update' : 'Publish') : 'Save Draft';
                }
            }
            updateTopTitle(s(titleEl && titleEl.value || state.entity && state.entity.title || ''));
        }

        function renderSectionList() {
            renderBuilderCanvas();
            renderBuilderOutline();
            renderBlockLibrary();
            renderBuilderSettings();
            renderReusableBlocks();
            renderRevisionList();
            syncBuilderChrome();
        }

        function sectionCardItemPrefix(key, i) {
            return '<div class="metis-se-card"><div class="metis-se-field-grid">' +
                '<div class="metis-se-field-row"><label>' + key + ' ' + (i + 1) + '</label>';
        }

        function renderSectionContentEditor() {
            var host = document.getElementById('metis-v2-section-content');
            if (!host) return;
            var sec = activeSection();
            var html = '';
            if (sec.type === 'heading') {
                var headingVariant = s(sec.content.variant || '') === 'section_header' ? 'section_header' : 'default';
                html += '<div class="metis-se-field-row"><label>Heading Style</label><select id="metis-v2-heading-variant" class="metis-se-select"><option value="default"' + (headingVariant === 'default' ? ' selected' : '') + '>Inline heading</option><option value="section_header"' + (headingVariant === 'section_header' ? ' selected' : '') + '>Full-width section header</option></select></div>';
                html += '<div class="metis-se-field-row"><label>Heading Level</label><select id="metis-v2-heading-level" class="metis-se-select"><option value="h1"' + (sec.content.level === 'h1' ? ' selected' : '') + '>H1</option><option value="h2"' + (sec.content.level === 'h2' ? ' selected' : '') + '>H2</option><option value="h3"' + (sec.content.level === 'h3' ? ' selected' : '') + '>H3</option><option value="h4"' + (sec.content.level === 'h4' ? ' selected' : '') + '>H4</option></select></div>';
                html += '<div class="metis-se-field-row"><label>Horizontal Alignment</label><select id="metis-v2-heading-align" class="metis-se-select"><option value="left"' + (sec.content.align === 'left' ? ' selected' : '') + '>Left</option><option value="center"' + (sec.content.align === 'center' ? ' selected' : '') + '>Center</option><option value="right"' + (sec.content.align === 'right' ? ' selected' : '') + '>Right</option></select></div>';
                html += '<div class="metis-se-field-row"><label>Vertical Alignment</label><select id="metis-v2-heading-vertical" class="metis-se-select"><option value="top"' + (sec.content.vertical_align === 'top' ? ' selected' : '') + '>Top</option><option value="middle"' + (sec.content.vertical_align === 'middle' ? ' selected' : '') + '>Middle</option><option value="bottom"' + (sec.content.vertical_align === 'bottom' ? ' selected' : '') + '>Bottom</option></select></div>';
            } else if (sec.type === 'image') {
                html += '<div class="metis-se-field-row"><label>Image</label><div class="metis-featured-image-actions"><button type="button" class="metis-se-nav-btn" data-open-block-media="src">Choose from Media</button></div></div>';
                html += '<div class="metis-se-field-row"><label>Image URL</label><input id="metis-v2-image-src" class="metis-se-input" value="' + esc(s(sec.content.src || '')) + '" placeholder="https://"></div>';
                html += '<div class="metis-se-field-row"><label>Alt Text</label><input id="metis-v2-image-alt" class="metis-se-input" value="' + esc(s(sec.content.alt || '')) + '"></div>';
                html += '<div class="metis-se-field-row"><label>Width</label><input id="metis-v2-image-width" class="metis-se-input" inputmode="numeric" pattern="[0-9]*" value="' + esc(s(sec.content.width || '')) + '" placeholder="Auto"></div>';
                html += '<div class="metis-se-field-row"><label>Height</label><input id="metis-v2-image-height" class="metis-se-input" inputmode="numeric" pattern="[0-9]*" value="' + esc(s(sec.content.height || '')) + '" placeholder="Auto"></div>';
            } else if (sec.type === 'button') {
                html += '<div class="metis-se-field-row"><label>URL</label><input id="metis-v2-button-url" class="metis-se-input" value="' + esc(s(sec.content.url || '#')) + '"></div>';
                html += '<div class="metis-se-field-row"><label>Alignment</label><select id="metis-v2-button-align" class="metis-se-select"><option value="left"' + (sec.content.align === 'left' ? ' selected' : '') + '>Left</option><option value="center"' + (sec.content.align === 'center' ? ' selected' : '') + '>Center</option><option value="right"' + (sec.content.align === 'right' ? ' selected' : '') + '>Right</option></select></div>';
            } else if (sec.type === 'hero') {
                html += '<div class="metis-se-field-row"><label>CTA URL</label><input id="metis-v2-block-hero-cta-url" class="metis-se-input" value="' + esc(s(sec.content.cta_url || '#')) + '"></div>';
                html += '<div class="metis-se-field-row"><label>Hero Image</label><div class="metis-featured-image-actions"><button type="button" class="metis-se-nav-btn" data-open-block-media="image_src">Choose from Media</button></div></div>';
                html += '<div class="metis-se-field-row"><label>Image URL</label><input id="metis-v2-block-hero-image" class="metis-se-input" value="' + esc(s(sec.content.image_src || '')) + '"></div>';
            } else if (sec.type === 'html') {
                html += '<div class="metis-se-field-row"><label>HTML</label><textarea id="metis-v2-html-code" class="metis-se-textarea metis-content-code-field" rows="14" spellcheck="false">' + esc(s(sec.content.html || '')) + '</textarea><div class="metis-se-field-help">Saved HTML is sanitized server-side before rendering.</div></div>';
            } else if (sec.type === 'transcript') {
                var transcriptRows = Array.isArray(sec.content.rows) ? sec.content.rows.length : 0;
                html += '<div class="metis-se-field-row"><label>Paste Transcript</label><textarea id="metis-v2-transcript-source" class="metis-se-textarea" rows="18" placeholder="MEG: Welcome everyone.\nELAINE: Thank you for having me.\n(Music fades)\nMEG: Let&#39;s get started.">' + esc(s(sec.content.source || '')) + '</textarea><div class="metis-se-field-help">Use one speaker per line in the format <code>SPEAKER: message</code>. Standalone lines in parentheses render as centered system messages.</div><div class="metis-se-meta-value">Parsed entries: ' + esc(String(transcriptRows)) + '</div></div>';
            }
            if (sec.type === 'columns') {
                var columns = Array.isArray(sec.content.columns) ? sec.content.columns : [];
                var columnCount = columns.length || 2;
                html += '<div class="metis-se-field-row"><label>Columns</label><select id="metis-v2-columns-count" class="metis-se-select">' +
                    '<option value="1"' + (columnCount === 1 ? ' selected' : '') + '>1</option>' +
                    '<option value="2"' + (columnCount === 2 ? ' selected' : '') + '>2</option>' +
                    '<option value="3"' + (columnCount === 3 ? ' selected' : '') + '>3</option>' +
                    '<option value="4"' + (columnCount === 4 ? ' selected' : '') + '>4</option>' +
                '</select></div>';
            } else if (sec.type === 'feature_grid' || sec.type === 'card_grid') {
                var items = Array.isArray(sec.content.items) ? sec.content.items : [];
                var fgCols = parseInt(s(sec.content.columns || '3'), 10) || 3;
                html += '<div class="metis-se-field-row"><label>Columns</label><select id="metis-v2-feature-cols" class="metis-se-select">' +
                    '<option value="2"' + (fgCols === 2 ? ' selected' : '') + '>2</option>' +
                    '<option value="3"' + (fgCols === 3 ? ' selected' : '') + '>3</option>' +
                    '<option value="4"' + (fgCols === 4 ? ' selected' : '') + '>4</option>' +
                '</select></div>';
                html += '<div class="metis-se-field-row"><button type="button" class="metis-se-nav-btn" data-v2-add-item="feature_grid">Add Item</button></div>';
                items.forEach(function (item, i) {
                    var cta = item.cta && typeof item.cta === 'object' ? item.cta : {};
                    html += '<div class="metis-se-card"><div class="metis-se-field-grid">' +
                        '<div class="metis-se-field-row"><label>Icon</label><input class="metis-se-input" data-v2-item="feature_grid" data-item-idx="' + i + '" data-item-field="icon" value="' + esc(s(item.icon || '')) + '"></div>' +
                        '<div class="metis-se-field-row"><label>Title</label><input class="metis-se-input" data-v2-item="feature_grid" data-item-idx="' + i + '" data-item-field="title" value="' + esc(s(item.title || '')) + '"></div>' +
                        '<div class="metis-se-field-row"><label>Text</label><textarea class="metis-se-input" data-v2-item="feature_grid" data-item-idx="' + i + '" data-item-field="text">' + esc(s(item.text || '')) + '</textarea></div>' +
                        '<div class="metis-se-field-row"><label>CTA Label</label><input class="metis-se-input" data-v2-item="feature_grid" data-item-idx="' + i + '" data-item-field="cta_label" value="' + esc(s(cta.label || '')) + '"></div>' +
                        '<div class="metis-se-field-row"><label>CTA Link</label><input class="metis-se-input" data-v2-item="feature_grid" data-item-idx="' + i + '" data-item-field="cta_url" value="' + esc(s(cta.url || '#')) + '"></div>' +
                        '<div class="metis-se-field-row"><button type="button" class="metis-se-nav-btn" data-v2-remove-item="feature_grid" data-item-idx="' + i + '">Remove Item</button></div>' +
                    '</div></div>';
                });
            } else if (sec.type === 'cta') {
                var ctaItems = Array.isArray(sec.content.items) ? sec.content.items : [];
                html += '<div class="metis-se-field-row"><label>Layout</label><select id="metis-v2-cta-layout" class="metis-se-select"><option value="single"' + (s(sec.content.layout || 'single') === 'single' ? ' selected' : '') + '>Single</option><option value="split"' + (s(sec.content.layout || '') === 'split' ? ' selected' : '') + '>Split</option></select></div>';
                ctaItems.forEach(function (item, i) {
                    var btn = item.button && typeof item.button === 'object' ? item.button : {};
                    html += '<div class="metis-se-card"><div class="metis-se-field-grid">' +
                        '<div class="metis-se-field-row"><label>Title</label><input class="metis-se-input" data-v2-item="cta" data-item-idx="' + i + '" data-item-field="title" value="' + esc(s(item.title || '')) + '"></div>' +
                        '<div class="metis-se-field-row"><label>Description</label><textarea class="metis-se-input" data-v2-item="cta" data-item-idx="' + i + '" data-item-field="text">' + esc(s(item.text || '')) + '</textarea></div>' +
                        '<div class="metis-se-field-row"><label>Button Label</label><input class="metis-se-input" data-v2-item="cta" data-item-idx="' + i + '" data-item-field="button_label" value="' + esc(s(btn.label || '')) + '"></div>' +
                        '<div class="metis-se-field-row"><label>Button Link</label><input class="metis-se-input" data-v2-item="cta" data-item-idx="' + i + '" data-item-field="button_url" value="' + esc(s(btn.url || '#')) + '"></div>' +
                    '</div></div>';
                });
            } else if (sec.type === 'events') {
                html += '<div class="metis-se-field-row"><label>Source</label><select id="metis-v2-events-source" class="metis-se-select"><option value="calendar"' + (s(sec.content.source || 'calendar') === 'calendar' ? ' selected' : '') + '>Public Calendar</option><option value="manual"' + (s(sec.content.source || '') === 'manual' ? ' selected' : '') + '>Manual</option></select></div>';
                html += '<div class="metis-se-field-row"><label>Item Limit</label><input id="metis-v2-events-limit" class="metis-se-input" type="number" min="1" max="50" value="' + esc(String(parseInt(s(sec.content.limit || '5'), 10) || 5)) + '"></div>';
            } else if (sec.type === 'form') {
                html += '<div class="metis-se-field-row"><label>Form</label><select id="metis-v2-form-id" class="metis-se-select">' + optionList(state.options.forms, sec.content.form_id, 'Select form') + '</select></div>';
                html += '<div class="metis-se-field-row"><label>Submit Label</label><input id="metis-v2-form-submit-label" class="metis-se-input" value="' + esc(s(sec.content.submit_label || 'Submit')) + '"></div>';
                if (!state.options.forms.length) html += '<div class="metis-se-meta-value">No forms are available yet.</div>';
            } else if (sec.type === 'donation_form') {
                html += '<div class="metis-se-field-row"><label>Campaign</label><select id="metis-v2-donation-campaign" class="metis-se-select">' + optionList(state.options.donationCampaigns, sec.content.campaign_id, 'Select campaign') + '</select></div>';
                html += '<div class="metis-se-field-row"><label>Preset Amounts</label><input id="metis-v2-donation-amounts" class="metis-se-input" value="' + esc(presetAmountsInputValue(sec.content.preset_amounts || [25, 50, 100])) + '" placeholder="25, 50, 100"></div>';
                html += '<div class="metis-se-field-row"><label>Mode</label><select id="metis-v2-donation-mode" class="metis-se-select"><option value="both"' + (s(sec.content.mode || 'both') === 'both' ? ' selected' : '') + '>One-time and monthly</option><option value="one_time"' + (s(sec.content.mode || '') === 'one_time' ? ' selected' : '') + '>One-time only</option><option value="monthly"' + (s(sec.content.mode || '') === 'monthly' ? ' selected' : '') + '>Monthly only</option></select></div>';
                html += '<div class="metis-se-field-row"><label class="metis-se-check-label" for="metis-v2-donation-allow-custom"><input id="metis-v2-donation-allow-custom" type="checkbox"' + (sec.content.allow_custom_amount ? ' checked' : '') + '> Allow custom amount</label></div>';
                html += '<div class="metis-se-field-row"><label class="metis-se-check-label" for="metis-v2-donation-show-name"><input id="metis-v2-donation-show-name" type="checkbox"' + (sec.content.show_name ? ' checked' : '') + '> Ask for name</label></div>';
                html += '<div class="metis-se-field-row"><label class="metis-se-check-label" for="metis-v2-donation-show-email"><input id="metis-v2-donation-show-email" type="checkbox"' + (sec.content.show_email ? ' checked' : '') + '> Ask for email</label></div>';
                html += '<div class="metis-se-field-row"><label class="metis-se-check-label" for="metis-v2-donation-show-phone"><input id="metis-v2-donation-show-phone" type="checkbox"' + (sec.content.show_phone ? ' checked' : '') + '> Ask for phone</label></div>';
            } else if (sec.type === 'donation_progress') {
                html += '<div class="metis-se-field-row"><label>Campaign</label><select id="metis-v2-progress-campaign" class="metis-se-select">' + optionList(state.options.donationCampaigns, sec.content.campaign_id, 'Use manual values') + '</select></div>';
                html += '<div class="metis-se-field-row"><label>Goal Amount</label><input id="metis-v2-progress-goal" class="metis-se-input" inputmode="decimal" value="' + esc(s(sec.content.goal_amount || '')) + '" placeholder="From campaign"></div>';
                html += '<div class="metis-se-field-row"><label>Raised Amount</label><input id="metis-v2-progress-raised" class="metis-se-input" inputmode="decimal" value="' + esc(s(sec.content.raised_amount || '')) + '" placeholder="From campaign"></div>';
                html += '<div class="metis-se-field-row"><label>Percent</label><input id="metis-v2-progress-percent" class="metis-se-input" inputmode="decimal" value="' + esc(s(sec.content.percent || '')) + '" placeholder="Auto"></div>';
            } else if (sec.type === 'campaign_summary') {
                html += '<div class="metis-se-field-row"><label>Campaign</label><select id="metis-v2-campaign-summary-campaign" class="metis-se-select">' + optionList(state.options.donationCampaigns, sec.content.campaign_id, 'Use custom content') + '</select></div>';
                html += '<div class="metis-se-field-row"><label>Title</label><input id="metis-v2-campaign-summary-title" class="metis-se-input" value="' + esc(s(sec.content.title || '')) + '"></div>';
                html += '<div class="metis-se-field-row"><label>Content</label><textarea id="metis-v2-campaign-summary-content" class="metis-se-textarea" rows="6">' + esc(s(sec.content.content || '')) + '</textarea></div>';
                html += '<div class="metis-se-field-row"><label>Image</label><div class="metis-featured-image-actions"><button type="button" class="metis-se-nav-btn" data-open-block-media="image">Choose from Media</button></div></div>';
                html += '<div class="metis-se-field-row"><label>Image URL</label><input id="metis-v2-campaign-summary-image" class="metis-se-input" value="' + esc(s(sec.content.image || '')) + '" placeholder="https://"></div>';
            } else if (sec.type === 'divider') {
                html += '<div class="metis-se-field-row"><label>Label</label><input id="metis-v2-divider-label" class="metis-se-input" value="' + esc(s(sec.content.label || '')) + '"></div>';
                html += '<div class="metis-se-field-row"><label>Line Style</label><select id="metis-v2-divider-style" class="metis-se-select"><option value="solid"' + (s(sec.content.style || 'solid') === 'solid' ? ' selected' : '') + '>Solid</option><option value="dashed"' + (s(sec.content.style || '') === 'dashed' ? ' selected' : '') + '>Dashed</option><option value="dotted"' + (s(sec.content.style || '') === 'dotted' ? ' selected' : '') + '>Dotted</option></select></div>';
            } else if (sec.type === 'spacer') {
                var h = s(sec.content.height || 'medium');
                html += '<div class="metis-se-field-row"><label>Size</label><select id="metis-v2-spacer-height" class="metis-se-select"><option value="small"' + (h === 'small' ? ' selected' : '') + '>Small</option><option value="medium"' + (h === 'medium' ? ' selected' : '') + '>Medium</option><option value="large"' + (h === 'large' ? ' selected' : '') + '>Large</option></select></div>';
            } else if (sec.type === 'posts_list') {
                var listSource = s(sec.content.source || 'this_page');
                var specificPage = parseInt(s(sec.content.specific_page || '0'), 10) || 0;
                var selectedCategoryIds = normalizeIdList(sec.content.category_ids || []);
                var pageOptions = '<option value="0">Select page</option>';
                (state.options.parentPages || []).forEach(function (row) {
                    var value = parseInt(s(row && row.value || '0'), 10) || 0;
                    pageOptions += '<option value="' + esc(String(value)) + '"' + (value === specificPage ? ' selected' : '') + '>' + esc(s(row && row.label || 'Page ' + value)) + '</option>';
                });
                html += '<div class="metis-se-field-row"><label>Source</label><select id="metis-v2-posts-source" class="metis-se-select"><option value="this_page"' + (listSource === 'this_page' ? ' selected' : '') + '>This Page</option><option value="specific_page"' + (listSource === 'specific_page' ? ' selected' : '') + '>Specific Page</option></select></div>';
                html += '<div class="metis-se-field-row"><label>Specific Page</label><select id="metis-v2-posts-specific-page" class="metis-se-select"' + (listSource === 'specific_page' ? '' : ' disabled') + '>' + pageOptions + '</select></div>';
                html += '<div class="metis-se-field-row"><label>Categories</label>' + categoryChipField('metis-v2-posts-category-ids', selectedCategoryIds, 'No categories available.') + '</div>';
                html += '<div class="metis-se-field-row"><label>Item Limit</label><input id="metis-v2-posts-limit" class="metis-se-input" type="number" min="1" max="50" value="' + esc(String(parseInt(s(sec.content.limit || '5'), 10) || 5)) + '"></div>';
                html += '<div class="metis-se-field-row"><label>Sort</label><input class="metis-se-input" value="Latest" disabled></div>';
            }
            host.innerHTML = html;
            bindIconFallbacks(host);
        }

        function renderStep2Editor() {
            var sec = activeSection();
            var left = document.getElementById('metis-v2-section-settings');
            var label = document.getElementById('metis-v2-section-label');
            if (label) label.textContent = (state.activeSection + 1) + ' / ' + state.sections.length;
            if (!left) return;
            var settings = normalizeSectionSettings(sec.settings || {});
            left.innerHTML =
                '<div class="metis-builder-settings-card"><div class="metis-se-field-grid">' +
                    '<div class="metis-se-meta-inline"><div class="metis-se-meta-inline-label">Editing</div><div class="metis-se-meta-inline-value">' + esc(sectionTypeLabel(sec.type)) + '</div></div>' +
                    '<div class="metis-se-field-row"><label>Background</label><select id="metis-v2-section-background" class="metis-se-select">' + backgroundOptions(settings.background) + '</select></div>' +
                '</div></div>';
            renderSectionContentEditor();
        }

        function renderHeroEditor() {
            if (!isPageContext()) return;
            var host = document.getElementById('metis-v2-hero-editor');
            if (!host) return;
            var homepageEl = document.getElementById('metis-v2-homepage');
            var isHomepage = !!(homepageEl && homepageEl.checked);
            state.hero = normalizeHeroState(state.hero, isHomepage);
            var hero = state.hero;
            var disabledAttr = isHomepage ? '' : ' disabled';
            var note = isHomepage
                ? '<div class="metis-se-meta-value">Homepage hero content is rendered by the active template.</div>'
                : '<div class="metis-se-meta-value">Enable "Set as homepage" in Properties to edit hero content.</div>';
            host.innerHTML = '' +
                '<div class="metis-se-card"><div class="metis-se-field-grid">' +
                    '<div class="metis-se-field-row"><label class="metis-se-check-label" for="metis-v2-hero-enabled"><input id="metis-v2-hero-enabled" type="checkbox"' + (hero.enabled ? ' checked' : '') + disabledAttr + '> Enable homepage hero</label></div>' +
                    '<div class="metis-se-field-row"><label for="metis-v2-hero-style">Hero Style</label><select id="metis-v2-hero-style" class="metis-se-select"' + disabledAttr + '><option value="split"' + (hero.style === 'split' ? ' selected' : '') + '>Split</option><option value="centered"' + (hero.style === 'centered' ? ' selected' : '') + '>Centered</option><option value="overlay"' + (hero.style === 'overlay' ? ' selected' : '') + '>Overlay</option></select></div>' +
                    '<div class="metis-se-field-row"><label for="metis-v2-hero-headline">Headline</label><input id="metis-v2-hero-headline" class="metis-se-input" type="text" value="' + esc(hero.headline) + '"' + disabledAttr + '></div>' +
                    '<div class="metis-se-field-row"><label for="metis-v2-hero-subtext">Subtext</label><textarea id="metis-v2-hero-subtext" class="metis-se-input"' + disabledAttr + '>' + esc(hero.subtext) + '</textarea></div>' +
                    '<div class="metis-se-field-row"><label for="metis-v2-hero-cta-label">Primary CTA Label</label><input id="metis-v2-hero-cta-label" class="metis-se-input" type="text" value="' + esc(hero.primary_cta_label) + '"' + disabledAttr + '></div>' +
                    '<div class="metis-se-field-row"><label for="metis-v2-hero-cta-link">Primary CTA Link</label><input id="metis-v2-hero-cta-link" class="metis-se-input" type="text" value="' + esc(hero.primary_cta_link) + '"' + disabledAttr + '></div>' +
                    '<div class="metis-se-field-row"><label for="metis-v2-hero-media-url">Hero Media URL</label><input id="metis-v2-hero-media-url" class="metis-se-input" type="text" value="' + esc(hero.media_url) + '"' + disabledAttr + '></div>' +
                '</div></div>' +
                '<div class="metis-se-card">' + note + '</div>';
        }

        function setDirtyAutosave() {
            if (!state.canEdit && !(state.id < 1 && state.canCreate)) return;
            state.dirty = true;
            saveRecoveryDraft();
            setStatus('Unsaved changes', 'saving');
            if (state.autosaveTimer) window.clearTimeout(state.autosaveTimer);
            state.autosaveTimer = window.setTimeout(function () { saveEntity(true); }, 1400);
        }

        function recoveryKey() {
            if (state.recoveryKey) return state.recoveryKey;
            state.recoveryKey = 'metis.' + contentModuleSlug() + '.editor.recovery.' + (isPostContext() ? 'post' : 'page') + '.' + (state.key || state.id || 'new');
            return state.recoveryKey;
        }

        function saveRecoveryDraft() {
            if (!isManagedContentContext() || !window.localStorage) return;
            var titleEl = document.getElementById('metis-v2-title');
            var slugEl = document.getElementById('metis-v2-slug');
            try {
                window.localStorage.setItem(recoveryKey(), JSON.stringify({
                    saved_at: Date.now(),
                    title: s(titleEl && titleEl.value || ''),
                    slug: s(slugEl && slugEl.value || ''),
                    layout_json: layoutJsonFromState()
                }));
            } catch (_err) {}
        }

        function clearRecoveryDraft() {
            if (!isManagedContentContext() || !window.localStorage) return;
            try { window.localStorage.removeItem(recoveryKey()); } catch (_err) {}
            state.recoveryAvailable = false;
        }

        function checkRecoveryDraft() {
            if (!isManagedContentContext() || !window.localStorage) return;
            var raw = '';
            try { raw = window.localStorage.getItem(recoveryKey()) || ''; } catch (_err) { raw = ''; }
            var draft = raw ? j(raw, null) : null;
            if (!draft || !draft.layout_json) return;
            var savedAt = parseInt(s(draft.saved_at || '0'), 10) || 0;
            if (savedAt < Date.now() - 7 * 24 * 60 * 60 * 1000) {
                clearRecoveryDraft();
                return;
            }
            state.recoveryAvailable = true;
            renderRecoveryBanner(draft);
        }

        function renderRecoveryBanner(draft) {
            var host = document.getElementById('metis-v2-recovery');
            if (!host) return;
            var label = draft && draft.saved_at ? formatConfiguredDateTime(draft.saved_at, 'recently') : 'recently';
            host.innerHTML = '<div class="metis-content-recovery"><span>Recovered local draft from ' + esc(label) + '.</span><button type="button" class="metis-se-nav-btn" id="metis-v2-recover-draft">Restore</button><button type="button" class="metis-se-nav-btn" id="metis-v2-discard-recovery">Discard</button></div>';
        }

        function restoreRecoveryDraft() {
            if (!window.localStorage) return;
            var draft = j(window.localStorage.getItem(recoveryKey()) || '', null);
            if (!draft || !draft.layout_json) return;
            var layoutModel = layoutModelFromLayout(draft.layout_json, isPostContext());
            state.sections = normalizeSections(layoutModel.sections, isPostContext());
            state.hero = normalizeHeroState(layoutModel.hero, layoutModel.page_type === 'homepage' && isPageContext());
            state.selectedTemplateKey = normalizeSelectedTemplateKey(layoutModel.template_key, layoutModel.page_type);
            var titleEl = document.getElementById('metis-v2-title');
            var slugEl = document.getElementById('metis-v2-slug');
            if (titleEl && draft.title) titleEl.value = s(draft.title);
            if (slugEl && draft.slug) slugEl.value = s(draft.slug);
            state.activeSection = -1;
            syncTemplateField();
            renderSectionList();
            syncStepUi();
            setStatus('Recovered local draft', 'saving');
        }

        function loadRevisionList() {
            if (!isManagedContentContext() || !(state.id > 0 || state.key)) return Promise.resolve();
            return request(contentAction('editor_revisions_list'), {
                context: isPostContext() ? 'post' : 'page',
                id: state.id || 0,
                key: state.key || '',
                limit: 12
            }).then(function (resp) {
                state.revisions = Array.isArray(resp.revisions) ? resp.revisions : [];
                renderRevisionList();
            }).catch(function () {});
        }

        function renderRevisionList() {
            var host = document.getElementById('metis-v2-revisions');
            if (!host) return;
            if (!state.revisions || !state.revisions.length) {
                host.innerHTML = '<div class="metis-se-meta-value">No revisions yet.</div>';
                renderRevisionCompare(null);
                return;
            }
            host.innerHTML = state.revisions.map(function (row) {
                var revisionId = esc(s(row.id || '0'));
                return '<div class="metis-content-revision-row"><span>' + esc(s(row.note || 'Revision')) + '<small>' + esc(s(row.created_at || '')) + '</small></span><div class="metis-content-revision-actions"><button type="button" class="metis-se-nav-btn" data-compare-revision="' + revisionId + '">Compare</button>' + (state.canEdit ? '<button type="button" class="metis-se-nav-btn" data-restore-revision="' + revisionId + '">Restore</button>' : '') + '</div></div>';
            }).join('');
        }

        function renderRevisionCompare(resp) {
            var host = document.getElementById('metis-v2-revision-compare');
            if (!host) return;
            if (!resp) {
                host.hidden = true;
                host.innerHTML = '';
                return;
            }
            var diffs = Array.isArray(resp.diffs) ? resp.diffs : [];
            var changedCount = parseInt(s(resp.changed_count || '0'), 10) || 0;
            var rows = diffs.length ? diffs.map(function (row) {
                var changed = !!row.changed;
                return '<div class="metis-content-compare-row' + (changed ? ' is-changed' : '') + '">' +
                    '<div class="metis-content-compare-label"><strong>' + esc(s(row.label || row.field || 'Field')) + '</strong><span>' + (changed ? 'Changed' : 'No change') + '</span></div>' +
                    '<div class="metis-content-compare-values"><div><small>' + esc(s(resp.before_label || 'Revision')) + '</small><pre>' + esc(s(row.before || '—')) + '</pre></div><div><small>' + esc(s(resp.after_label || 'Current draft')) + '</small><pre>' + esc(s(row.after || '—')) + '</pre></div></div>' +
                '</div>';
            }).join('') : '<div class="metis-se-meta-value">No comparable fields were found for this revision.</div>';
            host.hidden = false;
            host.innerHTML = '<div class="metis-content-compare-head"><div><strong>Revision Compare</strong><span>' + esc(String(changedCount)) + ' changed field' + (changedCount === 1 ? '' : 's') + '</span></div><button type="button" class="metis-se-nav-btn" data-clear-revision-compare="1">Clear</button></div>' + rows;
        }

        function loadRevisionCompare(revisionId) {
            var host = document.getElementById('metis-v2-revision-compare');
            if (host) {
                host.hidden = false;
                host.innerHTML = '<div class="metis-se-meta-value">Loading comparison...</div>';
            }
            return request(contentAction('editor_revision_compare'), {
                context: isPostContext() ? 'post' : 'page',
                id: state.id || 0,
                key: state.key || '',
                revision_id: revisionId
            }).then(function (resp) {
                renderRevisionCompare(resp || {});
            }).catch(function (err) {
                if (host) host.innerHTML = '<div class="metis-se-meta-value is-error">Compare failed: ' + esc(s(err && err.message || 'Request failed.')) + '</div>';
                setStatus('Compare failed: ' + s(err && err.message || 'Request failed.'), 'error');
            });
        }

        function requestEditorConfirmation(options) {
            var modal = document.getElementById('metis-v2-confirm-modal');
            if (!modal) return Promise.resolve(false);
            var title = document.getElementById('metis-v2-confirm-title');
            var message = document.getElementById('metis-v2-confirm-message');
            var accept = document.getElementById('metis-v2-confirm-accept');
            var cancel = document.getElementById('metis-v2-confirm-cancel');
            if (title) title.textContent = s(options && options.title || 'Confirm action');
            if (message) message.textContent = s(options && options.message || 'Continue?');
            if (accept) accept.textContent = s(options && options.confirmLabel || 'Continue');
            if (cancel) cancel.textContent = s(options && options.cancelLabel || 'Cancel');
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            return new Promise(function (resolve) {
                var complete = function (ok) {
                    modal.style.display = 'none';
                    modal.setAttribute('aria-hidden', 'true');
                    modal.onclick = null;
                    document.removeEventListener('keydown', onKey, true);
                    resolve(!!ok);
                };
                var onKey = function (event) {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        complete(false);
                    }
                };
                modal.onclick = function (event) {
                    if (event.target === modal || event.target.closest('[data-confirm-cancel]')) {
                        complete(false);
                        return;
                    }
                    if (event.target.closest('[data-confirm-accept]')) {
                        complete(true);
                    }
                };
                document.addEventListener('keydown', onKey, true);
                setTimeout(function () {
                    if (accept && typeof accept.focus === 'function') accept.focus();
                }, 0);
            });
        }

        function loadReusableBlocks() {
            if (!isManagedContentContext()) return Promise.resolve();
            return request(contentAction('reusable_blocks_list'), {
                context: isPostContext() ? 'post' : 'website'
            }).then(function (resp) {
                state.reusableBlocks = Array.isArray(resp.items) ? resp.items : [];
                renderReusableBlocks();
            }).catch(function () {});
        }

        function renderReusableBlocks() {
            var host = document.getElementById('metis-v2-reusable-blocks');
            if (!host) return;
            if (!state.reusableBlocks || !state.reusableBlocks.length) {
                host.innerHTML = '<div class="metis-se-meta-value">No reusable blocks saved.</div>';
                return;
            }
            host.innerHTML = state.reusableBlocks.slice(0, 12).map(function (row) {
                return '<button type="button" class="metis-content-library-item" data-insert-reusable="' + esc(s(row.block_code || '')) + '"><strong>' + esc(s(row.name || 'Reusable block')) + '</strong><small>' + esc(sectionTypeLabel(s(row.type || 'text'))) + '</small></button>';
            }).join('');
        }

        function sectionTypeFromReusableBlockType(type) {
            var t = s(type || 'text');
            var map = {
                grid: 'card_grid',
                post_list: 'posts_list',
                events_block: 'events',
                donation_form_block: 'donation_form',
                progress_bar_block: 'donation_progress',
                donation_goal_summary_block: 'donation_progress',
                campaign_description_block: 'campaign_summary'
            };
            return map[t] || t;
        }

        function sectionToReusableBlock(section) {
            var sec = section && typeof section === 'object' ? section : defaultSectionByType('text');
            var type = s(sec.type || 'text');
            var data = {};
            if (type === 'heading') data = {
                content: s(sec.content && sec.content.text || 'Heading'),
                level: s(sec.content && sec.content.level || 'h2'),
                align: s(sec.content && sec.content.align || 'left'),
                vertical_align: s(sec.content && sec.content.vertical_align || 'top'),
                variant: s(sec.content && sec.content.variant || 'default')
            };
            else if (type === 'text') data = { content: s(sec.content && sec.content.body || '<p></p>'), tag: 'div' };
            else if (type === 'image') data = { src: s(sec.content && sec.content.src || ''), alt: s(sec.content && sec.content.alt || '') };
            else if (type === 'button') data = { label: s(sec.content && sec.content.label || 'Learn more'), url: s(sec.content && sec.content.url || '#') };
            else if (type === 'hero') data = Object.assign({}, sec.content || {});
            else if (type === 'html') data = { content: s(sec.content && sec.content.html || ''), tag: 'div' };
            else data = Object.assign({}, sec.content || {});
            var blockTypeMap = {
                card_grid: 'grid',
                donation_form: 'donation_form_block',
                donation_progress: 'progress_bar_block',
                campaign_summary: 'campaign_description_block',
                posts_list: 'post_list',
                events: 'events_block'
            };
            return { type: blockTypeMap[type] || type, data: data, style: {} };
        }

        function renderFeaturedImageField() {
            var hiddenEl = document.getElementById('metis-v2-featured-image-id');
            var previewEl = document.getElementById('metis-v2-featured-image-preview');
            var captionRowEl = document.getElementById('metis-v2-featured-image-caption-row');
            var clearButton = document.getElementById('metis-v2-featured-image-clear');
            var selected = featuredImageMediaById(hiddenEl && hiddenEl.value || '0');
            if (!previewEl) return;
            if (captionRowEl) {
                captionRowEl.style.display = selected ? '' : 'none';
            }
            if (!selected) {
                previewEl.innerHTML = '<div class="metis-media-empty">No featured image set.</div>';
                if (clearButton) clearButton.hidden = true;
                return;
            }
            if (clearButton) clearButton.hidden = false;
            previewEl.innerHTML = '' +
                '<div class="metis-media-card metis-media-card--preview">' +
                    '<div class="metis-media-thumb-wrap">' +
                        '<img class="metis-media-thumb" src="' + esc(s(selected.url || '')) + '" alt="' + esc(s(selected.label || 'Featured image')) + '">' +
                    '</div>' +
                    '<div class="metis-media-meta">' +
                        '<div class="metis-media-name">' + esc(s(selected.label || 'Image')) + '</div>' +
                        '<div class="metis-media-mime">' + esc(s(selected.mime || '')) + '</div>' +
                    '</div>' +
                '</div>';
        }

        function setEditorLoading(isLoading, copy) {
            var shell = root.querySelector('.metis-se-shell');
            var overlay = document.getElementById('metis-v2-editor-loading');
            var copyEl = document.getElementById('metis-v2-editor-loading-copy');
            if (copyEl && copy) copyEl.textContent = s(copy);
            if (shell) shell.classList.toggle('is-loading', !!isLoading);
            if (overlay) {
                overlay.hidden = !isLoading;
                overlay.setAttribute('aria-hidden', isLoading ? 'false' : 'true');
            }
        }

        function closeFeaturedImageModal() {
            var modal = document.getElementById('metis-v2-featured-image-modal');
            if (!modal) return;
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }

        function renderFeaturedImagePickerList() {
            var list = document.getElementById('metis-v2-featured-image-list');
            var hiddenEl = document.getElementById('metis-v2-featured-image-id');
            var countEl = document.getElementById('metis-v2-featured-image-count');
            var searchEl = document.getElementById('metis-v2-featured-image-search');
            var mimeEl = document.getElementById('metis-v2-featured-image-mime');
            var selectedId = parseInt(s(hiddenEl && hiddenEl.value || '0'), 10) || 0;
            if (!list) return;
            var search = s(searchEl && searchEl.value || state.featuredImageFilter.search || '').trim().toLowerCase();
            var mime = s(mimeEl && mimeEl.value || state.featuredImageFilter.mime || '').trim().toLowerCase();
            state.featuredImageFilter.search = search;
            state.featuredImageFilter.mime = mime;
            var rows = imageMediaOptions().filter(function (row) {
                var rowMime = s(row && (row.mime || row.mime_type) || '').toLowerCase();
                var haystack = [
                    s(row && row.label || ''),
                    s(row && row.file_name || ''),
                    rowMime
                ].join(' ').toLowerCase();
                if (mime && rowMime !== mime) return false;
                if (search && haystack.indexOf(search) === -1) return false;
                return true;
            });
            if (countEl) {
                countEl.textContent = rows.length ? (rows.length + ' image' + (rows.length === 1 ? '' : 's')) : 'No matches';
            }
            if (!rows.length) {
                list.innerHTML = '<div class="metis-media-empty">No images match the current filters.</div>';
                return;
            }
            list.innerHTML = rows.map(function (row) {
                var rowId = parseInt(s(row && (row.id || row.media_id || '0') || '0'), 10) || 0;
                var selectedClass = rowId === selectedId ? ' metis-media-card--selected' : '';
                return '' +
                    '<button type="button" class="metis-media-card' + selectedClass + '" data-featured-image-select="' + esc(String(rowId)) + '">' +
                        '<div class="metis-media-thumb-wrap">' +
                            '<img class="metis-media-thumb" src="' + esc(s(row && row.url || '')) + '" alt="' + esc(s(row && row.label || 'Image')) + '">' +
                        '</div>' +
                        '<div class="metis-media-meta">' +
                            '<div class="metis-media-name">' + esc(s(row && row.label || 'Image')) + '</div>' +
                            '<div class="metis-media-mime">' + esc(s(row && row.mime || '')) + '</div>' +
                        '</div>' +
                    '</button>';
            }).join('');
        }

        function openFeaturedImageModal() {
            var modal = document.getElementById('metis-v2-featured-image-modal');
            var searchEl = document.getElementById('metis-v2-featured-image-search');
            var mimeEl = document.getElementById('metis-v2-featured-image-mime');
            if (!modal) return;
            var rows = imageMediaOptions();
            if (mimeEl) {
                mimeEl.innerHTML = '<option value="">All image types</option>' + uniqueImageMimeOptions(rows).map(function (value) {
                    return '<option value="' + esc(value) + '">' + esc(value.replace('image/', '').toUpperCase()) + '</option>';
                }).join('');
                mimeEl.value = state.featuredImageFilter.mime || '';
            }
            if (searchEl) {
                searchEl.value = state.featuredImageFilter.search || '';
            }
            renderFeaturedImagePickerList();
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            if (searchEl) searchEl.focus();
        }

        function closeInlineImageModal() {
            var modal = document.getElementById('metis-v2-inline-image-modal');
            if (!modal) return;
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            state.inlineImageTargetId = '';
        }

        function renderInlineImagePickerList() {
            var list = document.getElementById('metis-v2-inline-image-list');
            var countEl = document.getElementById('metis-v2-inline-image-count');
            var searchEl = document.getElementById('metis-v2-inline-image-search');
            var mimeEl = document.getElementById('metis-v2-inline-image-mime');
            if (!list) return;
            var search = s(searchEl && searchEl.value || state.inlineImageFilter.search || '').trim().toLowerCase();
            var mime = s(mimeEl && mimeEl.value || state.inlineImageFilter.mime || '').trim().toLowerCase();
            state.inlineImageFilter.search = search;
            state.inlineImageFilter.mime = mime;
            var rows = imageMediaOptions().filter(function (row) {
                var rowMime = s(row && (row.mime || row.mime_type) || '').toLowerCase();
                var haystack = [
                    s(row && row.label || ''),
                    s(row && row.file_name || ''),
                    rowMime
                ].join(' ').toLowerCase();
                if (mime && rowMime !== mime) return false;
                if (search && haystack.indexOf(search) === -1) return false;
                return true;
            });
            if (countEl) countEl.textContent = rows.length ? (rows.length + ' image' + (rows.length === 1 ? '' : 's')) : 'No matches';
            if (!rows.length) {
                list.innerHTML = '<div class="metis-media-empty">No images match the current filters.</div>';
                return;
            }
            list.innerHTML = rows.map(function (row) {
                var rowId = parseInt(s(row && (row.id || row.media_id || '0') || '0'), 10) || 0;
                return '' +
                    '<button type="button" class="metis-media-card" data-inline-image-select="' + esc(String(rowId)) + '">' +
                        '<div class="metis-media-thumb-wrap">' +
                            '<img class="metis-media-thumb" src="' + esc(s(row && row.url || '')) + '" alt="' + esc(s(row && row.label || 'Image')) + '">' +
                        '</div>' +
                        '<div class="metis-media-meta">' +
                            '<div class="metis-media-name">' + esc(s(row && row.label || 'Image')) + '</div>' +
                            '<div class="metis-media-mime">' + esc(s(row && row.mime || '')) + '</div>' +
                        '</div>' +
                    '</button>';
            }).join('');
        }

        function openInlineImageModal(targetId) {
            var modal = document.getElementById('metis-v2-inline-image-modal');
            var searchEl = document.getElementById('metis-v2-inline-image-search');
            var mimeEl = document.getElementById('metis-v2-inline-image-mime');
            if (!modal) return;
            state.inlineImageTargetId = s(targetId || '');
            var rows = imageMediaOptions();
            if (mimeEl) {
                mimeEl.innerHTML = '<option value="">All image types</option>' + uniqueImageMimeOptions(rows).map(function (value) {
                    return '<option value="' + esc(value) + '">' + esc(value.replace('image/', '').toUpperCase()) + '</option>';
                }).join('');
                mimeEl.value = state.inlineImageFilter.mime || '';
            }
            if (searchEl) searchEl.value = state.inlineImageFilter.search || '';
            renderInlineImagePickerList();
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            if (searchEl) searchEl.focus();
        }

        function editorMediaUploadPanelHtml(context) {
            if (!state.canManageMedia) return '';
            var cleanContext = s(context || 'inline');
            return '' +
                '<div class="metis-editor-media-upload" data-editor-media-upload-panel="' + esc(cleanContext) + '">' +
                    '<div class="metis-editor-media-upload__main">' +
                        '<div><strong>Upload image</strong><span data-editor-media-upload-status="' + esc(cleanContext) + '">Choose an image or drop it here.</span></div>' +
                        '<button type="button" class="metis-se-nav-btn" data-editor-media-upload-btn="' + esc(cleanContext) + '">Upload</button>' +
                        '<input type="file" class="metis-editor-media-upload__input" data-editor-media-upload-input="' + esc(cleanContext) + '" accept="image/*" hidden>' +
                    '</div>' +
                    '<div class="metis-editor-media-upload__progress" data-editor-media-upload-progress-wrap="' + esc(cleanContext) + '" hidden><span data-editor-media-upload-progress="' + esc(cleanContext) + '"></span></div>' +
                '</div>';
        }

        function setEditorMediaUploadUi(context, active, percent, message) {
            var pct = Math.max(0, Math.min(100, parseInt(s(percent || '0'), 10) || 0));
            var ctx = s(context || state.mediaUpload.context || '');
            root.querySelectorAll('[data-editor-media-upload-panel]').forEach(function (panel) {
                var panelCtx = s(panel.getAttribute('data-editor-media-upload-panel') || '');
                var isCurrent = !ctx || panelCtx === ctx;
                panel.classList.toggle('is-uploading', !!active && isCurrent);
                panel.setAttribute('aria-busy', active && isCurrent ? 'true' : 'false');
                panel.querySelectorAll('[data-editor-media-upload-btn], [data-editor-media-upload-input]').forEach(function (control) {
                    control.disabled = !!active || !state.canManageMedia;
                });
                var status = panel.querySelector('[data-editor-media-upload-status]');
                if (status && isCurrent) {
                    status.textContent = s(message || (active ? 'Uploading...' : 'Ready to upload.'));
                }
                var wrap = panel.querySelector('[data-editor-media-upload-progress-wrap]');
                var bar = panel.querySelector('[data-editor-media-upload-progress]');
                if (wrap) wrap.hidden = !(active && isCurrent);
                if (bar && isCurrent) bar.style.width = pct + '%';
            });
        }

        function editorMediaUploadPanelFromEvent(event) {
            return event && event.target && event.target.closest
                ? event.target.closest('[data-editor-media-upload-panel]')
                : null;
        }

        function eventHasUploadFiles(event) {
            if (!event || !event.dataTransfer) return false;
            if (event.dataTransfer.files && event.dataTransfer.files.length) return true;
            var types = event.dataTransfer.types || [];
            for (var i = 0; i < types.length; i += 1) {
                if (String(types[i]) === 'Files') return true;
            }
            return false;
        }

        function clearEditorMediaDropState(panel) {
            if (panel) {
                panel.classList.remove('is-drag-over');
                return;
            }
            root.querySelectorAll('[data-editor-media-upload-panel].is-drag-over').forEach(function (node) {
                node.classList.remove('is-drag-over');
            });
        }

        function upsertEditorMediaOption(media) {
            var row = normalizeMediaOption(media);
            if (!row.url) return null;
            var rowId = parseInt(s(row.id || '0'), 10) || 0;
            var rowUrl = s(row.url || '');
            var rows = Array.isArray(state.options.media) ? state.options.media : [];
            state.options.media = rows.filter(function (existing) {
                var existingId = parseInt(s(existing && (existing.id || existing.media_id || '0') || '0'), 10) || 0;
                var existingUrl = s(existing && existing.url || '');
                if (rowId > 0 && existingId === rowId) return false;
                return !(rowUrl && existingUrl === rowUrl);
            });
            state.options.media.unshift(row);
            return row;
        }

        function applyUploadedEditorImage(context, media) {
            var row = upsertEditorMediaOption(media);
            if (!row) return;
            var ctx = s(context || 'inline');
            if (ctx === 'featured') {
                var hiddenEl = document.getElementById('metis-v2-featured-image-id');
                if (hiddenEl && row.id > 0) hiddenEl.value = String(row.id);
                renderFeaturedImagePickerList();
                renderFeaturedImageField();
                closeFeaturedImageModal();
                setDirtyAutosave();
                return;
            }

            renderInlineImagePickerList();
            var blockTarget = blockImageTargetFromId(state.inlineImageTargetId);
            if (blockTarget && state.sections[blockTarget.index]) {
                var imageSection = state.sections[blockTarget.index];
                imageSection.content = imageSection.content && typeof imageSection.content === 'object' ? imageSection.content : {};
                imageSection.content[blockTarget.field] = s(row.url || '');
                if (imageSection.type === 'image' && blockTarget.field === 'src' && !s(imageSection.content.alt || '')) {
                    imageSection.content.alt = s(row.label || 'Image');
                }
                state.activeSection = blockTarget.index;
                closeInlineImageModal();
                renderSectionList();
                setDirtyAutosave();
                return;
            }

            var inlineTarget = state.inlineImageTargetId ? document.getElementById(state.inlineImageTargetId) : null;
            requestInlineImageSize().then(function (sizeChoice) {
                if (inlineTarget && sizeChoice) {
                    insertHtmlAtSelection(inlineTarget, '<figure class="metis-inline-image is-' + esc(sizeChoice) + '"><img src="' + esc(s(row.url || '')) + '" alt="' + esc(s(row.label || 'Inline image')) + '"></figure>');
                    setDirtyAutosave();
                }
                closeInlineImageModal();
            });
        }

        function editorUploadErrorMessage(raw, fallback) {
            var fallbackMessage = fallback || 'Upload failed.';
            if (!raw) return fallbackMessage;
            var payload = raw;
            if (typeof raw === 'string') {
                try {
                    payload = JSON.parse(raw);
                } catch (_err) {
                    return raw.trim().slice(0, 240) || fallbackMessage;
                }
            }
            if (payload && payload.data && payload.data.message) return s(payload.data.message);
            if (payload && payload.message) return s(payload.message);
            if (typeof payload.data === 'string') return s(payload.data);
            return fallbackMessage;
        }

        function uploadEditorImage(context, files) {
            if (!state.canManageMedia) {
                setStatus('Media upload requires permission.', 'error');
                return;
            }
            if (state.mediaUpload.active) {
                setEditorMediaUploadUi(context, true, state.mediaUpload.percent, 'Upload already in progress.');
                return;
            }
            var file = files && files.length ? files[0] : null;
            if (!file) return;
            if (file.type && file.type.indexOf('image/') !== 0) {
                setEditorMediaUploadUi(context, false, 0, 'Choose an image file.');
                setStatus('Choose an image file.', 'error');
                return;
            }

            var action = 'metis_website_editor_media_upload';
            var cfg = ajaxConfig(action);
            var form = new FormData();
            form.append('action', action);
            form.append('nonce', cfg.nonce || '');
            form.append('metis_action_nonce', cfg.action_nonce || '');
            form.append('metis_csrf_action', cfg.csrf_action || defaultCsrfAction());
            form.append('file', file);

            state.mediaUpload.active = true;
            state.mediaUpload.context = s(context || 'inline');
            state.mediaUpload.percent = 0;
            setEditorMediaUploadUi(context, true, 0, 'Uploading ' + s(file.name || 'image') + '...');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', cfg.ajax_url, true);
            xhr.responseType = 'text';
            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) return;
                var pct = Math.max(1, Math.min(99, Math.round((event.loaded / event.total) * 100)));
                state.mediaUpload.percent = pct;
                setEditorMediaUploadUi(context, true, pct, 'Uploading ' + pct + '%...');
            };
            xhr.onload = function () {
                var parsed = null;
                try {
                    parsed = JSON.parse(s(xhr.responseText || ''));
                } catch (_err) {}
                if (xhr.status < 200 || xhr.status >= 300 || !parsed || !parsed.success) {
                    var message = editorUploadErrorMessage(parsed || xhr.responseText, 'Upload failed.');
                    setEditorMediaUploadUi(context, false, 0, message);
                    setStatus(message, 'error');
                    return;
                }
                state.mediaUpload.percent = 100;
                setEditorMediaUploadUi(context, true, 100, 'Upload complete.');
                applyUploadedEditorImage(context, parsed.data && parsed.data.media ? parsed.data.media : {});
                setStatus('Image uploaded.', 'ok');
            };
            xhr.onerror = function () {
                setEditorMediaUploadUi(context, false, 0, 'Upload failed.');
                setStatus('Upload failed.', 'error');
            };
            xhr.onloadend = function () {
                state.mediaUpload.active = false;
                state.mediaUpload.context = '';
                window.setTimeout(function () {
                    if (!state.mediaUpload.active) setEditorMediaUploadUi(context, false, 0, 'Ready to upload.');
                }, 800);
                root.querySelectorAll('[data-editor-media-upload-input]').forEach(function (input) {
                    input.value = '';
                });
            };
            xhr.send(form);
        }

        function updatePreview() {
            var host = document.getElementById('metis-v2-preview');
            if (!host) return;
            host.innerHTML = '<div class="metis-se-meta-value">Rendering preview...</div>';
            var titleEl = document.getElementById('metis-v2-title');
            var featuredImageEl = document.getElementById('metis-v2-featured-image-id');
            var featuredImageCaptionEl = document.getElementById('metis-v2-featured-image-caption');
            var layoutRaw = layoutJsonFromState();
            var layoutObj = j(layoutRaw, {});
            var pageType = currentPageType();
            request('metis_editor_render_preview', {
                context: isPostContext() ? 'post' : 'website',
                layout: layoutObj,
                template_key: selectedTemplateKey(pageType),
                page_type: pageType,
                page_title: s(titleEl && titleEl.value || ''),
                featured_image_id: parseInt(s(featuredImageEl && featuredImageEl.value || '0'), 10) || 0,
                featured_image_caption: s(featuredImageCaptionEl && featuredImageCaptionEl.value || '')
            }).then(function (resp) {
                var doc = s(resp && resp.document_html || '');
                if (!doc) {
                    host.innerHTML = '<div class="metis-se-meta-value">Preview unavailable.</div>';
                    return;
                }
                host.innerHTML = '';
                var frame = document.createElement('iframe');
                frame.className = 'metis-se-preview-frame';
                frame.setAttribute('title', 'Preview');
                frame.style.width = '100%';
                frame.style.minHeight = '70vh';
                frame.style.border = '1px solid #e2e8f0';
                frame.srcdoc = doc;
                host.appendChild(frame);
            }).catch(function (err) {
                host.innerHTML = '<div class="metis-se-meta-value">Preview failed: ' + esc(s(err && err.message || 'Request failed.')) + '</div>';
            });
        }

        function saveEntity(autosave) {
            if (!state.canEdit && !(state.id < 1 && state.canCreate)) {
                setStatus('You do not have permission to save this item.', 'error');
                return;
            }
            if (state.saving) return;
            var titleEl = document.getElementById('metis-v2-title');
            var slugEl = document.getElementById('metis-v2-slug');
            var statusEl = document.getElementById('metis-v2-status');
            var publishedEl = document.getElementById('metis-v2-published-date');
            var parentEl = document.getElementById('metis-v2-parent-id');
            var categoryIds = selectedCategoryIds('metis-v2-category-ids');
            var excerptEl = document.getElementById('metis-v2-excerpt');
            var featuredImageEl = document.getElementById('metis-v2-featured-image-id');
            var featuredImageCaptionEl = document.getElementById('metis-v2-featured-image-caption');
            var title = s(titleEl && titleEl.value || '').trim();
            var slug = slugifyValue(s(slugEl && slugEl.value || '').trim().replace(/^\/+|\/+$/g, ''));
            if (slugEl) slugEl.value = slug;
            if (!title) { setStatus('Title is required.', 'error'); return; }
            if (!slug) { setStatus('URL Path must include letters or numbers.', 'error'); return; }
            var pageType = currentPageType();
            var action = '';
            var payload = {
                autosave: autosave ? 1 : 0,
                title: title,
                slug: slug,
                status: hasPublishedVersion() ? 'published' : (s(statusEl && statusEl.value || 'draft') || 'draft'),
                page_type: pageType,
                template_key: selectedTemplateKey(pageType)
            };
            var publishedAt = s(publishedEl && publishedEl.value || '').trim();
            if (publishedAt) publishedAt = publishedAt.replace('T', ' ') + ':00';
            if ((payload.status === 'published' || payload.status === 'scheduled') && !state.canPublish) {
                payload.status = 'draft';
            }
            if (payload.status === 'scheduled' && publishedAt) {
                payload.published_date = publishedAt;
                payload.schedule_at = publishedAt;
            }
            if (isPageContext()) {
                action = (state.id > 0 || state.key) ? contentAction('page_save') : contentAction('page_create');
                if (state.id > 0) payload.id = state.id;
                if (state.key) payload.key = state.key;
                payload.layout_json = layoutJsonFromState();
                payload.parent_id = parseInt(s(parentEl && parentEl.value || '0'), 10) || 0;
                payload.section_count = state.sections.length || 1;
                var home = document.getElementById('metis-v2-homepage');
                payload.set_as_homepage = home && home.checked ? 1 : 0;
            } else {
                action = (state.id > 0 || state.key) ? contentAction('post_save') : contentAction('post_create');
                if (state.id > 0) payload.id = state.id;
                if (state.key) payload.key = state.key;
                payload.content_json = layoutJsonFromState();
                payload.post_category_ids = categoryIds;
                payload.post_category_id = categoryIds.length ? categoryIds[0] : 0;
                payload.parent_page_id = parseInt(s(parentEl && parentEl.value || '0'), 10) || 0;
                if ((payload.status === 'published' || payload.status === 'scheduled') && !payload.post_category_ids.length) {
                    setStatus('Published or scheduled posts must have a category.', 'error');
                    return;
                }
                payload.excerpt = s(excerptEl && excerptEl.value || '');
                payload.featured_image_id = parseInt(s(featuredImageEl && featuredImageEl.value || '0'), 10) || 0;
                payload.featured_image_caption = payload.featured_image_id > 0 ? s(featuredImageCaptionEl && featuredImageCaptionEl.value || '').trim() : '';
            }
            state.saving = true;
            setStatus(autosave ? 'Autosaving...' : 'Saving...', 'saving');
            request(action, payload).then(function (resp) {
                var entity = isPageContext() ? (resp.page || {}) : (resp.post || {});
                state.entity = entity;
                state.entityLoaded = true;
                state.id = parseInt(s(entity.id || state.id || '0'), 10) || state.id;
                state.key = isPageContext() ? s(entity.page_code || state.key) : s(entity.post_code || state.key);
                applyInputsFromEntity(entity);
                var savedMessage = 'Saved at ' + formatConfiguredTime(new Date(), '');
                var savedKind = 'ok';
                if (resp.public_routes_auto_enabled) {
                    savedMessage = 'Published and public routes enabled.';
                } else if (resp.launch_required && entity && entity.is_homepage) {
                    savedMessage = resp.can_launch
                        ? 'Published. Enable public routes in Launch Center.'
                        : 'Published. Launch permission is required for public routes.';
                    savedKind = 'saving';
                }
                setStatus(savedMessage, savedKind);
                state.dirty = false;
                clearRecoveryDraft();
                loadRevisionList();
            }).catch(function (err) {
                setStatus('Save failed: ' + s(err && err.message || 'Request failed.'), 'error');
            }).finally(function () {
                state.saving = false;
            });
        }

        function renderTop() {
            var title = state.entity && state.entity.title ? s(state.entity.title) : (isPostContext() ? 'Post' : 'Page');
            root.innerHTML = '' +
                '<div class="metis-se-shell metis-builder-shell">' +
                    '<div id="metis-v2-editor-loading" class="metis-se-editor-loading" aria-hidden="false">' +
                        '<div class="metis-se-editor-loading-card">' +
                            '<div class="metis-se-editor-loading-spinner" aria-hidden="true"></div>' +
                            '<div class="metis-se-editor-loading-title">Loading editor</div>' +
                            '<div id="metis-v2-editor-loading-copy" class="metis-se-editor-loading-copy">Loading content and settings...</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="metis-se-topbar metis-builder-topbar">' +
                        '<div class="metis-se-top-left"><a class="metis-se-nav-btn" href="' + esc(isPostContext() ? (appBasePath() + '/admin/' + contentModuleSlug() + '/posts/') : (appBasePath() + '/admin/' + contentModuleSlug() + '/pages/')) + '">&larr; ' + esc(isPostContext() ? 'Back to Posts' : 'Back to Pages') + '</a></div>' +
                        '<div class="metis-se-top-center"><div id="metis-se-top-title" class="metis-se-top-title">' + esc(title) + '</div><span id="metis-builder-top-status" class="metis-builder-status-pill">Draft</span></div>' +
                        '<div class="metis-se-top-right"><span id="metis-se-save-status" class="metis-se-save-status"></span><button type="button" class="metis-se-nav-btn" data-preview-toggle="1">Preview</button><button type="button" class="metis-se-nav-btn" data-revisions-toggle="1">Revisions</button>' + ((state.canEdit || state.canCreate) ? '<button id="metis-v2-publish" type="button" class="metis-se-nav-btn metis-builder-primary-btn">Publish</button>' : '') + '</div>' +
                    '</div>' +
	                    '<div class="metis-se-stage-wrap metis-builder-stage-wrap">' +
	                        '<section class="metis-se-stage metis-builder-stage" data-v2-step="1">' +
	                            '<main class="metis-builder-canvas" aria-label="Page canvas">' +
	                                '<div class="metis-builder-canvas-frame">' +
	                                    '<div id="metis-v2-canvas" class="metis-builder-canvas-inner"></div>' +
                                '</div>' +
                            '</main>' +
                            '<aside class="metis-builder-settings-panel" aria-label="Settings">' +
                                '<div id="metis-builder-settings-page" class="metis-builder-settings-page">' +
                                    '<div class="metis-content-panel-title">' + esc(contentSettingsLabel()) + '</div>' +
                                    '<div id="metis-v2-recovery"></div>' +
                                    '<div class="metis-builder-settings-card"><div class="metis-se-field-grid">' +
                                        '<div class="metis-se-field-row"><label for="metis-v2-title">Title</label><input id="metis-v2-title" class="metis-se-input" type="text" placeholder="Title"></div>' +
                                        '<div class="metis-se-field-row"><label for="metis-v2-status">Status</label><select id="metis-v2-status" class="metis-se-select"><option value="draft">Draft</option>' + (state.canPublish ? '<option value="published">Published</option><option value="scheduled">Scheduled</option>' : '') + '</select></div>' +
                                        '<div class="metis-se-field-row"><label for="metis-v2-slug">URL Path</label><input id="metis-v2-slug" class="metis-se-input" type="text" placeholder="' + (isPostContext() ? 'post-slug-title' : 'page-slug') + '">' + (isPostContext() ? '<div class="metis-se-field-help">Public path uses the primary category, optional child category, and original publish year automatically.</div><div id="metis-v2-post-path-preview" class="metis-se-meta-value metis-se-path-preview">Select a primary category to generate the public path.</div>' : '') + '</div>' +
                                        '<div class="metis-se-field-row"><label for="metis-v2-template-key">' + esc(contentTypeLabel()) + ' Template</label><select id="metis-v2-template-key" class="metis-se-select"><option value="">Loading templates...</option></select><div id="metis-v2-template-display" class="metis-se-meta-value">Default template</div></div>' +
                                        '<div class="metis-se-field-row"><label for="metis-v2-parent-id">Parent Page</label><select id="metis-v2-parent-id" class="metis-se-select"><option value="">None</option></select></div>' +
                                        (isPostContext() ? '<div class="metis-se-field-row"><label>Categories</label><div id="metis-v2-category-chip-host">' + categoryChipField('metis-v2-category-ids', [], 'No categories available.') + '</div></div>' : '') +
                                        (isPostContext() ? '<div class="metis-se-field-row"><label>Featured Image</label><input id="metis-v2-featured-image-id" type="hidden" value=""><div class="metis-featured-image-actions"><button type="button" id="metis-v2-featured-image-open" class="metis-se-nav-btn">Choose Image</button><button type="button" id="metis-v2-featured-image-clear" class="metis-se-nav-btn">Remove</button></div><div id="metis-v2-featured-image-preview" class="metis-media-grid metis-featured-image-preview"></div></div>' : '') +
                                        (isPostContext() ? '<div class="metis-se-field-row" id="metis-v2-featured-image-caption-row" style="display:none;"><label for="metis-v2-featured-image-caption">Featured Image Caption</label><textarea id="metis-v2-featured-image-caption" class="metis-se-textarea" rows="2" placeholder="Optional caption shown under the featured image."></textarea></div>' : '') +
                                        (isPostContext() ? '<div class="metis-se-field-row"><label for="metis-v2-excerpt">Excerpt</label><textarea id="metis-v2-excerpt" class="metis-se-textarea" rows="3" placeholder="Optional summary for cards and SEO."></textarea></div>' : '') +
                                        '<div class="metis-se-field-row"><label>Author</label><div id="metis-v2-author-name" class="metis-se-meta-value">-</div></div>' +
                                        '<div class="metis-se-field-row" id="metis-v2-last-edit-row"><label>Last Edit</label><div id="metis-v2-last-edit" class="metis-se-meta-value">-</div></div>' +
                                        '<div class="metis-se-field-row"><label for="metis-v2-published-date">Published Date</label><input id="metis-v2-published-date" class="metis-se-input" type="datetime-local"></div>' +
                                        (isPageContext() ? '<div class="metis-se-field-row"><label class="metis-se-check-label" for="metis-v2-homepage"><input id="metis-v2-homepage" type="checkbox"> Set as homepage</label></div>' : '') +
                                    '</div></div>' +
                                    (isPageContext() ? '<div id="metis-v2-hero-editor" class="metis-se-builder-stack"></div>' : '') +
                                '</div>' +
                                '<div id="metis-builder-settings-block" class="metis-builder-settings-block" hidden>' +
                                    '<div class="metis-content-panel-title">Block Settings</div>' +
                                    '<button type="button" class="metis-se-nav-btn metis-builder-page-settings-return" data-panel-target="page">&larr; ' + esc(contentSettingsLabel()) + '</button>' +
                                    '<div class="metis-se-section-switch"><button id="metis-v2-section-prev" type="button" class="metis-se-nav-btn">&larr;</button><span id="metis-v2-section-label" class="metis-se-active-section-label"></span><button id="metis-v2-section-next" type="button" class="metis-se-nav-btn">&rarr;</button></div>' +
                                    '<div id="metis-v2-section-settings"></div>' +
	                                    '<div id="metis-v2-section-content"></div>' +
	                                '</div>' +
	                            '</aside>' +
	                        '</section>' +
	                    '</div>' +
	                    '<aside id="metis-builder-block-overlay" class="metis-builder-left-panel" hidden aria-hidden="true" aria-label="Add blocks"><div class="metis-builder-drawer-head"><div><strong>Add Block</strong><span id="metis-builder-block-insert-target">Choose where to insert.</span></div><button type="button" class="metis-modal-close" data-block-inserter-close="1" aria-label="Close">&times;</button></div><div class="metis-builder-block-overlay-scroll"><div class="metis-content-panel-title">Blocks</div><div id="metis-v2-block-library" class="metis-content-library-list"></div><div class="metis-content-panel-title">Reusable</div><div id="metis-v2-reusable-blocks" class="metis-content-library-list"></div></div></aside>' +
	                    '<aside id="metis-v2-revision-drawer" class="metis-builder-drawer" hidden aria-label="Revisions"><div class="metis-builder-drawer-head"><div><strong>Revisions</strong><span>Compare or restore a saved version.</span></div><button type="button" class="metis-modal-close" data-drawer-close="revisions" aria-label="Close">&times;</button></div><div id="metis-v2-revision-compare" class="metis-content-revision-compare" hidden></div><div id="metis-v2-revisions" class="metis-content-revisions"></div></aside>' +
                    '<aside id="metis-v2-preview-drawer" class="metis-builder-drawer metis-builder-preview-drawer" hidden aria-label="Preview"><div class="metis-builder-drawer-head"><div><strong>Preview</strong><span>Rendered from current blocks.</span></div><button type="button" class="metis-modal-close" data-drawer-close="preview" aria-label="Close">&times;</button></div><div id="metis-v2-preview" class="metis-se-preview"></div></aside>' +
                    '<div id="metis-v2-confirm-modal" class="metis-modal-overlay metis-v2-confirm-modal" style="display:none;" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="metis-v2-confirm-title" aria-describedby="metis-v2-confirm-message"><div class="metis-modal metis-v2-confirm-modal__dialog"><div class="metis-modal-header"><h2 id="metis-v2-confirm-title" class="metis-modal-title">Confirm action</h2><button type="button" class="metis-modal-close" data-confirm-cancel="1" aria-label="Close">&times;</button></div><div class="metis-modal-body"><p id="metis-v2-confirm-message" class="metis-v2-confirm-modal__message">Continue?</p></div><div class="metis-modal-footer"><button type="button" class="metis-btn" id="metis-v2-confirm-cancel" data-confirm-cancel="1">Cancel</button><button type="button" class="metis-btn metis-btn-primary" id="metis-v2-confirm-accept" data-confirm-accept="1">Continue</button></div></div></div>' +
                    (isPostContext() ? '<div id="metis-v2-featured-image-modal" class="metis-modal-overlay metis-featured-image-modal" style="display:none;" role="dialog" aria-modal="true" aria-hidden="true" aria-label="Featured Image Picker"><div class="metis-modal metis-featured-image-modal__dialog"><div class="metis-modal-header"><div><h2 class="metis-modal-title">Choose Featured Image</h2><div id="metis-v2-featured-image-count" class="metis-featured-image-modal__count">Loading images...</div></div><button type="button" class="metis-modal-close" id="metis-v2-featured-image-close" aria-label="Close">&times;</button></div><div class="metis-modal-body"><div class="metis-featured-image-modal__toolbar"><input id="metis-v2-featured-image-search" class="metis-se-input" type="search" placeholder="Search images by name or type"><select id="metis-v2-featured-image-mime" class="metis-se-select"><option value=\"\">All image types</option></select></div>' + editorMediaUploadPanelHtml('featured') + '<div id="metis-v2-featured-image-list" class="metis-media-grid metis-featured-image-modal__grid"></div></div><div class="metis-modal-footer"><button type="button" class="metis-btn" id="metis-v2-featured-image-cancel">Close</button></div></div></div>' : '') +
                    '<div id="metis-v2-inline-image-modal" class="metis-modal-overlay metis-featured-image-modal" style="display:none;" role="dialog" aria-modal="true" aria-hidden="true" aria-label="Inline Image Picker"><div class="metis-modal metis-featured-image-modal__dialog"><div class="metis-modal-header"><div><h2 class="metis-modal-title">Insert Image</h2><div id="metis-v2-inline-image-count" class="metis-featured-image-modal__count">Loading images...</div></div><button type="button" class="metis-modal-close" id="metis-v2-inline-image-close" aria-label="Close">&times;</button></div><div class="metis-modal-body"><div class="metis-featured-image-modal__toolbar"><input id="metis-v2-inline-image-search" class="metis-se-input" type="search" placeholder="Search images by name or type"><select id="metis-v2-inline-image-mime" class="metis-se-select"><option value=\"\">All image types</option></select></div>' + editorMediaUploadPanelHtml('inline') + '<div id="metis-v2-inline-image-list" class="metis-media-grid metis-featured-image-modal__grid"></div></div><div class="metis-modal-footer"><button type="button" class="metis-btn" id="metis-v2-inline-image-cancel">Close</button></div></div></div>' +
                '</div>';
        }

        function syncStepUi() {
            state.step = 1;
            root.querySelectorAll('[data-v2-step]').forEach(function (stage) {
                var idx = parseInt(s(stage.getAttribute('data-v2-step') || '1'), 10) || 1;
                var active = idx === state.step;
                stage.hidden = !active;
                stage.style.display = active ? (stage.classList.contains('metis-builder-stage') ? 'grid' : 'flex') : 'none';
            });
            if (isPageContext()) renderHeroEditor();
            renderSectionList();
            var previewDrawer = document.getElementById('metis-v2-preview-drawer');
            if (previewDrawer && !previewDrawer.hidden) updatePreview();
        }

        function applyInputsFromEntity(entity) {
            var data = entity && typeof entity === 'object' ? entity : {};
            var titleEl = document.getElementById('metis-v2-title');
            var statusEl = document.getElementById('metis-v2-status');
            var slugEl = document.getElementById('metis-v2-slug');
            var homeEl = document.getElementById('metis-v2-homepage');
            var parentEl = document.getElementById('metis-v2-parent-id');
            var featuredImageEl = document.getElementById('metis-v2-featured-image-id');
            var featuredImageCaptionEl = document.getElementById('metis-v2-featured-image-caption');
            var excerptEl = document.getElementById('metis-v2-excerpt');
            var authorEl = document.getElementById('metis-v2-author-name');
            var lastEditEl = document.getElementById('metis-v2-last-edit');
            var lastEditRow = document.getElementById('metis-v2-last-edit-row');
            var publishedEl = document.getElementById('metis-v2-published-date');
            if (titleEl) titleEl.value = s(data.title || state.key || '');
            updateTopTitle(s(data.title || state.key || ''));
            if (statusEl) statusEl.value = s(data.status || 'draft') || 'draft';
            if (slugEl) slugEl.value = s(data.slug || slugifyValue(s(data.title || state.key || '')));
            if (homeEl) homeEl.checked = !!data.is_homepage || s(data.page_type || '') === 'homepage';
            state.selectedTemplateKey = normalizeSelectedTemplateKey(
                s(data.template_key || state.selectedTemplateKey || ''),
                currentPageType()
            );
            syncTemplateField();
            if (parentEl) parentEl.value = s(data.parent_page_id || data.parent_id || '');
            setSelectedCategoryIds('metis-v2-category-ids', data.post_category_ids || data.category_ids || (data.post_category_id ? [data.post_category_id] : []));
            if (featuredImageEl) featuredImageEl.value = s(data.featured_image_id || '');
            if (featuredImageCaptionEl) featuredImageCaptionEl.value = s(data.featured_image_caption || '');
            if (excerptEl) excerptEl.value = s(data.excerpt || '');
            if (authorEl) authorEl.textContent = s(data.author_name || '') || '—';
            if (lastEditEl) lastEditEl.textContent = formatLastEditValue(s(data.last_edit || data.updated_at || ''));
            if (lastEditRow) lastEditRow.style.display = (state.id > 0 || state.key) ? '' : 'none';
            if (publishedEl) publishedEl.value = toDateTimeInputValue(s(data.published_date || data.published_at || data.publish_date || ''));
            if (isPostContext()) renderFeaturedImageField();
            renderPostPathPreview();
            syncPublishedDateField();
            if (isPageContext()) {
                state.hero = normalizeHeroState(state.hero, currentPageType() === 'homepage');
                renderHeroEditor();
            }
            syncBuilderChrome();
        }

        function applyOptions(resp) {
            state.options.parentPages = Array.isArray(resp.parent_pages) ? resp.parent_pages : [];
            state.options.authors = Array.isArray(resp.authors) ? resp.authors : [];
            state.options.categories = Array.isArray(resp.categories) ? resp.categories : [];
            state.options.forms = Array.isArray(resp.forms) ? resp.forms : [];
            state.options.donationCampaigns = Array.isArray(resp.donation_campaigns) ? resp.donation_campaigns : [];
            state.options.calendarSources = Array.isArray(resp.calendar_sources) ? resp.calendar_sources : [];
            state.options.media = Array.isArray(resp.media) ? resp.media : [];
            state.options.templates = Array.isArray(resp.templates) ? resp.templates : [];
            state.options.activeTemplate = resp.active_template && typeof resp.active_template === 'object'
                ? { key: s(resp.active_template.key || ''), label: s(resp.active_template.label || '') }
                : { key: '', label: '' };
            state.options.defaultTemplateKey = s(resp.default_template_key || '');
            var parentEl = document.getElementById('metis-v2-parent-id');
            if (parentEl) {
                var pHtml = '<option value="">None</option>';
                state.options.parentPages.forEach(function (row) {
                    pHtml += '<option value="' + esc(s(row.value || '')) + '">' + esc(s(row.label || row.value || '')) + '</option>';
                });
                parentEl.innerHTML = pHtml;
            }
            var categoryHost = document.getElementById('metis-v2-category-chip-host');
            if (categoryHost) {
                categoryHost.innerHTML = categoryChipField('metis-v2-category-ids', state.entity && (state.entity.post_category_ids || state.entity.category_ids || []), 'No categories available.');
            }
            state.selectedTemplateKey = normalizeSelectedTemplateKey(state.selectedTemplateKey, currentPageType());
            syncTemplateField();
            applyInputsFromEntity(state.entity || {});
            if (isPostContext()) renderFeaturedImageField();
            renderPostPathPreview();
            syncPublishedDateField();
        }

        function loadEntityAndOptions() {
            setEditorLoading(true, 'Loading content and settings...');
            var pEntity;
            if (state.entityLoaded && state.entity && typeof state.entity === 'object') {
                pEntity = Promise.resolve(state.entity);
            } else if (isNewEntityRoute()) {
                state.entity = {};
                state.entityLoaded = true;
                pEntity = Promise.resolve({});
            } else {
                var action = isPostContext() ? contentAction('post_get') : contentAction('page_get');
                var payload = {};
                if (/^\d+$/.test(state.key)) payload.id = parseInt(state.key, 10);
                else if (state.key) payload.key = state.key;
                else if (state.id > 0) payload.id = state.id;
                pEntity = request(action, payload).then(function (resp) {
                    var entity = isPostContext() ? (resp.post || {}) : (resp.page || {});
                    state.entity = entity;
                    state.entityLoaded = true;
                    state.id = parseInt(s(entity.id || state.id || '0'), 10) || state.id;
                    state.key = isPostContext() ? s(entity.post_code || state.key) : s(entity.page_code || state.key);
                    return entity;
                });
            }
            return pEntity.then(function (entity) {
                var raw = isPostContext() ? s(entity.draft_content_json || entity.content_json || '') : s(entity.draft_layout_json || entity.layout_json || '');
                var layoutModel = layoutModelFromLayout(raw, isPostContext());
                state.sections = normalizeSections(layoutModel.sections, isPostContext());
                state.hero = normalizeHeroState(layoutModel.hero, layoutModel.page_type === 'homepage' && isPageContext());
                state.selectedTemplateKey = normalizeSelectedTemplateKey(
                    s(entity.template_key || layoutModel.template_key || ''),
                    layoutModel.page_type
                );
                state.activeSection = -1;
                return request(contentAction('editor_properties_options'), {
                    context: isPostContext() ? 'post' : 'page',
                    id: state.id || 0,
                    key: state.key || ''
                }).then(function (optionsResp) {
                    applyOptions(optionsResp || {});
                    applyInputsFromEntity(entity || {});
                    renderSectionList();
                    syncStepUi();
                    checkRecoveryDraft();
                    loadRevisionList();
                    loadReusableBlocks();
                    setStatus('Loaded', 'ok');
                    setEditorLoading(false);
                }).catch(function () {
                    applyInputsFromEntity(entity || {});
                    renderSectionList();
                    syncStepUi();
                    checkRecoveryDraft();
                    loadRevisionList();
                    loadReusableBlocks();
                    setEditorLoading(false);
                });
            }).catch(function (err) {
                setStatus('Load failed: ' + s(err && err.message || 'Request failed.'), 'error');
                setEditorLoading(false, 'Editor data failed to load.');
            });
        }

        function wire() {
            var prev = document.getElementById('metis-v2-prev');
            var next = document.getElementById('metis-v2-next');
            if (prev) prev.addEventListener('click', function () { state.step -= 1; syncStepUi(); });
            if (next) next.addEventListener('click', function () {
                if (state.step === maxSteps()) { saveEntity(false); return; }
                state.step += 1;
                syncStepUi();
            });
            root.addEventListener('pointerdown', function (e) {
                var richControl = e.target && e.target.closest ? e.target.closest('button[data-rich-cmd], [data-rich-action], button[data-rich-toggle="menu"]') : null;
                if (!richControl) return;
                var richTarget = richEditorForControl(richControl);
                if (!richTarget) return;
                e.preventDefault();
                saveRichSelection(richTarget);
                state.activeRichTargetId = richTarget.id || state.activeRichTargetId;
                retargetContextToolbar(state.activeRichTargetId);
            }, true);
            root.addEventListener('click', function (e) {
                var publishBtn = e.target.closest('#metis-v2-publish');
                if (publishBtn) {
                    var publishStatusEl = document.getElementById('metis-v2-status');
                    if (state.canPublish && publishStatusEl && s(publishStatusEl.value || '') !== 'scheduled') {
                        publishStatusEl.value = 'published';
                        syncBuilderChrome();
                    }
                    saveEntity(false);
                    return;
                }
                var previewToggle = e.target.closest('[data-preview-toggle]');
                if (previewToggle) {
                    var previewDrawer = document.getElementById('metis-v2-preview-drawer');
                    if (previewDrawer) {
                        previewDrawer.hidden = false;
                        updatePreview();
                    }
                    return;
                }
                var revisionsToggle = e.target.closest('[data-revisions-toggle]');
                if (revisionsToggle) {
                    var revisionDrawer = document.getElementById('metis-v2-revision-drawer');
                    if (revisionDrawer) {
                        revisionDrawer.hidden = false;
                        loadRevisionList();
                    }
                    return;
                }
                var drawerClose = e.target.closest('[data-drawer-close]');
                if (drawerClose) {
                    var drawerKind = s(drawerClose.getAttribute('data-drawer-close') || '');
                    var drawer = drawerKind === 'preview' ? document.getElementById('metis-v2-preview-drawer') : document.getElementById('metis-v2-revision-drawer');
                    if (drawer) drawer.hidden = true;
                    return;
                }
                var builderLink = e.target.closest('[data-builder-link]');
                if (builderLink) {
                    e.preventDefault();
                    if (!builderLink.isContentEditable) return;
                }
                var pagePanel = e.target.closest('[data-panel-target="page"]');
                if (pagePanel) {
                    state.activeSection = -1;
                    renderSectionList();
                    return;
                }
                var outlineItem = e.target.closest('[data-outline-index]');
                if (outlineItem) {
                    state.activeSection = parseInt(s(outlineItem.getAttribute('data-outline-index') || '-1'), 10);
                    renderSectionList();
                    return;
                }
                var insertToggle = e.target.closest('[data-builder-insert-toggle]');
                if (insertToggle) {
                    var pickerIndex = parseInt(s(insertToggle.getAttribute('data-builder-insert-toggle') || '0'), 10) || 0;
                    openBlockInserter(pickerIndex);
                    return;
                }
                var blockInserterClose = e.target.closest('[data-block-inserter-close]');
                if (blockInserterClose) {
                    closeBlockInserter();
                    return;
                }
                var addBlockType = e.target.closest('[data-add-block-type]');
                if (addBlockType) {
                    var requestedIndex = s(addBlockType.getAttribute('data-insert-index') || '');
                    if (requestedIndex === '' && state.blockPickerIndex !== null && state.blockPickerIndex !== undefined) {
                        requestedIndex = String(state.blockPickerIndex);
                    }
                    state.blockPickerIndex = null;
                    insertSection(
                        s(addBlockType.getAttribute('data-add-block-type') || 'text'),
                        requestedIndex !== '' ? (parseInt(requestedIndex, 10) || 0) : undefined
                    );
                    closeBlockInserter();
                    return;
                }
                var recoverDraft = e.target.closest('#metis-v2-recover-draft');
                if (recoverDraft) {
                    restoreRecoveryDraft();
                    return;
                }
                var discardRecovery = e.target.closest('#metis-v2-discard-recovery');
                if (discardRecovery) {
                    clearRecoveryDraft();
                    var recoveryHost = document.getElementById('metis-v2-recovery');
                    if (recoveryHost) recoveryHost.innerHTML = '';
                    return;
                }
                var clearRevisionCompare = e.target.closest('[data-clear-revision-compare]');
                if (clearRevisionCompare) {
                    renderRevisionCompare(null);
                    return;
                }
                var compareRevision = e.target.closest('[data-compare-revision]');
                if (compareRevision) {
                    var compareRevisionId = parseInt(s(compareRevision.getAttribute('data-compare-revision') || '0'), 10) || 0;
                    if (compareRevisionId > 0) loadRevisionCompare(compareRevisionId);
                    return;
                }
                var restoreRevision = e.target.closest('[data-restore-revision]');
                if (restoreRevision) {
                    var revisionId = parseInt(s(restoreRevision.getAttribute('data-restore-revision') || '0'), 10) || 0;
                    if (revisionId > 0) {
                        requestEditorConfirmation({
                            title: 'Restore revision',
                            message: 'Restore this revision into the current draft? Published content will not change until you publish or update.',
                            confirmLabel: 'Restore'
                        }).then(function (confirmed) {
                            if (!confirmed) return;
                            request(contentAction('editor_revision_restore'), {
                                context: isPostContext() ? 'post' : 'page',
                                id: state.id || 0,
                                key: state.key || '',
                                revision_id: revisionId
                            }).then(function (resp) {
                                var entity = isPageContext() ? (resp.page || {}) : (resp.post || {});
                                state.entity = entity;
                                var raw = isPostContext() ? s(entity.draft_content_json || entity.content_json || '') : s(entity.draft_layout_json || entity.layout_json || '');
                                var layoutModel = layoutModelFromLayout(raw, isPostContext());
                                state.sections = normalizeSections(layoutModel.sections, isPostContext());
                                state.hero = normalizeHeroState(layoutModel.hero, layoutModel.page_type === 'homepage' && isPageContext());
                                state.selectedTemplateKey = normalizeSelectedTemplateKey(
                                    s(entity.template_key || layoutModel.template_key || ''),
                                    layoutModel.page_type
                                );
                                applyInputsFromEntity(entity);
                                renderSectionList();
                                syncStepUi();
                                setStatus('Revision restored', 'ok');
                            }).catch(function (err) {
                                setStatus('Restore failed: ' + s(err && err.message || 'Request failed.'), 'error');
                            });
                        });
                    }
                    return;
                }
                var insertReusable = e.target.closest('[data-insert-reusable]');
                if (insertReusable) {
                    var code = s(insertReusable.getAttribute('data-insert-reusable') || '');
                    if (code) {
                        request(contentAction('reusable_block_get'), { block_code: code }).then(function (resp) {
                            var item = resp.item || {};
                            var block = item.block && typeof item.block === 'object' ? item.block : {};
                            var sec = defaultSectionByType(sectionTypeFromReusableBlockType(block.type || 'text'));
                            if (block.data && typeof block.data === 'object') {
                                if (sec.type === 'heading') sec.content = {
                                    text: s(block.data.content || block.data.text || 'Heading'),
                                    level: s(block.data.level || 'h2'),
                                    align: ['left', 'center', 'right'].indexOf(s(block.data.align || 'left')) === -1 ? 'left' : s(block.data.align || 'left'),
                                    vertical_align: ['top', 'middle', 'bottom'].indexOf(s(block.data.vertical_align || 'top')) === -1 ? 'top' : s(block.data.vertical_align || 'top'),
                                    variant: s(block.data.variant || '') === 'section_header' ? 'section_header' : 'default'
                                };
                                else if (sec.type === 'text' || sec.type === 'html') sec.content = sec.type === 'html' ? { html: s(block.data.content || block.data.html || '') } : { body: s(block.data.content || block.data.body || '<p></p>') };
                                else sec.content = Object.assign({}, sec.content, block.data);
                            }
                            var insertAt = state.blockPickerIndex !== null && state.blockPickerIndex !== undefined
                                ? Math.max(0, Math.min(state.sections.length, parseInt(s(state.blockPickerIndex || '0'), 10) || 0))
                                : (state.activeSection >= 0 ? state.activeSection + 1 : state.sections.length);
                            state.sections.splice(insertAt, 0, sec);
                            state.activeSection = insertAt;
                            state.blockPickerIndex = null;
                            renderSectionList();
                            syncStepUi();
                            closeBlockInserter();
                            setDirtyAutosave();
                        }).catch(function (err) {
                            setStatus('Reusable block failed: ' + s(err && err.message || 'Request failed.'), 'error');
                        });
                    }
                    return;
                }
                var saveReusable = e.target.closest('[data-save-reusable]');
                if (saveReusable) {
                    var reusableSection = activeSection();
                    var name = sectionSummary(reusableSection);
                    var block = sectionToReusableBlock(reusableSection);
                    request(contentAction('reusable_block_save'), {
                        context: isPostContext() ? 'post' : 'website',
                        name: name,
                        category: 'custom',
                        block_json: JSON.stringify(block)
                    }).then(function () {
                        setStatus('Reusable block saved', 'ok');
                        loadReusableBlocks();
                    }).catch(function (err) {
                        setStatus('Reusable save failed: ' + s(err && err.message || 'Request failed.'), 'error');
                    });
                    return;
                }
                var addSectionBtn = e.target.closest('#metis-v2-add-section');
                if (addSectionBtn) {
                    var typeSel = document.getElementById('metis-v2-new-section-type');
                    state.sections.push(defaultSectionByType(s(typeSel && typeSel.value || 'text')));
                    state.activeSection = state.sections.length - 1;
                    renderSectionList();
                    syncStepUi();
                    setDirtyAutosave();
                    return;
                }
                var categoryChip = e.target.closest('[data-category-chip-field][data-category-chip-id]');
                if (categoryChip) {
                    var fieldId = s(categoryChip.getAttribute('data-category-chip-field') || '');
                    var categoryId = parseInt(s(categoryChip.getAttribute('data-category-chip-id') || '0'), 10) || 0;
                    if (fieldId && categoryId > 0) {
                        var selectedIds = selectedCategoryIds(fieldId);
                        var exists = selectedIds.indexOf(categoryId) !== -1;
                        if (exists) selectedIds = selectedIds.filter(function (id) { return id !== categoryId; });
                        else selectedIds.push(categoryId);
                        setSelectedCategoryIds(fieldId, selectedIds);
                        if (fieldId === 'metis-v2-posts-category-ids') {
                            activeSection().content.category_ids = selectedIds;
                        }
                        setDirtyAutosave();
                    }
                    return;
                }
                var canvasBlock = e.target.closest('[data-builder-block-index]');
                var richToolbarTarget = e.target.closest('button[data-rich-cmd], [data-rich-action], button[data-rich-toggle="menu"], .metis-se-rich-dropdown, .metis-se-rich-toolbar, .metis-builder-context-toolbar');
                if (canvasBlock && !richToolbarTarget && !e.target.closest('[data-v2-list-act]') && !e.target.closest('[data-save-reusable]')) {
                    var editingInline = !!e.target.closest('[contenteditable="true"]');
                    state.activeSection = parseInt(s(canvasBlock.getAttribute('data-builder-block-index') || '-1'), 10);
                    renderBuilderOutline();
                    renderBuilderSettings();
                    if (editingInline) {
                        root.querySelectorAll('.metis-builder-block.is-selected').forEach(function (node) {
                            node.classList.remove('is-selected');
                        });
                        canvasBlock.classList.add('is-selected');
                    } else {
                        renderBuilderCanvas();
                    }
                    return;
                }
                var rowBtn = e.target.closest('[data-v2-list-act]');
                if (rowBtn) {
                    var row = rowBtn.closest('[data-index]');
                    var idx = parseInt(s(row && row.getAttribute('data-index') || '-1'), 10);
                    if (idx < 0 || idx >= state.sections.length) return;
                    var act = s(rowBtn.getAttribute('data-v2-list-act') || '');
                    if (act === 'edit') { state.activeSection = idx; syncStepUi(); return; }
                    if (act === 'up' && idx > 0) { var a = state.sections[idx - 1]; state.sections[idx - 1] = state.sections[idx]; state.sections[idx] = a; if (state.activeSection === idx) state.activeSection = idx - 1; }
                    if (act === 'down' && idx < state.sections.length - 1) { var b = state.sections[idx + 1]; state.sections[idx + 1] = state.sections[idx]; state.sections[idx] = b; if (state.activeSection === idx) state.activeSection = idx + 1; }
                    if (act === 'duplicate') { var copy = JSON.parse(JSON.stringify(state.sections[idx])); copy.id = uid(); copy.metadata = { created_at: (new Date()).toISOString() }; state.sections.splice(idx + 1, 0, copy); state.activeSection = idx + 1; }
                    if (act === 'delete') { state.sections.splice(idx, 1); if (!state.sections.length) state.activeSection = -1; else if (state.activeSection >= state.sections.length) state.activeSection = state.sections.length - 1; }
                    renderSectionList();
                    syncStepUi();
                    setDirtyAutosave();
                    return;
                }
                var prevSection = e.target.closest('#metis-v2-section-prev');
                var nextSection = e.target.closest('#metis-v2-section-next');
                if (prevSection || nextSection) {
                    if (!state.sections.length) return;
                    if (prevSection) state.activeSection = (state.activeSection - 1 + state.sections.length) % state.sections.length;
                    if (nextSection) state.activeSection = (state.activeSection + 1) % state.sections.length;
                    syncStepUi();
                    return;
                }
                var uploadMediaBtn = e.target.closest('[data-editor-media-upload-btn]');
                if (uploadMediaBtn) {
                    var uploadContext = s(uploadMediaBtn.getAttribute('data-editor-media-upload-btn') || 'inline');
                    var uploadInput = root.querySelector('[data-editor-media-upload-input="' + uploadContext + '"]');
                    if (uploadInput && !uploadInput.disabled) uploadInput.click();
                    return;
                }
                var openFeatured = e.target.closest('#metis-v2-featured-image-open');
                if (openFeatured) {
                    openFeaturedImageModal();
                    return;
                }
                var clearFeatured = e.target.closest('#metis-v2-featured-image-clear');
                if (clearFeatured) {
                    var hiddenField = document.getElementById('metis-v2-featured-image-id');
                    if (hiddenField) hiddenField.value = '';
                    renderFeaturedImageField();
                    setDirtyAutosave();
                    return;
                }
                var closeFeatured = e.target.closest('#metis-v2-featured-image-close, #metis-v2-featured-image-cancel');
                if (closeFeatured) {
                    closeFeaturedImageModal();
                    return;
                }
                if (e.target && e.target.id === 'metis-v2-featured-image-modal') {
                    closeFeaturedImageModal();
                    return;
                }
                var closeInline = e.target.closest('#metis-v2-inline-image-close, #metis-v2-inline-image-cancel');
                if (closeInline) {
                    closeInlineImageModal();
                    return;
                }
                if (e.target && e.target.id === 'metis-v2-inline-image-modal') {
                    closeInlineImageModal();
                    return;
                }
                var openBlockMedia = e.target.closest('[data-open-block-media]');
                if (openBlockMedia) {
                    var mediaField = s(openBlockMedia.getAttribute('data-open-block-media') || 'src');
                    if (state.activeSection >= 0) openBlockImagePicker(state.activeSection, mediaField);
                    return;
                }
                var pickInline = e.target.closest('[data-inline-image-select]');
                if (pickInline) {
                    var inlineMediaId = parseInt(s(pickInline.getAttribute('data-inline-image-select') || '0'), 10) || 0;
                    var inlineRow = featuredImageMediaById(inlineMediaId);
                    var blockTarget = blockImageTargetFromId(state.inlineImageTargetId);
                    if (blockTarget && inlineRow && state.sections[blockTarget.index]) {
                        var imageSection = state.sections[blockTarget.index];
                        imageSection.content = imageSection.content && typeof imageSection.content === 'object' ? imageSection.content : {};
                        imageSection.content[blockTarget.field] = s(inlineRow.url || '');
                        if (imageSection.type === 'image' && blockTarget.field === 'src' && !s(imageSection.content.alt || '')) {
                            imageSection.content.alt = s(inlineRow.label || 'Image');
                        }
                        state.activeSection = blockTarget.index;
                        closeInlineImageModal();
                        renderSectionList();
                        setDirtyAutosave();
                        return;
                    }
                    var inlineTarget = state.inlineImageTargetId ? document.getElementById(state.inlineImageTargetId) : null;
                    requestInlineImageSize().then(function(sizeChoice) {
                        if (inlineRow && inlineTarget && sizeChoice) {
                            insertHtmlAtSelection(inlineTarget, '<figure class="metis-inline-image is-' + esc(sizeChoice) + '"><img src="' + esc(s(inlineRow.url || '')) + '" alt="' + esc(s(inlineRow.label || 'Inline image')) + '"></figure>');
                            setDirtyAutosave();
                        }
                        closeInlineImageModal();
                    });
                    return;
                }
                var pickFeatured = e.target.closest('[data-featured-image-select]');
                if (pickFeatured) {
                    var mediaId = parseInt(s(pickFeatured.getAttribute('data-featured-image-select') || '0'), 10) || 0;
                    var hiddenEl = document.getElementById('metis-v2-featured-image-id');
                    if (hiddenEl) hiddenEl.value = mediaId > 0 ? String(mediaId) : '';
                    closeFeaturedImageModal();
                    renderFeaturedImageField();
                    setDirtyAutosave();
                    return;
                }
                var addItem = e.target.closest('[data-v2-add-item=\"feature_grid\"]');
                if (addItem) {
                    var sec = activeSection();
                    sec.content.items.push({ icon: '', title: '', text: '', cta: { label: '', url: '#' } });
                    renderStep2Editor();
                    setDirtyAutosave();
                    return;
                }
                var removeItem = e.target.closest('[data-v2-remove-item=\"feature_grid\"]');
                if (removeItem) {
                    var remIdx = parseInt(s(removeItem.getAttribute('data-item-idx') || '-1'), 10);
                    var secR = activeSection();
                    if (Array.isArray(secR.content.items) && remIdx >= 0 && remIdx < secR.content.items.length) secR.content.items.splice(remIdx, 1);
                    if (!secR.content.items.length) secR.content.items = [{ icon: '', title: '', text: '', cta: { label: '', url: '#' } }];
                    renderStep2Editor();
                    setDirtyAutosave();
                    return;
                }
                var richBtn = e.target.closest('button[data-rich-cmd]');
                var richToggle = e.target.closest('button[data-rich-toggle="menu"]');
                var richAction = e.target.closest('[data-rich-action]');
                if (!richToggle && !richAction && !e.target.closest('.metis-se-rich-dropdown')) {
                    root.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function (node) {
                        node.classList.remove('is-open');
                    });
                }
                if (richToggle) {
                    e.preventDefault();
                    var dropdown = richToggle.closest('.metis-se-rich-dropdown');
                    root.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function (node) {
                        if (node !== dropdown) node.classList.remove('is-open');
                    });
                    if (dropdown) dropdown.classList.toggle('is-open');
                    return;
                }
                if (richAction) {
                    var action = s(richAction.getAttribute('data-rich-action') || '');
                    if (action === 'color-picker') {
                        return;
                    }
                    e.preventDefault();
                    var actionTargetId = s(richAction.getAttribute('data-rich-target') || '');
                    var actionValue = s(richAction.getAttribute('data-rich-value') || '');
                    var actionTarget = (actionTargetId ? document.getElementById(actionTargetId) : null) || richEditorForControl(richAction);
                    if (actionTarget) {
                        actionTarget.focus();
                        restoreRichSelection(actionTarget);
                        if (action === 'block') document.execCommand('formatBlock', false, actionValue || 'P');
                        else if (action === 'size') applySpanPreset(actionTarget, 'metis-text-size-', actionValue || 'default');
                        else if (action === 'color') applySpanPreset(actionTarget, 'metis-text-color-', actionValue || 'default');
                        else if (action === 'color-picker') applyTextColor(actionTarget, richAction.value || actionValue);
                        else if (action === 'weight') applySpanPreset(actionTarget, 'metis-text-weight-', actionValue || '700');
                        saveRichSelection(actionTarget);
                        syncRichEditorToSection(actionTarget);
                        setDirtyAutosave();
                    }
                    root.querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function (node) {
                        node.classList.remove('is-open');
                    });
                    return;
                }
                if (richBtn) {
                    e.preventDefault();
                    var cmd = s(richBtn.getAttribute('data-rich-cmd') || '');
                    var cmdVal = s(richBtn.getAttribute('data-rich-val') || '');
                    var targetId = s(richBtn.getAttribute('data-rich-target') || '');
                    var target = (targetId ? document.getElementById(targetId) : null) || richEditorForControl(richBtn);
                    if (target) {
                        target.focus();
                        restoreRichSelection(target);
                    }
                    if (cmd === 'createLink') {
                        if (target) saveRichSelection(target);
                        requestLinkUrl().then(function(url) {
                            if (url && target) {
                                applyLinkAtSelection(target, url);
                            }
                            if (target) {
                                saveRichSelection(target);
                                syncRichEditorToSection(target);
                            }
                            setDirtyAutosave();
                        });
                        return;
                    } else if (cmd === 'insertImagePrompt') {
                        if (target) {
                            saveRichSelection(target);
                            openInlineImageModal(target.id);
                        }
                    } else if (cmd === 'insertEmojiPrompt') {
                        requestEmoji().then(function(emojiValue) {
                            if (emojiValue && target) {
                                target.focus();
                                restoreRichSelection(target);
                                document.execCommand('insertText', false, emojiValue);
                            }
                            if (target) {
                                saveRichSelection(target);
                                syncRichEditorToSection(target);
                            }
                            setDirtyAutosave();
                        });
                        return;
                    } else if (cmd === 'insertDivider') {
                        if (target) {
                            insertHtmlAtSelection(target, '<hr class="metis-inline-divider">');
                        }
                    } else if (cmd === 'formatBlock' && cmdVal) document.execCommand('formatBlock', false, cmdVal);
                    else if (richAlignmentValue(cmd)) applyBlockClassPreset(target, 'metis-text-align-', richAlignmentValue(cmd));
                    else if (cmd) document.execCommand(cmd, false, null);
                    if (target) {
                        saveRichSelection(target);
                        syncRichEditorToSection(target);
                    }
                    setDirtyAutosave();
                    return;
                }
            });
            root.addEventListener('input', function (e) {
                var target = e.target;
                var sec = activeSection();
                if (target && target.matches && target.matches('[data-rich-action="color-picker"]')) {
                    var colorTargetId = s(target.getAttribute('data-rich-target') || '');
                    var colorTarget = (colorTargetId ? document.getElementById(colorTargetId) : null) || richEditorForControl(target);
                    if (colorTarget) {
                        applyTextColor(colorTarget, target.value);
                        syncRichEditorToSection(colorTarget);
                        setDirtyAutosave();
                    }
                    return;
                }
                if (target && target.classList && target.classList.contains('metis-se-rich-editor')) {
                    saveRichSelection(target);
                }
                if (target.id === 'metis-v2-featured-image-search') {
                    renderFeaturedImagePickerList();
                    return;
                }
                if (target.id === 'metis-v2-inline-image-search') {
                    renderInlineImagePickerList();
                    return;
                }
                if (target.id === 'metis-v2-block-search') {
                    state.blockLibrarySearch = s(target.value || '');
                    renderBlockLibrary();
                    var search = document.getElementById('metis-v2-block-search');
                    if (search) {
                        search.focus();
                        try {
                            search.setSelectionRange(search.value.length, search.value.length);
                        } catch (_err) {}
                    }
                    return;
                }
                var inlineBlock = target.closest && target.closest('[data-v2-inline]');
                if (inlineBlock) {
                    var inlineIndex = parseInt(s(inlineBlock.getAttribute('data-inline-index') || '-1'), 10);
                    var inlineField = s(inlineBlock.getAttribute('data-v2-inline') || '');
                    var inlineSection = inlineIndex >= 0 && state.sections[inlineIndex] ? state.sections[inlineIndex] : null;
                    if (!inlineSection) return;
                    inlineSection.content = inlineSection.content && typeof inlineSection.content === 'object' ? inlineSection.content : {};
                    if (inlineField === 'heading_text') inlineSection.content.text = s(inlineBlock.textContent || '');
                    else if (inlineField === 'text_body') inlineSection.content.body = inlineBlock.innerHTML;
                    else if (inlineField === 'image_caption') inlineSection.content.caption = s(inlineBlock.textContent || '');
                    else if (inlineField === 'button_label') inlineSection.content.label = s(inlineBlock.textContent || '');
                    else if (inlineField === 'hero_title') inlineSection.content.title = s(inlineBlock.textContent || '');
                    else if (inlineField === 'hero_subtitle') inlineSection.content.subtitle = s(inlineBlock.textContent || '');
                    else if (inlineField === 'hero_cta_label') inlineSection.content.cta_label = s(inlineBlock.textContent || '');
                    else if (inlineField === 'campaign_title') inlineSection.content.title = s(inlineBlock.textContent || '');
                    else if (inlineField === 'divider_label') inlineSection.content.label = s(inlineBlock.textContent || '');
                    renderBuilderOutline();
                    setDirtyAutosave();
                    return;
                }
                var inlineCol = target.closest && target.closest('[data-v2-inline-col]');
                if (inlineCol) {
                    var blockIndex = parseInt(s(inlineCol.getAttribute('data-inline-index') || '-1'), 10);
                    var colIndex = parseInt(s(inlineCol.getAttribute('data-v2-inline-col') || '-1'), 10);
                    var colSection = blockIndex >= 0 && state.sections[blockIndex] ? state.sections[blockIndex] : null;
                    if (colSection && Array.isArray(colSection.content && colSection.content.columns) && colSection.content.columns[colIndex]) {
                        colSection.content.columns[colIndex].body = inlineCol.innerHTML;
                        setDirtyAutosave();
                    }
                    return;
                }
                var inlineItem = target.closest && target.closest('[data-v2-inline-item]');
                if (inlineItem) {
                    var itemBlockIndex = parseInt(s(inlineItem.getAttribute('data-inline-index') || '-1'), 10);
                    var itemIndex = parseInt(s(inlineItem.getAttribute('data-v2-inline-item') || '-1'), 10);
                    var itemField = s(inlineItem.getAttribute('data-inline-item-field') || '');
                    var itemSection = itemBlockIndex >= 0 && state.sections[itemBlockIndex] ? state.sections[itemBlockIndex] : null;
                    if (itemSection && Array.isArray(itemSection.content && itemSection.content.items) && itemSection.content.items[itemIndex]) {
                        itemSection.content.items[itemIndex][itemField] = s(inlineItem.textContent || '');
                        renderBuilderOutline();
                        setDirtyAutosave();
                    }
                    return;
                }
                if (target.id === 'metis-v2-title') {
                    var slugEl = document.getElementById('metis-v2-slug');
                    if (slugEl && !state.slugManuallyEdited) slugEl.value = slugifyValue(s(target.value || ''));
                    updateTopTitle(s(target.value || ''));
                    renderPostPathPreview();
                    setDirtyAutosave();
                    return;
                }
                if (target.id === 'metis-v2-featured-image-caption') {
                    setDirtyAutosave();
                    return;
                }
                if (target.id === 'metis-v2-slug') {
                    state.slugManuallyEdited = true;
                    renderPostPathPreview();
                    setDirtyAutosave();
                    return;
                }
                if (target.id === 'metis-v2-published-date') {
                    renderPostPathPreview();
                    setDirtyAutosave();
                    return;
                }
                if (target.id === 'metis-v2-hero-headline') { state.hero.headline = s(target.value || '').trim(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-hero-subtext') { state.hero.subtext = s(target.value || '').trim(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-hero-cta-label') { state.hero.primary_cta_label = s(target.value || '').trim(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-hero-cta-link') { state.hero.primary_cta_link = s(target.value || '').trim(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-hero-media-url') { state.hero.media_url = s(target.value || '').trim(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-section-header') { sec.header = s(target.value || '').trim() || null; renderSectionList(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-section-subtext') { sec.subtext = s(target.value || '').trim() || null; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-section-background') { sec.settings = normalizeSectionSettings(Object.assign({}, sec.settings || {}, { background: target.value })); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-heading-text') { sec.content.text = s(target.value || ''); renderSectionList(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-image-src') { sec.content.src = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-image-alt') { sec.content.alt = s(target.value || ''); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-image-caption') { sec.content.caption = s(target.value || ''); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-image-width') { sec.content.width = normalizeImageDimension(target.value || ''); target.value = sec.content.width; renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-image-height') { sec.content.height = normalizeImageDimension(target.value || ''); target.value = sec.content.height; renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-button-label') { sec.content.label = s(target.value || ''); renderSectionList(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-button-url') { sec.content.url = s(target.value || '#') || '#'; renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-block-hero-title') { sec.content.title = s(target.value || ''); renderSectionList(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-block-hero-subtitle') { sec.content.subtitle = s(target.value || ''); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-block-hero-cta-label') { sec.content.cta_label = s(target.value || ''); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-block-hero-cta-url') { sec.content.cta_url = s(target.value || '#') || '#'; renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-block-hero-image') { sec.content.image_src = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-html-code') { sec.content.html = s(target.value || ''); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-rich-text') { sec.content.body = target.innerHTML; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-transcript-source') {
                    sec.content.source = sanitizeTranscriptSource(target.value || '');
                    sec.content.rows = parseTranscriptSource(sec.content.source);
                    setDirtyAutosave();
                    return;
                }
                if (target.id === 'metis-v2-columns-count') {
                    var count = Math.max(1, Math.min(4, parseInt(s(target.value || '2'), 10) || 2));
                    var widths = ['100%', '50%', '33%', '25%'];
                    var cols = Array.isArray(sec.content.columns) ? sec.content.columns : [];
                    while (cols.length < count) cols.push({ width: widths[count - 1], body: '<p></p>' });
                    if (cols.length > count) cols = cols.slice(0, count);
                    cols = cols.map(function (col) { return { width: widths[count - 1], body: s(col.body || '<p></p>') || '<p></p>' }; });
                    sec.content.columns = cols;
                    renderStep2Editor();
                    setDirtyAutosave();
                    return;
                }
                if (target.matches('[data-v2-rich=\"col_body\"]')) {
                    var colIdx = parseInt(s(target.getAttribute('data-col-idx') || '-1'), 10);
                    if (colIdx >= 0 && Array.isArray(sec.content.columns) && sec.content.columns[colIdx]) sec.content.columns[colIdx].body = target.innerHTML;
                    setDirtyAutosave();
                    return;
                }
                if (target.id === 'metis-v2-feature-cols') {
                    var fgCols = Math.max(2, Math.min(4, parseInt(s(target.value || '3'), 10) || 3));
                    sec.content.columns = fgCols;
                    setDirtyAutosave();
                    return;
                }
                if (target.matches('[data-v2-item=\"feature_grid\"]')) {
                    var i = parseInt(s(target.getAttribute('data-item-idx') || '-1'), 10);
                    var field = s(target.getAttribute('data-item-field') || '');
                    if (Array.isArray(sec.content.items) && i >= 0 && i < sec.content.items.length) {
                        var row = sec.content.items[i];
                        row.cta = row.cta && typeof row.cta === 'object' ? row.cta : { label: '', url: '#' };
                        if (field === 'cta_label') row.cta.label = s(target.value || '');
                        else if (field === 'cta_url') row.cta.url = s(target.value || '') || '#';
                        else row[field] = s(target.value || '');
                        setDirtyAutosave();
                    }
                    return;
                }
                if (target.id === 'metis-v2-cta-layout') {
                    sec.content.layout = s(target.value || 'single') === 'split' ? 'split' : 'single';
                    sec.content.items = Array.isArray(sec.content.items) ? sec.content.items : [{ title: '', text: '', button: { label: '', url: '#' } }];
                    if (sec.content.layout === 'single') sec.content.items = [sec.content.items[0] || { title: '', text: '', button: { label: '', url: '#' } }];
                    if (sec.content.layout === 'split' && sec.content.items.length < 2) sec.content.items.push({ title: '', text: '', button: { label: '', url: '#' } });
                    renderStep2Editor();
                    setDirtyAutosave();
                    return;
                }
                if (target.matches('[data-v2-item=\"cta\"]')) {
                    var cidx = parseInt(s(target.getAttribute('data-item-idx') || '-1'), 10);
                    var cfield = s(target.getAttribute('data-item-field') || '');
                    if (Array.isArray(sec.content.items) && cidx >= 0 && cidx < sec.content.items.length) {
                        var cRow = sec.content.items[cidx];
                        cRow.button = cRow.button && typeof cRow.button === 'object' ? cRow.button : { label: '', url: '#' };
                        if (cfield === 'button_label') cRow.button.label = s(target.value || '');
                        else if (cfield === 'button_url') cRow.button.url = s(target.value || '') || '#';
                        else cRow[cfield] = s(target.value || '');
                        setDirtyAutosave();
                    }
                    return;
                }
                if (target.id === 'metis-v2-events-source') { sec.content.source = s(target.value || 'calendar') === 'manual' ? 'manual' : 'calendar'; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-events-limit') { sec.content.limit = Math.max(1, Math.min(50, parseInt(s(target.value || '5'), 10) || 5)); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-form-submit-label') { sec.content.submit_label = s(target.value || 'Submit') || 'Submit'; renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-donation-amounts') { sec.content.preset_amounts = normalizePresetAmounts(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-progress-goal') { sec.content.goal_amount = normalizeDecimalString(target.value || '', 1000000000); target.value = sec.content.goal_amount; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-progress-raised') { sec.content.raised_amount = normalizeDecimalString(target.value || '', 1000000000); target.value = sec.content.raised_amount; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-progress-percent') { sec.content.percent = normalizePercentString(target.value || ''); target.value = sec.content.percent; renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-campaign-summary-title') { sec.content.title = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-campaign-summary-content') { sec.content.content = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-campaign-summary-image') { sec.content.image = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-divider-label') { sec.content.label = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-spacer-height') { sec.content.height = ['small', 'medium', 'large'].indexOf(s(target.value || 'medium')) !== -1 ? s(target.value) : 'medium'; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-posts-limit') { sec.content.limit = Math.max(1, Math.min(50, parseInt(s(target.value || '5'), 10) || 5)); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-posts-specific-page') { sec.content.specific_page = Math.max(0, parseInt(s(target.value || '0'), 10) || 0); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-category-ids') { setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-posts-category-ids') { sec.content.category_ids = selectedCategoryIds('metis-v2-posts-category-ids'); setDirtyAutosave(); return; }
            });
            root.addEventListener('change', function (e) {
                var target = e.target;
                if (target && target.matches && target.matches('[data-editor-media-upload-input]')) {
                    var uploadContext = s(target.getAttribute('data-editor-media-upload-input') || 'inline');
                    uploadEditorImage(uploadContext, target.files);
                    return;
                }
                var sec = activeSection();
                if (target.id === 'metis-v2-featured-image-mime') {
                    renderFeaturedImagePickerList();
                    return;
                }
                if (target.id === 'metis-v2-inline-image-mime') {
                    renderInlineImagePickerList();
                    return;
                }
                if (target.id === 'metis-v2-homepage') {
                    var isHomepage = !!target.checked;
                    if (!isHomepage) {
                        state.hero.enabled = false;
                    }
                    state.hero = normalizeHeroState(state.hero, isHomepage);
                    state.selectedTemplateKey = normalizeSelectedTemplateKey(state.selectedTemplateKey, currentPageType());
                    syncTemplateField();
                    if (isPageContext()) renderHeroEditor();
                    syncBuilderChrome();
                    setDirtyAutosave();
                    return;
                }
                if (target.id === 'metis-v2-status') { syncPublishedDateField(); renderPostPathPreview(); syncBuilderChrome(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-template-key') {
                    state.selectedTemplateKey = normalizeSelectedTemplateKey(target.value, currentPageType());
                    syncTemplateField();
                    setDirtyAutosave();
                    return;
                }
                if (target.id === 'metis-v2-parent-id' || target.id === 'metis-v2-featured-image-id' || target.id === 'metis-v2-published-date' || target.id === 'metis-v2-excerpt') { setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-hero-enabled') { state.hero.enabled = !!target.checked; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-hero-style') { state.hero.style = HERO_STYLES.indexOf(s(target.value || 'split')) !== -1 ? s(target.value || 'split') : 'split'; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-heading-variant') {
                    sec.content.variant = s(target.value || '') === 'section_header' ? 'section_header' : 'default';
                    if (sec.content.variant === 'section_header') {
                        sec.content.level = 'h1';
                        sec.content.align = 'center';
                        sec.content.vertical_align = 'middle';
                        sec.settings = normalizeSectionSettings(Object.assign({}, sec.settings || {}, { background: 'muted' }));
                    }
                    renderSectionList();
                    setDirtyAutosave();
                    return;
                }
                if (target.id === 'metis-v2-heading-level') { sec.content.level = ['h1', 'h2', 'h3', 'h4'].indexOf(s(target.value || 'h2')) === -1 ? 'h2' : s(target.value || 'h2'); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-heading-align') { sec.content.align = ['left', 'center', 'right'].indexOf(s(target.value || 'left')) === -1 ? 'left' : s(target.value || 'left'); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-heading-vertical') { sec.content.vertical_align = ['top', 'middle', 'bottom'].indexOf(s(target.value || 'top')) === -1 ? 'top' : s(target.value || 'top'); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-button-align') { sec.content.align = ['left', 'center', 'right'].indexOf(s(target.value || 'left')) === -1 ? 'left' : s(target.value || 'left'); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-section-background') { sec.settings = normalizeSectionSettings(Object.assign({}, sec.settings || {}, { background: target.value })); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-section-type') { sec.type = availableSectionTypes().indexOf(s(target.value || 'text')) === -1 ? 'text' : s(target.value); sec.content = defaultSectionByType(sec.type).content; renderSectionList(); renderStep2Editor(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-form-id') { sec.content.form_id = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-donation-campaign') { sec.content.campaign_id = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-donation-mode') { sec.content.mode = ['one_time', 'monthly', 'both'].indexOf(s(target.value || 'both')) === -1 ? 'both' : s(target.value || 'both'); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-donation-allow-custom') { sec.content.allow_custom_amount = !!target.checked; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-donation-show-name') { sec.content.show_name = !!target.checked; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-donation-show-email') { sec.content.show_email = !!target.checked; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-donation-show-phone') { sec.content.show_phone = !!target.checked; setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-progress-campaign') { sec.content.campaign_id = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-campaign-summary-campaign') { sec.content.campaign_id = s(target.value || ''); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-divider-style') { sec.content.style = ['solid', 'dashed', 'dotted'].indexOf(s(target.value || 'solid')) === -1 ? 'solid' : s(target.value || 'solid'); renderBuilderCanvas(); setDirtyAutosave(); return; }
                if (target.id === 'metis-v2-posts-source') {
                    sec.content.source = s(target.value || 'this_page') === 'specific_page' ? 'specific_page' : 'this_page';
                    if (sec.content.source !== 'specific_page') sec.content.specific_page = 0;
                    renderStep2Editor();
                    setDirtyAutosave();
                    return;
                }
            });
            root.addEventListener('dragenter', function (e) {
                var panel = editorMediaUploadPanelFromEvent(e);
                if (!panel || !eventHasUploadFiles(e)) return;
                e.preventDefault();
                if (!state.canManageMedia || state.mediaUpload.active) return;
                panel.classList.add('is-drag-over');
            });
            root.addEventListener('dragover', function (e) {
                var panel = editorMediaUploadPanelFromEvent(e);
                if (!panel || !eventHasUploadFiles(e)) return;
                e.preventDefault();
                if (e.dataTransfer) e.dataTransfer.dropEffect = state.mediaUpload.active ? 'none' : 'copy';
                if (!state.canManageMedia || state.mediaUpload.active) return;
                panel.classList.add('is-drag-over');
            });
            root.addEventListener('dragleave', function (e) {
                var panel = editorMediaUploadPanelFromEvent(e);
                if (!panel) return;
                var related = e.relatedTarget;
                if (related && panel.contains(related)) return;
                clearEditorMediaDropState(panel);
            });
            root.addEventListener('drop', function (e) {
                var panel = editorMediaUploadPanelFromEvent(e);
                if (!panel || !eventHasUploadFiles(e)) return;
                e.preventDefault();
                clearEditorMediaDropState(panel);
                var uploadContext = s(panel.getAttribute('data-editor-media-upload-panel') || 'inline');
                uploadEditorImage(uploadContext, e.dataTransfer ? e.dataTransfer.files : null);
            });
            root.addEventListener('pointerdown', function (e) {
                var picker = e.target && e.target.closest ? e.target.closest('[data-rich-action="color-picker"]') : null;
                if (!picker) return;
                captureRichColorSelection(picker);
            }, true);
            root.addEventListener('mouseup', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.metis-se-rich-editor') : null;
                if (target) saveRichSelection(target);
            });
            root.addEventListener('keyup', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.metis-se-rich-editor') : null;
                if (target) saveRichSelection(target);
            });
            root.addEventListener('focusin', function (e) {
                var target = e.target && e.target.closest ? e.target.closest('.metis-se-rich-editor') : null;
                if (target) {
                    saveRichSelection(target);
                    if (target.id && target.closest('.metis-builder-block')) {
                        state.activeRichTargetId = target.id;
                        retargetContextToolbar(target.id);
                    }
                }
            });
            root.addEventListener('dragstart', function (e) {
                var block = e.target && e.target.closest ? e.target.closest('[data-add-block-type]') : null;
                if (!block || (!state.canEdit && !(state.id < 1 && state.canCreate))) return;
                state.dragBlockType = s(block.getAttribute('data-add-block-type') || 'text');
                var canvas = document.getElementById('metis-v2-canvas');
                if (canvas) canvas.classList.add('is-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'copy';
                    e.dataTransfer.setData('text/plain', state.dragBlockType);
                }
            });
            root.addEventListener('dragover', function (e) {
                if (editorMediaUploadPanelFromEvent(e)) return;
                if (!state.dragBlockType) return;
                var canvas = e.target && e.target.closest ? e.target.closest('#metis-v2-canvas') : null;
                if (!canvas) return;
                e.preventDefault();
                canvas.classList.add('is-dragging');
                var activeIndex = dropIndexFromEvent(e);
                canvas.querySelectorAll('.metis-builder-drop-zone').forEach(function (node) {
                    var wrap = node.closest('[data-builder-drop-index]');
                    node.classList.toggle('is-active', parseInt(s(wrap && wrap.getAttribute('data-builder-drop-index') || '-1'), 10) === activeIndex);
                });
                if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
            });
            root.addEventListener('dragleave', function (e) {
                var canvas = document.getElementById('metis-v2-canvas');
                if (!canvas || !state.dragBlockType) return;
                var related = e.relatedTarget;
                if (related && canvas.contains(related)) return;
                clearCanvasDropTargets();
            });
            root.addEventListener('dragend', function () {
                state.dragBlockType = '';
                clearCanvasDropTargets();
            });
            root.addEventListener('drop', function (e) {
                if (editorMediaUploadPanelFromEvent(e)) return;
                var zone = e.target && e.target.closest ? e.target.closest('#metis-v2-canvas') : null;
                var type = s((e.dataTransfer && e.dataTransfer.getData('text/plain')) || state.dragBlockType || '');
                state.dragBlockType = '';
                if (!zone || !type || (!state.canEdit && !(state.id < 1 && state.canCreate))) return;
                e.preventDefault();
                var dropIndex = dropIndexFromEvent(e);
                clearCanvasDropTargets();
                insertSection(type, dropIndex);
            });
        }

        renderTop();
        wire();
        loadEntityAndOptions();
        syncStepUi();
        hideBootStatus();
    }

    if (isNewsletterContext()) {
        try {
            bootNewsletterEditor();
        } catch (err) {
            hideBootStatus();
            root.innerHTML = '<div class="metis-se-shell"><div class="metis-se-readonly">Newsletter editor failed to load. Refresh the page and try again.</div></div>';
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('Metis newsletter editor boot failed.', err);
            }
        }
        return;
    }

    if (isPageContext() || isPostContext()) {
        try {
            bootStructuredEditorV2();
        } catch (err) {
            hideBootStatus();
            root.innerHTML = '<div class="metis-se-shell"><div class="metis-se-readonly">Editor failed to load. Refresh the page and try again.</div></div>';
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('Metis structured editor boot failed.', err);
            }
        }
        return;
    }


    hideBootStatus();
    root.innerHTML = '<div class="metis-se-shell"><div class="metis-se-readonly">This editor now supports only structured Page/Post content. Manage templates from Templates and newsletters from Newsletter.</div></div>';
}(window, document));
