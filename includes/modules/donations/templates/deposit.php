<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$deposits_table     = Metis_Tables::get( 'deposits' );
$transactions_table = Metis_Tables::get( 'transactions' );
$contacts_table     = Metis_Tables::get( 'contacts' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );

$base_url     = metis_donations_base_url();
$deposit_code = sanitize_text_field( $_GET['id'] ?? '' );

if ( ! $deposit_code ) {
    echo '<p class="mw-muted">Invalid deposit.</p>';
    return;
}

$deposit = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM {$deposits_table} WHERE provider_ref = %s OR id = %s LIMIT 1",
    $deposit_code, $deposit_code
) );

if ( ! $deposit ) {
    echo '<p class="mw-muted">Deposit not found.</p>';
    return;
}

// ── Transactions ──────────────────────────────────────────────────────────
$transactions = $wpdb->get_results( $wpdb->prepare(
    "SELECT t.*,
            TRIM( CONCAT( IFNULL(c.first_name,''), ' ', IFNULL(c.last_name,'') ) ) AS donor_name,
            c.email AS donor_email,
            camp.cname AS campaign_name
     FROM {$transactions_table} t
     LEFT JOIN {$contacts_table}  c    ON c.did  = t.did
     LEFT JOIN {$campaigns_table} camp ON camp.cid = t.campaign_code
     WHERE t.deposit_batch_id = %s
     ORDER BY t.tran_date ASC",
    $deposit->provider_ref
) );

// ── Adjustments from deposit meta ─────────────────────────────────────────
$meta            = is_string( $deposit->meta ) ? ( json_decode( $deposit->meta, true ) ?: [] ) : [];
$payout_id       = $meta['payout_id'] ?? '';
$all_adjustments = $meta['adjustments'] ?? [];

// stripe_fee / application_fee → KPI card + table row above totals
// refund / payment             → suppressed (in transaction rows)
// everything else              → detail rows after Deposited
$stripe_adj_fee_total = 0.0;
$detail_adjustments   = [];

foreach ( $all_adjustments as $adj ) {
    $type      = $adj['type'] ?? 'adjustment';
    $amt_cents = (int) ( $adj['amount_cents'] ?? 0 );
    if ( in_array( $type, [ 'stripe_fee', 'application_fee' ], true ) ) {
        $stripe_adj_fee_total += abs( $amt_cents / 100.0 );
    } elseif ( in_array( $type, [ 'refund', 'payment' ], true ) ) {
        continue;
    } else {
        $detail_adjustments[] = $adj;
    }
}

// ── KPI values ───────────────────────────────────────────────────────────
//
// After the corrected backfill, stored values represent:
//   gross_total = sum of charge/payment btxn gross amounts (what donors paid)
//   fee_total   = sum of processing fees on charges
//   net_total   = Stripe-authoritative payout amount
//
// Refunds and Stripe billing fees are deductions shown as separate KPI cards,
// derived from the meta adjustments already parsed above.
//
// Full formula: Gross − Fees [− Stripe Fees] [− Refunded] = Net Deposited
//
// Refunded amount: derived from meta refund adjustments (abs of amount_cents).

$kpi_gross    = ! empty( $deposit->gross_total ) ? (float) $deposit->gross_total : null;
$kpi_fees     = ! empty( $deposit->fee_total )   ? (float) $deposit->fee_total   : null;
$kpi_net      = ! empty( $deposit->net_total )   ? (float) $deposit->net_total   : null;

// Sum refund adjustments from meta for the Refunded KPI card
$kpi_refunded = 0.0;
foreach ( $all_adjustments as $adj ) {
    if ( ( $adj['type'] ?? '' ) === 'refund' ) {
        $kpi_refunded += abs( (int) ( $adj['amount_cents'] ?? 0 ) ) / 100.0;
    }
}
$kpi_refunded = round( $kpi_refunded, 2 );

// ── Display helpers ───────────────────────────────────────────────────────
$deposit_date = $deposit->deposit_date ? date( 'F j, Y', strtotime( $deposit->deposit_date ) ) : '—';
$tx_count     = count( $transactions );
metis_set_page_title( $deposit->provider_ref );

$fmt = function( $v ) {
    if ( $v === null ) return '—';
    return '$' . number_format( abs( (float) $v ), 2 );
};

$fmt_signed = function( $v ) {
    if ( $v === null ) return '—';
    $v = (float) $v;
    return ( $v < 0 ? '-' : '' ) . '$' . number_format( abs( $v ), 2 );
};

