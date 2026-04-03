<?php
declare(strict_types=1);

if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

use Metis\Modules\Website\Services\PageService;
use Metis\Modules\Website\Services\PostService;
use Metis\Modules\Website\Services\MenuService;
use Metis\Modules\Website\Services\BannerService;
use Metis\Modules\Website\Services\PopupService;
use Metis\Modules\Website\Services\ThemeService;
use Metis\Modules\Website\Services\HomepageService;
use Metis\Modules\Website\Services\TemplateService;
use Metis\Modules\Website\Services\RevisionTimelineService;
use Metis\Modules\Website\Services\EditorContextPolicy;
use Metis\Modules\Website\Services\EditorLayoutService;
use Metis\Modules\Website\Services\ReusableBlockService;
use Metis\Modules\Website\Services\BlockRenderer;
use Metis\Modules\Website\Services\WebsiteRenderer;
use Metis\Modules\Website\BlockRegistry;

if ( function_exists( 'metis_ajax_register_controller' ) ) {
    $metis_website_ajax_permissions = [
        'metis_website_block_registry' => 'view',
        'metis_website_pages_list' => 'view',
        'metis_website_page_get' => 'view',
        'metis_website_page_create' => 'create',
        'metis_website_page_save' => 'edit',
        'metis_website_homepage_set' => 'edit',
        'metis_website_page_publish' => 'edit',
        'metis_website_page_unpublish' => 'edit',
        'metis_website_page_delete' => 'delete',
        'metis_website_posts_list' => 'view',
        'metis_website_post_get' => 'view',
        'metis_website_post_create' => 'create',
        'metis_website_post_save' => 'edit',
        'metis_website_post_publish' => 'edit',
        'metis_website_post_delete' => 'delete',
        'metis_website_menus_list' => 'view',
        'metis_website_menu_save' => 'edit',
        'metis_website_menu_delete' => 'delete',
        'metis_website_banners_list' => 'view',
        'metis_website_banner_save' => 'edit',
        'metis_website_banner_delete' => 'delete',
        'metis_website_popups_list' => 'view',
        'metis_website_popup_save' => 'edit',
        'metis_website_popup_delete' => 'delete',
        'metis_website_templates_list' => 'view',
        'metis_website_template_get' => 'view',
        'metis_website_template_save' => 'edit',
        'metis_website_template_delete' => 'delete',
        'metis_website_theme_get' => 'view',
        'metis_website_theme_save' => 'edit',
        'metis_website_blocks_list' => 'view',
        'metis_website_reusable_blocks_list' => 'view',
        'metis_website_reusable_block_get' => 'view',
        'metis_website_reusable_block_save' => 'edit',
    ];

    foreach ( $metis_website_ajax_permissions as $action => $permission ) {
        metis_ajax_register_controller( $action, [
            'module' => 'website',
            'permission' => $permission,
        ] );
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function metis_website_ajax_verify_nonce(): void {
    $nonce = isset( $_POST['nonce'] ) && is_scalar( $_POST['nonce'] )
        ? trim( (string) metis_runtime_unslash( $_POST['nonce'] ) )
        : '';
    $action_nonce = isset( $_POST['metis_action_nonce'] ) && is_scalar( $_POST['metis_action_nonce'] )
        ? trim( (string) metis_runtime_unslash( $_POST['metis_action_nonce'] ) )
        : '';
    $action = isset( $_POST['action'] ) && is_scalar( $_POST['action'] )
        ? sanitize_key( (string) metis_runtime_unslash( $_POST['action'] ) )
        : '';

    $valid = false;
    if ( $nonce !== '' && function_exists( 'metis_runtime_verify_nonce' ) ) {
        $valid = metis_runtime_verify_nonce( $nonce, 'metis_website' );
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

function metis_website_ajax_require_permission( string $key ): void {
    if ( ! metis_security_user_can( $key ) ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }
}

/**
 * @return array<string,mixed>
 */
function metis_website_ajax_decode_json_array( $raw ): array {
    if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
        return [];
    }
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

/**
 * @return array<int,mixed>
 */
function metis_website_ajax_blocks_from_layout_json( $raw_json ): array {
    return EditorLayoutService::modulesFromLayout( $raw_json );
}

function metis_website_ajax_assert_layout_valid( $raw_json ): void {
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
 * @return array<int,mixed>
 */
function metis_website_ajax_blocks_from_template_structure_json( $raw_json ): array {
    $decoded = metis_website_ajax_decode_json_array( $raw_json );
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
function metis_website_ajax_assert_blocks_valid( array $blocks, string $context, string $render_mode = '' ): void {
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
function metis_website_ajax_assert_blocks_valid_for_status(
    array $blocks,
    string $context,
    string $render_mode,
    string $status,
    bool $autosave = false
): void {
    $normalized_status = sanitize_key( $status );
    $normalized_context = sanitize_key( $context );
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
function metis_website_ajax_assert_accessibility_valid( array $blocks ): void {
    $errors = [];
    $state = [
        'last_heading_level' => 0,
    ];
    metis_website_ajax_validate_accessibility_blocks( $blocks, 'blocks', $state, $errors );
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
function metis_website_ajax_validate_accessibility_blocks( array $blocks, string $path, array &$state, array &$errors ): void {
    foreach ( $blocks as $index => $block ) {
        if ( ! is_array( $block ) ) {
            continue;
        }
        $block_path = $path . '.' . (string) $index;
        $type = sanitize_key( (string) ( $block['type'] ?? '' ) );
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
                $ratio = metis_website_ajax_color_contrast_ratio( $bg, $fg );
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
            $ratio = metis_website_ajax_color_contrast_ratio( $block_bg, $block_fg );
            if ( $ratio > 0 && $ratio < 3.0 ) {
                $errors[] = [
                    'path' => $block_path,
                    'message' => 'Text and background colors have low contrast.',
                ];
            }
        }

        if ( isset( $data['blocks'] ) && is_array( $data['blocks'] ) ) {
            metis_website_ajax_validate_accessibility_blocks( $data['blocks'], $block_path . '.blocks', $state, $errors );
        }
        if ( isset( $data['col_blocks'] ) && is_array( $data['col_blocks'] ) ) {
            foreach ( $data['col_blocks'] as $col_index => $col_blocks ) {
                if ( is_array( $col_blocks ) ) {
                    metis_website_ajax_validate_accessibility_blocks( $col_blocks, $block_path . '.col_blocks.' . (string) $col_index, $state, $errors );
                }
            }
        }
    }
}

function metis_website_ajax_color_contrast_ratio( string $a, string $b ): float {
    $rgb_a = metis_website_ajax_parse_color_rgb( $a );
    $rgb_b = metis_website_ajax_parse_color_rgb( $b );
    if ( ! is_array( $rgb_a ) || ! is_array( $rgb_b ) ) {
        return 0.0;
    }
    $lum_a = metis_website_ajax_relative_luminance( $rgb_a );
    $lum_b = metis_website_ajax_relative_luminance( $rgb_b );
    $light = max( $lum_a, $lum_b );
    $dark = min( $lum_a, $lum_b );
    return ( $light + 0.05 ) / ( $dark + 0.05 );
}

/**
 * @return array{r:int,g:int,b:int}|null
 */
function metis_website_ajax_parse_color_rgb( string $raw ): ?array {
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
function metis_website_ajax_relative_luminance( array $rgb ): float {
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
function metis_website_ajax_collect_rendered_block_map( array $blocks, array $render_context, array &$map ): void {
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
            metis_website_ajax_collect_rendered_block_map( $data['blocks'], $render_context, $map );
        }
        if ( isset( $data['col_blocks'] ) && is_array( $data['col_blocks'] ) ) {
            foreach ( $data['col_blocks'] as $col_blocks ) {
                if ( is_array( $col_blocks ) ) {
                    metis_website_ajax_collect_rendered_block_map( $col_blocks, $render_context, $map );
                }
            }
        }
    }
}

function metis_website_ajax_parse_schedule_at( string $raw ): ?string {
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

function metis_website_ajax_entity_type_for_context( string $context ): string {
    $normalized = EditorContextPolicy::normalizeContext( $context );
    if ( $normalized === 'post' ) {
        return 'post';
    }
    if ( $normalized === 'template' ) {
        return 'template';
    }
    return 'page';
}

function metis_website_ajax_clean_entity_key( $raw ): string {
    if ( ! is_scalar( $raw ) ) {
        return '';
    }
    $key = trim( (string) metis_runtime_unslash( $raw ) );
    if ( $key === '' ) {
        return '';
    }
    return preg_replace( '/[^A-Za-z0-9_-]/', '', $key ) ?? '';
}

function metis_website_ajax_requested_entity_key(): string {
    return metis_website_ajax_clean_entity_key( $_POST['key'] ?? '' );
}

function metis_website_ajax_resolve_entity_id( string $entity_type, int $fallback_id = 0 ): int {
    $key = metis_website_ajax_requested_entity_key();
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

function metis_website_ajax_generate_lock_token(): string {
    if ( function_exists( 'metis_runtime_generate_uuid' ) ) {
        return (string) metis_runtime_generate_uuid();
    }
    try {
        return strtolower( bin2hex( random_bytes( 16 ) ) );
    } catch ( Throwable $e ) {
        return uniqid( 'editor_lock_', true );
    }
}

function metis_website_ajax_lock_key( string $entity_type, int $entity_id ): string {
    return 'metis_editor_lock_' . sanitize_key( $entity_type ) . '_' . max( 0, $entity_id );
}

metis_ajax_register_handler( 'metis_website_block_registry', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $requested_context = isset( $_POST['context'] ) ? sanitize_key( (string) $_POST['context'] ) : 'website';
    $requested_render_mode = isset( $_POST['render_mode'] ) ? sanitize_key( (string) $_POST['render_mode'] ) : '';
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
    'module' => 'website',
    'permission' => 'view',
    'nonce_action' => 'metis_website',
] );

metis_ajax_register_handler( 'metis_editor_render_preview', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $requested_context = isset( $_POST['context'] ) ? sanitize_key( (string) $_POST['context'] ) : 'website';
    $requested_render_mode = isset( $_POST['render_mode'] ) ? sanitize_key( (string) $_POST['render_mode'] ) : '';
    $context = EditorContextPolicy::normalizeContext( $requested_context );
    $render_mode = EditorContextPolicy::normalizeRenderMode( $requested_render_mode, $context );
    $preview_device = isset( $_POST['preview_device'] ) ? sanitize_key( (string) $_POST['preview_device'] ) : 'desktop';
    if ( ! in_array( $preview_device, [ 'desktop', 'tablet', 'mobile' ], true ) ) {
        $preview_device = 'desktop';
    }

    $preview_blocks = null;
    $raw_layout = $_POST['layout'] ?? null;
    if ( is_string( $raw_layout ) ) {
        $decoded_layout = json_decode( metis_runtime_unslash( $raw_layout ), true );
        $raw_layout = is_array( $decoded_layout ) ? $decoded_layout : null;
    }
    if ( is_array( $raw_layout ) ) {
        metis_website_ajax_assert_layout_valid( $raw_layout );
        $preview_blocks = EditorLayoutService::renderBlocksFromLayout( $raw_layout );
    }

    if ( ! is_array( $preview_blocks ) ) {
        $raw_blocks = $_POST['blocks'] ?? null;
        if ( is_string( $raw_blocks ) ) {
            $decoded = json_decode( metis_runtime_unslash( $raw_blocks ), true );
            $raw_blocks = is_array( $decoded ) ? $decoded : null;
        }
        if ( ! is_array( $raw_blocks ) ) {
            metis_runtime_send_json_error( [ 'message' => 'Invalid preview content payload.' ], 400 );
        }
        $preview_blocks = $raw_blocks;
    }

    $validation = EditorContextPolicy::validateBlocks( $preview_blocks, $context, $render_mode );
    if ( ! (bool) ( $validation['valid'] ?? false ) ) {
        $errors = isset( $validation['errors'] ) && is_array( $validation['errors'] ) ? $validation['errors'] : [];
        $first = $errors !== [] && is_array( $errors[0] ?? null ) ? (string) ( $errors[0]['message'] ?? '' ) : '';
        metis_runtime_send_json_error(
            [
                'message' => $first !== '' ? $first : 'Content is invalid for preview.',
                'errors' => $errors,
            ],
            422
        );
    }

    $page_title = isset( $_POST['page_title'] ) ? sanitize_text_field( (string) metis_runtime_unslash( $_POST['page_title'] ) ) : '';
    $preview = WebsiteRenderer::renderEditorPreview(
        $preview_blocks,
        [
            'context' => $context,
            'render_mode' => $render_mode,
            'preview_device' => $preview_device,
            'page_title' => $page_title,
        ]
    );
    $render_context = is_array( $preview['context'] ?? null ) ? $preview['context'] : [];
    $blocks_html = [];
    metis_website_ajax_collect_rendered_block_map( $preview_blocks, $render_context, $blocks_html );

    metis_runtime_send_json_success(
        [
            'html' => (string) ( $preview['content_html'] ?? '' ),
            'document_html' => (string) ( $preview['document_html'] ?? '' ),
            'blocks_html' => $blocks_html,
            'context' => $context,
            'render_mode' => $render_mode,
            'preview_device' => $preview_device,
        ]
    );
}, [
    'module' => 'website',
    'permission' => 'view',
    'nonce_action' => 'metis_website',
] );

metis_ajax_register_handler( 'metis_website_editor_lock', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $context = isset( $_POST['context'] ) ? sanitize_key( (string) $_POST['context'] ) : 'website';
    $entity_type = metis_website_ajax_entity_type_for_context( $context );
    $requested_key = metis_website_ajax_requested_entity_key();
    $fallback_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $entity_id = metis_website_ajax_resolve_entity_id( $entity_type, $fallback_id );
    if ( $entity_id < 1 ) {
        if ( $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Editor lock target was not found.', 404 );
        }
        metis_runtime_send_json_error( 'Editor lock requires a saved entity.', 400 );
    }

    $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
    if ( $user_id < 1 ) {
        metis_runtime_send_json_error( 'Unauthorized', 403 );
    }

    $intent = isset( $_POST['intent'] ) ? sanitize_key( (string) $_POST['intent'] ) : 'acquire';
    if ( ! in_array( $intent, [ 'acquire', 'refresh', 'release' ], true ) ) {
        $intent = 'acquire';
    }

    $key = metis_website_ajax_lock_key( $entity_type, $entity_id );
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
        : metis_website_ajax_generate_lock_token();
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
    'module' => 'website',
    'permission' => 'edit',
    'nonce_action' => 'metis_website',
] );

metis_ajax_register_handler( 'metis_website_editor_revisions_list', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $context = isset( $_POST['context'] ) ? sanitize_key( (string) $_POST['context'] ) : 'website';
    $entity_type = metis_website_ajax_entity_type_for_context( $context );
    $requested_key = metis_website_ajax_requested_entity_key();
    $fallback_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $entity_id = metis_website_ajax_resolve_entity_id( $entity_type, $fallback_id );
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
    'module' => 'website',
    'permission' => 'view',
    'nonce_action' => 'metis_website',
] );

metis_ajax_register_handler( 'metis_website_editor_revision_restore', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $context = isset( $_POST['context'] ) ? sanitize_key( (string) $_POST['context'] ) : 'website';
    $entity_type = metis_website_ajax_entity_type_for_context( $context );
    $requested_key = metis_website_ajax_requested_entity_key();
    $fallback_id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $entity_id = metis_website_ajax_resolve_entity_id( $entity_type, $fallback_id );
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
        $template_status = isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : 'draft';
        if ( ! in_array( $template_status, [ 'draft', 'published' ], true ) ) {
            $template_status = 'draft';
        }
        $restored_structure_json = '';
        if ( isset( $payload['structure_json'] ) && is_string( $payload['structure_json'] ) ) {
            $decoded_structure = metis_website_ajax_decode_json_array( (string) $payload['structure_json'] );
            if ( $decoded_structure !== [] ) {
                $restored_structure_json = (string) $payload['structure_json'];
            }
        }
        if ( $restored_structure_json === '' && isset( $payload['layout_json'] ) && is_string( $payload['layout_json'] ) ) {
            $decoded_legacy = metis_website_ajax_decode_json_array( (string) $payload['layout_json'] );
            if ( $decoded_legacy !== [] ) {
                $restored_structure_json = (string) $payload['layout_json'];
            }
        }
        if ( $restored_structure_json === '' ) {
            $restored_structure_json = is_string( $current_template->structure_json ) && trim( $current_template->structure_json ) !== ''
                ? (string) $current_template->structure_json
                : '{}';
        }
        $update_ok = TemplateService::update( $entity_id, [
            'name' => isset( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : (string) $current_template->name,
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
        $status = isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : 'draft';
        if ( ! in_array( $status, [ 'draft', 'published', 'scheduled' ], true ) ) {
            $status = 'draft';
        }
        $schedule_at = isset( $payload['schedule_at'] ) ? metis_website_ajax_parse_schedule_at( (string) $payload['schedule_at'] ) : null;
        $update_data = [
            'title' => isset( $payload['title'] ) ? sanitize_text_field( (string) $payload['title'] ) : 'Untitled Post',
            'excerpt' => isset( $payload['excerpt'] ) ? sanitize_textarea_field( (string) $payload['excerpt'] ) : null,
            'draft_content_json' => isset( $payload['content_json'] ) ? (string) $payload['content_json'] : null,
            'seo_meta_json' => isset( $payload['seo_meta_json'] ) ? (string) $payload['seo_meta_json'] : null,
            'template_key' => isset( $payload['template_key'] ) ? sanitize_key( (string) $payload['template_key'] ) : null,
            'status' => $status,
            'publish_date' => $status === 'scheduled' ? $schedule_at : null,
        ];
        if ( isset( $payload['slug'] ) && (string) $payload['slug'] !== '' ) {
            $update_data['slug'] = sanitize_title( (string) $payload['slug'] );
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
        $status = isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : 'draft';
        if ( ! in_array( $status, [ 'draft', 'published', 'scheduled' ], true ) ) {
            $status = 'draft';
        }
        $schedule_at = isset( $payload['schedule_at'] ) ? metis_website_ajax_parse_schedule_at( (string) $payload['schedule_at'] ) : null;
        $update_data = [
            'title' => isset( $payload['title'] ) ? sanitize_text_field( (string) $payload['title'] ) : 'Untitled Page',
            'draft_layout_json' => isset( $payload['layout_json'] ) ? (string) $payload['layout_json'] : null,
            'seo_meta_json' => isset( $payload['seo_meta_json'] ) ? (string) $payload['seo_meta_json'] : null,
            'template_key' => isset( $payload['template_key'] ) ? sanitize_key( (string) $payload['template_key'] ) : null,
            'status' => $status,
            'published_at' => $status === 'scheduled' ? $schedule_at : null,
        ];
        if ( isset( $payload['slug'] ) && (string) $payload['slug'] !== '' ) {
            $update_data['slug'] = sanitize_title( (string) $payload['slug'] );
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

    RevisionTimelineService::save(
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
    'module' => 'website',
    'permission' => 'edit',
    'nonce_action' => 'metis_website',
] );

// ---------------------------------------------------------------------------
// Pages
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_website_pages_list', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';
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

metis_ajax_register_handler( 'metis_website_page_get', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $requested_key = metis_website_ajax_requested_entity_key();
    $id = metis_website_ajax_resolve_entity_id( 'page', isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
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

    metis_runtime_send_json_success( [ 'page' => $payload ] );
} );

metis_ajax_register_handler( 'metis_website_page_create', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.create' );

    $title = isset( $_POST['title'] ) ? sanitize_text_field( metis_runtime_unslash( $_POST['title'] ) ) : '';
    if ( $title === '' ) {
        metis_runtime_send_json_error( 'Title is required.', 400 );
    }

    $requested_status = isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'draft';
    if ( ! in_array( $requested_status, [ 'draft', 'published', 'scheduled' ], true ) ) {
        $requested_status = 'draft';
    }
    $schedule_at = isset( $_POST['schedule_at'] ) ? metis_website_ajax_parse_schedule_at( (string) metis_runtime_unslash( $_POST['schedule_at'] ) ) : null;
    $autosave = ! empty( $_POST['autosave'] );

    $data = [
        'title'             => $title,
        'slug'              => isset( $_POST['slug'] ) ? sanitize_title( metis_runtime_unslash( $_POST['slug'] ) ) : '',
        'status'            => $requested_status,
        'draft_layout_json' => isset( $_POST['layout_json'] ) ? metis_runtime_unslash( $_POST['layout_json'] ) : null,
        'template_key'      => isset( $_POST['template_key'] ) ? sanitize_key( $_POST['template_key'] ) : null,
    ];
    if ( $requested_status === 'scheduled' ) {
        $data['published_at'] = $schedule_at;
    }
    metis_website_ajax_assert_layout_valid( $data['draft_layout_json'] );
    $blocks = metis_website_ajax_blocks_from_layout_json( $data['draft_layout_json'] );
    metis_website_ajax_assert_blocks_valid_for_status( $blocks, 'website', 'standard', $requested_status, $autosave );
    if ( in_array( $requested_status, [ 'published', 'scheduled' ], true ) && ! $autosave ) {
        metis_website_ajax_assert_accessibility_valid( $blocks );
    }

    $page = PageService::create( $data );
    if ( $page === null ) {
        metis_runtime_send_json_error( 'Failed to create page.', 500 );
    }

    if ( $requested_status === 'published' && ! PageService::publish( (int) $page->id ) ) {
        metis_runtime_send_json_error( 'Page was created but could not be published.', 500 );
    }

    $autosave = ! empty( $_POST['autosave'] );
    $revision_note = isset( $_POST['revision_note'] )
        ? sanitize_text_field( (string) metis_runtime_unslash( $_POST['revision_note'] ) )
        : ( $autosave ? 'Autosave' : ( $requested_status === 'published' ? 'Published' : ( $requested_status === 'scheduled' ? 'Scheduled' : 'Saved draft' ) ) );
    RevisionTimelineService::save(
        'page',
        (int) $page->id,
        [
            'title' => $title,
            'slug' => (string) ( $data['slug'] ?? '' ),
            'status' => $requested_status,
            'layout_json' => (string) ( $data['draft_layout_json'] ?? '' ),
            'seo_meta_json' => isset( $_POST['seo_meta_json'] ) ? (string) metis_runtime_unslash( $_POST['seo_meta_json'] ) : '',
            'template_key' => (string) ( $data['template_key'] ?? '' ),
            'schedule_at' => (string) ( $schedule_at ?? '' ),
            'autosave' => $autosave,
        ],
        $revision_note
    );

    $set_homepage = ! empty( $_POST['set_as_homepage'] );
    if ( $set_homepage && $requested_status === 'published' ) {
        HomepageService::setHomepagePageId( (int) $page->id );
    }

    $page = PageService::getById( (int) $page->id ) ?? $page;
    $row  = $page->toArray();
    $row['is_homepage'] = HomepageService::getHomepagePageId() === $page->id;

    metis_runtime_send_json_success( [
        'message' => 'Page created.',
        'page'    => $row,
    ] );
} );

metis_ajax_register_handler( 'metis_website_page_save', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $requested_key = metis_website_ajax_requested_entity_key();
    $id = metis_website_ajax_resolve_entity_id( 'page', isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
    if ( $id < 1 ) {
        if ( $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Page not found.', 404 );
        }
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $title = isset( $_POST['title'] ) ? sanitize_text_field( metis_runtime_unslash( $_POST['title'] ) ) : '';
    if ( $title === '' ) {
        metis_runtime_send_json_error( 'Title is required.', 400 );
    }

    $requested_status = isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'draft';
    if ( ! in_array( $requested_status, [ 'draft', 'published', 'scheduled' ], true ) ) {
        $requested_status = 'draft';
    }
    $schedule_at = isset( $_POST['schedule_at'] ) ? metis_website_ajax_parse_schedule_at( (string) metis_runtime_unslash( $_POST['schedule_at'] ) ) : null;
    $autosave = ! empty( $_POST['autosave'] );

    $data = [
        'title'             => $title,
        'draft_layout_json' => isset( $_POST['layout_json'] ) ? metis_runtime_unslash( $_POST['layout_json'] ) : null,
        'seo_meta_json'     => isset( $_POST['seo_meta_json'] ) ? metis_runtime_unslash( $_POST['seo_meta_json'] ) : null,
        'template_key'      => isset( $_POST['template_key'] ) ? sanitize_key( $_POST['template_key'] ) : null,
        'status'            => $requested_status,
    ];
    if ( $requested_status === 'scheduled' ) {
        $data['published_at'] = $schedule_at;
    }
    metis_website_ajax_assert_layout_valid( $data['draft_layout_json'] );
    $blocks = metis_website_ajax_blocks_from_layout_json( $data['draft_layout_json'] );
    metis_website_ajax_assert_blocks_valid_for_status( $blocks, 'website', 'standard', $requested_status, $autosave );
    if ( in_array( $requested_status, [ 'published', 'scheduled' ], true ) && ! $autosave ) {
        metis_website_ajax_assert_accessibility_valid( $blocks );
    }

    if ( isset( $_POST['slug'] ) && $_POST['slug'] !== '' ) {
        $data['slug'] = sanitize_title( metis_runtime_unslash( $_POST['slug'] ) );
    }

    $ok = PageService::update( $id, $data );
    if ( $ok === false ) {
        metis_runtime_send_json_error( 'Failed to save page.', 500 );
    }

    if ( $requested_status === 'published' && ! PageService::publish( $id ) ) {
        metis_runtime_send_json_error( 'Page was saved but could not be published.', 500 );
    }

    $autosave = ! empty( $_POST['autosave'] );
    $revision_note = isset( $_POST['revision_note'] )
        ? sanitize_text_field( (string) metis_runtime_unslash( $_POST['revision_note'] ) )
        : ( $autosave ? 'Autosave' : ( $requested_status === 'published' ? 'Published' : ( $requested_status === 'scheduled' ? 'Scheduled' : 'Saved draft' ) ) );
    RevisionTimelineService::save(
        'page',
        $id,
        [
            'title' => $title,
            'slug' => isset( $data['slug'] ) ? (string) $data['slug'] : '',
            'status' => $requested_status,
            'layout_json' => (string) ( $data['draft_layout_json'] ?? '' ),
            'seo_meta_json' => (string) ( $data['seo_meta_json'] ?? '' ),
            'template_key' => (string) ( $data['template_key'] ?? '' ),
            'schedule_at' => (string) ( $schedule_at ?? '' ),
            'autosave' => $autosave,
        ],
        $revision_note
    );

    $set_homepage = ! empty( $_POST['set_as_homepage'] );
    if ( $set_homepage && $requested_status === 'published' ) {
        HomepageService::setHomepagePageId( $id );
    }

    metis_runtime_send_json_success( [ 'message' => 'Page saved.' ] );
} );

metis_ajax_register_handler( 'metis_website_homepage_set', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.publish' );

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

    metis_runtime_send_json_success( [ 'message' => 'Homepage updated.' ] );
} );

metis_ajax_register_handler( 'metis_website_page_publish', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.publish' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $ok = PageService::publish( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to publish page.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Page published.' ] );
} );

metis_ajax_register_handler( 'metis_website_page_unpublish', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.publish' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $ok = PageService::unpublish( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to unpublish page.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Page unpublished.' ] );
} );

metis_ajax_register_handler( 'metis_website_page_delete', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.delete' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid page ID.', 400 );
    }

    $ok = PageService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete page.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Page deleted.' ] );
} );

// ---------------------------------------------------------------------------
// Posts
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_website_posts_list', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '';
    $filters = $status !== '' ? [ 'status' => $status ] : [];

    $posts = PostService::getAll( $filters );

    metis_runtime_send_json_success( [
        'posts' => array_map( static fn ( $p ) => $p->toArray(), $posts ),
    ] );
} );

