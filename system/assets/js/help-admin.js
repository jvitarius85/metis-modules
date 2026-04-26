'use strict';

(function () {
    function toast(kind, message) {
        if (window.MetisCore && window.MetisCore.toast && typeof window.MetisCore.toast[kind] === 'function') {
            window.MetisCore.toast[kind](String(message || ''));
            return;
        }

        if (window.Metis && window.Metis.toast && typeof window.Metis.toast[kind] === 'function') {
            window.Metis.toast[kind](String(message || ''));
        }
    }

    function post(endpoint, nonce, payload) {
        const body = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (key) {
            body.set(key, String(payload[key] == null ? '' : payload[key]));
        });
        body.set('nonce', String(nonce || ''));
        body.set('metis_action_nonce', String(nonce || ''));

        return fetch(String(endpoint || ''), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: 'Invalid response.' };
            });
        }).then(function (payload) {
            if (!payload || payload.success !== true) {
                throw new Error(String((payload && payload.message) || 'Request failed.'));
            }
            return payload;
        });
    }

    function initPreview() {
        const source = document.querySelector('[data-help-preview-source]');
        const target = document.querySelector('[data-help-preview-target]');
        if (!source || !target) {
            return;
        }

        source.addEventListener('input', function () {
            target.innerHTML = (window.Metis && Metis.util && typeof Metis.util.sanitizeHtmlFragment === 'function')
                ? Metis.util.sanitizeHtmlFragment(source.value || '')
                : '';
        });
    }

    function initForm() {
        const form = document.querySelector('[data-help-admin-form]');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            const endpoint = String(form.getAttribute('data-endpoint') || '').trim();
            const nonce = String(form.getAttribute('data-nonce') || '').trim();
            const returnUrl = String(form.getAttribute('data-return-url') || '').trim();
            const data = new FormData(form);
            const payload = {};
            data.forEach(function (value, key) {
                payload[key] = value;
            });

            post(endpoint, nonce, payload).then(function () {
                toast('success', 'Help article saved.');
                if (returnUrl) {
                    window.location.assign(returnUrl);
                }
            }).catch(function (error) {
                toast('error', error.message || 'Unable to save the help article.');
            });
        });
    }

    function initRebuild() {
        const button = document.querySelector('[data-help-admin-rebuild]');
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            const endpoint = String(button.getAttribute('data-endpoint') || '').trim();
            const nonce = String(button.getAttribute('data-nonce') || '').trim();
            post(endpoint, nonce, {}).then(function () {
                toast('success', 'Help search index rebuilt.');
            }).catch(function (error) {
                toast('error', error.message || 'Unable to rebuild the help search index.');
            });
        });
    }

    function initConfirm() {
        const modal = document.getElementById('metis-help-admin-confirm');
        if (!modal) {
            return;
        }

        const titleEl = modal.querySelector('[id="metis-help-admin-confirm-title"]');
        const messageEl = modal.querySelector('[data-help-admin-confirm-message]');
        const submitEl = modal.querySelector('[data-help-admin-confirm-submit]');
        let current = null;

        function close() {
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('is-open');
            current = null;
        }

        document.querySelectorAll('[data-help-admin-confirm-close]').forEach(function (button) {
            button.addEventListener('click', close);
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                close();
            }
        });

        document.querySelectorAll('[data-help-admin-confirm]').forEach(function (button) {
            button.addEventListener('click', function () {
                current = button;
                if (titleEl) {
                    titleEl.textContent = String(button.getAttribute('data-confirm-title') || 'Confirm');
                }
                if (messageEl) {
                    messageEl.textContent = String(button.getAttribute('data-confirm-message') || 'Confirm this action.');
                }
                modal.setAttribute('aria-hidden', 'false');
                modal.classList.add('is-open');
            });
        });

        if (!submitEl) {
            return;
        }

        submitEl.addEventListener('click', function () {
            if (!current) {
                close();
                return;
            }

            post(
                String(current.getAttribute('data-endpoint') || '').trim(),
                String(current.getAttribute('data-nonce') || '').trim(),
                { id: String(current.getAttribute('data-article-id') || '').trim() }
            ).then(function () {
                toast('success', 'Help article updated.');
                window.location.reload();
            }).catch(function (error) {
                toast('error', error.message || 'Unable to complete the action.');
            }).finally(close);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPreview();
        initForm();
        initRebuild();
        initConfirm();
    });
}());
