<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

$db = metis_db();

$contacts_table     = Metis_Tables::get( 'contacts' );
$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );

$base_url         = metis_donations_base_url();
$dashboard_url    = $base_url . '/dashboard/';
$donors_url       = $base_url . '/donors/';
$transactions_url = $base_url . '/transactions/';
$deposits_url     = $base_url . '/deposits/';
$reports_url      = $base_url . '/reports/';
$campaigns_url    = $base_url . '/campaigns/';

$now_dt           = metis_current_datetime();
$today_sql        = $now_dt->format( 'Y-m-d H:i:s' );
$last_30_start    = $now_dt->modify( '-29 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
$prev_30_start    = $now_dt->modify( '-59 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
$prev_30_end      = $now_dt->modify( '-30 days' )->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );
$month_start      = $now_dt->modify( 'first day of this month' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
$year_start       = $now_dt->setDate( (int) $now_dt->format( 'Y' ), 1, 1 )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
$current_year     = (int) $now_dt->format( 'Y' );

$kpis = (object) ( $db->fetchOne(
    "
    SELECT
        COUNT(*) AS total_gifts,
        COALESCE(SUM(amount), 0) AS lifetime_raised,
        COALESCE(SUM(CASE WHEN tran_date >= %s THEN amount ELSE 0 END), 0) AS raised_30d,
        COUNT(CASE WHEN tran_date >= %s THEN 1 END) AS gifts_30d,
        COALESCE(SUM(CASE WHEN tran_date >= %s THEN amount ELSE 0 END), 0) AS raised_month,
        COALESCE(SUM(CASE WHEN tran_date >= %s THEN amount ELSE 0 END), 0) AS raised_ytd,
        COUNT(DISTINCT CASE WHEN tran_date >= %s AND did IS NOT NULL AND did <> '' THEN did END) AS donors_ytd,
        COALESCE(SUM(
            CASE
                WHEN (deposit_batch_id IS NULL OR deposit_batch_id = '')
                  AND LOWER(COALESCE(status, '')) = 'completed'
                THEN amount
                ELSE 0
            END
        ), 0) AS open_deposit_total,
        COUNT(
            CASE
                WHEN (deposit_batch_id IS NULL OR deposit_batch_id = '')
                  AND LOWER(COALESCE(status, '')) = 'completed'
                THEN 1
                ELSE NULL
            END
        ) AS open_deposit_count
    FROM {$transactions_table}
    ",
    [ $last_30_start, $last_30_start, $month_start, $year_start, $year_start ]
) ?: [] );

$comparison = (object) ( $db->fetchOne(
    "
    SELECT
        COALESCE(SUM(CASE WHEN tran_date BETWEEN %s AND %s THEN amount ELSE 0 END), 0) AS current_30d,
        COALESCE(SUM(CASE WHEN tran_date BETWEEN %s AND %s THEN amount ELSE 0 END), 0) AS previous_30d,
        COUNT(CASE WHEN tran_date BETWEEN %s AND %s THEN 1 ELSE NULL END) AS current_gifts,
        COUNT(CASE WHEN tran_date BETWEEN %s AND %s THEN 1 ELSE NULL END) AS previous_gifts
    FROM {$transactions_table}
    ",
    [ $last_30_start, $today_sql, $prev_30_start, $prev_30_end, $last_30_start, $today_sql, $prev_30_start, $prev_30_end ]
) ?: [] );

$campaign_counts = (object) ( $db->fetchOne(
    "
    SELECT
        COUNT(*) AS total_campaigns,
        SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_campaigns
    FROM {$campaigns_table}
    "
) ?: [] );

$recent_transactions = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "
    SELECT
        t.tid,
        t.did,
        t.amount,
        t.status,
        t.payment_method,
        t.tran_date,
        t.deposit_batch_id,
        t.platform,
        c.cname AS campaign_name,
        ct.first_name,
        ct.last_name,
        ct.email
    FROM {$transactions_table} t
    LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
    LEFT JOIN {$contacts_table} ct ON ct.did = t.did
    ORDER BY t.tran_date DESC, t.id DESC
    LIMIT 8
    "
) ?: [] );