metis_ajax_register_handler( 'metis_website_post_get', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $requested_key = metis_website_ajax_requested_entity_key();
    $id = metis_website_ajax_resolve_entity_id( 'post', isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
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

    metis_runtime_send_json_success( [ 'post' => $post->toArray() ] );
} );

metis_ajax_register_handler( 'metis_website_post_create', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.create' );

    $title = isset( $_POST['title'] ) ? sanitize_text_field( metis_runtime_unslash( $_POST['title'] ) ) : '';
    if ( $title === '' ) {
        metis_runtime_send_json_error( 'Title is required.', 400 );
    }

    $requested_status = isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'draft';
    if ( ! in_array( $requested_status, [ 'draft', 'published', 'scheduled' ], true ) ) {
        $requested_status = 'draft';
    }
    $schedule_at = isset( $_POST['schedule_at'] ) ? metis_website_ajax_parse_schedule_at( (string) metis_runtime_unslash( $_POST['schedule_at'] ) ) : null;
    $autosave = ! empty( $_POST['autosave'] );

    $draft_content_json = isset( $_POST['content_json'] ) ? metis_runtime_unslash( $_POST['content_json'] ) : null;
    metis_website_ajax_assert_layout_valid( $draft_content_json );
    $blocks = metis_website_ajax_blocks_from_layout_json( $draft_content_json );
    metis_website_ajax_assert_blocks_valid_for_status( $blocks, 'post', 'standard', $requested_status, $autosave );
    if ( in_array( $requested_status, [ 'published', 'scheduled' ], true ) && ! $autosave ) {
        metis_website_ajax_assert_accessibility_valid( $blocks );
    }

    $post = PostService::create( [
        'title'              => $title,
        'excerpt'            => isset( $_POST['excerpt'] ) ? sanitize_textarea_field( metis_runtime_unslash( $_POST['excerpt'] ) ) : null,
        'draft_content_json' => $draft_content_json,
        'template_key'       => isset( $_POST['template_key'] ) ? sanitize_key( (string) $_POST['template_key'] ) : null,
        'status'             => $requested_status,
        'publish_date'       => $requested_status === 'scheduled' ? $schedule_at : null,
    ] );

    if ( $post === null ) {
        metis_runtime_send_json_error( 'Failed to create post.', 500 );
    }

    if ( $requested_status === 'published' && ! PostService::publish( (int) $post->id ) ) {
        metis_runtime_send_json_error( 'Post was created but could not be published.', 500 );
    }

    $autosave = ! empty( $_POST['autosave'] );
    $revision_note = isset( $_POST['revision_note'] )
        ? sanitize_text_field( (string) metis_runtime_unslash( $_POST['revision_note'] ) )
        : ( $autosave ? 'Autosave' : ( $requested_status === 'published' ? 'Published' : ( $requested_status === 'scheduled' ? 'Scheduled' : 'Saved draft' ) ) );
    RevisionTimelineService::save(
        'post',
        (int) $post->id,
        [
            'title' => $title,
            'slug' => (string) ( $post->slug ?? '' ),
            'status' => $requested_status,
            'content_json' => (string) $draft_content_json,
            'excerpt' => isset( $_POST['excerpt'] ) ? sanitize_textarea_field( (string) metis_runtime_unslash( $_POST['excerpt'] ) ) : '',
            'seo_meta_json' => isset( $_POST['seo_meta_json'] ) ? (string) metis_runtime_unslash( $_POST['seo_meta_json'] ) : '',
            'template_key' => isset( $_POST['template_key'] ) ? sanitize_key( (string) $_POST['template_key'] ) : '',
            'schedule_at' => (string) ( $schedule_at ?? '' ),
            'autosave' => $autosave,
        ],
        $revision_note
    );

    metis_runtime_send_json_success( [
        'message' => 'Post created.',
        'post'    => $post->toArray(),
    ] );
} );

