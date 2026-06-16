window.MetisContactsModules = window.MetisContactsModules || {};

window.MetisContactsModules.initList = function (context) {
    const ajaxConfig = context.ajaxConfig;
    const escapeHtml = context.escapeHtml;
    const showAlert = context.showAlert;
    const openModal = context.openModal;
    const closeModal = context.closeModal;
    const request = context.request;
    const confirmAction = context.confirmAction;
    const listRoot = document.querySelector('.metis-contacts');
    const rowsContainer = document.getElementById('metis-contact-rows');

    if (listRoot && rowsContainer) {
        const canManage = listRoot.dataset.canManage === '1';
        const pageLabel = document.getElementById('metis-contacts-page');
        const prevBtn = document.getElementById('metis-contacts-prev');
        const nextBtn = document.getElementById('metis-contacts-next');
        const searchInput = document.getElementById('metis-contacts-search');
        const sortButtons = Array.from(document.querySelectorAll('.metis-contacts-table .metis-sortable'));
        const alertBox = document.getElementById('metis-contacts-alert');
        const statsCards = document.querySelectorAll('.metis-contacts-stat-value');
        const duplicateStatCard = statsCards.length > 3 ? statsCards[3] : null;

        let currentPage = 1;
        const PER_PAGE = 25;
        let sortKey = 'name';
        let sortDir = 'asc';

        function normalize(value) { return Metis.util.normalize(value); }
        function navigate(url) {
            var target = String(url || '').trim();
            if (!target) return false;
            if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
                return Metis.navigation.go(target);
            }
            window.location.assign(target);
            return true;
        }
        function removeRowsByCid(cids) {
            const targets = Array.isArray(cids) ? cids : [];
            targets.forEach(function (cid) {
                const row = rowsContainer.querySelector('.metis-contact-row[data-cid="' + String(cid || '') + '"]');
                if (row) row.remove();
            });
            updateStats();
            applyFiltersAndPagination();
        }

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
            sortButtons.forEach(function (button) {
                const key = button.dataset.sortKey;
                button.classList.remove('metis-sort-active', 'metis-sort-asc', 'metis-sort-desc');
                if (key === sortKey) {
                    button.classList.add('metis-sort-active', sortDir === 'asc' ? 'metis-sort-asc' : 'metis-sort-desc');
                }
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
                    emptyRow.className = 'metis-premium-row';
                    emptyRow.innerHTML = '<div class="metis-premium-cell metis-muted">No contacts match your search.</div>';
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
                if (href) navigate(href);
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
            if (window.Metis && Metis.time && typeof Metis.time.format === 'function') {
                return Metis.time.format(String(ts), { empty: '—' }) || '—';
            }
            const date = new Date(ts * 1000);
            return date.toLocaleString();
        }

        function buildRowHtml(contact) {
            const firstName = contact.first_name || '';
            const lastName = contact.last_name || '';
            const fullName = (firstName + ' ' + lastName).trim() || '(No name)';

            return '<div class="metis-premium-cell metis-contact-name-cell"><div class="metis-contact-name">' + escapeHtml(fullName) + '</div></div>' +
                '<div class="metis-premium-cell">' + escapeHtml(contact.email || '') + '</div>' +
                '<div class="metis-premium-cell">' + escapeHtml(formatUpdated(contact.updated_ts || 0)) + '</div>';
        }

        function upsertRow(contact) {
            const cid = String(contact.cid || '');
            const existing = rowsContainer.querySelector('.metis-contact-row[data-cid="' + cid + '"]');
            const firstName = contact.first_name || '';
            const lastName = contact.last_name || '';

            const target = existing || document.createElement('div');
            target.className = 'metis-premium-row metis-contact-row';
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
            const modal = document.getElementById('metis-modal-backdrop');
            const newBtn = document.getElementById('metis-contact-new-btn');
            const cancelBtn = document.getElementById('metis-contact-cancel');
            const form = document.getElementById('metis-form-grid');
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

                    if (typeof request !== 'function') {
                        showAlert('Contacts AJAX is not configured.', 'error');
                        return;
                    }

                    request('metis_contacts_save', {
                        first_name: firstNameInput.value || '',
                        last_name: lastNameInput.value || '',
                        email: emailInput.value || '',
                        phone: phoneInput.value || ''
                    })
                        .then(function (data) {
                            if (!data || !data.contact) {
                                throw new Error('Save failed');
                            }
                            upsertRow(data.contact);
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
            const importModal = document.getElementById('metis-contact-import-modal');
            const importOpenBtn = document.getElementById('metis-contact-import-btn');
            const importCancelBtn = document.getElementById('metis-contact-import-cancel');
            const importBackBtn = document.getElementById('metis-contact-import-back');
            const importNextBtn = document.getElementById('metis-contact-import-next');
            const importRunBtn = document.getElementById('metis-contact-import-run');
            const importFileInput = document.getElementById('metis-contact-import-file');
            const importFileMeta = document.getElementById('metis-contact-import-file-meta');
            const importSample = document.getElementById('metis-contact-import-sample');
            const importMappingFields = document.getElementById('metis-contact-import-mapping-fields');
            const importCreateLists = document.getElementById('metis-contact-import-create-lists');
            const importSummary = document.getElementById('metis-contact-import-summary');
            const importPreviewTable = document.getElementById('metis-contact-import-preview-table');
            const importErrors = document.getElementById('metis-contact-import-errors');
            const importStatus = document.getElementById('metis-contact-import-status');
            const importSteps = Array.from(document.querySelectorAll('#metis-contact-import-modal [data-import-step]'));
            const importFieldDefs = [
                { key: 'cid', label: 'Contact ID (optional)' },
                { key: 'first_name', label: 'First Name' },
                { key: 'last_name', label: 'Last Name' },
                { key: 'full_name', label: 'Full Name' },
                { key: 'email', label: 'Email' },
                { key: 'phone', label: 'Phone' },
                { key: 'newsletter_lists', label: 'Newsletter List(s)' }
            ];
            const importState = {
                token: '',
                headers: [],
                mapping: {},
                filename: '',
                rowCount: 0,
                sampleRows: [],
                summary: null,
                previewRows: [],
                errors: [],
                step: 'upload',
                busy: false,
                createMissingLists: true
            };

            function setImportBusy(isBusy) {
                importState.busy = !!isBusy;
                if (importOpenBtn) importOpenBtn.disabled = !!isBusy;
                if (importCancelBtn) importCancelBtn.disabled = !!isBusy;
                if (importBackBtn) importBackBtn.disabled = !!isBusy;
                if (importNextBtn) importNextBtn.disabled = !!isBusy;
                if (importRunBtn) importRunBtn.disabled = !!isBusy;
                if (importFileInput) importFileInput.disabled = !!isBusy;
                if (importCreateLists) importCreateLists.disabled = !!isBusy;
                const mappingInputs = importMappingFields ? importMappingFields.querySelectorAll('select') : [];
                mappingInputs.forEach(function (select) { select.disabled = !!isBusy; });
            }

            function setImportStatus(message, type) {
                if (!importStatus) return;
                const text = String(message || '').trim();
                if (!text) {
                    importStatus.style.display = 'none';
                    importStatus.className = 'metis-alert';
                    importStatus.textContent = '';
                    return;
                }
                importStatus.style.display = '';
                importStatus.className = 'metis-alert ' + (type === 'error' ? 'metis-alert-error' : type === 'success' ? 'metis-alert-success' : 'metis-alert-info');
                importStatus.textContent = text;
            }

            function setImportStep(step) {
                importState.step = step === 'mapping' ? 'mapping' : 'upload';
                importSteps.forEach(function (panel) {
                    const isActive = panel.getAttribute('data-import-step') === importState.step;
                    panel.hidden = !isActive;
                });
                if (importBackBtn) importBackBtn.hidden = importState.step !== 'mapping';
                if (importNextBtn) importNextBtn.hidden = importState.step !== 'upload';
                if (importRunBtn) importRunBtn.hidden = importState.step !== 'mapping';
            }

            function resetImportState() {
                importState.token = '';
                importState.headers = [];
                importState.mapping = {};
                importState.filename = '';
                importState.rowCount = 0;
                importState.sampleRows = [];
                importState.summary = null;
                importState.previewRows = [];
                importState.errors = [];
                importState.createMissingLists = true;
                if (importFileInput) importFileInput.value = '';
                if (importCreateLists) importCreateLists.checked = true;
                if (importFileMeta) importFileMeta.textContent = '';
                if (importSample) {
                    importSample.hidden = true;
                    importSample.innerHTML = '';
                }
                if (importMappingFields) importMappingFields.innerHTML = '';
                if (importSummary) importSummary.innerHTML = '';
                if (importPreviewTable) importPreviewTable.innerHTML = '';
                if (importErrors) importErrors.innerHTML = '';
                setImportStatus('', '');
                setImportStep('upload');
                setImportBusy(false);
            }

            function renderImportSample() {
                if (!importSample) return;
                if (!importState.sampleRows.length || !importState.headers.length) {
                    importSample.hidden = true;
                    importSample.innerHTML = '';
                    return;
                }
                const head = importState.headers.map(function (header) {
                    return '<th>' + escapeHtml(header) + '</th>';
                }).join('');
                const rows = importState.sampleRows.map(function (row) {
                    const cells = importState.headers.map(function (header) {
                        return '<td>' + escapeHtml(String(row[header] || '')) + '</td>';
                    }).join('');
                    return '<tr>' + cells + '</tr>';
                }).join('');
                importSample.hidden = false;
                importSample.innerHTML = '<div class="metis-contact-import-map-header">Sample Rows</div><div class="metis-contact-import-table-wrap"><table class="metis-contact-import-table"><thead><tr>' + head + '</tr></thead><tbody>' + rows + '</tbody></table></div>';
            }

            function renderImportMappingFields() {
                if (!importMappingFields) return;
                const options = ['<option value="">Not mapped</option>'].concat(importState.headers.map(function (header) {
                    return '<option value="' + escapeHtml(header) + '">' + escapeHtml(header) + '</option>';
                })).join('');
                importMappingFields.innerHTML = importFieldDefs.map(function (field) {
                    const value = String(importState.mapping[field.key] || '');
                    return '<label class="metis-contact-import-map-row">' +
                        '<span>' + escapeHtml(field.label) + '</span>' +
                        '<select class="metis-select" data-import-map="' + escapeHtml(field.key) + '">' + options + '</select>' +
                        '</label>';
                }).join('');
                importFieldDefs.forEach(function (field) {
                    const select = importMappingFields.querySelector('[data-import-map="' + field.key + '"]');
                    if (select) select.value = String(importState.mapping[field.key] || '');
                });
            }

            function renderImportAnalysis() {
                if (importSummary) {
                    const summary = importState.summary || {};
                    const missingLists = Array.isArray(summary.missing_lists) ? summary.missing_lists : [];
                    importSummary.innerHTML =
                        '<div><strong>' + escapeHtml(String(summary.filename || importState.filename || '')) + '</strong></div>' +
                        '<div>' + escapeHtml(String(summary.total_rows || 0)) + ' rows uploaded</div>' +
                        '<div>' + escapeHtml(String(summary.valid_rows || 0)) + ' valid, ' + escapeHtml(String(summary.skipped_rows || 0)) + ' skipped</div>' +
                        '<div>' + escapeHtml(String(summary.create_count || 0)) + ' new, ' + escapeHtml(String(summary.update_count || 0)) + ' updates</div>' +
                        (missingLists.length ? '<div>Missing lists: ' + escapeHtml(missingLists.join(', ')) + '</div>' : '');
                }

                if (importPreviewTable) {
                    if (!importState.previewRows.length) {
                        importPreviewTable.innerHTML = '<div class="metis-muted">No preview rows available yet.</div>';
                    } else {
                        const rows = importState.previewRows.map(function (row) {
                            return '<tr>' +
                                '<td>' + escapeHtml(String(row.row_number || '')) + '</td>' +
                                '<td>' + escapeHtml(String(row.action || '')) + '</td>' +
                                '<td>' + escapeHtml(String(row.name || '')) + '</td>' +
                                '<td>' + escapeHtml(String(row.email || '')) + '</td>' +
                                '<td>' + escapeHtml(Array.isArray(row.lists) ? row.lists.join(', ') : '') + '</td>' +
                                '</tr>';
                        }).join('');
                        importPreviewTable.innerHTML = '<div class="metis-contact-import-table-wrap"><table class="metis-contact-import-table"><thead><tr><th>Row</th><th>Action</th><th>Name</th><th>Email</th><th>Lists</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
                    }
                }

                if (importErrors) {
                    if (!importState.errors.length) {
                        importErrors.innerHTML = '';
                    } else {
                        importErrors.innerHTML = '<div class="metis-contact-import-map-header">Issues</div><ul class="metis-contact-import-error-list">' + importState.errors.map(function (error) {
                            return '<li>' + escapeHtml(String(error || '')) + '</li>';
                        }).join('') + '</ul>';
                    }
                }
            }

            function currentImportMapping() {
                const mapping = {};
                importFieldDefs.forEach(function (field) {
                    const select = importMappingFields ? importMappingFields.querySelector('[data-import-map="' + field.key + '"]') : null;
                    mapping[field.key] = select ? String(select.value || '') : '';
                });
                importState.mapping = mapping;
                return mapping;
            }

            function analyzeImportStage() {
                if (!importState.token) {
                    return Promise.reject(new Error('Upload a CSV file first.'));
                }
                const mapping = currentImportMapping();
                importState.createMissingLists = !!(importCreateLists && importCreateLists.checked);
                setImportBusy(true);
                setImportStatus('Analyzing import…', 'info');
                return request('metis_contacts_import_csv_analyze', {
                    token: importState.token,
                    mapping: JSON.stringify(mapping),
                    create_missing_lists: importState.createMissingLists ? '1' : '0'
                }).then(function (data) {
                    importState.summary = data.summary || null;
                    importState.previewRows = Array.isArray(data.preview_rows) ? data.preview_rows : [];
                    importState.errors = Array.isArray(data.errors) ? data.errors : [];
                    importState.mapping = data.mapping || mapping;
                    renderImportMappingFields();
                    renderImportAnalysis();
                    setImportStatus(importState.errors.length ? 'Review the import issues before proceeding.' : 'Import analysis is ready.', importState.errors.length ? 'error' : 'success');
                    return data;
                }).finally(function () {
                    setImportBusy(false);
                });
            }

            if (importOpenBtn && importModal) {
                importOpenBtn.addEventListener('click', function () {
                    resetImportState();
                    openModal(importModal);
                });
            }
            if (importCancelBtn && importModal) {
                importCancelBtn.addEventListener('click', function () {
                    closeModal(importModal);
                    resetImportState();
                });
            }
            if (importModal) {
                importModal.addEventListener('click', function (event) {
                    if (event.target === importModal && !importState.busy) {
                        closeModal(importModal);
                        resetImportState();
                    }
                });
            }
            if (importBackBtn) {
                importBackBtn.addEventListener('click', function () {
                    setImportStatus('', '');
                    setImportStep('upload');
                });
            }
            if (importNextBtn) {
                importNextBtn.addEventListener('click', function () {
                    const file = importFileInput && importFileInput.files ? importFileInput.files[0] : null;
                    if (!file) {
                        setImportStatus('Choose a CSV file first.', 'error');
                        return;
                    }
                    setImportBusy(true);
                    setImportStatus('Uploading CSV…', 'info');
                    request('metis_contacts_import_csv_stage', {
                        import_file: file
                    }).then(function (data) {
                        importState.token = String(data.token || '');
                        importState.filename = String(data.filename || file.name || '');
                        importState.headers = Array.isArray(data.headers) ? data.headers : [];
                        importState.rowCount = parseInt(String(data.row_count || '0'), 10) || 0;
                        importState.mapping = data.mapping || {};
                        importState.sampleRows = Array.isArray(data.sample_rows) ? data.sample_rows : [];
                        if (importFileMeta) {
                            importFileMeta.textContent = importState.filename + ' • ' + importState.rowCount + ' rows';
                        }
                        renderImportSample();
                        renderImportMappingFields();
                        setImportStep('mapping');
                        return analyzeImportStage();
                    }).catch(function (err) {
                        setImportStatus(err.message || 'Failed to stage CSV.', 'error');
                    }).finally(function () {
                        setImportBusy(false);
                    });
                });
            }
            if (importMappingFields) {
                importMappingFields.addEventListener('change', function (event) {
                    const target = event.target;
                    if (!target || !target.matches('[data-import-map]')) return;
                    analyzeImportStage().catch(function (err) {
                        setImportStatus(err.message || 'Failed to analyze import.', 'error');
                    });
                });
            }
            if (importCreateLists) {
                importCreateLists.addEventListener('change', function () {
                    analyzeImportStage().catch(function (err) {
                        setImportStatus(err.message || 'Failed to analyze import.', 'error');
                    });
                });
            }
            if (importRunBtn) {
                importRunBtn.addEventListener('click', function () {
                    if (!importState.token) {
                        setImportStatus('Upload a CSV file first.', 'error');
                        return;
                    }
                    const mapping = currentImportMapping();
                    importState.createMissingLists = !!(importCreateLists && importCreateLists.checked);
                    setImportBusy(true);
                    setImportStatus('Importing contacts…', 'info');
                    request('metis_contacts_import_csv_run', {
                        token: importState.token,
                        mapping: JSON.stringify(mapping),
                        create_missing_lists: importState.createMissingLists ? '1' : '0'
                    }).then(function (data) {
                        const imported = parseInt(String(data.imported_count || '0'), 10) || 0;
                        const created = parseInt(String(data.created_count || '0'), 10) || 0;
                        const updated = parseInt(String(data.updated_count || '0'), 10) || 0;
                        const skipped = parseInt(String(data.skipped_count || '0'), 10) || 0;
                        const errors = Array.isArray(data.errors) ? data.errors : [];
                        if (errors.length) {
                            importState.errors = errors;
                            renderImportAnalysis();
                        }
                        showAlert('Imported ' + imported + ' contacts (' + created + ' new, ' + updated + ' updated, ' + skipped + ' skipped).', errors.length ? 'warning' : 'success');
                        closeModal(importModal);
                        resetImportState();
                        window.location.reload();
                    }).catch(function (err) {
                        setImportStatus(err.message || 'Import failed.', 'error');
                    }).finally(function () {
                        setImportBusy(false);
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

            function setDuplicateGroups(groups) {
                if (!dupJsonEl) return;
                const nextGroups = Array.isArray(groups) ? groups : [];
                dupJsonEl.textContent = JSON.stringify(nextGroups);
                if (duplicateStatCard) {
                    duplicateStatCard.textContent = nextGroups.length.toLocaleString();
                }
            }

            function recommendPrimaryCid(members) {
                const list = Array.isArray(members) ? members.filter(function (member) {
                    return member && member.cid;
                }) : [];
                if (!list.length) return '';

                const donorMembers = list.filter(function (member) {
                    return String(member.did || '').trim() !== '';
                });
                const ranked = donorMembers.length ? donorMembers.slice() : list.slice();
                ranked.sort(function (a, b) {
                    const aTotal = Number(a.donation_total || 0);
                    const bTotal = Number(b.donation_total || 0);
                    if (aTotal === bTotal) {
                        return String(a.cid || '').localeCompare(String(b.cid || ''));
                    }
                    return bTotal - aTotal;
                });
                return String((ranked[0] && ranked[0].cid) || '');
            }

            function applyMergeToDuplicateGroups(primaryCid, mergedCids, primaryContact) {
                const mergedSet = new Set((Array.isArray(mergedCids) ? mergedCids : []).map(function (cid) {
                    return String(cid || '').trim();
                }).filter(Boolean));
                const groups = parseDuplicateGroups();
                const nextGroups = groups.map(function (group) {
                    const members = Array.isArray(group.members) ? group.members : [];
                    const nextMembers = [];
                    const seen = new Set();

                    members.forEach(function (member) {
                        const cid = String((member && member.cid) || '').trim();
                        if (!cid || mergedSet.has(cid) || seen.has(cid)) return;
                        let nextMember = Object.assign({}, member);
                        if (cid === String(primaryCid || '').trim() && primaryContact) {
                            nextMember.first_name = primaryContact.first_name || nextMember.first_name || '';
                            nextMember.last_name = primaryContact.last_name || nextMember.last_name || '';
                            nextMember.email = primaryContact.email || nextMember.email || '';
                            nextMember.did = primaryContact.did || nextMember.did || '';
                        }
                        seen.add(cid);
                        nextMembers.push(nextMember);
                    });

                    if (nextMembers.length < 2) return null;

                    return {
                        match: group && group.match ? group.match : 'name',
                        recommended_cid: recommendPrimaryCid(nextMembers),
                        members: nextMembers
                    };
                }).filter(Boolean);

                setDuplicateGroups(nextGroups);
                return nextGroups;
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
                    dupList.innerHTML = '<div class="metis-muted">No potential duplicates found.</div>';
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
                        '<button type="button" class="metis-btn metis-dup-merge-btn">Merge</button>' +
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
                                : '<span class="metis-muted">Drop one profile here</span>';
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
                                mergeZone.innerHTML = '<span class="metis-muted">Drop profiles here</span>';
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
                            if (typeof request !== 'function') return;
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
                                request('metis_contacts_merge_duplicates', {
                                    primary_cid: primaryCid,
                                    duplicate_cids: JSON.stringify(mergeCids)
                                })
                                .then(function (data) {
                                    const result = data || {};
                                    if (result.primary_contact) {
                                        upsertRow(result.primary_contact);
                                    }
                                    applyMergeToDuplicateGroups(primaryCid, mergeCids, result.primary_contact || null);
                                    showAlert('Contacts merged successfully.', 'success');
                                    removeRowsByCid(mergeCids);
                                    mergeBtn.disabled = false;
                                    pendingMergeFn = null;
                                    closeModal(dupConfirmModal);
                                    renderDuplicateGroups();
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
                    if (typeof request !== 'function') return;
                    confirmAction('Run one-time cleanup to consolidate older split system merge notes?', {
                        title: 'Run Cleanup',
                        confirmLabel: 'Run Cleanup'
                    }).then(function (confirmed) {
                        if (!confirmed) return;
                        dupCleanupBtn.disabled = true;
                        return request('metis_contacts_cleanup_merge_notes')
                            .then(function (data) {
                                const result = data || {};
                                showAlert(
                                    'Cleanup complete: consolidated ' + (result.groups_consolidated || 0) +
                                    ', created ' + (result.notes_created || 0) +
                                    ', deleted ' + (result.notes_deleted || 0) + '.',
                                    'success'
                                );
                                dupCleanupBtn.disabled = false;
                                renderDuplicateGroups();
                            })
                            .catch(function (err) {
                                dupCleanupBtn.disabled = false;
                                showAlert(err.message || 'Cleanup failed.', 'error');
                            });
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
};
