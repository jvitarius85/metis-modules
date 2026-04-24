<?php
declare(strict_types=1);

namespace Metis\Modules\Contacts;

final class Support {
    public static function canView(): bool {
        if ( function_exists( 'metis_people_can' ) ) {
            return \metis_people_can( 'contacts', 'view' );
        }

        return \metis_user_logged_in();
    }

    public static function canManage(): bool {
        if ( function_exists( 'metis_people_can' ) ) {
            return \metis_people_can( 'contacts', 'edit' );
        }

        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        $user = \metis_runtime_current_user();
        if ( ! $user || empty( $user->roles ) ) {
            return false;
        }

        $allowed_roles = [ 'donor_admin', 'newsletter_admin', 'board' ];
        foreach ( $user->roles as $role ) {
            if ( in_array( $role, $allowed_roles, true ) ) {
                return true;
            }
        }

        return false;
    }

    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'contacts' ), '/' );
    }

    public static function detailUrl( string $cid ): string {
        return \metis_portal_url( 'contacts', 'contact' ) . '?cid=' . rawurlencode( $cid );
    }

    public static function resolvedTimezone(): \DateTimeZone {
        $candidates = [];

        if ( class_exists( 'Core_Settings_Service' ) ) {
            $settings_candidates = [
                \Core_Settings_Service::get( 'timezone', '' ),
                \Core_Settings_Service::get( 'site_timezone', '' ),
            ];

            foreach ( $settings_candidates as $candidate ) {
                if ( is_string( $candidate ) && trim( $candidate ) !== '' ) {
                    $candidates[] = trim( $candidate );
                }
            }
        }

        $configured_tz = \metis_get_option( 'timezone_string' );
        if ( is_string( $configured_tz ) && trim( $configured_tz ) !== '' ) {
            $candidates[] = trim( $configured_tz );
        }

        foreach ( $candidates as $tz_name ) {
            try {
                return new \DateTimeZone( $tz_name );
            } catch ( \Exception ) {
                continue;
            }
        }

        return \metis_runtime_timezone();
    }

    public static function formatDatetime( string $mysql_datetime, string $format = 'm/d/y g:ia' ): string {
        $mysql_datetime = trim( $mysql_datetime );
        if ( $mysql_datetime === '' ) {
            return '';
        }

        $tz = self::resolvedTimezone();
        $dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime, $tz );
        if ( $dt instanceof \DateTimeImmutable ) {
            return \metis_runtime_date( $format, $dt->getTimestamp(), $tz );
        }

        $ts = strtotime( $mysql_datetime );
        if ( ! $ts ) {
            return '';
        }

        return \metis_runtime_date( $format, $ts, $tz );
    }
}
