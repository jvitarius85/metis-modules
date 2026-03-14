<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesReasoner {
    public function __construct(
        private readonly HermesContextBuilder $context_builder,
        private readonly HermesDiagnosticEngine $diagnostics,
        private readonly HermesPlaybookEngine $playbooks,
        private readonly HermesMissionEngine $missions,
        private readonly HermesActionPlanner $actions,
        private readonly HermesGroundingValidator $grounding
    ) {}

    public function reason( string $query, array $session = [] ): array {
        $context = $this->context_builder->build( $query, $session );
        $pack_keys = array_values( array_filter( array_map(
            static fn ( array $pack ): string => (string) ( $pack['key'] ?? '' ),
            (array) ( $context['context_packs'] ?? [] )
        ) ) );
        $matched_playbooks = $this->playbooks->match( $query, $pack_keys );
        $matched_missions  = $this->missions->match( $query, $pack_keys );
        $diagnostic_result = $this->shouldDiagnose( $query, $matched_playbooks, $matched_missions )
            ? $this->diagnostics->run( $context )
            : [ 'summary' => [ 'finding_count' => 0, 'high_severity' => 0 ], 'findings' => [] ];

        $sources = array_merge(
            (array) ( $context['knowledge']['docs'] ?? [] ),
            (array) ( $context['knowledge']['help_topics'] ?? [] ),
            (array) $matched_playbooks,
            (array) $matched_missions
        );

        $answer = $this->composeAnswer( $query, $context, $diagnostic_result, $matched_playbooks, $matched_missions );
        $grounding = $this->grounding->validate( $answer, $sources );

        $reasoning = [
            'query' => $query,
            'intent' => $this->detectIntent( $query, $matched_missions ),
            'context' => $context,
            'diagnostics' => $diagnostic_result,
            'playbooks' => $matched_playbooks,
            'missions' => $matched_missions,
            'knowledge' => (array) ( $context['knowledge'] ?? [] ),
            'answer' => $answer,
            'grounding' => $grounding,
        ];
        $reasoning['actions'] = $this->actions->plan( $reasoning );

        return $reasoning;
    }

    private function shouldDiagnose( string $query, array $playbooks, array $missions ): bool {
        $query = strtolower( $query );
        if ( $playbooks !== [] || $missions !== [] ) {
            return true;
        }

        foreach ( [ 'diagnostic', 'broken', 'issue', 'problem', 'health', 'error' ] as $keyword ) {
            if ( str_contains( $query, $keyword ) ) {
                return true;
            }
        }

        return false;
    }

    private function detectIntent( string $query, array $missions ): string {
        $query = strtolower( $query );
        if ( $missions !== [] ) {
            return 'mission';
        }
        if ( str_contains( $query, 'help' ) || str_contains( $query, 'how do i' ) ) {
            return 'help';
        }
        if ( str_contains( $query, 'walkthrough' ) ) {
            return 'walkthrough';
        }
        if ( str_contains( $query, 'diagnostic' ) || str_contains( $query, 'health' ) ) {
            return 'diagnostic';
        }
        return 'conversation';
    }

    private function composeAnswer( string $query, array $context, array $diagnostics, array $playbooks, array $missions ): string {
        $parts = [];
        $packs = array_map( static fn ( array $pack ): string => (string) ( $pack['title'] ?? $pack['key'] ?? 'context' ), (array) ( $context['context_packs'] ?? [] ) );

        if ( $packs !== [] ) {
            $parts[] = 'Loaded context packs: ' . implode( ', ', array_slice( $packs, 0, 3 ) ) . '.';
        }

        if ( ! empty( $diagnostics['summary']['finding_count'] ) ) {
            $parts[] = sprintf(
                'Diagnostics surfaced %d findings with %d high-severity items.',
                (int) $diagnostics['summary']['finding_count'],
                (int) $diagnostics['summary']['high_severity']
            );
        }

        if ( $playbooks !== [] ) {
            $parts[] = 'Relevant playbook: ' . (string) ( $playbooks[0]['title'] ?? $playbooks[0]['key'] ?? 'playbook' ) . '.';
        }

        if ( $missions !== [] ) {
            $parts[] = 'Suggested mission: ' . (string) ( $missions[0]['title'] ?? $missions[0]['key'] ?? 'mission' ) . '.';
        }

        if ( ! empty( $context['knowledge']['help_topics'][0]['title'] ) ) {
            $parts[] = 'Best documentation match: ' . (string) $context['knowledge']['help_topics'][0]['title'] . '.';
        }

        if ( $parts === [] ) {
            $parts[] = 'Hermes mapped the request and is ready to prepare grounded help, diagnostics, or mission actions.';
        }

        return implode( ' ', $parts );
    }
}
