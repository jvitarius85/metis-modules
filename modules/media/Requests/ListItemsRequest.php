<?php
declare(strict_types=1);

namespace Metis\Modules\Media\Requests;

final class ListItemsRequest {
    private function __construct(
        private readonly string $search,
        private readonly string $type,
        private readonly string $folder,
        private readonly string $category,
        private readonly string $sort,
        private readonly int $limit
    ) {}

    public static function fromGlobals(): self {
        $folder = isset( \metis_request_post()['folder'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['folder'] ) : '';
        $category = isset( \metis_request_post()['category'] ) ? (string) \metis_runtime_unslash( \metis_request_post()['category'] ) : '';

        return new self(
            isset( \metis_request_post()['search'] ) ? trim( \metis_text_clean( \metis_runtime_unslash( \metis_request_post()['search'] ) ) ) : '',
            isset( \metis_request_post()['type'] ) ? trim( \metis_key_clean( \metis_runtime_unslash( \metis_request_post()['type'] ) ) ) : '',
            function_exists( 'metis_media_normalize_folder_path' ) ? \metis_media_normalize_folder_path( $folder ) : \metis_slug_clean( $folder ),
            function_exists( 'metis_media_normalize_category_key' ) ? \metis_media_normalize_category_key( $category ) : \metis_key_clean( $category ),
            isset( \metis_request_post()['sort'] ) ? trim( \metis_key_clean( \metis_runtime_unslash( \metis_request_post()['sort'] ) ) ) : 'created_desc',
            isset( \metis_request_post()['limit'] ) ? (int) \metis_request_post()['limit'] : 80
        );
    }

    public function search(): string { return $this->search; }
    public function type(): string { return $this->type; }
    public function folder(): string { return $this->folder; }
    public function category(): string { return $this->category; }
    public function sort(): string { return $this->sort; }
    public function limit(): int { return $this->limit; }
}
