/* ============================================================
   METIS — Core JS
   Shared utilities, UI systems, and AJAX helpers.
   Modules rely on these instead of re-implementing them.
   ============================================================ */

'use strict';

window.Metis = window.Metis || {};

function metisCsrfHeaders(token) {
    var headers = new Headers();
    headers.set('X-Requested-With', 'XMLHttpRequest');
    if (token) {
        headers.set('X-Metis-CSRF-Token', String(token));
    }
    return headers;
}

/* ============================================================
   AJAX HELPERS
   ============================================================ */

Metis.ajax = {
    url:   window.metisAjax?.ajax_url || window.mwtools_ajax_url || '/api/ajax',
    nonce: window.metisAjax?.nonce    || window.mwtools_nonce    || '',
    actionNonces: window.metisAjax?.action_nonces || {},

    nonceFor(action, fallback) {
        if (action && this.actionNonces && this.actionNonces[action]) {
            return this.actionNonces[action];
        }
        return fallback ?? this.nonce;
    },

    formData(action, data) {
        const fd = new FormData();
        const payload = data && typeof data === 'object' ? data : {};
        const actionNonce = this.nonceFor(action || payload.action, payload.metis_action_nonce);
        const legacyNonce = payload.nonce ?? this.nonce;
        fd.append('action', action || payload.action || '');
        if (actionNonce) fd.append('metis_action_nonce', actionNonce);
        if (legacyNonce) fd.append('nonce', legacyNonce);
        Object.entries(payload).forEach(([k, v]) => {
            if (k === 'action' || k === 'nonce' || k === 'metis_action_nonce') return;
            fd.append(k, v);
        });
        return fd;
    },

    parseJson(response) {
        return response.json().catch(function () {
            throw new Error('HTTP ' + response.status);
        }).then(function (payload) {
            if (!response.ok) {
                throw new Error(Metis.ajax.message(payload, 'HTTP ' + response.status));
            }
            return payload;
        });
    },

    message(payload, fallback) {
        if (payload && typeof payload === 'object') {
            if (payload.data && typeof payload.data.message === 'string') return payload.data.message;
            if (typeof payload.data === 'string') return payload.data;
            if (typeof payload.message === 'string') return payload.message;
        }
        return fallback || 'Request failed.';
    },

    /**
     * POST to the AJAX endpoint.
     * @param {Object} data  — must include `action`
     * @returns {Promise<Object>}
     */
    post(data) {
        const action = data && data.action ? data.action : '';
        const fd = this.formData(action, data || {});
        const csrf = fd.get('metis_action_nonce') || fd.get('nonce') || '';
        return fetch(this.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: metisCsrfHeaders(csrf),
            body: fd
        }).then(r => this.parseJson(r));
    },

    /** GET a JSON url */
    get(url) {
        return fetch(url, { credentials: 'same-origin' }).then(r => r.json());
    },

    prepareFetch(input, init) {
        if (typeof input !== 'string' && !(input instanceof URL)) {
            return { input, init };
        }

        const targetUrl = new URL(String(input), window.location.href);
        const ajaxUrl = new URL(this.url, window.location.href);
        if (targetUrl.pathname !== ajaxUrl.pathname) {
            return { input, init };
        }

        const options = Object.assign({ method: 'GET' }, init || {});
        const method = String(options.method || 'GET').toUpperCase();
        const headers = new Headers(options.headers || {});

        if (method === 'POST' || method === 'PATCH' || method === 'DELETE') {
            headers.set('X-Requested-With', 'XMLHttpRequest');
        }

        if (method !== 'POST' || !options.body) {
            options.headers = headers;
            return { input, init: options };
        }

        if (options.body instanceof FormData) {
            const action = options.body.get('action') || '';
            if (action && !options.body.has('metis_action_nonce')) {
                options.body.set('metis_action_nonce', this.nonceFor(String(action), ''));
            }
            if (action && !options.body.has('nonce') && this.nonce) {
                options.body.set('nonce', this.nonce);
            }
            const csrf = options.body.get('metis_action_nonce') || options.body.get('nonce') || '';
            if (csrf) {
                headers.set('X-Metis-CSRF-Token', String(csrf));
            }
            options.headers = headers;
            return { input, init: options };
        }

        if (options.body instanceof URLSearchParams) {
            const action = options.body.get('action') || '';
            if (action && !options.body.has('metis_action_nonce')) {
                options.body.set('metis_action_nonce', this.nonceFor(String(action), ''));
            }
            if (action && !options.body.has('nonce') && this.nonce) {
                options.body.set('nonce', this.nonce);
            }
            const csrf = options.body.get('metis_action_nonce') || options.body.get('nonce') || '';
            if (csrf) {
                headers.set('X-Metis-CSRF-Token', String(csrf));
            }
            options.headers = headers;
            return { input, init: options };
        }

        options.headers = headers;
        return { input, init: options };
    }
};

