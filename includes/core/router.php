<?php
if (!defined('ABSPATH')) exit;

if ( ! class_exists( 'Metis' ) ) {
    require_once __DIR__ . '/bootstrap.php';
    metis_core_bootstrap( 'service_registry' );
}

function metis_http_router(): Metis_Http_Router {
    metis_register_core_services();
    $service = Metis::service( 'router' );

    if ( method_exists( $service, 'set_builder' ) ) {
        $service->set_builder( 'metis_build_http_router' );
    }

    return $service->router();
}

function metis_build_http_router(): Metis_Http_Router {
    $router = new Metis_Http_Router();
    metis_wp_router_configure_middleware( $router );

    $router->group(
        [ 'route.security' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'assets.module',
                [ 'GET', 'HEAD' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return metis_module_asset_match_request( $request );
                },
                'metis_wp_router_handle_module_asset_request'
            );

            $router->register(
                'webhook.gateway',
                [ 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    if ( $request->attribute( 'transport' ) !== 'webhook' ) {
                        return null;
                    }

                    $provider = sanitize_key( (string) $request->attribute( 'provider', '' ) );
                    if ( $provider !== '' ) {
                        return [ 'provider' => $provider ];
                    }

                    return metis_parse_webhook_path( $request->path() );
                },
                'metis_webhook_handle_router_request'
            );
        }
    );

    $router->group(
        [ 'contacts.stack' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'contacts.carddav',
                [ 'OPTIONS', 'PROPFIND', 'REPORT', 'GET', 'HEAD', 'PUT', 'DELETE' ],
                static function ( Metis_Http_Request $request ): ?array {
                    if ( ! function_exists( 'metis_contacts_carddav_match_request' ) ) {
                        return null;
                    }

                    return metis_contacts_carddav_match_request( $request );
                },
                'metis_contacts_carddav_handle_request'
            );
        }
    );

    $router->group(
        [ 'system.cron.stack' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'system.cron',
                [ 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    if ( ! Metis_Cron_Manager::matches_request( $request ) ) {
                        return null;
                    }

                    return [];
                },
                'metis_wp_router_handle_system_cron_request'
            );
        }
    );

    $router->group(
        [ 'route.security' ],
        static function ( Metis_Http_Router $router ): void {
            metis_register_manifest_module_routes( $router );
        }
    );

    $router->group(
        [ 'portal.stack' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'portal.page',
                [ 'GET', 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    if ( $request->attribute( 'transport' ) !== 'portal' ) {
                        return null;
                    }

                    [ $domain, $view ] = metis_parse_portal_path( $request->path() );

                    return [
                        'domain' => $domain,
                        'view'   => $view,
                    ];
                },
                'metis_wp_router_handle_portal_request'
            );
        }
    );

    $router->group(
        [ 'ajax.stack' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'ajax.metis.api',
                [ 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    if ( ! metis_ajax_request_matches( $request ) ) {
                        return null;
                    }

                    $action = metis_ajax_request_action( $request );
                    if ( $action === '' || ! str_starts_with( $action, 'metis_' ) ) {
                        return null;
                    }

                    return [ 'ajax_action' => $action ];
                },
                'metis_wp_router_handle_ajax_request'
            );
        }
    );

    return $router;
}

function metis_register_manifest_module_routes( Metis_Http_Router $router ): void {
    if ( ! class_exists( 'Metis' ) ) {
        return;
    }

    $service = Metis::service( 'modules' );
    if ( ! $service instanceof Metis_Module_Loader_Service ) {
        return;
    }

    foreach ( $service->routes() as $route ) {
        $name = (string) ( $route['name'] ?? '' );
        $pattern = (string) ( $route['pattern'] ?? '' );
        $handler = $route['handler'] ?? null;

        if ( $name === '' || $pattern === '' || ! is_callable( $handler ) ) {
            Metis_Logger::warn( 'Skipping invalid manifest route', [
                'module' => (string) ( $route['module'] ?? '' ),
                'name'   => $name,
            ] );
            continue;
        }

        $router->register(
            $name,
            array_values( array_filter( array_map(
                static fn ( mixed $method ): string => strtoupper( trim( (string) $method ) ),
                (array) ( $route['methods'] ?? [ 'GET' ] )
            ) ) ),
            static function ( Metis_Http_Request $request ) use ( $pattern ): ?array {
                $matches = [];
                if ( @preg_match( $pattern, $request->path(), $matches ) !== 1 ) {
                    return null;
                }

                $params = [];
                foreach ( $matches as $key => $value ) {
                    if ( is_string( $key ) ) {
                        $params[ $key ] = sanitize_text_field( (string) $value );
                    }
                }

                return $params;
            },
            $handler,
            (array) ( $route['middleware'] ?? [] )
        );
    }
}

