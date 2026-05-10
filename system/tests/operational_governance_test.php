<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$system = dirname( __DIR__ );
$root = dirname( $system );

if ( ! defined( 'METIS_PATH' ) ) {
    define( 'METIS_PATH', rtrim( $root, '/\\' ) . '/' );
}

require_once $system . '/src/Metis/Core/Runtime/CliProcessContext.php';
require_once $system . '/src/Metis/Core/Services/ProcessRunner.php';
require_once $system . '/src/Metis/Core/Services/FileService.php';

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$runner = new \Metis\Core\Services\ProcessRunner();
$missingContext = $runner->run( [ PHP_BINARY, '-r', 'echo "blocked";' ], $root );
$assert( (int) $missingContext['exit_code'] === 1, 'ProcessRunner must reject execution without explicit context.' );
$assert( str_contains( (string) $missingContext['stderr'], 'security_context' ), 'ProcessRunner rejection must explain the missing context.' );

$allowed = $runner->run(
    [ PHP_BINARY, '-r', 'echo "ok";' ],
    $root,
    metis_cli_process_context( 'operational_governance_test.process', 'system.tests.execute', [ 'tool' => 'operational_governance_test.php' ] ),
    10
);
$assert( (int) $allowed['exit_code'] === 0, 'ProcessRunner must allow preauthorized CLI tool context.' );
$assert( trim( (string) $allowed['stdout'] ) === 'ok', 'ProcessRunner must return stdout for governed execution.' );

$files = new \Metis\Core\Services\FileService();
$testDir = $root . '/storage/runtime/governance-tests';
$testPath = $testDir . '/file-service-test.txt';
$files->write( $testPath, 'governed-write' );
$assert( is_file( $testPath ), 'FileService must write managed files.' );
$assert( $files->read( $testPath ) === 'governed-write', 'FileService must read the managed write payload.' );
$files->remove( $testDir );
$assert( ! file_exists( $testDir ), 'FileService must remove managed directories recursively.' );

$websiteBootstrap = (string) file_get_contents( $system . '/modules/website/bootstrap.php' );
$routerRuntime = (string) file_get_contents( $system . '/src/Metis/Core/Routing/RouterRuntime.php' );
$securityBridge = (string) file_get_contents( $system . '/src/Metis/Core/Security/SecurityRuntimeBridge.php' );
foreach ( [ 'website.theme_css', 'website.homepage', 'website.page' ] as $routeName ) {
    $assert( str_contains( $websiteBootstrap, "'{$routeName}'" ), 'Website route must be registered: ' . $routeName );
}
$assert( substr_count( $websiteBootstrap, "[ 'route.security' ]" ) >= 3, 'Website public routes must attach route.security middleware.' );
$assert( str_contains( $routerRuntime, "case 'website.theme_css':" ), 'Router policy must classify website theme CSS route.' );
$assert( str_contains( $routerRuntime, "case 'website.homepage':" ), 'Router policy must classify website homepage route.' );
$assert( str_contains( $routerRuntime, "case 'website.page':" ), 'Router policy must classify website page route.' );
$assert( str_contains( $securityBridge, "'route.website_homepage'" ), 'Security bridge must register website homepage policy.' );
$assert( str_contains( $securityBridge, "'route.website_page'" ), 'Security bridge must register website page policy.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Operational governance checks passed.\n" );
