<?php
declare(strict_types=1);

namespace Metis\Hermes;

final class HermesHelpResolver {
    public function __construct(
        private readonly \Metis_Help_Service $help
    ) {}

    public function search( string $query, int $limit = 6 ): array {
        return $this->help->search( $query, $limit );
    }

    public function topic( string $topic_id ): ?array {
        return $this->help->topic( $topic_id );
    }
}
