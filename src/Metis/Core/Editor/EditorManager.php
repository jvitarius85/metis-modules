<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

final class EditorManager {
    /**
     * @return array<string,mixed>
     */
    public static function websiteConfig(): array {
        return [
            'flow' => EditorFlowService::websiteFlow(),
            'autosave' => [
                'enabled' => true,
                'debounce_ms' => EditorAutosaveService::DEBOUNCE_MS,
            ],
            'block_registry' => BlockRegistry::all(),
        ];
    }
}
