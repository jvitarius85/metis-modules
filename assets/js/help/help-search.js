'use strict';

window.Metis = window.Metis || {};

Metis.helpSearch = (function () {
    let modal = null;
    let input = null;
    let results = null;

    function requestConfig() {
        const helpConfig = window.metisHelp || {};
        return {
            ajax_url: helpConfig.ajax_url || (window.metisAjax && window.metisAjax.ajax_url) || '',
            nonce: helpConfig.nonce || (window.metisAjax && window.metisAjax.nonce) || '',
            action_nonces: helpConfig.action_nonces || {}
        };
    }

    function ensure() {
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'metis-help-search-modal';
        modal.className = 'mw-modal-backdrop metis-help-search-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = '' +
            '<div class="mw-modal metis-help-search-modal__dialog">' +
                '<div class="mw-modal-header">' +
                    '<h2 id="metis-help-search-title">Help Search</h2>' +
                    '<button type="button" class="mw-modal-close" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="mw-modal-body">' +
                    '<label class="screen-reader-text" for="metis-help-search-input">Search help topics, docs, or walkthroughs</label>' +
                    '<input id="metis-help-search-input" type="search" class="mw-input metis-help-search-modal__input" placeholder="Search help topics, docs, or walkthroughs">' +
                    '<div class="metis-help-search-modal__results" role="status" aria-live="polite"></div>' +
                '</div>' +
            '</div>';

        document.body.appendChild(modal);
        if (window.Metis && Metis.modal) {
            Metis.modal.init(document);
        }

        input = modal.querySelector('.metis-help-search-modal__input');
        results = modal.querySelector('.metis-help-search-modal__results');

        let timer = 0;
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = window.setTimeout(function () {
                search(input.value);
            }, 140);
        });

        results.addEventListener('click', function (event) {
            const button = event.target.closest('[data-help-result]');
            if (!button) return;

            const type = String(button.getAttribute('data-result-type') || '');
            const id = String(button.getAttribute('data-help-result') || '');

            if (type === 'walkthrough' && Metis.walkthrough) {
                Metis.walkthrough.start(id);
                close();
                return;
            }

            if (type === 'topic' && Metis.help) {
                Metis.help.openTopic(id);
                close();
                return;
            }

            if (type === 'doc') {
                const href = String(button.getAttribute('data-doc-url') || '');
                if (href) {
                    window.open(href, '_blank', 'noopener');
                }
            }
        });

        return modal;
    }

    function render(items) {
        if (!items || !items.length) {
            results.innerHTML = '<div class="mw-muted">No matching help content.</div>';
            return;
        }

        results.innerHTML = items.map(function (item) {
            const title = Metis.util.escapeHtml(item.title || item.id || 'Result');
            const description = Metis.util.escapeHtml(item.description || '');
            const type = Metis.util.escapeHtml(item.type || 'topic');
            const docUrl = item.learn_more ? ' data-doc-url="' + Metis.util.escapeHtml(Metis.help.resolveDocUrl(item.learn_more)) + '"' : '';
            return '' +
                '<button type="button" class="metis-help-search-result" data-help-result="' + Metis.util.escapeHtml(item.id || '') + '" data-result-type="' + type + '"' + docUrl + '>' +
                    '<span class="metis-help-search-result__type">' + type + '</span>' +
                    '<strong>' + title + '</strong>' +
                    (description ? '<span>' + description + '</span>' : '') +
                '</button>';
        }).join('');
    }

    function search(query) {
        query = String(query || '').trim();
        if (!query) {
            results.innerHTML = '<div class="mw-muted">Search for a workflow, module, or action.</div>';
            return Promise.resolve();
        }

        return Metis.request.post(requestConfig(), 'metis_help_search', { query: query }, 'Help search is not configured.')
            .then(function (data) {
                render(data.results || []);
            })
            .catch(function (error) {
                results.innerHTML = '<div class="mw-alert mw-alert-error">' + Metis.util.escapeHtml(error.message || 'Search failed.') + '</div>';
            });
    }

    function open() {
        ensure();
        if (window.Metis && Metis.modal) {
            Metis.modal.open(modal);
        } else {
            modal.classList.add('is-open');
        }
        input.focus();
        search(input.value);
    }

    function close() {
        if (!modal) return;
        if (window.Metis && Metis.modal) {
            Metis.modal.close(modal);
        } else {
            modal.classList.remove('is-open');
        }
    }

    return {
        ensure: ensure,
        open: open,
        close: close
    };
}());
