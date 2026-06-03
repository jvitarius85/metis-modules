<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Application;
use Metis\Core\HelpSearchStore;
use Metis\Core\Jobs\JobQueue;
use Metis\Hermes\HelpIssueResolver;

final class HermesCapabilityService {
    public function __construct(
        private readonly DatabaseService $db,
        private readonly HermesDirectoryService $directory,
        private readonly HermesUserAdminService $userAdmin,
        private readonly HermesSystemOperationsService $systemOps,
        private readonly ?HermesNewsletterAdminService $newsletterAdmin = null,
        private readonly ?JobQueue $jobs = null,
        private readonly ?HelpIssueResolver $helpIssueResolver = null
    ) {}

    public function createUser( array $payload ): array {
        return $this->userAdmin->createUser( $payload );
    }

    public function updateUser( array $payload ): array {
        return $this->userAdmin->updateUser( $payload );
    }

    public function disableUser( array $payload ): array {
        return $this->userAdmin->disableUser( $payload );
    }

    public function enableUser( array $payload ): array {
        return $this->userAdmin->enableUser( $payload );
    }

    public function deleteUser( array $payload ): array {
        return $this->userAdmin->deleteUser( $payload );
    }

    public function unlockUser( array $payload ): array {
        return $this->userAdmin->unlockUser( $payload );
    }

    public function resetMetisPassword( array $payload ): array {
        return $this->userAdmin->resetMetisPassword( $payload );
    }

    public function resetWorkspacePassword( array $payload ): array {
        return $this->userAdmin->resetWorkspacePassword( $payload );
    }

    public function updateWorkspaceUser( array $payload ): array {
        return $this->userAdmin->updateWorkspaceUser( $payload );
    }

    public function disableWorkspaceUser( array $payload ): array {
        return $this->userAdmin->disableWorkspaceUser( $payload );
    }

    public function enableWorkspaceUser( array $payload ): array {
        return $this->userAdmin->enableWorkspaceUser( $payload );
    }

    public function deleteWorkspaceUser( array $payload ): array {
        return $this->userAdmin->deleteWorkspaceUser( $payload );
    }

    public function assignRole( array $payload ): array {
        $payload['mode'] = 'add';
        return $this->userAdmin->manageUserRoles( $payload );
    }

    public function removeRole( array $payload ): array {
        $payload['mode'] = 'remove';
        return $this->userAdmin->manageUserRoles( $payload );
    }

    public function manageWorkspaceGroups( array $payload ): array {
        return $this->userAdmin->manageWorkspaceGroups( $payload );
    }

    public function resetUserMfa( array $payload ): array {
        return $this->userAdmin->resetUserMfa( $payload );
    }

    public function linkDriveFolder( array $payload ): array {
        return $this->userAdmin->linkDriveFolder( $payload );
    }

    public function listUsers( array $payload ): array {
        $peopleTable = \Metis_Tables::get( 'people' );
        $query = trim( strtolower( (string) ( $payload['query'] ?? '' ) ) );
        $args = [];
        $where = "WHERE status <> 'deleted'";

        if ( $query !== '' ) {
            $needle = '%' . $this->db->escapeLike( $query ) . '%';
            $where .= " AND (LOWER(COALESCE(display_name, '')) LIKE %s OR LOWER(COALESCE(email, '')) LIKE %s)";
            $args[] = $needle;
            $args[] = $needle;
        }

        $rows = $this->db->fetchAll(
            "SELECT id, pid, display_name, email, status, lifecycle_status, workspace_email
             FROM {$peopleTable}
             {$where}
             ORDER BY updated_at DESC, id DESC
             LIMIT 100",
            $args
        );

        return [
            'status' => 'success',
            'users' => $rows,
            'count' => count( $rows ),
            'message' => sprintf( 'Found %d users.', count( $rows ) ),
        ];
    }

    public function getUser( array $payload ): array {
        return $this->directory->lookupProfile( [
            'subject' => (string) ( $payload['subject'] ?? $payload['email'] ?? $payload['query'] ?? '' ),
            'entity_hint' => 'person',
        ] );
    }

    public function lookupProfile( array $payload ): array {
        return $this->directory->lookupProfile( (array) ( $payload['profile_request'] ?? $payload ) );
    }

