<?php
declare(strict_types=1);

namespace Metis\Core;

final class ServiceRegistry {
    private array $factories = [];
    private array $instances = [];

    public function singleton( string $name, callable $factory ): void {
        $this->factories[ $this->normalize( $name ) ] = $factory;
    }

    public function instance( string $name, mixed $service ): void {
        $this->instances[ $this->normalize( $name ) ] = $service;
    }

    public function has( string $name ): bool {
        $name = $this->normalize( $name );
        return array_key_exists( $name, $this->instances ) || array_key_exists( $name, $this->factories );
    }

    public function get( string $name ): mixed {
        $name = $this->normalize( $name );

        if ( array_key_exists( $name, $this->instances ) ) {
            return $this->instances[ $name ];
        }

        if ( ! array_key_exists( $name, $this->factories ) ) {
            throw new \InvalidArgumentException( sprintf( 'Metis service [%s] is not registered.', $name ) );
        }

        $this->instances[ $name ] = call_user_func( $this->factories[ $name ], $this );
        return $this->instances[ $name ];
    }

    private function normalize( string $name ): string {
        $name = \metis_key_clean( $name );
        if ( $name === '' ) {
            throw new \InvalidArgumentException( 'Metis service names cannot be empty.' );
        }

        return $name;
    }
}
