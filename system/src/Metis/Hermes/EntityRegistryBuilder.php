<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Core\ModulePathRegistry;

final class EntityRegistryBuilder {

    private static ?array $registry      = null;
    private static ?array $aliasMap      = null;
    private static ?array $knowledgeGraph = null;

    private array $moduleRoots;

    public function __construct( string|array $modulesRoot = '' ) {
        if ( is_array( $modulesRoot ) ) {
            $roots = $modulesRoot;
        } elseif ( $modulesRoot !== '' ) {
            $roots = [ $modulesRoot ];
        } else {
            $roots = $this->defaultModulesRoots();
        }

        $this->moduleRoots = array_values(
            array_filter(
                array_map(
                    static fn ( mixed $root ): string => rtrim( (string) $root, '/' ),
                    $roots
                ),
                static fn ( string $root ): bool => $root !== ''
            )
        );
    }

    public function registry(): array {
        $this->buildIfNeeded();
        return self::$registry ?? [];
    }

    public function resolve( string $term ): ?string {
        $this->buildIfNeeded();
        $key = strtolower( trim( $term ) );
        if ( isset( self::$registry[ $key ] ) ) {
            return $key;
        }
        return self::$aliasMap[ $key ] ?? null;
    }

    public function definition( string $entity ): ?array {
        $key = $this->resolve( $entity );
        return $key !== null ? ( self::$registry[ $key ] ?? null ) : null;
    }

    public function knowledgeGraph(): array {
        $this->buildIfNeeded();
        return self::$knowledgeGraph ?? [];
    }

    public function flush(): void {
        self::$registry       = null;
        self::$aliasMap       = null;
        self::$knowledgeGraph = null;
    }

    private function buildIfNeeded(): void {
        if ( self::$registry !== null ) {
            return;
        }
        $this->build();
    }

    private function defaultModulesRoots(): array {
        $roots = ModulePathRegistry::allRootPaths();
        if ( $roots !== [] ) {
            return $roots;
        }

        $candidates = [];
        if ( defined( 'METIS_ROOT' ) ) {
            $root = rtrim( (string) METIS_ROOT, '/' );
            $candidates[] = $root . '/modules';
            $candidates[] = $root . '/system/modules';
        }

        $base = rtrim( dirname( __DIR__, 4 ), '/' );
        $candidates[] = $base . '/modules';
        $candidates[] = $base . '/system/modules';

        return array_values( array_unique( $candidates ) );
    }

    private function build(): void {
        $registry = [];
        $aliases  = [];
        $graph    = [];

        $files = [];
        foreach ( $this->moduleRoots as $moduleRoot ) {
            $pattern = $moduleRoot . '/*/entities/*.entity.json';
            $matches = glob( $pattern );
            if ( is_array( $matches ) ) {
                $files = array_merge( $files, $matches );
            }
        }

        if ( $files === [] ) {
            self::$registry       = $registry;
            self::$aliasMap       = $aliases;
            self::$knowledgeGraph = $graph;
            return;
        }

        foreach ( $files as $filePath ) {
            $definition = $this->loadFile( $filePath );
            if ( $definition === null ) {
                continue;
            }

            $key = strtolower( trim( (string) ( $definition['entity'] ?? '' ) ) );
            if ( $key === '' ) {
                continue;
            }

            $registry[ $key ] = $definition;
            $aliases[ $key ]  = $key;

            foreach ( (array) ( $definition['aliases'] ?? [] ) as $alias ) {
                $normalized = strtolower( trim( (string) $alias ) );
                if ( $normalized !== '' ) {
                    $aliases[ $normalized ] = $key;
                }
            }

            foreach ( (array) ( $definition['relationships'] ?? [] ) as $rel ) {
                if ( ! is_array( $rel ) ) {
                    continue;
                }
                $related = strtolower( trim( (string) ( $rel['entity'] ?? '' ) ) );
                $fk      = (string) ( $rel['foreign_key'] ?? '' );
                if ( $related !== '' && $fk !== '' ) {
                    $graph[ $key ][ $related ] = $fk;
                }
            }
        }

        self::$registry       = $registry;
        self::$aliasMap       = $aliases;
        self::$knowledgeGraph = $graph;

        if ( function_exists( 'metis_logger' ) ) {
            metis_logger()->info( 'Hermes entity registry built', [
                'entity_count' => count( $registry ),
                'alias_count'  => count( $aliases ),
                'graph_edges'  => array_sum( array_map( 'count', $graph ) ),
            ] );
        }
    }

    private function loadFile( string $filePath ): ?array {
        if ( ! is_readable( $filePath ) ) {
            return null;
        }
        $raw = file_get_contents( $filePath );
        if ( $raw === false || trim( $raw ) === '' ) {
            return null;
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }
        if ( (int) ( $decoded['schema_version'] ?? 0 ) < 1 ) {
            return null;
        }
        return $decoded;
    }
}
