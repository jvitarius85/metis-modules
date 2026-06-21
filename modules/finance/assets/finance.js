function initMetisFinanceApp(context) {
    var scope = context && context.root ? context.root : document;
    var root = scope.querySelector('[data-finance-v2-app="1"]');
    if (!root) return;
    if (root.getAttribute('data-metis-finance-initialized') === '1') return;
    root.setAttribute('data-metis-finance-initialized', '1');

    var ajaxEndpoint = String(root.getAttribute('data-ajax-endpoint') || '').trim() || '/api/ajax';
    var currentSection = String(root.getAttribute('data-current-section') || '').trim().toLowerCase();
    var bootstrapAction = String(root.getAttribute('data-action-bootstrap') || '').trim();
    var entriesListAction = String(root.getAttribute('data-action-entries-list') || '').trim();
    var entriesCreateAction = String(root.getAttribute('data-action-entries-create') || '').trim();
    var reconImportAction = String(root.getAttribute('data-action-recon-import') || '').trim();
    var reconWorkflowAction = String(root.getAttribute('data-action-recon-workflow') || '').trim();
    var reconItemToggleAction = String(root.getAttribute('data-action-recon-item-toggle') || '').trim();
    var reconFinalizeAction = String(root.getAttribute('data-action-recon-finalize') || '').trim();
    var reconReopenAction = String(root.getAttribute('data-action-recon-reopen') || '').trim();
    var reconDeleteAction = String(root.getAttribute('data-action-recon-delete') || '').trim();
    var reconMappingListAction = String(root.getAttribute('data-action-recon-mapping-list') || '').trim();
    var reconMappingSaveAction = String(root.getAttribute('data-action-recon-mapping-save') || '').trim();
    var reconReviewAction = String(root.getAttribute('data-action-recon-review') || '').trim();
    var reconMatchLineAction = String(root.getAttribute('data-action-recon-match-line') || '').trim();
    var categoriesListAction = String(root.getAttribute('data-action-categories-list') || '').trim();
    var categoriesSaveAction = String(root.getAttribute('data-action-categories-save') || '').trim();
    var budgetSnapshotAction = String(root.getAttribute('data-action-budget-snapshot') || '').trim();
    var budgetVersionCreateAction = String(root.getAttribute('data-action-budget-version-create') || '').trim();
    var budgetLinesSaveAction = String(root.getAttribute('data-action-budget-lines-save') || '').trim();
    var invoicesListAction = String(root.getAttribute('data-action-invoices-list') || '').trim();
    var invoicesCreateAction = String(root.getAttribute('data-action-invoices-create') || '').trim();
    var invoicesSendAction = String(root.getAttribute('data-action-invoices-send') || '').trim();
    var invoicesPaidAction = String(root.getAttribute('data-action-invoices-paid') || '').trim();
    var fiscalSettingsGetAction = String(root.getAttribute('data-action-fiscal-settings-get') || '').trim();
    var fiscalSettingsUpdateAction = String(root.getAttribute('data-action-fiscal-settings-update') || '').trim();
    var fiscalMigrateAction = String(root.getAttribute('data-action-fiscal-migrate') || '').trim();
    var reportsSnapshotAction = String(root.getAttribute('data-action-reports-snapshot') || '').trim();
    var reportRenderAction = String(root.getAttribute('data-action-report-render') || '').trim();
    var reportPdfAction = String(root.getAttribute('data-action-report-pdf') || '').trim();
    var stripeOverviewAction = String(root.getAttribute('data-action-stripe-overview') || '').trim();
    var stripeEventCreateAction = String(root.getAttribute('data-action-stripe-event-create') || '').trim();
    var stripePayoutCreateAction = String(root.getAttribute('data-action-stripe-payout-create') || '').trim();
    var bankLineCreateAction = String(root.getAttribute('data-action-bank-line-create') || '').trim();
    var stripeMatchCreateAction = String(root.getAttribute('data-action-stripe-match-create') || '').trim();
    var stripeAutoMatchAction = String(root.getAttribute('data-action-stripe-auto-match') || '').trim();
    var reconListUrl = String(root.getAttribute('data-recon-list-url') || '').trim();
    var reconStepsUrl = String(root.getAttribute('data-recon-steps-url') || '').trim();

    var financeNonce = String(root.getAttribute('data-finance-nonce') || '').trim();
    var glCreateNonce = String(root.getAttribute('data-gl-create-nonce') || '').trim();
    var reconImportNonce = String(root.getAttribute('data-recon-import-nonce') || '').trim();
    var reconItemToggleNonce = String(root.getAttribute('data-recon-item-toggle-nonce') || '').trim();
    var reconFinalizeNonce = String(root.getAttribute('data-recon-finalize-nonce') || '').trim();
    var reconReopenNonce = String(root.getAttribute('data-recon-reopen-nonce') || '').trim();
    var reconDeleteNonce = String(root.getAttribute('data-recon-delete-nonce') || '').trim();
    var reconMappingNonce = String(root.getAttribute('data-recon-mapping-nonce') || '').trim();
    var reconReviewNonce = String(root.getAttribute('data-recon-review-nonce') || '').trim();
    var categorySaveNonce = String(root.getAttribute('data-category-save-nonce') || '').trim();
    var budgetVersionNonce = String(root.getAttribute('data-budget-version-nonce') || '').trim();
    var budgetLinesNonce = String(root.getAttribute('data-budget-lines-nonce') || '').trim();
    var invoiceCreateNonce = String(root.getAttribute('data-invoice-create-nonce') || '').trim();
    var invoiceSendNonce = String(root.getAttribute('data-invoice-send-nonce') || '').trim();
    var invoicePaidNonce = String(root.getAttribute('data-invoice-paid-nonce') || '').trim();
    var fiscalSettingsNonce = String(root.getAttribute('data-fiscal-settings-nonce') || '').trim();
    var fiscalMigrateNonce = String(root.getAttribute('data-fiscal-migrate-nonce') || '').trim();
    var reportRenderNonce = String(root.getAttribute('data-report-render-nonce') || '').trim();
    var reportPdfNonce = String(root.getAttribute('data-report-pdf-nonce') || '').trim();
    var stripeEventNonce = String(root.getAttribute('data-stripe-event-nonce') || '').trim();
    var stripePayoutNonce = String(root.getAttribute('data-stripe-payout-nonce') || '').trim();
    var bankLineNonce = String(root.getAttribute('data-bank-line-nonce') || '').trim();
    var stripeMatchNonce = String(root.getAttribute('data-stripe-match-nonce') || '').trim();

    var entriesBody = root.querySelector('[data-finance-entries="1"]');
    var runsBody = root.querySelector('[data-finance-recon-runs="1"]');
    var mappingsBody = root.querySelector('[data-finance-recon-mappings="1"]');
    var reviewQueueBody = root.querySelector('[data-finance-recon-review-queue="1"]');
    var reconLinesBody = root.querySelector('[data-finance-recon-lines="1"]');
    var reconManualItemsBody = root.querySelector('[data-finance-recon-manual-items="1"]');
    var reconHistoryBody = root.querySelector('[data-finance-recon-history="1"]');
    var reconAuditBody = root.querySelector('[data-finance-recon-audit="1"]');
    var reconStepNavButtons = root.querySelectorAll('[data-recon-step-nav]');
    var reconStepPages = root.querySelectorAll('[data-recon-step-page]');
    var reconSpaPanels = root.querySelectorAll('[data-recon-spa-panel]');
    var budgetVersionSelector = root.querySelector('[data-budget-version-selector="1"]');
    var budgetVersionStatus = root.querySelector('[data-budget-version-status="1"]');
    var budgetLinesBody = root.querySelector('[data-budget-lines="1"]');
    var budgetLinesSaveButton = root.querySelector('[data-budget-lines-save="1"]');
    var invoicesBody = root.querySelector('[data-finance-invoices="1"]');
    var fiscalPeriodsBody = root.querySelector('[data-finance-fiscal-periods="1"]');
    var reportPreviewBody = root.querySelector('[data-finance-report-preview="1"]');
    var payoutsBody = root.querySelector('[data-finance-stripe-payouts="1"]');
    var bankLinesBody = root.querySelector('[data-finance-bank-lines="1"]');
    var stripeAutoMatchButton = root.querySelector('[data-finance-stripe-auto-match="1"]');
    var stripeRefreshButton = root.querySelector('[data-finance-stripe-refresh="1"]');
    var categoriesListBody = root.querySelector('[data-finance-categories-list="1"]');

    var accountSelect = root.querySelector('[data-finance-account-select="1"]');
    var categorySelect = root.querySelector('[data-finance-category-select="1"]');
    var platformTimezoneDisplay = root.querySelector('[data-finance-platform-timezone="1"]');

    var glForm = root.querySelector('[data-finance-gl-form="1"]');
    var glQuickRows = root.querySelector('[data-gl-quick-rows="1"]');
    var glModal = document.getElementById('metis-finance-gl-modal');
    var glOpenButtons = root.querySelectorAll('[data-open-gl-modal="1"]');
    var reconForm = root.querySelector('[data-finance-recon-form="1"]');
    var reconMappingForm = root.querySelector('[data-finance-recon-mapping-form="1"]');
    var categoryForm = root.querySelector('[data-finance-category-form="1"]');
    var budgetQuickForm = root.querySelector('[data-finance-budget-quick-form="1"]');
    var budgetVersionForm = root.querySelector('[data-finance-budget-version-form="1"]');
    var invoiceForm = root.querySelector('[data-finance-invoice-form="1"]');
    var invoiceModal = document.getElementById('metis-finance-invoice-modal');
    var invoiceOpenButtons = root.querySelectorAll('[data-open-invoice-modal="1"]');
    var fiscalSettingsForm = root.querySelector('[data-finance-fiscal-settings-form="1"]');
    var fiscalMigrateForm = root.querySelector('[data-finance-fiscal-migrate-form="1"]');
    var reportForm = root.querySelector('[data-finance-report-form="1"]');
    var stripeEventForm = root.querySelector('[data-finance-stripe-event-form="1"]');
    var stripePayoutForm = root.querySelector('[data-finance-stripe-payout-form="1"]');
    var bankLineForm = root.querySelector('[data-finance-bank-line-form="1"]');

    var state = {
        payouts: [],
        bankLines: [],
        reconMappings: [],
        reconReviewQueue: [],
        reconSuggestions: [],
        budget: null,
        invoices: null,
        summary: null,
        stripeOverview: null,
        stripeSuggestions: null,
        performance: null,
        reportPreview: null,
        fiscal: null,
        reconciliation: null,
        reconSelectedMonthId: 0
    };

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
        });
    }

    function toast(type, message) {
        if (window.Metis && Metis.util && typeof Metis.util.notify === 'function') {
            Metis.util.notify(String(message || ''), type || 'info');
            return;
        }
        if (window.console && typeof window.console.log === 'function') {
            window.console.log(type + ': ' + message);
        }
    }

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

    function activateReconStep(stepKey) {
        var key = String(stepKey || '').trim().toLowerCase();
        if (!key) key = 'setup';
        if (key !== 'setup' && key !== 'review' && key !== 'finalize') key = 'setup';

        reconStepPages.forEach(function (page) {
            var pageKey = String(page.getAttribute('data-recon-step-page') || '').trim().toLowerCase();
            page.classList.toggle('is-active', pageKey === key);
        });
        reconStepNavButtons.forEach(function (button) {
            var btnKey = String(button.getAttribute('data-recon-step-nav') || '').trim().toLowerCase();
            button.classList.toggle('is-active', btnKey === key);
        });
    }

    function setReconSpaView(viewKey) {
        if (currentSection !== 'reconciliation' || !reconSpaPanels || reconSpaPanels.length < 1) {
            return;
        }
        var key = String(viewKey || '').trim().toLowerCase();
        if (key !== 'workflow') {
            key = 'list';
        }
        reconSpaPanels.forEach(function (panel) {
            var panelKey = String(panel.getAttribute('data-recon-spa-panel') || '').trim().toLowerCase();
            panel.classList.toggle('is-active', panelKey === key);
        });
    }

    function readReconStepFromHash() {
        var hash = String(window.location.hash || '').replace(/^#/, '').trim().toLowerCase();
        if (hash === 'step-review') return 'review';
        if (hash === 'step-finalize') return 'finalize';
        if (hash === 'step-setup') return 'setup';
        return 'setup';
    }

    function formatMoney(value) {
        var n = Number(value || 0);
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDateTime(value) {
        var raw = String(value || '').trim();
        if (!raw) return '-';
        if (window.Metis && Metis.time && typeof Metis.time.format === 'function') {
            return Metis.time.format(raw, { empty: raw }) || raw;
        }
        var parsed = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return raw;
        return parsed.toLocaleString();
    }

    function parseResponse(response) {
        return response.text().then(function (text) {
            var json = {};
            try {
                json = text ? JSON.parse(text) : {};
            } catch (error) {
                throw new Error('Finance API returned an invalid response.');
            }

            if (!response.ok || !json || json.success !== true) {
                var message = (
                    (json && json.data && json.data.message) ||
                    (json && json.message) ||
                    (json && json.error && json.error.message) ||
                    ('Finance request failed (' + response.status + ').')
                );
                throw new Error(message);
            }

            return json.data || {};
        });
    }

    function appendPayloadValue(formData, key, value) {
        if (value == null) return;
        if (value instanceof File || value instanceof Blob) {
            formData.append(key, value);
            return;
        }
        if (typeof value === 'object') {
            formData.append(key, JSON.stringify(value));
            return;
        }
        formData.append(key, String(value));
    }

    function apiRequest(method, action, payload, csrfToken) {
        if (!action) {
            return Promise.reject(new Error('Finance action is missing.'));
        }
        var nonce = String(csrfToken || financeNonce || '');
        var formData = new FormData();
        formData.append('action', action);
        if (nonce) {
            formData.append('metis_action_nonce', nonce);
        }
        var body = payload && typeof payload === 'object' ? payload : {};
        Object.keys(body).forEach(function (key) {
            appendPayloadValue(formData, key, body[key]);
        });

        return fetch(ajaxEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: metisCsrfHeaders(nonce),
            body: formData
        }).then(parseResponse);
    }

    function apiUploadRequest(action, formData, csrfToken) {
        if (!action) {
            return Promise.reject(new Error('Finance action is missing.'));
        }
        var nonce = String(csrfToken || financeNonce || '');
        if (!(formData instanceof FormData)) {
            return Promise.reject(new Error('Finance upload payload is invalid.'));
        }
        formData.set('action', action);
        if (nonce) {
            formData.set('metis_action_nonce', nonce);
        }
        return fetch(ajaxEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: metisCsrfHeaders(nonce),
            body: formData
        }).then(parseResponse);
    }

    function applySummary(summary) {
        var data = summary && typeof summary === 'object' ? summary : {};
        state.summary = data;
        Object.keys(data).forEach(function (key) {
            var el = root.querySelector('[data-kpi="' + key + '"]');
            if (el) el.textContent = String(data[key]);
        });
        renderSnapshotOverview();
    }

    function applyStripeOverview(overview) {
        var data = overview && typeof overview === 'object' ? overview : {};
        state.stripeOverview = data;
        Object.keys(data).forEach(function (key) {
            var el = root.querySelector('[data-stripe-kpi="' + key + '"]');
            if (el) el.textContent = formatMoney(data[key]);
        });
        renderSnapshotOverview();
    }

    function setSnapshotValue(key, value, money) {
        var el = root.querySelector('[data-snapshot-value="' + key + '"]');
        if (!el) return;
        if (money) {
            el.textContent = formatMoney(value || 0);
            return;
        }
        if (value == null || value === '') {
            el.textContent = '-';
            return;
        }
        el.textContent = String(value);
    }

    function drawLineChart(canvas, labels, values, strokeColor) {
        if (!canvas || !canvas.getContext) return;
        var ctx = canvas.getContext('2d');
        var width = canvas.width = canvas.clientWidth || 520;
        var height = canvas.height = Number(canvas.getAttribute('height') || 180);
        ctx.clearRect(0, 0, width, height);
        if (!Array.isArray(values) || values.length < 2) return;

        var padding = 24;
        var min = Math.min.apply(null, values);
        var max = Math.max.apply(null, values);
        var range = Math.max(1, max - min);
        var stepX = (width - (padding * 2)) / Math.max(1, values.length - 1);

        ctx.strokeStyle = '#d1d8e5';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(padding, height - padding);
        ctx.lineTo(width - padding, height - padding);
        ctx.stroke();

        ctx.strokeStyle = strokeColor || '#4f46e5';
        ctx.lineWidth = 2;
        ctx.beginPath();
        values.forEach(function (value, idx) {
            var x = padding + (idx * stepX);
            var y = height - padding - (((Number(value) - min) / range) * (height - (padding * 2)));
            if (idx === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
        });
        ctx.stroke();

        ctx.fillStyle = '#6b7280';
        ctx.font = '11px system-ui, -apple-system, "Segoe UI", sans-serif';
        ctx.fillText(String(labels[0] || ''), padding, height - 6);
        ctx.fillText(String(labels[labels.length - 1] || ''), Math.max(padding, width - padding - 30), height - 6);
    }

    function drawBarChart(canvas, labels, values, fillColor) {
        if (!canvas || !canvas.getContext) return;
        var ctx = canvas.getContext('2d');
        var width = canvas.width = canvas.clientWidth || 520;
        var height = canvas.height = Number(canvas.getAttribute('height') || 180);
        ctx.clearRect(0, 0, width, height);
        if (!Array.isArray(values) || !values.length) return;

        var padding = 24;
        var max = Math.max(1, Math.max.apply(null, values.map(function (value) { return Number(value) || 0; })));
        var barWidth = (width - (padding * 2)) / values.length;

        ctx.strokeStyle = '#d1d8e5';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(padding, height - padding);
        ctx.lineTo(width - padding, height - padding);
        ctx.stroke();

        ctx.fillStyle = fillColor || '#0891b2';
        values.forEach(function (value, idx) {
            var h = ((Number(value) || 0) / max) * (height - (padding * 2));
            var x = padding + (idx * barWidth) + 3;
            var y = height - padding - h;
            ctx.fillRect(x, y, Math.max(6, barWidth - 6), h);
        });

        ctx.fillStyle = '#6b7280';
        ctx.font = '11px system-ui, -apple-system, "Segoe UI", sans-serif';
        ctx.fillText(String(labels[0] || ''), padding, height - 6);
        ctx.fillText(String(labels[labels.length - 1] || ''), Math.max(padding, width - padding - 30), height - 6);
    }

    function renderPerformance(performance) {
        state.performance = performance && typeof performance === 'object' ? performance : {};
        var monthlyNet = Array.isArray(state.performance.monthly_net_activity) ? state.performance.monthly_net_activity : [];
        var invoiceTotals = Array.isArray(state.performance.monthly_invoice_totals) ? state.performance.monthly_invoice_totals : [];

        var netLabels = monthlyNet.map(function (row) { return String((row && row.month_label) || ''); });
        var netValues = monthlyNet.map(function (row) { return Number((row && row.net_amount) || 0); });
        var invoiceLabels = invoiceTotals.map(function (row) { return String((row && row.month_label) || ''); });
        var invoiceValues = invoiceTotals.map(function (row) { return Number((row && row.total_amount) || 0); });

        drawLineChart(root.querySelector('[data-finance-chart="monthly_net"]'), netLabels, netValues, '#4f46e5');
        drawBarChart(root.querySelector('[data-finance-chart="invoice_monthly"]'), invoiceLabels, invoiceValues, '#0891b2');
    }

    function renderSnapshotOverview() {
        var summary = state.summary && typeof state.summary === 'object' ? state.summary : {};
        var stripe = state.stripeOverview && typeof state.stripeOverview === 'object' ? state.stripeOverview : {};
        var budget = state.budget && typeof state.budget === 'object' ? state.budget : {};
        var invoices = state.invoices && typeof state.invoices === 'object' ? state.invoices : {};
        var reconciliation = state.reconciliation && typeof state.reconciliation === 'object' ? state.reconciliation : {};
        var reconMonth = reconciliation.current_month && typeof reconciliation.current_month === 'object' ? reconciliation.current_month : {};
        var reconTotals = reconciliation.totals && typeof reconciliation.totals === 'object' ? reconciliation.totals : {};
        var budgetLines = Array.isArray(budget.lines) ? budget.lines : [];
        var budgetSelected = budget && budget.selected_version && typeof budget.selected_version === 'object'
            ? budget.selected_version
            : {};
        var invoiceAging = invoices && typeof invoices.aging === 'object' ? invoices.aging : {};

        var budgetPlanned = 0;
        var budgetActual = 0;
        budgetLines.forEach(function (row) {
            budgetPlanned += Number((row && row.planned_amount) || 0);
            budgetActual += Number((row && row.actual_amount) || 0);
        });

        var invoiceOverdue = Number(invoiceAging.overdue_1_30_amount || 0)
            + Number(invoiceAging.overdue_31_60_amount || 0)
            + Number(invoiceAging.overdue_61_plus_amount || 0);

        setSnapshotValue('total_debits', summary.total_debits || 0, true);
        setSnapshotValue('total_credits', summary.total_credits || 0, true);
        setSnapshotValue('net_activity', Number(summary.total_debits || 0) - Number(summary.total_credits || 0), true);
        setSnapshotValue('autosuggest_count', summary.autosuggest_count || 0, false);
        setSnapshotValue('review_count', summary.review_count || 0, false);
        setSnapshotValue('manual_count', summary.manual_count || 0, false);
        setSnapshotValue('recon_month_label', reconMonth.recon_month_key || '-', false);
        setSnapshotValue(
            'recon_month_status',
            reconMonth.status
                ? String(reconMonth.status).replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); })
                : '-',
            false
        );
        setSnapshotValue('recon_cleared_count', reconTotals.cleared_count || 0, false);
        setSnapshotValue('recon_difference_amount', reconMonth.difference_amount || 0, true);
        setSnapshotValue('budget_version_label', budgetSelected.version_label || '-', false);
        setSnapshotValue('budget_planned_total', budgetPlanned, true);
        setSnapshotValue('budget_actual_total', budgetActual, true);
        setSnapshotValue('budget_variance_total', budgetActual - budgetPlanned, true);
        setSnapshotValue('invoice_open_count', invoiceAging.open_count || 0, false);
        setSnapshotValue('invoice_open_total', invoiceAging.open_total || 0, true);
        setSnapshotValue('invoice_overdue_total', invoiceOverdue, true);
        setSnapshotValue('invoice_draft_count', invoiceAging.draft_count || 0, false);
        setSnapshotValue('stripe_clearing_balance', stripe.clearing_balance || 0, true);
        setSnapshotValue('stripe_expected_total', stripe.expected_total || 0, true);
        setSnapshotValue('matched_payouts', summary.matched_payouts || 0, false);
        setSnapshotValue('unmatched_bank_lines', summary.unmatched_bank_lines || 0, false);
    }

    function renderAccounts(accounts) {
        if (!accountSelect) return;
        var rows = Array.isArray(accounts) ? accounts : [];
        var options = rows.map(function (row) {
            var code = String((row && row.account_code) || '');
            var name = String((row && row.account_name) || code);
            return '<option value="' + esc(code) + '">' + esc(name) + '</option>';
        }).join('');
        accountSelect.innerHTML = options || '<option value="">No accounts</option>';
    }

    function renderCategories(categories) {
        if (!categorySelect) return;
        var rows = Array.isArray(categories) ? categories : [];
        var options = ['<option value="">None</option>'];
        rows.forEach(function (row) {
            var code = String((row && row.category_code) || '');
            var name = String((row && row.category_name) || code);
            options.push('<option value="' + esc(code) + '">' + esc(name) + '</option>');
        });
        categorySelect.innerHTML = options.join('');
    }

    function renderCategoriesList(categories) {
        if (!categoriesListBody) return;
        var rows = Array.isArray(categories) ? categories : [];
        if (!rows.length) {
            categoriesListBody.innerHTML = '<tr><td colspan="2" class="metis-finance-v2-empty">No categories yet.</td></tr>';
            return;
        }

        categoriesListBody.innerHTML = rows.map(function (row) {
            return '<tr><td><code>' + esc(row.category_code || '') + '</code></td><td>' + esc(row.category_name || '') + '</td></tr>';
        }).join('');
    }

    function renderEntries(entries) {
        if (!entriesBody) return;
        var rows = Array.isArray(entries) ? entries : [];
        if (!rows.length) {
            entriesBody.innerHTML = '<tr><td colspan="7" class="metis-finance-v2-empty">No entries yet.</td></tr>';
            return;
        }

        entriesBody.innerHTML = rows.map(function (row) {
            var amountSigned = Number((row && row.amount_signed) || 0);
            var type = String((row && row.dc_type) || '').toUpperCase();
            var amountClass = amountSigned < 0 ? 'is-negative' : 'is-positive';
            return [
                '<tr>',
                '  <td>' + esc(row.entry_date || '') + '</td>',
                '  <td><code>' + esc(row.account_code || '') + '</code></td>',
                '  <td>' + esc(row.description || '') + '</td>',
                '  <td>' + esc(type || '-') + '</td>',
                '  <td class="' + amountClass + '">' + esc(formatMoney(amountSigned)) + '</td>',
                '  <td>' + esc(row.category_code || '-') + '</td>',
                '  <td>' + esc(row.reconciliation_status || '-') + '</td>',
                '</tr>'
            ].join('');
        }).join('');
    }

    function renderRuns(runs) {
        if (!runsBody) return;
        var rows = Array.isArray(runs) ? runs : [];
        if (!rows.length) {
            runsBody.innerHTML = '<tr><td colspan="5" class="metis-finance-v2-empty">No import runs yet.</td></tr>';
            return;
        }

        runsBody.innerHTML = rows.map(function (row) {
            return [
                '<tr>',
                '  <td>' + esc(formatDateTime(row.created_at || '')) + '</td>',
                '  <td>' + esc(String((row.import_type || '')).toUpperCase()) + '</td>',
                '  <td>' + esc(row.file_name || '') + '</td>',
                '  <td>' + esc(row.confidence_band || '') + '</td>',
                '  <td>' + esc(row.status || '') + '</td>',
                '</tr>'
            ].join('');
        }).join('');
    }

    function renderMappings(mappings) {
        if (!mappingsBody) return;
        state.reconMappings = Array.isArray(mappings) ? mappings : [];

        if (!state.reconMappings.length) {
            mappingsBody.innerHTML = '<tr><td colspan="6" class="metis-finance-v2-empty">No mappings saved.</td></tr>';
            return;
        }

        mappingsBody.innerHTML = state.reconMappings.map(function (row) {
            var mapping = row && typeof row.mapping === 'object' ? row.mapping : {};
            return [
                '<tr>',
                '  <td>' + esc(String((row.import_type || '')).toUpperCase()) + '</td>',
                '  <td>' + esc(row.mapping_name || '') + '</td>',
                '  <td>' + esc(mapping.date_column || '-') + '</td>',
                '  <td>' + esc(mapping.description_column || '-') + '</td>',
                '  <td>' + esc(mapping.amount_column || '-') + '</td>',
                '  <td>' + (row.is_default ? 'Yes' : 'No') + '</td>',
                '</tr>'
            ].join('');
        }).join('');
    }

    function renderReviewQueue(items) {
        if (!reviewQueueBody) return;
        state.reconReviewQueue = Array.isArray(items) ? items : [];

        if (!state.reconReviewQueue.length) {
            reviewQueueBody.innerHTML = '<tr><td colspan="5" class="metis-finance-v2-empty">No review queue items.</td></tr>';
            return;
        }

        reviewQueueBody.innerHTML = state.reconReviewQueue.map(function (row) {
            var status = String(row.status || '');
            var actionCell = '-';

            if (status === 'pending_confirmation') {
                actionCell = [
                    '<div class="metis-finance-v2-actions">',
                    '  <button type="button" class="metis-btn metis-btn-xs" data-recon-review-action="approve" data-recon-review-id="' + esc(row.id) + '">Approve</button>',
                    '  <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-recon-review-action="manual_confirmed" data-recon-review-id="' + esc(row.id) + '">Adjust</button>',
                    '  <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-recon-review-action="reject" data-recon-review-id="' + esc(row.id) + '">Reject</button>',
                    '</div>'
                ].join('');
            }

            return [
                '<tr>',
                '  <td>#' + esc(row.recon_parse_run_id || 0) + '</td>',
                '  <td>' + esc(row.confidence_band || '') + '</td>',
                '  <td>' + esc(status || '') + '</td>',
                '  <td>' + esc(row.decision || '-') + '</td>',
                '  <td>' + actionCell + '</td>',
                '</tr>'
            ].join('');
        }).join('');
    }

    function suggestionForLine(lineId) {
        var items = Array.isArray(state.reconSuggestions) ? state.reconSuggestions : [];
        for (var i = 0; i < items.length; i += 1) {
            var suggestion = items[i] || {};
            if (Number(suggestion.bank_line_id || 0) === Number(lineId || 0)) {
                return suggestion;
            }
        }
        return null;
    }

    function renderReconciliationLines(lines, suggestions) {
        if (!reconLinesBody) return;
        state.reconSuggestions = Array.isArray(suggestions) ? suggestions : [];
        var rows = Array.isArray(lines) ? lines : [];
        if (!rows.length) {
            reconLinesBody.innerHTML = '<tr><td colspan="5" class="metis-finance-v2-empty">No statement lines imported yet.</td></tr>';
            return;
        }

        reconLinesBody.innerHTML = rows.map(function (row) {
            var suggestion = suggestionForLine(row.id);
            var suggestionLabel = suggestion && suggestion.suggested_label ? String(suggestion.suggested_label) : '-';
            var actionCell = '-';

            if (suggestion && suggestion.suggested_type && Number(suggestion.suggested_id || 0) > 0) {
                actionCell = '<button type="button" class="metis-btn metis-btn-xs" data-recon-line-match="1" data-bank-line-id="' + esc(row.id) + '" data-match-type="' + esc(suggestion.suggested_type) + '" data-match-id="' + esc(suggestion.suggested_id) + '">Match</button>';
            }

            return [
                '<tr>',
                '  <td>' + esc(row.line_date || '') + '</td>',
                '  <td>' + esc(row.description || '') + '</td>',
                '  <td>' + esc(formatMoney(row.amount_signed || 0)) + '</td>',
                '  <td>' + esc(suggestionLabel) + '</td>',
                '  <td>' + actionCell + '</td>',
                '</tr>'
            ].join('');
        }).join('');
    }

    function renderManualReconciliation(workflow) {
        state.reconciliation = workflow && typeof workflow === 'object' ? workflow : null;
        var data = state.reconciliation || {};
        var month = data.current_month && typeof data.current_month === 'object' ? data.current_month : {};
        var totals = data.totals && typeof data.totals === 'object' ? data.totals : {};
        var items = Array.isArray(data.items) ? data.items : [];
        var history = Array.isArray(data.history) ? data.history : [];
        var audit = Array.isArray(data.audit) ? data.audit : [];
        var isBalanced = !!data.is_balanced;
        var currentMonthId = Number(month.id || 0);
        if (currentMonthId > 0) {
            state.reconSelectedMonthId = currentMonthId;
        }
        function monthLabel(value) {
            var raw = String(value || '').trim();
            if (!raw) return '-';
            var parts = raw.split('-');
            if (parts.length < 2) return raw;
            var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);
            if (Number.isNaN(dt.getTime())) return raw;
            if (window.Metis && Metis.time && typeof Metis.time.formatDate === 'function') {
                return Metis.time.formatDate(dt, { format: 'F Y', empty: raw }) || raw;
            }
            return dt.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        }
        function statusLabel(value) {
            var key = String(value || '').trim().toLowerCase();
            if (key === 'finalized') return 'Finalized';
            if (key === 'reopened') return 'Reopened';
            if (key === 'open') return 'Open';
            if (key === 'not_started') return 'Not Started';
            return key ? key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) : '-';
        }
        function eventLabel(value) {
            var key = String(value || '').trim().toLowerCase();
            if (key === 'started') return 'Month Started';
            if (key === 'finalized') return 'Month Finalized';
            if (key === 'reopened') return 'Month Reopened';
            return key ? key.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) : '-';
        }

        var monthInput = reconForm ? reconForm.querySelector('[name="recon_month"]') : null;
        if (monthInput && month.recon_month_key) {
            monthInput.value = String(month.recon_month_key);
        }
        var startInput = reconForm ? reconForm.querySelector('[name="starting_balance"]') : null;
        if (startInput && month.starting_balance != null && startInput.value === '') {
            startInput.value = String(month.starting_balance);
        }
        var endingInput = reconForm ? reconForm.querySelector('[name="statement_ending_balance"]') : null;
        if (endingInput && month.statement_ending_balance != null && Number(month.statement_ending_balance) !== 0 && endingInput.value === '') {
            endingInput.value = String(month.statement_ending_balance);
        }

        var statementLink = root.querySelector('[data-recon-statement-link="1"]');
        if (statementLink) {
            var fileUrl = String(month.statement_media_url || '').trim();
            var fileName = String(month.statement_file_name || '').trim();
            if (fileUrl) {
                statementLink.href = fileUrl;
                statementLink.textContent = fileName || 'Open Statement';
                statementLink.style.pointerEvents = '';
            } else {
                statementLink.href = '#';
                statementLink.textContent = 'Not Attached';
                statementLink.style.pointerEvents = 'none';
            }
        }

        var pairs = [
            ['data-recon-current-month-label', monthLabel(month.recon_month_key || month.recon_month || '')],
            ['data-recon-current-month-status', statusLabel(month.status || '')],
            ['data-recon-starting-balance', formatMoney(month.starting_balance || 0)],
            ['data-recon-cleared-net', formatMoney(totals.cleared_net || 0)],
            ['data-recon-expected-ending', formatMoney(month.expected_ending_balance || 0)],
            ['data-recon-statement-ending', formatMoney(month.statement_ending_balance || 0)],
            ['data-recon-difference', formatMoney(month.difference_amount || 0)],
            ['data-recon-entry-count', String(totals.entry_count || 0)],
            ['data-recon-cleared-count', String(totals.cleared_count || 0)],
            ['data-recon-uncleared-net', formatMoney(totals.uncleared_net || 0)],
            ['data-recon-balanced-label', isBalanced ? 'Yes' : 'No']
        ];
        pairs.forEach(function (row) {
            var el = root.querySelector('[' + row[0] + '="1"]');
            if (el) el.textContent = row[1];
        });

        if (reconManualItemsBody) {
            if (!items.length) {
                reconManualItemsBody.innerHTML = '<tr><td colspan="6" class="metis-finance-v2-empty">No transactions are available for this month.</td></tr>';
            } else {
                reconManualItemsBody.innerHTML = items.map(function (item) {
                    var checked = item.is_cleared ? ' checked' : '';
                    var disabled = String(month.status || '') === 'finalized' ? ' disabled' : '';
                    return [
                        '<tr>',
                        '  <td><input type="checkbox" data-recon-item-toggle="1" data-recon-item-id="' + esc(item.item_id || 0) + '"' + checked + disabled + '></td>',
                        '  <td>' + esc(item.entry_date || '') + '</td>',
                        '  <td><code>' + esc(item.account_code || '') + '</code></td>',
                        '  <td>' + esc(item.description || '') + '</td>',
                        '  <td>' + esc(formatMoney(item.amount_signed || 0)) + '</td>',
                        '  <td>' + esc(item.category_code || '-') + '</td>',
                        '</tr>'
                    ].join('');
                }).join('');
            }
        }

        if (reconHistoryBody) {
            if (!history.length) {
                reconHistoryBody.innerHTML = '<tr><td colspan="5" class="metis-finance-v2-empty">No reconciliation months yet.</td></tr>';
            } else {
                reconHistoryBody.innerHTML = history.map(function (row) {
                    var monthId = Number(row.id || 0);
                    var active = Number(month.id || 0) === monthId ? ' class="is-active"' : '';
                    var statementCell = row.statement_media_url
                        ? '<a class="metis-btn metis-btn-xs metis-btn-ghost" href="' + esc(row.statement_media_url) + '" target="_blank" rel="noopener">Open</a>'
                        : '-';
                    var monthCell = '<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-recon-month-select="' + esc(monthId) + '">' + esc(monthLabel(String(row.recon_month || '').slice(0, 7))) + '</button>';
                    var openCell = currentSection === 'reconciliation' && reconStepsUrl
                        ? '<button type="button" class="metis-btn metis-btn-xs" data-recon-open-steps="' + esc(monthId) + '">Open Workflow</button>'
                        : '<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-recon-month-select="' + esc(monthId) + '">Select</button>';
                    if (currentSection === 'reconciliation' && row && row.can_delete) {
                        openCell += ' <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-recon-delete-month="' + esc(monthId) + '">Delete</button>';
                    }
                    return [
                        '<tr' + active + '>',
                        '  <td>' + monthCell + '</td>',
                        '  <td>' + esc(statusLabel(row.status || '')) + '</td>',
                        '  <td>' + statementCell + '</td>',
                        '  <td>' + esc(formatMoney(row.difference_amount || 0)) + '</td>',
                        '  <td>' + openCell + '</td>',
                        '</tr>'
                    ].join('');
                }).join('');
            }
        }

        if (reconAuditBody) {
            if (!audit.length) {
                reconAuditBody.innerHTML = '<tr><td colspan="4" class="metis-finance-v2-empty">No reconciliation audit events yet.</td></tr>';
            } else {
                reconAuditBody.innerHTML = audit.map(function (row) {
                    return [
                        '<tr>',
                        '  <td>' + esc(formatDateTime(row.created_at || '')) + '</td>',
                        '  <td>' + esc(eventLabel(row.event_type || '')) + '</td>',
                        '  <td>' + esc(row.reason_text || '-') + '</td>',
                        '  <td>' + esc(String(row.actor_name || '').trim() || '-') + '</td>',
                        '</tr>'
                    ].join('');
                }).join('');
            }
        }

        var finalizeBtn = root.querySelector('[data-finance-recon-finalize="1"]');
        if (finalizeBtn) {
            finalizeBtn.disabled = !(data.can_finalize === true);
        }
        var reopenBtn = root.querySelector('[data-finance-recon-reopen="1"]');
        if (reopenBtn) {
            var selectedMonthStatus = String(month.status || '').toLowerCase();
            reopenBtn.disabled = !(selectedMonthStatus === 'finalized' && state.reconSelectedMonthId > 0);
        }
    }

    function renderBudget(snapshot) {
        state.budget = snapshot && typeof snapshot === 'object' ? snapshot : null;
        var budget = state.budget || {};
        var versions = Array.isArray(budget.versions) ? budget.versions : [];
        var lines = Array.isArray(budget.lines) ? budget.lines : [];
        var selectedVersionId = Number(budget.selected_version_id || 0);
        var selectedVersion = budget.selected_version && typeof budget.selected_version === 'object' ? budget.selected_version : null;
        var canEdit = !!budget.can_edit;

        if (budgetVersionSelector) {
            if (!versions.length) {
                budgetVersionSelector.innerHTML = '<option value="">No versions</option>';
            } else {
                budgetVersionSelector.innerHTML = versions.map(function (row) {
                    var id = Number(row.id || 0);
                    var label = String(row.version_label || ('Version #' + id));
                    var suffix = row.is_locked ? ' (Locked)' : ' (Active)';
                    return '<option value="' + esc(id) + '"' + (id === selectedVersionId ? ' selected' : '') + '>' + esc(label + suffix) + '</option>';
                }).join('');
            }
        }

        if (budgetVersionStatus) {
            if (!selectedVersion) {
                budgetVersionStatus.textContent = 'No version selected.';
            } else {
                var statusLabel = selectedVersion.is_locked ? 'Locked historical version' : 'Active editable version';
                budgetVersionStatus.textContent = statusLabel + ' | ' + String(selectedVersion.period_start || '-') + ' to ' + String(selectedVersion.period_end || '-');
            }
        }

        if (!budgetLinesBody) return;
        if (!lines.length) {
            budgetLinesBody.innerHTML = '<tr><td colspan="4" class="metis-finance-v2-empty">No budget lines available.</td></tr>';
            return;
        }

        budgetLinesBody.innerHTML = lines.map(function (row) {
            var planned = Number(row.planned_amount || 0);
            var actual = Number(row.actual_amount || 0);
            var variance = Number(row.variance_amount || 0);
            var varianceClass = variance > 0 ? 'is-negative' : (variance < 0 ? 'is-positive' : '');
            var plannedCell = canEdit
                ? '<input type="number" step="0.01" class="metis-input metis-budget-line-input" data-budget-line-account="' + esc(row.account_code || '') + '" value="' + esc(planned) + '">'
                : esc(formatMoney(planned));

            return [
                '<tr>',
                '  <td><strong>' + esc(row.account_name || row.account_code || '') + '</strong><br><code>' + esc(row.account_code || '') + '</code></td>',
                '  <td>' + plannedCell + '</td>',
                '  <td>' + esc(formatMoney(actual)) + '</td>',
                '  <td class="' + varianceClass + '">' + esc(formatMoney(variance)) + '</td>',
                '</tr>'
            ].join('');
        }).join('');

        if (budgetLinesSaveButton) {
            budgetLinesSaveButton.disabled = !(canEdit && selectedVersionId > 0);
        }
        renderSnapshotOverview();
    }

    function renderInvoiceAging(aging) {
        var data = aging && typeof aging === 'object' ? aging : {};
        Object.keys(data).forEach(function (key) {
            var el = root.querySelector('[data-invoice-aging="' + key + '"]');
            if (el) el.textContent = formatMoney(data[key] || 0);
        });
    }

    function renderInvoices(snapshot) {
        state.invoices = snapshot && typeof snapshot === 'object' ? snapshot : null;
        var payload = state.invoices || {};
        var rows = Array.isArray(payload.rows) ? payload.rows : [];
        renderInvoiceAging(payload.aging || {});

        if (!invoicesBody) return;
        if (!rows.length) {
            invoicesBody.innerHTML = '<tr><td colspan="8" class="metis-finance-v2-empty">No invoices yet.</td></tr>';
            return;
        }

        invoicesBody.innerHTML = rows.map(function (row) {
            var status = String(row.status || '');
            var actionCell = '-';

            if (status !== 'paid') {
                var actions = [];
                if (status === 'draft') {
                    actions.push('<button type="button" class="metis-btn metis-btn-xs" data-invoice-send="' + esc(row.id) + '">Send</button>');
                }
                if (status === 'sent' || status === 'overdue' || status === 'draft') {
                    actions.push('<button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-invoice-paid="' + esc(row.id) + '">Mark Paid</button>');
                }
                actionCell = actions.length ? '<div class="metis-finance-v2-actions">' + actions.join('') + '</div>' : '-';
            }

            return [
                '<tr>',
                '  <td><strong>' + esc(row.invoice_number || '') + '</strong><br><small>' + esc((row.customer_email || '')) + '</small></td>',
                '  <td>' + esc(row.customer_name || '') + '</td>',
                '  <td>' + esc(status || '') + '</td>',
                '  <td>' + esc(row.issued_date || '') + '</td>',
                '  <td>' + esc(row.due_date || '') + '</td>',
                '  <td>' + esc(row.paid_date || '-') + '</td>',
                '  <td>' + esc(formatMoney(row.total_amount || 0)) + '</td>',
                '  <td>' + actionCell + '</td>',
                '</tr>'
            ].join('');
        }).join('');
        renderSnapshotOverview();
    }

    function renderReportPreview(report) {
        state.reportPreview = report && typeof report === 'object' ? report : null;
        var data = state.reportPreview || {};
        var rows = Array.isArray(data.rows) ? data.rows : [];
        var type = String(data.type || '');
        var totals = data.totals && typeof data.totals === 'object' ? data.totals : {};
        var previous = data.previous_month && typeof data.previous_month === 'object' ? data.previous_month : null;

        if (!reportPreviewBody) return;
        if (type !== 'balance_sheet' && !rows.length) {
            reportPreviewBody.innerHTML = '<tr><td colspan="3" class="metis-finance-v2-empty">No report rendered yet.</td></tr>';
            return;
        }

        var htmlRows = [];

        if (type === 'treasury_summary') {
            rows.forEach(function (row) {
                htmlRows.push('<tr><td>' + esc(row.metric || '') + '</td><td>' + esc(formatMoney(row.amount || 0)) + '</td><td>-</td></tr>');
            });
            if (previous) {
                htmlRows.push('<tr><td>Previous Month (' + esc(previous.label || '-') + ')</td><td>' + esc(formatMoney(previous.cash_movement || 0)) + '</td><td>' + esc(formatMoney(previous.clearing_movement || 0)) + '</td></tr>');
            }
        } else if (type === 'cash_flow') {
            rows.forEach(function (row) {
                htmlRows.push('<tr><td>' + esc(row.category_code || '') + '</td><td>' + esc(formatMoney(row.net_amount || 0)) + '</td><td>In ' + esc(formatMoney(row.inflow_amount || 0)) + ' / Out ' + esc(formatMoney(row.outflow_amount || 0)) + '</td></tr>');
            });
        } else if (type === 'budget_vs_actual') {
            rows.forEach(function (row) {
                htmlRows.push('<tr><td>' + esc(row.account_name || row.account_code || '') + '</td><td>' + esc(formatMoney(row.actual_amount || 0)) + '</td><td>Planned ' + esc(formatMoney(row.planned_amount || 0)) + ' / Var ' + esc(formatMoney(row.variance_amount || 0)) + '</td></tr>');
            });
        } else if (type === 'balance_sheet') {
            ['asset', 'liability', 'equity'].forEach(function (group) {
                var groupRows = data.rows && data.rows[group] && Array.isArray(data.rows[group].rows) ? data.rows[group].rows : [];
                groupRows.forEach(function (row) {
                    htmlRows.push('<tr><td>' + esc(group) + ' • ' + esc(row.account_name || row.account_code || '') + '</td><td>' + esc(formatMoney(row.amount || 0)) + '</td><td>' + esc(row.account_code || '') + '</td></tr>');
                });
            });
        }

        Object.keys(totals).forEach(function (key) {
            htmlRows.push('<tr><td><strong>Total ' + esc(String(key).replace(/_/g, ' ')) + '</strong></td><td><strong>' + esc(formatMoney(totals[key] || 0)) + '</strong></td><td>-</td></tr>');
        });

        reportPreviewBody.innerHTML = htmlRows.join('');
    }

    function renderFiscal(snapshot) {
        state.fiscal = snapshot && typeof snapshot === 'object' ? snapshot : null;
        var data = state.fiscal || {};
        var periods = Array.isArray(data.periods) ? data.periods : [];

        if (fiscalSettingsForm) {
            var monthSelect = fiscalSettingsForm.querySelector('[name="fiscal_year_start_month"]');
            if (monthSelect && data.fiscal_year_start_month) {
                monthSelect.value = String(data.fiscal_year_start_month);
            }
            if (platformTimezoneDisplay && data.timezone) {
                platformTimezoneDisplay.value = String(data.timezone);
            }
        }

        if (!fiscalPeriodsBody) return;
        if (!periods.length) {
            fiscalPeriodsBody.innerHTML = '<tr><td colspan="4" class="metis-finance-v2-empty">No fiscal periods yet.</td></tr>';
            return;
        }

        var activeId = Number(data.active_period_id || 0);
        fiscalPeriodsBody.innerHTML = periods.map(function (row) {
            var id = Number(row.id || 0);
            var status = String(row.status || '');
            if (activeId > 0 && id === activeId && status !== 'active') {
                status = 'active';
            }
            return '<tr><td>' + esc(row.label || '') + '</td><td>' + esc(row.start_date || '') + '</td><td>' + esc(row.end_date || '') + '</td><td>' + esc(status || '') + '</td></tr>';
        }).join('');
    }

    function resolveDefaultMapping(importType) {
        var key = String(importType || '').toLowerCase();
        var candidates = state.reconMappings.filter(function (row) {
            return String(row.import_type || '').toLowerCase() === key;
        });
        if (!candidates.length) return {};
        var def = candidates.find(function (row) { return !!row.is_default; }) || candidates[0];
        return def && def.mapping && typeof def.mapping === 'object' ? def.mapping : {};
    }

    function renderPayouts(payouts) {
        if (!payoutsBody) return;
        var allPayouts = Array.isArray(payouts) ? payouts : [];
        state.payouts = allPayouts.filter(function (row) {
            return String((row && row.status) || '') !== 'matched';
        });

        if (!state.payouts.length) {
            payoutsBody.innerHTML = '<tr><td colspan="5" class="metis-finance-v2-empty">No unmatched expected deposits.</td></tr>';
            return;
        }

        var unmatchedBankLines = state.bankLines.filter(function (line) {
            return String(line.status || '') !== 'matched';
        });

        payoutsBody.innerHTML = state.payouts.map(function (row) {
            var status = String(row.status || '');
            var amount = Number(row.expected_deposit_amount || 0);
            var matchCell = '-';

            if (status !== 'matched') {
                var options = ['<option value="">Select bank line</option>'];
                unmatchedBankLines.forEach(function (line) {
                    options.push('<option value="' + esc(line.id) + '">#' + esc(line.id) + ' ' + esc(line.line_date) + ' ' + esc(formatMoney(line.amount_signed)) + '</option>');
                });
                matchCell = [
                    '<div class="metis-finance-v2-match-wrap">',
                    '  <select class="metis-input" data-match-bank-line-select="' + esc(row.id) + '">' + options.join('') + '</select>',
                    '  <button type="button" class="metis-btn metis-btn-xs" data-match-payout-btn="' + esc(row.id) + '">Match</button>',
                    '</div>'
                ].join('');
            } else if (status === 'matched') {
                matchCell = 'Line #' + esc(row.matched_bank_line_id || '-');
            }

            return [
                '<tr>',
                '  <td><code>' + esc(row.payout_id || '') + '</code></td>',
                '  <td>' + esc(row.payout_date || '') + '</td>',
                '  <td>' + esc(formatMoney(amount)) + '</td>',
                '  <td>' + esc(status) + '</td>',
                '  <td>' + matchCell + '</td>',
                '</tr>'
            ].join('');
        }).join('');
    }

    function renderStripeSuggestions(payload) {
        state.stripeSuggestions = payload && typeof payload === 'object' ? payload : {};
    }

    function renderBankLines(lines) {
        if (!bankLinesBody) return;
        var allLines = Array.isArray(lines) ? lines : [];
        state.bankLines = allLines.filter(function (row) {
            return String((row && row.status) || '') !== 'matched';
        });

        if (!state.bankLines.length) {
            bankLinesBody.innerHTML = '<tr><td colspan="5" class="metis-finance-v2-empty">No unmatched bank lines.</td></tr>';
            return;
        }

        bankLinesBody.innerHTML = state.bankLines.map(function (row) {
            return [
                '<tr>',
                '  <td>' + esc(row.line_date || '') + '</td>',
                '  <td>' + esc(row.description || '') + '</td>',
                '  <td>' + esc(formatMoney(row.amount_signed || 0)) + '</td>',
                '  <td>' + esc(row.status || '') + '</td>',
                '  <td>' + (row.matched_payout_id ? ('Deposit #' + esc(row.matched_payout_id)) : '-') + '</td>',
                '</tr>'
            ].join('');
        }).join('');
    }

    function applyBootstrap(data) {
        var payload = data && typeof data === 'object' ? data : {};
        applySummary(payload.summary || {});
        renderAccounts(payload.accounts || []);
        renderCategories(payload.categories || []);
        renderCategoriesList(payload.categories_all || payload.categories || []);
        renderEntries(payload.entries || []);
        renderManualReconciliation(payload.reconciliation || null);
        renderRuns(payload.reconciliation_runs || []);
        renderMappings(payload.reconciliation_mappings || []);
        renderReviewQueue(payload.reconciliation_review_queue || []);
        renderReconciliationLines(payload.statement_lines || [], payload.reconciliation_suggestions || []);
        renderBudget(payload.budget || null);
        renderInvoices(payload.invoices || null);
        renderFiscal(payload.fiscal || null);
        applyStripeOverview(payload.stripe_overview || {});
        renderStripeSuggestions(payload.stripe_suggestions || {});
        renderBankLines(payload.bank_lines || []);
        renderPayouts(payload.stripe_payouts || []);
        renderPerformance(payload.performance || {});
    }

    function refreshEntries() {
        return apiRequest('GET', entriesListAction, null, financeNonce).then(function (data) {
            renderEntries(data.entries || []);
            applySummary(data.summary || {});
            return data;
        });
    }

    function refreshReconciliationWorkflow(monthId) {
        if (!reconWorkflowAction) return Promise.resolve(null);
        var payload = {};
        if (Number(monthId || 0) > 0) {
            payload.month_id = Number(monthId);
        }
        return apiRequest('GET', reconWorkflowAction, payload, financeNonce).then(function (data) {
            renderManualReconciliation(data.reconciliation || null);
            applySummary(data.summary || {});
            return data;
        });
    }

    function refreshBudget(versionId) {
        if (!budgetSnapshotAction) return Promise.resolve(null);
        var payload = {};
        if (versionId && Number(versionId) > 0) {
            payload.version_id = Number(versionId);
        }
        return apiRequest('GET', budgetSnapshotAction, payload, financeNonce).then(function (data) {
            renderBudget(data.budget || null);
            return data;
        });
    }

    function refreshInvoices() {
        if (!invoicesListAction) return Promise.resolve(null);
        return apiRequest('GET', invoicesListAction, null, financeNonce).then(function (data) {
            renderInvoices(data.invoices || null);
            return data;
        });
    }

    function refreshFiscal() {
        if (!fiscalSettingsGetAction) return Promise.resolve(null);
        return apiRequest('GET', fiscalSettingsGetAction, null, financeNonce).then(function (data) {
            renderFiscal(data.fiscal || null);
            return data;
        });
    }

    function reportFormPayload() {
        if (!reportForm) return null;
        var fd = new FormData(reportForm);
        return {
            report_type: String(fd.get('report_type') || ''),
            period_code: String(fd.get('period_code') || ''),
            orientation: String(fd.get('orientation') || 'landscape'),
            include_previous_month: fd.get('include_previous_month') ? 1 : 0
        };
    }

    function renderReportRequest() {
        if (!reportRenderAction) return Promise.reject(new Error('Report action is missing.'));
        var payload = reportFormPayload();
        if (!payload) return Promise.reject(new Error('Report form is unavailable.'));
        payload.nonce = reportRenderNonce;
        payload.metis_action_nonce = reportRenderNonce;
        return apiRequest('POST', reportRenderAction, payload, reportRenderNonce).then(function (data) {
            renderReportPreview(data.report || null);
            return data;
        });
    }

    function downloadBase64Pdf(filename, base64) {
        if (!base64) return;
        var bytes = atob(base64);
        var len = bytes.length;
        var arr = new Uint8Array(len);
        for (var i = 0; i < len; i += 1) arr[i] = bytes.charCodeAt(i);
        var blob = new Blob([arr], { type: 'application/pdf' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename || 'finance-report.pdf';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
    }

    function refreshStripePanels() {
        return apiRequest('GET', stripeOverviewAction, null, financeNonce).then(function (data) {
            applyStripeOverview(data.stripe_overview || {});
            renderBankLines(data.bank_lines || []);
            renderPayouts(data.stripe_payouts || []);
            applySummary(data.summary || {});
            return data;
        });
    }

    if (stripeAutoMatchButton) {
        stripeAutoMatchButton.addEventListener('click', function () {
            var original = stripeAutoMatchButton.textContent;
            stripeAutoMatchButton.disabled = true;
            stripeAutoMatchButton.textContent = 'Matching...';
            apiRequest('POST', stripeAutoMatchAction, {
                nonce: stripeMatchNonce,
                metis_action_nonce: stripeMatchNonce
            }, stripeMatchNonce).then(function (data) {
                var matchedCount = Number((data && data.matched_count) || 0);
                toast('success', matchedCount > 0
                    ? ('Auto-match completed. Matched ' + matchedCount + ' item(s).')
                    : 'Auto-match completed. No confident pairs found.');
                applyStripeOverview(data.stripe_overview || {});
                renderBankLines(data.bank_lines || []);
                renderPayouts(data.stripe_payouts || []);
                applySummary(data.summary || {});
                return refreshEntries();
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Auto-match failed.');
            }).finally(function () {
                stripeAutoMatchButton.disabled = false;
                stripeAutoMatchButton.textContent = original;
            });
        });
    }

    if (stripeRefreshButton) {
        stripeRefreshButton.addEventListener('click', function () {
            var original = stripeRefreshButton.textContent;
            stripeRefreshButton.disabled = true;
            stripeRefreshButton.textContent = 'Refreshing...';
            refreshStripePanels().catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to refresh Stripe clearing.');
            }).finally(function () {
                stripeRefreshButton.disabled = false;
                stripeRefreshButton.textContent = original;
            });
        });
    }

    function setDefaultDate(input) {
        if (!input || input.value) return;
        var now = new Date();
        input.value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    }

    if (glForm) {
        function glRowTemplate() {
            var accountOptions = accountSelect ? accountSelect.innerHTML : '<option value="">No accounts</option>';
            var categoryOptions = categorySelect ? categorySelect.innerHTML : '<option value="">None</option>';
            var now = new Date();
            var dateDefault = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
            return [
                '<tr data-gl-row="1">',
                '  <td><input type="date" class="metis-input" name="entry_date" value="' + esc(dateDefault) + '" required></td>',
                '  <td><select class="metis-input" name="account_code" required>' + accountOptions + '</select></td>',
                '  <td><input type="text" class="metis-input" name="description" maxlength="255" required></td>',
                '  <td><input type="number" class="metis-input" name="amount" step="0.01" required></td>',
                '  <td><select class="metis-input" name="dc_type"><option value="debit">Debit</option><option value="credit">Credit</option></select></td>',
                '  <td><select class="metis-input" name="category_code">' + categoryOptions + '</select></td>',
                '  <td><button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" data-gl-remove-row="1">Remove</button></td>',
                '</tr>'
            ].join('');
        }

        function ensureGlRows() {
            if (!glQuickRows) return;
            if (!glQuickRows.querySelector('[data-gl-row="1"]')) {
                glQuickRows.innerHTML = glRowTemplate();
            }
        }

        ensureGlRows();

        if (glOpenButtons && glOpenButtons.length && glModal && window.Metis && Metis.modal) {
            glOpenButtons.forEach(function (glOpenButton) {
                glOpenButton.addEventListener('click', function () {
                    ensureGlRows();
                    Metis.modal.open(glModal);
                });
            });
        }

        if (window.location.hash === '#open-gl-modal' && glModal && window.Metis && Metis.modal) {
            ensureGlRows();
            Metis.modal.open(glModal);
        }

        root.addEventListener('click', function (event) {
            var addBtn = event.target.closest('[data-gl-add-row="1"]');
            if (addBtn && glQuickRows) {
                glQuickRows.insertAdjacentHTML('beforeend', glRowTemplate());
                return;
            }

            var removeBtn = event.target.closest('[data-gl-remove-row="1"]');
            if (removeBtn && glQuickRows) {
                var row = removeBtn.closest('[data-gl-row="1"]');
                if (row) row.remove();
                ensureGlRows();
                return;
            }

            var closeBtn = event.target.closest('[data-close-gl-modal="1"]');
            if (closeBtn && glModal && window.Metis && Metis.modal) {
                Metis.modal.close(glModal);
            }
        });

        glForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = glForm.querySelector('[data-finance-gl-submit="1"]');
            var original = submit ? submit.textContent : 'Save Entries';
            var rows = glQuickRows ? Array.prototype.slice.call(glQuickRows.querySelectorAll('[data-gl-row="1"]')) : [];
            if (!rows.length) {
                toast('error', 'Add at least one GL row.');
                return;
            }
            var requests = rows.map(function (row) {
                return {
                    entry_date: String((row.querySelector('[name="entry_date"]') || {}).value || ''),
                    account_code: String((row.querySelector('[name="account_code"]') || {}).value || ''),
                    description: String((row.querySelector('[name="description"]') || {}).value || ''),
                    amount: String((row.querySelector('[name="amount"]') || {}).value || ''),
                    dc_type: String((row.querySelector('[name="dc_type"]') || {}).value || ''),
                    category_code: String((row.querySelector('[name="category_code"]') || {}).value || ''),
                    nonce: glCreateNonce,
                    metis_action_nonce: glCreateNonce
                };
            });

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Saving...';
            }

            Promise.all(requests.map(function (payload) {
                return apiRequest('POST', entriesCreateAction, payload, glCreateNonce);
            })).then(function () {
                return refreshEntries();
            }).then(function () {
                if (glQuickRows) {
                    glQuickRows.innerHTML = glRowTemplate();
                }
                toast('success', 'GL entries saved.');
                if (glModal && window.Metis && Metis.modal) {
                    Metis.modal.close(glModal);
                }
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to save GL entries.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (reconForm) {
        reconForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = reconForm.querySelector('[data-finance-recon-submit="1"]');
            var original = submit ? submit.textContent : 'Save Month Setup';
            var formData = new FormData(reconForm);
            var reconMonth = String(formData.get('recon_month') || '').trim();
            var statementEnding = String(formData.get('statement_ending_balance') || '').trim();
            var selectedFile = formData.get('recon_file');
            if (!reconMonth) {
                toast('error', 'Select reconciliation month.');
                return;
            }
            if (!statementEnding) {
                toast('error', 'Statement ending balance is required.');
                return;
            }
            var uploadPayload = new FormData();
            uploadPayload.append('recon_month', reconMonth);
            uploadPayload.append('starting_balance', String(formData.get('starting_balance') || ''));
            uploadPayload.append('statement_ending_balance', statementEnding);
            if (selectedFile && typeof selectedFile.name === 'string' && selectedFile.name) {
                uploadPayload.append('recon_file', selectedFile);
            }
            uploadPayload.append('nonce', reconImportNonce);
            uploadPayload.append('metis_action_nonce', reconImportNonce);

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Saving...';
            }

            apiUploadRequest(reconImportAction, uploadPayload, reconImportNonce).then(function (data) {
                renderManualReconciliation(data.reconciliation || null);
                applySummary(data.summary || {});
                toast('success', 'Reconciliation month setup saved.');
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to save reconciliation month.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (reconStepNavButtons && reconStepNavButtons.length > 0) {
        activateReconStep(readReconStepFromHash());
        reconStepNavButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var key = String(button.getAttribute('data-recon-step-nav') || '').trim().toLowerCase();
                var hash = '#step-setup';
                if (key === 'review') hash = '#step-review';
                if (key === 'finalize') hash = '#step-finalize';
                if (window.location.hash !== hash) {
                    window.history.replaceState(null, '', hash);
                }
                activateReconStep(key);
            });
        });
        window.addEventListener('hashchange', function () {
            activateReconStep(readReconStepFromHash());
        });
    }

    if (reconMappingForm) {
        reconMappingForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = reconMappingForm.querySelector('[data-finance-recon-mapping-submit="1"]');
            var original = submit ? submit.textContent : 'Save Mapping';
            var formData = new FormData(reconMappingForm);

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Saving...';
            }

            apiRequest('POST', reconMappingSaveAction, {
                import_type: String(formData.get('import_type') || ''),
                mapping_name: String(formData.get('mapping_name') || ''),
                mapping: {
                    date_column: String(formData.get('date_column') || ''),
                    description_column: String(formData.get('description_column') || ''),
                    amount_column: String(formData.get('amount_column') || '')
                },
                is_default: formData.get('is_default') ? 1 : 0,
                nonce: reconMappingNonce,
                metis_action_nonce: reconMappingNonce
            }, reconMappingNonce).then(function () {
                toast('success', 'Mapping saved.');
                reconMappingForm.reset();
                return refreshReconciliationWorkflow();
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to save mapping.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (categoryForm) {
        categoryForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var submit = categoryForm.querySelector('[data-finance-category-submit="1"]');
            var original = submit ? submit.textContent : 'Save Category';
            var formData = new FormData(categoryForm);

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Saving...';
            }

            apiRequest('POST', categoriesSaveAction, {
                category_name: String(formData.get('category_name') || ''),
                category_code: String(formData.get('category_code') || ''),
                is_active: 1,
                nonce: categorySaveNonce,
                metis_action_nonce: categorySaveNonce
            }, categorySaveNonce).then(function (data) {
                renderCategories(data.categories || []);
                renderCategoriesList(data.categories_all || data.categories || []);
                categoryForm.reset();
                toast('success', 'Category saved.');
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to save category.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (budgetVersionSelector) {
        budgetVersionSelector.addEventListener('change', function () {
            var versionId = Number(budgetVersionSelector.value || 0);
            refreshBudget(versionId).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to load budget version.');
            });
        });
    }

    function createBudgetVersion(payload, submit, originalText) {
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Creating...';
        }
        return apiRequest('POST', budgetVersionCreateAction, payload, budgetVersionNonce).then(function (data) {
            renderBudget(data.budget || null);
            applySummary(data.summary || {});
            toast('success', 'Budget version created and copied from prior version.');
        }).catch(function (error) {
            toast('error', error && error.message ? error.message : 'Failed to create budget version.');
        }).finally(function () {
            if (submit) {
                submit.disabled = false;
                submit.textContent = originalText;
            }
        });
    }

    if (budgetQuickForm) {
        budgetQuickForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var submit = budgetQuickForm.querySelector('[data-budget-quick-submit="1"]');
            var original = submit ? submit.textContent : 'Create From Previous';
            var formData = new FormData(budgetQuickForm);
            var label = String(formData.get('version_label') || '').trim();
            if (!label) {
                toast('error', 'Version label is required.');
                return;
            }

            var year = new Date().getFullYear();
            var start = new Date(year, 0, 1);
            var end = new Date(year, 11, 31);
            var fiscalSnapshot = state.fiscal && typeof state.fiscal === 'object' ? state.fiscal : {};
            var fiscalPeriods = Array.isArray(fiscalSnapshot.periods) ? fiscalSnapshot.periods : [];
            var activeFiscal = fiscalPeriods.find(function (row) {
                return String((row && row.status) || '').toLowerCase() === 'active';
            }) || null;
            if (activeFiscal && activeFiscal.start_date && activeFiscal.end_date) {
                start = new Date(String(activeFiscal.start_date) + 'T00:00:00');
                end = new Date(String(activeFiscal.end_date) + 'T00:00:00');
                if (!Number.isNaN(start.getTime())) {
                    year = start.getFullYear();
                }
            }
            var payload = {
                version_label: label,
                fiscal_year: String(year),
                period_start: start.getFullYear() + '-' + String(start.getMonth() + 1).padStart(2, '0') + '-' + String(start.getDate()).padStart(2, '0'),
                period_end: end.getFullYear() + '-' + String(end.getMonth() + 1).padStart(2, '0') + '-' + String(end.getDate()).padStart(2, '0'),
                source_version_id: state.budget && state.budget.selected_version_id ? Number(state.budget.selected_version_id) : '',
                nonce: budgetVersionNonce,
                metis_action_nonce: budgetVersionNonce
            };

            createBudgetVersion(payload, submit, original).then(function () {
                budgetQuickForm.reset();
            });
        });
    }

    if (budgetVersionForm) {
        var fiscalYearInput = budgetVersionForm.querySelector('[name="fiscal_year"]');
        if (fiscalYearInput && !fiscalYearInput.value) {
            fiscalYearInput.value = String(new Date().getFullYear());
        }
        var periodStartInput = budgetVersionForm.querySelector('[name="period_start"]');
        var periodEndInput = budgetVersionForm.querySelector('[name="period_end"]');
        setDefaultDate(periodStartInput);
        if (periodEndInput && !periodEndInput.value) {
            var now = new Date();
            var end = new Date(now.getFullYear(), 11, 31);
            periodEndInput.value = end.getFullYear() + '-' + String(end.getMonth() + 1).padStart(2, '0') + '-' + String(end.getDate()).padStart(2, '0');
        }

        budgetVersionForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = budgetVersionForm.querySelector('[data-budget-version-submit="1"]');
            var original = submit ? submit.textContent : 'Create Version';
            var formData = new FormData(budgetVersionForm);
            var currentVersionId = state.budget && state.budget.selected_version_id ? Number(state.budget.selected_version_id) : 0;

            createBudgetVersion({
                version_label: String(formData.get('version_label') || ''),
                fiscal_year: String(formData.get('fiscal_year') || ''),
                period_start: String(formData.get('period_start') || ''),
                period_end: String(formData.get('period_end') || ''),
                source_version_id: currentVersionId > 0 ? currentVersionId : '',
                nonce: budgetVersionNonce,
                metis_action_nonce: budgetVersionNonce
            }, submit, original).then(function () {
                budgetVersionForm.reset();
                if (fiscalYearInput) fiscalYearInput.value = String(new Date().getFullYear());
                setDefaultDate(periodStartInput);
                if (periodEndInput) {
                    var now = new Date();
                    var end = new Date(now.getFullYear(), 11, 31);
                    periodEndInput.value = end.getFullYear() + '-' + String(end.getMonth() + 1).padStart(2, '0') + '-' + String(end.getDate()).padStart(2, '0');
                }
            });
        });
    }

    if (invoiceForm) {
        var issuedDateInput = invoiceForm.querySelector('[name="issued_date"]');
        var dueDateInput = invoiceForm.querySelector('[name="due_date"]');
        setDefaultDate(issuedDateInput);
        if (dueDateInput && !dueDateInput.value) {
            dueDateInput.value = issuedDateInput && issuedDateInput.value ? issuedDateInput.value : '';
        }

        if (invoiceOpenButtons && invoiceOpenButtons.length && invoiceModal && window.Metis && Metis.modal) {
            invoiceOpenButtons.forEach(function (invoiceOpenButton) {
                invoiceOpenButton.addEventListener('click', function () {
                    Metis.modal.open(invoiceModal);
                });
            });
        }
        if (window.location.hash === '#open-invoice-modal' && invoiceModal && window.Metis && Metis.modal) {
            Metis.modal.open(invoiceModal);
        }

        root.addEventListener('click', function (event) {
            var closeBtn = event.target.closest('[data-close-invoice-modal="1"]');
            if (closeBtn && invoiceModal && window.Metis && Metis.modal) {
                Metis.modal.close(invoiceModal);
            }
        });

        invoiceForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = invoiceForm.querySelector('[data-finance-invoice-submit="1"]');
            var original = submit ? submit.textContent : 'Create Invoice';
            var formData = new FormData(invoiceForm);

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Saving...';
            }

            apiRequest('POST', invoicesCreateAction, {
                customer_name: String(formData.get('customer_name') || ''),
                customer_email: String(formData.get('customer_email') || ''),
                issued_date: String(formData.get('issued_date') || ''),
                due_date: String(formData.get('due_date') || ''),
                line_description: String(formData.get('line_description') || ''),
                line_quantity: String(formData.get('line_quantity') || ''),
                line_unit_amount: String(formData.get('line_unit_amount') || ''),
                notes: String(formData.get('notes') || ''),
                currency: 'usd',
                nonce: invoiceCreateNonce,
                metis_action_nonce: invoiceCreateNonce
            }, invoiceCreateNonce).then(function (data) {
                renderInvoices(data.invoices || null);
                toast('success', 'Invoice created.');
                invoiceForm.reset();
                setDefaultDate(issuedDateInput);
                if (dueDateInput) {
                    dueDateInput.value = issuedDateInput && issuedDateInput.value ? issuedDateInput.value : '';
                }
                if (invoiceModal && window.Metis && Metis.modal) {
                    Metis.modal.close(invoiceModal);
                }
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to create invoice.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (fiscalSettingsForm) {
        fiscalSettingsForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = fiscalSettingsForm.querySelector('[data-finance-fiscal-settings-submit="1"]');
            var original = submit ? submit.textContent : 'Save Fiscal Settings';
            var fd = new FormData(fiscalSettingsForm);

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Saving...';
            }

            apiRequest('POST', fiscalSettingsUpdateAction, {
                fiscal_year_start_month: String(fd.get('fiscal_year_start_month') || '1'),
                nonce: fiscalSettingsNonce,
                metis_action_nonce: fiscalSettingsNonce
            }, fiscalSettingsNonce).then(function (data) {
                renderFiscal(data.fiscal || null);
                toast('success', 'Fiscal settings updated.');
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to update fiscal settings.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (fiscalMigrateForm) {
        var fiscalStartInput = fiscalMigrateForm.querySelector('[name="start_date"]');
        var fiscalEndInput = fiscalMigrateForm.querySelector('[name="end_date"]');
        setDefaultDate(fiscalStartInput);
        if (fiscalEndInput && !fiscalEndInput.value) {
            var now = new Date();
            var end = new Date(now.getFullYear(), 11, 31);
            fiscalEndInput.value = end.getFullYear() + '-' + String(end.getMonth() + 1).padStart(2, '0') + '-' + String(end.getDate()).padStart(2, '0');
        }

        fiscalMigrateForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = fiscalMigrateForm.querySelector('[data-finance-fiscal-migrate-submit="1"]');
            var original = submit ? submit.textContent : 'Migrate Fiscal Period';
            var fd = new FormData(fiscalMigrateForm);

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Migrating...';
            }

            apiRequest('POST', fiscalMigrateAction, {
                label: String(fd.get('label') || ''),
                start_date: String(fd.get('start_date') || ''),
                end_date: String(fd.get('end_date') || ''),
                nonce: fiscalMigrateNonce,
                metis_action_nonce: fiscalMigrateNonce
            }, fiscalMigrateNonce).then(function (data) {
                renderFiscal(data.fiscal || null);
                toast('success', 'Fiscal period migration completed.');
                fiscalMigrateForm.reset();
                setDefaultDate(fiscalStartInput);
                if (fiscalEndInput) {
                    var now = new Date();
                    var end = new Date(now.getFullYear(), 11, 31);
                    fiscalEndInput.value = end.getFullYear() + '-' + String(end.getMonth() + 1).padStart(2, '0') + '-' + String(end.getDate()).padStart(2, '0');
                }
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to migrate fiscal period.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (reportForm) {
        var renderBtn = reportForm.querySelector('[data-finance-report-render="1"]');
        if (renderBtn) {
            renderBtn.addEventListener('click', function () {
                var original = renderBtn.textContent;
                renderBtn.disabled = true;
                renderBtn.textContent = 'Rendering...';
                renderReportRequest().then(function () {
                    toast('success', 'Report rendered.');
                }).catch(function (error) {
                    toast('error', error && error.message ? error.message : 'Failed to render report.');
                }).finally(function () {
                    renderBtn.disabled = false;
                    renderBtn.textContent = original;
                });
            });
        }

        reportForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var submit = reportForm.querySelector('[data-finance-report-pdf="1"]');
            var original = submit ? submit.textContent : 'Download PDF';
            var payload = reportFormPayload();
            if (!payload) return;
            payload.nonce = reportPdfNonce;
            payload.metis_action_nonce = reportPdfNonce;

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Generating...';
            }

            apiRequest('POST', reportPdfAction, payload, reportPdfNonce).then(function (data) {
                if (data && data.report) {
                    renderReportPreview(data.report);
                }
                downloadBase64Pdf(String((data && data.filename) || 'finance-report.pdf'), String((data && data.content_base64) || ''));
                toast('success', 'PDF generated.');
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to generate PDF.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (stripeEventForm) {
        var stripeEventDate = stripeEventForm.querySelector('[name="event_date"]');
        setDefaultDate(stripeEventDate);

        stripeEventForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = stripeEventForm.querySelector('[data-finance-stripe-event-submit="1"]');
            var original = submit ? submit.textContent : 'Add Event';
            var formData = new FormData(stripeEventForm);

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Saving...';
            }

            apiRequest('POST', stripeEventCreateAction, {
                event_type: String(formData.get('event_type') || ''),
                event_date: String(formData.get('event_date') || ''),
                reference_id: String(formData.get('reference_id') || ''),
                amount: String(formData.get('amount') || ''),
                description: String(formData.get('description') || ''),
                nonce: stripeEventNonce,
                metis_action_nonce: stripeEventNonce
            }, stripeEventNonce).then(function (data) {
                applyStripeOverview(data.stripe_overview || {});
                applySummary(data.summary || {});
                toast('success', 'Stripe clearing event added.');
                stripeEventForm.reset();
                setDefaultDate(stripeEventDate);
                return refreshEntries();
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to add Stripe event.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (stripePayoutForm) {
        var payoutDateInput = stripePayoutForm.querySelector('[name="payout_date"]');
        setDefaultDate(payoutDateInput);

        stripePayoutForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = stripePayoutForm.querySelector('[data-finance-stripe-payout-submit="1"]');
            var original = submit ? submit.textContent : 'Create Expected Deposit';
            var formData = new FormData(stripePayoutForm);

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Saving...';
            }

            apiRequest('POST', stripePayoutCreateAction, {
                payout_id: String(formData.get('payout_id') || ''),
                payout_date: String(formData.get('payout_date') || ''),
                expected_deposit_amount: String(formData.get('expected_deposit_amount') || ''),
                bank_account_label: String(formData.get('bank_account_label') || ''),
                nonce: stripePayoutNonce,
                metis_action_nonce: stripePayoutNonce
            }, stripePayoutNonce).then(function () {
                toast('success', 'Expected payout deposit created.');
                stripePayoutForm.reset();
                setDefaultDate(payoutDateInput);
                return refreshStripePanels();
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to create expected payout.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    if (bankLineForm) {
        var bankLineDateInput = bankLineForm.querySelector('[name="line_date"]');
        setDefaultDate(bankLineDateInput);

        bankLineForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var submit = bankLineForm.querySelector('[data-finance-bank-line-submit="1"]');
            var original = submit ? submit.textContent : 'Add Bank Line';
            var formData = new FormData(bankLineForm);

            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Saving...';
            }

            apiRequest('POST', bankLineCreateAction, {
                line_date: String(formData.get('line_date') || ''),
                description: String(formData.get('description') || ''),
                amount_signed: String(formData.get('amount_signed') || ''),
                nonce: bankLineNonce,
                metis_action_nonce: bankLineNonce
            }, bankLineNonce).then(function () {
                toast('success', 'Bank line added.');
                bankLineForm.reset();
                setDefaultDate(bankLineDateInput);
                return refreshStripePanels();
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to add bank line.');
            }).finally(function () {
                if (submit) {
                    submit.disabled = false;
                    submit.textContent = original;
                }
            });
        });
    }

    root.addEventListener('click', function (event) {
        var openWorkflowBtn = event.target.closest('[data-recon-open-workflow="1"]');
        if (openWorkflowBtn) {
            setReconSpaView('workflow');
            activateReconStep('setup');
            return;
        }

        var openStepsBtn = event.target.closest('[data-recon-open-steps]');
        if (openStepsBtn) {
            var openMonthId = Number(openStepsBtn.getAttribute('data-recon-open-steps') || 0);
            if (!openMonthId) return;
            state.reconSelectedMonthId = openMonthId;
            if (currentSection === 'reconciliation') {
                refreshReconciliationWorkflow(openMonthId).then(function () {
                    setReconSpaView('workflow');
                    activateReconStep('setup');
                }).catch(function (error) {
                    toast('error', error && error.message ? error.message : 'Failed to load reconciliation workflow.');
                });
            } else {
                try {
                    window.localStorage.setItem('metis_finance_recon_month_id', String(openMonthId));
                } catch (_e) {}
                navigate(reconStepsUrl);
            }
            return;
        }

        var backToListBtn = event.target.closest('[data-recon-back-list="1"]');
        if (backToListBtn) {
            setReconSpaView('list');
            return;
        }

        var deleteMonthBtn = event.target.closest('[data-recon-delete-month]');
        if (deleteMonthBtn) {
            var deleteMonthId = Number(deleteMonthBtn.getAttribute('data-recon-delete-month') || 0);
            if (!deleteMonthId) return;
            confirmAction('Delete this unfinished reconciliation month?', {
                title: 'Delete Reconciliation Month',
                confirmLabel: 'Delete',
                tone: 'danger'
            }).then(function (confirmed) {
                if (!confirmed) return;
                var deleteOriginal = deleteMonthBtn.textContent;
                deleteMonthBtn.disabled = true;
                deleteMonthBtn.textContent = 'Deleting...';
                apiRequest('POST', reconDeleteAction, {
                    month_id: deleteMonthId,
                    nonce: reconDeleteNonce,
                    metis_action_nonce: reconDeleteNonce
                }, reconDeleteNonce).then(function (data) {
                    renderManualReconciliation(data.reconciliation || null);
                    applySummary(data.summary || {});
                    toast('success', 'Reconciliation month deleted.');
                }).catch(function (error) {
                    toast('error', error && error.message ? error.message : 'Failed to delete reconciliation month.');
                }).finally(function () {
                    deleteMonthBtn.disabled = false;
                    deleteMonthBtn.textContent = deleteOriginal;
                });
            });
            return;
        }

        var monthSelectBtn = event.target.closest('[data-recon-month-select]');
        if (monthSelectBtn) {
            var monthId = Number(monthSelectBtn.getAttribute('data-recon-month-select') || 0);
            if (!monthId) return;
            state.reconSelectedMonthId = monthId;
            refreshReconciliationWorkflow(monthId).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to load reconciliation month.');
            });
            return;
        }

        var toggleBox = event.target.closest('[data-recon-item-toggle="1"]');
        if (toggleBox) {
            var activeMonth = state.reconciliation && state.reconciliation.current_month ? Number(state.reconciliation.current_month.id || 0) : 0;
            var itemId = Number(toggleBox.getAttribute('data-recon-item-id') || 0);
            if (!activeMonth || !itemId) return;
            var checked = toggleBox.checked ? 1 : 0;
            apiRequest('POST', reconItemToggleAction, {
                month_id: activeMonth,
                item_id: itemId,
                is_cleared: checked,
                nonce: reconItemToggleNonce,
                metis_action_nonce: reconItemToggleNonce
            }, reconItemToggleNonce).then(function (data) {
                renderManualReconciliation(data.reconciliation || null);
                applySummary(data.summary || {});
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to update reconciliation item.');
                toggleBox.checked = !toggleBox.checked;
            });
            return;
        }

        var finalizeBtn = event.target.closest('[data-finance-recon-finalize="1"]');
        if (finalizeBtn) {
            var finalizeMonthId = state.reconciliation && state.reconciliation.current_month ? Number(state.reconciliation.current_month.id || 0) : 0;
            if (!finalizeMonthId) {
                toast('error', 'Start a reconciliation month first.');
                return;
            }
            var finalizeOriginal = finalizeBtn.textContent;
            finalizeBtn.disabled = true;
            finalizeBtn.textContent = 'Finalizing...';
            apiRequest('POST', reconFinalizeAction, {
                month_id: finalizeMonthId,
                nonce: reconFinalizeNonce,
                metis_action_nonce: reconFinalizeNonce
            }, reconFinalizeNonce).then(function (data) {
                renderManualReconciliation(data.reconciliation || null);
                applySummary(data.summary || {});
                toast('success', 'Reconciliation month finalized.');
                return refreshEntries();
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to finalize reconciliation month.');
            }).finally(function () {
                finalizeBtn.disabled = false;
                finalizeBtn.textContent = finalizeOriginal;
            });
            return;
        }

        var reopenBtn = event.target.closest('[data-finance-recon-reopen="1"]');
        if (reopenBtn) {
            var reopenMonthId = Number(state.reconSelectedMonthId || 0);
            if (!reopenMonthId) {
                toast('error', 'Select a finalized month first.');
                return;
            }
            var reasonInput = root.querySelector('[data-finance-recon-reopen-reason="1"]');
            var reason = reasonInput ? String(reasonInput.value || '').trim() : '';
            if (!reason) {
                toast('error', 'Reason is required to reopen.');
                return;
            }
            var reopenOriginal = reopenBtn.textContent;
            reopenBtn.disabled = true;
            reopenBtn.textContent = 'Reopening...';
            apiRequest('POST', reconReopenAction, {
                month_id: reopenMonthId,
                reason: reason,
                nonce: reconReopenNonce,
                metis_action_nonce: reconReopenNonce
            }, reconReopenNonce).then(function (data) {
                renderManualReconciliation(data.reconciliation || null);
                applySummary(data.summary || {});
                toast('success', 'Reconciliation month reopened.');
                if (reasonInput) reasonInput.value = '';
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to reopen reconciliation month.');
            }).finally(function () {
                reopenBtn.disabled = false;
                reopenBtn.textContent = reopenOriginal;
            });
            return;
        }

        var reviewBtn = event.target.closest('[data-recon-review-action]');
        if (reviewBtn) {
            var reviewAction = String(reviewBtn.getAttribute('data-recon-review-action') || '');
            var reviewId = Number(reviewBtn.getAttribute('data-recon-review-id') || 0);
            if (!reviewId || !reviewAction) return;

            var reviewOriginal = reviewBtn.textContent;
            reviewBtn.disabled = true;
            reviewBtn.textContent = 'Saving...';

            apiRequest('POST', reconReviewAction, {
                review_queue_id: reviewId,
                decision: reviewAction,
                decision_notes: '',
                nonce: reconReviewNonce,
                metis_action_nonce: reconReviewNonce
            }, reconReviewNonce).then(function () {
                toast('success', 'Review decision saved.');
                return refreshReconciliationWorkflow();
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to save review decision.');
            }).finally(function () {
                reviewBtn.disabled = false;
                reviewBtn.textContent = reviewOriginal;
            });
            return;
        }

        var lineMatchBtn = event.target.closest('[data-recon-line-match="1"]');
        if (lineMatchBtn) {
            var bankLineId = Number(lineMatchBtn.getAttribute('data-bank-line-id') || 0);
            var matchType = String(lineMatchBtn.getAttribute('data-match-type') || '');
            var matchId = Number(lineMatchBtn.getAttribute('data-match-id') || 0);
            if (!bankLineId || !matchType || !matchId) return;

            var lineOriginal = lineMatchBtn.textContent;
            lineMatchBtn.disabled = true;
            lineMatchBtn.textContent = 'Matching...';

            apiRequest('POST', reconMatchLineAction, {
                bank_line_id: bankLineId,
                match_type: matchType,
                match_id: matchId,
                run_id: 0,
                nonce: reconReviewNonce,
                metis_action_nonce: reconReviewNonce
            }, reconReviewNonce).then(function (data) {
                renderReconciliationLines(data.statement_lines || [], data.reconciliation_suggestions || []);
                renderRuns(data.reconciliation_runs || []);
                applySummary(data.summary || {});
                toast('success', 'Statement line reconciled.');
                return refreshEntries();
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to reconcile statement line.');
            }).finally(function () {
                lineMatchBtn.disabled = false;
                lineMatchBtn.textContent = lineOriginal;
            });
            return;
        }

        var budgetSaveBtn = event.target.closest('[data-budget-lines-save]');
        if (budgetSaveBtn) {
            var selectedVersionId = state.budget && state.budget.selected_version_id
                ? Number(state.budget.selected_version_id)
                : 0;
            if (selectedVersionId < 1) {
                toast('error', 'Select a budget version first.');
                return;
            }

            var lineInputs = root.querySelectorAll('[data-budget-line-account]');
            var lines = [];
            lineInputs.forEach(function (input) {
                var accountCode = String(input.getAttribute('data-budget-line-account') || '').trim();
                if (!accountCode) return;
                lines.push({
                    account_code: accountCode,
                    planned_amount: String(input.value || '0')
                });
            });

            var budgetOriginal = budgetSaveBtn.textContent;
            budgetSaveBtn.disabled = true;
            budgetSaveBtn.textContent = 'Saving...';

            apiRequest('POST', budgetLinesSaveAction, {
                budget_version_id: selectedVersionId,
                lines: lines,
                nonce: budgetLinesNonce,
                metis_action_nonce: budgetLinesNonce
            }, budgetLinesNonce).then(function (data) {
                renderBudget(data.budget || null);
                applySummary(data.summary || {});
                toast('success', 'Budget lines saved.');
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to save budget lines.');
            }).finally(function () {
                budgetSaveBtn.disabled = false;
                budgetSaveBtn.textContent = budgetOriginal;
            });
            return;
        }

        var sendBtn = event.target.closest('[data-invoice-send]');
        if (sendBtn) {
            var sendInvoiceId = Number(sendBtn.getAttribute('data-invoice-send') || 0);
            if (!sendInvoiceId) return;

            var sendOriginal = sendBtn.textContent;
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';

            apiRequest('POST', invoicesSendAction, {
                invoice_id: sendInvoiceId,
                nonce: invoiceSendNonce,
                metis_action_nonce: invoiceSendNonce
            }, invoiceSendNonce).then(function (data) {
                renderInvoices(data.invoices || null);
                toast('success', 'Invoice sent.');
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to send invoice.');
            }).finally(function () {
                sendBtn.disabled = false;
                sendBtn.textContent = sendOriginal;
            });
            return;
        }

        var paidBtn = event.target.closest('[data-invoice-paid]');
        if (paidBtn) {
            var paidInvoiceId = Number(paidBtn.getAttribute('data-invoice-paid') || 0);
            if (!paidInvoiceId) return;

            var paidOriginal = paidBtn.textContent;
            paidBtn.disabled = true;
            paidBtn.textContent = 'Saving...';

            apiRequest('POST', invoicesPaidAction, {
                invoice_id: paidInvoiceId,
                paid_date: new Date().toISOString().slice(0, 10),
                stripe_payment_intent_id: '',
                nonce: invoicePaidNonce,
                metis_action_nonce: invoicePaidNonce
            }, invoicePaidNonce).then(function (data) {
                renderInvoices(data.invoices || null);
                toast('success', 'Invoice marked paid.');
            }).catch(function (error) {
                toast('error', error && error.message ? error.message : 'Failed to mark invoice paid.');
            }).finally(function () {
                paidBtn.disabled = false;
                paidBtn.textContent = paidOriginal;
            });
            return;
        }

        var btn = event.target.closest('[data-match-payout-btn]');
        if (!btn) return;

        var payoutId = Number(btn.getAttribute('data-match-payout-btn') || 0);
        if (!payoutId) return;

        var select = root.querySelector('[data-match-bank-line-select="' + payoutId + '"]');
        var bankLineId = select ? Number(select.value || 0) : 0;
        if (!bankLineId) {
            toast('error', 'Select a bank line to match.');
            return;
        }

        var original = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Matching...';

        apiRequest('POST', stripeMatchCreateAction, {
            payout_record_id: payoutId,
            bank_line_id: bankLineId,
            nonce: stripeMatchNonce,
            metis_action_nonce: stripeMatchNonce
        }, stripeMatchNonce).then(function () {
            toast('success', 'Payout matched to bank line.');
            return Promise.all([refreshStripePanels(), refreshEntries()]);
        }).catch(function (error) {
            toast('error', error && error.message ? error.message : 'Failed to match payout.');
        }).finally(function () {
            btn.disabled = false;
            btn.textContent = original;
        });
    });

    var startupMonthId = 0;
    try {
        startupMonthId = Number(window.localStorage.getItem('metis_finance_recon_month_id') || 0);
    } catch (_e) {
        startupMonthId = 0;
    }
    if (startupMonthId > 0) {
        state.reconSelectedMonthId = startupMonthId;
    }
    if (currentSection === 'reconciliation') {
        setReconSpaView(startupMonthId > 0 ? 'workflow' : 'list');
    }

    Promise.all([
        apiRequest('GET', bootstrapAction, null, financeNonce),
        reconWorkflowAction ? refreshReconciliationWorkflow(startupMonthId > 0 ? startupMonthId : 0) : Promise.resolve(null),
        stripeOverviewAction ? refreshStripePanels() : Promise.resolve(null)
    ]).then(function (results) {
        applyBootstrap(results[0] || {});
        if (startupMonthId > 0) {
            try {
                window.localStorage.removeItem('metis_finance_recon_month_id');
            } catch (_e) {}
        }
    }).catch(function (error) {
        toast('error', error && error.message ? error.message : 'Failed to load finance workspace.');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    initMetisFinanceApp({ root: document, reason: 'dom-ready', url: window.location.href });
});

if (window.Metis && Metis.page && typeof Metis.page.register === 'function') {
    Metis.page.register('finance', initMetisFinanceApp);
}
