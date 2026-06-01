<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) && ! defined( 'METIS_STANDALONE' ) ) {
    exit;
}

require_once __DIR__ . '/Services/EmailService.php';

if ( ! function_exists( 'metis_help_plain_key' ) ) {
    function metis_help_plain_key( mixed $value ): string {
        $value = strtolower( trim( (string) $value ) );
        return preg_replace( '/[^a-z0-9._-]/', '', $value ) ?? '';
    }
}

if ( ! function_exists( 'metis_help_plain_text' ) ) {
    function metis_help_plain_text( mixed $value ): string {
        if ( function_exists( 'metis_text_clean' ) ) {
            return metis_text_clean( $value );
        }

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
        private ?\Metis\Core\HelpSearchStore $search_store = null;
        private bool $search_schema_checked = false;

        /** @var array<string, mixed>|null */
        private ?array $cached_index = null;

        /** @var array<string, mixed>|null */
        private ?array $cached_walkthroughs = null;

        /** @var array<int, array<string, mixed>>|null */
        private ?array $cached_docs = null;

        public function __construct( ?string $docs_path = null ) {
            $this->docs_path         = $docs_path ?? dirname( __DIR__, 3 ) . '/docs';
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
            $walkthrough_enabled = $this->walkthrough_enabled();
            $payload = [
                'enabled' => $this->enabled(),
                'walkthrough_enabled' => $walkthrough_enabled,
                'current_topic' => $current_topic,
                'current_domain' => metis_help_plain_key( $domain ),
                'current_view' => metis_help_plain_key( $view ),
                'docs_base_url' => $this->root_url,
                'docs_base_path' => $this->docs_path,
            ];

            if ( $walkthrough_enabled && function_exists( 'metis_service' ) ) {
                $walkthrough = metis_service( 'walkthroughs' );
                if ( $walkthrough instanceof Metis_Walkthrough_Service ) {
                    $payload['autostart'] = $walkthrough->autostart_candidate( $domain, $view );
                }
            }

            return $payload;
        }

        /**
         * @return array{results: array<int, array<string, mixed>>, total: int, page: int, categories: array<int, array<string, mixed>>}
         */
        public function searchIndex( string $query, string $category = '', int $limit = 10, int $page = 1 ): array {
            $this->ensureSearchSchema();
            $payload = $this->searchStore()->search( $query, $category, $limit, $page );
            $payload['categories'] = $this->searchCategories();
            return $payload;
        }

        /**
         * @param array<string,mixed> $input
         * @return array{sent:int,recipients:array<int,string>}
         */
        public function requestAdminAssistance( array $input ): array {
            $message = metis_help_plain_text( (string) ( $input['message'] ?? '' ) );
            $articleTitle = metis_help_plain_text( (string) ( $input['article_title'] ?? 'Help article' ) );
            $articleUrl = trim( (string) ( $input['article_url'] ?? '' ) );
            $articleSlug = metis_help_plain_key( (string) ( $input['article_slug'] ?? '' ) );
            $route = metis_help_plain_text( (string) ( $input['route'] ?? '' ) );

            if ( strlen( $message ) < 12 ) {
                throw new \RuntimeException( 'Please describe what you need help with.' );
            }

            $recipients = $this->systemAdminRecipients();
            if ( $recipients === [] ) {
                throw new \RuntimeException( 'No system administrator recipients are configured.' );
            }

            $requestor = $this->currentRequestor();
            $subject = '[Metis Help Request] ' . ( $articleTitle !== '' ? $articleTitle : 'Help Article' );
            $html = [];
            $html[] = '<h2>Help Request</h2>';
            $html[] = '<p>A user requested additional help from the Help library.</p>';
            $html[] = '<dl>';
            $html[] = '<dt><strong>Article</strong></dt><dd>' . htmlspecialchars( $articleTitle !== '' ? $articleTitle : 'Unknown article', ENT_QUOTES, 'UTF-8' ) . '</dd>';
            if ( $articleSlug !== '' ) {
                $html[] = '<dt><strong>Article slug</strong></dt><dd>' . htmlspecialchars( $articleSlug, ENT_QUOTES, 'UTF-8' ) . '</dd>';
            }
            if ( $articleUrl !== '' ) {
                $safeUrl = htmlspecialchars( $articleUrl, ENT_QUOTES, 'UTF-8' );
                $html[] = '<dt><strong>Link</strong></dt><dd><a href="' . $safeUrl . '">' . $safeUrl . '</a></dd>';
            }
            if ( $route !== '' ) {
                $html[] = '<dt><strong>Route</strong></dt><dd>' . htmlspecialchars( $route, ENT_QUOTES, 'UTF-8' ) . '</dd>';
            }
            $html[] = '<dt><strong>Requested by</strong></dt><dd>' . htmlspecialchars( $requestor['name'], ENT_QUOTES, 'UTF-8' ) . '</dd>';
            if ( $requestor['email'] !== '' ) {
                $html[] = '<dt><strong>Reply email</strong></dt><dd>' . htmlspecialchars( $requestor['email'], ENT_QUOTES, 'UTF-8' ) . '</dd>';
            }
            $html[] = '</dl>';
            $html[] = '<h3>User message</h3>';
            $html[] = '<p>' . nl2br( htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ) ) . '</p>';

            $sent = 0;
            $recipientEmails = [];
            foreach ( $recipients as $recipient ) {
                $email = strtolower( trim( (string) ( $recipient['email'] ?? '' ) ) );
                if ( $email === '' ) {
                    continue;
                }

                $result = \Metis\Core\Services\EmailService::sendHtml(
                    $email,
                    $subject,
                    implode( "\n", $html ),
                    [
                        'module' => 'help',
                        'from_name' => 'Metis Help',
                        'reply_to' => $requestor['email'],
                        'internal_reference' => strtoupper( 'HELP:' . $articleSlug ),
                    ]
                );

                if ( empty( $result['ok'] ) ) {
                    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
                    if ( $requestor['email'] !== '' && function_exists( 'metis_email_is_valid' ) && \metis_email_is_valid( $requestor['email'] ) ) {
                        $headers[] = 'Reply-To: ' . $requestor['email'];
                    }

                    $fallbackOk = function_exists( 'metis_runtime_mail' )
                        ? \metis_runtime_mail( $email, $subject, implode( "\n", $html ), $headers )
                        : false;

                    if ( $fallbackOk ) {
                        $result = [ 'ok' => true, 'provider' => 'runtime.mail', 'fallback' => 'help_request_fallback' ];
                    }
                }

                if ( ! empty( $result['ok'] ) ) {
                    $sent++;
                    $recipientEmails[] = $email;
                }
            }

            if ( $sent < 1 ) {
                throw new \RuntimeException( 'Unable to send the help request to a system administrator.' );
            }

            return [
                'sent' => $sent,
                'recipients' => array_values( array_unique( $recipientEmails ) ),
            ];
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
                        'learn_more' => '/system/docs/modules/' . metis_help_plain_key( (string) $slug ) . '.md',
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

                $root = defined( 'METIS_PATH' ) ? rtrim( (string) METIS_PATH, '/\\' ) : dirname( __DIR__, 4 );
                $normalized_file = str_replace( DIRECTORY_SEPARATOR, '/', $file );
                $normalized_root = str_replace( DIRECTORY_SEPARATOR, '/', $root );
                if ( str_starts_with( $normalized_file, $normalized_root . '/' ) ) {
                    $relative = substr( $normalized_file, strlen( $normalized_root ) );
                } else {
                    $relative = str_replace( DIRECTORY_SEPARATOR, '/', basename( $file ) );
                }
                $relative = '/' . ltrim( $relative, '/' );
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

        private function ensureSearchSchema(): void {
            if ( $this->search_schema_checked ) {
                return;
            }

            if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
                \metis_runtime_run_once_per_signature(
                    'help_search_schema',
                    [ __FILE__, __DIR__ . '/HelpSearchStore.php' ],
                    function (): void {
                        $this->searchStore()->ensureSchema();
                    }
                );
                $this->search_schema_checked = true;
                return;
            }

            $this->searchStore()->ensureSchema();
            $this->search_schema_checked = true;
        }

        private function searchStore(): \Metis\Core\HelpSearchStore {
            if ( $this->search_store instanceof \Metis\Core\HelpSearchStore ) {
                return $this->search_store;
            }

            $this->search_store = new \Metis\Core\HelpSearchStore( null, $this->docs_path );
            return $this->search_store;
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        private function searchCategories(): array {
            $this->ensureSearchSchema();
            $table = \Metis_Tables::get( 'help_categories' );
            return \metis_db()->fetchAll(
                "SELECT id, name, slug, sort_order
                 FROM {$table}
                 ORDER BY sort_order ASC, name ASC"
            );
        }

        /**
         * @return array<int,array<string,string>>
         */
        private function systemAdminRecipients(): array {
            if ( ! \Metis_Tables::has( 'people' ) || ! \Metis_Tables::has( 'people_roles' ) || ! \Metis_Tables::has( 'people_user_roles' ) ) {
                return [];
            }

            $peopleTable = \Metis_Tables::get( 'people' );
            $rolesTable = \Metis_Tables::get( 'people_roles' );
            $userRolesTable = \Metis_Tables::get( 'people_user_roles' );
            $authUsersTable = \Metis_Tables::has( 'auth_users' ) ? \Metis_Tables::get( 'auth_users' ) : '';
            $authJoin = $authUsersTable !== '' ? "LEFT JOIN {$authUsersTable} au ON au.person_id = p.id" : '';

            $rows = \metis_db()->fetchAll(
                "SELECT DISTINCT
                    COALESCE(NULLIF(TRIM(p.display_name), ''), CONCAT(TRIM(COALESCE(p.first_name, '')), ' ', TRIM(COALESCE(p.last_name, ''))), 'System Administrator') AS display_name,
                    COALESCE(NULLIF(TRIM(p.email), ''), NULLIF(TRIM(p.workspace_email), ''), NULLIF(TRIM(au.user_email), '')) AS email
                 FROM {$peopleTable} p
                 INNER JOIN {$userRolesTable} ur ON ur.person_id = p.id
                 INNER JOIN {$rolesTable} r ON r.id = ur.role_id
                 {$authJoin}
                 WHERE r.role_domain = 'metis'
                   AND r.role_key IN ('administrator', 'developer')
                 ORDER BY display_name ASC"
            ) ?: [];

            $recipients = [];
            foreach ( $rows as $row ) {
                $email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
                if ( $email === '' || ! function_exists( 'metis_email_is_valid' ) || ! \metis_email_is_valid( $email ) ) {
                    continue;
                }

                $recipients[] = [
                    'name' => trim( (string) ( $row['display_name'] ?? 'System Administrator' ) ),
                    'email' => $email,
                ];
            }

            return $recipients;
        }

        /**
         * @return array{name:string,email:string}
         */
        private function currentRequestor(): array {
            $name = 'Metis User';
            $email = '';

            if ( function_exists( 'metis_runtime_current_user' ) ) {
                $user = \metis_runtime_current_user();
                if ( $user instanceof \MetisUser ) {
                    $name = trim( (string) ( $user->display_name ?: $user->user_login ?: $name ) );
                }
            }

            $personId = function_exists( 'metis_auth_current_person_id' ) ? (int) \metis_auth_current_person_id() : 0;
            if ( $personId > 0 && \Metis_Tables::has( 'people' ) ) {
                $peopleTable = \Metis_Tables::get( 'people' );
                $person = \metis_db()->fetchOne(
                    "SELECT display_name, first_name, last_name, email, workspace_email
                     FROM {$peopleTable}
                     WHERE id = %d
                     LIMIT 1",
                    [ $personId ]
                );

                if ( is_array( $person ) ) {
                    $displayName = trim( (string) ( $person['display_name'] ?? '' ) );
                    if ( $displayName !== '' ) {
                        $name = $displayName;
                    }
                    $email = trim( (string) ( $person['email'] ?? $person['workspace_email'] ?? '' ) );
                }
            }

            return [
                'name' => $name,
                'email' => $email,
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

if ( ! function_exists( 'metis_help_error_response' ) ) {
    function metis_help_error_response( string $action, string $message, int $status, string $error_code ): never {
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

if ( ! function_exists( 'metis_help_topic_response' ) ) {
    function metis_help_topic_response(): void {
        $service = metis_help_service();
        if ( ! $service instanceof Metis_Help_Service ) {
            metis_help_error_response( 'metis_help_topic', 'Help service is unavailable.', 500, 'help_service_unavailable' );
        }

        $topic_id = metis_help_normalize_topic_id( metis_request_post()['topic'] ?? '' );
        if ( $topic_id === '' ) {
            metis_help_error_response( 'metis_help_topic', 'Help topic is required.', 400, 'help_topic_required' );
        }

        $topic = $service->topic( $topic_id );
        if ( ! is_array( $topic ) ) {
            metis_help_error_response( 'metis_help_topic', 'Help topic not found.', 404, 'help_topic_not_found' );
        }

        metis_runtime_send_json_success( [ 'topic' => $topic ] );
    }
}

if ( ! function_exists( 'metis_help_index_response' ) ) {
    function metis_help_index_response(): void {
        $service = metis_help_service();
        if ( ! $service instanceof Metis_Help_Service ) {
            metis_help_error_response( 'metis_help_index', 'Help service is unavailable.', 500, 'help_service_unavailable' );
        }

        $domain = metis_help_plain_key( (string) ( metis_request_post()['domain'] ?? '' ) );
        $view   = metis_help_plain_key( (string) ( metis_request_post()['view'] ?? '' ) );

        metis_runtime_send_json_success(
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
            metis_help_error_response( 'metis_help_search', 'Help service is unavailable.', 500, 'help_service_unavailable' );
        }

        try {
            $query = metis_help_plain_text( (string) ( metis_request_post()['query'] ?? '' ) );
            $limit = max( 1, min( 20, (int) ( metis_request_post()['limit'] ?? 12 ) ) );

            metis_runtime_send_json_success( [ 'results' => $service->search( $query, $limit ) ] );
        } catch ( Throwable $e ) {
            if ( class_exists( 'Metis_Logger' ) ) {
                Metis_Logger::warn( 'help.search_failed', [ 'error' => $e->getMessage() ] );
            }
            metis_help_error_response( 'metis_help_search', 'Help search failed. Please try again.', 500, 'help_search_failed' );
        }
    }
}

if ( ! function_exists( 'metis_help_register_ajax_controllers' ) ) {
    function metis_help_register_ajax_controllers(): void {
        static $registered = false;

        if ( $registered || ( ! function_exists( 'metis_ajax_register_controller' ) && ! class_exists( 'Metis_Ajax_Controller_Registry' ) ) ) {
            return;
        }

        $registered = true;

        if ( function_exists( 'metis_ajax_register_controller' ) ) {
            metis_ajax_register_controller(
                'metis_help_topic',
                [
                    'module' => 'core',
                    'permission' => 'view',
                    'nonce_action' => metis_ajax_nonce_action( 'metis_help_topic' ),
                    'schema' => [
                        'topic' => [ 'type' => 'string', 'required' => true ],
                    ],
                ]
            );
            metis_ajax_register_controller(
                'metis_help_index',
                [
                    'module' => 'core',
                    'permission' => 'view',
                    'nonce_action' => metis_ajax_nonce_action( 'metis_help_index' ),
                    'schema' => [
                        'domain' => [ 'type' => 'string', 'required' => false ],
                        'view' => [ 'type' => 'string', 'required' => false ],
                    ],
                ]
            );
            metis_ajax_register_controller(
                'metis_help_search',
                [
                    'module' => 'core',
                    'permission' => 'view',
                    'nonce_action' => metis_ajax_nonce_action( 'metis_help_search' ),
                    'schema' => [
                        'query' => [ 'type' => 'string', 'required' => true ],
                        'limit' => [ 'type' => 'numeric', 'required' => false ],
                    ],
                ]
            );

            return;
        }

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

if ( ! function_exists( 'metis_help_register_ajax_handlers' ) ) {
    function metis_help_register_ajax_handlers(): void {
        static $registered = false;

        if ( $registered || ! function_exists( 'metis_ajax_register_handler' ) ) {
            return;
        }

        $registered = true;
        metis_ajax_register_handler( 'metis_help_topic', 'metis_help_topic_response' );
        metis_ajax_register_handler( 'metis_help_index', 'metis_help_index_response' );
        metis_ajax_register_handler( 'metis_help_search', 'metis_help_search_response' );
    }
}

if ( function_exists( 'metis_ajax_register_handler' ) ) {
    metis_help_register_ajax_handlers();
}

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    metis_help_register_ajax_controllers();
}