if (window.fetch && !window.fetch._metisWrapped) {
    const metisFetch = window.fetch.bind(window);
    const wrappedFetch = function(input, init) {
        const prepared = Metis.ajax.prepareFetch(input, init);
        return metisFetch(prepared.input, prepared.init);
    };
    wrappedFetch._metisWrapped = true;
    window.fetch = wrappedFetch;
}

/* Legacy compat alias */
window.mwtools = window.mwtools || {};
mwtools.ajaxUrl  = Metis.ajax.url;
mwtools.nonce    = Metis.ajax.nonce;
mwtools.postJSON = d => Metis.ajax.post(d);
mwtools.getJSON  = u => Metis.ajax.get(u);

/* ============================================================
   SHARED UI / REQUEST HELPERS
   ============================================================ */

Metis.util = (function() {

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalize(value) {
        return String(value == null ? '' : value).toLowerCase().trim();
    }

    function notify(message, type, options) {
        var kind = type || 'success';
        if (!window.Metis || !Metis.toast || typeof Metis.toast[kind] !== 'function') {
            if (message && window.console && typeof window.console.warn === 'function') {
                window.console.warn(String(message));
            }
            return;
        }
        return Metis.toast[kind](String(message || ''), options);
    }

    return {
        escapeHtml: escapeHtml,
        normalize: normalize,
        notify: notify
    };

}());

Metis.request = (function() {

    function resolveConfig(config, fallbackMessage) {
        var resolved = config || window.metisAjax || null;
        if (!resolved || !resolved.ajax_url || !resolved.nonce) {
            throw new Error(fallbackMessage || 'AJAX not configured.');
        }
        return resolved;
    }

    function unwrap(json) {
        if (!json || !json.success) {
            throw new Error(Metis.ajax.message(json));
        }
        return json.data || {};
    }

    function resolvedNonceFor(resolved, action) {
        var nonces = resolved && resolved.action_nonces ? resolved.action_nonces : {};
        if (action && nonces && nonces[action]) {
            return String(nonces[action]);
        }
        return resolved.nonce;
    }

    function post(config, action, payload, fallbackMessage) {
        var resolved = resolveConfig(config, fallbackMessage);
        var body = Metis.ajax.formData(action, payload || {});
        body.set('metis_action_nonce', resolvedNonceFor(resolved, action));
        if (!body.has('nonce')) {
            body.set('nonce', resolved.nonce);
        }
        var csrf = body.get('metis_action_nonce') || body.get('nonce') || '';
        return fetch(resolved.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: metisCsrfHeaders(csrf),
            body: body
        }).then(function(res) {
            return Metis.ajax.parseJson(res);
        }).then(unwrap);
    }

    function postForm(config, action, formData, fallbackMessage) {
        var resolved = resolveConfig(config, fallbackMessage);
        var body = formData instanceof FormData ? formData : new FormData();
        body.set('action', action);
        body.set('metis_action_nonce', resolvedNonceFor(resolved, action));
        if (!body.has('nonce')) {
            body.set('nonce', resolved.nonce);
        }
        var csrf = body.get('metis_action_nonce') || body.get('nonce') || '';
        return fetch(resolved.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: metisCsrfHeaders(csrf),
            body: body
        }).then(function(res) {
            return Metis.ajax.parseJson(res);
        }).then(unwrap);
    }

    return {
        post: post,
        postForm: postForm
    };

}());


