<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! metis_finance_can_view() ) {
    echo '<div class="mw-alert mw-alert-error">You do not have permission to view finance.</div>';
    return;
}

metis_finance_ensure_schema();
metis_finance_sync_ledger_from_deposits();
metis_finance_sync_reconciliations();

global $wpdb;

$transactions_table = Metis_Tables::get( 'transactions' );
$deposits_table     = Metis_Tables::get( 'deposits' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );
$accounts_table     = Metis_Tables::get( 'finance_accounts' );
$events_table       = Metis_Tables::get( 'finance_events' );
$funds_table        = Metis_Tables::get( 'finance_funds' );
$ledger_table       = Metis_Tables::get( 'finance_ledger' );
$recons_table       = Metis_Tables::get( 'finance_reconciliations' );
$can_manage         = metis_finance_can_manage();
$now_ts             = current_time( 'timestamp' );
$today              = current_time( 'Y-m-d' );
$month_start        = metis_date( 'Y-m-01 00:00:00', $now_ts );
$year_start         = metis_date( 'Y-01-01 00:00:00', $now_ts );
$current_year       = metis_date( 'Y', $now_ts );

$has_transactions = metis_finance_table_exists( $transactions_table );
$has_deposits     = metis_finance_table_exists( $deposits_table );
$has_campaigns    = metis_finance_table_exists( $campaigns_table );
$has_accounts     = metis_finance_table_exists( $accounts_table );
$has_events       = metis_finance_table_exists( $events_table );
$has_funds        = metis_finance_table_exists( $funds_table );
$has_ledger       = metis_finance_table_exists( $ledger_table );
$has_recons       = metis_finance_table_exists( $recons_table );

$txn_summary = (object) [
    'gross_total'       => 0,
    'month_total'       => 0,
    'year_total'        => 0,
    'undeposited_total' => 0,
    'undeposited_count' => 0,
];

if ( $has_transactions ) {
    $txn_summary = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status NOT IN ('failed', 'voided') THEN amount ELSE 0 END), 0) AS gross_total,
            COALESCE(SUM(CASE WHEN status NOT IN ('failed', 'voided') AND tran_date >= %s THEN amount ELSE 0 END), 0) AS month_total,
            COALESCE(SUM(CASE WHEN status NOT IN ('failed', 'voided') AND tran_date >= %s THEN amount ELSE 0 END), 0) AS year_total,
            COALESCE(SUM(CASE WHEN status NOT IN ('failed', 'voided') AND (deposit_batch_id IS NULL OR deposit_batch_id = '') THEN amount ELSE 0 END), 0) AS undeposited_total,
            COALESCE(SUM(CASE WHEN status NOT IN ('failed', 'voided') AND (deposit_batch_id IS NULL OR deposit_batch_id = '') THEN 1 ELSE 0 END), 0) AS undeposited_count
         FROM {$transactions_table}",
        $month_start,
        $year_start
    ) );
}

$deposit_summary = (object) [
    'month_net'     => 0,
    'month_count'   => 0,
    'pending_count' => 0,
    'pending_total' => 0,
];

$recent_deposits = [];

if ( $has_deposits ) {
    $deposit_summary = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN deposit_date >= %s THEN COALESCE(net_total, total_amount, 0) ELSE 0 END), 0) AS month_net,
            COALESCE(SUM(CASE WHEN deposit_date >= %s THEN 1 ELSE 0 END), 0) AS month_count,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'in_transit') THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'in_transit') THEN COALESCE(net_total, total_amount, 0) ELSE 0 END), 0) AS pending_total
         FROM {$deposits_table}",
        $month_start,
        $month_start
    ) );

    $recent_deposits = $wpdb->get_results(
        "SELECT provider_ref, provider, status, deposit_date, transaction_count, COALESCE(net_total, total_amount, 0) AS display_total
         FROM {$deposits_table}
         ORDER BY deposit_date DESC, id DESC
         LIMIT 6"
    ) ?: [];
}

$campaign_summary = (object) [
    'active_count'  => 0,
    'active_raised' => 0,
    'largest_name'  => '',
];

$campaign_rows = [];
$fund_summary = (object) [
    'total_funds'       => 0,
    'restricted_funds'  => 0,
    'restricted_total'  => 0,
    'unrestricted_total'=> 0,
];
$fund_rows = [];

