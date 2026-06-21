<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$from = metis_text_clean( (string) ( metis_request_get()['from'] ?? '' ) );
$to   = metis_text_clean( (string) ( metis_request_get()['to'] ?? '' ) );
$report = \Metis\Modules\GrandyStash\GrandyStashRepository::reportData( $from, $to );
$report_tickets = \Metis\Modules\GrandyStash\GrandyStashRepository::reportTickets( $from, $to );
$summary = $report['summary'] ?? [];
$display_label = static function ( string $value, string $fallback = 'Other' ): string {
    $value = trim( $value );
    if ( $value === '' ) {
        return $fallback;
    }
    if ( str_contains( $value, '-' ) || str_contains( $value, '_' ) ) {
        $expanded = str_replace( [ '-', '_' ], ' ', $value );
        return $expanded === strtolower( $expanded ) ? ucwords( $expanded ) : $expanded;
    }
    return $value;
};
$range_text = ( $from !== '' || $to !== '' )
    ? ( $from !== '' ? $from : 'Start' ) . ' to ' . ( $to !== '' ? $to : 'Today' )
    : 'Showing all available ticket history.';
?>

<div class="metis-stash-app metis-stash-reports"
     data-stash-view="reports"
     data-view-base-url="<?php echo metis_escape_attr( metis_grandys_stash_view_url() ); ?>">

    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( "Grandy's Stash Reports" ) ); ?></h1>
    <p class="metis-subtitle">Grant-ready reporting on tickets, people served, and equipment distributed.</p>
    <div class="metis-people-stats metis-stash-stats">
        <article class="metis-people-stat"><div class="metis-people-stat-label">Total Tickets</div><div class="metis-people-stat-value" id="metis-stash-report-total-tickets"><?php echo (int) ( $summary['total_tickets'] ?? 0 ); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">People Served</div><div class="metis-people-stat-value" id="metis-stash-report-people-served"><?php echo (int) ( $report['people_served'] ?? 0 ); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Items Fulfilled</div><div class="metis-people-stat-value" id="metis-stash-report-items-fulfilled"><?php echo (int) ( $report['items_fulfilled'] ?? 0 ); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Completed</div><div class="metis-people-stat-value" id="metis-stash-report-completed"><?php echo (int) ( $summary['completed'] ?? 0 ); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Avg Days</div><div class="metis-people-stat-value" id="metis-stash-report-avg-days"><?php echo metis_escape_html( (string) ( $report['avg_days_to_complete'] ?? '—' ) ); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Open</div><div class="metis-people-stat-value" id="metis-stash-report-open"><?php echo (int) ( $summary['open_tickets'] ?? 0 ); ?></div></article>
    </div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () use ( $from, $to ) { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Date Range</div>
                <form method="get" action="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="metis-stash-report-filter-form" id="metis-stash-report-filter-form">
                    <label style="display:grid;gap:4px;font-size:13px;color:#67798b;">From
                        <input id="metis-stash-report-from" class="metis-input" type="date" name="from" value="<?php echo metis_escape_attr( $from ); ?>">
                    </label>
                    <label style="display:grid;gap:4px;font-size:13px;color:#67798b;margin-top:6px;">To
                        <input id="metis-stash-report-to" class="metis-input" type="date" name="to" value="<?php echo metis_escape_attr( $to ); ?>">
                    </label>
                    <div class="metis-stash-form-actions metis-stash-report-filter-actions">
                        <button type="submit" class="metis-btn metis-btn-xs" id="metis-stash-report-run">Run Report</button>
                        <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-stash-report-clear">Clear</button>
                    </div>
                </form>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Navigation</div>
                <nav class="metis-list-sidebar-nav" aria-label="Grandy's Stash navigation">
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>" class="metis-list-sidebar-nav-item">Inbox</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/groups/' ); ?>" class="metis-list-sidebar-nav-item">People Groups</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/organizations/' ); ?>" class="metis-list-sidebar-nav-item">Organizations</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="metis-list-sidebar-nav-item is-active">Reports</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/settings/' ); ?>" class="metis-list-sidebar-nav-item">Settings</a>
                </nav>
            </div>
        <?php },
        'content' => static function () use ( $report, $range_text, $display_label ) { ?>

    <div id="metis-stash-report-content">
        <section class="metis-stash-report-range-card">
            <div>
                <h2>Report Range</h2>
                <p id="metis-stash-report-range-text"><?php echo metis_escape_html( $range_text ); ?></p>
            </div>
            <div class="metis-stash-report-range-meta">
                <span class="metis-stash-status-badge" id="metis-stash-report-org-count">Organizations: <?php echo (int) count( $report['by_organization'] ?? [] ); ?></span>
                <span class="metis-stash-status-badge" id="metis-stash-report-person-count">People: <?php echo (int) count( $report['by_person'] ?? [] ); ?></span>
                <span class="metis-stash-status-badge" id="metis-stash-report-equipment-count">Equipment: <?php echo (int) count( $report['by_equipment'] ?? [] ); ?></span>
            </div>
        </section>

        <section id="metis-stash-report-drilldown" class="metis-stash-report-drilldown" hidden>
            <div class="metis-stash-report-drilldown-head">
                <div>
                    <h2 id="metis-stash-report-drilldown-title">Associated Tickets</h2>
                    <p id="metis-stash-report-drilldown-subtitle">Select a category, organization, or person to inspect matching tickets.</p>
                </div>
                <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-stash-report-drilldown-clear">Clear</button>
            </div>
            <div class="metis-stash-report-drilldown-tools">
                <input type="search" id="metis-stash-report-drilldown-search" class="metis-input" placeholder="Search code, person, organization, type, status, or items">
                <span class="metis-stash-status-badge" id="metis-stash-report-drilldown-count">0 results</span>
            </div>
            <table class="metis-premium-table metis-stash-report-drilldown-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable metis-sort-desc" data-report-sort="submitted_at">Submitted</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="code">Code</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="submit_name">Name</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="organization_label">Organization</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="type">Type</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="status">Status</button></th>
                        <th class="metis-premium-cell" scope="col">Items</th>
                    </tr>
                </thead>
                <tbody id="metis-stash-report-drilldown-body">
                    <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="7">Select a category, organization, or person to inspect matching tickets.</td></tr>
                </tbody>
            </table>
        </section>

        <section style="margin-bottom:28px;">
            <h2 style="font-size:16px;margin:0 0 12px;">Items by Category</h2>
            <table class="metis-premium-table metis-stash-report-cat-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Category</th>
                        <th class="metis-premium-cell" scope="col">Total Items</th>
                        <th class="metis-premium-cell" scope="col">Fulfilled</th>
                    </tr>
                </thead>
                <tbody id="metis-stash-report-category-body">
                <?php foreach ( ( $report['by_category'] ?? [] ) as $cat ) : ?>
                    <?php $label = $display_label( (string) ( $cat['category_name'] ?? '' ), 'Other' ); ?>
                    <tr class="metis-premium-row metis-stash-report-summary-row metis-clickable-row" tabindex="0" role="button" data-report-drilldown="category" data-report-value="<?php echo metis_escape_attr( (string) ( $cat['category_slug'] ?? '' ) ); ?>" data-report-label="<?php echo metis_escape_attr( $label ); ?>">
                        <td class="metis-premium-cell">
                            <span class="metis-stash-report-trigger"><?php echo metis_escape_html( $label ); ?></span>
                        </td>
                        <td class="metis-premium-cell"><?php echo (int) ( $cat['item_count'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $cat['fulfilled'] ?? 0 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $report['by_category'] ) ) : ?>
                    <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="3">No data for selected period.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section style="margin-bottom:28px;">
            <h2 style="font-size:16px;margin:0 0 12px;">Monthly Breakdown</h2>
            <table class="metis-premium-table metis-stash-report-month-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Month</th>
                        <th class="metis-premium-cell" scope="col">Tickets</th>
                        <th class="metis-premium-cell" scope="col">Requests</th>
                        <th class="metis-premium-cell" scope="col">Donations</th>
                        <th class="metis-premium-cell" scope="col">Completed</th>
                    </tr>
                </thead>
                <tbody id="metis-stash-report-monthly-body">
                <?php foreach ( ( $report['monthly'] ?? [] ) as $row ) : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['month_label'] ?? $row['month'] ?? '' ) ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['tickets'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['requests'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['donations'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['completed'] ?? 0 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $report['monthly'] ) ) : ?>
                    <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="5">No data for selected period.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <div class="metis-stash-report-split">
            <section>
                <h2 style="font-size:16px;margin:0 0 12px;">By Urgency</h2>
                <table class="metis-premium-table metis-stash-report-small-table">
                    <thead>
                        <tr class="metis-premium-row metis-premium-header">
                            <th class="metis-premium-cell" scope="col">Urgency</th>
                            <th class="metis-premium-cell" scope="col">Count</th>
                        </tr>
                    </thead>
                    <tbody id="metis-stash-report-urgency-body">
                    <?php foreach ( ( $report['by_urgency'] ?? [] ) as $row ) : ?>
                        <tr class="metis-premium-row">
                            <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( (string) ( $row['urgency'] ?? '' ) ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo (int) ( $row['count'] ?? 0 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <section>
                <h2 style="font-size:16px;margin:0 0 12px;">By Source</h2>
                <table class="metis-premium-table metis-stash-report-small-table">
                    <thead>
                        <tr class="metis-premium-row metis-premium-header">
                            <th class="metis-premium-cell" scope="col">Source</th>
                            <th class="metis-premium-cell" scope="col">Count</th>
                        </tr>
                    </thead>
                    <tbody id="metis-stash-report-source-body">
                    <?php foreach ( ( $report['by_source'] ?? [] ) as $row ) : ?>
                        <tr class="metis-premium-row">
                            <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( (string) ( $row['source'] ?? '' ) ) ); ?></td>
                            <td class="metis-premium-cell"><?php echo (int) ( $row['count'] ?? 0 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        </div>

        <section style="margin:28px 0;">
            <h2 style="font-size:16px;margin:0 0 12px;">Requests by Organization</h2>
            <table class="metis-premium-table metis-stash-report-wide-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Organization</th>
                        <th class="metis-premium-cell" scope="col">Domain</th>
                        <th class="metis-premium-cell" scope="col">Requests</th>
                        <th class="metis-premium-cell" scope="col">Tickets</th>
                    </tr>
                </thead>
                <tbody id="metis-stash-report-organization-body">
                <?php foreach ( ( $report['by_organization'] ?? [] ) as $row ) : ?>
                    <?php $label = $display_label( (string) ( $row['organization_name'] ?? '' ), 'Independent' ); ?>
                    <tr class="metis-premium-row metis-stash-report-summary-row metis-clickable-row" tabindex="0" role="button" data-report-drilldown="organization" data-report-value="<?php echo metis_escape_attr( (string) ( $row['organization_key'] ?? '' ) ); ?>" data-report-label="<?php echo metis_escape_attr( $label ); ?>">
                        <td class="metis-premium-cell">
                            <span class="metis-stash-report-trigger"><?php echo metis_escape_html( $label ); ?></span>
                        </td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['organization_domain'] ?? '—' ) ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['request_count'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['ticket_count'] ?? 0 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $report['by_organization'] ) ) : ?>
                    <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="4">No data for selected period.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section style="margin:28px 0;">
            <h2 style="font-size:16px;margin:0 0 12px;">Requests by Person</h2>
            <table class="metis-premium-table metis-stash-report-wide-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Person</th>
                        <th class="metis-premium-cell" scope="col">Email</th>
                        <th class="metis-premium-cell" scope="col">Requests</th>
                        <th class="metis-premium-cell" scope="col">Tickets</th>
                    </tr>
                </thead>
                <tbody id="metis-stash-report-person-body">
                <?php foreach ( ( $report['by_person'] ?? [] ) as $row ) : ?>
                    <?php $label = $display_label( (string) ( $row['person_name'] ?? '' ), 'Unknown' ); ?>
                    <tr class="metis-premium-row metis-stash-report-summary-row metis-clickable-row" tabindex="0" role="button" data-report-drilldown="person" data-report-value="<?php echo metis_escape_attr( (string) ( $row['person_key'] ?? '' ) ); ?>" data-report-label="<?php echo metis_escape_attr( $label ); ?>">
                        <td class="metis-premium-cell">
                            <span class="metis-stash-report-trigger"><?php echo metis_escape_html( $label ); ?></span>
                        </td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['person_email'] ?? '—' ) ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['request_count'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['ticket_count'] ?? 0 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $report['by_person'] ) ) : ?>
                    <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="4">No data for selected period.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section style="margin:28px 0 0;">
            <h2 style="font-size:16px;margin:0 0 12px;">Equipment Requested</h2>
            <table class="metis-premium-table metis-stash-report-equipment-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Equipment</th>
                        <th class="metis-premium-cell" scope="col">Category</th>
                        <th class="metis-premium-cell" scope="col">Requests</th>
                        <th class="metis-premium-cell" scope="col">Donations</th>
                        <th class="metis-premium-cell" scope="col">Fulfilled</th>
                    </tr>
                </thead>
                <tbody id="metis-stash-report-equipment-body">
                <?php foreach ( ( $report['by_equipment'] ?? [] ) as $row ) : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><?php echo metis_escape_html( $display_label( (string) ( $row['equipment_name'] ?? '' ), 'Other' ) ); ?></td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( $display_label( (string) ( $row['category_name'] ?? '' ), 'Other' ) ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['request_quantity'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['donation_quantity'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['fulfilled_quantity'] ?? 0 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $report['by_equipment'] ) ) : ?>
                    <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="5">No data for selected period.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

        <?php },
    ]); ?>

    <script id="metis-stash-boot" type="application/json"><?php echo metis_json_encode( [
        'report'        => $report,
        'reportTickets' => $report_tickets,
        'reportFilters' => [
            'from' => $from,
            'to'   => $to,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