/* ============================================================
   TOAST NOTIFICATION SYSTEM
   Metis.toast.show(message, type, options)
   Types: 'success' | 'error' | 'warning' | 'info'
   ============================================================ */

Metis.toast = (function() {

    var container = null;

    var icons = {
        success: '✓',
        error:   '✕',
        warning: '⚠',
        info:    'ℹ'
    };

    function getContainer() {
        if (!container) {
            container = document.getElementById('mw-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'mw-toast-container';
                document.body.appendChild(container);
            }
        }
        return container;
    }

    function show(message, type, options) {
        type = type || 'info';
        options = options || {};
        var duration = options.duration !== undefined ? options.duration : 4000;
        var title    = options.title || null;

        var toast = document.createElement('div');
        toast.className = 'mw-toast mw-toast-' + type;

        var iconSpan = document.createElement('span');
        iconSpan.className = 'mw-toast-icon';
        iconSpan.textContent = icons[type] || icons.info;
        toast.appendChild(iconSpan);

        var body = document.createElement('div');
        body.className = 'mw-toast-body';
        if (title) {
            var titleEl = document.createElement('div');
            titleEl.className = 'mw-toast-title';
            titleEl.textContent = title;
            body.appendChild(titleEl);
        }
        var msgEl = document.createElement('div');
        msgEl.className = 'mw-toast-message';
        msgEl.textContent = message;
        body.appendChild(msgEl);
        toast.appendChild(body);

        var closeBtn = document.createElement('button');
        closeBtn.className = 'mw-toast-close';
        closeBtn.innerHTML = '&times;';
        closeBtn.setAttribute('aria-label', 'Dismiss');
        closeBtn.addEventListener('click', function() { dismiss(toast); });
        toast.appendChild(closeBtn);

        getContainer().appendChild(toast);

        if (duration > 0) {
            setTimeout(function() { dismiss(toast); }, duration);
        }

        return toast;
    }

    function dismiss(toast) {
        if (!toast || !toast.parentNode) return;
        toast.classList.add('is-leaving');
        setTimeout(function() {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 220);
    }

    return {
        show:    show,
        success: function(msg, opts) { return show(msg, 'success', opts); },
        error:   function(msg, opts) { return show(msg, 'error',   opts); },
        warning: function(msg, opts) { return show(msg, 'warning', opts); },
        info:    function(msg, opts) { return show(msg, 'info',    opts); }
    };

}());


/* ============================================================
   TAB SYSTEM
   Metis.tabs.init(containerEl)   — auto-inits on DOMContentLoaded
   Metis.tabs.activate(btn)       — programmatically activate
   ============================================================ */

Metis.tabs = (function() {

    function activate(btn) {
        var group = btn.closest('[data-tab-group]') || btn.closest('.mw-tabs') || btn.parentElement;
        var target = btn.dataset.tab;
        if (!target) return;

        /* Deactivate all in group */
        var scope = btn.closest('.mw-tab-scope') || document;
        scope.querySelectorAll('[data-tab-group="' + (group.dataset.tabGroup || '') + '"] [data-tab], .mw-tabs [data-tab]').forEach(function(b) {
            if (b.closest('.mw-tab-scope') === btn.closest('.mw-tab-scope') || scope === document) {
                b.classList.remove('is-active');
            }
        });
        /* Also handle sidebar buttons */
        if (group.classList.contains('mw-tab-sidebar')) {
            group.querySelectorAll('.mw-tab-sidebar-btn').forEach(function(b) { b.classList.remove('is-active'); });
        }

        /* Hide all matching panels */
        var panels = scope.querySelectorAll('.mw-tab-panel');
        panels.forEach(function(p) {
            if (p.dataset.tabGroup === btn.dataset.tabGroup || !btn.dataset.tabGroup) {
                p.classList.remove('is-active');
            }
        });

        /* Activate clicked */
        btn.classList.add('is-active');

        var panel = scope.querySelector('#' + target);
        if (panel) panel.classList.add('is-active');
    }

    function init(root) {
        root = root || document;
        root.querySelectorAll('[data-tab]').forEach(function(btn) {
            if (btn._metisTabInited) return;
            btn._metisTabInited = true;
            btn.addEventListener('click', function() { activate(btn); });
        });
    }

    return { init: init, activate: activate };

}());

/* ============================================================
   INLINE EDIT SYSTEM
   data-inline-field="field_name" data-inline-action="my_ajax_action"
   ============================================================ */

Metis.inlineEdit = (function() {

    function init(root) {
        root = root || document;
        root.querySelectorAll('.mw-inline-editable:not([data-inline-inited])').forEach(function(el) {
            el.setAttribute('data-inline-inited', '1');
            el.addEventListener('click', function() { startEdit(el); });
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') startEdit(el);
            });
        });
    }

    function startEdit(el) {
        if (el.classList.contains('is-editing')) return;
        el.classList.add('is-editing');
        var original = el.textContent.trim();
        var field    = el.dataset.inlineField || '';
        var input    = document.createElement('input');
        input.type  = 'text';
        input.value = original;
        input.className = 'mw-input mw-inline-edit-input';
        el.innerHTML = '';
        el.appendChild(input);
        input.focus();
        input.select();

        function save() {
            var val = input.value.trim();
            el.classList.remove('is-editing');
            el.textContent = val || original;
            if (val !== original && el.dataset.inlineAction) {
                var payload = { action: el.dataset.inlineAction, field: field, value: val };
                var rid = el.dataset.recordId;
                if (rid) payload.record_id = rid;
                Metis.ajax.post(payload).then(function(r) {
                    if (r && r.success) {
                        showSaved(el);
                        if (typeof Metis.toast !== 'undefined') Metis.toast.success('Saved');
                    } else {
                        el.textContent = original;
                        if (typeof Metis.toast !== 'undefined') Metis.toast.error((r && r.data && r.data.message) || 'Save failed');
                    }
                }).catch(function() {
                    el.textContent = original;
                    if (typeof Metis.toast !== 'undefined') Metis.toast.error('Save failed');
                });
            }
        }

        input.addEventListener('blur', save);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); save(); }
            if (e.key === 'Escape') { el.classList.remove('is-editing'); el.textContent = original; }
        });
    }

    function showSaved(el) {
        var saved = el.nextElementSibling;
        if (!saved || !saved.classList.contains('mw-inline-saved')) {
            saved = document.createElement('span');
            saved.className = 'mw-inline-saved';
            saved.textContent = '✓ Saved';
            el.insertAdjacentElement('afterend', saved);
        }
        saved.classList.add('is-visible');
        setTimeout(function() { saved.classList.remove('is-visible'); }, 1600);
    }

    return { init: init };

}());


