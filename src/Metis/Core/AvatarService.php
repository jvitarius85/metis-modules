<?php
declare(strict_types=1);

namespace Metis\Core;

final class AvatarService {
    private const AVATAR_BASE_PATH = '/storage/uploads/avatars/';

    public static function storageKey( string $avatarKey ): string {
        $avatarKey = trim( $avatarKey );
        if ( $avatarKey === '' ) {
            return '';
        }

        $avatarKey = preg_replace( '/[^A-Za-z0-9_-]+/', '-', $avatarKey ) ?? '';
        return trim( $avatarKey, '-_' );
    }

    public static function initials( string $name ): string {
        $name = trim( preg_replace( '/\s+/', ' ', $name ) ?? '' );
        if ( $name === '' ) {
            return '??';
        }

        $parts = array_values( array_filter( array_map(
            static fn ( $part ): string => trim( (string) $part ),
            preg_split( '/[\s\-_]+/', $name ) ?: []
        ) ) );

        $letters = [];
        if ( count( $parts ) >= 2 ) {
            $letters[] = self::upper( self::substr( (string) $parts[0], 0, 1 ) );
            $letters[] = self::upper( self::substr( (string) $parts[ count( $parts ) - 1 ], 0, 1 ) );
        } elseif ( count( $parts ) === 1 ) {
            $letters[] = self::upper( self::substr( (string) $parts[0], 0, 1 ) );
            $tail = self::upper( self::substr( (string) $parts[0], 1, 1 ) );
            if ( $tail !== '' ) {
                $letters[] = $tail;
            }
        }

        if ( $letters === [] ) {
            $letters[] = self::upper( self::substr( $name, 0, 1 ) );
        }

        return implode( '', array_slice( $letters, 0, 2 ) );
    }

