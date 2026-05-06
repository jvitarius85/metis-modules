<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

require_once __DIR__ . '/../templates/_settings_bootstrap.php';

use Metis\Core\Cache\CacheService;

if ( ! function_exists( 'metis_settings_queue_operation_response' ) ) {
    function metis_settings_queue_operation_response( string $operation, array $payload = [], string $message = 'Operation queued.' ): void {
        $queued = metis_operations()->queueOperation( $operation, $payload, [
            'created_by' => metis_current_user_id(),
        ] );

        if ( empty( $queued['ok'] ) ) {
            metis_runtime_send_json_error( [ 'message' => 'Unable to queue operation.' ], 500 );
        }

        metis_runtime_send_json_success( [
            'message' => $message,
            'queued' => $queued,
        ] );
    }
}

if ( ! function_exists( 'metis_settings_release_progress_dir' ) ) {
    function metis_settings_release_progress_dir(): string {
        return rtrim( (string) METIS_PATH, '/\\' ) . '/storage/runtime/release/progress';
    }
}

if ( ! function_exists( 'metis_settings_release_progress_token' ) ) {
    function metis_settings_release_progress_token( string $token ): string {
        return preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $token ) === 1 ? $token : '';
    }
}

if ( ! function_exists( 'metis_settings_release_progress_path' ) ) {
    function metis_settings_release_progress_path( string $token ): string {
        return metis_settings_release_progress_dir() . '/' . $token . '.json';
    }
}

if ( ! function_exists( 'metis_settings_write_release_progress' ) ) {
    function metis_settings_write_release_progress( string $token, array $payload ): void {
        $token = metis_settings_release_progress_token( $token );
        if ( $token === '' ) {
            return;
        }

        $dir = metis_settings_release_progress_dir();
        if ( ! is_dir( $dir ) ) {
            metis_runtime_make_dir( $dir );
        }

        $payload['token'] = $token;
        $payload['updated_at'] = (string) ( $payload['updated_at'] ?? metis_current_time( 'mysql' ) );
        @file_put_contents(
            metis_settings_release_progress_path( $token ),
            metis_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '{}',
            LOCK_EX
        );
    }
}

