<?php
declare(strict_types=1);

namespace Metis\Core\Serialization;

final class LegacyPhpSerializedPayload {
    public static function decodeArray( string $payload, int $maxBytes = 524288 ): ?array {
        $payload = trim( $payload );
        if ( $payload === '' || strlen( $payload ) > $maxBytes ) {
            return null;
        }

        if ( preg_match( '/(?:^|[;:{])(?:O|C):\d+:/', $payload ) === 1 ) {
            return null;
        }

        set_error_handler( static fn (): bool => true );
        try {
            $decoded = unserialize( $payload, [ 'allowed_classes' => false ] );
        } finally {
            restore_error_handler();
        }

        return is_array( $decoded ) ? $decoded : null;
    }
}
