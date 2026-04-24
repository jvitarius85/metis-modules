<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class EntityRegistryBuilder {

    private static ?array $registry      = null;
    private static ?array $aliasMap      = null;
    private static ?array $knowledgeGraph = null;

    private string $modulesRoot;

    public function __construct( string $modulesRoot = '' ) {
        $this->modulesRoot = $modulesRoot !== ''
            ? rtrim( $modulesRoot, '/' )
            : rtrim( defined( 'METIS_ROOT' ) ? METIS_ROOT : dirname( __DIR__, 3 ), '/' ) . '/modules';
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

    private function build(): void {
        $registry = [];
        $aliases  = [];
        $graph    = [];

        $pattern = $this->modulesRoot . '/*/entities/*.entity.json';
        $files   = glob( $pattern );

        if ( $files === false || $files === [] ) {
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