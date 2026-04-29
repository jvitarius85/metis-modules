<?php
declare(strict_types=1);

namespace Metis\Modules\Cms\Services;

use Metis\Core\Application;
use Metis\Modules\Cms\Entities\WebPart;

/**
 * Web Part Service
 *
 * Provides reusable web part storage, targeting, visibility and rendering.
 */
final class WebPartService {
    /**
     * @return array<int,WebPart>
     */
    public static function getAll( array $filters = [] ): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'cms_web_parts' );
        $where = [];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = self::normalizeStatus( (string) $filters['status'] );
        }

        if ( ! empty( $filters['part_type'] ) ) {
            $where[] = 'part_type = %s';
            $params[] = metis_key_clean( (string) $filters['part_type'] );
        }

        if ( ! empty( $filters['target_scope'] ) ) {
            $where[] = 'target_scope = %s';
            $params[] = self::normalizeTargetScope( (string) $filters['target_scope'] );
        }

        $where_clause = $where !== [] ? ' WHERE ' . implode( ' AND ', $where ) : '';
        try {
            $rows = $db->fetchAll(
                "SELECT * FROM {$table}{$where_clause} ORDER BY sort_order ASC, id ASC",
                $params
            );
        } catch ( \Throwable $e ) {
            // Do not break public rendering when web parts schema is not yet installed.
            return [];
        }
        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_map( static fn ( array $row ): WebPart => WebPart::fromRow( $row ), $rows );
    }

    public static function getById( int $id ): ?WebPart {
        if ( $id < 1 ) {
            return null;
        }
        $row = self::db()->fetchOne(
            "SELECT * FROM " . \Metis_Tables::get( 'cms_web_parts' ) . " WHERE id = %d LIMIT 1",
            [ $id ]
        );
        return is_array( $row ) ? WebPart::fromRow( $row ) : null;
    }

    public static function create( array $data ): int|false {
        $payload = self::normalizePayload( $data, true );
        $payload['created_by'] = $data['created_by'] ?? self::getCurrentUserId();
        $payload['updated_by'] = $data['updated_by'] ?? self::getCurrentUserId();

        $result = self::db()->insert( \Metis_Tables::get( 'cms_web_parts' ), $payload );
        return $result ? (int) self::db()->lastInsertId() : false;
    }

    public static function update( int $id, array $data ): bool {
        if ( $id < 1 ) {
            return false;
        }

        $existing = self::getById( $id );
        if ( $existing === null ) {
            return false;
        }

        $payload = self::normalizePayload( array_merge( $existing->toArray(), $data ), false );
        $payload['updated_by'] = $data['updated_by'] ?? self::getCurrentUserId();

        return (bool) self::db()->update( \Metis_Tables::get( 'cms_web_parts' ), $payload, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        if ( $id < 1 ) {
            return false;
        }
        return (bool) self::db()->delete( \Metis_Tables::get( 'cms_web_parts' ), [ 'id' => $id ] );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function getRenderableForContext( array $context ): array {
        $parts = self::getAll( [ 'status' => 'published' ] );
        if ( $parts === [] ) {
            return [];
        }

        $out = [];
        foreach ( $parts as $part ) {
            if ( ! self::matchesTarget( $part, $context ) ) {
                continue;
            }
            if ( ! self::matchesVisibility( $part, $context ) ) {
                continue;
            }

            $html = self::renderPart( $part, $context );
            if ( trim( $html ) === '' ) {
                continue;
            }

            $out[] = [
                'id' => (int) ( $part->id ?? 0 ),
                'part_type' => $part->part_type,
                'region' => self::normalizeRegion( $part->region ),
                'slot' => self::normalizeSlot( $part->slot ),
                'sort_order' => (int) $part->sort_order,
                'html' => $html,
            ];
        }

        return $out;
    }

    private static function renderPart( WebPart $part, array $context ): string {
        $render_mode = self::normalizeRenderMode( $part->render_mode );
        if ( $render_mode === 'blocks' ) {
            $blocks = self::extractBlocks( $part->content_json );
            if ( $blocks === [] ) {
                return '';
            }

            $attributes = [
                'class' => 'metis-web-part metis-web-part-' . metis_escape_attr( $part->part_type ),
                'data-web-part-id' => (string) (int) ( $part->id ?? 0 ),
                'data-web-part-type' => $part->part_type,
            ];

            return '<div' . self::renderAttributes( $attributes ) . '>' . BlockRenderer::renderBlocks( $blocks, $context ) . '</div>';
        }

        return '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function extractBlocks( ?string $content_json ): array {
        if ( ! is_string( $content_json ) || trim( $content_json ) === '' ) {
            return [];
        }

        $decoded = json_decode( $content_json, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        if ( isset( $decoded['sections'] ) && is_array( $decoded['sections'] ) ) {
            $blocks = [];
            foreach ( $decoded['sections'] as $section ) {
                if ( ! is_array( $section ) || ! isset( $section['blocks'] ) || ! is_array( $section['blocks'] ) ) {
                    continue;
                }
                foreach ( $section['blocks'] as $block ) {
                    if ( is_array( $block ) ) {
                        $blocks[] = $block;
                    }
                }
            }
            return $blocks;
        }

        if ( isset( $decoded['blocks'] ) && is_array( $decoded['blocks'] ) ) {
            return array_values( array_filter( $decoded['blocks'], 'is_array' ) );
        }

        if ( isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
            return array_values( array_filter( $decoded, 'is_array' ) );
        }

        return [];
    }

    private static function matchesTarget( WebPart $part, array $context ): bool {
        $scope = self::normalizeTargetScope( $part->target_scope );
        if ( $scope === 'site' ) {
            return true;
        }

        $target_ref = metis_key_clean( (string) ( $part->target_ref ?? '' ) );
        if ( $target_ref === '' ) {
            return false;
        }

        if ( $scope === 'template' ) {
            return $target_ref === metis_key_clean( (string) ( $context['template_key'] ?? '' ) );
        }

        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        $slug = metis_slug_clean( (string) ( $context['slug'] ?? '' ) );
        $id = isset( $context['content_id'] ) ? (int) $context['content_id'] : 0;
        $code = metis_key_clean( (string) ( $context['content_code'] ?? '' ) );

        if ( $scope === 'page' ) {
            if ( $content_type !== 'page' ) {
                return false;
            }
            return $target_ref === $slug || $target_ref === $code || ( $id > 0 && $target_ref === (string) $id );
        }

        if ( $scope === 'post' ) {
            if ( $content_type !== 'post' ) {
                return false;
            }
            return $target_ref === $slug || $target_ref === $code || ( $id > 0 && $target_ref === (string) $id );
        }

        return false;
    }

    private static function matchesVisibility( WebPart $part, array $context ): bool {
        $rules = self::decodeJsonArray( $part->visibility_json );
        if ( $rules === [] || ! empty( $rules['site_wide'] ) ) {
            return true;
        }

        $path = (string) ( $context['path'] ?? '/' );
        $slug = metis_slug_clean( (string) ( $context['slug'] ?? '' ) );
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );

        foreach ( (array) ( $rules['exclude_paths'] ?? [] ) as $candidate ) {
            if ( trim( (string) $candidate ) === $path ) {
                return false;
            }
        }

        foreach ( (array) ( $rules['exclude_slugs'] ?? [] ) as $candidate ) {
            if ( metis_slug_clean( (string) $candidate ) === $slug && $slug !== '' ) {
                return false;
            }
        }

        foreach ( (array) ( $rules['exclude_content_types'] ?? [] ) as $candidate ) {
            if ( metis_key_clean( (string) $candidate ) === $content_type && $content_type !== '' ) {
                return false;
            }
        }

        foreach ( (array) ( $rules['paths'] ?? [] ) as $candidate ) {
            if ( trim( (string) $candidate ) === $path ) {
                return true;
            }
        }

        foreach ( (array) ( $rules['slugs'] ?? [] ) as $candidate ) {
            if ( metis_slug_clean( (string) $candidate ) === $slug && $slug !== '' ) {
                return true;
            }
        }

        foreach ( (array) ( $rules['content_types'] ?? [] ) as $candidate ) {
            if ( metis_key_clean( (string) $candidate ) === $content_type && $content_type !== '' ) {
                return true;
            }
        }

        return empty( $rules['paths'] ) && empty( $rules['slugs'] ) && empty( $rules['content_types'] );
    }

    private static function normalizePayload( array $data, bool $for_create ): array {
        $name = trim( metis_text_clean( (string) ( $data['name'] ?? '' ) ) );
        if ( $name === '' ) {
            $name = 'Untitled Web Part';
        }

        $content_json = self::normalizeContentJson( $data['content_json'] ?? null );

        $payload = [
            'name' => $name,
            'part_type' => metis_key_clean( (string) ( $data['part_type'] ?? 'custom' ) ),
            'render_mode' => self::normalizeRenderMode( (string) ( $data['render_mode'] ?? 'blocks' ) ),
            'status' => self::normalizeStatus( (string) ( $data['status'] ?? 'draft' ) ),
            'content_json' => $content_json,
            'config_json' => self::jsonEncode( self::decodeJsonArray( $data['config_json'] ?? null ) ),
            'visibility_json' => self::jsonEncode( self::normalizeVisibilityRules( self::decodeJsonArray( $data['visibility_json'] ?? null ) ) ),
            'target_scope' => self::normalizeTargetScope( (string) ( $data['target_scope'] ?? 'site' ) ),
            'target_ref' => self::normalizeTargetRef( (string) ( $data['target_ref'] ?? '' ) ),
            'region' => self::normalizeRegion( (string) ( $data['region'] ?? 'main' ) ),
            'slot' => self::normalizeSlot( (string) ( $data['slot'] ?? 'append' ) ),
            'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
        ];

        if ( $for_create ) {
            if ( function_exists( 'metis_entity_id_service' ) ) {
                $payload = \metis_entity_id_service()->assignForInsert( 'cms_web_part', $payload );
            } else {
                $payload['part_code'] = \metis_generate_code( 'WWP', \Metis_Tables::get( 'cms_web_parts' ), 'part_code' );
            }
        }

        return $payload;
    }

    private static function normalizeContentJson( mixed $raw ): string {
        if ( is_string( $raw ) && trim( $raw ) !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                return self::jsonEncode( $decoded );
            }
        }

        if ( is_array( $raw ) ) {
            return self::jsonEncode( $raw );
        }

        return self::jsonEncode( [ 'version' => 1, 'blocks' => [] ] );
    }

    private static function normalizeTargetScope( string $value ): string {
        $scope = metis_key_clean( $value );
        return in_array( $scope, [ 'site', 'template', 'page', 'post' ], true ) ? $scope : 'site';
    }

    private static function normalizeStatus( string $value ): string {
        $status = metis_key_clean( $value );
        return in_array( $status, [ 'draft', 'published' ], true ) ? $status : 'draft';
    }

    private static function normalizeRenderMode( string $value ): string {
        $mode = metis_key_clean( $value );
        return in_array( $mode, [ 'blocks' ], true ) ? $mode : 'blocks';
    }

    private static function normalizeRegion( string $value ): string {
        $region = metis_key_clean( $value );
        return in_array( $region, [ 'body', 'header', 'main', 'sidebar', 'footer', 'banners' ], true ) ? $region : 'main';
    }

    private static function normalizeSlot( string $value ): string {
        $slot = metis_key_clean( $value );
        return in_array( $slot, [ 'before', 'prepend', 'append', 'after' ], true ) ? $slot : 'append';
    }

    private static function normalizeTargetRef( string $value ): ?string {
        $trimmed = trim( $value );
        if ( $trimmed === '' ) {
            return null;
        }
        return metis_key_clean( $trimmed );
    }

    private static function normalizeVisibilityRules( array $rules ): array {
        $out = [
            'site_wide' => ! empty( $rules['site_wide'] ),
            'paths' => [],
            'slugs' => [],
            'content_types' => [],
            'exclude_paths' => [],
            'exclude_slugs' => [],
            'exclude_content_types' => [],
        ];

        foreach ( [ 'paths', 'exclude_paths' ] as $key ) {
            foreach ( (array) ( $rules[ $key ] ?? [] ) as $candidate ) {
                $path = trim( (string) $candidate );
                if ( $path === '' ) {
                    continue;
                }
                if ( $path[0] !== '/' ) {
                    $path = '/' . $path;
                }
                $out[ $key ][] = $path;
            }
            $out[ $key ] = array_values( array_unique( $out[ $key ] ) );
        }

        foreach ( [ 'slugs', 'exclude_slugs' ] as $key ) {
            foreach ( (array) ( $rules[ $key ] ?? [] ) as $candidate ) {
                $slug = metis_slug_clean( (string) $candidate );
                if ( $slug !== '' ) {
                    $out[ $key ][] = $slug;
                }
            }
            $out[ $key ] = array_values( array_unique( $out[ $key ] ) );
        }

        foreach ( [ 'content_types', 'exclude_content_types' ] as $key ) {
            foreach ( (array) ( $rules[ $key ] ?? [] ) as $candidate ) {
                $type = metis_key_clean( (string) $candidate );
                if ( $type !== '' ) {
                    $out[ $key ][] = $type;
                }
            }
            $out[ $key ] = array_values( array_unique( $out[ $key ] ) );
        }

        return $out;
    }

    private static function decodeJsonArray( mixed $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( ! is_string( $value ) || trim( $value ) === '' ) {
            return [];
        }
        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function renderAttributes( array $attributes ): string {
        $parts = [];
        foreach ( $attributes as $key => $value ) {
            $attr_key = preg_replace( '/[^a-zA-Z0-9_:-]/', '', (string) $key );
            if ( ! is_string( $attr_key ) || $attr_key === '' ) {
                continue;
            }
            $parts[] = ' ' . $attr_key . '="' . metis_escape_attr( (string) $value ) . '"';
        }
        return implode( '', $parts );
    }

    private static function getCurrentUserId(): ?int {
        if ( ! function_exists( 'metis_current_user_id' ) ) {
            return null;
        }
        $uid = metis_current_user_id();
        return $uid > 0 ? $uid : null;
    }

    private static function db(): object {
        return Application::service( 'db' );
    }

    private static function jsonEncode( mixed $value, int $flags = 0 ): string {
        if ( function_exists( 'metis_json_encode' ) ) {
            return (string) metis_json_encode( $value, $flags );
        }
        $json = json_encode( $value, $flags | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? $json : '{}';
    }
}
