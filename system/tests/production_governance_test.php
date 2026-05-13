<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $relative ) use ( $root ): string {
    $contents = file_get_contents( $root . '/' . ltrim( $relative, '/\\' ) );
    return is_string( $contents ) ? $contents : '';
};

$uploads = $read( 'src/Metis/Core/Runtime/UploadsRuntime.php' );
$request = $read( 'src/Metis/Core/Runtime/RequestRuntime.php' );
$kernel = $read( 'src/Metis/Core/Kernel/Bootstrap.php' );
$processRunner = $read( 'src/Metis/Core/Services/ProcessRunner.php' );
$releaseManager = $read( 'src/Metis/Release/ReleaseManager.php' );
$communicationsAttachments = $read( 'src/Metis/Modules/CommunicationsInbound/AttachmentStorageService.php' );
$finance = $read( 'src/Metis/Modules/Finance/FinanceV2Service.php' );
$scanner = $read( 'tools/security_scan.php' );
$settingsBootstrap = $read( 'modules/settings/views/_settings_bootstrap.php' );
$settingsRuntime = $read( 'modules/settings/views/runtime.php' );
$settingsJobsTasks = $read( 'modules/settings/views/jobs_tasks.php' );
$settingsAjax = $read( 'modules/settings/assets/settings.ajax.php' );
$settingsCss = $read( 'modules/settings/assets/settings.css' );
$settingsJs = $read( 'modules/settings/assets/settings.js' );
$helpersRuntime = $read( 'src/Metis/Core/Runtime/HelpersRuntime.php' );
$coreBootstrap = $read( 'src/Metis/Core/CoreBootstrap.php' );
$assetsRuntime = $read( 'src/Metis/Core/AssetsRuntime.php' );
$coreJs = $read( 'assets/core.js' );
$ajaxRuntime = $read( 'src/Metis/Core/Ajax/AjaxRuntime.php' );
$codeRegistry = $read( 'src/Metis/Core/CodeRegistry.php' );
$loggerRuntime = $read( 'src/Metis/Core/LoggerRuntime.php' );
$boardSupport = $read( 'src/Metis/Modules/Board/Support.php' );
$contactsSupport = $read( 'src/Metis/Modules/Contacts/Support.php' );
$newsletterSupport = $read( 'src/Metis/Modules/Newsletter/Support.php' );
$calendarJs = $read( 'modules/calendar/assets/calendar.js' );
$cronRuntime = $read( 'src/Metis/Core/Cron/CronRuntime.php' );
$releaseRuntime = $read( 'src/Metis/Core/ReleaseRuntime.php' );
$backupService = $read( 'src/Metis/Backup/BackupService.php' );
$backupRuntime = $read( 'src/Metis/Core/BackupRuntime.php' );
$jobQueue = $read( 'src/Metis/Core/Jobs/JobQueue.php' );
$operationsService = $read( 'src/Metis/Core/Services/OperationsService.php' );
$dataRetentionService = $read( 'src/Metis/Core/Services/DataRetentionService.php' );
$standaloneApplicationBootstrap = $read( 'src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php' );
$routerRuntime = $read( 'src/Metis/Core/Routing/RouterRuntime.php' );
$governance = require $root . '/config/governance.php';

$superglobalApprovals = (array) ( $governance['approved_layers']['superglobals'] ?? [] );
$assert( $superglobalApprovals === [], 'Raw request superglobals must not require production allowlist entries.' );
$requestBoundaryApprovals = (array) ( $governance['approved_layers']['request_boundary'] ?? [] );
$assert( $requestBoundaryApprovals === [ 'system/src/Metis/Core/Runtime/RequestRuntime.php' ], 'SAPI request boundary must be approved only in RequestRuntime.' );

$rawSqlApprovals = (array) ( $governance['approved_layers']['raw_sql'] ?? [] );
foreach ( $rawSqlApprovals as $approvedPath ) {
    $approvedPath = (string) $approvedPath;
    $assert(
        str_starts_with( $approvedPath, 'system/src/Metis/Core/' )
        || $approvedPath === 'system/src/Metis/Services/DatabaseService.php'
        || str_starts_with( $approvedPath, 'system/tools/' ),
        'Raw SQL approval must stay limited to core DB/runtime layers or explicit CLI tools: ' . $approvedPath
    );
}

