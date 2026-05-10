<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

if ( ! class_exists( 'Metis_Walkthrough_Service' ) ) {
    final class Metis_Walkthrough_Service {
        private Metis_Help_Service $help;

        public function __construct( Metis_Help_Service $help ) {
            $this->help = $help;
        }

        public function enabled(): bool {
            return $this->help->walkthrough_enabled();
        }

        /**
         * @return array<string, array<string, mixed>>
         */
        public function all(): array {
            return $this->help->walkthroughs();
        }

        public function get( string $walkthrough_id ): ?array {
            $walkthrough_id = metis_help_plain_key( $walkthrough_id );
            $all = $this->all();
            return $all[ $walkthrough_id ] ?? null;
        }

        /**
         * @return array<string, mixed>
         */
        public function progress( ?int $user_id = null ): array {
            $user_id = $user_id ?? ( function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0 );
            if ( $user_id < 1 || ! class_exists( 'Core_Settings_Service' ) ) {
                return [];
            }

            $progress = \Core_Settings_Service::get( $this->progress_key( $user_id ), [] );
            return is_array( $progress ) ? $progress : [];
        }

        /**
         * @param array<string, mixed> $state
         */
        public function save_progress( string $walkthrough_id, array $state, ?int $user_id = null ): bool {
            $user_id = $user_id ?? ( function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0 );
            if ( $user_id < 1 || ! class_exists( 'Core_Settings_Service' ) ) {
                return false;
            }

            $walkthrough_id = metis_help_plain_key( $walkthrough_id );
            if ( $walkthrough_id === '' ) {
                return false;
            }

            $progress = $this->progress( $user_id );
            $progress[ $walkthrough_id ] = array_merge(
                [
                    'step' => 0,
                    'completed' => false,
                    'skipped' => false,
                    'updated_at' => gmdate( 'Y-m-d H:i:s' ),
                ],
                $progress[ $walkthrough_id ] ?? [],
                $state,
                [ 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ]
            );

            return \Core_Settings_Service::set( $this->progress_key( $user_id ), $progress, false );
        }

        public function autostart_candidate( string $domain = '', string $view = '' ): ?array {
            if ( ! $this->enabled() ) {
                return null;
            }

            $progress = $this->progress();
            $domain   = metis_help_plain_key( $domain );
            $view     = metis_help_plain_key( $view );

            foreach ( $this->all() as $id => $walkthrough ) {
                $trigger = (string) ( $walkthrough['trigger'] ?? 'manual' );
                if ( ! in_array( $trigger, [ 'first_login', 'new_feature', 'feature' ], true ) ) {
                    continue;
                }

                if ( ! empty( $progress[ $id ]['completed'] ) || ! empty( $progress[ $id ]['skipped'] ) ) {
                    continue;
                }

                $module = metis_help_plain_key( (string) ( $walkthrough['module'] ?? '' ) );
                if ( $module !== '' && $domain !== '' && $module !== $domain ) {
                    continue;
                }

                $topic = (string) ( $walkthrough['topic'] ?? '' );
                if ( $topic !== '' ) {
                    $parts = explode( '.', $topic, 2 );
                    $topic_domain = metis_help_plain_key( (string) ( $parts[0] ?? '' ) );
                    $topic_view   = metis_help_plain_key( (string) ( $parts[1] ?? '' ) );

                    if ( $topic_domain !== '' && $domain !== '' && $topic_domain !== $domain ) {
                        continue;
                    }

                    if ( $topic_view !== '' && $view !== '' && $topic_view !== $view ) {
                        continue;
                    }
                }

                return [
                    'id' => $id,
                    'title' => (string) ( $walkthrough['title'] ?? $id ),
                    'trigger' => $trigger,
                ];
            }

            return null;
        }

        private function progress_key( int $user_id ): string {
            return 'walkthrough_progress_user_' . $user_id;
        }
    }
}

if ( ! function_exists( 'metis_walkthrough_service' ) ) {
    function metis_walkthrough_service(): ?Metis_Walkthrough_Service {
        if ( ! function_exists( 'metis_service' ) ) {
            return null;
        }

        $service = metis_service( 'walkthroughs' );
        return $service instanceof Metis_Walkthrough_Service ? $service : null;
    }
}

if ( ! function_exists( 'metis_walkthrough_error_response' ) ) {
    function metis_walkthrough_error_response( string $action, string $message, int $status, string $error_code ): never {
        $request_id = function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : '';
        $endpoint = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/api/ajax' ), PHP_URL_PATH ) ?? '/api/ajax' );
        $action_key = metis_key_clean( $action );
        $code_key = metis_key_clean( $error_code );

        metis_audit_log_security( 'ajax_action_failed', [
            'module'   => 'core',
            'severity' => $status >= 500 ? 'error' : 'warning',
            'outcome'  => 'failed',
            'resource' => [
                'type'  => 'ajax_action',
                'id'    => $action_key,
                'label' => $code_key,
            ],
            'context'  => [
                'route'         => 'ajax.metis.api',
                'endpoint'      => $endpoint,
                'status_code'   => $status,
                'error_code'    => $code_key,
                'error_message' => $message,
                'request_id'    => $request_id,
            ],
        ] );

        metis_runtime_send_json_error( [ 'message' => $message, 'code' => $code_key ], $status );
    }
}