function metis_wp_router_configure_middleware( Metis_Http_Router $router ): void {
    $router->register_middleware( 'request.normalize', 'metis_wp_router_normalize_request' );
    $router->register_middleware( 'request.security', 'metis_wp_router_require_request_security' );
    $router->register_middleware( 'route.security', 'metis_wp_router_require_route_security' );
    $router->register_middleware( 'portal.auth', 'metis_wp_router_require_portal_authentication' );
    $router->register_middleware( 'portal.permissions', 'metis_wp_router_require_portal_permissions' );
    $router->register_middleware( 'ajax.contract', 'metis_wp_router_require_ajax_contract' );
    $router->register_middleware( 'ajax.security', 'metis_wp_router_require_ajax_security' );
    $router->register_middleware( 'system.cron.security', 'metis_wp_router_require_system_cron_security' );
    $router->register_middleware( 'contacts.auth', 'metis_contacts_carddav_require_authentication' );

    $router->register_middleware_group( 'contacts.stack', [ 'contacts.auth', 'route.security' ] );
    $router->register_middleware_group( 'system.cron.stack', [ 'system.cron.security' ] );
    $router->register_middleware_group( 'portal.stack', [ 'portal.auth', 'route.security', 'request.security', 'portal.permissions' ] );
    $router->register_middleware_group( 'ajax.stack', [ 'request.security', 'ajax.contract', 'ajax.security' ] );

    $router->push_global_middleware( 'request.normalize' );
}

function metis_request_path_relative_to_site(): string {
    $candidates = [
        (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
        (string) ( $_SERVER['PATH_INFO'] ?? '' ),
        (string) ( $_SERVER['ORIG_PATH_INFO'] ?? '' ),
        (string) ( $_SERVER['REDIRECT_URL'] ?? '' ),
    ];

    $req_path = '/';
    foreach ( $candidates as $candidate ) {
        $parsed = metis_parse_url( $candidate, PHP_URL_PATH );
        if ( ! is_string( $parsed ) || $parsed === '' ) {
            continue;
        }
        if ( $parsed === '/index.php' || $parsed === '/metis/index.php' ) {
            continue;
        }
        $req_path = $parsed;
        break;
    }

    if ( $req_path === '/' ) {
        $req_path = metis_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }

    $site_path = metis_parse_url(home_url('/'), PHP_URL_PATH) ?: '/';

    $req_path  = '/' . ltrim($req_path, '/');
    $site_path = '/' . trim($site_path, '/') . '/';

    if ($site_path !== '//' && $site_path !== '/' && strpos($req_path . '/', $site_path) === 0) {
        $req_path = '/' . ltrim(substr($req_path, strlen($site_path) - 1), '/');
    }

    return '/' . ltrim($req_path, '/');
}

function metis_is_portal_request(): bool {
    if (php_sapi_name() === 'cli') return false;

    if ( defined( 'METIS_STANDALONE' ) && METIS_STANDALONE ) {
        return true;
    }

    $path = rtrim(metis_request_path_relative_to_site(), '/');
    $slug = trim(metis_portal_slug(), '/');

    if (!$slug) {
        return false;
    }

    return (bool) preg_match('#^/' . preg_quote($slug, '#') . '($|/)#', $path);
}

function metis_parse_portal_path( string $path ): array {
    $slug = metis_portal_slug();
    if ( $slug !== '' ) {
        $path = preg_replace('#^/' . preg_quote($slug, '#') . '#', '', $path);
    }
    $path = trim((string)$path, '/');

    $parts  = $path !== '' ? explode('/', $path) : [];
    $domain = sanitize_key($parts[0] ?? 'portal');
    $view   = sanitize_key($parts[1] ?? 'dashboard');

    if ($domain === '') $domain = 'portal';
    if ($view === '')   $view   = 'dashboard';

    return [$domain, $view];
}

function metis_parse_portal_request(): array {
    return metis_parse_portal_path( metis_request_path_relative_to_site() );
}

function metis_module_asset_base_path(): string {
    return '/assets/modules';
}

function metis_module_asset_url( string $module, string $asset ): string {
    $module = sanitize_key( $module );
    $asset  = ltrim( (string) $asset, '/' );
    $url = home_url( trim( metis_module_asset_base_path(), '/' ) . '/' . rawurlencode( $module ) . '/' . str_replace( '%2F', '/', rawurlencode( $asset ) ) );
    $path = trailingslashit( ABSPATH ) . 'includes/modules/' . $module . '/assets/' . $asset;
    if ( is_file( $path ) ) {
        $url = add_query_arg( 'v', (string) filemtime( $path ), $url );
    }
    return $url;
}

function metis_module_asset_match_request( Metis_Http_Request $request ): ?array {
    $path = '/' . ltrim( $request->path(), '/' );
    $base = rtrim( metis_module_asset_base_path(), '/' );

    if ( ! str_starts_with( $path, $base . '/' ) ) {
        return null;
    }

    $relative = ltrim( substr( $path, strlen( $base ) ), '/' );
    if ( $relative === '' ) {
        return null;
    }

    $parts = explode( '/', $relative, 2 );
    $module = sanitize_key( (string) ( $parts[0] ?? '' ) );
    $asset  = isset( $parts[1] ) ? trim( (string) $parts[1] ) : '';

    if ( $module === '' || $asset === '' || str_contains( $asset, '..' ) ) {
        return null;
    }

    return [
        'module_asset_module' => $module,
        'module_asset_path'   => $asset,
    ];
}

function metis_module_asset_content_type( string $path ): string {
    return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
        'css'  => 'text/css; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'  => 'font/ttf',
        default => 'application/octet-stream',
    };
}

