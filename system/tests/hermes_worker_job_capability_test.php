<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once $root . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( $root ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap', 'service_registry', 'ajax', 'router', 'security_runtime_bridge' ] );
metis_standalone_boot();
metis_register_core_services();
\Metis\Modules\Hermes\HermesModule::boot();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$capabilities = \Metis\Core\Application::service( 'hermes_capabilities' );
$operations = \Metis\Core\Application::service( 'operations' );
$db = \Metis\Core\Application::service( 'db' );

$createJob = $capabilities->createJob( [ 'task_slug' => 'module_compliance_audit' ] );
$queued = (array) ( $createJob['queued'] ?? [] );
$assert( (string) ( $createJob['status'] ?? '' ) === 'success', 'Create job capability should succeed for a registered cron task.' );
$assert( (string) ( $queued['operation'] ?? '' ) === 'cron.task.run', 'Create job capability should queue the canonical cron.task.run operation.' );
$assert( (string) ( $queued['payload']['task_slug'] ?? '' ) === 'module_compliance_audit', 'Create job capability should preserve the cron task slug.' );
$assert( (string) ( $createJob['message'] ?? '' ) === 'Cron task [module_compliance_audit] queued.', 'Create job capability should return the bounded cron task message.' );

$invalidJob = $capabilities->createJob( [ 'task_slug' => 'not_a_real_task' ] );
$assert( (string) ( $invalidJob['status'] ?? '' ) === 'error', 'Create job capability should reject unknown cron tasks.' );
$assert( (string) ( $invalidJob['error_code'] ?? '' ) === 'INVALID_INPUT', 'Unknown cron tasks should return INVALID_INPUT.' );

$queuedOperation = $operations->queueOperation( 'cron.task.run', [ 'task_slug' => 'module_compliance_audit' ] );
$jobCode = (string) ( $queuedOperation['job_code'] ?? '' );
$assert( ! empty( $queuedOperation['ok'] ) && $jobCode !== '', 'Fixture job should be queued for cancel/retry tests.' );

$cancel = $capabilities->cancelJob( [ 'job_key' => $jobCode ] );
$jobRow = $db->fetchOne(
    'SELECT status, last_error FROM ' . \Metis_Tables::get( 'job_queue' ) . ' WHERE job_code = %s LIMIT 1',
    [ $jobCode ]
);
$assert( (string) ( $cancel['status'] ?? '' ) === 'success', 'Cancel job capability should accept parsed job_key payloads.' );
$assert( (string) ( $jobRow['status'] ?? '' ) === 'failed', 'Cancel job capability should mark the job as failed.' );
$assert( (string) ( $jobRow['last_error'] ?? '' ) === 'Canceled by Hermes operator.', 'Cancel job capability should annotate the cancellation reason.' );

$retry = $capabilities->retryJob( [ 'job_key' => $jobCode ] );
$retriedRow = $db->fetchOne(
    'SELECT status, failed_at, last_error FROM ' . \Metis_Tables::get( 'job_queue' ) . ' WHERE job_code = %s LIMIT 1',
    [ $jobCode ]
);
$assert( (string) ( $retry['status'] ?? '' ) === 'success', 'Retry job capability should accept parsed job_key payloads.' );
$assert( (string) ( $retriedRow['status'] ?? '' ) === 'queued', 'Retry job capability should return the job to queued status.' );
$assert( empty( $retriedRow['last_error'] ), 'Retry job capability should clear the previous error.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Hermes worker job capability checks passed.\n" );
