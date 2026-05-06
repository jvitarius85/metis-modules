<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Modules\Website\Entities\Template;
use Metis\Modules\Website\Entities\Page;
use Metis\Modules\Website\Entities\Post;

final class TemplateService {
    private const ACTIVE_TEMPLATE_KEY = 'website_active_template_slug';
    private const SIDEBAR_VARIANT_SLUGS = [ 'editorial_focus', 'modular_grid_dash' ];

    /**
     * @return array<int,Template>
     */
    public static function getAll( string $type = '' ): array {
        $templates = self::discoverTemplates();
        $active = self::getActiveTemplateSlug();
        $items = [];
        $i = 1;
        foreach ( $templates as $template ) {
            $slug = (string) ( $template['slug'] ?? '' );
            $items[] = self::toEntity( $template, $i++, $slug === $active );
        }
        return $items;
    }

    public static function getByTemplateKey( string $template_key ): ?Template {
        $slug = self::canonicalTemplateKeyOrRaw( $template_key );
        if ( $slug === '' ) {
            return null;
        }
        $templates = self::discoverTemplates();
        $i = 1;
        $active = self::getActiveTemplateSlug();
        foreach ( $templates as $template ) {
            if ( (string) ( $template['slug'] ?? '' ) !== $slug ) {
                $i++;
                continue;
            }
            return self::toEntity( $template, $i, $slug === $active );
        }
        return null;
    }

    public static function getById( int $id ): ?Template {
        if ( $id < 1 ) {
            return null;
        }
        $templates = self::discoverTemplates();
        $active = self::getActiveTemplateSlug();
        $i = 1;
        foreach ( $templates as $template ) {
            if ( $i === $id ) {
                $slug = (string) ( $template['slug'] ?? '' );
                return self::toEntity( $template, $i, $slug === $active );
            }
            $i++;
        }
        return null;
    }

    public static function getDefault( string $type ): ?Template {
        $page_type = self::normalizePageType( $type, false );
        $template_key = StructuredWebsiteBuilderService::defaultTemplateForPageType( $page_type );
        return self::getByTemplateKey( $template_key );
    }

    public static function resolveForPage( Page $page ): array {
        $page_type = self::pageTypeFromEntity( $page );
        $template_key = self::templateKeyFromEntity( $page, $page_type, false );
        return self::resolveStructuredTemplateLayout( $template_key, $page_type );
    }

    public static function resolveForPost( Post $post ): array {
        $page_type = self::pageTypeFromEntity( $post, true );
        $template_key = self::templateKeyFromEntity( $post, $page_type, true );
        return self::resolveStructuredTemplateLayout( $template_key, $page_type );
    }

    public static function isValidTemplateKey( string $template_key ): bool {
        $candidate = self::canonicalTemplateKeyOrRaw( $template_key );
        return $candidate !== '' && self::templateMeta( $candidate ) !== [];
    }

    public static function normalizeTemplateKeyForPageType( string $template_key, string $page_type ): string {
        $candidate = self::canonicalTemplateKeyOrRaw( $template_key );
        if ( $candidate !== '' && self::templateMeta( $candidate ) !== [] ) {
            return self::normalizeStructuredTemplateKey( $candidate, $page_type );
        }

        return self::normalizeStructuredTemplateKey( self::getActiveTemplateSlug(), $page_type );
    }

