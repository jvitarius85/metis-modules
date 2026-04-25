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