    public function diagnosePermissions( array $payload ): array {
        if ( ! Application::has_service( 'security_diagnostics' ) ) {
            return $this->error( 'EXECUTION_FAILED', 'Security diagnostics service is unavailable.' );
        }

        return Application::service( 'security_diagnostics' )->diagnosePermissions( (array) ( $payload['diagnostic_request'] ?? $payload ) );
    }

    public function queryGivingSummary( array $payload ): array {
        return $this->directory->queryGivingSummary( (array) ( $payload['giving_request'] ?? $payload ) );
    }

    public function queryCapabilityActors( array $payload ): array {
        return $this->directory->queryCapabilityActors( (array) ( $payload['capability_request'] ?? $payload ) );
    }

    public function getEntityAttribute( array $payload ): array {
        $request = (array) ( $payload['attribute_request'] ?? [] );
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        $attribute = trim( strtolower( (string) ( $request['attribute'] ?? '' ) ) );
        if ( $subject === '' || $attribute === '' ) {
            return $this->error( 'INVALID_INPUT', 'Subject and attribute are required.' );
        }

        $profile = $this->directory->lookupProfile( [
            'subject' => $subject,
            'entity_hint' => (string) ( $request['entity_hint'] ?? 'auto' ),
        ] );
        if ( (string) ( $profile['status'] ?? '' ) !== 'success' ) {
            return $profile;
        }

        $data = (array) ( $profile['profile'] ?? [] );
        $person = (array) ( $data['person'] ?? [] );
        $contact = (array) ( $data['contact'] ?? [] );
        $value = match ( $attribute ) {
            'email' => (string) ( $person['email'] ?? $contact['email'] ?? '' ),
            'phone' => (string) ( $contact['phone'] ?? '' ),
            'address' => (string) ( $contact['address'] ?? '' ),
            'workspace_email' => (string) ( $person['workspace_email'] ?? '' ),
            'status' => (string) ( $person['status'] ?? '' ),
            'name' => (string) ( $data['name'] ?? '' ),
            default => '',
        };

        if ( $value === '' ) {
            return $this->error( 'ENTITY_NOT_FOUND', sprintf( 'Attribute [%s] is not available for [%s].', $attribute, $subject ) );
        }

        return [
            'status' => 'success',
            'attribute' => $attribute,
            'value' => $value,
            'message' => sprintf( '%s: %s', $attribute, $value ),
        ];
    }

    public function resolveHelpIssue( array $payload ): array {
        if ( $this->helpIssueResolver === null ) {
            return $this->error( 'EXECUTION_FAILED', 'Help issue resolver is unavailable.' );
        }

        $message = trim( (string) ( $payload['user_message'] ?? $payload['query'] ?? '' ) );
        if ( $message === '' ) {
            return $this->error( 'INVALID_INPUT', 'A help issue message is required.' );
        }

        $currentUserId = function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
        return $this->helpIssueResolver->resolve(
            $message,
            $currentUserId,
            (string) ( $payload['current_route'] ?? '' ),
            (string) ( $payload['current_module'] ?? '' ),
            (array) ( $payload['session_context'] ?? [] )
        );
    }

    public function clearCache( array $payload ): array {
        return $this->systemOps->clearCache( $payload );
    }

    public function rebuildIndexes( array $payload ): array {
        $store = new HelpSearchStore();
        $indexed = $store->rebuildSearchIndex();

        return [
            'status' => 'success',
            'index' => 'help_search',
            'indexed_articles' => $indexed,
            'message' => sprintf( 'Help search index rebuilt. Indexed %d article(s).', $indexed ),
        ];
    }

    public function reloadConfig( array $payload ): array {
        return [
            'status' => 'success',
            'result' => $this->systemOps->clearCache( $payload ),
            'message' => 'Configuration reload requested through cache invalidation.',
        ];
    }

