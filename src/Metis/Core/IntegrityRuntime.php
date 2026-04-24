<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

class Metis_Integrity_Manager {

    private const CRON_HOOK            = 'metis_integrity_scan';
    private const CRON_INTERVAL        = 'metis_integrity_hourly';
    private const STORAGE_DIR          = 'storage/runtime/integrity';
    private const MANIFEST_FILE        = 'manifest.json';
    private const MANIFEST_BACKUP_FILE = 'manifest.backup.json';
    private const SIGNATURE_FILE       = 'manifest.sig';
    private const SIGNATURE_BACKUP_FILE = 'manifest.backup.sig';
    private const RECOVERY_DIR         = 'recovery';
    private const QUARANTINE_DIR       = 'quarantine';
    private const INITIALIZED_OPTION   = 'integrity_initialized';
    private const ALERTS_OPTION        = 'integrity_alerts_enabled';
    private const AUTO_HEAL_OPTION     = 'integrity_auto_heal_enabled';
    private const QUARANTINE_OPTION    = 'integrity_quarantine_enabled';
    private const GIT_RESTORE_OPTION   = 'integrity_git_restore_enabled';

    private static bool $booted = false;
    private static bool $ensuring_runtime = false;
    private static bool $building_baseline = false;
    private static ?array $git_repository_state = null;

    public static function init(): void {

        if ( self::$booted ) {
            return;
        }

        metis_on( 'init', [ self::class, 'ensure_runtime' ], 5 );

        self::$booted = true;
    }

    public static function activate(): void {
        self::ensure_runtime();

        if ( ! self::is_initialized() ) {
            self::build_baseline( 'activation' );
            self::set_flag( self::INITIALIZED_OPTION, true );
        }
    }

    public static function deactivate(): void {
    }

    public static function register_schedule( array $schedules ): array {
        if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
            $schedules[ self::CRON_INTERVAL ] = [
                'interval' => HOUR_IN_SECONDS,
                'display'  => __( 'Metis Integrity Hourly', 'metis' ),
            ];
        }

