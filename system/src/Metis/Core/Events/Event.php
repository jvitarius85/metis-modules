<?php
declare(strict_types=1);

namespace Metis\Core\Events;

class Event {
    private bool $propagation_stopped = false;
    private array $errors = [];

    public function __construct(
        private string $name,
        private array $payload = [],
        private array $context = [],
        private string $emitted_at = ''
    ) {
        $this->emitted_at = $this->emitted_at !== '' ? $this->emitted_at : gmdate( 'c' );
    }

    public function name(): string {
        return $this->name;
    }

    public function payload( ?string $key = null, mixed $default = null ): mixed {
        if ( $key === null ) {
            return $this->payload;
        }

        return $this->payload[ $key ] ?? $default;
    }

    public function context( ?string $key = null, mixed $default = null ): mixed {
        if ( $key === null ) {
            return $this->context;
        }

        return $this->context[ $key ] ?? $default;
    }

    public function emitted_at(): string {
        return $this->emitted_at;
    }

    public function stop_propagation(): void {
        $this->propagation_stopped = true;
    }

    public function propagation_stopped(): bool {
        return $this->propagation_stopped;
    }

    public function record_error( string $listener, \Throwable $error ): void {
        $this->errors[] = [
            'listener' => $listener,
            'message'  => $error->getMessage(),
            'type'     => $error::class,
        ];
    }

    public function errors(): array {
        return $this->errors;
    }
}
