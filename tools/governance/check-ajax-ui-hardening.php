<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$exclude = [
    '.git',
    'vendor',
    'node_modules',
    'storage',
    'uploads',
    'logs',
    'cache',
];

$issues = [];
$phpFiles = [];
$ajaxFiles = [];
$rawSqlPattern = '/\bSELECT\s+.+\bFROM\b|\bINSERT\s+INTO\b|\bUPDATE\s+\S+\s+SET\b|\bDELETE\s+FROM\b/us';

$fileList = static function () use ($root, $exclude): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current, string $key, mixed $iterator) use ($exclude): bool {
                if ($current->isDir()) {
                    return !in_array($current->getFilename(), $exclude, true);
                }
                return true;
            }
        )
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $files[] = ltrim(str_replace($root, '', $file->getPathname()), DIRECTORY_SEPARATOR);
        }
    }

    sort($files);
    return $files;
};

$allFiles = $fileList();

foreach ($allFiles as $relative) {
    if (str_ends_with($relative, '.php')) {
        $phpFiles[] = $relative;
    }

    if (preg_match('/ajax[^\/]*\.php$/i', $relative) === 1) {
        $ajaxFiles[] = $relative;
    }
}

sort($phpFiles);
sort($ajaxFiles);

$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    $contents = @file_get_contents($path);
    return is_string($contents) ? $contents : '';
};

$addIssue = static function (string $section, string $message) use (&$issues): void {
    $issues[$section] ??= [];
    $issues[$section][] = $message;
};

foreach ($phpFiles as $relative) {
    if ($relative === 'tools/governance/check-ajax-ui-hardening.php') {
        continue;
    }

    $contents = $read($relative);
    if ($contents === '') {
        continue;
    }

    if (preg_match('/\$_GET|\$_POST|\$_REQUEST|\$_FILES|\$_COOKIE/u', $contents) === 1) {
        $addIssue('Raw Superglobals', $relative);
    }
}

foreach ($ajaxFiles as $relative) {
    if ($relative === 'system/ajax.php') {
        continue;
    }

    $contents = $read($relative);
    if ($contents === '') {
        continue;
    }

    $hasHandler = strpos($contents, 'metis_ajax_register_handler(') !== false;
    if (!$hasHandler) {
        continue;
    }

    $hasNonce = preg_match('/verify_nonce|metis_action_nonce|metis_ajax_nonce_action|[\'"]nonce_action[\'"]\s*=>/u', $contents) === 1;
    $hasPermission = preg_match('/require_view|require_manage|require_delete|require_publish|require_export|current_user_can|user_logged_in|Unauthorized|metis_security_user_can|metis_people_can(?:_[a-z_]+)?\(|[\'"]permission[\'"]\s*=>/u', $contents) === 1;
    $hasController = strpos($contents, 'metis_ajax_register_controller(') !== false;

    if (!$hasController) {
        $addIssue('Direct AJAX Execution', $relative . ' registers handlers without controller metadata.');
    }
    if (!$hasNonce) {
        $addIssue('Missing Nonce Validation', $relative);
    }
    if (!$hasPermission) {
        $addIssue('Missing Permission Validation', $relative);
    }

    if (preg_match($rawSqlPattern, $contents) === 1) {
        $section = str_starts_with($relative, 'system/modules/')
            ? 'Raw SQL In Frontend Handlers'
            : 'Raw SQL In Core Runtime';
        $addIssue($section, $relative);
    }
}

foreach ($phpFiles as $relative) {
    if (!str_contains($relative, 'views/') && !str_contains($relative, 'assets/')) {
        continue;
    }
    $contents = $read($relative);
    if ($contents !== '' && preg_match($rawSqlPattern, $contents) === 1) {
        $addIssue('Raw SQL In Frontend Handlers', $relative);
    }

    if (
        $relative !== 'system/tests/ajax_ui_hardening_contract_test.php'
        && preg_match('/window\.metis_(?:toast|confirm)\s*\(/u', $contents) === 1
    ) {
        $addIssue('Legacy UI Alias Usage', $relative);
    }
}

$jsFiles = array_values(array_filter(
    $allFiles,
    static fn (string $relative): bool => str_ends_with($relative, '.js')
));

foreach ($jsFiles as $relative) {
    $contents = $read($relative);
    if ($contents === '') {
        continue;
    }

    if (preg_match('/(^|[^A-Za-z0-9_])alert\s*\(|(^|[^A-Za-z0-9_])confirm\s*\(/u', $contents) === 1) {
        $addIssue('Browser Native Dialogs', $relative);
    }

    if ($relative !== 'system/assets/core.js') {
        if (preg_match('/Metis\.toast\s*=(?!=)|window\.metis_toast\s*=(?!=)/u', $contents) === 1) {
            $addIssue('Duplicate Toast Systems', $relative);
        }
        if (preg_match('/Metis\.modal\s*=(?!=)|Metis\.confirm\s*=(?!=)|window\.metis_confirm\s*=(?!=)/u', $contents) === 1) {
            $addIssue('Duplicate Modal Systems', $relative);
        }
        if (
            !str_starts_with($relative, 'system/tests/')
            && preg_match('/window\.metis_(?:toast|confirm)\s*\(/u', $contents) === 1
        ) {
            $addIssue('Legacy UI Alias Usage', $relative);
        }
    }
}

foreach ($phpFiles as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    $output = [];
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        $addIssue('PHP Syntax', $relative . ' :: ' . implode(' ', $output));
    }
}

ksort($issues);

echo "Metis AJAX/UI Hardening Governance Check\n";
echo "Repository: {$root}\n\n";

if ($issues === []) {
    echo "No governance issues detected.\n";
    exit(0);
}

foreach ($issues as $section => $messages) {
    echo "[{$section}]\n";
    foreach (array_values(array_unique($messages)) as $message) {
        echo " - {$message}\n";
    }
    echo "\n";
}

exit(1);
