<?php
declare(strict_types=1);

namespace Metis\Services;

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

final class DatabaseService {
    private ?object $injectedConnection;

    public function __construct( ?object $connection = null ) {
        $this->injectedConnection = $connection;
    }

    public function connection(): object {
        if ( $this->injectedConnection !== null ) {
            return $this->injectedConnection;
        }

        $connection = $GLOBALS['metis_db_connection'] ?? null;

        if ( ! is_object( $connection ) ) {
            throw new \RuntimeException( 'Database connection is not available.' );
        }

        return $connection;
    }

    public function table( string $key ): string {
        return \Metis_Tables::get( $key );
    }

    public function prepare( string $query, mixed ...$arguments ): mixed {
        return $this->connection()->prepare( $query, ...$arguments );
    }

    public function execute( string $query ): int|bool {
        return $this->runWithReconnect( fn (): int|bool => $this->connection()->query( $query ) );
    }

    public function fetchOne( string $query, array $arguments = [] ): ?array {
        $prepared = $arguments === [] ? $query : $this->prepare( $query, ...$arguments );
        $row = $this->runWithReconnect( fn (): mixed => $this->connection()->get_row( $prepared, \ARRAY_A ) );
        return \is_array( $row ) ? $row : null;
    }

    public function fetchAll( string $query, array $arguments = [] ): array {
        $prepared = $arguments === [] ? $query : $this->prepare( $query, ...$arguments );
        $rows = $this->runWithReconnect( fn (): mixed => $this->connection()->get_results( $prepared, \ARRAY_A ) );
        return \is_array( $rows ) ? $rows : [];
    }

    public function scalar( string $query, array $arguments = [] ): mixed {
        $prepared = $arguments === [] ? $query : $this->prepare( $query, ...$arguments );
        return $this->runWithReconnect( fn (): mixed => $this->connection()->get_var( $prepared ) );
    }

    public function column( string $query, array $arguments = [] ): array {
        $prepared = $arguments === [] ? $query : $this->prepare( $query, ...$arguments );
        $rows = $this->runWithReconnect( fn (): mixed => $this->connection()->get_col( $prepared ) );
        return \is_array( $rows ) ? $rows : [];
    }

    public function insert( string $table, array $data, array $format = [] ): mixed {
        return $this->runWithReconnect( fn (): mixed => $this->connection()->insert( $table, $data, $format ) );
    }

    public function update( string $table, array $data, array $where, array $format = [], array $whereFormat = [] ): mixed {
        return $this->runWithReconnect( fn (): mixed => $this->connection()->update( $table, $data, $where, $format, $whereFormat ) );
    }

    public function delete( string $table, array $where, array $whereFormat = [] ): mixed {
        return $this->runWithReconnect( fn (): mixed => $this->connection()->delete( $table, $where, $whereFormat ) );
    }

    public function replace( string $table, array $data, array $format = [] ): mixed {
        return $this->runWithReconnect( fn (): mixed => $this->connection()->replace( $table, $data, $format ) );
    }

    public function escapeLike( string $value ): string {
        return $this->connection()->esc_like( $value );
    }

    public function lastInsertId(): int {
        return (int) ( $this->connection()->insert_id ?? 0 );
    }

    public function reconnect(): bool {
        if ( ! class_exists( 'MetisRuntimeDbConnection' ) ) {
            return false;
        }

        $config = function_exists( 'metis_standalone_database_config' ) ? \metis_standalone_database_config() : [];
        $host = (string) ( $config['host'] ?? '' );
        $port = (int) ( $config['port'] ?? 3306 );
        $database = (string) ( $config['database'] ?? '' );
        $username = (string) ( $config['username'] ?? '' );
        $password = (string) ( $config['password'] ?? '' );
        $prefix = (string) ( $config['prefix'] ?? '' );

        if ( $host === '' || $database === '' || $username === '' ) {
            return false;
        }

        $GLOBALS['metis_db_connection'] = new \MetisRuntimeDbConnection(
            $username,
            $password,
            $database,
            $host . ':' . $port,
            $prefix
        );

        return true;
    }

    private function runWithReconnect( callable $callback ): mixed {
        $result = $callback();
        if ( ! $this->shouldReconnect( $result ) ) {
            return $result;
        }

        if ( ! $this->reconnect() ) {
            return $result;
        }

        return $callback();
    }

    private function shouldReconnect( mixed $result ): bool {
        $connection = $GLOBALS['metis_db_connection'] ?? null;
        $lastError = is_object( $connection ) && isset( $connection->last_error ) ? strtolower( (string) $connection->last_error ) : '';

        if ( $lastError === '' ) {
            return false;
        }

        if ( ! ( $result === false || $result === null || $result === [] ) ) {
            return false;
        }

        foreach ( [ 'server has gone away', 'lost connection', 'connection refused', 'connection failed', 'gone away' ] as $needle ) {
            if ( str_contains( $lastError, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    public function __call( string $method, array $arguments ): mixed {
        return $this->connection()->{$method}( ...$arguments );
    }
}
