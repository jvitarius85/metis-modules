<?php
if (!defined('METIS_ROOT')) exit;

if ( ! class_exists( 'Metis' ) ) {
    require_once dirname( __DIR__ ) . '/CoreBootstrap.php';
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
    metis_router_configure_middleware( $router );

    $router->group(
        [ 'route.security' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'assets.runtime',
                [ 'GET', 'HEAD' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return metis_runtime_asset_match_request( $request );
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_runtime_asset_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_runtime_asset_request( $request );
                }
            );

            $router->register(
                'assets.core',
                [ 'GET', 'HEAD' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return metis_core_asset_match_request( $request );
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_core_asset_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_core_asset_request( $request );
                }
            );

            $router->register(
                'assets.module',
                [ 'GET', 'HEAD' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return metis_module_asset_match_request( $request );
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_module_asset_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_module_asset_request( $request );
                }
            );

            $router->register(
                'assets.svg',
                [ 'GET', 'HEAD' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return metis_svg_icon_match_request( $request );
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_svg_icon_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_svg_icon_request( $request );
                }
            );

            $router->register(
                'webhook.gateway',
                [ 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    if ( $request->attribute( 'transport' ) !== 'webhook' ) {
                        return null;
                    }

                    $provider = metis_key_clean( (string) $request->attribute( 'provider', '' ) );
                    if ( $provider !== '' ) {
                        return [ 'provider' => $provider ];
                    }

                    return metis_parse_webhook_path( $request->path() );
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_webhook_handle_router_request' ) ) {
                        return null;
                    }
                    return metis_webhook_handle_router_request( $request );
                }
            );
        }
    );

    $router->group(
        [ 'auth.stack' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'auth.resolve',
                [ 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return metis_untrailingslashit( $request->path() ) === '/api/auth/resolve' ? [] : null;
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_auth_resolve_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_auth_resolve_request( $request );
                }
            );

            $router->register(
                'auth.passkeys.begin',
                [ 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return metis_untrailingslashit( $request->path() ) === '/api/auth/passkeys/begin' ? [] : null;
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_auth_passkey_begin_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_auth_passkey_begin_request( $request );
                }
            );

            $router->register(
                'auth.passkeys.complete',
                [ 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return metis_untrailingslashit( $request->path() ) === '/api/auth/passkeys/complete' ? [] : null;
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_auth_passkey_complete_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_auth_passkey_complete_request( $request );
                }
            );

            $router->register(
                'auth.session.keepalive',
                [ 'GET' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return metis_untrailingslashit( $request->path() ) === '/api/auth/session/keepalive' ? [] : null;
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_auth_session_keepalive_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_auth_session_keepalive_request( $request );
                }
            );
        }
    );

    $router->group(
        [ 'route.security' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'system.version',
                [ 'GET' ],
                static function ( Metis_Http_Request $request ): ?array {
                    return $request->path() === '/api/system/version' ? [] : null;
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_system_version_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_system_version_request( $request );
                }
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
                static function ( Metis_Http_Request $request ): array {
                    if ( ! function_exists( 'metis_contacts_carddav_handle_request' ) ) {
                        return [ 'status' => 501, 'body' => 'CardDAV not available.' ];
                    }

                    return metis_contacts_carddav_handle_request( $request );
                }
            );
        }
    );

    $router->group(
        [ 'system.cron.stack' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'system.cron',
                [ 'GET', 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    if ( ! Metis_Cron_Manager::matches_request( $request ) ) {
                        return null;
                    }

                    return [];
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_system_cron_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_system_cron_request( $request );
                }
            );
        }
    );

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_LOAD_MODULE_MANIFESTS' );
    }
    $router->group(
        [ 'route.security' ],
        static function ( Metis_Http_Router $router ): void {
            metis_register_manifest_module_routes( $router );
        }
    );
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_LOAD_MODULE_MANIFESTS_DONE' );
    }

    $router->group(
        [ 'portal.stack' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'portal.page',
                [ 'GET', 'HEAD', 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    if ( function_exists( 'metis_ajax_request_matches' ) && metis_ajax_request_matches( $request ) ) {
                        return null;
                    }

                    if ( $request->attribute( 'transport' ) !== 'portal' ) {
                        return null;
                    }

                    [ $domain, $view ] = metis_parse_portal_path( $request->path() );

                    return [
                        'domain' => $domain,
                        'view'   => $view,
                    ];
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_portal_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_portal_request( $request );
                }
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
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_ajax_request' ) ) {
                        return null;
                    }
                    return metis_router_handle_ajax_request( $request );
                }
            );
        }
    );


    $router->group(
        [ 'request.security', 'route.security' ],
        static function ( Metis_Http_Router $router ): void {
            $router->register(
                'batch.api',
                [ 'POST' ],
                static function ( Metis_Http_Request $request ): ?array {
                    if ( @preg_match( '#^/api/batch/(?P<batch_module>[A-Za-z0-9_-]+)/(?P<batch_action>[A-Za-z0-9_-]+)$#', metis_untrailingslashit( $request->path() ), $matches ) !== 1 ) {
                        return null;
                    }

                    return [
                        'batch_module' => metis_key_clean( (string) ( $matches['batch_module'] ?? '' ) ),
                        'batch_action' => metis_key_clean( (string) ( $matches['batch_action'] ?? '' ) ),
                    ];
                },
                static function ( Metis_Http_Request $request ): mixed {
                    if ( ! function_exists( 'metis_router_handle_batch_request' ) ) {
                        return null;
                    }

                    return metis_router_handle_batch_request( $request );
                }
            );
        }
    );

    if ( function_exists( 'metis_security_register_route_policies' ) ) {
        metis_security_register_route_policies();
    }

    return $router;
}

function metis_register_manifest_module_routes( Metis_Http_Router $router ): void {
    if ( ! class_exists( 'Metis' ) ) {
        return;
    }

    $service = Metis::service( 'modules' );
    if ( ! $service instanceof \Metis\Core\ModuleLoader ) {
        return;
    }

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_MODULE_ROUTES' );
    }
    $manifest_routes = $service->routes();
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_MODULE_ROUTES_DONE' );
    }

    foreach ( $manifest_routes as $route ) {
        $name = (string) ( $route['name'] ?? '' );
        $pattern = (string) ( $route['pattern'] ?? '' );
        $handler = $route['handler'] ?? null;

        if ( $name === '' || $pattern === '' || $handler === null || $handler === '' ) {
            Metis_Logger::warn( 'Skipping invalid manifest route', [
                'module' => (string) ( $route['module'] ?? '' ),
                'name'   => $name,
            ] );
            continue;
        }

        $route_handler = static function ( Metis_Http_Request $request ) use ( $handler, $name ): mixed {
            if ( is_string( $handler ) && function_exists( $handler ) ) {
                return $handler( $request );
            }

            if ( is_callable( $handler ) ) {
                return $handler( $request );
            }

            Metis_Logger::error( 'Manifest route handler unavailable', [
                'route'   => $name,
                'handler' => is_scalar( $handler ) ? (string) $handler : gettype( $handler ),
            ] );

            return Metis_Http_Response::html( 'Route handler unavailable.', 500 );
        };

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
                        $params[ $key ] = metis_text_clean( (string) $value );
                    }
                }

                return $params;
            },
            $route_handler,
            (array) ( $route['middleware'] ?? [] )
        );
    }
}

function metis_router_configure_middleware( Metis_Http_Router $router ): void {
    $router->register_middleware( 'request.normalize', static function ( Metis_Http_Request $request, callable $next ): mixed {
        if ( ! function_exists( 'metis_router_normalize_request' ) ) {
            return $next( $request );
        }
        return metis_router_normalize_request( $request, $next );
    } );
    $router->register_middleware( 'request.security', static function ( Metis_Http_Request $request, callable $next ): mixed {
        if ( ! function_exists( 'metis_router_require_request_security' ) ) {
            return $next( $request );
        }
        return metis_router_require_request_security( $request, $next );
    } );
    $router->register_middleware( 'auth.security', static function ( Metis_Http_Request $request, callable $next ): mixed {
        if ( ! function_exists( 'metis_router_require_auth_request_security' ) ) {
            return $next( $request );
        }
        return metis_router_require_auth_request_security( $request, $next );
    } );
    $router->register_middleware( 'route.security', static function ( Metis_Http_Request $request, callable $next ): mixed {
        if ( ! function_exists( 'metis_router_require_route_security' ) ) {
            return $next( $request );
        }
        return metis_router_require_route_security( $request, $next );
    } );
    $router->register_middleware( 'portal.auth', static function ( Metis_Http_Request $request, callable $next ): mixed {
        if ( ! function_exists( 'metis_router_require_portal_authentication' ) ) {
            return $next( $request );
        }
        return metis_router_require_portal_authentication( $request, $next );
    } );
    $router->register_middleware( 'portal.permissions', static function ( Metis_Http_Request $request, callable $next ): mixed {
        if ( ! function_exists( 'metis_router_require_portal_permissions' ) ) {
            return $next( $request );
        }
        return metis_router_require_portal_permissions( $request, $next );
    } );
    $router->register_middleware( 'ajax.contract', static function ( Metis_Http_Request $request, callable $next ): mixed {
        if ( ! function_exists( 'metis_router_require_ajax_contract' ) ) {
            return $next( $request );
        }
        return metis_router_require_ajax_contract( $request, $next );
    } );
    $router->register_middleware( 'ajax.security', static function ( Metis_Http_Request $request, callable $next ): mixed {
        if ( ! function_exists( 'metis_router_require_ajax_security' ) ) {
            return $next( $request );
        }
        return metis_router_require_ajax_security( $request, $next );
    } );
    $router->register_middleware( 'system.cron.security', static function ( Metis_Http_Request $request, callable $next ): mixed {
        if ( ! function_exists( 'metis_router_require_system_cron_security' ) ) {
            return $next( $request );
        }
        return metis_router_require_system_cron_security( $request, $next );
    } );
    if ( function_exists( 'metis_contacts_carddav_require_authentication' ) ) {
        $router->register_middleware( 'contacts.auth', static function ( Metis_Http_Request $request, callable $next ): mixed {
            if ( ! function_exists( 'metis_contacts_carddav_require_authentication' ) ) {
                return $next( $request );
            }
            return metis_contacts_carddav_require_authentication( $request, $next );
        } );
        $router->register_middleware_group( 'contacts.stack', [ 'contacts.auth', 'route.security' ] );
    } else {
        $router->register_middleware_group( 'contacts.stack', [ 'route.security' ] );
    }
    $router->register_middleware_group( 'system.cron.stack', [ 'system.cron.security' ] );
    $router->register_middleware_group( 'auth.stack', [ 'auth.security', 'route.security' ] );
    $router->register_middleware_group( 'portal.stack', [ 'portal.auth', 'route.security', 'request.security', 'portal.permissions' ] );
    $router->register_middleware_group( 'ajax.stack', [ 'request.security', 'ajax.contract', 'ajax.security' ] );

    $router->push_global_middleware( 'request.normalize' );
}

