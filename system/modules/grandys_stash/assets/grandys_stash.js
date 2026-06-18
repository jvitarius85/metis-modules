(function () {
  const root = document.querySelector('.metis-stash-app');
  const bootNode = document.querySelector('#metis-stash-boot');
  const ajax = window.metisGrandyStashAjax || {};
  if (!root || !bootNode) return;

  let state = parseJson(bootNode.textContent, { stats: {}, tickets: [], assignees: [], groups: [], organizations: [] });
  let currentFilter = 'action';
  let currentTicketId = 0;
  let selectedManagerId = 0;
  let selectedManagerKind = '';

  const canManage = root.dataset.canManage === '1';
  const canCreate = root.dataset.canCreate === '1';
  const canAssign = root.dataset.canAssign === '1';
  const canComment = root.dataset.canComment === '1';
  const canReply = root.dataset.canReply === '1';
  const canInventory = root.dataset.canInventory === '1';
  const canDelete = root.dataset.canDelete === '1';
  const currentUserId = parseInt(ajax.user_id || '0', 10);
  const isTicketPage = root.dataset.ticketPage === '1';
  const stashView = String(root.dataset.stashView || '');
  const viewBaseUrl = String(root.dataset.viewBaseUrl || '');

  const ui = {
    alert: qs('#metis-stash-alert'),
    rows: qs('#metis-stash-rows'),
    search: qs('#metis-stash-search'),
    ticketHeader: qs('#metis-stash-ticket-header'),
    ticketItems: qs('#metis-stash-ticket-items'),
    ticketConversation: qs('#metis-stash-ticket-conversation'),
    ticketNotes: qs('#metis-stash-ticket-notes'),
    ticketActivity: qs('#metis-stash-ticket-activity'),
    ticketOrganizationSection: qs('#metis-stash-ticket-organization-section'),
    ticketOrganization: qs('#metis-stash-ticket-organization'),
    ticketGroupSection: qs('#metis-stash-ticket-group-section'),
    ticketGroup: qs('#metis-stash-ticket-group'),
    ticketForm: qs('#metis-stash-ticket-form'),
    ticketDelete: qs('#metis-stash-ticket-delete'),
    replySubject: qs('#metis-stash-reply-subject'),
    replyInput: qs('#metis-stash-reply-input'),
    replySubmit: qs('#metis-stash-reply-submit'),
    noteInput: qs('#metis-stash-note-input'),
    noteSubmit: qs('#metis-stash-note-submit'),
    newTicketModal: qs('#metis-stash-new-ticket-modal'),
    newTicketForm: qs('#metis-stash-new-ticket-form'),
    routingForm: qs('#metis-stash-routing-form'),
    reportRunBtn: qs('#metis-stash-report-run'),
    reportFrom: qs('#metis-stash-report-from'),
    reportTo: qs('#metis-stash-report-to'),
    reportContent: qs('#metis-stash-report-content'),
    groupRows: qs('#metis-stash-group-rows'),
    groupSearch: qs('#metis-stash-group-search'),
    groupModal: qs('#metis-stash-group-modal'),
    groupModalTitle: qs('#metis-stash-group-modal-title'),
    groupModalSubtitle: qs('#metis-stash-group-modal-subtitle'),
    groupForm: qs('#metis-stash-group-form'),
    groupTicketList: qs('#metis-stash-group-ticket-list'),
    organizationRows: qs('#metis-stash-organization-rows'),
    organizationSearch: qs('#metis-stash-organization-search'),
    organizationModal: qs('#metis-stash-organization-modal'),
    organizationModalTitle: qs('#metis-stash-organization-modal-title'),
    organizationModalSubtitle: qs('#metis-stash-organization-modal-subtitle'),
    organizationForm: qs('#metis-stash-organization-form'),
    organizationTicketList: qs('#metis-stash-organization-ticket-list'),
  };

  initialize();

  function initialize() {
    mountModalToBody(ui.groupModal);
    mountModalToBody(ui.organizationModal);
    mountModalToBody(ui.newTicketModal);
    filterRows();
    filterManagerRows();
    hydrateReportDateInputs();

    if (isTicketPage) {
      const initialTicketId = parseInt(root.dataset.ticketId || '0', 10);
      if (initialTicketId > 0) currentTicketId = initialTicketId;
    }
  }

  document.addEventListener('click', async function (e) {
    const close = e.target.closest('[data-close-modal]');
    if (close) {
      closeModal(document.getElementById(String(close.dataset.closeModal || '')));
      return;
    }

    if (e.target.closest('#metis-stash-new-ticket-open')) {
      if (!canCreate) return;
      ui.newTicketForm?.reset();
      openModal(ui.newTicketModal);
      return;
    }

    const filterBtn = e.target.closest('[data-filter]');
    if (filterBtn) {
      qsa('.metis-stash-sidebar-filter').forEach(function (button) {
        button.classList.remove('is-active');
        button.classList.add('metis-btn-ghost');
      });
      filterBtn.classList.add('is-active');
      filterBtn.classList.remove('metis-btn-ghost');
      currentFilter = String(filterBtn.dataset.filter || 'all');
      filterRows();
      return;
    }

    const managerFilterBtn = e.target.closest('[data-manager-filter]');
    if (managerFilterBtn) {
      qsa('.metis-stash-manager-filter').forEach(function (button) {
        button.classList.remove('is-active');
        button.classList.add('metis-btn-ghost');
      });
      managerFilterBtn.classList.add('is-active');
      managerFilterBtn.classList.remove('metis-btn-ghost');
      filterManagerRows();
      return;
    }

    const managerOpen = e.target.closest('[data-manager-open]');
    if (managerOpen) {
      openManagerModal(String(managerOpen.dataset.managerOpen || ''), Number(managerOpen.dataset.id || 0));
      return;
    }

    const managerRow = e.target.closest('.metis-stash-manager-row[data-manager-kind]');
    if (managerRow && !e.target.closest('a, button, input, select, textarea, label')) {
      openManagerModal(String(managerRow.dataset.managerKind || ''), Number(managerRow.dataset.id || 0));
      return;
    }

    const tabButton = e.target.closest('.metis-stash-tab[data-tab-target]');
    if (tabButton) {
      switchTab(tabButton);
      return;
    }

    const reviewBtn = e.target.closest('a[data-ticket-url], button[data-ticket-url], [data-ticket-id]:not(.metis-stash-row)');
    if (reviewBtn) {
      const ticketUrl = String(reviewBtn.dataset.ticketUrl || '');
      if (ticketUrl) {
        window.location.href = ticketUrl;
      }
      return;
    }

    const ticketRow = e.target.closest('.metis-stash-row[data-ticket-url]');
    if (ticketRow && !e.target.closest('a, button, input, select, textarea, label, summary')) {
      const ticketUrl = String(ticketRow.dataset.ticketUrl || '');
      if (ticketUrl) window.location.href = ticketUrl;
      return;
    }

    const itemAction = e.target.closest('[data-item-action]');
    if (itemAction && canInventory) {
      await updateItemStatus(parseInt(itemAction.dataset.itemId || '0', 10), String(itemAction.dataset.itemAction || ''));
      return;
    }

    const unlinkBtn = e.target.closest('[data-unlink-group]');
    if (unlinkBtn && canAssign && currentTicketId > 0) {
      try {
        const data = await request('metis_grandys_stash_unlink_group', { ticket_id: currentTicketId });
        applyStateUpdate(data);
        if (ui.ticketGroup) ui.ticketGroup.innerHTML = '';
        if (ui.ticketGroupSection) ui.ticketGroupSection.style.display = 'none';
        if (data?.detail?.activity) renderActivity(data.detail.activity);
        showAlert('Ticket unlinked.');
      } catch (err) {
        showAlert(err.message, 'error');
      }
    }
  });

  ui.search?.addEventListener('input', filterRows);
  ui.groupSearch?.addEventListener('input', filterManagerRows);
  ui.organizationSearch?.addEventListener('input', filterManagerRows);

  ui.ticketForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canAssign) return;
    try {
      const data = await request('metis_grandys_stash_save_ticket', { payload: JSON.stringify(formToObject(ui.ticketForm)) });
      applyStateUpdate(data);
      showAlert('Ticket updated.');
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.ticketDelete?.addEventListener('click', async function () {
    if (!canDelete || currentTicketId < 1) return;
    if (!window.confirm('Delete this ticket and its conversation history? This cannot be undone.')) return;
    try {
      const data = await request('metis_grandys_stash_delete_ticket', { ticket_id: currentTicketId });
      showAlert(data.deleted_code ? 'Deleted ' + data.deleted_code + '.' : 'Ticket deleted.');
      window.location.href = root.dataset.viewBaseUrl ? root.dataset.viewBaseUrl.replace(/view\/?$/, '') : window.location.origin;
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.noteSubmit?.addEventListener('click', async function () {
    if (!canComment || !ui.noteInput || currentTicketId < 1) return;
    const content = ui.noteInput.value.trim();
    if (!content) return;
    try {
      const data = await request('metis_grandys_stash_add_note', { ticket_id: currentTicketId, content: content });
      ui.noteInput.value = '';
      renderNotes(data.notes || []);
      renderActivity(data.activity || []);
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.replySubmit?.addEventListener('click', async function () {
    if (!canReply || !ui.replyInput || currentTicketId < 1) return;
    const content = ui.replyInput.value.trim();
    const subject = ui.replySubject?.value?.trim() || '';
    if (!content) return;

    try {
      ui.replySubmit.disabled = true;
      const data = await request('metis_grandys_stash_send_reply', { ticket_id: currentTicketId, subject: subject, content: content });
      ui.replyInput.value = '';
      renderConversation(data.messages || []);
      renderActivity(data.activity || []);
      showAlert('Reply sent.');
    } catch (err) {
      showAlert(err.message, 'error');
    } finally {
      ui.replySubmit.disabled = false;
    }
  });

  ui.newTicketForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canCreate) return;
    try {
      await request('metis_grandys_stash_create_ticket', { payload: JSON.stringify(formToObject(ui.newTicketForm)) });
      closeModal(ui.newTicketModal);
      await refreshState('Ticket created.');
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.groupForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canAssign) return;
    try {
      await request('metis_grandys_stash_save_group', { payload: JSON.stringify(formToObject(ui.groupForm)) });
      await refreshState('Group saved.');
      openManagerModal('group', Number(ui.groupForm.elements.namedItem('id')?.value || '0'));
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.organizationForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    try {
      await request('metis_grandys_stash_save_organization', { payload: JSON.stringify(formToObject(ui.organizationForm)) });
      await refreshState('Organization saved.');
      openManagerModal('organization', Number(ui.organizationForm.elements.namedItem('id')?.value || '0'));
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.routingForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    try {
      await request('metis_grandys_stash_save_routing_defaults', { payload: JSON.stringify(formToObject(ui.routingForm)) });
      showAlert('Routing defaults saved.');
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  root.addEventListener('change', async function (e) {
    const toggle = e.target.closest('.metis-stash-email-toggle');
    if (!toggle || !canManage) return;
    try {
      await request('metis_grandys_stash_set_email_pref', {
        user_id: String(toggle.dataset.userId || ''),
        enabled: toggle.checked ? '1' : '0'
      });
      const label = toggle.parentElement?.querySelector('span');
      if (label) label.textContent = toggle.checked ? 'Yes' : 'No';
      showAlert('Email preference updated.');
    } catch (err) {
      toggle.checked = !toggle.checked;
      showAlert(err.message, 'error');
    }
  });

  ui.reportRunBtn?.addEventListener('click', async function () {
    const from = ui.reportFrom?.value || '';
    const to = ui.reportTo?.value || '';
    try {
      ui.reportRunBtn.disabled = true;
      ui.reportRunBtn.textContent = 'Loading...';
      const data = await request('metis_grandys_stash_report', { from: from, to: to });
      if (ui.reportContent) {
        ui.reportContent.innerHTML = buildReportHTML(data.report || {});
      }
      const params = new URLSearchParams(window.location.search);
      if (from) params.set('from', from); else params.delete('from');
      if (to) params.set('to', to); else params.delete('to');
      history.replaceState({}, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''));
      showAlert('Report updated.');
    } catch (err) {
      showAlert(err.message, 'error');
    } finally {
      ui.reportRunBtn.disabled = false;
      ui.reportRunBtn.textContent = 'Run Report';
    }
  });

  function applyStateUpdate(data) {
    if (data?.state) {
      state = Object.assign({}, state, data.state);
      rebuildRows();
      rebuildManagerSelections();
    }
  }

  function filterRows() {
    if (!ui.rows) return;
    const searchQuery = String(ui.search?.value || '').toLowerCase().trim();
    qsa('.metis-stash-row').forEach(function (row) {
      const status = String(row.dataset.status || '');
      const assigned = parseInt(row.dataset.assigned || '0', 10);
      const searchBlob = String(row.dataset.search || '');
      let visible = true;

      if (searchQuery && !searchBlob.includes(searchQuery)) {
        visible = false;
      }
      if (visible && currentFilter !== 'all') {
        if (currentFilter === 'action') {
          visible = status === 'NEW' || status === 'REVIEWING';
        } else if (currentFilter === 'waitlist') {
          visible = status === 'WAITLIST';
        } else if (currentFilter === 'mine') {
          visible = assigned === currentUserId && !['COMPLETED', 'CLOSED'].includes(status);
        }
      }

      row.style.display = visible ? '' : 'none';
    });
  }

  function filterManagerRows() {
    const filterBtn = root.querySelector('.metis-stash-manager-filter.is-active');
    const filter = String(filterBtn?.dataset.managerFilter || 'all');
    const rows = qsa('.metis-stash-manager-row');
    const searchQuery = String(ui.groupSearch?.value || ui.organizationSearch?.value || '').toLowerCase().trim();
    rows.forEach(function (row) {
      let visible = true;
      const openCount = parseInt(row.dataset.openCount || '0', 10);
      const lastTicket = String(row.dataset.lastTicket || '');
      const isActive = row.dataset.isActive !== '0';
      const searchBlob = String(row.dataset.search || '');

      if (searchQuery && !searchBlob.includes(searchQuery)) visible = false;
      if (visible && filter === 'open') visible = openCount > 0;
      if (visible && filter === 'active') visible = isActive;
      if (visible && filter === 'recent') visible = lastTicket !== '';

      row.style.display = visible ? '' : 'none';
    });
  }

  function openManagerModal(kind, id) {
    if (!kind || id < 1) return;
    selectedManagerKind = kind;
    selectedManagerId = id;

    qsa('.metis-stash-manager-row').forEach(function (row) {
      row.classList.toggle('is-selected', String(row.dataset.managerKind || '') === kind && Number(row.dataset.id || 0) === id);
    });

    if (kind === 'group') {
      const group = (state.groups || []).find(function (entry) { return Number(entry.id || 0) === id; });
      if (!group || !ui.groupTicketList) return;
      fillForm(ui.groupForm, group);
      if (ui.groupModalTitle) ui.groupModalTitle.textContent = group.name || 'Group Manager';
      if (ui.groupModalSubtitle) ui.groupModalSubtitle.textContent = group.code || '';
      ui.groupTicketList.innerHTML = buildManagerTicketList('Linked Tickets', ticketsForGroup(id));
      activateTab('group-general');
      openModal(ui.groupModal);
    } else if (kind === 'organization') {
      const organization = (state.organizations || []).find(function (entry) { return Number(entry.id || 0) === id; });
      if (!organization || !ui.organizationTicketList) return;
      fillForm(ui.organizationForm, organization);
      if (ui.organizationModalTitle) ui.organizationModalTitle.textContent = organization.name || 'Organization Manager';
      if (ui.organizationModalSubtitle) ui.organizationModalSubtitle.textContent = organization.domain || organization.code || '';
      ui.organizationTicketList.innerHTML = buildManagerTicketList('Linked Tickets', ticketsForOrganization(id));
      activateTab('organization-general');
      openModal(ui.organizationModal);
    }
  }

  function ticketsForGroup(groupId) {
    return (state.tickets || []).filter(function (ticket) {
      return Number(ticket.group_id || 0) === groupId;
    });
  }

  function ticketsForOrganization(organizationId) {
    return (state.tickets || []).filter(function (ticket) {
      return Number(ticket.organization_id || 0) === organizationId;
    });
  }

  function buildManagerTicketList(title, tickets) {
    if (!tickets.length) {
      return '<div class="metis-stash-ticket-section"><h3>' + esc(title) + '</h3><div class="metis-muted">No linked tickets.</div></div>';
    }
    return '<div class="metis-stash-ticket-section"><h3>' + esc(title) + '</h3><div class="metis-stash-linked-tickets">' +
      tickets.map(function (ticket) {
        const url = buildTicketUrl(ticket.code || '');
        return '<a class="metis-stash-linked-ticket" href="' + esc(url) + '" data-ticket-url="' + esc(url) + '">' +
          '<strong>' + esc(ticket.code || '') + '</strong>' +
          '<span>' + esc(ticket.submit_name || 'Unknown') + '</span>' +
          '<span class="metis-muted">' + esc(labelize(ticket.type || 'request')) + ' · ' + esc(ticket.status || 'NEW') + ' · ' + esc(shortDate(ticket.submitted_at || '')) + '</span>' +
        '</a>';
      }).join('') + '</div></div>';
  }

  function renderTicketItems(items) {
    if (!ui.ticketItems) return;
    if (!items.length) {
      ui.ticketItems.innerHTML = '<div class="metis-muted">No line items.</div>';
      return;
    }

    ui.ticketItems.innerHTML = '<table class="metis-premium-table"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell" scope="col">Item</th><th class="metis-premium-cell" scope="col">Category</th><th class="metis-premium-cell" scope="col">Qty</th><th class="metis-premium-cell" scope="col">Status</th>' + (canInventory ? '<th class="metis-premium-cell" scope="col">Actions</th>' : '') + '</tr></thead><tbody>' +
      items.map(function (item) {
        const actions = canInventory ? '<td class="metis-premium-cell"><div class="metis-stash-item-actions">' +
          (item.status !== 'available' ? '<button class="metis-btn-xs metis-btn-ghost" data-item-id="' + item.id + '" data-item-action="available">Available</button>' : '') +
          (item.status !== 'fulfilled' ? '<button class="metis-btn-xs metis-btn-ghost" data-item-id="' + item.id + '" data-item-action="fulfilled">Fulfilled</button>' : '') +
          (item.status !== 'unavailable' ? '<button class="metis-btn-xs metis-btn-ghost" data-item-id="' + item.id + '" data-item-action="unavailable">Unavailable</button>' : '') +
          '</div></td>' : '';
        return '<tr class="metis-premium-row">' +
          '<td class="metis-premium-cell"><strong>' + esc(item.item_name || item.category || 'Other') + '</strong>' + (item.condition_status ? '<div class="metis-muted">' + esc(labelize(item.condition_status)) + '</div>' : '') + '</td>' +
          '<td class="metis-premium-cell">' + esc(item.category || 'Other') + '</td>' +
          '<td class="metis-premium-cell">' + Number(item.quantity || 1) + '</td>' +
          '<td class="metis-premium-cell"><span class="metis-stash-status-badge metis-stash-status-' + esc(String(item.status || 'pending').toLowerCase()) + '">' + esc(item.status || 'pending') + '</span></td>' +
          actions +
        '</tr>';
      }).join('') + '</tbody></table>';
  }

  function renderConversation(messages) {
    if (!ui.ticketConversation) return;
    if (!messages.length) {
      ui.ticketConversation.innerHTML = '<div class="metis-muted">No email conversation yet.</div>';
      return;
    }

    ui.ticketConversation.innerHTML = messages.map(function (message) {
      const direction = String(message.direction || 'inbound');
      const status = String(message.delivery_status || (direction === 'outbound' ? 'sent' : 'received'));
      const author = esc(message.author_label || message.sender_email || 'System');
      const recipient = esc(message.recipient_email || '');
      const when = esc(shortDate(message.timeline_at || message.message_at || message.sent_at || message.received_at || message.created_at));
      const body = esc(message.body_text_display || '').replace(/\n/g, '<br>');
      const attachments = Array.isArray(message.attachments) ? message.attachments : [];
      const attachmentLinks = attachments.filter(function (attachment) {
        return attachment && (attachment.download_url || attachment.media_url);
      }).map(function (attachment) {
        const url = esc(String(attachment.download_url || attachment.media_url || ''));
        const name = esc(String(attachment.file_name || attachment.filename || 'Attachment'));
        return '<a class="metis-stash-conversation-attachment" href="' + url + '" target="_blank" rel="noopener">' + name + '</a>';
      }).join('');
      return '<article class="metis-stash-conversation-entry metis-stash-conversation-' + direction + '">' +
        '<div class="metis-stash-conversation-head"><div class="metis-stash-conversation-meta">' +
          '<span class="metis-stash-status-badge">' + esc(direction === 'outbound' ? 'Staff Reply' : 'Public Reply') + '</span>' +
          '<strong>' + author + '</strong>' +
          (recipient ? '<span class="metis-muted">to ' + recipient + '</span>' : '') +
        '</div><div class="metis-muted">' + when + '</div></div>' +
        '<div class="metis-stash-conversation-subject">' + esc(message.subject || '(No subject)') + '</div>' +
        '<div class="metis-stash-conversation-body">' + (body || '<span class="metis-muted">No message body.</span>') + '</div>' +
        (attachmentLinks ? '<div class="metis-stash-conversation-attachments">' + attachmentLinks + '</div>' : '') +
        '<div class="metis-stash-conversation-foot"><span class="metis-muted">Status: ' + esc(labelize(status)) + '</span></div>' +
      '</article>';
    }).join('');
  }

  function renderNotes(notes) {
    if (!ui.ticketNotes) return;
    if (!notes.length) {
      ui.ticketNotes.innerHTML = '<div class="metis-muted">No notes yet.</div>';
      return;
    }
    ui.ticketNotes.innerHTML = notes.map(function (note) {
      return '<div class="metis-stash-note-entry"><div class="metis-muted" style="font-size:12px;">' + esc(note.author_name || 'System') + ' · ' + esc(shortDate(note.created_at)) + '</div><p style="margin:4px 0 0;">' + esc(note.content || '') + '</p></div>';
    }).join('');
  }

  function renderActivity(activity) {
    if (!ui.ticketActivity) return;
    if (!activity.length) {
      ui.ticketActivity.innerHTML = '<div class="metis-muted">No activity.</div>';
      return;
    }
    ui.ticketActivity.innerHTML = activity.map(function (entry) {
      return '<div class="metis-stash-activity-entry"><span class="metis-stash-status-badge">' + esc(labelize(entry.action || 'activity')) + '</span><span class="metis-muted">' + esc(entry.detail || '') + (entry.detail ? ' · ' : '') + esc(entry.author_name || 'System') + ' · ' + esc(shortDate(entry.created_at)) + '</span></div>';
    }).join('');
  }

  async function updateItemStatus(itemId, status) {
    if (itemId < 1 || !status) return;
    try {
      const data = await request('metis_grandys_stash_update_item_status', { item_id: itemId, status: status });
      applyStateUpdate(data);
      if (data?.detail?.items) renderTicketItems(data.detail.items);
      if (data?.detail?.activity) renderActivity(data.detail.activity);
      showAlert('Item updated.');
    } catch (err) {
      showAlert(err.message, 'error');
    }
  }

  async function refreshState(message) {
    try {
      const data = await request('metis_grandys_stash_state');
      state = data.state || state;
      rebuildRows();
      rebuildManagerSelections();
      if (message) showAlert(message);
    } catch (err) {
      showAlert(err.message, 'error');
    }
  }

  function rebuildRows() {
    if (ui.rows) {
      ui.rows.innerHTML = (state.tickets || []).map(function (ticket) {
        const typeLabel = ticket.type === 'donation' ? 'Donation' : 'Request';
        const status = String(ticket.status || 'NEW');
        const code = esc(ticket.code || '');
        const name = esc(ticket.submit_name || 'Unknown');
        const assigned = esc(ticket.assigned_name || '\u2014');
        const urgency = esc(labelize(ticket.urgency || 'standard'));
        const items = Number(ticket.item_count || 0);
        const date = esc(shortDate(ticket.submitted_at || ''));
        const search = [ticket.code || '', ticket.submit_name || '', typeLabel, status, assigned, ticket.submit_email || '', ticket.items_summary || ''].join(' ').toLowerCase();
        const ticketUrl = buildTicketUrl(ticket.code || '');

        return '<tr class="metis-premium-row metis-stash-row" data-id="' + Number(ticket.id || 0) + '" data-ticket-url="' + esc(ticketUrl) + '" data-status="' + esc(status) + '" data-type="' + esc(ticket.type || 'request') + '" data-assigned="' + Number(ticket.assigned_to || 0) + '" data-search="' + esc(search) + '">' +
          '<td class="metis-premium-cell"><strong>' + code + '</strong></td>' +
          '<td class="metis-premium-cell">' + name + '</td>' +
          '<td class="metis-premium-cell"><span class="metis-stash-type-badge metis-stash-type-' + esc(ticket.type || 'request') + '">' + esc(typeLabel) + '</span></td>' +
          '<td class="metis-premium-cell"><span class="metis-stash-status-badge metis-stash-status-' + esc(status.toLowerCase()) + '">' + esc(status) + '</span></td>' +
          '<td class="metis-premium-cell">' + urgency + '</td>' +
          '<td class="metis-premium-cell">' + assigned + '</td>' +
          '<td class="metis-premium-cell">' + items + '</td>' +
          '<td class="metis-premium-cell">' + date + '</td>' +
          (canManage ? '<td class="metis-premium-cell"><a class="metis-btn-xs" href="' + esc(ticketUrl) + '" data-ticket-url="' + esc(ticketUrl) + '">Review</a></td>' : '') +
        '</tr>';
      }).join('');
      filterRows();
    }
  }

  function rebuildManagerSelections() {
    filterManagerRows();
  }

  function switchTab(tabButton) {
    const target = String(tabButton.dataset.tabTarget || '');
    if (!target) return;
    activateTab(target);
  }

  function activateTab(target) {
    qsa('.metis-stash-tab').forEach(function (button) {
      const active = String(button.dataset.tabTarget || '') === target;
      button.classList.toggle('is-active', active);
      button.classList.toggle('metis-btn-ghost', !active);
    });
    qsa('.metis-stash-tab-panel').forEach(function (panel) {
      panel.classList.toggle('is-active', String(panel.dataset.tabPanel || '') === target);
    });
  }

  function buildReportHTML(report) {
    const summary = report.summary || {};
    updateReportKpis(summary, report);
    return [
      buildCategoryTable(report.by_category || []),
      buildMonthlyTable(report.monthly || []),
      buildSmallReportSplit(report.by_urgency || [], report.by_source || []),
      buildOrganizationReport(report.by_organization || []),
      buildPersonReport(report.by_person || []),
      buildEquipmentReport(report.by_equipment || [])
    ].join('');
  }

  function updateReportKpis(summary, report) {
    const values = qsa('.metis-people-stat-value');
    if (values.length < 6) return;
    values[0].textContent = String(summary.total_tickets || 0);
    values[1].textContent = String(report.people_served || 0);
    values[2].textContent = String(report.items_fulfilled || 0);
    values[3].textContent = String(summary.completed || 0);
    values[4].textContent = String(report.avg_days_to_complete || '\u2014');
    values[5].textContent = String(summary.open_tickets || 0);
  }

  function buildCategoryTable(rows) {
    return '<section style="margin-bottom:28px;"><h2 style="font-size:16px;margin:0 0 12px;">Items by Category</h2>' +
      buildTable('metis-stash-report-cat-table', ['Category', 'Total Items', 'Fulfilled'], rows.map(function (row) {
        return [labelize(row.category || 'other'), Number(row.item_count || 0), Number(row.fulfilled || 0)];
      }), 3) + '</section>';
  }

  function buildMonthlyTable(rows) {
    return '<section style="margin-bottom:28px;"><h2 style="font-size:16px;margin:0 0 12px;">Monthly Breakdown</h2>' +
      buildTable('metis-stash-report-month-table', ['Month', 'Tickets', 'Requests', 'Donations', 'Completed'], rows.map(function (row) {
        return [row.month_label || row.month || '', Number(row.tickets || 0), Number(row.requests || 0), Number(row.donations || 0), Number(row.completed || 0)];
      }), 5) + '</section>';
  }

  function buildSmallReportSplit(urgencyRows, sourceRows) {
    return '<div class="metis-stash-report-split">' +
      '<section><h2 style="font-size:16px;margin:0 0 12px;">By Urgency</h2>' +
      buildTable('metis-stash-report-small-table', ['Urgency', 'Count'], urgencyRows.map(function (row) {
        return [labelize(row.urgency || ''), Number(row.count || 0)];
      }), 2) + '</section>' +
      '<section><h2 style="font-size:16px;margin:0 0 12px;">By Source</h2>' +
      buildTable('metis-stash-report-small-table', ['Source', 'Count'], sourceRows.map(function (row) {
        return [labelize(row.source || ''), Number(row.count || 0)];
      }), 2) + '</section>' +
      '</div>';
  }

  function buildOrganizationReport(rows) {
    return '<section style="margin:28px 0;"><h2 style="font-size:16px;margin:0 0 12px;">Requests by Organization</h2>' +
      buildTable('metis-stash-report-wide-table', ['Organization', 'Domain', 'Requests', 'Tickets'], rows.map(function (row) {
        return [row.organization_name || 'Independent', row.organization_domain || '\u2014', Number(row.request_count || 0), Number(row.ticket_count || 0)];
      }), 4) + '</section>';
  }

  function buildPersonReport(rows) {
    return '<section style="margin:28px 0;"><h2 style="font-size:16px;margin:0 0 12px;">Requests by Person</h2>' +
      buildTable('metis-stash-report-wide-table', ['Person', 'Email', 'Requests', 'Tickets'], rows.map(function (row) {
        return [row.person_name || 'Unknown', row.person_email || '\u2014', Number(row.request_count || 0), Number(row.ticket_count || 0)];
      }), 4) + '</section>';
  }

  function buildEquipmentReport(rows) {
    return '<section style="margin:28px 0 0;"><h2 style="font-size:16px;margin:0 0 12px;">Equipment Requested</h2>' +
      buildTable('metis-stash-report-equipment-table', ['Equipment', 'Category', 'Requests', 'Donations', 'Fulfilled'], rows.map(function (row) {
        return [row.equipment_name || 'Other', labelize(row.category || 'other'), Number(row.request_quantity || 0), Number(row.donation_quantity || 0), Number(row.fulfilled_quantity || 0)];
      }), 5) + '</section>';
  }

  function buildTable(className, headers, rows, colspan) {
    const body = rows.length ? rows.map(function (row) {
      return '<tr class="metis-premium-row">' + row.map(function (cell) {
        return '<td class="metis-premium-cell">' + esc(String(cell)) + '</td>';
      }).join('') + '</tr>';
    }).join('') : '<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="' + colspan + '">No data for selected period.</td></tr>';
    return '<table class="metis-premium-table ' + className + '"><thead><tr class="metis-premium-row metis-premium-header">' +
      headers.map(function (header) { return '<th class="metis-premium-cell" scope="col">' + esc(header) + '</th>'; }).join('') +
      '</tr></thead><tbody>' + body + '</tbody></table>';
  }

  function hydrateReportDateInputs() {
    const params = new URLSearchParams(window.location.search);
    if (ui.reportFrom && params.get('from')) ui.reportFrom.value = String(params.get('from'));
    if (ui.reportTo && params.get('to')) ui.reportTo.value = String(params.get('to'));
  }

  function request(action, body) {
    const params = new URLSearchParams({ action: action, nonce: ajax.nonce || '' });
    Object.entries(body || {}).forEach(function (entry) {
      params.append(entry[0], entry[1]);
    });
    return fetch(ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: params })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || !payload.success) {
          throw new Error(payload?.data?.message || 'Request failed');
        }
        return payload.data || {};
      });
  }

  function fillForm(form, values) {
    if (!form || !values) return;
    Object.keys(values).forEach(function (key) {
      setField(form, key, values[key]);
    });
  }

  function formToObject(form) {
    const data = {};
    Array.from(new FormData(form).entries()).forEach(function (entry) {
      data[entry[0]] = entry[1];
    });
    return data;
  }

  function setField(form, name, value) {
    const element = form?.elements?.namedItem(name);
    if (element) element.value = value == null ? '' : String(value);
  }

  function shortDate(value) {
    if (!value) return '';
    if (window.Metis && Metis.time && typeof Metis.time.formatDate === 'function') {
      return Metis.time.formatDate(value, { empty: String(value) }) || String(value);
    }
    const date = new Date(String(value).replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function buildTicketUrl(code) {
    const normalized = String(code || '').trim();
    if (!normalized) return '#';
    return viewBaseUrl + encodeURIComponent(normalized);
  }

  function parseJson(raw, fallback) {
    try {
      return JSON.parse(raw || '');
    } catch (err) {
      return fallback;
    }
  }

  function labelize(value) {
    return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, function (char) { return char.toUpperCase(); });
  }

  function openModal(node) {
    node?.classList.add('metis-open');
    if (node) node.setAttribute('aria-hidden', 'false');
  }

  function closeModal(node) {
    node?.classList.remove('metis-open');
    if (node) node.setAttribute('aria-hidden', 'true');
  }

  function mountModalToBody(node) {
    if (!node || node.dataset.bodyMounted === '1') return;
    document.body.appendChild(node);
    node.dataset.bodyMounted = '1';
  }

  function showAlert(message, type) {
    if (!ui.alert) return;
    ui.alert.className = 'metis-alert ' + (type === 'error' ? 'metis-alert-error' : 'metis-alert-success');
    ui.alert.textContent = message;
    ui.alert.style.display = 'block';
    window.setTimeout(function () {
      if (ui.alert) ui.alert.style.display = 'none';
    }, 4000);
  }

  function qs(selector) {
    return root.querySelector(selector);
  }

  function qsa(selector) {
    return Array.from(root.querySelectorAll(selector));
  }

  function esc(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
    });
  }
}());
