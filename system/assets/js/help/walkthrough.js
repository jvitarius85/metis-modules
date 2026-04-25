'use strict';

window.Metis = window.Metis || {};

Metis.walkthrough = (function () {
    let overlay = null;
    let tooltip = null;
    let state = null;
    let viewportEventsBound = false;
    let repositionFrame = 0;
    const VIEWPORT_PADDING = 16;
    const HIGHLIGHT_PADDING = 8;
    const TOOLTIP_GAP = 14;

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

    function clamp(value, min, max) {
        if (max < min) return min;
        return Math.min(Math.max(value, min), max);
    }

    function positionMissingTooltip() {
        const tooltipRect = tooltip.getBoundingClientRect();
        const left = clamp((window.innerWidth - tooltipRect.width) / 2, VIEWPORT_PADDING, window.innerWidth - tooltipRect.width - VIEWPORT_PADDING);
        const top = clamp((window.innerHeight - tooltipRect.height) / 2, VIEWPORT_PADDING, window.innerHeight - tooltipRect.height - VIEWPORT_PADDING);
        tooltip.style.left = left + 'px';
        tooltip.style.top = top + 'px';
        tooltip.setAttribute('data-placement', 'center');
    }

    function position(target) {
        if (!overlay || !tooltip || !target) return;
        const rect = target.getBoundingClientRect();
        const highlight = overlay.querySelector('.metis-walkthrough-highlight');
        const highlightLeft = clamp(rect.left - HIGHLIGHT_PADDING, VIEWPORT_PADDING / 2, window.innerWidth - VIEWPORT_PADDING);
        const highlightTop = clamp(rect.top - HIGHLIGHT_PADDING, VIEWPORT_PADDING / 2, window.innerHeight - VIEWPORT_PADDING);
        const highlightWidth = clamp(rect.width + (HIGHLIGHT_PADDING * 2), 32, window.innerWidth - highlightLeft - (VIEWPORT_PADDING / 2));
        const highlightHeight = clamp(rect.height + (HIGHLIGHT_PADDING * 2), 32, window.innerHeight - highlightTop - (VIEWPORT_PADDING / 2));

        highlight.style.left = highlightLeft + 'px';
        highlight.style.top = highlightTop + 'px';
        highlight.style.width = highlightWidth + 'px';
        highlight.style.height = highlightHeight + 'px';

        tooltip.style.maxHeight = Math.max(180, window.innerHeight - (VIEWPORT_PADDING * 2)) + 'px';

        let tooltipRect = tooltip.getBoundingClientRect();
        const availableBelow = window.innerHeight - rect.bottom - TOOLTIP_GAP - VIEWPORT_PADDING;
        const availableAbove = rect.top - TOOLTIP_GAP - VIEWPORT_PADDING;
        let placement = 'bottom';

        if (tooltipRect.height > availableBelow && availableAbove > availableBelow) {
            placement = 'top';
        }

        const placementHeight = placement === 'top' ? availableAbove : availableBelow;
        if (placementHeight > 0 && tooltipRect.height > placementHeight) {
            tooltip.style.maxHeight = Math.max(180, placementHeight) + 'px';
            tooltipRect = tooltip.getBoundingClientRect();
            if (tooltipRect.height > availableBelow && availableAbove > availableBelow) {
                placement = 'top';
            }
        }

        const tooltipLeft = clamp(rect.left, VIEWPORT_PADDING, window.innerWidth - tooltipRect.width - VIEWPORT_PADDING);
        const tooltipTop = placement === 'top'
            ? rect.top - tooltipRect.height - TOOLTIP_GAP
            : rect.bottom + TOOLTIP_GAP;

        tooltip.style.left = tooltipLeft + 'px';
        tooltip.style.top = clamp(tooltipTop, VIEWPORT_PADDING, window.innerHeight - tooltipRect.height - VIEWPORT_PADDING) + 'px';
        tooltip.setAttribute('data-placement', placement);
    }

    function schedulePosition() {
        if (!state || !state.targetElement) return;
        if (repositionFrame) {
            window.cancelAnimationFrame(repositionFrame);
        }
        repositionFrame = window.requestAnimationFrame(function () {
            position(state.targetElement);
        });
    }

    function bindViewportEvents() {
        if (viewportEventsBound) return;
        viewportEventsBound = true;
        window.addEventListener('resize', schedulePosition);
        window.addEventListener('scroll', schedulePosition, true);
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
            state.targetElement = null;
            tooltip.innerHTML = '' +
                '<div class="metis-walkthrough-tooltip__title">' + Metis.util.escapeHtml(state.walkthrough.title || 'Walkthrough') + '</div>' +
                '<div class="metis-walkthrough-tooltip__message">Target not found for this step. You can continue manually.</div>' +
                controlsHtml();
            bindControls();
            positionMissingTooltip();
            return;
        }

        tooltip.innerHTML = '' +
            '<div class="metis-walkthrough-tooltip__title">' + Metis.util.escapeHtml(state.walkthrough.title || 'Walkthrough') + '</div>' +
            '<div class="metis-walkthrough-tooltip__message">' + Metis.util.escapeHtml(step.message || '') + '</div>' +
            controlsHtml();
        bindControls();
        state.targetElement = target;
        target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
        position(target);
        window.setTimeout(schedulePosition, 180);

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
                '<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-walkthrough-action="back">Back</button>' +
                '<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-walkthrough-action="skip">Skip</button>' +
                '<button type="button" class="metis-btn metis-btn-xs" data-walkthrough-action="next">Next</button>' +
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
                bindViewportEvents();
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