foreach ( [ 'storage/public-media', 'storage/protected-media', 'storage/private-records' ] as $mediaRoot ) {
    $assert( in_array( $mediaRoot, (array) ( $governance['required_media_roots'] ?? [] ), true ), 'Governance config must require ' . $mediaRoot . '.' );
    $assert( is_dir( dirname( $root ) . '/' . $mediaRoot ), 'Required media root must exist: ' . $mediaRoot . '.' );
}

$assert( str_contains( $uploads, 'function metis_store_public_media' ), 'Canonical public-media helper is missing.' );
$assert( str_contains( $uploads, 'function metis_store_protected_media' ), 'Canonical protected-media helper is missing.' );
$assert( str_contains( $uploads, 'function metis_store_private_record' ), 'Canonical private-record helper is missing.' );
$assert( str_contains( $uploads, 'missing_access_ttl' ), 'Protected/private media storage must reject missing expiration metadata.' );
$assert( str_contains( $uploads, 'metis_media_audit_storage_event' ), 'Media storage must be auditable.' );
$assert( str_contains( $kernel, "private, no-store, max-age=0" ), 'Protected/private media responses must not be publicly cacheable.' );
$assert( str_contains( $communicationsAttachments, 'metis_store_protected_media' ), 'Inbound communications attachments must use protected-media.' );
$assert( str_contains( $finance, "'storage_class' => 'private'" ), 'Finance reconciliation statements must use private-records.' );

foreach ( [ 'metis_request_id', 'metis_request_object_code', 'metis_request_enum', 'metis_request_json', 'metis_request_date', 'metis_request_bool', 'metis_request_file' ] as $helper ) {
    $assert( str_contains( $request, 'function ' . $helper ), 'Request helper missing: ' . $helper . '.' );
}

foreach ( [ 'security_context', 'audit_context', 'permission_context' ] as $contextKey ) {
    $assert( str_contains( $processRunner, $contextKey ), 'ProcessRunner must require ' . $contextKey . '.' );
    $assert( str_contains( $releaseManager, $contextKey ), 'Release process execution must pass ' . $contextKey . '.' );
}
$assert( str_contains( $processRunner, 'contextAllowsExecution' ), 'ProcessRunner must validate authority before execution.' );
$assert( str_contains( $processRunner, 'redactContext' ), 'ProcessRunner audit context must redact sensitive values.' );

foreach ( [ 'sensitive-media-storage', 'process-context', 'route-middleware-governance', 'ajax-security-contracts', 'hermes-governance' ] as $scanRule ) {
    $assert( str_contains( $scanner, $scanRule ), 'Security scanner missing production governance rule: ' . $scanRule . '.' );
}
foreach ( [ 'request-boundary', 'native-db-access', 'serialization-boundary' ] as $scanRule ) {
    $assert( str_contains( $scanner, $scanRule ), 'Security scanner missing hardening rule: ' . $scanRule . '.' );
}

$assert( str_contains( $helpersRuntime, 'function metis_runtime_timezone_name' ), 'Runtime must centralize timezone setting resolution.' );
$assert( str_contains( $helpersRuntime, 'function metis_runtime_date_format' ) && str_contains( $helpersRuntime, 'function metis_runtime_time_format' ), 'Runtime must centralize date and time format setting resolution.' );
$assert( str_contains( $helpersRuntime, 'function metis_runtime_format_datetime' ) && str_contains( $helpersRuntime, 'function metis_runtime_format_date' ) && str_contains( $helpersRuntime, 'function metis_runtime_format_time' ), 'Runtime must expose canonical display date/time helpers.' );
$assert( str_contains( $helpersRuntime, 'metis_runtime_sync_default_timezone' ) && str_contains( $helpersRuntime, "metis_on( 'metis_runtime_loaded'" ), 'Runtime must sync PHP default timezone after settings preload.' );
$assert( str_contains( $coreBootstrap, "return gmdate( \$type )" ), 'metis_current_time fallback must support custom date formats instead of returning timestamps for unknown types.' );
$assert( str_contains( $assetsRuntime, "'time' => [" ) && str_contains( $assetsRuntime, 'metis_runtime_timezone_name()' ) && str_contains( $coreJs, 'Metis.time' ), 'Core assets must expose configured timezone/date/time formats to JavaScript.' );
$assert( str_contains( $coreJs, 'parseNaive(value)' ) && str_contains( $coreJs, 'zoneOffsetMs(date)' ), 'JavaScript time parsing must interpret naive Metis timestamps in the configured timezone before display.' );
$assert( str_contains( $settingsBootstrap, 'metis_runtime_format_datetime' ), 'Settings display timestamps must use canonical runtime datetime formatting.' );
$assert( str_contains( $settingsAjax, 'started_at_display' ) && str_contains( $settingsAjax, 'progress_updated_at_display' ), 'Backup history API must send preformatted configured-timezone timestamps.' );
$assert( str_contains( $loggerRuntime, "metis_runtime_format_datetime( \$timestamp, null, null, 'UTC'" ), 'Log viewer must render UTC log timestamps through configured display settings.' );
$assert( str_contains( $boardSupport, 'metis_runtime_format_datetime' ) && str_contains( $contactsSupport, 'metis_runtime_format_datetime' ) && str_contains( $newsletterSupport, 'metis_runtime_format_datetime' ), 'Module date helpers must delegate to canonical runtime datetime formatting.' );
$assert( str_contains( $calendarJs, 'calendarFormatDateTime' ) && str_contains( $calendarJs, 'Metis.time' ), 'Calendar UI must use configured timezone/date/time formatting instead of browser-only locale formatting.' );

