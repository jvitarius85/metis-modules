<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$deposits = metis_get_deposits();
$base_url = metis_donations_base_url();
?>

<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Deposits' ) ); ?></h1>
<p class="metis-subtitle">Review bank deposit activity across digital and manual batches.</p>

<div class="metis-list-layout">

<!-- Sidebar -->
<aside class="metis-list-sidebar">
    <div class="metis-list-sidebar-actions">
        <button type="button" id="metis-sync-deposits" class="metis-btn metis-btn-xs">Sync Stripe Deposits</button>
        <button type="button" id="metis-import-transactions" class="metis-btn metis-btn-xs metis-btn-secondary">Import Stripe Transactions</button>
        <button type="button" id="metis-backfill-deposits" class="metis-btn metis-btn-xs metis-btn-ghost">Backfill Totals</button>
        <button type="button" id="metis-link-payouts" class="metis-btn metis-btn-xs metis-btn-ghost">Link Payouts</button>
        <button type="button" id="metis-verify-links" class="metis-btn metis-btn-xs metis-btn-ghost">Verify Links</button>
    </div>
    <div class="metis-list-sidebar-section metis-sync-status-section">
        <span id="metis-sync-status" class="metis-muted metis-sync-status-text"></span>
    </div>
</aside>

<!-- Main content -->
<div class="metis-list-content">

<table class="metis-premium-table metis-deposits-table">
    <thead>
        <tr class="metis-premium-row metis-premium-header">
            <th class="metis-premium-cell metis-sortable metis-sort-active metis-sort-desc" scope="col">Date</th>
            <th class="metis-premium-cell" scope="col">Deposit ID</th>
            <th class="metis-premium-cell" scope="col">Provider</th>
            <th class="metis-premium-cell" scope="col">Source</th>
            <th class="metis-premium-cell metis-col-numeric" scope="col">Batches</th>
            <th class="metis-premium-cell metis-col-numeric" scope="col">Total</th>
            <th class="metis-premium-cell" scope="col">Status</th>
        </tr>
    </thead>
    <tbody>

    <?php if ( ! empty( $deposits ) ) : ?>
        <?php foreach ( $deposits as $d ) :
            $date       = $d->deposit_date ? date( 'm/d/y', strtotime( $d->deposit_date ) ) : '—';
            $total      = '$' . number_format( (float) $d->total_amount, 2 );
            $detail_url = $base_url . '/deposit/?id=' . urlencode( $d->provider_ref );
        ?>
            <tr class="metis-premium-row metis-deposit-row metis-clickable-row"
                 data-href="<?php echo metis_escape_url( $detail_url ); ?>">
                <td class="metis-premium-cell"><?php echo metis_escape_html( $date ); ?></td>
                <td class="metis-premium-cell metis-link"><?php echo metis_escape_html( $d->provider_ref ); ?></td>
                <td class="metis-premium-cell"><?php echo metis_deposit_source_badge( $d ); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( $d->source ?? '' ) ); ?></td>
                <td class="metis-premium-cell metis-col-numeric"><?php echo (int) $d->batch_count; ?></td>
                <td class="metis-premium-cell metis-col-numeric"><?php echo metis_escape_html( $total ); ?></td>
                <td class="metis-premium-cell"><?php echo metis_status_badge( $d->status ); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else : ?>
        <tr class="metis-premium-row">
            <td class="metis-premium-cell metis-muted" colspan="7">No deposits recorded yet.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table><!-- /metis-deposits-table -->
</div><!-- /metis-list-content -->
</div><!-- /metis-list-layout -->

<script>
document.addEventListener('click', function (e) {
    const row = e.target.closest('.metis-clickable-row');
    if (row && row.dataset.href) {
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            Metis.navigation.go(row.dataset.href);
            return;
        }
        window.location.assign(row.dataset.href);
    }
});

