<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

require_once __DIR__ . '/Autoload.php';

function metis_release_manager(): \Metis\Release\ReleaseManager {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'release' );
}

function metis_github_update_service(): \Metis\Core\Services\GitHubUpdateService {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'github_update' );
}

function metis_update_service(): \Metis\Core\Services\UpdateService {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'updates' );
}

function metis_self_healing_service(): \Metis\Core\Services\SelfHealingService {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'self_healing' );
}

function metis_release_status( bool $force_refresh = false ): array {
    return metis_release_manager()->status( $force_refresh );
}

function metis_release_status_snapshot(): array {
    return metis_release_manager()->statusSnapshot();
}

function metis_release_check_for_updates( bool $force_refresh = false, string $trigger = 'manual' ): array {
    try {
        $result = metis_release_manager()->checkForUpdates( $force_refresh, $trigger );
        $result['trigger'] = $trigger;
        return $result;
    } catch ( \RuntimeException $e ) {
        $message = $e->getMessage();
        if (
            $message === 'Release manager execution is disabled.'
            || ( $trigger === 'system_cron' && $message === 'System administrator access is required.' )
        ) {
            return [
                'ok' => true,
                'status' => 'skipped',
                'message' => $message,
                'trigger' => $trigger,
            ];
        }
        throw $e;
    }
}

function metis_release_auto_update_bool( mixed $value, bool $default = false ): bool {
    if ( is_bool( $value ) ) {
        return $value;
    }

    if ( is_int( $value ) || is_float( $value ) ) {
        return (int) $value === 1;
    }

    if ( is_string( $value ) ) {
        $normalized = strtolower( trim( $value ) );
        if ( in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true ) ) {
            return true;
        }
        if ( in_array( $normalized, [ '0', 'false', 'no', 'off', '' ], true ) ) {
            return false;
        }
    }

    return $default;
}

function metis_release_auto_update_enabled(): bool {
    if ( class_exists( 'Core_Settings_Service' ) ) {
        return metis_release_auto_update_bool( \Core_Settings_Service::get( 'release_auto_update_enabled', true ), true );
    }

    return true;
}

function metis_release_auto_update_max_level(): string {
    $level = class_exists( 'Core_Settings_Service' )
        ? (string) \Core_Settings_Service::get( 'release_auto_update_max_level', 'patch' )
        : 'patch';
    $level = strtolower( trim( $level ) );

    return in_array( $level, [ 'patch', 'minor', 'major' ], true ) ? $level : 'patch';
}

function metis_release_auto_update_version_parts( string $version ): ?array {
    $version = trim( $version );
    if ( preg_match( '/v?(\d+(?:\.\d+){1,3})/i', $version, $matches ) !== 1 ) {
        return null;
    }

    $parts = array_map( 'intval', explode( '.', (string) $matches[1] ) );
    while ( count( $parts ) < 4 ) {
        $parts[] = 0;
    }

    return $parts;
}

function metis_release_auto_update_change_level( string $current_version, string $latest_version ): string {
    $current = metis_release_auto_update_version_parts( $current_version );
    $latest = metis_release_auto_update_version_parts( $latest_version );
    if ( $current === null || $latest === null ) {
        return 'unknown';
    }

    if ( version_compare( implode( '.', $latest ), implode( '.', $current ), '<=' ) ) {
        return 'none';
    }

    if ( $latest[0] > $current[0] ) {
        return 'major';
    }

    if ( $latest[1] > $current[1] ) {
        return 'minor';
    }

    return 'patch';
}

function metis_release_auto_update_level_allowed( string $level, string $max_level ): bool {
    if ( $level === 'none' ) {
        return true;
    }

    $weight = [
        'patch' => 1,
        'minor' => 2,
        'major' => 3,
    ];

    return isset( $weight[ $level ], $weight[ $max_level ] ) && $weight[ $level ] <= $weight[ $max_level ];
}

