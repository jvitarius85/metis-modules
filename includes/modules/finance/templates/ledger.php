<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'metis_finance_view_badge' ) ) {
    function metis_finance_view_badge( string $status ): string {
        $status = strtolower( $status );
        $map = [
            'matched' => [ 'Matched', 'green' ],
            'review'  => [ 'Review', 'red' ],
            'open'    => [ 'Open', 'blue' ],
            'pending' => [ 'Pending', 'muted' ],
            'posted'  => [ 'Posted', 'green' ],
        ];
        if ( ! isset( $map[ $status ] ) ) {
            return '<span class="mw-badge gray">' . esc_html( ucfirst( $status ) ) . '</span>';
        }
        [ $label, $color ] = $map[ $status ];
        return '<span class="mw-badge ' . $color . '">' . esc_html( $label ) . '</span>';
    }
}

if ( ! metis_finance_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view finance activity.</div>';
    return;
}

metis_finance_ensure_schema();
metis_finance_sync_ledger_from_deposits();
metis_finance_sync_reconciliations();

global $wpdb;

$accounts_table = Metis_Tables::get( 'finance_accounts' );
$events_table   = Metis_Tables::get( 'finance_events' );
$funds_table    = Metis_Tables::get( 'finance_funds' );
$can_manage     = metis_finance_can_manage();
$message        = isset( $_GET['activity_saved'] ) ? 'Financial activity recorded.' : '';
$message_type   = 'success';

if ( $can_manage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['metis_finance_ledger_nonce'] ) && metis_verify_nonce( sanitize_text_field( metis_unslash( $_POST['metis_finance_ledger_nonce'] ) ), 'metis_finance_ledger_save' ) ) {
    $result = metis_finance_create_manual_activity(
        [
            'activity_type'   => (string) metis_unslash( $_POST['activity_type'] ?? '' ),
            'amount'          => (string) metis_unslash( $_POST['activity_amount'] ?? '0' ),
            'occurred_at'     => (string) metis_unslash( $_POST['activity_date'] ?? current_time( 'Y-m-d' ) ),
            'fund_id'         => (int) ( $_POST['activity_fund_id'] ?? 0 ),
            'campaign_code'   => (string) metis_unslash( $_POST['activity_campaign_code'] ?? '' ),
            'from_account_key'=> (string) metis_unslash( $_POST['activity_from_account'] ?? 'operating_cash' ),
            'to_account_key'  => (string) metis_unslash( $_POST['activity_to_account'] ?? '' ),
            'direction'       => (string) metis_unslash( $_POST['activity_direction'] ?? 'inflow' ),
            'notes'           => (string) metis_unslash( $_POST['activity_notes'] ?? '' ),
        ]
    );

    if ( metis_is_error( $result ) ) {
        $message = $result->get_error_message();
        $message_type = 'error';
    } else {
        metis_redirect( add_query_arg( [ 'activity_saved' => 1 ], metis_portal_url( 'finance', 'ledger' ) ) );
        exit;
    }
}

$type_filter   = sanitize_key( (string) ( $_GET['activity_type'] ?? '' ) );
$fund_filter   = isset( $_GET['fund_id'] ) ? (int) $_GET['fund_id'] : 0;
$search_filter = sanitize_text_field( (string) ( $_GET['s'] ?? '' ) );
$from_filter   = sanitize_text_field( (string) ( $_GET['from'] ?? '' ) );
$to_filter     = sanitize_text_field( (string) ( $_GET['to'] ?? '' ) );

$funds = $wpdb->get_results( "SELECT id, fund_name, restriction_type FROM {$funds_table} ORDER BY fund_name ASC" ) ?: [];
$accounts = $wpdb->get_results( "SELECT account_key, label FROM {$accounts_table} WHERE is_active = 1 ORDER BY label ASC" ) ?: [];
$activity_types = metis_finance_activity_type_options();

$where = [ '1=1' ];
$params = [];

