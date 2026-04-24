<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$deposits = metis_get_deposits();
$base_url = metis_donations_base_url();
?>

<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Deposits' ) ); ?></h1>
<p class="mw-subtitle">Review bank deposit activity across digital and manual batches.</p>

<div class="mw-list-layout">

<!-- Sidebar -->
<aside class="mw-list-sidebar">
    <div class="mw-list-sidebar-actions">
        <button type="button" id="mw-sync-deposits" class="mw-btn mw-btn-xs">Sync Stripe Deposits</button>
        <button type="button" id="mw-import-transactions" class="mw-btn mw-btn-xs mw-btn-secondary">Import Stripe Transactions</button>
        <button type="button" id="mw-backfill-deposits" class="mw-btn mw-btn-xs mw-btn-ghost">Backfill Totals</button>
        <button type="button" id="mw-link-payouts" class="mw-btn mw-btn-xs mw-btn-ghost">Link Payouts</button>
        <button type="button" id="mw-verify-links" class="mw-btn mw-btn-xs mw-btn-ghost">Verify Links</button>
    </div>
    <div class="mw-list-sidebar-section mw-sync-status-section">
        <span id="mw-sync-status" class="mw-muted mw-sync-status-text"></span>
    </div>
</aside>

<!-- Main content -->
<div class="mw-list-content">

<div class="mw-premium-table mw-deposits-table">

    <div class="mw-premium-row mw-premium-header">
        <div class="mw-premium-cell mw-sortable mw-sort-active mw-sort-desc">Date</div>
        <div class="mw-premium-cell">Deposit ID</div>
        <div class="mw-premium-cell">Provider</div>
        <div class="mw-premium-cell">Source</div>
        <div class="mw-premium-cell mw-col-numeric">Batches</div>
        <div class="mw-premium-cell mw-col-numeric">Total</div>
        <div class="mw-premium-cell">Status</div>
    </div>

    <?php if ( ! empty( $deposits ) ) : ?>
        <?php foreach ( $deposits as $d ) :
            $date       = $d->deposit_date ? date( 'm/d/y', strtotime( $d->deposit_date ) ) : '—';
            $total      = '$' . number_format( (float) $d->total_amount, 2 );
            $detail_url = $base_url . '/deposit/?id=' . urlencode( $d->provider_ref );
        ?>
            <div class="mw-premium-row mw-deposit-row mw-clickable-row"
                 data-href="<?php echo metis_escape_url( $detail_url ); ?>">
                <div class="mw-premium-cell"><?php echo metis_escape_html( $date ); ?></div>
                <div class="mw-premium-cell mw-link"><?php echo metis_escape_html( $d->provider_ref ); ?></div>
                <div class="mw-premium-cell"><?php echo metis_deposit_source_badge( $d ); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html( ucfirst( $d->source ?? '' ) ); ?></div>
                <div class="mw-premium-cell mw-col-numeric"><?php echo (int) $d->batch_count; ?></div>
                <div class="mw-premium-cell mw-col-numeric"><?php echo metis_escape_html( $total ); ?></div>
                <div class="mw-premium-cell"><?php echo metis_status_badge( $d->status ); ?></div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <div class="mw-premium-row">
            <div class="mw-premium-cell mw-muted">No deposits recorded yet.</div>
        </div>
    <?php endif; ?>

</div><!-- /mw-deposits-table -->
</div><!-- /mw-list-content -->
</div><!-- /mw-list-layout -->

<script>
document.addEventListener('click', function (e) {
    const row = e.target.closest('.mw-clickable-row');
    if (row && row.dataset.href) {
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            Metis.navigation.go(row.dataset.href);
            return;
        }
        window.location.assign(row.dataset.href);
    }
});

