<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );

$base_url = metis_donations_base_url();

// Fetch all contacts that have a DID
$contacts = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll( "
    SELECT id, first_name, last_name, email, did
    FROM {$contacts_table}
    WHERE did IS NOT NULL
      AND did <> ''
    ORDER BY last_name, first_name
" ) ?: [] );

// Build totals map: DID => total_amount
$totals_raw = [];
foreach ( $db->fetchAll( "
    SELECT did, SUM(amount) AS total_amount
    FROM {$transactions_table}
    GROUP BY did
" ) ?: [] as $row ) {
    $did = (string) ( $row['did'] ?? '' );
    if ( $did !== '' ) {
        $totals_raw[ $did ] = (object) $row;
    }
}

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
<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Donors' ) ); ?></h1>
<p class="metis-subtitle">Contacts with a linked donor record.</p>

<div class="metis-list-layout">

<!-- Sidebar -->
<aside class="metis-list-sidebar">
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Search</div>
        <input id="metis-donor-search" type="text" class="metis-input" placeholder="Name, email, or DID">
    </div>
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Giving</div>
        <select id="metis-donor-giving" class="metis-select">
            <option value="all">All donors</option>
            <option value="with_gifts">With contributions</option>
            <option value="no_gifts">No contributions</option>
        </select>
    </div>
</aside>

<!-- Main content -->
<div class="metis-list-content">
<table class="metis-premium-table metis-donors-table">
    <thead>
        <tr class="metis-premium-row metis-premium-header metis-donors-header">
            <th class="metis-premium-cell" scope="col">Name</th>
            <th class="metis-premium-cell" scope="col">Email</th>
            <th class="metis-premium-cell" scope="col">Total Contribution</th>
            <th class="metis-premium-cell metis-col-right" scope="col">Actions</th>
        </tr>
    </thead>
    <tbody id="metis-donor-rows">
        <?php if ( ! empty( $donors ) ) : ?>
            <?php foreach ( $donors as $d ) :
                $d_url     = $base_url . '/donor/?id=' . urlencode( $d['did'] );
                $full_name = trim( $d['first_name'] . ' ' . $d['last_name'] );
            ?>
                <tr class="metis-premium-row metis-donor-row"
                     data-name="<?php echo metis_escape_attr( strtolower( $full_name ) ); ?>"
                     data-email="<?php echo metis_escape_attr( strtolower( $d['email'] ) ); ?>"
                     data-did="<?php echo metis_escape_attr( $d['did'] ); ?>"
                     data-total="<?php echo metis_escape_attr( $d['total'] ); ?>"
                     data-href="<?php echo metis_escape_url( $d_url ); ?>">

                    <td class="metis-premium-cell metis-col-donor-name">
                        <div class="metis-donor-name"><?php echo metis_escape_html( $full_name ); ?></div>
                        <div class="metis-donor-sub metis-muted">DID: <?php echo metis_escape_html( $d['did'] ); ?></div>
                    </td>

                    <td class="metis-premium-cell metis-col-donor-email">
                        <?php echo metis_escape_html( $d['email'] ); ?>
                    </td>

                    <td class="metis-premium-cell metis-col-donor-total metis-col-numeric">
                        <?php echo $d['total'] > 0 ? '$' . number_format( $d['total'], 2 ) : '—'; ?>
                    </td>

                    <td class="metis-premium-cell metis-donor-actions-cell">
                        <a href="<?php echo metis_escape_url( $d_url ); ?>" class="metis-btn-xs">View</a>
                    </td>

                </tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr class="metis-premium-row">
                <td class="metis-premium-cell metis-muted" colspan="4">No donors found.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<div class="metis-pagination">
    <button id="metis-page-prev" class="metis-btn-xs">Prev</button>
    <span id="metis-page-indicator" class="metis-muted">Page 1 of 1</span>
    <button id="metis-page-next" class="metis-btn-xs">Next</button>
</div>
</div><!-- /metis-list-content -->
</div><!-- /metis-list-layout -->
