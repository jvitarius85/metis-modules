<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesWalkthroughResolver {
    public function __construct(
        private readonly \Metis_Walkthrough_Service $walkthroughs
    ) {}

    public function search( string $query, int $limit = 4 ): array {
        $needle = strtolower( trim( $query ) );
        if ( $needle === '' ) {
            return [];
        }

        $results = [];
        foreach ( $this->walkthroughs->all() as $id => $walkthrough ) {
            $haystack = strtolower( implode( "\n", [
                (string) $id,
                (string) ( $walkthrough['title'] ?? '' ),
                (string) ( $walkthrough['description'] ?? '' ),
                (string) ( $walkthrough['module'] ?? '' ),
            ] ) );

            if ( ! str_contains( $haystack, $needle ) ) {
                continue;
            }

            $results[] = array_merge( [ 'id' => $id ], $walkthrough );
        }

        return array_slice( $results, 0, max( 1, min( 12, $limit ) ) );
    }

    public function get( string $walkthrough_id ): ?array {
        return $this->walkthroughs->get( $walkthrough_id );
    }

    public function markStarted( string $walkthrough_id ): void {
        $this->walkthroughs->save_progress( $walkthrough_id, [ 'step' => 0, 'completed' => false, 'skipped' => false ] );
    }
}
