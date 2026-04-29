<?php
declare(strict_types=1);

namespace Metis\Modules\Cms\Services;

use Metis\Core\Application;
use Metis\Modules\Cms\SchemaManager;

/**
 * CMS redirects are path-based, deterministic, and loop-safe.
 */
final class RedirectService {
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array {
        SchemaManager::ensureSchema();

        $db = self::db();
        $table = \Metis_Tables::get( 'cms_redirects' );
        if ( $table === '' ) {
            return [];
        }

        $rows = $db->fetchAll(
            "SELECT id, source_path, destination_path, redirect_type, is_active, notes, created_by, updated_by, created_at, updated_at
             FROM {$table}
             ORDER BY source_path ASC",
            []
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_values( array_filter( array_map(
            static function ( $row ): ?array {
                if ( ! is_array( $row ) ) {
                    return null;
                }

                return [
                    'id' => (int) ( $row['id'] ?? 0 ),
                    'source_path' => (string) ( $row['source_path'] ?? '' ),
                    'destination_path' => (string) ( $row['destination_path'] ?? '' ),
                    'redirect_type' => (string) ( $row['redirect_type'] ?? '301' ),
                    'is_active' => ! empty( $row['is_active'] ),
                    'notes' => (string) ( $row['notes'] ?? '' ),
                    'created_by' => isset( $row['created_by'] ) ? (int) $row['created_by'] : null,
                    'updated_by' => isset( $row['updated_by'] ) ? (int) $row['updated_by'] : null,
                    'created_at' => (string) ( $row['created_at'] ?? '' ),
                    'updated_at' => (string) ( $row['updated_at'] ?? '' ),
                ];
            },
            $rows
        ) ) );
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getById( int $id ): ?array {
        if ( $id < 1 ) {
            return null;
        }

        SchemaManager::ensureSchema();

        $db = self::db();
        $table = \Metis_Tables::get( 'cms_redirects' );
        if ( $table === '' ) {
            return null;
        }

        $row = $db->fetchOne(
            "SELECT id, source_path, destination_path, redirect_type, is_active, notes, created_by, updated_by, created_at, updated_at
             FROM {$table}
             WHERE id = %d
             LIMIT 1",
            [ $id ]
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'source_path' => (string) ( $row['source_path'] ?? '' ),
            'destination_path' => (string) ( $row['destination_path'] ?? '' ),
            'redirect_type' => (string) ( $row['redirect_type'] ?? '301' ),
            'is_active' => ! empty( $row['is_active'] ),
            'notes' => (string) ( $row['notes'] ?? '' ),
            'created_by' => isset( $row['created_by'] ) ? (int) $row['created_by'] : null,
            'updated_by' => isset( $row['updated_by'] ) ? (int) $row['updated_by'] : null,
            'created_at' => (string) ( $row['created_at'] ?? '' ),
            'updated_at' => (string) ( $row['updated_at'] ?? '' ),
        ];
    }

    /**
     * @return array{status:int,location:string}|null
     */
    public static function resolve( string $requested_path, int $max_hops = 5 ): ?array {
        $source = self::normalizeSourcePath( $requested_path );
        if ( $source === '' ) {
            return null;
        }

        $hops = max( 1, min( 12, $max_hops ) );
        $seen = [ $source => true ];
        $status = 301;
        $current = $source;
        $resolved = null;

        for ( $i = 0; $i < $hops; $i++ ) {
            $row = self::activeRedirectBySource( $current );
            if ( $row === null ) {
                break;
            }

            if ( $i === 0 ) {
                $status = self::normalizeRedirectType( (string) ( $row['redirect_type'] ?? '301' ) );
            }

            $destination = self::normalizeDestinationPath( (string) ( $row['destination_path'] ?? '' ) );
            if ( $destination === '' || $destination === $current ) {
                return null;
            }
            if ( isset( $seen[ $destination ] ) ) {
                return null;
            }

            $resolved = $destination;
            $seen[ $destination ] = true;
            $current = $destination;
        }

        if ( $resolved === null || $resolved === $source ) {
            return null;
        }

        return [
            'status' => $status,
            'location' => $resolved,
        ];
    }

    public static function upsert(
        string $source_path,
        string $destination_path,
        string $redirect_type = '301',
        bool $is_active = true,
        string $notes = ''
    ): bool {
        SchemaManager::ensureSchema();

        $source = self::normalizeSourcePath( $source_path );
        $destination = self::normalizeDestinationPath( $destination_path );
        if ( $source === '' || $destination === '' || $source === $destination ) {
            return false;
        }

        $existing_chain = self::resolve( $destination );
        if ( is_array( $existing_chain ) && (string) ( $existing_chain['location'] ?? '' ) === $source ) {
            return false;
        }

        $db = self::db();
        $table = \Metis_Tables::get( 'cms_redirects' );
        if ( $table === '' ) {
            return false;
        }

        $payload = [
            'destination_path' => $destination,
            'redirect_type' => self::normalizeRedirectType( $redirect_type ),
            'is_active' => $is_active ? 1 : 0,
            'notes' => trim( $notes ) !== '' ? trim( $notes ) : null,
            'updated_by' => self::currentUserId(),
        ];

        $existing = $db->fetchOne( "SELECT id FROM {$table} WHERE source_path = %s LIMIT 1", [ $source ] );
        if ( is_array( $existing ) && isset( $existing['id'] ) ) {
            $result = $db->update( $table, $payload, [ 'id' => (int) $existing['id'] ] );
            return $result !== false;
        }

        $payload['source_path'] = $source;
        $payload['created_by'] = self::currentUserId();
        $result = $db->insert( $table, $payload );
        return $result !== false;
    }

    public static function save(
        int $id,
        string $source_path,
        string $destination_path,
        string $redirect_type = '301',
        bool $is_active = true,
        string $notes = ''
    ): bool {
        SchemaManager::ensureSchema();

        $source = self::normalizeSourcePath( $source_path );
        $destination = self::normalizeDestinationPath( $destination_path );
        if ( $source === '' || $destination === '' || $source === $destination ) {
            return false;
        }

        $existing_chain = self::resolve( $destination );
        if ( is_array( $existing_chain ) && (string) ( $existing_chain['location'] ?? '' ) === $source ) {
            return false;
        }

        $db = self::db();
        $table = \Metis_Tables::get( 'cms_redirects' );
        if ( $table === '' ) {
            return false;
        }

        $conflict = $db->fetchOne(
            "SELECT id FROM {$table} WHERE source_path = %s LIMIT 1",
            [ $source ]
        );
        $conflict_id = is_array( $conflict ) ? (int) ( $conflict['id'] ?? 0 ) : 0;
        if ( $conflict_id > 0 && $conflict_id !== $id ) {
            return false;
        }

        $payload = [
            'source_path' => $source,
            'destination_path' => $destination,
            'redirect_type' => self::normalizeRedirectType( $redirect_type ),
            'is_active' => $is_active ? 1 : 0,
            'notes' => trim( $notes ) !== '' ? trim( $notes ) : null,
            'updated_by' => self::currentUserId(),
        ];

        if ( $id > 0 ) {
            $existing = self::getById( $id );
            if ( $existing === null ) {
                return false;
            }

            $result = $db->update( $table, $payload, [ 'id' => $id ] );
            return $result !== false;
        }

        $payload['created_by'] = self::currentUserId();
        $result = $db->insert( $table, $payload );
        return $result !== false;
    }

    public static function delete( int $id ): bool {
        if ( $id < 1 ) {
            return false;
        }

        SchemaManager::ensureSchema();

        $db = self::db();
        $table = \Metis_Tables::get( 'cms_redirects' );
        if ( $table === '' ) {
            return false;
        }

        $result = $db->delete( $table, [ 'id' => $id ] );
        return $result !== false;
    }

    public static function createSlugChangeRedirect( string $old_path, string $new_path, string $entity_label = '' ): bool {
        $label = trim( $entity_label );
        $notes = $label !== ''
            ? 'Auto redirect after slug change (' . $label . ')'
            : 'Auto redirect after slug change';
        return self::upsert( $old_path, $new_path, '301', true, $notes );
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function activeRedirectBySource( string $source ): ?array {
        $db = self::db();
        $table = \Metis_Tables::get( 'cms_redirects' );
        if ( $table === '' ) {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT source_path, destination_path, redirect_type FROM {$table} WHERE source_path = %s AND is_active = 1 LIMIT 1",
            [ $source ]
        );
        return is_array( $row ) ? $row : null;
    }

    private static function normalizeRedirectType( string $value ): int {
        $type = trim( $value );
        return $type === '302' ? 302 : 301;
    }

    private static function normalizeSourcePath( string $path ): string {
        $normalized = self::comparablePath( $path );
        if ( $normalized === '' ) {
            return '/';
        }
        return '/' . ltrim( $normalized, '/' );
    }

    private static function normalizeDestinationPath( string $path ): string {
        return self::normalizeSourcePath( $path );
    }

    private static function comparablePath( string $value ): string {
        $raw = trim( $value );
        if ( $raw === '' ) {
            return '';
        }

        if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) === 1 ) {
            $raw = (string) ( parse_url( $raw, PHP_URL_PATH ) ?? '' );
        } elseif ( str_starts_with( $raw, '//' ) ) {
            $raw = (string) ( parse_url( 'https:' . $raw, PHP_URL_PATH ) ?? '' );
        }

        $raw = (string) preg_replace( '/[#?].*$/', '', $raw );
        $raw = trim( $raw );
        if ( $raw === '' ) {
            return '';
        }

        $raw = '/' . trim( $raw, '/' );
        $raw = (string) preg_replace( '#/{2,}#', '/', $raw );
        return $raw;
    }

    private static function currentUserId(): ?int {
        if ( ! function_exists( 'metis_current_user_id' ) ) {
            return null;
        }
        $uid = (int) metis_current_user_id();
        return $uid > 0 ? $uid : null;
    }

    private static function db(): object {
        return Application::service( 'db' );
    }
}
