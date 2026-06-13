<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;

/**
 * Popup Service — manages website modal popups.
 */
final class PopupService {

    public static function getAll( array $filters = [] ): array {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'website_popups' );
        $where  = [];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $filters['status'];
        }

        $where_clause = $where !== [] ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $rows         = $db->fetchAll( "SELECT * FROM {$table}{$where_clause} ORDER BY name ASC", $params );
        return is_array( $rows ) ? $rows : [];
    }

    public static function getById( int $id ): ?array {
        $row = self::db()->fetchOne( "SELECT * FROM " . \Metis_Tables::get( 'website_popups' ) . " WHERE id = %d", [ $id ] );
        return is_array( $row ) ? $row : null;
    }

    public static function create( array $data ): int|false {
        $payload = self::normalizePayload( $data, true );
        $payload['created_by'] = $data['created_by'] ?? self::getCurrentUserId();
        $payload['updated_by'] = $data['updated_by'] ?? self::getCurrentUserId();

        $result = self::db()->insert( \Metis_Tables::get( 'website_popups' ), $payload );
        return $result ? (int) self::db()->lastInsertId() : false;
    }

    public static function update( int $id, array $data ): bool {
        $update = self::normalizePayload( $data, false );
        $update['updated_by'] = $data['updated_by'] ?? self::getCurrentUserId();

        if ( $update === [] ) {
            return false;
        }

        return self::db()->update( \Metis_Tables::get( 'website_popups' ), $update, [ 'id' => $id ] ) !== false;
    }

    public static function delete( int $id ): bool {
        return (bool) self::db()->delete( \Metis_Tables::get( 'website_popups' ), [ 'id' => $id ] );
    }

    public static function getActiveForContext( array $context ): array {
        $rows = self::getAll( [ 'status' => 'published' ] );
        if ( $rows === [] ) {
            return [];
        }

        $active = [];
        foreach ( $rows as $row ) {
            if ( ! self::matchesDisplayRules( $row, $context ) ) {
                continue;
            }
            $active[] = $row;
        }
        return $active;
    }

    private static function matchesDisplayRules( array $popup, array $context ): bool {
        $rules = self::decodeJsonArray( $popup['display_rules_json'] ?? null );
        if ( $rules === [] ) {
            return true;
        }

        if ( ! empty( $rules['site_wide'] ) ) {
            return true;
        }

        $path = (string) ( $context['path'] ?? '/' );
        $slug = metis_slug_clean( (string) ( $context['slug'] ?? '' ) );
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );

        foreach ( (array) ( $rules['paths'] ?? [] ) as $candidate ) {
            $value = trim( (string) $candidate );
            if ( $value !== '' && $value === $path ) {
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

        return false;
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

    private static function normalizePayload( array $data, bool $for_create ): array {
        $out = [];

        if ( $for_create || array_key_exists( 'name', $data ) ) {
            $name = trim( metis_text_clean( (string) ( $data['name'] ?? '' ) ) );
            $out['name'] = $name !== '' ? $name : 'Untitled Popup';
        }

        if ( $for_create || array_key_exists( 'trigger_type', $data ) ) {
            $trigger_type = metis_key_clean( (string) ( $data['trigger_type'] ?? 'click' ) );
            if ( ! in_array( $trigger_type, [ 'click', 'delay', 'load', 'scroll', 'exit' ], true ) ) {
                $trigger_type = 'click';
            }
            $out['trigger_type'] = $trigger_type;
        }

        if ( $for_create || array_key_exists( 'status', $data ) ) {
            $status = metis_key_clean( (string) ( $data['status'] ?? 'draft' ) );
            if ( ! in_array( $status, [ 'draft', 'published' ], true ) ) {
                $status = 'draft';
            }
            $out['status'] = $status;
        }

        if ( $for_create || array_key_exists( 'trigger_config_json', $data ) ) {
            $trigger_config = self::decodeJsonArray( $data['trigger_config_json'] ?? null );
            $frequency = metis_key_clean( (string) ( $trigger_config['frequency'] ?? 'session' ) );
            if ( ! in_array( $frequency, [ 'session', 'persisted', 'always' ], true ) ) {
                $frequency = 'session';
            }
            $click_mode = metis_key_clean( (string) ( $trigger_config['click_mode'] ?? 'page_button' ) );
            if ( ! in_array( $click_mode, [ 'page_button', 'floating_button' ], true ) ) {
                $click_mode = 'page_button';
            }
            $launcher_position = metis_key_clean( (string) ( $trigger_config['launcher_position'] ?? 'bottom_right' ) );
            if ( ! in_array( $launcher_position, [ 'top_left', 'top_right', 'bottom_left', 'bottom_right' ], true ) ) {
                $launcher_position = 'bottom_right';
            }
            $launcher_color_key = metis_key_clean( (string) ( $trigger_config['launcher_color_key'] ?? 'metis_primary' ) );
            if ( ! in_array( $launcher_color_key, [ 'metis_primary', 'metis_accent', 'metis_text', 'metis_surface' ], true ) ) {
                $launcher_color_key = 'metis_primary';
            }
            $launcher_text_color_key = metis_key_clean( (string) ( $trigger_config['launcher_text_color_key'] ?? '' ) );
            if ( $launcher_text_color_key !== '' && ! in_array( $launcher_text_color_key, [ 'metis_primary', 'metis_accent', 'metis_text', 'metis_surface' ], true ) ) {
                $launcher_text_color_key = '';
            }
            $launcher_layout = metis_key_clean( (string) ( $trigger_config['launcher_layout'] ?? 'full' ) );
            if ( ! in_array( $launcher_layout, [ 'full', 'icon' ], true ) ) {
                $launcher_layout = 'full';
            }
            $launcher_style = metis_key_clean( (string) ( $trigger_config['launcher_style'] ?? 'solid' ) );
            if ( ! in_array( $launcher_style, [ 'solid', 'soft', 'outline' ], true ) ) {
                $launcher_style = 'solid';
            }
            $launcher_shape = metis_key_clean( (string) ( $trigger_config['launcher_shape'] ?? 'pill' ) );
            if ( ! in_array( $launcher_shape, [ 'pill', 'rounded', 'square' ], true ) ) {
                $launcher_shape = 'pill';
            }
            $launcher_border_width = metis_key_clean( (string) ( $trigger_config['launcher_border_width'] ?? 'thin' ) );
            if ( ! in_array( $launcher_border_width, [ 'none', 'thin', 'regular', 'thick' ], true ) ) {
                $launcher_border_width = 'thin';
            }
            $launcher_border_effect = metis_key_clean( (string) ( $trigger_config['launcher_border_effect'] ?? 'single' ) );
            if ( ! in_array( $launcher_border_effect, [ 'single', 'double_ring' ], true ) ) {
                $launcher_border_effect = 'single';
            }
            $launcher_icon = str_replace( '_', '-', metis_key_clean( str_replace( '-', '_', (string) ( $trigger_config['launcher_icon'] ?? '' ) ) ) );
            if ( $launcher_icon !== '' && function_exists( 'metis_navigation_svg_icon_path' ) && metis_navigation_svg_icon_path( $launcher_icon ) === '' ) {
                $launcher_icon = '';
            }
            $launcher_label = trim( metis_text_clean( (string) ( $trigger_config['launcher_label'] ?? 'Open popup' ) ) );
            if ( $launcher_label === '' ) {
                $launcher_label = 'Open popup';
            }
            $delay_ms = max( 0, (int) ( $trigger_config['delay_ms'] ?? 1500 ) );
            $scroll_percent = max( 1, min( 100, (int) ( $trigger_config['scroll_percent'] ?? 50 ) ) );

            $out['trigger_config_json'] = self::jsonEncode( [
                'frequency' => $frequency,
                'click_mode' => $click_mode,
                'launcher_position' => $launcher_position,
                'launcher_color_key' => $launcher_color_key,
                'launcher_text_color_key' => $launcher_text_color_key,
                'launcher_layout' => $launcher_layout,
                'launcher_style' => $launcher_style,
                'launcher_shape' => $launcher_shape,
                'launcher_border_width' => $launcher_border_width,
                'launcher_border_effect' => $launcher_border_effect,
                'launcher_icon' => $launcher_icon,
                'launcher_label' => $launcher_label,
                'delay_ms' => $delay_ms,
                'scroll_percent' => $scroll_percent,
            ] );
        }

        if ( $for_create || array_key_exists( 'display_rules_json', $data ) ) {
            $display_rules = self::decodeJsonArray( $data['display_rules_json'] ?? null );
            $paths = [];
            foreach ( (array) ( $display_rules['paths'] ?? [] ) as $candidate ) {
                $path = trim( (string) $candidate );
                if ( $path === '' ) {
                    continue;
                }
                if ( $path[0] !== '/' ) {
                    $path = '/' . $path;
                }
                $paths[] = $path;
            }

            $slugs = [];
            foreach ( (array) ( $display_rules['slugs'] ?? [] ) as $candidate ) {
                $slug = metis_slug_clean( (string) $candidate );
                if ( $slug !== '' ) {
                    $slugs[] = $slug;
                }
            }

            $types = [];
            foreach ( (array) ( $display_rules['content_types'] ?? [] ) as $candidate ) {
                $type = metis_key_clean( (string) $candidate );
                if ( $type !== '' ) {
                    $types[] = $type;
                }
            }

            $out['display_rules_json'] = self::jsonEncode( [
                'site_wide' => ! empty( $display_rules['site_wide'] ),
                'paths' => array_values( array_unique( $paths ) ),
                'slugs' => array_values( array_unique( $slugs ) ),
                'content_types' => array_values( array_unique( $types ) ),
            ] );
        }

        if ( $for_create || array_key_exists( 'layout_json', $data ) ) {
            $layout_raw = is_string( $data['layout_json'] ?? null ) ? trim( (string) $data['layout_json'] ) : '';
            if ( $layout_raw === '' ) {
                $out['layout_json'] = self::jsonEncode( [ 'version' => 1, 'sections' => [] ] );
            } else {
                $layout = json_decode( $layout_raw, true );
                $out['layout_json'] = is_array( $layout )
                    ? self::jsonEncode( $layout )
                    : self::jsonEncode( [ 'version' => 1, 'sections' => [] ] );
            }
        }

        return $out;
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
