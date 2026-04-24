<?php
declare(strict_types=1);

namespace Metis\Core;

use Metis\Core\Cache\CacheService;
use Metis\Core\Error\FailureIsolation;
use Metis\Core\Modules\ModuleValidator;

final class ModuleLoader {
    private const REQUIRED_MODULES = [ 'portal', 'people', 'profile', 'settings' ];
    private const COMPLIANCE_CACHE_TTL = 300;

    private array $modules = [];
    private array $boot_failures = [];
    private bool $booted = false;
    private string $base_path;
    private ?FailureIsolation $isolation = null;
    private ModuleValidator $validator;

    public function __construct( ?string $base_path = null, ?ModuleValidator $validator = null ) {
        $this->base_path = $base_path !== null
            ? metis_trailingslashit( $base_path )
            : ( \defined( 'METIS_PATH' ) ? (string) \METIS_PATH : dirname( __DIR__, 3 ) . '/' ) . 'modules/';
        $this->validator = $validator ?? new ModuleValidator();
    }

    public function register( string $slug, string $dir, array $config ): void {
        $slug = \metis_key_clean( $slug );
        if ( $slug === '' ) {
            throw new \RuntimeException( 'Module registration failed: invalid slug.' );
        }

        if ( is_dir( $dir ) && is_file( rtrim( $dir, '/\\' ) . '/module.json' ) ) {
            try {
                $config = $this->validator->validateModule( $dir, $config, $slug );
            } catch ( \Throwable $e ) {
                throw new \RuntimeException(
                    sprintf( 'Module registration failed for [%s]: %s', $slug, $e->getMessage() ),
                    0,
                    $e
                );
            }
        }

        $config['slug']   = $slug;
        $config['domain'] = \metis_key_clean( (string) ( $config['domain'] ?? $slug ) ) ?: $slug;
        $config['required'] = array_key_exists( 'required', $config )
            ? (bool) $config['required']
            : $this->isRequiredModule( $slug );

        $normalized_permissions = self::normalizeManifestPermissions( $slug, (array) ( $config['permissions'] ?? [] ) );
        $config['permissions']  = $normalized_permissions['roles'];
        if ( empty( $config['permission_definitions'] ) ) {
            $config['permission_definitions'] = $normalized_permissions['definitions'];
        }

        $this->modules[ $slug ] = [
            'slug'          => $slug,
            'dir'           => metis_trailingslashit( $dir ),
            'config'        => $config,
            'manifest_path' => (string) ( $config['_manifest_path'] ?? '' ),
        ];

        if ( function_exists( 'metis_quick_actions_service' ) ) {
            metis_quick_actions_service()->registerModuleActions( $slug, $config );
        }
    }

    public function all(): array {
        return $this->modules;
    }

    public function bootFailures(): array {
        return array_values( $this->boot_failures );
    }

