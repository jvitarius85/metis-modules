<?php
declare(strict_types=1);

namespace Metis\Modules\Forms;

final class Support {
    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'forms' ), '/' );
    }

    public static function publicUrl( string $slug = '' ): string {
        $base = rtrim( \metis_home_url( '/public/forms' ), '/' );
        if ( $slug === '' ) {
            return $base;
        }

        return $base . '/' . rawurlencode( \metis_slug_clean( $slug ) );
    }

    public static function detailUrl( int $form_id = 0 ): string {
        return self::buildAdminUrl( 'form', $form_id );
    }

    public static function buildUrl( int $form_id = 0 ): string {
        return self::buildAdminUrl( 'build', $form_id );
    }

    public static function entriesUrl( int $form_id = 0 ): string {
        return self::buildAdminUrl( 'entries', $form_id );
    }

    public static function settingsUrl( int $form_id = 0 ): string {
        return self::buildAdminUrl( 'settings', $form_id );
    }

    public static function canView(): bool {
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        if ( \function_exists( 'metis_people_can' ) ) {
            return \metis_people_can( 'forms', 'view' );
        }

        return \metis_user_logged_in();
    }

    public static function canManage(): bool {
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        if ( \function_exists( 'metis_people_can' ) ) {
            return \metis_people_can( 'forms', 'edit' ) || \metis_people_can( 'forms', 'create' );
        }

        return false;
    }

    public static function canDelete(): bool {
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        return \function_exists( 'metis_people_can' ) ? \metis_people_can( 'forms', 'delete' ) : false;
    }

    public static function moduleOptions(): array {
        return [
            [ 'value' => '', 'label' => 'Unassigned' ],
            [ 'value' => 'grandys_stash', 'label' => "Grandy's Stash" ],
            [ 'value' => 'donations', 'label' => 'Donations' ],
        ];
    }

    public static function moduleFlows(): array {
        return [
            'grandys_stash' => [
                [ 'value' => '', 'label' => 'Choose a flow' ],
                [ 'value' => 'request', 'label' => 'Supplies request' ],
                [ 'value' => 'donation', 'label' => 'Donation offer' ],
            ],
            'donations' => [
                [ 'value' => '', 'label' => 'Choose a flow' ],
                [ 'value' => 'donation', 'label' => 'Donation' ],
            ],
        ];
    }

    public static function roleOptions(): array {
        if ( ! \class_exists( '\Metis_Tables' ) || ! \Metis_Tables::has( 'people_roles' ) ) {
            return [];
        }

        $table = (string) \Metis_Tables::get( 'people_roles' );
        if ( $table === '' ) {
            return [];
        }

        $rows = \metis_db()->fetchAll(
            "SELECT role_key, role_name, role_domain
             FROM {$table}
             WHERE role_domain = %s
             ORDER BY role_name ASC, role_key ASC",
            [ 'metis' ]
        ) ?: [];

        $options = [];
        foreach ( $rows as $row ) {
            $key = (string) ( $row['role_key'] ?? '' );
            if ( $key === '' ) {
                continue;
            }
            $options[] = [
                'value' => (string) $key,
                'label' => (string) ( $row['role_name'] ?? $key ),
            ];
        }

        usort(
            $options,
            static fn ( array $a, array $b ): int => strcasecmp( (string) $a['label'], (string) $b['label'] )
        );

        return $options;
    }

    public static function cssClassToken( string $value, string $fallback = 'default' ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return $fallback;
        }

        $sanitized = preg_replace( '/[^A-Za-z0-9_-]/', '-', $value );
        $sanitized = is_string( $sanitized ) ? trim( $sanitized, '-' ) : '';

        return $sanitized !== '' ? $sanitized : $fallback;
    }

    private static function buildAdminUrl( string $view, int $form_id = 0 ): string {
        $base = \metis_portal_url( 'forms', $view );
        if ( $form_id < 1 ) {
            return $base;
        }

        return $base . '?form_id=' . rawurlencode( (string) $form_id );
    }
}