    public function getSystemStatus( array $payload ): array {
        $ops = Application::has_service( 'operations' ) ? Application::service( 'operations' )->queueSummary() : [];
        $version = Application::has_service( 'system_version' ) ? (array) Application::service( 'system_version' )->current() : [];
        $release = Application::has_service( 'release' ) ? (array) Application::service( 'release' )->status( false ) : [];
        return [
            'status' => 'success',
            'system' => [
                'php_version' => PHP_VERSION,
                'hermes_loaded' => true,
                'queue_summary' => $ops,
                'version' => $version,
                'release' => [
                    'status' => (string) ( $release['status'] ?? '' ),
                    'installed_version' => (string) ( $release['installed_version'] ?? '' ),
                    'installed_tag' => (string) ( $release['installed_tag'] ?? '' ),
                    'latest_tag' => (string) ( $release['latest']['tag'] ?? '' ),
                    'update_available' => ! empty( $release['update_available'] ),
                    'last_checked_at' => (string) ( $release['last_checked_at'] ?? '' ),
                ],
            ],
            'message' => sprintf(
                'System status loaded. Metis %s, PHP %s, release status: %s.',
                (string) ( $version['metis_version'] ?? 'unknown' ),
                PHP_VERSION,
                (string) ( $release['status'] ?? 'unknown' )
            ),
        ];
    }

    public function checkSystemUpdates( array $payload ): array {
        if ( ! Application::has_service( 'release' ) ) {
            return $this->error( 'EXECUTION_FAILED', 'Release manager is unavailable.' );
        }

        try {
            $release = Application::service( 'release' )->checkForUpdates( true, 'hermes' );
        } catch ( \Throwable $throwable ) {
            return $this->error( 'EXECUTION_FAILED', $throwable->getMessage() !== '' ? $throwable->getMessage() : 'System update check failed.' );
        }

        $current = is_array( $release['current'] ?? null ) ? (array) $release['current'] : [];
        $latest = is_array( $release['latest'] ?? null ) ? (array) $release['latest'] : [];
        $repository = is_array( $release['repository'] ?? null ) ? (array) $release['repository'] : [];
        $updateAvailable = ! empty( $release['update_available'] );
        $remoteError = trim( (string) ( $release['remote_error'] ?? '' ) );

        $message = $updateAvailable
            ? sprintf(
                'System update check completed. Update available: %s (%s). Installed: %s (%s).',
                (string) ( $latest['tag'] ?? 'unknown' ),
                (string) ( $latest['version'] ?? 'unknown' ),
                (string) ( $current['tag'] ?? ( $release['installed_tag'] ?? 'unknown' ) ),
                (string) ( $current['version'] ?? ( $release['installed_version'] ?? 'unknown' ) )
            )
            : sprintf(
                'System update check completed. No update is currently available. Installed: %s (%s).',
                (string) ( $current['tag'] ?? ( $release['installed_tag'] ?? 'unknown' ) ),
                (string) ( $current['version'] ?? ( $release['installed_version'] ?? 'unknown' ) )
            );

        if ( $remoteError !== '' ) {
            $message .= ' Release source warning: ' . $remoteError;
        }

        return [
            'status' => ! empty( $release['ok'] ) ? 'success' : 'warning',
            'update_available' => $updateAvailable,
            'installed' => [
                'version' => (string) ( $current['version'] ?? ( $release['installed_version'] ?? '' ) ),
                'tag' => (string) ( $current['tag'] ?? ( $release['installed_tag'] ?? '' ) ),
            ],
            'latest' => [
                'version' => (string) ( $latest['version'] ?? '' ),
                'tag' => (string) ( $latest['tag'] ?? '' ),
            ],
            'repository' => [
                'available' => ! empty( $repository['available'] ),
                'clean' => $repository['clean'] ?? null,
                'head' => (string) ( $repository['head'] ?? '' ),
                'remote' => (string) ( $repository['remote'] ?? '' ),
            ],
            'remote_status' => (string) ( $release['remote_status'] ?? '' ),
            'remote_error' => $remoteError,
            'last_checked_at' => (string) ( $release['last_checked_at'] ?? '' ),
            'release_status' => $release,
            'message' => $message,
        ];
    }

    public function startBackup( array $payload ): array {
        return $this->systemOps->runBackup( $payload );
    }

    public function restoreBackup( array $payload ): array {
        return $this->systemOps->restoreBackup( $payload );
    }

    public function validateBackup( array $payload ): array {
        return $this->systemOps->validateBackup( $payload );
    }

    public function restoreFile( array $payload ): array {
        return $this->systemOps->restoreFile( $payload );
    }

    public function installSystemUpdate( array $payload ): array {
        return $this->systemOps->installSystemUpdate( $payload );
    }

    public function rollbackRelease( array $payload ): array {
        return $this->systemOps->rollbackRelease( $payload );
    }

    public function queueDriveSync( array $payload ): array {
        return $this->systemOps->queueDriveSync( $payload );
    }

