<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

use Metis\Modules\Website\Services\RevisionTimelineService;

final class EditorVersionService {
    /**
     * @param array<string,mixed> $payload
     */
    public static function checkpoint( string $entityType, int $entityId, array $payload, string $note = '' ): bool {
        if ( $entityId < 1 ) {
            return false;
        }
        return RevisionTimelineService::save( $entityType, $entityId, $payload, $note );
    }
}