function metis_request_path_strip_legacy_system_prefix( string $path ): string {
    $path = '/' . ltrim( $path, '/' );
    if ( $path === '/system' || ! str_starts_with( $path, '/system/' ) ) {
        return $path;
    }

    $candidate = '/' . ltrim( substr( $path, strlen( '/system' ) ), '/' );
    foreach ( [ '/admin', '/api', '/ajax', '/media', '/account', '/auth', '/login', '/logout', '/profile' ] as $app_prefix ) {
        if ( $candidate === $app_prefix || str_starts_with( $candidate, $app_prefix . '/' ) ) {
            return $candidate;
        }
    }

    return $path;
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
        $parsed = metis_runtime_parse_url( $candidate, PHP_URL_PATH );
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
        $req_path = metis_runtime_parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }

    $site_path = metis_runtime_parse_url(metis_home_url('/'), PHP_URL_PATH) ?: '/';
    $script_dir = rtrim( dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '/index.php' ) ), '/' );

    $req_path  = '/' . ltrim($req_path, '/');
    $site_path = '/' . trim($site_path, '/') . '/';

    if ($site_path !== '//' && $site_path !== '/' && strpos($req_path . '/', $site_path) === 0) {
        $req_path = '/' . ltrim(substr($req_path, strlen($site_path) - 1), '/');
    }

    if ( $script_dir !== '' && $script_dir !== '/' ) {
        $script_prefix = '/' . ltrim( $script_dir, '/' );
        if ( $req_path === $script_prefix ) {
            $req_path = '/';
        } elseif ( str_starts_with( $req_path, $script_prefix . '/' ) ) {
            $req_path = '/' . ltrim( substr( $req_path, strlen( $script_prefix ) ), '/' );
        }
    }

    $req_path = metis_request_path_strip_legacy_system_prefix( $req_path );

    return '/' . ltrim($req_path, '/');
}

function metis_is_portal_request(): bool {
    if (php_sapi_name() === 'cli') return false;

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
    $domain = metis_key_clean($parts[0] ?? 'portal');
    $view   = metis_key_clean($parts[1] ?? 'dashboard');

    // Dedicated editor alias: /{portal}/editor/... should resolve to the
    // Website editor view without exposing the website module path.
    if ( $domain === 'editor' ) {
        $domain = 'website';
        $view = 'editor';
    }

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

function metis_runtime_asset_base_path(): string {
    return '/assets/runtime';
}

function metis_module_asset_bundle_files( string $module, string $asset, ?array $registered = null ): array {
    $module = metis_key_clean( $module );
    $asset = ltrim( (string) $asset, '/' );

    $bundles = [
        'contacts' => [
            'contacts.js' => [
                'js/contacts-app.js',
                'js/contact-list.js',
                'js/contact-detail.js',
                'js/relationship-editor.js',
                'js/import-export.js',
                'js/filters.js',
            ],
        ],
        'people' => [
            'profile.js' => [
                'js/profile-shell.js',
                'js/profile-overview.js',
                'js/profile-security.js',
                'js/profile-passkeys.js',
                'js/profile-roles.js',
                'js/profile-workspace.js',
            ],
        ],
    ];

    $paths = $bundles[ $module ][ $asset ] ?? [];
    if ( $paths === [] ) {
        return [];
    }

    if ( ! is_array( $registered ) ) {
        $registered = metis_get_module( $module );
    }
    if ( ! is_array( $registered ) ) {
        return [];
    }

    $root = metis_trailingslashit( (string) $registered['dir'] ) . 'assets/';
    $files = [];
    foreach ( $paths as $path ) {
        $file = $root . ltrim( (string) $path, '/' );
        if ( ! is_file( $file ) ) {
            return [];
        }
        $files[] = $file;
    }

    return $files;
}

function metis_module_asset_version( string $module, string $asset, ?array $registered = null ): string {
    $asset = ltrim( (string) $asset, '/' );
    $files = metis_module_asset_bundle_files( $module, $asset, $registered );
    if ( $files !== [] ) {
        $signature = [];
        foreach ( $files as $file ) {
            $signature[] = basename( $file ) . ':' . (string) filemtime( $file );
        }
        return md5( implode( '|', $signature ) );
    }

    if ( ! is_array( $registered ) ) {
        $registered = metis_get_module( $module );
    }
    if ( ! is_array( $registered ) ) {
        return METIS_VERSION;
    }

    $path = metis_trailingslashit( (string) $registered['dir'] ) . 'assets/' . $asset;
    return is_file( $path ) ? (string) filemtime( $path ) : METIS_VERSION;
}

function metis_module_asset_url( string $module, string $asset ): string {
    $module = metis_key_clean( $module );
    $asset  = ltrim( (string) $asset, '/' );
    $url = metis_home_url( trim( metis_module_asset_base_path(), '/' ) . '/' . rawurlencode( $module ) . '/' . str_replace( '%2F', '/', rawurlencode( $asset ) ) );
    return metis_add_query_arg( 'v', metis_module_asset_version( $module, $asset ), $url );
}

function metis_runtime_asset_url( string $asset, string $domain = '', string $view = '' ): string {
    $asset = ltrim( (string) $asset, '/' );
    $url = metis_home_url( trim( metis_runtime_asset_base_path(), '/' ) . '/' . str_replace( '%2F', '/', rawurlencode( $asset ) ) );
    $version = defined( 'METIS_VERSION' ) ? (string) METIS_VERSION : '1.0.0';

    $args = [
        'v' => $version,
    ];

    $domain = metis_key_clean( $domain );
    $view = metis_key_clean( $view );

    if ( $domain !== '' ) {
        $args['domain'] = $domain;
    }

    if ( $view !== '' ) {
        $args['view'] = $view;
    }

    return metis_add_query_arg( $args, $url );
}

function metis_runtime_asset_match_request( Metis_Http_Request $request ): ?array {
    $path = '/' . ltrim( $request->path(), '/' );
    $base = rtrim( metis_runtime_asset_base_path(), '/' );

    if ( ! str_starts_with( $path, $base . '/' ) ) {
        return null;
    }

    $asset = ltrim( substr( $path, strlen( $base ) ), '/' );
    if ( $asset === '' || str_contains( $asset, '..' ) ) {
        return null;
    }

    return [
        'runtime_asset_path' => $asset,
    ];
}

function metis_core_asset_match_request( Metis_Http_Request $request ): ?array {
    $path = '/' . ltrim( $request->path(), '/' );
    if ( ! str_starts_with( $path, '/assets/' ) ) {
        return null;
    }

    foreach ( [ '/assets/modules/', '/assets/runtime/', '/assets/error-pages/' ] as $reserved ) {
        if ( str_starts_with( $path, $reserved ) ) {
            return null;
        }
    }

    $asset = ltrim( substr( $path, strlen( '/assets/' ) ), '/' );
    if ( $asset === '' || str_contains( $asset, '..' ) || str_contains( $asset, '\\' ) ) {
        return null;
    }

    return [
        'core_asset_path' => $asset,
    ];
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
    $module = metis_key_clean( (string) ( $parts[0] ?? '' ) );
    $asset  = isset( $parts[1] ) ? trim( (string) $parts[1] ) : '';

    if ( $module === '' || $asset === '' || str_contains( $asset, '..' ) ) {
        return null;
    }

    return [
        'module_asset_module' => $module,
        'module_asset_path'   => $asset,
    ];
}

function metis_svg_icon_match_request( Metis_Http_Request $request ): ?array {
    $path = metis_untrailingslashit( '/' . ltrim( $request->path(), '/' ) );
    if ( preg_match( '#^/svg/([a-z0-9_-]+)$#i', $path, $matches ) !== 1 ) {
        return null;
    }

    $slug = str_replace( '_', '-', metis_key_clean( (string) ( $matches[1] ?? '' ) ) );
    if ( $slug === '' || preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) !== 1 ) {
        return null;
    }

    return [
        'svg_icon_slug' => $slug,
    ];
}

function metis_module_asset_content_type( string $path ): string {
    return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
        'css'  => 'text/css; charset=UTF-8',
        'js'   => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'  => 'font/ttf',
        'ico'  => 'image/x-icon',
        'csv'  => 'text/csv; charset=UTF-8',
        default => 'application/octet-stream',
    };
}

function metis_router_suppress_session_cookie_headers(): void {
    if ( headers_sent() ) {
        return;
    }

    header_remove( 'Set-Cookie' );
}

function metis_router_asset_cache_control_header(): string {
    return 'public, max-age=604800, stale-while-revalidate=86400';
}

/**
 * @param list<string> $files
 * @return array{etag:string,last_modified:string,last_modified_unix:int}
 */
