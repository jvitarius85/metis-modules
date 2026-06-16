<?php
declare(strict_types=1);

namespace Metis\Core\Editor {
    final class BlockRegistry {
        /** @var array<string,array<string,mixed>> */
        private static array $definitions = [];

        public static function boot(): void {}
        public static function register( string $type, array $definition ): void { self::$definitions[ $type ] = $definition; }
        public static function get( string $type ): ?array { return self::$definitions[ $type ] ?? null; }
        public static function all(): array { return self::$definitions; }
        public static function exists( string $type ): bool { return isset( self::$definitions[ $type ] ); }
        public static function validateBlock( array $block ): array { return [ 'valid' => true, 'errors' => [] ]; }
    }
}

namespace Metis\Modules\Website\Services {
    final class EditorContextPolicy {
        public static function normalizeRenderMode( string $mode, string $context ): string { return $mode !== '' ? $mode : 'public'; }
        public static function sanitizeStyleForRenderMode( array $style, string $mode ): array { return $style; }
    }

    final class MenuService {}
    final class PostService {}
}

namespace Metis\Modules\People {
    final class PersonProfileService {}
    final class ReadService {}
}

namespace Metis\Modules\Newsletter {
    final class NewsletterModule {
        public static function ensureSchema(): void {}
    }
}

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        fwrite( STDERR, "This test must be run from the command line.\n" );
        exit( 1 );
    }

    final class Metis_Tables {
        public static function get( string $table ): string {
            return 'metis_' . $table;
        }
    }

    final class MetisFakeNewsletterWebsiteDb {
        /** @var array<int,array{sql:string,args:array<int,int|string|float|null>}> */
        public array $prepareCalls = [];

        /** @var array<int,string> */
        public array $columnCalls = [];

        /** @param array<int,int|string|float|null> $args */
        public function prepare( string $sql, ...$args ): string {
            $this->prepareCalls[] = [
                'sql' => $sql,
                'args' => $args,
            ];

            return $sql . ' /* ' . json_encode( $args ) . ' */';
        }

        /** @return array<int,array<string,mixed>> */
        public function fetchAll( string $sql, array $params = [] ): array {
            if ( str_contains( $sql, 'FROM metis_newsletter_lists' ) && str_contains( $sql, 'WHERE is_active = 1 ORDER BY name ASC' ) ) {
                return [
                    [ 'id' => 2, 'name' => 'Members' ],
                    [ 'id' => 5, 'name' => 'Volunteers' ],
                ];
            }

            if ( str_contains( $sql, 'FROM metis_newsletter_lists' ) && str_contains( $sql, 'WHERE id IN (' ) ) {
                return [
                    [ 'id' => 2, 'name' => 'Members', 'newsletter_list_uid' => 'NL-members', 'list_key' => 'members' ],
                    [ 'id' => 5, 'name' => 'Volunteers', 'newsletter_list_uid' => 'NL-volunteers', 'list_key' => 'volunteers' ],
                ];
            }

            if ( str_contains( $sql, 'FROM metis_newsletter_campaigns c' ) && str_contains( $sql, "WHERE c.status = 'sent'" ) ) {
                return [
                    [
                        'id' => 88,
                        'campaign_code' => 'NC-88',
                        'name' => 'June Update',
                        'subject' => 'June Update',
                        'preheader' => 'What happened this month',
                        'sent_at' => '2026-06-10 15:00:00',
                        'updated_at' => '2026-06-10 15:00:00',
                        'list_names' => 'Members||Volunteers',
                    ],
                ];
            }

            return [];
        }

        /** @return array<int,int> */
        public function column( string $sql ): array {
            $this->columnCalls[] = $sql;

            if ( str_contains( $sql, 'SELECT id FROM metis_newsletter_lists WHERE id IN (' ) ) {
                return [ 2, 5 ];
            }

            return [];
        }
    }

    function metis_db(): MetisFakeNewsletterWebsiteDb {
        static $db = null;
        if ( ! $db instanceof MetisFakeNewsletterWebsiteDb ) {
            $db = new MetisFakeNewsletterWebsiteDb();
        }
        return $db;
    }

    function metis_escape_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function metis_escape_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function metis_escape_url( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function metis_runtime_kses_post( string $value ): string { return $value; }
    function metis_json_encode( mixed $value ): string|false { return json_encode( $value ); }
    function metis_newsletter_public_signup_url(): string { return 'https://example.test/n/signup/'; }
    function metis_newsletter_public_view_url( string $newsletter_ref ): string { return 'https://example.test/n/view/' . rawurlencode( trim( $newsletter_ref ) ) . '/'; }
    function metis_key_clean( string $value ): string {
        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_]+/', '_', $value ) ?? '';
    }
    function metis_text_clean( string $value ): string {
        return trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );
    }
    function metis_number_format( float $value, int $decimals = 0 ): string {
        return number_format( $value, $decimals, '.', ',' );
    }

    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Newsletter/CampaignService.php';
    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Newsletter/WebsiteService.php';
    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Website/BlockRegistry.php';
    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Website/Services/BlockRenderer.php';

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $listOptions = \Metis\Modules\Newsletter\WebsiteService::listOptions();
    $normalizedListIds = \Metis\Modules\Newsletter\WebsiteService::normalizeListIds( '2,5,99,bad,2' );
    $listRows = \Metis\Modules\Newsletter\WebsiteService::listsByIds( [ 2, 5, 99 ] );
    $archiveRows = \Metis\Modules\Newsletter\WebsiteService::publicArchiveCampaigns( [ 2, 5 ], 9 );
    $archivePage = \Metis\Modules\Newsletter\WebsiteService::publicArchiveCampaignPage( [ 2, 5 ], 9, 1 );

    \Metis\Modules\Website\BlockRegistry::boot();
    $signupDefinition = \Metis\Modules\Website\BlockRegistry::get( 'newsletter_signup' );
    $archiveDefinition = \Metis\Modules\Website\BlockRegistry::get( 'newsletter_archive' );

    $signupHtml = \Metis\Modules\Website\Services\BlockRenderer::render(
        [
            'type' => 'newsletter_signup',
            'data' => [
                'list_ids' => [ 2, 5 ],
                'submit_label' => 'Join the list',
                'success_message' => 'Thanks for joining.',
            ],
            'style' => [],
        ],
        []
    );

    $archiveHtml = \Metis\Modules\Website\Services\BlockRenderer::render(
        [
            'type' => 'newsletter_archive',
            'data' => [
                'list_ids' => [ 2, 5 ],
                'limit' => 9,
            ],
            'style' => [],
        ],
        []
    );

    $editorSource = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/editor/simple-editor.js' );
    $websiteAjaxSource = (string) file_get_contents( dirname( __DIR__ ) . '/modules/website/ajax/website.ajax.php' );
    $db = metis_db();

    $assert( count( $listOptions ) === 2, 'Newsletter website service must expose active list options for the website editor.' );
    $assert( $normalizedListIds === [ 2, 5 ], 'Newsletter website service must normalize and validate configured list IDs.' );
    $assert( count( $listRows ) === 2 && ( $listRows[0]['ref'] ?? '' ) === 'NL-members', 'Newsletter website service must return canonical list rows with public refs.' );
    $assert( count( $archiveRows ) === 1 && ( $archiveRows[0]['campaign_code'] ?? '' ) === 'NC-88', 'Newsletter website service must load sent archive campaigns for selected lists.' );
    $assert( count( $archivePage['rows'] ?? [] ) === 1 && empty( $archivePage['has_more'] ), 'Newsletter archive pagination must return the current page rows and has_more state.' );

    $assert( is_array( $signupDefinition ) && ( $signupDefinition['label'] ?? '' ) === 'Newsletter Signup', 'Website block registry must register the newsletter signup block.' );
    $assert( is_array( $archiveDefinition ) && ( $archiveDefinition['label'] ?? '' ) === 'Newsletter Archive', 'Website block registry must register the newsletter archive block.' );

    $assert( str_contains( $signupHtml, 'data-metis-newsletter-signup-form' ), 'Newsletter signup block must render the shared public signup form hook.' );
    $assert( str_contains( $signupHtml, 'Join the list' ), 'Newsletter signup block must render the configured submit label.' );
    $assert( str_contains( $signupHtml, 'name="list_ids"' ), 'Newsletter signup block must post configured newsletter list IDs.' );
    $assert( str_contains( $signupHtml, 'Thanks for joining.' ), 'Newsletter signup block must post the configured success message.' );

    $assert( str_contains( $archiveHtml, 'June Update' ), 'Newsletter archive block must render public newsletter headlines.' );
    $assert( str_contains( $archiveHtml, 'Members, Volunteers' ), 'Newsletter archive block must render associated newsletter list names.' );
    $assert( str_contains( $archiveHtml, 'https://example.test/n/view/NC-88/' ), 'Newsletter archive block must link to the public newsletter view route.' );
    $assert( ! str_contains( $archiveHtml, 'June updates and volunteer notes' ), 'Newsletter archive block must not render preheader or subject excerpts in the compact archive list.' );

    $assert( str_contains( $editorSource, "newsletter_signup: 'Visitor newsletter signup form'" ), 'Simple editor must expose the newsletter signup section type.' );
    $assert( str_contains( $editorSource, "newsletter_archive: 'Public newsletter archive'" ), 'Simple editor must expose the newsletter archive section type.' );
    $assert( str_contains( $editorSource, "'posts_list', 'newsletter_signup', 'newsletter_archive'" ), 'Simple editor block picker must include newsletter blocks in the visible library ordering.' );
    $assert( str_contains( $editorSource, "newsletter_signup: 'newsletter'" ), 'Simple editor block picker must map the newsletter signup icon.' );
    $assert( str_contains( $editorSource, "newsletter_archive: 'newsletter'" ), 'Simple editor block picker must map the newsletter archive icon.' );
    $assert( str_contains( $editorSource, "return ['text', 'form', 'form_tabs', 'donation_form', 'donation_progress', 'campaign_summary', 'testimonials', 'newsletter_signup', 'newsletter_archive', 'button', 'image'];" ), 'Column modules must include newsletter signup and archive block types.' );
    $assert( str_contains( $editorSource, ">Newsletter Signup</option>") && str_contains( $editorSource, ">Newsletter Archive</option>"), 'Column content picker must expose newsletter signup and archive options.' );
    $assert( str_contains( $websiteAjaxSource, "'newsletter_lists' => NewsletterWebsiteService::listOptions()" ), 'Website editor options must expose newsletter list choices through the shared newsletter website service.' );

    $archivePrepareCall = null;
    foreach ( $db->prepareCalls as $call ) {
        if ( str_contains( $call['sql'], 'FROM metis_newsletter_campaigns c' ) ) {
            $archivePrepareCall = $call;
            break;
        }
    }
    $assert( is_array( $archivePrepareCall ), 'Newsletter archive query must be prepared through the shared database layer.' );
    $assert( is_array( $archivePrepareCall ) && ( $archivePrepareCall['args'] ?? [] ) === [ 2, 5, 9, 0 ], 'Newsletter archive query must pass selected list IDs, the configured limit, and the offset to the shared query.' );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "Newsletter website block checks passed.\n" );
}