/* ============================================================
   MODAL SYSTEM
   Metis.modal.open(id)   Metis.modal.close(id)
   ============================================================ */

Metis.modal = (function() {

    function open(id) {
        var el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el) return;
        el.classList.add('is-open', 'metis-open');
        el.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        var first = el.querySelector('input, select, textarea, button, [tabindex]');
        if (first) setTimeout(function() { first.focus(); }, 60);
    }

    function close(id) {
        var el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el) return;
        el.classList.remove('is-open', 'metis-open');
        el.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function init(root) {
        root = root || document;
        /* Open triggers: [data-modal-open="modal-id"] */
        root.querySelectorAll('[data-modal-open]').forEach(function(btn) {
            if (btn._metisModalInited) return;
            btn._metisModalInited = true;
            btn.addEventListener('click', function() { open(btn.dataset.modalOpen); });
        });

        /* Close triggers: [data-modal-close] or .mw-modal-close */
        root.querySelectorAll('[data-modal-close], .mw-modal-close').forEach(function(btn) {
            if (btn._metisModalCloseInited) return;
            btn._metisModalCloseInited = true;
            btn.addEventListener('click', function() {
                var target = btn.dataset.modalClose;
                var backdrop = target ? document.getElementById(target) : btn.closest('.mw-modal-backdrop, .metis-contacts-modal');
                if (backdrop) close(backdrop);
            });
        });

        /* Close on backdrop click */
        root.querySelectorAll('.mw-modal-backdrop, .metis-contacts-modal').forEach(function(backdrop) {
            if (backdrop._metisBackdropInited) return;
            backdrop._metisBackdropInited = true;
            backdrop.addEventListener('click', function(e) {
                if (e.target === backdrop) close(backdrop);
            });
        });

        /* Escape key */
        if (!document._metisEscInited) {
            document._metisEscInited = true;
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Escape') return;
                var open = document.querySelector('.mw-modal-backdrop.is-open, .metis-contacts-modal.metis-open, .mw-modal-backdrop.metis-open');
                if (open) close(open);
            });
        }
    }

    return { open: open, close: close, init: init };

}());

