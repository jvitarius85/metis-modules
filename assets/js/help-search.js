'use strict';

window.Metis = window.Metis || {};

Metis.helpSearch = (function () {
    let modal = null;
    let root = null;
    let input = null;
    let category = null;
    let results = null;
    let empty = null;
    let loading = null;
    let timer = 0;
    let requestCounter = 0;
    let categoriesLoaded = false;

    function config() {
        const helpConfig = window.metisHelp || {};
        const ajaxConfig = window.metisAjax || {};
        return {
            endpoint: String(helpConfig.search_endpoint || '').trim(),
            nonce: String(helpConfig.search_nonce || helpConfig.nonce || '').trim(),
            baseUrl: String(helpConfig.docs_base_url || ajaxConfig.site_url || '').trim()
        };
    }

    function appBaseUrl() {
        const runtime = config();
        if (runtime.baseUrl) {
            return runtime.baseUrl.replace(/\/+$/, '');
        }

        return String(window.location.origin || '').replace(/\/+$/, '');
    }

    function normalizeResultUrl(rawUrl) {
        const value = String(rawUrl || '').trim();
        if (!value) {
            return '';
        }

        const baseUrl = appBaseUrl();

        if (/^https?:\/\//i.test(value)) {
            return value.replace(/\/enclave\/help\/admin\/help\//i, '/admin/help/');
        }

        if (value.indexOf('/enclave/help/admin/help/') !== -1) {
            return baseUrl + '/' + value.replace(/^\/+/, '').replace(/enclave\/help\/admin\/help\//i, 'admin/help/');
        }

        if (/^\/admin\/help\//i.test(value)) {
            return baseUrl + value;
        }

        if (/^admin\/help\//i.test(value)) {
            return baseUrl + '/' + value;
        }

        if (value.indexOf('/admin/help/') !== -1) {
            return baseUrl + value.slice(value.indexOf('/admin/help/'));
        }

        return value;
    }

    function ensure() {
        if (modal) {
            return modal;
        }

        modal = document.getElementById('metis-help-search-modal');
        if (!modal) {
            return null;
        }

        root = modal.querySelector('[data-help-search-root]');
        input = modal.querySelector('#helpSearchInput');
        category = modal.querySelector('#helpSearchCategory');
        results = modal.querySelector('#helpSearchResults');
        empty = modal.querySelector('#helpSearchEmpty');
        loading = modal.querySelector('#helpSearchLoading');

        if (!root || !input || !category || !results || !empty || !loading) {
            return null;
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-help-search-close]')) {
                close();
            }
        });

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = window.setTimeout(function () {
                runSearch(1);
            }, 300);
        });

        category.addEventListener('change', function () {
            runSearch(1);
        });

        results.addEventListener('click', function (event) {
            const item = event.target.closest('[data-help-result]');
            if (!item) {
                return;
            }

            const topicId = String(item.getAttribute('data-topic-id') || '').trim();
            const url = normalizeResultUrl(item.getAttribute('data-url') || '');

            if (topicId && window.Metis && Metis.help && typeof Metis.help.openTopic === 'function') {
                Metis.help.openTopic(topicId);
                close();
                return;
            }

            if (url) {
                window.location.assign(url);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal && modal.getAttribute('aria-hidden') === 'false') {
                close();
            }
        });

        return modal;
    }

    function setLoading(isLoading) {
        if (!loading) {
            return;
        }

        loading.classList.toggle('hidden', !isLoading);
    }

    function setEmpty(isVisible, message) {
        if (!empty) {
            return;
        }

        empty.textContent = message || 'No results found';
        empty.classList.toggle('hidden', !isVisible);
    }

    function clearResults() {
        if (results) {
            results.innerHTML = '';
        }

        setEmpty(false);
        setLoading(false);
    }

    function renderCategories(items) {
        if (!category || categoriesLoaded) {
            return;
        }

        const current = String(category.value || '').trim();
        const options = ['<option value="">All categories</option>'];
        (items || []).forEach(function (item) {
            const slug = String(item.slug || '').trim();
            const name = String(item.name || '').trim();
            if (!slug || !name) {
                return;
            }

            const selected = current === slug ? ' selected' : '';
            options.push('<option value="' + Metis.util.escapeHtml(slug) + '"' + selected + '>' + Metis.util.escapeHtml(name) + '</option>');
        });

        category.innerHTML = options.join('');
        categoriesLoaded = true;
    }

    function renderResults(items, queryExecuted) {
        if (!results) {
            return;
        }

        if (!Array.isArray(items) || items.length === 0) {
            results.innerHTML = '';
            setEmpty(queryExecuted, queryExecuted ? 'No results found' : 'No help articles are available yet.');
            return;
        }

        setEmpty(false);
        results.innerHTML = items.map(function (item) {
            const title = Metis.util.escapeHtml(String(item.title || 'Help result'));
            const summary = Metis.util.escapeHtml(String(item.summary || ''));
            const meta = Metis.util.escapeHtml(String(item.category || 'Help'));
            const url = Metis.util.escapeHtml(normalizeResultUrl(item.url || ''));
            const topicId = Metis.util.escapeHtml(String(item.topic_id || ''));

            return '' +
                '<button type="button" class="help-result-item" data-help-result="1" data-url="' + url + '" data-topic-id="' + topicId + '">' +
                    '<div class="help-result-title">' + title + '</div>' +
                    (summary ? '<div class="help-result-summary">' + summary + '</div>' : '') +
                    '<div class="help-result-meta">' + meta + '</div>' +
                '</button>';
        }).join('');
    }

    function buildBody(page) {
        const body = new URLSearchParams();
        body.set('query', String((input && input.value) || '').trim());
        body.set('category', String((category && category.value) || '').trim());
        body.set('limit', '10');
        body.set('page', String(page || 1));
        body.set('nonce', config().nonce);
        body.set('metis_action_nonce', config().nonce);
        return body;
    }

    function fetchSearch(page) {
        const runtime = config();
        if (!runtime.endpoint || !runtime.nonce) {
            return Promise.reject(new Error('Help search is not configured.'));
        }

        return fetch(runtime.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: buildBody(page).toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return {
                    success: false,
                    message: 'Help search returned an invalid response.'
                };
            });
        }).then(function (payload) {
            if (!payload || payload.success !== true) {
                throw new Error((payload && payload.message) || 'Search failed.');
            }
            return payload;
        });
    }

    function runSearch(page) {
        if (!ensure()) {
            return Promise.resolve();
        }

        const query = String(input.value || '').trim();
        const currentRequest = ++requestCounter;
        const queryExecuted = query.length >= 2 || String(category.value || '').trim() !== '';

        if (!queryExecuted) {
            return fetchSearch(page).then(function (payload) {
                if (currentRequest !== requestCounter) {
                    return;
                }
                renderCategories(payload.categories || []);
                renderResults(payload.results || [], false);
            }).catch(function () {
                renderResults([], false);
            });
        }

        if (query.length > 0 && query.length < 2) {
            clearResults();
            return Promise.resolve();
        }

        setLoading(true);
        setEmpty(false);

        return fetchSearch(page).then(function (payload) {
            if (currentRequest !== requestCounter) {
                return;
            }
            renderCategories(payload.categories || []);
            renderResults(payload.results || [], true);
        }).catch(function (error) {
            clearResults();
            if (window.Metis && Metis.toast && typeof Metis.toast.error === 'function') {
                Metis.toast.error((error && error.message) || 'Search failed.');
            }
        }).finally(function () {
            if (currentRequest === requestCounter) {
                setLoading(false);
            }
        });
    }

    function open() {
        if (!ensure()) {
            return;
        }

        if (window.Metis && Metis.modal) {
            Metis.modal.open(modal);
        } else {
            modal.classList.add('is-open');
        }

        modal.setAttribute('aria-hidden', 'false');
        input.focus();
        runSearch(1);
    }

    function close() {
        if (!modal) {
            return;
        }

        if (window.Metis && Metis.modal) {
            Metis.modal.close(modal);
        } else {
            modal.classList.remove('is-open');
        }

        modal.setAttribute('aria-hidden', 'true');
    }

    return {
        ensure: ensure,
        open: open,
        close: close
    };
}());
