(function () {
  const root = document.querySelector('.metis-stash-app');
  const bootNode = document.querySelector('#metis-stash-boot');
  const ajax = window.metisGrandyStashAjax || {};
  if (!root || !bootNode) return;

  let state = parseJson(bootNode.textContent, {
    stats: {},
    catalog: {},
    assignees: [],
    routing_defaults: {},
    inbox: [],
    items: [],
    cases: [],
    distributions: []
  });
  const filters = {
    cases: 'open',
    items: 'available',
    distributions: 'active'
  };

  const canManage = root.dataset.canManage === '1';
  const ui = {
    alert: qs('#metis-stash-alert'),
    kpis: qs('#metis-stash-kpis'),
    caseFilters: qs('#metis-stash-case-filters'),
    itemFilters: qs('#metis-stash-item-filters'),
    distributionFilters: qs('#metis-stash-distribution-filters'),
    items: qs('#metis-stash-items'),
    cases: qs('#metis-stash-cases'),
    distributions: qs('#metis-stash-distributions'),
    itemModal: qs('#metis-stash-item-modal'),
    caseModal: qs('#metis-stash-case-modal'),
    routingForm: qs('#metis-stash-routing-form'),
    itemForm: qs('#metis-stash-item-form'),
    caseForm: qs('#metis-stash-case-form'),
    caseSubmission: qs('#metis-stash-case-submission'),
    caseAssignee: qs('#metis-stash-case-assignee'),
    routingRequest: qs('#metis-stash-routing-request'),
    routingDonation: qs('#metis-stash-routing-donation'),
    assignmentItem: qs('#metis-stash-assignment-item'),
    itemCategory: qs('#metis-stash-item-category'),
    itemName: qs('#metis-stash-item-name'),
    itemOptions: qs('#metis-stash-item-options')
  };

  ui.itemCategory?.addEventListener('change', renderItemSuggestions);

  render();

  root.addEventListener('click', async function (event) {
    const close = event.target.closest('[data-close-modal]');
    if (close) {
      closeModal(qs('#' + close.dataset.closeModal));
      return;
    }

    if (event.target.closest('#metis-stash-refresh')) {
      await refreshState();
      return;
    }

    if (event.target.closest('#metis-stash-new-item')) {
      populateItemForm(null);
      openModal(ui.itemModal);
      return;
    }

    const filterButton = event.target.closest('[data-filter-group]');
    if (filterButton) {
      filters[filterButton.dataset.filterGroup] = filterButton.dataset.filterValue || 'all';
      render();
      return;
    }

    const kpiCard = event.target.closest('[data-kpi-filter-group]');
    if (kpiCard) {
      filters[kpiCard.dataset.kpiFilterGroup] = kpiCard.dataset.kpiFilterValue || 'all';
      render();
      return;
    }

    const editItem = event.target.closest('[data-edit-item]');
    if (editItem) {
      const item = state.items.find((entry) => String(entry.id) === String(editItem.dataset.editItem));
      populateItemForm(item || null);
      openModal(ui.itemModal);
      return;
    }

    const reviewCase = event.target.closest('[data-review-case]');
    if (reviewCase) {
      const record = (state.inbox || []).find((entry) => String(entry.form_submission_id || entry.id) === String(reviewCase.dataset.reviewCase));
      populateCaseForm(record || null);
      openModal(ui.caseModal);
    }
  });

  ui.itemForm?.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (!canManage) return;
    try {
      await request('metis_grandys_stash_save_item', { payload: JSON.stringify(formToObject(ui.itemForm)) });
      closeModal(ui.itemModal);
      await refreshState('Equipment record saved.');
    } catch (error) {
      showAlert(error.message, 'error');
    }
  });

  ui.caseForm?.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (!canManage) return;
    try {
      const payload = formToObject(ui.caseForm);
      payload.contact = {
        first_name: payload.contact_first_name,
        last_name: payload.contact_last_name,
        email: payload.contact_email,
        phone: payload.contact_phone
      };
      await request('metis_grandys_stash_save_case', { payload: JSON.stringify(payload) });
      await refreshState('Case updated.');
    } catch (error) {
      showAlert(error.message, 'error');
    }
  });

  ui.routingForm?.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (!canManage) return;
    try {
      await request('metis_grandys_stash_save_routing_defaults', { payload: JSON.stringify(formToObject(ui.routingForm)) });
      await refreshState('Routing defaults updated.');
    } catch (error) {
      showAlert(error.message, 'error');
    }
  });

  qs('#metis-stash-assign')?.addEventListener('click', async function () {
    if (!canManage) return;
    try {
      const payload = formToObject(ui.caseForm);
      payload.item_id = ui.assignmentItem?.value || '';
      payload.status = payload.assignment_status || 'assigned';
      payload.fulfillment_method = payload.pickup_delivery || '';
      payload.contact = {
        first_name: payload.contact_first_name,
        last_name: payload.contact_last_name,
        email: payload.contact_email,
        phone: payload.contact_phone
      };
      await request('metis_grandys_stash_assign_item', { payload: JSON.stringify(payload) });
      closeModal(ui.caseModal);
      await refreshState('Equipment assigned.');
    } catch (error) {
      showAlert(error.message, 'error');
    }
  });

  async function refreshState(message) {
    const payload = await request('metis_grandys_stash_state');
    state = payload.state || state;
    render();
    if (message) showAlert(message);
  }

  function render() {
    renderKpis();
    renderFilters();
    renderAssignees();
    renderRoutingDefaults();
    renderItems();
    renderCases();
    renderDistributions();
    renderItemFormOptions();
  }

  function renderKpis() {
    if (!ui.kpis) return;
    const stats = state.stats || {};
    ui.kpis.innerHTML = [
      kpi('Available', stats.available_items || 0, 'items', 'available'),
      kpi('Assigned', stats.assigned_items || 0, 'items', 'assigned'),
      kpi('Intake review', stats.intake_items || 0, 'items', 'intake_review'),
      kpi('Open cases', stats.open_cases || 0, 'cases', 'open'),
      kpi('Requests', stats.request_cases || 0, 'cases', 'request'),
      kpi('Donations', stats.donation_cases || 0, 'cases', 'donation')
    ].join('');
  }

  function renderFilters() {
    if (ui.caseFilters) {
      ui.caseFilters.innerHTML = [
        filterChip('cases', 'open', 'Open'),
        filterChip('cases', 'request', 'Requests'),
        filterChip('cases', 'donation', 'Donations'),
        filterChip('cases', 'ready', 'Ready'),
        filterChip('cases', 'fulfilled', 'Fulfilled'),
        filterChip('cases', 'all', 'All')
      ].join('');
    }

    if (ui.itemFilters) {
      ui.itemFilters.innerHTML = [
        filterChip('items', 'available', 'Available'),
        filterChip('items', 'assigned', 'Assigned'),
        filterChip('items', 'intake_review', 'Intake review'),
        filterChip('items', 'maintenance', 'Maintenance'),
        filterChip('items', 'all', 'All')
      ].join('');
    }

    if (ui.distributionFilters) {
      ui.distributionFilters.innerHTML = [
        filterChip('distributions', 'active', 'Active'),
        filterChip('distributions', 'scheduled', 'Scheduled'),
        filterChip('distributions', 'completed', 'Completed'),
        filterChip('distributions', 'all', 'All')
      ].join('');
    }
  }

  function renderItems() {
    if (!ui.items) return;
    const items = (state.items || []).filter(matchesItemFilter);
    ui.items.innerHTML = items.map(function (item) {
      return `
        <article class="metis-stash-list-item">
          <div class="metis-stash-card-head">
            <div>
              <h3>${escapeHtml(item.name || '')}</h3>
              <div class="metis-stash-meta">${escapeHtml(item.equipment_code || '')}</div>
            </div>
            ${canManage ? `<button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-edit-item="${escapeAttr(item.id)}">Edit</button>` : ''}
          </div>
          <div class="metis-stash-tags">
            <span class="metis-stash-tag">${labelize(item.category)}</span>
            <span class="metis-stash-tag">${labelize(item.condition_status)}</span>
            <span class="metis-stash-tag">${labelize(item.status)}</span>
          </div>
          <div class="metis-stash-summary">${escapeHtml(item.storage_location || 'No location set')}</div>
        </article>`;
    }).join('') || `<div class="metis-stash-empty">No inventory records match this filter.</div>`;
  }

  function renderCases() {
    if (!ui.cases) return;
    const cases = (state.inbox || []).filter(matchesCaseFilter);
    ui.cases.innerHTML = cases.map(function (record) {
      const summary = record.summary || 'No details captured';
      const intakeClass = record.intake_type === 'donation' ? 'is-donation' : 'is-request';
      const typeLabel = record.intake_type === 'donation' ? 'Donation offer' : 'Request';
      const preview = previewMarkup(record.submission_preview || {});
      return `
        <article class="metis-stash-list-item ${intakeClass}">
          <div class="metis-stash-card-head">
            <div>
              <h3>${escapeHtml(record.contact_name || 'Unlinked contact')}</h3>
              <div class="metis-stash-meta">${escapeHtml(record.case_code || '')} · ${labelize(record.intake_type)} · ${labelize(record.status)}${record.submission_created_at ? ' · ' + escapeHtml(record.submission_created_at) : ''}</div>
            </div>
            ${canManage ? `<button type="button" class="mw-btn mw-btn-xs mw-btn-ghost" data-review-case="${escapeAttr(record.form_submission_id || record.id)}">Review</button>` : ''}
          </div>
          <div class="metis-stash-tags">
            <span class="metis-stash-tag metis-stash-ticket-type">${escapeHtml(typeLabel)}</span>
            <span class="metis-stash-tag">${labelize(record.urgency)}</span>
            ${record.pickup_delivery ? `<span class="metis-stash-tag">${labelize(record.pickup_delivery)}</span>` : ''}
            ${record.submission_key ? `<span class="metis-stash-tag">${escapeHtml(record.submission_key)}</span>` : ''}
            ${record.assignee_name ? `<span class="metis-stash-tag">Assigned: ${escapeHtml(record.assignee_name)}</span>` : `<span class="metis-stash-tag">Unassigned</span>`}
          </div>
          <div class="metis-stash-summary">${escapeHtml(summary)}</div>
          ${preview}
        </article>`;
    }).join('') || `<div class="metis-stash-empty">No cases match this filter.</div>`;
  }

  function renderDistributions() {
    if (!ui.distributions) return;
    const distributions = (state.distributions || []).filter(matchesDistributionFilter);
    ui.distributions.innerHTML = distributions.map(function (record) {
      const name = [record.first_name || '', record.last_name || ''].join(' ').trim() || 'Recipient pending';
      return `
        <article class="metis-stash-list-item">
          <div class="metis-stash-card-head">
            <div>
              <h3>${escapeHtml(record.item_name || '')}</h3>
              <div class="metis-stash-meta">${escapeHtml(record.equipment_code || '')} · ${escapeHtml(record.case_code || '')}</div>
            </div>
            <span class="metis-stash-tag">${labelize(record.status)}</span>
          </div>
          <div class="metis-stash-summary">${escapeHtml(name)}</div>
        </article>`;
    }).join('') || `<div class="metis-stash-empty">No assignments match this filter.</div>`;
  }

  function populateItemForm(item) {
    resetForm(ui.itemForm);
    renderItemFormOptions();
    if (!item) {
      renderItemSuggestions();
      return;
    }
    setField(ui.itemForm, 'id', item.id);
    setField(ui.itemForm, 'grandy_stash_item_name', item.name || '');
    setField(ui.itemForm, 'category', item.category || '');
    setField(ui.itemForm, 'condition_status', item.condition_status || '');
    setField(ui.itemForm, 'status', item.status || '');
    setField(ui.itemForm, 'storage_location', item.storage_location || '');
    setField(ui.itemForm, 'serial_number', item.serial_number || '');
    setField(ui.itemForm, 'notes', item.notes || '');
    renderItemSuggestions();
  }

  function populateCaseForm(record) {
    resetForm(ui.caseForm);
    if (ui.caseSubmission) {
      ui.caseSubmission.innerHTML = '';
    }
    if (!record) return;
    renderAssignees();
    setField(ui.caseForm, 'id', record.id);
    setField(ui.caseForm, 'status', record.status);
    setField(ui.caseForm, 'urgency', record.urgency);
    setField(ui.caseForm, 'pickup_delivery', record.pickup_delivery);
    setField(ui.caseForm, 'intake_type_display', record.intake_type === 'donation' ? 'Donation offer' : 'Request');
    setField(ui.caseForm, 'assignee_user_id', record.assignee_user_id || '');
    setField(ui.caseForm, 'notes', record.notes);
    setField(ui.caseForm, 'internal_notes', record.internal_notes);
    setField(ui.caseForm, 'contact_first_name', record.first_name || '');
    setField(ui.caseForm, 'contact_last_name', record.last_name || '');
    setField(ui.caseForm, 'contact_email', record.email || '');
    setField(ui.caseForm, 'contact_phone', record.phone || '');
    setField(ui.caseForm, 'scheduled_for', toLocalDateTime(record.scheduled_for));
    if (ui.caseSubmission) {
      const preview = previewMarkup(record.submission_preview || {});
      ui.caseSubmission.innerHTML = `
        <div class="metis-stash-card-head">
          <div>
            <h3>Ticket details</h3>
            <div class="metis-stash-meta">${escapeHtml(record.submission_key || 'No submission key')} ${record.submission_created_at ? '· ' + escapeHtml(record.submission_created_at) : ''}</div>
          </div>
        </div>
        ${preview || '<div class="metis-stash-empty">No submission preview available.</div>'}`;
    }

    const availableItems = (state.items || []).filter(function (item) {
      return item.status === 'available' || item.status === 'intake_review';
    });
    if (ui.assignmentItem) {
      ui.assignmentItem.innerHTML = `<option value="">Select equipment</option>` + availableItems.map(function (item) {
        return `<option value="${escapeAttr(item.id)}">${escapeHtml(item.equipment_code + ' · ' + item.name)}</option>`;
      }).join('');
    }
  }

  function request(action, body) {
    const params = new URLSearchParams({ action: action, nonce: ajax.nonce || '' });
    Object.entries(body || {}).forEach(function ([key, value]) {
      params.append(key, value);
    });
    return fetch(ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: params })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload || !payload.success) {
          throw new Error(payload?.data?.message || 'Request failed');
        }
        return payload.data || {};
      });
  }

  function kpi(label, value, group, filter) {
    const active = filters[group] === filter ? ' is-active' : '';
    return `<article class="metis-stash-kpi${active}" data-kpi-filter-group="${escapeAttr(group)}" data-kpi-filter-value="${escapeAttr(filter)}"><span>${escapeHtml(label)}</span><strong>${escapeHtml(String(value))}</strong></article>`;
  }

  function filterChip(group, value, label) {
    const active = filters[group] === value ? ' is-active' : '';
    return `<button type="button" class="metis-stash-filter${active}" data-filter-group="${escapeAttr(group)}" data-filter-value="${escapeAttr(value)}">${escapeHtml(label)}</button>`;
  }

  function openModal(node) {
    node?.classList.add('metis-open');
    if (node) node.setAttribute('aria-hidden', 'false');
  }

  function closeModal(node) {
    node?.classList.remove('metis-open');
    if (node) node.setAttribute('aria-hidden', 'true');
  }

  function formToObject(form) {
    const data = {};
    Array.from(new FormData(form).entries()).forEach(function ([key, value]) {
      data[key] = value;
    });
    data.name = data.grandy_stash_item_name || '';
    return data;
  }

  function resetForm(form) {
    form?.reset();
    qsa(form, 'input[type="hidden"]').forEach((node) => { node.value = ''; });
  }

  function setField(form, name, value) {
    const field = form?.elements?.namedItem(name);
    if (field) field.value = value || '';
  }

  function renderItemFormOptions() {
    const categories = state.catalog?.categories || [];
    if (ui.itemCategory) {
      ui.itemCategory.innerHTML = `<option value="other">Other</option>` + categories.map(function (category) {
        return `<option value="${escapeAttr(category.category_slug || '')}">${escapeHtml(category.category_name || '')}</option>`;
      }).join('');
    }
    renderItemSuggestions();
  }

  function renderAssignees() {
    const assignees = state.assignees || [];
    const options = `<option value="">Unassigned</option>` + assignees.map(function (person) {
      return `<option value="${escapeAttr(person.id || '')}">${escapeHtml(person.label || '')}</option>`;
    }).join('');
    if (ui.caseAssignee) {
      ui.caseAssignee.innerHTML = options;
    }
    if (ui.routingRequest) {
      ui.routingRequest.innerHTML = options;
    }
    if (ui.routingDonation) {
      ui.routingDonation.innerHTML = options;
    }
  }

  function renderRoutingDefaults() {
    const defaults = state.routing_defaults || {};
    if (ui.routingRequest) {
      ui.routingRequest.value = String(defaults.request_assignee_user_id || '');
      ui.routingRequest.disabled = !canManage;
    }
    if (ui.routingDonation) {
      ui.routingDonation.value = String(defaults.donation_assignee_user_id || '');
      ui.routingDonation.disabled = !canManage;
    }
  }

  function renderItemSuggestions() {
    if (!ui.itemOptions) return;
    const category = ui.itemCategory?.value || '';
    const items = (state.catalog?.items || []).filter(function (item) {
      return !category || category === 'other' ? true : String(item.category_slug || '') === String(category);
    });
    ui.itemOptions.innerHTML = items.map(function (item) {
      return `<option value="${escapeAttr(item.item_name || '')}"></option>`;
    }).join('');
  }

  function matchesCaseFilter(record) {
    const current = normalize(filters.cases);
    const status = normalize(record?.status);
    const intakeType = normalize(record?.intake_type);
    if (current === 'all') return true;
    if (current === 'open') return !['fulfilled', 'closed'].includes(status);
    if (current === 'request' || current === 'donation') return intakeType === current;
    return status === current;
  }

  function matchesItemFilter(item) {
    const current = filters.items;
    if (current === 'all') return true;
    return normalize(item?.status) === current;
  }

  function matchesDistributionFilter(record) {
    const current = filters.distributions;
    if (current === 'all') return true;
    const status = normalize(record?.status);
    if (current === 'active') return ['assigned', 'scheduled', 'completed'].includes(status);
    return status === current;
  }

  function previewMarkup(preview) {
    const entries = Object.entries(preview || {}).filter(function ([, value]) {
      return String(value || '').trim() !== '';
    });
    if (!entries.length) return '';
    return `<div class="metis-stash-submission-grid">` + entries.map(function ([label, value]) {
      return `<div><strong>${escapeHtml(label)}</strong><span>${escapeHtml(String(value))}</span></div>`;
    }).join('') + `</div>`;
  }

  function normalize(value) {
    return String(value || '').trim().toLowerCase();
  }

  function toLocalDateTime(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) return '';
    const pad = (num) => String(num).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  }

  function labelize(value) {
    return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  function showAlert(message, type) {
    if (!ui.alert) return;
    ui.alert.className = `mw-alert ${type === 'error' ? 'mw-alert-error' : 'mw-alert-success'}`;
    ui.alert.textContent = message;
    ui.alert.style.display = 'block';
  }

  function parseJson(raw, fallback) {
    try {
      return JSON.parse(raw || '');
    } catch (error) {
      return fallback;
    }
  }

  function qs(selector) {
    return root.querySelector(selector);
  }

  function qsa(node, selector) {
    return Array.from((node || root).querySelectorAll(selector));
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
    });
  }

  function escapeAttr(value) {
    return escapeHtml(value);
  }
}());
