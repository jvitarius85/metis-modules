<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Modules\Cms\SchemaManager;
use Metis\Modules\Cms\Services\PostService;

final class HermesCmsAdminService {
    public function createPost( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $title = trim( (string) ( $request['title'] ?? '' ) );
        $status = \metis_key_clean( (string) ( $request['status'] ?? 'draft' ) );
        if ( $status === '' ) {
            $status = 'draft';
        }

        if ( $title === '' ) {
            throw new \RuntimeException( 'A post title is required.' );
        }

        SchemaManager::ensureSchema();
        $post = PostService::create( [
            'title' => $title,
            'slug' => (string) ( $request['slug'] ?? '' ),
            'excerpt' => (string) ( $request['excerpt'] ?? '' ),
            'status' => in_array( $status, [ 'draft', 'published', 'archived' ], true ) ? $status : 'draft',
        ] );

        if ( $post === null ) {
            throw new \RuntimeException( 'Failed to create cms post.' );
        }

        return [
            'status' => 'success',
            'post' => [
                'id' => (int) ( $post->id ?? 0 ),
                'post_code' => (string) ( $post->post_code ?? '' ),
                'title' => (string) ( $post->title ?? '' ),
                'slug' => (string) ( $post->slug ?? '' ),
                'status' => (string) ( $post->status ?? 'draft' ),
            ],
            'message' => sprintf( 'Created post "%s".', (string) ( $post->title ?? $title ) ),
        ];
    }

    public function publishPost( mixed $request = null ): array {
        $request = is_array( $request ) ? $request : [];
        $subject = trim( (string) ( $request['subject'] ?? '' ) );
        if ( $subject === '' ) {
            throw new \RuntimeException( 'Specify a post title, slug, or post code to publish.' );
        }

        SchemaManager::ensureSchema();
        $post = $this->resolvePost( $subject );
        if ( $post === null || (int) ( $post->id ?? 0 ) < 1 ) {
            throw new \RuntimeException( 'No matching post was found.' );
        }

        $ok = PostService::publish( (int) $post->id );
        if ( ! $ok ) {
            throw new \RuntimeException( 'Failed to publish post.' );
        }

        $fresh = PostService::getById( (int) $post->id );

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

    private function resolvePost( string $subject ): ?object {
        $subject = trim( $subject );
        if ( $subject === '' ) {
            return null;
        }

        if ( preg_match( '/^CMSP[A-Z0-9]+$/i', $subject ) ) {
            $table = \Metis_Tables::get( 'cms_posts' );
            $row = \metis_db()->fetchOne(
                "SELECT id
                 FROM {$table}
                 WHERE post_code = %s
                 LIMIT 1",
                [ strtoupper( $subject ) ]
            );
            if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
                return PostService::getById( (int) $row['id'] );
            }
        }

        $bySlug = PostService::getBySlug( $subject );
        if ( $bySlug !== null ) {
            return $bySlug;
        }

        $table = \Metis_Tables::get( 'cms_posts' );
        $row = \metis_db()->fetchOne(
            "SELECT id
             FROM {$table}
             WHERE LOWER(COALESCE(title, '')) = %s
             ORDER BY id DESC
             LIMIT 1",
            [ strtolower( $subject ) ]
        );

        if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) > 0 ) {
            return PostService::getById( (int) $row['id'] );
        }

        return null;
    }
}