// Fees always render as -$x.xx; zero/null = —
$fmt_fee = function( $v ) {
    if ( $v === null || abs( (float) $v ) < 0.005 ) return '—';
    return '-$' . number_format( abs( (float) $v ), 2 );
};

$adj_type_labels = [
    'payout_failure'             => 'Payout Failure',
    'adjustment'                 => 'Adjustment',
    'transfer'                   => 'Transfer',
    'transfer_reversal'          => 'Transfer Reversal',
    'dispute'                    => 'Dispute',
    'dispute_reversal'           => 'Dispute Reversal',
    'issuing_authorization_hold' => 'Issuing Hold',
];
?>

<h1 class="mw-page-title">Deposit <?php echo esc_html( $deposit->provider_ref ); ?></h1>
<p class="mw-subtitle">Funds deposited to bank on <?php echo esc_html( $deposit_date ); ?></p>

<!-- ── KPI CARDS ─────────────────────────────────────────────────────────
     Formula: Gross − Fees [− Stripe Fees] [− Refunded] = Net Deposited
     All values come from stored deposit totals (Stripe-authoritative).
     Refunded is derived so the equation always balances exactly.
──────────────────────────────────────────────────────────────────────── -->
<div class="mw-kpi-row" style="display:flex; gap:0; flex-wrap:wrap; margin:20px 0 28px; align-items:stretch;">

    <!-- Gross -->
    <div class="mw-kpi-card" style="flex:1; min-width:120px; background:#fff; border:1px solid #dde0ed; border-radius:8px 0 0 8px; padding:16px 20px;">
        <div class="mw-muted" style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px;">Gross</div>
        <div style="font-size:22px; font-weight:700; color:#1f2330;"><?php echo esc_html( $fmt( $kpi_gross ) ); ?></div>
        <div class="mw-muted" style="font-size:11px; margin-top:5px;"><?php echo $tx_count; ?> transaction<?php echo $tx_count !== 1 ? 's' : ''; ?></div>
    </div>

    <div style="display:flex; align-items:center; padding:0 8px; color:#9ca3af; font-size:18px; font-weight:300;">&#8722;</div>

    <!-- Processing Fees -->
    <div class="mw-kpi-card" style="flex:1; min-width:120px; background:#fff; border:1px solid #dde0ed; border-radius:0; padding:16px 20px;">
        <div class="mw-muted" style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px;">Fees</div>
        <div style="font-size:22px; font-weight:700; color:#b91c1c;"><?php echo esc_html( $fmt( $kpi_fees ) ); ?></div>
        <div class="mw-muted" style="font-size:11px; margin-top:5px;">Processing</div>
    </div>

    <?php if ( $kpi_refunded > 0 ) : ?>
    <div style="display:flex; align-items:center; padding:0 8px; color:#9ca3af; font-size:18px; font-weight:300;">&#8722;</div>
    <div class="mw-kpi-card" style="flex:1; min-width:120px; background:#fff8f0; border:1px solid #d97706; border-radius:0; padding:16px 20px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; color:#92681a;">Refunded</div>
        <div style="font-size:22px; font-weight:700; color:#b45309;"><?php echo esc_html( $fmt( $kpi_refunded ) ); ?></div>
        <div style="font-size:11px; margin-top:5px; color:#b45309;">Returned from balance</div>
    </div>
    <?php endif; ?>

    <div style="display:flex; align-items:center; padding:0 8px; color:#9ca3af; font-size:18px; font-weight:300;">=</div>

    <!-- Net Deposited -->
    <div class="mw-kpi-card" style="flex:1; min-width:120px; background:#f0f2fd; border:1px solid #485bc7; border-radius:0 8px 8px 0; padding:16px 20px;">
        <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; color:#485bc7;">Net Deposited</div>
        <div style="font-size:22px; font-weight:700; color:#485bc7;"><?php echo esc_html( $fmt( $kpi_net ) ); ?></div>
        <div style="font-size:11px; margin-top:5px; color:#485bc7;">To bank</div>
    </div>

</div>