function metis_release_auto_update( string $trigger = 'system_cron' ): array {
    if ( ! metis_release_auto_update_enabled() ) {
        return [
            'ok' => true,
            'status' => 'skipped',
            'message' => 'Release auto-update is disabled.',
            'trigger' => $trigger,
        ];
    }

    $status = metis_release_check_for_updates( true, $trigger );
    $release_status = strtolower( trim( (string) ( $status['status'] ?? '' ) ) );
    if ( empty( $status['ok'] ) || in_array( $release_status, [ 'skipped', 'failed', 'error' ], true ) ) {
        return [
            'ok' => ! empty( $status['ok'] ),
            'status' => $release_status === 'skipped' ? 'skipped' : 'failed',
            'message' => (string) ( $status['message'] ?? 'Release update check did not complete.' ),
            'trigger' => $trigger,
            'release_status' => $status,
        ];
    }

    if ( empty( $status['update_available'] ) ) {
        return [
            'ok' => true,
            'status' => 'current',
            'message' => 'No trusted release update is available.',
            'trigger' => $trigger,
            'release_status' => $status,
        ];
    }

    $latest = is_array( $status['latest'] ?? null ) ? (array) $status['latest'] : [];
    $current = is_array( $status['current'] ?? null ) ? (array) $status['current'] : [];
    $tag = trim( (string) ( $latest['tag'] ?? '' ) );
    if ( $tag === '' || empty( $latest['trusted'] ) ) {
        return [
            'ok' => true,
            'status' => 'skipped',
            'message' => 'Available release is not trusted for automatic application.',
            'trigger' => $trigger,
            'release_status' => $status,
        ];
    }

    $current_version = (string) ( $current['version'] ?? $status['installed_version'] ?? '' );
    $latest_version = (string) ( $latest['version'] ?? '' );
    $change_level = metis_release_auto_update_change_level( $current_version, $latest_version );
    $max_level = metis_release_auto_update_max_level();
    if ( $change_level === 'none' ) {
        return [
            'ok' => true,
            'status' => 'current',
            'message' => 'Trusted release metadata did not indicate a newer version.',
            'trigger' => $trigger,
            'tag' => $tag,
            'release_status' => $status,
        ];
    }

    if ( $change_level === 'unknown' || ! metis_release_auto_update_level_allowed( $change_level, $max_level ) ) {
        return [
            'ok' => true,
            'status' => 'policy_blocked',
            'message' => sprintf( 'Release %s is a %s update; auto-update policy allows up to %s.', $tag, $change_level, $max_level ),
            'trigger' => $trigger,
            'tag' => $tag,
            'change_level' => $change_level,
            'max_level' => $max_level,
        ];
    }

    if ( ! function_exists( 'metis_operations' ) ) {
        return [
            'ok' => false,
            'status' => 'operations_unavailable',
            'message' => 'Operations service is not available for governed release application.',
            'trigger' => $trigger,
            'tag' => $tag,
        ];
    }

    $queued = metis_operations()->queueOperation(
        'release.apply',
        [ 'tag' => $tag ],
        [
            'created_by' => 0,
            'dedupe_key' => 'operation:release.apply:auto:' . strtolower( $tag ),
        ]
    );

    if ( empty( $queued['ok'] ) ) {
        return [
            'ok' => false,
            'status' => 'queue_failed',
            'message' => (string) ( $queued['message'] ?? 'Release auto-update could not queue the release apply operation.' ),
            'trigger' => $trigger,
            'tag' => $tag,
            'queued' => $queued,
        ];
    }

    if ( class_exists( 'Metis_Logger' ) ) {
        \Metis_Logger::info( 'Release auto-update queued trusted release application', [
            'trigger' => $trigger,
            'tag' => $tag,
            'current_version' => $current_version,
            'latest_version' => $latest_version,
            'change_level' => $change_level,
            'max_level' => $max_level,
            'duplicate' => ! empty( $queued['duplicate'] ),
        ] );
    }
    if ( function_exists( 'metis_audit_log_security' ) ) {
        \metis_audit_log_security( 'release_auto_update_queued', [
            'module' => 'release',
            'severity' => 'info',
            'outcome' => ! empty( $queued['duplicate'] ) ? 'duplicate' : 'queued',
            'resource' => [
                'type' => 'release',
                'id' => $tag,
            ],
            'context' => [
                'trigger' => $trigger,
                'current_version' => $current_version,
                'latest_version' => $latest_version,
                'change_level' => $change_level,
                'max_level' => $max_level,
            ],
        ] );
    }

    return [
        'ok' => true,
        'status' => ! empty( $queued['duplicate'] ) ? 'duplicate' : 'queued',
        'message' => ! empty( $queued['duplicate'] )
            ? sprintf( 'Release %s auto-update is already queued.', $tag )
            : sprintf( 'Release %s auto-update queued.', $tag ),
        'trigger' => $trigger,
        'tag' => $tag,
        'change_level' => $change_level,
        'max_level' => $max_level,
        'queued' => $queued,
    ];
}

function metis_release_apply( string $tag, string $trigger = 'manual' ): array {
    return metis_release_manager()->applyRelease( $tag, $trigger );
}

function metis_release_apply_with_progress( string $tag, string $trigger, callable $progress ): array {
    $manager = metis_release_manager();
    $manager->setProgressReporter( $progress );
    try {
        return $manager->applyRelease( $tag, $trigger );
    } finally {
        $manager->setProgressReporter( null );
    }
}

function metis_release_rollback( string $trigger = 'manual' ): array {
    return metis_release_manager()->rollback( $trigger );
}

if ( function_exists( 'metis_on' ) ) {
    metis_on(
        'init',
        static function (): void {
            metis_release_manager()->ensureRuntime();
        },
        5
    );
}