/* ============================================================
   ACCESSIBILITY PREFERENCES
   ============================================================ */

Metis.accessibility = (function() {

    var toggles = ['contrast', 'large_text', 'readable_font', 'reduced_motion', 'underline_links', 'nav_labels'];

    function config() {
        return window.metisAccessibility || {
            toolbarEnabled: false,
            allowOverrides: false,
            defaultProfile: 'none',
            storageKey: 'metis-accessibility-preferences',
            profiles: {}
        };
    }

    function truthy(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function profilePreferences(profile) {
        var profiles = config().profiles || {};
        var entry = profiles[String(profile || '')] || null;
        return entry && typeof entry === 'object' && entry.preferences ? entry.preferences : {};
    }

    function normalize(prefs) {
        var resolved = { profile: String((prefs && prefs.profile) || config().defaultProfile || 'none') };
        var profilePrefs = profilePreferences(resolved.profile);
        toggles.forEach(function(key) {
            resolved[key] = truthy(profilePrefs[key]) || truthy(prefs && prefs[key]);
        });
        return resolved;
    }

    function apply(prefs) {
        var resolved = normalize(prefs);
        toggles.forEach(function(key) {
            document.documentElement.setAttribute('data-mw-' + key.replace(/_/g, '-'), resolved[key] ? 'true' : 'false');
        });
        document.documentElement.setAttribute('data-mw-profile', resolved.profile);
        return resolved;
    }

    function load() {
        var resolved = { profile: config().defaultProfile || 'none' };
        if (!config().allowOverrides) {
            return apply(resolved);
        }
        try {
            var raw = window.localStorage.getItem(String(config().storageKey || ''));
            if (raw) {
                resolved = Object.assign(resolved, JSON.parse(raw) || {});
            }
        } catch (e) {}
        return apply(resolved);
    }

    function save(prefs) {
        var resolved = apply(prefs);
        if (!config().allowOverrides) {
            return resolved;
        }
        try {
            window.localStorage.setItem(String(config().storageKey || ''), JSON.stringify(resolved));
        } catch (e) {}
        return resolved;
    }

    function reset() {
        try {
            window.localStorage.removeItem(String(config().storageKey || ''));
        } catch (e) {}
        return apply({ profile: config().defaultProfile || 'none' });
    }

    function syncForm(panel, prefs) {
        if (!panel) return;
        var profile = panel.querySelector('[data-accessibility-profile]');
        if (profile) {
            profile.value = String(prefs.profile || 'none');
        }
        toggles.forEach(function(key) {
            var input = panel.querySelector('[data-accessibility-pref="' + key + '"]');
            if (input) {
                input.checked = !!prefs[key];
            }
        });
    }

    function profileChanged(panel, value) {
        var next = { profile: String(value || 'none') };
        var profilePrefs = profilePreferences(next.profile);
        toggles.forEach(function(key) {
            next[key] = truthy(profilePrefs[key]);
        });
        var saved = save(next);
        syncForm(panel, saved);
    }

    function init() {
        var current = load();
        var toggle = document.getElementById('mw-accessibility-toggle');
        var panel = document.getElementById('mw-accessibility-panel');
        if (!toggle || !panel || !config().toolbarEnabled || !config().allowOverrides) {
            return;
        }

        syncForm(panel, current);

        function closePanel() {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }

        function openPanel() {
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
        }

        toggle.addEventListener('click', function() {
            if (panel.hidden) {
                openPanel();
            } else {
                closePanel();
            }
        });

        panel.querySelectorAll('[data-accessibility-close]').forEach(function(button) {
            button.addEventListener('click', closePanel);
        });

        var profile = panel.querySelector('[data-accessibility-profile]');
        if (profile) {
            profile.addEventListener('change', function() {
                profileChanged(panel, profile.value);
            });
        }

        toggles.forEach(function(key) {
            var input = panel.querySelector('[data-accessibility-pref="' + key + '"]');
            if (!input) return;
            input.addEventListener('change', function() {
                var prefs = { profile: profile ? profile.value : (document.documentElement.getAttribute('data-mw-profile') || 'none') };
                toggles.forEach(function(name) {
                    var checkbox = panel.querySelector('[data-accessibility-pref="' + name + '"]');
                    prefs[name] = !!(checkbox && checkbox.checked);
                });
                save(prefs);
            });
        });

        var resetButton = panel.querySelector('[data-accessibility-reset]');
        if (resetButton) {
            resetButton.addEventListener('click', function() {
                syncForm(panel, reset());
            });
        }

        document.addEventListener('click', function(event) {
            if (!panel.hidden && !event.target.closest('.mw-accessibility')) {
                closePanel();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !panel.hidden) {
                closePanel();
            }
        });
    }

    return {
        init: init,
        load: load,
        save: save,
        reset: reset
    };

}());

/* ============================================================
   SIDEBAR NAV
   ============================================================ */

Metis.nav = (function() {

    function init() {
        var toggle  = document.querySelector('.mw-nav-toggle');
        var sidebar = document.getElementById('mw-sidebar');
        var overlay = document.getElementById('mw-sidebar-overlay');
        if (!sidebar) return;

        /* Mobile: hamburger opens sidebar */
        if (toggle) {
            toggle.addEventListener('click', function() {
                var open = sidebar.classList.toggle('is-open');
                if (overlay) overlay.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }

        /* Overlay click closes sidebar */
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('is-open');
                overlay.classList.remove('is-open');
            });
        }

        function closeGroup(group) {
            group.classList.remove('is-open');
            var trigger = group.querySelector('.mw-sidebar-group-trigger');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
        }

        function openGroup(group) {
            sidebar.querySelectorAll('.mw-sidebar-group.is-open').forEach(function(openGroupEl) {
                if (openGroupEl !== group) {
                    closeGroup(openGroupEl);
                }
            });
            group.classList.add('is-open');
            var trigger = group.querySelector('.mw-sidebar-group-trigger');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'true');
            }
        }

        sidebar.querySelectorAll('.mw-sidebar-group').forEach(function(group) {
            var trigger = group.querySelector('.mw-sidebar-group-trigger');
            var submenu  = group.querySelector('.mw-sidebar-submenu');
            var closeTimer = null;
            if (!trigger || !submenu) return;

            function isOverGroupOrMenu(x, y) {
                var el = document.elementFromPoint(x, y);
                return el && (group.contains(el) || (submenu && submenu.contains(el)));
            }

            function hoverOpen() {
                clearTimeout(closeTimer);
                openGroup(group);
            }

            function onMouseMove(e) {
                if (!group.classList.contains('is-open')) return;
                clearTimeout(closeTimer);
                if (!isOverGroupOrMenu(e.clientX, e.clientY)) {
                    closeTimer = setTimeout(function() {
                        if (!isOverGroupOrMenu(lastX, lastY)) {
                            closeGroup(group);
                        }
                    }, 120);
                }
            }

            var lastX = 0, lastY = 0;
            document.addEventListener('mousemove', function(e) { lastX = e.clientX; lastY = e.clientY; });

            group.addEventListener('mouseenter', function() {
                hoverOpen();
                document.addEventListener('mousemove', onMouseMove);
            });

            group.addEventListener('mouseleave', function() {
                /* Don’t remove listener immediately — onMouseMove handles close */
            });

            submenu.addEventListener('mouseenter', function() { clearTimeout(closeTimer); });

            trigger.addEventListener('click', function() {
                if (group.classList.contains('is-open')) {
                    closeGroup(group);
                } else {
                    openGroup(group);
                }
            });

            trigger.addEventListener('keydown', function(event) {
                if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openGroup(group);
                    var firstItem = submenu.querySelector('a, button, [tabindex]:not([tabindex="-1"])');
                    if (firstItem) firstItem.focus();
                }
            });

            submenu.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeGroup(group);
                    trigger.focus();
                }
            });
        });

        /* Close open groups when clicking outside */
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mw-sidebar-group')) {
                sidebar.querySelectorAll('.mw-sidebar-group.is-open').forEach(function(g) {
                    closeGroup(g);
                });
            }
        });
    }

    return { init: init };

}());

