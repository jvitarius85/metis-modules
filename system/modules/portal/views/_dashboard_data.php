<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_portal_dashboard_table_exists' ) ) {
    function metis_portal_dashboard_table_exists( object $db, string $table ): bool {
        $exists = $db->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
        return $exists === $table;
    }
}

if ( ! function_exists( 'metis_portal_dashboard_remember' ) ) {
    function metis_portal_dashboard_remember( string $key, int $ttl, callable $resolver ): mixed {
        if ( class_exists( '\Metis\Core\Cache\CacheService' ) ) {
            return \Metis\Core\Cache\CacheService::remember( 'query.portal_dashboard.' . $key, $ttl, $resolver );
        }

        return $resolver();
    }
}

if ( ! function_exists( 'metis_portal_dashboard_forget_all' ) ) {
    function metis_portal_dashboard_forget_all(): void {
        if ( class_exists( '\Metis\Core\Cache\CacheService' ) ) {
            \Metis\Core\Cache\CacheService::clearByPrefix( 'query.portal_dashboard.' );
        }
    }
}

if ( ! function_exists( 'metis_portal_dashboard_donation_stats' ) ) {
    function metis_portal_dashboard_donation_stats( object $db, string $transactions_table, string $campaigns_table, string $last_30_start, string $month_start, string $year_start, bool $has_campaigns_table ): array {
        return metis_portal_dashboard_remember(
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
}

if ( ! function_exists( 'metis_portal_dashboard_contacts_stats' ) ) {
    function metis_portal_dashboard_contacts_stats( object $db, string $contacts_table ): array {
        return metis_portal_dashboard_remember(
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
}

if ( ! function_exists( 'metis_portal_dashboard_newsletter_stats' ) ) {
    function metis_portal_dashboard_newsletter_stats( object $db, string $newsletter_lists_table, string $newsletter_subs_table, string $newsletter_campaigns_table, string $newsletter_messages_table, int $newsletter_sent_30d = 0 ): array {
        return metis_portal_dashboard_remember(
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
}

if ( ! function_exists( 'metis_portal_dashboard_people_stats' ) ) {
    function metis_portal_dashboard_people_stats( object $db, string $people_table, ?string $requests_table = null ): array {
        return metis_portal_dashboard_remember(
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
}

if ( ! function_exists( 'metis_portal_dashboard_job_stats' ) ) {
    function metis_portal_dashboard_job_stats( object $db, string $job_queue_table ): array {
        return metis_portal_dashboard_remember(
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
}

if ( ! function_exists( 'metis_portal_dashboard_board_overview_stats' ) ) {
    function metis_portal_dashboard_board_overview_stats( object $db, string $board_meetings_table, string $board_actions_table, string $board_decisions_table, string $board_committees_table, string $now_mysql ): array {
        return metis_portal_dashboard_remember(
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
}

if ( ! function_exists( 'metis_portal_dashboard_finance_settlement_stats' ) ) {
    function metis_portal_dashboard_finance_settlement_stats( object $db, string $deposits_table, string $month_start, ?string $recons_table = null ): array {
        return metis_portal_dashboard_remember(
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
}

if ( ! function_exists( 'metis_portal_dashboard_watch_stats' ) ) {
    function metis_portal_dashboard_watch_stats( object $db, array $tables, string $now_mysql, string $next_week_mysql ): array {
        return metis_portal_dashboard_remember(
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
}

if ( ! function_exists( 'metis_portal_group_needs_attention' ) ) {
    function metis_portal_group_needs_attention( array $needs_attention ): array {
        $groups = [
            'critical' => [],
            'soon' => [],
            'info' => [],
        ];

        foreach ( $needs_attention as $item ) {
            $severity = (string) ( $item['severity'] ?? 'info' );
            if ( ! isset( $groups[ $severity ] ) ) {
                $severity = 'info';
            }
            $groups[ $severity ][] = $item;
        }

        return $groups;
    }
}

if ( ! function_exists( 'metis_portal_normalize_focus_metrics' ) ) {
    function metis_portal_normalize_focus_metrics( array $metrics ): array {
        $normalized = array_slice( $metrics, 0, 3 );
        while ( count( $normalized ) < 3 ) {
            $normalized[] = [
                'label' => '—',
                'value' => '—',
                'note'  => '',
            ];
        }

        return $normalized;
    }
}

if ( ! function_exists( 'metis_portal_build_dashboard_view_model' ) ) {
    function metis_portal_build_dashboard_view_model( array $args ): array {
        $can_access_module = $args['can_access_module'];
        $format_money = $args['format_money'];

        $needs_attention = [];
        if ( $can_access_module( 'board' ) && (int) ( $args['open_board_actions'] ?? 0 ) > 0 ) {
            $needs_attention[] = [
                'title' => 'Board action items are pending',
                'detail' => metis_number_format( (int) $args['open_board_actions'] ) . ' open action items need follow-up.',
                'url' => metis_portal_url( 'board', 'dashboard' ),
                'cta' => 'Review actions',
                'severity' => 'critical',
            ];
        }
        if ( $can_access_module( 'board' ) && (int) ( $args['upcoming_meetings'] ?? 0 ) > 0 ) {
            $needs_attention[] = [
                'title' => 'Board meetings coming up this week',
                'detail' => metis_number_format( (int) $args['upcoming_meetings'] ) . ' meetings are in the next 7 days.',
                'url' => metis_portal_url( 'board', 'dashboard' ),
                'cta' => 'Open calendar',
                'severity' => 'soon',
            ];
        }
        if ( $can_access_module( 'donations' ) && (int) ( $args['open_deposit_count'] ?? 0 ) > 0 ) {
            $needs_attention[] = [
                'title' => 'Deposit batching is waiting',
                'detail' => metis_number_format( (int) $args['open_deposit_count'] ) . ' gifts are still unbatched (' . $format_money( (float) ( $args['open_deposit_total'] ?? 0 ) ) . ').',
                'url' => metis_portal_url( 'donations', 'deposits' ),
                'cta' => 'Open deposits',
                'severity' => 'critical',
            ];
        }
        if ( $can_access_module( 'people' ) && (int) ( $args['pending_requests'] ?? 0 ) > 0 ) {
            $needs_attention[] = [
                'title' => 'Access requests are pending',
                'detail' => metis_number_format( (int) $args['pending_requests'] ) . ' people are waiting on access approval.',
                'url' => metis_portal_url( 'people', 'access_requests' ),
                'cta' => 'Review requests',
                'severity' => 'soon',
            ];
        }
        if ( $can_access_module( 'newsletter' ) && (int) ( $args['queued_emails'] ?? 0 ) > 0 ) {
            $needs_attention[] = [
                'title' => 'Newsletter send queue is not empty',
                'detail' => metis_number_format( (int) $args['queued_emails'] ) . ' messages are queued for delivery.',
                'url' => metis_portal_url( 'newsletter', 'campaigns' ),
                'cta' => 'Open campaigns',
                'severity' => 'info',
            ];
        }
        if ( $can_access_module( 'grandys_stash' ) && (int) ( $args['stash_attention'] ?? 0 ) > 0 ) {
            $needs_attention[] = [
                'title' => 'Grandy\'s Stash needs processing',
                'detail' => metis_number_format( (int) $args['stash_attention'] ) . ' tickets are waiting for action or inventory.',
                'url' => metis_portal_url( 'grandys_stash', 'dashboard' ),
                'cta' => 'Open inbox',
                'severity' => 'critical',
            ];
        }
        if ( $can_access_module( 'calendar' ) && empty( $args['calendar_ready'] ) ) {
            $needs_attention[] = [
                'title' => 'Calendar workspace is not configured',
                'detail' => 'Calendar setup still needs to be completed.',
                'url' => metis_portal_url( 'settings', 'calendar' ),
                'cta' => 'Configure calendar',
                'severity' => 'info',
            ];
        }
        if ( $can_access_module( 'drive' ) && empty( $args['drive_home_ready'] ) ) {
            $needs_attention[] = [
                'title' => 'Drive user home is not enabled',
                'detail' => 'People folders cannot be auto-managed until a home drive is configured.',
                'url' => metis_portal_url( 'settings', 'drive' ),
                'cta' => 'Configure drive',
                'severity' => 'info',
            ];
        }
        if ( $can_access_module( 'settings' ) && (int) ( $args['failed_jobs_count'] ?? 0 ) > 0 ) {
            $needs_attention[] = [
                'title' => 'Background jobs are failing',
                'detail' => metis_number_format( (int) $args['failed_jobs_count'] ) . ' failed jobs are in the queue.',
                'url' => metis_portal_url( 'settings', 'scheduler' ),
                'cta' => 'Review jobs',
                'severity' => 'critical',
            ];
        }

        $today_priority = [];
        if ( $can_access_module( 'board' ) ) {
            $today_priority[] = [
                'title' => 'Overdue',
                'count' => (int) ( $args['board_action_counts']['overdue'] ?? 0 ),
                'filter' => 'overdue',
                'note' => 'Past due board actions',
            ];
            $today_priority[] = [
                'title' => 'Due Today',
                'count' => (int) ( $args['board_action_counts']['today'] ?? 0 ),
                'filter' => 'today',
                'note' => 'Actions due today',
            ];
            $today_priority[] = [
                'title' => 'Blocked',
                'count' => (int) ( $args['board_action_counts']['blocked'] ?? 0 ),
                'filter' => 'blocked',
                'note' => 'Blocked or on hold',
            ];
        }

        $today_stats = [
            [
                'label' => 'Needs Attention',
                'value' => metis_number_format( count( $needs_attention ) ),
                'note'  => 'Role-scoped critical items',
            ],
            [
                'label' => 'Open Board Actions',
                'value' => metis_number_format( (int) ( $args['open_board_actions'] ?? 0 ) ),
                'note'  => 'Outstanding governance tasks',
            ],
            [
                'label' => 'Upcoming Meetings (7d)',
                'value' => metis_number_format( (int) ( $args['upcoming_meetings'] ?? 0 ) ),
                'note'  => 'Scheduled or draft meetings',
            ],
            [
                'label' => 'Queued Jobs',
                'value' => metis_number_format( (int) ( $args['pending_jobs_count'] ?? 0 ) ),
                'note'  => 'Background queue waiting',
            ],
        ];

        $focus_updated_label = metis_current_datetime()->format( 'M j, g:i a' );
        $focus_cards_by_key = [];
        if ( $can_access_module( 'board' ) ) {
            $focus_cards_by_key['board'] = [
                'title' => 'Board',
                'desc'  => 'Meetings, decisions, attendance, and open actions.',
                'url'   => metis_portal_url( 'board', 'dashboard' ),
                'metrics' => metis_portal_normalize_focus_metrics( (array) ( $args['board_metrics'] ?? [] ) ),
                'updated' => $focus_updated_label,
            ];
        }
        if ( $can_access_module( 'people' ) ) {
            $focus_cards_by_key['people'] = [
                'title' => 'People',
                'desc'  => 'Profiles, access approvals, and workspace links.',
                'url'   => metis_portal_url( 'people', 'dashboard' ),
                'metrics' => metis_portal_normalize_focus_metrics( (array) ( $args['people_metrics'] ?? [] ) ),
                'updated' => $focus_updated_label,
            ];
        }
        if ( $can_access_module( 'grandys_stash' ) ) {
            $focus_cards_by_key['grandys_stash'] = [
                'title' => 'Grandy\'s Stash',
                'desc'  => 'Request queue, waitlist pressure, and fulfillment.',
                'url'   => metis_portal_url( 'grandys_stash', 'dashboard' ),
                'metrics' => metis_portal_normalize_focus_metrics( (array) ( $args['grandys_metrics'] ?? [] ) ),
                'updated' => $focus_updated_label,
            ];
        }
        if ( $can_access_module( 'donations' ) || $can_access_module( 'finance' ) ) {
            $focus_cards_by_key['finance'] = [
                'title' => 'Finance',
                'desc'  => 'Revenue, deposits, and reconciliation status.',
                'url'   => $can_access_module( 'donations' ) ? metis_portal_url( 'donations', 'dashboard' ) : metis_portal_url( 'finance', 'finance' ),
                'metrics' => metis_portal_normalize_focus_metrics( (array) ( $args['finance_metrics'] ?? [] ) ),
                'updated' => $focus_updated_label,
            ];
        }
        if ( $can_access_module( 'newsletter' ) ) {
            $focus_cards_by_key['communications'] = [
                'title' => 'Communications',
                'desc'  => 'Newsletter queue and campaign activity.',
                'url'   => metis_portal_url( 'newsletter', 'dashboard' ),
                'metrics' => metis_portal_normalize_focus_metrics( (array) ( $args['newsletter_metrics'] ?? [] ) ),
                'updated' => $focus_updated_label,
            ];
        }

        $role_priority = [];
        if ( $can_access_module( 'board' ) ) {
            $role_priority = [ 'board', 'people', 'grandys_stash', 'finance', 'communications' ];
        } elseif ( $can_access_module( 'donations' ) || $can_access_module( 'finance' ) ) {
            $role_priority = [ 'finance', 'people', 'communications', 'board', 'grandys_stash' ];
        } elseif ( $can_access_module( 'newsletter' ) ) {
            $role_priority = [ 'communications', 'people', 'board', 'finance', 'grandys_stash' ];
        } elseif ( $can_access_module( 'people' ) ) {
            $role_priority = [ 'people', 'board', 'finance', 'communications', 'grandys_stash' ];
        }

        $focus_cards = [];
        foreach ( $role_priority as $key ) {
            if ( isset( $focus_cards_by_key[ $key ] ) ) {
                $focus_cards[] = $focus_cards_by_key[ $key ];
                unset( $focus_cards_by_key[ $key ] );
            }
        }
        foreach ( $focus_cards_by_key as $card ) {
            $focus_cards[] = $card;
        }

        $system_watch = [
            [
                'label' => 'Pending background jobs',
                'value' => metis_number_format( (int) ( $args['pending_jobs_count'] ?? 0 ) ),
                'state' => ( (int) ( $args['pending_jobs_count'] ?? 0 ) ) > 0 ? 'warn' : 'ok',
            ],
            [
                'label' => 'Failed background jobs',
                'value' => metis_number_format( (int) ( $args['failed_jobs_count'] ?? 0 ) ),
                'state' => ( (int) ( $args['failed_jobs_count'] ?? 0 ) ) > 0 ? 'alert' : 'ok',
            ],
            [
                'label' => 'Calendar workspace',
                'value' => ! empty( $args['calendar_ready'] ) ? 'Ready' : 'Needs setup',
                'state' => ! empty( $args['calendar_ready'] ) ? 'ok' : 'warn',
            ],
            [
                'label' => 'Drive user home',
                'value' => ! empty( $args['drive_home_ready'] ) ? 'Ready' : 'Needs setup',
                'state' => ! empty( $args['drive_home_ready'] ) ? 'ok' : 'warn',
            ],
        ];

        return [
            'needs_attention' => $needs_attention,
            'needs_attention_groups' => metis_portal_group_needs_attention( $needs_attention ),
            'today_priority' => $today_priority,
            'today_stats' => $today_stats,
            'focus_cards' => $focus_cards,
            'system_watch' => $system_watch,
        ];
    }
}

if ( ! function_exists( 'metis_portal_dashboard_board_action_counts' ) ) {
    function metis_portal_dashboard_board_action_counts( object $db, string $board_actions_table, int $current_person_id, string $today_date, string $due7_date ): array {
        if ( $current_person_id < 1 ) {
            return [
                'mine' => 0,
                'overdue' => 0,
                'due7' => 0,
                'done' => 0,
                'today' => 0,
                'blocked' => 0,
            ];
        }

        $counts = (array) ( $db->fetchOne(
            "SELECT
                SUM(CASE WHEN a.status NOT IN ('done','completed','closed') THEN 1 ELSE 0 END) AS mine,
                SUM(CASE WHEN a.status NOT IN ('done','completed','closed') AND a.due_date IS NOT NULL AND a.due_date < %s THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN a.status NOT IN ('done','completed','closed') AND a.due_date IS NOT NULL AND a.due_date >= %s AND a.due_date <= %s THEN 1 ELSE 0 END) AS due7,
                SUM(CASE WHEN a.status IN ('done','completed','closed') THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN a.status NOT IN ('done','completed','closed') AND a.due_date = %s THEN 1 ELSE 0 END) AS today,
                SUM(CASE WHEN LOWER(COALESCE(a.status, '')) IN ('blocked','on_hold','stalled') THEN 1 ELSE 0 END) AS blocked
             FROM {$board_actions_table} a
             WHERE a.owner_person_id = %d",
            [ $today_date, $today_date, $due7_date, $today_date, $current_person_id ]
        ) ?? [] );

        return [
            'mine' => (int) ( $counts['mine'] ?? 0 ),
            'overdue' => (int) ( $counts['overdue'] ?? 0 ),
            'due7' => (int) ( $counts['due7'] ?? 0 ),
            'done' => (int) ( $counts['done'] ?? 0 ),
            'today' => (int) ( $counts['today'] ?? 0 ),
            'blocked' => (int) ( $counts['blocked'] ?? 0 ),
        ];
    }
}
