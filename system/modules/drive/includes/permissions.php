<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_drive_can_view(): bool {
    if ( function_exists( 'metis_people_can' ) ) {
        return metis_people_can( 'drive', 'view' );
    }
    return function_exists( 'metis_user_logged_in' ) && metis_user_logged_in();
}

function metis_drive_can( string $action ): bool {
    $action = metis_key_clean( $action );
    if ( $action === '' ) {
        return false;
    }

    if ( function_exists( 'metis_people_can' ) ) {
        return metis_people_can( 'drive', $action );
    }

    return $action === 'view'
        ? ( function_exists( 'metis_user_logged_in' ) && metis_user_logged_in() )
        : ( function_exists( 'metis_current_user_can' ) && metis_current_user_can( 'manage_options' ) );
}

function metis_drive_can_manage(): bool {
    if ( function_exists( 'metis_people_can' ) ) {
        return metis_people_can( 'drive', 'edit' )
            || metis_people_can( 'drive', 'create' )
            || metis_people_can( 'drive', 'delete' );
    }
    return function_exists( 'metis_current_user_can' ) && metis_current_user_can( 'manage_options' );
}
