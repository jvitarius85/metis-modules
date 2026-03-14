<?php
declare(strict_types=1);

namespace Metis\Core;

final class ModuleLoader {
    private array $modules = [];
    private bool $booted = false;
    private string $base_path;

    public function __construct( ?string $base_path = null ) {
        $this->base_path = $base_path !== null
            ? \trailingslashit( $base_path )
            : METIS_PATH . 'includes/modules/';
    }

    public function register( string $slug, string $dir, array $config ): void {
        $slug = \sanitize_key( $slug );
        if ( $slug === '' ) {
            return;
        }

        $config['slug']   = $slug;
        $config['domain'] = \sanitize_key( (string) ( $config['domain'] ?? $slug ) ) ?: $slug;

        $normalized_permissions = self::normalizeManifestPermissions( $slug, (array) ( $config['permissions'] ?? [] ) );
        $config['permissions']  = $normalized_permissions['roles'];
        if ( empty( $config['permission_definitions'] ) ) {
            $config['permission_definitions'] = $normalized_permissions['definitions'];
        }

        $this->modules[ $slug ] = [
            'slug'          => $slug,
            'dir'           => \trailingslashit( $dir ),
            'config'        => $config,
            'manifest_path' => (string) ( $config['_manifest_path'] ?? '' ),
        ];
    }

    public function all(): array {
        return $this->modules;
    }

