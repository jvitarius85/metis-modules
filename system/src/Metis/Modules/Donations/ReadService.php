<?php
declare(strict_types=1);

namespace Metis\Modules\Donations;

final class ReadService {
    public static function donorsSnapshot(): array {
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get('contacts');
        $transactions_table = \Metis_Tables::get('transactions');

        $contacts = $db->fetchAll(
            "SELECT id, first_name, last_name, email, did
             FROM {$contacts_table}
             WHERE did IS NOT NULL
               AND did <> ''
             ORDER BY last_name, first_name"
        ) ?: [];

        $totals_raw = [];
        foreach ($db->fetchAll(
            "SELECT did, SUM(amount) AS total_amount
             FROM {$transactions_table}
             GROUP BY did"
        ) ?: [] as $row) {
            $did = (string) ($row['did'] ?? '');
            if ($did !== '') {
                $totals_raw[$did] = (float) ($row['total_amount'] ?? 0);
            }
        }

        $donors = [];
        foreach ($contacts as $contact_row) {
            $did = (string) ($contact_row['did'] ?? '');
            $donors[] = [
                'id' => (int) ($contact_row['id'] ?? 0),
                'first_name' => (string) ($contact_row['first_name'] ?? ''),
                'last_name' => (string) ($contact_row['last_name'] ?? ''),
                'email' => (string) ($contact_row['email'] ?? ''),
                'did' => $did,
                'total' => (float) ($totals_raw[$did] ?? 0),
            ];
        }

        return [
            'donors' => $donors,
        ];
    }

    public static function donorDetailSnapshot(string $donor_id): array {
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get('contacts');
        $transactions_table = \Metis_Tables::get('transactions');
        $campaigns_table = \Metis_Tables::get('campaigns');

        $donor_row = $db->fetchOne(
            "SELECT first_name, last_name, email, did FROM {$contacts_table} WHERE did = %s",
            [ $donor_id ]
        );
        $donor = is_array($donor_row) ? (object) $donor_row : null;

        $transactions = [];
        if ($donor) {
            $transactions = array_map(static function (array $row) {
                return (object) $row;
            }, $db->fetchAll(
                "SELECT t.*, c.cname AS campaign_name
                 FROM {$transactions_table} t
                 LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
                 WHERE t.did = %s
                 ORDER BY t.tran_date DESC, t.id DESC",
                [ $donor->did ]
            ) ?: []);
        }

        $total_gross = 0.0;
        foreach ($transactions as $transaction) {
            $total_gross += (float) $transaction->amount + (float) ($transaction->fee ?? 0);
        }

        return [
            'donor' => $donor,
            'transactions' => $transactions,
            'total_gross' => $total_gross,
            'total_net' => array_sum(array_map(static fn($t) => (float) $t->amount, $transactions)),
            'gift_count' => count($transactions),
        ];
    }

    public static function campaignsSnapshot(): array {
        $db = \metis_db();
        $campaigns_table = \Metis_Tables::get('campaigns');
        $transactions_table = \Metis_Tables::get('transactions');
        $current_year = (int) date('Y');

        $campaigns = array_map(static function (array $row) {
            return (object) $row;
        }, $db->fetchAll(
            "SELECT
                c.*,
                COUNT(t.id) AS gift_count,
                SUM(t.amount) AS total_raised,
                MAX(t.tran_date) AS last_gift_date
             FROM {$campaigns_table} c
             LEFT JOIN {$transactions_table} t ON t.campaign_code = c.cid
             GROUP BY c.id
             ORDER BY c.active DESC, c.cname ASC"
        ) ?: []);

        $year_raised_by_campaign = [];
        foreach ($db->fetchAll(
            "SELECT campaign_code, SUM(amount) AS year_raised
             FROM {$transactions_table}
             WHERE YEAR(tran_date) = %d
             GROUP BY campaign_code",
            [ $current_year ]
        ) ?: [] as $row) {
            $campaign_code = (string) ($row['campaign_code'] ?? '');
            if ($campaign_code !== '') {
                $year_raised_by_campaign[$campaign_code] = (float) ($row['year_raised'] ?? 0);
            }
        }

        return [
            'campaigns' => $campaigns,
            'current_year' => $current_year,
            'year_raised_by_campaign' => $year_raised_by_campaign,
        ];
    }

