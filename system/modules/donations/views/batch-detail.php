<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$batches_table      = Metis_Tables::get( 'batches' );
$transactions_table = Metis_Tables::get( 'transactions' );
$contacts_table     = Metis_Tables::get( 'contacts' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );

$base_url = metis_donations_base_url();
$code     = metis_donations_request_identifier( 'batch', 'batch' );

if ( ! $code ) {
    echo '<h1 class="metis-page-title">Batch Not Found</h1><p class="metis-subtitle">Invalid batch code.</p>';
    return;
}

$batch = $db->fetchOne( "SELECT * FROM {$batches_table} WHERE batch_code = %s", [ $code ] );
$batch = $batch ? (object) $batch : null;

if ( ! $batch ) {
    echo '<h1 class="metis-page-title">Batch Not Found</h1><p class="metis-subtitle">That batch does not exist.</p>';
    return;
}

$transactions = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "SELECT t.*, d.first_name, d.last_name, d.email, c.cname AS campaign_name
     FROM {$transactions_table} t
     LEFT JOIN {$contacts_table} d ON d.did = t.did
     LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
     WHERE t.deposit_batch_id = %s
     ORDER BY t.tran_date ASC, t.id ASC",
    [ $batch->batch_code ]
) ?: [] );

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

<h1 class="metis-page-title">Deposit Batch <?php echo metis_escape_html( $batch->batch_code ); ?></h1>
<p class="metis-subtitle">Transactions included in this deposit batch.</p>

