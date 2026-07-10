<?php
declare(strict_types=1);

namespace Metis\Modules\Media\Requests;

final class UploadMediaRequest {
    private function __construct(
        private readonly string $folderPath,
        private readonly string $categoryKey
    ) {}

    public static function fromGlobals(): self {
        return new self(
            isset( \metis_request_post()['folder_path'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['folder_path'] ) : '',
            isset( \metis_request_post()['category_key'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['category_key'] ) : ''
        );
    }

    public function folderPath(): string { return $this->folderPath; }
    public function categoryKey(): string { return $this->categoryKey; }
}
