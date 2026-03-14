<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/../templates/_settings_bootstrap.php';

metis_add_action( 'wp_ajax_metis_settings_save_section', function () {
    if ( ! metis_user_logged_in() ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    $section = sanitize_key( (string) ( $_POST['settings_section'] ?? 'general' ) );
    $ctx = metis_settings_bootstrap( $section );

    if ( empty( $ctx['allowed'] ) ) {
        metis_send_json_error( [ 'message' => 'You do not have permission to manage settings.' ], 403 );
    }

    $errors = array_values( array_filter( array_map( 'strval', (array) ( $ctx['errors'] ?? [] ) ) ) );
    if ( ! empty( $errors ) ) {
        metis_send_json_error( [
            'message' => $errors[0],
            'errors' => $errors,
        ], 400 );
    }

    $saved = ! empty( $ctx['saved'] );
    $carddav_token_notice = is_array( $ctx['carddav_token_notice'] ?? null ) ? $ctx['carddav_token_notice'] : null;
    metis_send_json_success( [
        'message' => $saved ? 'Settings saved.' : 'No changes to save.',
        'saved' => $saved,
        'section' => $section,
        'redirect_url' => (string) ( $ctx['redirect_url'] ?? '' ),
        'carddav_token_notice' => $carddav_token_notice,
    ] );
} );

metis_add_action( 'wp_ajax_metis_drive_sync_now', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    if ( ! function_exists( 'metis_drive_sync_all_configured_drives' ) ) {
        metis_send_json_error( [ 'message' => 'Drive sync is not available.' ], 500 );
    }

    $result = metis_drive_sync_all_configured_drives();
    $drives = (array) ( $result['drives'] ?? [] );
    $ok_count = 0;
    $error_count = 0;

    foreach ( $drives as $drive_result ) {
        if ( ! empty( $drive_result['ok'] ) ) {
            $ok_count++;
        } else {
            $error_count++;
        }
    }

    metis_send_json_success( [
        'message' => $error_count > 0
            ? sprintf( 'Drive sync finished with %d success and %d error.', $ok_count, $error_count )
            : sprintf( 'Drive sync finished for %d drive%s.', $ok_count, $ok_count === 1 ? '' : 's' ),
        'result' => $result,
        'ok_count' => $ok_count,
        'error_count' => $error_count,
    ] );
} );

metis_add_action( 'wp_ajax_metis_backup_run_now', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    if ( ! function_exists( 'metis_backup_run_now' ) ) {
        metis_send_json_error( [ 'message' => 'Backup service is not available.' ], 500 );
    }

    $result = metis_backup_run_now( 'settings_backup_run_now' );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( [ 'message' => (string) ( $result['error'] ?? 'Backup failed.' ), 'result' => $result ], 500 );
    }

    metis_send_json_success( [
        'message' => sprintf( 'Backup %s completed.', (string) ( $result['run_uuid'] ?? '' ) ),
        'result' => $result,
    ] );
} );

metis_add_action( 'wp_ajax_metis_backup_restore_run', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    if ( ! function_exists( 'metis_backup_restore_run' ) ) {
        metis_send_json_error( [ 'message' => 'Backup restore service is not available.' ], 500 );
    }

    $run_uuid = sanitize_text_field( (string) ( $_POST['run_uuid'] ?? '' ) );
    if ( $run_uuid === '' ) {
        metis_send_json_error( [ 'message' => 'Backup run ID is required.' ], 400 );
    }

    $result = metis_backup_restore_run( $run_uuid );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( [ 'message' => (string) ( $result['error'] ?? 'Restore failed.' ), 'result' => $result ], 500 );
    }

    metis_send_json_success( [
        'message' => sprintf( 'Restore from %s completed.', $run_uuid ),
        'result' => $result,
    ] );
} );

metis_add_action( 'wp_ajax_metis_scheduler_run_task_now', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    $task_slug = sanitize_key( (string) ( $_POST['task_slug'] ?? '' ) );
    if ( $task_slug === '' ) {
        metis_send_json_error( [ 'message' => 'Task slug is required.' ], 400 );
    }

    $result = Metis_Cron_Manager::run_task_now( $task_slug, 'settings_run_now' );
    $task_result = is_array( $result['results'][ $task_slug ] ?? null ) ? $result['results'][ $task_slug ] : [];
    $status = (string) ( $task_result['status'] ?? 'unknown' );

    if ( $status === 'failed' ) {
        metis_send_json_error( [
            'message' => (string) ( $task_result['message'] ?? 'Task failed.' ),
            'result' => $result,
        ], 500 );
    }

    metis_send_json_success( [
        'message' => sprintf( 'Task "%s" finished with status: %s.', $task_slug, $status ),
        'result' => $result,
    ] );
} );

