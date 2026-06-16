<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Services;

use Metis\Modules\Website\Services\MenuService;
use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\PostService;

final class ImportService {
    public static function previewFromUpload( array $file, int $user_id ): array {
        self::ensureDependencies();

        $validation = self::validateUpload( $file );
        if ( ! $validation['ok'] ) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Invalid upload.',
            ];
        }

        $runtime_dir = self::runtimeDir();
        $tmp_name = (string) ( $file['tmp_name'] ?? '' );
        $extension = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
        $tmp_path = $runtime_dir . '/upload-' . gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 6 ) ) . '.' . ( $extension !== '' ? $extension : 'tmp' );

        $moved = is_uploaded_file( $tmp_name )
            ? @move_uploaded_file( $tmp_name, $tmp_path )
            : @copy( $tmp_name, $tmp_path );

        if ( ! $moved ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Unable to store uploaded file for parsing.' ];
        }

        $parsed = self::parseUploadFile( $tmp_path, (string) ( $file['name'] ?? '' ) );
        @unlink( $tmp_path );

        if ( empty( $parsed['success'] ) ) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => (string) ( $parsed['error'] ?? 'Unable to parse import export.' ),
            ];
        }

        $import_type = (string) ( $parsed['import_type'] ?? 'wxr' );
        if ( $import_type === 'wxr' ) {
            $parsed['menu_groups'] = \Metis\Modules\Import\Parsers\WxrXmlParser::extractMenus( $parsed );
            $parsed['menus_count'] = count( (array) $parsed['menu_groups'] );
        } else {
            $parsed['menu_groups'] = [];
            $parsed['menus_count'] = 0;
        }

        $stats = is_array( $parsed['stats'] ?? null ) ? $parsed['stats'] : [];
        $pages_count = (int) ( $stats['pages'] ?? 0 );
        $posts_count = (int) ( $stats['posts'] ?? 0 );
        $media_count = (int) ( $stats['media'] ?? 0 );
        $menus_count = (int) ( $parsed['menus_count'] ?? 0 );
        $newsletters_count = (int) ( $stats['newsletters'] ?? 0 );
        $total_items = (int) ( $stats['total_items'] ?? 0 );

        if ( $total_items < 1 ) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => $import_type === 'wordpress_newsletter_archive'
                    ? 'No archived newsletters were found in this export.'
                    : 'No WXR content items were found in this XML export. Re-export using WXR (All content).',
            ];
        }

        if ( $pages_count < 1 && $posts_count < 1 && $media_count < 1 && $menus_count < 1 && $newsletters_count < 1 ) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => $import_type === 'wordpress_newsletter_archive'
                    ? 'Export parsed, but no archived newsletters were found.'
                    : 'Export parsed, but no supported content types were found (pages, posts, media, menus).',
            ];
        }

        self::storePreview( $user_id, $parsed );

        return [
            'ok' => true,
            'preview' => self::buildPreview( $parsed ),
        ];
    }

    public static function confirmImport( int $user_id, array $options ): array {
        self::ensureDependencies();
        self::ensureWebsiteSchema();

        $parsed = self::loadPreview( $user_id );
        if ( $parsed === null ) {
            return [
                'ok' => false,
                'status' => 409,
                'error' => 'Import preview expired or missing. Re-upload the file and preview again.',
            ];
        }

        $import_pages = ! empty( $options['import_pages'] );
        $import_posts = ! empty( $options['import_posts'] );
        $import_menus = ! empty( $options['import_menus'] );
        $import_newsletters = ! empty( $options['import_newsletters'] );

        $selected_page_ids = self::parseIdSelection( $options['selected_page_ids'] ?? [] );
        $selected_post_ids = self::parseIdSelection( $options['selected_post_ids'] ?? [] );
        $selected_newsletter_ids = self::parseIdSelection( $options['selected_newsletter_ids'] ?? [] );

        if ( (string) ( $parsed['import_type'] ?? '' ) === 'wordpress_newsletter_archive' ) {
            if ( ! $import_newsletters ) {
                return [
                    'ok' => true,
                    'results' => [
                        'newsletters' => 0,
                        'lists' => 0,
                        'skipped' => 0,
                        'errors' => [],
                    ],
                ];
            }

            return metis_newsletter_import_wordpress_archive(
                $parsed,
                [
                    'selected_newsletter_ids' => $selected_newsletter_ids,
                ]
            );
        }

        $results = [
            'pages' => 0,
            'posts' => 0,
            'menus' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $source_post_id_to_url = [];

        if ( $import_pages ) {
            foreach ( (array) ( $parsed['pages'] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $source_id = (int) ( $item['post_id'] ?? 0 );
                if ( ! empty( $selected_page_ids ) && ! in_array( $source_id, $selected_page_ids, true ) ) {
                    continue;
                }

                $slug = metis_slug_clean( (string) ( $item['slug'] ?? '' ) );
                if ( $slug === '' ) {
                    $slug = metis_slug_clean( (string) ( $item['title'] ?? '' ) );
                }
                if ( $slug === '' ) {
                    $slug = 'imported-page-' . ( $source_id > 0 ? $source_id : ( $results['pages'] + $results['skipped'] + 1 ) );
                }

                if ( PageService::getBySlug( $slug ) !== null ) {
                    $results['skipped']++;
                    continue;
                }

                [ $layout, $warnings ] = self::convertItemToLayout( $item );
                $warnings = array_values( array_unique( array_filter( array_map( 'strval', $warnings ) ) ) );
                foreach ( $warnings as $warning ) {
                    $results['errors'][] = '[Page ' . $slug . '] ' . $warning;
                }

                $layout_json = self::jsonEncode( $layout );
                $page = PageService::create( [
                    'title' => (string) ( $item['title'] ?? 'Imported Page' ),
                    'slug' => $slug,
                    'status' => 'draft',
                    'draft_layout_json' => $layout_json,
                    'layout_json' => $layout_json,
                    'menu_order' => (int) ( $item['menu_order'] ?? 0 ),
                ] );

                if ( $page === null ) {
                    $results['errors'][] = 'Failed to import page: ' . $slug;
                    $results['skipped']++;
                    continue;
                }

                $results['pages']++;
                if ( $source_id > 0 ) {
                    $source_post_id_to_url[ $source_id ] = '/' . ltrim( (string) $page->slug, '/' );
                }
            }
        }

        if ( $import_posts ) {
            foreach ( (array) ( $parsed['posts'] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $source_id = (int) ( $item['post_id'] ?? 0 );
                if ( ! empty( $selected_post_ids ) && ! in_array( $source_id, $selected_post_ids, true ) ) {
                    continue;
                }

                $slug = metis_slug_clean( (string) ( $item['slug'] ?? '' ) );
                if ( $slug === '' ) {
                    $slug = metis_slug_clean( (string) ( $item['title'] ?? '' ) );
                }
                if ( $slug === '' ) {
                    $slug = 'imported-post-' . ( $source_id > 0 ? $source_id : ( $results['posts'] + $results['skipped'] + 1 ) );
                }

                if ( PostService::getBySlug( $slug ) !== null ) {
                    $results['skipped']++;
                    continue;
                }

                [ $layout, $warnings ] = self::convertItemToLayout( $item );
                $warnings = array_values( array_unique( array_filter( array_map( 'strval', $warnings ) ) ) );
                foreach ( $warnings as $warning ) {
                    $results['errors'][] = '[Post ' . $slug . '] ' . $warning;
                }

                $content_json = self::jsonEncode( $layout );
                $post = PostService::create( [
                    'title' => (string) ( $item['title'] ?? 'Imported Post' ),
                    'slug' => $slug,
                    'status' => 'draft',
                    'excerpt' => (string) ( $item['excerpt'] ?? '' ),
                    'draft_content_json' => $content_json,
                    'content_json' => $content_json,
                    'publish_date' => null,
                ] );

                if ( $post === null ) {
                    $results['errors'][] = 'Failed to import post: ' . $slug;
                    $results['skipped']++;
                    continue;
                }

                $results['posts']++;
                if ( $source_id > 0 ) {
                    $source_post_id_to_url[ $source_id ] = '/blog/' . ltrim( (string) $post->slug, '/' );
                }
            }
        }

        if ( $import_menus ) {
            $menus = (array) ( $parsed['menu_groups'] ?? [] );
            $existing_names = array_map(
                static fn ( array $m ): string => strtolower( (string) ( $m['name'] ?? '' ) ),
                MenuService::getAll()
            );

            foreach ( $menus as $menu_group ) {
                if ( ! is_array( $menu_group ) ) {
                    continue;
                }

                $base_name = trim( (string) ( $menu_group['name'] ?? 'Imported Menu' ) );
                if ( $base_name === '' ) {
                    $base_name = 'Imported Menu';
                }
                $menu_name = self::uniqueMenuName( $base_name, $existing_names );
                $items = [];

                foreach ( (array) ( $menu_group['items'] ?? [] ) as $menu_item ) {
                    if ( ! is_array( $menu_item ) ) {
                        continue;
                    }

                    $label = trim( (string) ( $menu_item['title'] ?? '' ) );
                    $url = trim( (string) ( $menu_item['url'] ?? '' ) );
                    $object_id = (int) ( $menu_item['object_id'] ?? 0 );

                    if ( $url === '' && $object_id > 0 && isset( $source_post_id_to_url[ $object_id ] ) ) {
                        $url = $source_post_id_to_url[ $object_id ];
                    }

                    if ( $label === '' || $url === '' ) {
                        continue;
                    }

                    $items[] = [
                        'label' => $label,
                        'url' => $url,
                        'target' => '',
                    ];
                }

                if ( $items === [] ) {
                    continue;
                }

                $menu_id = MenuService::create( [
                    'name' => $menu_name,
                    'location' => null,
                    'items_json' => self::jsonEncode( $items ),
                    'status' => 'active',
                ] );

                if ( ! $menu_id ) {
                    $results['errors'][] = 'Failed to import menu: ' . $menu_name;
                    $results['skipped']++;
                    continue;
                }

                $existing_names[] = strtolower( $menu_name );
                $results['menus']++;
            }
        }

        return [ 'ok' => true, 'results' => $results ];
    }

    private static function ensureDependencies(): void {
        static $loaded = false;
        if ( $loaded ) {
            return;
        }

        $system_root = dirname( __DIR__, 3 ) . '/';

        require_once $system_root . 'modules/import/parsers/WxrXmlParser.php';
        require_once $system_root . 'modules/import/parsers/WordPressNewsletterArchiveParser.php';
        require_once $system_root . 'modules/import/converters/BeaverBuilderConverter.php';
        require_once $system_root . 'modules/import/converters/HtmlToBlockConverter.php';
        require_once $system_root . 'modules/newsletter/services/import.php';

        $loaded = true;
    }

    private static function ensureWebsiteSchema(): void {
        if ( class_exists( '\\Metis\\Modules\\Website\\SchemaManager' ) ) {
            \Metis\Modules\Website\SchemaManager::ensureSchema();
        }
    }

    private static function validateUpload( array $file ): array {
        $tmp_name = (string) ( $file['tmp_name'] ?? '' );
        $error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
        $size = (int) ( $file['size'] ?? 0 );
        $name = (string) ( $file['name'] ?? '' );

        if ( $error !== UPLOAD_ERR_OK ) {
            return [ 'ok' => false, 'error' => self::uploadErrorMessage( $error ) ];
        }
        if ( $tmp_name === '' || ! is_readable( $tmp_name ) ) {
            return [ 'ok' => false, 'error' => 'Uploaded file is not readable.' ];
        }
        if ( $size < 1 || $size > 32 * 1024 * 1024 ) {
            return [ 'ok' => false, 'error' => 'File must be between 1 byte and 32MB.' ];
        }

        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'xml', 'wxr', 'json' ], true ) ) {
            return [ 'ok' => false, 'error' => 'Only .xml, .wxr, and .json exports are supported.' ];
        }

        return [ 'ok' => true ];
    }

    private static function uploadErrorMessage( int $error ): string {
        $upload_max = (string) ini_get( 'upload_max_filesize' );
        $post_max = (string) ini_get( 'post_max_size' );
        switch ( $error ) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Upload exceeds server size limit (upload_max_filesize=' . $upload_max . ', post_max_size=' . $post_max . ').';
            case UPLOAD_ERR_PARTIAL:
                return 'Upload was interrupted before completion. Please retry.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was received by the server.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server upload temp directory is missing.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server could not write the uploaded file.';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload was blocked by a server extension.';
            default:
                return 'Upload failed with server error code ' . $error . '.';
        }
    }

    private static function runtimeDir(): string {
        $root = defined( 'METIS_PATH' ) ? rtrim( (string) METIS_PATH, '/\\' ) : dirname( __DIR__, 3 );
        $dir = $root . '/storage/runtime/import';
        if ( ! is_dir( $dir ) ) {
            @mkdir( $dir, 0775, true );
        }
        return $dir;
    }

    private static function previewPath( int $user_id ): string {
        return self::runtimeDir() . '/preview-' . max( 0, $user_id ) . '.json';
    }

    private static function storePreview( int $user_id, array $payload ): void {
        $record = [
            'created_at' => time(),
            'payload' => $payload,
        ];

        @file_put_contents( self::previewPath( $user_id ), self::jsonEncode( $record ), LOCK_EX );
    }

    private static function loadPreview( int $user_id ): ?array {
        $path = self::previewPath( $user_id );
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return null;
        }

        $raw = (string) @file_get_contents( $path );
        if ( $raw === '' ) {
            return null;
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        $created_at = (int) ( $decoded['created_at'] ?? 0 );
        if ( $created_at < 1 || ( time() - $created_at ) > 2 * HOUR_IN_SECONDS ) {
            @unlink( $path );
            return null;
        }

        $payload = $decoded['payload'] ?? null;
        return is_array( $payload ) ? $payload : null;
    }

    private static function buildPreview( array $parsed ): array {
        if ( (string) ( $parsed['import_type'] ?? '' ) === 'wordpress_newsletter_archive' ) {
            $newsletters = [];
            foreach ( (array) ( $parsed['newsletters'] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $newsletters[] = [
                    'source_id' => (int) ( $item['source_id'] ?? 0 ),
                    'title' => (string) ( $item['title'] ?? '' ),
                    'subject' => (string) ( $item['subject'] ?? '' ),
                    'sent_at' => (string) ( $item['sent_at'] ?? '' ),
                    'list_names' => array_values( array_map( 'strval', (array) ( $item['list_names'] ?? [] ) ) ),
                ];
            }

            return [
                'import_type' => 'wordpress_newsletter_archive',
                'site_info' => (array) ( $parsed['site_info'] ?? [] ),
                'stats' => (array) ( $parsed['stats'] ?? [ 'newsletters' => count( $newsletters ) ] ),
                'default_list' => (array) ( $parsed['default_list'] ?? [] ),
                'newsletters' => array_slice( $newsletters, 0, 200 ),
                'menus_count' => 0,
                'pages' => [],
                'posts' => [],
            ];
        }

        $pages = [];
        foreach ( (array) ( $parsed['pages'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $pages[] = [
                'post_id' => (int) ( $item['post_id'] ?? 0 ),
                'title' => (string) ( $item['title'] ?? '' ),
                'slug' => (string) ( $item['slug'] ?? '' ),
                'status' => (string) ( $item['status'] ?? '' ),
                'has_bb' => ! empty( $item['beaver_builder_data'] ),
            ];
        }

        $posts = [];
        foreach ( (array) ( $parsed['posts'] ?? [] ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $posts[] = [
                'post_id' => (int) ( $item['post_id'] ?? 0 ),
                'title' => (string) ( $item['title'] ?? '' ),
                'slug' => (string) ( $item['slug'] ?? '' ),
                'status' => (string) ( $item['status'] ?? '' ),
                'has_bb' => ! empty( $item['beaver_builder_data'] ),
            ];
        }

        return [
            'import_type' => 'wxr',
            'site_info' => (array) ( $parsed['site_info'] ?? [] ),
            'stats' => (array) ( $parsed['stats'] ?? [ 'pages' => count( $pages ), 'posts' => count( $posts ), 'media' => 0 ] ),
            'menus_count' => (int) ( $parsed['menus_count'] ?? 0 ),
            'pages' => array_slice( $pages, 0, 200 ),
            'posts' => array_slice( $posts, 0, 200 ),
        ];
    }

    private static function parseUploadFile( string $tmp_path, string $original_name ): array {
        $ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, [ 'xml', 'wxr' ], true ) ) {
            return \Metis\Modules\Import\Parsers\WxrXmlParser::parse( $tmp_path ) + [ 'import_type' => 'wxr' ];
        }

        if ( $ext === 'json' ) {
            return \Metis\Modules\Import\Parsers\WordPressNewsletterArchiveParser::parse( $tmp_path );
        }

        return [
            'success' => false,
            'error' => 'Unsupported import file type.',
        ];
    }

    private static function parseIdSelection( mixed $raw ): array {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            $raw = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $raw ) ) {
            return [];
        }

        $ids = [];
        foreach ( $raw as $value ) {
            $id = (int) $value;
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return array_values( array_unique( $ids ) );
    }

    private static function convertItemToLayout( array $item ): array {
        $warnings = [];

        $bb_data = (string) ( $item['beaver_builder_data'] ?? '' );
        if ( $bb_data !== '' ) {
            $bb = \Metis\Modules\Import\Converters\BeaverBuilderConverter::convert( $bb_data );
            $layout = is_array( $bb['layout'] ?? null ) ? $bb['layout'] : [];
            $bb_warnings = (array) ( $bb['report']['warnings'] ?? [] );
            foreach ( $bb_warnings as $warning ) {
                $warnings[] = (string) $warning;
            }

            if ( ! empty( $layout['sections'] ) ) {
                return [ $layout, $warnings ];
            }
        }

        $html = (string) ( $item['content'] ?? '' );
        $converted = \Metis\Modules\Import\Converters\HtmlToBlockConverter::convert( $html );
        $blocks = is_array( $converted['blocks'] ?? null ) ? $converted['blocks'] : [];
        foreach ( (array) ( $converted['warnings'] ?? [] ) as $warning ) {
            $warnings[] = (string) $warning;
        }

        $layout = [
            'version' => 1,
            'sections' => [
                [
                    'id' => 'section_import_' . (int) ( $item['post_id'] ?? 0 ),
                    'blocks' => $blocks,
                ],
            ],
        ];

        return [ $layout, $warnings ];
    }

    private static function uniqueMenuName( string $base_name, array $existing_lower_names ): string {
        $candidate = $base_name;
        $suffix = 2;

        while ( in_array( strtolower( $candidate ), $existing_lower_names, true ) ) {
            $candidate = $base_name . ' ' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private static function jsonEncode( mixed $value ): string {
        if ( function_exists( 'metis_json_encode' ) ) {
            return (string) metis_json_encode( $value );
        }

        $encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return $encoded === false ? '{}' : $encoded;
    }

}
