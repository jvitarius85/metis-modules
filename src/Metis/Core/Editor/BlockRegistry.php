<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

final class BlockRegistry {
    /** @var array<string,array<string,mixed>> */
    private static array $registeredBlocks = [];
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::registerFromFilesystem();
        self::$booted = true;
    }

    /**
     * @param array<string,mixed> $definition
     */
    public static function register( string $type, array $definition ): void {
        $key = metis_key_clean( $type );
        if ( $key === '' ) {
            return;
        }
        self::$registeredBlocks[ $key ] = $definition;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get( string $type ): ?array {
        self::boot();
        $key = metis_key_clean( $type );
        return self::$registeredBlocks[ $key ] ?? null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array {
        self::boot();
        return self::$registeredBlocks;
    }

    public static function exists( string $type ): bool {
        return self::get( $type ) !== null;
    }

    /**
     * @param array<string,mixed> $block
     * @return array{valid:bool,errors:array<int,string>}
     */
    public static function validateBlock( array $block ): array {
        $type = isset( $block['type'] ) ? metis_key_clean( (string) $block['type'] ) : '';
        if ( $type === '' ) {
            return [ 'valid' => false, 'errors' => [ 'Block type is required' ] ];
        }

        $definition = self::get( $type );
        if ( $definition === null ) {
            return [ 'valid' => false, 'errors' => [ 'Unknown block type: ' . $type ] ];
        }

        $payload = isset( $block['data'] ) && is_array( $block['data'] ) ? $block['data'] : [];
        $schema = isset( $definition['schema_raw'] ) && is_array( $definition['schema_raw'] )
            ? $definition['schema_raw']
            : [];
        $validation = EditorSchemaValidator::validateBlockPayload( $schema, $payload );
        return [
            'valid' => (bool) ( $validation['valid'] ?? false ),
            'errors' => isset( $validation['errors'] ) && is_array( $validation['errors'] ) ? $validation['errors'] : [],
        ];
    }

    private static function registerFromFilesystem(): void {
        $blocksDir = __DIR__ . '/Blocks';
        if ( ! is_dir( $blocksDir ) ) {
            return;
        }

        $names = scandir( $blocksDir );
        if ( ! is_array( $names ) ) {
            return;
        }

        foreach ( $names as $name ) {
            if ( $name === '.' || $name === '..' ) {
                continue;
            }
            $type = metis_key_clean( $name );
            if ( $type === '' ) {
                continue;
            }

            $dir = $blocksDir . '/' . $name;
            $schemaPath = $dir . '/schema.json';
            $renderPath = $dir . '/render.php';
            if ( ! is_file( $schemaPath ) || ! is_file( $renderPath ) ) {
                continue;
            }

            $raw = file_get_contents( $schemaPath );
            if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
                continue;
            }
            $schemaRaw = json_decode( $raw, true );
            if ( ! is_array( $schemaRaw ) ) {
                continue;
            }

            $schema = self::convertSchemaForUi( $schemaRaw );
            self::register( $type, [
                'label' => ucwords( str_replace( '_', ' ', $type ) ),
                'category' => self::categoryForType( $type ),
                'icon' => $type,
                'schema' => $schema,
                'schema_raw' => $schemaRaw,
                'render_path' => $renderPath,
            ] );
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,array<string,mixed>>
     */
    private static function convertSchemaForUi( array $schema ): array {
        $out = [];
        $properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
        $requiredSet = [];
        if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
            foreach ( $schema['required'] as $requiredKey ) {
                if ( is_string( $requiredKey ) && $requiredKey !== '' ) {
                    $requiredSet[ $requiredKey ] = true;
                }
            }
        }

        foreach ( $properties as $key => $meta ) {
            if ( ! is_string( $key ) || ! is_array( $meta ) ) {
                continue;
            }
            $out[ $key ] = [
                'type' => isset( $meta['type'] ) ? (string) $meta['type'] : 'string',
                'default' => $meta['default'] ?? null,
                'required' => isset( $requiredSet[ $key ] ),
            ];
        }

        return $out;
    }

    private static function categoryForType( string $type ): string {
        if ( in_array( $type, [ 'image' ], true ) ) {
            return 'media';
        }
        if ( in_array( $type, [ 'button' ], true ) ) {
            return 'interactive';
        }
        if ( in_array( $type, [ 'spacer', 'divider', 'columns' ], true ) ) {
            return 'layout';
        }
        return 'content';
    }
}
