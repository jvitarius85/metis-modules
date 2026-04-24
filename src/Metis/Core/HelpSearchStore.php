<?php
declare(strict_types=1);

namespace Metis\Core;

use Metis\Services\DatabaseService;

final class HelpSearchStore {
    private bool $schemaReady = false;
    private bool $seedChecked = false;

    public function __construct(
        private readonly ?DatabaseService $database = null,
        private readonly ?string $docsPath = null
    ) {}

    public function ensureSchema(): void {
        if ( $this->schemaReady ) {
            return;
        }

        $db = $this->db();
        $charset = $db->connection()->get_charset_collate();
        $categories = \Metis_Tables::get( 'help_categories' );
        $articles = \Metis_Tables::get( 'help_articles' );
        $searchIndex = \Metis_Tables::get( 'help_search_index' );

        \metis_db_delta( "CREATE TABLE {$categories} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY sort_order (sort_order),
            KEY name (name)
        ) {$charset};" );

        \metis_db_delta( "CREATE TABLE {$articles} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_code VARCHAR(16) NOT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            content LONGTEXT NOT NULL,
            summary TEXT DEFAULT NULL,
            category_id BIGINT UNSIGNED DEFAULT NULL,
            tags JSON DEFAULT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'published',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY object_code (object_code),
            UNIQUE KEY slug (slug),
            KEY status_category (status, category_id),
            KEY updated_at (updated_at),
            FULLTEXT KEY ft_help_article (title, summary, content)
        ) {$charset};" );

        \metis_db_delta( "CREATE TABLE {$searchIndex} (
            article_id BIGINT UNSIGNED NOT NULL,
            searchable_text LONGTEXT NOT NULL,
            PRIMARY KEY (article_id),
            FULLTEXT KEY ft_help_searchable_text (searchable_text)
        ) {$charset};" );

        $this->schemaReady = true;
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, total: int, page: int}
     */
    public function search( string $query, string $category = '', int $limit = 10, int $page = 1 ): array {
        $this->ensureSchema();
        $this->ensureSeeded();

        $query = trim( \metis_help_plain_text( $query ) );
        $category = trim( \metis_help_plain_key( $category ) );
        $limit = max( 1, min( 25, $limit ) );
        $page = max( 1, $page );
        $offset = ( $page - 1 ) * $limit;

        if ( $query === '' ) {
            return $this->popularArticles( $category, $limit, $page, $offset );
        }

        $fulltext = $this->runFulltextSearch( $query, $category, $limit, $offset );
        if ( $fulltext['results'] !== [] ) {
            $fulltext['page'] = $page;
            return $fulltext;
        }

        $fallback = $this->runLikeSearch( $query, $category, $limit, $offset );
        $fallback['page'] = $page;
        return $fallback;
    }

    private function ensureSeeded(): void {
        if ( $this->seedChecked ) {
            return;
        }

        $this->seedChecked = true;
        $articles = \Metis_Tables::get( 'help_articles' );
        $count = (int) $this->db()->scalar( "SELECT COUNT(1) FROM {$articles}" );
        if ( $count > 0 ) {
            return;
        }

        $this->seedFromHelpIndex();
        $this->seedFromDocs();
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, total: int}
     */
    private function popularArticles( string $category, int $limit, int $page, int $offset ): array {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );

        $where = [ "a.status = 'published'" ];
        $args = [];
        if ( $category !== '' ) {
            $where[] = 'c.slug = %s';
            $args[] = $category;
        }

