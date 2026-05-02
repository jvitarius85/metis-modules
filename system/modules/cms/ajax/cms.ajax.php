<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Cms\Services\PageService;
use Metis\Modules\Cms\Services\PostService;
use Metis\Modules\Cms\Services\MenuService;
use Metis\Modules\Cms\Services\BannerService;
use Metis\Modules\Cms\Services\PopupService;
use Metis\Modules\Cms\Services\ThemeService;
use Metis\Modules\Cms\Services\RedirectService;
use Metis\Modules\Cms\Services\PostCategoryService;
use Metis\Modules\Cms\Services\LayoutProfileService;
use Metis\Modules\Cms\Services\HomepageService;
use Metis\Modules\Cms\Services\TemplateService;
use Metis\Modules\Cms\Services\CmsLaunchService;
use Metis\Modules\Cms\Services\RevisionTimelineService;
use Metis\Modules\Cms\Services\EditorContextPolicy;
use Metis\Modules\Cms\Services\EditorLayoutService;
use Metis\Modules\Cms\Services\ReusableBlockService;
use Metis\Modules\Cms\Services\BlockRenderer;
use Metis\Modules\Cms\Services\CmsRenderer;
use Metis\Modules\Cms\Services\StructuredCmsBuilderService;
use Metis\Modules\Cms\BlockRegistry;
use Metis\Core\Editor\EditorAutosaveService;
use Metis\Core\Editor\EditorVersionService;
use Metis\Core\Editor\EditorManager;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $metis_cms_ajax_permissions = [
        'metis_cms_block_registry' => 'view',
        'metis_cms_pages_list' => 'view',
        'metis_cms_page_get' => 'view',
        'metis_cms_page_create' => 'create',
        'metis_cms_page_save' => 'edit',
        'metis_cms_homepage_set' => 'publish',
        'metis_cms_page_publish' => 'publish',
        'metis_cms_page_unpublish' => 'publish',
        'metis_cms_page_delete' => 'delete',
        'metis_cms_posts_list' => 'view',
        'metis_cms_post_get' => 'view',
        'metis_cms_post_create' => 'create',
        'metis_cms_post_save' => 'edit',
        'metis_cms_post_publish' => 'publish',
        'metis_cms_post_delete' => 'delete',
        'metis_cms_post_categories_list' => 'view',
        'metis_cms_post_category_save' => 'edit',
        'metis_cms_post_category_delete' => 'delete',
        'metis_cms_menus_list' => 'view',
        'metis_cms_menu_save' => 'manage_menus',
        'metis_cms_menu_delete' => 'manage_menus',
        'metis_cms_banners_list' => 'view',
        'metis_cms_banner_save' => 'manage_banners',
        'metis_cms_banner_delete' => 'manage_banners',
        'metis_cms_popups_list' => 'view',
        'metis_cms_popup_save' => 'manage_popups',
        'metis_cms_popup_delete' => 'manage_popups',
        'metis_cms_redirects_list' => 'view',
        'metis_cms_redirect_save' => 'manage_redirects',
        'metis_cms_redirect_delete' => 'manage_redirects',
        'metis_cms_templates_list' => 'view',
        'metis_cms_template_get' => 'view',
        'metis_cms_template_save' => 'manage_templates',
        'metis_cms_template_delete' => 'manage_templates',
        'metis_cms_theme_get' => 'view',
        'metis_cms_theme_save' => 'manage_theme',
        'metis_cms_launch_status' => 'view',
        'metis_cms_launch_enable' => 'launch',
        'metis_cms_launch_disable' => 'launch',
        'metis_cms_layout_profile_save' => 'manage_theme',
        'metis_cms_editor_properties_options' => 'view',
        'metis_cms_editor_media_upload' => 'manage_media',
        'metis_cms_blocks_list' => 'view',
        'metis_cms_reusable_blocks_list' => 'view',
        'metis_cms_reusable_block_get' => 'view',
        'metis_cms_reusable_block_save' => 'edit',
        'metis_core_editor_config' => 'view',
    ];

    foreach ( $metis_cms_ajax_permissions as $action => $permission ) {
        metis_ajax_register_controller( $action, [
            'module' => 'cms',
            'permission' => $permission,
        ] );
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function metis_cms_ajax_verify_nonce(): void {
    $nonce = isset( $_POST['nonce'] ) && is_scalar( $_POST['nonce'] )
        ? trim( (string) metis_runtime_unslash( $_POST['nonce'] ) )
        : '';
    $action_nonce = isset( $_POST['metis_action_nonce'] ) && is_scalar( $_POST['metis_action_nonce'] )
        ? trim( (string) metis_runtime_unslash( $_POST['metis_action_nonce'] ) )
        : '';
    $action = isset( $_POST['action'] ) && is_scalar( $_POST['action'] )
        ? metis_key_clean( (string) metis_runtime_unslash( $_POST['action'] ) )
        : '';

    $valid = false;
    if ( $nonce !== '' && function_exists( 'metis_runtime_verify_nonce' ) ) {
        $valid = metis_runtime_verify_nonce( $nonce, 'metis_cms' );
    }

    if ( ! $valid && $action !== '' && function_exists( 'metis_runtime_verify_nonce' ) ) {
        $action_nonce_key = function_exists( 'metis_ajax_nonce_action' )
            ? metis_ajax_nonce_action( $action )
            : ( 'metis_ajax:' . $action );

        if ( $action_nonce !== '' ) {
            $valid = metis_runtime_verify_nonce( $action_nonce, $action_nonce_key );
        }

        if ( ! $valid && $nonce !== '' ) {
            $valid = metis_runtime_verify_nonce( $nonce, $action_nonce_key );
        }
    }

    if ( ! $valid ) {
        metis_runtime_send_json_error( [ 'message' => 'Security check failed.', 'code' => 'invalid_nonce' ], 403 );
    }
}

function metis_cms_ajax_require_permission( string $key ): void {
    if ( ! metis_security_user_can( $key ) ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

function metis_cms_ajax_author_full_name( int $user_id ): string {
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
            $first = trim( (string) ( $auth_user['first_name'] ?? '' ) );
            $last = trim( (string) ( $auth_user['last_name'] ?? '' ) );
            $full = trim( $first . ' ' . $last );
            if ( $full !== '' ) {
                return $full;
            }
            $login = trim( (string) ( $auth_user['user_login'] ?? '' ) );
            if ( $login !== '' ) {
                return $login;
            }
        }
    }

    return '';
}

function metis_cms_ajax_users_table(): string {
    $db = metis_db();
    $connection = $db->connection();
    $prefix = is_object( $connection ) && isset( $connection->prefix ) ? (string) $connection->prefix : '';
    $candidates = [];
    if ( $prefix !== '' ) {
        $prefixed = $prefix . 'users';
        if ( $prefixed !== 'users' ) {
            $candidates[] = $prefixed;
        }
    }
    $candidates[] = 'users';
    foreach ( array_unique( $candidates ) as $candidate ) {
        $found = $db->scalar( 'SHOW TABLES LIKE %s', [ $candidate ] );
        if ( is_string( $found ) && $found === $candidate ) {
            return $candidate;
        }
    }
    return 'users';
}

/**
 * @return array<int,array{value:string,label:string}>
 */
function metis_cms_ajax_author_options(): array {
    $db = metis_db();
    $table = metis_cms_ajax_users_table();
    $rows = $db->fetchAll(
        "SELECT ID, user_login, display_name, first_name, last_name, user_email FROM {$table} ORDER BY COALESCE(NULLIF(display_name,''), user_login) ASC LIMIT 250",
        []
    );
    if ( ! is_array( $rows ) ) {
        return [];
    }
    $options = [];
    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $id = (int) ( $row['ID'] ?? 0 );
        if ( $id < 1 ) {
            continue;
        }
        $first = trim( (string) ( $row['first_name'] ?? '' ) );
        $last = trim( (string) ( $row['last_name'] ?? '' ) );
        $full = trim( $first . ' ' . $last );
        if ( $full === '' ) {
            $full = trim( (string) ( $row['display_name'] ?? '' ) );
        }
        if ( $full === '' ) {
            $full = trim( (string) ( $row['user_login'] ?? '' ) );
        }
        if ( $full === '' ) {
            continue;
        }
        $options[] = [
            'value' => (string) $id,
            'label' => $full,
        ];
    }
    return $options;
}

/**
 * @return array<string,mixed>
 */
