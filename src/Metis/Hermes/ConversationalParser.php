<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class ConversationalParser {
    private const FILLER_PATTERNS = [
        '/\b(hey|hi|hello)\b/i',
        '/\b(can you|could you|would you|please|for me)\b/i',
        '/\b(i need you to|i want you to|help me)\b/i',
    ];

    private const CONTEXT_PATTERNS = [
        'that user',
        'last one',
        'do it again',
        'that module',
        'that file',
        'that job',
    ];

    public function __construct(
        private readonly HermesCommandRegistry $commands,
        private readonly ?EntityResolver $entityResolver = null,
        private readonly ?HermesMemoryStore $memory = null,
        private readonly ?HermesIntentParser $legacy = null
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function parse( string $input, string $session_code = '' ): array {
        $normalized = $this->normalizeInput( $input );
        $fragments = $this->detectFragments( $normalized );
        $entities = $this->preResolveEntities( $input, $session_code );
        $context = $this->resolveContext( $normalized, $session_code );
        $matches = [];

        foreach ( $fragments as $fragment ) {
            $matches[] = $this->rankFragment( $fragment, $entities, $context );
        }

        $requiresClarification = false;
        $clarifications = [];
        $intents = [];
        $alternatives = [];
        $plan = [];
        $maxConfidence = 0.0;

        foreach ( $matches as $index => $match ) {
            $selected = is_array( $match['selected'] ?? null ) ? $match['selected'] : [];
            if ( $selected === [] ) {
                $requiresClarification = true;
                $clarifications[] = sprintf( 'I could not map step %d to a registered tool.', $index + 1 );
                continue;
            }

            $command = (array) ( $selected['command'] ?? [] );
            $confidence = (float) ( $selected['confidence'] ?? 0.0 );
            $maxConfidence = max( $maxConfidence, $confidence );

            if ( ! empty( $match['ambiguous'] ) ) {
                $requiresClarification = true;
                $clarifications[] = (string) ( $match['clarification'] ?? 'Multiple intents matched.' );
            }

            if ( (string) ( $selected['confidence_label'] ?? 'low' ) !== 'high' ) {
                $requiresClarification = true;
                $clarifications[] = sprintf(
                    'Step %d is not certain enough to execute. Confidence is %s.',
                    $index + 1,
                    (string) ( $selected['confidence_label'] ?? 'low' )
                );
            }

            $intents[] = [
                'fragment' => (string) ( $selected['fragment'] ?? '' ),
                'intent' => (string) ( $selected['intent'] ?? '' ),
                'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                'confidence' => $confidence,
                'confidence_label' => (string) ( $selected['confidence_label'] ?? 'low' ),
                'payload' => (array) ( $selected['payload'] ?? [] ),
            ];

            $alternatives[] = array_values( array_map(
                static fn ( array $candidate ): array => [
                    'intent' => (string) ( $candidate['intent'] ?? '' ),
                    'confidence' => (float) ( $candidate['confidence'] ?? 0.0 ),
                ],
                (array) ( $match['alternatives'] ?? [] )
            ) );

            $plan[] = [
                'step' => $index + 1,
                'fragment' => (string) ( $selected['fragment'] ?? '' ),
                'intent' => (string) ( $selected['intent'] ?? '' ),
                'tool_key' => (string) ( $command['tool_key'] ?? '' ),
                'payload' => (array) ( $selected['payload'] ?? [] ),
                'requires_approval' => ! empty( $command['requires_approval'] ),
            ];
        }

        $selectedIntent = $intents[0]['intent'] ?? 'unknown';

        return [
            'normalized_input' => $normalized,
            'intents' => $intents,
            'selected_intent' => $selectedIntent,
            'entities' => $entities,
            'confidence_score' => $maxConfidence,
            'confidence_label' => $this->confidenceLabel( $maxConfidence ),
            'requires_clarification' => $requiresClarification,
            'clarification_prompt' => $requiresClarification ? implode( ' ', array_values( array_unique( array_filter( $clarifications ) ) ) ) : '',
            'execution_plan' => count( $plan ) > 1 ? $plan : ( $plan !== [] ? $plan : [] ),
            'alternative_intents' => $alternatives,
            'context' => $context,
        ];
    }

    private function normalizeInput( string $input ): string {
        $normalized = strtolower( trim( $input ) );
        foreach ( self::FILLER_PATTERNS as $pattern ) {
            $normalized = preg_replace( $pattern, ' ', $normalized ) ?? $normalized;
        }

        $normalized = str_replace(
            [ '?', '!', ';', ':', "\n", "\r", "\t" ],
            ' ',
            $normalized
        );
        $normalized = str_replace(
            [ 'usr ', 'uesr ', 'diagnotics', 'restroe ', 'rol back', 'enbale ', 'disbale ' ],
            [ 'user ', 'user ', 'diagnostics', 'restore ', 'rollback', 'enable ', 'disable ' ],
            $normalized
        );

        return trim( preg_replace( '/\s+/', ' ', $normalized ) ?? $normalized );
    }

    /**
     * @return array<int,string>
     */
    private function detectFragments( string $normalized ): array {
        if ( $normalized === '' ) {
            return [];
        }

        $parts = preg_split( '/\s+(?:and then|then|and)\s+|,\s*/', $normalized ) ?: [];
        $parts = array_values( array_filter( array_map(
            static fn ( string $part ): string => trim( $part ),
            $parts
        ) ) );

        return $parts === [] ? [ $normalized ] : $parts;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function preResolveEntities( string $raw, string $session_code ): array {
        $subjects = [];
        preg_match_all( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $raw, $emails );
        preg_match_all( '/\b(?:id|pid|cid|did|job)\s*[:#-]?\s*([A-Z0-9_-]{3,})\b/i', $raw, $ids );
        preg_match_all( '/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\b/', $raw, $names );

        foreach ( array_merge( $emails[0] ?? [], $ids[1] ?? [], $names[1] ?? [] ) as $candidate ) {
            $candidate = trim( (string) $candidate );
            if ( $candidate !== '' ) {
                $subjects[ $candidate ] = $candidate;
            }
        }

        $resolved = [];
        foreach ( array_values( $subjects ) as $subject ) {
            $entry = [
                'subject' => $subject,
                'resolution' => null,
            ];

            if ( $this->entityResolver !== null ) {
                $resolution = $this->entityResolver->resolve( $subject, 'auto' );
                $entry['resolution'] = $resolution;
            }

            $resolved[] = $entry;
        }

        if ( $resolved === [] && $session_code !== '' && $this->memory !== null ) {
            foreach ( $this->memory->recall( 'conversation:' . $session_code, 2 ) as $memoryRow ) {
                $resolved[] = [
                    'subject' => (string) ( $memoryRow['scope_key'] ?? '' ),
                    'resolution' => null,
                    'source' => 'session_memory',
                ];
            }
        }

        return $resolved;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveContext( string $normalized, string $session_code ): array {
        $context = [
            'ambiguous' => false,
            'references' => [],
        ];

        foreach ( self::CONTEXT_PATTERNS as $pattern ) {
            if ( str_contains( $normalized, $pattern ) ) {
                $context['references'][] = $pattern;
            }
        }

        if ( $context['references'] !== [] ) {
            $memory = $this->memory?->recall( 'conversation:' . $session_code, 3 ) ?? [];
            if ( $memory === [] ) {
                $context['ambiguous'] = true;
            } else {
                $context['memory'] = $memory;
            }
        }

        return $context;
    }

    /**
     * @param array<int,array<string,mixed>> $entities
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function rankFragment( string $fragment, array $entities, array $context ): array {
        $candidates = [];

        foreach ( $this->commands->definitions() as $command_name => $command ) {
            $score = $this->scoreCommand( $fragment, $command, $entities, $context );
            if ( $score <= 0.0 ) {
                continue;
            }

            $payload = $this->extractPayload( $fragment, $command_name, $entities, $context );
            $candidates[] = [
                'intent' => $command_name,
                'fragment' => $fragment,
                'command' => $command,
                'payload' => $payload,
                'confidence' => min( 1.0, $score ),
                'confidence_label' => $this->confidenceLabel( $score ),
            ];
        }

        if ( $candidates === [] && $this->legacy !== null ) {
            $fallback = $this->legacy->parse( $fragment );
            $command = is_array( $fallback['command'] ?? null ) ? $fallback['command'] : [];
            if ( $command !== [] ) {
                $candidates[] = [
                    'intent' => (string) ( $fallback['action'] ?? 'unknown' ),
                    'fragment' => $fragment,
                    'command' => $command,
                    'payload' => (array) ( $fallback['payload'] ?? [] ),
                    'confidence' => (float) ( $fallback['confidence'] ?? 0.0 ),
                    'confidence_label' => $this->confidenceLabel( (float) ( $fallback['confidence'] ?? 0.0 ) ),
                ];
            }
        }

        usort( $candidates, static fn ( array $a, array $b ): int => ( $b['confidence'] <=> $a['confidence'] ) );

        $selected = $candidates[0] ?? [];
        $second = $candidates[1] ?? [];
        $ambiguous = $selected !== [] && $second !== [] && abs( (float) $selected['confidence'] - (float) $second['confidence'] ) < 0.1;

        return [
            'selected' => $selected,
            'alternatives' => array_slice( $candidates, 1, 3 ),
            'ambiguous' => $ambiguous || ! empty( $context['ambiguous'] ),
            'clarification' => $ambiguous
                ? sprintf( 'Multiple intents match "%s".', $fragment )
                : ( ! empty( $context['ambiguous'] ) ? 'Context reference could not be resolved safely.' : '' ),
        ];
    }

    /**
     * @param array<string,mixed> $command
     * @param array<int,array<string,mixed>> $entities
     * @param array<string,mixed> $context
     */
    private function scoreCommand( string $fragment, array $command, array $entities, array $context ): float {
        $score = 0.0;
        $patterns = array_values( array_filter( array_map( 'strval', (array) ( $command['phrases'] ?? [] ) ) ) );

        foreach ( $patterns as $pattern ) {
            if ( $pattern !== '' && str_contains( $fragment, strtolower( $pattern ) ) ) {
                $score += 0.7;
            }
        }

        foreach ( (array) ( $command['keywords'] ?? [] ) as $keyword ) {
            $keyword = strtolower( trim( (string) $keyword ) );
            if ( $keyword !== '' && str_contains( $fragment, $keyword ) ) {
                $score += 0.15;
            }
        }

        if ( $entities !== [] && ! empty( $command['expects_entity'] ) ) {
            $score += 0.1;
        }

        if ( ! empty( $context['references'] ) && ! empty( $command['supports_context'] ) ) {
            $score += 0.1;
        }

        return $score;
    }

    /**
     * @param array<int,array<string,mixed>> $entities
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function extractPayload( string $fragment, string $command_name, array $entities, array $context ): array {
        $payload = [];
        $entity_subject = (string) ( $entities[0]['subject'] ?? '' );
        if ( $entity_subject !== '' ) {
            $payload['subject'] = $entity_subject;
        } elseif ( ! empty( $context['memory'][0]['contents']['query'] ) ) {
            $payload['subject'] = (string) $context['memory'][0]['contents']['query'];
        }

        if ( preg_match( '/\b([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})\b/i', $fragment, $match ) ) {
            $payload['email'] = strtolower( $match[1] );
        }

        if ( preg_match( '/\b(module|file|job)\s+([a-z0-9._-]+)\b/i', $fragment, $match ) ) {
            $payload[ strtolower( $match[1] ) . '_key' ] = $match[2];
        }

        if ( preg_match( '/\b(role|roles?)\s+([a-z0-9_, -]+)\b/i', $fragment, $match ) ) {
            $roles = preg_split( '/\s*,\s*|\s+and\s+/', strtolower( trim( $match[2] ) ) ) ?: [];
            $payload['roles'] = array_values( array_filter( array_map( static fn ( string $role ): string => trim( $role ), $roles ) ) );
        }

        if ( str_starts_with( $command_name, 'list_' ) || str_starts_with( $command_name, 'get_' ) ) {
            $payload['query'] = $fragment;
        }

        return $payload;
    }

    private function confidenceLabel( float $score ): string {
        if ( $score >= 0.9 ) {
            return 'high';
        }
        if ( $score >= 0.6 ) {
            return 'medium';
        }
        if ( $score > 0.0 ) {
            return 'low';
        }

        return 'none';
    }
}
