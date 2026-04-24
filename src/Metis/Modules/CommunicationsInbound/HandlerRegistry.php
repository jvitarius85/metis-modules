<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

use Metis\Modules\CommunicationsInbound\Contracts\MessageHandlerInterface;

final class HandlerRegistry {
    /** @var array<string, MessageHandlerInterface> */
    private array $handlers = [];

    public function register( MessageHandlerInterface $handler ): void {
        $this->handlers[ $handler->key() ] = $handler;
    }

    public function resolve( string $key ): ?MessageHandlerInterface {
        $key = trim( strtolower( $key ) );
        if ( $key === '' ) {
            return null;
        }

        return $this->handlers[ $key ] ?? null;
    }
}
