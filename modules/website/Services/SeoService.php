<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;
use Metis\Core\Cache\CacheService;

final class SeoService {
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $context
     * @param array<string,mixed> $page_data
     * @param array<int,array<string,mixed>> $sections
     * @param array<string,mixed> $hero
     * @param array<string,string> $token_values
     * @return array<string,mixed>
     */
    public static function buildRenderPayload(
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

        $custom_title = self::resolveTokenString(
            self::seoStringFromKeys( $advanced, [ 'meta_title', 'title' ] ) !== ''
                ? self::seoStringFromKeys( $advanced, [ 'meta_title', 'title' ] )
                : self::seoStringFromKeys( $raw_meta, [ 'meta_title', 'title' ] ),
            $token_values
        );
        $custom_description = self::resolveTokenString(
            self::seoStringFromKeys( $advanced, [ 'meta_description', 'description' ] ) !== ''
                ? self::seoStringFromKeys( $advanced, [ 'meta_description', 'description' ] )
                : self::seoStringFromKeys( $raw_meta, [ 'meta_description', 'description' ] ),
            $token_values
        );
        if ( self::isLowSignalSummary( $custom_description ) ) {
            $custom_description = '';
        }
        $custom_canonical = self::resolveTokenString(
            self::seoStringFromKeys( $advanced, [ 'canonical', 'canonical_url' ] ) !== ''
                ? self::seoStringFromKeys( $advanced, [ 'canonical', 'canonical_url' ] )
                : self::seoStringFromKeys( $raw_meta, [ 'canonical', 'canonical_url' ] ),
            $token_values
        );
        $custom_og_title = self::resolveTokenString(
            self::seoStringFromKeys( $advanced, [ 'og_title' ] ) !== ''
                ? self::seoStringFromKeys( $advanced, [ 'og_title' ] )
                : self::seoStringFromKeys( $raw_meta, [ 'og_title' ] ),
            $token_values
        );
        $custom_og_description = self::resolveTokenString(
            self::seoStringFromKeys( $advanced, [ 'og_description' ] ) !== ''
                ? self::seoStringFromKeys( $advanced, [ 'og_description' ] )
                : self::seoStringFromKeys( $raw_meta, [ 'og_description' ] ),
            $token_values
        );
        if ( self::isLowSignalSummary( $custom_og_description ) ) {
            $custom_og_description = '';
        }
        $custom_og_image = self::resolveTokenString(
            self::seoStringFromKeys( $advanced, [ 'og_image', 'image' ] ) !== ''
                ? self::seoStringFromKeys( $advanced, [ 'og_image', 'image' ] )
                : self::seoStringFromKeys( $raw_meta, [ 'og_image', 'image' ] ),
            $token_values
        );
        $custom_twitter_title = self::resolveTokenString(
            self::seoStringFromKeys( $advanced, [ 'twitter_title' ] ) !== ''
                ? self::seoStringFromKeys( $advanced, [ 'twitter_title' ] )
                : self::seoStringFromKeys( $raw_meta, [ 'twitter_title' ] ),
            $token_values
        );
        $custom_twitter_description = self::resolveTokenString(
            self::seoStringFromKeys( $advanced, [ 'twitter_description' ] ) !== ''
                ? self::seoStringFromKeys( $advanced, [ 'twitter_description' ] )
                : self::seoStringFromKeys( $raw_meta, [ 'twitter_description' ] ),
            $token_values
        );
        if ( self::isLowSignalSummary( $custom_twitter_description ) ) {
            $custom_twitter_description = '';
        }
        $custom_twitter_image = self::resolveTokenString(
            self::seoStringFromKeys( $advanced, [ 'twitter_image' ] ) !== ''
                ? self::seoStringFromKeys( $advanced, [ 'twitter_image' ] )
                : self::seoStringFromKeys( $raw_meta, [ 'twitter_image' ] ),
            $token_values
        );

        $noindex = self::seoBoolFromKeys( $advanced, [ 'noindex' ] )
            || self::seoBoolFromKeys( $raw_meta, [ 'noindex' ] )
            || ! empty( $context['template_preview_mode'] );

        $title = trim( $custom_title !== '' ? $custom_title : $default_title );
        if ( $title === '' ) {
            $title = trim( (string) ( $page_data['title'] ?? '' ) );
        }
        if ( $title === '' ) {
            $title = 'Website';
        }
        $title = self::seoTrim( $title, 70 );

        $description = trim( $custom_description !== '' ? $custom_description : $default_description );
        if ( $description === '' || self::isLowSignalSummary( $description ) ) {
            $description = self::seoDescriptionFromContent( $sections, $hero );
        }
        $description = self::seoTrim( self::seoTextFromHtml( $description ), 160 );
        if ( self::isLowSignalSummary( $description ) ) {
            $description = '';
        }

        $canonical = $custom_canonical !== ''
            ? self::normalizePublicUrl( $custom_canonical )
            : self::normalizePublicUrl( self::normalizedPath( (string) ( $context['path'] ?? '/' ) ) );

        $og_title = self::seoTrim( trim( $custom_og_title !== '' ? $custom_og_title : $title ), 95 );
        $og_description = self::seoTrim(
            self::seoTextFromHtml( trim( $custom_og_description !== '' ? $custom_og_description : $description ) ),
            200
        );

        $image = trim( $custom_og_image );
        if ( $image === '' ) {
            $image = trim( (string) ( $page_data['featured_image_url'] ?? $context['seo_image'] ?? '' ) );
        }
        if ( $image === '' ) {
            $image = trim( (string) ( $hero['media_url'] ?? '' ) );
        }
        if ( $image !== '' ) {
            $image = self::normalizePublicUrl( $image );
        }

        $twitter_title = self::seoTrim( trim( $custom_twitter_title !== '' ? $custom_twitter_title : $og_title ), 95 );
        $twitter_description = self::seoTrim(
            self::seoTextFromHtml( trim( $custom_twitter_description !== '' ? $custom_twitter_description : $og_description ) ),
            200
        );
        $twitter_image = trim( $custom_twitter_image !== '' ? $custom_twitter_image : $image );
        if ( $twitter_image !== '' ) {
            $twitter_image = self::normalizePublicUrl( $twitter_image );
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'og_title' => $og_title,
            'og_description' => $og_description,
            'og_url' => $canonical,
            'og_image' => $image,
            'twitter_card' => $twitter_image !== '' ? 'summary_large_image' : 'summary',
            'twitter_title' => $twitter_title,
            'twitter_description' => $twitter_description,
            'twitter_image' => $twitter_image,
            'noindex' => $noindex,
            'structured_data' => self::structuredDataPayload(
                $context,
                $page_data,
                [
                    'title' => $title,
                    'description' => $description,
                    'canonical' => $canonical,
                    'image' => $image,
                    'noindex' => $noindex,
                ]
            ),
        ];
    }

