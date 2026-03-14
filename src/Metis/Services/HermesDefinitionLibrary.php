<?php
declare(strict_types=1);

namespace Metis\Services;

final class HermesDefinitionLibrary {
    private string $basePath;
    private ?array $library = null;

    public function __construct( ?string $basePath = null ) {
        $root = $basePath ?? ( defined( 'METIS_PATH' ) ? METIS_PATH : dirname( __DIR__, 3 ) . '/' );
        $this->basePath = \trailingslashit( $root );
    }

    public function library(): array {
        if ( $this->library !== null ) {
            return $this->library;
        }

        $manifest = $this->readJsonFile( $this->absolutePath( 'config/hermes/library.json' ) );

        $this->library = [
            'schema_version' => (int) ( $manifest['schema_version'] ?? 1 ),
            'manifest' => $manifest,
            'context_packs' => $this->loadDefinitions( (array) ( $manifest['context_packs'] ?? [] ), 'context_pack' ),
            'playbooks' => $this->loadDefinitions( (array) ( $manifest['playbooks'] ?? [] ), 'playbook' ),
            'missions' => $this->loadDefinitions( (array) ( $manifest['missions'] ?? [] ), 'mission' ),
            'dynamic_layer' => [
                'schema' => $this->readJsonFile( $this->absolutePath( (string) ( $manifest['dynamic_layer']['schema_path'] ?? 'config/hermes/dynamic-context.schema.json' ) ) ),
                'snapshot' => $this->loadDynamicSnapshot( (string) ( $manifest['dynamic_layer']['snapshot_path'] ?? 'storage/hermes/dynamic-context.json' ) ),
            ],
        ];

        return $this->library;
    }

    public function contextPacks(): array {
        return $this->library()['context_packs'];
    }

    public function playbooks(): array {
        return $this->library()['playbooks'];
    }

    public function missions(): array {
        return $this->library()['missions'];
    }

    public function dynamicLayer(): array {
        return $this->library()['dynamic_layer'];
    }

    public function getContextPack( string $key ): ?array {
        return $this->contextPacks()[ \sanitize_key( $key ) ] ?? null;
    }

    public function getPlaybook( string $key ): ?array {
        return $this->playbooks()[ \sanitize_key( $key ) ] ?? null;
    }

    public function getMission( string $key ): ?array {
        return $this->missions()[ \sanitize_key( $key ) ] ?? null;
    }

    public function runtimeSnapshot(): array {
        $library = $this->library();

        return [
            'schema_version' => $library['schema_version'],
            'context_packs' => array_values( $library['context_packs'] ),
            'playbooks' => array_values( $library['playbooks'] ),
            'missions' => array_values( $library['missions'] ),
            'dynamic_layer' => $library['dynamic_layer'],
        ];
    }

    private function loadDefinitions( array $paths, string $expectedType ): array {
        $definitions = [];

        foreach ( $paths as $relativePath ) {
            $path = $this->absolutePath( (string) $relativePath );
            $definition = $this->readJsonFile( $path );
            $type = \sanitize_key( (string) ( $definition['type'] ?? '' ) );
            $key = \sanitize_key( (string) ( $definition['key'] ?? '' ) );

            if ( $type !== $expectedType ) {
                throw new \RuntimeException( sprintf( 'Hermes definition [%s] must declare type [%s].', $path, $expectedType ) );
            }

            if ( $key === '' ) {
                throw new \RuntimeException( sprintf( 'Hermes definition [%s] is missing a valid key.', $path ) );
            }

            $definition['_path'] = $path;
            $definitions[ $key ] = $definition;
        }

        return $definitions;
    }

    private function loadDynamicSnapshot( string $relativePath ): array {
        $path = $this->absolutePath( $relativePath );
        if ( ! is_file( $path ) ) {
            return [
                'type' => 'dynamic_context_snapshot',
                'schema_version' => 1,
                'failure_patterns' => [],
                'successful_resolutions' => [],
                'workflow_signals' => [],
            ];
        }

        return $this->readJsonFile( $path );
    }

    private function absolutePath( string $relativePath ): string {
        $relativePath = ltrim( $relativePath, '/' );
        return $this->basePath . $relativePath;
    }

    private function readJsonFile( string $path ): array {
        if ( ! is_file( $path ) ) {
            throw new \RuntimeException( sprintf( 'Hermes definition file not found: %s', $path ) );
        }

        $raw = file_get_contents( $path );
        $decoded = json_decode( is_string( $raw ) ? $raw : '', true );

        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( sprintf( 'Hermes definition file contains invalid JSON: %s', $path ) );
        }

        return $decoded;
    }
}
