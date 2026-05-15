<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );
$refunds_table      = Metis_Tables::get( 'transaction_refunds' );
$notes_table        = Metis_Tables::get( 'transaction_notes' );

$base_url = metis_donations_base_url();
$tid      = metis_donations_request_identifier( 'tid', 'transaction' );

if ( $tid === '' ) : ?>
    <h1 class="metis-page-title">Transaction Not Found</h1>
    <p class="metis-subtitle">No Transaction ID was provided.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/transactions/' ); ?>" class="metis-btn metis-btn-xs">← Back</a>
    <?php return;
endif;

$transaction = $db->fetchOne(
    "SELECT t.*, c.cname AS campaign_name,
            d.first_name, d.last_name, d.email, d.did AS donor_did
     FROM {$transactions_table} t
     LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
     LEFT JOIN {$contacts_table}  d ON d.did = t.did
     WHERE t.tid = %s
     LIMIT 1",
    [ $tid ]
);
$transaction = $transaction ? (object) $transaction : null;

if ( ! $transaction ) : ?>
    <h1 class="metis-page-title">Transaction Not Found</h1>
    <p class="metis-subtitle">We couldn't match that transaction ID.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/transactions/' ); ?>" class="metis-btn metis-btn-xs">← Back</a>
    <?php return;
endif;

// Core values
$donor_name   = trim( $transaction->first_name . ' ' . $transaction->last_name );
$campaign     = $transaction->campaign_name ?: $transaction->campaign_code ?: '—';
$amount_raw   = (float) $transaction->amount;
$fee_raw      = $transaction->fee    !== null ? (float) $transaction->fee    : 0.0;
$payout_raw   = $transaction->payout !== null ? (float) $transaction->payout : 0.0;
$gross_raw    = $amount_raw + $fee_raw;

$display_date = $transaction->tran_date ? date( 'm/d/Y', strtotime( $transaction->tran_date ) ) : '—';
metis_set_page_title( $transaction->tid );
$donor_url    = metis_donations_detail_url( 'donor', (string) ( $transaction->did ?: $transaction->donor_did ) );
$stripe_refund_available = ! empty( $transaction->stripe_charge_id ) || ! empty( $transaction->stripe_pay_int );

$batch_code = ! empty( $transaction->deposit_batch_id ) ? $transaction->deposit_batch_id : '';
$batch_url  = $batch_code ? metis_donations_detail_url( 'batch', (string) $batch_code ) : '';

// Refunds
$auth_users_table = Metis_Tables::get( 'auth_users' );
$refund_columns = $db->column( "SHOW COLUMNS FROM {$refunds_table}" );
$refund_user_column = in_array( 'created_by', $refund_columns, true )
    ? 'created_by'
    : ( in_array( 'refunded_by', $refund_columns, true ) ? 'refunded_by' : '' );
$refund_date_expr = in_array( 'refund_date', $refund_columns, true )
    ? 'COALESCE(r.refund_date, r.created_at)'
    : 'r.created_at';
$refund_user_join = $refund_user_column !== ''
    ? "LEFT JOIN {$auth_users_table} u ON u.id = r.{$refund_user_column}"
    : "LEFT JOIN {$auth_users_table} u ON 1 = 0";
$refunds = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "SELECT r.*, {$refund_date_expr} AS refund_display_date, u.display_name
     FROM {$refunds_table} r
     {$refund_user_join}
     WHERE r.tid = %s ORDER BY r.created_at DESC",
    [ $tid ]
) ?: [] );

$total_refunded_raw = array_sum( array_map( fn($r) => (float) $r->amount, $refunds ) );

// Notes
$notes = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "SELECT n.*, u.display_name
     FROM {$notes_table} n
     LEFT JOIN {$auth_users_table} u ON u.id = n.user_id
     WHERE n.tid = %s ORDER BY n.created_at DESC",
    [ $tid ]
) ?: [] );
?>

<h1 class="metis-page-title">Transaction <?php echo metis_escape_html( $transaction->tid ); ?></h1>
<p class="metis-subtitle">Detailed record for this donation.</p>
<p><a href="<?php echo metis_escape_url( $donor_url ); ?>" class="metis-btn metis-btn-xs">← Back to Donor</a></p>

