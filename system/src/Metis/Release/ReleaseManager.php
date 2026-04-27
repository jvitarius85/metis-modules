<?php
declare(strict_types=1);

namespace Metis\Release;

use Metis\Core\Version;

final class ReleaseManager {
    private const STORAGE_DIR = 'storage/runtime/release';
    private const CACHE_DIR = 'cache';
    private const HISTORY_DIR = 'history';
    private const CACHE_FILE = 'release-cache.json';
    private const STATE_FILE = 'state.json';
    private const HISTORY_FILE = 'release-history.json';
    private const ARCHIVE_PROTECTED_DIRS = [
        '.git',
        '.github',
        'meta',
        'storage',
    ];
    private const ARCHIVE_PROTECTED_FILES = [
        '.DS_Store',
        '.env',
        '.gitignore',
        'AGENTS.md',
        'INSTALL.md',
        'MODULE_GUIDE.md',
        'README.md',
        'ROADMAP.md',
        'SECURITY.md',
        'system/config/database.php',
        'system/config/update.php',
    ];

    /** @var null|callable(array<string,mixed>):void */
    private $progressReporter = null;

    public function setProgressReporter( ?callable $reporter ): self {
        $this->progressReporter = $reporter;
        return $this;
    }

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
        $remote_status = (string) ( $releases_payload['remote_status'] ?? 'unavailable' );
        $remote_available = \in_array( $remote_status, [ 'live', 'manifest', 'api', 'cached' ], true )
            || ! empty( $releases_payload['remote_releases'] );

        $status = [
            'ok' => true,
            'status' => empty( $releases_payload['releases'] ) ? 'no_releases' : 'ready',
            'installed_version' => Version::current(),
            'installed_tag' => (string) ( $state['installed_tag'] ?? '' ),
            'current' => $current,
            'latest' => $latest,
            'update_available' => $latest !== null && $current !== null
                ? version_compare( (string) $latest['version'], (string) $current['version'], '>' )
                : ( $latest !== null && $current === null ),
            'repository' => [
                'available' => $repository !== null || $remote_available,
                'clean' => $repository !== null
                    ? ( ( $repository['dirty_known'] ?? true ) ? empty( $repository['dirty'] ) : null )
                    : null,
                'dirty_known' => $repository !== null ? (bool) ( $repository['dirty_known'] ?? true ) : false,
                'head' => $repository['commit'] ?? '',
                'tag' => $repository['exact_tag'] ?? '',
                'remote' => $repository['remote'] ?? '',
            ],
            'trusted_releases' => $releases_payload['releases'] ?? [],
            'last_checked_at' => (string) ( $releases_payload['checked_at'] ?? '' ),
            'cache_age_seconds' => (int) ( $releases_payload['cache_age_seconds'] ?? 0 ),
            'remote_status' => $remote_status,
            'remote_error' => (string) ( $releases_payload['remote_error'] ?? '' ),
            'state' => $state,
            'history' => $this->readHistory(),
        ];