if ( ! function_exists( 'metis_settings_checker_recompute_security_offenses' ) ) {
    function metis_settings_checker_recompute_security_offenses( array $report ): array {
        if ( ! class_exists( 'Metis_Tables' ) ) {
            return $report;
        }

        $security_table = Metis_Tables::get( 'audit_security' );
        $is_production_like = in_array(
            metis_key_clean( (string) ( function_exists( 'metis_environment_type' ) ? metis_environment_type() : 'production' ) ),
            [ 'production', 'prod', 'live' ],
            true
        );
        $security_offense_clause = "
            (
                LOWER(action_type) = 'login_failed'
                OR LOWER(action_type) = 'security_rate_limit_triggered'
                OR LOWER(action_type) = 'enclave.denied_rate_limit'
                OR LOWER(action_type) = 'rate_limited'
                OR LOWER(action_type) = 'invalid_cron_secret'
                OR LOWER(action_type) = 'cron_secret_missing'
                OR LOWER(action_type) LIKE '%denied%'
                OR LOWER(action_type) LIKE '%failed%'
                OR LOWER(action_type) LIKE '%blocked%'
                OR LOWER(action_type) LIKE '%lockout%'
                OR LOWER(action_type) LIKE '%threat%'
                OR LOWER(action_type) LIKE '%rate_limit%'
                OR LOWER(action_type) LIKE '%rate-lim%'
                OR LOWER(action_type) LIKE '%429%'
            )
        ";
        $security_offense_exclusion_clause = "
            NOT (
                (LOWER(action_type) = 'route_action_failed' AND LOWER(resource_label) IN ('invalid_nonce', 'operation_not_registered'))
                OR (LOWER(action_type) = 'ajax_action_failed' AND LOWER(resource_label) IN ('invalid_nonce', 'operation_not_registered'))
                OR (LOWER(action_type) = 'system_cron_task_failed' AND LOWER(module_slug) = 'grandys_stash')
            )
        ";

        $offense_total = (int) metis_db()->scalar(
            "SELECT COUNT(*)
             FROM {$security_table}
             WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND {$security_offense_clause}
               AND {$security_offense_exclusion_clause}"
        );
        $offense_top_rows = metis_db()->fetchAll(
            "SELECT action_type, COUNT(*) AS total
             FROM {$security_table}
             WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND {$security_offense_clause}
               AND {$security_offense_exclusion_clause}
             GROUP BY action_type
             ORDER BY total DESC
             LIMIT 1"
        );
        $offense_top = '';
        if ( is_array( $offense_top_rows ) && ! empty( $offense_top_rows[0]['action_type'] ) ) {
            $offense_top = (string) $offense_top_rows[0]['action_type'];
        }

        $offense_rows = metis_db()->fetchAll(
            "SELECT module_slug, action_type, resource_label, COUNT(*) AS total
             FROM {$security_table}
             WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND {$security_offense_clause}
               AND {$security_offense_exclusion_clause}
             GROUP BY module_slug, action_type, resource_label
             ORDER BY total DESC
             LIMIT 3"
        ) ?: [];
        $offense_breakdown = [];
        foreach ( $offense_rows as $row ) {
            $module = trim( (string) ( $row['module_slug'] ?? '' ) );
            $action = trim( (string) ( $row['action_type'] ?? '' ) );
            $resource = trim( (string) ( $row['resource_label'] ?? '' ) );
            $count = (int) ( $row['total'] ?? 0 );
            if ( $count < 1 ) {
                continue;
            }
            $descriptor = ( $module !== '' ? $module : 'unknown-module' ) . '/' . ( $action !== '' ? $action : 'unknown-action' );
            if ( $resource !== '' ) {
                $descriptor .= ' [' . $resource . ']';
            }
            $offense_breakdown[] = $descriptor . ': ' . $count;
        }

        $offense_status = $offense_total > ( $is_production_like ? 100 : 200 )
            ? 'fail'
            : ( $offense_total > ( $is_production_like ? 20 : 50 ) ? 'warn' : 'pass' );
        $offense_message = $offense_total < 1
            ? 'No security offense events were recorded in audit data for the last 7 days.'
            : sprintf(
                '%d security offense events in audit data for 7 days. Top repeated offenders: %s.',
                $offense_total,
                ! empty( $offense_breakdown ) ? implode( '; ', $offense_breakdown ) : ( $offense_top !== '' ? $offense_top : 'none identified' )
            );

        $checks = array_values( is_array( $report['checks'] ?? null ) ? $report['checks'] : [] );
        $found_check = false;
        foreach ( $checks as &$check ) {
            if ( (string) ( $check['id'] ?? '' ) !== 'security_offenses_7d' ) {
                continue;
            }
            $check['status'] = $offense_status;
            $check['message'] = $offense_message;
            $check['recommendation'] = $offense_status === 'pass' ? '' : 'Review repeated blocked/failed security events and tighten the offending routes or actors.';
            $found_check = true;
            break;
        }
        unset( $check );
        if ( ! $found_check ) {
            $checks[] = [
                'id' => 'security_offenses_7d',
                'title' => 'Repeated Security Offenses (7d)',
                'category' => 'security',
                'status' => $offense_status,
                'message' => $offense_message,
                'recommendation' => $offense_status === 'pass' ? '' : 'Review repeated blocked/failed security events and tighten the offending routes or actors.',
            ];
        }
        $report['checks'] = $checks;

        $kpis = array_values( is_array( $report['kpis'] ?? null ) ? $report['kpis'] : [] );
        foreach ( $kpis as &$kpi ) {
            if ( (string) ( $kpi['id'] ?? '' ) !== 'kpi_security_offenses' ) {
                continue;
            }
            $kpi['value'] = (string) $offense_total;
            $kpi['hint'] = $offense_top !== '' ? 'Top: ' . $offense_top : '';
            $kpi['tone'] = $offense_status === 'pass' ? 'good' : ( $offense_status === 'fail' ? 'bad' : 'warn' );
            break;
        }
        unset( $kpi );
        $report['kpis'] = $kpis;

        $status_counts = [ 'pass' => 0, 'warn' => 0, 'fail' => 0 ];
        foreach ( $checks as $check ) {
            $status = (string) ( $check['status'] ?? 'warn' );
            if ( isset( $status_counts[ $status ] ) ) {
                $status_counts[ $status ]++;
            }
        }
        $report['status_counts'] = $status_counts;
        $check_count = count( $checks );
        $computed_penalty = ( (int) $status_counts['warn'] * 10 ) + ( (int) $status_counts['fail'] * 25 );
        $max_penalty = max( 1, $check_count * 25 );
        $report['score'] = max( 0, min( 100, (int) round( 100 * max( 0, $max_penalty - $computed_penalty ) / $max_penalty ) ) );

        return $report;
    }
}

metis_ajax_register_controller( 'metis_settings_fetch_logging_viewer', [
    'module' => 'settings',
    'permission' => 'view',
    'nonce_action' => metis_ajax_nonce_action( 'metis_settings_fetch_logging_viewer' ),
] );

metis_ajax_register_controller( 'metis_settings_clear_log', [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_settings_clear_log' ),
] );