metis_ajax_register_handler( 'metis_website_post_save', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $requested_key = metis_website_ajax_requested_entity_key();
    $id = metis_website_ajax_resolve_entity_id( 'post', isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
    if ( $id < 1 ) {
        if ( $requested_key !== '' ) {
            metis_runtime_send_json_error( 'Post not found.', 404 );
        }
        metis_runtime_send_json_error( 'Invalid post ID.', 400 );
    }

    $title = isset( $_POST['title'] ) ? sanitize_text_field( metis_runtime_unslash( $_POST['title'] ) ) : '';
    if ( $title === '' ) {
        metis_runtime_send_json_error( 'Title is required.', 400 );
    }

    $requested_status = isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'draft';
    if ( ! in_array( $requested_status, [ 'draft', 'published', 'scheduled' ], true ) ) {
        $requested_status = 'draft';
    }
    $schedule_at = isset( $_POST['schedule_at'] ) ? metis_website_ajax_parse_schedule_at( (string) metis_runtime_unslash( $_POST['schedule_at'] ) ) : null;
    $autosave = ! empty( $_POST['autosave'] );

    $data = [
        'title'              => $title,
        'excerpt'            => isset( $_POST['excerpt'] ) ? sanitize_textarea_field( metis_runtime_unslash( $_POST['excerpt'] ) ) : null,
        'draft_content_json' => isset( $_POST['content_json'] ) ? metis_runtime_unslash( $_POST['content_json'] ) : null,
        'seo_meta_json'      => isset( $_POST['seo_meta_json'] ) ? metis_runtime_unslash( $_POST['seo_meta_json'] ) : null,
        'template_key'       => isset( $_POST['template_key'] ) ? sanitize_key( (string) $_POST['template_key'] ) : null,
        'status'             => $requested_status,
        'publish_date'       => $requested_status === 'scheduled' ? $schedule_at : null,
    ];
    metis_website_ajax_assert_layout_valid( $data['draft_content_json'] );
    $blocks = metis_website_ajax_blocks_from_layout_json( $data['draft_content_json'] );
    metis_website_ajax_assert_blocks_valid_for_status( $blocks, 'post', 'standard', $requested_status, $autosave );
    if ( in_array( $requested_status, [ 'published', 'scheduled' ], true ) && ! $autosave ) {
        metis_website_ajax_assert_accessibility_valid( $blocks );
    }

    if ( isset( $_POST['slug'] ) && $_POST['slug'] !== '' ) {
        $data['slug'] = sanitize_title( metis_runtime_unslash( $_POST['slug'] ) );
    }

    $ok = PostService::update( $id, $data );
    if ( $ok === false ) {
        metis_runtime_send_json_error( 'Failed to save post.', 500 );
    }

    if ( $requested_status === 'published' && ! PostService::publish( $id ) ) {
        metis_runtime_send_json_error( 'Post was saved but could not be published.', 500 );
    }

    $autosave = ! empty( $_POST['autosave'] );
    $revision_note = isset( $_POST['revision_note'] )
        ? sanitize_text_field( (string) metis_runtime_unslash( $_POST['revision_note'] ) )
        : ( $autosave ? 'Autosave' : ( $requested_status === 'published' ? 'Published' : ( $requested_status === 'scheduled' ? 'Scheduled' : 'Saved draft' ) ) );
    RevisionTimelineService::save(
        'post',
        $id,
        [
            'title' => $title,
            'slug' => isset( $data['slug'] ) ? (string) $data['slug'] : '',
            'status' => $requested_status,
            'content_json' => (string) ( $data['draft_content_json'] ?? '' ),
            'excerpt' => (string) ( $data['excerpt'] ?? '' ),
            'seo_meta_json' => (string) ( $data['seo_meta_json'] ?? '' ),
            'template_key' => (string) ( $data['template_key'] ?? '' ),
            'schedule_at' => (string) ( $schedule_at ?? '' ),
            'autosave' => $autosave,
        ],
        $revision_note
    );

    metis_runtime_send_json_success( [ 'message' => 'Post saved.' ] );
} );

