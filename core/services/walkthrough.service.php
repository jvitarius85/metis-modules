<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'METIS_STANDALONE' ) ) {
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

if ( ! function_exists( 'metis_walkthrough_get_response' ) ) {
    function metis_walkthrough_get_response(): void {
        $service = metis_walkthrough_service();
        if ( ! $service instanceof Metis_Walkthrough_Service ) {
            metis_send_json_error( [ 'message' => 'Walkthrough service is unavailable.' ], 500 );
        }

        $id = metis_help_plain_key( (string) ( $_POST['walkthrough'] ?? '' ) );
        if ( $id === '' ) {
            metis_send_json_error( [ 'message' => 'Walkthrough id is required.' ], 400 );
        }

        $walkthrough = $service->get( $id );
        if ( ! is_array( $walkthrough ) ) {
            metis_send_json_error( [ 'message' => 'Walkthrough not found.' ], 404 );
        }

        metis_send_json_success(
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
            metis_send_json_error( [ 'message' => 'Walkthrough service is unavailable.' ], 500 );
        }

        $id = metis_help_plain_key( (string) ( $_POST['walkthrough'] ?? '' ) );
        if ( $id === '' ) {
            metis_send_json_error( [ 'message' => 'Walkthrough id is required.' ], 400 );
        }

        $state = [
            'step' => max( 0, (int) ( $_POST['step'] ?? 0 ) ),
            'completed' => ! empty( $_POST['completed'] ),
            'skipped' => ! empty( $_POST['skipped'] ),
        ];

        $saved = $service->save_progress( $id, $state );
        if ( ! $saved ) {
            metis_send_json_error( [ 'message' => 'Unable to save walkthrough progress.' ], 500 );
        }

        metis_send_json_success( [ 'saved' => true, 'progress' => $service->progress() ] );
    }
}

if ( ! function_exists( 'metis_walkthrough_register_ajax_controllers' ) ) {
    function metis_walkthrough_register_ajax_controllers(): void {
        static $registered = false;

        if ( $registered || ! class_exists( 'Metis_Ajax_Controller_Registry' ) ) {
            return;
        }

        $registered = true;
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

if ( function_exists( 'metis_add_action' ) ) {
    metis_add_action( 'wp_ajax_metis_walkthrough_get', 'metis_walkthrough_get_response' );
    metis_add_action( 'wp_ajax_metis_walkthrough_progress', 'metis_walkthrough_progress_response' );
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_walkthrough_register_ajax_controllers();
}
