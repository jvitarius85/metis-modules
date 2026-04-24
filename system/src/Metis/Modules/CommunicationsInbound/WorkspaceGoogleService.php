<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

class WorkspaceGoogleService {
    /**
     * @param array<int, string> $scopes
     * @return array<string, mixed>
     */
    public function configForMailbox( array $mailbox, array $scopes = [] ): array {
        $service = \function_exists( 'metis_workspace_service_account_payload' ) ? \metis_workspace_service_account_payload() : [];
        $subject = strtolower( trim( (string) ( $mailbox['delegated_user'] ?? $mailbox['mailbox_email'] ?? '' ) ) );

        if ( $scopes === [] ) {
            $scopes = [ 'https://www.googleapis.com/auth/gmail.modify' ];
        }

        return [
            'service' => $service,
            'subject' => $subject,
            'scopes'  => $scopes,
        ];
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>
     */
    public function accessToken( array $cfg ): array {
        $service      = (array) ( $cfg['service'] ?? [] );
        $client_email = (string) ( $service['client_email'] ?? '' );
        $private_key  = (string) ( $service['private_key'] ?? '' );
        $token_uri    = (string) ( $service['token_uri'] ?? 'https://oauth2.googleapis.com/token' );
        $subject      = (string) ( $cfg['subject'] ?? '' );
        $scopes       = (array) ( $cfg['scopes'] ?? [] );

        $service_error = $this->serviceAccountError( $service );
        if ( $service_error !== '' ) {
            return [ 'ok' => false, 'error' => $service_error, 'stage' => 'config' ];
        }

        if ( $client_email === '' || $private_key === '' || $subject === '' || $scopes === [] ) {
            return [ 'ok' => false, 'error' => 'Workspace OAuth configuration is incomplete.', 'stage' => 'config' ];
        }

        $valid_subject = \function_exists( 'metis_email_is_valid' )
            ? \metis_email_is_valid( $subject )
            : filter_var( $subject, FILTER_VALIDATE_EMAIL );
        if ( ! $valid_subject ) {
            return [ 'ok' => false, 'error' => 'Delegated mailbox user must be a valid email address.', 'stage' => 'config' ];
        }

        $cache_key = 'metis_inbound_google_token_' . md5( $client_email . '|' . $subject . '|' . implode( ' ', $scopes ) );
        $cached = \metis_get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached['access_token'] ) ) {
            return [ 'ok' => true, 'access_token' => (string) $cached['access_token'] ];
        }

        $header = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
        $now = time();
        $claims = [
            'iss'   => $client_email,
            'scope' => implode( ' ', $scopes ),
            'aud'   => $token_uri,
            'iat'   => $now,
            'exp'   => $now + 3600,
            'sub'   => $subject,
        ];

        $jwt_input = self::b64urlEncode( \metis_json_encode( $header ) ) . '.' . self::b64urlEncode( \metis_json_encode( $claims ) );
        $signature = '';
        $signed = openssl_sign( $jwt_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
        if ( ! $signed ) {
            return [ 'ok' => false, 'error' => 'Could not sign Workspace JWT assertion.', 'stage' => 'jwt_sign' ];
        }

        $response = \metis_runtime_remote_post(
            $token_uri,
            [
                'timeout' => 20,
                'body'    => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt_input . '.' . self::b64urlEncode( $signature ),
                ],
            ]
        );

        if ( \metis_runtime_is_error( $response ) ) {
            return [
                'ok'    => false,
                'error' => 'Workspace token request failed: ' . $this->runtimeErrorMessage( $response ),
                'stage' => 'token_request',
            ];
        }

