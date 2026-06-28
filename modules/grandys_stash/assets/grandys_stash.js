(function () {
  const root = document.querySelector('.metis-stash-app');
  const bootNode = document.querySelector('#metis-stash-boot');
  const ajax = window.metisGrandyStashAjax || {};
  if (!root || !bootNode) return;

  let state = parseJson(bootNode.textContent, { stats: {}, tickets: [], assignees: [], groups: [], organizations: [], report: null, reportPage: { rows: [], pagination: {} }, reportOptions: {}, reportFilters: {} });
  let currentFilter = normalizeFilter('action');
  let currentSort = normalizeSort('submitted_desc');
  let currentTicketId = 0;
  let selectedManagerId = 0;
  let selectedManagerKind = '';
  let reportDrilldown = null;
  let reportSort = { field: 'submitted_at', direction: 'desc' };
  let reportRefreshTimer = 0;

  const canManage = root.dataset.canManage === '1';
  const canCreate = root.dataset.canCreate === '1';
  const canAssign = root.dataset.canAssign === '1';
  const canComment = root.dataset.canComment === '1';
  const canReply = root.dataset.canReply === '1';
  const canInventory = root.dataset.canInventory === '1';
  const canDelete = root.dataset.canDelete === '1';
  const canBulkDelete = root.dataset.canBulkDelete === '1';
  const canExport = root.dataset.canExport === '1';
  const currentUserId = parseInt(root.dataset.currentPersonId || ajax.person_id || ajax.user_id || '0', 10);
  const isTicketPage = root.dataset.ticketPage === '1';
  const stashView = String(root.dataset.stashView || '');
  const viewBaseUrl = String(root.dataset.viewBaseUrl || '');

  const ui = {
    rows: qs('#metis-stash-rows'),
    search: qs('#metis-stash-search'),
    sort: qs('#metis-stash-sort'),
    bulkDelete: qs('#metis-stash-bulk-delete'),
    selectAll: qs('#metis-stash-select-all'),
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
    legacySettingsForm: qs('#metis-stash-legacy-settings-form'),
    legacyPreviewForm: qs('#metis-stash-legacy-preview-form'),
    legacyPreviewJson: qs('#metis-stash-legacy-preview-json'),
    legacyAuditForm: qs('#metis-stash-legacy-audit-form'),
    legacyAuditResults: qs('#metis-stash-legacy-audit-results'),
    legacyImportForm: qs('#metis-stash-legacy-import-form'),
    legacyWipeForm: qs('#metis-stash-legacy-wipe-form'),
    legacyItemRepairForm: qs('#metis-stash-legacy-item-repair-form'),
    orgResolution: qs('#metis-stash-org-resolution'),
    itemResolution: qs('#metis-stash-item-resolution'),
    reportRunBtn: qs('#metis-stash-report-run'),
    reportForm: qs('#metis-stash-report-filter-form'),
    reportFrom: qs('#metis-stash-report-from'),
    reportTo: qs('#metis-stash-report-to'),
    reportClear: qs('#metis-stash-report-clear'),
    reportContent: qs('#metis-stash-report-content'),
    reportTotalTickets: qs('#metis-stash-report-total-tickets'),
    reportPeopleServed: qs('#metis-stash-report-people-served'),
    reportItemsFulfilled: qs('#metis-stash-report-items-fulfilled'),
    reportCompleted: qs('#metis-stash-report-completed'),
    reportAvgDays: qs('#metis-stash-report-avg-days'),
    reportOpen: qs('#metis-stash-report-open'),
    reportRangeText: qs('#metis-stash-report-range-text'),
    reportOrgCount: qs('#metis-stash-report-org-count'),
    reportPersonCount: qs('#metis-stash-report-person-count'),
    reportEquipmentCount: qs('#metis-stash-report-equipment-count'),
    reportCategoryBody: qs('#metis-stash-report-category-body'),
    reportMonthlyBody: qs('#metis-stash-report-monthly-body'),
    reportUrgencyBody: qs('#metis-stash-report-urgency-body'),
    reportSourceBody: qs('#metis-stash-report-source-body'),
    reportOrganizationBody: qs('#metis-stash-report-organization-body'),
    reportPersonBody: qs('#metis-stash-report-person-body'),
    reportEquipmentBody: qs('#metis-stash-report-equipment-body'),
    reportDrilldown: qs('#metis-stash-report-drilldown'),
    reportDrilldownTitle: qs('#metis-stash-report-drilldown-title'),
    reportDrilldownSubtitle: qs('#metis-stash-report-drilldown-subtitle'),
    reportDrilldownSearch: qs('#metis-stash-report-drilldown-search'),
    reportDrilldownCount: qs('#metis-stash-report-drilldown-count'),
    reportDrilldownBody: qs('#metis-stash-report-drilldown-body'),
    reportDrilldownClear: qs('#metis-stash-report-drilldown-clear'),
    reportExportPdf: qs('#metis-stash-report-export-pdf'),
    reportBuilderCategory: qs('#metis-stash-report-builder-category'),
    reportBuilderItem: qs('#metis-stash-report-builder-item'),
    reportBuilderOrganization: qs('#metis-stash-report-builder-organization'),
    reportBuilderPerson: qs('#metis-stash-report-builder-person'),
    reportBuilderUrgency: qs('#metis-stash-report-builder-urgency'),
    reportBuilderType: qs('#metis-stash-report-builder-type'),
    reportBuilderStatus: qs('#metis-stash-report-builder-status'),
    reportBuilderAssigned: qs('#metis-stash-report-builder-assigned'),
    reportBuilderItemOptions: qs('#metis-stash-report-item-options'),
    reportBuilderOrganizationOptions: qs('#metis-stash-report-organization-options'),
    reportBuilderPersonOptions: qs('#metis-stash-report-person-options'),
    reportTrendGraph: qs('#metis-stash-report-trend-graph'),
    reportPerPage: qs('#metis-stash-report-per-page'),
    reportPagePrev: qs('#metis-stash-report-page-prev'),
    reportPageNext: qs('#metis-stash-report-page-next'),
    reportPageInfo: qs('#metis-stash-report-page-info'),
    groupRows: qs('#metis-stash-group-rows'),
    groupSearch: qs('#metis-stash-group-search'),
    groupModal: qs('#metis-stash-group-modal'),
    groupModalTitle: qs('#metis-stash-group-modal-title'),
    groupModalSubtitle: qs('#metis-stash-group-modal-subtitle'),
    groupModalSummary: qs('#metis-stash-group-modal-summary'),
    groupForm: qs('#metis-stash-group-form'),
    groupTicketList: qs('#metis-stash-group-ticket-list'),
    organizationRows: qs('#metis-stash-organization-rows'),
    organizationSearch: qs('#metis-stash-organization-search'),
    organizationModal: qs('#metis-stash-organization-modal'),
    organizationModalTitle: qs('#metis-stash-organization-modal-title'),
    organizationModalSubtitle: qs('#metis-stash-organization-modal-subtitle'),
    organizationModalSummary: qs('#metis-stash-organization-modal-summary'),
    organizationForm: qs('#metis-stash-organization-form'),
    organizationLinkForm: qs('#metis-stash-organization-link-form'),
    organizationMergeForm: qs('#metis-stash-organization-merge-form'),
    organizationIndependentForm: qs('#metis-stash-organization-independent-form'),
    organizationLookup: qs('#metis-stash-organization-lookup'),
    organizationTicketList: qs('#metis-stash-organization-ticket-list'),
  };

  initialize();

  function initialize() {
    mountModalToBody(ui.groupModal);
    mountModalToBody(ui.organizationModal);
    mountModalToBody(ui.newTicketModal);
    hydrateInboxControls();
    filterRows();
    rebuildManagerSelections();
    renderResolutionPanels();
    renderReportView();

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

    const modalBackdrop = e.target.closest('.metis-stash-modal.metis-open');
    if (modalBackdrop && e.target === modalBackdrop) {
      closeModal(modalBackdrop);
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
      if (currentFilter === 'recent') {
        currentSort = 'updated_desc';
        if (ui.sort) ui.sort.value = currentSort;
      }
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

    const reportTrigger = e.target.closest('[data-report-drilldown]');
    if (reportTrigger && stashView === 'reports') {
      openReportDrilldown(
        String(reportTrigger.dataset.reportDrilldown || ''),
        String(reportTrigger.dataset.reportValue || ''),
        String(reportTrigger.dataset.reportLabel || '')
      );
      return;
    }

    const reportSummaryRow = e.target.closest('.metis-stash-report-summary-row[data-report-drilldown]');
    if (reportSummaryRow && stashView === 'reports' && !e.target.closest('a, button, input, select, textarea, label')) {
      openReportDrilldown(
        String(reportSummaryRow.dataset.reportDrilldown || ''),
        String(reportSummaryRow.dataset.reportValue || ''),
        String(reportSummaryRow.dataset.reportLabel || '')
      );
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

    const reportTicketRow = e.target.closest('.metis-stash-report-ticket-row[data-ticket-url]');
    if (reportTicketRow && !e.target.closest('a, button, input, select, textarea, label')) {
      const ticketUrl = String(reportTicketRow.dataset.ticketUrl || '');
      if (ticketUrl) window.location.href = ticketUrl;
      return;
    }

    const itemAction = e.target.closest('[data-item-action]');
    if (itemAction && canInventory) {
      await updateItemStatus(parseInt(itemAction.dataset.itemId || '0', 10), String(itemAction.dataset.itemAction || ''));
      return;
    }

    const resolveOrganizationBtn = e.target.closest('[data-resolution-org-submit]');
    if (resolveOrganizationBtn && canManage) {
      const form = resolveOrganizationBtn.closest('[data-resolution-org-form]');
      if (!form) return;
      const sourceId = Number(form.dataset.sourceId || '0');
      const targetId = Number(form.querySelector('[name="target_id"]')?.value || '0');
      if (sourceId < 1) return;
      try {
        const data = await request('metis_grandys_stash_resolve_org_candidate', {
          payload: JSON.stringify({ source_id: sourceId, target_id: targetId })
        });
        applyStateUpdate(data);
        const movedToIndependent = targetId < 1;
        showAlert(movedToIndependent ? 'Organization moved to Independent.' : 'Organization merged.');
      } catch (err) {
        showAlert(err.message, 'error');
      }
      return;
    }

    const resolveItemBtn = e.target.closest('[data-resolution-item-submit]');
    if (resolveItemBtn && canManage) {
      const form = e.target.closest('[data-resolution-item-form]');
      if (!form) return;
      const signature = String(form.dataset.signature || '').trim();
      const catalogItemId = Number(form.querySelector('[name="catalog_item_id"]')?.value || '0');
      if (!signature || catalogItemId < 1) {
        showAlert('Choose the catalog item to resolve this legacy label.', 'error');
        return;
      }
      try {
        const data = await request('metis_grandys_stash_resolve_item_candidate', {
          payload: JSON.stringify({ signature: signature, catalog_item_id: catalogItemId })
        });
        applyStateUpdate(data);
        showAlert('Legacy item labels resolved.');
      } catch (err) {
        showAlert(err.message, 'error');
      }
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
  ui.sort?.addEventListener('change', function () {
    currentSort = normalizeSort(ui.sort.value || 'submitted_desc');
    filterRows();
  });
  ui.reportForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (stashView !== 'reports') return;
    await refreshReport();
  });
  ui.reportClear?.addEventListener('click', async function () {
    if (ui.reportFrom) ui.reportFrom.value = '';
    if (ui.reportTo) ui.reportTo.value = '';
    if (stashView !== 'reports') return;
    await refreshReport();
  });
  ui.reportDrilldownSearch?.addEventListener('input', function () {
    queueReportRefresh(1);
  });
  ui.reportDrilldownClear?.addEventListener('click', function () {
    clearReportBuilder();
    queueReportRefresh(1, true);
  });
  [
    ui.reportBuilderCategory,
    ui.reportBuilderItem,
    ui.reportBuilderOrganization,
    ui.reportBuilderPerson,
    ui.reportBuilderUrgency,
    ui.reportBuilderType,
    ui.reportBuilderStatus,
    ui.reportBuilderAssigned
  ].forEach(function (node) {
    node?.addEventListener('input', function () { queueReportRefresh(1); });
    node?.addEventListener('change', function () { queueReportRefresh(1, true); });
  });
  ui.reportPerPage?.addEventListener('change', function () {
    queueReportRefresh(1, true);
  });
  ui.reportPagePrev?.addEventListener('click', function () {
    const page = Number(state.reportPage?.pagination?.page || 1);
    if (page > 1) queueReportRefresh(page - 1, true);
  });
  ui.reportPageNext?.addEventListener('click', function () {
    const page = Number(state.reportPage?.pagination?.page || 1);
    const totalPages = Number(state.reportPage?.pagination?.total_pages || 1);
    if (page < totalPages) queueReportRefresh(page + 1, true);
  });
  ui.reportExportPdf?.addEventListener('click', function () {
    if (!canExport) return;
    submitReportPdfExport();
  });
  ui.reportDrilldownBody?.addEventListener('click', function (e) {
    const reportTicketRow = e.target.closest('.metis-stash-report-ticket-row[data-ticket-url]');
    if (!reportTicketRow || e.target.closest('a, button, input, select, textarea, label')) return;
    const ticketUrl = String(reportTicketRow.dataset.ticketUrl || '');
    if (ticketUrl) window.location.assign(ticketUrl);
  });
  ui.reportDrilldownBody?.addEventListener('keydown', function (e) {
    const reportTicketRow = e.target.closest('.metis-stash-report-ticket-row[data-ticket-url]');
    if (!reportTicketRow) return;
    if (e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    const ticketUrl = String(reportTicketRow.dataset.ticketUrl || '');
    if (ticketUrl) window.location.assign(ticketUrl);
  });
  ui.selectAll?.addEventListener('change', syncSelectAllRows);
  ui.groupSearch?.addEventListener('input', filterManagerRows);
  ui.organizationSearch?.addEventListener('input', filterManagerRows);
  ui.organizationMergeForm?.elements?.namedItem('target_lookup')?.addEventListener('input', syncOrganizationMergeLookup);

  root.addEventListener('click', function (e) {
    const sortButton = e.target.closest('[data-report-sort]');
    if (!sortButton || stashView !== 'reports') return;
    const field = String(sortButton.dataset.reportSort || 'submitted_at');
    if (reportSort.field === field) {
      reportSort.direction = reportSort.direction === 'asc' ? 'desc' : 'asc';
    } else {
      reportSort.field = field;
      reportSort.direction = field === 'submitted_at' ? 'desc' : 'asc';
    }
    queueReportRefresh(1, true);
  });

  root.addEventListener('keydown', function (e) {
    const reportSummaryRow = e.target.closest('.metis-stash-report-summary-row[data-report-drilldown]');
    if (reportSummaryRow && stashView === 'reports') {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      e.preventDefault();
      openReportDrilldown(
        String(reportSummaryRow.dataset.reportDrilldown || ''),
        String(reportSummaryRow.dataset.reportValue || ''),
        String(reportSummaryRow.dataset.reportLabel || '')
      );
      return;
    }

    const reportTicketRow = e.target.closest('.metis-stash-report-ticket-row[data-ticket-url]');
    if (!reportTicketRow || stashView !== 'reports') return;
    if (e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    const ticketUrl = String(reportTicketRow.dataset.ticketUrl || '');
    if (ticketUrl) window.location.href = ticketUrl;
  });

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
    if (!(await confirmAction('Delete this ticket and its conversation history? This cannot be undone.', { tone: 'danger', confirmLabel: 'Delete Ticket' }))) return;
    try {
      const data = await request('metis_grandys_stash_delete_ticket', { ticket_id: currentTicketId });
      showAlert(data.deleted_code ? 'Deleted ' + data.deleted_code + '.' : 'Ticket deleted.');
      window.location.href = root.dataset.viewBaseUrl ? root.dataset.viewBaseUrl.replace(/view\/?$/, '') : window.location.origin;
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.bulkDelete?.addEventListener('click', async function () {
    if (!canBulkDelete) return;
    const ticketIds = selectedTicketIds();
    if (!ticketIds.length) {
      showAlert('Select at least one ticket to delete.', 'error');
      return;
    }
    if (!(await confirmAction('Delete ' + ticketIds.length + ' selected ticket' + (ticketIds.length === 1 ? '' : 's') + '? This cannot be undone.', { tone: 'danger', confirmLabel: 'Delete Selected Tickets' }))) return;
    try {
      const data = await request('metis_grandys_stash_delete_tickets', { payload: JSON.stringify({ ticket_ids: ticketIds }) });
      applyStateUpdate(data);
      setBulkDeleteState();
      showAlert('Deleted ' + Number(data.deleted_count || 0) + ' ticket' + (Number(data.deleted_count || 0) === 1 ? '' : 's') + '.');
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
      const data = await request('metis_grandys_stash_save_group', { payload: JSON.stringify(formToObject(ui.groupForm)) });
      applyStateUpdate(data);
      showAlert('Group saved.');
      openManagerModal('group', Number(ui.groupForm.elements.namedItem('id')?.value || '0'));
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.organizationForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    try {
      const data = await request('metis_grandys_stash_save_organization', { payload: JSON.stringify(formToObject(ui.organizationForm)) });
      applyStateUpdate(data);
      showAlert('Organization saved.');
      openManagerModal('organization', Number(data.organization_id || ui.organizationForm.elements.namedItem('id')?.value || '0'));
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

  ui.legacySettingsForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    try {
      await request('metis_grandys_stash_save_legacy_import_settings', { payload: JSON.stringify(formToObject(ui.legacySettingsForm)) });
      await refreshState('Remote legacy source saved.');
      if (ui.legacySettingsForm) {
        const secretField = ui.legacySettingsForm.elements.namedItem('secret');
        if (secretField) secretField.value = '';
      }
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.legacyImportForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    const submitButton = ui.legacyImportForm.querySelector('button[type="submit"]');
    const originalLabel = submitButton?.textContent || 'Import Legacy Tickets';
    try {
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Importing...';
      }
      const data = await request('metis_grandys_stash_import_legacy', { payload: JSON.stringify(formToObject(ui.legacyImportForm)) });
      applyStateUpdate(data);
      if (ui.legacyAuditResults) {
        ui.legacyAuditResults.innerHTML = '';
      }
      await runLegacyAudit({ silent: true });
      const importSummary = data.import || {};
      const errors = Array.isArray(importSummary.errors) ? importSummary.errors : [];
      showAlert(
        errors.length
          ? (importSummary.summary || 'Legacy import finished.') + ' ' + errors.join(' | ')
          : (importSummary.summary || 'Legacy import finished.')
      );
    } catch (err) {
      showAlert(err.message, 'error');
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;
      }
    }
  });

  ui.legacyPreviewForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    const submitButton = ui.legacyPreviewForm.querySelector('button[type="submit"]');
    const originalLabel = submitButton?.textContent || 'Preview Legacy Payload';
    try {
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Loading preview...';
      }
      const data = await request('metis_grandys_stash_preview_legacy', { payload: JSON.stringify(formToObject(ui.legacyPreviewForm)) });
      const preview = data.preview?.data || {};
      const diagnostics = preview.diagnostics || {};
      const summary = [
        'Mode: ' + String(data.preview?.mode || 'remote'),
        'Entries: ' + Number(preview.entry_count || 0),
        'Entries with items: ' + Number(preview.entries_with_items || 0),
        'Entries without items: ' + Number(preview.entries_without_items || 0),
        'Total item rows: ' + Number(preview.total_items || 0),
        diagnostics.child_link_count != null ? 'Child links discovered: ' + Number(diagnostics.child_link_count || 0) : '',
        diagnostics.request_nested_field_id ? 'Request nested field: ' + String(diagnostics.request_nested_field_id) : '',
        diagnostics.request_child_form_id ? 'Request child form: ' + String(diagnostics.request_child_form_id) : '',
        diagnostics.donation_nested_field_id ? 'Donation nested field: ' + String(diagnostics.donation_nested_field_id) : '',
        diagnostics.donation_child_form_id ? 'Donation child form: ' + String(diagnostics.donation_child_form_id) : ''
      ].filter(Boolean).join(' | ');
      if (ui.legacyPreviewJson) {
        ui.legacyPreviewJson.hidden = false;
        ui.legacyPreviewJson.textContent = summary + '\n\n' + JSON.stringify(preview, null, 2);
      }
      showAlert('Legacy preview loaded.');
    } catch (err) {
      if (ui.legacyPreviewJson) {
        ui.legacyPreviewJson.hidden = true;
        ui.legacyPreviewJson.textContent = '';
      }
      showAlert(err.message, 'error');
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;
      }
    }
  });

  async function runLegacyAudit(options) {
    options = options || {};
    if (!canManage || !ui.legacyAuditForm) return null;
    const submitButton = ui.legacyAuditForm.querySelector('button[type="submit"]');
    const originalLabel = submitButton?.textContent || 'Audit Imported Types';
    try {
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Auditing...';
      }
      const data = await request('metis_grandys_stash_audit_legacy_types', { payload: JSON.stringify(formToObject(ui.legacyAuditForm)) });
      renderLegacyAuditResults(data.audit || {});
      if (!options.silent) {
        showAlert(String(data.audit?.summary || 'Legacy type audit complete.'));
      }
      return data;
    } catch (err) {
      if (ui.legacyAuditResults) {
        ui.legacyAuditResults.innerHTML = '';
      }
      if (!options.silent) {
        showAlert(err.message, 'error');
      }
      throw err;
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;
      }
    }
  }

  ui.legacyAuditForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    try {
      await runLegacyAudit({ silent: false });
    } catch (err) {
      // handled inside runLegacyAudit
    }
  });

  ui.legacyWipeForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    if (!(await confirmAction('Wipe all legacy-imported Grandy\'s Stash tickets? This is intended as a one-time cleanup before reimporting.', { tone: 'danger', confirmLabel: 'Wipe Imported Tickets' }))) return;
    try {
      const data = await request('metis_grandys_stash_wipe_legacy_imports', {});
      applyStateUpdate(data);
      const wipe = data.wipe || {};
      const message = 'Deleted ' + Number(wipe.deleted || 0) + ' legacy ticket(s); pruned ' + Number(wipe.pruned_groups || 0) + ' group(s) and ' + Number(wipe.pruned_organizations || 0) + ' organization(s).';
      showAlert(message);
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.legacyItemRepairForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    try {
      const submitButton = ui.legacyItemRepairForm.querySelector('button[type="submit"]');
      const originalLabel = submitButton?.textContent || 'Split Existing Legacy Item Rows';
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = 'Splitting...';
      }
      const data = await request('metis_grandys_stash_repair_legacy_item_rows', { payload: JSON.stringify(formToObject(ui.legacyItemRepairForm)) });
      applyStateUpdate(data);
      showAlert(String(data.repair?.summary || 'Legacy item rows repaired.'));
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;
      }
    } catch (err) {
      const submitButton = ui.legacyItemRepairForm.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.textContent = 'Split Existing Legacy Item Rows';
      }
      showAlert(err.message, 'error');
    }
  });

  ui.organizationLinkForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    try {
      await request('metis_grandys_stash_link_organization_ticket', formToObject(ui.organizationLinkForm));
      await refreshState('Ticket linked to organization.');
      openManagerModal('organization', Number(ui.organizationLinkForm.elements.namedItem('organization_id')?.value || '0'));
      const codeField = ui.organizationLinkForm.elements.namedItem('ticket_code');
      if (codeField) codeField.value = '';
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.organizationMergeForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    if (!syncOrganizationMergeLookup()) {
      showAlert('Choose a destination organization from the lookup results.', 'error');
      return;
    }
    if (!(await confirmAction('Merge this organization into the selected destination? Tickets will be reassigned.', { tone: 'danger', confirmLabel: 'Merge Organizations' }))) return;
    try {
      const mergeData = await request('metis_grandys_stash_merge_organizations', formToObject(ui.organizationMergeForm));
      applyStateUpdate(mergeData);
      const mergedCount = Number(mergeData.merge?.merged_tickets || 0);
      const sourceCode = String(mergeData.merge?.source_code || '');
      const targetCode = String(mergeData.merge?.target_code || '');
      showAlert(
        sourceCode && targetCode
          ? 'Merged ' + sourceCode + ' into ' + targetCode + (mergedCount ? ' (' + mergedCount + ' ticket' + (mergedCount === 1 ? '' : 's') + ').' : '.')
          : 'Organizations merged.'
      );
      const targetOrganization = (state.organizations || []).find(function (organization) {
        return String(organization.code || '') === targetCode;
      });
      if (targetOrganization) openManagerModal('organization', Number(targetOrganization.id || 0));
      const targetField = ui.organizationMergeForm.elements.namedItem('target_code');
      if (targetField) targetField.value = '';
      const targetLookup = ui.organizationMergeForm.elements.namedItem('target_lookup');
      if (targetLookup) targetLookup.value = '';
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  ui.organizationIndependentForm?.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!canManage) return;
    const organizationId = Number(ui.organizationIndependentForm.elements.namedItem('organization_id')?.value || '0');
    if (organizationId < 1) return;
    if (!(await confirmAction('Move every ticket in this organization to Independent and remove this organization shell?', { tone: 'danger', confirmLabel: 'Move to Independent' }))) return;
    try {
      const data = await request('metis_grandys_stash_move_organization_to_independent', { organization_id: String(organizationId) });
      applyStateUpdate(data);
      closeModal(ui.organizationModal);
      const moved = Number(data.move?.ticket_count || 0);
      showAlert('Moved ' + moved + ' ticket' + (moved === 1 ? '' : 's') + ' to Independent.');
    } catch (err) {
      showAlert(err.message, 'error');
    }
  });

  root.addEventListener('change', async function (e) {
    const ticketSelect = e.target.closest('.metis-stash-ticket-select');
    if (ticketSelect) {
      setBulkDeleteState();
      return;
    }

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

  root.addEventListener('input', function (e) {
    const itemLookup = e.target.closest('[data-resolution-item-lookup]');
    if (!itemLookup) return;
    syncItemResolutionLookup(itemLookup.closest('[data-resolution-item-form]'));
  });

  function applyStateUpdate(data) {
    if (data?.state) {
      state = Object.assign({}, state, data.state);
      rebuildRows();
      rebuildOrganizationRows();
      rebuildManagerSelections();
      renderResolutionPanels();
    }
  }

  function filterRows() {
    if (!ui.rows) return;
    const searchQuery = String(ui.search?.value || '').toLowerCase().trim();
    const rows = qsa('.metis-stash-row');
    rows.forEach(function (row) {
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
    rows
      .sort(compareTicketRows)
      .forEach(function (row) {
        ui.rows.appendChild(row);
      });
    applyVisibleRowStates(qsa('.metis-stash-row'), 'metis-stash-row');
    setBulkDeleteState();
  }

  function compareTicketRows(a, b) {
    const submittedA = parseInt(a.dataset.submittedAt || '0', 10);
    const submittedB = parseInt(b.dataset.submittedAt || '0', 10);
    const updatedA = parseInt(a.dataset.updatedAt || '0', 10);
    const updatedB = parseInt(b.dataset.updatedAt || '0', 10);
    const nameA = String(a.dataset.name || '');
    const nameB = String(b.dataset.name || '');
    const codeA = String(a.dataset.code || '');
    const codeB = String(b.dataset.code || '');

    if (currentSort === 'submitted_asc') return submittedA - submittedB;
    if (currentSort === 'updated_desc') return updatedB - updatedA;
    if (currentSort === 'name_asc') return nameA.localeCompare(nameB) || codeA.localeCompare(codeB);
    if (currentSort === 'name_desc') return nameB.localeCompare(nameA) || codeB.localeCompare(codeA);
    if (currentSort === 'code_asc') return codeA.localeCompare(codeB, undefined, { numeric: true, sensitivity: 'base' });
    if (currentSort === 'code_desc') return codeB.localeCompare(codeA, undefined, { numeric: true, sensitivity: 'base' });
    return submittedB - submittedA;
  }

  function hydrateInboxControls() {
    if (ui.sort) ui.sort.value = currentSort;
    qsa('.metis-stash-sidebar-filter').forEach(function (button) {
      const active = String(button.dataset.filter || '') === currentFilter;
      button.classList.toggle('is-active', active);
      button.classList.toggle('metis-btn-ghost', !active);
    });
  }

  async function refreshReport() {
    try {
      const data = await request('metis_grandys_stash_report', buildReportRequestPayload(1));
      state.report = data.report || null;
      state.reportPage = data.reportPage || { rows: [], pagination: {} };
      state.reportOptions = data.reportOptions || {};
      state.reportFilters = data.filters || { from: '', to: '' };
      clearReportBuilder(true);
      renderReportView();
      showAlert('Report updated.');
    } catch (err) {
      showAlert(err.message, 'error');
    }
  }

  function renderReportView() {
    if (stashView !== 'reports' || !state.report) return;
    const report = state.report || {};
    const summary = report.summary || {};
    setText(ui.reportTotalTickets, formatNumber(summary.total_tickets || 0));
    setText(ui.reportPeopleServed, formatNumber(report.people_served || 0));
    setText(ui.reportItemsFulfilled, formatNumber(report.items_fulfilled || 0));
    setText(ui.reportCompleted, formatNumber(summary.completed || 0));
    setText(ui.reportAvgDays, String(report.avg_days_to_complete ?? '—'));
    setText(ui.reportOpen, formatNumber(summary.open_tickets || 0));
    setText(ui.reportRangeText, buildReportRangeText());
    setText(ui.reportOrgCount, 'Organizations: ' + formatNumber((report.by_organization || []).length));
    setText(ui.reportPersonCount, 'People: ' + formatNumber((report.by_person || []).length));
    setText(ui.reportEquipmentCount, 'Equipment: ' + formatNumber((report.by_equipment || []).length));
    renderReportTrendGraph(report.monthly || []);

    renderReportRows(
      ui.reportCategoryBody,
      (report.by_category || []).map(function (row) {
        const label = reportLabel(row.category_name || row.category_slug || 'Other');
        return '<tr class="metis-premium-row metis-stash-report-summary-row metis-clickable-row" tabindex="0" role="button" data-report-drilldown="category" data-report-value="' + esc(row.category_slug || '') + '" data-report-label="' + esc(label) + '">' +
          '<td class="metis-premium-cell"><span class="metis-stash-report-trigger">' + esc(label) + '</span></td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.ticket_count || 0) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.item_count || 0) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.fulfilled || 0) + '</td>' +
          '</tr>';
      }),
      4
    );
    renderReportRows(
      ui.reportMonthlyBody,
      (report.monthly || []).map(function (row) {
        return '<tr class="metis-premium-row">' +
          '<td class="metis-premium-cell">' + esc(row.month_label || row.month || '') + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.tickets || 0) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.requests || 0) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.donations || 0) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.completed || 0) + '</td>' +
          '</tr>';
      }),
      5
    );
    renderReportRows(
      ui.reportUrgencyBody,
      (report.by_urgency || []).map(function (row) {
        return '<tr class="metis-premium-row"><td class="metis-premium-cell">' + esc(labelize(row.urgency || '')) + '</td><td class="metis-premium-cell">' + formatNumber(row.count || 0) + '</td></tr>';
      }),
      2
    );
    renderReportRows(
      ui.reportSourceBody,
      (report.by_source || []).map(function (row) {
        return '<tr class="metis-premium-row"><td class="metis-premium-cell">' + esc(reportLabel(row.source || '')) + '</td><td class="metis-premium-cell">' + formatNumber(row.count || 0) + '</td></tr>';
      }),
      2
    );
    renderReportRows(
      ui.reportOrganizationBody,
      (report.by_organization || []).map(function (row) {
        const label = reportLabel(row.organization_name || 'Independent');
        return '<tr class="metis-premium-row metis-stash-report-summary-row metis-clickable-row" tabindex="0" role="button" data-report-drilldown="organization" data-report-value="' + esc(row.organization_key || '') + '" data-report-label="' + esc(label) + '">' +
          '<td class="metis-premium-cell"><span class="metis-stash-report-trigger">' + esc(label) + '</span></td>' +
          '<td class="metis-premium-cell">' + esc(row.organization_domain || '—') + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.request_count || 0) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.ticket_count || 0) + '</td>' +
          '</tr>';
      }),
      4
    );
    renderReportRows(
      ui.reportPersonBody,
      (report.by_person || []).map(function (row) {
        const label = reportLabel(row.person_name || 'Unknown');
        return '<tr class="metis-premium-row metis-stash-report-summary-row metis-clickable-row" tabindex="0" role="button" data-report-drilldown="person" data-report-value="' + esc(row.person_key || '') + '" data-report-label="' + esc(label) + '">' +
          '<td class="metis-premium-cell"><span class="metis-stash-report-trigger">' + esc(label) + '</span></td>' +
          '<td class="metis-premium-cell">' + esc(row.person_email || '—') + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.request_count || 0) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.ticket_count || 0) + '</td>' +
          '</tr>';
      }),
      4
    );
    renderReportRows(
      ui.reportEquipmentBody,
      (report.by_equipment || []).map(function (row) {
        return '<tr class="metis-premium-row">' +
          '<td class="metis-premium-cell">' + esc(reportLabel(row.equipment_name || 'Other')) + '</td>' +
          '<td class="metis-premium-cell">' + esc(reportLabel(row.category_name || row.category_slug || 'Other')) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.request_ticket_count || 0) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.donation_ticket_count || 0) + '</td>' +
          '<td class="metis-premium-cell">' + formatNumber(row.fulfilled_count || 0) + '</td>' +
          '</tr>';
      }),
      5
    );

    populateReportBuilderOptions();
    renderReportDrilldown();
  }

  function renderReportRows(target, rows, columnCount) {
    if (!target) return;
    target.innerHTML = rows.length
      ? rows.join('')
      : '<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="' + Number(columnCount || 1) + '">No data for selected period.</td></tr>';
  }

  function buildReportRangeText() {
    const from = String(state.reportFilters?.from || ui.reportFrom?.value || '').trim();
    const to = String(state.reportFilters?.to || ui.reportTo?.value || '').trim();
    if (!from && !to) return 'Showing all available ticket history.';
    return (from || 'Start') + ' to ' + (to || 'Today');
  }

  function openReportDrilldown(kind, value, label) {
    if (!kind || !value) return;
    reportDrilldown = { kind: String(kind), value: String(value).toLowerCase(), label: String(label || 'Associated Tickets') };
    if (kind === 'category' && ui.reportBuilderCategory) ui.reportBuilderCategory.value = String(value || '');
    if (kind === 'organization' && ui.reportBuilderOrganization) ui.reportBuilderOrganization.value = String(label || '');
    if (kind === 'person' && ui.reportBuilderPerson) ui.reportBuilderPerson.value = String(label || '');
    queueReportRefresh(1, true);
    ui.reportDrilldown?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function renderReportDrilldown() {
    if (!ui.reportDrilldown || !ui.reportDrilldownBody) return;
    setText(ui.reportDrilldownTitle, 'Report Builder');
    setText(
      ui.reportDrilldownSubtitle,
      reportDrilldown
        ? ('Seeded from ' + labelize(reportDrilldown.kind || 'filter') + ': ' + String(reportDrilldown.label || '').trim() + '.')
        : 'Filter tickets from the current report range without leaving the page.'
    );

    const rows = Array.isArray(state.reportPage?.rows) ? state.reportPage.rows : [];
    const pagination = state.reportPage?.pagination || {};
    const total = Number(pagination.total || rows.length || 0);
    const currentPage = Number(pagination.page || 1);
    const totalPages = Number(pagination.total_pages || 1);

    setText(ui.reportDrilldownCount, formatNumber(total) + ' result' + (total === 1 ? '' : 's'));
    ui.reportDrilldownBody.innerHTML = rows.length
      ? rows.map(buildReportTicketRow).join('')
      : '<tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="9">No tickets match the current builder filters.</td></tr>';
    applyVisibleRowStates(qsa('.metis-stash-report-ticket-row', ui.reportDrilldownBody), 'metis-stash-report-ticket-row');
    setText(ui.reportPageInfo, 'Page ' + currentPage + ' of ' + totalPages);
    if (ui.reportPagePrev) ui.reportPagePrev.disabled = !pagination.has_prev;
    if (ui.reportPageNext) ui.reportPageNext.disabled = !pagination.has_next;
    if (ui.reportPerPage) ui.reportPerPage.value = String(pagination.per_page || ui.reportPerPage.value || '25');
    updateReportSortIndicators();
  }

  function compactItemSummary(value) {
    const parts = String(value || '')
      .split(/\s*,\s*/)
      .map(function (part) { return String(part || '').trim(); })
      .filter(Boolean);
    if (!parts.length) return '—';
    if (parts.length === 1) return parts[0];
    if (parts.length === 2) return parts.join(', ');
    return parts.slice(0, 2).join(', ') + ' +' + (parts.length - 2) + ' more';
  }

  function applyVisibleRowStates(rows, baseClass) {
    let visibleIndex = 0;
    (rows || []).forEach(function (row) {
      if (!row) return;
      const hidden = row.style.display === 'none' || row.hidden;
      row.classList.remove(baseClass + '-odd', baseClass + '-even');
      if (hidden) return;
      row.classList.add(baseClass + '-' + (visibleIndex % 2 === 0 ? 'odd' : 'even'));
      visibleIndex += 1;
    });
  }

  function buildReportTicketRow(ticket) {
    const fullItemSummary = String(ticket.items_summary || ticket.category_labels || '—');
    const compactSummary = compactItemSummary(fullItemSummary);
    const ticketCode = String(ticket.code || '');
    const ticketType = String(ticket.type || 'request').toLowerCase();
    const ticketStatus = String(ticket.status || 'NEW').toUpperCase();
    return '<tr class="metis-premium-row metis-stash-report-ticket-row metis-clickable-row" tabindex="0" role="link" aria-label="Open ticket ' + esc(ticketCode) + '" data-ticket-url="' + esc(buildTicketUrl(ticketCode)) + '">' +
      '<td class="metis-premium-cell">' + esc(shortDate(ticket.submitted_at || '')) + '</td>' +
      '<td class="metis-premium-cell"><strong>' + esc(ticketCode) + '</strong></td>' +
      '<td class="metis-premium-cell">' + esc(ticket.submit_name || 'Unknown') + '</td>' +
      '<td class="metis-premium-cell">' + esc(reportLabel(ticket.organization_label || ticket.organization_name || 'Independent')) + '</td>' +
      '<td class="metis-premium-cell">' + esc(reportLabel(ticket.assigned_label || '—')) + '</td>' +
      '<td class="metis-premium-cell"><span class="metis-stash-type-badge metis-stash-type-' + esc(ticketType === 'donation' ? 'donation' : 'request') + '">' + esc(labelize(ticketType || 'request')) + '</span></td>' +
      '<td class="metis-premium-cell">' + esc(labelize(ticket.urgency || '')) + '</td>' +
      '<td class="metis-premium-cell"><span class="metis-stash-status-badge metis-stash-status-' + esc(ticketStatus.toLowerCase()) + '">' + esc(ticketStatus) + '</span></td>' +
      '<td class="metis-premium-cell metis-stash-report-items-cell" title="' + esc(fullItemSummary) + '">' + esc(compactSummary) + '</td>' +
      '</tr>';
  }

  function populateReportBuilderOptions() {
    populateSelectOptions(
      ui.reportBuilderCategory,
      uniqueOptionPairs((state.report?.by_category || []).map(function (row) {
        return { value: String(row.category_slug || ''), label: reportLabel(row.category_name || row.category_slug || 'Other') };
      })),
      'All categories'
    );
    populateSelectOptions(
      ui.reportBuilderAssigned,
      uniqueOptionPairs((Array.isArray(state.reportOptions?.assigned) ? state.reportOptions.assigned : []).map(function (label) {
        return { value: String(label || '').trim(), label: reportLabel(label || 'Unassigned') };
      }).filter(function (row) { return row.value !== ''; })),
      'Anyone'
    );
    populateDatalistOptions(
      ui.reportBuilderItemOptions,
      uniqueStrings((Array.isArray(state.reportOptions?.items) ? state.reportOptions.items : []).map(function (value) {
        return reportLabel(value);
      }))
    );
    populateDatalistOptions(
      ui.reportBuilderOrganizationOptions,
      uniqueStrings((Array.isArray(state.reportOptions?.organizations) ? state.reportOptions.organizations : []).map(function (value) {
        return reportLabel(value);
      }))
    );
    populateDatalistOptions(
      ui.reportBuilderPersonOptions,
      uniqueStrings((Array.isArray(state.reportOptions?.people) ? state.reportOptions.people : []).map(function (value) {
        return reportLabel(value);
      }))
    );
  }

  function buildReportRequestPayload(pageOverride) {
    return {
      from: String(ui.reportFrom?.value || ''),
      to: String(ui.reportTo?.value || ''),
      page: String(pageOverride || state.reportPage?.pagination?.page || 1),
      per_page: String(ui.reportPerPage?.value || state.reportPage?.pagination?.per_page || 25),
      search: String(ui.reportDrilldownSearch?.value || ''),
      category: String(ui.reportBuilderCategory?.value || ''),
      item: String(ui.reportBuilderItem?.value || ''),
      organization: String(ui.reportBuilderOrganization?.value || ''),
      person: String(ui.reportBuilderPerson?.value || ''),
      urgency: String(ui.reportBuilderUrgency?.value || ''),
      type: String(ui.reportBuilderType?.value || ''),
      status: String(ui.reportBuilderStatus?.value || ''),
      assigned: String(ui.reportBuilderAssigned?.value || ''),
      sort_field: reportSort.field,
      sort_direction: reportSort.direction
    };
  }

  function queueReportRefresh(pageOverride, immediate) {
    if (stashView !== 'reports') return;
    window.clearTimeout(reportRefreshTimer);
    if (immediate) {
      refreshReportPage(pageOverride);
      return;
    }
    reportRefreshTimer = window.setTimeout(function () {
      refreshReportPage(pageOverride);
    }, 250);
  }

  async function refreshReportPage(pageOverride) {
    if (stashView !== 'reports') return;
    try {
      const data = await request('metis_grandys_stash_report', buildReportRequestPayload(pageOverride));
      state.report = data.report || state.report || null;
      state.reportPage = data.reportPage || state.reportPage || { rows: [], pagination: {} };
      state.reportOptions = data.reportOptions || state.reportOptions || {};
      state.reportFilters = data.filters || state.reportFilters || { from: '', to: '' };
      renderReportView();
    } catch (err) {
      showAlert(err.message, 'error');
    }
  }

  function submitReportPdfExport() {
    if (!ajax.ajax_url) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = ajax.ajax_url;
    form.style.display = 'none';

    Object.entries(Object.assign({}, buildReportRequestPayload(1), {
      action: 'metis_grandys_stash_export',
      nonce: ajax.nonce || '',
      format: 'pdf'
    })).forEach(function (entry) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = String(entry[0]);
      input.value = String(entry[1] ?? '');
      form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    window.setTimeout(function () {
      form.remove();
    }, 1000);
  }

  function renderReportTrendGraph(monthlyRows) {
    if (!ui.reportTrendGraph) return;
    const rows = Array.isArray(monthlyRows) ? monthlyRows.slice().reverse().slice(-12) : [];
    if (!rows.length) {
      ui.reportTrendGraph.innerHTML = '<div class="metis-muted">Not enough data to graph a trend for this range.</div>';
      return;
    }

    const maxValue = rows.reduce(function (max, row) {
      return Math.max(max, Number(row.tickets || 0), Number(row.requests || 0), Number(row.donations || 0), Number(row.completed || 0));
    }, 1);
    const width = 720;
    const height = 260;
    const left = 42;
    const right = 20;
    const top = 18;
    const bottom = 44;
    const plotW = width - left - right;
    const plotH = height - top - bottom;
    const pointCount = Math.max(1, rows.length - 1);
    const series = [
      { key: 'tickets', label: 'Tickets', color: '#175cd3' },
      { key: 'requests', label: 'Requests', color: '#3d5a1e' },
      { key: 'donations', label: 'Donations', color: '#8e4b10' },
      { key: 'completed', label: 'Completed', color: '#344054' }
    ];

    function pointFor(index, value) {
      const x = left + (plotW * (index / pointCount));
      const y = top + plotH - (plotH * (Number(value || 0) / maxValue));
      return [x, y];
    }

    const grid = [0, 0.25, 0.5, 0.75, 1].map(function (ratio) {
      const y = top + plotH - (plotH * ratio);
      const value = Math.round(maxValue * ratio);
      return '<line x1="' + left + '" y1="' + y.toFixed(2) + '" x2="' + (width - right) + '" y2="' + y.toFixed(2) + '" stroke="#e4e7ec" stroke-width="1"></line>' +
        '<text x="' + (left - 8) + '" y="' + (y + 4).toFixed(2) + '" text-anchor="end" font-size="10" fill="#667085">' + esc(String(value)) + '</text>';
    }).join('');

    const polylines = series.map(function (entry) {
      const points = rows.map(function (row, index) {
        const point = pointFor(index, row[entry.key] || 0);
        return point[0].toFixed(2) + ',' + point[1].toFixed(2);
      }).join(' ');
      return '<polyline fill="none" stroke="' + entry.color + '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="' + points + '"></polyline>';
    }).join('');

    const labels = rows.map(function (row, index) {
      const point = pointFor(index, 0);
      return '<text x="' + point[0].toFixed(2) + '" y="' + (height - 16) + '" text-anchor="middle" font-size="10" fill="#667085">' + esc(String(row.month || row.month_label || '')) + '</text>';
    }).join('');

    const legend = series.map(function (entry) {
      return '<span class="metis-stash-report-trend-legend-item"><span class="metis-stash-report-trend-swatch" style="background:' + entry.color + ';"></span>' + esc(entry.label) + '</span>';
    }).join('');

    ui.reportTrendGraph.innerHTML = '<div class="metis-stash-report-trend-legend">' + legend + '</div>' +
      '<svg class="metis-stash-report-trend-svg" viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none" aria-hidden="true">' +
        grid +
        '<line x1="' + left + '" y1="' + (top + plotH) + '" x2="' + (width - right) + '" y2="' + (top + plotH) + '" stroke="#98a2b3" stroke-width="1.2"></line>' +
        polylines +
        labels +
      '</svg>';
  }

  function populateSelectOptions(node, options, defaultLabel) {
    if (!node) return;
    const current = String(node.value || '');
    const rows = ['<option value="">' + esc(defaultLabel || 'All') + '</option>'].concat(
      (options || []).map(function (option) {
        return '<option value="' + esc(option.value || '') + '">' + esc(option.label || option.value || '') + '</option>';
      })
    );
    node.innerHTML = rows.join('');
    node.value = current;
    if (current && node.value !== current) node.value = '';
  }

  function populateDatalistOptions(node, options) {
    if (!node) return;
    node.innerHTML = (options || []).map(function (value) {
      return '<option value="' + esc(value) + '"></option>';
    }).join('');
  }

  function uniqueOptionPairs(rows) {
    const seen = new Set();
    return (rows || []).filter(function (row) {
      const value = String(row.value || '').trim();
      if (!value) return false;
      const key = value.toLowerCase();
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    }).sort(function (a, b) {
      return String(a.label || a.value || '').localeCompare(String(b.label || b.value || ''), undefined, { sensitivity: 'base' });
    });
  }

  function uniqueStrings(values) {
    const seen = new Set();
    return (values || []).filter(function (value) {
      const normalized = String(value || '').trim();
      if (!normalized) return false;
      const key = normalized.toLowerCase();
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    }).sort(function (a, b) {
      return String(a).localeCompare(String(b), undefined, { sensitivity: 'base' });
    });
  }

  function clearReportBuilder(keepFilters) {
    reportDrilldown = null;
    if (keepFilters) return;
    if (ui.reportDrilldownSearch) ui.reportDrilldownSearch.value = '';
    if (ui.reportBuilderCategory) ui.reportBuilderCategory.value = '';
    if (ui.reportBuilderItem) ui.reportBuilderItem.value = '';
    if (ui.reportBuilderOrganization) ui.reportBuilderOrganization.value = '';
    if (ui.reportBuilderPerson) ui.reportBuilderPerson.value = '';
    if (ui.reportBuilderUrgency) ui.reportBuilderUrgency.value = '';
    if (ui.reportBuilderType) ui.reportBuilderType.value = '';
    if (ui.reportBuilderStatus) ui.reportBuilderStatus.value = '';
    if (ui.reportBuilderAssigned) ui.reportBuilderAssigned.value = '';
  }

  function updateReportSortIndicators() {
    qsa('[data-report-sort]').forEach(function (button) {
      const active = String(button.dataset.reportSort || '') === reportSort.field;
      button.classList.toggle('metis-sort-active', active);
      button.classList.toggle('metis-sort-asc', active && reportSort.direction === 'asc');
      button.classList.toggle('metis-sort-desc', active && reportSort.direction === 'desc');
    });
  }

  function normalizeFilter(value) {
    const allowed = ['action', 'waitlist', 'mine', 'recent', 'all'];
    return allowed.includes(String(value || '')) ? String(value) : 'action';
  }

  function normalizeSort(value) {
    const allowed = ['submitted_desc', 'submitted_asc', 'updated_desc', 'name_asc', 'name_desc', 'code_asc', 'code_desc'];
    return allowed.includes(String(value || '')) ? String(value) : 'submitted_desc';
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
      if (ui.groupModalSummary) ui.groupModalSummary.innerHTML = buildManagerSummary([
        ['Code', group.code || '\u2014'],
        ['Open Tickets', Number(group.open_count || 0)],
        ['Total Tickets', Number(group.ticket_count || 0)],
        ['Last Activity', group.last_ticket_at ? shortDate(group.last_ticket_at) : '\u2014']
      ]);
      ui.groupTicketList.innerHTML = buildManagerTicketList('Linked Tickets', ticketsForGroup(id));
      activateTab('group-general');
      openModal(ui.groupModal);
    } else if (kind === 'organization') {
      const organization = (state.organizations || []).find(function (entry) { return Number(entry.id || 0) === id; });
      if (!organization || !ui.organizationTicketList) return;
      fillForm(ui.organizationForm, Object.assign({}, organization, {
        alternate_domains: Array.isArray(organization.additional_domains) ? organization.additional_domains.join('\n') : ''
      }));
      if (ui.organizationModalTitle) ui.organizationModalTitle.textContent = organization.name || 'Organization Manager';
      if (ui.organizationModalSubtitle) ui.organizationModalSubtitle.textContent = organization.domain || organization.code || '';
      if (ui.organizationModalSummary) ui.organizationModalSummary.innerHTML = buildManagerSummary([
        ['Domain', organization.domain || '\u2014'],
        ['Code', organization.code || '\u2014'],
        ['Status', organization.is_active ? 'Active' : 'Inactive'],
        ['Open Tickets', Number(organization.open_count || 0)],
        ['Total Tickets', Number(organization.ticket_count || 0)]
      ]);
      if (ui.organizationLinkForm?.elements?.namedItem('organization_id')) ui.organizationLinkForm.elements.namedItem('organization_id').value = String(id);
      if (ui.organizationMergeForm?.elements?.namedItem('source_id')) ui.organizationMergeForm.elements.namedItem('source_id').value = String(id);
      if (ui.organizationMergeForm?.elements?.namedItem('target_code')) ui.organizationMergeForm.elements.namedItem('target_code').value = '';
      if (ui.organizationMergeForm?.elements?.namedItem('target_lookup')) ui.organizationMergeForm.elements.namedItem('target_lookup').value = '';
      if (ui.organizationIndependentForm?.elements?.namedItem('organization_id')) ui.organizationIndependentForm.elements.namedItem('organization_id').value = String(id);
      ui.organizationTicketList.innerHTML = buildManagerTicketList('Linked Tickets', ticketsForOrganization(id));
      rebuildManagerSelections();
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
        return '<button type="button" class="metis-stash-linked-ticket" data-ticket-url="' + esc(url) + '">' +
          '<strong>' + esc(ticket.code || '') + '</strong>' +
          '<span>' + esc(ticket.submit_name || 'Unknown') + '</span>' +
          '<span class="metis-muted">' + esc(labelize(ticket.type || 'request')) + ' · ' + esc(ticket.status || 'NEW') + ' · ' + esc(shortDate(ticket.submitted_at || '')) + '</span>' +
        '</button>';
      }).join('') + '</div></div>';
  }

  function buildManagerSummary(items) {
    return '<div class="metis-stash-manager-summary-grid">' + items.map(function (item) {
      return '<div class="metis-stash-manager-summary-item"><div class="metis-stash-manager-summary-label">' + esc(String(item[0] || '')) + '</div><div class="metis-stash-manager-summary-value">' + esc(String(item[1] ?? '')) + '</div></div>';
    }).join('') + '</div>';
  }

  function renderLegacyAuditResults(audit) {
    if (!ui.legacyAuditResults) return;
    const rows = Array.isArray(audit.rows) ? audit.rows : [];
    const summary = String(audit.summary || '');
    if (!rows.length) {
      ui.legacyAuditResults.innerHTML = summary
        ? '<div class="metis-muted" style="margin-top:12px;">' + esc(summary) + '</div>'
        : '';
      return;
    }

    ui.legacyAuditResults.innerHTML =
      '<div class="metis-muted" style="margin:12px 0;">' + esc(summary) + '</div>' +
      '<table class="metis-premium-table">' +
        '<thead><tr class="metis-premium-row metis-premium-header">' +
          '<th class="metis-premium-cell" scope="col">Entry</th>' +
          '<th class="metis-premium-cell" scope="col">Ticket</th>' +
          '<th class="metis-premium-cell" scope="col">Name</th>' +
          '<th class="metis-premium-cell" scope="col">Expected</th>' +
          '<th class="metis-premium-cell" scope="col">Actual</th>' +
          '<th class="metis-premium-cell" scope="col">Status</th>' +
        '</tr></thead>' +
        '<tbody>' +
          rows.map(function (row) {
            return '<tr class="metis-premium-row">' +
              '<td class="metis-premium-cell">#' + Number(row.parent_entry_id || 0) + '</td>' +
              '<td class="metis-premium-cell">' + esc(row.ticket_code || '\u2014') + '</td>' +
              '<td class="metis-premium-cell">' + esc(row.name || '\u2014') + '</td>' +
              '<td class="metis-premium-cell">' + esc(labelize(row.expected_type || '')) + '</td>' +
              '<td class="metis-premium-cell">' + esc(labelize(row.actual_type || '')) + '</td>' +
              '<td class="metis-premium-cell">' + esc(labelize(row.status || '')) + '</td>' +
            '</tr>';
          }).join('') +
        '</tbody>' +
      '</table>';
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
      rebuildOrganizationRows();
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
          (canBulkDelete ? '<td class="metis-premium-cell metis-stash-select-cell"><input type="checkbox" class="metis-stash-ticket-select" value="' + Number(ticket.id || 0) + '" aria-label="Select ' + code + '"></td>' : '') +
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
    rebuildOrganizationLookup();
    filterManagerRows();
  }

  function renderResolutionPanels() {
    renderOrganizationResolution();
    renderItemResolution();
  }

  function renderOrganizationResolution() {
    if (!ui.orgResolution) return;
    const candidates = Array.isArray(state.resolution?.organizations) ? state.resolution.organizations : [];
    if (!candidates.length) {
      ui.orgResolution.innerHTML =
        '<div class="metis-stash-ticket-section">' +
          '<h3 style="margin:0 0 8px;">Organization Resolution</h3>' +
          '<div class="metis-muted">No likely organization duplicates are waiting for review.</div>' +
        '</div>';
      return;
    }

    ui.orgResolution.innerHTML =
      '<div class="metis-stash-ticket-section">' +
        '<h3 style="margin:0 0 8px;">Organization Resolution</h3>' +
        '<table class="metis-premium-table">' +
          '<thead><tr class="metis-premium-row metis-premium-header">' +
            '<th class="metis-premium-cell" scope="col">Source</th>' +
            '<th class="metis-premium-cell" scope="col">Reason</th>' +
            '<th class="metis-premium-cell" scope="col">Tickets</th>' +
            '<th class="metis-premium-cell" scope="col">Resolve To</th>' +
            '<th class="metis-premium-cell" scope="col">Suggested</th>' +
          '</tr></thead>' +
          '<tbody>' +
            candidates.map(function (candidate) {
              const sourceMeta = [candidate.source_code || '', candidate.source_domain || '\u2014'].filter(Boolean).join(' · ');
              const selectOptions = buildOrganizationResolutionOptions(Number(candidate.source_id || 0), Number(candidate.suggested_target_id || 0));
              return '<tr class="metis-premium-row">' +
                '<td class="metis-premium-cell">' +
                  '<strong>' + esc(candidate.source_name || 'Unknown') + '</strong>' +
                  '<div class="metis-muted">' + esc(sourceMeta) + '</div>' +
                '</td>' +
                '<td class="metis-premium-cell">' + esc(candidate.reason_label || '') + '</td>' +
                '<td class="metis-premium-cell">' + Number(candidate.ticket_count || 0) + '</td>' +
                '<td class="metis-premium-cell">' +
                  '<div data-resolution-org-form data-source-id="' + Number(candidate.source_id || 0) + '">' +
                    '<select class="metis-select" name="target_id">' + selectOptions + '</select>' +
                    '<div style="margin-top:8px;"><button type="button" class="metis-btn-xs" data-resolution-org-submit>Resolve</button></div>' +
                  '</div>' +
                '</td>' +
                '<td class="metis-premium-cell">' + esc(candidate.suggested_target_name || '') + '</td>' +
              '</tr>';
            }).join('') +
          '</tbody>' +
        '</table>' +
      '</div>';
  }

  function renderItemResolution() {
    if (!ui.itemResolution) return;
    const candidates = Array.isArray(state.resolution?.items) ? state.resolution.items : [];
    if (!candidates.length) {
      ui.itemResolution.innerHTML =
        '<div class="metis-stash-ticket-section">' +
          '<h3 style="margin:0 0 8px;">Item Resolution</h3>' +
          '<div class="metis-muted">No unresolved legacy item labels are waiting for review.</div>' +
        '</div>';
      return;
    }

    ui.itemResolution.innerHTML =
      '<div class="metis-stash-ticket-section">' +
        '<h3 style="margin:0 0 8px;">Item Resolution</h3>' +
        '<datalist id="metis-stash-item-resolution-lookup">' + buildCatalogResolutionLookupOptions() + '</datalist>' +
        '<table class="metis-premium-table">' +
          '<thead><tr class="metis-premium-row metis-premium-header">' +
            '<th class="metis-premium-cell" scope="col">Legacy Label</th>' +
            '<th class="metis-premium-cell" scope="col">Rows</th>' +
            '<th class="metis-premium-cell" scope="col">Tickets</th>' +
            '<th class="metis-premium-cell" scope="col">Resolve To</th>' +
            '<th class="metis-premium-cell" scope="col">Suggested</th>' +
          '</tr></thead>' +
          '<tbody>' +
            candidates.map(function (candidate) {
              return '<tr class="metis-premium-row">' +
                '<td class="metis-premium-cell">' +
                  '<strong>' + esc(candidate.label || 'Unknown') + '</strong>' +
                  buildItemResolutionExamples(candidate.examples || []) +
                '</td>' +
                '<td class="metis-premium-cell">' + Number(candidate.row_count || 0) + '</td>' +
                '<td class="metis-premium-cell">' + Number(candidate.ticket_count || 0) + '</td>' +
                '<td class="metis-premium-cell">' +
                  '<div data-resolution-item-form data-signature="' + esc(candidate.signature || '') + '">' +
                    '<input class="metis-input" type="text" name="catalog_lookup" list="metis-stash-item-resolution-lookup" data-resolution-item-lookup value="' + esc(buildCatalogLookupValue(Number(candidate.suggested_catalog_item_id || 0))) + '" placeholder="Type to search the catalog">' +
                    '<input type="hidden" name="catalog_item_id" value="' + Number(candidate.suggested_catalog_item_id || 0) + '">' +
                    '<div style="margin-top:8px;"><button type="button" class="metis-btn-xs" data-resolution-item-submit>Resolve</button></div>' +
                  '</div>' +
                '</td>' +
                '<td class="metis-premium-cell">' + esc(candidate.suggested_item_name || '\u2014') + '</td>' +
              '</tr>';
            }).join('') +
          '</tbody>' +
        '</table>' +
      '</div>';
  }

  function buildOrganizationResolutionOptions(sourceId, selectedTargetId) {
    const options = ['<option value="0"' + (selectedTargetId < 1 ? ' selected' : '') + '>Independent</option>'];
    (state.organizations || []).forEach(function (organization) {
      const id = Number(organization.id || 0);
      if (id < 1 || id === sourceId) return;
      const selected = id === selectedTargetId ? ' selected' : '';
      const label = [organization.name || 'Unknown', organization.code || '', organization.domain || ''].filter(Boolean).join(' | ');
      options.push('<option value="' + id + '"' + selected + '>' + esc(label) + '</option>');
    });
    return options.join('');
  }

  function buildCatalogResolutionLookupOptions() {
    const options = [];
    (state.catalog?.items || []).forEach(function (item) {
      const id = Number(item.id || 0);
      if (id < 1) return;
      options.push('<option value="' + esc(buildCatalogLookupLabel(item)) + '"></option>');
    });
    return options.join('');
  }

  function buildCatalogLookupLabel(item) {
    return [item.category_name || 'Other', item.item_name || 'Unknown'].join(' | ');
  }

  function buildCatalogLookupValue(selectedCatalogItemId) {
    const item = (state.catalog?.items || []).find(function (entry) {
      return Number(entry.id || 0) === selectedCatalogItemId;
    });
    return item ? buildCatalogLookupLabel(item) : '';
  }

  function syncItemResolutionLookup(form) {
    if (!form) return false;
    const lookupField = form.querySelector('[name="catalog_lookup"]');
    const idField = form.querySelector('[name="catalog_item_id"]');
    if (!lookupField || !idField) return false;
    const raw = String(lookupField.value || '').trim().toLowerCase();
    if (!raw) {
      idField.value = '';
      return false;
    }

    const items = Array.isArray(state.catalog?.items) ? state.catalog.items : [];
    const exact = items.find(function (item) {
      return buildCatalogLookupLabel(item).toLowerCase() === raw;
    });
    if (exact) {
      idField.value = String(exact.id || '');
      return Boolean(idField.value);
    }

    const partialMatches = items.filter(function (item) {
      return buildCatalogLookupLabel(item).toLowerCase().includes(raw);
    });
    if (partialMatches.length === 1) {
      lookupField.value = buildCatalogLookupLabel(partialMatches[0]);
      idField.value = String(partialMatches[0].id || '');
      return Boolean(idField.value);
    }

    idField.value = '';
    return false;
  }

  function buildItemResolutionExamples(examples) {
    if (!Array.isArray(examples) || !examples.length) return '';
    return '<div class="metis-muted" style="margin-top:4px;">' +
      examples.map(function (example) {
        return esc(String(example.label || '')) + ' (' + Number(example.count || 0) + ')';
      }).join(' · ') +
    '</div>';
  }

  function rebuildOrganizationRows() {
    if (!ui.organizationRows) return;
    ui.organizationRows.innerHTML = (state.organizations || []).map(function (organization) {
      const id = Number(organization.id || 0);
      const selected = selectedManagerKind === 'organization' && selectedManagerId === id;
      const meta = [organization.code || '', organization.domain || '\u2014'].filter(Boolean).join(' · ');
      const lastActivity = organization.last_ticket_at ? shortDate(organization.last_ticket_at) : '\u2014';
      const additionalDomains = Array.isArray(organization.additional_domains) ? organization.additional_domains.join(' ') : '';
      const search = [organization.code || '', organization.name || '', organization.domain || '', additionalDomains].join(' ').toLowerCase();
      return '<tr class="metis-premium-row metis-stash-manager-row' + (selected ? ' is-selected' : '') + '"' +
        ' data-manager-kind="organization"' +
        ' data-id="' + id + '"' +
        ' data-open-count="' + Number(organization.open_count || 0) + '"' +
        ' data-is-active="' + (organization.is_active ? '1' : '0') + '"' +
        ' data-last-ticket="' + esc(String(organization.last_ticket_at || '')) + '"' +
        ' data-search="' + esc(search) + '">' +
        '<td class="metis-premium-cell">' +
          '<button type="button" class="metis-stash-link-button" data-manager-open="organization" data-id="' + id + '">' + esc(organization.name || 'Unknown') + '</button>' +
          '<div class="metis-muted">' + esc(meta) + '</div>' +
        '</td>' +
        '<td class="metis-premium-cell">' + Number(organization.ticket_count || 0) + '</td>' +
        '<td class="metis-premium-cell">' + Number(organization.open_count || 0) + '</td>' +
        '<td class="metis-premium-cell">' + esc(lastActivity) + '</td>' +
      '</tr>';
    }).join('');
    filterManagerRows();
  }

  function rebuildOrganizationLookup() {
    if (!ui.organizationLookup) return;
    const sourceId = Number(ui.organizationMergeForm?.elements?.namedItem('source_id')?.value || '0');
    ui.organizationLookup.innerHTML = (state.organizations || [])
      .filter(function (organization) {
        return Number(organization.id || 0) > 0 && Number(organization.id || 0) !== sourceId;
      })
      .map(function (organization) {
        const label = buildOrganizationLookupLabel(organization);
        return '<option value="' + esc(label) + '"></option>';
      })
      .join('');
  }

  function syncOrganizationMergeLookup() {
    if (!ui.organizationMergeForm) return false;
    const lookupField = ui.organizationMergeForm.elements.namedItem('target_lookup');
    const codeField = ui.organizationMergeForm.elements.namedItem('target_code');
    const sourceId = Number(ui.organizationMergeForm.elements.namedItem('source_id')?.value || '0');
    if (!lookupField || !codeField) return false;
    const raw = String(lookupField.value || '').trim().toLowerCase();
    if (!raw) {
      codeField.value = '';
      return false;
    }

    const organizations = (state.organizations || []).filter(function (organization) {
      return Number(organization.id || 0) > 0 && Number(organization.id || 0) !== sourceId;
    });
    const exact = organizations.find(function (organization) {
      if (Number(organization.id || 0) < 1 || Number(organization.id || 0) === sourceId) return false;
      const haystack = [
        buildOrganizationLookupLabel(organization),
        organization.code || '',
        organization.name || '',
        organization.domain || ''
      ].map(function (value) {
        return String(value || '').trim().toLowerCase();
      });
      return haystack.includes(raw);
    });
    if (exact) {
      codeField.value = String(exact.code || '');
      return Boolean(codeField.value);
    }

    const partialMatches = organizations.filter(function (organization) {
      const haystack = [
        buildOrganizationLookupLabel(organization),
        organization.code || '',
        organization.name || '',
        organization.domain || ''
      ].map(function (value) {
        return String(value || '').trim().toLowerCase();
      });
      return haystack.some(function (value) { return value.includes(raw); });
    });

    if (partialMatches.length === 1) {
      lookupField.value = buildOrganizationLookupLabel(partialMatches[0]);
      codeField.value = String(partialMatches[0].code || '');
      return Boolean(codeField.value);
    }

    codeField.value = '';
    return false;
  }

  function buildOrganizationLookupLabel(organization) {
    const name = String(organization.name || 'Unknown');
    const code = String(organization.code || '').trim();
    const domain = String(organization.domain || '').trim();
    const additionalDomains = Array.isArray(organization.additional_domains) ? organization.additional_domains.join(', ') : '';
    return [name, code, domain, additionalDomains].filter(Boolean).join(' | ');
  }

  function selectedTicketIds() {
    return qsa('.metis-stash-ticket-select:checked')
      .map(function (input) { return Number(input.value || '0'); })
      .filter(function (id) { return id > 0; });
  }

  function syncSelectAllRows() {
    const checked = Boolean(ui.selectAll?.checked);
    qsa('.metis-stash-ticket-select').forEach(function (input) {
      const row = input.closest('.metis-stash-row');
      if (!row || row.style.display === 'none') return;
      input.checked = checked;
    });
    setBulkDeleteState();
  }

  function setBulkDeleteState() {
    if (!canBulkDelete) return;
    const visibleInputs = qsa('.metis-stash-ticket-select').filter(function (input) {
      const row = input.closest('.metis-stash-row');
      return row && row.style.display !== 'none';
    });
    const checkedCount = visibleInputs.filter(function (input) { return input.checked; }).length;
    if (ui.selectAll) {
      ui.selectAll.checked = visibleInputs.length > 0 && checkedCount === visibleInputs.length;
      ui.selectAll.indeterminate = checkedCount > 0 && checkedCount < visibleInputs.length;
    }
    if (ui.bulkDelete) {
      ui.bulkDelete.disabled = checkedCount < 1;
      ui.bulkDelete.textContent = checkedCount > 0 ? 'Delete Selected (' + checkedCount + ')' : 'Delete Selected';
    }
  }

  function switchTab(tabButton) {
    const target = String(tabButton.dataset.tabTarget || '');
    if (!target) return;
    activateTab(target);
  }

  function activateTab(target) {
    Array.from(document.querySelectorAll('.metis-stash-tab')).forEach(function (button) {
      const active = String(button.dataset.tabTarget || '') === target;
      button.classList.toggle('is-active', active);
      button.classList.toggle('metis-btn-ghost', !active);
    });
    Array.from(document.querySelectorAll('.metis-stash-tab-panel')).forEach(function (panel) {
      panel.classList.toggle('is-active', String(panel.dataset.tabPanel || '') === target);
    });
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

  function reportLabel(value) {
    const raw = String(value || '').trim();
    if (!raw) return 'Other';
    if (raw.includes('-') || raw.includes('_')) {
      const expanded = raw.replace(/[-_]+/g, ' ');
      return expanded === expanded.toLowerCase()
        ? expanded.replace(/\b\w/g, function (char) { return char.toUpperCase(); })
        : expanded;
    }
    return raw;
  }

  function setText(node, value) {
    if (node) node.textContent = String(value ?? '');
  }

  function formatNumber(value) {
    return Number(value || 0).toLocaleString();
  }

  function openModal(node) {
    document.body.classList.add('metis-stash-modal-open');
    node?.classList.add('metis-open');
    if (node) node.setAttribute('aria-hidden', 'false');
  }

  function closeModal(node) {
    node?.classList.remove('metis-open');
    if (node) node.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.metis-stash-modal.metis-open')) {
      document.body.classList.remove('metis-stash-modal-open');
    }
  }

  function mountModalToBody(node) {
    if (!node || node.dataset.bodyMounted === '1') return;
    document.body.appendChild(node);
    node.dataset.bodyMounted = '1';
  }

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    Array.from(document.querySelectorAll('.metis-stash-modal.metis-open')).forEach(function (modal) {
      closeModal(modal);
    });
  });

  function showAlert(message, type) {
    const level = type === 'error' ? 'error' : 'success';
    if (window.Metis && Metis.ui && Metis.ui.toast && typeof Metis.ui.toast[level] === 'function') {
      Metis.ui.toast[level](String(message || ''));
      return;
    }
    if (window.Metis && Metis.toast && typeof Metis.toast[level] === 'function') {
      Metis.toast[level](String(message || ''));
      return;
    }
    if (window.console && typeof window.console.warn === 'function') {
      window.console.warn(String(message || ''));
    }
  }

  function confirmAction(message, options) {
    if (window.Metis && Metis.ui && Metis.ui.confirm && typeof Metis.ui.confirm.open === 'function') {
      return Metis.ui.confirm.open(Object.assign({}, options || {}, { message: String(message || 'Are you sure?') }));
    }
    if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
      return Metis.confirm.open(Object.assign({}, options || {}, { message: String(message || 'Are you sure?') }));
    }
    return Promise.resolve(false);
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
