<?php if (!defined('ABSPATH')) exit; ?>

<?php
global $wpdb;

$modules = metis_get_modules();
$format_money = static function ( float $amount ): string {
    return '$' . number_format_i18n( $amount, 2 );
};
$table_exists = static function ( string $table ) use ( $wpdb ): bool {
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    return $exists === $table;
};

$now_dt        = current_datetime();
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

$transactions_table = Metis_Tables::get( 'transactions' );
$campaigns_table    = Metis_Tables::get( 'campaigns' );
$deposits_table     = Metis_Tables::get( 'deposits' );
$recons_table       = Metis_Tables::get( 'finance_reconciliations' );
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
    $donation_summary = $wpdb->get_row( $wpdb->prepare(
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
        $last_30_start,
        $month_start,
        $year_start
    ) );

    $active_campaigns = 0;
    $total_campaigns  = 0;

    if ( $table_exists( $campaigns_table ) ) {
        $campaign_counts = $wpdb->get_row(
            "
            SELECT
                COUNT(*) AS total_campaigns,
                SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_campaigns
            FROM {$campaigns_table}
            "
        );

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
            'note'  => number_format_i18n( (int) ( $donation_summary->open_deposit_count ?? 0 ) ) . ' gifts to batch',
        ],
        [
            'label' => 'Campaigns',
            'value' => number_format_i18n( $active_campaigns ),
            'note'  => number_format_i18n( $total_campaigns ) . ' total configured',
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
        'note'  => number_format_i18n( (int) ( $donation_summary->open_deposit_count ?? 0 ) ) . ' unsettled transactions',
    ];
}

if ( $table_exists( $contacts_table ) ) {
    $total_contacts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$contacts_table}" );
    $with_did       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$contacts_table} WHERE did IS NOT NULL AND did <> ''" );
    $without_did    = max( 0, $total_contacts - $with_did );
    $duplicate_count = 0;

    $duplicate_rows = $wpdb->get_results(
        "SELECT LOWER(TRIM(email)) AS email_key, COUNT(*) AS matches
         FROM {$contacts_table}
         WHERE email IS NOT NULL AND TRIM(email) <> ''
         GROUP BY LOWER(TRIM(email))
         HAVING COUNT(*) > 1",
        ARRAY_A
    ) ?: [];

    foreach ( $duplicate_rows as $row ) {
        $duplicate_count += 1;
    }

    $contacts_metrics = [
        [
            'label' => 'Contacts',
            'value' => number_format_i18n( $total_contacts ),
            'note'  => 'Total records',
        ],
        [
            'label' => 'Donor IDs',
            'value' => number_format_i18n( $with_did ),
            'note'  => number_format_i18n( $without_did ) . ' without donor IDs',
        ],
        [
            'label' => 'Duplicates',
            'value' => number_format_i18n( $duplicate_count ),
            'note'  => 'Potential duplicate groups',
        ],
    ];
}

if ( $table_exists( $newsletter_lists_table ) && $table_exists( $newsletter_subs_table ) && $table_exists( $newsletter_campaigns_table ) && $table_exists( $newsletter_messages_table ) ) {
    $newsletter_sent_30d = 0;
    if ( function_exists( 'metis_newsletter_resolved_timezone' ) ) {
        $newsletter_sent_30d = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$newsletter_messages_table} WHERE status='sent' AND sent_at >= %s",
                ( new DateTimeImmutable( 'now', metis_newsletter_resolved_timezone() ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' )
            )
        );
    }

    $newsletter_metrics = [
        [
            'label' => 'Subscribers',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$newsletter_subs_table} WHERE status = 'subscribed'" ) ),
            'note'  => 'Confirmed subscribers',
        ],
        [
            'label' => 'Queued',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$newsletter_messages_table} WHERE status = 'queued'" ) ),
            'note'  => 'Waiting to send',
        ],
        [
            'label' => 'Sent 30d',
            'value' => number_format_i18n( $newsletter_sent_30d ),
            'note'  => 'Delivered in 30 days',
        ],
        [
            'label' => 'Lists',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$newsletter_lists_table} WHERE is_active = 1" ) ),
            'note'  => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$newsletter_campaigns_table}" ) ) . ' campaigns total',
        ],
    ];
}

if ( $table_exists( $board_meetings_table ) && $table_exists( $board_actions_table ) && $table_exists( $board_decisions_table ) && $table_exists( $board_committees_table ) ) {
    $now_mysql = current_time( 'mysql' );
    $board_metrics = [
        [
            'label' => 'Meetings',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$board_meetings_table}" ) ),
            'note'  => number_format_i18n( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$board_meetings_table} WHERE meeting_date >= %s AND status IN ('scheduled','draft')", $now_mysql ) ) ) . ' upcoming',
        ],
        [
            'label' => 'Open Actions',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$board_actions_table} WHERE status <> 'done'" ) ),
            'note'  => 'Outstanding governance tasks',
        ],
        [
            'label' => 'Decisions',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$board_decisions_table}" ) ),
            'note'  => 'Recorded decisions',
        ],
        [
            'label' => 'Committees',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$board_committees_table} WHERE is_active = 1" ) ),
            'note'  => 'Active committees',
        ],
    ];
}

