<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Website\Services\PostService;
use Metis\Modules\Website\Services\PostCategoryService;

require_once __DIR__ . '/_editor_bootstrap.php';

$per_page = 100;
$current_page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$total_posts = PostService::countAll();
$page_count = max( 1, (int) ceil( $total_posts / $per_page ) );
$current_page = min( $current_page, $page_count );
$posts = PostService::getAll(
    [
        'limit' => $per_page,
        'offset' => ( $current_page - 1 ) * $per_page,
    ]
);
$date_format = class_exists( 'Core_Settings_Service' ) ? trim( (string) \Core_Settings_Service::get( 'date_format', 'M j, Y' ) ) : 'M j, Y';
if ( $date_format === '' ) {
    $date_format = 'M j, Y';
}
$editor_post_id = (int) metis_get_query_var( 'metis_editor_post_id' );
$editor_new = (string) metis_get_query_var( 'metis_editor_new' );
$request_path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ?? '' );
if ( $editor_new === '' && preg_match( '#/website/posts/editor/new/?$#i', $request_path ) === 1 ) {
    $editor_new = 'post';
}
if ( $editor_post_id < 1 ) {
    $matches = [];
    if ( preg_match( '#/website/posts/editor/([A-Za-z0-9_-]+)/?$#i', $request_path, $matches ) === 1 && ! empty( $matches[1] ) ) {
        $raw_ref = (string) $matches[1];
        if ( ctype_digit( $raw_ref ) ) {
            $editor_post_id = (int) $raw_ref;
        } elseif ( class_exists( PostService::class ) ) {
            $post = PostService::getByCode( $raw_ref );
            $editor_post_id = $post !== null ? (int) ( $post->id ?? 0 ) : 0;
        }
    }
}
$is_editor_route = $editor_post_id > 0 || $editor_new === 'post';

if ( $is_editor_route ) {
    $editor_target_id = $editor_post_id > 0 ? $editor_post_id : 0;
    metis_website_render_editor_bootstrap(
        [
            'editor_new' => $editor_new,
            'editor_key' => '',
            'editor_id' => $editor_target_id,
            'editor_context' => 'post',
            'editor_kind' => '',
            'editor_page_id' => 0,
            'editor_post_id' => $editor_post_id,
        ]
    );
    return;
}
?>
<div id="mwpb-inline-root" style="<?php echo $is_editor_route ? '' : 'display:none;'; ?>"></div>
<div id="metis-posts-list-shell" style="<?php echo $is_editor_route ? 'display:none;' : ''; ?>">
<div class="mw-page-header">
    <div class="mw-page-header-left">
        <h1 class="mw-page-title">Posts</h1>
        <p class="mw-subtitle"><?php echo metis_escape_html( $total_posts ); ?> post<?php echo $total_posts !== 1 ? 's' : ''; ?> in website content.</p>
    </div>
    <div class="mw-page-header-right">
        <button class="mw-btn mw-btn-primary" id="metis-create-post-btn">
            <svg style="width:14px;height:14px;margin-right:6px;vertical-align:-2px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New Post
        </button>
    </div>
</div>

