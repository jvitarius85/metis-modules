<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );
$refunds_table      = Metis_Tables::get( 'transaction_refunds' );
$notes_table        = Metis_Tables::get( 'transaction_notes' );

$base_url = metis_donations_base_url();
$tid      = isset( metis_request_get()['tid'] ) ? metis_text_clean( metis_request_get()['tid'] ) : '';

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
$donor_url    = $base_url . '/donor/?id=' . urlencode( $transaction->did ?: $transaction->donor_did );

$batch_code = ! empty( $transaction->deposit_batch_id ) ? $transaction->deposit_batch_id : '';
$batch_url  = $batch_code ? $base_url . '/batch/?batch=' . urlencode( $batch_code ) : '';

// Refunds
$auth_users_table = Metis_Tables::get( 'auth_users' );
$refunds = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "SELECT r.*, u.display_name
     FROM {$refunds_table} r
     LEFT JOIN {$auth_users_table} u ON u.id = r.created_by
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
        <div class="large-number">$<?php echo number_format( $total_refunded_raw, 2 ); ?></div>
        <?php if ( $total_refunded_raw > 0 ) : ?>
            <div class="metis-muted metis-transaction-meta-text">
                Net after refunds: $<?php echo number_format( $amount_raw - $total_refunded_raw, 2 ); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- REFUND HISTORY -->
<h2 class="metis-page-title metis-transaction-section-title">Refund History</h2>
<div class="metis-detail-panel metis-transaction-refunds">
    <?php if ( ! empty( $refunds ) ) : ?>
        <div class="metis-refund-header-row">
            <div class="metis-ref-col">Amount</div>
            <div class="metis-ref-col">Date</div>
            <div class="metis-ref-col">By</div>
            <div class="metis-ref-col">Source</div>
            <div class="metis-ref-col">Reason</div>
        </div>
        <?php foreach ( $refunds as $r ) : ?>
            <div class="metis-refund-row">
                <div class="metis-ref-col">$<?php echo number_format( (float) $r->amount, 2 ); ?></div>
                <div class="metis-ref-col"><?php echo $r->created_at ? metis_escape_html( date( 'm/d/Y H:i', strtotime( $r->created_at ) ) ) : '—'; ?></div>
                <div class="metis-ref-col"><?php echo metis_escape_html( $r->display_name ?: 'System' ); ?></div>
                <div class="metis-ref-col"><?php echo metis_escape_html( ucfirst( $r->source ?: 'manual' ) ); ?></div>
                <div class="metis-ref-col"><?php echo metis_escape_html( $r->reason ?: '—' ); ?></div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <p class="metis-muted">No refunds recorded for this transaction.</p>
    <?php endif; ?>
    <div class="metis-refund-actions metis-transaction-actions-row">
        <button type="button" class="metis-btn metis-btn-xs metis-btn-disabled" disabled>+ Issue Refund (coming soon)</button>
    </div>
</div>

<!-- NOTES -->
<h2 class="metis-page-title metis-transaction-section-title">Internal Notes</h2>
<div class="metis-detail-panel metis-transaction-notes">
    <?php if ( ! empty( $notes ) ) : ?>
        <ul class="metis-notes-list">
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
        <p class="metis-muted">No notes yet for this transaction.</p>
    <?php endif; ?>
    <div class="metis-notes-actions metis-transaction-actions-row">
        <button type="button" class="metis-btn metis-btn-xs metis-btn-disabled" disabled>+ Add Note (coming soon)</button>
    </div>
</div>
