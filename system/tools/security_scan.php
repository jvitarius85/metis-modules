<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Metis/Core/Runtime/CliToolGuard.php';
metis_require_cli_tool();

$root = dirname( __DIR__, 2 );
$system = $root . '/system';
$failures = [];

$governance = require $system . '/config/governance.php';
$approved = is_array( $governance['approved_layers'] ?? null ) ? $governance['approved_layers'] : [];

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
    'raw public-media only' => "'public' => [ 'public' => \$all_roots['public'] ]",
    'deny storage direct' => 'metis_kernel_media_is_public_raw',
    'no symlink media' => '! is_link( $target_path )',
    'protected token checks' => "in_array( \$storage_class, [ 'protected', 'private' ], true )",
    'protected no-store cache' => "private, no-store, max-age=0",
] as $label => $needle ) {
    if ( str_contains( $media_source, $needle ) ) {
        echo 'PASS media-' . $label . "\n";
    } else {
        echo 'FAIL media-' . $label . "\n";
        $record( 'media-policy', 'Missing ' . $label . ' guard.' );
    }
}

$uploads_runtime = (string) file_get_contents( $system . '/src/Metis/Core/Runtime/UploadsRuntime.php' );
foreach ( [
    'canonical public helper' => 'function metis_store_public_media',
    'canonical protected helper' => 'function metis_store_protected_media',
    'canonical private helper' => 'function metis_store_private_record',
    'central storage class normalize' => 'function metis_media_normalize_storage_class',
    'registered path resolver' => 'function metis_media_resolve_registered_path',
    'protected explicit ttl' => 'Protected media requires an explicit access expiration.',
] as $label => $needle ) {
    if ( str_contains( $uploads_runtime, $needle ) ) {
        echo 'PASS media-' . $label . "\n";
    } else {
        echo 'FAIL media-' . $label . "\n";
        $record( 'media-policy', 'Missing ' . $label . '.' );
    }
}

foreach ( (array) ( $governance['required_media_roots'] ?? [] ) as $required_root ) {
    $required_root = trim( (string) $required_root, '/\\' );
    if ( $required_root !== '' && is_dir( $root . '/' . $required_root ) ) {
        echo 'PASS media-root-' . str_replace( '/', '-', $required_root ) . "\n";
        continue;
    }

    echo 'FAIL media-root-' . str_replace( '/', '-', $required_root ) . "\n";
    $record( 'media-roots', 'Missing required media root ' . $required_root . '.' );
}

$sensitive_media_failures = [];
foreach ( (array) ( $governance['sensitive_media_writes'] ?? [] ) as $rule ) {
    if ( ! is_array( $rule ) ) {
        continue;
    }
    $relative = trim( (string) ( $rule['path'] ?? '' ) );
    $required_class = trim( (string) ( $rule['required_storage_class'] ?? '' ) );
    if ( $relative === '' || $required_class === '' || ! is_file( $root . '/' . $relative ) ) {
        continue;
    }

    $contents = (string) file_get_contents( $root . '/' . $relative );
    $canonical_helpers = (array) ( $governance['canonical_media_helpers'] ?? [] );
    $expected_helper = (string) ( $canonical_helpers[ $required_class ] ?? '' );
    $has_helper = $expected_helper !== '' && str_contains( $contents, $expected_helper . '(' );
    $has_explicit_class = preg_match( "/['\"]storage_class['\"]\\s*=>\\s*['\"]" . preg_quote( $required_class, '/' ) . "['\"]/", $contents ) === 1;
    if ( ! $has_helper && ! $has_explicit_class ) {
        $sensitive_media_failures[] = $relative . ' must write to ' . $required_class . ' media via canonical helper or explicit storage_class.';
    }
}
if ( $sensitive_media_failures === [] ) {
    echo "PASS sensitive-media-storage\n";
} else {
    echo "FAIL sensitive-media-storage\n";
    foreach ( $sensitive_media_failures as $failure ) {
        $record( 'sensitive-media-storage', $failure );
    }
}

$htaccess = is_file( $root . '/.htaccess' ) ? (string) file_get_contents( $root . '/.htaccess' ) : '';
if ( str_contains( $htaccess, 'RewriteRule ^storage(?:/|$) - [F,L,NC]' ) ) {
    echo "PASS apache-storage-deny\n";
} else {
    echo "FAIL apache-storage-deny\n";
    $record( 'apache-storage-deny', '.htaccess must block direct storage access.' );
}

