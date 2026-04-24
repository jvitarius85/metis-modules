<?php if (!defined('METIS_ROOT')) exit; ?>

<?php
$db = metis_db();

$format_money = static function ( float $amount ): string {
    return '$' . metis_number_format( $amount, 2 );
};
$table_exists = static function ( string $table ) use ( $db ): bool {
    $exists = $db->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
    return $exists === $table;
};

$now_dt        = metis_current_datetime();
$last_30_start = ( clone $now_dt )->modify( '-29 days' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
$month_start   = ( clone $now_dt )->modify( 'first day of this month' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
$year_start    = ( clone $now_dt )->setDate( (int) $now_dt->format( 'Y' ), 1, 1 )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );

$donations_metrics = [];
$finance_metrics   = [];
$board_metrics     = [];
$contacts_metrics  = [];
$newsletter_metrics = [];
$calendar_metrics  = [];
$drive_metrics     = [];
$people_metrics    = [];
$workspace         = [ 'ok' => false, 'calendars' => [] ];
$users_home        = [ 'drive_id' => '' ];

$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );
$deposits_table     = Metis_Tables::get( 'deposits' );
$recons_table       = Metis_Tables::get( 'finance_v2_recon_months' );
$contacts_table     = Metis_Tables::get( 'contacts' );
$details_table      = Metis_Tables::get( 'contact_details' );
$board_meetings_table = Metis_Tables::get( 'board_meetings' );
$board_committees_table = Metis_Tables::get( 'board_committees' );
$board_actions_table = Metis_Tables::get( 'board_action_items' );
$board_decisions_table = Metis_Tables::get( 'board_decisions' );
$people_table       = Metis_Tables::get( 'people' );
$requests_table     = Metis_Tables::get( 'people_access_requests' );
$newsletter_lists_table = Metis_Tables::get( 'newsletter_lists' );
$newsletter_subs_table = Metis_Tables::get( 'newsletter_subs' );
$newsletter_campaigns_table = Metis_Tables::get( 'newsletter_campaigns' );
$newsletter_messages_table = Metis_Tables::get( 'newsletter_messages' );

