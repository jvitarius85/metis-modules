<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

if ( ! function_exists( 'metis_portal_dashboard_table_exists' ) ) {
    function metis_portal_dashboard_table_exists( object $db, string $table ): bool {
        return \Metis\Modules\Portal\PortalDashboardService::tableExists( $db, $table );
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
        \Metis\Modules\Portal\PortalDashboardService::forgetAll();
    }
}

if ( ! function_exists( 'metis_portal_dashboard_donation_stats' ) ) {
    function metis_portal_dashboard_donation_stats( object $db, string $transactions_table, string $campaigns_table, string $last_30_start, string $month_start, string $year_start, bool $has_campaigns_table ): array {
        return \Metis\Modules\Portal\PortalDashboardService::donationStats(
            $db,
            $transactions_table,
            $campaigns_table,
            $last_30_start,
            $month_start,
            $year_start,
            $has_campaigns_table
        );
    }
}

if ( ! function_exists( 'metis_portal_dashboard_contacts_stats' ) ) {
    function metis_portal_dashboard_contacts_stats( object $db, string $contacts_table ): array {
        return \Metis\Modules\Portal\PortalDashboardService::contactsStats( $db, $contacts_table );
    }
}

if ( ! function_exists( 'metis_portal_dashboard_newsletter_stats' ) ) {
    function metis_portal_dashboard_newsletter_stats( object $db, string $newsletter_lists_table, string $newsletter_subs_table, string $newsletter_campaigns_table, string $newsletter_messages_table, int $newsletter_sent_30d = 0 ): array {
        return \Metis\Modules\Portal\PortalDashboardService::newsletterStats(
            $db,
            $newsletter_lists_table,
            $newsletter_subs_table,
            $newsletter_campaigns_table,
            $newsletter_messages_table,
            $newsletter_sent_30d
        );
    }
}

if ( ! function_exists( 'metis_portal_dashboard_people_stats' ) ) {
    function metis_portal_dashboard_people_stats( object $db, string $people_table, ?string $requests_table = null ): array {
        return \Metis\Modules\Portal\PortalDashboardService::peopleStats( $db, $people_table, $requests_table );
    }
}

if ( ! function_exists( 'metis_portal_dashboard_job_stats' ) ) {
    function metis_portal_dashboard_job_stats( object $db, string $job_queue_table ): array {
        return \Metis\Modules\Portal\PortalDashboardService::jobStats( $db, $job_queue_table );
    }
}

if ( ! function_exists( 'metis_portal_dashboard_board_overview_stats' ) ) {
    function metis_portal_dashboard_board_overview_stats( object $db, string $board_meetings_table, string $board_actions_table, string $board_decisions_table, string $board_committees_table, string $now_mysql ): array {
        return \Metis\Modules\Portal\PortalDashboardService::boardOverviewStats(
            $db,
            $board_meetings_table,
            $board_actions_table,
            $board_decisions_table,
            $board_committees_table,
            $now_mysql
        );
    }
}

if ( ! function_exists( 'metis_portal_dashboard_finance_settlement_stats' ) ) {
    function metis_portal_dashboard_finance_settlement_stats( object $db, string $deposits_table, string $month_start, ?string $recons_table = null ): array {
        return \Metis\Modules\Portal\PortalDashboardService::financeSettlementStats( $db, $deposits_table, $month_start, $recons_table );
    }
}

if ( ! function_exists( 'metis_portal_dashboard_watch_stats' ) ) {
    function metis_portal_dashboard_watch_stats( object $db, array $tables, string $now_mysql, string $next_week_mysql ): array {
        return \Metis\Modules\Portal\PortalDashboardService::watchStats( $db, $tables, $now_mysql, $next_week_mysql );
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
        return \Metis\Modules\Portal\BoardActionService::dashboardCounts( $current_person_id );
    }
}