function metis_cms_ajax_decode_seo_meta_json( ?string $seo_meta_json ): array {
    $raw = trim( (string) $seo_meta_json );
    if ( $raw === '' ) {
        return [];
    }
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

/**
 * @param array<string,mixed> $meta
 */
function metis_cms_ajax_encode_seo_meta_json( array $meta ): ?string {
    if ( $meta === [] ) {
        return null;
    }
    if ( function_exists( 'metis_json_encode' ) ) {
        return (string) metis_json_encode( $meta );
    }
    $encoded = json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    return is_string( $encoded ) ? $encoded : null;
}

function metis_cms_ajax_merge_editor_meta_into_seo_meta( ?string $seo_meta_json, array $editor_patch ): ?string {
    $meta = metis_cms_ajax_decode_seo_meta_json( $seo_meta_json );
    $editor = isset( $meta['_editor'] ) && is_array( $meta['_editor'] ) ? $meta['_editor'] : [];
    foreach ( $editor_patch as $key => $value ) {
        if ( $value === null || $value === '' ) {
            unset( $editor[ $key ] );
            continue;
        }
        $editor[ $key ] = $value;
    }
    if ( $editor === [] ) {
        unset( $meta['_editor'] );
    } else {
        $meta['_editor'] = $editor;
    }
    return metis_cms_ajax_encode_seo_meta_json( $meta );
}

function metis_cms_ajax_page_section_count( array $page_row ): int {
    $seo = metis_cms_ajax_decode_seo_meta_json( isset( $page_row['seo_meta_json'] ) ? (string) $page_row['seo_meta_json'] : null );
    $editor = isset( $seo['_editor'] ) && is_array( $seo['_editor'] ) ? $seo['_editor'] : [];
    $stored = isset( $editor['section_count'] ) ? (int) $editor['section_count'] : 0;
    if ( $stored > 0 ) {
        return max( 1, min( 12, $stored ) );
    }
    $layout_raw = (string) ( $page_row['draft_layout_json'] ?? $page_row['layout_json'] ?? '' );
    if ( $layout_raw === '' ) {
        return 0;
    }
    $layout = metis_cms_ajax_decode_json_array( $layout_raw );
    if ( $layout !== [] ) {
        $structured_meta = StructuredCmsBuilderService::structuredMetaFromDecodedLayout( $layout );
        $structured_sections = isset( $structured_meta['sections'] ) && is_array( $structured_meta['sections'] )
            ? $structured_meta['sections']
            : [];
        if ( $structured_sections !== [] ) {
            return count( $structured_sections );
        }
    }
    $summary = metis_cms_ajax_layout_content_summary( $layout_raw );
    $structured_count = (int) ( $summary['structured_sections_count'] ?? 0 );
    if ( $structured_count > 0 ) {
        return $structured_count;
    }
    return (int) ( $summary['sections_count'] ?? 0 );
}

/**
 * @return array<int,array<string,mixed>>
 */
function metis_cms_ajax_post_category_options(): array {
    $options = [];
    foreach ( PostCategoryService::all() as $category ) {
        if ( ! is_array( $category ) ) {
            continue;
        }
        $id = (int) ( $category['id'] ?? 0 );
        if ( $id < 1 ) {
            continue;
        }
        $parent_id = (int) ( $category['parent_id'] ?? 0 );
        $parent = $parent_id > 0 ? PostCategoryService::getById( $parent_id ) : null;
        $options[] = [
            'value' => (string) $id,
            'label' => trim( (string) ( $category['name'] ?? '' ) ),
            'name' => (string) ( $category['name'] ?? '' ),
            'slug' => (string) ( $category['slug'] ?? '' ),
            'parent_id' => $parent_id,
            'parent_slug' => is_array( $parent ) ? (string) ( $parent['slug'] ?? '' ) : '',
            'depth' => (int) ( $category['depth'] ?? 0 ),
        ];
    }
    return $options;
}

/**
 * @return array<string,mixed>
 */
function metis_cms_ajax_post_category_payload( array $row ): array {
    $category_id = isset( $row['post_category_id'] ) ? (int) $row['post_category_id'] : 0;
    $category_ids = [];
    if ( isset( $row['post_category_ids'] ) && is_array( $row['post_category_ids'] ) ) {
        $category_ids = array_values( array_unique( array_filter( array_map( 'intval', $row['post_category_ids'] ), static fn( int $id ): bool => $id > 0 ) ) );
    }
    if ( $category_id < 1 ) {
        $legacy_id = PostCategoryService::categoryIdForPostMeta( isset( $row['seo_meta_json'] ) ? (string) $row['seo_meta_json'] : null );
        $category_id = $legacy_id !== null ? (int) $legacy_id : 0;
    }
    if ( $category_id > 0 && ! in_array( $category_id, $category_ids, true ) ) {
        array_unshift( $category_ids, $category_id );
    }
    if ( $category_ids === [] && $category_id > 0 ) {
        $category_ids = [ $category_id ];
    }
    $row['post_category_id'] = $category_id > 0 ? $category_id : null;
    $row['category_id'] = $category_id > 0 ? $category_id : 0;
    $row['category'] = $category_id > 0 ? PostCategoryService::categoryNameById( $category_id ) : '';
    $row['post_category_ids'] = $category_ids;
    $row['category_ids'] = $category_ids;
    $row['categories'] = array_values( array_filter( array_map(
        static function ( int $id ): ?array {
            $category = PostCategoryService::getById( $id );
            if ( ! is_array( $category ) ) {
                return null;
            }
            return [
                'id' => (int) ( $category['id'] ?? 0 ),
                'name' => (string) ( $category['name'] ?? '' ),
                'slug' => (string) ( $category['slug'] ?? '' ),
            ];
        },
        $category_ids
    ) ) );
    return $row;
}

/**
 * @return array<int,int>
 */
function metis_cms_ajax_post_category_ids_input(): array {
    $raw = $_POST['post_category_ids'] ?? ( $_POST['category_ids'] ?? [] );
    if ( is_string( $raw ) ) {
        $decoded = json_decode( (string) metis_runtime_unslash( $raw ), true );
        if ( is_array( $decoded ) ) {
            $raw = $decoded;
        } else {
            $raw = array_filter( array_map( 'trim', explode( ',', (string) metis_runtime_unslash( $raw ) ) ), static fn( string $value ): bool => $value !== '' );
        }
    }
    if ( ! is_array( $raw ) ) {
        $raw = [];
    }
    $ids = [];
    foreach ( $raw as $raw_id ) {
        $id = (int) $raw_id;
        if ( $id > 0 && PostCategoryService::getById( $id ) !== null ) {
            $ids[] = $id;
        }
    }
    return array_values( array_unique( $ids ) );
}

function metis_cms_ajax_post_category_ids_with_default( array $ids ): array {
    if ( $ids !== [] ) {
        return $ids;
    }

    $default_id = PostCategoryService::defaultCategoryId();
    if ( $default_id === null || $default_id < 1 ) {
        return [];
    }

    return [ (int) $default_id ];
}

function metis_cms_ajax_normalize_post_parent_page_id( int $parent_page_id, bool $require_published ): int {
    if ( $parent_page_id < 1 ) {
        return 0;
    }
    $page = PageService::getById( $parent_page_id );
    if ( $page === null ) {
        return 0;
    }
    if ( ! $require_published ) {
        return $parent_page_id;
    }
    if ( method_exists( PageService::class, 'publishedPathById' ) && (string) PageService::publishedPathById( $parent_page_id ) !== '' ) {
        return $parent_page_id;
    }
    return 0;
}

/**
 * @return array<int,array{value:string,label:string}>
 */
function metis_cms_ajax_form_options(): array {
    if ( ! class_exists( '\Metis\Modules\Forms\Repository' ) ) {
        return [];
    }
    $rows = \Metis\Modules\Forms\Repository::listForms();
    $options = [];
    foreach ( (array) $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $id = (int) ( $row['id'] ?? 0 );
        if ( $id < 1 ) {
            continue;
        }
        $name = trim( (string) ( $row['name'] ?? '' ) );
        $slug = trim( (string) ( $row['slug'] ?? '' ) );
        $label = $name !== '' ? $name : ( $slug !== '' ? $slug : ( 'Form #' . $id ) );
        $options[] = [ 'value' => (string) $id, 'label' => $label ];
    }
    return $options;
}

/**
 * @return array<int,array{value:string,label:string}>
 */
function metis_cms_ajax_donation_campaign_options(): array {
    $db = metis_db();
    $table = \Metis_Tables::get( 'campaigns' );
    if ( $table === '' ) {
        return [];
    }
    $rows = $db->fetchAll(
        "SELECT id, cid, campaign_code, code, cname FROM {$table} ORDER BY id DESC LIMIT 200",
        []
    );
    $options = [];
    foreach ( (array) $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $value = trim( (string) ( $row['cid'] ?? $row['campaign_code'] ?? $row['code'] ?? '' ) );
        if ( $value === '' ) {
            $id = (int) ( $row['id'] ?? 0 );
            if ( $id > 0 ) {
                $value = (string) $id;
            }
        }
        if ( $value === '' ) {
            continue;
        }
        $name = trim( (string) ( $row['cname'] ?? '' ) );
        $label = $name !== '' ? $name : ( 'Campaign ' . $value );
        $options[] = [ 'value' => $value, 'label' => $label ];
    }
    return $options;
}

/**
 * @return array<int,array{value:string,label:string}>
 */
function metis_cms_ajax_calendar_source_options(): array {
    if ( function_exists( 'metis_calendar_workspace_settings_all' ) ) {
        $workspace = metis_calendar_workspace_settings_all();
        $configs = isset( $workspace['calendars'] ) && is_array( $workspace['calendars'] ) ? $workspace['calendars'] : [];
        $options = [];
        foreach ( $configs as $cfg ) {
            if ( ! is_array( $cfg ) ) {
                continue;
            }
            $id = trim( (string) ( $cfg['calendar_id'] ?? '' ) );
            if ( $id === '' ) {
                continue;
            }
            $label = trim( (string) ( $cfg['label'] ?? $cfg['name'] ?? '' ) );
            $options[] = [ 'value' => $id, 'label' => ( $label !== '' ? $label : $id ) ];
        }
        if ( $options !== [] ) {
            return $options;
        }
    }

    $db = metis_db();
    $table = \Metis_Tables::get( 'calendar_events' );
    if ( $table === '' ) {
        return [];
    }
    $rows = $db->fetchAll(
        "SELECT calendar_id FROM {$table} WHERE calendar_id IS NOT NULL AND calendar_id <> '' GROUP BY calendar_id ORDER BY calendar_id ASC LIMIT 100",
        []
    );
    $options = [];
    foreach ( (array) $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $id = trim( (string) ( $row['calendar_id'] ?? '' ) );
        if ( $id === '' ) {
            continue;
        }
        $options[] = [ 'value' => $id, 'label' => $id ];
    }
    return $options;
}

/**
 * @return array<int,array{id:int,value:string,label:string,url:string,mime:string}>
 */
function metis_cms_ajax_media_options(): array {
    $db = metis_db();
    $table = function_exists( 'metis_media_table_name' ) ? metis_media_table_name() : \Metis_Tables::get( 'media_files' );
    if ( $table === '' ) {
        return [];
    }
    $rows = $db->fetchAll(
        "SELECT id, public_token, file_name, mime_type, created_at FROM {$table} ORDER BY created_at DESC LIMIT 200",
        []
    );
    $options = [];
    foreach ( (array) $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $token = trim( (string) ( $row['public_token'] ?? '' ) );
        if ( $token === '' ) {
            continue;
        }
        $name = trim( (string) ( $row['file_name'] ?? '' ) );
        $mime = trim( (string) ( $row['mime_type'] ?? '' ) );
        $url = function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/media/' . $token ) : ( '/media/' . $token );
        $options[] = [
            'id' => isset( $row['id'] ) ? (int) $row['id'] : 0,
            'value' => $token,
            'label' => $name !== '' ? $name : $token,
            'url' => $url,
            'mime' => $mime,
        ];
    }
    return $options;
}

/**
 * @return array<string,mixed>
 */
function metis_cms_ajax_decode_json_array( $raw ): array {
    if ( is_array( $raw ) ) {
        return $raw;
    }
    if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
        return [];
    }

    $raw_trimmed = trim( preg_replace( '/^\xEF\xBB\xBF/', '', $raw ) ?? $raw );
    $candidates = [ $raw_trimmed ];
    if ( str_contains( $raw_trimmed, '%' ) ) {
        $candidates[] = trim( (string) rawurldecode( $raw_trimmed ) );
    }
    if ( str_contains( $raw_trimmed, '&' ) ) {
        $candidates[] = trim( html_entity_decode( $raw_trimmed, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }
    if ( strlen( $raw_trimmed ) >= 2 ) {
        $first = $raw_trimmed[0];
        $last = $raw_trimmed[ strlen( $raw_trimmed ) - 1 ];
        if ( ( $first === '"' && $last === '"' ) || ( $first === '\'' && $last === '\'' ) ) {
            $candidates[] = trim( substr( $raw_trimmed, 1, -1 ) );
        }
    }

    $seen = [];
    foreach ( $candidates as $candidate ) {
        if ( $candidate === '' || isset( $seen[ $candidate ] ) ) {
            continue;
        }
        $seen[ $candidate ] = true;

        $current = $candidate;
        for ( $attempt = 0; $attempt < 4; $attempt++ ) {
            if ( $current === '' ) {
                break;
            }
            $decoded = json_decode( $current, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
            if ( ! is_string( $decoded ) ) {
                break;
            }
            $current = trim( $decoded );
        }
    }

    return [];
}

/**
 * @return array<int,mixed>
 */
function metis_cms_ajax_blocks_from_layout_json( $raw_json ): array {
    return EditorLayoutService::modulesFromLayout( $raw_json );
}

function metis_cms_ajax_assert_layout_valid( $raw_json ): void {
    $result = EditorLayoutService::validateLayout( $raw_json );
    if ( ! (bool) ( $result['valid'] ?? false ) ) {
        $errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : [];
        $first = $errors !== [] && is_array( $errors[0] ?? null ) ? (string) ( $errors[0]['message'] ?? '' ) : '';
        $message = $first !== '' ? $first : 'Layout is invalid.';
        metis_runtime_send_json_error(
            [
                'message' => $message,
                'errors' => $errors,
            ],
            422
        );
    }
}

/**
 * @param array<string,mixed> $options
 * @return array{layout_json:string,page_type:string,template_key:string,template_override:bool,hero:array<string,mixed>,sections:array<int,array<string,mixed>>}
 */
function metis_cms_ajax_normalize_structured_layout( $raw_layout, array $options = [] ): array {
    $normalized_options = $options;
    $requested_template = isset( $normalized_options['template_key'] ) ? metis_key_clean( (string) $normalized_options['template_key'] ) : '';
    if ( $requested_template === '' ) {
        $normalized_options['template_key'] = TemplateService::getActiveTemplateSlug();
    }

    $normalized = StructuredCmsBuilderService::normalizeLayout( $raw_layout, $normalized_options );
    return [
        'layout_json' => (string) ( $normalized['layout_json'] ?? '{}' ),
        'page_type' => (string) ( $normalized['page_type'] ?? 'page' ),
        'template_key' => (string) (
            $normalized['template_key']
            ?? StructuredCmsBuilderService::defaultTemplateForPageType( 'page' )
        ),
        'template_override' => ! empty( $normalized['template_override'] ),
        'hero' => isset( $normalized['hero'] ) && is_array( $normalized['hero'] ) ? $normalized['hero'] : [],
        'sections' => isset( $normalized['sections'] ) && is_array( $normalized['sections'] ) ? $normalized['sections'] : [],
    ];
}

function metis_cms_ajax_html_has_meaningful_content( string $html ): bool {
    $normalized = preg_replace( '/<br\s*\/?\s*>/i', ' ', $html ) ?? $html;
    $normalized = preg_replace( '/&nbsp;/i', ' ', $normalized ) ?? $normalized;
    $text = trim( strip_tags( html_entity_decode( $normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
    return $text !== '';
}

function metis_cms_ajax_text_candidate_is_meaningful( string $value ): bool {
    $trimmed = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    if ( $trimmed === '' ) {
        return false;
    }
    if ( metis_cms_ajax_html_has_meaningful_content( $trimmed ) ) {
        return true;
    }
    if ( preg_match( '/^https?:\/\//i', $trimmed ) === 1 ) {
        return false;
    }
    if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $trimmed ) === 1 ) {
        return false;
    }
    if ( preg_match( '/^[a-z0-9._-]+$/i', $trimmed ) === 1 && strlen( $trimmed ) <= 32 ) {
        return false;
    }
    return preg_match( '/[a-z]{2,}/i', $trimmed ) === 1;
}

function metis_cms_ajax_payload_has_meaningful_text( $value, int $depth = 0 ): bool {
    if ( $depth > 8 ) {
        return false;
    }
    if ( is_string( $value ) ) {
        return metis_cms_ajax_text_candidate_is_meaningful( $value );
    }
    if ( ! is_array( $value ) ) {
        return false;
    }

    $content_keys = [
        'content',
        'text',
        'label',
        'title',
        'caption',
        'html',
        'body',
        'description',
        'excerpt',
        'alt',
        'subtitle',
        'headline',
    ];
    foreach ( $content_keys as $key ) {
        if ( ! array_key_exists( $key, $value ) ) {
            continue;
        }
        if ( is_string( $value[ $key ] ) && metis_cms_ajax_text_candidate_is_meaningful( (string) $value[ $key ] ) ) {
            return true;
        }
        if ( is_array( $value[ $key ] ) && metis_cms_ajax_payload_has_meaningful_text( $value[ $key ], $depth + 1 ) ) {
            return true;
        }
    }

    foreach ( $value as $child ) {
        if ( metis_cms_ajax_payload_has_meaningful_text( $child, $depth + 1 ) ) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string,mixed>
 */
function metis_cms_ajax_layout_content_summary( $raw_json ): array {
    $decoded = metis_cms_ajax_decode_json_array( $raw_json );
    $meta = isset( $decoded['editor_meta'] ) && is_array( $decoded['editor_meta'] ) ? $decoded['editor_meta'] : [];
    $sections = isset( $decoded['sections'] ) && is_array( $decoded['sections'] ) ? $decoded['sections'] : [];
    $structured = isset( $meta['structured_builder'] ) && is_array( $meta['structured_builder'] ) ? $meta['structured_builder'] : [];
    $structured_sections = isset( $structured['sections'] ) && is_array( $structured['sections'] ) ? $structured['sections'] : [];
    $top_keys = $decoded !== [] ? array_slice( array_map( 'strval', array_keys( $decoded ) ), 0, 6 ) : [];
    $raw = (string) $raw_json;
    $head = substr( trim( preg_replace( '/\s+/', ' ', $raw ) ?? $raw ), 0, 120 );
    $head = preg_replace( '/[^[:print:]]/', '', (string) $head ) ?? '';
    return [
        'layout_len' => strlen( (string) $raw_json ),
        'decoded' => $decoded !== [],
        'head' => $head,
        'top_keys' => $top_keys,
        'sections_count' => count( $sections ),
        'structured_sections_count' => count( $structured_sections ),
    ];
}

function metis_cms_ajax_layout_has_meaningful_content( $raw_json ): bool {
    $decoded = metis_cms_ajax_decode_json_array( $raw_json );
    if ( $decoded === [] ) {
        return false;
    }

    $structured_meta = StructuredCmsBuilderService::structuredMetaFromDecodedLayout( $decoded );
    $structured_sections = isset( $structured_meta['sections'] ) && is_array( $structured_meta['sections'] )
        ? $structured_meta['sections']
        : [];
    foreach ( $structured_sections as $section ) {
        if ( is_array( $section ) && metis_cms_ajax_payload_has_meaningful_text( $section ) ) {
            return true;
        }
    }

    $modules = metis_cms_ajax_blocks_from_layout_json( $raw_json );
    foreach ( $modules as $module ) {
        if ( ! is_array( $module ) ) {
            continue;
        }
        $data = isset( $module['data'] ) && is_array( $module['data'] ) ? $module['data'] : [];
        foreach ( [ 'content', 'text', 'label', 'title', 'caption', 'html', 'body', 'description' ] as $key ) {
            $candidate = isset( $data[ $key ] ) ? (string) $data[ $key ] : '';
            if ( $candidate !== '' && metis_cms_ajax_html_has_meaningful_content( $candidate ) ) {
                return true;
            }
        }
        if ( metis_cms_ajax_payload_has_meaningful_text( $data ) ) {
            return true;
        }
    }

    if ( metis_cms_ajax_payload_has_meaningful_text( $decoded['sections'] ?? [] ) ) {
        return true;
    }

    return metis_cms_ajax_payload_has_meaningful_text( $decoded );
}

function metis_cms_ajax_layout_saved_at_epoch( $raw_json ): int {
    $decoded = metis_cms_ajax_decode_json_array( $raw_json );
    if ( $decoded === [] ) {
        return 0;
    }
    $meta = isset( $decoded['editor_meta'] ) && is_array( $decoded['editor_meta'] ) ? $decoded['editor_meta'] : [];
    $raw_saved_at = isset( $meta['saved_at'] ) ? trim( (string) $meta['saved_at'] ) : '';
    if ( $raw_saved_at === '' ) {
        return 0;
    }
    $epoch = strtotime( $raw_saved_at );
    if ( ! is_int( $epoch ) || $epoch <= 0 ) {
        return 0;
    }
    return $epoch;
}

/**
 * @return array<int,mixed>
 */
function metis_cms_ajax_blocks_from_template_structure_json( $raw_json ): array {
    $decoded = metis_cms_ajax_decode_json_array( $raw_json );
    $regions = isset( $decoded['regions'] ) && is_array( $decoded['regions'] ) ? $decoded['regions'] : [];
    if ( $regions === [] ) {
        return [];
    }
    $blocks = [];
    foreach ( [ 'header', 'main', 'sidebar', 'footer', 'banners' ] as $region_name ) {
        $region = isset( $regions[ $region_name ] ) && is_array( $regions[ $region_name ] ) ? $regions[ $region_name ] : [];
        $region_blocks = isset( $region['blocks'] ) && is_array( $region['blocks'] ) ? $region['blocks'] : [];
        if ( $region_blocks !== [] ) {
            $blocks = array_merge( $blocks, $region_blocks );
        }
    }
    return $blocks;
}

/**
 * @param array<int,mixed> $blocks
 */
function metis_cms_ajax_assert_blocks_valid( array $blocks, string $context, string $render_mode = '' ): void {
    $result = EditorContextPolicy::validateBlocks( $blocks, $context, $render_mode );
    $errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : [];
    if ( ! (bool) ( $result['valid'] ?? false ) ) {
        $first = $errors !== [] && is_array( $errors[0] ?? null ) ? (string) ( $errors[0]['message'] ?? '' ) : '';
        $message = $first !== '' ? $first : 'Content is invalid for this editor context.';
        metis_runtime_send_json_error(
            [
                'message' => $message,
                'errors' => $errors,
            ],
            422
        );
    }
}

/**
 * @param array<int,mixed> $blocks
 */
function metis_cms_ajax_assert_blocks_valid_for_status(
    array $blocks,
    string $context,
    string $render_mode,
    string $status,
    bool $autosave = false
): void {
    $normalized_status = metis_key_clean( $status );
    $normalized_context = metis_key_clean( $context );
    $strict = in_array( $normalized_status, [ 'published', 'scheduled' ], true )
        && ! $autosave
        && $normalized_context !== 'template';
    $result = EditorContextPolicy::validateBlocks( $blocks, $context, $render_mode );
    if ( (bool) ( $result['valid'] ?? false ) ) {
        return;
    }
    $errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : [];
    if ( ! $strict ) {
        $filtered = [];
        foreach ( $errors as $error ) {
            if ( ! is_array( $error ) ) {
                $filtered[] = $error;
                continue;
            }
            $message = strtolower( (string) ( $error['message'] ?? '' ) );
            if ( str_contains( $message, 'missing required settings' ) ) {
                continue;
            }
            $filtered[] = $error;
        }
        if ( $filtered === [] ) {
            return;
        }
        $errors = $filtered;
    }
    $first = $errors !== [] && is_array( $errors[0] ?? null ) ? (string) ( $errors[0]['message'] ?? '' ) : '';
    $message = $first !== '' ? $first : 'Content is invalid for this editor context.';
    metis_runtime_send_json_error(
        [
            'message' => $message,
            'errors' => $errors,
        ],
        422
    );
}

/**
 * @param array<int,mixed> $blocks
 */
function metis_cms_ajax_assert_accessibility_valid( array $blocks ): void {
    $errors = [];
    $state = [
        'last_heading_level' => 0,
    ];
    metis_cms_ajax_validate_accessibility_blocks( $blocks, 'blocks', $state, $errors );
    if ( $errors === [] ) {
        return;
    }
    $first = is_array( $errors[0] ?? null ) ? (string) ( $errors[0]['message'] ?? '' ) : '';
    $message = $first !== '' ? $first : 'Accessibility validation failed.';
    metis_runtime_send_json_error(
        [
            'message' => $message,
            'errors' => $errors,
        ],
        422
    );
}

/**
 * @param array<int,mixed> $blocks
 * @param array<string,mixed> $state
 * @param array<int,array<string,mixed>> $errors
 */
function metis_cms_ajax_validate_accessibility_blocks( array $blocks, string $path, array &$state, array &$errors ): void {
    foreach ( $blocks as $index => $block ) {
        if ( ! is_array( $block ) ) {
            continue;
        }
        $block_path = $path . '.' . (string) $index;
        $type = metis_key_clean( (string) ( $block['type'] ?? '' ) );
        $data = isset( $block['data'] ) && is_array( $block['data'] ) ? $block['data'] : [];
        $style = isset( $block['style'] ) && is_array( $block['style'] ) ? $block['style'] : [];

        if ( $type === 'image' ) {
            $alt = trim( (string) ( $data['alt'] ?? '' ) );
            if ( $alt === '' ) {
                $errors[] = [
                    'path' => $block_path,
                    'message' => 'Images require alt text.',
                ];
            }
        }

        if ( $type === 'heading' || $type === 'page_title' ) {
            $raw_level = trim( (string) ( $data['level'] ?? ( $data['tag'] ?? 'h2' ) ) );
            if ( preg_match( '/^h([1-6])$/i', $raw_level, $matches ) === 1 ) {
                $level = (int) $matches[1];
                $prev = (int) ( $state['last_heading_level'] ?? 0 );
                if ( $prev > 0 && $level > ( $prev + 1 ) ) {
                    $errors[] = [
                        'path' => $block_path,
                        'message' => 'Heading levels cannot skip hierarchy.',
                    ];
                }
                $state['last_heading_level'] = $level;
            }
        }

        if ( $type === 'button' ) {
            $label = trim( (string) ( $data['label'] ?? '' ) );
            if ( $label === '' ) {
                $errors[] = [
                    'path' => $block_path,
                    'message' => 'Buttons require a visible label.',
                ];
            }
            $bg = isset( $data['bgcolor'] ) ? (string) $data['bgcolor'] : '';
            $fg = isset( $data['color'] ) ? (string) $data['color'] : '';
            if ( $bg !== '' && $fg !== '' ) {
                $ratio = metis_cms_ajax_color_contrast_ratio( $bg, $fg );
                if ( $ratio > 0 && $ratio < 3.0 ) {
                    $errors[] = [
                        'path' => $block_path,
                        'message' => 'Button colors have low contrast. Increase contrast for readability.',
                    ];
                }
            }
        }

        if ( $type === 'button_group' ) {
            $buttons = isset( $data['buttons'] ) && is_array( $data['buttons'] ) ? $data['buttons'] : [];
            foreach ( $buttons as $button_index => $button ) {
                if ( ! is_array( $button ) ) {
                    continue;
                }
                $label = trim( (string) ( $button['label'] ?? '' ) );
                if ( $label === '' ) {
                    $errors[] = [
                        'path' => $block_path . '.buttons.' . (string) $button_index,
                        'message' => 'Each button in a button group requires a label.',
                    ];
                }
            }
        }

        if ( $type === 'cta' ) {
            $label = trim( (string) ( $data['label'] ?? ( $data['button_label'] ?? '' ) ) );
            if ( $label === '' ) {
                $errors[] = [
                    'path' => $block_path,
                    'message' => 'CTA blocks require a call-to-action label.',
                ];
            }
        }

        $block_bg = '';
        $block_fg = '';
        if ( isset( $style['color'] ) && is_array( $style['color'] ) ) {
            $block_bg = (string) ( $style['color']['background'] ?? '' );
            $block_fg = (string) ( $style['color']['text'] ?? '' );
        }
        if ( $block_bg !== '' && $block_fg !== '' ) {
            $ratio = metis_cms_ajax_color_contrast_ratio( $block_bg, $block_fg );
            if ( $ratio > 0 && $ratio < 3.0 ) {
                $errors[] = [
                    'path' => $block_path,
                    'message' => 'Text and background colors have low contrast.',
                ];
            }
        }

        if ( isset( $data['blocks'] ) && is_array( $data['blocks'] ) ) {
            metis_cms_ajax_validate_accessibility_blocks( $data['blocks'], $block_path . '.blocks', $state, $errors );
        }
        if ( isset( $data['col_blocks'] ) && is_array( $data['col_blocks'] ) ) {
            foreach ( $data['col_blocks'] as $col_index => $col_blocks ) {
                if ( is_array( $col_blocks ) ) {
                    metis_cms_ajax_validate_accessibility_blocks( $col_blocks, $block_path . '.col_blocks.' . (string) $col_index, $state, $errors );
                }
            }
        }
    }
}

function metis_cms_ajax_color_contrast_ratio( string $a, string $b ): float {
    $rgb_a = metis_cms_ajax_parse_color_rgb( $a );
    $rgb_b = metis_cms_ajax_parse_color_rgb( $b );
    if ( ! is_array( $rgb_a ) || ! is_array( $rgb_b ) ) {
        return 0.0;
    }
    $lum_a = metis_cms_ajax_relative_luminance( $rgb_a );
    $lum_b = metis_cms_ajax_relative_luminance( $rgb_b );
    $light = max( $lum_a, $lum_b );
    $dark = min( $lum_a, $lum_b );
    return ( $light + 0.05 ) / ( $dark + 0.05 );
}

/**
 * @return array{r:int,g:int,b:int}|null
 */
function metis_cms_ajax_parse_color_rgb( string $raw ): ?array {
    $value = trim( strtolower( $raw ) );
    if ( $value === '' ) {
        return null;
    }
    if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) === 1 ) {
        $hex = substr( $value, 1 );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            'r' => hexdec( substr( $hex, 0, 2 ) ),
            'g' => hexdec( substr( $hex, 2, 2 ) ),
            'b' => hexdec( substr( $hex, 4, 2 ) ),
        ];
    }
    if ( preg_match( '/^rgba?\((\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $value, $m ) === 1 ) {
        return [
            'r' => max( 0, min( 255, (int) $m[1] ) ),
            'g' => max( 0, min( 255, (int) $m[2] ) ),
            'b' => max( 0, min( 255, (int) $m[3] ) ),
        ];
    }
    return null;
}

/**
 * @param array{r:int,g:int,b:int} $rgb
 */
function metis_cms_ajax_relative_luminance( array $rgb ): float {
    $convert = static function ( int $channel ): float {
        $s = max( 0.0, min( 1.0, $channel / 255.0 ) );
        return $s <= 0.03928 ? ( $s / 12.92 ) : pow( ( ( $s + 0.055 ) / 1.055 ), 2.4 );
    };
    $r = $convert( (int) ( $rgb['r'] ?? 0 ) );
    $g = $convert( (int) ( $rgb['g'] ?? 0 ) );
    $b = $convert( (int) ( $rgb['b'] ?? 0 ) );
    return ( 0.2126 * $r ) + ( 0.7152 * $g ) + ( 0.0722 * $b );
}

/**
 * @param array<int,mixed> $blocks
 * @param array<string,mixed> $render_context
 * @param array<string,string> $map
 */
function metis_cms_ajax_collect_rendered_block_map( array $blocks, array $render_context, array &$map ): void {
    foreach ( $blocks as $block ) {
        if ( ! is_array( $block ) ) {
            continue;
        }
        $id = isset( $block['id'] ) && is_scalar( $block['id'] ) ? trim( (string) $block['id'] ) : '';
        if ( $id !== '' ) {
            $map[ $id ] = (string) BlockRenderer::render( $block, $render_context );
        }
        $data = isset( $block['data'] ) && is_array( $block['data'] ) ? $block['data'] : [];
        if ( isset( $data['blocks'] ) && is_array( $data['blocks'] ) ) {
            metis_cms_ajax_collect_rendered_block_map( $data['blocks'], $render_context, $map );
        }
        if ( isset( $data['col_blocks'] ) && is_array( $data['col_blocks'] ) ) {
            foreach ( $data['col_blocks'] as $col_blocks ) {
                if ( is_array( $col_blocks ) ) {
                    metis_cms_ajax_collect_rendered_block_map( $col_blocks, $render_context, $map );
                }
            }
        }
    }
}

function metis_cms_ajax_parse_schedule_at( string $raw ): ?string {
    $value = trim( $raw );
    if ( $value === '' ) {
        return null;
    }
    $timestamp = strtotime( $value );
    if ( $timestamp === false || $timestamp <= 0 ) {
        return null;
    }
    return gmdate( 'Y-m-d H:i:s', $timestamp );
}

function metis_cms_ajax_entity_type_for_context( string $context ): string {
    $raw_context = metis_key_clean( $context );
    if ( in_array( $raw_context, [ 'post', 'cms_post' ], true ) ) {
        return 'post';
    }
    if ( in_array( $raw_context, [ 'cms', 'cms_page', 'website', 'page' ], true ) ) {
        return 'page';
    }
    $normalized = EditorContextPolicy::normalizeContext( $context );
    if ( $normalized === 'post' ) {
        return 'post';
    }
    if ( $normalized === 'template' ) {
        return 'template';
    }
    return 'page';
}

function metis_cms_ajax_clean_entity_key( $raw ): string {
    if ( ! is_scalar( $raw ) ) {
        return '';
    }
    $key = trim( (string) metis_runtime_unslash( $raw ) );
    if ( $key === '' ) {
        return '';
    }
    return preg_replace( '/[^A-Za-z0-9_-]/', '', $key ) ?? '';
}

function metis_cms_ajax_requested_entity_key(): string {
    return metis_cms_ajax_clean_entity_key( $_POST['key'] ?? '' );
}

function metis_cms_ajax_post_string( string $key, bool $unslash = true ): ?string {
    if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
        return null;
    }
    $value = (string) $_POST[ $key ];
    if ( $unslash ) {
        $value = (string) metis_runtime_unslash( $value );
    }
    return $value;
}

function metis_cms_ajax_resolve_entity_id( string $entity_type, int $fallback_id = 0 ): int {
    $key = metis_cms_ajax_requested_entity_key();
    if ( $key !== '' ) {
        if ( $entity_type === 'template' ) {
            $template = TemplateService::getByTemplateKey( $key );
            if ( $template !== null ) {
                return (int) $template->id;
            }
            return max( 0, $fallback_id );
        }
        if ( $entity_type === 'post' ) {
            $post = PostService::getByCode( $key );
            if ( $post !== null ) {
                return (int) $post->id;
            }
            return max( 0, $fallback_id );
        }
        $page = PageService::getByCode( $key );
        if ( $page !== null ) {
            return (int) $page->id;
        }
        return max( 0, $fallback_id );
    }

    return max( 0, $fallback_id );
}

function metis_cms_ajax_generate_lock_token(): string {
    if ( function_exists( 'metis_runtime_generate_uuid' ) ) {
        return (string) metis_runtime_generate_uuid();
    }
    try {
        return strtolower( bin2hex( random_bytes( 16 ) ) );
    } catch ( Throwable $e ) {
        return uniqid( 'editor_lock_', true );
    }
}

function metis_cms_ajax_lock_key( string $entity_type, int $entity_id ): string {
    return 'metis_editor_lock_' . metis_key_clean( $entity_type ) . '_' . max( 0, $entity_id );
}

metis_ajax_register_handler( 'metis_core_editor_config', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    metis_runtime_send_json_success( [
        'editor' => EditorManager::cmsConfig(),
    ] );
}, [
    'module' => 'cms',
    'permission' => 'view',
    'nonce_action' => 'metis_cms',
] );

metis_ajax_register_handler( 'metis_cms_block_registry', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $requested_context = isset( $_POST['context'] ) ? metis_key_clean( (string) $_POST['context'] ) : 'cms';
    $requested_render_mode = isset( $_POST['render_mode'] ) ? metis_key_clean( (string) $_POST['render_mode'] ) : '';
    $context = EditorContextPolicy::normalizeContext( $requested_context );
    $render_mode = EditorContextPolicy::normalizeRenderMode( $requested_render_mode, $context );
    $profile = EditorContextPolicy::profile( $context, $render_mode );

    $definitions = EditorContextPolicy::filterRegistry( BlockRegistry::all(), $context, $render_mode );
    $registry    = [];

    foreach ( $definitions as $type => $definition ) {
        if ( ! is_array( $definition ) ) {
            continue;
        }
        $defaults = [];
        $schema   = isset( $definition['schema'] ) && is_array( $definition['schema'] )
            ? $definition['schema']
            : [];
        foreach ( $schema as $key => $field ) {
            if ( is_array( $field ) && array_key_exists( 'default', $field ) ) {
                $defaults[ (string) $key ] = $field['default'];
            }
        }
        $registry[ (string) $type ] = [
            'label'    => (string) ( $definition['label'] ?? ucfirst( (string) $type ) ),
            'icon'     => (string) ( $definition['icon'] ?? '▢' ),
            'category' => (string) ( $definition['category'] ?? 'content' ),
            'defaults' => $defaults,
        ];
    }

    metis_runtime_send_json_success(
        [
            'registry' => $registry,
            'context' => $context,
            'render_mode' => $render_mode,
            'profile' => $profile,
        ]
    );
}, [
    'module' => 'cms',
    'permission' => 'view',
    'nonce_action' => 'metis_cms',
] );

metis_ajax_register_handler( 'metis_editor_render_preview', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $requested_context = isset( $_POST['context'] ) ? metis_key_clean( (string) $_POST['context'] ) : 'cms';
    $requested_render_mode = isset( $_POST['render_mode'] ) ? metis_key_clean( (string) $_POST['render_mode'] ) : '';
    if ( in_array( $requested_context, [ 'cms', 'cms_page', 'website', 'page' ], true ) ) {
        $context = 'cms';
    } elseif ( in_array( $requested_context, [ 'post', 'cms_post' ], true ) ) {
        $context = 'post';
    } else {
        $context = EditorContextPolicy::normalizeContext( $requested_context );
    }
    $render_mode = EditorContextPolicy::normalizeRenderMode( $requested_render_mode, $context );
    $preview_device = isset( $_POST['preview_device'] ) ? metis_key_clean( (string) $_POST['preview_device'] ) : 'desktop';
    if ( ! in_array( $preview_device, [ 'desktop', 'tablet', 'mobile' ], true ) ) {
        $preview_device = 'desktop';
    }

    if ( ! in_array( $context, [ 'cms', 'post' ], true ) ) {
        metis_runtime_send_json_error(
            [ 'message' => 'Preview context is invalid for the structured cms editor.' ],
            400
        );
    }

    $raw_layout = $_POST['layout'] ?? null;
    if ( is_string( $raw_layout ) ) {
        $decoded_layout = json_decode( metis_runtime_unslash( $raw_layout ), true );
        $raw_layout = is_array( $decoded_layout ) ? $decoded_layout : null;
    }

    if ( ! is_array( $raw_layout ) ) {
        metis_runtime_send_json_error(
            [ 'message' => 'Preview for pages/posts requires a structured layout payload.' ],
            400
        );
    }

    $normalized_layout = metis_cms_ajax_normalize_structured_layout(
        $raw_layout,
        [
            'is_post' => $context === 'post',
            'page_type' => $context === 'post' ? 'post' : '',
        ]
    );
    metis_cms_ajax_assert_layout_valid( $normalized_layout['layout_json'] );
    $page_title = isset( $_POST['page_title'] ) ? metis_text_clean( (string) metis_runtime_unslash( $_POST['page_title'] ) ) : '';
    $featured_image_id = isset( $_POST['featured_image_id'] ) ? (int) $_POST['featured_image_id'] : 0;
    $featured_image_caption = isset( $_POST['featured_image_caption'] ) ? metis_textarea_clean( (string) metis_runtime_unslash( $_POST['featured_image_caption'] ) ) : '';
    $content_format = isset( $_POST['content_format'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['content_format'] ) ) : 'standard';
    if ( ! in_array( $content_format, [ 'standard', 'transcript' ], true ) ) {
        $content_format = 'standard';
    }
    $preview = CmsRenderer::renderStructuredEditorPreview(
        (string) $normalized_layout['layout_json'],
        [
            'context' => $context,
            'preview_device' => $preview_device,
            'page_title' => $page_title,
            'page_type' => (string) ( $normalized_layout['page_type'] ?? ( $context === 'post' ? 'post' : 'page' ) ),
            'content_format' => $content_format,
            'featured_image_id' => $featured_image_id > 0 ? $featured_image_id : 0,
            'featured_image_caption' => $featured_image_id > 0 ? $featured_image_caption : '',
            'template_key' => (string) ( $normalized_layout['template_key'] ?? '' ),
        ]
    );
    metis_runtime_send_json_success(
        [
            'html' => (string) ( $preview['content_html'] ?? '' ),
            'document_html' => (string) ( $preview['document_html'] ?? '' ),
            'blocks_html' => [],
            'context' => $context,
            'render_mode' => $render_mode,
            'preview_device' => $preview_device,
        ]
    );
}, [
    'module' => 'cms',
    'permission' => 'view',
    'nonce_action' => 'metis_cms',
] );

