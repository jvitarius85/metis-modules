<?php
declare(strict_types=1);

namespace Metis\Modules\Media\Requests;

final class DeleteMediaRequest {
    private function __construct(
        private readonly string $token
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['token'] ) ? strtolower( trim( \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['token'] ) ) ) ) : ''
        );
    }

    public function token(): string { return $this->token; }
}