metis_ajax_register_handler( 'metis_website_post_publish', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.publish' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid post ID.', 400 );
    }

    $ok = PostService::publish( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to publish post.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Post published.' ] );
} );

metis_ajax_register_handler( 'metis_website_post_delete', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.delete' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid post ID.', 400 );
    }

    $ok = PostService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete post.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Post deleted.' ] );
} );

// ---------------------------------------------------------------------------
// Menus
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_website_menus_list', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    metis_runtime_send_json_success( [ 'menus' => MenuService::getAll() ] );
} );

metis_ajax_register_handler( 'metis_website_menu_save', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( metis_runtime_unslash( $_POST['name'] ) ) : '';
    if ( $name === '' ) {
        metis_runtime_send_json_error( 'Menu name is required.', 400 );
    }

    $data = [
        'name'       => $name,
        'location'   => isset( $_POST['location'] ) ? sanitize_key( $_POST['location'] ) : null,
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

    metis_runtime_send_json_success( [ 'message' => $message ] );
} );

metis_ajax_register_handler( 'metis_website_menu_delete', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.delete' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid menu ID.', 400 );
    }

    $ok = MenuService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete menu.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Menu deleted.' ] );
} );

// ---------------------------------------------------------------------------
// Banners
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_website_banners_list', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    metis_runtime_send_json_success( [ 'banners' => BannerService::getAll() ] );
} );