metis_add_action( 'wp_ajax_metis_scheduler_build_integrity_baseline', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    $built = Metis_Integrity_Manager::initialize_baseline( 'settings_scheduler' );
    if ( ! $built ) {
        metis_send_json_error( [ 'message' => 'Integrity baseline could not be built.' ], 500 );
    }

    metis_send_json_success( [
        'message' => 'Integrity baseline built successfully.',
    ] );
} );

metis_add_action( 'wp_ajax_metis_release_check_updates', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    if ( ! function_exists( 'metis_release_check_for_updates' ) ) {
        metis_send_json_error( [ 'message' => 'Release manager is not available.' ], 500 );
    }

    $result = metis_release_check_for_updates( true, 'settings_refresh' );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( [ 'message' => 'Release metadata could not be refreshed.', 'result' => $result ], 500 );
    }

    $latest = (array) ( $result['latest'] ?? [] );
    $message = ! empty( $result['update_available'] ) && ! empty( $latest['tag'] )
        ? sprintf( 'Trusted release %s is available.', (string) $latest['tag'] )
        : 'Release metadata refreshed.';

    metis_send_json_success( [
        'message' => $message,
        'result' => $result,
    ] );
} );

metis_add_action( 'wp_ajax_metis_release_apply', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    if ( ! function_exists( 'metis_release_apply' ) ) {
        metis_send_json_error( [ 'message' => 'Release manager is not available.' ], 500 );
    }

    $tag = sanitize_text_field( (string) ( $_POST['tag'] ?? '' ) );
    if ( $tag === '' ) {
        metis_send_json_error( [ 'message' => 'A release tag is required.' ], 400 );
    }

    $result = metis_release_apply( $tag, 'settings_apply' );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Release update failed.' ), 'result' => $result ], 500 );
    }

    metis_send_json_success( [
        'message' => sprintf( 'Release %s applied.', $tag ),
        'result' => $result,
    ] );
} );

metis_add_action( 'wp_ajax_metis_release_rollback', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    if ( ! function_exists( 'metis_release_rollback' ) ) {
        metis_send_json_error( [ 'message' => 'Release manager is not available.' ], 500 );
    }

    $result = metis_release_rollback( 'settings_rollback' );
    if ( empty( $result['ok'] ) ) {
        metis_send_json_error( [ 'message' => (string) ( $result['message'] ?? 'Rollback failed.' ), 'result' => $result ], 500 );
    }

    metis_send_json_success( [
        'message' => (string) ( $result['message'] ?? 'Rollback completed.' ),
        'result' => $result,
    ] );
} );

metis_add_action( 'wp_ajax_metis_scheduler_update_task_settings', function () {
    if ( ! metis_user_logged_in() || ! metis_current_user_can( 'manage_options' ) ) {
        metis_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }

    $task_slug = sanitize_key( (string) ( $_POST['task_slug'] ?? '' ) );
    if ( $task_slug === '' ) {
        metis_send_json_error( [ 'message' => 'Task slug is required.' ], 400 );
    }

    $registered = Metis_Cron_Manager::registered_tasks();
    if ( ! isset( $registered[ $task_slug ] ) ) {
        metis_send_json_error( [ 'message' => 'Task is not registered.' ], 404 );
    }

    $disabled_tasks = Core_Settings_Service::get( 'system_cron_disabled_tasks', [] );
    $disabled_tasks = is_array( $disabled_tasks ) ? array_values( array_unique( array_map( 'sanitize_key', $disabled_tasks ) ) ) : [];

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
            metis_send_json_error( [ 'message' => 'Cadence must be at least 1 minute.' ], 400 );
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

    metis_send_json_success( [
        'message' => 'Task settings updated.',
        'task' => [
            'slug' => $task_slug,
            'enabled' => ! empty( $task['enabled'] ),
            'interval_minutes' => max( 1, (int) ceil( ( (int) ( $task['interval'] ?? 300 ) ) / MINUTE_IN_SECONDS ) ),
        ],
    ] );
} );
