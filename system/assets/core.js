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

function metisResolveAjaxUrl() {
    if (window.metisAjax && window.metisAjax.ajax_url) {
        return window.metisAjax.ajax_url;
    }

    var currentScript = document.currentScript;
    var scriptSource = currentScript && currentScript.src ? currentScript.src : '';
    if (!scriptSource) {
        var coreScript = document.querySelector('script[src*="/assets/core.js"]');
        scriptSource = coreScript && coreScript.src ? coreScript.src : '';
    }

    if (scriptSource) {
        try {
            var scriptUrl = new URL(scriptSource, window.location.href);
            var scriptPath = scriptUrl.pathname.replace(/\/+$/, '');
            var assetIndex = scriptPath.lastIndexOf('/assets/core.js');
            if (assetIndex >= 0) {
                return scriptPath.slice(0, assetIndex) + '/api/ajax';
            }
        } catch (error) {
            /* Ignore malformed script URLs and fall back to request path handling. */
        }
    }

    var path = String(window.location.pathname || '/');
    var normalized = path.replace(/\/+$/, '');
    var ajaxIndex = normalized.indexOf('/api/ajax');
    if (ajaxIndex >= 0) {
        return normalized.slice(0, ajaxIndex) + '/api/ajax';
    }

    return '/api/ajax';
}

function metisAuthFailureCode(payload) {
    if (!payload || typeof payload !== 'object') return '';
    if (payload.data && typeof payload.data === 'object' && typeof payload.data.code === 'string') {
        return String(payload.data.code || '').toLowerCase();
    }
    if (typeof payload.code === 'string') {
        return String(payload.code || '').toLowerCase();
    }
    return '';
}

function metisIsSessionAuthFailure(response, payload) {
    if (!response || (response.status !== 401 && response.status !== 403)) {
        return false;
    }
    var code = metisAuthFailureCode(payload);
    if (code === 'authentication_required' || code === 'invalid_session' || code === 'session_expired' || code === 'session_required') {
        return true;
    }
    var message = '';
    if (payload && payload.data && typeof payload.data.message === 'string') {
        message = payload.data.message;
    } else if (payload && typeof payload.message === 'string') {
        message = payload.message;
    }
    var normalized = String(message || '').toLowerCase();
    return normalized.indexOf('authentication required') >= 0 || normalized.indexOf('invalid session') >= 0;
}

/* ============================================================
   AJAX HELPERS
   ============================================================ */

Metis.ajax = {
    url:   metisResolveAjaxUrl(),
    ajax_url: metisResolveAjaxUrl(),
    nonce: window.metisAjax?.nonce    || '',
    actionNonces: window.metisAjax?.action_nonces || {},
    action_nonces: window.metisAjax?.action_nonces || {},

    nonceFor(action, fallback) {
        if (action && this.actionNonces && this.actionNonces[action]) {
            return this.actionNonces[action];
        }
        return fallback ?? this.nonce;
    },

    formData(action, data) {
        const fd = new FormData();
        const payload = data && typeof data === 'object' ? data : {};
        const resolvedAction = action || payload.action || '';
        const actionNonce = this.nonceFor(resolvedAction, payload.metis_action_nonce);
        const legacyNonce = payload.nonce ?? this.nonce;
        const csrfAction = payload.metis_csrf_action || (resolvedAction ? ('metis_ajax:' + resolvedAction) : '');
        fd.append('action', resolvedAction);
        if (csrfAction) fd.append('metis_csrf_action', csrfAction);
        if (actionNonce) fd.append('metis_action_nonce', actionNonce);
        if (legacyNonce) fd.append('nonce', legacyNonce);
        Object.entries(payload).forEach(([k, v]) => {
            if (k === 'action' || k === 'nonce' || k === 'metis_action_nonce' || k === 'metis_csrf_action') return;
            fd.append(k, v);
        });
        return fd;
    },

    parseJson(response) {
        return response.json().catch(function () {
            throw new Error('HTTP ' + response.status);
        }).then(function (payload) {
            if (!response.ok) {
                if (metisIsSessionAuthFailure(response, payload) && window.Metis && Metis.session && typeof Metis.session.onAuthFailure === 'function') {
                    Metis.session.onAuthFailure(payload, response);
                }
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

/* ============================================================
   TIME HELPERS
   ============================================================ */

Metis.time = {
    settings: (window.metisAjax && window.metisAjax.time && typeof window.metisAjax.time === 'object')
        ? window.metisAjax.time
        : {},

    timezone() {
        return String(this.settings.timezone || 'UTC').trim() || 'UTC';
    },

    dateFormat() {
        return String(this.settings.date_format || 'm/d/y').trim() || 'm/d/y';
    },

    timeFormat() {
        return String(this.settings.time_format || 'g:i:s a').trim() || 'g:i:s a';
    },

    datetimeFormat() {
        return String(this.settings.datetime_format || (this.dateFormat() + ' ' + this.timeFormat())).trim();
    },

    zoneOffsetMs(date) {
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: this.timezone(),
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23'
        });
        const parts = formatter.formatToParts(date).reduce(function (carry, part) {
            if (part.type !== 'literal') carry[part.type] = part.value;
            return carry;
        }, {});
        const asUtc = Date.UTC(
            Number(parts.year || '1970'),
            Math.max(0, Number(parts.month || '1') - 1),
            Number(parts.day || '1'),
            Number(parts.hour || '0'),
            Number(parts.minute || '0'),
            Number(parts.second || '0')
        );
        return asUtc - date.getTime();
    },

    parseNaive(value) {
        const match = String(value || '').trim().match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
        if (!match) return null;

        const wallClockUtc = Date.UTC(
            Number(match[1]),
            Math.max(0, Number(match[2]) - 1),
            Number(match[3]),
            Number(match[4] || '0'),
            Number(match[5] || '0'),
            Number(match[6] || '0')
        );
        const firstPass = wallClockUtc - this.zoneOffsetMs(new Date(wallClockUtc));
        const secondPass = wallClockUtc - this.zoneOffsetMs(new Date(firstPass));
        const date = new Date(secondPass);
        return Number.isNaN(date.getTime()) ? null : date;
    },

    parse(value) {
        if (value instanceof Date) return value;
        const raw = String(value || '').trim();
        if (!raw) return null;
        if (/^\d+$/.test(raw) && raw.length >= 10) {
            const date = new Date(Number(raw) * (raw.length === 10 ? 1000 : 1));
            return Number.isNaN(date.getTime()) ? null : date;
        }
        if (!/(?:Z|[+-]\d{2}:?\d{2})$/i.test(raw)) {
            const naive = this.parseNaive(raw);
            if (naive) return naive;
        }
        const normalized = raw.indexOf('T') >= 0 ? raw : raw.replace(' ', 'T');
        const date = new Date(normalized);
        return Number.isNaN(date.getTime()) ? null : date;
    },

    intlOptions(format, fallback) {
        const fmt = String(format || '').trim();
        const has = function (token) { return fmt.indexOf(token) >= 0; };
        const options = Object.assign({ timeZone: this.timezone() }, fallback || {});

        if (has('l')) options.weekday = 'long';
        else if (has('D')) options.weekday = 'short';

        if (has('Y')) options.year = 'numeric';
        else if (has('y')) options.year = '2-digit';

        if (has('F')) options.month = 'long';
        else if (has('M')) options.month = 'short';
        else if (has('m')) options.month = '2-digit';
        else if (has('n')) options.month = 'numeric';

        if (has('d')) options.day = '2-digit';
        else if (has('j')) options.day = 'numeric';

        if (has('H') || has('h')) options.hour = '2-digit';
        else if (has('G') || has('g')) options.hour = 'numeric';

        if (has('i')) options.minute = '2-digit';
        if (has('s')) options.second = '2-digit';

        if (has('a') || has('A') || has('g') || has('h')) options.hour12 = true;
        if (has('G') || has('H')) options.hour12 = false;

        return options;
    },

    format(value, options) {
        const date = this.parse(value);
        if (!date) return String((options && options.empty) || '');
        const format = options && options.format ? String(options.format) : this.datetimeFormat();
        return new Intl.DateTimeFormat(undefined, this.intlOptions(format)).format(date);
    },

    formatDate(value, options) {
        const date = this.parse(value);
        if (!date) return String((options && options.empty) || '');
        const format = options && options.format ? String(options.format) : this.dateFormat();
        return new Intl.DateTimeFormat(undefined, this.intlOptions(format, { year: 'numeric', month: 'numeric', day: 'numeric' })).format(date);
    },

    formatTime(value, options) {
        const date = this.parse(value);
        if (!date) return String((options && options.empty) || '');
        const format = options && options.format ? String(options.format) : this.timeFormat();
        return new Intl.DateTimeFormat(undefined, this.intlOptions(format, { hour: 'numeric', minute: '2-digit' })).format(date);
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

/* ============================================================
   SESSION LIVENESS + AUTH REDIRECT
   ============================================================ */

Metis.session = (function() {
    var state = {
        authenticated: false,
        issuedAt: 0,
        lastActivityAt: 0,
        idleTtl: 1800,
        absoluteTtl: 43200,
        idleExpiresAt: 0,
        absoluteExpiresAt: 0,
        lastInteractionMs: 0,
        lastInteractionRecordMs: 0,
        lastPingAtSec: 0,
        tickTimer: null,
        redirecting: false,
        warningCycleKey: '',
        warningShown: false
    };

    function nowSec() {
        return Math.floor(Date.now() / 1000);
    }

    function config() {
        if (window.metisAjax && window.metisAjax.session && typeof window.metisAjax.session === 'object') {
            return window.metisAjax.session;
        }
        return {};
    }

    function normalizeInt(value, fallback) {
        var parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function loginUrlWithRedirect() {
        var cfg = config();
        var rawLoginUrl = cfg.login_url ? String(cfg.login_url) : '/login';

        try {
            var login = new URL(rawLoginUrl, window.location.origin);
            login.searchParams.set('redirect_to', window.location.href);
            return login.toString();
        } catch (error) {
            var separator = rawLoginUrl.indexOf('?') >= 0 ? '&' : '?';
            return rawLoginUrl + separator + 'redirect_to=' + encodeURIComponent(window.location.href);
        }
    }

    function redirectToLogin() {
        if (state.redirecting) {
            return;
        }
        state.redirecting = true;
        window.location.assign(loginUrlWithRedirect());
    }

    function syncExpirations() {
        state.idleExpiresAt = state.lastActivityAt > 0 ? state.lastActivityAt + state.idleTtl : 0;
        state.absoluteExpiresAt = state.issuedAt > 0 ? state.issuedAt + state.absoluteTtl : 0;
    }

    function warningLeadSeconds() {
        return 120;
    }

    function currentExpirySec() {
        var idle = state.idleExpiresAt > 0 ? state.idleExpiresAt : 0;
        var absolute = state.absoluteExpiresAt > 0 ? state.absoluteExpiresAt : 0;
        if (idle > 0 && absolute > 0) {
            return Math.min(idle, absolute);
        }
        return idle > 0 ? idle : absolute;
    }

    function resetWarningCycleIfNeeded() {
        var expiry = currentExpirySec();
        var cycleKey = String(expiry || '');
        if (cycleKey !== state.warningCycleKey) {
            state.warningCycleKey = cycleKey;
            state.warningShown = false;
        }
    }

    function formatRemaining(seconds) {
        var remaining = Math.max(1, Math.floor(seconds));
        var mins = Math.floor(remaining / 60);
        var secs = remaining % 60;
        if (mins <= 0) {
            return secs + 's';
        }
        if (secs === 0) {
            return mins + 'm';
        }
        return mins + 'm ' + secs + 's';
    }

    function maybeWarnExpiry() {
        if (!state.authenticated || state.redirecting || !window.Metis || !Metis.toast || typeof Metis.toast.warning !== 'function') {
            return;
        }
        resetWarningCycleIfNeeded();
        if (state.warningShown) {
            return;
        }
        var expiry = currentExpirySec();
        if (expiry <= 0) {
            return;
        }
        var remaining = expiry - nowSec();
        if (remaining > 0 && remaining <= warningLeadSeconds()) {
            state.warningShown = true;
            Metis.toast.warning('Your session will expire in ' + formatRemaining(remaining) + '. Keep working to stay signed in.', {
                title: 'Session Expiring Soon',
                duration: 8000
            });
        }
    }

    function applyServerState(data) {
        if (!data || typeof data !== 'object') {
            return;
        }

        if (typeof data.authenticated !== 'undefined') {
            state.authenticated = !!data.authenticated;
        }
        state.issuedAt = Math.max(0, normalizeInt(data.issued_at, state.issuedAt));
        state.lastActivityAt = Math.max(0, normalizeInt(data.last_activity_at, state.lastActivityAt));
        state.idleTtl = Math.max(60, normalizeInt(data.idle_ttl, state.idleTtl));
        state.absoluteTtl = Math.max(60, normalizeInt(data.absolute_ttl, state.absoluteTtl));
        state.idleExpiresAt = Math.max(0, normalizeInt(data.idle_expires_at, 0));
        state.absoluteExpiresAt = Math.max(0, normalizeInt(data.absolute_expires_at, 0));

        if (state.idleExpiresAt <= 0 || state.absoluteExpiresAt <= 0) {
            syncExpirations();
        }
    }

    function keepaliveLeadSeconds() {
        return Math.max(60, Math.min(300, Math.floor(state.idleTtl * 0.2)));
    }

    function activeWindowSeconds() {
        return Math.max(90, Math.min(360, keepaliveLeadSeconds() + 60));
    }

    function recordInteraction() {
        var nowMs = Date.now();
        if (nowMs - state.lastInteractionRecordMs < 1500) {
            return;
        }
        state.lastInteractionRecordMs = nowMs;
        state.lastInteractionMs = nowMs;
    }

    function hasRecentInteraction() {
        if (state.lastInteractionMs <= 0) {
            return false;
        }
        return (Date.now() - state.lastInteractionMs) <= (activeWindowSeconds() * 1000);
    }

    function shouldPingKeepalive() {
        if (!state.authenticated) {
            return false;
        }
        if (document.hidden) {
            return false;
        }
        if (!hasRecentInteraction()) {
            return false;
        }
        var now = nowSec();
        if (state.lastPingAtSec > 0 && (now - state.lastPingAtSec) < 30) {
            return false;
        }
        if (state.idleExpiresAt <= 0) {
            return true;
        }
        return (state.idleExpiresAt - now) <= keepaliveLeadSeconds();
    }

    function isExpired() {
        if (!state.authenticated) {
            return false;
        }
        var now = nowSec();
        if (state.absoluteExpiresAt > 0 && now >= state.absoluteExpiresAt) {
            return true;
        }
        if (state.idleExpiresAt > 0 && now >= state.idleExpiresAt) {
            return true;
        }
        return false;
    }

    function onAuthFailure(payload, response) {
        if (!metisIsSessionAuthFailure(response, payload)) {
            return;
        }
        redirectToLogin();
    }

    function pingKeepalive() {
        var cfg = config();
        var keepaliveUrl = cfg.keepalive_url ? String(cfg.keepalive_url) : '/api/auth/session/keepalive';
        state.lastPingAtSec = nowSec();

        return fetch(keepaliveUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: metisCsrfHeaders(Metis.ajax.nonce || '')
        })
            .then(function(response) {
                return response.json().catch(function() {
                    return {};
                }).then(function(payload) {
                    if (!response.ok) {
                        onAuthFailure(payload, response);
                        return;
                    }
                    var data = payload && payload.data && typeof payload.data === 'object' ? payload.data : {};
                    applyServerState(data);
                });
            })
            .catch(function() {
                /* Ignore transient network errors; next tick will retry if needed. */
            });
    }

    function tick() {
        if (!state.authenticated) {
            return;
        }

        maybeWarnExpiry();

        if (isExpired()) {
            redirectToLogin();
            return;
        }

        if (shouldPingKeepalive()) {
            pingKeepalive();
        }
    }

    function bindActivityListeners() {
        ['click', 'keydown', 'touchstart', 'pointerdown', 'scroll', 'mousemove'].forEach(function(eventName) {
            window.addEventListener(eventName, recordInteraction, { passive: true });
        });
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                recordInteraction();
                tick();
            }
        });
    }

    function init() {
        var cfg = config();
        applyServerState(cfg);
        state.authenticated = !!cfg.authenticated;
        state.lastInteractionMs = Date.now();
        state.lastInteractionRecordMs = 0;
        state.warningCycleKey = '';
        state.warningShown = false;

        if (!state.authenticated) {
            return;
        }

        bindActivityListeners();
        if (state.tickTimer) {
            clearInterval(state.tickTimer);
        }
        state.tickTimer = window.setInterval(tick, 15000);
        tick();
    }

    return {
        init: init,
        onAuthFailure: onAuthFailure,
        redirectToLogin: redirectToLogin
    };
}());

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

    function sanitizeHtmlFragment(value) {
        var template = document.createElement('template');
        template.innerHTML = String(value == null ? '' : value);

        template.content.querySelectorAll('script, iframe, object, embed, link[rel="import"]').forEach(function(node) {
            node.remove();
        });

        template.content.querySelectorAll('*').forEach(function(node) {
            Array.prototype.slice.call(node.attributes || []).forEach(function(attribute) {
                var name = String(attribute.name || '').toLowerCase();
                var rawValue = String(attribute.value || '');
                var normalizedValue = rawValue.replace(/[\u0000-\u001F\u007F\s]+/g, '').toLowerCase();

                if (name.indexOf('on') === 0) {
                    node.removeAttribute(attribute.name);
                    return;
                }

                if ((name === 'href' || name === 'src' || name === 'xlink:href' || name === 'formaction')
                    && normalizedValue.indexOf('javascript:') === 0) {
                    node.removeAttribute(attribute.name);
                }
            });
        });

        return template.innerHTML;
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
        sanitizeHtmlFragment: sanitizeHtmlFragment,
        normalize: normalize,
        notify: notify
    };

}());