metis_ajax_register_handler( 'metis_website_banner_save', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( metis_runtime_unslash( $_POST['name'] ) ) : '';
    if ( $name === '' ) {
        metis_runtime_send_json_error( 'Banner name is required.', 400 );
    }

    $data = [
        'name'          => $name,
        'type'          => isset( $_POST['type'] ) ? sanitize_key( (string) $_POST['type'] ) : 'top_banner',
        'status'        => isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'draft',
        'dismiss_mode'  => isset( $_POST['dismiss_mode'] ) ? sanitize_key( (string) $_POST['dismiss_mode'] ) : 'session',
        'start_at'      => isset( $_POST['start_at'] ) ? metis_runtime_unslash( $_POST['start_at'] ) : '',
        'end_at'        => isset( $_POST['end_at'] ) ? metis_runtime_unslash( $_POST['end_at'] ) : '',
        'timezone'      => isset( $_POST['timezone'] ) ? sanitize_text_field( metis_runtime_unslash( $_POST['timezone'] ) ) : 'UTC',
        'content_json'  => isset( $_POST['content_json'] ) ? metis_runtime_unslash( $_POST['content_json'] ) : '{}',
        'targeting_json'=> isset( $_POST['targeting_json'] ) ? metis_runtime_unslash( $_POST['targeting_json'] ) : '{}',
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

    metis_runtime_send_json_success( [ 'message' => 'Banner saved.' ] );
} );

