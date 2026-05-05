<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

/**
 * Controls the Website public launch switch without changing routing behavior.
 */
final class WebsiteLaunchService {
    private const ROUTES_KEY = 'metis_website_public_routes_enabled';
    private const ENABLED_AT_KEY = 'metis_website_public_routes_enabled_at';
    private const ENABLED_BY_KEY = 'metis_website_public_routes_enabled_by';
    private const DISABLED_AT_KEY = 'metis_website_public_routes_disabled_at';
    private const DISABLED_BY_KEY = 'metis_website_public_routes_disabled_by';

    /**
     * @return array<string,mixed>
     */
    public static function status(): array {
        $readiness = WebsiteReadinessService::summary();
        $items = is_array( $readiness['items'] ?? null ) ? $readiness['items'] : [];
        $blockers = [];
        $warnings = [];

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $status = (string) ( $item['status'] ?? '' );
            if ( $status === 'blocked' ) {
                $blockers[] = $item;
            } elseif ( $status === 'attention' ) {
                $warnings[] = $item;
            }
        }

        $launched = WebsiteReadinessService::publicRoutesEnabled();

        return [
            'ok' => true,
            'launched' => $launched,
            'state' => $launched ? 'live' : (string) ( $readiness['state'] ?? 'setup' ),
            'can_launch' => count( $blockers ) === 0,
            'score' => (int) ( $readiness['score'] ?? 0 ),
            'total' => (int) ( $readiness['total'] ?? 0 ),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'readiness' => $readiness,
            'enabled_at' => (string) self::getSetting( self::ENABLED_AT_KEY, '' ),
            'enabled_by' => (int) self::getSetting( self::ENABLED_BY_KEY, 0 ),
            'disabled_at' => (string) self::getSetting( self::DISABLED_AT_KEY, '' ),
            'disabled_by' => (int) self::getSetting( self::DISABLED_BY_KEY, 0 ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function enable( bool $force = false ): array {
        $status = self::status();
        $blockers = is_array( $status['blockers'] ?? null ) ? $status['blockers'] : [];
        if ( ! $force && count( $blockers ) > 0 ) {
            return [
                'ok' => false,
                'message' => 'Resolve launch blockers before enabling public Website routes.',
                'status' => $status,
            ];
        }

        if ( ! self::setSetting( self::ROUTES_KEY, true, true ) ) {
            return [
                'ok' => false,
                'message' => 'Unable to enable public Website routes.',
                'status' => $status,
            ];
        }

        self::setSetting( self::ENABLED_AT_KEY, gmdate( 'c' ), false );
        self::setSetting( self::ENABLED_BY_KEY, self::currentUserId(), false );

        return [
            'ok' => true,
            'message' => 'Public Website routes enabled.',
            'status' => self::status(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function disable(): array {
        if ( ! self::setSetting( self::ROUTES_KEY, false, true ) ) {
            return [
                'ok' => false,
                'message' => 'Unable to disable public Website routes.',
                'status' => self::status(),
            ];
        }

        self::setSetting( self::DISABLED_AT_KEY, gmdate( 'c' ), false );
        self::setSetting( self::DISABLED_BY_KEY, self::currentUserId(), false );

        return [
            'ok' => true,
            'message' => 'Public Website routes disabled.',
            'status' => self::status(),
        ];
    }

    private static function getSetting( string $key, mixed $default ): mixed {
        if ( function_exists( '\\metis_get_option' ) ) {
            return \metis_get_option( $key, $default );
        }
        if ( class_exists( '\\Core_Settings_Service' ) ) {
            return \Core_Settings_Service::get( $key, $default );
        }

        return $default;
    }

    private static function setSetting( string $key, mixed $value, bool $autoload ): bool {
        if ( function_exists( '\\metis_update_option' ) ) {
            return (bool) \metis_update_option( $key, $value, $autoload );
        }
        if ( class_exists( '\\Core_Settings_Service' ) ) {
            return (bool) \Core_Settings_Service::set( $key, $value, $autoload );
        }

        return false;
    }

    private static function currentUserId(): int {
        if ( function_exists( '\\metis_current_user_id' ) ) {
            return (int) \metis_current_user_id();
        }

        return 0;
    }
}