if ( $type_filter !== '' ) {
    $where[] = 'e.event_type = %s';
    $params[] = $type_filter;
}
if ( $fund_filter > 0 ) {
    $where[] = 'e.fund_id = %d';
    $params[] = $fund_filter;
}
if ( $from_filter !== '' ) {
    $where[] = 'DATE(e.occurred_at) >= %s';
    $params[] = $from_filter;
}
if ( $to_filter !== '' ) {
    $where[] = 'DATE(e.occurred_at) <= %s';
    $params[] = $to_filter;
}
if ( $search_filter !== '' ) {
    $like = '%' . $wpdb->esc_like( $search_filter ) . '%';
    $where[] = '(e.reference_id LIKE %s OR e.notes LIKE %s OR f.fund_name LIKE %s)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode( ' AND ', $where );
$sql = "SELECT e.*, f.fund_name
        FROM {$events_table} e
        LEFT JOIN {$funds_table} f ON f.id = e.fund_id
        WHERE {$where_sql}
        ORDER BY e.occurred_at DESC, e.id DESC
        LIMIT 250";
$activities = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
$activities = $activities ?: [];

$summary_sql = "SELECT
    COUNT(*) AS activity_count,
    COALESCE(SUM(CASE WHEN e.event_type IN ('stripe_charge', 'stripe_payout') THEN e.amount ELSE 0 END), 0) AS inflow_total,
    COALESCE(SUM(CASE WHEN e.event_type NOT IN ('stripe_charge', 'stripe_payout') THEN e.amount ELSE 0 END), 0) AS outflow_total
    FROM {$events_table} e
    LEFT JOIN {$funds_table} f ON f.id = e.fund_id
    WHERE {$where_sql}";
$summary = $params ? $wpdb->get_row( $wpdb->prepare( $summary_sql, $params ) ) : $wpdb->get_row( $summary_sql );
$summary = $summary ?: (object) [ 'activity_count' => 0, 'inflow_total' => 0, 'outflow_total' => 0 ];
?>

<h1 class="mw-page-title">Activity</h1>
<p class="mw-subtitle">Record expenses, checks, transfers, and adjustments in plain language. Metis handles the accounting entries in the background.</p>

<?php if ( $message !== '' ) : ?>
    <div class="mw-alert <?php echo $message_type === 'error' ? 'mw-alert-error' : 'mw-alert-success'; ?>"><?php echo esc_html( $message ); ?></div>
<?php endif; ?>

<div class="metis-finance-stats">
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Activities</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( number_format_i18n( (int) $summary->activity_count ) ); ?></div>
        <div class="metis-finance-stat-note">Money movement records matching the current filters.</div>
    </div>
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Money In</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( metis_finance_currency( (float) $summary->inflow_total ) ); ?></div>
        <div class="metis-finance-stat-note">Donations and settlements captured in this view.</div>
    </div>
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Money Out</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( metis_finance_currency( (float) $summary->outflow_total ) ); ?></div>
        <div class="metis-finance-stat-note">Expenses, refunds, transfers, and adjustments in this view.</div>
    </div>
    <div class="metis-finance-stat">
        <div class="metis-finance-stat-label">Default Fund</div>
        <div class="metis-finance-stat-value"><?php echo esc_html( number_format_i18n( count( $funds ) ) ); ?></div>
        <div class="metis-finance-stat-note">Funds available for reporting and restrictions.</div>
    </div>
</div>

<?php if ( $can_manage ) : ?>
<section class="metis-finance-card">
    <div class="metis-finance-card-header">
        <h2>Record Financial Activity</h2>
        <span class="mw-muted">Plain-language intake form</span>
    </div>
    <div class="metis-finance-card-body">
        <form method="post" class="metis-finance-activity-form">
            <?php metis_nonce_field( 'metis_finance_ledger_save', 'metis_finance_ledger_nonce' ); ?>
            <div class="metis-finance-filter-grid">
                <label class="mw-field">
                    <span>Type</span>
                    <select id="metis-activity-type" name="activity_type" class="mw-input" required>
                        <option value="">Choose activity</option>
                        <?php foreach ( $activity_types as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="mw-field">
                    <span>Amount</span>
                    <input type="number" step="0.01" min="0.01" name="activity_amount" class="mw-input" placeholder="0.00" required>
                </label>
                <label class="mw-field">
                    <span>Date</span>
                    <input type="date" name="activity_date" class="mw-input" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required>
                </label>
                <label class="mw-field">
                    <span>Fund</span>
                    <select name="activity_fund_id" class="mw-input">
                        <?php foreach ( $funds as $fund ) : ?>
                            <option value="<?php echo esc_attr( (string) $fund->id ); ?>" <?php selected( (int) $fund->id, metis_finance_default_fund_id() ); ?>><?php echo esc_html( (string) $fund->fund_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="mw-field">
                    <span>Campaign</span>
                    <input type="text" name="activity_campaign_code" class="mw-input" placeholder="Optional campaign code">
                </label>
                <label class="mw-field">
                    <span><span class="metis-activity-from-label">Paid From</span></span>
                    <select name="activity_from_account" class="mw-input">
                        <?php foreach ( $accounts as $account ) : ?>
                            <option value="<?php echo esc_attr( (string) $account->account_key ); ?>" <?php selected( (string) $account->account_key, 'operating_cash' ); ?>><?php echo esc_html( (string) $account->label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="mw-field metis-activity-destination">
                    <span><span class="metis-activity-to-label">Move To</span></span>
                    <select name="activity_to_account" class="mw-input">
                        <option value="">Not needed</option>
                        <?php foreach ( $accounts as $account ) : ?>
                            <option value="<?php echo esc_attr( (string) $account->account_key ); ?>"><?php echo esc_html( (string) $account->label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="mw-field metis-activity-direction">
                    <span>Adjustment Direction</span>
                    <select name="activity_direction" class="mw-input">
                        <option value="inflow">Increase balance</option>
                        <option value="outflow">Decrease balance</option>
                    </select>
                </label>
                <label class="mw-field metis-finance-filter-search">
                    <span>Notes</span>
                    <input type="text" name="activity_notes" class="mw-input" placeholder="What happened and why">
                </label>
                <div class="metis-finance-actions">
                    <button type="submit" class="mw-btn mw-btn-xs">Record Activity</button>
                </div>
            </div>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="metis-finance-card">
    <div class="metis-finance-card-header">
        <h2>Filters</h2>
        <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_portal_url( 'finance', 'ledger' ) ); ?>">Reset</a>
    </div>
    <div class="metis-finance-card-body">
        <form method="get" class="metis-finance-filter-grid">
            <input type="hidden" name="metis_domain" value="finance">
            <input type="hidden" name="metis_view" value="ledger">
            <label class="mw-field">
                <span>Activity Type</span>
                <select name="activity_type" class="mw-input">
                    <option value="">All activity</option>
                    <?php foreach ( metis_finance_event_types() as $type ) : ?>
                        <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $type_filter, $type ); ?>><?php echo esc_html( metis_finance_activity_label( $type ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="mw-field">
                <span>Fund</span>
                <select name="fund_id" class="mw-input">
                    <option value="0">All funds</option>
                    <?php foreach ( $funds as $fund ) : ?>
                        <option value="<?php echo esc_attr( (string) $fund->id ); ?>" <?php selected( $fund_filter, (int) $fund->id ); ?>><?php echo esc_html( (string) $fund->fund_name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="mw-field">
                <span>From</span>
                <input type="date" name="from" class="mw-input" value="<?php echo esc_attr( $from_filter ); ?>">
            </label>
            <label class="mw-field">
                <span>To</span>
                <input type="date" name="to" class="mw-input" value="<?php echo esc_attr( $to_filter ); ?>">
            </label>
            <label class="mw-field metis-finance-filter-search">
                <span>Search</span>
                <input type="search" name="s" class="mw-input" value="<?php echo esc_attr( $search_filter ); ?>" placeholder="Reference, notes, or fund">
            </label>
            <div class="metis-finance-actions">
                <button type="submit" class="mw-btn mw-btn-xs">Apply Filters</button>
            </div>
        </form>
    </div>
</section>

<section class="metis-finance-card">
    <div class="metis-finance-card-header">
        <h2>Activity Register</h2>
        <span class="mw-muted">Latest 250 records</span>
    </div>
    <div class="metis-finance-card-body">
        <div class="mw-premium-table">
            <div class="mw-premium-row mw-premium-header">
                <div class="mw-premium-cell">Date</div>
                <div class="mw-premium-cell">Activity</div>
                <div class="mw-premium-cell">Fund</div>
                <div class="mw-premium-cell">Reference</div>
                <div class="mw-premium-cell">Notes</div>
                <div class="mw-premium-cell mw-col-numeric">Amount</div>
            </div>
            <?php if ( ! empty( $activities ) ) : ?>
                <?php foreach ( $activities as $activity ) : ?>
                    <?php $is_outflow = ! in_array( (string) $activity->event_type, [ 'stripe_charge', 'stripe_payout' ], true ); ?>
                    <div class="mw-premium-row">
                        <div class="mw-premium-cell"><?php echo esc_html( metis_finance_short_date( (string) $activity->occurred_at ) ); ?></div>
                        <div class="mw-premium-cell">
                            <strong><?php echo esc_html( metis_finance_activity_label( (string) $activity->event_type ) ); ?></strong>
                            <div class="mw-muted"><?php echo esc_html( strtoupper( (string) ( $activity->provider ?? 'manual' ) ) ); ?></div>
                        </div>
                        <div class="mw-premium-cell"><?php echo esc_html( (string) ( $activity->fund_name ?? 'General Fund' ) ); ?></div>
                        <div class="mw-premium-cell"><code><?php echo esc_html( (string) $activity->reference_id ); ?></code></div>
                        <div class="mw-premium-cell"><?php echo esc_html( (string) ( $activity->notes ?? '—' ) ); ?></div>
                        <div class="mw-premium-cell mw-col-numeric <?php echo $is_outflow ? 'metis-finance-negative' : ''; ?>"><?php echo esc_html( ( $is_outflow ? '-$' : '$' ) . number_format_i18n( abs( (float) $activity->amount ), 2 ) ); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="mw-premium-row"><div class="mw-premium-cell mw-muted">No activity matched the current filters.</div></div>
            <?php endif; ?>
        </div>
    </div>
</section>
