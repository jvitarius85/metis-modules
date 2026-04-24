<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

\Metis\Modules\Website\WebsiteModule::boot();

// ---------------------------------------------------------------------------
// Admin editor rewrite routes
// /{portal}/website/pages/editor/{id|new}
// /{portal}/website/posts/editor/{id|new}
// ---------------------------------------------------------------------------
metis_on( 'init', static function (): void {
    if ( ! function_exists( 'metis_portal_slug' ) || ! function_exists( 'metis_add_rewrite_rule' ) || ! function_exists( 'metis_add_rewrite_tag' ) ) {
        return;
    }

    $slug = trim( (string) metis_portal_slug(), '/' );
    if ( $slug === '' ) {
        return;
    }

    metis_add_rewrite_tag( '%metis_editor_page_id%', '([0-9]+)' );
    metis_add_rewrite_tag( '%metis_editor_post_id%', '([0-9]+)' );
    metis_add_rewrite_tag( '%metis_editor_key%', '([A-Za-z0-9_-]+)' );
    metis_add_rewrite_tag( '%metis_editor_new%', '(page|post|newsletter_campaign|newsletter_template)' );
    metis_add_rewrite_tag( '%metis_editor_context%', '([a-z_]+)' );
    metis_add_rewrite_tag( '%metis_editor_kind%', '([a-z_]+)' );
    metis_add_rewrite_tag( '%metis_shell%', '([a-z_]+)' );
    metis_add_rewrite_tag( '%metis_template_setup%', '(new|edit)' );
    metis_add_rewrite_tag( '%metis_template_setup_key%', '([A-Za-z0-9_-]+)' );

    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/([A-Za-z0-9-]+)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_key=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/new/(page|post)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_new=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/new/template/?$',
        'index.php?metis_domain=website&metis_view=templates&metis_template_setup=new',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/new/newsletter/campaign/?$',
        'index.php?metis_domain=newsletter&metis_view=editor&metis_editor_new=newsletter_campaign&metis_editor_context=newsletter&metis_editor_kind=campaign&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/new/newsletter/template/?$',
        'index.php?metis_domain=newsletter&metis_view=editor&metis_editor_new=newsletter_template&metis_editor_context=newsletter&metis_editor_kind=template&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/newsletter/campaign/([A-Za-z0-9_-]+)/?$',
        'index.php?metis_domain=newsletter&metis_view=editor&metis_editor_key=$matches[1]&metis_editor_context=newsletter&metis_editor_kind=campaign&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/newsletter/template/([A-Za-z0-9_-]+)/?$',
        'index.php?metis_domain=newsletter&metis_view=editor&metis_editor_key=$matches[1]&metis_editor_context=newsletter&metis_editor_kind=template&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/page/([0-9]+)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_page_id=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/post/([0-9]+)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_post_id=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/editor/template/([A-Za-z0-9_-]+)/?$',
        'index.php?metis_domain=website&metis_view=templates&metis_template_setup=edit&metis_template_setup_key=$matches[1]',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/editor/([A-Za-z0-9-]+)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_key=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/editor/new/(page|post)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_new=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/editor/new/template/?$',
        'index.php?metis_domain=website&metis_view=templates&metis_template_setup=new',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/editor/new/newsletter/campaign/?$',
        'index.php?metis_domain=newsletter&metis_view=editor&metis_editor_new=newsletter_campaign&metis_editor_context=newsletter&metis_editor_kind=campaign&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/editor/new/newsletter/template/?$',
        'index.php?metis_domain=newsletter&metis_view=editor&metis_editor_new=newsletter_template&metis_editor_context=newsletter&metis_editor_kind=template&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/editor/newsletter/campaign/([A-Za-z0-9_-]+)/?$',
        'index.php?metis_domain=newsletter&metis_view=editor&metis_editor_key=$matches[1]&metis_editor_context=newsletter&metis_editor_kind=campaign&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/editor/newsletter/template/([A-Za-z0-9_-]+)/?$',
        'index.php?metis_domain=newsletter&metis_view=editor&metis_editor_key=$matches[1]&metis_editor_context=newsletter&metis_editor_kind=template&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/editor/template/([A-Za-z0-9_-]+)/?$',
        'index.php?metis_domain=website&metis_view=templates&metis_template_setup=edit&metis_template_setup_key=$matches[1]',
        'top'
    );
    // Legacy compatibility routes
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/pages/editor/new/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_new=page&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/pages/editor/([0-9]+)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_page_id=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/pages/editor/([A-Za-z0-9-]+)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_key=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/posts/editor/([0-9]+)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_post_id=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/posts/editor/([A-Za-z0-9-]+)/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_key=$matches[1]&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/posts/editor/new/?$',
        'index.php?metis_domain=website&metis_view=editor&metis_editor_new=post&metis_shell=editor',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/templates/new/?$',
        'index.php?metis_domain=website&metis_view=templates&metis_template_setup=new',
        'top'
    );
    metis_add_rewrite_rule(
        '^' . preg_quote( $slug, '#' ) . '/website/templates/edit/([A-Za-z0-9_-]+)/?$',
        'index.php?metis_domain=website&metis_view=templates&metis_template_setup=edit&metis_template_setup_key=$matches[1]',
        'top'
    );

    if ( function_exists( 'metis_get_option' ) && function_exists( 'metis_update_option' ) && function_exists( 'metis_flush_rewrite_rules' ) ) {
        $key      = 'metis_website_editor_rewrite_signature';
        $current  = sha1( 'website-editor-routes-v14:' . $slug );
        $previous = (string) metis_get_option( $key, '' );
        if ( $previous !== $current ) {
            metis_flush_rewrite_rules( false );
            metis_update_option( $key, $current, false );
        }
    }
}, 15 );

