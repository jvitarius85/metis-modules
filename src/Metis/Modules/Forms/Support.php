<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

final class Support {
    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'forms' ), '/' );
    }

    public static function publicUrl( string $slug = '' ): string {
        $base = \home_url( '/public/forms' );
        if ( $slug === '' ) {
            return rtrim( $base, '/' );
        }

        return rtrim( $base, '/' ) . '/' . rawurlencode( \sanitize_title( $slug ) );
    }

    public static function detailUrl( int $form_id = 0 ): string {
        $base = \metis_portal_url( 'forms', 'form' );
        return $form_id > 0 ? $base . '?form_id=' . rawurlencode( (string) $form_id ) : $base;
    }

    public static function buildUrl( int $form_id = 0 ): string {
        $base = \metis_portal_url( 'forms', 'build' );
        return $form_id > 0 ? $base . '?form_id=' . rawurlencode( (string) $form_id ) : $base;
    }

    public static function entriesUrl( int $form_id = 0 ): string {
        $base = \metis_portal_url( 'forms', 'entries' );
        return $form_id > 0 ? $base . '?form_id=' . rawurlencode( (string) $form_id ) : $base;
    }

    public static function settingsUrl( int $form_id = 0 ): string {
        $base = \metis_portal_url( 'forms', 'settings' );
        return $form_id > 0 ? $base . '?form_id=' . rawurlencode( (string) $form_id ) : $base;
    }

    public static function canView(): bool {
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        return \function_exists( 'metis_people_can' ) ? \metis_people_can( 'forms', 'view' ) : \metis_user_logged_in();
    }

    public static function canManage(): bool {
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        return \function_exists( 'metis_people_can' ) ? \metis_people_can( 'forms', 'edit' ) : false;
    }

    public static function canDelete(): bool {
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        return \function_exists( 'metis_people_can' ) ? \metis_people_can( 'forms', 'delete' ) : false;
    }
}
