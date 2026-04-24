<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;

/**
 * Reusable Block Service
 *
 * Stores and retrieves reusable editor blocks in website_blocks.
 */
final class ReusableBlockService {
    /**
     * @return array<int,array<string,mixed>>
     */
    public static function listForContext( string $context, string $render_mode = '', string $search = '', int $limit = 200 ): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'website_blocks' );
        $rows = $db->fetchAll(
            "SELECT id, block_code, name, type, block_json, category, is_global, updated_at
             FROM {$table}
             ORDER BY updated_at DESC, id DESC
             LIMIT " . max( 1, min( 500, $limit ) )
        );
        if ( ! is_array( $rows ) || $rows === [] ) {
            return [];
        }

        $normalized_context = EditorContextPolicy::normalizeContext( $context );
        $normalized_mode = EditorContextPolicy::normalizeRenderMode( $render_mode, $normalized_context );
        $registry = EditorContextPolicy::filterRegistry( \Metis\Modules\Website\BlockRegistry::all(), $normalized_context, $normalized_mode );
        $allowed_types = array_fill_keys( array_keys( $registry ), true );
        $query = trim( strtolower( $search ) );

        $items = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $block = self::decodeBlock( $row['block_json'] ?? null );
            if ( $block === null ) {
                continue;
            }
            $type = metis_key_clean( (string) ( $block['type'] ?? $row['type'] ?? '' ) );
            if ( $type === '' || ! isset( $allowed_types[ $type ] ) ) {
                continue;
            }
            $valid = EditorContextPolicy::validateBlocks( [ $block ], $normalized_context, $normalized_mode );
            if ( ! (bool) ( $valid['valid'] ?? false ) ) {
                continue;
            }

            $name = trim( (string) ( $row['name'] ?? '' ) );
            $label = self::blockLabel( $name, $type );
            if ( $query !== '' ) {
                $haystack = strtolower( $label . ' ' . str_replace( '_', ' ', $type ) . ' ' . (string) ( $row['category'] ?? '' ) );
                if ( strpos( $haystack, $query ) === false ) {
                    continue;
                }
            }

            $items[] = [
                'id' => (int) ( $row['id'] ?? 0 ),
                'block_code' => (string) ( $row['block_code'] ?? '' ),
                'name' => $label,
                'type' => $type,
                'category' => metis_key_clean( (string) ( $row['category'] ?? 'custom' ) ),
                'is_global' => ! empty( $row['is_global'] ) ? 1 : 0,
                'updated_at' => (string) ( $row['updated_at'] ?? '' ),
                'block' => $block,
            ];
        }

        return $items;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getByCode( string $block_code ): ?array {
        $code = self::cleanCode( $block_code );
        if ( $code === '' ) {
            return null;
        }
        $row = self::db()->fetchOne(
            "SELECT id, block_code, name, type, block_json, category, is_global, created_by, updated_by, created_at, updated_at
             FROM " . \Metis_Tables::get( 'website_blocks' ) . " WHERE block_code = %s LIMIT 1",
            [ $code ]
        );
        if ( ! is_array( $row ) ) {
            return null;
        }
        $row['block'] = self::decodeBlock( $row['block_json'] ?? null );
        return $row;
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>|null
     */
    public static function save( array $block, string $name, string $category = 'custom', bool $is_global = true, string $block_code = '' ): ?array {
        $normalized_block = self::normalizeBlockForStorage( $block );
        if ( $normalized_block === null ) {
            return null;
        }

        $clean_name = trim( metis_text_clean( $name ) );
        if ( $clean_name === '' ) {
            $clean_name = self::blockLabel( '', (string) ( $normalized_block['type'] ?? '' ) );
        }
        $clean_category = metis_key_clean( $category );
        if ( $clean_category === '' ) {
            $clean_category = 'custom';
        }

        $table = \Metis_Tables::get( 'website_blocks' );
        $payload = [
            'name' => $clean_name,
            'type' => metis_key_clean( (string) ( $normalized_block['type'] ?? '' ) ),
            'block_json' => self::jsonEncode( $normalized_block, JSON_UNESCAPED_SLASHES ),
            'category' => $clean_category,
            'is_global' => $is_global ? 1 : 0,
            'updated_by' => self::currentUserId(),
        ];

        $code = self::cleanCode( $block_code );
        if ( $code !== '' ) {
            $existing = self::getByCode( $code );
            if ( $existing !== null ) {
                $ok = (bool) self::db()->update( $table, $payload, [ 'block_code' => $code ] );
                if ( ! $ok ) {
                    return null;
                }
                return self::getByCode( $code );
            }
        }

        if ( function_exists( 'metis_entity_id_service' ) ) {
            $payload = \metis_entity_id_service()->assignForInsert( 'website_block', $payload );
        }
        if ( empty( $payload['block_code'] ) ) {
            $payload['block_code'] = function_exists( 'metis_generate_code' )
                ? \metis_generate_code( 'WBL', $table, 'block_code' )
                : self::fallbackCode();
        }
        $payload['created_by'] = self::currentUserId();

        $inserted = self::db()->insert( $table, $payload );
        if ( ! $inserted ) {
            return null;
        }
        $id = (int) self::db()->lastInsertId();
        if ( function_exists( 'metis_entity_id_service' ) ) {
            \metis_entity_id_service()->register( 'website_block', $id, (string) ( $payload['block_code'] ?? '' ) );
        }

        return self::getByCode( (string) ( $payload['block_code'] ?? '' ) );
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>|null
     */
    private static function decodeBlock( $raw ): ?array {
        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return null;
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $block
     * @return array<string,mixed>|null
     */
    private static function normalizeBlockForStorage( array $block ): ?array {
        $type = metis_key_clean( (string) ( $block['type'] ?? '' ) );
        if ( $type === '' ) {
            return null;
        }
        $out = $block;
        $out['type'] = $type;
        unset( $out['id'] );
        if ( ! isset( $out['data'] ) || ! is_array( $out['data'] ) ) {
            $out['data'] = [];
        }
        if ( ! isset( $out['style'] ) || ! is_array( $out['style'] ) ) {
            $out['style'] = [];
        }
        return $out;
    }

    private static function cleanCode( string $code ): string {
        $value = strtoupper( trim( $code ) );
        if ( $value === '' ) {
            return '';
        }
        return preg_match( '/^[A-Z0-9_-]{4,32}$/', $value ) === 1 ? $value : '';
    }

    private static function blockLabel( string $name, string $type ): string {
        $clean = trim( $name );
        if ( $clean !== '' ) {
            return $clean;
        }
        $text = str_replace( '_', ' ', trim( $type ) );
        $text = preg_replace( '/\s+/', ' ', $text ) ?? '';
        $text = trim( (string) $text );
        if ( $text === '' ) {
            return 'Reusable Block';
        }
        return ucwords( $text );
    }

    private static function fallbackCode(): string {
        return 'WBL-' . strtoupper( substr( md5( uniqid( '', true ) ), 0, 10 ) );
    }

    private static function currentUserId(): ?int {
        if ( ! function_exists( 'metis_current_user_id' ) ) {
            return null;
        }
        $uid = metis_current_user_id();
        return $uid > 0 ? $uid : null;
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
