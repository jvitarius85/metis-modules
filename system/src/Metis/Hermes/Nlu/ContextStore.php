<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

use Metis\Hermes\HermesMemoryStore;

final class ContextStore {
    private const TTL_SECONDS = 900;

    public function __construct(
        private readonly HermesMemoryStore $memory
    ) {}

    public function remember( string $sessionCode, array $context ): void {
        $this->memory->rememberPendingNluContext( $sessionCode, $context );
    }

    public function clear( string $sessionCode ): void {
        $this->memory->clearPendingNluContext( $sessionCode );
    }

    /**
     * @return array<string,mixed>
     */
    public function recall( string $sessionCode ): array {
        $stored = $this->memory->recallPendingNluContext( $sessionCode );
        if ( $stored === [] ) {
            return [];
        }

        $updatedAt = trim( (string) ( $stored['updated_at'] ?? '' ) );
        if ( $updatedAt !== '' ) {
            $timestamp = strtotime( $updatedAt );
            if ( $timestamp !== false && $timestamp < ( time() - self::TTL_SECONDS ) ) {
                $this->clear( $sessionCode );
                return [];
            }
        }

        return (array) ( $stored['contents'] ?? [] );
    }
}
