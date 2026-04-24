<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

if ( ! metis_grandys_stash_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view Grandy&apos;s Stash.</div>';
    return;
}

$from = metis_text_clean( (string) ( $_GET['from'] ?? '' ) );
$to   = metis_text_clean( (string) ( $_GET['to'] ?? '' ) );
$report = \Metis\Modules\GrandyStash\GrandyStashRepository::reportData( $from, $to );
$summary = $report['summary'] ?? [];
?>

<div class="metis-stash-app metis-stash-reports">

    <h1 class="mw-page-title"><?php echo metis_escape_html( metis_current_module_view_title( "Grandy's Stash Reports" ) ); ?></h1>
    <p class="mw-subtitle">Grant-ready reporting on tickets, people served, and equipment distributed.</p>
    <div id="metis-stash-alert" class="mw-alert" style="display:none;"></div>

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
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Date Range</div>
                <label style="display:grid;gap:4px;font-size:13px;color:#67798b;">From
                    <input id="metis-stash-report-from" class="mw-input" type="date">
                </label>
                <label style="display:grid;gap:4px;font-size:13px;color:#67798b;margin-top:6px;">To
                    <input id="metis-stash-report-to" class="mw-input" type="date">
                </label>
                <button type="button" class="mw-btn mw-btn-xs" id="metis-stash-report-run" style="margin-top:8px;">Run Report</button>
            </div>
            <div class="mw-list-sidebar-section">
                <div class="mw-list-sidebar-label">Navigation</div>
                <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() ); ?>" class="mw-btn mw-btn-xs mw-btn-ghost">Inbox</a>
                <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/reports/' ); ?>" class="mw-btn mw-btn-xs">Reports</a>
                <a href="<?php echo metis_escape_url( metis_grandys_stash_base_url() . '/settings/' ); ?>" class="mw-btn mw-btn-xs mw-btn-ghost">Settings</a>
            </div>
        <?php },
        'content' => static function () use ( $report ) { ?>

    <div id="metis-stash-report-content">

        <!-- By Category -->
        <section style="margin-bottom:28px;">
            <h2 style="font-size:16px;margin:0 0 12px;">Items by Category</h2>
            <div class="mw-premium-table metis-stash-report-cat-table">
                <div class="mw-premium-header">
                    <div class="mw-premium-cell">Category</div>
                    <div class="mw-premium-cell">Total Items</div>
                    <div class="mw-premium-cell">Fulfilled</div>
                </div>
                <?php foreach ( ($report['by_category'] ?? []) as $cat ) : ?>
                <div class="mw-premium-row">
                    <div class="mw-premium-cell"><?php echo metis_escape_html( ucfirst( str_replace( '_', ' ', (string)($cat['category'] ?? '') ) ) ); ?></div>
                    <div class="mw-premium-cell"><?php echo (int)($cat['item_count'] ?? 0); ?></div>
                    <div class="mw-premium-cell"><?php echo (int)($cat['fulfilled'] ?? 0); ?></div>
                </div>
                <?php endforeach; ?>
                <?php if ( empty( $report['by_category'] ) ) : ?>
                <div class="mw-premium-row"><div class="mw-premium-cell mw-muted" style="grid-column:1/-1;">No data for selected period.</div></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Monthly Breakdown -->
        <section style="margin-bottom:28px;">
            <h2 style="font-size:16px;margin:0 0 12px;">Monthly Breakdown</h2>
            <div class="mw-premium-table metis-stash-report-month-table">
                <div class="mw-premium-header">
                    <div class="mw-premium-cell">Month</div>
                    <div class="mw-premium-cell">Tickets</div>
                    <div class="mw-premium-cell">Requests</div>
                    <div class="mw-premium-cell">Donations</div>
                    <div class="mw-premium-cell">Completed</div>
                </div>
                <?php foreach ( ($report['monthly'] ?? []) as $m ) : ?>
                <div class="mw-premium-row">
                    <div class="mw-premium-cell"><?php echo metis_escape_html( date( 'M Y', strtotime( (string)($m['month'] ?? '') . '-01' ) ) ); ?></div>
                    <div class="mw-premium-cell"><?php echo (int)($m['tickets'] ?? 0); ?></div>
                    <div class="mw-premium-cell"><?php echo (int)($m['requests'] ?? 0); ?></div>
                    <div class="mw-premium-cell"><?php echo (int)($m['donations'] ?? 0); ?></div>
                    <div class="mw-premium-cell"><?php echo (int)($m['completed'] ?? 0); ?></div>
                </div>
                <?php endforeach; ?>
                <?php if ( empty( $report['monthly'] ) ) : ?>
                <div class="mw-premium-row"><div class="mw-premium-cell mw-muted" style="grid-column:1/-1;">No data for selected period.</div></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- By Urgency + By Source side by side -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
            <section>
                <h2 style="font-size:16px;margin:0 0 12px;">By Urgency</h2>
                <div class="mw-premium-table metis-stash-report-small-table">
                    <div class="mw-premium-header">
                        <div class="mw-premium-cell">Urgency</div>
                        <div class="mw-premium-cell">Count</div>
                    </div>
                    <?php foreach ( ($report['by_urgency'] ?? []) as $u ) : ?>
                    <div class="mw-premium-row">
                        <div class="mw-premium-cell"><?php echo metis_escape_html( ucfirst( (string)($u['urgency'] ?? '') ) ); ?></div>
                        <div class="mw-premium-cell"><?php echo (int)($u['count'] ?? 0); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <section>
                <h2 style="font-size:16px;margin:0 0 12px;">By Source</h2>
                <div class="mw-premium-table metis-stash-report-small-table">
                    <div class="mw-premium-header">
                        <div class="mw-premium-cell">Source</div>
                        <div class="mw-premium-cell">Count</div>
                    </div>
                    <?php foreach ( ($report['by_source'] ?? []) as $s ) : ?>
                    <div class="mw-premium-row">
                        <div class="mw-premium-cell"><?php echo metis_escape_html( ucfirst( (string)($s['source'] ?? '') ) ); ?></div>
                        <div class="mw-premium-cell"><?php echo (int)($s['count'] ?? 0); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

    </div>

        <?php },
    ]); ?>
</div>
