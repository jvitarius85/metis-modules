<?php
declare(strict_types=1);

namespace Metis\Modules\Testimonies\Requests;

final class SavePayloadRequest {
    private function __construct(
        private readonly array $payload,
        private readonly int $userId
    ) {}

    public static function forKey( string $key ): self {
        $raw = '';
        if ( isset( \metis_request_post()[ $key ] ) ) {
            $raw = \metis_runtime_unslash( \metis_request_post()[ $key ] );
        }

        $payload = [];
        if ( is_array( $raw ) ) {
            $payload = $raw;
        } elseif ( is_string( $raw ) && trim( $raw ) !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $payload = $decoded;
            }
        }

        return new self(
            $payload,
            function_exists( 'metis_current_user_id' ) ? (int) \metis_current_user_id() : 0
        );
    }

    public function payload(): array {
        return $this->payload;
    }

    public function userId(): int {
        return $this->userId;
    }
}