    public static function recurringSnapshot(): array {
        RecurringDonationsService::ensureSchema();
        $plans = RecurringDonationsService::listPlans();
        $campaign_options = \metis_db()->fetchAll(
            'SELECT cid, campaign_uid, cname FROM ' . \Metis_Tables::get( 'campaigns' ) . ' ORDER BY active DESC, cname ASC LIMIT 500'
        ) ?: [];
        $active = count( array_filter( $plans, static fn ( array $row ): bool => (string) ( $row['status'] ?? '' ) === 'active' ) );
        $paused = count( array_filter( $plans, static fn ( array $row ): bool => (string) ( $row['status'] ?? '' ) === 'paused' ) );
        $monthly_total = array_sum( array_map( static function ( array $row ): float {
            if ( (string) ( $row['status'] ?? '' ) !== 'active' ) {
                return 0.0;
            }
            $amount = (float) ( $row['amount'] ?? 0 );
            return match ( (string) ( $row['frequency'] ?? 'monthly' ) ) {
                'quarterly' => $amount / 3,
                'semiannual' => $amount / 6,
                'annual' => $amount / 12,
                default => $amount,
            };
        }, $plans ) );

        return [
            'plans' => $plans,
            'campaign_options' => $campaign_options,
            'active' => $active,
            'paused' => $paused,
            'monthly_total' => $monthly_total,
        ];
    }

    public static function depositSnapshot( string $deposit_code ): array {
        $db = \metis_db();
        $deposits_table = \Metis_Tables::get( 'deposits' );
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $campaigns_table = \Metis_Tables::get( 'campaigns' );

        $deposit_row = $db->fetchOne(
            "SELECT * FROM {$deposits_table} WHERE provider_ref = %s OR id = %s LIMIT 1",
            [ $deposit_code, $deposit_code ]
        );
        $deposit = $deposit_row ? (object) $deposit_row : null;

        $transactions = [];
        if ( $deposit ) {
            $transactions = array_map( static function ( array $row ) {
                return (object) $row;
            }, $db->fetchAll(
                "SELECT t.*,
                        TRIM( CONCAT( IFNULL(c.first_name,''), ' ', IFNULL(c.last_name,'') ) ) AS donor_name,
                        c.email AS donor_email,
                        camp.cname AS campaign_name
                 FROM {$transactions_table} t
                 LEFT JOIN {$contacts_table}  c    ON c.did  = t.did
                 LEFT JOIN {$campaigns_table} camp ON camp.cid = t.campaign_code
                 WHERE t.deposit_batch_id = %s
                 ORDER BY t.tran_date ASC",
                [ $deposit->provider_ref ]
            ) ?: [] );
        }

        return [
            'deposit' => $deposit,
            'transactions' => $transactions,
        ];
    }

    public static function batchDetailSnapshot( string $code ): array {
        $db = \metis_db();
        $batches_table = \Metis_Tables::get( 'batches' );
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $campaigns_table = \Metis_Tables::get( 'campaigns' );

        $batch_row = $db->fetchOne( "SELECT * FROM {$batches_table} WHERE batch_code = %s", [ $code ] );
        $batch = $batch_row ? (object) $batch_row : null;

        $transactions = [];
        $export_rows = [];
        if ( $batch ) {
            $transactions = array_map( static function ( array $row ) {
                return (object) $row;
            }, $db->fetchAll(
                "SELECT t.*, d.first_name, d.last_name, d.email, c.cname AS campaign_name
                 FROM {$transactions_table} t
                 LEFT JOIN {$contacts_table} d ON d.did = t.did
                 LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
                 WHERE t.deposit_batch_id = %s
                 ORDER BY t.tran_date ASC, t.id ASC",
                [ $batch->batch_code ]
            ) ?: [] );

            foreach ( $transactions as $transaction ) {
                $donor = trim( ( $transaction->first_name ?: '' ) . ' ' . ( $transaction->last_name ?: '' ) ) ?: ( $transaction->email ?: 'Unknown' );
                $export_rows[] = [
                    'batch_code' => $batch->batch_code,
                    'tid' => $transaction->tid,
                    'tran_date' => $transaction->tran_date ? date( 'm/d/Y', strtotime( $transaction->tran_date ) ) : '',
                    'donor_name' => $donor,
                    'email' => $transaction->email ?? '',
                    'campaign' => $transaction->campaign_name ?: $transaction->campaign_code ?: '',
                    'amount' => (float) $transaction->amount,
                    'fee' => isset( $transaction->fee ) ? (float) $transaction->fee : 0,
                    'net' => (float) $transaction->amount - ( isset( $transaction->fee ) ? (float) $transaction->fee : 0 ),
                    'status' => $transaction->status ?? '',
                    'payment_method' => $transaction->payment_method ?? '',
                ];
            }
        }

        return [
            'batch' => $batch,
            'transactions' => $transactions,
            'export_rows' => $export_rows,
            'batch_notes' => $batch ? \metis_get_batch_notes( (string) $batch->batch_code ) : [],
        ];
    }