metis_ajax_register_handler( 'metis_cms_editor_lock', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.edit' );

    $context = isset( $_POST['context'] ) ? metis_key_clean( (string) $_POST['context'] ) : 'cms';
    $entity_type = metis_cms_ajax_entity_type_for_context( $context );
    $requested_key = metis_cms_ajax_requested_entity_key();
    $fallback_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $entity_id = metis_cms_ajax_resolve_entity_id( $entity_type, $fallback_id );
    if ( $entity_id < 1 ) {
        if ( $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Editor lock target was not found.', 404 );
        }
        metis_runtime_send_json_error( 'Editor lock requires a saved entity.', 400 );
    }

    $user_id = function_exists( 'metis_current_user_id' ) ? (int) metis_current_user_id() : 0;
    if ( $user_id < 1 ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $intent = isset( $_POST['intent'] ) ? metis_key_clean( (string) $_POST['intent'] ) : 'acquire';
    if ( ! in_array( $intent, [ 'acquire', 'refresh', 'release' ], true ) ) {
        $intent = 'acquire';
    }

    $key = metis_cms_ajax_lock_key( $entity_type, $entity_id );
    $now = time();
    $ttl = 120;
    $existing = metis_get_transient( $key );
    $existing_lock = is_array( $existing ) ? $existing : [];
    $existing_user = isset( $existing_lock['user_id'] ) ? (int) $existing_lock['user_id'] : 0;
    $existing_expires = isset( $existing_lock['expires_at'] ) ? (int) $existing_lock['expires_at'] : 0;

    if ( $intent === 'release' ) {
        if ( $existing_user === $user_id || $existing_user === 0 || $existing_expires <= $now ) {
            metis_delete_transient( $key );
        }
        metis_runtime_send_json_success( [ 'released' => true ] );
    }

    if ( $existing_user > 0 && $existing_user !== $user_id && $existing_expires > $now ) {
        metis_runtime_send_json_error(
            [
                'message' => 'This item is currently being edited by another user.',
                'code' => 'editor_locked',
                'lock' => [
                    'entity_type' => $entity_type,
                    'entity_id' => $entity_id,
                    'user_id' => $existing_user,
                    'expires_at' => $existing_expires,
                ],
            ],
            423
        );
    }

    $token = isset( $existing_lock['token'] ) && is_string( $existing_lock['token'] ) && $existing_user === $user_id
        ? (string) $existing_lock['token']
        : metis_cms_ajax_generate_lock_token();
    $lock = [
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'user_id' => $user_id,
        'token' => $token,
        'expires_at' => $now + $ttl,
    ];
    metis_set_transient( $key, $lock, $ttl );

    metis_runtime_send_json_success( [ 'lock' => $lock ] );
}, [
    'module' => 'cms',
    'permission' => 'edit',
    'nonce_action' => 'metis_cms',
] );