    public function queueCalendarSync( array $payload ): array {
        return $this->systemOps->queueCalendarSync( $payload );
    }

    public function drainQueue( array $payload ): array {
        return $this->systemOps->drainQueue( $payload );
    }

    public function buildIntegrityBaseline( array $payload ): array {
        return $this->systemOps->buildIntegrityBaseline( $payload );
    }

    public function runModuleComplianceAudit( array $payload ): array {
        return $this->systemOps->runModuleComplianceAudit( $payload );
    }

    public function prepareBoardWorkspace( array $payload ): array {
        return $this->systemOps->prepareBoardWorkspace( $payload );
    }

    public function createCampaign( array $payload ): array {
        return $this->requireNewsletterAdmin()->createCampaign( $payload );
    }

    public function updateCampaign( array $payload ): array {
        return $this->requireNewsletterAdmin()->updateCampaign( $payload );
    }

    public function publishCampaign( array $payload ): array {
        return $this->requireNewsletterAdmin()->sendCampaign( $payload );
    }

    public function archiveCampaign( array $payload ): array {
        return $this->requireNewsletterAdmin()->archiveCampaign( $payload );
    }

    public function deleteCampaign( array $payload ): array {
        return $this->requireNewsletterAdmin()->deleteCampaign( $payload );
    }

    public function createNewsletter( array $payload ): array {
        return $this->requireNewsletterAdmin()->createCampaign( $payload );
    }

    public function sendNewsletter( array $payload ): array {
        return $this->requireNewsletterAdmin()->sendCampaign( $payload );
    }

    public function scheduleNewsletter( array $payload ): array {
        return $this->requireNewsletterAdmin()->scheduleCampaign( $payload );
    }

    public function cancelNewsletter( array $payload ): array {
        return $this->requireNewsletterAdmin()->cancelCampaign( $payload );
    }

    public function deleteNewsletter( array $payload ): array {
        return $this->requireNewsletterAdmin()->deleteCampaign( $payload );
    }

    public function runFullDiagnostics( array $payload ): array {
        return $this->queueGenericJob(
            'hermes.diagnostics',
            [ 'scope' => (string) ( $payload['scope'] ?? 'system' ) ],
            'Full diagnostics queued. I will use the registered Hermes diagnostics worker.'
        );
    }

    public function checkModules( array $payload ): array {
        if ( ! Application::has_service( 'modules' ) ) {
            return $this->error( 'EXECUTION_FAILED', 'Module loader is unavailable.' );
        }

        $loader = Application::service( 'modules' );
        if ( ! is_object( $loader ) || ! method_exists( $loader, 'all' ) ) {
            return $this->error( 'EXECUTION_FAILED', 'Module loader does not expose module status.' );
        }

        $modules = (array) $loader->all();
        $bootFailures = method_exists( $loader, 'bootFailures' ) ? (array) $loader->bootFailures() : [];
        $compliance = method_exists( $loader, 'complianceReport' )
            ? (array) $loader->complianceReport( false )
            : [ 'summary' => [ 'checked' => count( $modules ), 'failed' => 0, 'passed' => count( $modules ) ], 'results' => [] ];

        $rows = [];
        foreach ( $modules as $slug => $module ) {
            if ( ! is_array( $module ) ) {
                continue;
            }

            $config = (array) ( $module['config'] ?? [] );
            $rows[] = [
                'slug' => (string) ( $module['slug'] ?? $slug ),
                'name' => (string) ( $config['name'] ?? $config['title'] ?? ucwords( str_replace( '_', ' ', (string) $slug ) ) ),
                'domain' => (string) ( $config['domain'] ?? $slug ),
                'version' => (string) ( $config['version'] ?? '' ),
                'required' => ! empty( $config['required'] ),
                'route_count' => count( (array) ( $config['routes'] ?? [] ) ),
                'permission_count' => count( (array) ( $config['permission_definitions'] ?? [] ) ),
            ];
        }

        usort( $rows, static fn ( array $a, array $b ): int => strcmp( (string) ( $a['slug'] ?? '' ), (string) ( $b['slug'] ?? '' ) ) );

        $failedCompliance = (int) ( $compliance['summary']['failed'] ?? 0 );
        $status = $bootFailures === [] && $failedCompliance === 0 ? 'success' : 'warning';
        $loadedList = implode( ', ', array_slice( array_map( static fn ( array $row ): string => (string) ( $row['slug'] ?? '' ), $rows ), 0, 18 ) );
        if ( count( $rows ) > 18 ) {
            $loadedList .= sprintf( ', and %d more', count( $rows ) - 18 );
        }

        $message = sprintf(
            "Module diagnostics completed.\nLoaded modules: %d%s\nCompliance: %d passed, %d failed\nBoot failures: %d%s",
            count( $rows ),
            $loadedList !== '' ? ' (' . $loadedList . ')' : '',
            (int) ( $compliance['summary']['passed'] ?? count( $rows ) ),
            $failedCompliance,
            count( $bootFailures ),
            $bootFailures !== [] ? "\nReview the module diagnostics result for failure details." : ''
        );

        return [
            'status' => $status,
            'summary' => [
                'loaded_count' => count( $rows ),
                'boot_failure_count' => count( $bootFailures ),
                'compliance_checked' => (int) ( $compliance['summary']['checked'] ?? count( $rows ) ),
                'compliance_passed' => (int) ( $compliance['summary']['passed'] ?? count( $rows ) ),
                'compliance_failed' => $failedCompliance,
            ],
            'modules' => $rows,
            'boot_failures' => array_values( $bootFailures ),
            'compliance' => $compliance,
            'message' => $message,
        ];
    }

