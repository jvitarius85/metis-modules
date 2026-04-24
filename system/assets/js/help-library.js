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

    function initSupportModal() {
        const modal = document.getElementById('metis-help-support-modal');
        const form = document.querySelector('[data-help-support-form]');
        const submit = document.querySelector('[data-help-support-submit]');
        if (!modal || !form || !submit) {
            return;
        }

        const endpoint = String(((window.metisHelp || {}).support_endpoint) || '').trim();
        const nonce = String(((window.metisHelp || {}).support_nonce) || '').trim();
        const messageField = form.querySelector('[name="message"]');

        function open(button) {
            if (button) {
                ['article_title', 'article_slug', 'article_url'].forEach(function (key) {
                    const field = form.querySelector('[name="' + key + '"]');
                    const attr = 'data-' + key.replace(/_/g, '-');
                    if (field) {
                        field.value = String(button.getAttribute(attr) || field.value || '');
                    }
                });
            }
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('metis-modal-open');
            if (messageField) {
                messageField.focus();
            }
        }

        function close() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('metis-modal-open');
        }

        document.querySelectorAll('[data-help-support-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                open(button);
            });
        });

        document.querySelectorAll('[data-help-support-close]').forEach(function (button) {
            button.addEventListener('click', close);
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                close();
            }
        });

        submit.addEventListener('click', function () {
            if (!endpoint || !nonce) {
                toast('error', 'Help support is not configured.');
                return;
            }

            const payload = {};
            new FormData(form).forEach(function (value, key) {
                payload[key] = value;
            });

            if (String(payload.message || '').trim().length < 12) {
                toast('error', 'Please describe what you need help with.');
                if (messageField) {
                    messageField.focus();
                }
                return;
            }

            submit.disabled = true;
            post(endpoint, nonce, payload).then(function () {
                toast('success', 'Your request was sent to the system admin.');
                if (messageField) {
                    messageField.value = '';
                }
                close();
            }).catch(function (error) {
                toast('error', error.message || 'Unable to send the help request.');
            }).finally(function () {
                submit.disabled = false;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSupportModal();
    });
}());