<!-- SUMMARY CARD -->
<div class="metis-summary-grid metis-transaction-summary">
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Donor</div>
        <div class="large-number"><?php echo metis_escape_html( $donor_name ?: '—' ); ?></div>
        <?php if ( $transaction->email ) : ?>
            <div class="metis-muted metis-transaction-meta-text"><?php echo metis_escape_html( $transaction->email ); ?></div>
        <?php endif; ?>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Campaign</div>
        <div class="large-number"><?php echo metis_escape_html( $campaign ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Amount</div>
        <div class="large-number">$<?php echo number_format( $amount_raw, 2 ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Date</div>
        <div class="large-number"><?php echo metis_escape_html( $display_date ); ?></div>
    </div>
</div>

<!-- DETAILS -->
<h2 class="metis-page-title metis-transaction-section-title">Details</h2>
<div class="metis-summary-grid metis-transaction-details">
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Status</div>
        <div><?php echo metis_status_badge( $transaction->status ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Payment Method</div>
        <div><?php echo metis_paymethod_badge( $transaction->payment_method ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Deposit</div>
        <div>
            <?php echo metis_deposit_badge( $transaction->deposit_date ); ?>
            <?php if ( $batch_code ) : ?>
                <div class="metis-transaction-batch-row">
                    <span class="metis-muted">Batch:</span>
                    <a href="<?php echo metis_escape_url( $batch_url ); ?>" class="metis-batch-link">
                        <?php echo metis_escape_html( $batch_code ); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Platform</div>
        <div class="large-number"><?php echo metis_escape_html( metis_platform_label( $transaction->platform ) ); ?></div>
    </div>
</div>

<!-- FINANCIAL SUMMARY -->
<h2 class="metis-page-title metis-transaction-section-title">Financial Summary</h2>
<div class="metis-summary-grid metis-transaction-financial">
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Gross Amount</div>
        <div class="large-number">$<?php echo number_format( $gross_raw, 2 ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Fees</div>
        <div class="large-number"><?php echo $fee_raw > 0 ? '$' . number_format( $fee_raw, 2 ) : '—'; ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Net Payout</div>
        <div class="large-number"><?php echo $payout_raw > 0 ? '$' . number_format( $payout_raw, 2 ) : '—'; ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted small-label">Total Refunded</div>
        <div class="large-number" id="metis-total-refunded">$<?php echo number_format( $total_refunded_raw, 2 ); ?></div>
        <div class="metis-muted metis-transaction-meta-text" id="metis-net-after-refunds"<?php echo $total_refunded_raw > 0 ? '' : ' hidden'; ?>>
            Net after refunds: $<?php echo number_format( $amount_raw - $total_refunded_raw, 2 ); ?>
        </div>
    </div>
</div>

<!-- REFUND HISTORY -->
<h2 class="metis-page-title metis-transaction-section-title">Refund History</h2>
<div class="metis-detail-panel metis-transaction-refunds">
    <form class="metis-inline-form metis-transaction-refund-form" id="metis-transaction-refund-form">
        <input type="hidden" name="tid" value="<?php echo metis_escape_attr( $transaction->tid ); ?>">
        <input class="metis-input" type="number" min="0.01" step="0.01" name="amount" placeholder="Amount">
        <select class="metis-input" name="source">
            <?php if ( $stripe_refund_available ) : ?>
                <option value="stripe">Stripe refund</option>
            <?php endif; ?>
            <option value="manual"<?php echo $stripe_refund_available ? '' : ' selected'; ?>>Manual record</option>
        </select>
        <input class="metis-input" type="text" name="reason" placeholder="Reason">
        <textarea class="metis-input" name="notes" rows="2" placeholder="Internal refund notes"></textarea>
        <button type="submit" class="metis-btn metis-btn-xs">Submit Refund</button>
    </form>
    <?php if ( ! empty( $refunds ) ) : ?>
        <div class="metis-refund-header-row" id="metis-refund-header-row">
            <div class="metis-ref-col">Amount</div>
            <div class="metis-ref-col">Date</div>
            <div class="metis-ref-col">By</div>
            <div class="metis-ref-col">Source</div>
            <div class="metis-ref-col">Reason</div>
        </div>
        <?php foreach ( $refunds as $r ) : ?>
            <div class="metis-refund-row">
                <div class="metis-ref-col">$<?php echo number_format( (float) $r->amount, 2 ); ?></div>
                <div class="metis-ref-col"><?php echo $r->refund_display_date ? metis_escape_html( date( 'm/d/Y H:i', strtotime( $r->refund_display_date ) ) ) : '—'; ?></div>
                <div class="metis-ref-col"><?php echo metis_escape_html( $r->display_name ?: 'System' ); ?></div>
                <div class="metis-ref-col"><?php echo metis_escape_html( ucfirst( $r->source ?: 'manual' ) ); ?></div>
                <div class="metis-ref-col"><?php echo metis_escape_html( $r->reason ?: '—' ); ?></div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <p class="metis-muted" id="metis-transaction-refunds-empty">No refunds recorded for this transaction.</p>
    <?php endif; ?>
</div>

<!-- NOTES -->
<h2 class="metis-page-title metis-transaction-section-title">Internal Notes</h2>
<div class="metis-detail-panel metis-transaction-notes">
    <form class="metis-inline-form metis-transaction-note-form" id="metis-transaction-note-form">
        <input type="hidden" name="tid" value="<?php echo metis_escape_attr( $transaction->tid ); ?>">
        <textarea class="metis-input" name="note" rows="3" placeholder="Add internal note"></textarea>
        <button type="submit" class="metis-btn metis-btn-xs">Add Note</button>
    </form>
    <?php if ( ! empty( $notes ) ) : ?>
        <ul class="metis-notes-list" id="metis-transaction-notes-list">
            <?php foreach ( $notes as $n ) : ?>
                <li class="metis-note-item">
                    <div class="metis-note-meta">
                        <span class="metis-note-author"><?php echo metis_escape_html( $n->display_name ?: 'System' ); ?></span>
                        <span class="metis-note-date metis-muted"> · <?php echo $n->created_at ? metis_escape_html( date( 'm/d/Y H:i', strtotime( $n->created_at ) ) ) : '—'; ?></span>
                    </div>
                    <div class="metis-note-body"><?php echo nl2br( metis_escape_html( $n->note ) ); ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="metis-muted" id="metis-transaction-notes-empty">No notes yet for this transaction.</p>
    <?php endif; ?>
</div>

<script>
(function() {
    'use strict';

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function(ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function actionBody(action, fields) {
        var body = new URLSearchParams(Object.assign({ action: action }, fields || {}));
        var fallback = window.metisAjax && metisAjax.nonce ? metisAjax.nonce : '';
        var nonce = window.Metis && Metis.ajax && typeof Metis.ajax.nonceFor === 'function'
            ? Metis.ajax.nonceFor(action, fallback)
            : fallback;
        body.set('metis_action_nonce', nonce);
        return body;
    }

    function post(action, fields) {
        return fetch(metisAjax.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: actionBody(action, fields)
        }).then(function(response) { return response.json(); });
    }

    function toast(message, type) {
        if (typeof window.metis_toast === 'function') {
            window.metis_toast(message, type || 'info');
        }
    }

    document.getElementById('metis-transaction-note-form')?.addEventListener('submit', function(event) {
        event.preventDefault();
        var form = event.currentTarget;
        var note = (form.note.value || '').trim();
        if (!note) return;

        post('metis_add_transaction_note', { tid: form.tid.value, note: note }).then(function(result) {
            if (!result || !result.success) {
                toast((result && result.data && result.data.message) || 'Failed to save note.', 'error');
                return;
            }
            document.getElementById('metis-transaction-notes-empty')?.remove();
            var list = document.getElementById('metis-transaction-notes-list');
            if (!list) {
                list = document.createElement('ul');
                list.className = 'metis-notes-list';
                list.id = 'metis-transaction-notes-list';
                form.insertAdjacentElement('afterend', list);
            }
            var row = result.data && result.data.note ? result.data.note : { note: note, display_name: 'You', created_at: 'Just now' };
            var li = document.createElement('li');
            li.className = 'metis-note-item';
            li.innerHTML = '<div class="metis-note-meta"><span class="metis-note-author">' + esc(row.display_name || 'You') + '</span><span class="metis-note-date metis-muted"> · Just now</span></div><div class="metis-note-body">' + esc(row.note).replace(/\n/g, '<br>') + '</div>';
            list.prepend(li);
            form.note.value = '';
            toast('Note saved.', 'success');
        }).catch(function() {
            toast('Request failed.', 'error');
        });
    });

    document.getElementById('metis-transaction-refund-form')?.addEventListener('submit', function(event) {
        event.preventDefault();
        var form = event.currentTarget;
        var amount = parseFloat(form.amount.value || '0');
        if (!(amount > 0)) return;

        post('metis_record_transaction_refund', {
            tid: form.tid.value,
            amount: form.amount.value,
            reason: form.reason.value || '',
            notes: form.notes.value || '',
            source: form.source.value || 'manual'
        }).then(function(result) {
            if (!result || !result.success) {
                toast((result && result.data && result.data.message) || 'Failed to record refund.', 'error');
                return;
            }
            var data = result.data || {};
            var refund = data.refund || {};
            document.getElementById('metis-transaction-refunds-empty')?.remove();
            var panel = document.querySelector('.metis-transaction-refunds');
            var header = document.getElementById('metis-refund-header-row');
            if (!header && panel) {
                header = document.createElement('div');
                header.className = 'metis-refund-header-row';
                header.id = 'metis-refund-header-row';
                header.innerHTML = '<div class="metis-ref-col">Amount</div><div class="metis-ref-col">Date</div><div class="metis-ref-col">By</div><div class="metis-ref-col">Source</div><div class="metis-ref-col">Reason</div>';
                form.insertAdjacentElement('afterend', header);
            }
            if (header) {
                var row = document.createElement('div');
                row.className = 'metis-refund-row';
                row.innerHTML = '<div class="metis-ref-col">$' + Number(refund.amount || amount).toFixed(2) + '</div><div class="metis-ref-col">Just now</div><div class="metis-ref-col">' + esc(refund.display_name || 'You') + '</div><div class="metis-ref-col">' + esc(refund.source || 'manual') + '</div><div class="metis-ref-col">' + esc(refund.reason || '-') + '</div>';
                header.insertAdjacentElement('afterend', row);
            }
            if (typeof data.total_refunded !== 'undefined') {
                document.getElementById('metis-total-refunded').textContent = '$' + Number(data.total_refunded || 0).toFixed(2);
            }
            if (typeof data.net_after_refunds !== 'undefined') {
                var net = document.getElementById('metis-net-after-refunds');
                net.hidden = false;
                net.textContent = 'Net after refunds: $' + Number(data.net_after_refunds || 0).toFixed(2);
            }
            form.reset();
            toast('Refund recorded.', 'success');
        }).catch(function() {
            toast('Request failed.', 'error');
        });
    });
})();
</script>
