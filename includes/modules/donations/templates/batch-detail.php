<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$batches_table      = Metis_Tables::get( 'batches' );
$transactions_table = Metis_Tables::get( 'transactions' );
$contacts_table     = Metis_Tables::get( 'contacts' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );

$base_url = metis_donations_base_url();
$code     = sanitize_text_field( $_GET['batch'] ?? '' );

if ( ! $code ) {
    echo '<h1 class="mw-page-title">Batch Not Found</h1><p class="mw-subtitle">Invalid batch code.</p>';
    return;
}

$batch = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$batches_table} WHERE batch_code = %s",
    $code
) );

if ( ! $batch ) {
    echo '<h1 class="mw-page-title">Batch Not Found</h1><p class="mw-subtitle">That batch does not exist.</p>';
    return;
}

$transactions = $wpdb->get_results( $wpdb->prepare(
    "SELECT t.*, d.first_name, d.last_name, d.email, c.cname AS campaign_name
     FROM {$transactions_table} t
     LEFT JOIN {$contacts_table} d ON d.did = t.did
     LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
     WHERE t.deposit_batch_id = %s
     ORDER BY t.tran_date ASC, t.id ASC",
    $batch->batch_code
) );

$deposit_date = $batch->deposit_date ? date( 'M d, Y', strtotime( $batch->deposit_date ) ) : '—';
metis_set_page_title( $batch->batch_code );

// Build export data for CSV
$export_rows = [];
foreach ( $transactions as $t ) {
    $donor = trim( ( $t->first_name ?: '' ) . ' ' . ( $t->last_name ?: '' ) ) ?: ( $t->email ?: 'Unknown' );
    $export_rows[] = [
        'batch_code'     => $batch->batch_code,
        'tid'            => $t->tid,
        'tran_date'      => $t->tran_date ? date( 'm/d/Y', strtotime( $t->tran_date ) ) : '',
        'donor_name'     => $donor,
        'email'          => $t->email ?? '',
        'campaign'       => $t->campaign_name ?: $t->campaign_code ?: '',
        'amount'         => (float) $t->amount,
        'fee'            => isset( $t->fee ) ? (float) $t->fee : 0,
        'net'            => (float) $t->amount - ( isset( $t->fee ) ? (float) $t->fee : 0 ),
        'status'         => $t->status ?? '',
        'payment_method' => $t->payment_method ?? '',
    ];
}

// Batch notes
$batch_notes = metis_get_batch_notes( $batch->batch_code );
?>

<h1 class="mw-page-title">Deposit Batch <?php echo esc_html( $batch->batch_code ); ?></h1>
<p class="mw-subtitle">Transactions included in this deposit batch.</p>

