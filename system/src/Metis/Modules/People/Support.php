<?php
declare(strict_types=1);

namespace Metis\Modules\People;

final class Support {
    public static function baseUrl(): string {
        return rtrim( \metis_portal_url( 'people' ), '/' );
    }

    public static function personUrl( string $pid = '' ): string {
        $base = rtrim( \metis_portal_url( 'people', 'person' ), '/' ) . '/';
        if ( $pid === '' ) {
            return $base;
        }

        return $base . rawurlencode( $pid ) . '/';
    }

    public static function peopleListUrl(): string {
        return \metis_portal_url( 'people', 'people_list' );
    }

    public static function rolesListUrl(): string {
        return \metis_portal_url( 'people', 'roles_list' );
    }

    public static function permissionsUrl(): string {
        return \metis_portal_url( 'people', 'permissions' );
    }

    public static function accessRequestsUrl(): string {
        return \metis_portal_url( 'people', 'access_requests' );
    }

    public static function templatesUrl(): string {
        return \metis_portal_url( 'people', 'templates' );
    }

    public static function bulkActionsUrl(): string {
        return \metis_portal_url( 'people', 'bulk_actions' );
    }

    public static function activityUrl(): string {
        return \metis_portal_url( 'people', 'activity' );
    }

    public static function workspaceUrl(): string {
        return \metis_portal_url( 'people', 'workspace' );
    }

    public static function roleUrl( string $role_key = '', string $role_domain = '' ): string {
        $base = \metis_portal_url( 'people', 'role' );
        if ( $role_key === '' ) {
            return $base;
        }

        $url = $base . '?role=' . rawurlencode( $role_key );
        if ( $role_domain !== '' ) {
            $url .= '&domain=' . rawurlencode( \metis_key_clean( $role_domain ) );
        }

        return $url;
    }

    public static function canManage(): bool {
        return AccessManager::can( 'people', 'edit' );
    }

    public static function canView(): bool {
        return AccessManager::can( 'people', 'view' );
    }

    public static function canWorkspaceManage(): bool {
        if ( \metis_current_user_can( 'manage_options' ) ) {
            return true;
        }

        return AccessManager::can( 'people', 'workspace_manage' );
    }
}
