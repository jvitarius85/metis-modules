<?php
declare(strict_types=1);

namespace Metis\Backup;

final class BackupService {
    private const RUNNING = 'running';
    private const SUCCESS = 'success';
    private const FAILED = 'failed';
    private const RUN_TIMEOUT_SECONDS = 3 * 60 * 60;
    private const LOCAL_ARTIFACT_STALE_SECONDS = 30 * 60;
    private const BACKUP_EXECUTION_REFRESH_SECONDS = 10 * 60;
    private const LOCAL_ARTIFACT_RETAINED_ERROR = 'Backup local artifacts were created, but Drive upload/finalization did not complete. Local artifact retained for review.';
    private const STAGE_HEALTH_CHECK = 'health_check';
    private const STAGE_LOCAL_GENERATION = 'local_generation';
    private const STAGE_VERIFY = 'verify';
    private const STAGE_UPLOAD = 'upload';
    private const PAUSED_SETTING = 'backup_paused_until_fix';
    private const PAUSED_REASON_SETTING = 'backup_paused_reason';
    private const PAUSED_AT_SETTING = 'backup_paused_at';

    private function database(): \Metis\Services\DatabaseService {
        return \function_exists( 'metis_db' ) ? \metis_db() : new \Metis\Services\DatabaseService();
    }

    public function ensureSchema(): void {
        static $done = false;
        if ( $done ) {
            return;
        }

        $table   = \Metis_Tables::get( 'backup_runs' );
        $charset = $this->database()->get_charset_collate();

        \metis_db_delta(
            "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_uuid VARCHAR(64) NOT NULL,
                environment VARCHAR(64) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'running',
                trigger_source VARCHAR(64) NOT NULL DEFAULT 'manual',
                version VARCHAR(32) DEFAULT NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                drive_id VARCHAR(191) DEFAULT NULL,
                drive_run_folder_id VARCHAR(191) DEFAULT NULL,
                local_path TEXT DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                components_json LONGTEXT DEFAULT NULL,
                restore_json LONGTEXT DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY run_uuid (run_uuid),
                KEY environment_status (environment, status),
                KEY completed_at (completed_at)
            ) {$charset};"
        );

