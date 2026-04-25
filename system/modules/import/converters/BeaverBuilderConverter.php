<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Converters;

/**
 * Beaver Builder → Metis Block Converter
 *
 * Maps Beaver Builder rows/modules to Metis sections/blocks.
 * Directive Section 19-20: explicit mapping table required.
 *
 * Conversion target: ~80-90% structured conversion.
 * Unsupported modules produce conversion warnings, not silent drops.
 */
final class BeaverBuilderConverter {

    /** @var array{converted:int, fallbacks:int, warnings:string[]} */
    private static array $report = [
        'converted' => 0,
        'fallbacks' => 0,
        'warnings'  => [],
    ];

    /**
     * Convert serialized Beaver Builder data to Metis layout JSON.
     *
     * @param  string $bb_data  Serialized BB _fl_builder_data meta value
     * @return array{layout: array, report: array}
     */
    public static function convert( string $bb_data ): array {
        self::$report = [ 'converted' => 0, 'fallbacks' => 0, 'warnings' => [] ];

        // BB data is PHP-serialized
        $nodes = @unserialize( $bb_data, [ 'allowed_classes' => false ] );

        if ( ! is_array( $nodes ) ) {
            self::$report['warnings'][] = 'Could not deserialize Beaver Builder data.';
            return [ 'layout' => self::emptyLayout(), 'report' => self::$report ];
        }

        // Separate node types
        $rows    = [];
        $columns = [];
        $modules = [];

        foreach ( $nodes as $id => $node ) {
            $node = (array) $node;
            $type = (string) ( $node['type'] ?? '' );
            switch ( $type ) {
                case 'row':    $rows[ $id ]    = $node; break;
                case 'column': $columns[ $id ] = $node; break;
                case 'module': $modules[ $id ] = $node; break;
            }
        }

        // Sort rows by position
        uasort( $rows, fn( $a, $b ) => ( (int) ( $a['position'] ?? 0 ) ) <=> ( (int) ( $b['position'] ?? 0 ) ) );

        $sections = [];

        foreach ( $rows as $row_id => $row ) {
            $section = self::convertRow( $row_id, $row, $columns, $modules );
            if ( $section !== null ) {
                $sections[] = $section;
            }
        }

        return [
            'layout' => [ 'version' => 1, 'sections' => $sections ],
            'report' => self::$report,
        ];
    }

    // -----------------------------------------------------------------------
    // Row → Container section
    // -----------------------------------------------------------------------

    private static function convertRow(
        string $row_id,
        array  $row,
        array  $all_columns,
        array  $all_modules
    ): ?array {
        // Collect columns belonging to this row
        $row_cols = array_filter( $all_columns, fn( $c ) => (string) ( $c['parent'] ?? '' ) === $row_id );
        uasort( $row_cols, fn( $a, $b ) => ( (int) ( $a['position'] ?? 0 ) ) <=> ( (int) ( $b['position'] ?? 0 ) ) );

        if ( empty( $row_cols ) ) {
            return null;
        }

        $col_count = count( $row_cols );
        $blocks    = [];

        if ( $col_count === 1 ) {
            // Single column → container, put its blocks directly
            $col = array_values( $row_cols )[0];
            $col_id = array_key_first( $row_cols );
            $blocks = self::collectModuleBlocks( $col_id, $all_modules );
        } else {
            // Multiple columns → grid block
            $col_blocks = [];
            foreach ( $row_cols as $col_id => $col ) {
                $col_blocks[] = self::collectModuleBlocks( $col_id, $all_modules );
            }
            $blocks[] = [
                'id'   => 'bb_grid_' . $row_id,
                'type' => 'grid',
                'data' => [
                    'columns'    => $col_count,
                    'gap'        => '24px',
                    'col_blocks' => $col_blocks,
                ],
                'style' => [],
            ];
            self::$report['converted']++;
        }

        // Row background/style
        $style = self::extractRowStyle( $row );

        return [
            'id'     => 'bb_row_' . $row_id,
            'type'   => 'container',
            'blocks' => $blocks,
            'style'  => $style,
        ];
    }

    // -----------------------------------------------------------------------
    // Module blocks collection
    // -----------------------------------------------------------------------

