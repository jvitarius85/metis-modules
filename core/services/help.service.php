<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

if ( ! function_exists( 'metis_help_plain_key' ) ) {
    function metis_help_plain_key( mixed $value ): string {
        $value = strtolower( trim( (string) $value ) );
        return preg_replace( '/[^a-z0-9._-]/', '', $value ) ?? '';
    }
}

if ( ! function_exists( 'metis_help_plain_text' ) ) {
    function metis_help_plain_text( mixed $value ): string {
        $value = strip_tags( (string) $value );
        $value = preg_replace( '/\s+/', ' ', $value ) ?? '';
        return trim( $value );
    }
}

if ( ! class_exists( 'Metis_Help_Service' ) ) {
    final class Metis_Help_Service {
        private string $docs_path;
        private string $help_index_path;
        private string $walkthroughs_path;
        private string $root_url;

        /** @var array<string, mixed>|null */
        private ?array $cached_index = null;

        /** @var array<string, mixed>|null */
        private ?array $cached_walkthroughs = null;

        /** @var array<int, array<string, mixed>>|null */
        private ?array $cached_docs = null;

        public function __construct( ?string $docs_path = null ) {
            $this->docs_path         = $docs_path ?? dirname( __DIR__, 2 ) . '/docs';
            $this->help_index_path   = $this->docs_path . '/help-index.json';
            $this->walkthroughs_path = $this->docs_path . '/walkthroughs.json';
            $this->root_url          = defined( 'METIS_URL' ) ? rtrim( METIS_URL, '/' ) : '';
        }

        public function enabled(): bool {
            if ( ! class_exists( 'Core_Settings_Service' ) ) {
                return true;
            }

            return (int) \Core_Settings_Service::get( 'help_enabled', 1 ) === 1;
        }

        public function walkthrough_enabled(): bool {
            if ( ! class_exists( 'Core_Settings_Service' ) ) {
                return true;
            }

            return (int) \Core_Settings_Service::get( 'walkthrough_enabled', 1 ) === 1;
        }

        public function bootstrap_payload( string $domain = '', string $view = '' ): array {
            $current_topic = $this->current_topic_id( $domain, $view );
            $payload = [
                'enabled' => $this->enabled(),
                'walkthrough_enabled' => $this->walkthrough_enabled(),
                'current_topic' => $current_topic,
                'current_domain' => metis_help_plain_key( $domain ),
                'current_view' => metis_help_plain_key( $view ),
                'docs_base_url' => $this->root_url,
                'docs_base_path' => $this->docs_path,
                'shortcut' => 'Shift+H',
            ];

            if ( $this->walkthrough_enabled() && function_exists( 'metis_service' ) ) {
                $walkthrough = metis_service( 'walkthroughs' );
                if ( $walkthrough instanceof Metis_Walkthrough_Service ) {
                    $payload['autostart'] = $walkthrough->autostart_candidate( $domain, $view );
                }
            }

            return $payload;
        }

        /**
         * @return array<string, array<string, mixed>>
         */
        public function index(): array {
            if ( is_array( $this->cached_index ) ) {
                return $this->cached_index;
            }

            $index = $this->read_json_file( $this->help_index_path );
            $index = is_array( $index ) ? $index : [];

            $index = $this->merge_module_topics( $index );
            $index = $this->merge_topic_overrides( $index );

            ksort( $index );
            $this->cached_index = $index;

            return $this->cached_index;
        }

        /**
         * @return array<string, array<string, mixed>>
         */
        public function walkthroughs(): array {
            if ( is_array( $this->cached_walkthroughs ) ) {
                return $this->cached_walkthroughs;
            }

            $walkthroughs = $this->read_json_file( $this->walkthroughs_path );
            $walkthroughs = is_array( $walkthroughs ) ? $walkthroughs : [];

            if ( class_exists( 'Core_Settings_Service' ) ) {
                $custom = \Core_Settings_Service::get( 'help_custom_walkthroughs', [] );
                if ( is_array( $custom ) ) {
                    foreach ( $custom as $id => $definition ) {
                        if ( ! is_string( $id ) || ! is_array( $definition ) ) {
                            continue;
                        }

                        $walkthroughs[ metis_help_plain_key( $id ) ] = $this->normalize_walkthrough(
                            metis_help_plain_key( $id ),
                            $definition
                        );
                    }
                }
            }

            ksort( $walkthroughs );
            $this->cached_walkthroughs = $walkthroughs;

            return $this->cached_walkthroughs;
        }

        public function topic( string $topic_id ): ?array {
            $topic_id = metis_help_plain_key( str_replace( '.', '_', $topic_id ) );
            foreach ( $this->index() as $id => $topic ) {
                if ( metis_help_plain_key( str_replace( '.', '_', (string) $id ) ) === $topic_id ) {
                    return $this->enrich_topic( (string) $id, $topic );
                }
            }

            return null;
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function search( string $query, int $limit = 12 ): array {
            $query = metis_help_plain_text( $query );
            if ( $query === '' ) {
                return [];
            }

            $needle  = strtolower( $query );
            $results = [];

            foreach ( $this->index() as $id => $topic ) {
                $haystack = strtolower(
                    implode(
                        "\n",
                        array_filter(
                            [
                                (string) $id,
                                (string) ( $topic['title'] ?? '' ),
                                (string) ( $topic['description'] ?? '' ),
                                implode( ' ', array_map( 'strval', (array) ( $topic['keywords'] ?? [] ) ) ),
                            ]
                        )
                    )
                );

                if ( ! str_contains( $haystack, $needle ) ) {
                    continue;
                }

                $results[] = [
                    'type' => 'topic',
                    'id' => (string) $id,
                    'title' => (string) ( $topic['title'] ?? $id ),
                    'description' => (string) ( $topic['description'] ?? '' ),
                    'learn_more' => (string) ( $topic['learn_more'] ?? '' ),
                ];
            }

            foreach ( $this->walkthroughs() as $id => $walkthrough ) {
                $haystack = strtolower(
                    implode(
                        "\n",
                        array_filter(
                            [
                                (string) $id,
                                (string) ( $walkthrough['title'] ?? '' ),
                                (string) ( $walkthrough['module'] ?? '' ),
                            ]
                        )
                    )
                );

                if ( ! str_contains( $haystack, $needle ) ) {
                    continue;
                }

                $results[] = [
                    'type' => 'walkthrough',
                    'id' => (string) $id,
                    'title' => (string) ( $walkthrough['title'] ?? $id ),
                    'description' => (string) ( $walkthrough['description'] ?? 'Interactive walkthrough' ),
                    'module' => (string) ( $walkthrough['module'] ?? '' ),
                ];
            }

            foreach ( $this->docs_search_index() as $doc ) {
                $haystack = strtolower(
                    implode(
                        "\n",
                        array_filter(
                            [
                                (string) ( $doc['title'] ?? '' ),
                                (string) ( $doc['path'] ?? '' ),
                                (string) ( $doc['content'] ?? '' ),
                            ]
                        )
                    )
                );

                if ( ! str_contains( $haystack, $needle ) ) {
                    continue;
                }

                $results[] = [
                    'type' => 'doc',
                    'id' => (string) ( $doc['path'] ?? '' ),
                    'title' => (string) ( $doc['title'] ?? basename( (string) ( $doc['path'] ?? 'Document' ) ) ),
                    'description' => (string) ( $doc['excerpt'] ?? '' ),
                    'learn_more' => (string) ( $doc['path'] ?? '' ),
                ];
            }

            usort(
                $results,
                static function ( array $left, array $right ) use ( $needle ): int {
                    $left_title  = strtolower( (string) ( $left['title'] ?? '' ) );
                    $right_title = strtolower( (string) ( $right['title'] ?? '' ) );
                    $left_score  = str_starts_with( $left_title, $needle ) ? 0 : 1;
                    $right_score = str_starts_with( $right_title, $needle ) ? 0 : 1;

                    if ( $left_score !== $right_score ) {
                        return $left_score <=> $right_score;
                    }

                    return strcmp( $left_title, $right_title );
                }
            );

            return array_slice( $results, 0, max( 1, $limit ) );
        }

        public function current_topic_id( string $domain, string $view ): string {
            $domain = metis_help_plain_key( $domain );
            $view   = metis_help_plain_key( $view );

            if ( $domain === '' ) {
                return 'portal.dashboard';
            }

            return $domain . '.' . ( $view !== '' ? $view : 'dashboard' );
        }

        public function doc_url( string $path ): string {
            $path = '/' . ltrim( $path, '/' );
            if ( $this->root_url === '' ) {
                return $path;
            }

            return $this->root_url . $path;
        }

        private function enrich_topic( string $id, array $topic ): array {
            $topic['id']            = $id;
            $topic['learn_more']    = (string) ( $topic['learn_more'] ?? '' );
            $topic['learn_more_url'] = $topic['learn_more'] !== '' ? $this->doc_url( $topic['learn_more'] ) : '';
            $topic['steps']         = array_values( array_map( 'strval', (array) ( $topic['steps'] ?? [] ) ) );
            $topic['walkthroughs']  = array_values( array_map( 'strval', (array) ( $topic['walkthroughs'] ?? [] ) ) );

            return $topic;
        }

        /**
         * @param array<string, array<string, mixed>> $index
         * @return array<string, array<string, mixed>>
         */
        private function merge_module_topics( array $index ): array {
            if ( ! function_exists( 'metis_get_modules' ) ) {
                return $index;
            }

            foreach ( metis_get_modules() as $slug => $module ) {
                $config = is_array( $module['config'] ?? null ) ? $module['config'] : [];
                $module_label = (string) ( $config['label'] ?? ucfirst( (string) $slug ) );
                $description  = (string) ( $config['description'] ?? '' );
                $help_topics  = array_values( array_map( 'strval', (array) ( $config['help_topics'] ?? [] ) ) );
                $views        = is_array( $config['views'] ?? null ) ? $config['views'] : [];

                if ( $help_topics === [] ) {
                    foreach ( array_keys( $views ) as $view ) {
                        $help_topics[] = metis_help_plain_key( (string) $slug ) . '.' . metis_help_plain_key( (string) $view );
                    }
                }

                foreach ( $help_topics as $topic_id ) {
                    if ( isset( $index[ $topic_id ] ) ) {
                        continue;
                    }

                    $topic_parts = explode( '.', $topic_id, 2 );
                    $view = metis_help_plain_key( (string) ( $topic_parts[1] ?? 'dashboard' ) );

                    $index[ $topic_id ] = [
                        'title' => $module_label . ' ' . ucwords( str_replace( '_', ' ', $view ) ),
                        'description' => $description !== '' ? $description : 'Help for the ' . $module_label . ' ' . $view . ' view.',
                        'learn_more' => '/docs/modules/' . metis_help_plain_key( (string) $slug ) . '.md',
                        'keywords' => [ $module_label, $slug, $view ],
                        'steps' => [
                            'Use the navigation menu to open the module.',
                            'Review the active page controls before making updates.',
                            'Use the full documentation link for workflow and data details.',
                        ],
                    ];
                }
            }

            return $index;
        }

        /**
         * @param array<string, array<string, mixed>> $index
         * @return array<string, array<string, mixed>>
         */
        private function merge_topic_overrides( array $index ): array {
            if ( ! class_exists( 'Core_Settings_Service' ) ) {
                return $index;
            }

            $overrides = \Core_Settings_Service::get( 'help_topic_overrides', [] );
            if ( is_array( $overrides ) ) {
                foreach ( $overrides as $topic_id => $override ) {
                    if ( ! is_string( $topic_id ) || ! is_array( $override ) ) {
                        continue;
                    }

                    $topic_id = trim( $topic_id );
                    if ( $topic_id === '' ) {
                        continue;
                    }

                    $index[ $topic_id ] = array_merge( $index[ $topic_id ] ?? [], $override );
                }
            }

            $custom = \Core_Settings_Service::get( 'help_custom_topics', [] );
            if ( is_array( $custom ) ) {
                foreach ( $custom as $topic_id => $topic ) {
                    if ( ! is_string( $topic_id ) || ! is_array( $topic ) ) {
                        continue;
                    }

                    $index[ trim( $topic_id ) ] = $topic;
                }
            }

            return $index;
        }

        /**
         * @return array<int, array<string, string>>
         */
        private function docs_search_index(): array {
            if ( is_array( $this->cached_docs ) ) {
                return $this->cached_docs;
            }

            $docs = [];
            $files = glob( $this->docs_path . '/**/*.md' );
            if ( $files === false || $files === [] ) {
                $files = [];
                if ( is_dir( $this->docs_path ) ) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator( $this->docs_path, FilesystemIterator::SKIP_DOTS )
                    );
                    foreach ( $iterator as $item ) {
                        if ( strtolower( $item->getExtension() ) !== 'md' ) {
                            continue;
                        }
                        $files[] = $item->getPathname();
                    }
                }
            }

            foreach ( $files as $file ) {
                $content = @file_get_contents( $file );
                if ( ! is_string( $content ) || $content === '' ) {
                    continue;
                }

                $relative = str_replace( dirname( __DIR__, 2 ), '', $file );
                $relative = '/' . ltrim( str_replace( DIRECTORY_SEPARATOR, '/', $relative ), '/' );
                $lines = preg_split( '/\R/', $content ) ?: [];
                $title = basename( $file );
                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( str_starts_with( $line, '# ' ) ) {
                        $title = trim( substr( $line, 2 ) );
                        break;
                    }
                }

                $plain = trim( preg_replace( '/\s+/', ' ', strip_tags( $content ) ) ?? '' );

                $docs[] = [
                    'path' => $relative,
                    'title' => $title,
                    'content' => strtolower( $plain ),
                    'excerpt' => function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, 180 ) : substr( $plain, 0, 180 ),
                ];
            }

            $this->cached_docs = $docs;

            return $this->cached_docs;
        }

        /**
         * @return array<string, mixed>|null
         */
        private function read_json_file( string $path ): ?array {
            if ( ! is_file( $path ) ) {
                return null;
            }

            $raw = @file_get_contents( $path );
            if ( ! is_string( $raw ) || $raw === '' ) {
                return null;
            }

            $decoded = json_decode( $raw, true );
            return is_array( $decoded ) ? $decoded : null;
        }

        /**
         * @param array<string, mixed> $definition
         * @return array<string, mixed>
         */
        private function normalize_walkthrough( string $id, array $definition ): array {
            $steps = [];
            foreach ( (array) ( $definition['steps'] ?? [] ) as $step ) {
                if ( ! is_array( $step ) ) {
                    continue;
                }

                $steps[] = [
                    'target' => (string) ( $step['target'] ?? '' ),
                    'message' => (string) ( $step['message'] ?? '' ),
                    'advance' => (string) ( $step['advance'] ?? 'click' ),
                ];
            }

            return [
                'id' => $id,
                'title' => (string) ( $definition['title'] ?? ucwords( str_replace( '_', ' ', $id ) ) ),
                'description' => (string) ( $definition['description'] ?? 'Interactive walkthrough' ),
                'module' => metis_help_plain_key( (string) ( $definition['module'] ?? '' ) ),
                'topic' => (string) ( $definition['topic'] ?? '' ),
                'trigger' => (string) ( $definition['trigger'] ?? 'manual' ),
                'steps' => $steps,
            ];
        }
    }
}

