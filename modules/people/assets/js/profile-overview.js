window.MetisPeopleProfileModules = window.MetisPeopleProfileModules || {};

window.MetisPeopleProfileModules.initOverview = function (context) {
    const normalize = context.normalize;
    const showAlert = context.showAlert;
    const post = context.post;
    const openModal = context.openModal;
    const closeModal = context.closeModal;
    const applyZebraRows = context.applyZebraRows;
    function navigate(url) {
        var target = String(url || '').trim();
        if (!target) return false;
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            return Metis.navigation.go(target);
        }
        window.location.assign(target);
        return true;
    }
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
                navigate(href);
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
                navigate(href);
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
                navigate(personBaseUrl + '?pid=' + encodeURIComponent(pid));
            });

            document.addEventListener('click', function (event) {
                if (event.target === searchInput || resultsWrap.contains(event.target)) return;
                resultsWrap.style.display = 'none';
            });
        }
    }

    // Positions manager page behavior.
    const positionsPage = document.querySelector('.metis-people-positions-page');
    if (positionsPage) {
        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function positionListWrap(groupKey) {
            return positionsPage.querySelector('[data-position-list="' + groupKey + '"]');
        }

        function newPositionInput(groupKey) {
            return positionsPage.querySelector('[data-position-new-label="' + groupKey + '"]');
        }

        function renderPositionList(groupKey, options) {
            const wrap = positionListWrap(groupKey);
            if (!wrap) return;
            const list = Array.isArray(options) ? options : [];
            if (!list.length) {
                wrap.innerHTML = '<div class="mw-muted">No positions configured.</div>';
                return;
            }
            wrap.innerHTML = list.map(function (item) {
                const label = String(item.position_label || item.label || '').trim();
                const id = parseInt(item.id, 10) || 0;
                return '<span class="metis-people-position-pill">' +
                    '<span>' + escapeHtml(label) + '</span>' +
                    '<button type="button" title="Delete" data-position-delete="' + id + '" data-position-group="' + escapeHtml(groupKey) + '">x</button>' +
                '</span>';
            }).join('');
        }

        function loadPositions() {
            return post('metis_people_get_positions', {}).then(function (data) {
                const positions = (data && data.positions) || {};
                ['board', 'staff', 'volunteer'].forEach(function (groupKey) {
                    renderPositionList(groupKey, Array.isArray(positions[groupKey]) ? positions[groupKey] : []);
                });
            });
        }

        positionsPage.addEventListener('click', function (event) {
            const deleteButton = event.target.closest('[data-position-delete]');
            if (deleteButton) {
                const positionId = String(deleteButton.getAttribute('data-position-delete') || '').trim();
                if (!positionId) return;
                post('metis_people_delete_position', { position_id: positionId })
                    .then(loadPositions)
                    .then(function () { showAlert('Position removed.', 'success'); })
                    .catch(function (err) { showAlert(err.message || 'Failed to remove position.', 'error'); });
                return;
            }

            const addButton = event.target.closest('[data-position-add]');
            if (!addButton) return;
            const groupKey = String(addButton.getAttribute('data-position-add') || '').trim();
            const input = newPositionInput(groupKey);
            const label = String(input ? input.value : '').trim();
            if (!label) {
                if (input) input.focus();
                return;
            }
            addButton.disabled = true;
            post('metis_people_save_position', { group_key: groupKey, position_label: label })
                .then(function () {
                    if (input) input.value = '';
                    return loadPositions();
                })
                .then(function () { showAlert('Position saved.', 'success'); })
                .catch(function (err) { showAlert(err.message || 'Failed to save position.', 'error'); })
                .finally(function () { addButton.disabled = false; });
        });

        loadPositions().catch(function (err) {
            showAlert(err.message || 'Failed to load positions.', 'error');
        });
    }
};