metis_ajax_register_handler( 'metis_cms_editor_revisions_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $context = isset( $_POST['context'] ) ? metis_key_clean( (string) $_POST['context'] ) : 'cms';
    $entity_type = metis_cms_ajax_entity_type_for_context( $context );
    $requested_key = metis_cms_ajax_requested_entity_key();
    $fallback_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $entity_id = metis_cms_ajax_resolve_entity_id( $entity_type, $fallback_id );
    if ( $entity_id < 1 ) {
        if ( $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Entity not found.', 404 );
        }
        metis_runtime_send_json_error( 'Invalid entity ID.', 400 );
    }
    $limit = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 50;
    $items = RevisionTimelineService::list( $entity_type, $entity_id, $limit );
    metis_runtime_send_json_success( [ 'revisions' => $items ] );
}, [
    'module' => 'cms',
    'permission' => 'view',
    'nonce_action' => 'metis_cms',
] );

metis_ajax_register_handler( 'metis_cms_editor_revision_restore', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.edit' );

    $context = isset( $_POST['context'] ) ? metis_key_clean( (string) $_POST['context'] ) : 'cms';
    $entity_type = metis_cms_ajax_entity_type_for_context( $context );
    $requested_key = metis_cms_ajax_requested_entity_key();
    $fallback_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $entity_id = metis_cms_ajax_resolve_entity_id( $entity_type, $fallback_id );
    $revision_id = isset( $_POST['revision_id'] ) ? (int) $_POST['revision_id'] : 0;
    if ( $entity_id < 1 || $revision_id < 1 ) {
        if ( $entity_id < 1 && $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Entity not found.', 404 );
        }
        metis_runtime_send_json_error( 'Invalid restore request.', 400 );
    }

    $payload = RevisionTimelineService::payloadForRevision( $entity_type, $entity_id, $revision_id );
    if ( ! is_array( $payload ) ) {
        metis_runtime_send_json_error( 'Revision not found.', 404 );
    }

    $restored_status = '';
    $restored_entity = null;
    if ( $entity_type === 'template' ) {
        $current_template = TemplateService::getById( $entity_id );
        if ( $current_template === null ) {
            metis_runtime_send_json_error( 'Template not found.', 404 );
        }
        $template_status = isset( $payload['status'] ) ? metis_key_clean( (string) $payload['status'] ) : 'draft';
        if ( ! in_array( $template_status, [ 'draft', 'published' ], true ) ) {
            $template_status = 'draft';
        }
        $restored_structure_json = '';
        if ( isset( $payload['structure_json'] ) && is_string( $payload['structure_json'] ) ) {
            $decoded_structure = metis_cms_ajax_decode_json_array( (string) $payload['structure_json'] );
            if ( $decoded_structure !== [] ) {
                $restored_structure_json = (string) $payload['structure_json'];
            }
        }
        if ( $restored_structure_json === '' ) {
            $restored_structure_json = is_string( $current_template->structure_json ) && trim( $current_template->structure_json ) !== ''
                ? (string) $current_template->structure_json
                : '{}';
        }
        $update_ok = TemplateService::update( $entity_id, [
            'name' => isset( $payload['name'] ) ? metis_text_clean( (string) $payload['name'] ) : (string) $current_template->name,
            'template_key' => (string) $current_template->template_key,
            'template_type' => (string) $current_template->template_type,
            'status' => $template_status,
            'structure_json' => $restored_structure_json,
            'is_default' => array_key_exists( 'is_default', $payload ) ? ( ! empty( $payload['is_default'] ) ? 1 : 0 ) : ( ! empty( $current_template->is_default ) ? 1 : 0 ),
        ] );
        if ( ! $update_ok ) {
            metis_runtime_send_json_error( 'Failed to restore template revision.', 500 );
        }
        $restored_status = $template_status;
        $restored_entity = TemplateService::getById( $entity_id );
    } elseif ( $entity_type === 'post' ) {
        $status = isset( $payload['status'] ) ? metis_key_clean( (string) $payload['status'] ) : 'draft';
        if ( ! in_array( $status, [ 'draft', 'published', 'scheduled' ], true ) ) {
            $status = 'draft';
        }
        $schedule_at = isset( $payload['schedule_at'] ) ? metis_cms_ajax_parse_schedule_at( (string) $payload['schedule_at'] ) : null;
        $update_data = [
            'title' => isset( $payload['title'] ) ? metis_text_clean( (string) $payload['title'] ) : 'Untitled Post',
            'excerpt' => isset( $payload['excerpt'] ) ? metis_textarea_clean( (string) $payload['excerpt'] ) : null,
            'draft_content_json' => isset( $payload['content_json'] ) ? (string) $payload['content_json'] : null,
            'seo_meta_json' => isset( $payload['seo_meta_json'] ) ? (string) $payload['seo_meta_json'] : null,
            'status' => $status,
            'publish_date' => $status === 'scheduled' ? $schedule_at : null,
        ];
        if ( isset( $payload['slug'] ) && (string) $payload['slug'] !== '' ) {
            $update_data['slug'] = metis_slug_clean( (string) $payload['slug'] );
        }
        if ( ! PostService::update( $entity_id, $update_data ) ) {
            metis_runtime_send_json_error( 'Failed to restore post revision.', 500 );
        }
        if ( $status === 'published' && ! PostService::publish( $entity_id ) ) {
            metis_runtime_send_json_error( 'Revision restored but publish failed.', 500 );
        }
        $restored_status = $status;
        $restored_entity = PostService::getById( $entity_id );
    } else {
        $status = isset( $payload['status'] ) ? metis_key_clean( (string) $payload['status'] ) : 'draft';
        if ( ! in_array( $status, [ 'draft', 'published', 'scheduled' ], true ) ) {
            $status = 'draft';
        }
        $schedule_at = isset( $payload['schedule_at'] ) ? metis_cms_ajax_parse_schedule_at( (string) $payload['schedule_at'] ) : null;
        $update_data = [
            'title' => isset( $payload['title'] ) ? metis_text_clean( (string) $payload['title'] ) : 'Untitled Page',
            'draft_layout_json' => isset( $payload['layout_json'] ) ? (string) $payload['layout_json'] : null,
            'seo_meta_json' => isset( $payload['seo_meta_json'] ) ? (string) $payload['seo_meta_json'] : null,
            'status' => $status,
            'published_at' => $status === 'scheduled' ? $schedule_at : null,
        ];
        if ( isset( $payload['slug'] ) && (string) $payload['slug'] !== '' ) {
            $update_data['slug'] = metis_slug_clean( (string) $payload['slug'] );
        }
        if ( ! PageService::update( $entity_id, $update_data ) ) {
            metis_runtime_send_json_error( 'Failed to restore page revision.', 500 );
        }
        if ( $status === 'published' && ! PageService::publish( $entity_id ) ) {
            metis_runtime_send_json_error( 'Revision restored but publish failed.', 500 );
        }
        $restored_status = $status;
        $restored_entity = PageService::getById( $entity_id );
    }

    EditorVersionService::checkpoint(
        $entity_type,
        $entity_id,
        $payload,
        'Restored revision #' . $revision_id
    );

    $response = [
        'message' => 'Revision restored.',
        'status' => $restored_status,
    ];
    if ( $entity_type === 'template' && $restored_entity !== null ) {
        $response['template'] = $restored_entity->toArray();
    } elseif ( $entity_type === 'post' && $restored_entity !== null ) {
        $response['post'] = $restored_entity->toArray();
    } elseif ( $restored_entity !== null ) {
        $response['page'] = $restored_entity->toArray();
    }

    metis_runtime_send_json_success( $response );
}, [
    'module' => 'cms',
    'permission' => 'edit',
    'nonce_action' => 'metis_cms',
] );

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_editor_properties_options', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $context = isset( $_POST['context'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['context'] ) ) : 'page';
    $exclude_id = metis_cms_ajax_resolve_entity_id(
        $context === 'post' ? 'post' : 'page',
        isset( $_POST['id'] ) ? (int) $_POST['id'] : 0
    );

    $parent_pages = [];
    $parent_page_filters = $context === 'post' ? [ 'status' => 'published' ] : [];
    foreach ( PageService::getAll( $parent_page_filters ) as $page ) {
        if ( ! is_object( $page ) ) {
            continue;
        }
        $id = (int) ( $page->id ?? 0 );
        if ( $id < 1 || $id === $exclude_id ) {
            continue;
        }
        $path = method_exists( PageService::class, 'publishedPathForPage' ) ? (string) PageService::publishedPathForPage( $page ) : '';
        $status = (string) ( $page->status ?? '' );
        $label_suffix = $path !== '' ? ' (' . $path . ')' : '';
        if ( $status !== 'published' ) {
            $label_suffix .= ' [' . ucfirst( $status ) . ']';
        }
        $parent_pages[] = [
            'value' => (string) $id,
            'label' => (string) ( ( $page->title ?? ( 'Page #' . $id ) ) . $label_suffix ),
        ];
    }

    $default_page_type = $context === 'post' ? 'post' : 'page';
    $active_template_key = TemplateService::getActiveTemplateSlug();
    $active_template_label = $active_template_key;
    foreach ( TemplateService::discoverTemplates() as $template_candidate ) {
        if ( ! is_array( $template_candidate ) ) {
            continue;
        }
        if ( metis_key_clean( (string) ( $template_candidate['slug'] ?? '' ) ) !== $active_template_key ) {
            continue;
        }
        $active_template_label = (string) ( $template_candidate['name'] ?? $active_template_key );
        break;
    }

    metis_runtime_send_json_success( [
        'parent_pages' => $parent_pages,
        'authors' => metis_cms_ajax_author_options(),
        'categories' => metis_cms_ajax_post_category_options(),
        'forms' => metis_cms_ajax_form_options(),
        'donation_campaigns' => metis_cms_ajax_donation_campaign_options(),
        'calendar_sources' => metis_cms_ajax_calendar_source_options(),
        'media' => metis_cms_ajax_media_options(),
        'templates' => StructuredCmsBuilderService::templateOptions(),
        'default_template_key' => StructuredCmsBuilderService::defaultTemplateForPageType( $default_page_type ),
        'active_template' => [
            'key' => $active_template_key,
            'label' => $active_template_label,
        ],
        'section_types' => StructuredCmsBuilderService::sectionTypes(),
    ] );
} );

