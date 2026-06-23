<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use DateTimeImmutable;
use DateTimeZone;
use Metis\Core\Application;

/**
 * Banner Service — scheduled and targeted public banners.
 */
final class BannerService {

    public static function getAll( array $filters = [] ): array {
        $db     = self::db();
        $table  = \Metis_Tables::get( 'website_banners' );
        $where  = [];
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = (string) $filters['status'];
        }

        if ( ! empty( $filters['type'] ) ) {
            $where[]  = 'type = %s';
            $params[] = (string) $filters['type'];
        }

        $where_clause = $where !== [] ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $rows = $db->fetchAll( "SELECT * FROM {$table}{$where_clause} ORDER BY sort_order ASC, id DESC", $params );
        return is_array( $rows ) ? $rows : [];
    }

    public static function getById( int $id ): ?array {
        $row = self::db()->fetchOne(
            'SELECT * FROM ' . \Metis_Tables::get( 'website_banners' ) . ' WHERE id = %d LIMIT 1',
            [ $id ]
        );
        return is_array( $row ) ? $row : null;
    }

    public static function create( array $data ): int|false {
        $normalized = self::normalizePayload( $data, true );
        $normalized['created_by']  = $data['created_by'] ?? self::currentUserId();
        $normalized['updated_by']  = $data['updated_by'] ?? self::currentUserId();
        if ( function_exists( 'metis_entity_id_service' ) ) {
            $normalized = \metis_entity_id_service()->assignForInsert( 'website_banner', $normalized );
        } else {
            $normalized['banner_code'] = \metis_generate_code( 'WBN', \Metis_Tables::get( 'website_banners' ), 'banner_code' );
        }

        $ok = self::db()->insert( \Metis_Tables::get( 'website_banners' ), $normalized );
        if ( ! $ok ) {
            return false;
        }

        $id = (int) self::db()->lastInsertId();
        if ( $id > 0 && ! empty( $normalized['banner_code'] ) ) {
            if ( function_exists( 'metis_entity_id_service' ) ) {
                \metis_entity_id_service()->register( 'website_banner', $id, (string) $normalized['banner_code'] );
            }
        }
        return $id > 0 ? $id : false;
    }

    public static function update( int $id, array $data ): bool {
        $update = self::normalizePayload( $data, false );
        $update['updated_by'] = $data['updated_by'] ?? self::currentUserId();

        if ( $update === [] ) {
            return false;
        }

        return self::db()->update( \Metis_Tables::get( 'website_banners' ), $update, [ 'id' => $id ] ) !== false;
    }

    public static function delete( int $id ): bool {
        return (bool) self::db()->delete( \Metis_Tables::get( 'website_banners' ), [ 'id' => $id ] );
    }

    public static function getActiveForContext( array $context ): array {
        $candidates = self::getAll( [ 'status' => 'published' ] );
        if ( $candidates === [] ) {
            return [];
        }

        $active = [];
        foreach ( $candidates as $banner ) {
            if ( ! self::isWithinSchedule( $banner ) ) {
                continue;
            }
            if ( ! self::matchesTargeting( $banner, $context ) ) {
                continue;
            }
            $active[] = $banner;
        }

        return $active;
    }

    private static function isWithinSchedule( array $banner ): bool {
        $timezone = self::safeTimezone( (string) ( $banner['timezone'] ?? 'UTC' ) );
        $now = new DateTimeImmutable( 'now', $timezone );
        $start_raw = trim( (string) ( $banner['start_at'] ?? '' ) );
        $end_raw = trim( (string) ( $banner['end_at'] ?? '' ) );

        if ( $start_raw !== '' ) {
            try {
                $start = new DateTimeImmutable( $start_raw, $timezone );
                if ( $now < $start ) {
                    return false;
                }
            } catch ( \Throwable ) {
                return false;
            }
        }

        if ( $end_raw !== '' ) {
            try {
                $end = new DateTimeImmutable( $end_raw, $timezone );
                if ( $now > $end ) {
                    return false;
                }
            } catch ( \Throwable ) {
                return false;
            }
        }

        return true;
    }

    private static function matchesTargeting( array $banner, array $context ): bool {
        $targeting = self::jsonDecodeArray( $banner['targeting_json'] ?? null );
        if ( $targeting === [] ) {
            return true;
        }

        if ( ! empty( $targeting['site_wide'] ) ) {
            return true;
        }

        $path = (string) ( $context['path'] ?? '/' );
        $slug = (string) ( $context['slug'] ?? '' );
        $content_type = (string) ( $context['content_type'] ?? '' );

        if ( ! empty( $targeting['paths'] ) && is_array( $targeting['paths'] ) ) {
            foreach ( $targeting['paths'] as $candidate ) {
                if ( (string) $candidate === $path ) {
                    return true;
                }
            }
        }

        if ( $slug !== '' && ! empty( $targeting['slugs'] ) && is_array( $targeting['slugs'] ) ) {
            foreach ( $targeting['slugs'] as $candidate ) {
                if ( metis_slug_clean( (string) $candidate ) === $slug ) {
                    return true;
                }
            }
        }

        if ( $content_type !== '' && ! empty( $targeting['content_types'] ) && is_array( $targeting['content_types'] ) ) {
            foreach ( $targeting['content_types'] as $candidate ) {
                if ( metis_key_clean( (string) $candidate ) === $content_type ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function normalizePayload( array $data, bool $for_create ): array {
        $out = [];

        if ( $for_create || array_key_exists( 'name', $data ) ) {
            $out['name'] = trim( metis_text_clean( (string) ( $data['name'] ?? '' ) ) );
            if ( $out['name'] === '' ) {
                $out['name'] = 'Untitled Banner';
            }
        }

        if ( $for_create || array_key_exists( 'type', $data ) ) {
            $type = metis_key_clean( (string) ( $data['type'] ?? 'top_banner' ) );
            if ( ! in_array( $type, [ 'top_banner', 'announcement_bar', 'inline' ], true ) ) {
                $type = 'top_banner';
            }
            $out['type'] = $type;
        }

        if ( $for_create || array_key_exists( 'status', $data ) ) {
            $status = metis_key_clean( (string) ( $data['status'] ?? 'draft' ) );
            if ( ! in_array( $status, [ 'draft', 'published' ], true ) ) {
                $status = 'draft';
            }
            $out['status'] = $status;
        }

        if ( $for_create || array_key_exists( 'dismiss_mode', $data ) ) {
            $dismiss = metis_key_clean( (string) ( $data['dismiss_mode'] ?? 'session' ) );
            if ( ! in_array( $dismiss, [ 'none', 'session', 'persisted' ], true ) ) {
                $dismiss = 'session';
            }
            $out['dismiss_mode'] = $dismiss;
        }

        if ( array_key_exists( 'sort_order', $data ) ) {
            $out['sort_order'] = (int) $data['sort_order'];
        } elseif ( $for_create ) {
            $out['sort_order'] = 0;
        }

        if ( $for_create || array_key_exists( 'content_json', $data ) ) {
            $content = self::jsonDecodeArray( $data['content_json'] ?? null );
            $text = function_exists( 'metis_runtime_kses_post' )
                ? metis_runtime_kses_post( (string) ( $content['text'] ?? '' ) )
                : strip_tags( (string) ( $content['text'] ?? '' ), '<p><br><strong><b><em><i><u><ul><ol><li><a><span><div>' );
            $cta_label = metis_text_clean( (string) ( $content['cta_label'] ?? '' ) );
            $cta_url = metis_url_clean( (string) ( $content['cta_url'] ?? '' ) );
            $allow_dismiss = ! empty( $content['allow_dismiss'] );

            $out['content_json'] = self::jsonEncode( [
                'text' => $text,
                'cta_label' => $cta_label,
                'cta_url' => $cta_url,
                'allow_dismiss' => $allow_dismiss,
            ] );
        }

        if ( $for_create || array_key_exists( 'targeting_json', $data ) ) {
            $targeting = self::jsonDecodeArray( $data['targeting_json'] ?? null );
            $site_wide = ! empty( $targeting['site_wide'] );
            $paths = [];
            foreach ( (array) ( $targeting['paths'] ?? [] ) as $path ) {
                $value = trim( (string) $path );
                if ( $value === '' ) {
                    continue;
                }
                if ( $value[0] !== '/' ) {
                    $value = '/' . $value;
                }
                $paths[] = $value;
            }
            $slugs = [];
            foreach ( (array) ( $targeting['slugs'] ?? [] ) as $slug ) {
                $value = metis_slug_clean( (string) $slug );
                if ( $value !== '' ) {
                    $slugs[] = $value;
                }
            }
            $types = [];
            foreach ( (array) ( $targeting['content_types'] ?? [] ) as $type ) {
                $value = metis_key_clean( (string) $type );
                if ( $value !== '' ) {
                    $types[] = $value;
                }
            }

            $out['targeting_json'] = self::jsonEncode( [
                'site_wide' => $site_wide,
                'paths' => array_values( array_unique( $paths ) ),
                'slugs' => array_values( array_unique( $slugs ) ),
                'content_types' => array_values( array_unique( $types ) ),
            ] );
        }

        if ( $for_create || array_key_exists( 'timezone', $data ) ) {
            $tz_name = metis_text_clean( (string) ( $data['timezone'] ?? 'UTC' ) );
            $out['timezone'] = self::safeTimezone( $tz_name )->getName();
        }

        if ( $for_create || array_key_exists( 'start_at', $data ) ) {
            $out['start_at'] = self::normalizeDateTime( $data['start_at'] ?? '' );
        }

        if ( $for_create || array_key_exists( 'end_at', $data ) ) {
            $out['end_at'] = self::normalizeDateTime( $data['end_at'] ?? '' );
        }

        return $out;
    }

    private static function normalizeDateTime( mixed $value ): ?string {
        $raw = trim( (string) $value );
        if ( $raw === '' ) {
            return null;
        }
        $normalized = str_replace( 'T', ' ', $raw );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}(:\d{2})?$/', $normalized ) !== 1 ) {
            return null;
        }
        if ( strlen( $normalized ) === 16 ) {
            $normalized .= ':00';
        }
        return $normalized;
    }

    private static function jsonDecodeArray( mixed $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }

        if ( ! is_string( $value ) || trim( $value ) === '' ) {
            return [];
        }

        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function safeTimezone( string $name ): DateTimeZone {
        try {
            return new DateTimeZone( $name );
        } catch ( \Throwable ) {
            return new DateTimeZone( 'UTC' );
        }
    }

    private static function currentUserId(): ?int {
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
