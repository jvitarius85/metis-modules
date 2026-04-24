<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\Contracts;

use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;

interface MessageParserInterface {
    public function key(): string;

    public function priority(): int;

    public function parse( NormalizedInboundMessage $message ): ParseResult;
}