        return $schedules;
    }

    public static function ensure_runtime(): void {
        if ( self::$ensuring_runtime ) {
            return;
        }

        self::$ensuring_runtime = true;

        self::ensure_storage_directories();

        try {
            if ( ! self::manifest_exists() && self::manifest_backup_exists() ) {
                self::restore_manifest_from_backup();
            }

            $manifest = self::load_manifest();
            if (
                ! self::$building_baseline
                && is_array( $manifest )
                && (string) ( $manifest['version'] ?? '' ) !== self::current_version()
            ) {
                try {
                    self::build_baseline( 'version_change' );
                } catch ( Throwable $e ) {
                    if ( class_exists( 'Metis_Logger', false ) ) {
                        Metis_Logger::warn( 'Integrity baseline refresh skipped during runtime boot', [
                            'reason' => 'version_change',
                            'error'  => $e->getMessage(),
                        ] );
                    }
                }
            }
        } finally {
            self::$ensuring_runtime = false;
        }
    }

    public static function scheduled_scan(): void {
        self::scan_and_heal( 'scheduled' );
    }

    public static function scan_and_heal( string $trigger = 'manual' ): array {
        self::ensure_runtime();

        $manifest = self::load_manifest();
        if ( $manifest === null ) {
            $status = self::signature_required() ? 'manifest_untrusted' : 'manifest_missing';
            self::record_security_event(
                self::signature_required() ? 'integrity_manifest_untrusted' : 'integrity_manifest_missing',
                'critical',
                'degraded',
                [
                    'trigger' => $trigger,
                    'storage' => self::storage_dir(),
                ]
            );

            self::send_alert(
                self::signature_required() ? 'Metis integrity manifest trust check failed' : 'Metis integrity manifest missing',
                [
                    self::signature_required()
                        ? 'Integrity scanning could not continue because the trusted manifest signature check failed.'
                        : 'Integrity scanning could not continue because the trusted manifest is unavailable.',
                    'Trigger: ' . $trigger,
                    'Storage: ' . self::storage_dir(),
                ]
            );

            return [
                'status' => $status,
                'issues' => [],
            ];
        }

        $current   = self::build_current_manifest();
        $expected  = (array) ( $manifest['files'] ?? [] );
        $issues    = self::detect_issues( $expected, $current );
        $restored  = [];
        $removed   = [];
        $quarantined = [];

        if ( empty( $issues ) ) {
            Metis_Logger::info( 'Integrity scan completed with no discrepancies', [ 'trigger' => $trigger ] );
            return [
                'status' => 'clean',
                'issues' => [],
            ];
        }

        foreach ( $issues as $issue ) {
            $context = [
                'trigger'  => $trigger,
                'path'     => $issue['path'],
                'type'     => $issue['type'],
                'expected' => $issue['expected_hash'] ?? '',
                'actual'   => $issue['actual_hash'] ?? '',
            ];

            Metis_Logger::warn( 'Integrity discrepancy detected', $context );
            self::record_security_event( 'integrity_discrepancy', 'critical', 'detected', $context, $issue['path'], $issue['type'] );

            if ( self::quarantine_enabled() && in_array( $issue['type'], [ 'modified', 'unexpected' ], true ) ) {
                $quarantine_path = self::quarantine_file( $issue['path'] );
                if ( $quarantine_path !== null ) {
                    $quarantined[] = [
                        'path'       => $issue['path'],
                        'quarantine' => $quarantine_path,
                    ];
                }
            }

            if ( ! self::auto_heal_enabled() ) {
                continue;
            }

            if ( in_array( $issue['type'], [ 'modified', 'missing' ], true ) ) {
                if ( self::restore_file(
                    $issue['path'],
                    (string) ( $issue['expected_hash'] ?? '' ),
                    (array) ( $manifest['git'] ?? [] )
                ) ) {
                    $restored[] = $issue['path'];
                    self::record_security_event( 'integrity_restored', 'critical', 'restored', $context, $issue['path'], $issue['type'] );
                }
                continue;
            }

            if ( $issue['type'] === 'unexpected' ) {
                if ( self::remove_unexpected_file( $issue['path'] ) ) {
                    $removed[] = $issue['path'];
                    self::record_security_event( 'integrity_removed', 'critical', 'removed', $context, $issue['path'], $issue['type'] );
                }
            }
        }

        self::send_alert(
            'Metis integrity discrepancy detected',
            self::build_alert_lines( $trigger, $issues, $restored, $removed, $quarantined )
        );

        return [
            'status'      => 'issues_detected',
            'issues'      => $issues,
            'restored'    => $restored,
            'removed'     => $removed,
            'quarantined' => $quarantined,
        ];
    }

    public static function sign_baseline( ?string $private_key_path = null ): bool {
        self::ensure_runtime();

        $artifact = self::load_manifest_artifact( self::manifest_path(), false );
        if ( $artifact === null ) {
            return false;
        }

        $private_key_path = $private_key_path ?: self::private_key_path();
        if ( $private_key_path === '' ) {
            Metis_Logger::error( 'Integrity signing failed: private key path missing' );
            return false;
        }

        $private_key = self::load_private_key( $private_key_path );
        if ( $private_key === null ) {
            Metis_Logger::error( 'Integrity signing failed: private key could not be loaded', [ 'path' => $private_key_path ] );
            return false;
        }

        $signature = '';
        $signed = openssl_sign( $artifact['raw'], $signature, $private_key, OPENSSL_ALGO_SHA256 );
        if ( ! $signed || $signature === '' ) {
            Metis_Logger::error( 'Integrity signing failed: openssl_sign returned false' );
            return false;
        }

        return self::write_signature_files( base64_encode( $signature ) );
    }

    public static function verify_baseline(): array {
        self::ensure_runtime();

        $manifest = self::load_manifest_artifact( self::manifest_path(), false );
        if ( $manifest === null ) {
            return [
                'ok' => false,
                'status' => 'manifest_missing',
                'signature_required' => self::signature_required(),
                'recovery_mismatches' => [],
            ];
        }

        $signature = self::verify_manifest_signature_for_artifact( $manifest );
        $expected_files = (array) ( $manifest['decoded']['files'] ?? [] );
        $recovery_mismatches = self::verify_recovery_snapshots( $expected_files );

        return [
            'ok' => $signature['ok'] && $recovery_mismatches === [],
            'status' => $signature['status'],
            'signature_required' => self::signature_required(),
            'signature_path' => self::signature_path(),
            'manifest_path' => self::manifest_path(),
            'recovery_mismatches' => $recovery_mismatches,
            'files' => count( $expected_files ),
        ];
    }

    public static function build_baseline( string $reason = 'manual' ): bool {
        if ( self::$building_baseline ) {
            return false;
        }

        self::$building_baseline = true;

        self::ensure_runtime();

        try {
            $files = self::build_current_manifest();
            if ( empty( $files ) ) {
                Metis_Logger::error( 'Integrity baseline generation failed: no files discovered', [ 'reason' => $reason ] );
                return false;
            }

            self::clear_recovery_directory();

            foreach ( $files as $relative => $entry ) {
                $source      = self::absolute_path( $relative );
                $destination = self::recovery_file_path( $relative );

                if ( ! is_string( $source ) || ! file_exists( $source ) ) {
                    continue;
                }

                if ( ! metis_runtime_make_dir( dirname( $destination ) ) ) {
                    Metis_Logger::warn( 'Integrity baseline refresh could not create recovery directory', [
                        'reason'      => $reason,
                        'path'        => dirname( $destination ),
                        'source_path' => $source,
                    ] );
                    return false;
                }

                if ( ! @copy( $source, $destination ) ) {
                    Metis_Logger::warn( 'Integrity baseline refresh could not copy recovery file', [
                        'reason'           => $reason,
                        'source_path'      => $source,
                        'destination_path' => $destination,
                    ] );
                    return false;
                }
            }

            $manifest = [
                'generated_at' => metis_current_time( 'mysql' ),
                'version'      => self::current_version(),
                'reason'       => $reason,
                'git'          => self::git_manifest_metadata(),
                'files'        => $files,
            ];

            $written = self::write_manifest( $manifest );

            if ( $written ) {
                Metis_Logger::info( 'Integrity baseline refreshed', [
                    'reason' => $reason,
                    'files'  => count( $files ),
                ] );
            }

            return $written;
        } finally {
            self::$building_baseline = false;
        }
    }

    public static function initialize_baseline( string $reason = 'manual' ): bool {
        $built = self::build_baseline( $reason );
        if ( $built ) {
            self::set_flag( self::INITIALIZED_OPTION, true );
        }

        return $built;
    }

    private static function detect_issues( array $expected, array $current ): array {
        $issues = [];

        foreach ( $expected as $relative => $entry ) {
            if ( ! isset( $current[ $relative ] ) ) {
                $issues[] = [
                    'path'          => $relative,
                    'type'          => 'missing',
                    'expected_hash' => (string) ( $entry['hash'] ?? '' ),
                ];
                continue;
            }

            if ( (string) ( $entry['hash'] ?? '' ) !== (string) ( $current[ $relative ]['hash'] ?? '' ) ) {
                $issues[] = [
                    'path'          => $relative,
                    'type'          => 'modified',
                    'expected_hash' => (string) ( $entry['hash'] ?? '' ),
                    'actual_hash'   => (string) ( $current[ $relative ]['hash'] ?? '' ),
                ];
            }
        }

        foreach ( $current as $relative => $entry ) {
            if ( isset( $expected[ $relative ] ) ) {
                continue;
            }

            $issues[] = [
                'path'        => $relative,
                'type'        => 'unexpected',
                'actual_hash' => (string) ( $entry['hash'] ?? '' ),
            ];
        }

        return $issues;
    }

    private static function build_current_manifest(): array {
        $manifest = [];

        foreach ( self::protected_roots() as $root ) {
            $absolute_root = self::absolute_path( $root );
            if ( ! is_string( $absolute_root ) || ! file_exists( $absolute_root ) ) {
                continue;
            }

            if ( is_file( $absolute_root ) ) {
                $relative = self::normalize_relative_path( $root );
                $entry = self::file_manifest_entry( $absolute_root );
                if ( $entry !== null ) {
                    $manifest[ $relative ] = $entry;
                }
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $absolute_root, FilesystemIterator::SKIP_DOTS )
            );

            foreach ( $iterator as $item ) {
                if ( ! $item instanceof SplFileInfo || ! $item->isFile() ) {
                    continue;
                }

                $absolute = $item->getPathname();
                $relative = self::relative_from_absolute( $absolute );

                if ( self::should_ignore( $relative ) ) {
                    continue;
                }

                $entry = self::file_manifest_entry( $absolute );
                if ( $entry === null ) {
                    continue;
                }

                $manifest[ $relative ] = $entry;
            }
        }

        ksort( $manifest );

        return $manifest;
    }

    private static function file_manifest_entry( string $absolute_path ): ?array {
        if ( ! is_file( $absolute_path ) || ! is_readable( $absolute_path ) ) {
            return null;
        }

        try {
            $hash = @hash_file( 'sha256', $absolute_path );
        } catch ( \Throwable $e ) {
            Metis_Logger::warn( 'Integrity manifest skipped unreadable file', [
                'path' => self::relative_from_absolute( $absolute_path ),
                'error' => $e->getMessage(),
            ] );
            return null;
        }

        if ( ! is_string( $hash ) || $hash === '' ) {
            Metis_Logger::warn( 'Integrity manifest skipped file with empty hash', [
                'path' => self::relative_from_absolute( $absolute_path ),
            ] );
            return null;
        }

        $size = 0;
        try {
            $size = (int) ( @filesize( $absolute_path ) ?: 0 );
        } catch ( \Throwable ) {
            $size = 0;
        }

        return [
            'hash' => $hash,
            'size' => $size,
        ];
    }

    private static function protected_roots(): array {
        $candidates = [
            'assets',
            'cloudflare',
            'columns',
            'composer.json',
            'composer.lock',
            'config',
            'index.php',
            'modules',
            'src',
            'system',
            'tools',
        ];

        $roots = [];

        foreach ( $candidates as $candidate ) {
            $absolute = self::absolute_path( $candidate );
            if ( is_string( $absolute ) && file_exists( $absolute ) ) {
                $roots[] = self::normalize_relative_path( $candidate );
            }
        }

        return array_values( array_unique( $roots ) );
    }

    private static function ignored_path_segments(): array {
        return [
            'uploads',
            'cache',
            'logs',
            self::STORAGE_DIR,
        ];
    }

    private static function ignored_basenames(): array {
        return [
            '.ds_store',
        ];
    }

    private static function should_ignore( string $relative ): bool {
        $relative = strtolower( self::normalize_relative_path( $relative ) );
        if ( $relative === '' ) {
            return true;
        }

        $parts = explode( '/', $relative );
        foreach ( $parts as $part ) {
            if ( in_array( $part, self::ignored_path_segments(), true ) ) {
                return true;
            }
        }

        return in_array( basename( $relative ), self::ignored_basenames(), true );
    }

    private static function restore_file( string $relative, string $expected_hash = '', array $git = [] ): bool {
        if ( self::restore_file_from_git( $relative, $expected_hash, $git ) ) {
            return true;
        }

        $snapshot = self::recovery_file_path( $relative );
        $target   = self::absolute_path( $relative );

        if ( ! file_exists( $snapshot ) ) {
            Metis_Logger::error( 'Integrity restore failed: snapshot missing', [ 'path' => $relative ] );
            return false;
        }

        if ( $expected_hash !== '' ) {
            $snapshot_hash = hash_file( 'sha256', $snapshot );
            if ( ! is_string( $snapshot_hash ) || ! hash_equals( $expected_hash, $snapshot_hash ) ) {
                Metis_Logger::error( 'Integrity restore failed: snapshot hash mismatch', [
                    'path' => $relative,
                    'expected_hash' => $expected_hash,
                    'snapshot_hash' => is_string( $snapshot_hash ) ? $snapshot_hash : '',
                ] );
                return false;
            }
        }

        metis_runtime_make_dir( dirname( $target ) );

        $restored = copy( $snapshot, $target );
        if ( ! $restored ) {
            Metis_Logger::error( 'Integrity restore failed: copy failed', [ 'path' => $relative ] );
            return false;
        }

        return true;
    }

    private static function restore_file_from_git( string $relative, string $expected_hash, array $git ): bool {
        if ( ! self::git_restore_enabled() ) {
            return false;
        }

        $commit = trim( (string) ( $git['commit'] ?? '' ) );
        if ( $commit === '' ) {
            return false;
        }

        $repository = self::git_repository_state();
        if ( $repository === null ) {
            return false;
        }

        $prefix = (string) ( $git['prefix'] ?? $repository['prefix'] ?? '' );
        $pathspec = ltrim( $prefix . self::normalize_relative_path( $relative ), '/' );
        if ( $pathspec === '' ) {
            return false;
        }

        $result = self::run_command(
            [
                self::git_binary(),
                '-C',
                METIS_PATH,
                'show',
                $commit . ':' . $pathspec,
            ]
        );

        if ( (int) ( $result['exit_code'] ?? 1 ) !== 0 || ! array_key_exists( 'stdout', $result ) ) {
            return false;
        }

        $content = (string) $result['stdout'];
        if ( $expected_hash !== '' ) {
            $content_hash = hash( 'sha256', $content );
            if ( ! hash_equals( $expected_hash, $content_hash ) ) {
                Metis_Logger::error( 'Integrity restore failed: git content hash mismatch', [
                    'path' => $relative,
                    'expected_hash' => $expected_hash,
                    'git_hash' => $content_hash,
                    'commit' => $commit,
                ] );
                return false;
            }
        }

        $target = self::absolute_path( $relative );
        $temp = $target . '.git-restore-' . bin2hex( random_bytes( 6 ) ) . '.tmp';

        metis_runtime_make_dir( dirname( $target ) );

        $written = file_put_contents( $temp, $content, LOCK_EX );
        if ( $written === false ) {
            @unlink( $temp );
            return false;
        }

        if ( ! @rename( $temp, $target ) ) {
            @unlink( $temp );
            return false;
        }

        return true;
    }

    private static function remove_unexpected_file( string $relative ): bool {
        $target = self::absolute_path( $relative );

        if ( ! is_string( $target ) || ! file_exists( $target ) ) {
            return true;
        }

        return unlink( $target );
    }

    private static function quarantine_file( string $relative ): ?string {
        $source = self::absolute_path( $relative );

        if ( ! is_string( $source ) || ! file_exists( $source ) ) {
            return null;
        }

        $timestamp   = gmdate( 'YmdHis' );
        $quarantine  = metis_trailingslashit( self::quarantine_dir() ) . $timestamp . '/' . self::normalize_relative_path( $relative );

        metis_runtime_make_dir( dirname( $quarantine ) );

        if ( ! @rename( $source, $quarantine ) ) {
            if ( ! @copy( $source, $quarantine ) ) {
                Metis_Logger::error( 'Integrity quarantine failed', [ 'path' => $relative ] );
                return null;
            }
        }

        return self::relative_from_absolute( $quarantine );
    }

    private static function record_security_event(
        string $action,
        string $severity,
        string $outcome,
        array $context,
        string $path = '',
        string $label = ''
    ): void {
        metis_audit_log_security( $action, [
            'severity' => $severity,
            'outcome'  => $outcome,
            'module'   => 'core',
            'resource' => [
                'type'  => 'file',
                'id'    => $path,
                'label' => $label,
            ],
            'context'  => $context,
        ] );
    }

    private static function build_alert_lines( string $trigger, array $issues, array $restored, array $removed, array $quarantined ): array {
        $lines = [
            'Metis detected filesystem integrity discrepancies.',
            'Trigger: ' . $trigger,
            'Issues: ' . count( $issues ),
        ];

        foreach ( $issues as $issue ) {
            $lines[] = strtoupper( $issue['type'] ) . ': ' . $issue['path'];
        }

        if ( ! empty( $restored ) ) {
            $lines[] = 'Restored: ' . implode( ', ', $restored );
        }

        if ( ! empty( $removed ) ) {
            $lines[] = 'Removed: ' . implode( ', ', $removed );
        }

        foreach ( $quarantined as $entry ) {
            $lines[] = 'Quarantined: ' . $entry['path'] . ' -> ' . $entry['quarantine'];
        }

        return $lines;
    }

    private static function send_alert( string $subject, array $lines ): void {
        if ( ! self::alerts_enabled() ) {
            return;
        }

        $recipients = self::alert_recipients();
        if ( empty( $recipients ) ) {
            return;
        }

        foreach ( $recipients as $recipient ) {
            \Metis\Core\Services\EmailService::sendHtml(
                (string) $recipient,
                $subject,
                '<pre>' . metis_escape_html( implode( PHP_EOL, $lines ) ) . '</pre>',
                [ 'module' => 'core' ]
            );
        }
    }

    private static function alert_recipients(): array {
        $emails = [];

        $admin_email = metis_get_option( 'admin_email' );
        if ( is_string( $admin_email ) && metis_email_is_valid( $admin_email ) ) {
            $emails[] = $admin_email;
        }

        if ( function_exists( 'get_users' ) ) {
            $admins = get_users( [
                'role'   => 'administrator',
                'fields' => [ 'user_email' ],
            ] );

            foreach ( $admins as $admin ) {
                $email = '';

                if ( is_array( $admin ) ) {
                    $email = (string) ( $admin['user_email'] ?? '' );
                } elseif ( is_object( $admin ) && isset( $admin->user_email ) ) {
                    $email = (string) $admin->user_email;
                }

                if ( metis_email_is_valid( $email ) ) {
                    $emails[] = $email;
                }
            }
        }

        return array_values( array_unique( $emails ) );
    }

    private static function schedule_scan(): void {
        if ( ! metis_runtime_next_scheduled( self::CRON_HOOK ) ) {
            metis_runtime_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    private static function ensure_storage_directories(): void {
        $directories = [
            self::storage_dir(),
            self::recovery_dir(),
            self::quarantine_dir(),
        ];

        foreach ( $directories as $directory ) {
            if ( ! is_dir( $directory ) ) {
                metis_runtime_make_dir( $directory );
            }

            self::write_directory_guards( $directory );
        }
    }

    private static function write_directory_guards( string $directory ): void {
        $index = metis_trailingslashit( $directory ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\nhttp_response_code(403);\nexit;\n" );
        }

        $htaccess = metis_trailingslashit( $directory ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" );
        }
    }

    private static function clear_recovery_directory(): void {
        self::delete_directory_contents( self::recovery_dir() );
        self::write_directory_guards( self::recovery_dir() );
    }

    private static function delete_directory_contents( string $directory ): void {
        if ( ! is_dir( $directory ) ) {
            return;
        }

        $items = scandir( $directory );
        if ( $items === false ) {
            return;
        }

        foreach ( $items as $item ) {
            if ( in_array( $item, [ '.', '..' ], true ) ) {
                continue;
            }

            $path = metis_trailingslashit( $directory ) . $item;
            if ( is_dir( $path ) ) {
                self::delete_directory_contents( $path );
                @rmdir( $path );
                continue;
            }

            @unlink( $path );
        }
    }

    private static function write_manifest( array $manifest ): bool {
        $json = metis_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) || $json === '' ) {
            return false;
        }

        $written_primary = file_put_contents( self::manifest_path(), $json );
        $written_backup  = file_put_contents( self::manifest_backup_path(), $json );

        return $written_primary !== false && $written_backup !== false;
    }

    private static function load_manifest(): ?array {
        $content = self::load_manifest_artifact( self::manifest_path(), true );
        if ( $content !== null ) {
            return $content['decoded'];
        }

        $backup = self::load_manifest_artifact( self::manifest_backup_path(), true, true );
        if ( $backup !== null ) {
            self::restore_manifest_from_backup();
            return $backup['decoded'];
        }

        return null;
    }

    private static function load_manifest_file( string $path ): ?array {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return null;
        }

        $json = file_get_contents( $path );
        if ( ! is_string( $json ) || trim( $json ) === '' ) {
            return null;
        }

        $decoded = json_decode( $json, true );

        return is_array( $decoded ) ? $decoded : null;
    }

    private static function load_manifest_artifact( string $path, bool $verify_signature = true, bool $is_backup = false ): ?array {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return null;
        }

        $raw = file_get_contents( $path );
        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return null;
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        $artifact = [
            'path' => $path,
            'raw' => $raw,
            'decoded' => $decoded,
            'is_backup' => $is_backup,
        ];

        if ( ! $verify_signature ) {
            return $artifact;
        }

        $verification = self::verify_manifest_signature_for_artifact( $artifact );
        if ( ! $verification['ok'] ) {
            Metis_Logger::error( 'Integrity manifest signature verification failed', [
                'path' => $path,
                'status' => $verification['status'],
            ] );
            return null;
        }

        return $artifact;
    }

    private static function restore_manifest_from_backup(): void {
        $backup = self::manifest_backup_path();
        if ( ! file_exists( $backup ) ) {
            return;
        }

        copy( $backup, self::manifest_path() );
        if ( file_exists( self::signature_backup_path() ) ) {
            copy( self::signature_backup_path(), self::signature_path() );
        }
    }

    private static function storage_dir(): string {
        return metis_trailingslashit( METIS_PATH ) . self::STORAGE_DIR;
    }

    private static function recovery_dir(): string {
        return metis_trailingslashit( self::storage_dir() ) . self::RECOVERY_DIR;
    }

    private static function quarantine_dir(): string {
        return metis_trailingslashit( self::storage_dir() ) . self::QUARANTINE_DIR;
    }

    private static function manifest_path(): string {
        return metis_trailingslashit( self::storage_dir() ) . self::MANIFEST_FILE;
    }

    private static function manifest_backup_path(): string {
        return metis_trailingslashit( self::storage_dir() ) . self::MANIFEST_BACKUP_FILE;
    }

    private static function signature_path(): string {
        return metis_trailingslashit( self::storage_dir() ) . self::SIGNATURE_FILE;
    }

    private static function signature_backup_path(): string {
        return metis_trailingslashit( self::storage_dir() ) . self::SIGNATURE_BACKUP_FILE;
    }

    private static function recovery_file_path( string $relative ): string {
        return metis_trailingslashit( self::recovery_dir() ) . self::normalize_relative_path( $relative );
    }

    private static function manifest_exists(): bool {
        return file_exists( self::manifest_path() );
    }

    private static function manifest_backup_exists(): bool {
        return file_exists( self::manifest_backup_path() );
    }

    private static function write_signature_files( string $signature ): bool {
        $written_primary = file_put_contents( self::signature_path(), $signature );
        $written_backup = file_put_contents( self::signature_backup_path(), $signature );

        return $written_primary !== false && $written_backup !== false;
    }

    private static function verify_manifest_signature_for_artifact( array $artifact ): array {
        if ( ! self::signature_required() ) {
            return [ 'ok' => true, 'status' => 'signature_not_required' ];
        }

        $public_key = self::public_key_pem();
        if ( $public_key === '' ) {
            return [ 'ok' => false, 'status' => 'public_key_missing' ];
        }

        $signature_path = ! empty( $artifact['is_backup'] ) ? self::signature_backup_path() : self::signature_path();
        if ( ! file_exists( $signature_path ) || ! is_readable( $signature_path ) ) {
            return [ 'ok' => false, 'status' => 'signature_missing' ];
        }

        $encoded_signature = trim( (string) file_get_contents( $signature_path ) );
        if ( $encoded_signature === '' ) {
            return [ 'ok' => false, 'status' => 'signature_missing' ];
        }

        $signature = base64_decode( $encoded_signature, true );
        if ( $signature === false ) {
            return [ 'ok' => false, 'status' => 'signature_invalid_encoding' ];
        }

        $public_key_resource = openssl_pkey_get_public( $public_key );
        if ( $public_key_resource === false ) {
            return [ 'ok' => false, 'status' => 'public_key_invalid' ];
        }

        $verified = openssl_verify( (string) $artifact['raw'], $signature, $public_key_resource, OPENSSL_ALGO_SHA256 );
        return [
            'ok' => $verified === 1,
            'status' => $verified === 1 ? 'verified' : 'signature_mismatch',
        ];
    }

    private static function verify_recovery_snapshots( array $expected_files ): array {
        $mismatches = [];

        foreach ( $expected_files as $relative => $entry ) {
            $snapshot = self::recovery_file_path( (string) $relative );
            $expected_hash = (string) ( $entry['hash'] ?? '' );

            if ( ! file_exists( $snapshot ) ) {
                $mismatches[] = [
                    'path' => (string) $relative,
                    'status' => 'snapshot_missing',
                ];
                continue;
            }

            $actual_hash = hash_file( 'sha256', $snapshot );
            if ( ! is_string( $actual_hash ) || ( $expected_hash !== '' && ! hash_equals( $expected_hash, $actual_hash ) ) ) {
                $mismatches[] = [
                    'path' => (string) $relative,
                    'status' => 'snapshot_hash_mismatch',
                    'expected_hash' => $expected_hash,
                    'actual_hash' => is_string( $actual_hash ) ? $actual_hash : '',
                ];
            }
        }

        return $mismatches;
    }

    private static function absolute_path( string $relative ): string {
        return metis_trailingslashit( METIS_PATH ) . ltrim( self::normalize_relative_path( $relative ), '/' );
    }

    private static function relative_from_absolute( string $absolute ): string {
        $base = preg_replace( '#/+#', '/', str_replace( '\\', '/', metis_trailingslashit( METIS_PATH ) ) ) ?? str_replace( '\\', '/', metis_trailingslashit( METIS_PATH ) );
        $path = preg_replace( '#/+#', '/', str_replace( '\\', '/', $absolute ) ) ?? str_replace( '\\', '/', $absolute );

        if ( strpos( $path, $base ) === 0 ) {
            $path = substr( $path, strlen( $base ) );
        }

        return self::normalize_relative_path( $path );
    }

    private static function normalize_relative_path( string $path ): string {
        $path = str_replace( '\\', '/', $path );
        $path = ltrim( $path, '/' );
        return trim( preg_replace( '#/+#', '/', $path ) ?: '', '/' );
    }

    private static function is_initialized(): bool {
        return self::get_flag( self::INITIALIZED_OPTION, false );
    }

    private static function alerts_enabled(): bool {
        return self::get_flag( self::ALERTS_OPTION, true );
    }

    private static function auto_heal_enabled(): bool {
        return self::get_flag( self::AUTO_HEAL_OPTION, false );
    }

    private static function quarantine_enabled(): bool {
        return self::get_flag( self::QUARANTINE_OPTION, false );
    }

    private static function get_flag( string $key, bool $default ): bool {
        if ( Core_Settings_Service::has( $key ) ) {
            return (bool) Core_Settings_Service::get( $key, $default );
        }

        return (bool) metis_get_option( METIS_PREFIX . '_' . $key, $default );
    }

    private static function set_flag( string $key, bool $value ): void {
        if ( ! Core_Settings_Service::set( $key, $value, true ) ) {
            metis_update_option( METIS_PREFIX . '_' . $key, $value, true );
        }
    }

    private static function current_version(): string {
        return defined( 'METIS_VERSION' ) ? (string) METIS_VERSION : '';
    }

    private static function git_manifest_metadata(): array {
        $repository = self::git_repository_state();
        if ( $repository === null ) {
            return [];
        }

        if ( ! isset( $repository['commit'], $repository['prefix'] ) ) {
            return [];
        }

        return [
            'commit' => (string) $repository['commit'],
            'prefix' => (string) $repository['prefix'],
            'dirty' => ! empty( $repository['dirty'] ),
        ];
    }

    private static function config(): array {
        static $config = null;
        if ( is_array( $config ) ) {
            return $config;
        }

        if ( function_exists( 'metis_standalone_read_config' ) ) {
            $config = metis_standalone_read_config( 'integrity', [] );
            return $config;
        }

        $path = metis_trailingslashit( METIS_PATH ) . 'config/integrity.php';
        if ( ! file_exists( $path ) ) {
            $config = [];
            return $config;
        }

        $loaded = require $path;
        $config = is_array( $loaded ) ? $loaded : [];

        return $config;
    }

    private static function signature_required(): bool {
        $config = self::config();
        return ! empty( $config['require_signature'] );
    }

    private static function git_restore_enabled(): bool {
        return self::get_flag( self::GIT_RESTORE_OPTION, true );
    }

    private static function git_binary(): string {
        $config = self::config();
        $binary = isset( $config['git_binary'] ) ? trim( (string) $config['git_binary'] ) : '';
        return $binary !== '' ? $binary : 'git';
    }

    private static function git_repository_state(): ?array {
        if ( is_array( self::$git_repository_state ) ) {
            return self::$git_repository_state;
        }

        $top_level = self::run_command( [ self::git_binary(), '-C', METIS_PATH, 'rev-parse', '--show-toplevel' ] );
        $commit = self::run_command( [ self::git_binary(), '-C', METIS_PATH, 'rev-parse', 'HEAD' ] );
        $prefix = self::run_command( [ self::git_binary(), '-C', METIS_PATH, 'rev-parse', '--show-prefix' ] );

        if (
            (int) ( $top_level['exit_code'] ?? 1 ) !== 0
            || (int) ( $commit['exit_code'] ?? 1 ) !== 0
            || (int) ( $prefix['exit_code'] ?? 1 ) !== 0
        ) {
            return null;
        }

        $dirty = self::run_command( [
            self::git_binary(),
            '-C',
            METIS_PATH,
            'status',
            '--porcelain',
            '--untracked-files=no',
            '--ignored=no',
        ] );

        self::$git_repository_state = [
            'top_level' => trim( (string) ( $top_level['stdout'] ?? '' ) ),
            'commit' => trim( (string) ( $commit['stdout'] ?? '' ) ),
            'prefix' => self::normalize_git_prefix( (string) ( $prefix['stdout'] ?? '' ) ),
            'dirty' => trim( (string) ( $dirty['stdout'] ?? '' ) ) !== '',
        ];

        return self::$git_repository_state;
    }

    private static function normalize_git_prefix( string $prefix ): string {
        $prefix = self::normalize_relative_path( trim( $prefix ) );
        return $prefix === '' ? '' : $prefix . '/';
    }

    private static function run_command( array $command, ?string $cwd = null ): array {
        if ( ! function_exists( 'proc_open' ) ) {
            return self::run_command_fallback( $command );
        }

        $descriptors = [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];

        $process = @proc_open( $command, $descriptors, $pipes, $cwd ?? METIS_PATH );
        if ( ! is_resource( $process ) ) {
            return self::run_command_fallback( $command );
        }

        fclose( $pipes[0] );
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );

        $exit_code = proc_close( $process );

        return [
            'exit_code' => is_int( $exit_code ) ? $exit_code : 1,
            'stdout' => is_string( $stdout ) ? $stdout : '',
            'stderr' => is_string( $stderr ) ? $stderr : '',
        ];
    }

    private static function run_command_fallback( array $command ): array {
        return [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'proc_open unavailable',
        ];
    }

    private static function private_key_path(): string {
        $env = getenv( 'METIS_INTEGRITY_PRIVATE_KEY' );
        if ( is_string( $env ) && trim( $env ) !== '' ) {
            return trim( $env );
        }

        $config = self::config();
        return isset( $config['private_key_path'] ) ? (string) $config['private_key_path'] : '';
    }

    private static function public_key_pem(): string {
        $config = self::config();
        if ( ! empty( $config['public_key'] ) && is_string( $config['public_key'] ) ) {
            return $config['public_key'];
        }

        $path = isset( $config['public_key_path'] ) ? (string) $config['public_key_path'] : '';
        if ( $path === '' ) {
            return '';
        }

        if ( ! str_starts_with( $path, '/' ) ) {
            $path = metis_trailingslashit( METIS_PATH ) . ltrim( $path, '/' );
        }

        $public = file_get_contents( $path );
        return is_string( $public ) ? $public : '';
    }

    private static function load_private_key( string $path ): mixed {
        if ( ! str_starts_with( $path, '/' ) ) {
            $path = metis_trailingslashit( METIS_PATH ) . ltrim( $path, '/' );
        }

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return null;
        }

        $private = file_get_contents( $path );
        if ( ! is_string( $private ) || trim( $private ) === '' ) {
            return null;
        }

        $key = openssl_pkey_get_private( $private );
        return $key === false ? null : $key;
    }
}
