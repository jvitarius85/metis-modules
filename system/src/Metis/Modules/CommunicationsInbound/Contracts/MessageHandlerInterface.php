<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound\Contracts;

use Metis\Modules\CommunicationsInbound\ValueObjects\NormalizedInboundMessage;
use Metis\Modules\CommunicationsInbound\ValueObjects\ParseResult;

interface MessageHandlerInterface {
    public function key(): string;

    /**
     * @param array<string, mixed> $message_row
     * @return array<string, mixed>
     */
    public function handle( array $message_row, NormalizedInboundMessage $message, ParseResult $result ): array;
}
