<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);

final class MetisSecurityAuditCli {
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

        if (!empty($this->options['internal'])) {
            $this->runInternalTests();
        }

        if (!empty($this->options['live']) || !empty($this->options['all'])) {
            $this->runLiveChecks();
        }

        if (!empty($this->options['third_party']) || !empty($this->options['all'])) {
            $this->runThirdPartyChecks();
        }

        if ($this->results === []) {
            $this->printUsage();
            return 1;
        }

        $this->printSummary();

        return $this->hasFailures() ? 1 : 0;
    }

    private function printUsage(): void {
        $script = 'php ' . $this->root . '/tools/security_audit.php';
        $lines = [
            'Metis security audit CLI',
            '',
            'Usage:',
            '  ' . $script . ' [--internal] [--live --base-url=http://localhost/metis --login-url=http://localhost/metis/login]',
            '  ' . $script . ' [--third-party --base-url=http://localhost/metis]',
            '  ' . $script . ' --all --base-url=http://localhost/metis --login-url=http://localhost/metis/login',
            '',
            'Options:',
            '  --internal              Run the built-in Metis PHP security tests.',
            '  --live                  Probe a running Metis instance for headers and auth throttling.',
            '  --third-party           Run third-party scanners if they are installed locally.',
            '  --all                   Run internal, live, and third-party checks.',
            '  --base-url=URL          Base URL for live and third-party checks.',
            '  --login-url=URL         Login URL for brute-force and rate-limit probes.',
            '                         ZAP uses the installed app bundle when available and writes reports to /tmp.',
            '  --attempts=N            Number of invalid login attempts to send. Default: 8',
            '  --username=VALUE        Username or email used for the invalid login probe.',
            '  --password=VALUE        Password used for the invalid login probe.',
            '  --json                  Print machine-readable JSON instead of text.',
            '  --help                  Show this help message.',
            '',
            'Examples:',
            '  ' . $script . ' --internal',
            '  ' . $script . ' --all --base-url=http://localhost/metis --login-url=http://localhost/metis/login',
        ];

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    /** @return array<string, mixed> */
    private function parseOptions(array $argv): array {
        $defaults = [
            'internal' => false,
            'live' => false,
            'third_party' => false,
            'all' => false,
            'base_url' => '',
            'login_url' => '',
            'attempts' => 8,
            'username' => 'security-audit@example.test',
            'password' => 'InvalidPassword!234',
            'json' => false,
            'help' => false,
        ];

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--internal') {
                $defaults['internal'] = true;
            } elseif ($arg === '--live') {
                $defaults['live'] = true;
            } elseif ($arg === '--third-party') {
                $defaults['third_party'] = true;
            } elseif ($arg === '--all') {
                $defaults['all'] = true;
                $defaults['internal'] = true;
                $defaults['live'] = true;
                $defaults['third_party'] = true;
            } elseif ($arg === '--json') {
                $defaults['json'] = true;
            } elseif ($arg === '--help' || $arg === '-h') {
                $defaults['help'] = true;
            } elseif (str_starts_with($arg, '--base-url=')) {
                $defaults['base_url'] = trim(substr($arg, strlen('--base-url=')));
            } elseif (str_starts_with($arg, '--login-url=')) {
                $defaults['login_url'] = trim(substr($arg, strlen('--login-url=')));
            } elseif (str_starts_with($arg, '--attempts=')) {
                $defaults['attempts'] = max(1, (int) substr($arg, strlen('--attempts=')));
            } elseif (str_starts_with($arg, '--username=')) {
                $defaults['username'] = (string) substr($arg, strlen('--username='));
            } elseif (str_starts_with($arg, '--password=')) {
                $defaults['password'] = (string) substr($arg, strlen('--password='));
            }
        }

        return $defaults;
    }

    private function runInternalTests(): void {
        $tests = $this->discoverInternalTests();

        foreach ($tests as $test) {
            $name = (string) ($test['name'] ?? '');
            $relativePath = (string) ($test['path'] ?? '');
            $path = $this->root . '/' . $relativePath;
            if ($name === '' || $relativePath === '') {
                continue;
            }

            if (!is_file($path)) {
                $this->results[] = [
                    'category' => 'internal',
                    'name' => $name,
                    'target' => $relativePath,
                    'status' => 'skipped',
                    'details' => 'Test file not present in this worktree.',
                ];
                continue;
            }

            $startedAt = microtime(true);
            $process = $this->runCommand(['php', $path], $this->root);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $status = $process['exit_code'] === 0 ? 'pass' : $this->classifyInternalFailure($process['output']);
            $this->results[] = [
                'category' => 'internal',
                'name' => $name,
                'target' => $relativePath,
                'status' => $status,
                'exit_code' => $process['exit_code'],
                'duration_ms' => $durationMs,
                'details' => trim($process['output']),
            ];
        }
    }

    /** @return array<int, array{name:string, path:string}> */
    private function discoverInternalTests(): array {
        $candidates = [
            [ 'name' => 'system_audit', 'path' => 'tests/system_audit_test.php' ],
            [ 'name' => 'directory_access', 'path' => 'tests/security/directory_access_test.php' ],
            [ 'name' => 'router_request_security', 'path' => 'tests/security/router_request_security_test.php' ],
            [ 'name' => 'integrity_signature', 'path' => 'tests/security/integrity_signature_test.php' ],
            [ 'name' => 'security_boundary', 'path' => 'tests/security/security_boundary_test.php' ],
            [ 'name' => 'security_enclave', 'path' => 'tests/security/security_enclave_test.php' ],
            [ 'name' => 'security_auth', 'path' => 'tests/security/security_auth_test.php' ],
        ];

        $tests = [];
        foreach ($candidates as $candidate) {
            $tests[] = $candidate;
        }

        return $tests;
    }

    private function runLiveChecks(): void {
        $baseUrl = $this->normalizedUrl((string) $this->options['base_url']);
        if ($baseUrl === '') {
            $this->results[] = [
                'category' => 'live',
                'name' => 'live_prerequisites',
                'target' => '',
                'status' => 'skipped',
                'details' => 'Missing --base-url. Live checks were not run.',
            ];
            return;
        }

        $this->checkSecurityHeaders($baseUrl);

        $loginUrl = $this->normalizedUrl((string) $this->options['login_url']);
        if ($loginUrl !== '') {
            $this->checkLoginRateLimiting($loginUrl);
        } else {
            $this->results[] = [
                'category' => 'live',
                'name' => 'login_probe',
                'target' => '',
                'status' => 'skipped',
                'details' => 'Missing --login-url. Auth brute-force and rate-limit probe was not run.',
            ];
        }
    }

    private function runThirdPartyChecks(): void {
        $baseUrl = $this->normalizedUrl((string) $this->options['base_url']);
        $toolChecks = [];

        $zapLauncher = $this->findZapLauncher();
        if ($zapLauncher !== null) {
            $reportPath = sys_get_temp_dir() . '/metis-zap-baseline-' . date('Ymd-His') . '.html';
            $toolChecks[] = [
                'name' => 'owasp_zap',
                'command' => [$zapLauncher, '-cmd', '-quickurl', $baseUrl, '-quickprogress', '-quickout', $reportPath],
                'binary' => $zapLauncher,
                'requires_url' => true,
                'timeout' => 600,
                'report_path' => $reportPath,
            ];
        } else {
            $toolChecks[] = [
                'name' => 'owasp_zap',
                'command' => ['zap-baseline.py', '-t', $baseUrl, '-m', '2'],
                'binary' => 'zap-baseline.py',
                'requires_url' => true,
                'timeout' => 180,
            ];
        }

        $toolChecks = array_merge($toolChecks, [
            [
                'name' => 'nuclei',
                'command' => ['nuclei', '-target', $baseUrl, '-severity', 'low,medium,high,critical'],
                'binary' => 'nuclei',
                'requires_url' => true,
                'timeout' => 180,
            ],
            [
                'name' => 'nikto',
                'command' => ['nikto', '-h', $baseUrl],
                'binary' => 'nikto',
                'requires_url' => true,
                'timeout' => 180,
            ],
        ]);

        foreach ($toolChecks as $check) {
            $binary = (string) $check['binary'];
            $binaryPath = str_contains($binary, '/') ? $binary : $this->findBinary($binary);

            if ($binaryPath === null) {
                $this->results[] = [
                    'category' => 'third_party',
                    'name' => $check['name'],
                    'target' => $binary,
                    'status' => 'skipped',
                    'details' => 'Tool not installed.',
                ];
                continue;
            }

            if (!empty($check['requires_url']) && $baseUrl === '') {
                $this->results[] = [
                    'category' => 'third_party',
                    'name' => $check['name'],
                    'target' => $binaryPath,
                    'status' => 'skipped',
                    'details' => 'Missing --base-url.',
                ];
                continue;
            }

            $reportPath = (string) ($check['report_path'] ?? '');
            if ($reportPath !== '' && is_file($reportPath)) {
                @unlink($reportPath);
            }

            $process = $this->runCommand((array) $check['command'], $this->root, (int) ($check['timeout'] ?? 180));
            $status = $process['exit_code'] === 0 ? 'pass' : 'warning';
            $details = trim($this->truncate($process['output'], 4000));
            if ($reportPath !== '') {
                $details = trim($details . PHP_EOL . (is_file($reportPath)
                    ? 'Report: ' . $reportPath
                    : 'Report file was not created.'));
            }
            $this->results[] = [
                'category' => 'third_party',
                'name' => $check['name'],
                'target' => $binaryPath,
                'status' => $status,
                'exit_code' => $process['exit_code'],
                'details' => $details,
            ];
        }

        $sqlmap = $this->findBinary('sqlmap');
        $this->results[] = [
            'category' => 'third_party',
            'name' => 'sqlmap',
            'target' => $sqlmap ?? 'sqlmap',
            'status' => $sqlmap === null ? 'skipped' : 'manual',
            'details' => $sqlmap === null
                ? 'Tool not installed.'
                : 'Use sqlmap only against a specific, authorized request endpoint with a captured request file.',
        ];
    }

    private function checkSecurityHeaders(string $baseUrl): void {
        $response = $this->httpRequest('GET', $baseUrl);
        if (!$response['ok']) {
            $this->results[] = [
                'category' => 'live',
                'name' => 'security_headers',
                'target' => $baseUrl,
                'status' => 'error',
                'details' => (string) $response['error'],
            ];
            return;
        }

        $headers = $this->normalizeHeaders((array) $response['headers']);
        $expected = [
            'content-security-policy',
            'x-frame-options',
            'x-content-type-options',
            'referrer-policy',
        ];
        $missing = [];
        foreach ($expected as $header) {
            if (!array_key_exists($header, $headers)) {
                $missing[] = $header;
            }
        }

        $this->results[] = [
            'category' => 'live',
            'name' => 'security_headers',
            'target' => $baseUrl,
            'status' => $missing === [] ? 'pass' : 'warning',
            'http_status' => $response['status_code'],
            'details' => $missing === []
                ? 'Expected baseline security headers were present.'
                : 'Missing headers: ' . implode(', ', $missing),
        ];
    }

    private function checkLoginRateLimiting(string $loginUrl): void {
        $attempts = (int) $this->options['attempts'];
        $username = (string) $this->options['username'];
        $password = (string) $this->options['password'];
        $statusCodes = [];
        $durations = [];
        $messages = [];

        for ($i = 0; $i < $attempts; $i++) {
            $startedAt = microtime(true);
            $response = $this->httpRequest('POST', $loginUrl, [
                'headers' => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                'body' => http_build_query([
                    'log' => $username,
                    'pwd' => $password,
                    'username' => $username,
                    'password' => $password,
                ]),
            ]);
            $durations[] = (int) round((microtime(true) - $startedAt) * 1000);

            if (!$response['ok']) {
                $this->results[] = [
                    'category' => 'live',
                    'name' => 'login_rate_limit',
                    'target' => $loginUrl,
                    'status' => 'error',
                    'details' => (string) $response['error'],
                ];
                return;
            }

            $statusCodes[] = (int) $response['status_code'];
            $body = strtolower((string) $response['body']);
            if (str_contains($body, 'too many sign-in attempts')) {
                $messages[] = 'retry_message';
            }
            if (str_contains($body, 'unable to sign in')) {
                $messages[] = 'generic_failure';
            }
        }

        $saw429 = in_array(429, $statusCodes, true);
        $medianDuration = $this->median($durations);
        $lastDuration = (int) end($durations);
        $firstDuration = (int) reset($durations);
        $slowedDown = $lastDuration > ($firstDuration + 500) || $medianDuration > 700;
        $status = ($saw429 || $slowedDown) ? 'pass' : 'warning';

        $this->results[] = [
            'category' => 'live',
            'name' => 'login_rate_limit',
            'target' => $loginUrl,
            'status' => $status,
            'details' => sprintf(
                'attempts=%d status_codes=%s median_ms=%d first_ms=%d last_ms=%d messages=%s',
                $attempts,
                implode(',', $statusCodes),
                $medianDuration,
                $firstDuration,
                $lastDuration,
                implode(',', array_values(array_unique($messages)))
            ),
        ];
    }

    /** @return array{ok: bool, status_code: int, headers: array<int, string>, body: string, error: string} */
    private function httpRequest(string $method, string $url, array $options = []): array {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'status_code' => 0,
                'headers' => [],
                'body' => '',
                'error' => 'cURL extension is not available in this PHP CLI runtime.',
            ];
        }

        $headers = [];
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'status_code' => 0,
                'headers' => [],
                'body' => '',
                'error' => 'Failed to initialize cURL.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$headers): int {
                $headers[] = trim($headerLine);
                return strlen($headerLine);
            },
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        if (!empty($options['headers']) && is_array($options['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
        }

        if (array_key_exists('body', $options)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $options['body']);
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'ok' => $body !== false,
            'status_code' => $status,
            'headers' => $headers,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    /** @return array<string, string> */
    private function normalizeHeaders(array $lines): array {
        $headers = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        return $headers;
    }

    /** @return array{exit_code: int, output: string} */
    private function runCommand(array $command, string $cwd, int $timeoutSeconds = 60): array {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            return [
                'exit_code' => 1,
                'output' => 'Failed to start process: ' . implode(' ', $command),
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = time();

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            if (!$status['running']) {
                break;
            }

            if ((time() - $startedAt) >= $timeoutSeconds) {
                proc_terminate($process, 9);
                break;
            }

            usleep(100000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ((time() - $startedAt) >= $timeoutSeconds && $exitCode === -1) {
            $stderr .= PHP_EOL . 'Process timed out after ' . $timeoutSeconds . ' seconds.';
            $exitCode = 124;
        }

        return [
            'exit_code' => $exitCode,
            'output' => trim($stdout . PHP_EOL . $stderr),
        ];
    }

    private function classifyInternalFailure(string $output): string {
        $normalized = strtolower($output);
        if (str_contains($normalized, 'call to undefined function') || str_contains($normalized, 'class "') || str_contains($normalized, 'class \'')) {
            return 'harness_error';
        }

        if (str_contains($normalized, 'fatal error') || str_contains($normalized, 'parse error')) {
            return 'error';
        }

        return 'fail';
    }

    private function hasFailures(): bool {
        foreach ($this->results as $result) {
            if (in_array($result['status'], ['fail', 'error', 'harness_error', 'warning'], true)) {
                return true;
            }
        }

        return false;
    }

    private function printSummary(): void {
        if (!empty($this->options['json'])) {
            fwrite(STDOUT, json_encode([
                'ok' => !$this->hasFailures(),
                'generated_at' => date(DATE_ATOM),
                'results' => $this->results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            return;
        }

        fwrite(STDOUT, "Metis security audit\n");
        fwrite(STDOUT, str_repeat('=', 20) . "\n");

        foreach ($this->results as $result) {
            $line = sprintf(
                '[%s] %s (%s)',
                strtoupper((string) $result['status']),
                (string) $result['name'],
                (string) ($result['target'] ?: $result['category'])
            );
            fwrite(STDOUT, $line . PHP_EOL);
            $details = trim((string) ($result['details'] ?? ''));
            if ($details !== '') {
                fwrite(STDOUT, '  ' . str_replace(PHP_EOL, PHP_EOL . '  ', $this->truncate($details, 1200)) . PHP_EOL);
            }
        }

        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, $this->hasFailures() ? "Overall result: attention required\n" : "Overall result: pass\n");
    }

    private function normalizedUrl(string $url): string {
        return rtrim(trim($url), '/');
    }

    private function truncate(string $value, int $limit): string {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit - 3) . '...';
    }

    private function median(array $values): int {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (int) $values[$middle];
        }

        return (int) round((((int) $values[$middle - 1]) + ((int) $values[$middle])) / 2);
    }

    private function findBinary(string $binary): ?string {
        $process = $this->runCommand(['sh', '-lc', 'command -v ' . escapeshellarg($binary)], $this->root, 10);
        if ($process['exit_code'] !== 0) {
            return null;
        }

        $path = trim($process['output']);
        return $path === '' ? null : $path;
    }

    private function findZapLauncher(): ?string {
        $candidates = [
            '/Applications/ZAP.app/Contents/MacOS/ZAP.sh',
            '/Applications/ZAP.app/Contents/Java/zap.sh',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

$cli = new MetisSecurityAuditCli($root, $argv);
exit($cli->run());
