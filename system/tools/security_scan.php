<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Metis/Core/Runtime/CliToolGuard.php';
metis_require_cli_tool();

$root = dirname( __DIR__, 2 );
$system = $root . '/system';
$failures = [];

$approved = [
    'superglobals' => [
        'system/assets/error-pages/',
        'system/core/Profiler.php',
        'system/enclave/',
        'system/src/Metis/Core/',
        'system/src/Metis/Auth/',
        'system/src/Metis/Hermes/HermesGateway.php',
        'system/src/Metis/Modules/',
        'system/modules/',
        'system/tests/',
        'system/tools/security_scan.php',
        'system/webhooks.php',
        'index.php',
    ],
    'raw_sql' => [
        'system/src/Metis/Core/',
        'system/src/Metis/Hermes/',
        'system/src/Metis/Services/DatabaseService.php',
        'system/src/Metis/Modules/',
        'system/modules/',
        'system/tools/',
        'system/tests/',
    ],
    'process' => [
        'system/src/Metis/Core/IntegrityRuntime.php',
        'system/src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php',
        'system/src/Metis/Core/Recovery/',
        'system/src/Metis/Release/',
        'system/src/Metis/Modules/Finance/FinanceV2Service.php',
        'system/modules/settings/views/_settings_bootstrap.php',
        'system/tools/',
    ],
];

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

$php_files = [];
foreach ( $iter as $file ) {
    if ( ! $file instanceof SplFileInfo || ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) {
        continue;
    }
    $path = str_replace( '\\', '/', $file->getPathname() );
    if ( str_contains( $path, '/system/vendor/' ) ) {
        continue;
    }
    $php_files[] = $path;
}
sort( $php_files );

$rel = static fn ( string $path ): string => ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
$is_approved = static function ( string $relative, string $bucket ) use ( $approved ): bool {
    foreach ( $approved[ $bucket ] ?? [] as $prefix ) {
        if ( str_starts_with( $relative, $prefix ) ) {
            return true;
        }
    }
    return false;
};
$line_no = static function ( string $contents, int $offset ): int {
    return substr_count( substr( $contents, 0, $offset ), "\n" ) + 1;
};
$record = static function ( string $check, string $message ) use ( &$failures ): void {
    $failures[] = '[' . $check . '] ' . $message;
};

$syntax_failures = 0;
foreach ( $php_files as $path ) {
    $cmd = 'php -l ' . escapeshellarg( $path ) . ' 2>&1';
    exec( $cmd, $out, $code );
    if ( $code !== 0 ) {
        $syntax_failures++;
        $record( 'php-lint', $rel( $path ) . ': ' . trim( implode( ' ', $out ) ) );
    }
}
echo $syntax_failures === 0 ? "PASS php-lint\n" : "FAIL php-lint ({$syntax_failures})\n";

$fallback_hits = [];
foreach ( $php_files as $path ) {
    $relative = $rel( $path );
    $contents = (string) file_get_contents( $path );
    if ( preg_match_all( "/metis_runtime_config_get\\(\\s*['\"]app_key['\"]\\s*,\\s*['\"](?:metis-local-key|changeme|change-me|default)['\"]\\s*\\)/", $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $fallback_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] );
        }
    }
}
if ( $fallback_hits === [] ) {
    echo "PASS app-key-no-static-fallback\n";
} else {
    echo "FAIL app-key-no-static-fallback\n";
    foreach ( $fallback_hits as $hit ) {
        $record( 'app-key-no-static-fallback', $hit );
    }
}

$media_source = (string) file_get_contents( $system . '/src/Metis/Core/Kernel/Bootstrap.php' );
foreach ( [
    'raw uploads only' => '$roots = $raw ? [ METIS_PATH . \'storage/uploads\' ]',
    'deny storage direct' => 'metis_kernel_media_is_public_raw',
    'no symlink media' => '! is_link( $target_path )',
] as $label => $needle ) {
    if ( str_contains( $media_source, $needle ) ) {
        echo 'PASS media-' . $label . "\n";
    } else {
        echo 'FAIL media-' . $label . "\n";
        $record( 'media-policy', 'Missing ' . $label . ' guard.' );
    }
}

$htaccess = is_file( $root . '/.htaccess' ) ? (string) file_get_contents( $root . '/.htaccess' ) : '';
if ( str_contains( $htaccess, 'RewriteRule ^storage(?:/|$) - [F,L,NC]' ) ) {
    echo "PASS apache-storage-deny\n";
} else {
    echo "FAIL apache-storage-deny\n";
    $record( 'apache-storage-deny', '.htaccess must block direct storage access.' );
}

$tool_failures = [];
foreach ( glob( $system . '/tools/*.php' ) ?: [] as $tool ) {
    $relative = $rel( $tool );
    if ( basename( $tool ) === 'index.php' ) {
        continue;
    }
    $contents = (string) file_get_contents( $tool );
    if ( ! str_contains( $contents, 'metis_require_cli_tool()' ) && ! preg_match( "/PHP_SAPI\\s*!==\\s*['\"]cli['\"]|PHP_SAPI\\s*===\\s*['\"]cli['\"]/", $contents ) ) {
        $tool_failures[] = $relative;
    }
}
if ( $tool_failures === [] ) {
    echo "PASS cli-tool-guards\n";
} else {
    echo "FAIL cli-tool-guards\n";
    foreach ( $tool_failures as $tool ) {
        $record( 'cli-tool-guards', $tool );
    }
}

$superglobal_hits = [];
$raw_sql_hits = [];
$process_hits = [];
foreach ( $php_files as $path ) {
    $relative = $rel( $path );
    $contents = (string) file_get_contents( $path );

    if ( ! $is_approved( $relative, 'superglobals' ) && preg_match_all( '/\\$_(?:GET|POST|REQUEST|FILES|COOKIE)|php:\\/\\/input/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $superglobal_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' ' . $match[0];
        }
    }

    if ( ! $is_approved( $relative, 'raw_sql' ) && preg_match_all( '/(?:mysqli_query|PDO::query|->query\\s*\\(|->exec\\s*\\()/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $raw_sql_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' ' . $match[0];
        }
    }

    if ( ! $is_approved( $relative, 'process' ) && preg_match_all( '/(?:shell_exec|proc_open|passthru|popen|\\bexec\\s*\\(|\\bsystem\\s*\\()/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $process_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' ' . $match[0];
        }
    }
}

foreach ( [
    'raw-superglobals' => $superglobal_hits,
    'raw-sql' => $raw_sql_hits,
    'process-execution' => $process_hits,
] as $check => $hits ) {
    if ( $hits === [] ) {
        echo 'PASS ' . $check . "\n";
        continue;
    }
    echo 'FAIL ' . $check . "\n";
    foreach ( $hits as $hit ) {
        $record( $check, $hit );
    }
}

if ( $failures !== [] ) {
    echo "\nSecurity scan failures:\n";
    foreach ( $failures as $failure ) {
        echo ' - ' . $failure . "\n";
    }
    exit( 1 );
}

echo "\nSecurity scan passed.\n";
