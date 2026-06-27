<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Entities;

use Metis\Modules\Website\Services\EditorLayoutService;

/**
 * Post Entity
 *
 * Represents a blog post with structured block content.
 */
final class Post {
    public ?int    $id                       = null;
    public ?string $post_code                = null;
    public string  $title                    = '';
    public string  $slug                     = '';
    public ?string $excerpt                  = null;
    public ?string $content_json             = null;
    public ?string $draft_content_json       = null;
    public ?string $published_content_json   = null;
    public string  $status                   = 'draft';
    public ?string $publish_date             = null;
    public ?string $seo_meta_json            = null;
    public string  $page_type                = 'post';
    public string  $content_format           = 'standard';
    public ?string $template_key             = null;
    public ?int    $post_category_id         = null;
    /** @var array<int,int> */
    public array   $post_category_ids        = [];
    /** @var array<int,int> */
    public array   $post_tag_ids             = [];
    /** @var array<int,string> */
    public array   $post_tags                = [];
    public ?int    $parent_page_id           = null;
    public ?int    $author_id                = null;
    public ?int    $featured_image_id        = null;
    public ?string $featured_image_caption   = null;
    public ?int    $created_by               = null;
    public ?int    $updated_by               = null;
    public ?string $created_at               = null;
    public ?string $updated_at               = null;

    public static function fromRow( array $row ): self {
        $p                      = new self();
        $p->id                  = isset( $row['id'] ) ? (int) $row['id'] : null;
        $p->post_code           = $row['post_code'] ?? null;
        $p->title               = (string) ( $row['title'] ?? '' );
        $p->slug                = (string) ( $row['slug'] ?? '' );
        $p->excerpt             = $row['excerpt'] ?? null;
        $p->content_json        = $row['content_json'] ?? null;
        $p->draft_content_json  = $row['draft_content_json'] ?? null;
        $p->published_content_json = $row['published_content_json'] ?? null;
        $p->status              = (string) ( $row['status'] ?? 'draft' );
        $p->publish_date        = $row['publish_date'] ?? null;
        $p->seo_meta_json       = $row['seo_meta_json'] ?? null;
        $p->page_type           = isset( $row['page_type'] ) ? (string) $row['page_type'] : 'post';
        $p->content_format      = isset( $row['content_format'] ) ? (string) $row['content_format'] : 'standard';
        $p->template_key        = $row['template_key'] ?? null;
        $p->post_category_id    = isset( $row['post_category_id'] ) ? (int) $row['post_category_id'] : null;
        $p->post_category_ids   = [];
        $p->post_tag_ids        = [];
        $p->post_tags           = [];
        $p->parent_page_id      = isset( $row['parent_page_id'] ) ? (int) $row['parent_page_id'] : null;
        $p->author_id           = isset( $row['author_id'] ) ? (int) $row['author_id'] : null;
        $p->featured_image_id   = isset( $row['featured_image_id'] ) ? (int) $row['featured_image_id'] : null;
        $p->featured_image_caption = $row['featured_image_caption'] ?? null;
        $p->created_by          = isset( $row['created_by'] ) ? (int) $row['created_by'] : null;
        $p->updated_by          = isset( $row['updated_by'] ) ? (int) $row['updated_by'] : null;
        $p->created_at          = $row['created_at'] ?? null;
        $p->updated_at          = $row['updated_at'] ?? null;
        return $p;
    }

    public function toArray(): array {
        return [
            'id'                     => $this->id,
            'post_code'              => $this->post_code,
            'title'                  => $this->title,
            'slug'                   => $this->slug,
            'excerpt'                => $this->excerpt,
            'content_json'           => $this->content_json,
            'draft_content_json'     => $this->draft_content_json,
            'published_content_json' => $this->published_content_json,
            'status'                 => $this->status,
            'publish_date'           => $this->publish_date,
            'seo_meta_json'          => $this->seo_meta_json,
            'page_type'              => $this->page_type,
            'content_format'         => $this->content_format,
            'template_key'           => $this->template_key,
            'post_category_id'       => $this->post_category_id,
            'post_category_ids'      => array_values( array_map( 'intval', $this->post_category_ids ) ),
            'post_tag_ids'           => array_values( array_map( 'intval', $this->post_tag_ids ) ),
            'post_tags'              => array_values( array_map( 'strval', $this->post_tags ) ),
            'parent_page_id'         => $this->parent_page_id,
            'author_id'              => $this->author_id,
            'featured_image_id'      => $this->featured_image_id,
            'featured_image_caption' => $this->featured_image_caption,
            'created_by'             => $this->created_by,
            'updated_by'             => $this->updated_by,
            'created_at'             => $this->created_at,
            'updated_at'             => $this->updated_at,
        ];
    }

    public function getBlocks(): array {
        $content = $this->status === 'published' && $this->published_content_json !== null
            ? $this->published_content_json
            : ( $this->draft_content_json ?? $this->content_json );

        if ( $content === null ) {
            return [];
        }
        return EditorLayoutService::renderBlocksFromLayout( $content );
    }

    public function getSeoMeta(): array {
        if ( $this->seo_meta_json === null ) {
            return [];
        }
        $decoded = json_decode( $this->seo_meta_json, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
