<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Services;

final class ImporterPipeline {
    public static function parseUpload( array $file, int $user_id ): array {
        return ImportService::previewFromUpload( $file, $user_id );
    }

    public static function confirm( array $options, int $user_id ): array {
        return ImportService::confirmImport( $user_id, $options );
    }
}