<!-- DEPOSIT META ────────────────────────────────────────────────────────── -->
<div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom:28px; align-items:flex-start;">

    <div style="flex:1; min-width:160px; background:#f5f6fa; border-radius:8px; padding:14px 18px;">
        <div class="mw-muted" style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px;">Provider</div>
        <div style="font-weight:600;"><?php echo esc_html( ucfirst( $deposit->provider ?? '' ) ); ?></div>
    </div>

    <div style="flex:1; min-width:160px; background:#f5f6fa; border-radius:8px; padding:14px 18px;">
        <div class="mw-muted" style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px;">Source</div>
        <div><?php echo metis_deposit_source_badge( $deposit ); ?></div>
    </div>

    <div style="flex:1; min-width:160px; background:#f5f6fa; border-radius:8px; padding:14px 18px;">
        <div class="mw-muted" style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px;">Status</div>
        <div><?php echo metis_status_badge( $deposit->status ); ?></div>
    </div>

    <?php if ( $payout_id ) : ?>
    <div style="flex:2; min-width:220px; background:#f5f6fa; border-radius:8px; padding:14px 18px;">
        <div class="mw-muted" style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px;">Stripe Payout ID</div>
        <div style="font-family:monospace; font-size:13px; color:#485bc7;"><?php echo esc_html( $payout_id ); ?></div>
    </div>
    <?php endif; ?>

</div>

