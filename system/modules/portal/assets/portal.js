(function () {
    const root = document.querySelector('.metis-portal-board-actions');
    if (!root) {
        return;
    }

    let ajax = null;
    try {
        ajax = (window.Metis && Metis.request && typeof Metis.request.config === 'function')
            ? Metis.request.config(window.metisPortalAjax || null, 'Portal AJAX not configured.')
            : (window.metisPortalAjax || window.metisAjax || null);
    } catch (_error) {
        return;
    }

    if (!ajax || !ajax.ajax_url || !ajax.nonce) {
        return;
    }

    const filters = Array.from(root.querySelectorAll('.metis-portal-action-filter'));
    const priorityButtons = Array.from(document.querySelectorAll('.metis-portal-priority-btn[data-filter]'));
    const listWrap = document.getElementById('metis-portal-my-actions-wrap');

    if (!listWrap || filters.length === 0) {
        return;
    }

    const applyActiveFilter = (filter) => {
        filters.forEach((button) => {
            const active = button.dataset.filter === filter;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    };

    const setBusy = (busy) => {
        listWrap.classList.toggle('is-loading', busy);
        filters.forEach((button) => {
            button.disabled = busy;
        });
    };

    const parseResponse = async (response) => {
        const payload = await response.json();
        if (!response.ok || !payload || !payload.success) {
            throw new Error((payload && payload.data && payload.data.message) ? payload.data.message : 'Unable to load board actions.');
        }
        return payload.data || {};
    };

    const refreshFilterCounts = (counts) => {
        if (!counts || typeof counts !== 'object') {
            return;
        }
        filters.forEach((button) => {
            const key = button.dataset.filter || '';
            if (!Object.prototype.hasOwnProperty.call(counts, key)) {
                return;
            }
            const chip = button.querySelector('span');
            if (chip) {
                chip.textContent = String(counts[key]);
            }
        });
    };

    const loadActions = async (filter) => {
        setBusy(true);
        applyActiveFilter(filter);

        try {
            const formData = new FormData();
            const action = 'metis_portal_fetch_board_actions';
            formData.set('action', action);
            if (window.Metis && window.Metis.ajax && typeof window.Metis.ajax.nonceFor === 'function') {
                formData.set('metis_action_nonce', window.Metis.ajax.nonceFor(action, ajax.nonce));
            }
            formData.set('nonce', ajax.nonce);
            formData.set('filter', filter);

            const data = await parseResponse(await fetch(ajax.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
            }));

            if (typeof data.html === 'string') {
                listWrap.innerHTML = data.html;
            }
            if (typeof data.active_filter === 'string') {
                applyActiveFilter(data.active_filter);
            }
            refreshFilterCounts(data.counts || {});
        } catch (error) {
            if (window.Metis && typeof window.Metis.toast === 'function') {
                window.Metis.toast(error.message || 'Failed to load board actions.', 'error');
            } else {
                console.error(error);
            }
        } finally {
            setBusy(false);
        }
    };

    filters.forEach((button) => {
        button.addEventListener('click', () => {
            const filter = button.dataset.filter || 'mine';
            loadActions(filter);
        });
    });

    priorityButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const filter = button.dataset.filter || 'mine';
            loadActions(filter);
        });
    });
})();
