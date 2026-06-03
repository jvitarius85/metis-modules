<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesToolRegistry.php';
require_once $root . '/src/Metis/Services/HermesCapabilityService.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$commands = ( new \Metis\Hermes\HermesCommandRegistry() )->definitions();
$tools = ( new \Metis\Hermes\HermesToolRegistry() )->definitions();

$required = [
    'create_user', 'update_user', 'disable_user', 'enable_user', 'user_delete', 'user_unlock', 'assign_role', 'remove_role', 'manage_workspace_groups', 'reset_user_mfa', 'link_drive_folder', 'list_users', 'get_user',
    'lookup_profile', 'get_entity_attribute', 'resolve_help_issue', 'diagnose_permissions', 'query_giving_summary', 'query_capability_actors',
    'clear_cache', 'backup_validate', 'rebuild_indexes', 'reload_config', 'get_system_status', 'check_system_updates', 'release_rollback',
    'drive_sync', 'calendar_sync', 'queue_drain', 'integrity_baseline', 'module_compliance_audit',
    'board_workspace_prepare',
    'run_full_diagnostics', 'check_modules', 'scan_integrity', 'check_db', 'check_workers',
    'recover_module', 'restore_file', 'rollback_module',
    'enable_module', 'disable_module', 'install_module', 'update_module',
    'export_data', 'import_data', 'deduplicate',
    'create_job', 'cancel_job', 'retry_job', 'list_jobs',
    'audit_permissions', 'verify_integrity', 'rotate_keys',
    'validate_routes', 'verify_nonce', 'run_enclave_test',
];

foreach ( $required as $commandKey ) {
    $assert( isset( $commands[ $commandKey ] ), sprintf( 'Required command [%s] is missing.', $commandKey ) );
    $command = (array) ( $commands[ $commandKey ] ?? [] );
    $toolKey = (string) ( $command['tool_key'] ?? '' );
    $assert( $toolKey !== '', sprintf( 'Command [%s] is missing a tool mapping.', $commandKey ) );
    $assert( isset( $tools[ $toolKey ] ), sprintf( 'Tool [%s] mapped from [%s] is missing.', $toolKey, $commandKey ) );
}

foreach ( $tools as $toolKey => $tool ) {
    $assert( (string) ( $tool['tool_key'] ?? '' ) === $toolKey, sprintf( 'Tool [%s] has an inconsistent tool_key.', $toolKey ) );
    $assert( ! empty( $tool['enclave_action'] ), sprintf( 'Tool [%s] is missing enclave_action.', $toolKey ) );
    $assert( isset( $tool['input_schema'] ), sprintf( 'Tool [%s] is missing input_schema.', $toolKey ) );
    $assert( isset( $tool['output_schema'] ), sprintf( 'Tool [%s] is missing output_schema.', $toolKey ) );
    $dispatch = (array) ( $tool['dispatch'] ?? [] );
    $assert( (string) ( $dispatch['service'] ?? '' ) !== '', sprintf( 'Tool [%s] is missing dispatch service.', $toolKey ) );
    $assert( (string) ( $dispatch['method'] ?? '' ) !== '', sprintf( 'Tool [%s] is missing dispatch method.', $toolKey ) );
    if ( (string) ( $dispatch['service'] ?? '' ) === 'hermes_capabilities' ) {
        $assert(
            method_exists( \Metis\Services\HermesCapabilityService::class, (string) ( $dispatch['method'] ?? '' ) ),
            sprintf( 'Tool [%s] dispatches to missing HermesCapabilityService method [%s].', $toolKey, (string) ( $dispatch['method'] ?? '' ) )
        );
    }
}

$manageWorkspaceGroupsTool = (array) ( $tools['hermes.user.manage_workspace_groups'] ?? [] );
$manageWorkspaceGroupsSchema = (array) ( $manageWorkspaceGroupsTool['input_schema']['properties'] ?? [] );
$assert( isset( $manageWorkspaceGroupsSchema['group_emails'] ), 'Workspace group management tool schema should declare group_emails.' );

$linkDriveFolderTool = (array) ( $tools['hermes.user.link_drive_folder'] ?? [] );
$linkDriveFolderSchema = (array) ( $linkDriveFolderTool['input_schema']['properties'] ?? [] );
$assert( isset( $linkDriveFolderSchema['folder_id'] ), 'Drive folder linking tool schema should declare folder_id.' );

$engineSource = file_get_contents( $root . '/src/Metis/Hermes/HermesExecutionEngine.php' ) ?: '';
$toolExecutorSource = file_get_contents( $root . '/src/Metis/Hermes/HermesToolExecutor.php' ) ?: '';
$capabilitySource = file_get_contents( $root . '/src/Metis/Services/HermesCapabilityService.php' ) ?: '';
$assert( str_contains( $toolExecutorSource, "require_once METIS_SRC_PATH . 'Metis/Core/Security/EnclaveToolRuntime.php'" ), 'Tool executor must route through src/Metis/Core/Security/EnclaveToolRuntime.php.' );
$assert( ! str_contains( $engineSource, 'Application::service' ), 'Execution engine should not directly resolve Hermes services.' );
$assert( ! str_contains( $toolExecutorSource, 'Application::service' ), 'Tool executor should not directly resolve Hermes services.' );
$assert( str_contains( $capabilitySource, 'registeredWorkers()' ), 'Hermes queued jobs must verify that a worker is registered.' );

preg_match_all( "/queueGenericJob\\(\\s*'([^']+)'/", $capabilitySource, $queuedTypes );
$allowedQueuedTypes = [ 'hermes.diagnostics' ];
foreach ( (array) ( $queuedTypes[1] ?? [] ) as $queuedType ) {
    $assert(
        in_array( (string) $queuedType, $allowedQueuedTypes, true ),
        sprintf( 'Hermes capability queues unregistered or unsupported job type [%s].', (string) $queuedType )
    );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes registry contract checks passed.\n" );