        $whereSql = implode( ' AND ', $where );
        $total = (int) $db->scalar(
            "SELECT COUNT(1)
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}",
            $args
        );

        $args[] = $limit;
        $args[] = $offset;
        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.object_code,
                    a.title,
                    a.summary,
                    a.tags,
                    c.name AS category_name,
                    c.slug AS category_slug
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}
             ORDER BY a.updated_at DESC, a.id DESC
             LIMIT %d OFFSET %d",
            $args
        );

        return [
            'results' => $this->mapRows( $rows, false ),
            'total' => $total,
            'page' => $page,
        ];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, total: int}
     */
    private function runFulltextSearch( string $query, string $category, int $limit, int $offset ): array {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );
        $boolean = $this->booleanQuery( $query );
        if ( $boolean === '' ) {
            return [ 'results' => [], 'total' => 0 ];
        }

        $where = [ "a.status = 'published'", 'MATCH (a.title, a.summary, a.content) AGAINST (%s IN BOOLEAN MODE)' ];
        $baseArgs = [ $boolean ];
        if ( $category !== '' ) {
            $where[] = 'c.slug = %s';
            $baseArgs[] = $category;
        }

        $whereSql = implode( ' AND ', $where );
        $countArgs = $baseArgs;
        $total = (int) $db->scalar(
            "SELECT COUNT(1)
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}",
            $countArgs
        );

        $titleLike = $db->escapeLike( $query ) . '%';
        $summaryLike = '%' . $db->escapeLike( $query ) . '%';
        $args = array_merge(
            [ $boolean, $query, $titleLike, $summaryLike ],
            $baseArgs,
            [ $limit, $offset ]
        );

        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.object_code,
                    a.title,
                    a.summary,
                    a.tags,
                    c.name AS category_name,
                    c.slug AS category_slug,
                    (
                        (MATCH (a.title, a.summary, a.content) AGAINST (%s IN BOOLEAN MODE) * 10)
                        + (CASE WHEN LOWER(a.title) = LOWER(%s) THEN 120 ELSE 0 END)
                        + (CASE WHEN LOWER(a.title) LIKE LOWER(%s) THEN 40 ELSE 0 END)
                        + (CASE WHEN LOWER(a.summary) LIKE LOWER(%s) THEN 12 ELSE 0 END)
                    ) AS relevance_score
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}
             ORDER BY relevance_score DESC, a.updated_at DESC, a.id DESC
             LIMIT %d OFFSET %d",
            $args
        );

        return [
            'results' => $this->mapRows( $rows, true ),
            'total' => $total,
        ];
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, total: int}
     */
    private function runLikeSearch( string $query, string $category, int $limit, int $offset ): array {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );
        $like = '%' . $db->escapeLike( $query ) . '%';
        $titlePrefix = $db->escapeLike( $query ) . '%';

        $where = [ "a.status = 'published'", '(a.title LIKE %s OR a.summary LIKE %s OR a.content LIKE %s)' ];
        $baseArgs = [ $like, $like, $like ];
        if ( $category !== '' ) {
            $where[] = 'c.slug = %s';
            $baseArgs[] = $category;
        }

        $whereSql = implode( ' AND ', $where );
        $total = (int) $db->scalar(
            "SELECT COUNT(1)
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}",
            $baseArgs
        );

        $args = array_merge(
            [ $titlePrefix, $like, $like ],
            $baseArgs,
            [ $limit, $offset ]
        );
        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.object_code,
                    a.title,
                    a.summary,
                    a.tags,
                    c.name AS category_name,
                    c.slug AS category_slug,
                    (
                        (CASE WHEN a.title LIKE %s THEN 30 ELSE 0 END)
                        + (CASE WHEN a.summary LIKE %s THEN 12 ELSE 0 END)
                        + (CASE WHEN a.content LIKE %s THEN 4 ELSE 0 END)
                    ) AS relevance_score
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}
             ORDER BY relevance_score DESC, a.updated_at DESC, a.id DESC
             LIMIT %d OFFSET %d",
            $args
        );

        return [
            'results' => $this->mapRows( $rows, true ),
            'total' => $total,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRows( array $rows, bool $includeScore ): array {
        $results = [];
        foreach ( $rows as $row ) {
            $tags = $this->decodeTags( $row['tags'] ?? null );
            $results[] = [
                'id' => (int) ( $row['id'] ?? 0 ),
                'object_code' => (string) ( $row['object_code'] ?? '' ),
                'title' => (string) ( $row['title'] ?? '' ),
                'summary' => (string) ( $row['summary'] ?? '' ),
                'category' => (string) ( $row['category_name'] ?? '' ),
                'category_slug' => (string) ( $row['category_slug'] ?? '' ),
                'relevance_score' => $includeScore ? round( (float) ( $row['relevance_score'] ?? 0 ), 4 ) : 0,
                'url' => (string) ( $tags['url'] ?? '' ),
                'topic_id' => (string) ( $tags['topic_id'] ?? '' ),
                'type' => (string) ( $tags['type'] ?? 'article' ),
            ];
        }

        return $results;
    }

    private function seedFromHelpIndex(): void {
        $indexPath = rtrim( (string) $this->docsPath(), '/\\' ) . '/help-index.json';
        if ( ! is_file( $indexPath ) ) {
            return;
        }

        $decoded = json_decode( (string) file_get_contents( $indexPath ), true );
        if ( ! is_array( $decoded ) ) {
            return;
        }

        foreach ( $decoded as $topicId => $topic ) {
            if ( ! is_string( $topicId ) || ! is_array( $topic ) ) {
                continue;
            }

            $title = trim( (string) ( $topic['title'] ?? $topicId ) );
            if ( $title === '' ) {
                continue;
            }

            $steps = array_values( array_filter( array_map( 'strval', (array) ( $topic['steps'] ?? [] ) ) ) );
            $keywords = array_values( array_filter( array_map( 'strval', (array) ( $topic['keywords'] ?? [] ) ) ) );
            $summary = trim( (string) ( $topic['description'] ?? '' ) );
            $content = trim( implode( "\n", array_filter( array_merge( [ $summary ], $steps, $keywords ) ) ) );
            $moduleSlug = \metis_help_plain_key( strtok( $topicId, '.' ) ?: 'help' );
            $categoryId = $this->categoryId( $moduleSlug !== '' ? ucfirst( $moduleSlug ) : 'Help', $moduleSlug !== '' ? $moduleSlug : 'help' );
            $learnMore = trim( (string) ( $topic['learn_more'] ?? '' ) );

            $this->insertArticle(
                $title,
                $this->uniqueSlug( $topicId ),
                $content !== '' ? $content : $title,
                $summary,
                $categoryId,
                [
                    'type' => 'topic',
                    'topic_id' => $topicId,
                    'url' => $learnMore,
                    'keywords' => $keywords,
                ]
            );
        }
    }

    private function seedFromDocs(): void {
        $docsPath = $this->docsPath();
        if ( ! is_dir( $docsPath ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $docsPath, \FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $item ) {
            if ( strtolower( $item->getExtension() ) !== 'md' ) {
                continue;
            }

            $path = $item->getPathname();
            $content = @file_get_contents( $path );
            if ( ! is_string( $content ) || trim( $content ) === '' ) {
                continue;
            }

            $relative = '/' . ltrim( str_replace( '\\', '/', str_replace( dirname( __DIR__, 3 ), '', $path ) ), '/' );
            $title = $this->markdownTitle( $content, basename( $path ) );
            $plain = trim( preg_replace( '/\s+/', ' ', strip_tags( $content ) ) ?? '' );
            $summary = function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, 220 ) : substr( $plain, 0, 220 );
            $slugSeed = trim( str_replace( [ '/', '.', '\\' ], '-', ltrim( $relative, '/' ) ), '-' );
            $categorySlug = $this->docCategorySlug( $relative );
            $categoryId = $this->categoryId( ucwords( str_replace( '-', ' ', $categorySlug ) ), $categorySlug );

            $this->insertArticle(
                $title,
                $this->uniqueSlug( $slugSeed ),
                $plain !== '' ? $plain : $title,
                $summary,
                $categoryId,
                [
                    'type' => 'doc',
                    'url' => $relative,
                ]
            );
        }
    }

    private function insertArticle( string $title, string $slug, string $content, string $summary, int $categoryId, array $tags ): void {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $searchIndex = \Metis_Tables::get( 'help_search_index' );
        $objectCode = \metis_generate_code( 'HLP', $articles, 'object_code' );
        $encodedTags = function_exists( 'wp_json_encode' )
            ? wp_json_encode( $tags )
            : json_encode( $tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $db->insert(
            $articles,
            [
                'object_code' => $objectCode,
                'title' => $title,
                'slug' => $slug,
                'content' => $content,
                'summary' => $summary,
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'tags' => is_string( $encodedTags ) ? $encodedTags : '{}',
                'status' => 'published',
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        $articleId = (int) $db->lastInsertId();
        if ( $articleId < 1 ) {
            return;
        }

        $db->replace(
            $searchIndex,
            [
                'article_id' => $articleId,
                'searchable_text' => trim( implode( "\n", array_filter( [ $title, $summary, $content ] ) ) ),
            ],
            [ '%d', '%s' ]
        );
    }

    private function categoryId( string $name, string $slug ): int {
        $db = $this->db();
        $categories = \Metis_Tables::get( 'help_categories' );
        $slug = \metis_help_plain_key( $slug );
        if ( $slug === '' ) {
            $slug = 'help';
        }

        $existing = $db->fetchOne( "SELECT id FROM {$categories} WHERE slug = %s LIMIT 1", [ $slug ] );
        if ( is_array( $existing ) ) {
            return (int) ( $existing['id'] ?? 0 );
        }

        $sortOrder = (int) $db->scalar( "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$categories}" );
        $db->insert(
            $categories,
            [
                'name' => trim( $name ) !== '' ? $name : ucfirst( $slug ),
                'slug' => $slug,
                'sort_order' => $sortOrder,
            ],
            [ '%s', '%s', '%d' ]
        );

        return (int) $db->lastInsertId();
    }

    private function uniqueSlug( string $seed ): string {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $seed = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $seed ) ?? '' ) );
        $seed = trim( $seed, '-' );
        if ( $seed === '' ) {
            $seed = 'help-article';
        }

        $slug = $seed;
        $suffix = 2;
        while ( (int) $db->scalar( "SELECT COUNT(1) FROM {$articles} WHERE slug = %s", [ $slug ] ) > 0 ) {
            $slug = $seed . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function markdownTitle( string $content, string $fallback ): string {
        foreach ( preg_split( '/\R/', $content ) ?: [] as $line ) {
            $line = trim( $line );
            if ( str_starts_with( $line, '# ' ) ) {
                return trim( substr( $line, 2 ) );
            }
        }

        return $fallback;
    }

    private function docCategorySlug( string $relativePath ): string {
        $trimmed = trim( $relativePath, '/' );
        $parts = explode( '/', $trimmed );
        if ( count( $parts ) >= 2 && $parts[0] === 'docs' ) {
            return \metis_help_plain_key( $parts[1] );
        }

        return 'docs';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeTags( mixed $raw ): array {
        if ( is_array( $raw ) ) {
            return $raw;
        }

        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private function booleanQuery( string $query ): string {
        $terms = preg_split( '/\s+/', strtolower( trim( $query ) ) ) ?: [];
        $tokens = [];
        foreach ( $terms as $term ) {
            $term = preg_replace( '/[^a-z0-9]/', '', $term ) ?? '';
            if ( strlen( $term ) < 2 ) {
                continue;
            }
            $tokens[] = '+' . $term . '*';
        }

        return implode( ' ', array_unique( $tokens ) );
    }

    private function db(): DatabaseService {
        return $this->database instanceof DatabaseService ? $this->database : \metis_db();
    }

    private function docsPath(): string {
        return $this->docsPath ?? dirname( __DIR__, 3 ) . '/docs';
    }
}