<!-- ── TRANSACTIONS TABLE ────────────────────────────────────────────────
     Transaction rows are for detail/audit. The table totals are separate
     from the KPI cards (which use authoritative deposit-level values).
     
     Refund display behavior (per Stripe's own accounting):
       Gross  = original charge gross (what was collected from donor)
       Fee    = original fee (Stripe keeps it — not returned on refund)
       Net    = NEGATIVE of original charge net (balance deduction shown)
     
     This means the Net column totals naturally show what remained in
     your Stripe balance after refunds, before Stripe billing fees.
──────────────────────────────────────────────────────────────────────── -->
<h2 class="mw-section-title">Transactions</h2>

<div class="mw-premium-table mw-deposit-tx-table">

    <div class="mw-premium-row mw-premium-header">
        <div class="mw-premium-cell">Date</div>
        <div class="mw-premium-cell">Donor</div>
        <div class="mw-premium-cell">Campaign</div>
        <div class="mw-premium-cell">Platform</div>
        <div class="mw-premium-cell mw-col-numeric">Gross</div>
        <div class="mw-premium-cell mw-col-numeric">Fee</div>
        <div class="mw-premium-cell mw-col-numeric">Net</div>
        <div class="mw-premium-cell">Status</div>
    </div>

    <?php if ( ! empty( $transactions ) ) :
        $tbl_gross = 0.0; // sum of all charge gross (always positive)
        $tbl_fees  = 0.0; // sum of all fees (always positive — Stripe keeps on refunds too)
        $tbl_net   = 0.0; // signed: completed adds, refunded subtracts

        foreach ( $transactions as $tx ) :
            $is_refunded    = strtolower( $tx->status ?? '' ) === 'refunded';
            $date           = $tx->tran_date ? date( 'm/d/y', strtotime( $tx->tran_date ) ) : '—';
            $tx_gross_raw   = (float) $tx->amount + (float) ( $tx->fee ?? 0 );
            $tx_fee_raw     = (float) ( $tx->fee ?? 0 );
            $tx_net_raw     = (float) $tx->amount;

            // Display columns
            $display_gross  = $tx_gross_raw;                           // always positive
            $display_fee    = $tx_fee_raw;                             // always positive (kept by Stripe)
            $display_net    = $is_refunded ? -$tx_net_raw : $tx_net_raw; // negative for refunded

            // Running totals
            $tbl_gross += $display_gross;
            $tbl_fees  += $display_fee;
            $tbl_net   += $display_net;

            $tx_url      = $base_url . '/transaction/?tid=' . urlencode( $tx->tid );
            $donor_label = trim( $tx->donor_name ?? '' ) ?: ( $tx->donor_email ?? '' ) ?: '—';
            $camp_label  = $tx->campaign_name ?: ( $tx->campaign_code ?: '—' );
            $row_opacity = $is_refunded ? 'opacity:0.72;' : '';
            $net_color   = $is_refunded ? '#b91c1c' : 'inherit';
    ?>
        <div class="mw-premium-row mw-transaction-row mw-clickable-row"
             data-href="<?php echo esc_url( $tx_url ); ?>"
             <?php if ( $is_refunded ) echo 'style="opacity:0.72;"'; ?>>
            <div class="mw-premium-cell"><?php echo esc_html( $date ); ?></div>
            <div class="mw-premium-cell">
                <?php echo esc_html( $donor_label ); ?>
                <?php if ( $tx->did ) : ?>
                    <div class="mw-muted" style="font-size:11px;"><?php echo esc_html( $tx->did ); ?></div>
                <?php endif; ?>
            </div>
            <div class="mw-premium-cell"><?php echo esc_html( $camp_label ); ?></div>
            <div class="mw-premium-cell"><?php echo esc_html( metis_platform_label( $tx->platform ?? '' ) ); ?></div>
            <div class="mw-premium-cell mw-col-numeric"><?php echo $fmt( $display_gross ); ?></div>
            <div class="mw-premium-cell mw-col-numeric" style="color:#b91c1c;"><?php echo $fmt_fee( $display_fee ); ?></div>
            <div class="mw-premium-cell mw-col-numeric" style="font-weight:600; color:<?php echo $net_color; ?>;"><?php echo $fmt_signed( $display_net ); ?></div>
            <div class="mw-premium-cell">
                <?php echo metis_status_badge( $tx->status ); ?>
                <?php echo metis_paymethod_badge( $tx->payment_method ); ?>
            </div>
        </div>
    <?php endforeach; ?>

        <?php if ( $stripe_adj_fee_total > 0 ) : ?>
        <!-- Stripe billing/account fees -->
        <div class="mw-premium-row" style="background:#f9fafb; border-top:1px dashed #dde0ed;">
            <div class="mw-premium-cell" style="color:#6d7485; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px;">Stripe Fees</div>
            <div class="mw-premium-cell" style="color:#9ca3af; font-size:12px; grid-column:span 3;">Billing &amp; account fees</div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell mw-col-numeric" style="color:#b91c1c; font-weight:600;"><?php echo '-' . $fmt( $stripe_adj_fee_total ); ?></div>
            <div class="mw-premium-cell"></div>
        </div>
        <?php endif; ?>

        <!-- TOTALS ROW -->
        <?php $tbl_net_after_fees = $tbl_net - $stripe_adj_fee_total; ?>
        <div class="mw-premium-row" style="background:#f0f1f8; font-weight:700; border-top:2px solid #c7cae8;">
            <div class="mw-premium-cell" style="color:#6d7485; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px;">Totals</div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell mw-col-numeric"><?php echo $fmt( $tbl_gross ); ?></div>
            <div class="mw-premium-cell mw-col-numeric" style="color:#b91c1c;"><?php echo $fmt_fee( $tbl_fees ); ?></div>
            <div class="mw-premium-cell mw-col-numeric" style="color:#485bc7;"><?php echo $fmt_signed( $tbl_net_after_fees ); ?></div>
            <div class="mw-premium-cell"></div>
        </div>

        <!-- DEPOSITED ROW -->
        <div class="mw-premium-row" style="background:#eef0fb; font-weight:700; border-top:1px solid #c7cae8;">
            <div class="mw-premium-cell" style="color:#485bc7; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px;">Deposited</div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell mw-col-numeric"></div>
            <div class="mw-premium-cell mw-col-numeric"></div>
            <div class="mw-premium-cell mw-col-numeric" style="color:#485bc7; font-size:15px;"><?php echo $fmt( $kpi_net ); ?></div>
            <div class="mw-premium-cell"></div>
        </div>

        <?php if ( ! empty( $detail_adjustments ) ) :
            foreach ( $detail_adjustments as $adj ) :
                $adj_cents   = (int) ( $adj['amount_cents'] ?? 0 );
                $adj_amt     = $adj_cents / 100.0;
                $adj_type    = $adj['type'] ?? 'adjustment';
                $adj_label   = $adj_type_labels[ $adj_type ] ?? ucwords( str_replace( '_', ' ', $adj_type ) );
                $adj_desc    = $adj['description'] ?? '';
                $is_neg      = $adj_cents < 0;
                $adj_color   = $is_neg ? '#b91c1c' : '#15803d';
                $adj_display = ( $is_neg ? '-' : '+' ) . '$' . number_format( abs( $adj_amt ), 2 );
        ?>
        <div class="mw-premium-row" style="background:#fff8f0; border-top:1px dashed #e0c090;">
            <div style="color:#92681a; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px;"><?php echo esc_html( $adj_label ); ?></div>
            <div style="color:#92681a; font-size:12px; grid-column:span 3;"><?php echo esc_html( $adj_desc ); ?></div>
            <div></div>
            <div></div>
            <div class="mw-col-numeric" style="color:<?php echo $adj_color; ?>; font-weight:600;"><?php echo $adj_display; ?></div>
            <div></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

    <?php else : ?>
        <div class="mw-premium-row">
            <div class="mw-premium-cell mw-muted">No transactions linked to this deposit.</div>
        </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('click', function (e) {
    const row = e.target.closest('.mw-clickable-row');
    if (row && row.dataset.href) window.location.href = row.dataset.href;
});
</script>
