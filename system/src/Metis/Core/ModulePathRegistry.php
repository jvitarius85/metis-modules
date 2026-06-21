<?php
declare(strict_types=1);

namespace Metis\Core;

final class ModulePathRegistry {
    private const CORE_SERVICE_SLUGS = [ 'help', 'people', 'portal', 'profile', 'settings' ];

    public static function coreServiceSlugs(): array {
        return self::CORE_SERVICE_SLUGS;
    }

    public static function isCoreServiceSlug( string $slug ): bool {
        return in_array( \metis_key_clean( $slug ), self::CORE_SERVICE_SLUGS, true );
    }

    public static function moduleRootPath(): string {
        return self::normalizedPath(
            \defined( 'METIS_MODULES_PATH' )
                ? (string) \METIS_MODULES_PATH
                : dirname( __DIR__, 3 ) . '/modules/'
        );
    }

    public static function coreServiceRootPath(): string {
        return self::normalizedPath(
            \defined( 'METIS_CORE_SERVICES_PATH' )
                ? (string) \METIS_CORE_SERVICES_PATH
                : dirname( __DIR__, 3 ) . '/core-services/'
        );
    }

    public static function rootDefinitions(): array {
        $roots = [];

        $coreServices = self::coreServiceRootPath();
        if ( is_dir( $coreServices ) ) {
            $roots[] = [
                'path' => $coreServices,
                'package_type' => 'core_service',
            ];
        }

        $modules = self::moduleRootPath();
        if ( is_dir( $modules ) ) {
            $roots[] = [
                'path' => $modules,
                'package_type' => 'module',
            ];
        }

        return $roots;
    }

    public static function allRootPaths(): array {
        return array_values(
            array_map(
                static fn ( array $root ): string => (string) $root['path'],
                self::rootDefinitions()
            )
        );
    }

    public static function manifestPaths( ?string $packageType = null ): array {
        $paths = [];

        foreach ( self::rootDefinitions() as $root ) {
            if ( $packageType !== null && ( $root['package_type'] ?? '' ) !== $packageType ) {
                continue;
            }

            foreach ( glob( rtrim( (string) $root['path'], '/\\' ) . '/*/module.json' ) ?: [] as $manifestPath ) {
                $paths[] = (string) $manifestPath;
            }
        }

        sort( $paths );

        return $paths;
    }

    public static function moduleDirectories( ?string $packageType = null ): array {
        $directories = [];

        foreach ( self::rootDefinitions() as $root ) {
            if ( $packageType !== null && ( $root['package_type'] ?? '' ) !== $packageType ) {
                continue;
            }

            foreach ( glob( rtrim( (string) $root['path'], '/\\' ) . '/*', GLOB_ONLYDIR ) ?: [] as $directory ) {
                $directories[] = (string) $directory;
            }
        }

        sort( $directories );

        return $directories;
    }

    public static function packageTypeForPath( string $path ): string {
        $normalized = self::normalizedPath( $path );

        foreach ( self::rootDefinitions() as $root ) {
            $rootPath = self::normalizedPath( (string) ( $root['path'] ?? '' ) );
            if ( $rootPath !== '' && str_starts_with( $normalized, $rootPath ) ) {
                return (string) ( $root['package_type'] ?? 'module' );
            }
        }

        $slug = basename( rtrim( $normalized, '/\\' ) );
        return self::isCoreServiceSlug( $slug ) ? 'core_service' : 'module';
    }

    public static function modulePath( string $slug ): ?string {
        $slug = \metis_key_clean( $slug );
        if ( $slug === '' ) {
            return null;
        }

        foreach ( self::rootDefinitions() as $root ) {
            $candidate = rtrim( (string) $root['path'], '/\\' ) . '/' . $slug;
            if ( is_dir( $candidate ) ) {
                return $candidate;
            }
        }

        return null;
    }

    private static function normalizedPath( string $path ): string {
        return rtrim( str_replace( '\\', '/', $path ), '/' ) . '/';
    }
}
