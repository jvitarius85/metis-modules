(function () {
  const root = document.querySelector('.metis-stash-app');
  const bootNode = document.querySelector('#metis-stash-boot');
  const ajax = window.metisGrandyStashAjax || {};
  if (!root || !bootNode) return;

  let state = parseJson(bootNode.textContent, { stats: {}, tickets: [], assignees: [] });
  let currentFilter = 'action';
  let searchQuery = '';
  let currentTicketId = 0;
  const canManage = root.dataset.canManage === '1';
  const currentUserId = parseInt(ajax.user_id || '0', 10);
  const isTicketPage = root.dataset.ticketPage === '1';
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
    ticketGroupSection: qs('#metis-stash-ticket-group-section'),
    ticketGroup: qs('#metis-stash-ticket-group'),
    ticketForm: qs('#metis-stash-ticket-form'),
    ticketAssignee: qs('#metis-stash-ticket-assignee'),
    replySubject: qs('#metis-stash-reply-subject'),
    replyInput: qs('#metis-stash-reply-input'),
    replySubmit: qs('#metis-stash-reply-submit'),
    noteInput: qs('#metis-stash-note-input'),
    noteSubmit: qs('#metis-stash-note-submit'),
    newTicketModal: qs('#metis-stash-new-ticket-modal'),
    newTicketForm: qs('#metis-stash-new-ticket-form'),
  };

  filterRows();
  if (isTicketPage) {
    const initialTicketId = parseInt(root.dataset.ticketId || '0', 10);
    if (initialTicketId > 0) {
      openTicketDetail(initialTicketId);
    }
  }

  // ─── Events ────────────────────────────────────────

  root.addEventListener('click', async function (e) {
    const close = e.target.closest('[data-close-modal]');
    if (close) { closeModal(qs('#' + close.dataset.closeModal)); return; }

    if (e.target.closest('#metis-stash-new-ticket-open')) {
      if (ui.newTicketForm) ui.newTicketForm.reset();
      openModal(ui.newTicketModal);
      return;
    }

    const filterBtn = e.target.closest('[data-filter]');
    if (filterBtn) {
      qsa('.metis-stash-sidebar-filter').forEach(b => {
        b.classList.remove('is-active');
        b.classList.add('metis-btn-ghost');
      });
      filterBtn.classList.add('is-active');
      filterBtn.classList.remove('metis-btn-ghost');
      currentFilter = filterBtn.dataset.filter;
      filterRows();
      return;
    }

    const reviewBtn = e.target.closest('[data-ticket-url], [data-ticket-id]');
    if (reviewBtn) {
      const ticketUrl = String(reviewBtn.dataset.ticketUrl || '');
      if (ticketUrl) {
        window.location.href = ticketUrl;
        return;
      }
      await openTicketDetail(parseInt(reviewBtn.dataset.ticketId, 10));
      return;
    }

    const itemAction = e.target.closest('[data-item-action]');
    if (itemAction && canManage) {
      await updateItemStatus(parseInt(itemAction.dataset.itemId, 10), itemAction.dataset.itemAction);
      return;
    }
  });

  ui.search?.addEventListener('input', function () {
    searchQuery = (ui.search.value || '').toLowerCase().trim();
    filterRows();
  });

  ui.ticketForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    try {
      await request('metis_grandys_stash_save_ticket', { payload: JSON.stringify(formToObject(ui.ticketForm)) });
      if (isTicketPage && currentTicketId > 0) {
        await openTicketDetail(currentTicketId);
        showAlert('Ticket updated.');
      } else {
        await refreshState('Ticket updated.');
      }
    } catch (err) { showAlert(err.message, 'error'); }
  });

  ui.noteSubmit?.addEventListener('click', async function () {
    if (!canManage || !ui.noteInput) return;
    const content = ui.noteInput.value.trim();
    if (!content || currentTicketId < 1) return;
    try {
      const data = await request('metis_grandys_stash_add_note', { ticket_id: currentTicketId, content: content });
      ui.noteInput.value = '';
      renderNotes(data.notes || []);
      renderActivity(data.activity || []);
    } catch (err) { showAlert(err.message, 'error'); }
  });

  ui.replySubmit?.addEventListener('click', async function () {
    if (!canManage || !ui.replyInput) return;
    const content = ui.replyInput.value.trim();
    const subject = ui.replySubject?.value?.trim() || '';
    if (!content || currentTicketId < 1) return;
    try {
      ui.replySubmit.disabled = true;
      const data = await request('metis_grandys_stash_send_reply', {
        ticket_id: currentTicketId,
        subject: subject,
        content: content
      });
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
    if (!canManage) return;
    try {
      await request('metis_grandys_stash_create_ticket', { payload: JSON.stringify(formToObject(ui.newTicketForm)) });
      closeModal(ui.newTicketModal);
      await refreshState('Ticket created.');
    } catch (err) { showAlert(err.message, 'error'); }
  });

  // ─── Filter rows ───────────────────────────────────

  function filterRows() {
    if (!ui.rows) return;
    const rows = qsa('.metis-stash-row');
    rows.forEach(function (row) {
      const status = row.dataset.status || '';
      const type = row.dataset.type || '';
      const assigned = row.dataset.assigned || '';
      const searchBlob = row.dataset.search || '';

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
          visible = parseInt(assigned || '0', 10) === currentUserId && !['COMPLETED', 'CLOSED'].includes(status);
        } else if (currentFilter === 'recent') {
          visible = true;
        }
      }

      row.style.display = visible ? '' : 'none';
    });
  }

  // ─── Ticket detail ─────────────────────────────────

  async function openTicketDetail(ticketId) {
    currentTicketId = ticketId;
    try {
      const data = await request('metis_grandys_stash_ticket_detail', { ticket_id: ticketId });
      const ticket = data.ticket || {};

      renderTicketHeader(ticket);
      renderTicketItems(data.items || []);
      renderConversation(data.messages || []);
      renderNotes(data.notes || []);
      renderActivity(data.activity || []);
      renderGroup(data.group || null);
      populateTicketForm(ticket);
    } catch (err) { showAlert(err.message, 'error'); }
  }

  function renderTicketHeader(t) {
    if (!ui.ticketHeader) return;
    const rows = [
      kv('Name', t.submit_name), kv('Email', t.submit_email), kv('Phone', t.submit_phone),
      kv('Type', labelize(t.type)), kv('Status', t.status), kv('Urgency', labelize(t.urgency)),
      kv('Source', labelize(t.source)), kv('Coordination', labelize(t.pickup_delivery)),
      kv('Submitted', shortDate(t.submitted_at)), t.closed_at ? kv('Closed', shortDate(t.closed_at)) : '',
    ].filter(Boolean).join('');
    const notes = t.submit_notes ? '<div style="margin-top:8px;"><strong class="metis-muted" style="font-size:12px;">Submitter notes</strong><p style="margin:4px 0 0;">' + esc(t.submit_notes) + '</p></div>' : '';
    ui.ticketHeader.innerHTML = '<div class="metis-stash-detail-grid">' + rows + '</div>' + notes;
  }

  function renderTicketItems(items) {
    if (!ui.ticketItems) return;
    if (!items.length) { ui.ticketItems.innerHTML = '<div class="metis-muted">No line items.</div>'; return; }
    ui.ticketItems.innerHTML = '<table class="metis-premium-table"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell" scope="col">Item</th><th class="metis-premium-cell" scope="col">Category</th><th class="metis-premium-cell" scope="col">Qty</th><th class="metis-premium-cell" scope="col">Status</th>' + (canManage ? '<th class="metis-premium-cell" scope="col">Actions</th>' : '') + '</tr></thead><tbody>' +
      items.map(function (item) {
        const actions = canManage ? '<td class="metis-premium-cell"><div class="metis-stash-item-actions">' +
          (item.status !== 'available' ? '<button class="metis-btn-xs metis-btn-ghost" data-item-id="' + item.id + '" data-item-action="available">Available</button>' : '') +
          (item.status !== 'fulfilled' ? '<button class="metis-btn-xs metis-btn-ghost" data-item-id="' + item.id + '" data-item-action="fulfilled">Fulfilled</button>' : '') +
          (item.status !== 'unavailable' ? '<button class="metis-btn-xs metis-btn-ghost" data-item-id="' + item.id + '" data-item-action="unavailable">Unavailable</button>' : '') +
          '</div>' +
          '</td>' : '';
        return '<tr class="metis-premium-row"><td class="metis-premium-cell"><strong>' + esc(item.item_name || item.category) + '</strong>' + (item.condition_status ? '<div class="metis-muted">' + labelize(item.condition_status) + '</div>' : '') + '</td>' +
          '<td class="metis-premium-cell">' + esc(item.category || 'Other') + '</td>' +
          '<td class="metis-premium-cell">' + (item.quantity || 1) + '</td>' +
          '<td class="metis-premium-cell"><span class="metis-stash-status-badge metis-stash-status-' + (item.status || 'pending') + '">' + esc(item.status) + '</span>' + (item.waitlist_at ? '<div class="metis-muted">Since ' + shortDate(item.waitlist_at) + '</div>' : '') + '</td>' +
          actions + '</tr>';
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
      const title = esc(message.subject || '(No subject)');
      const author = esc(message.author_label || message.sender_email || 'System');
      const recipient = esc(message.recipient_email || '');
      const when = shortDate(message.timeline_at || message.message_at || message.sent_at || message.received_at || message.created_at);
      const body = esc(message.body_text_display || '').replace(/\n/g, '<br>');
      const attachments = Array.isArray(message.attachments) ? message.attachments : [];
      const attachmentLinks = attachments.filter(function (attachment) {
        return attachment && (attachment.download_url || attachment.media_url);
      }).map(function (attachment) {
        const url = esc(String(attachment.download_url || attachment.media_url || ''));
        const name = esc(String(attachment.file_name || attachment.filename || 'Attachment'));
        return '<a class="metis-stash-conversation-attachment" href="' + url + '" target="_blank" rel="noopener">' + name + '</a>';
      }).join('');
      const attachmentCount = attachments.length;

      return '<article class="metis-stash-conversation-entry metis-stash-conversation-' + direction + '">' +
        '<div class="metis-stash-conversation-head">' +
          '<div class="metis-stash-conversation-meta">' +
            '<span class="metis-stash-status-badge">' + esc(direction === 'outbound' ? 'Staff Reply' : 'Public Reply') + '</span>' +
            '<strong>' + author + '</strong>' +
            (direction === 'outbound' && recipient ? '<span class="metis-muted">to ' + recipient + '</span>' : '') +
            (direction === 'inbound' && recipient ? '<span class="metis-muted">to ' + recipient + '</span>' : '') +
          '</div>' +
          '<div class="metis-muted">' + when + '</div>' +
        '</div>' +
        '<div class="metis-stash-conversation-subject">' + title + '</div>' +
        '<div class="metis-stash-conversation-body">' + (body || '<span class="metis-muted">No message body.</span>') + '</div>' +
        (attachmentLinks ? '<div class="metis-stash-conversation-attachments">' + attachmentLinks + '</div>' : '') +
        '<div class="metis-stash-conversation-foot">' +
          '<span class="metis-muted">Status: ' + esc(labelize(status)) + '</span>' +
          (attachmentCount ? '<span class="metis-muted">' + attachmentCount + ' attachment' + (attachmentCount === 1 ? '' : 's') + '</span>' : '') +
          (message.error_message ? '<span class="metis-muted">Error: ' + esc(message.error_message) + '</span>' : '') +
        '</div>' +
      '</article>';
    }).join('');
  }

  function renderNotes(notes) {
    if (!ui.ticketNotes) return;
    if (!notes.length) { ui.ticketNotes.innerHTML = '<div class="metis-muted">No notes yet.</div>'; return; }
    ui.ticketNotes.innerHTML = notes.map(function (n) {
      return '<div class="metis-stash-note-entry"><div class="metis-muted" style="font-size:12px;">' + esc(n.author_name || 'System') + ' · ' + shortDate(n.created_at) + '</div><p style="margin:4px 0 0;">' + esc(n.content) + '</p></div>';
    }).join('');
  }

  function renderActivity(activity) {
    if (!ui.ticketActivity) return;
    if (!activity.length) { ui.ticketActivity.innerHTML = '<div class="metis-muted">No activity.</div>'; return; }
    ui.ticketActivity.innerHTML = activity.map(function (a) {
      return '<div class="metis-stash-activity-entry"><span class="metis-stash-status-badge">' + labelize(a.action) + '</span> <span class="metis-muted">' + (a.detail ? esc(a.detail) + ' · ' : '') + (a.author_name || 'System') + ' · ' + shortDate(a.created_at) + '</span></div>';
    }).join('');
  }

  function renderGroup(group) {
    if (!ui.ticketGroupSection || !ui.ticketGroup) return;
    if (!group) { ui.ticketGroupSection.style.display = 'none'; return; }
    ui.ticketGroupSection.style.display = '';
    const others = (group.tickets || []).filter(t => t.id !== currentTicketId);
    ui.ticketGroup.innerHTML = '<div class="metis-stash-detail-grid">' + kv('Group', group.code) + kv('Name', group.name) + kv('Email', group.email) + kv('Phone', group.phone) + '</div>' +
      (others.length ? '<div style="margin-top:8px;"><strong class="metis-muted" style="font-size:12px;">Other tickets</strong>' +
        others.map(t => '<div class="metis-muted" style="margin-top:4px;">' + esc(t.code) + ' · ' + labelize(t.type) + ' · ' + t.status + ' · ' + shortDate(t.submitted_at) + '</div>').join('') +
      '</div>' : '') +
      (canManage ? '<div style="margin-top:8px;"><button class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-stash-unlink-group">Unlink from group</button></div>' : '');

    qs('#metis-stash-unlink-group')?.addEventListener('click', async function () {
      try {
        await request('metis_grandys_stash_unlink_group', { ticket_id: currentTicketId });
        if (isTicketPage && currentTicketId > 0) {
          await openTicketDetail(currentTicketId);
          showAlert('Ticket unlinked.');
        } else {
          await refreshState('Ticket unlinked.');
        }
      } catch (err) { showAlert(err.message, 'error'); }
    });
  }

  function populateTicketForm(ticket) {
    if (!ui.ticketForm) return;
    setField(ui.ticketForm, 'id', ticket.id);
    setField(ui.ticketForm, 'status', ticket.status);
    setField(ui.ticketForm, 'assigned_to', ticket.assigned_to || '');
    setField(ui.ticketForm, 'urgency', ticket.urgency || 'standard');
    if (ui.replySubject) {
      ui.replySubject.value = defaultReplySubject(ticket);
    }
    if (ui.replyInput) {
      ui.replyInput.value = '';
    }
  }

  function defaultReplySubject(ticket) {
    const code = String(ticket.code || '').trim();
    const name = String(ticket.submit_name || '').trim();
    if (code && name) return '[' + code + '] Grandy\'s Stash Update for ' + name;
    if (code) return '[' + code + '] Grandy\'s Stash Update';
    return 'Grandy\'s Stash Update';
  }

  // ─── Actions ───────────────────────────────────────

  async function updateItemStatus(itemId, status) {
    try {
      await request('metis_grandys_stash_update_item_status', { item_id: itemId, status: status });
      await openTicketDetail(currentTicketId);
      showAlert('Item updated.');
    } catch (err) { showAlert(err.message, 'error'); }
  }

  async function refreshState(message) {
    try {
      const data = await request('metis_grandys_stash_state');
      state = data.state || state;
      rebuildRows();
      if (message) showAlert(message);
    } catch (err) { showAlert(err.message, 'error'); }
  }

  function rebuildRows() {
    if (!ui.rows) return;
    const tickets = state.tickets || [];
    ui.rows.innerHTML = tickets.map(function (t) {
      const typeLabel = t.type === 'donation' ? 'Donation' : 'Request';
      const status = t.status || 'NEW';
      const name = esc(t.submit_name || 'Unknown');
      const code = esc(t.code || '');
      const urgency = labelize(t.urgency);
      const assigned = esc(t.assigned_name || '\u2014');
      const items = t.item_count || 0;
      const date = shortDate(t.submitted_at);
      const groupCode = t.group_code ? esc(t.group_code) : '';
      const search = [code, name, typeLabel, status, assigned, groupCode, t.submit_email || '', t.items_summary || ''].join(' ').toLowerCase();

      return '<tr class="metis-premium-row metis-stash-row" data-id="' + t.id + '" data-status="' + status + '" data-type="' + t.type + '" data-assigned="' + (t.assigned_to || '') + '" data-search="' + esc(search) + '">' +
        '<td class="metis-premium-cell"><strong>' + code + '</strong>' + (groupCode ? '<div class="metis-muted">' + groupCode + '</div>' : '') + '</td>' +
        '<td class="metis-premium-cell">' + name + '</td>' +
        '<td class="metis-premium-cell"><span class="metis-stash-type-badge metis-stash-type-' + t.type + '">' + typeLabel + '</span></td>' +
        '<td class="metis-premium-cell"><span class="metis-stash-status-badge metis-stash-status-' + status.toLowerCase() + '">' + status + '</span></td>' +
        '<td class="metis-premium-cell">' + urgency + '</td>' +
        '<td class="metis-premium-cell">' + assigned + '</td>' +
        '<td class="metis-premium-cell">' + items + '</td>' +
        '<td class="metis-premium-cell">' + date + '</td>' +
        (canManage ? '<td class="metis-premium-cell"><a class="metis-btn-xs" href="' + esc(buildTicketUrl(t.code || '')) + '" data-ticket-url="' + esc(buildTicketUrl(t.code || '')) + '">Review</a></td>' : '') +
      '</tr>';
    }).join('');
    filterRows();
  }

  // ─── Helpers ───────────────────────────────────────

  function request(action, body) {
    const params = new URLSearchParams({ action: action, nonce: ajax.nonce || '' });
    Object.entries(body || {}).forEach(([k, v]) => params.append(k, v));
    return fetch(ajax.ajax_url, { method: 'POST', credentials: 'same-origin', body: params })
      .then(r => r.json())
      .then(d => {
        if (!d || !d.success) throw new Error(d?.data?.message || 'Request failed');
        return d.data || {};
      });
  }

  function kv(label, value) {
    if (!value) return '';
    return '<div><strong class="metis-muted" style="font-size:12px;">' + esc(label) + '</strong><div>' + esc(String(value)) + '</div></div>';
  }

  function openModal(n) { n?.classList.add('metis-open'); if (n) n.setAttribute('aria-hidden', 'false'); }
  function closeModal(n) { n?.classList.remove('metis-open'); if (n) n.setAttribute('aria-hidden', 'true'); }
  function buildTicketUrl(code) {
    const normalized = String(code || '').trim();
    if (!normalized) return '#';
    return viewBaseUrl + encodeURIComponent(normalized);
  }
  function formToObject(f) { const d = {}; Array.from(new FormData(f).entries()).forEach(([k,v]) => d[k]=v); return d; }
  function setField(f, n, v) { const el = f?.elements?.namedItem(n); if (el) el.value = v || ''; }
  function shortDate(v) { if (!v) return ''; const d = new Date(String(v).replace(' ','T')+'Z'); if (isNaN(d.getTime())) return String(v); return d.toLocaleString('default',{month:'short'})+' '+d.getDate()+', '+d.getFullYear(); }
  function labelize(v) { return String(v||'').replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()); }
  function showAlert(m,t) { if(!ui.alert)return; ui.alert.className='metis-alert '+(t==='error'?'metis-alert-error':'metis-alert-success'); ui.alert.textContent=m; ui.alert.style.display='block'; setTimeout(()=>{ui.alert.style.display='none';},4000); }
  function parseJson(r,f) { try{return JSON.parse(r||'');}catch(e){return f;} }
  function qs(s) { return root.querySelector(s); }
  function qsa(s) { return Array.from(root.querySelectorAll(s)); }
  function esc(v) { return String(v||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }

  // ─── Settings page handlers ────────────────────────

  const routingForm = qs('#metis-stash-routing-form');
  routingForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    try {
      await request('metis_grandys_stash_save_routing_defaults', { payload: JSON.stringify(formToObject(routingForm)) });
      showAlert('Routing defaults saved.');
    } catch (err) { showAlert(err.message, 'error'); }
  });

  root.addEventListener('change', async function (e) {
    const toggle = e.target.closest('.metis-stash-email-toggle');
    if (!toggle || !canManage) return;
    const userId = toggle.dataset.userId;
    const enabled = toggle.checked ? '1' : '0';
    try {
      await request('metis_grandys_stash_set_email_pref', { user_id: userId, enabled: enabled });
      const label = toggle.parentElement?.querySelector('span');
      if (label) label.textContent = toggle.checked ? 'Yes' : 'No';
      showAlert('Email preference updated.');
    } catch (err) {
      toggle.checked = !toggle.checked;
      showAlert(err.message, 'error');
    }
  });


  // ─── Report page handlers ─────────────────────────

  const reportRunBtn = qs('#metis-stash-report-run');
  const reportFrom = qs('#metis-stash-report-from');
  const reportTo = qs('#metis-stash-report-to');
  const reportContent = qs('#metis-stash-report-content');

  reportRunBtn?.addEventListener('click', async function () {
    const from = reportFrom?.value || '';
    const to = reportTo?.value || '';
    try {
      reportRunBtn.textContent = 'Loading...';
      reportRunBtn.disabled = true;
      const data = await request('metis_grandys_stash_report', { from: from, to: to });
      const r = data.report || {};
      if (reportContent) {
        reportContent.innerHTML = buildReportHTML(r);
      }
      showAlert('Report updated.');
    } catch (err) {
      showAlert(err.message, 'error');
    } finally {
      reportRunBtn.textContent = 'Run Report';
      reportRunBtn.disabled = false;
    }
  });

  function buildReportHTML(r) {
    const s = r.summary || {};
    let html = '';

    // Update KPI strip if present
    const kpis = root.querySelectorAll('.metis-people-stat-value');
    if (kpis.length >= 6) {
      kpis[0].textContent = s.total_tickets || 0;
      kpis[1].textContent = r.people_served || 0;
      kpis[2].textContent = r.items_fulfilled || 0;
      kpis[3].textContent = s.completed || 0;
      kpis[4].textContent = r.avg_days_to_complete || '\u2014';
      kpis[5].textContent = s.open_tickets || 0;
    }

    // By Category
    html += '<section style="margin-bottom:28px;"><h2 style="font-size:16px;margin:0 0 12px;">Items by Category</h2>';
    html += '<table class="metis-premium-table metis-stash-report-cat-table"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell" scope="col">Category</th><th class="metis-premium-cell" scope="col">Total Items</th><th class="metis-premium-cell" scope="col">Fulfilled</th></tr></thead><tbody>';
    (r.by_category || []).forEach(function (c) {
      html += '<tr class="metis-premium-row"><td class="metis-premium-cell">' + esc(labelize(c.category)) + '</td><td class="metis-premium-cell">' + (c.item_count || 0) + '</td><td class="metis-premium-cell">' + (c.fulfilled || 0) + '</td></tr>';
    });
    if (!r.by_category || !r.by_category.length) html += '<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="3">No data for selected period.</td></tr>';
    html += '</tbody></table></section>';

    // Monthly
    html += '<section style="margin-bottom:28px;"><h2 style="font-size:16px;margin:0 0 12px;">Monthly Breakdown</h2>';
    html += '<table class="metis-premium-table metis-stash-report-month-table"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell" scope="col">Month</th><th class="metis-premium-cell" scope="col">Tickets</th><th class="metis-premium-cell" scope="col">Requests</th><th class="metis-premium-cell" scope="col">Donations</th><th class="metis-premium-cell" scope="col">Completed</th></tr></thead><tbody>';
    (r.monthly || []).forEach(function (m) {
      html += '<tr class="metis-premium-row"><td class="metis-premium-cell">' + esc(m.month || '') + '</td><td class="metis-premium-cell">' + (m.tickets || 0) + '</td><td class="metis-premium-cell">' + (m.requests || 0) + '</td><td class="metis-premium-cell">' + (m.donations || 0) + '</td><td class="metis-premium-cell">' + (m.completed || 0) + '</td></tr>';
    });
    if (!r.monthly || !r.monthly.length) html += '<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="5">No data for selected period.</td></tr>';
    html += '</tbody></table></section>';

    // Urgency + Source side by side
    html += '<div class="metis-stash-report-split">';
    html += '<section><h2 style="font-size:16px;margin:0 0 12px;">By Urgency</h2><table class="metis-premium-table metis-stash-report-small-table"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell" scope="col">Urgency</th><th class="metis-premium-cell" scope="col">Count</th></tr></thead><tbody>';
    (r.by_urgency || []).forEach(function (u) {
      html += '<tr class="metis-premium-row"><td class="metis-premium-cell">' + esc(labelize(u.urgency)) + '</td><td class="metis-premium-cell">' + (u.count || 0) + '</td></tr>';
    });
    html += '</tbody></table></section>';
    html += '<section><h2 style="font-size:16px;margin:0 0 12px;">By Source</h2><table class="metis-premium-table metis-stash-report-small-table"><thead><tr class="metis-premium-row metis-premium-header"><th class="metis-premium-cell" scope="col">Source</th><th class="metis-premium-cell" scope="col">Count</th></tr></thead><tbody>';
    (r.by_source || []).forEach(function (src) {
      html += '<tr class="metis-premium-row"><td class="metis-premium-cell">' + esc(labelize(src.source)) + '</td><td class="metis-premium-cell">' + (src.count || 0) + '</td></tr>';
    });
    html += '</tbody></table></section>';
    html += '</div>';

    return html;
  }


  // ─── Reports page handler ─────────────────────────

  const reportBtn = qs('#metis-stash-report-run');
  reportBtn?.addEventListener('click', function () {
    const from = qs('#metis-stash-report-from')?.value || '';
    const to = qs('#metis-stash-report-to')?.value || '';
    const params = new URLSearchParams(window.location.search);
    if (from) params.set('from', from); else params.delete('from');
    if (to) params.set('to', to); else params.delete('to');
    window.location.search = params.toString();
  });

  // Pre-fill date inputs from URL params
  const urlParams = new URLSearchParams(window.location.search);
  const fromInput = qs('#metis-stash-report-from');
  const toInput = qs('#metis-stash-report-to');
  if (fromInput && urlParams.get('from')) fromInput.value = urlParams.get('from');
  if (toInput && urlParams.get('to')) toInput.value = urlParams.get('to');

}());
