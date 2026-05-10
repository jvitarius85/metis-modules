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
?>

<div class="metis-stash-app metis-stash-reports">

    <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_view_title( "Grandy's Stash Reports" ) ); ?></h1>
    <p class="metis-subtitle">Grant-ready reporting on tickets, people served, and equipment distributed.</p>
    <div id="metis-stash-alert" class="metis-alert" style="display:none;"></div>

    <div class="metis-people-stats metis-stash-stats">
        <article class="metis-people-stat"><div class="metis-people-stat-label">Total Tickets</div><div class="metis-people-stat-value"><?php echo (int)($summary['total_tickets'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">People Served</div><div class="metis-people-stat-value"><?php echo (int)($report['people_served'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Items Fulfilled</div><div class="metis-people-stat-value"><?php echo (int)($report['items_fulfilled'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Completed</div><div class="metis-people-stat-value"><?php echo (int)($summary['completed'] ?? 0); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Avg Days</div><div class="metis-people-stat-value"><?php echo metis_escape_html( (string)($report['avg_days_to_complete'] ?? '—') ); ?></div></article>
        <article class="metis-people-stat"><div class="metis-people-stat-label">Open</div><div class="metis-people-stat-value"><?php echo (int)($summary['open_tickets'] ?? 0); ?></div></article>
    </div>

    <?php metis_render_sidebar_layout([
        'sidebar' => static function () { ?>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Date Range</div>
                <label style="display:grid;gap:4px;font-size:13px;color:#67798b;">From
                    <input id="metis-stash-report-from" class="metis-input" type="date">
                </label>
                <label style="display:grid;gap:4px;font-size:13px;color:#67798b;margin-top:6px;">To
                    <input id="metis-stash-report-to" class="metis-input" type="date">
                </label>
                <button type="button" class="metis-btn metis-btn-xs" id="metis-stash-report-run" style="margin-top:8px;">Run Report</button>
            </div>
            <div class="metis-list-sidebar-section">
                <div class="metis-list-sidebar-label">Navigation</div>
                <nav class="metis-list-sidebar-nav" aria-label="Grandy's Stash navigation">
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>" class="metis-list-sidebar-nav-item">Inbox</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="metis-list-sidebar-nav-item is-active">Reports</a>
                    <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/settings/' ); ?>" class="metis-list-sidebar-nav-item">Settings</a>
                </nav>
            </div>
        <?php },
        'content' => static function () use ( $report ) { ?>

    <div id="metis-stash-report-content">

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
                <td class="metis-premium-cell"><?php echo metis_escape_html( ucfirst( str_replace( '_', ' ', (string)($cat['category'] ?? '') ) ) ); ?></td>
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
                <td class="metis-premium-cell"><?php echo metis_escape_html( date( 'M Y', strtotime( (string)($m['month'] ?? '') . '-01' ) ) ); ?></td>
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

    </div>

        <?php },
    ]); ?>
</div>
