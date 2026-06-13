<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Modules\Website\BlockRegistry;
use Metis\Modules\Website\Services\MenuService;
use Metis\Modules\Website\Services\PostService;
use Metis\Modules\Website\Services\EditorContextPolicy;

/**
 * Block Renderer Service
 *
 * Renders blocks to clean frontend HTML.
 * All blocks render through this centralized service.
 */
final class BlockRenderer {
    private static int $testimonyRotatorSequence = 0;

    public static function render( array $block, array $context = [] ): string {
        $block = self::normalizeIncomingBlock( $block );
        if ( empty( $block['type'] ) ) {
            return '';
        }
        $render_mode = EditorContextPolicy::normalizeRenderMode(
            (string) ( $context['render_mode'] ?? '' ),
            (string) ( $context['editor_context'] ?? ( $context['context'] ?? ( $context['content_type'] ?? 'website' ) ) )
        );
        $context['_render_mode'] = $render_mode;

        $type       = (string) $block['type'];
        $definition = BlockRegistry::get( $type );

        if ( $definition === null ) {
            return self::renderUnknownBlock( $type );
        }

        $method = 'render' . self::studly( $type );

        $validation = BlockRegistry::validateBlock( $block );
        if ( ! $validation['valid'] ) {
            // Frontend rendering should degrade gracefully instead of dropping blocks.
            // This keeps editor->public parity even when schema has evolved.
            if ( method_exists( self::class, $method ) ) {
                $data  = $block['data'] ?? [];
                $style = $block['style'] ?? [];
                if ( ! empty( $context['_fluid_root'] ) && is_array( $style ) ) {
                    if ( self::hasFluidCoordinates( $style ) ) {
                        $style['__fluid_active'] = true;
                    }
                }
                return self::$method( $data, $style, $context );
            }
            return self::renderInvalidBlock( $type, $validation['errors'] );
        }

        $data  = $block['data'] ?? [];
        $style = $block['style'] ?? [];
        if ( is_array( $style ) ) {
            if ( self::isHiddenForDevice( $style, $context ) ) {
                return '';
            }
            if ( self::isHiddenForPageRule( $style, $context ) ) {
                return '';
            }
            $style = self::styleForDevice( $style, $context );
            $style = EditorContextPolicy::sanitizeStyleForRenderMode( $style, $render_mode );
        }
        if ( ! empty( $context['_fluid_root'] ) && is_array( $style ) ) {
            if ( self::hasFluidCoordinates( $style ) ) {
                $style['__fluid_active'] = true;
            }
        }

        if ( method_exists( self::class, $method ) ) {
            return self::$method( $data, $style, $context );
        }

        return self::renderGenericBlock( $type, $data, $style );
    }

    public static function renderBlocks( array $blocks, array $context = [] ): string {
        $fluid_root = false;
        $fluid_cols = 48;
        $fluid_rows = 0;
        foreach ( $blocks as $blk ) {
            if ( ! is_array( $blk ) ) {
                continue;
            }
            $normalized = self::normalizeIncomingBlock( $blk );
            $style = isset( $normalized['style'] ) && is_array( $normalized['style'] ) ? $normalized['style'] : [];
            if ( $style === [] ) {
                continue;
            }
            if ( self::hasFluidCoordinates( $style ) ) {
                $fluid_root = true;
                $rows = max( 1, (int) ( $style['grid_y'] ?? 0 ) + (int) ( $style['grid_h'] ?? 4 ) );
                if ( $rows > $fluid_rows ) {
                    $fluid_rows = $rows;
                }
                $cols = (int) ( $style['grid_cols'] ?? 48 );
                if ( $cols >= 12 && $cols <= 240 ) {
                    $fluid_cols = $cols;
                }
            }
        }

        $html = '';
        foreach ( $blocks as $block ) {
            if ( is_array( $block ) ) {
                try {
                    $render_context = $context;
                    if ( $fluid_root ) {
                        $render_context['_fluid_root'] = true;
                    }
                    $html .= self::render( $block, $render_context );
                } catch ( \Throwable $e ) {
                    if ( self::shouldEmitDebugComments() ) {
                        $type = isset( $block['type'] ) ? (string) $block['type'] : 'unknown';
                        $html .= sprintf(
                            '<!-- Block render failure (%s) -->',
                            metis_escape_html( $type )
                        );
                    }
                }
            }
        }

        if ( $fluid_root ) {
            return sprintf( '<div class="metis-fluid-root">%s</div>', $html );
        }
        return $html;
    }

    private static function renderText( array $data, array $style, array $context ): string {
        $content    = self::resolveDynamicShortcodes( (string) ( $data['content'] ?? '' ), $context );
        $tag        = (string) ( $data['tag'] ?? 'p' );
        $unwrapped  = self::unwrapOuterTag( $content, $tag );
        if ( $unwrapped !== null ) {
            $content = $unwrapped;
        }
        if ( $tag === 'p' && self::containsBlockMarkup( $content ) ) {
            $tag = 'div';
        }
        $min_height = ! empty( $data['min_height'] ) ? 'min-height:' . (int) $data['min_height'] . 'px;' : '';
        $i_style    = self::buildInlineStyle( $style, $min_height );
        $classes    = self::buildClasses( 'metis-block-text', $style );

        return sprintf( '<%1$s class="%2$s"%3$s>%4$s</%1$s>',
            metis_escape_attr( $tag ), metis_escape_attr( $classes ), $i_style, $content );
    }

    private static function renderHeading( array $data, array $style, array $context ): string {
        $content = self::resolveDynamicShortcodes( (string) ( $data['content'] ?? '' ), $context );
        $level   = (string) ( $data['level'] ?? 'h2' );
        $unwrapped = self::unwrapOuterTag( $content, $level );
        if ( $unwrapped !== null ) {
            $content = $unwrapped;
        }
        $heading_unwrapped = self::unwrapAnyHeading( $content );
        if ( $heading_unwrapped !== null ) {
            $content = $heading_unwrapped;
        }
        if ( self::containsBlockMarkup( $content ) ) {
            $level = 'div';
        }
        $i_style = self::buildInlineStyle( $style );
        $classes = self::buildClasses( 'metis-block-heading', $style );

        return sprintf( '<%1$s class="%2$s"%3$s>%4$s</%1$s>',
            metis_escape_attr( $level ), metis_escape_attr( $classes ), $i_style, $content );
    }

    private static function renderImage( array $data, array $style, array $context ): string {
        $src = $data['src'] ?? '';
        if ( $src === '' ) {
            $path = (string) ( $context['path'] ?? '' );
            if ( $path === '/editor/preview' ) {
                return sprintf(
                    '<div class="%s"%s><div class="metis-block-image-placeholder">Select an image</div></div>',
                    metis_escape_attr( self::buildClasses( 'metis-block-image', $style ) ),
                    self::buildInlineStyle( $style )
                );
            }
            return '';
        }

        $alt   = $data['alt'] ?? '';
        $width = $data['width'] ?? '100%';
        $link  = $data['link'] ?? '';

        $img = sprintf( '<img src="%s" alt="%s" style="width:%s;max-width:100%%;height:auto;display:block;">',
            metis_escape_url( $src ), metis_escape_attr( $alt ), metis_escape_attr( $width ) );

        if ( $link !== '' ) {
            $img = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', metis_escape_url( $link ), $img );
        }

        return sprintf( '<div class="%s"%s>%s</div>',
            metis_escape_attr( self::buildClasses( 'metis-block-image', $style ) ),
            self::buildInlineStyle( $style ),
            $img );
    }

    private static function renderButton( array $data, array $style, array $context ): string {
        $label   = self::resolveDynamicShortcodes( (string) ( $data['label'] ?? 'Click Here' ), $context );
        $url     = $data['url'] ?? '#';
        $action_type = metis_key_clean( (string) ( $data['action_type'] ?? 'url' ) );
        $popup_id = (int) ( $data['popup_id'] ?? 0 );
        $bgcolor = $data['bgcolor'] ?? '#0d6efd';
        $color   = $data['color'] ?? '#ffffff';
        $size    = $data['size'] ?? 'medium';

        if ( $action_type === 'popup' && $popup_id > 0 ) {
            return sprintf(
                '<div class="%s"%s><button type="button" class="metis-btn metis-btn-%s" data-metis-popup="%d" style="background-color:%s;color:%s;">%s</button></div>',
                metis_escape_attr( self::buildClasses( 'metis-block-button', $style ) ),
                self::buildInlineStyle( $style ),
                metis_escape_attr( $size ),
                $popup_id,
                metis_escape_attr( $bgcolor ),
                metis_escape_attr( $color ),
                metis_escape_html( $label )
            );
        }

        return sprintf(
            '<div class="%s"%s><a href="%s" class="metis-btn metis-btn-%s" style="background-color:%s;color:%s;">%s</a></div>',
            metis_escape_attr( self::buildClasses( 'metis-block-button', $style ) ),
            self::buildInlineStyle( $style ),
            metis_escape_url( $url ),
            metis_escape_attr( $size ),
            metis_escape_attr( $bgcolor ),
            metis_escape_attr( $color ),
            metis_escape_html( $label )
        );
    }

    private static function renderButtonGroup( array $data, array $style, array $context ): string {
        $buttons = isset( $data['buttons'] ) && is_array( $data['buttons'] ) ? $data['buttons'] : [];
        if ( $buttons === [] ) {
            return '';
        }
        $align = strtolower( trim( (string) ( $data['align'] ?? 'left' ) ) );
        if ( ! in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
            $align = 'left';
        }
        $justify = $align === 'center' ? 'center' : ( $align === 'right' ? 'flex-end' : 'flex-start' );
        $gap = self::sanitizeCssLength( (string) ( $data['gap'] ?? '8px' ), '8px' );
        $items = '';
        $count = 0;
        foreach ( $buttons as $idx => $button ) {
            if ( ! is_array( $button ) ) {
                continue;
            }
            $count += 1;
            $label = trim( self::resolveDynamicShortcodes( (string) ( $button['label'] ?? '' ), $context ) );
            if ( $label === '' ) {
                $label = 'Button ' . (string) $count;
            }
            $url = trim( (string) ( $button['url'] ?? '#' ) );
            if ( $url === '' ) {
                $url = '#';
            }

            $bg = self::sanitizeCssColor( (string) ( $button['bgcolor'] ?? '#485bc7' ), '#485bc7' );
            $color = self::sanitizeCssColor( (string) ( $button['color'] ?? '#ffffff' ), '#ffffff' );
            $hover_bg = self::sanitizeCssColor( (string) ( $button['hover_bgcolor'] ?? $bg ), $bg );
            $hover_color = self::sanitizeCssColor( (string) ( $button['hover_color'] ?? $color ), $color );
            $padding = self::sanitizeCssBox( (string) ( $button['padding'] ?? '10px 14px' ), '10px 14px' );
            $radius = self::sanitizeCssLength( (string) ( $button['border_radius'] ?? '8px' ), '8px' );
            $text_size = self::sanitizeCssLength( (string) ( $button['text_size'] ?? '16px' ), '16px' );
            $animation = strtolower( trim( (string) ( $button['animation'] ?? 'none' ) ) );
            if ( ! in_array( $animation, [ 'none', 'lift', 'pulse', 'glow' ], true ) ) {
                $animation = 'none';
            }

            $button_style = '--metis-btn-bg:' . $bg . ';'
                . '--metis-btn-color:' . $color . ';'
                . '--metis-btn-bg-hover:' . $hover_bg . ';'
                . '--metis-btn-color-hover:' . $hover_color . ';'
                . '--metis-btn-padding:' . $padding . ';'
                . '--metis-btn-radius:' . $radius . ';'
                . '--metis-btn-size:' . $text_size . ';';

            $items .= sprintf(
                '<a href="%s" class="metis-btn metis-btn-item metis-btn-anim-%s" style="%s">%s</a>',
                metis_escape_url( $url ),
                metis_escape_attr( $animation ),
                metis_escape_attr( $button_style ),
                metis_escape_html( $label )
            );
        }

        if ( $items === '' ) {
            return '';
        }

        $wrapper_styles = 'display:flex;flex-wrap:wrap;justify-content:' . $justify . ';gap:' . $gap . ';';
        return self::buttonGroupCssTag() . sprintf(
            '<div class="%s"%s>%s</div>',
            metis_escape_attr( self::buildClasses( 'metis-block-button-group', $style ) ),
            self::buildInlineStyle( $style, $wrapper_styles ),
            $items
        );
    }

