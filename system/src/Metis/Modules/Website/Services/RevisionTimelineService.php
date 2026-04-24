<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;

/**
 * Persists editor revision timeline entries for website entities.
 */
final class RevisionTimelineService {
    /**
     * @param array<string,mixed> $payload
     */
    public static function save( string $entity_type, int $entity_id, array $payload, string $note = '' ): bool {
        $normalized_type = self::normalizeEntityType( $entity_type );
        if ( $normalized_type === '' || $entity_id < 1 ) {
            return false;
        }

        $record = [
            'schema_version' => 1,
            'entity_type' => $normalized_type,
            'entity_id' => $entity_id,
            'saved_at' => (string) \metis_current_time( 'mysql' ),
            'saved_by' => self::currentUserId(),
            'payload' => $payload,
        ];
        $fingerprint = sha1( self::jsonEncode( $record['payload'] ) );
        $record['fingerprint'] = $fingerprint;

        $latest = self::db()->fetchOne(
            'SELECT id, revision_data FROM ' . \Metis_Tables::get( 'website_revisions' ) . ' WHERE entity_type = %s AND entity_id = %d ORDER BY id DESC LIMIT 1',
            [ $normalized_type, $entity_id ]
        );
        if ( is_array( $latest ) ) {
            $latest_decoded = self::decodeRevisionData( (string) ( $latest['revision_data'] ?? '' ) );
            $latest_hash = isset( $latest_decoded['fingerprint'] ) ? (string) $latest_decoded['fingerprint'] : '';
            if ( $latest_hash !== '' && hash_equals( $latest_hash, $fingerprint ) ) {
                return false;
            }
        }

        $inserted = self::db()->insert(
            \Metis_Tables::get( 'website_revisions' ),
            [
                'entity_type' => $normalized_type,
                'entity_id' => $entity_id,
                'revision_data' => self::jsonEncode( $record, JSON_UNESCAPED_SLASHES ),
                'revision_note' => metis_text_clean( $note ),
                'created_by' => self::currentUserId() ?: null,
                'created_at' => (string) \metis_current_time( 'mysql' ),
            ]
        );

        return (bool) $inserted;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function list( string $entity_type, int $entity_id, int $limit = 50 ): array {
        $normalized_type = self::normalizeEntityType( $entity_type );
        if ( $normalized_type === '' || $entity_id < 1 ) {
            return [];
        }

        $resolved_limit = max( 1, min( 100, $limit ) );
        $rows = self::db()->fetchAll(
            'SELECT id, revision_note, created_by, created_at, revision_data FROM ' . \Metis_Tables::get( 'website_revisions' ) . ' WHERE entity_type = %s AND entity_id = %d ORDER BY id DESC LIMIT %d',
            [ $normalized_type, $entity_id, $resolved_limit ]
        );
        if ( ! is_array( $rows ) ) {
            return [];
        }

        $items = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $revision_data = self::decodeRevisionData( (string) ( $row['revision_data'] ?? '' ) );
            $payload = isset( $revision_data['payload'] ) && is_array( $revision_data['payload'] ) ? $revision_data['payload'] : [];
            $items[] = [
                'id' => (int) ( $row['id'] ?? 0 ),
                'note' => (string) ( $row['revision_note'] ?? '' ),
                'created_by' => isset( $row['created_by'] ) ? (int) $row['created_by'] : 0,
                'created_at' => (string) ( $row['created_at'] ?? '' ),
                'status' => isset( $payload['status'] ) ? metis_key_clean( (string) $payload['status'] ) : '',
                'title' => isset( $payload['title'] ) ? (string) $payload['title'] : '',
            ];
        }
        return $items;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function payloadForRevision( string $entity_type, int $entity_id, int $revision_id ): ?array {
        $normalized_type = self::normalizeEntityType( $entity_type );
        if ( $normalized_type === '' || $entity_id < 1 || $revision_id < 1 ) {
            return null;
        }

        $row = self::db()->fetchOne(
            'SELECT revision_data FROM ' . \Metis_Tables::get( 'website_revisions' ) . ' WHERE id = %d AND entity_type = %s AND entity_id = %d LIMIT 1',
            [ $revision_id, $normalized_type, $entity_id ]
        );
        if ( ! is_array( $row ) ) {
            return null;
        }

        $decoded = self::decodeRevisionData( (string) ( $row['revision_data'] ?? '' ) );
        if ( ! isset( $decoded['payload'] ) || ! is_array( $decoded['payload'] ) ) {
            return null;
        }
        return $decoded['payload'];
    }

    private static function normalizeEntityType( string $entity_type ): string {
        $normalized = metis_key_clean( $entity_type );
        return in_array( $normalized, [ 'page', 'post', 'template' ], true ) ? $normalized : '';
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeRevisionData( string $raw ): array {
        if ( trim( $raw ) === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function currentUserId(): int {
        if ( ! function_exists( 'metis_current_user_id' ) ) {
            return 0;
        }
        return (int) metis_current_user_id();
    }

    private static function jsonEncode( mixed $value, int $flags = 0 ): string {
        if ( function_exists( 'metis_json_encode' ) ) {
            return (string) metis_json_encode( $value, $flags );
        }
        $json = json_encode( $value, $flags | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? $json : '{}';
    }

    private static function db(): object {
        return Application::service( 'db' );
    }
}
