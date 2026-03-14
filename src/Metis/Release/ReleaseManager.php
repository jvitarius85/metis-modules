<?php
declare(strict_types=1);

namespace Metis\Release;

final class ReleaseManager {
    private const STORAGE_DIR = '.metis-release';
    private const CACHE_DIR = 'cache';
    private const HISTORY_DIR = 'history';
    private const CACHE_FILE = 'release-cache.json';
    private const STATE_FILE = 'state.json';
    private const HISTORY_FILE = 'release-history.json';

    public function ensureRuntime(): void {
        $this->ensureStorageDirectories();
        $this->syncInstalledVersion();
    }

    public function status( bool $force_refresh = false ): array {
        $this->ensureRuntime();

        $releases_payload = $this->refreshTrustedReleases( $force_refresh, 'status' );
        $repository = $this->repositoryState( true );
        $state = $this->readState();
        $current = $this->currentRelease( $repository, $state, $releases_payload['releases'] ?? [] );
        $latest = $this->latestRelease( $releases_payload['releases'] ?? [] );

        $status = [
            'ok' => true,
            'status' => empty( $releases_payload['releases'] ) ? 'no_releases' : 'ready',
            'installed_version' => (string) ( $state['installed_version'] ?? ( \defined( 'METIS_VERSION' ) ? (string) \METIS_VERSION : '' ) ),
            'installed_tag' => (string) ( $state['installed_tag'] ?? '' ),
            'current' => $current,
            'latest' => $latest,
            'update_available' => $latest !== null && $current !== null
                ? version_compare( (string) $latest['version'], (string) $current['version'], '>' )
                : ( $latest !== null && $current === null ),
            'repository' => [
                'available' => $repository !== null,
                'clean' => $repository !== null ? empty( $repository['dirty'] ) : false,
                'head' => $repository['commit'] ?? '',
                'tag' => $repository['exact_tag'] ?? '',
                'remote' => $repository['remote'] ?? '',
            ],
            'trusted_releases' => $releases_payload['releases'] ?? [],
            'last_checked_at' => (string) ( $releases_payload['checked_at'] ?? '' ),
            'cache_age_seconds' => (int) ( $releases_payload['cache_age_seconds'] ?? 0 ),
            'remote_status' => (string) ( $releases_payload['remote_status'] ?? 'unavailable' ),
            'remote_error' => (string) ( $releases_payload['remote_error'] ?? '' ),
            'state' => $state,
            'history' => $this->readHistory(),
        ];

        if ( $repository === null ) {
            $status['status'] = 'git_unavailable';
            $status['ok'] = false;
        }

        $this->persistState(
            array_merge(
                $state,
                [
                    'installed_version' => $status['installed_version'],
                    'installed_tag' => (string) ( $current['tag'] ?? ( $state['installed_tag'] ?? '' ) ),
                    'installed_commit' => (string) ( $repository['commit'] ?? ( $state['installed_commit'] ?? '' ) ),
                    'last_checked_at' => $status['last_checked_at'],
                    'last_available_tag' => (string) ( $latest['tag'] ?? '' ),
                    'last_available_version' => (string) ( $latest['version'] ?? '' ),
                ]
            )
        );

        return $status;
    }

    public function checkForUpdates( bool $force_refresh = false, string $trigger = 'manual' ): array {
        $status = $this->status( $force_refresh );
        $status['trigger'] = $trigger;

        if ( \class_exists( 'Metis_Logger' ) ) {
            \Metis_Logger::info( 'Release update check completed', [
                'trigger' => $trigger,
                'status' => $status['status'],
                'current' => (string) ( $status['current']['tag'] ?? '' ),
                'latest' => (string) ( $status['latest']['tag'] ?? '' ),
                'update_available' => ! empty( $status['update_available'] ),
            ] );
        }

        return $status;
    }