metis_ajax_register_handler( 'metis_website_banner_delete', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.delete' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Invalid banner ID.', 400 );
    }

    $ok = BannerService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete banner.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Banner deleted.' ] );
} );

// ---------------------------------------------------------------------------
// Popups
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_website_popups_list', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    metis_runtime_send_json_success( [ 'popups' => PopupService::getAll() ] );
} );

metis_ajax_register_handler( 'metis_website_popup_save', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( metis_runtime_unslash( $_POST['name'] ) ) : '';
    if ( $name === '' ) {
        metis_runtime_send_json_error( 'Popup name is required.', 400 );
    }

    $data = [
        'name'                => $name,
        'trigger_type'        => isset( $_POST['trigger_type'] ) ? sanitize_key( $_POST['trigger_type'] ) : 'click',
        'trigger_config_json' => isset( $_POST['trigger_config_json'] ) ? metis_runtime_unslash( $_POST['trigger_config_json'] ) : null,
        'layout_json'         => isset( $_POST['layout_json'] ) ? metis_runtime_unslash( $_POST['layout_json'] ) : null,
        'status'              => isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'draft',
        'display_rules_json'  => isset( $_POST['display_rules_json'] ) ? metis_runtime_unslash( $_POST['display_rules_json'] ) : null,
    ];
    metis_website_ajax_assert_layout_valid( $data['layout_json'] );
    $popup_blocks = metis_website_ajax_blocks_from_layout_json( $data['layout_json'] );
    metis_website_ajax_assert_blocks_valid( $popup_blocks, 'web_part', 'standard' );
    metis_website_ajax_assert_accessibility_valid( $popup_blocks );

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

metis_ajax_register_handler( 'metis_website_popup_delete', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.delete' );

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
// Templates
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_website_templates_list', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $type = isset( $_POST['template_type'] ) ? sanitize_key( (string) $_POST['template_type'] ) : '';
    $items = TemplateService::getAll( $type );
    metis_runtime_send_json_success( [
        'templates' => array_map( static fn ( $template ) => $template->toArray(), $items ),
    ] );
} );