    public function scanIntegrity( array $payload ): array {
        if ( ! class_exists( 'Metis_Integrity_Manager' ) ) {
            return $this->error( 'EXECUTION_FAILED', 'Integrity manager is unavailable.' );
        }

        $result = \Metis_Integrity_Manager::verify_baseline();
        return [
            'status' => ! empty( $result['ok'] ) ? 'success' : 'warning',
            'integrity' => $result,
            'message' => ! empty( $result['ok'] )
                ? 'Integrity baseline verification passed.'
                : sprintf( 'Integrity baseline verification returned status [%s].', (string) ( $result['status'] ?? 'unknown' ) ),
        ];
    }

    public function checkDb( array $payload ): array {
        $row = $this->db->fetchOne( 'SELECT 1 AS ok' );
        return [
            'status' => $row !== null ? 'success' : 'error',
            'database' => [ 'connected' => $row !== null ],
            'message' => $row !== null ? 'Database connectivity looks healthy.' : 'Database connectivity check failed.',
        ];
    }

    public function checkWorkers( array $payload ): array {
        $workers = function_exists( 'metis_job_queue' ) ? \metis_job_queue()->registeredWorkers() : [];
        $jobs = Application::has_service( 'operations' ) ? Application::service( 'operations' )->recentJobs( 20 ) : [];
        $summary = Application::has_service( 'operations' ) ? Application::service( 'operations' )->queueSummary() : [];

        return [
            'status' => 'success',
            'workers' => $workers,
            'jobs' => $jobs,
            'summary' => $summary,
            'message' => sprintf( 'Worker status loaded. %d worker(s) registered, %d recent job(s) returned.', count( $workers ), count( $jobs ) ),
        ];
    }

    public function recoverModule( array $payload ): array {
        return $this->unsupportedOperation( 'recover_module', 'Module recovery does not have an executable backend yet. Use release rollback or backup restore for supported recovery paths.' );
    }

    public function rollbackModule( array $payload ): array {
        return $this->unsupportedOperation( 'rollback_module', 'Module rollback is not independently executable. Use release rollback for supported rollback behavior.' );
    }

    public function enableModule( array $payload ): array {
        return $this->unsupportedOperation( 'enable_module', 'Module enablement does not have a safe manifest/config writer registered for Hermes yet.' );
    }

    public function disableModule( array $payload ): array {
        return $this->unsupportedOperation( 'disable_module', 'Module disablement does not have a safe manifest/config writer registered for Hermes yet.' );
    }

    public function installModule( array $payload ): array {
        return $this->unsupportedOperation( 'install_module', 'Module installation is not wired to a trusted package source yet.' );
    }

    public function updateModule( array $payload ): array {
        return $this->unsupportedOperation( 'update_module', 'Module-specific updates are not supported separately from trusted system releases.' );
    }

    public function exportData( array $payload ): array {
        return $this->unsupportedOperation( 'export_data', 'Generic data export needs a concrete report or dataset target. Ask Hermes to run or export a specific report.' );
    }