if ( $table_exists( $transactions_table ) ) {
    $donation_summary = (object) ( $db->fetchOne(
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

    $active_campaigns = 0;
    $total_campaigns  = 0;

    if ( $table_exists( $campaigns_table ) ) {
        $campaign_counts = (object) ( $db->fetchOne(
            "
            SELECT
                COUNT(*) AS total_campaigns,
                SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_campaigns
            FROM {$campaigns_table}
            "
        ) ?? [] );

        $active_campaigns = (int) ( $campaign_counts->active_campaigns ?? 0 );
        $total_campaigns  = (int) ( $campaign_counts->total_campaigns ?? 0 );
    }

    $donations_metrics = [
        [
            'label' => 'Last 30 Days',
            'value' => $format_money( (float) ( $donation_summary->raised_30d ?? 0 ) ),
            'note'  => 'Recent giving',
        ],
        [
            'label' => 'This Month',
            'value' => $format_money( (float) ( $donation_summary->raised_month ?? 0 ) ),
            'note'  => $now_dt->format( 'F Y' ),
        ],
        [
            'label' => 'Open Queue',
            'value' => $format_money( (float) ( $donation_summary->open_deposit_total ?? 0 ) ),
            'note'  => metis_number_format( (int) ( $donation_summary->open_deposit_count ?? 0 ) ) . ' gifts to batch',
        ],
        [
            'label' => 'Campaigns',
            'value' => metis_number_format( $active_campaigns ),
            'note'  => metis_number_format( $total_campaigns ) . ' total configured',
        ],
    ];

    $finance_metrics[] = [
        'label' => 'Month to Date',
        'value' => $format_money( (float) ( $donation_summary->raised_month ?? 0 ) ),
        'note'  => 'From transactions',
    ];
    $finance_metrics[] = [
        'label' => 'Year to Date',
        'value' => $format_money( (float) ( $donation_summary->raised_ytd ?? 0 ) ),
        'note'  => 'Gross revenue this year',
    ];
    $finance_metrics[] = [
        'label' => 'Awaiting Deposit',
        'value' => $format_money( (float) ( $donation_summary->open_deposit_total ?? 0 ) ),
        'note'  => metis_number_format( (int) ( $donation_summary->open_deposit_count ?? 0 ) ) . ' unsettled transactions',
    ];
}

if ( $table_exists( $contacts_table ) ) {
    $total_contacts = (int) $db->scalar( "SELECT COUNT(*) FROM {$contacts_table}" );
    $with_did       = (int) $db->scalar( "SELECT COUNT(*) FROM {$contacts_table} WHERE did IS NOT NULL AND did <> ''" );
    $without_did    = max( 0, $total_contacts - $with_did );
    $duplicate_count = 0;

    $duplicate_rows = $db->fetchAll(
        "SELECT LOWER(TRIM(email)) AS email_key, COUNT(*) AS matches
         FROM {$contacts_table}
         WHERE email IS NOT NULL AND TRIM(email) <> ''
         GROUP BY LOWER(TRIM(email))
         HAVING COUNT(*) > 1"
    );

    foreach ( $duplicate_rows as $row ) {
        $duplicate_count += 1;
    }

    $contacts_metrics = [
        [
            'label' => 'Contacts',
            'value' => metis_number_format( $total_contacts ),
            'note'  => 'Total records',
        ],
        [
            'label' => 'Donor IDs',
            'value' => metis_number_format( $with_did ),
            'note'  => metis_number_format( $without_did ) . ' without donor IDs',
        ],
        [
            'label' => 'Duplicates',
            'value' => metis_number_format( $duplicate_count ),
            'note'  => 'Potential duplicate groups',
        ],
    ];
}

if ( $table_exists( $newsletter_lists_table ) && $table_exists( $newsletter_subs_table ) && $table_exists( $newsletter_campaigns_table ) && $table_exists( $newsletter_messages_table ) ) {
    $newsletter_sent_30d = 0;
    if ( function_exists( 'metis_newsletter_resolved_timezone' ) ) {
        $newsletter_sent_30d = (int) $db->scalar(
            "SELECT COUNT(*) FROM {$newsletter_messages_table} WHERE status='sent' AND sent_at >= %s",
            [ ( new DateTimeImmutable( 'now', metis_newsletter_resolved_timezone() ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' ) ]
        );
    }

    $newsletter_metrics = [
        [
            'label' => 'Subscribers',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$newsletter_subs_table} WHERE status = 'subscribed'" ) ),
            'note'  => 'Confirmed subscribers',
        ],
        [
            'label' => 'Queued',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$newsletter_messages_table} WHERE status = 'queued'" ) ),
            'note'  => 'Waiting to send',
        ],
        [
            'label' => 'Sent 30d',
            'value' => metis_number_format( $newsletter_sent_30d ),
            'note'  => 'Delivered in 30 days',
        ],
        [
            'label' => 'Lists',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$newsletter_lists_table} WHERE is_active = 1" ) ),
            'note'  => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$newsletter_campaigns_table}" ) ) . ' campaigns total',
        ],
    ];
}

if ( $table_exists( $board_meetings_table ) && $table_exists( $board_actions_table ) && $table_exists( $board_decisions_table ) && $table_exists( $board_committees_table ) ) {
    $now_mysql = metis_current_time( 'mysql' );
    $board_metrics = [
        [
            'label' => 'Meetings',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$board_meetings_table}" ) ),
            'note'  => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$board_meetings_table} WHERE meeting_date >= %s AND status IN ('scheduled','draft')", [ $now_mysql ] ) ) . ' upcoming',
        ],
        [
            'label' => 'Open Actions',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$board_actions_table} WHERE status <> 'done'" ) ),
            'note'  => 'Outstanding governance tasks',
        ],
        [
            'label' => 'Decisions',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$board_decisions_table}" ) ),
            'note'  => 'Recorded decisions',
        ],
        [
            'label' => 'Committees',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$board_committees_table} WHERE is_active = 1" ) ),
            'note'  => 'Active committees',
        ],
    ];
}

if ( $table_exists( $people_table ) ) {
    $people_metrics = [
        [
            'label' => 'People',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$people_table}" ) ),
            'note'  => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$people_table} WHERE status = 'active'" ) ) . ' active',
        ],
        [
            'label' => 'Staff',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$people_table} WHERE is_staff = 1" ) ),
            'note'  => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$people_table} WHERE is_board = 1" ) ) . ' board members',
        ],
        [
            'label' => 'Workspace',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$people_table} WHERE is_workspace_user = 1" ) ),
            'note'  => 'Linked Workspace users',
        ],
    ];

    if ( $table_exists( $requests_table ) ) {
        $people_metrics[] = [
            'label' => 'Requests',
            'value' => metis_number_format( (int) $db->scalar( "SELECT COUNT(*) FROM {$requests_table} WHERE status = 'pending'" ) ),
            'note'  => 'Pending access requests',
        ];
    }
}

if ( function_exists( 'metis_calendar_workspace_settings_all' ) ) {
    $workspace = metis_calendar_workspace_settings_all();
    $calendar_count = count( (array) ( $workspace['calendars'] ?? [] ) );
    $calendar_metrics = [
        [
            'label' => 'Status',
            'value' => ! empty( $workspace['ok'] ) ? 'Ready' : 'Setup',
            'note'  => ! empty( $workspace['ok'] ) ? 'Calendar connected' : 'Needs configuration',
        ],
        [
            'label' => 'Calendars',
            'value' => metis_number_format( $calendar_count ),
            'note'  => $calendar_count === 1 ? 'Connected calendar' : 'Connected calendars',
        ],
    ];
}

