<?php
declare(strict_types=1);

namespace Metis\Modules\Resources\Requests;

use Metis\Http\Request;

final class PublicRouteRequest {
    private function __construct(
        private readonly string $type,
        private readonly string $category,
        private readonly string $resource,
        private readonly array $query
    ) {}

    public static function fromRequest( Request $request ): self {
        return new self(
            \metis_slug_clean( (string) $request->attribute( 'type', '' ) ),
            \metis_slug_clean( (string) $request->attribute( 'category', '' ) ),
            \metis_slug_clean( (string) $request->attribute( 'resource', '' ) ),
            (array) $request->query()
        );
    }

    public function type(): string {
        return $this->type;
    }

    public function category(): string {
        return $this->category;
    }

    public function resource(): string {
        return $this->resource;
    }

    public function query(): array {
        return $this->query;
    }
}
