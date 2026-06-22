<?php
declare(strict_types=1);

namespace Metis\Modules\Resources;

use Metis\Http\Request;
use Metis\Http\Response;

final class ResourcesModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \metis_on( 'init', [ self::class, 'ensureRuntimeSchema' ], 5 );
        \metis_on( 'init', [ self::class, 'registerPublicRoutes' ], 19 );
    }

    public static function ensureSchema(): void {
        SchemaManager::ensureSchema();
    }

    public static function ensureRuntimeSchema(): void {
        if ( \function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'resources_schema',
                [ __FILE__, __DIR__ . '/SchemaManager.php', __DIR__ . '/Repository.php' ],
                static function (): void {
                    SchemaManager::ensureSchema();
                }
            );
            return;
        }

        self::ensureSchema();
    }

    public static function canView(): bool {
        return Support::canView();
    }

    public static function canManage(): bool {
        return Support::canManage();
    }

    public static function canDelete(): bool {
        return Support::canDelete();
    }

    public static function baseUrl(): string {
        return Support::baseUrl();
    }

    public static function registerPublicRoutes(): void {
        if ( ! \function_exists( 'metis_http_router' ) ) {
            return;
        }

        $public_routes_enabled = \function_exists( 'metis_get_option' )
            ? (bool) \metis_get_option( 'metis_website_public_routes_enabled', false )
            : false;
        if ( ! $public_routes_enabled ) {
            return;
        }

        $router = \metis_http_router();
        $normalize_public_path = static function ( \Metis_Http_Request $request ): string {
            $path = '/' . ltrim( (string) $request->path(), '/' );
            return $path === '' ? '/' : $path;
        };

        $router->register(
            'resources.public',
            [ 'GET', 'HEAD' ],
            static function ( \Metis_Http_Request $request ) use ( $normalize_public_path ): ?array {
                $matches = [];
                if ( preg_match( '#^/resources(?:/(?P<type>[a-z0-9-]+)(?:/(?P<category>[a-z0-9-]+)(?:/(?P<resource>[a-z0-9-]+))?)?)?/?$#i', $normalize_public_path( $request ), $matches ) !== 1 ) {
                    return null;
                }

                return [
                    'type' => (string) ( $matches['type'] ?? '' ),
                    'category' => (string) ( $matches['category'] ?? '' ),
                    'resource' => (string) ( $matches['resource'] ?? '' ),
                ];
            },
            [ self::class, 'handlePublicRoute' ],
            [ 'route.security' ]
        );
    }

    public static function handlePublicRoute( Request $request ): Response {
        $type = \metis_slug_clean( (string) $request->attribute( 'type', '' ) );
        $category = \metis_slug_clean( (string) $request->attribute( 'category', '' ) );
        $resource = \metis_slug_clean( (string) $request->attribute( 'resource', '' ) );

        $html = Repository::renderPublicRoute(
            $type,
            $category,
            $resource,
            (array) $request->query()
        );

        if ( $html === null ) {
            return new Response(
                404,
                [ 'Content-Type' => 'text/html; charset=utf-8' ],
                '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Not Found</title></head><body><main><h1>Not Found</h1><p>The requested resource could not be found.</p></main></body></html>'
            );
        }

        return new Response( 200, [ 'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=300' ], $html );
    }
}
