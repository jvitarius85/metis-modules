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
        return function_exists( 'metis_user_logged_in' ) ? \metis_user_logged_in() : false;
    }

    public function user(): mixed {
        if ( function_exists( 'metis_auth_current_user_row' ) ) {
            return \metis_auth_current_user_row();
        }

        return function_exists( 'metis_runtime_current_user' ) ? \metis_runtime_current_user() : null;
    }

    public function require_login(): void {
        if ( $this->is_authenticated() ) {
            return;
        }

        if ( php_sapi_name() === 'cli' ) {
            throw new \RuntimeException( 'Authentication required.' );
        }

        $target = function_exists( 'metis_portal_url' ) ? \metis_portal_url() : '/';
        header( 'Location: ' . $target, true, 302 );
        exit;
    }

    public function require_permission( ?string $module, string $permission = 'view' ): void {
        $allowed = class_exists( 'Metis' )
            ? (bool) \Metis::service( 'permissions' )->can( $module, $permission )
            : true;

        if ( $allowed ) {
            return;
        }

        throw new \RuntimeException( 'Permission denied.' );
    }

    public function logout(): void {
        if ( function_exists( 'metis_auth_logout' ) ) {
            \metis_auth_logout();
        }
    }

    public function cli_auth_required(): bool {
        $configured = \metis_runtime_config_get( 'cli_auth_required', null );
        if ( is_bool( $configured ) ) {
            return $configured;
        }

        if ( is_scalar( $configured ) && $configured !== null && $configured !== '' ) {
            return (bool) filter_var( (string) $configured, FILTER_VALIDATE_BOOLEAN );
        }

        $env = getenv( 'METIS_CLI_AUTH_REQUIRED' );
        if ( is_string( $env ) && $env !== '' ) {
            return (bool) filter_var( $env, FILTER_VALIDATE_BOOLEAN );
        }

        return true;
    }

    public function authenticate_cli( string $identifier, string $password, ?string $totp = null ): array {
        if ( ! function_exists( 'metis_auth_authenticate_primary' ) ) {
            return [ 'ok' => false, 'message' => 'CLI authentication is not available.' ];
        }

        $identifier = trim( $identifier );
        $password   = (string) $password;
        $totp       = $totp !== null ? trim( $totp ) : null;

        if ( $identifier === '' || $password === '' ) {
            return [ 'ok' => false, 'message' => 'Username/email and password are required.' ];
        }

        $auth = \metis_auth_authenticate_primary( $identifier, $password );
        if ( ! is_array( $auth ) || ! is_array( $auth['user'] ?? null ) ) {
            return [ 'ok' => false, 'message' => 'Authentication failed.' ];
        }

        $user   = (array) $auth['user'];
        $person = is_array( $auth['person'] ?? null ) ? (array) $auth['person'] : [];

        if ( $this->cli_totp_required( $person ) ) {
            if ( $totp === null || $totp === '' ) {
                return [
                    'ok' => false,
                    'mfa_required' => true,
                    'message' => 'A TOTP code is required for this account.',
                    'user' => $user,
                    'person' => $person,
                ];
            }

            if ( ! function_exists( 'metis_auth_verify_totp_code' ) || ! \metis_auth_verify_totp_code( $person, $totp ) ) {
                return [ 'ok' => false, 'message' => 'The TOTP code was not valid.' ];
            }
        }

        if ( function_exists( 'metis_auth_finalize_login' ) ) {
            \metis_auth_finalize_login( $user );
        }

        return [
            'ok' => true,
            'user' => $user,
            'person' => $person,
        ];
    }

    public function cli_totp_required( ?array $person ): bool {
        return function_exists( 'metis_auth_person_requires_totp' )
            ? \metis_auth_person_requires_totp( $person )
            : false;
    }
}