        if ( $repository === null && ! $remote_available ) {
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

    public function statusSnapshot(): array {
        $this->ensureStorageDirectories();

        $releases_payload = $this->readReleaseCache();
        $state = $this->readState();
        $releases = (array) ( $releases_payload['releases'] ?? [] );
        $current = $this->currentRelease( null, $state, $releases );
        $latest = $this->latestRelease( $releases );
        $installed_commit = (string) ( $state['installed_commit'] ?? '' );
        $remote_status = (string) ( $releases_payload['remote_status'] ?? 'cache_only' );
        $remote_available = \in_array( $remote_status, [ 'live', 'manifest', 'api', 'cached', 'cache_only' ], true )
            && ( $releases !== [] || ! empty( $releases_payload['remote_releases'] ) );

        return [
            'ok' => true,
            'status' => empty( $releases ) ? 'no_releases' : 'ready',
            'installed_version' => (string) ( $state['installed_version'] ?? Version::current() ),
            'installed_tag' => (string) ( $state['installed_tag'] ?? '' ),
            'current' => $current,
            'latest' => $latest,
            'update_available' => $latest !== null && $current !== null
                ? version_compare( (string) $latest['version'], (string) $current['version'], '>' )
                : ( $latest !== null && $current === null ),
            'repository' => [
                'available' => $installed_commit !== '' || $remote_available,
                'clean' => null,
                'dirty_known' => false,
                'head' => $installed_commit,
                'tag' => (string) ( $state['installed_tag'] ?? '' ),
                'remote' => (string) ( $releases_payload['remote'] ?? '' ),
            ],
            'trusted_releases' => $releases,
            'last_checked_at' => (string) ( $releases_payload['checked_at'] ?? ( $state['last_checked_at'] ?? '' ) ),
            'cache_age_seconds' => $this->releaseCacheAge( $releases_payload ),
            'remote_status' => $remote_status,
            'remote_error' => (string) ( $releases_payload['remote_error'] ?? '' ),
            'state' => $state,
            'history' => $this->readHistory(),
            'snapshot' => true,
        ];
    }

    public function checkForUpdates( bool $force_refresh = false, string $trigger = 'manual' ): array {
        $this->releaseExecution()->assertEnabled();
        $this->releaseExecution()->assertSystemAdministrator( $trigger );
        $this->releaseExecution()->auditAction( 'check', [
            'force_refresh' => $force_refresh,
            'trigger' => $trigger,
        ] );

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
        $this->releaseExecution()->assertEnabled();
        $this->releaseExecution()->assertSystemAdministrator( $trigger );
        $tag = $this->normalizeTag( $tag );
        $this->releaseExecution()->auditAction( 'apply', [
            'tag' => $tag,
            'trigger' => $trigger,
        ] );
        $this->progress( 'start', 'Preparing release update.', 2, [ 'tag' => $tag ] );

        if ( $tag === '' ) {
            $this->progress( 'failed', 'Release tag is missing.', 100 );
            return [
                'ok' => false,
                'status' => 'invalid_tag',
                'message' => 'A trusted release tag is required.',
            ];
        }

        $this->ensureRuntime();

        $cached_payload = $this->readReleaseCache();
        $releases_payload = $this->refreshTrustedReleases( true, 'apply' );
        $release = $this->findReleaseByTag( $tag, $releases_payload['releases'] ?? [] );
        if ( $release === null ) {
            $release = $this->findReleaseByTag( $tag, $cached_payload['releases'] ?? [] );
        }
        if ( $release === null ) {
            $this->progress( 'failed', 'Requested release is not trusted.', 100 );
            return [
                'ok' => false,
                'status' => 'untrusted_release',
                'message' => 'The requested release tag is not in the trusted release list.',
            ];
        }

        $repository = $this->repositoryState( true );
        if ( $repository === null ) {
            $this->progress( 'archive_mode', 'Git is unavailable; switching to trusted archive update.', 10 );
            return $this->applyArchiveRelease( $tag, $release, $trigger );
        }

        if ( ! empty( $repository['dirty'] ) ) {
            $this->progress( 'failed', 'Repository has tracked changes.', 100 );
            return [
                'ok' => false,
                'status' => 'dirty_worktree',
                'message' => 'Tracked repository changes must be committed or reverted before an update can be applied.',
                'repository' => $repository,
            ];
        }

        if ( (string) ( $repository['exact_tag'] ?? '' ) === $tag ) {
            $this->progress( 'complete', 'Requested release is already installed.', 100 );
            return [
                'ok' => true,
                'status' => 'already_installed',
                'message' => 'The requested release is already installed.',
                'release' => $release,
                'repository' => $repository,
            ];
        }

        $this->progress( 'integrity', 'Running integrity preflight.', 15 );
        $integrity = $this->preflightIntegrityCheck( 'pre_update' );
        if ( ! empty( $integrity['blocked'] ) ) {
            $this->progress( 'failed', 'Integrity preflight blocked the update.', 100 );
            return [
                'ok' => false,
                'status' => 'integrity_blocked',
                'message' => 'Integrity verification must pass before an update can be applied.',
                'integrity' => $integrity,
            ];
        }

        $this->progress( 'modules', 'Running module compliance preflight.', 25 );
        $compliance = $this->preflightModuleComplianceCheck();
        if ( ! empty( $compliance['blocked'] ) ) {
            $this->progress( 'failed', 'Module compliance blocked the update.', 100 );
            return [
                'ok' => false,
                'status' => 'module_compliance_blocked',
                'message' => 'Module compliance verification must pass before an update can be applied.',
                'module_compliance' => $compliance,
            ];
        }

        $this->progress( 'backup', 'Creating pre-update backup.', 35 );
        $backup = $this->runPreUpdateBackup( $trigger );
        if ( empty( $backup['ok'] ) ) {
            $this->progress( 'failed', 'Pre-update backup failed.', 100 );
            return [
                'ok' => false,
                'status' => 'backup_failed',
                'message' => 'Pre-update backup failed.',
                'backup' => $backup,
            ];
        }

        $previous = [
            'tag' => (string) ( $repository['exact_tag'] ?? '' ),
            'commit' => (string) ( $repository['commit'] ?? '' ),
            'version' => Version::current(),
        ];

        $this->progress( 'fetch', 'Verifying release tag locally.', 55 );
        if ( ! $this->ensureLocalTagAvailable( $tag, $repository ) ) {
            $this->progress( 'failed', 'Release tag could not be fetched.', 100 );
            return [
                'ok' => false,
                'status' => 'tag_unavailable',
                'message' => 'The requested release tag is not available in the local repository and could not be fetched.',
                'release' => $release,
            ];
        }

        $this->progress( 'checkout', 'Checking out release tag.', 68 );
        $checkout = $this->runCommand( [
            $this->gitBinary(),
            '-C',
            \METIS_PATH,
            'checkout',
            '--detach',
            'refs/tags/' . $tag,
        ] );

        if ( (int) ( $checkout['exit_code'] ?? 1 ) !== 0 ) {
            $this->progress( 'failed', 'Git checkout failed.', 100 );
            return [
                'ok' => false,
                'status' => 'checkout_failed',
                'message' => 'Git could not check out the requested release tag.',
                'stderr' => (string) ( $checkout['stderr'] ?? '' ),
            ];
        }

        $this->progress( 'baseline', 'Rebuilding integrity baseline.', 82 );
        $postflight = $this->finalizeCheckout( $tag, $release, $trigger, $backup, $previous, 'release_apply' );
        if ( ! empty( $postflight['ok'] ) ) {
            $this->progress( 'complete', 'Release update completed.', 100 );
            return $postflight;
        }

        $this->progress( 'rollback', 'Postflight failed; attempting rollback.', 92 );
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
        $this->progress( 'failed', 'Release update failed.', 100 );
        return $postflight;
    }

    public function rollback( string $trigger = 'manual' ): array {
        $this->releaseExecution()->assertEnabled();
        $this->releaseExecution()->assertSystemAdministrator( $trigger );
        $this->releaseExecution()->auditAction( 'rollback', [
            'trigger' => $trigger,
        ] );

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

        $compliance = $this->preflightModuleComplianceCheck();
        if ( ! empty( $compliance['blocked'] ) ) {
            return [
                'ok' => false,
                'status' => 'module_compliance_blocked',
                'message' => 'Module compliance verification must pass before rollback can continue.',
                'module_compliance' => $compliance,
            ];
        }

        $backup = $this->runPreUpdateBackup( $trigger . '_rollback' );
        if ( empty( $backup['ok'] ) ) {
            return [
                'ok' => false,
                'status' => 'backup_failed',
                'message' => 'Pre-rollback backup failed.',
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
            'checked_at' => \metis_current_time( 'mysql' ),
            'context' => $context,
            'releases' => $combined,
            'remote_releases' => $remote_payload['releases'] ?? [],
            'remote_status' => (string) ( $remote_payload['status'] ?? 'disabled' ),
            'remote_error' => '',
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

    private function preflightModuleComplianceCheck(): array {
        if ( ! \function_exists( 'metis_module_compliance_report' ) ) {
            return [
                'blocked' => false,
                'status' => 'unavailable',
                'summary' => [ 'checked' => 0, 'failed' => 0, 'passed' => 0 ],
                'failures' => [],
            ];
        }

        $report = (array) \metis_module_compliance_report( true );
        $summary = is_array( $report['summary'] ?? null ) ? $report['summary'] : [];
        $results = is_array( $report['results'] ?? null ) ? $report['results'] : [];
        $failures = array_values(
            array_filter(
                $results,
                static fn ( mixed $row ): bool => is_array( $row ) && (string) ( $row['status'] ?? '' ) === 'failed'
            )
        );
        $failed = (int) ( $summary['failed'] ?? count( $failures ) );

        return [
            'blocked' => $failed > 0,
            'status' => $failed > 0 ? 'failed' : 'ok',
            'summary' => [
                'checked' => (int) ( $summary['checked'] ?? 0 ),
                'failed' => $failed,
                'passed' => (int) ( $summary['passed'] ?? 0 ),
            ],
            'failures' => $failures,
            'report' => $report,
        ];
    }

    private function runPreUpdateBackup( string $trigger ): array {
        if ( ! \function_exists( 'metis_backup_run_now' ) ) {
            return $this->runLocalPreUpdateArchive( $trigger, [
                'ok' => false,
                'status' => 'managed_backup_unavailable',
                'message' => 'Managed backup service is unavailable.',
            ] );
        }

        $managed = \metis_backup_run_now( 'release_' . $trigger );
        if ( ! empty( $managed['ok'] ) ) {
            return $managed;
        }

        return $this->runLocalPreUpdateArchive( $trigger, $managed );
    }

    private function runLocalPreUpdateArchive( string $trigger, array $managed ): array {
        if ( ! \class_exists( '\ZipArchive' ) ) {
            return [
                'ok' => false,
                'status' => 'backup_failed',
                'message' => 'Managed backup failed and PHP ZipArchive is unavailable for local release backup.',
                'managed_backup' => $managed,
            ];
        }

        $backup_dir = $this->storageDir() . '/backups';
        if ( ! \is_dir( $backup_dir ) && ! \metis_runtime_make_dir( $backup_dir ) ) {
            return [
                'ok' => false,
                'status' => 'backup_failed',
                'message' => 'Managed backup failed and local release backup directory could not be created.',
                'managed_backup' => $managed,
            ];
        }

        $safe_trigger = preg_replace( '/[^A-Za-z0-9_.-]/', '-', $trigger ) ?: 'manual';
        $archive_path = $backup_dir . '/release-pre-update-' . gmdate( 'Ymd-His' ) . '-' . $safe_trigger . '.zip';
        $zip = new \ZipArchive();
        if ( $zip->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            return [
                'ok' => false,
                'status' => 'backup_failed',
                'message' => 'Managed backup failed and local release backup archive could not be opened.',
                'managed_backup' => $managed,
            ];
        }

        $root = rtrim( str_replace( '\\', '/', (string) \METIS_PATH ), '/' );
        $added = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( ! $item instanceof \SplFileInfo ) {
                continue;
            }

            $path = str_replace( '\\', '/', $item->getPathname() );
            $relative = ltrim( substr( $path, strlen( $root ) ), '/' );
            if ( $relative === '' || $this->releaseBackupPathIsProtected( $relative ) ) {
                continue;
            }

            if ( $item->isDir() ) {
                $zip->addEmptyDir( $relative );
                continue;
            }

            if ( $item->isFile() && $zip->addFile( $path, $relative ) ) {
                $added++;
            }
        }

        $zip->addFromString( 'release-backup.json', \metis_json_encode( [
            'created_at' => gmdate( 'c' ),
            'trigger' => $trigger,
            'version' => Version::current(),
            'managed_backup' => $managed,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '{}' );
        $zip->close();

        if ( $added < 1 || ! \is_file( $archive_path ) ) {
            @unlink( $archive_path );
            return [
                'ok' => false,
                'status' => 'backup_failed',
                'message' => 'Managed backup failed and local release backup archive is empty.',
                'managed_backup' => $managed,
            ];
        }

        return [
            'ok' => true,
            'status' => 'local_archive',
            'message' => 'Managed backup failed; created a local pre-update release archive instead.',
            'archive_path' => $archive_path,
            'bytes' => (int) @filesize( $archive_path ),
            'sha256' => (string) @hash_file( 'sha256', $archive_path ),
            'files' => $added,
            'managed_backup' => $managed,
        ];
    }

    private function releaseBackupPathIsProtected( string $relative ): bool {
        $relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
        foreach ( [ '.git', 'storage', 'meta' ] as $dir ) {
            if ( $relative === $dir || str_starts_with( $relative, $dir . '/' ) ) {
                return true;
            }
        }

        foreach ( [ '.DS_Store', 'system/config/database.php', 'system/config/update.php' ] as $file ) {
            if ( $relative === $file ) {
                return true;
            }
        }

        return preg_match( '#^system/config/.+\\.local\\.php$#', $relative ) === 1
            || str_starts_with( $relative, 'system/config/auth/' );
    }

    private function applyArchiveRelease( string $tag, array $release, string $trigger ): array {
        if ( ! \class_exists( '\ZipArchive' ) ) {
            $this->progress( 'failed', 'ZipArchive is unavailable.', 100 );
            return [
                'ok' => false,
                'status' => 'archive_unavailable',
                'message' => 'Git is unavailable and PHP ZipArchive is not installed, so Metis cannot apply a release archive safely.',
            ];
        }

        if ( ! \class_exists( '\Metis\Core\Application' ) || ! \Metis\Core\Application::has_service( 'github_update' ) ) {
            $this->progress( 'failed', 'GitHub update service is unavailable.', 100 );
            return [
                'ok' => false,
                'status' => 'archive_unavailable',
                'message' => 'Git is unavailable and the GitHub update service is not available.',
            ];
        }

        $this->progress( 'integrity', 'Running integrity preflight.', 15 );
        $integrity = $this->preflightIntegrityCheck( 'pre_update_archive' );
        if ( ! empty( $integrity['blocked'] ) ) {
            $this->progress( 'failed', 'Integrity preflight blocked the update.', 100 );
            return [
                'ok' => false,
                'status' => 'integrity_blocked',
                'message' => 'Integrity verification must pass before an archive update can be applied.',
                'integrity' => $integrity,
            ];
        }

        $this->progress( 'modules', 'Running module compliance preflight.', 25 );
        $compliance = $this->preflightModuleComplianceCheck();
        if ( ! empty( $compliance['blocked'] ) ) {
            $this->progress( 'failed', 'Module compliance blocked the update.', 100 );
            return [
                'ok' => false,
                'status' => 'module_compliance_blocked',
                'message' => 'Module compliance verification must pass before an archive update can be applied.',
                'module_compliance' => $compliance,
            ];
        }

        $this->progress( 'backup', 'Creating pre-update backup.', 35 );
        $backup = $this->runPreUpdateBackup( $trigger );
        if ( empty( $backup['ok'] ) ) {
            $this->progress( 'failed', 'Pre-update backup failed.', 100 );
            return [
                'ok' => false,
                'status' => 'backup_failed',
                'message' => 'Pre-update backup failed.',
                'backup' => $backup,
            ];
        }

        $this->progress( 'download', 'Downloading trusted release archive.', 52 );
        $archive = $this->downloadReleaseArchive( $tag );
        if ( empty( $archive['ok'] ) ) {
            $this->progress( 'failed', 'Release archive download failed.', 100 );
            return $archive + [
                'ok' => false,
                'status' => 'archive_download_failed',
                'message' => 'Release archive could not be downloaded.',
            ];
        }
        $expected_hash = strtolower( trim( (string) ( $release['sha256'] ?? '' ) ) );
        $actual_hash = strtolower( trim( (string) ( $archive['sha256'] ?? '' ) ) );
        if ( $expected_hash !== '' && ( $actual_hash === '' || ! hash_equals( $expected_hash, $actual_hash ) ) ) {
            $this->progress( 'failed', 'Release archive checksum did not match.', 100 );
            return [
                'ok' => false,
                'status' => 'archive_checksum_failed',
                'message' => 'Release archive checksum did not match the trusted release manifest.',
                'expected_sha256' => $expected_hash,
                'actual_sha256' => $actual_hash,
            ];
        }

        $this->progress( 'extract', 'Validating and extracting release archive.', 64 );
        $extracted = $this->extractReleaseArchive( (string) $archive['path'], $tag );
        if ( empty( $extracted['ok'] ) ) {
            $this->progress( 'failed', 'Release archive extraction failed.', 100 );
            return $extracted + [
                'ok' => false,
                'status' => 'archive_extract_failed',
                'message' => 'Release archive could not be extracted.',
            ];
        }

        $this->progress( 'apply', 'Applying release files.', 76 );
        $applied = $this->copyArchivePayload( (string) $extracted['source_root'] );
        if ( empty( $applied['ok'] ) ) {
            $this->progress( 'failed', 'Release files could not be applied.', 100 );
            return $applied + [
                'ok' => false,
                'status' => 'archive_apply_failed',
                'message' => 'Release archive files could not be copied into place.',
            ];
        }

        $this->progress( 'baseline', 'Rebuilding integrity baseline.', 88 );
        return $this->finalizeArchiveApply(
            $tag,
            $release,
            $trigger,
            $backup,
            [
                'tag' => (string) ( $this->readState()['installed_tag'] ?? '' ),
                'commit' => (string) ( $this->readState()['installed_commit'] ?? '' ),
                'version' => Version::current(),
            ],
            [
                'archive' => $archive,
                'extracted' => $extracted,
                'applied' => $applied,
            ]
        );
    }

    private function downloadReleaseArchive( string $tag ): array {
        $archive_path = $this->cacheDir() . '/release-' . preg_replace( '/[^A-Za-z0-9_.-]/', '-', $tag ) . '.zip';

        try {
            $download = \Metis\Core\Application::service( 'github_update' )->downloadReleaseArchive( $tag, $archive_path );
        } catch ( \Throwable $throwable ) {
            return [
                'ok' => false,
                'status' => 'archive_download_failed',
                'message' => $throwable->getMessage(),
            ];
        }

        return [
            'ok' => true,
            'status' => 'downloaded',
            'path' => (string) ( $download['path'] ?? $archive_path ),
            'bytes' => (int) ( $download['bytes'] ?? 0 ),
            'sha256' => (string) ( $download['sha256'] ?? '' ),
            'url' => (string) ( $download['url'] ?? '' ),
        ];
    }

    private function extractReleaseArchive( string $archive_path, string $tag ): array {
        $extract_dir = $this->cacheDir() . '/extract-' . preg_replace( '/[^A-Za-z0-9_.-]/', '-', $tag ) . '-' . date( 'YmdHis' );
        if ( ! \is_dir( $extract_dir ) && ! \metis_runtime_make_dir( $extract_dir ) ) {
            return [
                'ok' => false,
                'status' => 'archive_extract_failed',
                'message' => 'Unable to create release extraction directory.',
            ];
        }

        $zip = new \ZipArchive();
        if ( $zip->open( $archive_path ) !== true ) {
            return [
                'ok' => false,
                'status' => 'archive_extract_failed',
                'message' => 'Unable to open release archive.',
            ];
        }

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry = (string) $zip->getNameIndex( $i );
            $normalized = str_replace( '\\', '/', $entry );
            if (
                $normalized === ''
                || str_starts_with( $normalized, '/' )
                || str_contains( $normalized, '/../' )
                || str_starts_with( $normalized, '../' )
            ) {
                $zip->close();
                return [
                    'ok' => false,
                    'status' => 'archive_invalid',
                    'message' => 'Release archive contains an unsafe path.',
                ];
            }
        }

        $ok = $zip->extractTo( $extract_dir );
        $zip->close();
        if ( ! $ok ) {
            return [
                'ok' => false,
                'status' => 'archive_extract_failed',
                'message' => 'Unable to extract release archive.',
            ];
        }

        $children = array_values(
            array_filter(
                scandir( $extract_dir ) ?: [],
                static fn ( string $name ): bool => $name !== '.' && $name !== '..' && is_dir( $extract_dir . '/' . $name )
            )
        );
        $source_root = isset( $children[0] ) ? $extract_dir . '/' . $children[0] : $extract_dir;

        foreach ( [ 'index.php', 'system/src/Metis/Core/Version.php' ] as $required ) {
            if ( ! \is_file( rtrim( $source_root, '/' ) . '/' . $required ) ) {
                return [
                    'ok' => false,
                    'status' => 'archive_invalid',
                    'message' => 'Release archive does not contain the expected Metis application structure.',
                    'missing' => $required,
                ];
            }
        }

        return [
            'ok' => true,
            'status' => 'extracted',
            'extract_dir' => $extract_dir,
            'source_root' => $source_root,
        ];
    }

    private function copyArchivePayload( string $source_root ): array {
        $source_root = rtrim( str_replace( '\\', '/', $source_root ), '/' );
        $target_root = rtrim( str_replace( '\\', '/', (string) \METIS_PATH ), '/' );
        $copied = 0;
        $skipped = 0;
        $failures = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $source_root, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            if ( ! $item instanceof \SplFileInfo ) {
                continue;
            }

            $source_path = str_replace( '\\', '/', $item->getPathname() );
            $relative = ltrim( substr( $source_path, strlen( $source_root ) ), '/' );
            if ( $relative === '' || $this->archivePathIsProtected( $relative ) ) {
                $skipped++;
                continue;
            }

            $target_path = $target_root . '/' . $relative;
            if ( $item->isDir() ) {
                if ( ! \is_dir( $target_path ) && ! \metis_runtime_make_dir( $target_path ) ) {
                    $failures[] = [ 'path' => $relative, 'reason' => 'mkdir_failed' ];
                }
                continue;
            }

            if ( ! $item->isFile() ) {
                $skipped++;
                continue;
            }

            $target_dir = dirname( $target_path );
            if ( ! \is_dir( $target_dir ) && ! \metis_runtime_make_dir( $target_dir ) ) {
                $failures[] = [ 'path' => $relative, 'reason' => 'mkdir_failed' ];
                continue;
            }

            if ( \is_dir( $target_path ) ) {
                $failures[] = [ 'path' => $relative, 'reason' => 'target_is_directory' ];
                continue;
            }

            if ( ! @copy( $source_path, $target_path ) ) {
                $failures[] = [ 'path' => $relative, 'reason' => 'copy_failed' ];
                continue;
            }

            $copied++;
        }

        return [
            'ok' => $failures === [],
            'status' => $failures === [] ? 'applied' : 'failed',
            'copied' => $copied,
            'skipped' => $skipped,
            'failures' => array_slice( $failures, 0, 25 ),
            'failure_count' => count( $failures ),
        ];
    }

    private function archivePathIsProtected( string $relative ): bool {
        $relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
        foreach ( self::ARCHIVE_PROTECTED_DIRS as $dir ) {
            if ( $relative === $dir || str_starts_with( $relative, $dir . '/' ) ) {
                return true;
            }
        }

        foreach ( self::ARCHIVE_PROTECTED_FILES as $file ) {
            if ( $relative === $file ) {
                return true;
            }
        }

        if ( preg_match( '#^system/config/.+\\.local\\.php$#', $relative ) === 1 ) {
            return true;
        }

        if ( str_starts_with( $relative, 'system/config/auth/' ) ) {
            return true;
        }

        return false;
    }

    private function finalizeArchiveApply( string $tag, array $release, string $trigger, array $backup, array $previous, array $archive_result ): array {
        $this->invalidateConfigCache();

        $baseline_built = true;
        $baseline_signed = false;
        $signature_required = false;

        if ( \class_exists( 'Metis_Integrity_Manager' ) ) {
            $baseline_built = \Metis_Integrity_Manager::build_baseline( 'release_archive_apply:' . $tag );
            $verification = \Metis_Integrity_Manager::verify_baseline();
            $signature_required = ! empty( $verification['signature_required'] );
            $baseline_signed = ! $signature_required ? true : \Metis_Integrity_Manager::sign_baseline();
        }

        if ( ! $baseline_built || ! $baseline_signed ) {
            $this->progress( 'failed', 'Integrity baseline could not be established.', 100 );
            return [
                'ok' => false,
                'status' => 'baseline_failed',
                'message' => 'Release archive was applied, but the new integrity baseline could not be established.',
                'baseline_built' => $baseline_built,
                'baseline_signed' => $baseline_signed,
                'signature_required' => $signature_required,
                'archive' => $archive_result,
            ];
        }

        $state = $this->readState();
        $installed_commit = (string) ( $release['commit'] ?? '' );

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
                    'last_action' => 'release_archive_apply',
                    'last_action_at' => \metis_current_time( 'mysql' ),
                    'last_backup_run_uuid' => (string) ( $backup['run_uuid'] ?? '' ),
                ]
            )
        );

        $this->appendHistory( [
            'action' => 'release_archive_apply',
            'trigger' => $trigger,
            'tag' => $tag,
            'version' => (string) ( $release['version'] ?? '' ),
            'commit' => $installed_commit,
            'backup_run_uuid' => (string) ( $backup['run_uuid'] ?? '' ),
            'occurred_at' => \metis_current_time( 'mysql' ),
        ] );

        if ( \class_exists( 'Metis_Logger' ) ) {
            \Metis_Logger::info( 'Release archive mutation completed', [
                'trigger' => $trigger,
                'tag' => $tag,
                'commit' => $installed_commit,
                'copied' => (int) ( $archive_result['applied']['copied'] ?? 0 ),
            ] );
        }

        $this->progress( 'complete', 'Release update completed.', 100 );

        return [
            'ok' => true,
            'status' => 'release_archive_apply',
            'message' => sprintf( 'Release %s was installed from a trusted GitHub archive.', $tag ),
            'release' => $release,
            'backup' => $backup,
            'archive' => $archive_result,
            'repository' => [
                'available' => false,
                'mode' => 'archive',
            ],
            'baseline_built' => $baseline_built,
            'baseline_signed' => $baseline_signed,
        ];
    }