// ── Shared AJAX helper ───────────────────────────────────────────────────────
function metisDepositsRequest(action, btn, statusEl, buildMessage) {
    btn.disabled         = true;
    statusEl.textContent = 'Working\u2026';

    fetch(metisAjax.ajax_url, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action, metis_action_nonce: metisAjax.nonce })
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) {
            statusEl.textContent = 'Failed: ' + (res.data?.message || 'unknown error');
            console.error(res);
        } else {
            statusEl.textContent = buildMessage(res.data);
            if (res.data.errors?.length) console.warn('Errors:', res.data.errors);
        }
    })
    .catch(() => { statusEl.textContent = 'Network error.'; })
    .finally(() => { btn.disabled = false; });
}

// ── Sync Stripe Deposits ─────────────────────────────────────────────────────
document.getElementById('metis-sync-deposits')?.addEventListener('click', function () {
    metisDepositsRequest(
        'metis_sync_deposits', this,
        document.getElementById('metis-sync-status'),
        d => 'Sync done \u2014 ' + d.inserted + ' created, ' + d.updated + ' linked'
             + (d.errors?.length ? ', ' + d.errors.length + ' error(s)' : '') + '.'
    );
});

// ── Backfill Totals ──────────────────────────────────────────────────────────
document.getElementById('metis-backfill-deposits')?.addEventListener('click', function () {
    const btn    = this;
    const status = document.getElementById('metis-sync-status');
    btn.disabled         = true;
    status.textContent   = 'Working\u2026';

    fetch(metisAjax.ajax_url, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action: 'metis_backfill_deposit_totals', metis_action_nonce: metisAjax.nonce })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled       = false;
        status.textContent = '';
        if (!res.success) {
            status.textContent = 'Failed: ' + (res.data?.message || 'unknown error');
            return;
        }
        metisShowBackfillResults(res.data);
    })
    .catch(() => { btn.disabled = false; status.textContent = 'Network error.'; });
});