<!-- BATCH SUMMARY -->
<div class="mw-premium-row mw-batch-summary" style="margin-bottom:28px;">
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Deposit Date</div>
        <div class="large-number"><?php echo esc_html( $deposit_date ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Gross</div>
        <div class="large-number">$<?php echo number_format( (float) $batch->gross, 2 ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Fees</div>
        <div class="large-number">$<?php echo number_format( (float) $batch->fees, 2 ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Net</div>
        <div class="large-number">$<?php echo number_format( (float) $batch->net, 2 ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Transactions</div>
        <div class="large-number"><?php echo (int) $batch->txn_count; ?></div>
    </div>
</div>

<div style="margin-bottom:16px;">
    <button type="button" id="mw-batch-export" class="mw-btn mw-btn-xs">Export CSV</button>
</div>

<!-- TRANSACTIONS TABLE -->
<h2 class="mw-section-header">Transactions</h2>

<div class="mw-premium-table mw-batch-table">

    <div class="mw-premium-row mw-premium-header">
        <div>Date</div>
        <div>Donor</div>
        <div>Campaign</div>
        <div class="mw-col-numeric">Amount</div>
        <div>Status / Payment</div>
    </div>

    <?php if ( ! empty( $transactions ) ) : ?>
        <?php foreach ( $transactions as $t ) :
            $ts       = $t->tran_date ? strtotime( $t->tran_date ) : 0;
            $date     = $ts ? date( 'm/d/y', $ts ) : '—';
            $donor    = trim( ( $t->first_name ?: '' ) . ' ' . ( $t->last_name ?: '' ) ) ?: ( $t->email ?: 'Unknown' );
            $campaign = $t->campaign_name ?: $t->campaign_code ?: '—';
            $amount   = (float) $t->amount;
            $fee      = isset( $t->fee ) ? (float) $t->fee : 0;
            $tx_url   = $base_url . '/transaction/?tid=' . urlencode( $t->tid );
        ?>
            <div class="mw-premium-row mw-batch-row">
                <div><?php echo esc_html( $date ); ?></div>
                <div><a href="<?php echo esc_url( $tx_url ); ?>"><?php echo esc_html( $donor ); ?></a></div>
                <div><?php echo esc_html( $campaign ); ?></div>
                <div class="mw-col-numeric">
                    $<?php echo number_format( $amount, 2 ); ?>
                    <?php if ( $fee > 0 ) : ?>
                        <div class="mw-muted" style="font-size:12px;">($<?php echo number_format( $fee, 2 ); ?> fee)</div>
                    <?php endif; ?>
                </div>
                <div class="mw-badge-stack">
                    <?php echo metis_status_badge( $t->status ); ?>
                    <?php echo metis_paymethod_badge( $t->payment_method ); ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <div class="mw-premium-row">
            <div class="mw-premium-cell mw-muted">No transactions in this batch.</div>
        </div>
    <?php endif; ?>

</div>

<p style="margin-top:32px;">
    <a href="<?php echo esc_url( $base_url . '/transactions/' ); ?>" class="mw-btn mw-btn-xs">← Back to Transactions</a>
</p>

<!-- NOTES -->
<h2 class="mw-section-header" style="margin-top:32px;">Batch Notes</h2>

<div id="mw-batch-notes">
    <?php if ( ! empty( $batch_notes ) ) : ?>
        <ul class="mw-notes-list">
            <?php foreach ( $batch_notes as $n ) : ?>
                <li class="mw-note-item" data-id="<?php echo (int) $n->id; ?>">
                    <div class="mw-note-meta mw-muted" style="font-size:13px;">
                        <?php echo esc_html( $n->created_at ? date( 'm/d/Y H:i', strtotime( $n->created_at ) ) : '' ); ?>
                    </div>
                    <div class="mw-note-body"><?php echo nl2br( esc_html( $n->note_text ) ); ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="mw-muted" id="mw-notes-empty">No notes yet.</p>
    <?php endif; ?>
</div>

<div style="margin-top:12px; display:flex; gap:8px;">
    <input type="text" id="mw-new-note" class="mw-input" placeholder="Add a note…" style="flex:1;">
    <button type="button" id="mw-save-note" class="mw-btn mw-btn-xs">Save</button>
</div>

<script>
(function () {
    const notify = (message, type) => Metis.util.notify(message, type || 'info');

    const batchCode = <?php echo metis_json_encode( $batch->batch_code ); ?>;
    const exportData = <?php echo metis_json_encode( $export_rows ); ?>;

    // -------------------------------------------------------------------------
    // CSV Export
    // -------------------------------------------------------------------------
    document.getElementById('mw-batch-export')?.addEventListener('click', function () {
        if (!exportData.length) { notify('No transactions to export.', 'warning'); return; }

        const header = ['Batch Code','TID','Date','Donor','Email','Campaign','Amount','Fee','Net','Status','Payment Method'];
        const escape = v => { v = String(v ?? ''); return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g,'""') + '"' : v; };

        const lines = [header.map(escape).join(',')];
        exportData.forEach(r => lines.push([
            r.batch_code, r.tid, r.tran_date, r.donor_name, r.email,
            r.campaign, r.amount.toFixed(2), r.fee.toFixed(2), r.net.toFixed(2),
            r.status, r.payment_method
        ].map(escape).join(',')));

        const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = `batch-${batchCode}-${new Date().toISOString().slice(0,10)}.csv`;
        document.body.appendChild(a); a.click();
        document.body.removeChild(a); URL.revokeObjectURL(url);
    });

    // -------------------------------------------------------------------------
    // Add Note
    // -------------------------------------------------------------------------
    document.getElementById('mw-save-note')?.addEventListener('click', function () {
        const input = document.getElementById('mw-new-note');
        const text  = input?.value.trim();
        if (!text) return;

        fetch(metisAjax.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:      'metis_add_batch_note',
                batch_code:  batchCode,
                text:        text,
                _ajax_nonce: metisAjax.nonce
            })
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { notify('Failed to save note.', 'error'); return; }

            const empty = document.getElementById('mw-notes-empty');
            if (empty) empty.remove();

            let list = document.querySelector('#mw-batch-notes .mw-notes-list');
            if (!list) {
                list = document.createElement('ul');
                list.className = 'mw-notes-list';
                document.getElementById('mw-batch-notes').prepend(list);
            }

            const li = document.createElement('li');
            li.className = 'mw-note-item';
            li.innerHTML = `<div class="mw-note-meta mw-muted" style="font-size:13px;">Just now</div>
                            <div class="mw-note-body">${text.replace(/</g,'&lt;')}</div>`;
            list.prepend(li);

            if (input) input.value = '';
        });
    });

})();
</script>
