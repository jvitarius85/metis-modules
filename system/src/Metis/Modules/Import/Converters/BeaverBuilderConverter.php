<?php
declare(strict_types=1);

namespace Metis\Modules\Import\Converters;

use Metis\Core\Serialization\LegacyPhpSerializedPayload;

/**
 * Beaver Builder → Metis Block Converter
 *
 * Directive Section 19-20: explicit mapping table.
 * Maps BB rows/columns/modules → Metis sections/blocks.
 * ~80-90% structured conversion; unsupported modules produce warnings,
 * never silent drops.
 */
final class BeaverBuilderConverter {

    private static array $report = [ 'converted' => 0, 'fallbacks' => 0, 'warnings' => [] ];

    public static function convert( string $bb_data ): array {
        self::$report = [ 'converted' => 0, 'fallbacks' => 0, 'warnings' => [] ];

        $nodes = LegacyPhpSerializedPayload::decodeArray( $bb_data );
        if ( ! is_array( $nodes ) ) {
            self::$report['warnings'][] = 'Could not deserialize Beaver Builder data.';
            return [ 'layout' => self::emptyLayout(), 'report' => self::$report ];
        }

        $rows = $columns = $modules = [];
        foreach ( $nodes as $id => $node ) {
            $node = (array) $node;
            switch ( (string) ( $node['type'] ?? '' ) ) {
                case 'row':    $rows[ $id ]    = $node; break;
                case 'column': $columns[ $id ] = $node; break;
                case 'module': $modules[ $id ] = $node; break;
            }
        }

        uasort( $rows, fn( $a, $b ) => (int)( $a['position'] ?? 0 ) <=> (int)( $b['position'] ?? 0 ) );

        $sections = [];
        foreach ( $rows as $row_id => $row ) {
            $section = self::convertRow( $row_id, $row, $columns, $modules );
            if ( $section !== null ) $sections[] = $section;
        }

        return [ 'layout' => [ 'version' => 1, 'sections' => $sections ], 'report' => self::$report ];
    }

    private static function convertRow( string $row_id, array $row, array $all_cols, array $all_mods ): ?array {
        $row_cols = array_filter( $all_cols, fn( $c ) => (string)( $c['parent'] ?? '' ) === $row_id );
        uasort( $row_cols, fn( $a, $b ) => (int)( $a['position'] ?? 0 ) <=> (int)( $b['position'] ?? 0 ) );
        if ( empty( $row_cols ) ) return null;

        $col_count = count( $row_cols );
        $blocks    = [];

        if ( $col_count === 1 ) {
            $col_id = (string) array_key_first( $row_cols );
            $blocks = self::collectModuleBlocks( $col_id, $all_mods );
        } else {
            $col_blocks = [];
            foreach ( $row_cols as $col_id => $col ) {
                $col_blocks[] = self::collectModuleBlocks( (string) $col_id, $all_mods );
            }
            $blocks[] = [ 'id' => 'bb_grid_' . $row_id, 'type' => 'grid',
                'data' => [ 'columns' => $col_count, 'gap' => '24px', 'col_blocks' => $col_blocks ], 'style' => [] ];
            self::$report['converted']++;
        }

        return [ 'id' => 'bb_row_' . $row_id, 'type' => 'container',
            'blocks' => $blocks, 'style' => self::extractRowStyle( $row ) ];
    }

    private static function collectModuleBlocks( string $col_id, array $all_mods ): array {
        $col_mods = array_filter( $all_mods, fn( $m ) => (string)( $m['parent'] ?? '' ) === $col_id );
        uasort( $col_mods, fn( $a, $b ) => (int)( $a['position'] ?? 0 ) <=> (int)( $b['position'] ?? 0 ) );
        $blocks = [];
        foreach ( $col_mods as $mod_id => $mod ) {
            $block = self::convertModule( (string) $mod_id, $mod );
            if ( $block !== null ) $blocks[] = $block;
        }
        return $blocks;
    }


