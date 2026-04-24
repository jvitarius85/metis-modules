<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

use Metis\Modules\Website\Services\WebsiteRenderer;

final class EditorPreviewService {
    /**
     * @param array<int,mixed> $blocks
     * @param array<string,mixed> $options
     * @return array{document_html:string,content_html:string,context:array<string,mixed>}
     */
    public static function render( array $blocks, array $options = [] ): array {
        $layout_json = isset( $options['layout_json'] ) && is_string( $options['layout_json'] )
            ? $options['layout_json']
            : '';

        return WebsiteRenderer::renderStructuredEditorPreview( $layout_json, $options );
    }
}
