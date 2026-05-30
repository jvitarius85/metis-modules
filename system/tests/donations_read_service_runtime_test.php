<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

final class Metis_Tables {
    public static function get( string $table ): string {
        return 'metis_' . $table;
    }
}

final class MetisFakeDonationsReadDb {
    public array $fetchOneCalls = [];
    public array $fetchAllCalls = [];

    public function fetchOne( string $sql, array $params = [] ): array {
        $this->fetchOneCalls[] = [ $sql, $params ];

        if ( str_contains( $sql, 'COUNT(*) AS total_gifts' ) ) {
            return [
                'total_gifts' => 12,
                'lifetime_raised' => 5000,
                'raised_30d' => 1200,
                'gifts_30d' => 12,
                'raised_month' => 800,
                'raised_ytd' => 3000,
                'donors_ytd' => 9,
                'open_deposit_total' => 400,
                'open_deposit_count' => 2,
            ];
        }

        if ( str_contains( $sql, 'current_30d' ) && str_contains( $sql, 'previous_30d' ) ) {
            return [
                'current_30d' => 1200,
                'previous_30d' => 600,
                'current_gifts' => 12,
                'previous_gifts' => 6,
            ];
        }

        if ( str_contains( $sql, 'total_campaigns' ) && str_contains( $sql, 'active_campaigns' ) ) {
            return [
                'total_campaigns' => 5,
                'active_campaigns' => 3,
            ];
        }

        return [];
    }

    public function fetchAll( string $sql, array $params = [] ): array {
        $this->fetchAllCalls[] = [ $sql, $params ];

        if ( str_contains( $sql, 'LIMIT 8' ) && str_contains( $sql, 'campaign_name' ) ) {
            return [
                [
                    'tid' => 'T-100',
                    'did' => 'D-1',
                    'amount' => 250,
                    'status' => 'completed',
                    'payment_method' => 'card',
                    'tran_date' => '2026-05-30 10:00:00',
                    'deposit_batch_id' => '',
                    'platform' => 'stripe',
                    'campaign_name' => 'Spring Appeal',
                    'first_name' => 'Ada',
                    'last_name' => 'Lovelace',
                    'email' => 'ada@example.com',
                ],
            ];
        }

        if ( str_contains( $sql, 'donor_name' ) && str_contains( $sql, 'GROUP BY t.did' ) ) {
            return [
                [
                    'did' => 'D-1',
                    'donor_name' => 'Ada Lovelace',
                    'email' => 'ada@example.com',
                    'gift_count' => 4,
                    'total_raised' => 1500,
                    'last_gift_date' => '2026-05-30 10:00:00',
                ],
            ];
        }

        if ( str_contains( $sql, 'year_raised' ) && str_contains( $sql, 'lifetime_raised' ) ) {
            return [
                [
                    'cid' => 'C-1',
                    'cname' => 'Spring Appeal',
                    'active' => 1,
                    'type' => 'annual',
                    'goals' => '{"2026":5000}',
                    'year_raised' => 2200,
                    'lifetime_raised' => 4000,
                    'gift_count' => 8,
                ],
            ];
        }

        if ( str_contains( $sql, "payment_method" ) && str_contains( $sql, 'GROUP BY COALESCE(NULLIF(payment_method' ) ) {
            return [
                [ 'payment_method' => 'card', 'gift_count' => 10, 'total_amount' => 3200 ],
            ];
        }

        if ( str_contains( $sql, "platform_code" ) && str_contains( $sql, 'GROUP BY COALESCE(NULLIF(platform' ) && ! str_contains( $sql, 'oldest_tran_date' ) ) {
            return [
                [ 'platform_code' => 'stripe', 'gift_count' => 10, 'total_amount' => 3200 ],
            ];
        }

        if ( str_contains( $sql, 'oldest_tran_date' ) ) {
            return [
                [ 'platform_code' => 'stripe', 'gift_count' => 2, 'total_amount' => 400, 'oldest_tran_date' => '2026-05-28 09:00:00' ],
            ];
        }

        if ( str_contains( $sql, 'DATE(tran_date) AS trend_day' ) ) {
            return [
                [ 'trend_day' => '2026-05-01', 'total_amount' => 100, 'gift_count' => 1 ],
                [ 'trend_day' => '2026-05-30', 'total_amount' => 250, 'gift_count' => 2 ],
            ];
        }

        if ( str_contains( $sql, "DATE_FORMAT(tran_date, '%%Y-%%m')" ) ) {
            return [
                [ 'trend_month' => '2025-12', 'total_amount' => 900 ],
                [ 'trend_month' => '2026-05', 'total_amount' => 2000 ],
            ];
        }

        return [];
    }
}

function metis_db(): MetisFakeDonationsReadDb {
    static $db = null;
    if ( ! $db instanceof MetisFakeDonationsReadDb ) {
        $db = new MetisFakeDonationsReadDb();
    }
    return $db;
}

function metis_current_datetime(): DateTimeImmutable {
    return new DateTimeImmutable( '2026-05-30 12:00:00', new DateTimeZone( 'UTC' ) );
}