if ( $has_campaigns && $has_transactions ) {
    $campaign_summary = $wpdb->get_row(
        "SELECT
            COALESCE(SUM(CASE WHEN c.active = 1 THEN 1 ELSE 0 END), 0) AS active_count,
            COALESCE(SUM(CASE WHEN c.active = 1 THEN IFNULL(tx.total_raised, 0) ELSE 0 END), 0) AS active_raised
         FROM {$campaigns_table} c
         LEFT JOIN (
            SELECT campaign_code, SUM(amount) AS total_raised
            FROM {$transactions_table}
            WHERE status NOT IN ('failed', 'voided')
            GROUP BY campaign_code
         ) tx ON tx.campaign_code = c.cid"
    );

    $campaign_rows = $wpdb->get_results(
        "SELECT
            c.cid,
            c.cname,
            c.type,
            c.active,
            COALESCE(tx.total_raised, 0) AS total_raised,
            tx.last_gift_date
         FROM {$campaigns_table} c
         LEFT JOIN (
            SELECT campaign_code, SUM(amount) AS total_raised, MAX(tran_date) AS last_gift_date
            FROM {$transactions_table}
            WHERE status NOT IN ('failed', 'voided')
            GROUP BY campaign_code
         ) tx ON tx.campaign_code = c.cid
         ORDER BY total_raised DESC, c.cname ASC
         LIMIT 5"
    ) ?: [];

    if ( ! empty( $campaign_rows ) ) {
        $campaign_summary->largest_name = (string) ( $campaign_rows[0]->cname ?? '' );
    }
}

if ( $has_funds && $has_events ) {
    $fund_summary = $wpdb->get_row(
        "SELECT
            COUNT(*) AS total_funds,
            COALESCE(SUM(CASE WHEN restriction_type <> 'unrestricted' THEN 1 ELSE 0 END), 0) AS restricted_funds
         FROM {$funds_table}"
    );

    $fund_totals = $wpdb->get_row(
        "SELECT
            COALESCE(SUM(CASE WHEN f.restriction_type = 'unrestricted' THEN signed.amount ELSE 0 END), 0) AS unrestricted_total,
            COALESCE(SUM(CASE WHEN f.restriction_type <> 'unrestricted' THEN signed.amount ELSE 0 END), 0) AS restricted_total
         FROM {$funds_table} f
         LEFT JOIN (
            SELECT fund_id, SUM(CASE WHEN direction = 'outflow' THEN -amount ELSE amount END) AS amount
            FROM {$ledger_table}
            WHERE status = 'posted'
            GROUP BY fund_id
         ) signed ON signed.fund_id = f.id"
    );

    if ( $fund_totals ) {
        $fund_summary->unrestricted_total = (float) ( $fund_totals->unrestricted_total ?? 0 );
        $fund_summary->restricted_total   = (float) ( $fund_totals->restricted_total ?? 0 );
    }

    $fund_rows = $wpdb->get_results(
        "SELECT
            f.id,
            f.fund_name,
            f.restriction_type,
            COALESCE(SUM(CASE WHEN l.status = 'posted' AND l.direction = 'outflow' THEN -l.amount WHEN l.status = 'posted' THEN l.amount ELSE 0 END), 0) AS balance
         FROM {$funds_table} f
         LEFT JOIN {$ledger_table} l ON l.fund_id = f.id
         GROUP BY f.id, f.fund_name, f.restriction_type
         ORDER BY balance DESC, f.fund_name ASC
         LIMIT 5"
    ) ?: [];
}

$settlement_rows = [];

if ( $has_transactions ) {
    $settlement_rows = $wpdb->get_results(
        "SELECT tid, campaign_code, amount, tran_date, status
         FROM {$transactions_table}
         WHERE status NOT IN ('failed', 'voided')
           AND (deposit_batch_id IS NULL OR deposit_batch_id = '')
         ORDER BY tran_date DESC, id DESC
         LIMIT 8"
    ) ?: [];
}

$account_rows = [];
$ledger_rows  = [];
$recon_rows   = [];
$open_recons  = 0;
$bank_balance = 0.0;
$stripe_balance = 0.0;

