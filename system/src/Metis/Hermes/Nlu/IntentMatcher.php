<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

final class IntentMatcher {
    public function __construct(
        private readonly LanguagePackLoader $packs,
        private readonly PhraseMatcher $phrases,
        private readonly EntityExtractor $extractor,
        private readonly CommandValidator $validator
    ) {}

    /**
     * @param array<string,array<string,mixed>> $commandDefinitions
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function analyzeCommandFragment( string $raw, string $normalized, array $commandDefinitions, array $context, string $locale = 'en-US' ): array {
        $extracted = $this->extractor->extract( $raw, $normalized, $locale );
        $phraseMatches = $this->phrases->match( $normalized, $locale );
        $pack = $this->packs->load( $locale );
        $actionTerms = $this->detectActions( $normalized, $pack );

        $candidates = [];
        foreach ( $commandDefinitions as $intent => $command ) {
            $score = 0.0;
            $matchedPhrases = [];

            foreach ( (array) ( $command['phrases'] ?? [] ) as $phrase ) {
                $phrase = strtolower( trim( (string) $phrase ) );
                if ( $phrase === '' ) {
                    continue;
                }

                if ( str_contains( $normalized, $phrase ) ) {
                    $score += 0.82;
                    $matchedPhrases[] = $phrase;
                }
            }

            foreach ( (array) ( $command['keywords'] ?? [] ) as $keyword ) {
                $keyword = strtolower( trim( (string) $keyword ) );
                if ( $keyword !== '' && preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/', $normalized ) === 1 ) {
                    $score += 0.12;
                }
            }

            foreach ( $phraseMatches as $match ) {
                $phrase = (string) ( $match['phrase'] ?? '' );
                $action = (string) ( $match['action'] ?? '' );
                $entity = (string) ( $match['entity'] ?? '' );
                if ( $phrase !== '' && str_contains( $normalized, $phrase ) ) {
                    if ( $this->commandSupportsCanonicalAction( $intent, $action ) ) {
                        $score += (float) ( $match['weight'] ?? 0.9 );
                        $matchedPhrases[] = $phrase;
                    }
                    if ( $this->commandSupportsEntity( $intent, $entity ) ) {
                        $score += 0.18;
                    }
                }
            }

            foreach ( $actionTerms as $action ) {
                if ( $this->commandSupportsCanonicalAction( $intent, $action ) ) {
                    $score += 0.2;
                }
            }

            if ( ! empty( $command['expects_entity'] ) && ( $extracted['emails'] !== [] || $extracted['names'] !== [] ) ) {
                $score += 0.18;
            }
            if ( ! empty( $context['references'] ) && ! empty( $command['supports_context'] ) ) {
                $score += 0.15;
            }
            if ( ! empty( $context['recent_entity'] ) && ! empty( $command['supports_context'] ) ) {
                $score += 0.08;
            }

            $payload = $this->buildPayload( $intent, $normalized, $extracted, $context );
            $score -= $this->validator->readinessPenalty( $intent, $command, $payload );
            if ( $score <= 0.0 ) {
                continue;
            }

            $candidates[] = [
                'intent' => $intent,
                'command' => $command,
                'payload' => $payload,
                'confidence' => min( 1.0, round( $score, 2 ) ),
                'matched_phrases' => array_values( array_unique( $matchedPhrases ) ),
            ];
        }

        usort(
            $candidates,
            static fn ( array $left, array $right ): int => (float) ( $right['confidence'] ?? 0.0 ) <=> (float) ( $left['confidence'] ?? 0.0 )
        );

        return [
            'candidates' => $candidates,
            'phrase_matches' => $phraseMatches,
            'extracted' => $extracted,
            'action_terms' => $actionTerms,
            'context_ambiguous' => ! empty( $context['ambiguous'] ),
        ];
    }

    /**
     * @param array<string,mixed> $pack
     * @return array<int,string>
     */
    private function detectActions( string $normalized, array $pack ): array {
        $actions = [];
        foreach ( (array) ( $pack['actions'] ?? [] ) as $canonical => $aliases ) {
            foreach ( (array) $aliases as $alias ) {
                $alias = strtolower( trim( (string) $alias ) );
                if ( $alias !== '' && preg_match( '/\b' . preg_quote( $alias, '/' ) . '\b/', $normalized ) === 1 ) {
                    $actions[] = (string) $canonical;
                    break;
                }
            }
        }

        return array_values( array_unique( $actions ) );
    }

    private function commandSupportsCanonicalAction( string $intent, string $canonicalAction ): bool {
        return match ( $canonicalAction ) {
            'search', 'list', 'get' => str_contains( $intent, 'lookup' ) || str_contains( $intent, 'get_' ) || str_starts_with( $intent, 'list_' ),
            'create' => str_starts_with( $intent, 'create_' ) || str_ends_with( $intent, '_create' ) || $intent === 'workspace_user_create',
            'update' => str_starts_with( $intent, 'update_' ) || str_ends_with( $intent, '_update' ) || str_contains( $intent, 'manage_' ),
            'delete' => str_contains( $intent, 'delete' ) || str_contains( $intent, 'archive' ) || str_contains( $intent, 'remove' ),
            'export' => str_contains( $intent, 'export' ),
            'count' => str_starts_with( $intent, 'list_' ) || str_contains( $intent, 'query_' ),
            default => false,
        };
    }

    private function commandSupportsEntity( string $intent, string $entity ): bool {
        if ( $entity === '' ) {
            return false;
        }

        return match ( $entity ) {
            'donor', 'donation', 'contact', 'person' => in_array( $intent, [ 'lookup_profile', 'get_entity_attribute', 'query_giving_summary' ], true ),
            'event' => $intent === 'resolve_help_issue',
            default => false,
        };
    }

    /**
     * @param array<string,mixed> $extracted
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildPayload( string $intent, string $normalized, array $extracted, array $context ): array {
        $payload = [];
        $subject = '';
        if ( ! empty( $extracted['emails'][0] ) ) {
            $subject = strtolower( trim( (string) $extracted['emails'][0] ) );
            $payload['email'] = $subject;
        } elseif ( ! empty( $extracted['names'][0] ) ) {
            $subject = trim( (string) $extracted['names'][0] );
        } elseif ( ! empty( $context['recent_entity']['subject'] ) ) {
            $subject = trim( (string) $context['recent_entity']['subject'] );
        }

        if ( $subject !== '' ) {
            $payload['subject'] = $subject;
        }

        if ( $intent === 'lookup_profile' ) {
            $payload['profile_request'] = [
                'subject' => $subject,
                'entity_hint' => $this->inferEntityHint( $normalized ),
            ];
        }

        if ( $intent === 'get_entity_attribute' ) {
            $payload['attribute_request'] = [
                'subject' => $subject,
                'entity_hint' => $this->inferEntityHint( $normalized ),
            ];
        }

        if ( $intent === 'resolve_help_issue' ) {
            $payload['user_message'] = $normalized;
            $payload['current_route'] = '';
            $payload['current_module'] = '';
            $payload['session_context'] = [ 'references' => array_values( (array) ( $context['references'] ?? [] ) ) ];
        }

        return $payload;
    }

    private function inferEntityHint( string $normalized ): string {
        if ( preg_match( '/\b(donor|donation|gift|giver|supporter)\b/', $normalized ) === 1 ) {
            return 'donor';
        }
        if ( preg_match( '/\b(contact|household|individual)\b/', $normalized ) === 1 ) {
            return 'contact';
        }
        if ( preg_match( '/\b(user|person|profile)\b/', $normalized ) === 1 ) {
            return 'person';
        }

        return 'auto';
    }
}
