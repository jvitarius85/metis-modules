<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$base_url           = metis_donations_base_url();
$snapshot = \Metis\Modules\Donations\ReadService::campaignsSnapshot();
$campaigns = $snapshot['campaigns'] ?? [];

// -------------------------------------------------------------------------
// Parse goals field → current year goal amount
// Goals stored as pipe-delimited: "2025:10000.00|2024:2500.00"
// -------------------------------------------------------------------------
// metis_parse_goals() is defined in donations bootstrap

$current_year = (int) ( $snapshot['current_year'] ?? (int) date( 'Y' ) );
$year_raised_by_campaign = $snapshot['year_raised_by_campaign'] ?? [];
?>

<h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( 'Campaigns' ) ); ?></h1>
<p class="metis-subtitle">Manage donation campaigns, goals, and descriptions.</p>

<div class="metis-list-layout">

<!-- Sidebar -->
<aside class="metis-list-sidebar">
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Search</div>
        <input type="text" id="metis-campaign-search" class="metis-input" placeholder="Search campaigns…">
    </div>
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Type</div>
        <select id="metis-campaign-type-filter" class="metis-select">
            <option value="">All Types</option>
            <option value="ongoing">Ongoing</option>
            <option value="project">Project</option>
        </select>
    </div>
    <div class="metis-list-sidebar-section">
        <div class="metis-list-sidebar-label">Status</div>
        <select id="metis-campaign-status-filter" class="metis-select">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
</aside>

<!-- Main content -->
<div class="metis-list-content">

<!-- CAMPAIGNS TABLE -->
<table class="metis-premium-table metis-campaigns-table">
    <thead>
        <tr class="metis-premium-row metis-premium-header metis-campaign-row">
            <th class="metis-premium-cell" scope="col">Campaign</th>
            <th class="metis-premium-cell" scope="col">Type</th>
            <th class="metis-premium-cell metis-col-numeric" scope="col"><?php echo $current_year; ?> Goal</th>
            <th class="metis-premium-cell metis-col-numeric" scope="col">Total Raised</th>
            <th class="metis-premium-cell" scope="col"><?php echo $current_year; ?> Progress</th>
            <th class="metis-premium-cell" scope="col">Status</th>
        </tr>
    </thead>
    <tbody>

    <?php if ( ! empty( $campaigns ) ) : ?>
        <?php foreach ( $campaigns as $c ) :
            $goals         = metis_parse_goals( $c->goals );
            $year_goal     = $goals[ $current_year ] ?? null;
            $total_raised  = (float) ( $c->total_raised ?? 0 );
            $year_raised   = (float) ( $year_raised_by_campaign[ (string) ( $c->cid ?? '' ) ] ?? 0 );

            $pct         = ( $year_goal && $year_goal > 0 ) ? min( 100, round( ( $year_raised / $year_goal ) * 100, 1 ) ) : null;
            $campaign_url = metis_donations_detail_url( 'campaign', (string) $c->cid );
            $is_active    = (int) $c->active;
        ?>
            <tr class="metis-premium-row metis-campaign-row"
                 data-name="<?php echo metis_escape_attr( strtolower( $c->cname ) ); ?>"
                 data-type="<?php echo metis_escape_attr( strtolower( $c->type ?? '' ) ); ?>"
                 data-active="<?php echo $is_active ? 'active' : 'inactive'; ?>"
                 data-href="<?php echo metis_escape_url( $campaign_url ); ?>">

                <td class="metis-premium-cell metis-campaign-name-cell">
                    <div class="metis-campaign-name"><?php echo metis_escape_html( $c->cname ); ?></div>
                    <div class="metis-muted metis-campaign-meta-text">
                        <?php echo metis_escape_html( $c->cid ); ?>
                        <?php if ( $c->url ) : ?>
                            · <a href="<?php echo metis_escape_url( 'https://mobilizewaco.org' . $c->url ); ?>" target="_blank" class="metis-link-muted">↗</a>
                        <?php endif; ?>
                    </div>
                </td>

                <td class="metis-premium-cell">
                    <span class="metis-badge <?php echo strtolower( $c->type ?? '' ) === 'ongoing' ? 'blue' : 'muted'; ?>">
                        <?php echo metis_escape_html( ucfirst( $c->type ?? 'Unknown' ) ); ?>
                    </span>
                </td>

                <td class="metis-premium-cell metis-col-numeric">
                    <?php echo $year_goal ? '$' . number_format( $year_goal, 0 ) : '<span class="metis-muted">—</span>'; ?>
                </td>

                <td class="metis-premium-cell metis-col-numeric">
                    <?php echo $total_raised > 0 ? '$' . number_format( $total_raised, 2 ) : '<span class="metis-muted">—</span>'; ?>
                </td>

                <td class="metis-premium-cell metis-campaign-progress-cell">
                    <?php if ( $pct !== null ) : ?>
                        <div class="metis-progress-bar-wrap">
                            <div class="metis-progress-bar-fill <?php echo $pct >= 100 ? 'metis-progress-complete' : ( $pct >= 50 ? 'metis-progress-mid' : 'metis-progress-low' ); ?>"
                                 style="width: <?php echo $pct; ?>%"></div>
                        </div>
                        <div class="metis-progress-label">
                            $<?php echo number_format( $year_raised, 0 ); ?> · <?php echo $pct; ?>%
                        </div>
                    <?php elseif ( $year_raised > 0 ) : ?>
                        <span class="metis-muted metis-campaign-progress-note">$<?php echo number_format( $year_raised, 0 ); ?> raised · no goal set</span>
                    <?php else : ?>
                        <span class="metis-muted metis-campaign-progress-note">No activity</span>
                    <?php endif; ?>
                </td>

                <td class="metis-premium-cell">
                    <?php if ( $is_active ) : ?>
                        <span class="metis-badge green">Active</span>
                    <?php else : ?>
                        <span class="metis-badge gray">Inactive</span>
                    <?php endif; ?>
                </td>

            </tr>
        <?php endforeach; ?>
    <?php else : ?>
        <tr class="metis-premium-row">
            <td class="metis-premium-cell metis-muted" colspan="6">No campaigns found.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table><!-- /metis-campaigns-table -->
</div><!-- /metis-list-content -->
</div><!-- /metis-list-layout -->