if ( $has_accounts && $has_ledger ) {
    $account_rows = $wpdb->get_results(
        "SELECT
            a.account_key,
            a.label,
            a.category,
            COALESCE(SUM(CASE WHEN l.status = 'posted' AND l.direction = 'outflow' THEN -l.amount WHEN l.status = 'posted' THEN l.amount ELSE 0 END), 0) AS balance
         FROM {$accounts_table} a
         LEFT JOIN {$ledger_table} l ON l.account_key = a.account_key
         WHERE a.is_active = 1
         GROUP BY a.id, a.account_key, a.label, a.category
         ORDER BY FIELD(a.account_key, 'operating_cash', 'stripe_clearing', 'contributions_revenue', 'processing_fees'), a.label ASC"
    ) ?: [];

    $ledger_rows = $wpdb->get_results(
        "SELECT l.entry_date, l.account_key, l.source_type, l.source_ref, l.direction, l.amount, l.memo, l.status, a.label AS account_label
         FROM {$ledger_table} l
         LEFT JOIN {$accounts_table} a ON a.account_key = l.account_key
         ORDER BY l.entry_date DESC, l.id DESC
         LIMIT 8"
    ) ?: [];

    foreach ( $account_rows as $account_row ) {
        if ( (string) $account_row->account_key === 'operating_cash' ) {
            $bank_balance = (float) $account_row->balance;
        }
        if ( (string) $account_row->account_key === 'stripe_clearing' ) {
            $stripe_balance = (float) $account_row->balance;
        }
    }
}

if ( $has_recons ) {
    $recon_rows = $wpdb->get_results(
        "SELECT r.account_key, r.period_start, r.period_end, r.book_balance, r.statement_balance, r.variance, r.matched_count, r.status, a.label AS account_label
         FROM {$recons_table} r
         LEFT JOIN {$accounts_table} a ON a.account_key = r.account_key
         ORDER BY r.period_start DESC, r.id DESC
         LIMIT 8"
    ) ?: [];

    $open_recons = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$recons_table} WHERE status <> 'matched'" );
}

$donations_url    = metis_portal_url( 'donations', 'dashboard' );
$deposits_url     = metis_portal_url( 'donations', 'deposits' );
$campaigns_url    = metis_portal_url( 'donations', 'campaigns' );
$transactions_url = metis_portal_url( 'donations', 'transactions' );
?>

