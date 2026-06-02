<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesDisambiguationEngine {
    private const DISAMBIGUATION_TTL_SECONDS = 900;

    public function __construct(
        private readonly HermesMemoryStore $memory
    ) {}

    public function continueIfApplicable( string $query, array $session ): ?array {
        $sessionCode = trim( (string) ( $session['session_code'] ?? '' ) );
        if ( $sessionCode === '' ) {
            return null;
        }

        $stored = $this->memory->recallPendingDisambiguation( $sessionCode );
        $contents = (array) ( $stored['contents'] ?? [] );
        if ( $contents === [] ) {
            return null;
        }

        if ( $this->isExpired( (string) ( $stored['updated_at'] ?? '' ) ) ) {
            $this->memory->clearPendingDisambiguation( $sessionCode );

            return [
                'response' => [
                    'status' => 'workflow_expired',
                    'message' => 'The previous disambiguation prompt has expired. Would you like to start again?',
                    'response_type' => 'WorkflowExpiredPrompt',
                ],
                'intent' => [
                    'action' => 'entity_disambiguation_expired',
                    'top_level_intent' => 'LOOKUP',
                    'payload' => [],
                ],
                'parsed' => [
                    'selected_intent' => 'entity_disambiguation_expired',
                    'top_level_intent' => 'LOOKUP',
                ],
            ];
        }

        $choice = $this->resolveChoice( $query, array_values( (array) ( $contents['candidates'] ?? [] ) ) );
        if ( $choice === [] ) {
            return [
                'response' => [
                    'status' => 'disambiguation_required',
                    'message' => $this->promptForCandidates( array_values( (array) ( $contents['candidates'] ?? [] ) ) ),
                    'response_type' => 'Disambiguation',
                    'candidates' => array_values( (array) ( $contents['candidates'] ?? [] ) ),
                ],
                'intent' => [
                    'action' => 'entity_disambiguation',
                    'top_level_intent' => 'LOOKUP',
                    'payload' => [],
                ],
                'parsed' => [
                    'selected_intent' => 'entity_disambiguation',
                    'top_level_intent' => 'LOOKUP',
                ],
            ];
        }

        $request = (array) ( $contents['attribute_request'] ?? [] );
        $request['subject'] = $this->choiceSubject( $choice );
        if ( trim( (string) ( $request['entity_hint'] ?? '' ) ) === '' ) {
            $request['entity_hint'] = (string) ( $choice['entity_type'] ?? 'auto' );
        }

        $this->memory->clearPendingDisambiguation( $sessionCode );

        return [
            'kind' => 'entity_attribute',
            'attribute_request' => $request,
            'query' => (string) ( $contents['query'] ?? '' ),
            'selected_candidate' => $choice,
        ];
    }

    public function rememberIfApplicable( array $session, array $processed, array $response ): void {
        $sessionCode = trim( (string) ( $session['session_code'] ?? '' ) );
        if ( $sessionCode === '' ) {
            return;
        }

        if ( (string) ( $response['response_type'] ?? '' ) !== 'Disambiguation' ) {
            return;
        }

        $attributeRequest = (array) ( $processed['intent']['payload']['attribute_request'] ?? [] );
        $candidates = array_values( array_filter(
            (array) ( $response['candidates'] ?? [] ),
            static fn ( mixed $candidate ): bool => is_array( $candidate )
        ) );
        if ( $attributeRequest === [] || $candidates === [] ) {
            return;
        }

        $this->memory->rememberPendingDisambiguation( $sessionCode, [
            'kind' => 'entity_attribute',
            'query' => (string) ( $processed['parsed']['normalized_input'] ?? '' ),
            'attribute_request' => $attributeRequest,
            'candidates' => $candidates,
        ] );
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function resolveChoice( string $query, array $candidates ): array {
        $normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $query ) ?? $query ) );
        if ( $normalized === '' ) {
            return [];
        }

        if ( ctype_digit( $normalized ) ) {
            $index = (int) $normalized - 1;
            return is_array( $candidates[ $index ] ?? null ) ? (array) $candidates[ $index ] : [];
        }

        foreach ( $candidates as $candidate ) {
            $name = strtolower( trim( (string) ( $candidate['name'] ?? '' ) ) );
            $email = strtolower( trim( (string) ( $candidate['email'] ?? '' ) ) );
            if ( $name !== '' && $normalized === $name ) {
                return $candidate;
            }
            if ( $email !== '' && $normalized === $email ) {
                return $candidate;
            }
        }

        foreach ( $candidates as $candidate ) {
            $name = strtolower( trim( (string) ( $candidate['name'] ?? '' ) ) );
            if ( $name !== '' && str_contains( $name, $normalized ) ) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     */
    private function promptForCandidates( array $candidates ): string {
        $lines = [ 'I found multiple matches:' ];
        foreach ( $candidates as $index => $candidate ) {
            $name = trim( (string) ( $candidate['name'] ?? '' ) );
            $email = trim( (string) ( $candidate['email'] ?? '' ) );
            $suffix = $email !== '' ? ' (' . $email . ')' : '';
            $lines[] = sprintf( '%d. %s%s', $index + 1, $name !== '' ? $name : 'Unknown', $suffix );
        }
        $lines[] = 'Which person would you like?';

        return implode( "\n", $lines );
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function choiceSubject( array $candidate ): string {
        $name = trim( (string) ( $candidate['name'] ?? '' ) );
        if ( $name !== '' ) {
            return $name;
        }

        return trim( (string) ( $candidate['email'] ?? '' ) );
    }

    private function isExpired( string $updatedAt ): bool {
        if ( $updatedAt === '' ) {
            return false;
        }

        $timestamp = strtotime( $updatedAt );
        if ( $timestamp === false ) {
            return false;
        }

        return $timestamp < ( time() - self::DISAMBIGUATION_TTL_SECONDS );
    }
}