if ( ! function_exists( 'metis_help_service' ) ) {
    function metis_help_service(): ?Metis_Help_Service {
        if ( ! function_exists( 'metis_service' ) ) {
            return null;
        }

        $service = metis_service( 'help' );
        return $service instanceof Metis_Help_Service ? $service : null;
    }
}

if ( ! function_exists( 'metis_help_normalize_topic_id' ) ) {
    function metis_help_normalize_topic_id( mixed $value ): string {
        $value = trim( (string) $value );
        $value = str_replace( [ '..', ' ' ], [ '.', '_' ], $value );
        return preg_replace( '/[^a-z0-9._-]/', '', strtolower( $value ) ) ?? '';
    }
}

if ( ! function_exists( 'metis_help_topic_response' ) ) {
    function metis_help_topic_response(): void {
        $service = metis_help_service();
        if ( ! $service instanceof Metis_Help_Service ) {
            metis_send_json_error( [ 'message' => 'Help service is unavailable.' ], 500 );
        }

        $topic_id = metis_help_normalize_topic_id( $_POST['topic'] ?? '' );
        if ( $topic_id === '' ) {
            metis_send_json_error( [ 'message' => 'Help topic is required.' ], 400 );
        }

        $topic = $service->topic( $topic_id );
        if ( ! is_array( $topic ) ) {
            metis_send_json_error( [ 'message' => 'Help topic not found.' ], 404 );
        }

        metis_send_json_success( [ 'topic' => $topic ] );
    }
}