if ( function_exists( 'metis_drive_configured_drives' ) && function_exists( 'metis_drive_users_home_setting' ) ) {
    $drive_configs = metis_drive_configured_drives();
    $users_home    = metis_drive_users_home_setting();
    $drive_metrics = [
        [
            'label' => 'Drives',
            'value' => metis_number_format( count( (array) $drive_configs ) ),
            'note'  => 'Configured drive sources',
        ],
        [
            'label' => 'User Home',
            'value' => ! empty( $users_home['drive_id'] ) ? 'On' : 'Off',
            'note'  => ! empty( $users_home['drive_id'] ) ? 'Personal folder enabled' : 'No user home drive',
        ],
    ];
}

if ( $table_exists( $deposits_table ) ) {
    $deposit_summary = (object) ( $db->fetchOne(
        "
        SELECT
            COALESCE(SUM(CASE WHEN deposit_date >= %s THEN COALESCE(net_total, total_amount, 0) ELSE 0 END), 0) AS month_net,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'in_transit') THEN COALESCE(net_total, total_amount, 0) ELSE 0 END), 0) AS pending_total,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'in_transit') THEN 1 ELSE 0 END), 0) AS pending_count
        FROM {$deposits_table}
        ",
        [ $month_start ]
    ) ?? [] );

    $finance_metrics[1] = [
        'label' => 'Settled This Month',
        'value' => $format_money( (float) ( $deposit_summary->month_net ?? 0 ) ),
        'note'  => 'Deposits cleared to bank',
    ];

    $finance_metrics[3] = [
        'label' => 'Pending Payouts',
        'value' => $format_money( (float) ( $deposit_summary->pending_total ?? 0 ) ),
        'note'  => metis_number_format( (int) ( $deposit_summary->pending_count ?? 0 ) ) . ' deposits pending',
    ];
}

if ( $table_exists( $recons_table ) ) {
    $open_recons = (int) $db->scalar( "SELECT COUNT(*) FROM {$recons_table} WHERE status <> 'finalized'" );
    $finance_metrics[] = [
        'label' => 'Open Recons',
        'value' => metis_number_format( $open_recons ),
        'note'  => 'Periods still to match',
    ];
}

$grandys_metrics = [];
$stash_stats     = [];
if ( class_exists( '\Metis\Modules\GrandyStash\GrandyStashRepository' ) && function_exists( 'metis_grandys_stash_can_view' ) && metis_grandys_stash_can_view() ) {
    $stash_dashboard = \Metis\Modules\GrandyStash\GrandyStashRepository::dashboardData();
    $stash_stats     = is_array( $stash_dashboard['stats'] ?? null ) ? $stash_dashboard['stats'] : [];
    $grandys_metrics = [
        [
            'label' => 'Needs Action',
            'value' => metis_number_format( (int) ( $stash_stats['new_tickets'] ?? 0 ) ),
            'note'  => 'New requests and donations',
        ],
        [
            'label' => 'Waitlist',
            'value' => metis_number_format( (int) ( $stash_stats['waitlist'] ?? 0 ) ),
            'note'  => 'Items waiting for stock',
        ],
        [
            'label' => 'Ready',
            'value' => metis_number_format( (int) ( $stash_stats['ready'] ?? 0 ) ),
            'note'  => 'Tickets ready to fulfill',
        ],
        [
            'label' => 'Completed',
            'value' => metis_number_format( (int) ( $stash_stats['completed'] ?? 0 ) ),
            'note'  => 'Fulfilled requests',
        ],
    ];
}

$job_queue_table = Metis_Tables::get( 'job_queue' );
$pending_jobs_count = 0;
$failed_jobs_count  = 0;
if ( $table_exists( $job_queue_table ) ) {
    $pending_jobs_count = (int) $db->scalar( "SELECT COUNT(*) FROM {$job_queue_table} WHERE status = 'queued'" );
    $failed_jobs_count  = (int) $db->scalar( "SELECT COUNT(*) FROM {$job_queue_table} WHERE status = 'failed'" );
}

$can_access_module = static function ( string $module_slug ): bool {
    if ( function_exists( 'metis_current_user_can' ) && metis_current_user_can( 'manage_options' ) ) {
        return true;
    }
    if ( function_exists( 'metis_people_can' ) ) {
        return (bool) metis_people_can( $module_slug, 'view' );
    }
    return true;
};

$now_mysql          = metis_current_time( 'mysql' );
$next_week_mysql    = ( clone $now_dt )->modify( '+7 days' )->format( 'Y-m-d H:i:s' );
$upcoming_meetings  = 0;
$open_board_actions = 0;
$pending_requests   = 0;
$queued_emails      = 0;
$open_deposit_count = 0;
$open_deposit_total = 0.0;
$stash_attention    = 0;
$calendar_ready     = empty( $workspace['ok'] ) ? false : true;
$drive_home_ready   = empty( $users_home['drive_id'] ) ? false : true;
$current_person_id  = function_exists( 'metis_auth_current_person_id' ) ? (int) metis_auth_current_person_id() : 0;
$my_board_actions   = [];

