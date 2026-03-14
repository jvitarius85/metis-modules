document.addEventListener('DOMContentLoaded', function () {
    const ajax = window.metisBoardAjax || null;

    const openModal = Metis.modal.open;
    const closeModal = Metis.modal.close;
    const showAlert = Metis.util.notify;

    function post(action, payload) {
        return Metis.request.post(ajax, action, payload || {}, 'Board AJAX config missing.');
    }

    function postForm(action, formData) {
        return Metis.request.postForm(ajax, action, formData, 'Board AJAX config missing.');
    }

    const rowsContainer = document.getElementById('metis-board-meeting-rows');
    const searchInput = document.getElementById('metis-board-search');
    const sortButtons = Array.from(document.querySelectorAll('.metis-board-table .mw-sortable'));
    const pageLabel = document.getElementById('metis-board-page');
    const prevBtn = document.getElementById('metis-board-prev');
    const nextBtn = document.getElementById('metis-board-next');

    let currentPage = 1;
    const perPage = 20;
    let sortKey = 'date';
    let sortDir = 'desc';

    function norm(v) { return String(v || '').toLowerCase().trim(); }

    function allRows() {
        if (!rowsContainer) return [];
        return Array.from(rowsContainer.querySelectorAll('.metis-board-row'));
    }

    function applySortUi() {
        const labelMap = { title: 'Meeting', committee: 'Committee', date: 'Date' };
        sortButtons.forEach(function (btn) {
            const key = String(btn.dataset.sortKey || '');
            const label = labelMap[key] || key;
            btn.textContent = label + ' ' + (key === sortKey ? (sortDir === 'asc' ? '▲' : '▼') : '▾');
        });
    }

    function applyFilters() {
        const rows = allRows();
        const q = norm(searchInput ? searchInput.value : '');
        const filtered = rows.filter(function (row) {
            const blob = norm(row.dataset.search);
            return q === '' || blob.indexOf(q) !== -1;
        });

        filtered.sort(function (a, b) {
            if (sortKey === 'title') {
                const av = norm(a.dataset.titleSort);
                const bv = norm(b.dataset.titleSort);
                return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
            }
            if (sortKey === 'committee') {
                const av = norm(a.dataset.committeeSort);
                const bv = norm(b.dataset.committeeSort);
                return sortDir === 'asc' ? av.localeCompare(bv) : bv.localeCompare(av);
            }
            const av = parseInt(String(a.dataset.dateSort || '0'), 10);
            const bv = parseInt(String(b.dataset.dateSort || '0'), 10);
            return sortDir === 'asc' ? av - bv : bv - av;
        });

        rows.forEach(function (row) {
            row.style.display = 'none';
            rowsContainer.appendChild(row);
        });

        filtered.forEach(function (row) {
            rowsContainer.appendChild(row);
        });

        const totalPages = Math.max(1, Math.ceil(filtered.length / perPage));
        if (currentPage > totalPages) currentPage = totalPages;

        filtered.forEach(function (row, idx) {
            const start = (currentPage - 1) * perPage;
            const end = start + perPage;
            const visible = idx >= start && idx < end;
            row.style.display = visible ? '' : 'none';
            if (visible) {
                row.classList.remove('metis-row-even', 'metis-row-odd');
                row.classList.add((idx - start) % 2 === 0 ? 'metis-row-even' : 'metis-row-odd');
            }
        });

        if (pageLabel) pageLabel.textContent = 'Page ' + currentPage + ' of ' + totalPages;
        if (prevBtn) prevBtn.disabled = currentPage <= 1;
        if (nextBtn) nextBtn.disabled = currentPage >= totalPages;
        applySortUi();
    }

    if (rowsContainer) {
        rowsContainer.addEventListener('click', function (event) {
            const editBtn = event.target.closest('.metis-board-edit-meeting');
            if (editBtn) {
                event.preventDefault();
                event.stopPropagation();
                const row = editBtn.closest('.metis-board-row');
                if (!row) return;
                let data = null;
                try { data = JSON.parse(String(row.dataset.meetingJson || '{}')); } catch (e) { data = null; }
                if (!data) return;
                populateMeetingForm(data);
                openModal(document.getElementById('metis-board-meeting-modal'));
                return;
            }
            const row = event.target.closest('.metis-board-row');
            if (!row) return;
            const href = String(row.dataset.href || '');
            if (href !== '') window.location.href = href;
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            currentPage = 1;
            applyFilters();
        });
    }

    sortButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const key = String(btn.dataset.sortKey || '');
            if (!key) return;
            if (sortKey === key) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortKey = key;
                sortDir = key === 'date' ? 'desc' : 'asc';
            }
            currentPage = 1;
            applyFilters();
        });
    });

    prevBtn?.addEventListener('click', function () {
        currentPage -= 1;
        applyFilters();
    });

    nextBtn?.addEventListener('click', function () {
        currentPage += 1;
        applyFilters();
    });

    applyFilters();

    const detailWrap = document.querySelector('.metis-board-detail');
    if (detailWrap) {
        const tabButtons = Array.from(detailWrap.querySelectorAll('.metis-board-tab'));
        const panels = Array.from(detailWrap.querySelectorAll('.metis-board-tab-panel'));
        const canManageDetail = String(detailWrap.dataset.canManage || '0') === '1';
        const meetingIdDetail = String(detailWrap.dataset.meetingId || '0');
        const workflowModal = document.getElementById('metis-board-workflow-modal');
        const workflowManageBtn = detailWrap.querySelector('#metis-board-manage-workflow');
        const agendaBuilder = detailWrap.querySelector('#metis-board-agenda-builder');
        const agendaHidden = detailWrap.querySelector('#metis-board-agenda-json');
        const agendaTemplateNode = detailWrap.querySelector('#metis-board-agenda-section-template');

        let agendaTemplateCache = [];
        let decisionTemplateCache = [];
        let agendaSections = [];

        function setActiveTab(tabKey) {
            tabButtons.forEach(function (btn) {
                const active = String(btn.dataset.tab || '') === tabKey;
                btn.classList.toggle('metis-board-tab-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                const active = String(panel.dataset.panel || '') === tabKey;
                panel.hidden = !active;
            });
        }

        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setActiveTab(String(btn.dataset.tab || 'overview'));
            });
        });
        setActiveTab('overview');

        function splitLines(value) {
            return String(value || '')
                .split(/\r?\n/)
                .map(function (line) { return line.trim(); })
                .filter(function (line) { return line !== ''; });
        }

        function parseJson(value, fallback) {
            try {
                const parsed = JSON.parse(String(value || ''));
                return parsed;
            } catch (e) {
                return fallback;
            }
        }

        function parseTemplateItems(value) {
            const parsed = parseJson(value, []);
            return Array.isArray(parsed) ? parsed.map(function (v) { return String(v || '').trim(); }).filter(Boolean) : [];
        }

        function normalizeAgendaSection(raw, index) {
            const section = raw && typeof raw === 'object' ? raw : {};
            const items = Array.isArray(section.items) ? section.items : [];
            let points = Array.isArray(section.decision_points) ? section.decision_points : [];
            if (points.length < 1) {
                const legacyTitle = String(section.decision_title || '').trim();
                const legacyCode = String(section.decision_template_code || '').trim();
                if (legacyTitle !== '' || legacyCode !== '') {
                    points = [{ decision_template_code: legacyCode, decision_title: legacyTitle }];
                }
            }
            points = points.map(function (point) {
                const row = point && typeof point === 'object' ? point : {};
                return {
                    decision_template_code: String(row.decision_template_code || ''),
                    decision_title: String(row.decision_title || '').trim(),
                    item_title: String(row.item_title || '').trim(),
                };
            }).filter(function (point) {
                return point.decision_template_code !== '' || point.decision_title !== '' || point.item_title !== '';
            });
            return {
                order: index + 1,
                section_template_code: String(section.section_template_code || ''),
                section_name: String(section.section_name || section.custom_title || section.section || ''),
                items: items.map(function (v) { return String(v || '').trim(); }).filter(Boolean),
                decision_points: points,
                notes: String(section.notes || ''),
            };
        }

        function agendaOptionHtml(list, value, type) {
            const safeValue = String(value || '');
            const options = ['<option value="">' + (type === 'agenda' ? 'Custom section' : 'None') + '</option>'];
            list.forEach(function (row) {
                const code = String(row.template_code || '');
                if (!code) return;
                const label = String(type === 'agenda' ? row.name : row.title);
                options.push('<option value="' + code.replace(/"/g, '&quot;') + '"' + (safeValue === code ? ' selected' : '') + '>' + label.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>');
            });
            return options.join('');
        }

        function syncAgendaSnapshot() {
            agendaSections = agendaSections.map(function (row, idx) {
                return normalizeAgendaSection(row, idx);
            });
            if (agendaHidden) agendaHidden.value = JSON.stringify(agendaSections);
        }

        function createAgendaSection(partial) {
            const section = normalizeAgendaSection(partial || {}, agendaSections.length);
            agendaSections.push(section);
            renderAgendaBuilder();
        }

        function renderAgendaBuilder() {
            if (!agendaBuilder || !agendaTemplateNode) return;
            agendaBuilder.innerHTML = '';
            if (agendaSections.length < 1) {
                createAgendaSection({});
                return;
            }

            agendaSections.forEach(function (row, idx) {
                const frag = agendaTemplateNode.content.cloneNode(true);
                const card = frag.querySelector('.metis-board-agenda-section-card');
                if (!card) return;
                card.dataset.index = String(idx);
                const numberEl = card.querySelector('.metis-board-agenda-section-number');
                if (numberEl) numberEl.textContent = 'Section ' + String(idx + 1);

                const templateSelect = card.querySelector('.metis-board-agenda-template');
                const titleInput = card.querySelector('.metis-board-agenda-title');
                const itemsInput = card.querySelector('.metis-board-agenda-items');
                const decisionList = card.querySelector('.metis-board-agenda-decision-list');
                const addDecisionBtn = card.querySelector('.metis-board-agenda-decision-add');
                const notesInput = card.querySelector('.metis-board-agenda-notes');
                const removeBtn = card.querySelector('.metis-board-agenda-remove');
                const decisionRowTemplate = detailWrap.querySelector('#metis-board-agenda-decision-row-template');

                if (templateSelect) {
                    templateSelect.innerHTML = agendaOptionHtml(agendaTemplateCache, row.section_template_code, 'agenda');
                    templateSelect.addEventListener('change', function () {
                        const selectedCode = String(templateSelect.value || '');
                        row.section_template_code = selectedCode;
                        const tmpl = agendaTemplateCache.find(function (t) { return String(t.template_code || '') === selectedCode; }) || null;
                        if (tmpl) {
                            row.section_name = String(tmpl.name || row.section_name || '');
                            row.items = parseTemplateItems(tmpl.default_items_json || '[]');
                            if (titleInput) titleInput.value = row.section_name;
                            if (itemsInput) itemsInput.value = row.items.join('\n');
                        }
                        syncAgendaSnapshot();
                    });
                }

                if (titleInput) {
                    titleInput.value = row.section_name || '';
                    titleInput.addEventListener('input', function () {
                        row.section_name = String(titleInput.value || '').trim();
                        syncAgendaSnapshot();
                    });
                }

                if (itemsInput) {
                    itemsInput.value = Array.isArray(row.items) ? row.items.join('\n') : '';
                    itemsInput.addEventListener('input', function () {
                        row.items = splitLines(itemsInput.value || '');
                        syncAgendaSnapshot();
                    });
                }

                function renderDecisionPoints() {
                    if (!decisionList || !decisionRowTemplate) return;
                    const points = Array.isArray(row.decision_points) ? row.decision_points : [];
                    decisionList.innerHTML = '';
                    if (points.length < 1) {
                        decisionList.innerHTML = '<div class="mw-muted">No decision points added.</div>';
                        return;
                    }
                    points.forEach(function (point, pointIdx) {
                        const pointRow = point && typeof point === 'object' ? point : { decision_template_code: '', decision_title: '', item_title: '' };
                        const pointFrag = decisionRowTemplate.content.cloneNode(true);
                        const pointWrap = pointFrag.querySelector('.metis-board-agenda-decision-row');
                        if (!pointWrap) return;
                        pointWrap.dataset.pointIndex = String(pointIdx);
                        const pointSelect = pointWrap.querySelector('.metis-board-agenda-decision-template');
                        const pointTitle = pointWrap.querySelector('.metis-board-agenda-decision-title');
                        const pointItem = pointWrap.querySelector('.metis-board-agenda-decision-item');
                        const pointRemove = pointWrap.querySelector('.metis-board-agenda-decision-remove');

                        if (pointSelect) {
                            pointSelect.innerHTML = agendaOptionHtml(decisionTemplateCache, pointRow.decision_template_code, 'decision');
                            pointSelect.addEventListener('change', function () {
                                const selectedCode = String(pointSelect.value || '');
                                pointRow.decision_template_code = selectedCode;
                                const tmpl = decisionTemplateCache.find(function (t) { return String(t.template_code || '') === selectedCode; }) || null;
                                if (tmpl && !String(pointRow.decision_title || '').trim()) {
                                    pointRow.decision_title = String(tmpl.title || '');
                                    if (pointTitle) pointTitle.value = pointRow.decision_title;
                                }
                                row.decision_points[pointIdx] = pointRow;
                                syncAgendaSnapshot();
                            });
                        }

                        if (pointTitle) {
                            pointTitle.value = pointRow.decision_title || '';
                            pointTitle.addEventListener('input', function () {
                                pointRow.decision_title = String(pointTitle.value || '').trim();
                                row.decision_points[pointIdx] = pointRow;
                                syncAgendaSnapshot();
                            });
                        }

                        if (pointItem) {
                            pointItem.value = pointRow.item_title || '';
                            pointItem.addEventListener('input', function () {
                                pointRow.item_title = String(pointItem.value || '').trim();
                                row.decision_points[pointIdx] = pointRow;
                                syncAgendaSnapshot();
                            });
                        }

                        if (pointRemove) {
                            pointRemove.addEventListener('click', function () {
                                row.decision_points.splice(pointIdx, 1);
                                syncAgendaSnapshot();
                                renderDecisionPoints();
                            });
                        }

                        decisionList.appendChild(pointFrag);
                    });
                }

                if (!Array.isArray(row.decision_points)) row.decision_points = [];
                if (addDecisionBtn) {
                    addDecisionBtn.addEventListener('click', function () {
                        row.decision_points.push({ decision_template_code: '', decision_title: '', item_title: '' });
                        syncAgendaSnapshot();
                        renderDecisionPoints();
                    });
                }
                renderDecisionPoints();

                if (notesInput) {
                    notesInput.value = row.notes || '';
                    notesInput.addEventListener('input', function () {
                        row.notes = String(notesInput.value || '').trim();
                        syncAgendaSnapshot();
                    });
                }

                if (removeBtn) {
                    removeBtn.disabled = agendaSections.length <= 1;
                    removeBtn.addEventListener('click', function () {
                        if (agendaSections.length <= 1) return;
                        agendaSections.splice(idx, 1);
                        syncAgendaSnapshot();
                        renderAgendaBuilder();
                    });
                }

                agendaBuilder.appendChild(frag);
            });

            syncAgendaSnapshot();
        }

        function loadWorkflowTemplates() {
            return post('metis_board_get_workflow_templates', {})
                .then(function (data) {
                    agendaTemplateCache = Array.isArray(data && data.agenda) ? data.agenda.filter(function (r) { return parseInt(String(r.is_active || '1'), 10) === 1; }) : [];
                    decisionTemplateCache = Array.isArray(data && data.decisions) ? data.decisions.filter(function (r) { return parseInt(String(r.is_active || '1'), 10) === 1; }) : [];
                    renderWorkflowTemplateLists();
                    renderAgendaBuilder();
                })
                .catch(function (err) {
                    renderAgendaBuilder();
                    showAlert(err.message || 'Failed to load workflow templates.', 'error');
                });
        }

        function renderWorkflowTemplateLists() {
            const agendaList = document.getElementById('metis-board-agenda-template-list');
            const decisionList = document.getElementById('metis-board-decision-template-list');
            if (agendaList) {
                if (agendaTemplateCache.length < 1) {
                    agendaList.innerHTML = '<div class="mw-muted">No agenda section templates yet.</div>';
                } else {
                    agendaList.innerHTML = agendaTemplateCache.map(function (row) {
                        const code = String(row.template_code || '');
                        const name = String(row.name || 'Untitled');
                        const desc = String(row.description || '');
                        return '' +
                            '<div class="metis-board-template-row" data-template-id="' + String(row.id || '0') + '" data-template-code="' + code + '">' +
                                '<div><strong>' + name + '</strong><div class="mw-muted">' + desc + '</div></div>' +
                                '<div class="metis-board-template-actions">' +
                                    '<button type="button" class="mw-btn-xs metis-board-agenda-template-edit">Edit</button>' +
                                    '<button type="button" class="mw-btn-xs mw-btn-danger metis-board-agenda-template-delete">Disable</button>' +
                                '</div>' +
                            '</div>';
                    }).join('');
                }
            }
            if (decisionList) {
                if (decisionTemplateCache.length < 1) {
                    decisionList.innerHTML = '<div class="mw-muted">No decision templates yet.</div>';
                } else {
                    decisionList.innerHTML = decisionTemplateCache.map(function (row) {
                        const code = String(row.template_code || '');
                        const title = String(row.title || 'Untitled');
                        const desc = String(row.description || '');
                        return '' +
                            '<div class="metis-board-template-row" data-template-id="' + String(row.id || '0') + '" data-template-code="' + code + '">' +
                                '<div><strong>' + title + '</strong><div class="mw-muted">' + desc + '</div></div>' +
                                '<div class="metis-board-template-actions">' +
                                    '<button type="button" class="mw-btn-xs metis-board-decision-template-edit">Edit</button>' +
                                    '<button type="button" class="mw-btn-xs mw-btn-danger metis-board-decision-template-delete">Disable</button>' +
                                '</div>' +
                            '</div>';
                    }).join('');
                }
            }
        }

        if (agendaBuilder && agendaHidden) {
            const initial = parseJson(agendaHidden.value, []);
            agendaSections = Array.isArray(initial) ? initial.map(function (row, idx) { return normalizeAgendaSection(row, idx); }) : [];
            detailWrap.querySelector('#metis-board-agenda-add-section')?.addEventListener('click', function () {
                createAgendaSection({});
            });
            loadWorkflowTemplates();
        }

        workflowManageBtn?.addEventListener('click', function () {
            openModal(workflowModal);
            loadWorkflowTemplates();
        });

        document.getElementById('metis-board-agenda-template-reset')?.addEventListener('click', function () {
            const id = document.getElementById('metis-board-agenda-template-id');
            const name = document.getElementById('metis-board-agenda-template-name');
            const sort = document.getElementById('metis-board-agenda-template-sort');
            const items = document.getElementById('metis-board-agenda-template-items');
            const req = document.getElementById('metis-board-agenda-template-required');
            if (id) id.value = '0';
            if (name) name.value = '';
            if (sort) sort.value = '0';
            if (items) items.value = '';
            if (req) req.checked = false;
        });

        document.getElementById('metis-board-decision-template-reset')?.addEventListener('click', function () {
            const id = document.getElementById('metis-board-decision-template-id');
            const name = document.getElementById('metis-board-decision-template-name');
            const sort = document.getElementById('metis-board-decision-template-sort');
            const desc = document.getElementById('metis-board-decision-template-description');
            if (id) id.value = '0';
            if (name) name.value = '';
            if (sort) sort.value = '0';
            if (desc) desc.value = '';
        });

        document.getElementById('metis-board-agenda-template-form')?.addEventListener('submit', function (event) {
            event.preventDefault();
            const id = document.getElementById('metis-board-agenda-template-id');
            const name = document.getElementById('metis-board-agenda-template-name');
            const sort = document.getElementById('metis-board-agenda-template-sort');
            const items = document.getElementById('metis-board-agenda-template-items');
            const req = document.getElementById('metis-board-agenda-template-required');
            post('metis_board_save_agenda_template', {
                template_id: id ? id.value : '0',
                name: name ? name.value : '',
                sort_order: sort ? sort.value : '0',
                default_items_json: JSON.stringify(splitLines(items ? items.value : '')),
                is_required: req && req.checked ? '1' : '0',
                is_active: '1',
            }).then(function () {
                showAlert('Agenda template saved.', 'success');
                document.getElementById('metis-board-agenda-template-reset')?.click();
                loadWorkflowTemplates();
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save agenda template.', 'error');
            });
        });

        document.getElementById('metis-board-decision-template-form')?.addEventListener('submit', function (event) {
            event.preventDefault();
            const id = document.getElementById('metis-board-decision-template-id');
            const name = document.getElementById('metis-board-decision-template-name');
            const sort = document.getElementById('metis-board-decision-template-sort');
            const desc = document.getElementById('metis-board-decision-template-description');
            post('metis_board_save_decision_template', {
                template_id: id ? id.value : '0',
                title: name ? name.value : '',
                description: desc ? desc.value : '',
                sort_order: sort ? sort.value : '0',
                is_active: '1',
            }).then(function () {
                showAlert('Decision template saved.', 'success');
                document.getElementById('metis-board-decision-template-reset')?.click();
                loadWorkflowTemplates();
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save decision template.', 'error');
            });
        });

        workflowModal?.addEventListener('click', function (event) {
            const agendaEditBtn = event.target.closest('.metis-board-agenda-template-edit');
            if (agendaEditBtn) {
                const row = agendaEditBtn.closest('.metis-board-template-row');
                if (!row) return;
                const id = parseInt(String(row.dataset.templateId || '0'), 10);
                const selected = agendaTemplateCache.find(function (r) { return parseInt(String(r.id || '0'), 10) === id; }) || null;
                if (!selected) return;
                const idEl = document.getElementById('metis-board-agenda-template-id');
                const nameEl = document.getElementById('metis-board-agenda-template-name');
                const sortEl = document.getElementById('metis-board-agenda-template-sort');
                const itemsEl = document.getElementById('metis-board-agenda-template-items');
                const reqEl = document.getElementById('metis-board-agenda-template-required');
                if (idEl) idEl.value = String(selected.id || 0);
                if (nameEl) nameEl.value = String(selected.name || '');
                if (sortEl) sortEl.value = String(selected.sort_order || 0);
                if (itemsEl) itemsEl.value = parseTemplateItems(selected.default_items_json || '[]').join('\n');
                if (reqEl) reqEl.checked = parseInt(String(selected.is_required || '0'), 10) === 1;
                return;
            }
            const agendaDeleteBtn = event.target.closest('.metis-board-agenda-template-delete');
            if (agendaDeleteBtn) {
                const row = agendaDeleteBtn.closest('.metis-board-template-row');
                const id = String(row && row.dataset.templateId ? row.dataset.templateId : '');
                if (!id) return;
                post('metis_board_delete_agenda_template', { template_id: id }).then(function () {
                    showAlert('Agenda template disabled.', 'success');
                    loadWorkflowTemplates();
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to disable template.', 'error');
                });
                return;
            }
            const decisionEditBtn = event.target.closest('.metis-board-decision-template-edit');
            if (decisionEditBtn) {
                const row = decisionEditBtn.closest('.metis-board-template-row');
                if (!row) return;
                const id = parseInt(String(row.dataset.templateId || '0'), 10);
                const selected = decisionTemplateCache.find(function (r) { return parseInt(String(r.id || '0'), 10) === id; }) || null;
                if (!selected) return;
                const idEl = document.getElementById('metis-board-decision-template-id');
                const nameEl = document.getElementById('metis-board-decision-template-name');
                const sortEl = document.getElementById('metis-board-decision-template-sort');
                const descEl = document.getElementById('metis-board-decision-template-description');
                if (idEl) idEl.value = String(selected.id || 0);
                if (nameEl) nameEl.value = String(selected.title || '');
                if (sortEl) sortEl.value = String(selected.sort_order || 0);
                if (descEl) descEl.value = String(selected.description || '');
                return;
            }
            const decisionDeleteBtn = event.target.closest('.metis-board-decision-template-delete');
            if (decisionDeleteBtn) {
                const row = decisionDeleteBtn.closest('.metis-board-template-row');
                const id = String(row && row.dataset.templateId ? row.dataset.templateId : '');
                if (!id) return;
                post('metis_board_delete_decision_template', { template_id: id }).then(function () {
                    showAlert('Decision template disabled.', 'success');
                    loadWorkflowTemplates();
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to disable template.', 'error');
                });
            }
        });

        detailWrap.querySelector('#metis-board-agenda-minutes-form')?.addEventListener('submit', function (event) {
            event.preventDefault();
            syncAgendaSnapshot();
            post('metis_board_update_meeting_detail', {
                meeting_id: meetingIdDetail,
                agenda_json: detailWrap.querySelector('#metis-board-agenda-json')?.value || ''
            }).then(function () {
                return post('metis_board_sync_decision_points', {
                    meeting_id: meetingIdDetail,
                    agenda_json: detailWrap.querySelector('#metis-board-agenda-json')?.value || ''
                });
            }).then(function (data) {
                const created = parseInt(String(data && data.created_decisions != null ? data.created_decisions : '0'), 10) || 0;
                showAlert(created > 0 ? ('Agenda saved. ' + created + ' decision point(s) added to Decisions.') : 'Agenda saved.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save agenda.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-prepare-workspace')?.addEventListener('click', function () {
            post('metis_board_prepare_meeting_workspace', { meeting_id: meetingIdDetail }).then(function () {
                showAlert('Meeting workspace is ready in Drive.', 'success');
                if (typeof detailWrap.__metisBoardLoadDrive === 'function') detailWrap.__metisBoardLoadDrive();
            }).catch(function (err) {
                showAlert(err.message || 'Failed to prepare meeting workspace.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-generate-packet')?.addEventListener('click', function () {
            syncAgendaSnapshot();
            post('metis_board_update_meeting_detail', {
                meeting_id: meetingIdDetail,
                agenda_json: detailWrap.querySelector('#metis-board-agenda-json')?.value || ''
            }).then(function () {
                return post('metis_board_generate_packet_pdf', {
                    meeting_id: meetingIdDetail,
                    packet_source_minutes_meeting_id: detailWrap.querySelector('#metis-board-packet-prev-minutes')?.value || '0',
                    packet_financial_document_id: detailWrap.querySelector('#metis-board-packet-financial-doc')?.value || '0'
                });
            }).then(function () {
                showAlert('Agenda and packet PDFs generated and uploaded.', 'success');
                if (typeof detailWrap.__metisBoardLoadDrive === 'function') detailWrap.__metisBoardLoadDrive();
                window.setTimeout(function () { window.location.reload(); }, 450);
            }).catch(function (err) {
                showAlert(err.message || 'Failed to generate packet PDFs.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-packet-form')?.addEventListener('submit', function (event) {
            event.preventDefault();
            post('metis_board_update_meeting_detail', {
                meeting_id: meetingIdDetail,
                board_packet_notes: detailWrap.querySelector('#metis-board-packet-notes')?.value || '',
                packet_source_minutes_meeting_id: detailWrap.querySelector('#metis-board-packet-prev-minutes')?.value || '0',
                packet_financial_document_id: detailWrap.querySelector('#metis-board-packet-financial-doc')?.value || '0',
                packet_published: detailWrap.querySelector('#metis-board-packet-publish')?.checked ? '1' : '0'
            }).then(function () {
                showAlert('Packet metadata saved.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save packet metadata.', 'error');
            });
        });

        detailWrap.querySelectorAll('.metis-board-decision-save').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const row = btn.closest('.metis-board-decision-row');
                if (!row) return;
                post('metis_board_update_decision', {
                    decision_id: String(row.dataset.decisionId || ''),
                    outcome: row.querySelector('.metis-board-decision-outcome')?.value || 'pending',
                    votes_for: row.querySelector('.metis-board-decision-for')?.value || '0',
                    votes_against: row.querySelector('.metis-board-decision-against')?.value || '0',
                    votes_abstain: row.querySelector('.metis-board-decision-abstain')?.value || '0'
                }).then(function () {
                    showAlert('Decision votes updated.', 'success');
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to update decision.', 'error');
                });
            });
        });

        function applyQuorumSummary(data) {
            const presentEl = detailWrap.querySelector('#metis-board-present-count');
            const eligibleEl = detailWrap.querySelector('#metis-board-eligible-count');
            const requiredEl = detailWrap.querySelector('#metis-board-required-count');
            const statusEl = detailWrap.querySelector('#metis-board-quorum-status');
            if (presentEl && data && data.present_count != null) presentEl.textContent = String(data.present_count);
            if (eligibleEl && data && data.eligible_count != null) eligibleEl.textContent = String(data.eligible_count);
            if (requiredEl && data && data.required_count != null) requiredEl.textContent = String(data.required_count);
            if (statusEl && data && data.quorum_met != null) {
                statusEl.textContent = data.quorum_met ? 'Met' : 'Pending';
                statusEl.classList.toggle('mw-chip-success', !!data.quorum_met);
            }
        }

        detailWrap.querySelectorAll('.metis-board-attendance-save').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const row = btn.closest('.metis-board-attendance-row');
                if (!row) return;
                post('metis_board_upsert_attendance', {
                    meeting_id: meetingIdDetail,
                    person_id: String(row.dataset.personId || ''),
                    role_label: row.querySelector('.metis-board-attendance-role')?.value || '',
                    status: row.querySelector('.metis-board-attendance-status')?.value || 'absent',
                    notes: row.querySelector('.metis-board-attendance-notes')?.value || ''
                }).then(function (data) {
                    applyQuorumSummary(data);
                    showAlert('Attendance saved.', 'success');
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to save attendance.', 'error');
                });
            });
        });

        detailWrap.querySelector('#metis-board-attendance-lock')?.addEventListener('change', function (event) {
            if (!canManageDetail) return;
            post('metis_board_update_meeting_detail', {
                meeting_id: meetingIdDetail,
                attendance_locked: event.target.checked ? '1' : '0'
            }).then(function () {
                showAlert('Attendance lock updated.', 'success');
            }).catch(function (err) {
                event.target.checked = !event.target.checked;
                showAlert(err.message || 'Failed to update attendance lock.', 'error');
            });
        });
    }

    const meetingModal = document.getElementById('metis-board-meeting-modal');
    const committeeModal = document.getElementById('metis-board-committee-modal');
    const decisionModal = document.getElementById('metis-board-decision-modal');
    const actionModal = document.getElementById('metis-board-action-modal');
    const announcementModal = document.getElementById('metis-board-announcement-modal');

    document.querySelectorAll('.metis-contacts-modal').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal(modal);
        });
    });

    document.querySelectorAll('.metis-board-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.closest('.metis-contacts-modal'));
        });
    });

    function dtLocal(sqlDate) {
        const raw = String(sqlDate || '').trim();
        if (raw === '') return '';
        return raw.replace(' ', 'T').slice(0, 16);
    }

    function populateMeetingForm(data) {
        const set = function (id, val) {
            const el = document.getElementById(id);
            if (el) el.value = val;
        };
        set('metis-board-meeting-id', String(data.id || 0));
        set('metis-board-meeting-title', String(data.title || ''));
        set('metis-board-meeting-committee', String(data.committee_id || ''));
        set('metis-board-meeting-date', dtLocal(String(data.meeting_date || '')));
        set('metis-board-meeting-type', String(data.meeting_type || 'board'));
        set('metis-board-meeting-location', String(data.location || ''));
        set('metis-board-meeting-status', String(data.status || 'draft'));
        set('metis-board-meeting-calendar', String(data.google_calendar_event_id || ''));
        set('metis-board-meeting-drive', String(data.google_drive_folder_id || ''));
        set('metis-board-meeting-agenda', String(data.agenda_json || ''));
        set('metis-board-meeting-minutes', String(data.minutes_html || ''));
    }

    function clearMeetingForm() {
        populateMeetingForm({ id: 0, meeting_type: 'board', status: 'draft' });
    }

    document.getElementById('metis-board-new-meeting')?.addEventListener('click', function () {
        clearMeetingForm();
        openModal(meetingModal);
    });
    document.getElementById('metis-board-new-committee')?.addEventListener('click', function () {
        const form = document.getElementById('metis-board-committee-form');
        form?.reset();
        const idEl = document.getElementById('metis-board-committee-id');
        if (idEl) idEl.value = '0';
        openModal(committeeModal);
    });
    document.getElementById('metis-board-new-decision')?.addEventListener('click', function () {
        document.getElementById('metis-board-decision-form')?.reset();
        openModal(decisionModal);
    });
    document.getElementById('metis-board-new-decision-inline')?.addEventListener('click', function () {
        document.getElementById('metis-board-decision-form')?.reset();
        const inlineMeeting = document.querySelector('.metis-board-detail')?.dataset.meetingId || '';
        const meetingSelect = document.getElementById('metis-board-decision-meeting');
        if (meetingSelect && inlineMeeting !== '') meetingSelect.value = inlineMeeting;
        openModal(decisionModal);
    });
    document.getElementById('metis-board-new-action')?.addEventListener('click', function () {
        document.getElementById('metis-board-action-form')?.reset();
        openModal(actionModal);
    });
    document.getElementById('metis-board-new-action-inline')?.addEventListener('click', function () {
        document.getElementById('metis-board-action-form')?.reset();
        const inlineMeeting = document.querySelector('.metis-board-detail')?.dataset.meetingId || '';
        const meetingSelect = document.getElementById('metis-board-action-meeting');
        if (meetingSelect && inlineMeeting !== '') meetingSelect.value = inlineMeeting;
        openModal(actionModal);
    });
    document.getElementById('metis-board-new-announcement')?.addEventListener('click', function () {
        document.getElementById('metis-board-announcement-form')?.reset();
        openModal(announcementModal);
    });

    document.querySelectorAll('.metis-board-edit-committee').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const row = btn.closest('.mw-premium-row');
            if (!row) return;
            let data = null;
            try { data = JSON.parse(String(row.dataset.committeeJson || '{}')); } catch (e) { data = null; }
            if (!data) return;
            const set = function (id, val) {
                const el = document.getElementById(id);
                if (el) el.value = val;
            };
            set('metis-board-committee-id', String(data.id || 0));
            set('metis-board-committee-name', String(data.name || ''));
            set('metis-board-committee-chair', String(data.chair_person_id || ''));
            set('metis-board-committee-description', String(data.description || ''));
            set('metis-board-committee-active', String(parseInt(String(data.is_active || '1'), 10) === 1 ? '1' : '0'));
            openModal(committeeModal);
        });
    });

    document.getElementById('metis-board-meeting-form')?.addEventListener('submit', function (event) {
        event.preventDefault();
        post('metis_board_save_meeting', {
            meeting_id: document.getElementById('metis-board-meeting-id')?.value || '0',
            title: document.getElementById('metis-board-meeting-title')?.value || '',
            committee_id: document.getElementById('metis-board-meeting-committee')?.value || '',
            meeting_date: document.getElementById('metis-board-meeting-date')?.value || '',
            meeting_type: document.getElementById('metis-board-meeting-type')?.value || 'board',
            location: document.getElementById('metis-board-meeting-location')?.value || '',
            status: document.getElementById('metis-board-meeting-status')?.value || 'draft',
            google_calendar_event_id: document.getElementById('metis-board-meeting-calendar')?.value || '',
            google_drive_folder_id: document.getElementById('metis-board-meeting-drive')?.value || '',
            agenda_json: document.getElementById('metis-board-meeting-agenda')?.value || '',
            minutes_html: document.getElementById('metis-board-meeting-minutes')?.value || ''
        }).then(function () {
            closeModal(meetingModal);
            showAlert('Meeting saved.', 'success');
            window.setTimeout(function () { window.location.reload(); }, 350);
        }).catch(function (err) {
            showAlert(err.message || 'Failed to save meeting.', 'error');
        });
    });

    document.getElementById('metis-board-committee-form')?.addEventListener('submit', function (event) {
        event.preventDefault();
        post('metis_board_save_committee', {
            committee_id: document.getElementById('metis-board-committee-id')?.value || '0',
            name: document.getElementById('metis-board-committee-name')?.value || '',
            chair_person_id: document.getElementById('metis-board-committee-chair')?.value || '',
            description: document.getElementById('metis-board-committee-description')?.value || '',
            is_active: document.getElementById('metis-board-committee-active')?.value || '1'
        }).then(function () {
            closeModal(committeeModal);
            showAlert('Committee saved.', 'success');
            window.setTimeout(function () { window.location.reload(); }, 350);
        }).catch(function (err) {
            showAlert(err.message || 'Failed to save committee.', 'error');
        });
    });

    document.getElementById('metis-board-decision-form')?.addEventListener('submit', function (event) {
        event.preventDefault();
        post('metis_board_save_decision', {
            meeting_id: document.getElementById('metis-board-decision-meeting')?.value || '',
            title: document.getElementById('metis-board-decision-title')?.value || '',
            agenda_section_title: document.getElementById('metis-board-decision-section')?.value || '',
            agenda_item_title: document.getElementById('metis-board-decision-item')?.value || '',
            decision_text: document.getElementById('metis-board-decision-text')?.value || '',
            votes_for: document.getElementById('metis-board-decision-for')?.value || '0',
            votes_against: document.getElementById('metis-board-decision-against')?.value || '0',
            votes_abstain: document.getElementById('metis-board-decision-abstain')?.value || '0',
            outcome: document.getElementById('metis-board-decision-outcome')?.value || 'pending'
        }).then(function () {
            closeModal(decisionModal);
            showAlert('Decision recorded.', 'success');
            window.setTimeout(function () { window.location.reload(); }, 350);
        }).catch(function (err) {
            showAlert(err.message || 'Failed to save decision.', 'error');
        });
    });

    document.getElementById('metis-board-action-form')?.addEventListener('submit', function (event) {
        event.preventDefault();
        post('metis_board_save_action_item', {
            meeting_id: document.getElementById('metis-board-action-meeting')?.value || '',
            owner_person_id: document.getElementById('metis-board-action-owner')?.value || '',
            title: document.getElementById('metis-board-action-title')?.value || '',
            description: document.getElementById('metis-board-action-description')?.value || '',
            due_date: document.getElementById('metis-board-action-due')?.value || '',
            priority: document.getElementById('metis-board-action-priority')?.value || 'normal',
            status: document.getElementById('metis-board-action-status')?.value || 'open'
        }).then(function () {
            closeModal(actionModal);
            showAlert('Action item saved.', 'success');
            window.setTimeout(function () { window.location.reload(); }, 350);
        }).catch(function (err) {
            showAlert(err.message || 'Failed to save action item.', 'error');
        });
    });

    document.getElementById('metis-board-announcement-form')?.addEventListener('submit', function (event) {
        event.preventDefault();
        post('metis_board_save_announcement', {
            title: document.getElementById('metis-board-announcement-title')?.value || '',
            status: document.getElementById('metis-board-announcement-status')?.value || 'draft',
            publish_at: document.getElementById('metis-board-announcement-publish')?.value || '',
            body_html: document.getElementById('metis-board-announcement-body')?.value || ''
        }).then(function () {
            closeModal(announcementModal);
            showAlert('Announcement saved.', 'success');
            window.setTimeout(function () { window.location.reload(); }, 350);
        }).catch(function (err) {
            showAlert(err.message || 'Failed to save announcement.', 'error');
        });
    });

    const driveWrap = document.querySelector('.metis-board-drive-wrap');
    if (driveWrap) {
        const driveRows = document.getElementById('metis-board-drive-rows');
        const linkedDocRows = document.getElementById('metis-board-linked-doc-rows');
        const driveSearch = document.getElementById('metis-board-drive-search');
        const drivePath = document.getElementById('metis-board-drive-path');
        const driveRefresh = document.getElementById('metis-board-drive-refresh');
        const driveUp = document.getElementById('metis-board-drive-up');
        const driveUpload = document.getElementById('metis-board-drive-upload');
        const driveUploadInput = document.getElementById('metis-board-drive-upload-input');
        const driveNewFolder = document.getElementById('metis-board-drive-new-folder');
        const driveFolderModal = document.getElementById('metis-board-drive-folder-modal');
        const driveFolderForm = document.getElementById('metis-board-drive-folder-form');
        const driveFolderName = document.getElementById('metis-board-drive-folder-name');
        const driveFolderSetMeeting = document.getElementById('metis-board-drive-folder-set-meeting');
        const docMetaModal = document.getElementById('metis-board-doc-meta-modal');
        const docMetaForm = document.getElementById('metis-board-doc-meta-form');
        const docMetaTitle = document.getElementById('metis-board-doc-meta-title');
        const docMetaType = document.getElementById('metis-board-doc-meta-type');

        const meetingId = String(driveWrap.dataset.meetingId || '0');
        const canManage = String(driveWrap.dataset.canManage || '0') === '1';
        let folderId = String(driveWrap.dataset.folderId || '').trim() || 'root';
        let parentId = '';
        let searchTimer = null;
        let pendingDocAction = null;

        function fmtBytes(value) {
            const n = parseInt(String(value || '0'), 10);
            if (!n || n <= 0) return '—';
            const units = ['B', 'KB', 'MB', 'GB'];
            let idx = 0;
            let size = n;
            while (size >= 1024 && idx < units.length - 1) {
                size /= 1024;
                idx += 1;
            }
            return (idx === 0 ? String(Math.round(size)) : size.toFixed(1)) + ' ' + units[idx];
        }

        function fmtDate(value) {
            const raw = String(value || '').trim();
            if (!raw) return '—';
            const d = new Date(raw);
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleString();
        }

        function escHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function renderLinkedDocuments(docs) {
            if (!linkedDocRows) return;
            const list = Array.isArray(docs) ? docs : [];
            if (list.length < 1) {
                linkedDocRows.innerHTML = '<div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No meeting documents linked.</div></div>';
                return;
            }
            linkedDocRows.innerHTML = list.map(function (doc) {
                const docId = parseInt(String(doc && doc.id != null ? doc.id : '0'), 10) || 0;
                const title = String(doc && doc.title != null ? doc.title : '');
                const docTypeLabel = String(doc && doc.doc_type_label != null ? doc.doc_type_label : (doc && doc.doc_type != null ? doc.doc_type : 'Document'));
                const link = String(doc && doc.google_drive_url != null ? doc.google_drive_url : '');
                return '' +
                    '<div class="mw-premium-row">' +
                        '<div class="mw-premium-cell"><strong>' + escHtml(title || 'Document') + '</strong></div>' +
                        '<div class="mw-premium-cell">' + escHtml(docTypeLabel) + '</div>' +
                        '<div class="mw-premium-cell">' + (link ? ('<a href="' + escHtml(link) + '" target="_blank" rel="noopener">Open file</a>') : '<span class="mw-muted">—</span>') + '</div>' +
                        '<div class="mw-premium-cell">' + (canManage ? ('<button type="button" class="mw-btn-xs mw-btn-danger metis-board-unlink-doc" data-document-id="' + String(docId) + '">Unlink</button>') : '<span class="mw-muted">—</span>') + '</div>' +
                    '</div>';
            }).join('');
        }

        function refreshLinkedDocuments() {
            return post('metis_board_get_meeting_documents', { meeting_id: meetingId }).then(function (data) {
                renderLinkedDocuments((data && data.documents) || []);
                return data;
            });
        }

        function renderDriveRows(files) {
            if (!driveRows) return;
            const list = Array.isArray(files) ? files : [];
            if (list.length === 0) {
                driveRows.innerHTML = '<div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No files found in this folder.</div></div>';
                return;
            }
            driveRows.innerHTML = list.map(function (file) {
                const id = String(file.id || '');
                const name = String(file.name || 'Untitled');
                const mime = String(file.mimeType || '');
                const isFolder = !!file.isFolder;
                const link = String(file.webViewLink || '');
                const actions = [];
                if (isFolder) actions.push('<button type="button" class="mw-btn-xs metis-board-drive-open-folder" data-id="' + id + '">Open</button>');
                if (canManage && isFolder) actions.push('<button type="button" class="mw-btn-xs metis-board-drive-set-folder" data-id="' + id + '">Use as Meeting Folder</button>');
                if (link) actions.push('<a class="mw-btn-xs mw-btn-ghost" href="' + escHtml(link) + '" target="_blank" rel="noopener">View</a>');
                if (canManage) {
                    actions.push(
                        '<button type="button" class="mw-btn-xs metis-board-drive-link-file"' +
                        ' data-id="' + escHtml(id) + '"' +
                        ' data-name="' + escHtml(name) + '"' +
                        ' data-mime="' + escHtml(mime) + '"' +
                        ' data-link="' + escHtml(link) + '"' +
                        ' data-size="' + escHtml(String(file.size || '')) + '"' +
                        '>Link</button>'
                    );
                }
                return '' +
                    '<div class="mw-premium-row metis-board-drive-row">' +
                        '<div class="mw-premium-cell"><strong>' + escHtml(name) + '</strong></div>' +
                        '<div class="mw-premium-cell">' + escHtml(isFolder ? 'Folder' : (mime || 'File')) + '</div>' +
                        '<div class="mw-premium-cell">' + fmtDate(file.modifiedTime) + '</div>' +
                        '<div class="mw-premium-cell">' + (isFolder ? '—' : fmtBytes(file.size)) + '</div>' +
                        '<div class="mw-premium-cell"><div class="metis-board-drive-actions">' + actions.join(' ') + '</div></div>' +
                    '</div>';
            }).join('');
        }

        function loadDrive() {
            if (drivePath) drivePath.innerHTML = 'Folder: <code>' + folderId + '</code>';
            post('metis_board_drive_list', {
                meeting_id: meetingId,
                folder_id: folderId,
                search: driveSearch ? driveSearch.value : ''
            }).then(function (data) {
                folderId = String((data && data.folder_id) || folderId || 'root');
                parentId = String((data && data.parent_id) || '');
                if (driveUp) driveUp.disabled = !parentId;
                if (drivePath) drivePath.innerHTML = 'Folder: <code>' + folderId + '</code>';
                renderDriveRows((data && data.files) || []);
            }).catch(function (err) {
                showAlert(err.message || 'Failed to load Drive folder.', 'error');
            });
        }
        if (detailWrap) detailWrap.__metisBoardLoadDrive = loadDrive;

        driveRows?.addEventListener('click', function (event) {
            const openFolderBtn = event.target.closest('.metis-board-drive-open-folder');
            if (openFolderBtn) {
                folderId = String(openFolderBtn.dataset.id || folderId);
                loadDrive();
                return;
            }
            const setFolderBtn = event.target.closest('.metis-board-drive-set-folder');
            if (setFolderBtn) {
                post('metis_board_drive_set_meeting_folder', {
                    meeting_id: meetingId,
                    folder_id: String(setFolderBtn.dataset.id || '')
                }).then(function () {
                    showAlert('Meeting folder updated.', 'success');
                    folderId = String(setFolderBtn.dataset.id || folderId);
                    loadDrive();
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to set meeting folder.', 'error');
                });
                return;
            }
            const linkBtn = event.target.closest('.metis-board-drive-link-file');
            if (linkBtn) {
                pendingDocAction = {
                    mode: 'link',
                    file_id: String(linkBtn.dataset.id || ''),
                    title: String(linkBtn.dataset.name || ''),
                    mime_type: String(linkBtn.dataset.mime || ''),
                    web_view_link: String(linkBtn.dataset.link || ''),
                    size: String(linkBtn.dataset.size || '')
                };
                if (docMetaTitle) docMetaTitle.value = pendingDocAction.title || 'Packet Document';
                if (docMetaType) docMetaType.value = 'supporting_doc';
                openModal(docMetaModal);
            }
        });

        driveSearch?.addEventListener('input', function () {
            if (searchTimer) window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(loadDrive, 260);
        });
        driveRefresh?.addEventListener('click', loadDrive);
        driveUp?.addEventListener('click', function () {
            if (!parentId) return;
            folderId = parentId;
            loadDrive();
        });

        if (canManage) {
            driveNewFolder?.addEventListener('click', function () {
                if (driveFolderName) driveFolderName.value = '';
                if (driveFolderSetMeeting) driveFolderSetMeeting.checked = true;
                openModal(driveFolderModal);
            });
            driveFolderForm?.addEventListener('submit', function (event) {
                event.preventDefault();
                post('metis_board_drive_create_folder', {
                    meeting_id: meetingId,
                    parent_id: folderId,
                    folder_name: driveFolderName ? driveFolderName.value : '',
                    set_as_meeting: driveFolderSetMeeting && driveFolderSetMeeting.checked ? '1' : '0'
                }).then(function () {
                    closeModal(driveFolderModal);
                    showAlert('Folder created.', 'success');
                    loadDrive();
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to create folder.', 'error');
                });
            });
            driveUpload?.addEventListener('click', function () {
                driveUploadInput?.click();
            });
            driveUploadInput?.addEventListener('change', function () {
                const f = driveUploadInput.files && driveUploadInput.files[0] ? driveUploadInput.files[0] : null;
                if (!f) return;
                pendingDocAction = {
                    mode: 'upload',
                    file: f,
                    title: String(f.name || 'Packet Document')
                };
                if (docMetaTitle) docMetaTitle.value = pendingDocAction.title;
                if (docMetaType) docMetaType.value = 'supporting_doc';
                openModal(docMetaModal);
            });
        }

        linkedDocRows?.addEventListener('click', function (event) {
            const btn = event.target.closest('.metis-board-unlink-doc');
            if (!btn) return;
            const docId = String(btn.dataset.documentId || '');
            if (!docId) return;
            post('metis_board_drive_unlink_document', { document_id: docId }).then(function (data) {
                showAlert('Document unlinked.', 'success');
                renderLinkedDocuments((data && data.documents) || []);
            }).catch(function (err) {
                showAlert(err.message || 'Failed to unlink document.', 'error');
            });
        });

        docMetaForm?.addEventListener('submit', function (event) {
            event.preventDefault();
            const itemTitle = String(docMetaTitle ? docMetaTitle.value : '').trim();
            const docType = String(docMetaType ? docMetaType.value : 'supporting_doc');
            if (!pendingDocAction || itemTitle === '') {
                showAlert('Item name is required.', 'error');
                return;
            }
            if (pendingDocAction.mode === 'link') {
                post('metis_board_drive_link_document', {
                    meeting_id: meetingId,
                    file_id: pendingDocAction.file_id || '',
                    title: itemTitle,
                    doc_type: docType,
                    mime_type: pendingDocAction.mime_type || '',
                    web_view_link: pendingDocAction.web_view_link || '',
                    size: pendingDocAction.size || ''
                }).then(function (data) {
                    closeModal(docMetaModal);
                    pendingDocAction = null;
                    showAlert('File linked to this meeting.', 'success');
                    renderLinkedDocuments((data && data.documents) || []);
                    loadDrive();
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to link file.', 'error');
                });
                return;
            }

            const fd = new FormData();
            fd.set('meeting_id', meetingId);
            fd.set('folder_id', folderId);
            fd.set('doc_type', docType);
            fd.set('item_title', itemTitle);
            fd.set('file', pendingDocAction.file);
            postForm('metis_board_drive_upload', fd).then(function (data) {
                if (driveUploadInput) driveUploadInput.value = '';
                closeModal(docMetaModal);
                pendingDocAction = null;
                showAlert('File uploaded and linked.', 'success');
                renderLinkedDocuments((data && data.documents) || []);
                loadDrive();
            }).catch(function (err) {
                showAlert(err.message || 'Failed to upload file.', 'error');
            });
        });
        docMetaModal?.querySelectorAll('.metis-board-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                pendingDocAction = null;
                if (driveUploadInput) driveUploadInput.value = '';
            });
        });

        refreshLinkedDocuments().catch(function () {});
        loadDrive();
    }
});