// ---------------------------------------------------------------------------
// Route handlers — defined here so they exist before the router is built.
// Routes are registered on the 'init' hook so the router runtime is ready.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/routes/routes.php';

// Ensure editor routes always render in standalone editor shell mode, even if
// upstream rewrite/query-var handling drops custom vars.
metis_on( 'template_redirect', static function (): void {
    if ( ! function_exists( 'metis_set_query_var' ) ) {
        return;
    }
    $path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ?? '' );
    if (
        (
            preg_match( '#/editor(?:/|$)#i', $path ) === 1
            && preg_match( '#/newsletter/editor(?:/|$)#i', $path ) !== 1
        )
        || preg_match( '#/website/editor(?:/|$)#i', $path ) === 1
        || preg_match( '#/website/pages/editor(?:/|$)#i', $path ) === 1
        || preg_match( '#/website/posts/editor(?:/|$)#i', $path ) === 1
    ) {
        metis_set_query_var( 'metis_shell', 'editor' );
    }
}, 5 );

// Register public-facing website routes programmatically.
// These cannot be declared in module.json because the handler functions would
// not yet exist when the manifest validator runs during the early boot phase.
metis_on( 'init', static function (): void {
    if ( ! function_exists( 'metis_http_router' ) ) {
        return;
    }

    $router = metis_http_router();
    $normalize_public_path = static function ( \Metis_Http_Request $request ): string {
        $path = '/' . ltrim( (string) $request->path(), '/' );
        $base_path = '/';

        $script_name = (string) ( $_SERVER['SCRIPT_NAME'] ?? '' );
        $script_dir = trim( dirname( $script_name ), '/' );
        if ( $script_dir !== '' && $script_dir !== '.' ) {
            $base_path = '/' . $script_dir;
        } elseif ( function_exists( 'metis_home_url' ) ) {
            $home_path = (string) ( parse_url( (string) metis_home_url( '/' ), PHP_URL_PATH ) ?? '/' );
            $home_path = '/' . trim( $home_path, '/' );
            if ( $home_path === '//' ) {
                $home_path = '/';
            }
            $base_path = $home_path;
        }

        if ( $base_path !== '/' && str_starts_with( $path, $base_path . '/' ) ) {
            $path = substr( $path, strlen( $base_path ) );
            $path = $path === '' ? '/' : $path;
        } elseif ( $base_path !== '/' && $path === $base_path ) {
            $path = '/';
        }

        return $path === '' ? '/' : $path;
    };

    // Generated stylesheet endpoint for public website rendering.
    $router->register(
        'website.theme_css',
        [ 'GET', 'HEAD' ],
        static function ( \Metis_Http_Request $request ) use ( $normalize_public_path ): ?array {
            return preg_match( '#^/v1/website/theme\.css$#i', $normalize_public_path( $request ) ) === 1 ? [] : null;
        },
        'metis_website_handle_theme_css_route'
    );

    // Homepage
    $router->register(
        'website.homepage',
        [ 'GET', 'HEAD' ],
        static function ( \Metis_Http_Request $request ) use ( $normalize_public_path ): ?array {
            return $normalize_public_path( $request ) === '/' ? [] : null;
        },
        'metis_website_handle_homepage_route'
    );

    // Generic content path — lowest priority, checked last
    $router->register(
        'website.page',
        [ 'GET', 'HEAD' ],
        static function ( \Metis_Http_Request $request ) use ( $normalize_public_path ): ?array {
            $matches = [];
            if ( preg_match( '#^/(?P<path>[a-z0-9-]+(?:/[a-z0-9-]+)*)/?$#i', $normalize_public_path( $request ), $matches ) !== 1 ) {
                return null;
            }
            $path = '/' . trim( (string) ( $matches['path'] ?? '' ), '/' );
            if ( $path === '//' || $path === '' ) {
                return null;
            }
            return [ 'path' => $path ];
        },
        'metis_website_handle_page_route'
    );
}, 20 ); // priority 20 — after core routes (priority 1) but before request dispatch

// Load AJAX handlers
require_once __DIR__ . '/ajax/website.ajax.php';
