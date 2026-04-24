<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\PostService;

final class EditorAutosaveService {
    public const DEBOUNCE_MS = 2000;

    /**
     * @param array<string,mixed> $data
     */
    public static function saveDraft( string $entityType, int $entityId, array $data ): bool {
        if ( $entityType === 'page' ) {
            return PageService::update( $entityId, [
                'title' => $data['title'] ?? null,
                'slug' => $data['slug'] ?? null,
                'draft_layout_json' => $data['layout_json'] ?? null,
                'status' => 'draft',
            ] );
        }

        if ( $entityType === 'post' ) {
            return PostService::update( $entityId, [
                'title' => $data['title'] ?? null,
                'slug' => $data['slug'] ?? null,
                'draft_content_json' => $data['content_json'] ?? null,
                'excerpt' => $data['excerpt'] ?? null,
                'status' => 'draft',
            ] );
        }

        return false;
    }
}
