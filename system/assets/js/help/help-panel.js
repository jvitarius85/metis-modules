'use strict';

window.Metis = window.Metis || {};

Metis.helpPanel = (function () {
    let panel = null;
    let body = null;
    let title = null;
    let docLink = null;
    let walkthroughButton = null;

    function ensure() {
        if (panel) return panel;

        panel = document.createElement('aside');
        panel.className = 'metis-help-panel';
        panel.setAttribute('aria-hidden', 'true');
        panel.innerHTML = '' +
            '<div class="metis-help-panel__header">' +
                '<div>' +
                    '<div class="metis-help-panel__eyebrow">Contextual Help</div>' +
                    '<h2 class="metis-help-panel__title"></h2>' +
                '</div>' +
                '<button type="button" class="metis-help-panel__close" aria-label="Close help panel">&times;</button>' +
            '</div>' +
            '<div class="metis-help-panel__body"></div>' +
            '<div class="metis-help-panel__actions">' +
                '<button type="button" class="metis-btn metis-btn-ghost metis-help-panel__walkthrough" hidden>Start Walkthrough</button>' +
                '<a class="metis-btn metis-help-panel__doc" href="#" target="_blank" rel="noopener" hidden>Open Full Documentation</a>' +
            '</div>';

        document.body.appendChild(panel);
        body = panel.querySelector('.metis-help-panel__body');
        title = panel.querySelector('.metis-help-panel__title');
        docLink = panel.querySelector('.metis-help-panel__doc');
        walkthroughButton = panel.querySelector('.metis-help-panel__walkthrough');

        panel.querySelector('.metis-help-panel__close').addEventListener('click', close);
        return panel;
    }

    function renderList(items) {
        if (!items || !items.length) return '';
        return '<ol class="metis-help-panel__steps">' + items.map(function (step) {
            return '<li>' + Metis.util.escapeHtml(step) + '</li>';
        }).join('') + '</ol>';
    }

    function open(topic, options) {
        options = options || {};
        ensure();
        title.textContent = String((topic && topic.title) || 'Help');

        const parts = [];
        if (topic && topic.description) {
            parts.push('<p>' + Metis.util.escapeHtml(topic.description) + '</p>');
        }
        if (topic && Array.isArray(topic.steps) && topic.steps.length) {
            parts.push('<h3>Steps</h3>');
            parts.push(renderList(topic.steps));
        }
        if (topic && Array.isArray(topic.walkthroughs) && topic.walkthroughs.length) {
            parts.push('<p class="metis-help">Interactive walkthroughs are available for this topic.</p>');
        }
        body.innerHTML = parts.join('');

        if (topic && topic.learn_more_url) {
            docLink.href = String(topic.learn_more_url);
            docLink.hidden = false;
        } else {
            docLink.removeAttribute('href');
            docLink.hidden = true;
        }

        const walkthroughId = options.walkthrough || (topic && topic.walkthroughs && topic.walkthroughs[0]) || '';
        if (walkthroughId) {
            walkthroughButton.hidden = false;
            walkthroughButton.onclick = function () {
                if (Metis.walkthrough && typeof Metis.walkthrough.start === 'function') {
                    Metis.walkthrough.start(String(walkthroughId));
                }
            };
        } else {
            walkthroughButton.hidden = true;
            walkthroughButton.onclick = null;
        }

        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        document.body.classList.add('metis-help-panel-open');
    }

    function close() {
        if (!panel) return;
        panel.classList.remove('is-open');
        panel.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('metis-help-panel-open');
    }

    return {
        ensure: ensure,
        open: open,
        close: close
    };
}());