if ( $table_exists( $people_table ) ) {
    $people_metrics = [
        [
            'label' => 'People',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$people_table}" ) ),
            'note'  => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$people_table} WHERE status = 'active'" ) ) . ' active',
        ],
        [
            'label' => 'Staff',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$people_table} WHERE is_staff = 1" ) ),
            'note'  => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$people_table} WHERE is_board = 1" ) ) . ' board members',
        ],
        [
            'label' => 'Workspace',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$people_table} WHERE is_workspace_user = 1" ) ),
            'note'  => 'Linked Workspace users',
        ],
    ];

    if ( $table_exists( $requests_table ) ) {
        $people_metrics[] = [
            'label' => 'Requests',
            'value' => number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$requests_table} WHERE status = 'pending'" ) ),
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
            'value' => number_format_i18n( $calendar_count ),
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
            'value' => number_format_i18n( count( (array) $drive_configs ) ),
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
    $deposit_summary = $wpdb->get_row( $wpdb->prepare(
        "
        SELECT
            COALESCE(SUM(CASE WHEN deposit_date >= %s THEN COALESCE(net_total, total_amount, 0) ELSE 0 END), 0) AS month_net,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'in_transit') THEN COALESCE(net_total, total_amount, 0) ELSE 0 END), 0) AS pending_total,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'in_transit') THEN 1 ELSE 0 END), 0) AS pending_count
        FROM {$deposits_table}
        ",
        $month_start
    ) );

    $finance_metrics[1] = [
        'label' => 'Settled This Month',
        'value' => $format_money( (float) ( $deposit_summary->month_net ?? 0 ) ),
        'note'  => 'Deposits cleared to bank',
    ];

    $finance_metrics[3] = [
        'label' => 'Pending Payouts',
        'value' => $format_money( (float) ( $deposit_summary->pending_total ?? 0 ) ),
        'note'  => number_format_i18n( (int) ( $deposit_summary->pending_count ?? 0 ) ) . ' deposits pending',
    ];
}

if ( $table_exists( $recons_table ) ) {
    $open_recons = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$recons_table} WHERE status <> 'matched'" );
    $finance_metrics[] = [
        'label' => 'Open Recons',
        'value' => number_format_i18n( $open_recons ),
        'note'  => 'Periods still to match',
    ];
}

$desired_order = [ 'donations', 'finance', 'contacts', 'newsletter', 'board', 'drive', 'calendar', 'website', 'people', 'settings' ];
$ordered_modules = [];

foreach ( $desired_order as $slug ) {
    if ( isset( $modules[ $slug ] ) ) {
        $ordered_modules[ $slug ] = $modules[ $slug ];
    }
}

foreach ( $modules as $slug => $module ) {
    if ( ! isset( $ordered_modules[ $slug ] ) ) {
        $ordered_modules[ $slug ] = $module;
    }
}

$dashboard_sections = [];
$section_count      = 0;
$view_count         = 0;

foreach ( $ordered_modules as $slug => $module ) {
    if ( $slug === 'profile' || $slug === 'portal' ) {
        continue;
    }

    $cfg          = is_array( $module['config'] ?? null ) ? $module['config'] : [];
    $label        = (string) ( $cfg['label'] ?? ucfirst( $slug ) );
    $description  = trim( (string) ( $cfg['description'] ?? '' ) );
    $icon         = (string) ( $cfg['icon'] ?? '' );
    $default_view = sanitize_key( (string) ( $cfg['default_view'] ?? 'dashboard' ) );
    $menu         = is_array( $cfg['menu'] ?? null ) ? $cfg['menu'] : [];
    $menu_items   = is_array( $menu['items'] ?? null ) ? $menu['items'] : [];
    $views        = is_array( $cfg['views'] ?? null ) ? $cfg['views'] : [];
    $parent_views = is_array( $cfg['parent_views'] ?? null ) ? $cfg['parent_views'] : [];

    if ( $description === '' ) {
        $description = 'Open this section to manage related tools, activity, and records.';
    }

    $quick_links = [];

    if ( ! empty( $menu_items ) ) {
        foreach ( $menu_items as $view_slug => $view_label ) {
            $quick_links[] = [
                'slug'  => sanitize_key( (string) $view_slug ),
                'label' => (string) $view_label,
            ];
        }
    } else {
        $child_views = array_diff_key( $views, $parent_views );

        if ( isset( $child_views[ $default_view ] ) ) {
            $quick_links[] = [
                'slug'  => $default_view,
                'label' => $default_view === 'dashboard' ? 'Dashboard' : ucwords( str_replace( '_', ' ', $default_view ) ),
            ];
            unset( $child_views[ $default_view ] );
        }

        foreach ( $child_views as $view_slug => $template ) {
            $view_slug = sanitize_key( (string) $view_slug );
            if ( $view_slug === '' ) {
                continue;
            }

            $quick_links[] = [
                'slug'  => $view_slug,
                'label' => ucwords( str_replace( '_', ' ', $view_slug ) ),
            ];
        }
    }

    if ( empty( $quick_links ) ) {
        $quick_links[] = [
            'slug'  => $default_view !== '' ? $default_view : 'dashboard',
            'label' => 'Open',
        ];
    }

    $primary_link = $quick_links[0];
    $live_metrics = [];

    if ( $slug === 'donations' ) {
        $live_metrics = $donations_metrics;
    } elseif ( $slug === 'finance' ) {
        $live_metrics = $finance_metrics;
    } elseif ( $slug === 'contacts' ) {
        $live_metrics = $contacts_metrics;
    } elseif ( $slug === 'newsletter' ) {
        $live_metrics = $newsletter_metrics;
    } elseif ( $slug === 'board' ) {
        $live_metrics = $board_metrics;
    } elseif ( $slug === 'calendar' ) {
        $live_metrics = $calendar_metrics;
    } elseif ( $slug === 'drive' ) {
        $live_metrics = $drive_metrics;
    } elseif ( $slug === 'people' ) {
        $live_metrics = $people_metrics;
    }

    $dashboard_sections[] = [
        'slug'         => $slug,
        'label'        => $label,
        'description'  => $description,
        'icon'         => $icon,
        'primary_url'  => metis_portal_url( $slug, $primary_link['slug'] ),
        'primary_text' => $primary_link['label'],
        'metrics'      => $live_metrics,
        'quick_links'  => $quick_links,
    ];

    $section_count++;
    $view_count += count( $quick_links );
}
?>

