'use strict';

(function() {
    function normalizeCard(card) {
        if (!card || card.dataset.kpiReady === '1') {
            return;
        }

        var label = card.querySelector('.kpi-label, .metis-kpi-label');
        var value = card.querySelector('.kpi-value, .metis-kpi-value');
        if (!label || !value) {
            return;
        }

        var body = card.querySelector('.kpi-card-body');
        if (!body) {
            body = document.createElement('div');
            body.className = 'kpi-card-body';
            while (card.firstChild) {
                body.appendChild(card.firstChild);
            }
            card.appendChild(body);
        }

        card.dataset.kpiReady = '1';
    }

    function init(root) {
        (root || document).querySelectorAll('.kpi-card, .metis-kpi-card').forEach(normalizeCard);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { init(document); });
    } else {
        init(document);
    }

    window.Metis = window.Metis || {};
    window.Metis.kpiCards = { init: init };
}());
