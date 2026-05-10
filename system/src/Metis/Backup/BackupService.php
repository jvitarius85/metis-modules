<?php
declare(strict_types=1);

namespace Metis\Backup;

final class BackupService {
    private const RUNNING = 'running';
    private const SUCCESS = 'success';
    private const FAILED = 'failed';
    private const RUN_TIMEOUT_SECONDS = 3 * 60 * 60;
    private const BACKUP_EXECUTION_REFRESH_SECONDS = 10 * 60;

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

        $drive_cfg = $this->resolveDriveConfig();
        if ( empty( $drive_cfg['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Backup Drive is not configured.' ];
        }

        $run_uuid    = $this->buildRunUuid();
        $environment = $this->environmentLabel();
        $started_at  = \metis_current_time( 'mysql' );
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
            'drive_id'       => (string) ( $drive_cfg['shared_drive_id'] ?? '' ),
        ] );

        try {
            $component_archives = [];
            $checksums          = [];
            $timestamp_utc      = \gmdate( 'c' );

            $database_file = $this->buildDatabaseSnapshot( $payload_dir . '/database', $run_uuid );
            $component_archives['database'] = $this->describeFile( 'database', $database_file );

            $config_archive = $payload_dir . '/config.zip';
            $this->zipDirectory( $this->configPath(), $config_archive, [], [ 'index.php' ] );
            $component_archives['config'] = $this->describeFile( 'config', $config_archive );

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
                'component_archives' => $component_archives,
            ];

            $metadata_path = $payload_dir . '/metadata.json';
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
            $this->buildFullArchive( $full_archive, $database_file, $metadata_path, $checksums_path );
            $component_archives['full'] = $this->describeFile( 'full', $full_archive );

            $drive_segments = [
                $environment,
                \gmdate( 'Y' ),
                \gmdate( 'm' ),
                \gmdate( 'd' ),
                $run_uuid,
            ];
            $run_folder = $this->ensureDriveFolderPath( $drive_cfg, $drive_segments );
            if ( empty( $run_folder['ok'] ) ) {
                throw new \RuntimeException( 'Could not create the backup folder structure in Google Drive.' );
            }

            $root_folder_id = (string) ( $run_folder['folder_id'] ?? '' );
            $this->uploadJsonArtifact( $drive_cfg, $root_folder_id, 'metadata.json', $metadata );
            $this->uploadJsonArtifact( $drive_cfg, $root_folder_id, 'checksums.json', [
                'run_uuid'       => $run_uuid,
                'generated_at'   => $timestamp_utc,
                'component_hash' => $checksums,
            ] );

            $upload_map = [
                'full' => '',
            ];

            foreach ( $upload_map as $component => $folder_name ) {
                $component_folder = $folder_name === ''
                    ? $run_folder
                    : $this->ensureDriveFolderPath( $drive_cfg, array_merge( $drive_segments, [ $folder_name ] ) );
                if ( empty( $component_folder['ok'] ) && $folder_name !== '' ) {
                    throw new \RuntimeException( 'Could not create a component folder in Google Drive.' );
                }

                $upload = $this->uploadFileToDrive(
                    $drive_cfg,
                    (string) ( $component_folder['folder_id'] ?? $root_folder_id ),
                    (string) ( $component_archives[ $component ]['local_path'] ?? '' ),
                    (string) ( $component_archives[ $component ]['archive_name'] ?? '' ),
                    'application/zip'
                );
                if ( empty( $upload['ok'] ) ) {
                    throw new \RuntimeException( 'File upload failed.' );
                }

                $component_archives[ $component ]['drive_file_id'] = (string) ( $upload['id'] ?? '' );
                $component_archives[ $component ]['drive_web_view_link'] = (string) ( $upload['webViewLink'] ?? '' );
                $component_archives[ $component ]['drive_folder_id'] = (string) ( $component_folder['folder_id'] ?? '' );
            }

            $completed_at = \metis_current_time( 'mysql' );
            $metadata['completed_at_local'] = $completed_at;
            $metadata['drive'] = [
                'drive_id'       => (string) ( $drive_cfg['shared_drive_id'] ?? '' ),
                'run_folder_id'  => $root_folder_id,
                'shared_drive'   => (string) ( $drive_cfg['shared_drive_label'] ?? $drive_cfg['shared_drive_name'] ?? '' ),
            ];
            $metadata['integrity'] = [
                'created'  => true,
                'uploaded' => true,
            ];
            $metadata['component_archives'] = $component_archives;

            $this->updateRun( $run_id, [
                'status'              => self::SUCCESS,
                'completed_at'        => $completed_at,
                'drive_run_folder_id' => $root_folder_id,
                'metadata_json'       => $this->encode( $metadata ),
                'components_json'     => $this->encode( $component_archives ),
                'last_error'          => '',
            ] );

            $this->applyRetentionPolicy( $environment, (int) $run_id, $drive_cfg );
            $this->cleanupLocalRunArtifacts( $run_id, $run_uuid, $local_dir );

            return [
                'ok'             => true,
                'status'         => self::SUCCESS,
                'run_uuid'       => $run_uuid,
                'environment'    => $environment,
                'completed_at'   => $completed_at,
                'drive_folder_id'=> $root_folder_id,
                'components'     => $component_archives,
                'metadata'       => $metadata,
            ];
        } catch ( \Throwable $e ) {
            $this->cleanupLocalRunArtifacts( $run_id, $run_uuid, $local_dir );

            $this->updateRun( $run_id, [
                'status'        => self::FAILED,
                'completed_at'  => \metis_current_time( 'mysql' ),
                'last_error'    => 'Backup run failed. Review logs for details.',
                'local_path'     => '',
            ] );

            if ( \class_exists( 'Metis_Logger' ) ) {
                \Metis_Logger::error( 'Backup run failed', [
                    'run_uuid' => $run_uuid,
                    'error'    => $e->getMessage(),
                ] );
            }

            return [
                'ok'       => false,
                'status'   => self::FAILED,
                'run_uuid' => $run_uuid,
                'error'    => 'Backup run failed. Review logs for details.',
            ];
        }
    }

    public function listRuns( int $limit = 20 ): array {
        $this->ensureSchema();
        $this->reconcileStaleRuns();

        $table = \Metis_Tables::get( 'backup_runs' );
        $limit = max( 1, min( 100, $limit ) );
        $rows  = $this->database()->fetchAll( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", [ $limit ] );

        return array_values( array_map( fn ( array $row ): array => $this->normalizeRunRow( $row ), $rows ?: [] ) );
    }

    private function reconcileStaleRuns(): void {
        $table = \Metis_Tables::get( 'backup_runs' );
        $rows  = $this->database()->fetchAll(
            "SELECT id, run_uuid, started_at, local_path
             FROM {$table}
             WHERE status = %s
             ORDER BY id DESC
             LIMIT 200",
            [ self::RUNNING ]
        ) ?: [];

        $now = time();
        foreach ( $rows as $row ) {
            $started = (string) ( $row['started_at'] ?? '' );
            $timezone = \function_exists( 'metis_runtime_timezone' )
                ? \metis_runtime_timezone()
                : new \DateTimeZone( 'UTC' );
            $started_dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $started, $timezone );
            $started_ts = $started_dt instanceof \DateTimeImmutable ? $started_dt->getTimestamp() : false;
            if ( $started_ts === false || $started_ts < 1 ) {
                continue;
            }

            if ( ( $now - $started_ts ) < self::RUN_TIMEOUT_SECONDS ) {
                continue;
            }

            $run_id   = (int) ( $row['id'] ?? 0 );
            $run_uuid = (string) ( $row['run_uuid'] ?? '' );
            if ( $run_id < 1 ) {
                continue;
            }

            $this->updateRun( $run_id, [
                'status'       => self::FAILED,
                'completed_at' => \metis_current_time( 'mysql' ),
                'last_error'   => 'Backup run timed out before completion; marked failed by watchdog.',
            ] );

            $local_path = trim( (string) ( $row['local_path'] ?? '' ) );
            if ( $local_path !== '' ) {
                $this->removeDirectory( $local_path );
            }

            if ( \class_exists( 'Metis_Logger' ) ) {
                \Metis_Logger::warn( 'Backup run reconciled from stale running state', [
                    'run_uuid' => $run_uuid,
                    'started_at' => $started,
                    'timeout_seconds' => self::RUN_TIMEOUT_SECONDS,
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
        $zip->close();
    }

    private function zipDirectory( string $source, string $archive_path, array $exclude_dirs = [], array $exclude_files = [] ): void {
        $zip = new \ZipArchive();
        if ( $zip->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
            throw new \RuntimeException( 'Could not create archive: ' . basename( $archive_path ) );
        }

        $root_name = pathinfo( basename( $archive_path ), PATHINFO_FILENAME );
        $this->addDirectoryToZip( $zip, $source, $root_name, $exclude_dirs, $exclude_files );
        $zip->close();
    }

    private function addDirectoryToZip( \ZipArchive $zip, string $source, string $base_in_zip, array $exclude_dirs = [], array $exclude_files = [] ): void {
        if ( ! \is_dir( $source ) ) {
            return;
        }

        $source = rtrim( $source, '/\\' );
        $root   = realpath( $source );
        if ( ! \is_string( $root ) || $root === '' ) {
            return;
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
        unset( $row['metadata_json'], $row['components_json'], $row['restore_json'] );
        return $row;
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
}