        $code = (int) \metis_runtime_remote_retrieve_response_code( $response );
        $raw  = (string) \metis_runtime_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            return [
                'ok'     => false,
                'error'  => $this->tokenRequestError( $code, $body, $raw ),
                'status' => $code,
                'stage'  => 'token_request',
                'body'   => is_array( $body ) ? $body : [],
                'raw'    => $raw,
            ];
        }

        $access_token = (string) $body['access_token'];
        $ttl = max( 120, ( (int) ( $body['expires_in'] ?? 3600 ) ) - 60 );
        \metis_set_transient( $cache_key, [ 'access_token' => $access_token ], $ttl );

        return [ 'ok' => true, 'access_token' => $access_token ];
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function request( string $method, string $url, array $cfg, array|string|null $body = null, array $headers = [] ): array {
        $token = $this->accessToken( $cfg );
        if ( empty( $token['ok'] ) ) {
            return [
                'ok'          => false,
                'error'       => (string) ( $token['error'] ?? 'Workspace token error.' ),
                'stage'       => (string) ( $token['stage'] ?? 'token_request' ),
                'token_error' => $token,
            ];
        }

        $request_headers = array_merge(
            [
                'Authorization' => 'Bearer ' . (string) $token['access_token'],
                'Content-Type'  => 'application/json',
            ],
            $headers
        );

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 45,
            'headers' => $request_headers,
        ];

        if ( is_array( $body ) ) {
            $args['body'] = \metis_json_encode( $body );
        } elseif ( is_string( $body ) ) {
            $args['body'] = $body;
        }

        $response = \metis_runtime_remote_request( $url, $args );
        if ( \metis_runtime_is_error( $response ) ) {
            return [ 'ok' => false, 'error' => 'Google API request failed.' ];
        }

        $code = (int) \metis_runtime_remote_retrieve_response_code( $response );
        $raw = (string) \metis_runtime_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            return [
                'ok'     => false,
                'status' => $code,
                'error'  => 'Google API request failed (' . $code . ').',
                'raw'    => $raw,
                'body'   => is_array( $decoded ) ? $decoded : [],
            ];
        }

        return [
            'ok'     => true,
            'status' => $code,
            'body'   => is_array( $decoded ) ? $decoded : [],
            'raw'    => $raw,
        ];
    }

    /**
     * @param array<string, mixed> $mailbox
     * @return array<string, mixed>
     */
    public function diagnoseMailbox( array $mailbox, array $scopes = [] ): array {
        $cfg = $this->configForMailbox( $mailbox, $scopes );
        $token = $this->accessToken( $cfg );
        $service = (array) ( $cfg['service'] ?? [] );

        return [
            'ok'            => ! empty( $token['ok'] ),
            'mailbox_email' => strtolower( trim( (string) ( $mailbox['mailbox_email'] ?? '' ) ) ),
            'delegated_user'=> strtolower( trim( (string) ( $cfg['subject'] ?? '' ) ) ),
            'client_email'  => (string) ( $service['client_email'] ?? '' ),
            'scopes'        => array_values( array_map( 'strval', (array) ( $cfg['scopes'] ?? [] ) ) ),
            'error'         => (string) ( $token['error'] ?? '' ),
            'stage'         => (string) ( $token['stage'] ?? '' ),
            'status'        => (int) ( $token['status'] ?? 0 ),
            'body'          => (array) ( $token['body'] ?? [] ),
        ];
    }

    public static function b64urlEncode( string $value ): string {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    public static function b64urlDecode( string $value ): string {
        $remainder = strlen( $value ) % 4;
        if ( $remainder > 0 ) {
            $value .= str_repeat( '=', 4 - $remainder );
        }

        return (string) base64_decode( strtr( $value, '-_', '+/' ) );
    }

    /**
     * @param array<string, mixed> $service
     */
    private function serviceAccountError( array $service ): string {
        if ( ! \function_exists( 'metis_workspace_service_account_error' ) ) {
            return '';
        }

        return (string) \metis_workspace_service_account_error( $service );
    }

    private function runtimeErrorMessage( mixed $response ): string {
        if ( is_object( $response ) && method_exists( $response, 'get_error_message' ) ) {
            return trim( (string) $response->get_error_message() );
        }

        return 'Remote request could not be completed.';
    }

    /**
     * @param array<string, mixed>|mixed $body
     */
    private function tokenRequestError( int $code, mixed $body, string $raw ): string {
        $error = '';
        $description = '';

        if ( is_array( $body ) ) {
            $error = trim( (string) ( $body['error'] ?? '' ) );
            $description = trim( (string) ( $body['error_description'] ?? $body['error_summary'] ?? '' ) );
        }

        $parts = [ 'Workspace token request failed (' . $code . ')' ];
        if ( $error !== '' ) {
            $parts[] = $error;
        }
        if ( $description !== '' ) {
            $parts[] = $description;
        } elseif ( $raw !== '' && ! is_array( $body ) ) {
            $parts[] = trim( $raw );
        }

        return implode( ': ', array_filter( $parts, static fn ( string $part ): bool => $part !== '' ) );
    }
}