// ── Shared AJAX helper ───────────────────────────────────────────────────────
function mwDepositsRequest(action, btn, statusEl, buildMessage) {
    btn.disabled         = true;
    statusEl.textContent = 'Working\u2026';

    fetch(metisAjax.ajax_url, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action, _ajax_nonce: metisAjax.nonce })
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
document.getElementById('mw-sync-deposits')?.addEventListener('click', function () {
    mwDepositsRequest(
        'metis_sync_deposits', this,
        document.getElementById('mw-sync-status'),
        d => 'Sync done \u2014 ' + d.inserted + ' created, ' + d.updated + ' linked'
             + (d.errors?.length ? ', ' + d.errors.length + ' error(s)' : '') + '.'
    );
});

// ── Backfill Totals ──────────────────────────────────────────────────────────
document.getElementById('mw-backfill-deposits')?.addEventListener('click', function () {
    const btn    = this;
    const status = document.getElementById('mw-sync-status');
    btn.disabled         = true;
    status.textContent   = 'Working\u2026';

    fetch(metisAjax.ajax_url, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action: 'metis_backfill_deposit_totals', _ajax_nonce: metisAjax.nonce })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled       = false;
        status.textContent = '';
        if (!res.success) {
            status.textContent = 'Failed: ' + (res.data?.message || 'unknown error');
            return;
        }
        mwShowBackfillResults(res.data);
    })
    .catch(() => { btn.disabled = false; status.textContent = 'Network error.'; });
});

