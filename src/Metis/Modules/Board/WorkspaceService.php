<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class WorkspaceService {
    public static function b64urlEncode( string $value ): string {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    public static function workspaceSettings(): array {
        $service_json_raw      = (string) \Metis\Core\Services\CredentialService::getBySetting( 'workspace_service_account_json' );
        $impersonation_admin   = strtolower( trim( (string) \Core_Settings_Service::get( 'workspace_impersonation_admin', '' ) ) );
        if ( $service_json_raw === '' || ! \metis_email_is_valid( $impersonation_admin ) ) {
            return [ 'ok' => false, 'error' => 'Workspace service account JSON or impersonation admin is not configured.' ];
        }

        $service = json_decode( $service_json_raw, true );
        if ( ! is_array( $service ) || empty( $service['client_email'] ) || empty( $service['private_key'] ) || empty( $service['token_uri'] ) ) {
            return [ 'ok' => false, 'error' => 'Invalid Workspace service account JSON in settings.' ];
        }

        return [
            'ok'      => true,
            'service' => $service,
            'subject' => $impersonation_admin,
            'scopes'  => [ 'https://www.googleapis.com/auth/drive' ],
        ];
    }

    public static function googleAccessToken( array $cfg ): array {
        $service      = (array) ( $cfg['service'] ?? [] );
        $client_email = (string) ( $service['client_email'] ?? '' );
        $private_key  = (string) ( $service['private_key'] ?? '' );
        $token_uri    = (string) ( $service['token_uri'] ?? 'https://oauth2.googleapis.com/token' );
        $subject      = (string) ( $cfg['subject'] ?? '' );
        $scopes       = (array) ( $cfg['scopes'] ?? [] );
        if ( $client_email === '' || $private_key === '' || $subject === '' || empty( $scopes ) ) {
            return [ 'ok' => false, 'error' => 'Workspace OAuth configuration is incomplete.' ];
        }

        $cache_key = 'metis_board_ws_token_' . md5( $client_email . '|' . $subject . '|' . implode( ' ', $scopes ) );
        $cached    = \metis_get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached['access_token'] ) ) {
            return [ 'ok' => true, 'access_token' => (string) $cached['access_token'] ];
        }

        $header    = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
        $now       = time();
        $claims    = [
            'iss'   => $client_email,
            'scope' => implode( ' ', $scopes ),
            'aud'   => $token_uri,
            'iat'   => $now,
            'exp'   => $now + 3600,
            'sub'   => $subject,
        ];
        $jwt_input = self::b64urlEncode( \metis_json_encode( $header ) ) . '.' . self::b64urlEncode( \metis_json_encode( $claims ) );
        $signature = '';
        $signed    = openssl_sign( $jwt_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
        if ( ! $signed ) {
            return [ 'ok' => false, 'error' => 'Could not sign Workspace JWT assertion.' ];
        }

        $assertion = $jwt_input . '.' . self::b64urlEncode( $signature );
        $response  = \metis_runtime_remote_post( $token_uri, [
            'timeout' => 20,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ],
        ] );

        if ( \metis_runtime_is_error( $response ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token request failed.' ];
        }

        $code = (int) \metis_runtime_remote_retrieve_response_code( $response );
        $body = json_decode( (string) \metis_runtime_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token request failed (' . $code . ').' ];
        }

        $access_token = (string) $body['access_token'];
        $ttl          = max( 120, ( (int) ( $body['expires_in'] ?? 3600 ) ) - 60 );
        \metis_set_transient( $cache_key, [ 'access_token' => $access_token ], $ttl );

        return [ 'ok' => true, 'access_token' => $access_token ];
    }

    public static function googleRequest( string $method, string $url, ?array $body, array $cfg, array $extra_headers = [] ): array {
        $token = self::googleAccessToken( $cfg );
        if ( empty( $token['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token error.' ];
        }

        $headers = array_merge( [
            'Authorization' => 'Bearer ' . (string) $token['access_token'],
            'Content-Type'  => 'application/json',
        ], $extra_headers );

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => $headers,
        ];

        if ( $body !== null && ! isset( $extra_headers['Content-Type'] ) ) {
            $args['body'] = \metis_json_encode( $body );
        } elseif ( $body !== null ) {
            $args['body'] = (string) ( $body['raw_body'] ?? '' );
        }

        $response = \metis_runtime_remote_request( $url, $args );
        if ( \metis_runtime_is_error( $response ) ) {
            return [ 'ok' => false, 'error' => 'Google API request failed.' ];
        }

        $code    = (int) \metis_runtime_remote_retrieve_response_code( $response );
        $raw     = (string) \metis_runtime_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            return [ 'ok' => false, 'error' => 'Google API request failed (' . $code . ').', 'status' => $code, 'raw' => $raw ];
        }

        return [ 'ok' => true, 'status' => $code, 'body' => is_array( $decoded ) ? $decoded : [] ];
    }
}
