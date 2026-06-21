<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

use Metis\Core\Cache\CacheService;

final class Metis_Cron_Manager {
    private const ENDPOINT_PATH     = '/system/cron';
    private const SECRET_HEADER     = 'x-metis-cron-secret';
    private const FALLBACK_HEADER   = 'x-cron-secret';
    private const OPERATION         = 'system.cron.execute';
    private const LOCK_TTL          = 900;
    private const DEFAULT_INTERVAL  = 300;
    private const CRON_JOB_TYPE     = 'system.cron.task';
    private const DRAIN_BATCH_LIMIT = 25;
    private const DRAIN_MAX_BATCHES = 4;

    /** @var array<string,array{callback:callable,label:string,interval:int,lock_ttl:int,module:string}> */
    private static array $tasks = [];
    private static bool $booted = false;
    private static bool $drain_registered = false;

    public static function init(): void {
        if ( self::$booted ) {
            return;
        }

        self::register_worker();
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
            'recovery_integrity_check',
            static function (): array {
                $service = new \Metis\Core\Recovery\PrebootIntegrityService();
                $snapshot = $service->dashboardSnapshot();
                $status = (string) ( $snapshot['status'] ?? 'unknown' );
                return [
                    'status' => $status === 'critical' ? 'failed' : 'ok',
                    'message' => $status === 'critical'
                        ? 'Recovery integrity check detected critical issues.'
                        : 'Recovery integrity check completed.',
                    'snapshot' => [
                        'status' => $status,
                        'manifest' => $snapshot['manifest'] ?? [],
                        'backup' => $snapshot['backup'] ?? [],
                        'git' => $snapshot['git'] ?? [],
                    ],
                ];
            },
            [
                'label'    => 'Recovery Integrity Check',
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
            'data_retention_cleanup',
            static function (): array {
                if ( ! \Metis\Core\Application::has_service( 'data_retention' ) ) {
                    \metis_register_core_services();
                }

                if ( ! \function_exists( 'metis_data_retention' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Data retention service is not available.',
                    ];
                }

                return \metis_data_retention()->run( [ 'batch_limit' => 1000 ] );
            },
            [
                'label'    => 'Data Retention Cleanup',
                'interval' => DAY_IN_SECONDS,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'security_audit_digest',
            [ self::class, 'run_security_audit_digest' ],
            [
                'label'    => 'Security Audit Digest',
                'interval' => DAY_IN_SECONDS,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'security',
            ]
        );

        self::register_task(
            'release_update_check',
            static function (): array {
                if ( ! function_exists( 'metis_update_service' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Update services are not available.',
                    ];
                }

                try {
                    $result = metis_update_service()->refreshUpdateState( true, 'system_cron' );
                    return [
                        'status' => ! empty( $result['updates_available'] ) ? 'updates_available' : 'current',
                        'message' => 'Core and module updates checked.',
                        'core' => (array) ( $result['core'] ?? [] ),
                        'modules' => (array) ( $result['modules'] ?? [] ),
                    ];
                } catch ( \Throwable $exception ) {
                    if ( class_exists( 'Metis_Logger' ) ) {
                        \Metis_Logger::error( 'Scheduled update check failed', [
                            'message' => $exception->getMessage(),
                        ] );
                    }

                    return [
                        'status' => 'failed',
                        'message' => $exception->getMessage(),
                    ];
                }
            },
            [
                'label'    => 'Core + Module Update Check',
                'interval' => 6 * HOUR_IN_SECONDS,
                'lock_ttl' => 30 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'release_auto_update',
            static function (): array {
                if ( ! function_exists( 'metis_release_auto_update' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Release auto-update service is not available.',
                    ];
                }

                return metis_release_auto_update( 'system_cron' );
            },
            [
                'label'    => 'Release Auto Update',
                'interval' => 6 * HOUR_IN_SECONDS,
                'lock_ttl' => 30 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'module_compliance_audit',
            static function (): array {
                if ( ! function_exists( 'metis_module_compliance_report' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Module compliance report service is unavailable.',
                    ];
                }

                $report = (array) metis_module_compliance_report( true );
                $summary = is_array( $report['summary'] ?? null ) ? $report['summary'] : [];
                $failed = (int) ( $summary['failed'] ?? 0 );
                $checked = (int) ( $summary['checked'] ?? 0 );
                $results = is_array( $report['results'] ?? null ) ? $report['results'] : [];
                $failures = array_values(
                    array_filter(
                        $results,
                        static fn ( mixed $row ): bool => is_array( $row ) && (string) ( $row['status'] ?? '' ) === 'failed'
                    )
                );

                if ( $failed > 0 ) {
                    Metis_Logger::error( 'Module compliance audit detected failures', [
                        'checked' => $checked,
                        'failed' => $failed,
                        'failures' => $failures,
                    ] );
                }

                return [
                    'status' => $failed > 0 ? 'failed' : 'ok',
                    'message' => $failed > 0
                        ? sprintf( 'Module compliance audit failed for %d module(s).', $failed )
                        : sprintf( 'All %d modules passed compliance.', $checked ),
                    'summary' => $summary,
                    'failures' => $failures,
                ];
            },
            [
                'label'    => 'Module Compliance Audit',
                'interval' => HOUR_IN_SECONDS,
                'lock_ttl' => 10 * MINUTE_IN_SECONDS,
                'module'   => 'core',
            ]
        );

        self::register_task(
            'drive_listing_sync',
            static function (): array {
                if ( ! \Metis\Core\Application::has_service( 'operations' ) ) {
                    \metis_register_core_services();
                }
                if ( ! \function_exists( 'metis_operations' ) ) {
                    return [
                        'status'  => 'skipped',
                        'message' => 'Operations service is not available.',
                    ];
                }

                $queued = \metis_operations()->queueOperation(
                    'drive.sync',
                    [],
                    [
                        'created_by' => 0,
                        'dedupe_key' => 'operation:drive.sync',
                    ]
                );
                if ( empty( $queued['ok'] ) ) {
                    return [
                        'status'  => 'failed',
                        'message' => (string) ( $queued['message'] ?? 'Drive sync could not be queued.' ),
                    ];
                }

                return [
                    'status'   => ! empty( $queued['duplicate'] ) ? 'duplicate' : 'queued',
                    'message'  => ! empty( $queued['duplicate'] ) ? 'Drive sync is already queued.' : 'Drive sync queued.',
                    'job_id'   => (int) ( $queued['job_id'] ?? 0 ),
                    'job_code' => (string) ( $queued['job_code'] ?? '' ),
                ];
            },
            [
                'label'    => 'Drive Listing Sync',
                'interval' => \function_exists( 'metis_drive_cron_interval' ) ? \metis_drive_cron_interval() : HOUR_IN_SECONDS,
                'lock_ttl' => 20 * MINUTE_IN_SECONDS,
                'module'   => 'drive',
            ]
        );

        self::register_task(
            'background_job_processing',
            static function (): array {
                if ( ! \Metis\Core\Application::has_service( 'jobs' ) ) {
                    \metis_register_core_services();
                }

                return self::drain_job_queue( 'system_cron' );
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
        $slug = metis_key_clean( $slug );
        if ( $slug === '' ) {
            return;
        }

        self::$tasks[ $slug ] = [
            'callback' => $callback,
            'label'    => (string) ( $config['label'] ?? ucwords( str_replace( '_', ' ', $slug ) ) ),
            'interval' => self::resolved_interval( $slug, (int) ( $config['interval'] ?? self::DEFAULT_INTERVAL ) ),
            'default_interval' => max( 60, (int) ( $config['interval'] ?? self::DEFAULT_INTERVAL ) ),
            'lock_ttl' => max( 60, (int) ( $config['lock_ttl'] ?? self::LOCK_TTL ) ),
            'module'   => metis_key_clean( (string) ( $config['module'] ?? 'core' ) ),
        ];
    }

    public static function endpoint_path(): string {
        return self::ENDPOINT_PATH;
    }

    public static function endpoint_url(): string {
        return metis_home_url( self::ENDPOINT_PATH );
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
        $slug = metis_key_clean( $slug );
        if ( $slug === '' ) {
            return false;
        }

        $disabled = Core_Settings_Service::get( 'system_cron_disabled_tasks', [] );
        if ( ! is_array( $disabled ) ) {
            $disabled = [];
        }

        return ! in_array( $slug, array_map( 'metis_key_clean', $disabled ), true );
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
        $path = rtrim( $request->path(), '/' );
        if ( $path === '' ) {
            $path = '/';
        }

        if ( $path === self::ENDPOINT_PATH ) {
            return true;
        }

        // Temporary compatibility path for misconfigured schedulers that append the endpoint twice.
        return $path === self::ENDPOINT_PATH . self::ENDPOINT_PATH;
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
        metis_security_enclave()->execute(
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
        $trigger    = metis_key_clean( (string) ( $input['trigger'] ?? 'cloudflare_worker' ) );
        $request_id = metis_audit_request_id();
        $selected   = self::normalize_requested_tasks( $input['tasks'] ?? [] );
        $results    = self::queue_due_tasks( $selected, $force_all, $trigger, $request_id );

        if ( ! empty( $results['summary']['queued'] ) || ! empty( $results['summary']['duplicate'] ) ) {
            self::register_post_response_drain( $request_id );
        }

        $status = ( ! empty( $results['summary']['queued'] ) || ! empty( $results['summary']['duplicate'] ) ) ? 202 : 200;
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

    public static function queue_due_tasks( array $selected = [], bool $force_all = false, string $trigger = 'manual', string $request_id = '', bool $ignore_disabled = false ): array {
        self::init();

        $request_id = $request_id !== '' ? $request_id : metis_audit_request_id();
        $summary = [
            'queued'    => [],
            'duplicate' => [],
            'skipped'   => [],
            'failed'    => [],
        ];
        $results = [];
        $task_slugs = empty( $selected ) ? array_keys( self::$tasks ) : $selected;
        $now = time();

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

            $task  = self::$tasks[ $slug ];
            $state = self::task_state( $slug );

            if ( ! $force_all && ! self::task_is_due( $state, (int) $task['interval'], $now ) ) {
                $summary['skipped'][] = $slug;
                $results[ $slug ] = [
                    'status'   => 'skipped',
                    'message'  => 'Task is not due.',
                    'next_due' => self::next_due_timestamp( $state, (int) $task['interval'], $now ),
                ];
                continue;
            }

            $queued = metis_job_queue()->enqueue(
                self::CRON_JOB_TYPE,
                [
                    'task'            => $slug,
                    'force'           => $force_all,
                    'trigger'         => $trigger,
                    'request_id'      => $request_id,
                    'ignore_disabled' => $ignore_disabled,
                ],
                [
                    'queue'       => 'system',
                    'priority'    => 10,
                    'max_attempts'=> 3,
                    'dedupe_key'  => 'system_cron_task:' . $slug,
                ]
            );

            if ( empty( $queued['ok'] ) ) {
                $summary['failed'][] = $slug;
                $results[ $slug ] = [
                    'status'  => 'failed',
                    'message' => (string) ( $queued['message'] ?? 'Failed to queue task.' ),
                ];
                continue;
            }

            if ( ! empty( $queued['duplicate'] ) ) {
                $summary['duplicate'][] = $slug;
                $results[ $slug ] = [
                    'status'   => 'queued',
                    'message'  => 'Task is already queued.',
                    'job_id'   => (int) ( $queued['job_id'] ?? 0 ),
                    'job_code' => (string) ( $queued['job_code'] ?? '' ),
                ];
                continue;
            }

            $summary['queued'][] = $slug;
            $results[ $slug ] = [
                'status'   => 'queued',
                'message'  => 'Task queued for asynchronous execution.',
                'job_id'   => (int) ( $queued['job_id'] ?? 0 ),
                'job_code' => (string) ( $queued['job_code'] ?? '' ),
            ];
        }

        Metis_Logger::info( 'System cron tasks queued', [
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
        $slug = metis_key_clean( $slug );
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

    public static function run_queued_task( array $payload = [] ): array {
        self::init();

        $slug            = metis_key_clean( (string) ( $payload['task'] ?? '' ) );
        $force           = ! empty( $payload['force'] );
        $trigger         = metis_key_clean( (string) ( $payload['trigger'] ?? 'queued' ) );
        $request_id      = (string) ( $payload['request_id'] ?? metis_audit_request_id() );
        $ignore_disabled = ! empty( $payload['ignore_disabled'] );

        if ( $slug === '' ) {
            throw new RuntimeException( 'Queued cron task is missing a slug.' );
        }

        $result = self::run_due_tasks( [ $slug ], $force, $trigger, $request_id, $ignore_disabled );
        return (array) ( $result['results'][ $slug ] ?? [ 'status' => 'skipped', 'message' => 'Task result missing.' ] );
    }

    public static function drain_job_queue( string $worker_name = 'system_cron_async' ): array {
        self::init();

        if ( function_exists( 'metis_register_core_services' ) ) {
            metis_register_core_services();
        }

        if ( class_exists( \Metis\Core\Application::class ) && \Metis\Core\Application::has_service( 'operations' ) ) {
            \Metis\Core\Application::service( 'operations' );
        }

        $summary = [
            'processed' => 0,
            'completed' => 0,
            'failed'    => 0,
            'batches'   => 0,
        ];

        for ( $i = 0; $i < self::DRAIN_MAX_BATCHES; $i++ ) {
            $batch = metis_job_queue()->process( self::DRAIN_BATCH_LIMIT, $worker_name );
            $summary['batches']++;
            $summary['processed'] += (int) ( $batch['processed'] ?? 0 );
            $summary['completed'] += (int) ( $batch['completed'] ?? 0 );
            $summary['failed'] += (int) ( $batch['failed'] ?? 0 );

            if ( (int) ( $batch['processed'] ?? 0 ) < 1 ) {
                break;
            }
        }

        return $summary;
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

        $started_at = metis_current_time( 'mysql' );
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
            $result_status = strtolower( trim( (string) ( $result_payload['status'] ?? 'ok' ) ) );
            $task_failed = in_array( $result_status, [ 'failed', 'error' ], true );

            self::update_task_state( $slug, array_merge( self::task_state( $slug ), [
                'last_finished_at' => metis_current_time( 'mysql' ),
                'last_status'      => $task_failed ? 'failed' : 'ok',
                'running'          => false,
                'last_error'       => $task_failed ? (string) ( $result_payload['message'] ?? 'Task reported failure.' ) : '',
            ] ) );

            if ( $task_failed ) {
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
                        'result'  => $result_payload,
                    ],
                ] );

                Metis_Logger::error( 'Cron task reported failure', [
                    'task'       => $slug,
                    'request_id' => $request_id,
                    'result'     => $result_payload,
                ] );

                return [
                    'status' => 'failed',
                    'result' => $result_payload,
                    'message' => (string) ( $result_payload['message'] ?? 'Task reported failure.' ),
                ];
            }

            if ( self::verbose_operational_audit_enabled() ) {
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
            }

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
                'last_finished_at' => metis_current_time( 'mysql' ),
                'last_status'      => 'failed',
                'running'          => false,
                'last_error'       => 'Task failed. Review logs for details.',
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
                'message' => 'Task failed. Review logs for details.',
            ];
        } finally {
            self::release_lock( $slug );
        }
    }

    private static function register_worker(): void {
        if ( ! function_exists( 'metis_job_workers' ) ) {
            return;
        }

        metis_job_workers()->register(
            self::CRON_JOB_TYPE,
            static function ( array $payload ): array {
                return self::run_queued_task( $payload );
            }
        );
    }

    private static function register_post_response_drain( string $request_id ): void {
        if ( self::$drain_registered ) {
            return;
        }

        self::$drain_registered = true;

        register_shutdown_function(
            static function () use ( $request_id ): void {
                self::finish_client_response();

                $summary = self::drain_job_queue();
                Metis_Logger::info( 'Async cron drain completed', [
                    'request_id' => $request_id,
                    'summary'    => $summary,
                ] );
            }
        );
    }

    private static function finish_client_response(): void {
        if ( function_exists( 'session_status' ) && session_status() === PHP_SESSION_ACTIVE ) {
            session_write_close();
        }

        ignore_user_abort( true );

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
            return;
        }

        while ( ob_get_level() > 0 ) {
            @ob_end_flush();
        }

        @flush();
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
        $state = metis_get_option( 'metis_cron_task_state_' . $slug, [] );
        return is_array( $state ) ? $state : [];
    }

    private static function update_task_state( string $slug, array $state ): void {
        metis_update_option( 'metis_cron_task_state_' . $slug, $state, false );
    }

    private static function acquire_lock( string $slug, int $ttl ): bool {
        $key = 'metis_cron_lock_' . $slug;
        if ( metis_get_transient( $key ) ) {
            return false;
        }

        metis_set_transient( $key, 1, $ttl );
        return true;
    }

    private static function release_lock( string $slug ): void {
        metis_delete_transient( 'metis_cron_lock_' . $slug );
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
            $slug = metis_key_clean( (string) $slug );
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

    private static function env_int( string $name, int $default ): int {
        $value = getenv( $name );
        if ( $value === false ) {
            return $default;
        }

        $value = trim( (string) $value );
        if ( $value === '' || ! preg_match( '/^-?\d+$/', $value ) ) {
            return $default;
        }

        return (int) $value;
    }

    private static function run_cache_cleanup(): array {
        CacheService::clearGroup( 'query' );
        CacheService::clearGroup( 'fragments' );
        CacheService::clearGroup( 'hermes' );
        metis_reports_clear_cache();
        $release_cleanup = \function_exists( 'metis_release_cleanup_artifacts' )
            ? \metis_release_cleanup_artifacts( 'cache_cleanup' )
            : [ 'status' => 'skipped', 'message' => 'Release manager is not available.' ];
        $job_queue_cleanup = \function_exists( 'metis_job_queue' ) && \method_exists( \metis_job_queue(), 'cleanupHistory' )
            ? \metis_job_queue()->cleanupHistory( [ 'limit' => 5000 ] )
            : [ 'status' => 'skipped', 'message' => 'Job queue cleanup is not available.' ];
        $audit_compaction = \function_exists( 'metis_audit_compact' )
            ? \metis_audit_compact( 10000 )
            : [ 'status' => 'skipped', 'message' => 'Audit compaction is not available.' ];

        return [
            'deleted_rows' => 0,
            'reports_cache_cleared' => true,
            'cache_groups_cleared' => [ 'query', 'fragments', 'hermes' ],
            'release_artifact_cleanup' => $release_cleanup,
            'job_queue_history_cleanup' => $job_queue_cleanup,
            'audit_context_compaction' => $audit_compaction,
        ];
    }

    private static function verbose_operational_audit_enabled(): bool {
        if ( ! class_exists( 'Core_Settings_Service' ) ) {
            return false;
        }

        $value = Core_Settings_Service::get( 'audit_verbose_operational_events', false );
        if ( is_bool( $value ) ) {
            return $value;
        }

        return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
    }

    private static function run_security_audit_digest(): array {
        $table = Metis_Tables::get( 'audit_security' );
        $window_hours = max( 1, min( 168, self::env_int( 'METIS_SECURITY_DIGEST_WINDOW_HOURS', 24 ) ) );

        $total = (int) metis_db()->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)",
            [ $window_hours ]
        );

        $top_actions = metis_db()->fetchAll(
            "SELECT action_type, COUNT(*) AS total
             FROM {$table}
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
             GROUP BY action_type
             ORDER BY total DESC
             LIMIT 10",
            [ $window_hours ]
        );

        $severity_rows = metis_db()->fetchAll(
            "SELECT severity, COUNT(*) AS total
             FROM {$table}
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
             GROUP BY severity",
            [ $window_hours ]
        );

        $outcome_rows = metis_db()->fetchAll(
            "SELECT outcome, COUNT(*) AS total
             FROM {$table}
             WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
             GROUP BY outcome",
            [ $window_hours ]
        );

        $digest = [
            'window_hours'  => $window_hours,
            'generated_at'  => gmdate( 'Y-m-d H:i:s' ),
            'total_events'  => $total,
            'top_actions'   => is_array( $top_actions ) ? $top_actions : [],
            'by_severity'   => is_array( $severity_rows ) ? $severity_rows : [],
            'by_outcome'    => is_array( $outcome_rows ) ? $outcome_rows : [],
        ];

        metis_update_option( 'metis_security_audit_digest_last', $digest, false );

        Metis_Logger::info( 'Security audit digest generated', [
            'window_hours' => $window_hours,
            'total_events' => $total,
            'top_actions'  => array_slice( (array) $digest['top_actions'], 0, 3 ),
        ] );

        return $digest;
    }

}

Metis_Cron_Manager::init();
