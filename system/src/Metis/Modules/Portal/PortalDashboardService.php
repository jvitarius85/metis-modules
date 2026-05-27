<?php
declare(strict_types=1);

namespace Metis\Modules\Portal;

final class PortalDashboardService {
    public static function tableExists( object $db, string $table ): bool {
        $exists = $db->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
        return $exists === $table;
    }

    public static function donationStats(
        object $db,
        string $transactions_table,
        string $campaigns_table,
        string $last_30_start,
        string $month_start,
        string $year_start,
        bool $has_campaigns_table
    ): array {
        return self::remember(
            'donations.' . md5( $transactions_table . '|' . $campaigns_table . '|' . $month_start . '|' . $year_start ),
            120,
            static function () use ( $db, $transactions_table, $campaigns_table, $last_30_start, $month_start, $year_start, $has_campaigns_table ): array {
                $donation_summary = (array) ( $db->fetchOne(
                    "
                    SELECT
                        COALESCE(SUM(CASE WHEN tran_date >= %s THEN amount ELSE 0 END), 0) AS raised_30d,
                        COALESCE(SUM(CASE WHEN tran_date >= %s THEN amount ELSE 0 END), 0) AS raised_month,
                        COALESCE(SUM(CASE WHEN tran_date >= %s THEN amount ELSE 0 END), 0) AS raised_ytd,
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
                    [ $last_30_start, $month_start, $year_start ]
                ) ?? [] );

                $campaign_counts = [
                    'total_campaigns' => 0,
                    'active_campaigns' => 0,
                ];

                if ( $has_campaigns_table ) {
                    $campaign_counts = (array) ( $db->fetchOne(
                        "
                        SELECT
                            COUNT(*) AS total_campaigns,
                            SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_campaigns
                        FROM {$campaigns_table}
                        "
                    ) ?? [] );
                }

                return [
                    'raised_30d' => (float) ( $donation_summary['raised_30d'] ?? 0 ),
                    'raised_month' => (float) ( $donation_summary['raised_month'] ?? 0 ),
                    'raised_ytd' => (float) ( $donation_summary['raised_ytd'] ?? 0 ),
                    'open_deposit_total' => (float) ( $donation_summary['open_deposit_total'] ?? 0 ),
                    'open_deposit_count' => (int) ( $donation_summary['open_deposit_count'] ?? 0 ),
                    'total_campaigns' => (int) ( $campaign_counts['total_campaigns'] ?? 0 ),
                    'active_campaigns' => (int) ( $campaign_counts['active_campaigns'] ?? 0 ),
                ];
            }
        );
    }

    public static function contactsStats( object $db, string $contacts_table ): array {
        return self::remember(
            'contacts.' . md5( $contacts_table ),
            180,
            static function () use ( $db, $contacts_table ): array {
                $counts = (array) ( $db->fetchOne(
                    "SELECT
                        COUNT(*) AS total_contacts,
                        SUM(CASE WHEN did IS NOT NULL AND did <> '' THEN 1 ELSE 0 END) AS with_did
                     FROM {$contacts_table}"
                ) ?? [] );
                $duplicate_count = (int) $db->scalar(
                    "SELECT COUNT(*)
                     FROM (
                        SELECT LOWER(TRIM(email)) AS email_key
                        FROM {$contacts_table}
                        WHERE email IS NOT NULL AND TRIM(email) <> ''
                        GROUP BY LOWER(TRIM(email))
                        HAVING COUNT(*) > 1
                     ) duplicate_groups"
                );

                $total_contacts = (int) ( $counts['total_contacts'] ?? 0 );
                $with_did = (int) ( $counts['with_did'] ?? 0 );

                return [
                    'total_contacts' => $total_contacts,
                    'with_did' => $with_did,
                    'without_did' => max( 0, $total_contacts - $with_did ),
                    'duplicate_count' => $duplicate_count,
                ];
            }
        );
    }

    public static function newsletterStats(
        object $db,
        string $newsletter_lists_table,
        string $newsletter_subs_table,
        string $newsletter_campaigns_table,
        string $newsletter_messages_table,
        int $newsletter_sent_30d = 0
    ): array {
        return self::remember(
            'newsletter.' . md5( $newsletter_lists_table . '|' . $newsletter_subs_table . '|' . $newsletter_campaigns_table . '|' . $newsletter_messages_table . '|' . $newsletter_sent_30d ),
            120,
            static function () use ( $db, $newsletter_lists_table, $newsletter_subs_table, $newsletter_campaigns_table, $newsletter_messages_table, $newsletter_sent_30d ): array {
                $counts = (array) ( $db->fetchOne(
                    "SELECT
                        (SELECT COUNT(*) FROM {$newsletter_subs_table} WHERE status = 'subscribed') AS subscribers,
                        (SELECT COUNT(*) FROM {$newsletter_messages_table} WHERE status = 'queued') AS queued,
                        (SELECT COUNT(*) FROM {$newsletter_lists_table} WHERE is_active = 1) AS active_lists,
                        (SELECT COUNT(*) FROM {$newsletter_campaigns_table}) AS total_campaigns"
                ) ?? [] );

                return [
                    'subscribers' => (int) ( $counts['subscribers'] ?? 0 ),
                    'queued' => (int) ( $counts['queued'] ?? 0 ),
                    'active_lists' => (int) ( $counts['active_lists'] ?? 0 ),
                    'total_campaigns' => (int) ( $counts['total_campaigns'] ?? 0 ),
                    'sent_30d' => $newsletter_sent_30d,
                ];
            }
        );
    }