    public function get( string $slug ): ?array {
        $slug = \sanitize_key( $slug );
        return $this->modules[ $slug ] ?? null;
    }

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }

        $base = $this->base_path;
        if ( ! is_dir( $base ) ) {
            Application::service( 'logger' )->error( 'Modules folder missing', [ 'path' => $base ] );
            $this->booted = true;
            return;
        }

        Application::service( 'logger' )->info( 'Booting Modules' );

        foreach ( glob( $base . '*', GLOB_ONLYDIR ) as $dir ) {
            $module_slug = basename( $dir );
            \Metis_Logger::module( $module_slug );

            $manifest = $this->load_manifest( $dir, $module_slug );
            if ( $manifest === null ) {
                continue;
            }

            if ( array_key_exists( 'enabled', $manifest ) && empty( $manifest['enabled'] ) ) {
                Application::service( 'logger' )->info( sprintf( 'Skipping disabled module: %s', $module_slug ) );
                continue;
            }

            if ( ! $this->dependencies_available( $manifest ) ) {
                continue;
            }

            $slug = \sanitize_key( (string) ( $manifest['slug'] ?? $module_slug ) );
            if ( $slug === '' ) {
                Application::service( 'logger' )->warn( sprintf( 'Skipping module with invalid slug: %s', $module_slug ) );
                continue;
            }

            $this->register( $slug, $dir, $manifest );

            if ( function_exists( 'metis_security_register_module_policies' ) ) {
                \metis_security_register_module_policies( $slug, $manifest );
            }

            $this->publish_event( 'module.registered', [
                'module'   => $slug,
                'dir'      => \trailingslashit( $dir ),
                'manifest' => $manifest,
            ] );

            $this->autoload_manifest_files( $slug, $dir, (array) ( $manifest['services'] ?? [] ), 'service' );
            $this->autoload_bootstrap( $slug, $dir, $manifest );
            $this->register_manifest_listeners( $slug, $manifest );
            $this->publish_event( 'module.booted', [
                'module'   => $slug,
                'dir'      => \trailingslashit( $dir ),
                'manifest' => $manifest,
            ] );

            \Metis_Logger::module_registered( $slug );
        }

        $loaded = array_keys( $this->all() );
        Application::service( 'logger' )->info( 'Modules booted: ' . ( empty( $loaded ) ? '(none)' : implode( ', ', $loaded ) ) );
        \Metis_Logger::boot_end();
        $this->booted = true;
    }

    public function routes(): array {
        $routes = [];

        foreach ( $this->all() as $module ) {
            foreach ( (array) ( $module['config']['routes'] ?? [] ) as $route ) {
                if ( is_array( $route ) ) {
                    $routes[] = array_merge( [ 'module' => $module['slug'] ], $route );
                }
            }
        }

        return $routes;
    }

    public function declaredPermissions(): array {
        $declared = [];

        foreach ( $this->all() as $module ) {
            foreach ( (array) ( $module['config']['permission_definitions'] ?? [] ) as $definition ) {
                if ( ! is_array( $definition ) ) {
                    continue;
                }

                $key = self::sanitizePermissionKey( (string) ( $definition['key'] ?? '' ) );
                if ( $key === '' ) {
                    continue;
                }

                $definition['key'] = $key;
                $declared[ $key ]  = $definition;
            }
        }

        return array_values( $declared );
    }

    public function resolve_view( string $domain, string $view ): array {
        $modules = $this->all();

        if ( empty( $modules[ $domain ] ) ) {
            return [ 'error' => "Unknown module: {$domain}" ];
        }

        $module = $modules[ $domain ];
        $cfg    = $module['config'];

        if ( $view === 'dashboard' && ! empty( $cfg['default_view'] ) ) {
            $view = $cfg['default_view'];
        }

        if ( empty( $cfg['views'][ $view ] ) ) {
            return [ 'error' => "Unknown view '{$view}' for module '{$domain}'" ];
        }

        $template = \trailingslashit( $module['dir'] ) . 'templates/' . $cfg['views'][ $view ];
        if ( ! file_exists( $template ) ) {
            return [ 'error' => "Missing template file: {$template}" ];
        }

        return [
            'template' => $template,
            'module'   => $module['slug'],
            'domain'   => $domain,
            'view'     => $view,
        ];
    }

    private function load_manifest( string $dir, string $fallback_slug ): ?array {
        $manifest_path = $this->discover_manifest_path( $dir, $fallback_slug );
        if ( $manifest_path === null ) {
            Application::service( 'logger' )->warn( sprintf( 'Missing manifest for module: %s', $fallback_slug ) );
            return null;
        }

        $raw      = file_get_contents( $manifest_path );
        $manifest = json_decode( is_string( $raw ) ? $raw : '', true );
        if ( ! is_array( $manifest ) ) {
            Application::service( 'logger' )->error( 'Invalid module manifest JSON', [
                'module' => $fallback_slug,
                'path'   => $manifest_path,
            ] );
            return null;
        }

        $manifest['slug']         = \sanitize_key( (string) ( $manifest['slug'] ?? $fallback_slug ) );
        $manifest['domain']       = \sanitize_key( (string) ( $manifest['domain'] ?? $manifest['slug'] ) ) ?: $manifest['slug'];
        $manifest['enabled']      = array_key_exists( 'enabled', $manifest ) ? (bool) $manifest['enabled'] : true;
        $manifest['dependencies'] = array_values(
            array_filter(
                array_map(
                    static fn ( mixed $dependency ): string => \sanitize_key( (string) $dependency ),
                    (array) ( $manifest['dependencies'] ?? [] )
                )
            )
        );
        $normalized_permissions            = self::normalizeManifestPermissions( $manifest['slug'], (array) ( $manifest['permissions'] ?? [] ) );
        $manifest['permissions']           = $normalized_permissions['roles'];
        $manifest['permission_definitions'] = $normalized_permissions['definitions'];
        $manifest['_manifest_path'] = $manifest_path;

        return $manifest;
    }

    public static function rolesForPermission( array $module_config, string $action ): array {
        $entry = $module_config['permissions'][ $action ] ?? [];

        if ( is_array( $entry ) && array_is_list( $entry ) ) {
            return array_values(
                array_filter(
                    array_map(
                        static fn ( mixed $role ): string => (string) $role,
                        $entry
                    ),
                    static fn ( string $role ): bool => $role !== ''
                )
            );
        }

        if ( is_array( $entry ) ) {
            return array_values(
                array_filter(
                    array_map(
                        static fn ( mixed $role ): string => (string) $role,
                        (array) ( $entry['roles'] ?? [] )
                    ),
                    static fn ( string $role ): bool => $role !== ''
                )
            );
        }

        return [];
    }

    public static function normalizeManifestPermissions( string $module_slug, array $permissions ): array {
        $module_slug = \sanitize_key( $module_slug );
        $definitions = [];
        $roles       = [];

        foreach ( $permissions as $default_action => $entry ) {
            $default_action = \sanitize_key( (string) $default_action );
            if ( $default_action === '' || ! is_array( $entry ) ) {
                continue;
            }

            if ( array_is_list( $entry ) ) {
                $definition = self::buildPermissionDefinition( $module_slug, $default_action, [
                    'roles' => $entry,
                ] );
                if ( $definition === null ) {
                    continue;
                }

                $definitions[]                 = $definition;
                $roles[ $definition['action'] ] = $definition['roles'];
                continue;
            }

            $definition = self::buildPermissionDefinition( $module_slug, $default_action, $entry );
            if ( $definition === null ) {
                continue;
            }

            $definitions[]                 = $definition;
            $roles[ $definition['action'] ] = $definition['roles'];
        }

        return [
            'definitions' => $definitions,
            'roles'       => $roles,
        ];
    }

    private static function buildPermissionDefinition( string $module_slug, string $default_action, array $entry ): ?array {
        $action = \sanitize_key( (string) ( $entry['action'] ?? $default_action ) );
        if ( $module_slug === '' || $action === '' ) {
            return null;
        }

        $key = self::sanitizePermissionKey( (string) ( $entry['key'] ?? ( $module_slug . '.' . $action ) ) );
        if ( $key === '' ) {
            return null;
        }

        $name = trim( (string) ( $entry['name'] ?? ( ucfirst( $module_slug ) . ' ' . ucwords( str_replace( '_', ' ', $action ) ) ) ) );
        if ( $name === '' ) {
            $name = $key;
        }

        $declared_roles = (array) ( $entry['roles'] ?? [] );
        $roles          = [];

        foreach ( $declared_roles as $role ) {
            $role = \sanitize_key( (string) $role );
            if ( $role !== '' ) {
                $roles[] = $role;
            }
        }

        return [
            'key'    => $key,
            'module' => $module_slug,
            'action' => $action,
            'name'   => $name,
            'roles'  => array_values( array_unique( $roles ) ),
        ];
    }

    private static function sanitizePermissionKey( string $key ): string {
        $key = strtolower( trim( $key ) );
        return preg_replace( '/[^a-z0-9._-]/', '', $key ) ?? '';
    }

    private function discover_manifest_path( string $dir, string $fallback_slug ): ?string {
        $candidates = [
            $dir . '/' . $fallback_slug . '.json',
            $dir . '/module.json',
        ];

        foreach ( $candidates as $candidate ) {
            if ( is_file( $candidate ) ) {
                return $candidate;
            }
        }

        $json_files = glob( $dir . '/*.json' ) ?: [];
        foreach ( $json_files as $candidate ) {
            if ( is_file( $candidate ) ) {
                return $candidate;
            }
        }

        return null;
    }

    private function dependencies_available( array $manifest ): bool {
        $missing = [];
        foreach ( (array) ( $manifest['dependencies'] ?? [] ) as $dependency ) {
            if ( $dependency !== '' && ! isset( $this->modules[ $dependency ] ) ) {
                $missing[] = $dependency;
            }
        }

        if ( $missing === [] ) {
            return true;
        }

        Application::service( 'logger' )->warn( 'Skipping module with unresolved dependencies', [
            'module'  => (string) ( $manifest['slug'] ?? '' ),
            'missing' => $missing,
        ] );

        return false;
    }

    private function autoload_manifest_files( string $slug, string $dir, array $files, string $type ): void {
        foreach ( $files as $relative_path ) {
            $relative_path = ltrim( (string) $relative_path, '/' );
            if ( $relative_path === '' ) {
                continue;
            }

            $file = $dir . '/' . $relative_path;
            if ( ! is_file( $file ) ) {
                Application::service( 'logger' )->warn( sprintf( 'Missing module %s file', $type ), [
                    'module' => $slug,
                    'path'   => $file,
                ] );
                continue;
            }

            if ( function_exists( 'metis_security_trusted_include' ) ) {
                \metis_security_trusted_include( $file );
            } else {
                require_once $file;
            }
        }
    }

    private function autoload_bootstrap( string $slug, string $dir, array $manifest ): void {
        $bootstrap = array_key_exists( 'bootstrap', $manifest )
            ? ltrim( (string) $manifest['bootstrap'], '/' )
            : 'bootstrap.php';

        if ( $bootstrap === '' ) {
            return;
        }

        $bootstrap_file = $dir . '/' . $bootstrap;
        if ( ! is_file( $bootstrap_file ) ) {
            Application::service( 'logger' )->warn( sprintf( 'Missing bootstrap.php for module: %s', $slug ) );
            return;
        }

        if ( function_exists( 'metis_security_trusted_include' ) ) {
            \metis_security_trusted_include( $bootstrap_file );
        } else {
            require_once $bootstrap_file;
        }
    }

    private function register_manifest_listeners( string $slug, array $manifest ): void {
        if ( ! Application::has_service( 'events' ) ) {
            return;
        }

        foreach ( (array) ( $manifest['listeners'] ?? [] ) as $listener ) {
            if ( ! is_array( $listener ) ) {
                continue;
            }

            $event   = trim( strtolower( (string) ( $listener['event'] ?? '' ) ) );
            $handler = $listener['handler'] ?? null;
            $priority = (int) ( $listener['priority'] ?? 10 );

            if ( $event === '' || ! is_callable( $handler ) ) {
                Application::service( 'logger' )->warn( 'Skipping invalid manifest event listener', [
                    'module'  => $slug,
                    'event'   => $event,
                    'handler' => is_string( $handler ) ? $handler : gettype( $handler ),
                ] );
                continue;
            }

            Application::service( 'events' )->subscribe( $event, $handler, $priority );
        }
    }

    private function publish_event( string $event, array $payload ): void {
        if ( ! Application::has_service( 'events' ) ) {
            return;
        }

        Application::service( 'events' )->publish( $event, $payload );
    }
}