    private function progress( string $stage, string $message, int $percent, array $context = [] ): void {
        if ( ! \is_callable( $this->progressReporter ) ) {
            return;
        }

        try {
            ( $this->progressReporter )( [
                'stage' => $stage,
                'message' => $message,
                'percent' => max( 0, min( 100, $percent ) ),
                'context' => $context,
                'updated_at' => \function_exists( 'metis_current_time' ) ? \metis_current_time( 'mysql' ) : \gmdate( 'Y-m-d H:i:s' ),
            ] );
        } catch ( \Throwable ) {
        }
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
                    'last_action_at' => \metis_current_time( 'mysql' ),
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
            'occurred_at' => \metis_current_time( 'mysql' ),
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
            'last_action_at' => \metis_current_time( 'mysql' ),
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
            return $this->discoverLocalReleasesFromGitFiles();
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

        $manifest_releases = $this->discoverRemoteReleasesFromManifest( $force_refresh );
        if ( $manifest_releases !== [] ) {
            return [
                'status' => 'manifest',
                'releases' => $manifest_releases,
            ];
        }

        $remote = $this->remoteName();
        if ( $remote === '' ) {
            $github_releases = $this->discoverRemoteReleasesFromGitHub();
            if ( $github_releases !== [] ) {
                return [
                    'status' => 'api',
                    'releases' => $github_releases,
                ];
            }

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
            $github_releases = $this->discoverRemoteReleasesFromGitHub();
            if ( $github_releases !== [] ) {
                return [
                    'status' => 'api',
                    'releases' => $github_releases,
                ];
            }

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

    private function discoverRemoteReleasesFromManifest( bool $force_refresh ): array {
        if ( ! \class_exists( \Metis\Core\Application::class ) || ! \Metis\Core\Application::has_service( 'github_update' ) ) {
            return [];
        }

        try {
            $releases = \Metis\Core\Application::service( 'github_update' )->manifestReleases( $force_refresh );
            return \is_array( $releases ) ? $releases : [];
        } catch ( \Throwable ) {
            return [];
        }
    }

    private function releaseExecution(): \Metis\Core\Services\ReleaseExecutionService {
        if ( \class_exists( '\Metis\Core\Application' ) && \Metis\Core\Application::has_service( 'release_execution' ) ) {
            return \Metis\Core\Application::service( 'release_execution' );
        }

        return new \Metis\Core\Services\ReleaseExecutionService();
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
                'version' => Version::current(),
                'commit' => (string) ( $state['installed_commit'] ?? '' ),
                'source' => 'state',
                'trusted' => true,
            ];
        }

        $installed_version = Version::current();

        if ( $installed_version === '' || $installed_version === '0.0.0' ) {
            return null;
        }

        return [
            'tag' => '',
            'version' => $installed_version,
            'commit' => (string) ( $repository['commit'] ?? '' ),
            'source' => 'version_service',
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
        $version = Version::current();
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
                    'synced_at' => \metis_current_time( 'mysql' ),
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
                \metis_runtime_make_dir( $directory );
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

        $path = \METIS_CONFIG_PATH . 'release.php';
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
            $cached = $this->repositoryStateFromGitFiles();
            return $cached;
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
            'dirty_known' => true,
        ];

        return $cached;
    }

