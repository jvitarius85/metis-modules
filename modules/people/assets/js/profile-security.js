window.MetisPeopleProfileModules = window.MetisPeopleProfileModules || {};

window.MetisPeopleProfileModules.initSecurity = function (context) {
    const activePersonId = String(context.activePersonId || '').trim();
    const showAlert = context.showAlert;
    const post = context.post;
    const openModal = context.openModal;
    const closeModal = context.closeModal;

    const totpOpen = document.getElementById('metis-people-totp-setup-open');
    const totpModal = document.getElementById('metis-people-totp-modal');
    const totpCancel = document.getElementById('metis-people-totp-cancel');
    const totpGenerate = document.getElementById('metis-people-totp-generate');
    const totpVerify = document.getElementById('metis-people-totp-verify');
    const totpSecret = document.getElementById('metis-people-totp-secret');
    const totpQr = document.getElementById('metis-people-totp-qr');
    const totpUriInput = document.getElementById('metis-people-totp-uri');
    const totpCode = document.getElementById('metis-people-totp-code');
    const resetMfaButton = document.getElementById('metis-people-reset-mfa');
    const resetMetisPasswordButton = document.getElementById('metis-people-reset-metis-password');
    let pendingTotpSecret = '';

    function ensureSecurityConfirmModal() {
        let modal = document.getElementById('metis-people-security-confirm-modal');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'metis-people-security-confirm-modal';
        modal.className = 'metis-contacts-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="metis-contacts-modal-inner metis-people-modal-inner">' +
                '<h3 class="metis-contacts-modal-title" id="metis-people-security-confirm-title">Confirm Action</h3>' +
                '<div class="metis-contact-form">' +
                    '<div class="metis-contact-field metis-contact-field-full">' +
                        '<div class="mw-muted" id="metis-people-security-confirm-message"></div>' +
                    '</div>' +
                    '<div class="metis-contact-actions">' +
                        '<button type="button" id="metis-people-security-confirm-cancel" class="mw-btn mw-btn-ghost">Cancel</button>' +
                        '<button type="button" id="metis-people-security-confirm-ok" class="mw-btn mw-btn-danger">Confirm</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal(modal);
        });
        return modal;
    }

    function openSecurityConfirmModal(title, message, confirmLabel) {
        const modal = ensureSecurityConfirmModal();
        const titleNode = document.getElementById('metis-people-security-confirm-title');
        const messageNode = document.getElementById('metis-people-security-confirm-message');
        const cancelBtn = document.getElementById('metis-people-security-confirm-cancel');
        const okBtn = document.getElementById('metis-people-security-confirm-ok');
        if (!modal || !titleNode || !messageNode || !cancelBtn || !okBtn) {
            return Promise.resolve(false);
        }
        titleNode.textContent = String(title || 'Confirm Action');
        messageNode.textContent = String(message || '');
        okBtn.textContent = String(confirmLabel || 'Confirm');

        return new Promise(function (resolve) {
            let done = false;
            function cleanup() {
                cancelBtn.removeEventListener('click', onCancel);
                okBtn.removeEventListener('click', onOk);
                modal.removeEventListener('click', onBackdrop);
            }
            function finish(value) {
                if (done) return;
                done = true;
                cleanup();
                closeModal(modal);
                resolve(value);
            }
            function onCancel() { finish(false); }
            function onOk() { finish(true); }
            function onBackdrop(event) {
                if (event.target === modal) finish(false);
            }
            cancelBtn.addEventListener('click', onCancel);
            okBtn.addEventListener('click', onOk);
            modal.addEventListener('click', onBackdrop);
            openModal(modal);
        });
    }

    function ensureCredentialModal() {
        let modal = document.getElementById('metis-people-credential-modal');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'metis-people-credential-modal';
        modal.className = 'metis-contacts-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="metis-contacts-modal-inner metis-people-modal-inner">' +
                '<h3 class="metis-contacts-modal-title" id="metis-people-credential-title">Temporary Password</h3>' +
                '<div class="metis-contact-form">' +
                    '<div class="metis-contact-field metis-contact-field-full">' +
                        '<div class="mw-muted" id="metis-people-credential-message"></div>' +
                    '</div>' +
                    '<div class="metis-contact-field metis-contact-field-full">' +
                        '<label for="metis-people-credential-password">Temporary Password</label>' +
                        '<input id="metis-people-credential-password" class="mw-input" type="text" readonly autocomplete="off">' +
                    '</div>' +
                    '<div class="metis-contact-actions">' +
                        '<button type="button" id="metis-people-credential-copy" class="mw-btn">Copy</button>' +
                        '<button type="button" id="metis-people-credential-close" class="mw-btn mw-btn-ghost">Close</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal(modal);
        });
        return modal;
    }

    function copyToClipboard(text) {
        const value = String(text || '');
        if (value === '') return Promise.resolve(false);
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(value).then(function () { return true; }).catch(function () { return false; });
        }
        try {
            const area = document.createElement('textarea');
            area.value = value;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.focus();
            area.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(area);
            return Promise.resolve(!!ok);
        } catch (err) {
            return Promise.resolve(false);
        }
    }

    function openCredentialModal(identity, credential) {
        const modal = ensureCredentialModal();
        const titleNode = document.getElementById('metis-people-credential-title');
        const messageNode = document.getElementById('metis-people-credential-message');
        const passwordInput = document.getElementById('metis-people-credential-password');
        const copyBtn = document.getElementById('metis-people-credential-copy');
        const closeBtn = document.getElementById('metis-people-credential-close');
        if (!modal || !titleNode || !messageNode || !passwordInput || !copyBtn || !closeBtn) {
            return;
        }

        titleNode.textContent = 'Metis Password Ready';
        messageNode.textContent = 'Temporary Metis password for ' + identity + '. Copy it now and share securely.';
        passwordInput.value = String(credential || '');

        function cleanup() {
            copyBtn.removeEventListener('click', onCopy);
            closeBtn.removeEventListener('click', onClose);
            modal.removeEventListener('click', onBackdrop);
        }
        function onCopy() {
            copyToClipboard(passwordInput.value).then(function (ok) {
                showAlert(ok ? 'Password copied to clipboard.' : 'Copy failed. Select and copy manually.', ok ? 'success' : 'error');
                if (!ok) {
                    passwordInput.focus();
                    passwordInput.select();
                }
            });
        }
        function onClose() {
            cleanup();
            closeModal(modal);
            passwordInput.value = '';
        }
        function onBackdrop(event) {
            if (event.target === modal) onClose();
        }

        copyBtn.addEventListener('click', onCopy);
        closeBtn.addEventListener('click', onClose);
        modal.addEventListener('click', onBackdrop);

        openModal(modal);
        window.setTimeout(function () {
            passwordInput.focus();
            passwordInput.select();
        }, 20);
    }

    if (totpOpen && totpModal) {
        totpOpen.addEventListener('click', function () { openModal(totpModal); });
        if (totpCancel) totpCancel.addEventListener('click', function () { closeModal(totpModal); });
        totpModal.addEventListener('click', function (event) {
            if (event.target === totpModal) closeModal(totpModal);
        });
        if (totpGenerate) {
            totpGenerate.addEventListener('click', function () {
                if (!activePersonId) return;
                post('metis_people_generate_totp_secret', { person_id: activePersonId })
                    .then(function (data) {
                        pendingTotpSecret = String((data && data.secret) || '').trim();
                        if (totpSecret) totpSecret.textContent = pendingTotpSecret || 'Unable to generate secret.';
                        const uri = String((data && data.provisioning_uri) || '').trim();
                        if (totpUriInput) totpUriInput.value = uri;
                        if (totpQr) {
                            if (uri) {
                                totpQr.src = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' + encodeURIComponent(uri);
                                totpQr.style.display = 'block';
                            } else {
                                totpQr.removeAttribute('src');
                                totpQr.style.display = 'none';
                            }
                        }
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to generate TOTP secret.', 'error');
                    });
            });
        }
        if (totpVerify) {
            totpVerify.addEventListener('click', function () {
                const code = String((totpCode && totpCode.value) || '').trim();
                if (!activePersonId || !pendingTotpSecret || !/^\d{6}$/.test(code)) {
                    showAlert('Generate key and enter a valid 6-digit code.', 'error');
                    return;
                }
                post('metis_people_verify_totp_secret', {
                    person_id: activePersonId,
                    secret: pendingTotpSecret,
                    code: code
                }).then(function () {
                    closeModal(totpModal);
                    showAlert('Authenticator app enabled.', 'success');
                    const chips = Array.from(document.querySelectorAll('#metis-people-totp-setup-open')).map(function (btn) {
                        return btn.closest('.metis-people-security-row');
                    }).filter(Boolean);
                    chips.forEach(function (row) {
                        const chip = row.querySelector('.mw-chip');
                        if (chip) {
                            chip.textContent = 'Configured';
                            chip.classList.add('mw-chip-success');
                        }
                    });
                }).catch(function (err) {
                    showAlert(err.message || 'Code verification failed.', 'error');
                });
            });
        }
    }

    if (resetMfaButton && activePersonId) {
        resetMfaButton.addEventListener('click', function () {
            const personLabel = String(resetMfaButton.dataset.personLabel || 'this account').trim();
            openSecurityConfirmModal(
                'Reset MFA',
                'Reset MFA for ' + personLabel + '? This clears the authenticator secret and revokes all passkeys.',
                'Reset MFA'
            ).then(function (confirmed) {
                if (!confirmed) return;
                post('metis_people_reset_mfa', {
                    person_id: activePersonId
                }).then(function (data) {
                    const requires2fa = document.getElementById('metis-people-requires-2fa');
                    const mfaMethod = document.getElementById('metis-people-mfa-method');
                    if (requires2fa) requires2fa.checked = false;
                    if (mfaMethod) mfaMethod.value = 'none';

                    document.querySelectorAll('#metis-people-totp-setup-open').forEach(function (btn) {
                        btn.textContent = 'Set Up App Code';
                        const row = btn.closest('.metis-people-security-row');
                        const chip = row ? row.querySelector('.mw-chip') : null;
                        if (chip) {
                            chip.textContent = 'Not configured';
                            chip.classList.remove('mw-chip-success');
                        }
                    });

                    document.querySelectorAll('#metis-people-passkey-register-open').forEach(function (btn) {
                        const row = btn.closest('.metis-people-security-row');
                        const chip = row ? row.querySelector('.mw-chip') : null;
                        if (chip) {
                            chip.textContent = 'Not configured';
                            chip.classList.remove('mw-chip-success');
                        }
                    });

                    const passkeysList = document.getElementById('metis-people-passkeys-list');
                    if (passkeysList) {
                        passkeysList.innerHTML = '<div class="mw-muted">No passkeys registered.</div>';
                    }

                    pendingTotpSecret = '';
                    if (totpSecret) totpSecret.textContent = '';
                    if (totpCode) totpCode.value = '';
                    if (totpUriInput) totpUriInput.value = '';
                    if (totpQr) {
                        totpQr.removeAttribute('src');
                        totpQr.style.display = 'none';
                    }

                    const revoked = Number((data && data.revoked_passkeys) || 0);
                    const suffix = revoked > 0 ? ' Revoked ' + revoked + ' passkey' + (revoked === 1 ? '' : 's') + '.' : '';
                    showAlert('MFA reset.' + suffix, 'success');
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to reset MFA.', 'error');
                });
            });
        });
    }

    if (resetMetisPasswordButton && activePersonId) {
        resetMetisPasswordButton.addEventListener('click', function () {
            const personLabel = String(resetMetisPasswordButton.dataset.personLabel || 'this account').trim();
            const hasPassword = String(resetMetisPasswordButton.dataset.hasPassword || '0') === '1';
            const verb = hasPassword ? 'Reset' : 'Set';
            openSecurityConfirmModal(
                verb + ' Metis Password',
                verb + ' Metis password for ' + personLabel + '? A temporary password will be generated and active sessions will be signed out.',
                verb + ' Password'
            ).then(function (confirmed) {
                if (!confirmed) return;
                post('metis_people_reset_metis_password', {
                    person_id: activePersonId
                }).then(function (data) {
                    const credential = String((data && data.password) || '').trim();
                    const login = String((data && data.user_login) || '').trim();
                    const email = String((data && data.user_email) || '').trim();
                    const identity = login || email || personLabel;
                    resetMetisPasswordButton.dataset.hasPassword = '1';
                    resetMetisPasswordButton.textContent = 'Reset Metis Password';
                    if (credential) {
                        openCredentialModal(identity, credential);
                    }
                    showAlert('Metis password ' + (hasPassword ? 'reset' : 'set') + ' for ' + identity + '.', 'success');
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to set Metis password.', 'error');
                });
            });
        });
    }
};
