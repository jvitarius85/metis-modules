window.MetisPeopleProfileModules = window.MetisPeopleProfileModules || {};

window.MetisPeopleProfileModules.initWorkspace = function (context) {
    const normalize = context.normalize;
    const rawShowAlert = context.showAlert;
    const post = context.post;
    const openModal = context.openModal;
    const closeModal = context.closeModal;
    const confirmAction = context.confirmAction;
    const navigate = function (url) {
        const target = String(url || '').trim();
        if (!target) return false;
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            const handled = Metis.navigation.go(target);
            if (handled) return true;
        }
        window.location.assign(target);
        return true;
    };
    const recentWorkspaceAlerts = new Map();
    const showAlert = function (message, type) {
        const body = String(message || '').trim();
        if (!body || typeof rawShowAlert !== 'function') return;
        const tone = String(type || 'info').trim() || 'info';
        const key = tone + ':' + body;
        const now = Date.now();
        const last = Number(recentWorkspaceAlerts.get(key) || 0);
        if (last && now - last < 1500) return;
        recentWorkspaceAlerts.set(key, now);
        rawShowAlert(body, tone);
    };
    // Workspace management.
    const workspaceRoot = document.querySelector('.metis-people-workspace');
    if (workspaceRoot) {
        if (workspaceRoot.dataset.metisWorkspaceBound === '1') return;
        workspaceRoot.dataset.metisWorkspaceBound = '1';
        const workspaceLogGrid = workspaceRoot.querySelector('.metis-workspace-log-grid');
        function renderWorkspaceSyncRows(rows) {
            const wrap = document.getElementById('metis-workspace-sync-log-rows');
            if (!wrap) return;
            const list = Array.isArray(rows) ? rows : [];
            if (!list.length) {
                wrap.innerHTML =
                    '<tr class="metis-premium-row metis-workspace-empty-row">' +
                        '<td class="metis-premium-cell" colspan="4">No sync jobs.</td>' +
                    '</tr>';
                return;
            }
            wrap.innerHTML = list.map(function (row) {
                const title = Metis.util.escapeHtml(String((row && row.title) || 'Sync job'));
                const error = Metis.util.escapeHtml(String((row && row.error) || ''));
                const entityLabel = Metis.util.escapeHtml(String((row && row.entity_label) || 'Entity'));
                const entityUrl = String((row && row.entity_url) || '').trim();
                const statusLabel = Metis.util.escapeHtml(String((row && row.status_label) || 'Unknown'));
                const statusClass = String((row && row.status_class) || '').trim();
                const time = Metis.util.escapeHtml(String((row && row.time) || 'Unknown time'));
                const entityHtml = entityUrl
                    ? '<a class="metis-workspace-entity-link" href="' + Metis.util.escapeHtml(entityUrl) + '">' + entityLabel + '</a>'
                    : entityLabel;
                return '' +
                    '<tr class="metis-premium-row">' +
                        '<td class="metis-premium-cell">' +
                            '<strong>' + title + '</strong>' +
                            (error ? '<div class="metis-workspace-mini-error">' + error + '</div>' : '') +
                        '</td>' +
                        '<td class="metis-premium-cell">' + entityHtml + '</td>' +
                        '<td class="metis-premium-cell"><span class="metis-chip ' + Metis.util.escapeHtml(statusClass) + '">' + statusLabel + '</span></td>' +
                        '<td class="metis-premium-cell">' + time + '</td>' +
                    '</tr>';
            }).join('');
        }
        function renderWorkspaceSecurityRows(rows) {
            const wrap = document.getElementById('metis-workspace-security-log-rows');
            if (!wrap) return;
            const list = Array.isArray(rows) ? rows : [];
            if (!list.length) {
                wrap.innerHTML =
                    '<tr class="metis-premium-row metis-workspace-empty-row">' +
                        '<td class="metis-premium-cell" colspan="4">No security actions.</td>' +
                    '</tr>';
                return;
            }
            wrap.innerHTML = list.map(function (row) {
                const title = Metis.util.escapeHtml(String((row && row.title) || 'Security action'));
                const reason = Metis.util.escapeHtml(String((row && row.reason) || ''));
                const userName = Metis.util.escapeHtml(String((row && row.user_name) || 'Workspace user'));
                const userUrl = String((row && row.user_url) || '').trim();
                const statusLabel = Metis.util.escapeHtml(String((row && row.status_label) || 'Unknown'));
                const statusClass = String((row && row.status_class) || '').trim();
                const time = Metis.util.escapeHtml(String((row && row.time) || 'Unknown time'));
                const userHtml = userUrl
                    ? '<a class="metis-workspace-entity-link" href="' + Metis.util.escapeHtml(userUrl) + '">' + userName + '</a>'
                    : userName;
                return '' +
                    '<tr class="metis-premium-row">' +
                        '<td class="metis-premium-cell">' +
                            '<strong>' + title + '</strong>' +
                            (reason ? '<div class="metis-muted">Reason: ' + reason + '</div>' : '') +
                        '</td>' +
                        '<td class="metis-premium-cell">' + userHtml + '</td>' +
                        '<td class="metis-premium-cell"><span class="metis-chip ' + Metis.util.escapeHtml(statusClass) + '">' + statusLabel + '</span></td>' +
                        '<td class="metis-premium-cell">' + time + '</td>' +
                    '</tr>';
            }).join('');
        }
        function renderWorkspacePagination(sync, security) {
            const syncLabel = document.getElementById('metis-workspace-sync-page-label');
            const secLabel = document.getElementById('metis-workspace-security-page-label');
            if (syncLabel) {
                syncLabel.textContent = 'Page ' + String((sync && sync.page) || 1) + ' of ' + String((sync && sync.total_pages) || 1);
            }
            if (secLabel) {
                secLabel.textContent = 'Page ' + String((security && security.page) || 1) + ' of ' + String((security && security.total_pages) || 1);
            }
            if (workspaceLogGrid) {
                workspaceLogGrid.dataset.syncPage = String((sync && sync.page) || 1);
                workspaceLogGrid.dataset.securityPage = String((security && security.page) || 1);
            }
            const pagers = workspaceRoot.querySelectorAll('.metis-workspace-log-pagination-actions');
            if (pagers && pagers.length >= 2) {
                const syncPager = pagers[0];
                const secPager = pagers[1];
                if (syncPager) {
                    const syncPage = parseInt(String((sync && sync.page) || '1'), 10) || 1;
                    const secPage = parseInt(String((security && security.page) || '1'), 10) || 1;
                    let html = '';
                    if (sync && sync.has_prev) html += '<button type="button" class="metis-workspace-page-link" data-sync-page="' + String(sync.prev_page || 1) + '" data-security-page="' + String(secPage) + '">&larr; Previous</button>';
                    if (sync && sync.has_next) html += '<button type="button" class="metis-workspace-page-link" data-sync-page="' + String(sync.next_page || syncPage) + '" data-security-page="' + String(secPage) + '">Next &rarr;</button>';
                    syncPager.innerHTML = html;
                }
                if (secPager) {
                    const syncPage = parseInt(String((sync && sync.page) || '1'), 10) || 1;
                    const secPage = parseInt(String((security && security.page) || '1'), 10) || 1;
                    let html = '';
                    if (security && security.has_prev) html += '<button type="button" class="metis-workspace-page-link" data-sync-page="' + String(syncPage) + '" data-security-page="' + String(security.prev_page || 1) + '">&larr; Previous</button>';
                    if (security && security.has_next) html += '<button type="button" class="metis-workspace-page-link" data-sync-page="' + String(syncPage) + '" data-security-page="' + String(security.next_page || secPage) + '">Next &rarr;</button>';
                    secPager.innerHTML = html;
                }
            }
        }
        function loadWorkspaceLogPage(syncPage, securityPage) {
            if (!workspaceLogGrid) return Promise.resolve();
            const nextSync = parseInt(String(syncPage || workspaceLogGrid.dataset.syncPage || '1'), 10) || 1;
            const nextSecurity = parseInt(String(securityPage || workspaceLogGrid.dataset.securityPage || '1'), 10) || 1;
            workspaceLogGrid.classList.add('is-loading');
            return post('metis_people_workspace_get_activity_page', {
                sync_page: String(nextSync),
                security_page: String(nextSecurity)
            })
                .then(function (payload) {
                    const sync = payload && payload.sync ? payload.sync : {};
                    const security = payload && payload.security ? payload.security : {};
                    renderWorkspaceSyncRows(sync.rows || []);
                    renderWorkspaceSecurityRows(security.rows || []);
                    renderWorkspacePagination(sync, security);
                })
                .catch(function (err) {
                    showAlert((err && err.message) ? err.message : 'Failed to load activity page.', 'error');
                })
                .finally(function () {
                    workspaceLogGrid.classList.remove('is-loading');
                });
        }
        workspaceRoot.addEventListener('click', function (event) {
            const actionOpen = event.target.closest('.metis-workspace-actions-open');
            if (actionOpen) {
                event.preventDefault();
                event.stopPropagation();
                const row = actionOpen.closest('.metis-workspace-user-row');
                if (!row || !userRowsWrap) return;
                const menu = row.querySelector('.metis-workspace-actions-menu');
                if (!menu) return;
                const wasOpen = row.classList.contains('is-menu-open');
                Array.from(userRowsWrap.querySelectorAll('.metis-workspace-user-row.is-menu-open')).forEach(function (r) {
                    r.classList.remove('is-menu-open');
                    const openMenu = r.querySelector('.metis-workspace-actions-menu');
                    if (openMenu) openMenu.setAttribute('aria-hidden', 'true');
                });
                if (!wasOpen) {
                    row.classList.add('is-menu-open');
                    menu.setAttribute('aria-hidden', 'false');
                }
                return;
            }
            const pageLink = event.target.closest('.metis-workspace-page-link[data-sync-page][data-security-page]');
            if (!pageLink) return;
            event.preventDefault();
            event.stopPropagation();
            loadWorkspaceLogPage(
                pageLink.getAttribute('data-sync-page'),
                pageLink.getAttribute('data-security-page')
            );
        }, true);
        const canManage = workspaceRoot.dataset.canManage === '1';
        const userSearch = document.getElementById('metis-workspace-user-search');
        const userRowsWrap = document.getElementById('metis-workspace-user-rows');
        const showHiddenUsersToggle = document.getElementById('metis-workspace-show-hidden-users');
        const kpiUsersEl = document.getElementById('metis-workspace-kpi-users');
        function refreshWorkspaceUserKpi() {
            if (!userRowsWrap || !kpiUsersEl) return;
            const rows = Array.from(userRowsWrap.querySelectorAll('.metis-workspace-user-row'));
            const count = rows.filter(function (row) { return String(row.dataset.hidden || '0') !== '1'; }).length;
            kpiUsersEl.textContent = String(count);
        }
        function applyWorkspaceRowFilters() {
            if (!userRowsWrap) return;
            const q = normalize(userSearch ? userSearch.value : '');
            const showHidden = !!(showHiddenUsersToggle && showHiddenUsersToggle.checked);
            Array.from(userRowsWrap.querySelectorAll('.metis-workspace-user-row')).forEach(function (row) {
                const hay = normalize(row.dataset.search || '');
                const matchesSearch = !q || hay.indexOf(q) >= 0;
                const isHidden = String(row.dataset.hidden || '0') === '1';
                const visible = matchesSearch && (!isHidden || showHidden);
                row.style.display = visible ? '' : 'none';
            });
            applyWorkspaceRowClasses();
            refreshWorkspaceUserKpi();
        }
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
        if (userRowsWrap) {
            if (userSearch) {
                userSearch.addEventListener('input', applyWorkspaceRowFilters);
            }
            if (showHiddenUsersToggle) {
                showHiddenUsersToggle.addEventListener('change', applyWorkspaceRowFilters);
            }
            userRowsWrap.addEventListener('click', function (event) {
                const interactive = event.target.closest('button, a, input, select, textarea, label');
                if (interactive) return;
                const row = event.target.closest('.metis-workspace-user-row');
                if (!row) return;
                const personUrl = String(row.dataset.personUrl || '').trim();
                if (!personUrl) return;
                navigate(personUrl);
            });
            applyWorkspaceRowFilters();
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
                    roleMapAlert.className = 'metis-alert';
                    return;
                }
                roleMapAlert.className = 'metis-alert ' + (type === 'error' ? 'metis-alert-error' : 'metis-alert-success');
                roleMapAlert.textContent = txt;
                roleMapAlert.style.display = 'block';
            }

            function renderRoleMapRows(rows) {
                if (!roleMapRows) return;
                const list = Array.isArray(rows) ? rows : [];
                if (!list.length) {
                    roleMapRows.innerHTML = '<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="3">No roles returned from Google.</td></tr>';
                    return;
                }
                roleMapRows.innerHTML = list.map(function (row) {
                    const friendly = escapeHtml((row && row.friendly_name) || '');
                    const googleName = escapeHtml((row && row.google_role_name) || '');
                    const metisKey = escapeHtml((row && row.metis_role_key) || '');
                    const googleId = escapeHtml((row && row.google_role_id) || '');
                    const assignedCount = parseInt((row && row.assigned_count) || 0, 10);
                    const assignedLabel = assignedCount > 0 ? String(assignedCount) : '0';
                    return '<tr class="metis-premium-row">' +
                        '<td class="metis-premium-cell">' +
                            '<strong>' + friendly + '</strong>' +
                            '<div class="metis-muted" title="Google: ' + googleName + ' | ID: ' + googleId + '">Google: ' + googleName + '</div>' +
                        '</td>' +
                        '<td class="metis-premium-cell"><code>' + metisKey + '</code></td>' +
                        '<td class="metis-premium-cell">' + assignedLabel + '</td>' +
                    '</tr>';
                }).join('');
            }

            function loadWorkspaceRoleMap() {
                if (!roleMapRows) return Promise.resolve();
                roleMapRows.innerHTML = '<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="3">Loading role map...</td></tr>';
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
            const userSecondaryEmail = document.getElementById('metis-workspace-user-secondary-email');
            const userHidden = document.getElementById('metis-workspace-user-hidden');
            const userSuspended = document.getElementById('metis-workspace-user-suspended');
            const userProtected = document.getElementById('metis-workspace-user-protected');
            const userCreateMetis = document.getElementById('metis-workspace-user-create-metis');
            const userCreateDriveFolder = document.getElementById('metis-workspace-user-create-drive-folder');
            const roleToggles = Array.from(document.querySelectorAll('.metis-workspace-role-toggle'));
            const userGroupToggles = Array.from(document.querySelectorAll('.metis-workspace-user-group-toggle'));
            const createMetisUserButtons = Array.from(document.querySelectorAll('.metis-workspace-create-person-btn'));
            const roleLabelByKey = {};
            roleToggles.forEach(function (cb) {
                const key = String(cb.value || '').trim();
                if (!key) return;
                const label = cb.closest('label');
                const text = label ? String(label.textContent || '').replace(/\s+/g, ' ').trim() : key;
                roleLabelByKey[key] = text || key;
            });

            function workspaceNameFromUser(user) {
                const first = String((user && user.first_name) || '').trim();
                const last = String((user && user.last_name) || '').trim();
                const combined = (first + ' ' + last).trim();
                if (combined) return combined;
                const display = String((user && user.display_name) || '').trim();
                if (display) return display;
                return String((user && user.primary_email) || '').trim();
            }

            function updateWorkspaceRowFromUser(user) {
                if (!userRowsWrap || !user || !user.id) return;
                const workspaceUserId = String(user.id).trim();
                if (!workspaceUserId) return;
                const row = userRowsWrap.querySelector('.metis-workspace-user-row[data-workspace-user-id="' + workspaceUserId + '"]');
                if (!row) return;

                const name = workspaceNameFromUser(user);
                const primaryEmail = String(user.primary_email || '').trim();
                const orgUnit = String(user.org_unit_path || '/').trim() || '/';
                const secondaryEmail = String(user.secondary_email || '').trim();
                const linkedPid = String(user.linked_pid || '').trim();
                const linkedName = String(user.linked_name || '').trim();
                const personUrl = String(user.person_url || '').trim();
                const isHidden = String(user.is_hidden || '0') === '1';
                const isProtected = String(user.is_protected || '0') === '1';
                const isSuspended = String(user.is_suspended || '0') === '1';
                const roleKeys = Array.isArray(user.role_keys) ? user.role_keys : [];
                const roleLabels = roleKeys.map(function (key) {
                    const roleKey = String(key || '').trim();
                    return roleLabelByKey[roleKey] || roleKey;
                }).filter(function (label) { return String(label).trim() !== ''; });

                const cells = row.querySelectorAll('.metis-premium-cell');
                if (cells.length >= 7) {
                    cells[0].innerHTML = '<strong>' + escapeHtml(name) + '</strong>' + (secondaryEmail ? ('<div class="metis-muted">' + escapeHtml(secondaryEmail) + '</div>') : '');
                    cells[1].textContent = primaryEmail;
                    cells[2].textContent = orgUnit;
                    cells[3].textContent = roleLabels.length ? roleLabels.join(', ') : '—';
                    if (linkedPid) {
                        cells[5].innerHTML = linkedName
                            ? ('<div>' + escapeHtml(linkedName) + '</div><div class="metis-muted">' + escapeHtml(linkedPid) + '</div>')
                            : ('<div class="metis-muted">' + escapeHtml(linkedPid) + '</div>');
                    } else {
                        cells[5].textContent = '—';
                    }
                }

                const statusCell = row.querySelector('.metis-workspace-status-cell');
                if (statusCell) {
                    let html = isSuspended
                        ? '<span class="metis-chip">Suspended</span>'
                        : '<span class="metis-chip metis-chip-success">Active</span>';
                    if (isProtected) html += '<span class="metis-chip">Protected</span>';
                    if (isHidden) html += '<span class="metis-chip">Hidden</span>';
                    statusCell.innerHTML = html;
                }

                row.dataset.hidden = isHidden ? '1' : '0';
                row.dataset.protected = isProtected ? '1' : '0';
                row.dataset.suspended = isSuspended ? '1' : '0';
                row.dataset.search = String([
                    name,
                    primaryEmail,
                    secondaryEmail,
                    orgUnit,
                    linkedPid
                ].join(' ').toLowerCase()).trim();
                if (personUrl) {
                    row.dataset.personUrl = personUrl;
                    row.classList.add('is-clickable');
                } else {
                    delete row.dataset.personUrl;
                    row.classList.remove('is-clickable');
                }

                const editBtn = row.querySelector('.metis-workspace-edit-user-open');
                if (editBtn) {
                    editBtn.dataset.primaryEmail = primaryEmail;
                    editBtn.dataset.linkedPid = linkedPid;
                    editBtn.dataset.firstName = String(user.first_name || '').trim();
                    editBtn.dataset.lastName = String(user.last_name || '').trim();
                    editBtn.dataset.displayName = String(user.display_name || '').trim();
                    editBtn.dataset.orgUnit = orgUnit;
                    editBtn.dataset.secondaryEmail = secondaryEmail;
                    editBtn.dataset.hidden = isHidden ? '1' : '0';
                    editBtn.dataset.suspended = isSuspended ? '1' : '0';
                    editBtn.dataset.protected = isProtected ? '1' : '0';
                    editBtn.dataset.roleKeys = JSON.stringify(roleKeys);
                    editBtn.dataset.groupIds = JSON.stringify(Array.isArray(user.group_ids) ? user.group_ids : []);
                }

                const hiddenBtn = row.querySelector('.metis-workspace-flag-btn[data-flag="is_hidden"]');
                if (hiddenBtn) {
                    hiddenBtn.textContent = isHidden ? 'Unhide' : 'Hide';
                    hiddenBtn.dataset.value = isHidden ? '0' : '1';
                }
                const protectedBtn = row.querySelector('.metis-workspace-flag-btn[data-flag="is_protected"]');
                if (protectedBtn) {
                    protectedBtn.textContent = isProtected ? 'Unprotect' : 'Protect';
                    protectedBtn.dataset.value = isProtected ? '0' : '1';
                }
                const suspendBtn = row.querySelector('.metis-workspace-security-open[data-action-type="suspend_account"], .metis-workspace-security-open[data-action-type="unsuspend_account"]');
                if (suspendBtn) {
                    suspendBtn.dataset.actionType = isSuspended ? 'unsuspend_account' : 'suspend_account';
                    suspendBtn.textContent = isSuspended ? 'Unsuspend' : 'Suspend';
                }

                const createPersonBtn = row.querySelector('.metis-workspace-create-person-btn');
                if (createPersonBtn) {
                    createPersonBtn.hidden = linkedPid !== '';
                }
                const deleteBtn = row.querySelector('.metis-workspace-delete-user');
                if (deleteBtn) {
                    deleteBtn.hidden = (linkedPid !== '') || isProtected;
                }

                applyWorkspaceRowFilters();
            }

            function resetUserForm() {
                if (userTitle) userTitle.textContent = 'Add Workspace User';
                if (userIdEl) userIdEl.value = '0';
                if (userPrimaryEmail) userPrimaryEmail.value = '';
                if (userLinkedPid) userLinkedPid.value = '';
                if (userFirstName) userFirstName.value = '';
                if (userLastName) userLastName.value = '';
                if (userDisplayName) userDisplayName.value = '';
                if (userOrgUnit) userOrgUnit.value = '/';
                if (userSecondaryEmail) userSecondaryEmail.value = '';
                if (userHidden) userHidden.checked = false;
                if (userSuspended) userSuspended.checked = false;
                if (userProtected) userProtected.checked = false;
                if (userCreateMetis) userCreateMetis.checked = true;
                if (userCreateDriveFolder) userCreateDriveFolder.checked = true;
                roleToggles.forEach(function (cb) { cb.checked = false; });
                userGroupToggles.forEach(function (cb) { cb.checked = false; });
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
                    if (userSecondaryEmail) userSecondaryEmail.value = String(btn.dataset.secondaryEmail || '');
                    if (userHidden) userHidden.checked = String(btn.dataset.hidden || '0') === '1';
                    if (userSuspended) userSuspended.checked = String(btn.dataset.suspended || '0') === '1';
                    if (userProtected) userProtected.checked = String(btn.dataset.protected || '0') === '1';
                    if (userCreateMetis) userCreateMetis.checked = false;
                    if (userCreateDriveFolder) userCreateDriveFolder.checked = false;
                    let roles = [];
                    try { roles = JSON.parse(String(btn.dataset.roleKeys || '[]')); } catch (e) { roles = []; }
                    roleToggles.forEach(function (cb) { cb.checked = roles.indexOf(cb.value) >= 0; });
                    let groupIds = [];
                    try { groupIds = JSON.parse(String(btn.dataset.groupIds || '[]')); } catch (e) { groupIds = []; }
                    groupIds = groupIds.map(function (id) { return String(id); });
                    userGroupToggles.forEach(function (cb) { cb.checked = groupIds.indexOf(String(cb.value || '')) >= 0; });
                    openModal(userModal);
                });
            });

            if (userForm) {
                userForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    if (userForm.dataset.busy === '1') return;
                    userForm.dataset.busy = '1';
                    const submitButton = userForm.querySelector('button[type="submit"]');
                    if (submitButton) submitButton.disabled = true;
                    const roleKeys = roleToggles.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
                    const groupIds = userGroupToggles.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
                    post('metis_people_workspace_save_user', {
                        workspace_user_id: userIdEl ? userIdEl.value : '0',
                        primary_email: userPrimaryEmail ? userPrimaryEmail.value : '',
                        linked_pid: userLinkedPid ? userLinkedPid.value : '',
                        first_name: userFirstName ? userFirstName.value : '',
                        last_name: userLastName ? userLastName.value : '',
                        display_name: userDisplayName ? userDisplayName.value : '',
                        org_unit_path: userOrgUnit ? userOrgUnit.value : '/',
                        secondary_email: userSecondaryEmail ? userSecondaryEmail.value : '',
                        recovery_email: userSecondaryEmail ? userSecondaryEmail.value : '',
                        is_hidden: userHidden && userHidden.checked ? '1' : '0',
                        is_suspended: userSuspended && userSuspended.checked ? '1' : '0',
                        is_protected: userProtected && userProtected.checked ? '1' : '0',
                        create_metis_user: userCreateMetis && userCreateMetis.checked ? '1' : '0',
                        create_drive_folder: userCreateDriveFolder && userCreateDriveFolder.checked ? '1' : '0',
                        role_keys: JSON.stringify(roleKeys),
                        group_ids: JSON.stringify(groupIds)
                    }).then(function (data) {
                        if (data && data.user && Number(data.user.id || 0) > 0) {
                            updateWorkspaceRowFromUser(data.user);
                        }
                        const driveFolder = (data && data.drive_folder && typeof data.drive_folder === 'object') ? data.drive_folder : null;
                        const metisUser = (data && data.metis_user && typeof data.metis_user === 'object') ? data.metis_user : null;
                        const messages = ['Workspace user saved.'];
                        if (metisUser && metisUser.ok) {
                            messages.push(metisUser.created ? 'Metis user created.' : 'Metis user linked.');
                        }
                        if (driveFolder && driveFolder.ok) {
                            messages.push(driveFolder.created ? 'Drive folder created.' : 'Drive folder linked.');
                        }
                        showAlert((data && data.sync_warning) ? String(data.sync_warning) : messages.join(' '), (data && data.sync_warning) ? 'warning' : 'success');
                        closeModal(userModal);
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to save workspace user.', 'error');
                    }).finally(function () {
                        userForm.dataset.busy = '0';
                        if (submitButton) submitButton.disabled = false;
                    });
                });
            }

            createMetisUserButtons.forEach(function (btn) {
                btn.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const workspaceUserId = String(btn.dataset.workspaceUserId || '').trim();
                    if (!workspaceUserId) return;
                    btn.disabled = true;
                    post('metis_people_workspace_create_metis_user', {
                        workspace_user_id: workspaceUserId
                    }).then(function (data) {
                        const personUrl = String((data && data.person_url) || '').trim();
                        const pid = String((data && data.pid) || '').trim();
                        const row = btn.closest('.metis-workspace-user-row');
                        if (row) {
                            const cells = row.querySelectorAll('.metis-premium-cell');
                            if (cells.length >= 7) {
                                const nameCell = cells[0];
                                const strong = nameCell ? nameCell.querySelector('strong') : null;
                                const displayName = strong ? String(strong.textContent || '').trim() : '';
                                if (pid) {
                                    cells[5].innerHTML = displayName
                                        ? ('<div>' + escapeHtml(displayName) + '</div><div class="metis-muted">' + escapeHtml(pid) + '</div>')
                                        : ('<div class="metis-muted">' + escapeHtml(pid) + '</div>');
                                } else {
                                    cells[5].textContent = 'Linked';
                                }
                                cells[6].innerHTML = '<span class="metis-muted">—</span>';
                            }
                            if (personUrl) {
                                row.dataset.personUrl = personUrl;
                                row.classList.add('is-clickable');
                            }
                        }
                        const driveFolder = (data && data.drive_folder && typeof data.drive_folder === 'object') ? data.drive_folder : null;
                        let message = String((data && data.already_linked) ? 'Workspace user was already linked to a Metis profile.' : 'Metis user created and linked.');
                        if (driveFolder && driveFolder.ok) {
                            message += driveFolder.created ? ' Drive folder created.' : ' Drive folder linked.';
                        }
                        showAlert(message, 'success');
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to create Metis user.', 'error');
                    }).finally(function () {
                        btn.disabled = false;
                    });
                });
            });
            if (userRowsWrap) {
                function workspaceActionTrigger(row) {
                    return row ? row.querySelector('.metis-workspace-actions-open') : null;
                }
                function workspaceActionMenu(row) {
                    return row ? row.querySelector('.metis-workspace-actions-menu') : null;
                }
                function workspaceActionItems(menu) {
                    return Array.from((menu || document).querySelectorAll('[role="menuitem"]')).filter(function (node) {
                        return !node.hidden && !node.disabled;
                    });
                }
                function closeWorkspaceActionMenus(options) {
                    const config = options || {};
                    Array.from(userRowsWrap.querySelectorAll('.metis-workspace-user-row.is-menu-open')).forEach(function (r) {
                        r.classList.remove('is-menu-open');
                        const menu = workspaceActionMenu(r);
                        const trigger = workspaceActionTrigger(r);
                        if (menu) menu.setAttribute('aria-hidden', 'true');
                        if (trigger) trigger.setAttribute('aria-expanded', 'false');
                        if (config.restoreFocus && config.restoreFocus === r && trigger && typeof trigger.focus === 'function') {
                            trigger.focus();
                        }
                    });
                }
                function openWorkspaceActionMenu(row, focusPosition) {
                    if (!row) return;
                    const menu = workspaceActionMenu(row);
                    const trigger = workspaceActionTrigger(row);
                    if (!menu || !trigger) return;
                    closeWorkspaceActionMenus();
                    row.classList.add('is-menu-open');
                    menu.setAttribute('aria-hidden', 'false');
                    trigger.setAttribute('aria-expanded', 'true');
                    if (focusPosition) {
                        const items = workspaceActionItems(menu);
                        const target = focusPosition === 'last' ? items[items.length - 1] : items[0];
                        if (target && typeof target.focus === 'function') {
                            target.focus();
                        }
                    }
                }
                userRowsWrap.addEventListener('click', function (event) {
                    const inMenuAction = event.target.closest('.metis-workspace-actions-menu .metis-btn-xs');
                    if (inMenuAction) {
                        window.setTimeout(function () {
                            closeWorkspaceActionMenus();
                        }, 0);
                    }
                    const actionOpen = event.target.closest('.metis-workspace-actions-open');
                    if (actionOpen) {
                        event.preventDefault();
                        event.stopPropagation();
                        const row = actionOpen.closest('.metis-workspace-user-row');
                        if (!row) return;
                        const menu = workspaceActionMenu(row);
                        if (!menu) return;
                        const wasOpen = row.classList.contains('is-menu-open');
                        closeWorkspaceActionMenus();
                        if (!wasOpen) {
                            openWorkspaceActionMenu(row);
                        }
                        return;
                    }
                    const flagBtn = event.target.closest('.metis-workspace-flag-btn');
                    if (!flagBtn) return;
                    event.preventDefault();
                    event.stopPropagation();
                    const workspaceUserId = String(flagBtn.dataset.workspaceUserId || '').trim();
                    const flag = String(flagBtn.dataset.flag || '').trim();
                    const nextValue = String(flagBtn.dataset.value || '0') === '1' ? '1' : '0';
                    if (!workspaceUserId || (flag !== 'is_hidden' && flag !== 'is_protected')) return;
                    const payload = { workspace_user_id: workspaceUserId };
                    payload[flag] = nextValue;
                    flagBtn.disabled = true;
                    post('metis_people_workspace_set_user_flags', payload).then(function (data) {
                        const row = flagBtn.closest('.metis-workspace-user-row');
                        const user = data && data.user ? data.user : {};
                        const isHidden = String(user.is_hidden || '0') === '1';
                        const isProtected = String(user.is_protected || '0') === '1';
                        const suspendedSeed = row ? String(row.dataset.suspended || '0') : '0';
                        const isSuspended = String(user.is_suspended || suspendedSeed || '0') === '1';
                        if (row) {
                            row.dataset.hidden = isHidden ? '1' : '0';
                            row.dataset.protected = isProtected ? '1' : '0';
                            const hiddenBtn = row.querySelector('.metis-workspace-flag-btn[data-flag="is_hidden"]');
                            if (hiddenBtn) {
                                hiddenBtn.textContent = isHidden ? 'Unhide' : 'Hide';
                                hiddenBtn.dataset.value = isHidden ? '0' : '1';
                            }
                            const protectedBtn = row.querySelector('.metis-workspace-flag-btn[data-flag="is_protected"]');
                            if (protectedBtn) {
                                protectedBtn.textContent = isProtected ? 'Unprotect' : 'Protect';
                                protectedBtn.dataset.value = isProtected ? '0' : '1';
                            }
                            const deleteBtn = row.querySelector('.metis-workspace-delete-user');
                            if (deleteBtn) {
                                deleteBtn.hidden = isProtected;
                            }
                            const statusCell = row.querySelector('.metis-workspace-status-cell');
                            if (statusCell) {
                                let html = isSuspended
                                    ? '<span class="metis-chip">Suspended</span>'
                                    : '<span class="metis-chip metis-chip-success">Active</span>';
                                if (isProtected) html += '<span class="metis-chip">Protected</span>';
                                if (isHidden) html += '<span class="metis-chip">Hidden</span>';
                                statusCell.innerHTML = html;
                            }
                        }
                        applyWorkspaceRowFilters();
                        showAlert('Workspace email user flags updated.', 'success');
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to update user flags.', 'error');
                    }).finally(function () {
                        flagBtn.disabled = false;
                    });
                });
                userRowsWrap.addEventListener('keydown', function (event) {
                    const trigger = event.target.closest('.metis-workspace-actions-open');
                    if (trigger) {
                        const row = trigger.closest('.metis-workspace-user-row');
                        if (!row) return;
                        if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            openWorkspaceActionMenu(row, 'first');
                            return;
                        }
                        if (event.key === 'ArrowUp') {
                            event.preventDefault();
                            openWorkspaceActionMenu(row, 'last');
                            return;
                        }
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            closeWorkspaceActionMenus({ restoreFocus: row });
                        }
                        return;
                    }
                    const item = event.target.closest('.metis-workspace-actions-menu [role="menuitem"]');
                    if (!item) return;
                    const menu = item.closest('.metis-workspace-actions-menu');
                    const row = item.closest('.metis-workspace-user-row');
                    const items = workspaceActionItems(menu);
                    const index = items.indexOf(item);
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeWorkspaceActionMenus({ restoreFocus: row });
                        return;
                    }
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        const next = items[(index + 1) % items.length];
                        if (next && typeof next.focus === 'function') next.focus();
                        return;
                    }
                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        const prev = items[(index - 1 + items.length) % items.length];
                        if (prev && typeof prev.focus === 'function') prev.focus();
                    }
                });
                document.addEventListener('click', function (event) {
                    if (event.target.closest('.metis-workspace-user-row.is-menu-open')) return;
                    closeWorkspaceActionMenus();
                });
                document.addEventListener('keydown', function (event) {
                    if (event.key !== 'Escape') return;
                    const openRow = userRowsWrap.querySelector('.metis-workspace-user-row.is-menu-open');
                    if (!openRow) return;
                    closeWorkspaceActionMenus({ restoreFocus: openRow });
                });
                userRowsWrap.addEventListener('click', function (event) {
                    const driveBtn = event.target.closest('.metis-workspace-create-drive-folder-btn');
                    if (driveBtn) {
                        event.preventDefault();
                        event.stopPropagation();
                        const personPid = String(driveBtn.dataset.personPid || '').trim();
                        if (!personPid) {
                            showAlert('Linked PID is required to create a Drive folder.', 'error');
                            return;
                        }
                        driveBtn.disabled = true;
                        post('metis_people_attach_drive_folder', { pid: personPid })
                            .then(function (data) {
                                const created = !!(data && Number(data.created) === 1);
                                showAlert(created ? 'Drive folder created and linked.' : 'Drive folder linked.', 'success');
                            })
                            .catch(function (err) {
                                showAlert((err && err.message) ? err.message : 'Failed to create Drive folder.', 'error');
                            })
                            .finally(function () {
                                driveBtn.disabled = false;
                            });
                        return;
                    }
                    const deleteBtn = event.target.closest('.metis-workspace-delete-user');
                    if (!deleteBtn) return;
                    event.preventDefault();
                    event.stopPropagation();
                    const workspaceUserId = String(deleteBtn.dataset.workspaceUserId || '').trim();
                    const userEmail = String(deleteBtn.dataset.userEmail || '').trim();
                    if (!workspaceUserId) return;
                    confirmAction('Delete workspace account ' + userEmail + '? This cannot be undone.', {
                        title: 'Delete Workspace Account',
                        confirmLabel: 'Delete',
                        tone: 'danger'
                    }).then(function (confirmed) {
                        if (!confirmed) return;
                        if (deleteBtn.dataset.busy === '1') return;
                        deleteBtn.dataset.busy = '1';
                        deleteBtn.disabled = true;
                        post('metis_people_workspace_delete_user', {
                            workspace_user_id: workspaceUserId
                        }).then(function () {
                            const row = deleteBtn.closest('.metis-workspace-user-row');
                            if (row) row.remove();
                            applyWorkspaceRowFilters();
                            refreshWorkspaceUserKpi();
                            showAlert('Workspace account deleted.', 'success');
                        }).catch(function (err) {
                            showAlert(err.message || 'Failed to delete workspace account.', 'error');
                        }).finally(function () {
                            deleteBtn.dataset.busy = '0';
                            deleteBtn.disabled = false;
                        });
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
            const groupPermTemplate = document.getElementById('metis-workspace-group-perm-template');
            const groupPermTemplateApply = document.getElementById('metis-workspace-group-perm-template-apply');
            const groupPermTemplateCapture = document.getElementById('metis-workspace-group-perm-template-capture');
            const groupPermTemplateStatus = document.getElementById('metis-workspace-group-perm-template-status');
            const advTabButtons = Array.from((groupForm || document).querySelectorAll('.metis-workspace-adv-tab-btn'));
            const advTabPanels = Array.from((groupForm || document).querySelectorAll('.metis-workspace-adv-tab-panel'));
            const externalTabButton = document.getElementById('metis-workspace-group-tab-external');
            const membersGrid = document.getElementById('metis-workspace-members-grid');
            const externalGrid = document.getElementById('metis-workspace-external-grid');
            const groupRowsWrap = document.getElementById('metis-workspace-group-rows');
            const externalEmailInput = document.getElementById('metis-workspace-external-email');
            const externalRoleSelect = document.getElementById('metis-workspace-external-role');
            const externalAddBtn = document.getElementById('metis-workspace-external-add-btn');
            let permissionTemplates = {};
            let currentGroupPermissionsFull = {};
            const advancedFieldDefs = [
                { id: 'metis-workspace-adv-reply-to', key: 'replyTo', type: 'select' },
                { id: 'metis-workspace-adv-enable-collaborative-inbox', key: 'enableCollaborativeInbox', type: 'bool' },
                { id: 'metis-workspace-adv-default-sender', key: 'defaultSender', type: 'select' },
                { id: 'metis-workspace-adv-who-can-moderate-members', key: 'whoCanModerateMembers', type: 'select' },
                { id: 'metis-workspace-adv-who-can-contact-owner', key: 'whoCanContactOwner', type: 'select' },
                { id: 'metis-workspace-adv-allow-external-members', key: 'allowExternalMembers', type: 'bool' },
                { id: 'metis-workspace-adv-who-can-post-message', key: 'whoCanPostMessage', type: 'select' },
                { id: 'metis-workspace-adv-who-can-moderate-content', key: 'whoCanModerateContent', type: 'select' },
                { id: 'metis-workspace-adv-who-can-assist-content', key: 'whoCanAssistContent', type: 'select' },
                { id: 'metis-workspace-adv-who-can-enter-free-form-tags', key: 'whoCanEnterFreeFormTags', type: 'select' },
                { id: 'metis-workspace-adv-allow-web-posting', key: 'allowWebPosting', type: 'bool' },
                { id: 'metis-workspace-adv-members-can-post-as-group', key: 'membersCanPostAsTheGroup', type: 'bool' },
                { id: 'metis-workspace-adv-auto-reply-group-members', key: 'autoReplyToGroupMembers', type: 'bool' },
                { id: 'metis-workspace-adv-auto-reply-nonmembers-inside', key: 'autoReplyGroupMessage', type: 'bool' },
                { id: 'metis-workspace-adv-auto-reply-members-outside', key: 'autoReplyToMembers', type: 'bool' },
                { id: 'metis-workspace-adv-auto-reply-nonmembers-outside', key: 'autoReplyToNonMembers', type: 'bool' },
                { id: 'metis-workspace-adv-default-message-deny-text', key: 'autoReplyText', type: 'text' },
            ];
            const advancedDefaults = {
                replyTo: 'REPLY_TO_IGNORE',
                enableCollaborativeInbox: 'false',
                defaultSender: 'DEFAULT_SELF',
                whoCanModerateMembers: 'OWNERS_AND_MANAGERS',
                whoCanContactOwner: 'ALL_IN_DOMAIN_CAN_CONTACT',
                allowExternalMembers: 'false',
                whoCanPostMessage: 'ALL_MEMBERS_CAN_POST',
                whoCanModerateContent: 'OWNERS_ONLY',
                whoCanAssistContent: 'ALL_MEMBERS',
                whoCanEnterFreeFormTags: 'NONE',
                allowWebPosting: 'true',
                membersCanPostAsTheGroup: 'false',
                autoReplyToGroupMembers: 'false',
                autoReplyGroupMessage: 'false',
                autoReplyToMembers: 'false',
                autoReplyToNonMembers: 'false',
                autoReplyText: '',
            };
            function setGroupTemplateStatus(text) {
                if (!groupPermTemplateStatus) return;
                groupPermTemplateStatus.textContent = String(text || '').trim();
            }
            function trimWorkspaceGroupSettingsUi() {
                if (!groupForm) return;
                const keepAdvancedIds = new Set([
                    'metis-workspace-adv-enable-collaborative-inbox',
                    'metis-workspace-adv-allow-external-members',
                    'metis-workspace-adv-who-can-post-message',
                    'metis-workspace-adv-who-can-moderate-members',
                    'metis-workspace-adv-who-can-contact-owner',
                    'metis-workspace-adv-allow-web-posting',
                    'metis-workspace-adv-who-can-assist-content',
                    'metis-workspace-adv-who-can-enter-free-form-tags',
                    'metis-workspace-adv-who-can-moderate-content',
                    'metis-workspace-adv-members-can-post-as-group',
                    'metis-workspace-adv-default-sender',
                    'metis-workspace-adv-reply-to',
                    'metis-workspace-adv-auto-reply-group-members',
                    'metis-workspace-adv-auto-reply-nonmembers-inside',
                    'metis-workspace-adv-auto-reply-members-outside',
                    'metis-workspace-adv-auto-reply-nonmembers-outside',
                    'metis-workspace-adv-default-message-deny-text',
                ]);
                const labelOverrides = {
                    'metis-workspace-adv-enable-collaborative-inbox': 'Collaborative inbox',
                    'metis-workspace-adv-who-can-moderate-members': 'Manage members',
                    'metis-workspace-adv-who-can-contact-owner': 'Contact group owners',
                    'metis-workspace-adv-allow-web-posting': 'Allow email posting',
                    'metis-workspace-adv-who-can-assist-content': 'Who can reply privately',
                    'metis-workspace-adv-who-can-enter-free-form-tags': 'Who can attach files',
                    'metis-workspace-adv-who-can-moderate-content': 'Who can moderate',
                    'metis-workspace-adv-members-can-post-as-group': 'Who can post as group',
                    'metis-workspace-adv-default-message-deny-text': 'Auto-reply text',
                };
                const advancedPanel = groupForm.querySelector('.metis-tab-panel[data-tab-panel="group-advanced"]');
                if (advancedPanel) {
                    const fields = Array.from(advancedPanel.querySelectorAll('.metis-field'));
                    fields.forEach(function (field) {
                        const controls = Array.from(field.querySelectorAll('input[id], select[id], textarea[id]'));
                        if (!controls.length) return;
                        const keep = controls.some(function (ctrl) {
                            const id = String(ctrl.id || '').trim();
                            if (!id) return false;
                            return !id.startsWith('metis-workspace-adv-') || keepAdvancedIds.has(id);
                        });
                        field.style.display = keep ? '' : 'none';
                    });
                    Object.keys(labelOverrides).forEach(function (id) {
                        const field = advancedPanel.querySelector('#' + id);
                        if (!field) return;
                        const label = field.closest('label') || advancedPanel.querySelector('label[for="' + id + '"]');
                        if (!label) return;
                        if (field.type === 'checkbox' && label.contains(field)) {
                            label.innerHTML = '';
                            label.appendChild(field);
                            label.appendChild(document.createTextNode(' ' + labelOverrides[id]));
                        } else {
                            label.textContent = labelOverrides[id];
                        }
                    });
                    const panelOrder = ['adv-general', 'adv-moderation', 'adv-privacy', 'adv-posting', 'adv-email'];
                    const panelNames = {
                        'adv-general': 'General',
                        'adv-moderation': 'Membership',
                        'adv-privacy': 'Membership',
                        'adv-posting': 'Posting',
                        'adv-email': 'Email',
                    };
                    const visiblePanels = [];
                    const seenTabLabels = new Set();
                    panelOrder.forEach(function (name) {
                        const panel = advancedPanel.querySelector('.metis-workspace-adv-tab-panel[data-adv-tab-panel="' + name + '"]');
                        const btn = advancedPanel.querySelector('.metis-workspace-adv-tab-btn[data-adv-tab-target="' + name + '"]');
                        if (btn) btn.textContent = panelNames[name] || btn.textContent;
                        if (!panel || !btn) return;
                        const hasVisibleFields = Array.from(panel.querySelectorAll('.metis-field')).some(function (field) {
                            return field.style.display !== 'none';
                        });
                        const label = String(btn.textContent || '').trim().toLowerCase();
                        const duplicateLabel = label !== '' && seenTabLabels.has(label);
                        const show = hasVisibleFields && !duplicateLabel;
                        panel.style.display = show ? '' : 'none';
                        btn.style.display = show ? '' : 'none';
                        if (show) {
                            if (label !== '') seenTabLabels.add(label);
                            visiblePanels.push({ panel: panel, btn: btn, name: name });
                        }
                    });
                    if (visiblePanels.length) {
                        let active = visiblePanels.find(function (entry) { return entry.btn.classList.contains('is-active'); });
                        if (!active) active = visiblePanels[0];
                        advTabButtons.forEach(function (b) { b.classList.toggle('is-active', b === active.btn); });
                        advTabPanels.forEach(function (p) { p.classList.toggle('is-active', p === active.panel); });
                    }
                }
                const permPanel = groupForm.querySelector('.metis-tab-panel[data-tab-panel="group-permissions"]');
                if (permPanel) {
                    [
                        'metis-workspace-group-perm-join',
                        'metis-workspace-group-perm-view',
                        'metis-workspace-group-perm-post',
                        'metis-workspace-group-perm-external',
                    ].forEach(function (id) {
                        const el = permPanel.querySelector('#' + id);
                        if (!el) return;
                        const field = el.closest('.metis-field');
                        if (field) field.style.display = 'none';
                    });
                }
            }
            function renderWorkspaceMembersGrid(users) {
                if (!membersGrid) return;
                membersGrid.innerHTML = '';
                const list = Array.isArray(users) ? users : [];
                if (!list.length) {
                    membersGrid.innerHTML = '<div class="metis-muted" style="padding:10px 12px;">No workspace users available.</div>';
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
                        '<div class="metis-workspace-members-user"><strong>' + name.replace(/</g, '&lt;') + '</strong><span class="metis-muted">' + email.replace(/</g, '&lt;') + '</span></div>' +
                        '<div><select class="metis-select metis-workspace-members-role" data-workspace-user-id="' + workspaceUserId + '">' +
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
                    externalGrid.innerHTML = '<div class="metis-muted" style="padding:10px 12px;">No external users in this group.</div>';
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
                    const title = resolvedName || email;
                    const subtitle = resolvedName
                        ? email + (contactCid ? ' (' + contactCid + ')' : '')
                        : email;
                    row.innerHTML =
                        '<div class="metis-workspace-members-include-wrap"><input type="checkbox" class="metis-workspace-members-include" data-external-email="' + email.replace(/"/g, '&quot;') + '" checked></div>' +
                        '<div class="metis-workspace-members-user"><strong>' + title.replace(/</g, '&lt;') + '</strong><span class="metis-muted">' + subtitle.replace(/</g, '&lt;') + '</span></div>' +
                        '<div><select class="metis-select metis-workspace-members-role" data-external-email="' + email.replace(/"/g, '&quot;') + '">' +
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
            function loadGroupPermissionTemplates() {
                return post('metis_people_workspace_get_group_permission_templates', {}).then(function (data) {
                    permissionTemplates = (data && typeof data.templates === 'object' && data.templates) ? data.templates : {};
                    const board = permissionTemplates.board || {};
                    const supplies = permissionTemplates.supplies || {};
                    const labels = [];
                    if (board.captured_at) labels.push('Board');
                    if (supplies.captured_at) labels.push('Supplies');
                    setGroupTemplateStatus(labels.length ? ('Captured: ' + labels.join(', ')) : 'No templates captured yet.');
                }).catch(function () {
                    permissionTemplates = {};
                    setGroupTemplateStatus('Templates unavailable.');
                });
            }
            function applyAdvancedPermissions(payload) {
                const source = (payload && typeof payload === 'object') ? payload : {};
                function getFirstMatchingValue(obj, keys) {
                    if (!obj || typeof obj !== 'object' || !Array.isArray(keys) || !keys.length) return undefined;
                    for (let i = 0; i < keys.length; i++) {
                        const key = String(keys[i] || '').trim();
                        if (!key) continue;
                        if (Object.prototype.hasOwnProperty.call(obj, key)) return obj[key];
                    }
                    const lowerMap = {};
                    Object.keys(obj).forEach(function (k) {
                        lowerMap[String(k).toLowerCase()] = k;
                    });
                    for (let i = 0; i < keys.length; i++) {
                        const lower = String(keys[i] || '').trim().toLowerCase();
                        if (!lower) continue;
                        if (Object.prototype.hasOwnProperty.call(lowerMap, lower)) {
                            return obj[lowerMap[lower]];
                        }
                    }
                    return undefined;
                }
                advancedFieldDefs.forEach(function (field) {
                    const el = document.getElementById(field.id);
                    if (!el) return;
                    let raw = getFirstMatchingValue(source, [field.key]);
                    if ((raw === undefined || raw === null || String(raw).trim() === '') && source.settings && typeof source.settings === 'object') {
                        raw = getFirstMatchingValue(source.settings, [field.key]);
                    }
                    if (field.key === 'autoReplyText') {
                        const aliases = [
                            'autoReplyText',
                            'autoReplyMessage',
                            'auto_reply_text',
                            'auto_reply_message',
                            'defaultMessageDenyNotificationText',
                        ];
                        if (raw === undefined || raw === null || String(raw).trim() === '') raw = getFirstMatchingValue(source, aliases);
                        if ((raw === undefined || raw === null || String(raw).trim() === '') && source.settings && typeof source.settings === 'object') {
                            raw = getFirstMatchingValue(source.settings, aliases);
                        }
                    }
                    if (field.type === 'bool') {
                        el.checked = String(raw || 'false') === 'true' || raw === true || String(raw) === '1';
                        return;
                    }
                    if (raw === undefined || raw === null || String(raw).trim() === '') {
                        if (el.tagName === 'SELECT') {
                            if (el.options.length > 0) el.selectedIndex = 0;
                        } else {
                            el.value = '';
                        }
                        return;
                    }
                    if (el.tagName === 'SELECT') {
                        const targetValue = String(raw);
                        const hasValue = Array.from(el.options || []).some(function (opt) { return String(opt.value) === targetValue; });
                        if (!hasValue) {
                            const opt = document.createElement('option');
                            opt.value = targetValue;
                            opt.textContent = targetValue;
                            el.appendChild(opt);
                        }
                        el.value = targetValue;
                    } else {
                        el.value = String(raw);
                    }
                });
            }
            function collectAdvancedPermissions() {
                const out = {};
                advancedFieldDefs.forEach(function (field) {
                    const el = document.getElementById(field.id);
                    if (!el) return;
                    if (field.type === 'bool') {
                        out[field.key] = el.checked ? 'true' : 'false';
                        return;
                    }
                    out[field.key] = String(el.value || '').trim();
                });
                return out;
            }
            function resetAdvancedTabs() {
                advTabButtons.forEach(function (btn) {
                    btn.classList.toggle('is-active', String(btn.dataset.advTabTarget || '') === 'adv-general');
                });
                advTabPanels.forEach(function (panel) {
                    panel.classList.toggle('is-active', String(panel.dataset.advTabPanel || '') === 'adv-general');
                });
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
                currentGroupPermissionsFull = {};
                applyAdvancedPermissions({});
                resetAdvancedTabs();
                if (groupPermTemplate) groupPermTemplate.value = '';
                setGroupTemplateStatus('');
                renderWorkspaceMembersGrid([]);
                renderExternalMembersGrid([]);
                if (externalTabButton) externalTabButton.textContent = 'External Users';
                const tabButtons = Array.from((groupForm || document).querySelectorAll('.metis-tab-btn'));
                const tabPanels = Array.from((groupForm || document).querySelectorAll('.metis-tab-panel'));
                tabButtons.forEach(function (btn) { btn.classList.toggle('is-active', String(btn.dataset.tabTarget || '') === 'group-general'); });
                tabPanels.forEach(function (panel) { panel.classList.toggle('is-active', String(panel.dataset.tabPanel || '') === 'group-general'); });
                trimWorkspaceGroupSettingsUi();
            }
            function openGroupEditor(groupId) {
                if (!groupModal || !groupForm) return;
                resetGroupForm();
                if (!groupId || groupId < 1) {
                    loadGroupPermissionTemplates();
                    openModal(groupModal);
                    return;
                }
                if (groupIdEl) groupIdEl.value = String(groupId);
                if (groupTitle) groupTitle.textContent = 'Workspace Group Editor';
                if (groupDeleteBtn) groupDeleteBtn.style.display = '';
                openModal(groupModal);
                loadGroupPermissionTemplates();
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
                    const fromServer = (data && data.permissions_full && typeof data.permissions_full === 'object') ? data.permissions_full : {};
                    currentGroupPermissionsFull = Object.assign({}, advancedDefaults, fromServer);
                    applyAdvancedPermissions(currentGroupPermissionsFull);
                    const warning = String((data && data.load_warning) || '').trim();
                    if (warning) {
                        showAlert('Advanced settings warning: ' + warning, 'warning');
                    }
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
            advTabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const target = String(btn.dataset.advTabTarget || '').trim();
                    if (!target) return;
                    advTabButtons.forEach(function (b) {
                        b.classList.toggle('is-active', String(b.dataset.advTabTarget || '') === target);
                    });
                    advTabPanels.forEach(function (panel) {
                        panel.classList.toggle('is-active', String(panel.dataset.advTabPanel || '') === target);
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
                    if (groupForm.dataset.busy === '1') return;
                    groupForm.dataset.busy = '1';
                    const submitButton = groupForm.querySelector('button[type="submit"]');
                    if (submitButton) submitButton.disabled = true;
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
                    const advancedPayload = collectAdvancedPermissions();
                    if (typeof advancedPayload.whoCanJoin === 'string' && advancedPayload.whoCanJoin !== '') {
                        permissions.whoCanJoin = advancedPayload.whoCanJoin;
                    }
                    if (typeof advancedPayload.whoCanViewMembership === 'string' && advancedPayload.whoCanViewMembership !== '') {
                        permissions.whoCanViewMembership = advancedPayload.whoCanViewMembership;
                    }
                    if (typeof advancedPayload.whoCanPostMessage === 'string' && advancedPayload.whoCanPostMessage !== '') {
                        permissions.whoCanPostMessage = advancedPayload.whoCanPostMessage;
                    }
                    if (typeof advancedPayload.allowExternalMembers === 'string') {
                        permissions.allowExternalMembers = advancedPayload.allowExternalMembers;
                    }
                    const permissionsFull = Object.assign({}, (currentGroupPermissionsFull && typeof currentGroupPermissionsFull === 'object') ? currentGroupPermissionsFull : {}, advancedPayload, permissions);
                    let persistedGroupId = parseInt(String(groupIdEl ? groupIdEl.value : '0'), 10);
                    if (!Number.isFinite(persistedGroupId) || persistedGroupId < 1) persistedGroupId = 0;
                    post('metis_people_workspace_save_group', {
                        group_id: groupIdEl ? groupIdEl.value : '0',
                        group_name: groupNameEl ? groupNameEl.value : '',
                        group_email: groupEmailEl ? groupEmailEl.value : '',
                        description: groupDescEl ? groupDescEl.value : ''
                    }).then(function (saveData) {
                        const returnedGroupId = parseInt(String((saveData && saveData.group_id) || '0'), 10);
                        if (Number.isFinite(returnedGroupId) && returnedGroupId > 0) {
                            persistedGroupId = returnedGroupId;
                            if (groupIdEl) groupIdEl.value = String(returnedGroupId);
                        }
                        if (persistedGroupId < 1) {
                            throw new Error('Failed to resolve group id after save.');
                        }
                        return post('metis_people_workspace_save_group_members_bulk', {
                            group_id: String(persistedGroupId),
                            members: JSON.stringify(selectedMembers)
                        });
                    }).then(function () {
                        return post('metis_people_workspace_save_group_permissions', {
                            group_id: String(persistedGroupId),
                            permissions: JSON.stringify(permissions),
                            permissions_full: JSON.stringify(permissionsFull)
                        });
                    }).then(function () {
                        showAlert('Workspace group saved.', 'success');
                        closeModal(groupModal);
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to save group.', 'error');
                    }).finally(function () {
                        groupForm.dataset.busy = '0';
                        if (submitButton) submitButton.disabled = false;
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
                            return !!(emailNode && String((emailNode.querySelector('.metis-workspace-members-user .metis-muted') || {}).textContent || '').trim().toLowerCase() === email);
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
                    confirmAction('Delete this workspace group? This cannot be undone.', {
                        title: 'Delete Workspace Group',
                        confirmLabel: 'Delete',
                        tone: 'danger'
                    }).then(function (confirmed) {
                        if (!confirmed) return;
                        if (groupDeleteBtn.dataset.busy === '1') return;
                        groupDeleteBtn.dataset.busy = '1';
                        groupDeleteBtn.disabled = true;
                        post('metis_people_workspace_delete_group', { group_id: String(gid) })
                            .then(function () {
                                showAlert('Workspace group deleted.', 'success');
                                closeModal(groupModal);
                            })
                            .catch(function (err) {
                                showAlert(err.message || 'Failed to delete group.', 'error');
                            })
                            .finally(function () {
                                groupDeleteBtn.dataset.busy = '0';
                                groupDeleteBtn.disabled = false;
                            });
                    });
                });
            }
            if (groupPermTemplateApply) {
                groupPermTemplateApply.addEventListener('click', function () {
                    const key = String(groupPermTemplate ? groupPermTemplate.value : '').trim();
                    if (!key || !permissionTemplates[key]) {
                        showAlert('Select a template first.', 'error');
                        return;
                    }
                    const selected = permissionTemplates[key] || {};
                    const full = (selected.permissions_full && typeof selected.permissions_full === 'object') ? selected.permissions_full : (selected.permissions || {});
                    currentGroupPermissionsFull = full;
                    setGroupPermissions(full);
                    applyAdvancedPermissions(currentGroupPermissionsFull);
                    setGroupTemplateStatus('Applied template: ' + key + '.');
                    showAlert('Template applied.', 'success');
                });
            }
            if (groupPermTemplateCapture) {
                groupPermTemplateCapture.addEventListener('click', function () {
                    groupPermTemplateCapture.disabled = true;
                    post('metis_people_workspace_capture_group_permission_templates', {})
                        .then(function (data) {
                            permissionTemplates = (data && typeof data.templates === 'object' && data.templates) ? data.templates : {};
                            const captured = Array.isArray(data && data.captured) ? data.captured : [];
                            const missing = Array.isArray(data && data.missing) ? data.missing : [];
                            let message = captured.length ? ('Captured template(s): ' + captured.join(', ') + '.') : 'No templates were captured.';
                            if (missing.length) {
                                message += ' Missing source groups: ' + missing.join(', ') + '.';
                            }
                            setGroupTemplateStatus(message);
                            showAlert(message, missing.length ? 'warning' : 'success');
                        })
                        .catch(function (err) {
                            showAlert(err.message || 'Failed to capture templates.', 'error');
                        })
                        .finally(function () {
                            groupPermTemplateCapture.disabled = false;
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
                    if (securityAction) securityAction.value = String(btn.dataset.actionType || 'reset_password');
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
};