$top_donors = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "
    SELECT
        t.did,
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''), c.email, t.did) AS donor_name,
        c.email,
        COUNT(*) AS gift_count,
        COALESCE(SUM(t.amount), 0) AS total_raised,
        MAX(t.tran_date) AS last_gift_date
    FROM {$transactions_table} t
    LEFT JOIN {$contacts_table} c ON c.did = t.did
    WHERE t.did IS NOT NULL
      AND t.did <> ''
    GROUP BY t.did
    ORDER BY total_raised DESC, last_gift_date DESC
    LIMIT 6
    "
) ?: [] );

$top_campaigns = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "
    SELECT
        c.cid,
        c.cname,
        c.active,
        c.type,
        c.goals,
        COALESCE(SUM(CASE WHEN YEAR(t.tran_date) = %d THEN t.amount ELSE 0 END), 0) AS year_raised,
        COALESCE(SUM(t.amount), 0) AS lifetime_raised,
        COUNT(t.id) AS gift_count
    FROM {$campaigns_table} c
    LEFT JOIN {$transactions_table} t ON t.campaign_code = c.cid
    GROUP BY c.id
    ORDER BY year_raised DESC, lifetime_raised DESC, c.cname ASC
    LIMIT 5
    ",
    [ $current_year ]
) ?: [] );

$method_breakdown = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "
    SELECT
        COALESCE(NULLIF(payment_method, ''), 'unknown') AS payment_method,
        COUNT(*) AS gift_count,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM {$transactions_table}
    GROUP BY COALESCE(NULLIF(payment_method, ''), 'unknown')
    ORDER BY total_amount DESC, gift_count DESC
    LIMIT 6
    "
) ?: [] );

$platform_breakdown = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "
    SELECT
        COALESCE(NULLIF(platform, ''), 'unknown') AS platform_code,
        COUNT(*) AS gift_count,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM {$transactions_table}
    GROUP BY COALESCE(NULLIF(platform, ''), 'unknown')
    ORDER BY total_amount DESC, gift_count DESC
    LIMIT 4
    "
) ?: [] );

$open_deposit_rows = array_map( static function ( array $row ) {
    return (object) $row;
}, $db->fetchAll(
    "
    SELECT
        COALESCE(NULLIF(platform, ''), 'unknown') AS platform_code,
        COUNT(*) AS gift_count,
        COALESCE(SUM(amount), 0) AS total_amount,
        MIN(tran_date) AS oldest_tran_date
    FROM {$transactions_table}
    WHERE (deposit_batch_id IS NULL OR deposit_batch_id = '')
      AND LOWER(COALESCE(status, '')) = 'completed'
    GROUP BY COALESCE(NULLIF(platform, ''), 'unknown')
    ORDER BY total_amount DESC, oldest_tran_date ASC
    LIMIT 5
    "
) ?: [] );

$daily_trend_raw = $db->fetchAll(
    "
    SELECT
        DATE(tran_date) AS trend_day,
        COALESCE(SUM(amount), 0) AS total_amount,
        COUNT(*) AS gift_count
    FROM {$transactions_table}
    WHERE tran_date >= %s
    GROUP BY DATE(tran_date)
    ORDER BY trend_day ASC
    ",
    [ $last_30_start ]
) ?: [];