metis_ajax_register_handler( 'metis_cms_editor_media_upload', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_media' );

    if ( ! isset( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
        metis_runtime_send_json_error( 'Image file is required.', 400 );
    }
    if ( ! function_exists( 'metis_handle_upload' ) ) {
        metis_runtime_send_json_error( 'Upload service unavailable.', 500 );
    }

    $result = metis_handle_upload( $_FILES['file'], [
        'test_form' => false,
        'mimes' => [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
        ],
    ] );
    if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
        $message = is_array( $result ) ? (string) ( $result['error'] ?? 'Upload failed.' ) : 'Upload failed.';
        metis_runtime_send_json_error( $message, 422 );
    }

    $url = (string) ( $result['url'] ?? '' );
    $token = '';
    if ( preg_match( '#/media/([A-Za-z0-9_-]+)$#', $url, $m ) === 1 ) {
        $token = (string) $m[1];
    }
    metis_runtime_send_json_success( [
        'media' => [
            'id' => isset( $result['id'] ) ? (int) $result['id'] : 0,
            'value' => $token,
            'label' => (string) ( $result['file_name'] ?? basename( (string) ( $result['file'] ?? '' ) ) ),
            'url' => $url,
            'mime' => (string) ( $result['type'] ?? '' ),
        ],
    ] );
} );

metis_ajax_register_handler( 'metis_cms_pages_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $status = isset( $_POST['status'] ) ? metis_key_clean( $_POST['status'] ) : '';
    $filters = $status !== '' ? [ 'status' => $status ] : [];

    $pages = PageService::getAll( $filters );

    $homepage_id = HomepageService::getHomepagePageId();
    $payload     = array_map(
        static function ( $p ) use ( $homepage_id ): array {
            $row                 = $p->toArray();
            $row['is_homepage']  = $homepage_id !== null && (int) ( $row['id'] ?? 0 ) === $homepage_id;
            return $row;
        },
        $pages
    );

    metis_runtime_send_json_success( [ 'pages' => $payload ] );
} );

metis_ajax_register_handler( 'metis_cms_page_get', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $requested_key = metis_cms_ajax_requested_entity_key();
    $id = metis_cms_ajax_resolve_entity_id( 'page', isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
    if ( $id < 1 ) {
        if ( $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Page not found.', 404 );
        }
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $page = PageService::getById( $id );
    if ( $page === null ) {
        metis_runtime_send_json_error( 'Page not found.', 404 );
    }

    $payload                = $page->toArray();
    $payload['is_homepage'] = HomepageService::getHomepagePageId() === $page->id;
    $author_source_id = (int) ( $payload['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $payload['updated_by'] ?? 0 );
    }
    $payload['author_id'] = $author_source_id;
    $payload['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $payload['last_edit'] = (string) ( $payload['updated_at'] ?? '' );
    $payload['published_date'] = (string) ( $payload['published_at'] ?? '' );
    $payload['section_count'] = metis_cms_ajax_page_section_count( $payload );

    metis_runtime_send_json_success( [ 'page' => $payload ] );
} );

metis_ajax_register_handler( 'metis_cms_page_create', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.create' );

    $title = isset( $_POST['title'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['title'] ) ) : '';
    if ( $title === '' ) {
        metis_runtime_send_json_error( 'Title is required.', 400 );
    }

    $requested_status = isset( $_POST['status'] ) ? metis_key_clean( (string) $_POST['status'] ) : 'draft';
    if ( ! in_array( $requested_status, [ 'draft', 'published', 'scheduled' ], true ) ) {
        $requested_status = 'draft';
    }
    $published_date_input = isset( $_POST['published_date'] ) ? (string) metis_runtime_unslash( $_POST['published_date'] ) : '';
    $schedule_at = $published_date_input !== ''
        ? metis_cms_ajax_parse_schedule_at( $published_date_input )
        : ( isset( $_POST['schedule_at'] ) ? metis_cms_ajax_parse_schedule_at( (string) metis_runtime_unslash( $_POST['schedule_at'] ) ) : null );
    $autosave = ! empty( $_POST['autosave'] );
    $set_homepage = ! empty( $_POST['set_as_homepage'] );
    $parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
    $section_count = isset( $_POST['section_count'] ) ? max( 1, min( 12, (int) $_POST['section_count'] ) ) : 0;
    $raw_slug = isset( $_POST['slug'] ) ? (string) metis_runtime_unslash( $_POST['slug'] ) : '';
    $normalized_slug = metis_slug_clean( $raw_slug );
    if ( trim( $raw_slug ) !== '' && $normalized_slug === '' ) {
        metis_runtime_send_json_error( 'Slug Title must include letters or numbers.', 400 );
    }
    if ( $normalized_slug === '' ) {
        $normalized_slug = metis_slug_clean( $title );
    }
    if ( $normalized_slug === '' ) {
        metis_runtime_send_json_error( 'Slug Title must include letters or numbers.', 400 );
    }

    $raw_layout_json = metis_cms_ajax_post_string( 'layout_json', false );
    $normalized_layout = metis_cms_ajax_normalize_structured_layout(
        $raw_layout_json,
        [
            'is_post' => false,
            'set_as_homepage' => $set_homepage,
            'template_key' => TemplateService::getActiveTemplateSlug(),
            'page_type' => isset( $_POST['page_type'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['page_type'] ) ) : '',
        ]
    );

    $data = [
        'title'             => $title,
        'slug'              => $normalized_slug,
        'status'            => $requested_status,
        'draft_layout_json' => $normalized_layout['layout_json'],
        'page_type'         => $normalized_layout['page_type'],
        'template_key'      => TemplateService::getActiveTemplateSlug(),
        'parent_id'         => $parent_id > 0 ? $parent_id : null,
    ];
    if ( $section_count < 1 ) {
        $section_count = count( $normalized_layout['sections'] );
    }
    if ( $section_count > 0 ) {
        $data['seo_meta_json'] = metis_cms_ajax_merge_editor_meta_into_seo_meta(
            $data['seo_meta_json'] ?? null,
            [ 'section_count' => $section_count ]
        );
    }
    if ( $requested_status === 'scheduled' ) {
        $data['published_at'] = $schedule_at;
    }
    metis_cms_ajax_assert_layout_valid( $data['draft_layout_json'] );
    $blocks = metis_cms_ajax_blocks_from_layout_json( $data['draft_layout_json'] );
    metis_cms_ajax_assert_blocks_valid_for_status( $blocks, 'cms', 'standard', $requested_status, $autosave );
    if ( in_array( $requested_status, [ 'published', 'scheduled' ], true ) && ! $autosave ) {
        metis_cms_ajax_assert_accessibility_valid( $blocks );
    }

    $page = PageService::create( $data );
    if ( $page === null ) {
        metis_runtime_send_json_error( 'Failed to create page.', 500 );
    }

    if ( $requested_status === 'published' && ! $autosave && ! PageService::publish( (int) $page->id ) ) {
        metis_runtime_send_json_error( 'Page was created but could not be published.', 500 );
    }

    $autosave = ! empty( $_POST['autosave'] );
    $revision_note = isset( $_POST['revision_note'] )
        ? metis_text_clean( (string) metis_runtime_unslash( $_POST['revision_note'] ) )
        : ( $autosave ? 'Autosave' : ( $requested_status === 'published' ? 'Published' : ( $requested_status === 'scheduled' ? 'Scheduled' : 'Saved draft' ) ) );
    EditorVersionService::checkpoint(
        'page',
        (int) $page->id,
        [
            'title' => $title,
            'slug' => (string) ( $data['slug'] ?? '' ),
            'status' => $requested_status,
            'layout_json' => (string) ( $data['draft_layout_json'] ?? '' ),
            'seo_meta_json' => (string) ( metis_cms_ajax_post_string( 'seo_meta_json', false ) ?? '' ),
            'schedule_at' => (string) ( $schedule_at ?? '' ),
            'autosave' => $autosave,
        ],
        $revision_note
    );

    if ( $set_homepage && $requested_status === 'published' ) {
        HomepageService::setHomepagePageId( (int) $page->id );
    }

    $page = PageService::getById( (int) $page->id ) ?? $page;
    $row  = $page->toArray();
    $row['is_homepage'] = HomepageService::getHomepagePageId() === $page->id;
    $author_source_id = (int) ( $row['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['updated_by'] ?? 0 );
    }
    $row['author_id'] = $author_source_id;
    $row['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $row['last_edit'] = (string) ( $row['updated_at'] ?? '' );
    $row['published_date'] = (string) ( $row['published_at'] ?? '' );
    $row['section_count'] = metis_cms_ajax_page_section_count( $row );

    metis_runtime_send_json_success( [
        'message' => 'Page created.',
        'page'    => $row,
    ] );
} );

metis_ajax_register_handler( 'metis_cms_page_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.edit' );

    $requested_key = metis_cms_ajax_requested_entity_key();
    $id = metis_cms_ajax_resolve_entity_id( 'page', isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
    if ( $id < 1 ) {
        if ( $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Page not found.', 404 );
        }
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $title = isset( $_POST['title'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['title'] ) ) : '';
    if ( $title === '' ) {
        metis_runtime_send_json_error( 'Title is required.', 400 );
    }

    $requested_status = isset( $_POST['status'] ) ? metis_key_clean( (string) $_POST['status'] ) : 'draft';
    if ( ! in_array( $requested_status, [ 'draft', 'published', 'scheduled' ], true ) ) {
        $requested_status = 'draft';
    }
    $published_date_input = isset( $_POST['published_date'] ) ? (string) metis_runtime_unslash( $_POST['published_date'] ) : '';
    $schedule_at = $published_date_input !== ''
        ? metis_cms_ajax_parse_schedule_at( $published_date_input )
        : ( isset( $_POST['schedule_at'] ) ? metis_cms_ajax_parse_schedule_at( (string) metis_runtime_unslash( $_POST['schedule_at'] ) ) : null );
    $autosave = ! empty( $_POST['autosave'] );
    $set_homepage = ! empty( $_POST['set_as_homepage'] );
    $parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;
    $section_count = isset( $_POST['section_count'] ) ? max( 1, min( 12, (int) $_POST['section_count'] ) ) : 0;
    $raw_slug = isset( $_POST['slug'] ) ? (string) metis_runtime_unslash( $_POST['slug'] ) : '';
    $normalized_slug = metis_slug_clean( $raw_slug );
    if ( trim( $raw_slug ) !== '' && $normalized_slug === '' ) {
        metis_runtime_send_json_error( 'Slug Title must include letters or numbers.', 400 );
    }

    $raw_layout_json = metis_cms_ajax_post_string( 'layout_json', false );
    $normalized_layout = metis_cms_ajax_normalize_structured_layout(
        $raw_layout_json,
        [
            'is_post' => false,
            'set_as_homepage' => $set_homepage,
            'template_key' => TemplateService::getActiveTemplateSlug(),
            'page_type' => isset( $_POST['page_type'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['page_type'] ) ) : '',
        ]
    );

    $data = [
        'title'             => $title,
        'draft_layout_json' => $normalized_layout['layout_json'],
        'seo_meta_json'     => metis_cms_ajax_post_string( 'seo_meta_json', false ),
        'status'            => $requested_status,
        'page_type'         => $normalized_layout['page_type'],
        'template_key'      => TemplateService::getActiveTemplateSlug(),
        'parent_id'         => $parent_id > 0 ? $parent_id : null,
    ];
    if ( $section_count < 1 ) {
        $section_count = count( $normalized_layout['sections'] );
    }
    if ( $section_count > 0 ) {
        $data['seo_meta_json'] = metis_cms_ajax_merge_editor_meta_into_seo_meta(
            $data['seo_meta_json'] ?? null,
            [ 'section_count' => $section_count ]
        );
    }
    if ( $requested_status === 'scheduled' ) {
        $data['published_at'] = $schedule_at;
    }
    metis_cms_ajax_assert_layout_valid( $data['draft_layout_json'] );
    $blocks = metis_cms_ajax_blocks_from_layout_json( $data['draft_layout_json'] );
    metis_cms_ajax_assert_blocks_valid_for_status( $blocks, 'cms', 'standard', $requested_status, $autosave );
    if ( in_array( $requested_status, [ 'published', 'scheduled' ], true ) && ! $autosave ) {
        metis_cms_ajax_assert_accessibility_valid( $blocks );
    }

    if ( trim( $raw_slug ) !== '' ) {
        $data['slug'] = $normalized_slug;
    }

    $existing_page = PageService::getById( $id );
    $blocked_empty_layout_overwrite = false;
    $blocked_stale_layout_overwrite = false;
    if ( $existing_page !== null && array_key_exists( 'draft_layout_json', $data ) ) {
        $incoming_has_content = metis_cms_ajax_layout_has_meaningful_content( $data['draft_layout_json'] );
        $existing_has_content = metis_cms_ajax_layout_has_meaningful_content( $existing_page->draft_layout_json ?? $existing_page->layout_json ?? '' );
        if ( ! $incoming_has_content && $existing_has_content ) {
            $blocked_empty_layout_overwrite = true;
            if ( class_exists( 'Metis_Logger' ) ) {
                \Metis_Logger::warn( 'Blocked destructive empty page layout overwrite', [
                    'page_id' => $id,
                    'page_code' => (string) ( $existing_page->page_code ?? '' ),
                    'autosave' => $autosave,
                    'incoming_layout_len' => strlen( (string) $data['draft_layout_json'] ),
                    'incoming_summary' => metis_cms_ajax_layout_content_summary( $data['draft_layout_json'] ),
                    'existing_summary' => metis_cms_ajax_layout_content_summary( $existing_page->draft_layout_json ?? $existing_page->layout_json ?? '' ),
                ] );
            }
            unset( $data['draft_layout_json'] );
        } else {
            $incoming_saved_at = metis_cms_ajax_layout_saved_at_epoch( $data['draft_layout_json'] );
            $existing_saved_at = metis_cms_ajax_layout_saved_at_epoch( $existing_page->draft_layout_json ?? $existing_page->layout_json ?? '' );
            if ( $autosave && $incoming_saved_at > 0 && $existing_saved_at > 0 && $incoming_saved_at <= $existing_saved_at ) {
                $blocked_stale_layout_overwrite = true;
                if ( class_exists( 'Metis_Logger' ) ) {
                    \Metis_Logger::warn( 'Blocked stale page autosave overwrite', [
                        'page_id' => $id,
                        'page_code' => (string) ( $existing_page->page_code ?? '' ),
                        'incoming_saved_at' => $incoming_saved_at,
                        'existing_saved_at' => $existing_saved_at,
                    ] );
                }
                unset( $data['draft_layout_json'] );
            }
        }
    }

    $ok = PageService::update( $id, $data );
    if ( $ok === false ) {
        metis_runtime_send_json_error( 'Failed to save page.', 500 );
    }
    $suppressed_layout_write = $blocked_empty_layout_overwrite || $blocked_stale_layout_overwrite;
    if ( $autosave ) {
        $autosave_layout_json = (string) ( $data['draft_layout_json'] ?? '' );
        if ( $suppressed_layout_write && $existing_page !== null ) {
            $autosave_layout_json = (string) ( $existing_page->draft_layout_json ?? $existing_page->layout_json ?? '' );
        }
        EditorAutosaveService::saveDraft( 'page', $id, [
            'title' => $title,
            'slug' => (string) ( $data['slug'] ?? '' ),
            'layout_json' => $autosave_layout_json,
        ] );
    }

    if ( $requested_status === 'published' && ! $autosave && ! PageService::publish( $id ) ) {
        metis_runtime_send_json_error( 'Page was saved but could not be published.', 500 );
    }

    $autosave = ! empty( $_POST['autosave'] );
    $revision_note = isset( $_POST['revision_note'] )
        ? metis_text_clean( (string) metis_runtime_unslash( $_POST['revision_note'] ) )
        : ( $autosave ? 'Autosave' : ( $requested_status === 'published' ? 'Published' : ( $requested_status === 'scheduled' ? 'Scheduled' : 'Saved draft' ) ) );
    $checkpoint_layout_json = (string) ( $data['draft_layout_json'] ?? '' );
    if ( $suppressed_layout_write && $existing_page !== null ) {
        $checkpoint_layout_json = (string) ( $existing_page->draft_layout_json ?? $existing_page->layout_json ?? '' );
    }
    EditorVersionService::checkpoint(
        'page',
        $id,
        [
            'title' => $title,
            'slug' => isset( $data['slug'] ) ? (string) $data['slug'] : '',
            'status' => $requested_status,
            'layout_json' => $checkpoint_layout_json,
            'seo_meta_json' => (string) ( $data['seo_meta_json'] ?? '' ),
            'schedule_at' => (string) ( $schedule_at ?? '' ),
            'autosave' => $autosave,
        ],
        $revision_note
    );

    if ( $set_homepage && $requested_status === 'published' ) {
        HomepageService::setHomepagePageId( $id );
    }

    $page = PageService::getById( $id );
    if ( $page === null ) {
        metis_runtime_send_json_error( 'Page saved but reload failed.', 500 );
    }
    $saved_title = (string) ( $page->title ?? '' );
    if ( $saved_title !== $title ) {
        metis_runtime_send_json_error( 'Page save verification failed (title mismatch).', 500 );
    }
    if ( isset( $data['draft_layout_json'] ) ) {
        $expected_layout_hash = md5( (string) $data['draft_layout_json'] );
        $saved_layout_hash = md5( (string) ( $page->draft_layout_json ?? '' ) );
        if ( $expected_layout_hash !== $saved_layout_hash ) {
            metis_runtime_send_json_error( 'Page save verification failed (content mismatch).', 500 );
        }
    }

    $row = $page->toArray();
    $row['is_homepage'] = HomepageService::getHomepagePageId() === $page->id;
    $author_source_id = (int) ( $row['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['updated_by'] ?? 0 );
    }
    $row['author_id'] = $author_source_id;
    $row['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $row['last_edit'] = (string) ( $row['updated_at'] ?? '' );
    $row['published_date'] = (string) ( $row['published_at'] ?? '' );
    $row['section_count'] = metis_cms_ajax_page_section_count( $row );

    metis_runtime_send_json_success( [
        'message' => 'Page saved.',
        'page'    => $row,
    ] );
} );

metis_ajax_register_handler( 'metis_cms_homepage_set', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.publish' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $page = PageService::getById( $id );
    if ( $page === null ) {
        metis_runtime_send_json_error( 'Page not found.', 404 );
    }
    if ( $page->status !== 'published' ) {
        metis_runtime_send_json_error( 'Only published pages can be set as homepage.', 422 );
    }

    if ( ! HomepageService::setHomepagePageId( $id ) ) {
        metis_runtime_send_json_error( 'Failed to set homepage.', 500 );
    }

    $page = PageService::getById( $id );
    if ( $page === null ) {
        metis_runtime_send_json_error( 'Homepage updated but page reload failed.', 500 );
    }

    $row = $page->toArray();
    $row['is_homepage'] = true;
    $author_source_id = (int) ( $row['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['updated_by'] ?? 0 );
    }
    $row['author_id'] = $author_source_id;
    $row['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $row['last_edit'] = (string) ( $row['updated_at'] ?? '' );
    $row['published_date'] = (string) ( $row['published_at'] ?? '' );
    $row['section_count'] = metis_cms_ajax_page_section_count( $row );

    metis_runtime_send_json_success( [
        'message' => 'Homepage updated.',
        'page' => $row,
        'homepage_page_id' => $id,
    ] );
} );

metis_ajax_register_handler( 'metis_cms_page_publish', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.publish' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $ok = PageService::publish( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to publish page.', 500 );
    }

    $page = PageService::getById( $id );
    if ( $page === null ) {
        metis_runtime_send_json_error( 'Page published but reload failed.', 500 );
    }

    $row = $page->toArray();
    $row['is_homepage'] = HomepageService::getHomepagePageId() === $page->id;
    $author_source_id = (int) ( $row['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['updated_by'] ?? 0 );
    }
    $row['author_id'] = $author_source_id;
    $row['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $row['last_edit'] = (string) ( $row['updated_at'] ?? '' );
    $row['published_date'] = (string) ( $row['published_at'] ?? '' );
    $row['section_count'] = metis_cms_ajax_page_section_count( $row );

    metis_runtime_send_json_success( [ 'message' => 'Page published.', 'page' => $row ] );
} );

metis_ajax_register_handler( 'metis_cms_page_unpublish', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.publish' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $ok = PageService::unpublish( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to unpublish page.', 500 );
    }

    $page = PageService::getById( $id );
    if ( $page === null ) {
        metis_runtime_send_json_error( 'Page unpublished but reload failed.', 500 );
    }

    $row = $page->toArray();
    $row['is_homepage'] = false;
    $author_source_id = (int) ( $row['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['updated_by'] ?? 0 );
    }
    $row['author_id'] = $author_source_id;
    $row['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $row['last_edit'] = (string) ( $row['updated_at'] ?? '' );
    $row['published_date'] = (string) ( $row['published_at'] ?? '' );
    $row['section_count'] = metis_cms_ajax_page_section_count( $row );

    metis_runtime_send_json_success( [ 'message' => 'Page unpublished.', 'page' => $row ] );
} );

metis_ajax_register_handler( 'metis_cms_page_delete', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.delete' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $ok = PageService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete page.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Page deleted.', 'id' => $id, 'homepage_page_id' => HomepageService::getHomepagePageId() ] );
} );

// ---------------------------------------------------------------------------
// Posts
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_posts_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $status = isset( $_POST['status'] ) ? metis_key_clean( $_POST['status'] ) : '';
    $filters = $status !== '' ? [ 'status' => $status ] : [];

    $posts = PostService::getAll( $filters );
    $payload = [];
    foreach ( $posts as $post ) {
        if ( ! is_object( $post ) ) {
            continue;
        }
        $row = $post->toArray();
        $row['parent_id'] = isset( $row['parent_page_id'] ) ? (int) $row['parent_page_id'] : 0;
        $row['public_path'] = method_exists( PostService::class, 'publicPath' ) ? (string) PostService::publicPath( $post ) : '';
        $row = metis_cms_ajax_post_category_payload( $row );
        $payload[] = $row;
    }

    metis_runtime_send_json_success( [ 'posts' => $payload ] );
} );