    public function get( string $slug ): ?array {
        $slug = \metis_key_clean( $slug );
        return $this->modules[ $slug ] ?? null;
    }

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }

        $this->boot_failures = [];

        $base = $this->base_path;
        if ( ! is_dir( $base ) ) {
            $this->log( 'error', 'Modules folder missing', [ 'path' => $base ] );
            $this->booted = true;
            return;
        }

        $this->log( 'info', 'Booting Modules' );

        $pending = $this->discoverModules( $base );

        do {
            $progress = false;

            foreach ( $pending as $module_slug => $entry ) {
                $dir      = $entry['dir'];
                $manifest = $entry['manifest'];

                if ( array_key_exists( 'enabled', $manifest ) && empty( $manifest['enabled'] ) && ! $this->isRequiredModule( $module_slug ) ) {
                    $this->log( 'info', sprintf( 'Skipping disabled module: %s', $module_slug ) );
                    unset( $pending[ $module_slug ] );
                    continue;
                }

                if ( ! $this->dependencies_available( $manifest, false ) ) {
                    continue;
                }

                $slug = \metis_key_clean( (string) ( $manifest['slug'] ?? $module_slug ) );
                if ( $slug === '' ) {
                    $this->recordBootFailure( $module_slug, 'Module slug is invalid.', [
                        'path' => (string) ( $manifest['_manifest_path'] ?? $dir ),
                    ] );
                    unset( $pending[ $module_slug ] );
                    continue;
                }

                $module_class = $this->resolve_module_class( $manifest );
                if ( $module_class === null ) {
                    $this->recordBootFailure( $slug, 'Module class resolution failed.', [
                        'path' => (string) ( $manifest['_manifest_path'] ?? $dir ),
                    ] );
                    unset( $pending[ $module_slug ] );
                    continue;
                }

                $manifest['_module_class'] = $module_class;
                try {
                    $this->register( $slug, $dir, $manifest );

                    if ( function_exists( 'metis_security_register_module_policies' ) ) {
                        \metis_security_register_module_policies( $slug, $manifest );
                    }

                    $this->publish_event( 'module.registered', [
                        'module'   => $slug,
                        'dir'      => metis_trailingslashit( $dir ),
                        'manifest' => $manifest,
                    ] );

                    $this->autoload_manifest_files( $slug, $dir, (array) ( $manifest['services'] ?? [] ), 'service', (bool) ( $manifest['required'] ?? false ) );
                    $this->autoload_bootstrap( $slug, $dir, $manifest );
                    $this->register_manifest_listeners( $slug, $manifest, (bool) ( $manifest['required'] ?? false ) );
                    $this->publish_event( 'module.booted', [
                        'module'   => $slug,
                        'dir'      => metis_trailingslashit( $dir ),
                        'manifest' => $manifest,
                    ] );
                } catch ( \Throwable $e ) {
                    unset( $this->modules[ $slug ] );
                    $this->recordBootFailure( $slug, $e->getMessage(), [
                        'path' => (string) ( $manifest['_manifest_path'] ?? $dir ),
                    ] );
                    unset( $pending[ $module_slug ] );
                    continue;
                }

                $this->moduleLog( 'module_registered', $slug );
                unset( $pending[ $module_slug ] );
                $progress = true;
            }
        } while ( $progress && $pending !== [] );

        if ( $pending !== [] ) {
            foreach ( $pending as $module_slug => $entry ) {
                $manifest = (array) ( $entry['manifest'] ?? [] );
                $dependencies = (array) ( $manifest['dependencies'] ?? [] );
                $missing = array_values(
                    array_filter(
                        $dependencies,
                        fn ( mixed $dependency ): bool => (string) $dependency !== '' && ! isset( $this->modules[ (string) $dependency ] )
                    )
                );

                if ( $missing !== [] ) {
                    $this->recordBootFailure(
                        (string) $module_slug,
                        sprintf(
                            'Unresolved dependencies: %s',
                        implode( ', ', array_map( static fn ( mixed $value ): string => (string) $value, $missing ) )
                        ),
                        [ 'path' => (string) ( $manifest['_manifest_path'] ?? '' ) ]
                    );
                } else {
                    $this->recordBootFailure(
                        (string) $module_slug,
                        'Module did not become bootable due to unresolved compliance state.',
                        [ 'path' => (string) ( $manifest['_manifest_path'] ?? '' ) ]
                    );
                }
            }
        }

        $loaded = array_keys( $this->all() );
        $this->log( 'info', 'Modules booted: ' . ( empty( $loaded ) ? '(none)' : implode( ', ', $loaded ) ) );
        $this->moduleLog( 'boot_end' );
        $this->booted = true;
    }

    public function reload(): void {
        $this->modules = [];
        $this->boot_failures = [];
        $this->booted = false;
        CacheService::forget( $this->registryCacheKey() );
        CacheService::forget( $this->bootFailureCacheKey() );
        $this->boot();
    }

    public function complianceReport( bool $force_refresh = false ): array {
        $cache_key = 'modules.compliance.' . sha1( metis_trailingslashit( $this->base_path ) );
        if ( ! $force_refresh ) {
            $cached = CacheService::get( $cache_key );
            if ( is_array( $cached ) && isset( $cached['summary'], $cached['results'] ) ) {
                return $cached;
            }
        }

        $results = [];
        $failed = 0;
        $checked = 0;

        foreach ( glob( $this->base_path . '*', GLOB_ONLYDIR ) ?: [] as $dir ) {
            $checked++;
            $slug = \metis_key_clean( basename( $dir ) );
            if ( $slug === '' ) {
                $failed++;
                $results[] = [
                    'module' => basename( $dir ),
                    'status' => 'failed',
                    'reason' => 'Invalid module directory name.',
                    'path' => $dir,
                ];
                continue;
            }

            $manifest_path = $dir . '/module.json';
            if ( ! is_file( $manifest_path ) ) {
                $failed++;
                $results[] = [
                    'module' => $slug,
                    'status' => 'failed',
                    'reason' => 'Missing module manifest.',
                    'path' => $manifest_path,
                ];
                continue;
            }

            $manifest_raw = (string) @file_get_contents( $manifest_path );
            $manifest = json_decode( $manifest_raw, true );
            if ( ! is_array( $manifest ) ) {
                $failed++;
                $results[] = [
                    'module' => $slug,
                    'status' => 'failed',
                    'reason' => 'Invalid module manifest JSON.',
                    'path' => $manifest_path,
                ];
                continue;
            }

            try {
                $validated = $this->validator->validateModule( $dir, $manifest, $slug );
                if ( $this->resolve_module_class( $validated ) === null ) {
                    throw new \RuntimeException( 'Module class resolution failed.' );
                }
                $results[] = [
                    'module' => $slug,
                    'status' => 'ok',
                ];
            } catch ( \Throwable $e ) {
                $failed++;
                $results[] = [
                    'module' => $slug,
                    'status' => 'failed',
                    'reason' => $e->getMessage(),
                    'path' => $manifest_path,
                ];
            }
        }

        $report = [
            'summary' => [
                'checked' => $checked,
                'failed' => $failed,
                'passed' => max( 0, $checked - $failed ),
            ],
            'results' => $results,
        ];
        CacheService::set( $cache_key, $report, self::COMPLIANCE_CACHE_TTL );

        return $report;
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

        $relativeTemplate = ltrim( (string) $cfg['views'][ $view ], '/' );
        $moduleDir = metis_trailingslashit( $module['dir'] );
        $viewTemplate = $moduleDir . 'views/' . $relativeTemplate;
        $legacyTemplate = $moduleDir . 'templates/' . $relativeTemplate;

        $template = '';
        if ( file_exists( $viewTemplate ) ) {
            $template = $viewTemplate;
        } elseif ( file_exists( $legacyTemplate ) ) {
            $template = $legacyTemplate;
        } else {
            return [ 'error' => "Missing template file: {$viewTemplate}" ];
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
            $this->log( 'warn', sprintf( 'Missing manifest for module: %s', $fallback_slug ) );
            return null;
        }

        $raw      = file_get_contents( $manifest_path );
        $manifest = json_decode( is_string( $raw ) ? $raw : '', true );
        if ( ! is_array( $manifest ) ) {
            $this->log( 'error', 'Invalid module manifest JSON', [
                'module' => $fallback_slug,
                'path'   => $manifest_path,
            ] );
            return null;
        }

        $manifest['slug']         = \metis_key_clean( (string) ( $manifest['slug'] ?? $fallback_slug ) );
        $manifest['name']         = trim( (string) ( $manifest['name'] ?? $manifest['label'] ?? ucfirst( str_replace( '_', ' ', $fallback_slug ) ) ) );
        $manifest['version']      = trim( (string) ( $manifest['version'] ?? '0.0.0' ) );
        $manifest['description']  = trim( (string) ( $manifest['description'] ?? '' ) );
        $manifest['domain']       = \metis_key_clean( (string) ( $manifest['domain'] ?? $manifest['slug'] ) ) ?: $manifest['slug'];
        $manifest['enabled']      = array_key_exists( 'enabled', $manifest ) ? (bool) $manifest['enabled'] : true;
        $manifest['required']     = array_key_exists( 'required', $manifest ) ? (bool) $manifest['required'] : $this->isRequiredModule( $manifest['slug'] );
        $manifest['dependencies'] = array_values(
            array_filter(
                array_map(
                    static fn ( mixed $dependency ): string => \metis_key_clean( (string) $dependency ),
                    (array) ( $manifest['dependencies'] ?? [] )
                )
            )
        );
        $normalized_permissions             = self::normalizeManifestPermissions( $manifest['slug'], (array) ( $manifest['permissions'] ?? [] ) );
        $manifest['permissions']            = $normalized_permissions['roles'];
        $manifest['permission_definitions'] = $normalized_permissions['definitions'];
        $manifest['category']               = self::normalizeManifestCategory( $manifest['category'] ?? null );
        $manifest['default_parent']         = self::normalizeManifestDefaultParent( $manifest['default_parent'] ?? null );
        $manifest['order']                  = (int) ( $manifest['order'] ?? $manifest['menu_order'] ?? 50 );
        $manifest['navigation']             = self::normalizeManifestNavigation( (array) ( $manifest['navigation'] ?? [] ) );
        $manifest['quick_actions']          = self::normalizeManifestQuickActions( (array) ( $manifest['quick_actions'] ?? [] ) );
        $manifest['_manifest_path']         = $manifest_path;
        $manifest['_manifest_mtime']        = (int) ( @filemtime( $manifest_path ) ?: 0 );

        if ( ! $this->valid_manifest_metadata( $manifest ) ) {
            return null;
        }

        if ( $this->has_dependency_conflicts( $manifest ) ) {
            return null;
        }

        return $manifest;
    }

    private function discoverModules( string $base ): array {
        $cached = CacheService::get( $this->registryCacheKey() );
        if ( is_array( $cached ) && $cached !== [] ) {
            $pending = [];
            $cached_manifest_max_mtime = 0;
            foreach ( $cached as $module_slug => $entry ) {
                if ( ! is_array( $entry ) ) {
                    $pending = [];
                    break;
                }

                $dir      = (string) ( $entry['dir'] ?? '' );
                $manifest = is_array( $entry['manifest'] ?? null ) ? $entry['manifest'] : null;
                if ( $dir === '' || ! is_dir( $dir ) || ! is_array( $manifest ) ) {
                    $pending = [];
                    break;
                }

                $manifest_path = (string) ( $manifest['_manifest_path'] ?? '' );
                $manifest_mtime = (int) ( $manifest['_manifest_mtime'] ?? 0 );
                if (
                    $manifest_path === ''
                    || ! is_file( $manifest_path )
                    || $manifest_mtime !== (int) ( @filemtime( $manifest_path ) ?: 0 )
                ) {
                    $pending = [];
                    break;
                }

                if ( $manifest_mtime > $cached_manifest_max_mtime ) {
                    $cached_manifest_max_mtime = $manifest_mtime;
                }

                $this->moduleLog( 'module', (string) $module_slug );
                $pending[ (string) $module_slug ] = [
                    'dir' => $dir,
                    'manifest' => $manifest,
                ];
            }

            // A previously invalid module may become valid after a manifest/template fix.
            // If its manifest changed more recently than the current cache snapshot, rebuild.
            if ( $pending !== [] ) {
                foreach ( glob( $base . '*', GLOB_ONLYDIR ) as $dir ) {
                    $module_slug = basename( $dir );
                    if ( isset( $pending[ $module_slug ] ) ) {
                        continue;
                    }

                    $manifest_path = $dir . '/module.json';
                    if ( ! is_file( $manifest_path ) ) {
                        continue;
                    }

                    $manifest_mtime = (int) ( @filemtime( $manifest_path ) ?: 0 );
                    if ( $manifest_mtime > $cached_manifest_max_mtime ) {
                        $pending = [];
                        break;
                    }
                }
            }

            if ( $pending !== [] ) {
                $cached_failures = CacheService::get( $this->bootFailureCacheKey() );
                if ( is_array( $cached_failures ) ) {
                    $this->boot_failures = $cached_failures;
                }
                return $pending;
            }
        }

        $pending = [];
        foreach ( glob( $base . '*', GLOB_ONLYDIR ) as $dir ) {
            $module_slug = basename( $dir );
            $this->moduleLog( 'module', $module_slug );

            $manifest = $this->load_manifest( $dir, $module_slug );
            if ( $manifest === null ) {
                $this->recordBootFailure(
                    $module_slug,
                    'Missing or invalid module manifest.',
                    [ 'path' => $dir . '/module.json' ]
                );
                continue;
            }

            try {
                $manifest = $this->validator->validateModule( $dir, $manifest, $module_slug );
            } catch ( \Throwable $e ) {
                $this->recordBootFailure(
                    $module_slug,
                    $e->getMessage(),
                    [ 'path' => (string) ( $manifest['_manifest_path'] ?? $dir . '/module.json' ) ]
                );
                continue;
            }

            $pending[ $module_slug ] = [
                'dir' => $dir,
                'manifest' => $manifest,
            ];
        }

        CacheService::set( $this->registryCacheKey(), $pending, 3600 );
        CacheService::set( $this->bootFailureCacheKey(), $this->boot_failures, 3600 );

        return $pending;
    }

    public function registryCacheKey(): string {
        return self::registryCacheKeyForBasePath( $this->base_path );
    }

    public static function registryCacheKeyForBasePath( string $base_path ): string {
        return 'modules.registry.' . sha1( metis_trailingslashit( $base_path ) );
    }

    private function bootFailureCacheKey(): string {
        return 'modules.boot_failures.' . sha1( metis_trailingslashit( $this->base_path ) );
    }

    private function isRequiredModule( string $slug ): bool {
        return in_array( \metis_key_clean( $slug ), self::REQUIRED_MODULES, true );
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
        $module_slug = \metis_key_clean( $module_slug );
        $definitions = [];
        $roles       = [];

        foreach ( $permissions as $default_action => $entry ) {
            $default_action = \metis_key_clean( (string) $default_action );
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
        $action = \metis_key_clean( (string) ( $entry['action'] ?? $default_action ) );
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
            $role = \metis_key_clean( (string) $role );
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

    private static function normalizeManifestCategory( mixed $category ): string {
        if ( class_exists( '\Metis\Core\Services\NavigationService' ) ) {
            return \Metis\Core\Services\NavigationService::normalizeCategory( $category );
        }

        $category = \metis_key_clean( (string) $category );
        return in_array( $category, [ 'core', 'communications', 'website', 'administration', 'other' ], true )
            ? $category
            : 'other';
    }

    private static function normalizeManifestDefaultParent( mixed $defaultParent ): ?string {
        if ( $defaultParent === null ) {
            return null;
        }

        $parent = \metis_key_clean( (string) $defaultParent );
        if ( $parent === '' || $parent === 'null' ) {
            return null;
        }

        return in_array( $parent, [ 'communications', 'website', 'administration' ], true )
            ? $parent
            : null;
    }

    private static function normalizeManifestNavigation( array $navigation ): array {
        $label = trim( (string) ( $navigation['label'] ?? '' ) );

        return [
            'enabled' => array_key_exists( 'enabled', $navigation ) ? (bool) $navigation['enabled'] : true,
            'label' => $label,
            'visible' => array_key_exists( 'visible', $navigation ) ? (bool) $navigation['visible'] : true,
        ];
    }

    private static function normalizeManifestQuickActions( array $quickActions ): array {
        $normalized = [];

        foreach ( $quickActions as $quickAction ) {
            if ( ! is_array( $quickAction ) ) {
                continue;
            }

            $key = \metis_key_clean( (string) ( $quickAction['key'] ?? '' ) );
            $label = trim( (string) ( $quickAction['label'] ?? '' ) );
            if ( $key === '' || $label === '' ) {
                continue;
            }

            $type = \metis_key_clean( (string) ( $quickAction['type'] ?? 'route' ) );
            if ( ! in_array( $type, [ 'modal', 'route' ], true ) ) {
                $type = 'route';
            }

            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'icon' => (string) ( $quickAction['icon'] ?? '' ),
                'type' => $type,
                'route' => trim( (string) ( $quickAction['route'] ?? '' ) ),
                'permission' => trim( (string) ( $quickAction['permission'] ?? '' ) ),
                'group' => \metis_key_clean( (string) ( $quickAction['group'] ?? 'other' ) ),
                'handler' => trim( (string) ( $quickAction['handler'] ?? '' ) ),
                'submit_action' => \metis_key_clean( (string) ( $quickAction['submit_action'] ?? '' ) ),
                'submit_label' => trim( (string) ( $quickAction['submit_label'] ?? '' ) ),
            ];
        }

        return $normalized;
    }

    private function discover_manifest_path( string $dir, string $fallback_slug ): ?string {
        $candidate = $dir . '/module.json';

        if ( is_file( $candidate ) ) {
            return $candidate;
        }

        return null;
    }

    private function dependencies_available( array $manifest, bool $log_errors = true ): bool {
        $missing = [];
        foreach ( (array) ( $manifest['dependencies'] ?? [] ) as $dependency ) {
            if ( $dependency !== '' && ! isset( $this->modules[ $dependency ] ) ) {
                $missing[] = $dependency;
            }
        }

        if ( $missing === [] ) {
            return true;
        }

        if ( $log_errors ) {
            $this->log( 'warn', 'Skipping module with unresolved dependencies', [
                'module'  => (string) ( $manifest['slug'] ?? '' ),
                'missing' => $missing,
            ] );
        }

        return false;
    }

    private function valid_manifest_metadata( array $manifest ): bool {
        foreach ( [ 'name', 'version', 'description' ] as $field ) {
            if ( trim( (string) ( $manifest[ $field ] ?? '' ) ) === '' ) {
                $this->log( 'error', 'Module manifest is missing required metadata', [
                    'module' => (string) ( $manifest['slug'] ?? '' ),
                    'field' => $field,
                    'path' => (string) ( $manifest['_manifest_path'] ?? '' ),
                ] );

                return false;
            }
        }

        if ( ! isset( $manifest['permissions'] ) || ! is_array( $manifest['permissions'] ) ) {
            $this->log( 'error', 'Module manifest has invalid permissions metadata', [
                'module' => (string) ( $manifest['slug'] ?? '' ),
                'path' => (string) ( $manifest['_manifest_path'] ?? '' ),
            ] );

            return false;
        }

        return true;
    }

    private function has_dependency_conflicts( array $manifest ): bool {
        $slug         = (string) ( $manifest['slug'] ?? '' );
        $dependencies = (array) ( $manifest['dependencies'] ?? [] );

        if ( in_array( $slug, $dependencies, true ) ) {
            $this->log( 'error', 'Module cannot depend on itself', [
                'module' => $slug,
                'path' => (string) ( $manifest['_manifest_path'] ?? '' ),
            ] );

            return true;
        }

        if ( count( $dependencies ) !== count( array_unique( $dependencies ) ) ) {
            $this->log( 'error', 'Module manifest contains duplicate dependencies', [
                'module' => $slug,
                'dependencies' => $dependencies,
                'path' => (string) ( $manifest['_manifest_path'] ?? '' ),
            ] );

            return true;
        }

        return false;
    }

    private function resolve_module_class( array $manifest ): ?string {
        $candidates = [];
        $explicit   = trim( (string) ( $manifest['class'] ?? '' ) );

        if ( $explicit !== '' ) {
            $candidates[] = $explicit;
        }

        $studly = $this->studly_module_name(
            (string) ( $manifest['name'] ?? $manifest['label'] ?? $manifest['slug'] ?? '' )
        );

        if ( $studly !== '' ) {
            $candidates[] = 'Metis\\Modules\\' . $studly . '\\' . $studly . 'Module';
        }

        $slug_studly = $this->studly_module_name( (string) ( $manifest['slug'] ?? '' ) );
        if ( $slug_studly !== '' ) {
            $candidates[] = 'Metis\\Modules\\' . $slug_studly . '\\' . $slug_studly . 'Module';
        }

        foreach ( array_values( array_unique( $candidates ) ) as $candidate ) {
            if ( ! str_starts_with( $candidate, 'Metis\\Modules\\' ) ) {
                $this->log( 'error', 'Module class namespace is invalid', [
                    'module' => (string) ( $manifest['slug'] ?? '' ),
                    'class' => $candidate,
                ] );
                continue;
            }

            if ( class_exists( $candidate ) ) {
                return $candidate;
            }
        }

        $this->log( 'error', 'Module class could not be resolved', [
            'module' => (string) ( $manifest['slug'] ?? '' ),
            'path' => (string) ( $manifest['_manifest_path'] ?? '' ),
            'candidates' => array_values( array_unique( $candidates ) ),
        ] );

        return null;
    }

    private function studly_module_name( string $value ): string {
        $parts = preg_split( '/[^a-z0-9]+/i', strtolower( $value ) ) ?: [];

        return implode(
            '',
            array_map(
                static fn ( string $part ): string => ucfirst( $part ),
                array_values(
                    array_filter(
                        $parts,
                        static fn ( string $part ): bool => $part !== ''
                    )
                )
            )
        );
    }

    private function autoload_manifest_files( string $slug, string $dir, array $files, string $type, bool $required = false ): void {
        foreach ( $files as $relative_path ) {
            $relative_path = ltrim( (string) $relative_path, '/' );
            if ( $relative_path === '' ) {
                continue;
            }

            $file = $dir . '/' . $relative_path;
            if ( ! is_file( $file ) ) {
                $this->log( 'warn', sprintf( 'Missing module %s file', $type ), [
                    'module' => $slug,
                    'path'   => $file,
                ] );
                continue;
            }

            $this->runModuleOperation(
                function () use ( $file ): void {
                    $this->includeModuleFile( $file );
                },
                [
                    'optional' => ! $required,
                    'module' => $slug,
                    'service' => $type,
                    'fallback' => null,
                ]
            );
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
            $this->log( 'warn', sprintf( 'Missing bootstrap.php for module: %s', $slug ) );
            return;
        }

        $this->runModuleOperation(
            function () use ( $bootstrap_file ): void {
                $this->includeModuleFile( $bootstrap_file );
            },
            [
                'optional' => ! (bool) ( $manifest['required'] ?? false ),
                'module' => $slug,
                'service' => 'bootstrap',
                'fallback' => null,
            ]
        );
    }

    private function register_manifest_listeners( string $slug, array $manifest, bool $required = false ): void {
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
                $this->log( 'warn', 'Skipping invalid manifest event listener', [
                    'module'  => $slug,
                    'event'   => $event,
                    'handler' => is_string( $handler ) ? $handler : gettype( $handler ),
                ] );
                continue;
            }

            $this->runModuleOperation(
                static function () use ( $event, $handler, $priority ): void {
                    Application::service( 'events' )->subscribe( $event, $handler, $priority );
                },
                [
                    'optional' => ! $required,
                    'module' => $slug,
                    'service' => 'listener',
                    'fallback' => null,
                ]
            );
        }
    }

    private function isolation(): FailureIsolation {
        if ( $this->isolation instanceof FailureIsolation ) {
            return $this->isolation;
        }

        /** @var FailureIsolation $service */
        $service = Application::service( 'failure_isolation' );
        $this->isolation = $service;
        return $this->isolation;
    }

    private function runModuleOperation( callable $operation, array $context ): void {
        if ( Application::has_service( 'failure_isolation' ) ) {
            $this->isolation()->isolate( 'module', $operation, $context );
            return;
        }

        $operation();
    }

    private function includeModuleFile( string $file ): void {
        $real = realpath( $file );
        $root = defined( 'METIS_PATH' ) ? realpath( (string) METIS_PATH ) : false;

        if (
            function_exists( 'metis_security_trusted_include' )
            && is_string( $real )
            && is_string( $root )
            && str_starts_with( $real, $root )
        ) {
            \metis_security_trusted_include( $file );
            return;
        }

        require_once $file;
    }

    private function publish_event( string $event, array $payload ): void {
        if ( ! Application::has_service( 'events' ) ) {
            return;
        }

        Application::service( 'events' )->publish( $event, $payload );
    }

    private function log( string $level, string $message, array $context = [] ): void {
        $logger = null;

        if ( Application::has_service( 'logger' ) ) {
            $logger = Application::service( 'logger' );
        } elseif ( Application::has_service( 'logger_core' ) ) {
            $logger = Application::service( 'logger_core' );
        }

        if ( ! is_object( $logger ) || ! method_exists( $logger, $level ) ) {
            return;
        }

        $logger->{$level}( $message, $context );
    }

    private function moduleLog( string $method, mixed ...$arguments ): void {
        if ( ! class_exists( 'Metis_Logger' ) || ! method_exists( 'Metis_Logger', $method ) ) {
            return;
        }

        \Metis_Logger::{$method}( ...$arguments );
    }

    private function recordBootFailure( string $module, string $reason, array $context = [] ): void {
        $module = \metis_key_clean( $module );
        if ( $module === '' ) {
            $module = 'unknown';
        }

        $reason = $this->humanizeFailureReason( $module, $reason );

        $entry = [
            'module' => $module,
            'reason' => trim( $reason ),
            'path' => (string) ( $context['path'] ?? '' ),
        ];

        $this->boot_failures[ $module ] = $entry;
        $this->log( 'error', 'Module compliance failure', $entry );

        if ( function_exists( 'metis_audit_log_security' ) ) {
            metis_audit_log_security( 'module_compliance_failed', [
                'module' => 'core',
                'severity' => 'warning',
                'outcome' => 'blocked',
                'resource' => [
                    'type' => 'module',
                    'id' => $module,
                ],
                'context' => $entry,
            ] );
        }
    }

    private function humanizeFailureReason( string $module, string $reason ): string {
        $message = trim( $reason );
        if ( $message === '' ) {
            return 'This module did not pass compliance checks.';
        }

        $message = preg_replace( '/^Module registration failed for \[[^\]]+\]:\s*/i', '', $message ) ?? $message;
        $message = preg_replace( '/^Module validation failed for \[[^\]]+\]:\s*/i', '', $message ) ?? $message;
        $message = preg_replace( '/^Module validation failed:\s*/i', '', $message ) ?? $message;

        if ( stripos( $message, 'cannot register /api routes from bootstrap' ) !== false ) {
            return 'Uses custom /api routes in bootstrap.php. Move routes into module.json -> routes and routes/*.php handlers.';
        }

        if ( stripos( $message, 'manifest permissions must be an array' ) !== false ) {
            return 'module.json has an invalid permissions section. Set "permissions" to an object/array of permission definitions.';
        }

        if ( stripos( $message, 'module class resolution failed' ) !== false ) {
            return 'Module class could not be resolved. Check module.json class/entry and Module.php namespace.';
        }

        if ( stripos( $message, 'missing or invalid module manifest' ) !== false ) {
            return 'module.json is missing or invalid JSON.';
        }

        if ( stripos( $message, 'unresolved dependencies:' ) === 0 ) {
            return 'Required dependency modules are missing: ' . trim( substr( $message, strlen( 'unresolved dependencies:' ) ) );
        }

        if ( stripos( $message, 'module slug is invalid' ) !== false ) {
            return 'Module slug is invalid. Ensure folder name and module.json slug use lowercase snake_case.';
        }

        $message = str_ireplace(
            sprintf( 'Module [%s] ', $module ),
            '',
            $message
        );

        return rtrim( $message, '.' ) . '.';
    }
}
