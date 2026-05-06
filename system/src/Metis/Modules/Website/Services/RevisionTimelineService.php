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

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<int,array{field:string,label:string,before:string,after:string,changed:bool,kind:string}>
     */
    public static function comparePayloads( string $entity_type, array $before, array $after ): array {
        $normalized_type = self::normalizeEntityType( $entity_type );
        if ( $normalized_type === '' ) {
            return [];
        }

        $rows = [];
        if ( $normalized_type === 'template' ) {
            self::addScalarDiff( $rows, 'name', 'Name', $before, $after );
            self::addScalarDiff( $rows, 'status', 'Status', $before, $after );
            self::addScalarDiff( $rows, 'template_key', 'Template Key', $before, $after );
            self::addLayoutDiff( $rows, 'structure_json', 'Template Structure', $before, $after, $normalized_type );
            return $rows;
        }

        self::addScalarDiff( $rows, 'title', 'Title', $before, $after );
        self::addScalarDiff( $rows, 'slug', 'Slug / Path', $before, $after );
        self::addScalarDiff( $rows, 'status', 'Status', $before, $after );
        self::addScalarDiff( $rows, 'template_key', 'Template', $before, $after );
        if ( $normalized_type === 'post' ) {
            self::addScalarDiff( $rows, 'excerpt', 'Excerpt', $before, $after );
            self::addLayoutDiff( $rows, 'content_json', 'Structured Blocks', $before, $after, $normalized_type );
        } else {
            self::addLayoutDiff( $rows, 'layout_json', 'Structured Blocks', $before, $after, $normalized_type );
        }
        self::addSeoDiffs( $rows, $before, $after );

        return $rows;
    }

    private static function normalizeEntityType( string $entity_type ): string {
        $normalized = metis_key_clean( $entity_type );
        return in_array( $normalized, [ 'page', 'post', 'template' ], true ) ? $normalized : '';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private static function addScalarDiff( array &$rows, string $field, string $label, array $before, array $after ): void {
        $before_value = self::stringValue( $before[ $field ] ?? '' );
        $after_value = self::stringValue( $after[ $field ] ?? '' );
        if ( $before_value === '' && $after_value === '' ) {
            return;
        }
        $rows[] = [
            'field' => $field,
            'label' => $label,
            'before' => $before_value,
            'after' => $after_value,
            'changed' => $before_value !== $after_value,
            'kind' => 'text',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private static function addLayoutDiff( array &$rows, string $field, string $label, array $before, array $after, string $entity_type ): void {
        $before_raw = self::stringValue( $before[ $field ] ?? '' );
        $after_raw = self::stringValue( $after[ $field ] ?? '' );
        $before_summary = self::layoutSummary( $before_raw, $entity_type );
        $after_summary = self::layoutSummary( $after_raw, $entity_type );
        $before_hash = $before_raw !== '' ? sha1( $before_raw ) : '';
        $after_hash = $after_raw !== '' ? sha1( $after_raw ) : '';
        $rows[] = [
            'field' => $field,
            'label' => $label,
            'before' => $before_summary,
            'after' => $after_summary,
            'changed' => $before_hash !== $after_hash,
            'kind' => 'layout',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private static function addSeoDiffs( array &$rows, array $before, array $after ): void {
        $before_seo = self::decodeJsonObject( self::stringValue( $before['seo_meta_json'] ?? '' ) );
        $after_seo = self::decodeJsonObject( self::stringValue( $after['seo_meta_json'] ?? '' ) );
        foreach ( [
            'title' => 'SEO Title',
            'description' => 'SEO Description',
            'og_title' => 'Open Graph Title',
            'og_description' => 'Open Graph Description',
            'canonical_url' => 'Canonical URL',
        ] as $field => $label ) {
            $before_value = self::stringValue( $before_seo[ $field ] ?? '' );
            $after_value = self::stringValue( $after_seo[ $field ] ?? '' );
            if ( $before_value === '' && $after_value === '' ) {
                continue;
            }
            $rows[] = [
                'field' => 'seo.' . $field,
                'label' => $label,
                'before' => $before_value,
                'after' => $after_value,
                'changed' => $before_value !== $after_value,
                'kind' => 'seo',
            ];
        }
    }

    private static function layoutSummary( string $raw, string $entity_type ): string {
        $decoded = self::decodeJsonObject( $raw );
        if ( $decoded === [] ) {
            return 'No structured content';
        }

        $sections = [];
        if ( $entity_type === 'template' ) {
            $sections = is_array( $decoded['sections'] ?? null ) ? $decoded['sections'] : [];
            if ( $sections === [] && is_array( $decoded['regions'] ?? null ) ) {
                foreach ( $decoded['regions'] as $region ) {
                    if ( is_array( $region ) && is_array( $region['blocks'] ?? null ) ) {
                        foreach ( $region['blocks'] as $block ) {
                            if ( is_array( $block ) ) {
                                $sections[] = $block;
                            }
                        }
                    }
                }
            }
        } else {
            $meta = StructuredWebsiteBuilderService::structuredMetaFromDecodedLayout( $decoded );
            $sections = is_array( $meta['sections'] ?? null ) ? $meta['sections'] : [];
        }

        $counts = [];
        $labels = [];
        foreach ( $sections as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }
            $type = metis_key_clean( (string) ( $section['type'] ?? 'block' ) );
            if ( $type === '' ) {
                $type = 'block';
            }
            $counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
            if ( count( $labels ) < 8 ) {
                $labels[] = self::blockSummaryLabel( $section, $type );
            }
        }
        if ( $sections === [] ) {
            return 'No blocks';
        }

        $count_parts = [];
        foreach ( $counts as $type => $count ) {
            $count_parts[] = str_replace( '_', ' ', $type ) . ': ' . (string) $count;
        }

        return count( $sections ) . ' block' . ( count( $sections ) === 1 ? '' : 's' )
            . ' (' . implode( ', ', $count_parts ) . ')'
            . ( $labels !== [] ? "\n" . implode( "\n", $labels ) : '' );
    }

    /**
     * @param array<string,mixed> $section
     */
    private static function blockSummaryLabel( array $section, string $type ): string {
        $content = is_array( $section['content'] ?? null ) ? $section['content'] : ( is_array( $section['data'] ?? null ) ? $section['data'] : [] );
        $candidates = [
            $section['header'] ?? null,
            $content['title'] ?? null,
            $content['text'] ?? null,
            $content['label'] ?? null,
            $content['headline'] ?? null,
        ];
        $label = '';
        foreach ( $candidates as $candidate ) {
            $value = self::stringValue( $candidate );
            if ( $value !== '' ) {
                $label = $value;
                break;
            }
        }
        if ( $label === '' ) {
            $label = ucwords( str_replace( '_', ' ', $type ) );
        }
        if ( function_exists( 'mb_substr' ) ) {
            $label = mb_substr( $label, 0, 80 );
        } else {
            $label = substr( $label, 0, 80 );
        }
        return '- ' . ucwords( str_replace( '_', ' ', $type ) ) . ': ' . $label;
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeJsonObject( string $raw ): array {
        if ( trim( $raw ) === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function stringValue( mixed $value ): string {
        if ( is_scalar( $value ) ) {
            return trim( (string) $value );
        }
        if ( $value === null ) {
            return '';
        }
        return self::jsonEncode( $value, JSON_UNESCAPED_SLASHES );
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
