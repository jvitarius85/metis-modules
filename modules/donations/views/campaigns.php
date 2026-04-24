<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$campaigns_table    = Metis_Tables::get( 'campaigns' );
$transactions_table = Metis_Tables::get( 'transactions' );
$base_url           = metis_donations_base_url();

// -------------------------------------------------------------------------
// Fetch all campaigns with transaction aggregates
// -------------------------------------------------------------------------
$campaigns = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll( "
    SELECT
        c.*,
        COUNT( t.id )        AS gift_count,
        SUM( t.amount )      AS total_raised,
        MAX( t.tran_date )   AS last_gift_date
    FROM {$campaigns_table} c
    LEFT JOIN {$transactions_table} t ON t.campaign_code = c.cid
    GROUP BY c.id
    ORDER BY c.active DESC, c.cname ASC
" ) ?: [] );

// -------------------------------------------------------------------------
// Parse goals field → current year goal amount
// Goals stored as pipe-delimited: "2025:10000.00|2024:2500.00"
// -------------------------------------------------------------------------
// metis_parse_goals() is defined in donations bootstrap

$current_year = (int) date( 'Y' );
?>

<h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Campaigns' ) ); ?></h1>
<p class="mw-subtitle">Manage donation campaigns, goals, and descriptions.</p>

<div class="mw-list-layout">

<!-- Sidebar -->
<aside class="mw-list-sidebar">
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Search</div>
        <input type="text" id="mw-campaign-search" class="mw-input" placeholder="Search campaigns…">
    </div>
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Type</div>
        <select id="mw-campaign-type-filter" class="mw-select">
            <option value="">All Types</option>
            <option value="ongoing">Ongoing</option>
            <option value="project">Project</option>
        </select>
    </div>
    <div class="mw-list-sidebar-section">
        <div class="mw-list-sidebar-label">Status</div>
        <select id="mw-campaign-status-filter" class="mw-select">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
</aside>

<!-- Main content -->
<div class="mw-list-content">

<!-- CAMPAIGNS TABLE -->
<div class="mw-premium-table mw-campaigns-table">

    <div class="mw-premium-row mw-premium-header mw-campaign-row">
        <div class="mw-premium-cell">Campaign</div>
        <div class="mw-premium-cell">Type</div>
        <div class="mw-premium-cell mw-col-numeric"><?php echo $current_year; ?> Goal</div>
        <div class="mw-premium-cell mw-col-numeric">Total Raised</div>
        <div class="mw-premium-cell"><?php echo $current_year; ?> Progress</div>
        <div class="mw-premium-cell">Status</div>
    </div>

    <?php if ( ! empty( $campaigns ) ) : ?>
        <?php foreach ( $campaigns as $c ) :
            $goals         = metis_parse_goals( $c->goals );
            $year_goal     = $goals[ $current_year ] ?? null;
            $total_raised  = (float) ( $c->total_raised ?? 0 );
            $year_raised   = 0.0;

            // Calculate current-year raised from transactions
            $year_raised_raw = $db->scalar(
                "SELECT SUM(amount) FROM {$transactions_table}
                 WHERE campaign_code = %s AND YEAR(tran_date) = %d",
                [ $c->cid, $current_year ]
            );
            $year_raised = (float) ( $year_raised_raw ?? 0 );

            $pct         = ( $year_goal && $year_goal > 0 ) ? min( 100, round( ( $year_raised / $year_goal ) * 100, 1 ) ) : null;
            $campaign_url = $base_url . '/campaign/?cid=' . urlencode( $c->cid );
            $is_active    = (int) $c->active;
        ?>
            <div class="mw-premium-row mw-campaign-row"
                 data-name="<?php echo metis_escape_attr( strtolower( $c->cname ) ); ?>"
                 data-type="<?php echo metis_escape_attr( strtolower( $c->type ?? '' ) ); ?>"
                 data-active="<?php echo $is_active ? 'active' : 'inactive'; ?>"
                 data-href="<?php echo metis_escape_url( $campaign_url ); ?>">

                <div class="mw-premium-cell mw-campaign-name-cell">
                    <div class="mw-campaign-name"><?php echo metis_escape_html( $c->cname ); ?></div>
                    <div class="mw-muted mw-campaign-meta-text">
                        <?php echo metis_escape_html( $c->cid ); ?>
                        <?php if ( $c->url ) : ?>
                            · <a href="<?php echo metis_escape_url( 'https://mobilizewaco.org' . $c->url ); ?>" target="_blank" class="mw-link-muted">↗</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mw-premium-cell">
                    <span class="mw-badge <?php echo strtolower( $c->type ?? '' ) === 'ongoing' ? 'blue' : 'muted'; ?>">
                        <?php echo metis_escape_html( ucfirst( $c->type ?? 'Unknown' ) ); ?>
                    </span>
                </div>

                <div class="mw-premium-cell mw-col-numeric">
                    <?php echo $year_goal ? '$' . number_format( $year_goal, 0 ) : '<span class="mw-muted">—</span>'; ?>
                </div>

                <div class="mw-premium-cell mw-col-numeric">
                    <?php echo $total_raised > 0 ? '$' . number_format( $total_raised, 2 ) : '<span class="mw-muted">—</span>'; ?>
                </div>

                <div class="mw-premium-cell mw-campaign-progress-cell">
                    <?php if ( $pct !== null ) : ?>
                        <div class="mw-progress-bar-wrap">
                            <div class="mw-progress-bar-fill <?php echo $pct >= 100 ? 'mw-progress-complete' : ( $pct >= 50 ? 'mw-progress-mid' : 'mw-progress-low' ); ?>"
                                 style="width: <?php echo $pct; ?>%"></div>
                        </div>
                        <div class="mw-progress-label">
                            $<?php echo number_format( $year_raised, 0 ); ?> · <?php echo $pct; ?>%
                        </div>
                    <?php elseif ( $year_raised > 0 ) : ?>
                        <span class="mw-muted mw-campaign-progress-note">$<?php echo number_format( $year_raised, 0 ); ?> raised · no goal set</span>
                    <?php else : ?>
                        <span class="mw-muted mw-campaign-progress-note">No activity</span>
                    <?php endif; ?>
                </div>

                <div class="mw-premium-cell">
                    <?php if ( $is_active ) : ?>
                        <span class="mw-badge green">Active</span>
                    <?php else : ?>
                        <span class="mw-badge gray">Inactive</span>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <div class="mw-premium-row">
            <div class="mw-muted">No campaigns found.</div>
        </div>
    <?php endif; ?>

</div><!-- /mw-campaigns-table -->
</div><!-- /mw-list-content -->
</div><!-- /mw-list-layout -->