    private static function renderContainer( array $data, array $style, array $context ): string {
        $blocks      = $data['blocks'] ?? [];
        $max_width   = (string) ( $data['max_width'] ?? '1200px' );
        $fixed_width = (string) ( $data['fixed_width'] ?? $max_width );
        $width_mode  = (string) ( $data['width_mode'] ?? 'fixed' );
        $align       = (string) ( $data['align'] ?? 'center' );
        $render_style = is_array( $style ) ? $style : [];
        $child_context = $context;
        // Child blocks inside a container should respect container flow/alignment,
        // not inherit root-level fluid absolute positioning.
        unset( $child_context['_fluid_root'] );

        if ( $fixed_width === '' ) {
            $fixed_width = '1200px';
        }

        if ( $width_mode === 'full' ) {
            $container_style = 'width:100%;max-width:none;';
        } else {
            $container_style = 'max-width:' . $fixed_width . ';width:100%;';
        }
        $inner_style = '';
        if ( $align === 'center' || $align === 'full' ) {
            $container_style .= 'margin-left:auto;margin-right:auto;';
            if ( $width_mode === 'full' ) {
                $inner_style = 'width:100%;display:flex;flex-direction:column;align-items:center;';
            }
        } elseif ( $align === 'right' ) {
            $container_style .= 'margin-left:auto;';
            if ( $width_mode === 'full' ) {
                $inner_style = 'width:100%;display:flex;flex-direction:column;align-items:flex-end;';
            }
        } elseif ( $width_mode === 'full' ) {
            $inner_style = 'width:100%;display:flex;flex-direction:column;align-items:flex-start;';
        }

        $content = self::renderBlocks( $blocks, $child_context );
        if ( $inner_style !== '' ) {
            $content = '<div class="metis-container-inner" style="' . metis_escape_attr( $inner_style ) . '">' . $content . '</div>';
        }

        if ( self::hasFluidCoordinates( $render_style ) ) {
            // Containers in fluid layouts should keep x/y placement,
            // but let their content determine height without clipping.
            $render_style['__fluid_auto_height'] = true;
            $render_style['__fluid_no_clip'] = true;
        }

        return sprintf( '<div class="%s"%s>%s</div>',
            metis_escape_attr( self::buildClasses( 'metis-block-container', $style ) ),
            self::buildInlineStyle( $render_style, $container_style ),
            $content );
    }

    private static function renderSpacer( array $data, array $style, array $context ): string {
        $responsive = ! empty( $data['responsive'] );
        $height     = max( 0, (int) ( $data['height'] ?? 24 ) );
        if ( $responsive ) {
            $desktop = max( 0, (int) ( $data['desktop_height'] ?? $height ) );
            if ( $desktop > 0 ) {
                $height = $desktop;
            }
        }
        return sprintf(
            '<div class="%s"%s></div>',
            metis_escape_attr( self::buildClasses( 'metis-block-spacer', $style ) ),
            self::buildInlineStyle( $style, 'height:' . $height . 'px;' )
        );
    }

    private static function renderDivider( array $data, array $style, array $context ): string {
        $divider = sprintf(
            '<hr class="%s"%s>',
            metis_escape_attr( self::buildClasses( 'metis-block-divider', $style ) ),
            self::buildInlineStyle(
                $style,
                sprintf(
                    'border:none;border-top:%dpx %s %s;margin:1rem 0;',
                    (int) ( $data['height'] ?? 1 ),
                    (string) ( $data['style'] ?? 'solid' ),
                    (string) ( $data['color'] ?? '#e2e6ea' )
                )
            )
        );
        $label = trim( (string) ( $data['label'] ?? '' ) );
        if ( $label === '' ) {
            return $divider;
        }
        return $divider . '<div class="metis-block-divider-label" style="margin-top:0.375rem;font-size:0.75rem;color:#64748b;">' . metis_escape_html( $label ) . '</div>';
    }

    private static function renderGrid( array $data, array $style, array $context ): string {
        $columns   = max( 1, min( 4, (int) ( $data['columns'] ?? 2 ) ) );
        $gap       = (string) ( $data['gap'] ?? '24px' );
        $ratios    = self::normalizeColumnRatios( $data['ratios'] ?? [], $columns );
        $col_blocks = $data['col_blocks'] ?? [];
        $vertical_align = strtolower( (string) ( $style['vertical_align'] ?? 'top' ) );
        $column_justify = 'flex-start';
        if ( $vertical_align === 'center' ) {
            $column_justify = 'center';
        } elseif ( $vertical_align === 'bottom' ) {
            $column_justify = 'flex-end';
        }
        $grid_template = implode(
            ' ',
            array_map(
                static function( float $ratio ): string {
                    return 'minmax(0,' . rtrim( rtrim( number_format( $ratio, 4, '.', '' ), '0' ), '.' ) . 'fr)';
                },
                $ratios
            )
        );

        $cols_html = '';
        for ( $i = 0; $i < $columns; $i++ ) {
            $col_content = isset( $col_blocks[ $i ] ) && is_array( $col_blocks[ $i ] )
                ? self::renderBlocks( $col_blocks[ $i ], $context )
                : '';
            $cols_html .= '<div class="metis-grid-col" style="display:flex;flex-direction:column;justify-content:' . metis_escape_attr( $column_justify ) . ';">' . $col_content . '</div>';
        }

        return sprintf(
            '<div class="%s"%s>%s</div>',
            metis_escape_attr( self::buildClasses( 'metis-block-grid', $style ) ),
            self::buildInlineStyle( $style, sprintf( 'display:grid;grid-template-columns:%s;gap:%s;', $grid_template, $gap ) ),
            $cols_html
        );
    }

    private static function normalizeColumnRatios( $raw, int $columns ): array {
        $columns = max( 1, min( 4, $columns ) );
        $parts = is_array( $raw ) ? $raw : [];
        $ratios = [];

        foreach ( $parts as $part ) {
            $ratio = is_numeric( $part ) ? (float) $part : 0.0;
            if ( $ratio <= 0 ) {
                continue;
            }
            $ratios[] = $ratio;
        }

        while ( count( $ratios ) < $columns ) {
            $ratios[] = 1.0;
        }

        if ( count( $ratios ) > $columns ) {
            $ratios = array_slice( $ratios, 0, $columns );
        }

        if ( $ratios === [] ) {
            $ratios = array_fill( 0, $columns, 1.0 );
        }

        return $ratios;
    }

    private static function renderPostList( array $data, array $style, array $context ): string {
        $count        = max( 1, (int) ( $data['count'] ?? 5 ) );
        $layout       = $data['layout'] ?? 'list';
        $show_excerpt = ! empty( $data['show_excerpt'] );
        $show_date    = ! empty( $data['show_date'] );

        $posts = array_values( array_filter(
            PostService::getPublished( [ 'limit' => $count * 3 ] ),
            static fn ( $post ): bool => $post instanceof \Metis\Modules\Website\Entities\Post && PostService::publicPath( $post ) !== ''
        ) );
        $posts = array_slice( $posts, 0, $count );
        if ( empty( $posts ) ) {
            return sprintf(
                '<div class="%s"%s><p>No posts yet.</p></div>',
                metis_escape_attr( self::buildClasses( 'metis-block-post-list metis-post-list-empty', $style ) ),
                self::buildInlineStyle( $style )
            );
        }

        $items = '';
        foreach ( $posts as $post ) {
            $url   = PostService::publicPath( $post );
            if ( $url === '' ) {
                continue;
            }
            $date  = $show_date && $post->publish_date ? '<time class="metis-post-date">' . metis_escape_html( $post->publish_date ) . '</time>' : '';
            $exc   = $show_excerpt && $post->excerpt ? '<p class="metis-post-excerpt">' . metis_escape_html( $post->excerpt ) . '</p>' : '';
            $items .= sprintf(
                '<article class="metis-post-item"><h3><a href="%s">%s</a></h3>%s%s</article>',
                metis_escape_url( $url ), metis_escape_html( $post->title ), $date, $exc
            );
        }

        return sprintf(
            '<div class="%s"%s>%s</div>',
            metis_escape_attr( self::buildClasses( 'metis-block-post-list metis-post-list-' . $layout, $style ) ),
            self::buildInlineStyle( $style ),
            $items
        );
    }

    private static function renderEventsBlock( array $data, array $style, array $context ): string {
        $count = max( 1, min( 50, (int) ( $data['count'] ?? 5 ) ) );
        $source = metis_key_clean( (string) ( $data['source'] ?? 'calendar' ) );
        if ( ! in_array( $source, [ 'calendar', 'manual' ], true ) ) {
            $source = 'calendar';
        }

        $requested_calendar_id = trim( (string) ( $data['calendar_id'] ?? '' ) );
        $items = [];
        if ( $source === 'manual' ) {
            $manual_items = is_array( $data['items'] ?? null ) ? $data['items'] : [];
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
                if ( count( $items ) >= $count ) {
                    break;
                }
            }
        } elseif ( function_exists( 'metis_calendar_cached_events' ) ) {
            try {
                $configs = self::calendarConfigsForWebsite( $requested_calendar_id );

                $startTs = strtotime( 'today midnight' ) ?: time();
                $endTs = strtotime( '+365 days', $startTs ) ?: ( $startTs + ( 365 * DAY_IN_SECONDS ) );
                foreach ( $configs as $cfg ) {
                    $rows = metis_calendar_cached_events( $cfg, (int) $startTs, (int) $endTs, '' );
                    if ( is_array( $rows ) && $rows !== [] ) {
                        $items = array_merge( $items, $rows );
                    }
                    if ( $items !== [] ) {
                        break;
                    }
                }
            } catch ( \Throwable $e ) {
                // Keep graceful fallback below.
            }
        }

        if ( $items !== [] ) {
            usort(
                $items,
                static function ( $a, $b ): int {
                    $aStart = (string) ( ( $a['start']['dateTime'] ?? $a['start']['date'] ?? '' ) );
                    $bStart = (string) ( ( $b['start']['dateTime'] ?? $b['start']['date'] ?? '' ) );
                    $aTs = strtotime( $aStart ) ?: 0;
                    $bTs = strtotime( $bStart ) ?: 0;
                    return $aTs <=> $bTs;
                }
            );
            $items = array_slice( $items, 0, $count );
        }

        if ( $items === [] ) {
            return sprintf(
                '<div class="%s"%s><p>No upcoming events.</p></div>',
                metis_escape_attr( self::buildClasses( 'metis-block-events metis-events-empty', $style ) ),
                self::buildInlineStyle( $style )
            );
        }

