<?php
declare(strict_types=1);

namespace Metis\Modules\Website;

use Metis\Core\Application;

/**
 * Website Module
 * 
 * Manages website pages, posts, menus, popups, and themes.
 * Provides visual builder and import capabilities.
 */
final class WebsiteModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );

        // Boot block registry (no DB dependency)
        BlockRegistry::boot();

        \Metis_Logger::info( 'Website module booted' );
    }

    /**
     * Check if user can view Website module
     */
    public static function canView(): bool {
        if ( ! function_exists( 'metis_security_user_can' ) ) {
            return false;
        }

        return \metis_security_user_can( 'website.view' );
    }

    /**
     * Check if user can manage Website content
     */
    public static function canManage(): bool {
        if ( ! function_exists( 'metis_security_user_can' ) ) {
            return false;
        }

        return \metis_security_user_can( 'website.edit' );
    }

    /**
     * Get database service
     */
    public static function db(): object {
        return Application::service( 'db' );
    }

    public static function ensureRuntimeSchema(): void {
        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'website_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        SchemaManager::ensureSchema();
    }

    public static function createPost( array $request ): array {
        $title = trim( (string) ( $request['title'] ?? '' ) );
        $status = \metis_key_clean( (string) ( $request['status'] ?? 'draft' ) );
        if ( $status === '' ) {
            $status = 'draft';
        }

        if ( $title === '' ) {
            throw new \RuntimeException( 'A post title is required.' );
        }

        $post = Services\PostService::create( [
            'title' => $title,
            'slug' => (string) ( $request['slug'] ?? '' ),
            'excerpt' => (string) ( $request['excerpt'] ?? '' ),
            'status' => in_array( $status, [ 'draft', 'published', 'archived' ], true ) ? $status : 'draft',
        ] );

        if ( $post === null ) {
            throw new \RuntimeException( 'Failed to create website post.' );
        }

        return [
            'status' => 'success',
            'post' => self::postSummary( $post, $title ),
            'message' => sprintf( 'Created post "%s".', (string) ( $post->title ?? $title ) ),
        ];
    }

    public static function publishPost( array $request ): array {
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        if ( $subject === '' ) {
            throw new \RuntimeException( 'Specify a post title, slug, or post code to publish.' );
        }

        $post = self::resolvePost( $subject );
        if ( $post === null || (int) ( $post->id ?? 0 ) < 1 ) {
            throw new \RuntimeException( 'No matching post was found.' );
        }

        if ( ! Services\PostService::publish( (int) $post->id ) ) {
            throw new \RuntimeException( 'Failed to publish post.' );
        }

        $fresh = Services\PostService::getById( (int) $post->id );

        return [
            'status' => 'success',
            'post' => [
                'id' => (int) ( $fresh->id ?? $post->id ?? 0 ),
                'post_code' => (string) ( $fresh->post_code ?? $post->post_code ?? '' ),
                'title' => (string) ( $fresh->title ?? $post->title ?? '' ),
                'slug' => (string) ( $fresh->slug ?? $post->slug ?? '' ),
                'status' => (string) ( $fresh->status ?? 'published' ),
                'publish_date' => (string) ( $fresh->publish_date ?? '' ),
            ],
            'message' => sprintf( 'Published post "%s".', (string) ( $fresh->title ?? $post->title ?? 'post' ) ),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function saveDraft( string $entityType, int $entityId, array $data ): bool {
        if ( $entityType === 'page' ) {
            return Services\PageService::update( $entityId, [
                'title' => $data['title'] ?? null,
                'slug' => $data['slug'] ?? null,
                'draft_layout_json' => $data['layout_json'] ?? null,
                'status' => 'draft',
            ] );
        }

        if ( $entityType === 'post' ) {
            return Services\PostService::update( $entityId, [
                'title' => $data['title'] ?? null,
                'slug' => $data['slug'] ?? null,
                'draft_content_json' => $data['content_json'] ?? null,
                'excerpt' => $data['excerpt'] ?? null,
                'status' => 'draft',
            ] );
        }

        return false;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{document_html:string,content_html:string,context:array<string,mixed>}
     */
    public static function renderEditorPreview( array $options = [] ): array {
        $layout_json = isset( $options['layout_json'] ) && is_string( $options['layout_json'] )
            ? $options['layout_json']
            : '';

        return Services\WebsiteRenderer::renderStructuredEditorPreview( $layout_json, $options );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function checkpoint( string $entityType, int $entityId, array $payload, string $note = '' ): bool {
        if ( $entityId < 1 ) {
            return false;
        }

        return Services\RevisionTimelineService::save( $entityType, $entityId, $payload, $note );
    }

    public static function saveHomepageSelection( int $homepageId ): array {
        if ( $homepageId < 1 ) {
            if ( class_exists( '\Core_Settings_Service' ) ) {
                \Core_Settings_Service::delete( 'site_homepage_page_id' );
            }

            return [
                'saved' => true,
                'error' => '',
            ];
        }

        $page = Services\PageService::getById( $homepageId );
        if ( $page === null || $page->status !== 'published' ) {
            return [
                'saved' => false,
                'error' => 'Homepage must reference a published website page.',
            ];
        }

        if ( ! Services\HomepageService::setHomepagePageId( $homepageId ) ) {
            return [
                'saved' => false,
                'error' => 'Unable to save homepage selection.',
            ];
        }

        return [
            'saved' => true,
            'error' => '',
        ];
    }

    /**
     * @return array<int,mixed>
     */
    public static function publishedHomepagePages( bool $shouldLoad ): array {
        if ( ! $shouldLoad ) {
            return [];
        }

        return array_values( Services\PageService::getAll( [ 'status' => 'published' ] ) );
    }

    /**
     * Get base URL for Website module
     */
    public static function baseUrl(): string {
        if ( function_exists( 'metis_portal_url' ) ) {
            return (string) metis_portal_url( 'website' );
        }

        return function_exists( 'metis_admin_url' ) ? (string) metis_admin_url( 'admin.php?page=website' ) : '/website/';
    }

    private static function resolvePost( string $subject ): ?object {
        $subject = trim( $subject );
        if ( $subject === '' ) {
            return null;
        }

        if ( preg_match( '/^WBP[A-Z0-9]+$/i', $subject ) ) {
            $table = \Metis_Tables::get( 'website_posts' );
            $row = \metis_db()->fetchOne(
                "SELECT id
                 FROM {$table}
                 WHERE post_code = %s
                 LIMIT 1",
                [ strtoupper( $subject ) ]
            );
            if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
                return Services\PostService::getById( (int) $row['id'] );
            }
        }

        $bySlug = Services\PostService::getBySlug( $subject );
        if ( $bySlug !== null ) {
            return $bySlug;
        }

        $table = \Metis_Tables::get( 'website_posts' );
        $row = \metis_db()->fetchOne(
            "SELECT id
             FROM {$table}
             WHERE LOWER(COALESCE(title, '')) = %s
             ORDER BY id DESC
             LIMIT 1",
            [ strtolower( $subject ) ]
        );

        if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
            return Services\PostService::getById( (int) $row['id'] );
        }

        return null;
    }

    private static function postSummary( ?object $post, string $fallbackTitle = '' ): array {
        return [
            'id' => (int) ( $post->id ?? 0 ),
            'post_code' => (string) ( $post->post_code ?? '' ),
            'title' => (string) ( $post->title ?? $fallbackTitle ),
            'slug' => (string) ( $post->slug ?? '' ),
            'status' => (string) ( $post->status ?? 'draft' ),
        ];
    }
}
