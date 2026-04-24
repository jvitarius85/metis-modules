<?php
declare(strict_types=1);

namespace Metis\Core\Editor;

use Metis\Core\Editor\BlockRegistry;

final class EditorContextPolicy {
    private const CONTEXTS = [ 'website', 'post', 'template', 'web_part', 'newsletter', 'email' ];
    private const RENDER_MODES = [ 'standard', 'email_safe' ];
    private const EMAIL_UNSAFE_STYLE_KEYS = [
        'animation',
        'transform',
        'position',
        'z_index',
        'grid_x',
        'grid_y',
        'grid_w',
        'grid_h',
        'grid_cols',
        'scrollable',
    ];
    /** @var array<string,array{label:string,sample:string,contexts:array<int,string>}> */
    private const DYNAMIC_TOKEN_DEFINITIONS = [
        'site.name' => [ 'label' => 'Site Name', 'sample' => 'Metis Demo Site', 'contexts' => [ 'website', 'post', 'template', 'web_part' ] ],
        'current.date' => [ 'label' => 'Current Date', 'sample' => '2026-03-23', 'contexts' => [ 'website', 'post', 'template', 'web_part' ] ],
        'page.title' => [ 'label' => 'Page Title', 'sample' => 'Sample Page Title', 'contexts' => [ 'website', 'template', 'web_part' ] ],
        'page.slug' => [ 'label' => 'Page Slug', 'sample' => 'sample-page', 'contexts' => [ 'website', 'template', 'web_part' ] ],
        'page.excerpt' => [ 'label' => 'Page Excerpt', 'sample' => 'Sample page excerpt.', 'contexts' => [ 'website', 'template', 'web_part' ] ],
        'post.title' => [ 'label' => 'Post Title', 'sample' => 'Sample Post Title', 'contexts' => [ 'post', 'template', 'web_part' ] ],
        'post.excerpt' => [ 'label' => 'Post Excerpt', 'sample' => 'Sample post excerpt.', 'contexts' => [ 'post', 'template', 'web_part' ] ],
        'post.author_name' => [ 'label' => 'Post Author', 'sample' => 'Sample Author', 'contexts' => [ 'post', 'template', 'web_part' ] ],
        'donation.name' => [ 'label' => 'Donation Campaign Name', 'sample' => 'Community Campaign', 'contexts' => [ 'website', 'post', 'template', 'web_part' ] ],
        'donation.description' => [ 'label' => 'Donation Description', 'sample' => 'No data available', 'contexts' => [ 'website', 'post', 'template', 'web_part' ] ],
        'donation.goal' => [ 'label' => 'Donation Goal', 'sample' => '$10,000.00', 'contexts' => [ 'website', 'post', 'template', 'web_part' ] ],
        'donation.raised' => [ 'label' => 'Donation Raised', 'sample' => '$2,500.00', 'contexts' => [ 'website', 'post', 'template', 'web_part' ] ],
        'donation.percent' => [ 'label' => 'Donation Percent', 'sample' => '25.00', 'contexts' => [ 'website', 'post', 'template', 'web_part' ] ],
    ];

    /** @var array<string,array<string,mixed>> */
    private static array $profile_cache = [];

    public static function normalizeContext( string $context ): string {
        $normalized = metis_key_clean( $context );
        return in_array( $normalized, self::CONTEXTS, true ) ? $normalized : 'website';
    }

    public static function normalizeRenderMode( string $render_mode, string $context = 'website' ): string {
        $normalized_context = self::normalizeContext( $context );
        $normalized_mode = metis_key_clean( $render_mode );
        if ( in_array( $normalized_mode, self::RENDER_MODES, true ) ) {
            return $normalized_mode;
        }
        if ( in_array( $normalized_context, [ 'newsletter', 'email' ], true ) ) {
            return 'email_safe';
        }
        return 'standard';
    }

