<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

final class Metis_Batch_Processor {
    public function process( array $validated_rows, array $context ): array {
        $processor = (string) ( $context['processor'] ?? '' );
        if ( $processor === '' || ! function_exists( $processor ) ) {
            return [
                'saved' => 0,
                'failed' => count( $validated_rows ),
                'results' => array_map( static function ( array $entry ): array {
                    return [
                        'row_id' => (string) ( $entry['row_id'] ?? '' ),
                        'status' => 'error',
                        'message' => 'Processor callback is unavailable.',
                    ];
                }, $validated_rows ),
            ];
        }

        $saved = 0;
        $failed = 0;
        $results = [];
        $chunks = array_chunk( $validated_rows, 50 );

        foreach ( $chunks as $chunk ) {
            foreach ( $chunk as $entry ) {
                $row = (array) ( $entry['row'] ?? [] );
                $row_id = (string) ( $entry['row_id'] ?? ( $row['row_id'] ?? '' ) );

                if ( (string) ( $entry['status'] ?? '' ) !== 'valid' ) {
                    $failed++;
                    $results[] = [
                        'row_id' => $row_id,
                        'status' => 'error',
                        'message' => implode( ' ', (array) ( $entry['errors'] ?? [ 'Invalid row.' ] ) ),
                    ];
                    continue;
                }

                try {
                    $processed = $processor( $row, $context );
                    $ok = is_array( $processed ) ? ! empty( $processed['success'] ) : false;

                    if ( ! $ok ) {
                        $failed++;
                        $results[] = [
                            'row_id' => $row_id,
                            'status' => 'error',
                            'message' => is_array( $processed )
                                ? (string) ( $processed['message'] ?? 'Row processing failed.' )
                                : 'Row processing failed.',
                        ];
                        continue;
                    }

                    $saved++;
                    $results[] = [
                        'row_id' => $row_id,
                        'status' => 'saved',
                        'message' => is_array( $processed )
                            ? (string) ( $processed['message'] ?? 'Saved.' )
                            : 'Saved.',
                    ];
                } catch ( Throwable $e ) {
                    $failed++;
                    $results[] = [
                        'row_id' => $row_id,
                        'status' => 'error',
                        'message' => 'Row failed during processing.',
                    ];

                    if ( class_exists( 'Metis_Logger' ) ) {
                        Metis_Logger::error( 'Batch row processing exception', [
                            'module' => (string) ( $context['module'] ?? '' ),
                            'action' => (string) ( $context['action'] ?? '' ),
                            'row_id' => $row_id,
                            'error' => $e->getMessage(),
                        ] );
                    }
                }
            }
        }

        return [
            'saved' => $saved,
            'failed' => $failed,
            'results' => $results,
        ];
    }
}