        $html = '';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $title = trim( (string) ( $item['summary'] ?? $item['title'] ?? 'Event' ) );
            $startRaw = (string) ( $item['start']['dateTime'] ?? $item['start']['date'] ?? '' );
            $location = trim( (string) ( $item['location'] ?? '' ) );
            $when = '';
            if ( $startRaw !== '' ) {
                $ts = strtotime( $startRaw );
                if ( $ts ) {
                    $when = function_exists( 'metis_runtime_format_datetime' )
                        ? metis_runtime_format_datetime( $startRaw )
                        : date( 'M j, Y g:i A', (int) $ts );
                }
            }
            $html .= '<article class="metis-event-item">';
            $html .= '<h3 class="metis-event-title">' . metis_escape_html( $title !== '' ? $title : 'Event' ) . '</h3>';
            if ( $when !== '' ) {
                $html .= '<time class="metis-event-date">' . metis_escape_html( $when ) . '</time>';
            }
            if ( $location !== '' ) {
                $html .= '<p class="metis-event-location">' . metis_escape_html( $location ) . '</p>';
            }
            $html .= '</article>';
        }

        return sprintf(
            '<section class="%s"%s>%s</section>',
            metis_escape_attr( self::buildClasses( 'metis-block-events', $style ) ),
            self::buildInlineStyle( $style ),
            $html
        );
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

    private static function renderTeamBlock( array $data, array $style, array $context ): string {
        $members = isset( $data['members'] ) && is_array( $data['members'] ) ? $data['members'] : [];
        if ( $members === [] ) {
            return sprintf(
                '<div class="%s"%s><p>No team members configured.</p></div>',
                metis_escape_attr( self::buildClasses( 'metis-block-team metis-team-empty', $style ) ),
                self::buildInlineStyle( $style )
            );
        }

        $cards = '';
        foreach ( $members as $member ) {
            if ( ! is_array( $member ) ) {
                continue;
            }
            $name = trim( (string) ( $member['name'] ?? '' ) );
            $role = trim( (string) ( $member['role'] ?? '' ) );
            $bio = trim( (string) ( $member['bio'] ?? '' ) );
            $cards .= '<article class="metis-team-member">';
            $cards .= '<h3 class="metis-team-name">' . metis_escape_html( $name !== '' ? $name : 'Team Member' ) . '</h3>';
            if ( $role !== '' ) {
                $cards .= '<p class="metis-team-role">' . metis_escape_html( $role ) . '</p>';
            }
            if ( $bio !== '' ) {
                $cards .= '<p class="metis-team-bio">' . metis_escape_html( $bio ) . '</p>';
            }
            $cards .= '</article>';
        }

        if ( $cards === '' ) {
            $cards = '<p>No team members configured.</p>';
        }

        return sprintf(
            '<section class="%s"%s>%s</section>',
            metis_escape_attr( self::buildClasses( 'metis-block-team', $style ) ),
            self::buildInlineStyle( $style ),
            $cards
        );
    }

    private static function renderPageTitle( array $data, array $style, array $context ): string {
        $tag   = $data['tag'] ?? 'h1';
        $title = (string) ( $context['page_title'] ?? '' );
        if ( isset( $data['content'] ) && is_scalar( $data['content'] ) && trim( (string) $data['content'] ) !== '' ) {
            $title = (string) $data['content'];
        }
        $title = self::resolveDynamicShortcodes( $title, $context );
        if ( $title === '' ) {
            return '';
        }
        return sprintf( '<%1$s class="%2$s"%3$s>%4$s</%1$s>',
            metis_escape_attr( $tag ),
            metis_escape_attr( self::buildClasses( 'metis-block-page-title', $style ) ),
            self::buildInlineStyle( $style ),
            metis_escape_html( $title ) );
    }

    private static function renderBreadcrumbs( array $data, array $style, array $context ): string {
        $separator = $data['separator'] ?? '/';
        $show_home = ! empty( $data['show_home'] );
        $crumbs    = $context['breadcrumbs'] ?? [];

        if ( empty( $crumbs ) ) {
            return '';
        }

        $items = '';
        if ( $show_home ) {
            $items .= '<li class="metis-crumb"><a href="/">Home</a></li>';
        }
        foreach ( $crumbs as $i => $crumb ) {
            $sep   = ( $items !== '' ) ? '<span class="metis-crumb-sep" aria-hidden="true">' . metis_escape_html( $separator ) . '</span>' : '';
            $label = metis_escape_html( $crumb['label'] ?? '' );
            $url   = $crumb['url'] ?? '';
            $item  = ( $url !== '' && $i < count( $crumbs ) - 1 )
                ? '<a href="' . metis_escape_url( $url ) . '">' . $label . '</a>'
                : '<span aria-current="page">' . $label . '</span>';
            $items .= '<li class="metis-crumb">' . $sep . $item . '</li>';
        }

        return sprintf(
            '<nav class="%s"%s aria-label="Breadcrumb"><ol>%s</ol></nav>',
            metis_escape_attr( self::buildClasses( 'metis-block-breadcrumbs', $style ) ),
            self::buildInlineStyle( $style ),
            $items
        );
    }

    private static function renderPopupTrigger( array $data, array $style, array $context ): string {
        $popup_id = $data['popup_id'] ?? null;
        if ( $popup_id === null ) {
            return '';
        }
        $label   = metis_escape_html( $data['label'] ?? 'Open' );
        $element = $data['trigger_element'] ?? 'button';
        $attrs   = 'data-metis-popup="' . (int) $popup_id . '"';

        if ( $element === 'button' ) {
            return sprintf(
                '<div class="%s"%s><button type="button" class="metis-popup-btn" %s>%s</button></div>',
                metis_escape_attr( self::buildClasses( 'metis-block-popup-trigger', $style ) ),
                self::buildInlineStyle( $style ),
                $attrs,
                $label
            );
        }

        return sprintf(
            '<div class="%s"%s><span class="metis-popup-link" %s>%s</span></div>',
            metis_escape_attr( self::buildClasses( 'metis-block-popup-trigger', $style ) ),
            self::buildInlineStyle( $style ),
            $attrs,
            $label
        );
    }

    private static function renderIcon( array $data, array $style, array $context ): string {
        $svg   = $data['icon_svg'] ?? '';
        $size  = metis_escape_attr( $data['size'] ?? '24px' );
        $color = metis_escape_attr( $data['color'] ?? '#000000' );
        $link  = $data['link'] ?? '';

        if ( $svg === '' ) {
            return '';
        }

        $inner = sprintf( '<span class="metis-block-icon" style="display:inline-block;width:%s;height:%s;color:%s;">%s</span>',
            $size, $size, $color, $svg );

        if ( $link !== '' ) {
            $inner = sprintf( '<a href="%s">%s</a>', metis_escape_url( $link ), $inner );
        }

        return sprintf(
            '<div class="%s"%s>%s</div>',
            metis_escape_attr( self::buildClasses( 'metis-block-icon-wrap', $style ) ),
            self::buildInlineStyle( $style ),
            $inner
        );
    }

    private static function renderVideo( array $data, array $style, array $context ): string {
        $url      = $data['url'] ?? '';
        $provider = $data['provider'] ?? 'youtube';
        $ratio    = $data['aspect_ratio'] ?? '16:9';

        if ( $url === '' ) {
            return '';
        }

        [ $rw, $rh ] = explode( ':', $ratio . ':9' );
        $pad = round( ( (int) $rh / max( 1, (int) $rw ) ) * 100, 2 );

        $embed = '';
        $safe_url = metis_escape_url( (string) $url );
        if ( $provider === 'youtube' ) {
            if ( $safe_url !== '' ) {
                $embed = sprintf(
                    '<a class="metis-video-external metis-video-youtube" href="%1$s" target="_blank" rel="noopener noreferrer" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-decoration:none;font-weight:600;">Open YouTube Video</a>',
                    $safe_url
                );
            }
        } elseif ( $provider === 'vimeo' ) {
            if ( $safe_url !== '' ) {
                $embed = sprintf(
                    '<a class="metis-video-external metis-video-vimeo" href="%1$s" target="_blank" rel="noopener noreferrer" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-decoration:none;font-weight:600;">Open Vimeo Video</a>',
                    $safe_url
                );
            }
        } elseif ( $provider === 'local' ) {
            if ( $safe_url !== '' ) {
                $embed = sprintf( '<video controls style="position:absolute;top:0;left:0;width:100%%;height:100%%;"><source src="%s"></video>', $safe_url );
            }
        }

        if ( $embed === '' ) {
            return '';
        }

        return sprintf(
            '<div class="%s"%s>%s</div>',
            metis_escape_attr( self::buildClasses( 'metis-block-video', $style ) ),
            self::buildInlineStyle( $style, 'position:relative;padding-bottom:' . $pad . '%;height:0;overflow:hidden;' ),
            $embed
        );
    }

    private static function renderMenu( array $data, array $style, array $context ): string {
        $menu_id     = $data['menu_id'] ?? null;
        $orientation = $data['orientation'] ?? 'horizontal';

        if ( $menu_id === null ) {
            return '';
        }

        // MenuService is available via autoloader
        $menu  = MenuService::getById( (int) $menu_id );
        $items = $menu !== null ? MenuService::getItems( $menu ) : [];

        if ( empty( $items ) ) {
            return '';
        }

        $normalized_items = self::normalizeMenuItems( $items );
        $buttonize_items = strtolower( trim( (string) ( $data['buttonize_items'] ?? 'none' ) ) );
        if ( ! in_array( $buttonize_items, [ 'none', 'all', 'first', 'last' ], true ) ) {
            $buttonize_items = 'none';
        }
        $item_button_map = self::normalizeMenuItemButtonMap( $data['menu_item_buttons'] ?? [] );
        $links = self::renderMenuTreeHtml(
            $normalized_items,
            [
                'buttonize_items' => $buttonize_items,
                'item_button_map' => $item_button_map,
            ]
        );
        if ( $links === '' ) {
            return '';
        }

        $justify_value = strtolower( trim( (string) ( $data['justify'] ?? 'left' ) ) );
        $justify_css = 'flex-start';
        if ( $justify_value === 'center' ) {
            $justify_css = 'center';
        } elseif ( $justify_value === 'right' ) {
            $justify_css = 'flex-end';
        }
        $align_css = $justify_css;
        $gap = trim( (string) ( $data['gap'] ?? '12px' ) );
        if ( $gap === '' ) {
            $gap = '12px';
        }
        $item_color = trim( (string) ( $data['item_color'] ?? '' ) );
        $hover_color = trim( (string) ( $data['item_hover_color'] ?? '' ) );
        $item_weight = trim( (string) ( $data['item_weight'] ?? '' ) );
        $item_size = self::sanitizeCssLength( (string) ( $data['item_size'] ?? '' ), '' );
        $menu_vars = '--metis-menu-gap:' . $gap . ';--metis-menu-justify:' . $justify_css . ';--metis-menu-align:' . $align_css . ';';
        if ( $item_color !== '' ) {
            $menu_vars .= '--metis-menu-link:' . $item_color . ';';
        }
        if ( $hover_color !== '' ) {
            $menu_vars .= '--metis-menu-link-hover:' . $hover_color . ';';
        }
        if ( $item_weight !== '' ) {
            $menu_vars .= '--metis-menu-weight:' . preg_replace( '/[^0-9]/', '', $item_weight ) . ';';
        }
        if ( $item_size !== '' ) {
            $menu_vars .= '--metis-menu-size:' . $item_size . ';';
        }

        $menu_type = strtolower( trim( (string) ( $data['menu_type'] ?? 'horizontal' ) ) );
        if ( ! in_array( $menu_type, [ 'horizontal', 'sidebar', 'offcanvas' ], true ) ) {
            $menu_type = 'horizontal';
        }

        $responsive_toggle = strtolower( trim( (string) ( $data['responsive_toggle'] ?? 'hamburger' ) ) );
        if ( ! in_array( $responsive_toggle, [ 'hamburger', 'none' ], true ) ) {
            $responsive_toggle = 'hamburger';
        }
        $responsive_style = strtolower( trim( (string) ( $data['responsive_style'] ?? 'overlay' ) ) );
        if ( ! in_array( $responsive_style, [ 'overlay', 'dropdown', 'inline' ], true ) ) {
            $responsive_style = 'overlay';
        }
        $flyout_position = strtolower( trim( (string) ( $data['flyout_position'] ?? 'right' ) ) );
        if ( ! in_array( $flyout_position, [ 'left', 'right' ], true ) ) {
            $flyout_position = 'right';
        }
        $breakpoint = strtolower( trim( (string) ( $data['breakpoint'] ?? 'medium_small' ) ) );
        if ( ! in_array( $breakpoint, [ 'small_only', 'medium_small', 'always' ], true ) ) {
            $breakpoint = 'medium_small';
        }
        $submenu_icon = strtolower( trim( (string) ( $data['submenu_icon'] ?? 'none' ) ) );
        if ( ! in_array( $submenu_icon, [ 'none', 'arrow', 'chevron_down', 'plus', 'caret' ], true ) ) {
            $submenu_icon = 'none';
        }
        $flyout_width = max( 180, min( 520, (int) ( $data['flyout_width'] ?? 260 ) ) );
        $menu_vars .= '--metis-menu-flyout-width:' . $flyout_width . 'px;';
        $button_padding = trim( (string) ( $data['button_padding'] ?? '8px 12px' ) );
        if ( $button_padding === '' ) {
            $button_padding = '8px 12px';
        }
        $button_bg = self::sanitizeCssColor( (string) ( $data['button_bg'] ?? '#485bc7' ), '#485bc7' );
        $button_text_color = self::sanitizeCssColor( (string) ( $data['button_text_color'] ?? '#ffffff' ), '#ffffff' );
        $button_radius = self::sanitizeCssLength( (string) ( $data['button_radius'] ?? '8px' ), '8px' );
        $button_hover_bg = self::sanitizeCssColor( (string) ( $data['button_hover_bg'] ?? $button_bg ), $button_bg );
        $button_hover_text_color = self::sanitizeCssColor( (string) ( $data['button_hover_text_color'] ?? $button_text_color ), $button_text_color );
        $menu_vars .= '--metis-menu-btn-padding:' . $button_padding . ';';
        $menu_vars .= '--metis-menu-btn-bg:' . $button_bg . ';';
        $menu_vars .= '--metis-menu-btn-color:' . $button_text_color . ';';
        $menu_vars .= '--metis-menu-btn-radius:' . $button_radius . ';';
        $menu_vars .= '--metis-menu-btn-hover-bg:' . $button_hover_bg . ';';
        $menu_vars .= '--metis-menu-btn-hover-color:' . $button_hover_text_color . ';';

        return sprintf(
            '<nav class="%s"%s data-menu-type="%s" data-buttonize-items="%s" data-responsive-toggle="%s" data-responsive-style="%s" data-flyout-position="%s" data-breakpoint="%s" data-submenu-icon="%s"><ul class="metis-menu-list">%s</ul></nav>',
            metis_escape_attr( self::buildClasses( 'metis-block-menu metis-menu-' . $orientation . ' metis-menu-type-' . $menu_type, $style ) ),
            self::buildInlineStyle( $style, $menu_vars ),
            metis_escape_attr( $menu_type ),
            metis_escape_attr( $buttonize_items ),
            metis_escape_attr( $responsive_toggle ),
            metis_escape_attr( $responsive_style ),
            metis_escape_attr( $flyout_position ),
            metis_escape_attr( $breakpoint ),
            metis_escape_attr( $submenu_icon ),
            $links
        );
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,array{id:string,parent_id:string,label:string,url:string,target:string}>
     */
    private static function normalizeMenuItems( array $items ): array {
        $normalized = [];
        $seen_ids = [];
        $counter = 1;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $label = trim( (string) ( $item['label'] ?? '' ) );
            $url = trim( (string) ( $item['url'] ?? '' ) );
            if ( $label === '' || $url === '' ) {
                continue;
            }
            $candidate_id = metis_key_clean( (string) ( $item['id'] ?? '' ) );
            if ( $candidate_id === '' || isset( $seen_ids[ $candidate_id ] ) ) {
                $candidate_id = 'mitem_' . $counter;
                $counter++;
            }
            $seen_ids[ $candidate_id ] = true;
            $target = trim( (string) ( $item['target'] ?? '' ) );
            if ( $target === '' && ! empty( $item['external'] ) ) {
                $target = '_blank';
            }
            if ( $target !== '_blank' ) {
                $target = '';
            }
            $normalized[] = [
                'id' => $candidate_id,
                'parent_id' => metis_key_clean( (string) ( $item['parent_id'] ?? '' ) ),
                'label' => $label,
                'url' => $url,
                'target' => $target,
            ];
        }

        $id_map = [];
        foreach ( $normalized as $item ) {
            $id_map[ $item['id'] ] = true;
        }
        foreach ( $normalized as &$item ) {
            if ( $item['parent_id'] === '' || ! isset( $id_map[ $item['parent_id'] ] ) || $item['parent_id'] === $item['id'] ) {
                $item['parent_id'] = '';
            }
        }
        unset( $item );

        return $normalized;
    }

    /**
     * @param array<int,array{id:string,parent_id:string,label:string,url:string,target:string}> $items
     */
    private static function renderMenuTreeHtml( array $items, array $options = [] ): string {
        if ( $items === [] ) {
            return '';
        }

        $children = [];
        foreach ( $items as $item ) {
            $parent = (string) ( $item['parent_id'] ?? '' );
            if ( ! isset( $children[ $parent ] ) ) {
                $children[ $parent ] = [];
            }
            $children[ $parent ][] = $item;
        }

        $buttonize_items = strtolower( trim( (string) ( $options['buttonize_items'] ?? 'none' ) ) );
        $item_button_map = isset( $options['item_button_map'] ) && is_array( $options['item_button_map'] )
            ? $options['item_button_map']
            : [];
        $walk = static function ( string $parent_id, int $depth, array $trail = [] ) use ( &$walk, $children, $buttonize_items, $item_button_map ): string {
            if ( ! isset( $children[ $parent_id ] ) ) {
                return '';
            }

            $html = '';
            $siblings = $children[ $parent_id ];
            $total = count( $siblings );
            foreach ( $siblings as $idx => $item ) {
                $id = (string) $item['id'];
                if ( in_array( $id, $trail, true ) ) {
                    continue;
                }
                $next_trail = $trail;
                $next_trail[] = $id;
                $child_html = $walk( $id, $depth + 1, $next_trail );
                $has_children = $child_html !== '';
                $item_url = metis_escape_url( (string) $item['url'] );
                $item_label = metis_escape_html( (string) $item['label'] );
                $item_target = (string) $item['target'] === '_blank' ? ' target="_blank" rel="noopener"' : '';
                $item_class = 'metis-menu-item metis-menu-depth-' . $depth;
                if ( $has_children ) {
                    $item_class .= ' metis-menu-item-has-children';
                }
                $link_class = '';
                if ( $depth === 0 ) {
                    $is_button = false;
                    if ( $buttonize_items === 'all' ) {
                        $is_button = true;
                    } elseif ( $buttonize_items === 'first' && $idx === 0 ) {
                        $is_button = true;
                    } elseif ( $buttonize_items === 'last' && $idx === ( $total - 1 ) ) {
                        $is_button = true;
                    }
                    if ( $is_button ) {
                        $link_class = ' class="metis-menu-btn"';
                    }
                }
                $link_style = '';
                if ( isset( $item_button_map[ $id ] ) && is_array( $item_button_map[ $id ] ) ) {
                    $cfg = $item_button_map[ $id ];
                    $flag = strtolower( trim( (string) ( $cfg['is_button'] ?? '' ) ) );
                    if ( in_array( $flag, [ 'yes', 'true', '1', 'on' ], true ) ) {
                        if ( $link_class === '' ) {
                            $link_class = ' class="metis-menu-btn"';
                        }
                        $vars = '';
                        $item_btn_bg = self::sanitizeCssColor( (string) ( $cfg['button_bg'] ?? '' ), '' );
                        $item_btn_text = self::sanitizeCssColor( (string) ( $cfg['button_text_color'] ?? '' ), '' );
                        $item_btn_hover_bg = self::sanitizeCssColor( (string) ( $cfg['button_hover_bg'] ?? '' ), '' );
                        $item_btn_hover_text = self::sanitizeCssColor( (string) ( $cfg['button_hover_text_color'] ?? '' ), '' );
                        $item_btn_padding = self::sanitizeCssBox( (string) ( $cfg['button_padding'] ?? '' ), '' );
                        $item_btn_radius = self::sanitizeCssLength( (string) ( $cfg['button_radius'] ?? '' ), '' );
                        if ( $item_btn_bg !== '' ) {
                            $vars .= '--metis-menu-item-btn-bg:' . $item_btn_bg . ';';
                        }
                        if ( $item_btn_text !== '' ) {
                            $vars .= '--metis-menu-item-btn-color:' . $item_btn_text . ';';
                        }
                        if ( $item_btn_hover_bg !== '' ) {
                            $vars .= '--metis-menu-item-btn-hover-bg:' . $item_btn_hover_bg . ';';
                        }
                        if ( $item_btn_hover_text !== '' ) {
                            $vars .= '--metis-menu-item-btn-hover-color:' . $item_btn_hover_text . ';';
                        }
                        if ( $item_btn_padding !== '' ) {
                            $vars .= '--metis-menu-item-btn-padding:' . $item_btn_padding . ';';
                        }
                        if ( $item_btn_radius !== '' ) {
                            $vars .= '--metis-menu-item-btn-radius:' . $item_btn_radius . ';';
                        }
                        if ( $vars !== '' ) {
                            $link_style = ' style="' . metis_escape_attr( $vars ) . '"';
                        }
                    } elseif ( in_array( $flag, [ 'no', 'false', '0', 'off' ], true ) ) {
                        $link_class = '';
                    }
                }
                $html .= '<li class="' . metis_escape_attr( $item_class ) . '"><a' . $link_class . $link_style . ' href="' . $item_url . '"' . $item_target . '>' . $item_label . '</a>';
                if ( $has_children ) {
                    $html .= '<ul class="metis-menu-submenu">' . $child_html . '</ul>';
                }
                $html .= '</li>';
            }

            return $html;
        };

        return $walk( '', 0 );
    }

    /**
     * @param mixed $raw
     * @return array<string,array<string,string>>
     */
    private static function normalizeMenuItemButtonMap( $raw ): array {
        $rows = [];
        if ( is_array( $raw ) ) {
            $rows = $raw;
        } elseif ( is_string( $raw ) && trim( $raw ) !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $rows = $decoded;
            }
        }
        $map = [];
        foreach ( $rows as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $item_id = metis_key_clean( (string) ( $entry['item_id'] ?? '' ) );
            if ( $item_id === '' ) {
                continue;
            }
            $map[ $item_id ] = [
                'is_button' => strtolower( trim( (string) ( $entry['is_button'] ?? '' ) ) ),
                'button_bg' => (string) ( $entry['button_bg'] ?? '' ),
                'button_text_color' => (string) ( $entry['button_text_color'] ?? '' ),
                'button_hover_bg' => (string) ( $entry['button_hover_bg'] ?? '' ),
                'button_hover_text_color' => (string) ( $entry['button_hover_text_color'] ?? '' ),
                'button_padding' => (string) ( $entry['button_padding'] ?? '' ),
                'button_radius' => (string) ( $entry['button_radius'] ?? '' ),
            ];
        }
        return $map;
    }

    private static function renderHtml( array $data, array $style, array $context ): string {
        $content = $data['content'] ?? '';
        $text = self::resolveDynamicShortcodes( (string) $content, $context );
        if ( trim( $text ) === '' ) {
            return '<div class="metis-block-html"></div>';
        }

        $sanitized = function_exists( 'metis_runtime_kses_post' )
            ? metis_runtime_kses_post( $text )
            : strip_tags( $text, '<div><span><p><strong><em><b><i><u><br><hr><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><a><img><table><thead><tbody><tr><th><td>' );
        return sprintf(
            '<div class="%s"%s>%s</div>',
            metis_escape_attr( self::buildClasses( 'metis-block-html', $style ) ),
            self::buildInlineStyle( $style ),
            $sanitized
        );
    }

    private static function renderForm( array $data, array $style, array $context ): string {
        $form_id = trim( (string) ( $data['form_id'] ?? '' ) );
        $submit = trim( (string) ( $data['submit_label'] ?? 'Submit' ) );
        $fallback = sprintf(
            '<form class="metis-block-form metis-block-form-placeholder"><input type="text" placeholder="Your name"><input type="email" placeholder="Email"><button type="button">%s</button></form>',
            metis_escape_html( $submit )
        );
        return self::renderCanonicalFormEmbed( $form_id, $data, 'metis-block-form', $fallback );
    }

    private static function renderInlineForm( array $data, array $style, array $context ): string {
        return self::renderForm( $data, $style, $context );
    }

    private static function renderMultiStepForm( array $data, array $style, array $context ): string {
        return self::renderForm( $data, $style, $context );
    }

    private static function renderFormTabsBlock( array $data, array $style, array $context ): string {
        $tabs = is_array( $data['tabs'] ?? null ) ? $data['tabs'] : [];
        $group = 'metis-form-tabs-' . substr( sha1( (string) json_encode( $tabs ) ), 0, 8 );
        $buttons = '';
        $panels = '';
        $rendered = 0;

        foreach ( $tabs as $index => $tab ) {
            if ( ! is_array( $tab ) ) {
                continue;
            }

            $label = trim( (string) ( $tab['label'] ?? '' ) );
            $form_id = trim( (string) ( $tab['form_id'] ?? '' ) );
            if ( $form_id === '' ) {
                continue;
            }

            $form_html = self::renderCanonicalFormEmbed( $form_id, [ 'form_id' => $form_id ], 'metis-block-form-tabs__form', '' );
            if ( $form_html === '' ) {
                continue;
            }

            $panel_id = $group . '-panel-' . $rendered;
            $active = $rendered === 0;
            $buttons .= '<button type="button" class="metis-tab' . ( $active ? ' is-active' : '' ) . '" data-tab-group="' . metis_escape_attr( $group ) . '" data-tab="' . metis_escape_attr( $panel_id ) . '" role="tab" aria-selected="' . ( $active ? 'true' : 'false' ) . '" aria-controls="' . metis_escape_attr( $panel_id ) . '">' . metis_escape_html( $label !== '' ? $label : ( 'Form ' . (string) ( $rendered + 1 ) ) ) . '</button>';
            $panels .= '<div id="' . metis_escape_attr( $panel_id ) . '" class="metis-tab-panel' . ( $active ? ' is-active' : '' ) . '" data-tab-group="' . metis_escape_attr( $group ) . '" role="tabpanel"' . ( $active ? '' : ' hidden' ) . '>' . $form_html . '</div>';
            $rendered++;
            if ( $rendered >= 6 ) {
                break;
            }
        }

        if ( $rendered === 0 ) {
            return '';
        }

        $style_tag = '<style>'
            . '#' . $group . '{display:grid;gap:1rem;}'
            . '#' . $group . ' .metis-tabs{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;}'
            . '#' . $group . ' .metis-tab{appearance:none;border:1px solid rgba(72,91,199,.18);background:#eef2fb;color:#485bc7;border-radius:.85rem;padding:.8rem 1rem;font:inherit;font-weight:700;cursor:pointer;line-height:1.2;}'
            . '#' . $group . ' .metis-tab.is-active{background:#485bc7;color:#fff;border-color:#485bc7;}'
            . '#' . $group . ' .metis-tab-panel[hidden]{display:none !important;}'
            . '</style>';
        $script = '<script>(function(){var root=document.getElementById(' . json_encode( $group ) . ');if(!root){return;}var tabs=[].slice.call(root.querySelectorAll("[data-tab]"));var panels=[].slice.call(root.querySelectorAll(".metis-tab-panel"));function activate(btn){if(!btn){return;}var target=btn.getAttribute("data-tab")||"";tabs.forEach(function(tab){var active=tab===btn;tab.classList.toggle("is-active",active);tab.setAttribute("aria-selected",active?"true":"false");});panels.forEach(function(panel){var active=panel.id===target;panel.classList.toggle("is-active",active);panel.hidden=!active;});}tabs.forEach(function(tab){tab.addEventListener("click",function(){activate(tab);});});if(tabs.length){activate(tabs[0]);}}());</script>';
        return $style_tag . '<div id="' . metis_escape_attr( $group ) . '" class="metis-block-form-tabs metis-tab-scope"><div class="metis-tabs" data-tab-group="' . metis_escape_attr( $group ) . '" role="tablist" aria-label="Form tabs">' . $buttons . '</div>' . $panels . '</div>' . $script;
    }

    private static function renderCanonicalFormEmbed( string $form_ref, array $options, string $wrapper_class, string $fallback_html = '' ): string {
        if (
            $form_ref === \Metis\Modules\Newsletter\SubscriptionService::DEFAULT_SIGNUP_FORM_REF
            && function_exists( 'metis_newsletter_public_signup_url' )
        ) {
            $html = self::renderNewsletterSignupFormEmbed( $options );
            if ( $html !== '' ) {
                return sprintf( '<div class="%s">%s</div>', metis_escape_attr( $wrapper_class ), $html );
            }
        }

        if ( $form_ref !== '' && function_exists( 'metis_forms_render_embed' ) ) {
            $html = (string) metis_forms_render_embed( $form_ref, $options );
            if ( $html !== '' ) {
                return sprintf( '<div class="%s">%s</div>', metis_escape_attr( $wrapper_class ), self::stripInlineStyleAttributes( $html ) );
            }
        }

        return $fallback_html;
    }

    private static function renderNewsletterSignupFormEmbed( array $options ): string {
        $submit_url = function_exists( 'metis_newsletter_public_signup_url' )
            ? (string) metis_newsletter_public_signup_url()
            : '';
        if ( $submit_url === '' ) {
            return '';
        }

        $submit_label = trim( (string) ( $options['submit_label'] ?? 'Sign up' ) );
        if ( $submit_label === '' ) {
            $submit_label = 'Sign up';
        }

        return ''
            . '<form class="metis-newsletter-signup-form" data-metis-newsletter-signup-form action="' . metis_escape_url( $submit_url ) . '" method="post" novalidate>'
            . '<div class="metis-newsletter-signup-grid">'
            . '<label><span>First name</span><input class="metis-input" name="first_name" type="text" autocomplete="given-name" required></label>'
            . '<label><span>Last name</span><input class="metis-input" name="last_name" type="text" autocomplete="family-name" required></label>'
            . '<label class="metis-newsletter-signup-grid__email"><span>Email</span><input class="metis-input" name="email" type="email" autocomplete="email" required></label>'
            . '</div>'
            . '<div class="metis-newsletter-signup-alert" data-metis-newsletter-signup-alert hidden></div>'
            . '<button class="metis-btn" type="submit" data-metis-newsletter-signup-submit>' . metis_escape_html( $submit_label ) . '</button>'
            . '</form>';
    }

    private static function renderDonationFormBlock( array $data, array $style, array $context ): string {
        $campaign_id         = trim( (string) ( $data['campaign_id'] ?? '' ) );
        $preset_amounts      = (array) ( $data['preset_amounts'] ?? [ 25, 50, 100 ] );
        $allow_custom_amount = ! empty( $data['allow_custom_amount'] );
        $mode                = (string) ( $data['mode'] ?? 'both' );
        $show_name           = ! array_key_exists( 'show_name', $data ) || ! empty( $data['show_name'] );
        $show_email          = ! array_key_exists( 'show_email', $data ) || ! empty( $data['show_email'] );
        $show_phone          = ! empty( $data['show_phone'] );

        if ( function_exists( 'metis_donations_render_form_embed' ) ) {
            $html = (string) metis_donations_render_form_embed(
                [
                    'campaign_id' => $campaign_id,
                    'preset_amounts' => $preset_amounts,
                    'allow_custom_amount' => $allow_custom_amount,
                    'mode' => $mode,
                    'show_name' => $show_name,
                    'show_email' => $show_email,
                    'show_phone' => $show_phone,
                ]
            );
            if ( $html !== '' ) {
                return sprintf( '<div class="metis-block-donation-form">%s</div>', self::stripInlineStyleAttributes( $html ) );
            }
        }

        $chips = '';
        foreach ( $preset_amounts as $amount ) {
            $n = (float) $amount;
            if ( $n > 0 ) {
                $chips .= '<button type="button" class="metis-donation-chip">$' . metis_escape_html( metis_number_format( $n, 0 ) ) . '</button>';
            }
        }
        if ( $allow_custom_amount ) {
            $chips .= '<input type="number" min="1" step="1" class="metis-donation-custom" placeholder="Custom amount">';
        }

        $fields = '';
        if ( $show_name ) {
            $fields .= '<input type="text" placeholder="Full name">';
        }
        if ( $show_email ) {
            $fields .= '<input type="email" placeholder="Email">';
        }
        if ( $show_phone ) {
            $fields .= '<input type="tel" placeholder="Phone">';
        }

        return sprintf(
            '<form class="metis-block-donation-form metis-block-donation-form-placeholder" data-campaign-id="%s" data-mode="%s"><div class="metis-donation-amounts">%s</div><div class="metis-donation-fields">%s</div><button type="button" class="metis-btn metis-btn-primary">Donate</button></form>',
            metis_escape_attr( $campaign_id ),
            metis_escape_attr( $mode ),
            $chips,
            $fields
        );
    }

    private static function renderProgressBarBlock( array $data, array $style, array $context ): string {
        $campaign_id  = trim( (string) ( $data['campaign_id'] ?? ( $data['campaign_code'] ?? '' ) ) );
        $goal_amount  = (float) ( $data['goal_amount'] ?? 0 );
        $raised_total = (float) ( $data['raised_amount'] ?? 0 );

        if ( $campaign_id !== '' ) {
            $db = function_exists( 'metis_db' ) ? metis_db() : null;
            if ( $db && class_exists( '\\Metis_Tables' ) ) {
                $campaigns_table = \Metis_Tables::get( 'campaigns' );
                $tx_table        = \Metis_Tables::get( 'transactions' );
                if ( $campaigns_table !== '' && $tx_table !== '' ) {
                    $resolved_campaign = $campaign_id;
                    $exists = $db->scalar( "SELECT cid FROM {$campaigns_table} WHERE cid = %s LIMIT 1", [ $campaign_id ] );
                    if ( ( $exists === null || (string) $exists === '' ) && ctype_digit( $campaign_id ) ) {
                        $cid = $db->scalar( "SELECT cid FROM {$campaigns_table} WHERE id = %d LIMIT 1", [ (int) $campaign_id ] );
                        if ( $cid !== null && trim( (string) $cid ) !== '' ) {
                            $resolved_campaign = trim( (string) $cid );
                        }
                    }
                    $now         = \function_exists( 'metis_runtime_current_datetime' )
                        ? \metis_runtime_current_datetime()
                        : new \DateTimeImmutable( 'now', new \DateTimeZone( date_default_timezone_get() ?: 'UTC' ) );
                    $startOfYear = $now->setDate( (int) $now->format( 'Y' ), 1, 1 )->setTime( 0, 0, 0 );
                    $startNext   = $startOfYear->modify( '+1 year' );
                    $campaign_row = $db->fetchOne( "SELECT * FROM {$campaigns_table} WHERE cid = %s LIMIT 1", [ $resolved_campaign ] );
                    if ( is_array( $campaign_row ) ) {
                        if ( isset( $campaign_row['goal'] ) && is_numeric( $campaign_row['goal'] ) ) {
                            $goal_amount = (float) $campaign_row['goal'];
                        } elseif ( isset( $campaign_row['goal_amount'] ) && is_numeric( $campaign_row['goal_amount'] ) ) {
                            $goal_amount = (float) $campaign_row['goal_amount'];
                        } elseif ( ! empty( $campaign_row['goals'] ) && function_exists( 'metis_parse_goals' ) ) {
                            $year_goals = metis_parse_goals( (string) $campaign_row['goals'] );
                            $year_key = (int) $now->format( 'Y' );
                            if ( is_array( $year_goals ) && isset( $year_goals[ $year_key ] ) ) {
                                $goal_amount = (float) $year_goals[ $year_key ];
                            }
                        }
                    }
                    try {
                        $sum  = $db->scalar(
                            "SELECT COALESCE(SUM(amount),0) FROM {$tx_table} WHERE campaign_code = %s AND status IN ('completed','succeeded','paid') AND tran_date >= %s AND tran_date < %s",
                            [ $resolved_campaign, $startOfYear->format( 'Y-m-d H:i:s' ), $startNext->format( 'Y-m-d H:i:s' ) ]
                        );
                    } catch ( \Throwable $e ) {
                        $sum  = $db->scalar(
                            "SELECT COALESCE(SUM(amount),0) FROM {$tx_table} WHERE campaign_code = %s AND status IN ('completed','succeeded','paid') AND created_at >= %s AND created_at < %s",
                            [ $resolved_campaign, $startOfYear->format( 'Y-m-d H:i:s' ), $startNext->format( 'Y-m-d H:i:s' ) ]
                        );
                    }
                    if ( $sum !== null ) {
                        $raised_total = (float) $sum;
                    }
                }
            }
        }

        $percent = $goal_amount > 0 ? min( 100, max( 0, ( $raised_total / $goal_amount ) * 100 ) ) : max( 0, min( 100, (float) ( $data['percent'] ?? 0 ) ) );
        $year = \function_exists( 'metis_runtime_current_datetime' )
            ? \metis_runtime_current_datetime()->format( 'Y' )
            : ( new \DateTimeImmutable( 'now', new \DateTimeZone( date_default_timezone_get() ?: 'UTC' ) ) )->format( 'Y' );
        $percent_display = number_format( $percent, 2, '.', '' );
        $track_width = $percent_display . '%';
        $raised_text = self::formatUsdFixed( $raised_total );
        $goal_text = self::formatUsdFixed( $goal_amount );

        return sprintf(
            '<section class="metis-block-progress metis-donation-progress"%1$s>' .
                '<p class="metis-donation-progress-total">Total Donations received this year %2$s.</p>' .
                '<p class="metis-donation-progress-goal">Our %3$s goal is %4$s</p>' .
                '<div class="metis-donation-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%5$s" aria-label="%6$s">' .
                    '<span class="metis-donation-progress-fill" style="width:%7$s;"></span>' .
                    '<span class="metis-donation-progress-label">%8$s%% of %3$s Goal</span>' .
                '</div>' .
            '</section>',
            self::buildInlineStyle( $style ),
            metis_escape_html( $raised_text ),
            metis_escape_html( (string) $year ),
            metis_escape_html( $goal_text ),
            metis_escape_attr( $percent_display ),
            metis_escape_attr( $percent_display . '% of ' . $year . ' Goal' ),
            metis_escape_attr( $track_width ),
            metis_escape_html( $percent_display )
        );
    }

    private static function renderCampaignDescriptionBlock( array $data, array $style, array $context ): string {
        $campaign_id = trim( (string) ( $data['campaign_id'] ?? ( $data['campaign_code'] ?? '' ) ) );
        $title       = self::resolveDynamicShortcodes( (string) ( $data['title'] ?? '' ), $context );
        $content     = self::resolveDynamicShortcodes( (string) ( $data['content'] ?? '' ), $context );
        $image       = (string) ( $data['image'] ?? '' );
        $render_style = is_array( $style ) ? $style : [];

        if ( $campaign_id !== '' ) {
            $db = function_exists( 'metis_db' ) ? metis_db() : null;
            if ( $db && class_exists( '\\Metis_Tables' ) ) {
                $campaigns_table = \Metis_Tables::get( 'campaigns' );
                if ( $campaigns_table !== '' ) {
                    $campaign = null;
                    try {
                        $campaign = $db->fetchOne( "SELECT * FROM {$campaigns_table} WHERE cid = %s LIMIT 1", [ $campaign_id ] );
                        if ( ! is_array( $campaign ) ) {
                            $campaign = $db->fetchOne( "SELECT * FROM {$campaigns_table} WHERE campaign_code = %s LIMIT 1", [ $campaign_id ] );
                        }
                        if ( ! is_array( $campaign ) ) {
                            $campaign = $db->fetchOne( "SELECT * FROM {$campaigns_table} WHERE code = %s LIMIT 1", [ $campaign_id ] );
                        }
                    } catch ( \Throwable $e ) {
                        $campaign = null;
                    }
                    if ( ! is_array( $campaign ) && ctype_digit( $campaign_id ) ) {
                        try {
                            $campaign = $db->fetchOne( "SELECT * FROM {$campaigns_table} WHERE id = %d LIMIT 1", [ (int) $campaign_id ] );
                        } catch ( \Throwable $e ) {
                            $campaign = null;
                        }
                    }
                    if ( is_array( $campaign ) ) {
                        $title = (string) ( $campaign['cname'] ?? ( $campaign['name'] ?? ( $campaign['title'] ?? '' ) ) );
                        $content = (string) ( $campaign['cdesc'] ?? ( $campaign['description'] ?? ( $campaign['content'] ?? '' ) ) );
                        if ( $image === '' ) {
                            $image = (string) ( $campaign['image'] ?? ( $campaign['image_url'] ?? ( $campaign['featured_image'] ?? '' ) ) );
                        }
                    }
                }
            }
        }
        $title = self::resolveDynamicShortcodes( $title, $context );
        $content = self::resolveDynamicShortcodes( $content, $context );
        if ( class_exists( '\Metis\Modules\Donations\CampaignService' ) ) {
            $content = \Metis\Modules\Donations\CampaignService::normalizeDescriptionHtml( $content );
        }

        $safe_content = '';
        if ( $content !== '' ) {
            if ( function_exists( 'metis_runtime_kses_post' ) ) {
                $safe_content = (string) metis_runtime_kses_post( $content );
            } else {
                $safe_content = strip_tags(
                    $content,
                    '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><a><span><div>'
                );
            }
        }
        // Donation description should auto-grow to campaign content on public render.
        // Preserve explicit fluid-grid sizing only when positioned by fluid coordinates.
        if ( ! self::hasFluidCoordinates( $render_style ) ) {
            if ( isset( $render_style['height'] ) && trim( (string) $render_style['height'] ) !== '' ) {
                if ( ! isset( $render_style['min_height'] ) || trim( (string) $render_style['min_height'] ) === '' ) {
                    $render_style['min_height'] = (string) $render_style['height'];
                }
            }
            unset( $render_style['height'] );
            unset( $render_style['max_height'] );
            unset( $render_style['scrollable'] );
        }

        $media = $image !== '' ? '<img src="' . metis_escape_url( $image ) . '" alt="" class="metis-campaign-image">' : '';
        return sprintf(
            '<section class="metis-block-campaign-description"%s>%s%s%s</section>',
            self::buildInlineStyle( $render_style, self::hasFluidCoordinates( $render_style ) ? '' : 'height:auto;overflow:visible;' ),
            $title !== '' ? '<h3>' . metis_escape_html( $title ) . '</h3>' : '',
            $safe_content !== '' ? '<div class="metis-campaign-content">' . $safe_content . '</div>' : '',
            $media
        );
    }

    private static function renderDonationDescription( array $data, array $style, array $context ): string {
        return self::renderCampaignDescriptionBlock( $data, $style, $context );
    }

    private static function renderDonationDescriptionBlock( array $data, array $style, array $context ): string {
        return self::renderCampaignDescriptionBlock( $data, $style, $context );
    }

    private static function renderDonationGoalSummaryBlock( array $data, array $style, array $context ): string {
        return self::renderProgressBarBlock( $data, $style, $context );
    }

    private static function renderTestimoniesBlock( array $data, array $style, array $context ): string {
        if ( ! class_exists( '\Metis\Modules\Testimonies\Repository' ) ) {
            return '';
        }

        $categoryIds = array_values( array_unique( array_filter( array_map( 'intval', is_array( $data['category_ids'] ?? null ) ? $data['category_ids'] : [] ), static fn( int $id ): bool => $id > 0 ) ) );
        $limit = max( 1, min( 24, (int) ( $data['limit'] ?? 6 ) ) );
        $layout = metis_key_clean( (string) ( $data['layout'] ?? 'grid' ) );
        if ( ! in_array( $layout, [ 'grid', 'list', 'rotator' ], true ) ) {
            $layout = 'grid';
        }
        $showCategory = ! array_key_exists( 'show_category', $data ) || ! empty( $data['show_category'] );
        $rows = \Metis\Modules\Testimonies\Repository::publicTestimonials(
            [
                'category_ids' => $categoryIds,
                'limit' => $limit,
                'featured_only' => ! empty( $data['featured_only'] ),
            ]
        );

        if ( $rows === [] ) {
            $emptyMessage = trim( (string) ( $data['empty_message'] ?? '' ) );
            if ( $emptyMessage === '' ) {
                $emptyMessage = 'No testimonies available yet.';
            }
            return sprintf(
                '<section class="%s"%s><p class="metis-block-testimonies-empty">%s</p></section>',
                metis_escape_attr( self::buildClasses( 'metis-block-testimonies metis-block-testimonies--' . $layout, $style ) ),
                self::buildInlineStyle( $style ),
                metis_escape_html( $emptyMessage )
            );
        }

        $cards = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $speakerLine = trim( (string) ( $row['speaker_name'] ?? '' ) );
            $titleParts = array_values( array_filter( [
                trim( (string) ( $row['speaker_title'] ?? '' ) ),
                trim( (string) ( $row['speaker_company'] ?? '' ) ),
            ] ) );
            $categoryLine = $showCategory ? implode( ', ', array_map( 'strval', (array) ( $row['categories'] ?? [] ) ) ) : '';
            $cards[] = '<article class="metis-testimony-card">'
                . '<blockquote class="metis-testimony-card-quote">“' . metis_escape_html( (string) ( $row['quote_text'] ?? '' ) ) . '”</blockquote>'
                . '<div class="metis-testimony-card-speaker"><strong>' . metis_escape_html( $speakerLine ) . '</strong>'
                . ( $titleParts !== [] ? '<span>' . metis_escape_html( implode( ' • ', $titleParts ) ) . '</span>' : '' )
                . ( $categoryLine !== '' ? '<small>' . metis_escape_html( $categoryLine ) . '</small>' : '' )
                . '</div>'
                . '</article>';
        }

        if ( $layout === 'rotator' ) {
            self::$testimonyRotatorSequence++;
            $rotatorId = 'metis-testimonies-rotator-' . self::$testimonyRotatorSequence;
            $styleTag = '<style>#' . $rotatorId . '{display:block;}#' . $rotatorId . ' .metis-testimonies-rotator{position:relative;min-height:14rem;}#' . $rotatorId . ' .metis-testimony-slide{display:block;}#' . $rotatorId . ' .metis-testimony-card{max-width:52rem;margin:0 auto;padding:1.75rem;border:1px solid rgba(15,23,42,.12);border-radius:1.25rem;background:#fff;box-shadow:0 18px 34px rgba(15,23,42,.08);}#' . $rotatorId . ' .metis-testimony-card-quote{margin:0 0 1rem;font-size:1.125rem;line-height:1.7;}#' . $rotatorId . ' .metis-testimony-card-speaker{display:flex;flex-direction:column;gap:.35rem;}#' . $rotatorId . ' .metis-testimony-card-speaker strong{font-size:1rem;}#' . $rotatorId . ' .metis-testimony-card-speaker span,#' . $rotatorId . ' .metis-testimony-card-speaker small{color:#5a6475;}#' . $rotatorId . ' .metis-testimonies-rotator-controls{display:flex;align-items:center;justify-content:center;gap:.75rem;margin-top:1rem;flex-wrap:wrap;}#' . $rotatorId . ' .metis-testimonies-rotator-nav{appearance:none;border:1px solid rgba(15,23,42,.14);background:#fff;color:#1a1f2b;border-radius:999px;padding:.65rem 1rem;font:inherit;font-weight:700;cursor:pointer;}#' . $rotatorId . ' .metis-testimonies-rotator-dots{display:flex;align-items:center;gap:.5rem;}#' . $rotatorId . ' .metis-testimonies-rotator-dot{appearance:none;width:.8rem;height:.8rem;border:0;border-radius:999px;background:rgba(72,91,199,.22);cursor:pointer;padding:0;}#' . $rotatorId . ' .metis-testimonies-rotator-dot.is-active{background:#485bc7;}@media (max-width:640px){#' . $rotatorId . ' .metis-testimony-card{padding:1.25rem;}#' . $rotatorId . ' .metis-testimonies-rotator-nav{width:100%;}} </style>';
            $slides = '';
            foreach ( $cards as $index => $cardHtml ) {
                $slides .= sprintf(
                    '<div class="metis-testimony-slide"%s data-rotator-slide="%d">%s</div>',
                    $index === 0 ? '' : ' hidden',
                    $index,
                    $cardHtml
                );
            }
            $controls = '';
            $script = '';
            if ( count( $cards ) > 1 ) {
                $dots = '';
                foreach ( array_keys( $cards ) as $index ) {
                    $dots .= sprintf(
                        '<button type="button" class="metis-testimonies-rotator-dot%s" data-rotator-dot="%d" aria-label="Show testimony %d"></button>',
                        $index === 0 ? ' is-active' : '',
                        $index,
                        $index + 1
                    );
                }
                $controls = '<div class="metis-testimonies-rotator-controls">'
                    . '<button type="button" class="metis-testimonies-rotator-nav" data-rotator-prev aria-label="Previous testimony">Previous</button>'
                    . '<div class="metis-testimonies-rotator-dots" role="tablist" aria-label="Testimony rotation">' . $dots . '</div>'
                    . '<button type="button" class="metis-testimonies-rotator-nav" data-rotator-next aria-label="Next testimony">Next</button>'
                    . '</div>';
                $script = '<script>(function(){var root=document.getElementById(' . json_encode( $rotatorId ) . ');if(!root){return;}var slides=[].slice.call(root.querySelectorAll("[data-rotator-slide]"));var dots=[].slice.call(root.querySelectorAll("[data-rotator-dot]"));var prev=root.querySelector("[data-rotator-prev]");var next=root.querySelector("[data-rotator-next]");if(!slides.length){return;}var index=0;var timer=0;function show(i){index=((i%slides.length)+slides.length)%slides.length;slides.forEach(function(slide,slideIndex){var active=slideIndex===index;slide.hidden=!active;slide.setAttribute("aria-hidden",active?"false":"true");});dots.forEach(function(dot,dotIndex){var active=dotIndex===index;dot.classList.toggle("is-active",active);dot.setAttribute("aria-selected",active?"true":"false");});}function stop(){if(timer){window.clearInterval(timer);timer=0;}}function start(){if(slides.length<2){return;}stop();timer=window.setInterval(function(){show(index+1);},5000);}if(prev){prev.addEventListener("click",function(){stop();show(index-1);start();});}if(next){next.addEventListener("click",function(){stop();show(index+1);start();});}dots.forEach(function(dot){dot.addEventListener("click",function(){var target=parseInt(dot.getAttribute("data-rotator-dot")||"0",10)||0;stop();show(target);start();});});root.addEventListener("mouseenter",stop);root.addEventListener("mouseleave",start);root.addEventListener("focusin",stop);root.addEventListener("focusout",function(event){if(root.contains(event.relatedTarget)){return;}start();});show(0);start();}());</script>';
            }
            return sprintf(
                '%s<section id="%s" class="%s"%s><div class="metis-testimonies-rotator">%s</div>%s%s</section>',
                $styleTag,
                metis_escape_attr( $rotatorId ),
                metis_escape_attr( self::buildClasses( 'metis-block-testimonies metis-block-testimonies--rotator', $style ) ),
                self::buildInlineStyle( $style ),
                $slides,
                $controls,
                $script
            );
        }

        return sprintf(
            '<section class="%s"%s><div class="metis-testimonies-grid">%s</div></section>',
            metis_escape_attr( self::buildClasses( 'metis-block-testimonies metis-block-testimonies--' . $layout, $style ) ),
            self::buildInlineStyle( $style ),
            implode( '', $cards )
        );
    }

    private static function renderDonorWallBlock( array $data, array $style, array $context ): string {
        $limit = max( 1, min( 100, (int) ( $data['limit'] ?? 20 ) ) );
        $rows  = [];

        $db = function_exists( 'metis_db' ) ? metis_db() : null;
        if ( $db && class_exists( '\\Metis_Tables' ) ) {
            $tx_table = \Metis_Tables::get( 'transactions' );
            $d_table  = \Metis_Tables::get( 'donors' );
            if ( $tx_table !== '' && $d_table !== '' ) {
                $rows = $db->fetchAll(
                    "SELECT d.did, d.first_name, d.last_name, d.email, SUM(t.amount) AS total
                     FROM {$tx_table} t
                     LEFT JOIN {$d_table} d ON d.did = t.did
                     WHERE t.status IN ('completed','succeeded','paid')
                     GROUP BY d.did, d.first_name, d.last_name, d.email
                     ORDER BY total DESC
                     LIMIT {$limit}"
                ) ?: [];
            }
        }

        if ( empty( $rows ) ) {
            return sprintf( '<div class="metis-block-donor-wall"%s><p>No donor activity yet.</p></div>', self::buildInlineStyle( $style ) );
        }

        $items = '';
        foreach ( $rows as $row ) {
            $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
            if ( $name === '' ) {
                $name = (string) ( $row->email ?? 'Anonymous donor' );
            }
            $items .= '<li><span>' . metis_escape_html( $name ) . '</span><strong>' . metis_escape_html( self::formatCurrency( (float) ( $row->total ?? 0 ) ) ) . '</strong></li>';
        }

        return sprintf( '<div class="metis-block-donor-wall"%s><ul>%s</ul></div>', self::buildInlineStyle( $style ), $items );
    }

    private static function renderImpactMetricsBlock( array $data, array $style, array $context ): string {
        $items = (array) ( $data['items'] ?? [] );
        if ( $items === [] ) {
            return sprintf( '<div class="metis-block-impact-metrics"%s><p>No impact metrics configured.</p></div>', self::buildInlineStyle( $style ) );
        }

        $html = '';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $label = trim( (string) ( $item['label'] ?? '' ) );
            $value = trim( (string) ( $item['value'] ?? '' ) );
            if ( $label === '' && $value === '' ) {
                continue;
            }
            $html .= '<article><strong>' . metis_escape_html( $value ) . '</strong><span>' . metis_escape_html( $label ) . '</span></article>';
        }

        if ( $html === '' ) {
            $html = '<p>No impact metrics configured.</p>';
        }

        return sprintf( '<div class="metis-block-impact-metrics"%s>%s</div>', self::buildInlineStyle( $style ), $html );
    }

    private static function renderCountdownBlock( array $data, array $style, array $context ): string {
        $start_at = trim( (string) ( $data['start_at'] ?? '' ) );
        $end_at = trim( (string) ( $data['end_at'] ?? '' ) );
        if ( $end_at === '' ) {
            return '';
        }
        $timezone = trim( (string) ( $data['timezone'] ?? ( \function_exists( 'metis_runtime_timezone_name' ) ? \metis_runtime_timezone_name() : (string) date_default_timezone_get() ) ) );
        return sprintf(
            '<div class="metis-block-countdown" data-start-at="%s" data-end-at="%s" data-timezone="%s"%s></div>',
            metis_escape_attr( $start_at ),
            metis_escape_attr( $end_at ),
            metis_escape_attr( $timezone ),
            self::buildInlineStyle( $style )
        );
    }

    private static function renderAnchorBlock( array $data, array $style, array $context ): string {
        $anchor_id = trim( (string) ( $data['anchor_id'] ?? '' ) );
        if ( $anchor_id === '' ) {
            return '';
        }
        return sprintf( '<span id="%s" class="metis-block-anchor"%s></span>', metis_escape_attr( $anchor_id ), self::buildInlineStyle( $style ) );
    }

    /**
     * @param array<string,mixed> $style
     * @param array<string,mixed> $context
     */
    private static function styleForDevice( array $style, array $context ): array {
        $device = strtolower( (string) ( $context['preview_device'] ?? '' ) );
        if ( ! in_array( $device, [ 'desktop', 'tablet', 'mobile' ], true ) ) {
            return $style;
        }
        $responsive = isset( $style['responsive'] ) && is_array( $style['responsive'] ) ? $style['responsive'] : [];
        $devices = isset( $responsive['devices'] ) && is_array( $responsive['devices'] ) ? $responsive['devices'] : $responsive;
        $device_style = isset( $devices[ $device ] ) && is_array( $devices[ $device ] ) ? $devices[ $device ] : [];
        if ( $device_style === [] ) {
            return $style;
        }

        $style['spacing'] = isset( $style['spacing'] ) && is_array( $style['spacing'] ) ? $style['spacing'] : [];
        $style['typography'] = isset( $style['typography'] ) && is_array( $style['typography'] ) ? $style['typography'] : [];

        $align = isset( $device_style['align'] ) ? trim( (string) $device_style['align'] ) : '';
        if ( $align !== '' ) {
            $style['horizontal_align'] = $align;
            $style['align'] = $align;
            $style['text_align'] = $align;
        }

        $font_size = isset( $device_style['font_size'] ) ? trim( (string) $device_style['font_size'] ) : '';
        if ( $font_size !== '' ) {
            $style['font_size'] = $font_size;
            $style['typography']['size'] = $font_size;
        }

        $padding = isset( $device_style['padding'] ) ? trim( (string) $device_style['padding'] ) : '';
        if ( $padding !== '' ) {
            $style['spacing']['padding'] = $padding;
        }

        $margin = isset( $device_style['margin'] ) ? trim( (string) $device_style['margin'] ) : '';
        if ( $margin !== '' ) {
            $style['spacing']['margin'] = $margin;
        }

        return $style;
    }

    /**
     * @param array<string,mixed> $style
     * @param array<string,mixed> $context
     */
    private static function isHiddenForDevice( array $style, array $context ): bool {
        $device = strtolower( (string) ( $context['preview_device'] ?? '' ) );
        if ( ! in_array( $device, [ 'desktop', 'tablet', 'mobile' ], true ) ) {
            return false;
        }

        $visibility = isset( $style['visibility'] ) && is_array( $style['visibility'] ) ? $style['visibility'] : [];
        $devices = isset( $visibility['devices'] ) && is_array( $visibility['devices'] ) ? $visibility['devices'] : [];
        $device_visibility = isset( $devices[ $device ] ) ? strtolower( trim( (string) $devices[ $device ] ) ) : '';
        if ( $device_visibility === 'hide' ) {
            return true;
        }
        if ( $device_visibility === 'show' ) {
            return false;
        }

        $mode = strtolower( trim( (string) ( $visibility['mode'] ?? 'always' ) ) );
        if ( $mode === 'hidden' ) {
            return true;
        }
        if ( $mode === 'hidden_desktop' && $device === 'desktop' ) {
            return true;
        }
        if ( $mode === 'hidden_tablet' && $device === 'tablet' ) {
            return true;
        }
        if ( $mode === 'hidden_mobile' && $device === 'mobile' ) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string,mixed> $style
     * @param array<string,mixed> $context
     */
    private static function isHiddenForPageRule( array $style, array $context ): bool {
        $visibility = isset( $style['visibility'] ) && is_array( $style['visibility'] ) ? $style['visibility'] : [];
        if ( $visibility === [] ) {
            return false;
        }

        $path = trim( (string) ( $context['path'] ?? '' ) );
        if ( $path === '' || $path === '/editor/preview' ) {
            return false;
        }

        $normalized_path = '/' . trim( $path, '/' );
        if ( $normalized_path === '//' ) {
            $normalized_path = '/';
        }
        $slug = trim( basename( $normalized_path ) );

        $homepage_rule = strtolower( trim( (string) ( $visibility['homepage'] ?? '' ) ) );
        if ( $homepage_rule === 'hide' && ( $normalized_path === '/' || $slug === 'home' || $slug === 'homepage' ) ) {
            return true;
        }

        $raw_suppress = $visibility['suppress_pages'] ?? [];
        $list = [];
        if ( is_string( $raw_suppress ) ) {
            $list = preg_split( '/[\r\n,]+/', $raw_suppress ) ?: [];
        } elseif ( is_array( $raw_suppress ) ) {
            $list = $raw_suppress;
        }
        foreach ( $list as $entry ) {
            $item = strtolower( trim( (string) $entry ) );
            if ( $item === '' ) {
                continue;
            }
            $item = '/' . trim( $item, '/' );
            $item_slug = trim( basename( $item ) );
            if ( strtolower( $normalized_path ) === $item || strtolower( '/' . $slug ) === $item || strtolower( $slug ) === $item_slug ) {
                return true;
            }
        }
        return false;
    }

    private static function buildInlineStyle( array $style, string $additional = '' ): string {
        $parts = [];
        $spacing = ( isset( $style['spacing'] ) && is_array( $style['spacing'] ) ) ? $style['spacing'] : [];
        $color   = ( isset( $style['color'] ) && is_array( $style['color'] ) ) ? $style['color'] : [];
        $typography = ( isset( $style['typography'] ) && is_array( $style['typography'] ) ) ? $style['typography'] : [];
        $fluid_active = ! empty( $style['__fluid_active'] );
        $fluid_auto_height = ! empty( $style['__fluid_auto_height'] );
        $fluid_no_clip = ! empty( $style['__fluid_no_clip'] );

        self::pushStyle( $parts, 'margin', $spacing['margin'] ?? ( $style['margin'] ?? null ) );
        self::pushStyle( $parts, 'padding', $spacing['padding'] ?? ( $style['padding'] ?? null ) );
        self::pushStyle( $parts, 'color', $color['text'] ?? ( $style['text_color'] ?? null ) );
        self::pushStyle( $parts, 'background-color', $color['background'] ?? ( $style['background'] ?? ( $style['background_color'] ?? null ) ) );
        $border = $style['border'] ?? null;
        if ( ( $border === null || (string) $border === '' ) && isset( $style['border_width'] ) ) {
            $border_width = (int) $style['border_width'];
            if ( $border_width > 0 ) {
                $border_style = (string) ( $style['border_style'] ?? 'solid' );
                if ( ! in_array( $border_style, [ 'solid', 'dashed', 'dotted', 'double' ], true ) ) {
                    $border_style = 'solid';
                }
                $border_color = (string) ( $style['border_color'] ?? '#dbe2ee' );
                $border = $border_width . 'px ' . $border_style . ' ' . $border_color;
            }
        }
        self::pushStyle( $parts, 'border', $border );
        self::pushStyle( $parts, 'border-radius', $style['border_radius'] ?? ( $style['borderRadius'] ?? null ) );
        self::pushStyle( $parts, 'box-shadow', $style['box_shadow'] ?? ( $style['shadow'] ?? null ) );
        self::pushStyle( $parts, 'opacity', $style['opacity'] ?? null );
        self::pushStyle( $parts, 'text-align', $style['text_align'] ?? ( $style['textAlign'] ?? ( $style['align'] ?? ( $style['horizontal_align'] ?? ( $style['horizontalAlign'] ?? null ) ) ) ) );
        self::pushStyle( $parts, 'font-size', $typography['size'] ?? ( $style['font_size'] ?? ( $style['fontSize'] ?? null ) ) );
        self::pushStyle( $parts, 'font-weight', $typography['weight'] ?? ( $style['font_weight'] ?? null ) );
        self::pushStyle( $parts, 'line-height', $typography['line_height'] ?? ( $style['line_height'] ?? null ) );
        self::pushStyle( $parts, 'letter-spacing', $typography['letter_spacing'] ?? ( $style['letter_spacing'] ?? null ) );
        self::pushStyle( $parts, 'text-transform', $typography['text_transform'] ?? ( $style['text_transform'] ?? null ) );
        self::pushStyle( $parts, 'width', $style['width'] ?? null );
        self::pushStyle( $parts, 'max-width', $style['max_width'] ?? ( $style['maxWidth'] ?? null ) );
        self::pushStyle( $parts, 'min-width', $style['min_width'] ?? ( $style['minWidth'] ?? null ) );
        self::pushStyle( $parts, 'height', $style['height'] ?? null );
        self::pushStyle( $parts, 'min-height', $style['min_height'] ?? ( $style['minHeight'] ?? null ) );
        self::pushStyle( $parts, 'max-height', $style['max_height'] ?? ( $style['maxHeight'] ?? null ) );
        self::pushStyle( $parts, 'display', $style['display'] ?? null );
        self::pushStyle( $parts, 'justify-content', $style['justify_content'] ?? ( $style['justifyContent'] ?? null ) );
        self::pushStyle( $parts, 'align-items', $style['align_items'] ?? ( $style['alignItems'] ?? null ) );
        if ( ! empty( $style['scrollable'] ) ) {
            $parts[] = 'overflow:auto';
        }
        $vertical_align = strtolower( (string) ( $style['vertical_align'] ?? ( $style['verticalAlign'] ?? '' ) ) );
        if ( $vertical_align !== '' ) {
            if ( $vertical_align === 'center' ) {
                $parts[] = 'display:flex';
                $parts[] = 'flex-direction:column';
                $parts[] = 'justify-content:center';
            } elseif ( $vertical_align === 'bottom' ) {
                $parts[] = 'display:flex';
                $parts[] = 'flex-direction:column';
                $parts[] = 'justify-content:flex-end';
            } else {
                $parts[] = 'display:flex';
                $parts[] = 'flex-direction:column';
                $parts[] = 'justify-content:flex-start';
            }
        }
        self::pushStyle( $parts, 'gap', $style['gap'] ?? null );

        $block_width_mode = strtolower( trim( (string) ( $style['block_width_mode'] ?? '' ) ) );
        if ( $block_width_mode === 'fixed' ) {
            $fixed_width = trim( (string) ( $style['block_fixed_width'] ?? ( $style['width'] ?? '' ) ) );
            if ( $fixed_width !== '' ) {
                self::pushStyle( $parts, 'width', 'min(100%,' . $fixed_width . ')' );
                self::pushStyle( $parts, 'max-width', $fixed_width );
                self::pushStyle( $parts, 'margin-left', 'auto' );
                self::pushStyle( $parts, 'margin-right', 'auto' );
            }
        }
        if ( $fluid_active ) {
            $cols = (int) ( $style['grid_cols'] ?? 48 );
            if ( $cols < 12 || $cols > 240 ) {
                $cols = 48;
            }
            $gx = max( 0, (int) ( $style['grid_x'] ?? 0 ) );
            $gy = max( 0, (int) ( $style['grid_y'] ?? 0 ) );
            $gw = max( 1, (int) ( $style['grid_w'] ?? 8 ) );
            $gh = max( 1, (int) ( $style['grid_h'] ?? 4 ) );
            if ( $gx + $gw > $cols ) {
                $gx = max( 0, $cols - $gw );
            }
            $parts[] = 'position:absolute';
            $parts[] = 'left:' . (string) ( ( $gx / $cols ) * 100 ) . '%';
            $parts[] = 'top:' . (string) ( $gy * 12 ) . 'px';
            $parts[] = 'width:' . (string) ( ( $gw / $cols ) * 100 ) . '%';
            if ( ! $fluid_auto_height ) {
                $parts[] = 'height:' . (string) ( $gh * 12 ) . 'px';
            }
            $parts[] = $fluid_no_clip ? 'overflow:visible' : 'overflow:hidden';
        }
        if ( $additional !== '' ) {
            foreach ( explode( ';', $additional ) as $additional_part ) {
                $trimmed = trim( (string) $additional_part );
                if ( $trimmed !== '' ) {
                    $parts[] = $trimmed;
                }
            }
        }

        return $parts !== [] ? ' style="' . metis_escape_attr( implode( ';', $parts ) ) . '"' : '';
    }

    private static function hasFluidCoordinates( array $style ): bool {
        if ( ! array_key_exists( 'grid_x', $style ) && ! array_key_exists( 'grid_y', $style ) ) {
            return false;
        }
        $x = isset( $style['grid_x'] ) ? trim( (string) $style['grid_x'] ) : '';
        $y = isset( $style['grid_y'] ) ? trim( (string) $style['grid_y'] ) : '';
        if ( $x === '' && $y === '' ) {
            return false;
        }
        return is_numeric( $x ) || is_numeric( $y );
    }

    private static function pushStyle( array &$parts, string $property, $value ): void {
        if ( ! is_scalar( $value ) ) {
            return;
        }
        $normalized = trim( (string) $value );
        if ( $normalized === '' ) {
            return;
        }
        $normalized = str_replace( [ "\n", "\r", ';', '{', '}', '<', '>' ], '', $normalized );
        $normalized = trim( $normalized );
        if ( $normalized === '' ) {
            return;
        }
        if ( in_array( $property, [ 'padding', 'margin', 'border-radius', 'font-size', 'min-height', 'max-height', 'height', 'min-width', 'max-width', 'width', 'gap' ], true ) ) {
            $normalized = self::normalizeCssUnitValue( $normalized );
        } elseif ( $property === 'border' ) {
            $normalized = self::normalizeCssBorderValue( $normalized );
        }
        $parts[] = $property . ':' . $normalized;
    }

    private static function normalizeCssUnitValue( string $value ): string {
        $tokens = preg_split( '/\s+/', trim( $value ) ) ?: [];
        $normalized = array_map(
            static function ( string $token ): string {
                $t = trim( $token );
                if ( preg_match( '/^-?\d+(\.\d+)?$/', $t ) ) {
                    return $t . 'px';
                }
                return $t;
            },
            $tokens
        );
        return trim( implode( ' ', $normalized ) );
    }

    private static function normalizeCssBorderValue( string $value ): string {
        $text = trim( $value );
        if ( preg_match( '/^\d+(\.\d+)?$/', $text ) ) {
            return $text . 'px solid #dbe2ee';
        }
        $parts = preg_split( '/\s+/', $text ) ?: [];
        if ( isset( $parts[0] ) && preg_match( '/^\d+(\.\d+)?$/', $parts[0] ) ) {
            $parts[0] .= 'px';
        }
        return trim( implode( ' ', $parts ) );
    }

    private static function sanitizeCssLength( string $value, string $fallback ): string {
        $text = trim( $value );
        if ( $text === '' ) {
            return $fallback;
        }
        $text = preg_replace( '/[^0-9\.\-\s%a-z]/i', '', $text ) ?? '';
        $text = trim( $text );
        if ( $text === '' ) {
            return $fallback;
        }
        return self::normalizeCssUnitValue( $text );
    }

    private static function sanitizeCssBox( string $value, string $fallback ): string {
        $text = trim( $value );
        if ( $text === '' ) {
            return $fallback;
        }
        $text = preg_replace( '/[^0-9\.\-\s%a-z]/i', '', $text ) ?? '';
        $text = trim( $text );
        if ( $text === '' ) {
            return $fallback;
        }
        return self::normalizeCssUnitValue( $text );
    }

    private static function sanitizeCssColor( string $value, string $fallback ): string {
        $text = trim( $value );
        if ( $text === '' ) {
            return $fallback;
        }
        if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $text ) ) {
            return strtolower( $text );
        }
        if ( preg_match( '/^rgba?\([\d\.\s,%]+\)$/i', $text ) ) {
            return $text;
        }
        return $fallback;
    }

    private static function buttonGroupCssTag(): string {
        static $printed = false;
        if ( $printed ) {
            return '';
        }
        $printed = true;
        return '<style>'
            . '.metis-block-button-group .metis-btn-item{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;transition:transform 150ms ease,background-color 150ms ease,color 150ms ease,box-shadow 150ms ease;background:var(--metis-btn-bg,#485bc7);color:var(--metis-btn-color,#fff);padding:var(--metis-btn-padding,10px 14px);border-radius:var(--metis-btn-radius,8px);font-size:var(--metis-btn-size,16px);line-height:1.2;border:0;}'
            . '.metis-block-button-group .metis-btn-item:hover{background:var(--metis-btn-bg-hover,var(--metis-btn-bg,#485bc7));color:var(--metis-btn-color-hover,var(--metis-btn-color,#fff));}'
            . '.metis-block-button-group .metis-btn-item.metis-btn-anim-lift:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(15,23,42,.18);}'
            . '.metis-block-button-group .metis-btn-item.metis-btn-anim-pulse:hover{animation:metis-btn-pulse 450ms ease;}'
            . '.metis-block-button-group .metis-btn-item.metis-btn-anim-glow:hover{box-shadow:0 0 0 3px rgba(72,91,199,.2),0 10px 20px rgba(72,91,199,.25);}'
            . '@keyframes metis-btn-pulse{0%{transform:scale(1)}50%{transform:scale(1.04)}100%{transform:scale(1)}}'
            . '</style>';
    }

    private static function buildClasses( string $base, array $style ): string {
        $classes = [ $base ];
        if ( ! empty( $style['align'] ) ) {
            $classes[] = 'align-' . $style['align'];
        }
        $visibility = isset( $style['visibility'] ) && is_array( $style['visibility'] ) ? $style['visibility'] : [];
        $mode = strtolower( trim( (string) ( $visibility['mode'] ?? '' ) ) );
        if ( $mode === 'hidden' ) {
            $classes[] = 'metis-hide-all';
        } elseif ( $mode === 'hidden_desktop' ) {
            $classes[] = 'metis-hide-desktop';
        } elseif ( $mode === 'hidden_tablet' ) {
            $classes[] = 'metis-hide-tablet';
        } elseif ( $mode === 'hidden_mobile' ) {
            $classes[] = 'metis-hide-mobile';
        }
        $devices = isset( $visibility['devices'] ) && is_array( $visibility['devices'] ) ? $visibility['devices'] : [];
        if ( isset( $devices['desktop'] ) && strtolower( trim( (string) $devices['desktop'] ) ) === 'hide' ) {
            $classes[] = 'metis-hide-desktop';
        }
        if ( isset( $devices['tablet'] ) && strtolower( trim( (string) $devices['tablet'] ) ) === 'hide' ) {
            $classes[] = 'metis-hide-tablet';
        }
        if ( isset( $devices['mobile'] ) && strtolower( trim( (string) $devices['mobile'] ) ) === 'hide' ) {
            $classes[] = 'metis-hide-mobile';
        }
        return implode( ' ', $classes );
    }

    private static function formatCurrency( float $value ): string {
        if ( function_exists( 'metis_finance_currency' ) ) {
            return (string) metis_finance_currency( $value );
        }
        return '$' . number_format( $value, 2 );
    }

    private static function formatUsdFixed( float $value ): string {
        return '$' . number_format( $value, 2, '.', ',' );
    }

    private static function renderUnknownBlock( string $type ): string {
        return self::shouldEmitDebugComments()
            ? sprintf( '<!-- Unknown block type: %s -->', metis_escape_html( $type ) )
            : '';
    }

    private static function renderInvalidBlock( string $type, array $errors ): string {
        return self::shouldEmitDebugComments()
            ? sprintf( '<!-- Invalid block %s: %s -->', metis_escape_html( $type ), metis_escape_html( implode( ', ', $errors ) ) )
            : '';
    }

    private static function shouldEmitDebugComments(): bool {
        return defined( 'METIS_DEBUG' ) && METIS_DEBUG;
    }

    private static function stripInlineStyleAttributes( string $html ): string {
        $clean = preg_replace( '/\sstyle\s*=\s*"[^"]*"/i', '', $html );
        $clean = preg_replace( "/\sstyle\s*=\s*'[^']*'/i", '', (string) $clean );
        $clean = preg_replace( '/\sbgcolor\s*=\s*"[^"]*"/i', '', (string) $clean );
        $clean = preg_replace( "/\sbgcolor\s*=\s*'[^']*'/i", '', (string) $clean );
        return is_string( $clean ) ? $clean : $html;
    }

    private static function renderGenericBlock( string $type, array $data, array $style ): string {
        $parts = [];
        if ( ! empty( $data['title'] ) ) {
            $parts[] = '<h3>' . metis_escape_html( self::resolveDynamicShortcodes( (string) $data['title'], [] ) ) . '</h3>';
        }
        if ( ! empty( $data['content'] ) ) {
            $parts[] = function_exists( 'metis_runtime_kses_post' )
                ? metis_runtime_kses_post( self::resolveDynamicShortcodes( (string) $data['content'], [] ) )
                : strip_tags( self::resolveDynamicShortcodes( (string) $data['content'], [] ), '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><a><span><div>' );
        }
        if ( ! empty( $data['blocks'] ) && is_array( $data['blocks'] ) ) {
            $parts[] = self::renderBlocks( $data['blocks'] );
        }
        if ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
            $items = [];
            foreach ( $data['items'] as $item ) {
                if ( is_array( $item ) ) {
                    $label = trim( (string) ( $item['label'] ?? $item['title'] ?? '' ) );
                    if ( $label !== '' ) {
                        $items[] = '<li>' . metis_escape_html( $label ) . '</li>';
                    }
                }
            }
            if ( $items !== [] ) {
                $parts[] = '<ul>' . implode( '', $items ) . '</ul>';
            }
        }
        if ( $parts === [] ) {
            return sprintf( '<!-- Block type "%s" has no renderer -->', metis_escape_html( $type ) );
        }
        return sprintf(
            '<section class="metis-block-generic metis-block-%s"%s>%s</section>',
            metis_escape_attr( str_replace( '_', '-', $type ) ),
            self::buildInlineStyle( $style ),
            implode( '', $parts )
        );
    }

    private static function normalizeIncomingBlock( array $block ): array {
        $type = '';
        if ( isset( $block['type'] ) && is_scalar( $block['type'] ) ) {
            $type = trim( (string) $block['type'] );
        } elseif ( isset( $block['block_type'] ) && is_scalar( $block['block_type'] ) ) {
            $type = trim( (string) $block['block_type'] );
        } elseif ( isset( $block['name'] ) && is_scalar( $block['name'] ) ) {
            $type = trim( (string) $block['name'] );
        }
        $type = self::canonicalType( $type );

        $data = [];
        if ( isset( $block['data'] ) && is_array( $block['data'] ) ) {
            $data = $block['data'];
        } elseif ( isset( $block['props'] ) && is_array( $block['props'] ) ) {
            $data = $block['props'];
        } elseif ( isset( $block['content'] ) && is_array( $block['content'] ) ) {
            $data = $block['content'];
        }

        $style = [];
        if ( isset( $block['style'] ) && is_array( $block['style'] ) ) {
            $style = $block['style'];
        } elseif ( isset( $block['styles'] ) && is_array( $block['styles'] ) ) {
            $style = $block['styles'];
        }

        if ( $type !== '' ) {
            $def = BlockRegistry::get( $type );
            $schema = ( $def !== null && isset( $def['schema'] ) && is_array( $def['schema'] ) ) ? $def['schema'] : [];
            foreach ( array_keys( $schema ) as $field ) {
                if ( ! array_key_exists( $field, $data ) && array_key_exists( $field, $block ) ) {
                    $value = $block[ $field ];
                    if ( is_scalar( $value ) || is_array( $value ) || $value === null ) {
                        $data[ $field ] = $value;
                    }
                }
            }
        }

        if ( ! isset( $data['content'] ) && isset( $block['content'] ) && is_scalar( $block['content'] ) ) {
            $data['content'] = (string) $block['content'];
        }
        if ( ! isset( $data['anchor_id'] ) && isset( $style['anchor_id'] ) && is_scalar( $style['anchor_id'] ) ) {
            $data['anchor_id'] = (string) $style['anchor_id'];
        }

        $normalized = $block;
        $normalized['type'] = $type;
        $normalized['data'] = $data;
        $normalized['style'] = $style;

        return $normalized;
    }

    private static function resolveDynamicShortcodes( string $content, array $context ): string {
        if ( $content === '' || ! str_contains( $content, '{{' ) ) {
            return $content;
        }
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_.-]+)\s*\}\}/i',
            static function ( array $matches ) use ( $context ): string {
                $token = strtolower( (string) ( $matches[1] ?? '' ) );
                if ( $token === '' ) {
                    return '';
                }
                return self::resolveDynamicTokenValue( $token, $context );
            },
            $content
        );
    }

    private static function resolveDynamicTokenValue( string $token, array $context ): string {
        switch ( $token ) {
            case 'site.name':
                if ( function_exists( 'metis_get_option' ) ) {
                    $name = trim( (string) metis_get_option( 'site_name', '' ) );
                    if ( $name !== '' ) return $name;
                }
                return 'Metis Site';
            case 'site.url':
                return function_exists( 'metis_home_url' ) ? (string) metis_home_url( '/' ) : '/';
            case 'page.title':
            case 'post.title':
                return trim( (string) ( $context['page_title'] ?? $context['title'] ?? '' ) );
            case 'page.slug':
                return trim( (string) ( $context['slug'] ?? '' ) );
            case 'post.date':
                return trim( (string) ( $context['publish_date'] ?? $context['date'] ?? '' ) );
            case 'post.excerpt':
                return trim( (string) ( $context['excerpt'] ?? '' ) );
            default:
                return '';
        }
    }

    private static function canonicalType( string $type ): string {
        $aliases = [
            'columns' => 'grid',
            'advanced_columns' => 'grid',
            'section' => 'container',
            'responsive_spacer' => 'spacer',
            'enhanced_divider' => 'divider',
            'html_embed' => 'html',
            'posts_feed' => 'post_list',
            'posts_block' => 'post_list',
            'events_list' => 'events_block',
            'team' => 'team_block',
            'form_embed' => 'form',
            'form_multistep' => 'multi_step_form',
            'donation_form' => 'donation_form_block',
            'donation_progress' => 'progress_bar_block',
            'donation_description' => 'campaign_description_block',
            'donation_description_block' => 'campaign_description_block',
            'donation_goal_summary' => 'donation_goal_summary_block',
            'donor_wall' => 'donor_wall_block',
            'impact_metrics' => 'impact_metrics_block',
            'countdown' => 'countdown_block',
            'anchor' => 'anchor_block',
            'modal_trigger' => 'popup_trigger',
            'modal_trigger_block' => 'popup_trigger',
            'cta_banner_block' => 'cta',
        ];
        return $aliases[ $type ] ?? $type;
    }

    private static function studly( string $value ): string {
        return str_replace( ' ', '', ucwords( str_replace( [ '-', '_' ], ' ', $value ) ) );
    }

    private static function unwrapOuterTag( string $html, string $tag ): ?string {
        $safe = preg_quote( strtolower( trim( $tag ) ), '/' );
        if ( $safe === '' ) {
            return null;
        }
        if ( preg_match( '/^\s*<' . $safe . '\b[^>]*>(.*)<\/' . $safe . '>\s*$/is', $html, $m ) !== 1 ) {
            return null;
        }
        return (string) ( $m[1] ?? '' );
    }

    private static function unwrapAnyHeading( string $html ): ?string {
        if ( preg_match( '/^\s*<h[1-6]\b[^>]*>(.*)<\/h[1-6]>\s*$/is', $html, $m ) !== 1 ) {
            return null;
        }
        return (string) ( $m[1] ?? '' );
    }

    private static function containsBlockMarkup( string $html ): bool {
        return preg_match( '/<\s*(p|div|section|article|header|footer|main|aside|nav|h[1-6]|ul|ol|li|table|blockquote)\b/i', $html ) === 1;
    }
}