if ( ! function_exists( 'metis_help_index_response' ) ) {
    function metis_help_index_response(): void {
        $service = metis_help_service();
        if ( ! $service instanceof Metis_Help_Service ) {
            metis_send_json_error( [ 'message' => 'Help service is unavailable.' ], 500 );
        }

        $domain = metis_help_plain_key( (string) ( $_POST['domain'] ?? '' ) );
        $view   = metis_help_plain_key( (string) ( $_POST['view'] ?? '' ) );

        metis_send_json_success(
            [
                'topics' => $service->index(),
                'bootstrap' => $service->bootstrap_payload( $domain, $view ),
            ]
        );
    }
}

if ( ! function_exists( 'metis_help_search_response' ) ) {
    function metis_help_search_response(): void {
        $service = metis_help_service();
        if ( ! $service instanceof Metis_Help_Service ) {
            metis_send_json_error( [ 'message' => 'Help service is unavailable.' ], 500 );
        }

        try {
            $query = metis_help_plain_text( (string) ( $_POST['query'] ?? '' ) );
            $limit = max( 1, min( 20, (int) ( $_POST['limit'] ?? 12 ) ) );

            metis_send_json_success( [ 'results' => $service->search( $query, $limit ) ] );
        } catch ( Throwable $e ) {
            metis_send_json_error( [ 'message' => 'Help search failed: ' . $e->getMessage() ], 500 );
        }
    }
}