    public static function campaignDetailSnapshot( string $cid ): array {
        $db = \metis_db();
        $campaigns_table = \Metis_Tables::get( 'campaigns' );
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $contacts_table = \Metis_Tables::get( 'contacts' );

        $campaign_row = $db->fetchOne( "SELECT * FROM {$campaigns_table} WHERE cid = %s LIMIT 1", [ $cid ] );
        $campaign = $campaign_row ? (object) $campaign_row : null;

        $agg = null;
        $yearly = [];
        $transactions = [];
        $year_raised = 0.0;
        if ( $campaign ) {
            $agg = (object) ( $db->fetchOne(
                "SELECT
                    COUNT(*) AS gift_count,
                    SUM(amount) AS total_raised,
                    SUM(amount + IFNULL(fee, 0)) AS total_gross,
                    MIN(tran_date) AS first_gift,
                    MAX(tran_date) AS last_gift,
                    COUNT(DISTINCT did) AS donor_count
                 FROM {$transactions_table}
                 WHERE campaign_code = %s",
                [ $cid ]
            ) ?: [] );

            $yearly = array_map( static function ( array $row ) {
                return (object) $row;
            }, $db->fetchAll(
                "SELECT
                    YEAR(tran_date) AS year,
                    COUNT(*) AS gift_count,
                    SUM(amount) AS raised,
                    COUNT(DISTINCT did) AS donors
                 FROM {$transactions_table}
                 WHERE campaign_code = %s
                 GROUP BY YEAR(tran_date)
                 ORDER BY year DESC",
                [ $cid ]
            ) ?: [] );

            $transactions = array_map( static function ( array $row ) {
                return (object) $row;
            }, $db->fetchAll(
                "SELECT t.*, c.first_name, c.last_name, c.email
                 FROM {$transactions_table} t
                 LEFT JOIN {$contacts_table} c ON c.did = t.did
                 WHERE t.campaign_code = %s
                 ORDER BY t.tran_date DESC, t.id DESC
                 LIMIT 100",
                [ $cid ]
            ) ?: [] );

            $current_year = (int) date( 'Y' );
            $year_raised_raw = $db->scalar(
                "SELECT SUM(amount) FROM {$transactions_table} WHERE campaign_code = %s AND YEAR(tran_date) = %d",
                [ $cid, $current_year ]
            );
            $year_raised = (float) ( $year_raised_raw ?? 0 );
        }

        return [
            'campaign' => $campaign,
            'agg' => $agg,
            'yearly' => $yearly,
            'transactions' => $transactions,
            'year_raised' => $year_raised,
        ];
    }

