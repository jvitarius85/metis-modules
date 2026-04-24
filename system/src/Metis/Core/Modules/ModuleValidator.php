<?php
declare(strict_types=1);

namespace Metis\Core\Modules;

final class ModuleValidator {
    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function validateModule( string $modulePath, array $manifest, string $fallbackSlug ): array {
        if ( ! is_dir( $modulePath ) ) {
            throw new \RuntimeException( 'Module directory missing: ' . $modulePath );
        }

        $manifestPath = $this->discoverManifestPath( $modulePath, $fallbackSlug );
        if ( $manifestPath === null ) {
            throw new \RuntimeException( 'Module missing manifest: ' . $modulePath );
        }

        $slug = $this->sanitizeKey( (string) ( $manifest['slug'] ?? $fallbackSlug ) );
        if ( $slug === '' ) {
            throw new \RuntimeException( 'Module manifest slug is invalid: ' . $manifestPath );
        }

        $this->validateStructureContract( $modulePath, $manifestPath, $slug, $manifest );

        foreach ( [ 'name', 'version', 'description' ] as $field ) {
            if ( trim( (string) ( $manifest[ $field ] ?? '' ) ) === '' ) {
                throw new \RuntimeException( sprintf( 'Module manifest missing [%s]: %s', $field, $manifestPath ) );
            }
        }

        $category = \metis_key_clean( (string) ( $manifest['category'] ?? '' ) );
        if ( ! in_array( $category, [ 'core', 'communications', 'website', 'administration', 'other' ], true ) ) {
            throw new \RuntimeException( 'Module manifest category must be one of core|communications|website|administration|other: ' . $manifestPath );
        }

        if ( array_key_exists( 'default_parent', $manifest ) && $manifest['default_parent'] !== null ) {
            $defaultParent = \metis_key_clean( (string) $manifest['default_parent'] );
            if ( $defaultParent !== '' && ! in_array( $defaultParent, [ 'communications', 'website', 'administration' ], true ) ) {
                throw new \RuntimeException( 'Module manifest default_parent must be communications|website|administration|null: ' . $manifestPath );
            }
        }

        if ( array_key_exists( 'order', $manifest ) && ! is_numeric( $manifest['order'] ) ) {
            throw new \RuntimeException( 'Module manifest order must be numeric: ' . $manifestPath );
        }

        $navigation = $manifest['navigation'] ?? null;
        if ( $navigation !== null ) {
            if ( ! is_array( $navigation ) ) {
                throw new \RuntimeException( 'Module manifest navigation must be an object: ' . $manifestPath );
            }

            if ( array_key_exists( 'enabled', $navigation ) && ! is_bool( $navigation['enabled'] ) ) {
                throw new \RuntimeException( 'Module manifest navigation.enabled must be boolean: ' . $manifestPath );
            }

            if ( array_key_exists( 'visible', $navigation ) && ! is_bool( $navigation['visible'] ) ) {
                throw new \RuntimeException( 'Module manifest navigation.visible must be boolean: ' . $manifestPath );
            }

            if ( array_key_exists( 'label', $navigation ) && ! is_string( $navigation['label'] ) ) {
                throw new \RuntimeException( 'Module manifest navigation.label must be a string: ' . $manifestPath );
            }
        }

        if ( array_key_exists( 'quick_actions', $manifest ) ) {
            if ( ! is_array( $manifest['quick_actions'] ) ) {
                throw new \RuntimeException( 'Module manifest quick_actions must be an array: ' . $manifestPath );
            }

            $actionKeys = [];
            foreach ( $manifest['quick_actions'] as $index => $action ) {
                if ( ! is_array( $action ) ) {
                    throw new \RuntimeException( sprintf( 'Module quick_actions[%d] must be an object: %s', $index, $manifestPath ) );
                }

                $key = $this->sanitizeKey( (string) ( $action['key'] ?? '' ) );
                $label = trim( (string) ( $action['label'] ?? '' ) );
                $type = $this->sanitizeKey( (string) ( $action['type'] ?? 'route' ) );
                $route = trim( (string) ( $action['route'] ?? '' ) );
                $handler = trim( (string) ( $action['handler'] ?? '' ) );

                if ( $key === '' || $label === '' ) {
                    throw new \RuntimeException( sprintf( 'Module quick_actions[%d] must include key and label: %s', $index, $manifestPath ) );
                }

                if ( isset( $actionKeys[ $key ] ) ) {
                    throw new \RuntimeException( sprintf( 'Module quick_actions key must be unique (%s): %s', $key, $manifestPath ) );
                }
                $actionKeys[ $key ] = true;

                if ( ! in_array( $type, [ 'modal', 'route' ], true ) ) {
                    throw new \RuntimeException( sprintf( 'Module quick_actions[%d] type must be modal|route: %s', $index, $manifestPath ) );
                }

                if ( $type === 'route' && $route === '' ) {
                    throw new \RuntimeException( sprintf( 'Module quick_actions[%d] route is required: %s', $index, $manifestPath ) );
                }

                if ( $type === 'modal' && $handler === '' ) {
                    throw new \RuntimeException( sprintf( 'Module quick_actions[%d] handler is required for modal actions: %s', $index, $manifestPath ) );
                }

                if ( $key === 'create_campaign' || str_contains( strtolower( $label ), 'create campaign' ) ) {
                    throw new \RuntimeException( sprintf( 'Module quick_actions[%d] cannot define Create Campaign: %s', $index, $manifestPath ) );
                }
            }
        }

        if ( array_key_exists( 'entity_prefixes', $manifest ) ) {
            if ( ! is_array( $manifest['entity_prefixes'] ) ) {
                throw new \RuntimeException( 'Module manifest entity_prefixes must be an array: ' . $manifestPath );
            }

            $entityTypes = [];
            $prefixes = [];
            foreach ( $manifest['entity_prefixes'] as $index => $entry ) {
                if ( ! is_array( $entry ) ) {
                    throw new \RuntimeException( sprintf( 'Module entity_prefixes[%d] must be an object: %s', $index, $manifestPath ) );
                }

                $entityType = $this->sanitizeKey( (string) ( $entry['entity_type'] ?? '' ) );
                $prefix = strtoupper( trim( (string) ( $entry['prefix'] ?? '' ) ) );
                $tableKey = trim( (string) ( $entry['table_key'] ?? '' ) );
                $uidColumn = trim( (string) ( $entry['uid_column'] ?? '' ) );

                if ( $entityType === '' || ! preg_match( '/^[A-Z]{2,8}$/', $prefix ) ) {
                    throw new \RuntimeException( sprintf( 'Module entity_prefixes[%d] requires entity_type and 2-8 letter prefix: %s', $index, $manifestPath ) );
                }

                if ( isset( $entityTypes[ $entityType ] ) ) {
                    throw new \RuntimeException( sprintf( 'Module entity_prefixes entity_type must be unique (%s): %s', $entityType, $manifestPath ) );
                }
                $entityTypes[ $entityType ] = true;

                if ( isset( $prefixes[ $prefix ] ) ) {
                    throw new \RuntimeException( sprintf( 'Module entity_prefixes prefix must be unique (%s): %s', $prefix, $manifestPath ) );
                }
                $prefixes[ $prefix ] = true;

                if ( ( $tableKey === '' ) xor ( $uidColumn === '' ) ) {
                    throw new \RuntimeException( sprintf( 'Module entity_prefixes[%d] must provide both table_key and uid_column (or neither): %s', $index, $manifestPath ) );
                }

                if ( array_key_exists( 'legacy_columns', $entry ) && ! is_array( $entry['legacy_columns'] ) ) {
                    throw new \RuntimeException( sprintf( 'Module entity_prefixes[%d].legacy_columns must be an array: %s', $index, $manifestPath ) );
                }
            }
        }

        if ( ! isset( $manifest['permissions'] ) || ! is_array( $manifest['permissions'] ) ) {
            throw new \RuntimeException( 'Module manifest permissions must be an array: ' . $manifestPath );
        }

        if ( ! isset( $manifest['views'] ) || ! is_array( $manifest['views'] ) || $manifest['views'] === [] ) {
            throw new \RuntimeException( 'Module manifest views must be a non-empty array: ' . $manifestPath );
        }

        foreach ( (array) $manifest['views'] as $view => $template ) {
            $view = $this->sanitizeKey( (string) $view );
            $template = ltrim( (string) $template, '/' );
            if ( $view === '' || $template === '' ) {
                throw new \RuntimeException( 'Module manifest view entry is invalid: ' . $manifestPath );
            }

            $viewPath = $modulePath . '/views/' . $template;
            $templatePath = $modulePath . '/templates/' . $template;
            if ( ! is_file( $viewPath ) && ! is_file( $templatePath ) ) {
                throw new \RuntimeException(
                    'Module view template missing: ' . $viewPath . ' (fallback checked: ' . $templatePath . ')'
                );
            }
        }

        $bootstrap = array_key_exists( 'bootstrap', $manifest )
            ? ltrim( (string) $manifest['bootstrap'], '/' )
            : 'bootstrap.php';
        if ( $bootstrap !== '' && ! is_file( $modulePath . '/' . $bootstrap ) ) {
            throw new \RuntimeException( 'Module bootstrap missing: ' . $modulePath . '/' . $bootstrap );
        }

        $this->validateBootstrapRoutingContract( $modulePath, $bootstrap, $slug );

        foreach ( (array) ( $manifest['services'] ?? [] ) as $serviceFile ) {
            $serviceFile = ltrim( (string) $serviceFile, '/' );
            if ( $serviceFile === '' ) {
                continue;
            }

            if ( ! is_file( $modulePath . '/' . $serviceFile ) ) {
                throw new \RuntimeException( 'Module service file missing: ' . $modulePath . '/' . $serviceFile );
            }
        }

        $assets = is_array( $manifest['assets'] ?? null ) ? (array) $manifest['assets'] : [];
        foreach ( [ 'css', 'js' ] as $assetType ) {
            foreach ( (array) ( $assets[ $assetType ] ?? [] ) as $assetFile ) {
                $assetFile = ltrim( (string) $assetFile, '/' );
                if ( $assetFile === '' ) {
                    continue;
                }

                if ( ! is_file( $modulePath . '/assets/' . $assetFile ) ) {
                    throw new \RuntimeException( 'Module asset missing: ' . $modulePath . '/assets/' . $assetFile );
                }
            }
        }

        $ajaxFile = ltrim( (string) ( $assets['ajax'] ?? '' ), '/' );
        if ( $ajaxFile !== '' && ! is_file( $modulePath . '/assets/' . $ajaxFile ) ) {
            throw new \RuntimeException( 'Module AJAX asset missing: ' . $modulePath . '/assets/' . $ajaxFile );
        }

        foreach ( (array) ( $manifest['routes'] ?? [] ) as $route ) {
            if ( ! is_array( $route ) ) {
                throw new \RuntimeException( 'Module route entry must be an array: ' . $manifestPath );
            }

            $name = trim( (string) ( $route['name'] ?? '' ) );
            $pattern = trim( (string) ( $route['pattern'] ?? '' ) );
            $handler = $route['handler'] ?? null;

            if ( $name === '' || $pattern === '' || ! is_callable( $handler ) && ! is_string( $handler ) ) {
                throw new \RuntimeException( 'Module route entry is invalid: ' . $manifestPath );
            }
        }

        $manifest['_manifest_path'] = $manifestPath;
        $manifest['_manifest_mtime'] = (int) ( @filemtime( $manifestPath ) ?: 0 );

        return $manifest;
    }