function metis_wp_router_handle_module_asset_request( Metis_Http_Request $request ): Metis_Http_Response {
    $module = sanitize_key( (string) $request->attribute( 'module_asset_module', '' ) );
    $asset  = ltrim( (string) $request->attribute( 'module_asset_path', '' ), '/' );

    $registered = metis_get_module( $module );
    if ( ! is_array( $registered ) ) {
        return Metis_Http_Response::html( 'Asset not found.', 404 );
    }

    $file = trailingslashit( (string) $registered['dir'] ) . 'assets/' . $asset;
    $real = realpath( $file );
    $assets_root = realpath( trailingslashit( (string) $registered['dir'] ) . 'assets' );

    if ( ! is_string( $real ) || ! is_string( $assets_root ) || ! str_starts_with( $real, $assets_root . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
        return Metis_Http_Response::html( 'Asset not found.', 404 );
    }

    $body = file_get_contents( $real );
    if ( $body === false ) {
        return Metis_Http_Response::html( 'Asset unreadable.', 500 );
    }

    return new Metis_Http_Response(
        200,
        [
            'Content-Type'  => metis_module_asset_content_type( $real ),
            'Cache-Control' => 'public, max-age=300',
        ],
        $request->method() === 'HEAD' ? '' : $body
    );
}

function metis_wp_router_build_request( array $attributes = [] ): Metis_Http_Request {
    $headers = [];
    foreach ( $_SERVER as $key => $value ) {
        if ( ! is_string( $key ) ) {
            continue;
        }

        if ( str_starts_with( $key, 'HTTP_' ) ) {
            $header_name             = strtolower( str_replace( '_', '-', substr( $key, 5 ) ) );
            $headers[ $header_name ] = is_scalar( $value ) ? (string) $value : '';
        }
    }

    if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
        $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
    }

    // Some Apache / PHP-FPM setups expose Basic auth outside the HTTP_* namespace.
    if ( empty( $headers['authorization'] ) ) {
        foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization' ] as $server_key ) {
            if ( ! empty( $_SERVER[ $server_key ] ) && is_scalar( $_SERVER[ $server_key ] ) ) {
                $headers['authorization'] = (string) $_SERVER[ $server_key ];
                break;
            }
        }
    }

    if ( empty( $headers['authorization'] ) && isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
        $headers['authorization'] = 'Basic ' . base64_encode( (string) $_SERVER['PHP_AUTH_USER'] . ':' . (string) $_SERVER['PHP_AUTH_PW'] );
    }

    if ( function_exists( 'getallheaders' ) ) {
        $all_headers = getallheaders();
        if ( is_array( $all_headers ) ) {
            foreach ( $all_headers as $name => $value ) {
                if ( ! is_string( $name ) ) {
                    continue;
                }
                $normalized = strtolower( str_replace( '_', '-', $name ) );
                if ( ! isset( $headers[ $normalized ] ) && is_scalar( $value ) ) {
                    $headers[ $normalized ] = (string) $value;
                }
            }
        }
    }

    $raw_body    = file_get_contents( 'php://input' ) ?: '';
    $parsed_body = metis_unslash( $_POST );

    if ( empty( $parsed_body ) && str_contains( strtolower( (string) ( $headers['content-type'] ?? '' ) ), 'application/json' ) ) {
        $decoded = json_decode( $raw_body, true );
        if ( is_array( $decoded ) ) {
            $parsed_body = $decoded;
        }
    }

    return new Metis_Http_Request(
        strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ),
        (string) ( $_SERVER['REQUEST_URI'] ?? '/' ),
        metis_request_path_relative_to_site(),
        metis_unslash( $_GET ),
        $parsed_body,
        $headers,
        $_COOKIE,
        $_FILES,
        $_SERVER,
        $raw_body,
        $attributes
    );
}

function metis_wp_router_emit_response( Metis_Http_Response $response ): void {
    status_header( $response->status() );

    foreach ( $response->headers() as $name => $value ) {
        header( $name . ': ' . $value, true );
    }

    echo $response->body();
}

function metis_wp_router_normalize_request( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $method = strtoupper( $request->method() );
    $normalized = $request
        ->with_attribute( 'request_id', metis_audit_request_id() )
        ->with_attribute( 'normalized_path', '/' . ltrim( $request->path(), '/' ) )
        ->with_attribute( 'normalized_method', $method )
        ->with_attribute( 'is_safe_method', in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true ) );

    return $next( $normalized );
}

function metis_wp_router_require_portal_authentication( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    if ( defined( 'METIS_STANDALONE' ) && METIS_STANDALONE ) {
        if ( metis_user_logged_in() ) {
            return $next( $request );
        }

        $path = parse_url( $request->uri(), PHP_URL_PATH ) ?: '/';
        return Metis_Http_Response::redirect( function_exists( 'metis_auth_login_url' ) ? metis_auth_login_url( home_url( $path ) ) : home_url( '/login' ) );
    }

    if ( metis_user_logged_in() ) {
        return $next( $request );
    }

    $path = parse_url( $request->uri(), PHP_URL_PATH ) ?: '/';
    return Metis_Http_Response::redirect( metis_login_url( home_url( $path ) ) );
}

