<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );
$refunds_table      = Metis_Tables::get( 'transaction_refunds' );
$notes_table        = Metis_Tables::get( 'transaction_notes' );

$base_url = metis_donations_base_url();
$tid      = isset( $_GET['tid'] ) ? sanitize_text_field( $_GET['tid'] ) : '';

if ( $tid === '' ) : ?>
    <h1 class="mw-page-title">Transaction Not Found</h1>
    <p class="mw-subtitle">No Transaction ID was provided.</p>
    <a href="<?php echo esc_url( $base_url . '/transactions/' ); ?>" class="mw-btn mw-btn-xs">← Back</a>
    <?php return;
endif;

$transaction = $wpdb->get_row( $wpdb->prepare(
    "SELECT t.*, c.cname AS campaign_name,
            d.first_name, d.last_name, d.email, d.did AS donor_did
     FROM {$transactions_table} t
     LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
     LEFT JOIN {$contacts_table}  d ON d.did = t.did
     WHERE t.tid = %s
     LIMIT 1",
    $tid
) );

if ( ! $transaction ) : ?>
    <h1 class="mw-page-title">Transaction Not Found</h1>
    <p class="mw-subtitle">We couldn't match that transaction ID.</p>
    <a href="<?php echo esc_url( $base_url . '/transactions/' ); ?>" class="mw-btn mw-btn-xs">← Back</a>
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
$refunds = $wpdb->get_results( $wpdb->prepare(
    "SELECT r.*, u.display_name
     FROM {$refunds_table} r
     LEFT JOIN {$wpdb->users} u ON u.ID = r.created_by
     WHERE r.tid = %s ORDER BY r.created_at DESC",
    $tid
) );

$total_refunded_raw = array_sum( array_map( fn($r) => (float) $r->amount, $refunds ) );

// Notes
$notes = $wpdb->get_results( $wpdb->prepare(
    "SELECT n.*, u.display_name
     FROM {$notes_table} n
     LEFT JOIN {$wpdb->users} u ON u.ID = n.user_id
     WHERE n.tid = %s ORDER BY n.created_at DESC",
    $tid
) );
?>

<h1 class="mw-page-title">Transaction <?php echo esc_html( $transaction->tid ); ?></h1>
<p class="mw-subtitle">Detailed record for this donation.</p>
<p><a href="<?php echo esc_url( $donor_url ); ?>" class="mw-btn mw-btn-xs">← Back to Donor</a></p>

<!-- SUMMARY CARD -->
<div class="mw-premium-row mw-transaction-summary">
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Donor</div>
        <div class="large-number"><?php echo esc_html( $donor_name ?: '—' ); ?></div>
        <?php if ( $transaction->email ) : ?>
            <div class="mw-muted" style="font-size:13px;"><?php echo esc_html( $transaction->email ); ?></div>
        <?php endif; ?>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Campaign</div>
        <div class="large-number"><?php echo esc_html( $campaign ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Amount</div>
        <div class="large-number">$<?php echo number_format( $amount_raw, 2 ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Date</div>
        <div class="large-number"><?php echo esc_html( $display_date ); ?></div>
    </div>
</div>

<!-- DETAILS -->
<h2 class="mw-page-title mw-transaction-section-title">Details</h2>
<div class="mw-premium-row mw-transaction-details">
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Status</div>
        <div><?php echo metis_status_badge( $transaction->status ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Payment Method</div>
        <div><?php echo metis_paymethod_badge( $transaction->payment_method ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Deposit</div>
        <div>
            <?php echo metis_deposit_badge( $transaction->deposit_date ); ?>
            <?php if ( $batch_code ) : ?>
                <div style="margin-top:4px; font-size:13px;">
                    <span class="mw-muted">Batch:</span>
                    <a href="<?php echo esc_url( $batch_url ); ?>" class="mw-batch-link">
                        <?php echo esc_html( $batch_code ); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Platform</div>
        <div class="large-number"><?php echo esc_html( metis_platform_label( $transaction->platform ) ); ?></div>
    </div>
</div>

<!-- FINANCIAL SUMMARY -->
<h2 class="mw-page-title mw-transaction-section-title">Financial Summary</h2>
<div class="mw-premium-row mw-transaction-financial">
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Gross Amount</div>
        <div class="large-number">$<?php echo number_format( $gross_raw, 2 ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Fees</div>
        <div class="large-number"><?php echo $fee_raw > 0 ? '$' . number_format( $fee_raw, 2 ) : '—'; ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Net Payout</div>
        <div class="large-number"><?php echo $payout_raw > 0 ? '$' . number_format( $payout_raw, 2 ) : '—'; ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted small-label">Total Refunded</div>
        <div class="large-number">$<?php echo number_format( $total_refunded_raw, 2 ); ?></div>
        <?php if ( $total_refunded_raw > 0 ) : ?>
            <div class="mw-muted" style="font-size:13px;">
                Net after refunds: $<?php echo number_format( $amount_raw - $total_refunded_raw, 2 ); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- REFUND HISTORY -->
<h2 class="mw-page-title mw-transaction-section-title">Refund History</h2>
<div class="mw-premium-row mw-transaction-refunds">
    <?php if ( ! empty( $refunds ) ) : ?>
        <div class="mw-refund-header-row">
            <div class="mw-ref-col">Amount</div>
            <div class="mw-ref-col">Date</div>
            <div class="mw-ref-col">By</div>
            <div class="mw-ref-col">Source</div>
            <div class="mw-ref-col">Reason</div>
        </div>
        <?php foreach ( $refunds as $r ) : ?>
            <div class="mw-refund-row">
                <div class="mw-ref-col">$<?php echo number_format( (float) $r->amount, 2 ); ?></div>
                <div class="mw-ref-col"><?php echo $r->created_at ? esc_html( date( 'm/d/Y H:i', strtotime( $r->created_at ) ) ) : '—'; ?></div>
                <div class="mw-ref-col"><?php echo esc_html( $r->display_name ?: 'System' ); ?></div>
                <div class="mw-ref-col"><?php echo esc_html( ucfirst( $r->source ?: 'manual' ) ); ?></div>
                <div class="mw-ref-col"><?php echo esc_html( $r->reason ?: '—' ); ?></div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <p class="mw-muted">No refunds recorded for this transaction.</p>
    <?php endif; ?>
    <div class="mw-refund-actions" style="margin-top:12px;">
        <button type="button" class="mw-btn mw-btn-xs mw-btn-disabled" disabled>+ Issue Refund (coming soon)</button>
    </div>
</div>

<!-- NOTES -->
<h2 class="mw-page-title mw-transaction-section-title">Internal Notes</h2>
<div class="mw-premium-row mw-transaction-notes">
    <?php if ( ! empty( $notes ) ) : ?>
        <ul class="mw-notes-list">
            <?php foreach ( $notes as $n ) : ?>
                <li class="mw-note-item">
                    <div class="mw-note-meta">
                        <span class="mw-note-author"><?php echo esc_html( $n->display_name ?: 'System' ); ?></span>
                        <span class="mw-note-date mw-muted"> · <?php echo $n->created_at ? esc_html( date( 'm/d/Y H:i', strtotime( $n->created_at ) ) ) : '—'; ?></span>
                    </div>
                    <div class="mw-note-body"><?php echo nl2br( esc_html( $n->note ) ); ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="mw-muted">No notes yet for this transaction.</p>
    <?php endif; ?>
    <div class="mw-notes-actions" style="margin-top:12px;">
        <button type="button" class="mw-btn mw-btn-xs mw-btn-disabled" disabled>+ Add Note (coming soon)</button>
    </div>
</div>