if ( $table_exists( $board_meetings_table ) ) {
    $upcoming_meetings = (int) $db->scalar(
        "SELECT COUNT(*) FROM {$board_meetings_table} WHERE meeting_date >= %s AND meeting_date <= %s AND status IN ('scheduled','draft')",
        [ $now_mysql, $next_week_mysql ]
    );
}
if ( $table_exists( $board_actions_table ) ) {
    $open_board_actions = (int) $db->scalar( "SELECT COUNT(*) FROM {$board_actions_table} WHERE status <> 'done'" );
}
if ( $table_exists( $requests_table ) ) {
    $pending_requests = (int) $db->scalar( "SELECT COUNT(*) FROM {$requests_table} WHERE status = 'pending'" );
}
if ( $table_exists( $newsletter_messages_table ) ) {
    $queued_emails = (int) $db->scalar( "SELECT COUNT(*) FROM {$newsletter_messages_table} WHERE status = 'queued'" );
}
if ( $table_exists( $transactions_table ) ) {
    $open_queue_row = (array) ( $db->fetchOne(
        "SELECT
            COUNT(CASE WHEN (deposit_batch_id IS NULL OR deposit_batch_id = '') AND LOWER(COALESCE(status, '')) = 'completed' THEN 1 ELSE NULL END) AS open_count,
            COALESCE(SUM(CASE WHEN (deposit_batch_id IS NULL OR deposit_batch_id = '') AND LOWER(COALESCE(status, '')) = 'completed' THEN amount ELSE 0 END), 0) AS open_total
         FROM {$transactions_table}"
    ) ?? [] );
    $open_deposit_count = (int) ( $open_queue_row['open_count'] ?? 0 );
    $open_deposit_total = (float) ( $open_queue_row['open_total'] ?? 0 );
}

$stash_attention = (int) ( $stash_stats['new_tickets'] ?? 0 ) + (int) ( $stash_stats['waitlist'] ?? 0 );

$board_action_filters = [
    'mine' => 'Mine',
    'overdue' => 'Overdue',
    'due7' => 'Due 7d',
    'done' => 'Done',
];
$board_action_filter = 'mine';
$today_date = date( 'Y-m-d' );
$due7_date = ( new DateTimeImmutable( 'today' ) )->modify( '+7 days' )->format( 'Y-m-d' );

$build_board_action_where = static function ( string $filter ) use ( $current_person_id, $today_date, $due7_date ): array {
    $where = [
        'a.owner_person_id = %d',
    ];
    $params = [ $current_person_id ];

    switch ( $filter ) {
        case 'overdue':
            $where[] = "a.status NOT IN ('done','completed','closed')";
            $where[] = 'a.due_date IS NOT NULL';
            $where[] = 'a.due_date < %s';
            $params[] = $today_date;
            break;
        case 'today':
            $where[] = "a.status NOT IN ('done','completed','closed')";
            $where[] = 'a.due_date = %s';
            $params[] = $today_date;
            break;
        case 'blocked':
            $where[] = "LOWER(COALESCE(a.status, '')) IN ('blocked','on_hold','stalled')";
            break;
        case 'due7':
            $where[] = "a.status NOT IN ('done','completed','closed')";
            $where[] = 'a.due_date IS NOT NULL';
            $where[] = 'a.due_date >= %s';
            $where[] = 'a.due_date <= %s';
            $params[] = $today_date;
            $params[] = $due7_date;
            break;
        case 'done':
            $where[] = "a.status IN ('done','completed','closed')";
            break;
        case 'mine':
        default:
            $where[] = "a.status NOT IN ('done','completed','closed')";
            break;
    }

    return [ implode( ' AND ', $where ), $params ];
};

$load_board_actions = static function ( string $filter, int $limit = 8 ) use ( $db, $board_actions_table, $board_meetings_table, $build_board_action_where ): array {
    [ $where_sql, $params ] = $build_board_action_where( $filter );
    $params[] = $limit;

    return $db->fetchAll(
        "SELECT a.id, a.title, a.status, a.priority, a.due_date, a.meeting_id,
                m.title AS meeting_title, m.meeting_date
         FROM {$board_actions_table} a
         LEFT JOIN {$board_meetings_table} m ON m.id = a.meeting_id
         WHERE {$where_sql}
         ORDER BY
           CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END ASC,
           a.due_date ASC,
           a.id DESC
         LIMIT %d",
        $params
    ) ?: [];
};

$board_action_counts = [
    'mine' => 0,
    'overdue' => 0,
    'due7' => 0,
    'done' => 0,
    'today' => 0,
    'blocked' => 0,
];

