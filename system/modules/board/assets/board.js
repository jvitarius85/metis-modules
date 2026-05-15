document.addEventListener('DOMContentLoaded', function () {
    const ajax = window.metisBoardAjax || null;

    const openModal = Metis.modal.open;
    const closeModal = Metis.modal.close;
    const showAlert = Metis.util.notify;

    function navigate(url) {
        var target = String(url || '').trim();
        if (!target) return false;
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            return Metis.navigation.go(target);
        }
        window.location.assign(target);
        return true;
    }

    function confirmAction(message, options) {
        if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
            return Metis.confirm.open(Object.assign({}, options || {}, {
                message: String(message || 'Are you sure?')
            }));
        }
        return Promise.resolve(false);
    }

    function promptAction(options) {
        if (typeof window.metis_prompt === 'function') {
            return window.metis_prompt(options || {});
        }
        if (window.Metis && Metis.prompt && typeof Metis.prompt.open === 'function') {
            return Metis.prompt.open(options || {});
        }
        return Promise.resolve(null);
    }

    function post(action, payload) {
        return Metis.request.post(ajax, action, payload || {}, 'Board AJAX config missing.');
    }

    function postForm(action, formData) {
        return Metis.request.postForm(ajax, action, formData, 'Board AJAX config missing.');
    }

    function escHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    const boardWrap = document.querySelector('.metis-board');
    const detailWrap = document.querySelector('.metis-board-detail');
    const canManageBoard = String(boardWrap?.dataset.canManage || '0') === '1';
    const rowsContainer = document.getElementById('metis-board-meeting-rows');
    const committeeRowsContainer = document.getElementById('metis-board-committee-rows');
    const announcementRowsContainer = document.getElementById('metis-board-announcement-rows');
    const bylawsDisplay = document.getElementById('metis-board-bylaws-display');
    const bylawsMeta = document.getElementById('metis-board-bylaws-meta');
    const bylawsSignedLink = document.getElementById('metis-board-bylaws-signed-link');
    const bylawsPreview = document.getElementById('metis-board-bylaws-format-preview');
    const bylawsViewModal = document.getElementById('metis-board-bylaws-view-modal');
    const bylawsViewMeta = document.getElementById('metis-board-bylaws-view-meta');
    const bylawsReader = document.getElementById('metis-board-bylaws-reader');
    const bylawsToc = document.getElementById('metis-board-bylaws-toc');
    const bylawsSearch = document.getElementById('metis-board-bylaws-search');
    const bylawsSearchCount = document.getElementById('metis-board-bylaws-search-count');
    const bylawsSearchPrev = document.getElementById('metis-board-bylaws-search-prev');
    const bylawsSearchNext = document.getElementById('metis-board-bylaws-search-next');
    const bylawsHistory = document.getElementById('metis-board-bylaws-history');
    const bylawsPdfBrowserModal = document.getElementById('metis-board-bylaws-pdf-browser-modal');
    const bylawsPdfOptions = document.getElementById('metis-board-bylaws-pdf-options');
    const dashboardActionRowsContainer = document.getElementById('metis-board-dashboard-action-rows');
    const detailActionRowsContainer = document.getElementById('metis-board-action-rows');
    const decisionRowsContainer = document.getElementById('metis-board-decision-rows');
    const searchInput = document.getElementById('metis-board-search');
    const sortButtons = Array.from(document.querySelectorAll('.metis-board-table .metis-sortable'));
    const pageLabel = document.getElementById('metis-board-page');
    const prevBtn = document.getElementById('metis-board-prev');
    const nextBtn = document.getElementById('metis-board-next');

    let currentPage = 1;
    const perPage = 20;
    let sortKey = 'date';
    let sortDir = 'desc';
    let bylawsReaderOriginalHtml = bylawsReader ? bylawsReader.innerHTML : '';
    let bylawsSearchMarks = [];
    let bylawsActiveSearchIndex = -1;

    function norm(v) { return String(v || '').toLowerCase().trim(); }

    function allRows() {
        if (!rowsContainer) return [];
        return Array.from(rowsContainer.querySelectorAll('.metis-board-row'));
    }

    function parseId(value) {
        const parsed = parseInt(String(value || '0'), 10);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    const canManageAnyBoard = canManageBoard || String(detailWrap?.dataset.canManage || '0') === '1';
    const currentPersonId = parseId(boardWrap?.dataset.currentPersonId || detailWrap?.dataset.currentPersonId || 0);

    function formatDateTimeLabel(value) {
        const raw = String(value || '').trim();
        if (raw === '') return '—';
        if (window.Metis && Metis.time && typeof Metis.time.format === 'function') {
            return Metis.time.format(raw, { empty: raw }) || raw;
        }
        const parsed = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return raw;
        return parsed.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function formatDateLabel(value) {
        const raw = String(value || '').trim();
        if (raw === '') return '—';
        if (window.Metis && Metis.time && typeof Metis.time.formatDate === 'function') {
            return Metis.time.formatDate(raw, { empty: raw }) || raw;
        }
        const parsed = new Date(raw.length > 10 ? raw.replace(' ', 'T') : (raw + 'T00:00:00'));
        if (Number.isNaN(parsed.getTime())) return raw;
        return parsed.toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }

    function excerptText(value, maxWords) {
        const limit = parseInt(String(maxWords || '22'), 10) || 22;
        const text = String(value || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        if (!text) return '';
        const words = text.split(' ');
        if (words.length <= limit) return text;
        return words.slice(0, limit).join(' ') + '...';
    }

    function removePlaceholderRows(container, placeholderText) {
        if (!container) return;
        const needle = norm(placeholderText);
        Array.from(container.querySelectorAll('.metis-premium-row')).forEach(function (row) {
            const text = norm(row.textContent || '');
            if (text === needle || text.indexOf(needle) !== -1) {
                row.remove();
            }
        });
    }

    function currentMeetingJson(row) {
        if (!row) return null;
        try {
            return JSON.parse(String(row.dataset.meetingJson || '{}'));
        } catch (err) {
            return null;
        }
    }

    function upsertMeetingRow(payload, options) {
        if (!rowsContainer || !payload || typeof payload !== 'object') return;
        const opts = options && typeof options === 'object' ? options : {};
        const id = parseId(payload.id);
        if (id < 1) return;

        removePlaceholderRows(rowsContainer, 'No meetings yet.');

        let row = rowsContainer.querySelector('.metis-board-row[data-id="' + id + '"]');
        if (!row) {
            row = document.createElement('tr');
            row.className = 'metis-premium-row metis-board-row';
            rowsContainer.appendChild(row);
        }

        const data = Object.assign({}, payload, {
            id: id,
            committee_id: parseId(payload.committee_id),
            meeting_code: String(payload.meeting_code || ''),
            title: String(payload.title || ''),
            committee_name: String(payload.committee_name || ''),
            meeting_type: String(payload.meeting_type || 'board'),
            location: String(payload.location || ''),
            status: String(payload.status || 'draft'),
            open_actions: parseId(payload.open_actions),
            meeting_url: String(payload.meeting_url || '')
        });
        const sortTs = Date.parse(String(data.meeting_date || '').replace(' ', 'T'));

        row.classList.toggle('metis-board-row-draft', data.status === 'draft');
        row.dataset.id = String(id);
        row.dataset.search = norm([
            data.meeting_code,
            data.title,
            data.committee_name,
            data.meeting_type,
            data.location,
            data.status
        ].join(' '));
        row.dataset.titleSort = norm(data.title);
        row.dataset.committeeSort = norm(data.committee_name);
        row.dataset.dateSort = String(Number.isNaN(sortTs) ? 0 : Math.floor(sortTs / 1000));
        row.dataset.href = data.meeting_url;
        row.dataset.meetingJson = JSON.stringify(data);
        row.innerHTML = ''
            + '<td class="metis-premium-cell"><div><strong>' + escHtml(data.title) + '</strong></div><div class="metis-muted">' + escHtml(data.meeting_code) + '</div></td>'
            + '<td class="metis-premium-cell">' + escHtml(data.committee_name || '—') + '</td>'
            + '<td class="metis-premium-cell">' + escHtml(formatDateTimeLabel(data.meeting_date)) + '</td>'
            + '<td class="metis-premium-cell">' + escHtml(data.meeting_type) + '</td>'
            + '<td class="metis-premium-cell"><span class="metis-chip">' + escHtml(data.status) + '</span></td>'
            + '<td class="metis-premium-cell">' + escHtml(String(data.open_actions)) + '</td>'
            + (canManageBoard ? '<td class="metis-premium-cell metis-board-actions-cell"><button type="button" class="metis-btn-xs metis-board-edit-meeting">Edit</button></td>' : '');

        if (!opts.skipFilter) {
            applyFilters();
        }
    }

    function meetingRowForCommittee(committeeId) {
        return allRows().filter(function (row) {
            const data = currentMeetingJson(row);
            return parseId(data && data.committee_id) === committeeId;
        });
    }

    function syncMeetingCommitteeOption(payload) {
        const select = document.getElementById('metis-board-meeting-committee');
        if (!select || !payload || typeof payload !== 'object') return;
        const committeeId = String(parseId(payload.id));
        if (committeeId === '0') return;
        let option = Array.from(select.options).find(function (opt) {
            return String(opt.value || '') === committeeId;
        });
        if (!option) {
            option = document.createElement('option');
            option.value = committeeId;
            select.appendChild(option);
        }
        option.textContent = String(payload.name || 'Committee');
        const boardWide = select.querySelector('option[value=""]');
        const options = Array.from(select.querySelectorAll('option')).filter(function (opt) {
            return String(opt.value || '') !== '';
        }).sort(function (a, b) {
            return String(a.textContent || '').localeCompare(String(b.textContent || ''));
        });
        select.innerHTML = '';
        if (boardWide) select.appendChild(boardWide);
        options.forEach(function (opt) { select.appendChild(opt); });
    }

    function committeeRowId(row) {
        if (!row) return 0;
        const dataId = parseId(row.dataset.committeeId);
        if (dataId > 0) return dataId;
        try {
            const data = JSON.parse(String(row.dataset.committeeJson || '{}'));
            return parseId(data && data.id);
        } catch (err) {
            return 0;
        }
    }

    function upsertCommitteeRow(payload) {
        if (!committeeRowsContainer || !payload || typeof payload !== 'object') return;
        const id = parseId(payload.id);
        if (id < 1) return;

        removePlaceholderRows(committeeRowsContainer, 'No committees yet.');

        let row = Array.from(committeeRowsContainer.querySelectorAll('.metis-premium-row')).find(function (candidate) {
            return committeeRowId(candidate) === id;
        });
        if (!row) {
            row = document.createElement('tr');
            row.className = 'metis-premium-row';
            committeeRowsContainer.appendChild(row);
        }

        const data = Object.assign({}, payload, {
            id: id,
            committee_code: String(payload.committee_code || ''),
            name: String(payload.name || ''),
            chair_name: String(payload.chair_name || ''),
            newsletter_list_id: parseId(payload.newsletter_list_id),
            newsletter_list_name: String(payload.newsletter_list_name || ''),
            meeting_count: parseId(payload.meeting_count)
        });
        row.dataset.committeeId = String(id);
        row.dataset.committeeJson = JSON.stringify(data);
        row.innerHTML = ''
            + '<td class="metis-premium-cell"><strong>' + escHtml(data.name) + '</strong><div class="metis-muted">' + escHtml(data.committee_code) + '</div></td>'
            + '<td class="metis-premium-cell">' + escHtml(data.chair_name || '—') + '</td>'
            + '<td class="metis-premium-cell">' + escHtml(String(data.meeting_count)) + '</td>'
            + (canManageBoard ? '<td class="metis-premium-cell"><button type="button" class="metis-btn-xs metis-board-edit-committee">Edit</button></td>' : '');

        Array.from(committeeRowsContainer.querySelectorAll('.metis-premium-row')).sort(function (a, b) {
            const aData = norm((function () {
                try { return JSON.parse(String(a.dataset.committeeJson || '{}')).name || ''; } catch (err) { return ''; }
            })());
            const bData = norm((function () {
                try { return JSON.parse(String(b.dataset.committeeJson || '{}')).name || ''; } catch (err) { return ''; }
            })());
            return aData.localeCompare(bData);
        }).forEach(function (sortedRow) {
            committeeRowsContainer.appendChild(sortedRow);
        });
    }

    function refreshMeetingRowsForCommittee(payload) {
        const committeeId = parseId(payload && payload.id);
        if (committeeId < 1) return;
        meetingRowForCommittee(committeeId).forEach(function (row) {
            const data = currentMeetingJson(row);
            if (!data) return;
            upsertMeetingRow(Object.assign({}, data, { committee_name: String(payload.name || '') }), { skipFilter: true });
        });
        if (rowsContainer) applyFilters();
    }

    function canResolveActionItem(payload) {
        if (!payload || typeof payload !== 'object') return false;
        if (String(payload.status || 'open') === 'done') return false;
        if (canManageAnyBoard) return true;
        const ownerPersonId = parseId(payload.owner_person_id);
        return currentPersonId > 0 && ownerPersonId > 0 && ownerPersonId === currentPersonId;
    }

    function actionResolveControlHtml(payload) {
        if (!canResolveActionItem(payload)) {
            return '<span class="metis-muted">—</span>';
        }
        return '<button type="button" class="metis-btn-xs metis-btn-ghost metis-board-resolve-action" data-action-id="' + escHtml(String(parseId(payload.id))) + '">Resolve</button>';
    }

    function ensureActionPlaceholderRow(container, text) {
        if (!container) return;
        removePlaceholderRows(container, text);
        const hasActionRows = Array.from(container.querySelectorAll('.metis-premium-row')).some(function (row) {
            return parseId(row.dataset.actionId) > 0;
        });
        if (hasActionRows) return;
        const row = document.createElement('tr');
        row.className = 'metis-premium-row';
        row.innerHTML = '<td class="metis-premium-cell metis-muted" colspan="5">' + escHtml(String(text || 'No action items.')) + '</td>';
        container.appendChild(row);
    }

    function upsertMeetingActionRow(payload) {
        if (!detailActionRowsContainer || !payload || typeof payload !== 'object') return;
        const id = parseId(payload.id);
        if (id < 1) return;

        removePlaceholderRows(detailActionRowsContainer, 'No action items for this meeting.');

        let row = detailActionRowsContainer.querySelector('.metis-premium-row[data-action-id="' + id + '"]');
        if (!row) {
            row = document.createElement('tr');
            row.className = 'metis-premium-row';
        }

        row.dataset.actionId = String(id);
        row.dataset.ownerPersonId = String(parseId(payload.owner_person_id));
        row.innerHTML = ''
            + '<td class="metis-premium-cell"><strong>' + escHtml(String(payload.title || '')) + '</strong></td>'
            + '<td class="metis-premium-cell">' + escHtml(String(payload.owner_name || 'Unassigned')) + '</td>'
            + '<td class="metis-premium-cell">' + escHtml(formatDateLabel(payload.due_date)) + '</td>'
            + '<td class="metis-premium-cell"><span class="metis-chip">' + escHtml(String(payload.status || 'open')) + '</span></td>'
            + '<td class="metis-premium-cell metis-board-actions-cell">' + actionResolveControlHtml(payload) + '</td>';

        if (!row.parentNode) {
            if (String(payload.status || 'open') === 'done') {
                detailActionRowsContainer.appendChild(row);
            } else {
                detailActionRowsContainer.insertBefore(row, detailActionRowsContainer.firstChild);
            }
        } else if (String(payload.status || 'open') === 'done') {
            detailActionRowsContainer.appendChild(row);
        } else if (detailActionRowsContainer.firstChild !== row) {
            detailActionRowsContainer.insertBefore(row, detailActionRowsContainer.firstChild);
        }
    }

    function upsertAnnouncementRow(payload) {
        if (!announcementRowsContainer || !payload || typeof payload !== 'object') return;
        const id = parseId(payload.id);
        if (id < 1) return;

        removePlaceholderRows(announcementRowsContainer, 'No announcements yet.');

        let row = announcementRowsContainer.querySelector('.metis-premium-row[data-announcement-id="' + id + '"]');
        if (!row) {
            row = document.createElement('tr');
            row.className = 'metis-premium-row';
        }

        row.dataset.announcementId = String(id);
        row.innerHTML = ''
            + '<td class="metis-premium-cell"><strong>' + escHtml(String(payload.title || '')) + '</strong><div class="metis-muted">' + escHtml(String(payload.announcement_code || '')) + '</div></td>'
            + '<td class="metis-premium-cell"><span class="metis-chip">' + escHtml(String(payload.status || 'draft')) + '</span></td>'
            + '<td class="metis-premium-cell">' + escHtml(formatDateTimeLabel(payload.publish_at)) + '</td>'
            + '<td class="metis-premium-cell">' + escHtml(formatDateTimeLabel(payload.updated_at)) + '</td>';

        if (!row.parentNode) {
            announcementRowsContainer.insertBefore(row, announcementRowsContainer.firstChild);
        } else if (announcementRowsContainer.firstChild !== row) {
            announcementRowsContainer.insertBefore(row, announcementRowsContainer.firstChild);
        }
    }

    function bylawsSummaryHtml(payload) {
        const title = String(payload && payload.title ? payload.title : 'Bylaws');
        const hasHtml = String(payload && payload.formatted_html ? payload.formatted_html : '').trim() !== '';
        if (!hasHtml) {
            return '<div class="metis-empty-state">No bylaws have been saved yet.</div>';
        }
        return ''
            + '<div class="metis-board-bylaws-summary">'
            + '<strong>' + escHtml(title) + '</strong>'
            + '<span class="metis-muted">' + escHtml(String(payload.status || '') === 'active' ? 'Current approved bylaws version ' : 'Bylaws workflow version ') + escHtml(payload.version_number || '1') + '.</span>'
            + '<button id="metis-board-view-bylaws" class="metis-btn metis-btn-ghost metis-btn-xs" type="button">View Bylaws</button>'
            + '</div>';
    }

    function rebuildBylawsToc() {
        if (!bylawsReader || !bylawsToc) return;
        const headings = Array.from(bylawsReader.querySelectorAll('.metis-board-bylaws-document h3, .metis-board-bylaws-document h4'));
        if (!headings.length) {
            bylawsToc.innerHTML = '<div class="metis-muted">No sections found.</div>';
            return;
        }
        bylawsToc.innerHTML = headings.map(function (heading, index) {
            if (!heading.id) heading.id = 'metis-bylaws-heading-' + String(index + 1);
            return '<a href="#' + escHtml(heading.id) + '" data-level="' + escHtml(heading.tagName.toLowerCase()) + '">' + escHtml(heading.textContent || '') + '</a>';
        }).join('');
    }

    function setBylawsSearchCount() {
        if (!bylawsSearchCount) return;
        if (!bylawsSearchMarks.length) {
            bylawsSearchCount.textContent = '0 matches';
            return;
        }
        bylawsSearchCount.textContent = String(bylawsActiveSearchIndex + 1) + ' of ' + String(bylawsSearchMarks.length);
    }

    function setActiveBylawsSearchMatch(index) {
        if (!bylawsSearchMarks.length) {
            bylawsActiveSearchIndex = -1;
            setBylawsSearchCount();
            return;
        }
        bylawsSearchMarks.forEach(function (mark) {
            mark.classList.remove('is-active');
        });
        bylawsActiveSearchIndex = (index + bylawsSearchMarks.length) % bylawsSearchMarks.length;
        const active = bylawsSearchMarks[bylawsActiveSearchIndex];
        active.classList.add('is-active');
        active.scrollIntoView({ block: 'center', inline: 'nearest' });
        setBylawsSearchCount();
    }

    function escapeRegExp(value) {
        return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function applyBylawsSearch() {
        if (!bylawsReader) return;
        const query = String(bylawsSearch?.value || '').trim();
        bylawsReader.innerHTML = bylawsReaderOriginalHtml || '';
        rebuildBylawsToc();
        bylawsSearchMarks = [];
        bylawsActiveSearchIndex = -1;
        if (query === '') {
            setBylawsSearchCount();
            return;
        }

        const pattern = new RegExp(escapeRegExp(query), 'gi');
        const walker = document.createTreeWalker(bylawsReader, NodeFilter.SHOW_TEXT, {
            acceptNode: function (node) {
                const text = node.nodeValue || '';
                if (!text.trim() || !pattern.test(text)) {
                    pattern.lastIndex = 0;
                    return NodeFilter.FILTER_REJECT;
                }
                pattern.lastIndex = 0;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach(function (node) {
            const text = node.nodeValue || '';
            const fragment = document.createDocumentFragment();
            let last = 0;
            text.replace(pattern, function (match, offset) {
                if (offset > last) fragment.appendChild(document.createTextNode(text.slice(last, offset)));
                const mark = document.createElement('mark');
                mark.textContent = match;
                fragment.appendChild(mark);
                bylawsSearchMarks.push(mark);
                last = offset + match.length;
                return match;
            });
            if (last < text.length) fragment.appendChild(document.createTextNode(text.slice(last)));
            node.parentNode.replaceChild(fragment, node);
        });
        setActiveBylawsSearchMatch(0);
    }

    function refreshBylawsReader(html) {
        if (!bylawsReader) return;
        bylawsReader.innerHTML = String(html || '').trim() !== ''
            ? String(html || '')
            : '<div class="metis-empty-state">No bylaws have been saved yet.</div>';
        bylawsReaderOriginalHtml = bylawsReader.innerHTML;
        if (bylawsSearch) bylawsSearch.value = '';
        rebuildBylawsToc();
        applyBylawsSearch();
    }

    function bylawsHistoryHtml(rows) {
        const list = Array.isArray(rows) ? rows : [];
        if (!list.length) return '';
        return ''
            + '<h3>Approved Bylaws History</h3>'
            + '<div class="metis-board-bylaws-history-list">'
            + list.map(function (row) {
                const url = String(row.signed_pdf_url || '').trim();
                return '<div class="metis-board-bylaws-history-row">'
                    + '<div><strong>' + escHtml(row.title || 'Bylaws') + '</strong>'
                    + '<span class="metis-muted">Version ' + escHtml(row.version_number || '1') + ' · ' + escHtml(String(row.status || '') === 'active' ? 'Current' : 'Superseded') + '</span>'
                    + (String(row.change_summary || '').trim() ? '<div class="metis-muted">' + escHtml(row.change_summary) + '</div>' : '')
                    + '</div>'
                    + '<div class="metis-board-bylaws-history-meta"><span>Effective ' + escHtml(row.effective_date_label || '—') + '</span><span>Approved ' + escHtml(row.approved_at_label || '—') + '</span><span>Updated ' + escHtml(row.updated_at_label || '—') + '</span></div>'
                    + (url ? '<a class="metis-btn-xs metis-btn-ghost" href="' + escHtml(url) + '" target="_blank" rel="noopener">PDF</a>' : '')
                    + '</div>';
            }).join('')
            + '</div>';
    }

    function updateBylawsPanel(payload) {
        if (!payload || typeof payload !== 'object') return;

        const formatted = String(payload.formatted_html || '').trim();
        if (bylawsDisplay) {
            bylawsDisplay.innerHTML = bylawsSummaryHtml(payload);
        }
        if (bylawsPreview) {
            bylawsPreview.innerHTML = formatted !== ''
                ? formatted
                : '<div class="metis-muted">Formatted preview will appear here.</div>';
        }
        refreshBylawsReader(formatted);
        if (bylawsMeta) {
            bylawsMeta.innerHTML = ''
                + '<span>Version: <strong>' + escHtml(payload.version_number || '1') + '</strong></span>'
                + '<span>Workflow: <strong>' + escHtml(String(payload.approval_stage || payload.status || 'draft').replace(/_/g, ' ')) + '</strong></span>'
                + '<span>Effective: <strong>' + escHtml(payload.effective_date_label || '—') + '</strong></span>'
                + '<span>Approved: <strong>' + escHtml(payload.approved_at_label || '—') + '</strong></span>'
                + '<span>Updated: <strong>' + escHtml(payload.updated_at_label || '—') + '</strong></span>';
        }
        if (bylawsViewMeta) {
            bylawsViewMeta.innerHTML = ''
                + '<span>Effective: <strong>' + escHtml(payload.effective_date_label || '—') + '</strong></span>'
                + '<span>Approved: <strong>' + escHtml(payload.approved_at_label || '—') + '</strong></span>'
                + '<span>Updated: <strong>' + escHtml(payload.updated_at_label || '—') + '</strong></span>';
        }
        const viewTitle = document.getElementById('metis-board-bylaws-view-title');
        if (viewTitle) viewTitle.textContent = String(payload.title || 'Bylaws');
        if (bylawsSignedLink) {
            const url = String(payload.signed_pdf_url || '').trim();
            bylawsSignedLink.href = url || '#';
            bylawsSignedLink.hidden = url === '';
        }

        const set = function (id, value) {
            const el = document.getElementById(id);
            if (el) el.value = String(value || '');
        };
        set('metis-board-bylaws-id', payload.id || 0);
        set('metis-board-bylaws-title', payload.title || 'Bylaws');
        set('metis-board-bylaws-effective', payload.effective_date || '');
        set('metis-board-bylaws-stage', String(payload.approval_stage || payload.status || 'draft').replace(/_/g, ' '));
        set('metis-board-bylaws-meeting', payload.meeting_id || '');
        set('metis-board-bylaws-decision', payload.decision_id || '');
        set('metis-board-bylaws-action', payload.action_item_id || '');
        set('metis-board-bylaws-pdf-url', payload.signed_pdf_url || '');
        set('metis-board-bylaws-pdf-id', payload.signed_pdf_file_id || '');
        set('metis-board-bylaws-pdf-title', payload.signed_pdf_title || 'Signed bylaws PDF');
        set('metis-board-bylaws-change-summary', payload.change_summary || '');
        set('metis-board-bylaws-source', payload.source_text || '');
        const secretaryStatus = document.getElementById('metis-board-bylaws-secretary-status');
        if (secretaryStatus) secretaryStatus.textContent = payload.secretary_signature_name || 'Not certified';
        const presidentStatus = document.getElementById('metis-board-bylaws-president-status');
        if (presidentStatus) presidentStatus.textContent = payload.president_signature_name || 'Not approved';

        const editBtn = document.getElementById('metis-board-edit-bylaws');
        if (editBtn) editBtn.textContent = parseId(payload.id) > 0 ? 'Edit Bylaws' : 'Add Bylaws';
    }

    function upsertDashboardActionRow(payload) {
        if (!dashboardActionRowsContainer || !payload || typeof payload !== 'object') return;
        const id = parseId(payload.id);
        if (id < 1) return;

        let row = dashboardActionRowsContainer.querySelector('.metis-premium-row[data-action-id="' + id + '"]');
        if (String(payload.status || 'open') === 'done') {
            if (row) row.remove();
            ensureActionPlaceholderRow(dashboardActionRowsContainer, 'No open action items.');
            return;
        }

        removePlaceholderRows(dashboardActionRowsContainer, 'No open action items.');

        if (!row) {
            row = document.createElement('tr');
            row.className = 'metis-premium-row';
        }
        row.dataset.actionId = String(id);
        row.dataset.ownerPersonId = String(parseId(payload.owner_person_id));
        row.innerHTML = ''
            + '<td class="metis-premium-cell"><strong>' + escHtml(String(payload.title || '')) + '</strong><div class="metis-muted">' + escHtml(String(payload.meeting_code || '')) + '</div></td>'
            + '<td class="metis-premium-cell">' + escHtml(String(payload.owner_name || 'Unassigned')) + '</td>'
            + '<td class="metis-premium-cell">' + escHtml(formatDateLabel(payload.due_date)) + '</td>'
            + '<td class="metis-premium-cell"><span class="metis-chip">' + escHtml(String(payload.status || 'open')) + '</span></td>'
            + '<td class="metis-premium-cell metis-board-actions-cell">' + actionResolveControlHtml(payload) + '</td>';

        if (!row.parentNode) {
            dashboardActionRowsContainer.insertBefore(row, dashboardActionRowsContainer.firstChild);
        } else if (dashboardActionRowsContainer.firstChild !== row) {
            dashboardActionRowsContainer.insertBefore(row, dashboardActionRowsContainer.firstChild);
        }
    }

    function bumpMeetingOpenActionCount(meetingId, delta) {
        const id = parseId(meetingId);
        if (id < 1 || !rowsContainer || !delta) return;
        const row = rowsContainer.querySelector('.metis-board-row[data-id="' + id + '"]');
        const data = currentMeetingJson(row);
        if (!row || !data) return;
        const nextCount = Math.max(0, parseId(data.open_actions) + delta);
        upsertMeetingRow(Object.assign({}, data, { open_actions: nextCount }), { skipFilter: true });
        applyFilters();
    }

    function applySortUi() {
        const labelMap = { title: 'Meeting', committee: 'Committee', date: 'Date' };
        sortButtons.forEach(function (btn) {
            const key = String(btn.dataset.sortKey || '');
            const label = labelMap[key] || key;
            btn.textContent = label;
            btn.classList.remove('metis-sort-active', 'metis-sort-asc', 'metis-sort-desc');
            if (key === sortKey) {
                btn.classList.add('metis-sort-active');
                btn.classList.add(sortDir === 'asc' ? 'metis-sort-asc' : 'metis-sort-desc');
            }
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

    function resolveActionItem(actionId, trigger) {
        const id = parseId(actionId);
        if (id < 1) return Promise.resolve();
        if (trigger) trigger.disabled = true;
        return post('metis_board_resolve_action_item', {
            action_item_id: id
        }).then(function (data) {
            const saved = data && data.action_item && typeof data.action_item === 'object' ? data.action_item : null;
            const delta = parseId(data && data.open_count_delta);
            if (saved) {
                upsertDashboardActionRow(saved);
                upsertMeetingActionRow(saved);
                if (delta !== 0) {
                    bumpMeetingOpenActionCount(saved.meeting_id, delta);
                }
            }
            showAlert('Action item resolved.', 'success');
        }).catch(function (err) {
            showAlert(err.message || 'Failed to resolve action item.', 'error');
        }).finally(function () {
            if (trigger) trigger.disabled = false;
        });
    }

    function bindActionResolveButtons(container) {
        container?.addEventListener('click', function (event) {
            const btn = event.target.closest('.metis-board-resolve-action');
            if (!btn) return;
            event.preventDefault();
            event.stopPropagation();
            resolveActionItem(btn.dataset.actionId, btn);
        });
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
            if (href !== '') navigate(href);
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
    bindActionResolveButtons(dashboardActionRowsContainer);
    bindActionResolveButtons(detailActionRowsContainer);

    let initDecisionVoteDnD = function () {};

    function currentVoteMembersJson() {
        const explicit = String(detailWrap?.dataset.voteMembers || '').trim();
        if (explicit !== '') return explicit;
        const existing = document.querySelector('.metis-board-decision-row[data-vote-members]');
        return String(existing?.dataset.voteMembers || '[]');
    }

    function upsertDecisionRow(payload) {
        if (!decisionRowsContainer || !detailWrap || !payload || typeof payload !== 'object') return;
        const id = parseId(payload.id);
        if (id < 1) return;

        removePlaceholderRows(decisionRowsContainer, 'No decisions recorded.');

        let row = decisionRowsContainer.querySelector('.metis-board-decision-row[data-decision-id="' + id + '"]');
        if (!row) {
            row = document.createElement('tr');
            row.className = 'metis-premium-row metis-board-decision-row';
            decisionRowsContainer.appendChild(row);
        }

        const voteMembersJson = currentVoteMembersJson();
        let votingMembers = [];
        try {
            votingMembers = JSON.parse(voteMembersJson);
        } catch (err) {
            votingMembers = [];
        }
        const canManageDetail = String(detailWrap.dataset.canManage || '0') === '1';
        const showVotePanel = canManageDetail && Array.isArray(votingMembers) && votingMembers.length > 0;
        const decisionText = excerptText(payload.decision_text, 22);
        const sectionTitle = String(payload.agenda_section_title || '').trim();
        const itemTitle = String(payload.agenda_item_title || '').trim();
        const outcome = String(payload.outcome || 'pending');
        const outcomeOptions = ['pending', 'approved', 'rejected'].map(function (value) {
            const label = value.charAt(0).toUpperCase() + value.slice(1);
            return '<option value="' + escHtml(value) + '"' + (value === outcome ? ' selected' : '') + '>' + escHtml(label) + '</option>';
        }).join('');

        row.dataset.decisionId = String(id);
        row.dataset.voteMembers = voteMembersJson;
        row.dataset.voteAssignments = String(payload.decision_votes_json || '{"for":[],"against":[],"abstain":[]}');
        row.innerHTML = ''
            + '<td class="metis-premium-cell"><strong>' + escHtml(String(payload.title || '')) + '</strong>'
            + (sectionTitle || itemTitle ? '<div class="metis-muted">Section: ' + escHtml(sectionTitle) + (itemTitle ? ' · Item: ' + escHtml(itemTitle) : '') + '</div>' : '')
            + (decisionText ? '<div class="metis-muted">' + escHtml(decisionText) + '</div>' : '')
            + '</td>'
            + '<td class="metis-premium-cell">'
            + (canManageDetail
                ? '<select class="metis-select metis-board-decision-outcome" style="max-width:160px;" disabled>' + outcomeOptions + '</select>'
                : '<span class="metis-chip">' + escHtml(outcome) + '</span>')
            + '</td>'
            + '<td class="metis-premium-cell"><span class="metis-board-decision-count metis-board-decision-for" data-count="' + escHtml(String(parseId(payload.votes_for))) + '">' + escHtml(String(parseId(payload.votes_for))) + '</span></td>'
            + '<td class="metis-premium-cell"><span class="metis-board-decision-count metis-board-decision-against" data-count="' + escHtml(String(parseId(payload.votes_against))) + '">' + escHtml(String(parseId(payload.votes_against))) + '</span></td>'
            + '<td class="metis-premium-cell"><span class="metis-board-decision-count metis-board-decision-abstain" data-count="' + escHtml(String(parseId(payload.votes_abstain))) + '">' + escHtml(String(parseId(payload.votes_abstain))) + '</span></td>'
            + (showVotePanel
                ? '<td class="metis-premium-cell metis-board-decision-vote-panel"><details class="metis-board-vote-collapse"><summary>Member Voting</summary><div class="metis-board-vote-dnd" data-vote-dnd><section class="metis-board-vote-col"><h5>Board Members</h5><div class="metis-board-vote-dropzone" data-vote-bucket="available"></div></section><section class="metis-board-vote-col"><h5>For</h5><div class="metis-board-vote-dropzone" data-vote-bucket="for"></div></section><section class="metis-board-vote-col"><h5>Against</h5><div class="metis-board-vote-dropzone" data-vote-bucket="against"></div></section><section class="metis-board-vote-col"><h5>Abstain</h5><div class="metis-board-vote-dropzone" data-vote-bucket="abstain"></div></section></div><div class="metis-help">Drag board members into vote columns. Votes save automatically.</div></details></td>'
                : '');

        initDecisionVoteDnD(row);
    }

    if (detailWrap) {
        const tabButtons = Array.from(detailWrap.querySelectorAll('.metis-board-tab'));
        const quickNavButtons = Array.from(detailWrap.querySelectorAll('[data-open-board-tab]'));
        const panels = Array.from(detailWrap.querySelectorAll('.metis-board-tab-panel'));
        const canManageDetail = String(detailWrap.dataset.canManage || '0') === '1';
        const meetingIdDetail = String(detailWrap.dataset.meetingId || '0');
        const hasAgendaDetail = String(detailWrap.dataset.hasAgenda || '0') === '1';
        const hasMinutesDetail = String(detailWrap.dataset.hasMinutes || '0') === '1';
        const hasPacketDetail = String(detailWrap.dataset.hasPacket || '0') === '1';
        const stepSaveState = detailWrap.querySelector('#metis-board-step-save-state');
        const stepSaveText = stepSaveState ? stepSaveState.querySelector('.metis-board-step-save-text') : null;
        const workflowModal = document.getElementById('metis-board-workflow-modal');
        const workflowManageBtn = detailWrap.querySelector('#metis-board-manage-workflow');
        const agendaBuilder = detailWrap.querySelector('#metis-board-agenda-builder');
        const agendaHidden = detailWrap.querySelector('#metis-board-agenda-json');
        const agendaTemplateNode = detailWrap.querySelector('#metis-board-agenda-section-template');
        const setupForm = detailWrap.querySelector('#metis-board-setup-form');
        const setupCalendarName = detailWrap.querySelector('#metis-board-setup-calendar-name');
        const setupCalendarLink = detailWrap.querySelector('#metis-board-setup-calendar-link');
        const setupCalendarSelect = detailWrap.querySelector('#metis-board-setup-calendar-select');
        const setupFolderName = detailWrap.querySelector('#metis-board-setup-folder-name');
        const setupFolderLink = detailWrap.querySelector('#metis-board-setup-folder-link');
        const setupFolderSelect = detailWrap.querySelector('#metis-board-setup-folder-select');
        const setupTitleInput = detailWrap.querySelector('#metis-board-setup-title');
        const setupDateInput = detailWrap.querySelector('#metis-board-setup-date');
        const setupTypeInput = detailWrap.querySelector('#metis-board-setup-type');
        const setupLocationInput = detailWrap.querySelector('#metis-board-setup-location');
        const setupStatusInput = detailWrap.querySelector('#metis-board-setup-status');

        let agendaTemplateCache = [];
        let decisionTemplateCache = [];
        let agendaSections = [];
        let stepNavigationInFlight = false;
        let stepNavigationSaveLock = false;
        let saveStateTimer = 0;

        function setStepSaveState(text, kind) {
            if (!stepSaveState) return;
            if (stepNavigationSaveLock && kind !== 'saving' && kind !== 'error') {
                return;
            }
            if (saveStateTimer) {
                window.clearTimeout(saveStateTimer);
                saveStateTimer = 0;
            }
            stepSaveState.classList.remove('is-saving', 'is-error');
            detailWrap.classList.remove('metis-board-is-saving');
            if (!stepNavigationSaveLock) {
                detailWrap.classList.remove('metis-board-nav-saving');
            }
            if (kind === 'saving') stepSaveState.classList.add('is-saving');
            if (kind === 'error') stepSaveState.classList.add('is-error');
            if (kind === 'saving') detailWrap.classList.add('metis-board-is-saving');
            if (stepSaveText) {
                stepSaveText.textContent = String(text || 'Saved');
            } else {
                stepSaveState.textContent = String(text || 'Saved');
            }
            if (kind === 'saved') {
                saveStateTimer = window.setTimeout(function () {
                    if (stepSaveText) {
                        stepSaveText.textContent = 'Saved';
                    } else {
                        stepSaveState.textContent = 'Saved';
                    }
                }, 1400);
            }
        }

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
            detailWrap.dispatchEvent(new CustomEvent('metis:board-tab-changed', { detail: { tab: tabKey } }));
        }

        function activeTabKey() {
            const activeBtn = tabButtons.find(function (btn) {
                return btn.classList.contains('metis-board-tab-active');
            });
            return String((activeBtn && activeBtn.dataset && activeBtn.dataset.tab) || 'overview');
        }

        function applyWorkspaceSummary(summary) {
            const data = summary && typeof summary === 'object' ? summary : {};
            const calendar = data.calendar && typeof data.calendar === 'object' ? data.calendar : {};
            const folder = data.folder && typeof data.folder === 'object' ? data.folder : {};
            if (setupCalendarName) {
                setupCalendarName.textContent = String(calendar.name || (calendar.id ? 'Linked calendar event' : 'Not linked'));
            }
            if (setupCalendarLink) {
                const url = String(calendar.url || '');
                if (url) {
                    setupCalendarLink.href = url;
                    setupCalendarLink.hidden = false;
                } else {
                    setupCalendarLink.href = '#';
                    setupCalendarLink.hidden = true;
                }
            }
            if (setupCalendarSelect && calendar.id) {
                const id = String(calendar.id);
                const exists = Array.from(setupCalendarSelect.options).some(function (opt) { return String(opt.value) === id; });
                if (!exists) {
                    const opt = document.createElement('option');
                    opt.value = id;
                    opt.textContent = String(calendar.name || 'Linked calendar event');
                    setupCalendarSelect.appendChild(opt);
                }
                setupCalendarSelect.value = id;
            }
            if (setupFolderName) {
                setupFolderName.textContent = String(folder.name || (folder.id ? 'Linked Drive folder' : 'Not linked'));
            }
            if (setupFolderLink) {
                const url = String(folder.url || '');
                if (url) {
                    setupFolderLink.href = url;
                    setupFolderLink.hidden = false;
                } else {
                    setupFolderLink.href = '#';
                    setupFolderLink.hidden = true;
                }
            }
            if (setupFolderSelect && folder.id) {
                const id = String(folder.id);
                const exists = Array.from(setupFolderSelect.options).some(function (opt) { return String(opt.value) === id; });
                if (!exists) {
                    const opt = document.createElement('option');
                    opt.value = id;
                    opt.textContent = String(folder.name || 'Linked Drive folder');
                    setupFolderSelect.appendChild(opt);
                }
                setupFolderSelect.value = id;
            }
        }

        function refreshWorkspaceSummary() {
            if (!canManageDetail && !setupCalendarName && !setupFolderName) return Promise.resolve();
            return post('metis_board_get_workspace_links_summary', { meeting_id: meetingIdDetail })
                .then(function (data) {
                    applyWorkspaceSummary(data || {});
                    return data;
                });
        }

        function populateSelect(selectEl, rows, placeholderText) {
            if (!selectEl) return;
            const current = String(selectEl.value || '');
            const list = Array.isArray(rows) ? rows : [];
            selectEl.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = placeholderText;
            selectEl.appendChild(placeholder);
            list.forEach(function (row) {
                const id = String((row && row.id) || '');
                if (!id) return;
                const option = document.createElement('option');
                option.value = id;
                option.textContent = String((row && row.name) || id);
                selectEl.appendChild(option);
            });
            if (current && Array.from(selectEl.options).some(function (opt) { return String(opt.value) === current; })) {
                selectEl.value = current;
            }
        }

        function loadAssignableWorkspaceTargets() {
            const tasks = [];
            if (setupCalendarSelect) {
                tasks.push(
                    post('metis_board_list_calendar_events', { meeting_id: meetingIdDetail })
                        .then(function (data) {
                            populateSelect(setupCalendarSelect, (data && data.events) || [], 'Select calendar event');
                        })
                        .catch(function () {
                            populateSelect(setupCalendarSelect, [], 'No events available');
                        })
                );
            }
            if (setupFolderSelect) {
                tasks.push(
                    post('metis_board_list_drive_folders', { meeting_id: meetingIdDetail })
                        .then(function (data) {
                            populateSelect(setupFolderSelect, (data && data.folders) || [], 'Select drive folder');
                        })
                        .catch(function () {
                            populateSelect(setupFolderSelect, [], 'No folders available');
                        })
                );
            }
            return Promise.all(tasks);
        }

        function saveSetupProgress(notify, syncCalendar) {
            if (!setupForm || !canManageDetail) return Promise.resolve();
            const shouldNotify = notify !== false;
            const shouldSyncCalendar = syncCalendar !== false;
            const payload = {
                meeting_id: meetingIdDetail,
                title: setupTitleInput?.value || '',
                meeting_date: setupDateInput?.value || '',
                meeting_type: setupTypeInput?.value || 'board',
                location: setupLocationInput?.value || '',
                status: setupStatusInput?.value || 'draft',
                sync_calendar_event: shouldSyncCalendar ? '1' : '0',
            };
            setStepSaveState('Saving setup…', 'saving');
            return post('metis_board_update_meeting_detail', payload).then(function (data) {
                setStepSaveState('Setup saved', 'saved');
                if (shouldNotify) showAlert('Setup saved.', 'success');
                const sync = data && data.calendar_sync && typeof data.calendar_sync === 'object' ? data.calendar_sync : null;
                if (shouldNotify && sync && sync.ok === false) {
                    showAlert(sync.error || 'Meeting saved, but calendar sync failed.', 'error');
                }
                return refreshWorkspaceSummary().catch(function () { return null; }).then(function () { return data; });
            }).catch(function (err) {
                setStepSaveState('Save failed', 'error');
                if (shouldNotify) showAlert(err.message || 'Failed to save setup.', 'error');
                throw err;
            });
        }

        function saveCurrentStepProgress() {
            if (!canManageDetail) return Promise.resolve();
            const currentTab = activeTabKey();
            if (currentTab === 'overview') {
                return saveSetupProgress(false, false).then(function () { return null; });
            }
            if (currentTab === 'agenda') {
                return saveAgendaProgress().then(function () { return null; });
            }
            if (currentTab === 'minutes') {
                syncMinutesHiddenFromEditor();
                return post('metis_board_update_meeting_detail', {
                    meeting_id: meetingIdDetail,
                    minutes_html: detailWrap.querySelector('#metis-board-detail-minutes-html')?.value || ''
                }).then(function () { return null; });
            }
            if (currentTab === 'packet') {
                syncPacketNotesHiddenFromEditor();
                return post('metis_board_update_meeting_detail', {
                    meeting_id: meetingIdDetail,
                    board_packet_notes: detailWrap.querySelector('#metis-board-packet-notes')?.value || '',
                    packet_source_minutes_meeting_id: detailWrap.querySelector('#metis-board-packet-prev-minutes')?.value || '0',
                    packet_financial_document_id: detailWrap.querySelector('#metis-board-packet-financial-doc')?.value || '0',
                    packet_published: detailWrap.querySelector('#metis-board-packet-publish')?.checked ? '1' : '0'
                }).then(function () { return null; });
            }
            return Promise.resolve();
        }

        function navigateToTab(targetTab, options) {
            const target = String(targetTab || 'overview');
            const opts = options && typeof options === 'object' ? options : {};
            if (stepNavigationInFlight) return;
            if (activeTabKey() === target && !opts.force) return;
            stepNavigationInFlight = true;
            stepNavigationSaveLock = true;
            detailWrap.classList.add('metis-board-nav-saving');
            setStepSaveState('Saving…', 'saving');
            const savePromise = opts.skipSave ? Promise.resolve() : saveCurrentStepProgress();
            savePromise.then(function () {
                if (opts.populateMinutesDraft) {
                    syncAgendaSnapshot();
                    setMinutesEditorHtml(minutesDraftFromAgenda());
                }
                if (target === 'minutes' && canManageDetail && !opts.populateMinutesDraft && minutesIsEffectivelyEmpty()) {
                    syncAgendaSnapshot();
                    setMinutesEditorHtml(minutesDraftFromAgenda());
                }
                setActiveTab(target);
                // Keep the saving fade through the step switch so users can see the transition.
                window.setTimeout(function () {
                    stepNavigationSaveLock = false;
                    setStepSaveState('Saved', 'saved');
                }, 220);
            }).catch(function (err) {
                stepNavigationSaveLock = false;
                setStepSaveState('Save failed', 'error');
                showAlert(err && err.message ? err.message : 'Failed to save current step.', 'error');
            }).finally(function () {
                stepNavigationInFlight = false;
            });
        }

        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                navigateToTab(String(btn.dataset.tab || 'overview'));
            });
        });
        quickNavButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                navigateToTab(String(btn.dataset.openBoardTab || 'overview'));
            });
        });
        setActiveTab('overview');
        setStepSaveState('Saved', 'saved');
        loadAssignableWorkspaceTargets().then(function () {
            return refreshWorkspaceSummary();
        }).catch(function () { return null; });

        setupForm?.addEventListener('submit', function (event) {
            event.preventDefault();
            saveSetupProgress(true, true);
        });

        detailWrap.querySelector('#metis-board-setup-calendar-assign')?.addEventListener('click', function () {
            const value = String(setupCalendarSelect?.value || '').trim();
            if (value === '') {
                showAlert('Select a calendar event first.', 'error');
                return;
            }
            const label = String(setupCalendarSelect?.selectedOptions?.[0]?.textContent || '').trim();
            setStepSaveState('Assigning calendar event…', 'saving');
            post('metis_board_assign_calendar_event', {
                meeting_id: meetingIdDetail,
                event_id: value,
                event_name: label
            }).then(function () {
                setStepSaveState('Calendar event linked', 'saved');
                showAlert('Calendar event linked.', 'success');
                return refreshWorkspaceSummary();
            }).catch(function (err) {
                setStepSaveState('Calendar link failed', 'error');
                showAlert(err.message || 'Failed to assign calendar event.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-setup-calendar-generate')?.addEventListener('click', function () {
            saveSetupProgress(false, false).then(function () {
                setStepSaveState('Generating calendar event…', 'saving');
                return post('metis_board_generate_calendar_event', {
                    meeting_id: meetingIdDetail
                });
            }).then(function () {
                setStepSaveState('Calendar event generated', 'saved');
                showAlert('Calendar event generated.', 'success');
                return loadAssignableWorkspaceTargets().then(function () { return refreshWorkspaceSummary(); });
            }).catch(function (err) {
                setStepSaveState('Calendar generation failed', 'error');
                showAlert(err.message || 'Failed to generate calendar event.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-setup-folder-assign')?.addEventListener('click', function () {
            const value = String(setupFolderSelect?.value || '').trim();
            if (value === '') {
                showAlert('Select a Drive folder first.', 'error');
                return;
            }
            const label = String(setupFolderSelect?.selectedOptions?.[0]?.textContent || '').trim();
            setStepSaveState('Assigning Drive folder…', 'saving');
            post('metis_board_drive_set_meeting_folder', {
                meeting_id: meetingIdDetail,
                folder_id: value,
                folder_name: label
            }).then(function () {
                setStepSaveState('Drive folder linked', 'saved');
                showAlert('Drive folder linked.', 'success');
                if (typeof detailWrap.__metisBoardSetFolder === 'function') {
                    detailWrap.__metisBoardSetFolder(value, true);
                }
                return refreshWorkspaceSummary();
            }).catch(function (err) {
                setStepSaveState('Drive link failed', 'error');
                showAlert(err.message || 'Failed to assign Drive folder.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-setup-folder-generate')?.addEventListener('click', function () {
            setStepSaveState('Generating meeting folder…', 'saving');
            post('metis_board_prepare_meeting_workspace', { meeting_id: meetingIdDetail }).then(function () {
                setStepSaveState('Drive folder generated', 'saved');
                showAlert('Meeting workspace is ready in Drive.', 'success');
                return loadAssignableWorkspaceTargets().then(function () { return refreshWorkspaceSummary(); });
            }).then(function () {
                const currentFolderId = String(setupFolderSelect?.value || '').trim();
                if (currentFolderId !== '' && typeof detailWrap.__metisBoardSetFolder === 'function') {
                    detailWrap.__metisBoardSetFolder(currentFolderId, true);
                }
                if (typeof detailWrap.__metisBoardLoadDrive === 'function') detailWrap.__metisBoardLoadDrive();
            }).catch(function (err) {
                setStepSaveState('Folder generation failed', 'error');
                showAlert(err.message || 'Failed to prepare meeting workspace.', 'error');
            });
        });

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

        const minutesEditor = detailWrap.querySelector('#metis-board-detail-minutes-editor');
        const minutesHidden = detailWrap.querySelector('#metis-board-detail-minutes-html');
        const minutesToolbar = detailWrap.querySelector('[data-rich-toolbar="minutes"]');
        const packetNotesEditor = detailWrap.querySelector('#metis-board-packet-notes-editor');
        const packetNotesHidden = detailWrap.querySelector('#metis-board-packet-notes');
        let packetNotesAutosaveTimer = null;
        let packetNotesLastSavedValue = '';
        function normalizeEditorHtml(editorEl) {
            if (!editorEl) return '';
            const rawHtml = String(editorEl.innerHTML || '').trim();
            const rawText = String(editorEl.textContent || '').trim();
            if (rawHtml !== '') return rawHtml;
            if (rawText === '') return '';
            return '<p>' + rawText
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\r\n|\r|\n/g, '</p><p>') + '</p>';
        }
        function currentPacketNotesPayload() {
            syncPacketNotesHiddenFromEditor();
            const hiddenValue = String(packetNotesHidden?.value || '').trim();
            if (hiddenValue !== '') return hiddenValue;
            return normalizeEditorHtml(packetNotesEditor);
        }
        function syncMinutesHiddenFromEditor() {
            if (!minutesEditor || !minutesHidden) return;
            minutesHidden.value = normalizeEditorHtml(minutesEditor);
        }
        function syncPacketNotesHiddenFromEditor() {
            if (!packetNotesEditor || !packetNotesHidden) return;
            packetNotesHidden.value = normalizeEditorHtml(packetNotesEditor);
        }
        function setMinutesEditorHtml(html) {
            if (!minutesEditor || !minutesHidden) return;
            const cleanHtml = String(html || '').trim();
            minutesEditor.innerHTML = cleanHtml !== '' ? cleanHtml : '<p></p>';
            minutesHidden.value = cleanHtml;
        }
        function setPacketNotesEditorHtml(html) {
            if (!packetNotesEditor || !packetNotesHidden) return;
            const cleanHtml = String(html || '').trim();
            packetNotesEditor.innerHTML = cleanHtml !== '' ? cleanHtml : '<p></p>';
            packetNotesHidden.value = cleanHtml;
        }
        function initRichEditor(toolbar, editor, syncCallback) {
            if (!toolbar || !editor) return;
            toolbar.addEventListener('click', function (event) {
                const cmdButton = event.target.closest('[data-rich-command]');
                if (cmdButton) {
                    event.preventDefault();
                    editor.focus();
                    document.execCommand(String(cmdButton.dataset.richCommand || ''), false, null);
                    syncCallback();
                    return;
                }
                const linkButton = event.target.closest('[data-rich-link]');
                if (linkButton) {
                    event.preventDefault();
                    editor.focus();
                    promptAction({
                        title: 'Insert Link',
                        label: 'URL',
                        message: 'Enter link URL.',
                        defaultValue: 'https://',
                        placeholder: 'https://',
                        required: true,
                        confirmLabel: 'Insert'
                    }).then(function (value) {
                        const url = String(value || '').trim();
                        if (url !== '') {
                            editor.focus();
                            document.execCommand('createLink', false, url);
                            syncCallback();
                        }
                    });
                }
            });
            toolbar.addEventListener('change', function (event) {
                const blockSelect = event.target.closest('[data-rich-block]');
                if (!blockSelect) return;
                editor.focus();
                document.execCommand('formatBlock', false, String(blockSelect.value || 'p'));
                syncCallback();
            });
            editor.addEventListener('input', syncCallback);
            editor.addEventListener('blur', syncCallback);
        }
        function minutesIsEffectivelyEmpty() {
            const raw = String(minutesHidden?.value || minutesEditor?.innerHTML || '').trim();
            if (raw === '') return true;
            const normalized = raw
                .replace(/<p>\s*(<br\s*\/?>|\u00a0|\s)*<\/p>/gi, '')
                .replace(/<br\s*\/?>/gi, '')
                .replace(/&nbsp;/gi, ' ')
                .replace(/<[^>]*>/g, '')
                .trim();
            return normalized === '';
        }
        if (minutesEditor && minutesHidden) {
            setMinutesEditorHtml(minutesHidden.value || '');
            initRichEditor(minutesToolbar, minutesEditor, syncMinutesHiddenFromEditor);
        }
        if (packetNotesEditor && packetNotesHidden) {
            setPacketNotesEditorHtml(packetNotesHidden.value || '');
            initRichEditor(detailWrap.querySelector('[data-rich-toolbar="packet-notes"]'), packetNotesEditor, syncPacketNotesHiddenFromEditor);
            packetNotesLastSavedValue = String(packetNotesHidden.value || '').trim();
            const queuePacketNotesAutosave = function () {
                if (!canManageDetail) return;
                const currentValue = String(currentPacketNotesPayload() || '').trim();
                if (currentValue === packetNotesLastSavedValue) return;
                if (packetNotesAutosaveTimer) {
                    window.clearTimeout(packetNotesAutosaveTimer);
                }
                packetNotesAutosaveTimer = window.setTimeout(function () {
                    setStepSaveState('Saving packet notes…', 'saving');
                    post('metis_board_update_meeting_detail', {
                        meeting_id: meetingIdDetail,
                        board_packet_notes: currentValue
                    }).then(function () {
                        packetNotesLastSavedValue = currentValue;
                        setStepSaveState('Packet notes saved', 'saved');
                    }).catch(function () {
                        setStepSaveState('Save failed', 'error');
                    });
                }, 500);
            };
            packetNotesEditor.addEventListener('input', queuePacketNotesAutosave);
            packetNotesEditor.addEventListener('blur', queuePacketNotesAutosave);
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
            // Re-read builder fields so minutes draft can always use latest unsaved agenda edits.
            const cards = Array.from(detailWrap.querySelectorAll('.metis-board-agenda-section-card'));
            if (cards.length > 0) {
                agendaSections = cards.map(function (card, idx) {
                    const row = normalizeAgendaSection(agendaSections[idx] || {}, idx);
                    row.section_template_code = String(card.querySelector('.metis-board-agenda-template')?.value || row.section_template_code || '');
                    row.section_name = String(card.querySelector('.metis-board-agenda-title')?.value || '').trim();
                    row.items = splitLines(card.querySelector('.metis-board-agenda-items')?.value || '');
                    row.notes = String(card.querySelector('.metis-board-agenda-notes')?.value || '').trim();
                    const pointRows = Array.from(card.querySelectorAll('.metis-board-agenda-decision-row'));
                    row.decision_points = pointRows.map(function (pointRow) {
                        return {
                            decision_template_code: String(pointRow.querySelector('.metis-board-agenda-decision-template')?.value || ''),
                            decision_title: String(pointRow.querySelector('.metis-board-agenda-decision-title')?.value || '').trim(),
                            item_title: String(pointRow.querySelector('.metis-board-agenda-decision-item')?.value || '').trim(),
                        };
                    }).filter(function (point) {
                        return point.decision_template_code !== '' || point.decision_title !== '' || point.item_title !== '';
                    });
                    return row;
                });
            }

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
                        if (tmpl && titleInput) {
                            row.section_name = String(tmpl.name || '').trim();
                            titleInput.value = row.section_name;
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
                        decisionList.innerHTML = '<div class="metis-muted">No decision points added.</div>';
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
                    agendaList.innerHTML = '<div class="metis-muted">No agenda section templates yet.</div>';
                } else {
                    agendaList.innerHTML = agendaTemplateCache.map(function (row) {
                        const code = String(row.template_code || '');
                        const name = String(row.name || 'Untitled');
                        const desc = String(row.description || '');
                        return '' +
                            '<div class="metis-board-template-row" data-template-id="' + String(row.id || '0') + '" data-template-code="' + code + '">' +
                                '<div><strong>' + name + '</strong><div class="metis-muted">' + desc + '</div></div>' +
                                '<div class="metis-board-template-actions">' +
                                    '<button type="button" class="metis-btn-xs metis-board-agenda-template-edit">Edit</button>' +
                                    '<button type="button" class="metis-btn-xs metis-btn-danger metis-board-agenda-template-delete">Disable</button>' +
                                '</div>' +
                            '</div>';
                    }).join('');
                }
            }
            if (decisionList) {
                if (decisionTemplateCache.length < 1) {
                    decisionList.innerHTML = '<div class="metis-muted">No decision templates yet.</div>';
                } else {
                    decisionList.innerHTML = decisionTemplateCache.map(function (row) {
                        const code = String(row.template_code || '');
                        const title = String(row.title || 'Untitled');
                        const desc = String(row.description || '');
                        return '' +
                            '<div class="metis-board-template-row" data-template-id="' + String(row.id || '0') + '" data-template-code="' + code + '">' +
                                '<div><strong>' + title + '</strong><div class="metis-muted">' + desc + '</div></div>' +
                                '<div class="metis-board-template-actions">' +
                                    '<button type="button" class="metis-btn-xs metis-board-decision-template-edit">Edit</button>' +
                                    '<button type="button" class="metis-btn-xs metis-btn-danger metis-board-decision-template-delete">Disable</button>' +
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
            setWorkflowModalTab('agenda');
        });

        const workflowTabButtons = Array.from(document.querySelectorAll('.metis-board-workflow-tab-btn'));
        const workflowTabPanels = Array.from(document.querySelectorAll('.metis-board-workflow-tab-panel'));
        function setWorkflowModalTab(tabKey) {
            const key = String(tabKey || 'agenda');
            workflowTabButtons.forEach(function (btn) {
                const active = String(btn.dataset.workflowTab || '') === key;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            workflowTabPanels.forEach(function (panel) {
                panel.hidden = String(panel.dataset.workflowPanel || '') !== key;
            });
        }
        workflowTabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setWorkflowModalTab(String(btn.dataset.workflowTab || 'agenda'));
            });
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

        function saveAgendaProgress() {
            setStepSaveState('Saving agenda…', 'saving');
            syncAgendaSnapshot();
            return post('metis_board_update_meeting_detail', {
                meeting_id: meetingIdDetail,
                agenda_json: detailWrap.querySelector('#metis-board-agenda-json')?.value || ''
            }).then(function () {
                return post('metis_board_sync_decision_points', {
                    meeting_id: meetingIdDetail,
                    agenda_json: detailWrap.querySelector('#metis-board-agenda-json')?.value || ''
                });
            }).then(function (data) {
                setStepSaveState('Agenda saved', 'saved');
                return data;
            }).catch(function (err) {
                setStepSaveState('Save failed', 'error');
                throw err;
            });
        }

        detailWrap.querySelector('#metis-board-agenda-form')?.addEventListener('submit', function (event) {
            event.preventDefault();
            saveAgendaProgress().then(function (data) {
                const created = parseInt(String(data && data.created_decisions != null ? data.created_decisions : '0'), 10) || 0;
                showAlert(created > 0 ? ('Agenda saved. ' + created + ' decision point(s) added to Decisions.') : 'Agenda saved.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save agenda.', 'error');
            });
        });

        function minutesDraftFromAgenda() {
            const agendaJson = detailWrap.querySelector('#metis-board-agenda-json')?.value || '[]';
            const sections = parseJson(agendaJson, []);
            if (!Array.isArray(sections) || sections.length < 1) {
                return '<h2>Meeting Minutes</h2><p>No agenda sections found.</p>';
            }
            const chunks = ['<h2>Meeting Minutes</h2>'];
            const attendanceRows = Array.from(detailWrap.querySelectorAll('.metis-board-attendance-row'));
            const attendanceInline = [];
            if (attendanceRows.length > 0) {
                attendanceRows.forEach(function (rowEl) {
                    const member = String(rowEl.querySelector('.metis-premium-cell strong')?.textContent || '').trim();
                    const status = String(rowEl.querySelector('.metis-board-attendance-status')?.value || rowEl.querySelector('.metis-chip')?.textContent || '').trim();
                    if (member === '') return;
                    attendanceInline.push('<strong>' + escHtml(member) + '</strong>' + (status !== '' ? (' (' + escHtml(status) + ')') : ''));
                });
            }
            const eligible = String(detailWrap.querySelector('#metis-board-eligible-count')?.textContent || '').trim();
            const present = String(detailWrap.querySelector('#metis-board-present-count')?.textContent || '').trim();
            const required = String(detailWrap.querySelector('#metis-board-required-count')?.textContent || '').trim();
            const quorum = String(detailWrap.querySelector('#metis-board-quorum-status')?.textContent || '').trim();
            const quorumInline = [];
            if (eligible !== '') quorumInline.push('<strong>Eligible:</strong> ' + escHtml(eligible));
            if (present !== '') quorumInline.push('<strong>Present/Remote:</strong> ' + escHtml(present));
            if (required !== '') quorumInline.push('<strong>Required:</strong> ' + escHtml(required));
            if (quorum !== '') quorumInline.push('<strong>Status:</strong> ' + escHtml(quorum));
            if (attendanceInline.length > 0 || quorumInline.length > 0) {
                chunks.push('<h3>Attendance &amp; Quorum</h3>');
                if (attendanceInline.length > 0) chunks.push('<p><strong>Attendance roll:</strong> ' + attendanceInline.join(' &nbsp;|&nbsp; ') + '</p>');
                if (quorumInline.length > 0) chunks.push('<p><strong>Quorum:</strong> ' + quorumInline.join(' &nbsp;|&nbsp; ') + '</p>');
            }
            sections.forEach(function (section, idx) {
                const row = section && typeof section === 'object' ? section : {};
                const sectionName = String(row.section_name || row.custom_title || row.section || ('Section ' + String(idx + 1))).trim();
                const items = Array.isArray(row.items) ? row.items : [];
                const points = Array.isArray(row.decision_points) ? row.decision_points : [];
                const agendaEntries = [];
                const seenEntries = new Set();
                if (points.length > 0) {
                    points.forEach(function (point) {
                        const decisionTitle = String((point && point.decision_title) || '').trim();
                        const itemTitle = String((point && (point.item_title || point.agenda_item_title)) || '').trim();
                        const label = decisionTitle !== '' ? decisionTitle : itemTitle;
                        if (label === '') return;
                        const key = label.toLowerCase();
                        if (seenEntries.has(key)) return;
                        seenEntries.add(key);
                        agendaEntries.push({
                            title: label,
                            kind: 'decision',
                            decisionOutcome: '',
                        });
                    });
                }
                items.forEach(function (item) {
                    const label = String(item || '').trim();
                    if (label === '') return;
                    const key = label.toLowerCase();
                    if (seenEntries.has(key)) return;
                    seenEntries.add(key);
                    agendaEntries.push({
                        title: label,
                        kind: 'item',
                        decisionOutcome: '',
                    });
                });
                chunks.push('<h3>' + escHtml(sectionName) + '</h3>');
                if (agendaEntries.length > 0) {
                    chunks.push('<ul>');
                    agendaEntries.forEach(function (entry) {
                        const label = escHtml(String(entry && entry.title ? entry.title : ''));
                        const decisionOutcome = escHtml(String(entry && entry.decisionOutcome ? entry.decisionOutcome : ''));
                        const isDecision = String(entry && entry.kind ? entry.kind : '') === 'decision';
                        let line = '<li><strong>' + label + '</strong>';
                        if (isDecision) {
                            line += '<div>Decision outcome: ' + decisionOutcome + '</div>';
                        }
                        line += '<div>Minutes:</div><div>Notes:</div></li>';
                        chunks.push(line);
                    });
                    chunks.push('</ul>');
                } else {
                    chunks.push('<p><em>No agenda items listed.</em></p>');
                }
                chunks.push('<p><em>Section summary:</em></p>');
            });

            const actionRows = Array.from(detailWrap.querySelectorAll('.metis-board-tab-panel[data-panel="actions"] .metis-premium-row')).filter(function (rowEl) {
                if (rowEl.classList.contains('metis-premium-header')) return false;
                const cells = Array.from(rowEl.querySelectorAll('.metis-premium-cell'));
                if (cells.length < 1) return false;
                return String(cells[0].textContent || '').toLowerCase().indexOf('no action items') === -1;
            });
            if (actionRows.length > 0) {
                chunks.push('<h3>Action Items</h3><ul>');
                actionRows.forEach(function (rowEl) {
                    const cells = Array.from(rowEl.querySelectorAll('.metis-premium-cell'));
                    const title = String(cells[0]?.querySelector('strong')?.textContent || cells[0]?.textContent || '').trim();
                    const owner = String(cells[1]?.textContent || '').trim();
                    const due = String(cells[2]?.textContent || '').trim();
                    const status = String(cells[3]?.textContent || '').trim();
                    if (title === '') return;
                    let line = '<li><strong>' + escHtml(title) + '</strong>';
                    if (owner !== '') line += '<div>Owner: ' + escHtml(owner) + '</div>';
                    if (due !== '' && due !== '—') line += '<div>Due: ' + escHtml(due) + '</div>';
                    if (status !== '') line += '<div>Status: ' + escHtml(status) + '</div>';
                    line += '<div>Notes:</div></li>';
                    chunks.push(line);
                });
                chunks.push('</ul>');
            }
            return chunks.join('');
        }

        detailWrap.querySelector('#metis-board-continue-to-minutes')?.addEventListener('click', function () {
            saveAgendaProgress().then(function (data) {
                const created = parseInt(String(data && data.created_decisions != null ? data.created_decisions : '0'), 10) || 0;
                navigateToTab('minutes', { populateMinutesDraft: true, force: true, skipSave: true });
                showAlert(created > 0 ? ('Agenda saved and minutes draft prepared. ' + created + ' decision point(s) added to Decisions.') : 'Agenda saved and minutes draft prepared.', 'success');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save agenda before opening minutes.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-generate-minutes-draft')?.addEventListener('click', function () {
            syncAgendaSnapshot();
            setMinutesEditorHtml(minutesDraftFromAgenda());
            showAlert('Minutes draft populated from agenda.', 'success');
        });

        detailWrap.querySelector('#metis-board-minutes-form')?.addEventListener('submit', function (event) {
            event.preventDefault();
            syncMinutesHiddenFromEditor();
            setStepSaveState('Saving minutes…', 'saving');
            post('metis_board_update_meeting_detail', {
                meeting_id: meetingIdDetail,
                minutes_html: detailWrap.querySelector('#metis-board-detail-minutes-html')?.value || ''
            }).then(function () {
                setStepSaveState('Minutes saved', 'saved');
                showAlert('Minutes saved.', 'success');
            }).catch(function (err) {
                setStepSaveState('Save failed', 'error');
                showAlert(err.message || 'Failed to save minutes.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-generate-packet')?.addEventListener('click', function () {
            syncAgendaSnapshot();
            syncMinutesHiddenFromEditor();
            syncPacketNotesHiddenFromEditor();
            const minutesRef = String(detailWrap.querySelector('#metis-board-packet-prev-minutes')?.value || '0');
            post('metis_board_update_meeting_detail', {
                meeting_id: meetingIdDetail,
                agenda_json: detailWrap.querySelector('#metis-board-agenda-json')?.value || '',
                minutes_html: detailWrap.querySelector('#metis-board-detail-minutes-html')?.value || '',
                board_packet_notes: detailWrap.querySelector('#metis-board-packet-notes')?.value || ''
            }).then(function () {
                return post('metis_board_generate_packet_pdf', {
                    meeting_id: meetingIdDetail,
                    packet_source_minutes_meeting_id: minutesRef,
                    packet_source_minutes_reference: minutesRef,
                    packet_financial_document_id: detailWrap.querySelector('#metis-board-packet-financial-doc')?.value || '0'
                });
            }).then(function () {
                showAlert('Agenda and packet PDFs generated and uploaded.', 'success');
                if (typeof detailWrap.__metisBoardLoadDrive === 'function') detailWrap.__metisBoardLoadDrive();
                setStepSaveState('Packet generated', 'saved');
            }).catch(function (err) {
                showAlert(err.message || 'Failed to generate packet PDFs.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-resend-packet-email')?.addEventListener('click', function () {
            setStepSaveState('Sending packet email…', 'saving');
            post('metis_board_resend_packet_email', {
                meeting_id: meetingIdDetail
            }).then(function (data) {
                const publishEmail = data && data.publish_email && typeof data.publish_email === 'object' ? data.publish_email : null;
                const sent = parseInt(String((publishEmail && publishEmail.sent) || '0'), 10) || 0;
                const total = parseInt(String((publishEmail && publishEmail.total) || '0'), 10) || 0;
                const audienceLabel = String((publishEmail && publishEmail.audience_label) || 'recipients');
                setStepSaveState('Packet email sent', 'saved');
                showAlert('Packet email sent to ' + String(sent) + ' of ' + String(total) + ' ' + audienceLabel + '.', sent > 0 ? 'success' : 'error');
            }).catch(function (err) {
                setStepSaveState('Email send failed', 'error');
                showAlert(err.message || 'Failed to resend packet email.', 'error');
            });
        });

        detailWrap.querySelector('#metis-board-packet-form')?.addEventListener('submit', function (event) {
            event.preventDefault();
            syncPacketNotesHiddenFromEditor();
            setStepSaveState('Saving packet metadata…', 'saving');
            post('metis_board_update_meeting_detail', {
                meeting_id: meetingIdDetail,
                board_packet_notes: currentPacketNotesPayload(),
                packet_source_minutes_meeting_id: detailWrap.querySelector('#metis-board-packet-prev-minutes')?.value || '0',
                packet_financial_document_id: detailWrap.querySelector('#metis-board-packet-financial-doc')?.value || '0',
                packet_published: detailWrap.querySelector('#metis-board-packet-publish')?.checked ? '1' : '0'
            }).then(function (data) {
                packetNotesLastSavedValue = String(currentPacketNotesPayload() || '').trim();
                setStepSaveState('Packet metadata saved', 'saved');
                const publishEmail = data && data.publish_email && typeof data.publish_email === 'object' ? data.publish_email : null;
                if (publishEmail && publishEmail.total != null) {
                    const sent = parseInt(String(publishEmail.sent || '0'), 10) || 0;
                    const total = parseInt(String(publishEmail.total || '0'), 10) || 0;
                    const audienceLabel = String(publishEmail.audience_label || 'recipients');
                    if (total > 0) {
                        showAlert('Packet metadata saved. Packet email sent to ' + String(sent) + ' of ' + String(total) + ' ' + audienceLabel + '.', sent > 0 ? 'success' : 'error');
                    } else {
                        showAlert('Packet metadata saved.', 'success');
                    }
                } else if (publishEmail && publishEmail.ok === false) {
                    showAlert('Packet saved, but publish email failed: ' + String(publishEmail.error || 'Unknown error.'), 'error');
                } else {
                    showAlert('Packet metadata saved.', 'success');
                }
            }).catch(function (err) {
                setStepSaveState('Save failed', 'error');
                showAlert(err.message || 'Failed to save packet metadata.', 'error');
            });
        });

        function normalizeVoteAssignments(rawAssignments, membersById) {
            const base = rawAssignments && typeof rawAssignments === 'object' ? rawAssignments : {};
            const used = new Set();
            const normalized = { for: [], against: [], abstain: [] };
            ['for', 'against', 'abstain'].forEach(function (bucket) {
                const list = Array.isArray(base[bucket]) ? base[bucket] : [];
                list.forEach(function (idValue) {
                    const id = parseInt(String(idValue || '0'), 10);
                    if (id < 1 || used.has(id) || !membersById.has(id)) return;
                    used.add(id);
                    normalized[bucket].push(id);
                });
            });
            return normalized;
        }

        initDecisionVoteDnD = function (row) {
            const dndRoot = row.querySelector('[data-vote-dnd]');
            if (!dndRoot) return;
            let members = parseJson(String(row.dataset.voteMembers || '[]'), []);
            if (!Array.isArray(members)) members = [];
            const membersById = new Map();
            members.forEach(function (member) {
                const id = parseInt(String(member && member.id != null ? member.id : '0'), 10);
                const name = String(member && member.name != null ? member.name : '').trim();
                if (id > 0 && name !== '') membersById.set(id, name);
            });
            if (membersById.size < 1) return;

            let assignments = parseJson(String(row.dataset.voteAssignments || '{}'), {});
            assignments = normalizeVoteAssignments(assignments, membersById);
            row.dataset.voteAssignments = JSON.stringify(assignments);
            let autosaveTimer = 0;
            let autosaveInFlight = false;
            let autosaveQueued = false;

            const bucketEls = {
                available: dndRoot.querySelector('[data-vote-bucket="available"]'),
                for: dndRoot.querySelector('[data-vote-bucket="for"]'),
                against: dndRoot.querySelector('[data-vote-bucket="against"]'),
                abstain: dndRoot.querySelector('[data-vote-bucket="abstain"]'),
            };
            const eligibleCount = parseInt(String(detailWrap.querySelector('#metis-board-eligible-count')?.textContent || '0'), 10) || 0;

            function deriveDecisionOutcome(forCount, againstCount) {
                const threshold = Math.max(1, Math.floor(Math.max(1, eligibleCount) / 2) + 1);
                if (forCount >= threshold) return 'approved';
                if (againstCount >= threshold) return 'rejected';
                return 'pending';
            }

            function render() {
                Object.keys(bucketEls).forEach(function (key) {
                    if (bucketEls[key]) bucketEls[key].innerHTML = '';
                });
                const used = new Set([].concat(assignments.for, assignments.against, assignments.abstain));
                const available = [];
                membersById.forEach(function (_, id) {
                    if (!used.has(id)) available.push(id);
                });

                function chip(memberId) {
                    const node = document.createElement('span');
                    node.className = 'metis-board-vote-chip';
                    node.draggable = true;
                    node.setAttribute('draggable', 'true');
                    node.dataset.personId = String(memberId);
                    node.textContent = String(membersById.get(memberId) || ('Member ' + String(memberId)));
                    node.addEventListener('dragstart', function (event) {
                        if (!event.dataTransfer) return;
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.dropEffect = 'move';
                        event.dataTransfer.setData('text/plain', String(memberId));
                        event.dataTransfer.setData('application/x-metis-person', String(memberId));
                        // Force a clean drag ghost to avoid browser/extension white box artifacts.
                        const ghost = node.cloneNode(true);
                        ghost.style.position = 'fixed';
                        ghost.style.top = '-1000px';
                        ghost.style.left = '-1000px';
                        ghost.style.margin = '0';
                        ghost.style.pointerEvents = 'none';
                        document.body.appendChild(ghost);
                        event.dataTransfer.setDragImage(ghost, 12, 12);
                        window.setTimeout(function () {
                            if (ghost.parentNode) ghost.parentNode.removeChild(ghost);
                        }, 0);
                    });
                    return node;
                }

                available.forEach(function (id) { bucketEls.available?.appendChild(chip(id)); });
                assignments.for.forEach(function (id) { bucketEls.for?.appendChild(chip(id)); });
                assignments.against.forEach(function (id) { bucketEls.against?.appendChild(chip(id)); });
                assignments.abstain.forEach(function (id) { bucketEls.abstain?.appendChild(chip(id)); });

                const forEl = row.querySelector('.metis-board-decision-for');
                const againstEl = row.querySelector('.metis-board-decision-against');
                const abstainEl = row.querySelector('.metis-board-decision-abstain');
                if (forEl) {
                    forEl.textContent = String(assignments.for.length);
                    forEl.dataset.count = String(assignments.for.length);
                }
                if (againstEl) {
                    againstEl.textContent = String(assignments.against.length);
                    againstEl.dataset.count = String(assignments.against.length);
                }
                if (abstainEl) {
                    abstainEl.textContent = String(assignments.abstain.length);
                    abstainEl.dataset.count = String(assignments.abstain.length);
                }
                const outcomeEl = row.querySelector('.metis-board-decision-outcome');
                if (outcomeEl) {
                    outcomeEl.value = deriveDecisionOutcome(assignments.for.length, assignments.against.length);
                }
                row.dataset.voteAssignments = JSON.stringify(assignments);
            }

            function saveDecisionRowBackground() {
                const forCount = parseInt(String(row.querySelector('.metis-board-decision-for')?.dataset.count || row.querySelector('.metis-board-decision-for')?.textContent || '0'), 10) || 0;
                const againstCount = parseInt(String(row.querySelector('.metis-board-decision-against')?.dataset.count || row.querySelector('.metis-board-decision-against')?.textContent || '0'), 10) || 0;
                const abstainCount = parseInt(String(row.querySelector('.metis-board-decision-abstain')?.dataset.count || row.querySelector('.metis-board-decision-abstain')?.textContent || '0'), 10) || 0;
                if (autosaveInFlight) {
                    autosaveQueued = true;
                    return;
                }
                autosaveInFlight = true;
                setStepSaveState('Saving decision votes…', 'saving');
                post('metis_board_update_decision', {
                    decision_id: String(row.dataset.decisionId || ''),
                    outcome: deriveDecisionOutcome(forCount, againstCount),
                    votes_for: String(forCount),
                    votes_against: String(againstCount),
                    votes_abstain: String(abstainCount),
                    vote_assignments_json: row.dataset.voteAssignments || ''
                }).then(function (data) {
                    const nextOutcome = String((data && data.outcome) || deriveDecisionOutcome(forCount, againstCount));
                    const outcomeEl = row.querySelector('.metis-board-decision-outcome');
                    if (outcomeEl) outcomeEl.value = nextOutcome;
                    setStepSaveState('Saved', 'saved');
                }).catch(function (err) {
                    setStepSaveState('Save failed', 'error');
                    showAlert(err.message || 'Failed to save decision votes.', 'error');
                }).finally(function () {
                    autosaveInFlight = false;
                    if (autosaveQueued) {
                        autosaveQueued = false;
                        saveDecisionRowBackground();
                    }
                });
            }

            function queueDecisionAutosave() {
                if (autosaveTimer) window.clearTimeout(autosaveTimer);
                autosaveTimer = window.setTimeout(saveDecisionRowBackground, 320);
            }

            function movePersonToBucket(personId, bucket) {
                const id = parseInt(String(personId || '0'), 10);
                if (id < 1 || !membersById.has(id)) return;
                ['for', 'against', 'abstain'].forEach(function (key) {
                    assignments[key] = assignments[key].filter(function (memberId) { return memberId !== id; });
                });
                if (bucket === 'for' || bucket === 'against' || bucket === 'abstain') {
                    assignments[bucket].push(id);
                }
                assignments = normalizeVoteAssignments(assignments, membersById);
                render();
            }

            Object.keys(bucketEls).forEach(function (bucket) {
                const zone = bucketEls[bucket];
                if (!zone) return;
                zone.addEventListener('dragenter', function (event) {
                    event.preventDefault();
                    zone.classList.add('is-over');
                });
                zone.addEventListener('dragover', function (event) {
                    event.preventDefault();
                    zone.classList.add('is-over');
                });
                zone.addEventListener('dragleave', function () {
                    zone.classList.remove('is-over');
                });
                zone.addEventListener('drop', function (event) {
                    event.preventDefault();
                    zone.classList.remove('is-over');
                    const idRaw = String(
                        event.dataTransfer?.getData('application/x-metis-person')
                        || event.dataTransfer?.getData('text/plain')
                        || ''
                    ).trim();
                    movePersonToBucket(idRaw, bucket);
                    queueDecisionAutosave();
                });
            });
            render();
        };

        detailWrap.querySelectorAll('.metis-board-decision-row').forEach(function (row) {
            initDecisionVoteDnD(row);
        });

        function applyQuorumSummary(data) {
            const presentEl = detailWrap.querySelector('#metis-board-present-count');
            const eligibleEl = detailWrap.querySelector('#metis-board-eligible-count');
            const requiredEl = detailWrap.querySelector('#metis-board-required-count');
            const statusEl = detailWrap.querySelector('#metis-board-quorum-status');
            const kpiAttendanceEl = detailWrap.querySelector('#metis-board-kpi-attendance');
            const kpiQuorumEl = detailWrap.querySelector('#metis-board-kpi-quorum');
            if (presentEl && data && data.present_count != null) presentEl.textContent = String(data.present_count);
            if (eligibleEl && data && data.eligible_count != null) eligibleEl.textContent = String(data.eligible_count);
            if (requiredEl && data && data.required_count != null) requiredEl.textContent = String(data.required_count);
            if (kpiAttendanceEl && data && data.present_count != null) {
                kpiAttendanceEl.textContent = String(data.present_count);
            }
            if (statusEl && data && data.quorum_met != null) {
                statusEl.textContent = data.quorum_met ? 'Met' : 'Pending';
                statusEl.classList.toggle('metis-chip-success', !!data.quorum_met);
                if (kpiQuorumEl) {
                    kpiQuorumEl.textContent = data.quorum_met ? 'Met' : 'Pending';
                }
            }
        }

        const attendanceAutosaveState = new WeakMap();
        function queueAttendanceAutosave(row) {
            if (!row) return;
            const existing = attendanceAutosaveState.get(row) || { timer: 0, inFlight: false, queued: false };
            if (existing.timer) window.clearTimeout(existing.timer);
            existing.timer = window.setTimeout(function () {
                const state = attendanceAutosaveState.get(row) || existing;
                if (state.inFlight) {
                    state.queued = true;
                    attendanceAutosaveState.set(row, state);
                    return;
                }
                state.inFlight = true;
                state.queued = false;
                attendanceAutosaveState.set(row, state);
                setStepSaveState('Saving attendance…', 'saving');
                post('metis_board_upsert_attendance', {
                    meeting_id: meetingIdDetail,
                    person_id: String(row.dataset.personId || ''),
                    role_label: row.querySelector('.metis-board-attendance-role')?.value || '',
                    status: row.querySelector('.metis-board-attendance-status')?.value || 'absent',
                    notes: row.querySelector('.metis-board-attendance-notes')?.value || ''
                }).then(function (data) {
                    applyQuorumSummary(data);
                    setStepSaveState('Saved', 'saved');
                }).catch(function (err) {
                    setStepSaveState('Save failed', 'error');
                    showAlert(err.message || 'Failed to save attendance.', 'error');
                }).finally(function () {
                    const latest = attendanceAutosaveState.get(row) || state;
                    latest.inFlight = false;
                    if (latest.queued) {
                        latest.queued = false;
                        attendanceAutosaveState.set(row, latest);
                        queueAttendanceAutosave(row);
                        return;
                    }
                    attendanceAutosaveState.set(row, latest);
                });
            }, 320);
            attendanceAutosaveState.set(row, existing);
        }

        detailWrap.querySelectorAll('.metis-board-attendance-row').forEach(function (row) {
            const roleInput = row.querySelector('.metis-board-attendance-role');
            const statusSelect = row.querySelector('.metis-board-attendance-status');
            const notesInput = row.querySelector('.metis-board-attendance-notes');
            statusSelect?.addEventListener('change', function () { queueAttendanceAutosave(row); });
            roleInput?.addEventListener('change', function () { queueAttendanceAutosave(row); });
            notesInput?.addEventListener('change', function () { queueAttendanceAutosave(row); });
            notesInput?.addEventListener('blur', function () { queueAttendanceAutosave(row); });
            roleInput?.addEventListener('blur', function () { queueAttendanceAutosave(row); });
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
    const bylawsModal = document.getElementById('metis-board-bylaws-modal');

    document.querySelectorAll('.metis-modal-backdrop').forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal(modal);
        });
    });

    document.querySelectorAll('.metis-board-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModal(btn.closest('.metis-modal-backdrop'));
        });
    });

    rebuildBylawsToc();

    bylawsToc?.addEventListener('click', function (event) {
        const link = event.target.closest('a[href^="#"]');
        if (!link || !bylawsReader) return;
        event.preventDefault();
        const rawId = String(link.getAttribute('href') || '').replace(/^#/, '');
        const selector = '#' + (window.CSS && typeof CSS.escape === 'function' ? CSS.escape(rawId) : rawId.replace(/"/g, '\\"'));
        const target = bylawsReader.querySelector(selector);
        if (!target) return;
        const readerRect = bylawsReader.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        bylawsReader.scrollTo({
            top: Math.max(0, bylawsReader.scrollTop + targetRect.top - readerRect.top - 10),
            behavior: 'smooth'
        });
    });

    bylawsSearch?.addEventListener('input', applyBylawsSearch);
    bylawsSearchNext?.addEventListener('click', function () {
        setActiveBylawsSearchMatch(bylawsActiveSearchIndex + 1);
    });
    bylawsSearchPrev?.addEventListener('click', function () {
        setActiveBylawsSearchMatch(bylawsActiveSearchIndex - 1);
    });

    bylawsDisplay?.addEventListener('click', function (event) {
        const btn = event.target.closest('#metis-board-view-bylaws');
        if (!btn) return;
        event.preventDefault();
        refreshBylawsReader(bylawsReaderOriginalHtml);
        openModal(bylawsViewModal);
        setTimeout(function () {
            bylawsSearch?.focus();
        }, 50);
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
    document.getElementById('metis-board-edit-bylaws')?.addEventListener('click', function () {
        openModal(bylawsModal);
    });

    committeeRowsContainer?.addEventListener('click', function (event) {
        const btn = event.target.closest('.metis-board-edit-committee');
        if (!btn) return;
        event.preventDefault();
        event.stopPropagation();
        const row = btn.closest('.metis-premium-row');
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
        set('metis-board-committee-newsletter-list', String(data.newsletter_list_id || ''));
        set('metis-board-committee-description', String(data.description || ''));
        set('metis-board-committee-active', String(parseInt(String(data.is_active || '1'), 10) === 1 ? '1' : '0'));
        openModal(committeeModal);
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
        }).then(function (data) {
            const saved = data && data.meeting && typeof data.meeting === 'object' ? data.meeting : null;
            if (saved) {
                upsertMeetingRow(saved);
            }
            closeModal(meetingModal);
            showAlert('Meeting saved.', 'success');
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
            newsletter_list_id: document.getElementById('metis-board-committee-newsletter-list')?.value || '',
            description: document.getElementById('metis-board-committee-description')?.value || '',
            is_active: document.getElementById('metis-board-committee-active')?.value || '1'
        }).then(function (data) {
            const saved = data && data.committee && typeof data.committee === 'object' ? data.committee : null;
            if (saved) {
                upsertCommitteeRow(saved);
                syncMeetingCommitteeOption(saved);
                refreshMeetingRowsForCommittee(saved);
            }
            closeModal(committeeModal);
            showAlert('Committee saved.', 'success');
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
        }).then(function (data) {
            const saved = data && data.decision && typeof data.decision === 'object' ? data.decision : null;
            const activeMeetingId = String(detailWrap?.dataset.meetingId || '');
            if (saved && detailWrap && activeMeetingId !== '' && String(saved.meeting_id || '') === activeMeetingId) {
                upsertDecisionRow(saved);
            }
            closeModal(decisionModal);
            showAlert('Decision recorded.', 'success');
        }).catch(function (err) {
            showAlert(err.message || 'Failed to save decision.', 'error');
        });
    });

    document.getElementById('metis-board-action-form')?.addEventListener('submit', function (event) {
        event.preventDefault();
        const meetingIdValue = document.getElementById('metis-board-action-meeting')?.value || '';
        post('metis_board_save_action_item', {
            meeting_id: meetingIdValue,
            owner_person_id: document.getElementById('metis-board-action-owner')?.value || '',
            title: document.getElementById('metis-board-action-title')?.value || '',
            description: document.getElementById('metis-board-action-description')?.value || '',
            due_date: document.getElementById('metis-board-action-due')?.value || '',
            priority: document.getElementById('metis-board-action-priority')?.value || 'normal',
            status: document.getElementById('metis-board-action-status')?.value || 'open'
        }).then(function (data) {
            closeModal(actionModal);
            showAlert('Action item saved.', 'success');
            const activeMeetingId = String(detailWrap?.dataset.meetingId || '');
            const saved = data && data.action_item && typeof data.action_item === 'object' ? data.action_item : null;
            if (!saved) return;

            if (detailWrap && activeMeetingId !== '' && String(meetingIdValue) === activeMeetingId) {
                upsertMeetingActionRow(saved);
                return;
            }

            upsertDashboardActionRow(saved);
            if (String(saved.status || 'open') !== 'done') {
                bumpMeetingOpenActionCount(saved.meeting_id, 1);
            }
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
        }).then(function (data) {
            const saved = data && data.announcement && typeof data.announcement === 'object' ? data.announcement : null;
            if (saved) {
                upsertAnnouncementRow(saved);
            }
            closeModal(announcementModal);
            showAlert('Announcement saved.', 'success');
        }).catch(function (err) {
            showAlert(err.message || 'Failed to save announcement.', 'error');
        });
    });

    document.getElementById('metis-board-format-bylaws')?.addEventListener('click', function () {
        post('metis_board_format_bylaws', {
            title: document.getElementById('metis-board-bylaws-title')?.value || 'Bylaws',
            source_text: document.getElementById('metis-board-bylaws-source')?.value || ''
        }).then(function (data) {
            const formatted = data && data.formatted && typeof data.formatted === 'object' ? data.formatted : null;
            if (formatted && bylawsPreview) {
                bylawsPreview.innerHTML = String(formatted.html || '');
            }
            showAlert('Bylaws preview formatted.', 'success');
        }).catch(function (err) {
            showAlert(err.message || 'Failed to format bylaws.', 'error');
        });
    });

    document.getElementById('metis-board-bylaws-form')?.addEventListener('submit', function (event) {
        event.preventDefault();
        post('metis_board_save_bylaws', {
            bylaw_id: document.getElementById('metis-board-bylaws-id')?.value || '0',
            title: document.getElementById('metis-board-bylaws-title')?.value || 'Bylaws',
            effective_date: document.getElementById('metis-board-bylaws-effective')?.value || '',
            meeting_id: document.getElementById('metis-board-bylaws-meeting')?.value || '',
            decision_id: document.getElementById('metis-board-bylaws-decision')?.value || '',
            action_item_id: document.getElementById('metis-board-bylaws-action')?.value || '',
            signed_pdf_url: document.getElementById('metis-board-bylaws-pdf-url')?.value || '',
            signed_pdf_file_id: document.getElementById('metis-board-bylaws-pdf-id')?.value || '',
            signed_pdf_title: document.getElementById('metis-board-bylaws-pdf-title')?.value || 'Signed bylaws PDF',
            change_summary: document.getElementById('metis-board-bylaws-change-summary')?.value || '',
            source_text: document.getElementById('metis-board-bylaws-source')?.value || ''
        }).then(function (data) {
            const saved = data && data.bylaws && typeof data.bylaws === 'object' ? data.bylaws : null;
            if (saved) {
                updateBylawsPanel(saved);
            }
            if (bylawsHistory && Array.isArray(data && data.history)) {
                bylawsHistory.innerHTML = bylawsHistoryHtml(data.history);
            }
            showAlert('Bylaws draft saved. Secretary certification is the next approval step.', 'success');
        }).catch(function (err) {
            showAlert(err.message || 'Failed to save bylaws.', 'error');
        });
    });

    document.getElementById('metis-board-secretary-certify-bylaws')?.addEventListener('click', function () {
        post('metis_board_secretary_certify_bylaws', {
            bylaw_id: document.getElementById('metis-board-bylaws-id')?.value || '0'
        }).then(function (data) {
            const saved = data && data.bylaws && typeof data.bylaws === 'object' ? data.bylaws : null;
            if (saved) updateBylawsPanel(saved);
            if (bylawsHistory && Array.isArray(data && data.history)) {
                bylawsHistory.innerHTML = bylawsHistoryHtml(data.history);
            }
            showAlert('Bylaws certified by secretary. President approval is now available after the linked board vote is approved.', 'success');
        }).catch(function (err) {
            showAlert(err.message || 'Failed to certify bylaws.', 'error');
        });
    });

    document.getElementById('metis-board-president-approve-bylaws')?.addEventListener('click', function () {
        post('metis_board_president_approve_bylaws', {
            bylaw_id: document.getElementById('metis-board-bylaws-id')?.value || '0'
        }).then(function (data) {
            const saved = data && data.bylaws && typeof data.bylaws === 'object' ? data.bylaws : null;
            if (saved) updateBylawsPanel(saved);
            if (bylawsHistory && Array.isArray(data && data.history)) {
                bylawsHistory.innerHTML = bylawsHistoryHtml(data.history);
            }
            closeModal(bylawsModal);
            showAlert('Bylaws approved by president and activated.', 'success');
        }).catch(function (err) {
            showAlert(err.message || 'Failed to approve bylaws.', 'error');
        });
    });

    document.getElementById('metis-board-browse-bylaws-pdf')?.addEventListener('click', function () {
        if (!bylawsPdfBrowserModal || !bylawsPdfOptions) return;
        bylawsPdfOptions.innerHTML = '<div class="metis-muted">Loading PDF options...</div>';
        openModal(bylawsPdfBrowserModal);
        post('metis_board_list_bylaws_pdf_options', {}).then(function (data) {
            const options = Array.isArray(data && data.options) ? data.options : [];
            if (!options.length) {
                bylawsPdfOptions.innerHTML = '<div class="metis-empty-state">No PDF files found.</div>';
                return;
            }
            bylawsPdfOptions.innerHTML = options.map(function (option) {
                return '<button type="button" class="metis-board-bylaws-pdf-option" data-pdf-id="' + escHtml(option.id || '') + '" data-pdf-url="' + escHtml(option.url || '') + '" data-pdf-title="' + escHtml(option.title || 'Signed bylaws PDF') + '">'
                    + '<span><strong>' + escHtml(option.title || 'Signed bylaws PDF') + '</strong><span class="metis-muted">' + escHtml(option.meta || option.source || '') + '</span></span>'
                    + '<span class="metis-btn-xs metis-btn-ghost">Select</span>'
                    + '</button>';
            }).join('');
        }).catch(function (err) {
            bylawsPdfOptions.innerHTML = '<div class="metis-empty-state">' + escHtml(err.message || 'Failed to load PDF options.') + '</div>';
        });
    });

    bylawsPdfOptions?.addEventListener('click', function (event) {
        const option = event.target.closest('.metis-board-bylaws-pdf-option');
        if (!option) return;
        const set = function (id, value) {
            const el = document.getElementById(id);
            if (el) el.value = String(value || '');
        };
        set('metis-board-bylaws-pdf-id', option.dataset.pdfId || '');
        set('metis-board-bylaws-pdf-url', option.dataset.pdfUrl || '');
        set('metis-board-bylaws-pdf-title', option.dataset.pdfTitle || 'Signed bylaws PDF');
        closeModal(bylawsPdfBrowserModal);
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
        const docMetaModal = document.getElementById('metis-board-doc-meta-modal');
        const docMetaForm = document.getElementById('metis-board-doc-meta-form');
        const docMetaFileWrap = document.getElementById('metis-board-doc-meta-file-wrap');
        const docMetaFile = document.getElementById('metis-board-doc-meta-file');
        const docMetaTitle = document.getElementById('metis-board-doc-meta-title');
        const docMetaType = document.getElementById('metis-board-doc-meta-type');
        const docMetaSubmit = document.getElementById('metis-board-doc-meta-submit');
        const previewModal = document.getElementById('metis-board-drive-preview-modal');
        const previewFrame = document.getElementById('metis-board-drive-preview-frame');
        const previewOpen = document.getElementById('metis-board-drive-preview-open');
        const previewDownload = document.getElementById('metis-board-drive-preview-download');

        const meetingId = String(driveWrap.dataset.meetingId || '0');
        const driveModuleUrl = String(driveWrap.dataset.driveUrl || '').trim();
        const canManage = String(driveWrap.dataset.canManage || '0') === '1';
        let folderId = String(driveWrap.dataset.folderId || '').trim() || 'root';
        let folderName = '';
        let parentId = '';
        let searchTimer = null;
        let pendingDocAction = null;
        let driveLoaded = false;

        function refreshPacketDocumentDropdowns(docs) {
            const list = Array.isArray(docs) ? docs : [];
            const minutesSelect = document.getElementById('metis-board-packet-prev-minutes');
            const financialSelect = document.getElementById('metis-board-packet-financial-doc');

            if (minutesSelect) {
                const selectedValue = String(minutesSelect.value || '0');
                const meetingGroup = minutesSelect.querySelector('optgroup[label="Prior Meetings"]');
                let legacyGroup = minutesSelect.querySelector('optgroup[label="Legacy Meeting Docs"]');
                if (!legacyGroup) {
                    legacyGroup = document.createElement('optgroup');
                    legacyGroup.label = 'Legacy Meeting Docs';
                    minutesSelect.appendChild(legacyGroup);
                }
                legacyGroup.innerHTML = '';
                list.forEach(function (doc) {
                    const docId = String(doc && doc.id != null ? doc.id : '').trim();
                    if (docId === '') return;
                    const docType = String(doc && doc.doc_type != null ? doc.doc_type : '').toLowerCase();
                    if (docType !== 'minutes' && docType !== 'minutes_attachment') return;
                    const label = String(doc && doc.doc_type_label != null ? doc.doc_type_label : 'Minutes');
                    const title = String(doc && doc.title != null ? doc.title : 'Meeting Minutes');
                    const opt = document.createElement('option');
                    opt.value = 'doc:' + docId;
                    opt.textContent = title + ' (' + label + ')';
                    legacyGroup.appendChild(opt);
                });
                if (legacyGroup.children.length < 1) {
                    if (!meetingGroup) {
                        const none = document.createElement('option');
                        none.value = '0';
                        none.textContent = 'None';
                        minutesSelect.appendChild(none);
                    }
                    legacyGroup.remove();
                }
                minutesSelect.value = selectedValue;
            }

            if (financialSelect) {
                const selectedValue = String(financialSelect.value || '0');
                financialSelect.innerHTML = '';
                const none = document.createElement('option');
                none.value = '0';
                none.textContent = 'None';
                financialSelect.appendChild(none);
                list.forEach(function (doc) {
                    const docId = String(doc && doc.id != null ? doc.id : '').trim();
                    if (docId === '') return;
                    const docType = String(doc && doc.doc_type != null ? doc.doc_type : '').toLowerCase();
                    if (docType === 'board_packet' || docType === 'agenda' || docType === 'minutes') return;
                    const label = String(doc && doc.doc_type != null ? doc.doc_type : 'supporting_doc');
                    const title = String(doc && doc.title != null ? doc.title : 'Document');
                    const opt = document.createElement('option');
                    opt.value = docId;
                    opt.textContent = title + ' (' + label + ')';
                    financialSelect.appendChild(opt);
                });
                financialSelect.value = selectedValue;
            }
        }

        function openPacketUploadModal(withFile) {
            pendingDocAction = { mode: 'upload' };
            if (docMetaFileWrap) docMetaFileWrap.hidden = false;
            if (docMetaTitle) docMetaTitle.value = withFile && withFile.name ? String(withFile.name) : '';
            if (docMetaType) docMetaType.value = 'supporting_doc';
            if (docMetaSubmit) docMetaSubmit.textContent = 'Upload Document';
            openModal(docMetaModal);
        }

        docMetaFile?.addEventListener('change', function () {
            const f = docMetaFile.files && docMetaFile.files[0] ? docMetaFile.files[0] : null;
            if (!f || !docMetaTitle || String(docMetaTitle.value || '').trim() !== '') return;
            docMetaTitle.value = String(f.name || 'Packet Document');
        });

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
            if (window.Metis && Metis.time && typeof Metis.time.format === 'function') {
                return Metis.time.format(raw, { empty: '—' }) || '—';
            }
            const d = new Date(raw);
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleString();
        }

        function extractDriveFileId(link) {
            const raw = String(link || '').trim();
            if (raw === '') return '';
            const idMatch = raw.match(/\/d\/([^/]+)/);
            if (idMatch && idMatch[1]) return String(idMatch[1]).trim();
            return '';
        }

        function drivePreviewUrl(link) {
            const fileId = extractDriveFileId(link);
            if (fileId !== '') return 'https://drive.google.com/file/d/' + encodeURIComponent(fileId) + '/preview';
            return String(link || '').replace('/view', '/preview');
        }

        function driveDownloadUrl(link) {
            const fileId = extractDriveFileId(link);
            if (fileId !== '') return 'https://drive.google.com/uc?export=download&id=' + encodeURIComponent(fileId);
            return String(link || '');
        }

        function openPreviewModal(link) {
            const raw = String(link || '').trim();
            if (raw === '') {
                showAlert('Preview is not available for this file.', 'error');
                return;
            }
            const previewUrl = drivePreviewUrl(raw);
            const downloadUrl = driveDownloadUrl(raw);
            if (previewFrame) previewFrame.src = previewUrl;
            if (previewOpen) previewOpen.href = raw;
            if (previewDownload) previewDownload.href = downloadUrl;
            openModal(previewModal);
        }

        function renderLinkedDocuments(docs) {
            if (!linkedDocRows) return;
            const list = Array.isArray(docs) ? docs : [];
            if (list.length < 1) {
                linkedDocRows.innerHTML = '<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="4">No meeting documents linked.</td></tr>';
                return;
            }
            linkedDocRows.innerHTML = list.map(function (doc) {
                const docId = parseInt(String(doc && doc.id != null ? doc.id : '0'), 10) || 0;
                const title = String(doc && doc.title != null ? doc.title : '');
                const docTypeLabel = String(doc && doc.doc_type_label != null ? doc.doc_type_label : (doc && doc.doc_type != null ? doc.doc_type : 'Document'));
                const link = String(doc && doc.google_drive_url != null ? doc.google_drive_url : '');
                return '' +
                    '<tr class="metis-premium-row">' +
                        '<td class="metis-premium-cell"><strong>' + escHtml(title || 'Document') + '</strong></td>' +
                        '<td class="metis-premium-cell">' + escHtml(docTypeLabel) + '</td>' +
                        '<td class="metis-premium-cell">' + (link ? ('<button type="button" class="metis-btn-xs metis-btn-ghost metis-board-preview-linked-doc" data-link="' + escHtml(link) + '">Link</button>') : '<span class="metis-muted">—</span>') + '</td>' +
                        '<td class="metis-premium-cell">' + (canManage ? ('<button type="button" class="metis-btn-xs metis-btn-danger metis-board-unlink-doc" data-document-id="' + String(docId) + '">Unlink</button>') : '<span class="metis-muted">—</span>') + '</td>' +
                    '</tr>';
            }).join('');
        }

        function refreshLinkedDocuments() {
            return post('metis_board_get_meeting_documents', { meeting_id: meetingId }).then(function (data) {
                renderLinkedDocuments((data && data.documents) || []);
                refreshPacketDocumentDropdowns((data && data.documents) || []);
                return data;
            });
        }

        function renderDriveRows(files) {
            if (!driveRows) return;
            const list = Array.isArray(files) ? files : [];
            if (list.length === 0) {
                driveRows.innerHTML = '<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="5">No files found in this folder.</td></tr>';
                return;
            }
            driveRows.innerHTML = list.map(function (file) {
                const id = String(file.id || '');
                const name = String(file.name || 'Untitled');
                const mime = String(file.mimeType || '');
                const isFolder = !!file.isFolder;
                const link = String(file.webViewLink || '');
                const actions = [];
                if (isFolder && driveModuleUrl !== '') {
                    actions.push('<a class="metis-btn-xs metis-btn-ghost" href="' + escHtml(driveModuleUrl + '?folder_id=' + encodeURIComponent(id)) + '">Link</a>');
                }
                if (canManage && !isFolder) {
                    actions.push('<button type="button" class="metis-btn-xs metis-btn-ghost metis-board-drive-preview-file" data-link="' + escHtml(link) + '" data-name="' + escHtml(name) + '">View</button>');
                    actions.push(
                        '<button type="button" class="metis-btn-xs metis-board-drive-link-file"' +
                        ' data-id="' + escHtml(id) + '"' +
                        ' data-name="' + escHtml(name) + '"' +
                        ' data-mime="' + escHtml(mime) + '"' +
                        ' data-link="' + escHtml(link) + '"' +
                        ' data-size="' + escHtml(String(file.size || '')) + '"' +
                        '>Link</button>'
                    );
                    actions.push('<button type="button" class="metis-btn-xs metis-btn-danger metis-board-drive-delete-file" data-id="' + escHtml(id) + '" data-name="' + escHtml(name) + '">Delete</button>');
                }
                return '' +
                    '<tr class="metis-premium-row metis-board-drive-row' + (isFolder ? ' metis-board-drive-row-folder' : '') + '" data-folder-id="' + (isFolder ? escHtml(id) : '') + '">' +
                        '<td class="metis-premium-cell"><strong>' + escHtml(name) + '</strong></td>' +
                        '<td class="metis-premium-cell">' + escHtml(isFolder ? 'Folder' : (mime || 'File')) + '</td>' +
                        '<td class="metis-premium-cell">' + fmtDate(file.modifiedTime) + '</td>' +
                        '<td class="metis-premium-cell">' + (isFolder ? '—' : fmtBytes(file.size)) + '</td>' +
                        '<td class="metis-premium-cell"><div class="metis-board-drive-actions">' + actions.join(' ') + '</div></td>' +
                    '</tr>';
            }).join('');
        }

        function loadDrive() {
            if (drivePath) drivePath.textContent = 'loading...';
            post('metis_board_drive_list', {
                meeting_id: meetingId,
                folder_id: folderId,
                search: driveSearch ? driveSearch.value : ''
            }).then(function (data) {
                folderId = String((data && data.folder_id) || folderId || 'root');
                folderName = String((data && data.folder_name) || '').trim();
                parentId = String((data && data.parent_id) || '');
                if (driveUp) driveUp.disabled = !parentId;
                if (drivePath) {
                    const folderPath = String((data && data.folder_path) || '').trim();
                    drivePath.textContent = folderPath !== '' ? folderPath : (folderName !== '' ? folderName : 'Current meeting folder');
                }
                renderDriveRows((data && data.files) || []);
            }).catch(function (err) {
                showAlert(err.message || 'Failed to load Drive folder.', 'error');
            });
        }
        function ensureDriveLoaded(force) {
            if (!force && driveLoaded) return;
            driveLoaded = true;
            loadDrive();
        }
        if (detailWrap) {
            detailWrap.__metisBoardLoadDrive = function () { ensureDriveLoaded(true); };
            detailWrap.__metisBoardSetFolder = function (newFolderId, force) {
                const normalized = String(newFolderId || '').trim();
                if (normalized === '') return;
                folderId = normalized;
                if (force) ensureDriveLoaded(true);
            };
            detailWrap.addEventListener('metis:board-tab-changed', function (event) {
                const tab = String(event && event.detail ? event.detail.tab : '');
                if (tab === 'packet') ensureDriveLoaded(false);
            });
        }

        driveRows?.addEventListener('click', function (event) {
            const folderRow = event.target.closest('.metis-board-drive-row-folder');
            const clickedAction = event.target.closest('.metis-board-drive-actions');
            if (folderRow && !clickedAction) {
                const targetFolderId = String(folderRow.dataset.folderId || '').trim();
                if (targetFolderId !== '') {
                    folderId = targetFolderId;
                    ensureDriveLoaded(true);
                }
                return;
            }
            const previewBtn = event.target.closest('.metis-board-drive-preview-file');
            if (previewBtn) {
                openPreviewModal(String(previewBtn.dataset.link || ''));
                return;
            }
            const deleteBtn = event.target.closest('.metis-board-drive-delete-file');
            if (deleteBtn) {
                const fileId = String(deleteBtn.dataset.id || '').trim();
                const fileName = String(deleteBtn.dataset.name || 'file');
                if (fileId === '') return;
                confirmAction('Delete "' + fileName + '"?', {
                    title: 'Delete File',
                    confirmLabel: 'Delete',
                    tone: 'danger'
                }).then(function (confirmed) {
                    if (!confirmed) return;
                    post('metis_board_drive_delete_file', { meeting_id: meetingId, file_id: fileId }).then(function (data) {
                        showAlert('File deleted.', 'success');
                        renderLinkedDocuments((data && data.documents) || []);
                        refreshPacketDocumentDropdowns((data && data.documents) || []);
                        ensureDriveLoaded(true);
                    }).catch(function (err) {
                        showAlert(err.message || 'Failed to delete file.', 'error');
                    });
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
                if (docMetaFileWrap) docMetaFileWrap.hidden = true;
                if (docMetaFile) docMetaFile.value = '';
                if (docMetaTitle) docMetaTitle.value = pendingDocAction.title || 'Packet Document';
                if (docMetaType) docMetaType.value = 'supporting_doc';
                if (docMetaSubmit) docMetaSubmit.textContent = 'Save Link';
                openModal(docMetaModal);
            }
        });

        driveSearch?.addEventListener('input', function () {
            if (searchTimer) window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () { ensureDriveLoaded(true); }, 260);
        });
        driveRefresh?.addEventListener('click', function () { ensureDriveLoaded(true); });
        driveUp?.addEventListener('click', function () {
            if (!parentId) return;
            folderId = parentId;
            ensureDriveLoaded(true);
        });
        driveUpload?.addEventListener('click', function () {
            driveUploadInput?.click();
        });
        driveUploadInput?.addEventListener('change', function () {
            const file = driveUploadInput.files && driveUploadInput.files[0] ? driveUploadInput.files[0] : null;
            if (!file) return;
            openPacketUploadModal(file);
        });

        linkedDocRows?.addEventListener('click', function (event) {
            const previewBtn = event.target.closest('.metis-board-preview-linked-doc');
            if (previewBtn) {
                openPreviewModal(String(previewBtn.dataset.link || ''));
                return;
            }
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
                    refreshPacketDocumentDropdowns((data && data.documents) || []);
                    ensureDriveLoaded(true);
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to link file.', 'error');
                });
                return;
            }

            const uploadFile = docMetaFile && docMetaFile.files && docMetaFile.files[0] ? docMetaFile.files[0] : null;
            if (!uploadFile) {
                showAlert('Select a document file first.', 'error');
                return;
            }
            const fd = new FormData();
            fd.set('meeting_id', meetingId);
            fd.set('folder_id', folderId);
            fd.set('doc_type', docType);
            fd.set('item_title', itemTitle);
            fd.set('file', uploadFile);
            postForm('metis_board_drive_upload', fd).then(function (data) {
                closeModal(docMetaModal);
                pendingDocAction = null;
                if (driveUploadInput) driveUploadInput.value = '';
                showAlert('File uploaded and linked.', 'success');
                renderLinkedDocuments((data && data.documents) || []);
                refreshPacketDocumentDropdowns((data && data.documents) || []);
                ensureDriveLoaded(true);
            }).catch(function (err) {
                showAlert(err.message || 'Failed to upload file.', 'error');
            });
        });
        docMetaModal?.querySelectorAll('.metis-board-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                pendingDocAction = null;
                if (docMetaFileWrap) docMetaFileWrap.hidden = false;
                if (docMetaFile) docMetaFile.value = '';
                if (driveUploadInput) driveUploadInput.value = '';
                if (docMetaSubmit) docMetaSubmit.textContent = 'Save Document';
            });
        });
        previewModal?.querySelectorAll('.metis-board-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (previewFrame) previewFrame.src = 'about:blank';
                if (previewOpen) previewOpen.href = '#';
                if (previewDownload) previewDownload.href = '#';
            });
        });

        refreshLinkedDocuments().catch(function () {});
        if (detailWrap && detailWrap.querySelector('.metis-board-tab.metis-board-tab-active[data-tab="packet"]')) {
            ensureDriveLoaded(false);
        }
    }
});
