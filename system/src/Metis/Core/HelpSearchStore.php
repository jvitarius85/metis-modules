<?php
declare(strict_types=1);

namespace Metis\Core;

use InvalidArgumentException;
use Metis\Services\DatabaseService;

final class HelpSearchStore {
    private bool $schemaReady = false;
    private bool $seedChecked = false;
    private bool $indexPrimed = false;

    public function __construct(
        private readonly ?DatabaseService $database = null,
        private readonly ?string $docsPath = null
    ) {}

    public function ensureSchema(): void {
        if ( $this->schemaReady ) {
            return;
        }

        $db = $this->db();
        $charset = $db->get_charset_collate();
        $categories = \Metis_Tables::get( 'help_categories' );
        $articles = \Metis_Tables::get( 'help_articles' );
        $searchIndex = \Metis_Tables::get( 'help_search_index' );

        \metis_db_delta( "CREATE TABLE {$categories} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
            summary TEXT NOT NULL,
            content LONGTEXT NOT NULL,
            category_id BIGINT UNSIGNED DEFAULT NULL,
            module_key VARCHAR(64) DEFAULT NULL,
            action_key VARCHAR(64) DEFAULT NULL,
            tags LONGTEXT DEFAULT NULL,
            search_terms LONGTEXT DEFAULT NULL,
            seed_key VARCHAR(120) DEFAULT NULL,
            system_seeded TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY object_code (object_code),
            UNIQUE KEY slug (slug),
            UNIQUE KEY seed_key (seed_key),
            KEY category_status (category_id, status),
            KEY module_action_status (module_key, action_key, status),
            KEY status_updated (status, updated_at),
            KEY system_seeded (system_seeded),
            FULLTEXT KEY ft_help_article (title, summary, content)
        ) {$charset};" );

        \metis_db_delta( "CREATE TABLE {$searchIndex} (
            article_id BIGINT UNSIGNED NOT NULL,
            title TEXT NOT NULL,
            summary TEXT NOT NULL,
            content LONGTEXT NOT NULL,
            module_key VARCHAR(64) NOT NULL DEFAULT '',
            action_key VARCHAR(64) NOT NULL DEFAULT '',
            tags TEXT NOT NULL,
            category_name VARCHAR(191) NOT NULL DEFAULT '',
            search_terms TEXT NOT NULL,
            searchable_text LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (article_id),
            KEY module_action (module_key, action_key),
            KEY updated_at (updated_at),
            FULLTEXT KEY ft_help_searchable_text (searchable_text)
        ) {$charset};" );

        $this->reconcileSchema( $categories, $articles, $searchIndex );
        $this->schemaReady = true;
        $this->primeSearchIndexIfNeeded();
    }

    public function ensureSeeded( bool $force = false ): void {
        $this->ensureSchema();
        if ( $this->seedChecked && ! $force ) {
            return;
        }

        $this->seedChecked = true;
        $this->runSeeder( $force );
    }

    public function runSeeder( bool $force = false ): array {
        $this->ensureSchema();
        $seedFile = ( \defined( 'METIS_SRC_PATH' ) ? METIS_SRC_PATH : dirname( __DIR__, 3 ) . '/src/' ) . 'Metis/Core/Help/Seeds/HelpDocumentsSeed.php';
        if ( ! is_file( $seedFile ) ) {
            return [ 'categories' => 0, 'articles' => 0, 'updated' => 0 ];
        }

        require_once $seedFile;
        if ( ! class_exists( 'Metis_Help_Documents_Seed' ) ) {
            return [ 'categories' => 0, 'articles' => 0, 'updated' => 0 ];
        }

        return \Metis_Help_Documents_Seed::run( $this, $force );
    }

    public function upsertCategory( string $name, string $slug, int $sortOrder = 0 ): int {
        $this->ensureSchema();
        $db = $this->db();
        $categories = \Metis_Tables::get( 'help_categories' );

        $slug = \metis_help_plain_key( $slug );
        $name = trim( \metis_help_plain_text( $name ) );
        if ( $slug === '' ) {
            throw new InvalidArgumentException( 'Help category slug is required.' );
        }
        if ( $name === '' ) {
            $name = ucwords( str_replace( '-', ' ', $slug ) );
        }

        $existing = $db->fetchOne( "SELECT id FROM {$categories} WHERE slug = %s LIMIT 1", [ $slug ] );
        if ( is_array( $existing ) ) {
            $db->update(
                $categories,
                [
                    'name' => $name,
                    'sort_order' => $sortOrder,
                ],
                [ 'id' => (int) $existing['id'] ],
                [ '%s', '%d' ],
                [ '%d' ]
            );
            return (int) $existing['id'];
        }

        $db->insert(
            $categories,
            [
                'name' => $name,
                'slug' => $slug,
                'sort_order' => $sortOrder,
            ],
            [ '%s', '%s', '%d' ]
        );

        return (int) $db->lastInsertId();
    }

    public function upsertSeededArticle( array $definition, bool $force = false ): array {
        $this->ensureSchema();

        $seedKey = trim( (string) ( $definition['seed_key'] ?? '' ) );
        if ( $seedKey === '' ) {
            throw new InvalidArgumentException( 'Seeded help articles require a seed key.' );
        }

        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $existing = $db->fetchOne( "SELECT * FROM {$articles} WHERE seed_key = %s LIMIT 1", [ $seedKey ] );
        $normalized = $this->normalizeArticlePayload(
            $definition,
            true,
            is_array( $existing ) ? (int) ( $existing['id'] ?? 0 ) : null
        );

        if ( is_array( $existing ) && (int) ( $existing['system_seeded'] ?? 0 ) !== 1 && ! $force ) {
            $this->rebuildSearchIndex( (int) $existing['id'] );
            return [ 'action' => 'preserved', 'id' => (int) $existing['id'] ];
        }

        $record = [
            'title' => $normalized['title'],
            'slug' => $normalized['slug'],
            'summary' => $normalized['summary'],
            'content' => $normalized['content'],
            'category_id' => $normalized['category_id'],
            'module_key' => $normalized['module_key'],
            'action_key' => $normalized['action_key'],
            'tags' => $normalized['tags_json'],
            'search_terms' => $normalized['search_terms'],
            'seed_key' => $seedKey,
            'system_seeded' => 1,
            'status' => $normalized['status'],
        ];

        if ( is_array( $existing ) ) {
            $db->update(
                $articles,
                $record,
                [ 'id' => (int) $existing['id'] ],
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ],
                [ '%d' ]
            );
            $articleId = (int) $existing['id'];
            $action = 'updated';
        } else {
            $record['object_code'] = $this->nextObjectCode();
            $db->insert(
                $articles,
                $record,
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
            );
            $articleId = (int) $db->lastInsertId();
            $action = 'created';
        }

