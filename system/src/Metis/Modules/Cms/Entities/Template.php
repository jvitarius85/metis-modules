<?php
declare(strict_types=1);

namespace Metis\Modules\Cms\Entities;

/**
 * Template Entity
 *
 * Represents a structural cms template for page, post, or archive views.
 */
final class Template {
    public ?int $id = null;
    public string $template_key = '';
    public string $name = '';
    public string $template_type = 'page';
    public string $status = 'published';
    public ?string $structure_json = null;
    public bool $is_default = false;
    public ?int $created_by = null;
    public ?int $updated_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public static function fromRow( array $row ): self {
        $template = new self();
        $template->id = isset( $row['id'] ) ? (int) $row['id'] : null;
        $template->template_key = (string) ( $row['template_key'] ?? '' );
        $template->name = (string) ( $row['name'] ?? '' );
        $template->template_type = (string) ( $row['template_type'] ?? 'page' );
        $template->status = (string) ( $row['status'] ?? 'published' );
        $template->structure_json = $row['structure_json'] ?? null;
        $template->is_default = ! empty( $row['is_default'] );
        $template->created_by = isset( $row['created_by'] ) ? (int) $row['created_by'] : null;
        $template->updated_by = isset( $row['updated_by'] ) ? (int) $row['updated_by'] : null;
        $template->created_at = $row['created_at'] ?? null;
        $template->updated_at = $row['updated_at'] ?? null;
        return $template;
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'template_key' => $this->template_key,
            'name' => $this->name,
            'template_type' => $this->template_type,
            'status' => $this->status,
            'structure_json' => $this->structure_json,
            'is_default' => $this->is_default ? 1 : 0,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
