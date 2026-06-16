<?php
declare(strict_types=1);

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

    final class MetisFakeNewsletterImportDb {
        /** @var array<int,array<string,mixed>> */
        public array $lists = [];
        /** @var array<int,array<string,mixed>> */
        public array $campaigns = [];
        /** @var array<int,array<string,mixed>> */
        public array $campaignLists = [];
        public int $lastInsertIdValue = 0;

        public function __construct() {
            $this->lists = [
                1 => [
                    'id' => 1,
                    'name' => 'Imported Newsletter Archive',
                    'description' => 'Archived newsletters imported from WordPress.',
                    'newsletter_list_uid' => 'A1B2C3D4E5F60708',
                    'list_key' => 'WP_NEWSLETTER_ARCHIVE',
                    'is_active' => 1,
                    'updated_at' => '2026-06-15 18:00:00',
                ],
            ];
        }

        public function fetchOne( string $sql, array $params = [] ): ?array {
            if ( str_contains( $sql, 'FROM metis_newsletter_lists WHERE newsletter_list_uid = %s OR list_key = %s' ) ) {
                foreach ( $this->lists as $row ) {
                    if ( (string) ( $row['newsletter_list_uid'] ?? '' ) === (string) ( $params[0] ?? '' ) || (string) ( $row['list_key'] ?? '' ) === (string) ( $params[1] ?? '' ) ) {
                        return [ 'id' => (int) $row['id'] ];
                    }
                }
                return null;
            }

            if ( str_contains( $sql, 'FROM metis_newsletter_lists WHERE name = %s LIMIT 1' ) ) {
                foreach ( $this->lists as $row ) {
                    if ( (string) ( $row['name'] ?? '' ) === (string) ( $params[0] ?? '' ) ) {
                        return [ 'id' => (int) $row['id'] ];
                    }
                }
                return null;
            }

            if ( str_contains( $sql, 'FROM metis_newsletter_campaigns WHERE newsletter_campaign_uid = %s LIMIT 1' ) ) {
                foreach ( $this->campaigns as $row ) {
                    if ( strtoupper( (string) ( $row['newsletter_campaign_uid'] ?? '' ) ) === strtoupper( (string) ( $params[0] ?? '' ) ) ) {
                        return [ 'id' => (int) $row['id'] ];
                    }
                }
                return null;
            }

            if ( str_contains( $sql, 'FROM metis_newsletter_campaigns' ) && str_contains( $sql, 'LEFT JOIN metis_newsletter_templates' ) ) {
                $id = (int) ( $params[0] ?? 0 );
                if ( isset( $this->campaigns[ $id ] ) ) {
                    return $this->campaigns[ $id ];
                }
                return null;
            }

            return null;
        }

        public function scalar( string $sql, array $params = [] ): int|string|float|null {
            return null;
        }

        public function prepare( string $sql, ...$args ): string {
            return $sql . ' /* ' . json_encode( $args ) . ' */';
        }

        /** @return array<int,int> */
        public function column( string $sql ): array {
            if ( str_contains( $sql, 'SELECT id FROM metis_newsletter_lists WHERE id IN (' ) ) {
                return [ 1 ];
            }

            if ( str_contains( $sql, 'SELECT list_id FROM metis_newsletter_campaign_lists WHERE campaign_id =' ) ) {
                preg_match( '/campaign_id = (\d+)/', $sql, $matches );
                $campaign_id = isset( $matches[1] ) ? (int) $matches[1] : 0;
                $ids = [];
                foreach ( $this->campaignLists as $row ) {
                    if ( (int) ( $row['campaign_id'] ?? 0 ) === $campaign_id ) {
                        $ids[] = (int) ( $row['list_id'] ?? 0 );
                    }
                }
                return $ids;
            }

            return [];
        }

        public function insert( string $table, array $payload, array $formats = [] ): bool {
            $this->lastInsertIdValue++;
            $payload['id'] = $this->lastInsertIdValue;
            if ( str_contains( $table, 'newsletter_campaigns' ) ) {
                $this->campaigns[ $this->lastInsertIdValue ] = $payload;
            } elseif ( str_contains( $table, 'newsletter_lists' ) ) {
                $this->lists[ $this->lastInsertIdValue ] = $payload;
            } elseif ( str_contains( $table, 'newsletter_campaign_lists' ) ) {
                $this->campaignLists[] = $payload;
            }

            return true;
        }

        public function update( string $table, array $payload, array $where, array $formats = [], array $where_formats = [] ): bool {
            return true;
        }

        public function delete( string $table, array $where, array $where_formats = [] ): bool {
            if ( str_contains( $table, 'newsletter_campaign_lists' ) ) {
                $campaign_id = (int) ( $where['campaign_id'] ?? 0 );
                $this->campaignLists = array_values(
                    array_filter(
                        $this->campaignLists,
                        static fn ( array $row ): bool => (int) ( $row['campaign_id'] ?? 0 ) !== $campaign_id
                    )
                );
            }

            return true;
        }

        public function lastInsertId(): int {
            return $this->lastInsertIdValue;
        }
    }

    final class MetisFakeEntityIdService {
        public function assignForInsert( string $entity_type, array $payload, bool $sync_legacy_columns = true ): array {
            if ( $entity_type === 'newsletter_campaign' && empty( $payload['newsletter_campaign_uid'] ) ) {
                $payload['newsletter_campaign_uid'] = 'NCUID_TEST';
            }
            if ( $entity_type === 'newsletter_campaign' && empty( $payload['campaign_code'] ) ) {
                $payload['campaign_code'] = 'NC_TEST_' . substr( sha1( json_encode( $payload ) ), 0, 8 );
            }
            if ( $entity_type === 'newsletter_list' && empty( $payload['newsletter_list_uid'] ) ) {
                $payload['newsletter_list_uid'] = 'NLUID_TEST';
            }
            if ( $entity_type === 'newsletter_list' && empty( $payload['list_key'] ) ) {
                $payload['list_key'] = 'NL_TEST';
            }
            return $payload;
        }

        public function register( string $entity_type, int $entity_id, ?string $entity_uid = null ): bool {
            return true;
        }
    }

    function metis_db(): MetisFakeNewsletterImportDb {
        static $db = null;
        if ( ! $db instanceof MetisFakeNewsletterImportDb ) {
            $db = new MetisFakeNewsletterImportDb();
        }
        return $db;
    }

    function metis_entity_id_service(): MetisFakeEntityIdService {
        static $service = null;
        if ( ! $service instanceof MetisFakeEntityIdService ) {
            $service = new MetisFakeEntityIdService();
        }
        return $service;
    }

    function metis_current_user_id(): int { return 7; }
    function metis_current_time( string $format ): string { return '2026-06-15 18:00:00'; }
    function metis_generate_code( string $prefix, string $table, string $column ): string { return $prefix . '_TEST'; }
    function metis_json_encode( mixed $value ): string|false { return json_encode( $value ); }

    require_once dirname( __DIR__ ) . '/modules/import/parsers/WordPressNewsletterArchiveParser.php';
    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Newsletter/CampaignService.php';
    require_once dirname( __DIR__ ) . '/modules/newsletter/services/import.php';

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $fixture = [
        'format' => 'metis.wordpress.newsletter_archive.v1',
        'source' => [
            'site_title' => 'Mobilize Waco',
            'site_url' => 'https://example.test',
            'generator' => 'The Newsletter Plugin',
        ],
        'default_list' => [
            'ref' => 'wp_newsletter_archive',
            'name' => 'Imported Newsletter Archive',
            'description' => 'Archived newsletters imported from WordPress.',
        ],
        'newsletters' => [
            [
                'source_id' => 11,
                'uid' => 'WPARCHIVE00000011',
                'title' => 'June Action Team Meeting',
                'subject' => 'June Action Team Meeting',
                'html_body' => '<p>June updates</p>',
                'text_body' => 'June updates',
                'sent_at' => '2026-06-10 10:00:00',
                'updated_at' => '2026-06-10 10:05:00',
                'list_refs' => [ 'wp_newsletter_archive' ],
                'list_names' => [ 'Imported Newsletter Archive' ],
            ],
        ],
    ];

    $tmp = tempnam( sys_get_temp_dir(), 'metis-newsletter-import-' );
    file_put_contents( $tmp, json_encode( $fixture ) );

    $parsed = \Metis\Modules\Import\Parsers\WordPressNewsletterArchiveParser::parse( $tmp );
    unlink( $tmp );

    $assert( ! empty( $parsed['success'] ), 'WordPress newsletter parser must accept the dedicated archive export format.' );
    $assert( (int) ( $parsed['stats']['newsletters'] ?? 0 ) === 1, 'WordPress newsletter parser must count archived newsletters.' );
    $assert( (string) ( $parsed['default_list']['name'] ?? '' ) === 'Imported Newsletter Archive', 'WordPress newsletter parser must preserve the default archive list.' );

    $result = metis_newsletter_import_wordpress_archive(
        $parsed,
        [ 'selected_newsletter_ids' => [ 11 ] ]
    );
    $db = metis_db();

    $assert( ! empty( $result['ok'] ), 'WordPress newsletter archive import must return success.' );
    $assert( (int) ( $result['results']['newsletters'] ?? 0 ) === 1, 'WordPress newsletter archive import must create sent campaigns for selected newsletters.' );
    $assert( count( $db->campaigns ) === 1, 'WordPress newsletter archive import must persist a campaign row.' );
    $campaign = array_values( $db->campaigns )[0] ?? [];
    $assert( (string) ( $campaign['status'] ?? '' ) === 'sent', 'Imported newsletter campaigns must be marked sent so archive blocks can render them.' );
    $assert( (string) ( $campaign['newsletter_campaign_uid'] ?? '' ) === 'WPARCHIVE00000011', 'Imported newsletter campaigns must preserve the deterministic source UID.' );
    $assert( count( $db->campaignLists ) === 1 && (int) ( $db->campaignLists[0]['list_id'] ?? 0 ) === 1, 'Imported newsletter campaigns must be attached to a real newsletter list.' );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "WordPress newsletter archive import checks passed.\n" );
}