if ( ! function_exists( 'metis_help_register_ajax_controllers' ) ) {
    function metis_help_register_ajax_controllers(): void {
        static $registered = false;

        if ( $registered || ! class_exists( 'Metis_Ajax_Controller_Registry' ) ) {
            return;
        }

        $registered = true;
        $registry = Metis_Ajax_Controller_Registry::instance();

        $registry->register(
            'metis_help_topic',
            [
                'module' => 'core',
                'permission' => 'view',
                'schema' => [
                    'topic' => [ 'type' => 'string', 'required' => true ],
                ],
            ]
        );
        $registry->register(
            'metis_help_index',
            [
                'module' => 'core',
                'permission' => 'view',
                'schema' => [
                    'domain' => [ 'type' => 'string', 'required' => false ],
                    'view' => [ 'type' => 'string', 'required' => false ],
                ],
            ]
        );
        $registry->register(
            'metis_help_search',
            [
                'module' => 'core',
                'permission' => 'view',
                'schema' => [
                    'query' => [ 'type' => 'string', 'required' => true ],
                    'limit' => [ 'type' => 'numeric', 'required' => false ],
                ],
            ]
        );
    }
}

if ( function_exists( 'metis_add_action' ) ) {
    metis_add_action( 'wp_ajax_metis_help_topic', 'metis_help_topic_response' );
    metis_add_action( 'wp_ajax_metis_help_index', 'metis_help_index_response' );
    metis_add_action( 'wp_ajax_metis_help_search', 'metis_help_search_response' );
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_help_register_ajax_controllers();
}