// ── Backfill Results Modal ───────────────────────────────────────────────────
function mwShowBackfillResults(data) {
    document.getElementById('mw-backfill-modal')?.remove();

    const DOLLAR = '\u0024'; // avoid PHP $ interpolation
    const fmt    = v => (v === null || v === undefined) ? '\u2014' : DOLLAR + Number(v).toFixed(2);
    const rows   = data.rows || [];

    let tableRows = '';

    if (rows.length === 0) {
        const msg = data.message || 'No deposits needed backfilling.';
        tableRows = '<tr><td colspan="7" class="mw-result-empty-cell">' + msg + '</td></tr>';
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

            const aGrossClass = changed ? ' mw-result-value-updated' : '';
            const aFeesClass  = changed ? ' mw-result-value-negative' : '';
            const linkedTxt   = r.linked > 0 ? r.linked + ' txn' + (r.linked > 1 ? 's' : '') : '\u2014';

            tableRows +=
                '<tr class="mw-result-row">'
                + '<td class="mw-result-cell-mono">' + r.code + '</td>'
                + '<td><span class="mw-result-badge ' + sClass + '">' + label + '</span></td>'
                + '<td class="mw-col-numeric mw-result-cell-muted">' + bGross + '</td>'
                + '<td class="mw-col-numeric mw-result-cell-muted">' + bFees + '</td>'
                + '<td class="mw-col-numeric' + aGrossClass + '">' + aGross + '</td>'
                + '<td class="mw-col-numeric' + aFeesClass  + '">' + aFees  + '</td>'
                + '<td class="mw-col-numeric">' + linkedTxt + '</td>'
                + '</tr>';

            if (r.reason) {
                tableRows += '<tr class="mw-result-note-row"><td colspan="7">'
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
    modal.id = 'mw-backfill-modal';
    modal.className = 'mw-modal-overlay';

    modal.innerHTML =
        '<div class="mw-result-modal mw-result-modal-md">'

        // Header
        + '<div class="mw-modal-header">'
        + '<h3>Backfill Results</h3>'
        + '<button id="mw-backfill-close" class="mw-modal-close" type="button">&times;</button>'
        + '</div>'

        // Summary bar
        + '<div class="mw-result-summary">' + summary + '</div>'

        // Table
        + '<div class="mw-result-table-wrap">'
        + '<table class="mw-result-table">'
        + '<thead><tr>'
        + '<th>Deposit</th>'
        + '<th>Result</th>'
        + '<th class="mw-col-numeric">Before Gross</th>'
        + '<th class="mw-col-numeric">Before Fees</th>'
        + '<th class="mw-col-numeric">After Gross</th>'
        + '<th class="mw-col-numeric">After Fees</th>'
        + '<th class="mw-col-numeric">Linked</th>'
        + '</tr></thead>'
        + '<tbody>' + tableRows + '</tbody>'
        + '</table></div>'

        // Footer
        + '<div class="mw-modal-footer">'
        + '<button id="mw-backfill-dismiss" class="mw-btn mw-btn-secondary">Dismiss</button>'
        + '</div>'

        + '</div>';

    document.body.appendChild(modal);
    document.getElementById('mw-backfill-close').onclick   = function() { modal.remove(); };
    document.getElementById('mw-backfill-dismiss').onclick = function() { modal.remove(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

// ── Link Payouts ────────────────────────────────────────────────────────────
document.getElementById('mw-link-payouts')?.addEventListener('click', function () {
    const btn    = this;
    const status = document.getElementById('mw-sync-status');
    btn.disabled       = true;
    status.textContent = 'Working… (this may take a moment)';

    fetch(metisAjax.ajax_url, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action: 'metis_link_stripe_payouts', _ajax_nonce: metisAjax.nonce })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled       = false;
        status.textContent = '';
        if (!res.success) {
            status.textContent = 'Failed: ' + (res.data?.message || 'unknown error');
            return;
        }
        mwShowLinkPayoutsResults(res.data);
    })
    .catch(() => { btn.disabled = false; status.textContent = 'Network error.'; });
});

function mwShowLinkPayoutsResults(data) {
    document.getElementById('mw-link-payouts-modal')?.remove();

    const trunc = function(s, n) {
        if (!s) return '—';
        s = String(s);
        return s.length > n ? s.substring(0, n) + '…' : s;
    };

    const rows = data.rows || [];
    let tableRows = '';

    if (rows.length === 0) {
        tableRows = '<tr><td colspan="7" class="mw-result-empty-cell">' + (data.message || 'Nothing to link.') + '</td></tr>';
    } else {
        rows.forEach(function(r) {
            const statusMap = { linked: 'Linked', skipped: 'Skipped', error: 'Error' };
            const label     = statusMap[r.status] || r.status;
            const sClass    = r.status === 'linked'  ? 'is-success'
                            : r.status === 'skipped' ? 'is-warning'
                            : 'is-error';

            tableRows +=
                '<tr class="mw-result-row">' +
                '<td class="mw-result-cell-mono">'   + r.tid + '</td>' +
                '<td class="mw-result-cell-muted">'  + trunc(r.pi, 22) + '</td>' +
                '<td class="mw-result-cell-muted">'  + trunc(r.charge_id, 18) + '</td>' +
                '<td class="mw-result-cell-muted">'  + trunc(r.payout_id, 18) + '</td>' +
                '<td class="mw-result-cell-mono">'   + (r.deposit || '—') + '</td>' +
                '<td>' +
                    '<span class="mw-result-badge ' + sClass + '">' + label + '</span>' +
                '</td>' +
                '<td class="mw-result-note-row-cell">' + (r.note || '') + '</td>' +
                '</tr>';
        });
    }

    const newBadge = data.deposits_made > 0
        ? ' <span class="mw-result-badge is-info">' + data.deposits_made + ' new deposit' + (data.deposits_made > 1 ? 's' : '') + ' created</span>'
        : '';

    const summary = data.message
        ? data.message
        : data.linked + ' transaction' + (data.linked !== 1 ? 's' : '') + ' linked' + newBadge +
          (data.skipped ? ', ' + data.skipped + ' skipped' : '') +
          (data.errors?.length ? ', ' + data.errors.length + ' error(s)' : '') + '.';

    const modal = document.createElement('div');
    modal.id = 'mw-link-payouts-modal';
    modal.className = 'mw-modal-overlay';

    modal.innerHTML =
        '<div class="mw-result-modal mw-result-modal-lg">' +

        // Header
        '<div class="mw-modal-header">' +
        '<h3>Link Payouts — Results</h3>' +
        '<button id="mw-lp-close" class="mw-modal-close" type="button">&times;</button>' +
        '</div>' +

        // Summary
        '<div class="mw-result-summary">' + summary + '</div>' +

        // Table
        '<div class="mw-result-table-wrap">' +
        '<table class="mw-result-table">' +
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
        '<div class="mw-modal-footer">' +
        '<button id="mw-lp-dismiss" class="mw-btn mw-btn-secondary">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('mw-lp-close').onclick   = function() { modal.remove(); };
    document.getElementById('mw-lp-dismiss').onclick = function() { modal.remove(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

// ── Import Stripe Transactions ──────────────────────────────────────────
document.getElementById('mw-import-transactions')?.addEventListener('click', function () {
    const btn    = this;
    const status = document.getElementById('mw-sync-status');
    btn.disabled = true;

    // Accumulated totals across pages
    let totals = { imported: 0, skipped: 0, contacts_made: 0, errors: [], rows: [] };

    function runPage(cursor) {
        const pageNum = Math.floor(totals.rows.length / 100) + 1;
        status.textContent = 'Importing… page ' + pageNum + ' (' + totals.imported + ' imported so far)';

        const body = new URLSearchParams({
            action      : 'metis_import_stripe_transactions',
            _ajax_nonce : metisAjax.nonce,
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
                mwShowImportResults(totals);
            }
        })
        .catch(() => {
            btn.disabled       = false;
            status.textContent = 'Network error.';
        });
    }

    runPage('');
});

function mwShowImportResults(data) {
    document.getElementById('mw-import-modal')?.remove();

    const rows = data.rows || [];
    let tableRows = '';

    if (rows.length === 0) {
        tableRows = '<tr><td colspan="7" class="mw-result-empty-cell">No new transactions to import.</td></tr>';
    } else {
        rows.forEach(function(r) {
            const isError    = r.status === 'error';
            const newContact = r.new_contact;
            const rowClass   = isError ? ' mw-result-row-error' : '';
            const statusEl   = isError
                ? '<span class="mw-result-badge is-error">Error</span>'
                : '<span class="mw-result-badge is-success">Imported</span>';
            const contactEl  = newContact
                ? (r.did || '—') + ' <span class="mw-result-badge is-info mw-result-badge-mini">new</span>'
                : (r.did || '—');

            tableRows +=
                '<tr class="mw-result-row' + rowClass + '">' +
                '<td class="mw-result-cell-mono">' + (r.tid || '—') + '</td>' +
                '<td>' + (r.email || '—') + '</td>' +
                '<td class="mw-result-cell-muted">' + contactEl + '</td>' +
                '<td class="mw-col-numeric">' + (r.amount || '—') + '</td>' +
                '<td class="mw-col-numeric">' + (r.net || '—') + '</td>' +
                '<td class="mw-result-cell-muted">' + (r.date || '—') + '</td>' +
                '<td>' + statusEl + '</td>' +
                '</tr>';
        });
    }

    const pill = function(n, label, className) {
        if (!n) return '';
        return '<span class="mw-result-badge ' + className + '">' + n + ' ' + label + '</span>';
    };

    const summary =
        pill(data.imported,      'imported',         'is-success') +
        pill(data.skipped,       'already existed',  '') +
        pill(data.contacts_made, 'contacts created', 'is-info') +
        (data.errors?.length ? pill(data.errors.length, 'error(s)', 'is-error') : '') ||
        'Nothing to import.';

    const modal = document.createElement('div');
    modal.id = 'mw-import-modal';
    modal.className = 'mw-modal-overlay';

    modal.innerHTML =
        '<div class="mw-result-modal mw-result-modal-xl">' +

        '<div class="mw-modal-header">' +
        '<h3>Import Stripe Transactions — Results</h3>' +
        '<button id="mw-imp-close" class="mw-modal-close" type="button">&times;</button>' +
        '</div>' +

        '<div class="mw-result-summary mw-result-summary-badges">' +
        summary +
        '</div>' +

        '<div class="mw-result-table-wrap">' +
        '<table class="mw-result-table">' +
        '<thead><tr>' +
        '<th>TX ID</th>' +
        '<th>Email</th>' +
        '<th>Donor ID</th>' +
        '<th class="mw-col-numeric">Gross</th>' +
        '<th class="mw-col-numeric">Net</th>' +
        '<th>Date</th>' +
        '<th>Status</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
        '</table></div>' +

        '<div class="mw-modal-footer">' +
        '<button id="mw-imp-dismiss" class="mw-btn mw-btn-secondary">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('mw-imp-close').onclick   = function() { modal.remove(); };
    document.getElementById('mw-imp-dismiss').onclick = function() { modal.remove(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

// ── Verify Links ──────────────────────────────────────────────────────────
document.getElementById('mw-verify-links')?.addEventListener('click', function () {
    const btn    = this;
    const status = document.getElementById('mw-sync-status');
    btn.disabled       = true;
    status.textContent = 'Verifying… (fetching from Stripe)';

    fetch(metisAjax.ajax_url, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action: 'metis_verify_deposit_links', _ajax_nonce: metisAjax.nonce })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled       = false;
        status.textContent = '';
        if (!res.success) {
            status.textContent = 'Failed: ' + (res.data?.message || 'unknown error');
            return;
        }
        mwShowVerifyLinksResults(res.data);
    })
    .catch(() => { btn.disabled = false; status.textContent = 'Network error.'; });
});

function mwShowVerifyLinksResults(data) {
    document.getElementById('mw-verify-links-modal')?.remove();

    const trunc = function(s, n) {
        if (!s) return '—';
        s = String(s);
        return s.length > n ? s.substring(0, n) + '\u2026' : s;
    };

    const rows = data.rows || [];
    let tableRows = '';

    if (rows.length === 0) {
        tableRows = '<tr><td colspan="6" class="mw-result-empty-cell">' + (data.message || 'No transactions found to verify.') + '</td></tr>';
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
                '<tr class="mw-result-row">' +
                '<td class="mw-result-cell-mono">'  + (r.tid || '—') + '</td>' +
                '<td class="mw-result-cell-muted">' + trunc(r.btxn, 24) + '</td>' +
                '<td class="mw-result-cell-muted">' + trunc(r.charge_id, 22) + '</td>' +
                '<td class="mw-result-cell-mono">'  + (r.deposit || '—') + '</td>' +
                '<td class="mw-result-cell-muted">' + (r.was && r.was !== r.deposit ? '<s>' + r.was + '</s>' : '') + '</td>' +
                '<td>' +
                    '<span class="mw-result-badge ' + s.className + '">' + s.label + '</span>' +
                '</td>' +
                '</tr>';
        });
    }

    const pill = function(n, label, className) {
        if (!n) return '';
        return '<span class="mw-result-badge ' + className + '">' + n + ' ' + label + '</span>';
    };

    const summary =
        pill(data.verified,  'verified',  'is-success') +
        pill(data.corrected, 'corrected', 'is-info') +
        pill(data.linked,    'linked',    'is-success') +
        pill(data.unmatched, 'unmatched', 'is-warning') +
        (data.errors?.length ? pill(data.errors.length, 'error(s)', 'is-error') : '') ||
        'No transactions found.';

    const modal = document.createElement('div');
    modal.id = 'mw-verify-links-modal';
    modal.className = 'mw-modal-overlay';

    modal.innerHTML =
        '<div class="mw-result-modal mw-result-modal-lg">' +

        '<div class="mw-modal-header">' +
        '<h3>Verify Links — Results</h3>' +
        '<button id="mw-vl-close" class="mw-modal-close" type="button">&times;</button>' +
        '</div>' +

        '<div class="mw-result-summary mw-result-summary-badges">' +
        '<span class="mw-result-summary-label">Results:</span>' + summary +
        (data.unmatched ? '<span class="mw-result-summary-note">Unmatched = Stripe charges with no local transaction record (other donors not yet in MW Tools)</span>' : '') +
        '</div>' +

        '<div class="mw-result-table-wrap">' +
        '<table class="mw-result-table">' +
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

        '<div class="mw-modal-footer">' +
        '<button id="mw-vl-dismiss" class="mw-btn mw-btn-secondary">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('mw-vl-close').onclick   = function() { modal.remove(); };
    document.getElementById('mw-vl-dismiss').onclick = function() { modal.remove(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

</script>