    private static function collectModuleBlocks( string $col_id, array $all_modules ): array {
        $col_modules = array_filter( $all_modules, fn( $m ) => (string) ( $m['parent'] ?? '' ) === $col_id );
        uasort( $col_modules, fn( $a, $b ) => ( (int) ( $a['position'] ?? 0 ) ) <=> ( (int) ( $b['position'] ?? 0 ) ) );

        $blocks = [];
        foreach ( $col_modules as $mod_id => $mod ) {
            $block = self::convertModule( $mod_id, $mod );
            if ( $block !== null ) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

    // -----------------------------------------------------------------------
    // Module → Block mapping (Section 20)
    // -----------------------------------------------------------------------

    private static function convertModule( string $id, array $mod ): ?array {
        $type     = (string) ( $mod['settings']->type ?? $mod['type'] ?? '' );
        $settings = (array) ( $mod['settings'] ?? [] );
        $prefix   = 'bb_mod_';

        switch ( $type ) {

            // Heading module → heading block
            case 'heading':
                self::$report['converted']++;
                return [
                    'id'   => $prefix . $id,
                    'type' => 'heading',
                    'data' => [
                        'content' => '<' . ( $settings['tag'] ?? 'h2' ) . '>' . ( $settings['heading'] ?? '' ) . '</' . ( $settings['tag'] ?? 'h2' ) . '>',
                        'level'   => $settings['tag'] ?? 'h2',
                        'align'   => $settings['align'] ?? 'left',
                    ],
                    'style' => self::extractTypographyStyle( $settings ),
                ];

            // Text Editor module → text block
            case 'rich-text':
            case 'text-editor':
                self::$report['converted']++;
                return [
                    'id'   => $prefix . $id,
                    'type' => 'text',
                    'data' => [
                        'content' => $settings['text'] ?? $settings['content'] ?? '',
                        'tag'     => 'div',
                        'align'   => $settings['align'] ?? 'left',
                    ],
                    'style' => self::extractTypographyStyle( $settings ),
                ];

            // Photo module → image block
            case 'photo':
                self::$report['converted']++;
                return [
                    'id'   => $prefix . $id,
                    'type' => 'image',
                    'data' => [
                        'src'   => $settings['photo_src'] ?? $settings['src'] ?? '',
                        'alt'   => $settings['caption'] ?? '',
                        'link'  => $settings['link_url'] ?? '',
                        'width' => ( $settings['photo_max_width'] ?? '' ) !== '' ? $settings['photo_max_width'] . 'px' : '100%',
                    ],
                    'style' => [],
                ];

            // Button module → button block
            case 'button':
                self::$report['converted']++;
                return [
                    'id'   => $prefix . $id,
                    'type' => 'button',
                    'data' => [
                        'label'   => $settings['text'] ?? 'Button',
                        'url'     => $settings['link'] ?? '#',
                        'bgcolor' => $settings['bg_color'] ?? '#485bc7',
                        'color'   => $settings['text_color'] ?? '#ffffff',
                        'size'    => $settings['width'] ?? 'medium',
                    ],
                    'style' => self::extractSpacingStyle( $settings ),
                ];

            // HTML module → restricted html block
            case 'html':
                self::$report['converted']++;
                // Strip scripts per security rules
                $content = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $settings['html'] ?? '' ) ?? '';
                return [
                    'id'   => $prefix . $id,
                    'type' => 'html',
                    'data' => [ 'content' => $content ],
                    'style' => [],
                ];

            // Separator/Divider → divider block
            case 'separator':
            case 'divider':
                self::$report['converted']++;
                return [
                    'id'   => $prefix . $id,
                    'type' => 'divider',
                    'data' => [
                        'color'  => $settings['color'] ?? '#e2e6ea',
                        'height' => (int) ( $settings['height'] ?? 1 ),
                        'style'  => $settings['style'] ?? 'solid',
                    ],
                    'style' => self::extractSpacingStyle( $settings ),
                ];

            // Spacer → spacer block
            case 'spacer':
                self::$report['converted']++;
                return [
                    'id'   => $prefix . $id,
                    'type' => 'spacer',
                    'data' => [ 'height' => (int) ( $settings['height'] ?? 24 ) ],
                    'style' => [],
                ];

            // Menu module → menu block
            case 'menu':
                self::$report['converted']++;
                return [
                    'id'   => $prefix . $id,
                    'type' => 'menu',
                    'data' => [
                        'menu_id'     => null, // cannot map WP menu IDs to Metis IDs here
                        'orientation' => 'horizontal',
                    ],
                    'style' => [],
                ];

            // Icon module → icon block
            case 'icon':
                self::$report['converted']++;
                return [
                    'id'   => $prefix . $id,
                    'type' => 'icon',
                    'data' => [
                        'icon_svg' => '', // BB uses icon font names, SVG not directly available
                        'size'     => ( $settings['size'] ?? '24' ) . 'px',
                        'color'    => $settings['color'] ?? '#000000',
                        'link'     => $settings['link'] ?? '',
                    ],
                    'style' => self::extractSpacingStyle( $settings ),
                ];

            // Video module → video block
            case 'video':
                self::$report['converted']++;
                $url      = $settings['video_url'] ?? $settings['url'] ?? '';
                $provider = str_contains( $url, 'youtube' ) ? 'youtube' : ( str_contains( $url, 'vimeo' ) ? 'vimeo' : 'local' );
                return [
                    'id'   => $prefix . $id,
                    'type' => 'video',
                    'data' => [ 'url' => $url, 'provider' => $provider, 'aspect_ratio' => '16:9' ],
                    'style' => self::extractSpacingStyle( $settings ),
                ];

            // Posts module → post_list block
            case 'posts':
                self::$report['converted']++;
                return [
                    'id'   => $prefix . $id,
                    'type' => 'post_list',
                    'data' => [
                        'count'        => (int) ( $settings['posts_per_page'] ?? 5 ),
                        'layout'       => $settings['layout'] ?? 'list',
                        'show_excerpt' => true,
                        'show_date'    => true,
                    ],
                    'style' => [],
                ];

            // Callout/CTA → container with nested text + button
            case 'callout':
                self::$report['converted']++;
                $inner = [];
                if ( ! empty( $settings['title'] ) ) {
                    $inner[] = [ 'id' => $prefix . $id . '_h', 'type' => 'heading', 'data' => [ 'content' => '<h3>' . $settings['title'] . '</h3>', 'level' => 'h3', 'align' => 'left' ], 'style' => [] ];
                }
                if ( ! empty( $settings['text'] ) ) {
                    $inner[] = [ 'id' => $prefix . $id . '_t', 'type' => 'text', 'data' => [ 'content' => $settings['text'], 'tag' => 'p', 'align' => 'left' ], 'style' => [] ];
                }
                if ( ! empty( $settings['cta_text'] ) ) {
                    $inner[] = [ 'id' => $prefix . $id . '_b', 'type' => 'button', 'data' => [ 'label' => $settings['cta_text'], 'url' => $settings['cta_link'] ?? '#', 'bgcolor' => '#485bc7', 'color' => '#fff', 'size' => 'medium' ], 'style' => [] ];
                }
                return [
                    'id'   => $prefix . $id,
                    'type' => 'container',
                    'data' => [ 'blocks' => $inner, 'max_width' => '800px', 'align' => 'center' ],
                    'style' => self::extractSpacingStyle( $settings ),
                ];

            // Accordion/Tabs → fallback container with warning note
            case 'accordion':
            case 'tabs':
                self::$report['fallbacks']++;
                self::$report['warnings'][] = "Module type '{$type}' (ID: {$id}) — no direct Metis equivalent. Converted to HTML fallback block for manual review.";
                return [
                    'id'   => $prefix . $id,
                    'type' => 'html',
                    'data' => [ 'content' => '<!-- BB ' . metis_escape_html( $type ) . ' module — please rebuild in Metis block editor -->' ],
                    'style' => [],
                ];

            // Form module → placeholder html block (never silently dropped)
            case 'contact-form':
            case 'wpforms':
            case 'gravity-form':
            case 'form':
                self::$report['fallbacks']++;
                self::$report['warnings'][] = "Form module (ID: {$id}, type: {$type}) — forms must be recreated using the Metis Forms module.";
                return [
                    'id'   => $prefix . $id,
                    'type' => 'html',
                    'data' => [ 'content' => '<!-- Form module from Beaver Builder — recreate using Metis Forms module -->' ],
                    'style' => [],
                ];

            // Anything unknown → html fallback, never silently dropped
            default:
                self::$report['fallbacks']++;
                self::$report['warnings'][] = "Unsupported module type '{$type}' (ID: {$id}) — converted to HTML fallback block.";
                $fallback_content = $settings['text'] ?? $settings['content'] ?? $settings['html'] ?? '';
                return [
                    'id'   => $prefix . $id,
                    'type' => 'html',
                    'data' => [ 'content' => $fallback_content ? metis_sanitize_html( (string) $fallback_content ) : '<!-- BB module: ' . metis_escape_html( $type ) . ' -->' ],
                    'style' => [],
                ];
        }
    }

    // -----------------------------------------------------------------------
    // Style extraction helpers
    // -----------------------------------------------------------------------

    private static function extractRowStyle( array $row ): array {
        $settings = (array) ( $row['settings'] ?? [] );
        $style    = [];

        if ( ! empty( $settings['bg_color'] ) ) {
            $style['color']['background'] = '#' . ltrim( $settings['bg_color'], '#' );
        }
        if ( ! empty( $settings['padding_top'] ) || ! empty( $settings['padding_bottom'] ) ) {
            $t = (int) ( $settings['padding_top'] ?? 0 );
            $b = (int) ( $settings['padding_bottom'] ?? 0 );
            $style['spacing']['padding'] = "{$t}px 0 {$b}px 0";
        }

        return $style;
    }

    private static function extractTypographyStyle( array $settings ): array {
        $style = [];
        if ( ! empty( $settings['text_color'] ) ) {
            $style['color']['text'] = '#' . ltrim( $settings['text_color'], '#' );
        }
        if ( ! empty( $settings['padding_top'] ) ) {
            $style['spacing']['padding'] = ( (int) $settings['padding_top'] ) . 'px 0';
        }
        return $style;
    }

    private static function extractSpacingStyle( array $settings ): array {
        $parts = [];
        foreach ( ['top','bottom','left','right'] as $side ) {
            if ( ! empty( $settings['margin_' . $side] ) ) {
                $parts[] = 'margin-' . $side . ':' . (int) $settings['margin_' . $side] . 'px';
            }
        }
        return $parts ? [ 'spacing' => [ 'margin' => implode( ';', $parts ) ] ] : [];
    }

    private static function emptyLayout(): array {
        return [ 'version' => 1, 'sections' => [] ];
    }
}
