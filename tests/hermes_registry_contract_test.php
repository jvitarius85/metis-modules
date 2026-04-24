<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

require_once $root . '/src/Metis/Hermes/HermesCommandRegistry.php';
require_once $root . '/src/Metis/Hermes/HermesToolRegistry.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$commands = ( new \Metis\Hermes\HermesCommandRegistry() )->definitions();
$tools = ( new \Metis\Hermes\HermesToolRegistry() )->definitions();

$required = [
    'create_user', 'update_user', 'disable_user', 'enable_user', 'assign_role', 'remove_role', 'list_users', 'get_user',
    'clear_cache', 'rebuild_indexes', 'reload_config', 'get_system_status',
    'run_full_diagnostics', 'scan_integrity', 'check_db', 'check_workers',
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
}

$engineSource = file_get_contents( $root . '/src/Metis/Hermes/HermesExecutionEngine.php' ) ?: '';
$assert( str_contains( $engineSource, "require_once METIS_PATH . 'core/enclave/execute.php'" ), 'Execution engine must route through core/enclave/execute.php.' );
$assert( ! str_contains( $engineSource, 'Application::service' ), 'Execution engine should not directly resolve Hermes services.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes registry contract checks passed.\n" );
