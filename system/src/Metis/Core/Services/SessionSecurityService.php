<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class SessionSecurityService {
    public function configureCookieSettings(): void {
        if ( \headers_sent() || \session_status() === \PHP_SESSION_ACTIVE ) {
            return;
        }

        $params = \session_get_cookie_params();
        $params['httponly'] = true;
        $params['secure'] = $this->isHttps();
        $params['samesite'] = 'Lax';

        \session_set_cookie_params( $params );
    }

    public function startSession(): void {
        $this->configureCookieSettings();
        if ( \function_exists( 'ini_set' ) ) {
            @\ini_set( 'session.use_strict_mode', '1' );
            @\ini_set( 'session.use_only_cookies', '1' );
            @\ini_set( 'session.cookie_httponly', '1' );
            @\ini_set( 'session.cookie_samesite', 'Lax' );
            @\ini_set( 'session.cookie_secure', $this->isHttps() ? '1' : '0' );
            @\ini_set( 'session.cache_limiter', '' );
        }
        if ( \function_exists( 'session_cache_limiter' ) ) {
            @\session_cache_limiter( '' );
        }

        if ( \session_status() !== \PHP_SESSION_ACTIVE ) {
            \session_start();
        }
    }

    public function regenerateId( bool $delete_old_session = true ): void {
        $this->startSession();
        if ( \headers_sent() || \session_status() !== \PHP_SESSION_ACTIVE ) {
            return;
        }
        \session_regenerate_id( $delete_old_session );
    }

    public function isHttps(): bool {
        $https = strtolower( (string) ( $_SERVER['HTTPS'] ?? '' ) );
        if ( $https !== '' && $https !== 'off' ) {
            return true;
        }

        if ( (int) ( $_SERVER['SERVER_PORT'] ?? 0 ) === 443 ) {
            return true;
        }

        $forwarded = trim( (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) );
        if ( $forwarded === '' ) {
            return false;
        }

        $first = strtolower( trim( explode( ',', $forwarded )[0] ?? '' ) );
        return $first === 'https';
    }
}