metis_ajax_register_handler( 'metis_cms_post_get', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $requested_key = metis_cms_ajax_requested_entity_key();
    $id = metis_cms_ajax_resolve_entity_id( 'post', isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
    if ( $id < 1 ) {
        if ( $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Post not found.', 404 );
        }
        metis_runtime_send_json_error( 'Invalid post ID.', 400 );
    }

    $post = PostService::getById( $id );
    if ( $post === null ) {
        metis_runtime_send_json_error( 'Post not found.', 404 );
    }

    $payload = $post->toArray();
    $author_source_id = (int) ( $payload['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $payload['author_id'] ?? 0 );
    }
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $payload['updated_by'] ?? 0 );
    }
    $payload['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $payload['last_edit'] = (string) ( $payload['updated_at'] ?? '' );
    $payload['published_date'] = (string) ( $payload['publish_date'] ?? '' );
    $payload['parent_id'] = isset( $payload['parent_page_id'] ) ? (int) $payload['parent_page_id'] : 0;
    $payload['public_path'] = method_exists( PostService::class, 'publicPath' ) ? (string) PostService::publicPath( $post ) : '';
    $payload['content_format'] = in_array( (string) ( $payload['content_format'] ?? 'standard' ), [ 'standard', 'transcript' ], true )
        ? (string) $payload['content_format']
        : 'standard';
    $payload = metis_cms_ajax_post_category_payload( $payload );

    metis_runtime_send_json_success( [ 'post' => $payload ] );
} );

metis_ajax_register_handler( 'metis_cms_post_create', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.create' );

    $title = isset( $_POST['title'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['title'] ) ) : '';
    if ( $title === '' ) {
        metis_runtime_send_json_error( 'Title is required.', 400 );
    }

    $requested_status = isset( $_POST['status'] ) ? metis_key_clean( (string) $_POST['status'] ) : 'draft';
    if ( ! in_array( $requested_status, [ 'draft', 'published', 'scheduled' ], true ) ) {
        $requested_status = 'draft';
    }
    $published_date_input = isset( $_POST['published_date'] ) ? (string) metis_runtime_unslash( $_POST['published_date'] ) : '';
    $schedule_at = $published_date_input !== ''
        ? metis_cms_ajax_parse_schedule_at( $published_date_input )
        : ( isset( $_POST['schedule_at'] ) ? metis_cms_ajax_parse_schedule_at( (string) metis_runtime_unslash( $_POST['schedule_at'] ) ) : null );
    $autosave = ! empty( $_POST['autosave'] );
    $post_category_ids = metis_cms_ajax_post_category_ids_with_default( metis_cms_ajax_post_category_ids_input() );
    $post_category_id = $post_category_ids !== []
        ? (int) $post_category_ids[0]
        : ( isset( $_POST['post_category_id'] ) ? (int) $_POST['post_category_id'] : ( isset( $_POST['category_id'] ) ? (int) $_POST['category_id'] : 0 ) );
    $parent_page_id = isset( $_POST['parent_page_id'] ) ? (int) $_POST['parent_page_id'] : ( isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0 );
    $featured_image_id = isset( $_POST['featured_image_id'] ) ? (int) $_POST['featured_image_id'] : 0;
    $featured_image_caption = isset( $_POST['featured_image_caption'] ) ? metis_textarea_clean( (string) metis_runtime_unslash( $_POST['featured_image_caption'] ) ) : '';
    $content_format = isset( $_POST['content_format'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['content_format'] ) ) : 'standard';
    $parent_page_id = metis_cms_ajax_normalize_post_parent_page_id( $parent_page_id, false );
    if ( ! in_array( $content_format, [ 'standard', 'transcript' ], true ) ) {
        $content_format = 'standard';
    }
    if ( $featured_image_id < 1 ) {
        $featured_image_id = 0;
        $featured_image_caption = '';
    }
    if ( $post_category_id > 0 && PostCategoryService::getById( $post_category_id ) === null ) {
        $post_category_id = 0;
    }
    if ( $post_category_ids === [] && $post_category_id > 0 ) {
        $post_category_ids = [ $post_category_id ];
    }
    $raw_slug = isset( $_POST['slug'] ) ? (string) metis_runtime_unslash( $_POST['slug'] ) : '';
    $normalized_slug = metis_slug_clean( $raw_slug );
    if ( trim( $raw_slug ) !== '' && $normalized_slug === '' ) {
        metis_runtime_send_json_error( 'Slug Title must include letters or numbers.', 400 );
    }
    if ( $normalized_slug === '' ) {
        $normalized_slug = metis_slug_clean( $title );
    }
    if ( $normalized_slug === '' ) {
        metis_runtime_send_json_error( 'Slug Title must include letters or numbers.', 400 );
    }
    $raw_content_json = metis_cms_ajax_post_string( 'content_json', false );
    $normalized_layout = metis_cms_ajax_normalize_structured_layout(
        $raw_content_json,
        [
            'is_post' => true,
            'template_key' => TemplateService::getActiveTemplateSlug(),
            'page_type' => 'post',
        ]
    );
    $draft_content_json = $normalized_layout['layout_json'];
    metis_cms_ajax_assert_layout_valid( $draft_content_json );
    $blocks = metis_cms_ajax_blocks_from_layout_json( $draft_content_json );
    metis_cms_ajax_assert_blocks_valid_for_status( $blocks, 'post', 'standard', $requested_status, $autosave );
    if ( in_array( $requested_status, [ 'published', 'scheduled' ], true ) && ! $autosave ) {
        metis_cms_ajax_assert_accessibility_valid( $blocks );
    }

    $post = PostService::create( [
        'title'              => $title,
        'slug'               => $normalized_slug,
        'excerpt'            => isset( $_POST['excerpt'] ) ? metis_textarea_clean( metis_runtime_unslash( $_POST['excerpt'] ) ) : null,
        'draft_content_json' => $draft_content_json,
        'status'             => $requested_status,
        'publish_date'       => $requested_status === 'scheduled'
            ? $schedule_at
            : ( $requested_status === 'published' && $schedule_at !== null ? $schedule_at : null ),
        'page_type'          => $normalized_layout['page_type'],
        'content_format'     => $content_format,
        'template_key'       => TemplateService::getActiveTemplateSlug(),
        'post_category_id'   => $post_category_id > 0 ? $post_category_id : null,
        'post_category_ids'  => $post_category_ids,
        'parent_page_id'     => $parent_page_id > 0 ? $parent_page_id : null,
        'featured_image_id'  => $featured_image_id > 0 ? $featured_image_id : null,
        'featured_image_caption' => $featured_image_caption !== '' ? $featured_image_caption : null,
        'seo_meta_json'      => null,
    ] );

    if ( $post === null ) {
        metis_runtime_send_json_error( 'Failed to create post.', 500 );
    }

    if ( $requested_status === 'published' && ! $autosave && ! PostService::publish( (int) $post->id ) ) {
        metis_runtime_send_json_error( 'Post was created but could not be published.', 500 );
    }

    $autosave = ! empty( $_POST['autosave'] );
    $revision_note = isset( $_POST['revision_note'] )
        ? metis_text_clean( (string) metis_runtime_unslash( $_POST['revision_note'] ) )
        : ( $autosave ? 'Autosave' : ( $requested_status === 'published' ? 'Published' : ( $requested_status === 'scheduled' ? 'Scheduled' : 'Saved draft' ) ) );
    EditorVersionService::checkpoint(
        'post',
        (int) $post->id,
        [
            'title' => $title,
            'slug' => (string) ( $post->slug ?? '' ),
            'status' => $requested_status,
            'content_json' => (string) $draft_content_json,
            'excerpt' => isset( $_POST['excerpt'] ) ? metis_textarea_clean( (string) metis_runtime_unslash( $_POST['excerpt'] ) ) : '',
            'content_format' => $content_format,
            'seo_meta_json' => (string) ( metis_cms_ajax_post_string( 'seo_meta_json', false ) ?? '' ),
            'schedule_at' => (string) ( $schedule_at ?? '' ),
            'autosave' => $autosave,
        ],
        $revision_note
    );

    $post = PostService::getById( (int) $post->id ) ?? $post;
    $row = $post->toArray();
    $author_source_id = (int) ( $row['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['author_id'] ?? 0 );
    }
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['updated_by'] ?? 0 );
    }
    $row['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $row['last_edit'] = (string) ( $row['updated_at'] ?? '' );
    $row['published_date'] = (string) ( $row['publish_date'] ?? '' );
    $row['parent_id'] = isset( $row['parent_page_id'] ) ? (int) $row['parent_page_id'] : 0;
    $row['public_path'] = method_exists( PostService::class, 'publicPath' ) ? (string) PostService::publicPath( $post ) : '';
    $row = metis_cms_ajax_post_category_payload( $row );

    metis_runtime_send_json_success( [
        'message' => 'Post created.',
        'post'    => $row,
    ] );
} );