    public static function newsletterSent30d( object $db, string $newsletter_messages_table ): int {
        if ( ! function_exists( 'metis_newsletter_resolved_timezone' ) ) {
            return 0;
        }

        return (int) $db->scalar(
            "SELECT COUNT(*) FROM {$newsletter_messages_table} WHERE status='sent' AND sent_at >= %s",
            [ ( new \DateTimeImmutable( 'now', \metis_newsletter_resolved_timezone() ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' ) ]
        );
    }

    public static function peopleStats( object $db, string $people_table, ?string $requests_table = null ): array {
        return self::remember(
            'people.' . md5( $people_table . '|' . ( $requests_table ?? '' ) ),
            180,
            static function () use ( $db, $people_table, $requests_table ): array {
                $counts = (array) ( $db->fetchOne(
                    "SELECT
                        COUNT(*) AS total_people,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_people,
                        SUM(CASE WHEN is_staff = 1 THEN 1 ELSE 0 END) AS staff_count,
                        SUM(CASE WHEN is_board = 1 THEN 1 ELSE 0 END) AS board_count,
                        SUM(CASE WHEN is_workspace_user = 1 THEN 1 ELSE 0 END) AS workspace_count
                     FROM {$people_table}"
                ) ?? [] );

                return [
                    'total_people' => (int) ( $counts['total_people'] ?? 0 ),
                    'active_people' => (int) ( $counts['active_people'] ?? 0 ),
                    'staff_count' => (int) ( $counts['staff_count'] ?? 0 ),
                    'board_count' => (int) ( $counts['board_count'] ?? 0 ),
                    'workspace_count' => (int) ( $counts['workspace_count'] ?? 0 ),
                    'pending_requests' => $requests_table !== null ? (int) $db->scalar( "SELECT COUNT(*) FROM {$requests_table} WHERE status = 'pending'" ) : 0,
                ];
            }
        );
    }

    public static function jobStats( object $db, string $job_queue_table ): array {
        return self::remember(
            'jobs.' . md5( $job_queue_table ),
            60,
            static function () use ( $db, $job_queue_table ): array {
                $counts = (array) ( $db->fetchOne(
                    "SELECT
                        SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS pending_jobs_count,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_jobs_count
                     FROM {$job_queue_table}"
                ) ?? [] );

                return [
                    'pending_jobs_count' => (int) ( $counts['pending_jobs_count'] ?? 0 ),
                    'failed_jobs_count' => (int) ( $counts['failed_jobs_count'] ?? 0 ),
                ];
            }
        );
    }

    public static function boardOverviewStats(
        object $db,
        string $board_meetings_table,
        string $board_actions_table,
        string $board_decisions_table,
        string $board_committees_table,
        string $now_mysql
    ): array {
        return self::remember(
            'board_overview.' . md5( $board_meetings_table . '|' . $board_actions_table . '|' . $board_decisions_table . '|' . $board_committees_table . '|' . $now_mysql ),
            120,
            static function () use ( $db, $board_meetings_table, $board_actions_table, $board_decisions_table, $board_committees_table, $now_mysql ): array {
                $counts = (array) ( $db->fetchOne(
                    "SELECT
                        (SELECT COUNT(*) FROM {$board_meetings_table}) AS total_meetings,
                        (SELECT COUNT(*) FROM {$board_meetings_table} WHERE meeting_date >= %s AND status IN ('scheduled','draft')) AS upcoming_meetings,
                        (SELECT COUNT(*) FROM {$board_actions_table} WHERE status <> 'done') AS open_actions,
                        (SELECT COUNT(*) FROM {$board_decisions_table}) AS decision_count,
                        (SELECT COUNT(*) FROM {$board_committees_table} WHERE is_active = 1) AS committee_count",
                    [ $now_mysql ]
                ) ?? [] );

                return [
                    'total_meetings' => (int) ( $counts['total_meetings'] ?? 0 ),
                    'upcoming_meetings' => (int) ( $counts['upcoming_meetings'] ?? 0 ),
                    'open_actions' => (int) ( $counts['open_actions'] ?? 0 ),
                    'decision_count' => (int) ( $counts['decision_count'] ?? 0 ),
                    'committee_count' => (int) ( $counts['committee_count'] ?? 0 ),
                ];
            }
        );
    }

    public static function financeSettlementStats( object $db, string $deposits_table, string $month_start, ?string $recons_table = null ): array {
        return self::remember(
            'finance_settlement.' . md5( $deposits_table . '|' . ( $recons_table ?? '' ) . '|' . $month_start ),
            120,
            static function () use ( $db, $deposits_table, $month_start, $recons_table ): array {
                $deposit_summary = (array) ( $db->fetchOne(
                    "
                    SELECT
                        COALESCE(SUM(CASE WHEN deposit_date >= %s THEN COALESCE(net_total, total_amount, 0) ELSE 0 END), 0) AS month_net,
                        COALESCE(SUM(CASE WHEN status IN ('pending', 'in_transit') THEN COALESCE(net_total, total_amount, 0) ELSE 0 END), 0) AS pending_total,
                        COALESCE(SUM(CASE WHEN status IN ('pending', 'in_transit') THEN 1 ELSE 0 END), 0) AS pending_count
                    FROM {$deposits_table}
                    ",
                    [ $month_start ]
                ) ?? [] );

                return [
                    'month_net' => (float) ( $deposit_summary['month_net'] ?? 0 ),
                    'pending_total' => (float) ( $deposit_summary['pending_total'] ?? 0 ),
                    'pending_count' => (int) ( $deposit_summary['pending_count'] ?? 0 ),
                    'open_recons' => $recons_table !== null ? (int) $db->scalar( "SELECT COUNT(*) FROM {$recons_table} WHERE status <> 'finalized'" ) : 0,
                ];
            }
        );
    }

    public static function watchStats( object $db, array $tables, string $now_mysql, string $next_week_mysql ): array {
        return self::remember(
            'watch.' . md5( implode( '|', $tables ) . '|' . $now_mysql . '|' . $next_week_mysql ),
            60,
            static function () use ( $db, $tables, $now_mysql, $next_week_mysql ): array {
                [ $board_meetings_table, $board_actions_table, $requests_table, $newsletter_messages_table, $transactions_table ] = $tables;
                $stats = [
                    'upcoming_meetings' => 0,
                    'open_board_actions' => 0,
                    'pending_requests' => 0,
                    'queued_emails' => 0,
                    'open_deposit_count' => 0,
                    'open_deposit_total' => 0.0,
                ];

                if ( $board_meetings_table !== '' ) {
                    $stats['upcoming_meetings'] = (int) $db->scalar(
                        "SELECT COUNT(*) FROM {$board_meetings_table} WHERE meeting_date >= %s AND meeting_date <= %s AND status IN ('scheduled','draft')",
                        [ $now_mysql, $next_week_mysql ]
                    );
                }
                if ( $board_actions_table !== '' ) {
                    $stats['open_board_actions'] = (int) $db->scalar( "SELECT COUNT(*) FROM {$board_actions_table} WHERE status <> 'done'" );
                }
                if ( $requests_table !== '' ) {
                    $stats['pending_requests'] = (int) $db->scalar( "SELECT COUNT(*) FROM {$requests_table} WHERE status = 'pending'" );
                }
                if ( $newsletter_messages_table !== '' ) {
                    $stats['queued_emails'] = (int) $db->scalar( "SELECT COUNT(*) FROM {$newsletter_messages_table} WHERE status = 'queued'" );
                }
                if ( $transactions_table !== '' ) {
                    $open_queue_row = (array) ( $db->fetchOne(
                        "SELECT
                            COUNT(CASE WHEN (deposit_batch_id IS NULL OR deposit_batch_id = '') AND LOWER(COALESCE(status, '')) = 'completed' THEN 1 ELSE NULL END) AS open_count,
                            COALESCE(SUM(CASE WHEN (deposit_batch_id IS NULL OR deposit_batch_id = '') AND LOWER(COALESCE(status, '')) = 'completed' THEN amount ELSE 0 END), 0) AS open_total
                         FROM {$transactions_table}"
                    ) ?? [] );
                    $stats['open_deposit_count'] = (int) ( $open_queue_row['open_count'] ?? 0 );
                    $stats['open_deposit_total'] = (float) ( $open_queue_row['open_total'] ?? 0 );
                }

                return $stats;
            }
        );
    }

    public static function forgetAll(): void {
        if ( class_exists( '\Metis\Core\Cache\CacheService' ) ) {
            \Metis\Core\Cache\CacheService::clearByPrefix( 'query.portal_dashboard.' );
        }
    }

    private static function remember( string $key, int $ttl, callable $resolver ): mixed {
        if ( class_exists( '\Metis\Core\Cache\CacheService' ) ) {
            return \Metis\Core\Cache\CacheService::remember( 'query.portal_dashboard.' . $key, $ttl, $resolver );
        }

        return $resolver();
    }
}