// ── Backfill Results Modal ───────────────────────────────────────────────────
function metisShowBackfillResults(data) {
    document.getElementById('metis-backfill-modal')?.remove();

    const DOLLAR = '\u0024'; // avoid PHP $ interpolation
    const fmt    = v => (v === null || v === undefined) ? '\u2014' : DOLLAR + Number(v).toFixed(2);
    const rows   = data.rows || [];

    let tableRows = '';

    if (rows.length === 0) {
        const msg = data.message || 'No deposits needed backfilling.';
        tableRows = '<tr><td colspan="7" class="metis-result-empty-cell">' + msg + '</td></tr>';
    } else {
        rows.forEach(function(r) {
            const labels = { filled_stripe: 'Updated \u2022 Stripe', filled_local: 'Updated \u2022 Local', skipped: 'Skipped' };
            const label  = labels[r.status] || r.status;
            const sClass = r.status === 'skipped' ? 'is-warning' : 'is-success';

            const bGross = r.before ? fmt(r.before.gross) : '\u2014';
            const bFees  = r.before ? fmt(r.before.fees)  : '\u2014';
            const aGross = r.after  ? fmt(r.after.gross)  : '\u2014';
            const aFees  = r.after  ? fmt(r.after.fees)   : '\u2014';

            const changed = r.before && r.after &&
                            (r.before.gross !== r.after.gross || r.before.fees !== r.after.fees);

            const aGrossClass = changed ? ' metis-result-value-updated' : '';
            const aFeesClass  = changed ? ' metis-result-value-negative' : '';
            const linkedTxt   = r.linked > 0 ? r.linked + ' txn' + (r.linked > 1 ? 's' : '') : '\u2014';

            tableRows +=
                '<tr class="metis-result-row">'
                + '<td class="metis-result-cell-mono">' + r.code + '</td>'
                + '<td><span class="metis-result-badge ' + sClass + '">' + label + '</span></td>'
                + '<td class="metis-col-numeric metis-result-cell-muted">' + bGross + '</td>'
                + '<td class="metis-col-numeric metis-result-cell-muted">' + bFees + '</td>'
                + '<td class="metis-col-numeric' + aGrossClass + '">' + aGross + '</td>'
                + '<td class="metis-col-numeric' + aFeesClass  + '">' + aFees  + '</td>'
                + '<td class="metis-col-numeric">' + linkedTxt + '</td>'
                + '</tr>';

            if (r.reason) {
                tableRows += '<tr class="metis-result-note-row"><td colspan="7">'
                           + '\u26a0 ' + r.reason + '</td></tr>';
            }
        });
    }

    const summary = rows.length === 0
        ? (data.message || 'Nothing to backfill.')
        : data.filled + ' updated from Stripe, '
          + data.local + ' from local data, '
          + data.skipped + ' skipped, '
          + data.linked + ' transaction(s) linked.';

    const modal = document.createElement('div');
    modal.id = 'metis-backfill-modal';
    modal.className = 'metis-modal-overlay';

    modal.innerHTML =
        '<div class="metis-result-modal metis-result-modal-md">'

        // Header
        + '<div class="metis-modal-header">'
        + '<h3>Backfill Results</h3>'
        + '<button id="metis-backfill-close" class="metis-modal-close" type="button">&times;</button>'
        + '</div>'

        // Summary bar
        + '<div class="metis-result-summary">' + summary + '</div>'

        // Table
        + '<div class="metis-result-table-wrap">'
        + '<table class="metis-result-table">'
        + '<thead><tr>'
        + '<th>Deposit</th>'
        + '<th>Result</th>'
        + '<th class="metis-col-numeric">Before Gross</th>'
        + '<th class="metis-col-numeric">Before Fees</th>'
        + '<th class="metis-col-numeric">After Gross</th>'
        + '<th class="metis-col-numeric">After Fees</th>'
        + '<th class="metis-col-numeric">Linked</th>'
        + '</tr></thead>'
        + '<tbody>' + tableRows + '</tbody>'
        + '</table></div>'

        // Footer
        + '<div class="metis-modal-footer">'
        + '<button id="metis-backfill-dismiss" class="metis-btn metis-btn-secondary">Dismiss</button>'
        + '</div>'

        + '</div>';

    document.body.appendChild(modal);
    document.getElementById('metis-backfill-close').onclick   = function() { modal.remove(); };
    document.getElementById('metis-backfill-dismiss').onclick = function() { modal.remove(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

// ── Link Payouts ────────────────────────────────────────────────────────────
document.getElementById('metis-link-payouts')?.addEventListener('click', function () {
    const btn    = this;
    const status = document.getElementById('metis-sync-status');
    btn.disabled       = true;
    status.textContent = 'Working… (this may take a moment)';

    fetch(metisAjax.ajax_url, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action: 'metis_link_stripe_payouts', metis_action_nonce: metisAjax.nonce })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled       = false;
        status.textContent = '';
        if (!res.success) {
            status.textContent = 'Failed: ' + (res.data?.message || 'unknown error');
            return;
        }
        metisShowLinkPayoutsResults(res.data);
    })
    .catch(() => { btn.disabled = false; status.textContent = 'Network error.'; });
});

