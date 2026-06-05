<?php
declare(strict_types=1);

namespace Metis\Core\Cache {
    final class CacheService {
        /** @var array<string,mixed> */
        private static array $store = [];

        public static function remember( string $key, int $ttl, callable $resolver ): mixed {
            if ( array_key_exists( $key, self::$store ) ) {
                return self::$store[ $key ];
            }
            self::$store[ $key ] = $resolver();
            return self::$store[ $key ];
        }

        public static function clearByPrefix( string $prefix ): void {
            foreach ( array_keys( self::$store ) as $key ) {
                if ( str_starts_with( $key, $prefix ) ) {
                    unset( self::$store[ $key ] );
                }
            }
        }
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

    final class MetisFakeTestimoniesDb {
        public array $insertCalls = [];
        public array $updateCalls = [];
        public array $deleteCalls = [];
        public int $lastInsert = 99;

        public function get_charset_collate(): string {
            return 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }

        public function fetchAll( string $sql, array $params = [] ): array {
            if ( str_contains( $sql, 'FROM metis_testimony_categories' ) && str_contains( $sql, 'ORDER BY sort_order ASC, name ASC' ) ) {
                return [
                    [ 'id' => 2, 'name' => 'Healthcare', 'slug' => 'healthcare' ],
                    [ 'id' => 3, 'name' => 'Advocacy', 'slug' => 'advocacy' ],
                ];
            }

            if ( str_contains( $sql, 'FROM metis_testimonies t' ) && str_contains( $sql, "t.status = 'published'" ) ) {
                return [
                    [
                        'id' => 7,
                        'testimony_code' => 'TST-ABC',
                        'speaker_name' => 'Morgan Reese',
                        'speaker_title' => 'Director',
                        'speaker_company' => 'Access Co.',
                        'quote_text' => 'Metis helped us move faster.',
                        'is_featured' => 1,
                        'category_names' => 'Healthcare||Advocacy',
                    ],
                ];
            }

            if ( str_contains( $sql, 'FROM metis_testimonies t' ) && str_contains( $sql, 'GROUP_CONCAT(DISTINCT c.id') ) {
                return [
                    [
                        'id' => 11,
                        'testimony_code' => 'TST-ONE',
                        'speaker_name' => 'Ada Lovelace',
                        'speaker_title' => 'Board Chair',
                        'speaker_company' => 'Mobility Now',
                        'quote_text' => 'A strong quote.',
                        'source_notes' => 'Collected after event.',
                        'status' => 'published',
                        'is_featured' => 1,
                        'sort_order' => 3,
                        'updated_at' => '2026-06-05 10:00:00',
                        'category_ids' => '2,3',
                        'category_names' => 'Healthcare||Advocacy',
                    ],
                ];
            }

            if ( str_contains( $sql, 'COUNT(m.id) AS testimony_count' ) ) {
                return [
                    [ 'id' => 2, 'category_code' => 'TSC-1', 'name' => 'Healthcare', 'slug' => 'healthcare', 'is_active' => 1, 'sort_order' => 1, 'testimony_count' => 4 ],
                ];
            }

            return [];
        }

        public function fetchOne( string $sql, array $params = [] ): ?array {
            return null;
        }

        public function scalar( string $sql, array $params = [] ): int|string|float|null {
            if ( str_contains( $sql, 'SELECT COUNT(*) FROM metis_testimony_category_map' ) ) {
                return 0;
            }
            return null;
        }

        public function insert( string $table, array $payload, array $formats = [] ): bool {
            $this->insertCalls[] = [ $table, $payload, $formats ];
            $this->lastInsert++;
            return true;
        }

        public function update( string $table, array $payload, array $where, array $formats = [], array $whereFormats = [] ): bool {
            $this->updateCalls[] = [ $table, $payload, $where, $formats, $whereFormats ];
            return true;
        }

        public function delete( string $table, array $where, array $whereFormats = [] ): bool {
            $this->deleteCalls[] = [ $table, $where, $whereFormats ];
            return true;
        }

        public function lastInsertId(): int {
            return $this->lastInsert;
        }
    }

    function metis_db(): MetisFakeTestimoniesDb {
        static $db = null;
        if ( ! $db instanceof MetisFakeTestimoniesDb ) {
            $db = new MetisFakeTestimoniesDb();
        }
        return $db;
    }

    function metis_db_delta( string $sql ): void {}
    function metis_slug_clean( string $value ): string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9]+/', '-', $value ) ?? '';
        return trim( $value, '-' );
    }

    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Testimonies/SchemaManager.php';
    require_once dirname( __DIR__ ) . '/src/Metis/Modules/Testimonies/Repository.php';

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $options = \Metis\Modules\Testimonies\Repository::categoryOptions( true );
    $publicRows = \Metis\Modules\Testimonies\Repository::publicTestimonials( [ 'category_ids' => [ 2 ], 'limit' => 3 ] );
    $snapshot = \Metis\Modules\Testimonies\Repository::listSnapshot();
    $categorySave = \Metis\Modules\Testimonies\Repository::saveCategory(
        [ 'name' => 'Policy', 'slug' => 'policy', 'sort_order' => 5, 'is_active' => 1 ],
        41
    );
    $testimonySave = \Metis\Modules\Testimonies\Repository::saveTestimony(
        [
            'speaker_name' => 'Jordan Lee',
            'quote_text' => 'A practical win.',
            'status' => 'published',
            'category_ids' => [ 2, 3 ],
        ],
        41
    );

    $db = metis_db();

    $assert( count( $options ) === 2, 'Testimonies category options should expose active category choices.' );
    $assert( ( $publicRows[0]['speaker_name'] ?? '' ) === 'Morgan Reese', 'Testimonies public query should return published testimony rows.' );
    $assert( implode( ',', $publicRows[0]['categories'] ?? [] ) === 'Healthcare,Advocacy', 'Testimonies public query should preserve category labels.' );
    $assert( count( $snapshot['testimonies'] ?? [] ) === 1, 'Testimonies snapshot should return admin testimony rows.' );
    $assert( count( $snapshot['categories'] ?? [] ) === 1, 'Testimonies snapshot should return category usage rows.' );
    $assert( ! empty( $categorySave['ok'] ), 'Testimonies category save should succeed.' );
    $assert( ! empty( $testimonySave['ok'] ), 'Testimonies save should succeed.' );
    $assert( count( $db->insertCalls ) >= 4, 'Testimonies save should insert the category/testimony rows plus category mappings.' );
    $assert( count( $db->deleteCalls ) >= 1, 'Testimonies save should clear old category mappings before replacing them.' );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "Testimonies repository checks passed.\n" );
}