    private function repositoryStateFromGitFiles(): ?array {
        $git_dir = $this->gitDirectoryPath();
        if ( $git_dir === '' ) {
            return null;
        }

        $head = $this->readGitHead();
        $commit = (string) ( $head['commit'] ?? '' );
        if ( $commit === '' ) {
            return null;
        }

        return [
            'top_level' => rtrim( (string) \METIS_PATH, '/' ),
            'commit' => $commit,
            'dirty' => '',
            'exact_tag' => $this->tagForCommit( $commit ),
            'remote' => $this->gitRemoteNameFromConfig( $git_dir ),
            'dirty_known' => false,
        ];
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
        return preg_match( '/^\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?$/', $version ) === 1 ? $version : '';
    }

    private function readState(): array {
        return $this->readJsonFile( $this->statePath() );
    }

    private function persistState( array $state ): void {
        $state['updated_at'] = \metis_current_time( 'mysql' );
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
            \metis_runtime_make_dir( $dir );
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
            return $this->runCommandWithExec( $command );
        }

        $descriptors = [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];

        $process = @\proc_open( $command, $descriptors, $pipes, \METIS_PATH );
        if ( ! \is_resource( $process ) ) {
            return $this->runCommandWithExec( $command );
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

    private function runCommandWithExec( array $command ): array {
        return [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'proc_open unavailable',
        ];
    }

    private function discoverLocalReleasesFromGitFiles(): array {
        $tags = $this->gitTagMap();
        if ( $tags === [] ) {
            return [];
        }

        $releases = [];
        foreach ( $tags as $tag => $commit ) {
            $tag = $this->normalizeTag( (string) $tag );
            $version = $this->versionFromTag( $tag );
            if ( $tag === '' || $version === '' ) {
                continue;
            }

            $releases[ $tag ] = [
                'tag' => $tag,
                'version' => $version,
                'commit' => trim( (string) $commit ),
                'source' => 'local_tag',
                'trusted' => true,
                'cached' => false,
            ];
        }

        return array_values( $releases );
    }

    private function discoverRemoteReleasesFromGitHub(): array {
        if ( ! \class_exists( \Metis\Core\Application::class ) || ! \Metis\Core\Application::has_service( 'github_update' ) ) {
            return [];
        }

        try {
            $releases = \Metis\Core\Application::service( 'github_update' )->semanticTagReleases( true );
            if ( \is_array( $releases ) && $releases !== [] ) {
                return $releases;
            }
        } catch ( \Throwable ) {
        }

        try {
            $payload = \Metis\Core\Application::service( 'github_update' )->checkForUpdates( true );
        } catch ( \Throwable ) {
            return [];
        }

        $tag = $this->normalizeTag( (string) ( $payload['tag_name'] ?? '' ) );
        $version = $this->versionFromTag( $tag );
        if ( $tag === '' || $version === '' ) {
            return [];
        }

        return [
            [
                'tag' => $tag,
                'version' => $version,
                'commit' => '',
                'source' => 'remote_tag',
                'trusted' => true,
                'cached' => false,
            ],
        ];
    }

    private function gitDirectoryPath(): string {
        $git_path = rtrim( (string) \METIS_PATH, '/' ) . '/.git';
        if ( \is_dir( $git_path ) ) {
            return $git_path;
        }

        if ( ! \is_file( $git_path ) ) {
            return '';
        }

        $pointer = @\file_get_contents( $git_path );
        if ( ! \is_string( $pointer ) || ! str_starts_with( trim( $pointer ), 'gitdir:' ) ) {
            return '';
        }

        $git_dir = trim( substr( trim( $pointer ), 7 ) );
        if ( $git_dir === '' ) {
            return '';
        }

        if ( ! preg_match( '#^([A-Za-z]:)?[\\\\/]#', $git_dir ) ) {
            $git_dir = rtrim( (string) \METIS_PATH, '/' ) . '/' . ltrim( $git_dir, '/' );
        }

        $git_dir = str_replace( '\\', '/', $git_dir );
        return \is_dir( $git_dir ) ? rtrim( $git_dir, '/' ) : '';
    }

    private function readGitHead(): array {
        $git_dir = $this->gitDirectoryPath();
        if ( $git_dir === '' ) {
            return [];
        }

        $head_path = $git_dir . '/HEAD';
        $head = @\file_get_contents( $head_path );
        if ( ! \is_string( $head ) ) {
            return [];
        }

        $head = trim( $head );
        if ( str_starts_with( $head, 'ref:' ) ) {
            $ref = trim( substr( $head, 4 ) );
            $commit = $this->resolveGitRef( $git_dir, $ref );
            return [ 'ref' => $ref, 'commit' => $commit ];
        }

        return [ 'ref' => '', 'commit' => $head ];
    }

    private function resolveGitRef( string $git_dir, string $ref ): string {
        $ref_path = $git_dir . '/' . ltrim( $ref, '/' );
        if ( \is_file( $ref_path ) ) {
            $value = @\file_get_contents( $ref_path );
            return \is_string( $value ) ? trim( $value ) : '';
        }

        foreach ( $this->packedRefs( $git_dir ) as $packed_ref => $hash ) {
            if ( $packed_ref === $ref ) {
                return $hash;
            }
        }

        return '';
    }

    private function packedRefs( string $git_dir ): array {
        $path = $git_dir . '/packed-refs';
        if ( ! \is_file( $path ) ) {
            return [];
        }

        $raw = @\file_get_contents( $path );
        if ( ! \is_string( $raw ) || $raw === '' ) {
            return [];
        }

        $refs = [];
        foreach ( preg_split( '/\R+/', $raw ) ?: [] as $line ) {
            $line = trim( $line );
            if ( $line === '' || $line[0] === '#' || $line[0] === '^' ) {
                continue;
            }

            [ $hash, $ref ] = array_pad( preg_split( '/\s+/', $line, 2 ) ?: [], 2, '' );
            if ( $hash !== '' && $ref !== '' ) {
                $refs[ $ref ] = $hash;
            }
        }

        return $refs;
    }

    private function gitTagMap(): array {
        $git_dir = $this->gitDirectoryPath();
        if ( $git_dir === '' ) {
            return [];
        }

        $tags = [];
        $tags_root = $git_dir . '/refs/tags';
        if ( \is_dir( $tags_root ) ) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $tags_root, \FilesystemIterator::SKIP_DOTS )
            );