<div class="metis-finance" data-can-manage="<?php echo esc_attr( $can_manage ? '1' : '0' ); ?>">
    <section class="metis-finance-hero">
        <h1 class="mw-page-title">Finance</h1>
        <p class="mw-subtitle">Start here for a quick read on available cash, pending settlement, restricted funds, and follow-up items.</p>
        <div class="metis-finance-hero-meta">
            <span class="metis-finance-pill">Cash available: <?php echo esc_html( metis_finance_currency( $bank_balance ) ); ?></span>
            <span class="metis-finance-pill">Stripe pending: <?php echo esc_html( metis_finance_currency( $stripe_balance ) ); ?></span>
            <span class="metis-finance-pill">Restricted funds: <?php echo esc_html( metis_finance_currency( (float) ( $fund_summary->restricted_total ?? 0 ) ) ); ?></span>
            <span class="metis-finance-pill">Needs follow-up: <?php echo esc_html( number_format_i18n( $open_recons + (int) ( $txn_summary->undeposited_count ?? 0 ) ) ); ?></span>
        </div>
    </section>

    <div class="metis-finance-stats">
        <div class="metis-finance-stat">
            <div class="metis-finance-stat-label">Cash Available</div>
            <div class="metis-finance-stat-value"><?php echo esc_html( metis_finance_currency( $bank_balance ) ); ?></div>
            <div class="metis-finance-stat-note">Current posted balance in the main bank account.</div>
        </div>
        <div class="metis-finance-stat">
            <div class="metis-finance-stat-label">Awaiting Settlement</div>
            <div class="metis-finance-stat-value"><?php echo esc_html( metis_finance_currency( $stripe_balance ) ); ?></div>
            <div class="metis-finance-stat-note">Money still sitting in Stripe clearing or on the way to the bank.</div>
        </div>
        <div class="metis-finance-stat">
            <div class="metis-finance-stat-label">Restricted Funds</div>
            <div class="metis-finance-stat-value"><?php echo esc_html( metis_finance_currency( (float) ( $fund_summary->restricted_total ?? 0 ) ) ); ?></div>
            <div class="metis-finance-stat-note"><?php echo esc_html( number_format_i18n( (int) ( $fund_summary->restricted_funds ?? 0 ) ) ); ?> funds carry restrictions or designations.</div>
        </div>
        <div class="metis-finance-stat">
            <div class="metis-finance-stat-label">Needs Follow-Up</div>
            <div class="metis-finance-stat-value"><?php echo esc_html( number_format_i18n( $open_recons + (int) ( $txn_summary->undeposited_count ?? 0 ) ) ); ?></div>
            <div class="metis-finance-stat-note"><?php echo esc_html( number_format_i18n( $open_recons ) ); ?> reconciliation periods and <?php echo esc_html( number_format_i18n( (int) ( $txn_summary->undeposited_count ?? 0 ) ) ); ?> unsettled donations need review.</div>
        </div>
    </div>

    <div class="metis-finance-grid">
        <section class="metis-finance-card">
            <div class="metis-finance-card-header">
                <h2>Where Money Sits</h2>
                <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_portal_url( 'finance', 'ledger' ) ); ?>">Open activity</a>
            </div>
            <div class="metis-finance-card-body">
                <div class="metis-finance-split">
                    <div class="metis-finance-kpi">
                        <div class="metis-finance-kpi-label">Bank Account</div>
                        <div class="metis-finance-kpi-value"><?php echo esc_html( metis_finance_currency( $bank_balance ) ); ?></div>
                        <div class="metis-finance-kpi-sub">Money currently available to spend.</div>
                    </div>
                    <div class="metis-finance-kpi">
                        <div class="metis-finance-kpi-label">Stripe Clearing</div>
                        <div class="metis-finance-kpi-value"><?php echo esc_html( metis_finance_currency( $stripe_balance ) ); ?></div>
                        <div class="metis-finance-kpi-sub">Donations recorded but not yet fully settled to bank.</div>
                    </div>
                </div>

                <div class="metis-finance-card-header" style="margin:18px -18px 0; border-left:0; border-right:0;">
                    <h2>Recent Settlements</h2>
                    <span class="mw-muted">Latest payouts and deposits</span>
                </div>

                <div class="metis-finance-list" style="margin-top:16px;">
                    <?php if ( ! empty( $recent_deposits ) ) : ?>
                        <?php foreach ( $recent_deposits as $deposit ) : ?>
                            <div class="metis-finance-list-row">
                                <div>
                                    <div class="metis-finance-list-title"><?php echo esc_html( (string) ( $deposit->provider_ref ?? 'Deposit' ) ); ?></div>
                                    <div class="metis-finance-list-meta">
                                        <?php echo esc_html( ucfirst( (string) ( $deposit->provider ?? 'manual' ) ) ); ?>
                                        · <?php echo esc_html( metis_finance_short_date( (string) ( $deposit->deposit_date ?? '' ) ) ); ?>
                                        · <?php echo esc_html( number_format_i18n( (int) ( $deposit->transaction_count ?? 0 ) ) ); ?> txns
                                        · <?php echo esc_html( ucfirst( (string) ( $deposit->status ?? 'unknown' ) ) ); ?>
                                    </div>
                                </div>
                                <div class="metis-finance-list-amount"><?php echo esc_html( metis_finance_currency( (float) ( $deposit->display_total ?? 0 ) ) ); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="metis-finance-empty">No settlement activity is available yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="metis-finance-card">
            <div class="metis-finance-card-header">
                <h2>What Needs Attention</h2>
                <span class="mw-muted"><?php echo esc_html( metis_finance_short_date( $today ) ); ?></span>
            </div>
            <div class="metis-finance-card-body">
                <div class="metis-finance-list">
                    <div class="metis-finance-list-row">
                        <div>
                            <div class="metis-finance-list-title">Unsettled Donations</div>
                            <div class="metis-finance-list-meta"><?php echo esc_html( number_format_i18n( (int) ( $txn_summary->undeposited_count ?? 0 ) ) ); ?> gifts still need to settle to a deposit or payout.</div>
                        </div>
                        <div class="metis-finance-list-amount"><?php echo esc_html( metis_finance_currency( (float) ( $txn_summary->undeposited_total ?? 0 ) ) ); ?></div>
                    </div>
                    <div class="metis-finance-list-row">
                        <div>
                            <div class="metis-finance-list-title">Reconciliation Queue</div>
                            <div class="metis-finance-list-meta"><?php echo esc_html( number_format_i18n( $open_recons ) ); ?> account periods still need statement review.</div>
                        </div>
                        <div class="metis-finance-list-amount"><?php echo esc_html( number_format_i18n( $open_recons ) ); ?></div>
                    </div>
                    <div class="metis-finance-list-row">
                        <div>
                            <div class="metis-finance-list-title">This Month's Income</div>
                            <div class="metis-finance-list-meta">Donations recorded since the start of the month.</div>
                        </div>
                        <div class="metis-finance-list-amount"><?php echo esc_html( metis_finance_currency( (float) ( $txn_summary->month_total ?? 0 ) ) ); ?></div>
                    </div>
                </div>

                <div class="metis-finance-card-header" style="margin:18px -18px 0; border-left:0; border-right:0;">
                    <h2>Quick Actions</h2>
                    <span class="mw-muted">Common next steps</span>
                </div>

                <div class="metis-finance-actions" style="margin-top:16px;">
                    <a class="mw-btn mw-btn-xs" href="<?php echo esc_url( metis_portal_url( 'finance', 'ledger' ) ); ?>">Record activity</a>
                    <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_portal_url( 'finance', 'reconciliations' ) ); ?>">Reconcile accounts</a>
                    <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( $transactions_url ); ?>">Review donations</a>
                    <a class="mw-btn mw-btn-xs mw-btn-ghost" href="<?php echo esc_url( metis_portal_url( 'finance', 'reports' ) ); ?>">View insights</a>
                </div>
            </div>
        </section>
    </div>

    <div class="metis-finance-grid">
        <section class="metis-finance-card">
            <div class="metis-finance-card-header">
                <h2>Fund Snapshot</h2>
                <span class="mw-muted">Restricted and unrestricted balances</span>
            </div>
            <div class="metis-finance-card-body">
                <div class="metis-finance-split">
                    <div class="metis-finance-kpi">
                        <div class="metis-finance-kpi-label">Unrestricted</div>
                        <div class="metis-finance-kpi-value"><?php echo esc_html( metis_finance_currency( (float) ( $fund_summary->unrestricted_total ?? 0 ) ) ); ?></div>
                        <div class="metis-finance-kpi-sub">General operating balances with no donor restrictions.</div>
                    </div>
                    <div class="metis-finance-kpi">
                        <div class="metis-finance-kpi-label">Restricted</div>
                        <div class="metis-finance-kpi-value"><?php echo esc_html( metis_finance_currency( (float) ( $fund_summary->restricted_total ?? 0 ) ) ); ?></div>
                        <div class="metis-finance-kpi-sub">Purpose, time, or endowment-designated balances.</div>
                    </div>
                </div>

                <div class="metis-finance-card-header" style="margin:18px -18px 0; border-left:0; border-right:0;">
                    <h2>Top Funds</h2>
                    <span class="mw-muted">Highest current balances</span>
                </div>

                <div class="metis-finance-list" style="margin-top:16px;">
                    <?php if ( ! empty( $fund_rows ) ) : ?>
                        <?php foreach ( $fund_rows as $fund ) : ?>
                            <?php $balance = (float) ( $fund->balance ?? 0 ); ?>
                            <div class="metis-finance-list-row">
                                <div>
                                    <div class="metis-finance-list-title"><?php echo esc_html( (string) ( $fund->fund_name ?? 'Fund' ) ); ?></div>
                                    <div class="metis-finance-list-meta"><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $fund->restriction_type ?? 'unrestricted' ) ) ) ); ?></div>
                                </div>
                                <div class="metis-finance-list-amount"><?php echo esc_html( metis_finance_currency( $balance ) ); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="metis-finance-empty">Fund balances will appear here after finance activity is available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="metis-finance-card">
            <div class="metis-finance-card-header">
                <h2>Recent Activity</h2>
                <span class="mw-muted">Latest finance updates</span>
            </div>
            <div class="metis-finance-card-body">
                <div class="metis-finance-list">
                    <?php if ( ! empty( $ledger_rows ) ) : ?>
                        <?php foreach ( $ledger_rows as $entry ) : ?>
                            <?php
                            $signed_amount = metis_finance_signed_amount( (string) ( $entry->direction ?? 'inflow' ), (float) ( $entry->amount ?? 0 ) );
                            $amount_label  = $signed_amount < 0 ? '-$' . number_format_i18n( abs( $signed_amount ), 2 ) : '$' . number_format_i18n( abs( $signed_amount ), 2 );
                            ?>
                            <div class="metis-finance-list-row">
                                <div>
                                    <div class="metis-finance-list-title"><?php echo esc_html( (string) ( ! empty( $entry->memo ) ? $entry->memo : ( $entry->account_label ?? $entry->account_key ?? 'Activity' ) ) ); ?></div>
                                    <div class="metis-finance-list-meta">
                                        <?php echo esc_html( (string) ( $entry->account_label ?? $entry->account_key ?? '' ) ); ?>
                                        · <?php echo esc_html( strtoupper( (string) ( $entry->source_ref ?? '' ) ) ); ?>
                                        · <?php echo esc_html( metis_finance_short_date( (string) ( $entry->entry_date ?? '' ) ) ); ?>
                                        · <?php echo esc_html( ucfirst( (string) ( $entry->status ?? 'posted' ) ) ); ?>
                                    </div>
                                </div>
                                <div class="metis-finance-list-amount"><?php echo esc_html( $amount_label ); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="metis-finance-empty">No finance activity has been generated yet.</div>
                    <?php endif; ?>
                </div>

                <div class="metis-finance-card-header" style="margin:18px -18px 0; border-left:0; border-right:0;">
                    <h2>Reconciliation Status</h2>
                    <span class="mw-muted">Monthly account check-ins</span>
                </div>

                <div class="metis-finance-list" style="margin-top:16px;">
                    <?php if ( ! empty( $recon_rows ) ) : ?>
                        <?php foreach ( $recon_rows as $recon ) : ?>
                            <?php
                            $statement = $recon->statement_balance !== null
                                ? metis_finance_currency( (float) $recon->statement_balance )
                                : 'Statement pending';
                            $variance = $recon->variance !== null
                                ? metis_finance_currency( (float) $recon->variance )
                                : '—';
                            ?>
                            <div class="metis-finance-list-row">
                                <div>
                                    <div class="metis-finance-list-title"><?php echo esc_html( (string) ( $recon->account_label ?? $recon->account_key ?? 'Account' ) ); ?></div>
                                    <div class="metis-finance-list-meta">
                                        <?php echo esc_html( metis_date( 'M Y', strtotime( (string) ( $recon->period_start ?? '' ) ) ?: $now_ts ) ); ?>
                                        · Book <?php echo esc_html( metis_finance_currency( (float) ( $recon->book_balance ?? 0 ) ) ); ?>
                                        · <?php echo esc_html( $statement ); ?>
                                        · Variance <?php echo esc_html( $variance ); ?>
                                    </div>
                                </div>
                                <div class="metis-finance-list-amount"><?php echo esc_html( ucfirst( (string) ( $recon->status ?? 'open' ) ) ); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="metis-finance-empty">Reconciliation periods will appear once activity exists.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <section class="metis-finance-card">
        <div class="metis-finance-card-header">
        <h2>Waiting To Settle</h2>
        <input id="metis-finance-search" class="mw-input metis-finance-search" type="search" placeholder="Filter by transaction ID or campaign">
        </div>
        <div class="metis-finance-card-body">
            <div class="mw-premium-table">
                <div class="mw-premium-row mw-premium-header">
                    <div class="mw-premium-cell">Transaction</div>
                    <div class="mw-premium-cell">Campaign</div>
                    <div class="mw-premium-cell">Date</div>
                    <div class="mw-premium-cell">Status</div>
                    <div class="mw-premium-cell mw-col-numeric">Amount</div>
                </div>
                <?php if ( ! empty( $settlement_rows ) ) : ?>
                    <?php foreach ( $settlement_rows as $row ) : ?>
                        <div class="mw-premium-row metis-finance-search-row" data-search="<?php echo esc_attr( strtolower( trim( implode( ' ', [ (string) ( $row->tid ?? '' ), (string) ( $row->campaign_code ?? '' ), (string) ( $row->status ?? '' ) ] ) ) ) ); ?>">
                            <div class="mw-premium-cell"><strong><?php echo esc_html( (string) ( $row->tid ?? '' ) ); ?></strong></div>
                            <div class="mw-premium-cell"><?php echo esc_html( (string) ( $row->campaign_code ?? '—' ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo esc_html( metis_finance_short_date( (string) ( $row->tran_date ?? '' ) ) ); ?></div>
                            <div class="mw-premium-cell"><?php echo esc_html( ucfirst( (string) ( $row->status ?? 'unknown' ) ) ); ?></div>
                            <div class="mw-premium-cell mw-col-numeric"><?php echo esc_html( metis_finance_currency( (float) ( $row->amount ?? 0 ) ) ); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="mw-premium-row">
                        <div class="mw-premium-cell mw-muted">No unsettled transactions are waiting to be linked to a deposit or payout.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
