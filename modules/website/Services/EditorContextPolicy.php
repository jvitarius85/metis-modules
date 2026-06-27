<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Editor\EditorContextPolicy as CoreEditorContextPolicy;
use Metis\Modules\Website\BlockRegistry;

final class EditorContextPolicy {
    private static function bootWebsiteRegistry(): void {
        BlockRegistry::boot();
    }

    public static function normalizeContext( string $context ): string {
        return CoreEditorContextPolicy::normalizeContext( $context );
    }

    public static function normalizeRenderMode( string $render_mode, string $context = "website" ): string {
        return CoreEditorContextPolicy::normalizeRenderMode( $render_mode, $context );
    }

    public static function profile( string $context, string $render_mode = "" ): array {
        self::bootWebsiteRegistry();
        return CoreEditorContextPolicy::profile( $context, $render_mode );
    }

    public static function filterRegistry( array $definitions, string $context, string $render_mode = "" ): array {
        self::bootWebsiteRegistry();
        return CoreEditorContextPolicy::filterRegistry( $definitions, $context, $render_mode );
    }

    public static function validateBlocks( array $block_list, string $context, string $render_mode = "" ): array {
        self::bootWebsiteRegistry();
        return CoreEditorContextPolicy::validateBlocks( $block_list, $context, $render_mode );
    }

    public static function isBlockAllowed( string $type, array $definition, string $context, string $render_mode = "" ): bool {
        self::bootWebsiteRegistry();
        return CoreEditorContextPolicy::isBlockAllowed( $type, $definition, $context, $render_mode );
    }

    public static function sanitizeStyleForRenderMode( array $style, string $render_mode ): array {
        return CoreEditorContextPolicy::sanitizeStyleForRenderMode( $style, $render_mode );
    }
}
