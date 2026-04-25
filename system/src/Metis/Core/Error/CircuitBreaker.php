<?php
declare(strict_types=1);

namespace Metis\Core\Error;

use Metis\Core\Cache\CacheService;

final class CircuitBreaker {
    private const DEFAULT_THRESHOLD = 3;
    private const DEFAULT_COOLDOWN = 120;

    public function state( string $name ): array {
        return $this->read( $name );
    }

    public function before( string $name, int $threshold = self::DEFAULT_THRESHOLD, int $cooldown = self::DEFAULT_COOLDOWN ): array {
        $state = $this->read( $name );
        $now = time();

        if ( (string) ( $state['state'] ?? 'closed' ) !== 'open' ) {
            return $state;
        }

        if ( $now >= (int) ( $state['open_until'] ?? 0 ) ) {
            $state['state'] = 'half_open';
            $state['threshold'] = $threshold;
            $state['cooldown'] = $cooldown;
            $this->write( $name, $state, $cooldown );
        }

        return $state;
    }

    public function isCallPermitted( string $name, int $threshold = self::DEFAULT_THRESHOLD, int $cooldown = self::DEFAULT_COOLDOWN ): bool {
        $state = $this->before( $name, $threshold, $cooldown );
        return (string) ( $state['state'] ?? 'closed' ) !== 'open';
    }

    public function recordFailure( string $name, int $threshold = self::DEFAULT_THRESHOLD, int $cooldown = self::DEFAULT_COOLDOWN ): array {
        $state = $this->read( $name );
        $failures = (int) ( $state['failures'] ?? 0 ) + 1;
        $next = [
            'state' => $failures >= $threshold ? 'open' : ( (string) ( $state['state'] ?? 'closed' ) === 'half_open' ? 'open' : 'closed' ),
            'failures' => $failures,
            'opened_at' => $failures >= $threshold ? time() : (int) ( $state['opened_at'] ?? 0 ),
            'open_until' => $failures >= $threshold ? time() + $cooldown : (int) ( $state['open_until'] ?? 0 ),
            'threshold' => $threshold,
            'cooldown' => $cooldown,
            'updated_at' => time(),
        ];
        $this->write( $name, $next, max( $cooldown, 600 ) );
        return $next;
    }

    public function recordSuccess( string $name ): array {
        $next = [
            'state' => 'closed',
            'failures' => 0,
            'opened_at' => 0,
            'open_until' => 0,
            'updated_at' => time(),
        ];
        $this->write( $name, $next, 600 );
        return $next;
    }

    private function read( string $name ): array {
        $key = $this->key( $name );

        try {
            $state = CacheService::get( $key );
            return is_array( $state ) ? $state : [ 'state' => 'closed', 'failures' => 0, 'open_until' => 0 ];
        } catch ( \Throwable ) {
            return [ 'state' => 'closed', 'failures' => 0, 'open_until' => 0 ];
        }
    }

    private function write( string $name, array $state, int $ttl ): void {
        try {
            CacheService::set( $this->key( $name ), $state, $ttl );
        } catch ( \Throwable ) {
        }
    }

    private function key( string $name ): string {
        return 'error.circuit.' . strtolower( preg_replace( '/[^a-z0-9_.-]/i', '_', $name ) ?? 'dependency' );
    }
}
