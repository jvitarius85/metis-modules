<?php
declare(strict_types=1);

namespace Metis\Core\Jobs;

class JobWorkerRegistry {
    /** @var array<string,callable> */
    private array $workers = [];

    public function register( string $job_type, callable $handler ): void {
        $job_type = $this->normalize( $job_type );
        if ( $job_type === '' ) {
            throw new \InvalidArgumentException( 'Job type cannot be empty.' );
        }

        $this->workers[ $job_type ] = $handler;
    }

    public function has( string $job_type ): bool {
        $job_type = $this->normalize( $job_type );
        return $job_type !== '' && isset( $this->workers[ $job_type ] );
    }

    public function run( string $job_type, array $payload = [], array $job = [] ): mixed {
        $job_type = $this->normalize( $job_type );
        if ( ! isset( $this->workers[ $job_type ] ) ) {
            throw new \RuntimeException( sprintf( 'No worker registered for job type [%s].', $job_type ) );
        }

        return \call_user_func( $this->workers[ $job_type ], $payload, $job );
    }

    public function all(): array {
        return array_keys( $this->workers );
    }

    private function normalize( string $job_type ): string {
        return trim( strtolower( $job_type ) );
    }
}