<!-- BATCH SUMMARY -->
<div class="metis-summary-grid metis-batch-summary metis-batch-summary-row">
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Deposit Date</div>
        <div class="large-number"><?php echo metis_escape_html( $deposit_date ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Gross</div>
        <div class="large-number">$<?php echo number_format( (float) $batch->gross, 2 ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Fees</div>
        <div class="large-number">$<?php echo number_format( (float) $batch->fees, 2 ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Net</div>
        <div class="large-number">$<?php echo number_format( (float) $batch->net, 2 ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Transactions</div>
        <div class="large-number"><?php echo (int) $batch->txn_count; ?></div>
    </div>
</div>

<div class="metis-batch-actions-row">
    <button type="button" id="metis-batch-export" class="metis-btn metis-btn-xs">Export CSV</button>
</div>

<!-- TRANSACTIONS TABLE -->
<h2 class="metis-section-header">Transactions</h2>

<table class="metis-premium-table metis-batch-table">
    <thead>
        <tr class="metis-premium-row metis-premium-header metis-batch-row">
            <th class="metis-premium-cell" scope="col">Date</th>
            <th class="metis-premium-cell" scope="col">Donor</th>
            <th class="metis-premium-cell" scope="col">Campaign</th>
            <th class="metis-premium-cell metis-col-numeric" scope="col">Amount</th>
            <th class="metis-premium-cell" scope="col">Status / Payment</th>
        </tr>
    </thead>
    <tbody>

    <?php if ( ! empty( $transactions ) ) : ?>
        <?php foreach ( $transactions as $t ) :
            $ts       = $t->tran_date ? strtotime( $t->tran_date ) : 0;
            $date     = $ts ? date( 'm/d/y', $ts ) : '—';
            $donor    = trim( ( $t->first_name ?: '' ) . ' ' . ( $t->last_name ?: '' ) ) ?: ( $t->email ?: 'Unknown' );
            $campaign = $t->campaign_name ?: $t->campaign_code ?: '—';
            $amount   = (float) $t->amount;
            $fee      = isset( $t->fee ) ? (float) $t->fee : 0;
            $tx_url   = metis_donations_detail_url( 'transaction', (string) $t->tid );
        ?>
            <tr class="metis-premium-row metis-batch-row">
                <td class="metis-premium-cell"><?php echo metis_escape_html( $date ); ?></td>
                <td class="metis-premium-cell"><a href="<?php echo metis_escape_url( $tx_url ); ?>"><?php echo metis_escape_html( $donor ); ?></a></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html( $campaign ); ?></td>
                <td class="metis-premium-cell metis-col-numeric">
                    $<?php echo number_format( $amount, 2 ); ?>
                    <?php if ( $fee > 0 ) : ?>
                        <div class="metis-muted metis-batch-fee-note">($<?php echo number_format( $fee, 2 ); ?> fee)</div>
                    <?php endif; ?>
                </td>
                <td class="metis-premium-cell metis-badge-stack">
                    <?php echo metis_status_badge( $t->status ); ?>
                    <?php echo metis_paymethod_badge_with_details( $t->payment_method, $t ); ?>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else : ?>
        <tr class="metis-premium-row">
            <td class="metis-premium-cell metis-muted" colspan="5">No transactions in this batch.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<p class="metis-batch-back-link">
    <a href="<?php echo metis_escape_url( $base_url . '/transactions/' ); ?>" class="metis-btn metis-btn-xs">← Back to Transactions</a>
</p>

<!-- NOTES -->
<h2 class="metis-section-header metis-batch-notes-heading">Batch Notes</h2>

<div id="metis-batch-notes">
    <?php if ( ! empty( $batch_notes ) ) : ?>
        <ul class="metis-notes-list">
            <?php foreach ( $batch_notes as $n ) : ?>
                <li class="metis-note-item" data-id="<?php echo (int) $n->id; ?>">
                    <div class="metis-note-meta metis-muted metis-batch-note-meta">
                        <?php echo metis_escape_html( $n->created_at ? date( 'm/d/Y H:i', strtotime( $n->created_at ) ) : '' ); ?>
                    </div>
                    <div class="metis-note-body"><?php echo nl2br( metis_escape_html( $n->note_text ) ); ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="metis-muted" id="metis-notes-empty">No notes yet.</p>
    <?php endif; ?>
</div>

<div class="metis-batch-note-form">
    <input type="text" id="metis-new-note" class="metis-input metis-batch-note-input" placeholder="Add a note…">
    <button type="button" id="metis-save-note" class="metis-btn metis-btn-xs">Save</button>
</div>

<script>
(function () {
    const notify = (message, type) => Metis.util.notify(message, type || 'info');

    const batchCode = <?php echo metis_json_encode( $batch->batch_code ); ?>;
    const exportData = <?php echo metis_json_encode( $export_rows ); ?>;

    // -------------------------------------------------------------------------
    // CSV Export
    // -------------------------------------------------------------------------
    document.getElementById('metis-batch-export')?.addEventListener('click', function () {
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
    document.getElementById('metis-save-note')?.addEventListener('click', function () {
        const input = document.getElementById('metis-new-note');
        const text  = input?.value.trim();
        if (!text) return;

        const body = new URLSearchParams({
            action:      'metis_add_batch_note',
            batch_code:  batchCode,
            text:        text
        });
        body.set('metis_action_nonce', window.Metis && Metis.ajax && typeof Metis.ajax.nonceFor === 'function'
            ? Metis.ajax.nonceFor('metis_add_batch_note', metisAjax.nonce)
            : metisAjax.nonce);

        fetch(metisAjax.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { notify('Failed to save note.', 'error'); return; }

            const empty = document.getElementById('metis-notes-empty');
            if (empty) empty.remove();

            let list = document.querySelector('#metis-batch-notes .metis-notes-list');
            if (!list) {
                list = document.createElement('ul');
                list.className = 'metis-notes-list';
                document.getElementById('metis-batch-notes').prepend(list);
            }

            const li = document.createElement('li');
            li.className = 'metis-note-item';
            li.innerHTML = `<div class="metis-note-meta metis-muted metis-batch-note-meta">Just now</div>
                            <div class="metis-note-body">${text.replace(/</g,'&lt;')}</div>`;
            list.prepend(li);

            if (input) input.value = '';
        });
    });

})();
</script>
