<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Entities;

use Metis\Modules\Website\Services\EditorLayoutService;

/**
 * Page Entity
 *
 * Represents a website page with structured block content.
 */
final class Page {
    public ?int    $id                    = null;
    public ?string $page_code             = null;
    public string  $title                 = '';
    public string  $slug                  = '';
    public string  $status                = 'draft';
    public ?string $layout_json           = null;
    public ?string $draft_layout_json     = null;
    public ?string $published_layout_json = null;
    public ?string $seo_meta_json         = null;
    public string  $page_type             = 'page';
    public ?string $template_key          = null;
    public ?int    $parent_id             = null;
    public int     $menu_order            = 0;
    public ?int    $created_by            = null;
    public ?int    $updated_by            = null;
    public ?string $published_at          = null;
    public ?string $created_at            = null;
    public ?string $updated_at            = null;

    public static function fromRow( array $row ): self {
        $p                        = new self();
        $p->id                    = isset( $row['id'] ) ? (int) $row['id'] : null;
        $p->page_code             = $row['page_code'] ?? null;
        $p->title                 = (string) ( $row['title'] ?? '' );
        $p->slug                  = (string) ( $row['slug'] ?? '' );
        $p->status                = (string) ( $row['status'] ?? 'draft' );
        $p->layout_json           = $row['layout_json'] ?? null;
        $p->draft_layout_json     = $row['draft_layout_json'] ?? null;
        $p->published_layout_json = $row['published_layout_json'] ?? null;
        $p->seo_meta_json         = $row['seo_meta_json'] ?? null;
        $p->page_type             = isset( $row['page_type'] ) ? (string) $row['page_type'] : 'page';
        $p->template_key          = $row['template_key'] ?? null;
        $p->parent_id             = isset( $row['parent_id'] ) ? (int) $row['parent_id'] : null;
        $p->menu_order            = isset( $row['menu_order'] ) ? (int) $row['menu_order'] : 0;
        $p->created_by            = isset( $row['created_by'] ) ? (int) $row['created_by'] : null;
        $p->updated_by            = isset( $row['updated_by'] ) ? (int) $row['updated_by'] : null;
        $p->published_at          = $row['published_at'] ?? null;
        $p->created_at            = $row['created_at'] ?? null;
        $p->updated_at            = $row['updated_at'] ?? null;
        return $p;
    }

    public function toArray(): array {
        return [
            'id'                    => $this->id,
            'page_code'             => $this->page_code,
            'title'                 => $this->title,
            'slug'                  => $this->slug,
            'status'                => $this->status,
            'layout_json'           => $this->layout_json,
            'draft_layout_json'     => $this->draft_layout_json,
            'published_layout_json' => $this->published_layout_json,
            'seo_meta_json'         => $this->seo_meta_json,
            'page_type'             => $this->page_type,
            'template_key'          => $this->template_key,
            'parent_id'             => $this->parent_id,
            'menu_order'            => $this->menu_order,
            'created_by'            => $this->created_by,
            'updated_by'            => $this->updated_by,
            'published_at'          => $this->published_at,
            'created_at'            => $this->created_at,
            'updated_at'            => $this->updated_at,
        ];
    }

    public function getBlocks(): array {
        $layout  = $this->status === 'published' && $this->published_layout_json !== null
            ? $this->published_layout_json
            : ( $this->draft_layout_json ?? $this->layout_json );

        if ( $layout === null ) {
            return [];
        }
        return EditorLayoutService::renderBlocksFromLayout( $layout );
    }

    public function getSeoMeta(): array {
        if ( $this->seo_meta_json === null ) {
            return [];
        }
        $decoded = json_decode( $this->seo_meta_json, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
