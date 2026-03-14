document.addEventListener('DOMContentLoaded', function () {
    const ajaxConfig = window.metisContactsAjax || null;

    const escapeHtml = Metis.util.escapeHtml;
    const showAlert = Metis.util.notify;
    const openModal = Metis.modal.open;
    const closeModal = Metis.modal.close;

    // ---------------------------------------------------------------------
    // Contacts list page
    // ---------------------------------------------------------------------
    const listRoot = document.querySelector('.metis-contacts');
    const rowsContainer = document.getElementById('metis-contact-rows');

    if (listRoot && rowsContainer) {
        const canManage = listRoot.dataset.canManage === '1';
        const pageLabel = document.getElementById('metis-contacts-page');
        const prevBtn = document.getElementById('metis-contacts-prev');
        const nextBtn = document.getElementById('metis-contacts-next');
        const searchInput = document.getElementById('metis-contacts-search');
        const sortButtons = Array.from(document.querySelectorAll('.metis-contacts-table .mw-sortable'));
        const alertBox = document.getElementById('metis-contacts-alert');
        const statsCards = document.querySelectorAll('.metis-contacts-stat-value');

        let currentPage = 1;
        const PER_PAGE = 25;
        let sortKey = 'name';
        let sortDir = 'asc';

        function normalize(value) { return Metis.util.normalize(value); }

        function getRows() {
            return Array.from(rowsContainer.querySelectorAll('.metis-contact-row'));
        }

        function updateStats() {
            if (statsCards.length < 3) return;
            const rows = getRows();
            const total = rows.length;
            const withDid = rows.filter(function (row) { return normalize(row.dataset.did) !== ''; }).length;
            const withoutDid = total - withDid;

            statsCards[0].textContent = total.toLocaleString();
            statsCards[1].textContent = withDid.toLocaleString();
            statsCards[2].textContent = withoutDid.toLocaleString();
        }

        function updateSortUi() {
            const labelMap = { name: 'Name', email: 'Email', updated: 'Updated' };
            sortButtons.forEach(function (button) {
                const key = button.dataset.sortKey;
                const label = labelMap[key] || key;
                button.textContent = label + ' ' + (key === sortKey ? (sortDir === 'asc' ? '▲' : '▼') : '▾');
            });
        }

        function applyFiltersAndPagination() {
            const rows = getRows();
            const query = normalize(searchInput ? searchInput.value : '');

            const filtered = rows.filter(function (row) {
                const firstName = normalize(row.dataset.firstName);
                const lastName = normalize(row.dataset.lastName);
                const email = normalize(row.dataset.email);
                const did = normalize(row.dataset.did);
                const phone = normalize(row.dataset.phone);
                const fullName = (firstName + ' ' + lastName).trim();

                if (!query) return true;
                return fullName.includes(query) || email.includes(query) || did.includes(query) || phone.includes(query);
            });

            filtered.sort(function (a, b) {
                const aName = normalize(a.dataset.nameSort);
                const bName = normalize(b.dataset.nameSort);
                const aEmail = normalize(a.dataset.email);
                const bEmail = normalize(b.dataset.email);
                const aUpdated = parseInt(a.dataset.updatedTs || '0', 10);
                const bUpdated = parseInt(b.dataset.updatedTs || '0', 10);

                if (sortKey === 'name') return sortDir === 'asc' ? aName.localeCompare(bName) : bName.localeCompare(aName);
                if (sortKey === 'email') return sortDir === 'asc' ? aEmail.localeCompare(bEmail) : bEmail.localeCompare(aEmail);
                if (sortKey === 'updated') return sortDir === 'asc' ? aUpdated - bUpdated : bUpdated - aUpdated;
                return 0;
            });

            rows.forEach(function (row) {
                row.style.display = 'none';
                rowsContainer.appendChild(row);
            });

            filtered.forEach(function (row) {
                rowsContainer.appendChild(row);
            });

            const totalPages = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
            if (currentPage > totalPages) currentPage = totalPages;

            filtered.forEach(function (row, index) {
                const start = (currentPage - 1) * PER_PAGE;
                const end = start + PER_PAGE;
                row.style.display = index >= start && index < end ? '' : 'none';
            });

            const visibleRows = filtered.filter(function (_, index) {
                const start = (currentPage - 1) * PER_PAGE;
                const end = start + PER_PAGE;
                return index >= start && index < end;
            });

            visibleRows.forEach(function (row, index) {
                row.classList.remove('metis-row-odd', 'metis-row-even');
                row.classList.add(index % 2 === 0 ? 'metis-row-even' : 'metis-row-odd');
            });

            if (pageLabel) pageLabel.textContent = 'Page ' + currentPage + ' of ' + totalPages;
            if (prevBtn) prevBtn.disabled = currentPage <= 1;
            if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
            updateSortUi();

            const emptyStateId = 'metis-contacts-empty-js';
            const existingEmpty = document.getElementById(emptyStateId);
            const existingStaticEmpty = document.getElementById('metis-contacts-empty');

            if (filtered.length === 0) {
                if (!existingEmpty) {
                    const emptyRow = document.createElement('div');
                    emptyRow.id = emptyStateId;
                    emptyRow.className = 'mw-premium-row';
                    emptyRow.innerHTML = '<div class="mw-premium-cell mw-muted">No contacts match your search.</div>';
                    rowsContainer.appendChild(emptyRow);
                }
                if (existingStaticEmpty) existingStaticEmpty.style.display = 'none';
            } else if (existingEmpty) {
                existingEmpty.remove();
                if (existingStaticEmpty) existingStaticEmpty.remove();
            } else if (existingStaticEmpty) {
                existingStaticEmpty.remove();
            }
        }

        function bindRowNavigation() {
            rowsContainer.addEventListener('click', function (event) {
                const row = event.target.closest('.metis-contact-row');
                if (!row) return;
                const href = row.dataset.href;
                if (href) window.location.href = href;
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                currentPage = 1;
                applyFiltersAndPagination();
            });
        }

        sortButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const key = button.dataset.sortKey;
                if (!key) return;
                if (sortKey === key) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortKey = key;
                    sortDir = key === 'updated' ? 'desc' : 'asc';
                }
                currentPage = 1;
                applyFiltersAndPagination();
            });
        });

        if (prevBtn) prevBtn.addEventListener('click', function () { currentPage -= 1; applyFiltersAndPagination(); });
        if (nextBtn) nextBtn.addEventListener('click', function () { currentPage += 1; applyFiltersAndPagination(); });

        function formatUpdated(ts) {
            if (!ts) return '—';
            const date = new Date(ts * 1000);
            return date.toLocaleString();
        }

        function buildRowHtml(contact) {
            const firstName = contact.first_name || '';
            const lastName = contact.last_name || '';
            const fullName = (firstName + ' ' + lastName).trim() || '(No name)';

            return '<div class="mw-premium-cell metis-contact-name-cell"><div class="metis-contact-name">' + escapeHtml(fullName) + '</div></div>' +
                '<div class="mw-premium-cell">' + escapeHtml(contact.email || '') + '</div>' +
                '<div class="mw-premium-cell">' + escapeHtml(formatUpdated(contact.updated_ts || 0)) + '</div>';
        }

        function upsertRow(contact) {
            const cid = String(contact.cid || '');
            const existing = rowsContainer.querySelector('.metis-contact-row[data-cid="' + cid + '"]');
            const firstName = contact.first_name || '';
            const lastName = contact.last_name || '';

            const target = existing || document.createElement('div');
            target.className = 'mw-premium-row metis-contact-row';
            target.dataset.cid = cid;
            target.dataset.firstName = firstName;
            target.dataset.lastName = lastName;
            target.dataset.email = contact.email || '';
            target.dataset.phone = contact.phone || '';
            target.dataset.did = contact.did || '';
            target.dataset.updatedTs = String(contact.updated_ts || 0);
            target.dataset.nameSort = (lastName + ' ' + firstName).trim().toLowerCase();
            target.dataset.href = contact.detail_url || '';
            target.classList.toggle('metis-contact-is-donor', !!(contact.did || '').trim());
            target.innerHTML = buildRowHtml(contact);

            if (!existing) rowsContainer.appendChild(target);

            applyFiltersAndPagination();
            updateStats();
        }

        if (canManage) {
            const modal = document.getElementById('metis-contacts-modal');
            const newBtn = document.getElementById('metis-contact-new-btn');
            const cancelBtn = document.getElementById('metis-contact-cancel');
            const form = document.getElementById('metis-contact-form');
            const firstNameInput = document.getElementById('metis-contact-first-name');
            const lastNameInput = document.getElementById('metis-contact-last-name');
            const emailInput = document.getElementById('metis-contact-email');
            const phoneInput = document.getElementById('metis-contact-phone');

            function clearForm() {
                firstNameInput.value = '';
                lastNameInput.value = '';
                emailInput.value = '';
                phoneInput.value = '';
            }

            if (newBtn) newBtn.addEventListener('click', function () { clearForm(); openModal(modal); });
            if (cancelBtn) cancelBtn.addEventListener('click', function () { closeModal(modal); });
            if (modal) modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(modal); });

            if (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();

                    if (!ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) {
                        showAlert('Contacts AJAX is not configured.', 'error');
                        return;
                    }

                    const payload = new FormData();
                    payload.append('action', 'metis_contacts_save');
                    payload.append('nonce', ajaxConfig.nonce);
                    payload.append('first_name', firstNameInput.value || '');
                    payload.append('last_name', lastNameInput.value || '');
                    payload.append('email', emailInput.value || '');
                    payload.append('phone', phoneInput.value || '');

                    fetch(ajaxConfig.ajax_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: payload
                    })
                        .then(function (res) { return res.json(); })
                        .then(function (response) {
                            if (!response || !response.success || !response.data || !response.data.contact) {
                                throw new Error(response && response.data ? response.data : 'Save failed');
                            }
                            upsertRow(response.data.contact);
                            closeModal(modal);
                            clearForm();
                            showAlert('Contact added.', 'success');
                        })
                        .catch(function (err) {
                            showAlert(err.message || 'Save failed.', 'error');
                        });
                });
            }
        }

        if (canManage) {
            const dupBtn = document.getElementById('metis-review-duplicates-btn');
            const dupModal = document.getElementById('metis-duplicates-modal');
            const dupClose = document.getElementById('metis-duplicates-close');
            const dupCleanupBtn = document.getElementById('metis-duplicates-run-cleanup');
            const dupList = document.getElementById('metis-duplicates-list');
            const dupJsonEl = document.getElementById('metis-duplicates-json');
            const dupConfirmModal = document.getElementById('metis-duplicates-confirm-modal');
            const dupConfirmContent = document.getElementById('metis-duplicates-confirm-content');
            const dupConfirmCancel = document.getElementById('metis-duplicates-confirm-cancel');
            const dupConfirmMerge = document.getElementById('metis-duplicates-confirm-merge');
            let pendingMergeFn = null;
            let activeDraggedCid = '';
            let activeDragCancelled = false;

            function parseDuplicateGroups() {
                if (!dupJsonEl) return [];
                try {
                    const parsed = JSON.parse(dupJsonEl.textContent || '[]');
                    return Array.isArray(parsed) ? parsed : [];
                } catch (e) {
                    return [];
                }
            }

            function memberLabel(member) {
                const name = ((member.first_name || '') + ' ' + (member.last_name || '')).trim() || '(No name)';
                return name + ' - ' + (member.cid || '');
            }

            function refreshDuplicateVisuals(wrap, primaryCid, mergeCids) {
                if (!wrap) return;
                const mergeSet = new Set(mergeCids || []);
                const rows = Array.from(wrap.querySelectorAll('tbody tr'));
                rows.forEach(function (tr) {
                    const cid = tr.getAttribute('data-cid') || '';
                    const arrowCell = tr.querySelector('.metis-merge-direction');
                    const nameCell = tr.querySelector('td');
                    if (nameCell) {
                        Array.from(nameCell.querySelectorAll('.metis-dup-primary-pill')).forEach(function (pill) {
                            pill.remove();
                        });
                    }
                    tr.classList.remove('metis-duplicate-row-primary', 'metis-duplicate-row-merge');

                    if (!cid) return;
                    if (cid === primaryCid) {
                        tr.classList.add('metis-duplicate-row-primary');
                        if (nameCell) {
                            nameCell.insertAdjacentHTML('beforeend', ' <span class="metis-recommended-pill metis-dup-primary-pill">Primary</span>');
                        }
                        if (arrowCell) arrowCell.textContent = 'Primary';
                        return;
                    }
                    if (mergeSet.has(cid)) {
                        tr.classList.add('metis-duplicate-row-merge');
                        if (arrowCell) arrowCell.textContent = '→ ' + primaryCid;
                        return;
                    }
                    if (arrowCell) arrowCell.textContent = '';
                });
            }

            function renderDuplicateGroups() {
                if (!dupList) return;
                const groups = parseDuplicateGroups();
                if (!groups.length) {
                    dupList.innerHTML = '<div class="mw-muted">No potential duplicates found.</div>';
                    return;
                }

                dupList.innerHTML = '';
                groups.forEach(function (group, groupIndex) {
                    const members = Array.isArray(group.members) ? group.members : [];
                    if (members.length < 2) return;
                    const recommendedCid = (group.recommended_cid || '');
                    let primaryCid = recommendedCid || (members[0] && members[0].cid ? members[0].cid : '');
                    let selectedMergeCids = members
                        .map(function (m) { return m && m.cid ? m.cid : ''; })
                        .filter(function (cid) { return cid && cid !== primaryCid; });
                    let draggedCid = '';
                    const memberByCid = {};
                    members.forEach(function (m) { if (m && m.cid) memberByCid[m.cid] = m; });

                    const wrap = document.createElement('div');
                    wrap.className = 'metis-duplicate-group';
                    wrap.innerHTML = '<h4>Potential duplicate #' + (groupIndex + 1) + ' (' + escapeHtml(group.match || 'match') + ')' +
                        '<span class="metis-recommended-pill">Recommended Primary Highlighted</span></h4>' +
                        '<table><thead><tr><th>Name</th><th>Email</th><th>Donor ID</th><th>Total Donations</th><th>CID</th><th>Merge Direction</th></tr></thead><tbody>' +
                        members.map(function (m) {
                            const name = ((m.first_name || '') + ' ' + (m.last_name || '')).trim() || '(No name)';
                            const total = Number(m.donation_total || 0);
                            const isRecommended = recommendedCid && m.cid === recommendedCid;
                            return '<tr class="' + (isRecommended ? 'metis-duplicate-row-recommended' : '') + '" data-cid="' + escapeHtml(m.cid || '') + '">' +
                                '<td>' + escapeHtml(name) + '</td>' +
                                '<td>' + escapeHtml(m.email || '—') + '</td>' +
                                '<td>' + escapeHtml(m.did || '—') + '</td>' +
                                '<td>' + escapeHtml('$' + total.toFixed(2)) + '</td>' +
                                '<td>' + escapeHtml(m.cid || '') + '</td>' +
                                '<td class="metis-merge-direction"></td>' +
                                '</tr>';
                        }).join('') +
                        '</tbody></table>' +
                        '<div class="metis-duplicate-actions metis-duplicate-dnd">' +
                        '<div class="metis-dup-drop-wrap metis-dup-drop-keep-wrap">' +
                        '<div class="metis-dup-drop-label metis-dup-keep-label">Keep Profile</div>' +
                        '<div class="metis-dup-drop-zone metis-dup-drop-keep"></div>' +
                        '</div>' +
                        '<div class="metis-dup-drop-wrap metis-dup-drop-merge-wrap">' +
                        '<div class="metis-dup-drop-label metis-dup-merge-label">Merge Profiles</div>' +
                        '<div class="metis-dup-drop-zone metis-dup-drop-merge"></div>' +
                        '</div>' +
                        '<button type="button" class="mw-btn metis-dup-merge-btn">Merge</button>' +
                        '</div>';

                    const keepZone = wrap.querySelector('.metis-dup-drop-keep');
                    const mergeZone = wrap.querySelector('.metis-dup-drop-merge');
                    const mergeBtn = wrap.querySelector('.metis-dup-merge-btn');
                    const tableRows = Array.from(wrap.querySelectorAll('tbody tr'));

                    function renderDropChips() {
                        if (keepZone) {
                            const keepMember = memberByCid[primaryCid];
                            keepZone.innerHTML = keepMember
                                ? '<span class="metis-merge-chip is-primary" data-cid="' + escapeHtml(primaryCid) + '">' + escapeHtml(memberLabel(keepMember)) + '</span>'
                                : '<span class="mw-muted">Drop one profile here</span>';
                        }
                        if (mergeZone) {
                            mergeZone.innerHTML = selectedMergeCids.map(function (cid) {
                                const m = memberByCid[cid];
                                if (!m) return '';
                                return '<button type="button" class="metis-merge-chip is-active" data-cid="' + escapeHtml(cid) + '" title="Remove from merge">' +
                                    '<span class="metis-merge-chip-text">' + escapeHtml(memberLabel(m)) + '</span>' +
                                    '<span class="metis-merge-chip-remove" aria-hidden="true">×</span>' +
                                    '</button>';
                            }).join('');
                            if (!selectedMergeCids.length) {
                                mergeZone.innerHTML = '<span class="mw-muted">Drop profiles here</span>';
                            }
                        }

                        if (mergeZone) {
                            Array.from(mergeZone.querySelectorAll('.metis-merge-chip')).forEach(function (chipBtn) {
                                chipBtn.addEventListener('click', function () {
                                    const cid = chipBtn.getAttribute('data-cid') || '';
                                    if (!cid) return;
                                    selectedMergeCids = selectedMergeCids.filter(function (c) { return c !== cid; });
                                    renderDropChips();
                                    refreshDuplicateVisuals(wrap, primaryCid, selectedMergeCids);
                                });
                            });
                        }
                    }

                    function makeDropZoneInteractive(zoneEl, onDropCid) {
                        if (!zoneEl) return;
                        function isAllowedCid(cid) {
                            return !!(cid && memberByCid[cid]);
                        }
                        zoneEl.addEventListener('dragover', function (event) {
                            if (activeDragCancelled) return;
                            const transferCid = event.dataTransfer ? event.dataTransfer.getData('text/plain') : '';
                            const cid = transferCid || activeDraggedCid || draggedCid;
                            if (!isAllowedCid(cid)) {
                                zoneEl.classList.remove('is-drag-over');
                                if (event.dataTransfer) event.dataTransfer.dropEffect = 'none';
                                return;
                            }
                            event.preventDefault();
                            zoneEl.classList.add('is-drag-over');
                            if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
                        });
                        zoneEl.addEventListener('dragleave', function () {
                            zoneEl.classList.remove('is-drag-over');
                        });
                        zoneEl.addEventListener('drop', function (event) {
                            if (activeDragCancelled) return;
                            event.preventDefault();
                            zoneEl.classList.remove('is-drag-over');
                            const cid = event.dataTransfer ? event.dataTransfer.getData('text/plain') : '';
                            const appliedCid = cid || activeDraggedCid || draggedCid;
                            if (!isAllowedCid(appliedCid)) return;
                            onDropCid(appliedCid);
                        });
                    }

                    tableRows.forEach(function (tr) {
                        const cid = tr.getAttribute('data-cid') || '';
                        tr.setAttribute('draggable', 'true');
                        tr.classList.add('metis-dup-row-draggable');
                        tr.addEventListener('dragstart', function (event) {
                            draggedCid = cid;
                            activeDraggedCid = cid;
                            activeDragCancelled = false;
                            if (event.dataTransfer) {
                                event.dataTransfer.setData('text/plain', cid);
                                event.dataTransfer.effectAllowed = 'move';
                            }
                        });
                        tr.addEventListener('dragend', function () {
                            draggedCid = '';
                            activeDraggedCid = '';
                            activeDragCancelled = false;
                            if (keepZone) keepZone.classList.remove('is-drag-over');
                            if (mergeZone) mergeZone.classList.remove('is-drag-over');
                        });
                    });

                    wrap.addEventListener('dragenter', function (event) {
                        if (!activeDraggedCid || activeDragCancelled) return;
                        if (!memberByCid[activeDraggedCid]) {
                            activeDragCancelled = true;
                            draggedCid = '';
                            activeDraggedCid = '';
                            Array.from(document.querySelectorAll('.metis-dup-drop-zone.is-drag-over')).forEach(function (zoneEl) {
                                zoneEl.classList.remove('is-drag-over');
                            });
                            if (event.dataTransfer) event.dataTransfer.dropEffect = 'none';
                        }
                    });

                    makeDropZoneInteractive(keepZone, function (cid) {
                        if (!memberByCid[cid]) return;
                        if (primaryCid !== cid) {
                            const oldPrimary = primaryCid;
                            primaryCid = cid;
                            selectedMergeCids = selectedMergeCids.filter(function (c) { return c !== cid; });
                            if (oldPrimary && oldPrimary !== cid && selectedMergeCids.indexOf(oldPrimary) === -1) {
                                selectedMergeCids.push(oldPrimary);
                            }
                        }
                        renderDropChips();
                        refreshDuplicateVisuals(wrap, primaryCid, selectedMergeCids);
                    });

                    makeDropZoneInteractive(mergeZone, function (cid) {
                        if (!memberByCid[cid] || cid === primaryCid) return;
                        if (selectedMergeCids.indexOf(cid) === -1) {
                            selectedMergeCids.push(cid);
                        }
                        renderDropChips();
                        refreshDuplicateVisuals(wrap, primaryCid, selectedMergeCids);
                    });

                    renderDropChips();
                    refreshDuplicateVisuals(wrap, primaryCid, selectedMergeCids);

                    if (mergeBtn) {
                        mergeBtn.addEventListener('click', function () {
                            if (!ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) return;
                            const mergeCids = selectedMergeCids.filter(function (cid) { return cid !== primaryCid; });
                            if (!primaryCid || !mergeCids.length) {
                                showAlert('Select both contacts before merging.', 'warning');
                                return;
                            }
                            const primaryLabel = memberByCid[primaryCid] ? memberLabel(memberByCid[primaryCid]) : primaryCid;
                            const mergeItemsHtml = mergeCids.map(function (cid) {
                                return '<li>' + escapeHtml(memberByCid[cid] ? memberLabel(memberByCid[cid]) : cid) + '</li>';
                            }).join('');

                            if (dupConfirmContent) {
                                dupConfirmContent.innerHTML =
                                    '<div><strong>Primary profile (kept):</strong> ' + escapeHtml(primaryLabel) + '</div>' +
                                    '<div style="margin-top:8px;"><strong>Profiles to merge and delete:</strong><ul>' + mergeItemsHtml + '</ul></div>' +
                                    '<div style="margin-top:8px; color:#b91c1c; font-weight:700;">This action is permanent and cannot be undone.</div>';
                            }

                            pendingMergeFn = function () {
                                mergeBtn.disabled = true;
                                const payload = new FormData();
                                payload.append('action', 'metis_contacts_merge_duplicates');
                                payload.append('nonce', ajaxConfig.nonce);
                                payload.append('primary_cid', primaryCid);
                                payload.append('duplicate_cids', JSON.stringify(mergeCids));

                                fetch(ajaxConfig.ajax_url, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    body: payload
                                })
                                .then(function (res) { return res.json(); })
                                .then(function (response) {
                                    if (!response || !response.success) {
                                        throw new Error(response && response.data ? response.data : 'Merge failed.');
                                    }
                                    showAlert('Contacts merged successfully.', 'success');
                                    window.location.reload();
                                })
                                .catch(function (err) {
                                    mergeBtn.disabled = false;
                                    showAlert(err.message || 'Merge failed.', 'error');
                                });
                            };

                            openModal(dupConfirmModal);
                        });
                    }

                    dupList.appendChild(wrap);
                });
            }

            if (dupBtn) {
                dupBtn.addEventListener('click', function () {
                    renderDuplicateGroups();
                    openModal(dupModal);
                });
            }
            if (dupClose) dupClose.addEventListener('click', function () { closeModal(dupModal); });
            if (dupCleanupBtn) {
                dupCleanupBtn.addEventListener('click', function () {
                    if (!ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) return;
                    if (!window.confirm('Run one-time cleanup to consolidate older split system merge notes?')) return;
                    dupCleanupBtn.disabled = true;
                    const payload = new FormData();
                    payload.append('action', 'metis_contacts_cleanup_merge_notes');
                    payload.append('nonce', ajaxConfig.nonce);
                    fetch(ajaxConfig.ajax_url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: payload
                    })
                        .then(function (res) { return res.json(); })
                        .then(function (response) {
                            if (!response || !response.success || !response.data) {
                                throw new Error(response && response.data ? response.data : 'Cleanup failed.');
                            }
                            const data = response.data || {};
                            showAlert(
                                'Cleanup complete: consolidated ' + (data.groups_consolidated || 0) +
                                ', created ' + (data.notes_created || 0) +
                                ', deleted ' + (data.notes_deleted || 0) + '.',
                                'success'
                            );
                            window.location.reload();
                        })
                        .catch(function (err) {
                            dupCleanupBtn.disabled = false;
                            showAlert(err.message || 'Cleanup failed.', 'error');
                        });
                });
            }
            if (dupModal) dupModal.addEventListener('click', function (event) { if (event.target === dupModal) closeModal(dupModal); });
            if (dupConfirmCancel) {
                dupConfirmCancel.addEventListener('click', function () {
                    pendingMergeFn = null;
                    closeModal(dupConfirmModal);
                });
            }
            if (dupConfirmMerge) {
                dupConfirmMerge.addEventListener('click', function () {
                    const fn = pendingMergeFn;
                    pendingMergeFn = null;
                    closeModal(dupConfirmModal);
                    if (typeof fn === 'function') fn();
                });
            }
            if (dupConfirmModal) {
                dupConfirmModal.addEventListener('click', function (event) {
                    if (event.target === dupConfirmModal) {
                        pendingMergeFn = null;
                        closeModal(dupConfirmModal);
                    }
                });
            }
        }

        bindRowNavigation();
        applyFiltersAndPagination();
        updateStats();
    }

    // ---------------------------------------------------------------------
    // Contact detail page editor + notes
    // ---------------------------------------------------------------------
    const detailForm = document.getElementById('metis-contact-detail-form');
    if (detailForm) {
        const editModal = document.getElementById('metis-contact-detail-modal');
        const openEditBtn = document.getElementById('metis-open-contact-edit');
        const editCancelBtn = document.getElementById('metis-contact-detail-cancel');

        const noteModal = document.getElementById('metis-note-modal');
        const openNoteBtn = document.getElementById('metis-open-note-modal');
        const openNoteBtnPlus = document.getElementById('metis-open-note-modal-plus');
        const openAdditionalEmailPlus = document.getElementById('metis-open-additional-email-modal');
        const openRelationshipsPlus = document.getElementById('metis-open-relationships-plus');
        const openNewsletterPlus = document.getElementById('metis-open-newsletter-modal');
        const noteCancelBtn = document.getElementById('metis-note-cancel');
        const noteSaveBtn = document.getElementById('metis-note-save');
        const noteTextEl = document.getElementById('metis-contact-note-text');
        const notesList = document.getElementById('metis-notes-list');
        const noNotesEl = document.getElementById('metis-no-notes');
        const additionalEmailsView = document.getElementById('metis-additional-emails-view');
        const additionalEmailsSection = document.getElementById('metis-additional-emails-section');
        const relationshipsSection = document.getElementById('metis-relationships-section');
        const newsletterSection = document.getElementById('metis-newsletter-section');
        const newsletterView = document.getElementById('metis-newsletter-view');
        const inlineEditables = Array.from(document.querySelectorAll('.metis-inline-editable'));
        const pageTitle = document.querySelector('.mw-page-title');
        const tabButtons = Array.from(document.querySelectorAll('.metis-contact-tab'));
        const tabPanels = Array.from(document.querySelectorAll('.metis-contact-tab-panel'));

        const addEmailsHidden = document.getElementById('metis-detail-additional-emails-json');
        const relationshipsHidden = document.getElementById('metis-detail-relationships-json');
        const addEmailRows = document.getElementById('metis-additional-emails-rows');
        const addEmailBtn = document.getElementById('metis-add-email-row');
        const detailEmailInput = document.getElementById('metis-detail-email');
        const emailModal = document.getElementById('metis-email-modal');
        const emailModalInput = document.getElementById('metis-email-modal-input');
        const emailModalCancel = document.getElementById('metis-email-modal-cancel');
        const emailModalSave = document.getElementById('metis-email-modal-save');
        const emailModalError = document.getElementById('metis-email-modal-error');
        let emailModalPrevValue = '';
        let pendingDetailSubmitAfterEmail = false;
        let relationshipQuickMode = false;

        const additionalEmailModal = document.getElementById('metis-additional-email-modal');
        const additionalEmailModalInput = document.getElementById('metis-additional-email-modal-input');
        const additionalEmailModalCancel = document.getElementById('metis-additional-email-modal-cancel');
        const additionalEmailModalSave = document.getElementById('metis-additional-email-modal-save');
        const newsletterModal = document.getElementById('metis-newsletter-modal');
        const newsletterModalSelect = document.getElementById('metis-newsletter-modal-select');
        const newsletterModalCancel = document.getElementById('metis-newsletter-modal-cancel');
        const newsletterModalSave = document.getElementById('metis-newsletter-modal-save');

        const relationshipsList = document.getElementById('metis-relationships-list');
        const addRelationshipBtn = document.getElementById('metis-add-relationship');
        const relationshipModal = document.getElementById('metis-relationship-modal');
        const relationshipRows = document.getElementById('metis-relationship-rows');
        const relationshipContactsTemplate = document.getElementById('metis-relationship-contacts-template');
        const relationshipTypesTemplate = document.getElementById('metis-relationship-types-template');
        const relationshipAddRowBtn = document.getElementById('metis-relationship-add-row');
        const relationshipCancelBtn = document.getElementById('metis-relationship-cancel');
        const relationshipSaveBtn = document.getElementById('metis-relationship-save');

        let relationships = [];
        let editingRelationshipIndex = -1;
        const relationshipNameByCid = {};
        if (relationshipContactsTemplate) {
            Array.from(relationshipContactsTemplate.querySelectorAll('option')).forEach(function (opt) {
                const cid = (opt.value || '').trim();
                if (!cid) return;
                const label = (opt.getAttribute('data-name') || '').trim();
                if (label) {
                    relationshipNameByCid[cid] = label;
                }
            });
        }

        function safeParseArray(value) {
            try {
                const parsed = JSON.parse(value || '[]');
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }

        function setRelationshipsJson() {
            if (relationshipsHidden) relationshipsHidden.value = JSON.stringify(relationships);
        }

        function relationshipDisplayName(cid, fallback) {
            const key = (cid || '').trim();
            if (key && relationshipNameByCid[key]) return relationshipNameByCid[key];
            return (fallback || '').trim() || 'Related Contact';
        }

        function setAdditionalEmailsJson() {
            if (!addEmailsHidden || !addEmailRows) return;
            function normalizeEmail(value) {
                return String(value || '')
                    .normalize('NFKC')
                    .replace(/[\u200B-\u200D\uFEFF]/g, '')
                    .replace(/\s+/g, '')
                    .trim()
                    .toLowerCase();
            }
            const primaryEmail = normalizeEmail(detailEmailInput ? detailEmailInput.value : '');
            const seen = new Set();
            const values = Array.from(addEmailRows.querySelectorAll('.metis-email-row-input'))
                .map(function (input) { return normalizeEmail(input.value || ''); })
                .filter(function (v) {
                    return v && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
                })
                .filter(function (v) {
                    if (!v || v === primaryEmail || seen.has(v)) return false;
                    seen.add(v);
                    return true;
                });
            addEmailsHidden.value = JSON.stringify(values);
        }

        function ensurePrimaryEmailSelection(options) {
            const interactive = !!(options && options.interactive);
            if (!detailEmailInput || detailEmailInput.tagName !== 'SELECT') return true;
            if (detailEmailInput.value !== '__new__') {
                detailEmailInput.dataset.prevValue = detailEmailInput.value || '';
                setAdditionalEmailsJson();
                return true;
            }
            if (!interactive) {
                detailEmailInput.value = detailEmailInput.dataset.prevValue || (detailEmailInput.options[0] ? detailEmailInput.options[0].value : '');
                setAdditionalEmailsJson();
                return true;
            }
            emailModalPrevValue = detailEmailInput.dataset.prevValue || (detailEmailInput.options[0] ? detailEmailInput.options[0].value : '');
            if (emailModalInput) emailModalInput.value = '';
            if (emailModalError) emailModalError.style.display = 'none';
            openModal(emailModal);
            return false;
        }

        function usePrimaryEmailFromModal() {
            if (!detailEmailInput || !emailModalInput) return;

            const normalized = String(emailModalInput.value || '')
                .normalize('NFKC')
                .replace(/[\u200B-\u200D\uFEFF]/g, '')
                .replace(/\s+/g, '')
                .trim()
                .toLowerCase();

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized)) {
                if (emailModalError) emailModalError.style.display = '';
                return;
            }
            if (emailModalError) emailModalError.style.display = 'none';

            let option = Array.from(detailEmailInput.options).find(function (opt) {
                return (opt.value || '').toLowerCase() === normalized;
            });
            if (!option) {
                option = document.createElement('option');
                option.value = normalized;
                option.textContent = normalized;
                const newOption = Array.from(detailEmailInput.options).find(function (opt) { return opt.value === '__new__'; });
                if (newOption && newOption.parentNode === detailEmailInput) {
                    detailEmailInput.insertBefore(option, newOption);
                } else {
                    detailEmailInput.appendChild(option);
                }
            }

            detailEmailInput.value = normalized;
            detailEmailInput.dataset.prevValue = normalized;
            setAdditionalEmailsJson();
            closeModal(emailModal);
            if (pendingDetailSubmitAfterEmail && detailForm) {
                pendingDetailSubmitAfterEmail = false;
                detailForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
        }

        function addEmailRow(value) {
            if (!addEmailRows) return;
            const row = document.createElement('div');
            row.className = 'metis-editor-row';
            row.innerHTML = '<input type="email" class="mw-input metis-email-row-input" placeholder="name@example.org">' +
                '<button type="button" class="mw-btn-xs mw-btn-danger">Remove</button>';
            const input = row.querySelector('.metis-email-row-input');
            const removeBtn = row.querySelector('button');
            if (input) {
                input.value = value || '';
                input.addEventListener('input', setAdditionalEmailsJson);
            }
            if (removeBtn) {
                removeBtn.addEventListener('click', function () {
                    row.remove();
                    setAdditionalEmailsJson();
                });
            }
            addEmailRows.appendChild(row);
        }

        function renderAdditionalEmailChips(emails) {
            if (!additionalEmailsView) return;
            const normalized = Array.isArray(emails) ? emails : [];
            if (!normalized.length) {
                if (additionalEmailsSection && !openAdditionalEmailPlus) {
                    additionalEmailsSection.style.display = 'none';
                } else {
                    additionalEmailsView.innerHTML = '<div class="mw-muted">No additional emails recorded.</div>';
                }
                return;
            }
            if (additionalEmailsSection) additionalEmailsSection.style.display = '';
            const chipHtml = normalized.map(function (email) {
                return '<span class="metis-chip">' +
                    '<span>' + escapeHtml(email) + '</span>' +
                    '<button type="button" class="metis-chip-remove" data-email="' + escapeHtml(email) + '" aria-label="Remove ' + escapeHtml(email) + '">×</button>' +
                    '</span>';
            }).join('');
            additionalEmailsView.innerHTML = '<div class="metis-chip-list">' + chipHtml + '</div>';
        }

        function renderNewsletterChips(items) {
            if (!newsletterView) return;
            const list = Array.isArray(items) ? items : [];
            if (!list.length) {
                if (newsletterSection && !openNewsletterPlus) {
                    newsletterSection.style.display = 'none';
                } else {
                    newsletterView.innerHTML = '<div class="mw-muted">No newsletter subscriptions.</div>';
                }
                return;
            }
            if (newsletterSection) newsletterSection.style.display = '';
            const html = list.map(function (item) {
                const id = parseInt(item.id || '0', 10);
                const name = String(item.name || '').trim();
                return '<span class="metis-chip">' +
                    '<span>' + escapeHtml(name) + '</span>' +
                    '<button type="button" class="metis-chip-remove metis-remove-newsletter-chip" data-list-id="' + escapeHtml(String(id)) + '" aria-label="Remove newsletter list">×</button>' +
                    '</span>';
            }).join('');
            newsletterView.innerHTML = '<div class="metis-chip-list">' + html + '</div>';
        }

        function formatPhone(value) {
            const digits = String(value || '').replace(/\D+/g, '');
            if (digits.length === 11 && digits.charAt(0) === '1') {
                return digits.slice(1, 4) + '-' + digits.slice(4, 7) + '-' + digits.slice(7, 11);
            }
            if (digits.length === 10) {
                return digits.slice(0, 3) + '-' + digits.slice(3, 6) + '-' + digits.slice(6, 10);
            }
            return String(value || '').trim();
        }

        function parseAddressLine2(line2) {
            const raw = String(line2 || '').trim();
            if (!raw) return { city: '', state: '', zip: '' };
            const match = raw.match(/^(.+?),\s*([A-Za-z]{2})(?:\s+(\d{5}(?:-\d{4})?))?$/);
            if (match) {
                return {
                    city: (match[1] || '').trim(),
                    state: (match[2] || '').trim().toUpperCase(),
                    zip: (match[3] || '').trim()
                };
            }
            return { city: raw, state: '', zip: '' };
        }

        function showInlineSaved(anchorEl) {
            if (!anchorEl || !anchorEl.parentNode) return;
            const mark = document.createElement('span');
            mark.className = 'metis-inline-saved';
            mark.textContent = '✓';
            anchorEl.parentNode.insertBefore(mark, anchorEl.nextSibling);
            window.setTimeout(function () { mark.classList.add('is-visible'); }, 10);
            window.setTimeout(function () {
                mark.classList.remove('is-visible');
                window.setTimeout(function () { mark.remove(); }, 180);
            }, 1100);
        }

        function saveInlineField(field, value, onDone) {
            if (!ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) {
                if (typeof onDone === 'function') onDone(new Error('Missing AJAX config'));
                return;
            }
            const payload = new FormData();
            payload.append('action', 'metis_contact_inline_update');
            payload.append('nonce', ajaxConfig.nonce);
            payload.append('cid', detailForm.dataset.contactCid || '');
            payload.append('field', field);
            payload.append('value', value || '');

            fetch(ajaxConfig.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    if (!response || !response.success || !response.data) {
                        throw new Error(response && response.data ? response.data : 'Failed to save');
                    }
                    if (typeof onDone === 'function') onDone(null, response.data);
                })
                .catch(function (err) {
                    if (typeof onDone === 'function') onDone(err);
                });
        }

        function wireInlineEditable(el) {
            if (!el) return;
            const field = (el.getAttribute('data-field') || '').trim();
            if (!field) return;
            let editing = false;

            el.addEventListener('dblclick', function () {
                if (editing) return;

                if ((el.getAttribute('data-input-type') || '') === 'checkbox') {
                    const raw = (el.dataset.rawValue || '0') === '1' ? '1' : '0';
                    const next = raw === '1' ? '0' : '1';
                    saveInlineField(field, next, function (err) {
                        if (err) {
                            showAlert(err.message || 'Failed to save', 'error');
                            return;
                        }
                        el.dataset.rawValue = next;
                        el.textContent = next === '1' ? 'Yes' : 'No';
                        showInlineSaved(el);
                    });
                    return;
                }

                editing = true;

                const currentRaw = (el.dataset.rawValue || '').trim();

                if (field === 'address_full') {
                    const lines = Array.from(el.querySelectorAll('.metis-address-line')).map(function (node) {
                        return (node.textContent || '').trim();
                    }).filter(Boolean);
                    const line1 = lines[0] || '';
                    const parsedLine2 = parseAddressLine2(lines[1] || '');

                    const editor = document.createElement('div');
                    editor.className = 'metis-inline-address-editor';
                    editor.innerHTML = '' +
                        '<div class="metis-inline-address-row metis-inline-address-row-street">' +
                        '<input type="text" class="mw-input metis-inline-address-street" placeholder="Street address">' +
                        '</div>' +
                        '<div class="metis-inline-address-row metis-inline-address-row-local">' +
                        '<input type="text" class="mw-input metis-inline-address-city" placeholder="City">' +
                        '<select class="mw-select metis-inline-address-state" aria-label="State">' +
                        '<option value="">State</option>' +
                        '<option value="AL">AL</option><option value="AK">AK</option><option value="AZ">AZ</option><option value="AR">AR</option>' +
                        '<option value="CA">CA</option><option value="CO">CO</option><option value="CT">CT</option><option value="DE">DE</option>' +
                        '<option value="FL">FL</option><option value="GA">GA</option><option value="HI">HI</option><option value="ID">ID</option>' +
                        '<option value="IL">IL</option><option value="IN">IN</option><option value="IA">IA</option><option value="KS">KS</option>' +
                        '<option value="KY">KY</option><option value="LA">LA</option><option value="ME">ME</option><option value="MD">MD</option>' +
                        '<option value="MA">MA</option><option value="MI">MI</option><option value="MN">MN</option><option value="MS">MS</option>' +
                        '<option value="MO">MO</option><option value="MT">MT</option><option value="NE">NE</option><option value="NV">NV</option>' +
                        '<option value="NH">NH</option><option value="NJ">NJ</option><option value="NM">NM</option><option value="NY">NY</option>' +
                        '<option value="NC">NC</option><option value="ND">ND</option><option value="OH">OH</option><option value="OK">OK</option>' +
                        '<option value="OR">OR</option><option value="PA">PA</option><option value="RI">RI</option><option value="SC">SC</option>' +
                        '<option value="SD">SD</option><option value="TN">TN</option><option value="TX">TX</option><option value="UT">UT</option>' +
                        '<option value="VT">VT</option><option value="VA">VA</option><option value="WA">WA</option><option value="WV">WV</option>' +
                        '<option value="WI">WI</option><option value="WY">WY</option>' +
                        '</select>' +
                        '<input type="text" class="mw-input metis-inline-address-zip" maxlength="10" placeholder="ZIP">' +
                        '</div>' +
                        '<div class="metis-inline-address-actions">' +
                        '<button type="button" class="mw-btn-xs metis-inline-address-save">Save</button>' +
                        '<button type="button" class="mw-btn-xs mw-btn-ghost metis-inline-address-cancel">Cancel</button>' +
                        '</div>';

                    const streetInput = editor.querySelector('.metis-inline-address-street');
                    const cityInput = editor.querySelector('.metis-inline-address-city');
                    const stateInput = editor.querySelector('.metis-inline-address-state');
                    const zipInput = editor.querySelector('.metis-inline-address-zip');
                    const saveBtn = editor.querySelector('.metis-inline-address-save');
                    const cancelBtn = editor.querySelector('.metis-inline-address-cancel');

                    if (streetInput) streetInput.value = line1;
                    if (cityInput) cityInput.value = parsedLine2.city;
                    if (stateInput) stateInput.value = parsedLine2.state;
                    if (zipInput) zipInput.value = parsedLine2.zip;

                    el.style.display = 'none';
                    el.parentNode.insertBefore(editor, el.nextSibling);
                    if (streetInput) streetInput.focus();

                    let done = false;
                    let outsideHandler = null;
                    const cleanup = function () {
                        if (outsideHandler) {
                            document.removeEventListener('mousedown', outsideHandler, true);
                            outsideHandler = null;
                        }
                        editor.remove();
                        el.style.display = '';
                        editing = false;
                    };
                    const finish = function (save) {
                        if (done) return;
                        done = true;
                        if (!save) {
                            cleanup();
                            return;
                        }
                        const street = String(streetInput ? streetInput.value : '').trim();
                        const city = String(cityInput ? cityInput.value : '').trim();
                        const state = String(stateInput ? stateInput.value : '').trim().toUpperCase();
                        const zip = String(zipInput ? zipInput.value : '').trim();
                        const line2 = [city, (state + (zip ? ' ' + zip : '')).trim()].filter(Boolean).join(', ');
                        const candidate = [street, line2].filter(Boolean).join(', ');

                        if (saveBtn) saveBtn.disabled = true;
                        saveInlineField(field, candidate, function (err, data) {
                            if (err) {
                                showAlert(err.message || 'Failed to save', 'error');
                                if (saveBtn) saveBtn.disabled = false;
                                done = false;
                                return;
                            }
                            const nextValue = String((data && data.value) || candidate).trim();
                            el.dataset.rawValue = nextValue;

                            const nextLine1 = String((data && data.address_line_1) || '').trim();
                            const nextLine2 = String((data && data.address_line_2) || '').trim();
                            if (!nextLine1 && !nextLine2) {
                                el.innerHTML = '<span class="metis-address-line">—</span>';
                            } else {
                                el.innerHTML = '';
                                if (nextLine1) {
                                    const s1 = document.createElement('span');
                                    s1.className = 'metis-address-line';
                                    s1.textContent = nextLine1;
                                    el.appendChild(s1);
                                }
                                if (nextLine2) {
                                    const s2 = document.createElement('span');
                                    s2.className = 'metis-address-line';
                                    s2.textContent = nextLine2;
                                    el.appendChild(s2);
                                }
                            }

                            const modalAddress = document.getElementById('metis-detail-address');
                            const modalCity = document.getElementById('metis-detail-city');
                            const modalState = document.getElementById('metis-detail-state');
                            const modalZip = document.getElementById('metis-detail-zip');
                            if (modalAddress) modalAddress.value = nextLine1;
                            if (modalCity || modalState || modalZip) {
                                const parsed = parseAddressLine2(nextLine2);
                                if (modalCity) modalCity.value = parsed.city;
                                if (modalState) modalState.value = parsed.state;
                                if (modalZip) modalZip.value = parsed.zip;
                            }

                            showInlineSaved(el);
                            cleanup();
                        });
                    };

                    if (saveBtn) saveBtn.addEventListener('click', function () { finish(true); });
                    if (cancelBtn) cancelBtn.addEventListener('click', function () { finish(false); });

                    [streetInput, cityInput, stateInput, zipInput].forEach(function (inputEl) {
                        if (!inputEl) return;
                        inputEl.addEventListener('keydown', function (event) {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                finish(true);
                            } else if (event.key === 'Escape') {
                                event.preventDefault();
                                finish(false);
                            }
                        });
                    });

                    window.setTimeout(function () {
                        outsideHandler = function (event) {
                            if (!editor.contains(event.target)) {
                                finish(true);
                            }
                        };
                        document.addEventListener('mousedown', outsideHandler, true);
                    }, 0);

                    return;
                }

                const input = document.createElement('input');
                input.type = field === 'email' ? 'email' : 'text';
                input.className = 'mw-input metis-inline-edit-input';
                input.value = currentRaw;
                if (field === 'email') {
                    const listId = 'metis-inline-email-options';
                    input.setAttribute('list', listId);
                    if (!document.getElementById(listId)) {
                        const dl = document.createElement('datalist');
                        dl.id = listId;
                        let options = [];
                        try {
                            options = JSON.parse(el.getAttribute('data-email-options') || '[]');
                        } catch (e) {
                            options = [];
                        }
                        if (Array.isArray(options)) {
                            options.forEach(function (entry) {
                                const val = String(entry || '').trim();
                                if (!val) return;
                                const opt = document.createElement('option');
                                opt.value = val;
                                dl.appendChild(opt);
                            });
                        }
                        document.body.appendChild(dl);
                    }
                }
                if (field === 'phone') {
                    input.placeholder = 'xxx-xxx-xxxx';
                }
                el.style.display = 'none';
                el.parentNode.insertBefore(input, el.nextSibling);
                input.focus();
                input.select();

                let done = false;
                function finish(save) {
                    if (done) return;
                    done = true;
                    const candidate = (input.value || '').trim();
                    const cancel = function () {
                        input.remove();
                        el.style.display = '';
                        editing = false;
                    };

                    if (!save || candidate === currentRaw) {
                        cancel();
                        return;
                    }

                    input.disabled = true;
                    saveInlineField(field, candidate, function (err, data) {
                        if (err) {
                            showAlert(err.message || 'Failed to save', 'error');
                            input.disabled = false;
                            done = false;
                            return;
                        }

                        const nextValue = String((data && data.value) || candidate).trim();
                        el.dataset.rawValue = nextValue;
                        if (field === 'full_name' && data) {
                            const nextFirst = String(data.first_name || '').trim();
                            const nextLast = String(data.last_name || '').trim();
                            const firstEl = document.querySelector('.metis-inline-editable[data-field="first_name"]');
                            const lastEl = document.querySelector('.metis-inline-editable[data-field="last_name"]');
                            const modalFirstEl = document.getElementById('metis-detail-first-name');
                            const modalLastEl = document.getElementById('metis-detail-last-name');
                            if (firstEl) {
                                firstEl.dataset.rawValue = nextFirst;
                                firstEl.textContent = nextFirst || '—';
                            }
                            if (lastEl) {
                                lastEl.dataset.rawValue = nextLast;
                                lastEl.textContent = nextLast || '—';
                            }
                            if (modalFirstEl) {
                                modalFirstEl.value = nextFirst;
                            }
                            if (modalLastEl) {
                                modalLastEl.value = nextLast;
                            }
                            if (pageTitle) {
                                pageTitle.dataset.rawValue = nextValue || (nextFirst + ' ' + nextLast).trim();
                                pageTitle.textContent = nextValue || (nextFirst + ' ' + nextLast).trim() || '(No name)';
                            }
                        } else if (field === 'phone') {
                            el.textContent = nextValue ? formatPhone(nextValue) : '—';
                        } else if (field === 'address_full') {
                            const line1 = String((data && data.address_line_1) || '').trim();
                            const line2 = String((data && data.address_line_2) || '').trim();
                            if (!line1 && !line2) {
                                el.innerHTML = '<span class="metis-address-line">—</span>';
                            } else {
                                el.innerHTML = '';
                                if (line1) {
                                    const s1 = document.createElement('span');
                                    s1.className = 'metis-address-line';
                                    s1.textContent = line1;
                                    el.appendChild(s1);
                                }
                                if (line2) {
                                    const s2 = document.createElement('span');
                                    s2.className = 'metis-address-line';
                                    s2.textContent = line2;
                                    el.appendChild(s2);
                                }
                            }
                            const modalAddress = document.getElementById('metis-detail-address');
                            const modalCity = document.getElementById('metis-detail-city');
                            const modalState = document.getElementById('metis-detail-state');
                            const modalZip = document.getElementById('metis-detail-zip');
                            if (modalAddress || modalCity || modalState || modalZip) {
                                const parts = (nextValue || '').split(',').map(function (p) { return p.trim(); }).filter(Boolean);
                                const line1Value = line1 || (parts[0] || '');
                                let cityValue = '';
                                let stateValue = '';
                                let zipValue = '';
                                const line2Value = line2 || (parts.length > 1 ? parts.slice(1).join(', ') : '');
                                if (line2Value) {
                                    const m = line2Value.match(/^(.+?),\s*([A-Za-z]{2})(?:\s+(\d{5}(?:-\d{4})?))?$/);
                                    if (m) {
                                        cityValue = (m[1] || '').trim();
                                        stateValue = (m[2] || '').trim().toUpperCase();
                                        zipValue = (m[3] || '').trim();
                                    }
                                }
                                if (modalAddress) modalAddress.value = line1Value;
                                if (modalCity) modalCity.value = cityValue;
                                if (modalState) modalState.value = stateValue;
                                if (modalZip) modalZip.value = zipValue;
                            }
                        } else {
                            el.textContent = nextValue || '—';
                        }
                        if (field === 'email' && detailEmailInput) {
                            let option = Array.from(detailEmailInput.options).find(function (opt) {
                                return (opt.value || '').toLowerCase() === nextValue.toLowerCase();
                            });
                            if (!option) {
                                option = document.createElement('option');
                                option.value = nextValue;
                                option.textContent = nextValue;
                                const newOpt = Array.from(detailEmailInput.options).find(function (opt) { return opt.value === '__new__'; });
                                if (newOpt && newOpt.parentNode === detailEmailInput) {
                                    detailEmailInput.insertBefore(option, newOpt);
                                } else {
                                    detailEmailInput.appendChild(option);
                                }
                            }
                            detailEmailInput.value = nextValue;
                            detailEmailInput.dataset.prevValue = nextValue;
                            if (data && Array.isArray(data.additional_emails)) {
                                if (addEmailsHidden) addEmailsHidden.value = JSON.stringify(data.additional_emails);
                                if (addEmailRows) {
                                    addEmailRows.innerHTML = '';
                                    if (data.additional_emails.length) {
                                        data.additional_emails.forEach(function (entry) { addEmailRow(String(entry)); });
                                    } else {
                                        addEmailRow('');
                                    }
                                    setAdditionalEmailsJson();
                                }
                                renderAdditionalEmailChips(data.additional_emails);
                            }
                        }

                        if (field === 'first_name' || field === 'last_name') {
                            const firstEl = document.querySelector('.metis-inline-editable[data-field="first_name"]');
                            const lastEl = document.querySelector('.metis-inline-editable[data-field="last_name"]');
                            const first = firstEl ? (firstEl.dataset.rawValue || '').trim() : '';
                            const last = lastEl ? (lastEl.dataset.rawValue || '').trim() : '';
                            if (pageTitle) {
                                const full = (first + ' ' + last).trim();
                                pageTitle.textContent = full || '(No name)';
                            }
                        }

                        showInlineSaved(el);

                        cancel();
                    });
                }

                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        finish(true);
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        finish(false);
                    }
                });
                input.addEventListener('blur', function () { finish(true); });
            });
        }

        function renderRelationships() {
            if (!relationshipsList) return;
            relationshipsList.innerHTML = '';
            if (!relationships.length) {
                relationshipsList.innerHTML = '<div class="mw-muted">No relationships added.</div>';
                editingRelationshipIndex = -1;
                return;
            }

            relationships.forEach(function (rel, index) {
                const row = document.createElement('div');

                if (editingRelationshipIndex === index && relationshipContactsTemplate && relationshipTypesTemplate) {
                    row.className = 'metis-editor-row metis-inline-rel-row metis-inline-rel-row-editing';
                    const contactSel = document.createElement('select');
                    contactSel.className = 'mw-select metis-inline-rel-contact';
                    contactSel.innerHTML = relationshipContactsTemplate.innerHTML;
                    if (rel.related_contact_cid) contactSel.value = rel.related_contact_cid;

                    const typeSel = document.createElement('select');
                    typeSel.className = 'mw-select metis-inline-rel-type';
                    typeSel.innerHTML = relationshipTypesTemplate.innerHTML;
                    if (rel.relation_type) typeSel.value = rel.relation_type;

                    const notesInput = document.createElement('input');
                    notesInput.type = 'text';
                    notesInput.className = 'mw-input metis-inline-rel-notes';
                    notesInput.placeholder = 'Notes (optional)';
                    notesInput.value = rel.notes || '';

                    const actionWrap = document.createElement('div');
                    actionWrap.className = 'metis-editor-row-actions';

                    const saveBtn = document.createElement('button');
                    saveBtn.type = 'button';
                    saveBtn.className = 'mw-btn-xs';
                    saveBtn.textContent = 'Save';
                    saveBtn.addEventListener('click', function () {
                        if (!contactSel.value || !typeSel.value) {
                            showAlert('Contact and relationship type are required.', 'warning');
                            return;
                        }
                        const option = contactSel.options[contactSel.selectedIndex];
                        relationships[index] = {
                            related_contact_cid: contactSel.value,
                            relation_type: typeSel.value,
                            notes: (notesInput.value || '').trim()
                        };
                        relationships = uniqueRelationships(relationships);
                        relationships = relationships.map(function (entry) {
                            return {
                                related_contact_cid: entry.related_contact_cid || '',
                                relation_type: entry.relation_type || '',
                                notes: entry.notes || ''
                            };
                        });
                        editingRelationshipIndex = -1;
                        setRelationshipsJson();
                        renderRelationships();
                    });

                    const cancelBtn = document.createElement('button');
                    cancelBtn.type = 'button';
                    cancelBtn.className = 'mw-btn-xs mw-btn-ghost';
                    cancelBtn.textContent = 'Cancel';
                    cancelBtn.addEventListener('click', function () {
                        editingRelationshipIndex = -1;
                        renderRelationships();
                    });

                    actionWrap.appendChild(saveBtn);
                    actionWrap.appendChild(cancelBtn);

                    row.appendChild(contactSel);
                    row.appendChild(typeSel);
                    row.appendChild(notesInput);
                    row.appendChild(actionWrap);
                } else {
                    row.className = 'metis-editor-row metis-inline-rel-row metis-inline-rel-row-display';
                    const label = relationshipDisplayName(rel.related_contact_cid || '', rel.name || '');
                    const line = label +
                        (rel.relation_type ? ' (' + rel.relation_type + ')' : '') +
                        (rel.notes ? ' - ' + rel.notes : '');
                    row.innerHTML = '<div class="metis-editor-row-label">' + escapeHtml(line) + '</div>' +
                        '<div class="metis-editor-row-actions">' +
                        '<button type="button" class="mw-btn-xs metis-rel-edit-btn">Edit</button>' +
                        '<button type="button" class="mw-btn-xs mw-btn-danger metis-rel-delete-btn">Delete</button>' +
                        '</div>';
                    const editBtn = row.querySelector('.metis-rel-edit-btn');
                    const removeBtn = row.querySelector('.metis-rel-delete-btn');
                    if (editBtn) {
                        editBtn.addEventListener('click', function () {
                            editingRelationshipIndex = index;
                            renderRelationships();
                        });
                    }
                    if (removeBtn) {
                        removeBtn.addEventListener('click', function () {
                            relationships.splice(index, 1);
                            editingRelationshipIndex = -1;
                            setRelationshipsJson();
                            renderRelationships();
                        });
                    }
                }

                relationshipsList.appendChild(row);
            });
        }

        function addRelationshipPickerRow(seed) {
            if (!relationshipRows || !relationshipContactsTemplate || !relationshipTypesTemplate) return;
            const row = document.createElement('div');
            row.className = 'metis-editor-row metis-relationship-row';

            const contactSel = document.createElement('select');
            contactSel.className = 'mw-select metis-rel-contact';
            contactSel.innerHTML = relationshipContactsTemplate.innerHTML;

            const typeSel = document.createElement('select');
            typeSel.className = 'mw-select metis-rel-type';
            typeSel.innerHTML = relationshipTypesTemplate.innerHTML;

            const notesInput = document.createElement('input');
            notesInput.className = 'mw-input metis-rel-notes';
            notesInput.type = 'text';
            notesInput.placeholder = 'Notes (optional)';

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'mw-btn-xs mw-btn-danger metis-rel-row-remove';
            removeBtn.textContent = 'Remove';
            removeBtn.addEventListener('click', function () { row.remove(); });

            row.appendChild(contactSel);
            row.appendChild(typeSel);
            row.appendChild(notesInput);
            row.appendChild(removeBtn);
            relationshipRows.appendChild(row);

            if (seed && typeof seed === 'object') {
                if (seed.related_contact_cid) contactSel.value = seed.related_contact_cid;
                if (seed.relation_type) typeSel.value = seed.relation_type;
                if (seed.notes) notesInput.value = seed.notes;
            }
        }

        function uniqueRelationships(items) {
            const seen = new Set();
            const out = [];
            items.forEach(function (entry) {
                const cid = (entry.related_contact_cid || '').trim();
                const type = (entry.relation_type || '').trim().toLowerCase();
                const key = cid + '::' + type;
                if (!cid || !type || seen.has(key)) return;
                seen.add(key);
                out.push(entry);
            });
            return out;
        }

        function openRelationshipEditor(focusIndex) {
            if (!relationshipRows) return;
            relationshipRows.innerHTML = '';
            if (relationships.length) {
                relationships.forEach(function (entry, index) {
                    addRelationshipPickerRow(entry);
                    if (typeof focusIndex === 'number' && focusIndex === index) {
                        const currentRows = relationshipRows.querySelectorAll('.metis-relationship-row');
                        const targetRow = currentRows[currentRows.length - 1];
                        if (targetRow) {
                            targetRow.classList.add('metis-relationship-row-focus');
                        }
                    }
                });
            } else {
                addRelationshipPickerRow();
            }
            openModal(relationshipModal);
        }

        // Init fields
        const initialEmails = safeParseArray(addEmailsHidden ? addEmailsHidden.value : '[]');
        if (initialEmails.length) {
            initialEmails.forEach(function (email) { addEmailRow(String(email)); });
        } else {
            addEmailRow('');
        }
        setAdditionalEmailsJson();

        relationships = safeParseArray(relationshipsHidden ? relationshipsHidden.value : '[]').map(function (entry) {
            if (entry && typeof entry === 'object') {
                return {
                    related_contact_cid: entry.related_contact_cid || entry.related_contact_id || '',
                    name: entry.name || entry.contact_name || '',
                    relation_type: entry.relation_type || '',
                    notes: entry.notes || ''
                };
            }
            return { related_contact_cid: '', name: String(entry || ''), relation_type: '', notes: '' };
        });
        relationships = relationships.map(function (entry) {
            return {
                related_contact_cid: entry.related_contact_cid || '',
                relation_type: entry.relation_type || '',
                notes: entry.notes || ''
            };
        });
        setRelationshipsJson();
        renderRelationships();

        // Modal bindings
        if (openEditBtn) openEditBtn.addEventListener('click', function () {
            if (tabButtons.length && tabPanels.length) {
                tabButtons.forEach(function (b, idx) { b.classList.toggle('is-active', idx === 0); });
                tabPanels.forEach(function (p, idx) { p.classList.toggle('is-active', idx === 0); });
            }
            openModal(editModal);
        });
        if (editCancelBtn) editCancelBtn.addEventListener('click', function () { closeModal(editModal); });
        if (editModal) editModal.addEventListener('click', function (event) { if (event.target === editModal) closeModal(editModal); });

        const openNoteModalHandler = function () {
            openModal(noteModal);
            if (noteTextEl) noteTextEl.value = '';
        };
        if (openNoteBtn) openNoteBtn.addEventListener('click', openNoteModalHandler);
        if (openNoteBtnPlus) openNoteBtnPlus.addEventListener('click', openNoteModalHandler);
        if (noteCancelBtn) noteCancelBtn.addEventListener('click', function () { closeModal(noteModal); });
        if (noteModal) noteModal.addEventListener('click', function (event) { if (event.target === noteModal) closeModal(noteModal); });

        if (addEmailBtn) addEmailBtn.addEventListener('click', function () { addEmailRow(''); });
        if (detailEmailInput) {
            if (detailEmailInput.tagName === 'SELECT' && detailEmailInput.value === '__new__') {
                const firstRealOption = Array.from(detailEmailInput.options).find(function (opt) {
                    return opt.value && opt.value !== '__new__';
                });
                if (firstRealOption) {
                    detailEmailInput.value = firstRealOption.value;
                }
            }
            detailEmailInput.dataset.prevValue = detailEmailInput.value || '';
            detailEmailInput.addEventListener('change', function () { ensurePrimaryEmailSelection({ interactive: true }); });
            detailEmailInput.addEventListener('input', setAdditionalEmailsJson);
        }
        if (emailModalCancel) {
            emailModalCancel.addEventListener('click', function () {
                if (detailEmailInput) {
                    detailEmailInput.value = emailModalPrevValue || (detailEmailInput.options[0] ? detailEmailInput.options[0].value : '');
                }
                pendingDetailSubmitAfterEmail = false;
                closeModal(emailModal);
            });
        }
        if (emailModalSave) {
            emailModalSave.addEventListener('click', usePrimaryEmailFromModal);
        }
        if (emailModalInput) {
            emailModalInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    usePrimaryEmailFromModal();
                }
            });
        }
        if (emailModal) {
            emailModal.addEventListener('click', function (event) {
                if (event.target === emailModal) {
                    if (detailEmailInput) {
                        detailEmailInput.value = emailModalPrevValue || (detailEmailInput.options[0] ? detailEmailInput.options[0].value : '');
                    }
                    pendingDetailSubmitAfterEmail = false;
                    closeModal(emailModal);
                }
            });
        }

        if (addRelationshipBtn) {
            addRelationshipBtn.addEventListener('click', function () {
                relationshipQuickMode = false;
                openRelationshipEditor();
            });
        }
        if (openRelationshipsPlus) {
            openRelationshipsPlus.addEventListener('click', function () {
                relationshipQuickMode = true;
                openRelationshipEditor();
            });
        }
        if (relationshipAddRowBtn) relationshipAddRowBtn.addEventListener('click', addRelationshipPickerRow);
        if (relationshipCancelBtn) relationshipCancelBtn.addEventListener('click', function () { closeModal(relationshipModal); });
        if (relationshipModal) relationshipModal.addEventListener('click', function (event) { if (event.target === relationshipModal) closeModal(relationshipModal); });

        if (relationshipSaveBtn) {
            relationshipSaveBtn.addEventListener('click', function () {
                if (!relationshipRows) return;
                const rows = Array.from(relationshipRows.querySelectorAll('.metis-editor-row'));
                const updated = [];

                rows.forEach(function (row) {
                    const cSel = row.querySelector('.metis-rel-contact');
                    const tSel = row.querySelector('.metis-rel-type');
                    const nEl = row.querySelector('.metis-rel-notes');
                    if (!cSel || !tSel) return;
                    if (!cSel.value || !tSel.value) return;
                    updated.push({
                        related_contact_cid: cSel.value,
                        relation_type: tSel.value,
                        notes: nEl ? (nEl.value || '').trim() : ''
                    });
                });

                if (!updated.length) {
                    showAlert('Select at least one contact and relationship type.', 'warning');
                    return;
                }

                relationships = uniqueRelationships(updated);
                relationships = relationships.map(function (entry) {
                    return {
                        related_contact_cid: entry.related_contact_cid || '',
                        relation_type: entry.relation_type || '',
                        notes: entry.notes || ''
                    };
                });
                setRelationshipsJson();
                renderRelationships();
                closeModal(relationshipModal);
                if (relationshipQuickMode) {
                    relationshipQuickMode = false;
                    detailForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                }
            });
        }

        if (noteSaveBtn) {
            noteSaveBtn.addEventListener('click', function () {
                if (!ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce || !noteTextEl) return;
                const noteText = (noteTextEl.value || '').trim();
                if (!noteText) {
                    showAlert('Note is required.', 'warning');
                    return;
                }

                const payload = new FormData();
                payload.append('action', 'metis_contact_add_note');
                payload.append('nonce', ajaxConfig.nonce);
                payload.append('cid', detailForm.dataset.contactCid || '');
                payload.append('note', noteText);

                fetch(ajaxConfig.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(function (res) { return res.json(); })
                    .then(function (response) {
                        if (!response || !response.success || !response.data) {
                            throw new Error(response && response.data ? response.data : 'Failed to save note');
                        }

                        if (noNotesEl) noNotesEl.remove();
                        if (notesList) {
                            const item = document.createElement('article');
                            item.className = 'metis-contact-note-item';
                            item.innerHTML = '<div>' + escapeHtml(noteText) + '</div>' +
                                '<div class="mw-muted" style="font-size: 12px; margin-top: 4px;">' +
                                escapeHtml((response.data.author || 'System') + ' - ' + (response.data.when || '')) +
                                '</div>';
                            notesList.prepend(item);
                        }

                        closeModal(noteModal);
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to save note', 'error');
                    });
            });
        }

        if (openAdditionalEmailPlus) {
            openAdditionalEmailPlus.addEventListener('click', function () {
                if (additionalEmailModalInput) additionalEmailModalInput.value = '';
                openModal(additionalEmailModal);
            });
        }
        if (additionalEmailModalCancel) {
            additionalEmailModalCancel.addEventListener('click', function () {
                closeModal(additionalEmailModal);
            });
        }
        if (additionalEmailModal) {
            additionalEmailModal.addEventListener('click', function (event) {
                if (event.target === additionalEmailModal) closeModal(additionalEmailModal);
            });
        }
        if (additionalEmailModalSave) {
            additionalEmailModalSave.addEventListener('click', function () {
                const email = String(additionalEmailModalInput ? additionalEmailModalInput.value : '').trim();
                if (!email || !ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) return;
                const payload = new FormData();
                payload.append('action', 'metis_contact_add_additional_email');
                payload.append('nonce', ajaxConfig.nonce);
                payload.append('cid', detailForm.dataset.contactCid || '');
                payload.append('email', email);
                fetch(ajaxConfig.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(function (res) { return res.json(); })
                    .then(function (response) {
                        if (!response || !response.success || !response.data) {
                            throw new Error(response && response.data ? response.data : 'Failed to add email');
                        }
                        const emails = Array.isArray(response.data.additional_emails) ? response.data.additional_emails : [];
                        renderAdditionalEmailChips(emails);
                        if (addEmailsHidden) addEmailsHidden.value = JSON.stringify(emails);
                        if (addEmailRows) {
                            addEmailRows.innerHTML = '';
                            if (emails.length) emails.forEach(function (entry) { addEmailRow(String(entry)); });
                            else addEmailRow('');
                            setAdditionalEmailsJson();
                        }
                        closeModal(additionalEmailModal);
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to add email', 'error');
                    });
            });
        }

        if (openNewsletterPlus) {
            openNewsletterPlus.addEventListener('click', function () {
                if (newsletterModalSelect) newsletterModalSelect.value = '';
                openModal(newsletterModal);
            });
        }
        if (newsletterModalCancel) {
            newsletterModalCancel.addEventListener('click', function () { closeModal(newsletterModal); });
        }
        if (newsletterModal) {
            newsletterModal.addEventListener('click', function (event) {
                if (event.target === newsletterModal) closeModal(newsletterModal);
            });
        }
        if (newsletterModalSave) {
            newsletterModalSave.addEventListener('click', function () {
                const listId = parseInt(newsletterModalSelect ? newsletterModalSelect.value : '0', 10);
                if (!listId || !ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) return;
                const payload = new FormData();
                payload.append('action', 'metis_contact_add_newsletter');
                payload.append('nonce', ajaxConfig.nonce);
                payload.append('cid', detailForm.dataset.contactCid || '');
                payload.append('list_id', String(listId));
                fetch(ajaxConfig.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(function (res) { return res.json(); })
                    .then(function (response) {
                        if (!response || !response.success || !response.data) {
                            throw new Error(response && response.data ? response.data : 'Failed to add subscription');
                        }
                        const existing = Array.from((newsletterView && newsletterView.querySelectorAll('.metis-remove-newsletter-chip')) || [])
                            .map(function (btn) {
                                return {
                                    id: parseInt(btn.getAttribute('data-list-id') || '0', 10),
                                    name: String(btn.parentNode && btn.parentNode.querySelector('span') ? btn.parentNode.querySelector('span').textContent : '').trim()
                                };
                            })
                            .filter(function (x) { return x.id > 0 && x.name; });
                        const next = existing.filter(function (x) { return x.id !== response.data.list_id; });
                        next.push({ id: response.data.list_id, name: response.data.name || '' });
                        next.sort(function (a, b) { return a.name.localeCompare(b.name); });
                        renderNewsletterChips(next);
                        closeModal(newsletterModal);
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to add subscription', 'error');
                    });
            });
        }

        inlineEditables.forEach(function (el) { wireInlineEditable(el); });

        if (tabButtons.length && tabPanels.length) {
            tabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const tab = btn.getAttribute('data-tab') || '';
                    tabButtons.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                    tabPanels.forEach(function (panel) {
                        panel.classList.toggle('is-active', (panel.getAttribute('data-tab-panel') || '') === tab);
                    });
                });
            });
        }

        if (additionalEmailsView) {
            additionalEmailsView.addEventListener('click', function (event) {
                const removeBtn = event.target && event.target.closest ? event.target.closest('.metis-chip-remove') : null;
                if (!removeBtn) return;
                const email = (removeBtn.getAttribute('data-email') || '').trim();
                if (!email || !ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) return;

                const payload = new FormData();
                payload.append('action', 'metis_contact_remove_additional_email');
                payload.append('nonce', ajaxConfig.nonce);
                payload.append('cid', detailForm.dataset.contactCid || '');
                payload.append('email', email);

                removeBtn.disabled = true;

                fetch(ajaxConfig.ajax_url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                })
                    .then(function (res) { return res.json(); })
                    .then(function (response) {
                        if (!response || !response.success || !response.data) {
                            throw new Error(response && response.data ? response.data : 'Failed to remove email');
                        }
                        const emails = Array.isArray(response.data.additional_emails) ? response.data.additional_emails : [];
                        renderAdditionalEmailChips(emails);
                        if (addEmailsHidden) addEmailsHidden.value = JSON.stringify(emails);
                        if (addEmailRows) {
                            addEmailRows.innerHTML = '';
                            if (emails.length) {
                                emails.forEach(function (entry) { addEmailRow(String(entry)); });
                            } else {
                                addEmailRow('');
                            }
                            setAdditionalEmailsJson();
                        }
                    })
                    .catch(function (err) {
                        showAlert(err.message || 'Failed to remove email', 'error');
                        removeBtn.disabled = false;
                    });
            });
        }

        document.addEventListener('click', function (event) {
            const removeRelBtn = event.target && event.target.closest ? event.target.closest('.metis-remove-relationship-chip') : null;
            if (!removeRelBtn) return;
            if (!ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) return;

            const payload = new FormData();
            payload.append('action', 'metis_contact_remove_relationship');
            payload.append('nonce', ajaxConfig.nonce);
            payload.append('cid', detailForm.dataset.contactCid || '');
            payload.append('related_cid', removeRelBtn.getAttribute('data-related-cid') || '');
            payload.append('relation_type', removeRelBtn.getAttribute('data-relation-type') || '');
            payload.append('notes', removeRelBtn.getAttribute('data-notes') || '');
            removeRelBtn.disabled = true;

            fetch(ajaxConfig.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    if (!response || !response.success) {
                        throw new Error(response && response.data ? response.data : 'Failed to remove relationship');
                    }
                    const chip = removeRelBtn.closest('.metis-relationship-chip');
                    if (chip) {
                        const chipList = chip.parentNode;
                        chip.remove();
                        if (chipList && !chipList.querySelector('.metis-relationship-chip') && relationshipsSection && !openRelationshipsPlus) {
                            relationshipsSection.style.display = 'none';
                        }
                    }
                })
                .catch(function (err) {
                    removeRelBtn.disabled = false;
                    showAlert(err.message || 'Failed to remove relationship', 'error');
                });
        });

        document.addEventListener('click', function (event) {
            const removeBtn = event.target && event.target.closest ? event.target.closest('.metis-remove-newsletter-chip') : null;
            if (!removeBtn || !ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) return;
            const listId = parseInt(removeBtn.getAttribute('data-list-id') || '0', 10);
            if (!listId) return;
            const payload = new FormData();
            payload.append('action', 'metis_contact_remove_newsletter');
            payload.append('nonce', ajaxConfig.nonce);
            payload.append('cid', detailForm.dataset.contactCid || '');
            payload.append('list_id', String(listId));
            fetch(ajaxConfig.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    if (!response || !response.success) {
                        throw new Error(response && response.data ? response.data : 'Failed to remove subscription');
                    }
                    const chip = removeBtn.closest('.metis-chip');
                    if (chip) chip.remove();
                    const remaining = Array.from((newsletterView && newsletterView.querySelectorAll('.metis-remove-newsletter-chip')) || [])
                        .map(function (btn) {
                            return {
                                id: parseInt(btn.getAttribute('data-list-id') || '0', 10),
                                name: String(btn.parentNode && btn.parentNode.querySelector('span') ? btn.parentNode.querySelector('span').textContent : '').trim()
                            };
                        })
                        .filter(function (x) { return x.id > 0 && x.name; });
                    renderNewsletterChips(remaining);
                })
                .catch(function (err) {
                    showAlert(err.message || 'Failed to remove subscription', 'error');
                });
        });

        detailForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (!ajaxConfig || !ajaxConfig.ajax_url || !ajaxConfig.nonce) return;

            setAdditionalEmailsJson();
            setRelationshipsJson();

            const payload = new FormData();
            payload.append('action', 'metis_contact_detail_save');
            payload.append('nonce', ajaxConfig.nonce);
            payload.append('cid', detailForm.dataset.contactCid || '');

            const firstNameEl = document.getElementById('metis-detail-first-name');
            const lastNameEl = document.getElementById('metis-detail-last-name');
            const emailEl = document.getElementById('metis-detail-email');
            const phoneEl = document.getElementById('metis-detail-phone');
            const preferredNameEl = document.getElementById('metis-detail-preferred-name');
            const preferredContactEl = document.getElementById('metis-detail-preferred-contact-method');
            const addressEl = document.getElementById('metis-detail-address');
            const cityEl = document.getElementById('metis-detail-city');
            const stateEl = document.getElementById('metis-detail-state');
            const zipEl = document.getElementById('metis-detail-zip');
            const birthdayEl = document.getElementById('metis-detail-birthday');
            const householdIdEl = document.getElementById('metis-detail-household-id');
            const doNotContactEl = document.getElementById('metis-detail-do-not-contact');
            const volunteerStatusEl = document.getElementById('metis-detail-volunteer-status');
            const anonymousDonorEl = document.getElementById('metis-detail-anonymous-donor');
            const newsletterListEls = Array.from(document.querySelectorAll('.metis-newsletter-list-input'));

            payload.append('first_name', firstNameEl ? firstNameEl.value : '');
            payload.append('last_name', lastNameEl ? lastNameEl.value : '');
            const emailReady = ensurePrimaryEmailSelection({ interactive: false });
            if (!emailReady || (emailModal && emailModal.classList.contains('metis-open'))) {
                pendingDetailSubmitAfterEmail = true;
                return;
            }
            payload.append('email', emailEl ? emailEl.value : '');
            payload.append('phone', phoneEl ? formatPhone(phoneEl.value) : '');
            payload.append('preferred_name', preferredNameEl ? preferredNameEl.value : '');
            payload.append('preferred_contact_method', preferredContactEl ? preferredContactEl.value : '');
            payload.append('address', addressEl ? addressEl.value : '');
            payload.append('city', cityEl ? cityEl.value : '');
            payload.append('state', stateEl ? stateEl.value : '');
            payload.append('zip', zipEl ? zipEl.value : '');
            payload.append('birthday', birthdayEl ? birthdayEl.value : '');
            payload.append('household_id', householdIdEl ? householdIdEl.value : '');
            payload.append('do_not_contact', doNotContactEl && doNotContactEl.checked ? '1' : '0');
            payload.append('volunteer_status', volunteerStatusEl && volunteerStatusEl.checked ? '1' : '0');
            payload.append('anonymous_donor', anonymousDonorEl && anonymousDonorEl.checked ? '1' : '0');
            payload.append('newsletter_list_ids', JSON.stringify(newsletterListEls.filter(function (cb) { return cb.checked; }).map(function (cb) { return parseInt(cb.value || '0', 10); }).filter(function (id) { return id > 0; })));
            payload.append('relationships_json', relationshipsHidden ? relationshipsHidden.value : '[]');
            payload.append('additional_emails_json', addEmailsHidden ? addEmailsHidden.value : '[]');

            fetch(ajaxConfig.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    if (!response || !response.success) {
                        throw new Error(response && response.data ? response.data : 'Failed to save contact details');
                    }
                    closeModal(editModal);
                    window.location.reload();
                })
                .catch(function (err) {
                    showAlert(err.message || 'Failed to save contact details', 'error');
                });
        });
    }
});
