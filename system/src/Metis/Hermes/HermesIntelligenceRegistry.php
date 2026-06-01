<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesIntelligenceRegistry {
    public function __construct(
        private readonly HermesDocumentationIndex $documentation,
        private readonly HermesHelpResolver $help,
        private readonly HermesWalkthroughResolver $walkthroughs,
        private readonly HermesGroundingValidator $grounding
    ) {}

    public function definitions(): array {
        return [
            'documentation' => [
                'key' => 'documentation',
                'label' => 'Documentation Index',
                'type' => 'knowledge',
                'default_limit' => 6,
            ],
            'help_topics' => [
                'key' => 'help_topics',
                'label' => 'Help Topics',
                'type' => 'knowledge',
                'default_limit' => 6,
            ],
            'walkthroughs' => [
                'key' => 'walkthroughs',
                'label' => 'Walkthroughs',
                'type' => 'workflow_guidance',
                'default_limit' => 3,
            ],
        ];
    }

    public function definition( string $key ): ?array {
        $key = strtolower( trim( $key ) );
        return $this->definitions()[ $key ] ?? null;
    }

    public function resolve( string $query, int $limit = 6 ): array {
        $definitions = $this->definitions();
        $docs = $this->documentation->search( $query, max( 1, min( 25, $limit ) ) );
        $helpTopics = $this->help->search( $query, max( 1, min( 25, $limit ) ) );
        $walkthroughs = $this->walkthroughs->search(
            $query,
            (int) ( $definitions['walkthroughs']['default_limit'] ?? 3 )
        );

        $sources = array_merge( $docs, $helpTopics, $walkthroughs );

        return [
            'docs' => $docs,
            'help_topics' => $helpTopics,
            'walkthroughs' => $walkthroughs,
            'sources' => [
                'documentation' => [
                    'definition' => $definitions['documentation'],
                    'results' => $docs,
                ],
                'help_topics' => [
                    'definition' => $definitions['help_topics'],
                    'results' => $helpTopics,
                ],
                'walkthroughs' => [
                    'definition' => $definitions['walkthroughs'],
                    'results' => $walkthroughs,
                ],
            ],
            'grounding' => $this->grounding->validate( 'knowledge', $sources ),
        ];
    }
}
