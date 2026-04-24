<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$deposits_table     = Metis_Tables::get( 'deposits' );
$transactions_table = Metis_Tables::get( 'transactions' );
$contacts_table     = Metis_Tables::get( 'contacts' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );

$base_url     = metis_donations_base_url();
$deposit_code = metis_text_clean( $_GET['id'] ?? '' );

if ( ! $deposit_code ) {
    echo '<p class="mw-muted">Invalid deposit.</p>';
    return;
}

$deposit = $db->fetchOne(
    "SELECT * FROM {$deposits_table} WHERE provider_ref = %s OR id = %s LIMIT 1",
    [ $deposit_code, $deposit_code ]
);
$deposit = $deposit ? (object) $deposit : null;

if ( ! $deposit ) {
    echo '<p class="mw-muted">Deposit not found.</p>';
    return;
}

// ── Transactions ──────────────────────────────────────────────────────────
$transactions = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "SELECT t.*,
            TRIM( CONCAT( IFNULL(c.first_name,''), ' ', IFNULL(c.last_name,'') ) ) AS donor_name,
            c.email AS donor_email,
            camp.cname AS campaign_name
     FROM {$transactions_table} t
     LEFT JOIN {$contacts_table}  c    ON c.did  = t.did
     LEFT JOIN {$campaigns_table} camp ON camp.cid = t.campaign_code
     WHERE t.deposit_batch_id = %s
     ORDER BY t.tran_date ASC",
    [ $deposit->provider_ref ]
) ?: [] );

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

<h1 class="mw-page-title">Deposit <?php echo metis_escape_html( $deposit->provider_ref ); ?></h1>
<p class="mw-subtitle">Funds deposited to bank on <?php echo metis_escape_html( $deposit_date ); ?></p>

<!-- ── KPI CARDS ─────────────────────────────────────────────────────────
     Formula: Gross − Fees [− Stripe Fees] [− Refunded] = Net Deposited
     All values come from stored deposit totals (Stripe-authoritative).
     Refunded is derived so the equation always balances exactly.
──────────────────────────────────────────────────────────────────────── -->
<div class="mw-kpi-row mw-deposit-kpi-row">

    <!-- Gross -->
    <div class="mw-kpi-card mw-deposit-kpi-card mw-deposit-kpi-card--gross">
        <div class="mw-muted mw-deposit-kpi-label">Gross</div>
        <div class="mw-deposit-kpi-value"><?php echo metis_escape_html( $fmt( $kpi_gross ) ); ?></div>
        <div class="mw-muted mw-deposit-kpi-note"><?php echo $tx_count; ?> transaction<?php echo $tx_count !== 1 ? 's' : ''; ?></div>
    </div>

    <div class="mw-deposit-kpi-op">&minus;</div>

    <!-- Processing Fees -->
    <div class="mw-kpi-card mw-deposit-kpi-card mw-deposit-kpi-card--fees">
        <div class="mw-muted mw-deposit-kpi-label">Fees</div>
        <div class="mw-deposit-kpi-value mw-deposit-kpi-value--fees"><?php echo metis_escape_html( $fmt( $kpi_fees ) ); ?></div>
        <div class="mw-muted mw-deposit-kpi-note">Processing</div>
    </div>

    <?php if ( $kpi_refunded > 0 ) : ?>
    <div class="mw-deposit-kpi-op">&minus;</div>
    <div class="mw-kpi-card mw-deposit-kpi-card mw-deposit-kpi-card--refunded">
        <div class="mw-deposit-kpi-label mw-deposit-kpi-label--refunded">Refunded</div>
        <div class="mw-deposit-kpi-value mw-deposit-kpi-value--refunded"><?php echo metis_escape_html( $fmt( $kpi_refunded ) ); ?></div>
        <div class="mw-deposit-kpi-note mw-deposit-kpi-note--refunded">Returned from balance</div>
    </div>
    <?php endif; ?>

    <div class="mw-deposit-kpi-op">=</div>

    <!-- Net Deposited -->
    <div class="mw-kpi-card mw-deposit-kpi-card mw-deposit-kpi-card--net">
        <div class="mw-deposit-kpi-label mw-deposit-kpi-label--net">Net Deposited</div>
        <div class="mw-deposit-kpi-value mw-deposit-kpi-value--net"><?php echo metis_escape_html( $fmt( $kpi_net ) ); ?></div>
        <div class="mw-deposit-kpi-note mw-deposit-kpi-note--net">To bank</div>
    </div>

</div>