    public function importData( array $payload ): array {
        return $this->unsupportedOperation( 'import_data', 'Generic data import needs a configured import job and source file. Use the Import module workflow for now.' );
    }

    public function deduplicate( array $payload ): array {
        return $this->unsupportedOperation( 'deduplicate', 'Deduplication needs a concrete entity type and merge policy before it can run safely.' );
    }

    public function createJob( array $payload ): array {
        $taskSlug = \metis_key_clean( (string) ( $payload['task_slug'] ?? $payload['subject'] ?? '' ) );
        if ( $taskSlug === '' ) {
            return $this->error( 'INVALID_INPUT', 'A cron task slug is required.' );
        }

        if ( ! class_exists( 'Metis_Cron_Manager' ) || ! method_exists( 'Metis_Cron_Manager', 'registered_tasks' ) ) {
            return $this->error( 'EXECUTION_FAILED', 'Cron task registry is unavailable.' );
        }

        $registeredTasks = (array) \Metis_Cron_Manager::registered_tasks();
        $taskConfig = is_array( $registeredTasks[ $taskSlug ] ?? null ) ? $registeredTasks[ $taskSlug ] : [];
        if ( $taskConfig === [] ) {
            return $this->error( 'INVALID_INPUT', sprintf( 'Cron task [%s] is not registered.', $taskSlug ) );
        }

        if ( array_key_exists( 'enabled', $taskConfig ) && empty( $taskConfig['enabled'] ) ) {
            return $this->error( 'INVALID_INPUT', sprintf( 'Cron task [%s] is disabled.', $taskSlug ) );
        }

        if ( ! Application::has_service( 'operations' ) ) {
            return $this->error( 'EXECUTION_FAILED', 'Operations service is unavailable.' );
        }

        $queued = Application::service( 'operations' )->queueOperation( 'cron.task.run', [ 'task_slug' => $taskSlug ] );
        if ( ! is_array( $queued ) || empty( $queued['ok'] ) ) {
            return $this->error( 'EXECUTION_FAILED', (string) ( $queued['message'] ?? 'Cron task queue request failed.' ) );
        }

        return [
            'status' => 'success',
            'queued' => [
                'operation' => 'cron.task.run',
                'job_code' => (string) ( $queued['job_code'] ?? '' ),
                'queue' => (string) ( $queued['queue_name'] ?? 'system' ),
                'payload' => [ 'task_slug' => $taskSlug ],
            ],
            'message' => sprintf( 'Cron task [%s] queued.', $taskSlug ),
        ];
    }

    public function cancelJob( array $payload ): array {
        $jobCode = $this->normalizeJobCode( $payload );
        if ( $jobCode === '' ) {
            return $this->error( 'INVALID_INPUT', 'A job code is required.' );
        }

        $updated = $this->db->update(
            \Metis_Tables::get( 'job_queue' ),
            [ 'status' => 'failed', 'last_error' => 'Canceled by Hermes operator.' ],
            [ 'job_code' => $jobCode ],
            [ '%s', '%s' ],
            [ '%s' ]
        );

        return [
            'status' => $updated ? 'success' : 'error',
            'job_code' => $jobCode,
            'message' => $updated ? 'Job canceled.' : 'Job was not updated.',
        ];
    }

