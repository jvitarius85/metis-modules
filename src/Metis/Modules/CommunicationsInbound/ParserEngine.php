<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;

final class ParserEngine {
    public function __construct( private readonly ParserRegistry $registry ) {}

    /**
     * @return array{result: ParseResult, errors: array<int, array<string, string>>}
     */
    public function evaluate( NormalizedInboundMessage $message ): array {
        $errors = [];

        foreach ( $this->registry->all() as $parser ) {
            try {
                $result = $parser->parse( $message );
            } catch ( \Throwable $e ) {
                $errors[] = [
                    'parser_key' => $parser->key(),
                    'error'      => $e->getMessage(),
                ];
                continue;
            }

            if ( $result->matchedMessage() ) {
                return [
                    'result' => $result,
                    'errors' => $errors,
                ];
            }
        }

        return [
            'result' => ParseResult::unknown(),
            'errors' => $errors,
        ];
    }
}