function metis_router_asset_cache_metadata( array $files ): array {
    $parts = [];
    $latest = 0;

    foreach ( $files as $file ) {
        $mtime = is_file( $file ) ? (int) @filemtime( $file ) : 0;
        $size  = is_file( $file ) ? (int) @filesize( $file ) : 0;
        $parts[] = $file . ':' . $mtime . ':' . $size;
        if ( $mtime > $latest ) {
            $latest = $mtime;
        }
    }

    return [
        'etag' => '"' . sha1( implode( '|', $parts ) ) . '"',
        'last_modified' => gmdate( 'D, d M Y H:i:s', $latest > 0 ? $latest : time() ) . ' GMT',
        'last_modified_unix' => $latest,
    ];
}

function metis_router_request_matches_asset_cache( Metis_Http_Request $request, string $etag, int $last_modified_unix ): bool {
    $if_none_match = trim( (string) $request->header( 'if-none-match', '' ) );
    if ( $if_none_match !== '' ) {
        foreach ( array_map( 'trim', explode( ',', $if_none_match ) ) as $candidate ) {
            if ( $candidate === '*' || $candidate === $etag ) {
                return true;
            }
        }
    }

    $if_modified_since = trim( (string) $request->header( 'if-modified-since', '' ) );
    if ( $if_modified_since !== '' ) {
        $since = strtotime( $if_modified_since );
        if ( $since !== false && $last_modified_unix > 0 && $since >= $last_modified_unix ) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $files
 * @param callable():string|false $body_loader
 */
function metis_router_build_cacheable_asset_response( Metis_Http_Request $request, string $content_type, array $files, callable $body_loader ): Metis_Http_Response {
    $cache = metis_router_asset_cache_metadata( $files );
    $headers = [
        'Content-Type' => $content_type,
        'Cache-Control' => metis_router_asset_cache_control_header(),
        'ETag' => $cache['etag'],
        'Last-Modified' => $cache['last_modified'],
        'X-Content-Type-Options' => 'nosniff',
    ];

    if ( metis_router_request_matches_asset_cache( $request, $cache['etag'], $cache['last_modified_unix'] ) ) {
        return new Metis_Http_Response( 304, $headers, '' );
    }

    $body = $body_loader();
    if ( $body === false ) {
        return Metis_Http_Response::html( 'Asset unreadable.', 500 );
    }

    return new Metis_Http_Response(
        200,
        $headers,
        $request->method() === 'HEAD' ? '' : $body
    );
}

function metis_router_handle_core_asset_request( Metis_Http_Request $request ): Metis_Http_Response {
    metis_router_suppress_session_cookie_headers();

    $asset = ltrim( (string) $request->attribute( 'core_asset_path', '' ), '/' );
    $extension = strtolower( pathinfo( $asset, PATHINFO_EXTENSION ) );
    $allowed = [
        'css',
        'js',
        'json',
        'svg',
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'woff',
        'woff2',
        'ttf',
        'ico',
        'csv',
    ];

    if ( $asset === '' || str_contains( $asset, '..' ) || ! in_array( $extension, $allowed, true ) ) {
        return Metis_Http_Response::html( 'Asset not found.', 404 );
    }

    $assets_root = realpath( METIS_ROOT . '/system/assets' );
    if ( ! is_string( $assets_root ) ) {
        return Metis_Http_Response::html( 'Asset root unavailable.', 500 );
    }

    $real = realpath( $assets_root . DIRECTORY_SEPARATOR . $asset );
    if ( ! is_string( $real ) || ! str_starts_with( $real, $assets_root . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
        return Metis_Http_Response::html( 'Asset not found.', 404 );
    }

    return metis_router_build_cacheable_asset_response(
        $request,
        metis_module_asset_content_type( $real ),
        [ $real ],
        static function () use ( $real ) {
            return file_get_contents( $real );
        }
    );
}

function metis_router_handle_module_asset_request( Metis_Http_Request $request ): Metis_Http_Response {
    metis_router_suppress_session_cookie_headers();

    $module = metis_key_clean( (string) $request->attribute( 'module_asset_module', '' ) );
    $asset  = ltrim( (string) $request->attribute( 'module_asset_path', '' ), '/' );

    $registered = metis_get_module( $module );
    if ( ! is_array( $registered ) ) {
        return Metis_Http_Response::html( 'Asset not found.', 404 );
    }

    $bundle_files = metis_module_asset_bundle_files( $module, $asset, $registered );
    if ( $bundle_files !== [] ) {
        return metis_router_build_cacheable_asset_response(
            $request,
            metis_module_asset_content_type( $asset ),
            $bundle_files,
            static function () use ( $bundle_files ) {
                $body = '';
                foreach ( $bundle_files as $file ) {
                    $chunk = file_get_contents( $file );
                    if ( $chunk === false ) {
                        return false;
                    }

                    $body .= "\n/* " . basename( $file ) . " */\n" . $chunk . "\n";
                }

                return $body;
            }
        );
    }

    $file = metis_trailingslashit( (string) $registered['dir'] ) . 'assets/' . $asset;
    $real = realpath( $file );
    $assets_root = realpath( metis_trailingslashit( (string) $registered['dir'] ) . 'assets' );

    if ( ! is_string( $real ) || ! is_string( $assets_root ) || ! str_starts_with( $real, $assets_root . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
        return Metis_Http_Response::html( 'Asset not found.', 404 );
    }

    return metis_router_build_cacheable_asset_response(
        $request,
        metis_module_asset_content_type( $real ),
        [ $real ],
        static function () use ( $real ) {
            return file_get_contents( $real );
        }
    );
}

function metis_router_handle_runtime_asset_request( Metis_Http_Request $request ): Metis_Http_Response {
    metis_register_core_services();
    metis_router_suppress_session_cookie_headers();

    $asset = ltrim( (string) $request->attribute( 'runtime_asset_path', '' ), '/' );
    $query = $request->query();
    $domain = metis_key_clean( (string) ( $query['domain'] ?? '' ) );
    $view = metis_key_clean( (string) ( $query['view'] ?? '' ) );
    $service = Metis::service( 'runtime_assets' );

    if ( ! $service instanceof \Metis\Core\Services\RuntimeAssetService ) {
        return Metis_Http_Response::html( 'Runtime asset unavailable.', 500 );
    }

    if ( $asset === 'bootstrap.js' ) {
        return new Metis_Http_Response(
            200,
            [
                'Content-Type'  => 'application/javascript; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            $request->method() === 'HEAD' ? '' : $service->renderJavascript( $domain, $view )
        );
    }

    if ( $asset === 'theme.css' ) {
        return new Metis_Http_Response(
            200,
            [
                'Content-Type'  => 'text/css; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            $request->method() === 'HEAD' ? '' : $service->renderStylesheet( $domain, $view )
        );
    }


    $batch_asset_map = [
        'batch-entry.js' => [
            METIS_ASSETS_PATH . 'js/core/batch-entry/batch-entry.validation.js',
            METIS_ASSETS_PATH . 'js/core/batch-entry/batch-entry.totals.js',
            METIS_ASSETS_PATH . 'js/core/batch-entry/batch-entry.renderer.js',
            METIS_ASSETS_PATH . 'js/core/batch-entry/batch-entry.js',
        ],
        'batch-entry.css' => [
            METIS_ASSETS_PATH . 'css/core/batch-entry.css',
        ],
    ];

    if ( isset( $batch_asset_map[ $asset ] ) ) {
        $body = '';
        foreach ( $batch_asset_map[ $asset ] as $asset_file ) {
            if ( ! is_file( $asset_file ) ) {
                return Metis_Http_Response::html( 'Runtime batch asset missing.', 404 );
            }

            $chunk = file_get_contents( $asset_file );
            if ( $chunk === false ) {
                return Metis_Http_Response::html( 'Runtime batch asset unreadable.', 500 );
            }

            $body .= "\n/* " . basename( $asset_file ) . " */\n" . $chunk . "\n";
        }

        return new Metis_Http_Response(
            200,
            [
                'Content-Type' => metis_module_asset_content_type( $asset ),
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
            $request->method() === 'HEAD' ? '' : $body
        );
    }
    return Metis_Http_Response::html( 'Asset not found.', 404 );
}

function metis_router_handle_svg_icon_request( Metis_Http_Request $request ): Metis_Http_Response {
    metis_router_suppress_session_cookie_headers();

    $slug = str_replace( '_', '-', metis_key_clean( (string) $request->attribute( 'svg_icon_slug', '' ) ) );
    if ( $slug === '' || preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) !== 1 ) {
        return Metis_Http_Response::html( 'Icon not found.', 404 );
    }

    if ( ! function_exists( 'metis_navigation_svg_icon_path' ) ) {
        return Metis_Http_Response::html( 'Icon not found.', 404 );
    }

    $path = metis_navigation_svg_icon_path( $slug );
    if ( $path === '' || ! is_file( $path ) ) {
        return Metis_Http_Response::html( 'Icon not found.', 404 );
    }

    $etag = '"' . md5( $path . ':' . (string) filemtime( $path ) ) . '"';
    if ( trim( $request->header( 'if-none-match', '' ) ) === $etag ) {
        return new Metis_Http_Response(
            304,
            [
                'ETag'          => $etag,
                'Cache-Control' => 'public, max-age=604800, stale-while-revalidate=86400',
            ],
            ''
        );
    }

    $body = function_exists( 'metis_navigation_svg_icon_markup' )
        ? (string) metis_navigation_svg_icon_markup( $slug )
        : (string) file_get_contents( $path );

    if ( trim( $body ) === '' ) {
        return Metis_Http_Response::html( 'Icon unreadable.', 500 );
    }

    return new Metis_Http_Response(
        200,
        [
            'Content-Type'  => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=604800, stale-while-revalidate=86400',
            'ETag'          => $etag,
        ],
        $request->method() === 'HEAD' ? '' : $body
    );
}

function metis_router_build_request( array $attributes = [] ): Metis_Http_Request {
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_START' );
        Profiler::mark( 'ROUTER_PARSE_REQUEST' );
    }

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

    $raw_body    = metis_request_raw_body() ?: '';
    $parsed_body = metis_runtime_unslash( metis_request_post() );

    if ( empty( $parsed_body ) && str_contains( strtolower( (string) ( $headers['content-type'] ?? '' ) ), 'application/json' ) ) {
        $decoded = json_decode( $raw_body, true );
        if ( is_array( $decoded ) ) {
            $parsed_body = $decoded;
        }
    }

    $request = new Metis_Http_Request(
        strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ),
        (string) ( $_SERVER['REQUEST_URI'] ?? '/' ),
        metis_request_path_relative_to_site(),
        metis_runtime_unslash( metis_request_get() ),
        $parsed_body,
        $headers,
        metis_request_cookie(),
        metis_request_files(),
        $_SERVER,
        $raw_body,
        $attributes
    );

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_PARSE_REQUEST_DONE' );
    }

    return $request;
}

function metis_router_emit_response( Metis_Http_Response $response ): void {
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_RESOLVED' );
    }

    if ( function_exists( 'metis_send_status' ) ) {
        metis_send_status( $response->status() );
    } else {
        header( sprintf( 'HTTP/1.1 %d', $response->status() ), true, $response->status() );
    }

    if ( function_exists( 'metis_runtime_emit_security_headers' ) ) {
        metis_runtime_emit_security_headers();
    }

    $headers = $response->headers();
    $has_request_id_header = false;
    foreach ( array_keys( $headers ) as $header_name ) {
        if ( strtolower( (string) $header_name ) === 'x-metis-request-id' ) {
            $has_request_id_header = true;
            break;
        }
    }

    if ( ! $has_request_id_header && function_exists( 'metis_audit_request_id' ) ) {
        $request_id = trim( (string) metis_audit_request_id() );
        if ( $request_id !== '' ) {
            header( 'X-Metis-Request-Id: ' . $request_id, true );
        }
    }

    foreach ( $headers as $name => $value ) {
        header( $name . ': ' . $value, true );
    }

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'RENDER_START' );
    }
    echo $response->body();
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'RENDER_COMPLETE' );
    }
}

