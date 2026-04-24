<?php
declare(strict_types=1);

namespace Metis\Modules\Website;

use Metis\Core\Editor\BlockRegistry as CoreBlockRegistry;

/**
 * Website compatibility wrapper for the core editor block registry.
 */
final class BlockRegistry {
    public static function boot(): void {
        CoreBlockRegistry::boot();
    }

    public static function register( string $type, array $definition ): void {
        CoreBlockRegistry::register( $type, $definition );
    }

    public static function get( string $type ): ?array {
        return CoreBlockRegistry::get( $type );
    }

    public static function all(): array {
        return CoreBlockRegistry::all();
    }

    public static function exists( string $type ): bool {
        return CoreBlockRegistry::exists( $type );
    }

    public static function validateBlock( array $block ): array {
        return CoreBlockRegistry::validateBlock( $block );
    }
}
