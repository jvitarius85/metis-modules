'use strict';

window.Metis = window.Metis || {};

Metis.walkthrough = (function () {
    let overlay = null;
    let tooltip = null;
    let state = null;

    function requestConfig() {
        const helpConfig = window.metisHelp || {};
        return {
            ajax_url: helpConfig.ajax_url || (window.metisAjax && window.metisAjax.ajax_url) || '',
            nonce: helpConfig.nonce || (window.metisAjax && window.metisAjax.nonce) || '',
            action_nonces: helpConfig.action_nonces || {}
        };
    }

    function ensure() {
        if (overlay) return;

        overlay = document.createElement('div');
        overlay.className = 'metis-walkthrough-overlay';
        overlay.innerHTML = '<div class="metis-walkthrough-highlight"></div>';
        tooltip = document.createElement('div');
        tooltip.className = 'metis-walkthrough-tooltip';
        document.body.appendChild(overlay);
        document.body.appendChild(tooltip);
    }

    function position(target) {
        const rect = target.getBoundingClientRect();
        const highlight = overlay.querySelector('.metis-walkthrough-highlight');
        highlight.style.left = (window.scrollX + rect.left - 8) + 'px';
        highlight.style.top = (window.scrollY + rect.top - 8) + 'px';
        highlight.style.width = (rect.width + 16) + 'px';
        highlight.style.height = (rect.height + 16) + 'px';

        tooltip.style.left = (window.scrollX + rect.left) + 'px';
        tooltip.style.top = (window.scrollY + rect.bottom + 12) + 'px';
    }

    function renderStep() {
        if (!state || !state.walkthrough) return;
        const step = state.walkthrough.steps[state.index];
        if (!step) {
            finish(true, false);
            return;
        }

        const target = document.querySelector(step.target);
        if (!target) {
            tooltip.innerHTML = '' +
                '<div class="metis-walkthrough-tooltip__title">' + Metis.util.escapeHtml(state.walkthrough.title || 'Walkthrough') + '</div>' +
                '<div class="metis-walkthrough-tooltip__message">Target not found for this step. You can continue manually.</div>' +
                controlsHtml();
            bindControls();
            return;
        }

        position(target);
        target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });

        tooltip.innerHTML = '' +
            '<div class="metis-walkthrough-tooltip__title">' + Metis.util.escapeHtml(state.walkthrough.title || 'Walkthrough') + '</div>' +
            '<div class="metis-walkthrough-tooltip__message">' + Metis.util.escapeHtml(step.message || '') + '</div>' +
            controlsHtml();
        bindControls();

        if (String(step.advance || 'click') === 'click') {
            state.targetCleanup = function () {
                target.removeEventListener('click', state.advanceHandler, true);
            };
            state.advanceHandler = function () {
                next();
            };
            target.addEventListener('click', state.advanceHandler, true);
        }
    }

    function controlsHtml() {
        return '' +
            '<div class="metis-walkthrough-tooltip__controls">' +
                '<button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-walkthrough-action="back">Back</button>' +
                '<button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-walkthrough-action="skip">Skip</button>' +
                '<button type="button" class="mw-btn mw-btn-xs" data-walkthrough-action="next">Next</button>' +
            '</div>';
    }

    function bindControls() {
        tooltip.querySelectorAll('[data-walkthrough-action]').forEach(function (button) {
            button.onclick = function () {
                const action = button.getAttribute('data-walkthrough-action');
                if (action === 'back') back();
                if (action === 'skip') finish(false, true);
                if (action === 'next') next();
            };
        });
    }

    function clearTargetHandler() {
        if (state && typeof state.targetCleanup === 'function') {
            state.targetCleanup();
        }
        if (state) {
            state.targetCleanup = null;
            state.advanceHandler = null;
        }
    }

    function next() {
        if (!state) return;
        clearTargetHandler();
        state.index += 1;
        persist(false, false);
        renderStep();
    }

    function back() {
        if (!state) return;
        clearTargetHandler();
        state.index = Math.max(0, state.index - 1);
        persist(false, false);
        renderStep();
    }

    function persist(completed, skipped) {
        if (!state) return;
        Metis.request.post(requestConfig(), 'metis_walkthrough_progress', {
            walkthrough: state.id,
            step: state.index,
            completed: completed ? '1' : '',
            skipped: skipped ? '1' : ''
        }, 'Walkthrough progress is not configured.').catch(function () {});
    }

    function finish(completed, skipped) {
        clearTargetHandler();
        if (overlay) overlay.classList.remove('is-open');
        if (tooltip) tooltip.classList.remove('is-open');
        document.body.classList.remove('metis-walkthrough-active');
        persist(completed, skipped);
        state = null;
    }

    function start(id) {
        ensure();
        return Metis.request.post(requestConfig(), 'metis_walkthrough_get', {
            walkthrough: id
        }, 'Walkthroughs are not configured.')
            .then(function (data) {
                state = {
                    id: id,
                    index: Math.max(0, Number((data.progress || {})[id] && (data.progress || {})[id].step || 0)),
                    walkthrough: data.walkthrough || null,
                    targetCleanup: null,
                    advanceHandler: null
                };

                if (!state.walkthrough || !Array.isArray(state.walkthrough.steps)) {
                    throw new Error('Walkthrough is unavailable.');
                }

                overlay.classList.add('is-open');
                tooltip.classList.add('is-open');
                document.body.classList.add('metis-walkthrough-active');
                renderStep();
            })
            .catch(function (error) {
                if (Metis.toast) {
                    Metis.toast.error(error.message || 'Walkthrough failed to start.');
                }
            });
    }

    return {
        start: start,
        finish: finish
    };
}());
