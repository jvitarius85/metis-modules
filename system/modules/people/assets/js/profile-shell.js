function initMetisPeopleProfileShell(context) {
    const scope = context && context.root ? context.root : document;
    const initRoot = scope === document ? document.documentElement : scope;
    const hasPeopleUi = scope.querySelector('.metis-people-detail, .metis-people-role-detail, .metis-people-activity-log, .metis-people-profile-card, .metis-people-workspace');
    if (!hasPeopleUi) return;
    if (initRoot && initRoot.getAttribute('data-metis-people-shell-initialized') === '1') return;
    if (initRoot) initRoot.setAttribute('data-metis-people-shell-initialized', '1');

    const ajax = window.metisPeopleAjax || null;

    function normalize(v) { return Metis.util.normalize(v); }

    const showAlert = Metis.util.notify;
    let initFailureNotified = false;

    function post(action, data) {
        return Metis.request.post(ajax, action, data || {}, 'People AJAX not configured.');
    }

    function safeInit(name, callback) {
        if (typeof callback !== 'function') return;
        try {
            callback();
        } catch (error) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('Metis people profile init failed for "' + String(name || 'unknown') + '".', error);
            }
            if (!initFailureNotified && typeof showAlert === 'function') {
                initFailureNotified = true;
                showAlert('Part of this page failed to load. The rest of the page is still available.', 'warn');
            }
        }
    }

    const openModal = Metis.modal.open;
    const closeModal = Metis.modal.close;

    function confirmAction(message, options) {
        if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
            return Metis.confirm.open(Object.assign({}, options || {}, {
                message: String(message || 'Are you sure?')
            }));
        }
        return Promise.resolve(false);
    }

    function openPromptModal(opts) {
        return Metis.prompt.open(Object.assign({
            title: 'Provide Details',
            label: 'Value',
            confirmLabel: 'Save'
        }, opts || {})).then(function (value) {
            if (value === null) {
                throw new Error('cancelled');
            }
            return String(value || '').trim();
        });
    }

    function collectRoleWindows() {
        const windows = {};
        const checkboxes = document.querySelectorAll('.metis-role-toggle');
        checkboxes.forEach(function (cb) {
            if (!cb || !cb.checked) return;
            const key = String(cb.dataset.roleKey || cb.value || '').trim();
            if (!key) return;
            const startInput = document.querySelector('.metis-role-start[data-role-key="' + key + '"]');
            const endInput = document.querySelector('.metis-role-end[data-role-key="' + key + '"]');
            windows[key] = {
                start_at: startInput ? String(startInput.value || '') : '',
                end_at: endInput ? String(endInput.value || '') : ''
            };
        });
        return windows;
    }

    function collectNotificationPrefs() {
        const prefs = {};
        document.querySelectorAll('.metis-people-notify-pref').forEach(function (el) {
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

    function nowLocalDateTimeValue() {
        const d = new Date();
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hour = String(d.getHours()).padStart(2, '0');
        const minute = String(d.getMinutes()).padStart(2, '0');
        return year + '-' + month + '-' + day + 'T' + hour + ':' + minute;
    }

    function addDaysLocalDateTimeValue(base, days) {
        const d = base ? new Date(base) : new Date();
        d.setDate(d.getDate() + days);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hour = String(d.getHours()).padStart(2, '0');
        const minute = String(d.getMinutes()).padStart(2, '0');
        return year + '-' + month + '-' + day + 'T' + hour + ':' + minute;
    }

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

    function syncPersonHeaderFromForm() {
        const firstName = String((document.getElementById('metis-people-first-name') || {}).value || '').trim();
        const lastName = String((document.getElementById('metis-people-last-name') || {}).value || '').trim();
        const displayName = String((document.getElementById('metis-people-name') || {}).value || '').trim();
        const email = String((document.getElementById('metis-people-email') || {}).value || '').trim();
        const name = [firstName, lastName].filter(Boolean).join(' ') || displayName || 'Person';
        const title = document.querySelector('.metis-people-detail-header .metis-page-title');
        if (title) title.textContent = name;
        const cardName = document.querySelector('.metis-people-profile-card h3');
        if (cardName) cardName.textContent = name;
        const cardEmail = document.querySelector('.metis-people-profile-card .metis-muted');
        if (cardEmail && email) cardEmail.textContent = email;
    }

    function applyZebraRows(container, selector) {
        if (!container) return;
        const rows = Array.from(container.querySelectorAll(selector));
        rows.forEach(function (row, idx) {
            row.classList.remove('metis-row-even', 'metis-row-odd');
            row.classList.add(idx % 2 === 0 ? 'metis-row-even' : 'metis-row-odd');
        });
    }

    function currentPersonPid(input) {
        const hiddenPid = String(input ? input.value : '').trim();
        if (hiddenPid) return hiddenPid;
        return String(new URLSearchParams(window.location.search).get('pid') || '').trim();
    }

    // ── Tab switching (person detail) ─────────────────────────────────
    // Handles both the new vertical sidebar nav (.metis-people-tab-nav-btn)
    // and the legacy horizontal nav (.metis-tab-btn) so old code still works.
    function activateTab(targetKey) {
        if (!targetKey) return;
        // Deactivate all nav buttons (both old and new selectors)
        document.querySelectorAll('.metis-people-tab-nav-btn, .metis-tab-btn').forEach(function (btn) {
            btn.classList.remove('is-active');
        });
        // Activate matching nav buttons
        document.querySelectorAll(
            '.metis-people-tab-nav-btn[data-tab-target="' + targetKey + '"],' +
            '.metis-tab-btn[data-tab-target="' + targetKey + '"]'
        ).forEach(function (btn) {
            btn.classList.add('is-active');
        });
        // Deactivate all panels
        document.querySelectorAll('.metis-tab-panel').forEach(function (panel) {
            panel.classList.remove('is-active');
        });
        // Activate target panel
        const panel = document.querySelector('.metis-tab-panel[data-tab-panel="' + targetKey + '"]');
        if (panel) panel.classList.add('is-active');
    }

    scope.addEventListener('click', function (e) {
        const btn = e.target.closest('.metis-people-tab-nav-btn, .metis-tab-btn');
        if (!btn) return;
        const target = String(btn.dataset.tabTarget || '').trim();
        if (!target) return;
        e.preventDefault();
        activateTab(target);
    });


    const modules = window.MetisPeopleProfileModules || {};
    const moduleContext = {
        ajax: ajax,
        normalize: normalize,
        showAlert: showAlert,
        post: post,
        openModal: openModal,
        closeModal: closeModal,
        confirmAction: confirmAction,
        applyZebraRows: applyZebraRows
    };

    safeInit('overview', function () {
        if (typeof modules.initOverview === 'function') {
            modules.initOverview(moduleContext);
        }
    });
    safeInit('workspace', function () {
        if (typeof modules.initWorkspace === 'function') {
            modules.initWorkspace(moduleContext);
        }
    });
    // Person detail behavior.
    const personDetailRoot = document.querySelector('.metis-people-detail');
    if (personDetailRoot) {
        const canManage = personDetailRoot.dataset.canManage === '1';
        personDetailRoot.querySelectorAll('[data-tabs-root]').forEach(function (root) {
            const buttons = Array.from(root.querySelectorAll('.metis-tab-btn'));
            const panels = Array.from(root.querySelectorAll('.metis-tab-panel'));
            const activateTab = function (target) {
                buttons.forEach(function (b) { b.classList.toggle('is-active', String(b.dataset.tabTarget || '') === target); });
                panels.forEach(function (panel) {
                    panel.classList.toggle('is-active', String(panel.dataset.tabPanel || '') === target);
                });
            };
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const target = String(btn.dataset.tabTarget || '');
                    activateTab(target);
                });
            });
            const requestedPanel = String(new URLSearchParams(window.location.search).get('panel') || '').trim();
            if (requestedPanel !== '' && panels.some(function (panel) { return String(panel.dataset.tabPanel || '') === requestedPanel; })) {
                activateTab(requestedPanel);
            }
        });
        const linkedDonorInput = document.getElementById('metis-people-linked-donor-id');
        const linkedDonorList = document.getElementById('metis-people-donor-list');
        const linkedDonorName = document.getElementById('metis-people-linked-donor-name');
        const stripeRoleSelect = document.getElementById('metis-people-stripe-role-tab') || document.getElementById('metis-people-stripe-role');
        const stripeRoleHelp = document.getElementById('metis-people-stripe-role-help-tab') || document.getElementById('metis-people-stripe-role-help');
        const stripeRoleDescriptionsEl = document.getElementById('metis-people-stripe-role-descriptions');
        const workspaceGroupToggleWrap = document.getElementById('metis-people-workspace-group-toggle-wrap');
        const workspaceGroupsJson = document.getElementById('metis-people-workspace-groups-json');
        const workspaceUserToggle = document.getElementById('metis-people-workspace-user-tab') || document.getElementById('metis-people-workspace-user');
        const driveFolderAttachBtn = document.getElementById('metis-people-drive-folder-attach');
        const driveFolderNameEl = document.getElementById('metis-people-drive-folder-name');
        const driveFolderWrap = document.getElementById('metis-people-drive-folder-wrap');
        const driveFolderPickerModal = document.getElementById('metis-people-drive-picker-modal');
        const driveFolderPickerCancel = document.getElementById('metis-people-drive-picker-cancel');
        const driveFolderPickerUp = document.getElementById('metis-people-drive-picker-up');
        const driveFolderPickerPath = document.getElementById('metis-people-drive-picker-path');
        const driveFolderPickerStatus = document.getElementById('metis-people-drive-picker-status');
        const driveFolderPickerList = document.getElementById('metis-people-drive-picker-list');
        const personIdInput = document.getElementById('metis-people-id');
        const personPidInput = document.getElementById('metis-people-pid');
        let drivePickerCurrentFolderId = '';
        let drivePickerParentId = '';
        let drivePickerUsersRootId = '';
        let drivePickerUsersRootName = 'Users';
        let stripeRoleDescriptions = {};
        if (stripeRoleDescriptionsEl) {
            try {
                stripeRoleDescriptions = JSON.parse(stripeRoleDescriptionsEl.textContent || '{}') || {};
            } catch (e) {
                stripeRoleDescriptions = {};
            }
        }
        let donorSearchTimer = null;
        let donorLookup = {};
        let workspaceGroupSet = new Set();

        function updateStripeRoleHelp() {
            if (!stripeRoleHelp || !stripeRoleSelect) return;
            const key = String(stripeRoleSelect.value || '');
            stripeRoleHelp.textContent = String(stripeRoleDescriptions[key] || '');
        }

        function applyDriveFolderAttachment(data) {
            const folderName = String((data && data.folder_name) || '');
            const folderUrl = String((data && data.folder_url) || '');
            const wasCreated = !!(data && data.created);

            if (driveFolderNameEl) {
                driveFolderNameEl.textContent = folderName || 'Attached';
                driveFolderNameEl.classList.remove('metis-muted');
                driveFolderNameEl.classList.add('metis-chip');
            }

            let openLink = document.getElementById('metis-people-drive-folder-open');
            if (folderUrl) {
                if (!openLink && driveFolderWrap) {
                    openLink = document.createElement('a');
                    openLink.id = 'metis-people-drive-folder-open';
                    openLink.className = 'metis-btn-xs';
                    openLink.textContent = 'Open in Drive';
                    driveFolderWrap.insertBefore(openLink, driveFolderAttachBtn);
                }
                if (openLink) {
                    openLink.href = folderUrl;
                }
            }

            if (driveFolderAttachBtn) {
                driveFolderAttachBtn.textContent = 'Select Folder';
            }
            showAlert(wasCreated ? 'Drive folder created and attached.' : 'Drive folder attached.', 'success');
        }

        function renderDriveFolderPicker(data) {
            drivePickerCurrentFolderId = String((data && data.folder_id) || '').trim();
            drivePickerParentId = String((data && data.parent_id) || '').trim();
            drivePickerUsersRootId = String((data && data.users_root_id) || drivePickerUsersRootId || '').trim();
            drivePickerUsersRootName = String((data && data.users_root_name) || drivePickerUsersRootName || 'Users').trim();
            if (driveFolderPickerPath) {
                driveFolderPickerPath.textContent = String((data && data.folder_name) || drivePickerUsersRootName || 'Folder');
            }
            if (driveFolderPickerStatus) {
                driveFolderPickerStatus.textContent = '';
            }
            if (driveFolderPickerUp) {
                driveFolderPickerUp.disabled = !drivePickerParentId;
            }
            if (!driveFolderPickerList) return;

            const folders = Array.isArray(data && data.folders) ? data.folders : [];
            if (!folders.length) {
                driveFolderPickerList.innerHTML = '<div class="metis-muted">No folders found here.</div>';
                return;
            }

            driveFolderPickerList.innerHTML = folders.map(function (folder) {
                const id = String((folder && folder.id) || '');
                const name = Metis.util.escapeHtml(String((folder && folder.name) || 'Folder'));
                return '' +
                    '<div class="metis-people-drive-picker-row">' +
                        '<div class="metis-people-drive-picker-main">' +
                            '<button type="button" class="metis-people-drive-picker-open" data-folder-open="' + id + '">' + name + '</button>' +
                            '<div class="metis-muted">Open folder contents or attach it to this person.</div>' +
                        '</div>' +
                        '<div class="metis-people-drive-picker-actions">' +
                            '<button type="button" class="metis-btn metis-btn-ghost" data-folder-open="' + id + '">Open</button>' +
                            '<button type="button" class="metis-btn" data-folder-choose="' + id + '" data-folder-name="' + name + '">Select</button>' +
                        '</div>' +
                    '</div>';
            }).join('');
        }

        function loadDriveFolderPicker(folderId) {
            const sharedDriveId = String((driveFolderAttachBtn && driveFolderAttachBtn.dataset.sharedDriveId) || '').trim();
            drivePickerUsersRootId = String((driveFolderAttachBtn && driveFolderAttachBtn.dataset.usersRootId) || drivePickerUsersRootId || '').trim();
            drivePickerUsersRootName = String((driveFolderAttachBtn && driveFolderAttachBtn.dataset.usersRootName) || drivePickerUsersRootName || 'Users').trim();
            const targetFolderId = String(folderId || drivePickerUsersRootId || '').trim();
            if (!sharedDriveId || !targetFolderId) {
                showAlert('Drive folder picker is not configured.', 'error');
                return Promise.reject(new Error('Drive folder picker is not configured.'));
            }
            if (driveFolderPickerStatus) {
                driveFolderPickerStatus.textContent = 'Loading folders...';
            }
            if (driveFolderPickerList) {
                driveFolderPickerList.innerHTML = '';
            }
            return Metis.request.post(window.metisAjax || null, 'metis_people_drive_folder_picker', {
                drive_id: sharedDriveId,
                folder_id: targetFolderId
            }, 'Drive folder picker is not configured.')
                .then(function (data) {
                    renderDriveFolderPicker(data);
                    return data;
                })
                .catch(function (err) {
                    if (driveFolderPickerStatus) {
                        driveFolderPickerStatus.textContent = '';
                    }
                    showAlert(err.message || 'Failed to load folders.', 'error');
                    throw err;
                });
        }

        function renderDonorOptions(donors) {
            donorLookup = {};
            if (!linkedDonorList) return;
            linkedDonorList.innerHTML = '';
            (donors || []).forEach(function (donor) {
                const did = String(donor.did || '').trim();
                if (!did) return;
                donorLookup[did.toUpperCase()] = donor;
                const option = document.createElement('option');
                option.value = did;
                option.label = String(donor.label || did);
                linkedDonorList.appendChild(option);
            });
        }

        function setLinkedDonorNameFromInput() {
            const did = String(linkedDonorInput ? linkedDonorInput.value : '').trim().toUpperCase();
            if (!linkedDonorName) return;
            if (!did) {
                linkedDonorName.textContent = '';
                return;
            }
            const donor = donorLookup[did];
            linkedDonorName.textContent = donor ? String(donor.label || donor.name || did) : '';
        }

        function searchDonors(q) {
            return post('metis_people_search_donor', { q: q }).then(function (data) {
                return (data && Array.isArray(data.donors)) ? data.donors : [];
            });
        }

        function normalizeWorkspaceGroupEmail(value) {
            const email = String(value || '').trim().toLowerCase();
            if (!email || email.indexOf('@') < 1 || email.indexOf('.') < 3) return '';
            return email;
        }

        function syncWorkspaceGroupsHidden() {
            if (!workspaceGroupsJson) return;
            workspaceGroupsJson.value = JSON.stringify(Array.from(workspaceGroupSet.values()));
        }

        function renderWorkspaceGroupToggles() {
            if (!workspaceGroupToggleWrap) return;
            const buttons = Array.from(workspaceGroupToggleWrap.querySelectorAll('.metis-people-workspace-group-toggle'));
            buttons.forEach(function (btn) {
                const groupEmail = normalizeWorkspaceGroupEmail(btn.dataset.groupEmail || '');
                if (!groupEmail) return;
                if (workspaceGroupSet.has(groupEmail)) {
                    btn.classList.add('is-active');
                } else {
                    btn.classList.remove('is-active');
                }
            });
            syncWorkspaceGroupsHidden();
        }

        if (workspaceGroupsJson) {
            try {
                const parsed = JSON.parse(String(workspaceGroupsJson.value || '[]'));
                if (Array.isArray(parsed)) {
                    parsed.forEach(function (groupEmail) {
                        const normalized = normalizeWorkspaceGroupEmail(groupEmail);
                        if (normalized) workspaceGroupSet.add(normalized);
                    });
                }
            } catch (e) {}
        } else if (workspaceGroupToggleWrap) {
            Array.from(workspaceGroupToggleWrap.querySelectorAll('.metis-people-workspace-group-toggle')).forEach(function (chip) {
                const normalized = normalizeWorkspaceGroupEmail(chip.dataset.groupEmail || chip.textContent || '');
                if (normalized) workspaceGroupSet.add(normalized);
            });
        }
        renderWorkspaceGroupToggles();

        if (canManage && workspaceGroupToggleWrap) {
            workspaceGroupToggleWrap.addEventListener('click', function (event) {
                const button = event.target && event.target.closest ? event.target.closest('.metis-people-workspace-group-toggle') : null;
                if (!button) return;
                if (!workspaceUserToggle || !workspaceUserToggle.checked) {
                    showAlert('Enable Google Workspace User first to manage group membership.', 'error');
                    return;
                }
                const key = normalizeWorkspaceGroupEmail(button.dataset.groupEmail || '');
                if (!key) return;
                if (workspaceGroupSet.has(key)) {
                    workspaceGroupSet.delete(key);
                } else {
                    workspaceGroupSet.add(key);
                }
                renderWorkspaceGroupToggles();
            });
        }

        if (canManage && linkedDonorInput) {
            linkedDonorInput.addEventListener('input', function () {
                const q = String(linkedDonorInput.value || '').trim();
                setLinkedDonorNameFromInput();
                if (donorSearchTimer) window.clearTimeout(donorSearchTimer);
                if (q.length < 2) return;
                donorSearchTimer = window.setTimeout(function () {
                    searchDonors(q).then(function (donors) {
                        renderDonorOptions(donors);
                        setLinkedDonorNameFromInput();
                    }).catch(function () {});
                }, 220);
            });

            linkedDonorInput.addEventListener('change', setLinkedDonorNameFromInput);

            const initialDid = String(linkedDonorInput.value || '').trim();
            if (initialDid !== '') {
                searchDonors(initialDid).then(function (donors) {
                    renderDonorOptions(donors);
                    setLinkedDonorNameFromInput();
                }).catch(function () {});
            }
        }

        if (stripeRoleSelect) {
            stripeRoleSelect.addEventListener('change', updateStripeRoleHelp);
            updateStripeRoleHelp();
        }

        if (driveFolderPickerCancel && driveFolderPickerModal) {
            driveFolderPickerCancel.addEventListener('click', function () {
                closeModal(driveFolderPickerModal);
            });
        }
        if (driveFolderPickerModal) {
            driveFolderPickerModal.addEventListener('click', function (event) {
                if (event.target === driveFolderPickerModal) closeModal(driveFolderPickerModal);
            });
        }
        if (driveFolderPickerUp) {
            driveFolderPickerUp.addEventListener('click', function () {
                if (drivePickerParentId) loadDriveFolderPicker(drivePickerParentId);
            });
        }
        if (driveFolderPickerList) {
            driveFolderPickerList.addEventListener('click', function (event) {
                const openButton = event.target && event.target.closest ? event.target.closest('[data-folder-open]') : null;
                if (openButton) {
                    event.preventDefault();
                    const nextFolderId = String(openButton.getAttribute('data-folder-open') || '').trim();
                    if (nextFolderId) loadDriveFolderPicker(nextFolderId);
                    return;
                }

                const chooseButton = event.target && event.target.closest ? event.target.closest('[data-folder-choose]') : null;
                if (!chooseButton) return;
                event.preventDefault();

                const personId = String(personIdInput ? personIdInput.value : '').trim();
                const personPid = currentPersonPid(personPidInput);
                const folderId = String(chooseButton.getAttribute('data-folder-choose') || '').trim();
                if ((!personId || personId === '0') && !personPid) {
                    showAlert('Save the person first, then attach the drive folder.', 'error');
                    return;
                }
                if (!folderId) return;

                chooseButton.disabled = true;
                post('metis_people_attach_drive_folder_selection', {
                    person_id: personId,
                    pid: personPid,
                    folder_id: folderId
                }).then(function (data) {
                    applyDriveFolderAttachment(data);
                    if (driveFolderPickerModal) closeModal(driveFolderPickerModal);
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to attach drive folder.', 'error');
                }).finally(function () {
                    chooseButton.disabled = false;
                });
            });
        }

        if (canManage && driveFolderAttachBtn && personIdInput) {
            driveFolderAttachBtn.addEventListener('click', function () {
                const personId = String(personIdInput.value || '').trim();
                const personPid = currentPersonPid(personPidInput);
                if ((!personId || personId === '0') && !personPid) {
                    showAlert('Save the person first, then attach the drive folder.', 'error');
                    return;
                }
                if (!driveFolderPickerModal) {
                    showAlert('Drive folder picker is unavailable.', 'error');
                    return;
                }
                openModal(driveFolderPickerModal);
                loadDriveFolderPicker(String(driveFolderAttachBtn.dataset.usersRootId || '').trim());
            });
        }

        const activePersonIdEl = document.getElementById('metis-people-id');
        const activePersonId = String((activePersonIdEl && activePersonIdEl.value) || '0').trim();
        const detailModules = window.MetisPeopleProfileModules || {};
        const detailContext = {
            canManage: canManage,
            activePersonId: activePersonId,
            showAlert: showAlert,
            post: post,
            openModal: openModal,
            closeModal: closeModal,
            openPromptModal: openPromptModal,
            base64UrlToUint8Array: base64UrlToUint8Array,
            arrayBufferToBase64Url: arrayBufferToBase64Url,
            nowLocalDateTimeValue: nowLocalDateTimeValue,
            addDaysLocalDateTimeValue: addDaysLocalDateTimeValue,
            collectRoleWindows: collectRoleWindows,
            collectNotificationPrefs: collectNotificationPrefs,
            currentPersonPid: currentPersonPid,
            personPidInput: personPidInput,
            syncPersonHeaderFromForm: syncPersonHeaderFromForm
        };

        safeInit('person-detail', function () {
            if (typeof detailModules.initPersonDetail === 'function') {
                detailModules.initPersonDetail(detailContext);
            }
        });
        safeInit('security', function () {
            if (typeof detailModules.initSecurity === 'function') {
                detailModules.initSecurity(detailContext);
            }
        });
        safeInit('passkeys', function () {
            if (typeof detailModules.initPasskeys === 'function') {
                detailModules.initPasskeys(detailContext);
            }
        });
        safeInit('roles', function () {
            if (typeof detailModules.initRoles === 'function') {
                detailModules.initRoles(detailContext);
            }
        });
    }

    // Role detail behavior.
    const roleDetailRoot = document.querySelector('.metis-people-role-detail');
    if (roleDetailRoot) {
        const canManage = roleDetailRoot.dataset.canManage === '1';
        const form = document.getElementById('metis-role-detail-form');
        if (canManage && form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                const roleId = document.getElementById('metis-role-id');
                const roleDomain = document.getElementById('metis-role-domain');
                const roleKey = document.getElementById('metis-role-key');
                const roleName = document.getElementById('metis-role-name');
                const roleDescription = document.getElementById('metis-role-description');
                const permissions = Array.from(document.querySelectorAll('.metis-role-perm-toggle:checked')).map(function (cb) {
                    return cb.value;
                });

                post('metis_people_save_role', {
                    role_id: roleId ? roleId.value : '0',
                    role_domain: roleDomain ? roleDomain.value : 'metis',
                    role_key: roleKey ? roleKey.value : '',
                    role_name: roleName ? roleName.value : '',
                    description: roleDescription ? roleDescription.value : '',
                    permissions: JSON.stringify(permissions)
                })
                    .then(function (data) {
                        showAlert('Role saved.', 'success');
                        const url = new URL(window.location.href);
                        const savedRole = String((data && data.role_key) || '').trim();
                        if (savedRole && url.searchParams.get('role') !== savedRole) {
                            url.searchParams.delete('new');
                            url.searchParams.set('role', savedRole);
                            const domainValue = String(roleDomain ? roleDomain.value : '').trim();
                            if (domainValue) {
                                url.searchParams.set('domain', domainValue);
                            }
                            window.history.replaceState({}, '', url.toString());
                            const roleIdValue = String((data && data.role_id) || '').trim();
                            if (roleId && roleIdValue) roleId.value = roleIdValue;
                            return;
                        }
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to save role.', 'error');
                    });
            });
        }
    }

    // Access requests.
    const accessRequestForm = document.getElementById('metis-access-request-form');
    if (accessRequestForm) {
        accessRequestForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const pid = document.getElementById('metis-access-target-pid');
            const roleKey = document.getElementById('metis-access-role-key');
            const reason = document.getElementById('metis-access-reason');
            const requestedStart = document.getElementById('metis-access-requested-start');
            const requestedEnd = document.getElementById('metis-access-requested-end');
            const expiresAt = document.getElementById('metis-access-expires-at');
            const requiredApprovals = document.getElementById('metis-access-required-approvals');
            const requestReason = String(reason ? reason.value : '');
            post('metis_people_create_access_request', {
                target_pid: pid ? pid.value : '',
                role_key: roleKey ? roleKey.value : '',
                reason: requestReason,
                requested_start_at: requestedStart ? requestedStart.value : '',
                requested_end_at: requestedEnd ? requestedEnd.value : '',
                expires_at: expiresAt ? expiresAt.value : '',
                required_approvals: requiredApprovals ? requiredApprovals.value : '2'
            }).then(function (data) {
                showAlert('Access request submitted.', 'success');
                accessRequestForm.reset();
                const table = document.querySelector('.metis-people-ops .metis-premium-table');
                const header = table ? table.querySelector('.metis-premium-header') : null;
                if (!table || !header) return;
                const requestCode = String((data && data.request_code) || '').trim();
                const row = document.createElement('div');
                row.className = 'metis-premium-row';
                row.style.cssText = 'display:grid;grid-template-columns:120px 120px 130px 170px 160px 130px 200px 1fr 170px;align-items:center;';
                row.innerHTML =
                    '<div class="metis-premium-cell">' + requestCode + '</div>' +
                    '<div class="metis-premium-cell">' + String((data && data.status) || 'pending') + '</div>' +
                    '<div class="metis-premium-cell">' + String((data && data.target_pid) || '—') + '</div>' +
                    '<div class="metis-premium-cell">' + String((data && data.target_name) || '—') + '</div>' +
                    '<div class="metis-premium-cell">' + String((data && data.role_name) || '—') + '</div>' +
                    '<div class="metis-premium-cell">' + String((data && data.approval_count) || 0) + '/' + String((data && data.required_approvals) || (requiredApprovals ? requiredApprovals.value : '2')) + '</div>' +
                    '<div class="metis-premium-cell">' +
                        ((data && data.requested_start_at) ? ('Start ' + data.requested_start_at) : '') +
                        ((data && data.requested_end_at) ? (' | End ' + data.requested_end_at) : '') +
                        ((data && data.expires_at) ? (' | Req exp ' + data.expires_at) : '') +
                        (!data || (!data.requested_start_at && !data.requested_end_at && !data.expires_at) ? '—' : '') +
                    '</div>' +
                    '<div class="metis-premium-cell"><div>' + requestReason + '</div></div>' +
                    '<div class="metis-premium-cell"><span class="metis-muted">Pending review</span></div>';
                table.appendChild(row);
            }).catch(function (err) {
                showAlert(err.message || 'Failed to submit request.', 'error');
            });
        });
    }
    document.querySelectorAll('.metis-access-resolve').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const decision = String(btn.dataset.decision || '');
            const noteLabel = decision === 'rejected'
                ? 'Reason for rejection'
                : 'Approval note';
            openPromptModal({
                title: decision === 'rejected' ? 'Reject Access Request' : 'Approve Access Request',
                label: noteLabel,
                defaultValue: '',
                placeholder: 'Required note',
                submitText: decision === 'rejected' ? 'Reject Request' : 'Approve Request',
                required: true,
                multiline: true
            }).then(function (note) {
                return post('metis_people_resolve_access_request', {
                    request_id: btn.dataset.id || '',
                    decision: decision,
                    decision_note: String(note || '').trim()
                });
            }).then(function (data) {
                showAlert('Request updated.', 'success');
                const row = btn.closest('.metis-premium-row');
                if (!row) return;
                const statusCell = row.children[1];
                const approvalsCell = row.children[5];
                const actionsCell = row.children[8];
                const nextStatus = String((data && data.status) || decision);
                if (statusCell) statusCell.textContent = nextStatus;
                if (approvalsCell && data && data.required_approvals) {
                    approvalsCell.textContent = String(data.approval_count || 0) + '/' + String(data.required_approvals || 0);
                }
                if (actionsCell && nextStatus !== 'pending') {
                    actionsCell.innerHTML = '<span class="metis-muted">—</span>';
                }
            }).catch(function (err) {
                if (err && err.message === 'cancelled') return;
                showAlert(err.message || 'Failed to resolve request.', 'error');
            });
        });
    });

    // Templates.
    const templateSaveForm = document.getElementById('metis-template-save-form');
    if (templateSaveForm) {
        templateSaveForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const roleKeys = Array.from(document.querySelectorAll('.metis-template-role:checked')).map(function (cb) { return cb.value; });
            const checklistEl = document.getElementById('metis-template-checklist');
            const checklist = String((checklistEl && checklistEl.value) || '')
                .split(/\r\n|\r|\n/g)
                .map(function (line) { return String(line || '').trim(); })
                .filter(function (line, idx, arr) { return line && arr.indexOf(line) === idx; });
            post('metis_people_save_template', {
                template_key: (document.getElementById('metis-template-key') || {}).value || '',
                template_name: (document.getElementById('metis-template-name') || {}).value || '',
                description: (document.getElementById('metis-template-desc') || {}).value || '',
                role_keys: JSON.stringify(roleKeys),
                checklist_json: JSON.stringify(checklist),
                checklist_text: checklistEl ? checklistEl.value : ''
            }).then(function () {
                showAlert('Template saved.', 'success');
                templateSaveForm.reset();
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save template.', 'error');
            });
        });
    }
    const templateApplyForm = document.getElementById('metis-template-apply-form');
    if (templateApplyForm) {
        templateApplyForm.addEventListener('submit', function (event) {
            event.preventDefault();
            post('metis_people_apply_template', {
                pid: (document.getElementById('metis-template-apply-pid') || {}).value || '',
                template_key: (document.getElementById('metis-template-apply-key') || {}).value || ''
            }).then(function (data) {
                const count = parseInt((data && data.added) || 0, 10);
                const taskCount = parseInt((data && data.tasks_added) || 0, 10);
                showAlert('Template applied (' + count + ' roles, ' + taskCount + ' tasks).', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to apply template.', 'error');
            });
        });
    }

    // Bulk role actions.
    const bulkPeopleList = document.getElementById('metis-bulk-person-list');
    const bulkPeopleSearch = document.getElementById('metis-bulk-person-search');
    const bulkSelectAllBtn = document.getElementById('metis-bulk-select-all');
    const bulkClearAllBtn = document.getElementById('metis-bulk-clear-all');
    const bulkSelectedCount = document.getElementById('metis-bulk-selected-count');
    function bulkSelectedPids() {
        return Array.from(document.querySelectorAll('.metis-bulk-person:checked')).map(function (cb) { return cb.value; });
    }
    function refreshBulkSelectedCount() {
        if (!bulkSelectedCount) return;
        bulkSelectedCount.textContent = String(bulkSelectedPids().length);
    }
    function applyBulkPeopleFilter() {
        if (!bulkPeopleList) return;
        const q = normalize(bulkPeopleSearch ? bulkPeopleSearch.value : '');
        Array.from(bulkPeopleList.querySelectorAll('.metis-people-bulk-person-item')).forEach(function (row) {
            const hay = normalize(row.dataset.search || '');
            const visible = !q || hay.indexOf(q) >= 0;
            row.style.display = visible ? '' : 'none';
        });
    }
    if (bulkPeopleList) {
        bulkPeopleList.addEventListener('change', function (event) {
            if (event.target && event.target.classList && event.target.classList.contains('metis-bulk-person')) {
                refreshBulkSelectedCount();
            }
        });
        refreshBulkSelectedCount();
    }
    if (bulkPeopleSearch) {
        bulkPeopleSearch.addEventListener('input', applyBulkPeopleFilter);
        applyBulkPeopleFilter();
    }
    if (bulkSelectAllBtn && bulkPeopleList) {
        bulkSelectAllBtn.addEventListener('click', function () {
            Array.from(bulkPeopleList.querySelectorAll('.metis-people-bulk-person-item')).forEach(function (row) {
                if (row.style.display === 'none') return;
                const cb = row.querySelector('.metis-bulk-person');
                if (cb) cb.checked = true;
            });
            refreshBulkSelectedCount();
        });
    }
    if (bulkClearAllBtn) {
        bulkClearAllBtn.addEventListener('click', function () {
            Array.from(document.querySelectorAll('.metis-bulk-person:checked')).forEach(function (cb) { cb.checked = false; });
            refreshBulkSelectedCount();
        });
    }

    const bulkPositionType = document.getElementById('metis-bulk-position-type');
    const bulkPositionValue = document.getElementById('metis-bulk-position-value');
    function syncBulkPositionOptions() {
        if (!bulkPositionType || !bulkPositionValue) return;
        const type = String(bulkPositionType.value || '').trim();
        const options = Array.from(bulkPositionValue.options || []);
        options.forEach(function (opt, index) {
            if (index === 0) {
                opt.hidden = false;
                return;
            }
            const group = String(opt.getAttribute('data-group') || '').trim();
            opt.hidden = !(type !== '' && type !== 'clear' && group === type);
        });
        if (type === 'clear') {
            bulkPositionValue.value = '';
            bulkPositionValue.disabled = true;
        } else {
            bulkPositionValue.disabled = type === '';
            const selected = bulkPositionValue.options[bulkPositionValue.selectedIndex];
            const selectedGroup = selected ? String(selected.getAttribute('data-group') || '').trim() : '';
            if (type !== '' && selectedGroup !== type) bulkPositionValue.value = '';
        }
    }
    if (bulkPositionType) {
        bulkPositionType.addEventListener('change', syncBulkPositionOptions);
        syncBulkPositionOptions();
    }
    function runBulkForm(formEl, workFn) {
        if (!formEl || typeof workFn !== 'function') return;
        let inFlight = false;
        formEl.addEventListener('submit', function (event) {
            event.preventDefault();
            if (inFlight) return;
            const submitBtn = formEl.querySelector('button[type="submit"]');
            inFlight = true;
            if (submitBtn) submitBtn.disabled = true;
            Promise.resolve()
                .then(workFn)
                .catch(function (err) {
                    if (err && err.message === 'cancelled') return;
                    showAlert((err && err.message) || 'Bulk action failed.', 'error');
                })
                .finally(function () {
                    inFlight = false;
                    if (submitBtn) submitBtn.disabled = false;
                });
        });
    }

    const bulkWorkspaceUserAction = document.getElementById('metis-bulk-workspace-user-action');
    const bulkOrgUnitField = document.getElementById('metis-bulk-org-unit-field');
    const bulkOrgUnit = document.getElementById('metis-bulk-org-unit');
    function syncBulkWorkspaceActionFields() {
        if (!bulkWorkspaceUserAction || !bulkOrgUnitField) return;
        const action = String(bulkWorkspaceUserAction.value || '').trim();
        const needsOrgUnit = action === 'set_org_unit';
        bulkOrgUnitField.style.display = needsOrgUnit ? '' : 'none';
        if (bulkOrgUnit) bulkOrgUnit.disabled = !needsOrgUnit;
    }
    if (bulkWorkspaceUserAction) {
        bulkWorkspaceUserAction.addEventListener('change', syncBulkWorkspaceActionFields);
        syncBulkWorkspaceActionFields();
    }

    runBulkForm(document.getElementById('metis-bulk-role-form'), function () {
        const pids = bulkSelectedPids();
        if (!pids.length) throw new Error('Select at least one person.');
        return post('metis_people_bulk_role_action', {
            role_key: (document.getElementById('metis-bulk-role') || {}).value || '',
            bulk_action: (document.getElementById('metis-bulk-action') || {}).value || 'assign',
            person_pids: JSON.stringify(pids)
        }).then(function (data) {
            const count = parseInt((data && data.updated) || 0, 10);
            showAlert('Bulk role action completed (' + count + ' updated).', 'success');
        });
    });

    runBulkForm(document.getElementById('metis-bulk-profile-form'), function () {
        const pids = bulkSelectedPids();
        if (!pids.length) throw new Error('Select at least one person.');
        return post('metis_people_bulk_profile_action', {
            position_type: bulkPositionType ? bulkPositionType.value : '',
            position_value: bulkPositionValue ? bulkPositionValue.value : '',
            person_pids: JSON.stringify(pids)
        }).then(function (data) {
            const updated = parseInt((data && data.updated) || 0, 10);
            showAlert('Bulk position action completed (' + updated + ' updated).', 'success');
        });
    });

    runBulkForm(document.getElementById('metis-bulk-workspace-user-form'), function () {
        const pids = bulkSelectedPids();
        if (!pids.length) throw new Error('Select at least one person.');
        return post('metis_people_bulk_workspace_user_action', {
            workspace_action: (bulkWorkspaceUserAction || {}).value || 'set_org_unit',
            org_unit_path: (bulkOrgUnit || {}).value || '/',
            person_pids: JSON.stringify(pids)
        }).then(function (data) {
            const updated = parseInt((data && data.updated) || 0, 10);
            const skipped = parseInt((data && data.skipped) || 0, 10);
            const failed = parseInt((data && data.failed) || 0, 10);
            showAlert('Workspace action completed (' + updated + ' updated, ' + skipped + ' skipped, ' + failed + ' failed).', failed > 0 ? 'warn' : 'success');
        });
    });

    runBulkForm(document.getElementById('metis-bulk-workspace-group-form'), function () {
        const pids = bulkSelectedPids();
        if (!pids.length) throw new Error('Select at least one person.');
        return post('metis_people_bulk_workspace_group_action', {
            group_email: (document.getElementById('metis-bulk-workspace-group') || {}).value || '',
            bulk_action: (document.getElementById('metis-bulk-workspace-group-action') || {}).value || 'assign',
            member_role: (document.getElementById('metis-bulk-workspace-member-role') || {}).value || 'member',
            person_pids: JSON.stringify(pids)
        }).then(function (data) {
            const updated = parseInt((data && data.updated) || 0, 10);
            const skipped = parseInt((data && data.skipped) || 0, 10);
            showAlert('Workspace group action completed (' + updated + ' updated, ' + skipped + ' skipped).', 'success');
        });
    });

    runBulkForm(document.getElementById('metis-bulk-stripe-role-form'), function () {
        const pids = bulkSelectedPids();
        if (!pids.length) throw new Error('Select at least one person.');
        return post('metis_people_bulk_stripe_role_action', {
            stripe_role: (document.getElementById('metis-bulk-stripe-role') || {}).value || '',
            bulk_action: (document.getElementById('metis-bulk-stripe-action') || {}).value || 'set',
            person_pids: JSON.stringify(pids)
        }).then(function (data) {
            const updated = parseInt((data && data.updated) || 0, 10);
            showAlert('Stripe action completed (' + updated + ' updated).', 'success');
        });
    });

    runBulkForm(document.getElementById('metis-bulk-offboard-form'), function () {
        const pids = bulkSelectedPids();
        const confirmEl = document.getElementById('metis-bulk-offboard-confirm');
        if (!pids.length) throw new Error('Select at least one person.');
        if (!confirmEl || !confirmEl.checked) throw new Error('Confirm offboarding before applying.');
        return confirmAction('Run offboarding for selected people? This will deactivate selected Metis users.')
            .then(function (ok) {
                if (!ok) throw new Error('cancelled');
                let successCount = 0;
                let failedCount = 0;
                let chain = Promise.resolve();
                pids.forEach(function (pid) {
                    chain = chain.then(function () {
                        return post('metis_people_offboard_person', { pid: pid })
                            .then(function () { successCount += 1; })
                            .catch(function () { failedCount += 1; });
                    });
                });
                return chain.then(function () {
                    showAlert('Offboarding completed (' + successCount + ' done, ' + failedCount + ' failed).', failedCount > 0 ? 'warn' : 'success');
                });
            });
    });

    const activityRoot = document.querySelector('.metis-people-activity-log');
    if (activityRoot) {
        const activityRows = document.getElementById('metis-people-activity-rows');
        const activityPager = document.getElementById('metis-people-activity-pagination');
        const activityPageLabel = document.getElementById('metis-people-activity-page-label');
        const activityPageActions = document.getElementById('metis-people-activity-page-actions');
        const activityFilter = document.getElementById('metis-people-activity-filter');
        let activityFilterTimer = null;

        function renderActivityRows(rows) {
            if (!activityRows) return;
            const list = Array.isArray(rows) ? rows : [];
            if (!list.length) {
                activityRows.innerHTML =
                    '<tr class="metis-premium-row">' +
                        '<td class="metis-premium-cell" colspan="5">No activity yet.</td>' +
                    '</tr>';
                return;
            }
            activityRows.innerHTML = list.map(function (row) {
                return '' +
                    '<tr class="metis-premium-row">' +
                        '<td class="metis-premium-cell">' + Metis.util.escapeHtml(String((row && row.time) || '')) + '</td>' +
                        '<td class="metis-premium-cell">' + Metis.util.escapeHtml(String((row && row.type) || '')) + '</td>' +
                        '<td class="metis-premium-cell">' + Metis.util.escapeHtml(String((row && row.summary) || '')) + '</td>' +
                        '<td class="metis-premium-cell">' + Metis.util.escapeHtml(String((row && row.target) || '—')) + '</td>' +
                        '<td class="metis-premium-cell">' + Metis.util.escapeHtml(String((row && row.actor) || 'System')) + '</td>' +
                    '</tr>';
            }).join('');
        }

        function renderActivityPagination(payload) {
            if (!activityPager || !activityPageLabel || !activityPageActions) return;
            const page = parseInt(String((payload && payload.page) || '1'), 10) || 1;
            const totalPages = parseInt(String((payload && payload.total_pages) || '1'), 10) || 1;
            const q = String((payload && payload.q) || (activityFilter ? activityFilter.value : '') || '').trim();
            activityPager.dataset.page = String(page);
            activityPager.dataset.totalPages = String(totalPages);
            activityPager.dataset.q = q;
            activityPageLabel.textContent = 'Page ' + String(page) + ' of ' + String(totalPages);
            let html = '';
            if (payload && payload.has_prev) {
                html += '<button type="button" class="metis-workspace-page-link" data-activity-page="' + String((payload && payload.prev_page) || 1) + '">&larr; Previous</button>';
            }
            if (payload && payload.has_next) {
                html += '<button type="button" class="metis-workspace-page-link" data-activity-page="' + String((payload && payload.next_page) || page) + '">Next &rarr;</button>';
            }
            activityPageActions.innerHTML = html;
        }

        function loadActivityPage(page, q) {
            if (!activityRoot) return Promise.resolve();
            const targetPage = parseInt(String(page || (activityPager && activityPager.dataset.page) || '1'), 10) || 1;
            const targetQuery = String(q !== undefined ? q : ((activityFilter && activityFilter.value) || (activityPager && activityPager.dataset.q) || '')).trim();
            activityRoot.classList.add('is-loading');
            return post('metis_people_get_activity_page', { page: String(targetPage), q: targetQuery })
                .then(function (payload) {
                    renderActivityRows(payload && payload.rows ? payload.rows : []);
                    renderActivityPagination(payload || {});
                })
                .catch(function (err) {
                    showAlert((err && err.message) ? err.message : 'Failed to load activity page.', 'error');
                })
                .finally(function () {
                    activityRoot.classList.remove('is-loading');
                });
        }

        activityRoot.addEventListener('click', function (event) {
            const btn = event.target && event.target.closest ? event.target.closest('[data-activity-page]') : null;
            if (!btn) return;
            event.preventDefault();
            event.stopPropagation();
            loadActivityPage(btn.getAttribute('data-activity-page'));
        });
        if (activityFilter) {
            activityFilter.addEventListener('input', function () {
                const value = String(activityFilter.value || '');
                if (activityFilterTimer) window.clearTimeout(activityFilterTimer);
                activityFilterTimer = window.setTimeout(function () {
                    loadActivityPage(1, value);
                }, 180);
            });
        }
    }
}

if (window.Metis && Metis.page && typeof Metis.page.register === 'function') {
    Metis.page.register('people-profile-shell', initMetisPeopleProfileShell);
} else {
    document.addEventListener('DOMContentLoaded', function () {
        try {
            initMetisPeopleProfileShell({ root: document, reason: 'dom-ready', url: window.location.href });
        } catch (error) {
            if (window.console && typeof window.console.error === 'function') {
                window.console.error('Metis people profile shell fallback init failed.', error);
            }
        }
    });
}