if ( $can_access_module( 'board' ) && $current_person_id > 0 && $table_exists( $board_actions_table ) && $table_exists( $board_meetings_table ) ) {
    $my_board_actions = $load_board_actions( $board_action_filter );

    $board_action_counts['mine'] = (int) $db->scalar(
        "SELECT COUNT(*) FROM {$board_actions_table} a
         WHERE a.owner_person_id = %d
           AND a.status NOT IN ('done','completed','closed')",
        [ $current_person_id ]
    );
    $board_action_counts['overdue'] = (int) $db->scalar(
        "SELECT COUNT(*) FROM {$board_actions_table} a
         WHERE a.owner_person_id = %d
           AND a.status NOT IN ('done','completed','closed')
           AND a.due_date IS NOT NULL
           AND a.due_date < %s",
        [ $current_person_id, $today_date ]
    );
    $board_action_counts['due7'] = (int) $db->scalar(
        "SELECT COUNT(*) FROM {$board_actions_table} a
         WHERE a.owner_person_id = %d
           AND a.status NOT IN ('done','completed','closed')
           AND a.due_date IS NOT NULL
           AND a.due_date >= %s
           AND a.due_date <= %s",
        [ $current_person_id, $today_date, $due7_date ]
    );
    $board_action_counts['done'] = (int) $db->scalar(
        "SELECT COUNT(*) FROM {$board_actions_table} a
         WHERE a.owner_person_id = %d
           AND a.status IN ('done','completed','closed')",
        [ $current_person_id ]
    );
    $board_action_counts['today'] = (int) $db->scalar(
        "SELECT COUNT(*) FROM {$board_actions_table} a
         WHERE a.owner_person_id = %d
           AND a.status NOT IN ('done','completed','closed')
           AND a.due_date = %s",
        [ $current_person_id, $today_date ]
    );
    $board_action_counts['blocked'] = (int) $db->scalar(
        "SELECT COUNT(*) FROM {$board_actions_table} a
         WHERE a.owner_person_id = %d
           AND LOWER(COALESCE(a.status, '')) IN ('blocked','on_hold','stalled')",
        [ $current_person_id ]
    );
}

$needs_attention = [];
if ( $can_access_module( 'board' ) && $open_board_actions > 0 ) {
    $needs_attention[] = [
        'title' => 'Board action items are pending',
        'detail' => metis_number_format( $open_board_actions ) . ' open action items need follow-up.',
        'url' => metis_portal_url( 'board', 'dashboard' ),
        'cta' => 'Review actions',
        'severity' => 'critical',
    ];
}
if ( $can_access_module( 'board' ) && $upcoming_meetings > 0 ) {
    $needs_attention[] = [
        'title' => 'Board meetings coming up this week',
        'detail' => metis_number_format( $upcoming_meetings ) . ' meetings are in the next 7 days.',
        'url' => metis_portal_url( 'board', 'dashboard' ),
        'cta' => 'Open calendar',
        'severity' => 'soon',
    ];
}
if ( $can_access_module( 'donations' ) && $open_deposit_count > 0 ) {
    $needs_attention[] = [
        'title' => 'Deposit batching is waiting',
        'detail' => metis_number_format( $open_deposit_count ) . ' gifts are still unbatched (' . $format_money( $open_deposit_total ) . ').',
        'url' => metis_portal_url( 'donations', 'deposits' ),
        'cta' => 'Open deposits',
        'severity' => 'critical',
    ];
}
if ( $can_access_module( 'people' ) && $pending_requests > 0 ) {
    $needs_attention[] = [
        'title' => 'Access requests are pending',
        'detail' => metis_number_format( $pending_requests ) . ' people are waiting on access approval.',
        'url' => metis_portal_url( 'people', 'access_requests' ),
        'cta' => 'Review requests',
        'severity' => 'soon',
    ];
}
if ( $can_access_module( 'newsletter' ) && $queued_emails > 0 ) {
    $needs_attention[] = [
        'title' => 'Newsletter send queue is not empty',
        'detail' => metis_number_format( $queued_emails ) . ' messages are queued for delivery.',
        'url' => metis_portal_url( 'newsletter', 'campaigns' ),
        'cta' => 'Open campaigns',
        'severity' => 'info',
    ];
}
if ( $can_access_module( 'grandys_stash' ) && $stash_attention > 0 ) {
    $needs_attention[] = [
        'title' => 'Grandy\'s Stash needs processing',
        'detail' => metis_number_format( $stash_attention ) . ' tickets are waiting for action or inventory.',
        'url' => metis_portal_url( 'grandys_stash', 'dashboard' ),
        'cta' => 'Open inbox',
        'severity' => 'critical',
    ];
}
if ( $can_access_module( 'calendar' ) && ! $calendar_ready ) {
    $needs_attention[] = [
        'title' => 'Calendar workspace is not configured',
        'detail' => 'Calendar setup still needs to be completed.',
        'url' => metis_portal_url( 'settings', 'calendar' ),
        'cta' => 'Configure calendar',
        'severity' => 'info',
    ];
}
if ( $can_access_module( 'drive' ) && ! $drive_home_ready ) {
    $needs_attention[] = [
        'title' => 'Drive user home is not enabled',
        'detail' => 'People folders cannot be auto-managed until a home drive is configured.',
        'url' => metis_portal_url( 'settings', 'drive' ),
        'cta' => 'Configure drive',
        'severity' => 'info',
    ];
}
if ( $can_access_module( 'settings' ) && $failed_jobs_count > 0 ) {
    $needs_attention[] = [
        'title' => 'Background jobs are failing',
        'detail' => metis_number_format( $failed_jobs_count ) . ' failed jobs are in the queue.',
        'url' => metis_portal_url( 'settings', 'scheduler' ),
        'cta' => 'Review jobs',
        'severity' => 'critical',
    ];
}

