<?php
declare(strict_types=1);

namespace Metis\Http;

use Metis\Core\Error\ErrorPageRenderer;

final class Router {
    /** @var Route[] */
    private array $routes = [];
    /** @var array<string, callable> */
    private array $middleware_aliases = [];
    /** @var array<string, array<int, callable|string>> */
    private array $middleware_groups = [];
    /** @var array<int, callable|string> */
    private array $global_middleware = [];
    /** @var array<int, array<int, callable|string>> */
    private array $scoped_middleware = [];

    public function register_middleware( string $name, callable $middleware ): void {
        $name = \metis_key_clean( str_replace( '.', '_', $name ) );
        if ( $name === '' ) {
            throw new \InvalidArgumentException( 'Middleware names cannot be empty.' );
        }

        $this->middleware_aliases[ $name ] = $middleware;
    }

    public function register_middleware_group( string $name, array $middleware ): void {
        $name = \metis_key_clean( str_replace( '.', '_', $name ) );
        if ( $name === '' ) {
            throw new \InvalidArgumentException( 'Middleware group names cannot be empty.' );
        }

        $this->middleware_groups[ $name ] = array_values( $middleware );
    }

    public function push_global_middleware( callable|string ...$middleware ): void {
        foreach ( $middleware as $entry ) {
            $this->global_middleware[] = $entry;
        }
    }

    public function group( array|string|callable $middleware, callable $registrar ): void {
        $entries = is_array( $middleware ) ? array_values( $middleware ) : [ $middleware ];
        $this->scoped_middleware[] = $entries;

        try {
            $registrar( $this );
        } finally {
            array_pop( $this->scoped_middleware );
        }
    }

    public function register(
        string $name,
        array $methods,
        callable $matcher,
        callable $handler,
        array $middleware = []
    ): void {
        $normalized = [];
        foreach ( $methods as $method ) {
            $method = strtoupper( trim( (string) $method ) );
            if ( $method !== '' ) {
                $normalized[] = $method;
            }
        }

        $resolved_middleware = $this->resolve_route_middleware( $middleware );

        $this->routes[] = new Route(
            $name,
            $normalized,
            $matcher,
            $handler,
            $resolved_middleware
        );
    }

    public function dispatch( Request $request ): Response {
        foreach ( $this->routes as $route ) {
            if ( ! in_array( strtoupper( $request->method() ), $route->methods, true ) ) {
                continue;
            }

            $params = call_user_func( $route->matcher, $request );
            if ( $params === null ) {
                continue;
            }

            $resolved = $request
                ->with_attribute( 'route_name', $route->name )
                ->with_attribute( 'route_params', is_array( $params ) ? $params : [] );

            if ( is_array( $params ) ) {
                foreach ( $params as $key => $value ) {
                    if ( is_string( $key ) ) {
                        $resolved = $resolved->with_attribute( $key, $value );
                    }
                }
            }

            $pipeline = array_reduce(
                array_reverse( $route->middleware ),
                static function ( callable $next, callable $middleware ): callable {
                    return static function ( Request $request ) use ( $middleware, $next ): Response {
                        return $middleware( $request, $next );
                    };
                },
                static function ( Request $request ) use ( $route ): Response {
                    return call_user_func( $route->handler, $request );
                }
            );

            return $pipeline( $resolved );
        }

        $trace_id = function_exists( 'metis_audit_request_id' ) ? (string) \metis_audit_request_id() : '';
        if ( class_exists( ErrorPageRenderer::class ) ) {
            $renderer = new ErrorPageRenderer();
            return Response::html(
                $renderer->render( 404, $trace_id !== '' ? $trace_id : 'router-404', 'No route matched this request path.', 'Route Not Found' ),
                404,
                array_filter( [
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'X-Metis-Trace-Id' => $trace_id !== '' ? $trace_id : null,
                ], static fn ( mixed $value ): bool => is_string( $value ) && $value !== '' )
            );
        }

        return Response::html( '<div class="metis-error">Route not found.</div>', 404 );
    }

    private function resolve_route_middleware( array $middleware ): array {
        $entries = [];
        foreach ( $this->global_middleware as $entry ) {
            $entries[] = $entry;
        }

        foreach ( $this->scoped_middleware as $group ) {
            foreach ( $group as $entry ) {
                $entries[] = $entry;
            }
        }

        foreach ( $middleware as $entry ) {
            $entries[] = $entry;
        }

        $resolved = [];
        foreach ( $entries as $entry ) {
            $this->flatten_middleware_entry( $entry, $resolved );
        }

        return $resolved;
    }

    private function flatten_middleware_entry( callable|string $entry, array &$resolved ): void {
        if ( is_callable( $entry ) ) {
            $resolved[] = $entry;
            return;
        }

        $key = \metis_key_clean( str_replace( '.', '_', $entry ) );
        if ( $key === '' ) {
            return;
        }

        if ( isset( $this->middleware_groups[ $key ] ) ) {
            foreach ( $this->middleware_groups[ $key ] as $member ) {
                $this->flatten_middleware_entry( $member, $resolved );
            }
            return;
        }

        if ( isset( $this->middleware_aliases[ $key ] ) ) {
            $resolved[] = $this->middleware_aliases[ $key ];
            return;
        }

        if ( is_callable( $entry ) ) {
            $resolved[] = $entry;
            return;
        }

        throw new \InvalidArgumentException( sprintf( 'Unknown middleware [%s].', $entry ) );
    }
}
