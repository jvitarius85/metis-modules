<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$deposits = metis_get_deposits();
$base_url = metis_donations_base_url();
?>

<h1 class="mw-page-title">Deposits</h1>
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
    <div class="mw-list-sidebar-section" style="padding-top:8px;">
        <span id="mw-sync-status" class="mw-muted" style="font-size:12px; line-height:1.4; display:block;"></span>
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
                 data-href="<?php echo esc_url( $detail_url ); ?>">
                <div class="mw-premium-cell"><?php echo esc_html( $date ); ?></div>
                <div class="mw-premium-cell mw-link"><?php echo esc_html( $d->provider_ref ); ?></div>
                <div class="mw-premium-cell"><?php echo metis_deposit_source_badge( $d ); ?></div>
                <div class="mw-premium-cell"><?php echo esc_html( ucfirst( $d->source ?? '' ) ); ?></div>
                <div class="mw-premium-cell mw-col-numeric"><?php echo (int) $d->batch_count; ?></div>
                <div class="mw-premium-cell mw-col-numeric"><?php echo esc_html( $total ); ?></div>
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
    if (row && row.dataset.href) window.location.href = row.dataset.href;
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
            setTimeout(() => location.reload(), 1400);
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
        tableRows = '<tr><td colspan="7" style="text-align:center;color:#6d7485;padding:20px">' + msg + '</td></tr>';
    } else {
        rows.forEach(function(r) {
            const labels = { filled_stripe: 'Updated \u2022 Stripe', filled_local: 'Updated \u2022 Local', skipped: 'Skipped' };
            const label  = labels[r.status] || r.status;
            const sColor = r.status === 'skipped' ? '#92400e' : '#15803d';
            const sBg    = r.status === 'skipped' ? '#fffbeb' : '#f0fdf4';

            const bGross = r.before ? fmt(r.before.gross) : '\u2014';
            const bFees  = r.before ? fmt(r.before.fees)  : '\u2014';
            const aGross = r.after  ? fmt(r.after.gross)  : '\u2014';
            const aFees  = r.after  ? fmt(r.after.fees)   : '\u2014';

            const changed = r.before && r.after &&
                            (r.before.gross !== r.after.gross || r.before.fees !== r.after.fees);

            const aGrossStyle = changed ? 'font-weight:700;color:#15803d' : '';
            const aFeesStyle  = changed ? 'font-weight:700;color:#b91c1c' : '';
            const linkedTxt   = r.linked > 0 ? r.linked + ' txn' + (r.linked > 1 ? 's' : '') : '\u2014';

            tableRows +=
                '<tr style="border-bottom:1px solid #e0e2ea">'
                + '<td style="padding:10px 12px;font-weight:600;font-family:monospace;font-size:13px">' + r.code + '</td>'
                + '<td style="padding:10px 12px"><span style="background:' + sBg + ';color:' + sColor + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px">' + label + '</span></td>'
                + '<td style="padding:10px 12px;text-align:right;color:#6d7485">' + bGross + '</td>'
                + '<td style="padding:10px 12px;text-align:right;color:#6d7485">' + bFees + '</td>'
                + '<td style="padding:10px 12px;text-align:right;' + aGrossStyle + '">' + aGross + '</td>'
                + '<td style="padding:10px 12px;text-align:right;' + aFeesStyle  + '">' + aFees  + '</td>'
                + '<td style="padding:10px 12px;text-align:right">' + linkedTxt + '</td>'
                + '</tr>';

            if (r.reason) {
                tableRows += '<tr><td colspan="7" style="padding:2px 12px 10px;font-size:12px;color:#92400e">'
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
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center';

    modal.innerHTML =
        '<div style="background:#fff;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.18);width:90%;max-width:820px;max-height:80vh;display:flex;flex-direction:column;overflow:hidden">'

        // Header
        + '<div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #e0e2ea;background:#f9faff">'
        + '<h2 style="margin:0;font-size:17px;font-weight:700;color:#485bc7">Backfill Results</h2>'
        + '<button id="mw-backfill-close" style="background:none;border:none;cursor:pointer;font-size:22px;color:#6d7485;line-height:1">&times;</button>'
        + '</div>'

        // Summary bar
        + '<div style="padding:14px 24px;background:#f5f6fa;font-size:13px;color:#1f2330;border-bottom:1px solid #e0e2ea">' + summary + '</div>'

        // Table
        + '<div style="overflow-y:auto;flex:1">'
        + '<table style="width:100%;border-collapse:collapse;font-size:13px">'
        + '<thead><tr style="background:#eceeff;color:#3246a7;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px">'
        + '<th style="padding:10px 12px;text-align:left">Deposit</th>'
        + '<th style="padding:10px 12px;text-align:left">Result</th>'
        + '<th style="padding:10px 12px;text-align:right">Before Gross</th>'
        + '<th style="padding:10px 12px;text-align:right">Before Fees</th>'
        + '<th style="padding:10px 12px;text-align:right">After Gross</th>'
        + '<th style="padding:10px 12px;text-align:right">After Fees</th>'
        + '<th style="padding:10px 12px;text-align:right">Linked</th>'
        + '</tr></thead>'
        + '<tbody>' + tableRows + '</tbody>'
        + '</table></div>'

        // Footer
        + '<div style="padding:14px 24px;border-top:1px solid #e0e2ea;display:flex;justify-content:flex-end;gap:10px">'
        + '<button id="mw-backfill-reload"  class="mw-btn">Reload Page</button>'
        + '<button id="mw-backfill-dismiss" class="mw-btn" style="background:#e0e2ea;color:#1f2330">Dismiss</button>'
        + '</div>'

        + '</div>';

    document.body.appendChild(modal);
    document.getElementById('mw-backfill-close').onclick   = function() { modal.remove(); };
    document.getElementById('mw-backfill-dismiss').onclick = function() { modal.remove(); };
    document.getElementById('mw-backfill-reload').onclick  = function() { location.reload(); };
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
        tableRows = '<tr><td colspan="7" style="text-align:center;color:#6d7485;padding:20px">' + (data.message || 'Nothing to link.') + '</td></tr>';
    } else {
        rows.forEach(function(r) {
            const statusMap = { linked: 'Linked', skipped: 'Skipped', error: 'Error' };
            const label     = statusMap[r.status] || r.status;
            const sColor    = r.status === 'linked'  ? '#15803d'
                            : r.status === 'skipped' ? '#92400e'
                            : '#b91c1c';
            const sBg       = r.status === 'linked'  ? '#f0fdf4'
                            : r.status === 'skipped' ? '#fffbeb'
                            : '#fef2f2';

            tableRows +=
                '<tr style="border-bottom:1px solid #e0e2ea">' +
                '<td style="padding:9px 12px;font-weight:600;font-family:monospace;font-size:12px">'   + r.tid + '</td>' +
                '<td style="padding:9px 12px;font-family:monospace;font-size:11px;color:#6d7485">'     + trunc(r.pi, 22) + '</td>' +
                '<td style="padding:9px 12px;font-family:monospace;font-size:11px;color:#6d7485">'     + trunc(r.charge_id, 18) + '</td>' +
                '<td style="padding:9px 12px;font-family:monospace;font-size:11px;color:#6d7485">'     + trunc(r.payout_id, 18) + '</td>' +
                '<td style="padding:9px 12px;font-family:monospace;font-size:12px;font-weight:600">'   + (r.deposit || '—') + '</td>' +
                '<td style="padding:9px 12px">' +
                    '<span style="background:' + sBg + ';color:' + sColor + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px">' + label + '</span>' +
                '</td>' +
                '<td style="padding:9px 12px;font-size:12px;color:#92400e">' + (r.note || '') + '</td>' +
                '</tr>';
        });
    }

    const newBadge = data.deposits_made > 0
        ? ' <span style="background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px">' + data.deposits_made + ' new deposit' + (data.deposits_made > 1 ? 's' : '') + ' created</span>'
        : '';

    const summary = data.message
        ? data.message
        : data.linked + ' transaction' + (data.linked !== 1 ? 's' : '') + ' linked' + newBadge +
          (data.skipped ? ', ' + data.skipped + ' skipped' : '') +
          (data.errors?.length ? ', ' + data.errors.length + ' error(s)' : '') + '.';

    const modal = document.createElement('div');
    modal.id = 'mw-link-payouts-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center';

    modal.innerHTML =
        '<div style="background:#fff;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.18);width:92%;max-width:960px;max-height:80vh;display:flex;flex-direction:column;overflow:hidden">' +

        // Header
        '<div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #e0e2ea;background:#f9faff">' +
        '<h2 style="margin:0;font-size:17px;font-weight:700;color:#485bc7">Link Payouts — Results</h2>' +
        '<button id="mw-lp-close" style="background:none;border:none;cursor:pointer;font-size:22px;color:#6d7485;line-height:1">&times;</button>' +
        '</div>' +

        // Summary
        '<div style="padding:14px 24px;background:#f5f6fa;font-size:13px;color:#1f2330;border-bottom:1px solid #e0e2ea">' + summary + '</div>' +

        // Table
        '<div style="overflow-y:auto;flex:1">' +
        '<table style="width:100%;border-collapse:collapse;font-size:13px">' +
        '<thead><tr style="background:#eceeff;color:#3246a7;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px">' +
        '<th style="padding:9px 12px;text-align:left">TX ID</th>' +
        '<th style="padding:9px 12px;text-align:left">Payment Intent</th>' +
        '<th style="padding:9px 12px;text-align:left">Charge</th>' +
        '<th style="padding:9px 12px;text-align:left">Payout</th>' +
        '<th style="padding:9px 12px;text-align:left">Deposit</th>' +
        '<th style="padding:9px 12px;text-align:left">Status</th>' +
        '<th style="padding:9px 12px;text-align:left">Note</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
        '</table></div>' +

        // Footer
        '<div style="padding:14px 24px;border-top:1px solid #e0e2ea;display:flex;justify-content:flex-end;gap:10px">' +
        '<button id="mw-lp-reload"  class="mw-btn">Reload Page</button>' +
        '<button id="mw-lp-dismiss" class="mw-btn" style="background:#e0e2ea;color:#1f2330">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('mw-lp-close').onclick   = function() { modal.remove(); };
    document.getElementById('mw-lp-dismiss').onclick = function() { modal.remove(); };
    document.getElementById('mw-lp-reload').onclick  = function() { location.reload(); };
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
        tableRows = '<tr><td colspan="7" style="text-align:center;color:#6d7485;padding:20px">No new transactions to import.</td></tr>';
    } else {
        rows.forEach(function(r) {
            const isError    = r.status === 'error';
            const newContact = r.new_contact;
            const rowBg      = isError ? '#fff8f8' : '';
            const statusEl   = isError
                ? '<span style="background:#fef2f2;color:#b91c1c;font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px">Error</span>'
                : '<span style="background:#f0fdf4;color:#15803d;font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px">Imported</span>';
            const contactEl  = newContact
                ? r.did + ' <span style="background:#dbeafe;color:#1d4ed8;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px">new</span>'
                : (r.did || '—');

            tableRows +=
                '<tr style="border-bottom:1px solid #e0e2ea;background:' + rowBg + '">' +
                '<td style="padding:9px 12px;font-weight:600;font-family:monospace;font-size:12px">' + (r.tid || '—') + '</td>' +
                '<td style="padding:9px 12px;font-size:13px">' + (r.email || '—') + '</td>' +
                '<td style="padding:9px 12px;font-family:monospace;font-size:11px;color:#6d7485">' + contactEl + '</td>' +
                '<td style="padding:9px 12px;font-size:13px;text-align:right">' + (r.amount || '—') + '</td>' +
                '<td style="padding:9px 12px;font-size:13px;text-align:right">' + (r.net || '—') + '</td>' +
                '<td style="padding:9px 12px;font-size:12px;color:#6d7485">' + (r.date || '—') + '</td>' +
                '<td style="padding:9px 12px">' + statusEl + '</td>' +
                '</tr>';
        });
    }

    const pill = function(n, label, color, bg) {
        if (!n) return '';
        return '<span style="background:' + bg + ';color:' + color + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;margin-right:6px">' + n + ' ' + label + '</span>';
    };

    const summary =
        pill(data.imported,      'imported',         '#15803d', '#f0fdf4') +
        pill(data.skipped,       'already existed',  '#6d7485', '#f3f4f6') +
        pill(data.contacts_made, 'contacts created', '#1d4ed8', '#dbeafe') +
        (data.errors?.length ? pill(data.errors.length, 'error(s)', '#b91c1c', '#fef2f2') : '') ||
        'Nothing to import.';

    const modal = document.createElement('div');
    modal.id = 'mw-import-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center';

    modal.innerHTML =
        '<div style="background:#fff;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.18);width:92%;max-width:1080px;max-height:84vh;display:flex;flex-direction:column;overflow:hidden">' +

        '<div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #e0e2ea;background:#f9faff">' +
        '<h2 style="margin:0;font-size:17px;font-weight:700;color:#485bc7">Import Stripe Transactions \u2014 Results</h2>' +
        '<button id="mw-imp-close" style="background:none;border:none;cursor:pointer;font-size:22px;color:#6d7485;line-height:1">&times;</button>' +
        '</div>' +

        '<div style="padding:14px 24px;background:#f5f6fa;border-bottom:1px solid #e0e2ea;display:flex;align-items:center;flex-wrap:wrap;gap:4px">' +
        summary +
        '</div>' +

        '<div style="overflow-y:auto;flex:1">' +
        '<table style="width:100%;border-collapse:collapse;font-size:13px">' +
        '<thead><tr style="background:#eceeff;color:#3246a7;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px">' +
        '<th style="padding:9px 12px;text-align:left">TX ID</th>' +
        '<th style="padding:9px 12px;text-align:left">Email</th>' +
        '<th style="padding:9px 12px;text-align:left">Donor ID</th>' +
        '<th style="padding:9px 12px;text-align:right">Gross</th>' +
        '<th style="padding:9px 12px;text-align:right">Net</th>' +
        '<th style="padding:9px 12px;text-align:left">Date</th>' +
        '<th style="padding:9px 12px;text-align:left">Status</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
        '</table></div>' +

        '<div style="padding:14px 24px;border-top:1px solid #e0e2ea;display:flex;justify-content:flex-end;gap:10px">' +
        '<button id="mw-imp-reload"  class="mw-btn">Reload Page</button>' +
        '<button id="mw-imp-dismiss" class="mw-btn" style="background:#e0e2ea;color:#1f2330">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('mw-imp-close').onclick   = function() { modal.remove(); };
    document.getElementById('mw-imp-dismiss').onclick = function() { modal.remove(); };
    document.getElementById('mw-imp-reload').onclick  = function() { location.reload(); };
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
        tableRows = '<tr><td colspan="6" style="text-align:center;color:#6d7485;padding:20px">' + (data.message || 'No transactions found to verify.') + '</td></tr>';
    } else {
        rows.forEach(function(r) {
            const styleMap = {
                verified  : { label: 'Verified',  color: '#15803d', bg: '#f0fdf4' },
                corrected : { label: 'Corrected', color: '#1d4ed8', bg: '#dbeafe' },
                linked    : { label: 'Linked',    color: '#15803d', bg: '#f0fdf4' },
                unmatched : { label: 'Unmatched', color: '#92400e', bg: '#fffbeb' },
                error     : { label: 'Error',     color: '#b91c1c', bg: '#fef2f2' },
            };
            const s = styleMap[r.status] || { label: r.status, color: '#374151', bg: '#f3f4f6' };

            tableRows +=
                '<tr style="border-bottom:1px solid #e0e2ea">' +
                '<td style="padding:9px 12px;font-weight:600;font-family:monospace;font-size:12px">'  + (r.tid || '—') + '</td>' +
                '<td style="padding:9px 12px;font-family:monospace;font-size:11px;color:#6d7485">'    + trunc(r.btxn, 24) + '</td>' +
                '<td style="padding:9px 12px;font-family:monospace;font-size:11px;color:#6d7485">'    + trunc(r.charge_id, 22) + '</td>' +
                '<td style="padding:9px 12px;font-family:monospace;font-size:12px;font-weight:600">'  + (r.deposit || '—') + '</td>' +
                '<td style="padding:9px 12px;font-size:11px;color:#6d7485">'                          + (r.was && r.was !== r.deposit ? '<s>' + r.was + '</s>' : '') + '</td>' +
                '<td style="padding:9px 12px">' +
                    '<span style="background:' + s.bg + ';color:' + s.color + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px">' + s.label + '</span>' +
                '</td>' +
                '</tr>';
        });
    }

    const pill = function(n, label, color, bg) {
        if (!n) return '';
        return ' <span style="background:' + bg + ';color:' + color + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;margin-left:4px">' + n + ' ' + label + '</span>';
    };

    const summary =
        pill(data.verified,  'verified',  '#15803d', '#f0fdf4') +
        pill(data.corrected, 'corrected', '#1d4ed8', '#dbeafe') +
        pill(data.linked,    'linked',    '#15803d', '#f0fdf4') +
        pill(data.unmatched, 'unmatched', '#92400e', '#fffbeb') +
        (data.errors?.length ? pill(data.errors.length, 'error(s)', '#b91c1c', '#fef2f2') : '') ||
        'No transactions found.';

    const modal = document.createElement('div');
    modal.id = 'mw-verify-links-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center';

    modal.innerHTML =
        '<div style="background:#fff;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.18);width:92%;max-width:1000px;max-height:80vh;display:flex;flex-direction:column;overflow:hidden">' +

        '<div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #e0e2ea;background:#f9faff">' +
        '<h2 style="margin:0;font-size:17px;font-weight:700;color:#485bc7">Verify Links \u2014 Results</h2>' +
        '<button id="mw-vl-close" style="background:none;border:none;cursor:pointer;font-size:22px;color:#6d7485;line-height:1">&times;</button>' +
        '</div>' +

        '<div style="padding:14px 24px;background:#f5f6fa;font-size:13px;color:#1f2330;border-bottom:1px solid #e0e2ea;display:flex;align-items:center;flex-wrap:wrap;gap:4px">' +
        '<span style="color:#6d7485;margin-right:4px">Results:</span>' + summary +
        (data.unmatched ? '<span style="margin-left:12px;font-size:12px;color:#92400e">Unmatched = Stripe charges with no local transaction record (other donors not yet in MW Tools)</span>' : '') +
        '</div>' +

        '<div style="overflow-y:auto;flex:1">' +
        '<table style="width:100%;border-collapse:collapse;font-size:13px">' +
        '<thead><tr style="background:#eceeff;color:#3246a7;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px">' +
        '<th style="padding:9px 12px;text-align:left">TX ID</th>' +
        '<th style="padding:9px 12px;text-align:left">Balance Txn</th>' +
        '<th style="padding:9px 12px;text-align:left">Charge ID</th>' +
        '<th style="padding:9px 12px;text-align:left">Deposit</th>' +
        '<th style="padding:9px 12px;text-align:left">Was</th>' +
        '<th style="padding:9px 12px;text-align:left">Status</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
        '</table></div>' +

        '<div style="padding:14px 24px;border-top:1px solid #e0e2ea;display:flex;justify-content:flex-end;gap:10px">' +
        '<button id="mw-vl-reload"  class="mw-btn">Reload Page</button>' +
        '<button id="mw-vl-dismiss" class="mw-btn" style="background:#e0e2ea;color:#1f2330">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('mw-vl-close').onclick   = function() { modal.remove(); };
    document.getElementById('mw-vl-dismiss').onclick = function() { modal.remove(); };
    document.getElementById('mw-vl-reload').onclick  = function() { location.reload(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}

// ── Import Stripe Transactions ───────────────────────────────────────────────
document.getElementById('mw-import-transactions')?.addEventListener('click', function () {
    const btn    = this;
    const status = document.getElementById('mw-sync-status');
    btn.disabled       = true;
    status.textContent = 'Importing… (this may take a moment)';

    fetch(metisAjax.ajax_url, {
        method : 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body   : new URLSearchParams({ action: 'metis_import_stripe_charges', _ajax_nonce: metisAjax.nonce })
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled       = false;
        status.textContent = '';
        if (!res.success) {
            status.textContent = 'Failed: ' + (res.data?.message || 'unknown error');
            return;
        }
        mwShowImportResults(res.data);
    })
    .catch(() => { btn.disabled = false; status.textContent = 'Network error.'; });
});

function mwShowImportResults(data) {
    document.getElementById('mw-import-results-modal')?.remove();

    const trunc = (s, n) => { if (!s) return '\u2014'; s = String(s); return s.length > n ? s.substring(0, n) + '\u2026' : s; };

    const rows     = data.rows || [];
    let tableRows  = '';

    if (rows.length === 0) {
        tableRows = '<tr><td colspan="8" style="text-align:center;color:#6d7485;padding:20px">No new transactions found.</td></tr>';
    } else {
        rows.forEach(function(r) {
            const styleMap = {
                imported : { label: 'Imported', color: '#15803d', bg: '#f0fdf4' },
                error    : { label: 'Error',    color: '#b91c1c', bg: '#fef2f2' },
            };
            const s = styleMap[r.status] || { label: r.status, color: '#374151', bg: '#f3f4f6' };
            const donorBadge = r.donor_status === 'created'
                ? '<span style="background:#dbeafe;color:#1d4ed8;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px">New</span>'
                : '';

            tableRows +=
                '<tr style="border-bottom:1px solid #e0e2ea">' +
                '<td style="padding:8px 10px;font-weight:600;font-family:monospace;font-size:12px">'   + (r.tid || '\u2014') + '</td>' +
                '<td style="padding:8px 10px;font-family:monospace;font-size:11px;color:#6d7485">'     + trunc(r.charge_id, 20) + '</td>' +
                '<td style="padding:8px 10px;font-size:12px">'                                         + trunc(r.email, 28) + '</td>' +
                '<td style="padding:8px 10px;font-family:monospace;font-size:12px">'                   + (r.did || '\u2014') + donorBadge + '</td>' +
                '<td style="padding:8px 10px;font-size:12px;text-align:right;font-weight:600">'        + (r.amount || '\u2014') + '</td>' +
                '<td style="padding:8px 10px;font-family:monospace;font-size:12px">'                   + (r.deposit || '\u2014') + '</td>' +
                '<td style="padding:8px 10px;font-size:12px;color:#6d7485">'                           + (r.date || '\u2014') + '</td>' +
                '<td style="padding:8px 10px">' +
                    '<span style="background:' + s.bg + ';color:' + s.color + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px">' + s.label + '</span>' +
                '</td>' +
                '</tr>';
        });
    }

    const pill = (n, label, color, bg) => n
        ? '<span style="background:' + bg + ';color:' + color + ';font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;margin-left:4px">' + n + ' ' + label + '</span>'
        : '';

    const summary =
        pill(data.imported,   'imported',     '#15803d', '#f0fdf4') +
        pill(data.new_donors, 'new donors',   '#1d4ed8', '#dbeafe') +
        pill(data.skipped,    'skipped',      '#6d7485', '#f3f4f6') +
        (data.errors?.length ? pill(data.errors.length, 'error(s)', '#b91c1c', '#fef2f2') : '') ||
        'No changes made.';

    const modal = document.createElement('div');
    modal.id = 'mw-import-results-modal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;display:flex;align-items:center;justify-content:center';

    modal.innerHTML =
        '<div style="background:#fff;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,.18);width:94%;max-width:1100px;max-height:82vh;display:flex;flex-direction:column;overflow:hidden">' +

        '<div style="display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid #e0e2ea;background:#f9faff">' +
        '<h2 style="margin:0;font-size:17px;font-weight:700;color:#485bc7">Import Stripe Transactions \u2014 Results</h2>' +
        '<button id="mw-ir-close" style="background:none;border:none;cursor:pointer;font-size:22px;color:#6d7485;line-height:1">&times;</button>' +
        '</div>' +

        '<div style="padding:14px 24px;background:#f5f6fa;font-size:13px;color:#1f2330;border-bottom:1px solid #e0e2ea;display:flex;align-items:center;flex-wrap:wrap;gap:4px">' +
        '<span style="color:#6d7485;margin-right:4px">Results:</span>' + summary +
        '</div>' +

        '<div style="overflow-y:auto;flex:1">' +
        '<table style="width:100%;border-collapse:collapse;font-size:13px">' +
        '<thead><tr style="background:#eceeff;color:#3246a7;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px">' +
        '<th style="padding:8px 10px;text-align:left">TX ID</th>' +
        '<th style="padding:8px 10px;text-align:left">Charge ID</th>' +
        '<th style="padding:8px 10px;text-align:left">Email</th>' +
        '<th style="padding:8px 10px;text-align:left">Donor ID</th>' +
        '<th style="padding:8px 10px;text-align:right">Amount</th>' +
        '<th style="padding:8px 10px;text-align:left">Deposit</th>' +
        '<th style="padding:8px 10px;text-align:left">Date</th>' +
        '<th style="padding:8px 10px;text-align:left">Status</th>' +
        '</tr></thead>' +
        '<tbody>' + tableRows + '</tbody>' +
        '</table></div>' +

        '<div style="padding:14px 24px;border-top:1px solid #e0e2ea;display:flex;justify-content:flex-end;gap:10px">' +
        '<button id="mw-ir-reload"  class="mw-btn">Reload Page</button>' +
        '<button id="mw-ir-dismiss" class="mw-btn" style="background:#e0e2ea;color:#1f2330">Dismiss</button>' +
        '</div>' +
        '</div>';

    document.body.appendChild(modal);
    document.getElementById('mw-ir-close').onclick   = function() { modal.remove(); };
    document.getElementById('mw-ir-dismiss').onclick = function() { modal.remove(); };
    document.getElementById('mw-ir-reload').onclick  = function() { location.reload(); };
    modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
}
</script>
