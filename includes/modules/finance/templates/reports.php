<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_finance_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view finance insights.</div>';
    return;
}

metis_finance_ensure_schema();
metis_finance_sync_ledger_from_deposits();
metis_finance_sync_reconciliations();

global $wpdb;

$accounts_table = Metis_Tables::get( 'finance_accounts' );
$ledger_table   = Metis_Tables::get( 'finance_ledger' );
$recons_table   = Metis_Tables::get( 'finance_reconciliations' );

$now_ts         = current_time( 'timestamp' );
$default_start  = metis_date( 'Y-01-01', $now_ts );
$default_end    = metis_date( 'Y-m-d', $now_ts );
$start_filter   = sanitize_text_field( (string) ( $_GET['start'] ?? $default_start ) );
$end_filter     = sanitize_text_field( (string) ( $_GET['end'] ?? $default_end ) );
$account_filter = sanitize_key( (string) ( $_GET['account'] ?? '' ) );
$group_filter   = sanitize_key( (string) ( $_GET['group'] ?? 'month' ) );
$status_filter  = sanitize_key( (string) ( $_GET['entry_status'] ?? '' ) );

if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_filter ) ) {
    $start_filter = $default_start;
}
if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_filter ) ) {
    $end_filter = $default_end;
}
if ( ! in_array( $group_filter, [ 'month', 'account', 'source' ], true ) ) {
    $group_filter = 'month';
}

$accounts = $wpdb->get_results( "SELECT account_key, label FROM {$accounts_table} WHERE is_active = 1 ORDER BY label ASC" ) ?: [];

$where = [ 'l.entry_date >= %s', 'l.entry_date <= %s' ];
$params = [ $start_filter, $end_filter ];

if ( $account_filter !== '' ) {
    $where[] = 'l.account_key = %s';
    $params[] = $account_filter;
}
if ( $status_filter !== '' ) {
    $where[] = 'l.status = %s';
    $params[] = $status_filter;
}

$where_sql = implode( ' AND ', $where );