if ( ! function_exists( 'metis_walkthrough_get_response' ) ) {
    function metis_walkthrough_get_response(): void {
        $service = metis_walkthrough_service();
        if ( ! $service instanceof Metis_Walkthrough_Service ) {
            metis_walkthrough_error_response( 'metis_walkthrough_get', 'Walkthrough service is unavailable.', 500, 'walkthrough_service_unavailable' );
        }

        $id = metis_help_plain_key( (string) ( metis_request_post()['walkthrough'] ?? '' ) );
        if ( $id === '' ) {
            metis_walkthrough_error_response( 'metis_walkthrough_get', 'Walkthrough id is required.', 400, 'walkthrough_id_required' );
        }

        $walkthrough = $service->get( $id );
        if ( ! is_array( $walkthrough ) ) {
            metis_walkthrough_error_response( 'metis_walkthrough_get', 'Walkthrough not found.', 404, 'walkthrough_not_found' );
        }

        metis_runtime_send_json_success(
            [
                'walkthrough' => $walkthrough,
                'progress' => $service->progress(),
            ]
        );
    }
}

if ( ! function_exists( 'metis_walkthrough_progress_response' ) ) {
    function metis_walkthrough_progress_response(): void {
        $service = metis_walkthrough_service();
        if ( ! $service instanceof Metis_Walkthrough_Service ) {
            metis_walkthrough_error_response( 'metis_walkthrough_progress', 'Walkthrough service is unavailable.', 500, 'walkthrough_service_unavailable' );
        }

        $id = metis_help_plain_key( (string) ( metis_request_post()['walkthrough'] ?? '' ) );
        if ( $id === '' ) {
            metis_walkthrough_error_response( 'metis_walkthrough_progress', 'Walkthrough id is required.', 400, 'walkthrough_id_required' );
        }

        $state = [
            'step' => max( 0, (int) ( metis_request_post()['step'] ?? 0 ) ),
            'completed' => ! empty( metis_request_post()['completed'] ),
            'skipped' => ! empty( metis_request_post()['skipped'] ),
        ];

        $saved = $service->save_progress( $id, $state );
        if ( ! $saved ) {
            metis_walkthrough_error_response( 'metis_walkthrough_progress', 'Unable to save walkthrough progress.', 500, 'walkthrough_save_failed' );
        }

        metis_runtime_send_json_success( [ 'saved' => true, 'progress' => $service->progress() ] );
    }
}

if ( ! function_exists( 'metis_walkthrough_register_ajax_controllers' ) ) {
    function metis_walkthrough_register_ajax_controllers(): void {
        static $registered = false;

        if ( $registered || ( ! function_exists( 'metis_ajax_register_controller' ) && ! class_exists( 'Metis_Ajax_Controller_Registry' ) ) ) {
            return;
        }

        $registered = true;

        if ( function_exists( 'metis_ajax_register_controller' ) ) {
            metis_ajax_register_controller(
                'metis_walkthrough_get',
                [
                    'module' => 'core',
                    'permission' => 'view',
                    'nonce_action' => metis_ajax_nonce_action( 'metis_walkthrough_get' ),
                    'schema' => [
                        'walkthrough' => [ 'type' => 'string', 'required' => true ],
                    ],
                ]
            );
            metis_ajax_register_controller(
                'metis_walkthrough_progress',
                [
                    'module' => 'core',
                    'permission' => 'view',
                    'nonce_action' => metis_ajax_nonce_action( 'metis_walkthrough_progress' ),
                    'schema' => [
                        'walkthrough' => [ 'type' => 'string', 'required' => true ],
                        'step' => [ 'type' => 'numeric', 'required' => false ],
                        'completed' => [ 'type' => 'string', 'required' => false ],
                        'skipped' => [ 'type' => 'string', 'required' => false ],
                    ],
                ]
            );

            return;
        }

        $registry = Metis_Ajax_Controller_Registry::instance();

        $registry->register(
            'metis_walkthrough_get',
            [
                'module' => 'core',
                'permission' => 'view',
                'schema' => [
                    'walkthrough' => [ 'type' => 'string', 'required' => true ],
                ],
            ]
        );
        $registry->register(
            'metis_walkthrough_progress',
            [
                'module' => 'core',
                'permission' => 'view',
                'schema' => [
                    'walkthrough' => [ 'type' => 'string', 'required' => true ],
                    'step' => [ 'type' => 'numeric', 'required' => false ],
                    'completed' => [ 'type' => 'string', 'required' => false ],
                    'skipped' => [ 'type' => 'string', 'required' => false ],
                ],
            ]
        );
    }
}

if ( ! function_exists( 'metis_walkthrough_register_ajax_handlers' ) ) {
    function metis_walkthrough_register_ajax_handlers(): void {
        static $registered = false;

        if ( $registered || ! function_exists( 'metis_ajax_register_handler' ) ) {
            return;
        }

        $registered = true;
        metis_ajax_register_handler( 'metis_walkthrough_get', 'metis_walkthrough_get_response' );
        metis_ajax_register_handler( 'metis_walkthrough_progress', 'metis_walkthrough_progress_response' );
    }
}

if ( function_exists( 'metis_ajax_register_handler' ) ) {
    metis_walkthrough_register_ajax_handlers();
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_walkthrough_register_ajax_controllers();
}