        $this->rebuildSearchIndex( $articleId );
        return [ 'action' => $action, 'id' => $articleId ];
    }

    public function saveArticle( array $payload, ?int $articleId = null ): int {
        $this->ensureSchema();
        $normalized = $this->normalizeArticlePayload( $payload, false, $articleId );
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );

        $record = [
            'title' => $normalized['title'],
            'slug' => $normalized['slug'],
            'summary' => $normalized['summary'],
            'content' => $normalized['content'],
            'category_id' => $normalized['category_id'],
            'module_key' => $normalized['module_key'],
            'action_key' => $normalized['action_key'],
            'tags' => $normalized['tags_json'],
            'search_terms' => $normalized['search_terms'],
            'status' => $normalized['status'],
            'system_seeded' => 0,
        ];

        if ( $articleId !== null && $articleId > 0 ) {
            $db->update(
                $articles,
                $record,
                [ 'id' => $articleId ],
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' ],
                [ '%d' ]
            );
            $id = $articleId;
        } else {
            $record['object_code'] = $this->nextObjectCode();
            $db->insert(
                $articles,
                $record,
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );
            $id = (int) $db->lastInsertId();
        }

        $this->rebuildSearchIndex( $id );
        return $id;
    }

    public function setStatus( int $articleId, string $status ): bool {
        $this->ensureSchema();
        $status = $this->normalizeStatus( $status );
        $updated = $this->db()->update(
            \Metis_Tables::get( 'help_articles' ),
            [ 'status' => $status ],
            [ 'id' => $articleId ],
            [ '%s' ],
            [ '%d' ]
        );

        $this->rebuildSearchIndex( $articleId );
        return $updated !== false;
    }

    public function rebuildSearchIndex( ?int $articleId = null ): int {
        $this->ensureSchema();
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );
        $searchIndex = \Metis_Tables::get( 'help_search_index' );

        $where = '';
        $args = [];
        if ( $articleId !== null && $articleId > 0 ) {
            $where = 'WHERE a.id = %d';
            $args[] = $articleId;
        }

        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.title,
                    a.summary,
                    a.content,
                    a.module_key,
                    a.action_key,
                    a.tags,
                    a.search_terms,
                    a.updated_at,
                    COALESCE(c.name, '') AS category_name
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             {$where}",
            $args
        );

        if ( $articleId !== null && $articleId > 0 ) {
            $db->delete( $searchIndex, [ 'article_id' => $articleId ], [ '%d' ] );
        } else {
            $db->execute( "TRUNCATE TABLE {$searchIndex}" );
        }

        $count = 0;
        foreach ( $rows as $row ) {
            $tags = implode( ', ', $this->decodeTags( $row['tags'] ?? '' ) );
            $searchTerms = trim( (string) ( $row['search_terms'] ?? '' ) );
            $content = $this->searchableText( (string) ( $row['content'] ?? '' ) );
            $searchableText = trim(
                implode(
                    "\n",
                    array_filter(
                        [
                            $this->searchableText( (string) ( $row['title'] ?? '' ) ),
                            $this->searchableText( (string) ( $row['summary'] ?? '' ) ),
                            $content,
                            $this->searchableText( (string) ( $row['module_key'] ?? '' ) ),
                            $this->searchableText( (string) ( $row['action_key'] ?? '' ) ),
                            $this->searchableText( $tags ),
                            $this->searchableText( (string) ( $row['category_name'] ?? '' ) ),
                            $this->searchableText( $searchTerms ),
                        ]
                    )
                )
            );

            $db->replace(
                $searchIndex,
                [
                    'article_id' => (int) ( $row['id'] ?? 0 ),
                    'title' => $this->searchableText( (string) ( $row['title'] ?? '' ) ),
                    'summary' => $this->searchableText( (string) ( $row['summary'] ?? '' ) ),
                    'content' => $content,
                    'module_key' => $this->searchableText( (string) ( $row['module_key'] ?? '' ) ),
                    'action_key' => $this->searchableText( (string) ( $row['action_key'] ?? '' ) ),
                    'tags' => $this->searchableText( $tags ),
                    'category_name' => $this->searchableText( (string) ( $row['category_name'] ?? '' ) ),
                    'search_terms' => $this->searchableText( $searchTerms ),
                    'searchable_text' => $searchableText,
                    'updated_at' => (string) ( $row['updated_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );
            $count++;
        }

        return $count;
    }

    private function reconcileSchema( string $categories, string $articles, string $searchIndex ): void {
        $this->ensureColumn( $categories, 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' );
        $this->ensureColumn( $categories, 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' );

        $this->ensureColumn( $articles, 'summary', 'TEXT NOT NULL' );
        $this->ensureColumn( $articles, 'module_key', 'VARCHAR(64) DEFAULT NULL' );
        $this->ensureColumn( $articles, 'action_key', 'VARCHAR(64) DEFAULT NULL' );
        $this->ensureColumn( $articles, 'tags', 'LONGTEXT DEFAULT NULL' );
        $this->ensureColumn( $articles, 'search_terms', 'LONGTEXT DEFAULT NULL' );
        $this->ensureColumn( $articles, 'seed_key', 'VARCHAR(120) DEFAULT NULL' );
        $this->ensureColumn( $articles, 'system_seeded', 'TINYINT(1) NOT NULL DEFAULT 0' );
        $this->ensureColumn( $articles, 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' );
        $this->ensureColumn( $articles, 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' );

        $this->ensureColumn( $searchIndex, 'title', 'TEXT NOT NULL' );
        $this->ensureColumn( $searchIndex, 'summary', 'TEXT NOT NULL' );
        $this->ensureColumn( $searchIndex, 'content', 'LONGTEXT NOT NULL' );
        $this->ensureColumn( $searchIndex, 'module_key', "VARCHAR(64) NOT NULL DEFAULT ''" );
        $this->ensureColumn( $searchIndex, 'action_key', "VARCHAR(64) NOT NULL DEFAULT ''" );
        $this->ensureColumn( $searchIndex, 'tags', 'TEXT NOT NULL' );
        $this->ensureColumn( $searchIndex, 'category_name', "VARCHAR(191) NOT NULL DEFAULT ''" );
        $this->ensureColumn( $searchIndex, 'search_terms', 'TEXT NOT NULL' );
        $this->ensureColumn( $searchIndex, 'searchable_text', 'LONGTEXT NOT NULL' );
        $this->ensureColumn( $searchIndex, 'updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP' );
    }

    private function ensureColumn( string $table, string $column, string $definition ): void {
        if ( $this->columnExists( $table, $column ) ) {
            return;
        }

        $this->db()->execute( sprintf( 'ALTER TABLE %s ADD COLUMN `%s` %s', $table, $column, $definition ) );
    }

    private function columnExists( string $table, string $column ): bool {
        $rows = $this->db()->fetchAll( 'SHOW COLUMNS FROM ' . $table );
        foreach ( $rows as $row ) {
            if ( strcasecmp( (string) ( $row['Field'] ?? '' ), $column ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    private function primeSearchIndexIfNeeded(): void {
        if ( $this->indexPrimed ) {
            return;
        }

        $this->indexPrimed = true;

        $articles = \Metis_Tables::get( 'help_articles' );
        $searchIndex = \Metis_Tables::get( 'help_search_index' );
        $articleCount = (int) $this->db()->scalar( "SELECT COUNT(1) FROM {$articles}" );
        $indexCount = (int) $this->db()->scalar( "SELECT COUNT(1) FROM {$searchIndex}" );

        if ( $articleCount > 0 && $indexCount === 0 ) {
            $this->rebuildSearchIndex();
        }
    }

    public function search( string $query, string $category = '', int $limit = 10, int $page = 1, bool $includeDrafts = false ): array {
        $this->ensureSchema();
        $query = trim( \metis_help_plain_text( $query ) );
        $category = trim( \metis_help_plain_key( $category ) );
        $limit = max( 1, min( 25, $limit ) );
        $page = max( 1, $page );
        $offset = ( $page - 1 ) * $limit;

        if ( $query === '' ) {
            return $this->popularArticles( $category, $limit, $page, $offset, $includeDrafts );
        }

        $fulltext = $this->runFulltextSearch( $query, $category, $limit, $offset, $includeDrafts );
        if ( $fulltext['results'] !== [] ) {
            $fulltext['page'] = $page;
            return $fulltext;
        }

        $fallback = $this->runLikeSearch( $query, $category, $limit, $offset, $includeDrafts );
        $fallback['page'] = $page;
        return $fallback;
    }

    public function landingData(): array {
        $this->ensureSchema();
        return [
            'categories' => $this->categorySummaries(),
            'popular_articles' => $this->recentArticles( 6, true ),
            'recent_articles' => $this->recentArticles( 6, false ),
        ];
    }

    public function recentArticles( int $limit = 6, bool $preferPopular = false ): array {
        $this->ensureSchema();
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );
        $order = $preferPopular
            ? 'a.system_seeded DESC, a.updated_at DESC, a.id DESC'
            : 'a.updated_at DESC, a.id DESC';

        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.object_code,
                    a.title,
                    a.slug,
                    a.summary,
                    a.module_key,
                    a.action_key,
                    a.tags,
                    a.updated_at,
                    c.name AS category_name,
                    c.slug AS category_slug
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE a.status = 'published'
               AND " . $this->userFacingWhereClause( 'a' ) . "
             ORDER BY {$order}
             LIMIT %d",
            [ $limit ]
        );

        return $this->mapRows( $rows, false );
    }

    public function categorySummaries(): array {
        $this->ensureSchema();
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );

        return $db->fetchAll(
            "SELECT c.id,
                    c.name,
                    c.slug,
                    c.sort_order,
                    COUNT(a.id) AS article_count
             FROM {$categories} c
             LEFT JOIN {$articles} a
                    ON a.category_id = c.id
                   AND a.status = 'published'
                   AND " . $this->userFacingWhereClause( 'a' ) . "
             GROUP BY c.id, c.name, c.slug, c.sort_order
             HAVING COUNT(a.id) > 0
             ORDER BY c.sort_order ASC, c.name ASC"
        );
    }

    public function navigationTree(): array {
        $this->ensureSchema();
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );

        $rows = $db->fetchAll(
            "SELECT c.id AS category_id,
                    c.name AS category_name,
                    c.slug AS category_slug,
                    c.sort_order,
                    a.id AS article_id,
                    a.title AS article_title,
                    a.slug AS article_slug,
                    a.updated_at
             FROM {$categories} c
             LEFT JOIN {$articles} a
                    ON a.category_id = c.id
                   AND a.status = 'published'
                   AND " . $this->userFacingWhereClause( 'a' ) . "
             WHERE a.id IS NOT NULL
             ORDER BY c.sort_order ASC, c.name ASC, a.title ASC, a.id ASC"
        );

        $tree = [];
        foreach ( $rows as $row ) {
            $categoryId = (int) ( $row['category_id'] ?? 0 );
            if ( $categoryId < 1 ) {
                continue;
            }

            if ( ! isset( $tree[ $categoryId ] ) ) {
                $tree[ $categoryId ] = [
                    'id' => $categoryId,
                    'name' => (string) ( $row['category_name'] ?? '' ),
                    'slug' => (string) ( $row['category_slug'] ?? '' ),
                    'article_count' => 0,
                    'url' => $this->appUrl( '/admin/help/category/' . ltrim( (string) ( $row['category_slug'] ?? '' ), '/' ) ),
                    'articles' => [],
                ];
            }

            $articleId = (int) ( $row['article_id'] ?? 0 );
            if ( $articleId < 1 ) {
                continue;
            }

            $tree[ $categoryId ]['article_count']++;
            $tree[ $categoryId ]['articles'][] = [
                'id' => $articleId,
                'title' => (string) ( $row['article_title'] ?? '' ),
                'slug' => (string) ( $row['article_slug'] ?? '' ),
                'updated_at' => (string) ( $row['updated_at'] ?? '' ),
                'url' => $this->articleUrl( (string) ( $row['article_slug'] ?? '' ) ),
            ];
        }

        return array_values( $tree );
    }

    public function articleBySlug( string $slug, bool $includeDraft = false ): ?array {
        $this->ensureSchema();
        $slug = trim( \metis_help_plain_key( $slug ) );
        if ( $slug === '' ) {
            return null;
        }

        return $this->fetchSingleArticle( 'a.slug = %s', [ $slug ], $includeDraft, false );
    }

    public function articleById( int $id, bool $includeDraft = true ): ?array {
        $this->ensureSchema();
        if ( $id < 1 ) {
            return null;
        }

        return $this->fetchSingleArticle( 'a.id = %d', [ $id ], $includeDraft, true );
    }

    public function categoryBySlug( string $slug ): ?array {
        $this->ensureSchema();
        $slug = trim( \metis_help_plain_key( $slug ) );
        if ( $slug === '' ) {
            return null;
        }

        return $this->db()->fetchOne(
            "SELECT id, name, slug, sort_order
             FROM " . \Metis_Tables::get( 'help_categories' ) . "
             WHERE slug = %s
             LIMIT 1",
            [ $slug ]
        );
    }

    public function articlesForCategory( string $slug, int $limit = 12, int $page = 1 ): array {
        return $this->search( '', $slug, $limit, $page, false );
    }

    public function relatedArticles( int $articleId, int $categoryId, int $limit = 4 ): array {
        $this->ensureSchema();
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );

        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.object_code,
                    a.title,
                    a.slug,
                    a.summary,
                    a.module_key,
                    a.action_key,
                    a.tags,
                    a.updated_at,
                    c.name AS category_name,
                    c.slug AS category_slug
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE a.status = 'published'
               AND " . $this->userFacingWhereClause( 'a' ) . "
               AND a.id <> %d
               AND a.category_id = %d
             ORDER BY a.updated_at DESC, a.id DESC
             LIMIT %d",
            [ $articleId, $categoryId, $limit ]
        );

        return $this->mapRows( $rows, false );
    }

    public function adminList( string $query = '', string $category = '', string $status = '', int $page = 1, int $limit = 20 ): array {
        $this->ensureSchema();
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );

        $query = trim( \metis_help_plain_text( $query ) );
        $category = trim( \metis_help_plain_key( $category ) );
        $status = trim( \metis_help_plain_key( $status ) );
        $limit = max( 1, min( 50, $limit ) );
        $page = max( 1, $page );
        $offset = ( $page - 1 ) * $limit;

        $where = [ '1=1' ];
        $args = [];

        if ( $category !== '' ) {
            $where[] = 'c.slug = %s';
            $args[] = $category;
        }

        if ( in_array( $status, [ 'draft', 'published' ], true ) ) {
            $where[] = 'a.status = %s';
            $args[] = $status;
        }

        if ( $query !== '' ) {
            $like = '%' . $db->escapeLike( $query ) . '%';
            $where[] = '(a.title LIKE %s OR a.summary LIKE %s OR a.content LIKE %s OR a.search_terms LIKE %s OR a.tags LIKE %s)';
            array_push( $args, $like, $like, $like, $like, $like );
        }

        $whereSql = implode( ' AND ', $where );
        $total = (int) $db->scalar(
            "SELECT COUNT(1)
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}",
            $args
        );

        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.object_code,
                    a.title,
                    a.slug,
                    a.summary,
                    a.module_key,
                    a.action_key,
                    a.tags,
                    a.search_terms,
                    a.seed_key,
                    a.system_seeded,
                    a.status,
                    a.updated_at,
                    c.name AS category_name,
                    c.slug AS category_slug
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}
             ORDER BY a.updated_at DESC, a.id DESC
             LIMIT %d OFFSET %d",
            array_merge( $args, [ $limit, $offset ] )
        );

        return [
            'results' => array_map( fn ( array $row ): array => $this->mapAdminRow( $row ), $rows ),
            'total' => $total,
            'page' => $page,
            'categories' => $this->categorySummaries(),
        ];
    }

    private function popularArticles( string $category, int $limit, int $page, int $offset, bool $includeDrafts ): array {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );

        $where = [ $this->userFacingWhereClause( 'a' ) ];
        $args = [];
        if ( ! $includeDrafts ) {
            $where[] = "a.status = 'published'";
        }
        if ( $category !== '' ) {
            $where[] = 'c.slug = %s';
            $args[] = $category;
        }

        $whereSql = $where === [] ? '1=1' : implode( ' AND ', $where );
        $total = (int) $db->scalar(
            "SELECT COUNT(1)
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}",
            $args
        );

        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.object_code,
                    a.title,
                    a.slug,
                    a.summary,
                    a.module_key,
                    a.action_key,
                    a.tags,
                    a.updated_at,
                    c.name AS category_name,
                    c.slug AS category_slug
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}
             ORDER BY a.system_seeded DESC, a.updated_at DESC, a.id DESC
             LIMIT %d OFFSET %d",
            array_merge( $args, [ $limit, $offset ] )
        );

        return [
            'results' => $this->mapRows( $rows, false ),
            'total' => $total,
            'page' => $page,
        ];
    }

    private function runFulltextSearch( string $query, string $category, int $limit, int $offset, bool $includeDrafts ): array {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );
        $searchIndex = \Metis_Tables::get( 'help_search_index' );
        $boolean = $this->booleanQuery( $query );
        $normalized = $this->searchableText( $query );
        $exact = $normalized;
        $like = '%' . $db->escapeLike( $normalized ) . '%';
        $moduleAction = $this->detectModuleAction( $query );
        if ( $boolean === '' ) {
            return [ 'results' => [], 'total' => 0 ];
        }

        $where = [
            '(MATCH (si.searchable_text) AGAINST (%s IN BOOLEAN MODE) OR (%s <> \'\' AND si.module_key = %s) OR (%s <> \'\' AND si.action_key = %s))',
            $this->userFacingWhereClause( 'a' ),
        ];
        $baseArgs = [ $boolean, (string) ( $moduleAction['module_key'] ?? '' ), (string) ( $moduleAction['module_key'] ?? '' ), (string) ( $moduleAction['action_key'] ?? '' ), (string) ( $moduleAction['action_key'] ?? '' ) ];

        if ( ! $includeDrafts ) {
            $where[] = "a.status = 'published'";
        }

        if ( $category !== '' ) {
            $where[] = 'c.slug = %s';
            $baseArgs[] = $category;
        }

        $whereSql = implode( ' AND ', $where );
        $total = (int) $db->scalar(
            "SELECT COUNT(1)
             FROM {$searchIndex} si
             INNER JOIN {$articles} a ON a.id = si.article_id
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}",
            $baseArgs
        );

        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.object_code,
                    a.title,
                    a.slug,
                    a.summary,
                    a.module_key,
                    a.action_key,
                    a.tags,
                    a.updated_at,
                    c.name AS category_name,
                    c.slug AS category_slug,
                    ((MATCH (si.searchable_text) AGAINST (%s IN BOOLEAN MODE))
                     + CASE WHEN si.title = %s THEN 120 ELSE 0 END
                     + CASE WHEN FIND_IN_SET(%s, REPLACE(si.search_terms, ', ', ',')) > 0 THEN 90 ELSE 0 END
                     + CASE WHEN si.search_terms LIKE %s THEN 40 ELSE 0 END
                     + CASE WHEN (%s <> '' AND si.module_key = %s) THEN 24 ELSE 0 END
                     + CASE WHEN (%s <> '' AND si.action_key = %s) THEN 20 ELSE 0 END
                     + CASE WHEN si.tags LIKE %s THEN 16 ELSE 0 END
                     + CASE WHEN si.summary LIKE %s THEN 10 ELSE 0 END
                     + CASE WHEN si.content LIKE %s THEN 6 ELSE 0 END) AS relevance_score
             FROM {$searchIndex} si
             INNER JOIN {$articles} a ON a.id = si.article_id
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}
             ORDER BY relevance_score DESC, a.updated_at DESC, a.id DESC
             LIMIT %d OFFSET %d",
            array_merge(
                [ $boolean, $exact, $exact, $like, (string) ( $moduleAction['module_key'] ?? '' ), (string) ( $moduleAction['module_key'] ?? '' ), (string) ( $moduleAction['action_key'] ?? '' ), (string) ( $moduleAction['action_key'] ?? '' ), $like, $like, $like ],
                $baseArgs,
                [ $limit, $offset ]
            )
        );

        return [
            'results' => $this->mapRows( $rows, true ),
            'total' => $total,
        ];
    }

    private function runLikeSearch( string $query, string $category, int $limit, int $offset, bool $includeDrafts ): array {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );
        $searchIndex = \Metis_Tables::get( 'help_search_index' );
        $normalized = $this->searchableText( $query );
        $exact = $normalized;
        $like = '%' . $db->escapeLike( $normalized ) . '%';
        $moduleAction = $this->detectModuleAction( $query );

        $where = [
            '(si.title LIKE %s OR si.summary LIKE %s OR si.searchable_text LIKE %s OR si.search_terms LIKE %s OR si.tags LIKE %s OR (%s <> \'\' AND si.module_key = %s) OR (%s <> \'\' AND si.action_key = %s))',
            $this->userFacingWhereClause( 'a' ),
        ];
        $baseArgs = [ $like, $like, $like, $like, $like, (string) ( $moduleAction['module_key'] ?? '' ), (string) ( $moduleAction['module_key'] ?? '' ), (string) ( $moduleAction['action_key'] ?? '' ), (string) ( $moduleAction['action_key'] ?? '' ) ];

        if ( ! $includeDrafts ) {
            $where[] = "a.status = 'published'";
        }

        if ( $category !== '' ) {
            $where[] = 'c.slug = %s';
            $baseArgs[] = $category;
        }

        $whereSql = implode( ' AND ', $where );
        $total = (int) $db->scalar(
            "SELECT COUNT(1)
             FROM {$searchIndex} si
             INNER JOIN {$articles} a ON a.id = si.article_id
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}",
            $baseArgs
        );

        $rows = $db->fetchAll(
            "SELECT a.id,
                    a.object_code,
                    a.title,
                    a.slug,
                    a.summary,
                    a.module_key,
                    a.action_key,
                    a.tags,
                    a.updated_at,
                    c.name AS category_name,
                    c.slug AS category_slug,
                    (CASE WHEN si.title = %s THEN 120 ELSE 0 END
                     + CASE WHEN si.title LIKE %s THEN 80 ELSE 0 END
                     + CASE WHEN FIND_IN_SET(%s, REPLACE(si.search_terms, ', ', ',')) > 0 THEN 70 ELSE 0 END
                     + CASE WHEN si.search_terms LIKE %s THEN 60 ELSE 0 END
                     + CASE WHEN (%s <> '' AND si.module_key = %s AND %s <> '' AND si.action_key = %s) THEN 50 ELSE 0 END
                     + CASE WHEN si.tags LIKE %s THEN 30 ELSE 0 END
                     + CASE WHEN si.summary LIKE %s THEN 20 ELSE 0 END
                     + CASE WHEN si.content LIKE %s THEN 10 ELSE 0 END) AS relevance_score
             FROM {$searchIndex} si
             INNER JOIN {$articles} a ON a.id = si.article_id
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE {$whereSql}
             ORDER BY relevance_score DESC, a.updated_at DESC, a.id DESC
             LIMIT %d OFFSET %d",
            array_merge(
                [ $exact, $like, $exact, $like, (string) ( $moduleAction['module_key'] ?? '' ), (string) ( $moduleAction['module_key'] ?? '' ), (string) ( $moduleAction['action_key'] ?? '' ), (string) ( $moduleAction['action_key'] ?? '' ), $like, $like, $like ],
                $baseArgs,
                [ $limit, $offset ]
            )
        );

        return [
            'results' => $this->mapRows( $rows, true ),
            'total' => $total,
        ];
    }

    private function fetchSingleArticle( string $whereClause, array $args, bool $includeDraft, bool $includeLegacy ): ?array {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $categories = \Metis_Tables::get( 'help_categories' );

        $where = [ $whereClause ];
        if ( ! $includeLegacy ) {
            $where[] = $this->userFacingWhereClause( 'a' );
        }
        if ( ! $includeDraft ) {
            $where[] = "a.status = 'published'";
        }

        $row = $db->fetchOne(
            "SELECT a.*,
                    c.name AS category_name,
                    c.slug AS category_slug
             FROM {$articles} a
             LEFT JOIN {$categories} c ON c.id = a.category_id
             WHERE " . implode( ' AND ', $where ) . "
             LIMIT 1",
            $args
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return $this->mapArticleRecord( $row );
    }

    private function normalizeArticlePayload( array $payload, bool $seeded, ?int $articleId = null ): array {
        $title = trim( \metis_help_plain_text( (string) ( $payload['title'] ?? '' ) ) );
        $slug = trim( (string) ( $payload['slug'] ?? '' ) );
        $summary = trim( \metis_help_plain_text( (string) ( $payload['summary'] ?? '' ) ) );
        $content = $this->sanitizeContentHtml( (string) ( $payload['content'] ?? '' ) );
        $status = $this->normalizeStatus( (string) ( $payload['status'] ?? ( $seeded ? 'published' : 'draft' ) ) );
        $categoryId = (int) ( $payload['category_id'] ?? 0 );
        $categorySlug = trim( \metis_help_plain_key( (string) ( $payload['category_slug'] ?? '' ) ) );
        $categoryName = trim( \metis_help_plain_text( (string) ( $payload['category_name'] ?? '' ) ) );
        $moduleKey = trim( \metis_help_plain_key( (string) ( $payload['module_key'] ?? '' ) ) );
        $actionKey = trim( \metis_help_plain_key( (string) ( $payload['action_key'] ?? '' ) ) );
        $tags = $this->normalizeTags( $payload['tags'] ?? [] );
        $searchTerms = $this->normalizeSearchTerms( $payload['search_terms'] ?? '' );

        if ( $title === '' ) {
            throw new InvalidArgumentException( 'Title is required.' );
        }
        if ( $summary === '' ) {
            throw new InvalidArgumentException( 'Summary is required.' );
        }
        if ( $content === '' ) {
            throw new InvalidArgumentException( 'Content is required.' );
        }

        if ( $categoryId < 1 ) {
            if ( $categorySlug === '' ) {
                throw new InvalidArgumentException( 'Category is required.' );
            }
            $categoryId = $this->upsertCategory(
                $categoryName !== '' ? $categoryName : ucwords( str_replace( '-', ' ', $categorySlug ) ),
                $categorySlug
            );
        }

        $slug = $this->normalizeSlug( $slug !== '' ? $slug : $title, $articleId );
        if ( count( $tags ) < 5 ) {
            $tags = array_values(
                array_unique(
                    array_merge(
                        $tags,
                        preg_split( '/[\s-]+/', strtolower( $title ) ) ?: [],
                        preg_split( '/[\s,]+/', strtolower( $searchTerms ) ) ?: []
                    )
                )
            );
            $tags = array_values( array_filter( $tags, static fn ( mixed $value ): bool => is_string( $value ) && trim( $value ) !== '' ) );
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'summary' => $summary,
            'content' => $content,
            'category_id' => $categoryId,
            'module_key' => $moduleKey,
            'action_key' => $actionKey,
            'tags_json' => $this->encodeTags( $tags ),
            'search_terms' => $searchTerms,
            'status' => $status,
        ];
    }

    private function normalizeSlug( string $seed, ?int $articleId = null ): string {
        $db = $this->db();
        $articles = \Metis_Tables::get( 'help_articles' );
        $seed = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $seed ) ?? '' ) );
        $seed = trim( $seed, '-' );
        if ( $seed === '' ) {
            throw new InvalidArgumentException( 'Slug is required.' );
        }

        $existing = $db->fetchOne( "SELECT id FROM {$articles} WHERE slug = %s LIMIT 1", [ $seed ] );
        if ( is_array( $existing ) && (int) ( $existing['id'] ?? 0 ) !== (int) $articleId ) {
            throw new InvalidArgumentException( 'Slug must be unique.' );
        }

        return $seed;
    }

    private function sanitizeContentHtml( string $content ): string {
        $content = trim( $content );
        if ( $content === '' ) {
            return '';
        }

        $allowed = '<h1><h2><h3><p><ul><ol><li><strong><em><b><i><a><code><pre><blockquote><br>';
        $content = strip_tags( $content, $allowed );
        $content = preg_replace( '#<script[^>]*>.*?</script>#is', '', $content ) ?? $content;
        $content = preg_replace( '#\son[a-z]+\s*=\s*([\'"]).*?\1#i', '', $content ) ?? $content;
        $content = preg_replace( '#javascript:#i', '', $content ) ?? $content;
        return trim( $content );
    }

    private function normalizeStatus( string $status ): string {
        $status = \metis_help_plain_key( $status );
        if ( ! in_array( $status, [ 'draft', 'published' ], true ) ) {
            throw new InvalidArgumentException( 'Status must be draft or published.' );
        }
        return $status;
    }

    private function normalizeTags( mixed $raw ): array {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $raw = $decoded;
            } else {
                $raw = preg_split( '/[\n,]+/', $raw ) ?: [];
            }
        }

        $tags = [];
        foreach ( (array) $raw as $tag ) {
            $tag = strtolower( trim( \metis_help_plain_text( (string) $tag ) ) );
            if ( $tag !== '' ) {
                $tags[] = $tag;
            }
        }

        return array_values( array_unique( $tags ) );
    }

    private function normalizeSearchTerms( mixed $raw ): string {
        if ( is_array( $raw ) ) {
            $raw = implode( ', ', array_map( 'strval', $raw ) );
        }

        $terms = [];
        foreach ( preg_split( '/[\n,]+/', (string) $raw ) ?: [] as $term ) {
            $term = trim( \metis_help_plain_text( $term ) );
            if ( $term !== '' ) {
                $terms[] = $term;
            }
        }

        return implode( ', ', array_values( array_unique( $terms ) ) );
    }

    private function nextObjectCode(): string {
        $articles = \Metis_Tables::get( 'help_articles' );
        $latest = (string) $this->db()->scalar(
            "SELECT object_code
             FROM {$articles}
             WHERE object_code LIKE 'HLP%'
             ORDER BY object_code DESC
             LIMIT 1"
        );

        $number = 0;
        if ( preg_match( '/^HLP(\d{6})$/', $latest, $matches ) === 1 ) {
            $number = (int) ( $matches[1] ?? 0 );
        }

        return sprintf( 'HLP%06d', $number + 1 );
    }

    private function searchableText( string $value ): string {
        return trim( \metis_help_plain_text( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
    }

    private function encodeTags( array $tags ): string {
        $encoded = json_encode( array_values( array_unique( array_map( 'strval', $tags ) ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $encoded ) ? $encoded : '[]';
    }

    private function decodeTags( mixed $raw ): array {
        if ( is_array( $raw ) ) {
            return $this->flattenTagValues( $raw );
        }

        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        if ( is_array( $decoded ) ) {
            return $this->flattenTagValues( $decoded );
        }

        return array_values(
            array_filter(
                array_map(
                    static fn ( string $item ): string => trim( $item ),
                    preg_split( '/[\n,]+/', $raw ) ?: []
                ),
                static fn ( string $item ): bool => $item !== ''
            )
        );
    }

    private function flattenTagValues( array $values ): array {
        $tags = [];

        array_walk_recursive(
            $values,
            static function ( mixed $value ) use ( &$tags ): void {
                if ( is_scalar( $value ) ) {
                    $tag = trim( (string) $value );
                    if ( $tag !== '' ) {
                        $tags[] = $tag;
                    }
                }
            }
        );

        return array_values( array_unique( $tags ) );
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

    private function mapRows( array $rows, bool $includeScore ): array {
        $results = [];
        foreach ( $rows as $row ) {
            $tags = $this->decodeTags( $row['tags'] ?? '' );
            $results[] = [
                'id' => (int) ( $row['id'] ?? 0 ),
                'object_code' => (string) ( $row['object_code'] ?? '' ),
                'title' => (string) ( $row['title'] ?? '' ),
                'slug' => (string) ( $row['slug'] ?? '' ),
                'summary' => (string) ( $row['summary'] ?? '' ),
                'module_key' => (string) ( $row['module_key'] ?? '' ),
                'action_key' => (string) ( $row['action_key'] ?? '' ),
                'category' => (string) ( $row['category_name'] ?? '' ),
                'category_slug' => (string) ( $row['category_slug'] ?? '' ),
                'updated_at' => (string) ( $row['updated_at'] ?? '' ),
                'tags' => $tags,
                'relevance_score' => $includeScore ? round( (float) ( $row['relevance_score'] ?? 0 ), 4 ) : 0.0,
                'url' => $this->articleUrl( (string) ( $row['slug'] ?? '' ) ),
                'type' => 'article',
            ];
        }

        return $results;
    }

    private function mapAdminRow( array $row ): array {
        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'object_code' => (string) ( $row['object_code'] ?? '' ),
            'title' => (string) ( $row['title'] ?? '' ),
            'slug' => (string) ( $row['slug'] ?? '' ),
            'summary' => (string) ( $row['summary'] ?? '' ),
            'module_key' => (string) ( $row['module_key'] ?? '' ),
            'action_key' => (string) ( $row['action_key'] ?? '' ),
            'category' => (string) ( $row['category_name'] ?? '' ),
            'category_slug' => (string) ( $row['category_slug'] ?? '' ),
            'status' => (string) ( $row['status'] ?? 'draft' ),
            'updated_at' => (string) ( $row['updated_at'] ?? '' ),
            'seed_key' => (string) ( $row['seed_key'] ?? '' ),
            'system_seeded' => (int) ( $row['system_seeded'] ?? 0 ),
            'tags' => $this->decodeTags( $row['tags'] ?? '' ),
            'search_terms' => (string) ( $row['search_terms'] ?? '' ),
            'preview_url' => $this->articleUrl( (string) ( $row['slug'] ?? '' ) ),
        ];
    }

    private function mapArticleRecord( array $row ): array {
        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'object_code' => (string) ( $row['object_code'] ?? '' ),
            'title' => (string) ( $row['title'] ?? '' ),
            'slug' => (string) ( $row['slug'] ?? '' ),
            'summary' => (string) ( $row['summary'] ?? '' ),
            'content' => (string) ( $row['content'] ?? '' ),
            'category_id' => (int) ( $row['category_id'] ?? 0 ),
            'category_name' => (string) ( $row['category_name'] ?? '' ),
            'category_slug' => (string) ( $row['category_slug'] ?? '' ),
            'module_key' => (string) ( $row['module_key'] ?? '' ),
            'action_key' => (string) ( $row['action_key'] ?? '' ),
            'tags' => $this->decodeTags( $row['tags'] ?? '' ),
            'search_terms' => (string) ( $row['search_terms'] ?? '' ),
            'status' => (string) ( $row['status'] ?? 'draft' ),
            'seed_key' => (string) ( $row['seed_key'] ?? '' ),
            'system_seeded' => (int) ( $row['system_seeded'] ?? 0 ),
            'created_at' => (string) ( $row['created_at'] ?? '' ),
            'updated_at' => (string) ( $row['updated_at'] ?? '' ),
            'url' => $this->articleUrl( (string) ( $row['slug'] ?? '' ) ),
        ];
    }

    private function detectModuleAction( string $query ): array {
        $normalized = strtolower( $this->searchableText( $query ) );
        if ( $normalized === '' ) {
            return [
                'module_key' => '',
                'action_key' => '',
            ];
        }

        $moduleMap = [
            'accounting' => [ 'accounting', 'general ledger', 'gl', 'journal entry', 'ledger', 'finance entry' ],
            'donations' => [ 'donation', 'deposit batch', 'deposit', 'gift' ],
            'newsletter' => [ 'newsletter', 'email campaign', 'test email', 'mailing' ],
            'website' => [ 'website', 'page', 'publish page', 'web page' ],
            'files' => [ 'file', 'upload', 'document', 'asset' ],
            'calendar' => [ 'calendar', 'event', 'meeting' ],
            'people' => [ 'person record', 'contact', 'people', 'directory' ],
            'reports' => [ 'report', 'dashboard export', 'analytics' ],
            'settings' => [ 'settings', 'configuration', 'feature flag', 'module access' ],
            'roles' => [ 'role', 'permission', 'access' ],
            'search' => [ 'search', 'find results', 'no results' ],
        ];
        $actionMap = [
            'create_gl_entry' => [ 'create gl entry', 'new gl entry', 'create general ledger entry', 'journal entry', 'add journal entry' ],
            'save_gl_entry' => [ 'save gl entry', 'save entry' ],
            'post_gl_entry' => [ 'post gl entry', 'post entry' ],
            'create_donation' => [ 'create donation', 'new donation' ],
            'edit_donation' => [ 'edit donation', 'update donation' ],
            'create_deposit_batch' => [ 'create deposit batch', 'new deposit batch', 'deposit batch' ],
            'send_newsletter' => [ 'send newsletter', 'publish newsletter' ],
            'send_test_email' => [ 'test email', 'send test email', 'newsletter test email' ],
            'publish_page' => [ 'publish page', 'publish website page', 'go live page' ],
            'edit_page' => [ 'edit page', 'website page edit' ],
            'upload_file' => [ 'upload file', 'attach file' ],
            'find_file' => [ 'find file', 'locate file' ],
            'create_calendar_event' => [ 'create calendar event', 'new event', 'schedule event' ],
            'assign_role' => [ 'assign role', 'change role' ],
            'find_person_record' => [ 'find person', 'find person record', 'locate contact' ],
            'run_report' => [ 'run report', 'open report', 'generate report' ],
            'export_report' => [ 'export report', 'download report' ],
            'save_settings' => [ 'save settings', 'update settings' ],
            'access_module' => [ 'access module', 'open module', 'see module' ],
            'permission_denied' => [ 'permission denied', 'access denied', 'not allowed' ],
            'button_noop' => [ 'button does nothing', 'click nothing happens' ],
            'page_load' => [ 'page will not load', 'page wont load', 'loading page' ],
            'search_no_results' => [ 'search shows no results', 'no results', 'cannot find results' ],
        ];

        $moduleKey = '';
        foreach ( $moduleMap as $candidate => $terms ) {
            foreach ( $terms as $term ) {
                if ( str_contains( $normalized, $term ) ) {
                    $moduleKey = $candidate;
                    break 2;
                }
            }
        }

        $actionKey = '';
        foreach ( $actionMap as $candidate => $terms ) {
            foreach ( $terms as $term ) {
                if ( str_contains( $normalized, $term ) ) {
                    $actionKey = $candidate;
                    break 2;
                }
            }
        }

        return [
            'module_key' => $moduleKey,
            'action_key' => $actionKey,
        ];
    }

    private function articleUrl( string $slug ): string {
        $path = '/admin/help/article/' . ltrim( $slug, '/' );
        return $this->appUrl( $path );
    }

    private function userFacingWhereClause( string $alias ): string {
        $alias = trim( $alias ) !== '' ? trim( $alias ) : 'a';
        return sprintf(
            "(%s.system_seeded = 1 OR %s.seed_key IS NOT NULL OR %s.object_code REGEXP '^HLP[0-9]{6}$')",
            $alias,
            $alias,
            $alias
        );
    }

    private function db(): DatabaseService {
        return $this->database instanceof DatabaseService ? $this->database : \metis_db();
    }

    private function appUrl( string $path ): string {
        $path = '/' . ltrim( $path, '/' );

        if ( defined( 'METIS_URL' ) ) {
            return rtrim( (string) \METIS_URL, '/' ) . $path;
        }

        return function_exists( 'metis_home_url' ) ? (string) \metis_home_url( $path ) : $path;
    }
}
