<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Modules\CommunicationsInbound\Contracts\MessageParserInterface;

final class ParserRegistry {
    /** @var array<string, MessageParserInterface> */
    private array $parsers = [];

    public function register( MessageParserInterface $parser ): void {
        $this->parsers[ $parser->key() ] = $parser;
    }

    /**
     * @return array<int, MessageParserInterface>
     */
    public function all(): array {
        $parsers = array_values( $this->parsers );
        usort(
            $parsers,
            static function ( MessageParserInterface $left, MessageParserInterface $right ): int {
                return $left->priority() <=> $right->priority();
            }
        );

        return $parsers;
    }
}
