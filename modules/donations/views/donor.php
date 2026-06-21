<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$base_url = metis_donations_base_url();
$donor_id = metis_donations_request_identifier( 'id', 'donor' );

if ( $donor_id === '' ) : ?>
    <h1 class="metis-page-title">Donor not found</h1>
    <p class="metis-subtitle">No donor ID was provided.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/donors/' ); ?>" class="metis-btn metis-btn-xs">← Back to Donors</a>
    <?php return;
endif;
$snapshot = \Metis\Modules\Donations\ReadService::donorDetailSnapshot( $donor_id );
$donor = $snapshot['donor'] ?? null;

if ( ! $donor ) : ?>
    <h1 class="metis-page-title">Donor not found</h1>
    <p class="metis-subtitle">We couldn't find a donor with that ID.</p>
    <a href="<?php echo metis_escape_url( $base_url . '/donors/' ); ?>" class="metis-btn metis-btn-xs">← Back to Donors</a>
    <?php return;
endif;
$transactions = $snapshot['transactions'] ?? [];
$total_gross = (float) ( $snapshot['total_gross'] ?? 0 );
$total_net = (float) ( $snapshot['total_net'] ?? 0 );
$gift_count = (int) ( $snapshot['gift_count'] ?? 0 );
$full_name    = trim( $donor->first_name . ' ' . $donor->last_name );
metis_set_page_title( $full_name ?: $donor->email ?: $donor_id );
?>

<h1 class="metis-page-title"><?php echo metis_escape_html( $full_name ?: $donor->email ?: 'Donor' ); ?></h1>
<p class="metis-subtitle">Detailed giving history for this donor.</p>
<p><a href="<?php echo metis_escape_url( $base_url . '/donors/' ); ?>" class="metis-btn metis-btn-xs">← Back to Donors</a></p>

<!-- SUMMARY CARD -->
<div class="metis-summary-grid metis-donor-summary">
    <div class="metis-premium-cell">
        <div class="metis-muted metis-label">Name</div>
        <div class="metis-value"><?php echo metis_escape_html( $full_name ?: '—' ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted metis-label">Email</div>
        <div class="metis-value"><?php echo metis_escape_html( $donor->email ?: '—' ); ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted metis-label">Total Net</div>
        <div class="metis-value metis-value--primary"><?php echo $total_net > 0 ? '$' . number_format( $total_net, 2 ) : '—'; ?></div>
    </div>
    <div class="metis-premium-cell">
        <div class="metis-muted metis-label">Gifts</div>
        <div class="metis-value"><?php echo $gift_count; ?></div>
    </div>
</div>

<!-- GIVING HISTORY -->
<h2 class="metis-section-title">Giving History</h2>

<table class="metis-premium-table metis-donor-table metis-donor-view">
    <thead>
        <tr class="metis-premium-row metis-premium-header metis-donor-tx-row">
            <th class="metis-premium-cell metis-sort-btn" scope="col" data-sort="date">Date</th>
            <th class="metis-premium-cell metis-sort-btn" scope="col" data-sort="campaign">Campaign</th>
            <th class="metis-premium-cell" scope="col">Platform</th>
            <th class="metis-premium-cell metis-col-numeric metis-sort-btn" scope="col" data-sort="amount">Amount</th>
            <th class="metis-premium-cell" scope="col">Status / Type</th>
        </tr>
    </thead>
    <tbody id="metis-donor-tx-rows">
    <?php if ( ! empty( $transactions ) ) : ?>
        <?php foreach ( $transactions as $t ) :
            $timestamp    = $t->tran_date ? strtotime( $t->tran_date ) : 0;
            $display_date = $timestamp ? date( 'm/d/y', $timestamp ) : '—';
            $iso_date     = $timestamp ? date( 'Y-m-d', $timestamp ) : '';
            $campaign     = $t->campaign_name ?: $t->campaign_code ?: '—';
            $amount       = (float) $t->amount;
            $tx_url       = metis_donations_detail_url( 'transaction', (string) $t->tid );
        ?>
            <tr class="metis-premium-row metis-donor-tx-row"
                 data-href="<?php echo metis_escape_url( $tx_url ); ?>"
                 data-date="<?php echo metis_escape_attr( $iso_date ); ?>"
                 data-campaign="<?php echo metis_escape_attr( strtolower( $campaign ) ); ?>"
                 data-amount="<?php echo metis_escape_attr( $amount ); ?>"
                 tabindex="0">
                <td class="metis-premium-cell"><?php echo metis_escape_html( $display_date ); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html( $campaign ); ?></td>
                <td class="metis-premium-cell"><?php echo metis_escape_html( metis_platform_label( $t->platform ) ); ?></td>
                <td class="metis-premium-cell metis-col-numeric">$<?php echo number_format( $amount, 2 ); ?></td>
                <td class="metis-premium-cell metis-tx-flags">
                    <?php echo metis_status_badge( $t->status ); ?>
                    <?php echo metis_paymethod_badge( $t->payment_method ); ?>
                    <?php echo metis_deposit_badge( $t->deposit_date ); ?>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php else : ?>
        <tr class="metis-premium-row">
            <td class="metis-premium-cell metis-muted" colspan="5">No giving history recorded for this donor.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<!-- PAGINATION -->
<div class="metis-pagination">
    <button id="metis-donor-prev" class="metis-btn metis-btn-xs metis-btn-ghost">← Prev</button>
    <span id="metis-donor-page-status" class="metis-muted">Page 1 of 1</span>
    <button id="metis-donor-next" class="metis-btn metis-btn-xs metis-btn-ghost">Next →</button>
</div>
