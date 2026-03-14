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

            $this->autoload_manifest_files( $slug, $dir, (array) ( $manifest['services'] ?? [] ), 'service' );
            $this->autoload_bootstrap( $slug, $dir, $manifest );

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
        $manifest['_manifest_path'] = $manifest_path;

        return $manifest;
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
}
