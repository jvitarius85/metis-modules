/**
 * Website Module — Main JS
 * Initializes page/post management and wires the block editor.
 */
(function($) {
'use strict';

if (!$) {
    var fallbackNotify = function(message, level) {
        if (typeof window.metis_toast === 'function') {
            window.metis_toast(String(message || ''), level || 'error');
            return;
        }
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn(String(message || ''));
        }
    };
    var fallbackPortalBasePath = function() {
        var p = String(window.location.pathname || '/');
        var m = p.match(/^(.*?\/)(?:website|newsletter|settings|people|finance|portal|editor)(?:\/|$)/i);
        return (m && m[1]) ? String(m[1]) : '/';
    };
    var fallbackGo = function(path) {
        var base = fallbackPortalBasePath();
        var suffix = String(path || '').replace(/^\/+/, '');
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            Metis.navigation.go(base + suffix);
            return;
        }
        window.location.assign(base + suffix);
    };
    var fallbackAjaxConfig = function() {
        if (window.Metis && Metis.request && typeof Metis.request.config === 'function') {
            try {
                return Metis.request.config(window.metisWebsiteAjax || null, 'Request endpoint is unavailable.');
            } catch (_error) {
                /* Fall through to the plain object merge below. */
            }
        }
        var cfg = window.metisWebsiteAjax || window.metisAjax || {};
        return {
            ajax_url: String(cfg.ajax_url || cfg.url || (typeof metisResolveAjaxUrl === 'function' ? metisResolveAjaxUrl() : '') || '').trim(),
            nonce: typeof cfg.nonce === 'string' ? cfg.nonce : '',
            action_nonces: (cfg.action_nonces && typeof cfg.action_nonces === 'object') ? cfg.action_nonces : {}
        };
    };
    var fallbackNonceFor = function(action) {
        var cfg = fallbackAjaxConfig();
        var map = (cfg.action_nonces && typeof cfg.action_nonces === 'object') ? cfg.action_nonces : {};
        var key = String(action || '').trim();
        if (key !== '' && typeof map[key] === 'string' && map[key] !== '') {
            return map[key];
        }
        if (window.Metis && Metis.ajax && typeof Metis.ajax.nonceFor === 'function') {
            return Metis.ajax.nonceFor(key, typeof cfg.nonce === 'string' ? cfg.nonce : '');
        }
        return typeof cfg.nonce === 'string' ? cfg.nonce : '';
    };
    var fallbackConfirm = function(message, onConfirm, options) {
        if (typeof window.metis_confirm === 'function') {
            return window.metis_confirm(message, onConfirm, options || {});
        }
        if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
            return Metis.confirm.open(Object.assign({}, options || {}, {
                message: String(message || 'Are you sure?')
            })).then(function(confirmed) {
                if (confirmed && typeof onConfirm === 'function') {
                    onConfirm();
                }
                return confirmed;
            });
        }
        return Promise.resolve(false);
    };
    var fallbackAjax = function(action, payload, onSuccess) {
        var cfg = fallbackAjaxConfig();
        var url = String(cfg.ajax_url || '').trim();
        if (url === '') {
            fallbackNotify('Request endpoint is unavailable.', 'error');
            return;
        }
        var body = new URLSearchParams();
        body.set('action', action);
        var nonce = fallbackNonceFor(action);
        if (nonce !== '') {
            body.set('nonce', nonce);
            body.set('metis_action_nonce', nonce);
        }
        var data = payload && typeof payload === 'object' ? payload : {};
        Object.keys(data).forEach(function(key) {
            body.set(key, String(data[key]));
        });
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function(res) {
            return res.json();
        }).then(function(json) {
            if (json && json.success) {
                if (typeof onSuccess === 'function') {
                    onSuccess(json);
                }
                return;
            }
            var msg = (json && json.data && json.data.message) ? String(json.data.message) : 'Request failed.';
            fallbackNotify(msg, 'error');
        }).catch(function() {
            fallbackNotify('Request failed.', 'error');
        });
    };
    var fallbackRemoveRow = function(trigger, emptyText) {
        var row = trigger && trigger.closest ? trigger.closest('.mw-premium-row') : null;
        var container = row && row.parentElement ? row.parentElement : null;
        if (row && row.parentNode) {
            row.parentNode.removeChild(row);
        }
        if (container && !container.querySelector('.mw-premium-row')) {
            var placeholder = document.createElement('div');
            placeholder.className = 'mw-premium-row';
            placeholder.innerHTML = '<div class="mw-premium-cell mw-muted">' + String(emptyText || 'No records found.') + '</div>';
            container.appendChild(placeholder);
        }
    };
    document.addEventListener('click', function(e) {
        var target = e.target;
        if (!target || !target.closest) return;

        var createPage = target.closest('#metis-create-page-btn, #metis-create-page-btn-empty');
        if (createPage) {
            e.preventDefault();
            fallbackGo('website/pages/editor/new/');
            return;
        }

        var editPage = target.closest('.metis-edit-page');
        if (editPage) {
            e.preventDefault();
            var pageCode = String(editPage.getAttribute('data-code') || '').trim();
            var pageId = String(editPage.getAttribute('data-id') || '').trim();
            var pageRef = pageCode !== '' ? pageCode : pageId;
            if (pageRef !== '') {
                fallbackGo('website/pages/editor/' + encodeURIComponent(pageRef) + '/');
            }
            return;
        }

        var createPost = target.closest('#metis-create-post-btn, #metis-create-post-btn-empty');
        if (createPost) {
            e.preventDefault();
            fallbackGo('website/posts/editor/new/');
            return;
        }

        var editPost = target.closest('.metis-edit-post');
        if (editPost) {
            e.preventDefault();
            var postCode = String(editPost.getAttribute('data-code') || '').trim();
            var postId = String(editPost.getAttribute('data-id') || '').trim();
            var postRef = postCode !== '' ? postCode : postId;
            if (postRef !== '') {
                fallbackGo('website/posts/editor/' + encodeURIComponent(postRef) + '/');
            }
            return;
        }

        var deletePage = target.closest('.metis-delete-page');
        if (deletePage) {
            e.preventDefault();
            var deletePageId = parseInt(String(deletePage.getAttribute('data-id') || ''), 10);
            if (!Number.isNaN(deletePageId) && deletePageId > 0) {
                fallbackConfirm('Delete this page? This cannot be undone.', function() {
                    fallbackAjax('metis_website_page_delete', { id: deletePageId }, function() {
                        fallbackRemoveRow(deletePage, 'No pages found.');
                        fallbackNotify('Page deleted.', 'success');
                    });
                }, {
                    title: 'Delete Page',
                    confirmLabel: 'Delete',
                    tone: 'danger'
                });
            }
            return;
        }

        var deletePost = target.closest('.metis-delete-post');
        if (deletePost) {
            e.preventDefault();
            var deletePostId = parseInt(String(deletePost.getAttribute('data-id') || ''), 10);
            if (!Number.isNaN(deletePostId) && deletePostId > 0) {
                fallbackConfirm('Delete this post? This cannot be undone.', function() {
                    fallbackAjax('metis_website_post_delete', { id: deletePostId }, function() {
                        fallbackRemoveRow(deletePost, 'No posts found.');
                        fallbackNotify('Post deleted.', 'success');
                    });
                }, {
                    title: 'Delete Post',
                    confirmLabel: 'Delete',
                    tone: 'danger'
                });
            }
        }
    }, true);
    return;
}

