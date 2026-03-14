<?php
declare(strict_types=1);

namespace Metis\Http;

final class Request {
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $parsed_body = [],
        private readonly array $headers = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private readonly array $server = [],
        private readonly string $body = '',
        private readonly array $attributes = []
    ) {}

    public function method(): string {
        return $this->method;
    }

    public function uri(): string {
        return $this->uri;
    }

    public function path(): string {
        return $this->path;
    }

    public function query(): array {
        return $this->query;
    }

    public function parsed_body(): array {
        return $this->parsed_body;
    }

    public function input(): array {
        return array_replace_recursive( $this->query, $this->parsed_body );
    }

    public function headers(): array {
        return $this->headers;
    }

    public function header( string $name, string $default = '' ): string {
        $key = strtolower( $name );
        return isset( $this->headers[ $key ] ) ? (string) $this->headers[ $key ] : $default;
    }

    public function cookies(): array {
        return $this->cookies;
    }

    public function files(): array {
        return $this->files;
    }

    public function server(): array {
        return $this->server;
    }

    public function body(): string {
        return $this->body;
    }

    public function attributes(): array {
        return $this->attributes;
    }

    public function attribute( string $key, mixed $default = null ): mixed {
        return $this->attributes[ $key ] ?? $default;
    }

    public function with_attribute( string $key, mixed $value ): self {
        $attributes         = $this->attributes;
        $attributes[ $key ] = $value;

        return new self(
            $this->method,
            $this->uri,
            $this->path,
            $this->query,
            $this->parsed_body,
            $this->headers,
            $this->cookies,
            $this->files,
            $this->server,
            $this->body,
            $attributes
        );
    }
}
