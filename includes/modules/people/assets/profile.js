document.addEventListener('DOMContentLoaded', function () {
    const ajax = window.metisPeopleAjax || null;

    function normalize(v) { return Metis.util.normalize(v); }

    const showAlert = Metis.util.notify;

    function post(action, data) {
        return Metis.request.post(ajax, action, data || {}, 'People AJAX not configured.');
    }

    const openModal = Metis.modal.open;
    const closeModal = Metis.modal.close;

    function ensurePeoplePromptModal() {
        let modal = document.getElementById('metis-people-prompt-modal');
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = 'metis-people-prompt-modal';
        modal.className = 'metis-contacts-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML =
            '<div class="metis-contacts-modal-inner metis-people-modal-inner">' +
                '<h3 id="metis-people-prompt-title" class="metis-contacts-modal-title">Confirm</h3>' +
                '<div class="metis-contact-form">' +
                    '<div class="metis-contact-field metis-contact-field-full">' +
                        '<label id="metis-people-prompt-label" for="metis-people-prompt-input">Note</label>' +
                        '<textarea id="metis-people-prompt-input" class="mw-input" rows="3"></textarea>' +
                    '</div>' +
                    '<div class="metis-contact-actions">' +
                        '<button type="button" id="metis-people-prompt-cancel" class="mw-btn mw-btn-ghost">Cancel</button>' +
                        '<button type="button" id="metis-people-prompt-submit" class="mw-btn">Save</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(modal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal(modal);
        });
        return modal;
    }

    function openPromptModal(opts) {
        const options = opts || {};
        const modal = ensurePeoplePromptModal();
        const title = document.getElementById('metis-people-prompt-title');
        const label = document.getElementById('metis-people-prompt-label');
        const input = document.getElementById('metis-people-prompt-input');
        const cancel = document.getElementById('metis-people-prompt-cancel');
        const submit = document.getElementById('metis-people-prompt-submit');
        if (!modal || !input || !cancel || !submit || !title || !label) {
            return Promise.reject(new Error('Prompt modal unavailable.'));
        }
        title.textContent = String(options.title || 'Provide Details');
        label.textContent = String(options.label || 'Value');
        input.value = String(options.defaultValue || '');
        input.placeholder = String(options.placeholder || '');
        submit.textContent = String(options.submitText || 'Save');
        const multiline = options.multiline !== false;
        if (multiline) {
            input.setAttribute('rows', String(options.rows || 3));
        } else {
            input.setAttribute('rows', '1');
        }
        return new Promise(function (resolve, reject) {
            let settled = false;
            function cleanup() {
                cancel.removeEventListener('click', onCancel);
                submit.removeEventListener('click', onSubmit);
                input.removeEventListener('keydown', onKeyDown);
                modal.removeEventListener('click', onBackdrop);
            }
            function onCancel() {
                if (settled) return;
                settled = true;
                cleanup();
                closeModal(modal);
                reject(new Error('cancelled'));
            }
            function onSubmit() {
                const value = String(input.value || '').trim();
                if (options.required && !value) {
                    input.focus();
                    return;
                }
                if (settled) return;
                settled = true;
                cleanup();
                closeModal(modal);
                resolve(value);
            }
            function onKeyDown(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    onCancel();
                    return;
                }
                if (!multiline && event.key === 'Enter') {
                    event.preventDefault();
                    onSubmit();
                }
            }
            function onBackdrop(event) {
                if (event.target === modal) onCancel();
            }
            cancel.addEventListener('click', onCancel);
            submit.addEventListener('click', onSubmit);
            input.addEventListener('keydown', onKeyDown);
            modal.addEventListener('click', onBackdrop);
            openModal(modal);
            window.setTimeout(function () { input.focus(); input.select(); }, 20);
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
        const title = document.querySelector('.metis-people-detail-header .mw-page-title');
        if (title) title.textContent = name;
        const cardName = document.querySelector('.metis-people-profile-card h3');
        if (cardName) cardName.textContent = name;
        const cardEmail = document.querySelector('.metis-people-profile-card .mw-muted');
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

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.metis-people-tab-nav-btn, .metis-tab-btn');
        if (!btn) return;
        const target = String(btn.dataset.tabTarget || '').trim();
        if (!target) return;
        e.preventDefault();
        activateTab(target);
    });

    // Dashboard behavior.
    const dashboardRoot = document.querySelector('.metis-people');
    if (dashboardRoot) {
        const personBaseUrl = String(dashboardRoot.dataset.personBaseUrl || '');
        const roleBaseUrl = String(dashboardRoot.dataset.roleBaseUrl || '');
        const rowsWrap = document.getElementById('metis-people-rows');
        const roleRowsWrap = document.getElementById('metis-role-rows');
        const search = document.getElementById('metis-people-search');
        const addPeopleModal = document.getElementById('metis-people-add-modal');
        const addRoleModal = document.getElementById('metis-role-add-modal');

        function applySearch() {
            if (!rowsWrap) return;
            const query = normalize(search ? search.value : '');
            const visible = [];
            Array.from(rowsWrap.querySelectorAll('.metis-people-row')).forEach(function (row) {
                const hay = normalize(row.dataset.search || '');
                const matched = !query || hay.indexOf(query) >= 0;
                row.style.display = matched ? '' : 'none';
                row.classList.remove('metis-row-even', 'metis-row-odd');
                if (matched) visible.push(row);
            });

            visible.forEach(function (row, index) {
                row.classList.add(index % 2 === 0 ? 'metis-row-even' : 'metis-row-odd');
            });
        }

        if (search) search.addEventListener('input', applySearch);
        applySearch();

        // Role search filter.
        const roleSearch = document.getElementById('metis-role-search');
        function applyRoleSearch() {
            if (!roleRowsWrap) return;
            const query = normalize(roleSearch ? roleSearch.value : '');
            Array.from(roleRowsWrap.querySelectorAll('.metis-role-row')).forEach(function (row) {
                const hay = normalize(row.textContent || '');
                row.style.display = (!query || hay.indexOf(query) >= 0) ? '' : 'none';
            });
        }
        if (roleSearch) roleSearch.addEventListener('input', applyRoleSearch);

        const addPeopleOpen = document.getElementById('metis-people-add-open');
        const addPeopleCancel = document.getElementById('metis-people-add-cancel');
        const addPeopleForm = document.getElementById('metis-people-add-form');

        if (addPeopleOpen) addPeopleOpen.addEventListener('click', function () { openModal(addPeopleModal); });
        if (addPeopleCancel) addPeopleCancel.addEventListener('click', function () { closeModal(addPeopleModal); });
        if (addPeopleModal) addPeopleModal.addEventListener('click', function (event) {
            if (event.target === addPeopleModal) closeModal(addPeopleModal);
        });

        if (addPeopleForm) {
            addPeopleForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const firstName = document.getElementById('metis-people-add-first-name');
                const lastName = document.getElementById('metis-people-add-last-name');
                const email = document.getElementById('metis-people-add-email');
                const fullName = [String(firstName ? firstName.value : '').trim(), String(lastName ? lastName.value : '').trim()].filter(Boolean).join(' ');

                post('metis_people_save_person', {
                    person_id: '0',
                    first_name: firstName ? firstName.value : '',
                    last_name: lastName ? lastName.value : '',
                    display_name: fullName,
                    email: email ? email.value : '',
                    auth_provider: 'metis',
                    is_workspace_user: '0',
                    workspace_email: '',
                    workspace_role: '',
                    stripe_role: '',
                    linked_donor_id: '',
                    status: 'active',
                    is_staff: '0',
                    is_board: '0',
                    is_volunteer: '0',
                    roles: '[]'
                }).then(function (data) {
                    const pid = String((data && data.pid) || '').trim();
                    if (pid && rowsWrap) {
                        const personName = fullName || 'Person';
                        const personEmail = String(email ? email.value : '').trim();
                        const href = personBaseUrl ? (personBaseUrl + '?pid=' + encodeURIComponent(pid)) : '';
                        const row = document.createElement('div');
                        row.className = 'mw-premium-row metis-people-row';
                        row.dataset.search = normalize([pid, personName, personEmail, 'metis', 'no'].join(' '));
                        if (href) row.dataset.href = href;
                        row.innerHTML =
                            '<div class="mw-premium-cell"><strong>' + personName + '</strong><div class="mw-muted">' + pid + '</div></div>' +
                            '<div class="mw-premium-cell">' + personEmail + '</div>' +
                            '<div class="mw-premium-cell">Metis</div>' +
                            '<div class="mw-premium-cell">—</div>' +
                            '<div class="mw-premium-cell">No</div>' +
                            '<div class="mw-premium-cell">—</div>' +
                            '<div class="mw-premium-cell">' + (href ? ('<a href="' + href + '" class="mw-btn-xs">Edit</a>') : '—') + '</div>';
                        rowsWrap.prepend(row);
                        applyZebraRows(rowsWrap, '.metis-people-row');
                        closeModal(addPeopleModal);
                        addPeopleForm.reset();
                        showAlert('Person created.', 'success');
                        return;
                    }
                    showAlert('Person created.', 'success');
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to create person.', 'error');
                });
            });
        }

        const addRoleOpen = document.getElementById('metis-role-add-open');
        const addRoleCancel = document.getElementById('metis-role-add-cancel');
        const addRoleForm = document.getElementById('metis-role-add-form');

        if (addRoleOpen) addRoleOpen.addEventListener('click', function () { openModal(addRoleModal); });
        if (addRoleCancel) addRoleCancel.addEventListener('click', function () { closeModal(addRoleModal); });
        if (addRoleModal) addRoleModal.addEventListener('click', function (event) {
            if (event.target === addRoleModal) closeModal(addRoleModal);
        });

        if (addRoleForm) {
            addRoleForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const roleKey = document.getElementById('metis-role-add-key');
                const roleName = document.getElementById('metis-role-add-name');
                const roleDomain = document.getElementById('metis-role-add-domain');
                const roleDescription = document.getElementById('metis-role-add-description');
                post('metis_people_save_role', {
                    role_id: '0',
                    role_key: roleKey ? roleKey.value : '',
                    role_domain: roleDomain ? roleDomain.value : 'metis',
                    role_name: roleName ? roleName.value : '',
                    description: roleDescription ? roleDescription.value : '',
                    permissions: '[]'
                }).then(function (data) {
                    const savedRole = String((data && data.role_key) || '').trim();
                    const savedDomain = String(roleDomain ? roleDomain.value : 'metis').trim();
                    const roleNameText = String(roleName ? roleName.value : '').trim() || savedRole;
                    if (savedRole && roleRowsWrap) {
                        const href = roleBaseUrl ? (roleBaseUrl + '?role=' + encodeURIComponent(savedRole) + '&domain=' + encodeURIComponent(savedDomain || 'metis')) : '';
                        const row = document.createElement('div');
                        row.className = 'mw-premium-row metis-role-row';
                        if (href) row.dataset.href = href;
                        row.innerHTML =
                            '<div class="mw-premium-cell metis-role-col-key">' + savedRole + '</div>' +
                            '<div class="mw-premium-cell metis-role-col-name">' + roleNameText + '</div>' +
                            '<div class="mw-premium-cell metis-role-col-perms">0</div>' +
                            '<div class="mw-premium-cell metis-role-col-members">0</div>' +
                            '<div class="mw-premium-cell metis-role-col-system">No</div>' +
                            '<div class="mw-premium-cell metis-role-col-actions">' + (href ? ('<a href="' + href + '" class="mw-btn-xs">Edit</a>') : '—') + '</div>';
                        roleRowsWrap.prepend(row);
                        applyZebraRows(roleRowsWrap, '.metis-role-row');
                        closeModal(addRoleModal);
                        addRoleForm.reset();
                        showAlert('Role created.', 'success');
                        return;
                    }
                    showAlert('Role created.', 'success');
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to create role.', 'error');
                });
            });
        }

        if (rowsWrap) {
            rowsWrap.addEventListener('click', function (event) {
                const row = event.target.closest('.metis-people-row');
                if (!row) return;
                const href = row.dataset.href;
                if (!href) return;
                if (event.target.closest('a')) {
                    return;
                }
                window.location.href = href;
            });
        }

        if (roleRowsWrap) {
            Array.from(roleRowsWrap.querySelectorAll('.metis-role-row')).forEach(function (row, index) {
                row.classList.remove('metis-row-even', 'metis-row-odd');
                row.classList.add(index % 2 === 0 ? 'metis-row-even' : 'metis-row-odd');
            });

            roleRowsWrap.addEventListener('click', function (event) {
                const row = event.target.closest('.metis-role-row');
                if (!row) return;
                const href = row.dataset.href;
                if (!href) return;
                if (event.target.closest('a')) {
                    return;
                }
                window.location.href = href;
            });
        }
    }

    // Dashboard landing tile search.
    const dashboardLanding = document.querySelector('.metis-people-dashboard');
    if (dashboardLanding) {
        const personBaseUrl = String(dashboardLanding.dataset.personBaseUrl || '');
        const searchInput = document.getElementById('metis-people-dashboard-search');
        const resultsWrap = document.getElementById('metis-people-dashboard-results');
        let searchTimer = null;

        function renderDashboardPeopleResults(people) {
            if (!resultsWrap) return;
            resultsWrap.innerHTML = '';
            const list = Array.isArray(people) ? people : [];
            if (!list.length) {
                resultsWrap.style.display = 'none';
                return;
            }
            list.forEach(function (person) {
                const pid = String(person.pid || '').trim();
                if (!pid) return;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'metis-people-search-result';
                btn.dataset.pid = pid;
                btn.textContent = String(person.label || pid);
                resultsWrap.appendChild(btn);
            });
            resultsWrap.style.display = resultsWrap.children.length ? 'block' : 'none';
        }

        function searchPeople(q) {
            return post('metis_people_search_person', { q: q }).then(function (data) {
                return (data && Array.isArray(data.people)) ? data.people : [];
            });
        }

        if (searchInput && resultsWrap) {
            searchInput.addEventListener('input', function () {
                const q = String(searchInput.value || '').trim();
                if (searchTimer) window.clearTimeout(searchTimer);
                if (q.length < 2) {
                    renderDashboardPeopleResults([]);
                    return;
                }
                searchTimer = window.setTimeout(function () {
                    searchPeople(q).then(function (people) {
                        renderDashboardPeopleResults(people);
                    }).catch(function () {
                        renderDashboardPeopleResults([]);
                    });
                }, 180);
            });

            resultsWrap.addEventListener('click', function (event) {
                const btn = event.target.closest('.metis-people-search-result');
                if (!btn) return;
                const pid = String(btn.dataset.pid || '').trim();
                if (!pid || !personBaseUrl) return;
                window.location.href = personBaseUrl + '?pid=' + encodeURIComponent(pid);
            });

            document.addEventListener('click', function (event) {
                if (event.target === searchInput || resultsWrap.contains(event.target)) return;
                resultsWrap.style.display = 'none';
            });
        }
    }

    // Workspace management.
    const workspaceRoot = document.querySelector('.metis-people-workspace');
    if (workspaceRoot) {
        const canManage = workspaceRoot.dataset.canManage === '1';
        const userSearch = document.getElementById('metis-workspace-user-search');
        const userRowsWrap = document.getElementById('metis-workspace-user-rows');
        function applyWorkspaceRowClasses() {
            if (!userRowsWrap) return;
            const rows = Array.from(userRowsWrap.querySelectorAll('.metis-workspace-user-row'));
            let visibleIndex = 0;
            rows.forEach(function (row) {
                row.classList.remove('metis-row-even', 'metis-row-odd');
                if (row.style.display === 'none') return;
                row.classList.add(visibleIndex % 2 === 0 ? 'metis-row-even' : 'metis-row-odd');
                visibleIndex++;
            });
        }
        if (userSearch && userRowsWrap) {
            userSearch.addEventListener('input', function () {
                const q = normalize(userSearch.value);
                Array.from(userRowsWrap.querySelectorAll('.metis-workspace-user-row')).forEach(function (row) {
                    const hay = normalize(row.dataset.search || '');
                    row.style.display = !q || hay.indexOf(q) >= 0 ? '' : 'none';
                });
                applyWorkspaceRowClasses();
            });
            userRowsWrap.addEventListener('click', function (event) {
                const interactive = event.target.closest('button, a, input, select, textarea, label');
                if (interactive) return;
                const row = event.target.closest('.metis-workspace-user-row');
                if (!row) return;
                const personUrl = String(row.dataset.personUrl || '').trim();
                if (!personUrl) return;
                window.location.href = personUrl;
            });
            applyWorkspaceRowClasses();
        }
        if (canManage) {
            const importUsersButton = document.getElementById('metis-workspace-import-users');
            const fullSyncButton = document.getElementById('metis-workspace-full-sync');
            const runSyncButton = document.getElementById('metis-workspace-sync-run');
            const userModal = document.getElementById('metis-workspace-user-modal');
            const groupModal = document.getElementById('metis-workspace-group-modal');
            const securityModal = document.getElementById('metis-workspace-security-modal');
            const roleMapModal = document.getElementById('metis-workspace-role-map-modal');
            const roleMapOpen = document.getElementById('metis-workspace-role-map-open');
            const roleMapClose = document.getElementById('metis-workspace-role-map-close');
            const roleMapRefresh = document.getElementById('metis-workspace-role-map-refresh');
            const roleMapRows = document.getElementById('metis-workspace-role-map-rows');
            const roleMapAlert = document.getElementById('metis-workspace-role-map-alert');
            const inspectUserOpen = document.getElementById('metis-workspace-inspect-user-open');
            const inspectUserModal = document.getElementById('metis-workspace-inspect-user-modal');
            const inspectUserClose = document.getElementById('metis-workspace-inspect-user-close');
            const inspectUserForm = document.getElementById('metis-workspace-inspect-user-form');
            const inspectUserEmail = document.getElementById('metis-workspace-inspect-user-email');
            const inspectUserOutput = document.getElementById('metis-workspace-inspect-user-output');
            const inspectUserRun = document.getElementById('metis-workspace-inspect-user-run');

            function escapeHtml(value) {
                return String(value || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function setRoleMapAlert(message, type) {
                if (!roleMapAlert) return;
                const txt = String(message || '').trim();
                if (!txt) {
                    roleMapAlert.style.display = 'none';
                    roleMapAlert.textContent = '';
                    roleMapAlert.className = 'mw-alert';
                    return;
                }
                roleMapAlert.className = 'mw-alert ' + (type === 'error' ? 'mw-alert-error' : 'mw-alert-success');
                roleMapAlert.textContent = txt;
                roleMapAlert.style.display = 'block';
            }

            function renderRoleMapRows(rows) {
                if (!roleMapRows) return;
                const list = Array.isArray(rows) ? rows : [];
                if (!list.length) {
                    roleMapRows.innerHTML = '<div class="mw-muted" style="padding:12px;">No roles returned from Google.</div>';
                    return;
                }
                roleMapRows.innerHTML = list.map(function (row) {
                    const friendly = escapeHtml((row && row.friendly_name) || '');
                    const googleName = escapeHtml((row && row.google_role_name) || '');
                    const metisKey = escapeHtml((row && row.metis_role_key) || '');
                    const googleId = escapeHtml((row && row.google_role_id) || '');
                    const assignedCount = parseInt((row && row.assigned_count) || 0, 10);
                    const assignedLabel = assignedCount > 0 ? String(assignedCount) : '0';
                    return '<div class="mw-premium-row">' +
                        '<div class="mw-premium-cell">' +
                            '<strong>' + friendly + '</strong>' +
                            '<div class="mw-muted" title="Google: ' + googleName + ' | ID: ' + googleId + '">Google: ' + googleName + '</div>' +
                        '</div>' +
                        '<div class="mw-premium-cell"><code>' + metisKey + '</code></div>' +
                        '<div class="mw-premium-cell">' + assignedLabel + '</div>' +
                    '</div>';
                }).join('');
            }

            function loadWorkspaceRoleMap() {
                if (!roleMapRows) return Promise.resolve();
                roleMapRows.innerHTML = '<div class="mw-muted" style="padding:12px;">Loading role map...</div>';
                setRoleMapAlert('', '');
                return post('metis_people_workspace_get_role_map', {})
                    .then(function (data) {
                        const rows = (data && Array.isArray(data.roles)) ? data.roles : [];
                        renderRoleMapRows(rows);
                        const total = parseInt((data && data.total_roles) || rows.length || 0, 10);
                        setRoleMapAlert('Loaded ' + total + ' workspace roles.', 'success');
                    })
                    .catch(function (err) {
                        renderRoleMapRows([]);
                        setRoleMapAlert((err && err.message) ? err.message : 'Failed to load role map.', 'error');
                    });
            }

            if (inspectUserOpen && inspectUserModal) {
                inspectUserOpen.addEventListener('click', function () {
                    if (inspectUserOutput) inspectUserOutput.value = '';
                    if (inspectUserEmail) inspectUserEmail.value = '';
                    openModal(inspectUserModal);
                });
            }
            if (inspectUserClose && inspectUserModal) {
                inspectUserClose.addEventListener('click', function () { closeModal(inspectUserModal); });
            }
            if (inspectUserModal) {
                inspectUserModal.addEventListener('click', function (event) {
                    if (event.target === inspectUserModal) closeModal(inspectUserModal);
                });
            }
            if (inspectUserForm) {
                inspectUserForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    const email = String((inspectUserEmail && inspectUserEmail.value) || '').trim();
                    if (!email) {
                        if (inspectUserOutput) inspectUserOutput.value = 'Enter a workspace email first.';
                        return;
                    }
                    if (inspectUserRun) inspectUserRun.disabled = true;
                    if (inspectUserOutput) inspectUserOutput.value = 'Loading...';
                    post('metis_people_workspace_inspect_user_attributes', { email: email })
                        .then(function (data) {
                            if (inspectUserOutput) {
                                inspectUserOutput.value = JSON.stringify(data || {}, null, 2);
                            }
                        })
                        .catch(function (err) {
                            if (inspectUserOutput) {
                                inspectUserOutput.value = 'Error: ' + String((err && err.message) || 'Failed to query user attributes.');
                            }
                        })
                        .finally(function () {
                            if (inspectUserRun) inspectUserRun.disabled = false;
                        });
                });
            }

            const userOpen = document.getElementById('metis-workspace-add-user-open');
            const userCancel = document.getElementById('metis-workspace-user-cancel');
            const userForm = document.getElementById('metis-workspace-user-form');
            const userTitle = document.getElementById('metis-workspace-user-modal-title');
            const userIdEl = document.getElementById('metis-workspace-user-id');
            const userPrimaryEmail = document.getElementById('metis-workspace-user-primary-email');
            const userLinkedPid = document.getElementById('metis-workspace-user-linked-pid');
            const userFirstName = document.getElementById('metis-workspace-user-first-name');
            const userLastName = document.getElementById('metis-workspace-user-last-name');
            const userDisplayName = document.getElementById('metis-workspace-user-display-name');
            const userOrgUnit = document.getElementById('metis-workspace-user-org-unit');
            const userRecoveryEmail = document.getElementById('metis-workspace-user-recovery-email');
            const userSuspended = document.getElementById('metis-workspace-user-suspended');
            const userProtected = document.getElementById('metis-workspace-user-protected');
            const roleToggles = Array.from(document.querySelectorAll('.metis-workspace-role-toggle'));

            function resetUserForm() {
                if (userTitle) userTitle.textContent = 'Add Workspace User';
                if (userIdEl) userIdEl.value = '0';
                if (userPrimaryEmail) userPrimaryEmail.value = '';
                if (userLinkedPid) userLinkedPid.value = '';
                if (userFirstName) userFirstName.value = '';
                if (userLastName) userLastName.value = '';
                if (userDisplayName) userDisplayName.value = '';
                if (userOrgUnit) userOrgUnit.value = '/';
                if (userRecoveryEmail) userRecoveryEmail.value = '';
                if (userSuspended) userSuspended.checked = false;
                if (userProtected) userProtected.checked = false;
                roleToggles.forEach(function (cb) { cb.checked = false; });
            }

            if (userOpen && userModal) {
                userOpen.addEventListener('click', function () {
                    resetUserForm();
                    openModal(userModal);
                });
            }
            if (userCancel && userModal) userCancel.addEventListener('click', function () { closeModal(userModal); });
            if (userModal) userModal.addEventListener('click', function (event) { if (event.target === userModal) closeModal(userModal); });

            document.querySelectorAll('.metis-workspace-edit-user-open').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!userModal) return;
                    if (userTitle) userTitle.textContent = 'Edit Workspace User';
                    if (userIdEl) userIdEl.value = String(btn.dataset.id || '0');
                    if (userPrimaryEmail) userPrimaryEmail.value = String(btn.dataset.primaryEmail || '');
                    if (userLinkedPid) userLinkedPid.value = String(btn.dataset.linkedPid || '');
                    if (userFirstName) userFirstName.value = String(btn.dataset.firstName || '');
                    if (userLastName) userLastName.value = String(btn.dataset.lastName || '');
                    if (userDisplayName) userDisplayName.value = String(btn.dataset.displayName || '');
                    if (userOrgUnit) userOrgUnit.value = String(btn.dataset.orgUnit || '/');
                    if (userRecoveryEmail) userRecoveryEmail.value = String(btn.dataset.recoveryEmail || '');
                    if (userSuspended) userSuspended.checked = String(btn.dataset.suspended || '0') === '1';
                    if (userProtected) userProtected.checked = String(btn.dataset.protected || '0') === '1';
                    let roles = [];
                    try { roles = JSON.parse(String(btn.dataset.roleKeys || '[]')); } catch (e) { roles = []; }
                    roleToggles.forEach(function (cb) { cb.checked = roles.indexOf(cb.value) >= 0; });
                    openModal(userModal);
                });
            });

            if (userForm) {
                userForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    const roleKeys = roleToggles.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
                    post('metis_people_workspace_save_user', {
                        workspace_user_id: userIdEl ? userIdEl.value : '0',
                        primary_email: userPrimaryEmail ? userPrimaryEmail.value : '',
                        linked_pid: userLinkedPid ? userLinkedPid.value : '',
                        first_name: userFirstName ? userFirstName.value : '',
                        last_name: userLastName ? userLastName.value : '',
                        display_name: userDisplayName ? userDisplayName.value : '',
                        org_unit_path: userOrgUnit ? userOrgUnit.value : '/',
                        recovery_email: userRecoveryEmail ? userRecoveryEmail.value : '',
                        is_suspended: userSuspended && userSuspended.checked ? '1' : '0',
                        is_protected: userProtected && userProtected.checked ? '1' : '0',
                        role_keys: JSON.stringify(roleKeys)
                    }).then(function () {
                        showAlert('Workspace user saved.', 'success');
                        closeModal(userModal);
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to save workspace user.', 'error');
                    });
                });
            }

            const groupOpen = document.getElementById('metis-workspace-add-group-open');
            const groupCancel = document.getElementById('metis-workspace-group-cancel');
            const groupForm = document.getElementById('metis-workspace-group-form');
            const groupTitle = document.getElementById('metis-workspace-group-modal-title');
            const groupIdEl = document.getElementById('metis-workspace-group-id');
            const groupNameEl = document.getElementById('metis-workspace-group-name');
            const groupEmailEl = document.getElementById('metis-workspace-group-email');
            const groupDescEl = document.getElementById('metis-workspace-group-description');
            const groupDeleteBtn = document.getElementById('metis-workspace-group-delete');
            const groupPermJoin = document.getElementById('metis-workspace-group-perm-join');
            const groupPermView = document.getElementById('metis-workspace-group-perm-view');
            const groupPermPost = document.getElementById('metis-workspace-group-perm-post');
            const groupPermExternal = document.getElementById('metis-workspace-group-perm-external');
            const externalTabButton = document.getElementById('metis-workspace-group-tab-external');
            const membersGrid = document.getElementById('metis-workspace-members-grid');
            const externalGrid = document.getElementById('metis-workspace-external-grid');
            const groupRowsWrap = document.getElementById('metis-workspace-group-rows');
            const externalEmailInput = document.getElementById('metis-workspace-external-email');
            const externalRoleSelect = document.getElementById('metis-workspace-external-role');
            const externalAddBtn = document.getElementById('metis-workspace-external-add-btn');
            function renderWorkspaceMembersGrid(users) {
                if (!membersGrid) return;
                membersGrid.innerHTML = '';
                const list = Array.isArray(users) ? users : [];
                if (!list.length) {
                    membersGrid.innerHTML = '<div class="mw-muted" style="padding:10px 12px;">No workspace users available.</div>';
                    return;
                }
                list.forEach(function (user) {
                    const row = document.createElement('div');
                    row.className = 'metis-workspace-members-grid-row';
                    const checked = user && String(user.in_group || '0') === '1';
                    const roleValue = String((user && user.member_role) || 'member');
                    const workspaceUserId = String((user && user.workspace_user_id) || '');
                    const name = String((user && user.name) || (user && user.primary_email) || 'User');
                    const email = String((user && user.primary_email) || '');
                    row.innerHTML =
                        '<div class="metis-workspace-members-include-wrap"><input type="checkbox" class="metis-workspace-members-include" data-workspace-user-id="' + workspaceUserId + '"' + (checked ? ' checked' : '') + '></div>' +
                        '<div class="metis-workspace-members-user"><strong>' + name.replace(/</g, '&lt;') + '</strong><span class="mw-muted">' + email.replace(/</g, '&lt;') + '</span></div>' +
                        '<div><select class="mw-select metis-workspace-members-role" data-workspace-user-id="' + workspaceUserId + '">' +
                            '<option value="member"' + (roleValue === 'member' ? ' selected' : '') + '>Member</option>' +
                            '<option value="manager"' + (roleValue === 'manager' ? ' selected' : '') + '>Manager</option>' +
                            '<option value="owner"' + (roleValue === 'owner' ? ' selected' : '') + '>Owner</option>' +
                        '</select></div>';
                    membersGrid.appendChild(row);
                });
            }
            function renderExternalMembersGrid(externalMembers) {
                if (!externalGrid) return;
                externalGrid.innerHTML = '';
                const externals = Array.isArray(externalMembers) ? externalMembers : [];
                if (!externals.length) {
                    externalGrid.innerHTML = '<div class="mw-muted" style="padding:10px 12px;">No external users in this group.</div>';
                    return;
                }
                externals.forEach(function (member) {
                    const row = document.createElement('div');
                    row.className = 'metis-workspace-members-grid-row';
                    const email = String((member && member.member_email) || '').trim();
                    if (!email) return;
                    const roleValue = String((member && member.member_role) || 'member');
                    const resolvedName = String((member && member.resolved_name) || '').trim();
                    const contactCid = String((member && member.contact_cid) || '').trim();
                    let subtitle = email;
                    if (resolvedName) {
                        subtitle = resolvedName + (contactCid ? ' (' + contactCid + ')' : '') + ' • ' + email;
                    }
                    row.innerHTML =
                        '<div class="metis-workspace-members-include-wrap"><input type="checkbox" class="metis-workspace-members-include" data-external-email="' + email.replace(/"/g, '&quot;') + '" checked></div>' +
                        '<div class="metis-workspace-members-user"><strong>External Member</strong><span class="mw-muted">' + subtitle.replace(/</g, '&lt;') + '</span></div>' +
                        '<div><select class="mw-select metis-workspace-members-role" data-external-email="' + email.replace(/"/g, '&quot;') + '">' +
                            '<option value="member"' + (roleValue === 'member' ? ' selected' : '') + '>Member</option>' +
                            '<option value="manager"' + (roleValue === 'manager' ? ' selected' : '') + '>Manager</option>' +
                            '<option value="owner"' + (roleValue === 'owner' ? ' selected' : '') + '>Owner</option>' +
                        '</select></div>';
                    externalGrid.appendChild(row);
                });
            }
            function setGroupPermissions(permissions) {
                const p = permissions || {};
                if (groupPermJoin) groupPermJoin.value = String(p.whoCanJoin || 'INVITED_CAN_JOIN');
                if (groupPermView) groupPermView.value = String(p.whoCanViewMembership || 'ALL_MEMBERS_CAN_VIEW');
                if (groupPermPost) groupPermPost.value = String(p.whoCanPostMessage || 'ALL_MEMBERS_CAN_POST');
                if (groupPermExternal) groupPermExternal.checked = String(p.allowExternalMembers || 'false') === 'true';
            }
            function resetGroupForm() {
                if (groupTitle) groupTitle.textContent = 'Workspace Group Editor';
                if (groupIdEl) groupIdEl.value = '0';
                if (groupNameEl) groupNameEl.value = '';
                if (groupEmailEl) groupEmailEl.value = '';
                if (groupDescEl) groupDescEl.value = '';
                if (groupEmailEl) groupEmailEl.readOnly = false;
                if (groupDeleteBtn) groupDeleteBtn.style.display = 'none';
                setGroupPermissions({});
                renderWorkspaceMembersGrid([]);
                renderExternalMembersGrid([]);
                if (externalTabButton) externalTabButton.textContent = 'External Users';
                const tabButtons = Array.from((groupForm || document).querySelectorAll('.metis-tab-btn'));
                const tabPanels = Array.from((groupForm || document).querySelectorAll('.metis-tab-panel'));
                tabButtons.forEach(function (btn) { btn.classList.toggle('is-active', String(btn.dataset.tabTarget || '') === 'group-general'); });
                tabPanels.forEach(function (panel) { panel.classList.toggle('is-active', String(panel.dataset.tabPanel || '') === 'group-general'); });
            }
            function openGroupEditor(groupId) {
                if (!groupModal || !groupForm) return;
                resetGroupForm();
                if (!groupId || groupId < 1) {
                    openModal(groupModal);
                    return;
                }
                if (groupIdEl) groupIdEl.value = String(groupId);
                if (groupTitle) groupTitle.textContent = 'Workspace Group Editor';
                if (groupDeleteBtn) groupDeleteBtn.style.display = '';
                openModal(groupModal);
                post('metis_people_workspace_get_group_members_matrix', { group_id: String(groupId) }).then(function (data) {
                    const group = data && data.group ? data.group : {};
                    if (groupNameEl) groupNameEl.value = String(group.group_name || '');
                    if (groupEmailEl) groupEmailEl.value = String(group.group_email || '');
                    if (groupDescEl) groupDescEl.value = String(group.description || '');
                    if (groupEmailEl) groupEmailEl.readOnly = true;
                    const externals = (data && data.external_members) || [];
                    renderWorkspaceMembersGrid((data && data.users) || []);
                    renderExternalMembersGrid(externals);
                    if (externalTabButton) {
                        externalTabButton.textContent = 'External Users' + (externals.length ? ' (' + externals.length + ')' : '');
                    }
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to load group members.', 'error');
                });
                post('metis_people_workspace_get_group_permissions', { group_id: String(groupId) }).then(function (data) {
                    setGroupPermissions((data && data.permissions) || {});
                }).catch(function () {});
            }
            Array.from((groupForm || document).querySelectorAll('.metis-tab-btn')).forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const target = String(btn.dataset.tabTarget || '').trim();
                    if (!target) return;
                    Array.from((groupForm || document).querySelectorAll('.metis-tab-btn')).forEach(function (b) {
                        b.classList.toggle('is-active', String(b.dataset.tabTarget || '') === target);
                    });
                    Array.from((groupForm || document).querySelectorAll('.metis-tab-panel')).forEach(function (panel) {
                        panel.classList.toggle('is-active', String(panel.dataset.tabPanel || '') === target);
                    });
                });
            });
            if (groupOpen && groupModal) groupOpen.addEventListener('click', function () { openGroupEditor(0); });
            if (groupCancel && groupModal) groupCancel.addEventListener('click', function () { closeModal(groupModal); });
            if (groupModal) groupModal.addEventListener('click', function (event) { if (event.target === groupModal) closeModal(groupModal); });
            if (groupRowsWrap) {
                groupRowsWrap.addEventListener('click', function (event) {
                    const row = event.target.closest('.metis-workspace-group-row');
                    if (!row) return;
                    const groupId = parseInt(String(row.dataset.groupId || '0'), 10);
                    if (!groupId) return;
                    openGroupEditor(groupId);
                });
            }
            if (groupForm) {
                groupForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    const selectedMembers = [];
                    const checkboxes = Array.from((groupForm || document).querySelectorAll('.metis-workspace-members-include'));
                    checkboxes.forEach(function (cb) {
                        if (!cb.checked) return;
                        const workspaceUserId = parseInt(String(cb.dataset.workspaceUserId || '0'), 10);
                        const externalEmail = String(cb.dataset.externalEmail || '').trim().toLowerCase();
                        if (!workspaceUserId && !externalEmail) return;
                        let roleSelect = null;
                        if (workspaceUserId) {
                            roleSelect = (membersGrid || document).querySelector('.metis-workspace-members-role[data-workspace-user-id="' + workspaceUserId + '"]');
                        } else {
                            roleSelect = (membersGrid || document).querySelector('.metis-workspace-members-role[data-external-email="' + externalEmail + '"]');
                        }
                        const role = roleSelect ? String(roleSelect.value || 'member') : 'member';
                        if (workspaceUserId) {
                            selectedMembers.push({ workspace_user_id: workspaceUserId, member_role: role });
                        } else {
                            selectedMembers.push({ member_email: externalEmail, member_role: role });
                        }
                    });
                    const permissions = {
                        whoCanJoin: groupPermJoin ? groupPermJoin.value : 'INVITED_CAN_JOIN',
                        whoCanViewMembership: groupPermView ? groupPermView.value : 'ALL_MEMBERS_CAN_VIEW',
                        whoCanPostMessage: groupPermPost ? groupPermPost.value : 'ALL_MEMBERS_CAN_POST',
                        allowExternalMembers: groupPermExternal && groupPermExternal.checked ? 'true' : 'false'
                    };
                    post('metis_people_workspace_save_group', {
                        group_id: groupIdEl ? groupIdEl.value : '0',
                        group_name: groupNameEl ? groupNameEl.value : '',
                        group_email: groupEmailEl ? groupEmailEl.value : '',
                        description: groupDescEl ? groupDescEl.value : ''
                    }).then(function () {
                        return post('metis_people_workspace_save_group_members_bulk', {
                            group_id: groupIdEl ? groupIdEl.value : '0',
                            members: JSON.stringify(selectedMembers)
                        });
                    }).then(function () {
                        return post('metis_people_workspace_save_group_permissions', {
                            group_id: groupIdEl ? groupIdEl.value : '0',
                            permissions: JSON.stringify(permissions)
                        });
                    }).then(function () {
                        showAlert('Workspace group saved.', 'success');
                        closeModal(groupModal);
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to save group.', 'error');
                    });
                });
            }
            if (externalAddBtn) {
                externalAddBtn.addEventListener('click', function () {
                    const email = String(externalEmailInput ? externalEmailInput.value : '').trim().toLowerCase();
                    if (!email || email.indexOf('@') < 1) {
                        showAlert('Enter a valid external email.', 'error');
                        return;
                    }
                    const existing = Array.from((membersGrid || document).querySelectorAll('.metis-workspace-members-include[data-external-email], .metis-workspace-members-include[data-workspace-user-id]'))
                        .some(function (el) {
                            const external = String(el.dataset.externalEmail || '').trim().toLowerCase();
                            if (external) return external === email;
                            const userId = String(el.dataset.workspaceUserId || '').trim();
                            if (!userId) return false;
                            const emailNode = el.closest('.metis-workspace-members-grid-row');
                            return !!(emailNode && String((emailNode.querySelector('.metis-workspace-members-user .mw-muted') || {}).textContent || '').trim().toLowerCase() === email);
                        });
                    if (existing) {
                        showAlert('That email is already listed.', 'error');
                        return;
                    }
                    const externalRows = Array.from((externalGrid || document).querySelectorAll('.metis-workspace-members-include[data-external-email]')).map(function (cb) {
                        const row = cb.closest('.metis-workspace-members-grid-row');
                        const roleSelect = row ? row.querySelector('.metis-workspace-members-role[data-external-email]') : null;
                        return {
                            member_email: String(cb.dataset.externalEmail || '').trim().toLowerCase(),
                            member_role: roleSelect ? String(roleSelect.value || 'member') : 'member'
                        };
                    }).concat([{ member_email: email, member_role: String(externalRoleSelect ? externalRoleSelect.value : 'member') }]);
                    renderExternalMembersGrid(externalRows);
                    if (externalTabButton) {
                        externalTabButton.textContent = 'External Users' + (externalRows.length ? ' (' + externalRows.length + ')' : '');
                    }
                    if (externalEmailInput) externalEmailInput.value = '';
                });
            }
            if (groupDeleteBtn) {
                groupDeleteBtn.addEventListener('click', function () {
                    const gid = groupIdEl ? parseInt(String(groupIdEl.value || '0'), 10) : 0;
                    if (!gid) return;
                    if (!window.confirm('Delete this workspace group? This cannot be undone.')) return;
                    post('metis_people_workspace_delete_group', { group_id: String(gid) })
                        .then(function () {
                            showAlert('Workspace group deleted.', 'success');
                            closeModal(groupModal);
                        })
                        .catch(function (err) {
                            showAlert(err.message || 'Failed to delete group.', 'error');
                        });
                });
            }

            const securityForm = document.getElementById('metis-workspace-security-form');
            const securityCancel = document.getElementById('metis-workspace-security-cancel');
            const securityUserId = document.getElementById('metis-workspace-security-user-id');
            const securityUserEmail = document.getElementById('metis-workspace-security-user-email');
            const securityAction = document.getElementById('metis-workspace-security-action');
            const securityReason = document.getElementById('metis-workspace-security-reason');
            document.querySelectorAll('.metis-workspace-security-open').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!securityModal) return;
                    if (securityUserId) securityUserId.value = String(btn.dataset.userId || '0');
                    if (securityUserEmail) securityUserEmail.value = String(btn.dataset.userEmail || '');
                    if (securityAction) securityAction.value = 'reset_password';
                    if (securityReason) securityReason.value = '';
                    openModal(securityModal);
                });
            });
            if (securityCancel && securityModal) securityCancel.addEventListener('click', function () { closeModal(securityModal); });
            if (securityModal) securityModal.addEventListener('click', function (event) { if (event.target === securityModal) closeModal(securityModal); });
            if (securityForm) {
                securityForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    post('metis_people_workspace_run_security_action', {
                        workspace_user_id: securityUserId ? securityUserId.value : '0',
                        action_type: securityAction ? securityAction.value : '',
                        reason: securityReason ? securityReason.value : ''
                    }).then(function () {
                        closeModal(securityModal);
                        showAlert('Security action queued.', 'success');
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to queue security action.', 'error');
                    });
                });
            }

            if (roleMapOpen && roleMapModal) {
                roleMapOpen.addEventListener('click', function () {
                    openModal(roleMapModal);
                    loadWorkspaceRoleMap();
                });
            }
            if (roleMapClose && roleMapModal) {
                roleMapClose.addEventListener('click', function () { closeModal(roleMapModal); });
            }
            if (roleMapRefresh) {
                roleMapRefresh.addEventListener('click', function () { loadWorkspaceRoleMap(); });
            }
            if (roleMapModal) {
                roleMapModal.addEventListener('click', function (event) {
                    if (event.target === roleMapModal) closeModal(roleMapModal);
                });
            }

            if (importUsersButton) {
                importUsersButton.addEventListener('click', function () {
                    importUsersButton.disabled = true;
                    post('metis_people_workspace_import_directory_users', {
                        limit: '500'
                    }).then(function (data) {
                        const imported = parseInt((data && data.imported) || 0, 10);
                        const created = parseInt((data && data.created) || 0, 10);
                        const updated = parseInt((data && data.updated) || 0, 10);
                        const linked = parseInt((data && data.linked) || 0, 10);
                        const rolesSynced = parseInt((data && data.roles_synced) || 0, 10);
                        const roleSyncError = String((data && data.role_sync_error) || '').trim();
                        let message = 'Imported ' + imported + ' users (' + created + ' created, ' + updated + ' updated, ' + linked + ' linked, ' + rolesSynced + ' roles synced).';
                        if (roleSyncError) {
                            message += ' Role sync warning: ' + roleSyncError;
                        }
                        showAlert(message, roleSyncError ? 'error' : 'success');
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to import existing Workspace users.', 'error');
                    }).finally(function () {
                        importUsersButton.disabled = false;
                    });
                });
            }
            if (fullSyncButton) {
                fullSyncButton.addEventListener('click', function () {
                    fullSyncButton.disabled = true;
                    post('metis_people_workspace_full_sync_directory', {
                        user_limit: '800',
                        group_limit: '400'
                    }).then(function (data) {
                        const imported = parseInt((data && data.imported) || 0, 10);
                        const rolesSynced = parseInt((data && data.roles_synced) || 0, 10);
                        const groupsImported = parseInt((data && data.groups_imported) || 0, 10);
                        const groupsRemoved = parseInt((data && data.groups_removed) || 0, 10);
                        const membersSynced = parseInt((data && data.group_members_synced) || 0, 10);
                        const roleSyncError = String((data && data.role_sync_error) || '').trim();
                        const groupSyncError = String((data && data.group_sync_error) || '').trim();
                        let message = 'Full sync complete: ' + imported + ' users, ' + rolesSynced + ' role assignments, ' + groupsImported + ' groups, ' + groupsRemoved + ' groups removed, ' + membersSynced + ' group members.';
                        if (roleSyncError || groupSyncError) {
                            message += ' Warnings: ' + [roleSyncError, groupSyncError].filter(Boolean).join(' | ');
                        }
                        showAlert(message, (roleSyncError || groupSyncError) ? 'error' : 'success');
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to run full sync.', 'error');
                    }).finally(function () {
                        fullSyncButton.disabled = false;
                    });
                });
            }
            if (runSyncButton) {
                runSyncButton.addEventListener('click', function () {
                    runSyncButton.disabled = true;
                    post('metis_people_workspace_process_queue', {
                        limit: '15',
                        dry_run: '0',
                        run_all: '1'
                    }).then(function (data) {
                        const processed = parseInt((data && data.processed) || 0, 10);
                        const completed = parseInt((data && data.completed) || 0, 10);
                        const failed = parseInt((data && data.failed) || 0, 10);
                        const remaining = parseInt((data && data.remaining_queued) || 0, 10);
                        const messages = Array.isArray(data && data.messages) ? data.messages.filter(Boolean) : [];
                        let message = 'Sync run finished (' + processed + ' processed, ' + completed + ' completed, ' + failed + ' failed, ' + remaining + ' queued).';
                        if (failed > 0 && messages.length > 0) {
                            message += ' Last error: ' + String(messages[messages.length - 1]);
                        }
                        showAlert(message, failed > 0 ? 'warning' : 'success');
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to process sync queue.', 'error');
                    }).finally(function () {
                        runSyncButton.disabled = false;
                    });
                });
            }
        }
    }

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
                driveFolderNameEl.classList.remove('mw-muted');
                driveFolderNameEl.classList.add('mw-chip');
            }

            let openLink = document.getElementById('metis-people-drive-folder-open');
            if (folderUrl) {
                if (!openLink && driveFolderWrap) {
                    openLink = document.createElement('a');
                    openLink.id = 'metis-people-drive-folder-open';
                    openLink.className = 'mw-btn-xs';
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
                driveFolderPickerList.innerHTML = '<div class="mw-muted">No folders found here.</div>';
                return;
            }

            driveFolderPickerList.innerHTML = folders.map(function (folder) {
                const id = String((folder && folder.id) || '');
                const name = Metis.util.escapeHtml(String((folder && folder.name) || 'Folder'));
                return '' +
                    '<div class="metis-people-drive-picker-row">' +
                        '<div class="metis-people-drive-picker-main">' +
                            '<button type="button" class="metis-people-drive-picker-open" data-folder-open="' + id + '">' + name + '</button>' +
                            '<div class="mw-muted">Open folder contents or attach it to this person.</div>' +
                        '</div>' +
                        '<div class="metis-people-drive-picker-actions">' +
                            '<button type="button" class="mw-btn mw-btn-ghost" data-folder-open="' + id + '">Open</button>' +
                            '<button type="button" class="mw-btn" data-folder-choose="' + id + '" data-folder-name="' + name + '">Select</button>' +
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

        const form = document.getElementById('metis-people-detail-form');
        const saveAccessButton = document.getElementById('metis-people-save-access');
        const saveWorkspaceButton = document.getElementById('metis-people-save-workspace');
        const saveSecurityButton = document.getElementById('metis-people-save-security');
        const saveNotificationsButton = document.getElementById('metis-people-save-notifications');
        if (canManage && form && saveAccessButton) {
            saveAccessButton.addEventListener('click', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                }
            });
        }
        if (canManage && form && saveSecurityButton) {
            saveSecurityButton.addEventListener('click', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                }
            });
        }
        if (canManage && form && saveWorkspaceButton) {
            saveWorkspaceButton.addEventListener('click', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                }
            });
        }
        if (canManage && form && saveNotificationsButton) {
            saveNotificationsButton.addEventListener('click', function () {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                }
            });
        }
        if (canManage && form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                const personId = document.getElementById('metis-people-id');
                const personFirstName = document.getElementById('metis-people-first-name');
                const personLastName = document.getElementById('metis-people-last-name');
                const personName = document.getElementById('metis-people-name');
                const personEmail = document.getElementById('metis-people-email');
                const personProvider = document.getElementById('metis-people-provider');
                const personWorkspaceUser = document.getElementById('metis-people-workspace-user-tab') || document.getElementById('metis-people-workspace-user');
                const personWorkspaceEmail = document.getElementById('metis-people-workspace-email-tab') || document.getElementById('metis-people-workspace-email');
                const personWorkspaceRole = document.getElementById('metis-people-workspace-role-tab') || document.getElementById('metis-people-workspace-role');
                const personWorkspaceProtected = document.getElementById('metis-people-workspace-protected-tab');
                const personWorkspaceGroups = document.getElementById('metis-people-workspace-groups-json');
                const personStripeRole = document.getElementById('metis-people-stripe-role-tab') || document.getElementById('metis-people-stripe-role');
                const personStatus = document.getElementById('metis-people-status');
                const personLifecycleStatus = document.getElementById('metis-people-lifecycle-status');
                const personManagerPid = document.getElementById('metis-people-manager-pid');
                const personDepartment = document.getElementById('metis-people-department');
                const personBoardTermStart = document.getElementById('metis-people-board-term-start');
                const personBoardTermEnd = document.getElementById('metis-people-board-term-end');
                const personVolunteerArea = document.getElementById('metis-people-volunteer-area');
                const personEmailNotifications = document.getElementById('metis-people-email-notifications');
                const personRequires2fa = document.getElementById('metis-people-requires-2fa');
                const personMfaMethod = document.getElementById('metis-people-mfa-method');
                const personLinkedDonorId = document.getElementById('metis-people-linked-donor-id');
                const personStaff = document.getElementById('metis-people-staff');
                const personBoard = document.getElementById('metis-people-board');
                const personVolunteer = document.getElementById('metis-people-volunteer');
                const roles = Array.from(document.querySelectorAll('.metis-role-toggle:checked')).map(function (cb) { return cb.value; });
                const roleWindows = collectRoleWindows();
                const notificationPrefs = collectNotificationPrefs();
                const workspaceEnabled = !!(personWorkspaceUser && personWorkspaceUser.checked);
                const effectiveWorkspaceEmail = String((personWorkspaceEmail && personWorkspaceEmail.value) || '').trim();

                post('metis_people_save_person', {
                    person_id: personId ? personId.value : '0',
                    pid: currentPersonPid(personPidInput),
                    first_name: personFirstName ? personFirstName.value : '',
                    last_name: personLastName ? personLastName.value : '',
                    display_name: personName ? personName.value : '',
                    email: personEmail ? personEmail.value : '',
                    auth_provider: personProvider ? personProvider.value : 'metis',
                    is_workspace_user: personWorkspaceUser && personWorkspaceUser.checked ? '1' : '0',
                    workspace_email: effectiveWorkspaceEmail,
                    workspace_role: personWorkspaceRole ? personWorkspaceRole.value : '',
                    workspace_is_protected: personWorkspaceProtected && personWorkspaceProtected.checked ? '1' : '0',
                    workspace_groups_json: personWorkspaceGroups ? personWorkspaceGroups.value : '[]',
                    stripe_role: personStripeRole ? personStripeRole.value : '',
                    linked_donor_id: personLinkedDonorId ? personLinkedDonorId.value : '',
                    manager_pid: personManagerPid ? personManagerPid.value : '',
                    department: personDepartment ? personDepartment.value : '',
                    board_term_start: personBoardTermStart ? personBoardTermStart.value : '',
                    board_term_end: personBoardTermEnd ? personBoardTermEnd.value : '',
                    volunteer_area: personVolunteerArea ? personVolunteerArea.value : '',
                    lifecycle_status: personLifecycleStatus ? personLifecycleStatus.value : 'active',
                    email_notifications: personEmailNotifications && personEmailNotifications.checked ? '1' : '0',
                    requires_2fa: personRequires2fa && personRequires2fa.checked ? '1' : '0',
                    mfa_method: personMfaMethod ? personMfaMethod.value : 'none',
                    notification_prefs_json: JSON.stringify(notificationPrefs),
                    status: personStatus ? personStatus.value : 'active',
                    is_staff: personStaff && personStaff.checked ? '1' : '0',
                    is_board: personBoard && personBoard.checked ? '1' : '0',
                    is_volunteer: personVolunteer && personVolunteer.checked ? '1' : '0',
                    roles: JSON.stringify(roles),
                    role_windows: JSON.stringify(roleWindows)
                })
                    .then(function (data) {
                        showAlert('Person saved.', 'success');
                        const url = new URL(window.location.href);
                        const savedPersonId = String((data && data.person_id) || '').trim();
                        const savedPid = String((data && data.pid) || '').trim();
                        if (savedPersonId && personId) {
                            personId.value = savedPersonId;
                        }
                        if (savedPid) {
                            url.searchParams.delete('new');
                            url.searchParams.set('pid', savedPid);
                            window.history.replaceState({}, '', url.toString());
                            if (personPidInput) personPidInput.value = savedPid;
                            if (offboardPidEl) offboardPidEl.dataset.pid = savedPid;
                            const sub = document.querySelector('.metis-people-detail-header .mw-subtitle');
                            if (sub) sub.textContent = 'PID: ' + savedPid;
                        }
                        syncPersonHeaderFromForm();
                    })
                    .catch(function (err) {
                    showAlert(err.message || 'Failed to save person.', 'error');
                });
            });
        }

        const offboardPidEl = document.getElementById('metis-people-offboard-confirm');
        const activePersonIdEl = document.getElementById('metis-people-id');
        const activePersonId = String((activePersonIdEl && activePersonIdEl.value) || '0').trim();

        const avatarOpen = document.getElementById('metis-people-avatar-edit-open');
        const avatarModal = document.getElementById('metis-people-avatar-modal');
        const avatarCancel = document.getElementById('metis-people-avatar-cancel');
        const avatarSave = document.getElementById('metis-people-avatar-save');
        const avatarFile = document.getElementById('metis-people-avatar-file');
        const avatarCanvas = document.getElementById('metis-people-avatar-canvas');
        const avatarZoom = document.getElementById('metis-people-avatar-zoom');
        const avatarOffsetX = document.getElementById('metis-people-avatar-offset-x');
        const avatarOffsetY = document.getElementById('metis-people-avatar-offset-y');
        let avatarImage = null;
        let avatarImageLoaded = false;
        const avatarCtx = avatarCanvas ? avatarCanvas.getContext('2d') : null;

        function drawAvatarCanvas() {
            if (!avatarCtx || !avatarCanvas) return;
            const width = avatarCanvas.width;
            const height = avatarCanvas.height;
            avatarCtx.clearRect(0, 0, width, height);
            avatarCtx.fillStyle = '#f3f5fb';
            avatarCtx.fillRect(0, 0, width, height);
            if (!avatarImageLoaded || !avatarImage) return;
            const zoom = parseFloat(avatarZoom ? avatarZoom.value : '1') || 1;
            const dx = parseInt(avatarOffsetX ? avatarOffsetX.value : '0', 10) || 0;
            const dy = parseInt(avatarOffsetY ? avatarOffsetY.value : '0', 10) || 0;
            const srcW = avatarImage.width;
            const srcH = avatarImage.height;
            const fit = Math.max(width / srcW, height / srcH);
            const drawW = srcW * fit * zoom;
            const drawH = srcH * fit * zoom;
            const x = (width - drawW) / 2 + dx;
            const y = (height - drawH) / 2 + dy;
            avatarCtx.drawImage(avatarImage, x, y, drawW, drawH);
        }

        if (avatarOpen && avatarModal) {
            avatarOpen.addEventListener('click', function () { openModal(avatarModal); drawAvatarCanvas(); });
            if (avatarCancel) avatarCancel.addEventListener('click', function () { closeModal(avatarModal); });
            avatarModal.addEventListener('click', function (event) {
                if (event.target === avatarModal) closeModal(avatarModal);
            });
            [avatarZoom, avatarOffsetX, avatarOffsetY].forEach(function (el) {
                if (!el) return;
                el.addEventListener('input', drawAvatarCanvas);
            });
            if (avatarFile) {
                avatarFile.addEventListener('change', function () {
                    const file = avatarFile.files && avatarFile.files[0] ? avatarFile.files[0] : null;
                    if (!file) return;
                    const reader = new FileReader();
                    reader.onload = function () {
                        const img = new Image();
                        img.onload = function () {
                            avatarImage = img;
                            avatarImageLoaded = true;
                            if (avatarZoom) avatarZoom.value = '1';
                            if (avatarOffsetX) avatarOffsetX.value = '0';
                            if (avatarOffsetY) avatarOffsetY.value = '0';
                            drawAvatarCanvas();
                        };
                        img.src = String(reader.result || '');
                    };
                    reader.readAsDataURL(file);
                });
            }
            if (avatarSave) {
                avatarSave.addEventListener('click', function () {
                    if (!avatarCanvas || !avatarImageLoaded || !activePersonId) {
                        showAlert('Select and crop an image first.', 'error');
                        return;
                    }
                    const base64 = avatarCanvas.toDataURL('image/png');
                    post('metis_people_save_avatar', {
                        person_id: activePersonId,
                        avatar_base64: base64
                    }).then(function (data) {
                        closeModal(avatarModal);
                        showAlert('Profile photo updated.', 'success');
                        const src = String((data && data.avatar_url) || '').trim();
                        if (src) {
                            document.querySelectorAll('.metis-people-profile-avatar').forEach(function (img) {
                                img.src = src;
                            });
                        }
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to save profile photo.', 'error');
                    });
                });
            }
        }

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
        let pendingTotpSecret = '';
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
                const confirmed = window.confirm('Reset MFA for ' + personLabel + '? This will clear the authenticator app secret and revoke all passkeys.');
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
        }

        const passkeyRegisterBtn = document.getElementById('metis-people-passkey-register-open');
        function setPasskeyConfiguredUI() {
            const rowWrap = passkeyRegisterBtn ? passkeyRegisterBtn.closest('.metis-people-security-row') : null;
            const chip = rowWrap ? rowWrap.querySelector('.mw-chip') : null;
            if (chip) {
                chip.textContent = 'Configured';
                chip.classList.add('mw-chip-success');
            }
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
                                const revokeBtn = item.querySelector('.metis-passkey-revoke');
                                if (revokeBtn) {
                                    revokeBtn.addEventListener('click', function () {
                                        const passkeyId = String(revokeBtn.dataset.id || '').trim();
                                        if (!passkeyId) return;
                                        post('metis_people_revoke_passkey', { passkey_id: passkeyId })
                                            .then(function (resp) {
                                                item.remove();
                                                if (resp && parseInt(resp.active_count || '0', 10) < 1) {
                                                    const rowWrap = passkeyRegisterBtn ? passkeyRegisterBtn.closest('.metis-people-security-row') : null;
                                                    const chip = rowWrap ? rowWrap.querySelector('.mw-chip') : null;
                                                    if (chip) {
                                                        chip.textContent = 'Not configured';
                                                        chip.classList.remove('mw-chip-success');
                                                    }
                                                }
                                                showAlert('Passkey revoked.', 'success');
                                            })
                                            .catch(function (err) {
                                                showAlert(err.message || 'Failed to revoke passkey.', 'error');
                                            });
                                    });
                                }
                            }
                        }
                        showAlert('Passkey registered.', 'success');
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to register passkey.', 'error');
                    });
            });
        }

        document.querySelectorAll('.metis-passkey-revoke').forEach(function (btn) {
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
                            const rowWrap = passkeyRegisterBtn ? passkeyRegisterBtn.closest('.metis-people-security-row') : null;
                            const chip = rowWrap ? rowWrap.querySelector('.mw-chip') : null;
                            if (chip) {
                                chip.textContent = 'Not configured';
                                chip.classList.remove('mw-chip-success');
                            }
                        }
                        showAlert('Passkey revoked.', 'success');
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to revoke passkey.', 'error');
                    });
            });
        });

        document.querySelectorAll('.metis-role-window-preset').forEach(function (preset) {
            preset.addEventListener('change', function () {
                const roleKey = String(preset.dataset.roleKey || '').trim();
                if (!roleKey) return;
                const startInput = document.querySelector('.metis-role-start[data-role-key="' + roleKey + '"]');
                const endInput = document.querySelector('.metis-role-end[data-role-key="' + roleKey + '"]');
                if (!startInput || !endInput) return;
                const mode = String(preset.value || '');
                if (mode === 'always') {
                    startInput.value = '';
                    endInput.value = '';
                    return;
                }
                if (mode === '30d' || mode === '90d') {
                    const days = mode === '30d' ? 30 : 90;
                    const start = nowLocalDateTimeValue();
                    startInput.value = start;
                    endInput.value = addDaysLocalDateTimeValue(start, days);
                }
            });
        });

        const offboardButton = document.getElementById('metis-people-offboard-btn');
        const offboardModal = document.getElementById('metis-people-offboard-modal');
        const offboardCancel = document.getElementById('metis-people-offboard-cancel');
        const offboardConfirm = document.getElementById('metis-people-offboard-confirm');
        if (canManage && offboardButton && offboardModal) {
            offboardButton.addEventListener('click', function () { openModal(offboardModal); });
            if (offboardCancel) offboardCancel.addEventListener('click', function () { closeModal(offboardModal); });
            offboardModal.addEventListener('click', function (event) {
                if (event.target === offboardModal) closeModal(offboardModal);
            });
            if (offboardConfirm) {
                offboardConfirm.addEventListener('click', function () {
                    const pid = String(offboardConfirm.dataset.pid || '').trim();
                    if (!pid) return;
                    post('metis_people_offboard_person', { pid: pid })
                        .then(function () {
                            closeModal(offboardModal);
                            showAlert('Offboarding completed.', 'success');
                            const status = document.getElementById('metis-people-status');
                            const lifecycle = document.getElementById('metis-people-lifecycle-status');
                            const workspaceUser = document.getElementById('metis-people-workspace-user-tab') || document.getElementById('metis-people-workspace-user');
                            const workspaceEmail = document.getElementById('metis-people-workspace-email-tab') || document.getElementById('metis-people-workspace-email');
                            const workspaceRole = document.getElementById('metis-people-workspace-role-tab') || document.getElementById('metis-people-workspace-role');
                            const stripeRole = document.getElementById('metis-people-stripe-role-tab') || document.getElementById('metis-people-stripe-role');
                            if (status) status.value = 'inactive';
                            if (lifecycle) lifecycle.value = 'alumni';
                            if (workspaceUser) workspaceUser.checked = false;
                            if (workspaceEmail) workspaceEmail.value = '';
                            if (workspaceRole) workspaceRole.value = '';
                            if (stripeRole) stripeRole.value = '';
                        })
                        .catch(function (err) {
                            showAlert(err.message || 'Offboarding failed.', 'error');
                        });
                });
            }
        }

        const docForm = document.getElementById('metis-people-doc-form');
        if (canManage && docForm) {
            docForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const pidEl = document.getElementById('metis-people-offboard-confirm');
                const pid = String((pidEl && pidEl.dataset.pid) || '').trim();
                post('metis_people_add_document', {
                    pid: pid,
                    doc_type: (document.getElementById('metis-people-doc-type') || {}).value || '',
                    doc_label: (document.getElementById('metis-people-doc-label') || {}).value || '',
                    storage_ref: (document.getElementById('metis-people-doc-ref') || {}).value || '',
                    remind_at: (document.getElementById('metis-people-doc-remind') || {}).value || '',
                    expires_at: (document.getElementById('metis-people-doc-expires') || {}).value || ''
                }).then(function (data) {
                    showAlert('Document added.', 'success');
                    docForm.reset();
                    const list = document.querySelector('[data-tab-panel="documents"] .metis-people-mini-list');
                    const row = data && data.row ? data.row : null;
                    if (!list || !row) return;
                    const empty = list.querySelector('.mw-muted');
                    if (empty) empty.remove();
                    const item = document.createElement('div');
                    item.className = 'metis-people-mini-item';
                    item.innerHTML =
                        '<div><strong>' + String(row.doc_label || '') + '</strong> (' + String(row.doc_type || '') + ')</div>' +
                        '<div class="mw-muted">' +
                            String(row.storage_ref || '') +
                            (row.expires_at ? (' | Expires: ' + row.expires_at) : '') +
                            (row.remind_at ? (' | Remind: ' + row.remind_at) : '') +
                            (row.lifecycle_status ? (' | Status: ' + row.lifecycle_status) : '') +
                        '</div>' +
                        '<div class="metis-people-mini-actions"><button type="button" class="mw-btn-xs mw-btn-danger metis-doc-delete">Delete</button></div>';
                    const deleteBtn = item.querySelector('.metis-doc-delete');
                    if (deleteBtn && data && data.doc_id) deleteBtn.dataset.id = String(data.doc_id);
                    if (deleteBtn && !deleteBtn.dataset.id) deleteBtn.remove();
                    if (deleteBtn) {
                        deleteBtn.addEventListener('click', function () {
                            const id = String(deleteBtn.dataset.id || '').trim();
                            if (!id) return;
                            post('metis_people_delete_document', { doc_id: id }).then(function () {
                                item.remove();
                                showAlert('Document deleted.', 'success');
                            }).catch(function (err) {
                                showAlert(err.message || 'Failed to delete document.', 'error');
                            });
                        });
                    }
                    list.prepend(item);
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to add document.', 'error');
                });
            });
        }
        document.querySelectorAll('.metis-doc-delete').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const docId = String(btn.dataset.id || '').trim();
                if (!docId) return;
                post('metis_people_delete_document', { doc_id: docId })
                    .then(function () {
                        showAlert('Document deleted.', 'success');
                        const row = btn.closest('.metis-people-mini-item');
                        if (row) row.remove();
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to delete document.', 'error');
                    });
            });
        });

        const emergencyForm = document.getElementById('metis-people-emergency-form');
        if (canManage && emergencyForm) {
            emergencyForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const pidEl = document.getElementById('metis-people-offboard-confirm');
                const pid = String((pidEl && pidEl.dataset.pid) || '').trim();
                post('metis_people_grant_emergency_access', {
                    pid: pid,
                    role_key: (document.getElementById('metis-people-emergency-role') || {}).value || '',
                    hours: (document.getElementById('metis-people-emergency-hours') || {}).value || '4',
                    reason: (document.getElementById('metis-people-emergency-reason') || {}).value || ''
                }).then(function () {
                    showAlert('Emergency access granted.', 'success');
                    emergencyForm.reset();
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to grant emergency access.', 'error');
                });
            });
        }
        document.querySelectorAll('.metis-emergency-revoke').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const entryId = String(btn.dataset.id || '').trim();
                if (!entryId) return;
                post('metis_people_revoke_emergency_access', { entry_id: entryId })
                    .then(function () {
                        showAlert('Emergency access revoked.', 'success');
                        btn.disabled = true;
                        btn.textContent = 'Revoked';
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to revoke emergency access.', 'error');
                    });
            });
        });

        const simRunBtn = document.getElementById('metis-people-sim-run');
        if (simRunBtn) {
            simRunBtn.addEventListener('click', function () {
                const moduleEl = document.getElementById('metis-people-sim-module');
                const actionEl = document.getElementById('metis-people-sim-action');
                const resultEl = document.getElementById('metis-people-sim-result');
                const pidEl = document.getElementById('metis-people-offboard-confirm');
                const pid = String((pidEl && pidEl.dataset.pid) || '').trim();
                post('metis_people_simulate_permission', {
                    pid: pid,
                    module: moduleEl ? moduleEl.value : '',
                    action: actionEl ? actionEl.value : ''
                }).then(function (data) {
                    if (!resultEl) return;
                    const allowed = !!(data && data.allowed);
                    const roles = Array.isArray(data && data.source_roles) ? data.source_roles : [];
                    const roleNames = roles.map(function (r) { return String(r.role_name || r.role_key || ''); }).filter(Boolean);
                    resultEl.textContent = allowed
                        ? ('Allowed via: ' + (roleNames.length ? roleNames.join(', ') : 'assigned role'))
                        : 'Denied (no active role grants this permission).';
                    resultEl.style.color = allowed ? '#0f7a3d' : '#b42318';
                }).catch(function (err) {
                    showAlert(err.message || 'Simulation failed.', 'error');
                });
            });
        }

        const taskForm = document.getElementById('metis-people-task-form');
        if (canManage && taskForm) {
            taskForm.addEventListener('submit', function (event) {
                event.preventDefault();
                const pidEl = document.getElementById('metis-people-offboard-confirm');
                const pid = String((pidEl && pidEl.dataset.pid) || '').trim();
                post('metis_people_add_lifecycle_task', {
                    pid: pid,
                    phase: (document.getElementById('metis-people-task-phase') || {}).value || 'onboarding',
                    due_at: (document.getElementById('metis-people-task-due') || {}).value || '',
                    task_label: (document.getElementById('metis-people-task-label') || {}).value || ''
                }).then(function (data) {
                    showAlert('Lifecycle task added.', 'success');
                    taskForm.reset();
                    const list = document.getElementById('metis-people-task-list');
                    const task = data && data.task ? data.task : null;
                    if (!list || !task) return;
                    const empty = list.querySelector('.mw-muted');
                    if (empty) empty.remove();
                    const item = document.createElement('div');
                    item.className = 'metis-people-mini-item';
                    const title = document.createElement('div');
                    const phaseStrong = document.createElement('strong');
                    phaseStrong.textContent = String(task.phase || 'onboarding').replace(/^./, function (c) { return c.toUpperCase(); });
                    title.appendChild(phaseStrong);
                    title.appendChild(document.createTextNode(' - ' + String(task.task_label || '')));
                    const meta = document.createElement('div');
                    meta.className = 'mw-muted';
                    meta.textContent = 'Status: pending' + (task.due_at ? (' | Due: ' + task.due_at) : '');
                    const actions = document.createElement('div');
                    actions.className = 'metis-people-mini-actions';
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'mw-btn-xs metis-task-complete';
                    btn.dataset.id = String(task.id || '');
                    btn.textContent = 'Mark Complete';
                    btn.addEventListener('click', function () {
                        post('metis_people_complete_lifecycle_task', { task_id: btn.dataset.id || '' })
                            .then(function () {
                                showAlert('Task marked complete.', 'success');
                                meta.textContent = String(meta.textContent || '').replace('Status: pending', 'Status: completed').replace('Status: in_progress', 'Status: completed');
                                btn.disabled = true;
                                btn.textContent = 'Completed';
                            })
                            .catch(function (err) {
                                showAlert(err.message || 'Failed to complete task.', 'error');
                            });
                    });
                    actions.appendChild(btn);
                    item.appendChild(title);
                    item.appendChild(meta);
                    item.appendChild(actions);
                    list.prepend(item);
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to add lifecycle task.', 'error');
                });
            });
        }
        document.querySelectorAll('.metis-task-complete').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const taskId = String(btn.dataset.id || '').trim();
                if (!taskId) return;
                post('metis_people_complete_lifecycle_task', { task_id: taskId })
                    .then(function () {
                        showAlert('Task marked complete.', 'success');
                        const row = btn.closest('.metis-people-mini-item');
                        if (row) {
                            const meta = row.querySelector('.mw-muted');
                            if (meta && meta.textContent.indexOf('Status: completed') < 0) {
                                meta.textContent = String(meta.textContent || '').replace('Status: pending', 'Status: completed').replace('Status: in_progress', 'Status: completed');
                            }
                        }
                        btn.disabled = true;
                        btn.textContent = 'Completed';
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to complete task.', 'error');
                    });
            });
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
                const table = document.querySelector('.metis-people-ops .mw-premium-table');
                const header = table ? table.querySelector('.mw-premium-header') : null;
                if (!table || !header) return;
                const requestCode = String((data && data.request_code) || '').trim();
                const row = document.createElement('div');
                row.className = 'mw-premium-row';
                row.style.cssText = 'display:grid;grid-template-columns:120px 120px 130px 170px 160px 130px 200px 1fr 170px;align-items:center;';
                row.innerHTML =
                    '<div class="mw-premium-cell">' + requestCode + '</div>' +
                    '<div class="mw-premium-cell">' + String((data && data.status) || 'pending') + '</div>' +
                    '<div class="mw-premium-cell">' + String((data && data.target_pid) || '—') + '</div>' +
                    '<div class="mw-premium-cell">' + String((data && data.target_name) || '—') + '</div>' +
                    '<div class="mw-premium-cell">' + String((data && data.role_name) || '—') + '</div>' +
                    '<div class="mw-premium-cell">' + String((data && data.approval_count) || 0) + '/' + String((data && data.required_approvals) || (requiredApprovals ? requiredApprovals.value : '2')) + '</div>' +
                    '<div class="mw-premium-cell">' +
                        ((data && data.requested_start_at) ? ('Start ' + data.requested_start_at) : '') +
                        ((data && data.requested_end_at) ? (' | End ' + data.requested_end_at) : '') +
                        ((data && data.expires_at) ? (' | Req exp ' + data.expires_at) : '') +
                        (!data || (!data.requested_start_at && !data.requested_end_at && !data.expires_at) ? '—' : '') +
                    '</div>' +
                    '<div class="mw-premium-cell"><div>' + requestReason + '</div></div>' +
                    '<div class="mw-premium-cell"><span class="mw-muted">Pending review</span></div>';
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
                const row = btn.closest('.mw-premium-row');
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
                    actionsCell.innerHTML = '<span class="mw-muted">—</span>';
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
    const bulkRoleForm = document.getElementById('metis-bulk-role-form');
    if (bulkRoleForm) {
        bulkRoleForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const pids = Array.from(document.querySelectorAll('.metis-bulk-person:checked')).map(function (cb) { return cb.value; });
            post('metis_people_bulk_role_action', {
                role_key: (document.getElementById('metis-bulk-role') || {}).value || '',
                bulk_action: (document.getElementById('metis-bulk-action') || {}).value || 'assign',
                person_pids: JSON.stringify(pids)
            }).then(function (data) {
                const count = parseInt((data && data.updated) || 0, 10);
                showAlert('Bulk action completed (' + count + ' records updated).', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Bulk action failed.', 'error');
            });
        });
    }
    const bulkWorkspaceForm = document.getElementById('metis-bulk-workspace-group-form');
    if (bulkWorkspaceForm) {
        bulkWorkspaceForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const pids = Array.from(document.querySelectorAll('.metis-bulk-person:checked')).map(function (cb) { return cb.value; });
            post('metis_people_bulk_workspace_group_action', {
                group_email: (document.getElementById('metis-bulk-workspace-group') || {}).value || '',
                bulk_action: (document.getElementById('metis-bulk-workspace-group-action') || {}).value || 'assign',
                member_role: (document.getElementById('metis-bulk-workspace-member-role') || {}).value || 'member',
                person_pids: JSON.stringify(pids)
            }).then(function (data) {
                const updated = parseInt((data && data.updated) || 0, 10);
                const skipped = parseInt((data && data.skipped) || 0, 10);
                showAlert('Workspace bulk action completed (' + updated + ' updated, ' + skipped + ' skipped).', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Workspace bulk action failed.', 'error');
            });
        });
    }
    const bulkStripeForm = document.getElementById('metis-bulk-stripe-role-form');
    if (bulkStripeForm) {
        bulkStripeForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const pids = Array.from(document.querySelectorAll('.metis-bulk-person:checked')).map(function (cb) { return cb.value; });
            post('metis_people_bulk_stripe_role_action', {
                stripe_role: (document.getElementById('metis-bulk-stripe-role') || {}).value || '',
                bulk_action: (document.getElementById('metis-bulk-stripe-action') || {}).value || 'set',
                person_pids: JSON.stringify(pids)
            }).then(function (data) {
                const updated = parseInt((data && data.updated) || 0, 10);
                const queued = parseInt((data && data.queued) || 0, 10);
                showAlert('Stripe bulk action completed (' + updated + ' updated, ' + queued + ' sync jobs queued).', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Stripe bulk action failed.', 'error');
            });
        });
    }
});