    public static function resolveForArchive(): array {
        return self::resolveStructuredTemplateLayout(
            self::getActiveTemplateSlug(),
            'page'
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function resolveForPreview( string $template_key, string $page_type ): array {
        $candidate = metis_key_clean( $template_key );
        if ( $candidate === '' ) {
            $candidate = self::getActiveTemplateSlug();
        }
        return self::resolveStructuredTemplateLayout( $candidate, $page_type );
    }

    /**
     * @return array<string,mixed>
     */
    public static function resolveStructureForSlug( string $slug ): array {
        $target = self::canonicalTemplateKeyOrRaw( $slug );
        if ( $target === '' ) {
            $target = self::getActiveTemplateSlug();
        }
        return self::resolveStructuredTemplateLayout( $target, 'page' );
    }

    public static function create( array $data ): int|false {
        return false;
    }

    public static function update( int $id, array $data ): bool {
        if ( $id < 1 ) {
            return false;
        }
        if ( ! isset( $data['template_key'] ) && ! isset( $data['slug'] ) ) {
            return false;
        }
        $slug = self::canonicalTemplateKeyOrRaw( (string) ( $data['template_key'] ?? $data['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return false;
        }
        return self::setActiveTemplateSlug( $slug );
    }

    public static function delete( int $id ): bool {
        return false;
    }

    /**
     * @return array<string,mixed>
     */
    public static function resolveStructureForActiveTemplate(): array {
        return self::resolveStructuredTemplateLayout(
            self::getActiveTemplateSlug(),
            'page'
        );
    }

    public static function getActiveTemplateSlug(): string {
        $stored = '';
        if ( class_exists( '\\Core_Settings_Service' ) ) {
            $stored = metis_key_clean( (string) \Core_Settings_Service::get( self::ACTIVE_TEMPLATE_KEY, '' ) );
            $stored = self::canonicalTemplateKeyOrRaw( $stored );
        }

        if ( $stored !== '' && self::templateMeta( $stored ) !== [] ) {
            return $stored;
        }

        $all = self::discoverTemplates();
        if ( $all === [] ) {
            return 'default';
        }

        foreach ( $all as $template ) {
            if ( metis_key_clean( (string) ( $template['slug'] ?? '' ) ) === 'default' ) {
                return 'default';
            }
        }

        return metis_key_clean( (string) ( $all[0]['slug'] ?? 'default' ) );
    }

    public static function setActiveTemplateSlug( string $slug ): bool {
        $candidate = self::canonicalTemplateKeyOrRaw( $slug );
        if ( $candidate === '' ) {
            return false;
        }
        if ( self::templateMeta( $candidate ) === [] ) {
            return false;
        }
        if ( ! class_exists( '\\Core_Settings_Service' ) ) {
            return false;
        }
        return (bool) \Core_Settings_Service::set( self::ACTIVE_TEMPLATE_KEY, $candidate, true );
    }

    /**
     * @return array<string,mixed>
     */
    private static function resolveStructuredTemplateLayout( string $template_key, string $page_type ): array {
        $structured_key = self::normalizeStructuredTemplateKey( $template_key, $page_type );
        $is_post_context = metis_key_clean( $page_type ) === 'post';
        $resolved_page_type = self::normalizePageType( $page_type, $is_post_context );
        $selected_meta = self::templateMeta( $structured_key );
        if ( $selected_meta === [] ) {
            $selected_meta = self::activeTemplateMeta();
        }
        $variant = metis_key_clean( (string) ( $selected_meta['slug'] ?? $structured_key ) );
        $profile = is_array( $selected_meta['profile'] ?? null ) ? $selected_meta['profile'] : [];
        $layout_map = is_array( $selected_meta['layouts'] ?? null ) ? $selected_meta['layouts'] : [];
        $layout_files = [
            'homepage' => (string) ( $selected_meta['layout_paths']['homepage'] ?? '' ),
            'page' => (string) ( $selected_meta['layout_paths']['page'] ?? '' ),
            'post' => (string) ( $selected_meta['layout_paths']['post'] ?? '' ),
        ];

        return [
            'template_key' => $structured_key,
            'template_variant' => $variant,
            'template_type' => 'site',
            'layout' => [
                'main_with_sidebar' => in_array( $variant, self::SIDEBAR_VARIANT_SLUGS, true ),
                'sidebar_position' => 'right',
                'content_max_width' => 'var(--metis-container-content,860px)',
                'suppress_header_on_homepage' => false,
                'template_kind' => 'body',
                'header_variant' => (string) ( $profile['header_variant'] ?? 'split' ),
                'footer_variant' => (string) ( $profile['footer_variant'] ?? 'columns' ),
                'body_variant' => (string) ( $profile['body_variant'] ?? 'contained' ),
            ],
            'editor_meta' => [
                'homepage_mode' => 'site_template_only',
                'template_layout_file' => $layout_files[ $resolved_page_type === 'homepage' ? 'homepage' : $resolved_page_type ],
                'template_layout_files' => $layout_files,
                'template_layout_manifest' => $layout_map,
                'template_preview_file' => (string) ( $selected_meta['preview_path'] ?? '' ),
                'structured_template_key' => $structured_key,
                'page_type' => $resolved_page_type,
            ],
            'regions' => [
                'header' => [ 'enabled' => true, 'source' => 'global_layout', 'layout_id' => 0, 'blocks' => [] ],
                'main' => [ 'enabled' => true, 'source' => 'blocks', 'layout_id' => 0, 'blocks' => [] ],
                'sidebar' => [ 'enabled' => in_array( $variant, self::SIDEBAR_VARIANT_SLUGS, true ), 'source' => 'blocks', 'layout_id' => 0, 'blocks' => [] ],
                'footer' => [ 'enabled' => true, 'source' => 'global_layout', 'layout_id' => 0, 'blocks' => [] ],
                'banners' => [ 'enabled' => true, 'source' => 'none', 'layout_id' => 0, 'blocks' => [] ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function templateMeta( string $slug ): array {
        $needle = self::canonicalTemplateKeyOrRaw( $slug );
        if ( $needle === '' ) {
            return [];
        }

        $all = self::discoverTemplates();
        foreach ( $all as $template ) {
            if ( (string) ( $template['slug'] ?? '' ) === $needle ) {
                return $template;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private static function activeTemplateMeta(): array {
        $active = self::getActiveTemplateSlug();
        $meta = self::templateMeta( $active );
        if ( $meta !== [] ) {
            return $meta;
        }
        $all = self::discoverTemplates();
        if ( $all !== [] && is_array( $all[0] ) ) {
            return $all[0];
        }
        return [];
    }

    public static function renderPreviewSurface( string $slug ): string {
        $meta = self::templateMeta( $slug );
        $previewPath = isset( $meta['preview_path'] ) ? (string) $meta['preview_path'] : '';
        if ( $previewPath === '' || ! is_file( $previewPath ) ) {
            return self::fallbackPreviewSurface( $slug );
        }

        $template_slug = metis_key_clean( (string) ( $meta['slug'] ?? $slug ) );
        ob_start();
        include $previewPath;
        $html = ob_get_clean();
        if ( ! is_string( $html ) || trim( $html ) === '' ) {
            return self::fallbackPreviewSurface( $template_slug );
        }
        return $html;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function discoverTemplates(): array {
        $base_dir = self::templatesBaseDir();
        if ( $base_dir === '' ) {
            return [];
        }

        $template_keys = self::templateKeys();
        $templates_by_slug = [];
        foreach ( $template_keys as $key ) {
            $slug = metis_key_clean( (string) $key );
            if ( $slug === '' ) {
                continue;
            }

            $dir = $base_dir . '/' . $slug;
            $folder_slug = $slug;
            if ( ! is_dir( $dir ) ) {
                continue;
            }

            $manifestPath = $dir . '/manifest.json';
            $previewPath = $dir . '/preview.php';
            if ( ! is_file( $manifestPath ) || ! is_file( $previewPath ) ) {
                continue;
            }

            $raw = file_get_contents( $manifestPath );
            if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
                continue;
            }
            $manifest = json_decode( $raw, true );
            if ( ! is_array( $manifest ) || ! self::validateManifest( $manifest, $folder_slug, $dir ) ) {
                continue;
            }

            $manifest['slug'] = $slug;
            $manifest['preview_path'] = $previewPath;
            $manifest['layout_paths'] = [
                'homepage' => $dir . '/' . (string) ( $manifest['layouts']['homepage'] ?? 'homepage.php' ),
                'page' => $dir . '/' . (string) ( $manifest['layouts']['page'] ?? 'page.php' ),
                'post' => $dir . '/' . (string) ( $manifest['layouts']['post'] ?? 'post.php' ),
            ];
            $templates_by_slug[ $slug ] = $manifest;
        }

        $templates = array_values( $templates_by_slug );
        $order = array_flip( self::templateKeys() );
        usort(
            $templates,
            static function ( array $a, array $b ) use ( $order ): int {
                $a_slug = metis_key_clean( (string) ( $a['slug'] ?? '' ) );
                $b_slug = metis_key_clean( (string) ( $b['slug'] ?? '' ) );
                $a_idx = isset( $order[ $a_slug ] ) ? (int) $order[ $a_slug ] : PHP_INT_MAX;
                $b_idx = isset( $order[ $b_slug ] ) ? (int) $order[ $b_slug ] : PHP_INT_MAX;
                if ( $a_idx === $b_idx ) {
                    return strcmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
                }
                return $a_idx <=> $b_idx;
            }
        );

        return $templates;
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private static function validateManifest( array $manifest, string $folderSlug, string $dir ): bool {
        $requiredKeys = [ 'name', 'slug', 'version', 'description', 'regions', 'supports', 'layouts', 'default_sections' ];
        foreach ( $requiredKeys as $key ) {
            if ( ! array_key_exists( $key, $manifest ) ) {
                return false;
            }
        }

        if ( metis_key_clean( (string) ( $manifest['slug'] ?? '' ) ) !== $folderSlug ) {
            return false;
        }
        if ( ! is_array( $manifest['regions'] ) || ! is_array( $manifest['supports'] ) || ! is_array( $manifest['layouts'] ) || ! is_array( $manifest['default_sections'] ) ) {
            return false;
        }

        foreach ( [ 'homepage', 'page', 'post' ] as $requiredLayout ) {
            if ( ! isset( $manifest['layouts'][ $requiredLayout ] ) ) {
                return false;
            }
            $layout_file = trim( (string) $manifest['layouts'][ $requiredLayout ] );
            if ( $layout_file === '' || preg_match( '/^[A-Za-z0-9._-]+\.php$/', $layout_file ) !== 1 ) {
                return false;
            }
            if ( ! is_file( $dir . '/' . $layout_file ) ) {
                return false;
            }
            if ( ! isset( $manifest['default_sections'][ $requiredLayout ] ) || ! is_array( $manifest['default_sections'][ $requiredLayout ] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $template
     */
    private static function toEntity( array $template, int $id, bool $isDefault ): Template {
        $row = [
            'id' => $id,
            'template_key' => (string) ( $template['slug'] ?? '' ),
            'name' => (string) ( $template['name'] ?? '' ),
            'template_type' => 'site',
            'status' => 'published',
            'structure_json' => function_exists( 'metis_json_encode' )
                ? (string) metis_json_encode( $template, JSON_UNESCAPED_SLASHES )
                : (string) json_encode( $template, JSON_UNESCAPED_SLASHES ),
            'is_default' => $isDefault ? 1 : 0,
        ];

        return Template::fromRow( $row );
    }

    /**
     * Use a single canonical template directory.
     */
    private static function templatesBaseDir(): string {
        $root = METIS_MODULES_PATH . 'website';
        $candidate = $root . '/Templates';
        return is_dir( $candidate ) ? $candidate : '';
    }

    /**
     * @return array<int,string>
     */
    private static function templateKeys(): array {
        return array_values( array_unique( array_merge( [ 'default' ], StructuredWebsiteBuilderService::templateKeys() ) ) );
    }

    private static function normalizeStructuredTemplateKey( string $raw, string $page_type ): string {
        return StructuredWebsiteBuilderService::resolveTemplateKey( $raw, $page_type );
    }

    private static function canonicalTemplateKeyOrRaw( string $raw ): string {
        $normalized_raw = metis_key_clean( $raw );
        if ( $normalized_raw === '' ) {
            return '';
        }
        $candidate = StructuredWebsiteBuilderService::resolveTemplateKey( $normalized_raw, 'page' );
        if ( $candidate === '' || ! StructuredWebsiteBuilderService::isStructuredTemplateKey( $candidate ) ) {
            return '';
        }
        return $candidate;
    }

    private static function fallbackPreviewSurface( string $slug ): string {
        $template_slug = metis_key_clean( $slug );
        $header = '<span class="metis-layout-gallery-thumb-header">'
            . '<span class="metis-layout-thumb-logo"></span>'
            . '<span class="metis-layout-thumb-nav"></span>'
            . '<span class="metis-layout-thumb-actions"></span>'
            . '</span>';

        $main = match ( $template_slug ) {
            'hero_split_glass' => '<span class="metis-layout-gallery-thumb-main metis-layout-thumb-main-hero-split">'
                . '<span class="metis-layout-thumb-hero-block"></span>'
                . '<span class="metis-layout-thumb-split-col"><span class="metis-layout-thumb-content-secondary"></span><span class="metis-layout-thumb-content-secondary"></span></span>'
                . '</span>',
            'centered_stack_marketing' => '<span class="metis-layout-gallery-thumb-main metis-layout-thumb-main-centered-stack">'
                . '<span class="metis-layout-thumb-content-primary"></span><span class="metis-layout-thumb-content-primary"></span><span class="metis-layout-thumb-content-secondary"></span>'
                . '</span>',
            'image_overlay_banner' => '<span class="metis-layout-gallery-thumb-main metis-layout-thumb-main-overlay-banner">'
                . '<span class="metis-layout-thumb-overlay-band"></span><span class="metis-layout-thumb-content-primary"></span><span class="metis-layout-thumb-content-secondary"></span>'
                . '</span>',
            'modular_grid_dash' => '<span class="metis-layout-gallery-thumb-main metis-layout-thumb-main-modular-grid">'
                . '<span class="metis-layout-thumb-grid-cell"></span><span class="metis-layout-thumb-grid-cell"></span><span class="metis-layout-thumb-grid-cell"></span><span class="metis-layout-thumb-grid-cell"></span>'
                . '</span>',
            'editorial_focus' => '<span class="metis-layout-gallery-thumb-main metis-layout-thumb-main-editorial-focus">'
                . '<span class="metis-layout-thumb-sidebar-rail"></span><span class="metis-layout-thumb-content-primary"></span><span class="metis-layout-thumb-content-primary"></span>'
                . '</span>',
            'compact_app_style' => '<span class="metis-layout-gallery-thumb-main metis-layout-thumb-main-compact-app">'
                . '<span class="metis-layout-thumb-app-rail"></span><span class="metis-layout-thumb-content-primary"></span><span class="metis-layout-thumb-content-secondary"></span>'
                . '</span>',
            default => '<span class="metis-layout-gallery-thumb-main metis-layout-thumb-main-fallback metis-layout-thumb-main-modern_split"><span class="metis-layout-thumb-content-primary"></span><span class="metis-layout-thumb-content-secondary"></span></span>',
        };

        $footer = '<span class="metis-layout-gallery-thumb-footer">'
            . '<span class="metis-layout-thumb-footer-col"></span>'
            . '<span class="metis-layout-thumb-footer-col"></span>'
            . '<span class="metis-layout-thumb-footer-col"></span>'
            . '</span>';

        return $header . $main . $footer;
    }

    private static function normalizePageType( string $raw, bool $is_post ): string {
        if ( $is_post ) {
            return 'post';
        }
        $type = metis_key_clean( $raw );
        if ( ! in_array( $type, [ 'homepage', 'page', 'post' ], true ) ) {
            $type = 'page';
        }
        if ( $type === 'post' && ! $is_post ) {
            $type = 'page';
        }
        return $type;
    }

    private static function pageTypeFromEntity( object $entity, bool $is_post = false ): string {
        $page_type = '';
        if ( isset( $entity->page_type ) && is_string( $entity->page_type ) ) {
            $page_type = $entity->page_type;
        }

        if ( $page_type === '' ) {
            $layout_raw = '';
            if ( $is_post ) {
                $layout_raw = (string) ( $entity->draft_content_json ?? $entity->content_json ?? '' );
            } else {
                $layout_raw = (string) ( $entity->draft_layout_json ?? $entity->layout_json ?? '' );
            }
            $layout = json_decode( $layout_raw, true );
            if ( is_array( $layout ) ) {
                $meta = StructuredWebsiteBuilderService::structuredMetaFromDecodedLayout( $layout );
                if ( isset( $meta['page_type'] ) && is_scalar( $meta['page_type'] ) ) {
                    $page_type = (string) $meta['page_type'];
                }
            }
        }

        return self::normalizePageType( $page_type, $is_post );
    }

    private static function templateKeyFromEntity( object $entity, string $page_type, bool $is_post ): string {
        $stored_key = '';
        if ( isset( $entity->template_key ) && is_scalar( $entity->template_key ) ) {
            $stored_key = (string) $entity->template_key;
        }
        if ( self::isValidTemplateKey( $stored_key ) ) {
            return self::normalizeStructuredTemplateKey( $stored_key, $page_type );
        }

        $layout_raw = '';
        if ( $is_post ) {
            $layout_raw = (string) ( $entity->draft_content_json ?? $entity->content_json ?? '' );
        } else {
            $layout_raw = (string) ( $entity->draft_layout_json ?? $entity->layout_json ?? '' );
        }
        $layout = json_decode( $layout_raw, true );
        if ( is_array( $layout ) ) {
            $meta = StructuredWebsiteBuilderService::structuredMetaFromDecodedLayout( $layout );
            $layout_key = isset( $meta['template_key'] ) && is_scalar( $meta['template_key'] ) ? (string) $meta['template_key'] : '';
            if ( self::isValidTemplateKey( $layout_key ) ) {
                return self::normalizeStructuredTemplateKey( $layout_key, $page_type );
            }
        }

        return self::normalizeStructuredTemplateKey( self::getActiveTemplateSlug(), $page_type );
    }
}