function metisShowLinkPayoutsResults(data) {
    document.getElementById('metis-link-payouts-modal')?.remove();

    const trunc = function(s, n) {
        if (!s) return '—';
        s = String(s);
        return s.length > n ? s.substring(0, n) + '…' : s;
    };

    const rows = data.rows || [];
    let tableRows = '';

    if (rows.length === 0) {
        tableRows = '<tr><td colspan="7" class="metis-result-empty-cell">' + (data.message || 'Nothing to link.') + '</td></tr>';
    } else {
        rows.forEach(function(r) {
            const statusMap = { linked: 'Linked', skipped: 'Skipped', error: 'Error' };
            const label     = statusMap[r.status] || r.status;
            const sClass    = r.status === 'linked'  ? 'is-success'
                            : r.status === 'skipped' ? 'is-warning'
                            : 'is-error';

            tableRows +=
                '<tr class="metis-result-row">' +
                '<td class="metis-result-cell-mono">'   + r.tid + '</td>' +
                '<td class="metis-result-cell-muted">'  + trunc(r.pi, 22) + '</td>' +
                '<td class="metis-result-cell-muted">'  + trunc(r.charge_id, 18) + '</td>' +
                '<td class="metis-result-cell-muted">'  + trunc(r.payout_id, 18) + '</td>' +
                '<td class="metis-result-cell-mono">'   + (r.deposit || '—') + '</td>' +
                '<td>' +
                    '<span class="metis-result-badge ' + sClass + '">' + label + '</span>' +
                '</td>' +
                '<td class="metis-result-note-row-cell">' + (r.note || '') + '</td>' +
                '</tr>';
        });
    }

    const newBadge = data.deposits_made > 0
        ? ' <span class="metis-result-badge is-info">' + data.deposits_made + ' new deposit' + (data.deposits_made > 1 ? 's' : '') + ' created</span>'
        : '';

    const summary = data.message
        ? data.message
        : data.linked + ' transaction' + (data.linked !== 1 ? 's' : '') + ' linked' + newBadge +
          (data.skipped ? ', ' + data.skipped + ' skipped' : '') +
          (data.errors?.length ? ', ' + data.errors.length + ' error(s)' : '') + '.';

    const modal = document.createElement('div');
    modal.id = 'metis-link-payouts-modal';
    modal.className = 'metis-modal-overlay';

    modal.innerHTML =
        '<div class="metis-result-modal metis-result-modal-lg">' +

        // Header
        '<div class="metis-modal-header">' +
        '<h3>Link Payouts — Results</h3>' +
        '<button id="metis-lp-close" class="metis-modal-close" type="button">&times;</button>' +
        '</div>' +

        // Summary
        '<div class="metis-result-summary">' + summary + '</div>' +

        // Table
        '<div class="metis-result-table-wrap">' +
        '<table class="metis-result-table">' +
        '<thead><tr>' +
        '<th>TX ID</th>' +
        '<th>Payment Intent</th>' +
        '<th>Charge</th>' +
        '<th>Payout</th>' +
        '<th>Deposit</th>' +
        '<th>Status</th>' +
        '<th>Note</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
        '</table></div>' +

        // Footer
        '<div class="metis-modal-footer">' +
        '<button id="metis-lp-dismiss" class="metis-btn metis-btn-secondary">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('metis-lp-close').onclick   = function() { modal.remove(); };
    document.getElementById('metis-lp-dismiss').onclick = function() { modal.remove(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

// ── Import Stripe Transactions ──────────────────────────────────────────
document.getElementById('metis-import-transactions')?.addEventListener('click', function () {
    const btn    = this;
    const status = document.getElementById('metis-sync-status');
    btn.disabled = true;

    // Accumulated totals across pages
    let totals = { imported: 0, skipped: 0, contacts_made: 0, errors: [], rows: [] };

    function runPage(cursor) {
        const pageNum = Math.floor(totals.rows.length / 100) + 1;
        status.textContent = 'Importing… page ' + pageNum + ' (' + totals.imported + ' imported so far)';

        const body = new URLSearchParams({
            action      : 'metis_import_stripe_transactions',
            metis_action_nonce : metisAjax.nonce,
        });
        if (cursor) body.set('cursor', cursor);

        fetch(metisAjax.ajax_url, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
            body    : body,
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                btn.disabled       = false;
                status.textContent = 'Failed: ' + (res.data?.message || 'unknown error');
                return;
            }
            const d = res.data;
            totals.imported      += d.imported      || 0;
            totals.skipped       += d.skipped       || 0;
            totals.contacts_made += d.contacts_made || 0;
            totals.errors         = totals.errors.concat(d.errors || []);
            totals.rows           = totals.rows.concat(d.rows || []);

            if (d.has_more && d.next_cursor) {
                runPage(d.next_cursor);
            } else {
                btn.disabled       = false;
                status.textContent = '';
                metisShowImportResults(totals);
            }
        })
        .catch(() => {
            btn.disabled       = false;
            status.textContent = 'Network error.';
        });
    }

    runPage('');
});

function metisShowImportResults(data) {
    document.getElementById('metis-import-modal')?.remove();

    const rows = data.rows || [];
    let tableRows = '';

    if (rows.length === 0) {
        tableRows = '<tr><td colspan="7" class="metis-result-empty-cell">No new transactions to import.</td></tr>';
    } else {
        rows.forEach(function(r) {
            const isError    = r.status === 'error';
            const newContact = r.new_contact;
            const rowClass   = isError ? ' metis-result-row-error' : '';
            const statusEl   = isError
                ? '<span class="metis-result-badge is-error">Error</span>'
                : '<span class="metis-result-badge is-success">Imported</span>';
            const contactEl  = newContact
                ? (r.did || '—') + ' <span class="metis-result-badge is-info metis-result-badge-mini">new</span>'
                : (r.did || '—');

            tableRows +=
                '<tr class="metis-result-row' + rowClass + '">' +
                '<td class="metis-result-cell-mono">' + (r.tid || '—') + '</td>' +
                '<td>' + (r.email || '—') + '</td>' +
                '<td class="metis-result-cell-muted">' + contactEl + '</td>' +
                '<td class="metis-col-numeric">' + (r.amount || '—') + '</td>' +
                '<td class="metis-col-numeric">' + (r.net || '—') + '</td>' +
                '<td class="metis-result-cell-muted">' + (r.date || '—') + '</td>' +
                '<td>' + statusEl + '</td>' +
                '</tr>';
        });
    }

    const pill = function(n, label, className) {
        if (!n) return '';
        return '<span class="metis-result-badge ' + className + '">' + n + ' ' + label + '</span>';
    };

    const summary =
        pill(data.imported,      'imported',         'is-success') +
        pill(data.skipped,       'already existed',  '') +
        pill(data.contacts_made, 'contacts created', 'is-info') +
        (data.errors?.length ? pill(data.errors.length, 'error(s)', 'is-error') : '') ||
        'Nothing to import.';

    const modal = document.createElement('div');
    modal.id = 'metis-import-modal';
    modal.className = 'metis-modal-overlay';

    modal.innerHTML =
        '<div class="metis-result-modal metis-result-modal-xl">' +

        '<div class="metis-modal-header">' +
        '<h3>Import Stripe Transactions — Results</h3>' +
        '<button id="metis-imp-close" class="metis-modal-close" type="button">&times;</button>' +
        '</div>' +

        '<div class="metis-result-summary metis-result-summary-badges">' +
        summary +
        '</div>' +

        '<div class="metis-result-table-wrap">' +
        '<table class="metis-result-table">' +
        '<thead><tr>' +
        '<th>TX ID</th>' +
        '<th>Email</th>' +
        '<th>Donor ID</th>' +
        '<th class="metis-col-numeric">Gross</th>' +
        '<th class="metis-col-numeric">Net</th>' +
        '<th>Date</th>' +
        '<th>Status</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
        '</table></div>' +

        '<div class="metis-modal-footer">' +
        '<button id="metis-imp-dismiss" class="metis-btn metis-btn-secondary">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('metis-imp-close').onclick   = function() { modal.remove(); };
    document.getElementById('metis-imp-dismiss').onclick = function() { modal.remove(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

// ── Verify Links ──────────────────────────────────────────────────────────
document.getElementById('metis-verify-links')?.addEventListener('click', function () {
    const btn    = this;
    const status = document.getElementById('metis-sync-status');
    btn.disabled       = true;
    status.textContent = 'Verifying… (fetching from Stripe)';

    fetch(metisAjax.ajax_url, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action: 'metis_verify_deposit_links', metis_action_nonce: metisAjax.nonce })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled       = false;
        status.textContent = '';
        if (!res.success) {
            status.textContent = 'Failed: ' + (res.data?.message || 'unknown error');
            return;
        }
        metisShowVerifyLinksResults(res.data);
    })
    .catch(() => { btn.disabled = false; status.textContent = 'Network error.'; });
});

function metisShowVerifyLinksResults(data) {
    document.getElementById('metis-verify-links-modal')?.remove();

    const trunc = function(s, n) {
        if (!s) return '—';
        s = String(s);
        return s.length > n ? s.substring(0, n) + '\u2026' : s;
    };

    const rows = data.rows || [];
    let tableRows = '';

    if (rows.length === 0) {
        tableRows = '<tr><td colspan="6" class="metis-result-empty-cell">' + (data.message || 'No transactions found to verify.') + '</td></tr>';
    } else {
        rows.forEach(function(r) {
            const styleMap = {
                verified  : { label: 'Verified',  className: 'is-success' },
                corrected : { label: 'Corrected', className: 'is-info' },
                linked    : { label: 'Linked',    className: 'is-success' },
                unmatched : { label: 'Unmatched', className: 'is-warning' },
                error     : { label: 'Error',     className: 'is-error' },
            };
            const s = styleMap[r.status] || { label: r.status, className: '' };

            tableRows +=
                '<tr class="metis-result-row">' +
                '<td class="metis-result-cell-mono">'  + (r.tid || '—') + '</td>' +
                '<td class="metis-result-cell-muted">' + trunc(r.btxn, 24) + '</td>' +
                '<td class="metis-result-cell-muted">' + trunc(r.charge_id, 22) + '</td>' +
                '<td class="metis-result-cell-mono">'  + (r.deposit || '—') + '</td>' +
                '<td class="metis-result-cell-muted">' + (r.was && r.was !== r.deposit ? '<s>' + r.was + '</s>' : '') + '</td>' +
                '<td>' +
                    '<span class="metis-result-badge ' + s.className + '">' + s.label + '</span>' +
                '</td>' +
                '</tr>';
        });
    }

    const pill = function(n, label, className) {
        if (!n) return '';
        return '<span class="metis-result-badge ' + className + '">' + n + ' ' + label + '</span>';
    };

    const summary =
        pill(data.verified,  'verified',  'is-success') +
        pill(data.corrected, 'corrected', 'is-info') +
        pill(data.linked,    'linked',    'is-success') +
        pill(data.unmatched, 'unmatched', 'is-warning') +
        (data.errors?.length ? pill(data.errors.length, 'error(s)', 'is-error') : '') ||
        'No transactions found.';

    const modal = document.createElement('div');
    modal.id = 'metis-verify-links-modal';
    modal.className = 'metis-modal-overlay';

    modal.innerHTML =
        '<div class="metis-result-modal metis-result-modal-lg">' +

        '<div class="metis-modal-header">' +
        '<h3>Verify Links — Results</h3>' +
        '<button id="metis-vl-close" class="metis-modal-close" type="button">&times;</button>' +
        '</div>' +

        '<div class="metis-result-summary metis-result-summary-badges">' +
        '<span class="metis-result-summary-label">Results:</span>' + summary +
        (data.unmatched ? '<span class="metis-result-summary-note">Unmatched = Stripe charges with no local transaction record (other donors not yet in MW Tools)</span>' : '') +
        '</div>' +

        '<div class="metis-result-table-wrap">' +
        '<table class="metis-result-table">' +
        '<thead><tr>' +
        '<th>TX ID</th>' +
        '<th>Balance Txn</th>' +
        '<th>Charge ID</th>' +
        '<th>Deposit</th>' +
        '<th>Was</th>' +
        '<th>Status</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
        '</table></div>' +

        '<div class="metis-modal-footer">' +
        '<button id="metis-vl-dismiss" class="metis-btn metis-btn-secondary">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('metis-vl-close').onclick   = function() { modal.remove(); };
    document.getElementById('metis-vl-dismiss').onclick = function() { modal.remove(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

</script>
