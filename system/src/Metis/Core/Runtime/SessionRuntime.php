<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_runtime_current_user' ) ) {
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
}

if ( ! function_exists( 'metis_runtime_user_logged_in' ) ) {
    function metis_runtime_user_logged_in(): bool {
        return (int) metis_runtime_current_user()->ID > 0;
    }
}

if ( ! function_exists( 'metis_runtime_current_user_id' ) ) {
    function metis_runtime_current_user_id(): int {
        return metis_runtime_current_user()->ID;
    }
}

if ( ! function_exists( 'metis_runtime_current_user_can' ) ) {
    function metis_runtime_current_user_can( string $capability ): bool {
        $user = metis_runtime_current_user();
        if ( in_array( 'administrator', $user->roles, true ) ) {
            return true;
        }

        return in_array( $capability, $user->roles, true );
    }
}

if ( ! function_exists( 'metis_runtime_is_admin' ) ) {
    function metis_runtime_is_admin(): bool {
        $path = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        return str_contains( $path, '/admin' );
    }
}

if ( ! function_exists( 'metis_user_logged_in' ) ) {
    function metis_user_logged_in(): bool {
        return metis_runtime_user_logged_in();
    }
}

if ( ! function_exists( 'metis_current_user_id' ) ) {
    function metis_current_user_id(): int {
        return metis_runtime_current_user_id();
    }
}

if ( ! function_exists( 'metis_current_user_can' ) ) {
    function metis_current_user_can( string $capability ): bool {
        return metis_runtime_current_user_can( $capability );
    }
}

if ( ! function_exists( 'metis_is_admin' ) ) {
    function metis_is_admin(): bool {
        return metis_runtime_is_admin();
    }
}

if ( ! function_exists( 'metis_runtime_session_token' ) ) {
    function metis_runtime_session_token(): string {
        if ( empty( $_SESSION['metis_session_token'] ) ) {
            $_SESSION['metis_session_token'] = bin2hex( random_bytes( 16 ) );
        }

        return (string) $_SESSION['metis_session_token'];
    }
}
