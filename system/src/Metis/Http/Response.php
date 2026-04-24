<?php
declare(strict_types=1);

namespace Metis\Http;

final class Response {
    public function __construct(
        private readonly int $status = 200,
        private readonly array $headers = [],
        private readonly string $body = ''
    ) {}

    public function status(): int {
        return $this->status;
    }

    public function headers(): array {
        return $this->headers;
    }

    public function body(): string {
        return $this->body;
    }

    public static function html( string $body, int $status = 200, array $headers = [] ): self {
        return new self(
            $status,
            array_merge( [ 'Content-Type' => 'text/html; charset=UTF-8' ], $headers ),
            $body
        );
    }

    public static function json( array $payload, int $status = 200, array $headers = [] ): self {
        return new self(
            $status,
            array_merge( [ 'Content-Type' => 'application/json; charset=UTF-8' ], $headers ),
            \metis_json_encode( $payload ) ?: '{}'
        );
    }

    public static function redirect( string $location, int $status = 302, array $headers = [] ): self {
        return new self( $status, array_merge( [ 'Location' => $location ], $headers ), '' );
    }
}
