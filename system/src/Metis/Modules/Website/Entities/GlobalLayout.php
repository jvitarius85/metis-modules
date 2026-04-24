<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Entities;

/**
 * GlobalLayout Entity
 *
 * Represents a global header or footer layout.
 * Stored as structured block JSON and injected at render time.
 */
final class GlobalLayout {
    public ?int    $id          = null;
    public ?string $layout_code = null;
    public string  $type        = 'header'; // 'header' or 'footer'
    public string  $name        = '';
    public ?string $layout_json = null;
    public string  $status      = 'draft';
    public bool    $is_default  = false;
    public ?int    $created_by  = null;
    public ?int    $updated_by  = null;
    public ?string $created_at  = null;
    public ?string $updated_at  = null;

    public static function fromRow( array $row ): self {
        $l              = new self();
        $l->id          = isset( $row['id'] ) ? (int) $row['id'] : null;
        $l->layout_code = $row['layout_code'] ?? null;
        $l->type        = (string) ( $row['type'] ?? 'header' );
        $l->name        = (string) ( $row['name'] ?? '' );
        $l->layout_json = $row['layout_json'] ?? null;
        $l->status      = (string) ( $row['status'] ?? 'draft' );
        $l->is_default  = ! empty( $row['is_default'] );
        $l->created_by  = isset( $row['created_by'] ) ? (int) $row['created_by'] : null;
        $l->updated_by  = isset( $row['updated_by'] ) ? (int) $row['updated_by'] : null;
        $l->created_at  = $row['created_at'] ?? null;
        $l->updated_at  = $row['updated_at'] ?? null;
        return $l;
    }

    public function getBlocks(): array {
        if ( $this->layout_json === null ) {
            return [];
        }
        $decoded = json_decode( $this->layout_json, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }
        return $decoded['sections'] ?? $decoded['blocks'] ?? [];
    }

    public function toArray(): array {
        return [
            'id'          => $this->id,
            'layout_code' => $this->layout_code,
            'type'        => $this->type,
            'name'        => $this->name,
            'layout_json' => $this->layout_json,
            'status'      => $this->status,
            'is_default'  => $this->is_default ? 1 : 0,
            'created_by'  => $this->created_by,
            'updated_by'  => $this->updated_by,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