/* ============================================================
   CLICKABLE ROWS
   ============================================================ */

function mwInitClickableRows(root) {
    root = root || document;
    root.addEventListener('click', function(e) {
        var row = e.target.closest('.mw-clickable-row');
        if (!row || !row.dataset.href) return;
        /* Don't navigate if click was on a button or link */
        if (e.target.closest('a, button, input, select, .mw-btn')) return;
        window.location = row.dataset.href;
    });
}

/* ============================================================
   NAV PILLS — DESKTOP DROPDOWN
   ============================================================ */

function mwInitNavDropdowns() {
    document.querySelectorAll('.mw-pill-dropdown').forEach(function(dropdown) {
        var btn   = dropdown.querySelector('.mw-pill-has-dropdown');
        var panel = dropdown.querySelector('.mw-dropdown-panel');
        if (!btn || !panel) return;

        var closeTimer = null; // per-dropdown timer

        function openDropdown() {
            clearTimeout(closeTimer);
            // Close all others first
            document.querySelectorAll('.mw-pill-dropdown.is-open').forEach(function(d) {
                if (d !== dropdown) d.classList.remove('is-open');
            });
            dropdown.classList.add('is-open');
        }

        function scheduleClose() {
            clearTimeout(closeTimer);
            closeTimer = setTimeout(function() {
                dropdown.classList.remove('is-open');
            }, 200); // increased from 120 to 200ms
        }

        function cancelClose() {
            clearTimeout(closeTimer);
        }

        // Desktop: open on hover, track both button and panel
        dropdown.addEventListener('mouseenter', openDropdown);
        dropdown.addEventListener('mouseleave', scheduleClose);
        panel.addEventListener('mouseenter',    cancelClose);
        panel.addEventListener('mouseleave',    scheduleClose);

        // Mobile / click fallback
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (dropdown.classList.contains('is-open')) {
                dropdown.classList.remove('is-open');
            } else {
                openDropdown();
            }
        });
    });

    // Click outside closes all
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.mw-pill-dropdown')) {
            document.querySelectorAll('.mw-pill-dropdown.is-open').forEach(function(d) {
                d.classList.remove('is-open');
            });
        }
    });
}