metis_ajax_register_handler( 'metis_settings_save_section', function () {
    $section = metis_key_clean( (string) ( $_POST['settings_section'] ?? 'general' ) );
    $ctx = metis_settings_bootstrap( $section );

    if ( empty( $ctx['allowed'] ) ) {
        metis_runtime_send_json_error( [ 'message' => 'You do not have permission to manage settings.' ], 403 );
    }

    $errors = array_values( array_filter( array_map( 'strval', (array) ( $ctx['errors'] ?? [] ) ) ) );
    if ( ! empty( $errors ) ) {
        metis_runtime_send_json_error( [
            'message' => $errors[0],
            'errors' => $errors,
        ], 400 );
    }

    $saved = ! empty( $ctx['saved'] );
    $carddav_token_notice = is_array( $ctx['carddav_token_notice'] ?? null ) ? $ctx['carddav_token_notice'] : null;
    if ( $saved ) {
        CacheService::clearGroup( 'api' );
        CacheService::clearGroup( 'query' );
        CacheService::clearGroup( 'fragments' );
        CacheService::clearGroup( 'hermes' );
        CacheService::forget( 'configuration.compiled' );
        if ( function_exists( 'metis_standalone_compiled_config' ) ) {
            CacheService::set( 'configuration.compiled', metis_standalone_compiled_config( true ), 3600 );
        }
    }
    metis_runtime_send_json_success( [
        'message' => $saved ? 'Settings saved.' : 'No changes to save.',
        'saved' => $saved,
        'section' => $section,
        'redirect_url' => (string) ( $ctx['redirect_url'] ?? '' ),
        'carddav_token_notice' => $carddav_token_notice,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_settings_save_section' ),
] );

metis_ajax_register_handler( 'metis_settings_clear_cache', function () {
    CacheService::clearAll();
    metis_runtime_send_json_success( [ 'message' => 'Cache cleared.' ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_settings_clear_cache' ),
] );

metis_ajax_register_handler( 'metis_settings_clear_cache_group', function () {
    $group = metis_key_clean( (string) ( $_POST['group'] ?? '' ) );
    if ( $group === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'A cache group is required.' ], 400 );
    }

    CacheService::clearGroup( $group );
    metis_runtime_send_json_success( [ 'message' => 'Cache group cleared.' ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_settings_clear_cache_group' ),
] );

metis_ajax_register_handler( 'metis_settings_rebuild_cache', function () {
    $summary = CacheService::rebuildSystemCaches();
    metis_runtime_send_json_success( [
        'message' => 'System caches rebuilt.',
        'summary' => $summary,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_settings_rebuild_cache' ),
] );

metis_ajax_register_handler( 'metis_settings_fetch_logging_viewer', function () {
    $state = metis_settings_build_logging_viewer_state( $_POST );
    metis_runtime_send_json_success( [
        'entries' => array_values( (array) ( $state['logging_entries'] ?? [] ) ),
        'total_entries' => (int) ( $state['logging_total_entries'] ?? 0 ),
        'page' => (int) ( $state['logging_page'] ?? 1 ),
        'total_pages' => (int) ( $state['logging_total_pages'] ?? 1 ),
    ] );
}, [
    'module' => 'settings',
    'permission' => 'view',
] );

metis_ajax_register_handler( 'metis_settings_clear_log', function () {
    if ( class_exists( 'Metis_Logger' ) ) {
        Metis_Logger::clear();
    }

    $state = metis_settings_build_logging_viewer_state( $_POST );
    metis_runtime_send_json_success( [
        'message' => 'Log cleared.',
        'entries' => array_values( (array) ( $state['logging_entries'] ?? [] ) ),
        'total_entries' => (int) ( $state['logging_total_entries'] ?? 0 ),
        'page' => (int) ( $state['logging_page'] ?? 1 ),
        'total_pages' => (int) ( $state['logging_total_pages'] ?? 1 ),
    ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_drive_sync_now', function () {
    metis_settings_queue_operation_response( 'drive.sync', [], 'Drive sync queued.' );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_drive_sync_now' ),
] );

metis_ajax_register_handler( 'metis_backup_run_now', function () {
    metis_settings_queue_operation_response( 'backup.run', [], 'Backup queued.' );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_backup_run_now' ),
] );

metis_ajax_register_handler( 'metis_backup_restore_run', function () {
    $run_uuid = metis_text_clean( (string) ( $_POST['run_uuid'] ?? '' ) );
    if ( $run_uuid === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'Backup run ID is required.' ], 400 );
    }

    metis_settings_queue_operation_response( 'backup.restore', [ 'run_uuid' => $run_uuid ], sprintf( 'Restore from %s queued.', $run_uuid ) );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_backup_restore_run' ),
] );

metis_ajax_register_handler( 'metis_backup_history_snapshot', function () {
    $runs = function_exists( 'metis_backup_list_runs' ) ? (array) metis_backup_list_runs( 12 ) : [];
    $rows = [];
    foreach ( $runs as $run ) {
        if ( ! is_array( $run ) ) {
            continue;
        }
        $components = is_array( $run['components'] ?? null ) ? $run['components'] : [];
        $rows[] = [
            'run_uuid' => (string) ( $run['run_uuid'] ?? '' ),
            'status' => (string) ( $run['status'] ?? 'unknown' ),
            'environment' => (string) ( $run['environment'] ?? '' ),
            'completed_at' => (string) ( $run['completed_at'] ?? '' ),
            'drive_folder_id' => (string) ( $run['drive_run_folder_id'] ?? '' ),
            'full_link' => (string) ( $components['full']['drive_web_view_link'] ?? '' ),
            'last_error' => (string) ( $run['last_error'] ?? '' ),
        ];
    }

    metis_runtime_send_json_success( [
        'message' => 'Backup history loaded.',
        'runs' => $rows,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'view',
    'nonce_action' => metis_ajax_nonce_action( 'metis_backup_history_snapshot' ),
] );

metis_ajax_register_handler( 'metis_scheduler_run_task_now', function () {
    $task_slug = metis_key_clean( (string) ( $_POST['task_slug'] ?? '' ) );
    if ( $task_slug === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'Task slug is required.' ], 400 );
    }

    metis_settings_queue_operation_response( 'cron.task.run', [ 'task_slug' => $task_slug ], sprintf( 'Task "%s" queued.', $task_slug ) );
}, [
    'module' => 'settings',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_scheduler_status_snapshot', function () {
    $timezone = metis_settings_normalize_timezone( (string) Core_Settings_Service::get( 'timezone', Core_Settings_Service::get( 'site_timezone', 'UTC' ) ) );
    $date_format = (string) Core_Settings_Service::get( 'date_format', 'm/d/y' );
    $time_format = (string) Core_Settings_Service::get( 'time_format', 'g:i:s a' );
    $snapshot = metis_settings_build_scheduler_snapshot( $timezone, $date_format, $time_format );

    metis_runtime_send_json_success( [
        'message' => 'Scheduler snapshot loaded.',
        'queue_summary' => (array) ( $snapshot['queue_summary'] ?? [] ),
        'task_rows' => array_values( (array) ( $snapshot['system_cron_task_rows'] ?? [] ) ),
        'recent_jobs' => array_values( (array) ( $snapshot['system_cron_recent_jobs'] ?? [] ) ),
        'generated_at' => metis_current_time( 'mysql' ),
        'timezone' => $timezone,
        'date_format' => $date_format,
        'time_format' => $time_format,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'view',
    'nonce_action' => metis_ajax_nonce_action( 'metis_scheduler_status_snapshot' ),
] );

metis_ajax_register_handler( 'metis_settings_checker_snapshot', function () {
    $report = function_exists( 'metis_settings_build_performance_security_report' )
        ? metis_settings_build_performance_security_report()
        : [ 'generated_at' => metis_current_time( 'mysql' ), 'score' => 0, 'status_counts' => [ 'pass' => 0, 'warn' => 0, 'fail' => 0 ], 'checks' => [] ];
    $report = metis_settings_checker_recompute_security_offenses( $report );

    metis_runtime_send_json_success( [
        'message' => 'Checker report generated.',
        'report' => $report,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'view',
    'nonce_action' => metis_ajax_nonce_action( 'metis_settings_checker_snapshot' ),
] );

metis_ajax_register_handler( 'metis_settings_checker_remediate', function () {
    $actions = [];
    $warnings = [];
    $failed_actions = [];
    $add_failure = static function ( string $action, string $message ) use ( &$warnings, &$failed_actions ): void {
        $msg = trim( $message );
        if ( $msg === '' ) {
            return;
        }
        $warnings[] = $msg;
        $failed_actions[] = [
            'action' => $action,
            'message' => $msg,
        ];
    };
    $user_id = metis_current_user_id();
    $report = function_exists( 'metis_settings_build_performance_security_report' )
        ? metis_settings_build_performance_security_report()
        : [ 'checks' => [] ];

    $check_index = [];
    foreach ( (array) ( $report['checks'] ?? [] ) as $check ) {
        if ( ! is_array( $check ) ) {
            continue;
        }
        $id = (string) ( $check['id'] ?? '' );
        if ( $id === '' ) {
            continue;
        }
        $check_index[ $id ] = (string) ( $check['status'] ?? 'warn' );
    }
    $check_failing = static function ( string $id ) use ( $check_index ): bool {
        return isset( $check_index[ $id ] ) && $check_index[ $id ] !== 'pass';
    };

    $root_path = defined( 'METIS_ROOT' ) ? (string) METIS_ROOT : ( defined( 'METIS_PATH' ) ? (string) METIS_PATH : dirname( __DIR__, 3 ) );
    $parse_datetime = static function ( string $raw ): int {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return 0;
        }
        $ts = strtotime( $raw );
        return $ts !== false ? (int) $ts : 0;
    };

    try {
        if ( isset( $check_index['compiled_config_cache'] ) && $check_index['compiled_config_cache'] !== 'pass' ) {
            $summary = CacheService::rebuildSystemCaches();
            $actions[] = [ 'action' => 'cache.rebuild', 'result' => $summary ];
        }
    } catch ( Throwable $e ) {
        $add_failure( 'cache.rebuild', 'Cache rebuild failed: ' . $e->getMessage() );
    }

    if ( \Metis\Core\Application::has_service( 'operations' ) ) {
        try {
            if (
                ( isset( $check_index['queue_backlog'] ) && $check_index['queue_backlog'] !== 'pass' )
                || ( isset( $check_index['queue_failures'] ) && $check_index['queue_failures'] !== 'pass' )
            ) {
                $queued = metis_operations()->queueCommand( 'queue drain', $user_id );
                if ( ! empty( $queued['ok'] ) ) {
                    $actions[] = [ 'action' => 'queue.drain', 'result' => $queued ];
                } else {
                    $add_failure( 'queue.drain', 'Queue drain command could not be queued.' );
                }
            }
        } catch ( Throwable $e ) {
            $add_failure( 'queue.drain', 'Queue drain remediation failed: ' . $e->getMessage() );
        }

        try {
            if ( $check_failing( 'critical_cron_tasks' ) ) {
                $registered_tasks = Metis_Cron_Manager::registered_tasks();
                $critical_tasks = [ 'background_job_processing', 'cache_cleanup', 'integrity_scan' ];
                $disabled_tasks = Core_Settings_Service::get( 'system_cron_disabled_tasks', [] );
                $disabled_tasks = is_array( $disabled_tasks ) ? array_values( array_unique( array_map( 'metis_key_clean', $disabled_tasks ) ) ) : [];
                $before_count = count( $disabled_tasks );

                foreach ( $critical_tasks as $task_slug ) {
                    if ( ! isset( $registered_tasks[ $task_slug ] ) ) {
                        continue;
                    }
                    $disabled_tasks = array_values( array_filter( $disabled_tasks, static function ( string $slug ) use ( $task_slug ): bool {
                        return $slug !== $task_slug;
                    } ) );
                }

                if ( count( $disabled_tasks ) !== $before_count ) {
                    Core_Settings_Service::set( 'system_cron_disabled_tasks', $disabled_tasks, false );
                    $actions[] = [ 'action' => 'scheduler.enable_critical_tasks', 'result' => 'ok' ];
                }
            }
        } catch ( Throwable $e ) {
            $add_failure( 'scheduler.enable_critical_tasks', 'Critical-cron remediation failed: ' . $e->getMessage() );
        }

        try {
            if ( $check_failing( 'cron_execution_health' ) || $check_failing( 'critical_cron_tasks' ) ) {
                $queued_stale = [];
                $registered_tasks = Metis_Cron_Manager::registered_tasks();
                foreach ( $registered_tasks as $task_slug => $task_config ) {
                    if ( empty( $task_config['enabled'] ) ) {
                        continue;
                    }
                    $state = metis_get_option( 'metis_cron_task_state_' . $task_slug, [] );
                    $state = is_array( $state ) ? $state : [];
                    $last_finished = (string) ( $state['last_finished_at'] ?? '' );
                    $last_ts = $parse_datetime( $last_finished );
                    $interval = max( 60, (int) ( $task_config['interval'] ?? 300 ) );
                    $stale_threshold = max( 3600, $interval * 4 );
                    $is_stale = $last_ts < 1 || ( time() - $last_ts ) > $stale_threshold;
                    if ( ! $is_stale ) {
                        continue;
                    }

                    $queued = metis_operations()->queueOperation( 'cron.task.run', [ 'task_slug' => $task_slug ], [ 'created_by' => $user_id ] );
                    if ( ! empty( $queued['ok'] ) ) {
                        $queued_stale[] = $task_slug;
                    } else {
                        $add_failure( 'scheduler.run_stale_tasks', sprintf( 'Could not queue stale cron task "%s".', $task_slug ) );
                    }
                }

                if ( ! empty( $queued_stale ) ) {
                    $actions[] = [ 'action' => 'scheduler.run_stale_tasks', 'result' => [ 'tasks' => $queued_stale ] ];
                }
            }
        } catch ( Throwable $e ) {
            $add_failure( 'scheduler.run_stale_tasks', 'Stale-cron remediation failed: ' . $e->getMessage() );
        }

        try {
            if ( isset( $check_index['backup_recency'] ) && $check_index['backup_recency'] !== 'pass' ) {
                $queued = metis_operations()->queueOperation( 'backup.run', [], [ 'created_by' => $user_id ] );
                if ( ! empty( $queued['ok'] ) ) {
                    $actions[] = [ 'action' => 'backup.run', 'result' => $queued ];
                } else {
                    $add_failure( 'backup.run', 'Backup run could not be queued.' );
                }
            }
        } catch ( Throwable $e ) {
            $add_failure( 'backup.run', 'Backup remediation failed: ' . $e->getMessage() );
        }

        try {
            if ( isset( $check_index['release_update_checker'] ) && $check_index['release_update_checker'] !== 'pass' ) {
                $queued = metis_operations()->queueOperation( 'release.check', [], [ 'created_by' => $user_id ] );
                if ( ! empty( $queued['ok'] ) ) {
                    $actions[] = [ 'action' => 'release.check', 'result' => $queued ];
                } else {
                    $add_failure( 'release.check', 'Release check could not be queued.' );
                }
            }
        } catch ( Throwable $e ) {
            $add_failure( 'release.check', 'Release-check remediation failed: ' . $e->getMessage() );
        }
    }

    try {
        $runtime_permission_checks = [
            'fs_perm_storage',
            'fs_perm_storage_runtime',
            'fs_perm_storage_uploads',
        ];
        $needs_permission_fix = false;
        foreach ( $runtime_permission_checks as $check_id ) {
            if ( $check_failing( $check_id ) ) {
                $needs_permission_fix = true;
                break;
            }
        }

        if ( $needs_permission_fix ) {
            $runtime_dirs = [
                [ 'label' => 'storage', 'path' => $root_path . '/storage', 'mode' => 0775 ],
                [ 'label' => 'storage/runtime', 'path' => $root_path . '/storage/runtime', 'mode' => 0775 ],
                [ 'label' => 'storage/uploads', 'path' => $root_path . '/storage/uploads', 'mode' => 0775 ],
            ];

            $normalized = [];
            foreach ( $runtime_dirs as $dir ) {
                $path = (string) ( $dir['path'] ?? '' );
                $label = (string) ( $dir['label'] ?? $path );
                $mode = (int) ( $dir['mode'] ?? 0775 );
                if ( $path === '' ) {
                    continue;
                }

                if ( ! is_dir( $path ) ) {
                    if ( ! @mkdir( $path, $mode, true ) ) {
                        $add_failure( 'filesystem.normalize_runtime_permissions', sprintf( 'Could not create runtime directory "%s".', $label ) );
                        continue;
                    }
                }

                if ( ! @chmod( $path, $mode ) ) {
                    $add_failure( 'filesystem.normalize_runtime_permissions', sprintf( 'Could not change permissions for "%s".', $label ) );
                }

                clearstatcache( true, $path );
                if ( ! is_writable( $path ) ) {
                    $add_failure( 'filesystem.normalize_runtime_permissions', sprintf( 'Runtime directory "%s" is still not writable.', $label ) );
                    continue;
                }

                $normalized[] = $label;
            }

            if ( ! empty( $normalized ) ) {
                $actions[] = [ 'action' => 'filesystem.normalize_runtime_permissions', 'result' => [ 'paths' => $normalized, 'mode' => '0775' ] ];
            }
        }
    } catch ( Throwable $e ) {
        $add_failure( 'filesystem.normalize_runtime_permissions', 'Filesystem-permission remediation failed: ' . $e->getMessage() );
    }

    try {
        if ( isset( $check_index['cron_secret'] ) && $check_index['cron_secret'] !== 'pass' ) {
            $secret = bin2hex( random_bytes( 24 ) );
            Core_Settings_Service::set( 'system_cron_secret', $secret, false );
            $actions[] = [ 'action' => 'scheduler.secret.generate', 'result' => 'ok' ];
        }
    } catch ( Throwable $e ) {
        $add_failure( 'scheduler.secret.generate', 'Cron-secret remediation failed: ' . $e->getMessage() );
    }

    $refreshed_report = function_exists( 'metis_settings_build_performance_security_report' )
        ? metis_settings_build_performance_security_report()
        : [ 'generated_at' => metis_current_time( 'mysql' ), 'score' => 0, 'status_counts' => [ 'pass' => 0, 'warn' => 0, 'fail' => 0 ], 'kpis' => [], 'checks' => [] ];
    $refreshed_report = metis_settings_checker_recompute_security_offenses( $refreshed_report );

    metis_runtime_send_json_success( [
        'message' => empty( $actions ) ? 'No automatic remediation actions were available.' : 'Remediation actions executed.',
        'actions' => $actions,
        'warnings' => $warnings,
        'failed_actions' => $failed_actions,
        'report' => $refreshed_report,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_settings_checker_remediate' ),
] );

metis_ajax_register_handler( 'metis_settings_checker_permission_plan', function () {
    $root_path = defined( 'METIS_ROOT' ) ? (string) METIS_ROOT : ( defined( 'METIS_PATH' ) ? (string) METIS_PATH : dirname( __DIR__, 3 ) );
    $targets = [
        [ 'label' => 'config', 'path' => $root_path . '/config' ],
        [ 'label' => 'modules', 'path' => $root_path . '/modules' ],
        [ 'label' => 'src', 'path' => $root_path . '/src' ],
    ];

    $target_states = [];
    foreach ( $targets as $target ) {
        $path = (string) ( $target['path'] ?? '' );
        $exists = is_dir( $path ) || is_file( $path );
        $mode = 'unknown';
        $world_writable = false;
        if ( $exists ) {
            $perms = @fileperms( $path );
            if ( $perms !== false ) {
                $mode = substr( sprintf( '%o', $perms ), -4 );
                $world_writable = ( (int) $perms & 0x0002 ) !== 0;
            }
        }

        $target_states[] = [
            'label' => (string) ( $target['label'] ?? $path ),
            'path' => $path,
            'exists' => $exists,
            'current_mode' => $mode,
            'recommended_mode' => '0755 (directories), 0644 (files)',
            'is_world_writable' => $world_writable,
        ];
    }

    $quote = static function ( string $path ): string {
        return escapeshellarg( $path );
    };
    $commands = [];
    foreach ( $targets as $target ) {
        $path = (string) ( $target['path'] ?? '' );
        if ( $path === '' ) {
            continue;
        }
        $qp = $quote( $path );
        $commands[] = 'find ' . $qp . ' -type d -exec chmod 0755 {} +';
        $commands[] = 'find ' . $qp . ' -type f -exec chmod 0644 {} +';
        $commands[] = 'chmod -R o-w ' . $qp;
    }

    $ownership_hint = 'Optional: set ownership if needed, e.g. chown -R <app-user>:<app-group> <path>.';
    if ( function_exists( 'fileowner' ) && function_exists( 'filegroup' ) && is_dir( $root_path ) ) {
        $uid = @fileowner( $root_path );
        $gid = @filegroup( $root_path );
        if ( $uid !== false && $gid !== false ) {
            $owner_name = is_numeric( $uid ) ? (string) $uid : '';
            $group_name = is_numeric( $gid ) ? (string) $gid : '';
            if ( function_exists( 'posix_getpwuid' ) ) {
                $owner = @posix_getpwuid( (int) $uid );
                if ( is_array( $owner ) && ! empty( $owner['name'] ) ) {
                    $owner_name = (string) $owner['name'];
                }
            }
            if ( function_exists( 'posix_getgrgid' ) ) {
                $group = @posix_getgrgid( (int) $gid );
                if ( is_array( $group ) && ! empty( $group['name'] ) ) {
                    $group_name = (string) $group['name'];
                }
            }
            if ( $owner_name !== '' && $group_name !== '' ) {
                $ownership_hint = sprintf( 'Optional: set ownership if needed, e.g. chown -R %s:%s <path>.', $owner_name, $group_name );
            }
        }
    }

    metis_runtime_send_json_success( [
        'message' => 'Generated manual permission plan for sensitive directories.',
        'generated_at' => metis_current_time( 'mysql' ),
        'targets' => $target_states,
        'commands' => $commands,
        'notes' => [
            'These commands are for manual review and execution by a system administrator.',
            'Do not run blindly in production. Validate ownership and deployment model first.',
            $ownership_hint,
        ],
    ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_settings_checker_permission_plan' ),
] );

metis_ajax_register_handler( 'metis_scheduler_build_integrity_baseline', function () {
    metis_settings_queue_operation_response( 'integrity.baseline', [], 'Integrity baseline build queued.' );
}, [
    'module' => 'settings',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_release_check_updates', function () {
    if ( ! function_exists( 'metis_release_status' ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Release manager is not available.' ], 503 );
    }

    $status = metis_release_status( true );
    $module_catalog = function_exists( 'metis_github_update_service' )
        ? metis_github_update_service()->moduleCatalog( true )
        : [];

    metis_runtime_send_json_success( [
        'message' => 'Release and module metadata refreshed.',
        'release_status' => $status,
        'module_catalog' => $module_catalog,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'view',
] );

metis_ajax_register_handler( 'metis_release_apply', function () {
    $tag = metis_text_clean( (string) ( $_POST['tag'] ?? '' ) );
    if ( $tag === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'A release tag is required.' ], 400 );
    }

    metis_settings_queue_operation_response( 'release.apply', [ 'tag' => $tag ], sprintf( 'Release %s queued.', $tag ) );
}, [
    'module' => 'settings',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_release_apply_now', function () {
    if ( ! function_exists( 'metis_release_apply_with_progress' ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Release manager is not available.' ], 503 );
    }

    $tag = metis_text_clean( (string) ( $_POST['tag'] ?? '' ) );
    $token = metis_settings_release_progress_token( (string) ( $_POST['progress_token'] ?? '' ) );
    if ( $tag === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'A release tag is required.' ], 400 );
    }
    if ( $token === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'A progress token is required.' ], 400 );
    }

    @ignore_user_abort( true );
    @set_time_limit( 0 );

    $write_progress = static function ( array $progress ) use ( $token, $tag ): void {
        metis_settings_write_release_progress( $token, [
            'tag' => $tag,
            'stage' => (string) ( $progress['stage'] ?? 'running' ),
            'message' => (string) ( $progress['message'] ?? 'Running update.' ),
            'percent' => (int) ( $progress['percent'] ?? 0 ),
            'context' => is_array( $progress['context'] ?? null ) ? $progress['context'] : [],
            'done' => false,
        ] );
    };

    $write_progress( [
        'stage' => 'start',
        'message' => 'Starting release update.',
        'percent' => 1,
    ] );

    try {
        $result = metis_release_apply_with_progress( $tag, 'settings_direct', $write_progress );
    } catch ( Throwable $throwable ) {
        $result = [
            'ok' => false,
            'status' => 'exception',
            'message' => $throwable->getMessage(),
            'exception' => get_class( $throwable ),
        ];
    }

    metis_settings_write_release_progress( $token, [
        'tag' => $tag,
        'stage' => ! empty( $result['ok'] ) ? 'complete' : 'failed',
        'message' => (string) ( $result['message'] ?? ( ! empty( $result['ok'] ) ? 'Release update completed.' : 'Release update failed.' ) ),
        'percent' => 100,
        'done' => true,
        'result' => $result,
    ] );

    metis_runtime_send_json_success( [
        'message' => (string) ( $result['message'] ?? ( ! empty( $result['ok'] ) ? 'Release update completed.' : 'Release update failed.' ) ),
        'release_result' => $result,
        'progress_token' => $token,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_release_apply_now' ),
] );

metis_ajax_register_handler( 'metis_release_apply_progress', function () {
    $token = metis_settings_release_progress_token( (string) ( $_POST['progress_token'] ?? '' ) );
    if ( $token === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'A progress token is required.' ], 400 );
    }

    $path = metis_settings_release_progress_path( $token );
    $progress = [];
    if ( is_file( $path ) ) {
        $raw = file_get_contents( $path );
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : [];
        $progress = is_array( $decoded ) ? $decoded : [];
    }

    metis_runtime_send_json_success( [
        'message' => 'Release progress loaded.',
        'progress' => $progress,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_release_apply_progress' ),
    'rate_limit' => 120,
    'rate_window_seconds' => 60,
] );

metis_ajax_register_handler( 'metis_release_rollback', function () {
    metis_settings_queue_operation_response( 'release.rollback', [], 'Release rollback queued.' );
}, [
    'module' => 'settings',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_operations_queue_command', function () {
    $command = trim( (string) ( $_POST['command'] ?? '' ) );
    if ( $command === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'A command is required.' ], 400 );
    }

    $queued = metis_operations()->queueCommand( $command, metis_current_user_id() );
    if ( empty( $queued['ok'] ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Command is not allowed.' ], 400 );
    }

    metis_runtime_send_json_success( [
        'message' => sprintf( 'Queued command: %s', (string) ( $queued['normalized_command'] ?? $command ) ),
        'queued' => $queued,
    ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
] );

metis_ajax_register_handler( 'metis_scheduler_update_task_settings', function () {
    $task_slug = metis_key_clean( (string) ( $_POST['task_slug'] ?? '' ) );
    if ( $task_slug === '' ) {
        metis_runtime_send_json_error( [ 'message' => 'Task slug is required.' ], 400 );
    }

    $registered = Metis_Cron_Manager::registered_tasks();
    if ( ! isset( $registered[ $task_slug ] ) ) {
        metis_runtime_send_json_error( [ 'message' => 'Task is not registered.' ], 404 );
    }

    $disabled_tasks = Core_Settings_Service::get( 'system_cron_disabled_tasks', [] );
    $disabled_tasks = is_array( $disabled_tasks ) ? array_values( array_unique( array_map( 'metis_key_clean', $disabled_tasks ) ) ) : [];

    if ( isset( $_POST['enabled'] ) ) {
        $enabled = (string) $_POST['enabled'] === '1';
        if ( $enabled ) {
            $disabled_tasks = array_values( array_filter( $disabled_tasks, static function ( string $slug ) use ( $task_slug ): bool {
                return $slug !== $task_slug;
            } ) );
        } elseif ( ! in_array( $task_slug, $disabled_tasks, true ) ) {
            $disabled_tasks[] = $task_slug;
        }

        Core_Settings_Service::set( 'system_cron_disabled_tasks', $disabled_tasks, false );
    }

    if ( isset( $_POST['interval_minutes'] ) ) {
        $interval_minutes = (int) $_POST['interval_minutes'];
        if ( $interval_minutes < 1 ) {
            metis_runtime_send_json_error( [ 'message' => 'Cadence must be at least 1 minute.' ], 400 );
        }

        $overrides = Core_Settings_Service::get( 'system_cron_task_intervals', [] );
        $overrides = is_array( $overrides ) ? $overrides : [];
        $default_interval = max( 60, (int) ( $registered[ $task_slug ]['default_interval'] ?? $registered[ $task_slug ]['interval'] ?? 300 ) );
        $override_seconds = $interval_minutes * MINUTE_IN_SECONDS;

        if ( $override_seconds === $default_interval ) {
            unset( $overrides[ $task_slug ] );
        } else {
            $overrides[ $task_slug ] = $override_seconds;
        }

        Core_Settings_Service::set( 'system_cron_task_intervals', $overrides, false );
    }

    $refreshed = Metis_Cron_Manager::registered_tasks();
    $task = $refreshed[ $task_slug ] ?? $registered[ $task_slug ];

    metis_runtime_send_json_success( [
        'message' => 'Task settings updated.',
        'task' => [
            'slug' => $task_slug,
            'enabled' => ! empty( $task['enabled'] ),
            'interval_minutes' => max( 1, (int) ceil( ( (int) ( $task['interval'] ?? 300 ) ) / MINUTE_IN_SECONDS ) ),
        ],
    ] );
}, [
    'module' => 'settings',
    'permission' => 'edit',
    'nonce_action' => metis_ajax_nonce_action( 'metis_scheduler_update_task_settings' ),
] );
