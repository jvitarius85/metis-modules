<?php
declare(strict_types=1);

namespace Metis\Hermes\Nlu;

final class CommandValidator {
    /**
     * @param array<string,mixed> $command
     * @param array<string,mixed> $payload
     */
    public function readinessPenalty( string $intent, array $command, array $payload ): float {
        if ( ! empty( $command['expects_entity'] ) && trim( (string) ( $payload['subject'] ?? '' ) ) === '' ) {
            return 0.18;
        }

        if ( in_array( $intent, [ 'workspace_user_password_reset', 'user_password_reset', 'user_delete', 'campaign_delete' ], true )
            && trim( (string) ( $payload['subject'] ?? '' ) ) === '' ) {
            return 0.24;
        }

        return 0.0;
    }
}
