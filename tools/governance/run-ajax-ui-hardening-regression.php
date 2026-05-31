<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$checks = [
    [
        'label' => 'Governance checker',
        'command' => [ PHP_BINARY, $root . '/tools/governance/check-ajax-ui-hardening.php' ],
    ],
    [
        'label' => 'AJAX/UI hardening contract',
        'command' => [ PHP_BINARY, $root . '/system/tests/ajax_ui_hardening_contract_test.php' ],
    ],
    [
        'label' => 'View/service delegation contract',
        'command' => [ PHP_BINARY, $root . '/system/tests/view_service_delegation_contract_test.php' ],
    ],
    [
        'label' => 'Donations read service runtime',
        'command' => [ PHP_BINARY, $root . '/system/tests/donations_read_service_runtime_test.php' ],
    ],
    [
        'label' => 'Newsletter read service runtime',
        'command' => [ PHP_BINARY, $root . '/system/tests/newsletter_read_service_runtime_test.php' ],
    ],
    [
        'label' => 'People read service runtime',
        'command' => [ PHP_BINARY, $root . '/system/tests/people_read_service_runtime_test.php' ],
    ],
    [
        'label' => 'Accessibility governance',
        'command' => [ PHP_BINARY, $root . '/system/tests/accessibility_governance_test.php' ],
    ],
    [
        'label' => 'Production governance',
        'command' => [ PHP_BINARY, $root . '/system/tests/production_governance_test.php' ],
    ],
    [
        'label' => 'Security governance',
        'command' => [ PHP_BINARY, $root . '/system/tests/security_governance_test.php' ],
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

echo "Metis AJAX/UI Hardening Regression Runner\n";
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

echo "All AJAX/UI hardening regression checks passed.\n";
