<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;
use Metis\Modules\Website\Entities\Page;
use Metis\Modules\Website\Entities\Post;
use Metis\Modules\Website\Services\LayoutProfileService;
use Metis\Modules\Website\Services\MenuService;
use Metis\Modules\Website\Services\TemplateService;
/**
 * Website Renderer
 *
 * Renders public-facing pages and posts with clean HTML output.
 * Injects global headers/footers and handles SEO meta.
 */
final class WebsiteRenderer {
    /** @var array<int,string> */
    private const STRUCTURED_SECTION_TYPES = [ 'heading', 'text', 'image', 'button', 'columns', 'hero', 'feature_grid', 'card_grid', 'cta', 'events', 'form', 'donation_form', 'donation_progress', 'campaign_summary', 'divider', 'spacer', 'posts_list', 'html', 'transcript' ];

    /**
     * Structured page/post preview that matches the production rendering pipeline.
     *
     * @param array<string,mixed> $options
     * @return array{document_html:string,content_html:string,context:array<string,mixed>}
     */
    public static function renderStructuredEditorPreview( string $layout_json, array $options = [] ): array {
        $context_name = metis_key_clean( (string) ( $options['context'] ?? 'website' ) );
        if ( $context_name === '' ) {
            $context_name = 'website';
        }
        $page_title = trim( (string) ( $options['page_title'] ?? 'Preview' ) );
        if ( $page_title === '' ) {
            $page_title = 'Preview';
        }
        $preview_device = metis_key_clean( (string) ( $options['preview_device'] ?? 'desktop' ) );
        if ( ! in_array( $preview_device, [ 'desktop', 'tablet', 'mobile' ], true ) ) {
            $preview_device = 'desktop';
        }

        $is_post = $context_name === 'post';
        $page_type = metis_key_clean( (string) ( $options['page_type'] ?? ( $is_post ? 'post' : 'page' ) ) );
        if ( $is_post ) {
            $page_type = 'post';
        } elseif ( ! in_array( $page_type, [ 'homepage', 'page' ], true ) ) {
            $page_type = 'page';
        }
        $template_key = metis_key_clean( (string) ( $options['template_key'] ?? '' ) );
        $template_structure = TemplateService::resolveForPreview( $template_key, $page_type );
        $content_type = $is_post ? 'post' : 'page';
        $is_homepage = ! $is_post && $page_type === 'homepage';

        $context = [
            'path' => $is_homepage ? '/' : '/editor/preview',
            'slug' => 'editor-preview',
            'content_type' => $content_type,
            'content_format' => $is_post
                ? ( in_array( metis_key_clean( (string) ( $options['content_format'] ?? 'standard' ) ), [ 'standard', 'transcript' ], true )
                    ? metis_key_clean( (string) ( $options['content_format'] ?? 'standard' ) )
                    : 'standard' )
                : '',
            'editor_context' => $context_name,
            'context' => $context_name,
            'preview_device' => $preview_device,
            'page_title' => $page_title,
            'template_key' => metis_key_clean( (string) ( $template_structure['template_key'] ?? $template_key ) ),
            'is_homepage' => $is_homepage,
            'preview_layout_kind' => $page_type,
        ];

        $sections = StructuredWebsiteBuilderService::sectionsFromLayout( $layout_json, $is_post );
        $hero = StructuredWebsiteBuilderService::heroFromLayout( $layout_json, $is_homepage );
        $content_html = self::renderStructuredSections( $sections, $context );
        $featured_image_id = isset( $options['featured_image_id'] ) ? (int) $options['featured_image_id'] : 0;
        $featured_image_caption = trim( (string) ( $options['featured_image_caption'] ?? '' ) );
        $featured_image_url = $featured_image_id > 0 ? self::mediaUrlById( $featured_image_id ) : '';
        $document_html = self::renderWithPipeline(
            [
                'title' => $page_title,
                'description' => '',
                'template_structure' => $template_structure,
                'sections' => $sections,
                'hero' => $hero,
                'page' => [
                    'id' => 0,
                    'slug' => 'editor-preview',
                    'title' => $page_title,
                    'featured_image_url' => $featured_image_url,
                    'featured_image_caption' => $featured_image_caption,
                    'page_type' => $page_type,
                ],
                'layout_settings' => self::layoutSettingsFromRaw( $layout_json ),
                'context' => $context,
            ]
        );

        return [
            'document_html' => $document_html,
            'content_html' => $content_html,
            'context' => $context,
        ];
    }

    /**
     * Render first-party public module content inside the active website wrapper.
     *
     * @param array<string,mixed> $context
     */
    public static function renderPublicDocument( string $title, string $content_html, array $context = [] ): string {
        $title = trim( $title ) !== '' ? trim( $title ) : 'Page';
        $path = trim( (string) ( $context['path'] ?? ( $_SERVER['REQUEST_URI'] ?? '/' ) ) );
        if ( $path === '' ) {
            $path = '/';
        }

        $render_context = array_merge(
            [
                'path' => $path,
                'slug' => trim( $path, '/' ) !== '' ? trim( $path, '/' ) : 'public',
                'content_type' => 'public_module',
                'content_format' => 'standard',
                'context' => 'website',
                'is_homepage' => false,
            ],
            $context
        );

        return self::renderWithPipeline( [
            'title' => $title,
            'description' => '',
            'template_structure' => TemplateService::resolveForArchive(),
            'sections' => [],
            'hero' => [],
            'content' => $content_html,
            'page' => [
                'id' => 0,
                'slug' => (string) $render_context['slug'],
                'title' => $title,
                'page_type' => 'public_module',
            ],
            'layout_settings' => [],
            'context' => $render_context,
        ] );
    }

    public static function hasHomepageConfigured(): bool {
        $page = self::resolveHomepagePage();
        return $page !== null && $page->status === 'published';
    }

    public static function renderConfiguredHomepage(): ?string {
        if ( self::isTemplatePreviewMode() ) {
            self::emitPreviewNoCacheHeaders();
            return self::renderTemplatePreviewDocument();
        }

        $page = self::resolveHomepagePage();
        if ( $page === null || $page->status !== 'published' ) {
            return null;
        }

        return self::renderPageEntity( $page, [
            'path' => '/',
            'slug' => (string) $page->slug,
            'content_type' => 'page',
        ] );
    }

    public static function renderHomepagePlaceholder(): string {
        $admin_url = function_exists( 'metis_portal_url' )
            ? (string) metis_portal_url( 'website', 'pages' )
            : ( function_exists( 'metis_admin_url' ) ? (string) metis_admin_url( 'website/pages' ) : '/website/pages/' );
        $inline_css = self::errorPageCss();
        $logo_src   = self::errorPageLogoSrc();
        $request    = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );

        $html  = '<!doctype html><html lang="en"><head>';
        $html .= '<meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>Site Setup | Metis</title>';
        $html .= '<style>' . $inline_css . '</style>';
        $html .= '</head><body><main class="page"><section class="card">';

        $html .= '<aside class="brand-panel">';
        $html .= '<p class="brand-kicker">Metis Website Setup</p>';
        $html .= '<div class="logo-wrap">';
        $html .= '<img src="' . metis_escape_attr( $logo_src ) . '" alt="Metis logo">';
        $html .= '<span class="status-chip">Homepage pending</span>';
        $html .= '<p class="system-copy">Your public routes are active. Publish a homepage to replace this setup placeholder.</p>';
        $html .= '</div></aside>';

        $html .= '<div class="content-panel">';
        $html .= '<h1 class="code">SETUP</h1>';
        $html .= '<h2 class="title">No homepage is published yet.</h2>';
        $html .= '<p class="lead">Create and publish a page, then set it as homepage in Website editor or Settings to make it the live root page.</p>';
        $html .= '<div class="meta">';
        $html .= '<div class="meta-item"><span class="meta-label">Requested route</span><div class="meta-value">' . metis_escape_html( $request ) . '</div></div>';
        $html .= '<div class="meta-item"><span class="meta-label">Next step</span><div class="meta-value">Open Website Pages, create or publish your homepage, then refresh this route.</div></div>';
        $html .= '</div>';
        $html .= '<div class="actions"><a class="btn btn-secondary" href="' . metis_escape_attr( $admin_url ) . '">Open Website Pages</a></div>';
        $html .= '<p class="footer-note">This placeholder is shown only when no homepage record is configured.</p>';
        $html .= '</div></section></main></body></html>';

        return $html;
    }

    private static function errorPageCss(): string {
        $path = ( defined( 'METIS_ASSETS_PATH' ) ? METIS_ASSETS_PATH : dirname( __DIR__, 5 ) . '/assets/' ) . 'error-pages/metis-errors.css';
        $css = is_readable( $path ) ? (string) file_get_contents( $path ) : '';

        if ( $css !== '' ) {
            return $css;
        }

        return 'body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#161b2e;background:#f6f7fb}.page{min-height:100vh;display:grid;place-items:center;padding:28px}.card{width:min(980px,100%);display:grid;grid-template-columns:1fr 1fr;gap:28px;background:#fff;border:1px solid #d9deee;border-radius:22px;box-shadow:0 20px 60px rgba(0,0,0,.16);overflow:hidden}.brand-panel,.content-panel{padding:36px}.brand-panel{border-right:1px solid #d9deee}.code{margin:0;font-size:80px;line-height:.9}.title{margin:10px 0;font-size:36px;line-height:1.08}.lead,.system-copy,.footer-note{color:#5f687d;line-height:1.6}.meta{display:grid;gap:12px;margin:24px 0}.meta-item{padding:14px 16px;border-radius:16px;background:#f7f8fc;border:1px solid #d9deee}.meta-label{display:block;margin-bottom:6px;color:#5f687d;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 18px;border-radius:14px;text-decoration:none;font-weight:700}.btn-secondary{color:#161b2e;background:#f7f8fc;border:1px solid #d9deee}@media(max-width:860px){.card{grid-template-columns:1fr}.brand-panel{border-right:0;border-bottom:1px solid #d9deee}}';
    }

    private static function errorPageLogoSrc(): string {
        $path = ( defined( 'METIS_ASSETS_PATH' ) ? METIS_ASSETS_PATH : dirname( __DIR__, 5 ) . '/assets/' ) . 'error-pages/metis-shield-logo.png';
        if ( is_readable( $path ) ) {
            $data = file_get_contents( $path );
            if ( is_string( $data ) && $data !== '' ) {
                return 'data:image/png;base64,' . base64_encode( $data );
            }
        }

        return rtrim( metis_site_url( 'assets/error-pages/' ), '/' ) . '/metis-shield-logo.png';
    }

    public static function renderPage( string $slug ): ?string {
        $slug = metis_slug_clean( $slug );
        if ( $slug === '' ) {
            return null;
        }

        return self::renderPagePath( '/' . ltrim( $slug, '/' ) );
    }

    public static function renderPagePath( string $path ): ?string {
        $normalized_path = self::normalizedPath( $path );
        if ( $normalized_path === '/' ) {
            return null;
        }

        $page = method_exists( PageService::class, 'getPublishedByPath' )
            ? PageService::getPublishedByPath( $normalized_path )
            : null;

        if ( $page === null ) {
            $segments = array_values( array_filter( explode( '/', trim( $normalized_path, '/' ) ) ) );
            $leaf_slug = $segments !== [] ? metis_slug_clean( (string) end( $segments ) ) : '';
            if ( $leaf_slug === '' ) {
                return null;
            }
            $candidate = PageService::getBySlug( $leaf_slug );
            if ( $candidate === null || $candidate->status !== 'published' ) {
                return null;
            }
            $candidate_path = method_exists( PageService::class, 'publishedPathForPage' )
                ? (string) PageService::publishedPathForPage( $candidate )
                : ( '/' . ltrim( (string) $candidate->slug, '/' ) );
            if ( $candidate_path !== '' && trim( $candidate_path, '/' ) !== trim( $normalized_path, '/' ) ) {
                return null;
            }
            $page = $candidate;
        }

        return self::renderPageEntity( $page, [
            'path' => $normalized_path,
            'slug' => (string) $page->slug,
            'content_type' => 'page',
        ] );
    }

    public static function renderPageEntity( Page $page, array $context = [] ): string {
        $seo_meta = $page->getSeoMeta();
        $template_structure = TemplateService::resolveForPage( $page );
        $layout_raw = $page->status === 'published' && $page->published_layout_json !== null
            ? $page->published_layout_json
            : ( $page->draft_layout_json ?? $page->layout_json );
        $layout_settings = self::layoutSettingsFromRaw( $layout_raw );
        $sections = StructuredWebsiteBuilderService::sectionsFromLayout( (string) $layout_raw, false );
        $requested_path = trim( (string) ( $context['path'] ?? '' ) );
        $entity_page_type = metis_key_clean( (string) ( $page->page_type ?? '' ) );
        $is_homepage = in_array( $requested_path, [ '', '/' ], true ) || $entity_page_type === 'homepage';
        $hero = StructuredWebsiteBuilderService::heroFromLayout( (string) $layout_raw, $is_homepage );
        $context = array_merge(
            [
                'path' => '/' . ltrim( (string) $page->slug, '/' ),
                'slug' => (string) $page->slug,
                'content_type' => 'page',
                'content_id' => (int) ( $page->id ?? 0 ),
                'content_code' => (string) ( $page->page_code ?? '' ),
                'template_key' => metis_key_clean( (string) ( $template_structure['template_key'] ?? '' ) ),
                'is_homepage' => $is_homepage,
            ],
            $context
        );
        if ( ! empty( $context['is_homepage'] ) ) {
            $context['is_homepage'] = true;
        }

        return self::renderWithPipeline( [
            'title'       => $seo_meta['title'] ?? $page->title,
            'description' => $seo_meta['description'] ?? '',
            'seo_meta' => $seo_meta,
            'template_structure' => $template_structure,
            'sections'    => $sections,
            'hero' => $hero,
            'page'        => [
                'id' => (int) ( $page->id ?? 0 ),
                'slug' => (string) $page->slug,
                'title' => (string) ( $page->title ?? '' ),
                'page_type' => (string) ( $page->page_type ?? '' ),
            ],
            'layout_settings' => $layout_settings,
            'context'     => $context,
            'layout_data' => [
                'page_data' => [
                    'id' => (int) ( $page->id ?? 0 ),
                    'slug' => (string) $page->slug,
                    'title' => (string) ( $page->title ?? '' ),
                    'page_type' => (string) ( $page->page_type ?? '' ),
                ],
                'layout_settings' => $layout_settings,
            ],
        ] );
    }

    public static function renderPost( string $slug ): ?string {
        $slug = metis_slug_clean( $slug );
        if ( $slug === '' ) {
            return null;
        }

        $post = method_exists( PostService::class, 'getPublishedBySlug' )
            ? PostService::getPublishedBySlug( $slug, null )
            : PostService::getBySlug( $slug );
        if ( $post === null || $post->status !== 'published' ) {
            return null;
        }

        return self::renderPostEntity( $post );
    }

    public static function renderPostByCategoryYearPath( string $path ): ?string {
        $normalized_path = self::normalizedPath( $path );
        $segments = array_values( array_filter( explode( '/', trim( $normalized_path, '/' ) ), static fn ( string $segment ): bool => $segment !== '' ) );
        if ( count( $segments ) !== 3 && count( $segments ) !== 4 ) {
            return null;
        }

        $category_slug = metis_slug_clean( (string) ( $segments[0] ?? '' ) );
        $child_category_slug = count( $segments ) === 4 ? metis_slug_clean( (string) ( $segments[1] ?? '' ) ) : '';
        $route_year = trim( (string) ( $segments[ count( $segments ) === 4 ? 2 : 1 ] ?? '' ) );
        $post_slug = metis_slug_clean( (string) ( $segments[ count( $segments ) === 4 ? 3 : 2 ] ?? '' ) );
        if ( $category_slug === '' || preg_match( '/^\d{4}$/', $route_year ) !== 1 || $post_slug === '' ) {
            return null;
        }
        if ( count( $segments ) === 4 && $child_category_slug === '' ) {
            return null;
        }

        $post = method_exists( PostService::class, 'getPublishedBySlug' )
            ? PostService::getPublishedBySlug( $post_slug, null )
            : null;
        if ( $post === null || $post->status !== 'published' ) {
            return null;
        }

        $expected_paths = method_exists( PostService::class, 'publicPaths' )
            ? array_map( [ self::class, 'normalizedPath' ], (array) PostService::publicPaths( $post ) )
            : [];
        if ( $expected_paths === [] && method_exists( PostService::class, 'publicPath' ) ) {
            $single_path = (string) PostService::publicPath( $post );
            if ( $single_path !== '' ) {
                $expected_paths[] = self::normalizedPath( $single_path );
            }
        }
        if ( $expected_paths === [] || ! in_array( $normalized_path, $expected_paths, true ) ) {
            return null;
        }

        return self::renderPostEntity( $post, [ 'path' => $normalized_path ] );
    }

    public static function renderPostByParentPath( string $path ): ?string {
        return self::renderPostByCategoryYearPath( $path );
    }

    public static function renderPostEntity( Post $post, array $context = [] ): string {
        $seo_meta = $post->getSeoMeta();
        $template_structure = TemplateService::resolveForPost( $post );
        $layout_raw = $post->status === 'published' && $post->published_content_json !== null
            ? $post->published_content_json
            : ( $post->draft_content_json ?? $post->content_json );
        $layout_settings = self::layoutSettingsFromRaw( $layout_raw );
        $sections = StructuredWebsiteBuilderService::sectionsFromLayout( (string) $layout_raw, true );
        $hero = StructuredWebsiteBuilderService::heroFromLayout( (string) $layout_raw, false );
        $post_path = self::postPublicPath( $post );
        $render_path = isset( $context['path'] ) ? self::normalizedPath( (string) $context['path'] ) : $post_path;

        $context = array_merge(
            [
                'path' => $render_path,
                'slug' => (string) $post->slug,
                'content_type' => 'post',
                'content_id' => (int) ( $post->id ?? 0 ),
                'content_code' => (string) ( $post->post_code ?? '' ),
                'content_format' => in_array( (string) ( $post->content_format ?? 'standard' ), [ 'standard', 'transcript' ], true )
                    ? (string) $post->content_format
                    : 'standard',
                'template_key' => metis_key_clean( (string) ( $template_structure['template_key'] ?? '' ) ),
            ],
            $context
        );
        $author_source_id = (int) ( $post->author_id ?? 0 );
        if ( $author_source_id < 1 ) {
            $author_source_id = (int) ( $post->updated_by ?? $post->created_by ?? 0 );
        }
        return self::renderWithPipeline( [
            'title'       => $seo_meta['title'] ?? $post->title,
            'description' => $seo_meta['description'] ?? $post->excerpt ?? '',
            'seo_meta' => $seo_meta,
            'template_structure' => $template_structure,
            'sections'    => $sections,
            'hero' => $hero,
            'page'        => [
                'id' => (int) ( $post->id ?? 0 ),
                'slug' => (string) $post->slug,
                'title' => (string) ( $post->title ?? '' ),
                'excerpt' => (string) ( $post->excerpt ?? '' ),
                'publish_date' => (string) ( $post->publish_date ?? '' ),
                'author_name' => self::authorNameById( $author_source_id ),
                'featured_image_url' => self::mediaUrlById( (int) ( $post->featured_image_id ?? 0 ) ),
                'featured_image_caption' => (string) ( $post->featured_image_caption ?? '' ),
                'page_type' => 'post',
            ],
            'layout_settings' => $layout_settings,
            'context'     => $context,
            'layout_data' => [
                'page_data' => [
                    'id' => (int) ( $post->id ?? 0 ),
                    'slug' => (string) $post->slug,
                    'title' => (string) ( $post->title ?? '' ),
                    'excerpt' => (string) ( $post->excerpt ?? '' ),
                    'publish_date' => (string) ( $post->publish_date ?? '' ),
                    'author_name' => self::authorNameById( $author_source_id ),
                    'featured_image_url' => self::mediaUrlById( (int) ( $post->featured_image_id ?? 0 ) ),
                    'featured_image_caption' => (string) ( $post->featured_image_caption ?? '' ),
                    'page_type' => 'post',
                ],
                'layout_settings' => $layout_settings,
            ],
        ] );
    }

    public static function render404(): string {
        $content  = '<div class="metis-404">';
        $content .= '<h1>404 – Page Not Found</h1>';
        $content .= '<p>The page you are looking for does not exist.</p>';
        $content .= '</div>';
        $template_structure = TemplateService::resolveForArchive();

        return self::renderWithPipeline( [
            'title'       => '404 – Page Not Found',
            'description' => '',
            'content'     => $content,
            'template_structure' => $template_structure,
            'context' => [
                'path' => (string) ( $_SERVER['REQUEST_URI'] ?? '/404' ),
                'slug' => '404',
                'content_type' => 'error',
                'template_key' => metis_key_clean( (string) ( $template_structure['template_key'] ?? '' ) ),
            ],
        ] );
    }

    private static function resolveHomepagePage(): ?Page {
        $configured = HomepageService::getHomepagePage();
        if ( $configured !== null && $configured->status === 'published' ) {
            return $configured;
        }

        return PageService::getBySlug( 'home' ) ?? PageService::getBySlug( 'homepage' );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $context
     * @param array<string,mixed> $page_data
     * @return array<string,string>
     */
    private static function tokenValuesForRender( array $input, array $context, array $page_data ): array {
        $site_name = class_exists( 'Core_Settings_Service' ) ? trim( (string) \Core_Settings_Service::get( 'org_name', '' ) ) : '';
        if ( $site_name === '' && function_exists( 'metis_portal_name' ) ) {
            $site_name = trim( (string) metis_portal_name() );
        }
        if ( $site_name === '' ) {
            $site_name = 'Website';
        }

        $site_url = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/' ) : '/';
        if ( trim( $site_url ) === '' ) {
            $site_url = '/';
        }

        $page_title = trim( (string) ( $page_data['title'] ?? $input['title'] ?? '' ) );
        $page_slug = trim( (string) ( $page_data['slug'] ?? $context['slug'] ?? '' ) );

        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        $post_title = '';
        $post_date = '';
        $post_excerpt = '';
        if ( $content_type === 'post' ) {
            $post_title = trim( (string) ( $page_data['title'] ?? $input['title'] ?? '' ) );
            $post_date = trim( (string) ( $page_data['publish_date'] ?? '' ) );
        }

        return [
            'site.name' => $site_name,
            'site.url' => $site_url,
            'page.title' => $page_title,
            'page.slug' => $page_slug,
            'post.title' => $post_title,
            'post.date' => $post_date,
            'post.excerpt' => $post_excerpt,
        ];
    }

    /**
     * @param array<string,string> $tokens
     */
    private static function resolveTokenString( string $value, array $tokens ): string {
        if ( $value === '' || strpos( $value, '{{' ) === false ) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_.-]+)\s*\}\}/i',
            static function ( array $matches ) use ( $tokens ): string {
                $token = strtolower( trim( (string) ( $matches[1] ?? '' ) ) );
                if ( $token === '' ) {
                    return '';
                }
                return isset( $tokens[ $token ] ) ? (string) $tokens[ $token ] : '';
            },
            $value
        );
    }

    /**
     * @param mixed $value
     * @param array<string,string> $tokens
     * @return mixed
     */
    private static function resolveTokensInValue( $value, array $tokens ) {
        if ( is_string( $value ) ) {
            return self::resolveTokenString( $value, $tokens );
        }
        if ( ! is_array( $value ) ) {
            return $value;
        }

        foreach ( $value as $key => $item ) {
            $value[ $key ] = self::resolveTokensInValue( $item, $tokens );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $source
     * @param array<int,string> $keys
     */
    private static function seoStringFromKeys( array $source, array $keys ): string {
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $source ) ) {
                continue;
            }
            $value = trim( (string) $source[ $key ] );
            if ( $value !== '' ) {
                return $value;
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $source
     * @param array<int,string> $keys
     */
    private static function seoBoolFromKeys( array $source, array $keys ): bool {
        foreach ( $keys as $key ) {
            if ( ! array_key_exists( $key, $source ) ) {
                continue;
            }
            $raw = $source[ $key ];
            if ( is_bool( $raw ) ) {
                return $raw;
            }
            if ( is_numeric( $raw ) ) {
                return (int) $raw !== 0;
            }
            $normalized = strtolower( trim( (string) $raw ) );
            if ( in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true ) ) {
                return true;
            }
            if ( in_array( $normalized, [ '0', 'false', 'no', 'off' ], true ) ) {
                return false;
            }
        }
        return false;
    }

    private static function seoTextFromHtml( string $value ): string {
        $text = trim( strip_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        if ( $text === '' ) {
            return '';
        }
        return trim( preg_replace( '/\s+/', ' ', $text ) ?? $text );
    }

    private static function seoTrim( string $value, int $limit ): string {
        $value = trim( $value );
        if ( $value === '' || $limit < 8 ) {
            return $value;
        }
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $value ) <= $limit ) {
                return $value;
            }
            return rtrim( mb_substr( $value, 0, $limit - 1 ) ) . '…';
        }
        if ( strlen( $value ) <= $limit ) {
            return $value;
        }
        return rtrim( substr( $value, 0, $limit - 1 ) ) . '…';
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @param array<string,mixed> $hero
     */
    private static function seoDescriptionFromContent( array $sections, array $hero ): string {
        $hero_subtext = self::seoTextFromHtml( (string) ( $hero['subtext'] ?? '' ) );
        if ( $hero_subtext !== '' ) {
            return self::seoTrim( $hero_subtext, 160 );
        }

        foreach ( $sections as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }
            $header = self::seoTextFromHtml( (string) ( $section['header'] ?? '' ) );
            $subtext = self::seoTextFromHtml( (string) ( $section['subtext'] ?? '' ) );
            if ( $subtext !== '' ) {
                return self::seoTrim( $subtext, 160 );
            }
            $content = is_array( $section['content'] ?? null ) ? $section['content'] : [];
            $type = metis_key_clean( (string) ( $section['type'] ?? '' ) );
            if ( $type === 'text' ) {
                $body_text = self::seoTextFromHtml( (string) ( $content['body'] ?? '' ) );
                if ( $body_text !== '' ) {
                    return self::seoTrim( $body_text, 160 );
                }
            } elseif ( $type === 'transcript' ) {
                $rows = is_array( $content['rows'] ?? null ) ? $content['rows'] : [];
                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }
                    $text = self::seoTextFromHtml( (string) ( $row['text'] ?? '' ) );
                    if ( $text !== '' ) {
                        return self::seoTrim( $text, 160 );
                    }
                }
            } elseif ( $type === 'columns' ) {
                $columns = is_array( $content['columns'] ?? null ) ? $content['columns'] : [];
                foreach ( $columns as $column ) {
                    if ( ! is_array( $column ) ) {
                        continue;
                    }
                    $body_text = self::seoTextFromHtml( (string) ( $column['body'] ?? '' ) );
                    if ( $body_text !== '' ) {
                        return self::seoTrim( $body_text, 160 );
                    }
                }
            } elseif ( $type === 'feature_grid' ) {
                $items = is_array( $content['items'] ?? null ) ? $content['items'] : [];
                foreach ( $items as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $item_text = self::seoTextFromHtml( (string) ( $item['text'] ?? '' ) );
                    if ( $item_text === '' ) {
                        $item_text = self::seoTextFromHtml( (string) ( $item['title'] ?? '' ) );
                    }
                    if ( $item_text !== '' ) {
                        return self::seoTrim( $item_text, 160 );
                    }
                }
            } elseif ( $type === 'cta' ) {
                $items = is_array( $content['items'] ?? null ) ? $content['items'] : [];
                foreach ( $items as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $item_text = self::seoTextFromHtml( (string) ( $item['text'] ?? '' ) );
                    if ( $item_text === '' ) {
                        $item_text = self::seoTextFromHtml( (string) ( $item['title'] ?? '' ) );
                    }
                    if ( $item_text !== '' ) {
                        return self::seoTrim( $item_text, 160 );
                    }
                }
            }
            if ( $header !== '' ) {
                return self::seoTrim( $header, 160 );
            }
        }

        return '';
    }

    private static function mediaUrlById( int $media_id ): string {
        if ( $media_id < 1 ) {
            return '';
        }
        $db = Application::service( 'db' );
        if ( ! is_object( $db ) || ! method_exists( $db, 'fetchOne' ) ) {
            return '';
        }

        $queries = [
            'SELECT public_token FROM metis_media_files WHERE id = %d LIMIT 1',
            'SELECT public_token FROM media_files WHERE id = %d LIMIT 1',
        ];

        foreach ( $queries as $sql ) {
            try {
                $row = $db->fetchOne( $sql, [ $media_id ] );
            } catch ( \Throwable $e ) {
                $row = null;
            }

            if ( ! is_array( $row ) ) {
                continue;
            }

            $token = trim( (string) ( $row['public_token'] ?? '' ) );
            if ( $token !== '' ) {
                return self::buildMediaPublicUrl( $token );
            }
        }

        return '';
    }

    private static function buildMediaPublicUrl( string $token ): string {
        $token = trim( $token );
        if ( $token === '' ) {
            return '';
        }

        return self::normalizePublicUrl( '/media/' . rawurlencode( $token ) );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $context
     * @param array<string,mixed> $page_data
     * @param array<int,array<string,mixed>> $sections
     * @param array<string,mixed> $hero
     * @return array<string,mixed>
     */
    private static function seoPayloadForRender(
        array $input,
        array $context,
        array $page_data,
        array $sections,
        array $hero,
        string $default_title,
        string $default_description,
        array $token_values = []
    ): array {
        $raw_meta = is_array( $input['seo_meta'] ?? null ) ? $input['seo_meta'] : [];
        $advanced = is_array( $raw_meta['advanced'] ?? null ) ? $raw_meta['advanced'] : [];

        $custom_title = self::seoStringFromKeys( $advanced, [ 'meta_title', 'title' ] );
        if ( $custom_title === '' ) {
            $custom_title = self::seoStringFromKeys( $raw_meta, [ 'meta_title', 'title' ] );
        }
        if ( $custom_title !== '' ) {
            $custom_title = self::resolveTokenString( $custom_title, $token_values );
        }

        $custom_description = self::seoStringFromKeys( $advanced, [ 'meta_description', 'description' ] );
        if ( $custom_description === '' ) {
            $custom_description = self::seoStringFromKeys( $raw_meta, [ 'meta_description', 'description' ] );
        }
        if ( $custom_description !== '' ) {
            $custom_description = self::resolveTokenString( $custom_description, $token_values );
        }

        $custom_canonical = self::seoStringFromKeys( $advanced, [ 'canonical', 'canonical_url' ] );
        if ( $custom_canonical === '' ) {
            $custom_canonical = self::seoStringFromKeys( $raw_meta, [ 'canonical', 'canonical_url' ] );
        }
        if ( $custom_canonical !== '' ) {
            $custom_canonical = self::resolveTokenString( $custom_canonical, $token_values );
        }

        $custom_og_title = self::seoStringFromKeys( $advanced, [ 'og_title' ] );
        if ( $custom_og_title === '' ) {
            $custom_og_title = self::seoStringFromKeys( $raw_meta, [ 'og_title' ] );
        }
        if ( $custom_og_title !== '' ) {
            $custom_og_title = self::resolveTokenString( $custom_og_title, $token_values );
        }
        $custom_og_description = self::seoStringFromKeys( $advanced, [ 'og_description' ] );
        if ( $custom_og_description === '' ) {
            $custom_og_description = self::seoStringFromKeys( $raw_meta, [ 'og_description' ] );
        }
        if ( $custom_og_description !== '' ) {
            $custom_og_description = self::resolveTokenString( $custom_og_description, $token_values );
        }
        $custom_og_image = self::seoStringFromKeys( $advanced, [ 'og_image', 'image' ] );
        if ( $custom_og_image === '' ) {
            $custom_og_image = self::seoStringFromKeys( $raw_meta, [ 'og_image', 'image' ] );
        }
        if ( $custom_og_image !== '' ) {
            $custom_og_image = self::resolveTokenString( $custom_og_image, $token_values );
        }

        $noindex = self::seoBoolFromKeys( $advanced, [ 'noindex' ] );
        if ( ! $noindex ) {
            $noindex = self::seoBoolFromKeys( $raw_meta, [ 'noindex' ] );
        }
        if ( ! empty( $context['template_preview_mode'] ) ) {
            $noindex = true;
        }

        $title = trim( $custom_title !== '' ? $custom_title : $default_title );
        if ( $title === '' ) {
            $title = trim( (string) ( $page_data['title'] ?? '' ) );
        }
        if ( $title === '' ) {
            $title = 'Website';
        }
        $title = self::seoTrim( $title, 70 );

        $description = trim( $custom_description !== '' ? $custom_description : $default_description );
        if ( $description === '' ) {
            $description = self::seoDescriptionFromContent( $sections, $hero );
        }
        $description = self::seoTrim( self::seoTextFromHtml( $description ), 160 );

        $canonical = $custom_canonical !== ''
            ? self::normalizePublicUrl( $custom_canonical )
            : self::normalizePublicUrl( self::normalizedPath( (string) ( $context['path'] ?? '/' ) ) );

        $og_title = self::seoTrim( trim( $custom_og_title !== '' ? $custom_og_title : $title ), 95 );
        $og_description = self::seoTrim(
            self::seoTextFromHtml( trim( $custom_og_description !== '' ? $custom_og_description : $description ) ),
            200
        );
        $og_image = trim( $custom_og_image );
        if ( $og_image === '' ) {
            $og_image = trim( (string) ( $page_data['featured_image_url'] ?? '' ) );
        }
        if ( $og_image === '' ) {
            $og_image = trim( (string) ( $hero['media_url'] ?? '' ) );
        }
        if ( $og_image !== '' ) {
            $og_image = self::normalizePublicUrl( $og_image );
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'og_title' => $og_title,
            'og_description' => $og_description,
            'og_url' => $canonical,
            'og_image' => $og_image,
            'noindex' => $noindex,
        ];
    }

    /**
     * Single rendering pipeline for public output.
     *
     * 1. Load Page Data
     * 2. Load Sections
     * 3. Load Menu
     * 4. Load Theme
     * 5. Resolve Template
     * 6. Render Header
     * 7. Render Menu
     * 8. Render Sections
     * 9. Render Footer
     * 10. Output HTML
     */
    private static function renderWithPipeline( array $input ): string {
        // 1) Load page data
        $page_data = is_array( $input['page'] ?? null ) ? $input['page'] : [];

        // 2) Load sections
        $sections = is_array( $input['sections'] ?? null ) ? $input['sections'] : [];
        $hero = is_array( $input['hero'] ?? null ) ? $input['hero'] : [];

        // 3) Load menu structure
        $menu_dataset = self::loadMenuDataset();

        // 4) Load theme
        $title = (string) ( $input['title'] ?? 'Page' );
        $description = (string) ( $input['description'] ?? '' );
        $layout_settings = is_array( $input['layout_settings'] ?? null ) ? $input['layout_settings'] : [];
        $context = is_array( $input['context'] ?? null ) ? $input['context'] : [];
        $template_preview_mode = ! empty( $context['template_preview_mode'] );
        $theme = ThemeService::getActiveNormalized();
        $site_layout_profile_key = LayoutProfileService::sanitizeWBProfile(
            (string) ( $theme['global_settings']['site_layout_profile'] ?? '' )
        );
        $menu_style = self::sanitizeMenuStyle( (string) ( $theme['global_settings']['menu_style'] ?? 'h_glide' ) );
        $preview_profile_key = self::previewLayoutProfileOverride();
        if ( $preview_profile_key !== '' ) {
            $site_layout_profile_key = $preview_profile_key;
        }
        $site_layout_profile = LayoutProfileService::resolveWBProfile( $site_layout_profile_key );
        $token_values = self::tokenValuesForRender( $input, $context, $page_data );
        $title = self::resolveTokenString( $title, $token_values );
        $description = self::resolveTokenString( $description, $token_values );
        $resolved_sections = self::resolveTokensInValue( $sections, $token_values );
        if ( is_array( $resolved_sections ) ) {
            $sections = $resolved_sections;
        }
        $resolved_hero = self::resolveTokensInValue( $hero, $token_values );
        if ( is_array( $resolved_hero ) ) {
            $hero = $resolved_hero;
        }
        $seo = self::seoPayloadForRender( $input, $context, $page_data, $sections, $hero, $title, $description, $token_values );
        $title = (string) ( $seo['title'] ?? $title );
        $description = (string) ( $seo['description'] ?? $description );

        // 5) Resolve template
        $template_structure = is_array( $input['template_structure'] ?? null )
            ? $input['template_structure']
            : TemplateService::resolveForArchive();
        // 8) Render sections
        $content_html = $sections !== []
            ? self::renderStructuredSections( $sections, $context )
            : self::resolveTokenString( (string) ( $input['content'] ?? '' ), $token_values );

        // Attach Web Parts
        $show_banners = self::templateRegionEnabled( $template_structure, 'banners' );
        $web_parts = self::attachWebParts( $context, $template_structure, $show_banners );
        if ( $template_preview_mode ) {
            $web_parts = [
                'banners' => '',
                'popups' => '',
                'slots' => [],
            ];
        }

        // Load CSS/JS
        // Keep preview and live rendering on the same CSS pipeline.
        $style_href = self::stylesheetHref( $context, $template_structure, $template_preview_mode );
        $font_stylesheets = ThemeService::fontStylesheetHrefs( $theme );
        $menu_config = is_array( $theme['components']['menu_config'] ?? null ) ? $theme['components']['menu_config'] : [];

        return self::buildPageHtml( [
            'title' => $title,
            'description' => $description,
            'seo' => $seo,
            'content' => $content_html,
            'template_structure' => $template_structure,
            'banners' => $web_parts['banners'],
            'popups' => $web_parts['popups'],
            'web_part_slots' => $web_parts['slots'],
            'style_href' => $style_href,
            'font_stylesheets' => $font_stylesheets,
            'context' => $context,
            'site_layout_profile' => $site_layout_profile,
            'site_layout_profile_key' => $site_layout_profile_key,
            'menu_style' => $menu_style,
            'menu_config' => $menu_config,
            'menu_dataset' => $menu_dataset,
            'page' => $page_data,
            'layout_data' => is_array( $input['layout_data'] ?? null ) ? $input['layout_data'] : [],
            'template_preview_mode' => $template_preview_mode,
        ] );
    }

    private static function attachWebParts( array $context, array $template_structure, bool $show_banners ): array {
        if ( $context === [] ) {
            return [
                'banners' => '',
                'popups' => '',
                'slots' => [],
            ];
        }

        $configured_parts = WebPartService::getRenderableForContext( $context );
        return [
            'banners' => $show_banners ? self::renderBanners( $context ) : '',
            'popups' => self::renderPopups( $context ),
            'slots' => self::placeWebParts( $configured_parts, $template_structure ),
        ];
    }

    private static function loadPipelineStyles( array $theme, array $layout_settings, array $context = [] ): array {
        $font_css = ThemeService::renderFontCss( $theme );
        $theme_css = ThemeService::renderGlobalCss( $theme );
        $custom_css = self::sanitizeCustomCss( (string) ( $theme['custom_css'] ?? '' ) );
        if ( $custom_css !== '' ) {
            $theme_css .= "\n" . $custom_css;
        }
        $menu_variants = self::menuStyleVariantsFromContext( $context );
        $menu_variant_css = self::menuVariantCss( $menu_variants );

        return array_values(
            array_filter(
                [
                    trim( $font_css ),
                    self::publicBaseCss(),
                    self::menuBaseCss(),
                    $menu_variant_css,
                    self::templateBaseCss(),
                    trim( $theme_css ),
                    self::layoutCss( $layout_settings ),
                ],
                static fn ( mixed $value ): bool => is_string( $value ) && trim( $value ) !== ''
            )
        );
    }

    private static function buildPageHtml( array $data ): string {
        $title       = (string) ( $data['title'] ?? 'Page' );
        $description = $data['description'] ?? '';
        $seo_data = is_array( $data['seo'] ?? null ) ? $data['seo'] : [];
        $canonical = trim( (string) ( $seo_data['canonical'] ?? '' ) );
        $og_title = trim( (string) ( $seo_data['og_title'] ?? '' ) );
        $og_description = trim( (string) ( $seo_data['og_description'] ?? '' ) );
        $og_url = trim( (string) ( $seo_data['og_url'] ?? '' ) );
        $og_image = trim( (string) ( $seo_data['og_image'] ?? '' ) );
        $noindex = ! empty( $seo_data['noindex'] );
        $content     = $data['content'] ?? '';
        $banners     = $data['banners'] ?? '';
        $popups      = $data['popups'] ?? '';
        $web_part_slots = is_array( $data['web_part_slots'] ?? null ) ? $data['web_part_slots'] : [];
        $template_structure = is_array( $data['template_structure'] ?? null ) ? $data['template_structure'] : TemplateService::resolveForArchive();
        $style_href = trim( (string) ( $data['style_href'] ?? '' ) );
        $font_stylesheets = is_array( $data['font_stylesheets'] ?? null ) ? $data['font_stylesheets'] : [];
        $context = is_array( $data['context'] ?? null ) ? $data['context'] : [];
        $layout_data = is_array( $data['layout_data'] ?? null ) ? $data['layout_data'] : [];
        $hero = is_array( $data['hero'] ?? null ) ? $data['hero'] : [];
        $layout_page_data = is_array( $layout_data['page_data'] ?? null ) ? $layout_data['page_data'] : [];
        $layout_settings = is_array( $layout_data['layout_settings'] ?? null ) ? $layout_data['layout_settings'] : [];
        $page_data = is_array( $data['page'] ?? null ) ? $data['page'] : [];
        $resolved_page_data = array_merge( $page_data, $layout_page_data );
        $content = self::prependPostFeaturedImage( (string) $content, $context, $resolved_page_data );
        $site_layout_profile = is_array( $data['site_layout_profile'] ?? null ) ? $data['site_layout_profile'] : [];
        $site_layout_profile_key = metis_key_clean( (string) ( $data['site_layout_profile_key'] ?? ( $site_layout_profile['key'] ?? LayoutProfileService::defaultWBProfileKey() ) ) );
        $template_preview_mode = ! empty( $data['template_preview_mode'] );
        $menu_dataset = is_array( $data['menu_dataset'] ?? null ) ? $data['menu_dataset'] : [];
        if ( $site_layout_profile_key === '' ) {
            $site_layout_profile_key = LayoutProfileService::defaultWBProfileKey();
        }
        $menu_style = self::sanitizeMenuStyle( (string) ( $data['menu_style'] ?? 'h_glide' ) );
        $menu_config = is_array( $data['menu_config'] ?? null ) ? $data['menu_config'] : [];
        $body_classes = self::bodyClassList( $context, $site_layout_profile_key, $menu_style, $menu_config );
        $body_attrs = self::menuBodyDataAttributes( $menu_config );

        $head = [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '  <meta charset="UTF-8">',
            '  <meta name="viewport" content="width=device-width, initial-scale=1.0">',
            '  <title>' . metis_escape_html( self::documentTitle( $title, $context ) ) . '</title>',
        ];

        if ( $description !== '' ) {
            $head[] = '  <meta name="description" content="' . metis_escape_attr( $description ) . '">';
        }
        if ( $canonical !== '' ) {
            $head[] = '  <link rel="canonical" href="' . metis_escape_attr( $canonical ) . '">';
        }
        if ( $noindex ) {
            $head[] = '  <meta name="robots" content="noindex, nofollow">';
        }
        if ( $og_title !== '' ) {
            $head[] = '  <meta property="og:title" content="' . metis_escape_attr( $og_title ) . '">';
        }
        if ( $og_description !== '' ) {
            $head[] = '  <meta property="og:description" content="' . metis_escape_attr( $og_description ) . '">';
        }
        if ( $og_url !== '' ) {
            $head[] = '  <meta property="og:url" content="' . metis_escape_attr( $og_url ) . '">';
        }
        $head[] = '  <meta property="og:type" content="' . metis_escape_attr( metis_key_clean( (string) ( $context['content_type'] ?? '' ) ) === 'post' ? 'article' : 'website' ) . '">';
        if ( $og_image !== '' ) {
            $head[] = '  <meta property="og:image" content="' . metis_escape_attr( $og_image ) . '">';
        }
        foreach ( $font_stylesheets as $font_href ) {
            $font_href = trim( (string) $font_href );
            if ( $font_href === '' ) {
                continue;
            }
            $head[] = '  <link rel="stylesheet" href="' . metis_escape_attr( $font_href ) . '">';
        }

        if ( $style_href !== '' ) {
            $head[] = '  <link rel="stylesheet" href="' . metis_escape_attr( $style_href ) . '">';
        }
        // Keep preview/live on one CSS pipeline via generated stylesheet route.
        $favicon_url = self::portalAssetUrl( 'portal_favicon', 'metis_portal_favicon_url' );
        $favicon_mime = self::portalAssetMime( 'portal_favicon', 'metis_portal_favicon_asset' );
        if ( $favicon_url !== '' ) {
            $type_attr = $favicon_mime !== '' ? ' type="' . metis_escape_attr( $favicon_mime ) . '"' : '';
            $head[] = '  <link rel="icon" href="' . metis_escape_attr( $favicon_url ) . '"' . $type_attr . '>';
            $head[] = '  <link rel="shortcut icon" href="' . metis_escape_attr( $favicon_url ) . '"' . $type_attr . '>';
            $head[] = '  <link rel="apple-touch-icon" href="' . metis_escape_attr( $favicon_url ) . '">';
        }

        $head[] = '</head>';
        $head[] = '<body class="' . metis_escape_attr( implode( ' ', $body_classes ) ) . '"' . $body_attrs . '>';

        $suppress_header = self::suppressHeaderForHomepage( $template_structure, $context );
        $header = $suppress_header ? '' : self::renderShellHeader( $context, $site_layout_profile, $menu_dataset );
        $footer = self::renderShellFooter( $context, $site_layout_profile, $menu_dataset );
        $sidebar = self::renderTemplateRegion( $template_structure, 'sidebar' );
        $layout = is_array( $template_structure['layout'] ?? null ) ? $template_structure['layout'] : [];
        $with_sidebar = ! empty( $layout['main_with_sidebar'] ) && $sidebar !== '';
        $sidebar_position = (string) ( $layout['sidebar_position'] ?? 'right' );
        if ( ! in_array( $sidebar_position, [ 'left', 'right' ], true ) ) {
            $sidebar_position = 'right';
        }

        $file_template_html = self::renderFileTemplateLayout(
            $template_structure,
            $context,
            $site_layout_profile,
            $menu_dataset,
            self::renderHomepageHero( $hero, $context ),
            (string) $content,
            (string) $sidebar,
            $with_sidebar,
            $sidebar_position,
            $resolved_page_data,
            $layout_settings
        );
        $file_template_html = trim( $file_template_html );

        $body = [];
        $body_prepend = self::webPartSlotHtml( $web_part_slots, 'body:prepend' );
        if ( $body_prepend !== '' ) {
            $body[] = $body_prepend;
        }
        $banners_before = self::webPartSlotHtml( $web_part_slots, 'banners:before' );
        if ( $banners_before !== '' ) {
            $body[] = $banners_before;
        }
        $banners_prepend = self::webPartSlotHtml( $web_part_slots, 'banners:prepend' );
        $banners_append = self::webPartSlotHtml( $web_part_slots, 'banners:append' );
        if ( $banners !== '' || $banners_prepend !== '' || $banners_append !== '' ) {
            $body[] = '<div class="metis-template-banners">' . $banners_prepend . $banners . $banners_append . '</div>';
        }
        $banners_after = self::webPartSlotHtml( $web_part_slots, 'banners:after' );
        if ( $banners_after !== '' ) {
            $body[] = $banners_after;
        }
        if ( $file_template_html !== '' ) {
            $body[] = $file_template_html;
        } elseif ( ! $suppress_header ) {
            $header_before = self::webPartSlotHtml( $web_part_slots, 'header:before' );
            if ( $header_before !== '' ) {
                $body[] = $header_before;
            }
            $header_prepend = self::webPartSlotHtml( $web_part_slots, 'header:prepend' );
            $header_append = self::webPartSlotHtml( $web_part_slots, 'header:append' );
            if ( $header !== '' || $header_prepend !== '' || $header_append !== '' ) {
                $body[] = '<header class="metis-site-header">' . $header_prepend . $header . $header_append . '</header>';
            }
            $header_after = self::webPartSlotHtml( $web_part_slots, 'header:after' );
            if ( $header_after !== '' ) {
                $body[] = $header_after;
            }
        }

        if ( $file_template_html === '' ) {
            $main_before = self::webPartSlotHtml( $web_part_slots, 'main:before' );
            if ( $main_before !== '' ) {
                $body[] = $main_before;
            }
            $main_prepend = self::webPartSlotHtml( $web_part_slots, 'main:prepend' );
            $main_append = self::webPartSlotHtml( $web_part_slots, 'main:append' );
            $sidebar_before = self::webPartSlotHtml( $web_part_slots, 'sidebar:before' );
            $sidebar_prepend = self::webPartSlotHtml( $web_part_slots, 'sidebar:prepend' );
            $sidebar_append = self::webPartSlotHtml( $web_part_slots, 'sidebar:append' );
            $sidebar_after = self::webPartSlotHtml( $web_part_slots, 'sidebar:after' );
            $body[] = self::renderShellBody(
                [
                    'context' => $context,
                    'content_html' => $main_prepend . $content . $main_append,
                    'sidebar_html' => $sidebar_prepend . $sidebar . $sidebar_append,
                    'sidebar_before' => $sidebar_before,
                    'sidebar_after' => $sidebar_after,
                    'with_sidebar' => $with_sidebar,
                    'sidebar_position' => $sidebar_position,
                    'site_layout_profile' => $site_layout_profile,
                ]
            );
            $main_after = self::webPartSlotHtml( $web_part_slots, 'main:after' );
            if ( $main_after !== '' ) {
                $body[] = $main_after;
            }

            $footer_before = self::webPartSlotHtml( $web_part_slots, 'footer:before' );
            if ( $footer_before !== '' ) {
                $body[] = $footer_before;
            }
            $footer_prepend = self::webPartSlotHtml( $web_part_slots, 'footer:prepend' );
            $footer_append = self::webPartSlotHtml( $web_part_slots, 'footer:append' );
            if ( $footer !== '' || $footer_prepend !== '' || $footer_append !== '' ) {
                $body[] = '<footer class="metis-site-footer">' . $footer_prepend . $footer . $footer_append . '</footer>';
            }
            $footer_after = self::webPartSlotHtml( $web_part_slots, 'footer:after' );
            if ( $footer_after !== '' ) {
                $body[] = $footer_after;
            }
        }
        if ( $popups !== '' ) {
            $body[] = $popups;
        }
        $body_append = self::webPartSlotHtml( $web_part_slots, 'body:append' );
        if ( $body_append !== '' ) {
            $body[] = $body_append;
        }

        $tail = [];
        $menu_script = self::navigationBehaviorScript();
        if ( $menu_script !== '' ) {
            $tail[] = $menu_script;
        }
        $header_script = self::headerBehaviorScript();
        if ( $header_script !== '' ) {
            $tail[] = $header_script;
        }
        $tail[] = '</body>';
        $tail[] = '</html>';

        return implode( "\n", array_merge( $head, $body, $tail ) );
    }

    private static function suppressHeaderForHomepage( array $template_structure, array $context ): bool {
        $layout = is_array( $template_structure['layout'] ?? null ) ? $template_structure['layout'] : [];
        if ( empty( $layout['suppress_header_on_homepage'] ) ) {
            return false;
        }
        $path = trim( (string) ( $context['path'] ?? '' ) );
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        return $content_type === 'page' && in_array( $path, [ '', '/' ], true );
    }

    private static function headerBehaviorScript(): string {
        return '<script>(function(){var headers=document.querySelectorAll(".metis-template-header,.metis-shell-header");if(!headers.length){return;}function sync(){var scrolled=(window.scrollY||window.pageYOffset||0)>8;for(var i=0;i<headers.length;i++){headers[i].classList.toggle("is-scrolled",scrolled);}}window.addEventListener("scroll",sync,{passive:true});sync();})();</script>';
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $page_data
     */
    private static function prependPostFeaturedImage( string $content_html, array $context, array $page_data ): string {
        if ( metis_key_clean( (string) ( $context['content_type'] ?? '' ) ) !== 'post' ) {
            return $content_html;
        }

        $header_html = self::buildPostHeaderHtml( $page_data );

        $image_url = trim( (string) ( $page_data['featured_image_url'] ?? '' ) );
        if ( $image_url === '' ) {
            return $header_html . $content_html;
        }

        $caption = trim( (string) ( $page_data['featured_image_caption'] ?? '' ) );
        $figure = '<figure class="metis-template-post-media">';
        $figure .= '<img src="' . metis_escape_attr( self::normalizePublicUrl( $image_url ) ) . '" alt="">';
        if ( $caption !== '' ) {
            $figure .= '<figcaption class="metis-template-post-media-caption">' . metis_escape_html( $caption ) . '</figcaption>';
        }
        $figure .= '</figure>';

        return $header_html . $figure . $content_html;
    }

    /**
     * @param array<string,mixed> $page_data
     */
    private static function prependPostHeader( string $content_html, array $page_data ): string {
        return self::buildPostHeaderHtml( $page_data ) . $content_html;
    }

    /**
     * @param array<string,mixed> $page_data
     */
    private static function buildPostHeaderHtml( array $page_data ): string {
        $title = trim( (string) ( $page_data['title'] ?? '' ) );
        $date_raw = trim( (string) ( $page_data['publish_date'] ?? '' ) );
        $author = trim( (string) ( $page_data['author_name'] ?? '' ) );

        if ( $title === '' && $date_raw === '' && $author === '' ) {
            return '';
        }

        $meta_parts = [];
        $timestamp = $date_raw !== '' ? strtotime( $date_raw ) : false;
        if ( $timestamp !== false && $timestamp > 0 ) {
            $date_label = self::formatSystemDate( (int) $timestamp );
            $meta_parts[] = 'Posted on ' . $date_label;
        }
        if ( $author !== '' ) {
            $meta_parts[] = 'By ' . $author;
        }

        $html = '<header class="metis-template-post-header">';
        if ( $title !== '' ) {
            $html .= '<div class="metis-structured-section__head metis-structured-section__head--post"><h1>' . metis_escape_html( $title ) . '</h1></div>';
        }
        if ( $meta_parts !== [] ) {
            $html .= '<p class="metis-template-post-meta">' . metis_escape_html( implode( ' ', $meta_parts ) ) . '</p>';
        }
        $html .= '</header>';

        return $html;
    }

    private static function authorNameById( int $user_id ): string {
        if ( $user_id < 1 ) {
            return '';
        }

        if ( function_exists( 'metis_auth_find_user' ) ) {
            $auth_user = metis_auth_find_user( 'id', $user_id );
            if ( is_array( $auth_user ) ) {
                $person_id = (int) ( $auth_user['person_id'] ?? 0 );
                if ( $person_id > 0 && function_exists( 'metis_auth_get_person' ) ) {
                    $person = metis_auth_get_person( $person_id );
                    if ( is_array( $person ) ) {
                        $full = trim( (string) ( $person['first_name'] ?? '' ) . ' ' . (string) ( $person['last_name'] ?? '' ) );
                        if ( $full !== '' ) {
                            return $full;
                        }
                        $display = trim( (string) ( $person['display_name'] ?? '' ) );
                        if ( $display !== '' ) {
                            return $display;
                        }
                    }
                }

                $display = trim( (string) ( $auth_user['display_name'] ?? '' ) );
                if ( $display !== '' ) {
                    return $display;
                }
                $full = trim( (string) ( $auth_user['first_name'] ?? '' ) . ' ' . (string) ( $auth_user['last_name'] ?? '' ) );
                if ( $full !== '' ) {
                    return $full;
                }
                return trim( (string) ( $auth_user['user_login'] ?? '' ) );
            }
        }

        return '';
    }

    private static function systemDateFormat(): string {
        return function_exists( 'metis_runtime_date_format' ) ? metis_runtime_date_format() : 'M j, Y';
    }

    private static function formatSystemDate( int $timestamp ): string {
        if ( $timestamp < 1 ) {
            return '';
        }
        return function_exists( 'metis_runtime_format_date' )
            ? (string) metis_runtime_format_date( $timestamp, self::systemDateFormat() )
            : gmdate( 'M j, Y', $timestamp );
    }

    private static function documentTitle( string $page_title, array $context = [] ): string {
        $org_name = class_exists( 'Core_Settings_Service' ) ? (string) \Core_Settings_Service::get( 'org_name', '' ) : '';
        if ( $org_name === '' && function_exists( 'metis_portal_name' ) ) {
            $org_name = (string) metis_portal_name();
        }
        if ( trim( $org_name ) === '' ) {
            $org_name = 'Website';
        }

        $path = trim( (string) ( $context['path'] ?? '' ) );
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        $is_homepage = $content_type === 'page' && in_array( $path, [ '', '/' ], true );
        if ( $is_homepage ) {
            $tagline = class_exists( 'Core_Settings_Service' ) ? trim( (string) \Core_Settings_Service::get( 'org_tagline', '' ) ) : '';
            if ( $tagline !== '' ) {
                return $org_name . ': ' . $tagline;
            }
            return $org_name;
        }

        $page_title = trim( $page_title );
        if ( $page_title === '' || strcasecmp( $page_title, $org_name ) === 0 ) {
            return $org_name;
        }

        return $org_name . ' - ' . $page_title;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,string>
     */
    private static function bodyClassList( array $context, string $site_layout_profile_key, string $menu_style, array $menu_config = [] ): array {
        $classes = [ 'metis-public-site' ];
        $classes[] = 'metis-layout-' . self::safeHtmlClass( self::visualLayoutClassForProfile( $site_layout_profile_key ) );
        $classes[] = 'metis-menu-style-' . self::safeHtmlClass( self::sanitizeMenuStyle( $menu_style ) );
        $classes[] = 'metis-menu-source-theme';
        $layout = self::sanitizeMenuLayout( (string) ( $menu_config['layout'] ?? '' ) );
        if ( $layout !== '' ) {
            $classes[] = 'metis-menu-layout-' . self::safeHtmlClass( $layout );
        }
        $alignment = metis_key_clean( (string) ( $menu_config['alignment'] ?? '' ) );
        if ( in_array( $alignment, [ 'left', 'center', 'right' ], true ) ) {
            $classes[] = 'metis-menu-align-' . self::safeHtmlClass( $alignment );
        }
        $container = metis_key_clean( (string) ( $menu_config['container'] ?? '' ) );
        if ( in_array( $container, [ 'full', 'contained' ], true ) ) {
            $classes[] = 'metis-menu-container-' . self::safeHtmlClass( $container );
        }
        $classes[] = 'metis-menu-mobile-slide';
        $classes[] = 'metis-menu-mobile-btn-rounded';
        $chevron = is_array( $menu_config['chevron'] ?? null ) ? $menu_config['chevron'] : [];
        $chevron_type = metis_key_clean( (string) ( $chevron['type'] ?? '' ) );
        if ( in_array( $chevron_type, [ 'arrow', 'chevron' ], true ) ) {
            $classes[] = 'metis-menu-chevron-' . self::safeHtmlClass( $chevron_type );
        }
        $dropdown = is_array( $menu_config['dropdown'] ?? null ) ? $menu_config['dropdown'] : [];
        $dropdown_behavior = self::sanitizeMenuDropdownBehavior( (string) ( $dropdown['behavior'] ?? '' ) );
        if ( $dropdown_behavior !== '' ) {
            $classes[] = 'metis-menu-dropdown-' . self::safeHtmlClass( $dropdown_behavior );
        }
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        $path = trim( (string) ( $context['path'] ?? '' ) );
        if ( $content_type === 'page' && in_array( $path, [ '', '/' ], true ) ) {
            $classes[] = 'metis-view-homepage';
        } elseif ( $content_type !== '' ) {
            $classes[] = 'metis-view-' . self::safeHtmlClass( $content_type );
        }
        $content_format = metis_key_clean( (string) ( $context['content_format'] ?? '' ) );
        if ( in_array( $content_format, [ 'standard', 'transcript' ], true ) ) {
            $classes[] = 'metis-content-format-' . self::safeHtmlClass( $content_format );
        }
        return $classes;
    }

    private static function menuBodyDataAttributes( array $menu_config ): string {
        $breakpoint = 980;
        $dropdown = is_array( $menu_config['dropdown'] ?? null ) ? $menu_config['dropdown'] : [];
        $dropdown_behavior = self::sanitizeMenuDropdownBehavior( (string) ( $dropdown['behavior'] ?? 'hover' ) );
        return ' data-metis-nav-breakpoint="' . metis_escape_attr( (string) $breakpoint ) . '" data-metis-nav-dropdown-behavior="' . metis_escape_attr( $dropdown_behavior ) . '"';
    }

    private static function sanitizeMenuLayout( string $layout ): string {
        $layout = metis_key_clean( strtolower( trim( $layout ) ) );
        if ( $layout === 'centered' ) {
            $layout = 'centered_logo';
        } elseif ( $layout === 'split' ) {
            $layout = 'split_nav';
        } elseif ( $layout === 'sidebar' ) {
            $layout = 'sidebar_overlay';
        }
        $allowed = [ 'horizontal_clean', 'centered_logo', 'split_nav', 'minimal_topbar', 'glide_gradient', 'marker_dropdown', 'sidebar_overlay' ];
        if ( ! in_array( $layout, $allowed, true ) ) {
            return '';
        }
        return $layout;
    }

    private static function sanitizeMenuDropdownBehavior( string $behavior ): string {
        $behavior = metis_key_clean( strtolower( trim( $behavior ) ) );
        if ( ! in_array( $behavior, [ 'hover', 'click' ], true ) ) {
            return 'hover';
        }
        return $behavior;
    }

    private static function sanitizeMenuStyle( string $style ): string {
        $normalized = metis_key_clean( strtolower( trim( $style ) ) );
        $allowed = [
            'h_glide',
            'h_marker_dropdown',
            'h_pill_dropdown',
            'h_modern_bar',
        ];
        if ( ! in_array( $normalized, $allowed, true ) ) {
            return 'h_glide';
        }
        return $normalized;
    }

    private static function visualLayoutClassForProfile( string $profile_key ): string {
        $normalized = metis_key_clean( $profile_key );
        if ( $normalized === '' ) {
            return 'modern_split';
        }

        return match ( $normalized ) {
            'image_overlay_banner' => 'modern_split',
            'centered_stack_marketing' => 'centered_editorial',
            'hero_split_glass' => 'impact_campaign',
            'editorial_focus' => 'story_sidebar',
            'compact_app_style' => 'minimal_focus',
            'modular_grid_dash' => 'showcase_grid',
            default => $normalized,
        };
    }

    private static function stylesheetHref( array $context, array $template_structure = [], bool $template_preview_mode = false ): string {
        $base = function_exists( 'metis_home_url' )
            ? (string) metis_home_url( '/v1/website/theme.css' )
            : '/metis/v1/website/theme.css';

        $params = [];
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        if ( in_array( $content_type, [ 'page', 'post', 'error' ], true ) ) {
            $params['content_type'] = $content_type;
        }
        $content_id = isset( $context['content_id'] ) ? (int) $context['content_id'] : 0;
        if ( $content_id > 0 ) {
            $params['content_id'] = (string) $content_id;
        }
        $template_slug = self::templateVariantSlug( $template_structure );
        if ( $template_slug !== '' ) {
            $params['template_slug'] = $template_slug;
        }
        if ( $template_preview_mode ) {
            $params['template_preview'] = '1';
        }
        $params['v'] = self::assetVersionToken( $template_structure, true );

        if ( function_exists( 'metis_add_query_arg' ) ) {
            return (string) metis_add_query_arg( $params, $base );
        }

        if ( $params === [] ) {
            return $base;
        }

        return $base . '?' . http_build_query( $params );
    }

    private static function publicAssetUrl( string $asset ): string {
        $file = ltrim( trim( $asset ), '/' );
        if ( $file === '' ) {
            return '';
        }
        if ( function_exists( 'metis_module_asset_url' ) ) {
            $base = (string) metis_module_asset_url( 'website', $file );
        } else {
            $base = '/metis/assets/modules/website/' . $file;
        }
        $version = self::assetVersionToken();
        if ( function_exists( 'metis_add_query_arg' ) ) {
            return (string) metis_add_query_arg( [ 'v' => $version ], $base );
        }
        $sep = strpos( $base, '?' ) === false ? '?' : '&';
        return $base . $sep . 'v=' . rawurlencode( $version );
    }

    private static function assetVersionToken( array $template_structure = [], bool $include_theme_state = false ): string {
        $version = defined( 'METIS_VERSION' ) ? (string) METIS_VERSION : '1';
        $candidates = [
            METIS_SRC_PATH . 'Metis/Modules/Website/Services/WebsiteRenderer.php',
            METIS_SRC_PATH . 'Metis/Modules/Website/Services/ThemeService.php',
            METIS_MODULES_PATH . 'website/assets/public-navigation.js',
        ];
        $template_slug = self::templateVariantSlug( $template_structure );
        if ( $template_slug !== '' ) {
            $candidates[] = METIS_MODULES_PATH . 'website/Templates/' . $template_slug . '/structure.css';
            $candidates[] = METIS_MODULES_PATH . 'website/Templates/' . $template_slug . '/menu.css';
        }
        $latest = 0;
        foreach ( $candidates as $path ) {
            if ( ! is_string( $path ) || $path === '' || ! is_file( $path ) ) {
                continue;
            }
            $mtime = (int) @filemtime( $path );
            if ( $mtime > $latest ) {
                $latest = $mtime;
            }
        }
        $token = $latest > 0 ? (string) $latest : $version;
        if ( $include_theme_state ) {
            $theme_token = ThemeService::activeVersionToken();
            if ( $theme_token !== '' ) {
                $token .= '-' . $theme_token;
            }
        }
        return $token;
    }

    public static function renderGeneratedCss( array $query = [] ): string {
        $content_type = metis_key_clean( (string) ( $query['content_type'] ?? '' ) );
        $content_id = (int) ( $query['content_id'] ?? 0 );
        $template_slug = metis_key_clean( (string) ( $query['template_slug'] ?? '' ) );
        $template_preview_mode = in_array(
            strtolower( trim( (string) ( $query['template_preview'] ?? '' ) ) ),
            [ '1', 'true', 'yes' ],
            true
        );
        $layout_settings = [];

        if ( $content_type === 'page' && $content_id > 0 ) {
            $page = PageService::getById( $content_id );
            if ( $page !== null && $page->status === 'published' ) {
                $layout_settings = self::layoutSettingsFromRaw(
                    $page->published_layout_json !== null ? $page->published_layout_json : ( $page->draft_layout_json ?? $page->layout_json )
                );
            }
        } elseif ( $content_type === 'post' && $content_id > 0 ) {
            $post = PostService::getById( $content_id );
            if ( $post !== null && $post->status === 'published' ) {
                $layout_settings = self::layoutSettingsFromRaw(
                    $post->published_content_json !== null ? $post->published_content_json : ( $post->draft_content_json ?? $post->content_json )
                );
            }
        }

        $theme = ThemeService::getActiveNormalized();
        $style_parts = self::loadPipelineStyles(
            $theme,
            $layout_settings,
            [
                'content_type' => $content_type,
                'content_id' => $content_id,
            ]
        );
        $template_structure = $template_slug !== ''
            ? TemplateService::resolveStructureForSlug( $template_slug )
            : TemplateService::resolveForArchive();
        $template_structure_css = self::loadTemplateStructureCss( $template_structure );
        if ( $template_structure_css !== '' ) {
            $style_parts[] = $template_structure_css;
        }
        $style_parts[] = self::themeTemplateMenuCss();
        if ( $template_preview_mode ) {
            $style_parts[] = self::templatePreviewIsolationCss();
        }
        return implode( "\n\n", $style_parts );
    }

    private static function templateRegionEnabled( array $template_structure, string $region ): bool {
        $regions = is_array( $template_structure['regions'] ?? null ) ? $template_structure['regions'] : [];
        $config = is_array( $regions[ $region ] ?? null ) ? $regions[ $region ] : [];
        return ! empty( $config['enabled'] );
    }

    /**
     * @param array<int,array<string,mixed>> $parts
     * @return array<string,string>
     */
    private static function placeWebParts( array $parts, array $template_structure ): array {
        if ( $parts === [] ) {
            return [];
        }

        $slots = [];
        foreach ( $parts as $part ) {
            $region = metis_key_clean( (string) ( $part['region'] ?? 'main' ) );
            $slot = metis_key_clean( (string) ( $part['slot'] ?? 'append' ) );
            $html = (string) ( $part['html'] ?? '' );
            if ( $html === '' ) {
                continue;
            }

            if ( ! in_array( $region, [ 'body', 'header', 'main', 'sidebar', 'footer', 'banners' ], true ) ) {
                $region = 'main';
            }
            if ( ! in_array( $slot, [ 'before', 'prepend', 'append', 'after' ], true ) ) {
                $slot = 'append';
            }
            if ( $region === 'banners' && ! self::templateRegionEnabled( $template_structure, 'banners' ) ) {
                continue;
            }

            $key = $region . ':' . $slot;
            if ( ! isset( $slots[ $key ] ) ) {
                $slots[ $key ] = '';
            }
            $slots[ $key ] .= $html;
        }

        return $slots;
    }

    /**
     * @param array<string,string> $slots
     */
    private static function webPartSlotHtml( array $slots, string $key ): string {
        return isset( $slots[ $key ] ) ? (string) $slots[ $key ] : '';
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,array<int,array<string,mixed>>> $menu_dataset
     */
    private static function renderShellHeader( array $context, array $site_layout_profile = [], array $menu_dataset = [] ): string {
        if ( ! empty( $context['template_preview_mode'] ) ) {
            return self::renderShellPartial( 'header', [
                'context' => $context,
                'site_name' => 'Template Preview',
                'org_name' => 'Template Preview',
                'home_url' => '#',
                'logo_url' => '',
                'menu_html' => '<ul class="metis-shell-menu-list"><li class="metis-shell-menu-item"><a href="#">Home</a></li><li class="metis-shell-menu-item"><a href="#">About</a></li><li class="metis-shell-menu-item"><a href="#">Contact</a></li></ul>',
                'utility_menu_html' => '',
                'header_variant' => metis_key_clean( (string) ( $site_layout_profile['header_variant'] ?? 'split' ) ),
            ] );
        }

        $site_name = function_exists( 'metis_portal_name' ) ? (string) metis_portal_name() : 'Website';
        $org_name = class_exists( 'Core_Settings_Service' ) ? (string) \Core_Settings_Service::get( 'org_name', '' ) : '';
        $logo_url = self::portalAssetUrl( 'portal_logo', 'metis_portal_logo_url' );
        $primary_locations = is_array( $site_layout_profile['primary_menu_locations'] ?? null )
            ? $site_layout_profile['primary_menu_locations']
            : [ 'primary', 'header' ];
        $utility_locations = is_array( $site_layout_profile['utility_menu_locations'] ?? null )
            ? $site_layout_profile['utility_menu_locations']
            : [ 'utility', 'secondary', 'top' ];
        $menu_html = self::renderShellMenuByLocation( $primary_locations, [], $menu_dataset );
        $utility_menu_html = self::renderShellMenuByLocation( $utility_locations, [], $menu_dataset );
        $header_variant = metis_key_clean( (string) ( $site_layout_profile['header_variant'] ?? 'split' ) );

        return self::renderShellPartial( 'header', [
            'context' => $context,
            'site_name' => $site_name,
            'org_name' => $org_name !== '' ? $org_name : $site_name,
            'home_url' => self::normalizePublicUrl( '/' ),
            'logo_url' => $logo_url,
            'menu_html' => $menu_html,
            'utility_menu_html' => $utility_menu_html,
            'header_variant' => $header_variant,
        ] );
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,array<int,array<string,mixed>>> $menu_dataset
     */
    private static function renderShellFooter( array $context, array $site_layout_profile = [], array $menu_dataset = [] ): string {
        if ( ! empty( $context['template_preview_mode'] ) ) {
            return self::renderShellPartial( 'footer', [
                'context' => $context,
                'site_name' => 'Template Preview',
                'org_name' => 'Template Preview',
                'logo_url' => '',
                'menu_html' => '<ul class="metis-shell-menu-list"><li class="metis-shell-menu-item"><a href="#">Footer Link</a></li><li class="metis-shell-menu-item"><a href="#">Resources</a></li></ul>',
                'year' => (string) gmdate( 'Y' ),
                'footer_variant' => metis_key_clean( (string) ( $site_layout_profile['footer_variant'] ?? 'columns' ) ),
            ] );
        }

        $site_name = function_exists( 'metis_portal_name' ) ? (string) metis_portal_name() : 'Website';
        $org_name = class_exists( 'Core_Settings_Service' ) ? (string) \Core_Settings_Service::get( 'org_name', '' ) : '';
        $logo_url = self::portalAssetUrl( 'portal_logo', 'metis_portal_logo_url' );
        $footer_locations = is_array( $site_layout_profile['footer_menu_locations'] ?? null )
            ? $site_layout_profile['footer_menu_locations']
            : [ 'footer', 'primary', 'header' ];
        $menu_html = self::renderShellMenuByLocation( $footer_locations, [
            'mode' => 'footer',
        ], $menu_dataset );
        if ( trim( $menu_html ) === '' ) {
            $menu_html = self::renderShellMenuByLocation( [ 'primary', 'header' ], [
                'mode' => 'footer',
            ], $menu_dataset );
        }
        $footer_variant = metis_key_clean( (string) ( $site_layout_profile['footer_variant'] ?? 'columns' ) );

        return self::renderShellPartial( 'footer', [
            'context' => $context,
            'site_name' => $site_name,
            'org_name' => $org_name !== '' ? $org_name : $site_name,
            'logo_url' => $logo_url,
            'menu_html' => $menu_html,
            'year' => (string) gmdate( 'Y' ),
            'footer_variant' => $footer_variant,
        ] );
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderShellBody( array $data ): string {
        return self::renderShellPartial( 'body', $data );
    }

    /**
     * @param array<string,mixed> $template_structure
     * @param array<string,mixed> $context
     * @param array<string,mixed> $site_layout_profile
     * @param array<string,array<int,array<string,mixed>>> $menu_dataset
     */
    private static function renderFileTemplateLayout(
        array $template_structure,
        array $context,
        array $site_layout_profile,
        array $menu_dataset,
        string $hero_html,
        string $content_html,
        string $sidebar_html,
        bool $with_sidebar,
        string $sidebar_position,
        array $page_data = [],
        array $layout_settings = []
    ): string {
        $meta = is_array( $template_structure['editor_meta'] ?? null ) ? $template_structure['editor_meta'] : [];
        $layout_path = self::resolveTemplateLayoutPathForContext( $meta, $context );
        if ( $layout_path === '' || ! is_file( $layout_path ) ) {
            return '';
        }

        $template_slug = self::templateVariantSlug( $template_structure );
        $brand_html = self::renderTemplateBrand( $context );
        $primary_locations = is_array( $site_layout_profile['primary_menu_locations'] ?? null )
            ? $site_layout_profile['primary_menu_locations']
            : [ 'primary', 'header' ];
        $menu_html = self::renderShellMenuByLocation( $primary_locations, [], $menu_dataset );
        $footer_html = self::renderTemplateFooterContent( $context, $site_layout_profile, $menu_dataset );
        if ( $hero_html === '' && ! empty( $context['template_preview_mode'] ) && ! empty( $context['template_preview_placeholder'] ) ) {
            $hero_html = '<div class="metis-template-preview-hero"><div class="metis-template-preview-hero-kicker"></div><div class="metis-template-preview-hero-title"></div><div class="metis-template-preview-hero-copy"></div></div>';
            if ( trim( $sidebar_html ) === '' && in_array( $template_slug, [ 'editorial_focus', 'modular_grid_dash', 'hero_split_glass' ], true ) ) {
                $sidebar_html = '<div class="metis-template-preview-sidebar"><div class="metis-template-preview-side-item"></div><div class="metis-template-preview-side-item"></div><div class="metis-template-preview-side-item"></div></div>';
                $with_sidebar = true;
            }
        }

        $header_html = $brand_html;
        $page_data = $page_data !== [] ? $page_data : ( is_array( $context['page'] ?? null ) ? $context['page'] : [] );
        $layout_settings = $layout_settings !== [] ? $layout_settings : ( is_array( $context['layout_settings'] ?? null ) ? $context['layout_settings'] : [] );
        ob_start();
        include $layout_path;
        $html = ob_get_clean();
        if ( ! is_string( $html ) ) {
            return '';
        }
        return $html;
    }

    /**
     * @param array<string,mixed> $hero
     * @param array<string,mixed> $context
     */
    private static function renderHomepageHero( array $hero, array $context ): string {
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        if ( $content_type !== 'page' || empty( $context['is_homepage'] ) ) {
            return '';
        }

        if ( empty( $hero['enabled'] ) ) {
            return '';
        }

        $style = metis_key_clean( (string) ( $hero['style'] ?? 'split' ) );
        if ( ! in_array( $style, [ 'split', 'centered', 'overlay' ], true ) ) {
            $style = 'split';
        }
        $headline = trim( (string) ( $hero['headline'] ?? '' ) );
        $subtext = trim( (string) ( $hero['subtext'] ?? '' ) );
        $cta_label = trim( (string) ( $hero['primary_cta_label'] ?? '' ) );
        $cta_link = trim( (string) ( $hero['primary_cta_link'] ?? '' ) );
        $media_url = trim( (string) ( $hero['media_url'] ?? '' ) );

        if ( $headline === '' && $subtext === '' && $cta_label === '' && $media_url === '' ) {
            return '';
        }

        $html = '<div class="metis-template-hero-shell metis-template-hero-style-' . metis_escape_attr( $style ) . '">';
        if ( $media_url !== '' ) {
            $html .= '<figure class="metis-template-hero-media"><img src="' . metis_escape_attr( self::normalizePublicUrl( $media_url ) ) . '" alt=""></figure>';
        }
        $html .= '<div class="metis-template-hero-copy">';
        if ( $headline !== '' ) {
            $html .= '<h1 class="metis-template-hero-title">' . metis_escape_html( $headline ) . '</h1>';
        }
        if ( $subtext !== '' ) {
            $html .= '<p class="metis-template-hero-subtext">' . metis_escape_html( $subtext ) . '</p>';
        }
        if ( $cta_label !== '' ) {
            $href = $cta_link !== '' ? self::normalizePublicUrl( $cta_link ) : '#';
            $html .= '<p class="metis-template-hero-actions"><a class="metis-template-hero-button" href="' . metis_escape_attr( $href ) . '">' . metis_escape_html( $cta_label ) . '</a></p>';
        }
        $html .= '</div></div>';

        return $html;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $context
     */
    private static function resolveTemplateLayoutPathForContext( array $meta, array $context ): string {
        $kind = self::templateLayoutKindFromContext( $context );
        $files = is_array( $meta['template_layout_files'] ?? null ) ? $meta['template_layout_files'] : [];
        $candidate = isset( $files[ $kind ] ) ? (string) $files[ $kind ] : '';
        if ( $candidate !== '' && is_file( $candidate ) ) {
            return $candidate;
        }

        $fallback = isset( $meta['template_layout_file'] ) ? (string) $meta['template_layout_file'] : '';
        if ( $fallback !== '' && is_file( $fallback ) ) {
            return $fallback;
        }

        foreach ( [ 'homepage', 'page', 'post' ] as $key ) {
            $path = isset( $files[ $key ] ) ? (string) $files[ $key ] : '';
            if ( $path !== '' && is_file( $path ) ) {
                return $path;
            }
        }

        return '';
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function templateLayoutKindFromContext( array $context ): string {
        $type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        $path = trim( (string) ( $context['path'] ?? '' ) );
        if ( ! empty( $context['template_preview_mode'] ) ) {
            $preview_kind = metis_key_clean( (string) ( $context['preview_layout_kind'] ?? '' ) );
            if ( in_array( $preview_kind, [ 'homepage', 'page', 'post' ], true ) ) {
                return $preview_kind;
            }
        }
        if ( ! empty( $context['is_homepage'] ) || in_array( $path, [ '', '/' ], true ) ) {
            return 'homepage';
        }
        if ( $type === 'post' ) {
            return 'post';
        }
        return 'page';
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function renderTemplateBrand( array $context ): string {
        $site_name = function_exists( 'metis_portal_name' ) ? (string) metis_portal_name() : 'Website';
        $org_name = class_exists( 'Core_Settings_Service' ) ? (string) \Core_Settings_Service::get( 'org_name', '' ) : '';
        $logo_url = self::portalAssetUrl( 'portal_logo', 'metis_portal_logo_url' );
        $name = $org_name !== '' ? $org_name : $site_name;
        $home = self::normalizePublicUrl( '/' );
        if ( $logo_url !== '' ) {
            return '<a class="metis-template-brand" href="' . metis_escape_attr( $home ) . '"><img class="metis-template-brand-logo" src="' . metis_escape_attr( $logo_url ) . '" alt="' . metis_escape_attr( $name ) . '"></a>';
        }
        return '<a class="metis-template-brand" href="' . metis_escape_attr( $home ) . '">' . metis_escape_html( $name ) . '</a>';
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $site_layout_profile
     * @param array<string,array<int,array<string,mixed>>> $menu_dataset
     */
    private static function renderTemplateFooterContent( array $context, array $site_layout_profile, array $menu_dataset = [] ): string {
        $site_name = function_exists( 'metis_portal_name' ) ? (string) metis_portal_name() : 'Website';
        $org_name = class_exists( 'Core_Settings_Service' ) ? (string) \Core_Settings_Service::get( 'org_name', '' ) : '';
        $name = $org_name !== '' ? $org_name : $site_name;

        $footer_locations = is_array( $site_layout_profile['footer_menu_locations'] ?? null )
            ? $site_layout_profile['footer_menu_locations']
            : [ 'footer', 'primary', 'header' ];
        $menu = self::renderShellMenuByLocation( $footer_locations, [ 'mode' => 'footer' ], $menu_dataset );
        if ( trim( $menu ) === '' ) {
            $menu = self::renderShellMenuByLocation( [ 'primary', 'header' ], [ 'mode' => 'footer' ], $menu_dataset );
        }

        return '<div class="metis-template-footer-inner">'
            . '<div class="metis-template-footer-brand-stack">'
            . '<div class="metis-template-footer-brand">' . metis_escape_html( $name ) . '</div>'
            . '<div class="metis-template-footer-meta">&copy; ' . metis_escape_html( (string) gmdate( 'Y' ) ) . ' ' . metis_escape_html( $name ) . '.</div>'
            . '</div>'
            . '<div class="metis-template-footer-menu">' . $menu . '</div>'
            . '</div>';
    }

    private static function portalAssetUrl( string $settings_key, string $helper_function = '' ): string {
        if ( $helper_function !== '' && function_exists( $helper_function ) ) {
            $url = trim( (string) $helper_function() );
            if ( $url !== '' ) {
                return $url;
            }
        }

        $asset = [];
        if ( class_exists( 'Core_Settings_Service' ) ) {
            $stored = \Core_Settings_Service::get( $settings_key, [] );
            if ( is_array( $stored ) ) {
                $asset = $stored;
            }
        }
        if ( $asset === [] ) {
            return '';
        }

        $asset_url = trim( metis_url_clean( (string) ( $asset['url'] ?? '' ) ) );
        if ( $asset_url !== '' ) {
            return $asset_url;
        }

        if ( function_exists( 'metis_settings_asset_src' ) ) {
            $url = trim( (string) metis_settings_asset_src( $asset ) );
            if ( $url !== '' ) {
                return $url;
            }
        }

        $mime_type = trim( (string) ( $asset['mime_type'] ?? '' ) );
        $data_base64 = trim( (string) ( $asset['data_base64'] ?? '' ) );
        if ( $mime_type === '' || $data_base64 === '' ) {
            return '';
        }

        return 'data:' . $mime_type . ';base64,' . $data_base64;
    }

    private static function portalAssetMime( string $settings_key, string $helper_asset_function = '' ): string {
        $asset = [];
        if ( $helper_asset_function !== '' && function_exists( $helper_asset_function ) ) {
            $candidate = $helper_asset_function();
            if ( is_array( $candidate ) ) {
                $asset = $candidate;
            }
        }
        if ( $asset === [] && class_exists( 'Core_Settings_Service' ) ) {
            $stored = \Core_Settings_Service::get( $settings_key, [] );
            if ( is_array( $stored ) ) {
                $asset = $stored;
            }
        }

        $mime_type = strtolower( trim( (string) ( $asset['mime_type'] ?? '' ) ) );
        if ( $mime_type === '' ) {
            return '';
        }

        if ( ! preg_match( '/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/', $mime_type ) ) {
            return '';
        }

        return $mime_type;
    }

    /**
     * @param array<string,mixed> $vars
     */
    private static function renderShellPartial( string $name, array $vars ): string {
        $slug = metis_key_clean( $name );
        if ( ! in_array( $slug, [ 'header', 'body', 'footer' ], true ) ) {
            return '';
        }

        $path = METIS_MODULES_PATH . 'website/Templates/shell/' . $slug . '.php';
        if ( ! is_file( $path ) ) {
            return '';
        }

        extract( $vars, EXTR_SKIP );
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }

    /**
     * @param string|array<int,string> $location
     * @param array<string,mixed> $options
     * @param array<string,array<int,array<string,mixed>>> $menu_dataset
     */
    private static function renderShellMenuByLocation( $location, array $options = [], array $menu_dataset = [] ): string {
        $locations = is_array( $location ) ? $location : [ (string) $location ];
        $mode = metis_key_clean( (string) ( $options['mode'] ?? 'default' ) );
        if ( ! in_array( $mode, [ 'default', 'footer' ], true ) ) {
            $mode = 'default';
        }
        foreach ( $locations as $candidate ) {
            $location_key = metis_key_clean( (string) $candidate );
            if ( $location_key === '' ) {
                continue;
            }
            $tree = is_array( $menu_dataset[ $location_key ] ?? null )
                ? (array) $menu_dataset[ $location_key ]
                : [];
            if ( $tree === [] ) {
                $menu = MenuService::getByLocation( $location_key );
                if ( ! is_array( $menu ) ) {
                    continue;
                }
                $items = MenuService::getItems( $menu );
                if ( ! is_array( $items ) || $items === [] ) {
                    continue;
                }
                $tree = self::normalizeShellMenuTree( $items );
            }
            if ( $mode === 'footer' ) {
                return self::renderShellFooterMenu( $tree );
            }
            $current_path = self::currentRequestComparablePath();
            if ( self::isHomepageComparablePath( $current_path ) ) {
                $current_path = '';
            }
            return '<ul class="metis-shell-menu-list">' . self::renderShellMenuItems( $tree, $current_path ) . '</ul>';
        }
        return '';
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function loadMenuDataset(): array {
        $dataset = [];
        foreach ( [ 'primary', 'header', 'utility', 'secondary', 'top', 'footer' ] as $location ) {
            $menu = MenuService::getByLocation( $location );
            if ( ! is_array( $menu ) ) {
                continue;
            }
            $items = MenuService::getItems( $menu );
            if ( ! is_array( $items ) || $items === [] ) {
                continue;
            }
            $dataset[ $location ] = self::normalizeShellMenuTree( $items );
        }
        return $dataset;
    }

    /**
     * Render menu preview markup for Theme admin using the same menu tree source as public.
     */
    public static function renderThemeMenuPreviewHtml(): string {
        $theme = ThemeService::getActiveNormalized();
        $profile_key = LayoutProfileService::sanitizeWBProfile(
            (string) ( $theme['global_settings']['site_layout_profile'] ?? LayoutProfileService::defaultWBProfileKey() )
        );
        $profile = LayoutProfileService::resolveWBProfile( $profile_key );
        $primary_locations = is_array( $profile['primary_menu_locations'] ?? null )
            ? $profile['primary_menu_locations']
            : [ 'primary', 'header' ];

        $menu_html = self::renderShellMenuByLocation( $primary_locations );
        if ( trim( $menu_html ) === '' ) {
            $menu_html = self::renderShellMenuByLocation( [ 'primary', 'header' ] );
        }
        if ( trim( $menu_html ) === '' ) {
            $menu_html = '<ul class="metis-shell-menu-list"><li class="metis-shell-menu-item"><a href="#" class="metis-shell-menu-link">Home</a></li><li class="metis-shell-menu-item"><a href="#" class="metis-shell-menu-link">About</a></li><li class="metis-shell-menu-item has-children"><a href="#" class="metis-shell-menu-link" aria-haspopup="true">Programs<span class="metis-shell-menu-sub-indicator" aria-hidden="true"></span></a><ul class="metis-shell-menu-sub"><li class="metis-shell-menu-item"><a href="#" class="metis-shell-menu-link">Services</a></li><li class="metis-shell-menu-item"><a href="#" class="metis-shell-menu-link">Events</a></li><li class="metis-shell-menu-item"><a href="#" class="metis-shell-menu-link">Resources</a></li></ul></li><li class="metis-shell-menu-item has-children"><a href="#" class="metis-shell-menu-link" aria-haspopup="true">Support<span class="metis-shell-menu-sub-indicator" aria-hidden="true"></span></a><ul class="metis-shell-menu-sub"><li class="metis-shell-menu-item"><a href="#" class="metis-shell-menu-link">Donate</a></li><li class="metis-shell-menu-item"><a href="#" class="metis-shell-menu-link">Volunteer</a></li><li class="metis-shell-menu-item"><a href="#" class="metis-shell-menu-link">Partner</a></li></ul></li><li class="metis-shell-menu-item"><a href="#" class="metis-shell-menu-link metis-shell-menu-btn metis-shell-menu-btn--metis_primary" data-metis-nav-url="#">Contact</a></li></ul>';
        }
        return '<div class="metis-template metis-theme-menu-preview-shell"><div class="metis-template-header"><div class="metis-template-header-inner"><div class="metis-template-menu metis-shell-nav metis-shell-nav-primary">' . $menu_html . '</div></div></div></div>';
    }

    public static function renderThemeMenuPreviewCss(): string {
        return '<style id="metis-theme-menu-preview-rendered-css">' . self::themeMenuPreviewRenderedCss() . '</style>';
    }

    private static function themeMenuPreviewRenderedCss(): string {
        return implode( "\n", [
            '.metis-theme-menu-live .metis-template .metis-template-header{display:block !important;position:relative !important;padding:0 !important;}',
            '.metis-theme-menu-live .metis-template .metis-template-header-inner{display:flex !important;align-items:center !important;justify-content:flex-start !important;gap:18px !important;padding:0 !important;}',
            '.metis-theme-menu-live .metis-template .metis-template-menu{display:flex !important;align-items:center !important;justify-content:flex-start !important;min-width:0;overflow:visible;}',
            '.metis-theme-menu-live .metis-template .metis-template-menu .metis-shell-menu-list{display:flex !important;justify-content:flex-start !important;align-items:var(--metis-menu-item-align,center) !important;flex-wrap:wrap !important;gap:10px var(--metis-menu-item-gap,16px) !important;list-style:none;margin:0;padding:0;overflow:visible;}',
            self::codepenGlideMenuCss( '.metis-theme-menu-live.metis-menu-style-h_glide .metis-template .metis-template-menu' ),
            self::markerDropdownMenuCss( '.metis-theme-menu-live.metis-menu-style-h_marker_dropdown .metis-template .metis-template-menu' ),
            self::pillDropdownMenuCss( '.metis-theme-menu-live.metis-menu-style-h_pill_dropdown .metis-template .metis-template-menu' ),
            self::modernBarMenuCss( '.metis-theme-menu-live.metis-menu-style-h_modern_bar .metis-template .metis-template-menu' ),
            self::menuButtonAttentionCss( '.metis-theme-menu-live .metis-template .metis-template-menu' ),
        ] );
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private static function renderShellFooterMenu( array $items ): string {
        if ( $items === [] ) {
            return '';
        }
        $columns = '';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $label = trim( (string) ( $item['label'] ?? $item['title'] ?? '' ) );
            if ( $label === '' ) {
                $label = 'Untitled';
            }
            $children = is_array( $item['children'] ?? null ) ? $item['children'] : [];
            $columns .= '<div class="metis-shell-footer-menu-col">';
            $columns .= '<div class="metis-shell-footer-menu-title">' . metis_escape_html( $label ) . '</div>';
            if ( $children !== [] ) {
                $columns .= '<ul class="metis-shell-footer-menu-list">';
                foreach ( $children as $child ) {
                    if ( ! is_array( $child ) ) {
                        continue;
                    }
                    $child_label = trim( (string) ( $child['label'] ?? $child['title'] ?? '' ) );
                    if ( $child_label === '' ) {
                        $child_label = 'Untitled';
                    }
                    $child_url = trim( (string) ( $child['url'] ?? '#' ) );
                    if ( $child_url === '' ) {
                        $child_url = '#';
                    }
                    $child_url = self::normalizePublicUrl( $child_url );
                    $columns .= '<li><a href="' . metis_escape_attr( $child_url ) . '">' . metis_escape_html( $child_label ) . '</a></li>';
                }
                $columns .= '</ul>';
            }
            $columns .= '</div>';
        }
        return '<div class="metis-shell-footer-menu-grid">' . $columns . '</div>';
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeShellMenuTree( array $items ): array {
        if ( $items === [] ) {
            return [];
        }

        $has_nested = false;
        foreach ( $items as $item ) {
            if ( is_array( $item ) && is_array( $item['children'] ?? null ) && ( $item['children'] ?? [] ) !== [] ) {
                $has_nested = true;
                break;
            }
        }
        if ( $has_nested ) {
            return array_values( array_filter( $items, 'is_array' ) );
        }

        $nodes = [];
        $order = [];
        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $id = trim( (string) ( $item['id'] ?? '' ) );
            if ( $id === '' ) {
                $id = 'menu_item_' . (string) $index;
            }
            $item['id'] = $id;
            $item['children'] = [];
            $item['_idx'] = (int) $index;
            $nodes[ $id ] = $item;
            $order[] = $id;
        }

        $roots = [];
        foreach ( $order as $id ) {
            if ( ! isset( $nodes[ $id ] ) ) {
                continue;
            }
            $parent_id = trim( (string) ( $nodes[ $id ]['parent_id'] ?? '' ) );
            if ( $parent_id !== '' && isset( $nodes[ $parent_id ] ) && $parent_id !== $id ) {
                $nodes[ $parent_id ]['children'][] = &$nodes[ $id ];
            } else {
                $roots[] = &$nodes[ $id ];
            }
        }
        unset( $id );

        return self::sortShellMenuTree( $roots );
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @return array<int,array<string,mixed>>
     */
    private static function sortShellMenuTree( array $nodes ): array {
        usort(
            $nodes,
            static function ( array $a, array $b ): int {
                $a_idx = (int) ( $a['_idx'] ?? 0 );
                $b_idx = (int) ( $b['_idx'] ?? 0 );
                return $a_idx <=> $b_idx;
            }
        );
        foreach ( $nodes as &$node ) {
            $children = is_array( $node['children'] ?? null ) ? $node['children'] : [];
            $node['children'] = self::sortShellMenuTree( $children );
            unset( $node['_idx'] );
        }
        unset( $node );
        return $nodes;
    }

    /**
     * @param array<int,mixed> $items
     */
    private static function renderShellMenuItems( array $items, string $current_path = '' ): string {
        $html = '';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $label = trim( (string) ( $item['label'] ?? $item['title'] ?? '' ) );
            if ( $label === '' ) {
                $label = 'Untitled';
            }
            $url = trim( (string) ( $item['url'] ?? '#' ) );
            if ( $url === '' ) {
                $url = '#';
            }
            $url = self::normalizePublicUrl( $url );
            $has_navigable_url = self::shellMenuUrlIsNavigable( $url );
            $self_active = self::menuUrlMatchesCurrentPath( $url, $current_path );
            $as_button = ! empty( $item['as_button'] );
            $button_color_key = metis_key_clean( (string) ( $item['button_color_key'] ?? 'metis_primary' ) );
            if ( ! in_array( $button_color_key, [ 'metis_primary', 'metis_accent', 'metis_text', 'metis_surface' ], true ) ) {
                $button_color_key = 'metis_primary';
            }
            $button_style = $as_button ? self::shellMenuButtonColorStyle( $button_color_key ) : '';
            $children = is_array( $item['children'] ?? null ) ? $item['children'] : [];
            $has_children = $children !== [];
            $children_html = '';
            $children_active = false;
            if ( $has_children ) {
                $children_html = self::renderShellMenuItems( $children, $current_path );
                $children_active = strpos( $children_html, ' aria-current="page"' ) !== false
                    || strpos( $children_html, ' is-active' ) !== false;
            }
            $item_class = 'metis-shell-menu-item'
                . ( $has_children ? ' has-children' : '' )
                . ( $self_active ? ' is-active' : '' )
                . ( $children_active && ! $self_active ? ' is-active-ancestor' : '' );
            $html .= '<li class="' . metis_escape_attr( $item_class ) . '">';
            $submenu_indicator = $has_children ? '<span class="metis-shell-menu-sub-indicator" aria-hidden="true"></span>' : '';
            $label_class = 'metis-shell-menu-link metis-shell-menu-label';
            if ( $as_button ) {
                $label_class .= ' metis-shell-menu-btn metis-shell-menu-btn--' . $button_color_key;
            }
            if ( $as_button ) {
                if ( $has_navigable_url ) {
                    $button_aria = $self_active ? ' aria-current="page"' : '';
                    $button_has_popup = $has_children ? ' aria-haspopup="true" aria-expanded="false"' : '';
                    $html .= '<a href="' . metis_escape_attr( $url ) . '" class="metis-shell-menu-link metis-shell-menu-btn metis-shell-menu-btn--'
                        . metis_escape_attr( $button_color_key ) . '" data-metis-nav-url="' . metis_escape_attr( $url ) . '"' . $button_style . $button_aria . $button_has_popup . '>'
                        . metis_escape_html( $label ) . $submenu_indicator . '</a>';
                } else {
                    $label_focus = $has_children ? ' tabindex="0" aria-haspopup="true" aria-expanded="false"' : '';
                    $html .= '<span class="' . metis_escape_attr( $label_class ) . '"' . $button_style . $label_focus . '>'
                        . metis_escape_html( $label ) . $submenu_indicator . '</span>';
                }
            } else {
                if ( $has_navigable_url ) {
                    $link_attrs = ' class="metis-shell-menu-link"' . ( $has_children ? ' aria-haspopup="true" aria-expanded="false"' : '' ) . ( $self_active ? ' aria-current="page"' : '' );
                    $html .= '<a href="' . metis_escape_attr( $url ) . '"' . $link_attrs . '>' . metis_escape_html( $label ) . $submenu_indicator . '</a>';
                } else {
                    $label_focus = $has_children ? ' tabindex="0" aria-haspopup="true" aria-expanded="false"' : '';
                    $html .= '<span class="' . metis_escape_attr( $label_class ) . '"' . $label_focus . '>'
                        . metis_escape_html( $label ) . $submenu_indicator . '</span>';
                }
            }
            if ( $has_children ) {
                $html .= '<ul class="metis-shell-menu-sub">' . $children_html . '</ul>';
            }
            $html .= '</li>';
        }
        return $html;
    }

    private static function shellMenuButtonColorStyle( string $button_color_key ): string {
        $colors = self::themeTokenColors();
        $bg = $colors[ $button_color_key ] ?? $colors['metis_primary'];
        $text = self::readableTextColorForHex( $bg );
        $border = $button_color_key === 'metis_surface' ? 'var(--metis-color-border,#d8deea)' : 'transparent';

        return ' style="--metis-menu-button-bg:' . metis_escape_attr( $bg ) . ';--metis-menu-button-text:' . metis_escape_attr( $text ) . ';--metis-menu-button-border:' . metis_escape_attr( $border ) . ';"';
    }

    /**
     * @return array<string,string>
     */
    private static function themeTokenColors(): array {
        $defaults = [
            'metis_primary' => '#485bc7',
            'metis_accent' => '#ff7542',
            'metis_text' => '#1f2330',
            'metis_surface' => '#ffffff',
        ];
        $saved = class_exists( '\Core_Settings_Service', false ) ? \Core_Settings_Service::get( 'theme_colors', [] ) : [];
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        foreach ( $defaults as $key => $fallback ) {
            $raw = (string) ( $saved[ $key ] ?? $fallback );
            $defaults[ $key ] = metis_hex_color_clean( $raw ) ?: $fallback;
        }

        return $defaults;
    }

    private static function readableTextColorForHex( string $hex ): string {
        $hex = ltrim( metis_hex_color_clean( $hex ) ?: '#485bc7', '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        $luminance = ( ( $r * 299 ) + ( $g * 587 ) + ( $b * 114 ) ) / 1000;

        return $luminance > 150 ? '#1a1f2b' : '#ffffff';
    }

    private static function safeHtmlClass( string $value ): string {
        $sanitized = metis_key_clean( $value );
        if ( $sanitized === '' ) {
            return 'default';
        }
        return preg_replace( '/[^a-z0-9_-]/', '-', strtolower( $sanitized ) ) ?? 'default';
    }

    private static function normalizePublicUrl( string $url ): string {
        $url = trim( $url );
        if ( $url === '' ) {
            return '#';
        }
        if ( function_exists( 'metis_runtime_is_safe_url_value' ) && ! metis_runtime_is_safe_url_value( $url ) ) {
            return '#';
        }
        if ( $url === '#' ) {
            return '#';
        }
        if ( preg_match( '#^(https?:)?//#i', $url ) === 1 ) {
            return self::sanitizeAbsoluteUrl( $url );
        }
        if ( preg_match( '#^(mailto|tel):#i', $url ) === 1 ) {
            return $url;
        }

        if ( function_exists( 'metis_home_url' ) ) {
            if ( str_starts_with( $url, '/' ) ) {
                return self::sanitizeAbsoluteUrl( (string) metis_home_url( $url ) );
            }
            return self::sanitizeAbsoluteUrl( (string) metis_home_url( '/' . ltrim( $url, '/' ) ) );
        }

        if ( str_starts_with( $url, '/' ) ) {
            return $url;
        }
        return '/' . ltrim( $url, '/' );
    }

    private static function sanitizeAbsoluteUrl( string $url ): string {
        $url = trim( $url );
        if ( $url === '' ) {
            return $url;
        }

        return (string) preg_replace(
            '#^(https?://[^/]+?)\.(?=/)#i',
            '$1',
            $url
        );
    }

    private static function normalizedPath( string $path ): string {
        $normalized = self::comparablePathFromUrl( $path );
        if ( $normalized === '' ) {
            return '/';
        }
        return str_starts_with( $normalized, '/' ) ? $normalized : '/' . $normalized;
    }

    private static function postPublicPath( Post $post ): string {
        if ( method_exists( PostService::class, 'publicPath' ) ) {
            $path = (string) PostService::publicPath( $post );
            if ( trim( $path ) !== '' ) {
                return self::normalizedPath( $path );
            }
        }
        return '';
    }

    private static function currentRequestComparablePath(): string {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
        if ( $request_uri === '' ) {
            $request_uri = '/';
        }
        return self::comparablePathFromUrl( $request_uri );
    }

    private static function isHomepageComparablePath( string $path ): bool {
        $normalized = trim( self::comparablePathFromUrl( $path ) );
        if ( $normalized === '' || $normalized === '/' ) {
            return true;
        }

        if ( function_exists( 'metis_home_url' ) ) {
            $home_path = self::comparablePathFromUrl( (string) metis_home_url( '/' ) );
            if ( $home_path === '' || $home_path === '/' ) {
                return $normalized === '/';
            }
            return $normalized === $home_path;
        }

        return false;
    }

    private static function menuUrlMatchesCurrentPath( string $menu_url, string $current_path ): bool {
        if ( $menu_url === '' || $menu_url === '#' || $current_path === '' ) {
            return false;
        }
        if ( preg_match( '#^(mailto|tel):#i', $menu_url ) === 1 ) {
            return false;
        }
        $menu_path = self::comparablePathFromUrl( $menu_url );
        if ( $menu_path === '' ) {
            return false;
        }
        return $menu_path === $current_path;
    }

    private static function shellMenuUrlIsNavigable( string $url ): bool {
        $url = trim( $url );
        return $url !== '' && $url !== '#';
    }

    private static function comparablePathFromUrl( string $url ): string {
        $parsed_path = parse_url( $url, PHP_URL_PATH );
        $path = is_string( $parsed_path ) ? $parsed_path : $url;
        $path = trim( $path );
        if ( $path === '' ) {
            $path = '/';
        }
        $path = preg_replace( '#/+#', '/', $path ) ?? $path;
        if ( $path !== '/' ) {
            $path = rtrim( $path, '/' );
        }
        return $path;
    }

    private static function previewLayoutProfileOverride(): string {
        if ( ! isset( metis_request_get()['metis_layout_preview'] ) ) {
            return '';
        }
        if ( ! function_exists( 'metis_security_user_can' ) || ! metis_security_user_can( 'website.edit' ) ) {
            return '';
        }
        $raw_value = metis_request_get()['metis_layout_preview'];
        if ( function_exists( 'metis_runtime_unslash' ) ) {
            $raw_value = metis_runtime_unslash( $raw_value );
        }
        $raw = metis_key_clean( (string) $raw_value );
        if ( $raw === '' ) {
            return '';
        }
        return LayoutProfileService::sanitizeWBProfile( $raw );
    }

    private static function emitPreviewNoCacheHeaders(): void {
        if ( headers_sent() ) {
            return;
        }
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
    }

    private static function isTemplatePreviewMode(): bool {
        if ( ! isset( metis_request_get()['metis_preview'] ) ) {
            return false;
        }
        if ( ! function_exists( 'metis_security_user_can' ) || ! metis_security_user_can( 'website.edit' ) ) {
            return false;
        }
        $raw = metis_request_get()['metis_preview'];
        if ( function_exists( 'metis_runtime_unslash' ) ) {
            $raw = metis_runtime_unslash( $raw );
        }
        $flag = strtolower( trim( (string) $raw ) );
        return in_array( $flag, [ '1', 'true', 'yes' ], true );
    }

    private static function previewTemplateSlugFromRequest(): string {
        if ( ! isset( metis_request_get()['metis_layout_preview'] ) ) {
            return TemplateService::getActiveTemplateSlug();
        }
        $raw = metis_request_get()['metis_layout_preview'];
        if ( function_exists( 'metis_runtime_unslash' ) ) {
            $raw = metis_runtime_unslash( $raw );
        }
        $slug = metis_key_clean( (string) $raw );
        if ( $slug === '' ) {
            return TemplateService::getActiveTemplateSlug();
        }
        return $slug;
    }

    private static function renderTemplatePreviewDocument(): string {
        $slug = self::previewTemplateSlugFromRequest();
        $structure = TemplateService::resolveStructureForSlug( $slug );
        $preview = self::resolveTemplatePreviewPayload();
        $sections = is_array( $preview['sections'] ?? null ) ? $preview['sections'] : [];

        return self::renderWithPipeline( [
            'title' => (string) ( $preview['title'] ?? 'Template Preview' ),
            'description' => (string) ( $preview['description'] ?? '' ),
            'template_structure' => $structure,
            'sections' => $sections,
            'hero' => is_array( $preview['hero'] ?? null ) ? $preview['hero'] : [],
            'content' => (string) ( $preview['content'] ?? '' ),
            'layout_settings' => is_array( $preview['layout_settings'] ?? null ) ? $preview['layout_settings'] : [],
            'layout_data' => [
                'page_data' => [
                    'title' => (string) ( $preview['title'] ?? '' ),
                    'excerpt' => (string) ( $preview['description'] ?? '' ),
                    'publish_date' => (string) ( $preview['published_date'] ?? '' ),
                    'featured_image_url' => (string) ( $preview['featured_image_url'] ?? '' ),
                    'featured_image_caption' => (string) ( $preview['featured_image_caption'] ?? '' ),
                    'page_type' => (string) ( $preview['content_type'] ?? '' ),
                ],
                'layout_settings' => is_array( $preview['layout_settings'] ?? null ) ? $preview['layout_settings'] : [],
            ],
            'context' => [
                'path' => (string) ( $preview['path'] ?? '/' ),
                'slug' => (string) ( $preview['slug'] ?? 'template-preview' ),
                'content_type' => (string) ( $preview['content_type'] ?? 'homepage' ),
                'content_id' => (int) ( $preview['content_id'] ?? 0 ),
                'is_homepage' => ! empty( $preview['is_homepage'] ),
                'preview_layout_kind' => (string) ( $preview['layout_kind'] ?? 'homepage' ),
                'template_preview_mode' => true,
                'template_preview_placeholder' => ! empty( $preview['placeholder'] ),
            ],
        ] );
    }

    /**
     * @return array<string,mixed>
     */
    private static function resolveTemplatePreviewPayload(): array {
        $source = self::previewContentSourceFromRequest();
        $source_kind = (string) ( $source['source'] ?? 'auto' );
        $source_id = (int) ( $source['id'] ?? 0 );

        if ( $source_kind === 'demo' ) {
            return self::previewPayloadForDemo();
        }

        if ( $source_kind === 'homepage' ) {
            $homepage = self::resolveHomepagePage();
            if ( $homepage !== null && $homepage->status === 'published' ) {
                return self::previewPayloadForPage( $homepage, true );
            }
        }

        if ( $source_kind === 'page' && $source_id > 0 ) {
            $page = PageService::getById( $source_id );
            if ( $page !== null && $page->status === 'published' ) {
                return self::previewPayloadForPage( $page, false );
            }
        }

        if ( $source_kind === 'post' && $source_id > 0 ) {
            $post = PostService::getById( $source_id );
            if ( $post !== null && $post->status === 'published' ) {
                return self::previewPayloadForPost( $post );
            }
        }

        if ( $source_kind === 'page' ) {
            $pages = PageService::getPublished( [ 'limit' => 1 ] );
            if ( is_array( $pages ) && isset( $pages[0] ) && $pages[0] instanceof Page ) {
                return self::previewPayloadForPage( $pages[0], false );
            }
        }

        if ( $source_kind === 'post' ) {
            $posts = PostService::getPublished( [ 'limit' => 1 ] );
            if ( is_array( $posts ) && isset( $posts[0] ) && $posts[0] instanceof Post ) {
                return self::previewPayloadForPost( $posts[0] );
            }
        }

        $homepage = self::resolveHomepagePage();
        if ( $homepage !== null && $homepage->status === 'published' ) {
            return self::previewPayloadForPage( $homepage, true );
        }

        $pages = PageService::getPublished( [ 'limit' => 1 ] );
        if ( is_array( $pages ) && isset( $pages[0] ) && $pages[0] instanceof Page ) {
            return self::previewPayloadForPage( $pages[0], false );
        }

        $posts = PostService::getPublished( [ 'limit' => 1 ] );
        if ( is_array( $posts ) && isset( $posts[0] ) && $posts[0] instanceof Post ) {
            return self::previewPayloadForPost( $posts[0] );
        }

        return [
            'title' => 'Template Preview',
            'description' => '',
            'sections' => [
                [
                    'type' => 'text',
                    'header' => 'Template Preview',
                    'subtext' => 'Publish a page or post to preview templates with live content.',
                    'settings' => [ 'background' => 'light', 'padding' => 'medium' ],
                    'content' => [
                        'body' => '<div class="metis-template-preview-block"></div><div class="metis-template-preview-block is-wide"></div>',
                    ],
                ],
            ],
            'layout_settings' => [],
            'path' => '/',
            'slug' => 'template-preview',
            'content_type' => 'homepage',
            'content_id' => 0,
            'is_homepage' => true,
            'placeholder' => true,
        ];
    }

    /**
     * @return array{source:string,id:int}
     */
    private static function previewContentSourceFromRequest(): array {
        if ( ! isset( metis_request_get()['metis_preview_source'] ) ) {
            return [ 'source' => 'auto', 'id' => 0 ];
        }
        $raw = metis_request_get()['metis_preview_source'];
        if ( function_exists( 'metis_runtime_unslash' ) ) {
            $raw = metis_runtime_unslash( $raw );
        }
        $source = metis_key_clean( (string) $raw );
        if ( ! in_array( $source, [ 'auto', 'homepage', 'page', 'post', 'demo' ], true ) ) {
            $source = 'auto';
        }

        $id = 0;
        if ( isset( metis_request_get()['metis_preview_id'] ) ) {
            $raw_id = metis_request_get()['metis_preview_id'];
            if ( function_exists( 'metis_runtime_unslash' ) ) {
                $raw_id = metis_runtime_unslash( $raw_id );
            }
            $id = max( 0, (int) $raw_id );
        }

        return [ 'source' => $source, 'id' => $id ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function previewPayloadForDemo(): array {
        $demo_image = self::publicSiteAssetUrl( 'assets/Images/demo.png' );
        $hero = [
            'enabled' => true,
            'style' => 'split',
            'headline' => 'Structured Homepage Hero',
            'subtext' => 'Template preview uses deterministic hero content for homepage comparison.',
            'primary_cta_label' => 'Explore Demo',
            'primary_cta_link' => '/demo/start',
            'media_url' => $demo_image,
        ];
        $sections = [
            [
                'type' => 'text',
                'header' => 'Demo Homepage Heading',
                'subtext' => 'Structured demo content for template previews.',
                'settings' => [ 'background' => 'light', 'padding' => 'large' ],
                'content' => [
                    'body' => '<figure class="metis-template-preview-demo-media"><img src="' . metis_escape_attr( $demo_image ) . '" alt="Template demo image"></figure><p>This preview uses deterministic demo sections so every template can be compared using the same content.</p>',
                ],
            ],
            [
                'type' => 'feature_grid',
                'header' => 'Feature Grid',
                'subtext' => null,
                'settings' => [ 'background' => 'light', 'padding' => 'medium' ],
                'content' => [
                    'columns' => 3,
                    'items' => [
                        [ 'icon' => 'star', 'title' => 'Feature One', 'text' => 'Primary value proposition.', 'cta' => [ 'label' => 'Learn More', 'url' => '/demo/feature-one' ] ],
                        [ 'icon' => 'bolt', 'title' => 'Feature Two', 'text' => 'Secondary supporting detail.', 'cta' => [ 'label' => 'Read More', 'url' => '/demo/feature-two' ] ],
                        [ 'icon' => 'check', 'title' => 'Feature Three', 'text' => 'Consistent rendering across templates.', 'cta' => [ 'label' => 'See Details', 'url' => '/demo/feature-three' ] ],
                    ],
                ],
            ],
            [
                'type' => 'cta',
                'header' => null,
                'subtext' => null,
                'settings' => [ 'background' => 'accent', 'padding' => 'medium' ],
                'content' => [
                    'layout' => 'single',
                    'items' => [
                        [
                            'title' => 'Call to Action',
                            'text' => 'This is static demo content used only for template preview mode.',
                            'button' => [ 'label' => 'Get Started', 'url' => '/demo/start' ],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'title' => 'Template Demo',
            'description' => 'Deterministic demo content',
            'sections' => $sections,
            'hero' => $hero,
            'layout_settings' => [],
            'path' => '/',
            'slug' => 'template-preview-demo',
            'content_type' => 'page',
            'content_id' => 0,
            'is_homepage' => true,
            'layout_kind' => 'homepage',
            'placeholder' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function previewPayloadForPage( Page $page, bool $is_homepage ): array {
        $seo_meta = $page->getSeoMeta();
        $layout_raw = $page->published_layout_json !== null
            ? $page->published_layout_json
            : ( $page->draft_layout_json ?? $page->layout_json );
        $layout_settings = self::layoutSettingsFromRaw( $layout_raw );
        $sections = StructuredWebsiteBuilderService::sectionsFromLayout( (string) $layout_raw, false );
        $hero = StructuredWebsiteBuilderService::heroFromLayout( (string) $layout_raw, $is_homepage );

        return [
            'title' => (string) ( $seo_meta['title'] ?? $page->title ?? 'Template Preview' ),
            'description' => (string) ( $seo_meta['description'] ?? '' ),
            'sections' => $sections,
            'hero' => $hero,
            'layout_settings' => $layout_settings,
            'path' => $is_homepage
                ? '/'
                : ( ( method_exists( PageService::class, 'publishedPathForPage' ) ? (string) PageService::publishedPathForPage( $page ) : '' ) ?: ( '/' . ltrim( (string) $page->slug, '/' ) ) ),
            'slug' => (string) ( $page->slug ?? 'template-preview-page' ),
            'content_type' => 'page',
            'content_id' => (int) ( $page->id ?? 0 ),
            'is_homepage' => $is_homepage,
            'layout_kind' => $is_homepage ? 'homepage' : 'page',
            'placeholder' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function previewPayloadForPost( Post $post ): array {
        $seo_meta = $post->getSeoMeta();
        $layout_raw = $post->published_content_json !== null
            ? $post->published_content_json
            : ( $post->draft_content_json ?? $post->content_json );
        $layout_settings = self::layoutSettingsFromRaw( $layout_raw );
        $sections = StructuredWebsiteBuilderService::sectionsFromLayout( (string) $layout_raw, true );
        $hero = StructuredWebsiteBuilderService::heroFromLayout( (string) $layout_raw, false );

        return [
            'title' => (string) ( $seo_meta['title'] ?? $post->title ?? 'Template Preview' ),
            'description' => (string) ( $seo_meta['description'] ?? ( $post->excerpt ?? '' ) ),
            'sections' => $sections,
            'hero' => $hero,
            'layout_settings' => $layout_settings,
            'path' => self::postPublicPath( $post ),
            'slug' => (string) ( $post->slug ?? 'template-preview-post' ),
            'content_type' => 'post',
            'content_id' => (int) ( $post->id ?? 0 ),
            'featured_image_url' => self::mediaUrlById( (int) ( $post->featured_image_id ?? 0 ) ),
            'featured_image_caption' => (string) ( $post->featured_image_caption ?? '' ),
            'content_format' => in_array( (string) ( $post->content_format ?? 'standard' ), [ 'standard', 'transcript' ], true )
                ? (string) $post->content_format
                : 'standard',
            'is_homepage' => false,
            'layout_kind' => 'post',
            'placeholder' => false,
        ];
    }

    private static function templatePreviewIsolationCss(): string {
        return '.metis-template-preview-content-surface{background:#fff;border:1px solid #dbe4f2;border-radius:12px;padding:24px;max-width:920px;margin:24px auto;}'
            . '.metis-template-preview-content-surface h1{margin:0 0 8px;font-size:26px;line-height:1.2;color:#1f2d44;}'
            . '.metis-template-preview-content-surface p{margin:0 0 16px;color:#53657f;}'
            . '.metis-template-preview-block{height:42px;border-radius:8px;background:#dfe8f8;margin:10px 0;}'
            . '.metis-template-preview-block.is-wide{height:72px;background:#cedcf5;}'
            . '.metis-template-preview-hero{border:1px solid #cfdbef;border-radius:16px;min-height:170px;padding:18px;margin:18px 0;background: var(--metis-surface, #fff);}'
            . '.metis-template-preview-hero-kicker{width:120px;height:12px;border-radius:999px;background:#d6e2f8;margin:0 0 12px;}'
            . '.metis-template-preview-hero-title{width:min(620px,88%);height:34px;border-radius:10px;background:#c5d6f4;margin:0 0 12px;}'
            . '.metis-template-preview-hero-copy{width:min(520px,82%);height:18px;border-radius:10px;background:#d9e4f7;}'
            . '.metis-template-preview-sidebar{border:1px solid #d6dfef;border-radius:14px;padding:12px;background:#f8fbff;}'
            . '.metis-template-preview-side-item{height:14px;border-radius:999px;background:#d9e4f7;margin:8px 0;}'
            . '.metis-template-preview-demo-media{margin:0 0 14px;}'
            . '.metis-template-preview-demo-media img{display:block;width:100%;max-height:340px;object-fit:cover;border-radius:12px;border:1px solid #d7e2f2;}';
    }

    private static function publicSiteAssetUrl( string $asset_path ): string {
        $normalized = ltrim( trim( $asset_path ), '/' );
        if ( $normalized === '' ) {
            return '/';
        }

        $normalized = preg_replace( '#^assets/images/#i', 'assets/Images/', $normalized ) ?? $normalized;

        $script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $script_base = trim( str_replace( '\\', '/', dirname( $script_name ) ) );
        $script_base = ( $script_base === '' || $script_base === '.' || $script_base === '/' ) ? '' : ( '/' . trim( $script_base, '/' ) );
        if ( $script_base !== '' && preg_match( '#^(.*)/admin(?:/.*)?$#i', $script_base, $matches ) === 1 ) {
            $script_base = rtrim( (string) ( $matches[1] ?? '' ), '/' );
        }

        $runtime_base = '';
        if ( function_exists( 'metis_runtime_base_path' ) ) {
            $runtime_base = trim( (string) metis_runtime_base_path(), '/' );
            $runtime_base = $runtime_base === '' ? '' : ( '/' . $runtime_base );
        }

        $home_base = '';
        if ( function_exists( 'metis_home_url' ) ) {
            $home_url = (string) metis_home_url( '/' );
            $home_path = trim( (string) ( parse_url( $home_url, PHP_URL_PATH ) ?? '' ) );
            if ( $home_path !== '' && $home_path !== '/' ) {
                $home_base = '/' . trim( $home_path, '/' );
            }
        }

        $base_path = '';
        foreach ( [ $runtime_base, $home_base, $script_base ] as $candidate ) {
            if ( $candidate !== '' ) {
                $base_path = rtrim( $candidate, '/' );
                break;
            }
        }

        $path = ( $base_path !== '' ? $base_path : '' ) . '/' . $normalized;

        if ( function_exists( 'metis_home_url' ) ) {
            $home_url = (string) metis_home_url( '/' );
            $parts = parse_url( $home_url );
            if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
                $scheme = ! empty( $parts['scheme'] ) ? (string) $parts['scheme'] . '://' : '';
                $host = (string) $parts['host'];
                $port = isset( $parts['port'] ) ? ':' . (string) $parts['port'] : '';
                return $scheme . $host . $port . $path;
            }
        }

        return $path;
    }

    /**
     * @param array<string,mixed> $template_structure
     */
    private static function loadTemplateStructureCss( array $template_structure ): string {
        $meta = is_array( $template_structure['editor_meta'] ?? null ) ? $template_structure['editor_meta'] : [];
        $layout_path = isset( $meta['template_layout_file'] ) ? (string) $meta['template_layout_file'] : '';
        if ( $layout_path === '' || ! is_file( $layout_path ) ) {
            return '';
        }

        $dir = dirname( $layout_path );
        $css_path = $dir . '/structure.css';
        if ( ! is_file( $css_path ) ) {
            return '';
        }

        $css = file_get_contents( $css_path );
        if ( ! is_string( $css ) ) {
            return '';
        }
        return self::sanitizeCustomCss( $css );
    }

    private static function renderTemplateRegion( array $template_structure, string $region ): string {
        $regions = is_array( $template_structure['regions'] ?? null ) ? $template_structure['regions'] : [];
        $config = is_array( $regions[ $region ] ?? null ) ? $regions[ $region ] : [];
        if ( empty( $config['enabled'] ) ) {
            return '';
        }

        $source = metis_key_clean( (string) ( $config['source'] ?? 'none' ) );
        if ( $source === 'html' ) {
            $html = trim( (string) ( $config['html'] ?? '' ) );
            if ( $html !== '' ) {
                return function_exists( 'metis_runtime_kses_post' )
                    ? (string) metis_runtime_kses_post( $html )
                    : strip_tags( $html, '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><span><div>' );
            }
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $sections
     * @param array<string,mixed> $context
     */
    private static function renderStructuredSections( array $sections, array $context = [] ): string {
        if ( $sections === [] ) {
            return '';
        }
        $html = '';
        foreach ( $sections as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }
            $rendered = self::renderStructuredSection( $section, $context );
            if ( $rendered === '' ) {
                continue;
            }
            $html .= $rendered;
        }
        return $html;
    }

    /**
     * @param array<string,mixed> $section
     * @param array<string,mixed> $context
     */
    private static function renderStructuredSection( array $section, array $context = [] ): string {
        $type = metis_key_clean( (string) ( $section['type'] ?? '' ) );
        if ( ! in_array( $type, self::STRUCTURED_SECTION_TYPES, true ) ) {
            return '';
        }

        $content = is_array( $section['content'] ?? null ) ? $section['content'] : [];
        $body = self::renderStructuredSectionBody( $type, $content, $context );
        if ( trim( $body ) === '' ) {
            return '';
        }

        $settings = is_array( $section['settings'] ?? null ) ? $section['settings'] : [];
        $background = metis_key_clean( (string) ( $settings['background'] ?? 'default' ) );
        if ( ! in_array( $background, [ 'default', 'surface', 'muted', 'primary_tint', 'accent_tint' ], true ) ) {
            $background = 'default';
        }
        $classes = [ 'metis-structured-section' ];
        if ( $type === 'posts_list' ) {
            $classes[] = 'metis-structured-section--posts-list';
        }
        if ( $type === 'heading' && metis_key_clean( (string) ( $content['variant'] ?? 'default' ) ) === 'section_header' ) {
            $classes[] = 'metis-structured-section--heading-band';
        }
        if ( $background !== 'default' ) {
            $classes[] = 'is-bg-' . str_replace( '_', '-', $background );
        }

        $header = trim( self::repairMojibakeText( (string) ( $section['header'] ?? '' ) ) );
        $subtext = trim( self::repairMojibakeText( (string) ( $section['subtext'] ?? '' ) ) );
        $header_html = '';
        if ( $header !== '' || $subtext !== '' ) {
            $header_html .= '<header class="metis-structured-section__head">';
            if ( $header !== '' ) {
                $header_html .= '<h1 class="metis-structured-section__title">' . metis_escape_html( $header ) . '</h1>';
            }
            if ( $subtext !== '' ) {
                $header_html .= '<p class="metis-structured-section__subtext">' . metis_escape_html( $subtext ) . '</p>';
            }
            $header_html .= '</header>';
        }

        return '<section class="' . metis_escape_attr( implode( ' ', $classes ) ) . '">'
            . $header_html
            . '<div class="metis-structured-section__inner">'
            . '<div class="metis-structured-section__content">' . $body . '</div>'
            . '</div></section>';
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $context
     */
    private static function renderStructuredSectionBody( string $type, array $content, array $context = [] ): string {
        if ( $type === 'heading' ) {
            return self::renderStructuredHeadingSection( $content );
        }
        if ( $type === 'text' ) {
            return self::renderStructuredTextSection( $content );
        }
        if ( $type === 'image' ) {
            return self::renderStructuredImageSection( $content );
        }
        if ( $type === 'button' ) {
            return self::renderStructuredButtonSection( $content );
        }
        if ( $type === 'hero' ) {
            return self::renderStructuredHeroBlockSection( $content );
        }
        if ( $type === 'html' ) {
            return self::renderStructuredHtmlSection( $content );
        }
        if ( $type === 'transcript' ) {
            return self::renderStructuredTranscriptSection( $content );
        }
        if ( $type === 'columns' ) {
            return self::renderStructuredColumnsSection( $content, $context );
        }
        if ( $type === 'feature_grid' || $type === 'card_grid' ) {
            return self::renderStructuredFeatureGridSection( $content );
        }
        if ( $type === 'cta' ) {
            return self::renderStructuredCtaSection( $content );
        }
        if ( $type === 'events' ) {
            return self::renderStructuredEventsSection( $content, $context );
        }
        if ( $type === 'form' ) {
            return self::renderStructuredFormSection( $content, $context );
        }
        if ( $type === 'donation_form' ) {
            return self::renderStructuredDonationFormSection( $content, $context );
        }
        if ( $type === 'donation_progress' ) {
            return self::renderStructuredDonationProgressSection( $content, $context );
        }
        if ( $type === 'campaign_summary' ) {
            return self::renderStructuredCampaignSummarySection( $content, $context );
        }
        if ( $type === 'divider' ) {
            return self::renderStructuredDividerSection( $content, $context );
        }
        if ( $type === 'spacer' ) {
            return self::renderStructuredSpacerSection( $content );
        }
        if ( $type === 'posts_list' ) {
            return self::renderStructuredPostsListSection( $content, $context );
        }
        return '';
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredHeadingSection( array $content ): string {
        $text = trim( self::repairMojibakeText( (string) ( $content['text'] ?? '' ) ) );
        if ( $text === '' ) {
            return '';
        }
        $level = metis_key_clean( (string) ( $content['level'] ?? 'h2' ) );
        if ( ! in_array( $level, [ 'h1', 'h2', 'h3', 'h4' ], true ) ) {
            $level = 'h2';
        }
        $align = metis_key_clean( (string) ( $content['align'] ?? 'left' ) );
        if ( ! in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            $align = 'left';
        }
        $vertical_align = metis_key_clean( (string) ( $content['vertical_align'] ?? 'top' ) );
        if ( ! in_array( $vertical_align, [ 'top', 'middle', 'bottom' ], true ) ) {
            $vertical_align = 'top';
        }
        $variant = metis_key_clean( (string) ( $content['variant'] ?? 'default' ) );
        if ( $variant === 'section_header' ) {
            $level = 'h1';
            $align = 'center';
            $vertical_align = 'middle';
        } else {
            $variant = 'default';
        }

        return '<div class="metis-structured-heading-wrap is-valign-' . metis_escape_attr( $vertical_align ) . ( $variant === 'section_header' ? ' is-section-header' : '' ) . '"><' . $level . ' class="metis-structured-heading metis-structured-heading--' . metis_escape_attr( $level ) . ' is-align-' . metis_escape_attr( $align ) . '">'
            . metis_escape_html( $text )
            . '</' . $level . '></div>';
    }


    private static function sanitizeRichTextFragment( string $html ): string {
        $raw = trim( $html );
        if ( $raw === '' ) {
            return '';
        }
        if ( ! class_exists( \DOMDocument::class ) ) {
            $safe = function_exists( 'metis_runtime_kses_post' )
                ? (string) metis_runtime_kses_post( $raw )
                : strip_tags( $raw, '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><span><div><figure><figcaption><img><table><thead><tbody><tr><th><td><hr><pre><code>' );
            return self::repairPublicHtmlText( $safe );
        }

        $previous = libxml_use_internal_errors( true );
        $document = new \DOMDocument( '1.0', 'UTF-8' );
        $document->loadHTML( '<?xml encoding="UTF-8"><div id="metis-rich-root">' . $raw . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        $root = $document->getElementById( 'metis-rich-root' );
        if ( ! $root instanceof \DOMElement ) {
            return '';
        }
        self::sanitizeRichTextNode( $root );

        $safe = '';
        foreach ( iterator_to_array( $root->childNodes ) as $child ) {
            $safe .= $document->saveHTML( $child ) ?: '';
        }
        return self::repairPublicHtmlText( $safe );
    }

    private static function sanitizeRichTextNode( \DOMNode $node ): void {
        foreach ( iterator_to_array( $node->childNodes ) as $child ) {
            if ( $child->nodeType === XML_COMMENT_NODE ) {
                $node->removeChild( $child );
                continue;
            }
            if ( $child instanceof \DOMElement ) {
                $tag = strtolower( $child->tagName );
                if ( in_array( $tag, [ 'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math' ], true ) ) {
                    $node->removeChild( $child );
                    continue;
                }
                self::sanitizeRichTextNode( $child );
                if ( ! in_array( $tag, self::allowedRichTextTags(), true ) ) {
                    while ( $child->firstChild ) {
                        $node->insertBefore( $child->firstChild, $child );
                    }
                    $node->removeChild( $child );
                    continue;
                }
                self::sanitizeRichTextAttributes( $child, $tag );
            }
        }
    }

    /** @return array<int,string> */
    private static function allowedRichTextTags(): array {
        return [ 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'span', 'div', 'figure', 'figcaption', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr', 'pre', 'code' ];
    }

    private static function sanitizeRichTextAttributes( \DOMElement $element, string $tag ): void {
        foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
            $name = strtolower( $attribute->name );
            $value = trim( (string) $attribute->value );
            $keep = false;

            if ( $name === 'class' ) {
                $value = self::sanitizeRichTextClassList( $value );
                $keep = $value !== '';
            } elseif ( $name === 'style' ) {
                $value = self::sanitizeRichTextStyle( $value );
                $keep = $value !== '';
            } elseif ( $tag === 'a' && $name === 'href' ) {
                $keep = self::isSafeRichTextUrl( $value );
            } elseif ( $tag === 'a' && $name === 'target' ) {
                $keep = in_array( $value, [ '_blank', '_self' ], true );
            } elseif ( $tag === 'a' && $name === 'rel' ) {
                $value = preg_replace( '/[^a-z\s_-]/i', '', $value ) ?: '';
                $keep = $value !== '';
            } elseif ( $tag === 'img' && $name === 'src' ) {
                $keep = self::isSafeRichTextUrl( $value );
            } elseif ( in_array( $name, [ 'alt', 'title' ], true ) && in_array( $tag, [ 'a', 'img', 'figure', 'figcaption' ], true ) ) {
                $value = trim( strip_tags( $value ) );
                $keep = $value !== '';
            } elseif ( in_array( $name, [ 'width', 'height' ], true ) && $tag === 'img' ) {
                $value = preg_replace( '/[^0-9]/', '', $value ) ?: '';
                $keep = $value !== '' && (int) $value > 0 && (int) $value <= 4000;
            } elseif ( in_array( $name, [ 'colspan', 'rowspan' ], true ) && in_array( $tag, [ 'td', 'th' ], true ) ) {
                $value = preg_replace( '/[^0-9]/', '', $value ) ?: '';
                $keep = $value !== '' && (int) $value > 0 && (int) $value <= 20;
            } elseif ( $name === 'scope' && $tag === 'th' ) {
                $keep = in_array( $value, [ 'col', 'row', 'colgroup', 'rowgroup' ], true );
            }

            if ( $keep ) {
                $element->setAttribute( $name, $value );
            } else {
                $element->removeAttribute( $attribute->name );
            }
        }
    }

    private static function sanitizeRichTextClassList( string $classes ): string {
        $safe = [];
        foreach ( preg_split( '/\s+/', $classes ) ?: [] as $class ) {
            $class = trim( $class );
            if ( $class === '' ) {
                continue;
            }
            if ( preg_match( '/^metis-text-(size|color|weight|align)-[a-z0-9_-]+$/i', $class ) === 1 || preg_match( '/^metis-inline-(image|divider)$/i', $class ) === 1 || in_array( $class, [ 'is-small', 'is-medium', 'is-large', 'is-full' ], true ) ) {
                $safe[] = $class;
            }
        }
        return implode( ' ', array_values( array_unique( $safe ) ) );
    }

    private static function sanitizeRichTextStyle( string $style ): string {
        $safe = [];
        foreach ( explode( ';', $style ) as $rule ) {
            $parts = explode( ':', $rule, 2 );
            if ( count( $parts ) !== 2 ) {
                continue;
            }
            $property = strtolower( trim( $parts[0] ) );
            $value = trim( $parts[1] );
            $value = trim( preg_replace( '/\s*!important\s*$/i', '', $value ) ?? $value );
            if ( $property === 'color' && self::isSafeRichTextColor( $value ) ) {
                $safe[] = 'color: ' . strtolower( preg_replace( '/\s+/', ' ', $value ) ) . ' !important';
            } elseif ( $property === 'font-weight' && preg_match( '/^(normal|bold|[1-9]00)$/i', $value ) === 1 ) {
                $safe[] = 'font-weight: ' . strtolower( $value ) . ' !important';
            } elseif ( $property === 'font-style' && in_array( strtolower( $value ), [ 'normal', 'italic' ], true ) ) {
                $safe[] = 'font-style: ' . strtolower( $value ) . ' !important';
            } elseif ( $property === 'text-decoration' && in_array( strtolower( $value ), [ 'none', 'underline', 'line-through' ], true ) ) {
                $safe[] = 'text-decoration: ' . strtolower( $value ) . ' !important';
            } elseif ( $property === 'text-align' && in_array( strtolower( $value ), [ 'left', 'center', 'right' ], true ) ) {
                $safe[] = 'text-align: ' . strtolower( $value );
            }
        }
        return implode( '; ', $safe );
    }

    private static function isSafeRichTextColor( string $value ): bool {
        $value = trim( $value );
        if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) === 1 ) {
            return true;
        }
        if ( preg_match( '/^rgba?\(\s*(?:\d{1,3}\s*,\s*){2}\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $value ) === 1 ) {
            preg_match_all( '/\d+(?:\.\d+)?/', $value, $matches );
            $channels = array_slice( $matches[0] ?? [], 0, 3 );
            foreach ( $channels as $channel ) {
                if ( (int) $channel > 255 ) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    private static function isSafeRichTextUrl( string $value ): bool {
        if ( $value === '' ) {
            return false;
        }
        return preg_match( '~^(https?:|mailto:|tel:|/|#)~i', $value ) === 1;
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredTextSection( array $content ): string {
        $body = trim( (string) ( $content['body'] ?? '' ) );
        if ( $body === '' ) {
            return '';
        }
        $safe = self::sanitizeRichTextFragment( $body );
        if ( trim( $safe ) === '' ) {
            return '';
        }
        $safe = self::repairPublicHtmlText( $safe );
        $safe = self::transformTranscriptTables( $safe );
        $safe = self::replaceEmojiShortcodes( $safe );
        return '<div class="metis-structured-text">' . $safe . '</div>';
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredImageSection( array $content ): string {
        $src = trim( (string) ( $content['src'] ?? '' ) );
        if ( $src === '' ) {
            return '';
        }
        $alt = self::repairMojibakeText( (string) ( $content['alt'] ?? '' ) );
        $caption = trim( self::repairMojibakeText( (string) ( $content['caption'] ?? '' ) ) );

        $html = '<figure class="metis-structured-image">';
        $html .= '<img src="' . metis_escape_attr( self::normalizePublicUrl( $src ) ) . '" alt="' . metis_escape_attr( $alt ) . '" loading="lazy"' . self::renderImageDimensionAttributes( $content ) . '>';
        if ( $caption !== '' ) {
            $html .= '<figcaption>' . metis_escape_html( $caption ) . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderImageDimensionAttributes( array $content ): string {
        $width = self::imageDimensionValue( $content['width'] ?? '' );
        $height = self::imageDimensionValue( $content['height'] ?? '' );
        $attrs = '';
        $style = [];
        if ( $width !== '' ) {
            $attrs .= ' width="' . metis_escape_attr( $width ) . '"';
            $style[] = 'width:' . $width . 'px';
        }
        if ( $height !== '' ) {
            $attrs .= ' height="' . metis_escape_attr( $height ) . '"';
            $style[] = 'height:' . $height . 'px';
        }
        if ( $style !== [] ) {
            $attrs .= ' style="' . metis_escape_attr( implode( ';', $style ) ) . '"';
        }
        return $attrs;
    }

    private static function imageDimensionValue( mixed $value ): string {
        $raw = is_scalar( $value ) ? trim( (string) $value ) : '';
        if ( $raw === '' ) {
            return '';
        }
        $number = (int) preg_replace( '/[^0-9]/', '', $raw );
        if ( $number < 1 || $number > 4000 ) {
            return '';
        }
        return (string) $number;
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredButtonSection( array $content ): string {
        $label = trim( self::repairMojibakeText( (string) ( $content['label'] ?? '' ) ) );
        if ( $label === '' ) {
            return '';
        }
        $url = trim( (string) ( $content['url'] ?? '#' ) );
        $align = metis_key_clean( (string) ( $content['align'] ?? 'left' ) );
        if ( ! in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            $align = 'left';
        }

        return '<p class="metis-structured-button-row is-' . metis_escape_attr( $align ) . '">'
            . '<a class="metis-structured-button" href="' . metis_escape_attr( self::normalizePublicUrl( $url !== '' ? $url : '#' ) ) . '">'
            . metis_escape_html( $label )
            . '</a></p>';
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredHeroBlockSection( array $content ): string {
        $title = trim( self::repairMojibakeText( (string) ( $content['title'] ?? '' ) ) );
        $subtitle = trim( self::repairMojibakeText( (string) ( $content['subtitle'] ?? '' ) ) );
        $cta_label = trim( self::repairMojibakeText( (string) ( $content['cta_label'] ?? '' ) ) );
        $cta_url = trim( (string) ( $content['cta_url'] ?? '#' ) );
        $image_src = trim( (string) ( $content['image_src'] ?? '' ) );
        if ( $title === '' && $subtitle === '' && $cta_label === '' && $image_src === '' ) {
            return '';
        }

        $html = '<div class="metis-structured-hero-block">';
        $html .= '<div class="metis-structured-hero-block__copy">';
        if ( $title !== '' ) {
            $html .= '<h1>' . metis_escape_html( $title ) . '</h1>';
        }
        if ( $subtitle !== '' ) {
            $html .= '<p>' . metis_escape_html( $subtitle ) . '</p>';
        }
        if ( $cta_label !== '' ) {
            $html .= '<a class="metis-structured-button" href="' . metis_escape_attr( self::normalizePublicUrl( $cta_url !== '' ? $cta_url : '#' ) ) . '">'
                . metis_escape_html( $cta_label )
                . '</a>';
        }
        $html .= '</div>';
        if ( $image_src !== '' ) {
            $html .= '<figure class="metis-structured-hero-block__media"><img src="' . metis_escape_attr( self::normalizePublicUrl( $image_src ) ) . '" alt="" loading="lazy"></figure>';
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredHtmlSection( array $content ): string {
        $html = trim( (string) ( $content['html'] ?? '' ) );
        if ( $html === '' ) {
            return '';
        }
        $safe = function_exists( 'metis_runtime_kses_post' )
            ? (string) metis_runtime_kses_post( $html )
            : strip_tags( $html, '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><span><div><figure><img><table><thead><tbody><tr><th><td>' );
        $safe = self::repairPublicHtmlText( $safe );
        if ( trim( $safe ) === '' ) {
            return '';
        }
        return '<div class="metis-structured-html">' . $safe . '</div>';
    }

    private static function repairPublicHtmlText( string $html ): string {
        if ( trim( $html ) === '' ) {
            return '';
        }

        $parts = preg_split( '/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) {
            return self::repairMojibakeText( $html );
        }

        foreach ( $parts as $index => $part ) {
            if ( $part === '' || str_starts_with( $part, '<' ) ) {
                continue;
            }
            $parts[ $index ] = self::repairPublicTextNode( $part );
        }

        return implode( '', $parts );
    }

    private static function repairPublicTextNode( string $text ): string {
        if ( $text === '' ) {
            return '';
        }

        $text = str_ireplace(
            [
                '&Acirc;&nbsp;',
                '&Acirc;&#160;',
                '&Acirc;&#xA0;',
                '&Acirc;&#xa0;',
                'Â&nbsp;',
                'Â&#160;',
                'Â&#xA0;',
                'Â&#xa0;',
                '&nbsp;',
                '&#160;',
                '&#xA0;',
                '&#xa0;',
            ],
            ' ',
            $text
        );
        $text = self::repairMojibakeText( $text );

        return preg_replace( '/[ \t]{2,}/u', ' ', $text ) ?? $text;
    }

    private static function replaceEmojiShortcodes( string $html ): string {
        if ( trim( $html ) === '' ) {
            return '';
        }

        $map = [
            ':wave:' => '👋',
            ':sparkles:' => '✨',
            ':heart:' => '❤️',
            ':star:' => '⭐',
            ':fire:' => '🔥',
            ':check:' => '✔️',
            ':point_right:' => '👉',
            ':point_left:' => '👈',
            ':ear:' => '👂',
            ':microphone:' => '🎤',
            ':calendar:' => '📅',
            ':tada:' => '🎉',
            ':smile:' => '😊',
        ];

        return str_ireplace( array_keys( $map ), array_values( $map ), $html );
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredTranscriptSection( array $content ): string {
        $rows = is_array( $content['rows'] ?? null ) ? $content['rows'] : [];
        if ( $rows === [] ) {
            return '';
        }

        $speaker_sides = self::transcriptSpeakerSides( $rows );
        $html = '<div class="metis-transcript" data-render="transcript">';
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $type = metis_key_clean( (string) ( $row['type'] ?? 'message' ) );
            $text = trim( self::repairMojibakeText( (string) ( $row['text'] ?? '' ) ) );
            if ( $text === '' ) {
                continue;
            }

            if ( $type === 'cue' ) {
                $html .= '<div class="metis-transcript__system"><span class="metis-transcript__system-pill">' . metis_escape_html( $text ) . '</span></div>';
                continue;
            }

            $speaker = self::normalizeTranscriptSpeaker( (string) ( $row['speaker'] ?? '' ) );
            if ( $speaker === '' ) {
                continue;
            }

            if ( self::isTranscriptIntroSpeaker( $speaker ) ) {
                $html .= '<div class="metis-transcript__system"><span class="metis-transcript__system-pill">' . metis_escape_html( $speaker ) . '</span></div>';
                $html .= self::renderTranscriptMessageSequence( $speaker, 'intro', $text );
                continue;
            }

            $side = (string) ( $speaker_sides[ $speaker ] ?? 'left' );
            $html .= self::renderTranscriptMessageSequence( $speaker, $side, $text );
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,string>
     */
    private static function transcriptSpeakerSides( array $rows ): array {
        $sides = [];
        $next_side = 'left';

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || metis_key_clean( (string) ( $row['type'] ?? 'message' ) ) !== 'message' ) {
                continue;
            }

            $speaker = self::normalizeTranscriptSpeaker( (string) ( $row['speaker'] ?? '' ) );
            if ( $speaker === '' || self::isTranscriptIntroSpeaker( $speaker ) || isset( $sides[ $speaker ] ) ) {
                continue;
            }

            $sides[ $speaker ] = $next_side;
            $next_side = $next_side === 'left' ? 'right' : 'left';
        }

        return $sides;
    }

    private static function renderTranscriptMessageText( string $text ): string {
        $text = str_replace( [ "\r\n", "\r" ], "\n", trim( $text ) );
        if ( $text === '' ) {
            return '';
        }

        $paragraphs = preg_split( "/\n\s*\n/", $text ) ?: [ $text ];
        $html = '';
        foreach ( $paragraphs as $paragraph ) {
            $line = trim( $paragraph );
            if ( $line === '' ) {
                continue;
            }
            $html .= '<p>' . self::decorateTranscriptInlineText( $line ) . '</p>';
        }

        return $html;
    }

    /**
     * @return array<int,array{type:string,text:string}>
     */
    private static function parseTranscriptSegments( string $text ): array {
        $text = str_replace( [ "\r\n", "\r" ], "\n", trim( $text ) );
        if ( $text === '' ) {
            return [];
        }

        $segments = [];
        $offset = 0;
        if ( preg_match_all( '/\(([^<>()]{1,180})\)/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as $index => $full_match ) {
                $matched_text = (string) ( $full_match[0] ?? '' );
                $match_offset = (int) ( $full_match[1] ?? 0 );
                if ( $match_offset > $offset ) {
                    $before = trim( substr( $text, $offset, $match_offset - $offset ) );
                    if ( $before !== '' ) {
                        $segments[] = [ 'type' => 'text', 'text' => $before ];
                    }
                }

                $cue_text = trim( (string) ( $matches[1][ $index ][0] ?? '' ) );
                if ( $cue_text !== '' ) {
                    $segments[] = [ 'type' => 'cue', 'text' => $cue_text ];
                }

                $offset = $match_offset + strlen( $matched_text );
            }
        }

        if ( $offset < strlen( $text ) ) {
            $after = trim( substr( $text, $offset ) );
            if ( $after !== '' ) {
                $segments[] = [ 'type' => 'text', 'text' => $after ];
            }
        }

        if ( $segments === [] ) {
            $segments[] = [ 'type' => 'text', 'text' => $text ];
        }

        return $segments;
    }

    private static function decorateTranscriptInlineText( string $text ): string {
        return nl2br( metis_escape_html( $text ) );
    }

    private static function renderTranscriptMessageSequence( string $speaker, string $side, string $text ): string {
        $segments = self::parseTranscriptSegments( $text );
        if ( $segments === [] ) {
            return '';
        }

        $html = '';
        foreach ( $segments as $segment ) {
            $segment_type = (string) ( $segment['type'] ?? 'text' );
            $segment_text = trim( (string) ( $segment['text'] ?? '' ) );
            if ( $segment_text === '' ) {
                continue;
            }

            if ( $segment_type === 'cue' ) {
                $html .= '<div class="metis-transcript__system"><span class="metis-transcript__system-pill metis-transcript__system-pill--cue">' . metis_escape_html( $segment_text ) . '</span></div>';
                continue;
            }

            $row_class = 'metis-transcript__row metis-transcript__row--' . $side;
            if ( $side === 'intro' ) {
                $row_class .= ' metis-transcript__row--lead';
                $html .= '<div class="' . metis_escape_attr( $row_class ) . '">';
                $html .= '<div class="metis-transcript__body-column"><div class="metis-transcript__body">' . self::renderTranscriptMessageText( $segment_text ) . '</div></div>';
                $html .= '</div>';
                continue;
            }

            $html .= '<div class="' . metis_escape_attr( $row_class ) . '">';
            $html .= '<div class="metis-transcript__speaker-column"><div class="metis-transcript__speaker">' . metis_escape_html( $speaker ) . '</div></div>';
            $html .= '<div class="metis-transcript__body-column"><div class="metis-transcript__body">' . self::renderTranscriptMessageText( $segment_text ) . '</div></div>';
            $html .= '</div>';
        }

        return $html;
    }

    private static function isTranscriptIntroSpeaker( string $speaker ): bool {
        return in_array( strtolower( trim( $speaker ) ), [ 'introduction', 'intro', 'opening' ], true );
    }

    private static function transformTranscriptTables( string $html ): string {
        if ( stripos( $html, '<table' ) === false || ! class_exists( \DOMDocument::class ) ) {
            return $html;
        }

        $document = new \DOMDocument( '1.0', 'UTF-8' );
        $wrapped = '<div id="metis-transcript-root">' . $html . '</div>';
        $previous = libxml_use_internal_errors( true );
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?>' . self::utf8HtmlFragment( $wrapped ),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return $html;
        }

        $xpath = new \DOMXPath( $document );
        $tables = $xpath->query( '//table' );
        if ( ! ( $tables instanceof \DOMNodeList ) || $tables->length < 1 ) {
            return $html;
        }

        $replacements = [];
        foreach ( $tables as $table ) {
            if ( ! $table instanceof \DOMElement ) {
                continue;
            }
            $rows = self::transcriptRowsFromTable( $table );
            if ( count( $rows ) < 2 ) {
                continue;
            }
            $replacements[] = [ 'table' => $table, 'rows' => $rows ];
        }

        if ( $replacements === [] ) {
            return $html;
        }

        foreach ( $replacements as $replacement ) {
            $table = $replacement['table'];
            $rows = $replacement['rows'];
            if ( ! $table instanceof \DOMElement || ! is_array( $rows ) ) {
                continue;
            }

            $replace_target = self::transcriptReplaceTarget( $table );
            $fragment = self::htmlFragmentForDocument( $document, self::renderTranscriptRowsHtml( $rows ) );
            if ( $replace_target->parentNode !== null ) {
                $replace_target->parentNode->replaceChild( $fragment, $replace_target );
            }
        }

        $root = $document->getElementById( 'metis-transcript-root' );
        return $root instanceof \DOMElement ? self::domInnerHtml( $root ) : $html;
    }

    /**
     * @return array<int,array{speaker:string,body_html:string}>
     */
    private static function transcriptRowsFromTable( \DOMElement $table ): array {
        $rows = [];
        foreach ( $table->getElementsByTagName( 'tr' ) as $row ) {
            if ( ! $row instanceof \DOMElement ) {
                continue;
            }

            $cells = [];
            foreach ( $row->childNodes as $cell ) {
                if ( $cell instanceof \DOMElement && in_array( strtolower( $cell->tagName ), [ 'td', 'th' ], true ) ) {
                    $cells[] = $cell;
                }
            }
            if ( count( $cells ) !== 2 ) {
                return [];
            }

            $speaker = self::normalizeTranscriptSpeaker( $cells[0]->textContent ?? '' );
            if ( $speaker === '' ) {
                return [];
            }

            $body_html = self::transcriptCellHtml( $cells[1] );
            if ( $body_html === '' ) {
                continue;
            }

            $rows[] = [
                'speaker' => $speaker,
                'body_html' => $body_html,
            ];
        }

        if ( count( $rows ) < 2 ) {
            return [];
        }

        return $rows;
    }

    private static function transcriptReplaceTarget( \DOMElement $table ): \DOMElement {
        $parent = $table->parentNode;
        if ( $parent instanceof \DOMElement && strtolower( $parent->tagName ) === 'figure' && str_contains( ' ' . $parent->getAttribute( 'class' ) . ' ', ' wp-block-table ' ) ) {
            return $parent;
        }
        return $table;
    }

    private static function normalizeTranscriptSpeaker( string $speaker ): string {
        $speaker = trim( self::repairMojibakeText( $speaker ) );
        if ( $speaker === '' ) {
            return '';
        }
        $speaker = preg_replace( '/\s+/u', ' ', $speaker ) ?? $speaker;
        $speaker = trim( $speaker );
        if ( mb_strlen( $speaker ) > 48 ) {
            return '';
        }
        if ( preg_match( '/^[A-Za-z0-9 .,&()\'"\/-]+:?$/u', $speaker ) !== 1 ) {
            return '';
        }
        return rtrim( $speaker, ':' );
    }

    private static function transcriptCellHtml( \DOMElement $cell ): string {
        self::repairMojibakeTextNodes( $cell );
        $html = trim( self::domInnerHtml( $cell ) );
        if ( $html === '' ) {
            return '';
        }
        if ( preg_match( '/<\s*(p|div|ul|ol|blockquote|figure|h[1-6])\b/i', $html ) !== 1 ) {
            $html = '<p>' . $html . '</p>';
        }
        return self::decorateTranscriptBodyHtml( $html );
    }

    private static function decorateTranscriptBodyHtml( string $html ): string {
        return preg_replace_callback(
            '/<p>(\s*)\(([^<()]{1,180})\)(\s*)/iu',
            static function ( array $matches ): string {
                $leading = (string) ( $matches[1] ?? '' );
                $cue = trim( (string) ( $matches[2] ?? '' ) );
                $trailing = (string) ( $matches[3] ?? '' );
                if ( $cue === '' ) {
                    return (string) ( $matches[0] ?? '' );
                }
                return '<p>' . $leading . '<span class="metis-transcript__cue">(' . metis_escape_html( $cue ) . ')</span>' . $trailing;
            },
            $html
        ) ?? $html;
    }

    private static function repairMojibakeTextNodes( \DOMNode $node ): void {
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof \DOMText ) {
                $child->nodeValue = self::repairMojibakeText( (string) $child->nodeValue );
                continue;
            }
            if ( $child instanceof \DOMNode ) {
                self::repairMojibakeTextNodes( $child );
            }
        }
    }

    private static function repairMojibakeText( string $text ): string {
        $current = self::replaceCommonMojibakeSequences( $text );
        $current = str_ireplace(
            [
                '&Acirc;&nbsp;',
                '&Acirc;&#160;',
                '&Acirc;&#xA0;',
                '&Acirc;&#xa0;',
                'Â&nbsp;',
                'Â&#160;',
                'Â&#xA0;',
                'Â&#xa0;',
            ],
            ' ',
            $current
        );
        for ( $i = 0; $i < 3; $i++ ) {
            $candidate = function_exists( 'mb_convert_encoding' )
                ? @mb_convert_encoding( $current, 'UTF-8', 'Windows-1252' )
                : @iconv( 'Windows-1252', 'UTF-8//IGNORE', $current );
            if ( ! is_string( $candidate ) || $candidate === '' || $candidate === $current ) {
                break;
            }
            if ( self::mojibakeScore( $candidate ) >= self::mojibakeScore( $current ) ) {
                break;
            }
            $current = $candidate;
        }

	        $current = str_replace( [ "\xc2\xa0", "\u{00A0}", "\xa0" ], ' ', $current );
	        $current = str_replace( 'ï¿½', '', $current );
	        $current = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $current ) ?? $current;
	        $current = preg_replace( '/\x{FFFD}+/u', '', $current ) ?? $current;
	        $current = preg_replace( '/Ã+(?=\s|$)/u', '', $current ) ?? $current;
	        $current = preg_replace( '/Ã(?=[A-Za-z0-9])/u', '', $current ) ?? $current;
	        $current = preg_replace( '/Â+(?=\s|$)/u', '', $current ) ?? $current;
	        $current = preg_replace( '/Â(?=[A-Za-z0-9])/u', '', $current ) ?? $current;
	        return $current;
	    }

    private static function replaceCommonMojibakeSequences( string $text ): string {
        return str_replace(
            [
                'Ã¢ÂÂ',
                'Ã¢ÂÂ',
                'Ã¢ÂÂ',
                'Ã¢ÂÂ',
                'Ã¢ÂÂ¦',
                'Ã¢ÂÂ',
                'Ã¢ÂÂ',
                'â',
                'â',
                'â',
                'â',
                'â¦',
                'â',
                'â',
                'ÃÂ ',
                'Â ',
            ],
            [
                '’',
                '‘',
                '“',
                '”',
                '…',
                '–',
                '—',
                '’',
                '‘',
                '“',
                '”',
                '…',
                '–',
                '—',
                ' ',
                ' ',
            ],
            preg_replace(
                '/([A-Za-z0-9?!.,])\x{FFFD}\?\?($|[\s)\]}.,;!?])/u',
                '$1”$2',
                preg_replace(
                    '/(^|[\s(\[{])\x{FFFD}\?\?([A-Za-z0-9])/u',
                    '$1“$2',
                    preg_replace(
                        '/([A-Za-z0-9])\x{FFFD}\?\?([A-Za-z0-9])/u',
                        '$1’$2',
                        $text
                    ) ?? $text
                ) ?? $text
            ) ?? $text
        );
    }

    private static function mojibakeScore( string $text ): int {
        $markers = [ 'Ã', 'Â', 'â€', 'â', 'â€™', 'â€œ', 'â€\x9d', 'ï¿½', '�' ];
        $score = 0;
        foreach ( $markers as $marker ) {
            $score += substr_count( $text, $marker );
        }
        return $score;
    }

    /**
     * @param array<int,array{speaker:string,body_html:string}> $rows
     */
    private static function renderTranscriptRowsHtml( array $rows ): string {
        $html = '<div class="metis-transcript" data-render="transcript">';
        foreach ( $rows as $row ) {
            $html .= '<div class="metis-transcript__row">';
            $html .= '<div class="metis-transcript__speaker-column"><div class="metis-transcript__speaker">' . metis_escape_html( (string) $row['speaker'] ) . '</div></div>';
            $html .= '<div class="metis-transcript__body-column"><div class="metis-transcript__body">' . (string) $row['body_html'] . '</div></div>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private static function domInnerHtml( \DOMNode $node ): string {
        $html = '';
        foreach ( $node->childNodes as $child ) {
            $html .= $node->ownerDocument instanceof \DOMDocument
                ? (string) $node->ownerDocument->saveHTML( $child )
                : '';
        }
        return $html;
    }

    private static function htmlFragmentForDocument( \DOMDocument $document, string $html ): \DOMDocumentFragment {
        $fragment = $document->createDocumentFragment();
        $temp = new \DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        $loaded = $temp->loadHTML(
            '<?xml encoding="utf-8" ?>' . self::utf8HtmlFragment( '<div id="metis-fragment-root">' . $html . '</div>' ),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return $fragment;
        }

        $root = $temp->getElementById( 'metis-fragment-root' );
        if ( ! $root instanceof \DOMElement ) {
            return $fragment;
        }

        foreach ( iterator_to_array( $root->childNodes ) as $child ) {
            if ( ! $child instanceof \DOMNode ) {
                continue;
            }
            $fragment->appendChild( $document->importNode( $child, true ) );
        }

        return $fragment;
    }

    private static function utf8HtmlFragment( string $html ): string {
        if ( function_exists( 'mb_encode_numericentity' ) ) {
            return (string) mb_encode_numericentity(
                $html,
                [ 0x80, 0x10FFFF, 0, 0x10FFFF ],
                'UTF-8'
            );
        }

        return preg_replace_callback(
            '/[^\x00-\x7F]/u',
            static function ( array $matches ): string {
                $char = (string) ( $matches[0] ?? '' );
                if ( $char === '' || ! function_exists( 'mb_ord' ) ) {
                    return $char;
                }
                $codepoint = mb_ord( $char, 'UTF-8' );
                return $codepoint > 0 ? '&#' . (string) $codepoint . ';' : $char;
            },
            $html
        ) ?? $html;
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredColumnsSection( array $content, array $context = [] ): string {
        $columns = is_array( $content['columns'] ?? null ) ? $content['columns'] : [];
        $items = '';
        $count = 0;
        foreach ( $columns as $column ) {
            if ( ! is_array( $column ) ) {
                continue;
            }
            $width_raw = trim( (string) ( $column['width'] ?? '50%' ) );
            $width = (int) str_replace( '%', '', $width_raw );
            if ( ! in_array( $width, [ 25, 33, 50, 100 ], true ) ) {
                $width = 50;
            }
            $module = is_array( $column['module'] ?? null ) ? $column['module'] : [];
            $module_type = metis_key_clean( (string) ( $module['type'] ?? 'text' ) );
            $safe_body = '';
            if ( in_array( $module_type, [ 'form', 'donation_form', 'donation_progress', 'campaign_summary', 'button', 'image' ], true ) ) {
                $module_content = is_array( $module['content'] ?? null ) ? $module['content'] : [];
                $safe_body = self::renderStructuredSectionBody( $module_type, $module_content, $context );
            } else {
                $body = trim( (string) ( $column['body'] ?? '' ) );
                if ( $body === '' ) {
                    $body = is_array( $module['content'] ?? null ) ? (string) ( $module['content']['body'] ?? '' ) : '';
                }
                if ( $body === '' ) {
                    $body = '<p></p>';
                }
                $safe_body = function_exists( 'metis_runtime_kses_post' )
                    ? (string) metis_runtime_kses_post( $body )
                    : strip_tags( $body, '<p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><span><div>' );
                $safe_body = self::repairPublicHtmlText( $safe_body );
            }
            $items .= '<div class="metis-structured-columns__col is-w' . metis_escape_attr( (string) $width ) . '">'
                . $safe_body . '</div>';
            $count++;
            if ( $count >= 4 ) {
                break;
            }
        }
        if ( $items === '' ) {
            return '';
        }
        return '<div class="metis-structured-columns">' . $items . '</div>';
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredFeatureGridSection( array $content ): string {
        $columns = (int) ( $content['columns'] ?? 3 );
        if ( ! in_array( $columns, [ 2, 3, 4 ], true ) ) {
            $columns = 3;
        }
        $items = is_array( $content['items'] ?? null ) ? $content['items'] : [];
        $cards = '';
        $count = 0;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $icon = trim( self::repairMojibakeText( (string) ( $item['icon'] ?? '' ) ) );
            $title = trim( self::repairMojibakeText( (string) ( $item['title'] ?? '' ) ) );
            $text = trim( self::repairMojibakeText( (string) ( $item['text'] ?? '' ) ) );
            $cta = is_array( $item['cta'] ?? null ) ? $item['cta'] : [];
            $cta_label = trim( self::repairMojibakeText( (string) ( $cta['label'] ?? '' ) ) );
            $cta_url = trim( (string) ( $cta['url'] ?? '' ) );
            if ( $title === '' && $text === '' && $cta_label === '' ) {
                continue;
            }
            $cards .= '<article class="metis-structured-feature-grid__item">';
            if ( $icon !== '' ) {
                $cards .= '<div class="metis-structured-feature-grid__icon" aria-hidden="true">' . metis_escape_html( $icon ) . '</div>';
            }
            if ( $title !== '' ) {
                $cards .= '<h3 class="metis-structured-feature-grid__title">' . metis_escape_html( $title ) . '</h3>';
            }
            if ( $text !== '' ) {
                $cards .= '<p class="metis-structured-feature-grid__text">' . metis_escape_html( $text ) . '</p>';
            }
            if ( $cta_label !== '' ) {
                $url = $cta_url !== '' ? self::normalizePublicUrl( $cta_url ) : '#';
                $cards .= '<p class="metis-structured-feature-grid__cta"><a href="' . metis_escape_attr( $url ) . '">'
                    . metis_escape_html( $cta_label ) . '</a></p>';
            }
            $cards .= '</article>';
            $count++;
            if ( $count >= 16 ) {
                break;
            }
        }
        if ( $cards === '' ) {
            return '';
        }
        return '<div class="metis-structured-feature-grid cols-' . metis_escape_attr( (string) $columns ) . '">' . $cards . '</div>';
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredCtaSection( array $content ): string {
        $layout = metis_key_clean( (string) ( $content['layout'] ?? 'single' ) );
        if ( ! in_array( $layout, [ 'single', 'split' ], true ) ) {
            $layout = 'single';
        }
        $items = is_array( $content['items'] ?? null ) ? $content['items'] : [];
        $cards = '';
        $count = 0;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $title = trim( self::repairMojibakeText( (string) ( $item['title'] ?? '' ) ) );
            $text = trim( self::repairMojibakeText( (string) ( $item['text'] ?? '' ) ) );
            $button = is_array( $item['button'] ?? null ) ? $item['button'] : [];
            $button_label = trim( self::repairMojibakeText( (string) ( $button['label'] ?? '' ) ) );
            $button_url = trim( (string) ( $button['url'] ?? '' ) );
            if ( $title === '' && $text === '' && $button_label === '' ) {
                continue;
            }
            $cards .= '<article class="metis-structured-cta__item">';
            if ( $title !== '' ) {
                $cards .= '<h3 class="metis-structured-cta__title">' . metis_escape_html( $title ) . '</h3>';
            }
            if ( $text !== '' ) {
                $cards .= '<p class="metis-structured-cta__text">' . metis_escape_html( $text ) . '</p>';
            }
            if ( $button_label !== '' ) {
                $url = $button_url !== '' ? self::normalizePublicUrl( $button_url ) : '#';
                $cards .= '<p class="metis-structured-cta__action"><a class="metis-structured-cta__button" href="'
                    . metis_escape_attr( $url ) . '">' . metis_escape_html( $button_label ) . '</a></p>';
            }
            $cards .= '</article>';
            $count++;
            if ( $layout === 'single' || $count >= 2 ) {
                break;
            }
        }
        if ( $cards === '' ) {
            return '';
        }
        return '<div class="metis-structured-cta metis-structured-cta--' . metis_escape_attr( $layout ) . '">' . $cards . '</div>';
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $context
     */
    private static function renderStructuredEventsSection( array $content, array $context = [] ): string {
        $source = metis_key_clean( (string) ( $content['source'] ?? 'calendar' ) );
        if ( ! in_array( $source, [ 'calendar', 'manual' ], true ) ) {
            $source = 'calendar';
        }
        $limit = (int) ( $content['limit'] ?? 5 );
        $limit = max( 1, min( 50, $limit ) );
        $requested_calendar_id = trim( (string) ( $content['calendar_id'] ?? '' ) );

        $items = [];
        if ( $source === 'manual' ) {
            $manual_items = is_array( $content['items'] ?? null ) ? $content['items'] : [];
            foreach ( $manual_items as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $title = trim( (string) ( $row['title'] ?? $row['summary'] ?? '' ) );
                if ( $title === '' ) {
                    continue;
                }
                $start = trim( (string) ( $row['start'] ?? $row['date'] ?? '' ) );
                $location = trim( (string) ( $row['location'] ?? '' ) );
                $items[] = [
                    'summary' => $title,
                    'start' => [ 'dateTime' => $start ],
                    'location' => $location,
                ];
                if ( count( $items ) >= $limit ) {
                    break;
                }
            }
        } elseif ( function_exists( 'metis_calendar_cached_events' ) ) {
            try {
                $configs = self::calendarConfigsForWebsite( $requested_calendar_id );

                $start_ts = strtotime( 'today midnight' ) ?: time();
                $end_ts = strtotime( '+365 days', $start_ts );
                if ( ! $end_ts ) {
                    $end_ts = $start_ts + ( 365 * 86400 );
                }
                foreach ( $configs as $cfg ) {
                    $rows = metis_calendar_cached_events( $cfg, (int) $start_ts, (int) $end_ts, '' );
                    if ( is_array( $rows ) && $rows !== [] ) {
                        $items = array_merge( $items, $rows );
                    }
                    if ( $items !== [] ) {
                        break;
                    }
                }
            } catch ( \Throwable $e ) {
                // Keep graceful fallback output below.
            }
        }

        if ( $items !== [] ) {
            usort(
                $items,
                static function ( $a, $b ): int {
                    $a_start = (string) ( $a['start']['dateTime'] ?? $a['start']['date'] ?? '' );
                    $b_start = (string) ( $b['start']['dateTime'] ?? $b['start']['date'] ?? '' );
                    $a_ts = strtotime( $a_start ) ?: 0;
                    $b_ts = strtotime( $b_start ) ?: 0;
                    return $a_ts <=> $b_ts;
                }
            );
            $items = array_slice( $items, 0, $limit );
        }

        if ( $items === [] ) {
            return '<div class="metis-structured-events metis-structured-events--empty"><p>No upcoming events.</p></div>';
        }

        $html = '<div class="metis-structured-events">';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $title = trim( (string) ( $item['summary'] ?? $item['title'] ?? 'Event' ) );
            $start_raw = (string) ( $item['start']['dateTime'] ?? $item['start']['date'] ?? '' );
            $end_raw = (string) ( $item['end']['dateTime'] ?? $item['end']['date'] ?? '' );
            $location = trim( (string) ( $item['location'] ?? '' ) );
            $event_url = trim( (string) ( $item['htmlLink'] ?? $item['url'] ?? '' ) );
            $date_label = '';
            $time_label = '';
            if ( $start_raw !== '' ) {
                $ts = strtotime( $start_raw );
                if ( $ts ) {
                    $date_label = function_exists( 'metis_runtime_date' )
                        ? metis_runtime_date( 'l, M j, Y', (int) $ts )
                        : date( 'l, M j, Y', (int) $ts );
                    $time_label = function_exists( 'metis_runtime_date' )
                        ? metis_runtime_date( 'g:i A', (int) $ts )
                        : date( 'g:i A', (int) $ts );
                    if ( $end_raw !== '' ) {
                        $end_ts = strtotime( $end_raw );
                        if ( $end_ts ) {
                            $time_label .= ' - ' . ( function_exists( 'metis_runtime_date' )
                                ? metis_runtime_date( 'g:i A', (int) $end_ts )
                                : date( 'g:i A', (int) $end_ts ) );
                        }
                    }
                }
            }
            if ( $location !== '' && preg_match( '#(https?://\S+)$#', $location, $location_match ) === 1 ) {
                $event_url = $event_url !== '' ? $event_url : trim( (string) $location_match[1] );
                $location = trim( preg_replace( '#\s*;?\s*https?://\S+$#', '', $location ) ?? $location );
            }
            $html .= '<article class="metis-structured-events__item">';
            $html .= '<div class="metis-structured-events__eyebrow">Upcoming Event</div>';
            $html .= '<h3 class="metis-structured-events__title">' . metis_escape_html( $title !== '' ? $title : 'Event' ) . '</h3>';
            $html .= '<div class="metis-structured-events__meta">';
            if ( $date_label !== '' ) {
                $html .= '<span class="metis-structured-events__chip"><strong>Date</strong><span>' . metis_escape_html( $date_label ) . '</span></span>';
            }
            if ( $time_label !== '' ) {
                $html .= '<span class="metis-structured-events__chip"><strong>Time</strong><span>' . metis_escape_html( $time_label ) . '</span></span>';
            }
            $html .= '</div>';
            if ( $location !== '' ) {
                $html .= '<p class="metis-structured-events__location"><span>Where</span>' . metis_escape_html( $location ) . '</p>';
            }
            if ( $event_url !== '' ) {
                $html .= '<p class="metis-structured-events__cta"><a href="' . metis_escape_attr( self::normalizePublicUrl( $event_url ) ) . '">See full details</a></p>';
            }
            $html .= '</article>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Prefer the public calendar when available, falling back to default config.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function calendarConfigsForWebsite( string $requested_calendar_id = '' ): array {
        $configs = [];
        $all = [];

        if ( function_exists( 'metis_calendar_workspace_settings_all' ) ) {
            $workspace = metis_calendar_workspace_settings_all();
            if ( is_array( $workspace ) && ! empty( $workspace['ok'] ) ) {
                $all = is_array( $workspace['calendars'] ?? null ) ? $workspace['calendars'] : [];
            }
        }

        if ( $requested_calendar_id !== '' && $all !== [] ) {
            foreach ( $all as $cfg ) {
                if ( ! is_array( $cfg ) ) {
                    continue;
                }
                if ( trim( (string) ( $cfg['calendar_id'] ?? '' ) ) === $requested_calendar_id ) {
                    $configs[] = $cfg;
                    return $configs;
                }
            }
        }

        if ( $all !== [] ) {
            $public = self::pickPublicCalendarConfig( $all );
            if ( $public !== [] ) {
                $configs[] = $public;
                return $configs;
            }
            if ( isset( $all[0] ) && is_array( $all[0] ) ) {
                $configs[] = $all[0];
                return $configs;
            }
        }

        if ( function_exists( 'metis_calendar_workspace_settings' ) ) {
            $default = metis_calendar_workspace_settings();
            if ( is_array( $default ) && ! empty( $default['ok'] ) ) {
                $configs[] = $default;
            }
        }

        return $configs;
    }

    /**
     * @param array<int,array<string,mixed>> $configs
     * @return array<string,mixed>
     */
    private static function pickPublicCalendarConfig( array $configs ): array {
        $fallback = [];
        foreach ( $configs as $cfg ) {
            if ( ! is_array( $cfg ) ) {
                continue;
            }
            if ( $fallback === [] ) {
                $fallback = $cfg;
            }
            $label = strtolower( trim( (string) ( $cfg['calendar_label'] ?? $cfg['label'] ?? $cfg['calendar_name'] ?? '' ) ) );
            if ( $label === '' ) {
                continue;
            }
            if ( str_contains( $label, 'public' ) ) {
                return $cfg;
            }
        }
        return $fallback;
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $context
     */
    private static function renderStructuredFormSection( array $content, array $context = [] ): string {
        return self::renderStructuredBlockModule(
            'form',
            [
                'form_id' => trim( (string) ( $content['form_id'] ?? '' ) ),
                'submit_label' => trim( (string) ( $content['submit_label'] ?? 'Submit' ) ),
            ],
            $context
        );
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $context
     */
    private static function renderStructuredDonationFormSection( array $content, array $context = [] ): string {
        $mode = metis_key_clean( (string) ( $content['mode'] ?? 'both' ) );
        if ( ! in_array( $mode, [ 'one_time', 'monthly', 'both' ], true ) ) {
            $mode = 'both';
        }
        return self::renderStructuredBlockModule(
            'donation_form_block',
            [
                'campaign_id' => trim( (string) ( $content['campaign_id'] ?? '' ) ),
                'preset_amounts' => self::structuredPresetAmounts( $content['preset_amounts'] ?? [] ),
                'allow_custom_amount' => self::structuredBoolean( $content['allow_custom_amount'] ?? true ),
                'mode' => $mode,
                'show_name' => self::structuredBoolean( $content['show_name'] ?? true ),
                'show_email' => self::structuredBoolean( $content['show_email'] ?? true ),
                'show_phone' => self::structuredBoolean( $content['show_phone'] ?? false ),
            ],
            $context
        );
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $context
     */
    private static function renderStructuredDonationProgressSection( array $content, array $context = [] ): string {
        return self::renderStructuredBlockModule(
            'progress_bar_block',
            [
                'campaign_id' => trim( (string) ( $content['campaign_id'] ?? '' ) ),
                'goal_amount' => self::structuredDecimal( $content['goal_amount'] ?? '' ),
                'raised_amount' => self::structuredDecimal( $content['raised_amount'] ?? '' ),
                'percent' => self::structuredPercent( $content['percent'] ?? '' ),
            ],
            $context
        );
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $context
     */
    private static function renderStructuredCampaignSummarySection( array $content, array $context = [] ): string {
        return self::renderStructuredBlockModule(
            'campaign_description_block',
            [
                'campaign_id' => trim( (string) ( $content['campaign_id'] ?? '' ) ),
                'title' => trim( (string) ( $content['title'] ?? '' ) ),
                'content' => (string) ( $content['content'] ?? '' ),
                'image' => trim( (string) ( $content['image'] ?? '' ) ),
            ],
            $context
        );
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $context
     */
    private static function renderStructuredDividerSection( array $content, array $context = [] ): string {
        $style = metis_key_clean( (string) ( $content['style'] ?? 'solid' ) );
        if ( ! in_array( $style, [ 'solid', 'dashed', 'dotted' ], true ) ) {
            $style = 'solid';
        }
        return self::renderStructuredBlockModule(
            'divider',
            [
                'label' => trim( (string) ( $content['label'] ?? '' ) ),
                'style' => $style,
            ],
            $context
        );
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     */
    private static function renderStructuredBlockModule( string $type, array $data, array $context = [] ): string {
        $id_source = $type . ':' . ( function_exists( 'metis_json_encode' ) ? metis_json_encode( $data ) : json_encode( $data ) );
        return BlockRenderer::render(
            [
                'id' => 'structured_' . metis_key_clean( $type ) . '_' . substr( sha1( (string) $id_source ), 0, 8 ),
                'type' => $type,
                'data' => $data,
                'style' => [],
            ],
            $context
        );
    }

    /**
     * @return array<int,float|int>
     */
    private static function structuredPresetAmounts( mixed $raw ): array {
        $source = [];
        if ( is_array( $raw ) ) {
            $source = $raw;
        } elseif ( is_scalar( $raw ) ) {
            $source = preg_split( '/[,\s]+/', (string) $raw ) ?: [];
        }

        $amounts = [];
        foreach ( $source as $value ) {
            if ( ! is_scalar( $value ) || ! is_numeric( (string) $value ) ) {
                continue;
            }
            $amount = round( (float) $value, 2 );
            if ( $amount <= 0 || $amount > 1000000 ) {
                continue;
            }
            $amounts[] = floor( $amount ) === $amount ? (int) $amount : $amount;
            if ( count( $amounts ) >= 8 ) {
                break;
            }
        }

        return $amounts !== [] ? array_values( array_unique( $amounts, SORT_REGULAR ) ) : [ 25, 50, 100 ];
    }

    private static function structuredBoolean( mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_int( $value ) || is_float( $value ) ) {
            return (int) $value === 1;
        }
        return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
    }

    private static function structuredDecimal( mixed $value ): string {
        if ( ! is_scalar( $value ) || trim( (string) $value ) === '' || ! is_numeric( (string) $value ) ) {
            return '';
        }
        $number = max( 0.0, min( 1000000000.0, (float) $value ) );
        return rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );
    }

    private static function structuredPercent( mixed $value ): string {
        if ( ! is_scalar( $value ) || trim( (string) $value ) === '' || ! is_numeric( (string) $value ) ) {
            return '';
        }
        $number = max( 0.0, min( 100.0, (float) $value ) );
        return rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );
    }

    /**
     * @param array<string,mixed> $content
     */
    private static function renderStructuredSpacerSection( array $content ): string {
        $height = metis_key_clean( (string) ( $content['height'] ?? 'medium' ) );
        if ( ! in_array( $height, [ 'small', 'medium', 'large' ], true ) ) {
            $height = 'medium';
        }
        return '<div class="metis-structured-spacer is-' . metis_escape_attr( $height ) . '" aria-hidden="true"></div>';
    }

    /**
     * @param array<string,mixed> $content
     * @param array<string,mixed> $context
     */
    private static function renderStructuredPostsListSection( array $content, array $context = [] ): string {
        $limit = (int) ( $content['limit'] ?? 5 );
        $limit = max( 1, min( 50, $limit ) );
        $source = metis_key_clean( (string) ( $content['source'] ?? 'this_page' ) );
        if ( ! in_array( $source, [ 'this_page', 'specific_page' ], true ) ) {
            $source = 'this_page';
        }
        $specific_page = (int) ( $content['specific_page'] ?? 0 );
        $query = [ 'limit' => $limit ];
        $category_ids = array_values( array_unique( array_filter( array_map( 'intval', is_array( $content['category_ids'] ?? null ) ? $content['category_ids'] : [] ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $category_ids !== [] ) {
            $query['post_category_ids'] = $category_ids;
        }
        if ( $source === 'specific_page' && $specific_page > 0 ) {
            $query['parent_page_id'] = $specific_page;
        } elseif (
            $source === 'this_page'
            && metis_key_clean( (string) ( $context['content_type'] ?? '' ) ) === 'page'
            && (int) ( $context['content_id'] ?? 0 ) > 0
            && empty( $context['is_homepage'] )
        ) {
            $query['parent_page_id'] = (int) $context['content_id'];
        }

        $posts = array_values( array_filter(
            PostService::getPublished( $query ),
            static fn ( $post ): bool => $post instanceof Post && self::postPublicPath( $post ) !== ''
        ) );
        if ( ! is_array( $posts ) || $posts === [] ) {
            return '<div class="metis-structured-posts-list metis-structured-posts-list--empty"><p>No posts yet.</p></div>';
        }

        $items = '';
        foreach ( $posts as $post ) {
            if ( ! $post instanceof Post ) {
                continue;
            }
            $post_path = self::postPublicPath( $post );
            if ( $post_path === '' ) {
                continue;
            }
            $url = self::normalizePublicUrl( $post_path );
            $date = trim( (string) ( $post->publish_date ?? $post->created_at ?? '' ) );
            $excerpt = trim( (string) ( $post->excerpt ?? '' ) );
            $author_name = self::authorNameById( (int) ( $post->author_id ?? $post->updated_by ?? $post->created_by ?? 0 ) );
            $featured_image_url = self::mediaUrlById( (int) ( $post->featured_image_id ?? 0 ) );
            $date_label = $date;
            if ( $date !== '' ) {
                $ts = strtotime( $date );
                if ( $ts !== false && $ts > 0 ) {
                    $date_label = self::formatSystemDate( (int) $ts );
                }
            }
            $category_ids = array_values( array_unique( array_filter( array_map( 'intval', is_array( $post->post_category_ids ?? null ) ? $post->post_category_ids : [] ) ) ) );
            if ( $category_ids === [] && (int) ( $post->post_category_id ?? 0 ) > 0 ) {
                $category_ids = [ (int) $post->post_category_id ];
            }
            $category_labels = [];
            foreach ( $category_ids as $category_id ) {
                $label = method_exists( PostCategoryService::class, 'categoryNameById' )
                    ? trim( (string) PostCategoryService::categoryNameById( $category_id ) )
                    : '';
                if ( $label !== '' ) {
                    $category_labels[] = $label;
                }
            }
            $items .= '<article class="metis-structured-posts-list__item">';
            if ( $featured_image_url !== '' ) {
                $items .= '<a class="metis-structured-posts-list__media" href="' . metis_escape_attr( $url ) . '"><img src="' . metis_escape_attr( self::normalizePublicUrl( $featured_image_url ) ) . '" alt=""></a>';
            }
            $items .= '<h3 class="metis-structured-posts-list__title"><a href="' . metis_escape_attr( $url ) . '">' . metis_escape_html( (string) $post->title ) . '</a></h3>';
            if ( $author_name !== '' || $date !== '' ) {
                $items .= '<p class="metis-structured-posts-list__byline">';
                if ( $author_name !== '' ) {
                    $items .= 'By <span>' . metis_escape_html( $author_name ) . '</span>';
                }
                if ( $author_name !== '' && $date !== '' ) {
                    $items .= ' <span class="metis-structured-posts-list__byline-sep">|</span> ';
                }
                if ( $date !== '' ) {
                    $items .= metis_escape_html( $date_label );
                }
                $items .= '</p>';
            }
            if ( $category_labels !== [] ) {
                $items .= '<p class="metis-structured-posts-list__categories">Posted in ';
                foreach ( $category_labels as $index => $category_label ) {
                    if ( $index > 0 ) {
                        $items .= ', ';
                    }
                    $items .= '<span>' . metis_escape_html( $category_label ) . '</span>';
                }
                $items .= '</p>';
            }
            if ( $excerpt !== '' ) {
                $safe_excerpt = function_exists( 'metis_runtime_kses_post' )
                    ? (string) metis_runtime_kses_post( $excerpt )
                    : strip_tags( $excerpt, '<p><br><strong><b><em><i><u><ul><ol><li><a><span><div>' );
                $items .= '<div class="metis-structured-posts-list__excerpt">' . $safe_excerpt . '</div>';
            }
            $items .= '<p class="metis-structured-posts-list__cta"><a href="' . metis_escape_attr( $url ) . '">Read More</a></p>';
            $items .= '</article>';
        }

        return '<div class="metis-structured-posts-list metis-structured-posts-list--cards">' . $items . '</div>';
    }

    private static function layoutSettingsFromRaw( ?string $raw ): array {
        $settings = [
            'layout_width' => 'constrained',
            'layout_max_width' => 860,
            'page_background_mode' => 'custom',
            'page_background' => '#ffffff',
            'page_padding' => '8px',
            'page_margin' => '0',
        ];

        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return $settings;
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return $settings;
        }

        $layout = is_array( $decoded['settings'] ?? null )
            ? $decoded['settings']
            : ( is_array( $decoded['content']['settings'] ?? null ) ? $decoded['content']['settings'] : [] );

        if ( ! is_array( $layout ) ) {
            return $settings;
        }

        if ( in_array( (string) ( $layout['layout_width'] ?? '' ), [ 'full', 'constrained' ], true ) ) {
            $settings['layout_width'] = (string) $layout['layout_width'];
        }

        $max_width = (int) ( $layout['layout_max_width'] ?? $settings['layout_max_width'] );
        if ( $max_width >= 640 && $max_width <= 1800 ) {
            $settings['layout_max_width'] = $max_width;
        }

        if ( in_array( (string) ( $layout['page_background_mode'] ?? '' ), [ 'theme', 'custom' ], true ) ) {
            $settings['page_background_mode'] = (string) $layout['page_background_mode'];
        }

        foreach ( [ 'page_background', 'page_padding', 'page_margin' ] as $key ) {
            if ( isset( $layout[ $key ] ) && is_string( $layout[ $key ] ) && trim( $layout[ $key ] ) !== '' ) {
                $settings[ $key ] = trim( (string) $layout[ $key ] );
            }
        }

        return $settings;
    }

    private static function layoutCss( array $settings ): string {
        $layout_width = (string) ( $settings['layout_width'] ?? 'constrained' );
        $layout_max_width = (int) ( $settings['layout_max_width'] ?? 860 );
        $page_background_mode = (string) ( $settings['page_background_mode'] ?? 'custom' );
        $page_background = (string) ( $settings['page_background'] ?? '#ffffff' );
        $page_padding = (string) ( $settings['page_padding'] ?? '8px' );
        $page_margin = (string) ( $settings['page_margin'] ?? '0' );

        $max_width = $layout_width === 'full' ? 'none' : max( 640, min( 1800, $layout_max_width ) ) . 'px';
        $background = $page_background_mode === 'custom' ? $page_background : 'var(--metis-color-bg, #ffffff)';

        return implode( "\n", [
            '.metis-public-site{background:' . metis_escape_attr( $background ) . ';}',
            '.metis-site-content{padding:' . metis_escape_attr( $page_padding ) . ';}',
            '.metis-site-content-inner{max-width:' . metis_escape_attr( $max_width ) . ';margin:' . metis_escape_attr( $page_margin ) . ';}',
        ] );
    }

    private static function publicBaseCss(): string {
        return implode( "\n", [
            'html,body{height:100%;}',
            'body.metis-public-site{margin:0;background:var(--metis-color-bg,#ffffff);min-height:100vh;display:flex;flex-direction:column;}',
            '.metis-shell-header{position:sticky;top:0;z-index:1000;background:var(--metis-color-surface,#fff);border-bottom:0;}',
            '.metis-shell-header-inner{max-width:1200px;margin:0 auto;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;gap:20px;}',
            '.metis-shell-brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:var(--metis-color-text,#1a1f2b);}',
            '.metis-shell-brand-logo{display:block;width:auto;height:auto;max-width:100%;object-fit:contain;background:transparent;border:0;border-radius:0;}',
            '.metis-shell-brand-logo-header{max-height:130px;}',
            '.metis-shell-brand-logo-footer{max-height:88px;}',
            '.metis-shell-brand-mark{width:42px;height:42px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:var(--metis-color-primary,#485bc7);color:#fff;font-size:18px;font-weight:700;}',
            '.metis-shell-brand-text{display:flex;flex-direction:column;line-height:1.1;}',
            '.metis-shell-brand-name{font-size:18px;font-weight:700;color:var(--metis-color-text,#1a1f2b);}',
            '.metis-shell-brand-org{font-size:12px;color:var(--metis-color-muted,#64748b);}',
            '.metis-shell-nav{display:flex;align-items:center;}',
            '.metis-shell-nav-toggle{display:none;align-items:center;justify-content:center;gap:8px;min-width:44px;min-height:44px;padding:8px 12px;border:1px solid var(--metis-color-border,#d8deea);border-radius:10px;background:var(--metis-color-surface,#fff);color:var(--metis-color-text,#1a1f2b);font-weight:700;cursor:pointer;box-shadow:0 8px 18px rgba(15,23,42,.08);}',
            '.metis-shell-nav-toggle-lines{display:inline-grid;gap:4px;width:20px;}',
            '.metis-shell-nav-toggle-lines span{display:block;height:2px;border-radius:999px;background:currentColor;transition:transform .22s ease,opacity .18s ease;}',
            '.metis-shell-nav-toggle-text{font-size:.78rem;line-height:1;text-transform:uppercase;letter-spacing:.06em;}',
            '.metis-shell-menu-list,.metis-shell-menu-sub{list-style:none;margin:0;padding:0;}',
            '.metis-shell-menu-list{display:flex;flex-wrap:wrap;gap:8px var(--metis-menu-item-gap,14px);align-items:var(--metis-menu-item-align,center);}',
            '.metis-shell-menu-item{position:relative;z-index:1;}',
            '.metis-shell-menu-item.has-children{z-index:30;}',
            '.metis-shell-menu-item>.metis-shell-menu-link,.metis-shell-menu-item>.metis-shell-menu-btn{text-decoration:none;color:var(--metis-color-text,#1a1f2b);font-weight:500;font-size:var(--metis-menu-font-size,14px);display:inline-flex;align-items:var(--metis-menu-item-align,center);justify-content:center;gap:8px;padding:0 10px;min-height:40px;border:0;background:transparent;line-height:1;box-sizing:border-box;cursor:pointer;font-family:inherit;border-radius:var(--metis-menu-item-radius,10px);}',
            '.metis-shell-menu-item>.metis-shell-menu-link:hover,.metis-shell-menu-item>.metis-shell-menu-btn:hover{color:var(--metis-color-primary,#485bc7);}',
            '.metis-shell-menu-item>.metis-shell-menu-btn{padding:var(--metis-menu-button-padding-y,10px) var(--metis-menu-button-padding-x,14px);min-height:0;border-radius:var(--metis-menu-button-radius,10px);background:var(--metis-menu-button-bg,var(--metis-color-primary,#485bc7));color:var(--metis-menu-button-text,#fff) !important;font-weight:700;border:1px solid var(--metis-menu-button-border,transparent);line-height:1;-webkit-appearance:none;appearance:none;}',
            '.metis-shell-menu-item>.metis-shell-menu-btn:hover{filter:brightness(.96);}',
            '.metis-shell-menu-item>.metis-shell-menu-btn--metis_primary,.metis-shell-menu-item>.metis-shell-menu-btn--metis_accent,.metis-shell-menu-item>.metis-shell-menu-btn--metis_text,.metis-shell-menu-item>.metis-shell-menu-btn--metis_surface{background:var(--metis-menu-button-bg,var(--metis-color-primary,#485bc7));color:var(--metis-menu-button-text,#fff) !important;border:1px solid var(--metis-menu-button-border,transparent);}',
            '.metis-shell-menu-item>.metis-shell-menu-btn--metis_primary{--metis-menu-button-bg:var(--metis-color-primary,#485bc7);--metis-menu-button-text:var(--metis-color-button_text,#fff);--metis-menu-button-border:transparent;}',
            '.metis-shell-menu-item>.metis-shell-menu-btn--metis_accent{--metis-menu-button-bg:var(--metis-color-accent,#ff7542);--metis-menu-button-text:var(--metis-color-button_text,#fff);--metis-menu-button-border:transparent;}',
            '.metis-shell-menu-item>.metis-shell-menu-btn--metis_text{--metis-menu-button-bg:var(--metis-color-text,#1a1f2b);--metis-menu-button-text:var(--metis-color-bg,#fff);--metis-menu-button-border:transparent;}',
            '.metis-shell-menu-item>.metis-shell-menu-btn--metis_surface{--metis-menu-button-bg:var(--metis-color-surface,#fff);--metis-menu-button-text:var(--metis-color-text,#1a1f2b);--metis-menu-button-border:var(--metis-color-border,#d8deea);}',
            '.metis-shell-menu-label:not([tabindex]){cursor:default;}',
            '.metis-shell-menu-item.has-children>.metis-shell-menu-label{cursor:pointer;}',
            '.metis-shell-menu-sub-indicator{display:inline-flex;align-items:center;justify-content:center;width:6px;height:6px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg) translateY(-3px);opacity:.9;flex-shrink:0;}',
            '.metis-shell-menu-item.has-children:hover>.metis-shell-menu-sub,.metis-shell-menu-item.has-children:focus-within>.metis-shell-menu-sub,.metis-shell-menu-item.has-children.is-open>.metis-shell-menu-sub{opacity:1;visibility:visible;pointer-events:auto;transform:translateY(0) scale(1);}',
            '.metis-shell-menu-sub{display:block;position:absolute;left:0;top:calc(100% + 8px);z-index:1200;min-width:180px;background:#fff;border:1px solid var(--metis-color-border,#e2e8f0);padding:6px;border-radius:var(--metis-menu-dropdown-radius,10px);box-shadow:0 10px 24px rgba(2,8,23,.14);opacity:0;visibility:hidden;pointer-events:none;transform:translateY(var(--metis-menu-sub-open-y,-8px)) scale(var(--metis-menu-sub-open-scale,1));transform-origin:top left;transition:opacity var(--metis-menu-sub-open-duration,180ms) ease,transform var(--metis-menu-sub-open-duration,180ms) ease;}',
            '.metis-shell-menu-sub .metis-shell-menu-item{width:100%;}',
            '.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-link,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-btn{display:flex;align-items:var(--metis-menu-item-align,center);justify-content:flex-start;gap:8px;width:100%;box-sizing:border-box;padding:7px 10px;background:transparent;border-radius:8px;color:var(--metis-menu-dropdown-text,var(--metis-color-text,#1a1f2b));font-weight:var(--metis-menu-dropdown-weight,500);transition:background-color .18s ease,color .18s ease,transform .18s ease,box-shadow .18s ease,background-position .24s ease,background-size .24s ease;}',
            '.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-link:hover,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-btn:hover{background:var(--metis-menu-dropdown-highlight,var(--metis-color-surface_alt,#f8fafc));color:var(--metis-menu-dropdown-text,var(--metis-color-text,#1a1f2b));}',
            '.metis-shell-body{padding:16px max(16px,3vw);flex:1 0 auto;}',
            '.metis-shell-body-inner{max-width:1200px;margin:0 auto;}',
            '.metis-shell-body-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,320px);gap:24px;align-items:start;}',
            '.metis-shell-body-grid.sidebar-left .metis-shell-main{order:2;}',
            '.metis-shell-body-grid.sidebar-left .metis-shell-sidebar{order:1;}',
            '.metis-shell-main{min-width:0;}',
            '.metis-public-content{color:var(--metis-color-text,#1a1f2b);font-size:clamp(16px,1.1vw,18px);line-height:1.7;word-wrap:break-word;overflow-wrap:anywhere;}',
            '.metis-public-content::after{content:"";display:block;clear:both;}',
            '.metis-public-content > :first-child{margin-top:0 !important;}',
            '.metis-public-content > :last-child{margin-bottom:0 !important;}',
            '.metis-public-content h1,.metis-public-content h2,.metis-public-content h3,.metis-public-content h4,.metis-public-content h5,.metis-public-content h6{color:var(--metis-color-text,#1a1f2b);line-height:1.22;margin:0 0 .58em;font-weight:700;letter-spacing:-.01em;}',
            '.metis-public-content h1{font-size:clamp(2rem,4vw,3rem);}',
            '.metis-public-content h2{font-size:clamp(1.6rem,3vw,2.4rem);}',
            '.metis-public-content h3{font-size:clamp(1.25rem,2.2vw,1.8rem);}',
            '.metis-public-content p{margin:0 0 1em;}',
            '.metis-public-content ul,.metis-public-content ol{margin:0 0 1em;padding-left:1.35em;}',
            '.metis-public-content li{margin:0 0 .45em;}',
            '.metis-public-content li>ul,.metis-public-content li>ol,.metis-structured-text li>ul,.metis-structured-text li>ol{margin:.45em 0 0;}',
            '.metis-public-content ol ol,.metis-structured-text ol ol{list-style-type:lower-alpha;}',
            '.metis-public-content ol ol ol,.metis-structured-text ol ol ol{list-style-type:lower-roman;}',
            '.metis-public-content ul ul,.metis-structured-text ul ul{list-style-type:circle;}',
            '.metis-public-content ul ul ul,.metis-structured-text ul ul ul{list-style-type:square;}',
            '.metis-structured-text strong,.metis-structured-text b{font-weight:800!important;}',
            '.metis-structured-text em,.metis-structured-text i{font-style:italic!important;}',
            '.metis-structured-text u{text-decoration:underline!important;}',
            '.metis-structured-text s,.metis-structured-text strike{text-decoration:line-through!important;}',
            '.metis-structured-text .metis-text-weight-600{font-weight:600!important;}',
            '.metis-structured-text .metis-text-weight-700{font-weight:700!important;}',
            '.metis-structured-text .metis-text-weight-800{font-weight:800!important;}',
            '.metis-public-content blockquote{margin:1.2em 0;padding:.8em 1.1em;border-left:4px solid var(--metis-color-primary,#485bc7);background:var(--metis-color-surface_alt,#f8fafc);}',
            '.metis-public-content a{color:var(--metis-color-link,var(--metis-color-primary,#485bc7));text-underline-offset:2px;}',
            '.metis-public-content a:hover{color:var(--metis-color-link_hover,#3246a8);}',
            '.metis-public-content img,.metis-public-content svg,.metis-public-content video,.metis-public-content iframe{max-width:100%;height:auto;}',
            '.metis-public-content iframe{width:100%;min-height:360px;border:0;}',
            '.metis-public-content figure{margin:1em 0;}',
            '.metis-public-content figcaption{font-size:.9em;color:var(--metis-color-muted,#64748b);margin-top:.45em;}',
            'body.metis-public-site select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-image: none;background-position:calc(100% - 14px) calc(50% - 2px),calc(100% - 9px) calc(50% - 2px);background-size:5px 5px,5px 5px;background-repeat:no-repeat;padding-right:30px;}',
            '.metis-public-content table{width:100%;border-collapse:collapse;margin:1.2em 0;}',
            '.metis-public-content th,.metis-public-content td{border:1px solid var(--metis-color-border,#dbe3ef);padding:.55em .7em;text-align:left;vertical-align:top;}',
            '.metis-public-content .metis-transcript,.metis-structured-section__content .metis-transcript{display:grid;gap:14px;margin:1.8em auto;max-width:860px;width:min(100%,860px);justify-items:center;}',
            '.metis-public-content .metis-transcript__row,.metis-structured-section__content .metis-transcript__row{display:grid;gap:8px;justify-items:start;width:100%;}',
            '.metis-public-content .metis-transcript__row--right,.metis-structured-section__content .metis-transcript__row--right{justify-items:end;}',
            '.metis-public-content .metis-transcript__row--intro,.metis-public-content .metis-transcript__row--lead,.metis-structured-section__content .metis-transcript__row--intro,.metis-structured-section__content .metis-transcript__row--lead{justify-items:center;margin-bottom:6px;}',
            '.metis-public-content .metis-transcript__system,.metis-structured-section__content .metis-transcript__system{display:flex;justify-content:center;margin:2px 0 4px;}',
            '.metis-public-content .metis-transcript__system-pill,.metis-structured-section__content .metis-transcript__system-pill{display:inline-flex;align-items:center;justify-content:center;padding:.38rem .78rem;border-radius:999px;background:var(--metis-color-surface_alt,#f8fafc);border:1px solid var(--metis-color-border,#dbe3ef);color:var(--metis-color-muted,#64748b);font-size:.76rem;font-weight:700;letter-spacing:.02em;line-height:1.2;box-shadow:0 8px 18px rgba(15,23,42,.04);}',
            '.metis-public-content .metis-transcript__system-pill--cue,.metis-structured-section__content .metis-transcript__system-pill--cue{background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 8%,#ffffff);border-color:color-mix(in srgb,var(--metis-color-primary,#485bc7) 16%,transparent);}',
            '.metis-public-content .metis-transcript__speaker-column,.metis-public-content .metis-transcript__body-column,.metis-structured-section__content .metis-transcript__speaker-column,.metis-structured-section__content .metis-transcript__body-column{min-width:0;max-width:100%;width:100%;}',
            '.metis-public-content .metis-transcript__row--right .metis-transcript__speaker-column,.metis-public-content .metis-transcript__row--right .metis-transcript__body-column,.metis-structured-section__content .metis-transcript__row--right .metis-transcript__speaker-column,.metis-structured-section__content .metis-transcript__row--right .metis-transcript__body-column{text-align:right;}',
            '.metis-public-content .metis-transcript__speaker,.metis-structured-section__content .metis-transcript__speaker{display:inline-flex;align-items:center;max-width:100%;padding:.38rem .78rem;border-radius:999px;background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 10%,#ffffff);border:1px solid color-mix(in srgb,var(--metis-color-primary,#485bc7) 20%,#dbe3ef);font-size:.77rem;font-weight:800;letter-spacing:.08em;line-height:1.1;text-transform:uppercase;color:var(--metis-color-primary,#485bc7);box-shadow:0 8px 18px rgba(15,23,42,.05);}',
            '.metis-public-content .metis-transcript__body,.metis-structured-section__content .metis-transcript__body{display:inline-block;min-width:0;max-width:min(100%,72ch);padding:1rem 1.1rem;border:1px solid color-mix(in srgb,var(--metis-color-border,#dbe3ef) 60%,transparent);border-radius:18px 18px 18px 8px;background:color-mix(in srgb,var(--metis-color-surface,#ffffff) 24%,transparent);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);box-shadow:0 12px 28px rgba(15,23,42,.04);}',
            '.metis-public-content .metis-transcript__row--right .metis-transcript__body,.metis-structured-section__content .metis-transcript__row--right .metis-transcript__body{border-radius:18px 18px 8px 18px;border-color:color-mix(in srgb,var(--metis-color-primary,#485bc7) 28%,transparent);background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 16%,transparent);}',
            '.metis-public-content .metis-transcript__row--intro .metis-transcript__body,.metis-public-content .metis-transcript__row--lead .metis-transcript__body,.metis-structured-section__content .metis-transcript__row--intro .metis-transcript__body,.metis-structured-section__content .metis-transcript__row--lead .metis-transcript__body{max-width:min(100%,66ch);text-align:left;border-radius:28px;border-color:color-mix(in srgb,var(--metis-color-primary,#485bc7) 14%,transparent);background: var(--metis-surface, #fff);box-shadow:0 18px 36px rgba(15,23,42,.05);padding:1.2rem 1.25rem 1.25rem;}',
            '.metis-public-content .metis-transcript__body > *,.metis-structured-section__content .metis-transcript__body > *{background:transparent !important;border:0 !important;box-shadow:none !important;}',
            '.metis-public-content .metis-transcript__body p,.metis-structured-section__content .metis-transcript__body p{background:transparent !important;padding:0 !important;}',
            '.metis-public-content .metis-transcript__body>:first-child,.metis-structured-section__content .metis-transcript__body>:first-child{margin-top:0 !important;}',
            '.metis-public-content .metis-transcript__body>:last-child,.metis-structured-section__content .metis-transcript__body>:last-child{margin-bottom:0 !important;}',
            '.metis-public-content .metis-transcript__cue,.metis-structured-section__content .metis-transcript__cue{display:inline-flex;align-items:center;vertical-align:middle;margin:0 .35em 0 0;padding:.18rem .55rem;border-radius:999px;background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 8%,#ffffff);border:1px solid color-mix(in srgb,var(--metis-color-primary,#485bc7) 14%,transparent);color:var(--metis-color-muted,#64748b);font-size:.82em;font-weight:700;line-height:1.2;white-space:nowrap;}',
            '.metis-public-content .aligncenter{display:block;margin-left:auto;margin-right:auto;}',
            '.metis-public-content .alignleft{float:left;margin:.25em 1em .6em 0;}',
            '.metis-public-content .alignright{float:right;margin:.25em 0 .6em 1em;}',
            '.metis-public-content .wp-caption{max-width:100%;}',
            '.metis-public-content .metis-simple-grid{margin:1.2em 0;}',
            '.metis-hide-all{display:none !important;}',
            '@media (min-width:1025px){.metis-hide-desktop{display:none !important;}}',
            '@media (min-width:768px) and (max-width:1024px){.metis-hide-tablet{display:none !important;}}',
            '@media (max-width:767px){.metis-hide-mobile{display:none !important;}}',
            '@media (max-width:767px){.metis-public-content .metis-transcript,.metis-structured-section__content .metis-transcript{gap:12px;margin:1.35em auto;}.metis-public-content .metis-transcript__body,.metis-structured-section__content .metis-transcript__body{max-width:100%;padding:.92rem .95rem;border-radius:16px 16px 16px 8px;}.metis-public-content .metis-transcript__row--right .metis-transcript__body,.metis-structured-section__content .metis-transcript__row--right .metis-transcript__body{border-radius:16px 16px 8px 16px;}.metis-public-content .metis-transcript__row--intro .metis-transcript__body,.metis-public-content .metis-transcript__row--lead .metis-transcript__body,.metis-structured-section__content .metis-transcript__row--intro .metis-transcript__body,.metis-structured-section__content .metis-transcript__row--lead .metis-transcript__body{max-width:100%;border-radius:20px;padding:1rem 1.05rem;}.metis-public-content .metis-transcript__speaker,.metis-structured-section__content .metis-transcript__speaker{font-size:.72rem;}}',
            '.metis-shell-sidebar{min-width:0;}',
            '.metis-shell-footer{background:var(--metis-color-surface_alt,#f8fafc);border-top:0;margin-top:auto;}',
            '.metis-shell-footer-inner{max-width:1200px;margin:0 auto;padding:30px 24px;display:grid;grid-template-columns:minmax(180px,260px) minmax(0,1fr);gap:24px;align-items:start;}',
            '.metis-shell-footer-brand-stack{display:grid;gap:6px;align-content:start;}',
            '.metis-shell-footer-meta{font-size:13px;color:var(--metis-color-muted,#64748b);}',
            '.metis-shell-footer .metis-shell-menu-list{gap:8px 12px;}',
            '.metis-shell-footer-menu-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,180px));gap:18px;max-width:680px;}',
            '.metis-shell-footer-menu-title{font-size:18px;font-weight:700;color:var(--metis-color-primary,#485bc7);margin:0 0 12px;}',
            '.metis-shell-footer-menu-list{list-style:none;margin:0;padding:0;}',
            '.metis-shell-footer-menu-list li{margin:0 0 10px;}',
            '.metis-shell-footer-menu-list a{text-decoration:none;color:var(--metis-color-primary,#485bc7);font-size:15px;font-weight:500;}',
            '.metis-shell-footer-menu-list a:hover{text-decoration:underline;}',
            self::codepenGlideMenuCss( 'body.metis-menu-style-h_glide .metis-shell-nav-primary' ),
            self::markerDropdownMenuCss( 'body.metis-menu-style-h_marker_dropdown .metis-shell-nav-primary' ),
            self::pillDropdownMenuCss( 'body.metis-menu-style-h_pill_dropdown .metis-shell-nav-primary' ),
            self::modernBarMenuCss( 'body.metis-menu-style-h_modern_bar .metis-shell-nav-primary' ),
            self::menuButtonAttentionCss( '.metis-shell-nav-primary' ),
            'body.metis-layout-modern_split .metis-shell-header-inner{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;column-gap:24px;}',
            'body.metis-layout-modern_split .metis-shell-nav-primary .metis-shell-menu-list{justify-content:center;gap:8px 18px;}',
            'body.metis-layout-modern_split .metis-shell-nav-utility .metis-shell-menu-list{justify-content:flex-end;}',
            'body.metis-layout-centered_editorial .metis-shell-header{position:relative;background: var(--metis-surface, #fff);}',
            'body.metis-layout-centered_editorial .metis-shell-header-inner{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;column-gap:20px;padding-top:16px;padding-bottom:16px;}',
            'body.metis-layout-centered_editorial .metis-shell-header-row-main{display:contents;}',
            'body.metis-layout-centered_editorial .metis-shell-brand{grid-column:2;justify-content:center;}',
            'body.metis-layout-centered_editorial .metis-shell-nav-primary{grid-column:1;min-width:0;}',
            'body.metis-layout-centered_editorial .metis-shell-nav-primary .metis-shell-menu-list{justify-content:flex-end;gap:8px 14px;}',
            'body.metis-layout-centered_editorial .metis-shell-nav-utility{grid-column:3;}',
            'body.metis-layout-centered_editorial .metis-shell-nav-utility .metis-shell-menu-list{justify-content:flex-start;gap:8px 10px;}',
            'body.metis-layout-impact_campaign .metis-shell-header{background: var(--metis-surface, #fff);backdrop-filter:blur(8px);}',
            'body.metis-layout-impact_campaign .metis-shell-header-inner{max-width:1400px;display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;column-gap:24px;}',
            'body.metis-layout-impact_campaign .metis-shell-nav-primary .metis-shell-menu-list{justify-content:center;gap:10px 18px;}',
            'body.metis-layout-story_sidebar .metis-site-header{padding:0;}',
            'body.metis-layout-story_sidebar .metis-shell-header{position:fixed;left:0;top:0;bottom:0;width:286px;border-right:1px solid var(--metis-color-border,#e2e8f0);border-bottom:0;box-shadow:none;}',
            'body.metis-layout-story_sidebar .metis-shell-header-inner{max-width:none;height:100%;padding:26px 18px;display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;gap:18px;}',
            'body.metis-layout-story_sidebar .metis-shell-header-row-main{display:flex;flex-direction:column;align-items:stretch;gap:14px;}',
            'body.metis-layout-story_sidebar .metis-shell-nav-primary .metis-shell-menu-list{flex-direction:column;align-items:flex-start;gap:8px;width:100%;}',
            'body.metis-layout-story_sidebar .metis-shell-nav-utility{margin-top:auto;width:100%;}',
            'body.metis-layout-story_sidebar .metis-shell-nav-utility .metis-shell-menu-list{flex-direction:column;align-items:stretch;gap:8px;}',
            'body.metis-layout-story_sidebar .metis-shell-body{margin-left:286px;}',
            'body.metis-layout-story_sidebar .metis-site-footer{margin-left:286px;}',
            'body.metis-layout-story_sidebar .metis-shell-body-inner{max-width:1360px;}',
            'body.metis-layout-story_sidebar .metis-shell-body-grid{grid-template-columns:minmax(0,1fr) minmax(260px,340px);gap:34px;}',
            'body.metis-layout-story_sidebar .metis-shell-sidebar{position:sticky;top:116px;}',
            'body.metis-layout-minimal_focus .metis-shell-header{position:relative;background:transparent;border-bottom:0;}',
            'body.metis-layout-minimal_focus .metis-shell-header-inner{max-width:980px;padding-top:22px;padding-bottom:6px;}',
            'body.metis-layout-minimal_focus .metis-shell-nav-toggle{display:inline-flex;}',
            'body.metis-layout-minimal_focus .metis-shell-nav-primary{position:fixed;top:0;right:-320px;width:min(320px,88vw);height:100vh;background:var(--metis-color-surface,#fff);border-left:1px solid var(--metis-color-border,#e2e8f0);box-shadow:-12px 0 26px rgba(2,8,23,.14);padding:72px 20px 22px;z-index:1400;transition:right .24s ease;}',
            'body.metis-layout-minimal_focus .metis-shell-nav-primary .metis-shell-menu-list{flex-direction:column;align-items:flex-start;gap:8px;}',
            'body.metis-layout-minimal_focus.metis-nav-open .metis-shell-nav-primary{right:0;}',
            'body.metis-layout-minimal_focus.metis-nav-open::before{content:"";position:fixed;inset:0;background:rgba(2,8,23,.42);z-index:1300;}',
            'body.metis-layout-minimal_focus .metis-shell-footer{background:transparent;border-top:0;margin-top:56px;}',
            'body.metis-layout-minimal_focus .metis-shell-footer-inner{max-width:980px;padding-top:10px;padding-bottom:26px;}',
            'body.metis-layout-showcase_grid .metis-shell-header{position:relative;border-bottom:0;background: var(--metis-surface, #fff);}',
            'body.metis-layout-showcase_grid .metis-shell-header-inner{max-width:1440px;padding-top:18px;padding-bottom:18px;}',
            'body.metis-layout-showcase_grid .metis-shell-header-row-main{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:22px;align-items:center;}',
            'body.metis-layout-showcase_grid .metis-shell-nav-primary .metis-shell-menu-list{justify-content:center;gap:10px var(--metis-menu-item-gap,16px);}',
            'body.metis-layout-showcase_grid .metis-shell-nav-utility .metis-shell-menu-list{justify-content:flex-end;}',
            'body.metis-layout-showcase_grid .metis-shell-brand-logo-header{max-height:110px;}',
            self::stickyHeaderCss(),
            'body.metis-layout-showcase_grid .metis-shell-main{position:relative;}',
            'body.metis-layout-showcase_grid .metis-shell-main::before{content:"";position:absolute;inset:-22px -18px auto auto;width:220px;height:220px;background: var(--metis-surface, #fff);opacity:.14;pointer-events:none;}',
            'body.metis-layout-showcase_grid .metis-shell-footer{border-top:0;}',
            'body.metis-layout-showcase_grid .metis-shell-footer-inner{max-width:1440px;}',
            'body.metis-view-homepage.metis-layout-impact_campaign .metis-shell-body{padding-top:32px;padding-bottom:32px;}',
            'body.metis-view-homepage.metis-layout-impact_campaign .metis-shell-body-inner{max-width:1440px;}',
            'body.metis-view-homepage.metis-layout-showcase_grid .metis-shell-body-inner{max-width:1440px;}',
            'body.metis-view-post.metis-layout-story_sidebar .metis-public-content{font-size:clamp(17px,1.12vw,19px);line-height:1.75;}',
            'body.metis-view-post.metis-layout-minimal_focus .metis-public-content,body.metis-view-page.metis-layout-minimal_focus .metis-public-content{max-width:760px;margin:0 auto;}',
            '.metis-shell-body-variant-contained .metis-shell-body-inner{max-width:1200px;}',
            '.metis-shell-body-variant-centered .metis-shell-body-inner{max-width:1040px;}',
            '.metis-shell-body-variant-wide_home .metis-shell-body-inner{max-width:1360px;}',
            '.metis-shell-body-variant-reading .metis-shell-body-inner{max-width:1280px;}',
            '.metis-shell-body-variant-minimal .metis-shell-body-inner{max-width:960px;}',
            '.metis-shell-body-variant-showcase .metis-shell-body-inner{max-width:1440px;}',
            '.metis-shell-footer-variant-inline .metis-shell-footer-inner{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;}',
            '.metis-shell-footer-variant-inline .metis-shell-nav .metis-shell-menu-list{justify-content:center;}',
            '.metis-shell-footer-variant-minimal .metis-shell-footer-inner{display:block;text-align:center;}',
            '.metis-shell-footer-variant-minimal .metis-shell-brand{justify-content:center;margin:0 auto 10px;}',
            '.metis-shell-footer-variant-minimal .metis-shell-nav{justify-content:center;margin:0 auto 8px;}',
            '.metis-shell-footer-variant-minimal .metis-shell-footer-meta{text-align:center;}',
            '.metis-simple-grid{display:grid;gap:16px;margin:16px 0;}',
            '.metis-simple-grid-2{grid-template-columns:repeat(2,minmax(0,1fr));}',
            '.metis-simple-grid-3{grid-template-columns:repeat(3,minmax(0,1fr));}',
            '.metis-simple-grid-cell{border:1px dashed var(--metis-color-border,#dbe3ef);border-radius:10px;padding:12px;}',
            '.metis-structured-section{display:block;}',
            '.metis-structured-section__inner{width:100%;max-width:1200px;margin:0 auto;padding:48px 0;box-sizing:border-box;}',
            '.metis-structured-section__head{margin:0 0 20px;}',
            '.metis-structured-section__title{margin:0;font-size:clamp(1.4rem,2vw,2rem);line-height:1.2;}',
            '.metis-structured-section__subtext{margin:10px 0 0;opacity:.92;}',
            '.metis-structured-columns{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:18px;}',
            '.metis-structured-columns__col.is-w25{grid-column:span 3;}',
            '.metis-structured-columns__col.is-w33{grid-column:span 4;}',
            '.metis-structured-columns__col.is-w50{grid-column:span 6;}',
            '.metis-structured-columns__col.is-w100{grid-column:span 12;}',
            '.metis-structured-feature-grid{display:grid;gap:18px;}',
            '.metis-structured-feature-grid.cols-2{grid-template-columns:repeat(2,minmax(0,1fr));}',
            '.metis-structured-feature-grid.cols-3{grid-template-columns:repeat(3,minmax(0,1fr));}',
            '.metis-structured-feature-grid.cols-4{grid-template-columns:repeat(4,minmax(0,1fr));}',
            '.metis-structured-feature-grid__item{background:var(--metis-color-surface_alt,#f8fafc);border:1px solid var(--metis-color-border,#dbe3ef);border-radius:12px;padding:18px;}',
            '.metis-structured-feature-grid__title{margin:0 0 8px;font-size:1.1rem;}',
            '.metis-structured-feature-grid__text{margin:0 0 10px;}',
            '.metis-structured-cta{display:grid;gap:18px;}',
            '.metis-structured-cta--split{grid-template-columns:repeat(2,minmax(0,1fr));}',
            '.metis-structured-cta__item{background:var(--metis-color-surface_alt,#f8fafc);border:1px solid var(--metis-color-border,#dbe3ef);border-radius:12px;padding:20px;}',
            '.metis-structured-cta__title{margin:0 0 8px;font-size:1.2rem;}',
            '.metis-structured-cta__text{margin:0 0 12px;}',
            '.metis-structured-cta__button{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;background:var(--metis-color-primary,#485bc7);color:#fff;text-decoration:none;font-weight:600;}',
            '.metis-structured-section{display:grid;gap:0;}',
            '.metis-structured-section.is-bg-surface{background:var(--metis-color-surface,#ffffff);}',
            '.metis-structured-section.is-bg-muted{background:var(--metis-color-surface_alt,#f8fafc);}',
            '.metis-structured-section.is-bg-primary-tint{background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 8%,var(--metis-color-surface,#ffffff));}',
            '.metis-structured-section.is-bg-accent-tint{background:color-mix(in srgb,var(--metis-color-accent,#ff7542) 8%,var(--metis-color-surface,#ffffff));}',
            '.metis-structured-section__inner{width:100%;max-width:1200px;margin:0 auto;padding:34px 0 48px;box-sizing:border-box;}',
            '.metis-structured-section--posts-list > .metis-structured-section__inner{position:relative;left:50%;width:min(1200px,calc(100vw - 32px));max-width:calc(100vw - 32px);transform:translateX(-50%);}',
            '.metis-structured-section--posts-list .metis-structured-section__content{width:100%;}',
            '.metis-structured-section > .metis-structured-section__head{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;position:relative;left:50%;width:100vw;max-width:none;min-height:120px;margin:0 0 10px -50vw;padding:35px 24px;border:0;border-radius:0;box-shadow:none;text-align:center;box-sizing:border-box;}',
            '.metis-structured-section__title{margin:0;font-size:clamp(1.9rem,2.5vw,2.9rem);line-height:1.08;}',
            '.metis-structured-section__subtext{margin:0 auto;max-width:70ch;color:var(--metis-color-muted,#64748b);font-size:1rem;line-height:1.65;}',
            '.metis-structured-heading-wrap{min-height:56px;display:grid;align-items:start;}',
            '.metis-structured-heading-wrap.is-valign-middle{align-items:center;}',
            '.metis-structured-heading-wrap.is-valign-bottom{align-items:end;}',
            '.metis-structured-section--heading-band.is-bg-surface,.metis-structured-section--heading-band.is-bg-muted,.metis-structured-section--heading-band.is-bg-primary-tint,.metis-structured-section--heading-band.is-bg-accent-tint{background:transparent;}',
            '.metis-structured-section--heading-band > .metis-structured-section__inner{max-width:none;padding:0;}',
            '.metis-structured-section--heading-band .metis-structured-section__content{width:100%;}',
            '.metis-structured-heading-wrap.is-section-header{position:relative;left:50%;width:100vw;max-width:none;min-height:132px;margin:0 0 0 -50vw;padding:42px 24px;box-sizing:border-box;align-items:center;justify-items:center;background:var(--metis-color-surface_alt,#f1f2f5);}',
            '.metis-structured-section--heading-band.is-bg-surface .metis-structured-heading-wrap.is-section-header{background:var(--metis-color-surface,#ffffff);}',
            '.metis-structured-section--heading-band.is-bg-muted .metis-structured-heading-wrap.is-section-header{background:var(--metis-color-surface_alt,#f8fafc);}',
            '.metis-structured-section--heading-band.is-bg-primary-tint .metis-structured-heading-wrap.is-section-header{background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 8%,var(--metis-color-surface,#ffffff));}',
            '.metis-structured-section--heading-band.is-bg-accent-tint .metis-structured-heading-wrap.is-section-header{background:color-mix(in srgb,var(--metis-color-accent,#ff7542) 8%,var(--metis-color-surface,#ffffff));}',
            '.metis-structured-heading-wrap.is-section-header .metis-structured-heading{width:min(1200px,100%);margin:0;text-align:center;color:var(--metis-color-primary,#485bc7);font-size:clamp(2rem,3vw,3rem);}',
            '.metis-structured-heading{width:100%;margin:0 0 .7em;line-height:1.12;letter-spacing:0;color:var(--metis-color-text,#1a1f2b);}',
            '.metis-structured-heading.is-align-left{text-align:left;}',
            '.metis-structured-heading.is-align-center{text-align:center;}',
            '.metis-structured-heading.is-align-right{text-align:right;}',
            '.metis-structured-image{display:grid;gap:10px;justify-items:center;margin:0;}',
            '.metis-structured-image img{display:block;width:100%;max-width:min(920px,100%);height:auto;border-radius:16px;border:1px solid var(--metis-color-border,#dbe3ef);}',
            '.metis-structured-image figcaption{max-width:72ch;text-align:center;color:var(--metis-color-muted,#64748b);}',
            '.metis-structured-button-row{display:flex;margin:0;}',
            '.metis-structured-button-row.is-center{justify-content:center;}',
            '.metis-structured-button-row.is-right{justify-content:flex-end;}',
            '.metis-structured-button{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border-radius:10px;background:var(--metis-color-primary,#485bc7);color:var(--metis-color-button_text,#fff) !important;text-decoration:none;font-weight:700;border:1px solid transparent;}',
            '.metis-structured-button:hover{background:var(--metis-color-primary_dark,#3246a7);color:var(--metis-color-button_text,#fff) !important;text-decoration:none;}',
            '.metis-structured-hero-block{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(220px,.95fr);gap:24px;align-items:center;padding:26px;border:1px solid var(--metis-color-border,#dbe3ef);border-radius:16px;background:var(--metis-color-surface_alt,#f8fafc);}',
            '.metis-structured-hero-block__copy{display:grid;gap:12px;align-content:center;}',
            '.metis-structured-hero-block__copy h1{margin:0;}',
            '.metis-structured-hero-block__copy p{margin:0;color:var(--metis-color-muted,#64748b);}',
            '.metis-structured-hero-block__media{margin:0;}',
            '.metis-structured-hero-block__media img{display:block;width:100%;height:auto;border-radius:14px;border:1px solid var(--metis-color-border,#dbe3ef);}',
            '.metis-structured-html{min-width:0;}',
            '.metis-structured-text .metis-inline-image{margin:18px auto;display:grid;justify-items:center;gap:10px;max-width:100%;}',
            '.metis-structured-text .metis-inline-image .metis-inline-image{margin:0;}',
            '.metis-structured-text .metis-inline-image > img{display:block;width:auto;max-width:min(720px,100%);height:auto;border-radius:16px;border:1px solid var(--metis-color-border,#dbe3ef);box-shadow:0 18px 40px rgba(15,23,42,.08);}',
            '.metis-structured-text .metis-inline-image.is-small > img{max-width:min(280px,100%);}',
            '.metis-structured-text .metis-inline-image.is-medium > img{max-width:min(520px,100%);}',
            '.metis-structured-text .metis-inline-image.is-large > img{max-width:min(720px,100%);}',
            '.metis-structured-text .metis-inline-image.is-full > img{max-width:100%;}',
            '.metis-structured-section__content .metis-inline-image{margin:18px auto;display:grid;justify-items:center;gap:10px;max-width:100%;}',
            '.metis-structured-section__content .metis-inline-image .metis-inline-image{margin:0;}',
            '.metis-structured-section__content .metis-inline-image > img{display:block;width:auto;max-width:min(720px,100%);height:auto;border-radius:16px;border:1px solid var(--metis-color-border,#dbe3ef);box-shadow:0 18px 40px rgba(15,23,42,.08);}',
            '.metis-structured-section__content .metis-inline-image.is-small{max-width:min(280px,100%);}',
            '.metis-structured-section__content .metis-inline-image.is-medium{max-width:min(520px,100%);}',
            '.metis-structured-section__content .metis-inline-image.is-large{max-width:min(720px,100%);}',
            '.metis-structured-section__content .metis-inline-image.is-full{max-width:100%;}',
            '.metis-structured-section__content .metis-inline-image.is-small > img{max-width:100%;}',
            '.metis-structured-section__content .metis-inline-image.is-medium > img{max-width:100%;}',
            '.metis-structured-section__content .metis-inline-image.is-large > img{max-width:100%;}',
            '.metis-structured-section__content .metis-inline-image.is-full > img{max-width:100%;}',
            '.metis-structured-text .metis-text-size-sm{font-size:.92rem;}',
            '.metis-structured-text .metis-text-size-lg{font-size:1.12rem;}',
            '.metis-structured-text .metis-text-size-xl{font-size:1.28rem;}',
            '.metis-structured-text .metis-text-color-metis_primary{color:var(--metis-primary,#485bc7)!important;}',
            '.metis-structured-text .metis-text-color-metis_primary_dark{color:var(--metis-primary-dark,#3246a7)!important;}',
            '.metis-structured-text .metis-text-color-metis_accent{color:var(--metis-accent,#ff7542)!important;}',
            '.metis-structured-text .metis-text-color-metis_bg{color:var(--metis-bg,#f5f6fa)!important;}',
            '.metis-structured-text .metis-text-color-metis_surface{color:var(--metis-surface,#ffffff)!important;}',
            '.metis-structured-text .metis-text-color-metis_border{color:var(--metis-border,#e0e2ea)!important;}',
            '.metis-structured-text .metis-text-color-metis_text{color:var(--metis-text,#1f2330)!important;}',
            '.metis-structured-text .metis-text-color-metis_text_muted{color:var(--metis-text-muted,#6d7485)!important;}',
            '.metis-structured-text .metis-text-color-metis_header_bg{color:var(--metis-header-bg,#eceeff)!important;}',
            '.metis-structured-text .metis-text-color-metis_row_odd_bg{color:var(--metis-row-odd-bg,#ffffff)!important;}',
            '.metis-structured-text .metis-text-color-metis_row_even_bg{color:var(--metis-row-even-bg,#f8f9fd)!important;}',
            '.metis-structured-text .metis-text-color-metis_row_hover_bg{color:var(--metis-row-hover-bg,#eef2ff)!important;}',
            '.metis-structured-text .metis-text-color-metis_sidebar_bg{color:var(--metis-sidebar-bg,#16192b)!important;}',
            '.metis-structured-text .metis-text-color-metis_sidebar_icon_color{color:var(--metis-sidebar-icon-color,#7a82a6)!important;}',
            '.metis-structured-text .metis-text-color-metis_sidebar_active_color{color:var(--metis-sidebar-active-color,#a8b4ff)!important;}',
            '.metis-public-content .metis-text-align-left,.metis-structured-section__content .metis-text-align-left{text-align:left;}',
            '.metis-public-content .metis-text-align-center,.metis-structured-section__content .metis-text-align-center{text-align:center;}',
            '.metis-public-content .metis-text-align-right,.metis-structured-section__content .metis-text-align-right{text-align:right;}',
            '.metis-structured-events,.metis-structured-posts-list{display:grid;gap:16px;}',
            '.metis-structured-events{grid-template-columns:repeat(auto-fit,minmax(240px,320px));justify-content:center;align-items:stretch;overflow:visible;padding:6px 0 14px;}',
            '.metis-structured-posts-list--cards{width:100%;grid-template-columns:repeat(4,minmax(0,1fr));column-gap:18px;row-gap:34px;justify-content:stretch;align-items:stretch;padding:4px 0 12px;}',
            '@media (max-width:1100px){.metis-structured-posts-list--cards{grid-template-columns:repeat(2,minmax(0,1fr));}}',
            '@media (max-width:640px){.metis-structured-posts-list--cards{grid-template-columns:1fr;}}',
            '.metis-structured-events__item{background: var(--metis-surface, #fff);border:1px solid color-mix(in srgb,var(--metis-color-accent,#ff7542) 42%, var(--metis-color-border,#dbe3ef));border-radius:22px;padding:20px;box-shadow:0 14px 28px rgba(15,23,42,.06);backdrop-filter:blur(8px);}',
            '.metis-structured-posts-list__item{min-width:0;height:100%;display:flex;flex-direction:column;background: var(--metis-surface, #fff);border:1px solid color-mix(in srgb,var(--metis-color-accent,#ff7542) 36%, var(--metis-color-border,#dbe3ef));border-radius:14px;padding:14px;box-shadow:0 8px 18px rgba(15,23,42,.05);}',
            '.metis-structured-events__eyebrow{font-size:.72rem;letter-spacing:.12em;text-transform:uppercase;font-weight:700;color:var(--metis-color-accent,#ff7542);margin:0 0 10px;}',
            '.metis-structured-events__title{margin:0 0 8px;font-size:1.08rem;line-height:1.3;}',
            '.metis-public-content .metis-structured-posts-list__title,.metis-structured-section__content .metis-structured-posts-list__title{margin:0 0 7px;font-size:1rem;line-height:1.28;letter-spacing:0;}',
            '.metis-structured-posts-list__media{display:block;margin:-14px -14px 12px;}',
            '.metis-structured-posts-list__media img{display:block;width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:14px 14px 0 0;border-bottom:1px solid color-mix(in srgb,var(--metis-color-border,#dbe3ef) 70%, transparent);}',
            '.metis-public-content .metis-structured-posts-list__byline,.metis-public-content .metis-structured-posts-list__categories,.metis-structured-section__content .metis-structured-posts-list__byline,.metis-structured-section__content .metis-structured-posts-list__categories{margin:0 0 8px;color:var(--metis-color-muted,#64748b);font-size:.82rem;line-height:1.4;}',
            '.metis-structured-posts-list__byline span,.metis-structured-posts-list__categories span{color:var(--metis-color-primary,#485bc7);font-weight:600;}',
            '.metis-structured-posts-list__byline-sep{color:var(--metis-color-text,#0f172a);font-weight:400;}',
            '.metis-structured-events__meta{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 14px;}',
            '.metis-structured-events__chip{display:grid;gap:2px;min-width:120px;padding:10px 12px;border-radius:14px;background:color-mix(in srgb,var(--metis-color-surface_alt,#f8fafc) 72%, transparent);border:1px solid color-mix(in srgb,var(--metis-color-primary,#485bc7) 16%, var(--metis-color-border,#dbe3ef));}',
            '.metis-structured-events__chip strong{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--metis-color-primary,#485bc7);}',
            '.metis-structured-events__chip span{font-size:.95rem;color:var(--metis-color-text,#0f172a);}',
            '.metis-structured-events__location{margin:0 0 16px;display:grid;gap:6px;color:var(--metis-color-text,#0f172a);line-height:1.6;}',
            '.metis-structured-events__location span{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--metis-color-primary,#485bc7);font-weight:700;}',
            '.metis-structured-events__date,.metis-structured-posts-list__date{display:block;color:var(--metis-color-muted,#64748b);font-size:.92rem;margin-bottom:8px;}',
            '.metis-structured-events__cta{margin:0;padding-top:4px;}',
            '.metis-structured-events__cta a{text-decoration:none;color:var(--metis-color-primary,#485bc7);font-weight:700;}',
            '.metis-structured-events--empty,.metis-structured-posts-list--empty{color:var(--metis-color-muted,#64748b);}',
            '.metis-public-content .metis-structured-posts-list__excerpt,.metis-structured-section__content .metis-structured-posts-list__excerpt{font-size:.9rem;line-height:1.48;}',
            '.metis-public-content .metis-structured-posts-list__excerpt p,.metis-structured-section__content .metis-structured-posts-list__excerpt p{margin:0 0 8px;}',
            '.metis-public-content .metis-structured-posts-list__cta,.metis-structured-section__content .metis-structured-posts-list__cta{margin:auto 0 0;padding-top:8px;font-size:.9rem;line-height:1.35;}',
            '.metis-structured-posts-list__cta a{text-decoration:none;color:var(--metis-color-primary,#485bc7);font-weight:600;}',
            '.metis-structured-spacer{display:block;width:100%;}',
            '.metis-structured-spacer.is-small{height:24px;}',
            '.metis-structured-spacer.is-medium{height:48px;}',
            '.metis-structured-spacer.is-large{height:72px;}',
            '.metis-site-header,.metis-site-footer{padding:16px max(16px, 3vw);background:var(--metis-color-surface,#ffffff);}',
            '.metis-site-content{padding:12px max(16px, 3vw);}',
            '.metis-site-content-inner{width:100%;}',
            '.metis-fluid-root{position:relative;width:100%;}',
            '.metis-fluid-root > *{box-sizing:border-box;}',
            '.metis-block-text p{margin:0 0 .8em;}',
            '.metis-block-heading{margin:0 0 .6em;}',
            '.metis-block-grid{width:100%;}',
            '.metis-block-image img{border-radius:8px;}',
            '.metis-btn,.metis-block-button .metis-btn{display:inline-flex;align-items:center;gap:8px;text-decoration:none;}',
            '.metis-block-form,.metis-block-donation-form{background:transparent;border:0;box-shadow:none;padding:0;}',
            '.metis-block-form form,.metis-block-donation-form form{background:transparent;border:0;box-shadow:none;padding:0;}',
            '.metis-block-form input,.metis-block-form select,.metis-block-form textarea,.metis-block-form button,.metis-block-donation-form input,.metis-block-donation-form select,.metis-block-donation-form textarea,.metis-block-donation-form button{font:inherit;color:inherit;}',
            '.metis-blog-index,.metis-404,.metis-post-excerpt{max-width:900px;margin:0 auto;}',
            '.metis-donation-progress{display:block;width:100%;}',
            '.metis-donation-progress-total,.metis-donation-progress-goal{margin:0 0 var(--metis-space-xs,6px);color:var(--metis-color-text,#1a1f2b);}',
            '.metis-donation-progress-goal{margin-bottom:var(--metis-space-sm,10px);}',
            '.metis-donation-progress-track{position:relative;display:block;width:100%;min-height:40px;border-radius:999px;overflow:hidden;background:var(--metis-token-donation-track,var(--metis-color-accent,#ff7542));}',
            '.metis-donation-progress-fill{position:absolute;inset:0 auto 0 0;display:block;height:100%;border-radius:inherit;background:var(--metis-token-donation-fill,var(--metis-color-primary,#485bc7));}',
            '.metis-donation-progress-label{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:0 14px;color:var(--metis-token-donation-label,var(--metis-color-button_text,#ffffff));font-weight:700;line-height:1.2;text-shadow:0 1px 2px rgba(15,23,42,.18);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;pointer-events:none;}',
            '.metis-public-popup[hidden]{display:none !important;}',
            '.metis-public-popup{position:fixed;inset:0;z-index:1300;display:flex;align-items:center;justify-content:center;}',
            '.metis-public-popup__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.62);}',
            '.metis-public-popup__dialog{position:relative;background:#fff;color:#0f172a;max-width:min(720px,92vw);max-height:86vh;overflow:auto;border-radius:12px;padding:24px 20px;box-shadow:0 20px 60px rgba(2,6,23,.35);}',
            '.metis-public-popup__close{position:absolute;top:8px;right:10px;border:0;background:transparent;font-size:30px;line-height:1;cursor:pointer;color:#334155;}',
            '.metis-public-banners{position:sticky;top:0;z-index:1200;display:flex;flex-direction:column;gap:1px;}',
            '.metis-public-banner{display:flex;align-items:center;gap:12px;justify-content:center;padding:12px 16px;background:#0f172a;color:#fff;font:500 14px/1.4 system-ui,sans-serif;}',
            '.metis-banner-announcement_bar{background:#1d4ed8;}',
            '.metis-banner-inline{background:#334155;}',
            '.metis-public-banner__content{max-width:980px;}',
            '.metis-public-banner__cta{display:inline-flex;align-items:center;padding:5px 10px;border-radius:999px;background:#fff;color:#0f172a;text-decoration:none;font-weight:700;}',
            '.metis-public-banner__dismiss{margin-left:8px;border:0;background:transparent;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:0 4px;}',
            '@media (max-width: 980px){.metis-shell-header-inner{padding:12px 16px;flex-wrap:wrap;display:flex !important;}.metis-shell-menu-list{gap:8px 10px;}.metis-shell-body-grid{grid-template-columns:1fr;}.metis-simple-grid-2,.metis-simple-grid-3{grid-template-columns:1fr;}.metis-shell-brand-logo-header{max-height:88px;}.metis-shell-brand-logo-footer{max-height:70px;}.metis-shell-footer-menu-grid{grid-template-columns:1fr;}.metis-shell-nav-utility{width:100%;}.metis-shell-nav-utility .metis-shell-menu-list{justify-content:flex-start;}.metis-shell-footer-variant-inline .metis-shell-footer-inner{display:block;}body.metis-layout-centered_editorial .metis-shell-header-inner{display:flex !important;}body.metis-layout-centered_editorial .metis-shell-header-row-main{display:flex;flex-wrap:wrap;align-items:center;gap:12px;width:100%;}body.metis-layout-centered_editorial .metis-shell-brand{order:1;margin-right:auto;}body.metis-layout-centered_editorial .metis-shell-nav-primary{order:3;width:100%;}body.metis-layout-centered_editorial .metis-shell-nav-primary .metis-shell-menu-list{justify-content:flex-start;}body.metis-layout-centered_editorial .metis-shell-nav-utility{order:2;margin-left:auto;}body.metis-layout-story_sidebar .metis-shell-header{position:relative;width:100%;height:auto;left:auto;top:auto;bottom:auto;border-right:0;border-bottom:1px solid var(--metis-color-border,#e2e8f0);}body.metis-layout-story_sidebar .metis-shell-header-inner{height:auto;padding:12px 16px;}body.metis-layout-story_sidebar .metis-shell-header-row-main{flex-direction:row;flex-wrap:wrap;}body.metis-layout-story_sidebar .metis-shell-body,body.metis-layout-story_sidebar .metis-site-footer{margin-left:0;}body.metis-layout-story_sidebar .metis-shell-nav-primary .metis-shell-menu-list{flex-direction:row;flex-wrap:wrap;}body.metis-layout-minimal_focus .metis-shell-nav-toggle{display:inline-flex;}body.metis-layout-minimal_focus .metis-shell-nav-primary{width:min(320px,90vw);}body.metis-layout-showcase_grid .metis-shell-header-row-main{display:flex;flex-wrap:wrap;}body.metis-layout-showcase_grid .metis-shell-nav-primary{width:100%;}}',
            self::mobileFlyoutMenuCss(),
            self::mobileFlyoutMenuHardeningCss(),
            '@media (max-width: 900px){.metis-block-grid{grid-template-columns:1fr !important;}.metis-structured-columns{grid-template-columns:1fr;}.metis-structured-columns__col.is-w25,.metis-structured-columns__col.is-w33,.metis-structured-columns__col.is-w50,.metis-structured-columns__col.is-w100{grid-column:1/-1;}.metis-structured-feature-grid.cols-2,.metis-structured-feature-grid.cols-3,.metis-structured-feature-grid.cols-4,.metis-structured-cta--split,.metis-structured-hero-block{grid-template-columns:1fr;}.metis-structured-hero-block{padding:18px;}}',
        ] );
    }

    private static function menuButtonAttentionCss( string $scope ): string {
        $scope = trim( $scope );
        if ( $scope === '' ) {
            return '';
        }

        $button = $scope . ' .metis-shell-menu-item > .metis-shell-menu-btn';
        return implode( "\n", [
            $button . '{position:relative !important;isolation:isolate;min-width:max-content !important;padding:.78rem 1.16rem !important;background:var(--metis-menu-button-bg,var(--metis-color-accent,#ff7542)) !important;color:var(--metis-menu-button-text,#fff) !important;border:1px solid var(--metis-menu-button-border,rgba(255,255,255,.38)) !important;border-radius:999px !important;box-shadow:0 12px 26px color-mix(in srgb,var(--metis-menu-button-bg,var(--metis-color-accent,#ff7542)) 34%,transparent),inset 0 0 0 1px rgba(255,255,255,.22) !important;font-weight:800 !important;opacity:1 !important;}',
            $button . '::before{content:"";position:absolute;inset:2px;border-radius:inherit;background:linear-gradient(180deg,rgba(255,255,255,.22),rgba(255,255,255,0));z-index:-1;pointer-events:none;}',
            $button . ':hover,' . $button . ':focus-visible{filter:none !important;transform:translateY(-1px);box-shadow:0 16px 30px color-mix(in srgb,var(--metis-menu-button-bg,var(--metis-color-accent,#ff7542)) 40%,transparent),inset 0 0 0 1px rgba(255,255,255,.28) !important;}',
        ] );
    }

    private static function stickyHeaderCss(): string {
        return implode( "\n", [
            '.metis-shell-header{position:sticky !important;top:0;z-index:1000;background:var(--metis-color-surface,#fff);overflow:visible;transition:padding .22s ease,box-shadow .22s ease,background-color .22s ease;}',
            'body.metis-public-site .metis-template{padding-top:var(--metis-fixed-header-space,118px);}',
            'body.metis-public-site .metis-template .metis-template-sticky-capable{position:fixed !important;top:0;left:0;right:0;width:100%;z-index:1000;padding:12px max(16px,calc((100vw - 1320px) / 2)) !important;box-sizing:border-box;background:var(--metis-color-surface,#fff);overflow:visible;transition:padding .22s ease,box-shadow .22s ease,background-color .22s ease;}',
            'body.metis-public-site .metis-template .metis-template-sticky-capable::before{left:0;right:0;}',
            'body.metis-public-site .metis-template .metis-template-header-inner{max-width:1320px;margin:0 auto;min-width:0;width:100%;gap:48px !important;}',
            'body.metis-public-site .metis-template .metis-template-header-brand{flex:0 0 auto;min-width:0;}',
            'body.metis-public-site .metis-template .metis-template-menu{flex:1 1 auto;min-width:0;}',
            '.metis-shell-header-inner,.metis-template .metis-template-header-inner{transition:padding .22s ease,gap .22s ease;}',
            '.metis-shell-brand-logo-header,.metis-template .metis-template-brand-logo{transition:max-height .22s ease;}',
            '.metis-shell-header.is-scrolled,.metis-template .metis-template-sticky-capable.is-scrolled{box-shadow:0 12px 28px rgba(15,23,42,.12);}',
            '.metis-shell-header.is-scrolled .metis-shell-header-inner{padding-top:7px;padding-bottom:7px;}',
            '.metis-shell-header.is-scrolled .metis-shell-brand-logo-header{max-height:64px;}',
            'body.metis-public-site .metis-template .metis-template-sticky-capable.is-scrolled{padding-top:6px !important;padding-bottom:6px !important;}',
            'body.metis-public-site .metis-template .metis-template-sticky-capable.is-scrolled .metis-template-brand-logo{max-height:54px;}',
            'body.metis-public-site .metis-template .metis-template-sticky-capable.is-scrolled .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-link,body.metis-public-site .metis-template .metis-template-sticky-capable.is-scrolled .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn{min-height:34px;padding-top:1.05rem !important;padding-bottom:1.05rem !important;}',
            '@media (max-width: 980px){body.metis-public-site .metis-template{padding-top:92px;}body.metis-public-site .metis-template .metis-template-sticky-capable{padding:10px 16px !important;}}',
        ] );
    }

    private static function mobileFlyoutMenuCss(): string {
        return '@media (max-width: 980px){body.metis-nav-mobile-viewport .metis-shell-header-inner,body.metis-nav-mobile-viewport .metis-template .metis-template-header-inner{display:grid !important;grid-template-columns:minmax(0,1fr) auto !important;align-items:center !important;gap:12px !important;padding-right:62px !important;}body.metis-nav-mobile-viewport .metis-shell-nav-toggle{display:inline-flex !important;position:fixed !important;top:14px;right:14px;z-index:1502;}body.metis-nav-open .metis-shell-nav-toggle-lines span:nth-child(1){transform:translateY(6px) rotate(45deg);}body.metis-nav-open .metis-shell-nav-toggle-lines span:nth-child(2){opacity:0;}body.metis-nav-open .metis-shell-nav-toggle-lines span:nth-child(3){transform:translateY(-6px) rotate(-45deg);}body.metis-nav-mobile-viewport .metis-shell-nav-primary{position:fixed !important;top:0 !important;right:0 !important;bottom:0 !important;left:auto !important;z-index:1500 !important;width:min(360px,86vw) !important;max-width:86vw !important;height:auto !important;margin:0 !important;padding:76px 18px 24px !important;border:0 !important;border-left:1px solid var(--metis-color-border,#d8deea) !important;border-radius:0 !important;background:var(--metis-color-surface,#fff) !important;color:var(--metis-color-text,#1a1f2b) !important;box-shadow:-22px 0 46px rgba(2,8,23,.24) !important;overflow-y:auto !important;overflow-x:hidden !important;visibility:hidden;pointer-events:none;transform:translateX(105%);transition:transform .28s cubic-bezier(.2,.8,.2,1),visibility .28s ease;}body.metis-nav-mobile-viewport.metis-nav-open .metis-shell-nav-primary{visibility:visible;pointer-events:auto;transform:translateX(0);}body.metis-nav-mobile-viewport.metis-nav-open::before{content:"";position:fixed;inset:0;z-index:1490;background:rgba(15,23,42,.48);}body.metis-nav-mobile-viewport .metis-shell-nav-primary > .metis-shell-menu-list{display:flex !important;flex-direction:column !important;flex-wrap:nowrap !important;gap:0 !important;align-items:stretch !important;justify-content:flex-start !important;width:100% !important;min-width:0 !important;max-width:none !important;height:auto !important;margin:0 !important;padding:0 !important;overflow:visible !important;}body.metis-nav-mobile-viewport .metis-shell-nav-primary > .metis-shell-menu-list::after,body.metis-nav-mobile-viewport .metis-shell-nav-primary > .metis-shell-menu-list > .metis-shell-menu-item:last-child::after{display:none !important;}body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item{display:flex !important;flex:0 0 auto !important;flex-direction:column !important;align-items:stretch !important;width:100% !important;min-width:0 !important;height:auto !important;}body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item > .metis-shell-menu-link,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item > .metis-shell-menu-btn{display:flex !important;align-items:center !important;justify-content:flex-start !important;width:100% !important;min-width:0 !important;height:auto !important;min-height:48px !important;padding:1rem 2.75rem 1rem 1rem !important;border:0 !important;border-radius:0 !important;background:transparent !important;box-shadow:none !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:1rem !important;font-weight:750 !important;text-align:left !important;text-shadow:none !important;text-transform:none !important;white-space:normal !important;opacity:1 !important;}body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item > .metis-shell-menu-btn{background:var(--metis-menu-button-bg,var(--metis-color-accent,#ff7542)) !important;color:var(--metis-menu-button-text,#fff) !important;border-radius:12px !important;margin:.35rem 0 !important;justify-content:center !important;padding:1rem !important;box-shadow:0 12px 24px color-mix(in srgb,var(--metis-menu-button-bg,var(--metis-color-accent,#ff7542)) 32%,transparent) !important;}body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub{position:static !important;display:block !important;width:100% !important;min-width:0 !important;max-height:0 !important;margin:0 !important;padding:0 !important;border:0 !important;border-radius:0 !important;background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 8%,var(--metis-color-surface,#fff)) !important;box-shadow:none !important;visibility:visible !important;opacity:1 !important;pointer-events:auto !important;transform:none !important;overflow:hidden !important;transition:max-height .24s ease;}body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item.is-open > .metis-shell-menu-sub,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item:hover > .metis-shell-menu-sub,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item:focus-within > .metis-shell-menu-sub{max-height:32rem !important;}body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub .metis-shell-menu-item,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub .metis-shell-menu-link,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub .metis-shell-menu-btn{transform:none !important;transition:none !important;}body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub .metis-shell-menu-link,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub .metis-shell-menu-btn{padding:.9rem 1rem .9rem 1.5rem !important;background:transparent !important;color:var(--metis-color-text,#1a1f2b) !important;justify-content:flex-start !important;text-align:left !important;box-shadow:none !important;}}';
    }

    private static function mobileFlyoutMenuHardeningCss(): string {
        return '@media (max-width: 980px){'
            . 'body.metis-nav-mobile-viewport.metis-nav-open::before{display:none !important;content:none !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-toggle{display:inline-flex !important;position:fixed !important;top:14px !important;right:14px !important;z-index:3100 !important;border-radius:999px !important;background:var(--metis-color-primary,#485bc7) !important;color:#fff !important;border:1px solid color-mix(in srgb,var(--metis-color-primary,#485bc7) 72%,#fff) !important;box-shadow:0 12px 26px rgba(15,23,42,.18) !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-toggle-lines span{background:currentColor !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary{position:fixed !important;top:0 !important;right:0 !important;bottom:0 !important;left:auto !important;z-index:3000 !important;width:min(360px,88vw) !important;max-width:88vw !important;height:100dvh !important;margin:0 !important;padding:84px 18px 24px !important;border:0 !important;border-left:1px solid var(--metis-color-border,#d8deea) !important;border-radius:0 !important;background:var(--metis-color-surface,#fff) !important;color:var(--metis-color-text,#1a1f2b) !important;box-shadow:-18px 0 40px rgba(15,23,42,.18) !important;opacity:1 !important;visibility:hidden !important;pointer-events:none !important;transform:translateX(104%) !important;overflow-y:auto !important;overflow-x:hidden !important;filter:none !important;isolation:isolate !important;}'
            . 'body.metis-nav-mobile-viewport.metis-nav-open .metis-shell-nav-primary{visibility:visible !important;pointer-events:auto !important;transform:translateX(0) !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary > .metis-shell-menu-list{display:flex !important;flex-direction:column !important;flex-wrap:nowrap !important;align-items:stretch !important;justify-content:flex-start !important;gap:0 !important;width:100% !important;min-width:0 !important;max-width:none !important;height:auto !important;margin:0 !important;padding:0 !important;background:transparent !important;box-shadow:none !important;overflow:visible !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary > .metis-shell-menu-list::before,body.metis-nav-mobile-viewport .metis-shell-nav-primary > .metis-shell-menu-list::after{display:none !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item{position:relative !important;display:flex !important;flex:0 0 auto !important;flex-direction:column !important;align-items:stretch !important;width:100% !important;min-width:0 !important;max-width:none !important;height:auto !important;margin:0 !important;padding:0 !important;transform:none !important;opacity:1 !important;background:transparent !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item::before,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item::after{display:none !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item > .metis-shell-menu-link,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item > .metis-shell-menu-btn{position:relative !important;display:flex !important;align-items:center !important;justify-content:flex-start !important;width:100% !important;min-width:0 !important;max-width:none !important;height:auto !important;min-height:58px !important;margin:0 !important;padding:1rem 4rem 1rem .25rem !important;border:0 !important;border-bottom:1px solid color-mix(in srgb,var(--metis-color-border,#d8deea) 72%,transparent) !important;border-radius:0 !important;background:transparent !important;box-shadow:none !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:1.04rem !important;font-weight:760 !important;line-height:1.2 !important;letter-spacing:0 !important;text-align:left !important;text-decoration:none !important;text-shadow:none !important;text-transform:none !important;white-space:normal !important;overflow:visible !important;text-overflow:clip !important;opacity:1 !important;transform:none !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item > .metis-shell-menu-link:is(:hover,:focus-visible),body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item > .metis-shell-menu-btn:is(:hover,:focus-visible){background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 7%,transparent) !important;color:var(--metis-color-text,#1a1f2b) !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item > .metis-shell-menu-btn{margin:.8rem 0 .35rem !important;padding:1rem 1.25rem !important;border:0 !important;border-radius:14px !important;background:var(--metis-menu-button-bg,var(--metis-color-accent,#ff7542)) !important;color:var(--metis-menu-button-text,#fff) !important;justify-content:center !important;box-shadow:0 10px 22px color-mix(in srgb,var(--metis-menu-button-bg,var(--metis-color-accent,#ff7542)) 26%,transparent) !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub{position:static !important;display:block !important;width:100% !important;min-width:0 !important;max-width:none !important;max-height:0 !important;margin:0 !important;padding:0 !important;border:0 !important;border-radius:0 !important;background:transparent !important;box-shadow:none !important;visibility:visible !important;opacity:0 !important;pointer-events:auto !important;transform:none !important;overflow:hidden !important;transition:max-height .24s ease,opacity .18s ease !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item.has-children:not(.is-open) > .metis-shell-menu-sub{max-height:0 !important;opacity:0 !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item.has-children.is-open > .metis-shell-menu-sub{max-height:44rem !important;opacity:1 !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub{padding-left:1rem !important;border-left:2px solid color-mix(in srgb,var(--metis-color-primary,#485bc7) 30%,transparent) !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub .metis-shell-menu-item > .metis-shell-menu-link,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub .metis-shell-menu-item > .metis-shell-menu-btn{min-height:46px !important;padding:.78rem .75rem .78rem .9rem !important;background:transparent !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:.95rem !important;font-weight:640 !important;border-bottom:1px solid color-mix(in srgb,var(--metis-color-border,#d8deea) 52%,transparent) !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-sub-indicator{display:none !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item.has-children > .metis-shell-menu-link::before,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item.has-children > .metis-shell-menu-btn::before{content:"" !important;position:absolute !important;right:.25rem !important;top:50% !important;width:30px !important;height:30px !important;border:1px solid color-mix(in srgb,var(--metis-color-primary,#485bc7) 22%,var(--metis-color-border,#d8deea)) !important;border-radius:999px !important;background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 8%,var(--metis-color-surface,#fff)) !important;transform:translateY(-50%) !important;box-sizing:border-box !important;display:block !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item.has-children > .metis-shell-menu-link::after,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item.has-children > .metis-shell-menu-btn::after{content:"+" !important;position:absolute !important;right:.25rem !important;top:50% !important;width:30px !important;height:30px !important;display:flex !important;align-items:center !important;justify-content:center !important;color:var(--metis-color-primary,#485bc7) !important;font-size:1.1rem !important;font-weight:800 !important;line-height:1 !important;transform:translateY(-50%) !important;opacity:1 !important;background:transparent !important;border:0 !important;box-shadow:none !important;}'
            . 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item.has-children.is-open > .metis-shell-menu-link::after,body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-item.has-children.is-open > .metis-shell-menu-btn::after{content:"-" !important;}'
            . '}';
    }

    private static function navigationBehaviorScript(): string {
        $src = self::publicAssetUrl( 'public-navigation.js' );
        if ( $src === '' ) {
            return '';
        }
        return '<script src="' . metis_escape_attr( $src ) . '" defer></script>';
    }

    private static function templateVariantSlug( array $template_structure ): string {
        $variant = metis_key_clean( (string) ( $template_structure['template_variant'] ?? '' ) );
        if ( $variant !== '' ) {
            return $variant;
        }
        return metis_key_clean( (string) ( $template_structure['template_key'] ?? '' ) );
    }

    private static function pillDropdownMenuCss( string $scope ): string {
        $scope = trim( $scope );
        if ( $scope === '' ) {
            return '';
        }

        $list = $scope . ' > .metis-shell-menu-list';
        $item = $list . ' > .metis-shell-menu-item';
        $mobile_scope = str_replace(
            'body.metis-menu-style-h_pill_dropdown ',
            'body.metis-menu-style-h_pill_dropdown.metis-nav-mobile-viewport ',
            $scope
        );
        $mobile_list = $mobile_scope . ' > .metis-shell-menu-list';
        $mobile_item = $mobile_list . ' > .metis-shell-menu-item';

        $rules = [
            $scope . '{padding:.35rem !important;border:1px solid var(--metis-color-border,#d8deea) !important;border-radius:999px !important;background:color-mix(in srgb,var(--metis-color-surface,#fff) 88%,var(--metis-color-primary,#485bc7)) !important;box-shadow:0 16px 34px rgba(15,23,42,.13);overflow:visible !important;}',
            $list . '{display:flex !important;flex-wrap:nowrap !important;gap:.35rem !important;align-items:center !important;justify-content:center !important;width:100%;min-width:max-content;margin:0 !important;padding:0 !important;list-style:none !important;overflow:visible !important;}',
            $item . '{position:relative;display:flex !important;align-items:center !important;justify-content:center !important;min-width:max-content;margin:0 !important;padding:0 !important;list-style:none !important;}',
            $item . ' > .metis-shell-menu-link,' . $item . ' > .metis-shell-menu-btn{display:flex !important;align-items:center !important;justify-content:center !important;min-width:max-content;min-height:0 !important;padding:.78rem 1.08rem !important;border:0 !important;border-radius:999px !important;background:transparent !important;box-shadow:none !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:var(--metis-menu-font-size,14px) !important;font-weight:700 !important;letter-spacing:0 !important;line-height:1 !important;text-decoration:none !important;text-transform:none !important;white-space:nowrap !important;transition:background-color .2s ease,color .2s ease,box-shadow .2s ease,transform .2s ease;}',
            $item . ':is(:hover,:focus-within,.is-active,.is-active-ancestor) > .metis-shell-menu-link,' . $item . ':is(:hover,:focus-within,.is-active,.is-active-ancestor) > .metis-shell-menu-btn{background:var(--metis-color-primary,#485bc7) !important;color:var(--metis-color-button_text,#fff) !important;box-shadow:0 8px 18px color-mix(in srgb,var(--metis-color-primary,#485bc7) 28%,transparent);}',
            $item . '.has-children > .metis-shell-menu-link .metis-shell-menu-sub-indicator,' . $item . '.has-children > .metis-shell-menu-btn .metis-shell-menu-sub-indicator{color:currentColor;border-color:currentColor;transition:transform .2s ease;}',
            $item . '.has-children:is(:hover,:focus-within,.is-open) > .metis-shell-menu-link .metis-shell-menu-sub-indicator,' . $item . '.has-children:is(:hover,:focus-within,.is-open) > .metis-shell-menu-btn .metis-shell-menu-sub-indicator{transform:rotate(225deg) translate(1px,50%);}',
            $item . ' > .metis-shell-menu-sub{position:absolute !important;top:calc(100% + .65rem) !important;left:50% !important;z-index:1200 !important;min-width:max(100%,14rem) !important;width:max-content !important;margin:0 !important;padding:.45rem !important;border:1px solid var(--metis-color-border,#d8deea) !important;border-radius:18px !important;background:var(--metis-color-surface,#fff) !important;box-shadow:0 18px 34px rgba(15,23,42,.16) !important;visibility:hidden;opacity:0;pointer-events:none;transform:translate(-50%,8px) scale(.96) !important;transform-origin:top center;transition:opacity .18s ease,transform .22s cubic-bezier(.2,.8,.2,1),visibility .18s ease;overflow:hidden !important;}',
            $item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub{visibility:visible;opacity:1;pointer-events:auto;transform:translate(-50%,0) scale(1) !important;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item{display:block !important;width:100%;min-width:0;opacity:0;transform:translateY(6px);transition:opacity .18s ease,transform .2s ease;}',
            $item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub > .metis-shell-menu-item{opacity:1;transform:translateY(0);}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn{display:flex !important;align-items:center !important;justify-content:flex-start !important;width:100%;min-height:0 !important;padding:.82rem .95rem !important;border:0 !important;border-radius:12px !important;background:transparent !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:.95rem !important;font-weight:650 !important;letter-spacing:0 !important;line-height:1.1 !important;text-align:left;text-decoration:none !important;text-transform:none !important;white-space:nowrap !important;transition:background-color .18s ease,color .18s ease,transform .18s ease;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link:hover,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link:focus-visible,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn:hover,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn:focus-visible{background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 12%,var(--metis-color-surface,#fff)) !important;color:var(--metis-color-primary,#485bc7) !important;transform:translateX(2px);}',
            $mobile_scope . '{padding:0 !important;border:0 !important;border-radius:0 !important;background:var(--metis-color-surface,#fff) !important;box-shadow:-10px 0 24px rgba(2,8,23,.16) !important;overflow:auto !important;}',
            $mobile_list . '{flex-direction:column !important;flex-wrap:nowrap !important;gap:0 !important;align-items:stretch !important;justify-content:flex-start !important;width:100% !important;min-width:0 !important;}',
            $mobile_item . '{display:flex !important;flex:0 0 auto !important;flex-direction:column;align-items:stretch;min-width:0 !important;}',
            $mobile_item . ' > .metis-shell-menu-link,' . $mobile_item . ' > .metis-shell-menu-btn{justify-content:flex-start !important;min-width:0 !important;padding:1rem 2.75rem 1rem 1rem !important;border-radius:0 !important;background:transparent !important;color:var(--metis-color-text,#1a1f2b) !important;box-shadow:none !important;text-align:left !important;text-transform:none !important;white-space:normal !important;}',
            $mobile_item . ' > .metis-shell-menu-sub{position:static !important;width:100% !important;min-width:0 !important;max-height:0;visibility:visible;opacity:1;pointer-events:auto;transform:none !important;border:0 !important;border-radius:0 !important;background:rgba(0,0,0,.06) !important;box-shadow:none !important;padding:0 !important;transition:max-height .25s ease;overflow:hidden !important;}',
            $mobile_item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub{max-height:32rem;}',
            $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item{opacity:1;transform:none;transition:none;}',
            $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link,' . $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn{border-radius:0 !important;background:transparent !important;color:var(--metis-color-text,#1a1f2b) !important;white-space:normal !important;}',
            '@media (prefers-reduced-motion: reduce){' . $scope . ' *{transition:none !important;}}',
        ];

        for ( $position = 1; $position <= 12; $position++ ) {
            $rules[] = $item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub > .metis-shell-menu-item:nth-child(' . $position . '){transition-delay:' . ( 35 * $position ) . 'ms;}';
        }

        return implode( "\n", $rules );
    }

    private static function modernBarMenuCss( string $scope ): string {
        $scope = trim( $scope );
        if ( $scope === '' ) {
            return '';
        }

        $list = $scope . ' > .metis-shell-menu-list';
        $item = $list . ' > .metis-shell-menu-item';
        $mobile_scope = str_replace(
            'body.metis-menu-style-h_modern_bar ',
            'body.metis-menu-style-h_modern_bar.metis-nav-mobile-viewport ',
            $scope
        );
        $mobile_list = $mobile_scope . ' > .metis-shell-menu-list';
        $mobile_item = $mobile_list . ' > .metis-shell-menu-item';

        return implode( "\n", [
            $scope . '{padding:.25rem !important;border:1px solid var(--metis-color-border,#d8deea) !important;border-radius:16px !important;background:linear-gradient(180deg,color-mix(in srgb,var(--metis-color-surface,#fff) 94%,var(--metis-color-primary,#485bc7)),var(--metis-color-surface,#fff)) !important;box-shadow:0 14px 30px rgba(15,23,42,.11);overflow:visible !important;}',
            $list . '{display:flex !important;flex-wrap:nowrap !important;gap:0 !important;align-items:stretch !important;justify-content:center !important;width:100%;min-width:max-content;margin:0 !important;padding:0 !important;list-style:none !important;overflow:visible !important;}',
            $item . '{position:relative;display:flex !important;align-items:stretch !important;justify-content:center !important;min-width:max-content;margin:0 !important;padding:0 !important;list-style:none !important;}',
            $item . ' > .metis-shell-menu-link,' . $item . ' > .metis-shell-menu-btn{position:relative;display:flex !important;align-items:center !important;justify-content:center !important;min-width:max-content;min-height:0 !important;padding:1rem 1.08rem !important;border:0 !important;border-radius:12px !important;background:transparent !important;box-shadow:none !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:var(--metis-menu-font-size,14px) !important;font-weight:700 !important;letter-spacing:0 !important;line-height:1 !important;text-decoration:none !important;text-transform:none !important;white-space:nowrap !important;transition:background-color .2s ease,color .2s ease,box-shadow .2s ease;}',
            $item . ' > .metis-shell-menu-link::after,' . $item . ' > .metis-shell-menu-btn::after{content:"";position:absolute;left:1rem;right:1rem;bottom:.43rem;height:2px;border-radius:999px;background:var(--metis-color-accent,#ff7542);transform:scaleX(0);transform-origin:left center;transition:transform .22s ease;}',
            $item . ':is(:hover,:focus-within,.is-active,.is-active-ancestor) > .metis-shell-menu-link,' . $item . ':is(:hover,:focus-within,.is-active,.is-active-ancestor) > .metis-shell-menu-btn{background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 8%,transparent) !important;color:var(--metis-color-primary,#485bc7) !important;}',
            $item . ':is(:hover,:focus-within,.is-active,.is-active-ancestor) > .metis-shell-menu-link::after,' . $item . ':is(:hover,:focus-within,.is-active,.is-active-ancestor) > .metis-shell-menu-btn::after{transform:scaleX(1);}',
            $item . '.has-children > .metis-shell-menu-link .metis-shell-menu-sub-indicator,' . $item . '.has-children > .metis-shell-menu-btn .metis-shell-menu-sub-indicator{color:currentColor;border-color:currentColor;transition:transform .2s ease;}',
            $item . '.has-children:is(:hover,:focus-within,.is-open) > .metis-shell-menu-link .metis-shell-menu-sub-indicator,' . $item . '.has-children:is(:hover,:focus-within,.is-open) > .metis-shell-menu-btn .metis-shell-menu-sub-indicator{transform:rotate(225deg) translate(1px,50%);}',
            $item . ' > .metis-shell-menu-sub{position:absolute !important;top:calc(100% + .5rem) !important;left:0 !important;z-index:1200 !important;min-width:max(100%,15rem) !important;width:max-content !important;margin:0 !important;padding:.5rem !important;border:1px solid var(--metis-color-border,#d8deea) !important;border-radius:14px !important;background:var(--metis-color-surface,#fff) !important;box-shadow:0 18px 34px rgba(15,23,42,.16) !important;visibility:hidden;opacity:0;pointer-events:none;transform:translateY(10px) !important;transition:opacity .18s ease,transform .22s ease,visibility .18s ease;overflow:hidden !important;}',
            $item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub{visibility:visible;opacity:1;pointer-events:auto;transform:translateY(0) !important;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item{display:block !important;width:100%;min-width:0;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn{display:flex !important;align-items:center !important;justify-content:flex-start !important;width:100%;min-height:0 !important;padding:.82rem .9rem !important;border:0 !important;border-radius:10px !important;background:transparent !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:.95rem !important;font-weight:650 !important;letter-spacing:0 !important;line-height:1.1 !important;text-align:left;text-decoration:none !important;text-transform:none !important;white-space:nowrap !important;transition:background-color .18s ease,color .18s ease,transform .18s ease;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link::after,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn::after{display:none;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link:hover,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link:focus-visible,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn:hover,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn:focus-visible{background:color-mix(in srgb,var(--metis-color-primary,#485bc7) 10%,var(--metis-color-surface,#fff)) !important;color:var(--metis-color-primary,#485bc7) !important;transform:translateX(2px);}',
            $mobile_scope . '{padding:0 !important;border:0 !important;border-radius:0 !important;background:var(--metis-color-surface,#fff) !important;box-shadow:-10px 0 24px rgba(2,8,23,.16) !important;overflow:auto !important;}',
            $mobile_list . '{flex-direction:column !important;flex-wrap:nowrap !important;gap:0 !important;align-items:stretch !important;justify-content:flex-start !important;width:100% !important;min-width:0 !important;}',
            $mobile_item . '{display:flex !important;flex:0 0 auto !important;flex-direction:column;align-items:stretch;min-width:0 !important;}',
            $mobile_item . ' > .metis-shell-menu-link,' . $mobile_item . ' > .metis-shell-menu-btn{justify-content:flex-start !important;min-width:0 !important;padding:1rem 2.75rem 1rem 1rem !important;border-radius:0 !important;background:transparent !important;color:var(--metis-color-text,#1a1f2b) !important;box-shadow:none !important;text-align:left !important;text-transform:none !important;white-space:normal !important;}',
            $mobile_item . ' > .metis-shell-menu-link::after,' . $mobile_item . ' > .metis-shell-menu-btn::after{display:none !important;}',
            $mobile_item . ' > .metis-shell-menu-sub{position:static !important;width:100% !important;min-width:0 !important;max-height:0;visibility:visible;opacity:1;pointer-events:auto;transform:none !important;border:0 !important;border-radius:0 !important;background:rgba(0,0,0,.06) !important;box-shadow:none !important;padding:0 !important;transition:max-height .25s ease;overflow:hidden !important;}',
            $mobile_item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub{max-height:32rem;}',
            $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link,' . $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn{border-radius:0 !important;background:transparent !important;color:var(--metis-color-text,#1a1f2b) !important;white-space:normal !important;}',
            '@media (prefers-reduced-motion: reduce){' . $scope . ' *{transition:none !important;}}',
        ] );
    }

    private static function codepenGlideMenuCss( string $scope ): string {
        $scope = trim( $scope );
        if ( $scope === '' ) {
            return '';
        }

        $list = $scope . ' > .metis-shell-menu-list';
        $item = $list . ' > .metis-shell-menu-item';
        $mobile_scope = str_replace(
            'body.metis-menu-style-h_glide ',
            'body.metis-menu-style-h_glide.metis-nav-mobile-viewport ',
            $scope
        );
        $mobile_list = $mobile_scope . ' > .metis-shell-menu-list';
        $mobile_item = $mobile_list . ' > .metis-shell-menu-item';

        $rules = [
            $scope . '{--metis-glide-primary:var(--metis-color-primary,#485bc7);--metis-glide-accent:var(--metis-color-accent,#ff7542);--metis-glide-menu-width:62rem;flex:0 1 var(--metis-glide-menu-width);min-width:0;width:min(100%,var(--metis-glide-menu-width)) !important;max-width:100%;margin-left:auto !important;padding:0 !important;border:0 !important;border-radius:.5rem !important;background-image:linear-gradient(to right,var(--metis-glide-primary) 0%,var(--metis-glide-accent) 100%);box-shadow:inset .1rem .1rem .5rem rgba(0,0,0,.15),0 12px 28px rgba(15,23,42,.13);text-transform:uppercase !important;overflow:visible !important;}',
            $list . '{position:relative;display:flex !important;flex-wrap:nowrap !important;gap:0 !important;align-items:stretch !important;justify-content:flex-start !important;width:100%;min-width:0;max-width:100%;margin:0 !important;padding:0 !important;list-style:none !important;overflow:visible !important;}',
            $item . '{position:relative;display:flex !important;align-items:center !important;justify-content:center !important;flex:1 1 0 !important;width:auto !important;min-width:0;max-width:none;margin:0 !important;padding:0 !important;border:0 !important;list-style:none !important;z-index:1;}',
            $item . ':last-child::after{content:"";position:absolute;top:0;bottom:0;width:100%;right:50%;margin-right:-50%;border-radius:.5rem;background:rgba(0,0,0,.12);z-index:-1;opacity:0;transition:right 350ms cubic-bezier(1,.49,.09,1.29),opacity 180ms ease;pointer-events:none;}',
            $list . ':has(> .metis-shell-menu-item:is(:hover,:focus-within,.is-open,.is-active,.is-active-ancestor)) > .metis-shell-menu-item:last-child::after{opacity:1;}',
            $list . ':hover > .metis-shell-menu-item:first-child > .metis-shell-menu-link,' . $list . ':focus-within > .metis-shell-menu-item:first-child > .metis-shell-menu-link,' . $list . ':hover > .metis-shell-menu-item:first-child > .metis-shell-menu-btn,' . $list . ':focus-within > .metis-shell-menu-item:first-child > .metis-shell-menu-btn{opacity:.6;}',
            $item . ':first-child > .metis-shell-menu-link,' . $item . ':first-child > .metis-shell-menu-btn{opacity:1;}',
            $item . ':is(:hover,:focus-within,.is-active,.is-active-ancestor) > .metis-shell-menu-link,' . $item . ':is(:hover,:focus-within,.is-active,.is-active-ancestor) > .metis-shell-menu-btn{opacity:1 !important;}',
            $item . ' > .metis-shell-menu-link,' . $item . ' > .metis-shell-menu-btn{position:relative;z-index:1;display:flex !important;align-items:center !important;justify-content:center !important;width:100% !important;min-width:0;max-width:100%;min-height:0 !important;padding:2rem .9rem !important;border:0 !important;border-radius:0 !important;background:transparent !important;box-shadow:none !important;color:var(--metis-color-button_text,#fff) !important;font-size:var(--metis-menu-font-size,19px) !important;font-weight:500 !important;letter-spacing:0 !important;text-decoration:none !important;text-transform:uppercase !important;text-align:center;white-space:nowrap !important;overflow:hidden;text-overflow:ellipsis;line-height:1.1 !important;opacity:.7;text-shadow:0 1px 1px rgba(0,0,0,.28);transition:opacity 250ms ease,color 250ms ease,background-color 250ms ease,box-shadow 250ms ease;}',
            $item . ' > .metis-shell-menu-link:focus-visible,' . $item . ' > .metis-shell-menu-btn:focus-visible{outline:2px solid rgba(255,255,255,.85);outline-offset:-4px;}',
            $item . '.has-children > .metis-shell-menu-link .metis-shell-menu-sub-indicator,' . $item . '.has-children > .metis-shell-menu-btn .metis-shell-menu-sub-indicator{color:currentColor;border-color:currentColor;transition:transform 250ms ease;}',
            $item . ' > .metis-shell-menu-sub{position:absolute !important;top:100% !important;left:0 !important;width:100% !important;min-width:max(100%,180px) !important;z-index:1200 !important;visibility:hidden;opacity:1 !important;pointer-events:none;transform:none !important;border:0 !important;border-radius:0 !important;background:transparent !important;box-shadow:none !important;padding:0 !important;margin:0 !important;overflow:visible !important;}',
            $item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub{visibility:visible;pointer-events:auto;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item{width:100%;margin-top:.5rem;transform:scale(0);transform-origin:top center;transition:250ms cubic-bezier(.42,.83,.49,1.35) transform;}',
            $item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub > .metis-shell-menu-item{transform:scale(1);}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn{display:block !important;width:100%;min-height:0 !important;padding:.85rem .7rem !important;border:0 !important;border-radius:.25rem !important;background:var(--metis-glide-accent,var(--metis-color-accent,#ff7542)) !important;color:rgba(255,255,255,.86) !important;text-align:center;font-size:1rem !important;font-weight:550 !important;letter-spacing:0 !important;text-decoration:none !important;text-transform:none !important;white-space:nowrap !important;opacity:1;text-shadow:0 1px 1px rgba(0,0,0,.22);box-shadow:inset 0 0 0 3rem rgba(0,0,0,0);transition:250ms ease all;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link:hover,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link:focus-visible,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn:hover,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn:focus-visible{box-shadow:inset 0 0 0 3rem rgba(0,0,0,.1);color:#fff !important;}',
            $mobile_scope . '{border-radius:0 !important;background:var(--metis-color-surface,#fff) !important;box-shadow:-10px 0 24px rgba(2,8,23,.16) !important;text-transform:none !important;}',
            $mobile_list . '{flex-direction:column !important;flex-wrap:nowrap !important;align-items:stretch !important;justify-content:flex-start !important;}',
            $mobile_item . ':last-child::after{display:none !important;}',
            $mobile_item . '{display:flex !important;flex:0 0 auto !important;flex-direction:column;align-items:stretch;text-align:center;}',
            $mobile_item . ' > .metis-shell-menu-link,' . $mobile_item . ' > .metis-shell-menu-btn{justify-content:flex-start !important;min-height:0 !important;padding:1rem 2.75rem 1rem 1rem !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:var(--metis-menu-font-size,14px) !important;font-weight:700 !important;opacity:1;text-align:left;text-shadow:none !important;text-transform:none !important;}',
            $mobile_item . '.has-children > .metis-shell-menu-link .metis-shell-menu-sub-indicator,' . $mobile_item . '.has-children > .metis-shell-menu-btn .metis-shell-menu-sub-indicator{position:absolute;right:1.25rem;top:50%;transform:rotate(45deg) translateY(-50%);}',
            $mobile_item . '.has-children:is(:hover,:focus-within,.is-open) > .metis-shell-menu-link .metis-shell-menu-sub-indicator,' . $mobile_item . '.has-children:is(:hover,:focus-within,.is-open) > .metis-shell-menu-btn .metis-shell-menu-sub-indicator{transform:rotate(225deg) translate(1px,50%);}',
            $mobile_item . ' > .metis-shell-menu-sub{position:static;width:100%;min-width:0;max-height:0;visibility:visible;pointer-events:auto;overflow:hidden;background:rgba(0,0,0,.1);box-shadow:inset .1rem .1rem .5rem rgba(0,0,0,.15);transition:max-height 250ms ease;}',
            $mobile_item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub{max-height:16rem;}',
            $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item{margin:0;transform:none;transition:none;}',
            $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link,' . $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn{background:transparent !important;border-radius:0 !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:.9em !important;text-align:left !important;}',
            '@media (prefers-reduced-motion: reduce){' . $scope . ' *{transition:none !important;}}',
        ];

        for ( $count = 1; $count <= 12; $count++ ) {
            if ( $count === 1 ) {
                $rules[] = $item . ':first-child:last-child::after{right:50%;}';
            } else {
                $rules[] = $item . ':first-child:nth-last-child(' . $count . ') ~ .metis-shell-menu-item:last-child::after{right:calc(50% + ' . ( 100 * ( $count - 1 ) ) . '%);}';
            }
            for ( $position = 1; $position <= $count; $position++ ) {
                $rules[] = $list . ':has(> .metis-shell-menu-item:first-child:nth-last-child(' . $count . ')):has(> .metis-shell-menu-item:nth-child(' . $position . '):is(:hover,:focus-within,.is-open,.is-active,.is-active-ancestor)) > .metis-shell-menu-item:last-child::after{right:calc(50% + ' . ( 100 * ( $count - $position ) ) . '%);}';
            }
        }

        for ( $position = 1; $position <= 12; $position++ ) {
            $rules[] = $item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub > .metis-shell-menu-item:nth-child(' . $position . '){transition-delay:' . ( 150 * $position ) . 'ms;}';
        }

        return implode( "\n", $rules );
    }

    private static function markerDropdownMenuCss( string $scope ): string {
        $scope = trim( $scope );
        if ( $scope === '' ) {
            return '';
        }

        $list = $scope . ' > .metis-shell-menu-list';
        $item = $list . ' > .metis-shell-menu-item';
        $mobile_scope = str_replace(
            'body.metis-menu-style-h_marker_dropdown ',
            'body.metis-menu-style-h_marker_dropdown.metis-nav-mobile-viewport ',
            $scope
        );
        $mobile_list = $mobile_scope . ' > .metis-shell-menu-list';
        $mobile_item = $mobile_list . ' > .metis-shell-menu-item';

        $rules = [
            $scope . '{--metis-marker-count:1;--metis-marker-index:0;--metis-marker-bg:var(--metis-color-primary,#485bc7);--metis-marker-accent:var(--metis-color-accent,#ff7542);padding:0 !important;border:0 !important;border-radius:0 !important;background:var(--metis-marker-bg) !important;box-shadow:0 12px 24px rgba(15,23,42,.13);text-transform:uppercase !important;overflow:visible !important;}',
            $list . '{position:relative;display:flex !important;flex-wrap:nowrap !important;gap:0 !important;align-items:stretch !important;justify-content:flex-start !important;width:max-content;min-width:500px;max-width:100%;height:50px;padding:0 !important;margin:0 !important;list-style:none !important;overflow:visible;font-size:0;}',
            $list . '::after{content:"";position:absolute;top:0;left:0;width:calc(100% / var(--metis-marker-count,1));height:100%;background:var(--metis-marker-accent);z-index:0;opacity:0;transform:translateX(calc(var(--metis-marker-index,0) * 100%));transition:all .5s ease 0s;pointer-events:none;}',
            $list . ':has(> .metis-shell-menu-item:is(:hover,:focus-within,.is-open,.is-active,.is-active-ancestor))::after{opacity:1;}',
            $item . '{position:relative;display:flex !important;align-items:stretch !important;justify-content:center !important;flex:1 1 0 !important;min-width:max-content;margin:0 !important;padding:0 !important;border:0 !important;list-style:none !important;z-index:1;cursor:pointer;}',
            $item . ' > .metis-shell-menu-link,' . $item . ' > .metis-shell-menu-btn{position:relative;z-index:5;display:flex !important;align-items:center !important;justify-content:center !important;width:100%;min-width:max-content;height:50px;min-height:0 !important;padding:0 20px !important;border:0 !important;border-radius:0 !important;background:transparent !important;box-shadow:none !important;color:var(--metis-color-button_text,#fff) !important;font-size:.8rem !important;font-weight:700 !important;letter-spacing:0 !important;line-height:1 !important;text-decoration:none !important;text-transform:uppercase !important;text-align:center;white-space:nowrap !important;opacity:1;text-shadow:0 1px 1px rgba(0,0,0,.22);transition:opacity .18s ease,color .18s ease;}',
            $item . ' > .metis-shell-menu-link:hover,' . $item . ' > .metis-shell-menu-link:focus-visible,' . $item . ' > .metis-shell-menu-btn:hover,' . $item . ' > .metis-shell-menu-btn:focus-visible,' . $item . '.is-active > .metis-shell-menu-link,' . $item . '.is-active-ancestor > .metis-shell-menu-link{opacity:1;color:var(--metis-color-button_text,#fff) !important;}',
            $item . '.has-children > .metis-shell-menu-link .metis-shell-menu-sub-indicator,' . $item . '.has-children > .metis-shell-menu-btn .metis-shell-menu-sub-indicator{color:currentColor;border-color:currentColor;}',
            $item . ' > .metis-shell-menu-sub{position:absolute !important;top:50px !important;left:0 !important;width:100% !important;min-width:100% !important;max-height:0;z-index:1200 !important;visibility:hidden !important;opacity:1 !important;pointer-events:none;transform:none !important;border:0 !important;border-radius:0 !important;background:transparent !important;box-shadow:none !important;padding:0 !important;margin:0 !important;overflow:hidden !important;transition:max-height 0s .5s,visibility 0s .5s;}',
            $item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub{max-height:32rem;visibility:visible !important;pointer-events:auto;transition:max-height 0s,visibility 0s;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item{display:block !important;width:100%;min-width:0;padding:0;background:var(--metis-marker-accent) !important;transform:translate(0,-100%);transition:all .5s .1s;position:relative;}',
            $item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub > .metis-shell-menu-item{transform:translate(0,0);}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn{display:flex !important;width:100%;min-width:0;height:50px;min-height:0 !important;padding:0 20px !important;border:0 !important;border-radius:0 !important;background:var(--metis-marker-accent) !important;color:rgba(255,255,255,.9) !important;text-align:center;align-items:center !important;justify-content:center !important;font-size:.8rem !important;font-weight:700 !important;letter-spacing:0 !important;text-decoration:none !important;text-transform:uppercase !important;white-space:nowrap !important;opacity:1;text-shadow:0 1px 1px rgba(0,0,0,.2);transition:box-shadow .2s ease,color .2s ease;}',
            $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link:hover,' . $item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn:hover{box-shadow:inset 0 0 0 999px rgba(0,0,0,.12);color:#fff !important;}',
            $mobile_scope . '{border-radius:0 !important;background:var(--metis-color-surface,#fff) !important;box-shadow:-10px 0 24px rgba(2,8,23,.16) !important;text-transform:none !important;}',
            $mobile_list . '{display:flex !important;flex-direction:column !important;flex-wrap:nowrap !important;align-items:stretch !important;justify-content:flex-start !important;width:100% !important;min-width:0 !important;height:auto !important;}',
            $mobile_list . '::after{display:none;}',
            $mobile_item . '{display:flex !important;flex:0 0 auto !important;flex-direction:column;align-items:stretch;min-width:0;text-align:center;}',
            $mobile_item . ' > .metis-shell-menu-link,' . $mobile_item . ' > .metis-shell-menu-btn{justify-content:flex-start !important;height:auto !important;min-width:0 !important;min-height:0 !important;padding:1rem 2.75rem 1rem 1rem !important;color:var(--metis-color-text,#1a1f2b) !important;font-size:var(--metis-menu-font-size,14px) !important;text-align:left !important;text-shadow:none !important;text-transform:none !important;white-space:normal;}',
            $mobile_item . ' > .metis-shell-menu-sub{position:static;width:100%;min-width:0;max-height:0;background:rgba(0,0,0,.1);box-shadow:inset 0 8px 18px rgba(15,23,42,.12);transition:max-height .25s ease;}',
            $mobile_item . ':is(:hover,:focus-within,.is-open) > .metis-shell-menu-sub{max-height:32rem;}',
            $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item{transform:none;transition:none;}',
            $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-link,' . $mobile_item . ' > .metis-shell-menu-sub > .metis-shell-menu-item > .metis-shell-menu-btn{background:transparent !important;color:var(--metis-color-text,#1a1f2b) !important;white-space:normal;}',
            '@media (prefers-reduced-motion: reduce){' . $scope . ' *{transition:none !important;}}',
        ];

        for ( $count = 1; $count <= 12; $count++ ) {
            $rules[] = $list . ':has(> .metis-shell-menu-item:nth-child(' . $count . '):last-child){--metis-marker-count:' . $count . ';}';
            for ( $position = 1; $position <= $count; $position++ ) {
                $rules[] = $list . ':has(> .metis-shell-menu-item:first-child:nth-last-child(' . $count . ')):has(> .metis-shell-menu-item:nth-child(' . $position . '):is(:hover,:focus-within,.is-open,.is-active,.is-active-ancestor)){--metis-marker-index:' . ( $position - 1 ) . ';}';
            }
        }

        return implode( "\n", $rules );
    }

    private static function menuBaseCss(): string {
        return implode( "\n", [
            '.metis-block-menu .metis-menu-list{list-style:none;margin:0;padding:0;display:flex;gap:var(--metis-menu-gap,12px);flex-wrap:wrap;justify-content:var(--metis-menu-justify,flex-start);align-items:center;}',
            '.metis-block-menu.metis-menu-vertical .metis-menu-list{flex-direction:column;align-items:var(--metis-menu-align,flex-start);}',
            '.metis-block-menu.metis-menu-type-sidebar .metis-menu-list{flex-direction:column;align-items:var(--metis-menu-align,flex-start);justify-content:flex-start;}',
            '.metis-block-menu.metis-menu-type-sidebar .metis-menu-item>a{display:inline-flex;width:auto;}',
            '.metis-block-menu.metis-menu-type-offcanvas{padding:10px;border:1px solid var(--metis-color-border,#d8deea);border-radius:10px;background:var(--metis-color-surface,#fff);}',
            '.metis-block-menu.metis-menu-type-offcanvas .metis-menu-list{flex-direction:column;align-items:var(--metis-menu-align,flex-start);justify-content:flex-start;}',
            '.metis-block-menu.metis-menu-type-offcanvas .metis-menu-item>a{display:inline-flex;width:auto;}',
            '.metis-block-menu .metis-menu-item{position:relative;}',
            '.metis-block-menu .metis-menu-item a{text-decoration:none;color:var(--metis-menu-link,var(--metis-color-text,#1a1f2b));font-weight:var(--metis-menu-weight,inherit);font-size:var(--metis-menu-size,inherit);}',
            '.metis-block-menu .metis-menu-item a:hover{color:var(--metis-menu-link-hover,var(--metis-menu-link,var(--metis-color-text,#1a1f2b)));}',
            '.metis-block-menu .metis-menu-item-has-children > a{display:inline-flex;align-items:center;gap:8px;}',
            '.metis-block-menu .metis-menu-item-has-children > a::after{display:inline-flex;align-items:center;justify-content:center;margin-left:2px;padding:0;color:var(--metis-menu-link,var(--metis-color-text,#1a1f2b));font-size:.9em;line-height:1;font-weight:600;flex-shrink:0;}',
            '.metis-block-menu[data-submenu-icon="none"] .metis-menu-item-has-children > a::after{content:"";display:none;}',
            '.metis-block-menu[data-submenu-icon="arrow"] .metis-menu-item-has-children > a::after{content:"→";}',
            '.metis-block-menu[data-submenu-icon="chevron_down"] .metis-menu-item-has-children > a::after{content:"⌄";}',
            '.metis-block-menu[data-submenu-icon="plus"] .metis-menu-item-has-children > a::after{content:"+";}',
            '.metis-block-menu[data-submenu-icon="caret"] .metis-menu-item-has-children > a::after{content:"▸";}',
            '.metis-block-menu .metis-menu-item > a.metis-menu-btn{display:inline-flex;align-items:center;justify-content:center;padding:var(--metis-menu-item-btn-padding,var(--metis-menu-btn-padding,8px 12px));background:var(--metis-menu-item-btn-bg,var(--metis-menu-btn-bg,#485bc7));color:var(--metis-menu-item-btn-color,var(--metis-menu-btn-color,#fff));border-radius:var(--metis-menu-item-btn-radius,var(--metis-menu-btn-radius,8px));transition:background-color 150ms ease,color 150ms ease,transform 150ms ease;line-height:1.2;}',
            '.metis-block-menu .metis-menu-item > a.metis-menu-btn:hover{background:var(--metis-menu-item-btn-hover-bg,var(--metis-menu-btn-hover-bg,var(--metis-menu-btn-bg,#485bc7)));color:var(--metis-menu-item-btn-hover-color,var(--metis-menu-btn-hover-color,var(--metis-menu-btn-color,#fff)));transform:translateY(-1px);}',
        ] );
    }

    private static function menuVariantCss( array $variants ): string {
        $chunks = [];
        foreach ( $variants as $variant ) {
            $css = self::readMenuCssAsset( (string) $variant );
            if ( $css !== '' ) {
                $chunks[] = $css;
            }
        }
        return implode( "\n", $chunks );
    }

    private static function readMenuCssAsset( string $variant ): string {
        $name = metis_key_clean( strtolower( trim( $variant ) ) );
        if ( ! in_array( $name, [ 'overlay', 'dropdown', 'inline' ], true ) ) {
            return '';
        }
        $path = METIS_ASSETS_PATH . 'css/website/menu-' . $name . '.css';
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return '';
        }
        $raw = file_get_contents( $path );
        if ( ! is_string( $raw ) ) {
            return '';
        }
        return trim( str_replace( [ "\0", "\r" ], '', $raw ) );
    }

    private static function menuStyleVariantsFromContext( array $context ): array {
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        $content_id = (int) ( $context['content_id'] ?? 0 );
        if ( $content_id < 1 || ! in_array( $content_type, [ 'page', 'post' ], true ) ) {
            return [ 'overlay' ];
        }

        $template = null;
        $blocks = [];
        if ( $content_type === 'page' ) {
            $page = PageService::getById( $content_id );
            if ( $page !== null && $page->status === 'published' ) {
                $template = TemplateService::resolveForPage( $page );
                $blocks = is_array( $page->getBlocks() ) ? $page->getBlocks() : [];
            }
        } elseif ( $content_type === 'post' ) {
            $post = PostService::getById( $content_id );
            if ( $post !== null && $post->status === 'published' ) {
                $template = TemplateService::resolveForPost( $post );
                $blocks = is_array( $post->getBlocks() ) ? $post->getBlocks() : [];
            }
        }

        $variants = [];
        if ( is_array( $template ) ) {
            $regions = is_array( $template['regions'] ?? null ) ? $template['regions'] : [];
            foreach ( [ 'header', 'footer', 'main', 'sidebar', 'banners' ] as $region ) {
                $region_data = is_array( $regions[ $region ] ?? null ) ? $regions[ $region ] : [];
                $region_blocks = is_array( $region_data['blocks'] ?? null ) ? $region_data['blocks'] : [];
                self::collectMenuVariantsFromBlocks( $region_blocks, $variants );
            }
        }
        self::collectMenuVariantsFromBlocks( $blocks, $variants );

        if ( $variants === [] ) {
            $variants[] = 'overlay';
        }
        return array_values( array_unique( $variants ) );
    }

    private static function collectMenuVariantsFromBlocks( array $blocks, array &$variants ): void {
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }
            $type = metis_key_clean( (string) ( $block['type'] ?? '' ) );
            $data = is_array( $block['data'] ?? null ) ? $block['data'] : [];
            if ( $type === 'menu' ) {
                $style = metis_key_clean( (string) ( $data['responsive_style'] ?? 'overlay' ) );
                if ( ! in_array( $style, [ 'overlay', 'dropdown', 'inline' ], true ) ) {
                    $style = 'overlay';
                }
                $variants[] = $style;
            }
            $child_blocks = is_array( $data['blocks'] ?? null ) ? $data['blocks'] : [];
            if ( $child_blocks !== [] ) {
                self::collectMenuVariantsFromBlocks( $child_blocks, $variants );
            }
            $col_blocks = is_array( $data['col_blocks'] ?? null ) ? $data['col_blocks'] : [];
            foreach ( $col_blocks as $col_list ) {
                if ( is_array( $col_list ) && $col_list !== [] ) {
                    self::collectMenuVariantsFromBlocks( $col_list, $variants );
                }
            }
        }
    }

    private static function templateBaseCss(): string {
        return implode( "\n", [
            '.metis-template{max-width:1200px;margin:0 auto;padding:0 16px 0;position:relative;min-height:100vh;display:flex;flex-direction:column;}',
            '.metis-template-header{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:14px 0;position:relative;z-index:1;}',
            '.metis-template-header::before{content:"";position:absolute;z-index:-1;left:calc(50% - 50vw);right:calc(50% - 50vw);top:0;bottom:0;background:var(--metis-color-surface,#fff);border-bottom:0;}',
            '.metis-template-header,.metis-template-header-inner,.metis-template-footer,.metis-template-footer-inner-wrap{border-top:0 !important;border-bottom:0 !important;}',
            '.metis-template-header-brand{display:flex;align-items:center;min-width:0;}',
            '.metis-template-brand{text-decoration:none;color:var(--metis-color-text,#1a1f2b);font-weight:700;font-size:20px;line-height:1.1;}',
            '.metis-template-brand-logo{display:block;max-height:84px;max-width:280px;width:auto;height:auto;object-fit:contain;}',
            '.metis-template-hero-shell{display:grid;gap:16px;align-items:center;}',
            '.metis-template-hero-style-split{grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr);}',
            '.metis-template-hero-style-centered{grid-template-columns:1fr;text-align:center;justify-items:center;}',
            '.metis-template-hero-style-overlay{position:relative;overflow:hidden;min-height:240px;border-radius:18px;padding:24px;background: var(--metis-surface, #fff);}',
            '.metis-template-hero-style-overlay .metis-template-hero-media{position:absolute;inset:0;margin:0;opacity:.28;}',
            '.metis-template-hero-style-overlay .metis-template-hero-copy{position:relative;z-index:2;max-width:760px;}',
            '.metis-template-hero-media{margin:0;}',
            '.metis-template-hero-media img{display:block;width:100%;height:auto;max-height:420px;object-fit:cover;border-radius:16px;border:1px solid var(--metis-color-border,#d8deea);}',
            '.metis-template-hero-title{margin:0 0 10px;font-size:clamp(28px,4vw,46px);line-height:1.1;color:var(--metis-color-text,#1a1f2b);}',
            '.metis-template-hero-subtext{margin:0;color:var(--metis-color-muted,#64748b);font-size:clamp(15px,1.8vw,19px);line-height:1.5;}',
            '.metis-template-hero-actions{margin:16px 0 0;}',
            '.metis-template-hero-button{display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:12px;background:var(--metis-color-primary,#485bc7);color:#fff;text-decoration:none;font-weight:700;line-height:1;}',
            '.metis-template select{appearance:none;-webkit-appearance:none;-moz-appearance:none;background-image: none;background-position:calc(100% - 14px) calc(50% - 2px),calc(100% - 9px) calc(50% - 2px);background-size:5px 5px,5px 5px;background-repeat:no-repeat;padding-right:30px;}',
            '.metis-template-main{padding:18px 0;flex:1 0 auto;}',
            '.metis-template-main-inner{display:block;}',
            '.metis-template-content{min-width:0;}',
            '.metis-template-main[data-has-sidebar="1"] .metis-template-main-inner{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,320px);gap:24px;align-items:start;}',
            '.metis-template-main[data-has-sidebar="1"] .metis-template-sidebar-left{order:1;}',
            '.metis-template-main[data-has-sidebar="1"] .metis-template-content{order:2;}',
            '.metis-template-main[data-has-sidebar="1"] .metis-template-sidebar-right{order:3;}',
            '.metis-template-footer{margin-top:auto;padding-top:16px;position:relative;z-index:1;}',
            '.metis-template-footer::before{content:"";position:absolute;z-index:-1;left:calc(50% - 50vw);right:calc(50% - 50vw);top:0;bottom:0;background:var(--metis-color-surface_alt,#f8fafc);border-top:0;}',
            '.metis-template-footer-inner{display:grid;grid-template-columns:minmax(180px,280px) minmax(0,1fr);gap:18px;align-items:start;}',
            '.metis-template-footer-brand-stack{display:grid;gap:6px;align-content:start;}',
            '.metis-template-footer-brand{font-weight:700;color:var(--metis-color-text,#1a1f2b);}',
            '.metis-template-footer-menu .metis-shell-menu-list{display:flex;flex-wrap:wrap;gap:8px 12px;list-style:none;margin:0;padding:0;}',
            '.metis-template-footer-menu .metis-shell-footer-menu-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,170px));gap:14px;max-width:620px;}',
            '.metis-template-footer-meta{color:var(--metis-color-muted,#64748b);font-size:13px;}',
            '.metis-template-page-header{padding:20px 0 8px;}',
            '.metis-template-page-header-centered{text-align:center;}',
            '.metis-template-post-header{padding:12px 0 18px;display:grid;gap:10px;}',
            '.metis-template-post-header .metis-structured-section__head--post{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;position:relative;left:50%;width:100vw;max-width:none;min-height:120px;margin:0 0 10px -50vw;padding:35px 24px;border:0;border-radius:0;box-shadow:none;text-align:center;box-sizing:border-box;}',
            '.metis-template-post-header .metis-structured-section__head--post h1{margin:0;}',
            '.metis-template-post-meta{color:var(--metis-color-muted,#64748b);font-size:13px;margin:0;}',
            '.metis-template-post-media{margin:0 auto 22px;display:grid;gap:10px;justify-items:center;max-width:min(820px,100%);}',
            '.metis-template-post-media img{display:block;width:100%;border-radius:18px;border:1px solid var(--metis-color-border,#d8deea);box-shadow:0 18px 40px rgba(15,23,42,.08);}',
            '.metis-template-post-media-caption{margin:0;max-width:72ch;font-size:13px;line-height:1.65;color:color-mix(in srgb,var(--metis-color-muted,#64748b) 88%, #fff);text-align:center;font-style:italic;}',
            '.metis-template-hero-region{padding:18px 0;}',
            '.metis-template-hero-region-centered{text-align:center;}',
            '.metis-template-hero-region-glass{background: var(--metis-surface, #fff);border-radius:18px;padding:20px;box-shadow:0 20px 60px rgba(15,23,42,.08);}',
            '.metis-template-header-glass::before{background: var(--metis-surface, #fff);border-bottom:1px solid rgba(148,163,184,.25);}',
            '.metis-template-header-centered .metis-template-header-inner{display:grid;grid-template-columns:1fr;justify-items:center;gap:8px;}',
            '.metis-template-header-centered .metis-template-menu{justify-content:center !important;}',
            '.metis-template-header-overlay{position:relative;}',
            '.metis-template-banner{padding:18px 0;}',
            '.metis-template-banner-inner{border-radius:18px;padding:22px;background: var(--metis-surface, #fff);display:grid;gap:16px;align-items:center;}',
            '.metis-template-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,320px);gap:24px;}',
            '.metis-template-grid-rail{border-radius:16px;padding:14px;background:var(--metis-color-surface_alt,#f8fafc);border:1px solid var(--metis-color-border,#d8deea);}',
            '.metis-template-editorial-shell{display:grid;grid-template-columns:200px minmax(0,1fr);gap:28px;}',
            '.metis-template-editorial-rail{position:sticky;top:18px;align-self:start;display:flex;flex-direction:column;gap:18px;}',
            '.metis-template-menu-vertical .metis-shell-menu-list{flex-direction:column;align-items:flex-start !important;gap:8px;}',
            '.metis-template-header-compact{padding:8px 0;}',
            '.metis-template-compact_app_style .metis-template-header-inner{gap:10px;}',
            '.metis-template-content-centered{max-width:760px;margin:0 auto;}',
            '@media (max-width: 980px){.metis-template-grid{grid-template-columns:1fr;}.metis-template-editorial-shell{grid-template-columns:1fr;}.metis-template-editorial-rail{position:static;flex-direction:row;justify-content:space-between;}}',
            '@media (max-width: 980px){.metis-template{padding:0 12px 0;}.metis-template-header{flex-direction:column;align-items:flex-start;}.metis-template-brand-logo{max-height:72px;max-width:220px;}}',
            '@media (max-width: 980px){.metis-template-hero-style-split{grid-template-columns:1fr;}.metis-template-hero-shell{gap:12px;}}',
            '@media (max-width: 980px){.metis-template-footer-inner{grid-template-columns:1fr;}.metis-template-footer-meta{text-align:left;}}',
            '@media (max-width: 980px){.metis-template-main[data-has-sidebar="1"] .metis-template-main-inner{display:block;}}',
        ] );
    }

    private static function themeTemplateMenuCss(): string {
        return implode( "\n", [
            '.metis-template .metis-template-header{display:block !important;}',
            '.metis-template .metis-template-header-inner{display:flex !important;align-items:center !important;justify-content:space-between !important;gap:18px !important;}',
            '.metis-template .metis-template-header-brand{display:flex;align-items:center;justify-content:flex-start;}',
            '.metis-template .metis-template-menu{display:flex !important;align-items:center !important;justify-content:flex-end !important;min-width:0;overflow:visible;}',
            '.metis-template .metis-template-menu .metis-shell-menu-list{display:flex !important;justify-content:flex-end !important;align-items:var(--metis-menu-item-align,center) !important;flex-wrap:wrap !important;gap:10px var(--metis-menu-item-gap,16px) !important;list-style:none;margin:0;padding:0;overflow:visible;}',
            '.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-link,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn{text-decoration:none;color:var(--metis-color-text,#1a1f2b);font-weight:600;font-size:var(--metis-menu-font-size,14px);padding:0 10px;min-height:40px;display:inline-flex;align-items:var(--metis-menu-item-align,center);justify-content:center;gap:8px;border:0;background:transparent;box-sizing:border-box;cursor:pointer;font-family:inherit;border-radius:var(--metis-menu-item-radius,10px);line-height:1;}',
            '.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn{padding:var(--metis-menu-button-padding-y,10px) var(--metis-menu-button-padding-x,14px);min-height:0;border-radius:var(--metis-menu-button-radius,10px);background:var(--metis-menu-button-bg,var(--metis-color-primary,#485bc7));color:var(--metis-menu-button-text,#fff);border:1px solid var(--metis-menu-button-border,transparent);line-height:1;-webkit-appearance:none;appearance:none;}',
            '.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn--metis_primary,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn--metis_accent,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn--metis_text,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn--metis_surface{background:var(--metis-menu-button-bg,var(--metis-color-primary,#485bc7));color:var(--metis-menu-button-text,#fff);border:1px solid var(--metis-menu-button-border,transparent);}',
            '.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn--metis_primary{--metis-menu-button-bg:var(--metis-color-primary,#485bc7);--metis-menu-button-text:var(--metis-color-button_text,#fff);--metis-menu-button-border:transparent;}',
            '.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn--metis_accent{--metis-menu-button-bg:var(--metis-color-accent,#ff7542);--metis-menu-button-text:var(--metis-color-button_text,#fff);--metis-menu-button-border:transparent;}',
            '.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn--metis_text{--metis-menu-button-bg:var(--metis-color-text,#1a1f2b);--metis-menu-button-text:var(--metis-color-bg,#fff);--metis-menu-button-border:transparent;}',
            '.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn--metis_surface{--metis-menu-button-bg:var(--metis-color-surface,#fff);--metis-menu-button-text:var(--metis-color-text,#1a1f2b);--metis-menu-button-border:var(--metis-color-border,#d8deea);}',
            '.metis-template .metis-template-menu .metis-shell-menu-label:not([tabindex]){cursor:default;}',
            '.metis-template .metis-template-menu .metis-shell-menu-item.has-children{z-index:40;}',
            '.metis-template .metis-template-menu .metis-shell-menu-item.has-children>.metis-shell-menu-label{cursor:pointer;}',
            '.metis-template .metis-template-menu .metis-shell-menu-item .metis-shell-menu-sub-indicator{display:inline-flex;align-items:center;justify-content:center;width:6px;height:6px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg) translateY(-3px);opacity:.9;flex-shrink:0;}',
            '.metis-template .metis-template-menu .metis-shell-menu-sub{z-index:1200;padding:6px;border-radius:var(--metis-menu-dropdown-radius,10px);}',
            '.metis-template .metis-template-menu .metis-shell-menu-sub .metis-shell-menu-item{width:100%;}',
            '.metis-template .metis-template-menu .metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-link,.metis-template .metis-template-menu .metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-btn{display:flex;align-items:var(--metis-menu-item-align,center);justify-content:flex-start;gap:8px;width:100%;box-sizing:border-box;padding:7px 10px;background:transparent;border-radius:8px;color:var(--metis-menu-dropdown-text,var(--metis-color-text,#1a1f2b));font-weight:var(--metis-menu-dropdown-weight,500);transition:background-color .18s ease,color .18s ease,transform .18s ease,box-shadow .18s ease,background-position .24s ease,background-size .24s ease;}',
            self::codepenGlideMenuCss( 'body.metis-menu-style-h_glide .metis-template .metis-template-menu' ),
            self::markerDropdownMenuCss( 'body.metis-menu-style-h_marker_dropdown .metis-template .metis-template-menu' ),
            self::pillDropdownMenuCss( 'body.metis-menu-style-h_pill_dropdown .metis-template .metis-template-menu' ),
            self::modernBarMenuCss( 'body.metis-menu-style-h_modern_bar .metis-template .metis-template-menu' ),
            self::menuButtonAttentionCss( '.metis-template .metis-template-menu' ),
            self::stickyHeaderCss(),
            '@media (max-width: 980px){.metis-template .metis-template-header-inner{display:grid !important;grid-template-columns:minmax(0,1fr) auto !important;align-items:center !important;gap:12px !important;}.metis-template .metis-template-menu{justify-content:flex-start !important;}}',
            self::mobileFlyoutMenuCss(),
            self::mobileFlyoutMenuHardeningCss(),
        ] );
    }

    private static function sanitizeCustomCss( string $css ): string {
        $value = trim( $css );
        if ( $value === '' ) {
            return '';
        }
        $value = str_replace( [ "\0", "\r" ], '', $value );
        $value = preg_replace( '#</style#i', '<\\/style', $value ) ?? $value;
        $value = preg_replace( '#expression\s*\(#i', '', $value ) ?? $value;
        $value = preg_replace( '#javascript:#i', '', $value ) ?? $value;
        return trim( $value );
    }

    private static function renderPopups( array $context ): string {
        $popups = PopupService::getActiveForContext( $context );
        if ( $popups === [] ) {
            return '';
        }

        $items = [];
        $html = '<div class="metis-public-popups">';
        foreach ( $popups as $popup ) {
            $id = (int) ( $popup['id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }

            $layout = json_decode( (string) ( $popup['layout_json'] ?? '{}' ), true );
            if ( ! is_array( $layout ) ) {
                $layout = [];
            }

            $blocks = [];
            if ( isset( $layout['sections'][0]['blocks'] ) && is_array( $layout['sections'][0]['blocks'] ) ) {
                $blocks = $layout['sections'][0]['blocks'];
            } elseif ( isset( $layout['blocks'] ) && is_array( $layout['blocks'] ) ) {
                $blocks = $layout['blocks'];
            }

            $body_html = BlockRenderer::renderBlocks( $blocks, $context );
            if ( trim( $body_html ) === '' ) {
                continue;
            }

            $config = json_decode( (string) ( $popup['trigger_config_json'] ?? '{}' ), true );
            if ( ! is_array( $config ) ) {
                $config = [];
            }

            $items[] = [
                'id' => $id,
                'trigger' => metis_key_clean( (string) ( $popup['trigger_type'] ?? 'click' ) ),
                'delay_ms' => max( 0, (int) ( $config['delay_ms'] ?? 1500 ) ),
                'scroll_percent' => max( 1, min( 100, (int) ( $config['scroll_percent'] ?? 50 ) ) ),
                'frequency' => in_array( (string) ( $config['frequency'] ?? 'session' ), [ 'session', 'persisted', 'always' ], true ) ? (string) $config['frequency'] : 'session',
            ];

            $html .= '<div class="metis-public-popup" id="metis-public-popup-' . metis_escape_attr( (string) $id ) . '" data-popup-id="' . metis_escape_attr( (string) $id ) . '" hidden>';
            $html .= '<div class="metis-public-popup__backdrop" data-popup-close></div>';
            $html .= '<div class="metis-public-popup__dialog" role="dialog" aria-modal="true" aria-label="' . metis_escape_attr( (string) ( $popup['name'] ?? 'Popup' ) ) . '" tabindex="-1">';
            $html .= '<button class="metis-public-popup__close" type="button" aria-label="Close popup" data-popup-close>&times;</button>';
            $html .= '<div class="metis-public-popup__body">' . $body_html . '</div>';
            $html .= '</div></div>';
        }
        $html .= '</div>';

        if ( $items === [] ) {
            return '';
        }

        $html .= '<script>(function(){';
        $defs_json = function_exists( 'metis_json_encode' )
            ? (string) metis_json_encode( $items )
            : (string) json_encode( $items, JSON_UNESCAPED_SLASHES );
        $html .= 'var defs=' . $defs_json . ';';
        $html .= 'if(!Array.isArray(defs)){return;}';
        $html .= 'function key(id){return "metis_popup_seen_"+id;}';
        $html .= 'function seen(id,f){if(f==="persisted"){try{return localStorage.getItem(key(id))==="1";}catch(e){return false;}}if(f==="session"){try{return sessionStorage.getItem(key(id))==="1";}catch(e){return false;}}return false;}';
        $html .= 'function mark(id,f){if(f==="persisted"){try{localStorage.setItem(key(id),"1");}catch(e){}}if(f==="session"){try{sessionStorage.setItem(key(id),"1");}catch(e){}}}';
        $html .= 'var openCount=0;var focusRestore={};';
        $html .= 'function tabbables(node){if(!node){return [];}return Array.prototype.slice.call(node.querySelectorAll(\'a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])\')).filter(function(el){return !el.hidden;});}';
        $html .= 'function getNode(id){return document.getElementById("metis-public-popup-"+id);}';
        $html .= 'function open(id,f){var n=getNode(id);if(!n){return;}if(f!=="always"&&seen(id,f)){return;}if(n.hidden){openCount++;}n.hidden=false;document.body.classList.add("metis-popup-open");document.body.style.overflow="hidden";focusRestore[id]=document.activeElement;var d=n.querySelector(".metis-public-popup__dialog");if(d){var list=tabbables(d);if(list.length){list[0].focus();}else{d.focus();}}mark(id,f);}';
        $html .= 'function close(id){var n=getNode(id);if(!n||n.hidden){return;}n.hidden=true;openCount=Math.max(0,openCount-1);if(openCount<1){document.body.classList.remove("metis-popup-open");document.body.style.overflow="";}var back=focusRestore[id];if(back&&typeof back.focus==="function"){try{back.focus();}catch(e){}}delete focusRestore[id];}';
        $html .= 'document.addEventListener("click",function(ev){var t=ev.target;var c=t&&t.closest? t.closest("[data-popup-close]"):null;if(c){var p=c.closest(".metis-public-popup");if(p){close(Number(p.getAttribute("data-popup-id")||0));}return;}var trg=t&&t.closest? t.closest("[data-metis-popup]"):null;if(trg){var id=Number(trg.getAttribute("data-metis-popup")||0);for(var i=0;i<defs.length;i++){if(defs[i].id===id){open(id,defs[i].frequency);break;}}}});';
        $html .= 'document.addEventListener("keydown",function(ev){if(ev.key==="Escape"){var nodes=document.querySelectorAll(".metis-public-popup:not([hidden])");for(var i=0;i<nodes.length;i++){close(Number(nodes[i].getAttribute("data-popup-id")||0));}return;}if(ev.key!=="Tab"){return;}var active=document.querySelector(".metis-public-popup:not([hidden]) .metis-public-popup__dialog");if(!active){return;}var list=tabbables(active);if(list.length===0){ev.preventDefault();active.focus();return;}var first=list[0];var last=list[list.length-1];if(ev.shiftKey&&document.activeElement===first){ev.preventDefault();last.focus();}else if(!ev.shiftKey&&document.activeElement===last){ev.preventDefault();first.focus();}});';
        $html .= 'defs.forEach(function(def){if(def.trigger==="load"){window.setTimeout(function(){open(def.id,def.frequency);},Math.max(0,def.delay_ms||0));}';
        $html .= 'if(def.trigger==="delay"){window.setTimeout(function(){open(def.id,def.frequency);},Math.max(0,def.delay_ms||1500));}';
        $html .= 'if(def.trigger==="scroll"){var done=false;window.addEventListener("scroll",function(){if(done){return;}var h=document.documentElement.scrollHeight-window.innerHeight;if(h<=0){return;}var pct=(window.scrollY/h)*100;if(pct>=(def.scroll_percent||50)){done=true;open(def.id,def.frequency);}});}';
        $html .= 'if(def.trigger==="exit"){var ex=false;document.addEventListener("mouseout",function(ev){if(ex){return;}var rel=ev.relatedTarget||ev.toElement;if(rel){return;}if(ev.clientY<=0){ex=true;open(def.id,def.frequency);}});}';
        $html .= '});';
        $html .= '})();</script>';

        return $html;
    }

    private static function renderBanners( array $context ): string {
        $banners = BannerService::getActiveForContext( $context );
        if ( $banners === [] ) {
            return '';
        }

        $html = '<div class="metis-public-banners" role="region" aria-label="Site announcements">';
        foreach ( $banners as $banner ) {
            $id = (int) ( $banner['id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }

            $content = json_decode( (string) ( $banner['content_json'] ?? '{}' ), true );
            if ( ! is_array( $content ) ) {
                $content = [];
            }
            $text = function_exists( 'metis_runtime_kses_post' )
                ? metis_runtime_kses_post( (string) ( $content['text'] ?? '' ) )
                : strip_tags( (string) ( $content['text'] ?? '' ), '<p><br><strong><b><em><i><u><ul><ol><li><a><span><div>' );
            $cta_label = metis_text_clean( (string) ( $content['cta_label'] ?? '' ) );
            $cta_url = metis_url_clean( (string) ( $content['cta_url'] ?? '' ) );
            $allow_dismiss = ! empty( $content['allow_dismiss'] );
            $dismiss_mode = metis_key_clean( (string) ( $banner['dismiss_mode'] ?? 'session' ) );

            if ( $text === '' ) {
                continue;
            }

            $html .= '<section class="metis-public-banner metis-banner-' . metis_escape_attr( (string) ( $banner['type'] ?? 'top_banner' ) ) . '"';
            $html .= ' data-banner-id="' . metis_escape_attr( (string) $id ) . '" data-dismiss-mode="' . metis_escape_attr( $dismiss_mode ) . '">';
            $html .= '<div class="metis-public-banner__content">' . $text . '</div>';
            if ( $cta_label !== '' && $cta_url !== '' ) {
                $html .= '<a class="metis-public-banner__cta" href="' . metis_escape_attr( $cta_url ) . '">' . metis_escape_html( $cta_label ) . '</a>';
            }
            if ( $allow_dismiss && $dismiss_mode !== 'none' ) {
                $html .= '<button type="button" class="metis-public-banner__dismiss" aria-label="Dismiss announcement">&times;</button>';
            }
            $html .= '</section>';
        }
        $html .= '</div>';

        $html .= '<script>(function(){';
        $html .= 'function key(id){return "metis_banner_dismiss_"+id;}';
        $html .= 'function isDismissed(id,mode){if(mode==="persisted"){try{return localStorage.getItem(key(id))==="1";}catch(e){return false;}}';
        $html .= 'if(mode==="session"){try{return sessionStorage.getItem(key(id))==="1";}catch(e){return false;}}return false;}';
        $html .= 'function markDismissed(id,mode){if(mode==="persisted"){try{localStorage.setItem(key(id),"1");}catch(e){}}';
        $html .= 'if(mode==="session"){try{sessionStorage.setItem(key(id),"1");}catch(e){}}}';
        $html .= 'var nodes=document.querySelectorAll(".metis-public-banner[data-banner-id]");';
        $html .= 'for(var i=0;i<nodes.length;i++){(function(node){var id=node.getAttribute("data-banner-id");var mode=node.getAttribute("data-dismiss-mode")||"session";';
        $html .= 'if(isDismissed(id,mode)){node.remove();return;}';
        $html .= 'var btn=node.querySelector(".metis-public-banner__dismiss");if(btn){btn.addEventListener("click",function(){markDismissed(id,mode);node.remove();});}})(nodes[i]);}';
        $html .= '})();</script>';

        return $html;
    }
}