$assert( str_contains( $settingsBootstrap, 'function metis_settings_health_filesystem_targets' ), 'System health must use canonical filesystem target metadata.' );
$assert( str_contains( $settingsBootstrap, 'function metis_settings_health_filesystem_check_id' ), 'System health filesystem check IDs must be centralized.' );
$assert( str_contains( $settingsBootstrap, 'metis_media_storage_roots( true )' ), 'System health media checks must use canonical media storage roots.' );
$assert( str_contains( $settingsBootstrap, 'function metis_settings_latest_backup_artifact' ), 'System health backup recency must inspect local backup artifacts when run history is empty.' );
$assert( str_contains( $settingsBootstrap, 'queue_worker_registration' ), 'System health must report missing queue worker registration.' );
$assert( str_contains( $settingsBootstrap, 'function metis_settings_health_service_targets' ), 'System health must declare canonical core service hydration targets.' );
$assert( str_contains( $settingsBootstrap, 'core_service_hydration' ), 'System health must report core service hydration.' );
$assert( str_contains( $settingsBootstrap, 'help_service_hydration' ) && str_contains( $settingsBootstrap, 'editing-a-user' ), 'System health must report Help service and seeded article hydration.' );
$assert( str_contains( $settingsBootstrap, 'hermes_definition_library' ) && str_contains( $settingsBootstrap, 'system_health_diagnostics' ), 'System health must report Hermes context pack and playbook hydration.' );
$assert( str_contains( $settingsBootstrap, 'code_lookup_registry' ) && str_contains( $settingsBootstrap, 'metis_settings_health_code_lookup_status' ), 'System health must report code lookup registry hydration.' );
$assert( str_contains( $codeRegistry, 'function rehydrate' ) && str_contains( $codeRegistry, 'migrateExistingRecords' ), 'Code registry must expose a central rehydration API backed by EntityId migration.' );
$assert( str_contains( $settingsAjax, 'code_lookup.rehydrate' ) && str_contains( $settingsAjax, 'CodeRegistry::rehydrate( true )' ), 'System health remediation must rehydrate the code lookup registry.' );
$assert( str_contains( $ajaxRuntime, 'metis_rehydrate_code_lookup' ) && str_contains( $ajaxRuntime, "'found' => false" ), 'Core AJAX must expose governed code lookup rehydration and return 200-level lookup misses.' );
$assert( ! str_contains( $ajaxRuntime, "Code not found.' ], 404" ), 'Code lookup misses must not surface as AJAX 404 failures.' );
$assert( str_contains( $coreJs, "r.success && (!r.data || r.data.found !== false)" ) && str_contains( $coreJs, "metis_csrf_action" ), 'Core code search must handle lookup misses without AJAX errors and send explicit CSRF action metadata.' );
$assert( str_contains( $settingsBootstrap, 'metis_settings_build_failed_login_snapshot' ) && str_contains( $settingsBootstrap, 'auth_failed_login' ), 'Runtime settings must expose recent failed login audit events.' );
$assert( str_contains( $settingsRuntime, 'Failed Logins' ) && str_contains( $settingsRuntime, 'failed_login_snapshot' ), 'Runtime settings must render a dedicated Failed Logins panel.' );
$assert( str_contains( $settingsAjax, 'metis_settings_health_filesystem_targets()' ), 'System health remediation must reuse canonical filesystem target metadata.' );
$assert( str_contains( $settingsAjax, 'metis_settings_health_filesystem_check_id( $label )' ), 'System health remediation must derive filesystem check IDs from canonical labels.' );
$assert( str_contains( $settingsAjax, 'services.hydrate' ), 'System health remediation must attempt core service hydration repair.' );
$assert( str_contains( $settingsAjax, 'help.seed_and_index' ) && str_contains( $settingsAjax, 'runSeeder( true )' ), 'System health remediation must reseed and reindex Help documents.' );
$assert( str_contains( $settingsAjax, 'hermes.definitions.reload' ) && str_contains( $settingsAjax, "CacheService::clearGroup( 'hermes' )" ), 'System health remediation must clear and reload Hermes definitions.' );
$assert( ! str_contains( $settingsAjax, "'fs_perm_storage_uploads'" ), 'System health remediation must not use stale legacy-upload filesystem check IDs.' );
$assert( str_contains( $settingsAjax, "in_array( \$type, [ 'runtime', 'legacy_runtime' ], true )" ), 'System health remediation must cover runtime and existing legacy runtime paths.' );
$assert( str_contains( $settingsAjax, '! $required && ! is_dir( $path ) && ! is_file( $path )' ), 'Manual permission plan must not create absent optional legacy paths.' );
$assert( str_contains( $settingsCss, '.metis-checker-finding-cell' ) && str_contains( $settingsCss, 'overflow-wrap: anywhere' ), 'System health report cells must wrap long findings and recommendations.' );
$assert( str_contains( $settingsJs, 'metis-checker-finding-cell' ) && str_contains( $settingsJs, 'metis-checker-recommendation-cell' ), 'System health rows must expose semantic cells for wrapping.' );
$assert( str_contains( $routerRuntime, 'function metis_request_path_strip_legacy_system_prefix' ) && str_contains( $routerRuntime, "'/admin'" ), 'Router must normalize legacy /system/admin app-route prefixes before manifest matching.' );
$assert( str_contains( $cronRuntime, "'background_job_processing'" ), 'Background job processor task must stay registered.' );
$assert( ! str_contains( $cronRuntime, 'Queue processing is handled by the async drain.' ), 'Background job processor must be queueable instead of skipped.' );
$assert( str_contains( $cronRuntime, "return self::drain_job_queue( 'system_cron' );" ), 'Background job processor must drain bounded batches so staged jobs can advance without waiting for another poll.' );
$assert( str_contains( $cronRuntime, 'metis_register_core_services();' ) && str_contains( $cronRuntime, "\\Metis\\Core\\Application::service( 'operations' )" ), 'Queue drain must register core operation workers before processing jobs.' );
$assert( str_contains( $cronRuntime, "metis_release_check_for_updates( true, 'system_cron' )" ), 'Scheduled release update checks must force-refresh trusted release metadata.' );
$assert( str_contains( $cronRuntime, "'data_retention_cleanup'" ) && str_contains( $cronRuntime, 'metis_data_retention()->run' ), 'Scheduler must run governed data retention cleanup.' );
$assert( str_contains( $cronRuntime, "'release_auto_update'" ) && str_contains( $cronRuntime, "metis_release_auto_update( 'system_cron' )" ), 'Scheduler must run guarded release auto-update checks.' );
$assert( str_contains( $dataRetentionService, 'final class DataRetentionService' ), 'Data retention service must be present.' );
foreach ( [ 'job_queue_completed', 'job_queue_failed', 'webhook_events_processed', 'email_send_events', 'audit_activity', 'audit_security', 'hermes_command_logs', 'backup_runs_failed' ] as $retentionPolicy ) {
    $assert( str_contains( $dataRetentionService, "'key' => '" . $retentionPolicy . "'" ), 'Data retention policy missing: ' . $retentionPolicy . '.' );
}
$assert( str_contains( $dataRetentionService, 'DEFAULT_BATCH_LIMIT' ) && str_contains( $dataRetentionService, 'LIMIT %d' ), 'Data retention cleanup must delete in bounded batches.' );
$assert( str_contains( $dataRetentionService, 'validIdentifier' ) && str_contains( $dataRetentionService, 'information_schema.TABLES' ), 'Data retention cleanup must validate table identifiers before dynamic SQL.' );
$assert( str_contains( $settingsBootstrap, 'data_retention_cleanup' ) && str_contains( $settingsBootstrap, 'kpi_retention_expired' ), 'System health must expose data-retention status and expired-row pressure.' );
$assert( str_contains( $settingsAjax, 'data_retention.cleanup' ), 'Auto-remediate must queue data-retention cleanup when health detects drift.' );
$assert( str_contains( $releaseRuntime, 'function metis_release_auto_update' ), 'Release runtime must expose guarded auto-update.' );
$assert( str_contains( $releaseRuntime, 'release_auto_update_enabled' ) && str_contains( $releaseRuntime, 'release_auto_update_max_level' ), 'Release auto-update must be policy controlled.' );
$assert( str_contains( $releaseRuntime, "'release.apply'" ) && str_contains( $releaseRuntime, "'operation:release.apply:auto:'" ), 'Release auto-update must queue the existing governed release apply operation.' );
$assert( str_contains( $operationsService, "'release.auto_update'" ) && str_contains( $operationsService, 'runReleaseAutoUpdateOperation' ), 'Operations service must expose governed release auto-update execution.' );
$assert( str_contains( $standaloneApplicationBootstrap, "'release_auto_update_enabled', true" ) && str_contains( $standaloneApplicationBootstrap, "'release_auto_update_max_level', 'patch'" ), 'Install defaults must enable patch-only trusted auto-update.' );
$assert( str_contains( $backupService, 'ensureBackupSourceDirectories' ), 'Backup service must normalize required source directories before creating artifacts.' );
$assert( str_contains( $backupService, 'backupSourceDirectories' ) && str_contains( $backupService, 'storage/public-media' ) && str_contains( $backupService, 'storage/protected-media' ) && str_contains( $backupService, 'storage/private-records' ), 'Backup service must cover canonical media storage roots.' );
$assert( str_contains( $backupService, 'addEmptyDir( $base_in_zip )' ), 'Backup service must create deterministic empty directory archives.' );
$assert( str_contains( $backupService, 'Could not finalize archive' ), 'Backup service must fail loudly when zip finalization fails.' );
$assert( str_contains( $backupService, 'STAGE_HEALTH_CHECK' ) && str_contains( $backupService, 'STAGE_LOCAL_GENERATION' ) && str_contains( $backupService, 'STAGE_VERIFY' ) && str_contains( $backupService, 'STAGE_UPLOAD' ), 'Backup service must run through deterministic staged backup phases.' );
$assert( str_contains( $backupService, 'runBackupStage' ) && str_contains( $backupService, 'enqueueStage' ) && str_contains( $backupService, "queueOperation(\n            'backup.stage'" ), 'Backup stages must be queued through the governed operations service.' );
$assert( str_contains( $backupService, 'verifyLocalBackupArtifacts' ) && str_contains( $backupService, 'checksum did not match' ), 'Backup service must verify local artifacts before upload.' );
$assert( str_contains( $backupService, 'uploadFileToDriveResumable' ) && str_contains( $backupService, 'uploadType=resumable' ) && str_contains( $backupService, 'Content-Range' ), 'Backup service must use resumable Drive uploads for large backup artifacts.' );
$assert( str_contains( $backupService, 'pauseScheduledBackups' ) && str_contains( $backupService, 'backup_paused_until_fix' ), 'Backup service must pause scheduled backups after non-remediable failures.' );
$assert( str_contains( $backupService, 'updated_at' ) && str_contains( $backupService, 'progress' ) && str_contains( $backupService, 'LOCAL_ARTIFACT_STALE_SECONDS' ), 'Backup watchdog must use run progress heartbeats and local-artifact stale detection instead of only start time.' );
$assert( str_contains( $backupRuntime, 'function metis_backup_run_stage' ) && str_contains( $backupRuntime, 'function metis_backup_pause_status' ), 'Backup runtime must expose stage execution and pause status helpers.' );
$assert( str_contains( $operationsService, "'backup.stage'" ) && str_contains( $operationsService, 'runBackupStageOperation' ), 'Operations service must govern backup stage execution.' );
$assert( str_contains( $jobQueue, 'LONG_RUNNING_LEASE_TTL' ) && str_contains( $jobQueue, "'backup.stage'" ), 'Job queue must provide long-running leases for backup stage jobs.' );
$assert( str_contains( $settingsAjax, 'pause_status' ) && str_contains( $settingsAjax, 'local_artifact_available' ), 'Backup history API must expose pause status and retained local artifacts.' );
$assert( str_contains( $settingsJs, 'renderBackupStatusAlert' ) && str_contains( $settingsJs, 'Scheduled backups are paused because:' ), 'Backup UI must show explicit backup failure and pause reasons.' );
$assert( str_contains( $settingsJs, 'renderBackupLiveStatus' ) && str_contains( $settingsJs, 'backupProgressPercent' ) && str_contains( $settingsJs, 'role="progressbar"' ), 'Backup UI must render live progress for staged backups.' );
$assert( str_contains( $settingsJs, 'metis-status-chip is-' ) && str_contains( $settingsJs, 'metis-backup-archive-link' ), 'Backup history rows must use Metis status chips and action styling.' );
$assert( str_contains( $settingsCss, '.metis-backup-alert' ) && str_contains( $settingsCss, '.metis-backup-progress' ), 'Backup UI must style backup failure alerts and live progress.' );
$assert( str_contains( $settingsCss, '.metis-backup-history-table' ) && str_contains( $settingsCss, '.metis-backup-run-id' ), 'Backup history table must use compact Metis table styling.' );
$assert( str_contains( $settingsBootstrap, "'recent_async_jobs'," ), 'Settings context must expose recent async jobs to the Jobs & Tasks view.' );
$assert( str_contains( $settingsBootstrap, "'recent_async_jobs_pagination'," ) && str_contains( $settingsBootstrap, "metis_request_id( 'jobs_page'" ), 'Jobs & Tasks history must expose governed pagination from typed request helpers.' );
$assert( str_contains( $settingsBootstrap, "'started_at_display'" ) && str_contains( $settingsBootstrap, "'finished_at_display'" ), 'Scheduler snapshots must format recent job timestamps with runtime display settings.' );
$assert( str_contains( $operationsService, 'countJobs' ) && str_contains( $operationsService, 'ORDER BY COALESCE(started_at, available_at, created_at) DESC, id DESC' ) && str_contains( $operationsService, 'LIMIT %d OFFSET %d' ), 'Recent system jobs must be queryable newest-first with bounded pagination.' );
$assert( str_contains( $operationsService, "'result_summary'" ) && str_contains( $operationsService, 'summarizeJobResult' ), 'Recent system jobs must expose sanitized result summaries instead of raw JSON payloads.' );
$assert( str_contains( $settingsJobsTasks, '$recent_async_jobs' ) && str_contains( $settingsJobsTasks, 'metis-premium-table metis-scheduler-history-table' ), 'Jobs & Tasks recent jobs panel must render returned queue history in Metis table styling.' );
$assert( str_contains( $settingsJobsTasks, 'metis-pagination' ) && str_contains( $settingsJobsTasks, '$recent_async_jobs_pagination' ), 'Jobs & Tasks recent jobs panel must render pagination controls.' );
$assert( ! str_contains( $settingsJobsTasks, 'metis_json_encode( (array) $job_row[\'result\']' ), 'Jobs & Tasks recent jobs panel must not render raw result JSON.' );
$assert( str_contains( $settingsJs, 'schedulerCsrfAction' ) && str_contains( $settingsJs, "metis_csrf_action', schedulerCsrfAction(action)" ), 'Scheduler live polling must send explicit AJAX CSRF action metadata.' );
$assert( str_contains( $settingsJs, 'schedulerAuthRejected' ) && str_contains( $settingsJs, 'stopSchedulerPolling' ), 'Scheduler live polling must stop retrying after session integrity/auth rejects.' );

if ( preg_match( '/function metis_settings_health_security_offense_clause\\(\\): string \\{(?P<body>.*?)\\n\\}/s', $settingsBootstrap, $match ) === 1 ) {
    $offenseClause = strtolower( (string) ( $match['body'] ?? '' ) );
    foreach ( [ "%denied%", "%failed%", "%blocked%", "%rate_limit%", "%rate-lim%", "%429%" ] as $broadPattern ) {
        $assert( ! str_contains( $offenseClause, $broadPattern ), 'Repeated security offense health check must not count broad expected-control patterns: ' . $broadPattern );
    }
} else {
    $assert( false, 'System health security offense clause helper is missing.' );
}

$deploymentDocs = [
    'docs/deployment/deployment-verification-checklist.md',
    'docs/deployment/web-server-deny-rules.md',
    'docs/governance/production-governance.md',
    'docs/security/media-isolation.md',
];
foreach ( $deploymentDocs as $doc ) {
    $assert( is_file( $root . '/' . $doc ), 'Production documentation missing: ' . $doc . '.' );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Production governance checks passed.\n" );
