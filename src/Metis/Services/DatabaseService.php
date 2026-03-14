<?php
declare(strict_types=1);

namespace Metis\Services;

final class DatabaseService {
    public function connection(): object {
        global $wpdb;

        if ( ! is_object( $wpdb ?? null ) ) {
            throw new \RuntimeException( 'Database connection is not available.' );
        }

        return $wpdb;
    }

    public function table( string $key ): string {
        return \Metis_Tables::get( $key );
    }

    public function __call( string $method, array $arguments ): mixed {
        return $this->connection()->{$method}( ...$arguments );
    }
}
