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
        'his',
        'her',
        'hers',
        'him',
        'they',
        'them',
        'their',
        'it',
        'that user',
        'that donor',
        'that contact',
        'that campaign',
        'last one',
        'do it again',
        'that module',
        'that file',
        'that job',
    ];

    private const HELP_STYLE_PATTERN = '/\b(how do i|how can i|where do i|show me how|walk me through|why can\'?t i|i can\'?t|i cant|i don\'?t see|i dont see|won\'t|will not|button does nothing|search shows no results|permission denied)\b/';
    private const INSTRUCTIONAL_PATTERN = '/\b(how do i|how can i|where do i|show me how|walk me through)\b/';
    private const FRAGMENT_START_PATTERN = '(?:disable|enable|reactivate|offboard|create|add|update|edit|change|assign|remove|revoke|list|show|get|find|look up|lookup|clear|rebuild|reload|run|scan|check|recover|restore|rollback|install|export|import|deduplicate|dedupe|cancel|retry|audit|verify|rotate|who is|what is|who has|how do i|how can i|where do i|why can\'?t i|i can\'?t|i cant|i don\'?t see|i dont see|search shows no results|button does nothing)';

    public function __construct(
        private readonly HermesCommandRegistry $commands,
        private readonly ?EntityResolver $entityResolver = null,
        private readonly ?HermesMemoryStore $memory = null,
        private readonly ?HermesIntentParser $legacy = null,
        private readonly ?HermesIntentRegistry $intentRegistry = null
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
        $topLevelIntent = $this->intentRegistry?->classifyCommand( $selectedIntent, $normalized )
            ?? $this->legacy?->parse( $normalized )['top_level_intent']
            ?? 'LOOKUP';

        return [
            'normalized_input' => $normalized,
            'intents' => $intents,
            'selected_intent' => $selectedIntent,
            'top_level_intent' => $topLevelIntent,
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
            [ 'usr ', 'uesr ', 'diagnotics', 'diagnotic', 'restroe ', 'rol back', 'enbale ', 'disbale ' ],
            [ 'user ', 'user ', 'diagnostics', 'diagnostics', 'restore ', 'rollback', 'enable ', 'disable ' ],
            $normalized
        );
        $normalized = preg_replace( '/\bdiagnostic\b/', 'diagnostics', $normalized ) ?? $normalized;

        return trim( preg_replace( '/\s+/', ' ', $normalized ) ?? $normalized );
    }

    /**
     * @return array<int,string>
     */
    private function detectFragments( string $normalized ): array {
        if ( $normalized === '' ) {
            return [];
        }

        $parts = preg_split( '/\s+(?:and then|then)\s+/', $normalized ) ?: [];
        $fragments = [];

        foreach ( $parts as $part ) {
            $commaParts = preg_split( '/,\s*(?=' . self::FRAGMENT_START_PATTERN . ')/', $part ) ?: [];
            foreach ( $commaParts as $commaPart ) {
                $andParts = preg_split( '/\s+and\s+(?=' . self::FRAGMENT_START_PATTERN . ')/', $commaPart ) ?: [];
                foreach ( $andParts as $candidate ) {
                    $candidate = trim( $candidate );
                    if ( $candidate !== '' ) {
                        $fragments[] = $candidate;
                    }
                }
            }
        }

        return $fragments === [] ? [ $normalized ] : $fragments;
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
            if ( preg_match( '/\b' . preg_quote( $pattern, '/' ) . '\b/', $normalized ) === 1 ) {
                $context['references'][] = $pattern;
            }
        }

        if ( $context['references'] !== [] ) {
            $recentEntity = $this->memory?->recallRecentEntity( $session_code ) ?? [];
            if ( $recentEntity !== [] ) {
                $context['recent_entity'] = $recentEntity;
            }

            $memory = $this->memory?->recallConversation( $session_code ) ?? [];
            if ( $memory !== [] ) {
                $context['memory'] = $memory;
            }

            if ( empty( $context['recent_entity'] ) && empty( $context['memory'] ) ) {
                $context['ambiguous'] = true;
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

        if ( $this->legacy !== null ) {
            $fallback = $this->legacy->parse( $fragment );
            $command = is_array( $fallback['command'] ?? null ) ? $fallback['command'] : [];
            if ( $command !== [] ) {
                $fallbackIntent = strtolower( trim( (string) ( $fallback['action'] ?? 'unknown' ) ) );
                if ( $fallbackIntent === 'lookup_profile' && ! $this->isProfileLookupFragment( $fragment ) ) {
                    $command = [];
                }
            }
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

        if ( $candidates !== [] ) {
            $candidates = array_values( array_map(
                fn ( array $candidate ): array => $this->adjustCandidateConfidence( $fragment, $candidate ),
                $candidates
            ) );
        }

        if ( $candidates !== [] ) {
            $deduped = [];
            foreach ( $candidates as $candidate ) {
                $intentKey = strtolower( trim( (string) ( $candidate['intent'] ?? '' ) ) );
                if ( $intentKey === '' ) {
                    continue;
                }
                if ( ! isset( $deduped[ $intentKey ] ) || (float) $candidate['confidence'] > (float) $deduped[ $intentKey ]['confidence'] ) {
                    $deduped[ $intentKey ] = $candidate;
                }
            }
            $candidates = array_values( $deduped );
        }

        usort( $candidates, fn ( array $a, array $b ): int => $this->compareCandidates( $fragment, $a, $b ) );
        $candidates = $this->prioritizeHelpCandidate( $fragment, $candidates );

        $selected = $candidates[0] ?? [];
        $second = $candidates[1] ?? [];
        $sameTool = $selected !== [] && $second !== []
            && (string) ( $selected['command']['tool_key'] ?? '' ) !== ''
            && (string) ( $selected['command']['tool_key'] ?? '' ) === (string) ( $second['command']['tool_key'] ?? '' );
        $specificityGap = $selected !== [] && $second !== []
            ? abs( $this->candidateSpecificityScore( $fragment, $selected ) - $this->candidateSpecificityScore( $fragment, $second ) )
            : 0.0;
        $passwordScopeResolved = $selected !== [] && $second !== []
            && $this->hasExplicitPasswordScope( $fragment )
            && in_array( strtolower( trim( (string) ( $selected['intent'] ?? '' ) ) ), [ 'user_password_reset', 'workspace_user_password_reset' ], true )
            && in_array( strtolower( trim( (string) ( $second['intent'] ?? '' ) ) ), [ 'user_password_reset', 'workspace_user_password_reset' ], true );
        $ambiguous = ! $sameTool
            && ! $passwordScopeResolved
            && $selected !== []
            && $second !== []
            && $specificityGap < 0.05
            && abs( (float) $selected['confidence'] - (float) $second['confidence'] ) < 0.1;

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
        $commandKey = strtolower( trim( (string) ( $command['key'] ?? '' ) ) );
        if ( $commandKey === 'lookup_profile' && ! $this->isProfileLookupFragment( $fragment ) ) {
            return 0.0;
        }
        if ( $commandKey === 'get_entity_attribute' && ! $this->isAttributeLookupFragment( $fragment ) ) {
            return 0.0;
        }
        $patterns = array_values( array_filter( array_map( 'strval', (array) ( $command['phrases'] ?? [] ) ) ) );

        foreach ( $patterns as $pattern ) {
            $score += $this->patternMatchScore( $fragment, $pattern );
        }

        if ( ! empty( $patterns ) ) {
            $score += $this->bestPatternSpecificityBonus( $fragment, $patterns );
        }

        if ( $this->isGenericResetPasswordFragment( $fragment ) && in_array( $commandKey, [ 'user_password_reset', 'workspace_user_password_reset' ], true ) ) {
            $score -= 0.1;
            if ( $commandKey === 'workspace_user_password_reset' && ! str_contains( $fragment, 'workspace' ) && ! str_contains( $fragment, 'google' ) ) {
                $score -= 0.05;
            }
        }

        foreach ( (array) ( $command['keywords'] ?? [] ) as $keyword ) {
            $keyword = strtolower( trim( (string) $keyword ) );
            if ( $keyword !== '' && str_contains( $fragment, $keyword ) ) {
                $score += 0.15;
            }
        }

        if ( $patterns === [] && ! empty( $command['keywords'] ) ) {
            $firstKeyword = strtolower( trim( (string) ( (array) $command['keywords'] )[0] ?? '' ) );
            if ( $firstKeyword !== '' && str_starts_with( $fragment, $firstKeyword . ' ' ) ) {
                $score += 0.35;
            }
        }

        if ( $entities !== [] && ! empty( $command['expects_entity'] ) ) {
            $score += 0.2;
        }

        if ( ! empty( $context['references'] ) && ! empty( $command['supports_context'] ) ) {
            $score += 0.25;
        }

        if ( ! empty( $context['recent_entity'] ) && ! empty( $command['expects_entity'] ) ) {
            $score += 0.15;
        }

        if ( $commandKey === 'resolve_help_issue' && $this->isHelpStyleFragment( $fragment ) ) {
            $score += 0.75;
        }

        return $score;
    }

    private function isProfileLookupFragment( string $fragment ): bool {
        return (bool) preg_match(
            '/\b(who is|look up|lookup|find person|find contact|find donor|find user|profile for|person record|contact record|donor record)\b/',
            $fragment
        );
    }

    private function isAttributeLookupFragment( string $fragment ): bool {
        return (bool) preg_match(
            '/\b(what is|email for|phone for|address for|email address|phone number|mailing address)\b/',
            $fragment
        );
    }

    /**
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    private function compareCandidates( string $fragment, array $left, array $right ): int {
        $confidenceComparison = ( (float) ( $right['confidence'] ?? 0.0 ) <=> (float) ( $left['confidence'] ?? 0.0 ) );
        if ( $confidenceComparison !== 0 ) {
            return $confidenceComparison;
        }

        return $this->candidateSpecificityScore( $fragment, $right ) <=> $this->candidateSpecificityScore( $fragment, $left );
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function candidateSpecificityScore( string $fragment, array $candidate ): float {
        $command = (array) ( $candidate['command'] ?? [] );
        $patterns = array_values( array_filter( array_map( 'strval', (array) ( $command['phrases'] ?? [] ) ) ) );
        $score = 0.0;
        foreach ( $patterns as $pattern ) {
            $score = max(
                $score,
                $this->patternMatchScore( $fragment, $pattern )
                + min( 0.2, count( $this->meaningfulTokens( $pattern ) ) * 0.02 )
            );
        }

        return $score;
    }

    private function patternMatchScore( string $fragment, string $pattern ): float {
        $pattern = strtolower( trim( $pattern ) );
        if ( $pattern === '' ) {
            return 0.0;
        }

        if ( str_contains( $fragment, $pattern ) ) {
            return 0.7;
        }

        $patternTokens = $this->meaningfulTokens( $pattern );
        $fragmentTokens = $this->meaningfulTokens( $fragment );
        if ( $patternTokens === [] || $fragmentTokens === [] ) {
            return 0.0;
        }

        $cursor = 0;
        foreach ( $patternTokens as $token ) {
            $matched = false;
            while ( $cursor < count( $fragmentTokens ) ) {
                if ( $fragmentTokens[ $cursor ] === $token ) {
                    $matched = true;
                    $cursor++;
                    break;
                }
                $cursor++;
            }

            if ( ! $matched ) {
                return 0.0;
            }
        }

        return 0.55;
    }

    /**
     * @param array<int,string> $patterns
     */
    private function bestPatternSpecificityBonus( string $fragment, array $patterns ): float {
        $bonus = 0.0;
        foreach ( $patterns as $pattern ) {
            $matchScore = $this->patternMatchScore( $fragment, $pattern );
            if ( $matchScore <= 0.0 ) {
                continue;
            }

            $bonus = max( $bonus, min( 0.2, count( $this->meaningfulTokens( $pattern ) ) * 0.02 ) );
        }

        return $bonus;
    }

    /**
     * @return array<int,string>
     */
    private function meaningfulTokens( string $value ): array {
        $tokens = preg_split( '/[^a-z0-9]+/i', strtolower( $value ) ) ?: [];
        $ignored = [ 'a', 'an', 'the', 'that', 'this', 'these', 'those', 'his', 'her', 'hers', 'him', 'their', 'them', 'they', 'it', 'my', 'our', 'your' ];

        return array_values( array_filter(
            array_map( static fn ( string $token ): string => trim( $token ), $tokens ),
            static fn ( string $token ): bool => $token !== '' && ! in_array( $token, $ignored, true )
        ) );
    }

    private function isGenericResetPasswordFragment( string $fragment ): bool {
        if ( ! str_contains( $fragment, 'password' ) ) {
            return false;
        }

        if ( ! str_contains( $fragment, 'reset' ) && ! str_contains( $fragment, 'change' ) && ! str_contains( $fragment, 'set' ) ) {
            return false;
        }

        return ! str_contains( $fragment, 'workspace' )
            && ! str_contains( $fragment, 'google' )
            && ! str_contains( $fragment, 'metis' )
            && ! str_contains( $fragment, 'local' )
            && ! str_contains( $fragment, 'internal' );
    }

    private function hasExplicitPasswordScope( string $fragment ): bool {
        return str_contains( $fragment, 'workspace' )
            || str_contains( $fragment, 'google' )
            || str_contains( $fragment, 'metis' )
            || str_contains( $fragment, 'local' )
            || str_contains( $fragment, 'internal' );
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
        } elseif ( ! empty( $context['recent_entity']['subject'] ) ) {
            $payload['subject'] = (string) $context['recent_entity']['subject'];
        }

        if ( preg_match( '/\b([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})\b/i', $fragment, $match ) ) {
            $payload['email'] = strtolower( $match[1] );
        }

        if ( $command_name === 'manage_workspace_groups' && preg_match_all( '/\b([a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,})\b/i', $fragment, $matches ) ) {
            $payload['group_emails'] = array_values( array_unique( array_map(
                static fn ( string $email ): string => strtolower( trim( $email ) ),
                (array) ( $matches[1] ?? [] )
            ) ) );
            if ( (string) ( $payload['subject'] ?? '' ) === (string) ( $payload['email'] ?? '' ) ) {
                unset( $payload['subject'] );
            }
        }

        if (
            preg_match( '/\b(module|file|job)\s+([a-z0-9._-]+)\b/i', $fragment, $match )
            && $this->commandAcceptsKeyPayload( $command_name, strtolower( $match[1] ), strtolower( $match[2] ) )
        ) {
            $payload[ strtolower( $match[1] ) . '_key' ] = $match[2];
        }

        if ( $command_name === 'restore_file' ) {
            if ( preg_match( '/\b(run_[a-z0-9_-]+)\b/i', $fragment, $match ) || preg_match( '/\b(?:from|run)\s+([a-z0-9_-]{8,})\b/i', $fragment, $match ) ) {
                $payload['run_uuid'] = strtolower( trim( (string) ( $match[1] ?? '' ) ) );
            }

            if ( preg_match( '/["\']([^"\']+)["\']/', $fragment, $match ) ) {
                $payload['relative_path'] = trim( str_replace( '\\', '/', (string) ( $match[1] ?? '' ) ) );
            } elseif ( preg_match( '#\b(config/[^\s]+|storage/[^\s]+)#i', $fragment, $match ) ) {
                $payload['relative_path'] = trim( str_replace( '\\', '/', (string) ( $match[1] ?? '' ) ) );
            } elseif ( ! empty( $payload['file_key'] ) ) {
                $payload['relative_path'] = (string) $payload['file_key'];
            }
        }

        if ( $command_name === 'create_job' && preg_match( '/\btask\s+([a-z0-9_-]+)\b/i', $fragment, $match ) ) {
            $payload['task_slug'] = function_exists( 'metis_key_clean' )
                ? \metis_key_clean( (string) ( $match[1] ?? '' ) )
                : strtolower( preg_replace( '/[^a-z0-9_-]+/', '', (string) ( $match[1] ?? '' ) ) ?? '' );
            if ( (string) ( $payload['subject'] ?? '' ) === 'task' ) {
                unset( $payload['subject'] );
            }
        }

        if ( $command_name === 'link_drive_folder' ) {
            if ( preg_match( '#drive\.google\.com/drive/folders/([A-Za-z0-9_-]{10,})#', $fragment, $match ) ) {
                $payload['folder_id'] = trim( (string) ( $match[1] ?? '' ) );
            } elseif ( preg_match( '/\bfolder(?:\s+id)?[:=\s]+([A-Za-z0-9_-]{10,})\b/i', $fragment, $match ) ) {
                $payload['folder_id'] = trim( (string) ( $match[1] ?? '' ) );
            }
        }

        if ( preg_match( '/\broles?\s+([a-z0-9_-]+(?:\s*,\s*[a-z0-9_-]+)*(?:\s+and\s+[a-z0-9_-]+)*)\b(?=(?:\s+to\b|\s+for\b|$))/i', $fragment, $match ) ) {
            $roles = preg_split( '/\s*,\s*|\s+and\s+/', strtolower( trim( $match[1] ) ) ) ?: [];
            $payload['roles'] = array_values( array_filter( array_map( static fn ( string $role ): string => trim( $role ), $roles ) ) );
        }

        if ( ! isset( $payload['subject'] ) && preg_match( '/\b(?:for|to)\s+([a-z][a-z0-9._-]*(?:\s+[a-z][a-z0-9._-]*){0,2})\b(?=(?:\s+(?:with|using|via|in)\b|$))/i', $fragment, $match ) ) {
            $payload['subject'] = trim( $match[1] );
        }

        if ( str_starts_with( $command_name, 'list_' ) || str_starts_with( $command_name, 'get_' ) ) {
            $payload['query'] = $fragment;
        }

        if ( $command_name === 'resolve_help_issue' ) {
            $payload['user_message'] = $fragment;
            $payload['current_route'] = '';
            $payload['current_module'] = '';
            $payload['session_context'] = [
                'references' => array_values( (array) ( $context['references'] ?? [] ) ),
            ];
        }

        if ( in_array( $command_name, [ 'assign_role', 'manage_user_roles' ], true ) ) {
            $payload['mode'] = 'add';
        } elseif ( $command_name === 'remove_role' ) {
            $payload['mode'] = 'remove';
        } elseif ( $command_name === 'manage_workspace_groups' ) {
            $normalized = strtolower( $fragment );
            $payload['mode'] = str_contains( $normalized, 'remove' ) ? 'remove' : 'add';
        }

        return $this->normalizeContextualPayload( $command_name, $fragment, $payload, $context );
    }

    private function commandAcceptsKeyPayload( string $command_name, string $kind, string $value ): bool {
        if ( in_array( $value, [ 'diagnostic', 'diagnostics', 'health', 'status', 'scan', 'integrity', 'test' ], true ) ) {
            return false;
        }

        return match ( $kind ) {
            'module' => in_array( $command_name, [ 'recover_module', 'rollback_module', 'enable_module', 'disable_module', 'install_module', 'update_module' ], true ),
            'file' => $command_name === 'restore_file',
            'job' => in_array( $command_name, [ 'cancel_job', 'retry_job' ], true ),
            default => false,
        };
    }

    /**
     * @param array<string,mixed> $candidate
     * @return array<string,mixed>
     */
    private function adjustCandidateConfidence( string $fragment, array $candidate ): array {
        $intent = strtolower( trim( (string) ( $candidate['intent'] ?? '' ) ) );
        $command = (array) ( $candidate['command'] ?? [] );
        $payload = (array) ( $candidate['payload'] ?? [] );
        $score = (float) ( $candidate['confidence'] ?? 0.0 );
        $helpStyle = $this->isHelpStyleFragment( $fragment );
        $instructional = $this->isInstructionalFragment( $fragment );

        if ( $intent === 'resolve_help_issue' ) {
            if ( $helpStyle ) {
                $score = max( $score, $instructional ? 0.98 : 0.92 );
            }
        } elseif ( $helpStyle ) {
            $score *= ( ! empty( $command['requires_approval'] ) || empty( $command['read_only'] ) )
                ? ( $instructional ? 0.25 : 0.4 )
                : 0.6;
        }

        $score = $this->applyPayloadReadinessPenalty( $intent, $payload, $score );
        $candidate['confidence'] = min( 1.0, max( 0.0, $score ) );
        $candidate['confidence_label'] = $this->confidenceLabel( (float) $candidate['confidence'] );

        return $candidate;
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    private function prioritizeHelpCandidate( string $fragment, array $candidates ): array {
        if ( ! $this->isHelpStyleFragment( $fragment ) || $candidates === [] ) {
            return $candidates;
        }

        foreach ( $candidates as $index => $candidate ) {
            if ( strtolower( trim( (string) ( $candidate['intent'] ?? '' ) ) ) !== 'resolve_help_issue' ) {
                continue;
            }

            if ( $index === 0 ) {
                return $candidates;
            }

            $topScore = (float) ( $candidates[0]['confidence'] ?? 0.0 );
            $helpScore = (float) ( $candidate['confidence'] ?? 0.0 );
            if ( $helpScore < max( 0.75, $topScore - 0.15 ) ) {
                return $candidates;
            }

            unset( $candidates[ $index ] );
            array_unshift( $candidates, $candidate );
            return array_values( $candidates );
        }

        return $candidates;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function normalizeContextualPayload( string $command_name, string $fragment, array $payload, array $context ): array {
        $recentEntity = (array) ( $context['recent_entity'] ?? [] );
        $recentSubject = trim( (string) ( $recentEntity['subject'] ?? '' ) );

        if ( $recentSubject === '' || ! $this->hasContextReference( $fragment ) ) {
            return $payload;
        }

        if ( in_array( $command_name, [ 'lookup_profile', 'get_entity_attribute' ], true ) ) {
            if ( $command_name === 'lookup_profile' ) {
                $request = (array) ( $payload['profile_request'] ?? [] );
                $request['subject'] = $recentSubject;
                if ( trim( (string) ( $request['entity_hint'] ?? '' ) ) === '' ) {
                    $request['entity_hint'] = (string) ( $recentEntity['entity_hint'] ?? 'auto' );
                }
                $payload['profile_request'] = $request;
            } else {
                $request = (array) ( $payload['attribute_request'] ?? [] );
                $request['subject'] = $recentSubject;
                if ( trim( (string) ( $request['entity_hint'] ?? '' ) ) === '' ) {
                    $request['entity_hint'] = (string) ( $recentEntity['entity_hint'] ?? 'auto' );
                }
                $payload['attribute_request'] = $request;
            }

            return $payload;
        }

        $command = $this->commands->definition( $command_name );
        if ( empty( $command['supports_context'] ) ) {
            return $payload;
        }

        $payload['subject'] = $recentSubject;
        if ( ! isset( $payload['entity_hint'] ) || trim( (string) $payload['entity_hint'] ) === '' ) {
            $payload['entity_hint'] = (string) ( $recentEntity['entity_hint'] ?? 'auto' );
        }

        return $payload;
    }

    private function hasContextReference( string $fragment ): bool {
        $normalized = strtolower( trim( $fragment ) );
        foreach ( self::CONTEXT_PATTERNS as $pattern ) {
            if ( preg_match( '/\b' . preg_quote( $pattern, '/' ) . '\b/', $normalized ) === 1 ) {
                return true;
            }
        }

        return false;
    }

    private function applyPayloadReadinessPenalty( string $intent, array $payload, float $score ): float {
        $subject = trim( (string) ( $payload['subject'] ?? '' ) );
        $email = trim( (string) ( $payload['email'] ?? '' ) );
        $roles = array_values( array_filter( array_map( 'strval', (array) ( $payload['roles'] ?? [] ) ) ) );
        $moduleKey = trim( (string) ( $payload['module_key'] ?? '' ) );
        $fileKey = trim( (string) ( $payload['relative_path'] ?? $payload['file_key'] ?? '' ) );
        $jobKey = trim( (string) ( $payload['job_key'] ?? $payload['job_code'] ?? '' ) );
        $taskSlug = trim( (string) ( $payload['task_slug'] ?? '' ) );
        $groupEmails = array_values( array_filter( array_map( 'strval', (array) ( $payload['group_emails'] ?? [] ) ) ) );
        $runUuid = trim( (string) ( $payload['run_uuid'] ?? '' ) );

        return match ( true ) {
            in_array( $intent, [ 'create_user', 'update_user', 'disable_user', 'offboard_user', 'enable_user', 'get_user' ], true )
                && $subject === ''
                && $email === '' => $score * 0.55,
            in_array( $intent, [ 'assign_role', 'remove_role', 'manage_user_roles' ], true )
                && $subject === ''
                && $roles === [] => $score * 0.45,
            in_array( $intent, [ 'assign_role', 'remove_role', 'manage_user_roles' ], true )
                && ( $subject === '' || $roles === [] ) => $score * 0.65,
            $intent === 'manage_workspace_groups'
                && ( $subject === '' || $groupEmails === [] ) => $score * 0.55,
            in_array( $intent, [ 'recover_module', 'rollback_module', 'enable_module', 'disable_module', 'install_module', 'update_module' ], true )
                && $moduleKey === '' => $score * 0.55,
            $intent === 'create_job'
                && $taskSlug === '' => $score * 0.55,
            $intent === 'restore_file'
                && ( $fileKey === '' || $runUuid === '' ) => $score * 0.55,
            in_array( $intent, [ 'cancel_job', 'retry_job' ], true )
                && $jobKey === '' => $score * 0.55,
            default => $score,
        };
    }

    private function isHelpStyleFragment( string $fragment ): bool {
        return preg_match( self::HELP_STYLE_PATTERN, $fragment ) === 1;
    }

    private function isInstructionalFragment( string $fragment ): bool {
        return preg_match( self::INSTRUCTIONAL_PATTERN, $fragment ) === 1;
    }

    private function confidenceLabel( float $score ): string {
        if ( $score >= 0.85 ) {
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
