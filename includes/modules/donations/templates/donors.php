<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );

$base_url = metis_donations_base_url();

// Fetch all contacts that have a DID
$contacts = $wpdb->get_results( "
    SELECT id, first_name, last_name, email, did
    FROM {$contacts_table}
    WHERE did IS NOT NULL
      AND did <> ''
    ORDER BY last_name, first_name
" );

// Build totals map: DID => total_amount
$totals_raw = $wpdb->get_results( "
    SELECT did, SUM(amount) AS total_amount
    FROM {$transactions_table}
    GROUP BY did
", OBJECT_K );

// Build donors array
$donors = [];

foreach ( $contacts as $c ) {
    $did   = $c->did;
    $total = isset( $totals_raw[ $did ] ) ? (float) $totals_raw[ $did ]->total_amount : 0.0;

    $donors[] = [
        'id'         => (int) $c->id,
        'first_name' => $c->first_name,
        'last_name'  => $c->last_name,
        'email'      => $c->email,
        'did'        => $did,
        'total'      => $total,
    ];
}
?>
<h1 class="mw-page-title">Donors</h1>
<p class="mw-subtitle">Contacts with a linked donor record.</p>

<div class="mw-list-layout">

<!-- Sidebar -->
<aside class="mw-list-sidebar">
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Search</div>
        <input id="mw-donor-search" type="text" class="mw-input" placeholder="Name, email, or DID">
    </div>
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Giving</div>
        <select id="mw-donor-giving" class="mw-select">
            <option value="all">All donors</option>
            <option value="with_gifts">With contributions</option>
            <option value="no_gifts">No contributions</option>
        </select>
    </div>
</aside>

<!-- Main content -->
<div class="mw-list-content">
<div class="mw-premium-table mw-donors-table">

    <div class="mw-premium-row mw-premium-header mw-donors-header">
        <div class="mw-premium-cell mw-sortable" id="sort-name">Name ▾</div>
        <div class="mw-premium-cell mw-sortable" id="sort-email">Email ▾</div>
        <div class="mw-premium-cell mw-sortable" id="sort-total">Total Contribution ▾</div>
        <div class="mw-premium-cell" style="text-align:right;">Actions</div>
    </div>

    <div id="mw-donor-rows">
        <?php if ( ! empty( $donors ) ) : ?>
            <?php foreach ( $donors as $d ) :
                $d_url     = $base_url . '/donor/?id=' . urlencode( $d['did'] );
                $full_name = trim( $d['first_name'] . ' ' . $d['last_name'] );
            ?>
                <div class="mw-premium-row mw-donor-row"
                     data-name="<?php echo esc_attr( strtolower( $full_name ) ); ?>"
                     data-email="<?php echo esc_attr( strtolower( $d['email'] ) ); ?>"
                     data-did="<?php echo esc_attr( $d['did'] ); ?>"
                     data-total="<?php echo esc_attr( $d['total'] ); ?>"
                     data-href="<?php echo esc_url( $d_url ); ?>">

                    <div class="mw-premium-cell mw-col-donor-name">
                        <div class="mw-donor-name"><?php echo esc_html( $full_name ); ?></div>
                        <div class="mw-donor-sub mw-muted">DID: <?php echo esc_html( $d['did'] ); ?></div>
                    </div>

                    <div class="mw-premium-cell mw-col-donor-email">
                        <?php echo esc_html( $d['email'] ); ?>
                    </div>

                    <div class="mw-premium-cell mw-col-donor-total mw-col-numeric">
                        <?php echo $d['total'] > 0 ? '$' . number_format( $d['total'], 2 ) : '—'; ?>
                    </div>

                    <div class="mw-premium-cell" style="flex:0 0 140px; text-align:right;">
                        <a href="<?php echo esc_url( $d_url ); ?>" class="mw-btn-xs">View</a>
                    </div>

                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="mw-premium-row">
                <div class="mw-premium-cell mw-muted">No donors found.</div>
            </div>
        <?php endif; ?>
    </div>

</div>

<div class="mw-pagination">
    <button id="mw-page-prev" class="mw-btn-xs">Prev</button>
    <span id="mw-page-indicator" class="mw-muted">Page 1 of 1</span>
    <button id="mw-page-next" class="mw-btn-xs">Next</button>
</div>
</div><!-- /mw-list-content -->
</div><!-- /mw-list-layout -->