$monthly_trend_raw = $db->fetchAll(
    "
    SELECT
        DATE_FORMAT(tran_date, '%%Y-%%m') AS trend_month,
        COALESCE(SUM(amount), 0) AS total_amount
    FROM {$transactions_table}
    WHERE tran_date >= %s
    GROUP BY DATE_FORMAT(tran_date, '%%Y-%%m')
    ORDER BY trend_month ASC
    ",
    [ $now_dt->modify( '-11 months' )->modify( 'first day of this month' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' ) ]
) ?: [];

$recent_deposits = array_slice( metis_get_deposits(), 0, 5 );

$raised_30d      = (float) ( $kpis->raised_30d ?? 0 );
$previous_30d    = (float) ( $comparison->previous_30d ?? 0 );
$current_gifts   = (int) ( $comparison->current_gifts ?? 0 );
$previous_gifts  = (int) ( $comparison->previous_gifts ?? 0 );
$avg_gift_30d    = $current_gifts > 0 ? $raised_30d / $current_gifts : 0.0;
$queue_total     = (float) ( $kpis->open_deposit_total ?? 0 );
$queue_gifts     = (int) ( $kpis->open_deposit_count ?? 0 );
$active_campaigns = (int) ( $campaign_counts->active_campaigns ?? 0 );
$total_campaigns  = (int) ( $campaign_counts->total_campaigns ?? 0 );

$raised_delta = $previous_30d > 0 ? ( ( $raised_30d - $previous_30d ) / $previous_30d ) * 100 : null;
$gift_delta   = $previous_gifts > 0 ? ( ( $current_gifts - $previous_gifts ) / $previous_gifts ) * 100 : null;

$format_delta = static function ( ?float $value, string $suffix = '%' ): array {
    if ( $value === null ) {
        return [ 'No prior period', 'neutral' ];
    }

    if ( abs( $value ) < 0.05 ) {
        return [ 'Flat vs previous period', 'neutral' ];
    }

    $direction = $value > 0 ? 'up' : 'down';
    $class     = $value > 0 ? 'positive' : 'negative';
    return [ sprintf( '%s %s%s vs previous period', $direction, number_format( abs( $value ), 1 ), $suffix ), $class ];
};

[ $raised_delta_label, $raised_delta_class ] = $format_delta( $raised_delta );
[ $gift_delta_label, $gift_delta_class ]     = $format_delta( $gift_delta );

$daily_trend_map = [];
foreach ( $daily_trend_raw as $row ) {
    $daily_trend_map[ (string) $row['trend_day'] ] = [
        'amount' => (float) $row['total_amount'],
        'count'  => (int) $row['gift_count'],
    ];
}

$daily_trend = [];
for ( $i = 29; $i >= 0; $i-- ) {
    $day = $now_dt->modify( '-' . $i . ' days' );
    $key = $day->format( 'Y-m-d' );
    $daily_trend[] = [
        'key'    => $key,
        'label'  => $day->format( 'M j' ),
        'amount' => (float) ( $daily_trend_map[ $key ]['amount'] ?? 0 ),
        'count'  => (int) ( $daily_trend_map[ $key ]['count'] ?? 0 ),
    ];
}

$monthly_trend_map = [];
foreach ( $monthly_trend_raw as $row ) {
    $monthly_trend_map[ (string) $row['trend_month'] ] = (float) $row['total_amount'];
}

$monthly_trend = [];
for ( $i = 11; $i >= 0; $i-- ) {
    $month = $now_dt->modify( '-' . $i . ' months' );
    $key   = $month->format( 'Y-m' );
    $monthly_trend[] = [
        'key'    => $key,
        'label'  => $month->format( 'M' ),
        'amount' => (float) ( $monthly_trend_map[ $key ] ?? 0 ),
    ];
}

$build_line_chart = static function ( array $series, string $value_key, int $width = 640, int $height = 220 ): array {
    if ( empty( $series ) ) {
        return [
            'polyline' => '',
            'area'     => '',
            'bars'     => [],
            'max'      => 0.0,
        ];
    }

    $values = array_map( static fn( array $row ): float => (float) ( $row[ $value_key ] ?? 0 ), $series );
    $max    = max( $values );
    $max    = $max > 0 ? $max : 1.0;
    $count  = count( $series );
    $step_x = $count > 1 ? $width / ( $count - 1 ) : $width;
    $points = [];
    $bars   = [];

    foreach ( $series as $index => $row ) {
        $x = $count > 1 ? $index * $step_x : $width / 2;
        $y = $height - ( ( (float) $row[ $value_key ] / $max ) * ( $height - 24 ) ) - 12;
        $points[] = round( $x, 2 ) . ',' . round( $y, 2 );
        $bars[] = [
            'x'     => round( $x, 2 ),
            'y'     => round( $y, 2 ),
            'value' => (float) $row[ $value_key ],
            'label' => (string) ( $row['label'] ?? '' ),
        ];
    }

    $area = '0,' . $height . ' ' . implode( ' ', $points ) . ' ' . $width . ',' . $height;

    return [
        'polyline' => implode( ' ', $points ),
        'area'     => $area,
        'bars'     => $bars,
        'max'      => $max,
    ];
};

$daily_chart   = $build_line_chart( $daily_trend, 'amount' );
$monthly_chart = $build_line_chart( $monthly_trend, 'amount' );
?>

<div class="metis-donations-dashboard">
    <div class="metis-page-header metis-donations-dashboard-hero">
        <div class="metis-page-header-left">
            <h1 class="metis-page-title"><?php echo metis_escape_html( metis_current_module_label( 'Donations' ) ); ?></h1>
            <p class="metis-subtitle">A live snapshot of giving, deposits, donors, and campaign momentum.</p>
        </div>
        <div class="metis-page-header-right metis-donations-dashboard-actions">
            <a class="metis-btn metis-btn-xs" href="<?php echo metis_escape_url( $transactions_url ); ?>">Review transactions</a>
            <a class="metis-btn metis-btn-xs metis-btn-secondary" href="<?php echo metis_escape_url( $reports_url ); ?>">Open reports</a>
        </div>
    </div>

    <div class="metis-sidebar-layout metis-donations-layout">
        <aside class="metis-sidebar-layout-sidebar metis-donations-layout-sidebar">
            <div class="metis-sidebar-layout-sidebar-inner metis-donations-layout-sidebar-inner">
                <div class="metis-list-sidebar-actions">
                    <div class="metis-list-sidebar-label">Operations</div>
                    <nav class="metis-list-sidebar-nav" aria-label="Donations operations">
                        <a class="metis-list-sidebar-nav-item is-active" href="<?php echo metis_escape_url( $dashboard_url ); ?>">Dashboard</a>
                        <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url( $transactions_url ); ?>">Transactions</a>
                        <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url( $deposits_url ); ?>">Deposits</a>
                        <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url( $donors_url ); ?>">Donors</a>
                        <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url( $campaigns_url ); ?>">Campaigns</a>
                        <a class="metis-list-sidebar-nav-item" href="<?php echo metis_escape_url( $reports_url ); ?>">Reports</a>
                    </nav>
                </div>
            </div>
        </aside>
        <div class="metis-sidebar-layout-content metis-donations-layout-content">
    <section class="metis-donations-kpis">
        <article class="metis-donations-kpi">
            <div class="metis-donations-kpi-label">Lifetime raised</div>
            <div class="metis-donations-kpi-value">$<?php echo number_format( (float) ( $kpis->lifetime_raised ?? 0 ), 2 ); ?></div>
            <div class="metis-donations-kpi-note"><?php echo number_format( (int) ( $kpis->total_gifts ?? 0 ) ); ?> recorded gifts</div>
        </article>
        <article class="metis-donations-kpi">
            <div class="metis-donations-kpi-label">Last 30 days</div>
            <div class="metis-donations-kpi-value">$<?php echo number_format( $raised_30d, 2 ); ?></div>
            <div class="metis-donations-kpi-note metis-donations-kpi-note--<?php echo metis_escape_attr( $raised_delta_class ); ?>"><?php echo metis_escape_html( $raised_delta_label ); ?></div>
        </article>
        <article class="metis-donations-kpi">
            <div class="metis-donations-kpi-label">This month</div>
            <div class="metis-donations-kpi-value">$<?php echo number_format( (float) ( $kpis->raised_month ?? 0 ), 2 ); ?></div>
            <div class="metis-donations-kpi-note"><?php echo metis_escape_html( $now_dt->format( 'F Y' ) ); ?></div>
        </article>
        <article class="metis-donations-kpi">
            <div class="metis-donations-kpi-label">Year to date</div>
            <div class="metis-donations-kpi-value">$<?php echo number_format( (float) ( $kpis->raised_ytd ?? 0 ), 2 ); ?></div>
            <div class="metis-donations-kpi-note"><?php echo number_format( (int) ( $kpis->donors_ytd ?? 0 ) ); ?> active donors in <?php echo metis_escape_html( (string) $current_year ); ?></div>
        </article>
        <article class="metis-donations-kpi">
            <div class="metis-donations-kpi-label">Open deposit queue</div>
            <div class="metis-donations-kpi-value">$<?php echo number_format( $queue_total, 2 ); ?></div>
            <div class="metis-donations-kpi-note"><?php echo number_format( $queue_gifts ); ?> completed gifts not batched</div>
        </article>
        <article class="metis-donations-kpi">
            <div class="metis-donations-kpi-label">Campaigns</div>
            <div class="metis-donations-kpi-value"><?php echo number_format( $active_campaigns ); ?></div>
            <div class="metis-donations-kpi-note"><?php echo number_format( $total_campaigns ); ?> total campaigns configured</div>
        </article>
    </section>

    <section class="metis-donations-pulse">
        <div class="metis-donations-pulse-card">
            <div class="metis-donations-pulse-label">Gift velocity</div>
            <div class="metis-donations-pulse-value"><?php echo number_format( $current_gifts ); ?></div>
            <div class="metis-donations-pulse-note metis-donations-kpi-note--<?php echo metis_escape_attr( $gift_delta_class ); ?>"><?php echo metis_escape_html( $gift_delta_label ); ?></div>
        </div>
        <div class="metis-donations-pulse-card">
            <div class="metis-donations-pulse-label">Average gift</div>
            <div class="metis-donations-pulse-value">$<?php echo number_format( $avg_gift_30d, 2 ); ?></div>
            <div class="metis-donations-pulse-note">Across the last 30 days</div>
        </div>
        <div class="metis-donations-pulse-card">
            <div class="metis-donations-pulse-label">Deposit coverage</div>
            <div class="metis-donations-pulse-value"><?php echo $queue_gifts > 0 ? number_format( max( 0, (int) ( $kpis->total_gifts ?? 0 ) - $queue_gifts ) ) : number_format( (int) ( $kpis->total_gifts ?? 0 ) ); ?></div>
            <div class="metis-donations-pulse-note">Gifts already attached to a batch</div>
        </div>
    </section>

    <section class="metis-donations-trend-grid">
        <article class="metis-premium-wrap metis-donations-panel metis-donations-trend-panel">
            <div class="metis-donations-panel-head">
                <div>
                    <h2 class="metis-section-title">30-Day Giving Trend</h2>
                    <div class="metis-donations-chart-subtitle">Daily gift totals across the most recent 30 days.</div>
                </div>
                <div class="metis-donations-chart-total">$<?php echo number_format( $raised_30d, 2 ); ?></div>
            </div>
            <div class="metis-donations-chart-shell">
                <div class="metis-donations-chart-yaxis">
                    <span>$<?php echo number_format( $daily_chart['max'], 0 ); ?></span>
                    <span>$<?php echo number_format( $daily_chart['max'] / 2, 0 ); ?></span>
                    <span>$0</span>
                </div>
                <div class="metis-donations-chart-wrap">
                    <svg class="metis-donations-chart" viewBox="0 0 640 220" preserveAspectRatio="none" aria-hidden="true">
                        <line x1="0" y1="208" x2="640" y2="208"></line>
                        <line x1="0" y1="110" x2="640" y2="110"></line>
                        <line x1="0" y1="12" x2="640" y2="12"></line>
                        <polygon class="metis-donations-chart-area" points="<?php echo metis_escape_attr( $daily_chart['area'] ); ?>"></polygon>
                        <polyline class="metis-donations-chart-line" points="<?php echo metis_escape_attr( $daily_chart['polyline'] ); ?>"></polyline>
                        <?php foreach ( $daily_chart['bars'] as $point ) : ?>
                            <circle cx="<?php echo metis_escape_attr( (string) $point['x'] ); ?>" cy="<?php echo metis_escape_attr( (string) $point['y'] ); ?>" r="4" class="metis-donations-chart-dot">
                                <title><?php echo metis_escape_html( $point['label'] . ': $' . number_format( $point['value'], 2 ) ); ?></title>
                            </circle>
                        <?php endforeach; ?>
                    </svg>
                    <div class="metis-donations-chart-labels">
                        <span><?php echo metis_escape_html( $daily_trend[0]['label'] ?? '' ); ?></span>
                        <span><?php echo metis_escape_html( $daily_trend[14]['label'] ?? '' ); ?></span>
                        <span><?php echo metis_escape_html( end( $daily_trend )['label'] ?? '' ); ?></span>
                    </div>
                </div>
            </div>
        </article>

        <article class="metis-premium-wrap metis-donations-panel metis-donations-trend-panel">
            <div class="metis-donations-panel-head">
                <div>
                    <h2 class="metis-section-title">12-Month Fundraising Trend</h2>
                    <div class="metis-donations-chart-subtitle">Monthly donation totals for the last year.</div>
                </div>
                <div class="metis-donations-chart-total">$<?php echo number_format( array_sum( array_column( $monthly_trend, 'amount' ) ), 2 ); ?></div>
            </div>
            <div class="metis-donations-chart-shell">
                <div class="metis-donations-chart-yaxis">
                    <span>$<?php echo number_format( $monthly_chart['max'], 0 ); ?></span>
                    <span>$<?php echo number_format( $monthly_chart['max'] / 2, 0 ); ?></span>
                    <span>$0</span>
                </div>
                <div class="metis-donations-chart-wrap">
                    <svg class="metis-donations-chart" viewBox="0 0 640 220" preserveAspectRatio="none" aria-hidden="true">
                        <line x1="0" y1="208" x2="640" y2="208"></line>
                        <line x1="0" y1="110" x2="640" y2="110"></line>
                        <line x1="0" y1="12" x2="640" y2="12"></line>
                        <polygon class="metis-donations-chart-area metis-donations-chart-area--secondary" points="<?php echo metis_escape_attr( $monthly_chart['area'] ); ?>"></polygon>
                        <polyline class="metis-donations-chart-line metis-donations-chart-line--secondary" points="<?php echo metis_escape_attr( $monthly_chart['polyline'] ); ?>"></polyline>
                        <?php foreach ( $monthly_chart['bars'] as $point ) : ?>
                            <circle cx="<?php echo metis_escape_attr( (string) $point['x'] ); ?>" cy="<?php echo metis_escape_attr( (string) $point['y'] ); ?>" r="4" class="metis-donations-chart-dot metis-donations-chart-dot--secondary">
                                <title><?php echo metis_escape_html( $point['label'] . ': $' . number_format( $point['value'], 2 ) ); ?></title>
                            </circle>
                        <?php endforeach; ?>
                    </svg>
                    <div class="metis-donations-chart-labels">
                        <?php foreach ( $monthly_trend as $month ) : ?>
                            <span><?php echo metis_escape_html( $month['label'] ); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </article>
    </section>

    <div class="metis-donations-dashboard-grid metis-donations-dashboard-grid-single">
        <section class="metis-premium-wrap metis-donations-panel">
            <div class="metis-donations-panel-head">
                <h2 class="metis-section-title">Recent Transactions</h2>
                <a class="metis-link-muted" href="<?php echo metis_escape_url( $transactions_url ); ?>">See all</a>
            </div>
            <div class="metis-donations-activity-list">
                <?php if ( ! empty( $recent_transactions ) ) : ?>
                    <?php foreach ( $recent_transactions as $tx ) :
                        $tx_url      = metis_donations_detail_url( 'transaction', (string) $tx->tid );
                        $donor_name  = trim( (string) ( $tx->first_name ?? '' ) . ' ' . (string) ( $tx->last_name ?? '' ) );
                        $donor_name  = $donor_name !== '' ? $donor_name : ( $tx->email ?: ( $tx->did ?: 'Unknown donor' ) );
                        $campaign    = $tx->campaign_name ?: 'Unassigned campaign';
                        $date_label  = $tx->tran_date ? metis_runtime_format_date( (string) $tx->tran_date, null, null, null, 'No date' ) : 'No date';
                    ?>
                        <a class="metis-donations-activity-row" href="<?php echo metis_escape_url( $tx_url ); ?>">
                            <div class="metis-donations-activity-main">
                                <div class="metis-donations-activity-title"><?php echo metis_escape_html( $donor_name ); ?></div>
                                <div class="metis-donations-activity-meta"><?php echo metis_escape_html( $campaign ); ?> · <?php echo metis_escape_html( $date_label ); ?></div>
                            </div>
                            <div class="metis-donations-activity-side">
                                <div class="metis-donations-activity-amount">$<?php echo number_format( (float) $tx->amount, 2 ); ?></div>
                                <div class="metis-donations-activity-flags">
                                    <?php echo metis_status_badge( (string) $tx->status ); ?>
                                    <?php echo metis_deposit_badge( $tx->deposit_batch_id ? 'batched' : null ); ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="metis-muted">No transaction activity recorded yet.</div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="metis-donations-dashboard-columns">
        <section class="metis-premium-wrap metis-donations-panel">
            <div class="metis-donations-panel-head">
                <h2 class="metis-section-title">Campaign Momentum</h2>
                <a class="metis-link-muted" href="<?php echo metis_escape_url( $campaigns_url ); ?>">Manage campaigns</a>
            </div>
            <div class="metis-donations-metric-list">
                <?php if ( ! empty( $top_campaigns ) ) : ?>
                    <?php foreach ( $top_campaigns as $campaign ) :
                        $campaign_url = metis_donations_detail_url( 'campaign', (string) $campaign->cid );
                        $goals        = metis_parse_goals( $campaign->goals );
                        $year_goal    = (float) ( $goals[ $current_year ] ?? 0 );
                        $year_raised  = (float) ( $campaign->year_raised ?? 0 );
                        $progress     = $year_goal > 0 ? min( 100, round( ( $year_raised / $year_goal ) * 100, 1 ) ) : 0;
                    ?>
                        <a class="metis-donations-metric-row" href="<?php echo metis_escape_url( $campaign_url ); ?>">
                            <div class="metis-donations-metric-main">
                                <div class="metis-donations-metric-title"><?php echo metis_escape_html( $campaign->cname ); ?></div>
                                <div class="metis-donations-metric-meta">
                                    <?php echo metis_escape_html( ucfirst( (string) ( $campaign->type ?: 'campaign' ) ) ); ?>
                                    · <?php echo metis_escape_html( $campaign->active ? 'Active' : 'Inactive' ); ?>
                                </div>
                                <div class="metis-donations-progress">
                                    <div class="metis-donations-progress-bar">
                                        <span style="width: <?php echo metis_escape_attr( $progress ); ?>%;"></span>
                                    </div>
                                    <div class="metis-donations-progress-meta">
                                        <span>$<?php echo number_format( $year_raised, 2 ); ?> in <?php echo metis_escape_html( (string) $current_year ); ?></span>
                                        <span><?php echo $year_goal > 0 ? '$' . number_format( $year_goal, 0 ) . ' goal' : 'No goal set'; ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="metis-donations-metric-side">
                                <div class="metis-donations-metric-value">$<?php echo number_format( (float) ( $campaign->lifetime_raised ?? 0 ), 2 ); ?></div>
                                <div class="metis-donations-metric-caption">Lifetime</div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="metis-muted">No campaigns are available yet.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="metis-premium-wrap metis-donations-panel">
            <div class="metis-donations-panel-head">
                <h2 class="metis-section-title">Top Donors</h2>
                <a class="metis-link-muted" href="<?php echo metis_escape_url( $donors_url ); ?>">Open donor list</a>
            </div>
            <div class="metis-donations-metric-list">
                <?php if ( ! empty( $top_donors ) ) : ?>
                    <?php foreach ( $top_donors as $donor ) :
                        $donor_url = metis_donations_detail_url( 'donor', (string) $donor->did );
                        $last_gift = $donor->last_gift_date ? metis_runtime_format_date( (string) $donor->last_gift_date, null, null, null, 'No gifts yet' ) : 'No gifts yet';
                    ?>
                        <a class="metis-donations-metric-row" href="<?php echo metis_escape_url( $donor_url ); ?>">
                            <div class="metis-donations-metric-main">
                                <div class="metis-donations-metric-title"><?php echo metis_escape_html( $donor->donor_name ); ?></div>
                                <div class="metis-donations-metric-meta">
                                    <?php echo metis_escape_html( $donor->did ); ?>
                                    <?php if ( ! empty( $donor->email ) ) : ?>
                                        · <?php echo metis_escape_html( $donor->email ); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="metis-donations-metric-meta"><?php echo number_format( (int) $donor->gift_count ); ?> gifts · last gift <?php echo metis_escape_html( $last_gift ); ?></div>
                            </div>
                            <div class="metis-donations-metric-side">
                                <div class="metis-donations-metric-value">$<?php echo number_format( (float) $donor->total_raised, 2 ); ?></div>
                                <div class="metis-donations-metric-caption">Raised</div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="metis-muted">No donor activity recorded yet.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="metis-premium-wrap metis-donations-panel">
            <div class="metis-donations-panel-head">
                <h2 class="metis-section-title">Deposit Queue</h2>
                <a class="metis-link-muted" href="<?php echo metis_escape_url( $transactions_url ); ?>">Batch transactions</a>
            </div>
            <div class="metis-donations-stack">
                <?php if ( ! empty( $open_deposit_rows ) ) : ?>
                    <?php foreach ( $open_deposit_rows as $row ) :
                        $oldest = $row->oldest_tran_date ? metis_runtime_date( 'M j', strtotime( $row->oldest_tran_date ) ) : 'n/a';
                    ?>
                        <div class="metis-donations-stack-row">
                            <div>
                                <div class="metis-donations-stack-title"><?php echo metis_escape_html( metis_platform_label( strtoupper( (string) $row->platform_code ) ) ); ?></div>
                                <div class="metis-donations-stack-meta"><?php echo number_format( (int) $row->gift_count ); ?> gifts waiting · oldest <?php echo metis_escape_html( $oldest ); ?></div>
                            </div>
                            <div class="metis-donations-stack-value">$<?php echo number_format( (float) $row->total_amount, 2 ); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="metis-muted">Everything completed is already attached to a deposit batch.</div>
                <?php endif; ?>
            </div>

            <div class="metis-donations-divider"></div>

            <div class="metis-donations-panel-subhead">Giving by payment method</div>
            <div class="metis-donations-stack">
                <?php if ( ! empty( $method_breakdown ) ) : ?>
                    <?php foreach ( $method_breakdown as $method ) : ?>
                        <div class="metis-donations-stack-row">
                            <div>
                                <div class="metis-donations-stack-title"><?php echo metis_escape_html( ucfirst( str_replace( '_', ' ', (string) $method->payment_method ) ) ); ?></div>
                                <div class="metis-donations-stack-meta"><?php echo number_format( (int) $method->gift_count ); ?> gifts</div>
                            </div>
                            <div class="metis-donations-stack-value">$<?php echo number_format( (float) $method->total_amount, 2 ); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <div class="metis-muted">No payment method data yet.</div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <section class="metis-premium-wrap metis-donations-panel">
        <div class="metis-donations-panel-head">
            <h2 class="metis-section-title">Recent Deposits</h2>
            <a class="metis-link-muted" href="<?php echo metis_escape_url( $deposits_url ); ?>">View deposits</a>
        </div>
        <table class="metis-premium-table metis-donations-deposit-table">
            <thead>
                <tr class="metis-premium-row metis-premium-header">
                    <th class="metis-premium-cell" scope="col">Deposit</th>
                    <th class="metis-premium-cell" scope="col">Date</th>
                    <th class="metis-premium-cell" scope="col">Status</th>
                    <th class="metis-premium-cell metis-col-numeric" scope="col">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( ! empty( $recent_deposits ) ) : ?>
                <?php foreach ( $recent_deposits as $deposit ) :
                    $deposit_url = metis_donations_detail_url( 'deposit', (string) $deposit->provider_ref );
                    $deposit_date = $deposit->deposit_date ? metis_runtime_format_date( (string) $deposit->deposit_date, null, null, null, 'No date' ) : 'No date';
                ?>
                    <tr class="metis-premium-row metis-donations-deposit-row metis-clickable-row" data-href="<?php echo metis_escape_url( $deposit_url ); ?>">
                        <td class="metis-premium-cell">
                            <div class="metis-donations-deposit-id"><?php echo metis_escape_html( $deposit->provider_ref ); ?></div>
                            <div class="metis-donations-deposit-meta">
                                <?php echo metis_escape_html( ucfirst( (string) ( $deposit->source ?: 'manual' ) ) ); ?>
                                · <?php echo number_format( (int) ( $deposit->batch_count ?? 0 ) ); ?> batches
                            </div>
                        </td>
                        <td class="metis-premium-cell"><?php echo metis_escape_html( $deposit_date ); ?></td>
                        <td class="metis-premium-cell"><?php echo metis_status_badge( (string) $deposit->status ); ?></td>
                        <td class="metis-premium-cell metis-col-numeric"><strong>$<?php echo number_format( (float) $deposit->total_amount, 2 ); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            <?php else : ?>
                <tr class="metis-premium-row">
                    <td class="metis-premium-cell metis-muted" colspan="4">No deposits have been recorded yet.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $platform_breakdown ) ) : ?>
            <div class="metis-donations-divider"></div>
            <div class="metis-donations-panel-subhead">Giving by platform</div>
            <div class="metis-donations-platform-row">
                <?php foreach ( $platform_breakdown as $platform ) : ?>
                    <div class="metis-donations-platform-pill">
                        <span><?php echo metis_escape_html( metis_platform_label( strtoupper( (string) $platform->platform_code ) ) ); ?></span>
                        <strong>$<?php echo number_format( (float) $platform->total_amount, 0 ); ?></strong>
                        <small><?php echo number_format( (int) $platform->gift_count ); ?> gifts</small>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
</div>
</div>
