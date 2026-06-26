<?php
declare(strict_types=1);

/**
 * Website Module — Public Route Handlers
 *
 * Handles public-facing routes for pages, posts, and blog index.
 * Slug params are passed as route attributes, not query parameters.
 * Public permission policy and middleware are attached in website/bootstrap.php
 * via route.security before these render-only handlers are invoked.
 */

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Website\Services\WebsiteRenderer;
use Metis\Modules\Website\Services\RedirectService;
use Metis\Core\Error\ErrorPageRenderer;

function metis_website_error_response( int $status, string $message, string $title = '' ): Metis_Http_Response {
    $trace_id = function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : '';
    $renderer = new ErrorPageRenderer();
    $headers  = [ 'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ];
    if ( $trace_id !== '' ) {
        $headers['X-Metis-Trace-Id'] = $trace_id;
        $headers['X-Metis-Request-Id'] = $trace_id;
    }

    return new Metis_Http_Response(
        $status,
        $headers,
        $renderer->render( $status, $trace_id !== '' ? $trace_id : 'public-route', $message, $title )
    );
}

function metis_website_log_route_exception( string $route, \Throwable $e, ?Metis_Http_Request $request = null ): void {
    $trace_id = function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : '';
    $endpoint = $request instanceof Metis_Http_Request
        ? '/' . ltrim( (string) $request->path(), '/' )
        : (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ?? '/' );

    metis_audit_log_security( 'route_action_failed', [
        'module'   => 'website',
        'severity' => 'error',
        'outcome'  => 'failed',
        'resource' => [
            'type'  => 'route_action',
            'id'    => $route,
            'label' => 'route_render_exception',
        ],
        'context'  => [
            'route'         => 'website.public',
            'action'        => $route,
            'endpoint'      => $endpoint,
            'status_code'   => 500,
            'error_code'    => 'route_render_exception',
            'error_message' => 'Website route render failed.',
            'request_id'    => $trace_id,
        ],
    ] );

    $payload = [
        'route' => $route,
        'endpoint' => $endpoint,
        'trace_id' => $trace_id,
        'exception' => get_class( $e ),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];

    if ( class_exists( 'Metis_Logger' ) ) {
        Metis_Logger::error( 'Website public route render exception', $payload );
        return;
    }

    if ( function_exists( 'metis_log' ) ) {
        metis_log( 'Website public route render exception', $payload );
        return;
    }

    $encoded = function_exists( 'metis_json_encode' )
        ? (string) metis_json_encode( $payload )
        : (string) json_encode( $payload, JSON_UNESCAPED_SLASHES );
    $line = '[metis.website.public-route] ' . $encoded;
    error_log( $line );
}

function metis_website_public_html_headers( bool $cacheable = true ): array {
    if ( ! $cacheable ) {
        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }

    return [
        'Content-Type' => 'text/html; charset=utf-8',
        'Cache-Control' => 'private, no-cache, no-store, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ];
}

function metis_website_normalize_public_path( ?string $path, string $fallback_slug = '' ): string {
    $raw_path = trim( (string) $path );
    if ( $raw_path === '' ) {
        $fallback_slug = metis_slug_clean( $fallback_slug );
        $raw_path = $fallback_slug !== '' ? '/' . $fallback_slug : '';
    }

    $normalized_path = '/' . trim( $raw_path, '/' );
    if ( $normalized_path === '//' || $normalized_path === '' ) {
        return '/';
    }

    return $normalized_path;
}

function metis_website_redirect_response_for_path( string $normalized_path ): ?Metis_Http_Response {
    if ( $normalized_path === '/' ) {
        return null;
    }

    $redirect = RedirectService::resolve( $normalized_path );
    if ( ! is_array( $redirect ) ) {
        return null;
    }

    $status = (int) ( $redirect['status'] ?? 301 );
    if ( ! in_array( $status, [ 301, 302 ], true ) ) {
        $status = 301;
    }

    $location = trim( (string) ( $redirect['location'] ?? '' ) );
    if ( $location === '' ) {
        return null;
    }

    return new Metis_Http_Response(
        $status,
        [
            'Location' => $location,
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => $status === 301 ? 'public, max-age=3600' : 'no-store, max-age=0',
        ],
        ''
    );
}

function metis_website_handle_homepage_route( Metis_Http_Request $request ): Metis_Http_Response {
    try {
        $html = WebsiteRenderer::renderConfiguredHomepage();
    } catch ( \Throwable $e ) {
        metis_website_log_route_exception( 'homepage', $e, $request );
        return metis_website_error_response( 500, 'Metis could not render the homepage at this time.', 'Homepage Render Error' );
    }

    if ( $html !== null ) {
        return new Metis_Http_Response( 200, metis_website_public_html_headers( true ), $html );
    }

    if ( ! WebsiteRenderer::hasHomepageConfigured() ) {
        return new Metis_Http_Response(
            200,
            metis_website_public_html_headers( false ),
            WebsiteRenderer::renderHomepagePlaceholder()
        );
    }

    return metis_website_error_response( 404, 'No homepage route is currently available.', 'Homepage Not Found' );
}

function metis_website_handle_theme_css_route( Metis_Http_Request $request ): Metis_Http_Response {
    try {
        $css = WebsiteRenderer::renderGeneratedCss( $request->query() );
    } catch ( \Throwable $e ) {
        metis_website_log_route_exception( 'theme-css', $e, $request );
        return new Metis_Http_Response( 500, [ 'Content-Type' => 'text/css; charset=utf-8' ], '/* Metis theme stylesheet failed to render */' );
    }

    $etag = '"' . sha1( $css ) . '"';
    $if_none_match = trim( (string) $request->header( 'if-none-match', '' ) );
    if ( $if_none_match !== '' ) {
        foreach ( array_map( 'trim', explode( ',', $if_none_match ) ) as $candidate ) {
            if ( $candidate === '*' || $candidate === $etag ) {
                return new Metis_Http_Response(
                    304,
                    [
                        'Content-Type' => 'text/css; charset=utf-8',
                        'Cache-Control' => trim( (string) ( $request->query()['v'] ?? '' ) ) !== ''
                            ? 'public, max-age=31536000, immutable'
                            : 'public, max-age=300',
                        'ETag' => $etag,
                        'X-Content-Type-Options' => 'nosniff',
                    ],
                    ''
                );
            }
        }
    }

    $cache_control = trim( (string) ( $request->query()['v'] ?? '' ) ) !== ''
        ? 'public, max-age=31536000, immutable'
        : 'public, max-age=300';

    return new Metis_Http_Response(
        200,
        [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => $cache_control,
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ],
        $css
    );
}

function metis_website_handle_people_profile_route( Metis_Http_Request $request ): Metis_Http_Response {
    $slug = metis_slug_clean( (string) $request->attribute( 'slug', '' ) );
    if ( $slug === '' ) {
        return metis_website_error_response( 404, 'The requested profile could not be found.', 'Profile Not Found' );
    }

    try {
        $html = WebsiteRenderer::renderPublicPersonProfileBySlug( $slug );
    } catch ( \Throwable $e ) {
        metis_website_log_route_exception( 'people-profile:' . $slug, $e, $request );
        return metis_website_error_response( 500, 'Metis could not render the requested profile.', 'Profile Render Error' );
    }

    if ( $html === null ) {
        return metis_website_error_response( 404, 'The requested profile could not be found.', 'Profile Not Found' );
    }

    return new Metis_Http_Response( 200, metis_website_public_html_headers( true ), $html );
}

function metis_website_handle_page_route( Metis_Http_Request $request ): Metis_Http_Response {
    $normalized_path = metis_website_normalize_public_path(
        (string) $request->attribute( 'path', '' ),
        (string) $request->attribute( 'slug', '' )
    );
    if ( $normalized_path === '/' ) {
        return metis_website_error_response( 404, 'The requested page path is invalid.', 'Page Not Found' );
    }

    try {
        // Keep public resolution deterministic: page path, category/year post path, redirect, then 404.
        $html = method_exists( WebsiteRenderer::class, 'renderPagePath' )
            ? WebsiteRenderer::renderPagePath( $normalized_path )
            : WebsiteRenderer::renderPage( trim( $normalized_path, '/' ) );
    } catch ( \Throwable $e ) {
        metis_website_log_route_exception( 'page:' . trim( $normalized_path, '/' ), $e, $request );
        return metis_website_error_response( 500, 'Metis could not render the requested page.', 'Page Render Error' );
    }

    if ( $html !== null ) {
        return new Metis_Http_Response( 200, metis_website_public_html_headers( true ), $html );
    }

    try {
        $html = method_exists( WebsiteRenderer::class, 'renderPostByCategoryYearPath' )
            ? WebsiteRenderer::renderPostByCategoryYearPath( $normalized_path )
            : null;
    } catch ( \Throwable $e ) {
        metis_website_log_route_exception( 'post-path:' . trim( $normalized_path, '/' ), $e, $request );
        return metis_website_error_response( 500, 'Metis could not render the requested post.', 'Post Render Error' );
    }

    if ( $html !== null ) {
        return new Metis_Http_Response( 200, metis_website_public_html_headers( true ), $html );
    }

    $redirect_response = metis_website_redirect_response_for_path( $normalized_path );
    if ( $redirect_response instanceof Metis_Http_Response ) {
        return $redirect_response;
    }

    return metis_website_error_response( 404, 'The requested page could not be found.', 'Page Not Found' );
}
