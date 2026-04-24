window.MetisContactsModules = window.MetisContactsModules || {};

document.addEventListener('DOMContentLoaded', function () {
    const coreAjax = (window.Metis && Metis.ajax) ? Metis.ajax : null;
    const specificAjax = (window.metisContactsAjax && typeof window.metisContactsAjax === 'object') ? window.metisContactsAjax : {};
    const globalAjax = (window.metisAjax && typeof window.metisAjax === 'object') ? window.metisAjax : {};
    const ajaxConfig = {
        ajax_url: String(specificAjax.ajax_url || globalAjax.ajax_url || (coreAjax && coreAjax.url) || '').trim(),
        nonce: String(specificAjax.nonce || globalAjax.nonce || (coreAjax && coreAjax.nonce) || '').trim(),
        action_nonces: Object.assign({}, globalAjax.action_nonces || {}, specificAjax.action_nonces || {})
    };
    const modules = window.MetisContactsModules || {};

    function request(action, data) {
        const actionName = String(action || '').trim();
        if (!actionName) {
            return Promise.reject(new Error('Contacts action is missing.'));
        }
        if (!ajaxConfig.ajax_url) {
            return Promise.reject(new Error('Contacts AJAX is not configured.'));
        }

        const payload = Object.assign({}, data || {}, { action: actionName });
        if (!payload.nonce && ajaxConfig.nonce) {
            payload.nonce = ajaxConfig.nonce;
        }

        const actionNonce = (coreAjax && typeof coreAjax.nonceFor === 'function')
            ? coreAjax.nonceFor(actionName, ajaxConfig.action_nonces[actionName] || '')
            : String(ajaxConfig.action_nonces[actionName] || '').trim();

        if (!payload.metis_action_nonce && actionNonce) {
            payload.metis_action_nonce = actionNonce;
        }

        if (coreAjax && typeof coreAjax.post === 'function') {
            return coreAjax.post(payload).then(function (response) {
                if (!response || response.success !== true) {
                    throw new Error(coreAjax.message(response, 'Request failed.'));
                }
                return response.data || {};
            });
        }

        const formData = new FormData();
        Object.keys(payload).forEach(function (key) {
            formData.append(key, payload[key]);
        });

        return fetch(ajaxConfig.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (res) {
            return res.json();
        }).then(function (response) {
            if (!response || response.success !== true) {
                const message = response && response.data && typeof response.data === 'string'
                    ? response.data
                    : (response && response.data && response.data.message) || 'Request failed.';
                throw new Error(message);
            }
            return response.data || {};
        });
    }

    const context = {
        ajaxConfig: ajaxConfig,
        escapeHtml: Metis.util.escapeHtml,
        normalize: Metis.util.normalize,
        showAlert: Metis.util.notify,
        openModal: Metis.modal.open,
        closeModal: Metis.modal.close,
        request: request,
        confirmAction: function (message, options) {
            if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
                return Metis.confirm.open(Object.assign({ message: message }, options || {}));
            }
            return Promise.resolve(false);
        }
    };

    if (typeof modules.initList === 'function') {
        modules.initList(context);
    }
    if (typeof modules.initDetail === 'function') {
        modules.initDetail(context);
    }
});