function metis_get_deposits(): array {
    return [
        [ 'provider_ref' => 'DEP-1' ],
        [ 'provider_ref' => 'DEP-2' ],
        [ 'provider_ref' => 'DEP-3' ],
        [ 'provider_ref' => 'DEP-4' ],
        [ 'provider_ref' => 'DEP-5' ],
        [ 'provider_ref' => 'DEP-6' ],
    ];
}

require_once dirname( __DIR__ ) . '/src/Metis/Modules/Donations/ReadService.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$snapshot = \Metis\Modules\Donations\ReadService::dashboardSnapshot();
$db = metis_db();

$assert( ( $snapshot['current_year'] ?? null ) === 2026, 'Dashboard snapshot must use the current year from runtime time.' );
$assert( ( $snapshot['current_month_label'] ?? '' ) === 'May 2026', 'Dashboard snapshot must expose the current month label.' );
$assert( abs( (float) ( $snapshot['raised_30d'] ?? 0 ) - 1200.0 ) < 0.001, 'Dashboard snapshot must expose 30-day raised total.' );
$assert( (int) ( $snapshot['current_gifts'] ?? 0 ) === 12, 'Dashboard snapshot must expose current gift count.' );
$assert( abs( (float) ( $snapshot['avg_gift_30d'] ?? 0 ) - 100.0 ) < 0.001, 'Dashboard snapshot must compute average gift size.' );
$assert( abs( (float) ( $snapshot['queue_total'] ?? 0 ) - 400.0 ) < 0.001, 'Dashboard snapshot must expose open deposit queue total.' );
$assert( (int) ( $snapshot['queue_gifts'] ?? 0 ) === 2, 'Dashboard snapshot must expose open deposit queue count.' );
$assert( (int) ( $snapshot['covered_gifts'] ?? 0 ) === 10, 'Dashboard snapshot must compute covered gift count.' );
$assert( (int) ( $snapshot['active_campaigns'] ?? 0 ) === 3 && (int) ( $snapshot['total_campaigns'] ?? 0 ) === 5, 'Dashboard snapshot must expose campaign counts.' );
$assert( ( $snapshot['raised_delta_label'] ?? '' ) === 'up 100.0% vs previous period', 'Dashboard snapshot must format raised delta label.' );
$assert( ( $snapshot['raised_delta_class'] ?? '' ) === 'positive', 'Dashboard snapshot must classify positive delta.' );
$assert( ( $snapshot['gift_delta_label'] ?? '' ) === 'up 100.0% vs previous period', 'Dashboard snapshot must format gift delta label.' );

$dailyTrend = $snapshot['daily_trend'] ?? [];
$monthlyTrend = $snapshot['monthly_trend'] ?? [];
$assert( is_array( $dailyTrend ) && count( $dailyTrend ) === 30, 'Dashboard snapshot must build a 30-day trend series.' );
$assert( is_array( $monthlyTrend ) && count( $monthlyTrend ) === 12, 'Dashboard snapshot must build a 12-month trend series.' );
$assert( ( $dailyTrend[0]['key'] ?? '' ) === '2026-05-01', 'Daily trend must start 29 days before the current day.' );
$assert( (float) ( $dailyTrend[0]['amount'] ?? -1 ) === 100.0, 'Daily trend must map returned daily amounts.' );
$assert( ( $dailyTrend[29]['key'] ?? '' ) === '2026-05-30' && (float) ( $dailyTrend[29]['amount'] ?? -1 ) === 250.0, 'Daily trend must include the current day point.' );
$assert( (float) ( $dailyTrend[1]['amount'] ?? -1 ) === 0.0, 'Daily trend must fill missing days with zero amounts.' );
$assert( ( $monthlyTrend[11]['key'] ?? '' ) === '2026-05' && (float) ( $monthlyTrend[11]['amount'] ?? -1 ) === 2000.0, 'Monthly trend must include the current month point.' );
$assert( count( $snapshot['recent_deposits'] ?? [] ) === 5, 'Dashboard snapshot must cap recent deposits to five rows.' );
$assert( count( $snapshot['recent_transactions'] ?? [] ) === 1, 'Dashboard snapshot must return recent transaction objects.' );
$assert( count( $snapshot['top_donors'] ?? [] ) === 1, 'Dashboard snapshot must return top donor objects.' );
$assert( count( $snapshot['top_campaigns'] ?? [] ) === 1, 'Dashboard snapshot must return top campaign objects.' );
$assert( count( $snapshot['method_breakdown'] ?? [] ) === 1, 'Dashboard snapshot must return payment method breakdown rows.' );
$assert( count( $snapshot['platform_breakdown'] ?? [] ) === 1, 'Dashboard snapshot must return platform breakdown rows.' );
$assert( count( $snapshot['open_deposit_rows'] ?? [] ) === 1, 'Dashboard snapshot must return open deposit queue rows.' );
$assert( count( $db->fetchOneCalls ) === 3, 'Dashboard snapshot must perform the expected aggregate fetchOne calls.' );
$assert( count( $db->fetchAllCalls ) === 8, 'Dashboard snapshot must perform the expected fetchAll calls.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Donations read service runtime checks passed.\n" );
