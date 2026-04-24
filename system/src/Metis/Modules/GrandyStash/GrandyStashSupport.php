<?php
declare(strict_types=1);

namespace Metis\Modules\GrandyStash;

final class GrandyStashSupport {
    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'grandys_stash' ), '/' );
    }

    public static function viewUrl( string $ticket_code = '' ): string {
        $base = rtrim( self::baseUrl(), '/' ) . '/view/';
        $ticket_code = strtoupper( trim( \metis_text_clean( $ticket_code ) ) );

        if ( $ticket_code === '' ) {
            return $base;
        }

        return $base . rawurlencode( $ticket_code );
    }

    public static function canView(): bool {
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        return \function_exists( 'metis_people_can' ) ? \metis_people_can( 'grandys_stash', 'view' ) : \metis_user_logged_in();
    }

    public static function canManage(): bool {
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        return \function_exists( 'metis_people_can' ) ? \metis_people_can( 'grandys_stash', 'edit' ) : false;
    }
}

\class_alias( __NAMESPACE__ . '\\GrandyStashSupport', 'Metis\\Modules\\GrandyStashSupport' );