$eval_hits = [];
$request_hits = [];
$request_superglobal_pattern = '/\\$' . '_REQUEST\\b/';
foreach ( $php_files as $path ) {
    $relative = $rel( $path );
    $contents = (string) file_get_contents( $path );
    if ( preg_match_all( '/\\beval\\s*\\(/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $eval_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] );
        }
    }
    if ( preg_match_all( $request_superglobal_pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $request_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] );
        }
    }
}
if ( $eval_hits === [] ) {
    echo "PASS no-eval\n";
} else {
    echo "FAIL no-eval\n";
    foreach ( $eval_hits as $hit ) {
        $record( 'no-eval', $hit );
    }
}

if ( $request_hits === [] ) {
    echo "PASS no-request-superglobal\n";
} else {
    echo "FAIL no-request-superglobal\n";
    foreach ( $request_hits as $hit ) {
        $record( 'no-request-superglobal', $hit );
    }
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
$request_boundary_hits = [];
$raw_sql_hits = [];
$native_db_hits = [];
$process_hits = [];
$serialization_hits = [];
foreach ( $php_files as $path ) {
    $relative = $rel( $path );
    $contents = (string) file_get_contents( $path );

    if ( ! $is_approved( $relative, 'superglobals' ) && preg_match_all( '/\\$_(?:GET|POST|REQUEST|FILES|COOKIE)\\b/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $superglobal_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' ' . $match[0];
        }
    }

    if ( ! $is_approved( $relative, 'request_boundary' ) && preg_match_all( '/(?:\\$GLOBALS\\s*\\[\\s*[\'"]_(?:GET|POST|REQUEST|FILES|COOKIE)[\'"]\\s*\\]|php:\\/\\/input)/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $request_boundary_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' ' . $match[0];
        }
    }

    if ( ! $is_approved( $relative, 'raw_sql' ) && preg_match_all( '/(?:mysqli_query|PDO::query|\$pdo->query\\s*\\(|\$pdo->exec\\s*\\(|\$mysqli->query\\s*\\(|\$db->query\\s*\\(|\$database->query\\s*\\(|\$db_connection->query\\s*\\(|->exec\\s*\\()/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $raw_sql_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' ' . $match[0];
        }
    }

    if ( ! $is_approved( $relative, 'native_db' ) && preg_match_all( '/(?:new\\s+PDO\\b|mysqli_(?:init|report|options|real_connect|connect_errno|connect_error)|->connection\\s*\\(|\\$GLOBALS\\s*\\[\\s*[\'"]metis_db_connection[\'"]\\s*\\]|\\$pdo->|\\$mysqli->|\\$db_connection->query\\s*\\()/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $native_db_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' ' . trim( $match[0] );
        }
    }

    if ( ! $is_approved( $relative, 'process' ) && preg_match_all( '/(?:shell_exec\\s*\\(|proc_open\\s*\\(|passthru\\s*\\(|popen\\s*\\(|\\bexec\\s*\\(|\\bsystem\\s*\\()/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $process_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' ' . $match[0];
        }
    }

    if ( ! $is_approved( $relative, 'serialization' ) && preg_match_all( '/\\b(?:unserialize|maybe_unserialize)\\s*\\(/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches[0] as $match ) {
            $serialization_hits[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' ' . $match[0];
        }
    }
}

foreach ( [
    'raw-superglobals' => $superglobal_hits,
    'request-boundary' => $request_boundary_hits,
    'raw-sql' => $raw_sql_hits,
    'native-db-access' => $native_db_hits,
    'process-execution' => $process_hits,
    'serialization-boundary' => $serialization_hits,
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

$process_runner_source = (string) file_get_contents( $system . '/src/Metis/Core/Services/ProcessRunner.php' );
$process_context_failures = [];
foreach ( (array) ( $governance['required_process_context_keys'] ?? [] ) as $key ) {
    $key = (string) $key;
    if ( $key !== '' && ! str_contains( $process_runner_source, "'" . $key . "'" ) && ! str_contains( $process_runner_source, '"' . $key . '"' ) ) {
        $process_context_failures[] = 'ProcessRunner does not require ' . $key . '.';
    }
}
if ( ! str_contains( $process_runner_source, 'validateExecutionContext' ) || ! str_contains( $process_runner_source, 'contextAllowsExecution' ) ) {
    $process_context_failures[] = 'ProcessRunner must validate context and authority before proc_open.';
}
foreach ( $php_files as $path ) {
    $relative = $rel( $path );
    if ( $relative === 'system/src/Metis/Core/Services/ProcessRunner.php' ) {
        continue;
    }
    $contents = (string) file_get_contents( $path );
    if ( preg_match_all( '/ProcessRunner\\s*\\(\\s*\\)\\s*->\\s*run\\s*\\((?P<args>.*?)\\);/s', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        foreach ( $matches['args'] as $match ) {
            $args = (string) $match[0];
            foreach ( (array) ( $governance['required_process_context_keys'] ?? [] ) as $key ) {
                if ( ! str_contains( $args, (string) $key ) ) {
                    $process_context_failures[] = $relative . ':' . $line_no( $contents, (int) $match[1] ) . ' missing ' . $key . ' in ProcessRunner context.';
                }
            }
        }
    }
}
if ( $process_context_failures === [] ) {
    echo "PASS process-context\n";
} else {
    echo "FAIL process-context\n";
    foreach ( $process_context_failures as $failure ) {
        $record( 'process-context', $failure );
    }
}

$router_source = (string) file_get_contents( $system . '/src/Metis/Core/Routing/RouterRuntime.php' );
$route_governance_failures = [];
foreach ( [
    'metis_router_configure_middleware',
    'metis_router_require_request_security',
    'metis_router_require_route_security',
    'metis_security_register_route_policies',
    'metis_router_require_ajax_contract',
    'metis_router_require_ajax_security',
] as $needle ) {
    if ( ! str_contains( $router_source, $needle ) ) {
        $route_governance_failures[] = 'Router runtime missing ' . $needle . '.';
    }
}
if ( $route_governance_failures === [] ) {
    echo "PASS route-middleware-governance\n";
} else {
    echo "FAIL route-middleware-governance\n";
    foreach ( $route_governance_failures as $failure ) {
        $record( 'route-middleware-governance', $failure );
    }
}

$ajax_contract_failures = [];
foreach ( glob( $system . '/modules/*/{ajax,assets}/*.ajax.php', GLOB_BRACE ) ?: [] as $ajax_file ) {
    $relative = $rel( $ajax_file );
    $contents = (string) file_get_contents( $ajax_file );
    if ( str_contains( $contents, 'metis_ajax_register_handler' ) && ! str_contains( $contents, 'metis_ajax_register_controller' ) ) {
        $ajax_contract_failures[] = $relative . ' registers handlers without controller security metadata.';
    }
}
if ( $ajax_contract_failures === [] ) {
    echo "PASS ajax-security-contracts\n";
} else {
    echo "FAIL ajax-security-contracts\n";
    foreach ( $ajax_contract_failures as $failure ) {
        $record( 'ajax-security-contracts', $failure );
    }
}

$hermes_registry_source = (string) file_get_contents( $system . '/src/Metis/Hermes/HermesToolRegistry.php' );
$hermes_executor_source = (string) file_get_contents( $system . '/src/Metis/Hermes/HermesToolExecutor.php' );
$hermes_failures = [];
if ( ! str_contains( $hermes_registry_source, 'required_permissions' ) || ! str_contains( $hermes_registry_source, 'requires_approval' ) || ! str_contains( $hermes_registry_source, 'risk_level' ) ) {
    $hermes_failures[] = 'Hermes registry must expose permission, risk, and approval metadata.';
}
if ( ! str_contains( $hermes_executor_source, 'HermesPermissionValidator' ) || ! str_contains( $hermes_executor_source, 'metis_core_enclave_execute_tool' ) ) {
    $hermes_failures[] = 'Hermes executor must validate permissions and enter the Secure Enclave.';
}
if ( preg_match_all( "/'risk_level'\\s*=>\\s*'(?:high|critical)'(?:(?!\\],).)*'requires_approval'\\s*=>\\s*false/s", $hermes_registry_source, $matches, PREG_OFFSET_CAPTURE ) ) {
    foreach ( $matches[0] as $match ) {
        $hermes_failures[] = 'High or critical Hermes tool without approval at line ' . $line_no( $hermes_registry_source, (int) $match[1] ) . '.';
    }
}
if ( $hermes_failures === [] ) {
    echo "PASS hermes-governance\n";
} else {
    echo "FAIL hermes-governance\n";
    foreach ( $hermes_failures as $failure ) {
        $record( 'hermes-governance', $failure );
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
