'use strict';

window.Metis = window.Metis || {};

Metis.help = (function () {
    const config = window.metisHelp || {};
    const requestConfig = {
        ajax_url: config.ajax_url || (window.metisAjax && window.metisAjax.ajax_url) || '',
        nonce: config.nonce || (window.metisAjax && window.metisAjax.nonce) || '',
        action_nonces: config.action_nonces || {}
    };
    let enabled = false;
    let loaded = false;
    let topics = {};
    let hoverCard = null;
    let focusedTarget = null;

    function ensureHoverCard() {
        if (hoverCard) return hoverCard;
        hoverCard = document.createElement('div');
        hoverCard.className = 'metis-help-hovercard';
        document.body.appendChild(hoverCard);
        return hoverCard;
    }

    function resolveDocUrl(path) {
        path = String(path || '');
        if (!path) return '';
        if (/^https?:\/\//i.test(path)) return path;
        const base = String(config.docs_base_url || '').replace(/\/$/, '');
        return base + '/' + path.replace(/^\//, '');
    }

    function indexPromise() {
        if (loaded) return Promise.resolve(topics);
        return Metis.request.post(requestConfig, 'metis_help_index', {
            domain: config.current_domain || '',
            view: config.current_view || ''
        }, 'Help is not configured.')
            .then(function (data) {
                topics = data.topics || {};
                loaded = true;
                if (data.bootstrap && data.bootstrap.autostart && data.bootstrap.autostart.id && config.walkthrough_enabled) {
                    window.setTimeout(function () {
                        if (Metis.walkthrough) Metis.walkthrough.start(String(data.bootstrap.autostart.id));
                    }, 400);
                }
                return topics;
            });
    }

    function normalizeTopicId(value) {
        return String(value || '').trim();
    }

    function currentTopicId() {
        return String(config.current_topic || document.querySelector('[data-metis-topic]')?.getAttribute('data-metis-topic') || '');
    }

    function clearFallbackTags() {
        document.querySelectorAll('[data-help-fallback]').forEach(function (element) {
            element.removeAttribute('data-help-fallback');
            element.classList.remove('metis-help-fallback');
        });
    }

    function tagFallbackElements() {
        clearFallbackTags();
        const scope = document.querySelector('.metis-view-shell');
        if (!scope) return;
        const topic = currentTopicId();
        if (!topic) return;
        const selectors = [
            'section',
            'article',
            'form',
            '.metis-page-header',
            '.metis-settings-card',
            '.metis-premium-table',
            '.metis-table-wrap',
            '.metis-list-content',
            '.metis-tile',
            '.metis-stat-card',
            '.metis-calendar-shell',
            '.metis-calendar-toolbar-left'
        ];
        let tagged = 0;

        scope.querySelectorAll(selectors.join(',')).forEach(function (element) {
            if (tagged >= 18) return;
            if (element.hasAttribute('data-help') || element.closest('[data-help]')) return;
            if (element.querySelector('[data-help]')) return;
            if (String(element.textContent || '').trim() === '') return;
            element.classList.add('metis-help-fallback');
            element.setAttribute('data-help-fallback', topic);
            tagged += 1;
        });

        if (tagged < 1) {
            scope.classList.add('metis-help-fallback');
            scope.setAttribute('data-help-fallback', topic);
        }
    }

    function clearFocusedTarget() {
        if (!focusedTarget) return;
        focusedTarget.classList.remove('metis-help-guided-target');
        focusedTarget.removeAttribute('data-help-guided-target');
        focusedTarget = null;
    }

    function focusTarget(options) {
        options = options || {};
        clearFocusedTarget();

        const selector = String(options.selector || '').trim();
        const fallbackSelector = String(options.fallbackSelector || '.metis-view-shell').trim();
        const target = selector
            ? document.querySelector(selector) || document.querySelector(fallbackSelector)
            : document.querySelector(fallbackSelector);

        if (!target) {
            return false;
        }

        focusedTarget = target;
        target.classList.add('metis-help-guided-target');
        target.setAttribute('data-help-guided-target', '1');
        target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
        window.setTimeout(function () {
            if (focusedTarget === target) {
                clearFocusedTarget();
            }
        }, 4200);

        return true;
    }

    function topicForElement(element) {
        const explicit = element.closest('[data-help]');
        if (explicit) return normalizeTopicId(explicit.getAttribute('data-help'));
        const fallback = element.closest('[data-help-fallback]');
        if (fallback) return normalizeTopicId(fallback.getAttribute('data-help-fallback'));
        return currentTopicId();
    }

    function showHover(event, topic) {
        const card = ensureHoverCard();
        card.innerHTML = '' +
            '<strong>' + Metis.util.escapeHtml(topic.title || 'Help') + '</strong>' +
            (topic.description ? '<span>' + Metis.util.escapeHtml(topic.description) + '</span>' : '');
        card.style.left = (event.pageX + 14) + 'px';
        card.style.top = (event.pageY + 14) + 'px';
        card.classList.add('is-visible');
    }

    function hideHover() {
        if (!hoverCard) return;
        hoverCard.classList.remove('is-visible');
    }

    function bindDocument() {
        document.addEventListener('mouseover', function (event) {
            if (!enabled) return;
            const topicId = topicForElement(event.target);
            if (!topicId || !topics[topicId]) return;
            showHover(event, topics[topicId]);
        });

        document.addEventListener('mousemove', function (event) {
            if (!enabled || !hoverCard || !hoverCard.classList.contains('is-visible')) return;
            hoverCard.style.left = (event.pageX + 14) + 'px';
            hoverCard.style.top = (event.pageY + 14) + 'px';
        });

        document.addEventListener('mouseout', function () {
            if (!enabled) return;
            hideHover();
        });

        document.addEventListener('click', function (event) {
            if (!enabled) return;
            if (event.target.closest('#metis-help-toggle')) {
                event.preventDefault();
                event.stopPropagation();
                toggle(false);
                return;
            }
            if (event.target.closest('#metis-help-search-trigger')) {
                event.preventDefault();
                event.stopPropagation();
                if (Metis.helpSearch) Metis.helpSearch.open();
                return;
            }
            const topicId = topicForElement(event.target);
            if (!topicId) return;
            const interactive = event.target.closest('a, button, [role="button"], input, select, textarea');
            if (!interactive) return;
            event.preventDefault();
            event.stopPropagation();
            openTopic(topicId);
        }, true);

    }

    function toggle(force) {
        const next = typeof force === 'boolean' ? force : !enabled;
        enabled = next;
        document.body.classList.toggle('metis-help-mode', enabled);
        const toggleButton = document.getElementById('metis-help-toggle');
        if (toggleButton) toggleButton.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        if (!enabled) {
            hideHover();
            return;
        }

        tagFallbackElements();
        indexPromise().catch(function (error) {
            enabled = false;
            document.body.classList.remove('metis-help-mode');
            if (Metis.toast) {
                Metis.toast.error(error.message || 'Help could not be loaded.');
            }
        });
    }

    function openTopic(topicId) {
        topicId = normalizeTopicId(topicId);
        return indexPromise().then(function () {
            const topic = topics[topicId];
            if (!topic) {
                throw new Error('Help topic not found.');
            }

            topic.learn_more_url = resolveDocUrl(topic.learn_more || topic.learn_more_url || '');
            Metis.helpPanel.open(topic);
        }).catch(function (error) {
            if (Metis.toast) {
                Metis.toast.error(error.message || 'Help topic unavailable.');
            }
        });
    }

    function bindShell() {
        const toggleButton = document.getElementById('metis-help-toggle');
        if (toggleButton) {
            toggleButton.addEventListener('click', function () { toggle(); });
        }

        const searchButton = document.getElementById('metis-help-search-trigger');
        if (searchButton) {
            searchButton.addEventListener('click', function () {
                if (Metis.helpSearch) Metis.helpSearch.open();
            });
        }
    }

    function init() {
        if (!config.enabled) return;
        bindShell();
        bindDocument();
    }

    return {
        init: init,
        toggle: toggle,
        openTopic: openTopic,
        resolveDocUrl: resolveDocUrl,
        focusTarget: focusTarget,
        retagFallbackElements: tagFallbackElements
    };
}());

document.addEventListener('DOMContentLoaded', function () {
    if (Metis.help) Metis.help.init();
});