<div class="metis-table-wrap">
    <?php if ( empty( $posts ) ) : ?>
        <div class="metis-empty-state">
            <div class="metis-empty-state-icon">&#9997;</div>
            <h2>No posts yet</h2>
            <p>Create your first post, assign it a category, and publish it when the route is ready.</p>
            <button class="mw-btn mw-btn-primary" id="metis-create-post-btn-empty">New Post</button>
        </div>
    <?php else : ?>
        <table class="metis-table metis-posts-table-compact">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Published</th>
                    <th class="mw-col-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $posts as $post ) : ?>
                    <?php
                    $public_path = method_exists( PostService::class, 'publicPath' )
                        ? (string) PostService::publicPath( $post )
                        : '';
                    $public_url = function_exists( 'metis_home_url' ) ? (string) metis_home_url( $public_path ) : $public_path;
                    $slug_path = '/' . ltrim( (string) ( $post->slug ?? '' ), '/' );
                    $category_ids = array_values( array_unique( array_filter( array_map( 'intval', is_array( $post->post_category_ids ?? null ) ? $post->post_category_ids : [] ) ) ) );
                    if ( $category_ids === [] && isset( $post->post_category_id ) && (int) $post->post_category_id > 0 ) {
                        $category_ids = [ (int) $post->post_category_id ];
                    }
                    $category_labels = [];
                    foreach ( $category_ids as $category_id ) {
                        $label = method_exists( PostCategoryService::class, 'categoryNameById' )
                            ? trim( (string) PostCategoryService::categoryNameById( $category_id ) )
                            : '';
                        if ( $label !== '' ) {
                            $category_labels[] = $label;
                        }
                    }
                    $published_label = '—';
                    if ( ! empty( $post->publish_date ) ) {
                        $timestamp = strtotime( (string) $post->publish_date );
                        if ( $timestamp !== false && $timestamp > 0 ) {
                            $published_label = function_exists( 'metis_runtime_date' )
                                ? (string) metis_runtime_date( $date_format, (int) $timestamp )
                                : gmdate( 'M j, Y', (int) $timestamp );
                        }
                    }
                    ?>
                    <tr>
                        <td class="metis-posts-table-compact__title-cell">
                            <button type="button" class="metis-link-button metis-edit-post" data-id="<?php echo metis_escape_attr( (string) $post->id ); ?>" data-code="<?php echo metis_escape_attr( (string) ( $post->post_code ?? '' ) ); ?>"><?php echo metis_escape_html( $post->title ); ?></button>
                            <div class="metis-posts-table-compact__slug"><?php echo metis_escape_html( $slug_path ); ?></div>
                            <div class="metis-posts-table-compact__category-list metis-posts-table-compact__category-list--under-title">
                                <?php if ( $category_labels !== [] ) : ?>
                                    <?php foreach ( $category_labels as $category_label ) : ?>
                                        <span class="metis-posts-table-compact__category-chip"><?php echo metis_escape_html( $category_label ); ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="metis-posts-table-compact__category-chip">Uncategorized</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="metis-status metis-status-<?php echo metis_escape_attr( $post->status ); ?>"><?php echo metis_escape_html( ucfirst( $post->status ) ); ?></span></td>
                        <td class="metis-posts-table-compact__published">
                            <?php echo metis_escape_html( $published_label ); ?>
                        </td>
                        <td class="mw-col-right">
                            <div class="metis-table-actions">
                                <button class="metis-action-btn metis-edit-post" data-id="<?php echo metis_escape_attr( (string) $post->id ); ?>" data-code="<?php echo metis_escape_attr( (string) ( $post->post_code ?? '' ) ); ?>" title="Edit in editor">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <?php if ( $post->status === 'draft' ) : ?>
                                <button class="metis-action-btn metis-action-btn-primary metis-publish-post" data-id="<?php echo metis_escape_attr( (string) $post->id ); ?>" title="Publish">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </button>
                                <?php endif; ?>
                                <?php if ( $post->status === 'published' && $public_path !== '' ) : ?>
                                <a href="<?php echo metis_escape_attr( $public_url ); ?>" class="metis-action-btn" title="View live" target="_blank" rel="noopener">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                </a>
                                <?php endif; ?>
                                <button class="metis-action-btn metis-action-btn-danger metis-delete-post" data-id="<?php echo metis_escape_attr( (string) $post->id ); ?>" title="Delete">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $post_base_params = $_GET;
        unset( $post_base_params['paged'] );
        $post_base_path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ?? '' );
        $post_link = static function ( int $target_page ) use ( $post_base_params, $post_base_path ): string {
            $query = $post_base_params;
            if ( $target_page > 1 ) {
                $query['paged'] = $target_page;
            }
            $query_string = http_build_query( $query );
            return $post_base_path . ( $query_string !== '' ? '?' . $query_string : '' );
        };
        ?>
        <?php if ( $page_count > 1 ) : ?>
            <div class="mw-pagination" style="margin-top:16px;">
                <?php if ( $current_page > 1 ) : ?>
                    <a class="mw-btn-xs" href="<?php echo metis_escape_url( $post_link( $current_page - 1 ) ); ?>">Prev</a>
                <?php else : ?>
                    <span class="mw-btn-xs" aria-disabled="true">Prev</span>
                <?php endif; ?>
                <span class="mw-muted">Page <?php echo metis_escape_html( (string) $current_page ); ?> of <?php echo metis_escape_html( (string) $page_count ); ?></span>
                <?php if ( $current_page < $page_count ) : ?>
                    <a class="mw-btn-xs" href="<?php echo metis_escape_url( $post_link( $current_page + 1 ) ); ?>">Next</a>
                <?php else : ?>
                    <span class="mw-btn-xs" aria-disabled="true">Next</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<script>
(function bootPostsView() {
    'use strict';
    if (!window.jQuery) {
        window.setTimeout(bootPostsView, 50);
        return;
    }
    var $ = window.jQuery;
    $(document).on('click', '#metis-create-post-btn-empty', function() {
        $('#metis-create-post-btn').trigger('click');
    });

    $(function() {
        try {
            var params = new URLSearchParams(window.location.search || '');
            if (String(params.get('qa') || '') === 'create_post') {
                $('#metis-create-post-btn').trigger('click');
            }
        } catch (_error) {}
    });
})();
</script>
