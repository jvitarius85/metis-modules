<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Entities {
    final class Post {}
}

namespace Metis\Modules\Website\Services {
    final class PostService {
        public static function getPublished( array $query = [] ): array {
            return [];
        }

        public static function publicPath( mixed $post ): string {
            return '';
        }
    }
}

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        fwrite( STDERR, "This test must be run from the command line.\n" );
        exit( 1 );
    }

    function metis_key_clean( string $value ): string {
        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_]+/', '_', $value ) ?? '';
    }

    $root = dirname( __DIR__ );
    require_once $root . '/src/Metis/Modules/Website/Services/PostsListService.php';

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $query = \Metis\Modules\Website\Services\PostsListService::buildPublishedQuery(
        [
            'source' => 'this_page',
            'category_ids' => [ '4', 7, 'bad', 7 ],
            'limit' => '12',
        ],
        [
            'content_type' => 'page',
            'content_id' => 42,
            'is_homepage' => false,
        ]
    );

    $specificPageQuery = \Metis\Modules\Website\Services\PostsListService::buildPublishedQuery(
        [
            'source' => 'specific_page',
            'specific_page' => '19',
            'count' => '8',
            'category_ids' => [ '5' ],
        ],
        [
            'content_type' => 'page',
            'content_id' => 42,
            'is_homepage' => false,
        ]
    );

    $homepageQuery = \Metis\Modules\Website\Services\PostsListService::buildPublishedQuery(
        [
            'source' => 'this_page',
            'category_ids' => [ '9' ],
        ],
        [
            'content_type' => 'page',
            'content_id' => 77,
            'is_homepage' => true,
        ]
    );

    $blockRendererSource = (string) file_get_contents( $root . '/src/Metis/Modules/Website/Services/BlockRenderer.php' );
    $websiteRendererSource = (string) file_get_contents( $root . '/src/Metis/Modules/Website/Services/WebsiteRenderer.php' );
    $editorSource = (string) file_get_contents( $root . '/assets/js/editor/simple-editor.js' );

    $assert( ( $query['limit'] ?? 0 ) === 12, 'Posts list query should keep the configured limit.' );
    $assert( ! isset( $query['parent_page_id'] ), 'Category-filtered this-page posts lists should not force a current-page parent filter.' );
    $assert( ( $query['post_category_ids'] ?? [] ) === [ 4, 7 ], 'Posts list query should normalize category IDs.' );

    $assert( ( $specificPageQuery['limit'] ?? 0 ) === 8, 'Specific-page posts list should fall back to count when limit is absent.' );
    $assert( ( $specificPageQuery['parent_page_id'] ?? 0 ) === 19, 'Specific-page posts list should scope to the chosen page.' );
    $assert( ( $specificPageQuery['post_category_ids'] ?? [] ) === [ 5 ], 'Specific-page posts list should preserve selected categories.' );

    $assert( ! isset( $homepageQuery['parent_page_id'] ), 'Homepage posts lists should not force a current-page parent filter.' );
    $assert( ( $homepageQuery['post_category_ids'] ?? [] ) === [ 9 ], 'Homepage posts lists should still apply category filters.' );

    $thisPageWithoutCategories = \Metis\Modules\Website\Services\PostsListService::buildPublishedQuery(
        [
            'source' => 'this_page',
            'limit' => 6,
            'category_ids' => [],
        ],
        [
            'content_type' => 'page',
            'content_id' => 42,
            'is_homepage' => false,
        ]
    );

    $assert( ( $thisPageWithoutCategories['parent_page_id'] ?? 0 ) === 42, 'Unfiltered this-page posts lists should still scope to the current page.' );

    $assert(
        str_contains( $blockRendererSource, 'PostsListService::getPublishedPosts' ),
        'BlockRenderer post list rendering must use the shared posts list service.'
    );
    $assert(
        str_contains( $websiteRendererSource, 'PostsListService::getPublishedPosts' ),
        'WebsiteRenderer structured posts list rendering must use the shared posts list service.'
    );
    $assert(
        str_contains( $editorSource, 'var richSelections = sharedRichSelections;' ),
        'Simple editor rich selection state must use the shared selection store so emoji insertion restores the active cursor.'
    );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "Website posts list service checks passed.\n" );
}
