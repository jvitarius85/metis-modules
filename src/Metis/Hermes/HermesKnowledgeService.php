<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesKnowledgeService {
    public function __construct(
        private readonly HermesDocumentationIndex $documentation,
        private readonly HermesHelpResolver $help,
        private readonly HermesWalkthroughResolver $walkthroughs,
        private readonly HermesGroundingValidator $grounding
    ) {}

    public function resolve( string $query, int $limit = 6 ): array {
        $docs         = $this->documentation->search( $query, $limit );
        $help_topics  = $this->help->search( $query, $limit );
        $walkthroughs = $this->walkthroughs->search( $query, 3 );
        $sources      = array_merge( $docs, $help_topics, $walkthroughs );

        return [
            'docs'         => $docs,
            'help_topics'  => $help_topics,
            'walkthroughs' => $walkthroughs,
            'grounding'    => $this->grounding->validate( 'knowledge', $sources ),
        ];
    }
}
