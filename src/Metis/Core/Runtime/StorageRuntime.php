<?php
declare(strict_types=1);

function metis_runtime_storage_path( string $file ): string {
    $dir = dirname( __DIR__, 4 ) . '/storage/runtime';
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0775, true );
    }

    $path = $dir . '/' . ltrim( $file, '/' );
    $path_dir = dirname( $path );
    if ( ! is_dir( $path_dir ) ) {
        mkdir( $path_dir, 0775, true );
    }

    return $path;
}

function metis_runtime_json_store_read( string $file ): array {
    $path = metis_runtime_storage_path( $file );
    if ( ! is_file( $path ) ) {
        return [];
    }

    $raw = file_get_contents( $path );
    if ( $raw === false || trim( $raw ) === '' ) {
        return [];
    }

    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

function metis_runtime_json_store_write( string $file, array $payload ): void {
    $path = metis_runtime_storage_path( $file );
    file_put_contents( $path, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
}

function metis_runtime_get_option( string $key, mixed $default = false ): mixed {
    $options = metis_runtime_json_store_read( 'options.json' );
    return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}

function metis_runtime_update_option( string $key, mixed $value, bool $autoload = true ): bool {
    $options = metis_runtime_json_store_read( 'options.json' );
    $options[ $key ] = $value;
    metis_runtime_json_store_write( 'options.json', $options );
    return true;
}

function metis_runtime_delete_option( string $key ): bool {
    $options = metis_runtime_json_store_read( 'options.json' );
    unset( $options[ $key ] );
    metis_runtime_json_store_write( 'options.json', $options );
    return true;
}

function metis_runtime_get_transient( string $key ): mixed {
    $store = metis_runtime_json_store_read( 'transients.json' );
    $row = $store[ $key ] ?? null;
    if ( ! is_array( $row ) ) {
        return false;
    }
    if ( (int) ( $row['expires_at'] ?? 0 ) < time() ) {
        unset( $store[ $key ] );
        metis_runtime_json_store_write( 'transients.json', $store );
        return false;
    }
    return $row['value'] ?? false;
}

function metis_runtime_set_transient( string $key, mixed $value, int $expiration ): bool {
    $store = metis_runtime_json_store_read( 'transients.json' );
    $store[ $key ] = [
        'value' => $value,
        'expires_at' => time() + max( 1, $expiration ),
    ];
    metis_runtime_json_store_write( 'transients.json', $store );
    return true;
}

function metis_runtime_delete_transient( string $key ): bool {
    $store = metis_runtime_json_store_read( 'transients.json' );
    unset( $store[ $key ] );
    metis_runtime_json_store_write( 'transients.json', $store );
    return true;
}
