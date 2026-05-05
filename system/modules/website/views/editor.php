<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'editor' ) ) {
    return;
}

use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\PostService;

require_once __DIR__ . '/_editor_bootstrap.php';

$editor_page_id = (int) metis_get_query_var( 'metis_editor_page_id' );
$editor_post_id = (int) metis_get_query_var( 'metis_editor_post_id' );
$editor_key = trim( (string) metis_get_query_var( 'metis_editor_key' ) );
$editor_new = trim( (string) metis_get_query_var( 'metis_editor_new' ) );
$request_path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ?? '' );
$portal_slug = function_exists( 'metis_portal_slug' ) ? trim( (string) metis_portal_slug(), '/' ) : '';
$portal_base = '';
if ( function_exists( 'metis_home_url' ) ) {
    $portal_base = rtrim( (string) metis_home_url( '/' . ltrim( $portal_slug, '/' ) ), '/' );
} elseif ( $portal_slug !== '' ) {
    $portal_base = '/' . $portal_slug;
}

if ( $editor_new === '' ) {
    if ( preg_match( '#/website/page(?:s)?/editor/new/?$#i', $request_path ) === 1 ) {
        $editor_new = 'page';
    } elseif ( preg_match( '#/website/posts/editor/new/?$#i', $request_path ) === 1 ) {
        $editor_new = 'post';
    }
}

$is_template_editor = (
    $editor_new === 'template'
    || preg_match( '#/(?:website/)?editor/template/(?:[A-Za-z0-9_-]+)/?$#i', $request_path ) === 1
    || preg_match( '#/(?:website/)?editor/new/template/?$#i', $request_path ) === 1
);

if ( ! headers_sent() && function_exists( 'metis_safe_redirect' ) ) {
    if ( $is_template_editor ) {
        $template_target = $portal_base . '/website/templates/new/';
        if ( $editor_key !== '' ) {
            $template_target = $portal_base . '/website/templates/edit/' . rawurlencode( $editor_key ) . '/';
        }
        metis_safe_redirect( $template_target );
        exit;
    }
}

$context = ( $editor_post_id > 0 || $editor_new === 'post' || strtoupper( substr( $editor_key, 0, 4 ) ) === 'WBP' ) ? 'website_post' : 'website_page';
$editor_kind = '';

$is_editor_route = (
    $editor_page_id > 0
    || $editor_post_id > 0
    || $editor_key !== ''
    || in_array( $editor_new, [ 'page', 'post' ], true )
);

if ( ! $is_editor_route ) {
    if ( ! headers_sent() && function_exists( 'metis_safe_redirect' ) && function_exists( 'metis_portal_url' ) ) {
        metis_safe_redirect( (string) metis_portal_url( 'website', 'pages' ) );
        exit;
    }
    require __DIR__ . '/pages.php';
    return;
}

$editor_target_id = 0;
if ( $context === 'website_post' ) {
    if ( $editor_post_id > 0 ) {
        $editor_target_id = $editor_post_id;
    } elseif ( $editor_key !== '' && class_exists( PostService::class ) ) {
        $post = PostService::getByCode( $editor_key );
        $editor_target_id = $post !== null ? (int) ( $post->id ?? 0 ) : 0;
    }
} elseif ( $context === 'website_page' ) {
    if ( $editor_page_id > 0 ) {
        $editor_target_id = $editor_page_id;
    } elseif ( $editor_key !== '' && class_exists( PageService::class ) ) {
        $page = PageService::getByCode( $editor_key );
        $editor_target_id = $page !== null ? (int) ( $page->id ?? 0 ) : 0;
    }
}

metis_website_render_editor_bootstrap(
    [
        'editor_new' => $editor_new,
        'editor_key' => $editor_key,
        'editor_id' => $editor_target_id,
        'editor_context' => $context,
        'editor_kind' => $editor_kind,
        'editor_page_id' => $editor_page_id,
        'editor_post_id' => $editor_post_id,
        'include_preview' => true,
    ]
);
