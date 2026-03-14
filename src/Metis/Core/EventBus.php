<?php
declare(strict_types=1);

namespace Metis\Core;

final class EventBus {
    private array $listeners = [];
    private int $sequence = 0;

    public function subscribe( string $event, callable $listener, int $priority = 10 ): void {
        $event = $this->normalize_pattern( $event );
        if ( $event === '' ) {
            throw new \InvalidArgumentException( 'Metis event names cannot be empty.' );
        }

        $this->listeners[ $event ][] = [
            'listener' => $listener,
            'priority' => $priority,
            'sequence' => $this->sequence++,
        ];
    }

    public function publish( string $name, array $payload = [], array $context = [] ): Event {
        $name = $this->normalize_name( $name );
        if ( $name === '' ) {
            throw new \InvalidArgumentException( 'Metis event names cannot be empty.' );
        }

        $event     = new Event( $name, $payload, $context );
        $listeners = $this->listeners_for( $name );

        foreach ( $listeners as $entry ) {
            $listener = $entry['listener'];
            $label    = $this->callable_label( $listener );

            try {
                $listener( $event );
            } catch ( \Throwable $error ) {
                $event->record_error( $label, $error );
                $this->log_listener_error( $event, $label, $error );
            }

            if ( $event->propagation_stopped() ) {
                break;
            }
        }

        return $event;
    }

    public function listeners( ?string $event = null ): array {
        if ( $event === null ) {
            return $this->listeners;
        }

        $event = $this->normalize_name( $event );
        return $this->listeners_for( $event );
    }

    private function listeners_for( string $event ): array {
        $resolved = [];

        foreach ( $this->listeners as $pattern => $listeners ) {
            if ( ! $this->matches( $pattern, $event ) ) {
                continue;
            }

            foreach ( $listeners as $listener ) {
                $resolved[] = $listener;
            }
        }

        usort(
            $resolved,
            static function ( array $left, array $right ): int {
                if ( $left['priority'] === $right['priority'] ) {
                    return $left['sequence'] <=> $right['sequence'];
                }

                return $left['priority'] <=> $right['priority'];
            }
        );

        return $resolved;
    }

    private function matches( string $pattern, string $event ): bool {
        if ( $pattern === '*' ) {
            return true;
        }

        if ( ! str_contains( $pattern, '*' ) ) {
            return $pattern === $event;
        }

        $regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
        return preg_match( $regex, $event ) === 1;
    }

    private function normalize_name( string $name ): string {
        return trim( strtolower( preg_replace( '/[^a-z0-9._-]+/', '', $name ) ?? '' ) );
    }

    private function normalize_pattern( string $pattern ): string {
        return trim( strtolower( preg_replace( '/[^a-z0-9._*-]+/', '', $pattern ) ?? '' ) );
    }

    private function callable_label( callable $listener ): string {
        if ( is_string( $listener ) ) {
            return $listener;
        }

        if ( is_array( $listener ) ) {
            $target = is_object( $listener[0] ?? null ) ? $listener[0]::class : (string) ( $listener[0] ?? 'callable' );
            return $target . '::' . (string) ( $listener[1] ?? '__invoke' );
        }

        if ( $listener instanceof \Closure ) {
            return 'closure';
        }

        if ( is_object( $listener ) ) {
            return $listener::class;
        }

        return 'callable';
    }

    private function log_listener_error( Event $event, string $listener, \Throwable $error ): void {
        if ( ! Application::has_service( 'logger' ) ) {
            return;
        }

        Application::service( 'logger' )->error(
            'Event listener failed',
            [
                'event'    => $event->name(),
                'listener' => $listener,
                'error'    => $error->getMessage(),
            ]
        );
    }
}