    public function retryJob( array $payload ): array {
        $jobCode = $this->normalizeJobCode( $payload );
        if ( $jobCode === '' ) {
            return $this->error( 'INVALID_INPUT', 'A job code is required.' );
        }

        $updated = $this->db->update(
            \Metis_Tables::get( 'job_queue' ),
            [
                'status' => 'queued',
                'available_at' => \metis_current_time( 'mysql' ),
                'failed_at' => null,
                'last_error' => null,
            ],
            [ 'job_code' => $jobCode ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%s' ]
        );

        return [
            'status' => $updated ? 'success' : 'error',
            'job_code' => $jobCode,
            'message' => $updated ? 'Job re-queued.' : 'Job was not updated.',
        ];
    }

    public function listJobs( array $payload ): array {
        $jobs = Application::has_service( 'operations' ) ? Application::service( 'operations' )->recentJobs( 50 ) : [];
        return [
            'status' => 'success',
            'jobs' => $jobs,
            'count' => count( $jobs ),
            'message' => sprintf( 'Found %d jobs.', count( $jobs ) ),
        ];
    }

    public function auditPermissions( array $payload ): array {
        if ( ! Application::has_service( 'security_diagnostics' ) ) {
            return $this->error( 'EXECUTION_FAILED', 'Security diagnostics service is unavailable.' );
        }

        return Application::service( 'security_diagnostics' )->diagnosePermissions( [
            'query' => (string) ( $payload['query'] ?? $payload['subject'] ?? 'audit permissions' ),
        ] );
    }

    public function verifyIntegrity( array $payload ): array {
        return $this->scanIntegrity( $payload );
    }

    public function rotateKeys( array $payload ): array {
        return $this->unsupportedOperation( 'rotate_keys', 'Key rotation does not have a registered key-management backend for Hermes execution yet.' );
    }

    public function validateRoutes( array $payload ): array {
        $router = function_exists( 'metis_http_router' ) ? \metis_http_router() : null;
        $routes = Application::has_service( 'modules' ) && method_exists( Application::service( 'modules' ), 'routes' )
            ? (array) Application::service( 'modules' )->routes()
            : [];
        $missingHandlers = [];
        foreach ( $routes as $route ) {
            if ( ! is_array( $route ) ) {
                continue;
            }
            $handler = $route['handler'] ?? null;
            if ( is_string( $handler ) && str_contains( $handler, '::' ) && ! is_callable( explode( '::', $handler, 2 ) ) ) {
                $missingHandlers[] = [
                    'module' => (string) ( $route['module'] ?? '' ),
                    'path' => (string) ( $route['path'] ?? '' ),
                    'handler' => $handler,
                ];
            }
        }

        return [
            'status' => is_object( $router ) && $missingHandlers === [] ? 'success' : ( is_object( $router ) ? 'warning' : 'error' ),
            'route_count' => count( $routes ),
            'missing_handlers' => $missingHandlers,
            'message' => is_object( $router )
                ? sprintf( 'Route validation completed. %d route(s), %d missing handler(s).', count( $routes ), count( $missingHandlers ) )
                : 'Router runtime is unavailable.',
        ];
    }

    public function verifyNonce( array $payload ): array {
        $token = (string) ( $payload['nonce'] ?? '' );
        $action = (string) ( $payload['nonce_action'] ?? 'metis_hermes_execute_action' );
        if ( $token === '' || ! function_exists( 'metis_runtime_verify_nonce' ) ) {
            return $this->error( 'INVALID_INPUT', 'Nonce and runtime verifier are required.' );
        }

        return [
            'status' => \metis_runtime_verify_nonce( $token, $action ) ? 'success' : 'error',
            'message' => \metis_runtime_verify_nonce( $token, $action ) ? 'Nonce verified.' : 'Nonce verification failed.',
        ];
    }

    public function runEnclaveTest( array $payload ): array {
        if ( ! function_exists( 'metis_security_enclave' ) ) {
            return $this->error( 'ENCLAVE_UNAVAILABLE', 'Secure Enclave is unavailable.' );
        }

        require_once METIS_SRC_PATH . 'Metis/Core/Security/EnclaveToolRuntime.php';

        $checks = [];
        $enclave = \metis_security_enclave();
        $checks[] = [
            'check' => 'enclave_service',
            'status' => is_object( $enclave ) ? 'pass' : 'fail',
            'message' => is_object( $enclave ) ? 'Secure Enclave service resolved.' : 'Secure Enclave service did not resolve.',
        ];

        $readOnlyOperation = function_exists( 'metis_core_enclave_operation_for_tool' )
            ? \metis_core_enclave_operation_for_tool( [
                'tool_key' => 'hermes.metis.run_enclave_test',
                'enclave_action' => 'hermes.tool.execute',
                'requires_approval' => false,
            ] )
            : '';
        $checks[] = [
            'check' => 'read_only_operation_mapping',
            'status' => $readOnlyOperation === 'hermes.tool.query' ? 'pass' : 'fail',
            'message' => sprintf( 'Read-only operation maps to [%s].', $readOnlyOperation !== '' ? $readOnlyOperation : 'unavailable' ),
        ];

        $writeOperation = function_exists( 'metis_core_enclave_operation_for_tool' )
            ? \metis_core_enclave_operation_for_tool( [
                'tool_key' => 'hermes.system.clear_cache',
                'enclave_action' => 'hermes.tool.execute',
                'requires_approval' => true,
            ] )
            : '';
        $checks[] = [
            'check' => 'approval_operation_mapping',
            'status' => $writeOperation === 'hermes.tool.execute' ? 'pass' : 'fail',
            'message' => sprintf( 'Approval-gated operation maps to [%s].', $writeOperation !== '' ? $writeOperation : 'unavailable' ),
        ];

        $requestContext = function_exists( 'metis_security_runtime_request_context' )
            ? \metis_security_runtime_request_context( [
                'tool_key' => 'hermes.metis.run_enclave_test',
                'payload' => $payload,
            ] )
            : [];
        $requestId = (string) ( $requestContext['meta']['request_id'] ?? '' );
        $checks[] = [
            'check' => 'request_context',
            'status' => $requestId !== '' ? 'pass' : 'warn',
            'message' => $requestId !== '' ? 'Runtime request context includes a request id.' : 'Runtime request context is available without a request id.',
        ];

        $failed = count( array_filter( $checks, static fn ( array $check ): bool => (string) ( $check['status'] ?? '' ) === 'fail' ) );
        $warnings = count( array_filter( $checks, static fn ( array $check ): bool => (string) ( $check['status'] ?? '' ) === 'warn' ) );

        return [
            'status' => $failed > 0 ? 'error' : ( $warnings > 0 ? 'warning' : 'success' ),
            'summary' => [
                'passed' => count( $checks ) - $failed - $warnings,
                'warnings' => $warnings,
                'failed' => $failed,
            ],
            'checks' => $checks,
            'request_id' => $requestId,
            'message' => sprintf(
                'Secure Enclave test completed: %d passed, %d warning(s), %d failed.',
                count( $checks ) - $failed - $warnings,
                $warnings,
                $failed
            ),
        ];
    }

    private function queueGenericJob( string $jobType, array $payload, string $message ): array {
        $jobType = trim( strtolower( preg_replace( '/[^a-z0-9._-]+/', '.', $jobType ) ?? '' ) );
        if ( $jobType === '' ) {
            return $this->error( 'INVALID_INPUT', 'A valid job type is required.' );
        }

        $jobQueue = $this->jobs ?? ( function_exists( 'metis_job_queue' ) ? \metis_job_queue() : null );
        if ( ! $jobQueue instanceof JobQueue ) {
            return $this->error( 'EXECUTION_FAILED', 'Job queue is unavailable.' );
        }

        if ( ! in_array( $jobType, $jobQueue->registeredWorkers(), true ) ) {
            return $this->error(
                'EXECUTION_FAILED',
                sprintf( 'No worker is registered for job type [%s]. Hermes did not queue a non-executable job.', $jobType )
            );
        }

        $queued = $jobQueue->enqueue(
            $jobType,
            $payload,
            [
                'queue' => 'hermes',
                'priority' => 20,
                'max_attempts' => 2,
                'created_by' => function_exists( 'metis_current_user_id' ) ? \metis_current_user_id() : 0,
            ]
        );

        if ( empty( $queued['ok'] ) ) {
            return $this->error( 'EXECUTION_FAILED', (string) ( $queued['message'] ?? 'Job queue request failed.' ) );
        }

        return [
            'status' => 'queued',
            'job_id' => (int) ( $queued['job_id'] ?? 0 ),
            'job_code' => (string) ( $queued['job_code'] ?? '' ),
            'job_type' => $jobType,
            'message' => $message,
        ];
    }

    private function normalizeJobCode( array $payload ): string {
        $jobCode = trim( (string) ( $payload['job_code'] ?? $payload['job_key'] ?? $payload['subject'] ?? '' ) );
        if ( $jobCode === '' ) {
            return '';
        }

        return strtoupper( preg_replace( '/[^A-Z0-9_-]+/i', '', $jobCode ) ?? '' );
    }

    private function unsupportedOperation( string $operation, string $message ): array {
        return [
            'status' => 'error',
            'error_code' => 'TOOL_NOT_FOUND',
            'operation' => $operation,
            'message' => $message,
        ];
    }

    private function error( string $code, string $message ): array {
        return [
            'status' => 'error',
            'error_code' => $code,
            'message' => $message,
        ];
    }

    private function requireNewsletterAdmin(): HermesNewsletterAdminService {
        if ( $this->newsletterAdmin === null ) {
            throw new \RuntimeException( 'Newsletter campaign administration is unavailable.' );
        }

        return $this->newsletterAdmin;
    }
}