metis_ajax_register_handler( 'metis_website_template_get', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $template_key = isset( $_POST['template_key'] ) ? sanitize_key( (string) $_POST['template_key'] ) : '';
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

    metis_runtime_send_json_success( [ 'template' => $template->toArray() ] );
} );

metis_ajax_register_handler( 'metis_website_template_save', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    $data = [
        'template_key' => isset( $_POST['template_key'] ) ? sanitize_key( (string) $_POST['template_key'] ) : '',
        'name' => isset( $_POST['name'] ) ? sanitize_text_field( metis_runtime_unslash( $_POST['name'] ) ) : '',
        'template_type' => isset( $_POST['template_type'] ) ? sanitize_key( (string) $_POST['template_type'] ) : 'page',
        'status' => isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : 'draft',
        'structure_json' => isset( $_POST['structure_json'] ) ? metis_runtime_unslash( $_POST['structure_json'] ) : '{}',
        'is_default' => ! empty( $_POST['is_default'] ) ? 1 : 0,
    ];
    $template_context = 'template';
    $autosave = ! empty( $_POST['autosave'] );
    $structure_blocks = metis_website_ajax_blocks_from_template_structure_json( $data['structure_json'] );
    metis_website_ajax_assert_blocks_valid_for_status( $structure_blocks, $template_context, 'standard', (string) ( $data['status'] ?? 'draft' ), $autosave );
    if (
        in_array( (string) ( $data['status'] ?? 'draft' ), [ 'published', 'scheduled' ], true )
        && ! $autosave
        && $template_context !== 'template'
    ) {
        metis_website_ajax_assert_accessibility_valid( $structure_blocks );
    }

    if ( trim( (string) $data['name'] ) === '' ) {
        metis_runtime_send_json_error( 'Template name is required.', 422 );
    }

    $saved_id = 0;
    if ( $id > 0 ) {
        $ok = TemplateService::update( $id, $data );
        if ( ! $ok && ! empty( $data['template_key'] ) ) {
            $existing_by_key = TemplateService::getByTemplateKey( (string) $data['template_key'] );
            if ( $existing_by_key !== null && (int) ( $existing_by_key->id ?? 0 ) > 0 ) {
                $id = (int) $existing_by_key->id;
                $ok = TemplateService::update( $id, $data );
            }
        }
        $saved_id = $ok ? $id : 0;
    } else {
        $existing_by_key = ! empty( $data['template_key'] ) ? TemplateService::getByTemplateKey( (string) $data['template_key'] ) : null;
        if ( $existing_by_key !== null && (int) ( $existing_by_key->id ?? 0 ) > 0 ) {
            $saved_id = (int) $existing_by_key->id;
            $ok = TemplateService::update( $saved_id, $data );
        } else {
            $created = TemplateService::create( $data );
            $ok = $created !== false;
            $saved_id = $ok ? (int) $created : 0;
        }
    }

    if ( ! $ok ) {
        if ( function_exists( 'metis_log' ) ) {
            metis_log( 'Template save failed', [
                'handler' => 'metis_website_template_save',
                'id' => $id,
                'template_key' => (string) ( $data['template_key'] ?? '' ),
                'template_type' => (string) ( $data['template_type'] ?? '' ),
                'status' => (string) ( $data['status'] ?? '' ),
            ] );
        }
        metis_runtime_send_json_error( 'Failed to save template.', 500 );
    }

    $autosave = ! empty( $_POST['autosave'] );
    $revision_note = isset( $_POST['revision_note'] )
        ? sanitize_text_field( (string) metis_runtime_unslash( $_POST['revision_note'] ) )
        : ( $autosave ? 'Autosave' : 'Template saved' );
    if ( $saved_id > 0 ) {
        RevisionTimelineService::save(
            'template',
            $saved_id,
            [
                'name' => (string) ( $data['name'] ?? '' ),
                'template_key' => (string) ( $data['template_key'] ?? '' ),
                'template_type' => (string) ( $data['template_type'] ?? '' ),
                'status' => (string) ( $data['status'] ?? 'draft' ),
                'structure_json' => (string) ( $data['structure_json'] ?? '{}' ),
                'is_default' => ! empty( $data['is_default'] ) ? 1 : 0,
                'autosave' => $autosave,
            ],
            $revision_note
        );
    }

    $saved_template = $saved_id > 0 ? TemplateService::getById( $saved_id ) : null;
    if ( $saved_template === null && ! empty( $data['template_key'] ) ) {
        $saved_template = TemplateService::getByTemplateKey( (string) $data['template_key'] );
    }

    $response = [ 'message' => 'Template saved.' ];
    if ( $saved_template !== null ) {
        $response['template'] = $saved_template->toArray();
    }
    metis_runtime_send_json_success( $response );
} );