var MetisWebsite = {

    _editorRuntimePromise: null,
    _bindingsApplied: false,

    init: function() {
        this._ensureToast();
        if (!this._bindingsApplied) {
            this._bindDashboardActions();
            this._bindPageActions();
            this._bindPostActions();
            this._bindCategoryActions();
            this._bindTemplateActions();
            this._bindingsApplied = true;
        }
        this._maybeLaunchQuickAction();
    },

    _ensureToast: function() {
        if (typeof window.metis_toast !== 'function') {
            window.metis_toast = function(message, level) {
                if (message && window.console && typeof window.console.log === 'function') {
                    window.console.log(String(level || 'info') + ': ' + String(message));
                }
            };
        }
        if (typeof window.metis_confirm !== 'function') {
            window.metis_confirm = function(message, onConfirm, options) {
                if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
                    return Metis.confirm.open(Object.assign({}, options || {}, {
                        message: String(message || 'Are you sure?')
                    })).then(function(confirmed) {
                        if (confirmed && typeof onConfirm === 'function') {
                            onConfirm();
                        }
                        return confirmed;
                    });
                }
                return Promise.resolve(false);
            };
        }
    },

    _portalBasePath: function() {
        var p = String(window.location.pathname || '/');
        var m = p.match(/^(.*?\/)(?:website|newsletter|settings|people|finance|portal|editor)(?:\/|$)/i);
        if (m && m[1]) {
            return String(m[1]);
        }
        return '/';
    },

    _gotoEditorPath: function(path) {
        var base = this._portalBasePath();
        var suffix = String(path || '').replace(/^\/+/, '');
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            Metis.navigation.go(base + suffix);
            return;
        }
        window.location.assign(base + suffix);
    },

    _formatShortDate: function(raw) {
        var value = String(raw || '').trim();
        if (value === '') {
            return '—';
        }
        var normalized = value.replace(' ', 'T');
        var parsed = new Date(normalized);
        if (Number.isNaN(parsed.getTime())) {
            return value;
        }
        return parsed.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    },

    _updateListSubtitle: function(shellSelector, count, singularLabel, pluralLabel, suffix) {
        var shell = $(shellSelector);
        if (!shell.length) {
            return;
        }
        var safeCount = Math.max(0, parseInt(String(count || '0'), 10) || 0);
        var noun = safeCount === 1 ? singularLabel : pluralLabel;
        shell.find('.mw-page-header .mw-subtitle').first().text(safeCount + ' ' + noun + ' ' + String(suffix || ''));
    },

    _pageRows: function() {
        return $('#metis-pages-list-shell .metis-pages-table .mw-premium-row').not('.mw-premium-header');
    },

    _pageRowForId: function(id) {
        return $('#metis-pages-list-shell .metis-pages-table').find('.metis-edit-page[data-id="' + String(id || '') + '"]').first().closest('.mw-premium-row');
    },

    _pageLiveHref: function(page) {
        var base = String(this._publicSiteBaseUrl() || '').replace(/\/+$/, '');
        var slug = String(page && page.slug ? page.slug : '').replace(/^\/+/, '');
        if (page && page.is_homepage) {
            return (base || '') + '/';
        }
        return slug !== '' ? ((base || '') + '/' + slug) : ((base || '') + '/');
    },

    _pageActionsMarkup: function(page) {
        var id = String(page && page.id ? page.id : '');
        var code = this._escHtml(String(page && page.page_code ? page.page_code : ''));
        var liveHref = this._pageLiveHref(page);
        var actions = [
            '<div class="metis-table-actions">',
            '<button class="metis-action-btn metis-edit-page" data-id="' + this._escHtml(id) + '" data-code="' + code + '" title="Edit in editor">',
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
            '</button>'
        ];

        if (String(page && page.status ? page.status : 'draft') === 'draft') {
            actions.push('<button class="metis-action-btn metis-action-btn-primary metis-publish-page" data-id="' + this._escHtml(id) + '" title="Publish"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></button>');
        } else {
            actions.push('<button class="metis-action-btn metis-unpublish-page" data-id="' + this._escHtml(id) + '" title="Unpublish"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>');
            actions.push('<a href="' + this._escHtml(liveHref) + '" class="metis-action-btn" title="View live" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>');
        }

        actions.push('<button class="metis-action-btn metis-action-btn-danger metis-delete-page" data-id="' + this._escHtml(id) + '" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>');
        actions.push('</div>');
        return actions.join('');
    },

    _syncHomepageSelectorOption: function(page) {
        var $selector = $('#metis-homepage-selector');
        if (!$selector.length || !page || !page.id) {
            return;
        }
        var id = String(page.id);
        var $option = $selector.find('option[value="' + id + '"]');
        if (String(page.status || '') === 'published') {
            var label = String(page.title || '') + ' (/' + String(page.slug || '') + ')';
            if ($option.length) {
                $option.text(label);
            } else {
                $selector.append('<option value="' + this._escHtml(id) + '">' + this._escHtml(label) + '</option>');
            }
        } else {
            $option.remove();
            if (String($selector.val() || '') === id) {
                $selector.val('');
            }
        }
    },

    _applyHomepagePageId: function(homepageId) {
        var targetId = String(homepageId || '');
        this._pageRows().find('.metis-homepage-badge').remove();
        $('#metis-homepage-selector').val(targetId);
        if (targetId === '') {
            return;
        }
        var $row = this._pageRowForId(targetId);
        if ($row.length) {
            var $titleCell = $row.children('.mw-premium-cell').eq(0);
            if ($titleCell.length && !$titleCell.find('.metis-homepage-badge').length) {
                $titleCell.append(' <span class="metis-status metis-status-published metis-homepage-badge">Homepage</span>');
            }
        }
    },

    _updatePageRow: function(page) {
        if (!page || !page.id) {
            return;
        }
        var $row = this._pageRowForId(page.id);
        if (!$row.length) {
            return;
        }
        var safeTitle = this._escHtml(String(page.title || ''));
        var safeCode = this._escHtml(String(page.page_code || '—'));
        var safeCodeAttr = this._escHtml(String(page.page_code || ''));
        var safeSlug = this._escHtml('/' + String(page.slug || '').replace(/^\/+/, ''));
        var safeStatus = this._escHtml(String(page.status || 'draft'));
        var safeStatusLabel = this._escHtml(this._titleCase(String(page.status || 'draft')));

        $row.children('.mw-premium-cell').eq(0).html(
            '<strong class="metis-edit-page metis-page-title" data-id="' + this._escHtml(String(page.id)) + '" data-code="' + safeCodeAttr + '">' + safeTitle + '</strong>'
            + (page.is_homepage ? ' <span class="metis-status metis-status-published metis-homepage-badge">Homepage</span>' : '')
        );
        $row.children('.mw-premium-cell').eq(1).html('<code class="metis-inline-code-muted">' + safeSlug + '</code>');
        $row.children('.mw-premium-cell').eq(2).html('<span class="metis-status metis-status-' + safeStatus + '">' + safeStatusLabel + '</span>');
        $row.children('.mw-premium-cell').eq(3).html('<code class="metis-inline-code">' + safeCode + '</code>');
        $row.children('.mw-premium-cell').eq(4).text(this._formatShortDate(page.updated_at || page.last_edit || ''));
        $row.children('.mw-premium-cell').eq(5).html(this._pageActionsMarkup(page));
        this._syncHomepageSelectorOption(page);
        this._applyHomepagePageId(page.is_homepage ? page.id : $('#metis-homepage-selector').val());
        this._updateListSubtitle('#metis-pages-list-shell', this._pageRows().length, 'page', 'pages', 'in website content.');
    },

    _removePageRow: function(id) {
        this._pageRowForId(id).remove();
        $('#metis-homepage-selector option[value="' + String(id || '') + '"]').remove();
        if (String($('#metis-homepage-selector').val() || '') === String(id || '')) {
            $('#metis-homepage-selector').val('');
        }
        this._updateListSubtitle('#metis-pages-list-shell', this._pageRows().length, 'page', 'pages', 'in website content.');
    },

    _postRows: function() {
        return $('#metis-posts-list-shell tbody tr');
    },

    _postRowForId: function(id) {
        return $('#metis-posts-list-shell tbody').find('.metis-edit-post[data-id="' + String(id || '') + '"]').first().closest('tr');
    },

    _postCategoriesMarkup: function(post) {
        var categories = post && Array.isArray(post.categories) ? post.categories : [];
        if (!categories.length) {
            return '<span class="metis-posts-table-compact__category-chip">Uncategorized</span>';
        }
        return categories.map(function(category) {
            return '<span class="metis-posts-table-compact__category-chip">' + MetisWebsite._escHtml(String(category && category.name ? category.name : '')) + '</span>';
        }).join('');
    },

    _postActionsMarkup: function(post) {
        var id = String(post && post.id ? post.id : '');
        var code = this._escHtml(String(post && post.post_code ? post.post_code : ''));
        var publicPath = String(post && post.public_path ? post.public_path : '').trim();
        var liveHref = publicPath !== '' ? String(this._publicSiteBaseUrl() || '').replace(/\/+$/, '') + '/' + publicPath.replace(/^\/+/, '') : '';
        var actions = [
            '<div class="metis-table-actions">',
            '<button class="metis-action-btn metis-edit-post" data-id="' + this._escHtml(id) + '" data-code="' + code + '" title="Edit in editor"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>'
        ];
        if (String(post && post.status ? post.status : 'draft') === 'draft') {
            actions.push('<button class="metis-action-btn metis-action-btn-primary metis-publish-post" data-id="' + this._escHtml(id) + '" title="Publish"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></button>');
        }
        if (String(post && post.status ? post.status : '') === 'published' && liveHref !== '') {
            actions.push('<a href="' + this._escHtml(liveHref) + '" class="metis-action-btn" title="View live" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>');
        }
        actions.push('<button class="metis-action-btn metis-action-btn-danger metis-delete-post" data-id="' + this._escHtml(id) + '" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>');
        actions.push('</div>');
        return actions.join('');
    },

    _updatePostRow: function(post) {
        if (!post || !post.id) {
            return;
        }
        var $row = this._postRowForId(post.id);
        if (!$row.length) {
            return;
        }
        var safeTitle = this._escHtml(String(post.title || ''));
        var safeCode = this._escHtml(String(post.post_code || ''));
        var slugPath = '/' + String(post.slug || '').replace(/^\/+/, '');
        $row.children('td').eq(0).html(
            '<button type="button" class="metis-link-button metis-edit-post" data-id="' + this._escHtml(String(post.id)) + '" data-code="' + safeCode + '">' + safeTitle + '</button>'
            + '<div class="metis-posts-table-compact__slug">' + this._escHtml(slugPath) + '</div>'
            + '<div class="metis-posts-table-compact__category-list metis-posts-table-compact__category-list--under-title">' + this._postCategoriesMarkup(post) + '</div>'
        );
        $row.children('td').eq(1).html('<span class="metis-status metis-status-' + this._escHtml(String(post.status || 'draft')) + '">' + this._escHtml(this._titleCase(String(post.status || 'draft'))) + '</span>');
        $row.children('td').eq(2).text(this._formatShortDate(post.publish_date || post.published_date || ''));
        $row.children('td').eq(3).html(this._postActionsMarkup(post));
        this._updateListSubtitle('#metis-posts-list-shell', this._postRows().length, 'post', 'posts', 'in website content.');
    },

    _removePostRow: function(id) {
        this._postRowForId(id).remove();
        this._updateListSubtitle('#metis-posts-list-shell', this._postRows().length, 'post', 'posts', 'in website content.');
    },

    _publicSiteBaseUrl: function() {
        var ajax = (window.metisWebsiteAjax && window.metisWebsiteAjax.ajax_url) ? String(window.metisWebsiteAjax.ajax_url) : '';
        if (ajax !== '') {
            try {
                var u = new URL(ajax, window.location.origin);
                var path = String(u.pathname || '/').replace(/\/api\/ajax\/?$/i, '');
                path = path.replace(/\/+$/, '');
                return u.origin + (path || '');
            } catch (_err) {}
        }
        var origin = String(window.location.origin || '');
        var adminBase = this._portalBasePath().replace(/\/admin\/?$/i, '').replace(/\/+$/, '');
        return origin + adminBase;
    },
    _ensureEditorRuntime: function(done) {
        var hasSimpleEditor = !!window.__metisSimpleEditorBooted || !!document.getElementById("metis-simple-editor-root");
        if (typeof done === "function") done(hasSimpleEditor);
    },

    _nonceFor: function(action) {
        var ajax = window.metisWebsiteAjax || {};
        var actionNonces = (ajax.action_nonces && typeof ajax.action_nonces === 'object') ? ajax.action_nonces : {};
        var key = String(action || '').trim();
        if (key !== '' && typeof actionNonces[key] === 'string' && actionNonces[key] !== '') {
            return actionNonces[key];
        }
        return typeof ajax.nonce === 'string' ? ajax.nonce : '';
    },

    _baseNonce: function() {
        var website = window.metisWebsiteAjax || {};
        var core = window.metisAjax || {};
        var metisAjax = window.Metis && window.Metis.ajax ? window.Metis.ajax : null;
        return String(website.nonce || core.nonce || (metisAjax && metisAjax.nonce) || '').trim();
    },

    _csrfPayload: function(action) {
        var key = String(action || '').trim();
        var baseNonce = this._baseNonce();
        var actionNonce = this._nonceFor(key);
        if (actionNonce === '' && window.Metis && window.Metis.ajax && typeof window.Metis.ajax.nonceFor === 'function') {
            actionNonce = String(window.Metis.ajax.nonceFor(key, '') || '').trim();
        }
        return {
            nonce: baseNonce,
            metis_action_nonce: actionNonce !== '' ? actionNonce : baseNonce,
            metis_csrf_action: actionNonce !== '' ? ('metis_ajax:' + key) : 'metis_website'
        };
    },

    _confirm: function(message, onConfirm, options) {
        if (typeof window.metis_confirm === 'function') {
            window.metis_confirm(String(message || ''), onConfirm, options || {});
            return;
        }
        if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
            Metis.confirm.open(Object.assign({}, options || {}, {
                message: String(message || 'Are you sure?')
            })).then(function(confirmed) {
                if (confirmed && typeof onConfirm === 'function') {
                    onConfirm();
                }
            });
        }
    },

    _bindDashboardActions: function() {
        $(document).off('click.metisWebsiteDashboardPage').on('click.metisWebsiteDashboardPage', '#metis-dashboard-new-page-btn, #metis-dashboard-quick-new-page-btn', function() {
            MetisWebsite.openPageEditor(null);
        });
        $(document).off('click.metisWebsiteDashboardPost').on('click.metisWebsiteDashboardPost', '#metis-dashboard-new-post-btn, #metis-dashboard-quick-new-post-btn', function() {
            MetisWebsite.openPostEditor(null);
        });
    },

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    _bindPageActions: function() {
        $(document).off('click.metisWebsiteCreatePage').on('click.metisWebsiteCreatePage', '#metis-create-page-btn', function() {
            MetisWebsite.openPageEditor(null);
        });

        $(document).off('click.metisWebsiteEditPage').on('click.metisWebsiteEditPage', '.metis-edit-page', function() {
            var code = String($(this).data('code') || '').trim();
            if (code !== '') {
                MetisWebsite.openPageEditor(code);
                return;
            }
            var id = parseInt($(this).data('id'), 10);
            if (!Number.isNaN(id) && id > 0) {
                MetisWebsite.openPageEditor(String(id));
            }
        });

        $(document).off('click.metisWebsiteViewPage').on('click.metisWebsiteViewPage', '.metis-view-page', function(e) {
            e.stopPropagation();
        });

        $(document).off('click.metisWebsitePublishPage').on('click.metisWebsitePublishPage', '.metis-publish-page', function() {
            var id = parseInt($(this).data('id'), 10);
            MetisWebsite._publishPage(id);
        });

        $(document).off('click.metisWebsiteUnpublishPage').on('click.metisWebsiteUnpublishPage', '.metis-unpublish-page', function() {
            var id = parseInt($(this).data('id'), 10);
            MetisWebsite._unpublishPage(id);
        });

        $(document).off('click.metisWebsiteDeletePage').on('click.metisWebsiteDeletePage', '.metis-delete-page', function() {
            var id = parseInt($(this).data('id'), 10);
            var title = String($(this).closest('.mw-premium-row').find('.mw-premium-cell:first strong').first().text() || '').trim();
            MetisWebsite._confirm('Delete "' + title + '"? This cannot be undone.', function() {
                MetisWebsite._deletePage(id);
            });
        });

        $(document).off('click.metisWebsiteHomepage').on('click.metisWebsiteHomepage', '#metis-set-homepage-btn', function() {
            var id = parseInt(String($('#metis-homepage-selector').val() || ''), 10);
            if (Number.isNaN(id) || id < 1) {
                metis_toast('Select a published page first.', 'warning');
                return;
            }
            MetisWebsite._setHomepage(id);
        });
    },

    openPageEditor: function(pageRef) {
        if (pageRef) {
            this._gotoEditorPath('website/pages/editor/' + encodeURIComponent(String(pageRef)) + '/');
            return;
        }
        this._gotoEditorPath('website/pages/editor/new/');
    },

    _deletePage: function(id) {
        var self = this;
        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: { action: 'metis_website_page_delete', nonce: self._nonceFor('metis_website_page_delete'), id: id },
            success: function(r) {
                if (r && r.success) {
                    metis_toast('Page deleted.', 'success');
                    MetisWebsite._removePageRow((r.data && r.data.id) ? r.data.id : id);
                    MetisWebsite._applyHomepagePageId((r.data && r.data.homepage_page_id) ? r.data.homepage_page_id : $('#metis-homepage-selector').val());
                }
                else { metis_toast(r.data && r.data.message ? r.data.message : 'Delete failed.', 'error'); }
            },
            error: function() { metis_toast('Request failed.', 'error'); }
        });
    },

    _publishPage: function(id) {
        var self = this;
        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: { action: 'metis_website_page_publish', nonce: self._nonceFor('metis_website_page_publish'), id: id },
            success: function(r) {
                if (r && r.success && r.data && r.data.page) {
                    metis_toast('Page published.', 'success');
                    MetisWebsite._updatePageRow(r.data.page);
                }
                else { metis_toast(r.data && r.data.message ? r.data.message : 'Publish failed.', 'error'); }
            },
            error: function() { metis_toast('Request failed.', 'error'); }
        });
    },

    _unpublishPage: function(id) {
        var self = this;
        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: { action: 'metis_website_page_unpublish', nonce: self._nonceFor('metis_website_page_unpublish'), id: id },
            success: function(r) {
                if (r && r.success && r.data && r.data.page) {
                    metis_toast('Page unpublished.', 'success');
                    MetisWebsite._updatePageRow(r.data.page);
                }
                else { metis_toast(r.data && r.data.message ? r.data.message : 'Unpublish failed.', 'error'); }
            },
            error: function() { metis_toast('Request failed.', 'error'); }
        });
    },

    _setHomepage: function(id) {
        var self = this;
        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: { action: 'metis_website_homepage_set', nonce: self._nonceFor('metis_website_homepage_set'), id: id },
            success: function(r) {
                if (r && r.success && r.data) {
                    metis_toast('Homepage updated.', 'success');
                    if (r.data.page) {
                        MetisWebsite._updatePageRow(r.data.page);
                    }
                    MetisWebsite._applyHomepagePageId(r.data.homepage_page_id || id);
                    return;
                }
                metis_toast((r && r.data && r.data.message) ? r.data.message : 'Failed to set homepage.', 'error');
            },
            error: function() { metis_toast('Request failed.', 'error'); }
        });
    },

    // -------------------------------------------------------------------------
    // Posts
    // -------------------------------------------------------------------------

    _bindPostActions: function() {
        $(document).off('click.metisWebsiteCreatePost').on('click.metisWebsiteCreatePost', '#metis-create-post-btn', function() {
            MetisWebsite.openPostEditor(null);
        });

        $(document).off('click.metisWebsiteEditPost').on('click.metisWebsiteEditPost', '.metis-edit-post', function() {
            var code = String($(this).data('code') || '').trim();
            if (code !== '') {
                MetisWebsite.openPostEditor(code);
                return;
            }
            var id = parseInt($(this).data('id'), 10);
            if (!Number.isNaN(id) && id > 0) {
                MetisWebsite.openPostEditor(String(id));
            }
        });

        $(document).off('click.metisWebsitePublishPost').on('click.metisWebsitePublishPost', '.metis-publish-post', function() {
            var id = parseInt($(this).data('id'), 10);
            MetisWebsite._publishPost(id);
        });

        $(document).off('click.metisWebsiteDeletePost').on('click.metisWebsiteDeletePost', '.metis-delete-post', function() {
            var id = parseInt($(this).data('id'), 10);
            var title = String($(this).closest('.mw-premium-row').find('.mw-premium-cell:first strong').first().text() || '').trim();
            MetisWebsite._confirm('Delete "' + title + '"? This cannot be undone.', function() {
                MetisWebsite._deletePost(id);
            });
        });
    },

    openPostEditor: function(postRef) {
        if (postRef) {
            this._gotoEditorPath('website/posts/editor/' + encodeURIComponent(String(postRef)) + '/');
            return;
        }
        this._gotoEditorPath('website/posts/editor/new/');
    },

    // -------------------------------------------------------------------------
    // Post Categories
    // -------------------------------------------------------------------------

    _bindCategoryActions: function() {
        if (!document.getElementById('metis-post-category-modal')) {
            return;
        }

        $(document).off('click.metisWebsiteCreateCategory').on('click.metisWebsiteCreateCategory', '#metis-create-post-category-btn, #metis-create-post-category-btn-empty', function(e) {
            e.preventDefault();
            MetisWebsite._openPostCategoryCreate();
        });

        $(document).off('click.metisWebsiteEditCategory').on('click.metisWebsiteEditCategory', '.metis-edit-post-category', function(e) {
            e.preventDefault();
            MetisWebsite._openPostCategoryEdit(this);
        });

        $(document).off('click.metisWebsiteDeleteCategory').on('click.metisWebsiteDeleteCategory', '.metis-delete-post-category', function(e) {
            e.preventDefault();
            MetisWebsite._deletePostCategory(this);
        });

        $(document).off('click.metisWebsiteCloseCategory').on('click.metisWebsiteCloseCategory', '#metis-post-category-modal-close, #metis-post-category-cancel-btn', function(e) {
            e.preventDefault();
            MetisWebsite._closePostCategoryModal();
        });

        $(document).off('click.metisWebsiteSaveCategory').on('click.metisWebsiteSaveCategory', '#metis-post-category-save-btn', function(e) {
            e.preventDefault();
            MetisWebsite._savePostCategory();
        });

        $(document).off('click.metisWebsiteCategoryBackdrop').on('click.metisWebsiteCategoryBackdrop', '#metis-post-category-modal', function(e) {
            if (e.target && e.target.id === 'metis-post-category-modal') {
                MetisWebsite._closePostCategoryModal();
            }
        });
    },

    _postCategoryModal: function() {
        return $('#metis-post-category-modal');
    },

    _postCategoriesView: function() {
        return $('#metis-post-categories-view');
    },

    _postCategoryNonce: function(action) {
        var view = this._postCategoriesView();
        if (!view.length) {
            return '';
        }
        var key = String(action || '').trim();
        if (key === 'metis_website_post_category_save') {
            return String(view.attr('data-save-nonce') || '').trim();
        }
        if (key === 'metis_website_post_category_delete') {
            return String(view.attr('data-delete-nonce') || '').trim();
        }
        return '';
    },

    _postCategoryParentOptions: function() {
        var raw = String(this._postCategoryModal().attr('data-category-options') || '[]');
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (_error) {
            return [];
        }
    },

    _resetPostCategoryForm: function() {
        $('#metis-post-category-id').val('');
        $('#metis-post-category-name').val('');
        $('#metis-post-category-slug').val('');
        $('#metis-post-category-status').val('active');
        $('#metis-post-category-sort-order').val('0');
        this._renderPostCategoryParentOptions(0, 0);
    },

    _renderPostCategoryParentOptions: function(selectedId, currentId) {
        var selected = parseInt(String(selectedId || '0'), 10);
        var current = parseInt(String(currentId || '0'), 10);
        if (Number.isNaN(selected) || selected < 1) selected = 0;
        if (Number.isNaN(current) || current < 1) current = 0;

        var html = ['<option value="0">None</option>'];
        this._postCategoryParentOptions().forEach(function(option) {
            var id = parseInt(String(option.id || 0), 10);
            if (Number.isNaN(id) || id < 1 || id === current) {
                return;
            }
            var label = String(option.label || '');
            html.push('<option value="' + String(id) + '"' + (id === selected ? ' selected' : '') + '>' + MetisWebsite._escHtml(label) + '</option>');
        });
        $('#metis-post-category-parent-id').html(html.join(''));
    },

    _renderPostCategoriesTable: function(categories) {
        var view = this._postCategoriesView();
        if (!view.length) {
            return;
        }
        var list = Array.isArray(categories) ? categories : [];
        var wrap = view.find('.metis-post-categories-wrap');
        var modal = this._postCategoryModal();
        var optionPayload = list
            .filter(function(category) {
                return category && parseInt(String(category.id || '0'), 10) > 0;
            })
            .map(function(category) {
                return {
                    id: parseInt(String(category.id || '0'), 10) || 0,
                    label: String(category.indented_name || category.name || '')
                };
            });

        wrap.empty();
        view.find('.mw-page-header .mw-subtitle').text(list.length + ' categor' + (list.length === 1 ? 'y' : 'ies') + ' available for posts.');
        modal.attr('data-category-options', JSON.stringify(optionPayload));

        if (!list.length) {
            wrap.append(
                '<div class="metis-empty-state">' +
                    '<div class="metis-empty-state-icon">&#128278;</div>' +
                    '<h2>No categories yet</h2>' +
                    '<p>Create categories here, including child categories when needed, then assign one or more categories per post in the post editor.</p>' +
                    '<button type="button" class="mw-btn mw-btn-primary" id="metis-create-post-category-btn-empty">New Category</button>' +
                '</div>'
            );
            return;
        }

        var rows = list.map(function(category) {
            var id = parseInt(String(category.id || '0'), 10) || 0;
            var name = String(category.name || '');
            var indentedName = String(category.indented_name || name);
            var slug = String(category.slug || '');
            var status = String(category.status || 'active');
            var parentId = parseInt(String(category.parent_id || '0'), 10) || 0;
            var parentName = String(category.parent_name || '—') || '—';
            var sortOrder = parseInt(String(category.sort_order || '0'), 10) || 0;
            var postCount = parseInt(String(category.post_count || '0'), 10) || 0;
            return '<tr>'
                + '<td><strong>' + MetisWebsite._escHtml(indentedName) + '</strong></td>'
                + '<td>' + MetisWebsite._escHtml(parentName) + '</td>'
                + '<td><code>' + MetisWebsite._escHtml(slug) + '</code></td>'
                + '<td><span class="metis-status metis-status-' + (status === 'active' ? 'published' : 'draft') + '">' + MetisWebsite._escHtml(MetisWebsite._titleCase(status)) + '</span></td>'
                + '<td>' + MetisWebsite._escHtml(String(postCount)) + '</td>'
                + '<td class="mw-col-right"><div class="metis-table-actions">'
                + '<button type="button" class="metis-action-btn metis-edit-post-category" data-id="' + MetisWebsite._escHtml(String(id)) + '" data-name="' + MetisWebsite._escHtml(name) + '" data-slug="' + MetisWebsite._escHtml(slug) + '" data-status="' + MetisWebsite._escHtml(status) + '" data-sort-order="' + MetisWebsite._escHtml(String(sortOrder)) + '" data-parent-id="' + MetisWebsite._escHtml(String(parentId)) + '" title="Edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>'
                + '<button type="button" class="metis-action-btn metis-action-btn-danger metis-delete-post-category" data-id="' + MetisWebsite._escHtml(String(id)) + '" data-post-count="' + MetisWebsite._escHtml(String(postCount)) + '" title="Delete"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>'
                + '</div></td>'
                + '</tr>';
        }).join('');

        wrap.append(
            '<table class="metis-post-categories-table" role="table"><thead><tr><th>Name</th><th>Parent</th><th>Slug</th><th>Status</th><th>Posts</th><th class="mw-col-right">Actions</th></tr></thead><tbody>' + rows + '</tbody></table>'
        );
    },

    _openPostCategoryModal: function(title) {
        $('#metis-post-category-modal-title').text(String(title || 'Category'));
        this._postCategoryModal().css('display', 'flex');
    },

    _closePostCategoryModal: function() {
        this._postCategoryModal().css('display', 'none');
    },

    _openPostCategoryCreate: function() {
        this._resetPostCategoryForm();
        this._openPostCategoryModal('New Category');
    },

    _openPostCategoryEdit: function(button) {
        var $button = $(button);
        var currentId = parseInt(String($button.attr('data-id') || '0'), 10);
        if (Number.isNaN(currentId) || currentId < 1) {
            metis_toast('Category record is missing an id.', 'error');
            return;
        }

        $('#metis-post-category-id').val(String(currentId));
        $('#metis-post-category-name').val(String($button.attr('data-name') || ''));
        $('#metis-post-category-slug').val(String($button.attr('data-slug') || ''));
        $('#metis-post-category-status').val(String($button.attr('data-status') || 'active'));
        $('#metis-post-category-sort-order').val(String($button.attr('data-sort-order') || '0'));
        this._renderPostCategoryParentOptions(String($button.attr('data-parent-id') || '0'), currentId);
        this._openPostCategoryModal('Edit Category');
    },

    _savePostCategory: function() {
        var payload = {
            action: 'metis_website_post_category_save',
            id: String($('#metis-post-category-id').val() || '').trim(),
            name: String($('#metis-post-category-name').val() || '').trim(),
            slug: String($('#metis-post-category-slug').val() || '').trim(),
            parent_id: String($('#metis-post-category-parent-id').val() || '0').trim(),
            status: String($('#metis-post-category-status').val() || 'active').trim(),
            sort_order: String($('#metis-post-category-sort-order').val() || '0').trim()
        };
        var security = this._csrfPayload(payload.action);
        var pageActionNonce = this._postCategoryNonce(payload.action);
        payload.nonce = security.nonce;
        payload.metis_action_nonce = pageActionNonce !== '' ? pageActionNonce : security.metis_action_nonce;
        payload.metis_csrf_action = payload.metis_action_nonce !== '' ? ('metis_ajax:' + payload.action) : security.metis_csrf_action;

        if (payload.name === '') {
            metis_toast('Category name is required.', 'warning');
            return;
        }

        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: payload,
            success: function(r) {
                if (r && r.success) {
                    MetisWebsite._renderPostCategoriesTable((r.data && r.data.categories) || []);
                    MetisWebsite._closePostCategoryModal();
                    metis_toast((r.data && r.data.message) ? r.data.message : 'Category saved.', 'success');
                    return;
                }
                metis_toast((r && r.data && r.data.message) ? r.data.message : 'Failed to save category.', 'error');
            },
            error: function(xhr) {
                var message = 'Request failed.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                    if (xhr.responseJSON.data.message) {
                        message = String(xhr.responseJSON.data.message);
                    } else if (typeof xhr.responseJSON.data === 'string') {
                        message = xhr.responseJSON.data;
                    }
                }
                metis_toast(message, 'error');
            }
        });
    },

    _deletePostCategory: function(button) {
        var $button = $(button);
        var id = parseInt(String($button.attr('data-id') || '0'), 10);
        var postCount = parseInt(String($button.attr('data-post-count') || '0'), 10);
        if (Number.isNaN(id) || id < 1) {
            metis_toast('Category record is missing an id.', 'error');
            return;
        }
        if (!Number.isNaN(postCount) && postCount > 0) {
            metis_toast('Remove this category from existing posts before deleting it.', 'warning');
            return;
        }

        this._confirm('Delete this category? This cannot be undone.', function() {
            var security = MetisWebsite._csrfPayload('metis_website_post_category_delete');
            var pageActionNonce = MetisWebsite._postCategoryNonce('metis_website_post_category_delete');
            $.ajax({
                url: metisWebsiteAjax.ajax_url,
                type: 'POST',
                data: {
                    action: 'metis_website_post_category_delete',
                    nonce: security.nonce,
                    metis_action_nonce: pageActionNonce !== '' ? pageActionNonce : security.metis_action_nonce,
                    metis_csrf_action: (pageActionNonce !== '' ? 'metis_ajax:metis_website_post_category_delete' : security.metis_csrf_action),
                    id: id
                },
                success: function(r) {
                    if (r && r.success) {
                        MetisWebsite._renderPostCategoriesTable((r.data && r.data.categories) || []);
                        metis_toast((r.data && r.data.message) ? r.data.message : 'Category deleted.', 'success');
                        return;
                    }
                    metis_toast((r && r.data && r.data.message) ? r.data.message : 'Failed to delete category.', 'error');
                },
                error: function(xhr) {
                    var message = 'Request failed.';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                        if (xhr.responseJSON.data.message) {
                            message = String(xhr.responseJSON.data.message);
                        } else if (typeof xhr.responseJSON.data === 'string') {
                            message = xhr.responseJSON.data;
                        }
                    }
                    metis_toast(message, 'error');
                }
            });
        });
    },

    // -------------------------------------------------------------------------
    // Templates
    // -------------------------------------------------------------------------

    _bindTemplateActions: function() {
        if (!document.getElementById('metis-templates-view')) {
            return;
        }

        var self = this;
        $(document).off('click.metisWebsiteLayoutItem').on('click.metisWebsiteLayoutItem', '.metis-layout-gallery-item', function() {
            var key = String($(this).data('layoutProfile') || '').trim();
            var name = String($(this).data('layoutName') || key).trim();
            if (key === '') return;
            self._saveSiteLayoutProfile(key, name);
        });
        $(document).off('click.metisWebsitePreviewOpen').on('click.metisWebsitePreviewOpen', '#metis-layout-preview-open', function() {
            self._openLayoutPreviewModal();
        });
        $(document).off('change.metisWebsitePreviewSelect').on('change.metisWebsitePreviewSelect', '#metis-layout-preview-select', function() {
            var key = String($(this).val() || '').trim();
            self._renderLayoutPreviewCanvas(key);
        });
        $(document).off('change.metisWebsitePreviewSource').on('change.metisWebsitePreviewSource', '#metis-layout-preview-source', function() {
            var key = String($('#metis-layout-preview-select').val() || '').trim();
            self._renderLayoutPreviewCanvas(key);
        });
        $(document).off('click.metisWebsitePreviewClose').on('click.metisWebsitePreviewClose', '#metis-layout-preview-modal [data-action=\"close\"]', function() {
            $('#metis-layout-preview-modal').attr('hidden', 'hidden');
        });
    },

    _saveSiteLayoutProfile: function(key, name) {
        var self = this;
        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'metis_website_layout_profile_save',
                nonce: this._nonceFor('metis_website_layout_profile_save'),
                site_layout_profile: key
            },
            success: function(r) {
                if (!r || !r.success) {
                    metis_toast((r && r.data && r.data.message) || 'Failed to save template.', 'error');
                    return;
                }
                $('.metis-layout-gallery-item').removeClass('is-active');
                $('.metis-layout-gallery-item[data-layout-profile="' + self._escHtml(key) + '"]').addClass('is-active');
                $('#metis-layout-gallery-status').text('Active template: ' + (name || key));
                $('#metis-layout-preview-select').val(key);
                self._renderLayoutPreviewCanvas(key);
                metis_toast('Site template updated.', 'success');
            },
            error: function() {
                metis_toast('Request failed.', 'error');
            }
        });
    },

    _openLayoutPreviewModal: function() {
        var $modal = $('#metis-layout-preview-modal');
        var active = String($('.metis-layout-gallery-item.is-active').data('layoutProfile') || $('#metis-layout-preview-select').val() || 'centered_stack_marketing');
        $('#metis-layout-preview-select').val(active);
        this._renderLayoutPreviewCanvas(active);
        $modal.removeAttr('hidden');
    },

    _renderLayoutPreviewCanvas: function(profileKey) {
        var key = String(profileKey || 'centered_stack_marketing');
        var label = String($('#metis-layout-preview-select option[value="' + key + '"]').text() || key);
        $('#metis-layout-preview-name').text(label);
        var source = String($('#metis-layout-preview-source').val() || 'auto').trim().toLowerCase();
        if (!/^(auto|homepage|page|post|demo)$/.test(source)) {
            source = 'auto';
        }
        var base = this._publicSiteBaseUrl();
        var previewUrl = base
            + '/?metis_layout_preview=' + encodeURIComponent(key)
            + '&metis_preview=1'
            + '&metis_preview_source=' + encodeURIComponent(source)
            + '&metis_preview_ts=' + String(Date.now());
        $('#metis-layout-preview-frame').attr('src', previewUrl);
    },

    _renderTemplateRows: function() {
        var items = (this._templateState && Array.isArray(this._templateState.items)) ? this._templateState.items : [];
        var $body = $('#metis-template-table-body');
        var $empty = $('#metis-template-empty-state');
        $body.empty();

        if (items.length === 0) {
            $('.metis-templates-table').hide();
            $empty.prop('hidden', false);
            return;
        }

        $('.metis-templates-table').show();
        $empty.prop('hidden', true);

        for (var i = 0; i < items.length; i += 1) {
            var item = items[i] || {};
            var row = [
                '<div class="mw-premium-row">',
                '  <div class="mw-premium-cell"><strong>' + this._escHtml(item.name || '') + '</strong></div>',
                '  <div class="mw-premium-cell">' + this._escHtml(this._templateTypeLabel(item.template_type || '')) + '</div>',
                '  <div class="mw-premium-cell"><span class="metis-status metis-status-' + this._escHtml(item.status || 'draft') + '">' + this._escHtml(this._titleCase(item.status || 'draft')) + '</span></div>',
                '  <div class="mw-premium-cell">' + (item.is_default ? 'Yes' : 'No') + '</div>',
                '  <div class="mw-premium-cell"><code>' + this._escHtml(item.template_key || '') + '</code></div>',
                '  <div class="mw-premium-cell mw-col-right">',
                '      <div class="metis-table-actions">',
                '          <button class="metis-action-btn metis-template-edit" data-template-key="' + this._escHtml(item.template_key || '') + '" title="Edit">',
                '              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
                '          </button>',
                '          <button class="metis-action-btn metis-action-btn-danger metis-template-delete" data-id="' + this._escHtml(String(item.id || '')) + '" data-name="' + this._escHtml(item.name || '') + '" title="Delete">',
                '              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
                '          </button>',
                '      </div>',
                '  </div>',
                '</div>'
            ].join('');
            $body.append(row);
        }
    },

    _deleteTemplate: function(id) {
        var self = this;
        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'metis_website_template_delete',
                nonce: this._nonceFor('metis_website_template_delete'),
                id: id
            },
            success: function(r) {
                if (!r || !r.success) {
                    metis_toast((r && r.data && r.data.message) || 'Failed to delete template.', 'error');
                    return;
                }
                metis_toast('Template deleted.', 'success');
                if (typeof self._loadTemplates === 'function') {
                    self._loadTemplates();
                }
            },
            error: function() {
                metis_toast('Request failed.', 'error');
            }
        });
    },

    _titleCase: function(value) {
        var text = String(value || '').replace(/_/g, ' ');
        if (text === '') return '';
        return text.charAt(0).toUpperCase() + text.slice(1);
    },

    _templateTypeLabel: function(value) {
        var type = String(value || '').trim();
        if (type === 'page') return 'Body (Page)';
        if (type === 'post') return 'Body (Post)';
        if (type === 'header') return 'Header';
        if (type === 'footer') return 'Footer';
        return this._titleCase(type);
    },

    _escHtml: function(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    },

    _deletePost: function(id) {
        var self = this;
        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: { action: 'metis_website_post_delete', nonce: self._nonceFor('metis_website_post_delete'), id: id },
            success: function(r) {
                if (r && r.success) { metis_toast('Post deleted.', 'success'); MetisWebsite._removePostRow((r.data && r.data.id) ? r.data.id : id); }
                else { metis_toast(r.data && r.data.message ? r.data.message : 'Delete failed.', 'error'); }
            },
            error: function() { metis_toast('Request failed.', 'error'); }
        });
    },

    _publishPost: function(id) {
        var self = this;
        $.ajax({
            url: metisWebsiteAjax.ajax_url,
            type: 'POST',
            data: { action: 'metis_website_post_publish', nonce: self._nonceFor('metis_website_post_publish'), id: id },
            success: function(r) {
                if (r && r.success && r.data && r.data.post) { metis_toast('Post published.', 'success'); MetisWebsite._updatePostRow(r.data.post); }
                else { metis_toast(r.data && r.data.message ? r.data.message : 'Publish failed.', 'error'); }
            },
            error: function() { metis_toast('Request failed.', 'error'); }
        });
    },

    _maybeLaunchQuickAction: function() {
        var params;
        try {
            params = new URLSearchParams(window.location.search || '');
        } catch (_error) {
            return;
        }

        var qa = String(params.get('qa') || '');
        if (qa !== 'create_page' && qa !== 'create_post') {
            return;
        }

        if (qa === 'create_page') {
            this.openPageEditor(null);
        } else {
            this.openPostEditor(null);
        }
    },

    
};

window.MetisWebsite = MetisWebsite;
function initMetisWebsiteModule() {
    MetisWebsite.init();
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMetisWebsiteModule);
} else {
    initMetisWebsiteModule();
}
if (window.Metis && Metis.page && typeof Metis.page.register === 'function') {
    Metis.page.register('website', initMetisWebsiteModule);
}

})(window.jQuery || null);

// -----------------------------------------------------------------------
// Popup Builder (legacy removed)
// -----------------------------------------------------------------------

var MetisPopupBuilder = {
    open: function() {
        if (typeof window.metis_toast === "function") {
            window.metis_toast("Popup legacy editor has been removed. Use simple editor workflows.", "warning");
        }
    }
};
