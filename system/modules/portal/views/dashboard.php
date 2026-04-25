<?php if (!defined('METIS_ROOT')) exit; ?>

<?php
require_once __DIR__ . '/_dashboard_data.php';

$db = metis_db();

$format_money = static function ( float $amount ): string {
    return '$' . metis_number_format( $amount, 2 );
};
$table_exists = static function ( string $table ) use ( $db ): bool {
    return metis_portal_dashboard_table_exists( $db, $table );
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
    $donation_summary = metis_portal_dashboard_donation_stats(
        $db,
        $transactions_table,
        $campaigns_table,
        $last_30_start,
        $month_start,
        $year_start,
        $table_exists( $campaigns_table )
    );
    $active_campaigns = (int) ( $donation_summary['active_campaigns'] ?? 0 );
    $total_campaigns  = (int) ( $donation_summary['total_campaigns'] ?? 0 );

    $donations_metrics = [
        [
            'label' => 'Last 30 Days',
            'value' => $format_money( (float) ( $donation_summary['raised_30d'] ?? 0 ) ),
            'note'  => 'Recent giving',
        ],
        [
            'label' => 'This Month',
            'value' => $format_money( (float) ( $donation_summary['raised_month'] ?? 0 ) ),
            'note'  => $now_dt->format( 'F Y' ),
        ],
        [
            'label' => 'Open Queue',
            'value' => $format_money( (float) ( $donation_summary['open_deposit_total'] ?? 0 ) ),
            'note'  => metis_number_format( (int) ( $donation_summary['open_deposit_count'] ?? 0 ) ) . ' gifts to batch',
        ],
        [
            'label' => 'Campaigns',
            'value' => metis_number_format( $active_campaigns ),
            'note'  => metis_number_format( $total_campaigns ) . ' total configured',
        ],
    ];

    $finance_metrics[] = [
        'label' => 'Month to Date',
        'value' => $format_money( (float) ( $donation_summary['raised_month'] ?? 0 ) ),
        'note'  => 'From transactions',
    ];
    $finance_metrics[] = [
        'label' => 'Year to Date',
        'value' => $format_money( (float) ( $donation_summary['raised_ytd'] ?? 0 ) ),
        'note'  => 'Gross revenue this year',
    ];
    $finance_metrics[] = [
        'label' => 'Awaiting Deposit',
        'value' => $format_money( (float) ( $donation_summary['open_deposit_total'] ?? 0 ) ),
        'note'  => metis_number_format( (int) ( $donation_summary['open_deposit_count'] ?? 0 ) ) . ' unsettled transactions',
    ];
}

