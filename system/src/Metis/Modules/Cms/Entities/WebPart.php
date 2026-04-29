<?php
declare(strict_types=1);

namespace Metis\Modules\Cms\Entities;

/**
 * WebPart Entity
 *
 * Reusable cms component attached by target + placement rules.
 */
final class WebPart {
    public ?int $id = null;
    public ?string $part_code = null;
    public string $name = '';
    public string $part_type = 'custom';
    public string $render_mode = 'blocks';
    public string $status = 'draft';
    public ?string $content_json = null;
    public ?string $config_json = null;
    public ?string $visibility_json = null;
    public string $target_scope = 'site';
    public ?string $target_ref = null;
    public string $region = 'main';
    public string $slot = 'append';
    public int $sort_order = 0;
    public ?int $created_by = null;
    public ?int $updated_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public static function fromRow( array $row ): self {
        $part = new self();
        $part->id = isset( $row['id'] ) ? (int) $row['id'] : null;
        $part->part_code = isset( $row['part_code'] ) ? (string) $row['part_code'] : null;
        $part->name = (string) ( $row['name'] ?? '' );
        $part->part_type = (string) ( $row['part_type'] ?? 'custom' );
        $part->render_mode = (string) ( $row['render_mode'] ?? 'blocks' );
        $part->status = (string) ( $row['status'] ?? 'draft' );
        $part->content_json = isset( $row['content_json'] ) ? (string) $row['content_json'] : null;
        $part->config_json = isset( $row['config_json'] ) ? (string) $row['config_json'] : null;
        $part->visibility_json = isset( $row['visibility_json'] ) ? (string) $row['visibility_json'] : null;
        $part->target_scope = (string) ( $row['target_scope'] ?? 'site' );
        $part->target_ref = isset( $row['target_ref'] ) ? (string) $row['target_ref'] : null;
        $part->region = (string) ( $row['region'] ?? 'main' );
        $part->slot = (string) ( $row['slot'] ?? 'append' );
        $part->sort_order = isset( $row['sort_order'] ) ? (int) $row['sort_order'] : 0;
        $part->created_by = isset( $row['created_by'] ) ? (int) $row['created_by'] : null;
        $part->updated_by = isset( $row['updated_by'] ) ? (int) $row['updated_by'] : null;
        $part->created_at = isset( $row['created_at'] ) ? (string) $row['created_at'] : null;
        $part->updated_at = isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null;

        return $part;
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'part_code' => $this->part_code,
            'name' => $this->name,
            'part_type' => $this->part_type,
            'render_mode' => $this->render_mode,
            'status' => $this->status,
            'content_json' => $this->content_json,
            'config_json' => $this->config_json,
            'visibility_json' => $this->visibility_json,
            'target_scope' => $this->target_scope,
            'target_ref' => $this->target_ref,
            'region' => $this->region,
            'slot' => $this->slot,
            'sort_order' => $this->sort_order,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