    public static function profile( string $context, string $render_mode = '' ): array {
        $context = self::normalizeContext( $context );
        $render_mode = self::normalizeRenderMode( $render_mode, $context );
        $cache_key = $context . '|' . $render_mode;
        if ( isset( self::$profile_cache[ $cache_key ] ) ) {
            return self::$profile_cache[ $cache_key ];
        }

        $allow_dynamic = ! in_array( $context, [ 'newsletter', 'email' ], true );
        $allow_advanced_layout = ! in_array( $context, [ 'newsletter', 'email' ], true );
        $allowed_style_keys = [ 'spacing', 'color', 'typography', 'width', 'max_width', 'min_height', 'border', 'border_radius', 'box_shadow', 'text_align', 'align' ];
        if ( $render_mode === 'standard' ) {
            $allowed_style_keys = array_merge(
                $allowed_style_keys,
                [ 'height', 'max_height', 'display', 'justify_content', 'align_items', 'gap', 'grid_x', 'grid_y', 'grid_w', 'grid_h', 'grid_cols', 'scrollable' ]
            );
        }

        $profile = [
            'context' => $context,
            'render_mode' => $render_mode,
            'allow_dynamic' => $allow_dynamic,
            'allow_advanced_layout' => $allow_advanced_layout,
            'allowed_style_keys' => $allowed_style_keys,
            'disallowed_style_keys' => $render_mode === 'email_safe' ? self::EMAIL_UNSAFE_STYLE_KEYS : [],
            'allowed_dynamic_tokens' => array_keys( self::dynamicTokenCatalogForContext( $context, $allow_dynamic ) ),
            'dynamic_tokens' => self::dynamicTokenCatalogForContext( $context, $allow_dynamic ),
        ];

        self::$profile_cache[ $cache_key ] = $profile;
        return $profile;
    }

    /**
     * @param array<string,array<string,mixed>> $definitions
     * @return array<string,array<string,mixed>>
     */
    public static function filterRegistry( array $definitions, string $context, string $render_mode = '' ): array {
        $context = self::normalizeContext( $context );
        $render_mode = self::normalizeRenderMode( $render_mode, $context );
        $filtered = [];
        foreach ( $definitions as $type => $definition ) {
            if ( ! is_array( $definition ) ) {
                continue;
            }
            if ( ! self::isBlockAllowed( (string) $type, $definition, $context, $render_mode ) ) {
                continue;
            }
            $filtered[ (string) $type ] = $definition;
        }
        return $filtered;
    }