function metis_router_handle_system_version_request( Metis_Http_Request $request ): Metis_Http_Response {
    metis_register_core_services();

    return Metis_Http_Response::json(
        Metis::service( 'system_version' )->current(),
        200,
        [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
    );
}

function metis_router_normalize_request( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $method = strtoupper( $request->method() );
    $normalized = $request
        ->with_attribute( 'request_id', metis_router_request_id() )
        ->with_attribute( 'normalized_path', '/' . ltrim( $request->path(), '/' ) )
        ->with_attribute( 'normalized_method', $method )
        ->with_attribute( 'is_safe_method', in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true ) );

    return $next( $normalized );
}

function metis_router_require_portal_authentication( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    if ( defined( 'METIS_STANDALONE' ) && METIS_STANDALONE ) {
        if ( metis_user_logged_in() ) {
            return $next( $request );
        }

        $redirect_target = metis_router_current_request_redirect_target( $request );
        return Metis_Http_Response::redirect( function_exists( 'metis_auth_login_url' ) ? metis_auth_login_url( $redirect_target ) : metis_home_url( '/login' ) );
    }

    if ( metis_user_logged_in() ) {
        return $next( $request );
    }

    $redirect_target = metis_router_current_request_redirect_target( $request );
    return Metis_Http_Response::redirect(
        function_exists( 'metis_auth_login_url' )
            ? metis_auth_login_url( $redirect_target )
            : metis_home_url( '/login' )
    );
}

function metis_router_current_request_redirect_target( Metis_Http_Request $request ): string {
    $path = '/' . ltrim( (string) $request->path(), '/' );
    $query = '';

    $uri_parts = metis_runtime_parse_url( (string) $request->uri() );
    if ( is_array( $uri_parts ) ) {
        $uri_path = (string) ( $uri_parts['path'] ?? '' );
        if ( $uri_path !== '' ) {
            $path = '/' . ltrim( $uri_path, '/' );
        }
        if ( isset( $uri_parts['query'] ) && is_string( $uri_parts['query'] ) ) {
            $query = trim( $uri_parts['query'] );
        }
    }

    if ( $path === '//' ) {
        $path = '/';
    }

    $site_path = (string) ( metis_runtime_parse_url( metis_home_url( '/' ), PHP_URL_PATH ) ?? '/' );
    $site_path = '/' . trim( $site_path, '/' );
    if ( $site_path !== '/' ) {
        if ( $path === $site_path ) {
            $path = '/';
        } elseif ( str_starts_with( $path, $site_path . '/' ) ) {
            $path = '/' . ltrim( substr( $path, strlen( $site_path ) ), '/' );
        }
    }

    $target = $path;
    if ( $query !== '' ) {
        $target .= '?' . $query;
    }

    return $target;
}

function metis_router_require_system_cron_security( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    try {
        Metis_Cron_Manager::authorize_request( $request );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        $message = metis_router_public_security_message( $e );
        return Metis_Http_Response::json(
            [
                'success' => false,
                'data'    => [
                    'message' => $message,
                    'code'    => $e->code_name(),
                ],
            ],
            $e->status(),
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    return $next( $request );
}

function metis_router_route_permission_for_request( Metis_Http_Request $request ): string {
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

    if ( in_array( $route_name, [ 'help.index', 'help.search', 'help.article', 'help.category' ], true ) ) {
        return 'view';
    }

    if ( in_array( $route_name, [ 'help.admin.articles', 'help.admin.create', 'help.admin.edit' ], true ) ) {
        return 'manage';
    }

    if ( in_array( $route_name, [ 'forms.public', 'newsletter.public.signup', 'donations.recurring.manage', 'manage.profile', 'manage.access', 'manage.statement' ], true ) ) {
        return $method === 'POST' ? 'create' : 'view';
    }

    if ( in_array( $route_name, [ 'newsletter.open', 'newsletter.click', 'newsletter.unsubscribe' ], true ) ) {
        return 'view';
    }

    if ( in_array( $route_name, [ 'website.theme_css', 'website.homepage', 'website.page' ], true ) ) {
        return 'view';
    }

    if ( $route_name === 'webhook.gateway' ) {
        return 'create';
    }

    if ( in_array( $route_name, [ 'auth.resolve', 'auth.passkeys.begin', 'auth.passkeys.complete' ], true ) ) {
        return 'create';
    }

    if ( $route_name === 'batch.api' ) {
        return 'create';
    }

    return 'view';
}

function metis_router_route_policy( Metis_Http_Request $request ): ?Metis_Security_Policy {
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
            // Module assets are shared by both admin and public surfaces.
            // Do not bind to a module permission gate for static asset delivery.
            $module = null;
            // Public pages may load module-owned assets (forms, website blocks, etc.).
            // Keep asset delivery readable without a portal session.
            $require_authentication = false;
            $require_session = false;
            $rate_limit = 600;
            break;

        case 'portal.page':
            $module = metis_key_clean( (string) $request->attribute( 'domain', 'portal' ) );
            $require_authentication = true;
            $require_session = true;
            $rate_limit = 360;
            break;

        case 'help.index':
        case 'help.search':
        case 'help.article':
        case 'help.category':
            $module = 'help';
            $require_authentication = true;
            $require_session = true;
            $rate_limit = 240;
            break;

        case 'help.admin.articles':
        case 'help.admin.create':
        case 'help.admin.edit':
            $module = 'help';
            $require_authentication = true;
            $require_session = true;
            $rate_limit = 180;
            break;

        case 'forms.public':
        case 'newsletter.public.signup':
            // Public form views/submissions must be accessible without portal auth.
            $module = null;
            $require_authentication = false;
            $require_session = false;
            $require_nonce = false;
            $rate_limit = 120;
            $rate_window = 60;
            break;

        case 'donations.recurring.manage':
        case 'manage.profile':
        case 'manage.access':
        case 'manage.statement':
            $module = null;
            $require_authentication = false;
            $require_session = false;
            $require_nonce = false;
            $rate_limit = 60;
            $rate_window = 60;
            break;

        case 'newsletter.open':
        case 'newsletter.click':
        case 'newsletter.unsubscribe':
            $module = null;
            $require_authentication = false;
            $require_session = false;
            $require_nonce = false;
            $rate_limit = 240;
            $rate_window = 60;
            break;

        case 'website.theme_css':
        case 'website.homepage':
        case 'website.page':
            // Public website routes are intentionally anonymous, but still pass route.security
            // so rate limiting, policy registration, audit context, and fail-secure handling apply.
            $module = null;
            $require_authentication = false;
            $require_session = false;
            $require_nonce = false;
            $rate_limit = 300;
            $rate_window = 60;
            break;

        case 'webhook.gateway':
            $module = null;
            $require_authentication = false;
            $require_session = false;
            $rate_limit = 180;
            break;

        case 'auth.resolve':
        case 'auth.passkeys.begin':
        case 'auth.passkeys.complete':
            $module = null;
            $require_authentication = false;
            $require_session = false;
            $require_nonce = false;
            $rate_limit = 180;
            $rate_window = 60;
            break;

        case 'auth.session.keepalive':
            $module = null;
            $require_authentication = true;
            $require_session = true;
            $require_nonce = false;
            $rate_limit = 240;
            $rate_window = 60;
            break;

        case 'batch.api':
            $module = metis_key_clean( (string) $request->attribute( 'batch_module', '' ) );
            $require_authentication = true;
            $require_session = true;
            $require_nonce = true;
            $rate_limit = 120;
            break;

        default:
            return null;
    }

    $permission = metis_router_route_permission_for_request( $request );
    $operation = 'route.' . str_replace( '.', '_', $route_name );
    if ( $module !== null && $module !== '' ) {
        $operation .= '.' . $module . '.' . $permission;
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

function metis_router_route_context( Metis_Http_Request $request ): array {
    $context = metis_security_runtime_request_context( array_merge(
        $request->query(),
        $request->parsed_body(),
        [ 'route' => (string) $request->attribute( 'route_name', '' ) ]
    ) );

    $meta = (array) ( $context['meta'] ?? [] );
    $meta['request_id'] = metis_router_request_id();
    $meta['path'] = $request->path();
    $meta['method'] = strtoupper( $request->method() );
    $meta['route_name'] = (string) $request->attribute( 'route_name', '' );

    $context['meta'] = $meta;
    return $context;
}

function metis_router_route_security_failure_response( Metis_Http_Request $request, Metis_Security_Enclave_Exception $e ): Metis_Http_Response {
    $route_name = (string) $request->attribute( 'route_name', '' );
    $message = metis_router_public_security_message( $e );
    $request_id = metis_router_request_id();
    $endpoint = '/' . ltrim( (string) $request->path(), '/' );
    $status_code = (int) $e->status();
    $code_name = metis_key_clean( $e->code_name() );

    if ( function_exists( 'metis_audit_log_security' ) ) {
        metis_audit_log_security( 'route_action_failed', [
            'module'   => metis_key_clean( (string) $request->attribute( 'domain', '' ) ),
            'severity' => $status_code >= 500 ? 'error' : 'warning',
            'outcome'  => 'blocked',
            'resource' => [
                'type'  => 'route_action',
                'id'    => $route_name !== '' ? $route_name : 'unknown',
                'label' => $code_name,
            ],
            'context'  => [
                'route'         => $route_name,
                'endpoint'      => $endpoint,
                'status_code'   => $status_code,
                'error_code'    => $code_name,
                'error_message' => $message,
                'request_id'    => $request_id,
            ],
        ] );
    }

    if ( in_array( $route_name, [ 'webhook.gateway', 'system.cron', 'ajax.metis.api', 'auth.resolve', 'auth.passkeys.begin', 'auth.passkeys.complete' ], true ) ) {
        return Metis_Http_Response::json(
            [ 'success' => false, 'data' => [ 'message' => $message, 'code' => $e->code_name() ] ],
            $status_code,
            [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'X-Metis-Request-Id' => $request_id,
            ]
        );
    }

    if ( in_array( $route_name, [ 'contacts.carddav', 'assets.module' ], true ) ) {
        return new Metis_Http_Response(
            $status_code,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Metis-Request-Id' => $request_id,
            ],
            $message
        );
    }

    return Metis_Http_Response::html(
        '<div class="metis-error">' . metis_esc_html( $message ) . '</div>',
        $status_code,
        [ 'X-Metis-Request-Id' => $request_id ]
    );
}

function metis_router_public_security_message( Metis_Security_Enclave_Exception $e ): string {
    return match ( metis_key_clean( $e->code_name() ) ) {
        'invalid_nonce', 'csrf_failed' => 'Security check failed.',
        'authentication_required', 'session_required', 'invalid_session_integrity' => 'Authentication is required.',
        'permission_denied' => 'You do not have permission to perform this action.',
        default => 'Request rejected.',
    };
}

function metis_router_require_route_security( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_PERMISSION_CHECK' );
    }
    $policy = metis_router_route_policy( $request );
    if ( ! $policy instanceof Metis_Security_Policy ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_PERMISSION_CHECK_DONE' );
        }
        return $next( $request );
    }
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_PERMISSION_CHECK_DONE' );
    }

    if ( function_exists( 'metis_security_register_route_policies' ) ) {
        metis_security_register_route_policies();
    }

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_ENCLAVE_CHECK' );
    }
    $enclave = metis_security_enclave();
    if ( ! $enclave->has_policy( $policy->operation ) ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_ENCLAVE_CHECK_DONE' );
        }
        return metis_router_route_security_failure_response(
            $request,
            new Metis_Security_Enclave_Exception(
                'Unregistered enclave operation.',
                'operation_not_registered',
                403,
                [ 'operation' => $policy->operation ]
            )
        );
    }

    try {
        $enclave->execute(
            $policy->operation,
            metis_router_route_context( $request ),
            static function () {
                return true;
            }
        );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_ENCLAVE_CHECK_DONE' );
        }
        return metis_router_route_security_failure_response( $request, $e );
    }
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_ENCLAVE_CHECK_DONE' );
    }

    return $next(
        $request
            ->with_attribute( 'module', $policy->module ?? '' )
            ->with_attribute( 'permission', $policy->permission )
    );
}