<!-- DEPOSIT META ────────────────────────────────────────────────────────── -->
<div class="mw-deposit-meta-row">

    <div class="mw-deposit-meta-card">
        <div class="mw-muted mw-deposit-meta-label">Provider</div>
        <div class="mw-deposit-meta-value"><?php echo metis_escape_html( ucfirst( $deposit->provider ?? '' ) ); ?></div>
    </div>

    <div class="mw-deposit-meta-card">
        <div class="mw-muted mw-deposit-meta-label">Source</div>
        <div><?php echo metis_deposit_source_badge( $deposit ); ?></div>
    </div>

    <div class="mw-deposit-meta-card">
        <div class="mw-muted mw-deposit-meta-label">Status</div>
        <div><?php echo metis_status_badge( $deposit->status ); ?></div>
    </div>

    <?php if ( $payout_id ) : ?>
    <div class="mw-deposit-meta-card mw-deposit-meta-card--wide">
        <div class="mw-muted mw-deposit-meta-label">Stripe Payout ID</div>
        <div class="mw-deposit-meta-payout-id"><?php echo metis_escape_html( $payout_id ); ?></div>
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
            $row_class   = $is_refunded ? ' mw-transaction-row--refunded' : '';
            $net_class   = $is_refunded ? ' mw-deposit-net-negative' : ' mw-deposit-net-default';
    ?>
        <div class="mw-premium-row mw-transaction-row mw-clickable-row<?php echo metis_escape_attr( $row_class ); ?>"
             data-href="<?php echo metis_escape_url( $tx_url ); ?>">
            <div class="mw-premium-cell"><?php echo metis_escape_html( $date ); ?></div>
            <div class="mw-premium-cell">
                <?php echo metis_escape_html( $donor_label ); ?>
                <?php if ( $tx->did ) : ?>
                    <div class="mw-muted mw-deposit-tx-did"><?php echo metis_escape_html( $tx->did ); ?></div>
                <?php endif; ?>
            </div>
            <div class="mw-premium-cell"><?php echo metis_escape_html( $camp_label ); ?></div>
            <div class="mw-premium-cell"><?php echo metis_escape_html( metis_platform_label( $tx->platform ?? '' ) ); ?></div>
            <div class="mw-premium-cell mw-col-numeric"><?php echo $fmt( $display_gross ); ?></div>
            <div class="mw-premium-cell mw-col-numeric mw-deposit-fee-cell"><?php echo $fmt_fee( $display_fee ); ?></div>
            <div class="mw-premium-cell mw-col-numeric mw-deposit-net-cell<?php echo metis_escape_attr( $net_class ); ?>"><?php echo $fmt_signed( $display_net ); ?></div>
            <div class="mw-premium-cell">
                <?php echo metis_status_badge( $tx->status ); ?>
                <?php echo metis_paymethod_badge( $tx->payment_method ); ?>
            </div>
        </div>
    <?php endforeach; ?>

        <?php if ( $stripe_adj_fee_total > 0 ) : ?>
        <!-- Stripe billing/account fees -->
        <div class="mw-premium-row mw-deposit-row-stripe-fees">
            <div class="mw-premium-cell mw-deposit-row-label">Stripe Fees</div>
            <div class="mw-premium-cell mw-deposit-row-detail">Billing &amp; account fees</div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell mw-col-numeric mw-deposit-fee-cell mw-deposit-amount-strong"><?php echo '-' . $fmt( $stripe_adj_fee_total ); ?></div>
            <div class="mw-premium-cell"></div>
        </div>
        <?php endif; ?>

        <!-- TOTALS ROW -->
        <?php $tbl_net_after_fees = $tbl_net - $stripe_adj_fee_total; ?>
        <div class="mw-premium-row mw-deposit-row-totals">
            <div class="mw-premium-cell mw-deposit-row-label">Totals</div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell mw-col-numeric"><?php echo $fmt( $tbl_gross ); ?></div>
            <div class="mw-premium-cell mw-col-numeric mw-deposit-fee-cell"><?php echo $fmt_fee( $tbl_fees ); ?></div>
            <div class="mw-premium-cell mw-col-numeric mw-deposit-net-accent"><?php echo $fmt_signed( $tbl_net_after_fees ); ?></div>
            <div class="mw-premium-cell"></div>
        </div>

        <!-- DEPOSITED ROW -->
        <div class="mw-premium-row mw-deposit-row-deposited">
            <div class="mw-premium-cell mw-deposit-row-label mw-deposit-row-label--accent">Deposited</div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell mw-col-numeric"></div>
            <div class="mw-premium-cell mw-col-numeric"></div>
            <div class="mw-premium-cell mw-col-numeric mw-deposit-net-accent mw-deposit-net-accent-lg"><?php echo $fmt( $kpi_net ); ?></div>
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
                $adj_class   = $is_neg ? 'mw-deposit-amount-negative' : 'mw-deposit-amount-positive';
                $adj_display = ( $is_neg ? '-' : '+' ) . '$' . number_format( abs( $adj_amt ), 2 );
        ?>
        <div class="mw-premium-row mw-deposit-row-adjustment">
            <div class="mw-premium-cell mw-deposit-row-label mw-deposit-row-label--adjustment"><?php echo metis_escape_html( $adj_label ); ?></div>
            <div class="mw-premium-cell mw-deposit-row-detail mw-deposit-row-detail--adjustment"><?php echo metis_escape_html( $adj_desc ); ?></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell"></div>
            <div class="mw-premium-cell mw-col-numeric mw-deposit-amount-strong <?php echo metis_escape_attr( $adj_class ); ?>"><?php echo metis_escape_html( $adj_display ); ?></div>
            <div class="mw-premium-cell"></div>
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
    if (row && row.dataset.href) {
        if (window.Metis && Metis.navigation && typeof Metis.navigation.go === 'function') {
            Metis.navigation.go(row.dataset.href);
            return;
        }
        window.location.assign(row.dataset.href);
    }
});
</script>