metis_ajax_register_handler( 'metis_cms_post_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.edit' );

    $requested_key = metis_cms_ajax_requested_entity_key();
    $id = metis_cms_ajax_resolve_entity_id( 'post', isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
    if ( $id < 1 ) {
        if ( $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Post not found.', 404 );
        }
        metis_runtime_send_json_error( 'Invalid post ID.', 400 );
    }

    $title = isset( $_POST['title'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['title'] ) ) : '';
    if ( $title === '' ) {
        metis_runtime_send_json_error( 'Title is required.', 400 );
    }

    $requested_status = isset( $_POST['status'] ) ? metis_key_clean( (string) $_POST['status'] ) : 'draft';
    if ( ! in_array( $requested_status, [ 'draft', 'published', 'scheduled' ], true ) ) {
        $requested_status = 'draft';
    }
    $published_date_input = isset( $_POST['published_date'] ) ? (string) metis_runtime_unslash( $_POST['published_date'] ) : '';
    $schedule_at = $published_date_input !== ''
        ? metis_cms_ajax_parse_schedule_at( $published_date_input )
        : ( isset( $_POST['schedule_at'] ) ? metis_cms_ajax_parse_schedule_at( (string) metis_runtime_unslash( $_POST['schedule_at'] ) ) : null );
    $autosave = ! empty( $_POST['autosave'] );
    $post_category_ids = metis_cms_ajax_post_category_ids_with_default( metis_cms_ajax_post_category_ids_input() );
    $post_category_id = $post_category_ids !== []
        ? (int) $post_category_ids[0]
        : ( isset( $_POST['post_category_id'] ) ? (int) $_POST['post_category_id'] : ( isset( $_POST['category_id'] ) ? (int) $_POST['category_id'] : 0 ) );
    $parent_page_id = isset( $_POST['parent_page_id'] ) ? (int) $_POST['parent_page_id'] : ( isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0 );
    $featured_image_id = isset( $_POST['featured_image_id'] ) ? (int) $_POST['featured_image_id'] : 0;
    $featured_image_caption = isset( $_POST['featured_image_caption'] ) ? metis_textarea_clean( (string) metis_runtime_unslash( $_POST['featured_image_caption'] ) ) : '';
    $content_format = isset( $_POST['content_format'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['content_format'] ) ) : 'standard';
    $parent_page_id = metis_cms_ajax_normalize_post_parent_page_id( $parent_page_id, false );
    if ( ! in_array( $content_format, [ 'standard', 'transcript' ], true ) ) {
        $content_format = 'standard';
    }
    if ( $featured_image_id < 1 ) {
        $featured_image_id = 0;
        $featured_image_caption = '';
    }
    if ( $post_category_id > 0 && PostCategoryService::getById( $post_category_id ) === null ) {
        $post_category_id = 0;
    }
    if ( $post_category_ids === [] && $post_category_id > 0 ) {
        $post_category_ids = [ $post_category_id ];
    }
    $raw_slug = isset( $_POST['slug'] ) ? (string) metis_runtime_unslash( $_POST['slug'] ) : '';
    $normalized_slug = metis_slug_clean( $raw_slug );
    if ( trim( $raw_slug ) !== '' && $normalized_slug === '' ) {
        metis_runtime_send_json_error( 'Slug Title must include letters or numbers.', 400 );
    }
    $raw_content_json = metis_cms_ajax_post_string( 'content_json', false );
    $normalized_layout = metis_cms_ajax_normalize_structured_layout(
        $raw_content_json,
        [
            'is_post' => true,
            'template_key' => TemplateService::getActiveTemplateSlug(),
            'page_type' => 'post',
        ]
    );
    $existing_post = PostService::getById( $id );

    $data = [
        'title'              => $title,
        'excerpt'            => isset( $_POST['excerpt'] ) ? metis_textarea_clean( metis_runtime_unslash( $_POST['excerpt'] ) ) : null,
        'draft_content_json' => $normalized_layout['layout_json'],
        'seo_meta_json'      => metis_cms_ajax_post_string( 'seo_meta_json', false ),
        'status'             => $requested_status,
        'page_type'          => $normalized_layout['page_type'],
        'content_format'     => $content_format,
        'template_key'       => TemplateService::getActiveTemplateSlug(),
        'post_category_id'   => $post_category_id > 0 ? $post_category_id : null,
        'post_category_ids'  => $post_category_ids,
        'parent_page_id'     => $parent_page_id > 0 ? $parent_page_id : null,
        'featured_image_id'  => $featured_image_id > 0 ? $featured_image_id : null,
        'featured_image_caption' => $featured_image_caption !== '' ? $featured_image_caption : null,
    ];
    if ( $requested_status === 'scheduled' ) {
        $data['publish_date'] = $schedule_at;
    } elseif ( $requested_status === 'published' && $schedule_at !== null && $existing_post !== null && trim( (string) ( $existing_post->publish_date ?? '' ) ) === '' ) {
        $data['publish_date'] = $schedule_at;
    }
    metis_cms_ajax_assert_layout_valid( $data['draft_content_json'] );
    $blocks = metis_cms_ajax_blocks_from_layout_json( $data['draft_content_json'] );
    metis_cms_ajax_assert_blocks_valid_for_status( $blocks, 'post', 'standard', $requested_status, $autosave );
    if ( in_array( $requested_status, [ 'published', 'scheduled' ], true ) && ! $autosave ) {
        metis_cms_ajax_assert_accessibility_valid( $blocks );
    }

    if ( trim( $raw_slug ) !== '' ) {
        $data['slug'] = $normalized_slug;
    }

    $blocked_empty_content_overwrite = false;
    $blocked_stale_content_overwrite = false;
    if ( $existing_post !== null && array_key_exists( 'draft_content_json', $data ) ) {
        $incoming_has_content = metis_cms_ajax_layout_has_meaningful_content( $data['draft_content_json'] );
        $existing_has_content = metis_cms_ajax_layout_has_meaningful_content( $existing_post->draft_content_json ?? $existing_post->content_json ?? '' );
        if ( ! $incoming_has_content && $existing_has_content ) {
            $blocked_empty_content_overwrite = true;
            if ( class_exists( 'Metis_Logger' ) ) {
                \Metis_Logger::warn( 'Blocked destructive empty post layout overwrite', [
                    'post_id' => $id,
                    'post_code' => (string) ( $existing_post->post_code ?? '' ),
                    'autosave' => $autosave,
                    'incoming_layout_len' => strlen( (string) $data['draft_content_json'] ),
                    'incoming_summary' => metis_cms_ajax_layout_content_summary( $data['draft_content_json'] ),
                    'existing_summary' => metis_cms_ajax_layout_content_summary( $existing_post->draft_content_json ?? $existing_post->content_json ?? '' ),
                ] );
            }
            unset( $data['draft_content_json'] );
        } else {
            $incoming_saved_at = metis_cms_ajax_layout_saved_at_epoch( $data['draft_content_json'] );
            $existing_saved_at = metis_cms_ajax_layout_saved_at_epoch( $existing_post->draft_content_json ?? $existing_post->content_json ?? '' );
            if ( $autosave && $incoming_saved_at > 0 && $existing_saved_at > 0 && $incoming_saved_at <= $existing_saved_at ) {
                $blocked_stale_content_overwrite = true;
                if ( class_exists( 'Metis_Logger' ) ) {
                    \Metis_Logger::warn( 'Blocked stale post autosave overwrite', [
                        'post_id' => $id,
                        'post_code' => (string) ( $existing_post->post_code ?? '' ),
                        'incoming_saved_at' => $incoming_saved_at,
                        'existing_saved_at' => $existing_saved_at,
                    ] );
                }
                unset( $data['draft_content_json'] );
            }
        }
    }

    $ok = PostService::update( $id, $data );
    if ( $ok === false ) {
        metis_runtime_send_json_error( 'Failed to save post.', 500 );
    }
    $suppressed_content_write = $blocked_empty_content_overwrite || $blocked_stale_content_overwrite;
    if ( $autosave ) {
        $autosave_content_json = (string) ( $data['draft_content_json'] ?? '' );
        if ( $suppressed_content_write && $existing_post !== null ) {
            $autosave_content_json = (string) ( $existing_post->draft_content_json ?? $existing_post->content_json ?? '' );
        }
        EditorAutosaveService::saveDraft( 'post', $id, [
            'title' => $title,
            'slug' => (string) ( $data['slug'] ?? '' ),
            'content_json' => $autosave_content_json,
            'excerpt' => (string) ( $data['excerpt'] ?? '' ),
        ] );
    }

    if ( $requested_status === 'published' && ! $autosave && ! PostService::publish( $id ) ) {
        metis_runtime_send_json_error( 'Post was saved but could not be published.', 500 );
    }

    $autosave = ! empty( $_POST['autosave'] );
    $revision_note = isset( $_POST['revision_note'] )
        ? metis_text_clean( (string) metis_runtime_unslash( $_POST['revision_note'] ) )
        : ( $autosave ? 'Autosave' : ( $requested_status === 'published' ? 'Published' : ( $requested_status === 'scheduled' ? 'Scheduled' : 'Saved draft' ) ) );
    $checkpoint_content_json = (string) ( $data['draft_content_json'] ?? '' );
    if ( $suppressed_content_write && $existing_post !== null ) {
        $checkpoint_content_json = (string) ( $existing_post->draft_content_json ?? $existing_post->content_json ?? '' );
    }
    EditorVersionService::checkpoint(
        'post',
        $id,
        [
            'title' => $title,
            'slug' => isset( $data['slug'] ) ? (string) $data['slug'] : '',
            'status' => $requested_status,
            'content_json' => $checkpoint_content_json,
            'excerpt' => (string) ( $data['excerpt'] ?? '' ),
            'content_format' => $content_format,
            'seo_meta_json' => (string) ( $data['seo_meta_json'] ?? '' ),
            'schedule_at' => (string) ( $schedule_at ?? '' ),
            'autosave' => $autosave,
        ],
        $revision_note
    );

    $post = PostService::getById( $id );
    if ( $post === null ) {
        metis_runtime_send_json_error( 'Post saved but reload failed.', 500 );
    }
    $saved_title = (string) ( $post->title ?? '' );
    if ( $saved_title !== $title ) {
        metis_runtime_send_json_error( 'Post save verification failed (title mismatch).', 500 );
    }
    if ( isset( $data['draft_content_json'] ) ) {
        $expected_content_hash = md5( (string) $data['draft_content_json'] );
        $saved_content_hash = md5( (string) ( $post->draft_content_json ?? '' ) );
        if ( $expected_content_hash !== $saved_content_hash ) {
            metis_runtime_send_json_error( 'Post save verification failed (content mismatch).', 500 );
        }
    }

    $row = $post->toArray();
    $author_source_id = (int) ( $row['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['author_id'] ?? 0 );
    }
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['updated_by'] ?? 0 );
    }
    $row['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $row['last_edit'] = (string) ( $row['updated_at'] ?? '' );
    $row['published_date'] = (string) ( $row['publish_date'] ?? '' );
    $row['parent_id'] = isset( $row['parent_page_id'] ) ? (int) $row['parent_page_id'] : 0;
    $row['public_path'] = method_exists( PostService::class, 'publicPath' ) ? (string) PostService::publicPath( $post ) : '';
    $row = metis_cms_ajax_post_category_payload( $row );

    metis_runtime_send_json_success( [
        'message' => 'Post saved.',
        'post'    => $row,
    ] );
} );

metis_ajax_register_handler( 'metis_cms_post_publish', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.publish' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid post ID.', 400 );
    }

    $post = PostService::getById( $id );
    if ( $post === null ) {
        metis_runtime_send_json_error( 'Post not found.', 404 );
    }
    if ( ! PostService::isReadyForPublicRoute( $post ) ) {
        metis_runtime_send_json_error( 'Posts must have a category before publishing.', 422 );
    }

    $ok = PostService::publish( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to publish post.', 500 );
    }

    $post = PostService::getById( $id );
    if ( $post === null ) {
        metis_runtime_send_json_error( 'Post published but reload failed.', 500 );
    }

    $row = $post->toArray();
    $author_source_id = (int) ( $row['created_by'] ?? 0 );
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['author_id'] ?? 0 );
    }
    if ( $author_source_id < 1 ) {
        $author_source_id = (int) ( $row['updated_by'] ?? 0 );
    }
    $row['author_name'] = metis_cms_ajax_author_full_name( $author_source_id );
    $row['last_edit'] = (string) ( $row['updated_at'] ?? '' );
    $row['published_date'] = (string) ( $row['publish_date'] ?? '' );
    $row['parent_id'] = isset( $row['parent_page_id'] ) ? (int) $row['parent_page_id'] : 0;
    $row['public_path'] = method_exists( PostService::class, 'publicPath' ) ? (string) PostService::publicPath( $post ) : '';
    $row = metis_cms_ajax_post_category_payload( $row );

    metis_runtime_send_json_success( [ 'message' => 'Post published.', 'post' => $row ] );
} );

metis_ajax_register_handler( 'metis_cms_post_categories_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    metis_runtime_send_json_success( [ 'categories' => PostCategoryService::all( true ) ] );
} );

metis_ajax_register_handler( 'metis_cms_post_category_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.edit' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $name = isset( $_POST['name'] ) ? metis_text_clean( (string) metis_runtime_unslash( $_POST['name'] ) ) : '';
    $slug = isset( $_POST['slug'] ) ? metis_slug_clean( (string) metis_runtime_unslash( $_POST['slug'] ) ) : '';
    $status = isset( $_POST['status'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['status'] ) ) : 'active';
    $sort_order = isset( $_POST['sort_order'] ) ? (int) $_POST['sort_order'] : 0;
    $parent_id = isset( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;

    if ( trim( $name ) === '' ) {
        metis_runtime_send_json_error( 'Category name is required.', 422 );
    }

    $ok = PostCategoryService::save( $id, $name, $slug, $status, $sort_order, $parent_id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Category could not be saved. Check for duplicate names or slugs, invalid parent selection, or hierarchy loops.', 422 );
    }

    metis_runtime_send_json_success( [
        'message' => 'Category saved.',
        'categories' => PostCategoryService::all( true ),
    ] );
} );

metis_ajax_register_handler( 'metis_cms_post_category_delete', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.delete' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid category ID.', 400 );
    }

    $ok = PostCategoryService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Category could not be deleted. Remove it from posts first.', 422 );
    }

    metis_runtime_send_json_success( [
        'message' => 'Category deleted.',
        'categories' => PostCategoryService::all( true ),
    ] );
} );

metis_ajax_register_handler( 'metis_cms_post_delete', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.delete' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid post ID.', 400 );
    }

    $ok = PostService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete post.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Post deleted.', 'id' => $id ] );
} );

// ---------------------------------------------------------------------------
// Menus
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_menus_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    metis_runtime_send_json_success( [ 'menus' => MenuService::getAll() ] );
} );

metis_ajax_register_handler( 'metis_cms_menu_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_menus' );

    $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $name = isset( $_POST['name'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['name'] ) ) : '';
    if ( $name === '' ) {
        metis_runtime_send_json_error( 'Menu name is required.', 400 );
    }

    $data = [
        'name'       => $name,
        'location'   => isset( $_POST['location'] ) ? metis_key_clean( $_POST['location'] ) : null,
        'items_json' => isset( $_POST['items_json'] ) ? metis_runtime_unslash( $_POST['items_json'] ) : '[]',
    ];

    if ( $id > 0 ) {
        $ok = MenuService::update( $id, $data );
        $message = $ok ? 'Menu updated.' : 'Failed to update menu.';
    } else {
        $new_id = MenuService::create( $data );
        $ok = (bool) $new_id;
        $message = $ok ? 'Menu created.' : 'Failed to create menu.';
        if ( $new_id ) {
            $data['id'] = $new_id;
        }
    }

    if ( ! $ok ) {
        metis_runtime_send_json_error( $message, 500 );
    }

    metis_runtime_send_json_success( [ 'message' => $message, 'menus' => MenuService::getAll() ] );
} );

metis_ajax_register_handler( 'metis_cms_menu_delete', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_menus' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid menu ID.', 400 );
    }

    $ok = MenuService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete menu.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Menu deleted.', 'menus' => MenuService::getAll() ] );
} );

// ---------------------------------------------------------------------------
// Banners
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_banners_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    metis_runtime_send_json_success( [ 'banners' => BannerService::getAll() ] );
} );

