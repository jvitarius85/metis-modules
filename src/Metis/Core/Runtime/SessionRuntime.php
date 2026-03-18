<?php
declare(strict_types=1);

function metis_runtime_current_user(): MetisUser {
    if ( isset( $GLOBALS['metis_current_user_override'] ) && $GLOBALS['metis_current_user_override'] instanceof MetisUser ) {
        return $GLOBALS['metis_current_user_override'];
    }

    if ( empty( $_SESSION['metis_user'] ) || ! is_array( $_SESSION['metis_user'] ) ) {
        return new MetisUser( [
            'ID' => 0,
            'user_login' => '',
            'user_email' => '',
            'display_name' => '',
            'first_name' => '',
            'last_name' => '',
            'roles' => [],
            'user_pass' => '',
        ] );
    }

    return new MetisUser( $_SESSION['metis_user'] );
}

function is_user_logged_in(): bool {
    return (int) metis_runtime_current_user()->ID > 0;
}

function get_current_user_id(): int {
    return metis_runtime_current_user()->ID;
}

function current_user_can( string $capability ): bool {
    $user = metis_runtime_current_user();
    if ( in_array( 'administrator', $user->roles, true ) ) {
        return true;
    }

    return in_array( $capability, $user->roles, true );
}

function is_admin(): bool {
    $path = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
    return str_contains( $path, '/admin' );
}

function metis_runtime_session_token(): string {
    if ( empty( $_SESSION['metis_session_token'] ) ) {
        $_SESSION['metis_session_token'] = bin2hex( random_bytes( 16 ) );
    }

    return (string) $_SESSION['metis_session_token'];
}
