<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class Metis_Cron_Manager {
    private const ENDPOINT_PATH     = '/system/cron';
    private const SECRET_HEADER     = 'x-metis-cron-secret';
    private const FALLBACK_HEADER   = 'x-cron-secret';
    private const OPERATION         = 'system.cron.execute';
    private const LOCK_TTL          = 900;
    private const DEFAULT_INTERVAL  = 300;

    /** @var array<string,array{callback:callable,label:string,interval:int,lock_ttl:int,module:string}> */
    private static array $tasks = [];
    private static bool $booted = false;

    public static function init(): void {
        if ( self::$booted ) {
            return;
        }

        self::register_policy();
        self::register_task(
            'integrity_scan',
            static function (): array {
                return Metis_Integrity_Manager::scan_and_heal( 'system_cron' );
            },
            [
                'label'    => 'Integrity Scan',
                'interval' => HOUR_IN_SECONDS,
                'lock_ttl' => 20 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'cache_cleanup',
            [ self::class, 'run_cache_cleanup' ],
            [
                'label'    => 'Cache Cleanup',
                'interval' => HOUR_IN_SECONDS,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'release_update_check',
            static function (): array {
                if ( ! function_exists( 'metis_release_check_for_updates' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Release manager is not available.',
                    ];
                }

                return metis_release_check_for_updates( false, 'system_cron' );
            },
            [
                'label'    => 'Release Update Check',
                'interval' => 6 * HOUR_IN_SECONDS,
                'lock_ttl' => 30 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'background_job_processing',
            static function (): array {
                if ( ! \Metis\Core\Application::has_service( 'jobs' ) ) {
                    \metis_register_core_services();
                }

                return \metis_job_queue()->process( 25, 'system_cron' );
            },
            [
                'label'    => 'Background Job Processing',
                'interval' => 60,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::$booted = true;
    }

    public static function register_task( string $slug, callable $callback, array $config = [] ): void {
        $slug = sanitize_key( $slug );
        if ( $slug === '' ) {
            return;
        }

        self::$tasks[ $slug ] = [
            'callback' => $callback,
            'label'    => (string) ( $config['label'] ?? ucwords( str_replace( '_', ' ', $slug ) ) ),
            'interval' => self::resolved_interval( $slug, (int) ( $config['interval'] ?? self::DEFAULT_INTERVAL ) ),
            'default_interval' => max( 60, (int) ( $config['interval'] ?? self::DEFAULT_INTERVAL ) ),
            'lock_ttl' => max( 60, (int) ( $config['lock_ttl'] ?? self::LOCK_TTL ) ),
            'module'   => sanitize_key( (string) ( $config['module'] ?? 'core' ) ),
        ];
    }

    public static function endpoint_path(): string {
        return self::ENDPOINT_PATH;
    }

    public static function endpoint_url(): string {
        return home_url( self::ENDPOINT_PATH );
    }

    public static function registered_tasks(): array {
        self::init();

        $tasks = [];
        foreach ( self::$tasks as $slug => $task ) {
            $tasks[ $slug ] = [
                'label'    => $task['label'],
                'interval' => (int) $task['interval'],
                'default_interval' => (int) ( $task['default_interval'] ?? $task['interval'] ),
                'lock_ttl' => (int) $task['lock_ttl'],
                'module'   => $task['module'],
                'enabled'  => self::task_enabled( $slug ),
            ];
        }

        return $tasks;
    }

    public static function task_enabled( string $slug ): bool {
        $slug = sanitize_key( $slug );
        if ( $slug === '' ) {
            return false;
        }

        $disabled = Core_Settings_Service::get( 'system_cron_disabled_tasks', [] );
        if ( ! is_array( $disabled ) ) {
            $disabled = [];
        }

        return ! in_array( $slug, array_map( 'sanitize_key', $disabled ), true );
    }

    public static function configured_secret_masked(): string {
        $secret = self::configured_secret();
        if ( $secret === '' ) {
            return '';
        }

        if ( strlen( $secret ) <= 8 ) {
            return str_repeat( '•', strlen( $secret ) );
        }

        return substr( $secret, 0, 4 ) . str_repeat( '•', max( 8, strlen( $secret ) - 8 ) ) . substr( $secret, -4 );
    }

    public static function matches_request( Metis_Http_Request $request ): bool {
        return rtrim( $request->path(), '/' ) === self::ENDPOINT_PATH;
    }

    public static function authorize_request( Metis_Http_Request $request ): array {
        self::register_policy();

        $secret = self::configured_secret();
        if ( $secret === '' ) {
            throw new Metis_Security_Enclave_Exception(
                'System cron secret is not configured.',
                'cron_secret_missing',
                503
            );
        }

        $provided = self::request_secret( $request );
        if ( $provided === '' || ! hash_equals( $secret, $provided ) ) {
            throw new Metis_Security_Enclave_Exception(
                'Invalid cron scheduler secret.',
                'invalid_cron_secret',
                403
            );
        }

        $context = self::request_context( $request );
        metis_security_enclave()->handle(
            self::OPERATION,
            $context,
            static function () {
                return true;
            }
        );

        return $context;
    }

    public static function handle_request( Metis_Http_Request $request ): Metis_Http_Response {
        $input      = $request->input();
        $force_all  = ! empty( $input['force'] );
        $trigger    = sanitize_key( (string) ( $input['trigger'] ?? 'cloudflare_worker' ) );
        $request_id = metis_audit_request_id();
        $selected   = self::normalize_requested_tasks( $input['tasks'] ?? [] );
        $results    = self::run_due_tasks( $selected, $force_all, $trigger, $request_id );

        $status = 200;
        if ( ! empty( $results['summary']['failed'] ) ) {
            $status = 207;
        }

        return Metis_Http_Response::json(
            [
                'success' => empty( $results['summary']['failed'] ),
                'data'    => $results,
            ],
            $status,
            [
                'Cache-Control'     => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Metis-Request-Id' => $request_id,
            ]
        );
    }

    public static function run_due_tasks( array $selected = [], bool $force_all = false, string $trigger = 'manual', string $request_id = '', bool $ignore_disabled = false ): array {
        self::init();

        $request_id = $request_id !== '' ? $request_id : metis_audit_request_id();
        $summary = [
            'ran'     => [],
            'skipped' => [],
            'failed'  => [],
        ];
        $results = [];
        $task_slugs = empty( $selected ) ? array_keys( self::$tasks ) : $selected;

        foreach ( $task_slugs as $slug ) {
            if ( ! isset( self::$tasks[ $slug ] ) ) {
                $summary['skipped'][] = $slug;
                $results[ $slug ] = [
                    'status'  => 'skipped',
                    'message' => 'Task is not registered.',
                ];
                continue;
            }

            if ( ! $ignore_disabled && ! self::task_enabled( $slug ) ) {
                $summary['skipped'][] = $slug;
                $results[ $slug ] = [
                    'status'  => 'skipped',
                    'message' => 'Task is disabled.',
                ];
                continue;
            }

            $result = self::run_task( $slug, $force_all, $trigger, $request_id );
            $results[ $slug ] = $result;

            if ( $result['status'] === 'ok' ) {
                $summary['ran'][] = $slug;
            } elseif ( $result['status'] === 'failed' ) {
                $summary['failed'][] = $slug;
            } else {
                $summary['skipped'][] = $slug;
            }
        }

        Metis_Logger::info( 'System cron runner completed', [
            'trigger'    => $trigger,
            'request_id' => $request_id,
            'summary'    => $summary,
        ] );

        return [
            'trigger'    => $trigger,
            'request_id' => $request_id,
            'summary'    => $summary,
            'results'    => $results,
        ];
    }

    public static function run_task_now( string $slug, string $trigger = 'manual_ui', string $request_id = '' ): array {
        $slug = sanitize_key( $slug );
        if ( $slug === '' ) {
            return [
                'summary' => [
                    'ran' => [],
                    'skipped' => [ $slug ],
                    'failed' => [],
                ],
                'results' => [
                    $slug => [
                        'status' => 'skipped',
                        'message' => 'Task slug is invalid.',
                    ],
                ],
                'trigger' => $trigger,
                'request_id' => $request_id !== '' ? $request_id : metis_audit_request_id(),
            ];
        }

        return self::run_due_tasks( [ $slug ], true, $trigger, $request_id, true );
    }

    private static function run_task( string $slug, bool $force, string $trigger, string $request_id ): array {
        $task  = self::$tasks[ $slug ];
        $state = self::task_state( $slug );
        $now   = time();

        if ( ! $force && ! self::task_is_due( $state, (int) $task['interval'], $now ) ) {
            return [
                'status'   => 'skipped',
                'message'  => 'Task is not due.',
                'next_due' => self::next_due_timestamp( $state, (int) $task['interval'], $now ),
            ];
        }

        if ( ! self::acquire_lock( $slug, (int) $task['lock_ttl'] ) ) {
            return [
                'status'  => 'skipped',
                'message' => 'Task is already running.',
            ];
        }

        $started_at = current_time( 'mysql' );
        self::update_task_state( $slug, array_merge( $state, [
            'last_started_at' => $started_at,
            'last_request_id' => $request_id,
            'last_trigger'    => $trigger,
            'running'         => true,
        ] ) );

        Metis_Logger::info( 'Cron task started', [
            'task'       => $slug,
            'label'      => $task['label'],
            'trigger'    => $trigger,
            'request_id' => $request_id,
        ] );

        try {
            $payload = call_user_func( $task['callback'] );
            $result_payload = is_array( $payload ) ? $payload : [ 'result' => $payload ];

            self::update_task_state( $slug, array_merge( self::task_state( $slug ), [
                'last_finished_at' => current_time( 'mysql' ),
                'last_status'      => 'ok',
                'running'          => false,
                'last_error'       => '',
            ] ) );

            metis_audit_log_activity( 'system_cron_task_completed', [
                'module'     => $task['module'],
                'request_id' => $request_id,
                'resource'   => [
                    'type'  => 'cron_task',
                    'id'    => $slug,
                    'label' => $task['label'],
                ],
                'context'    => [
                    'trigger' => $trigger,
                    'result'  => $result_payload,
                ],
            ] );

            Metis_Logger::info( 'Cron task completed', [
                'task'       => $slug,
                'request_id' => $request_id,
                'result'     => $result_payload,
            ] );

            return [
                'status' => 'ok',
                'result' => $result_payload,
            ];
        } catch ( Throwable $e ) {
            self::update_task_state( $slug, array_merge( self::task_state( $slug ), [
                'last_finished_at' => current_time( 'mysql' ),
                'last_status'      => 'failed',
                'running'          => false,
                'last_error'       => $e->getMessage(),
            ] ) );

            metis_audit_log_security( 'system_cron_task_failed', [
                'module'     => $task['module'],
                'request_id' => $request_id,
                'severity'   => 'warning',
                'outcome'    => 'error',
                'resource'   => [
                    'type'  => 'cron_task',
                    'id'    => $slug,
                    'label' => $task['label'],
                ],
                'context'    => [
                    'trigger' => $trigger,
                    'error'   => $e->getMessage(),
                ],
            ] );

            Metis_Logger::error( 'Cron task failed', [
                'task'       => $slug,
                'request_id' => $request_id,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
            ] );

            return [
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ];
        } finally {
            self::release_lock( $slug );
        }
    }

    private static function task_is_due( array $state, int $interval, int $now ): bool {
        $last_finished = self::timestamp_from_state( $state['last_finished_at'] ?? '' );
        if ( $last_finished < 1 ) {
            return true;
        }

        return ( $now - $last_finished ) >= $interval;
    }

    private static function next_due_timestamp( array $state, int $interval, int $now ): int {
        $last_finished = self::timestamp_from_state( $state['last_finished_at'] ?? '' );
        if ( $last_finished < 1 ) {
            return $now;
        }

        return $last_finished + $interval;
    }

    private static function task_state( string $slug ): array {
        $state = get_option( 'metis_cron_task_state_' . $slug, [] );
        return is_array( $state ) ? $state : [];
    }

    private static function update_task_state( string $slug, array $state ): void {
        update_option( 'metis_cron_task_state_' . $slug, $state, false );
    }

    private static function acquire_lock( string $slug, int $ttl ): bool {
        $key = 'metis_cron_lock_' . $slug;
        if ( get_transient( $key ) ) {
            return false;
        }

        set_transient( $key, 1, $ttl );
        return true;
    }

    private static function release_lock( string $slug ): void {
        delete_transient( 'metis_cron_lock_' . $slug );
    }

    private static function timestamp_from_state( mixed $value ): int {
        if ( ! is_string( $value ) || $value === '' ) {
            return 0;
        }

        $timestamp = strtotime( $value );
        return $timestamp !== false ? (int) $timestamp : 0;
    }

    private static function request_context( Metis_Http_Request $request ): array {
        return [
            'actor' => [
                'id'          => 'cloudflare-worker',
                'roles'       => [ 'system' ],
                'permissions' => [ 'cron' ],
                'session_id'  => '',
            ],
            'meta' => [
                'ip'         => metis_audit_ip_address(),
                'user_agent' => metis_audit_user_agent(),
                'request_id' => metis_audit_request_id(),
            ],
            'input' => $request->input(),
        ];
    }

    private static function request_secret( Metis_Http_Request $request ): string {
        $secret = trim( $request->header( self::SECRET_HEADER ) );
        if ( $secret !== '' ) {
            return $secret;
        }

        return trim( $request->header( self::FALLBACK_HEADER ) );
    }

    private static function configured_secret(): string {
        if ( defined( 'METIS_CRON_SECRET' ) ) {
            return trim( (string) constant( 'METIS_CRON_SECRET' ) );
        }

        $secret = Core_Settings_Service::get( 'system_cron_secret', '' );
        return is_string( $secret ) ? trim( $secret ) : '';
    }

    private static function register_policy(): void {
        $enclave = metis_security_enclave();
        if ( $enclave->has_policy( self::OPERATION ) ) {
            return;
        }

        $enclave->register_policy(
            new Metis_Security_Policy(
                self::OPERATION,
                null,
                'execute',
                false,
                false,
                false,
                null,
                12,
                60
            )
        );
    }

    private static function normalize_requested_tasks( mixed $value ): array {
        if ( is_string( $value ) ) {
            $value = array_filter( array_map( 'trim', explode( ',', $value ) ) );
        }

        if ( ! is_array( $value ) ) {
            return [];
        }

        $tasks = [];
        foreach ( $value as $slug ) {
            $slug = sanitize_key( (string) $slug );
            if ( $slug !== '' && ! in_array( $slug, $tasks, true ) ) {
                $tasks[] = $slug;
            }
        }

        return $tasks;
    }

    private static function resolved_interval( string $slug, int $default_interval ): int {
        $default_interval = max( 60, $default_interval );
        $overrides = Core_Settings_Service::get( 'system_cron_task_intervals', [] );
        if ( ! is_array( $overrides ) ) {
            return $default_interval;
        }

        $override = isset( $overrides[ $slug ] ) ? (int) $overrides[ $slug ] : 0;
        return $override >= 60 ? $override : $default_interval;
    }

    private static function run_cache_cleanup(): array {
        global $wpdb;

        $now = time();
        $deleted = 0;
        $patterns = [
            '_transient_timeout_metis_%',
            '_site_transient_timeout_metis_%',
        ];

        foreach ( $patterns as $pattern ) {
            $expired = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value <> '' AND CAST(option_value AS UNSIGNED) < %d",
                    $pattern,
                    $now
                )
            ) ?: [];

            foreach ( $expired as $timeout_key ) {
                $timeout_key = (string) $timeout_key;
                $value_key = preg_replace( '/^_(site_)?transient_timeout_/', '_$1transient_', $timeout_key );
                $deleted += (int) $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
                        $timeout_key,
                        (string) $value_key
                    )
                );
            }
        }

        metis_reports_clear_cache();

        return [
            'deleted_rows' => $deleted,
            'reports_cache_cleared' => true,
        ];
    }

}

Metis_Cron_Manager::init();