    /**
     * @param array<string,mixed> $seo_data
     * @param array<string,mixed> $context
     * @return array<int,string>
     */
    public static function buildHeadTags( array $seo_data, array $context = [] ): array {
        $head = [];
        $description = trim( (string) ( $seo_data['description'] ?? '' ) );
        $canonical = trim( (string) ( $seo_data['canonical'] ?? '' ) );
        $noindex = ! empty( $seo_data['noindex'] );
        $robots_content = $noindex ? 'noindex, nofollow' : 'index, follow';
        $og_title = trim( (string) ( $seo_data['og_title'] ?? '' ) );
        $og_description = trim( (string) ( $seo_data['og_description'] ?? '' ) );
        $og_url = trim( (string) ( $seo_data['og_url'] ?? '' ) );
        $og_image = trim( (string) ( $seo_data['og_image'] ?? '' ) );
        $twitter_card = trim( (string) ( $seo_data['twitter_card'] ?? '' ) );
        $twitter_title = trim( (string) ( $seo_data['twitter_title'] ?? '' ) );
        $twitter_description = trim( (string) ( $seo_data['twitter_description'] ?? '' ) );
        $twitter_image = trim( (string) ( $seo_data['twitter_image'] ?? '' ) );
        $structured_data = is_array( $seo_data['structured_data'] ?? null ) ? $seo_data['structured_data'] : [];
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );

        if ( $description !== '' ) {
            $head[] = '  <meta name="description" content="' . metis_escape_attr( $description ) . '">';
        }
        if ( $canonical !== '' ) {
            $head[] = '  <link rel="canonical" href="' . metis_escape_attr( $canonical ) . '">';
        }
        $head[] = '  <meta name="robots" content="' . metis_escape_attr( $robots_content ) . '">';
        if ( $canonical !== '' ) {
            $head[] = '  <meta property="og:url" content="' . metis_escape_attr( $canonical ) . '">';
        } elseif ( $og_url !== '' ) {
            $head[] = '  <meta property="og:url" content="' . metis_escape_attr( $og_url ) . '">';
        }
        if ( $og_title !== '' ) {
            $head[] = '  <meta property="og:title" content="' . metis_escape_attr( $og_title ) . '">';
        }
        if ( $og_description !== '' ) {
            $head[] = '  <meta property="og:description" content="' . metis_escape_attr( $og_description ) . '">';
        }
        $head[] = '  <meta property="og:type" content="' . metis_escape_attr( $content_type === 'post' ? 'article' : 'website' ) . '">';
        if ( $og_image !== '' ) {
            $head[] = '  <meta property="og:image" content="' . metis_escape_attr( $og_image ) . '">';
        }
        if ( $twitter_card !== '' ) {
            $head[] = '  <meta name="twitter:card" content="' . metis_escape_attr( $twitter_card ) . '">';
        }
        if ( $twitter_title !== '' ) {
            $head[] = '  <meta name="twitter:title" content="' . metis_escape_attr( $twitter_title ) . '">';
        }
        if ( $twitter_description !== '' ) {
            $head[] = '  <meta name="twitter:description" content="' . metis_escape_attr( $twitter_description ) . '">';
        }
        if ( $twitter_image !== '' ) {
            $head[] = '  <meta name="twitter:image" content="' . metis_escape_attr( $twitter_image ) . '">';
        }
        if ( $structured_data !== [] ) {
            $json = function_exists( 'metis_json_encode' )
                ? (string) metis_json_encode( $structured_data )
                : (string) json_encode( $structured_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            if ( trim( $json ) !== '' ) {
                $head[] = '  <script type="application/ld+json">' . $json . '</script>';
            }
        }

        return $head;
    }

    public static function renderRobotsTxt(): string {
        return (string) CacheService::remember(
            'website.seo.robots_txt',
            300,
            static function (): string {
                $lines = [
                    'User-agent: *',
                    'Allow: /',
                ];

                $portal_slug = function_exists( 'metis_portal_slug' ) ? trim( (string) metis_portal_slug(), '/' ) : '';
                if ( $portal_slug !== '' ) {
                    $lines[] = 'Disallow: /' . $portal_slug . '/';
                }

                $lines[] = 'Sitemap: ' . self::normalizePublicUrl( '/sitemap.xml' );

                return implode( "\n", $lines ) . "\n";
            }
        );
    }

    public static function renderSitemapXml(): string {
        return (string) CacheService::remember(
            'website.seo.sitemap_xml',
            300,
            static function (): string {
                $entries = self::sitemapEntries();
                $lines = [
                    '<?xml version="1.0" encoding="UTF-8"?>',
                    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
                ];

                foreach ( $entries as $entry ) {
                    $loc = trim( (string) ( $entry['loc'] ?? '' ) );
                    if ( $loc === '' ) {
                        continue;
                    }
                    $lines[] = '  <url>';
                    $lines[] = '    <loc>' . self::escapeXml( $loc ) . '</loc>';
                    $lastmod = trim( (string) ( $entry['lastmod'] ?? '' ) );
                    if ( $lastmod !== '' ) {
                        $lines[] = '    <lastmod>' . self::escapeXml( $lastmod ) . '</lastmod>';
                    }
                    $changefreq = trim( (string) ( $entry['changefreq'] ?? '' ) );
                    if ( $changefreq !== '' ) {
                        $lines[] = '    <changefreq>' . self::escapeXml( $changefreq ) . '</changefreq>';
                    }
                    $priority = trim( (string) ( $entry['priority'] ?? '' ) );
                    if ( $priority !== '' ) {
                        $lines[] = '    <priority>' . self::escapeXml( $priority ) . '</priority>';
                    }
                    $lines[] = '  </url>';
                }

                $lines[] = '</urlset>';
                return implode( "\n", $lines ) . "\n";
            }
        );
    }