            foreach ( $iterator as $file ) {
                if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
                    continue;
                }

                $tag = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $tags_root ) + 1 ) );
                $hash = @\file_get_contents( $file->getPathname() );
                if ( \is_string( $hash ) ) {
                    $tags[ $tag ] = trim( $hash );
                }
            }
        }

        foreach ( $this->packedRefs( $git_dir ) as $ref => $hash ) {
            if ( str_starts_with( $ref, 'refs/tags/' ) ) {
                $tags[ substr( $ref, 10 ) ] = $hash;
            }
        }

        return $tags;
    }

    private function tagForCommit( string $commit ): string {
        foreach ( $this->gitTagMap() as $tag => $hash ) {
            if ( trim( (string) $hash ) === trim( $commit ) ) {
                return $this->normalizeTag( (string) $tag );
            }
        }

        return '';
    }

    private function gitRemoteNameFromConfig( string $git_dir ): string {
        $config_path = $git_dir . '/config';
        if ( ! \is_file( $config_path ) ) {
            return '';
        }

        $config = @\file_get_contents( $config_path );
        if ( ! \is_string( $config ) || $config === '' ) {
            return '';
        }

        if ( preg_match( '/\\[remote\\s+"([^"]+)"\\]/', $config, $match ) === 1 ) {
            return trim( (string) $match[1] );
        }

        return '';
    }
}
