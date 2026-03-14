<?php
declare(strict_types=1);

namespace Metis\Services;

final class AuthService {
    public function ensure_schema(): void {
        if ( function_exists( 'metis_auth_ensure_schema' ) ) {
            \metis_auth_ensure_schema();
        }
    }

    public function refresh_session(): void {
        if ( function_exists( 'metis_auth_refresh_session' ) ) {
            \metis_auth_refresh_session();
        }
    }

    public function handle_request( mixed $request ): bool {
        return function_exists( 'metis_auth_handle_request' ) ? (bool) \metis_auth_handle_request( $request ) : false;
    }

    public function current_person_id(): int {
        return function_exists( 'metis_auth_current_person_id' ) ? (int) \metis_auth_current_person_id() : 0;
    }

    public function is_authenticated(): bool {
        return function_exists( 'is_user_logged_in' ) ? \is_user_logged_in() : false;
    }
}
