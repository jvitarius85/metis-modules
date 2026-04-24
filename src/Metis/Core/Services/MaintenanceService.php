<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use RuntimeException;

final class MaintenanceService {
    public function __construct(
        private readonly CsrfService $csrf = new CsrfService(),
        private readonly AuditLogService $audit = new AuditLogService()
    ) {}

    public function assertAuthorizedMutation( string $nonce_action ): void {
        if ( ! \function_exists( 'metis_user_logged_in' ) || ! \metis_user_logged_in() ) {
            throw new RuntimeException( 'Authentication is required.' );
        }

        if ( ! \function_exists( 'metis_current_user_can' ) || ! \metis_current_user_can( 'manage_options' ) ) {
            throw new RuntimeException( 'Administrator access is required.' );
        }

        if ( \strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'POST' ) {
            throw new RuntimeException( 'This operation must be submitted with POST.' );
        }

        $this->csrf->requireValidToken( $_POST, $nonce_action );
    }

    public function renderPostConfirmation( string $title, string $message, string $nonce_action, array $hidden = [] ): never {
        $body = '<h1>' . \metis_escape_html( $title ) . '</h1>';
        $body .= '<p>' . \metis_escape_html( $message ) . '</p>';
        $body .= '<form method="post">';
        $body .= $this->csrf->hiddenFields( $nonce_action );
        foreach ( $hidden as $name => $value ) {
            $body .= '<input type="hidden" name="' . \metis_escape_attr( (string) $name ) . '" value="' . \metis_escape_attr( (string) $value ) . '">';
        }
        $body .= '<button type="submit">Confirm</button>';
        $body .= '</form>';

        if ( \function_exists( 'metis_auth_render_shell' ) ) {
            \metis_auth_render_shell( $title, $body );
        }

        \metis_runtime_die( $body, $title, [ 'response' => 200 ] );
    }

    public function auditMutation( string $action, array $context = [], array $resource = [] ): void {
        $this->audit->activity( $action, $context, [ 'resource' => $resource ] );
    }
}
