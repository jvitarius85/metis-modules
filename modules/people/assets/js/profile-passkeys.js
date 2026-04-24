window.MetisPeopleProfileModules = window.MetisPeopleProfileModules || {};

window.MetisPeopleProfileModules.initPasskeys = function (context) {
    const activePersonId = String(context.activePersonId || '').trim();
    const showAlert = context.showAlert;
    const post = context.post;
    const openPromptModal = context.openPromptModal;
    const base64UrlToUint8Array = context.base64UrlToUint8Array;
    const arrayBufferToBase64Url = context.arrayBufferToBase64Url;
    const passkeyRegisterBtn = document.getElementById('metis-people-passkey-register-open');

    function setPasskeyConfiguredUI() {
        const rowWrap = passkeyRegisterBtn ? passkeyRegisterBtn.closest('.metis-people-security-row') : null;
        const chip = rowWrap ? rowWrap.querySelector('.mw-chip') : null;
        if (chip) {
            chip.textContent = 'Configured';
            chip.classList.add('mw-chip-success');
        }
    }

    function setPasskeyUnconfiguredUI() {
        const rowWrap = passkeyRegisterBtn ? passkeyRegisterBtn.closest('.metis-people-security-row') : null;
        const chip = rowWrap ? rowWrap.querySelector('.mw-chip') : null;
        if (chip) {
            chip.textContent = 'Not configured';
            chip.classList.remove('mw-chip-success');
        }
    }

    function bindRevokeButton(btn) {
        if (!btn) return;
        btn.addEventListener('click', function () {
            const passkeyId = String(btn.dataset.id || '').trim();
            if (!passkeyId) return;
            post('metis_people_revoke_passkey', { passkey_id: passkeyId })
                .then(function (resp) {
                    const row = btn.closest('.metis-people-mini-item');
                    if (row) row.remove();
                    const list = document.getElementById('metis-people-passkeys-list');
                    if (list && !list.querySelector('.metis-people-mini-item')) {
                        list.innerHTML = '<div class="mw-muted">No passkeys registered.</div>';
                    }
                    if (resp && parseInt(resp.active_count || '0', 10) < 1) {
                        setPasskeyUnconfiguredUI();
                    }
                    showAlert('Passkey revoked.', 'success');
                })
                .catch(function (err) {
                    showAlert(err.message || 'Failed to revoke passkey.', 'error');
                });
        });
    }

    if (passkeyRegisterBtn && window.PublicKeyCredential && activePersonId) {
        passkeyRegisterBtn.addEventListener('click', function () {
            post('metis_people_begin_passkey_registration', { person_id: activePersonId })
                .then(async function (data) {
                    const opts = data && data.public_key ? data.public_key : null;
                    const challengeKey = String((data && data.challenge_key) || '');
                    if (!opts || !challengeKey) throw new Error('Missing passkey options.');
                    const publicKey = {
                        rp: opts.rp,
                        user: Object.assign({}, opts.user, { id: base64UrlToUint8Array(opts.user.id) }),
                        challenge: base64UrlToUint8Array(opts.challenge),
                        pubKeyCredParams: Array.isArray(opts.pubKeyCredParams) ? opts.pubKeyCredParams : [],
                        timeout: opts.timeout || 60000,
                        attestation: opts.attestation || 'none',
                        authenticatorSelection: opts.authenticatorSelection || { userVerification: 'preferred' },
                        excludeCredentials: Array.isArray(opts.excludeCredentials) ? opts.excludeCredentials.map(function (cred) {
                            return { type: cred.type || 'public-key', id: base64UrlToUint8Array(cred.id || '') };
                        }) : []
                    };
                    const credential = await navigator.credentials.create({ publicKey: publicKey });
                    if (!credential || !credential.response) throw new Error('Passkey registration was cancelled.');
                    const response = credential.response;
                    const transports = typeof response.getTransports === 'function' ? response.getTransports() : [];
                    return openPromptModal({
                        title: 'Passkey Label',
                        label: 'Device Name',
                        defaultValue: 'Primary device',
                        placeholder: 'Example: Office MacBook',
                        submitText: 'Save Passkey',
                        required: false,
                        multiline: false
                    }).catch(function (err) {
                        if (err && err.message === 'cancelled') return 'Passkey';
                        throw err;
                    }).then(function (label) {
                        return post('metis_people_complete_passkey_registration', {
                            person_id: activePersonId,
                            challenge_key: challengeKey,
                            credential_id: credential.id,
                            client_data_json: arrayBufferToBase64Url(response.clientDataJSON),
                            attestation_object: arrayBufferToBase64Url(response.attestationObject),
                            transports_json: JSON.stringify(transports || []),
                            label: String(label).trim() || 'Passkey'
                        });
                    });
                })
                .then(function (data) {
                    const passkey = data && data.passkey ? data.passkey : null;
                    setPasskeyConfiguredUI();
                    if (passkey) {
                        const list = document.getElementById('metis-people-passkeys-list');
                        if (list) {
                            const empty = list.querySelector('.mw-muted');
                            if (empty && empty.textContent && empty.textContent.indexOf('No passkeys') >= 0) empty.remove();
                            const item = document.createElement('div');
                            item.className = 'metis-people-mini-item';
                            item.innerHTML = '<div><strong>' + String(passkey.label || 'Passkey') + '</strong></div>'
                                + '<div class="mw-muted">Created: ' + String(passkey.created_at || '') + '</div>'
                                + '<div class="metis-people-mini-actions"><button type="button" class="mw-btn-xs mw-btn-danger metis-passkey-revoke" data-id="' + String(passkey.id || '') + '">Revoke</button></div>';
                            list.prepend(item);
                            bindRevokeButton(item.querySelector('.metis-passkey-revoke'));
                        }
                    }
                    showAlert('Passkey registered.', 'success');
                })
                .catch(function (err) {
                    showAlert(err.message || 'Failed to register passkey.', 'error');
                });
        });
    }

    document.querySelectorAll('.metis-passkey-revoke').forEach(bindRevokeButton);
};
