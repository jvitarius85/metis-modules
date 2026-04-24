<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesActionPlanner {
    public function __construct(
        private readonly HermesActionPreview $preview,
        private readonly HermesToolRegistry $tools
    ) {}

    public function plan( array $reasoning ): array {
        $actions = [];

        if ( ! empty( $reasoning['diagnostics']['findings'] ) ) {
            $actions[] = $this->buildAction( 'run_diagnostic', [
                'query' => (string) ( $reasoning['query'] ?? 'system health' ),
            ] );
        }

        $topic = $reasoning['knowledge']['help_topics'][0] ?? null;
        if ( is_array( $topic ) && ! empty( $topic['id'] ) ) {
            $actions[] = $this->buildAction( 'open_help_topic', [
                'topic_id' => (string) $topic['id'],
            ] );
        }

        $walkthrough = $reasoning['knowledge']['walkthroughs'][0] ?? null;
        if ( is_array( $walkthrough ) && ! empty( $walkthrough['id'] ) ) {
            $actions[] = $this->buildAction( 'launch_walkthrough', [
                'walkthrough_id' => (string) $walkthrough['id'],
            ] );
        }

        $mission = $reasoning['missions'][0] ?? null;
        if ( is_array( $mission ) && ! empty( $mission['key'] ) ) {
            $actions[] = $this->buildAction( 'execute_mission', [
                'mission_key' => (string) $mission['key'],
                'query' => (string) ( $reasoning['query'] ?? '' ),
            ] );
        }

        return $actions;
    }

    private function buildAction( string $action_type, array $payload ): array {
        $tool = $this->tools->definition( $action_type );

        return [
            'action_type' => $action_type,
            'title' => (string) ( $tool['title'] ?? ucwords( str_replace( '_', ' ', $action_type ) ) ),
            'payload' => $payload,
            'preview' => $this->preview->preview( $action_type, $payload, $tool ),
        ];
    }
}
