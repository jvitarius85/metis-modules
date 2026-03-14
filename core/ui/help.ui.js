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

    function tagFallbackElements() {
        const scope = document.querySelector('.metis-view-shell');
        if (!scope) return;
        const topic = currentTopicId();
        if (!topic) return;

        scope.querySelectorAll('button, a, input, select, textarea').forEach(function (element) {
            if (element.hasAttribute('data-help')) return;
            element.classList.add('metis-help-fallback');
            element.setAttribute('data-help-fallback', topic);
        });
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
            if (event.target.closest('#mw-help-toggle')) {
                event.preventDefault();
                event.stopPropagation();
                toggle(false);
                return;
            }
            if (event.target.closest('#mw-help-search-trigger')) {
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

        document.addEventListener('keydown', function (event) {
            if (event.shiftKey && String(event.key).toUpperCase() === 'H') {
                event.preventDefault();
                toggle();
            }
            if ((event.metaKey || event.ctrlKey) && String(event.key).toLowerCase() === 'k') {
                const trigger = document.getElementById('mw-help-search-trigger');
                if (trigger) {
                    event.preventDefault();
                    if (Metis.helpSearch) Metis.helpSearch.open();
                }
            }
        });
    }

    function toggle(force) {
        const next = typeof force === 'boolean' ? force : !enabled;
        enabled = next;
        document.body.classList.toggle('metis-help-mode', enabled);
        const toggleButton = document.getElementById('mw-help-toggle');
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
        const toggleButton = document.getElementById('mw-help-toggle');
        if (toggleButton) {
            toggleButton.addEventListener('click', function () { toggle(); });
        }

        const searchButton = document.getElementById('mw-help-search-trigger');
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
        resolveDocUrl: resolveDocUrl
    };
}());

document.addEventListener('DOMContentLoaded', function () {
    if (Metis.help) Metis.help.init();
});