Metis.request = (function() {

    function resolveConfig(config, fallbackMessage) {
        var coreAjax = (window.Metis && Metis.ajax) ? Metis.ajax : null;
        var globalAjax = (window.metisAjax && typeof window.metisAjax === 'object') ? window.metisAjax : {};
        var specificAjax = (config && typeof config === 'object') ? config : {};
        var actionNonces = Object.assign(
            {},
            (coreAjax && (coreAjax.action_nonces || coreAjax.actionNonces)) || {},
            globalAjax.action_nonces || {},
            specificAjax.action_nonces || {},
            specificAjax.actionNonces || {}
        );
        var resolved = Object.assign({}, coreAjax || {}, globalAjax, specificAjax, {
            ajax_url: String(
                specificAjax.ajax_url
                || specificAjax.url
                || globalAjax.ajax_url
                || globalAjax.url
                || (coreAjax && (coreAjax.ajax_url || coreAjax.url))
                || metisResolveAjaxUrl()
                || ''
            ).trim(),
            url: String(
                specificAjax.ajax_url
                || specificAjax.url
                || globalAjax.ajax_url
                || globalAjax.url
                || (coreAjax && (coreAjax.ajax_url || coreAjax.url))
                || metisResolveAjaxUrl()
                || ''
            ).trim(),
            nonce: String(
                specificAjax.nonce
                || globalAjax.nonce
                || (coreAjax && coreAjax.nonce)
                || ''
            ).trim(),
            action_nonces: actionNonces,
            actionNonces: actionNonces
        });

        if (!resolved.ajax_url || !resolved.nonce) {
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
        config: resolveConfig,
        post: post,
        postForm: postForm
    };

}());

/* ============================================================
   NAVIGATION GUARD
   Metis.navigation.go(url)
   Metis.navigation.replace(url)
   Metis.navigation.validate(url, options)
   ============================================================ */

Metis.navigation = (function() {
    var allowedPhpPaths = {
        'index.php': true,
        'system/ajax.php': true,
        'system/cron.php': true,
        'system/webhooks.php': true,
        'system/shell.php': true
    };

    var blockedPrefixes = [
        '/modules/',
        '/core/',
        '/config/',
        '/tests/',
        '/tools/',
        '/.metis-'
    ];

    var initialized = false;

    function toastInvalid(message) {
        var text = String(message || 'Navigation blocked.');
        if (window.Metis && Metis.toast && typeof Metis.toast.error === 'function') {
            Metis.toast.error(text, { title: 'Navigation Blocked' });
            return;
        }
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn(text);
        }
    }

    function shouldReportInvalidNavigation(result) {
        return !result || result.reason !== 'empty_navigation_target';
    }

    function normalizePathname(pathname) {
        var value = String(pathname || '/').replace(/\/+/g, '/');
        if (value === '') {
            return '/';
        }
        return value.charAt(0) === '/' ? value : ('/' + value);
    }

    function resolve(url) {
        try {
            return new URL(String(url), window.location.href);
        } catch (_error) {
            return null;
        }
    }

    function isAllowedPhpPath(pathname) {
        var normalized = normalizePathname(pathname).replace(/^\/+/, '');
        return !!allowedPhpPaths[normalized];
    }

    function isBlockedPrefix(pathname) {
        var normalized = normalizePathname(pathname).toLowerCase();
        return blockedPrefixes.some(function(prefix) {
            return normalized.indexOf(prefix) === 0;
        });
    }

    function validate(url, options) {
        var raw = String(url == null ? '' : url).trim();
        var opts = options || {};
        if (raw === '' || raw === '#') {
            return { ok: false, reason: 'empty_navigation_target', message: 'Navigation target is empty.' };
        }
        if (raw.charAt(0) === '#') {
            return { ok: true, href: raw, external: false };
        }

        var target = resolve(raw);
        if (!target) {
            return { ok: false, reason: 'malformed_navigation_target', message: 'Navigation target is invalid.' };
        }

        var protocol = String(target.protocol || '').toLowerCase();
        if (protocol === 'mailto:' || protocol === 'tel:') {
            return { ok: true, href: target.href, external: true };
        }

        if (protocol !== 'http:' && protocol !== 'https:') {
            return { ok: false, reason: 'unsupported_navigation_protocol', message: 'Navigation target uses an unsupported protocol.' };
        }

        var sameOrigin = target.origin === window.location.origin;
        if (!sameOrigin) {
            if (opts.allowExternal === true) {
                return { ok: true, href: target.href, external: true };
            }
            return { ok: false, reason: 'external_navigation_blocked', message: 'External navigation must use an explicit external link target.' };
        }

        var pathname = normalizePathname(target.pathname || '/');
        if (isBlockedPrefix(pathname)) {
            return { ok: false, reason: 'blocked_internal_path', message: 'Direct navigation to internal source paths is blocked.' };
        }

        if (/\.php$/i.test(pathname) && !isAllowedPhpPath(pathname)) {
            return { ok: false, reason: 'unapproved_php_entry', message: 'Direct navigation to unapproved PHP entry points is blocked.' };
        }

        return { ok: true, href: target.href, external: false };
    }

    function shouldBypassAnchor(anchor, event) {
        if (!anchor) return true;
        if (anchor.hasAttribute('download')) return true;
        if (anchor.getAttribute('data-nav-allow') === '1') return true;
        if (event && (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) return true;
        if (event && typeof event.button === 'number' && event.button !== 0) return true;
        return false;
    }

    function allowExternalForAnchor(anchor) {
        if (!anchor) return false;
        var target = String(anchor.getAttribute('target') || '').toLowerCase();
        if (target !== '' && target !== '_self') {
            return true;
        }
        return anchor.getAttribute('rel') === 'external';
    }

    function go(url, options) {
        var result = validate(url, options);
        if (!result.ok) {
            if (shouldReportInvalidNavigation(result)) {
                toastInvalid(result.message);
            }
            return false;
        }
        window.location.assign(result.href);
        return true;
    }

    function replace(url, options) {
        var result = validate(url, options);
        if (!result.ok) {
            if (shouldReportInvalidNavigation(result)) {
                toastInvalid(result.message);
            }
            return false;
        }
        window.location.replace(result.href);
        return true;
    }

    function reload() {
        window.location.reload();
    }

    function init() {
        if (initialized) {
            return;
        }
        initialized = true;

        document.addEventListener('click', function(event) {
            var target = event.target;
            if (!target || typeof target.closest !== 'function') {
                return;
            }

            var anchor = target.closest('a[href]');
            if (!anchor || shouldBypassAnchor(anchor, event)) {
                return;
            }

            var href = String(anchor.getAttribute('href') || '').trim();
            if (href === '') {
                return;
            }

            var result = validate(href, { allowExternal: allowExternalForAnchor(anchor) });
            if (!result.ok) {
                event.preventDefault();
                if (shouldReportInvalidNavigation(result)) {
                    toastInvalid(result.message);
                }
            }
        }, true);
    }

    return {
        init: init,
        validate: validate,
        go: go,
        replace: replace,
        reload: reload
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
            container = document.getElementById('metis-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'metis-toast-container';
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
        toast.className = 'metis-toast metis-toast-' + type;

        var iconSpan = document.createElement('span');
        iconSpan.className = 'metis-toast-icon';
        iconSpan.textContent = icons[type] || icons.info;
        toast.appendChild(iconSpan);

        var body = document.createElement('div');
        body.className = 'metis-toast-body';
        if (title) {
            var titleEl = document.createElement('div');
            titleEl.className = 'metis-toast-title';
            titleEl.textContent = title;
            body.appendChild(titleEl);
        }
        var msgEl = document.createElement('div');
        msgEl.className = 'metis-toast-message';
        msgEl.textContent = message;
        body.appendChild(msgEl);
        toast.appendChild(body);

        var closeBtn = document.createElement('button');
        closeBtn.className = 'metis-toast-close';
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

Metis.ui = Metis.ui || {};
Metis.ui.ajax = Metis.ajax;
Metis.ui.toast = Metis.toast;
Metis.ui.loading = (function() {
    function set(target, busy, options) {
        var node = typeof target === 'string' ? document.querySelector(target) : target;
        if (!node) return;
        var className = options && options.className ? String(options.className) : 'is-loading';
        node.classList.toggle(className, !!busy);
        node.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    function button(target, busy, options) {
        var buttonEl = typeof target === 'string' ? document.querySelector(target) : target;
        if (!buttonEl) return;
        if (!buttonEl.dataset.metisOriginalLabel) {
            buttonEl.dataset.metisOriginalLabel = buttonEl.textContent || '';
        }
        buttonEl.disabled = !!busy;
        buttonEl.setAttribute('aria-busy', busy ? 'true' : 'false');
        buttonEl.classList.toggle('is-loading', !!busy);
        if (options && typeof options.loadingLabel === 'string' && options.loadingLabel !== '') {
            buttonEl.textContent = busy ? options.loadingLabel : (buttonEl.dataset.metisOriginalLabel || '');
        }
    }

    return {
        set: set,
        button: button
    };
}());

Metis.ui.form = (function() {
    function setSubmitting(target, busy, options) {
        if (!target) return;
        if (target instanceof HTMLButtonElement) {
            Metis.ui.loading.button(target, busy, options || {});
            return;
        }
        var button = target.querySelector ? target.querySelector('button[type="submit"], .metis-btn[type="submit"]') : null;
        if (button instanceof HTMLButtonElement) {
            Metis.ui.loading.button(button, busy, options || {});
        }
    }

    function clearErrors(formEl) {
        if (!(formEl instanceof HTMLElement)) return;
        formEl.querySelectorAll('.metis-forms-field-error').forEach(function(node) { node.remove(); });
        formEl.querySelectorAll('[aria-invalid="true"]').forEach(function(node) { node.removeAttribute('aria-invalid'); });
        formEl.querySelectorAll('.is-error').forEach(function(node) { node.classList.remove('is-error'); });
    }

    return {
        setSubmitting: setSubmitting,
        clearErrors: clearErrors
    };
}());

Metis.ui.select = (function() {
    var counter = 0;

    function normalizeRoot(root) {
        if (root instanceof HTMLSelectElement) return root;
        return root && root.querySelectorAll ? root : document;
    }

    function listEnhanceable(root) {
        var scope = normalizeRoot(root);
        if (scope instanceof HTMLSelectElement) {
            return isEnhanceable(scope) ? [scope] : [];
        }

        var selectList = Array.prototype.slice.call(scope.querySelectorAll('select.metis-select, select[data-metis-ui-select="1"]'));
        if (scope instanceof HTMLElement && scope.matches('select.metis-select, select[data-metis-ui-select="1"]')) {
            selectList.unshift(scope);
        }

        return selectList.filter(isEnhanceable);
    }

    function isEnhanceable(select) {
        return select instanceof HTMLSelectElement
            && !select.multiple
            && Number(select.size || 0) <= 1
            && select.dataset.metisNativeSelect !== '1'
            && !select.closest('[data-metis-native-selects="1"]');
    }

    function ensureId(select) {
        if (select.id) return select.id;
        counter += 1;
        select.id = 'metis-select-' + counter;
        return select.id;
    }

    function ensureWrapper(select) {
        var existing = select.parentElement;
        if (existing && existing.classList.contains('metis-ui-select')) {
            return existing;
        }

        var wrapper = document.createElement('div');
        wrapper.className = 'metis-ui-select';

        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('metis-ui-select__native');

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = buildTriggerClass(select);
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');

        var panel = document.createElement('div');
        panel.className = 'metis-ui-select__panel';
        panel.hidden = true;

        var list = document.createElement('div');
        list.className = 'metis-ui-select__list';
        list.setAttribute('role', 'listbox');
        panel.appendChild(list);

        wrapper.appendChild(trigger);
        wrapper.appendChild(panel);

        trigger.addEventListener('click', function(event) {
            event.preventDefault();
            if (select.disabled) return;
            toggle(wrapper);
        });

        trigger.addEventListener('keydown', function(event) {
            var key = String(event.key || '');
            if (key === 'ArrowDown' || key === 'ArrowUp') {
                event.preventDefault();
                open(wrapper);
                focusAdjacent(wrapper, key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (key === 'Enter' || key === ' ') {
                event.preventDefault();
                toggle(wrapper);
                return;
            }
            if (key === 'Escape') {
                close(wrapper);
            }
        });

        list.addEventListener('click', function(event) {
            var option = event.target.closest('[data-metis-select-value]');
            if (!option || option.disabled) return;
            event.preventDefault();
            setValue(select, String(option.getAttribute('data-metis-select-value') || ''), true);
            close(wrapper);
            trigger.focus();
        });

        list.addEventListener('keydown', function(event) {
            var key = String(event.key || '');
            if (key === 'Escape') {
                event.preventDefault();
                close(wrapper);
                trigger.focus();
                return;
            }
            if (key === 'ArrowDown' || key === 'ArrowUp') {
                event.preventDefault();
                focusAdjacent(wrapper, key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (key === 'Enter' || key === ' ') {
                var active = document.activeElement;
                if (active && active.matches('[data-metis-select-value]') && !active.disabled) {
                    event.preventDefault();
                    active.click();
                }
            }
        });

        select.addEventListener('change', function() {
            syncSelect(select);
        });

        if (!select.__metisUiSelectObserver) {
            select.__metisUiSelectObserver = new MutationObserver(function() {
                syncSelect(select);
            });
            select.__metisUiSelectObserver.observe(select, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['disabled']
            });
        }

        return wrapper;
    }

    function syncSelect(select) {
        if (!isEnhanceable(select)) return;
        var wrapper = ensureWrapper(select);
        var trigger = wrapper.querySelector('.metis-ui-select__trigger');
        var panel = wrapper.querySelector('.metis-ui-select__panel');
        var list = wrapper.querySelector('.metis-ui-select__list');
        if (!trigger || !panel || !list) return;

        trigger.className = buildTriggerClass(select);
        applyVariant(wrapper, select);

        var selectId = ensureId(select);
        var listId = selectId + '-listbox';
        list.id = listId;
        trigger.setAttribute('aria-controls', listId);
        trigger.disabled = !!select.disabled;
        wrapper.classList.toggle('is-disabled', !!select.disabled);
        if (select.disabled) {
            close(wrapper);
        }

        var selectedIndex = select.selectedIndex >= 0 ? select.selectedIndex : 0;
        var selectedOption = select.options[selectedIndex] || select.options[0] || null;
        var isPlaceholder = !select.value && selectedOption && String(selectedOption.value || '') === '';

        trigger.innerHTML = '';
        renderOptionContent(trigger, select, selectedOption);
        var caret = document.createElement('span');
        caret.className = 'metis-ui-select__caret';
        caret.setAttribute('aria-hidden', 'true');
        caret.textContent = '▾';
        trigger.appendChild(caret);
        trigger.classList.toggle('is-placeholder', !!isPlaceholder);

        list.innerHTML = '';
        Array.prototype.forEach.call(select.options, function(option, index) {
            var optionButton = document.createElement('button');
            optionButton.type = 'button';
            optionButton.className = 'metis-ui-select__option';
            optionButton.setAttribute('role', 'option');
            optionButton.setAttribute('data-metis-select-value', String(option.value || ''));
            optionButton.setAttribute('aria-selected', option.selected ? 'true' : 'false');
            optionButton.disabled = option.disabled;
            optionButton.dataset.optionIndex = String(index);
            optionButton.tabIndex = option.selected && !option.disabled ? 0 : -1;
            optionButton.classList.toggle('is-selected', option.selected);
            optionButton.classList.toggle('is-placeholder', String(option.value || '') === '');
            renderOptionContent(optionButton, select, option);
            list.appendChild(optionButton);
        });
    }

    function buildTriggerClass(select) {
        var baseClass = String(select.dataset.metisSelectTriggerClass || 'metis-select').trim() || 'metis-select';
        return baseClass + ' metis-ui-select__trigger';
    }

    function applyVariant(wrapper, select) {
        Array.prototype.slice.call(wrapper.classList).forEach(function(className) {
            if (className.indexOf('metis-ui-select--') === 0) {
                wrapper.classList.remove(className);
            }
        });
        var variant = String(select.dataset.metisSelectVariant || '').trim();
        if (variant !== '') {
            wrapper.classList.add('metis-ui-select--' + variant);
        }
    }

    function optionMeta(select, option) {
        var meta = {
            color: '',
            fontFamily: '',
            iconClass: '',
            label: option ? String(option.dataset.metisSelectLabel || option.text || '').trim() : '',
            iconText: '',
            placeholder: false
        };

        if (!(option instanceof HTMLOptionElement)) {
            meta.label = meta.label || 'Select…';
            return meta;
        }

        meta.color = String(option.dataset.metisSelectColor || '').trim();
        meta.fontFamily = String(option.dataset.metisSelectFontFamily || '').trim();
        meta.iconClass = String(option.dataset.metisSelectIconClass || '').trim();
        meta.iconText = String(option.dataset.metisSelectIconText || '').trim();
        meta.placeholder = String(option.value || '') === '';
        return meta;
    }

    function renderOptionContent(container, select, option) {
        var meta = optionMeta(select, option);
        var content = document.createElement('span');
        content.className = 'metis-ui-select__content';

        if (meta.color !== '') {
            var swatch = document.createElement('span');
            swatch.className = 'metis-ui-select__swatch';
            swatch.style.background = meta.color;
            content.appendChild(swatch);
        }

        if (meta.iconClass !== '' || meta.iconText !== '') {
            var icon = document.createElement('span');
            icon.className = 'metis-ui-select__icon';
            if (meta.iconClass !== '') {
                meta.iconClass.split(/\s+/).filter(Boolean).forEach(function(className) {
                    icon.classList.add(className);
                });
            }
            if (meta.iconText !== '') {
                icon.textContent = meta.iconText;
            }
            content.appendChild(icon);
        }

        var label = document.createElement('span');
        label.className = 'metis-ui-select__label';
        label.textContent = meta.label || 'Select…';
        if (meta.fontFamily !== '' && !meta.placeholder) {
            label.style.fontFamily = meta.fontFamily;
        }
        content.appendChild(label);

        container.appendChild(content);
    }

    function setValue(select, value, emitChange) {
        if (!(select instanceof HTMLSelectElement)) return;
        if (select.value === value) {
            syncSelect(select);
            return;
        }
        select.value = value;
        syncSelect(select);
        if (emitChange) {
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function focusAdjacent(wrapper, direction) {
        var options = Array.prototype.slice.call(wrapper.querySelectorAll('.metis-ui-select__option:not(:disabled)'));
        var selectedIndex = -1;
        if (options.length === 0) return;
        var current = document.activeElement;
        var currentIndex = options.indexOf(current);
        if (currentIndex === -1) {
            options.some(function(option, index) {
                if (option.classList.contains('is-selected')) {
                    selectedIndex = index;
                    return true;
                }
                return false;
            });
            currentIndex = selectedIndex;
        }
        var nextIndex = currentIndex + direction;
        if (nextIndex < 0) nextIndex = options.length - 1;
        if (nextIndex >= options.length) nextIndex = 0;
        options[nextIndex].focus();
    }

    function open(target) {
        var wrapper = typeof target === 'string' ? document.querySelector(target) : target;
        if (!wrapper || wrapper.classList.contains('is-disabled')) return;
        closeAll(wrapper);
        wrapper.classList.add('is-open');
        var trigger = wrapper.querySelector('.metis-ui-select__trigger');
        var panel = wrapper.querySelector('.metis-ui-select__panel');
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
        if (panel) panel.hidden = false;
    }

    function close(target) {
        var wrapper = typeof target === 'string' ? document.querySelector(target) : target;
        if (!wrapper) return;
        wrapper.classList.remove('is-open');
        var trigger = wrapper.querySelector('.metis-ui-select__trigger');
        var panel = wrapper.querySelector('.metis-ui-select__panel');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
        if (panel) panel.hidden = true;
    }

    function toggle(target) {
        var wrapper = typeof target === 'string' ? document.querySelector(target) : target;
        if (!wrapper) return;
        if (wrapper.classList.contains('is-open')) {
            close(wrapper);
            return;
        }
        open(wrapper);
    }

    function closeAll(except) {
        document.querySelectorAll('.metis-ui-select.is-open').forEach(function(wrapper) {
            if (except && wrapper === except) return;
            close(wrapper);
        });
    }

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.metis-ui-select')) {
            closeAll();
        }
    });

    return {
        init: function(root) {
            listEnhanceable(root).forEach(syncSelect);
        },
        refresh: function(root) {
            listEnhanceable(root).forEach(syncSelect);
        },
        open: open,
        close: close,
        toggle: toggle,
        closeAll: closeAll
    };
}());

Metis.ui.richText = (function() {
    var selectionStore = Object.create(null);
    var iconMarkupCache = Object.create(null);

    function appBasePath() {
        var ajax = String(
            (window.Metis && Metis.ajax && (Metis.ajax.url || Metis.ajax.ajax_url)) ||
            (window.metisAjax && window.metisAjax.ajax_url) ||
            ''
        ).trim();
        if (ajax) {
            return ajax.replace(/\/api\/ajax\/?$/, '').replace(/\/+$/, '');
        }
        return '';
    }

    function iconUrl(slug) {
        return appBasePath() + '/svg/' + encodeURIComponent(String(slug || '').replace(/_/g, '-'));
    }

    function iconFallbackUrl(slug) {
        return appBasePath() + '/assets/Images/icons/' + encodeURIComponent(String(slug || '')) + '.svg';
    }

    function iconCacheKey(img) {
        return [
            String(img && img.getAttribute && img.getAttribute('src') || '').trim(),
            String(img && img.getAttribute && img.getAttribute('data-icon-fallback') || '').trim()
        ].join('||');
    }

    function inlineSvgNode(markup, img) {
        if (!markup || !img || !img.parentNode) return null;
        var template = document.createElement('template');
        template.innerHTML = String(markup || '').trim();
        var svg = template.content && template.content.firstElementChild ? template.content.firstElementChild : null;
        if (!svg || String(svg.tagName || '').toLowerCase() !== 'svg') return null;
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        svg.classList.add('metis-inline-icon-svg');
        if (img.className) svg.classList.add.apply(svg.classList, img.className.split(/\s+/).filter(Boolean));
        return svg;
    }

    function fetchIconMarkup(url) {
        if (!url || typeof fetch !== 'function') return Promise.resolve('');
        return fetch(url, { credentials: 'same-origin' }).then(function(response) {
            if (!response || !response.ok) return '';
            return response.text();
        }).then(function(markup) {
            return /<svg[\s>]/i.test(String(markup || '')) ? String(markup || '') : '';
        }).catch(function() {
            return '';
        });
    }

    function resolveIconMarkup(img) {
        var key = iconCacheKey(img);
        if (!key || key === '||') return Promise.resolve('');
        if (iconMarkupCache[key]) return iconMarkupCache[key];
        var primary = String(img.getAttribute('src') || '').trim();
        var fallback = String(img.getAttribute('data-icon-fallback') || '').trim();
        iconMarkupCache[key] = fetchIconMarkup(primary).then(function(markup) {
            if (markup || !fallback || fallback === primary) return markup;
            return fetchIconMarkup(fallback);
        });
        return iconMarkupCache[key];
    }

    function hydrateIcon(img) {
        if (!img || img.dataset.iconHydrated === '1' || img.dataset.iconHydrating === '1') return;
        img.dataset.iconHydrating = '1';
        resolveIconMarkup(img).then(function(markup) {
            img.dataset.iconHydrating = '0';
            if (!markup || !img.parentNode) return;
            var svg = inlineSvgNode(markup, img);
            if (!svg) return;
            img.dataset.iconHydrated = '1';
            img.parentNode.replaceChild(svg, img);
        });
    }

    function bindIconFallbacks(scope) {
        (scope || document).querySelectorAll('img[data-icon-fallback]').forEach(function(img) {
            if (img.dataset.iconFallbackBound === '1') return;
            img.dataset.iconFallbackBound = '1';
            img.addEventListener('error', function onError() {
                var fallback = String(img.getAttribute('data-icon-fallback') || '').trim();
                if (!fallback || img.dataset.iconFallbackUsed === '1') return;
                img.dataset.iconFallbackUsed = '1';
                img.src = fallback;
            }, { once: false });
            hydrateIcon(img);
        });
    }

    function normalizeHtml(value) {
        var current = String(value || '');
        if (!current) return '';
        var replacements = {
            'Ã¢ÂÂ': '’',
            'Ã¢ÂÂ': '‘',
            'Ã¢ÂÂ': '“',
            'Ã¢ÂÂ': '”',
            'Ã¢ÂÂ¦': '…',
            'Ã¢ÂÂ': '–',
            'Ã¢ÂÂ': '—',
            'â€™': '’',
            'â€˜': '‘',
            'â€œ': '“',
            'â€': '”',
            'â€¦': '…',
            'â€“': '–',
            'â€”': '—',
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
        Object.keys(replacements).forEach(function(key) {
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

    function saveSelection(editor) {
        var key = String(editor && editor.getAttribute('data-metis-rich-editor') || '');
        var selection = window.getSelection ? window.getSelection() : null;
        if (!key || !selection || !selection.rangeCount) return;
        var range = selection.getRangeAt(0);
        if (!editor.contains(range.commonAncestorContainer)) return;
        selectionStore[key] = range.cloneRange();
    }

    function restoreSelection(editor) {
        var key = String(editor && editor.getAttribute('data-metis-rich-editor') || '');
        var selection = window.getSelection ? window.getSelection() : null;
        var range = key ? selectionStore[key] : null;
        if (!selection || !range) return null;
        selection.removeAllRanges();
        selection.addRange(range);
        return range;
    }

    function placeCaretAtEnd(editor) {
        if (!editor) return;
        editor.focus();
        var range = document.createRange();
        range.selectNodeContents(editor);
        range.collapse(false);
        var selection = window.getSelection ? window.getSelection() : null;
        if (!selection) return;
        selection.removeAllRanges();
        selection.addRange(range);
        saveSelection(editor);
    }

    function selectedHtml(range) {
        if (!range) return '';
        var div = document.createElement('div');
        div.appendChild(range.cloneContents());
        return div.innerHTML;
    }

    function wrapSelection(editor, html) {
        if (!editor) return;
        restoreSelection(editor);
        document.execCommand('insertHTML', false, html);
        editor.innerHTML = normalizeHtml(editor.innerHTML || '');
        saveSelection(editor);
    }

    function topLevelNode(editor, range) {
        var node = range && range.commonAncestorContainer ? range.commonAncestorContainer : null;
        if (!node) return null;
        if (node.nodeType === Node.TEXT_NODE) node = node.parentNode;
        while (node && node.parentNode && node.parentNode !== editor) {
            node = node.parentNode;
        }
        return node && node !== editor && node.nodeType === Node.ELEMENT_NODE ? node : null;
    }

    function applySpanStyle(editor, styleText) {
        var selection = window.getSelection ? window.getSelection() : null;
        var range = restoreSelection(editor) || (selection && selection.rangeCount ? selection.getRangeAt(0) : null);
        if (!range || range.collapsed) return;
        wrapSelection(editor, '<span style="' + String(styleText || '') + '">' + selectedHtml(range) + '</span>');
    }

    function applyAlignment(editor, align) {
        var selection = window.getSelection ? window.getSelection() : null;
        var range = restoreSelection(editor) || (selection && selection.rangeCount ? selection.getRangeAt(0) : null);
        var target = topLevelNode(editor, range);
        if (target) target.style.textAlign = align;
        else editor.style.textAlign = align;
        saveSelection(editor);
    }

    function closeMenus(root) {
        (root || document).querySelectorAll('.metis-se-rich-dropdown.is-open').forEach(function(node) {
            node.classList.remove('is-open');
        });
    }

    function normalizeEditor(editor) {
        if (!editor) return '';
        var normalized = normalizeHtml(editor.innerHTML || '');
        if (String(editor.innerHTML || '') !== normalized) {
            editor.innerHTML = normalized;
            placeCaretAtEnd(editor);
        }
        return normalized;
    }

    async function applyCommand(editor, command, value) {
        if (!editor || !command) return false;
        editor.focus();
        if (!restoreSelection(editor)) {
            placeCaretAtEnd(editor);
        }
        if (command === 'createLink') {
            var url = await Metis.prompt.open({
                title: 'Insert Link',
                message: 'Provide the destination URL for the selected text.',
                label: 'URL',
                defaultValue: 'https://',
                required: true,
                confirmLabel: 'Insert Link'
            });
            if (!url) return false;
            document.execCommand('createLink', false, url);
        } else if (command === 'formatBlock') {
            document.execCommand('formatBlock', false, value === 'P' ? '<p>' : '<' + String(value || 'P').toLowerCase() + '>');
        } else if (command === 'justifyLeft' || command === 'justifyCenter' || command === 'justifyRight') {
            applyAlignment(editor, command === 'justifyCenter' ? 'center' : (command === 'justifyRight' ? 'right' : 'left'));
        } else if (command === 'insertDivider') {
            document.execCommand('insertHTML', false, '<hr class="metis-inline-divider">');
        } else {
            document.execCommand(command, false, null);
        }
        normalizeEditor(editor);
        saveSelection(editor);
        return true;
    }

    function applyAction(editor, action, value, color) {
        if (!editor || !action) return;
        editor.focus();
        if (!restoreSelection(editor)) {
            placeCaretAtEnd(editor);
        }
        if (action === 'block') {
            document.execCommand('formatBlock', false, value === 'P' ? '<p>' : '<' + String(value || 'P').toLowerCase() + '>');
        } else if (action === 'merge') {
            document.execCommand('insertText', false, String(value || ''));
        } else if (action === 'size') {
            var sizeMap = { sm: '0.92rem', lg: '1.12rem', xl: '1.28rem' };
            if (value === 'default') document.execCommand('removeFormat', false, null);
            else applySpanStyle(editor, 'font-size:' + (sizeMap[value] || '1rem'));
        } else if (action === 'color') {
            applySpanStyle(editor, 'color:' + (color || value || ''));
        } else if (action === 'weight') {
            applySpanStyle(editor, 'font-weight:' + String(value || '400'));
        }
        normalizeEditor(editor);
        saveSelection(editor);
    }

    return {
        appBasePath: appBasePath,
        iconUrl: iconUrl,
        iconFallbackUrl: iconFallbackUrl,
        bindIconFallbacks: bindIconFallbacks,
        normalizeHtml: normalizeHtml,
        normalizeEditor: normalizeEditor,
        saveSelection: saveSelection,
        restoreSelection: restoreSelection,
        placeCaretAtEnd: placeCaretAtEnd,
        closeMenus: closeMenus,
        applyCommand: applyCommand,
        applyAction: applyAction
    };
}());

/* ============================================================
   CORE TOOLTIP SYSTEM
   data-metis-tooltip="Message"
   data-metis-tooltip-position="top|right|bottom|left" (optional)
   data-metis-tooltip-variant="regular|notification|error|info|success|warning" (optional)
   ============================================================ */

Metis.tooltip = (function() {
    var tip = null;
    var listenersBound = false;

    function ensureTooltip() {
        if (tip) return tip;
        tip = document.createElement('div');
        tip.className = 'metis-ui-tooltip';
        tip.setAttribute('role', 'tooltip');
        tip.setAttribute('aria-hidden', 'true');
        document.body.appendChild(tip);
        return tip;
    }

    function positionTooltip(target, position) {
        if (!tip || !target) return;
        var rect = target.getBoundingClientRect();
        var tipRect = tip.getBoundingClientRect();
        var gap = 10;
        var top = 0;
        var left = 0;
        var mode = String(position || 'top').toLowerCase();

        if (mode === 'right') {
            top = rect.top + (rect.height / 2) - (tipRect.height / 2);
            left = rect.right + gap;
        } else if (mode === 'bottom') {
            top = rect.bottom + gap;
            left = rect.left + (rect.width / 2) - (tipRect.width / 2);
        } else if (mode === 'left') {
            top = rect.top + (rect.height / 2) - (tipRect.height / 2);
            left = rect.left - tipRect.width - gap;
        } else {
            top = rect.top - tipRect.height - gap;
            left = rect.left + (rect.width / 2) - (tipRect.width / 2);
        }

        var minEdge = 8;
        var maxLeft = window.innerWidth - tipRect.width - minEdge;
        var maxTop = window.innerHeight - tipRect.height - minEdge;
        if (left < minEdge) left = minEdge;
        if (left > maxLeft) left = maxLeft;
        if (top < minEdge) top = minEdge;
        if (top > maxTop) top = maxTop;

        tip.style.left = Math.round(left) + 'px';
        tip.style.top = Math.round(top) + 'px';
    }

    function show(target) {
        if (!target) return;
        var text = String(target.getAttribute('data-metis-tooltip') || '').trim();
        if (!text) return;

        var tooltip = ensureTooltip();
        tooltip.textContent = text;
        var requestedVariant = String(target.getAttribute('data-metis-tooltip-variant') || 'regular').toLowerCase();
        var variant = 'regular';
        if (requestedVariant === 'error' || requestedVariant === 'danger') {
            variant = 'error';
        } else if (
            requestedVariant === 'notification'
            || requestedVariant === 'info'
            || requestedVariant === 'success'
            || requestedVariant === 'warning'
        ) {
            variant = 'notification';
        }
        tooltip.classList.remove('metis-ui-tooltip-regular', 'metis-ui-tooltip-notification', 'metis-ui-tooltip-error');
        tooltip.classList.add('metis-ui-tooltip-' + variant);
        tooltip.classList.add('is-visible');
        tooltip.setAttribute('aria-hidden', 'false');
        positionTooltip(target, target.getAttribute('data-metis-tooltip-position'));
    }

    function hide() {
        if (!tip) return;
        tip.classList.remove('is-visible');
        tip.setAttribute('aria-hidden', 'true');
    }

    function bindElement(el) {
        if (!el || el.getAttribute('data-metis-tooltip-bound') === '1') return;
        el.setAttribute('data-metis-tooltip-bound', '1');
        el.addEventListener('mouseenter', function() { show(el); });
        el.addEventListener('mouseleave', hide);
        el.addEventListener('focus', function() { show(el); });
        el.addEventListener('blur', hide);
        el.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') hide();
        });
    }

    function init(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-metis-tooltip]').forEach(bindElement);

        if (!listenersBound) {
            listenersBound = true;
            window.addEventListener('scroll', hide, true);
            window.addEventListener('resize', hide);
        }
    }

    return {
        init: init,
        show: show,
        hide: hide
    };

}());


/* ============================================================
   TAB SYSTEM
   Metis.tabs.init(containerEl)   — auto-inits on DOMContentLoaded
   Metis.tabs.activate(btn)       — programmatically activate
   ============================================================ */

Metis.tabs = (function() {

    function activate(btn) {
        var group = btn.closest('[data-tab-group]') || btn.closest('.metis-tabs') || btn.parentElement;
        var target = btn.dataset.tab;
        if (!target) return;

        /* Deactivate all in group */
        var scope = btn.closest('.metis-tab-scope') || document;
        scope.querySelectorAll('[data-tab-group="' + (group.dataset.tabGroup || '') + '"] [data-tab], .metis-tabs [data-tab]').forEach(function(b) {
            if (b.closest('.metis-tab-scope') === btn.closest('.metis-tab-scope') || scope === document) {
                b.classList.remove('is-active');
            }
        });
        /* Also handle sidebar buttons */
        if (group.classList.contains('metis-tab-sidebar')) {
            group.querySelectorAll('.metis-tab-sidebar-btn').forEach(function(b) { b.classList.remove('is-active'); });
        }

        /* Hide all matching panels */
        var panels = scope.querySelectorAll('.metis-tab-panel');
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
        root.querySelectorAll('.metis-inline-editable:not([data-inline-inited])').forEach(function(el) {
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
        input.className = 'metis-input metis-inline-edit-input';
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
        if (!saved || !saved.classList.contains('metis-inline-saved')) {
            saved = document.createElement('span');
            saved.className = 'metis-inline-saved';
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
    var focusableSelector = [
        'a[href]',
        'area[href]',
        'button:not([disabled])',
        'input:not([type="hidden"]):not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(',');
    var openCount = 0;
    var bodyOverflow = '';

    function resolveModal(id) {
        return typeof id === 'string' ? document.getElementById(id) : id;
    }

    function dialogNode(modal) {
        if (window.Metis && Metis.a11y && typeof Metis.a11y.getDialogNode === 'function') {
            return Metis.a11y.getDialogNode(modal) || modal;
        }
        return modal;
    }

    function focusableNodes(container) {
        if (!container) {
            return [];
        }
        return Array.prototype.slice.call(container.querySelectorAll(focusableSelector)).filter(function(node) {
            return !node.hidden && node.getAttribute('aria-hidden') !== 'true' && node.getClientRects().length > 0;
        });
    }

    function lockBody() {
        if (openCount === 1) {
            bodyOverflow = document.body.style.overflow || '';
            document.body.style.overflow = 'hidden';
        }
    }

    function unlockBody() {
        if (openCount === 0) {
            document.body.style.overflow = bodyOverflow;
        }
    }

    function trapFocus(event, modal) {
        if (event.key !== 'Tab') {
            return;
        }
        var dialog = dialogNode(modal);
        var focusables = focusableNodes(dialog);
        if (focusables.length === 0) {
            event.preventDefault();
            if (dialog && typeof dialog.focus === 'function') {
                dialog.focus();
            }
            return;
        }
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
            return;
        }
        if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function open(id) {
        var el = resolveModal(id);
        if (!el || el.classList.contains('metis-open')) return;
        if (window.Metis && Metis.a11y && typeof Metis.a11y.ensureDialogSemantics === 'function') {
            Metis.a11y.ensureDialogSemantics(el);
        }
        var dialog = dialogNode(el);
        el._metisLastFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        el.classList.add('is-open', 'metis-open');
        el.setAttribute('aria-hidden', 'false');
        openCount += 1;
        lockBody();
        if (dialog && !dialog.hasAttribute('tabindex')) {
            dialog.setAttribute('tabindex', '-1');
        }
        el._metisTrapHandler = function(event) {
            trapFocus(event, el);
        };
        document.addEventListener('keydown', el._metisTrapHandler);
        var focusables = focusableNodes(dialog);
        var first = focusables[0] || dialog;
        if (first) {
            setTimeout(function() { first.focus(); }, 60);
        }
    }

    function close(id) {
        var el = resolveModal(id);
        if (!el || !el.classList.contains('metis-open')) return;
        el.classList.remove('is-open', 'metis-open');
        el.setAttribute('aria-hidden', 'true');
        if (el._metisTrapHandler) {
            document.removeEventListener('keydown', el._metisTrapHandler);
            el._metisTrapHandler = null;
        }
        openCount = Math.max(0, openCount - 1);
        unlockBody();
        var lastFocus = el._metisLastFocus;
        el._metisLastFocus = null;
        if (lastFocus && document.contains(lastFocus) && typeof lastFocus.focus === 'function') {
            window.setTimeout(function() {
                lastFocus.focus();
            }, 0);
        }
    }

    function init(root) {
        root = root || document;
        if (window.Metis && Metis.a11y && typeof Metis.a11y.enhance === 'function') {
            Metis.a11y.enhance(root);
        }
        /* Open triggers: [data-modal-open="modal-id"] */
        root.querySelectorAll('[data-modal-open]').forEach(function(btn) {
            if (btn._metisModalInited) return;
            btn._metisModalInited = true;
            btn.addEventListener('click', function() { open(btn.dataset.modalOpen); });
        });

        /* Close triggers: [data-modal-close] or .metis-modal-close */
        root.querySelectorAll('[data-modal-close], .metis-modal-close').forEach(function(btn) {
            if (btn._metisModalCloseInited) return;
            btn._metisModalCloseInited = true;
            btn.addEventListener('click', function() {
                var target = btn.dataset.modalClose;
                var backdrop = target ? document.getElementById(target) : btn.closest('.metis-modal-backdrop');
                if (backdrop) close(backdrop);
            });
        });

        /* Close on backdrop click */
        root.querySelectorAll('.metis-modal-backdrop').forEach(function(backdrop) {
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
                var open = document.querySelector('.metis-modal-backdrop.is-open, .metis-modal-backdrop.metis-open');
                if (open) close(open);
            });
        }
    }

    return { open: open, close: close, init: init };

}());

Metis.ui.modal = Metis.modal;

/* ============================================================
   CONFIRM DIALOG
   Metis.confirm.open({ title, message, confirmLabel, cancelLabel, tone })
   ============================================================ */

Metis.confirm = (function() {

    var modal = null;

    function ensure() {
        if (modal) return modal;

        modal = document.createElement('div');
        modal.className = 'metis-modal-backdrop';
        modal.id = 'metis-confirm-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="metis-modal metis-confirm-modal-inner">' +
                '<h3 class="metis-modal-title" id="metis-confirm-title">Confirm</h3>' +
                '<p class="metis-confirm-message" id="metis-confirm-message"></p>' +
                '<div class="metis-form-actions">' +
                    '<button type="button" class="metis-btn metis-btn-ghost" data-confirm-cancel>Cancel</button>' +
                    '<button type="button" class="metis-btn" data-confirm-submit>Continue</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);
        Metis.modal.init(document);
        return modal;
    }

    function open(options) {
        var config = options || {};
        var root = ensure();
        var title = root.querySelector('#metis-confirm-title');
        var message = root.querySelector('#metis-confirm-message');
        var cancelButton = root.querySelector('[data-confirm-cancel]');
        var submitButton = root.querySelector('[data-confirm-submit]');

        if (!title || !message || !cancelButton || !submitButton) {
            return Promise.resolve(false);
        }

        title.textContent = String(config.title || 'Confirm');
        message.textContent = String(config.message || 'Are you sure?');
        cancelButton.textContent = String(config.cancelLabel || 'Cancel');
        submitButton.textContent = String(config.confirmLabel || 'Continue');
        submitButton.classList.toggle('metis-btn-danger', String(config.tone || '') === 'danger');

        return new Promise(function(resolve) {
            var settled = false;

            function cleanup() {
                cancelButton.removeEventListener('click', onCancel);
                submitButton.removeEventListener('click', onSubmit);
                root.removeEventListener('click', onBackdrop);
                document.removeEventListener('keydown', onKeyDown);
            }

            function finish(value) {
                if (settled) return;
                settled = true;
                cleanup();
                Metis.modal.close(root);
                resolve(value);
            }

            function onCancel() { finish(false); }
            function onSubmit() { finish(true); }
            function onBackdrop(event) {
                if (event.target === root) finish(false);
            }
            function onKeyDown(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    finish(false);
                }
            }

            cancelButton.addEventListener('click', onCancel);
            submitButton.addEventListener('click', onSubmit);
            root.addEventListener('click', onBackdrop);
            document.addEventListener('keydown', onKeyDown);
            Metis.modal.open(root);
        });
    }

    return { open: open };

}());

Metis.ui.confirm = Metis.confirm;
Metis.ui.modal.confirm = function(options) {
    return Metis.confirm.open(options || {});
};
Metis.ui.modal.form = function(target) {
    Metis.modal.open(target);
};

/* ============================================================
   INPUT PROMPT
   Metis.prompt.open({ title, message, label, defaultValue })
   ============================================================ */

Metis.prompt = (function() {

    var modal = null;

    function ensure() {
        if (modal) return modal;

        modal = document.createElement('div');
        modal.className = 'metis-modal-backdrop';
        modal.id = 'metis-prompt-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="metis-modal metis-confirm-modal-inner metis-prompt-modal-inner">' +
                '<h3 class="metis-modal-title" id="metis-prompt-title">Provide Input</h3>' +
                '<p class="metis-confirm-message" id="metis-prompt-message"></p>' +
                '<div class="metis-field">' +
                    '<label id="metis-prompt-label" for="metis-prompt-input">Value</label>' +
                    '<input type="text" class="metis-input" id="metis-prompt-input">' +
                    '<textarea class="metis-input" id="metis-prompt-textarea" rows="4" hidden></textarea>' +
                '</div>' +
                '<div class="metis-form-actions">' +
                    '<button type="button" class="metis-btn metis-btn-ghost" data-prompt-cancel>Cancel</button>' +
                    '<button type="button" class="metis-btn" data-prompt-submit>Continue</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);
        Metis.modal.init(document);
        return modal;
    }

    function open(options) {
        var config = options || {};
        var root = ensure();
        var title = root.querySelector('#metis-prompt-title');
        var message = root.querySelector('#metis-prompt-message');
        var label = root.querySelector('#metis-prompt-label');
        var input = root.querySelector('#metis-prompt-input');
        var textarea = root.querySelector('#metis-prompt-textarea');
        var cancelButton = root.querySelector('[data-prompt-cancel]');
        var submitButton = root.querySelector('[data-prompt-submit]');
        var multiline = !!config.multiline;
        var activeField = multiline ? textarea : input;
        var inactiveField = multiline ? input : textarea;

        if (!title || !message || !label || !input || !textarea || !cancelButton || !submitButton) {
            return Promise.resolve(null);
        }

        title.textContent = String(config.title || 'Provide Input');
        var messageText = String(config.message || '');
        message.textContent = messageText;
        message.hidden = messageText === '';
        label.textContent = String(config.label || 'Value');
        cancelButton.textContent = String(config.cancelLabel || 'Cancel');
        submitButton.textContent = String(config.confirmLabel || 'Continue');
        submitButton.classList.toggle('metis-btn-danger', String(config.tone || '') === 'danger');

        inactiveField.value = '';
        inactiveField.hidden = true;
        activeField.hidden = false;
        activeField.value = String(config.defaultValue || '');
        activeField.placeholder = String(config.placeholder || '');
        if (multiline && config.rows) {
            activeField.setAttribute('rows', String(config.rows));
        }

        return new Promise(function(resolve) {
            var settled = false;

            function cleanup() {
                cancelButton.removeEventListener('click', onCancel);
                submitButton.removeEventListener('click', onSubmit);
                activeField.removeEventListener('keydown', onKeyDown);
                root.removeEventListener('click', onBackdrop);
                document.removeEventListener('keydown', onEscape);
            }

            function finalize(value) {
                if (settled) return;
                settled = true;
                cleanup();
                Metis.modal.close(root);
                resolve(value);
            }

            function onCancel() {
                finalize(null);
            }

            function onSubmit() {
                var value = String(activeField.value || '');
                if (config.trim !== false) {
                    value = value.trim();
                }
                if (config.required && value === '') {
                    activeField.focus();
                    return;
                }
                finalize(value);
            }

            function onKeyDown(event) {
                if (!multiline && event.key === 'Enter') {
                    event.preventDefault();
                    onSubmit();
                }
            }

            function onEscape(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    onCancel();
                }
            }

            function onBackdrop(event) {
                if (event.target === root) {
                    onCancel();
                }
            }

            cancelButton.addEventListener('click', onCancel);
            submitButton.addEventListener('click', onSubmit);
            activeField.addEventListener('keydown', onKeyDown);
            root.addEventListener('click', onBackdrop);
            document.addEventListener('keydown', onEscape);
            Metis.modal.open(root);
            window.setTimeout(function() {
                activeField.focus();
                if (typeof activeField.select === 'function') {
                    activeField.select();
                }
            }, 20);
        });
    }

    return { open: open };

}());

window.metis_toast = function(message, type, options) {
    var kind = String(type || 'info').toLowerCase();
    if (kind !== 'success' && kind !== 'error' && kind !== 'warning' && kind !== 'info') {
        kind = 'info';
    }
    if (!window.Metis || !Metis.toast || typeof Metis.toast[kind] !== 'function') {
        if (message && window.console && typeof window.console.warn === 'function') {
            window.console.warn(String(message));
        }
        return null;
    }
    return Metis.toast[kind](String(message || ''), options || {});
};

window.metis_confirm = function(message, onConfirm, options) {
    return Metis.confirm.open(Object.assign({}, options || {}, {
        message: String(message || 'Are you sure?')
    })).then(function(confirmed) {
        if (confirmed && typeof onConfirm === 'function') {
            onConfirm();
        }
        return confirmed;
    });
};

window.metis_prompt = function(options) {
    return Metis.prompt.open(options || {});
};

/* ============================================================
   QUICK ACTIONS
   ============================================================ */

Metis.quickActions = (function() {
    var panel = null;
    var trigger = null;
    var root = null;
    var closeTimer = null;
    var modal = null;
    var modalTitle = null;
    var modalBody = null;
    var modalSubmit = null;
    var modalError = null;
    var activePayload = null;

    function titleCase(value) {
        return String(value || '')
            .replace(/[_-]+/g, ' ')
            .trim()
            .replace(/\b\w/g, function(ch) { return ch.toUpperCase(); });
    }

    function iconSvg(name) {
        var key = String(name || '').toLowerCase();
        if (key === 'calendar-plus') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="13" x2="12" y2="19"/><line x1="9" y1="16" x2="15" y2="16"/></svg>';
        }
        if (key === 'user-plus' || key === 'user-round-plus') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="4"/><path d="M2 20a7 7 0 0 1 14 0"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>';
        }
        if (key === 'mail-plus') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/><line x1="19" y1="2" x2="19" y2="8"/><line x1="16" y1="5" x2="22" y2="5"/></svg>';
        }
        if (key === 'hand-heart') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 11V5a2 2 0 0 1 4 0v3"/><path d="M11 8a2 2 0 0 1 4 0v1"/><path d="M15 9a2 2 0 0 1 4 0v3"/><path d="M3 12h4l2 7h8a3 3 0 0 0 3-3v-4"/><path d="m14 4 .7 1.4L16 6l-1.3.6L14 8l-.7-1.4L12 6l1.3-.6z"/></svg>';
        }
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"/><path d="M5 12h14"/></svg>';
    }

    function registryIconUrl(slug) {
        var key = String(slug || '').replace(/_/g, '-').toLowerCase().replace(/[^a-z0-9-]/g, '');
        if (!key) return '';
        var ajaxUrl = (window.Metis && Metis.ajax && Metis.ajax.url) ? Metis.ajax.url : metisResolveAjaxUrl();
        try {
            var url = new URL(String(ajaxUrl || '/api/ajax'), window.location.href);
            var basePath = url.pathname.replace(/\/api\/ajax\/?$/, '').replace(/\/+$/, '');
            url.pathname = basePath + '/svg/' + encodeURIComponent(key) + '/';
            url.search = '';
            url.hash = '';
            return url.toString();
        } catch (error) {
            return '/svg/' + encodeURIComponent(key) + '/';
        }
    }

    function ensureModal() {
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'metis-quick-action-modal';
        modal.className = 'metis-modal-backdrop metis-quick-action-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="metis-modal metis-modal-lg metis-quick-action-dialog" role="dialog" aria-modal="true" aria-labelledby="metis-quick-action-modal-title">' +
                '<div class="metis-modal-header">' +
                    '<h2 class="metis-modal-title" id="metis-quick-action-modal-title">Quick Action</h2>' +
                    '<button type="button" class="metis-modal-close metis-quick-action-close" data-qa-modal-close aria-label="Close" title="Close"><span class="metis-quick-action-close-icon" aria-hidden="true"></span></button>' +
                '</div>' +
                '<div class="metis-modal-body">' +
                    '<div class="metis-alert metis-alert-error metis-quick-action-modal-error" data-qa-modal-error hidden></div>' +
                    '<div data-qa-modal-body></div>' +
                '</div>' +
                '<div class="metis-modal-footer">' +
                    '<button type="button" class="metis-btn metis-btn-ghost" data-qa-modal-close>Cancel</button>' +
                    '<button type="button" class="metis-btn" data-qa-modal-submit>Save</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);
        modalTitle = modal.querySelector('#metis-quick-action-modal-title');
        modalBody = modal.querySelector('[data-qa-modal-body]');
        modalSubmit = modal.querySelector('[data-qa-modal-submit]');
        modalError = modal.querySelector('[data-qa-modal-error]');
        var closeIcon = modal.querySelector('.metis-quick-action-close-icon');
        if (closeIcon) {
            var closeIconUrl = registryIconUrl('close-outline');
            closeIcon.style.setProperty('--metis-quick-action-close-icon', 'url("' + closeIconUrl + '")');
            fetch(closeIconUrl, { credentials: 'same-origin' }).then(function(response) {
                if (!response.ok) return '';
                return response.text();
            }).then(function(markup) {
                if (!markup) return;
                closeIcon.innerHTML = markup;
                closeIcon.classList.add('is-inline');
            }).catch(function() {
                /* Keep the CSS mask fallback when the registry fetch fails. */
            });
        }

        modal.querySelectorAll('[data-qa-modal-close]').forEach(function(button) {
            button.addEventListener('click', closeModal);
        });
        modalSubmit.addEventListener('click', submitModal);
        if (window.Metis && Metis.modal && typeof Metis.modal.init === 'function') {
            Metis.modal.init(document);
        }

        return modal;
    }

    function setModalError(message) {
        ensureModal();
        var text = String(message || '').trim();
        if (!text) {
            modalError.hidden = true;
            modalError.textContent = '';
            return;
        }
        modalError.textContent = text;
        modalError.hidden = false;
    }

    function closeModal() {
        if (!modal) return;
        if (window.Metis && Metis.modal && typeof Metis.modal.close === 'function') {
            Metis.modal.close(modal);
        }
        activePayload = null;
        setModalError('');
    }

    function openModal(payload) {
        ensureModal();
        activePayload = payload || {};
        modalTitle.textContent = String(activePayload.title || 'Quick Action');
        modalBody.innerHTML = Metis.util.sanitizeHtmlFragment(activePayload.html || '');
        modalSubmit.textContent = String(activePayload.submit_label || 'Save');
        modalSubmit.disabled = !String(activePayload.submit_action || '').trim();
        setModalError('');

        if (window.Metis && Metis.modal && typeof Metis.modal.open === 'function') {
            Metis.modal.open(modal);
        }

        var first = modalBody.querySelector('input, select, textarea, button');
        if (first && typeof first.focus === 'function') {
            window.setTimeout(function() { first.focus(); }, 80);
        }
    }

    function submitModal() {
        if (!activePayload || !modalBody || !modalSubmit) return;
        var submitAction = String(activePayload.submit_action || '').trim();
        var form = modalBody.querySelector('form');
        if (!submitAction || !form) return;

        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }

        var body = new FormData(form);
        body.set('action', submitAction);
        var nonce = String(activePayload.submit_nonce || '').trim();
        var actionNonce = String(activePayload.submit_action_nonce || nonce).trim();
        if (nonce) body.set('nonce', nonce);
        if (actionNonce) body.set('metis_action_nonce', actionNonce);
        var csrf = actionNonce || nonce || '';

        modalSubmit.disabled = true;
        setModalError('');
        fetch(Metis.ajax.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: metisCsrfHeaders(csrf),
            body: body
        }).then(function(response) {
            return Metis.ajax.parseJson(response);
        }).then(function(payload) {
            var completedPayload = activePayload || {};
            closeModal();
            if (window.Metis && Metis.toast && typeof Metis.toast.success === 'function') {
                Metis.toast.success(String(completedPayload.success_message || payload.message || 'Action completed.'));
            }
            if (completedPayload.redirect) {
                window.setTimeout(function() { Metis.navigation.go(String(completedPayload.redirect)); }, 450);
            }
        }).catch(function(error) {
            setModalError(error && error.message ? error.message : 'Quick action failed.');
        }).finally(function() {
            modalSubmit.disabled = false;
        });
    }

    function loadModal(action) {
        if (!Metis.ajax || !Metis.ajax.url) {
            Metis.navigation.go(String(action.route || ''));
            return;
        }

        Metis.ajax.post({
            action: 'metis_quick_action_form',
            key: action.key || '',
            nonce: Metis.ajax.nonce || ''
        }).then(function(payload) {
            if (!payload || !payload.success) {
                throw new Error(Metis.ajax.message(payload));
            }
            openModal(payload.data || {});
        }).catch(function(error) {
            if (action.route) {
                if (window.Metis && Metis.toast && typeof Metis.toast.error === 'function') {
                    Metis.toast.error(error && error.message ? error.message : 'Opening the modal failed. Redirecting instead.');
                }
                Metis.navigation.go(String(action.route || ''));
                return;
            }
            setModalError(error && error.message ? error.message : 'Quick action modal failed.');
        });
    }

    function execute(action) {
        if (!action || typeof action !== 'object') return;
        var route = String(action.route || '').trim();
        if (String(action.type || '') === 'modal') {
            loadModal(action);
            return;
        }
        if (!route) return;
        Metis.navigation.go(route);
    }

    function openPanel() {
        if (!panel || !trigger) return;
        panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
    }

    function closePanel() {
        if (!panel || !trigger) return;
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    }

    function render() {
        root = document.getElementById('metis-quick-actions-list');
        if (!root) return;

        var payload = window.metisQuickActions && Array.isArray(window.metisQuickActions.actions)
            ? window.metisQuickActions.actions
            : [];

        if (!payload.length) {
            root.innerHTML = '<p class="metis-help">No quick actions available.</p>';
            return;
        }

        var grouped = {};
        payload.forEach(function(action) {
            var group = String(action.group || 'other');
            if (!grouped[group]) grouped[group] = [];
            grouped[group].push(action);
        });

        var html = '';
        Object.keys(grouped).sort().forEach(function(group) {
            html += '<div class="metis-quick-actions-group">';
            html += '<div class="metis-quick-actions-group-label">' + Metis.util.escapeHtml(titleCase(group)) + '</div>';
            html += '<div class="metis-quick-actions-grid">';
            grouped[group].forEach(function(action) {
                var actionLabel = String(action.label || 'Action');
                html += '<button type="button" class="metis-quick-actions-item metis-quick-action-item" role="menuitem" aria-label="' + Metis.util.escapeHtml(actionLabel) + '" title="' + Metis.util.escapeHtml(actionLabel) + '" data-qa-key="' + Metis.util.escapeHtml(String(action.key || '')) + '">'
                    + '<span class="metis-quick-actions-item-icon">' + iconSvg(action.icon) + '</span>'
                    + '<span>' + Metis.util.escapeHtml(actionLabel) + '</span>'
                    + '</button>';
            });
            html += '</div></div>';
        });

        root.innerHTML = html;
        root.querySelectorAll('.metis-quick-action-item').forEach(function(button) {
            button.addEventListener('click', function() {
                var key = String(button.getAttribute('data-qa-key') || '');
                var action = payload.find(function(candidate) {
                    return String(candidate.key || '') === key;
                });
                execute(action || null);
                closePanel();
            });
        });
    }

    function bindInteractions() {
        panel = document.getElementById('metis-quick-actions-panel');
        trigger = document.getElementById('metis-quick-actions-trigger');
        if (!panel || !trigger) return;

        var wrapper = document.getElementById('metis-quick-actions');
        if (!wrapper) return;

        wrapper.addEventListener('mouseenter', function() {
            if (closeTimer) {
                window.clearTimeout(closeTimer);
                closeTimer = null;
            }
            openPanel();
        });

        wrapper.addEventListener('mouseleave', function() {
            if (closeTimer) {
                window.clearTimeout(closeTimer);
            }
            closeTimer = window.setTimeout(closePanel, 180);
        });

        trigger.addEventListener('click', function(event) {
            event.preventDefault();
            if (panel.hidden) {
                openPanel();
            } else {
                closePanel();
            }
        });

        document.addEventListener('click', function(event) {
            if (!wrapper.contains(event.target)) {
                closePanel();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closePanel();
            }
        });
    }

    function init() {
        render();
        bindInteractions();
    }

    return { init: init, execute: execute };
}());

/* ============================================================
   AVATAR CROPPER
   ============================================================ */

Metis.avatarCropper = function(config) {
    var options = config || {};
    var canvas = options.canvas || null;
    var preview = options.preview || null;
    var zoomInput = options.zoomInput || null;
    var outputSize = options.outputSize || 256;
    var ctx = canvas && canvas.getContext ? canvas.getContext('2d') : null;
    var image = null;
    var zoom = 1;
    var minZoom = 1;
    var offsetX = 0;
    var offsetY = 0;
    var dragging = false;
    var dragStartX = 0;
    var dragStartY = 0;
    var dragOriginX = 0;
    var dragOriginY = 0;

    function clampOffsets() {
        if (!canvas || !image) return;
        var scale = Math.max(canvas.width / image.width, canvas.height / image.height) * zoom;
        var drawWidth = image.width * scale;
        var drawHeight = image.height * scale;
        var maxOffsetX = Math.max(0, (drawWidth - canvas.width) / 2);
        var maxOffsetY = Math.max(0, (drawHeight - canvas.height) / 2);
        offsetX = Math.max(-maxOffsetX, Math.min(maxOffsetX, offsetX));
        offsetY = Math.max(-maxOffsetY, Math.min(maxOffsetY, offsetY));
    }

    function render() {
        if (!canvas || !ctx) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#eef2f7';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        if (!image) {
            ctx.fillStyle = '#6d7485';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '600 16px Figtree, sans-serif';
            ctx.fillText('Upload an image', canvas.width / 2, canvas.height / 2);
            return;
        }

        clampOffsets();
        var scale = Math.max(canvas.width / image.width, canvas.height / image.height) * zoom;
        var drawWidth = image.width * scale;
        var drawHeight = image.height * scale;
        var x = (canvas.width - drawWidth) / 2 + offsetX;
        var y = (canvas.height - drawHeight) / 2 + offsetY;

        ctx.drawImage(image, x, y, drawWidth, drawHeight);

        ctx.save();
        ctx.fillStyle = 'rgba(16, 23, 41, 0.48)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.globalCompositeOperation = 'destination-out';
        ctx.beginPath();
        ctx.arc(canvas.width / 2, canvas.height / 2, Math.min(canvas.width, canvas.height) * 0.38, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();

        ctx.beginPath();
        ctx.arc(canvas.width / 2, canvas.height / 2, Math.min(canvas.width, canvas.height) * 0.38, 0, Math.PI * 2);
        ctx.lineWidth = 3;
        ctx.strokeStyle = '#ffffff';
        ctx.stroke();

        updatePreview();
    }

    function updatePreview() {
        if (!preview) return;
        if (!image) {
            preview.removeAttribute('src');
            return;
        }
        preview.src = getDataUrl();
    }

    function getDataUrl() {
        if (!canvas || !image) return '';
        var out = document.createElement('canvas');
        out.width = outputSize;
        out.height = outputSize;
        var outCtx = out.getContext('2d');
        if (!outCtx) return '';

        var scale = Math.max(canvas.width / image.width, canvas.height / image.height) * zoom;
        var drawWidth = image.width * scale;
        var drawHeight = image.height * scale;
        var x = (canvas.width - drawWidth) / 2 + offsetX;
        var y = (canvas.height - drawHeight) / 2 + offsetY;

        var exportScale = outputSize / canvas.width;
        outCtx.fillStyle = '#ffffff';
        outCtx.fillRect(0, 0, outputSize, outputSize);
        outCtx.drawImage(image, x * exportScale, y * exportScale, drawWidth * exportScale, drawHeight * exportScale);
        return out.toDataURL('image/jpeg', 0.9);
    }

    function setZoom(value) {
        zoom = Math.max(minZoom, Math.min(4, value));
        if (zoomInput) {
            zoomInput.value = String(zoom);
        }
        render();
    }

    function loadFile(file) {
        return new Promise(function(resolve, reject) {
            if (!file) {
                reject(new Error('Select an image first.'));
                return;
            }
            var reader = new FileReader();
            reader.onload = function() {
                var nextImage = new Image();
                nextImage.onload = function() {
                    image = nextImage;
                    minZoom = 1;
                    zoom = 1;
                    offsetX = 0;
                    offsetY = 0;
                    if (zoomInput) {
                        zoomInput.min = String(minZoom);
                        zoomInput.max = '4';
                        zoomInput.step = '0.01';
                        zoomInput.value = String(zoom);
                    }
                    render();
                    resolve();
                };
                nextImage.onerror = function() {
                    reject(new Error('Selected image could not be loaded.'));
                };
                nextImage.src = String(reader.result || '');
            };
            reader.onerror = function() {
                reject(new Error('Selected image could not be read.'));
            };
            reader.readAsDataURL(file);
        });
    }

    if (zoomInput) {
        zoomInput.addEventListener('input', function() {
            setZoom(parseFloat(String(zoomInput.value || '1')) || 1);
        });
    }

    if (canvas) {
        canvas.addEventListener('pointerdown', function(event) {
            if (!image) return;
            dragging = true;
            dragStartX = event.clientX;
            dragStartY = event.clientY;
            dragOriginX = offsetX;
            dragOriginY = offsetY;
            canvas.setPointerCapture(event.pointerId);
        });
        canvas.addEventListener('pointermove', function(event) {
            if (!dragging) return;
            offsetX = dragOriginX + (event.clientX - dragStartX);
            offsetY = dragOriginY + (event.clientY - dragStartY);
            render();
        });
        function stopDrag(event) {
            if (!dragging) return;
            dragging = false;
            if (event && typeof event.pointerId === 'number') {
                canvas.releasePointerCapture(event.pointerId);
            }
        }
        canvas.addEventListener('pointerup', stopDrag);
        canvas.addEventListener('pointercancel', stopDrag);
        canvas.addEventListener('wheel', function(event) {
            if (!image) return;
            event.preventDefault();
            var delta = event.deltaY < 0 ? 0.08 : -0.08;
            setZoom(zoom + delta);
        }, { passive: false });
    }

    render();

    return {
        loadFile: loadFile,
        render: render,
        getDataUrl: getDataUrl,
        hasImage: function() { return !!image; }
    };
};

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
            document.documentElement.setAttribute('data-metis-' + key.replace(/_/g, '-'), resolved[key] ? 'true' : 'false');
        });
        document.documentElement.setAttribute('data-metis-profile', resolved.profile);
        if (document.body) {
            var enabled = toggles.some(function(key) { return !!resolved[key]; });
            document.body.classList.toggle('accessibility-enabled', enabled);
        }
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
                var parsed = JSON.parse(raw) || {};
                ['profile'].concat(toggles).forEach(function(key) {
                    if (Object.prototype.hasOwnProperty.call(parsed, key)) {
                        resolved[key] = parsed[key];
                    }
                });
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
        var toggle = document.getElementById('metis-accessibility-toggle');
        var panel = document.getElementById('metis-accessibility-panel');
        var lastFocus = null;
        if (!toggle || !panel || !config().toolbarEnabled || !config().allowOverrides) {
            return;
        }

        syncForm(panel, current);

        function firstPanelField() {
            return panel.querySelector('select, input, button, [tabindex]:not([tabindex="-1"])');
        }

        function closePanel(restoreFocus) {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            if (restoreFocus !== false && lastFocus && document.contains(lastFocus) && typeof lastFocus.focus === 'function') {
                window.setTimeout(function() {
                    lastFocus.focus();
                }, 0);
            }
        }

        function openPanel() {
            lastFocus = document.activeElement instanceof HTMLElement ? document.activeElement : toggle;
            panel.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            var first = firstPanelField();
            if (first && typeof first.focus === 'function') {
                window.setTimeout(function() {
                    first.focus();
                }, 20);
            }
        }

        toggle.addEventListener('click', function() {
            if (panel.hidden) {
                openPanel();
            } else {
                closePanel();
            }
        });

        panel.querySelectorAll('[data-accessibility-close]').forEach(function(button) {
            button.addEventListener('click', function() {
                closePanel();
            });
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
                var prefs = { profile: profile ? profile.value : (document.documentElement.getAttribute('data-metis-profile') || 'none') };
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
            if (!panel.hidden && !event.target.closest('.metis-accessibility')) {
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
   ACCESSIBILITY ENHANCEMENTS
   ============================================================ */

Metis.a11y = (function() {
    var dialogSelector = '.metis-modal-backdrop';
    var controlSelector = 'input:not([type="hidden"]), select, textarea';

    function cleanText(value) {
        return String(value || '')
            .replace(/\s+/g, ' ')
            .replace(/\s+\*/g, '')
            .trim();
    }

    function ensureId(element, fallback) {
        if (!element) {
            return '';
        }
        if (element.id) {
            return element.id;
        }
        element.id = String(fallback || ('metis-a11y-' + Math.random().toString(36).slice(2, 10)));
        return element.id;
    }

    function getDialogNode(modal) {
        if (!modal || !(modal instanceof Element)) {
            return null;
        }
        if (modal.matches('.metis-modal')) {
            return modal;
        }
        return modal.querySelector('.metis-modal') || modal;
    }

    function ensureDialogSemantics(modal) {
        var dialog = getDialogNode(modal);
        if (!dialog) {
            return null;
        }

        if (!dialog.hasAttribute('role')) {
            dialog.setAttribute('role', 'dialog');
        }
        dialog.setAttribute('aria-modal', 'true');

        if (!dialog.hasAttribute('aria-label') && !dialog.hasAttribute('aria-labelledby')) {
            var heading = dialog.querySelector('.metis-modal-title, h1, h2, h3, strong');
            var headingId = ensureId(heading, (modal.id || 'metis-modal') + '-title');
            if (headingId !== '') {
                dialog.setAttribute('aria-labelledby', headingId);
            }
        }

        if (!dialog.hasAttribute('aria-describedby')) {
            var description = dialog.querySelector('.metis-confirm-message, .metis-modal-body > p');
            var descriptionId = ensureId(description, (modal.id || 'metis-modal') + '-description');
            if (descriptionId !== '') {
                dialog.setAttribute('aria-describedby', descriptionId);
            }
        }

        return dialog;
    }

    function hasProgrammaticLabel(control) {
        if (!control || !(control instanceof Element)) {
            return true;
        }
        if (control.hasAttribute('aria-label') || control.hasAttribute('aria-labelledby') || control.hasAttribute('title')) {
            return true;
        }
        if (control.labels && control.labels.length > 0) {
            return true;
        }
        return !!control.closest('label');
    }

    function deriveLabel(control) {
        if (!control || !(control instanceof Element)) {
            return '';
        }

        var container = control.closest('.metis-field, .metis-field, .metis-form-field, .metis-media-modal-field, .metis-se-field-row');
        if (container) {
            var labelled = container.querySelector('label, legend');
            var labelText = cleanText(labelled ? labelled.textContent : '');
            if (labelText !== '') {
                return labelText;
            }
        }

        var placeholder = cleanText(control.getAttribute('placeholder'));
        if (placeholder !== '') {
            return placeholder;
        }

        if (control.getAttribute('type') === 'search') {
            return 'Search';
        }

        return '';
    }

    function ensureControlLabels(root) {
        root.querySelectorAll(controlSelector).forEach(function(control) {
            if (hasProgrammaticLabel(control)) {
                return;
            }
            var label = deriveLabel(control);
            if (label !== '') {
                control.setAttribute('aria-label', label);
            }
        });
    }

    function ensureButtonLabels(root) {
        root.querySelectorAll('button').forEach(function(button) {
            if (button.hasAttribute('aria-label') || button.hasAttribute('aria-labelledby') || cleanText(button.textContent) !== '') {
                return;
            }
            var label = cleanText(button.getAttribute('title') || button.getAttribute('data-tooltip') || '');
            if (label !== '') {
                button.setAttribute('aria-label', label);
            }
        });
    }

    function enhance(root) {
        root = root || document;
        if (!root || !(root instanceof Element || root instanceof Document)) {
            return;
        }
        ensureControlLabels(root);
        ensureButtonLabels(root);
        root.querySelectorAll(dialogSelector).forEach(ensureDialogSemantics);
    }

    return {
        enhance: enhance,
        ensureDialogSemantics: ensureDialogSemantics,
        getDialogNode: getDialogNode
    };
}());

/* ============================================================
   SIDEBAR NAV
   ============================================================ */

Metis.nav = (function() {
    function init() {
        var toggle  = document.querySelector('.metis-nav-toggle');
        var sidebar = document.getElementById('metis-sidebar');
        var overlay = document.getElementById('metis-sidebar-overlay');
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

        // Reset any stale flyout state on first load so hidden panes do not render mispositioned.
        sidebar.querySelectorAll('.metis-sidebar-group').forEach(function(group) {
            group.classList.remove('is-open');
            var submenu = group.querySelector('.metis-sidebar-submenu');
            if (submenu) {
                submenu.style.left = '';
                submenu.style.top = '';
                submenu.style.maxHeight = '';
                submenu.style.position = '';
                submenu.style.visibility = '';
                submenu.querySelectorAll('.metis-sidebar-subitem-group').forEach(function(subGroup) {
                    subGroup.classList.remove('is-open');
                });
                submenu.querySelectorAll('.metis-sidebar-subsubmenu').forEach(function(subMenu) {
                    subMenu.style.left = '';
                    subMenu.style.top = '';
                    subMenu.style.maxHeight = '';
                    subMenu.style.position = '';
                    subMenu.style.visibility = '';
                });
            }
        });

        function directSidebarLabel(item) {
            if (!item) return null;
            var children = item.children || [];
            for (var i = 0; i < children.length; i += 1) {
                if (children[i].classList && children[i].classList.contains('metis-sidebar-label')) {
                    return children[i];
                }
            }
            return null;
        }

        function clearSidebarLabelPosition(item) {
            var label = directSidebarLabel(item);
            if (!label) return;
            label.classList.remove('is-positioned');
            label.style.left = '';
            label.style.top = '';
        }

        function positionSidebarLabel(item) {
            if (!item || document.documentElement.getAttribute('data-metis-nav-labels') === 'true') return;
            if (item.classList.contains('metis-sidebar-has-submenu')) return;

            var label = directSidebarLabel(item);
            if (!label) return;

            var rect = item.getBoundingClientRect();
            label.classList.add('is-positioned');
            label.style.left = Math.round(rect.right + 10) + 'px';
            label.style.top = Math.round(rect.top + (rect.height / 2)) + 'px';
        }

        sidebar.querySelectorAll('.metis-sidebar-item:not(.metis-sidebar-has-submenu)').forEach(function(item) {
            item.addEventListener('mouseenter', function() {
                positionSidebarLabel(item);
            });
            item.addEventListener('mouseleave', function() {
                clearSidebarLabelPosition(item);
            });
            item.addEventListener('focusin', function() {
                positionSidebarLabel(item);
            });
            item.addEventListener('focusout', function() {
                clearSidebarLabelPosition(item);
            });
        });

        function clearAllSidebarLabelPositions() {
            sidebar.querySelectorAll('.metis-sidebar-label.is-positioned').forEach(function(label) {
                label.classList.remove('is-positioned');
                label.style.left = '';
                label.style.top = '';
            });
        }

        var navScroll = sidebar.querySelector('.metis-sidebar-nav-scroll');
        if (navScroll) {
            navScroll.addEventListener('scroll', clearAllSidebarLabelPositions, { passive: true });
        }
        window.addEventListener('resize', clearAllSidebarLabelPositions);

        function closeGroup(group) {
            var groupLink = group.querySelector('.metis-sidebar-group-link');
            group.classList.remove('is-open');
            var submenu = group.querySelector('.metis-sidebar-submenu');
            if (groupLink) {
                groupLink.setAttribute('aria-expanded', 'false');
            }
            if (submenu && window.innerWidth > 900) {
                submenu.style.top = '';
                submenu.style.left = '';
                submenu.style.visibility = '';
                submenu.style.position = '';
                submenu.style.maxHeight = '';
            }
            if (submenu) {
                submenu.setAttribute('aria-hidden', 'true');
                submenu.querySelectorAll('.metis-sidebar-subitem-group.is-open').forEach(function(openSub) {
                    openSub.classList.remove('is-open');
                    var openSubLink = openSub.querySelector('.metis-sidebar-subitem-link');
                    var openSubMenu = openSub.querySelector('.metis-sidebar-subsubmenu');
                    if (openSubLink) {
                        openSubLink.setAttribute('aria-expanded', 'false');
                    }
                    if (openSubMenu) {
                        openSubMenu.setAttribute('aria-hidden', 'true');
                    }
                });
                submenu.querySelectorAll('.metis-sidebar-subsubmenu').forEach(function(subMenu) {
                    subMenu.style.left = '';
                    subMenu.style.top = '';
                    subMenu.style.maxHeight = '';
                    subMenu.style.visibility = '';
                    subMenu.style.position = '';
                });
            }
        }

        function openGroup(group) {
            var submenu = group.querySelector('.metis-sidebar-submenu');
            var groupLink = group.querySelector('.metis-sidebar-group-link');
            if (!submenu) return;

            sidebar.querySelectorAll('.metis-sidebar-group.is-open').forEach(function(openGroupEl) {
                if (openGroupEl !== group) {
                    closeGroup(openGroupEl);
                }
            });
            submenu.querySelectorAll('.metis-sidebar-subitem-group.is-open').forEach(function(openSub) {
                openSub.classList.remove('is-open');
            });
            submenu.querySelectorAll('.metis-sidebar-subsubmenu').forEach(function(subMenu) {
                subMenu.style.left = '';
                subMenu.style.top = '';
                subMenu.style.maxHeight = '';
                subMenu.style.visibility = '';
            });
            if (window.innerWidth > 900) {
                var viewportPadding = 8;
                submenu.style.position = 'fixed';
                submenu.style.maxHeight = Math.max(180, Math.floor(window.innerHeight * 0.82)) + 'px';
                submenu.style.visibility = 'hidden';
                submenu.style.left = '-9999px';
                submenu.style.top = '0px';
            }
            group.classList.add('is-open');
            if (groupLink) {
                groupLink.setAttribute('aria-expanded', 'true');
            }
            submenu.setAttribute('aria-hidden', 'false');
            if (window.innerWidth > 900) {
                var rect = group.getBoundingClientRect();
                var submenuRect = submenu.getBoundingClientRect();
                var submenuHeight = submenuRect.height || submenu.offsetHeight || 220;
                var submenuWidth = submenuRect.width || submenu.offsetWidth || 220;
                var top = Math.min(Math.max(viewportPadding, rect.top), Math.max(viewportPadding, window.innerHeight - submenuHeight - viewportPadding));
                var left = rect.right + 8;
                if (left + submenuWidth > window.innerWidth - viewportPadding) {
                    left = Math.max(viewportPadding, rect.left - submenuWidth - 8);
                }
                submenu.style.top = top + 'px';
                submenu.style.left = left + 'px';
                submenu.style.maxHeight = Math.max(180, Math.floor(window.innerHeight * 0.82)) + 'px';
                submenu.style.visibility = '';
            }
        }

        sidebar.querySelectorAll('.metis-sidebar-group').forEach(function(group) {
            var submenu  = group.querySelector('.metis-sidebar-submenu');
            var groupLink = group.querySelector('.metis-sidebar-group-link');
            var closeTimer = null;
            if (!submenu) return;

            group.addEventListener('mouseenter', function() {
                clearTimeout(closeTimer);
                openGroup(group);
            });

            group.addEventListener('mouseleave', function(event) {
                if (event && event.relatedTarget && submenu.contains(event.relatedTarget)) {
                    return;
                }
                clearTimeout(closeTimer);
                closeTimer = window.setTimeout(function() {
                    closeGroup(group);
                }, 220);
            });

            if (groupLink) {
                groupLink.addEventListener('click', function(event) {
                    if (window.innerWidth > 900 && String(groupLink.getAttribute('href') || '') === '#') {
                        event.preventDefault();
                        if (group.classList.contains('is-open')) {
                            closeGroup(group);
                        } else {
                            openGroup(group);
                        }
                    }
                });
                groupLink.addEventListener('keydown', function(event) {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowRight' || ((event.key === 'Enter' || event.key === ' ') && String(groupLink.getAttribute('href') || '') === '#')) {
                        event.preventDefault();
                        openGroup(group);
                        var firstItem = submenu.querySelector('.metis-sidebar-subitem, .metis-sidebar-subitem-link');
                        if (firstItem) {
                            firstItem.focus();
                        }
                    }
                });
            }

            submenu.addEventListener('mouseenter', function() {
                clearTimeout(closeTimer);
            });

            submenu.addEventListener('mouseleave', function(event) {
                if (event && event.relatedTarget && group.contains(event.relatedTarget)) {
                    return;
                }
                clearTimeout(closeTimer);
                closeTimer = window.setTimeout(function() {
                    closeGroup(group);
                }, 220);
            });

            group.addEventListener('focusin', function() {
                clearTimeout(closeTimer);
                openGroup(group);
            });

            group.addEventListener('focusout', function() {
                window.setTimeout(function() {
                    var active = document.activeElement;
                    var stillInside = !!(active && group.contains(active));
                    var hovered = group.matches(':hover') || submenu.matches(':hover');
                    if (!stillInside && !hovered) {
                        closeGroup(group);
                    }
                }, 120);
            });

            submenu.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeGroup(group);
                    var rootLink = group.querySelector('.metis-sidebar-group-link');
                    if (rootLink) rootLink.focus();
                }
            });

            submenu.querySelectorAll('.metis-sidebar-subitem-group').forEach(function(subGroup) {
                var subMenu = subGroup.querySelector('.metis-sidebar-subsubmenu');
                var subLink = subGroup.querySelector('.metis-sidebar-subitem-link');
                if (!subMenu) return;

                function closeSubGroup() {
                    subGroup.classList.remove('is-open');
                    if (subLink) {
                        subLink.setAttribute('aria-expanded', 'false');
                    }
                    subMenu.style.left = '';
                    subMenu.style.top = '';
                    subMenu.style.maxHeight = '';
                    subMenu.style.visibility = '';
                    subMenu.style.position = '';
                    subMenu.setAttribute('aria-hidden', 'true');
                }
                function openSubGroup() {
                    submenu.querySelectorAll('.metis-sidebar-subitem-group.is-open').forEach(function(openSub) {
                        if (openSub !== subGroup) {
                            openSub.classList.remove('is-open');
                            var openSubLink = openSub.querySelector('.metis-sidebar-subitem-link');
                            var openSubMenu = openSub.querySelector('.metis-sidebar-subsubmenu');
                            if (openSubLink) {
                                openSubLink.setAttribute('aria-expanded', 'false');
                            }
                            if (openSubMenu) {
                                openSubMenu.setAttribute('aria-hidden', 'true');
                            }
                        }
                    });
                    if (window.innerWidth > 900) {
                        subMenu.style.position = 'fixed';
                        subMenu.style.maxHeight = Math.max(160, Math.floor(window.innerHeight * 0.8)) + 'px';
                        subMenu.style.visibility = 'hidden';
                        subMenu.style.left = '-9999px';
                        subMenu.style.top = '0px';
                    }
                    subGroup.classList.add('is-open');
                    if (subLink) {
                        subLink.setAttribute('aria-expanded', 'true');
                    }
                    subMenu.setAttribute('aria-hidden', 'false');
                    if (window.innerWidth > 900) {
                        if (!subGroup.classList.contains('is-open')) return;
                        var triggerRect = subGroup.getBoundingClientRect();
                        var menuRect = subMenu.getBoundingClientRect();
                        var viewportPadding = 8;
                        var left = triggerRect.right + 8;
                        var top = triggerRect.top - 6;

                        if (left + menuRect.width > window.innerWidth - viewportPadding) {
                            left = Math.max(viewportPadding, triggerRect.left - menuRect.width - 8);
                        }
                        if (top + menuRect.height > window.innerHeight - viewportPadding) {
                            top = Math.max(viewportPadding, window.innerHeight - menuRect.height - viewportPadding);
                        }
                        if (top < viewportPadding) {
                            top = viewportPadding;
                        }

                        subMenu.style.position = 'fixed';
                        subMenu.style.left = left + 'px';
                        subMenu.style.top = top + 'px';
                        subMenu.style.maxHeight = Math.max(160, Math.floor(window.innerHeight * 0.8)) + 'px';
                        subMenu.style.visibility = '';
                    }
                }

                subGroup.addEventListener('mouseenter', function() {
                    openSubGroup();
                });

                subGroup.addEventListener('mouseleave', function() {});

                subMenu.addEventListener('mouseenter', function() {
                    openSubGroup();
                });

                subMenu.addEventListener('mouseleave', function() {});

                subGroup.addEventListener('focusin', function() {
                    openSubGroup();
                });

                subGroup.addEventListener('focusout', function() {
                    window.setTimeout(function() {
                        var active = document.activeElement;
                        var stillInside = !!(active && subGroup.contains(active));
                        var hovered = subGroup.matches(':hover') || subMenu.matches(':hover');
                        if (!stillInside && !hovered) {
                            closeSubGroup();
                        }
                    }, 120);
                });

                if (subLink) {
                    subLink.addEventListener('click', function(event) {
                        if (window.innerWidth <= 900) {
                            return;
                        }
                        event.preventDefault();
                        event.stopPropagation();
                        if (subGroup.classList.contains('is-open')) {
                            closeSubGroup();
                        } else {
                            openSubGroup();
                        }
                    });
                    subLink.addEventListener('keydown', function(event) {
                        if (event.key === 'ArrowRight' || ((event.key === 'Enter' || event.key === ' ') && String(subLink.getAttribute('href') || '') === '#')) {
                            event.preventDefault();
                            openSubGroup();
                            var firstNested = subMenu.querySelector('.metis-sidebar-subitem');
                            if (firstNested) {
                                firstNested.focus();
                            }
                        }
                        if (event.key === 'ArrowLeft' || event.key === 'Escape') {
                            event.preventDefault();
                            closeSubGroup();
                            subLink.focus();
                        }
                    });
                }
            });
        });

        function resolveNestedLink(event) {
            var target = event.target;
            if (!target) return null;
            if (target.nodeType !== 1 && target.parentElement) {
                target = target.parentElement;
            }
            if (!target || typeof target.closest !== 'function') return null;
            return target.closest('.metis-sidebar-subsubmenu .metis-sidebar-subitem[data-nav-nested-link="1"][href]');
        }

        function navigateNested(event) {
            if (event.type === 'pointerdown') {
                if (typeof event.button === 'number' && event.button !== 0) return;
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            }
            var nestedLink = resolveNestedLink(event);
            if (!nestedLink) {
                return;
            }
            var href = String(nestedLink.getAttribute('href') || '').trim();
            if (href === '' || href === '#') {
                return;
            }
            event.preventDefault();
            Metis.navigation.go(href);
        }

        sidebar.addEventListener('pointerdown', navigateNested, true);
        sidebar.addEventListener('click', navigateNested, true);

        sidebar.querySelector('.metis-sidebar-nav-scroll')?.addEventListener('scroll', function() {
            sidebar.querySelectorAll('.metis-sidebar-group.is-open').forEach(function(group) {
                openGroup(group);
            });
        });

        /* Close open groups when clicking outside */
        document.addEventListener('click', function(e) {
            var clickedInsideSidebar = sidebar.contains(e.target);
            var clickedInsideFlyout = !!(e.target.closest('.metis-sidebar-submenu') || e.target.closest('.metis-sidebar-subsubmenu'));
            if (!clickedInsideSidebar && !clickedInsideFlyout) {
                sidebar.querySelectorAll('.metis-sidebar-group.is-open').forEach(function(g) {
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

function metisInitClickableRows(root) {
    root = root || document;

    function navigateRow(row) {
        var href = String(row && row.dataset ? row.dataset.href || '' : '').trim();
        if (!href) return;
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            Metis.navigation.go(href);
            return;
        }
        window.location.assign(href);
    }

    if (!root._metisClickableRowsKeyBound) {
        root._metisClickableRowsKeyBound = true;
        root.addEventListener('keydown', function(e) {
            var row = e.target.closest('.metis-clickable-row');
            if (!row || !row.dataset.href) return;
            if (e.target.closest('a, button, input, select, textarea, .metis-btn')) return;
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            navigateRow(row);
        });
        root.addEventListener('click', function(e) {
            var row = e.target.closest('.metis-clickable-row');
            if (!row || !row.dataset.href) return;
            /* Don't navigate if click was on a button or link */
            if (e.target.closest('a, button, input, select, .metis-btn')) return;
            navigateRow(row);
        });
    }
    root.querySelectorAll('.metis-clickable-row[data-href]').forEach(function(row) {
        if (!row.hasAttribute('tabindex')) {
            row.setAttribute('tabindex', '0');
        }
        if (!row.hasAttribute('role')) {
            row.setAttribute('role', 'link');
        }
    });
}

/* ============================================================
   NAV PILLS — DESKTOP DROPDOWN
   ============================================================ */

function metisInitNavDropdowns() {
    document.querySelectorAll('.metis-pill-dropdown').forEach(function(dropdown) {
        var btn   = dropdown.querySelector('.metis-pill-has-dropdown');
        var panel = dropdown.querySelector('.metis-dropdown-panel');
        if (!btn || !panel) return;

        var closeTimer = null; // per-dropdown timer

        function openDropdown() {
            clearTimeout(closeTimer);
            // Close all others first
            document.querySelectorAll('.metis-pill-dropdown.is-open').forEach(function(d) {
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
        if (!e.target.closest('.metis-pill-dropdown')) {
            document.querySelectorAll('.metis-pill-dropdown.is-open').forEach(function(d) {
                d.classList.remove('is-open');
            });
        }
    });
}

Metis.ui.dropdown = {
    init: metisInitNavDropdowns,
    open: function(target) {
        var dropdown = typeof target === 'string' ? document.querySelector(target) : target;
        if (!dropdown) return;
        dropdown.classList.add('is-open');
        var trigger = dropdown.querySelector('.metis-pill-has-dropdown');
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
    },
    close: function(target) {
        var dropdown = typeof target === 'string' ? document.querySelector(target) : target;
        if (!dropdown) return;
        dropdown.classList.remove('is-open');
        var trigger = dropdown.querySelector('.metis-pill-has-dropdown');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    },
    toggle: function(target) {
        var dropdown = typeof target === 'string' ? document.querySelector(target) : target;
        if (!dropdown) return;
        if (dropdown.classList.contains('is-open')) {
            Metis.ui.dropdown.close(dropdown);
            return;
        }
        Metis.ui.dropdown.open(dropdown);
    },
    closeAll: function() {
        document.querySelectorAll('.metis-pill-dropdown.is-open').forEach(function(dropdown) {
            Metis.ui.dropdown.close(dropdown);
        });
    }
};


/* ============================================================
   UNIVERSAL OBJECT CODE SEARCH
   ============================================================ */

Metis.codeSearch = (function() {

    var debounceTimer = null;
    var lastQuery     = '';
    var MIN_CODE_LENGTH = 3;
    var MIN_NUMERIC_LENGTH = 2;

    function escHtml(str) {
        return Metis.util.escapeHtml(str);
    }

    function escAttr(str) {
        return Metis.util.escapeHtml(str);
    }

    function hideResult(result) {
        result.style.display = 'none';
        result.innerHTML = '';
    }

    function showError(result, msg) {
        result.className = 'metis-code-result metis-code-error';
        result.textContent = msg;
        result.style.display = 'block';
    }

    function showResult(result, data) {
        var matches = Array.isArray(data && data.matches) ? data.matches : [];
        if (!matches.length && data && data.code) {
            matches = [data];
        }
        if (!matches.length) {
            hideResult(result);
            return;
        }

        var html = '';
        matches.forEach(function(item) {
            if (!item || !item.code) return;
            var resultUrl = '';
            if (item.url && window.Metis && Metis.navigation && typeof Metis.navigation.validate === 'function') {
                var validation = Metis.navigation.validate(item.url);
                resultUrl = validation.ok ? validation.href : '';
            }
            html += '<div class="metis-code-result-item">'
                + '<div class="metis-code-result-head">'
                + '<div class="metis-code-result-label">' + escHtml(item.label || item.code) + '</div>'
                + (item.entity_type ? '<div class="metis-code-result-meta">' + escHtml(String(item.entity_type).replace(/_/g, ' ')) + '</div>' : '')
                + '</div>'
                + '<div class="metis-code-result-foot">'
                + '<div class="metis-code-result-code">' + escHtml(item.code) + '</div>'
                + (resultUrl
                    ? '<a class="metis-code-result-link" href="' + escAttr(resultUrl) + '">Open &rarr;</a>'
                    : '<span class="metis-code-result-no-url">No URL</span>'
                )
                + '</div>'
                + '</div>';
        });

        result.className = 'metis-code-result';
        result.innerHTML = html;
        result.style.display = 'block';
    }

    function minLengthFor(value) {
        var normalized = String(value || '').trim().replace(/\s+/g, '');
        if (/^[0-9]+$/.test(normalized)) {
            return MIN_NUMERIC_LENGTH;
        }
        return MIN_CODE_LENGTH;
    }

    function doLookup(input, result, code, strict) {
        code = code.toUpperCase().trim();
        var minLength = minLengthFor(code);
        if (code === lastQuery || code.length < minLength) {
            if (code.length < minLength) hideResult(result);
            return;
        }
        lastQuery = code;

        Metis.ajax.post({ action: 'metis_resolve_code', code: code, fuzzy: strict ? 0 : 1 })
            .then(function(r) {
                if (r.success && (!r.data || r.data.found !== false)) {
                    showResult(result, r.data);
                } else {
                    if (strict) {
                        showError(result, (r.data && r.data.message) ? r.data.message : 'Code not found.');
                    } else {
                        hideResult(result);
                    }
                }
            })
            .catch(function() {
                if (strict) {
                    showError(result, 'Lookup failed.');
                } else {
                    hideResult(result);
                }
            });
    }

    function init() {
        var input  = document.getElementById('metis-code-input');
        var result = document.getElementById('metis-code-result');
        if (!input || !result) return;

        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            var val = this.value.trim();
            if (val.length < minLengthFor(val)) { hideResult(result); lastQuery = ''; return; }
            debounceTimer = setTimeout(function() { doLookup(input, result, val, false); }, 350);
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { clearTimeout(debounceTimer); doLookup(input, result, this.value, false); }
            if (e.key === 'Escape') { hideResult(result); this.blur(); }
        });

        input.addEventListener('paste', function() {
            var self = this;
            setTimeout(function() {
                var val = self.value.trim();
                if (val.length >= minLengthFor(val)) { clearTimeout(debounceTimer); doLookup(input, result, val, false); }
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
        (Array.isArray(items) ? items : []).forEach(function(item, i) {
            var label = Metis.util.escapeHtml(item && item.label ? item.label : '');
            var url = item && item.url ? String(item.url) : '';
            var validUrl = '';
            if (url && window.Metis && Metis.navigation && typeof Metis.navigation.validate === 'function') {
                var validation = Metis.navigation.validate(url);
                validUrl = validation.ok ? validation.href : '';
            } else {
                validUrl = url;
            }

            if (i > 0) html += '<span class="metis-breadcrumb-sep">/</span>';
            if (validUrl && i < items.length - 1) {
                html += '<a href="' + Metis.util.escapeHtml(validUrl) + '">' + label + '</a>';
            } else {
                html += '<span class="metis-breadcrumb-current">' + label + '</span>';
            }
        });
        el.innerHTML = html;
    }
};

/* ============================================================
   PAGE INITIALIZER REGISTRY
   Metis.page.register(name, initFn)
   Metis.page.init(root, context)
   ============================================================ */

Metis.page = (function() {
    var registry = {};

    function normalizeRoot(root) {
        return root && root.querySelectorAll ? root : document;
    }

    function runCore(root) {
        var scope = normalizeRoot(root);
        if (Metis.a11y) {
            Metis.a11y.enhance(scope);
        }
        Metis.tooltip.init(scope);
        Metis.ui.select.init(scope);
        Metis.tabs.init(scope);
        Metis.modal.init(scope);
        Metis.inlineEdit.init(scope);
        metisInitClickableRows(scope);
    }

    function register(name, initFn) {
        var key = String(name || '').trim();
        if (!key || typeof initFn !== 'function') return;
        registry[key] = initFn;
    }

    function init(root, context) {
        var scope = normalizeRoot(root);
        var ctx = Object.assign({
            root: scope,
            reason: 'page-init',
            url: window.location.href
        }, context || {});

        runCore(scope);

        Object.keys(registry).forEach(function(key) {
            try {
                registry[key](ctx);
            } catch (error) {
                if (window.console && typeof window.console.error === 'function') {
                    window.console.error('Metis.page init failed for "' + key + '".', error);
                }
            }
        });
    }

    return {
        register: register,
        init: init
    };
}());

/* ============================================================
   DOM-READY BOOTSTRAP
   ============================================================ */

document.addEventListener('DOMContentLoaded', function() {
    var authNotice = window.metisAuthNotice || null;
    if (authNotice && authNotice.message && Metis.toast) {
        var level = String(authNotice.type || '').toLowerCase();
        if (level === 'error') {
            Metis.toast.error(String(authNotice.message));
        } else if (level === 'warning') {
            Metis.toast.warning(String(authNotice.message));
        } else {
            Metis.toast.success(String(authNotice.message));
        }
    }

    var moduleFailures = (window.metisAjax && Array.isArray(window.metisAjax.module_boot_failures))
        ? window.metisAjax.module_boot_failures
        : [];
    if (moduleFailures.length > 0 && Metis.toast) {
        var sessionStore = null;
        try {
            sessionStore = window.sessionStorage;
        } catch (err) {
            sessionStore = null;
        }
        moduleFailures.forEach(function(failure) {
            if (!failure || typeof failure !== 'object') return;
            var moduleSlug = String(failure.module || 'unknown');
            var reason = String(failure.reason || 'Module failed compliance verification.');
            var toastKey = 'metis.module.failure.toast.' + moduleSlug + '.' + reason;
            if (sessionStore && sessionStore.getItem(toastKey) === '1') {
                return;
            }
            Metis.toast.error('The "' + moduleSlug + '" module was disabled. ' + reason, {
                title: 'Module Disabled (Compliance)',
                duration: 9000
            });
            if (sessionStore) {
                try {
                    sessionStore.setItem(toastKey, '1');
                } catch (err) {
                    /* Session storage unavailable; allow duplicate toasts in this case. */
                }
            }
        });
    }

    if (Metis.a11y) {
        Metis.a11y.enhance(document);
    }
    Metis.accessibility.init();
    Metis.session.init();
    Metis.navigation.init();
    Metis.quickActions.init();
    Metis.nav.init();
    Metis.codeSearch.init();
    Metis.page.init(document, {
        reason: 'dom-ready',
        url: window.location.href
    });
});