if ( $table_exists( $contacts_table ) ) {
    $contact_stats = metis_portal_dashboard_contacts_stats( $db, $contacts_table );

    $contacts_metrics = [
        [
            'label' => 'Contacts',
            'value' => metis_number_format( (int) ( $contact_stats['total_contacts'] ?? 0 ) ),
            'note'  => 'Total records',
        ],
        [
            'label' => 'Donor IDs',
            'value' => metis_number_format( (int) ( $contact_stats['with_did'] ?? 0 ) ),
            'note'  => metis_number_format( (int) ( $contact_stats['without_did'] ?? 0 ) ) . ' without donor IDs',
        ],
        [
            'label' => 'Duplicates',
            'value' => metis_number_format( (int) ( $contact_stats['duplicate_count'] ?? 0 ) ),
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
    $newsletter_stats = metis_portal_dashboard_newsletter_stats(
        $db,
        $newsletter_lists_table,
        $newsletter_subs_table,
        $newsletter_campaigns_table,
        $newsletter_messages_table,
        $newsletter_sent_30d
    );

    $newsletter_metrics = [
        [
            'label' => 'Subscribers',
            'value' => metis_number_format( (int) ( $newsletter_stats['subscribers'] ?? 0 ) ),
            'note'  => 'Confirmed subscribers',
        ],
        [
            'label' => 'Queued',
            'value' => metis_number_format( (int) ( $newsletter_stats['queued'] ?? 0 ) ),
            'note'  => 'Waiting to send',
        ],
        [
            'label' => 'Sent 30d',
            'value' => metis_number_format( (int) ( $newsletter_stats['sent_30d'] ?? 0 ) ),
            'note'  => 'Delivered in 30 days',
        ],
        [
            'label' => 'Lists',
            'value' => metis_number_format( (int) ( $newsletter_stats['active_lists'] ?? 0 ) ),
            'note'  => metis_number_format( (int) ( $newsletter_stats['total_campaigns'] ?? 0 ) ) . ' campaigns total',
        ],
    ];
}

if ( $table_exists( $board_meetings_table ) && $table_exists( $board_actions_table ) && $table_exists( $board_decisions_table ) && $table_exists( $board_committees_table ) ) {
    $now_mysql = metis_current_time( 'mysql' );
    $board_stats = metis_portal_dashboard_board_overview_stats(
        $db,
        $board_meetings_table,
        $board_actions_table,
        $board_decisions_table,
        $board_committees_table,
        $now_mysql
    );
    $board_metrics = [
        [
            'label' => 'Meetings',
            'value' => metis_number_format( (int) ( $board_stats['total_meetings'] ?? 0 ) ),
            'note'  => metis_number_format( (int) ( $board_stats['upcoming_meetings'] ?? 0 ) ) . ' upcoming',
        ],
        [
            'label' => 'Open Actions',
            'value' => metis_number_format( (int) ( $board_stats['open_actions'] ?? 0 ) ),
            'note'  => 'Outstanding governance tasks',
        ],
        [
            'label' => 'Decisions',
            'value' => metis_number_format( (int) ( $board_stats['decision_count'] ?? 0 ) ),
            'note'  => 'Recorded decisions',
        ],
        [
            'label' => 'Committees',
            'value' => metis_number_format( (int) ( $board_stats['committee_count'] ?? 0 ) ),
            'note'  => 'Active committees',
        ],
    ];
}

if ( $table_exists( $people_table ) ) {
    $people_stats = metis_portal_dashboard_people_stats(
        $db,
        $people_table,
        $table_exists( $requests_table ) ? $requests_table : null
    );
    $people_metrics = [
        [
            'label' => 'People',
            'value' => metis_number_format( (int) ( $people_stats['total_people'] ?? 0 ) ),
            'note'  => metis_number_format( (int) ( $people_stats['active_people'] ?? 0 ) ) . ' active',
        ],
        [
            'label' => 'Staff',
            'value' => metis_number_format( (int) ( $people_stats['staff_count'] ?? 0 ) ),
            'note'  => metis_number_format( (int) ( $people_stats['board_count'] ?? 0 ) ) . ' board members',
        ],
        [
            'label' => 'Workspace',
            'value' => metis_number_format( (int) ( $people_stats['workspace_count'] ?? 0 ) ),
            'note'  => 'Linked Workspace users',
        ],
    ];

    if ( $table_exists( $requests_table ) ) {
        $people_metrics[] = [
            'label' => 'Requests',
            'value' => metis_number_format( (int) ( $people_stats['pending_requests'] ?? 0 ) ),
            'note'  => 'Pending access requests',
        ];
    }
}

if ( function_exists( 'metis_calendar_workspace_settings_all' ) ) {
    $workspace = metis_portal_dashboard_remember(
        'calendar_workspace',
        120,
        static fn (): array => metis_calendar_workspace_settings_all()
    );
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
    $drive_configs = metis_portal_dashboard_remember(
        'drive_configs',
        120,
        static fn (): array => (array) metis_drive_configured_drives()
    );
    $users_home    = metis_portal_dashboard_remember(
        'drive_users_home',
        120,
        static fn (): array => (array) metis_drive_users_home_setting()
    );
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
    $finance_settlement_stats = metis_portal_dashboard_finance_settlement_stats(
        $db,
        $deposits_table,
        $month_start,
        $table_exists( $recons_table ) ? $recons_table : null
    );

    $finance_metrics[1] = [
        'label' => 'Settled This Month',
        'value' => $format_money( (float) ( $finance_settlement_stats['month_net'] ?? 0 ) ),
        'note'  => 'Deposits cleared to bank',
    ];

    $finance_metrics[3] = [
        'label' => 'Pending Payouts',
        'value' => $format_money( (float) ( $finance_settlement_stats['pending_total'] ?? 0 ) ),
        'note'  => metis_number_format( (int) ( $finance_settlement_stats['pending_count'] ?? 0 ) ) . ' deposits pending',
    ];
    if ( $table_exists( $recons_table ) ) {
        $finance_metrics[] = [
            'label' => 'Open Recons',
            'value' => metis_number_format( (int) ( $finance_settlement_stats['open_recons'] ?? 0 ) ),
            'note'  => 'Periods still to match',
        ];
    }
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
    $job_stats = metis_portal_dashboard_job_stats( $db, $job_queue_table );
    $pending_jobs_count = (int) ( $job_stats['pending_jobs_count'] ?? 0 );
    $failed_jobs_count  = (int) ( $job_stats['failed_jobs_count'] ?? 0 );
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

$watch_stats = metis_portal_dashboard_watch_stats(
    $db,
    [
        $table_exists( $board_meetings_table ) ? $board_meetings_table : '',
        $table_exists( $board_actions_table ) ? $board_actions_table : '',
        $table_exists( $requests_table ) ? $requests_table : '',
        $table_exists( $newsletter_messages_table ) ? $newsletter_messages_table : '',
        $table_exists( $transactions_table ) ? $transactions_table : '',
    ],
    $now_mysql,
    $next_week_mysql
);
$upcoming_meetings = (int) ( $watch_stats['upcoming_meetings'] ?? 0 );
$open_board_actions = (int) ( $watch_stats['open_board_actions'] ?? 0 );
$pending_requests = (int) ( $watch_stats['pending_requests'] ?? 0 );
$queued_emails = (int) ( $watch_stats['queued_emails'] ?? 0 );
$open_deposit_count = (int) ( $watch_stats['open_deposit_count'] ?? 0 );
$open_deposit_total = (float) ( $watch_stats['open_deposit_total'] ?? 0 );

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
    $board_action_counts = metis_portal_dashboard_board_action_counts(
        $db,
        $board_actions_table,
        $current_person_id,
        $today_date,
        $due7_date
    );
}

$dashboard_view = metis_portal_build_dashboard_view_model(
    [
        'can_access_module' => $can_access_module,
        'format_money' => $format_money,
        'open_board_actions' => $open_board_actions,
        'upcoming_meetings' => $upcoming_meetings,
        'open_deposit_count' => $open_deposit_count,
        'open_deposit_total' => $open_deposit_total,
        'pending_requests' => $pending_requests,
        'queued_emails' => $queued_emails,
        'stash_attention' => $stash_attention,
        'calendar_ready' => $calendar_ready,
        'drive_home_ready' => $drive_home_ready,
        'failed_jobs_count' => $failed_jobs_count,
        'pending_jobs_count' => $pending_jobs_count,
        'board_action_counts' => $board_action_counts,
        'board_metrics' => $board_metrics,
        'people_metrics' => $people_metrics,
        'grandys_metrics' => $grandys_metrics,
        'finance_metrics' => $finance_metrics,
        'newsletter_metrics' => $newsletter_metrics,
    ]
);
$needs_attention = $dashboard_view['needs_attention'];
$needs_attention_groups = $dashboard_view['needs_attention_groups'];
$today_priority = $dashboard_view['today_priority'];
$today_stats = $dashboard_view['today_stats'];
$focus_cards = $dashboard_view['focus_cards'];
$system_watch = $dashboard_view['system_watch'];
?>

<div class="metis-page-title"><?php echo metis_escape_html( metis_portal_name() ); ?> Dashboard</div>

<section class="metis-portal-hub-shell metis-panel">
    <div class="metis-portal-dashboard-intro">
        <div>
            <p class="metis-subtitle metis-portal-dashboard-subtitle">
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
                                        <a class="metis-btn metis-btn-xs" href="<?php echo metis_escape_url( (string) $item['url'] ); ?>"><?php echo metis_escape_html( (string) $item['cta'] ); ?></a>
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
                                            <a class="metis-chip" href="<?php echo metis_escape_url( $action_url ); ?>">Open</a>
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