    private function discoverManifestPath( string $modulePath, string $fallbackSlug ): ?string {
        $candidate = $modulePath . '/module.json';

        if ( is_file( $candidate ) ) {
            return $candidate;
        }

        return null;
    }

    private function sanitizeKey( string $value ): string {
        return \metis_key_clean( $value );
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function validateStructureContract( string $modulePath, string $manifestPath, string $slug, array $manifest ): void {
        $moduleDirName = basename( rtrim( $modulePath, '/\\' ) );
        if ( ! preg_match( '/^[a-z0-9_]+$/', $moduleDirName ) ) {
            throw new \RuntimeException( 'Module directory name must be lowercase snake_case: ' . $modulePath );
        }

        if ( $moduleDirName !== $slug ) {
            throw new \RuntimeException( 'Module directory must match manifest slug: ' . $modulePath );
        }

        $manifestName = $this->sanitizeKey( (string) ( $manifest['name'] ?? '' ) );
        if ( $manifestName === '' || $manifestName !== $moduleDirName ) {
            throw new \RuntimeException( 'Module manifest name must match directory name: ' . $manifestPath );
        }

        foreach ( [ 'title', 'entry', 'views' ] as $field ) {
            if ( ! array_key_exists( $field, $manifest ) ) {
                throw new \RuntimeException( sprintf( 'Module manifest missing required field [%s]: %s', $field, $manifestPath ) );
            }
        }

        foreach ( [ 'routes', 'services' ] as $field ) {
            if ( array_key_exists( $field, $manifest ) && ! is_array( $manifest[ $field ] ) ) {
                throw new \RuntimeException( sprintf( 'Module manifest [%s] must be an array: %s', $field, $manifestPath ) );
            }
        }

        foreach ( [ 'Module.php', 'views' ] as $path ) {
            $absolute = $modulePath . '/' . $path;
            if ( ! file_exists( $absolute ) ) {
                throw new \RuntimeException( 'Module structure missing required path: ' . $absolute );
            }
        }

        $entry = ltrim( (string) ( $manifest['entry'] ?? '' ), '/' );
        if ( $entry === '' || ! is_file( $modulePath . '/' . $entry ) ) {
            throw new \RuntimeException( 'Module entry file missing: ' . $modulePath . '/' . $entry );
        }

    }

    private function validateBootstrapRoutingContract( string $modulePath, string $bootstrap, string $slug ): void {
        if ( $bootstrap === '' ) {
            return;
        }

        $bootstrapFile = $modulePath . '/' . $bootstrap;
        if ( ! is_file( $bootstrapFile ) ) {
            return;
        }

        $source = (string) @file_get_contents( $bootstrapFile );
        if ( $source === '' ) {
            return;
        }

        $registersRouter = preg_match( '/\bmetis_http_router\s*\(/', $source ) === 1
            || preg_match( '/->\s*register\s*\(/', $source ) === 1;
        $registersApiRoute = preg_match( '/[\'"]#\^\/api\//i', $source ) === 1
            || preg_match( '/[\'"]\/api\//i', $source ) === 1;

        if ( $registersRouter && $registersApiRoute ) {
            throw new \RuntimeException(
                sprintf(
                    'Module [%s] cannot register /api routes from bootstrap. Declare module routes in manifest routes and routes/*.php handlers.',
                    $slug
                )
            );
        }
    }
}
