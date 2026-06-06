<?php
declare(strict_types=1);

namespace Metis\Modules\Website;

use Metis\Core\Editor\BlockRegistry as CoreBlockRegistry;

/**
 * Website compatibility wrapper for the core editor block registry.
 */
final class BlockRegistry {
    private static bool $websiteBlocksBooted = false;

    public static function boot(): void {
        CoreBlockRegistry::boot();
        self::registerWebsiteDynamicBlocks();
    }

    public static function register( string $type, array $definition ): void {
        CoreBlockRegistry::register( $type, $definition );
    }

    public static function get( string $type ): ?array {
        self::boot();
        return CoreBlockRegistry::get( $type );
    }

    public static function all(): array {
        self::boot();
        return CoreBlockRegistry::all();
    }

    public static function exists( string $type ): bool {
        self::boot();
        return CoreBlockRegistry::exists( $type );
    }

    public static function validateBlock( array $block ): array {
        self::boot();
        return CoreBlockRegistry::validateBlock( $block );
    }

    private static function registerWebsiteDynamicBlocks(): void {
        if ( self::$websiteBlocksBooted ) {
            return;
        }
        self::$websiteBlocksBooted = true;

        $definitions = [
            'donation_form_block' => [
                'label' => 'Donation Form',
                'category' => 'dynamic',
                'icon' => 'donation-form',
                'schema_raw' => [
                    'type' => 'object',
                    'properties' => [
                        'campaign_id' => [ 'type' => 'string', 'default' => '' ],
                        'preset_amounts' => [ 'type' => 'array', 'default' => [ 25, 50, 100 ] ],
                        'allow_custom_amount' => [ 'type' => 'boolean', 'default' => true ],
                        'mode' => [ 'type' => 'string', 'default' => 'both' ],
                        'show_name' => [ 'type' => 'boolean', 'default' => true ],
                        'show_email' => [ 'type' => 'boolean', 'default' => true ],
                        'show_phone' => [ 'type' => 'boolean', 'default' => false ],
                    ],
                ],
            ],
            'testimonies_block' => [
                'label' => 'Testimonies',
                'category' => 'dynamic',
                'icon' => 'quote',
                'schema_raw' => [
                    'type' => 'object',
                    'properties' => [
                        'category_ids' => [ 'type' => 'array', 'default' => [] ],
                        'limit' => [ 'type' => 'integer', 'default' => 6 ],
                        'layout' => [ 'type' => 'string', 'default' => 'grid' ],
                        'featured_only' => [ 'type' => 'boolean', 'default' => false ],
                        'show_category' => [ 'type' => 'boolean', 'default' => true ],
                        'empty_message' => [ 'type' => 'string', 'default' => '' ],
                    ],
                ],
            ],
            'form_tabs_block' => [
                'label' => 'Form Tabs',
                'category' => 'dynamic',
                'icon' => 'tabs-streamline-rounded-material-symbols',
                'schema_raw' => [
                    'type' => 'object',
                    'properties' => [
                        'tabs' => [ 'type' => 'array', 'default' => [] ],
                    ],
                ],
            ],
        ];

        foreach ( $definitions as $type => $definition ) {
            if ( CoreBlockRegistry::get( $type ) !== null ) {
                continue;
            }
            $definition['schema'] = self::schemaUiFromRaw( is_array( $definition['schema_raw'] ?? null ) ? $definition['schema_raw'] : [] );
            CoreBlockRegistry::register( $type, $definition );
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @return array<string,array<string,mixed>>
     */
    private static function schemaUiFromRaw( array $schema ): array {
        $out = [];
        $properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
        $required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? array_flip( array_filter( $schema['required'], 'is_string' ) ) : [];
        foreach ( $properties as $key => $meta ) {
            if ( ! is_string( $key ) || ! is_array( $meta ) ) {
                continue;
            }
            $out[ $key ] = [
                'type' => isset( $meta['type'] ) ? (string) $meta['type'] : 'string',
                'default' => $meta['default'] ?? null,
                'required' => isset( $required[ $key ] ),
            ];
        }
        return $out;
    }
}