metis_ajax_register_handler( 'metis_cms_banner_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_banners' );

    $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $name = isset( $_POST['name'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['name'] ) ) : '';
    if ( $name === '' ) {
        metis_runtime_send_json_error( 'Banner name is required.', 400 );
    }

    $data = [
        'name'          => $name,
        'type'          => isset( $_POST['type'] ) ? metis_key_clean( (string) $_POST['type'] ) : 'top_banner',
        'status'        => isset( $_POST['status'] ) ? metis_key_clean( (string) $_POST['status'] ) : 'draft',
        'dismiss_mode'  => isset( $_POST['dismiss_mode'] ) ? metis_key_clean( (string) $_POST['dismiss_mode'] ) : 'session',
        'start_at'      => isset( $_POST['start_at'] ) ? metis_runtime_unslash( $_POST['start_at'] ) : '',
        'end_at'        => isset( $_POST['end_at'] ) ? metis_runtime_unslash( $_POST['end_at'] ) : '',
        'timezone'      => isset( $_POST['timezone'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['timezone'] ) ) : 'UTC',
        'content_json'  => metis_cms_ajax_post_string( 'content_json', false ) ?? '{}',
        'targeting_json'=> metis_cms_ajax_post_string( 'targeting_json', false ) ?? '{}',
    ];

    if ( $id > 0 ) {
        $ok = BannerService::update( $id, $data );
    } else {
        $new_id = BannerService::create( $data );
        $ok = (bool) $new_id;
    }

    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to save banner.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Banner saved.', 'banners' => BannerService::getAll() ] );
} );

metis_ajax_register_handler( 'metis_cms_banner_delete', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_banners' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid banner ID.', 400 );
    }

    $ok = BannerService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete banner.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Banner deleted.', 'banners' => BannerService::getAll() ] );
} );

// ---------------------------------------------------------------------------
// Popups
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_popups_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    metis_runtime_send_json_success( [ 'popups' => PopupService::getAll() ] );
} );

metis_ajax_register_handler( 'metis_cms_popup_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_popups' );

    $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $name = isset( $_POST['name'] ) ? metis_text_clean( metis_runtime_unslash( $_POST['name'] ) ) : '';
    if ( $name === '' ) {
        metis_runtime_send_json_error( 'Popup name is required.', 400 );
    }

    $data = [
        'name'                => $name,
        'trigger_type'        => isset( $_POST['trigger_type'] ) ? metis_key_clean( $_POST['trigger_type'] ) : 'click',
        'trigger_config_json' => metis_cms_ajax_post_string( 'trigger_config_json', false ),
        'layout_json'         => metis_cms_ajax_post_string( 'layout_json', false ),
        'status'              => isset( $_POST['status'] ) ? metis_key_clean( (string) $_POST['status'] ) : 'draft',
        'display_rules_json'  => metis_cms_ajax_post_string( 'display_rules_json', false ),
    ];
    metis_cms_ajax_assert_layout_valid( $data['layout_json'] );
    $popup_blocks = metis_cms_ajax_blocks_from_layout_json( $data['layout_json'] );
    metis_cms_ajax_assert_blocks_valid( $popup_blocks, 'web_part', 'standard' );
    metis_cms_ajax_assert_accessibility_valid( $popup_blocks );

    if ( $id > 0 ) {
        $ok = PopupService::update( $id, $data );
    } else {
        $ok = (bool) PopupService::create( $data );
    }

    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to save popup.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Popup saved.' ] );
} );

metis_ajax_register_handler( 'metis_cms_popup_delete', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_popups' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid popup ID.', 400 );
    }

    $ok = PopupService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete popup.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Popup deleted.' ] );
} );

// ---------------------------------------------------------------------------
// Redirects
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_redirects_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    metis_runtime_send_json_success( [ 'redirects' => RedirectService::all() ] );
} );

metis_ajax_register_handler( 'metis_cms_redirect_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_redirects' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $source_path = metis_cms_ajax_post_string( 'source_path', false );
    $destination_path = metis_cms_ajax_post_string( 'destination_path', false );
    $redirect_type = isset( $_POST['redirect_type'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['redirect_type'] ) ) : '301';
    $is_active = isset( $_POST['is_active'] ) ? (bool) (int) $_POST['is_active'] : ! empty( $_POST['is_active'] );
    $notes = metis_cms_ajax_post_string( 'notes', false );

    if ( trim( (string) $source_path ) === '' ) {
        metis_runtime_send_json_error( 'Source path is required.', 422 );
    }
    if ( trim( (string) $destination_path ) === '' ) {
        metis_runtime_send_json_error( 'Destination path is required.', 422 );
    }

    $ok = RedirectService::save(
        $id,
        (string) $source_path,
        (string) $destination_path,
        $redirect_type === '302' ? '302' : '301',
        $is_active,
        (string) $notes
    );

    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Redirect could not be saved. Check for duplicate paths or redirect loops.', 422 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Redirect saved.', 'redirects' => RedirectService::all() ] );
} );

metis_ajax_register_handler( 'metis_cms_redirect_delete', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_redirects' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid redirect ID.', 400 );
    }

    $ok = RedirectService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete redirect.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Redirect deleted.', 'redirects' => RedirectService::all() ] );
} );

// ---------------------------------------------------------------------------
// Templates
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_templates_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $items = TemplateService::getAll();
    metis_runtime_send_json_success( [
        'templates' => array_map( static fn ( $template ) => $template->toArray(), $items ),
        'active_template' => TemplateService::getActiveTemplateSlug(),
    ] );
} );

metis_ajax_register_handler( 'metis_cms_template_get', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $template_key = isset( $_POST['template_key'] ) ? metis_key_clean( (string) $_POST['template_key'] ) : '';
    $template_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $template = null;
    if ( $template_key !== '' ) {
        $template = TemplateService::getByTemplateKey( $template_key );
    } elseif ( $template_id > 0 ) {
        $template = TemplateService::getById( $template_id );
    } else {
        metis_runtime_send_json_error( 'Template key or template ID is required.', 400 );
    }
    if ( $template === null ) {
        metis_runtime_send_json_error( 'Template not found.', 404 );
    }

    $meta = TemplateService::templateMeta( (string) $template->template_key );
    metis_runtime_send_json_success( [
        'template' => $template->toArray(),
        'meta' => $meta,
        'active_template' => TemplateService::getActiveTemplateSlug(),
    ] );
} );

metis_ajax_register_handler( 'metis_cms_template_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_templates' );

    $template_key = isset( $_POST['template_key'] ) ? metis_key_clean( (string) $_POST['template_key'] ) : '';
    if ( $template_key === '' ) {
        metis_runtime_send_json_error( 'Template key is required.', 422 );
    }

    if ( ! TemplateService::setActiveTemplateSlug( $template_key ) ) {
        metis_runtime_send_json_error( 'Template could not be activated.', 422 );
    }

    $saved_template = TemplateService::getByTemplateKey( $template_key );
    if ( $saved_template === null ) {
        metis_runtime_send_json_error( 'Template could not be loaded after activation.', 500 );
    }

    metis_runtime_send_json_success( [
        'message' => 'Template activated.',
        'template' => $saved_template->toArray(),
        'active_template' => TemplateService::getActiveTemplateSlug(),
    ] );
} );

metis_ajax_register_handler( 'metis_cms_template_delete', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_templates' );
    metis_runtime_send_json_error( 'File-based templates cannot be deleted from admin.', 422 );
} );

// ---------------------------------------------------------------------------
// Theme
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_theme_get', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $theme = ThemeService::getActiveNormalized();
    metis_runtime_send_json_success( [ 'theme' => $theme ] );
} );

metis_ajax_register_handler( 'metis_cms_theme_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_theme' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

    $data = [
        'global_styles_json' => metis_cms_ajax_post_string( 'global_styles_json', false ),
        'typography_json'    => metis_cms_ajax_post_string( 'typography_json', false ),
        'color_palette_json' => metis_cms_ajax_post_string( 'color_palette_json', false ),
        'spacing_json'       => metis_cms_ajax_post_string( 'spacing_json', false ),
        'custom_tokens_json' => metis_cms_ajax_post_string( 'custom_tokens_json', false ),
    ];

    if ( $id > 0 ) {
        $ok = ThemeService::update( $id, $data );
        if ( $ok ) {
            ThemeService::activate( $id );
        }
    } else {
        $new_id = ThemeService::save( $data );
        $ok = (bool) $new_id;
        if ( $new_id ) {
            ThemeService::activate( (int) $new_id );
        }
    }

    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to save theme.', 500 );
    }

    metis_runtime_send_json_success(
        [
            'message' => 'Theme saved.',
            'theme' => ThemeService::getActiveNormalized(),
        ]
    );
} );

// ---------------------------------------------------------------------------
// Launch controls
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_launch_status', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    metis_runtime_send_json_success( [
        'message' => 'Launch readiness refreshed.',
        'status' => CmsLaunchService::status(),
    ] );
} );

metis_ajax_register_handler( 'metis_cms_launch_enable', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.launch' );

    $force = ! empty( $_POST['force'] );
    $result = CmsLaunchService::enable( $force );
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( [
            'message' => (string) ( $result['message'] ?? 'Unable to enable public CMS routes.' ),
            'status' => $result['status'] ?? CmsLaunchService::status(),
        ], 422 );
    }

    metis_runtime_send_json_success( [
        'message' => (string) ( $result['message'] ?? 'Public CMS routes enabled.' ),
        'status' => $result['status'] ?? CmsLaunchService::status(),
    ] );
} );

metis_ajax_register_handler( 'metis_cms_launch_disable', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.launch' );

    $result = CmsLaunchService::disable();
    if ( empty( $result['ok'] ) ) {
        metis_runtime_send_json_error( [
            'message' => (string) ( $result['message'] ?? 'Unable to disable public CMS routes.' ),
            'status' => $result['status'] ?? CmsLaunchService::status(),
        ], 500 );
    }

    metis_runtime_send_json_success( [
        'message' => (string) ( $result['message'] ?? 'Public CMS routes disabled.' ),
        'status' => $result['status'] ?? CmsLaunchService::status(),
    ] );
} );

metis_ajax_register_handler( 'metis_cms_layout_profile_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.manage_theme' );

    $requested_profile = isset( $_POST['site_layout_profile'] )
        ? metis_key_clean( (string) metis_runtime_unslash( $_POST['site_layout_profile'] ) )
        : '';
    $site_layout_profile = LayoutProfileService::sanitizeCMSProfile( $requested_profile );

    if ( ! TemplateService::setActiveTemplateSlug( $site_layout_profile ) ) {
        metis_runtime_send_json_error( 'Failed to update site template.', 500 );
    }

    metis_runtime_send_json_success(
        [
            'message' => 'CMS template updated.',
            'site_layout_profile' => $site_layout_profile,
            'active_template' => TemplateService::getActiveTemplateSlug(),
        ]
    );
} );

// ---------------------------------------------------------------------------
// Block registry
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_blocks_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $requested_context = isset( $_POST['context'] ) ? metis_key_clean( (string) $_POST['context'] ) : 'cms';
    $requested_render_mode = isset( $_POST['render_mode'] ) ? metis_key_clean( (string) $_POST['render_mode'] ) : '';
    $context = EditorContextPolicy::normalizeContext( $requested_context );
    $render_mode = EditorContextPolicy::normalizeRenderMode( $requested_render_mode, $context );
    $blocks = EditorContextPolicy::filterRegistry( BlockRegistry::all(), $context, $render_mode );

    // Return only the fields the UI needs (strip schema detail noise)
    $summary = [];
    foreach ( $blocks as $type => $def ) {
        $summary[] = [
            'type'     => $type,
            'label'    => $def['label'] ?? $type,
            'category' => $def['category'] ?? 'content',
            'icon'     => $def['icon'] ?? 'block',
        ];
    }

    metis_runtime_send_json_success(
        [
            'blocks' => $summary,
            'context' => $context,
            'render_mode' => $render_mode,
        ]
    );
} );

// ---------------------------------------------------------------------------
// Reusable blocks
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_cms_reusable_blocks_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $requested_context = isset( $_POST['context'] ) ? metis_key_clean( (string) $_POST['context'] ) : 'cms';
    $requested_render_mode = isset( $_POST['render_mode'] ) ? metis_key_clean( (string) $_POST['render_mode'] ) : '';
    $search = isset( $_POST['search'] ) ? metis_text_clean( (string) metis_runtime_unslash( $_POST['search'] ) ) : '';

    $context = EditorContextPolicy::normalizeContext( $requested_context );
    $render_mode = EditorContextPolicy::normalizeRenderMode( $requested_render_mode, $context );
    $items = ReusableBlockService::listForContext( $context, $render_mode, $search );

    metis_runtime_send_json_success(
        [
            'items' => $items,
            'context' => $context,
            'render_mode' => $render_mode,
        ]
    );
} );

metis_ajax_register_handler( 'metis_cms_reusable_block_get', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $code = isset( $_POST['block_code'] ) ? strtoupper( trim( (string) metis_runtime_unslash( $_POST['block_code'] ) ) ) : '';
    if ( $code === '' ) {
        metis_runtime_send_json_error( 'Reusable block code is required.', 400 );
    }

    $item = ReusableBlockService::getByCode( $code );
    if ( ! is_array( $item ) ) {
        metis_runtime_send_json_error( 'Reusable block not found.', 404 );
    }

    metis_runtime_send_json_success( [ 'item' => $item ] );
} );

metis_ajax_register_handler( 'metis_cms_reusable_block_save', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.edit' );

    $name = isset( $_POST['name'] ) ? metis_text_clean( (string) metis_runtime_unslash( $_POST['name'] ) ) : '';
    $category = isset( $_POST['category'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['category'] ) ) : 'custom';
    $context = isset( $_POST['context'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['context'] ) ) : 'cms';
    $render_mode = isset( $_POST['render_mode'] ) ? metis_key_clean( (string) metis_runtime_unslash( $_POST['render_mode'] ) ) : '';
    $is_global = isset( $_POST['is_global'] ) ? (int) $_POST['is_global'] === 1 : true;
    $block_code = isset( $_POST['block_code'] ) ? strtoupper( trim( (string) metis_runtime_unslash( $_POST['block_code'] ) ) ) : '';
    $block_payload = isset( $_POST['block_json'] ) ? metis_runtime_unslash( $_POST['block_json'] ) : null;

    if ( ! is_string( $block_payload ) || trim( $block_payload ) === '' ) {
        metis_runtime_send_json_error( 'Reusable block payload is required.', 400 );
    }
    $decoded = json_decode( $block_payload, true );
    if ( ! is_array( $decoded ) ) {
        metis_runtime_send_json_error( 'Reusable block payload is invalid JSON.', 422 );
    }

    $normalized_context = EditorContextPolicy::normalizeContext( $context );
    $normalized_mode = EditorContextPolicy::normalizeRenderMode( $render_mode, $normalized_context );
    metis_cms_ajax_assert_blocks_valid( [ $decoded ], $normalized_context, $normalized_mode );
    metis_cms_ajax_assert_accessibility_valid( [ $decoded ] );

    $saved = ReusableBlockService::save( $decoded, $name, $category, $is_global, $block_code );
    if ( ! is_array( $saved ) ) {
        metis_runtime_send_json_error( 'Failed to save reusable block.', 500 );
    }

    metis_runtime_send_json_success(
        [
            'message' => 'Reusable block saved.',
            'item' => $saved,
        ]
    );
} );

/* ---- Donation campaign list for editor block picker ---- */
metis_ajax_register_handler( 'metis_donations_campaign_list', function (): void {
    metis_cms_ajax_verify_nonce();
    metis_cms_ajax_require_permission( 'cms.view' );

    $db = metis_get_db();
    $table = function_exists('metis_tables_get') ? metis_tables_get('donation_campaigns') : 'metis_donation_campaigns';
    $rows = $db->fetchAll( "SELECT cid AS id, cname AS name FROM {$table} WHERE active = 1 ORDER BY cname ASC" );
    metis_runtime_send_json_success( [ 'campaigns' => $rows ?: [] ] );
} );

\Metis_Logger::info( 'CMS AJAX handlers loaded' );