metis_ajax_register_handler( 'metis_website_template_delete', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.delete' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
    if ( $id < 1 ) {
        metis_runtime_send_json_error( 'Template ID is required.', 400 );
    }

    $ok = TemplateService::delete( $id );
    if ( ! $ok ) {
        metis_runtime_send_json_error( 'Failed to delete template.', 500 );
    }

    metis_runtime_send_json_success( [ 'message' => 'Template deleted.' ] );
} );

// ---------------------------------------------------------------------------
// Theme
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_website_theme_get', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $theme = ThemeService::getActive();
    metis_runtime_send_json_success( [ 'theme' => $theme ] );
} );

metis_ajax_register_handler( 'metis_website_theme_save', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

    $data = [
        'global_styles_json' => isset( $_POST['global_styles_json'] ) ? metis_runtime_unslash( $_POST['global_styles_json'] ) : null,
        'typography_json'    => isset( $_POST['typography_json'] ) ? metis_runtime_unslash( $_POST['typography_json'] ) : null,
        'color_palette_json' => isset( $_POST['color_palette_json'] ) ? metis_runtime_unslash( $_POST['color_palette_json'] ) : null,
        'spacing_json'       => isset( $_POST['spacing_json'] ) ? metis_runtime_unslash( $_POST['spacing_json'] ) : null,
        'custom_tokens_json' => isset( $_POST['custom_tokens_json'] ) ? metis_runtime_unslash( $_POST['custom_tokens_json'] ) : null,
    ];

    if ( $id > 0 ) {
        $ok = ThemeService::update( $id, $data );
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

    metis_runtime_send_json_success( [ 'message' => 'Theme saved.' ] );
} );

// ---------------------------------------------------------------------------
// Block registry
// ---------------------------------------------------------------------------

metis_ajax_register_handler( 'metis_website_blocks_list', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $requested_context = isset( $_POST['context'] ) ? sanitize_key( (string) $_POST['context'] ) : 'website';
    $requested_render_mode = isset( $_POST['render_mode'] ) ? sanitize_key( (string) $_POST['render_mode'] ) : '';
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

metis_ajax_register_handler( 'metis_website_reusable_blocks_list', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $requested_context = isset( $_POST['context'] ) ? sanitize_key( (string) $_POST['context'] ) : 'website';
    $requested_render_mode = isset( $_POST['render_mode'] ) ? sanitize_key( (string) $_POST['render_mode'] ) : '';
    $search = isset( $_POST['search'] ) ? sanitize_text_field( (string) metis_runtime_unslash( $_POST['search'] ) ) : '';

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

metis_ajax_register_handler( 'metis_website_reusable_block_get', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

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

metis_ajax_register_handler( 'metis_website_reusable_block_save', function (): void {
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.edit' );

    $name = isset( $_POST['name'] ) ? sanitize_text_field( (string) metis_runtime_unslash( $_POST['name'] ) ) : '';
    $category = isset( $_POST['category'] ) ? sanitize_key( (string) metis_runtime_unslash( $_POST['category'] ) ) : 'custom';
    $context = isset( $_POST['context'] ) ? sanitize_key( (string) metis_runtime_unslash( $_POST['context'] ) ) : 'website';
    $render_mode = isset( $_POST['render_mode'] ) ? sanitize_key( (string) metis_runtime_unslash( $_POST['render_mode'] ) ) : '';
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
    metis_website_ajax_assert_blocks_valid( [ $decoded ], $normalized_context, $normalized_mode );
    metis_website_ajax_assert_accessibility_valid( [ $decoded ] );

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
    metis_website_ajax_verify_nonce();
    metis_website_ajax_require_permission( 'website.view' );

    $db = metis_get_db();
    $table = function_exists('metis_tables_get') ? metis_tables_get('donation_campaigns') : 'mw_donation_campaigns';
    $rows = $db->fetchAll( "SELECT cid AS id, cname AS name FROM {$table} WHERE active = 1 ORDER BY cname ASC" );
    metis_runtime_send_json_success( [ 'campaigns' => $rows ?: [] ] );
} );

\Metis_Logger::info( 'Website AJAX handlers loaded' );
