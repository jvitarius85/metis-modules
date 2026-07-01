<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$from = metis_text_clean( (string) ( metis_request_get()['from'] ?? '' ) );
$to   = metis_text_clean( (string) ( metis_request_get()['to'] ?? '' ) );
$report = \Metis\Modules\GrandyStash\GrandyStashRepository::reportData( $from, $to );
$report_page = \Metis\Modules\GrandyStash\GrandyStashRepository::reportTicketPage( [
    'from'     => $from,
    'to'       => $to,
    'page'     => 1,
    'per_page' => 25,
] );
$report_options = \Metis\Modules\GrandyStash\GrandyStashRepository::reportBuilderOptions( $from, $to );
$report = is_array( $report ) ? $report : [];
$report += [
    'summary'              => [],
    'people_served'        => 0,
    'items_fulfilled'      => 0,
    'avg_days_to_complete' => '—',
    'by_category'          => [],
    'monthly'              => [],
    'by_urgency'           => [],
    'by_source'            => [],
    'by_organization'      => [],
    'by_person'            => [],
    'by_equipment'         => [],
];
$report['summary'] = is_array( $report['summary'] ) ? $report['summary'] : [];
$report_page = is_array( $report_page ) ? $report_page : [];
$report_page += [
    'rows' => [],
    'pagination' => [
        'page'        => 1,
        'per_page'    => 25,
        'total'       => 0,
        'total_pages' => 1,
        'has_prev'    => false,
        'has_next'    => false,
    ],
];
$report_options = is_array( $report_options ) ? $report_options : [];
$report_options += [
    'assigned'      => [],
    'organizations' => [],
    'people'        => [],
    'items'         => [],
];
$summary = $report['summary'];
$can_export = function_exists( 'metis_grandys_stash_can_export' ) && metis_grandys_stash_can_export();
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
     data-can-export="<?php echo metis_escape_attr( $can_export ? '1' : '0' ); ?>"
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
        'content' => static function () use ( $report, $range_text, $display_label, $can_export ) { ?>

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

        <section id="metis-stash-report-drilldown" class="metis-stash-report-drilldown">
            <div class="metis-stash-report-drilldown-head">
                <div>
                    <h2 id="metis-stash-report-drilldown-title">Report Builder</h2>
                    <p id="metis-stash-report-drilldown-subtitle">Filter tickets from the current report range without leaving the page.</p>
                </div>
                <div class="metis-stash-report-head-actions">
                    <?php if ( $can_export ) : ?>
                    <button type="button" class="metis-btn metis-btn-xs" id="metis-stash-report-export-pdf">Download PDF</button>
                    <?php endif; ?>
                    <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-stash-report-drilldown-clear">Clear</button>
                </div>
            </div>
            <div class="metis-stash-report-drilldown-tools">
                <input type="search" id="metis-stash-report-drilldown-search" class="metis-input" placeholder="Search code, person, organization, type, status, or items">
                <span class="metis-stash-status-badge" id="metis-stash-report-drilldown-count">0 results</span>
            </div>
            <div class="metis-stash-report-builder-grid">
                <label class="metis-stash-report-filter-field">
                    <span>Category</span>
                    <select id="metis-stash-report-builder-category" class="metis-input">
                        <option value="">All categories</option>
                    </select>
                </label>
                <label class="metis-stash-report-filter-field">
                    <span>Item</span>
                    <input type="search" id="metis-stash-report-builder-item" class="metis-input" list="metis-stash-report-item-options" placeholder="Type to search items">
                </label>
                <label class="metis-stash-report-filter-field">
                    <span>Organization</span>
                    <input type="search" id="metis-stash-report-builder-organization" class="metis-input" list="metis-stash-report-organization-options" placeholder="Type to search organizations">
                </label>
                <label class="metis-stash-report-filter-field">
                    <span>Person</span>
                    <input type="search" id="metis-stash-report-builder-person" class="metis-input" list="metis-stash-report-person-options" placeholder="Type to search people">
                </label>
                <label class="metis-stash-report-filter-field">
                    <span>Urgency</span>
                    <select id="metis-stash-report-builder-urgency" class="metis-input">
                        <option value="">All urgencies</option>
                        <option value="urgent">Urgent</option>
                        <option value="standard">Standard</option>
                        <option value="flexible">Flexible</option>
                    </select>
                </label>
                <label class="metis-stash-report-filter-field">
                    <span>Type</span>
                    <select id="metis-stash-report-builder-type" class="metis-input">
                        <option value="">All types</option>
                        <option value="request">Request</option>
                        <option value="donation">Donation</option>
                    </select>
                </label>
                <label class="metis-stash-report-filter-field">
                    <span>Status</span>
                    <select id="metis-stash-report-builder-status" class="metis-input">
                        <option value="">All statuses</option>
                        <option value="NEW">New</option>
                        <option value="REVIEWING">Reviewing</option>
                        <option value="WAITLIST">Waitlist</option>
                        <option value="READY">Ready</option>
                        <option value="COMPLETED">Completed</option>
                        <option value="CLOSED">Closed</option>
                    </select>
                </label>
                <label class="metis-stash-report-filter-field">
                    <span>Assigned</span>
                    <select id="metis-stash-report-builder-assigned" class="metis-input">
                        <option value="">Anyone</option>
                    </select>
                </label>
            </div>
            <datalist id="metis-stash-report-item-options"></datalist>
            <datalist id="metis-stash-report-organization-options"></datalist>
            <datalist id="metis-stash-report-person-options"></datalist>
            <section class="metis-stash-report-trend-card" aria-labelledby="metis-stash-report-trend-title">
                <div class="metis-stash-report-trend-head">
                    <div>
                        <h3 id="metis-stash-report-trend-title">Monthly Trends</h3>
                        <p id="metis-stash-report-trend-subtitle">Requests, donations, and completed tickets across the current report window.</p>
                    </div>
                </div>
                <div id="metis-stash-report-trend-graph" class="metis-stash-report-trend-graph">
                    <div class="metis-muted">Trend data will appear here.</div>
                </div>
            </section>
            <table class="metis-premium-table metis-stash-report-drilldown-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable metis-sort-desc" data-report-sort="submitted_at">Submitted</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="code">Code</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="submit_name">Name</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="organization_label">Organization</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="assigned_label">Assigned</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="type">Type</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="urgency">Urgency</button></th>
                        <th class="metis-premium-cell" scope="col"><button type="button" class="metis-sortable" data-report-sort="status">Status</button></th>
                        <th class="metis-premium-cell" scope="col">Items</th>
                    </tr>
                </thead>
                <tbody id="metis-stash-report-drilldown-body">
                    <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="9">Loading tickets.</td></tr>
                </tbody>
            </table>
            <div class="metis-stash-report-pagination">
                <label class="metis-stash-report-pagination-size">
                    <span>Rows</span>
                    <select id="metis-stash-report-per-page" class="metis-input">
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </label>
                <div class="metis-stash-report-pagination-actions">
                    <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-stash-report-page-prev">Previous</button>
                    <span id="metis-stash-report-page-info" class="metis-stash-report-page-info">Page 1 of 1</span>
                    <button type="button" class="metis-btn metis-btn-xs metis-btn-ghost" id="metis-stash-report-page-next">Next</button>
                </div>
            </div>
        </section>

        <section style="margin-bottom:28px;">
            <h2 style="font-size:16px;margin:0 0 12px;">Items by Category</h2>
            <table class="metis-premium-table metis-stash-report-cat-table">
                <thead>
                    <tr class="metis-premium-row metis-premium-header">
                        <th class="metis-premium-cell" scope="col">Category</th>
                        <th class="metis-premium-cell" scope="col">Tickets</th>
                        <th class="metis-premium-cell" scope="col">Item Rows</th>
                        <th class="metis-premium-cell" scope="col">Fulfilled Rows</th>
                    </tr>
                </thead>
                <tbody id="metis-stash-report-category-body">
                <?php foreach ( ( $report['by_category'] ?? [] ) as $cat ) : ?>
                    <?php $label = $display_label( (string) ( $cat['category_name'] ?? '' ), 'Other' ); ?>
                    <tr class="metis-premium-row metis-stash-report-summary-row metis-clickable-row" tabindex="0" role="button" data-report-drilldown="category" data-report-value="<?php echo metis_escape_attr( (string) ( $cat['category_slug'] ?? '' ) ); ?>" data-report-label="<?php echo metis_escape_attr( $label ); ?>">
                        <td class="metis-premium-cell">
                            <span class="metis-stash-report-trigger"><?php echo metis_escape_html( $label ); ?></span>
                        </td>
                        <td class="metis-premium-cell"><?php echo (int) ( $cat['ticket_count'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $cat['item_count'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $cat['fulfilled'] ?? 0 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ( empty( $report['by_category'] ) ) : ?>
                    <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="4">No data for selected period.</td></tr>
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
                        <th class="metis-premium-cell" scope="col">Request Tickets</th>
                        <th class="metis-premium-cell" scope="col">Donation Tickets</th>
                        <th class="metis-premium-cell" scope="col">Fulfilled Rows</th>
                    </tr>
                </thead>
                <tbody id="metis-stash-report-equipment-body">
                <?php foreach ( ( $report['by_equipment'] ?? [] ) as $row ) : ?>
                    <tr class="metis-premium-row">
                        <td class="metis-premium-cell"><?php echo metis_escape_html( $display_label( (string) ( $row['equipment_name'] ?? '' ), 'Other' ) ); ?></td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( $display_label( (string) ( $row['category_name'] ?? '' ), 'Other' ) ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['request_ticket_count'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['donation_ticket_count'] ?? 0 ); ?></td>
                        <td class="metis-premium-cell"><?php echo (int) ( $row['fulfilled_count'] ?? 0 ); ?></td>
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
        'reportPage'    => $report_page,
        'reportOptions' => $report_options,
        'reportFilters' => [
            'from' => $from,
            'to'   => $to,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?></script>
</div>