    public static function fallbackDataUri( string $name, int $size = 96 ): string {
        $size = max( 24, min( 512, $size ) );
        $initials = self::initials( $name );
        $seed = substr( md5( strtolower( trim( $name ) ) ), 0, 6 );
        $bg = '#' . $seed;
        $fg = self::contrastColor( $bg );
        $font_size = (int) round( $size * 0.34 );

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s"><circle cx="%3$d" cy="%3$d" r="%4$d" fill="%5$s"/><text x="50%%" y="51%%" dominant-baseline="middle" text-anchor="middle" fill="%6$s" font-family="Figtree, Segoe UI, sans-serif" font-size="%7$d" font-weight="700" letter-spacing="0.04em">%8$s</text></svg>',
            $size,
            htmlspecialchars( $name !== '' ? $name : $initials, ENT_QUOTES ),
            (int) floor( $size / 2 ),
            (int) floor( $size / 2 ) - 2,
            $bg,
            $fg,
            $font_size,
            htmlspecialchars( $initials, ENT_QUOTES )
        );

        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode( $svg );
    }

    public static function resolveUrl( string $name, string $avatarUrl = '', int $size = 96, string $avatarKey = '' ): string {
        $storedUrl = self::storedAvatarUrl( $avatarKey );
        if ( $storedUrl !== '' ) {
            return $storedUrl;
        }

        $avatarUrl = self::normalizeLocalAvatarUrl( trim( $avatarUrl ), $avatarKey );
        if ( $avatarUrl !== '' ) {
            return $avatarUrl;
        }

        return self::fallbackDataUri( $name, $size );
    }

    /**
     * @return array{type:string,url:string,initials:string,name:string}
     */
    public static function payload( string $name, string $avatarUrl = '', int $size = 96, string $avatarKey = '' ): array {
        $resolved = self::storedAvatarUrl( $avatarKey );
        if ( $resolved === '' ) {
            $resolved = trim( $avatarUrl );
        }

        return [
            'type' => $resolved !== '' ? 'image' : 'initials',
            'url' => self::resolveUrl( $name, $resolved, $size, $avatarKey ),
            'initials' => self::initials( $name ),
            'name' => trim( $name ),
        ];
    }

    public static function avatarStorageDir( string $avatarKey ): string {
        $avatarKey = self::storageKey( $avatarKey );
        if ( $avatarKey === '' ) {
            return '';
        }

        $root = defined( 'METIS_PATH' ) ? rtrim( (string) METIS_PATH, '/\\' ) : dirname( __DIR__, 3 );
        return $root . '/storage/uploads/avatars/' . $avatarKey;
    }

    public static function avatarStoragePath( string $avatarKey ): string {
        $dir = self::avatarStorageDir( $avatarKey );
        return $dir !== '' ? $dir . '/avatar.jpg' : '';
    }

    public static function avatarPublicUrl( string $avatarKey, ?int $version = null ): string {
        $avatarKey = self::storageKey( $avatarKey );
        if ( $avatarKey === '' ) {
            return '';
        }

        $url = self::baseUrl() . self::AVATAR_BASE_PATH . $avatarKey . '/avatar.jpg';
        if ( $version !== null && $version > 0 ) {
            $url = metis_add_query_arg( [ 'v' => $version ], $url );
        }
        return $url;
    }

    public static function storedAvatarUrl( string $avatarKey, bool $cacheBust = true ): string {
        $avatarKey = self::storageKey( $avatarKey );
        if ( $avatarKey === '' ) {
            return '';
        }

        $path = self::avatarStoragePath( $avatarKey );
        if ( ! is_file( $path ) ) {
            return '';
        }

        $version = $cacheBust ? (int) @filemtime( $path ) : null;
        return self::avatarPublicUrl( $avatarKey, $version );
    }

    private static function normalizeLocalAvatarUrl( string $avatarUrl, string $avatarKey = '' ): string {
        if ( $avatarUrl === '' ) {
            return '';
        }

        $path = parse_url( $avatarUrl, PHP_URL_PATH );
        if ( ! is_string( $path ) || ! str_contains( $path, self::AVATAR_BASE_PATH ) ) {
            return $avatarUrl;
        }

        $relative = strstr( $path, self::AVATAR_BASE_PATH );
        if ( ! is_string( $relative ) || $relative === '' ) {
            return $avatarUrl;
        }

        $filesystemPath = rtrim( defined( 'METIS_PATH' ) ? (string) METIS_PATH : dirname( __DIR__, 3 ), '/\\' ) . $relative;
        if ( ! is_file( $filesystemPath ) ) {
            return '';
        }

        $migratedUrl = self::migrateLegacyAvatarToStorageKey( $filesystemPath, $avatarKey );
        if ( $migratedUrl !== '' ) {
            return $migratedUrl;
        }

        $version = (int) @filemtime( $filesystemPath );
        $normalized = self::baseUrl() . $relative;
        if ( $version > 0 ) {
            $normalized = metis_add_query_arg( [ 'v' => $version ], $normalized );
        }

        return $normalized;
    }

    private static function baseUrl(): string {
        if ( defined( 'METIS_URL' ) ) {
            return rtrim( (string) METIS_URL, '/' );
        }

        return rtrim( metis_home_url( '/' ), '/' );
    }

    private static function migrateLegacyAvatarToStorageKey( string $legacyPath, string $avatarKey ): string {
        $avatarKey = self::storageKey( $avatarKey );
        if ( $avatarKey === '' ) {
            return '';
        }

        $targetPath = self::avatarStoragePath( $avatarKey );
        if ( $targetPath === '' ) {
            return '';
        }

        if ( is_file( $targetPath ) ) {
            return self::storedAvatarUrl( $avatarKey );
        }

        $targetDir = dirname( $targetPath );
        if ( ! is_dir( $targetDir ) && ! @mkdir( $targetDir, 0755, true ) && ! is_dir( $targetDir ) ) {
            return '';
        }

        if ( ! @copy( $legacyPath, $targetPath ) ) {
            return '';
        }

        @chmod( $targetDir, 0755 );
        @chmod( $targetPath, 0644 );

        return self::storedAvatarUrl( $avatarKey );
    }

    private static function contrastColor( string $hex ): string {
        $hex = ltrim( trim( $hex ), '#' );
        if ( strlen( $hex ) !== 6 ) {
            return '#ffffff';
        }

        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        $luma = ( ( 0.299 * $r ) + ( 0.587 * $g ) + ( 0.114 * $b ) ) / 255;

        return $luma > 0.58 ? '#182033' : '#ffffff';
    }

    private static function upper( string $value ): string {
        if ( function_exists( 'mb_strtoupper' ) ) {
            return mb_strtoupper( $value, 'UTF-8' );
        }

        return strtoupper( $value );
    }

    private static function substr( string $value, int $start, int $length ): string {
        if ( function_exists( 'mb_substr' ) ) {
            return (string) mb_substr( $value, $start, $length, 'UTF-8' );
        }

        return substr( $value, $start, $length );
    }
}