    /**
     * @return array<int,array<string,string>>
     */
    private static function sitemapEntries(): array {
        $entries = [];
        $seen = [];

        foreach ( self::publishedPageRows() as $row ) {
            $path = PageService::publishedPathById( (int) ( $row['id'] ?? 0 ) );
            $url = self::canonicalSitemapUrl( $path, (string) ( $row['seo_meta_json'] ?? '' ) );
            if ( $url === '' || isset( $seen[ $url ] ) || self::seoMetaNoindex( (string) ( $row['seo_meta_json'] ?? '' ) ) ) {
                continue;
            }
            $seen[ $url ] = true;
            $entries[] = [
                'loc' => $url,
                'lastmod' => self::sitemapDate( (string) ( $row['updated_at'] ?? $row['published_at'] ?? '' ) ),
                'changefreq' => $path === '/' ? 'daily' : 'weekly',
                'priority' => $path === '/' ? '1.0' : '0.8',
            ];
        }

        foreach ( self::publishedPostRows() as $row ) {
            $path = '';
            $post = PostService::getById( (int) ( $row['id'] ?? 0 ) );
            if ( $post !== null ) {
                $path = (string) PostService::publicPath( $post );
            }
            $url = self::canonicalSitemapUrl( $path, (string) ( $row['seo_meta_json'] ?? '' ) );
            if ( $url === '' || isset( $seen[ $url ] ) || self::seoMetaNoindex( (string) ( $row['seo_meta_json'] ?? '' ) ) ) {
                continue;
            }
            $seen[ $url ] = true;
            $entries[] = [
                'loc' => $url,
                'lastmod' => self::sitemapDate( (string) ( $row['updated_at'] ?? $row['publish_date'] ?? $row['created_at'] ?? '' ) ),
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }

        foreach ( self::publicPeopleRows() as $row ) {
            $slug = metis_slug_clean( (string) ( $row['public_slug'] ?? '' ) );
            if ( $slug === '' ) {
                continue;
            }
            $url = self::normalizePublicUrl( '/people/' . $slug . '/' );
            if ( $url === '' || $url === '#' || isset( $seen[ $url ] ) ) {
                continue;
            }
            $seen[ $url ] = true;
            $entries[] = [
                'loc' => $url,
                'lastmod' => self::sitemapDate( (string) ( $row['public_updated_at'] ?? $row['updated_at'] ?? '' ) ),
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }

        usort(
            $entries,
            static fn ( array $left, array $right ): int => strcmp( (string) ( $left['loc'] ?? '' ), (string) ( $right['loc'] ?? '' ) )
        );

        return $entries;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function publishedPageRows(): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'website_pages' );
        $rows = $db->fetchAll(
            "SELECT id, page_type, seo_meta_json, published_at, updated_at
             FROM {$table}
             WHERE status = 'published'
             ORDER BY menu_order ASC, title ASC"
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function publishedPostRows(): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'website_posts' );
        $rows = $db->fetchAll(
            "SELECT id, seo_meta_json, publish_date, created_at, updated_at
             FROM {$table}
             WHERE status = 'published'
             ORDER BY publish_date DESC, created_at DESC"
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function publicPeopleRows(): array {
        $db = self::db();
        $table = \Metis_Tables::get( 'people' );
        $rows = $db->fetchAll(
            "SELECT public_slug, public_updated_at, updated_at
             FROM {$table}
             WHERE public_slug IS NOT NULL
               AND public_slug <> ''
               AND public_visibility <> 'private'
               AND status = 'active'
             ORDER BY public_sort_order ASC, display_name ASC"
        );

        return is_array( $rows ) ? $rows : [];
    }

    private static function canonicalSitemapUrl( string $path, string $seo_meta_json ): string {
        $path = trim( $path );
        if ( $path === '' ) {
            return '';
        }

        $meta = self::decodeSeoMeta( $seo_meta_json );
        $advanced = is_array( $meta['advanced'] ?? null ) ? $meta['advanced'] : [];
        $custom = self::seoStringFromKeys( $advanced, [ 'canonical', 'canonical_url' ] );
        if ( $custom === '' ) {
            $custom = self::seoStringFromKeys( $meta, [ 'canonical', 'canonical_url' ] );
        }
        if ( $custom !== '' ) {
            $normalized = self::normalizePublicUrl( $custom );
            return $normalized !== '#' ? $normalized : '';
        }

        $normalized = self::normalizePublicUrl( $path );
        return $normalized !== '#' ? $normalized : '';
    }

    private static function seoMetaNoindex( string $seo_meta_json ): bool {
        $meta = self::decodeSeoMeta( $seo_meta_json );
        $advanced = is_array( $meta['advanced'] ?? null ) ? $meta['advanced'] : [];
        return self::seoBoolFromKeys( $advanced, [ 'noindex' ] ) || self::seoBoolFromKeys( $meta, [ 'noindex' ] );
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeSeoMeta( string $seo_meta_json ): array {
        $raw = trim( $seo_meta_json );
        if ( $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $page_data
     * @param array<string,mixed> $seo
     * @return array<string,mixed>
     */
    private static function structuredDataPayload( array $context, array $page_data, array $seo ): array {
        $canonical = trim( (string) ( $seo['canonical'] ?? '' ) );
        if ( $canonical === '' || ! empty( $seo['noindex'] ) ) {
            return [];
        }

        $site_name = class_exists( 'Core_Settings_Service' ) ? trim( (string) \Core_Settings_Service::get( 'org_name', '' ) ) : '';
        if ( $site_name === '' && function_exists( 'metis_portal_name' ) ) {
            $site_name = trim( (string) metis_portal_name() );
        }
        if ( $site_name === '' ) {
            $site_name = 'Website';
        }

        $site_url = function_exists( 'metis_home_url' ) ? self::normalizePublicUrl( '/' ) : '/';
        $description = trim( (string) ( $seo['description'] ?? '' ) );
        $image = trim( (string) ( $seo['image'] ?? '' ) );
        $headline = trim( (string) ( $seo['title'] ?? $page_data['title'] ?? '' ) );
        $content_type = metis_key_clean( (string) ( $context['content_type'] ?? '' ) );
        $published_at = self::schemaDate( (string) ( $page_data['publish_date'] ?? $context['seo_published_at'] ?? '' ) );
        $updated_at = self::schemaDate( (string) ( $page_data['updated_at'] ?? $context['seo_updated_at'] ?? '' ) );

        $graph = [
            [
                '@type' => 'WebSite',
                '@id' => rtrim( $site_url, '/' ) . '#website',
                'url' => $site_url,
                'name' => $site_name,
            ],
        ];

        if ( $content_type === 'post' ) {
            $article = [
                '@type' => 'Article',
                '@id' => $canonical . '#article',
                'mainEntityOfPage' => $canonical,
                'headline' => $headline,
                'url' => $canonical,
                'publisher' => [
                    '@type' => 'Organization',
                    'name' => $site_name,
                ],
            ];
            if ( $description !== '' ) {
                $article['description'] = $description;
            }
            if ( $image !== '' ) {
                $article['image'] = [ $image ];
            }
            if ( $published_at !== '' ) {
                $article['datePublished'] = $published_at;
            }
            if ( $updated_at !== '' ) {
                $article['dateModified'] = $updated_at;
            }
            $author_name = trim( (string) ( $page_data['author_name'] ?? $context['seo_author_name'] ?? '' ) );
            if ( $author_name !== '' ) {
                $article['author'] = [ '@type' => 'Person', 'name' => $author_name ];
                $author_url = trim( (string) ( $page_data['author_url'] ?? $context['seo_author_url'] ?? '' ) );
                if ( $author_url !== '' ) {
                    $article['author']['url'] = self::normalizePublicUrl( $author_url );
                }
            }
            $graph[] = $article;
        } elseif ( $content_type === 'public_person' ) {
            $person = [
                '@type' => 'Person',
                '@id' => $canonical . '#person',
                'name' => $headline,
                'url' => $canonical,
            ];
            if ( $description !== '' ) {
                $person['description'] = $description;
            }
            if ( $image !== '' ) {
                $person['image'] = $image;
            }

            $graph[] = [
                '@type' => 'ProfilePage',
                '@id' => $canonical . '#profile',
                'url' => $canonical,
                'name' => $headline,
                'isPartOf' => [ '@id' => rtrim( $site_url, '/' ) . '#website' ],
                'mainEntity' => [ '@id' => $canonical . '#person' ],
            ];
            $graph[] = $person;
        } else {
            $page = [
                '@type' => 'WebPage',
                '@id' => $canonical . '#webpage',
                'url' => $canonical,
                'name' => $headline,
                'isPartOf' => [ '@id' => rtrim( $site_url, '/' ) . '#website' ],
            ];
            if ( $description !== '' ) {
                $page['description'] = $description;
            }
            if ( $image !== '' ) {
                $page['primaryImageOfPage'] = $image;
            }
            if ( $updated_at !== '' ) {
                $page['dateModified'] = $updated_at;
            }
            $graph[] = $page;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
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

    private static function isLowSignalSummary( string $value ): bool {
        $normalized = strtolower( trim( preg_replace( '/\s+/', ' ', self::seoTextFromHtml( $value ) ) ?? '' ) );
        if ( $normalized === '' ) {
            return false;
        }

        return in_array(
            $normalized,
            [ 'test', 'testing', 'todo', 'tbd', 'placeholder', 'sample', 'draft', 'n/a', 'na', 'none' ],
            true
        );
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
                foreach ( (array) ( $content['rows'] ?? [] ) as $row ) {
                    if ( ! is_array( $row ) ) {
                        continue;
                    }
                    $text = self::seoTextFromHtml( (string) ( $row['text'] ?? '' ) );
                    if ( $text !== '' ) {
                        return self::seoTrim( $text, 160 );
                    }
                }
            }
        }

        return '';
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
                return $token !== '' && isset( $tokens[ $token ] ) ? (string) $tokens[ $token ] : '';
            },
            $value
        );
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

        return str_starts_with( $url, '/' ) ? $url : '/' . ltrim( $url, '/' );
    }

    private static function sanitizeAbsoluteUrl( string $url ): string {
        return (string) preg_replace( '#^(https?://[^/]+?)\.(?=/)#i', '$1', trim( $url ) );
    }

    private static function normalizedPath( string $path ): string {
        $normalized = self::comparablePathFromUrl( $path );
        if ( $normalized === '' ) {
            return '/';
        }
        return str_starts_with( $normalized, '/' ) ? $normalized : '/' . $normalized;
    }

    private static function comparablePathFromUrl( string $url ): string {
        $url = trim( $url );
        if ( $url === '' ) {
            return '';
        }

        $path = (string) ( parse_url( $url, PHP_URL_PATH ) ?? '' );
        if ( $path === '' ) {
            $path = $url;
        }
        $path = '/' . trim( $path, '/' );
        return $path === '//' ? '/' : $path;
    }

    private static function escapeXml( string $value ): string {
        return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    private static function sitemapDate( string $value ): string {
        $timestamp = strtotime( trim( $value ) );
        return $timestamp !== false ? gmdate( 'Y-m-d', $timestamp ) : '';
    }

    private static function schemaDate( string $value ): string {
        $timestamp = strtotime( trim( $value ) );
        return $timestamp !== false ? gmdate( 'c', $timestamp ) : '';
    }

    private static function db(): object {
        return function_exists( 'metis_resolve_db_service' ) ? \metis_resolve_db_service() : Application::service( 'db' );
    }
}