$needs_attention_groups = [
    'critical' => [],
    'soon' => [],
    'info' => [],
];
foreach ( $needs_attention as $item ) {
    $severity = (string) ( $item['severity'] ?? 'info' );
    if ( ! isset( $needs_attention_groups[ $severity ] ) ) {
        $severity = 'info';
    }
    $needs_attention_groups[ $severity ][] = $item;
}

$today_priority = [];
if ( $can_access_module( 'board' ) ) {
    $today_priority[] = [
        'title' => 'Overdue',
        'count' => (int) ( $board_action_counts['overdue'] ?? 0 ),
        'filter' => 'overdue',
        'note' => 'Past due board actions',
    ];
    $today_priority[] = [
        'title' => 'Due Today',
        'count' => (int) ( $board_action_counts['today'] ?? 0 ),
        'filter' => 'today',
        'note' => 'Actions due today',
    ];
    $today_priority[] = [
        'title' => 'Blocked',
        'count' => (int) ( $board_action_counts['blocked'] ?? 0 ),
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
        'value' => metis_number_format( $open_board_actions ),
        'note'  => 'Outstanding governance tasks',
    ],
    [
        'label' => 'Upcoming Meetings (7d)',
        'value' => metis_number_format( $upcoming_meetings ),
        'note'  => 'Scheduled or draft meetings',
    ],
    [
        'label' => 'Queued Jobs',
        'value' => metis_number_format( $pending_jobs_count ),
        'note'  => 'Background queue waiting',
    ],
];

$normalize_metrics = static function ( array $metrics ): array {
    $normalized = array_slice( $metrics, 0, 3 );
    while ( count( $normalized ) < 3 ) {
        $normalized[] = [
            'label' => '—',
            'value' => '—',
            'note'  => '',
        ];
    }
    return $normalized;
};
$focus_updated_label = metis_current_datetime()->format( 'M j, g:i a' );

$focus_cards_by_key = [];
if ( $can_access_module( 'board' ) ) {
    $focus_cards_by_key['board'] = [
        'title' => 'Board',
        'desc'  => 'Meetings, decisions, attendance, and open actions.',
        'url'   => metis_portal_url( 'board', 'dashboard' ),
        'metrics' => $normalize_metrics( $board_metrics ),
        'updated' => $focus_updated_label,
    ];
}
if ( $can_access_module( 'people' ) ) {
    $focus_cards_by_key['people'] = [
        'title' => 'People',
        'desc'  => 'Profiles, access approvals, and workspace links.',
        'url'   => metis_portal_url( 'people', 'dashboard' ),
        'metrics' => $normalize_metrics( $people_metrics ),
        'updated' => $focus_updated_label,
    ];
}
if ( $can_access_module( 'grandys_stash' ) ) {
    $focus_cards_by_key['grandys_stash'] = [
        'title' => 'Grandy\'s Stash',
        'desc'  => 'Request queue, waitlist pressure, and fulfillment.',
        'url'   => metis_portal_url( 'grandys_stash', 'dashboard' ),
        'metrics' => $normalize_metrics( $grandys_metrics ),
        'updated' => $focus_updated_label,
    ];
}
if ( $can_access_module( 'donations' ) || $can_access_module( 'finance' ) ) {
    $focus_cards_by_key['finance'] = [
        'title' => 'Finance',
        'desc'  => 'Revenue, deposits, and reconciliation status.',
        'url'   => $can_access_module( 'donations' ) ? metis_portal_url( 'donations', 'dashboard' ) : metis_portal_url( 'finance', 'finance' ),
        'metrics' => $normalize_metrics( $finance_metrics ),
        'updated' => $focus_updated_label,
    ];
}
if ( $can_access_module( 'newsletter' ) ) {
    $focus_cards_by_key['communications'] = [
        'title' => 'Communications',
        'desc'  => 'Newsletter queue and campaign activity.',
        'url'   => metis_portal_url( 'newsletter', 'dashboard' ),
        'metrics' => $normalize_metrics( $newsletter_metrics ),
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
        'value' => metis_number_format( $pending_jobs_count ),
        'state' => $pending_jobs_count > 0 ? 'warn' : 'ok',
    ],
    [
        'label' => 'Failed background jobs',
        'value' => metis_number_format( $failed_jobs_count ),
        'state' => $failed_jobs_count > 0 ? 'alert' : 'ok',
    ],
    [
        'label' => 'Calendar workspace',
        'value' => $calendar_ready ? 'Ready' : 'Needs setup',
        'state' => $calendar_ready ? 'ok' : 'warn',
    ],
    [
        'label' => 'Drive user home',
        'value' => $drive_home_ready ? 'Ready' : 'Needs setup',
        'state' => $drive_home_ready ? 'ok' : 'warn',
    ],
];
?>

