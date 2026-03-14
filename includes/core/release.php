<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

require_once __DIR__ . '/autoload.php';

function metis_release_manager(): \Metis\Release\ReleaseManager {
    metis_register_core_services();
    return \Metis\Core\Application::service( 'release' );
}

function metis_release_status( bool $force_refresh = false ): array {
    return metis_release_manager()->status( $force_refresh );
}

function metis_release_check_for_updates( bool $force_refresh = false, string $trigger = 'manual' ): array {
    return metis_release_manager()->checkForUpdates( $force_refresh, $trigger );
}

function metis_release_apply( string $tag, string $trigger = 'manual' ): array {
    return metis_release_manager()->applyRelease( $tag, $trigger );
}

function metis_release_rollback( string $trigger = 'manual' ): array {
    return metis_release_manager()->rollback( $trigger );
}

if ( function_exists( 'metis_add_action' ) ) {
    metis_add_action(
        'init',
        static function (): void {
            metis_release_manager()->ensureRuntime();
        },
        5
    );
}