function metis_wp_router_require_system_cron_security( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    try {
        Metis_Cron_Manager::authorize_request( $request );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        return Metis_Http_Response::json(
            [
                'success' => false,
                'data'    => [
                    'message' => $e->getMessage(),
                    'code'    => $e->code_name(),
                ],
            ],
            $e->status(),
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    return $next( $request );
}

function metis_wp_router_route_permission_for_request( Metis_Http_Request $request ): string {
    $route_name = (string) $request->attribute( 'route_name', '' );
    $method     = strtoupper( $request->method() );

    if ( $route_name === 'contacts.carddav' ) {
        return match ( $method ) {
            'PUT' => 'edit',
            'DELETE' => 'delete',
            default => 'view',
        };
    }

    if ( $route_name === 'assets.module' || $route_name === 'portal.page' ) {
        return 'view';
    }

    if ( $route_name === 'forms.public' ) {
        return $method === 'POST' ? 'create' : 'view';
    }

    if ( $route_name === 'webhook.gateway' ) {
        return 'create';
    }

    return 'view';
}

function metis_wp_router_route_policy( Metis_Http_Request $request ): ?Metis_Security_Policy {
    $route_name = (string) $request->attribute( 'route_name', '' );
    if ( $route_name === '' ) {
        return null;
    }

    $module = null;
    $require_authentication = false;
    $require_session = false;
    $require_nonce = false;
    $rate_limit = 240;
    $rate_window = 60;

    switch ( $route_name ) {
        case 'contacts.carddav':
            $module = 'contacts';
            $require_authentication = true;
            $require_session = false;
            $rate_limit = 300;
            break;

        case 'assets.module':
            $module = sanitize_key( (string) $request->attribute( 'module_asset_module', '' ) );
            $require_authentication = true;
            $require_session = true;
            $rate_limit = 600;
            break;

        case 'portal.page':
            $module = sanitize_key( (string) $request->attribute( 'domain', 'portal' ) );
            $require_authentication = true;
            $require_session = true;
            $rate_limit = 360;
            break;

        case 'forms.public':
            $module = 'forms';
            $require_authentication = false;
            $require_session = false;
            $require_nonce = false;
            $rate_limit = 120;
            $rate_window = 60;
            break;

        case 'webhook.gateway':
            $module = null;
            $require_authentication = false;
            $require_session = false;
            $rate_limit = 180;
            break;

        default:
            return null;
    }

    $permission = metis_wp_router_route_permission_for_request( $request );
    $operation = 'route.' . str_replace( '.', '_', $route_name );
    if ( $module !== null && $module !== '' ) {
        $operation .= '.' . $module . '.' . $permission;
    } elseif ( $route_name === 'webhook.gateway' ) {
        $provider = sanitize_key( (string) $request->attribute( 'provider', '' ) );
        if ( $provider === '' ) {
            $route_params = (array) $request->attribute( 'route_params', [] );
            $provider = sanitize_key( (string) ( $route_params['provider'] ?? '' ) );
        }
        $operation .= $provider !== '' ? '.' . $provider : '.unknown';
    }

    return new Metis_Security_Policy(
        $operation,
        $module !== '' ? $module : null,
        $permission,
        $require_authentication,
        $require_session,
        $require_nonce,
        null,
        $rate_limit,
        $rate_window
    );
}

function metis_wp_router_route_context( Metis_Http_Request $request ): array {
    $context = metis_security_runtime_request_context( array_merge(
        $request->query(),
        $request->parsed_body(),
        [ 'route' => (string) $request->attribute( 'route_name', '' ) ]
    ) );

    $meta = (array) ( $context['meta'] ?? [] );
    $meta['request_id'] = metis_audit_request_id();
    $meta['path'] = $request->path();
    $meta['method'] = strtoupper( $request->method() );
    $meta['route_name'] = (string) $request->attribute( 'route_name', '' );

    $context['meta'] = $meta;
    return $context;
}

function metis_wp_router_route_security_failure_response( Metis_Http_Request $request, Metis_Security_Enclave_Exception $e ): Metis_Http_Response {
    $route_name = (string) $request->attribute( 'route_name', '' );

    if ( in_array( $route_name, [ 'webhook.gateway', 'system.cron', 'ajax.metis.api' ], true ) ) {
        return Metis_Http_Response::json(
            [ 'success' => false, 'data' => [ 'message' => $e->getMessage(), 'code' => $e->code_name() ] ],
            $e->status(),
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    if ( in_array( $route_name, [ 'contacts.carddav', 'assets.module' ], true ) ) {
        return new Metis_Http_Response(
            $e->status(),
            [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ],
            $e->getMessage()
        );
    }

    return Metis_Http_Response::html(
        '<div class="metis-error">' . esc_html( $e->getMessage() ) . '</div>',
        $e->status()
    );
}

function metis_wp_router_require_route_security( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $policy = metis_wp_router_route_policy( $request );
    if ( ! $policy instanceof Metis_Security_Policy ) {
        return $next( $request );
    }

    $enclave = metis_security_enclave();
    if ( ! $enclave->has_policy( $policy->operation ) ) {
        $enclave->register_policy( $policy );
    }

    try {
        $enclave->handle(
            $policy->operation,
            metis_wp_router_route_context( $request ),
            static function () {
                return true;
            }
        );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        return metis_wp_router_route_security_failure_response( $request, $e );
    }

    return $next(
        $request
            ->with_attribute( 'module', $policy->module ?? '' )
            ->with_attribute( 'permission', $policy->permission )
    );
}

function metis_wp_router_portal_nonce_map(): array {
    return [
        'portal/settings' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_portal_settings' ],
        'settings/general' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_general' ],
        'settings/logging' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_logging' ],
        'settings/customization' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_customization' ],
        'settings/accessibility' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_accessibility' ],
        'settings/menu' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_menu' ],
        'settings/profile' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_profile' ],
        'settings/newsletter' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_newsletter' ],
        'settings/workspace' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_workspace' ],
        'settings/drive' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_drive' ],
        'settings/calendar' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_calendar' ],
        'settings/api' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_api' ],
        'settings/scheduler' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_scheduler' ],
        'settings/help' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_help' ],
        'donations/transactions' => [ 'field' => 'mw_batch_nonce', 'action' => 'mw_create_batch' ],
        'finance/ledger' => [ 'field' => 'metis_finance_ledger_nonce', 'action' => 'metis_finance_ledger_save' ],
        'finance/reconciliations' => [ 'field' => 'metis_finance_recon_nonce', 'action' => 'metis_finance_recon_save' ],
    ];
}

function metis_wp_router_request_requires_csrf( Metis_Http_Request $request ): bool {
    $method = strtoupper( $request->method() );
    if ( in_array( $method, [ 'POST', 'PATCH', 'DELETE' ], true ) ) {
        return true;
    }

    return (string) $request->attribute( 'route_name', '' ) === 'ajax.metis.api';
}

function metis_wp_router_request_origin_is_valid( Metis_Http_Request $request ): bool {
    $site_host = strtolower( (string) metis_parse_url( home_url( '/' ), PHP_URL_HOST ) );
    if ( $site_host === '' ) {
        return true;
    }

    foreach ( [ 'origin', 'referer' ] as $header_name ) {
        $header_value = trim( $request->header( $header_name ) );
        if ( $header_value === '' ) {
            continue;
        }

        $header_host = strtolower( (string) metis_parse_url( $header_value, PHP_URL_HOST ) );
        if ( $header_host !== '' && hash_equals( $site_host, $header_host ) ) {
            return true;
        }

        return false;
    }

    return false;
}

function metis_wp_router_request_nonce_candidates( Metis_Http_Request $request ): array {
    $candidates = [];
    foreach ( [ 'x-metis-csrf-token', 'x-csrf-token' ] as $header_name ) {
        $value = trim( $request->header( $header_name ) );
        if ( $value !== '' ) {
            $candidates[] = $value;
        }
    }

    $fields = [ 'csrf_token', 'metis_action_nonce', '_wpnonce', 'security', '_ajax_nonce', 'nonce' ];
    if ( (string) $request->attribute( 'route_name', '' ) === 'portal.page' ) {
        $domain = sanitize_key( (string) $request->attribute( 'domain', '' ) );
        $view   = sanitize_key( (string) $request->attribute( 'view', '' ) );
        $key    = $domain . '/' . $view;
        $map    = metis_wp_router_portal_nonce_map();
        if ( isset( $map[ $key ]['field'] ) && is_string( $map[ $key ]['field'] ) ) {
            $fields[] = $map[ $key ]['field'];
        }
    }

    foreach ( array_values( array_unique( $fields ) ) as $field ) {
        foreach ( $request->input() as $key => $value ) {
            if ( $key !== $field || ! is_scalar( $value ) ) {
                continue;
            }

            $token = trim( (string) $value );
            if ( $token !== '' ) {
                $candidates[] = $token;
            }
        }
    }

    return array_values( array_unique( $candidates ) );
}

function metis_wp_router_request_nonce_actions( Metis_Http_Request $request ): array {
    $actions = [];
    $route_name = (string) $request->attribute( 'route_name', '' );

    if ( $route_name === 'ajax.metis.api' ) {
        $ajax_action = sanitize_key( (string) $request->attribute( 'ajax_action', metis_ajax_request_action( $request ) ) );
        if ( $ajax_action !== '' ) {
            $actions[] = metis_ajax_nonce_action( $ajax_action );

            $controller = $request->attribute( 'ajax_controller' );
            if ( is_array( $controller ) && ! empty( $controller['nonce_action'] ) ) {
                $actions[] = (string) $controller['nonce_action'];
            }
        }
    }

    if ( $route_name === 'portal.page' ) {
        $domain = sanitize_key( (string) $request->attribute( 'domain', '' ) );
        $view   = sanitize_key( (string) $request->attribute( 'view', '' ) );
        $key    = $domain . '/' . $view;
        $map    = metis_wp_router_portal_nonce_map();

        if ( isset( $map[ $key ]['action'] ) ) {
            $actions[] = (string) $map[ $key ]['action'];
        }

        $input = $request->input();
        if ( isset( $input['metis_csrf_action'] ) && is_scalar( $input['metis_csrf_action'] ) ) {
            $explicit = trim( (string) $input['metis_csrf_action'] );
            if ( $explicit !== '' ) {
                $actions[] = $explicit;
            }
        }
    }

    return array_values( array_unique( array_filter( $actions, 'strlen' ) ) );
}

function metis_wp_router_request_nonce_is_valid( Metis_Http_Request $request ): bool {
    $candidates = metis_wp_router_request_nonce_candidates( $request );
    $actions    = metis_wp_router_request_nonce_actions( $request );

    if ( $candidates === [] || $actions === [] ) {
        return false;
    }

    foreach ( $actions as $action ) {
        foreach ( $candidates as $candidate ) {
            if ( metis_verify_nonce( $candidate, $action ) ) {
                return true;
            }
        }
    }

    return false;
}

function metis_wp_router_request_security_failure_response( Metis_Http_Request $request, string $message, string $code, int $status ): Metis_Http_Response {
    return metis_wp_router_route_security_failure_response(
        $request,
        new Metis_Security_Enclave_Exception( $message, $code, $status )
    );
}

function metis_wp_router_require_request_security( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    if ( ! metis_wp_router_request_requires_csrf( $request ) ) {
        return $next( $request );
    }

    if ( ! metis_wp_router_request_origin_is_valid( $request ) ) {
        return metis_wp_router_request_security_failure_response( $request, 'Cross-site request rejected.', 'csrf_failed', 403 );
    }

    if ( ! metis_auth_session_integrity_is_valid() ) {
        return metis_wp_router_request_security_failure_response( $request, 'Invalid session integrity.', 'invalid_session_integrity', 401 );
    }

    if ( ! metis_wp_router_request_nonce_is_valid( $request ) ) {
        return metis_wp_router_request_security_failure_response( $request, 'Invalid request nonce.', 'invalid_nonce', 403 );
    }

    return $next(
        $request->with_attribute(
            'request_security',
            [
                'csrf_validated'      => true,
                'origin_validated'    => true,
                'session_integrity'   => true,
                'nonce_actions'       => metis_wp_router_request_nonce_actions( $request ),
                'token_candidates'    => metis_wp_router_request_nonce_candidates( $request ),
            ]
        )
    );
}

function metis_wp_router_require_portal_permissions( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $domain = sanitize_key( (string) $request->attribute( 'domain', '' ) );
    $view   = sanitize_key( (string) $request->attribute( 'view', '' ) );

    try {
        metis_security_authorize_view( $domain, $view );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        return Metis_Http_Response::html(
            '<div class="metis-error">' . esc_html( $e->getMessage() ) . '</div>',
            $e->status()
        );
    }

    return $next( $request );
}

function metis_wp_router_require_ajax_contract( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $ajax_action = sanitize_key( (string) $request->attribute( 'ajax_action', '' ) );
    $controller  = metis_ajax_registry()->get( $ajax_action );

    if ( ! is_array( $controller ) ) {
        return Metis_Http_Response::json(
            [ 'success' => false, 'data' => [ 'message' => 'Unregistered AJAX controller.', 'code' => 'ajax_controller_missing' ] ],
            404
        );
    }

    try {
        $validated = metis_ajax_validate_request( $request, $controller );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        return Metis_Http_Response::json(
            [
                'success' => false,
                'data' => [
                    'message' => $e->getMessage(),
                    'code' => $e->code_name(),
                    'errors' => (array) ( $e->context()['errors'] ?? [] ),
                ],
            ],
            $e->status()
        );
    }

    return $next(
        $request
            ->with_attribute( 'ajax_controller', $controller )
            ->with_attribute( 'validated_input', $validated )
            ->with_attribute( 'module', (string) ( $controller['module'] ?? '' ) )
            ->with_attribute( 'permission', (string) ( $controller['permission'] ?? 'view' ) )
    );
}

function metis_wp_router_require_ajax_security( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $ajax_action = sanitize_key( (string) $request->attribute( 'ajax_action', '' ) );
    $controller  = $request->attribute( 'ajax_controller' );
    $module      = sanitize_key( (string) ( is_array( $controller ) ? ( $controller['module'] ?? '' ) : '' ) );

    if ( $module === '' ) {
        $module = (string) metis_security_infer_module_from_ajax_action( $ajax_action );
    }

    if ( $module === '' ) {
        return Metis_Http_Response::json(
            [ 'success' => false, 'data' => [ 'message' => 'Unregistered enclave action.', 'code' => 'ajax_module_missing' ] ],
            403
        );
    }

    $permission  = (string) ( is_array( $controller ) ? ( $controller['permission'] ?? 'view' ) : 'view' );
    $operation   = sprintf( 'ajax.%s.%s', $module, $ajax_action );
    $nonce_key   = (string) ( is_array( $controller ) ? ( $controller['nonce_action'] ?? metis_ajax_nonce_action( $ajax_action ) ) : metis_ajax_nonce_action( $ajax_action ) );
    $rate_limit  = (int) ( is_array( $controller ) ? ( $controller['rate_limit'] ?? 0 ) : 0 );
    $rate_window = (int) ( is_array( $controller ) ? ( $controller['rate_window_seconds'] ?? 60 ) : 60 );
    $enclave     = metis_security_enclave();

    if ( $rate_limit < 1 ) {
        $rate_limit = $permission === 'view' ? 180 : 90;
    }

    if ( ! $enclave->has_policy( $operation ) ) {
        $enclave->register_policy(
            new Metis_Security_Policy(
                $operation,
                $module,
                $permission,
                true,
                true,
                true,
                $nonce_key,
                $rate_limit,
                max( 1, $rate_window )
            )
        );
    }

    try {
        $enclave->handle(
            $operation,
            metis_security_runtime_request_context( (array) $request->attribute( 'validated_input', $request->input() ) ),
            static function () {
                return true;
            }
        );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        return Metis_Http_Response::json(
            [
                'success' => false,
                'data'    => [
                    'message' => $e->getMessage(),
                    'code'    => $e->code_name(),
                ],
            ],
            $e->status()
        );
    }

    metis_audit_log_activity( 'ajax_action_authorized', [
        'module'   => $module,
        'resource' => [
            'type'  => 'ajax_action',
            'id'    => $ajax_action,
            'label' => $permission,
        ],
        'context'  => [
            'operation'  => $operation,
            'permission' => $permission,
            'route'      => 'ajax.metis.api',
        ],
    ] );

    return $next( $request->with_attribute( 'module', $module )->with_attribute( 'permission', $permission ) );
}

function metis_wp_router_handle_portal_request( Metis_Http_Request $request ): Metis_Http_Response {
    $domain = sanitize_key( (string) $request->attribute( 'domain', 'portal' ) );
    $view   = sanitize_key( (string) $request->attribute( 'view', 'dashboard' ) );

    set_query_var( 'metis_domain', $domain );
    set_query_var( 'metis_view', $view );

    global $wp_query;
    if ( $wp_query instanceof WP_Query ) {
        $wp_query->is_404      = false;
        $wp_query->is_home     = false;
        $wp_query->is_archive  = false;
        $wp_query->is_singular = false;
    }

    nocache_headers();
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    $shell = METIS_PATH . 'includes/core/shell.php';
    if ( ! file_exists( $shell ) ) {
        return Metis_Http_Response::html( '<div class="metis-error">METIS shell is missing.</div>', 500 );
    }

    ob_start();
    if ( function_exists( 'metis_security_trusted_include' ) ) {
        metis_security_trusted_include( $shell );
    } else {
        require_once $shell;
    }
    $body = (string) ob_get_clean();

    Metis_Logger::info( 'Router dispatched portal request', [
        'route'  => 'portal.page',
        'domain' => $domain,
        'view'   => $view,
    ] );

    return Metis_Http_Response::html( $body, 200, [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ] );
}

function metis_wp_router_handle_ajax_request( Metis_Http_Request $request ): Metis_Http_Response {
    $ajax_action = sanitize_key( (string) $request->attribute( 'ajax_action', '' ) );
    $request_id  = metis_audit_request_id();

    try {
        $dispatched = metis_ajax_dispatch_legacy_action( $ajax_action );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        return Metis_Http_Response::json(
            [ 'success' => false, 'data' => [ 'message' => $e->getMessage(), 'code' => $e->code_name() ] ],
            $e->status(),
            [ 'X-Metis-Request-Id' => $request_id ]
        );
    }

    $payload = is_array( $dispatched['body'] ?? null ) ? $dispatched['body'] : [];
    if ( ! array_key_exists( 'success', $payload ) ) {
        $payload = [
            'success' => (int) ( $dispatched['status'] ?? 200 ) < 400,
            'data' => $payload,
        ];
    }

    if ( ! isset( $payload['meta'] ) || ! is_array( $payload['meta'] ) ) {
        $payload['meta'] = [];
    }
    $payload['meta']['request_id'] = $request_id;
    $payload['meta']['action']     = $ajax_action;

    Metis_Logger::info( 'Router dispatched ajax request', [
        'route'  => 'ajax.metis.api',
        'action' => $ajax_action,
        'status' => (int) ( $dispatched['status'] ?? 200 ),
    ] );

    return Metis_Http_Response::json(
        $payload,
        (int) ( $dispatched['status'] ?? 200 ),
        [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'X-Metis-Request-Id' => $request_id ]
    );
}

function metis_wp_router_handle_system_cron_request( Metis_Http_Request $request ): Metis_Http_Response {
    Metis_Logger::info( 'Router dispatched system cron request', [
        'route'      => 'system.cron',
        'request_id' => metis_audit_request_id(),
    ] );

    return Metis_Cron_Manager::handle_request( $request );
}

metis_add_action('init', function () {
    add_rewrite_tag('%metis_domain%', '([^&]+)');
    add_rewrite_tag('%metis_view%',   '([^&]+)');
    add_rewrite_tag('%metis_webhook_provider%', '([^&]+)');

    $slug = metis_portal_slug();
    $ajax = trim( metis_ajax_endpoint_path(), '/' );
    $webhooks = trim( metis_webhook_base_path(), '/' );
    $module_assets = trim( metis_module_asset_base_path(), '/' );

    if ( $slug !== '' ) {
        add_rewrite_rule("^{$slug}/?$", "index.php?metis_domain=portal&metis_view=dashboard", 'top');
        add_rewrite_rule("^{$slug}/([^/]+)/?$", "index.php?metis_domain=\$matches[1]&metis_view=dashboard", 'top');
        add_rewrite_rule("^{$slug}/([^/]+)/([^/]+)/?$", "index.php?metis_domain=\$matches[1]&metis_view=\$matches[2]", 'top');
    }
    add_rewrite_rule("^{$ajax}/?$", 'index.php?metis_api_ajax=1', 'top');
    add_rewrite_rule("^{$webhooks}/([^/]+)/?$", "index.php?metis_webhook_provider=\$matches[1]", 'top');
    add_rewrite_rule("^{$module_assets}/([^/]+)/(.+)?$", "index.php?metis_module_asset_module=\$matches[1]&metis_module_asset_path=\$matches[2]", 'top');
}, 1);

metis_add_filter('query_vars', function ($vars) {
    $vars[] = 'metis_domain';
    $vars[] = 'metis_view';
    $vars[] = 'metis_api_ajax';
    $vars[] = 'metis_webhook_provider';
    $vars[] = 'metis_module_asset_module';
    $vars[] = 'metis_module_asset_path';
    return $vars;
});

metis_add_action('admin_init', function () {
    if (!metis_current_user_can('manage_options')) return;

    $key      = 'metis_last_route_signature';
    $current  = metis_portal_slug() . '|' . metis_webhook_base_path();
    $previous = get_option($key, '');

    if ($previous !== $current) {
        flush_rewrite_rules(false);
        update_option($key, $current, false);
        Metis_Logger::info( "Flushed rewrite rules (route signature changed {$previous} -> {$current})" );
    }
});

metis_add_action('template_redirect', function () {
    $request = metis_wp_router_build_request();

    if ( function_exists( 'metis_contacts_carddav_is_request' ) && metis_contacts_carddav_is_request( $request ) ) {
        $response = metis_http_router()->dispatch( $request );
        metis_wp_router_emit_response( $response );
        exit;
    }

    if ( Metis_Cron_Manager::matches_request( $request ) ) {
        $response = metis_http_router()->dispatch( $request );
        metis_wp_router_emit_response( $response );
        exit;
    }

    if ( get_query_var( 'metis_api_ajax' ) || metis_ajax_request_matches( $request ) ) {
        $response = metis_http_router()->dispatch( $request );
        metis_wp_router_emit_response( $response );
        exit;
    }

    if ( get_query_var( 'metis_module_asset_module' ) || metis_module_asset_match_request( $request ) ) {
        if ( get_query_var( 'metis_module_asset_module' ) ) {
            $request = $request
                ->with_attribute( 'module_asset_module', sanitize_key( (string) get_query_var( 'metis_module_asset_module' ) ) )
                ->with_attribute( 'module_asset_path', ltrim( (string) get_query_var( 'metis_module_asset_path' ), '/' ) );
        }
        $response = metis_http_router()->dispatch( $request );
        metis_wp_router_emit_response( $response );
        exit;
    }

    $webhook_provider = sanitize_key( (string) get_query_var( 'metis_webhook_provider' ) );
    if ( $webhook_provider !== '' || metis_is_webhook_request() ) {
        $response = metis_http_router()->dispatch(
            $request
                ->with_attribute( 'transport', 'webhook' )
                ->with_attribute( 'provider', $webhook_provider )
        );
        metis_wp_router_emit_response( $response );
        exit;
    }

    if (!metis_is_portal_request()) {
        return;
    }

    $response = metis_http_router()->dispatch(
        $request->with_attribute( 'transport', 'portal' )
    );

    metis_wp_router_emit_response( $response );
    exit;
}, 0);

metis_add_action( 'admin_init', function () {
    if ( ! metis_doing_ajax() ) {
        return;
    }

    $action = isset( $_REQUEST['action'] ) ? sanitize_key( metis_unslash( $_REQUEST['action'] ) ) : '';
    if ( $action === '' || ! str_starts_with( $action, 'metis_' ) ) {
        return;
    }

    metis_wp_router_emit_response(
        Metis_Http_Response::json(
            [
                'success' => false,
                'data' => [
                    'message' => 'Direct admin-ajax access is disabled. Use the centralized AJAX endpoint.',
                    'code' => 'ajax_endpoint_disabled',
                    'endpoint' => metis_ajax_endpoint_url(),
                ],
            ],
            410
        )
    );
    exit;
}, -1000 );