$kpi_sql = "SELECT
    COALESCE(SUM(CASE WHEN l.direction = 'inflow' THEN l.amount ELSE 0 END), 0) AS inflow_total,
    COALESCE(SUM(CASE WHEN l.direction = 'outflow' THEN l.amount ELSE 0 END), 0) AS outflow_total,
    COALESCE(COUNT(DISTINCT CASE WHEN e.provider = 'manual' THEN e.id ELSE NULL END), 0) AS manual_count,
    COALESCE(SUM(CASE WHEN l.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
    COUNT(*) AS entry_count
    FROM {$ledger_table} l
    LEFT JOIN " . Metis_Tables::get( 'finance_events' ) . " e ON e.id = l.event_id
    WHERE {$where_sql}";
$kpis = $wpdb->get_row( $wpdb->prepare( $kpi_sql, $params ) );
$kpis = $kpis ?: (object) [ 'inflow_total' => 0, 'outflow_total' => 0, 'manual_count' => 0, 'pending_count' => 0, 'entry_count' => 0 ];
$net_total = (float) $kpis->inflow_total - (float) $kpis->outflow_total;

switch ( $group_filter ) {
    case 'account':
        $group_select = 'COALESCE(a.label, l.account_key)';
        $group_by     = 'l.account_key, a.label';
        $group_label  = 'Account';
        break;
    case 'source':
        $group_select = 'UPPER(l.source_type)';
        $group_by     = 'l.source_type';
        $group_label  = 'Source';
        break;
    case 'month':
    default:
        $group_select = "DATE_FORMAT(l.entry_date, '%Y-%m')";
        $group_by     = "DATE_FORMAT(l.entry_date, '%Y-%m')";
        $group_label  = 'Period';
        break;
}

$summary_sql = "SELECT
    {$group_select} AS group_value,
    COALESCE(SUM(CASE WHEN l.direction = 'inflow' THEN l.amount ELSE 0 END), 0) AS inflow_total,
    COALESCE(SUM(CASE WHEN l.direction = 'outflow' THEN l.amount ELSE 0 END), 0) AS outflow_total,
    COUNT(*) AS entry_count
    FROM {$ledger_table} l
    LEFT JOIN {$accounts_table} a ON a.account_key = l.account_key
    WHERE {$where_sql}
    GROUP BY {$group_by}
    ORDER BY group_value ASC
    LIMIT 100";
$summary_rows = $wpdb->get_results( $wpdb->prepare( $summary_sql, $params ) ) ?: [];

$recon_summary = $wpdb->get_results( $wpdb->prepare(
    "SELECT r.status, COUNT(*) AS row_count
     FROM {$recons_table} r
     WHERE r.period_start >= %s AND r.period_end <= %s
     GROUP BY r.status
     ORDER BY r.status ASC",
    $start_filter,
    $end_filter
) ) ?: [];

$recent_variances = $wpdb->get_results( $wpdb->prepare(
    "SELECT r.account_key, r.period_start, r.period_end, r.book_balance, r.statement_balance, r.variance, r.status, a.label AS account_label
     FROM {$recons_table} r
     LEFT JOIN {$accounts_table} a ON a.account_key = r.account_key
     WHERE r.period_start >= %s AND r.period_end <= %s
     ORDER BY ABS(COALESCE(r.variance, 0)) DESC, r.period_start DESC
     LIMIT 8",
    $start_filter,
    $end_filter
) ) ?: [];
?>

<h1 class="mw-page-title">Insights</h1>
<p class="mw-subtitle">Summaries for money in, money out, and reconciliation health across any reporting window.</p>

<div class="metis-finance-stats">
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Inflows</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( metis_finance_currency( (float) $kpis->inflow_total ) ); ?></div>
        <div class="metis-finance-stat-note">All money in recorded during the selected window.</div>
    </div>
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Outflows</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( metis_finance_currency( (float) $kpis->outflow_total ) ); ?></div>
        <div class="metis-finance-stat-note">Expenses, fees, refunds, and other money out.</div>
    </div>
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Net Movement</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( metis_finance_currency( $net_total ) ); ?></div>
        <div class="metis-finance-stat-note"><?php echo esc_html( number_format_i18n( (int) $kpis->entry_count ) ); ?> posted ledger lines in scope.</div>
    </div>
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Manual Activities</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( number_format_i18n( (int) $kpis->manual_count ) ); ?></div>
        <div class="metis-finance-stat-note"><?php echo esc_html( number_format_i18n( (int) $kpis->pending_count ) ); ?> entries still pending.</div>
    </div>
</div>

<section class="metis-finance-card">
    <div class="metis-finance-card-header">
        <h2>Filters</h2>
        <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_portal_url( 'finance', 'reports' ) ); ?>">Reset</a>
    </div>
    <div class="metis-finance-card-body">
        <form method="get" class="metis-finance-filter-grid">
            <input type="hidden" name="metis_domain" value="finance">
            <input type="hidden" name="metis_view" value="reports">
            <label class="mw-field">
                <span>Start</span>
                <input type="date" name="start" class="mw-input" value="<?php echo esc_attr( $start_filter ); ?>">
            </label>
            <label class="mw-field">
                <span>End</span>
                <input type="date" name="end" class="mw-input" value="<?php echo esc_attr( $end_filter ); ?>">
            </label>
            <label class="mw-field">
                <span>Account</span>
                <select name="account" class="mw-input">
                    <option value="">All accounts</option>
                    <?php foreach ( $accounts as $account ) : ?>
                        <option value="<?php echo esc_attr( (string) $account->account_key ); ?>" <?php selected( $account_filter, (string) $account->account_key ); ?>><?php echo esc_html( (string) $account->label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="mw-field">
                <span>Status</span>
                <select name="entry_status" class="mw-input">
                    <option value="">All statuses</option>
                    <option value="posted" <?php selected( $status_filter, 'posted' ); ?>>Posted</option>
                    <option value="pending" <?php selected( $status_filter, 'pending' ); ?>>Pending</option>
                    <option value="review" <?php selected( $status_filter, 'review' ); ?>>Review</option>
                </select>
            </label>
            <label class="mw-field">
                <span>Group By</span>
                <select name="group" class="mw-input">
                    <option value="month" <?php selected( $group_filter, 'month' ); ?>>Month</option>
                    <option value="account" <?php selected( $group_filter, 'account' ); ?>>Account</option>
                    <option value="source" <?php selected( $group_filter, 'source' ); ?>>Source</option>
                </select>
            </label>
            <div class="metis-finance-actions">
                <button type="submit" class="mw-btn mw-btn-xs">Run Insight</button>
                <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_portal_url( 'finance', 'ledger' ) ); ?>">Open Activity</a>
            </div>
        </form>
    </div>
</section>

<div class="metis-finance-grid">
    <section class="metis-finance-card">
        <div class="metis-finance-card-header">
            <h2>Activity Summary</h2>
            <span class="mw-muted">Grouped by <?php echo esc_html( strtolower( $group_label ) ); ?></span>
        </div>
        <div class="metis-finance-card-body">
            <div class="mw-premium-table">
                <div class="mw-premium-row mw-premium-header">
                    <div class="mw-premium-cell"><?php echo esc_html( $group_label ); ?></div>
                    <div class="mw-premium-cell mw-col-numeric">Inflows</div>
                    <div class="mw-premium-cell mw-col-numeric">Outflows</div>
                    <div class="mw-premium-cell mw-col-numeric">Net</div>
                    <div class="mw-premium-cell mw-col-numeric">Entries</div>
                </div>
                <?php if ( ! empty( $summary_rows ) ) : ?>
                    <?php foreach ( $summary_rows as $row ) : ?>
                        <?php $row_net = (float) $row->inflow_total - (float) $row->outflow_total; ?>
                        <div class="mw-premium-row">
                            <div class="mw-premium-cell"><?php echo esc_html( (string) $row->group_value ); ?></div>
                            <div class="mw-premium-cell mw-col-numeric"><?php echo esc_html( metis_finance_currency( (float) $row->inflow_total ) ); ?></div>
                            <div class="mw-premium-cell mw-col-numeric"><?php echo esc_html( metis_finance_currency( (float) $row->outflow_total ) ); ?></div>
                            <div class="mw-premium-cell mw-col-numeric <?php echo $row_net < 0 ? 'metis-finance-negative' : ''; ?>"><?php echo esc_html( metis_finance_currency( $row_net ) ); ?></div>
                            <div class="mw-premium-cell mw-col-numeric"><?php echo esc_html( number_format_i18n( (int) $row->entry_count ) ); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No finance data matched the current filters.</div></div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="metis-finance-card">
        <div class="metis-finance-card-header">
            <h2>Reconciliation Status</h2>
            <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_portal_url( 'finance', 'reconciliations' ) ); ?>">Open Reconcile</a>
        </div>
        <div class="metis-finance-card-body">
            <div class="metis-finance-list">
                <?php if ( ! empty( $recon_summary ) ) : ?>
                    <?php foreach ( $recon_summary as $row ) : ?>
                        <div class="metis-finance-list-row">
                            <div>
                                <div class="metis-finance-list-title"><?php echo esc_html( ucfirst( (string) $row->status ) ); ?></div>
                                <div class="metis-finance-list-meta">Reconciliation periods in the selected date range.</div>
                            </div>
                            <div class="metis-finance-list-amount"><?php echo esc_html( number_format_i18n( (int) $row->row_count ) ); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="metis-finance-empty">No reconciliation periods fell inside the selected reporting window.</div>
                <?php endif; ?>
            </div>

            <div class="metis-finance-card-header" style="margin:18px -18px 0; border-left:0; border-right:0;">
                <h2>Largest Variances</h2>
                <span class="mw-muted">Highest absolute recon gaps</span>
            </div>

            <div class="metis-finance-list" style="margin-top:16px;">
                <?php if ( ! empty( $recent_variances ) ) : ?>
                    <?php foreach ( $recent_variances as $row ) : ?>
                        <div class="metis-finance-list-row">
                            <div>
                                <div class="metis-finance-list-title"><?php echo esc_html( (string) ( $row->account_label ?? $row->account_key ) ); ?></div>
                                <div class="metis-finance-list-meta"><?php echo esc_html( metis_date( 'M Y', strtotime( (string) $row->period_start ) ) ); ?> · Book <?php echo esc_html( metis_finance_currency( (float) $row->book_balance ) ); ?> · Statement <?php echo esc_html( $row->statement_balance !== null ? metis_finance_currency( (float) $row->statement_balance ) : '—' ); ?></div>
                            </div>
                            <div class="metis-finance-list-amount <?php echo ( (float) ( $row->variance ?? 0 ) ) < 0 ? 'metis-finance-negative' : ''; ?>"><?php echo esc_html( $row->variance !== null ? metis_finance_currency( (float) $row->variance ) : '—' ); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="metis-finance-empty">No reconciliation variance data is available yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
