<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Modules\Website\Entities\Post;

final class PostsListService {
    /**
     * @param array<string,mixed> $config
     * @return array{limit:int,source:string,specific_page:int,category_ids:array<int,int>,tag_ids:array<int,int>,sort:string}
     */
    public static function normalizeConfig( array $config ): array {
        $limit = isset( $config['limit'] ) ? (int) $config['limit'] : (int) ( $config['count'] ?? 5 );
        $limit = max( 1, min( 50, $limit ) );

        $source = metis_key_clean( (string) ( $config['source'] ?? 'this_page' ) );
        if ( ! in_array( $source, [ 'this_page', 'specific_page' ], true ) ) {
            $source = 'this_page';
        }

        $specific_page = isset( $config['specific_page'] )
            ? (int) $config['specific_page']
            : (int) ( $config['parent_page_id'] ?? 0 );
        if ( $specific_page < 1 ) {
            $specific_page = 0;
        }
        if ( $source !== 'specific_page' ) {
            $specific_page = 0;
        }

        $category_ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        is_array( $config['category_ids'] ?? null ) ? $config['category_ids'] : []
                    ),
                    static fn( int $id ): bool => $id > 0
                )
            )
        );

        $tag_ids = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        is_array( $config['tag_ids'] ?? null ) ? $config['tag_ids'] : []
                    ),
                    static fn( int $id ): bool => $id > 0
                )
            )
        );

        $sort = metis_key_clean( (string) ( $config['sort'] ?? 'latest' ) );
        if ( $sort !== 'latest' ) {
            $sort = 'latest';
        }

        return [
            'limit' => $limit,
            'source' => $source,
            'specific_page' => $specific_page,
            'category_ids' => $category_ids,
            'tag_ids' => $tag_ids,
            'sort' => $sort,
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function buildPublishedQuery( array $config, array $context = [] ): array {
        $normalized = self::normalizeConfig( $config );
        $query = [ 'limit' => $normalized['limit'] ];
        $has_category_filter = $normalized['category_ids'] !== [];

        if ( $has_category_filter ) {
            $query['post_category_ids'] = $normalized['category_ids'];
        }
        if ( $normalized['tag_ids'] !== [] ) {
            $query['post_tag_ids'] = $normalized['tag_ids'];
        }

        if ( $normalized['source'] === 'specific_page' && $normalized['specific_page'] > 0 ) {
            $query['parent_page_id'] = $normalized['specific_page'];
        } elseif (
            ! $has_category_filter
            && $normalized['source'] === 'this_page'
            && metis_key_clean( (string) ( $context['content_type'] ?? '' ) ) === 'page'
            && (int) ( $context['content_id'] ?? 0 ) > 0
            && empty( $context['is_homepage'] )
        ) {
            $query['parent_page_id'] = (int) $context['content_id'];
        } elseif (
            $normalized['source'] === 'this_page'
            && ! $has_category_filter
        ) {
            $query['parent_page_id'] = 0;
        }

        return $query;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $context
     * @return array<int,Post>
     */
    public static function getPublishedPosts( array $config, array $context = [] ): array {
        $posts = PostService::getPublished( self::buildPublishedQuery( $config, $context ) );

        return array_values(
            array_filter(
                $posts,
                static fn( mixed $post ): bool => $post instanceof Post && PostService::publicPath( $post ) !== ''
            )
        );
    }
}
