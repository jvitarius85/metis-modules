<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use RuntimeException;

final class CsrfService {
    public function __construct(
        private readonly AuditLogService $audit = new AuditLogService()
    ) {}

    public function createToken( string $action ): string {
        if ( \function_exists( 'metis_runtime_create_nonce' ) ) {
            return (string) \metis_runtime_create_nonce( $action );
        }

        return '';
    }

    public function validateToken( string $token, string $action ): bool {
        if ( $token === '' || ! \function_exists( 'metis_runtime_verify_nonce' ) ) {
            return false;
        }

        return (bool) \metis_runtime_verify_nonce( $token, $action );
    }

    public function tokenFromInput( array $input ): string {
        foreach ( [ 'csrf_token', 'metis_action_nonce', 'security', 'nonce' ] as $field ) {
            if ( ! isset( $input[ $field ] ) || ! \is_scalar( $input[ $field ] ) ) {
                continue;
            }

            return \metis_text_clean( (string) $input[ $field ] );
        }

        return '';
    }

    public function requireValidToken( array $input, string $action, string $message = 'Invalid request nonce.' ): void {
        $token = $this->tokenFromInput( $input );
        if ( $this->validateToken( $token, $action ) ) {
            return;
        }

        $endpoint = '';
        if ( \function_exists( 'metis_runtime_parse_url' ) ) {
            $endpoint = (string) \metis_runtime_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
        }
        if ( $endpoint === '' ) {
            $endpoint = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        }
        $request_id = \function_exists( 'metis_audit_request_id' ) ? (string) \metis_audit_request_id() : '';
        $nonce_fp = $token === '' ? '' : substr( hash( 'sha256', $token ), 0, 12 ) . ':' . strlen( $token );

        $this->audit->security( 'csrf_failed', [
            'action' => $action,
            'endpoint' => $endpoint,
            'method' => strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ),
            'request_id' => $request_id,
            'nonce_present' => $token !== '',
            'nonce_fingerprint' => $nonce_fp,
        ] );

        throw new RuntimeException( $message );
    }

    public function hiddenFields( string $action, string $token_field = 'csrf_token', string $action_field = 'metis_csrf_action' ): string {
        return '<input type="hidden" name="' . \metis_esc_attr( $token_field ) . '" value="' . \metis_esc_attr( $this->createToken( $action ) ) . '">'
            . '<input type="hidden" name="' . \metis_esc_attr( $action_field ) . '" value="' . \metis_esc_attr( $action ) . '">';
    }
}
