document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('.metis-profile');
    if (!root) return;

    let ajax = null;
    try {
        ajax = (window.Metis && Metis.request && typeof Metis.request.config === 'function')
            ? Metis.request.config(window.metisProfileAjax || null, 'Profile AJAX not configured.')
            : (window.metisProfileAjax || window.metisAjax || null);
    } catch (_error) {
        return;
    }

    if (!ajax || !ajax.ajax_url || !ajax.nonce) return;

    const personId = parseInt(String(root.dataset.personId || '0'), 10) || 0;
    let pendingTotpSecret = '';
    let pendingPasskeyRegistration = null;

    // Tabs (Profile/Security/Notifications)
    (function wireTabs() {
        const buttons = Array.from(root.querySelectorAll('.metis-profile-tab-btn'));
        const panels = Array.from(root.querySelectorAll('.metis-profile-tab-panel'));
        if (!buttons.length || !panels.length) return;
        function activate(tabKey) {
            buttons.forEach(function (btn) {
                btn.classList.toggle('is-active', String(btn.dataset.profileTab || '') === tabKey);
            });
            panels.forEach(function (panel) {
                panel.classList.toggle('is-active', String(panel.dataset.profilePanel || '') === tabKey);
            });
        }
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                activate(String(btn.dataset.profileTab || 'profile'));
            });
        });
    })();

    const showAlert = Metis.util.notify;

    function post(action, data) {
        return Metis.request.post(ajax, action, data || {}, 'Profile AJAX not configured.');
    }

    function navigate(url) {
        var target = String(url || '').trim();
        if (!target) return false;
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            return Metis.navigation.go(target);
        }
        window.location.assign(target);
        return true;
    }

    const openModal = Metis.modal.open;
    const closeModal = Metis.modal.close;

    function normalize(v) {
        return Metis.util.normalize(v);
    }

    function updateHeaderName() {
        const first = String((document.getElementById('metis-profile-first-name') || {}).value || '').trim();
        const last = String((document.getElementById('metis-profile-last-name') || {}).value || '').trim();
        const display = String((document.getElementById('metis-profile-display-name') || {}).value || '').trim();
        const name = [first, last].filter(Boolean).join(' ') || display || 'Profile';
        const title = document.getElementById('metis-profile-title');
        const cardName = document.getElementById('metis-profile-name');
        if (title) title.textContent = name;
        if (cardName) cardName.textContent = name;
    }

    function collectNotificationPrefs() {
        const prefs = {};
        document.querySelectorAll('.metis-profile-notify-pref').forEach(function (el) {
            const eventKey = String(el.dataset.event || '').trim();
            const channel = String(el.dataset.channel || '').trim();
            if (!eventKey || !channel) return;
            if (!prefs[eventKey]) {
                prefs[eventKey] = { email: false, in_app: false };
            }
            prefs[eventKey][channel] = !!el.checked;
        });
        return prefs;
    }

    function applyPersonPayload(person) {
        const p = person || {};
        const email = String(p.email || '').trim();
        const updated = String(p.updated_at || '').trim();
        const mfaMethod = String(p.mfa_method || 'none');

        const firstName = document.getElementById('metis-profile-first-name');
        const lastName = document.getElementById('metis-profile-last-name');
        const displayName = document.getElementById('metis-profile-display-name');
        const emailInput = document.getElementById('metis-profile-email');
        const departmentView = document.getElementById('metis-profile-department-view');
        const managerPidView = document.getElementById('metis-profile-manager-pid-view');
        const lifecycleView = document.getElementById('metis-profile-lifecycle-status-view');
        const mfaPolicy = document.getElementById('metis-profile-mfa-method');
        const emailNotify = document.getElementById('metis-profile-email-notifications');
        const requires2fa = document.getElementById('metis-profile-requires-2fa');

        if (firstName) firstName.value = String(p.first_name || '');
        if (lastName) lastName.value = String(p.last_name || '');
        if (displayName) displayName.value = String(p.display_name || '');
        if (emailInput) emailInput.value = email;
        if (departmentView) departmentView.textContent = String(p.department || '—');
        if (managerPidView) managerPidView.textContent = String(p.manager_pid || '—');
        if (lifecycleView) {
            const raw = String(p.lifecycle_status || 'active');
            lifecycleView.textContent = raw ? (raw.charAt(0).toUpperCase() + raw.slice(1)) : 'Active';
        }
        if (mfaPolicy) mfaPolicy.value = mfaMethod;
        if (emailNotify) emailNotify.checked = !!p.email_notifications;
        if (requires2fa) requires2fa.checked = !!p.requires_2fa;

        const titleEmail = document.getElementById('metis-profile-email-text');
        const updatedEl = document.getElementById('metis-profile-updated');
        if (titleEmail) titleEmail.textContent = email;
        if (updatedEl) updatedEl.textContent = updated;

        const avatar = document.getElementById('metis-profile-avatar');
        if (avatar && p.avatar_url) {
            avatar.src = String(p.avatar_url);
        }

        const totpState = document.getElementById('metis-profile-totp-state');
        const passkeyState = document.getElementById('metis-profile-passkey-state');
        if (totpState) {
            const active = !!p.totp_enabled;
            totpState.classList.toggle('is-active', active);
            totpState.textContent = active ? 'TOTP Enabled' : 'TOTP Not Enabled';
        }
        if (passkeyState) {
            const active = !!p.passkey_enabled;
            passkeyState.classList.toggle('is-active', active);
            passkeyState.textContent = active ? 'Passkey Enabled' : 'Passkey Not Enabled';
        }

        const carddavEndpoint = document.getElementById('metis-profile-carddav-endpoint');
        const carddavUsername = document.getElementById('metis-profile-carddav-username');
        if (carddavEndpoint) carddavEndpoint.textContent = String(p.carddav_endpoint || '');
        if (carddavUsername) carddavUsername.textContent = String(p.carddav_username || '');

        const prefs = (p.notification_prefs && typeof p.notification_prefs === 'object') ? p.notification_prefs : {};
        document.querySelectorAll('.metis-profile-notify-pref').forEach(function (el) {
            const eventKey = String(el.dataset.event || '').trim();
            const channel = String(el.dataset.channel || '').trim();
            if (!eventKey || !channel) return;
            const cfg = prefs[eventKey] || {};
            if (typeof cfg[channel] === 'boolean') {
                el.checked = cfg[channel];
            }
        });

        renderPasskeys(Array.isArray(p.passkeys) ? p.passkeys : []);
        renderCarddavTokens(Array.isArray(p.carddav_tokens) ? p.carddav_tokens : []);
        updateHeaderName();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderPasskeys(passkeys) {
        const list = document.getElementById('metis-profile-passkeys-list');
        if (!list) return;
        const rows = Array.isArray(passkeys) ? passkeys : [];
        if (!rows.length) {
            list.innerHTML = '<div class="metis-muted" id="metis-profile-passkeys-empty">No passkeys registered.</div>';
            return;
        }
        list.innerHTML = rows.map(function (row) {
            const id = parseInt(String(row.id || '0'), 10) || 0;
            return '<div class="metis-profile-passkey-item" data-passkey-id="' + id + '">' +
                '<div><strong>' + escapeHtml(row.label || 'Passkey') + '</strong></div>' +
                '<div class="metis-muted">Created: ' + escapeHtml(row.created_at || '') + '</div>' +
                '<div class="metis-muted">Last used: ' + escapeHtml(row.last_used_at || 'Never') + '</div>' +
                '<div class="metis-people-mini-actions"><button type="button" class="metis-btn-xs metis-btn-danger metis-profile-passkey-revoke" data-id="' + id + '">Revoke</button></div>' +
            '</div>';
        }).join('');
    }

    function renderCarddavTokens(tokens) {
        const list = document.getElementById('metis-profile-carddav-list');
        if (!list) return;
        const rows = Array.isArray(tokens) ? tokens : [];
        if (!rows.length) {
            list.innerHTML = '<div class="metis-muted" id="metis-profile-carddav-empty">No CardDAV tokens issued.</div>';
            return;
        }
        list.innerHTML = rows.map(function (row) {
            const id = parseInt(String(row.id || '0'), 10) || 0;
            return '<div class="metis-profile-passkey-item" data-carddav-token-id="' + id + '">' +
                '<div><strong>' + escapeHtml(row.label || 'CardDAV device') + '</strong></div>' +
                '<div class="metis-muted">Created: ' + escapeHtml(row.created_at || '') + '</div>' +
                '<div class="metis-muted">Last used: ' + escapeHtml(row.last_used_at || 'Never') + '</div>' +
                '<div class="metis-people-mini-actions"><button type="button" class="metis-btn-xs metis-btn-danger metis-profile-carddav-revoke" data-id="' + id + '">Revoke</button></div>' +
            '</div>';
        }).join('');
    }

    function loadProfile() {
        return post('metis_profile_get', {}).then(function (data) {
            if (data && data.person) {
                applyPersonPayload(data.person);
            }
        });
    }

    const profileForm = document.getElementById('metis-profile-form');
    if (profileForm) {
        profileForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const payload = {
                first_name: (document.getElementById('metis-profile-first-name') || {}).value || '',
                last_name: (document.getElementById('metis-profile-last-name') || {}).value || '',
                display_name: (document.getElementById('metis-profile-display-name') || {}).value || '',
                email_notifications: (document.getElementById('metis-profile-email-notifications') || {}).checked ? '1' : '0',
                requires_2fa: (document.getElementById('metis-profile-requires-2fa') || {}).checked ? '1' : '0',
                mfa_method: (document.getElementById('metis-profile-mfa-method') || {}).value || 'none',
                notification_prefs_json: JSON.stringify(collectNotificationPrefs())
            };
            post('metis_profile_save', payload).then(function (data) {
                if (data && data.person) applyPersonPayload(data.person);
                showAlert('Profile saved.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save profile.', 'error');
            });
        });
    }

    const saveNotifications = document.getElementById('metis-profile-save-notifications');
    if (saveNotifications) {
        saveNotifications.addEventListener('click', function () {
            const payload = {
                first_name: (document.getElementById('metis-profile-first-name') || {}).value || '',
                last_name: (document.getElementById('metis-profile-last-name') || {}).value || '',
                display_name: (document.getElementById('metis-profile-display-name') || {}).value || '',
                email_notifications: (document.getElementById('metis-profile-email-notifications') || {}).checked ? '1' : '0',
                requires_2fa: (document.getElementById('metis-profile-requires-2fa') || {}).checked ? '1' : '0',
                mfa_method: (document.getElementById('metis-profile-mfa-method') || {}).value || 'none',
                notification_prefs_json: JSON.stringify(collectNotificationPrefs())
            };
            post('metis_profile_save', payload).then(function (data) {
                if (data && data.person) applyPersonPayload(data.person);
                showAlert('Notification preferences saved.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save notifications.', 'error');
            });
        });
    }

    const passwordForm = document.getElementById('metis-profile-password-form');
    if (passwordForm) {
        passwordForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const hasPassword = String(passwordForm.getAttribute('data-has-password') || '0') === '1';
            const canSetFromSession = String(passwordForm.getAttribute('data-can-set-from-session') || '0') === '1';
            const currentPasswordInput = document.getElementById('metis-profile-current-password');
            const newPasswordInput = document.getElementById('metis-profile-new-password');
            const confirmPasswordInput = document.getElementById('metis-profile-confirm-password');
            const currentPassword = String((currentPasswordInput && currentPasswordInput.value) || '');
            const newPassword = String((newPasswordInput && newPasswordInput.value) || '');
            const confirmPassword = String((confirmPasswordInput && confirmPasswordInput.value) || '');
            if (hasPassword && !canSetFromSession && currentPassword === '') {
                showAlert('Current password is required.', 'error');
                return;
            }
            if (newPassword.length < 12) {
                showAlert('Password must be at least 12 characters.', 'error');
                return;
            }
            if (newPassword !== confirmPassword) {
                showAlert('Password confirmation does not match.', 'error');
                return;
            }
            post('metis_profile_change_password', {
                current_password: currentPassword,
                new_password: newPassword,
                confirm_password: confirmPassword
            }).then(function (data) {
                if (currentPasswordInput) currentPasswordInput.value = '';
                if (newPasswordInput) newPasswordInput.value = '';
                if (confirmPasswordInput) confirmPasswordInput.value = '';
                if (data && data.reauthenticate && data.redirect_url) {
                    navigate(String(data.redirect_url));
                    return;
                }
                showAlert((hasPassword && !canSetFromSession) ? 'Password updated.' : 'Password set.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to update password.', 'error');
            });
        });
    }

    const workspacePasswordForm = document.getElementById('metis-profile-workspace-password-form');
    if (workspacePasswordForm) {
        workspacePasswordForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const newPasswordInput = document.getElementById('metis-profile-workspace-new-password');
            const confirmPasswordInput = document.getElementById('metis-profile-workspace-confirm-password');
            const newPassword = String((newPasswordInput && newPasswordInput.value) || '');
            const confirmPassword = String((confirmPasswordInput && confirmPasswordInput.value) || '');
            if (newPassword.length < 12) {
                showAlert('Password must be at least 12 characters.', 'error');
                return;
            }
            if (newPassword !== confirmPassword) {
                showAlert('Password confirmation does not match.', 'error');
                return;
            }
            post('metis_profile_change_workspace_password', {
                new_password: newPassword,
                confirm_password: confirmPassword
            }).then(function () {
                if (newPasswordInput) newPasswordInput.value = '';
                if (confirmPasswordInput) confirmPasswordInput.value = '';
                showAlert('Workspace password updated.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to update Workspace password.', 'error');
            });
        });
    }

    // Avatar modal
    const avatarOpen = document.getElementById('metis-profile-avatar-edit-open');
    const avatarModal = document.getElementById('metis-profile-avatar-modal');
    const avatarCancel = document.getElementById('metis-profile-avatar-cancel');
    const avatarSave = document.getElementById('metis-profile-avatar-save');
    const avatarFile = document.getElementById('metis-profile-avatar-file');
    const avatarCanvas = document.getElementById('metis-profile-avatar-canvas');
    const avatarZoom = document.getElementById('metis-profile-avatar-zoom');
    const avatarPreview = document.getElementById('metis-profile-avatar-preview');
    const avatarCropper = (window.Metis && typeof Metis.avatarCropper === 'function' && avatarCanvas)
        ? Metis.avatarCropper({ canvas: avatarCanvas, preview: avatarPreview, zoomInput: avatarZoom, outputSize: 256 })
        : null;

    if (avatarOpen && avatarModal) {
        avatarOpen.addEventListener('click', function () {
            openModal(avatarModal);
            if (avatarCropper) avatarCropper.render();
        });
        if (avatarCancel) avatarCancel.addEventListener('click', function () { closeModal(avatarModal); });
        avatarModal.addEventListener('click', function (event) {
            if (event.target === avatarModal) closeModal(avatarModal);
        });
        if (avatarFile) {
            avatarFile.addEventListener('change', function () {
                const file = avatarFile.files && avatarFile.files[0] ? avatarFile.files[0] : null;
                if (!file || !avatarCropper) return;
                avatarCropper.loadFile(file).catch(function (err) {
                    showAlert(err.message || 'Selected image could not be loaded.', 'error');
                });
            });
        }
        if (avatarSave) {
            avatarSave.addEventListener('click', function () {
                if (!avatarCropper || !avatarCropper.hasImage() || !personId) {
                    showAlert('Select an image first.', 'error');
                    return;
                }
                const base64 = avatarCropper.getDataUrl();
                post('metis_profile_save_avatar', { avatar_base64: base64 }).then(function (data) {
                    closeModal(avatarModal);
                    const url = String((data && data.avatar_url) || '').trim();
                    if (url) {
                        const avatar = document.getElementById('metis-profile-avatar');
                        if (avatar) avatar.src = url;
                    }
                    showAlert('Profile photo updated.', 'success');
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to update profile photo.', 'error');
                });
            });
        }
    }

    // TOTP modal
    const totpModal = document.getElementById('metis-profile-totp-modal');
    const totpOpen = document.getElementById('metis-profile-totp-open');
    const totpCancel = document.getElementById('metis-profile-totp-cancel');
    const totpGenerate = document.getElementById('metis-profile-totp-generate');
    const totpVerify = document.getElementById('metis-profile-totp-verify');
    const totpQr = document.getElementById('metis-profile-totp-qr');
    const totpSecret = document.getElementById('metis-profile-totp-secret');
    const totpUri = document.getElementById('metis-profile-totp-uri');
    const totpCode = document.getElementById('metis-profile-totp-code');

    if (totpOpen && totpModal) {
        totpOpen.addEventListener('click', function () { openModal(totpModal); });
        if (totpCancel) totpCancel.addEventListener('click', function () { closeModal(totpModal); });
        totpModal.addEventListener('click', function (event) {
            if (event.target === totpModal) closeModal(totpModal);
        });
        if (totpGenerate) {
            totpGenerate.addEventListener('click', function () {
                post('metis_profile_generate_totp_secret', {}).then(function (data) {
                    pendingTotpSecret = String((data && data.secret) || '').trim();
                    const uri = String((data && data.provisioning_uri) || '').trim();
                    if (totpSecret) totpSecret.textContent = pendingTotpSecret || 'Unable to generate secret.';
                    if (totpUri) totpUri.value = uri;
                    if (totpQr) {
                        if (uri) {
                            totpQr.src = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' + encodeURIComponent(uri);
                            totpQr.style.display = 'block';
                        } else {
                            totpQr.style.display = 'none';
                            totpQr.removeAttribute('src');
                        }
                    }
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to generate secret.', 'error');
                });
            });
        }
        if (totpVerify) {
            totpVerify.addEventListener('click', function () {
                const code = String((totpCode && totpCode.value) || '').trim();
                if (!pendingTotpSecret || !/^\d{6}$/.test(code)) {
                    showAlert('Enter a valid 6-digit code.', 'error');
                    return;
                }
                post('metis_profile_verify_totp_secret', {
                    secret: pendingTotpSecret,
                    code: code
                }).then(function () {
                    closeModal(totpModal);
                    pendingTotpSecret = '';
                    if (totpCode) totpCode.value = '';
                    loadProfile();
                    showAlert('Authenticator app enabled.', 'success');
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to verify code.', 'error');
                });
            });
        }
    }

    // Passkey registration
    const passkeyRegister = document.getElementById('metis-profile-passkey-register-open');
    const passkeyLabelModal = document.getElementById('metis-profile-passkey-label-modal');
    const passkeyLabelInput = document.getElementById('metis-profile-passkey-label');
    const passkeyLabelCancel = document.getElementById('metis-profile-passkey-label-cancel');
    const passkeyLabelSave = document.getElementById('metis-profile-passkey-label-save');

    function base64UrlToUint8Array(base64Url) {
        const base64 = String(base64Url || '').replace(/-/g, '+').replace(/_/g, '/');
        const pad = base64.length % 4;
        const padded = base64 + (pad ? '='.repeat(4 - pad) : '');
        const raw = window.atob(padded);
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function arrayBufferToBase64Url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
        return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    }

    if (passkeyRegister && window.PublicKeyCredential) {
        passkeyRegister.addEventListener('click', function () {
            post('metis_profile_begin_passkey_registration', {}).then(function (data) {
                const opts = data && data.public_key ? data.public_key : null;
                const challengeKey = String((data && data.challenge_key) || '').trim();
                if (!opts || !challengeKey) throw new Error('Missing passkey options.');

                const publicKey = Object.assign({}, opts, {
                    challenge: base64UrlToUint8Array(opts.challenge),
                    user: Object.assign({}, opts.user, { id: base64UrlToUint8Array(opts.user.id) }),
                    excludeCredentials: Array.isArray(opts.excludeCredentials) ? opts.excludeCredentials.map(function (cred) {
                        return Object.assign({}, cred, { id: base64UrlToUint8Array(cred.id) });
                    }) : []
                });

                return navigator.credentials.create({ publicKey: publicKey }).then(function (credential) {
                    if (!credential || !credential.response) throw new Error('Passkey registration was cancelled.');
                    pendingPasskeyRegistration = {
                        challengeKey: challengeKey,
                        credentialId: credential.id,
                        clientDataJSON: arrayBufferToBase64Url(credential.response.clientDataJSON),
                        attestationObject: arrayBufferToBase64Url(credential.response.attestationObject),
                        transports: credential.response.getTransports ? credential.response.getTransports() : []
                    };
                    if (passkeyLabelInput) passkeyLabelInput.value = '';
                    openModal(passkeyLabelModal);
                });
            }).catch(function (err) {
                showAlert(err.message || 'Failed to begin passkey registration.', 'error');
            });
        });
    }

    if (passkeyLabelCancel && passkeyLabelModal) {
        passkeyLabelCancel.addEventListener('click', function () {
            pendingPasskeyRegistration = null;
            closeModal(passkeyLabelModal);
        });
        passkeyLabelModal.addEventListener('click', function (event) {
            if (event.target === passkeyLabelModal) {
                pendingPasskeyRegistration = null;
                closeModal(passkeyLabelModal);
            }
        });
    }

    if (passkeyLabelSave) {
        passkeyLabelSave.addEventListener('click', function () {
            if (!pendingPasskeyRegistration) {
                closeModal(passkeyLabelModal);
                return;
            }
            const label = String((passkeyLabelInput && passkeyLabelInput.value) || '').trim() || 'Passkey';
            post('metis_profile_complete_passkey_registration', {
                challenge_key: pendingPasskeyRegistration.challengeKey,
                credential_id: pendingPasskeyRegistration.credentialId,
                client_data_json: pendingPasskeyRegistration.clientDataJSON,
                attestation_object: pendingPasskeyRegistration.attestationObject,
                transports_json: JSON.stringify(pendingPasskeyRegistration.transports || []),
                label: label
            }).then(function () {
                pendingPasskeyRegistration = null;
                closeModal(passkeyLabelModal);
                loadProfile();
                showAlert('Passkey registered.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to register passkey.', 'error');
            });
        });
    }

    const passkeyList = document.getElementById('metis-profile-passkeys-list');
    if (passkeyList) {
        passkeyList.addEventListener('click', function (event) {
            const btn = event.target.closest('.metis-profile-passkey-revoke');
            if (!btn) return;
            const passkeyId = parseInt(String(btn.dataset.id || '0'), 10) || 0;
            if (passkeyId < 1) return;
            post('metis_profile_revoke_passkey', { passkey_id: String(passkeyId) }).then(function () {
                loadProfile();
                showAlert('Passkey revoked.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to revoke passkey.', 'error');
            });
        });
    }

    const carddavGenerate = document.getElementById('metis-profile-carddav-generate');
    const carddavLabel = document.getElementById('metis-profile-carddav-token-label');
    const carddavIssued = document.getElementById('metis-profile-carddav-issued');
    if (carddavGenerate) {
        carddavGenerate.addEventListener('click', function () {
            const label = String((carddavLabel && carddavLabel.value) || '').trim();
            post('metis_profile_carddav_issue_token', { label: label }).then(function (data) {
                if (carddavLabel) carddavLabel.value = '';
                const issued = data && data.issued ? data.issued : null;
                if (carddavIssued) {
                    if (issued && issued.token) {
                        carddavIssued.innerHTML = 'New CardDAV token for <strong>' +
                            escapeHtml(String(issued.label || 'CardDAV device')) +
                            '</strong>: <code>' + escapeHtml(String(issued.token || '')) + '</code>';
                        carddavIssued.style.display = '';
                    } else {
                        carddavIssued.style.display = 'none';
                        carddavIssued.textContent = '';
                    }
                }
                if (data && data.person) {
                    applyPersonPayload(data.person);
                } else {
                    loadProfile();
                }
                showAlert('CardDAV token generated.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to generate CardDAV token.', 'error');
            });
        });
    }

    const carddavList = document.getElementById('metis-profile-carddav-list');
    if (carddavList) {
        carddavList.addEventListener('click', function (event) {
            const btn = event.target.closest('.metis-profile-carddav-revoke');
            if (!btn) return;
            const tokenId = parseInt(String(btn.dataset.id || '0'), 10) || 0;
            if (tokenId < 1) return;
            post('metis_profile_carddav_revoke_token', { token_id: String(tokenId) }).then(function (data) {
                if (data && data.person) {
                    applyPersonPayload(data.person);
                } else {
                    loadProfile();
                }
                showAlert('CardDAV token revoked.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to revoke CardDAV token.', 'error');
            });
        });
    }

    // Initial hydration ensures no stale state after ajax saves.
    loadProfile().catch(function () {
        // Silent fallback: initial page already rendered server-side.
    });
});