    /** Section 20 mapping table */
    private static function convertModule( string $id, array $mod ): ?array {
        $type     = (string)( (array)( $mod['settings'] ?? [] ) )['type'] ?? (string)( $mod['type'] ?? '' );
        // Also try settings object directly
        if ( $type === '' && isset( $mod['settings'] ) ) {
            $s = (array) $mod['settings'];
            $type = (string)( $s['type'] ?? '' );
        }
        $settings = (array)( $mod['settings'] ?? [] );
        $px = 'bb_mod_';

        switch ( $type ) {
            case 'heading':
                self::$report['converted']++;
                return [ 'id' => $px.$id, 'type' => 'heading', 'data' => [
                    'content' => '<'.($settings['tag']??'h2').'>'.($settings['heading']??'').'</'.($settings['tag']??'h2').'>',
                    'level'   => $settings['tag'] ?? 'h2',
                    'align'   => $settings['align'] ?? 'left',
                ], 'style' => self::extractTypographyStyle( $settings ) ];

            case 'rich-text':
            case 'text-editor':
                self::$report['converted']++;
                return [ 'id' => $px.$id, 'type' => 'text', 'data' => [
                    'content' => $settings['text'] ?? $settings['content'] ?? '',
                    'tag'     => 'div',
                    'align'   => $settings['align'] ?? 'left',
                ], 'style' => self::extractTypographyStyle( $settings ) ];

            case 'photo':
                self::$report['converted']++;
                return [ 'id' => $px.$id, 'type' => 'image', 'data' => [
                    'src'   => $settings['photo_src'] ?? $settings['src'] ?? '',
                    'alt'   => $settings['caption'] ?? '',
                    'link'  => $settings['link_url'] ?? '',
                    'width' => isset($settings['photo_max_width']) && $settings['photo_max_width'] !== '' ? $settings['photo_max_width'].'px' : '100%',
                ], 'style' => [] ];

            case 'button':
                self::$report['converted']++;
                return [ 'id' => $px.$id, 'type' => 'button', 'data' => [
                    'label'   => $settings['text'] ?? 'Button',
                    'url'     => $settings['link'] ?? '#',
                    'bgcolor' => $settings['bg_color'] ?? '#485bc7',
                    'color'   => $settings['text_color'] ?? '#ffffff',
                    'size'    => 'medium',
                ], 'style' => self::extractSpacingStyle( $settings ) ];

            case 'html':
                self::$report['converted']++;
                $content = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $settings['html'] ?? '' ) ?? '';
                return [ 'id' => $px.$id, 'type' => 'html', 'data' => [ 'content' => $content ], 'style' => [] ];

            case 'separator':
            case 'divider':
                self::$report['converted']++;
                return [ 'id' => $px.$id, 'type' => 'divider', 'data' => [
                    'color'  => $settings['color'] ?? '#e2e6ea',
                    'height' => (int)( $settings['height'] ?? 1 ),
                    'style'  => $settings['style'] ?? 'solid',
                ], 'style' => self::extractSpacingStyle( $settings ) ];

            case 'spacer':
                self::$report['converted']++;
                return [ 'id' => $px.$id, 'type' => 'spacer', 'data' => [ 'height' => (int)( $settings['height'] ?? 24 ) ], 'style' => [] ];

            case 'menu':
                self::$report['converted']++;
                return [ 'id' => $px.$id, 'type' => 'menu', 'data' => [ 'menu_id' => null, 'orientation' => 'horizontal' ], 'style' => [] ];

            case 'icon':
                self::$report['converted']++;
                return [ 'id' => $px.$id, 'type' => 'icon', 'data' => [
                    'icon_svg' => '',
                    'size'     => ( $settings['size'] ?? '24' ).'px',
                    'color'    => $settings['color'] ?? '#000000',
                    'link'     => $settings['link'] ?? '',
                ], 'style' => self::extractSpacingStyle( $settings ) ];

            case 'video':
                self::$report['converted']++;
                $url = $settings['video_url'] ?? $settings['url'] ?? '';
                $provider = str_contains( $url, 'youtube' ) ? 'youtube' : ( str_contains( $url, 'vimeo' ) ? 'vimeo' : 'local' );
                return [ 'id' => $px.$id, 'type' => 'video', 'data' => [ 'url' => $url, 'provider' => $provider, 'aspect_ratio' => '16:9' ], 'style' => [] ];

            case 'posts':
                self::$report['converted']++;
                return [ 'id' => $px.$id, 'type' => 'post_list', 'data' => [
                    'count' => (int)( $settings['posts_per_page'] ?? 5 ), 'layout' => 'list', 'show_excerpt' => true, 'show_date' => true,
                ], 'style' => [] ];

            case 'callout':
                self::$report['converted']++;
                $inner = [];
                if ( ! empty( $settings['title'] ) )    $inner[] = [ 'id' => $px.$id.'_h', 'type' => 'heading', 'data' => [ 'content' => '<h3>'.$settings['title'].'</h3>', 'level' => 'h3', 'align' => 'left' ], 'style' => [] ];
                if ( ! empty( $settings['text'] ) )     $inner[] = [ 'id' => $px.$id.'_t', 'type' => 'text',    'data' => [ 'content' => $settings['text'], 'tag' => 'p', 'align' => 'left' ],  'style' => [] ];
                if ( ! empty( $settings['cta_text'] ) ) $inner[] = [ 'id' => $px.$id.'_b', 'type' => 'button',  'data' => [ 'label' => $settings['cta_text'], 'url' => $settings['cta_link'] ?? '#', 'bgcolor' => '#485bc7', 'color' => '#fff', 'size' => 'medium' ], 'style' => [] ];
                return [ 'id' => $px.$id, 'type' => 'container', 'data' => [ 'blocks' => $inner, 'max_width' => '800px', 'align' => 'center' ], 'style' => [] ];

            case 'accordion':
            case 'tabs':
                self::$report['fallbacks']++;
                self::$report['warnings'][] = "BB module '{$type}' (ID: {$id}) has no Metis equivalent. Converted to HTML fallback.";
                return [ 'id' => $px.$id, 'type' => 'html', 'data' => [ 'content' => '<!-- BB '.$type.' module — rebuild in Metis block editor -->' ], 'style' => [] ];

            case 'contact-form':
            case 'wpforms':
            case 'gravity-form':
            case 'form':
                self::$report['fallbacks']++;
                self::$report['warnings'][] = "Form module '{$type}' (ID: {$id}) must be recreated using Metis Forms module.";
                return [ 'id' => $px.$id, 'type' => 'html', 'data' => [ 'content' => '<!-- Form from BB — recreate with Metis Forms -->' ], 'style' => [] ];

            default:
                self::$report['fallbacks']++;
                self::$report['warnings'][] = "Unsupported BB module '{$type}' (ID: {$id}) — converted to HTML fallback.";
                $content = $settings['text'] ?? $settings['content'] ?? $settings['html'] ?? '';
                return [ 'id' => $px.$id, 'type' => 'html', 'data' => [ 'content' => $content !== '' ? metis_sanitize_html( (string)$content ) : '<!-- BB: '.$type.' -->' ], 'style' => [] ];
        }
    }

    private static function extractRowStyle( array $row ): array {
        $s = (array)( $row['settings'] ?? [] );
        $style = [];
        if ( ! empty( $s['bg_color'] ) )       $style['color']['background'] = '#'.ltrim($s['bg_color'],'#');
        if ( ! empty( $s['padding_top'] ) || ! empty( $s['padding_bottom'] ) ) {
            $style['spacing']['padding'] = (int)($s['padding_top']??0).'px 0 '.(int)($s['padding_bottom']??0).'px 0';
        }
        return $style;
    }

    private static function extractTypographyStyle( array $s ): array {
        $style = [];
        if ( ! empty( $s['text_color'] ) ) $style['color']['text'] = '#'.ltrim($s['text_color'],'#');
        return $style;
    }

    private static function extractSpacingStyle( array $s ): array {
        $parts = [];
        foreach ( ['top','bottom','left','right'] as $side ) {
            if ( ! empty( $s['margin_'.$side] ) ) $parts[] = 'margin-'.$side.':'.(int)$s['margin_'.$side].'px';
        }
        return $parts ? [ 'spacing' => [ 'margin' => implode(';',$parts) ] ] : [];
    }

    private static function emptyLayout(): array {
        return [ 'version' => 1, 'sections' => [] ];
    }
}
