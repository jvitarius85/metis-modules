<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

final class Metis_Batch_Response {
    public static function build(
        bool $success,
        int $saved,
        int $failed,
        array $results,
        int $total_received,
        int $valid_submitted,
        int $invalid_skipped
    ): array {
        return [
            'success' => $success,
            'saved' => $saved,
            'failed' => $failed,
            'results' => array_values( $results ),
            'summary' => [
                'total_received' => $total_received,
                'valid_submitted' => $valid_submitted,
                'invalid_skipped' => $invalid_skipped,
            ],
        ];
    }
}