window.MetisPeopleProfileModules.initPersonDetail = function (context) {
    const canManage = !!context.canManage;
    const activePersonId = String(context.activePersonId || '').trim();
    const showAlert = context.showAlert;
    const post = context.post;
    const openModal = context.openModal;
    const closeModal = context.closeModal;
    const collectRoleWindows = context.collectRoleWindows;
    const collectNotificationPrefs = context.collectNotificationPrefs;
    const currentPersonPid = context.currentPersonPid;
    const personPidInput = context.personPidInput || null;
    const syncPersonHeaderFromForm = context.syncPersonHeaderFromForm;

    const form = document.getElementById('metis-people-detail-form');
    const offboardPidEl = document.getElementById('metis-people-offboard-confirm');
    const saveAccessButton = document.getElementById('metis-people-save-access');
    const saveWorkspaceButton = document.getElementById('metis-people-save-workspace');
    const saveSecurityButton = document.getElementById('metis-people-save-security');
    const saveNotificationsButton = document.getElementById('metis-people-save-notifications');
    const boardToggle = document.getElementById('metis-people-board');
    const staffToggle = document.getElementById('metis-people-staff');
    const volunteerToggle = document.getElementById('metis-people-volunteer');
    const boardPositionWrap = document.getElementById('metis-people-board-position-wrap');
    const staffPositionWrap = document.getElementById('metis-people-staff-position-wrap');
    const volunteerPositionWrap = document.getElementById('metis-people-volunteer-position-wrap');
    const boardPositionSelect = document.getElementById('metis-people-board-position');
    const staffPositionSelect = document.getElementById('metis-people-staff-position');
    const volunteerPositionSelect = document.getElementById('metis-people-volunteer-position');

    function syncPositionFieldVisibility() {
        const showBoard = !!(boardToggle && boardToggle.checked);
        const showStaff = !!(staffToggle && staffToggle.checked);
        const showVolunteer = !!(volunteerToggle && volunteerToggle.checked);
        if (boardPositionWrap) {
            boardPositionWrap.hidden = !showBoard;
            boardPositionWrap.style.display = showBoard ? '' : 'none';
        }
        if (staffPositionWrap) {
            staffPositionWrap.hidden = !showStaff;
            staffPositionWrap.style.display = showStaff ? '' : 'none';
        }
        if (volunteerPositionWrap) {
            volunteerPositionWrap.hidden = !showVolunteer;
            volunteerPositionWrap.style.display = showVolunteer ? '' : 'none';
        }
        if (!showBoard && boardPositionSelect) boardPositionSelect.value = '';
        if (!showStaff && staffPositionSelect) staffPositionSelect.value = '';
        if (!showVolunteer && volunteerPositionSelect) volunteerPositionSelect.value = '';
    }
    if (boardToggle) boardToggle.addEventListener('change', syncPositionFieldVisibility);
    if (staffToggle) staffToggle.addEventListener('change', syncPositionFieldVisibility);
    if (volunteerToggle) volunteerToggle.addEventListener('change', syncPositionFieldVisibility);
    syncPositionFieldVisibility();

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function selectForGroup(groupKey) {
        if (groupKey === 'board') return boardPositionSelect;
        if (groupKey === 'staff') return staffPositionSelect;
        if (groupKey === 'volunteer') return volunteerPositionSelect;
        return null;
    }

    function renderPositionOptions(groupKey, options) {
        const select = selectForGroup(groupKey);
        if (!select) return;
        const current = String(select.value || '');
        const placeholderByGroup = {
            board: 'Select board position',
            staff: 'Select staff position',
            volunteer: 'Select volunteer position'
        };
        const list = Array.isArray(options) ? options.slice() : [];
        const labels = list.map(function (item) { return String(item.position_label || item.label || '').trim(); }).filter(Boolean);
        if (current && labels.indexOf(current) === -1) {
            list.push({ position_label: current, id: 0 });
        }
        select.innerHTML = '<option value="">' + (placeholderByGroup[groupKey] || 'Select position') + '</option>' + list.map(function (item) {
            const label = String(item.position_label || item.label || '').trim();
            if (!label) return '';
            const selected = current === label ? ' selected' : '';
            return '<option value="' + escapeHtml(label) + '"' + selected + '>' + escapeHtml(label) + '</option>';
        }).join('');
    }

    function fetchPositions() {
        return post('metis_people_get_positions', {}).then(function (data) {
            const positions = (data && data.positions) || {};
            ['board', 'staff', 'volunteer'].forEach(function (groupKey) {
                renderPositionOptions(groupKey, Array.isArray(positions[groupKey]) ? positions[groupKey] : []);
            });
            return positions;
        });
    }
    if (boardPositionSelect || staffPositionSelect || volunteerPositionSelect) {
        fetchPositions().catch(function () {});
    }

    function requestFormSubmit() {
        if (!form) return;
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
    }

    if (canManage && form && saveAccessButton) {
        saveAccessButton.addEventListener('click', requestFormSubmit);
    }
    if (canManage && form && saveSecurityButton) {
        saveSecurityButton.addEventListener('click', requestFormSubmit);
    }
    if (canManage && form && saveWorkspaceButton) {
        saveWorkspaceButton.addEventListener('click', requestFormSubmit);
    }
    if (canManage && form && saveNotificationsButton) {
        saveNotificationsButton.addEventListener('click', requestFormSubmit);
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
            const personBoardPosition = document.getElementById('metis-people-board-position');
            const personStaffPosition = document.getElementById('metis-people-staff-position');
            const personVolunteerPosition = document.getElementById('metis-people-volunteer-position');
            const personVolunteer = document.getElementById('metis-people-volunteer');
            const roles = Array.from(document.querySelectorAll('.metis-role-toggle:checked')).map(function (cb) { return cb.value; });
            const roleWindows = collectRoleWindows();
            const notificationPrefs = collectNotificationPrefs();
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
                board_position: personBoardPosition ? personBoardPosition.value : '',
                staff_position: personStaffPosition ? personStaffPosition.value : '',
                is_volunteer: personVolunteer && personVolunteer.checked ? '1' : '0',
                volunteer_position: personVolunteerPosition ? personVolunteerPosition.value : '',
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
            const pid = String(((document.getElementById('metis-people-offboard-confirm') || {}).dataset || {}).pid || '').trim();
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
            const pid = String(((document.getElementById('metis-people-offboard-confirm') || {}).dataset || {}).pid || '').trim();
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
            const pid = String(((document.getElementById('metis-people-offboard-confirm') || {}).dataset || {}).pid || '').trim();
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
            const pid = String(((document.getElementById('metis-people-offboard-confirm') || {}).dataset || {}).pid || '').trim();
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
};