/* ============================================================
   UNIVERSAL OBJECT CODE SEARCH
   ============================================================ */

Metis.codeSearch = (function() {

    var debounceTimer = null;
    var lastQuery     = '';

    function escHtml(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function escAttr(str) {
        return String(str).replace(/"/g, '&quot;');
    }

    function hideResult(result) {
        result.style.display = 'none';
        result.innerHTML = '';
    }

    function showError(result, msg) {
        result.className = 'mw-code-result mw-code-error';
        result.textContent = msg;
        result.style.display = 'block';
    }

    function showResult(result, data) {
        result.className = 'mw-code-result';
        result.innerHTML =
            '<div class="mw-code-result-label">' + escHtml(data.label) + '</div>' +
            '<div class="mw-code-result-code">' + escHtml(data.code) + '</div>' +
            (data.url
                ? '<a class="mw-code-result-link" href="' + escAttr(data.url) + '">Open &rarr;</a>'
                : '<span style="color:#b45309;font-size:12px;">No URL for this code.</span>'
            );
        result.style.display = 'block';
    }

    function doLookup(input, result, code) {
        code = code.toUpperCase().trim();
        if (code === lastQuery || code.length < 4) {
            if (code.length < 4) hideResult(result);
            return;
        }
        lastQuery = code;

        Metis.ajax.post({ action: 'metis_resolve_code', code: code })
            .then(function(r) {
                if (r.success) {
                    showResult(result, r.data);
                } else {
                    showError(result, (r.data && r.data.message) ? r.data.message : 'Code not found.');
                }
            })
            .catch(function() { showError(result, 'Lookup failed.'); });
    }

    function init() {
        var input  = document.getElementById('metis-code-input');
        var result = document.getElementById('metis-code-result');
        if (!input || !result) return;

        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            var val = this.value.trim();
            if (val.length < 4) { hideResult(result); lastQuery = ''; return; }
            debounceTimer = setTimeout(function() { doLookup(input, result, val); }, 350);
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { clearTimeout(debounceTimer); doLookup(input, result, this.value); }
            if (e.key === 'Escape') { hideResult(result); this.blur(); }
        });

        input.addEventListener('paste', function() {
            var self = this;
            setTimeout(function() {
                var val = self.value.trim();
                if (val.length >= 6) { clearTimeout(debounceTimer); doLookup(input, result, val); }
            }, 50);
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('#metis-code-search')) { hideResult(result); lastQuery = ''; }
        });
    }

    return { init: init };

}());

/* ============================================================
   BREADCRUMB HELPER
   Metis.breadcrumb.render(containerId, items)
   items = [{label, url?}, ...]  last item has no url
   ============================================================ */

Metis.breadcrumb = {
    render: function(containerId, items) {
        var el = document.getElementById(containerId);
        if (!el) return;
        var html = '';
        items.forEach(function(item, i) {
            if (i > 0) html += '<span class="mw-breadcrumb-sep">/</span>';
            if (item.url && i < items.length - 1) {
                html += '<a href="' + item.url + '">' + item.label + '</a>';
            } else {
                html += '<span class="mw-breadcrumb-current">' + item.label + '</span>';
            }
        });
        el.innerHTML = html;
    }
};

/* ============================================================
   DOM-READY BOOTSTRAP
   ============================================================ */

document.addEventListener('DOMContentLoaded', function() {
    Metis.accessibility.init();
    Metis.tabs.init(document);
    Metis.modal.init(document);
    Metis.inlineEdit.init(document);
    Metis.nav.init();
    Metis.codeSearch.init();
    mwInitClickableRows(document);
});