    public function applyRelease( string $tag, string $trigger = 'manual' ): array {
        $tag = $this->normalizeTag( $tag );
        if ( $tag === '' ) {
            return [
                'ok' => false,
                'status' => 'invalid_tag',
                'message' => 'A trusted release tag is required.',
            ];
        }

        $this->ensureRuntime();

        $releases_payload = $this->refreshTrustedReleases( true, 'apply' );
        $release = $this->findReleaseByTag( $tag, $releases_payload['releases'] ?? [] );
        if ( $release === null ) {
            return [
                'ok' => false,
                'status' => 'untrusted_release',
                'message' => 'The requested release tag is not in the trusted release list.',
            ];
        }

        $repository = $this->repositoryState( true );
        if ( $repository === null ) {
            return [
                'ok' => false,
                'status' => 'git_unavailable',
                'message' => 'Git repository state could not be resolved.',
            ];
        }

        if ( ! empty( $repository['dirty'] ) ) {
            return [
                'ok' => false,
                'status' => 'dirty_worktree',
                'message' => 'Tracked repository changes must be committed or reverted before an update can be applied.',
                'repository' => $repository,
            ];
        }

        if ( (string) ( $repository['exact_tag'] ?? '' ) === $tag ) {
            return [
                'ok' => true,
                'status' => 'already_installed',
                'message' => 'The requested release is already installed.',
                'release' => $release,
                'repository' => $repository,
            ];
        }

        $integrity = $this->preflightIntegrityCheck( 'pre_update' );
        if ( ! empty( $integrity['blocked'] ) ) {
            return [
                'ok' => false,
                'status' => 'integrity_blocked',
                'message' => 'Integrity verification must pass before an update can be applied.',
                'integrity' => $integrity,
            ];
        }

        $backup = $this->runPreUpdateBackup( $trigger );
        if ( empty( $backup['ok'] ) ) {
            return [
                'ok' => false,
                'status' => 'backup_failed',
                'message' => (string) ( $backup['error'] ?? 'Pre-update backup failed.' ),
                'backup' => $backup,
            ];
        }

        $previous = [
            'tag' => (string) ( $repository['exact_tag'] ?? '' ),
            'commit' => (string) ( $repository['commit'] ?? '' ),
            'version' => (string) ( $this->readState()['installed_version'] ?? ( \defined( 'METIS_VERSION' ) ? (string) \METIS_VERSION : '' ) ),
        ];

        if ( ! $this->ensureLocalTagAvailable( $tag, $repository ) ) {
            return [
                'ok' => false,
                'status' => 'tag_unavailable',
                'message' => 'The requested release tag is not available in the local repository and could not be fetched.',
                'release' => $release,
            ];
        }

        $checkout = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'checkout',
            '--detach',
            'refs/tags/' . $tag,
        ] );

        if ( (int) ( $checkout['exit_code'] ?? 1 ) !== 0 ) {
            return [
                'ok' => false,
                'status' => 'checkout_failed',
                'message' => 'Git could not check out the requested release tag.',
                'stderr' => (string) ( $checkout['stderr'] ?? '' ),
            ];
        }

        $postflight = $this->finalizeCheckout( $tag, $release, $trigger, $backup, $previous, 'release_apply' );
        if ( ! empty( $postflight['ok'] ) ) {
            return $postflight;
        }

        $rollback_target = $previous['tag'] !== '' ? 'refs/tags/' . $previous['tag'] : $previous['commit'];
        if ( $rollback_target !== '' ) {
            $this->runCommand( [
                $this->gitBinary(),
                '-C',
                \METIS_PATH,
                'checkout',
                '--detach',
                $rollback_target,
            ] );
            $this->finalizeRollbackState( $previous, $backup, 'automatic_recovery' );
        }

        $postflight['rolled_back'] = $rollback_target !== '';
        return $postflight;
    }

    public function rollback( string $trigger = 'manual' ): array {
        $this->ensureRuntime();

        $repository = $this->repositoryState( true );
        if ( $repository === null ) {
            return [
                'ok' => false,
                'status' => 'git_unavailable',
                'message' => 'Git repository state could not be resolved.',
            ];
        }

        if ( ! empty( $repository['dirty'] ) ) {
            return [
                'ok' => false,
                'status' => 'dirty_worktree',
                'message' => 'Tracked repository changes must be committed or reverted before a rollback can be applied.',
                'repository' => $repository,
            ];
        }

        $state = $this->readState();
        $target_tag = $this->normalizeTag( (string) ( $state['previous_tag'] ?? '' ) );
        if ( $target_tag === '' ) {
            return [
                'ok' => false,
                'status' => 'rollback_unavailable',
                'message' => 'No previous trusted release is recorded for rollback.',
            ];
        }

        $integrity = $this->preflightIntegrityCheck( 'pre_rollback' );
        if ( ! empty( $integrity['blocked'] ) ) {
            return [
                'ok' => false,
                'status' => 'integrity_blocked',
                'message' => 'Integrity verification must pass before rollback can continue.',
                'integrity' => $integrity,
            ];
        }

        $backup = $this->runPreUpdateBackup( $trigger . '_rollback' );
        if ( empty( $backup['ok'] ) ) {
            return [
                'ok' => false,
                'status' => 'backup_failed',
                'message' => (string) ( $backup['error'] ?? 'Pre-rollback backup failed.' ),
                'backup' => $backup,
            ];
        }

        if ( ! $this->ensureLocalTagAvailable( $target_tag, $repository ) ) {
            return [
                'ok' => false,
                'status' => 'tag_unavailable',
                'message' => 'The rollback target tag is unavailable locally and could not be fetched.',
                'target_tag' => $target_tag,
            ];
        }

        $checkout = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'checkout',
            '--detach',
            'refs/tags/' . $target_tag,
        ] );

        if ( (int) ( $checkout['exit_code'] ?? 1 ) !== 0 ) {
            return [
                'ok' => false,
                'status' => 'checkout_failed',
                'message' => 'Git could not check out the rollback tag.',
                'stderr' => (string) ( $checkout['stderr'] ?? '' ),
            ];
        }

        $release = $this->findReleaseByTag( $target_tag, (array) ( $this->refreshTrustedReleases( false, 'rollback' )['releases'] ?? [] ) );
        if ( $release === null ) {
            $release = [
                'tag' => $target_tag,
                'version' => $this->versionFromTag( $target_tag ),
            ];
        }

        $previous = [
            'tag' => (string) ( $repository['exact_tag'] ?? '' ),
            'commit' => (string) ( $repository['commit'] ?? '' ),
            'version' => (string) ( $state['installed_version'] ?? '' ),
        ];

        return $this->finalizeCheckout( $target_tag, $release, $trigger, $backup, $previous, 'release_rollback' );
    }

    public function refreshTrustedReleases( bool $force_refresh = false, string $context = 'manual' ): array {
        $this->ensureStorageDirectories();

        $cached = $this->readReleaseCache();
        $age = $this->releaseCacheAge( $cached );
        if ( ! $force_refresh && $cached !== [] && $age < $this->cacheTtl() ) {
            $cached['cache_age_seconds'] = $age;
            return $cached;
        }

        $local_releases = $this->discoverLocalReleases();
        $remote_payload = $this->discoverRemoteReleases( $force_refresh, $cached );
        $combined = $this->combineReleases( $local_releases, $remote_payload['releases'] ?? [] );

        $payload = [
            'checked_at' => \current_time( 'mysql' ),
            'context' => $context,
            'releases' => $combined,
            'remote_releases' => $remote_payload['releases'] ?? [],
            'remote_status' => (string) ( $remote_payload['status'] ?? 'disabled' ),
            'remote_error' => (string) ( $remote_payload['error'] ?? '' ),
            'cache_age_seconds' => 0,
        ];

        $this->writeJsonFile( $this->releaseCachePath(), $payload );

        return $payload;
    }

    private function preflightIntegrityCheck( string $trigger ): array {
        if ( ! \class_exists( 'Metis_Integrity_Manager' ) ) {
            return [
                'blocked' => false,
                'status' => 'unavailable',
            ];
        }

        $result = \Metis_Integrity_Manager::scan_and_heal( $trigger );
        $blocked = (string) ( $result['status'] ?? '' ) !== 'clean';

        return [
            'blocked' => $blocked,
            'status' => (string) ( $result['status'] ?? 'unknown' ),
            'result' => $result,
        ];
    }

    private function runPreUpdateBackup( string $trigger ): array {
        if ( ! \function_exists( 'metis_backup_run_now' ) ) {
            return [
                'ok' => true,
                'status' => 'skipped',
                'message' => 'Backup service is unavailable; continuing without a managed backup snapshot.',
            ];
        }

        return \metis_backup_run_now( 'release_' . $trigger );
    }

    private function finalizeCheckout( string $tag, array $release, string $trigger, array $backup, array $previous, string $reason ): array {
        $this->invalidateConfigCache();

        $baseline_built = true;
        $baseline_signed = false;
        $signature_required = false;

        if ( \class_exists( 'Metis_Integrity_Manager' ) ) {
            $baseline_built = \Metis_Integrity_Manager::build_baseline( $reason . ':' . $tag );
            $verification = \Metis_Integrity_Manager::verify_baseline();
            $signature_required = ! empty( $verification['signature_required'] );
            $baseline_signed = ! $signature_required ? true : \Metis_Integrity_Manager::sign_baseline();
        }

        if ( ! $baseline_built || ! $baseline_signed ) {
            return [
                'ok' => false,
                'status' => 'baseline_failed',
                'message' => 'Release checkout completed, but the new integrity baseline could not be established.',
                'baseline_built' => $baseline_built,
                'baseline_signed' => $baseline_signed,
                'signature_required' => $signature_required,
            ];
        }

        $repository = $this->repositoryState( true );
        $state = $this->readState();
        $installed_commit = (string) ( $repository['commit'] ?? ( $release['commit'] ?? '' ) );

        $this->persistState(
            array_merge(
                $state,
                [
                    'installed_version' => (string) ( $release['version'] ?? $this->versionFromTag( $tag ) ),
                    'installed_tag' => $tag,
                    'installed_commit' => $installed_commit,
                    'previous_tag' => (string) ( $previous['tag'] ?? '' ),
                    'previous_version' => (string) ( $previous['version'] ?? '' ),
                    'previous_commit' => (string) ( $previous['commit'] ?? '' ),
                    'last_action' => $reason,
                    'last_action_at' => \current_time( 'mysql' ),
                    'last_backup_run_uuid' => (string) ( $backup['run_uuid'] ?? '' ),
                ]
            )
        );

        $this->appendHistory( [
            'action' => $reason,
            'trigger' => $trigger,
            'tag' => $tag,
            'version' => (string) ( $release['version'] ?? '' ),
            'commit' => $installed_commit,
            'backup_run_uuid' => (string) ( $backup['run_uuid'] ?? '' ),
            'occurred_at' => \current_time( 'mysql' ),
        ] );

        if ( \class_exists( 'Metis_Logger' ) ) {
            \Metis_Logger::info( 'Release mutation completed', [
                'action' => $reason,
                'trigger' => $trigger,
                'tag' => $tag,
                'commit' => $installed_commit,
            ] );
        }

        return [
            'ok' => true,
            'status' => $reason,
            'message' => sprintf( 'Release %s is now installed.', $tag ),
            'release' => $release,
            'backup' => $backup,
            'repository' => $repository,
            'baseline_built' => $baseline_built,
            'baseline_signed' => $baseline_signed,
        ];
    }

    private function finalizeRollbackState( array $previous, array $backup, string $reason ): void {
        if ( ! \class_exists( 'Metis_Integrity_Manager' ) ) {
            return;
        }

        \Metis_Integrity_Manager::build_baseline( $reason );
        $verification = \Metis_Integrity_Manager::verify_baseline();
        if ( ! empty( $verification['signature_required'] ) ) {
            \Metis_Integrity_Manager::sign_baseline();
        }

        $this->persistState( [
            'installed_version' => (string) ( $previous['version'] ?? '' ),
            'installed_tag' => (string) ( $previous['tag'] ?? '' ),
            'installed_commit' => (string) ( $previous['commit'] ?? '' ),
            'last_action' => $reason,
            'last_action_at' => \current_time( 'mysql' ),
            'last_backup_run_uuid' => (string) ( $backup['run_uuid'] ?? '' ),
        ] + $this->readState() );
    }

    private function ensureLocalTagAvailable( string $tag, array $repository ): bool {
        if ( $this->localTagExists( $tag ) ) {
            return true;
        }

        $remote = (string) ( $repository['remote'] ?? $this->remoteName() );
        if ( $remote === '' ) {
            return false;
        }

        $fetch = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'fetch',
            $remote,
            'refs/tags/' . $tag . ':refs/tags/' . $tag,
        ] );

        return (int) ( $fetch['exit_code'] ?? 1 ) === 0 && $this->localTagExists( $tag );
    }

    private function localTagExists( string $tag ): bool {
        $result = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'show-ref',
            '--verify',
            '--quiet',
            'refs/tags/' . $tag,
        ] );

        return (int) ( $result['exit_code'] ?? 1 ) === 0;
    }

    private function discoverLocalReleases(): array {
        $result = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'tag',
            '--list',
            '--sort=-version:refname',
        ] );

        if ( (int) ( $result['exit_code'] ?? 1 ) !== 0 ) {
            return [];
        }

        $releases = [];
        foreach ( preg_split( '/\R+/', trim( (string) ( $result['stdout'] ?? '' ) ) ) ?: [] as $tag ) {
            $tag = $this->normalizeTag( $tag );
            $version = $this->versionFromTag( $tag );
            if ( $tag === '' || $version === '' ) {
                continue;
            }

            $commit = $this->runCommand( [
                $this->gitBinary(),
                '-C',
                \METIS_PATH,
                'rev-list',
                '-n',
                '1',
                $tag,
            ] );

            $releases[ $tag ] = [
                'tag' => $tag,
                'version' => $version,
                'commit' => trim( (string) ( $commit['stdout'] ?? '' ) ),
                'source' => 'local_tag',
                'trusted' => true,
                'cached' => false,
            ];
        }

        return array_values( $releases );
    }

    private function discoverRemoteReleases( bool $force_refresh, array $cached ): array {
        if ( ! $this->remoteChecksEnabled() ) {
            return [ 'status' => 'disabled', 'releases' => [] ];
        }

        $remote = $this->remoteName();
        if ( $remote === '' ) {
            return [ 'status' => 'no_remote', 'releases' => [] ];
        }

        $result = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'ls-remote',
            '--tags',
            '--refs',
            $remote,
        ] );

        if ( (int) ( $result['exit_code'] ?? 1 ) !== 0 ) {
            return [
                'status' => $cached !== [] && ! empty( $cached['remote_releases'] ) ? 'cached' : 'error',
                'error' => trim( (string) ( $result['stderr'] ?? '' ) ),
                'releases' => (array) ( $cached['remote_releases'] ?? [] ),
                'forced' => $force_refresh,
            ];
        }

        $releases = [];
        foreach ( preg_split( '/\R+/', trim( (string) ( $result['stdout'] ?? '' ) ) ) ?: [] as $line ) {
            if ( $line === '' ) {
                continue;
            }

            [ $commit, $ref ] = array_pad( preg_split( '/\s+/', trim( $line ), 2 ) ?: [], 2, '' );
            $tag = str_replace( 'refs/tags/', '', trim( (string) $ref ) );
            $tag = $this->normalizeTag( $tag );
            $version = $this->versionFromTag( $tag );
            if ( $tag === '' || $version === '' ) {
                continue;
            }

            $releases[ $tag ] = [
                'tag' => $tag,
                'version' => $version,
                'commit' => trim( (string) $commit ),
                'source' => 'remote_tag',
                'trusted' => true,
                'cached' => false,
            ];
        }

        return [
            'status' => 'live',
            'releases' => array_values( $releases ),
        ];
    }

    private function combineReleases( array $local, array $remote ): array {
        $combined = [];

        foreach ( array_merge( $remote, $local ) as $release ) {
            $tag = $this->normalizeTag( (string) ( $release['tag'] ?? '' ) );
            if ( $tag === '' ) {
                continue;
            }

            if ( ! isset( $combined[ $tag ] ) || (string) ( $release['source'] ?? '' ) === 'local_tag' ) {
                $combined[ $tag ] = $release;
            }
        }

        uasort(
            $combined,
            static function ( array $left, array $right ): int {
                $cmp = version_compare( (string) ( $right['version'] ?? '0.0.0' ), (string) ( $left['version'] ?? '0.0.0' ) );
                if ( $cmp !== 0 ) {
                    return $cmp;
                }

                return strcmp( (string) ( $right['tag'] ?? '' ), (string) ( $left['tag'] ?? '' ) );
            }
        );

        return array_values( $combined );
    }

    private function currentRelease( ?array $repository, array $state, array $releases ): ?array {
        $exact_tag = $this->normalizeTag( (string) ( $repository['exact_tag'] ?? '' ) );
        if ( $exact_tag !== '' ) {
            $release = $this->findReleaseByTag( $exact_tag, $releases );
            if ( $release !== null ) {
                return $release;
            }

            return [
                'tag' => $exact_tag,
                'version' => $this->versionFromTag( $exact_tag ),
                'commit' => (string) ( $repository['commit'] ?? '' ),
                'source' => 'current_checkout',
                'trusted' => true,
            ];
        }

        $installed_tag = $this->normalizeTag( (string) ( $state['installed_tag'] ?? '' ) );
        if ( $installed_tag !== '' ) {
            return $this->findReleaseByTag( $installed_tag, $releases ) ?? [
                'tag' => $installed_tag,
                'version' => (string) ( $state['installed_version'] ?? $this->versionFromTag( $installed_tag ) ),
                'commit' => (string) ( $state['installed_commit'] ?? '' ),
                'source' => 'state',
                'trusted' => true,
            ];
        }

        $installed_version = trim( (string) ( $state['installed_version'] ?? '' ) );
        if ( $installed_version === '' && \defined( 'METIS_VERSION' ) ) {
            $installed_version = (string) \METIS_VERSION;
        }

        if ( $installed_version === '' ) {
            return null;
        }

        return [
            'tag' => '',
            'version' => $installed_version,
            'commit' => (string) ( $repository['commit'] ?? '' ),
            'source' => 'version_constant',
            'trusted' => false,
        ];
    }

    private function latestRelease( array $releases ): ?array {
        return isset( $releases[0] ) && \is_array( $releases[0] ) ? $releases[0] : null;
    }

    private function findReleaseByTag( string $tag, array $releases ): ?array {
        foreach ( $releases as $release ) {
            if ( $this->normalizeTag( (string) ( $release['tag'] ?? '' ) ) === $tag ) {
                return $release;
            }
        }

        return null;
    }

    private function syncInstalledVersion(): void {
        $state = $this->readState();
        $version = \defined( 'METIS_VERSION' ) ? (string) \METIS_VERSION : '';
        $repository = $this->repositoryState();
        $tag = $this->normalizeTag( (string) ( $repository['exact_tag'] ?? ( $state['installed_tag'] ?? '' ) ) );
        $commit = (string) ( $repository['commit'] ?? ( $state['installed_commit'] ?? '' ) );

        if (
            (string) ( $state['installed_version'] ?? '' ) === $version
            && (string) ( $state['installed_tag'] ?? '' ) === $tag
            && (string) ( $state['installed_commit'] ?? '' ) === $commit
        ) {
            return;
        }

        $this->persistState(
            array_merge(
                $state,
                [
                    'installed_version' => $version,
                    'installed_tag' => $tag,
                    'installed_commit' => $commit,
                    'synced_at' => \current_time( 'mysql' ),
                ]
            )
        );
    }

    private function ensureStorageDirectories(): void {
        $directories = [
            $this->storageDir(),
            $this->cacheDir(),
            $this->historyDir(),
        ];

        foreach ( $directories as $directory ) {
            if ( ! \is_dir( $directory ) ) {
                \metis_make_dir( $directory );
            }

            $this->writeDirectoryGuards( $directory );
        }
    }

    private function writeDirectoryGuards( string $directory ): void {
        $index = rtrim( $directory, '/' ) . '/index.php';
        if ( ! \file_exists( $index ) ) {
            \file_put_contents( $index, "<?php\nhttp_response_code(403);\nexit;\n", LOCK_EX );
        }

        $htaccess = rtrim( $directory, '/' ) . '/.htaccess';
        if ( ! \file_exists( $htaccess ) ) {
            \file_put_contents( $htaccess, "Deny from all\n", LOCK_EX );
        }
    }

    private function storageDir(): string {
        return rtrim( (string) \METIS_PATH, '/' ) . '/' . self::STORAGE_DIR;
    }

    private function cacheDir(): string {
        return $this->storageDir() . '/' . self::CACHE_DIR;
    }

    private function historyDir(): string {
        return $this->storageDir() . '/' . self::HISTORY_DIR;
    }

    private function releaseCachePath(): string {
        return $this->cacheDir() . '/' . self::CACHE_FILE;
    }

    private function statePath(): string {
        return $this->storageDir() . '/' . self::STATE_FILE;
    }

    private function historyPath(): string {
        return $this->historyDir() . '/' . self::HISTORY_FILE;
    }

    private function readReleaseCache(): array {
        return $this->readJsonFile( $this->releaseCachePath() );
    }

    private function releaseCacheAge( array $cached ): int {
        $checked_at = trim( (string) ( $cached['checked_at'] ?? '' ) );
        if ( $checked_at === '' ) {
            return PHP_INT_MAX;
        }

        $timestamp = strtotime( $checked_at );
        if ( $timestamp === false ) {
            return PHP_INT_MAX;
        }

        return max( 0, time() - $timestamp );
    }

    private function cacheTtl(): int {
        $config = $this->config();
        $ttl = (int) ( $config['cache_ttl'] ?? 6 * \HOUR_IN_SECONDS );
        return max( 60, $ttl );
    }

    private function remoteChecksEnabled(): bool {
        $config = $this->config();
        return ! isset( $config['remote_enabled'] ) || ! empty( $config['remote_enabled'] );
    }

    private function remoteName(): string {
        $config = $this->config();
        $remote = trim( (string) ( $config['remote'] ?? '' ) );
        if ( $remote !== '' ) {
            return $remote;
        }

        $repository = $this->repositoryState();
        return (string) ( $repository['remote'] ?? 'origin' );
    }

    private function gitBinary(): string {
        $config = $this->config();
        $binary = trim( (string) ( $config['git_binary'] ?? '' ) );
        return $binary !== '' ? $binary : 'git';
    }

    private function config(): array {
        static $config = null;
        if ( \is_array( $config ) ) {
            return $config;
        }

        if ( \function_exists( 'metis_standalone_read_config' ) ) {
            $config = \metis_standalone_read_config( 'release', [] );
            return $config;
        }

        $path = rtrim( (string) \METIS_PATH, '/' ) . '/config/release.php';
        if ( ! \is_file( $path ) ) {
            $config = [];
            return $config;
        }

        $loaded = require $path;
        $config = \is_array( $loaded ) ? $loaded : [];
        return $config;
    }

    private function repositoryState( bool $refresh = false ): ?array {
        static $cached = null;
        if ( ! $refresh && \is_array( $cached ) ) {
            return $cached;
        }

        $top_level = $this->runCommand( [ $this->gitBinary(), '-C', \METIS_PATH, 'rev-parse', '--show-toplevel' ] );
        $commit = $this->runCommand( [ $this->gitBinary(), '-C', \METIS_PATH, 'rev-parse', 'HEAD' ] );
        if ( (int) ( $top_level['exit_code'] ?? 1 ) !== 0 || (int) ( $commit['exit_code'] ?? 1 ) !== 0 ) {
            return null;
        }

        $status = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'status',
            '--porcelain',
            '--untracked-files=no',
            '--ignored=no',
        ] );

        $exact_tag = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'describe',
            '--tags',
            '--exact-match',
            'HEAD',
        ] );

        $remote = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'remote',
        ] );

        $first_remote = '';
        foreach ( preg_split( '/\R+/', trim( (string) ( $remote['stdout'] ?? '' ) ) ) ?: [] as $line ) {
            $line = trim( (string) $line );
            if ( $line !== '' ) {
                $first_remote = $line;
                break;
            }
        }

        $cached = [
            'top_level' => trim( (string) ( $top_level['stdout'] ?? '' ) ),
            'commit' => trim( (string) ( $commit['stdout'] ?? '' ) ),
            'dirty' => trim( (string) ( $status['stdout'] ?? '' ) ),
            'exact_tag' => $this->normalizeTag( trim( (string) ( $exact_tag['stdout'] ?? '' ) ) ),
            'remote' => $first_remote,
        ];

        return $cached;
    }

    private function normalizeTag( string $tag ): string {
        return trim( preg_replace( '/\s+/', '', $tag ) ?? '' );
    }

    private function versionFromTag( string $tag ): string {
        $tag = $this->normalizeTag( $tag );
        if ( $tag === '' ) {
            return '';
        }

        $version = preg_replace( '/^v/i', '', $tag ) ?? '';
        return preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) === 1 ? $version : '';
    }

    private function readState(): array {
        return $this->readJsonFile( $this->statePath() );
    }

    private function persistState( array $state ): void {
        $state['updated_at'] = \current_time( 'mysql' );
        $this->writeJsonFile( $this->statePath(), $state );
    }

    private function readHistory(): array {
        return array_values( $this->readJsonFile( $this->historyPath() ) );
    }

    private function appendHistory( array $entry ): void {
        $history = $this->readHistory();
        array_unshift( $history, $entry );
        $history = array_slice( $history, 0, 25 );
        $this->writeJsonFile( $this->historyPath(), $history );
    }

    private function readJsonFile( string $path ): array {
        if ( ! \is_file( $path ) ) {
            return [];
        }

        $raw = \file_get_contents( $path );
        if ( ! \is_string( $raw ) || trim( $raw ) === '' ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        return \is_array( $decoded ) ? $decoded : [];
    }

    private function writeJsonFile( string $path, array $payload ): void {
        $dir = dirname( $path );
        if ( ! \is_dir( $dir ) ) {
            \metis_make_dir( $dir );
        }

        \file_put_contents(
            $path,
            \metis_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '{}',
            LOCK_EX
        );
    }

    private function invalidateConfigCache(): void {
        if ( \function_exists( 'metis_standalone_invalidate_config_cache' ) ) {
            \metis_standalone_invalidate_config_cache();
        }
    }

    private function runCommand( array $command ): array {
        if ( ! \function_exists( 'proc_open' ) ) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'proc_open unavailable',
            ];
        }

        $descriptors = [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];

        $process = @\proc_open( $command, $descriptors, $pipes, \METIS_PATH );
        if ( ! \is_resource( $process ) ) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'proc_open failed',
            ];
        }

        \fclose( $pipes[0] );
        $stdout = \stream_get_contents( $pipes[1] );
        $stderr = \stream_get_contents( $pipes[2] );
        \fclose( $pipes[1] );
        \fclose( $pipes[2] );

        return [
            'exit_code' => \proc_close( $process ),
            'stdout' => \is_string( $stdout ) ? $stdout : '',
            'stderr' => \is_string( $stderr ) ? $stderr : '',
        ];
    }
}
