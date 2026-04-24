<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

final class EditorFlowService {
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function websiteFlow(): array {
        return [
            [ 'step' => 1, 'key' => 'properties', 'title' => 'Properties' ],
            [ 'step' => 2, 'key' => 'section_editor', 'title' => 'Section Editor' ],
            [ 'step' => 3, 'key' => 'preview', 'title' => 'Preview' ],
            [ 'step' => 4, 'key' => 'publish', 'title' => 'Publish / Schedule' ],
        ];
    }

    public static function canAdvance( int $fromStep, array $data ): bool {
        if ( $fromStep === 1 ) {
            return trim( (string) ( $data['title'] ?? '' ) ) !== ''
                && trim( (string) ( $data['slug'] ?? '' ) ) !== '';
        }

        if ( $fromStep === 2 ) {
            $layout = isset( $data['layout_json'] ) ? (string) $data['layout_json'] : '';
            return trim( $layout ) !== '';
        }

        return true;
    }

    public static function canJump( bool $entityExists ): bool {
        return $entityExists;
    }
}
