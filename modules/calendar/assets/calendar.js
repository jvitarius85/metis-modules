function initMetisCalendar(context) {
    const scope = context && context.root ? context.root : document;
    const root = scope.querySelector('.metis-calendar');
    if (!root) return;
    if (root.getAttribute('data-metis-calendar-initialized') === '1') return;
    root.setAttribute('data-metis-calendar-initialized', '1');

    let ajax = null;
    try {
        ajax = (window.Metis && Metis.request && typeof Metis.request.config === 'function')
            ? Metis.request.config(window.metisCalendarAjax || null, 'Calendar AJAX not configured.')
            : (window.metisCalendarAjax || window.metisAjax || null);
    } catch (_error) {
        return;
    }

    if (!ajax || !ajax.ajax_url || !ajax.nonce) return;

    const canManage = String(root.dataset.canManage || '0') === '1';
    const searchEl = document.getElementById('metis-calendar-search');
    const refreshBtn = document.getElementById('metis-calendar-refresh');
    const todayBtn = document.getElementById('metis-calendar-today');
    const prevBtn = document.getElementById('metis-calendar-prev');
    const nextBtn = document.getElementById('metis-calendar-next');
    const newBtn = document.getElementById('metis-calendar-new');
    const countEl = document.getElementById('metis-calendar-count');
    const upcomingEl = document.getElementById('metis-calendar-upcoming');
    const monthLabelEl = document.getElementById('metis-calendar-month-label');
    const calendarNameEl = document.getElementById('metis-calendar-name');
    const calendarIdEl = document.getElementById('metis-calendar-id');
    const gridEl = document.getElementById('metis-calendar-grid');
    const timeViewEl = document.getElementById('metis-calendar-timeview');
    const selectedLabelEl = document.getElementById('metis-calendar-selected-label');
    const selectedCountEl = document.getElementById('metis-calendar-selected-count');
    const selectedEventsEl = document.getElementById('metis-calendar-selected-events');
    const viewButtons = {
        month: document.getElementById('metis-calendar-view-month'),
        week: document.getElementById('metis-calendar-view-week'),
        day: document.getElementById('metis-calendar-view-day')
    };

    const modal = document.getElementById('metis-calendar-event-modal');
    const modalTitle = document.getElementById('metis-calendar-event-title');
    const form = document.getElementById('metis-calendar-event-form');
    const deleteBtn = document.getElementById('metis-calendar-delete');
    const idEl = document.getElementById('metis-calendar-event-id');
    const summaryEl = document.getElementById('metis-calendar-event-summary');
    const startDtEl = document.getElementById('metis-calendar-event-start');
    const endDtEl = document.getElementById('metis-calendar-event-end');
    const typeEl = document.getElementById('metis-calendar-event-type');
    const moduleEl = document.getElementById('metis-calendar-event-module');
    const locationEl = document.getElementById('metis-calendar-event-location');
    const descriptionEl = document.getElementById('metis-calendar-event-description');
    const calendarSelectEl = document.getElementById('metis-calendar-event-calendar');
    const filtersEl = document.getElementById('metis-calendar-filters');
    const detailModal = document.getElementById('metis-calendar-detail-modal');
    const detailTitleEl = document.getElementById('metis-calendar-detail-title');
    const detailBadgeEl = document.getElementById('metis-calendar-detail-badge');
    const detailWhenEl = document.getElementById('metis-calendar-detail-when');
    const detailCalendarEl = document.getElementById('metis-calendar-detail-calendar');
    const detailLocationEl = document.getElementById('metis-calendar-detail-location');
    const detailDescriptionEl = document.getElementById('metis-calendar-detail-description');
    const detailOpenEl = document.getElementById('metis-calendar-detail-open');
    const detailEditBtn = document.getElementById('metis-calendar-detail-edit');

    const DAY_MS = 24 * 60 * 60 * 1000;
    const SLOT_MINUTES = 30;
    const SLOT_HEIGHT = 28;
    const START_HOUR = 7;
    const END_HOUR = 21;
    const today = new Date();
    const cutoff = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 60);

    let searchTimer = null;
    let currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    let selectedDay = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    let currentView = 'month';
    let loadedItems = [];
    let dragState = null;
    let availableCalendars = [];
    let visibleCalendarIds = [];
    let activeDetailItem = null;

    try {
        availableCalendars = JSON.parse(root.dataset.calendars || '[]');
    } catch (err) {
        availableCalendars = [];
    }
    visibleCalendarIds = availableCalendars.map(function (row) {
        return String((row && row.calendar_id) || '');
    }).filter(Boolean);

    const palette = {
        general: '#4d5fcf',
        board: '#2667a8',
        calendar: '#7b58c8',
        contacts: '#0f8b74',
        newsletter: '#bf5b32',
        people: '#6e6fb3',
        portal: '#404d73',
        meeting: '#3159c7',
        deadline: '#d0534f',
        task: '#9b7a27',
        public: '#1b8b62'
    };

    const escHtml = Metis.util.escapeHtml;

    const showAlert = Metis.util.notify;

    function renderLoadWarnings(data) {
        const errors = Array.isArray(data && data.errors) ? data.errors : [];
        if (!errors.length) return;
        const summary = String((data && data.error_summary) || '').trim();
        if (summary) {
            showAlert(summary, 'error');
            return;
        }
        const first = errors[0] || {};
        const label = String(first.calendar_label || 'Calendar');
        const message = String(first.error || 'Failed to load events.');
        showAlert(label + ': ' + message, 'error');
    }

    function post(action, payload) {
        const formData = new FormData();
        formData.set('action', action);
        formData.set('metis_action_nonce', Metis.ajax.nonceFor(action, ajax.nonce));
        formData.set('nonce', ajax.nonce);
        Object.keys(payload || {}).forEach(function (key) {
            const value = payload[key];
            if (Array.isArray(value)) {
                value.forEach(function (entry) {
                    formData.append(key + '[]', entry == null ? '' : String(entry));
                });
                return;
            }
            formData.set(key, value == null ? '' : String(value));
        });
        return fetch(ajax.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).then(function (res) {
            return Metis.ajax.parseJson(res);
        }).then(function (json) {
            if (!json || !json.success) throw new Error(Metis.ajax.message(json));
            return json.data;
        });
    }

    function triggerSync(forceSync) {
        const formData = new FormData();
        formData.set('action', 'metis_calendar_sync_worker');
        formData.set('metis_action_nonce', Metis.ajax.nonceFor('metis_calendar_sync_worker', ajax.nonce));
        formData.set('nonce', ajax.nonce);
        visibleCalendarIds.forEach(function (id) {
            formData.append('calendar_ids[]', id);
        });
        if (forceSync) {
            formData.set('force', '1');
        }

        fetch(ajax.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        }).catch(function () {
            // Best-effort background sync only.
        });
    }

    function openModal() {
        Metis.modal.open(modal);
    }

    function closeModal() {
        Metis.modal.close(modal);
    }

    function openDetailModal() {
        if (!detailModal) return;
        Metis.modal.open(detailModal);
    }

    function closeDetailModal() {
        if (!detailModal) return;
        Metis.modal.close(detailModal);
    }

    function activeCalendarId() {
        if (calendarSelectEl && calendarSelectEl.value) {
            return String(calendarSelectEl.value);
        }
        return visibleCalendarIds[0] || '';
    }

    function renderCalendarFilters() {
        if (!filtersEl || !availableCalendars.length) return;
        filtersEl.innerHTML = availableCalendars.map(function (row) {
            const calendarId = String((row && row.calendar_id) || '');
            const label = String((row && (row.calendar_label || row.calendar_name || row.calendar_id)) || 'Calendar');
            const isActive = visibleCalendarIds.includes(calendarId);
            return '<button type="button" class="metis-calendar-filter-chip' + (isActive ? ' is-active' : '') + '" data-calendar-id="' + escHtml(calendarId) + '" aria-pressed="' + (isActive ? 'true' : 'false') + '" title="' + escHtml(String((row && (row.calendar_name || row.calendar_label || row.calendar_id)) || label)) + '">' +
                escHtml(label) +
            '</button>';
        }).join('');

        filtersEl.querySelectorAll('.metis-calendar-filter-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                const calendarId = String(chip.dataset.calendarId || '');
                if (!calendarId) return;
                if (visibleCalendarIds.includes(calendarId)) {
                    if (visibleCalendarIds.length === 1) return;
                    visibleCalendarIds = visibleCalendarIds.filter(function (id) { return id !== calendarId; });
                } else {
                    visibleCalendarIds = visibleCalendarIds.concat([calendarId]);
                }
                renderCalendarFilters();
                load();
            });
        });
    }

    function eventStart(item) {
        const s = item && item.start ? (item.start.dateTime || item.start.date) : '';
        return String(s || '');
    }

    function eventEnd(item) {
        const e = item && item.end ? (item.end.dateTime || item.end.date) : '';
        return String(e || '');
    }

    function eventStartDate(item) {
        const raw = eventStart(item);
        if (!raw) return null;
        const date = new Date(raw);
        return isNaN(date.getTime()) ? null : date;
    }

    function eventEndDate(item) {
        const raw = eventEnd(item);
        if (!raw) return null;
        const date = new Date(raw);
        return isNaN(date.getTime()) ? null : date;
    }

    function toDayKey(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function sameMonth(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth();
    }

    function sameDay(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function monthRange(date) {
        return {
            start: new Date(date.getFullYear(), date.getMonth(), 1),
            end: new Date(date.getFullYear(), date.getMonth() + 1, 0)
        };
    }

    function weekRange(date) {
        const start = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        start.setDate(start.getDate() - start.getDay());
        const end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 6);
        return { start: start, end: end };
    }

    function gridStart(date) {
        const range = monthRange(date);
        const start = new Date(range.start);
        start.setDate(start.getDate() - start.getDay());
        return start;
    }

    function clampToCutoff(date) {
        if (date.getTime() < cutoff.getTime()) {
            return new Date(cutoff.getFullYear(), cutoff.getMonth(), cutoff.getDate());
        }
        return date;
    }

    function updateNavState() {
        const cutoffMonth = new Date(cutoff.getFullYear(), cutoff.getMonth(), 1);
        if (prevBtn) prevBtn.disabled = currentView === 'month' && currentMonth.getTime() <= cutoffMonth.getTime();
    }

    function toInputDateTime(v) {
        const d = new Date(String(v || ''));
        if (isNaN(d.getTime())) return '';
        const z = function (n) { return String(n).padStart(2, '0'); };
        return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) + 'T' + z(d.getHours()) + ':' + z(d.getMinutes());
    }

    function formatMonthLabel() {
        if (currentView === 'month') {
            return currentMonth.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        }
        if (currentView === 'week') {
            const range = weekRange(selectedDay);
            const a = range.start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            const b = range.end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
            return a + ' - ' + b;
        }
        return selectedDay.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    }

    function setView(nextView) {
        currentView = nextView;
        Object.keys(viewButtons).forEach(function (key) {
            viewButtons[key]?.classList.toggle('is-active', key === nextView);
            if (viewButtons[key]) {
                viewButtons[key].setAttribute('aria-selected', key === nextView ? 'true' : 'false');
                viewButtons[key].setAttribute('tabindex', key === nextView ? '0' : '-1');
            }
        });
        render();
    }

    function itemColor(item) {
        const moduleKey = String(item.metis_module || 'general');
        const typeKey = String(item.metis_type || 'general');
        return palette[moduleKey] || palette[typeKey] || palette.general;
    }

    function itemBadge(item) {
        const moduleKey = String(item.metis_module || 'general');
        const typeKey = String(item.metis_type || 'general');
        return moduleKey !== 'general' ? moduleKey : typeKey;
    }

    function itemsForDay(date) {
        const key = toDayKey(date);
        return loadedItems.filter(function (item) {
            const start = eventStartDate(item);
            return start && toDayKey(start) === key;
        });
    }

    function updateStats() {
        if (countEl) countEl.textContent = String(loadedItems.length);
        const now = Date.now();
        const seven = now + (7 * DAY_MS);
        const upcoming = loadedItems.filter(function (it) {
            const start = eventStartDate(it);
            if (!start) return false;
            const ts = start.getTime();
            return ts >= now && ts <= seven;
        }).length;
        if (upcomingEl) upcomingEl.textContent = String(upcoming);
    }

    function normalizeSelectedDay() {
        selectedDay = clampToCutoff(selectedDay);
        currentMonth = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), 1);
    }

    function fmtWhen(item) {
        const sd = eventStartDate(item);
        const ed = eventEndDate(item);
        if (!sd) return '—';
        const timeOptions = { hour: 'numeric', minute: '2-digit' };
        const dateOptions = { month: 'short', day: 'numeric', year: 'numeric' };
        const same = ed && sameDay(sd, ed);
        if (!ed || !same) {
            const sTxt = sd.toLocaleString();
            const eTxt = ed ? ed.toLocaleString() : '';
            return eTxt ? (sTxt + ' - ' + eTxt) : sTxt;
        }
        return sd.toLocaleDateString(undefined, dateOptions) + ' · ' + sd.toLocaleTimeString(undefined, timeOptions) + ' - ' + ed.toLocaleTimeString(undefined, timeOptions);
    }

    function calendarLabel(item) {
        const itemCalendarId = String((item && item.calendar_id) || '');
        const matched = availableCalendars.find(function (row) {
            return String((row && row.calendar_id) || '') === itemCalendarId;
        });
        return String(
            (item && (item.calendar_label || item.calendar_name)) ||
            (matched && (matched.calendar_label || matched.calendar_name || matched.calendar_id)) ||
            itemCalendarId ||
            'Calendar'
        );
    }

    function populateDetailModal(item) {
        activeDetailItem = item || null;
        if (!activeDetailItem) return;
        const color = itemColor(activeDetailItem);
        if (detailTitleEl) detailTitleEl.textContent = String(activeDetailItem.summary || '(untitled)');
        if (detailBadgeEl) {
            detailBadgeEl.textContent = itemBadge(activeDetailItem);
            detailBadgeEl.style.borderColor = color;
            detailBadgeEl.style.color = color;
        }
        if (detailWhenEl) detailWhenEl.textContent = fmtWhen(activeDetailItem);
        if (detailCalendarEl) detailCalendarEl.textContent = calendarLabel(activeDetailItem);
        if (detailLocationEl) detailLocationEl.textContent = String(activeDetailItem.location || 'No location');
        if (detailDescriptionEl) detailDescriptionEl.textContent = String(activeDetailItem.description || 'No description');
        if (detailOpenEl) {
            if (activeDetailItem.htmlLink) {
                detailOpenEl.href = String(activeDetailItem.htmlLink);
                detailOpenEl.style.display = '';
            } else {
                detailOpenEl.href = '#';
                detailOpenEl.style.display = 'none';
            }
        }
    }

    function openEventDetails(item) {
        if (!item) return;
        populateDetailModal(item);
        openDetailModal();
    }

    function populateModal(item) {
        const it = item || {};
        if (idEl) idEl.value = String(it.id || '');
        if (summaryEl) summaryEl.value = String(it.summary || '');
        if (startDtEl) startDtEl.value = toInputDateTime(eventStart(it));
        if (endDtEl) endDtEl.value = toInputDateTime(eventEnd(it));
        if (typeEl) typeEl.value = String(it.metis_type || 'general');
        if (moduleEl) moduleEl.value = String(it.metis_module || 'general');
        if (locationEl) locationEl.value = String(it.location || '');
        if (descriptionEl) descriptionEl.value = String(it.description || '');
        if (calendarSelectEl) {
            calendarSelectEl.value = String(it.calendar_id || activeCalendarId() || calendarSelectEl.value || '');
        }
        if (modalTitle) modalTitle.textContent = it.id ? 'Edit Event' : 'New Event';
        if (deleteBtn) deleteBtn.style.display = it.id ? '' : 'none';
    }

    function renderSelectedDay() {
        const items = itemsForDay(selectedDay).sort(function (a, b) {
            return (eventStartDate(a)?.getTime() || 0) - (eventStartDate(b)?.getTime() || 0);
        });

        if (selectedLabelEl) {
            selectedLabelEl.textContent = selectedDay.toLocaleDateString(undefined, {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });
        }
        if (selectedCountEl) selectedCountEl.textContent = items.length + ' event' + (items.length === 1 ? '' : 's');
        if (!selectedEventsEl) return;

        if (!items.length) {
            selectedEventsEl.innerHTML = '<div class="metis-calendar-empty">No events for this day.</div>';
            return;
        }

        selectedEventsEl.innerHTML = items.map(function (it) {
            const color = itemColor(it);
            const actions = [];
            if (it.htmlLink) actions.push('<a class="mw-btn-xs mw-btn-ghost" href="' + escHtml(it.htmlLink) + '" target="_blank" rel="noopener">Open</a>');
            if (canManage) actions.push('<button type="button" class="mw-btn-xs metis-calendar-edit" data-id="' + escHtml(it.id || '') + '">Edit</button>');
            return '<article class="metis-calendar-event-card" data-event-id="' + escHtml(it.id || '') + '" role="button" tabindex="0" aria-label="View details for ' + escHtml(it.summary || '(untitled)') + '" style="border-left:4px solid ' + escHtml(color) + ';">' +
                '<div class="metis-calendar-event-card-top">' +
                    '<h4>' + escHtml(it.summary || '(untitled)') + '</h4>' +
                    '<span class="mw-chip" style="border-color:' + escHtml(color) + ';color:' + escHtml(color) + ';">' + escHtml(itemBadge(it)) + '</span>' +
                '</div>' +
                '<div class="metis-calendar-event-meta">' + escHtml(fmtWhen(it)) + '</div>' +
                (it.location ? '<div class="metis-calendar-event-meta">' + escHtml(it.location) + '</div>' : '') +
                '<div class="metis-calendar-actions">' + actions.join('') + '</div>' +
            '</article>';
        }).join('');

        selectedEventsEl.querySelectorAll('[data-event-id]').forEach(function (card) {
            function activateCard(event) {
                const editBtn = event.target.closest('.metis-calendar-edit');
                const eventObj = loadedItems.find(function (item) { return String(item.id || '') === String(card.dataset.eventId || ''); }) || null;
                if (!eventObj) return;
                if (editBtn && canManage) {
                    populateModal(eventObj);
                    closeDetailModal();
                    openModal();
                    return;
                }
                if (event.target.closest('a')) return;
                openEventDetails(eventObj);
            }

            card.addEventListener('click', activateCard);
            card.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                activateCard(event);
            });
        });
    }

    function buildMonthCell(day) {
        const items = itemsForDay(day).sort(function (a, b) {
            return (eventStartDate(a)?.getTime() || 0) - (eventStartDate(b)?.getTime() || 0);
        });
        const classes = ['metis-calendar-cell'];
        if (!sameMonth(day, currentMonth)) classes.push('is-outside-month');
        if (sameDay(day, today)) classes.push('is-today');
        if (sameDay(day, selectedDay)) classes.push('is-selected');

        const preview = items.slice(0, 3).map(function (item) {
            const start = eventStartDate(item);
            const time = start ? start.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '';
            return '<button type="button" class="metis-calendar-pill" data-event-id="' + escHtml(item.id || '') + '" style="border-left:3px solid ' + escHtml(itemColor(item)) + ';">' +
                '<span class="metis-calendar-pill-time">' + escHtml(time) + '</span>' +
                '<span class="metis-calendar-pill-text">' + escHtml(item.summary || '(untitled)') + '</span>' +
            '</button>';
        }).join('');

        const overflow = items.length > 3 ? ('<div class="metis-calendar-more">+' + (items.length - 3) + ' more</div>') : '';
        return '<div class="' + classes.join(' ') + '" data-day="' + escHtml(toDayKey(day)) + '" role="gridcell" tabindex="0" aria-selected="' + (sameDay(day, selectedDay) ? 'true' : 'false') + '" aria-label="View events for ' + escHtml(day.toLocaleDateString()) + '">' +
            '<div class="metis-calendar-cell-top"><span class="metis-calendar-day-number">' + day.getDate() + '</span><span class="metis-calendar-day-count">' + (items.length ? (items.length + ' evt') : '') + '</span></div>' +
            '<div class="metis-calendar-cell-events">' + preview + overflow + '</div>' +
        '</div>';
    }

    function attachMonthEvents() {
        gridEl.querySelectorAll('.metis-calendar-cell').forEach(function (cell) {
            function activateCell(event) {
                const dayKey = String(cell.dataset.day || '');
                if (!dayKey) return;
                if (event.target.closest('.metis-calendar-pill')) {
                    const pill = event.target.closest('.metis-calendar-pill');
                    const eventObj = loadedItems.find(function (item) { return String(item.id || '') === String(pill.dataset.eventId || ''); }) || null;
                    if (!eventObj) return;
                    openEventDetails(eventObj);
                    return;
                }
                const parts = dayKey.split('-').map(function (part) { return parseInt(part, 10); });
                selectedDay = new Date(parts[0], parts[1] - 1, parts[2]);
                render();
            }

            cell.addEventListener('click', function (event) {
                activateCell(event);
            });
            cell.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                activateCell(event);
            });
            if (canManage) {
                cell.addEventListener('mousedown', function (event) {
                    if (event.target.closest('.metis-calendar-pill')) {
                        dragState = null;
                        return;
                    }
                    const parts = String(cell.dataset.day || '').split('-').map(function (part) { return parseInt(part, 10); });
                    if (parts.length !== 3) return;
                    dragState = {
                        mode: 'create-month',
                        start: new Date(parts[0], parts[1] - 1, parts[2], 9, 0, 0),
                        end: new Date(parts[0], parts[1] - 1, parts[2], 10, 0, 0)
                    };
                });
            }
        });
        if (canManage) {
            gridEl.addEventListener('mouseup', function () {
                if (!dragState || dragState.mode !== 'create-month') return;
                populateModal({
                    start: { dateTime: dragState.start.toISOString() },
                    end: { dateTime: dragState.end.toISOString() },
                    metis_type: 'general',
                    metis_module: 'calendar'
                });
                dragState = null;
                openModal();
            }, { once: true });
        }
    }

    function minuteOffset(date) {
        return ((date.getHours() - START_HOUR) * 60) + date.getMinutes();
    }

    function slotFromY(rect, clientY) {
        const y = Math.max(0, clientY - rect.top);
        const minutes = Math.round(y / SLOT_HEIGHT) * SLOT_MINUTES;
        const total = Math.max(0, Math.min(((END_HOUR - START_HOUR) * 60) - SLOT_MINUTES, minutes));
        return total;
    }

    function dateFromSlot(day, minutes) {
        const hours = START_HOUR + Math.floor(minutes / 60);
        const mins = minutes % 60;
        return new Date(day.getFullYear(), day.getMonth(), day.getDate(), hours, mins, 0);
    }

    function renderTimeView() {
        if (!timeViewEl) return;
        const days = [];
        if (currentView === 'week') {
            const range = weekRange(selectedDay);
            for (let i = 0; i < 7; i += 1) {
                days.push(new Date(range.start.getFullYear(), range.start.getMonth(), range.start.getDate() + i));
            }
        } else {
            days.push(new Date(selectedDay.getFullYear(), selectedDay.getMonth(), selectedDay.getDate()));
        }

        const slots = [];
        for (let hour = START_HOUR; hour < END_HOUR; hour += 1) {
            slots.push('<div class="metis-calendar-hour-label">' + new Date(2000, 0, 1, hour).toLocaleTimeString(undefined, { hour: 'numeric' }) + '</div>');
        }

        timeViewEl.innerHTML =
            '<div class="metis-calendar-time-shell">' +
                '<div class="metis-calendar-time-hours">' + slots.join('') + '</div>' +
                '<div class="metis-calendar-time-columns columns-' + days.length + '">' +
                    days.map(function (day) {
                        const dayItems = itemsForDay(day);
                        const allSlots = [];
                        for (let slot = 0; slot < ((END_HOUR - START_HOUR) * 60) / SLOT_MINUTES; slot += 1) {
                            allSlots.push('<div class="metis-calendar-slot" data-day="' + escHtml(toDayKey(day)) + '" data-slot="' + slot + '"></div>');
                        }
                        const events = dayItems.map(function (item) {
                            const start = eventStartDate(item);
                            const end = eventEndDate(item) || new Date(start.getTime() + (60 * 60 * 1000));
                            const top = Math.max(0, minuteOffset(start)) / SLOT_MINUTES * SLOT_HEIGHT;
                            const height = Math.max(SLOT_HEIGHT, ((Math.max(30, (end.getTime() - start.getTime()) / 60000)) / SLOT_MINUTES) * SLOT_HEIGHT);
                            const color = itemColor(item);
                            return '<div class="metis-calendar-time-event" data-event-id="' + escHtml(item.id || '') + '" style="top:' + top + 'px;height:' + height + 'px;background:' + escHtml(color) + ';">' +
                                '<div class="metis-calendar-time-event-title">' + escHtml(item.summary || '(untitled)') + '</div>' +
                                '<div class="metis-calendar-time-event-meta">' + escHtml(itemBadge(item)) + '</div>' +
                                '<div class="metis-calendar-resize-handle" data-event-id="' + escHtml(item.id || '') + '"></div>' +
                            '</div>';
                        }).join('');
                        return '<div class="metis-calendar-time-column" data-day="' + escHtml(toDayKey(day)) + '">' +
                            '<div class="metis-calendar-time-column-header">' + escHtml(day.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })) + '</div>' +
                            '<div class="metis-calendar-time-column-body">' + allSlots.join('') + events + '</div>' +
                        '</div>';
                    }).join('') +
                '</div>' +
            '</div>';

        timeViewEl.querySelectorAll('.metis-calendar-time-event').forEach(function (el) {
            el.addEventListener('click', function (event) {
                if (event.target.closest('.metis-calendar-resize-handle')) return;
                const eventObj = loadedItems.find(function (item) { return String(item.id || '') === String(el.dataset.eventId || ''); }) || null;
                if (!eventObj) return;
                openEventDetails(eventObj);
            });
        });

        if (!canManage) return;

        timeViewEl.querySelectorAll('.metis-calendar-time-column-body').forEach(function (body) {
            body.addEventListener('mousedown', function (event) {
                if (event.target.closest('.metis-calendar-time-event')) return;
                const dayKey = String(body.parentElement.dataset.day || '');
                const parts = dayKey.split('-').map(function (part) { return parseInt(part, 10); });
                const day = new Date(parts[0], parts[1] - 1, parts[2]);
                const rect = body.getBoundingClientRect();
                const slotMinutes = slotFromY(rect, event.clientY);
                dragState = { mode: 'create-time', body: body, day: day, startMinutes: slotMinutes };
            });
        });

        timeViewEl.querySelectorAll('.metis-calendar-resize-handle').forEach(function (handle) {
            handle.addEventListener('mousedown', function (event) {
                event.stopPropagation();
                const eventObj = loadedItems.find(function (item) { return String(item.id || '') === String(handle.dataset.eventId || ''); }) || null;
                const parent = handle.closest('.metis-calendar-time-event');
                const body = handle.closest('.metis-calendar-time-column-body');
                if (!eventObj || !parent || !body) return;
                dragState = { mode: 'resize', eventObj: eventObj, body: body };
            });
        });
    }

    document.addEventListener('mousemove', function (event) {
        if (!dragState) return;
        if (dragState.mode === 'create-time') {
            const rect = dragState.body.getBoundingClientRect();
            dragState.endMinutes = slotFromY(rect, event.clientY) + SLOT_MINUTES;
        }
        if (dragState.mode === 'resize') {
            const rect = dragState.body.getBoundingClientRect();
            dragState.endMinutes = slotFromY(rect, event.clientY) + SLOT_MINUTES;
        }
    });

    document.addEventListener('mouseup', function () {
        if (!dragState) return;

        if (dragState.mode === 'create-time') {
            const startMinutes = Math.min(dragState.startMinutes, dragState.endMinutes || dragState.startMinutes);
            const endMinutes = Math.max(dragState.startMinutes + SLOT_MINUTES, dragState.endMinutes || (dragState.startMinutes + SLOT_MINUTES));
            populateModal({
                start: { dateTime: dateFromSlot(dragState.day, startMinutes).toISOString() },
                end: { dateTime: dateFromSlot(dragState.day, endMinutes).toISOString() },
                metis_type: 'general',
                metis_module: 'calendar'
            });
            openModal();
        } else if (dragState.mode === 'resize' && dragState.eventObj) {
            const start = eventStartDate(dragState.eventObj);
            if (start) {
                const minutes = Math.max(minuteOffset(start) + SLOT_MINUTES, dragState.endMinutes || (minuteOffset(start) + SLOT_MINUTES));
                post('metis_calendar_save_event', {
                    event_id: dragState.eventObj.id || '',
                    calendar_id: dragState.eventObj.calendar_id || activeCalendarId(),
                    summary: dragState.eventObj.summary || '',
                    start_dt: toInputDateTime(eventStart(dragState.eventObj)),
                    end_dt: toInputDateTime(dateFromSlot(start, minutes).toISOString()),
                    location: dragState.eventObj.location || '',
                    description: dragState.eventObj.description || '',
                    event_type: dragState.eventObj.metis_type || 'general',
                    event_module: dragState.eventObj.metis_module || 'general'
                }).then(function () {
                    showAlert('Event updated.', 'success');
                    triggerSync(true);
                    load(false);
                }).catch(function (err) {
                    showAlert(err.message || 'Failed to resize event.', 'error');
                });
            }
        }

        dragState = null;
    });

    function renderMonthView() {
        const start = gridStart(currentMonth);
        const cells = [];
        for (let index = 0; index < 42; index += 1) {
            const day = new Date(start.getFullYear(), start.getMonth(), start.getDate() + index);
            cells.push(buildMonthCell(day));
        }
        gridEl.innerHTML = cells.join('');
        attachMonthEvents();
    }

    function render() {
        normalizeSelectedDay();
        if (monthLabelEl) monthLabelEl.textContent = formatMonthLabel();
        updateNavState();
        renderSelectedDay();
        if (currentView === 'month') {
            gridEl.style.display = '';
            timeViewEl.style.display = 'none';
            renderMonthView();
            return;
        }
        gridEl.style.display = 'none';
        timeViewEl.style.display = '';
        renderTimeView();
    }

    function load(forceSync) {
        const range = currentView === 'month' ? monthRange(currentMonth) : (currentView === 'week' ? weekRange(selectedDay) : { start: selectedDay, end: selectedDay });
        const start = new Date(Math.max(new Date(range.start.getFullYear(), range.start.getMonth(), range.start.getDate()).getTime(), cutoff.getTime()));
        let end = new Date(range.end.getFullYear(), range.end.getMonth(), range.end.getDate(), 23, 59, 59);
        if (end.getTime() < start.getTime()) {
            end = new Date(start.getFullYear(), start.getMonth(), start.getDate(), 23, 59, 59);
        }

        root.classList.add('is-loading');
        post('metis_calendar_list_events', {
            search: searchEl ? searchEl.value : '',
            start: toDayKey(start),
            end: toDayKey(end),
            calendar_ids: visibleCalendarIds,
            force: forceSync ? '1' : '0'
        }).then(function (data) {
            loadedItems = Array.isArray(data && data.items) ? data.items : [];
            if (Array.isArray(data && data.calendars) && data.calendars.length) {
                availableCalendars = data.calendars;
                if (!visibleCalendarIds.length) {
                    visibleCalendarIds = availableCalendars.map(function (row) {
                        return String((row && row.calendar_id) || '');
                    }).filter(Boolean);
                } else {
                    visibleCalendarIds = visibleCalendarIds.filter(function (id) {
                        return availableCalendars.some(function (row) {
                            return String((row && row.calendar_id) || '') === id;
                        });
                    });
                    if (!visibleCalendarIds.length) {
                        visibleCalendarIds = availableCalendars.map(function (row) {
                            return String((row && row.calendar_id) || '');
                        }).filter(Boolean);
                    }
                }
                renderCalendarFilters();
            }
            if (calendarNameEl && data && data.calendar_name) calendarNameEl.textContent = data.calendar_name;
            if (calendarIdEl) {
                calendarIdEl.textContent = visibleCalendarIds.length > 1 ? (visibleCalendarIds.length + ' calendars visible') : (data && data.calendar_id ? 'ID: ' + data.calendar_id : '');
            }
            updateStats();
            render();
            renderLoadWarnings(data);
            triggerSync(forceSync);
        }).catch(function (err) {
            const raw = String((err && err.message) || 'Failed to load events.');
            const msg = raw.replace(/\s*\(Calendar ID may be missing.*$/i, '');
            showAlert(msg, 'error');
        }).finally(function () {
            root.classList.remove('is-loading');
        });
    }

    refreshBtn?.addEventListener('click', function () {
        load(true);
    });
    todayBtn?.addEventListener('click', function () {
        currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        selectedDay = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        load(false);
    });
    prevBtn?.addEventListener('click', function () {
        if (currentView === 'month') {
            const cutoffMonth = new Date(cutoff.getFullYear(), cutoff.getMonth(), 1);
            if (currentMonth.getTime() <= cutoffMonth.getTime()) return;
            currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() - 1, 1);
            selectedDay = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), 1);
        } else if (currentView === 'week') {
            selectedDay = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), selectedDay.getDate() - 7);
            currentMonth = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), 1);
        } else {
            selectedDay = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), selectedDay.getDate() - 1);
            currentMonth = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), 1);
        }
        load(false);
    });
    nextBtn?.addEventListener('click', function () {
        if (currentView === 'month') {
            currentMonth = new Date(currentMonth.getFullYear(), currentMonth.getMonth() + 1, 1);
            selectedDay = new Date(currentMonth.getFullYear(), currentMonth.getMonth(), 1);
        } else if (currentView === 'week') {
            selectedDay = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), selectedDay.getDate() + 7);
            currentMonth = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), 1);
        } else {
            selectedDay = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), selectedDay.getDate() + 1);
            currentMonth = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), 1);
        }
        load(false);
    });
    searchEl?.addEventListener('input', function () {
        if (searchTimer) window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
            load(false);
        }, 250);
    });
    Object.keys(viewButtons).forEach(function (key) {
        viewButtons[key]?.addEventListener('click', function () {
            setView(key);
            load(false);
        });
        viewButtons[key]?.addEventListener('keydown', function (event) {
            const order = ['month', 'week', 'day'];
            const currentIndex = order.indexOf(key);
            if (currentIndex < 0) return;
            let nextIndex = -1;
            if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % order.length;
            if (event.key === 'ArrowLeft') nextIndex = (currentIndex + order.length - 1) % order.length;
            if (nextIndex < 0) return;
            event.preventDefault();
            const nextKey = order[nextIndex];
            setView(nextKey);
            load(false);
            viewButtons[nextKey]?.focus();
        });
    });

    if (canManage) {
        document.querySelectorAll('.metis-calendar-cancel').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });
        modal?.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        newBtn?.addEventListener('click', function () {
            const start = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), selectedDay.getDate(), 9, 0, 0);
            const end = new Date(selectedDay.getFullYear(), selectedDay.getMonth(), selectedDay.getDate(), 10, 0, 0);
            populateModal({
                start: { dateTime: start.toISOString() },
                end: { dateTime: end.toISOString() },
                metis_type: 'general',
                metis_module: 'calendar'
            });
            openModal();
        });
        form?.addEventListener('submit', function (event) {
            event.preventDefault();
            post('metis_calendar_save_event', {
                event_id: idEl ? idEl.value : '',
                calendar_id: calendarSelectEl ? calendarSelectEl.value : activeCalendarId(),
                summary: summaryEl ? summaryEl.value : '',
                start_dt: startDtEl ? startDtEl.value : '',
                end_dt: endDtEl ? endDtEl.value : '',
                event_type: typeEl ? typeEl.value : 'general',
                event_module: moduleEl ? moduleEl.value : 'general',
                location: locationEl ? locationEl.value : '',
                description: descriptionEl ? descriptionEl.value : ''
            }).then(function () {
                closeModal();
                showAlert('Event saved.', 'success');
                triggerSync(true);
                load(false);
            }).catch(function (err) {
                showAlert(err.message || 'Failed to save event.', 'error');
            });
        });
        deleteBtn?.addEventListener('click', function () {
            const id = idEl ? idEl.value : '';
            if (!id) return;
            post('metis_calendar_delete_event', {
                event_id: id,
                calendar_id: calendarSelectEl ? calendarSelectEl.value : activeCalendarId()
            }).then(function () {
                closeModal();
                showAlert('Event deleted.', 'success');
                triggerSync(true);
                load(false);
            }).catch(function (err) {
                showAlert(err.message || 'Failed to delete event.', 'error');
            });
        });
    }

    document.querySelectorAll('.metis-calendar-detail-close').forEach(function (btn) {
        btn.addEventListener('click', closeDetailModal);
    });
    detailModal?.addEventListener('click', function (event) {
        if (event.target === detailModal) closeDetailModal();
    });
    detailEditBtn?.addEventListener('click', function () {
        if (!activeDetailItem || !canManage) return;
        populateModal(activeDetailItem);
        closeDetailModal();
        openModal();
    });

    renderCalendarFilters();
    load(false);
}

document.addEventListener('DOMContentLoaded', function () {
    initMetisCalendar({ root: document, reason: 'dom-ready', url: window.location.href });
});

if (window.Metis && Metis.page && typeof Metis.page.register === 'function') {
    Metis.page.register('calendar', initMetisCalendar);
}