function metis_router_portal_nonce_map(): array {
    return [
        'portal/settings' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_portal_settings' ],
        'settings/general' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_general' ],
        'settings/identity' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_general' ],
        'settings/newsletter' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_newsletter' ],
        'settings/organization' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_newsletter' ],
        'settings/api' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_api' ],
        'settings/developers' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_developers_api' ],
        'settings/developers_api' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_developers_api' ],
        'settings/system' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_runtime' ],
        'settings/runtime' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_runtime' ],
        'settings/logging' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_logging' ],
        'settings/security' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_system_health' ],
        'settings/drive' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_drive' ],
        'settings/calendar' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_calendar' ],
        'settings/workspace' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_google_workspace' ],
        'settings/data' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_backup' ],
        'settings/backup' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_backup' ],
        'settings/scheduler' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_scheduler' ],
        'settings/menu' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_navigation' ],
        'settings/jobs_tasks' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_jobs_tasks' ],
        'settings/profile' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_profile' ],
        'settings/accessibility' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_accessibility' ],
        'settings/user_experience' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_user_experience' ],
        'settings/customization' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_branding' ],
        'settings/platform' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_about' ],
        'settings/help' => [ 'field' => 'metis_settings_nonce', 'action' => 'metis_save_settings_help' ],
        'donations/transactions' => [ 'field' => 'metis_batch_nonce', 'action' => 'metis_create_batch' ],
    ];
}

function metis_router_request_requires_csrf( Metis_Http_Request $request ): bool {
    $method = strtoupper( $request->method() );
    if ( in_array( $method, [ 'POST', 'PATCH', 'DELETE' ], true ) ) {
        return true;
    }

    return (string) $request->attribute( 'route_name', '' ) === 'ajax.metis.api';
}