    /**
     * @param array<int,mixed> $block_list
     * @return array{valid:bool,errors:array<int,array<string,mixed>>}
     */
    public static function validateBlocks( array $block_list, string $context, string $render_mode = '' ): array {
        $context = self::normalizeContext( $context );
        $render_mode = self::normalizeRenderMode( $render_mode, $context );
        $errors = [];

        self::validateBlockList( $block_list, $context, $render_mode, 'root', $errors );

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $definition
     */
    public static function isBlockAllowed( string $type, array $definition, string $context, string $render_mode = '' ): bool {
        $context = self::normalizeContext( $context );
        $render_mode = self::normalizeRenderMode( $render_mode, $context );

        if ( $render_mode === 'email_safe' ) {
            return self::supportsEmailSafe( $type, $definition );
        }

        $contexts = isset( $definition['contexts'] ) && is_array( $definition['contexts'] ) ? $definition['contexts'] : [];
        if ( $contexts !== [] && ! in_array( $context, array_map( 'strval', $contexts ), true ) ) {
            return false;
        }

        if ( in_array( $context, [ 'newsletter', 'email' ], true ) ) {
            return self::supportsEmailSafe( $type, $definition );
        }

        return true;
    }

    /**
     * @param array<string,mixed> $style
     * @return array<string,mixed>
     */
    public static function sanitizeStyleForRenderMode( array $style, string $render_mode ): array {
        $render_mode = self::normalizeRenderMode( $render_mode );
        if ( $render_mode !== 'email_safe' ) {
            return $style;
        }
        foreach ( self::EMAIL_UNSAFE_STYLE_KEYS as $unsafe_key ) {
            unset( $style[ $unsafe_key ] );
        }
        return $style;
    }

    /**
     * @param array<int,mixed> $block_list
     * @param array<int,array<string,mixed>> $errors
     */
    private static function validateBlockList( array $block_list, string $context, string $render_mode, string $path, array &$errors ): void {
        foreach ( $block_list as $index => $block ) {
            $current_path = $path . '.' . (int) $index;
            if ( ! is_array( $block ) ) {
                $errors[] = [
                    'path' => $current_path,
                    'message' => 'Invalid block payload.',
                ];
                continue;
            }

            $type = isset( $block['type'] ) && is_scalar( $block['type'] ) ? metis_key_clean( (string) $block['type'] ) : '';
            if ( $type === '' ) {
                $errors[] = [
                    'path' => $current_path,
                    'message' => 'Block type is required.',
                ];
                continue;
            }

            $definition = BlockRegistry::get( $type );
            if ( $definition === null ) {
                $errors[] = [
                    'path' => $current_path,
                    'message' => 'Unsupported block: ' . $type . '.',
                ];
                continue;
            }
            if ( ! self::isBlockAllowed( $type, $definition, $context, $render_mode ) ) {
                $errors[] = [
                    'path' => $current_path,
                    'message' => 'Block "' . $type . '" is not allowed for this editor context.',
                ];
            }

            $schema_validation = BlockRegistry::validateBlock( $block );
            if ( ! (bool) ( $schema_validation['valid'] ?? false ) ) {
                $errors[] = [
                    'path' => $current_path,
                    'message' => 'Block "' . $type . '" is missing required settings.',
                    'details' => $schema_validation['errors'] ?? [],
                ];
            }

            if ( $render_mode === 'email_safe' ) {
                $style = isset( $block['style'] ) && is_array( $block['style'] ) ? $block['style'] : [];
                $disallowed = array_values( array_intersect( array_keys( $style ), self::EMAIL_UNSAFE_STYLE_KEYS ) );
                if ( $disallowed !== [] ) {
                    $errors[] = [
                        'path' => $current_path,
                        'message' => 'Some styling is not allowed in email-safe mode.',
                        'details' => $disallowed,
                    ];
                }
            }

            $data = isset( $block['data'] ) && is_array( $block['data'] ) ? $block['data'] : [];
            $token_issues = self::validateDynamicTokensInData( $data, $context, $render_mode );
            foreach ( $token_issues as $token_issue ) {
                $errors[] = [
                    'path' => $current_path,
                    'message' => (string) $token_issue,
                ];
            }
            $html_issues = self::validateHtmlBlockContent( $type, $data );
            foreach ( $html_issues as $html_issue ) {
                $errors[] = [
                    'path' => $current_path,
                    'message' => (string) $html_issue,
                ];
            }
            if ( isset( $data['blocks'] ) && is_array( $data['blocks'] ) ) {
                self::validateBlockList( $data['blocks'], $context, $render_mode, $current_path . '.blocks', $errors );
            }
            if ( isset( $data['col_blocks'] ) && is_array( $data['col_blocks'] ) ) {
                foreach ( $data['col_blocks'] as $col_index => $col_blocks ) {
                    if ( is_array( $col_blocks ) ) {
                        self::validateBlockList( $col_blocks, $context, $render_mode, $current_path . '.col_blocks.' . (int) $col_index, $errors );
                    }
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,string>
     */
    private static function validateHtmlBlockContent( string $type, array $data ): array {
        if ( ! in_array( $type, [ 'html', 'html_embed' ], true ) ) {
            return [];
        }
        $content = isset( $data['content'] ) ? (string) $data['content'] : '';
        if ( $content === '' ) {
            return [];
        }
        $blocked_patterns = [
            '/<script[\s>]/i',
            '/<iframe[\s>]/i',
            '/<object[\s>]/i',
            '/<embed[\s>]/i',
            '/<style[\s>]/i',
            '/<link[\s>]/i',
            '/on[a-z]+\s*=/i',
            '/javascript\s*:/i',
            '/data\s*:\s*text\/html/i',
            '/expression\s*\(/i',
        ];
        foreach ( $blocked_patterns as $pattern ) {
            if ( preg_match( $pattern, $content ) ) {
                return [ 'HTML block contains unsafe markup and cannot be saved.' ];
            }
        }
        return [];
    }

    /**
     * @param array<string,mixed> $definition
     */
    private static function supportsEmailSafe( string $type, array $definition ): bool {
        $render_support = isset( $definition['render_support'] ) && is_array( $definition['render_support'] )
            ? $definition['render_support']
            : [];
        if ( array_key_exists( 'email_safe', $render_support ) ) {
            return ! empty( $render_support['email_safe'] );
        }

        $category = (string) ( $definition['category'] ?? '' );
        if ( in_array( $category, [ 'dynamic', 'advanced', 'donations', 'navigation' ], true ) ) {
            return false;
        }

        $behavior_support = isset( $definition['behavior_support'] ) && is_array( $definition['behavior_support'] )
            ? $definition['behavior_support']
            : [];
        if ( ! empty( $behavior_support['animation'] ) || ! empty( $behavior_support['modal_trigger'] ) ) {
            return false;
        }

        if ( $type === 'video' || $type === 'video_block' ) {
            return false;
        }

        return in_array( $category, [ 'content', 'layout', 'media', 'interactive' ], true );
    }

    /**
     * @return array<string,array{label:string,sample:string}>
     */
    private static function dynamicTokenCatalogForContext( string $context, bool $allow_dynamic ): array {
        if ( ! $allow_dynamic ) {
            return [];
        }
        $catalog = [];
        foreach ( self::DYNAMIC_TOKEN_DEFINITIONS as $token => $meta ) {
            $contexts = array_map( 'strval', $meta['contexts'] ?? [] );
            if ( $contexts !== [] && ! in_array( $context, $contexts, true ) ) {
                continue;
            }
            $catalog[ (string) $token ] = [
                'label' => (string) ( $meta['label'] ?? $token ),
                'sample' => (string) ( $meta['sample'] ?? '' ),
            ];
        }
        return $catalog;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,string>
     */
    private static function validateDynamicTokensInData( array $data, string $context, string $render_mode ): array {
        $profile = self::profile( $context, $render_mode );
        $allow_dynamic = ! empty( $profile['allow_dynamic'] );
        $allowed = isset( $profile['allowed_dynamic_tokens'] ) && is_array( $profile['allowed_dynamic_tokens'] )
            ? array_map( static fn( $v ): string => strtolower( (string) $v ), $profile['allowed_dynamic_tokens'] )
            : [];
        $allowed_lookup = array_fill_keys( $allowed, true );

        $found_tokens = [];
        self::collectDynamicTokensFromValue( $data, $found_tokens );
        if ( $found_tokens === [] ) {
            return [];
        }
        if ( ! $allow_dynamic ) {
            return [ 'Dynamic content is not available in this editor context.' ];
        }

        $issues = [];
        foreach ( array_keys( $found_tokens ) as $token ) {
            if ( ! isset( $allowed_lookup[ $token ] ) ) {
                $issues[] = 'Dynamic token "' . $token . '" is not allowed in this context.';
            }
        }
        return $issues;
    }

    /**
     * @param mixed $value
     * @param array<string,bool> $tokens
     */
    private static function collectDynamicTokensFromValue( $value, array &$tokens ): void {
        if ( is_string( $value ) ) {
            if ( preg_match_all( '/\[metis:([a-z0-9_.-]+)\]/i', $value, $matches ) ) {
                foreach ( $matches[1] as $token ) {
                    $normalized = strtolower( metis_key_clean( str_replace( '.', '_', (string) $token ) ) );
                    $source = strtolower( (string) $token );
                    if ( $source !== '' && $normalized !== '' ) {
                        $tokens[ $source ] = true;
                    }
                }
            }
            return;
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                self::collectDynamicTokensFromValue( $item, $tokens );
            }
            return;
        }
        if ( is_object( $value ) ) {
            foreach ( get_object_vars( $value ) as $item ) {
                self::collectDynamicTokensFromValue( $item, $tokens );
            }
        }
    }
}
