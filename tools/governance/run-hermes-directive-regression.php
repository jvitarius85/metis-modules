<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$checks = [
    [
        'label' => 'Hermes directive completion audit',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_directive_completion_audit_test.php' ],
    ],
    [
        'label' => 'Security governance',
        'command' => [ PHP_BINARY, $root . '/system/tests/security_governance_test.php' ],
    ],
    [
        'label' => 'Hermes registry contract',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_registry_contract_test.php' ],
    ],
    [
        'label' => 'Hermes blocked gateway contract',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_blocked_gateway_contract_test.php' ],
    ],
    [
        'label' => 'Hermes unsupported operations contract',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_unsupported_operations_contract_test.php' ],
    ],
    [
        'label' => 'Operations registry framework',
        'command' => [ PHP_BINARY, $root . '/system/tests/operations_registry_framework_test.php' ],
    ],
    [
        'label' => 'Hermes operations registry',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_operations_registry_test.php' ],
    ],
    [
        'label' => 'Hermes dashboard payload contract',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_dashboard_payload_contract_test.php' ],
    ],
    [
        'label' => 'Hermes catalog parity contract',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_catalog_parity_contract_test.php' ],
    ],
    [
        'label' => 'Hermes conversational parser',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_conversational_parser_test.php' ],
    ],
    [
        'label' => 'Hermes pending workflow',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_pending_workflow_test.php' ],
    ],
    [
        'label' => 'Hermes recent entity memory',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_recent_entity_memory_test.php' ],
    ],
    [
        'label' => 'Hermes workflow continuation',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_workflow_continuation_test.php' ],
    ],
    [
        'label' => 'Hermes disambiguation engine',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_disambiguation_engine_test.php' ],
    ],
    [
        'label' => 'Hermes multistep operations',
        'command' => [ PHP_BINARY, $root . '/system/tests/hermes_multistep_operations_test.php' ],
    ],
    [
        'label' => 'Intelligence dashboard services',
        'command' => [ PHP_BINARY, $root . '/system/tests/intelligence_dashboard_services_test.php' ],
    ],
    [
        'label' => 'Intelligence recommendation service',
        'command' => [ PHP_BINARY, $root . '/system/tests/intelligence_recommendation_service_test.php' ],
    ],
    [
        'label' => 'Intelligence trend service',
        'command' => [ PHP_BINARY, $root . '/system/tests/intelligence_trend_service_test.php' ],
    ],
];

$run = static function ( array $command, string $cwd ): array {
    $descriptor = [
        0 => [ 'pipe', 'r' ],
        1 => [ 'pipe', 'w' ],
        2 => [ 'pipe', 'w' ],
    ];

    $process = proc_open( $command, $descriptor, $pipes, $cwd );
    if ( ! is_resource( $process ) ) {
        return [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Failed to start process.',
        ];
    }

    fclose( $pipes[0] );
    $stdout = stream_get_contents( $pipes[1] );
    $stderr = stream_get_contents( $pipes[2] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    $exitCode = proc_close( $process );

    return [
        'exit_code' => is_int( $exitCode ) ? $exitCode : 1,
        'stdout' => is_string( $stdout ) ? $stdout : '',
        'stderr' => is_string( $stderr ) ? $stderr : '',
    ];
};

echo "Metis Hermes Directive Regression Runner\n";
echo "Repository: {$root}\n\n";

$failed = false;

foreach ( $checks as $check ) {
    $label = (string) $check['label'];
    $command = (array) $check['command'];

    echo "[{$label}]\n";
    echo '$ ' . implode( ' ', array_map( 'escapeshellarg', $command ) ) . "\n";

    $result = $run( $command, $root );
    $stdout = trim( (string) $result['stdout'] );
    $stderr = trim( (string) $result['stderr'] );

    if ( $stdout !== '' ) {
        echo $stdout . "\n";
    }
    if ( $stderr !== '' ) {
        echo $stderr . "\n";
    }

    if ( (int) $result['exit_code'] !== 0 ) {
        $failed = true;
        echo "Result: FAIL\n\n";
        break;
    }

    echo "Result: PASS\n\n";
}

if ( $failed ) {
    exit( 1 );
}

echo "All Hermes directive regression checks passed.\n";
