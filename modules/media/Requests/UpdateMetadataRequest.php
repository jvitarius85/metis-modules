<?php
declare(strict_types=1);

namespace Metis\Modules\Media\Requests;

final class UpdateMetadataRequest {
    private function __construct(
        private readonly string $token,
        private readonly string $folderPath,
        private readonly string $categoryKey
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['token'] ) ? strtolower( trim( \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['token'] ) ) ) ) : '',
            isset( \metis_request_post()['folder_path'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['folder_path'] ) : '',
            isset( \metis_request_post()['category_key'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['category_key'] ) : ''
        );
    }

    public function token(): string { return $this->token; }
    public function folderPath(): string { return $this->folderPath; }
    public function categoryKey(): string { return $this->categoryKey; }
}
