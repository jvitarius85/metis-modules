<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Services\HermesDefinitionLibrary;

final class HermesDocumentationIndex {
    private ?array $cached = null;

    public function __construct(
        private readonly HermesDefinitionLibrary $library
    ) {}

    public function search( string $query, int $limit = 6 ): array {
        $needle = strtolower( trim( $query ) );
        if ( $needle === '' ) {
            return [];
        }

        $matches = [];
        foreach ( $this->documents() as $document ) {
            $haystack = strtolower( implode( "\n", array_filter( [
                (string) ( $document['title'] ?? '' ),
                (string) ( $document['description'] ?? '' ),
                implode( ' ', array_map( 'strval', (array) ( $document['keywords'] ?? [] ) ) ),
            ] ) ) );

            if ( ! str_contains( $haystack, $needle ) ) {
                continue;
            }

            $matches[] = $document;
        }

        return array_slice( $matches, 0, max( 1, min( 20, $limit ) ) );
    }

    public function documents(): array {
        if ( $this->cached !== null ) {
            return $this->cached;
        }

        $snapshot = $this->library->runtimeSnapshot();
        $documents = [];

        foreach ( [ 'context_packs', 'playbooks', 'missions' ] as $group ) {
            foreach ( (array) ( $snapshot[ $group ] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $documents[] = [
                    'type'        => rtrim( $group, 's' ),
                    'key'         => (string) ( $item['key'] ?? '' ),
                    'title'       => (string) ( $item['title'] ?? $item['key'] ?? 'Hermes Item' ),
                    'description' => (string) ( $item['description'] ?? $item['objective'] ?? '' ),
                    'keywords'    => array_values( array_filter( array_map( 'strval', array_merge(
                        (array) ( $item['intent_signals'] ?? [] ),
                        (array) ( $item['required_context_packs'] ?? [] ),
                        (array) ( $item['source_modules'] ?? [] )
                    ) ) ) ),
                    'source'      => 'hermes_library',
                ];
            }
        }

        $this->cached = $documents;
        return $this->cached;
    }
}