<div class="mw-page-title"><?php echo esc_html( metis_portal_name() ); ?> Dashboard</div>

<div class="metis-portal-dashboard-intro mw-panel">
    <div>
        <p class="mw-subtitle metis-portal-dashboard-subtitle">
            One place to reach every section in the portal. Each card below opens a full area and, where available, links straight into its key views.
        </p>
    </div>
    <div class="metis-portal-dashboard-stats" aria-label="Dashboard summary">
        <div class="metis-portal-dashboard-stat">
            <span class="metis-portal-dashboard-stat-value"><?php echo esc_html( (string) $section_count ); ?></span>
            <span class="metis-portal-dashboard-stat-label">Sections</span>
        </div>
        <div class="metis-portal-dashboard-stat">
            <span class="metis-portal-dashboard-stat-value"><?php echo esc_html( (string) $view_count ); ?></span>
            <span class="metis-portal-dashboard-stat-label">Quick links</span>
        </div>
    </div>
</div>

<div class="metis-portal-dashboard-grid">
    <?php foreach ( $dashboard_sections as $section ) : ?>
        <section class="metis-portal-dashboard-card mw-tile-inner" aria-labelledby="metis-dashboard-card-<?php echo esc_attr( $section['slug'] ); ?>">
            <div class="metis-portal-dashboard-card-top">
                <div class="metis-portal-dashboard-card-header">
                    <div class="metis-portal-dashboard-card-icon" aria-hidden="true">
                        <?php echo $section['icon']; ?>
                    </div>
                    <div>
                        <h2 class="mw-tile-title metis-portal-dashboard-card-title" id="metis-dashboard-card-<?php echo esc_attr( $section['slug'] ); ?>">
                            <?php echo esc_html( $section['label'] ); ?>
                        </h2>
                    </div>
                </div>
                <p class="mw-tile-desc metis-portal-dashboard-card-desc">
                    <?php echo esc_html( $section['description'] ); ?>
                </p>
            </div>

            <div class="metis-portal-dashboard-card-actions">
                <a class="mw-btn mw-btn-xs" href="<?php echo esc_url( $section['primary_url'] ); ?>">
                    Open <?php echo esc_html( $section['primary_text'] ); ?>
                </a>
            </div>

            <?php if ( ! empty( $section['metrics'] ) ) : ?>
                <div class="metis-portal-dashboard-metrics" aria-label="<?php echo esc_attr( $section['label'] . ' KPIs' ); ?>">
                    <?php foreach ( $section['metrics'] as $metric ) : ?>
                        <article class="metis-portal-dashboard-metric">
                            <div class="metis-portal-dashboard-metric-label"><?php echo esc_html( $metric['label'] ); ?></div>
                            <div class="metis-portal-dashboard-metric-value"><?php echo esc_html( $metric['value'] ); ?></div>
                            <div class="metis-portal-dashboard-metric-note"><?php echo esc_html( $metric['note'] ); ?></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            $secondary_links = array_slice( $section['quick_links'], 1 );
            ?>
            <?php if ( ! empty( $secondary_links ) ) : ?>
                <div class="metis-portal-dashboard-links" aria-label="<?php echo esc_attr( $section['label'] . ' quick links' ); ?>">
                    <?php foreach ( $secondary_links as $link ) : ?>
                        <a class="mw-chip" href="<?php echo esc_url( metis_portal_url( $section['slug'], $link['slug'] ) ); ?>">
                            <?php echo esc_html( $link['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
