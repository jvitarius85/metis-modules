<?php
declare(strict_types=1);

namespace Metis\Hermes;

use Metis\Core\Services\EntityResolverService;

/**
 * Backward-compatible Hermes adapter around the core EntityResolverService.
 */
final class EntityResolver {
    public function __construct(
        private readonly EntityResolverService $resolver
    ) {}

    /**
     * Resolve an entity subject for Hermes legacy consumers.
     *
     * @return array<string, mixed>
     */
    public function resolve( string $subject, string $entity_hint = 'auto' ): array {
        $subject = trim( $subject );
        if ( $subject === '' ) {
            return [ 'ok' => false, 'error' => 'No subject provided.' ];
        }

        $types = $this->typesToSearch( $entity_hint );
        $ambiguousCandidates = [];
        $errors = [];

        foreach ( $types as $type ) {
            $result = $this->resolver->resolve( $subject, $type );
            $payload = $result->toArray();
            $legacyType = $this->toLegacyType( $type );

            if ( (string) ( $payload['status'] ?? '' ) === 'resolved' ) {
                $match = (array) ( $payload['match'] ?? [] );
                $record = (array) ( $match['metadata']['record'] ?? [] );
                return [
                    'ok' => true,
                    'entity_type' => $legacyType,
                    'id' => (int) ( $match['id'] ?? 0 ),
                    'record' => $record,
                ];
            }

            if ( (string) ( $payload['status'] ?? '' ) === 'ambiguous' ) {
                $candidates = (array) ( $payload['candidates'] ?? [] );
                $match = (array) ( $payload['match'] ?? [] );

                if ( count( $candidates ) === 1 && ! empty( $match ) ) {
                    $ambiguousCandidates[] = $this->candidateFromMatch( $legacyType, $match );
                }

                foreach ( $candidates as $candidate ) {
                    if ( ! is_array( $candidate ) ) {
                        continue;
                    }

                    $ambiguousCandidates[] = [
                        'entity_type' => $legacyType,
                        'id' => (int) ( $candidate['id'] ?? 0 ),
                        'name' => (string) ( $candidate['name'] ?? '' ),
                        'email' => (string) ( $candidate['email'] ?? '' ),
                    ];
                }
            }

            $errors[] = (string) ( $payload['message'] ?? '' );
        }

        if ( $ambiguousCandidates !== [] ) {
            $ambiguousCandidates = $this->dedupeCandidates( $ambiguousCandidates );
            return [
                'ok' => false,
                'multiple' => true,
                'error' => sprintf( 'Multiple entities matched "%s". Please be more specific.', $subject ),
                'candidates' => $ambiguousCandidates,
            ];
        }

        return [
            'ok' => false,
            'error' => sprintf(
                'No entity found matching "%s". %s',
                $subject,
                $this->firstMessage( $errors )
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveAs( string $entity_type, string $subject ): array {
        return $this->resolve( $subject, $entity_type );
    }

    /**
     * @return array<int, string>
     */
    private function typesToSearch( string $entity_hint ): array {
        $entity_hint = strtolower( trim( $entity_hint ) );

        if ( $entity_hint === 'person' ) {
            return [ 'user' ];
        }

        if ( in_array( $entity_hint, [ 'user', 'contact', 'donor' ], true ) ) {
            return [ $entity_hint ];
        }

        return [ 'user', 'contact', 'donor' ];
    }

    private function toLegacyType( string $type ): string {
        return $type === 'user' ? 'person' : $type;
    }

    /**
     * @param array<int, string> $messages
     */
    private function firstMessage( array $messages ): string {
        foreach ( $messages as $message ) {
            $message = trim( $message );
            if ( $message !== '' ) {
                return $message;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $match
     * @return array<string,mixed>
     */
    private function candidateFromMatch( string $legacyType, array $match ): array {
        return [
            'entity_type' => $legacyType,
            'id' => (int) ( $match['id'] ?? 0 ),
            'name' => (string) ( $match['name'] ?? '' ),
            'email' => (string) ( $match['email'] ?? '' ),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private function dedupeCandidates( array $candidates ): array {
        $deduped = [];
        foreach ( $candidates as $candidate ) {
            $key = strtolower( trim( (string) ( $candidate['entity_type'] ?? '' ) ) ) . '|'
                . (int) ( $candidate['id'] ?? 0 ) . '|'
                . strtolower( trim( (string) ( $candidate['email'] ?? '' ) ) );
            $deduped[ $key ] = $candidate;
        }

        return array_values( $deduped );
    }
}