    public static function dashboardSnapshot(): array {
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $campaigns_table = \Metis_Tables::get( 'campaigns' );

        $now_dt = \metis_current_datetime();
        $today_sql = $now_dt->format( 'Y-m-d H:i:s' );
        $last_30_start = ( clone $now_dt )->modify( '-29 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
        $prev_30_start = ( clone $now_dt )->modify( '-59 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
        $prev_30_end = ( clone $now_dt )->modify( '-30 days' )->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' );
        $month_start = ( clone $now_dt )->modify( 'first day of this month' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
        $year_start = ( clone $now_dt )->setDate( (int) $now_dt->format( 'Y' ), 1, 1 )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
        $trend_month_start = ( clone $now_dt )->modify( '-11 months' )->modify( 'first day of this month' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
        $current_year = (int) $now_dt->format( 'Y' );

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
            [ $trend_month_start ]
        ) ?: [];

        $raised_30d = (float) ( $kpis->raised_30d ?? 0 );
        $previous_30d = (float) ( $comparison->previous_30d ?? 0 );
        $current_gifts = (int) ( $comparison->current_gifts ?? 0 );
        $previous_gifts = (int) ( $comparison->previous_gifts ?? 0 );
        $avg_gift_30d = $current_gifts > 0 ? $raised_30d / $current_gifts : 0.0;
        $queue_total = (float) ( $kpis->open_deposit_total ?? 0 );
        $queue_gifts = (int) ( $kpis->open_deposit_count ?? 0 );
        $active_campaigns = (int) ( $campaign_counts->active_campaigns ?? 0 );
        $total_campaigns = (int) ( $campaign_counts->total_campaigns ?? 0 );
        $covered_gifts = $queue_gifts > 0 ? max( 0, (int) ( $kpis->total_gifts ?? 0 ) - $queue_gifts ) : (int) ( $kpis->total_gifts ?? 0 );

        $raised_delta = $previous_30d > 0 ? ( ( $raised_30d - $previous_30d ) / $previous_30d ) * 100 : null;
        $gift_delta = $previous_gifts > 0 ? ( ( $current_gifts - $previous_gifts ) / $previous_gifts ) * 100 : null;

        [ $raised_delta_label, $raised_delta_class ] = self::formatDelta( $raised_delta );
        [ $gift_delta_label, $gift_delta_class ] = self::formatDelta( $gift_delta );

        $daily_trend_map = [];
        foreach ( $daily_trend_raw as $row ) {
            $daily_trend_map[ (string) $row['trend_day'] ] = [
                'amount' => (float) $row['total_amount'],
                'count' => (int) $row['gift_count'],
            ];
        }

        $daily_trend = [];
        for ( $i = 29; $i >= 0; $i-- ) {
            $day = ( clone $now_dt )->modify( '-' . $i . ' days' );
            $key = $day->format( 'Y-m-d' );
            $daily_trend[] = [
                'key' => $key,
                'label' => $day->format( 'M j' ),
                'amount' => (float) ( $daily_trend_map[ $key ]['amount'] ?? 0 ),
                'count' => (int) ( $daily_trend_map[ $key ]['count'] ?? 0 ),
            ];
        }

        $monthly_trend_map = [];
        foreach ( $monthly_trend_raw as $row ) {
            $monthly_trend_map[ (string) $row['trend_month'] ] = (float) $row['total_amount'];
        }

        $monthly_trend = [];
        for ( $i = 11; $i >= 0; $i-- ) {
            $month = ( clone $now_dt )->modify( '-' . $i . ' months' );
            $key = $month->format( 'Y-m' );
            $monthly_trend[] = [
                'key' => $key,
                'label' => $month->format( 'M' ),
                'amount' => (float) ( $monthly_trend_map[ $key ] ?? 0 ),
            ];
        }

        return [
            'current_year' => $current_year,
            'current_month_label' => $now_dt->format( 'F Y' ),
            'kpis' => $kpis,
            'comparison' => $comparison,
            'campaign_counts' => $campaign_counts,
            'recent_transactions' => $recent_transactions,
            'top_donors' => $top_donors,
            'top_campaigns' => $top_campaigns,
            'method_breakdown' => $method_breakdown,
            'platform_breakdown' => $platform_breakdown,
            'open_deposit_rows' => $open_deposit_rows,
            'recent_deposits' => array_slice( \metis_get_deposits(), 0, 5 ),
            'raised_30d' => $raised_30d,
            'previous_30d' => $previous_30d,
            'current_gifts' => $current_gifts,
            'previous_gifts' => $previous_gifts,
            'avg_gift_30d' => $avg_gift_30d,
            'queue_total' => $queue_total,
            'queue_gifts' => $queue_gifts,
            'covered_gifts' => $covered_gifts,
            'active_campaigns' => $active_campaigns,
            'total_campaigns' => $total_campaigns,
            'raised_delta' => $raised_delta,
            'gift_delta' => $gift_delta,
            'raised_delta_label' => $raised_delta_label,
            'raised_delta_class' => $raised_delta_class,
            'gift_delta_label' => $gift_delta_label,
            'gift_delta_class' => $gift_delta_class,
            'daily_trend' => $daily_trend,
            'monthly_trend' => $monthly_trend,
        ];
    }

    public static function transactionDetailSnapshot( string $tid ): array {
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $campaigns_table = \Metis_Tables::get( 'campaigns' );
        $refunds_table = \Metis_Tables::get( 'transaction_refunds' );
        $notes_table = \Metis_Tables::get( 'transaction_notes' );
        $auth_users_table = \Metis_Tables::get( 'auth_users' );

        $transaction_row = $db->fetchOne(
            "SELECT t.*, c.cname AS campaign_name,
                    d.first_name, d.last_name, d.email, d.did AS donor_did
             FROM {$transactions_table} t
             LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
             LEFT JOIN {$contacts_table} d ON d.did = t.did
             WHERE t.tid = %s
             LIMIT 1",
            [ $tid ]
        );
        $transaction = $transaction_row ? (object) $transaction_row : null;

        $refunds = [];
        $notes = [];
        if ( $transaction ) {
            $refund_columns = $db->column( "SHOW COLUMNS FROM {$refunds_table}" );
            $refund_user_column = in_array( 'created_by', $refund_columns, true )
                ? 'created_by'
                : ( in_array( 'refunded_by', $refund_columns, true ) ? 'refunded_by' : '' );
            $refund_date_expr = in_array( 'refund_date', $refund_columns, true )
                ? 'COALESCE(r.refund_date, r.created_at)'
                : 'r.created_at';
            $refund_user_join = $refund_user_column !== ''
                ? "LEFT JOIN {$auth_users_table} u ON u.id = r.{$refund_user_column}"
                : "LEFT JOIN {$auth_users_table} u ON 1 = 0";

            $refunds = array_map( static function ( array $row ) {
                return (object) $row;
            }, $db->fetchAll(
                "SELECT r.*, {$refund_date_expr} AS refund_display_date, u.display_name
                 FROM {$refunds_table} r
                 {$refund_user_join}
                 WHERE r.tid = %s ORDER BY r.created_at DESC",
                [ $tid ]
            ) ?: [] );

            $notes = array_map( static function ( array $row ) {
                return (object) $row;
            }, $db->fetchAll(
                "SELECT n.*, u.display_name
                 FROM {$notes_table} n
                 LEFT JOIN {$auth_users_table} u ON u.id = n.user_id
                 WHERE n.tid = %s ORDER BY n.created_at DESC",
                [ $tid ]
            ) ?: [] );
        }

        return [
            'transaction' => $transaction,
            'refunds' => $refunds,
            'notes' => $notes,
        ];
    }

    public static function transactionsSnapshot(): array {
        $db = \metis_db();
        $contacts_table = \Metis_Tables::get( 'contacts' );
        $transactions_table = \Metis_Tables::get( 'transactions' );
        $campaigns_table = \Metis_Tables::get( 'campaigns' );

        $campaign_options = array_map( static function ( array $row ) {
            return (object) $row;
        }, $db->fetchAll(
            "SELECT cid, cname, active
             FROM {$campaigns_table}
             ORDER BY active DESC, cname ASC, cid ASC"
        ) ?: [] );

        $transactions = array_map( static function ( array $row ) {
            return (object) $row;
        }, $db->fetchAll(
            "SELECT
                t.*,
                c.cname AS campaign_name,
                d.first_name,
                d.last_name,
                d.email
             FROM {$transactions_table} t
             LEFT JOIN {$campaigns_table} c ON c.cid = t.campaign_code
             LEFT JOIN {$contacts_table} d ON d.did = t.did
             ORDER BY t.tran_date DESC, t.id DESC
             LIMIT 500"
        ) ?: [] );

        return [
            'campaign_options' => $campaign_options,
            'transactions' => $transactions,
        ];
    }

    private static function formatDelta( ?float $value, string $suffix = '%' ): array {
        if ( $value === null ) {
            return [ 'No prior period', 'neutral' ];
        }

        if ( abs( $value ) < 0.05 ) {
            return [ 'Flat vs previous period', 'neutral' ];
        }

        $direction = $value > 0 ? 'up' : 'down';
        $class = $value > 0 ? 'positive' : 'negative';
        return [ sprintf( '%s %s%s vs previous period', $direction, number_format( abs( $value ), 1 ), $suffix ), $class ];
    }
}
