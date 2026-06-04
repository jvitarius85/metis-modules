<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesIntentRouter {
    private const DATA_INTENTS = [ 'list', 'search', 'get', 'count', 'aggregate', 'top', 'export' ];

    public function __construct(
        private readonly ConversationalParser $parser,
        private readonly HermesCommandRegistry $commands,
        private readonly HermesIntentParser $legacyParser,
        private readonly HermesIntentRegistry $intentRegistry
    ) {}

    public function route( string $query, array $runtimeContext = [] ): array {
        $sessionCode = trim( (string) ( $runtimeContext['session_code'] ?? '' ) );
        $parsed = $this->parser->parse( $query, $sessionCode );
        $legacy = $this->legacyParser->parse( $query );

        if ( $this->shouldPreferLegacy( $parsed, $legacy, $query ) ) {
            $parsed = $this->buildParsedFromLegacy( $legacy, $query );
        }

        $intent = [
            'action' => (string) ( $parsed['selected_intent'] ?? 'unknown' ),
            'top_level_intent' => (string) ( $parsed['top_level_intent'] ?? $this->intentRegistry->classifyQuery( $query ) ),
            'confidence' => (float) ( $parsed['confidence_score'] ?? 0.0 ),
            'payload' => (array) ( $parsed['intents'][0]['payload'] ?? [] ),
            'type' => (string) ( $parsed['type'] ?? '' ),
            'command' => $parsed['command'] ?? null,
            'query' => $query,
            'raw_query' => $query,
        ];

        if ( (string) ( $parsed['type'] ?? '' ) === 'data' ) {
            $intent = array_merge( $intent, [
                'entity' => (string) ( $parsed['entity'] ?? '' ),
                'filters' => (array) ( $parsed['filters'] ?? [] ),
                'fields_requested' => (array) ( $parsed['fields_requested'] ?? [] ),
                'aggregate' => (array) ( $parsed['aggregate'] ?? [] ),
                'group_by' => (array) ( $parsed['group_by'] ?? [] ),
                'date_range' => (array) ( $parsed['date_range'] ?? [] ),
                'sort' => $parsed['sort'] ?? null,
                'sort_dir' => (string) ( $parsed['sort_dir'] ?? 'desc' ),
                'limit' => (int) ( $parsed['limit'] ?? 50 ),
                'offset' => (int) ( $parsed['offset'] ?? 0 ),
                'top_n' => $parsed['top_n'] ?? null,
            ] );
        }

        if ( (string) ( $intent['action'] ?? '' ) === 'resolve_help_issue' ) {
            $intent['payload'] = $this->mergeHelpPayload( $intent['payload'], $runtimeContext );
            $parsed = $this->syncParsedPayload( $parsed, $intent['payload'] );
        }

        $command = $this->commands->definition( (string) ( $intent['action'] ?? '' ) );
        $routeType = $this->determineRouteType( $parsed, $intent, $command );

        return [
            'parsed' => $parsed,
            'intent' => $intent,
            'command' => $command,
            'route_type' => $routeType,
        ];
    }

    private function shouldPreferLegacy( array $parsed, array $legacy, string $query ): bool {
        if ( (string) ( $legacy['type'] ?? '' ) === 'data' ) {
            return true;
        }

        $selectedIntent = strtolower( trim( (string) ( $parsed['selected_intent'] ?? '' ) ) );
        if ( $selectedIntent === '' || $selectedIntent === 'unknown' ) {
            if ( (string) ( $legacy['type'] ?? '' ) !== 'command' || empty( $legacy['command'] ) ) {
                return false;
            }

            return $this->isSafeLegacyCommandFallback( (string) ( $legacy['action'] ?? '' ), $query );
        }

        return false;
    }

    private function isSafeLegacyCommandFallback( string $action, string $query ): bool {
        $normalized = strtolower( trim( $query ) );

        return match ( $action ) {
            'lookup_profile' => preg_match(
                '/\b(who is|look up|lookup|find person|find contact|find donor|find user|profile for|person record|contact record|donor record)\b/',
                $normalized
            ) === 1,
            'get_entity_attribute' => preg_match(
                '/\b(what is|email for|phone for|address for|email address|phone number|mailing address)\b/',
                $normalized
            ) === 1,
            default => true,
        };
    }

    private function buildParsedFromLegacy( array $legacy, string $query ): array {
        $action = (string) ( $legacy['action'] ?? 'unknown' );
        $payload = (array) ( $legacy['payload'] ?? [] );
        $confidence = (float) ( $legacy['confidence'] ?? 0.0 );
        $topLevelIntent = (string) ( $legacy['top_level_intent'] ?? $this->intentRegistry->classifyCommand( $action, $query ) );

        return [
            'normalized_input' => strtolower( trim( $query ) ),
            'intents' => [
                [
                    'fragment' => strtolower( trim( $query ) ),
                    'intent' => $action,
                    'tool_key' => (string) ( $legacy['command']['tool_key'] ?? '' ),
                    'confidence' => $confidence,
                    'confidence_label' => $this->confidenceLabel( $confidence ),
                    'payload' => $payload,
                ],
            ],
            'selected_intent' => $action,
            'top_level_intent' => $topLevelIntent,
            'entities' => [],
            'confidence_score' => $confidence,
            'confidence_label' => $this->confidenceLabel( $confidence ),
            'requires_clarification' => false,
            'clarification_prompt' => '',
            'execution_plan' => [],
            'alternative_intents' => [],
            'context' => [],
            'type' => (string) ( $legacy['type'] ?? 'command' ),
            'command' => $legacy['command'] ?? null,
            'query' => $query,
            'raw_query' => $query,
        ] + ( (string) ( $legacy['type'] ?? '' ) === 'data' ? [
            'entity' => (string) ( $legacy['entity'] ?? '' ),
            'filters' => (array) ( $legacy['filters'] ?? [] ),
            'fields_requested' => (array) ( $legacy['fields_requested'] ?? [] ),
            'aggregate' => (array) ( $legacy['aggregate'] ?? [] ),
            'group_by' => (array) ( $legacy['group_by'] ?? [] ),
            'date_range' => (array) ( $legacy['date_range'] ?? [] ),
            'sort' => $legacy['sort'] ?? null,
            'sort_dir' => (string) ( $legacy['sort_dir'] ?? 'desc' ),
            'limit' => (int) ( $legacy['limit'] ?? 50 ),
            'offset' => (int) ( $legacy['offset'] ?? 0 ),
            'top_n' => $legacy['top_n'] ?? null,
        ] : [] );
    }

    private function determineRouteType( array $parsed, array $intent, array $command ): string {
        if ( ! empty( $parsed['requires_clarification'] ) ) {
            return 'clarification';
        }

        if ( (string) ( $intent['action'] ?? '' ) === 'get_entity_attribute' ) {
            return 'entity_attribute';
        }

        if ( (string) ( $parsed['type'] ?? '' ) === 'data' || $this->isDataIntent( $intent, $command ) ) {
            return 'data';
        }

        if ( $command !== [] ) {
            return 'command';
        }

        return 'knowledge';
    }

    private function isDataIntent( array $intent, array $command ): bool {
        if ( (string) ( $intent['type'] ?? '' ) === 'data' ) {
            return true;
        }

        return $command === []
            && in_array( strtolower( (string) ( $intent['action'] ?? '' ) ), self::DATA_INTENTS, true );
    }

    private function mergeHelpPayload( array $payload, array $runtimeContext ): array {
        $payload['current_route'] = trim( (string) ( $runtimeContext['current_route'] ?? ( $payload['current_route'] ?? '' ) ) );
        $payload['current_module'] = trim( (string) ( $runtimeContext['current_module'] ?? ( $payload['current_module'] ?? '' ) ) );
        $payload['session_context'] = array_merge(
            (array) ( $payload['session_context'] ?? [] ),
            array_filter( [
                'current_topic' => trim( (string) ( $runtimeContext['current_topic'] ?? '' ) ),
            ], static fn ( mixed $value ): bool => is_string( $value ) && $value !== '' )
        );

        return $payload;
    }

    private function syncParsedPayload( array $parsed, array $payload ): array {
        if ( isset( $parsed['intents'][0] ) && is_array( $parsed['intents'][0] ) ) {
            $parsed['intents'][0]['payload'] = $payload;
        }
        if ( isset( $parsed['execution_plan'][0]['payload'] ) && is_array( $parsed['execution_plan'][0]['payload'] ) ) {
            $parsed['execution_plan'][0]['payload'] = $payload;
        }

        return $parsed;
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