        $done = true;
    }

    public function runBackup( string $trigger = 'manual' ): array {
        $this->initializeLongRunningExecution();
        $this->ensureSchema();
        $this->reconcileStaleRuns();

        if ( ! \class_exists( 'Metis_Tables' ) ) {
            return [ 'ok' => false, 'error' => 'Backup prerequisites are not loaded.' ];
        }

        $pause = $this->backupPauseStatus();
        if ( $this->isScheduledTrigger( $trigger ) && ! empty( $pause['paused'] ) ) {
            return [
                'ok'      => false,
                'status'  => 'paused',
                'paused'  => true,
                'error'   => 'Scheduled backups are paused: ' . (string) ( $pause['reason'] ?? 'manual repair is required.' ),
            ];
        }

        $active = $this->activeRunningRun();
        if ( $active !== null ) {
            return [
                'ok'        => true,
                'status'    => self::RUNNING,
                'duplicate' => true,
                'run_uuid'  => (string) ( $active['run_uuid'] ?? '' ),
                'message'   => 'A backup run is already active.',
            ];
        }

        $run_uuid    = $this->buildRunUuid();
        $environment = $this->environmentLabel();
        $started_at  = \metis_current_time( 'mysql' );
        $timestamp_utc = \gmdate( 'c' );
        $local_dir   = $this->runDirectory( $run_uuid );
        $payload_dir = $local_dir . '/payload';

        if ( ! \metis_runtime_make_dir( $payload_dir . '/database' ) ) {
            return [ 'ok' => false, 'error' => 'Could not create the local backup staging directory.' ];
        }

        $run_id = $this->insertRun( [
            'run_uuid'       => $run_uuid,
            'environment'    => $environment,
            'status'         => self::RUNNING,
            'trigger_source' => $trigger,
            'version'        => $this->version(),
            'started_at'     => $started_at,
            'local_path'     => $local_dir,
            'drive_id'       => '',
        ] );

        $component_archives = [];
        $metadata = [
            'run_uuid'           => $run_uuid,
            'created_at_utc'     => $timestamp_utc,
            'created_at_local'   => $started_at,
            'environment'        => $environment,
            'site_url'           => \metis_home_url( '/' ),
            'version'            => $this->version(),
            'trigger_source'     => $trigger,
            'directory_layout'   => [
                'database' => 'database/',
                'config'   => 'config/',
                'media'    => 'media/',
                'runtime'  => 'runtime/',
                'full'     => 'full/',
            ],
            'restore_order'      => [ 'config', 'media', 'runtime', 'database' ],
            'component_archives' => [],
        ];
        $this->updateRunProgress( $run_id, $metadata, $component_archives, 'initializing', 'Preparing local backup workspace.' );

        $queued = $this->enqueueStage( $run_uuid, self::STAGE_HEALTH_CHECK );
        if ( empty( $queued['ok'] ) ) {
            $reason = 'Backup failed because the stage worker could not be queued.';
            $this->failRun( $run_id, $run_uuid, $local_dir, $metadata, $component_archives, 'stage_queue_failed', $reason, true );
            return [
                'ok'       => false,
                'status'   => self::FAILED,
                'run_uuid' => $run_uuid,
                'error'    => $reason,
            ];
        }

        return [
            'ok'        => true,
            'status'    => 'queued',
            'run_uuid'  => $run_uuid,
            'stage'     => self::STAGE_HEALTH_CHECK,
            'message'   => 'Backup pipeline queued.',
            'job'       => $queued,
        ];
    }

    public function runBackupStage( string $run_uuid, string $stage ): array {
        $this->initializeLongRunningExecution();
        $this->ensureSchema();
        $this->reconcileStaleRuns();

        $run_uuid = trim( $run_uuid );
        $stage = $this->normalizeBackupStage( $stage );
        if ( $run_uuid === '' || $stage === '' ) {
            return [ 'ok' => false, 'status' => self::FAILED, 'error' => 'Backup stage payload is invalid.' ];
        }

        $row = $this->findRun( $run_uuid );
        if ( $row === null ) {
            return [ 'ok' => false, 'status' => self::FAILED, 'error' => 'Backup run not found.' ];
        }
        if ( (string) ( $row['status'] ?? '' ) !== self::RUNNING ) {
            return [
                'ok'       => true,
                'status'   => 'skipped',
                'run_uuid' => $run_uuid,
                'stage'    => $stage,
                'message'  => 'Backup stage skipped because the run is no longer active.',
            ];
        }

        return match ( $stage ) {
            self::STAGE_HEALTH_CHECK => $this->runHealthCheckStage( $row ),
            self::STAGE_LOCAL_GENERATION => $this->runLocalGenerationStage( $row ),
            self::STAGE_VERIFY => $this->runVerifyStage( $row ),
            self::STAGE_UPLOAD => $this->runUploadStage( $row ),
            default => [ 'ok' => false, 'status' => self::FAILED, 'error' => 'Unknown backup stage.' ],
        };
    }

    public function pauseStatus(): array {
        return $this->backupPauseStatus();
    }

    public function listRuns( int $limit = 20 ): array {
        $this->ensureSchema();
        $this->reconcileStaleRuns();

        $table = \Metis_Tables::get( 'backup_runs' );
        $limit = max( 1, min( 100, $limit ) );
        $rows  = $this->database()->fetchAll( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", [ $limit ] );

        return array_values( array_map( fn ( array $row ): array => $this->normalizeRunRow( $row ), $rows ?: [] ) );
    }

    private function runHealthCheckStage( array $row ): array {
        $run_id = (int) ( $row['id'] ?? 0 );
        $run_uuid = (string) ( $row['run_uuid'] ?? '' );
        $local_dir = (string) ( $row['local_path'] ?? '' );
        $metadata = $this->decode( (string) ( $row['metadata_json'] ?? '' ) );
        $components = $this->decode( (string) ( $row['components_json'] ?? '' ) );

        try {
            $this->updateRunProgress( $run_id, $metadata, $components, self::STAGE_HEALTH_CHECK, 'Running backup health check.' );
            if ( ! \class_exists( '\ZipArchive' ) ) {
                throw new \RuntimeException( 'Backup failed because PHP ZipArchive is not available.' );
            }
            if ( ! \metis_runtime_make_dir( rtrim( $local_dir, '/\\' ) . '/payload/database' ) ) {
                throw new \RuntimeException( 'Backup failed because the local backup staging directory could not be created.' );
            }
            if ( ! \is_dir( $this->metisPath() ) || ! \is_readable( $this->metisPath() ) ) {
                throw new \RuntimeException( 'Backup failed because the application root is not readable.' );
            }
            if ( ! \is_dir( $this->configPath() ) || ! \is_readable( $this->configPath() ) ) {
                throw new \RuntimeException( 'Backup failed because the configuration directory is not readable.' );
            }
            $this->ensureBackupSourceDirectories();
            $drive_cfg = $this->resolveDriveConfig();
            if ( empty( $drive_cfg['ok'] ) ) {
                throw new \RuntimeException( 'Backup failed because the configured Google Drive backup target is unavailable.' );
            }

            $this->updateRun( $run_id, [ 'drive_id' => (string) ( $drive_cfg['shared_drive_id'] ?? '' ) ] );
            $this->updateRunProgress( $run_id, $metadata, $components, 'health_check_passed', 'Backup health check passed.' );
            $queued = $this->enqueueStage( $run_uuid, self::STAGE_LOCAL_GENERATION );
            if ( empty( $queued['ok'] ) ) {
                throw new \RuntimeException( 'Backup failed because the local generation stage could not be queued.' );
            }

            return [
                'ok' => true,
                'status' => 'queued',
                'run_uuid' => $run_uuid,
                'stage' => self::STAGE_HEALTH_CHECK,
                'next_stage' => self::STAGE_LOCAL_GENERATION,
                'job' => $queued,
            ];
        } catch ( \Throwable $e ) {
            return $this->failRun( $run_id, $run_uuid, $local_dir, $metadata, $components, 'health_check_failed', $this->publicFailureReason( self::STAGE_HEALTH_CHECK, $e ), true );
        }
    }

    private function runLocalGenerationStage( array $row ): array {
        $run_id = (int) ( $row['id'] ?? 0 );
        $run_uuid = (string) ( $row['run_uuid'] ?? '' );
        $local_dir = (string) ( $row['local_path'] ?? '' );
        $metadata = $this->decode( (string) ( $row['metadata_json'] ?? '' ) );
        $components = $this->decode( (string) ( $row['components_json'] ?? '' ) );

        try {
            $snapshot = $this->localArtifactSnapshot( $local_dir, $run_uuid, $metadata, $components );
            if ( empty( $snapshot['full_available'] ) ) {
                [ $metadata, $components ] = $this->createLocalBackupArtifacts( $run_id, $run_uuid, $local_dir, $metadata, $components );
            } else {
                $metadata = (array) ( $snapshot['metadata'] ?? $metadata );
                $components = (array) ( $snapshot['components'] ?? $components );
                $this->updateRunProgress( $run_id, $metadata, $components, 'local_artifact_reused', 'Existing local backup archive found; reusing it for verification.' );
            }

            $queued = $this->enqueueStage( $run_uuid, self::STAGE_VERIFY );
            if ( empty( $queued['ok'] ) ) {
                throw new \RuntimeException( 'Backup failed because the verification stage could not be queued.' );
            }

            return [
                'ok' => true,
                'status' => 'queued',
                'run_uuid' => $run_uuid,
                'stage' => self::STAGE_LOCAL_GENERATION,
                'next_stage' => self::STAGE_VERIFY,
                'job' => $queued,
            ];
        } catch ( \Throwable $e ) {
            return $this->failRun( $run_id, $run_uuid, $local_dir, $metadata, $components, 'local_generation_failed', $this->publicFailureReason( self::STAGE_LOCAL_GENERATION, $e ), true );
        }
    }

    private function runVerifyStage( array $row ): array {
        $run_id = (int) ( $row['id'] ?? 0 );
        $run_uuid = (string) ( $row['run_uuid'] ?? '' );
        $local_dir = (string) ( $row['local_path'] ?? '' );
        $metadata = $this->decode( (string) ( $row['metadata_json'] ?? '' ) );
        $components = $this->decode( (string) ( $row['components_json'] ?? '' ) );

        try {
            $snapshot = $this->localArtifactSnapshot( $local_dir, $run_uuid, $metadata, $components );
            $metadata = (array) ( $snapshot['metadata'] ?? $metadata );
            $components = (array) ( $snapshot['components'] ?? $components );
            $this->updateRunProgress( $run_id, $metadata, $components, self::STAGE_VERIFY, 'Verifying local backup artifacts.' );
            $this->verifyLocalBackupArtifacts( $components );
            $this->updateRunProgress( $run_id, $metadata, $components, 'verification_passed', 'Local backup artifacts verified.' );

            $queued = $this->enqueueStage( $run_uuid, self::STAGE_UPLOAD );
            if ( empty( $queued['ok'] ) ) {
                throw new \RuntimeException( 'Backup failed because the upload stage could not be queued.' );
            }

            return [
                'ok' => true,
                'status' => 'queued',
                'run_uuid' => $run_uuid,
                'stage' => self::STAGE_VERIFY,
                'next_stage' => self::STAGE_UPLOAD,
                'job' => $queued,
            ];
        } catch ( \Throwable $e ) {
            return $this->failRun( $run_id, $run_uuid, $local_dir, $metadata, $components, 'verification_failed', $this->publicFailureReason( self::STAGE_VERIFY, $e ), true );
        }
    }

    private function runUploadStage( array $row ): array {
        $run_id = (int) ( $row['id'] ?? 0 );
        $run_uuid = (string) ( $row['run_uuid'] ?? '' );
        $local_dir = (string) ( $row['local_path'] ?? '' );
        $environment = (string) ( $row['environment'] ?? $this->environmentLabel() );
        $metadata = $this->decode( (string) ( $row['metadata_json'] ?? '' ) );
        $components = $this->decode( (string) ( $row['components_json'] ?? '' ) );

        try {
            $snapshot = $this->localArtifactSnapshot( $local_dir, $run_uuid, $metadata, $components );
            $metadata = (array) ( $snapshot['metadata'] ?? $metadata );
            $components = (array) ( $snapshot['components'] ?? $components );
            $this->verifyLocalBackupArtifacts( $components );

            $drive_cfg = $this->resolveDriveConfig( (string) ( $row['drive_id'] ?? '' ) );
            if ( empty( $drive_cfg['ok'] ) ) {
                throw new \RuntimeException( 'Backup failed because the configured Google Drive backup target is unavailable.' );
            }

            $drive_segments = [
                $environment,
                \gmdate( 'Y' ),
                \gmdate( 'm' ),
                \gmdate( 'd' ),
                $run_uuid,
            ];
            $this->updateRunProgress( $run_id, $metadata, $components, 'drive_folder', 'Creating or locating backup folder in Drive.' );
            $run_folder = $this->ensureDriveFolderPath( $drive_cfg, $drive_segments );
            if ( empty( $run_folder['ok'] ) ) {
                throw new \RuntimeException( 'Backup failed because the Drive backup folder could not be created.' );
            }

            $root_folder_id = (string) ( $run_folder['folder_id'] ?? '' );
            $metadata['drive'] = [
                'drive_id'       => (string) ( $drive_cfg['shared_drive_id'] ?? '' ),
                'run_folder_id'  => $root_folder_id,
                'shared_drive'   => (string) ( $drive_cfg['shared_drive_label'] ?? $drive_cfg['shared_drive_name'] ?? '' ),
            ];
            $metadata['integrity'] = [
                'created'  => true,
                'uploaded' => false,
            ];

            $this->updateRunProgress( $run_id, $metadata, $components, 'drive_upload_metadata', 'Uploading backup metadata to Drive.' );
            $this->uploadJsonArtifact( $drive_cfg, $root_folder_id, 'metadata.json', $metadata );
            $this->uploadJsonArtifact( $drive_cfg, $root_folder_id, 'checksums.json', $this->checksumPayload( $run_uuid, $metadata, $components ) );

            $this->updateRunProgress( $run_id, $metadata, $components, 'drive_upload_full', 'Uploading full backup archive to Drive.' );
            $upload = $this->uploadFileToDrive(
                $drive_cfg,
                $root_folder_id,
                (string) ( $components['full']['local_path'] ?? '' ),
                (string) ( $components['full']['archive_name'] ?? 'full.zip' ),
                'application/zip'
            );
            if ( empty( $upload['ok'] ) ) {
                throw new \RuntimeException( 'Backup failed because the full archive could not be uploaded to Drive.' );
            }

            $components['full']['drive_file_id'] = (string) ( $upload['id'] ?? '' );
            $components['full']['drive_web_view_link'] = (string) ( $upload['webViewLink'] ?? '' );
            $components['full']['drive_folder_id'] = $root_folder_id;

            $completed_at = \metis_current_time( 'mysql' );
            $metadata['completed_at_local'] = $completed_at;
            $metadata['integrity'] = [
                'created'  => true,
                'uploaded' => true,
            ];
            $metadata['component_archives'] = $components;
            $metadata['progress'] = [
                'stage'      => 'completed',
                'message'    => 'Backup completed and uploaded.',
                'updated_at' => $completed_at,
            ];

            $this->updateRun( $run_id, [
                'status'              => self::SUCCESS,
                'completed_at'        => $completed_at,
                'drive_run_folder_id' => $root_folder_id,
                'metadata_json'       => $this->encode( $metadata ),
                'components_json'     => $this->encode( $components ),
                'last_error'          => '',
            ] );

            $this->clearBackupPause();
            $this->applyRetentionPolicy( $environment, $run_id, $drive_cfg );
            $this->cleanupLocalRunArtifacts( $run_id, $run_uuid, $local_dir );

            return [
                'ok' => true,
                'status' => self::SUCCESS,
                'run_uuid' => $run_uuid,
                'stage' => self::STAGE_UPLOAD,
                'completed_at' => $completed_at,
                'drive_folder_id' => $root_folder_id,
            ];
        } catch ( \Throwable $e ) {
            return $this->failRun( $run_id, $run_uuid, $local_dir, $metadata, $components, 'upload_failed', $this->publicFailureReason( self::STAGE_UPLOAD, $e ), true );
        }
    }

    private function createLocalBackupArtifacts( int $run_id, string $run_uuid, string $local_dir, array $metadata, array $component_archives ): array {
        $payload_dir = rtrim( $local_dir, '/\\' ) . '/payload';
        if ( ! \metis_runtime_make_dir( $payload_dir . '/database' ) ) {
            throw new \RuntimeException( 'Backup failed because the local backup staging directory could not be created.' );
        }

        $timestamp_utc = (string) ( $metadata['created_at_utc'] ?? \gmdate( 'c' ) );
        $checksums = [];

        $this->ensureBackupSourceDirectories();

        $this->updateRunProgress( $run_id, $metadata, $component_archives, 'database_snapshot', 'Creating database snapshot.' );
        $database_file = $this->buildDatabaseSnapshot( $payload_dir . '/database', $run_uuid );
        $component_archives['database'] = $this->describeFile( 'database', $database_file );
        $this->updateRunProgress( $run_id, $metadata, $component_archives, 'component_archives', 'Database snapshot created.' );

        $config_archive = $payload_dir . '/config.zip';
        $this->zipDirectory( $this->configPath(), $config_archive, [], [ 'index.php' ] );
        $component_archives['config'] = $this->describeFile( 'config', $config_archive );

        $this->updateRunProgress( $run_id, $metadata, $component_archives, 'component_archives', 'Archiving media and runtime directories.' );
        $media_archive = $payload_dir . '/media.zip';
        $this->zipDirectory( $this->metisPath( 'storage/media' ), $media_archive );
        $component_archives['media'] = $this->describeFile( 'media', $media_archive );

        foreach ( [
            'public_media' => 'storage/public-media',
            'protected_media' => 'storage/protected-media',
            'private_records' => 'storage/private-records',
        ] as $component => $storage_path ) {
            $archive = $payload_dir . '/' . $component . '.zip';
            $this->zipDirectory( $this->metisPath( $storage_path ), $archive );
            $component_archives[ $component ] = $this->describeFile( $component, $archive );
        }

        $runtime_archive = $payload_dir . '/runtime.zip';
        $this->zipDirectory(
            $this->metisPath( 'storage/runtime' ),
            $runtime_archive,
            [ 'backups' ]
        );
        $component_archives['runtime'] = $this->describeFile( 'runtime', $runtime_archive );

        $this->updateRunProgress( $run_id, $metadata, $component_archives, 'metadata', 'Writing local backup metadata.' );

        $metadata_path = $payload_dir . '/metadata.json';
        $metadata['component_archives'] = $component_archives;
        $this->writeJsonFile( $metadata_path, $metadata );

        foreach ( $component_archives as $component => $details ) {
            $checksums[ $component ] = [
                'archive' => (string) ( $details['archive_name'] ?? '' ),
                'sha256'  => (string) ( $details['sha256'] ?? '' ),
                'bytes'   => (int) ( $details['bytes'] ?? 0 ),
            ];
        }

        $checksums_path = $payload_dir . '/checksums.json';
        $this->writeJsonFile( $checksums_path, [
            'run_uuid'       => $run_uuid,
            'generated_at'   => $timestamp_utc,
            'component_hash' => $checksums,
        ] );

        $full_archive = $payload_dir . '/full.zip';
        $this->updateRunProgress( $run_id, $metadata, $component_archives, 'full_archive', 'Building full local backup archive.' );
        $this->buildFullArchive( $full_archive, $database_file, $metadata_path, $checksums_path );
        $component_archives['full'] = $this->describeFile( 'full', $full_archive );
        $metadata['integrity'] = [
            'created'  => true,
            'uploaded' => false,
        ];
        $metadata['component_archives'] = $component_archives;
        $this->writeJsonFile( $metadata_path, $metadata );
        $this->updateRunProgress( $run_id, $metadata, $component_archives, 'local_generation_complete', 'Full local backup archive created.' );

        return [ $metadata, $component_archives ];
    }

    private function verifyLocalBackupArtifacts( array $components ): void {
        foreach ( [ 'database', 'config', 'media', 'public_media', 'protected_media', 'private_records', 'runtime', 'full' ] as $component ) {
            $path = trim( (string) ( $components[ $component ]['local_path'] ?? '' ) );
            if ( $path === '' || ! \is_file( $path ) ) {
                throw new \RuntimeException( 'Backup failed because a required local artifact is missing: ' . $component );
            }
            if ( (int) @filesize( $path ) < 1 ) {
                throw new \RuntimeException( 'Backup failed because a required local artifact is empty: ' . $component );
            }

            $expected_hash = trim( (string) ( $components[ $component ]['sha256'] ?? '' ) );
            if ( $expected_hash !== '' ) {
                $actual_hash = \hash_file( 'sha256', $path );
                if ( ! \is_string( $actual_hash ) || ! \hash_equals( $expected_hash, $actual_hash ) ) {
                    throw new \RuntimeException( 'Backup failed because a local artifact checksum did not match: ' . $component );
                }
            }
        }

        $zip = new \ZipArchive();
        $full_path = (string) ( $components['full']['local_path'] ?? '' );
        if ( $zip->open( $full_path ) !== true ) {
            throw new \RuntimeException( 'Backup failed because the full local archive could not be opened.' );
        }

        foreach ( [ 'metadata.json', 'checksums.json', 'database/database.sql.gz' ] as $entry ) {
            if ( $zip->locateName( $entry ) === false ) {
                $zip->close();
                throw new \RuntimeException( 'Backup failed because the full archive is missing ' . $entry . '.' );
            }
        }
        $zip->close();
    }

    private function checksumPayload( string $run_uuid, array $metadata, array $components ): array {
        $hashes = [];
        foreach ( $components as $component => $details ) {
            if ( ! \is_array( $details ) ) {
                continue;
            }
            $hashes[ (string) $component ] = [
                'archive' => (string) ( $details['archive_name'] ?? '' ),
                'sha256'  => (string) ( $details['sha256'] ?? '' ),
                'bytes'   => (int) ( $details['bytes'] ?? 0 ),
            ];
        }

        return [
            'run_uuid'       => $run_uuid,
            'generated_at'   => (string) ( $metadata['created_at_utc'] ?? \gmdate( 'c' ) ),
            'component_hash' => $hashes,
        ];
    }

    private function failRun( int $run_id, string $run_uuid, string $local_dir, array $metadata, array $components, string $stage, string $reason, bool $pause_scheduled ): array {
        $completed_at = \metis_current_time( 'mysql' );
        $snapshot = $this->localArtifactSnapshot( $local_dir, $run_uuid, $metadata, $components );
        $snapshot_metadata = (array) ( $snapshot['metadata'] ?? $metadata );
        $snapshot_components = (array) ( $snapshot['components'] ?? $components );
        $snapshot_metadata['completed_at_local'] = $completed_at;
        $snapshot_metadata['integrity'] = [
            'created'  => ! empty( $snapshot['full_available'] ),
            'uploaded' => false,
        ];
        $this->updateRunProgress( $run_id, $snapshot_metadata, $snapshot_components, $stage, $reason );

        $payload = [
            'status'          => self::FAILED,
            'completed_at'    => $completed_at,
            'metadata_json'   => $this->encode( $snapshot_metadata ),
            'components_json' => $this->encode( $snapshot_components ),
            'last_error'      => $reason,
        ];

        if ( empty( $snapshot['full_available'] ) ) {
            $this->cleanupLocalRunArtifacts( $run_id, $run_uuid, $local_dir );
            $payload['local_path'] = '';
        }

        $this->updateRun( $run_id, $payload );

        if ( $pause_scheduled ) {
            $this->pauseScheduledBackups( $reason );
        }

        if ( \class_exists( 'Metis_Logger' ) ) {
            \Metis_Logger::error( 'Backup stage failed', [
                'run_uuid' => $run_uuid,
                'stage' => $stage,
                'reason' => $reason,
                'local_artifact_available' => ! empty( $snapshot['full_available'] ),
            ] );
        }

        return [
            'ok' => false,
            'status' => self::FAILED,
            'run_uuid' => $run_uuid,
            'stage' => $stage,
            'error' => $reason,
            'paused' => $pause_scheduled,
            'local_artifact_available' => ! empty( $snapshot['full_available'] ),
        ];
    }

    private function enqueueStage( string $run_uuid, string $stage ): array {
        if ( ! \function_exists( 'metis_operations' ) ) {
            return [ 'ok' => false, 'message' => 'Operations service is unavailable.' ];
        }

        return \metis_operations()->queueOperation(
            'backup.stage',
            [
                'run_uuid' => $run_uuid,
                'stage'    => $this->normalizeBackupStage( $stage ),
            ],
            [
                'created_by' => 0,
                'dedupe_key' => 'operation:backup.stage:' . $run_uuid . ':' . $this->normalizeBackupStage( $stage ),
            ]
        );
    }

    private function activeRunningRun(): ?array {
        $table = \Metis_Tables::get( 'backup_runs' );
        $cutoff = $this->formatTimestamp( time() - self::RUN_TIMEOUT_SECONDS );
        $row = $this->database()->fetchOne(
            "SELECT *
             FROM {$table}
             WHERE status = %s
               AND started_at >= %s
             ORDER BY id DESC
             LIMIT 1",
            [ self::RUNNING, $cutoff ]
        );

        return \is_array( $row ) ? $row : null;
    }

    private function normalizeBackupStage( string $stage ): string {
        $stage = $this->normalizeProgressStage( $stage );
        return \in_array( $stage, [ self::STAGE_HEALTH_CHECK, self::STAGE_LOCAL_GENERATION, self::STAGE_VERIFY, self::STAGE_UPLOAD ], true )
            ? $stage
            : '';
    }

    private function publicFailureReason( string $stage, \Throwable $e ): string {
        $message = trim( $e->getMessage() );
        if ( str_starts_with( $message, 'Backup failed because ' ) ) {
            return $message;
        }

        return match ( $stage ) {
            self::STAGE_HEALTH_CHECK => 'Backup failed because the backup health check did not pass.',
            self::STAGE_LOCAL_GENERATION => 'Backup failed because local backup artifact generation did not complete.',
            self::STAGE_VERIFY => 'Backup failed because local backup artifact verification did not pass.',
            self::STAGE_UPLOAD => 'Backup failed because the verified backup archive could not be uploaded.',
            default => 'Backup failed because the backup pipeline did not complete.',
        };
    }

    private function pauseScheduledBackups( string $reason ): void {
        if ( \class_exists( '\Core_Settings_Service' ) ) {
            \Core_Settings_Service::set( self::PAUSED_SETTING, true, false );
            \Core_Settings_Service::set( self::PAUSED_REASON_SETTING, $reason, false );
            \Core_Settings_Service::set( self::PAUSED_AT_SETTING, \metis_current_time( 'mysql' ), false );
        }
    }

    private function clearBackupPause(): void {
        if ( \class_exists( '\Core_Settings_Service' ) ) {
            \Core_Settings_Service::set( self::PAUSED_SETTING, false, false );
            \Core_Settings_Service::set( self::PAUSED_REASON_SETTING, '', false );
            \Core_Settings_Service::set( self::PAUSED_AT_SETTING, '', false );
        }
    }

    private function backupPauseStatus(): array {
        if ( ! \class_exists( '\Core_Settings_Service' ) ) {
            return [ 'paused' => false, 'reason' => '', 'paused_at' => '' ];
        }

        return [
            'paused' => (bool) \Core_Settings_Service::get( self::PAUSED_SETTING, false ),
            'reason' => (string) \Core_Settings_Service::get( self::PAUSED_REASON_SETTING, '' ),
            'paused_at' => (string) \Core_Settings_Service::get( self::PAUSED_AT_SETTING, '' ),
        ];
    }

    private function isScheduledTrigger( string $trigger ): bool {
        return \in_array( \metis_key_clean( $trigger ), [ 'system_cron', 'scheduled', 'queued' ], true );
    }

    private function reconcileStaleRuns(): void {
        $table = \Metis_Tables::get( 'backup_runs' );
        $rows  = $this->database()->fetchAll(
            "SELECT id, run_uuid, started_at, updated_at, local_path, metadata_json, components_json
             FROM {$table}
             WHERE status = %s
             ORDER BY id DESC
             LIMIT 200",
            [ self::RUNNING ]
        ) ?: [];

        $now = time();
        foreach ( $rows as $row ) {
            $started = (string) ( $row['started_at'] ?? '' );
            $last_activity = (string) ( $row['updated_at'] ?? $started );
            $timezone = \function_exists( 'metis_runtime_timezone' )
                ? \metis_runtime_timezone()
                : new \DateTimeZone( 'UTC' );
            $activity_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $last_activity !== '' ? $last_activity : $started, $timezone );
            $activity_ts = $activity_dt instanceof \DateTimeImmutable ? $activity_dt->getTimestamp() : false;
            if ( $activity_ts === false || $activity_ts < 1 ) {
                continue;
            }

            $run_id   = (int) ( $row['id'] ?? 0 );
            $run_uuid = (string) ( $row['run_uuid'] ?? '' );
            $local_path = trim( (string) ( $row['local_path'] ?? '' ) );
            if ( $run_id < 1 ) {
                continue;
            }

            $snapshot = $this->localArtifactSnapshot(
                $local_path,
                $run_uuid,
                $this->decode( (string) ( $row['metadata_json'] ?? '' ) ),
                $this->decode( (string) ( $row['components_json'] ?? '' ) )
            );
            $timeout_seconds = ! empty( $snapshot['full_available'] )
                ? self::LOCAL_ARTIFACT_STALE_SECONDS
                : self::RUN_TIMEOUT_SECONDS;
            if ( ( $now - $activity_ts ) < $timeout_seconds ) {
                continue;
            }

            $completed_at = \metis_current_time( 'mysql' );
            if ( ! empty( $snapshot['full_available'] ) ) {
                $snapshot_metadata = (array) ( $snapshot['metadata'] ?? [] );
                $snapshot_components = (array) ( $snapshot['components'] ?? [] );
                $snapshot_metadata['completed_at_local'] = $completed_at;
                $snapshot_metadata['integrity'] = [
                    'created'  => true,
                    'uploaded' => false,
                ];
                $this->updateRunProgress( $run_id, $snapshot_metadata, $snapshot_components, 'stale_after_local_artifact', self::LOCAL_ARTIFACT_RETAINED_ERROR );
                $this->updateRun( $run_id, [
                    'status'          => self::FAILED,
                    'completed_at'    => $completed_at,
                    'metadata_json'   => $this->encode( $snapshot_metadata ),
                    'components_json' => $this->encode( $snapshot_components ),
                    'last_error'      => self::LOCAL_ARTIFACT_RETAINED_ERROR,
                ] );
                $this->pauseScheduledBackups( self::LOCAL_ARTIFACT_RETAINED_ERROR );
            } else {
                $timeout_reason = 'Backup failed because the backup worker stopped without completing local artifact generation.';
                $this->updateRun( $run_id, [
                    'status'       => self::FAILED,
                    'completed_at' => $completed_at,
                    'last_error'   => $timeout_reason,
                ] );
                $this->pauseScheduledBackups( $timeout_reason );
            }

            if ( $local_path !== '' && empty( $snapshot['full_available'] ) ) {
                $this->removeDirectory( $local_path );
            }

            if ( \class_exists( 'Metis_Logger' ) ) {
                \Metis_Logger::warn( 'Backup run reconciled from stale running state', [
                    'run_uuid' => $run_uuid,
                    'started_at' => $started,
                    'timeout_seconds' => $timeout_seconds,
                ] );
            }
        }
    }

    private function cleanupLocalRunArtifacts( int $run_id, string $run_uuid, string $local_dir ): void {
        if ( $local_dir === '' ) {
            return;
        }

        try {
            $this->removeDirectory( $local_dir );
            $this->updateRun( $run_id, [ 'local_path' => '' ] );
        } catch ( \Throwable $e ) {
            if ( \class_exists( 'Metis_Logger' ) ) {
                \Metis_Logger::warn( 'Backup local cleanup failed after successful upload', [
                    'run_uuid' => $run_uuid,
                    'local_path' => $local_dir,
                    'error' => $e->getMessage(),
                ] );
            }
        }
    }

    private function updateRunProgress( int $run_id, array &$metadata, array $components, string $stage, string $message ): void {
        $metadata['component_archives'] = $components;
        $metadata['progress'] = [
            'stage'      => $this->normalizeProgressStage( $stage ),
            'message'    => $message,
            'updated_at' => \metis_current_time( 'mysql' ),
        ];

        $this->updateRun( $run_id, [
            'metadata_json'   => $this->encode( $metadata ),
            'components_json' => $this->encode( $components ),
        ] );
    }

    private function localArtifactSnapshot( string $local_dir, string $run_uuid, array $fallback_metadata = [], array $fallback_components = [] ): array {
        $local_dir = rtrim( trim( $local_dir ), '/\\' );
        $payload_dir = $local_dir !== '' ? $local_dir . '/payload' : '';
        $metadata = $fallback_metadata;
        $components = $fallback_components;

        $metadata_path = $payload_dir !== '' ? $payload_dir . '/metadata.json' : '';
        if ( $metadata_path !== '' && \is_file( $metadata_path ) ) {
            $metadata_contents = \file_get_contents( $metadata_path );
            $metadata_file = \is_string( $metadata_contents ) ? $this->decode( $metadata_contents ) : [];
            if ( $metadata_file !== [] ) {
                $metadata = array_replace_recursive( $metadata, $metadata_file );
            }
        }

        if ( isset( $metadata['component_archives'] ) && \is_array( $metadata['component_archives'] ) ) {
            $components = array_replace( $components, $metadata['component_archives'] );
        }

        $candidate_paths = $payload_dir !== '' ? [
            'database' => $payload_dir . '/database/database.sql.gz',
            'config' => $payload_dir . '/config.zip',
            'media' => $payload_dir . '/media.zip',
            'public_media' => $payload_dir . '/public_media.zip',
            'protected_media' => $payload_dir . '/protected_media.zip',
            'private_records' => $payload_dir . '/private_records.zip',
            'runtime' => $payload_dir . '/runtime.zip',
            'full' => $payload_dir . '/full.zip',
        ] : [];

        foreach ( $candidate_paths as $component => $path ) {
            if ( $path === '' || ! \is_file( $path ) ) {
                continue;
            }

            try {
                $components[ $component ] = array_merge(
                    \is_array( $components[ $component ] ?? null ) ? (array) $components[ $component ] : [],
                    $this->describeFile( $component, $path )
                );
            } catch ( \Throwable ) {
                continue;
            }
        }

        $full_available = isset( $candidate_paths['full'] ) && \is_file( $candidate_paths['full'] );
        $metadata['run_uuid'] = $run_uuid !== '' ? $run_uuid : (string) ( $metadata['run_uuid'] ?? '' );
        $metadata['component_archives'] = $components;
        $metadata['integrity'] = [
            'created'  => $full_available,
            'uploaded' => ! empty( $components['full']['drive_file_id'] ),
        ];

        return [
            'full_available' => $full_available,
            'metadata'       => $metadata,
            'components'     => $components,
        ];
    }

    public function restoreRun( string $run_uuid ): array {
        $this->ensureSchema();

        $run_uuid = trim( $run_uuid );
        if ( $run_uuid === '' ) {
            return [ 'ok' => false, 'error' => 'A backup run ID is required.' ];
        }

        $row = $this->findRun( $run_uuid );
        if ( $row === null ) {
            return [ 'ok' => false, 'error' => 'Backup run not found.' ];
        }

        $components = $this->decode( (string) ( $row['components_json'] ?? '' ) );
        if ( empty( $components['full']['local_path'] ) && empty( $components['full']['drive_file_id'] ) ) {
            return [ 'ok' => false, 'error' => 'The full backup archive is missing for this run.' ];
        }

        $full_archive = (string) ( $components['full']['local_path'] ?? '' );
        if ( $full_archive === '' || ! \is_file( $full_archive ) ) {
            $drive_cfg = $this->resolveDriveConfig( (string) ( $row['drive_id'] ?? '' ) );
            if ( empty( $drive_cfg['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Backup Drive is not configured.' ];
            }

            $download_target = $this->runDirectory( $run_uuid ) . '/downloaded-full.zip';
            $download = $this->downloadDriveFile(
                $drive_cfg,
                (string) ( $components['full']['drive_file_id'] ?? '' ),
                $download_target
            );
            if ( empty( $download['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Could not download the full backup archive.' ];
            }
            $full_archive = $download_target;
        }

        $restore_dir = $this->runDirectory( $run_uuid ) . '/restore-' . \gmdate( 'Ymd-His' );
        if ( ! \metis_runtime_make_dir( $restore_dir ) ) {
            return [ 'ok' => false, 'error' => 'Could not create the restore workspace.' ];
        }

        try {
            $zip = new \ZipArchive();
            if ( $zip->open( $full_archive ) !== true ) {
                throw new \RuntimeException( 'The full backup archive could not be opened.' );
            }
            if ( ! $zip->extractTo( $restore_dir ) ) {
                $zip->close();
                throw new \RuntimeException( 'The full backup archive could not be extracted.' );
            }
            $zip->close();

            $config_source     = $restore_dir . '/config';
            $media_source      = $restore_dir . '/storage/media';
            $public_source     = $restore_dir . '/storage/public-media';
            $protected_source  = $restore_dir . '/storage/protected-media';
            $private_source    = $restore_dir . '/storage/private-records';
            $uploads_source    = $restore_dir . '/storage/uploads';
            $runtime_source    = $restore_dir . '/storage/runtime';
            $database_file     = $restore_dir . '/database/database.sql.gz';

            if ( \is_dir( $config_source ) ) {
                $this->mirrorDirectory( $config_source, $this->configPath() );
            }
            if ( \is_dir( $media_source ) ) {
                $this->mirrorDirectory( $media_source, $this->metisPath( 'storage/media' ) );
            }
            if ( \is_dir( $public_source ) ) {
                $this->mirrorDirectory( $public_source, $this->metisPath( 'storage/public-media' ) );
            }
            if ( \is_dir( $protected_source ) ) {
                $this->mirrorDirectory( $protected_source, $this->metisPath( 'storage/protected-media' ) );
            }
            if ( \is_dir( $private_source ) ) {
                $this->mirrorDirectory( $private_source, $this->metisPath( 'storage/private-records' ) );
            }
            // Backward compatibility for older archives that used storage/uploads.
            if ( \is_dir( $uploads_source ) ) {
                $this->mirrorDirectory( $uploads_source, $this->metisPath( 'storage/public-media' ) );
            }
            if ( \is_dir( $runtime_source ) ) {
                $this->mirrorDirectory( $runtime_source, $this->metisPath( 'storage/runtime' ), [ 'backups' ] );
            }

            if ( ! \is_file( $database_file ) ) {
                throw new \RuntimeException( 'The database snapshot is missing from the full backup archive.' );
            }

            $this->restoreDatabaseFromSnapshot( $database_file );

            $restore_payload = [
                'restored_at'   => \metis_current_time( 'mysql' ),
                'restored_from' => $run_uuid,
                'archive_path'  => $full_archive,
            ];

            $this->updateRun( (int) ( $row['id'] ?? 0 ), [
                'restore_json' => $this->encode( $restore_payload ),
            ] );

            return [
                'ok'         => true,
                'run_uuid'   => $run_uuid,
                'restored_at'=> (string) $restore_payload['restored_at'],
                'archive'    => $full_archive,
            ];
        } catch ( \Throwable $e ) {
            return [
                'ok'       => false,
                'run_uuid' => $run_uuid,
                'error'    => 'Restore operation failed. Review logs for details.',
            ];
        }
    }

    private function buildDatabaseSnapshot( string $directory, string $run_uuid ): string {
        $this->initializeLongRunningExecution();
        $db = $this->database();

        $sql_path = $directory . '/database.sql';
        $handle   = \fopen( $sql_path, 'wb' );
        if ( ! \is_resource( $handle ) ) {
            throw new \RuntimeException( 'Could not create the database snapshot.' );
        }

        $tables = array_values( array_unique( \array_filter( \array_map(
            'strval',
            \class_exists( 'Metis_Tables' ) ? \Metis_Tables::all() : []
        ) ) ) );

        \fwrite( $handle, "-- Metis backup: {$run_uuid}\n" );
        \fwrite( $handle, "-- Generated at " . \gmdate( 'c' ) . "\n\n" );
        \fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n" );

        foreach ( $tables as $table ) {
            $exists = $db->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
            if ( $exists !== $table ) {
                continue;
            }

            $create_row = $db->fetchOne( "SHOW CREATE TABLE `{$table}`" );
            $create_sql = (string) ( $create_row['Create Table'] ?? '' );
            if ( $create_sql === '' ) {
                continue;
            }

            \fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
            \fwrite( $handle, $create_sql . ";\n\n" );

            $batch_size = 500;
            $offset = 0;
            $processed_rows = 0;
            do {
                $this->refreshExecutionBudget();
                $rows = $db->fetchAll(
                    "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                    [ $batch_size, $offset ]
                ) ?: [];

                foreach ( $rows as $row ) {
                    $processed_rows++;
                    if ( ( $processed_rows % 200 ) === 0 ) {
                        $this->refreshExecutionBudget();
                    }
                    $columns = array_map(
                        static fn ( string $column ): string => '`' . str_replace( '`', '``', $column ) . '`',
                        array_keys( $row )
                    );
                    $values = array_map( fn ( mixed $value ): string => $this->sqlValue( $value ), array_values( $row ) );
                    \fwrite(
                        $handle,
                        'INSERT INTO `' . $table . '` (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ");\n"
                    );
                }

                $offset += $batch_size;
            } while ( $rows !== [] );

            \fwrite( $handle, "\n" );
        }

        \fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
        \fclose( $handle );

        $gz_path = $directory . '/database.sql.gz';
        $this->gzipFile( $sql_path, $gz_path );
        @unlink( $sql_path );

        return $gz_path;
    }

    private function buildFullArchive( string $archive_path, string $database_file, string $metadata_path, string $checksums_path ): void {
        $zip = new \ZipArchive();
        if ( $zip->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            throw new \RuntimeException( 'Could not create the full backup archive.' );
        }

        $zip->addFile( $metadata_path, 'metadata.json' );
        $zip->addFile( $checksums_path, 'checksums.json' );
        $zip->addFile( $database_file, 'database/' . basename( $database_file ) );
        $this->addDirectoryToZip( $zip, $this->configPath(), 'config', [ 'index.php' ] );
        $this->addDirectoryToZip( $zip, $this->metisPath( 'storage/media' ), 'storage/media' );
        $this->addDirectoryToZip( $zip, $this->metisPath( 'storage/public-media' ), 'storage/public-media' );
        $this->addDirectoryToZip( $zip, $this->metisPath( 'storage/protected-media' ), 'storage/protected-media' );
        $this->addDirectoryToZip( $zip, $this->metisPath( 'storage/private-records' ), 'storage/private-records' );
        $this->addDirectoryToZip( $zip, $this->metisPath( 'storage/runtime' ), 'storage/runtime', [ 'backups' ] );
        if ( ! $zip->close() ) {
            throw new \RuntimeException( 'Could not finalize archive: ' . basename( $archive_path ) );
        }
    }

    private function zipDirectory( string $source, string $archive_path, array $exclude_dirs = [], array $exclude_files = [] ): void {
        $zip = new \ZipArchive();
        if ( $zip->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            throw new \RuntimeException( 'Could not create archive: ' . basename( $archive_path ) );
        }

        $root_name = pathinfo( basename( $archive_path ), PATHINFO_FILENAME );
        $this->addDirectoryToZip( $zip, $source, $root_name, $exclude_dirs, $exclude_files );
        if ( ! $zip->close() ) {
            throw new \RuntimeException( 'Could not finalize archive: ' . basename( $archive_path ) );
        }
    }

    private function addDirectoryToZip( \ZipArchive $zip, string $source, string $base_in_zip, array $exclude_dirs = [], array $exclude_files = [] ): void {
        $base_in_zip = trim( $base_in_zip, '/\\' );
        if ( ! \is_dir( $source ) ) {
            if ( $base_in_zip !== '' ) {
                $zip->addEmptyDir( $base_in_zip );
            }
            return;
        }

        $source = rtrim( $source, '/\\' );
        $root   = realpath( $source );
        if ( ! \is_string( $root ) || $root === '' ) {
            if ( $base_in_zip !== '' ) {
                $zip->addEmptyDir( $base_in_zip );
            }
            return;
        }

        if ( $base_in_zip !== '' ) {
            $zip->addEmptyDir( $base_in_zip );
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $path = $item->getPathname();
            $relative = ltrim( substr( $path, strlen( $root ) ), DIRECTORY_SEPARATOR );
            if ( $relative === '' ) {
                continue;
            }

            $segments = preg_split( '#[\\\\/]#', $relative ) ?: [];
            $skip = false;
            foreach ( $segments as $segment ) {
                if ( in_array( $segment, $exclude_dirs, true ) ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) {
                continue;
            }

            if ( $item->isFile() && in_array( basename( $path ), $exclude_files, true ) ) {
                continue;
            }

            $zip_path = trim( $base_in_zip . '/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative ), '/' );
            if ( $item->isDir() ) {
                $zip->addEmptyDir( $zip_path );
                continue;
            }

            $zip->addFile( $path, $zip_path );
        }
    }

    private function ensureBackupSourceDirectories(): void {
        foreach ( $this->backupSourceDirectories() as $label => $path ) {
            if ( ! \is_dir( $path ) && ! \metis_runtime_make_dir( $path ) ) {
                throw new \RuntimeException( 'Could not create backup source directory: ' . $label );
            }

            @chmod( $path, 0775 );
            clearstatcache( true, $path );

            if ( ! \is_dir( $path ) ) {
                throw new \RuntimeException( 'Backup source path is not a directory: ' . $label );
            }

            if ( ! \is_readable( $path ) ) {
                throw new \RuntimeException( 'Backup source directory is not readable: ' . $label );
            }
        }
    }

    private function backupSourceDirectories(): array {
        $media_roots = \function_exists( 'metis_media_storage_roots' )
            ? (array) \metis_media_storage_roots( true )
            : [];

        return [
            'storage/media' => (string) ( $media_roots['legacy_media'] ?? $this->metisPath( 'storage/media' ) ),
            'storage/public-media' => (string) ( $media_roots['public'] ?? $this->metisPath( 'storage/public-media' ) ),
            'storage/protected-media' => (string) ( $media_roots['protected'] ?? $this->metisPath( 'storage/protected-media' ) ),
            'storage/private-records' => (string) ( $media_roots['private'] ?? $this->metisPath( 'storage/private-records' ) ),
            'storage/runtime' => $this->metisPath( 'storage/runtime' ),
        ];
    }

    private function uploadJsonArtifact( array $cfg, string $parent_id, string $name, array $payload ): array {
        $path = $this->temporaryFile( 'backup-json-' );
        $this->writeJsonFile( $path, $payload );
        $result = $this->uploadFileToDrive( $cfg, $parent_id, $path, $name, 'application/json' );
        @unlink( $path );
        return $result;
    }

    private function uploadFileToDrive( array $cfg, string $parent_id, string $path, string $name, string $mime ): array {
        if ( ! \is_file( $path ) ) {
            return [ 'ok' => false, 'error' => 'Backup artifact is missing: ' . $name ];
        }

        $bytes = \file_get_contents( $path );
        if ( $bytes === false ) {
            return [ 'ok' => false, 'error' => 'Could not read backup artifact: ' . $name ];
        }

        $token = \metis_drive_google_access_token( $cfg );
        if ( empty( $token['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token error.' ];
        }

        $boundary = 'metis_backup_' . \substr( \md5( $name . '|' . \microtime( true ) ), 0, 12 );
        $meta = [
            'name'    => $name,
            'parents' => [ $parent_id ],
            'driveId' => (string) ( $cfg['shared_drive_id'] ?? '' ),
        ];

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $this->encode( $meta ) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $bytes . "\r\n";
        $body .= "--{$boundary}--";

        $upload_url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&includeItemsFromAllDrives=true&useDomainAdminAccess=true&fields=id,name,mimeType,size,webViewLink,parents,driveId';
        $response = \metis_runtime_remote_post( $upload_url, [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) $token['access_token'],
                'Content-Type'  => 'multipart/related; boundary=' . $boundary,
            ],
            'body' => $body,
        ] );

        if ( \metis_runtime_is_error( $response ) ) {
            return [ 'ok' => false, 'error' => 'Backup artifact upload request failed.' ];
        }

        $status  = (int) \metis_runtime_remote_retrieve_response_code( $response );
        $raw     = (string) \metis_runtime_remote_retrieve_body( $response );
        $decoded = \json_decode( $raw, true );
        if ( $status < 200 || $status >= 300 || ! \is_array( $decoded ) || empty( $decoded['id'] ) ) {
            return [
                'ok'    => false,
                'error' => 'Failed to upload backup artifact.',
            ];
        }

        return [ 'ok' => true ] + $decoded;
    }

    private function ensureDriveFolderPath( array $cfg, array $segments ): array {
        $parent_id = (string) ( $cfg['shared_drive_id'] ?? '' );
        if ( $parent_id === '' ) {
            return [ 'ok' => false, 'error' => 'Shared Drive ID is missing.' ];
        }

        foreach ( $segments as $segment ) {
            $segment = trim( (string) $segment );
            if ( $segment === '' ) {
                continue;
            }

            $folder = $this->findOrCreateDriveFolder( $cfg, $parent_id, $segment );
            if ( empty( $folder['ok'] ) ) {
                return $folder;
            }
            $parent_id = (string) ( $folder['folder_id'] ?? '' );
        }

        return [ 'ok' => true, 'folder_id' => $parent_id ];
    }

    private function findOrCreateDriveFolder( array $cfg, string $parent_id, string $name ): array {
        $query = sprintf(
            "trashed = false and mimeType = 'application/vnd.google-apps.folder' and name = '%s' and '%s' in parents",
            str_replace( "'", "\\'", $name ),
            str_replace( "'", "\\'", $parent_id )
        );

        $find_url = \metis_add_query_arg( [
            'corpora'                   => 'drive',
            'driveId'                   => (string) ( $cfg['shared_drive_id'] ?? '' ),
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives'         => 'true',
            'useDomainAdminAccess'      => 'true',
            'q'                         => $query,
            'fields'                    => 'files(id,name,parents,driveId,webViewLink)',
            'pageSize'                  => 1,
        ], 'https://www.googleapis.com/drive/v3/files' );
        $find = \metis_drive_google_request( 'GET', $find_url, null, $cfg );
        if ( ! empty( $find['ok'] ) ) {
            $existing = (array) ( $find['body']['files'][0] ?? [] );
            if ( ! empty( $existing['id'] ) ) {
                return [
                    'ok'        => true,
                    'folder_id' => (string) $existing['id'],
                ];
            }
        }

        $create_url = \metis_add_query_arg( [
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
            'useDomainAdminAccess'      => 'true',
            'fields'                    => 'id,name,parents,driveId,webViewLink',
        ], 'https://www.googleapis.com/drive/v3/files' );
        $create = \metis_drive_google_request( 'POST', $create_url, $this->encode( [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [ $parent_id ],
        ] ), $cfg );
        if ( empty( $create['ok'] ) || empty( $create['body']['id'] ) ) {
            return [ 'ok' => false, 'error' => 'Could not create a backup folder in Google Drive.' ];
        }

        return [
            'ok'        => true,
            'folder_id' => (string) $create['body']['id'],
        ];
    }

    private function resolveDriveConfig( string $preferred_drive_id = '' ): array {
        if ( ! \function_exists( 'metis_drive_workspace_base_settings' ) || ! \function_exists( 'metis_drive_list_shared_drives' ) ) {
            return [ 'ok' => false, 'error' => 'The Drive integration is not available.' ];
        }

        $drive_id = trim( \Core_Settings_Service::get( 'backup_drive_id', $preferred_drive_id ) );
        if ( $drive_id !== '' ) {
            $base = \metis_drive_workspace_base_settings();
            if ( empty( $base['ok'] ) ) {
                return $base;
            }

            $drives = \metis_drive_list_shared_drives( $base );
            if ( empty( $drives['ok'] ) ) {
                return [ 'ok' => false, 'error' => 'Unable to load Shared Drives.' ];
            }

            foreach ( (array) ( $drives['drives'] ?? [] ) as $drive ) {
                if ( (string) ( $drive['id'] ?? '' ) !== $drive_id ) {
                    continue;
                }

                $base['shared_drive_id'] = $drive_id;
                $base['shared_drive_name'] = trim( (string) ( $drive['name'] ?? '' ) );
                $base['shared_drive_label'] = trim( (string) ( $drive['name'] ?? '' ) );
                return $base;
            }

            return [ 'ok' => false, 'error' => 'The selected backup Shared Drive could not be found.' ];
        }

        $base = \metis_drive_workspace_base_settings();
        if ( empty( $base['ok'] ) ) {
            return $base;
        }

        $drives = \metis_drive_list_shared_drives( $base );
        if ( empty( $drives['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Unable to load Shared Drives.' ];
        }

        foreach ( (array) ( $drives['drives'] ?? [] ) as $drive ) {
            $name = strtolower( trim( (string) ( $drive['name'] ?? '' ) ) );
            if ( $name === 'backups' ) {
                $base['shared_drive_id'] = (string) ( $drive['id'] ?? '' );
                $base['shared_drive_name'] = trim( (string) ( $drive['name'] ?? '' ) );
                $base['shared_drive_label'] = trim( (string) ( $drive['name'] ?? '' ) );
                return $base;
            }
        }

        return [ 'ok' => false, 'error' => 'No Google Shared Drive named "backups" is configured.' ];
    }

    private function applyRetentionPolicy( string $environment, int $current_run_id, array $drive_cfg ): void {
        $retention = max( 1, (int) \Core_Settings_Service::get( 'backup_retention_runs', 14 ) );
        $table = \Metis_Tables::get( 'backup_runs' );
        $rows  = $this->database()->fetchAll(
            "SELECT id, run_uuid, drive_run_folder_id, local_path
                 FROM {$table}
                 WHERE environment = %s
                   AND status = %s
                 ORDER BY id DESC",
            [ $environment, self::SUCCESS ]
        ) ?: [];

        $keep = 0;
        foreach ( $rows as $row ) {
            $run_id = (int) ( $row['id'] ?? 0 );
            if ( $run_id === $current_run_id ) {
                $keep++;
                continue;
            }

            $keep++;
            if ( $keep <= $retention ) {
                continue;
            }

            $drive_folder_id = (string) ( $row['drive_run_folder_id'] ?? '' );
            if ( $drive_folder_id !== '' ) {
                $this->trashDriveItem( $drive_cfg, $drive_folder_id );
            }

            $local_path = trim( (string) ( $row['local_path'] ?? '' ) );
            if ( $local_path !== '' ) {
                $this->removeDirectory( $local_path );
            }
        }
    }

    private function trashDriveItem( array $cfg, string $file_id ): void {
        if ( $file_id === '' ) {
            return;
        }

        $url = \metis_add_query_arg( [
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
            'useDomainAdminAccess'      => 'true',
            'fields'                    => 'id,trashed',
        ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) );

        \metis_drive_google_request( 'PATCH', $url, $this->encode( [ 'trashed' => true ] ), $cfg );
    }

    private function downloadDriveFile( array $cfg, string $file_id, string $destination ): array {
        if ( $file_id === '' ) {
            return [ 'ok' => false, 'error' => 'The backup archive file ID is missing.' ];
        }

        $token = \metis_drive_google_access_token( $cfg );
        if ( empty( $token['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token error.' ];
        }

        $url = \metis_add_query_arg( [
            'alt'                => 'media',
            'supportsAllDrives'  => 'true',
            'useDomainAdminAccess' => 'true',
        ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) );
        $response = \metis_runtime_remote_get( $url, [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) $token['access_token'],
            ],
        ] );
        if ( \metis_runtime_is_error( $response ) ) {
            return [ 'ok' => false, 'error' => 'Backup archive download request failed.' ];
        }

        $status = (int) \metis_runtime_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            return [ 'ok' => false, 'error' => 'Could not download the backup archive from Google Drive.' ];
        }

        $body = (string) \metis_runtime_remote_retrieve_body( $response );
        if ( \file_put_contents( $destination, $body, LOCK_EX ) === false ) {
            return [ 'ok' => false, 'error' => 'Could not save the downloaded backup archive.' ];
        }

        return [ 'ok' => true, 'path' => $destination ];
    }

    private function restoreDatabaseFromSnapshot( string $snapshot ): void {
        $sql = \gzdecode( (string) \file_get_contents( $snapshot ) );
        if ( ! \is_string( $sql ) || trim( $sql ) === '' ) {
            throw new \RuntimeException( 'The database snapshot could not be read.' );
        }

        $dbh = $this->database()->nativeMysqli();
        if ( ! $dbh instanceof \mysqli ) {
            throw new \RuntimeException( 'Restore requires an available mysqli connection.' );
        }

        if ( ! $dbh->multi_query( $sql ) ) {
            throw new \RuntimeException( 'Database restore failed: ' . $dbh->error );
        }

        do {
            if ( $result = $dbh->store_result() ) {
                $result->free();
            }
        } while ( $dbh->more_results() && $dbh->next_result() );

        if ( $dbh->errno ) {
            throw new \RuntimeException( 'Database restore failed: ' . $dbh->error );
        }
    }

    private function mirrorDirectory( string $source, string $destination, array $exclude_dirs = [] ): void {
        if ( ! \is_dir( $source ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $path = $item->getPathname();
            $relative = ltrim( substr( $path, strlen( rtrim( $source, '/\\' ) ) ), DIRECTORY_SEPARATOR );
            if ( $relative === '' ) {
                continue;
            }

            $segments = preg_split( '#[\\\\/]#', $relative ) ?: [];
            if ( array_intersect( $segments, $exclude_dirs ) !== [] ) {
                continue;
            }

            $target = rtrim( $destination, '/\\' ) . DIRECTORY_SEPARATOR . $relative;
            if ( $item->isDir() ) {
                \metis_runtime_make_dir( $target );
                continue;
            }

            \metis_runtime_make_dir( dirname( $target ) );
            if ( ! \copy( $path, $target ) ) {
                throw new \RuntimeException( 'Could not restore file: ' . $relative );
            }
        }
    }

    private function removeDirectory( string $directory ): void {
        if ( ! \is_dir( $directory ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            if ( $item->isDir() ) {
                @rmdir( $item->getPathname() );
            } else {
                @unlink( $item->getPathname() );
            }
        }
        @rmdir( $directory );
    }

    private function insertRun( array $payload ): int {
        $this->database()->insert(
            \Metis_Tables::get( 'backup_runs' ),
            $payload,
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return $this->database()->lastInsertId();
    }

    private function updateRun( int $id, array $payload ): void {
        if ( $id < 1 || $payload === [] ) {
            return;
        }

        $formats = [];
        foreach ( $payload as $value ) {
            $formats[] = is_int( $value ) ? '%d' : '%s';
        }

        $this->database()->update(
            \Metis_Tables::get( 'backup_runs' ),
            $payload,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );
    }

    private function findRun( string $run_uuid ): ?array {
        $row = $this->database()->fetchOne(
            'SELECT * FROM ' . \Metis_Tables::get( 'backup_runs' ) . ' WHERE run_uuid = %s LIMIT 1',
            [ $run_uuid ]
        );

        return is_array( $row ) ? $row : null;
    }

    private function normalizeRunRow( array $row ): array {
        $row['metadata']   = $this->decode( (string) ( $row['metadata_json'] ?? '' ) );
        $row['components'] = $this->decode( (string) ( $row['components_json'] ?? '' ) );
        $row['restore']    = $this->decode( (string) ( $row['restore_json'] ?? '' ) );
        $row['local_artifact_available'] = $this->localArtifactAvailable( $row );
        unset( $row['metadata_json'], $row['components_json'], $row['restore_json'] );
        return $row;
    }

    private function localArtifactAvailable( array $row ): bool {
        $components = \is_array( $row['components'] ?? null ) ? (array) $row['components'] : [];
        $full_path = trim( (string) ( $components['full']['local_path'] ?? '' ) );
        if ( $full_path !== '' && \is_file( $full_path ) ) {
            return true;
        }

        $local_path = trim( (string) ( $row['local_path'] ?? '' ) );
        return $local_path !== '' && \is_file( rtrim( $local_path, '/\\' ) . '/payload/full.zip' );
    }

    private function describeFile( string $component, string $path ): array {
        if ( ! \is_file( $path ) ) {
            throw new \RuntimeException( 'Backup artifact missing: ' . $component );
        }

        $hash = \hash_file( 'sha256', $path );
        if ( ! \is_string( $hash ) || $hash === '' ) {
            throw new \RuntimeException( 'Could not hash backup artifact: ' . $component );
        }

        return [
            'component'    => $component,
            'archive_name' => basename( $path ),
            'local_path'   => $path,
            'bytes'        => (int) @filesize( $path ),
            'sha256'       => $hash,
        ];
    }

    private function gzipFile( string $source, string $destination ): void {
        $input = \fopen( $source, 'rb' );
        $output = \gzopen( $destination, 'wb9' );
        if ( ! \is_resource( $input ) || ! \is_resource( $output ) ) {
            if ( \is_resource( $input ) ) {
                \fclose( $input );
            }
            throw new \RuntimeException( 'Could not compress the database snapshot.' );
        }

        while ( ! \feof( $input ) ) {
            $chunk = \fread( $input, 1024 * 1024 );
            if ( $chunk === false ) {
                break;
            }
            \gzwrite( $output, $chunk );
        }

        \fclose( $input );
        \gzclose( $output );
    }

    private function sqlValue( mixed $value ): string {
        if ( $value === null ) {
            return 'NULL';
        }

        $escaped = \is_scalar( $value ) ? (string) $value : $this->encode( $value );
        $escaped = $this->database()->escapeString( $escaped );

        return "'" . $escaped . "'";
    }

    private function initializeLongRunningExecution(): void {
        if ( \function_exists( 'ignore_user_abort' ) ) {
            @\ignore_user_abort( true );
        }
        if ( \function_exists( 'set_time_limit' ) ) {
            @\set_time_limit( 0 );
        }
        @\ini_set( 'max_execution_time', '0' );
    }

    private function refreshExecutionBudget(): void {
        if ( \function_exists( 'set_time_limit' ) ) {
            @\set_time_limit( self::BACKUP_EXECUTION_REFRESH_SECONDS );
        }
    }

    private function environmentLabel(): string {
        $configured = trim( (string) \Core_Settings_Service::get( 'backup_environment', '' ) );
        if ( $configured !== '' ) {
            return \metis_key_clean( $configured );
        }

        $env = \metis_environment_type();
        if ( $env !== '' ) {
            return $env;
        }

        $host = strtolower( (string) \metis_runtime_parse_url( \metis_home_url( '/' ), PHP_URL_HOST ) );
        if ( str_contains( $host, 'localhost' ) || str_contains( $host, '.local' ) ) {
            return 'local';
        }
        if ( str_contains( $host, 'staging' ) || str_contains( $host, 'stage' ) ) {
            return 'staging';
        }
        if ( str_contains( $host, 'dev' ) || str_contains( $host, 'test' ) ) {
            return 'development';
        }

        return 'production';
    }

    private function version(): string {
        return \defined( 'METIS_VERSION' ) ? (string) METIS_VERSION : '';
    }

    private function runDirectory( string $run_uuid ): string {
        return $this->metisPath( 'storage/backups/' . $run_uuid );
    }

    private function buildRunUuid(): string {
        return \gmdate( 'Ymd-His' ) . '-' . substr( md5( (string) microtime( true ) . '|' . (string) random_int( 1000, 999999 ) ), 0, 8 );
    }

    private function temporaryFile( string $prefix ): string {
        $path = \tempnam( sys_get_temp_dir(), $prefix );
        if ( ! \is_string( $path ) || $path === '' ) {
            throw new \RuntimeException( 'Could not allocate a temporary file.' );
        }
        return $path;
    }

    private function writeJsonFile( string $path, array $payload ): void {
        $json = $this->encode( $payload );
        if ( \file_put_contents( $path, $json . "\n", LOCK_EX ) === false ) {
            throw new \RuntimeException( 'Could not write a backup metadata file.' );
        }
    }

    private function formatTimestamp( int $timestamp ): string {
        return \function_exists( 'metis_runtime_date' )
            ? \metis_runtime_date( 'Y-m-d H:i:s', $timestamp )
            : date( 'Y-m-d H:i:s', $timestamp );
    }

    private function metisPath( string $suffix = '' ): string {
        return rtrim( \METIS_PATH, '/\\' ) . ( $suffix !== '' ? '/' . ltrim( $suffix, '/\\' ) : '' );
    }

    private function configPath(): string {
        if ( \defined( 'METIS_CONFIG_PATH' ) ) {
            return rtrim( (string) \METIS_CONFIG_PATH, '/\\' );
        }

        $system_config = $this->metisPath( 'system/config' );
        if ( \is_dir( $system_config ) ) {
            return $system_config;
        }

        return $this->metisPath( 'config' );
    }

    private function encode( mixed $value ): string {
        if ( \function_exists( 'metis_json_encode' ) ) {
            return (string) \metis_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        }

        return (string) \json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    private function decode( string $value ): array {
        if ( trim( $value ) === '' ) {
            return [];
        }

        $decoded = \json_decode( $value, true );
        return \is_array( $decoded ) ? $decoded : [];
    }

    private function normalizeProgressStage( string $stage ): string {
        $stage = strtolower( trim( preg_replace( '/[^a-z0-9_-]+/', '_', $stage ) ?? '' ) );
        return $stage !== '' ? $stage : 'unknown';
    }
}
