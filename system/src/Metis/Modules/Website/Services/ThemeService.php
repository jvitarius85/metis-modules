<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Core\Application;
use Metis\Modules\Website\Services\LayoutProfileService;

/**
 * Theme Service — manages website-wide design tokens and theme configuration.
 */
final class ThemeService {
    private static ?array $active_normalized_cache = null;
    private static ?int $active_normalized_theme_id = null;

    public static function getActive(): ?array {
        $row = self::db()->fetchOne(
            "SELECT * FROM " . \Metis_Tables::get( 'website_theme_config' ) . " WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
        );
        return is_array( $row ) ? $row : null;
    }

    public static function getById( int $id ): ?array {
        $row = self::db()->fetchOne(
            "SELECT * FROM " . \Metis_Tables::get( 'website_theme_config' ) . " WHERE id = %d",
            [ $id ]
        );
        return is_array( $row ) ? $row : null;
    }

    public static function save( array $data ): int|false {
        $data = self::normalizeStoragePayload( $data );
        $result = self::db()->insert( \Metis_Tables::get( 'website_theme_config' ), [
            'global_styles_json' => $data['global_styles_json'] ?? null,
            'typography_json'    => $data['typography_json'] ?? null,
            'color_palette_json' => $data['color_palette_json'] ?? null,
            'spacing_json'       => $data['spacing_json'] ?? null,
            'custom_tokens_json' => $data['custom_tokens_json'] ?? null,
            'is_active'          => 0,
            'created_by'         => $data['created_by'] ?? self::getCurrentUserId(),
            'updated_by'         => $data['updated_by'] ?? self::getCurrentUserId(),
        ] );
        return $result ? (int) self::db()->lastInsertId() : false;
    }

    public static function activate( int $id ): bool {
        $db    = self::db();
        $table = \Metis_Tables::get( 'website_theme_config' );
        $db->execute( "UPDATE {$table} SET is_active = 0" );
        $ok = (bool) $db->update( $table, [ 'is_active' => 1 ], [ 'id' => $id ] );
        if ( $ok ) {
            self::$active_normalized_cache = null;
            self::$active_normalized_theme_id = null;
        }
        return $ok;
    }

