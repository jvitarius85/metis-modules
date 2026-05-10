<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Metis/Core/Runtime/CliToolGuard.php';
require_once $root . '/src/Metis/Core/Runtime/CliProcessContext.php';
require_once $root . '/src/Metis/Core/Services/ProcessRunner.php';
metis_require_cli_tool();

final class MetisTestSuiteCli {
    /** @var array<string, mixed> */
    private array $options;

    /** @var array<int, array<string, mixed>> */
    private array $results = [];

    public function __construct(private readonly string $root, array $argv) {
        $this->options = $this->parseOptions($argv);
    }

    public function run(): int {
        if (!empty($this->options['help'])) {
            $this->printUsage();
            return 0;
        }

        $tests = $this->discoverTests();
        if ($tests === []) {
            fwrite(STDERR, "No test files found under tests/.\n");
            return 1;
        }

        foreach ($tests as $relativePath) {
            $this->results[] = $this->runTest($relativePath);
        }

        $this->printSummary();

        return $this->hasFailures() ? 1 : 0;
    }

    private function printUsage(): void {
        $script = 'php ' . $this->root . '/tools/test_suite.php';
        $lines = [
            'Metis application test suite CLI',
            '',
            'Usage:',
            '  ' . $script,
            '  ' . $script . ' --json',
            '  ' . $script . ' --filter=newsletter',
            '',
            'Options:',
            '  --json            Print machine-readable JSON.',
            '  --filter=TERM     Run only tests whose relative path contains TERM.',
            '  --help            Show this help message.',
        ];

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /** @return array<string, mixed> */
    private function parseOptions(array $argv): array {
        $options = [
            'json' => false,
            'help' => false,
            'filter' => '',
        ];

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--json') {
                $options['json'] = true;
            } elseif ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;
            } elseif (str_starts_with($arg, '--filter=')) {
                $options['filter'] = trim((string) substr($arg, strlen('--filter=')));
            }
        }

        return $options;
    }

    /** @return array<int, string> */
    private function discoverTests(): array {
        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root . '/tests', FilesystemIterator::SKIP_DOTS)
        );

        $filter = strtolower((string) $this->options['filter']);

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();
            if (!preg_match('/_test\.(php|js)$/', $filename)) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($this->root) + 1);
            if ($relativePath === false) {
                continue;
            }

            if ($filter !== '' && !str_contains(strtolower($relativePath), $filter)) {
                continue;
            }

            $paths[] = str_replace('\\', '/', $relativePath);
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return array<string, mixed> */
    private function runTest(string $relativePath): array {
        $path = $this->root . '/' . $relativePath;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $command = match ($extension) {
            'php' => ['php', $path],
            'js' => ['node', $path],
            default => throw new RuntimeException('Unsupported test extension: ' . $relativePath),
        };

        $startedAt = microtime(true);
        $process = $this->runCommand($command, $this->root);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'path' => $relativePath,
            'language' => $extension,
            'status' => $process['exit_code'] === 0 ? 'pass' : 'fail',
            'exit_code' => $process['exit_code'],
            'duration_ms' => $durationMs,
            'output' => trim($process['output']),
        ];
    }

    /**
     * @param array<int, string> $command
     * @return array{exit_code:int, output:string}
     */
    private function runCommand(array $command, string $cwd): array {
        $result = ( new \Metis\Core\Services\ProcessRunner() )->run(
            $command,
            $cwd,
            metis_cli_process_context( 'test_suite.run_test', 'system.tests.execute', [ 'tool' => 'test_suite.php' ] )
        );

        $output = trim((string) $result['stdout']);
        $stderr = trim((string) $result['stderr']);
        if ($stderr !== '') {
            $output = $output === '' ? $stderr : $output . PHP_EOL . $stderr;
        }

        return [
            'exit_code' => (int) $result['exit_code'],
            'output' => $output,
        ];
    }

    private function printSummary(): void {
        $passed = 0;
        $failed = 0;

        foreach ($this->results as $result) {
            if (($result['status'] ?? '') === 'pass') {
                $passed++;
            } else {
                $failed++;
            }
        }

        if (!empty($this->options['json'])) {
            fwrite(STDOUT, json_encode([
                'ok' => $failed === 0,
                'summary' => [
                    'total' => count($this->results),
                    'passed' => $passed,
                    'failed' => $failed,
                ],
                'results' => $this->results,
                'checked_at' => gmdate('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            return;
        }

        foreach ($this->results as $result) {
            $line = sprintf(
                '[%s] %s (%d ms)',
                strtoupper((string) $result['status']),
                (string) $result['path'],
                (int) ($result['duration_ms'] ?? 0)
            );
            fwrite(STDOUT, $line . PHP_EOL);

            $output = trim((string) ($result['output'] ?? ''));
            if ($output !== '' && ($result['status'] ?? '') !== 'pass') {
                fwrite(STDOUT, $output . PHP_EOL);
            }
        }

        fwrite(
            STDOUT,
            sprintf(
                'Summary: %d total, %d passed, %d failed',
                count($this->results),
                $passed,
                $failed
            ) . PHP_EOL
        );
    }

    private function hasFailures(): bool {
        foreach ($this->results as $result) {
            if (($result['status'] ?? '') !== 'pass') {
                return true;
            }
        }

        return false;
    }
}

$cli = new MetisTestSuiteCli($root, $argv);
exit($cli->run());
