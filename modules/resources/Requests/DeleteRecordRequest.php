<?php
declare(strict_types=1);

namespace Metis\Modules\Resources\Requests;

final class DeleteRecordRequest {
    private function __construct(
        private readonly int $recordId
    ) {}

    public static function fromPostKey( string $key = 'id' ): self {
        $value = 0;
        if ( isset( \metis_request_post()[ $key ] ) ) {
            $value = (int) \metis_runtime_unslash( \metis_request_post()[ $key ] );
        }

        return new self( max( 0, $value ) );
    }

    public function recordId(): int {
        return $this->recordId;
    }
}