    public static function update( int $id, array $data ): bool {
        $data = self::normalizeStoragePayload( $data );
        $update = [];
        foreach ( [ 'global_styles_json', 'typography_json', 'color_palette_json', 'spacing_json', 'custom_tokens_json' ] as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $update[ $field ] = $data[ $field ];
            }
        }
        $update['updated_by'] = $data['updated_by'] ?? self::getCurrentUserId();
        if ( function_exists( 'metis_current_time' ) ) {
            $update['updated_at'] = (string) metis_current_time( 'mysql' );
        }
        $ok = (bool) self::db()->update( \Metis_Tables::get( 'website_theme_config' ), $update, [ 'id' => $id ] );
        if ( $ok && self::$active_normalized_theme_id === $id ) {
            self::$active_normalized_cache = null;
            self::$active_normalized_theme_id = null;
        }
        return $ok;
    }

    public static function getActiveNormalized(): array {
        $row = self::getActive();
        if ( ! is_array( $row ) ) {
            return self::defaultThemeConfig();
        }

        $theme_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
        if ( self::$active_normalized_cache !== null && self::$active_normalized_theme_id === $theme_id ) {
            return self::$active_normalized_cache;
        }

        $config = self::normalizeThemeRow( $row );
        self::$active_normalized_cache = $config;
        self::$active_normalized_theme_id = $theme_id;

        return $config;
    }

    public static function activeVersionToken(): string {
        $row = self::getActive();
        if ( ! is_array( $row ) ) {
            return '';
        }
        $id = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $updated_at = trim( (string) ( $row['updated_at'] ?? '' ) );
        $ts = $updated_at !== '' ? (int) strtotime( $updated_at ) : 0;
        if ( $id > 0 && $ts > 0 ) {
            return $id . '-' . $ts;
        }
        if ( $id > 0 ) {
            return (string) $id;
        }
        return $ts > 0 ? (string) $ts : '';
    }

    public static function formatDocumentTitle( string $page_title ): string {
        $config = self::getActiveNormalized();
        $format = (string) ( $config['global_settings']['title_format'] ?? '{page} | {site}' );
        if ( trim( $format ) === '' ) {
            $format = '{page} | {site}';
        }

        $site_name = 'Metis';
        if ( function_exists( 'metis_get_option' ) ) {
            $name = trim( (string) metis_get_option( 'blogname', '' ) );
            if ( $name !== '' ) {
                $site_name = $name;
            }
        }

        $resolved = str_replace(
            [ '{page}', '{site}' ],
            [ trim( $page_title ) !== '' ? $page_title : 'Page', $site_name ],
            $format
        );

        return trim( preg_replace( '/\s+/', ' ', $resolved ) ?? $resolved );
    }

    public static function renderFontCss( array $theme ): string {
        $lines = [];
        $typography = is_array( $theme['typography'] ?? null ) ? $theme['typography'] : [];
        $custom_fonts = is_array( $typography['custom_fonts'] ?? null ) ? $typography['custom_fonts'] : [];
        $selected_families = self::selectedThemeFontFamilies( $typography );

        foreach ( self::localFontFacesCss( $selected_families ) as $face_rule ) {
            $lines[] = $face_rule;
        }

        foreach ( $custom_fonts as $font ) {
            if ( ! is_array( $font ) ) {
                continue;
            }
            $name = self::sanitizeFontFamilyName( (string) ( $font['name'] ?? '' ) );
            $data = self::sanitizeFontDataUri( (string) ( $font['data'] ?? '' ) );
            if ( $name === '' || $data === '' ) {
                continue;
            }
            if ( ! isset( $selected_families[ strtolower( $name ) ] ) ) {
                continue;
            }
            $format = self::sanitizeFontFormat( (string) ( $font['format'] ?? 'woff2' ) );
            $lines[] = '@font-face{font-family:"' . str_replace( '"', '', $name ) . '";src:url("' . $data . '") format("' . $format . '");font-weight:100 900;font-style:normal;font-display:swap;}';
        }

        return implode( "\n", $lines );
    }

    public static function renderGlobalCss( array $theme ): string {
        $lines = [];
        $vars = [];
        $global_settings = is_array( $theme['global_settings'] ?? null ) ? $theme['global_settings'] : [];

        $colors = is_array( $theme['colors'] ?? null ) ? $theme['colors'] : [];
        foreach ( $colors as $key => $value ) {
            $name = self::sanitizeVarName( (string) $key );
            $val = self::sanitizeCssValue( (string) $value );
            if ( $name === '' || $val === '' ) {
                continue;
            }
            $vars[] = '--metis-color-' . $name . ': ' . $val . ';';
        }

        $containers = is_array( $theme['layout_tokens']['container_widths'] ?? null ) ? $theme['layout_tokens']['container_widths'] : [];
        foreach ( $containers as $key => $value ) {
            $name = self::sanitizeVarName( (string) $key );
            $val = self::sanitizeCssValue( (string) $value );
            if ( $name === '' || $val === '' ) {
                continue;
            }
            $vars[] = '--metis-container-' . $name . ': ' . $val . ';';
        }

        $spacing = is_array( $theme['layout_tokens']['spacing_scale'] ?? null ) ? $theme['layout_tokens']['spacing_scale'] : [];
        foreach ( $spacing as $key => $value ) {
            $name = self::sanitizeVarName( (string) $key );
            $val = self::sanitizeCssValue( (string) $value );
            if ( $name === '' || $val === '' ) {
                continue;
            }
            $vars[] = '--metis-space-' . $name . ': ' . $val . ';';
        }

        $menu = is_array( $theme['components']['menu'] ?? null ) ? $theme['components']['menu'] : [];
        $menu_style = self::sanitizeMenuStyle(
            (string) ( $menu['style'] ?? ( $global_settings['menu_style'] ?? 'h_glide' ) )
        );
        $menu_config = self::menuPresetConfig( $menu_style );
        $menu_layout = (string) ( $menu_config['layout'] ?? 'horizontal_clean' );
        $menu_alignment = (string) ( $menu_config['alignment'] ?? 'left' );
        $menu_container = (string) ( $menu_config['container'] ?? 'contained' );
        $menu_desktop = is_array( $menu_config['desktop'] ?? null ) ? $menu_config['desktop'] : [];
        $menu_dropdown_cfg = is_array( $menu_config['dropdown'] ?? null ) ? $menu_config['dropdown'] : [];
        $menu_chevron_cfg = is_array( $menu_config['chevron'] ?? null ) ? $menu_config['chevron'] : [];
        $menu_font_size = max( 11, min( 28, (int) ( $menu_desktop['font_size'] ?? 14 ) ) );
        $menu_spacing_mode = self::sanitizeMenuSpacingMode(
            (string) ( $menu_desktop['item_spacing'] ?? 'normal' )
        );
        $menu_item_spacing = self::menuSpacingPixels( $menu_spacing_mode );
        $menu_hover_style = self::sanitizeMenuHoverStyle( (string) ( $menu_desktop['hover_style'] ?? 'fill' ) );
        $menu_active_style_v2 = self::sanitizeMenuDesktopActiveStyle( (string) ( $menu_desktop['active_style'] ?? 'underline' ) );
        $menu_dropdown_behavior = self::sanitizeMenuDropdownBehavior( (string) ( $menu_dropdown_cfg['behavior'] ?? 'hover' ) );
        $menu_vertical_align = 'center';
        $menu_item_radius = 10;
        $menu_button_radius = 10;
        $menu_button_padding_x = 14;
        $menu_button_padding_y = 10;
        $menu_dropdown_radius = max( 0, min( 30, (int) ( $menu_dropdown_cfg['radius'] ?? 10 ) ) );
        $menu_dropdown_highlight = 'var(--metis-color-surface_alt,#f8fafc)';
        $menu_dropdown_text = 'var(--metis-color-text,#1a1f2b)';
        $menu_dropdown_weight = '500';
        $menu_submenu_open_animation = self::sanitizeMenuSubmenuOpenAnimation( (string) ( $menu_dropdown_cfg['animation'] ?? 'fade' ) );
        $menu_submenu_hover_animation = self::sanitizeMenuSubmenuHoverAnimation( $menu_hover_style );
        $menu_active_style = self::sanitizeMenuActiveStyle( $menu_active_style_v2 === 'none' ? 'none' : $menu_active_style_v2 );
        $menu_active_color = 'var(--metis-color-primary,#485bc7)';
        $menu_chevron_color = 'var(--metis-color-primary,#485bc7)';
        $menu_chevron_animation = self::sanitizeMenuChevronAnimation(
            self::sanitizeMenuChevronAnimationV2( (string) ( $menu_chevron_cfg['animation'] ?? 'rotate' ) ) === 'rotate' ? 'flip' : 'none'
        );
        $menu_chevron_type = self::sanitizeMenuChevronType( (string) ( $menu_chevron_cfg['type'] ?? 'chevron' ) );
        $menu_mobile_breakpoint = 980;
        $menu_button_bg = 'var(--metis-color-primary,#485bc7)';
        $menu_button_text = 'var(--metis-color-button_text,#ffffff)';
        $menu_button_border = 'transparent';
        $vars[] = '--metis-menu-font-size: ' . $menu_font_size . 'px;';
        $vars[] = '--metis-menu-item-gap: ' . $menu_item_spacing . 'px;';
        $vars[] = '--metis-menu-item-align: ' . self::menuVerticalAlignToCss( $menu_vertical_align ) . ';';
        $vars[] = '--metis-menu-item-radius: ' . $menu_item_radius . 'px;';
        $vars[] = '--metis-menu-active-color: ' . $menu_active_color . ';';
        $vars[] = '--metis-menu-button-radius: ' . $menu_button_radius . 'px;';
        $vars[] = '--metis-menu-button-padding-x: ' . $menu_button_padding_x . 'px;';
        $vars[] = '--metis-menu-button-padding-y: ' . $menu_button_padding_y . 'px;';
        $vars[] = '--metis-menu-dropdown-radius: ' . $menu_dropdown_radius . 'px;';
        $vars[] = '--metis-menu-dropdown-highlight: ' . $menu_dropdown_highlight . ';';
        $vars[] = '--metis-menu-dropdown-text: ' . $menu_dropdown_text . ';';
        $vars[] = '--metis-menu-dropdown-weight: ' . $menu_dropdown_weight . ';';
        $vars[] = '--metis-menu-chevron-color: ' . $menu_chevron_color . ';';
        $vars[] = '--metis-menu-button-bg: ' . $menu_button_bg . ';';
        $vars[] = '--metis-menu-button-text: ' . $menu_button_text . ';';
        $vars[] = '--metis-menu-button-border: ' . $menu_button_border . ';';
        $vars[] = '--metis-menu-mobile-breakpoint: ' . $menu_mobile_breakpoint . 'px;';
        $vars[] = '--metis-menu-dropdown-behavior: ' . $menu_dropdown_behavior . ';';
        if ( $menu_submenu_open_animation === 'none' ) {
            $vars[] = '--metis-menu-sub-open-duration: 0ms;';
            $vars[] = '--metis-menu-sub-open-y: 0px;';
            $vars[] = '--metis-menu-sub-open-scale: 1;';
        } elseif ( $menu_submenu_open_animation === 'slide' ) {
            $vars[] = '--metis-menu-sub-open-duration: 180ms;';
            $vars[] = '--metis-menu-sub-open-y: -10px;';
            $vars[] = '--metis-menu-sub-open-scale: 1;';
        } elseif ( $menu_submenu_open_animation === 'scale' ) {
            $vars[] = '--metis-menu-sub-open-duration: 180ms;';
            $vars[] = '--metis-menu-sub-open-y: -2px;';
            $vars[] = '--metis-menu-sub-open-scale: .96;';
        } else {
            $vars[] = '--metis-menu-sub-open-duration: 180ms;';
            $vars[] = '--metis-menu-sub-open-y: 0px;';
            $vars[] = '--metis-menu-sub-open-scale: 1;';
        }

        if ( $menu_chevron_animation === 'none' ) {
            $lines[] = '.metis-shell-menu-sub-indicator{transition:none !important;}';
        } else {
            $lines[] = '.metis-shell-menu-sub-indicator{transition:transform .2s ease,color .2s ease,border-color .2s ease;}';
            if ( $menu_chevron_animation === 'flip' || $menu_chevron_animation === 'flip_color' ) {
                $lines[] = '.metis-shell-menu-item.has-children.is-open>.metis-shell-menu-link .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children.is-open>.metis-shell-menu-btn .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children:hover>.metis-shell-menu-link .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children:hover>.metis-shell-menu-btn .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children:focus-within>.metis-shell-menu-link .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children:focus-within>.metis-shell-menu-btn .metis-shell-menu-sub-indicator{transform:rotate(225deg) translateY(1px);}';
            }
            if ( $menu_chevron_animation === 'color' || $menu_chevron_animation === 'flip_color' ) {
                $lines[] = '.metis-shell-menu-item.has-children.is-open>.metis-shell-menu-link .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children.is-open>.metis-shell-menu-btn .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children:hover>.metis-shell-menu-link .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children:hover>.metis-shell-menu-btn .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children:focus-within>.metis-shell-menu-link .metis-shell-menu-sub-indicator,.metis-shell-menu-item.has-children:focus-within>.metis-shell-menu-btn .metis-shell-menu-sub-indicator{color:var(--metis-menu-chevron-color,var(--metis-color-primary,#485bc7));border-color:currentColor;}';
            }
        }
        if ( $menu_submenu_hover_animation === 'none' ) {
            $lines[] = '.metis-shell-menu-sub .metis-shell-menu-item:hover>.metis-shell-menu-link,.metis-shell-menu-sub .metis-shell-menu-item:hover>.metis-shell-menu-btn,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-link:hover,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-btn:hover{background:transparent !important;transform:none !important;box-shadow:none !important;text-decoration:none !important;}';
        } elseif ( $menu_submenu_hover_animation === 'lift' ) {
            $lines[] = '.metis-shell-menu-sub .metis-shell-menu-item:hover>.metis-shell-menu-link,.metis-shell-menu-sub .metis-shell-menu-item:hover>.metis-shell-menu-btn,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-link:hover,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-btn:hover{background:var(--metis-menu-dropdown-highlight,var(--metis-color-surface_alt,#f8fafc)) !important;color:var(--metis-menu-dropdown-text,var(--metis-color-text,#1a1f2b));transform:translateY(-1px);box-shadow:0 8px 16px rgba(15,23,42,.10);}';
        } elseif ( $menu_submenu_hover_animation === 'underline' ) {
            $lines[] = '.metis-shell-menu-sub .metis-shell-menu-item:hover>.metis-shell-menu-link,.metis-shell-menu-sub .metis-shell-menu-item:hover>.metis-shell-menu-btn,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-link:hover,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-btn:hover{background:transparent !important;box-shadow:inset 0 -2px 0 var(--metis-menu-dropdown-highlight,var(--metis-color-surface_alt,#f8fafc));}';
        } else {
            $lines[] = '.metis-shell-menu-sub .metis-shell-menu-item:hover>.metis-shell-menu-link,.metis-shell-menu-sub .metis-shell-menu-item:hover>.metis-shell-menu-btn,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-link:hover,.metis-shell-menu-sub .metis-shell-menu-item>.metis-shell-menu-btn:hover{background:var(--metis-menu-dropdown-highlight,var(--metis-color-surface_alt,#f8fafc)) !important;color:var(--metis-menu-dropdown-text,var(--metis-color-text,#1a1f2b));}';
        }
        if ( $menu_hover_style === 'underline' ) {
            $lines[] = '.metis-shell-menu-item>.metis-shell-menu-link:hover,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-link:hover{box-shadow:inset 0 -2px 0 var(--metis-menu-active-color,var(--metis-color-primary,#485bc7));}';
        } elseif ( $menu_hover_style === 'fill' ) {
            $lines[] = '.metis-shell-menu-item>.metis-shell-menu-link:hover,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-link:hover{background:var(--metis-menu-dropdown-highlight,var(--metis-color-surface_alt,#f8fafc));}';
        }
        if ( $menu_active_style === 'underline' ) {
            $lines[] = '.metis-shell-menu-item.is-active>.metis-shell-menu-link,.metis-shell-menu-item.is-active-ancestor>.metis-shell-menu-link,.metis-shell-menu-item>.metis-shell-menu-link[aria-current="page"],.metis-template .metis-template-menu .metis-shell-menu-item.is-active>.metis-shell-menu-link,.metis-template .metis-template-menu .metis-shell-menu-item.is-active-ancestor>.metis-shell-menu-link,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-link[aria-current="page"]{color:var(--metis-menu-active-color,var(--metis-color-primary,#485bc7));box-shadow:inset 0 -2px 0 var(--metis-menu-active-color,var(--metis-color-primary,#485bc7));}';
        } elseif ( $menu_active_style === 'pill' ) {
            $lines[] = '.metis-shell-menu-item.is-active>.metis-shell-menu-link,.metis-shell-menu-item>.metis-shell-menu-link[aria-current="page"],.metis-template .metis-template-menu .metis-shell-menu-item.is-active>.metis-shell-menu-link,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-link[aria-current="page"]{background:var(--metis-menu-active-color,var(--metis-color-primary,#485bc7));color:var(--metis-color-button_text,#fff);}';
        } elseif ( $menu_active_style === 'none' ) {
            $lines[] = '.metis-shell-menu-item.is-active>.metis-shell-menu-link,.metis-shell-menu-item.is-active-ancestor>.metis-shell-menu-link,.metis-shell-menu-item>.metis-shell-menu-link[aria-current="page"],.metis-template .metis-template-menu .metis-shell-menu-item.is-active>.metis-shell-menu-link,.metis-template .metis-template-menu .metis-shell-menu-item.is-active-ancestor>.metis-shell-menu-link,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-link[aria-current="page"]{color:inherit;box-shadow:none;background:transparent;}';
        } else {
            $lines[] = '.metis-shell-menu-item.is-active>.metis-shell-menu-link,.metis-shell-menu-item.is-active-ancestor>.metis-shell-menu-link,.metis-shell-menu-item>.metis-shell-menu-link[aria-current="page"],.metis-template .metis-template-menu .metis-shell-menu-item.is-active>.metis-shell-menu-link,.metis-template .metis-template-menu .metis-shell-menu-item.is-active-ancestor>.metis-shell-menu-link,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-link[aria-current="page"]{color:var(--metis-menu-active-color,var(--metis-color-primary,#485bc7));}';
        }
        if ( $menu_chevron_type === 'none' ) {
            $lines[] = '.metis-shell-menu-sub-indicator{display:none !important;}';
        } elseif ( $menu_chevron_type === 'arrow' ) {
            $lines[] = '.metis-shell-menu-sub-indicator{width:8px;height:8px;border-right:2px solid currentColor;border-top:2px solid currentColor;border-bottom:0;border-left:0;transform:rotate(135deg) translateY(-1px);}';
        }
        $lines[] = 'body.metis-nav-mobile-viewport .metis-shell-nav-primary{position:fixed;top:0;right:-110%;width:min(320px,90vw);height:100vh;overflow:auto;background:var(--metis-color-surface,#fff);z-index:1400;padding:24px 18px;box-shadow:-10px 0 24px rgba(2,8,23,.16);transition:right .22s ease;}';
        $lines[] = 'body.metis-nav-mobile-viewport .metis-shell-nav-primary .metis-shell-menu-list{display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;gap:12px;}';
        $lines[] = 'body.metis-nav-mobile-viewport.metis-nav-open .metis-shell-nav-primary{right:0;}';
        $lines[] = 'body.metis-nav-mobile-viewport .metis-shell-nav-toggle{display:inline-flex !important;}';
        $lines[] = '.metis-shell-nav-toggle{border-radius:999px !important;}';
        if ( $menu_dropdown_behavior === 'click' ) {
            $lines[] = '.metis-shell-menu-item.has-children:hover>.metis-shell-menu-sub,.metis-shell-menu-item.has-children:focus-within>.metis-shell-menu-sub{opacity:0 !important;visibility:hidden !important;pointer-events:none !important;transform:translateY(var(--metis-menu-sub-open-y,-8px)) scale(var(--metis-menu-sub-open-scale,1)) !important;}';
            $lines[] = '.metis-shell-menu-item.has-children.is-open>.metis-shell-menu-sub{opacity:1 !important;visibility:visible !important;pointer-events:auto !important;transform:translateY(0) scale(1) !important;}';
        }
        if ( $menu_layout === 'centered_logo' ) {
            $lines[] = '.metis-template .metis-template-menu,.metis-shell-nav-primary .metis-shell-menu-list{justify-content:center !important;}';
        } elseif ( $menu_layout === 'split_nav' ) {
            $lines[] = '.metis-template .metis-template-header-inner,.metis-shell-header-row-main{justify-content:space-between !important;}';
        } elseif ( $menu_layout === 'minimal_topbar' ) {
            $lines[] = '.metis-template .metis-template-header,.metis-shell-header{border-bottom:1px solid var(--metis-color-border,#d8deea);}';
            $lines[] = '.metis-template .metis-template-header-inner,.metis-shell-header-inner{padding-top:8px !important;padding-bottom:8px !important;}';
            $lines[] = '.metis-shell-menu-item>.metis-shell-menu-link,.metis-shell-menu-item>.metis-shell-menu-btn{font-size:12px;letter-spacing:.02em;text-transform:uppercase;}';
        }
        if ( $menu_alignment === 'center' ) {
            $lines[] = '.metis-template .metis-template-menu .metis-shell-menu-list,.metis-shell-nav-primary .metis-shell-menu-list{justify-content:center !important;}';
        } elseif ( $menu_alignment === 'right' ) {
            $lines[] = '.metis-template .metis-template-menu .metis-shell-menu-list,.metis-shell-nav-primary .metis-shell-menu-list{justify-content:flex-end !important;}';
        } else {
            $lines[] = '.metis-template .metis-template-menu .metis-shell-menu-list,.metis-shell-nav-primary .metis-shell-menu-list{justify-content:flex-start !important;}';
        }
        if ( $menu_container === 'full' ) {
            $lines[] = '.metis-shell-header-inner,.metis-template .metis-template-header-inner{max-width:none !important;width:100% !important;}';
        }
        $lines[] = '.metis-shell-menu-item>.metis-shell-menu-btn,.metis-template .metis-template-menu .metis-shell-menu-item>.metis-shell-menu-btn{display:inline-flex !important;align-items:center !important;justify-content:center !important;line-height:1 !important;}';

        $custom_tokens = is_array( $theme['custom_tokens'] ?? null ) ? $theme['custom_tokens'] : [];
        foreach ( $custom_tokens as $key => $value ) {
            $name = self::sanitizeVarName( (string) $key );
            $val = self::sanitizeCssValue( (string) $value );
            if ( $name === '' || $val === '' ) {
                continue;
            }
            $vars[] = '--metis-token-' . $name . ': ' . $val . ';';
        }

        if ( $vars !== [] ) {
            $lines[] = ':root{';
            foreach ( $vars as $var_line ) {
                $lines[] = '  ' . $var_line;
            }
            $lines[] = '}';
        }

        $footer_bg = self::sanitizeCssValue( (string) ( $global_settings['footer_background'] ?? '#f8fafc' ) );
        if ( $footer_bg === '' ) {
            $footer_bg = '#f8fafc';
        }
        $lines[] = '.metis-site-footer,.metis-template-footer{background:' . $footer_bg . ';}';

        $elements = is_array( $theme['elements'] ?? null ) ? $theme['elements'] : [];
        $selector_map = [
            'body' => 'body.metis-public-site',
            'h1' => 'body.metis-public-site h1',
            'h2' => 'body.metis-public-site h2',
            'h3' => 'body.metis-public-site h3',
            'h4' => 'body.metis-public-site h4',
            'h5' => 'body.metis-public-site h5',
            'h6' => 'body.metis-public-site h6',
            'p' => 'body.metis-public-site p',
            'a' => 'body.metis-public-site a',
            'button' => 'body.metis-public-site button, body.metis-public-site .metis-btn, body.metis-public-site .metis-block-button .metis-btn',
            'input' => 'body.metis-public-site input',
            'select' => 'body.metis-public-site select',
            'textarea' => 'body.metis-public-site textarea',
            'label' => 'body.metis-public-site label',
            'divider' => 'body.metis-public-site hr, body.metis-public-site .metis-inline-divider, body.metis-public-site .metis-block-divider',
        ];

        foreach ( $selector_map as $key => $selector ) {
            $rule = is_array( $elements[ $key ] ?? null ) ? $elements[ $key ] : [];
            $block = self::renderRule( $selector, $rule );
            if ( $block !== '' ) {
                $lines[] = $block;
            }
        }

        $section_header_rule = is_array( $elements['section_header'] ?? null ) ? $elements['section_header'] : [];
        $section_header_block = self::renderSectionHeaderRule( $section_header_rule );
        if ( $section_header_block !== '' ) {
            $lines[] = $section_header_block;
        }

        $site_header_rule = is_array( $elements['site_header'] ?? null ) ? $elements['site_header'] : [];
        $site_header_block = self::renderSiteHeaderRule( $site_header_rule );
        if ( $site_header_block !== '' ) {
            $lines[] = $site_header_block;
        }

        $breakpoints = is_array( $theme['layout_tokens']['breakpoints'] ?? null ) ? $theme['layout_tokens']['breakpoints'] : [];
        $bp_md = self::sanitizeBreakpoint( $breakpoints['md'] ?? 768, 768 );
        $bp_lg = self::sanitizeBreakpoint( $breakpoints['lg'] ?? 1024, 1024 );
        $lines[] = '@media (max-width:' . $bp_lg . 'px){.metis-site-content-inner{max-width:var(--metis-container-content,860px);}}';
        $lines[] = '@media (max-width:' . $bp_md . 'px){.metis-site-content-inner{padding-left:var(--metis-space-sm,12px);padding-right:var(--metis-space-sm,12px);}}';

        return implode( "\n", $lines );
    }

    /**
     * @return array<int,string>
     */
    public static function fontStylesheetHrefs( array $theme ): array {
        return [];
    }

    public static function normalizeStoragePayload( array $data ): array {
        $global_styles = self::decodeJsonObject( $data['global_styles_json'] ?? null );
        $typography = self::decodeJsonObject( $data['typography_json'] ?? null );
        $colors = self::decodeJsonObject( $data['color_palette_json'] ?? null );
        $spacing = self::decodeJsonObject( $data['spacing_json'] ?? null );
        $custom_tokens_root = self::decodeJsonObject( $data['custom_tokens_json'] ?? null );
        $custom_tokens = is_array( $custom_tokens_root['tokens'] ?? null ) ? $custom_tokens_root['tokens'] : $custom_tokens_root;

        $normalized = self::normalizeThemeParts( $global_styles, $typography, $colors, $spacing, $custom_tokens );

        return [
            'global_styles_json' => self::jsonEncode( $normalized['global_styles'], JSON_UNESCAPED_SLASHES ),
            'typography_json' => self::jsonEncode( $normalized['typography'], JSON_UNESCAPED_SLASHES ),
            'color_palette_json' => self::jsonEncode( $normalized['colors'], JSON_UNESCAPED_SLASHES ),
            'spacing_json' => self::jsonEncode( $normalized['spacing'], JSON_UNESCAPED_SLASHES ),
            'custom_tokens_json' => self::jsonEncode( [ 'tokens' => $normalized['custom_tokens'] ], JSON_UNESCAPED_SLASHES ),
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
        ];
    }

    private static function jsonEncode( mixed $value, int $flags = 0 ): string {
        if ( function_exists( 'metis_json_encode' ) ) {
            return (string) metis_json_encode( $value, $flags );
        }
        $json = json_encode( $value, $flags | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? $json : '{}';
    }

    private static function getCurrentUserId(): ?int {
        if ( ! function_exists( 'metis_current_user_id' ) ) {
            return null;
        }
        $uid = metis_current_user_id();
        return $uid > 0 ? $uid : null;
    }

    private static function db(): object {
        return Application::service( 'db' );
    }

    private static function normalizeThemeRow( array $row ): array {
        $global_styles = self::decodeJsonObject( $row['global_styles_json'] ?? null );
        $typography = self::decodeJsonObject( $row['typography_json'] ?? null );
        $colors = self::decodeJsonObject( $row['color_palette_json'] ?? null );
        $spacing = self::decodeJsonObject( $row['spacing_json'] ?? null );
        $custom_tokens_root = self::decodeJsonObject( $row['custom_tokens_json'] ?? null );
        $custom_tokens = is_array( $custom_tokens_root['tokens'] ?? null ) ? $custom_tokens_root['tokens'] : $custom_tokens_root;

        $normalized = self::normalizeThemeParts( $global_styles, $typography, $colors, $spacing, $custom_tokens );

        return [
            'id' => (int) ( $row['id'] ?? 0 ),
            'global_settings' => $normalized['global_styles']['global_settings'],
            'layout' => $normalized['global_styles']['layout'],
            'layout_tokens' => $normalized['global_styles']['layout_tokens'],
            'elements' => $normalized['global_styles']['elements'],
            'components' => $normalized['global_styles']['components'],
            'typography' => $normalized['typography'],
            'colors' => $normalized['colors'],
            'spacing' => $normalized['spacing'],
            'custom_tokens' => $normalized['custom_tokens'],
            'custom_css' => (string) ( $normalized['global_styles']['advanced']['custom_css'] ?? '' ),
        ];
    }

    private static function defaultThemeConfig(): array {
        $normalized = self::normalizeThemeParts( [], [], [], [], [] );
        return [
            'id' => 0,
            'global_settings' => $normalized['global_styles']['global_settings'],
            'layout' => $normalized['global_styles']['layout'],
            'layout_tokens' => $normalized['global_styles']['layout_tokens'],
            'elements' => $normalized['global_styles']['elements'],
            'components' => $normalized['global_styles']['components'],
            'typography' => $normalized['typography'],
            'colors' => $normalized['colors'],
            'spacing' => $normalized['spacing'],
            'custom_tokens' => $normalized['custom_tokens'],
            'custom_css' => (string) ( $normalized['global_styles']['advanced']['custom_css'] ?? '' ),
        ];
    }

    private static function normalizeThemeParts( array $global_styles, array $typography, array $colors, array $spacing, array $custom_tokens ): array {
        $default_colors = [
            'bg' => '#ffffff',
            'background' => '#ffffff',
            'surface' => '#ffffff',
            'text' => '#1a1f2b',
            'muted' => '#596173',
            'border' => '#d8deea',
            'primary' => '#485bc7',
            'accent' => '#ff7542',
            'link' => '#2b59ff',
            'button_text' => '#ffffff',
        ];
        $merged_colors = array_merge( $default_colors, self::sanitizeAssocValues( $colors ) );

        $default_spacing = [
            'xs' => '4px',
            'sm' => '8px',
            'md' => '16px',
            'lg' => '24px',
            'xl' => '32px',
        ];
        $merged_spacing = array_merge( $default_spacing, self::sanitizeAssocValues( $spacing ) );

        $body_font = self::sanitizeCssValue( (string) ( $typography['body_font'] ?? 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif' ) );
        $heading_font = self::sanitizeCssValue( (string) ( $typography['heading_font'] ?? $body_font ) );
        $body_size = self::sanitizeCssValue( (string) ( $typography['base_size'] ?? '16px' ) );
        if ( ctype_digit( $body_size ) ) {
            $body_size .= 'px';
        }
        $line_height = self::sanitizeCssValue( (string) ( $typography['line_height'] ?? '1.6' ) );
        $heading_weight = self::sanitizeCssValue( (string) ( $typography['heading_weight'] ?? '700' ) );
        $font_source = metis_key_clean( (string) ( $typography['font_source'] ?? 'system' ) );
        if ( ! in_array( $font_source, [ 'system', 'google', 'custom' ], true ) ) {
            $font_source = 'system';
        }
        $custom_fonts = self::sanitizeCustomFonts( is_array( $typography['custom_fonts'] ?? null ) ? $typography['custom_fonts'] : [] );

        $layout = is_array( $global_styles['layout'] ?? null ) ? $global_styles['layout'] : [];
        $container_content_px = max( 640, min( 1600, (int) ( $layout['container_width'] ?? 860 ) ) );
        $container_site_px = max( 720, min( 1920, (int) ( $layout['max_width'] ?? 1200 ) ) );
        $container_content = $container_content_px . 'px';
        $container_site = $container_site_px . 'px';
        $spacing_preset = metis_key_clean( (string) ( $layout['spacing_preset'] ?? 'balanced' ) );
        if ( ! in_array( $spacing_preset, [ 'compact', 'balanced', 'airy' ], true ) ) {
            $spacing_preset = 'balanced';
        }

        $components = is_array( $global_styles['components'] ?? null ) ? $global_styles['components'] : [];
        $button_comp = is_array( $components['buttons'] ?? null ) ? $components['buttons'] : [];
        $menu_comp = is_array( $components['menu'] ?? null ) ? $components['menu'] : [];
        $form_comp = is_array( $components['forms'] ?? null ) ? $components['forms'] : [];
        $link_comp = is_array( $components['links'] ?? null ) ? $components['links'] : [];
        $cards_comp = is_array( $components['cards'] ?? null ) ? $components['cards'] : [];
        $advanced = is_array( $global_styles['advanced'] ?? null ) ? $global_styles['advanced'] : [];
        $global_settings = is_array( $global_styles['global_settings'] ?? null ) ? $global_styles['global_settings'] : [];
        $title_format = trim( (string) ( $global_settings['title_format'] ?? '' ) );
        if ( $title_format === '' ) {
            $title_format = '{page} | {site}';
        }
        $site_layout_profile = LayoutProfileService::sanitizeWBProfile(
            (string) ( $global_settings['site_layout_profile'] ?? LayoutProfileService::defaultWBProfileKey() )
        );
        $newsletter_layout_profile = LayoutProfileService::sanitizeNewsletterProfile(
            (string) ( $global_settings['newsletter_layout_profile'] ?? LayoutProfileService::defaultNewsletterProfileKey() )
        );
        $style_seed = trim( (string) ( $menu_comp['style'] ?? ( $global_settings['menu_style'] ?? '' ) ) );
        if ( $style_seed === '' ) {
            $style_seed = 'h_glide';
        }
        $menu_style = self::sanitizeMenuStyle( $style_seed );
        $menu_preset = self::menuPresetConfig( $menu_style );
        $menu_layout = (string) $menu_preset['layout'];
        $menu_alignment = (string) $menu_preset['alignment'];
        $menu_container = (string) $menu_preset['container'];
        $menu_desktop = is_array( $menu_preset['desktop'] ?? null ) ? $menu_preset['desktop'] : [];
        $menu_dropdown = is_array( $menu_preset['dropdown'] ?? null ) ? $menu_preset['dropdown'] : [];
        $menu_mobile = is_array( $menu_preset['mobile'] ?? null ) ? $menu_preset['mobile'] : [];
        $menu_chevron = is_array( $menu_preset['chevron'] ?? null ) ? $menu_preset['chevron'] : [];

        $menu_font_size = max( 11, min( 28, (int) ( $menu_desktop['font_size'] ?? 14 ) ) );
        $menu_spacing_mode = self::sanitizeMenuSpacingMode(
            (string) ( $menu_desktop['item_spacing'] ?? 'normal' )
        );
        $menu_item_spacing = self::menuSpacingPixels( $menu_spacing_mode );
        $menu_hover_style = self::sanitizeMenuHoverStyle( (string) ( $menu_desktop['hover_style'] ?? 'fill' ) );
        $menu_active_style_v2 = self::sanitizeMenuDesktopActiveStyle( (string) ( $menu_desktop['active_style'] ?? 'underline' ) );
        $menu_dropdown_behavior = self::sanitizeMenuDropdownBehavior( (string) ( $menu_dropdown['behavior'] ?? 'hover' ) );
        $menu_dropdown_animation = self::sanitizeMenuDropdownAnimation( (string) ( $menu_dropdown['animation'] ?? 'fade' ) );
        $menu_dropdown_radius = max( 0, min( 30, (int) ( $menu_dropdown['radius'] ?? 10 ) ) );
        $menu_mobile_breakpoint = 980;
        $menu_mobile_style = 'hamburger';
        $menu_mobile_menu_type = 'slide';
        $menu_mobile_button_style = 'rounded';
        $menu_chevron_type = self::sanitizeMenuChevronType( (string) ( $menu_chevron['type'] ?? 'chevron' ) );
        $menu_chevron_animation_v2 = self::sanitizeMenuChevronAnimationV2( (string) ( $menu_chevron['animation'] ?? 'rotate' ) );
        $menu_vertical_align = 'center';
        $menu_item_radius = 10;
        $menu_button_variant = 'primary';
        $menu_button_radius = 10;
        $menu_button_padding_x = 14;
        $menu_button_padding_y = 10;
        $menu_button_bg = 'var(--metis-color-primary,#485bc7)';
        $menu_dropdown_highlight = 'var(--metis-color-surface_alt,#f8fafc)';
        $menu_dropdown_text = 'var(--metis-color-text,#1a1f2b)';
        $menu_dropdown_weight = '500';
        $menu_submenu_open_animation = self::sanitizeMenuSubmenuOpenAnimation( $menu_dropdown_animation );
        $menu_submenu_hover_animation = self::sanitizeMenuSubmenuHoverAnimation( $menu_hover_style );
        $menu_active_style = self::sanitizeMenuActiveStyle(
            $menu_active_style_v2 === 'none' ? 'text' : $menu_active_style_v2
        );
        $menu_active_color = 'var(--metis-color-primary,#485bc7)';
        $menu_chevron_color = 'var(--metis-color-primary,#485bc7)';
        $menu_chevron_animation = self::sanitizeMenuChevronAnimation(
            $menu_chevron_animation_v2 === 'rotate' ? 'flip' : 'none'
        );
        $menu_use_template_css = 0;
        $menu_button_bg_binding = '';
        $menu_active_color_binding = '';
        $menu_dropdown_highlight_binding = '';
        $menu_dropdown_text_binding = '';
        $menu_chevron_color_binding = '';
        $branding_bindings = [];
        if ( isset( $global_settings['branding_color_bindings'] ) && is_array( $global_settings['branding_color_bindings'] ) ) {
            foreach ( $global_settings['branding_color_bindings'] as $theme_key => $branding_key ) {
                $theme_key = metis_key_clean( (string) $theme_key );
                $branding_key = metis_key_clean( (string) $branding_key );
                if ( $theme_key === '' || $branding_key === '' ) {
                    continue;
                }
                $branding_bindings[ $theme_key ] = $branding_key;
            }
        }
        $footer_background_binding = metis_key_clean( (string) ( $global_settings['footer_background_binding'] ?? '' ) );
        $footer_background = self::sanitizeCssValue(
            (string) ( $components['footer']['background'] ?? ( $global_settings['footer_background'] ?? '#f8fafc' ) )
        );
        if ( $footer_background === '' ) {
            $footer_background = '#f8fafc';
        }
        $layout_tokens = is_array( $global_styles['layout_tokens'] ?? null ) ? $global_styles['layout_tokens'] : [];
        $breakpoints = is_array( $layout_tokens['breakpoints'] ?? null ) ? $layout_tokens['breakpoints'] : [];

        $global_styles_out = [
            'global_settings' => [
                'title_format' => $title_format,
                'site_layout_profile' => $site_layout_profile,
                'newsletter_layout_profile' => $newsletter_layout_profile,
                'menu_style' => $menu_style,
                'footer_background' => $footer_background,
                'footer_background_binding' => $footer_background_binding,
                'branding_color_bindings' => $branding_bindings,
            ],
            'layout' => [
                'max_width' => $container_site_px,
                'container_width' => $container_content_px,
                'spacing_preset' => $spacing_preset,
            ],
            'layout_tokens' => [
                'container_widths' => [
                    'site' => $container_site,
                    'content' => $container_content,
                ],
                'spacing_scale' => $merged_spacing,
                'breakpoints' => [
                    'sm' => self::sanitizeBreakpoint( $breakpoints['sm'] ?? 640, 640 ),
                    'md' => self::sanitizeBreakpoint( $breakpoints['md'] ?? 768, 768 ),
                    'lg' => self::sanitizeBreakpoint( $breakpoints['lg'] ?? 1024, 1024 ),
                    'xl' => self::sanitizeBreakpoint( $breakpoints['xl'] ?? 1280, 1280 ),
                ],
            ],
            'elements' => [
                'body' => [
                    'font_family' => $body_font,
                    'font_size' => $body_size,
                    'font_weight' => '400',
                    'line_height' => $line_height,
                    'color' => 'var(--metis-color-text,#1a1f2b)',
                    'background' => 'var(--metis-color-bg,#ffffff)',
                    'margin' => '0',
                ],
                'h1' => [ 'font_family' => $heading_font, 'font_size' => '2.2rem', 'font_weight' => $heading_weight, 'line_height' => '1.2', 'color' => 'var(--metis-color-text,#1a1f2b)', 'margin' => '0 0 .65em 0' ],
                'h2' => [ 'font_family' => $heading_font, 'font_size' => '1.9rem', 'font_weight' => $heading_weight, 'line_height' => '1.25', 'color' => 'var(--metis-color-text,#1a1f2b)', 'margin' => '0 0 .65em 0' ],
                'h3' => [ 'font_family' => $heading_font, 'font_size' => '1.6rem', 'font_weight' => $heading_weight, 'line_height' => '1.3', 'color' => 'var(--metis-color-text,#1a1f2b)', 'margin' => '0 0 .6em 0' ],
                'h4' => [ 'font_family' => $heading_font, 'font_size' => '1.35rem', 'font_weight' => $heading_weight, 'line_height' => '1.35', 'color' => 'var(--metis-color-text,#1a1f2b)', 'margin' => '0 0 .55em 0' ],
                'h5' => [ 'font_family' => $heading_font, 'font_size' => '1.15rem', 'font_weight' => $heading_weight, 'line_height' => '1.4', 'color' => 'var(--metis-color-text,#1a1f2b)', 'margin' => '0 0 .5em 0' ],
                'h6' => [ 'font_family' => $heading_font, 'font_size' => '1rem', 'font_weight' => $heading_weight, 'line_height' => '1.45', 'color' => 'var(--metis-color-text,#1a1f2b)', 'margin' => '0 0 .5em 0' ],
                'p' => [ 'font_family' => $body_font, 'font_size' => $body_size, 'font_weight' => '400', 'line_height' => $line_height, 'color' => 'var(--metis-color-text,#1a1f2b)', 'margin' => '0 0 1em 0' ],
                'a' => [
                    'font_family' => $body_font,
                    'font_size' => $body_size,
                    'font_weight' => self::sanitizeCssValue( (string) ( $link_comp['weight'] ?? '500' ) ),
                    'line_height' => $line_height,
                    'color' => 'var(--metis-color-link,#2b59ff)',
                    'text_decoration' => ! empty( $link_comp['underline'] ) ? 'underline' : 'none',
                ],
                'button' => [
                    'font_family' => $body_font,
                    'font_size' => $body_size,
                    'font_weight' => '600',
                    'line_height' => '1.2',
                    'color' => 'var(--metis-color-button_text,#ffffff)',
                    'background' => 'var(--metis-color-primary,#485bc7)',
                    'padding' => max( 4, (int) ( $button_comp['padding_y'] ?? 10 ) ) . 'px ' . max( 4, (int) ( $button_comp['padding_x'] ?? 14 ) ) . 'px',
                    'border_radius' => max( 0, (int) ( $button_comp['radius'] ?? 8 ) ) . 'px',
                    'box_shadow' => self::sanitizeCssValue( (string) ( $button_comp['shadow'] ?? '' ) ),
                    'border' => 'none',
                ],
                'input' => [
                    'font_family' => $body_font,
                    'font_size' => $body_size,
                    'line_height' => $line_height,
                    'color' => 'var(--metis-color-text,#1a1f2b)',
                    'background' => 'var(--metis-color-surface,#ffffff)',
                    'padding' => '10px 12px',
                    'border_radius' => max( 0, (int) ( $form_comp['radius'] ?? 8 ) ) . 'px',
                    'border' => max( 0, (int) ( $form_comp['border'] ?? 1 ) ) . 'px solid var(--metis-color-border,#d8deea)',
                    'box_shadow' => self::sanitizeCssValue( (string) ( $form_comp['shadow'] ?? '' ) ),
                ],
                'select' => [
                    'font_family' => $body_font, 'font_size' => $body_size, 'line_height' => $line_height, 'color' => 'var(--metis-color-text,#1a1f2b)',
                    'background' => 'var(--metis-color-surface,#ffffff)', 'padding' => '10px 12px', 'border_radius' => max( 0, (int) ( $form_comp['radius'] ?? 8 ) ) . 'px',
                    'border' => max( 0, (int) ( $form_comp['border'] ?? 1 ) ) . 'px solid var(--metis-color-border,#d8deea)', 'box_shadow' => self::sanitizeCssValue( (string) ( $form_comp['shadow'] ?? '' ) ),
                ],
                'textarea' => [
                    'font_family' => $body_font, 'font_size' => $body_size, 'line_height' => $line_height, 'color' => 'var(--metis-color-text,#1a1f2b)',
                    'background' => 'var(--metis-color-surface,#ffffff)', 'padding' => '10px 12px', 'border_radius' => max( 0, (int) ( $form_comp['radius'] ?? 8 ) ) . 'px',
                    'border' => max( 0, (int) ( $form_comp['border'] ?? 1 ) ) . 'px solid var(--metis-color-border,#d8deea)', 'box_shadow' => self::sanitizeCssValue( (string) ( $form_comp['shadow'] ?? '' ) ),
                ],
                'label' => [
                    'font_family' => $body_font, 'font_size' => '0.875rem', 'font_weight' => '600', 'line_height' => '1.35',
                    'color' => 'var(--metis-color-text,#1a1f2b)', 'margin' => '0 0 6px 0',
                ],
                'divider' => [
                    'background' => 'transparent',
                    'border' => '1px solid var(--metis-color-border,#d8deea)',
                ],
                'site_header' => [
                    'background' => 'var(--metis-color-surface,#ffffff)',
                    'border' => 'none',
                    'box_shadow' => '',
                    'padding' => '14px 0',
                    'sticky' => '1',
                    'shrink' => '1',
                    'compact_padding' => '8px 0',
                ],
                'section_header' => [
                    'font_family' => $heading_font,
                    'font_size' => '2.2rem',
                    'font_weight' => $heading_weight,
                    'line_height' => '1.2',
                    'color' => 'var(--metis-color-primary,#485bc7)',
                    'margin' => '0 0 10px 0',
                    'padding' => '35px 24px',
                    'background' => '#ececec',
                    'border' => 'none',
                ],
            ],
            'components' => [
                'buttons' => [
                    'radius' => max( 0, (int) ( $button_comp['radius'] ?? 8 ) ),
                    'padding_x' => max( 4, (int) ( $button_comp['padding_x'] ?? 14 ) ),
                    'padding_y' => max( 4, (int) ( $button_comp['padding_y'] ?? 10 ) ),
                    'shadow' => self::sanitizeCssValue( (string) ( $button_comp['shadow'] ?? '' ) ),
                ],
                'forms' => [
                    'radius' => max( 0, (int) ( $form_comp['radius'] ?? 8 ) ),
                    'border' => max( 0, (int) ( $form_comp['border'] ?? 1 ) ),
                    'shadow' => self::sanitizeCssValue( (string) ( $form_comp['shadow'] ?? '' ) ),
                ],
                'links' => [
                    'underline' => ! empty( $link_comp['underline'] ) ? 1 : 0,
                    'weight' => (int) ( is_numeric( $link_comp['weight'] ?? null ) ? $link_comp['weight'] : 500 ),
                ],
                'menu' => [
                    'style' => $menu_style,
                    'font_size' => $menu_font_size,
                    'item_spacing' => $menu_item_spacing,
                    'vertical_align' => $menu_vertical_align,
                    'item_radius' => $menu_item_radius,
                    'use_template_menu_css' => $menu_use_template_css,
                    'button_variant' => $menu_button_variant,
                    'button_radius' => $menu_button_radius,
                    'button_padding_x' => $menu_button_padding_x,
                    'button_padding_y' => $menu_button_padding_y,
                    'button_bg' => $menu_button_bg,
                    'active_style' => $menu_active_style,
                    'active_color' => $menu_active_color,
                    'dropdown_highlight' => $menu_dropdown_highlight,
                    'dropdown_text' => $menu_dropdown_text,
                    'dropdown_weight' => $menu_dropdown_weight,
                    'submenu_open_animation' => $menu_submenu_open_animation,
                    'submenu_hover_animation' => $menu_submenu_hover_animation,
                    'chevron_color' => $menu_chevron_color,
                    'dropdown_radius' => $menu_dropdown_radius,
                    'chevron_animation' => $menu_chevron_animation,
                    'bindings' => [
                        'button_bg' => $menu_button_bg_binding,
                        'active_color' => $menu_active_color_binding,
                        'dropdown_highlight' => $menu_dropdown_highlight_binding,
                        'dropdown_text' => $menu_dropdown_text_binding,
                        'chevron_color' => $menu_chevron_color_binding,
                    ],
                ],
                'menu_config' => [
                    'layout' => $menu_layout,
                    'alignment' => $menu_alignment,
                    'container' => $menu_container,
                    'desktop' => [
                        'font_size' => $menu_font_size,
                        'item_spacing' => $menu_spacing_mode,
                        'hover_style' => $menu_hover_style,
                        'active_style' => $menu_active_style_v2,
                    ],
                    'dropdown' => [
                        'behavior' => $menu_dropdown_behavior,
                        'animation' => $menu_dropdown_animation,
                        'radius' => $menu_dropdown_radius,
                    ],
                    'mobile' => [
                        'breakpoint' => $menu_mobile_breakpoint,
                        'style' => $menu_mobile_style,
                        'menu_type' => $menu_mobile_menu_type,
                        'button_style' => $menu_mobile_button_style,
                    ],
                    'chevron' => [
                        'type' => $menu_chevron_type,
                        'animation' => $menu_chevron_animation_v2,
                    ],
                ],
                'footer' => [
                    'background' => $footer_background,
                ],
                'cards' => [
                    'radius' => max( 0, (int) ( $cards_comp['radius'] ?? 12 ) ),
                    'padding' => max( 0, (int) ( $cards_comp['padding'] ?? 16 ) ),
                ],
            ],
            'advanced' => [
                'custom_css' => '',
            ],
        ];

        $incoming_elements = is_array( $global_styles['elements'] ?? null ) ? $global_styles['elements'] : [];
        foreach ( $incoming_elements as $el => $rule ) {
            if ( ! is_array( $rule ) || ! isset( $global_styles_out['elements'][ $el ] ) || ! is_array( $global_styles_out['elements'][ $el ] ) ) {
                continue;
            }
            foreach ( $rule as $prop => $value ) {
                if ( ! is_scalar( $value ) ) {
                    continue;
                }
                $clean_value = self::sanitizeCssValue( (string) $value );
                if ( $clean_value === '' ) {
                    continue;
                }
                $global_styles_out['elements'][ $el ][ (string) $prop ] = $clean_value;
            }
        }

        return [
            'global_styles' => $global_styles_out,
            'typography' => [
                'body_font' => $body_font,
                'heading_font' => $heading_font,
                'base_size' => $body_size,
                'line_height' => $line_height,
                'heading_weight' => $heading_weight,
                'font_source' => $font_source,
                'custom_fonts' => $custom_fonts,
            ],
            'colors' => $merged_colors,
            'spacing' => $merged_spacing,
            'custom_tokens' => self::sanitizeAssocValues( $custom_tokens ),
        ];
    }

    private static function decodeJsonObject( mixed $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( ! is_string( $value ) || trim( $value ) === '' ) {
            return [];
        }

        $decoded = json_decode( $value, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function sanitizeAssocValues( array $value ): array {
        $out = [];
        foreach ( $value as $key => $item ) {
            if ( ! is_scalar( $item ) ) {
                continue;
            }
            $name = self::sanitizeVarName( (string) $key );
            if ( $name === '' ) {
                continue;
            }
            $clean = self::sanitizeCssValue( (string) $item );
            if ( $clean === '' ) {
                continue;
            }
            $out[ $name ] = $clean;
        }
        return $out;
    }

    private static function sanitizeVarName( string $value ): string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9_-]+/', '-', $value ) ?? '';
        $value = trim( $value, '-_' );
        return substr( $value, 0, 64 );
    }

    private static function sanitizeCssValue( string $value ): string {
        $value = trim( str_replace( [ "\0", "\r" ], '', $value ) );
        if ( $value === '' ) {
            return '';
        }
        $value = preg_replace( '#</style#i', '', $value ) ?? $value;
        $value = preg_replace( '#expression\s*\(#i', '', $value ) ?? $value;
        $value = preg_replace( '#javascript:#i', '', $value ) ?? $value;
        return trim( $value );
    }

    private static function sanitizeCustomCss( string $css ): string {
        return self::sanitizeCssValue( $css );
    }

    private static function sanitizeBreakpoint( mixed $value, int $default ): int {
        $bp = is_numeric( $value ) ? (int) $value : $default;
        return max( 360, min( 2400, $bp ) );
    }

    private static function sanitizeMenuStyle( string $value ): string {
        $style = metis_key_clean( strtolower( trim( $value ) ) );
        $allowed = [
            'h_glide',
            'h_marker_dropdown',
            'h_pill_dropdown',
            'h_modern_bar',
        ];
        if ( ! in_array( $style, $allowed, true ) ) {
            return 'h_glide';
        }
        return $style;
    }

    /**
     * Public menu styles are presets. Saved legacy customization is preserved in storage
     * history, but rendering derives behavior from the selected preset only.
     *
     * @return array<string,mixed>
     */
    private static function menuPresetConfig( string $style ): array {
        $style = self::sanitizeMenuStyle( $style );
        $base = [
            'style' => $style,
            'layout' => 'horizontal_clean',
            'alignment' => 'left',
            'container' => 'contained',
            'desktop' => [
                'font_size' => 14,
                'item_spacing' => 'normal',
                'hover_style' => 'fill',
                'active_style' => 'underline',
            ],
            'dropdown' => [
                'behavior' => 'hover',
                'animation' => 'fade',
                'radius' => 10,
            ],
            'mobile' => [
                'breakpoint' => 980,
                'style' => 'hamburger',
                'menu_type' => 'slide',
                'button_style' => 'rounded',
            ],
            'chevron' => [
                'type' => 'chevron',
                'animation' => 'rotate',
            ],
        ];

        $presets = [
            'h_glide' => [
                'layout' => 'glide_gradient',
                'alignment' => 'center',
                'desktop' => [ 'hover_style' => 'none', 'active_style' => 'none' ],
                'dropdown' => [ 'animation' => 'scale', 'radius' => 8 ],
                'chevron' => [ 'type' => 'none', 'animation' => 'none' ],
            ],
            'h_marker_dropdown' => [
                'layout' => 'marker_dropdown',
                'alignment' => 'center',
                'desktop' => [ 'font_size' => 13, 'hover_style' => 'none', 'active_style' => 'none' ],
                'dropdown' => [ 'animation' => 'slide', 'radius' => 0 ],
                'chevron' => [ 'type' => 'none', 'animation' => 'none' ],
            ],
            'h_pill_dropdown' => [
                'alignment' => 'center',
                'desktop' => [ 'active_style' => 'pill' ],
                'dropdown' => [ 'animation' => 'scale', 'radius' => 18 ],
            ],
            'h_modern_bar' => [
                'alignment' => 'center',
                'desktop' => [ 'hover_style' => 'underline', 'active_style' => 'underline' ],
                'dropdown' => [ 'animation' => 'slide', 'radius' => 14 ],
            ],
        ];

        return array_replace_recursive( $base, $presets[ $style ], [ 'style' => $style ] );
    }

    private static function sanitizeMenuLayout( string $value ): string {
        $layout = metis_key_clean( strtolower( trim( $value ) ) );
        if ( $layout === 'centered' ) {
            $layout = 'centered_logo';
        } elseif ( $layout === 'split' ) {
            $layout = 'split_nav';
        } elseif ( $layout === 'sidebar' ) {
            $layout = 'sidebar_overlay';
        }
        $allowed = [ 'horizontal_clean', 'centered_logo', 'split_nav', 'minimal_topbar', 'glide_gradient', 'marker_dropdown', 'sidebar_overlay' ];
        if ( ! in_array( $layout, $allowed, true ) ) {
            return 'horizontal_clean';
        }
        return $layout;
    }

    private static function sanitizeMenuSpacingMode( string $value ): string {
        $raw = strtolower( trim( $value ) );
        if ( is_numeric( $raw ) ) {
            $px = (int) $raw;
            if ( $px <= 12 ) {
                return 'tight';
            }
            if ( $px >= 22 ) {
                return 'wide';
            }
            return 'normal';
        }
        $mode = metis_key_clean( $raw );
        if ( ! in_array( $mode, [ 'tight', 'normal', 'wide' ], true ) ) {
            return 'normal';
        }
        return $mode;
    }

    private static function menuSpacingPixels( string $mode ): int {
        return match ( self::sanitizeMenuSpacingMode( $mode ) ) {
            'tight' => 10,
            'wide' => 24,
            default => 16,
        };
    }

    private static function sanitizeMenuAlignment( string $value ): string {
        $align = metis_key_clean( strtolower( trim( $value ) ) );
        $allowed = [ 'left', 'center', 'right' ];
        if ( ! in_array( $align, $allowed, true ) ) {
            return 'left';
        }
        return $align;
    }

    private static function sanitizeMenuContainer( string $value ): string {
        $container = metis_key_clean( strtolower( trim( $value ) ) );
        if ( ! in_array( $container, [ 'full', 'contained' ], true ) ) {
            return 'contained';
        }
        return $container;
    }

    private static function sanitizeMenuHoverStyle( string $value ): string {
        $style = metis_key_clean( strtolower( trim( $value ) ) );
        if ( ! in_array( $style, [ 'underline', 'fill', 'none' ], true ) ) {
            return 'fill';
        }
        return $style;
    }

    private static function sanitizeMenuDesktopActiveStyle( string $value ): string {
        $style = metis_key_clean( strtolower( trim( $value ) ) );
        if ( ! in_array( $style, [ 'pill', 'underline', 'none' ], true ) ) {
            return 'underline';
        }
        return $style;
    }

    private static function sanitizeMenuDropdownAnimation( string $value ): string {
        $animation = metis_key_clean( strtolower( trim( $value ) ) );
        if ( ! in_array( $animation, [ 'fade', 'scale', 'slide', 'none' ], true ) ) {
            return 'fade';
        }
        return $animation;
    }

    private static function sanitizeMenuDropdownBehavior( string $value ): string {
        $behavior = metis_key_clean( strtolower( trim( $value ) ) );
        if ( ! in_array( $behavior, [ 'hover', 'click' ], true ) ) {
            return 'hover';
        }
        return $behavior;
    }

    private static function sanitizeMenuChevronType( string $value ): string {
        $type = metis_key_clean( strtolower( trim( $value ) ) );
        if ( ! in_array( $type, [ 'arrow', 'chevron', 'none' ], true ) ) {
            return 'chevron';
        }
        return $type;
    }

    private static function sanitizeMenuChevronAnimationV2( string $value ): string {
        $animation = metis_key_clean( strtolower( trim( $value ) ) );
        if ( ! in_array( $animation, [ 'rotate', 'none' ], true ) ) {
            return 'rotate';
        }
        return $animation;
    }

    private static function sanitizeMenuVerticalAlign( string $value ): string {
        $align = metis_key_clean( strtolower( trim( $value ) ) );
        $allowed = [ 'top', 'center', 'bottom' ];
        if ( ! in_array( $align, $allowed, true ) ) {
            return 'center';
        }
        return $align;
    }

    private static function menuVerticalAlignToCss( string $value ): string {
        return match ( self::sanitizeMenuVerticalAlign( $value ) ) {
            'top' => 'flex-start',
            'bottom' => 'flex-end',
            default => 'center',
        };
    }

    private static function sanitizeMenuChevronAnimation( string $value ): string {
        $mode = metis_key_clean( strtolower( trim( $value ) ) );
        $allowed = [ 'none', 'flip', 'color', 'flip_color' ];
        if ( ! in_array( $mode, $allowed, true ) ) {
            return 'flip';
        }
        return $mode;
    }

    private static function sanitizeMenuSubmenuOpenAnimation( string $value ): string {
        $mode = metis_key_clean( strtolower( trim( $value ) ) );
        $allowed = [ 'none', 'fade', 'slide', 'scale' ];
        if ( ! in_array( $mode, $allowed, true ) ) {
            return 'fade';
        }
        return $mode;
    }

    private static function sanitizeMenuSubmenuHoverAnimation( string $value ): string {
        $mode = metis_key_clean( strtolower( trim( $value ) ) );
        if ( $mode === 'diag_fill' || $mode === 'wave_fill' ) {
            $mode = 'fill';
        }
        $allowed = [ 'none', 'fill', 'lift', 'underline' ];
        if ( ! in_array( $mode, $allowed, true ) ) {
            return 'fill';
        }
        return $mode;
    }

    private static function sanitizeMenuActiveStyle( string $value ): string {
        $mode = metis_key_clean( strtolower( trim( $value ) ) );
        $allowed = [ 'text', 'underline', 'pill', 'none' ];
        if ( ! in_array( $mode, $allowed, true ) ) {
            return 'text';
        }
        return $mode;
    }

    private static function sanitizeMenuDropdownWeight( string $value ): string {
        $weight = preg_replace( '/[^0-9]/', '', $value ) ?? '';
        $allowed = [ '300', '400', '500', '600', '700', '800' ];
        if ( ! in_array( $weight, $allowed, true ) ) {
            return '500';
        }
        return $weight;
    }

    /**
     * @param array<string,mixed> $fonts
     * @return array<string,array{name:string,data:string,format:string}>
     */
    private static function sanitizeCustomFonts( array $fonts ): array {
        $out = [];
        foreach ( $fonts as $key => $font ) {
            if ( ! is_array( $font ) ) {
                continue;
            }
            $name = self::sanitizeFontFamilyName( (string) ( $font['name'] ?? '' ) );
            $data = self::sanitizeFontDataUri( (string) ( $font['data'] ?? '' ) );
            if ( $name === '' || $data === '' ) {
                continue;
            }
            $format = self::sanitizeFontFormat( (string) ( $font['format'] ?? 'woff2' ) );
            $map_key = self::sanitizeVarName( (string) $key );
            if ( $map_key === '' ) {
                $map_key = self::sanitizeVarName( strtolower( preg_replace( '/\s+/', '_', $name ) ?? $name ) );
            }
            if ( $map_key === '' ) {
                continue;
            }
            $out[ $map_key ] = [
                'name' => $name,
                'data' => $data,
                'format' => $format,
            ];
        }
        return $out;
    }

    private static function sanitizeFontFamilyName( string $value ): string {
        $value = trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );
        $value = preg_replace( '/[^A-Za-z0-9 _-]/', '', $value ) ?? '';
        return substr( trim( $value ), 0, 80 );
    }

    private static function sanitizeFontFormat( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( ! in_array( $value, [ 'woff2', 'woff', 'truetype', 'opentype' ], true ) ) {
            return 'woff2';
        }
        return $value;
    }

    private static function sanitizeFontDataUri( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }
        if ( strlen( $value ) > 3_000_000 ) {
            return '';
        }
        if ( preg_match( '/^data:[a-z0-9.+\\/-]+;base64,[a-z0-9+\\/=\\r\\n]+$/i', $value ) !== 1 ) {
            return '';
        }
        return str_replace( [ "\r", "\n" ], '', $value );
    }

    /**
     * @param array<string,mixed> $fonts
     * @return array<string,true>
     */
    private static function declaredCustomFontFamilies( array $fonts ): array {
        $names = [];
        foreach ( $fonts as $font ) {
            if ( ! is_array( $font ) ) {
                continue;
            }
            $name = strtolower( self::sanitizeFontFamilyName( (string) ( $font['name'] ?? '' ) ) );
            if ( $name !== '' ) {
                $names[ $name ] = true;
            }
        }
        return $names;
    }

    /**
     * @return array<int,string>
     */
    private static function localFontFacesCss( array $selected_families = [] ): array {
        $roots = [
            [ 'dir' => METIS_ASSETS_PATH . 'fonts', 'source' => 'runtime' ],
            [ 'dir' => METIS_MODULES_PATH . 'website/assets/fonts', 'source' => 'module' ],
        ];
        $faces = [];
        $seen_files = [];
        $style_keywords = [ 'thin', 'extralight', 'ultralight', 'light', 'regular', 'normal', 'book', 'medium', 'semibold', 'demibold', 'bold', 'extrabold', 'ultrabold', 'black', 'heavy', 'italic', 'oblique' ];
        $selected_families = array_change_key_case( $selected_families, CASE_LOWER );
        foreach ( $roots as $root_meta ) {
            $root = (string) ( $root_meta['dir'] ?? '' );
            $source = (string) ( $root_meta['source'] ?? '' );
            if ( ! is_dir( $root ) ) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file_info ) {
                if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
                    continue;
                }
                $entry = (string) $file_info->getFilename();
                if ( preg_match( '/\.(woff2|woff|ttf|otf)$/i', $entry ) !== 1 ) {
                    continue;
                }
                $full_path = (string) $file_info->getPathname();
                $relative = ltrim( str_replace( '\\', '/', substr( $full_path, strlen( $root ) ) ), '/' );
                $entry_key = strtolower( $relative );
                if ( isset( $seen_files[ $entry_key ] ) ) {
                    continue;
                }
                $seen_files[ $entry_key ] = true;

                $meta = self::parseLocalFontFilename( pathinfo( $entry, PATHINFO_FILENAME ) );
                $family = self::sanitizeFontFamilyName( (string) ( $meta['family'] ?? '' ) );
                if ( $family === '' ) {
                    continue;
                }
                if ( in_array( strtolower( $family ), $style_keywords, true ) || preg_match( '/^[1-9]00$/', $family ) === 1 ) {
                    $dir_hint = basename( str_replace( '\\', '/', dirname( $relative ) ) );
                    $dir_hint = trim( preg_replace( '/\s+/', ' ', str_replace( [ '_', '-' ], ' ', $dir_hint ) ) ?? $dir_hint );
                    if ( $dir_hint !== '' && strtolower( $dir_hint ) !== 'fonts' ) {
                        $family = self::sanitizeFontFamilyName( $dir_hint );
                    }
                }
                if ( $family === '' ) {
                    continue;
                }
                $family_key = strtolower( $family );
                if ( $selected_families !== [] && ! isset( $selected_families[ $family_key ] ) ) {
                    continue;
                }

                $format = self::sanitizeFontFormat( (string) pathinfo( $entry, PATHINFO_EXTENSION ) );
                $weight = (string) ( $meta['weight'] ?? '400' );
                $style = (string) ( $meta['style'] ?? 'normal' );
                if ( $source === 'runtime' ) {
                    $url = function_exists( 'metis_home_url' )
                        ? (string) metis_home_url( '/assets/fonts/' . self::encodeAssetPath( $relative ) )
                        : '/assets/fonts/' . self::encodeAssetPath( $relative );
                } else {
                    $url = function_exists( 'metis_module_asset_url' )
                        ? (string) metis_module_asset_url( 'website', 'fonts/' . $relative )
                        : ( function_exists( 'metis_home_url' )
                            ? (string) metis_home_url( '/assets/modules/website/fonts/' . self::encodeAssetPath( $relative ) )
                            : '/assets/modules/website/fonts/' . self::encodeAssetPath( $relative )
                        );
                }
                $faces[] = '@font-face{font-family:"' . str_replace( '"', '', $family ) . '";src:url("' . self::sanitizeCssValue( $url ) . '") format("' . $format . '");font-weight:' . $weight . ';font-style:' . $style . ';font-display:swap;}';
            }
        }
        return $faces;
    }

    /**
     * @param array<string,mixed> $typography
     * @return array<string,true>
     */
    private static function selectedThemeFontFamilies( array $typography ): array {
        $selected = [];
        foreach ( [ 'body_font', 'heading_font' ] as $font_key ) {
            $family = self::extractPrimaryFontFamily( (string) ( $typography[ $font_key ] ?? '' ) );
            if ( $family === '' ) {
                continue;
            }
            $family_key = strtolower( $family );
            if ( self::isGenericFontFamily( $family_key ) ) {
                continue;
            }
            $selected[ $family_key ] = true;
        }
        return $selected;
    }

    /**
     * @return array{family:string,weight:string,style:string}
     */
    private static function parseLocalFontFilename( string $filename ): array {
        $name = trim( preg_replace( '/\s+/', ' ', str_replace( [ '_', '-' ], ' ', $filename ) ) ?? $filename );
        $tokens = preg_split( '/\s+/', strtolower( $name ) ) ?: [];
        $weight_map = [
            'thin' => '100',
            'extralight' => '200',
            'ultralight' => '200',
            'light' => '300',
            'regular' => '400',
            'normal' => '400',
            'book' => '400',
            'medium' => '500',
            'semibold' => '600',
            'demibold' => '600',
            'bold' => '700',
            'extrabold' => '800',
            'ultrabold' => '800',
            'black' => '900',
            'heavy' => '900',
        ];

        $weight = '400';
        $style = 'normal';
        while ( $tokens !== [] ) {
            $last = (string) end( $tokens );
            if ( $last === 'italic' || $last === 'oblique' ) {
                $style = 'italic';
                array_pop( $tokens );
                continue;
            }
            if ( isset( $weight_map[ $last ] ) ) {
                $weight = $weight_map[ $last ];
                array_pop( $tokens );
                continue;
            }
            if ( preg_match( '/^[1-9]00$/', $last ) === 1 ) {
                $weight = $last;
                array_pop( $tokens );
                continue;
            }
            break;
        }

        $family = trim( implode( ' ', $tokens ) );
        if ( $family === '' ) {
            $family = $name;
        }
        $family = ucwords( $family );
        return [
            'family' => $family,
            'weight' => $weight,
            'style' => $style,
        ];
    }

    private static function extractPrimaryFontFamily( string $font_stack ): string {
        $stack = trim( $font_stack );
        if ( $stack === '' ) {
            return '';
        }
        $primary = trim( explode( ',', $stack )[0] ?? '' );
        $primary = trim( $primary, "\"'" );
        return self::sanitizeFontFamilyName( $primary );
    }

    private static function isGenericFontFamily( string $value ): bool {
        $value = strtolower( trim( $value ) );
        return in_array(
            $value,
            [
                'inherit',
                'initial',
                'unset',
                'serif',
                'sans-serif',
                'monospace',
                'cursive',
                'fantasy',
                'system-ui',
                '-apple-system',
                'ui-sans-serif',
                'ui-serif',
                'ui-monospace',
            ],
            true
        );
    }

    private static function encodeAssetPath( string $path ): string {
        $clean = trim( str_replace( '\\', '/', $path ), '/' );
        if ( $clean === '' ) {
            return '';
        }
        $parts = explode( '/', $clean );
        $parts = array_map(
            static fn ( string $part ): string => rawurlencode( $part ),
            $parts
        );
        return implode( '/', $parts );
    }

    private static function renderRule( string $selector, array $rule ): string {
        $allowed = [
            'font_family' => 'font-family',
            'font_size' => 'font-size',
            'font_weight' => 'font-weight',
            'line_height' => 'line-height',
            'color' => 'color',
            'margin' => 'margin',
            'padding' => 'padding',
            'border_radius' => 'border-radius',
            'box_shadow' => 'box-shadow',
            'text_decoration' => 'text-decoration',
            'background' => 'background',
            'border' => 'border',
        ];

        $lines = [];
        foreach ( $allowed as $key => $property ) {
            if ( ! isset( $rule[ $key ] ) || ! is_scalar( $rule[ $key ] ) ) {
                continue;
            }
            $value = self::sanitizeCssValue( (string) $rule[ $key ] );
            if ( $value === '' ) {
                continue;
            }
            $lines[] = '  ' . $property . ':' . $value . ';';
        }

        if ( $lines === [] ) {
            return '';
        }

        return $selector . "{\n" . implode( "\n", $lines ) . "\n}";
    }

    private static function renderSiteHeaderRule( array $rule ): string {
        $background = self::sanitizeCssValue( (string) ( $rule['background'] ?? '' ) );
        $border = self::sanitizeCssValue( (string) ( $rule['border'] ?? '' ) );
        $box_shadow = self::sanitizeCssValue( (string) ( $rule['box_shadow'] ?? '' ) );
        $padding = self::sanitizeCssValue( (string) ( $rule['padding'] ?? '' ) );
        $compact_padding = self::sanitizeCssValue( (string) ( $rule['compact_padding'] ?? '' ) );
        $sticky = ! empty( $rule['sticky'] ) && (string) $rule['sticky'] !== '0';
        $shrink = ! empty( $rule['shrink'] ) && (string) $rule['shrink'] !== '0';

        $lines = [];
        $header_lines = [];
        if ( $padding !== '' ) {
            $header_lines[] = 'padding:' . $padding . ' !important;';
        }
        if ( $sticky ) {
            $header_lines[] = 'position:sticky !important;';
            $header_lines[] = 'top:0 !important;';
            $header_lines[] = 'z-index:1000 !important;';
        } else {
            $header_lines[] = 'position:relative !important;';
            $header_lines[] = 'top:auto !important;';
        }
        if ( $background !== '' ) {
            $header_lines[] = 'background:' . $background . ' !important;';
        }
        if ( $border !== '' ) {
            $header_lines[] = 'border-bottom:' . ( $border === 'none' ? 'none' : $border ) . ' !important;';
        }
        if ( $box_shadow !== '' ) {
            $header_lines[] = 'box-shadow:' . $box_shadow . ' !important;';
        }
        $header_lines[] = 'transition:padding .18s ease, box-shadow .18s ease, background .18s ease;';
        if ( $header_lines !== [] ) {
            $lines[] = '.metis-template-header,.metis-shell-header{' . implode( '', $header_lines ) . '}';
            $lines[] = '.metis-template-header::before,.metis-shell-header::before{background:transparent !important;border:0 !important;box-shadow:none !important;}';
        }
        if ( $shrink && $compact_padding !== '' ) {
            $lines[] = '.metis-template-header.is-scrolled,.metis-shell-header.is-scrolled{padding:' . $compact_padding . ' !important;}';
        }
        return implode( "\n", $lines );
    }

    private static function renderSectionHeaderRule( array $rule ): string {
        $head_selector = 'body.metis-public-site .metis-structured-section > .metis-structured-section__head, body.metis-public-site .metis-template-post-header .metis-structured-section__head--post';
        $title_selector = 'body.metis-public-site .metis-structured-section > .metis-structured-section__head h1, body.metis-public-site .metis-template-post-header .metis-structured-section__head--post h1';
        $subtext_selector = 'body.metis-public-site .metis-structured-section > .metis-structured-section__head .metis-structured-section__subtext, body.metis-public-site .metis-template-post-header .metis-structured-section__head--post .metis-structured-section__subtext';

        $head_lines = [];
        foreach ( [ 'background' => 'background', 'padding' => 'padding', 'border' => 'border', 'box_shadow' => 'box-shadow' ] as $key => $property ) {
            if ( ! isset( $rule[ $key ] ) || ! is_scalar( $rule[ $key ] ) ) {
                continue;
            }
            $value = self::sanitizeCssValue( (string) $rule[ $key ] );
            if ( $value === '' ) {
                continue;
            }
            $head_lines[] = '  ' . $property . ':' . $value . ';';
        }

        $title_lines = [ '  margin:0;' ];
        foreach ( [ 'font_size' => 'font-size', 'font_weight' => 'font-weight', 'line_height' => 'line-height', 'color' => 'color' ] as $key => $property ) {
            if ( ! isset( $rule[ $key ] ) || ! is_scalar( $rule[ $key ] ) ) {
                continue;
            }
            $value = self::sanitizeCssValue( (string) $rule[ $key ] );
            if ( $value === '' ) {
                continue;
            }
            $title_lines[] = '  ' . $property . ':' . $value . ';';
        }

        $subtext_lines = [ '  margin:0 auto;', '  max-width:70ch;' ];
        if ( isset( $rule['color'] ) && is_scalar( $rule['color'] ) ) {
            $value = self::sanitizeCssValue( (string) $rule['color'] );
            if ( $value !== '' ) {
                $subtext_lines[] = '  color:' . $value . ';';
            }
        }

        $blocks = [];
        if ( count( $head_lines ) > 0 ) {
            $blocks[] = $head_selector . "{\n" . implode( "\n", $head_lines ) . "\n}";
        }
        if ( count( $title_lines ) > 1 ) {
            $blocks[] = $title_selector . "{\n" . implode( "\n", $title_lines ) . "\n}";
        }
        if ( count( $subtext_lines ) > 2 ) {
            $blocks[] = $subtext_selector . "{\n" . implode( "\n", $subtext_lines ) . "\n}";
        }

        return implode( "\n", $blocks );
    }
}
