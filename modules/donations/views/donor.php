<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );

$base_url = metis_donations_base_url();
$donor_id = isset( $_GET['id'] ) ? metis_text_clean( $_GET['id'] ) : '';

if ( $donor_id === '' ) : ?>
    <h1 class="mw-page-title">Donor not found</h1>
    <p class="mw-subtitle">No donor ID was provided.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/donors/' ); ?>" class="mw-btn mw-btn-xs">← Back to Donors</a>
    <?php return;
endif;

$donor = $db->fetchOne( "SELECT first_name, last_name, email, did FROM {$contacts_table} WHERE did = %s", [ $donor_id ] );
$donor = $donor ? (object) $donor : null;

if ( ! $donor ) : ?>
    <h1 class="mw-page-title">Donor not found</h1>
    <p class="mw-subtitle">We couldn't find a donor with that ID.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/donors/' ); ?>" class="mw-btn mw-btn-xs">← Back to Donors</a>
    <?php return;
endif;

$transactions = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "SELECT t.*, c.cname AS campaign_name
     FROM {$transactions_table} t
     LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
     WHERE t.did = %s
     ORDER BY t.tran_date DESC, t.id DESC",
    [ $donor->did ]
) ?: [] );

$total_gross = 0;
foreach ( $transactions as $t ) {
    $total_gross += (float) $t->amount + (float) ( $t->fee ?? 0 );
}
$total_net    = array_sum( array_map( fn($t) => (float) $t->amount, $transactions ) );
$gift_count   = count( $transactions );
$full_name    = trim( $donor->first_name . ' ' . $donor->last_name );
metis_set_page_title( $full_name ?: $donor->email ?: $donor_id );
?>

<h1 class="mw-page-title"><?php echo metis_escape_html( $full_name ?: $donor->email ?: 'Donor' ); ?></h1>
<p class="mw-subtitle">Detailed giving history for this donor.</p>
<p><a href="<?php echo metis_escape_url( $base_url . '/donors/' ); ?>" class="mw-btn mw-btn-xs">← Back to Donors</a></p>

<!-- SUMMARY CARD -->
<div class="mw-premium-row mw-donor-summary">
    <div class="mw-premium-cell">
        <div class="mw-muted mw-label">Name</div>
        <div class="mw-value"><?php echo metis_escape_html( $full_name ?: '—' ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted mw-label">Email</div>
        <div class="mw-value"><?php echo metis_escape_html( $donor->email ?: '—' ); ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted mw-label">Total Net</div>
        <div class="mw-value mw-value--primary"><?php echo $total_net > 0 ? '$' . number_format( $total_net, 2 ) : '—'; ?></div>
    </div>
    <div class="mw-premium-cell">
        <div class="mw-muted mw-label">Gifts</div>
        <div class="mw-value"><?php echo $gift_count; ?></div>
    </div>
</div>

<!-- GIVING HISTORY -->
<h2 class="mw-section-title">Giving History</h2>

<div class="mw-premium-table mw-donor-table mw-donor-view">

    <div class="mw-premium-header mw-donor-tx-row">
        <div class="mw-premium-cell mw-sort-btn" data-sort="date">Date</div>
        <div class="mw-premium-cell mw-sort-btn" data-sort="campaign">Campaign</div>
        <div class="mw-premium-cell">Platform</div>
        <div class="mw-premium-cell mw-col-numeric mw-sort-btn" data-sort="amount">Amount</div>
        <div class="mw-premium-cell">Status / Type</div>
    </div>

    <div id="mw-donor-tx-rows">
    <?php if ( ! empty( $transactions ) ) : ?>
        <?php foreach ( $transactions as $t ) :
            $timestamp    = $t->tran_date ? strtotime( $t->tran_date ) : 0;
            $display_date = $timestamp ? date( 'm/d/y', $timestamp ) : '—';
            $iso_date     = $timestamp ? date( 'Y-m-d', $timestamp ) : '';
            $campaign     = $t->campaign_name ?: $t->campaign_code ?: '—';
            $amount       = (float) $t->amount;
            $tx_url       = $base_url . '/transaction/?tid=' . urlencode( $t->tid );
        ?>
            <div class="mw-premium-row mw-donor-tx-row"
                 data-href="<?php echo metis_escape_url( $tx_url ); ?>"
                 data-date="<?php echo metis_escape_attr( $iso_date ); ?>"
                 data-campaign="<?php echo metis_escape_attr( strtolower( $campaign ) ); ?>"
                 data-amount="<?php echo metis_escape_attr( $amount ); ?>"
                 tabindex="0">
                <div class="mw-premium-cell"><?php echo metis_escape_html( $display_date ); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html( $campaign ); ?></div>
                <div class="mw-premium-cell"><?php echo metis_escape_html( metis_platform_label( $t->platform ) ); ?></div>
                <div class="mw-premium-cell mw-col-numeric">$<?php echo number_format( $amount, 2 ); ?></div>
                <div class="mw-premium-cell mw-tx-flags">
                    <?php echo metis_status_badge( $t->status ); ?>
                    <?php echo metis_paymethod_badge( $t->payment_method ); ?>
                    <?php echo metis_deposit_badge( $t->deposit_date ); ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <div class="mw-premium-row">
            <div class="mw-muted">No giving history recorded for this donor.</div>
        </div>
    <?php endif; ?>
    </div>

</div>

<!-- PAGINATION -->
<div class="mw-pagination">
    <button id="mw-donor-prev" class="mw-btn mw-btn-xs mw-btn-ghost">← Prev</button>
    <span id="mw-donor-page-status" class="mw-muted">Page 1 of 1</span>
    <button id="mw-donor-next" class="mw-btn mw-btn-xs mw-btn-ghost">Next →</button>
</div>
