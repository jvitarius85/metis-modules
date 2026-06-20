<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="metis-alert metis-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$from = metis_text_clean( (string) ( metis_request_get()['from'] ?? '' ) );
$to   = metis_text_clean( (string) ( metis_request_get()['to'] ?? '' ) );
$report = \Metis\Modules\GrandyStash\GrandyStashRepository::reportData( $from, $to );
$summary = $report['summary'] ?? [];
$build_inbox_drilldown = static function ( array $params ): string {
    $query = array_filter(
        $params,
        static fn( $value ): bool => $value !== '' && $value !== null
    );
    $base = metis_grandys_stash_base_url();
    return $base . ( empty( $query ) ? '' : '?' . http_build_query( $query ) );
};
?>

<div class="metis-stash-app metis-stash-reports">

    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( "Grandy's Stash Reports" ) ); ?></h1>
    <p class="metis-subtitle">Grant-ready reporting on tickets, people served, and equipment distributed.</p>
    <div class="metis-people-stats metis-stash-stats">
        <article class="metis-people-stat"><div class="metis-people-stat-label">Total Tickets</div><div class="metis-people-stat-value"><?php echo (int)($summary['total_tickets'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">People Served</div><div class="metis-people-stat-value"><?php echo (int)($report['people_served'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Items Fulfilled</div><div class="metis-people-stat-value"><?php echo (int)($report['items_fulfilled'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Completed</div><div class="metis-people-stat-value"><?php echo (int)($summary['completed'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Avg Days</div><div class="metis-people-stat-value"><?php echo metis_escape_html( (string)($report['avg_days_to_complete'] ?? '—') ); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Open</div><div class="metis-people-stat-value"><?php echo (int)($summary['open_tickets'] ?? 0); ?></div></article>
    </div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () use ( $from, $to ) { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Date Range</div>
                <form method="get" action="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="metis-stash-report-filter-form">
                    <label style="display:grid;gap:4px;font-size:13px;color:#67798b;">From
                        <input id="metis-stash-report-from" class="metis-input" type="date" name="from" value="<?php echo metis_escape_attr( $from ); ?>">
                    </label>
                    <label style="display:grid;gap:4px;font-size:13px;color:#67798b;margin-top:6px;">To
                        <input id="metis-stash-report-to" class="metis-input" type="date" name="to" value="<?php echo metis_escape_attr( $to ); ?>">
                    </label>
                    <div class="metis-stash-form-actions metis-stash-report-filter-actions">
                        <button type="submit" class="metis-btn metis-btn-xs" id="metis-stash-report-run">Run Report</button>
                        <?php if ( $from !== '' || $to !== '' ) : ?>
                        <a class="metis-btn metis-btn-xs metis-btn-ghost" href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>">Clear</a>
                        <?php endif; ?>
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
        'content' => static function () use ( $report, $from, $to, $build_inbox_drilldown ) { ?>

    <div id="metis-stash-report-content">
        <section class="metis-stash-report-range-card">
            <div>
                <h2>Report Range</h2>
                <p>
                    <?php
                    if ( $from !== '' || $to !== '' ) {
                        echo metis_escape_html( $from !== '' ? $from : 'Start' ) . ' to ' . metis_escape_html( $to !== '' ? $to : 'Today' );
                    } else {
                        echo 'Showing all available ticket history.';
                    }
                    ?>
                </p>
            </div>
            <div class="metis-stash-report-range-meta">
                <span class="metis-stash-status-badge">Organizations: <?php echo (int) count( $report['by_organization'] ?? [] ); ?></span>
                <span class="metis-stash-status-badge">People: <?php echo (int) count( $report['by_person'] ?? [] ); ?></span>
                <span class="metis-stash-status-badge">Equipment: <?php echo (int) count( $report['by_equipment'] ?? [] ); ?></span>
            </div>
        </section>

        <!-- By Category -->
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
            <tbody>
            <?php foreach ( ($report['by_category'] ?? []) as $cat ) : ?>
            <tr class="metis-premium-row">
                <td class="metis-premium-cell">
                    <a href="<?php echo metis_escape_url( $build_inbox_drilldown([
                        'stash_filter' => 'all',
                        'stash_sort'   => 'submitted_desc',
                        'category_slug'=> (string) ( $cat['category_slug'] ?? '' ),
                    ]) ); ?>" class="metis-stash-link-button">
                        <?php echo metis_escape_html( (string) ( $cat['category_name'] ?? 'Other' ) ); ?>
                    </a>
                </td>
                <td class="metis-premium-cell"><?php echo (int)($cat['item_count'] ?? 0); ?></td>
                <td class="metis-premium-cell"><?php echo (int)($cat['fulfilled'] ?? 0); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $report['by_category'] ) ) : ?>
            <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="3">No data for selected period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </section>

        <!-- Monthly Breakdown -->
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
            <tbody>
            <?php foreach ( ($report['monthly'] ?? []) as $m ) : ?>
            <tr class="metis-premium-row">
                <td class="metis-premium-cell"><?php echo metis_escape_html( (string)($m['month_label'] ?? $m['month'] ?? '') ); ?></td>
                <td class="metis-premium-cell"><?php echo (int)($m['tickets'] ?? 0); ?></td>
                <td class="metis-premium-cell"><?php echo (int)($m['requests'] ?? 0); ?></td>
                <td class="metis-premium-cell"><?php echo (int)($m['donations'] ?? 0); ?></td>
                <td class="metis-premium-cell"><?php echo (int)($m['completed'] ?? 0); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $report['monthly'] ) ) : ?>
            <tr class="metis-premium-row"><td class="metis-premium-cell metis-muted" colspan="5">No data for selected period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </section>

        <!-- By Urgency + By Source side by side -->
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
                <tbody>
                <?php foreach ( ($report['by_urgency'] ?? []) as $u ) : ?>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( (string)($u['urgency'] ?? '') ) ); ?></td>
                    <td class="metis-premium-cell"><?php echo (int)($u['count'] ?? 0); ?></td>
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
                <tbody>
                <?php foreach ( ($report['by_source'] ?? []) as $s ) : ?>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( (string)($s['source'] ?? '') ) ); ?></td>
                    <td class="metis-premium-cell"><?php echo (int)($s['count'] ?? 0); ?></td>
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
                <tbody>
                <?php foreach ( ($report['by_organization'] ?? []) as $row ) : ?>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell">
                        <a href="<?php echo metis_escape_url( $build_inbox_drilldown([
                            'stash_filter'    => 'all',
                            'stash_sort'      => 'submitted_desc',
                            'organization_key'=> (string) ( $row['organization_key'] ?? '' ),
                        ]) ); ?>" class="metis-stash-link-button">
                            <?php echo metis_escape_html( (string) ( $row['organization_name'] ?? 'Independent' ) ); ?>
                        </a>
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
                <tbody>
                <?php foreach ( ($report['by_person'] ?? []) as $row ) : ?>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell">
                        <a href="<?php echo metis_escape_url( $build_inbox_drilldown([
                            'stash_filter' => 'all',
                            'stash_sort'   => 'submitted_desc',
                            'person_key'   => (string) ( $row['person_key'] ?? '' ),
                        ]) ); ?>" class="metis-stash-link-button">
                            <?php echo metis_escape_html( (string) ( $row['person_name'] ?? 'Unknown' ) ); ?>
                        </a>
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
                <tbody>
                <?php foreach ( ($report['by_equipment'] ?? []) as $row ) : ?>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['equipment_name'] ?? 'Other' ) ); ?></td>
                    <td class="metis-premium-cell"><?php echo metis_escape_html( (string) ( $row['category_name'] ?? 'Other' ) ); ?></td>
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
</div>
