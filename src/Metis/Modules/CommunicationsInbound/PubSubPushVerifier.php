<?php
declare(strict_types=1);

namespace Metis\Modules\CommunicationsInbound;

final class PubSubPushVerifier {
    public function verifyRequest( \Metis_Http_Request $request ): array {
        if ( strtoupper( $request->method() ) !== 'POST' ) {
            throw new \Metis_Webhook_Exception( 'Webhook method not allowed.', 405, 'webhook_method_invalid' );
        }

        $config = Settings::config();
        $expected_audience = trim( (string) ( $config['pubsub_audience'] ?? '' ) );
        $expected_email = strtolower( trim( (string) ( $config['pubsub_service_account_email'] ?? '' ) ) );
        if ( $expected_audience === '' || $expected_email === '' ) {
            throw new \Metis_Webhook_Exception( 'Inbound Pub/Sub auth is not configured.', 503, 'webhook_provider_unconfigured' );
        }

        $authorization = trim( $request->header( 'authorization' ) );
        if ( ! preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub bearer token is missing.', 401, 'webhook_signature_missing' );
        }

        $claims = $this->validateJwt( trim( (string) $matches[1] ), $expected_audience, $expected_email );
        $envelope = $this->decodeEnvelope( $request->body() );
        $notification = $this->decodeNotification( $envelope );

        return [
            'event_id'    => 'pubsub:' . (string) ( $envelope['message']['messageId'] ?? '' ),
            'event_type'  => 'gmail.history_update',
            'resource_id' => (string) ( $notification['emailAddress'] ?? '' ),
            'payload'     => [
                'notification' => $notification,
                'envelope'     => $envelope,
                'auth'         => [
                    'email' => (string) ( $claims['email'] ?? '' ),
                    'aud'   => (string) ( $claims['aud'] ?? '' ),
                    'iss'   => (string) ( $claims['iss'] ?? '' ),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeEnvelope( string $raw_body ): array {
        $decoded = json_decode( $raw_body, true );
        if ( ! is_array( $decoded ) || ! is_array( $decoded['message'] ?? null ) ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub payload is malformed.', 400, 'webhook_payload_invalid' );
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public function decodeNotification( array $envelope ): array {
        $data = (string) ( $envelope['message']['data'] ?? '' );
        if ( $data === '' ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub message data is missing.', 400, 'webhook_payload_invalid' );
        }

        $decoded = json_decode( WorkspaceGoogleService::b64urlDecode( $data ), true );
        if ( ! is_array( $decoded ) || empty( $decoded['emailAddress'] ) || empty( $decoded['historyId'] ) ) {
            throw new \Metis_Webhook_Exception( 'Gmail notification payload is invalid.', 400, 'webhook_payload_invalid' );
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateJwt( string $jwt, string $expected_audience, string $expected_email ): array {
        $parts = explode( '.', $jwt );
        if ( count( $parts ) !== 3 ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub bearer token is invalid.', 401, 'webhook_signature_invalid' );
        }

        $header = json_decode( WorkspaceGoogleService::b64urlDecode( $parts[0] ), true );
        $claims = json_decode( WorkspaceGoogleService::b64urlDecode( $parts[1] ), true );
        $signature = WorkspaceGoogleService::b64urlDecode( $parts[2] );

        if ( ! is_array( $header ) || ! is_array( $claims ) || $signature === '' ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub bearer token is invalid.', 401, 'webhook_signature_invalid' );
        }

        $kid = trim( (string) ( $header['kid'] ?? '' ) );
        if ( $kid === '' ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub bearer token is missing a key id.', 401, 'webhook_signature_invalid' );
        }

        $cert = $this->certificates()[ $kid ] ?? '';
        if ( $cert === '' ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub signing certificate is unavailable.', 401, 'webhook_signature_invalid' );
        }

        $verified = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $cert, OPENSSL_ALGO_SHA256 );
        if ( $verified !== 1 ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub bearer token signature verification failed.', 401, 'webhook_signature_invalid' );
        }

        $now = time();
        $issuer = (string) ( $claims['iss'] ?? '' );
        $email = strtolower( trim( (string) ( $claims['email'] ?? '' ) ) );
        $audience = (string) ( $claims['aud'] ?? '' );
        $email_verified = filter_var( $claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN );
        $expires_at = (int) ( $claims['exp'] ?? 0 );

        if ( ! in_array( $issuer, [ 'accounts.google.com', 'https://accounts.google.com' ], true ) ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub issuer is invalid.', 401, 'webhook_signature_invalid' );
        }

        if ( $audience !== $expected_audience ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub audience claim is invalid.', 401, 'webhook_signature_invalid' );
        }

        if ( $email !== $expected_email || ! $email_verified ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub service account claim is invalid.', 401, 'webhook_signature_invalid' );
        }

        if ( $expires_at <= $now ) {
            throw new \Metis_Webhook_Exception( 'Pub/Sub bearer token has expired.', 401, 'webhook_signature_invalid' );
        }

        return $claims;
    }

    /**
     * @return array<string, string>
     */
    private function certificates(): array {
        $cache_key = 'metis_inbound_pubsub_google_certs';
        $cached = \metis_get_transient( $cache_key );
        if ( is_array( $cached ) && $cached !== [] ) {
            return array_map( 'strval', $cached );
        }

        $response = \metis_runtime_remote_get( 'https://www.googleapis.com/oauth2/v1/certs', [ 'timeout' => 20 ] );
        if ( \metis_runtime_is_error( $response ) ) {
            return [];
        }

        $code = (int) \metis_runtime_remote_retrieve_response_code( $response );
        $body = json_decode( (string) \metis_runtime_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            return [];
        }

        \metis_set_transient( $cache_key, $body, 6 * HOUR_IN_SECONDS );
        return array_map( 'strval', $body );
    }
}