function metis_router_request_origin_is_valid( Metis_Http_Request $request ): bool {
    $site_host = strtolower( (string) metis_runtime_parse_url( metis_home_url( '/' ), PHP_URL_HOST ) );
    $trusted_hosts = [];
    if ( $site_host !== '' ) {
        $trusted_hosts[] = $site_host;
    }

    $request_server = $request->server();
    $request_host = strtolower( trim( (string) ( $request_server['HTTP_HOST'] ?? '' ) ) );
    if ( $request_host !== '' ) {
        $request_host = preg_replace( '/:\d+$/', '', $request_host ) ?: $request_host;
        if ( $request_host !== '' ) {
            $trusted_hosts[] = $request_host;
        }
    }

    $forwarded_host = strtolower( trim( (string) ( $request_server['HTTP_X_FORWARDED_HOST'] ?? '' ) ) );
    if ( $forwarded_host !== '' ) {
        $primary_forwarded = trim( explode( ',', $forwarded_host )[0] ?? '' );
        $primary_forwarded = preg_replace( '/:\d+$/', '', $primary_forwarded ) ?: $primary_forwarded;
        if ( $primary_forwarded !== '' ) {
            $trusted_hosts[] = $primary_forwarded;
        }
    }

    $trusted_hosts = array_values( array_unique( array_filter( $trusted_hosts, static fn ( $host ): bool => is_string( $host ) && $host !== '' ) ) );
    if ( empty( $trusted_hosts ) ) {
        return true;
    }

    $validated_header = false;
    foreach ( [ 'origin', 'referer' ] as $header_name ) {
        $header_value = trim( $request->header( $header_name ) );
        if ( $header_value === '' ) {
            continue;
        }
        $validated_header = true;

        $header_host = strtolower( (string) metis_runtime_parse_url( $header_value, PHP_URL_HOST ) );
        if ( $header_host !== '' ) {
            foreach ( $trusted_hosts as $trusted_host ) {
                if ( hash_equals( (string) $trusted_host, $header_host ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    // Some same-origin clients/proxies may omit Origin/Referer.
    // In that case, defer protection to nonce/session checks.
    return ! $validated_header;
}

function metis_router_request_nonce_candidates( Metis_Http_Request $request ): array {
    $candidates = [];
    foreach ( [ 'x-metis-csrf-token', 'x-csrf-token', 'x-wp-nonce', 'x-metis-nonce' ] as $header_name ) {
        $value = trim( $request->header( $header_name ) );
        if ( $value !== '' ) {
            $candidates[] = $value;
        }
    }

    $fields = [ 'csrf_token', 'metis_action_nonce', 'security', 'nonce' ];
    if ( (string) $request->attribute( 'route_name', '' ) === 'portal.page' ) {
        $domain = metis_key_clean( (string) $request->attribute( 'domain', '' ) );
        $view   = metis_key_clean( (string) $request->attribute( 'view', '' ) );
        $key    = $domain . '/' . $view;
        $map    = metis_router_portal_nonce_map();
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

function metis_router_request_nonce_actions( Metis_Http_Request $request ): array {
    $actions = [];
    $route_name = (string) $request->attribute( 'route_name', '' );
    $input = $request->input();

    if ( isset( $input['metis_csrf_action'] ) && is_scalar( $input['metis_csrf_action'] ) ) {
        $explicit = trim( (string) $input['metis_csrf_action'] );
        if ( $explicit !== '' ) {
            $actions[] = $explicit;
        }
    }

    if ( $route_name === 'ajax.metis.api' ) {
        $ajax_action = metis_key_clean( (string) $request->attribute( 'ajax_action', metis_ajax_request_action( $request ) ) );
        if ( $ajax_action !== '' ) {
            $actions[] = metis_ajax_nonce_action( $ajax_action );

            $controller = $request->attribute( 'ajax_controller' );
            if ( is_array( $controller ) && ! empty( $controller['nonce_action'] ) ) {
                $actions[] = (string) $controller['nonce_action'];
            } else {
                $registry = function_exists( 'metis_ajax_registry' ) ? metis_ajax_registry() : null;
                $registered = is_object( $registry ) && method_exists( $registry, 'get' )
                    ? $registry->get( $ajax_action )
                    : null;
                if ( is_array( $registered ) && ! empty( $registered['nonce_action'] ) ) {
                    $actions[] = (string) $registered['nonce_action'];
                }
            }

            // Compatibility fallback for module-scoped nonces used by legacy/fallback
            // editor surfaces. Action-scoped nonce remains preferred above.
            if ( str_starts_with( $ajax_action, 'metis_website_' ) ) {
                $actions[] = 'metis_website';
            } elseif ( str_starts_with( $ajax_action, 'metis_newsletter_' ) ) {
                $actions[] = 'metis_newsletter';
            }
        }
    }


    if ( $route_name === 'batch.api' ) {
        $actions[] = 'metis_batch_api';
    }
    if ( $route_name === 'auth.resolve' ) {
        $actions[] = 'metis_auth_resolve';
    }
    if ( $route_name === 'auth.passkeys.begin' ) {
        $actions[] = function_exists( 'metis_auth_passkey_begin_nonce_action' )
            ? metis_auth_passkey_begin_nonce_action()
            : 'metis_auth_passkey_begin';
    }
    if ( $route_name === 'auth.passkeys.complete' ) {
        $actions[] = function_exists( 'metis_auth_passkey_complete_nonce_action' )
            ? metis_auth_passkey_complete_nonce_action()
            : 'metis_auth_passkey_complete';
    }
    if ( $route_name === 'auth.session.keepalive' ) {
        $actions[] = 'metis_core';
    }
    if ( $route_name === 'portal.page' ) {
        $domain = metis_key_clean( (string) $request->attribute( 'domain', '' ) );
        $view   = metis_key_clean( (string) $request->attribute( 'view', '' ) );
        $key    = $domain . '/' . $view;
        $map    = metis_router_portal_nonce_map();

        if ( isset( $map[ $key ]['action'] ) ) {
            $actions[] = (string) $map[ $key ]['action'];
        }

    }

    return array_values( array_unique( array_filter( $actions, 'strlen' ) ) );
}

function metis_router_require_auth_request_security( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    if ( ! metis_router_request_origin_is_valid( $request ) ) {
        return metis_router_request_security_failure_response( $request, 'Cross-site request rejected.', 'csrf_failed', 403 );
    }

    if ( ! metis_router_request_nonce_is_valid( $request ) ) {
        return metis_router_request_security_failure_response( $request, 'Invalid request nonce.', 'invalid_nonce', 403 );
    }

    return $next( $request );
}

function metis_router_request_nonce_is_valid( Metis_Http_Request $request ): bool {
    $candidates = metis_router_request_nonce_candidates( $request );
    $actions    = metis_router_request_nonce_actions( $request );

    if ( $candidates === [] || $actions === [] ) {
        return false;
    }

    foreach ( $actions as $action ) {
        foreach ( $candidates as $candidate ) {
            if ( metis_runtime_verify_nonce( $candidate, $action ) ) {
                return true;
            }
        }
    }

    return false;
}

function metis_router_security_token_fingerprint( string $value ): string {
    $value = trim( $value );
    if ( $value === '' ) {
        return '';
    }

    return substr( hash( 'sha256', $value ), 0, 12 ) . ':' . strlen( $value );
}

function metis_router_log_security_failure( Metis_Http_Request $request, string $code, int $status ): void {
    $route_name = (string) $request->attribute( 'route_name', '' );
    $path = (string) $request->path();
    $is_action_route = $route_name === 'ajax.metis.api'
        || $route_name === 'batch.api'
        || in_array( $route_name, [ 'auth.resolve', 'auth.passkeys.begin', 'auth.passkeys.complete', 'auth.session.keepalive' ], true )
        || stripos( $path, '/api/ajax' ) !== false
        || str_starts_with( $path, '/api/' );
    if ( ! $is_action_route ) {
        return;
    }

    $input = $request->input();
    $token_fields = [ 'csrf_token', 'metis_action_nonce', 'security', 'nonce' ];
    $token_debug = [];
    foreach ( $token_fields as $field ) {
        if ( isset( $input[ $field ] ) && is_scalar( $input[ $field ] ) ) {
            $fp = metis_router_security_token_fingerprint( (string) $input[ $field ] );
            if ( $fp !== '' ) {
                $token_debug[ $field ] = $fp;
            }
        }
    }

    $candidate_debug = [];
    foreach ( array_slice( metis_router_request_nonce_candidates( $request ), 0, 6 ) as $candidate ) {
        if ( ! is_string( $candidate ) ) {
            continue;
        }
        $fp = metis_router_security_token_fingerprint( $candidate );
        if ( $fp !== '' ) {
            $candidate_debug[] = $fp;
        }
    }

    $site_host = strtolower( (string) metis_runtime_parse_url( metis_home_url( '/' ), PHP_URL_HOST ) );
    $origin = trim( $request->header( 'origin' ) );
    $referer = trim( $request->header( 'referer' ) );
    $origin_host = strtolower( (string) metis_runtime_parse_url( $origin, PHP_URL_HOST ) );
    $referer_host = strtolower( (string) metis_runtime_parse_url( $referer, PHP_URL_HOST ) );
    $ajax_action = metis_key_clean( (string) $request->attribute( 'ajax_action', metis_ajax_request_action( $request ) ) );

    if ( class_exists( 'Metis_Logger' ) ) {
        Metis_Logger::warn( 'SECURITY router.reject', [
            'status' => $status,
            'code' => $code,
            'method' => strtoupper( (string) $request->method() ),
            'path' => $path,
            'route_name' => $route_name,
            'ajax_action' => $ajax_action,
            'input_action' => isset( $input['action'] ) && is_scalar( $input['action'] ) ? metis_key_clean( (string) $input['action'] ) : '',
            'csrf_action' => isset( $input['metis_csrf_action'] ) && is_scalar( $input['metis_csrf_action'] ) ? (string) $input['metis_csrf_action'] : '',
            'nonce_actions' => metis_router_request_nonce_actions( $request ),
            'candidate_count' => count( metis_router_request_nonce_candidates( $request ) ),
            'candidate_fingerprints' => $candidate_debug,
            'token_fingerprints' => $token_debug,
            'site_host' => $site_host,
            'origin_host' => $origin_host,
            'referer_host' => $referer_host,
        ] );
    }
}

function metis_router_request_security_failure_response( Metis_Http_Request $request, string $message, string $code, int $status ): Metis_Http_Response {
    metis_router_log_security_failure( $request, $code, $status );

    return metis_router_route_security_failure_response(
        $request,
        new Metis_Security_Enclave_Exception( $message, $code, $status )
    );
}

function metis_router_request_id(): string {
    if ( function_exists( 'metis_audit_request_id' ) ) {
        $request_id = trim( (string) metis_audit_request_id() );
        if ( $request_id !== '' ) {
            return $request_id;
        }
    }

    return 'router-' . substr( sha1( uniqid( 'router-', true ) ), 0, 16 );
}

function metis_router_require_request_security( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    if ( ! metis_router_request_requires_csrf( $request ) ) {
        return $next( $request );
    }

    if ( ! metis_router_request_origin_is_valid( $request ) ) {
        return metis_router_request_security_failure_response( $request, 'Cross-site request rejected.', 'csrf_failed', 403 );
    }

    if ( ! metis_auth_session_integrity_is_valid() ) {
        return metis_router_request_security_failure_response( $request, 'Invalid session integrity.', 'invalid_session_integrity', 401 );
    }

    if ( ! metis_router_request_nonce_is_valid( $request ) ) {
        return metis_router_request_security_failure_response( $request, 'Invalid request nonce.', 'invalid_nonce', 403 );
    }

    return $next(
        $request->with_attribute(
            'request_security',
            [
                'csrf_validated'      => true,
                'origin_validated'    => true,
                'session_integrity'   => true,
                'nonce_actions'       => metis_router_request_nonce_actions( $request ),
                'token_candidates'    => metis_router_request_nonce_candidates( $request ),
            ]
        )
    );
}

function metis_router_require_portal_permissions( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $domain = metis_key_clean( (string) $request->attribute( 'domain', '' ) );
    $view   = metis_key_clean( (string) $request->attribute( 'view', '' ) );

    try {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_PERMISSION_CHECK' );
        }
        metis_security_authorize_view( $domain, $view );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_PERMISSION_CHECK_DONE' );
        }
        $message = metis_router_public_security_message( $e );
        return Metis_Http_Response::html(
            '<div class="metis-error">' . metis_esc_html( $message ) . '</div>',
            $e->status()
        );
    }
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_PERMISSION_CHECK_DONE' );
    }

    return $next( $request );
}

function metis_router_require_ajax_contract( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $ajax_action = metis_key_clean( (string) $request->attribute( 'ajax_action', '' ) );
    $controller  = metis_ajax_registry()->get( $ajax_action );

    if ( ! is_array( $controller ) ) {
        return Metis_Http_Response::json(
            [
                'status' => 'error',
                'message' => 'Unregistered AJAX controller.',
                'errors' => [ 'code' => 'ajax_controller_missing' ],
                'success' => false,
                'data' => [ 'message' => 'Unregistered AJAX controller.', 'code' => 'ajax_controller_missing' ],
            ],
            404
        );
    }

    try {
        $validated = metis_ajax_validate_request( $request, $controller );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        $message = metis_router_public_security_message( $e );
        return Metis_Http_Response::json(
            [
                'status' => 'error',
                'message' => $message,
                'errors' => [ 'code' => $e->code_name(), 'details' => (array) ( $e->context()['errors'] ?? [] ) ],
                'success' => false,
                'data' => [
                    'message' => $message,
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

function metis_router_require_ajax_security( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $ajax_action = metis_key_clean( (string) $request->attribute( 'ajax_action', '' ) );
    $controller  = $request->attribute( 'ajax_controller' );
    $module      = metis_key_clean( (string) ( is_array( $controller ) ? ( $controller['module'] ?? '' ) : '' ) );

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

    if ( function_exists( 'metis_security_register_ajax_policies' ) ) {
        metis_security_register_ajax_policies();
    }

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_ENCLAVE_CHECK' );
    }
    if ( ! $enclave->has_policy( $operation ) ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_ENCLAVE_CHECK_DONE' );
        }
        return Metis_Http_Response::json(
            [
                'status' => 'error',
                'message' => 'Unregistered enclave action.',
                'errors' => [ 'code' => 'operation_not_registered' ],
                'success' => false,
                'data' => [
                    'message' => 'Unregistered enclave action.',
                    'code' => 'operation_not_registered',
                ],
            ],
            403
        );
    }

    try {
        $enclave->execute(
            $operation,
            metis_security_runtime_request_context( (array) $request->attribute( 'validated_input', $request->input() ) ),
            static function () {
                return true;
            }
        );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        if ( class_exists( 'Profiler', false ) ) {
            Profiler::mark( 'ROUTER_ENCLAVE_CHECK_DONE' );
        }
        $message = metis_router_public_security_message( $e );
        return Metis_Http_Response::json(
            [
                'success' => false,
                'data'    => [
                    'message' => $message,
                    'code'    => $e->code_name(),
                ],
            ],
            $e->status()
        );
    }
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_ENCLAVE_CHECK_DONE' );
    }

    $log_success = class_exists( 'Core_Settings_Service', false )
        ? (bool) Core_Settings_Service::get( 'audit_log_successful_ajax_authorizations', false )
        : false;
    if ( $log_success ) {
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
    }

    return $next( $request->with_attribute( 'module', $module )->with_attribute( 'permission', $permission ) );
}


function metis_router_handle_batch_request( Metis_Http_Request $request ): Metis_Http_Response {
    require_once METIS_SRC_PATH . 'Metis/Core/Api/Batch/BatchValidator.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Api/Batch/BatchProcessor.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Api/Batch/BatchResponse.php';
    require_once METIS_SRC_PATH . 'Metis/Core/Api/Batch/BatchController.php';

    $controller = new Metis_Batch_Controller();
    $response = $controller->handle( $request );

    Metis_Logger::info( 'Router dispatched batch request', [
        'route' => 'batch.api',
        'module' => (string) $request->attribute( 'batch_module', '' ),
        'action' => (string) $request->attribute( 'batch_action', '' ),
        'status' => $response->status(),
    ] );

    return $response;
}
function metis_router_handle_portal_request( Metis_Http_Request $request ): Metis_Http_Response {
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_PORTAL_HANDLER' );
    }

    $domain = metis_key_clean( (string) $request->attribute( 'domain', 'portal' ) );
    $view   = metis_key_clean( (string) $request->attribute( 'view', 'dashboard' ) );

    metis_set_query_var( 'metis_domain', $domain );
    metis_set_query_var( 'metis_view', $view );

    global $metis_query_state;
    if ( $metis_query_state instanceof MetisQueryState ) {
        $metis_query_state->is_404      = false;
        $metis_query_state->is_home     = false;
        $metis_query_state->is_archive  = false;
        $metis_query_state->is_singular = false;
    }

    nocache_headers();
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    if ( ! function_exists( 'metis_sidebar_nav' ) ) {
        require_once METIS_SRC_PATH . 'Metis/Core/CoreHelpers.php';
    }

    $shell = METIS_SRC_PATH . 'Metis/Core/Runtime/ShellTemplate.php';
    if ( ! file_exists( $shell ) ) {
        return Metis_Http_Response::html( '<div class="metis-error">METIS shell is missing.</div>', 500 );
    }

    ob_start();
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_SHELL_INCLUDE' );
    }
    if ( function_exists( 'metis_security_trusted_include' ) ) {
        metis_security_trusted_include( $shell );
    } else {
        require_once $shell;
    }
    $body = (string) ob_get_clean();
    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_SHELL_INCLUDE_DONE' );
    }

    Metis_Logger::info( 'Router dispatched portal request', [
        'route'  => 'portal.page',
        'domain' => $domain,
        'view'   => $view,
    ] );

    if ( class_exists( 'Profiler', false ) ) {
        Profiler::mark( 'ROUTER_PORTAL_HANDLER_DONE' );
    }

    return Metis_Http_Response::html( $body, 200, [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ] );
}

function metis_router_handle_ajax_request( Metis_Http_Request $request ): Metis_Http_Response {
    $ajax_action = metis_key_clean( (string) $request->attribute( 'ajax_action', '' ) );
    $request_id  = metis_router_request_id();
    $endpoint    = '/' . ltrim( (string) $request->path(), '/' );
    $endpoint_url = metis_ajax_endpoint_url();
    $route_name  = 'ajax.metis.api';

    try {
        if ( ! metis_ajax_handler_registry()->has( $ajax_action ) ) {
            throw new Metis_Security_Enclave_Exception( 'AJAX controller not found.', 'ajax_handler_missing', 404 );
        }
        $dispatched = metis_ajax_dispatch_handler( $ajax_action );
    } catch ( Metis_Security_Enclave_Exception $e ) {
        $message = metis_router_public_security_message( $e );
        $controller = metis_ajax_registry()->get( $ajax_action );
        $module = metis_key_clean( (string) ( is_array( $controller ) ? ( $controller['module'] ?? '' ) : '' ) );
        if ( $module === '' ) {
            $module = (string) metis_security_infer_module_from_ajax_action( $ajax_action );
        }
        $status_code = (int) $e->status();

        if ( function_exists( 'metis_audit_log_security' ) ) {
            metis_audit_log_security( 'ajax_action_failed', [
                'module'   => $module,
                'severity' => 'warning',
                'outcome'  => 'blocked',
                'resource' => [
                    'type'  => 'ajax_action',
                    'id'    => $ajax_action,
                    'label' => $e->code_name(),
                ],
                'context'  => [
                    'route'         => $route_name,
                    'endpoint'      => $endpoint,
                    'endpoint_url'  => $endpoint_url,
                    'status_code'   => $status_code,
                    'error_code'    => $e->code_name(),
                    'error_message' => $message,
                    'request_id'    => $request_id,
                ],
            ] );
        }

        Metis_Logger::warn( 'AJAX action blocked by security policy', [
            'route'        => $route_name,
            'endpoint'     => $endpoint,
            'endpoint_url' => $endpoint_url,
            'action'       => $ajax_action,
            'module'       => $module,
            'status'       => $status_code,
            'error_code'   => $e->code_name(),
            'request_id'   => $request_id,
        ] );

        return Metis_Http_Response::json(
            [
                'status'  => 'error',
                'message' => $message,
                'errors'  => [ 'code' => $e->code_name() ],
                'success' => false,
                'data'    => [ 'message' => $message, 'code' => $e->code_name() ],
            ],
            $e->status(),
            [ 'X-Metis-Request-Id' => $request_id ]
        );
    } catch ( Throwable $e ) {
        $controller = metis_ajax_registry()->get( $ajax_action );
        $module = metis_key_clean( (string) ( is_array( $controller ) ? ( $controller['module'] ?? '' ) : '' ) );
        if ( $module === '' ) {
            $module = (string) metis_security_infer_module_from_ajax_action( $ajax_action );
        }

        if ( function_exists( 'metis_audit_log_security' ) ) {
            metis_audit_log_security( 'ajax_action_failed', [
                'module'   => $module,
                'severity' => 'error',
                'outcome'  => 'failed',
                'resource' => [
                    'type'  => 'ajax_action',
                    'id'    => $ajax_action,
                    'label' => 'ajax_handler_exception',
                ],
                'context'  => [
                    'route'         => $route_name,
                    'endpoint'      => $endpoint,
                    'endpoint_url'  => $endpoint_url,
                    'status_code'   => 500,
                    'error_code'    => 'ajax_handler_exception',
                    'error_message' => $e->getMessage(),
                    'request_id'    => $request_id,
                ],
            ] );
        }

        Metis_Logger::error( 'AJAX action failed with unhandled exception', [
            'route'        => $route_name,
            'endpoint'     => $endpoint,
            'endpoint_url' => $endpoint_url,
            'action'       => $ajax_action,
            'module'       => $module,
            'exception'    => get_class( $e ),
            'message'      => $e->getMessage(),
            'request_id'   => $request_id,
        ] );

        $message = 'Metis could not complete the request.';
        return Metis_Http_Response::json(
            [
                'status'  => 'error',
                'message' => $message,
                'errors'  => [ 'code' => 'ajax_handler_exception' ],
                'success' => false,
                'data'    => [ 'message' => $message, 'code' => 'ajax_handler_exception' ],
                'meta'    => [
                    'request_id' => $request_id,
                    'action'     => $ajax_action,
                    'endpoint'   => $endpoint,
                ],
            ],
            500,
            [ 'X-Metis-Request-Id' => $request_id ]
        );
    }

    $status_code = (int) ( $dispatched['status'] ?? 200 );
    $payload = is_array( $dispatched['body'] ?? null ) ? $dispatched['body'] : [];
    if ( ! array_key_exists( 'success', $payload ) && ! array_key_exists( 'status', $payload ) ) {
        $payload = [
            'success' => $status_code < 400,
            'status'  => $status_code < 400 ? 'success' : 'error',
            'message' => $status_code < 400 ? 'Operation completed' : 'Operation failed',
            'data' => $payload,
            'errors' => [],
        ];
    } elseif ( ! array_key_exists( 'status', $payload ) && array_key_exists( 'success', $payload ) ) {
        $payload['status'] = ! empty( $payload['success'] ) ? 'success' : 'error';
    } elseif ( ! array_key_exists( 'success', $payload ) && array_key_exists( 'status', $payload ) ) {
        $payload['success'] = (string) $payload['status'] === 'success';
    }

    if ( ! array_key_exists( 'message', $payload ) || ! is_string( $payload['message'] ) || trim( (string) $payload['message'] ) === '' ) {
        $payload['message'] = ! empty( $payload['success'] ) ? 'Operation completed' : 'Operation failed';
    }

    if ( ! array_key_exists( 'errors', $payload ) || ! is_array( $payload['errors'] ) ) {
        $payload['errors'] = [];
    }

    if ( ! array_key_exists( 'data', $payload ) || ! is_array( $payload['data'] ) ) {
        $payload['data'] = is_array( $payload['data'] ?? null ) ? $payload['data'] : [];
    }

    if ( ! isset( $payload['meta'] ) || ! is_array( $payload['meta'] ) ) {
        $payload['meta'] = [];
    }
    $payload['meta']['request_id'] = $request_id;
    $payload['meta']['action']     = $ajax_action;
    $payload['meta']['endpoint']   = $endpoint;

    $controller = metis_ajax_registry()->get( $ajax_action );
    $module = metis_key_clean( (string) ( is_array( $controller ) ? ( $controller['module'] ?? '' ) : '' ) );
    if ( $module === '' ) {
        $module = (string) metis_security_infer_module_from_ajax_action( $ajax_action );
    }
    $message = substr( metis_text_clean( (string) ( $payload['message'] ?? '' ) ), 0, 255 );
    if ( $status_code >= 400 || empty( $payload['success'] ) ) {
        if ( function_exists( 'metis_audit_log_security' ) ) {
            metis_audit_log_security( 'ajax_action_failed', [
                'module'   => $module,
                'severity' => $status_code >= 500 ? 'error' : 'warning',
                'outcome'  => 'failed',
                'resource' => [
                    'type'  => 'ajax_action',
                    'id'    => $ajax_action,
                    'label' => (string) $status_code,
                ],
                'context'  => [
                    'route'         => $route_name,
                    'endpoint'      => $endpoint,
                    'endpoint_url'  => $endpoint_url,
                    'status_code'   => $status_code,
                    'error_message' => $message !== '' ? $message : 'Operation failed',
                    'request_id'    => $request_id,
                ],
            ] );
        }

        Metis_Logger::warn( 'AJAX action failed', [
            'route'        => $route_name,
            'endpoint'     => $endpoint,
            'endpoint_url' => $endpoint_url,
            'action'       => $ajax_action,
            'module'       => $module,
            'status'       => $status_code,
            'message'      => $message !== '' ? $message : 'Operation failed',
            'request_id'   => $request_id,
        ] );
    }

    Metis_Logger::info( 'Router dispatched ajax request', [
        'route'        => $route_name,
        'endpoint'     => $endpoint,
        'endpoint_url' => $endpoint_url,
        'action'       => $ajax_action,
        'status'       => $status_code,
    ] );

    return Metis_Http_Response::json(
        $payload,
        $status_code,
        [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'X-Metis-Request-Id' => $request_id ]
    );
}

function metis_router_handle_system_cron_request( Metis_Http_Request $request ): Metis_Http_Response {
    Metis_Logger::info( 'Router dispatched system cron request', [
        'route'      => 'system.cron',
        'request_id' => metis_router_request_id(),
    ] );

    return Metis_Cron_Manager::handle_request( $request );
}

metis_on('init', function () {
    metis_add_rewrite_tag('%metis_domain%', '([^&]+)');
    metis_add_rewrite_tag('%metis_view%',   '([^&]+)');
    metis_add_rewrite_tag('%metis_webhook_provider%', '([^&]+)');

    $slug = metis_portal_slug();
    $ajax = trim( metis_ajax_endpoint_path(), '/' );
    $webhooks = trim( metis_webhook_base_path(), '/' );
    $module_assets = trim( metis_module_asset_base_path(), '/' );

    if ( $slug !== '' ) {
        metis_add_rewrite_rule("^{$slug}/?$", "index.php?metis_domain=portal&metis_view=dashboard", 'top');
        metis_add_rewrite_rule("^{$slug}/([^/]+)/?$", "index.php?metis_domain=\$matches[1]&metis_view=dashboard", 'top');
        metis_add_rewrite_rule("^{$slug}/([^/]+)/([^/]+)/?$", "index.php?metis_domain=\$matches[1]&metis_view=\$matches[2]", 'top');
    }
    metis_add_rewrite_rule("^{$ajax}/?$", 'index.php?metis_api_ajax=1', 'top');
    metis_add_rewrite_rule("^{$webhooks}/([^/]+)/?$", "index.php?metis_webhook_provider=\$matches[1]", 'top');
    metis_add_rewrite_rule("^{$module_assets}/([^/]+)/(.+)?$", "index.php?metis_module_asset_module=\$matches[1]&metis_module_asset_path=\$matches[2]", 'top');
}, 1);

metis_add_filter('query_vars', function ($vars) {
    $vars[] = 'metis_domain';
    $vars[] = 'metis_view';
    $vars[] = 'metis_shell';
    $vars[] = 'metis_api_ajax';
    $vars[] = 'metis_webhook_provider';
    $vars[] = 'metis_module_asset_module';
    $vars[] = 'metis_module_asset_path';
    return $vars;
});

metis_on('metis_admin_init', function () {
    if (!metis_current_user_can('manage_options')) return;

    $key      = 'metis_last_route_signature';
    $current  = metis_portal_slug() . '|' . metis_webhook_base_path() . '|' . metis_ajax_endpoint_path();
    $previous = metis_get_option($key, '');

    if ($previous !== $current) {
        metis_flush_rewrite_rules(false);
        metis_update_option($key, $current, false);
        Metis_Logger::info( "Flushed rewrite rules (route signature changed {$previous} -> {$current})" );
    }
});

metis_on('template_redirect', function () {
    $request = metis_router_build_request();

    if ( function_exists( 'metis_contacts_carddav_is_request' ) && metis_contacts_carddav_is_request( $request ) ) {
        $response = metis_http_router()->dispatch( $request );
        metis_router_emit_response( $response );
        exit;
    }

    if ( Metis_Cron_Manager::matches_request( $request ) ) {
        $response = metis_http_router()->dispatch( $request );
        metis_router_emit_response( $response );
        exit;
    }

    if ( metis_get_query_var( 'metis_api_ajax' ) || metis_ajax_request_matches( $request ) ) {
        $response = metis_http_router()->dispatch( $request );
        metis_router_emit_response( $response );
        exit;
    }

    if ( metis_runtime_asset_match_request( $request ) ) {
        $response = metis_http_router()->dispatch( $request );
        metis_router_emit_response( $response );
        exit;
    }

    if ( metis_core_asset_match_request( $request ) ) {
        $response = metis_http_router()->dispatch( $request );
        metis_router_emit_response( $response );
        exit;
    }

    if ( metis_svg_icon_match_request( $request ) ) {
        $response = metis_http_router()->dispatch( $request );
        metis_router_emit_response( $response );
        exit;
    }

    if ( metis_get_query_var( 'metis_module_asset_module' ) || metis_module_asset_match_request( $request ) ) {
        if ( metis_get_query_var( 'metis_module_asset_module' ) ) {
            $request = $request
                ->with_attribute( 'module_asset_module', metis_key_clean( (string) metis_get_query_var( 'metis_module_asset_module' ) ) )
                ->with_attribute( 'module_asset_path', ltrim( (string) metis_get_query_var( 'metis_module_asset_path' ), '/' ) );
        }
        $response = metis_http_router()->dispatch( $request );
        metis_router_emit_response( $response );
        exit;
    }

    $webhook_provider = metis_key_clean( (string) metis_get_query_var( 'metis_webhook_provider' ) );
    if ( $webhook_provider !== '' || metis_is_webhook_request() ) {
        $response = metis_http_router()->dispatch(
            $request
                ->with_attribute( 'transport', 'webhook' )
                ->with_attribute( 'provider', $webhook_provider )
        );
        metis_router_emit_response( $response );
        exit;
    }

    if (!metis_is_portal_request()) {
        $site_path = (string) ( parse_url( metis_home_url( '/' ), PHP_URL_PATH ) ?? '/' );
        $site_path = '/' . trim( $site_path, '/' );
        if ( $site_path === '//' ) {
            $site_path = '/';
        }

        $request_path = '/' . ltrim( $request->path(), '/' );
        $is_metis_site_request = $site_path !== '/'
            && ( $request_path === $site_path || str_starts_with( $request_path, $site_path . '/' ) );

        if ( $is_metis_site_request ) {
            $response = metis_http_router()->dispatch( $request );
            metis_router_emit_response( $response );
            exit;
        }

        return;
    }

    $response = metis_http_router()->dispatch(
        $request->with_attribute( 'transport', 'portal' )
    );

    metis_router_emit_response( $response );
    exit;
}, 0);

metis_on( 'metis_admin_init', function () {
    if ( ! metis_runtime_doing_ajax() ) {
        return;
    }

    $action_source = metis_request_post()['action'] ?? metis_request_get()['action'] ?? '';
    $action = is_string( $action_source ) ? metis_key_clean( metis_runtime_unslash( $action_source ) ) : '';
    if ( $action === '' || ! str_starts_with( $action, 'metis_' ) ) {
        return;
    }

    metis_router_emit_response(
        Metis_Http_Response::json(
            [
                'success' => false,
                'data' => [
                    'message' => 'Direct legacy AJAX access is disabled. Use the centralized AJAX endpoint.',
                    'code' => 'ajax_endpoint_disabled',
                    'endpoint' => metis_ajax_endpoint_url(),
                ],
            ],
            410
        )
    );
    exit;
}, -1000 );
