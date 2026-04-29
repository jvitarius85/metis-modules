<?php
declare(strict_types=1);

namespace Metis\Modules\Cms\Services;

use Metis\Core\Editor\EditorContextPolicy as CoreEditorContextPolicy;

final class EditorContextPolicy {
    public static function normalizeContext( string $context ): string {
        return CoreEditorContextPolicy::normalizeContext( $context );
    }

    public static function normalizeRenderMode( string $render_mode, string $context = "cms" ): string {
        return CoreEditorContextPolicy::normalizeRenderMode( $render_mode, $context );
    }

    public static function profile( string $context, string $render_mode = "" ): array {
        return CoreEditorContextPolicy::profile( $context, $render_mode );
    }

    public static function filterRegistry( array $definitions, string $context, string $render_mode = "" ): array {
        return CoreEditorContextPolicy::filterRegistry( $definitions, $context, $render_mode );
    }

    public static function validateBlocks( array $block_list, string $context, string $render_mode = "" ): array {
        return CoreEditorContextPolicy::validateBlocks( $block_list, $context, $render_mode );
    }

    public static function isBlockAllowed( string $type, array $definition, string $context, string $render_mode = "" ): bool {
        return CoreEditorContextPolicy::isBlockAllowed( $type, $definition, $context, $render_mode );
    }

    public static function sanitizeStyleForRenderMode( array $style, string $render_mode ): array {
        return CoreEditorContextPolicy::sanitizeStyleForRenderMode( $style, $render_mode );
    }
}