<div class="mw-page-title"><?php echo metis_escape_html( metis_portal_name() ); ?> Dashboard</div>

<section class="metis-portal-hub-shell mw-panel">
    <div class="metis-portal-dashboard-intro">
        <div>
            <p class="mw-subtitle metis-portal-dashboard-subtitle">
                Operational dashboard for today. Needs Attention is filtered by your module access.
            </p>
        </div>
    </div>

    <div class="metis-portal-hub-kpis" aria-label="Today overview">
        <?php foreach ( $today_stats as $stat ) : ?>
            <article class="metis-portal-hub-kpi">
                <div class="metis-portal-hub-kpi-label"><?php echo metis_escape_html( $stat['label'] ); ?></div>
                <div class="metis-portal-hub-kpi-value"><?php echo metis_escape_html( $stat['value'] ); ?></div>
                <div class="metis-portal-hub-kpi-note"><?php echo metis_escape_html( $stat['note'] ); ?></div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ( $today_priority !== [] ) : ?>
        <div class="metis-portal-priority-strip" aria-label="Today priority">
            <?php foreach ( array_slice( $today_priority, 0, 3 ) as $priority_item ) : ?>
                <button
                    type="button"
                    class="metis-portal-priority-btn"
                    data-filter="<?php echo metis_escape_attr( (string) $priority_item['filter'] ); ?>"
                >
                    <span class="metis-portal-priority-title"><?php echo metis_escape_html( (string) $priority_item['title'] ); ?></span>
                    <span class="metis-portal-priority-value"><?php echo metis_escape_html( metis_number_format( (int) $priority_item['count'] ) ); ?></span>
                    <span class="metis-portal-priority-note"><?php echo metis_escape_html( (string) $priority_item['note'] ); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="metis-portal-hub-main">
        <section class="metis-portal-hub-attention" aria-labelledby="metis-portal-needs-attention">
            <div class="metis-portal-hub-section-head">
                <h2 id="metis-portal-needs-attention">Needs Attention</h2>
            </div>
            <?php if ( $needs_attention === [] ) : ?>
                <div class="metis-portal-hub-empty">No urgent items for your current access.</div>
            <?php else : ?>
                <div class="metis-portal-hub-attention-groups">
                    <?php
                    $group_labels = [
                        'critical' => 'Critical',
                        'soon' => 'Soon',
                        'info' => 'Info',
                    ];
                    foreach ( $needs_attention_groups as $group_key => $group_items ) :
                        if ( $group_items === [] ) {
                            continue;
                        }
                        $is_open = $group_key === 'critical';
                        ?>
                        <details class="metis-portal-attention-group" <?php echo $is_open ? 'open' : ''; ?>>
                            <summary>
                                <span><?php echo metis_escape_html( (string) ( $group_labels[ $group_key ] ?? ucfirst( $group_key ) ) ); ?></span>
                                <span class="metis-portal-attention-count"><?php echo metis_escape_html( metis_number_format( count( $group_items ) ) ); ?></span>
                            </summary>
                            <div class="metis-portal-hub-attention-list">
                                <?php foreach ( $group_items as $item ) : ?>
                                    <article class="metis-portal-hub-attention-item level-<?php echo metis_escape_attr( (string) $group_key ); ?>">
                                        <div>
                                            <h3><?php echo metis_escape_html( (string) $item['title'] ); ?></h3>
                                            <p><?php echo metis_escape_html( (string) $item['detail'] ); ?></p>
                                        </div>
                                        <a class="mw-btn mw-btn-xs" href="<?php echo metis_escape_url( (string) $item['url'] ); ?>"><?php echo metis_escape_html( (string) $item['cta'] ); ?></a>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( $can_access_module( 'board' ) ) : ?>
                <div
                    class="metis-portal-board-actions"
                    data-default-filter="<?php echo metis_escape_attr( $board_action_filter ); ?>"
                    data-owner-person-id="<?php echo metis_escape_attr( (string) $current_person_id ); ?>"
                >
                    <div class="metis-portal-hub-section-head metis-portal-action-head">
                        <h2>My Board Action Items</h2>
                    </div>
                    <div class="metis-portal-action-filters" role="tablist" aria-label="Board action filters">
                        <?php foreach ( $board_action_filters as $filter_key => $filter_label ) : ?>
                            <button
                                type="button"
                                class="metis-portal-action-filter<?php echo $filter_key === $board_action_filter ? ' is-active' : ''; ?>"
                                data-filter="<?php echo metis_escape_attr( (string) $filter_key ); ?>"
                                aria-pressed="<?php echo $filter_key === $board_action_filter ? 'true' : 'false'; ?>"
                            >
                                <?php echo metis_escape_html( (string) $filter_label ); ?>
                                <span><?php echo metis_escape_html( metis_number_format( (int) ( $board_action_counts[ $filter_key ] ?? 0 ) ) ); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="metis-portal-my-actions-wrap" id="metis-portal-my-actions-wrap">
                        <?php if ( $my_board_actions === [] ) : ?>
                            <div class="metis-portal-hub-empty">No board actions for this filter. <a href="<?php echo metis_escape_url( metis_portal_url( 'board', 'meeting' ) ); ?>">Create action item</a>.</div>
                        <?php else : ?>
                            <div class="metis-portal-my-actions">
                                <?php foreach ( $my_board_actions as $action ) : ?>
                                    <?php
                                    $due_raw = (string) ( $action['due_date'] ?? '' );
                                    $due_display = $due_raw !== '' ? date( 'M j, Y', strtotime( $due_raw ) ) : 'No due date';
                                    $is_overdue = $due_raw !== '' && $due_raw < date( 'Y-m-d' );
                                    $meeting_title = trim( (string) ( $action['meeting_title'] ?? '' ) );
                                    $meeting_label = $meeting_title !== '' ? $meeting_title : 'Board meeting';
                                    $meeting_id = (int) ( $action['meeting_id'] ?? 0 );
                                    $action_url = $meeting_id > 0 ? metis_portal_url( 'board', 'meeting', [ 'meeting' => $meeting_id ] ) : metis_portal_url( 'board', 'dashboard' );
                                    ?>
                                    <article class="metis-portal-my-action-item<?php echo $is_overdue ? ' is-overdue' : ''; ?>">
                                        <div class="metis-portal-my-action-main">
                                            <h3><?php echo metis_escape_html( (string) ( $action['title'] ?? 'Action item' ) ); ?></h3>
                                            <p><?php echo metis_escape_html( $meeting_label ); ?></p>
                                        </div>
                                        <div class="metis-portal-my-action-meta">
                                            <span class="metis-portal-my-action-status"><?php echo metis_escape_html( ucfirst( (string) ( $action['status'] ?? 'open' ) ) ); ?></span>
                                            <span class="metis-portal-my-action-due"><?php echo metis_escape_html( $due_display ); ?></span>
                                            <a class="mw-chip" href="<?php echo metis_escape_url( $action_url ); ?>">Open</a>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="metis-portal-hub-snapshots" aria-labelledby="metis-portal-module-snapshots">
            <div class="metis-portal-hub-section-head">
                <h2 id="metis-portal-module-snapshots">Focus Areas</h2>
            </div>
            <div class="metis-portal-focus-list">
                <?php foreach ( $focus_cards as $card ) : ?>
                    <article class="metis-portal-focus-card">
                        <a class="metis-portal-focus-head" href="<?php echo metis_escape_url( (string) $card['url'] ); ?>">
                            <div>
                                <h3><?php echo metis_escape_html( $card['title'] ); ?></h3>
                                <p><?php echo metis_escape_html( $card['desc'] ); ?></p>
                            </div>
                            <span class="metis-portal-focus-updated">Updated <?php echo metis_escape_html( (string) $card['updated'] ); ?></span>
                        </a>
                        <div class="metis-portal-focus-metrics">
                            <?php foreach ( $card['metrics'] as $metric ) : ?>
                                <div class="metis-portal-focus-metric">
                                    <span class="metis-portal-focus-metric-label"><?php echo metis_escape_html( (string) ( $metric['label'] ?? '' ) ); ?></span>
                                    <strong class="metis-portal-focus-metric-value"><?php echo metis_escape_html( (string) ( $metric['value'] ?? '' ) ); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ( $card['title'] === 'Board' && $upcoming_meetings === 0 ) : ?>
                            <div class="metis-portal-hub-empty metis-portal-card-empty">No meetings scheduled. <a href="<?php echo metis_escape_url( metis_portal_url( 'board', 'meeting' ) ); ?>">Create meeting</a>.</div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="metis-portal-hub-section-head metis-portal-system-head">
                <h2>System Watch</h2>
            </div>
            <div class="metis-portal-system-watch">
                <?php foreach ( $system_watch as $watch ) : ?>
                    <article class="metis-portal-system-item state-<?php echo metis_escape_attr( (string) $watch['state'] ); ?>">
                        <span class="metis-portal-system-label"><?php echo metis_escape_html( (string) $watch['label'] ); ?></span>
                        <strong class="metis-portal-system-value"><?php echo metis_escape_html( (string) $watch['value'] ); ?></strong>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</section>
