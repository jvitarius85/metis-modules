<?php
if ( ! defined( 'METIS_ROOT' ) ) {
    exit;
}

require_once __DIR__ . '/_access.php';
if ( ! metis_website_require_view_permission( 'theme' ) ) {
    return;
}

use Metis\Modules\Website\Services\ThemeService;

$theme = ThemeService::getActiveNormalized();
$branding_colors = class_exists( 'Core_Settings_Service' ) ? \Core_Settings_Service::get( 'theme_colors', [] ) : [];
if ( ! is_array( $branding_colors ) ) {
    $branding_colors = [];
}

$typography = is_array( $theme['typography'] ?? null ) ? $theme['typography'] : [];
$colors     = is_array( $theme['colors'] ?? null ) ? $theme['colors'] : [];
$spacing    = is_array( $theme['spacing'] ?? null ) ? $theme['spacing'] : [];
$custom     = is_array( $theme['custom_tokens'] ?? null ) ? $theme['custom_tokens'] : [];
$global_styles = [
    'global_settings' => is_array( $theme['global_settings'] ?? null ) ? $theme['global_settings'] : [],
    'layout'          => is_array( $theme['layout'] ?? null ) ? $theme['layout'] : [],
    'layout_tokens'   => is_array( $theme['layout_tokens'] ?? null ) ? $theme['layout_tokens'] : [],
    'elements'        => is_array( $theme['elements'] ?? null ) ? $theme['elements'] : [],
    'components'      => is_array( $theme['components'] ?? null ) ? $theme['components'] : [],
    'advanced'        => [
        'custom_css' => (string) ( $theme['custom_css'] ?? '' ),
    ],
];

$branding_field_defs = [
    'metis_primary' => [ 'label' => 'Primary', 'default' => '#485bc7' ],
    'metis_primary_dark' => [ 'label' => 'Primary Dark', 'default' => '#3246a7' ],
    'metis_accent' => [ 'label' => 'Accent', 'default' => '#ff7542' ],
    'metis_bg' => [ 'label' => 'Background', 'default' => '#f5f6fa' ],
    'metis_surface' => [ 'label' => 'Surface', 'default' => '#ffffff' ],
    'metis_border' => [ 'label' => 'Border', 'default' => '#e0e2ea' ],
    'metis_text' => [ 'label' => 'Text', 'default' => '#1f2330' ],
    'metis_text_muted' => [ 'label' => 'Muted Text', 'default' => '#6d7485' ],
    'metis_header_bg' => [ 'label' => 'Header Background', 'default' => '#eceeff' ],
    'metis_row_odd_bg' => [ 'label' => 'Row Odd Background', 'default' => '#ffffff' ],
    'metis_row_even_bg' => [ 'label' => 'Row Even Background', 'default' => '#f8f9fd' ],
    'metis_row_hover_bg' => [ 'label' => 'Row Hover Background', 'default' => '#eef2ff' ],
    'metis_sidebar_bg' => [ 'label' => 'Sidebar Background', 'default' => '#16192b' ],
    'metis_sidebar_icon_color' => [ 'label' => 'Sidebar Icon', 'default' => '#7a82a6' ],
    'metis_sidebar_active_color' => [ 'label' => 'Sidebar Active', 'default' => '#a8b4ff' ],
];
$branding_palette = [];
foreach ( $branding_field_defs as $key => $field ) {
    $default = (string) ( $field['default'] ?? '#000000' );
    $raw = (string) ( $branding_colors[ $key ] ?? $default );
    $branding_palette[ $key ] = metis_hex_color_clean( $raw ) ?: $default;
}
$branding_field_labels = [];
foreach ( $branding_field_defs as $key => $field ) {
    $branding_field_labels[ $key ] = (string) ( $field['label'] ?? $key );
}

$branding_binding_defaults = [
    'primary' => 'metis_primary',
    'accent' => 'metis_accent',
    'text' => 'metis_text',
    'muted' => 'metis_text_muted',
    'bg' => 'metis_bg',
    'surface' => 'metis_surface',
    'border' => 'metis_border',
    'link' => 'metis_primary',
    'link_hover' => 'metis_primary_dark',
    'button_text' => 'metis_surface',
    'form_bg' => 'metis_surface',
    'card_bg' => 'metis_surface',
    'card_border' => 'metis_border',
];

$color_defaults = [
    'primary'       => $branding_palette['metis_primary'],
    'accent'        => $branding_palette['metis_accent'],
    'text'          => $branding_palette['metis_text'],
    'muted'         => $branding_palette['metis_text_muted'],
    'bg'            => $branding_palette['metis_bg'],
    'surface'       => $branding_palette['metis_surface'],
    'border'        => $branding_palette['metis_border'],
    'success'       => '#198754',
    'warning'       => '#b54708',
    'danger'        => '#dc3545',
    'link'          => '#2b59ff',
    'link_hover'    => '#1639b8',
    'button_text'   => '#ffffff',
    'form_bg'       => '#ffffff',
    'card_bg'       => '#ffffff',
    'card_border'   => '#d8deea',
];

$typography_defaults = [
    'base_size'      => '16',
    'line_height'    => '1.6',
    'heading_weight' => '600',
    'body_font'      => 'Inter, system-ui, -apple-system, Segoe UI, sans-serif',
    'heading_font'   => 'Inter, system-ui, -apple-system, Segoe UI, sans-serif',
    'font_source'    => 'system',
    'custom_fonts'   => [],
];
$heading_weight_options = [
    '300' => 'Light',
    '400' => 'Regular',
    '600' => 'Bold',
    '800' => 'Heavy',
];
$line_height_options = [
    '1.25' => 'Tight',
    '1.45' => 'Cozy',
    '1.60' => 'Comfy',
    '1.80' => 'Relaxed',
];

$spacing_defaults = [
    'xs'  => '4px',
    'sm'  => '8px',
    'md'  => '16px',
    'lg'  => '24px',
    'xl'  => '40px',
    'xxl' => '64px',
];

$element_defaults = [
    'body' => [ 'font_family' => '', 'font_size' => '16px', 'font_weight' => '400', 'line_height' => '1.6', 'color' => '#1a1f2b', 'margin' => '0', 'padding' => '0', 'background' => '#ffffff' ],
    'site_header' => [ 'background' => '#ffffff', 'border' => 'none', 'box_shadow' => '', 'padding' => '14px 0', 'sticky' => '1', 'shrink' => '1', 'compact_padding' => '8px 0' ],
    'h1' => [ 'font_family' => '', 'font_size' => '2.2rem', 'font_weight' => '700', 'line_height' => '1.2', 'color' => '#1a1f2b', 'margin' => '0 0 .65em 0', 'padding' => '' ],
    'h2' => [ 'font_family' => '', 'font_size' => '1.9rem', 'font_weight' => '700', 'line_height' => '1.25', 'color' => '#1a1f2b', 'margin' => '0 0 .65em 0', 'padding' => '' ],
    'h3' => [ 'font_family' => '', 'font_size' => '1.6rem', 'font_weight' => '700', 'line_height' => '1.3', 'color' => '#1a1f2b', 'margin' => '0 0 .6em 0', 'padding' => '' ],
    'h4' => [ 'font_family' => '', 'font_size' => '1.35rem', 'font_weight' => '700', 'line_height' => '1.35', 'color' => '#1a1f2b', 'margin' => '0 0 .55em 0', 'padding' => '' ],
    'h5' => [ 'font_family' => '', 'font_size' => '1.15rem', 'font_weight' => '700', 'line_height' => '1.4', 'color' => '#1a1f2b', 'margin' => '0 0 .5em 0', 'padding' => '' ],
    'h6' => [ 'font_family' => '', 'font_size' => '1rem', 'font_weight' => '700', 'line_height' => '1.45', 'color' => '#1a1f2b', 'margin' => '0 0 .5em 0', 'padding' => '' ],
    'p' => [ 'font_family' => '', 'font_size' => '16px', 'font_weight' => '400', 'line_height' => '1.6', 'color' => '#1a1f2b', 'margin' => '0 0 1em 0', 'padding' => '' ],
    'a' => [ 'font_family' => '', 'font_size' => '16px', 'font_weight' => '500', 'line_height' => '1.6', 'color' => '#2b59ff', 'background' => '', 'text_decoration' => 'underline' ],
    'button' => [ 'font_family' => '', 'font_size' => '16px', 'font_weight' => '600', 'line_height' => '1.2', 'color' => '#ffffff', 'padding' => '10px 14px', 'border_radius' => '8px', 'box_shadow' => 'none', 'background' => '#485bc7', 'border' => 'none' ],
    'input' => [ 'font_family' => '', 'font_size' => '16px', 'font_weight' => '400', 'line_height' => '1.6', 'color' => '#1a1f2b', 'padding' => '10px 12px', 'border_radius' => '8px', 'box_shadow' => '', 'background' => '#ffffff', 'border' => '1px solid #d8deea' ],
    'select' => [ 'font_family' => '', 'font_size' => '16px', 'font_weight' => '400', 'line_height' => '1.6', 'color' => '#1a1f2b', 'padding' => '10px 12px', 'border_radius' => '8px', 'box_shadow' => '', 'background' => '#ffffff', 'border' => '1px solid #d8deea' ],
    'textarea' => [ 'font_family' => '', 'font_size' => '16px', 'font_weight' => '400', 'line_height' => '1.6', 'color' => '#1a1f2b', 'padding' => '10px 12px', 'border_radius' => '8px', 'box_shadow' => '', 'background' => '#ffffff', 'border' => '1px solid #d8deea' ],
    'label' => [ 'font_family' => '', 'font_size' => '0.875rem', 'font_weight' => '600', 'line_height' => '1.35', 'color' => '#1a1f2b', 'margin' => '0 0 6px 0' ],
    'divider' => [ 'background' => 'transparent', 'border' => '1px solid #d8deea' ],
    'section_header' => [ 'font_family' => '', 'font_size' => '2.2rem', 'font_weight' => '700', 'line_height' => '1.2', 'color' => '#485bc7', 'margin' => '0 0 10px 0', 'padding' => '35px 24px', 'background' => '#ececec', 'border' => 'none' ],
];

$global_defaults = [
    'global_settings' => [
        'title_format' => '{page} | {site}',
        'site_layout_profile' => 'modern_split',
        'newsletter_layout_profile' => 'newsletter_standard',
        'branding_color_bindings' => $branding_binding_defaults,
    ],
    'layout_tokens' => [
        'breakpoints' => [
            'sm' => 640,
            'md' => 768,
            'lg' => 1024,
            'xl' => 1280,
        ],
    ],
    'brand' => [
        'logo_data'    => '',
        'logo_alt'     => 'Logo',
        'favicon_data' => '',
    ],
    'layout' => [
        'max_width'       => 1200,
        'container_width' => 860,
        'spacing_preset'  => 'balanced',
    ],
    'components' => [
        'buttons' => [
            'radius'     => 8,
            'padding_y'  => 10,
            'padding_x'  => 14,
            'shadow'     => 'none',
        ],
        'menu' => [
            'style' => 'h_glide',
            'font_size' => 14,
            'vertical_align' => 'center',
            'item_radius' => 10,
            'use_template_menu_css' => 0,
            'button_variant' => 'primary',
            'button_radius' => 10,
            'button_padding_x' => 14,
            'button_padding_y' => 10,
            'button_bg' => 'var(--metis-color-primary,#485bc7)',
            'active_style' => 'text',
            'active_color' => 'var(--metis-color-primary,#485bc7)',
            'dropdown_highlight' => 'var(--metis-color-surface_alt,#f8fafc)',
            'dropdown_text' => 'var(--metis-color-text,#1a1f2b)',
            'dropdown_weight' => '500',
            'submenu_open_animation' => 'fade',
            'submenu_hover_animation' => 'fill',
            'chevron_color' => 'var(--metis-color-primary,#485bc7)',
            'dropdown_radius' => 10,
            'chevron_animation' => 'flip',
            'bindings' => [
                'button_bg' => '',
                'active_color' => '',
                'dropdown_highlight' => '',
                'dropdown_text' => '',
                'chevron_color' => '',
            ],
        ],
        'footer' => [
            'background' => '#f8fafc',
        ],
        'cards' => [
            'radius' => 12,
            'shadow' => '0 1px 3px rgba(16,24,40,.08)',
            'padding' => 16,
        ],
        'forms' => [
            'radius' => 8,
            'border' => 1,
            'focus_ring' => '#2b59ff',
        ],
        'links' => [
            'underline' => 1,
            'weight'    => 500,
        ],
    ],
    'elements' => $element_defaults,
    'advanced' => [
        'custom_css' => '',
    ],
];

$menu_style_options = [
    'h_glide' => 'Glide Gradient',
    'h_marker_dropdown' => 'Marker Dropdown',
    'h_pill_dropdown' => 'Pill Dropdown',
    'h_modern_bar' => 'Modern Bar',
];
$custom_defaults = [
    'tokens' => [],
];

$custom_input = is_array( $custom ) ? $custom : [];
if ( ! isset( $custom_input['tokens'] ) || ! is_array( $custom_input['tokens'] ) ) {
    $custom_input = [ 'tokens' => $custom_input ];
}

$colors = array_merge( $color_defaults, is_array( $colors ) ? $colors : [] );
$typography = array_merge( $typography_defaults, is_array( $typography ) ? $typography : [] );
$spacing = array_merge( $spacing_defaults, is_array( $spacing ) ? $spacing : [] );
$global_styles = array_replace_recursive( $global_defaults, is_array( $global_styles ) ? $global_styles : [] );
$custom = array_replace_recursive( $custom_defaults, $custom_input );
$menu_component = is_array( $global_styles['components']['menu'] ?? null ) ? $global_styles['components']['menu'] : [];
$menu_visual_style = (string) ( $menu_component['style'] ?? ( $global_styles['global_settings']['menu_style'] ?? 'h_glide' ) );
if ( ! isset( $menu_style_options[ $menu_visual_style ] ) ) {
    $menu_visual_style = 'h_glide';
}

$current_heading_weight = (string) ( $typography['heading_weight'] ?? $typography_defaults['heading_weight'] );
if ( ! isset( $heading_weight_options[ $current_heading_weight ] ) ) {
    $current_heading_weight = '600';
}
$current_line_height = (float) ( $typography['line_height'] ?? $typography_defaults['line_height'] );
$line_height_selection = '1.60';
$closest_delta = PHP_FLOAT_MAX;
foreach ( array_keys( $line_height_options ) as $line_height_value ) {
    $delta = abs( (float) $line_height_value - $current_line_height );
    if ( $delta < $closest_delta ) {
        $closest_delta = $delta;
        $line_height_selection = (string) $line_height_value;
    }
}

$saved_bindings = isset( $global_styles['global_settings']['branding_color_bindings'] ) && is_array( $global_styles['global_settings']['branding_color_bindings'] )
    ? $global_styles['global_settings']['branding_color_bindings']
    : [];
$valid_theme_keys = array_fill_keys( array_keys( $color_defaults ), true );
$valid_branding_keys = array_fill_keys( array_keys( $branding_palette ), true );
$normalized_bindings = [];
foreach ( $saved_bindings as $theme_key => $branding_key ) {
    $theme_key = (string) $theme_key;
    $branding_key = (string) $branding_key;
    if ( isset( $valid_theme_keys[ $theme_key ] ) && isset( $valid_branding_keys[ $branding_key ] ) ) {
        $normalized_bindings[ $theme_key ] = $branding_key;
    }
}
$global_styles['global_settings']['branding_color_bindings'] = array_merge( $branding_binding_defaults, $normalized_bindings );
foreach ( $global_styles['global_settings']['branding_color_bindings'] as $theme_key => $branding_key ) {
    if ( isset( $colors[ $theme_key ] ) && isset( $branding_palette[ $branding_key ] ) ) {
        $colors[ $theme_key ] = $branding_palette[ $branding_key ];
    }
}

$font_options = [
    'inherit' => 'Theme Default',
];
$local_font_dirs = [
    METIS_ASSETS_PATH . 'fonts',
    METIS_MODULES_PATH . 'website/assets/fonts',
];
$local_font_seen = [];
$local_family_seen = [];
$local_style_tokens = [ 'thin', 'extralight', 'ultralight', 'light', 'regular', 'normal', 'book', 'medium', 'semibold', 'demibold', 'bold', 'extrabold', 'ultrabold', 'black', 'heavy', 'italic', 'oblique', 'variablefont', 'opsz', 'wght', 'wdth', 'slnt', 'ital' ];
foreach ( $local_font_dirs as $local_font_dir ) {
    if ( ! is_dir( $local_font_dir ) ) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $local_font_dir, FilesystemIterator::SKIP_DOTS )
    );
    foreach ( $iterator as $file_info ) {
        if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
            continue;
        }
        $entry = (string) $file_info->getFilename();
        if ( preg_match( '/\.(woff2|woff|ttf|otf)$/i', $entry ) !== 1 ) {
            continue;
        }
        $full_path = (string) $file_info->getPathname();
        $relative = ltrim( str_replace( '\\', '/', substr( $full_path, strlen( $local_font_dir ) ) ), '/' );
        $entry_key = strtolower( $relative );
        if ( isset( $local_font_seen[ $entry_key ] ) ) {
            continue;
        }
        $local_font_seen[ $entry_key ] = true;

        $base = (string) pathinfo( $entry, PATHINFO_FILENAME );
        $name = trim( preg_replace( '/\s+/', ' ', str_replace( [ '_', '-', ',' ], ' ', $base ) ) ?? $base );
        $tokens = preg_split( '/\s+/', strtolower( $name ) ) ?: [];
        while ( $tokens !== [] ) {
            $last = (string) end( $tokens );
            if ( in_array( $last, $local_style_tokens, true ) || preg_match( '/^[1-9]00$/', $last ) === 1 ) {
                array_pop( $tokens );
                continue;
            }
            break;
        }
        $family = trim( implode( ' ', $tokens ) );
        if ( $family === '' ) {
            $family = $name;
        }
        if ( preg_match( '/^(thin|extralight|ultralight|light|regular|normal|book|medium|semibold|demibold|bold|extrabold|ultrabold|black|heavy|italic|oblique|[1-9]00)$/i', $family ) === 1 ) {
            $dir_hint = basename( str_replace( '\\', '/', (string) $file_info->getPath() ) );
            $dir_hint = trim( preg_replace( '/\s+/', ' ', str_replace( [ '_', '-' ], ' ', $dir_hint ) ) ?? $dir_hint );
            if ( $dir_hint !== '' && strtolower( $dir_hint ) !== 'fonts' ) {
                $family = $dir_hint;
            }
        }
        $family = trim( preg_replace( '/\s+/', ' ', ucwords( $family ) ) ?? $family );
        if ( $family === '' ) {
            continue;
        }
        $family_key = strtolower( $family );
        if ( isset( $local_family_seen[ $family_key ] ) ) {
            continue;
        }
        $local_family_seen[ $family_key ] = true;
        $value = $family . ', system-ui, -apple-system, Segoe UI, sans-serif';
        if ( ! array_key_exists( $value, $font_options ) ) {
            $font_options[ $value ] = $family;
        }
    }
}
if ( count( $font_options ) > 1 ) {
    $theme_default_label = (string) ( $font_options['inherit'] ?? 'Theme Default' );
    unset( $font_options['inherit'] );
    asort( $font_options, SORT_NATURAL | SORT_FLAG_CASE );
    $font_options = [ 'inherit' => $theme_default_label ] + $font_options;
}
foreach ( [ (string) ( $typography['body_font'] ?? '' ), (string) ( $typography['heading_font'] ?? '' ) ] as $selected_stack ) {
    $selected_stack = trim( $selected_stack );
    if ( $selected_stack === '' || isset( $font_options[ $selected_stack ] ) ) {
        continue;
    }
    $label = trim( preg_replace( '/\s+/', ' ', (string) ( explode( ',', $selected_stack )[0] ?? $selected_stack ) ) );
    $label = trim( $label, "\"'" );
    $label = preg_replace( '/\s*\((custom|local|system)\)\s*/i', '', (string) $label ) ?? $label;
    $label = preg_replace( '/\b(variablefont|opsz|wght|wdth|slnt|ital)\b/i', '', (string) $label ) ?? $label;
    $label = trim( preg_replace( '/\s+/', ' ', (string) $label ) ?? (string) $label );
    $label = trim( (string) $label );
    if ( $label === '' ) {
        $label = $selected_stack;
    }
    $font_options[ $selected_stack ] = $label;
}
$json_encode = static function ( $value ): string {
    $json = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    return $json === false ? '{}' : $json;
};
$weight_option_rows = [];
foreach ( $heading_weight_options as $value => $label ) {
    $weight_option_rows[] = [
        'value' => (string) $value,
        'label' => (string) $label,
    ];
}
$line_height_option_rows = [];
foreach ( $line_height_options as $value => $label ) {
    $line_height_option_rows[] = [
        'value' => (string) $value,
        'label' => (string) $label,
    ];
}

?>
<div class="metis-page-header">
    <div class="metis-page-header-left">
        <h1 class="metis-page-title">Theme</h1>
        <p class="metis-subtitle"><?php echo $theme ? 'Active theme loaded.' : 'Create and activate a design system theme.'; ?></p>
    </div>
    <div class="metis-page-header-right">
        <button class="metis-btn metis-btn-ghost" id="metis-theme-reset-btn">Reset</button>
    </div>
</div>

<input type="hidden" id="metis-theme-id" value="<?php echo metis_escape_attr( $theme ? ( $theme['id'] ?? '' ) : '' ); ?>">

<div class="metis-theme-shell">
    <aside class="metis-theme-nav" aria-label="Theme sections">
        <button class="metis-theme-nav-btn is-active" data-section-target="colors">Colors</button>
        <button class="metis-theme-nav-btn" data-section-target="typography">Typography</button>
        <button class="metis-theme-nav-btn" data-section-target="elements">Elements</button>
        <button class="metis-theme-nav-btn" data-section-target="layout">Layout</button>
        <button class="metis-theme-nav-btn" data-section-target="components">Components</button>
        <button class="metis-theme-nav-btn" data-section-target="menu">Menu</button>
        <button class="metis-theme-nav-btn" data-section-target="advanced">Advanced</button>
    </aside>

    <main class="metis-theme-main">
        <section class="metis-theme-card is-active" data-section="colors">
            <div class="metis-theme-card-head">
                <div class="metis-theme-card-title">Colors</div>
                <div class="metis-theme-file-help">Pick a branding token for each theme color, or set a custom fixed value.</div>
            </div>
            <div class="metis-theme-grid metis-theme-grid-wide" id="metis-theme-color-grid"></div>
        </section>

        <section class="metis-theme-card" data-section="typography">
            <div class="metis-theme-card-title">Typography</div>
            <div class="metis-theme-grid metis-theme-grid-wide">
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Body font</label>
                    <select id="metis-theme-body-font" class="metis-input">
                        <?php foreach ( $font_options as $value => $label ) : ?>
                            <option value="<?php echo metis_escape_attr( $value ); ?>" style="font-family:<?php echo metis_escape_attr( $value === 'inherit' ? 'inherit' : (string) $value ); ?>;"<?php echo ( (string) $typography['body_font'] === (string) $value ) ? ' selected' : ''; ?>><?php echo metis_escape_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Heading font</label>
                    <select id="metis-theme-heading-font" class="metis-input">
                        <?php foreach ( $font_options as $value => $label ) : ?>
                            <option value="<?php echo metis_escape_attr( $value ); ?>" style="font-family:<?php echo metis_escape_attr( $value === 'inherit' ? 'inherit' : (string) $value ); ?>;"<?php echo ( (string) $typography['heading_font'] === (string) $value ) ? ' selected' : ''; ?>><?php echo metis_escape_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Body size (px)</label>
                    <input type="number" id="metis-theme-base-size" class="metis-input" min="12" max="24" value="<?php echo metis_escape_attr( (string) $typography['base_size'] ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Line height</label>
                    <select id="metis-theme-line-height" class="metis-input">
                        <?php foreach ( $line_height_options as $line_height_value => $line_height_label ) : ?>
                            <option value="<?php echo metis_escape_attr( (string) $line_height_value ); ?>"<?php echo $line_height_selection === (string) $line_height_value ? ' selected' : ''; ?>>
                                <?php echo metis_escape_html( $line_height_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Heading weight</label>
                    <select id="metis-theme-heading-weight" class="metis-input">
                        <?php foreach ( $heading_weight_options as $weight_value => $weight_label ) : ?>
                            <option value="<?php echo metis_escape_attr( (string) $weight_value ); ?>"<?php echo $current_heading_weight === (string) $weight_value ? ' selected' : ''; ?>>
                                <?php echo metis_escape_html( $weight_label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="metis-theme-field metis-theme-span-2">
                    <label class="metis-theme-label">Custom font upload (.woff/.woff2)</label>
                    <div class="metis-theme-inline-grid">
                        <input type="text" id="metis-theme-custom-font-name" class="metis-input" placeholder="Font family name">
                        <input type="file" id="metis-theme-custom-font-file" class="metis-input" accept=".woff,.woff2,font/woff,font/woff2">
                    </div>
                    <div class="metis-theme-file-help">Uploaded fonts are stored in theme config and available instantly in preview.</div>
                    <div class="metis-theme-chip-list" id="metis-theme-custom-font-list"></div>
                </div>
            </div>
        </section>

        <section class="metis-theme-card" data-section="elements">
            <div class="metis-theme-card-title">Element Styles</div>
            <div id="metis-theme-element-jump" class="metis-theme-element-jump"></div>
            <div id="metis-theme-element-grid" class="metis-theme-element-grid"></div>
        </section>

        <section class="metis-theme-card" data-section="layout">
            <div class="metis-theme-card-title">Layout</div>
            <div class="metis-theme-grid">
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Max width (px)</label>
                    <input type="number" id="metis-theme-max-width" class="metis-input" min="720" max="1920" value="<?php echo metis_escape_attr( (string) ( $global_styles['layout']['max_width'] ?? 1200 ) ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Container width (px)</label>
                    <input type="number" id="metis-theme-container-width" class="metis-input" min="640" max="1600" value="<?php echo metis_escape_attr( (string) ( $global_styles['layout']['container_width'] ?? 860 ) ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Spacing preset</label>
                    <select id="metis-theme-spacing-preset" class="metis-input">
                        <?php foreach ( [ 'compact', 'balanced', 'airy' ] as $preset ) : ?>
                            <option value="<?php echo metis_escape_attr( $preset ); ?>"<?php echo ( (string) ( $global_styles['layout']['spacing_preset'] ?? 'balanced' ) === (string) $preset ) ? ' selected' : ''; ?>><?php echo metis_escape_html( ucfirst( $preset ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Breakpoint SM (px)</label>
                    <input type="number" id="metis-theme-bp-sm" class="metis-input" min="360" max="2400" value="<?php echo metis_escape_attr( (string) ( $global_styles['layout_tokens']['breakpoints']['sm'] ?? 640 ) ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Breakpoint MD (px)</label>
                    <input type="number" id="metis-theme-bp-md" class="metis-input" min="360" max="2400" value="<?php echo metis_escape_attr( (string) ( $global_styles['layout_tokens']['breakpoints']['md'] ?? 768 ) ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Breakpoint LG (px)</label>
                    <input type="number" id="metis-theme-bp-lg" class="metis-input" min="360" max="2400" value="<?php echo metis_escape_attr( (string) ( $global_styles['layout_tokens']['breakpoints']['lg'] ?? 1024 ) ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Breakpoint XL (px)</label>
                    <input type="number" id="metis-theme-bp-xl" class="metis-input" min="360" max="2400" value="<?php echo metis_escape_attr( (string) ( $global_styles['layout_tokens']['breakpoints']['xl'] ?? 1280 ) ); ?>">
                </div>
                <?php foreach ( $spacing_defaults as $key => $default ) : ?>
                    <div class="metis-theme-field">
                        <label class="metis-theme-label">Spacing <?php echo metis_escape_html( strtoupper( $key ) ); ?></label>
                        <input type="text" class="metis-input metis-theme-spacing" data-key="<?php echo metis_escape_attr( $key ); ?>" value="<?php echo metis_escape_attr( (string) ( $spacing[ $key ] ?? $default ) ); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="metis-theme-card" data-section="components">
            <div class="metis-theme-card-title">Components</div>
            <div class="metis-theme-grid metis-theme-grid-wide">
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Button radius</label>
                    <input type="number" id="metis-theme-btn-radius" class="metis-input" min="0" max="40" value="<?php echo metis_escape_attr( (string) ( $global_styles['components']['buttons']['radius'] ?? 8 ) ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Button padding</label>
                    <input type="hidden" id="metis-theme-btn-padding-box" class="metis-theme-element-input metis-theme-component-box-input" value="">
                    <div class="metis-theme-box4 metis-theme-box4--tight" data-component="buttons_padding">
                        <button type="button" class="metis-theme-box4-link" data-linked="1" title="Link all sides" aria-label="Toggle linked sides"><span class="metis-theme-box4-link-icon" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M11.8 8.2a3 3 0 0 1 4.2 4.2l-2.1 2.1a3 3 0 0 1-4.2 0l-.7-.7 1.4-1.4.7.7a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 1 0-1.4-1.4l-.9.9-1.4-1.4.9-.9ZM8.2 11.8a3 3 0 0 1-4.2-4.2l2.1-2.1a3 3 0 0 1 4.2 0l.7.7-1.4 1.4-.7-.7a1 1 0 0 0-1.4 0L5.4 9.1a1 1 0 1 0 1.4 1.4l.9-.9 1.4 1.4-.9.9Zm.6-2.8 2.2-2.2 1.4 1.4-2.2 2.2-1.4-1.4Z"/></svg></span><span class="metis-theme-box4-link-text">Linked</span></button>
                        <div class="metis-theme-box4-grid">
                            <input type="text" class="metis-input metis-theme-box4-input" data-side="top" placeholder="T">
                            <input type="text" class="metis-input metis-theme-box4-input" data-side="right" placeholder="R">
                            <input type="text" class="metis-input metis-theme-box4-input" data-side="bottom" placeholder="B">
                            <input type="text" class="metis-input metis-theme-box4-input" data-side="left" placeholder="L">
                        </div>
                    </div>
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Card padding</label>
                    <input type="hidden" id="metis-theme-card-padding-box" class="metis-theme-element-input metis-theme-component-box-input" value="">
                    <div class="metis-theme-box4 metis-theme-box4--tight" data-component="cards_padding">
                        <button type="button" class="metis-theme-box4-link" data-linked="1" title="Link all sides" aria-label="Toggle linked sides"><span class="metis-theme-box4-link-icon" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M11.8 8.2a3 3 0 0 1 4.2 4.2l-2.1 2.1a3 3 0 0 1-4.2 0l-.7-.7 1.4-1.4.7.7a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 1 0-1.4-1.4l-.9.9-1.4-1.4.9-.9ZM8.2 11.8a3 3 0 0 1-4.2-4.2l2.1-2.1a3 3 0 0 1 4.2 0l.7.7-1.4 1.4-.7-.7a1 1 0 0 0-1.4 0L5.4 9.1a1 1 0 1 0 1.4 1.4l.9-.9 1.4 1.4-.9.9Zm.6-2.8 2.2-2.2 1.4 1.4-2.2 2.2-1.4-1.4Z"/></svg></span><span class="metis-theme-box4-link-text">Linked</span></button>
                        <div class="metis-theme-box4-grid">
                            <input type="text" class="metis-input metis-theme-box4-input" data-side="top" placeholder="T">
                            <input type="text" class="metis-input metis-theme-box4-input" data-side="right" placeholder="R">
                            <input type="text" class="metis-input metis-theme-box4-input" data-side="bottom" placeholder="B">
                            <input type="text" class="metis-input metis-theme-box4-input" data-side="left" placeholder="L">
                        </div>
                    </div>
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Card radius</label>
                    <input type="number" id="metis-theme-card-radius" class="metis-input" min="0" max="40" value="<?php echo metis_escape_attr( (string) ( $global_styles['components']['cards']['radius'] ?? 12 ) ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Form radius</label>
                    <input type="number" id="metis-theme-form-radius" class="metis-input" min="0" max="24" value="<?php echo metis_escape_attr( (string) ( $global_styles['components']['forms']['radius'] ?? 8 ) ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Form border width</label>
                    <input type="number" id="metis-theme-form-border" class="metis-input" min="0" max="6" value="<?php echo metis_escape_attr( (string) ( $global_styles['components']['forms']['border'] ?? 1 ) ); ?>">
                </div>
                <div class="metis-theme-field">
                    <label class="metis-theme-label">Link underline</label>
                    <select id="metis-theme-link-underline" class="metis-input">
                        <option value="1"<?php echo ( (string) ( $global_styles['components']['links']['underline'] ?? '1' ) === '1' ) ? ' selected' : ''; ?>>Always</option>
                        <option value="0"<?php echo ( (string) ( $global_styles['components']['links']['underline'] ?? '1' ) === '0' ) ? ' selected' : ''; ?>>On hover</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="metis-theme-card" data-section="menu">
            <div class="metis-theme-card-title">Menu</div>
            <div class="metis-theme-grid metis-theme-grid-wide">
                <div class="metis-theme-span-2 metis-theme-menu-group-wrap">
                    <div class="metis-theme-menu-group">
                        <div class="metis-theme-menu-group-title">Preset</div>
                        <div class="metis-theme-grid">
                            <div class="metis-theme-field metis-theme-span-2">
                                <label class="metis-theme-label">Menu style</label>
                                <select id="metis-theme-menu-style" class="metis-input">
                                    <?php foreach ( $menu_style_options as $style_value => $style_label ) : ?>
                                        <option value="<?php echo metis_escape_attr( $style_value ); ?>"<?php echo ( $menu_visual_style === (string) $style_value ) ? ' selected' : ''; ?>><?php echo metis_escape_html( $style_label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="metis-theme-field metis-theme-span-2 metis-theme-menu-preview-row">
                    <label class="metis-theme-label">Live preview</label>
                    <?php echo \Metis\Modules\Website\Services\WebsiteRenderer::renderThemeMenuPreviewCss(); ?>
                    <div class="metis-theme-menu-live" id="metis-theme-menu-live">
                        <?php echo \Metis\Modules\Website\Services\WebsiteRenderer::renderThemeMenuPreviewHtml(); ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="metis-theme-card" data-section="advanced">
            <div class="metis-theme-card-title">Advanced</div>
            <div class="metis-theme-grid metis-theme-grid-wide">
                <div class="metis-theme-field metis-theme-span-2">
                    <label class="metis-theme-label">Default page title format</label>
                    <input type="text" id="metis-theme-title-format" class="metis-input" value="<?php echo metis_escape_attr( (string) ( $global_styles['global_settings']['title_format'] ?? '{page} | {site}' ) ); ?>" placeholder="{page} | {site}">
                </div>
                <div class="metis-theme-field metis-theme-span-2">
                    <label class="metis-theme-label">Theme portability</label>
                    <div class="metis-theme-preset-row">
                        <button type="button" class="metis-btn metis-btn-ghost" id="metis-theme-export-btn">Export Theme</button>
                        <button type="button" class="metis-btn metis-btn-ghost" id="metis-theme-import-btn">Import Theme</button>
                        <input type="file" id="metis-theme-import-file" class="metis-is-hidden" accept=".json,application/json">
                    </div>
                </div>
                <div class="metis-theme-field metis-theme-span-2">
                    <label class="metis-theme-label">Version history (recent local snapshots)</label>
                    <div class="metis-theme-inline-grid">
                        <select id="metis-theme-version-select" class="metis-input">
                            <option value="">Select saved snapshot</option>
                        </select>
                        <button type="button" class="metis-btn metis-btn-ghost" id="metis-theme-version-apply-btn">Apply Snapshot</button>
                    </div>
                </div>
                <div class="metis-theme-field metis-theme-span-2">
                    <label class="metis-theme-label">Accessibility checks</label>
                    <div id="metis-theme-a11y-results" class="metis-theme-a11y"></div>
                </div>
                <div class="metis-theme-field metis-theme-span-2">
                    <label class="metis-theme-label">Custom CSS</label>
                    <textarea id="metis-theme-custom-css" class="metis-input" rows="8" placeholder=".metis-block-hero { border-radius: 18px; }"><?php echo metis_escape_html( (string) ( $global_styles['advanced']['custom_css'] ?? '' ) ); ?></textarea>
                </div>
                <div class="metis-theme-field metis-theme-span-2">
                    <label class="metis-theme-label">Token editor (one per line: token-name: value)</label>
                    <textarea id="metis-theme-token-editor" class="metis-input" rows="8" placeholder="radius-lg: 20px&#10;shadow-soft: 0 8px 24px rgba(0,0,0,.08)"><?php
                        $token_lines = [];
                        if ( ! empty( $custom['tokens'] ) && is_array( $custom['tokens'] ) ) {
                            foreach ( $custom['tokens'] as $k => $v ) {
                                if ( is_scalar( $v ) ) {
                                    $token_lines[] = (string) $k . ': ' . (string) $v;
                                }
                            }
                        }
                        echo metis_escape_html( implode( "\n", $token_lines ) );
                    ?></textarea>
                </div>
            </div>
        </section>
    </main>
</div>
<div class="metis-theme-floating-actions">
    <button class="metis-btn metis-btn-primary" id="metis-theme-save-btn">Save &amp; Activate</button>
</div>


<script>
(function bootThemeEditor(){
'use strict';

if (!window.jQuery) {
    window.setTimeout(bootThemeEditor, 50);
    return;
}

var $ = window.jQuery;

function themeAjaxConfig(action) {
    var website = window.metisWebsiteAjax || {};
    var core = window.metisAjax || {};
    var websiteNonces = (website.action_nonces && typeof website.action_nonces === 'object') ? website.action_nonces : {};
    var coreNonces = (core.action_nonces && typeof core.action_nonces === 'object') ? core.action_nonces : {};
    var nonce = websiteNonces[action] || coreNonces[action] || website.nonce || core.nonce || '';
    var ajaxUrl = website.ajax_url || core.ajax_url || '/api/ajax';
    return { ajax_url: ajaxUrl, nonce: nonce };
}

function themeToast(message, level) {
    if (typeof window.metis_toast === 'function') {
        window.metis_toast(message, level || 'info');
        return;
    }
    if (window.console && typeof window.console.log === 'function') {
        window.console.log('[Theme] ' + String(message || ''));
    }
}

function themeConfirm(message, onConfirm) {
    if (typeof window.metis_confirm === 'function') {
        window.metis_confirm(message, onConfirm);
        return;
    }
    if (window.Metis && Metis.confirm && typeof Metis.confirm.open === 'function') {
        Metis.confirm.open({ message: String(message || 'Confirm?') }).then(function(confirmed) {
            if (confirmed && typeof onConfirm === 'function') {
                onConfirm();
            }
        });
    }
}

var defaults = {
    colors: <?php echo $json_encode( $color_defaults ); ?>,
    typography: <?php echo $json_encode( $typography_defaults ); ?>,
    spacing: <?php echo $json_encode( $spacing_defaults ); ?>,
    global_styles: <?php echo $json_encode( $global_defaults ); ?>,
    custom_tokens: <?php echo $json_encode( $custom_defaults ); ?>
};

var brandingPalette = <?php echo $json_encode( $branding_palette ); ?>;
var brandingLabels = <?php echo $json_encode( $branding_field_labels ); ?>;

var state = {
    colors: <?php echo $json_encode( $colors ); ?>,
    typography: <?php echo $json_encode( $typography ); ?>,
    spacing: <?php echo $json_encode( $spacing ); ?>,
    global_styles: <?php echo $json_encode( $global_styles ); ?>,
    custom_tokens: <?php echo $json_encode( $custom ); ?>
};
applyBrandingColorBindings(defaults);
applyBrandingColorBindings(state);

var themeDirtyPaths = {};
var themeForceFullSave = false;

function deepClone(v) {
    return JSON.parse(JSON.stringify(v || {}));
}

function markThemeDirtyPath(path) {
    var key = String(path || '').trim();
    if (!key) return;
    themeDirtyPaths[key] = true;
}

function markThemeDirtyPaths(paths) {
    (paths || []).forEach(markThemeDirtyPath);
}

function markThemeDirtySection(section) {
    var target = String(section || '').trim();
    if (target === 'colors') {
        markThemeDirtyPaths([
            'colors',
            'global_styles.global_settings.branding_color_bindings',
            'global_styles.global_settings.footer_background_binding',
            'global_styles.components.footer'
        ]);
        return;
    }
    if (target === 'typography') {
        markThemeDirtyPath('typography');
        return;
    }
    if (target === 'layout') {
        markThemeDirtyPaths([
            'spacing',
            'global_styles.layout',
            'global_styles.layout_tokens',
            'global_styles.global_settings.title_format',
            'global_styles.global_settings.site_layout_profile',
            'global_styles.global_settings.newsletter_layout_profile'
        ]);
        return;
    }
    if (target === 'components') {
        markThemeDirtyPaths([
            'global_styles.components.buttons',
            'global_styles.components.cards',
            'global_styles.components.forms',
            'global_styles.components.links'
        ]);
        return;
    }
    if (target === 'menu') {
        markThemeDirtyPaths([
            'global_styles.components.menu',
            'global_styles.components.menu_config',
            'global_styles.global_settings.menu_style'
        ]);
        return;
    }
    if (target === 'elements') {
        markThemeDirtyPath('global_styles.elements');
        return;
    }
    if (target === 'advanced') {
        markThemeDirtyPaths([
            'global_styles.advanced',
            'custom_tokens'
        ]);
    }
}

function markThemeDirtyFromInput(input) {
    var $input = $(input);
    if (!$input.length) return;
    var id = String(input && input.id ? input.id : '');
    var section = String($input.closest('.metis-theme-card').data('section') || '');
    var colorKey = String($input.data('key') || '');
    if ($input.hasClass('metis-theme-color') || $input.hasClass('metis-theme-color-binding')) {
        if (colorKey === 'footer_background') {
            markThemeDirtyPaths([
                'global_styles.global_settings.footer_background_binding',
                'global_styles.components.footer'
            ]);
        } else {
            markThemeDirtyPaths([
                'colors',
                'global_styles.global_settings.branding_color_bindings'
            ]);
        }
        return;
    }
    if (id === 'metis-theme-menu-style') {
        markThemeDirtySection('menu');
        return;
    }
    if ($input.hasClass('metis-theme-element-input') && section === 'components') {
        markThemeDirtySection('components');
        return;
    }
    if ($input.hasClass('metis-theme-element-input') || $input.hasClass('metis-theme-box4-input') || $input.hasClass('metis-theme-border-width') || $input.hasClass('metis-theme-border-style') || $input.hasClass('metis-theme-border-color') || $input.hasClass('metis-theme-textdec-type') || $input.hasClass('metis-theme-textdec-color') || $input.hasClass('metis-theme-color-token') || $input.hasClass('metis-theme-fontsize-range')) {
        markThemeDirtyPath('global_styles.elements');
        return;
    }
    markThemeDirtySection(section);
}

function clearThemeSaveDirtyState() {
    themeDirtyPaths = {};
    themeForceFullSave = false;
}

function normalizeThemeForEditor(incoming) {
    var src = (incoming && typeof incoming === 'object') ? incoming : {};
    var globalStyles = src.global_styles && typeof src.global_styles === 'object'
        ? src.global_styles
        : {
            global_settings: src.global_settings || {},
            layout: src.layout || {},
            layout_tokens: src.layout_tokens || {},
            elements: src.elements || {},
            components: src.components || {},
            advanced: { custom_css: src.custom_css || '' }
        };
    var customTokens = src.custom_tokens && typeof src.custom_tokens === 'object' ? src.custom_tokens : {};
    if (!customTokens.tokens || typeof customTokens.tokens !== 'object') {
        customTokens = { tokens: customTokens };
    }
    return {
        colors: Object.assign({}, defaults.colors, src.colors || {}),
        typography: Object.assign({}, defaults.typography, src.typography || {}),
        spacing: Object.assign({}, defaults.spacing, src.spacing || {}),
        global_styles: $.extend(true, {}, defaults.global_styles, globalStyles),
        custom_tokens: $.extend(true, {}, defaults.custom_tokens, customTokens)
    };
}

function applyBrandingColorBindings(target) {
    var out = target && typeof target === 'object' ? target : {};
    ensureObjectPath(out, ['global_styles'], {});
    ensureObjectPath(out, ['global_styles', 'global_settings'], {});
    ensureObjectPath(out, ['global_styles', 'global_settings', 'branding_color_bindings'], {});
    if (!out.colors || typeof out.colors !== 'object') {
        out.colors = {};
    }
    Object.keys(out.global_styles.global_settings.branding_color_bindings || {}).forEach(function(key) {
        var brandingKey = String(out.global_styles.global_settings.branding_color_bindings[key] || '');
        if (brandingKey && Object.prototype.hasOwnProperty.call(brandingPalette, brandingKey)) {
            out.colors[key] = brandingPalette[brandingKey];
        }
    });
    return out;
}

var elementOrder = ['body', 'site_header', 'section_header', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'button', 'input', 'select', 'textarea', 'label', 'divider'];
var elementWeightOptions = <?php echo $json_encode( $weight_option_rows ); ?>;
var elementLineHeightOptions = <?php echo $json_encode( $line_height_option_rows ); ?>;
var elementFieldMap = [
    { key: 'font_size', label: 'Font Size' },
    { key: 'font_weight', label: 'Font Weight' },
    { key: 'line_height', label: 'Line Height' },
    { key: 'color', label: 'Color' },
    { key: 'margin', label: 'Margin' },
    { key: 'padding', label: 'Padding' },
    { key: 'border_radius', label: 'Border Radius' },
    { key: 'box_shadow', label: 'Box Shadow' },
    { key: 'background', label: 'Background' },
    { key: 'border', label: 'Border' },
    { key: 'text_decoration', label: 'Text Decoration' },
    { key: 'sticky', label: 'Sticky Header' },
    { key: 'shrink', label: 'Shrink On Scroll' },
    { key: 'compact_padding', label: 'Compact Padding' }
];
var elementFieldVisibility = {
    body: ['font_size', 'font_weight', 'line_height', 'color', 'margin', 'padding', 'background'],
    site_header: ['background', 'border', 'box_shadow', 'padding', 'sticky', 'shrink', 'compact_padding'],
    section_header: ['font_size', 'font_weight', 'line_height', 'color', 'margin', 'padding', 'background', 'border'],
    h1: ['font_size', 'font_weight', 'line_height', 'color', 'margin', 'padding'],
    h2: ['font_size', 'font_weight', 'line_height', 'color', 'margin', 'padding'],
    h3: ['font_size', 'font_weight', 'line_height', 'color', 'margin', 'padding'],
    h4: ['font_size', 'font_weight', 'line_height', 'color', 'margin', 'padding'],
    h5: ['font_size', 'font_weight', 'line_height', 'color', 'margin', 'padding'],
    h6: ['font_size', 'font_weight', 'line_height', 'color', 'margin', 'padding'],
    p: ['font_size', 'font_weight', 'line_height', 'color', 'margin', 'padding'],
    a: ['font_size', 'font_weight', 'line_height', 'color', 'background', 'text_decoration'],
    button: ['font_size', 'font_weight', 'line_height', 'color', 'padding', 'border_radius', 'box_shadow', 'background', 'border'],
    input: ['font_size', 'font_weight', 'line_height', 'color', 'padding', 'border_radius', 'box_shadow', 'background', 'border'],
    select: ['font_size', 'font_weight', 'line_height', 'color', 'padding', 'border_radius', 'box_shadow', 'background', 'border'],
    textarea: ['font_size', 'font_weight', 'line_height', 'color', 'padding', 'border_radius', 'box_shadow', 'background', 'border'],
    label: ['font_size', 'font_weight', 'line_height', 'color', 'margin'],
    divider: ['background', 'border']
};

function elementLabel(key) {
    if (String(key || '').toLowerCase() === 'body') return 'Body';
    if (String(key || '') === 'site_header') return 'Site Header';
    if (String(key || '') === 'section_header') return 'Section Header';
    return String(key || '').toUpperCase();
}

function elementPreviewMarkup(element) {
    if (element === 'a') {
        return '<a href="#" class="metis-theme-element-live-target" data-live-element="a">Preview Link</a>';
    }
    if (element === 'button') {
        return '<button type="button" class="metis-theme-element-live-target" data-live-element="button">Preview Button</button>';
    }
    if (element === 'input') {
        return '<input type="text" class="metis-theme-element-live-target" data-live-element="input" placeholder="Input preview">';
    }
    if (element === 'select') {
        return '<select class="metis-theme-element-live-target" data-live-element="select"><option>Select preview</option><option>Option two</option></select>';
    }
    if (element === 'textarea') {
        return '<textarea class="metis-theme-element-live-target" data-live-element="textarea" rows="2">Textarea preview</textarea>';
    }
    if (element === 'label') {
        return '<label class="metis-theme-element-live-target" data-live-element="label">Label preview</label>';
    }
    if (element === 'divider') {
        return '<hr class="metis-theme-element-live-target metis-theme-element-live-target--divider" data-live-element="divider">';
    }
    if (element === 'site_header') {
        return '<div class="metis-theme-element-live-target metis-theme-element-live-target--site-header" data-live-element="site_header"><div class="metis-theme-element-live-header-brand">Logo</div><div class="metis-theme-element-live-header-menu">Menu</div></div>';
    }
    if (element === 'section_header') {
        return '<div class="metis-theme-element-live-target metis-theme-element-live-target--section-header" data-live-element="section_header"><h1>Section Header</h1></div>';
    }
    if (element === 'body' || element === 'p') {
        return '<p class="metis-theme-element-live-target" data-live-element="' + element + '">This is a live preview for this element style.</p>';
    }
    return '<' + element + ' class="metis-theme-element-live-target" data-live-element="' + element + '">Live ' + element.toUpperCase() + ' preview</' + element + '>';
}

function fontSizeRangeBounds(element) {
    if (String(element || '') === 'section_header') return { min: 20, max: 72, step: 1 };
    if (/^h[1-6]$/.test(String(element || ''))) return { min: 14, max: 64, step: 1 };
    return { min: 10, max: 32, step: 1 };
}

function cssFontSizeToPx(raw) {
    var value = String(raw || '').trim().toLowerCase();
    if (!value) return 16;
    var num = parseFloat(value);
    if (!isFinite(num)) return 16;
    if (value.indexOf('rem') !== -1 || value.indexOf('em') !== -1) return Math.round(num * 16);
    return Math.round(num);
}

function pxToCssFontSize(px) {
    var n = Math.max(1, Math.round(parseFloat(px) || 16));
    return String(n) + 'px';
}

function buildElementGrid() {
    var $grid = $('#metis-theme-element-grid');
    if (!$grid.length) return;
    var html = [];
    var jumpHtml = [];
    elementOrder.forEach(function(el) {
        jumpHtml.push('<button type="button" class="metis-theme-element-jump-btn" data-scroll-element="' + el + '">' + elementLabel(el) + '</button>');
        html.push('<article class="metis-theme-element-card" id="metis-theme-element-card-' + el + '" data-element="' + el + '">');
        html.push('<div class="metis-theme-element-main">');
        html.push('<h4 class="metis-theme-element-title">' + elementLabel(el) + '</h4>');
        html.push('<div class="metis-theme-element-fields">');
        var visibleFields = elementFieldVisibility[el] || [];
        elementFieldMap.forEach(function(field) {
            if (visibleFields.indexOf(field.key) === -1) return;
            var id = 'metis-theme-element-' + el + '-' + field.key;
            html.push('<div class="metis-theme-field">');
            html.push('<label class="metis-theme-label" for="' + id + '">' + field.label + '</label>');
            if (field.key === 'font_size') {
                var bounds = fontSizeRangeBounds(el);
                html.push('<input type="hidden" class="metis-theme-element-input" id="' + id + '" data-element="' + el + '" data-prop="' + field.key + '">');
                html.push('<div class="metis-theme-fontsize" data-element="' + el + '">');
                html.push('<input type="range" class="metis-theme-fontsize-range" data-element="' + el + '" min="' + bounds.min + '" max="' + bounds.max + '" step="' + bounds.step + '">');
                html.push('<div class="metis-theme-fontsize-value" data-element="' + el + '">16px</div>');
                html.push('</div>');
            } else if (field.key === 'font_weight') {
                html.push('<select class="metis-input metis-theme-element-input" id="' + id + '" data-element="' + el + '" data-prop="' + field.key + '">');
                elementWeightOptions.forEach(function(opt) {
                    html.push('<option value="' + opt.value + '">' + opt.label + '</option>');
                });
                html.push('</select>');
            } else if (field.key === 'line_height') {
                html.push('<select class="metis-input metis-theme-element-input" id="' + id + '" data-element="' + el + '" data-prop="' + field.key + '">');
                elementLineHeightOptions.forEach(function(opt) {
                    html.push('<option value="' + opt.value + '">' + opt.label + '</option>');
                });
                html.push('</select>');
            } else if (field.key === 'sticky' || field.key === 'shrink') {
                html.push('<select class="metis-input metis-theme-element-input" id="' + id + '" data-element="' + el + '" data-prop="' + field.key + '">');
                html.push('<option value="1">Yes</option>');
                html.push('<option value="0">No</option>');
                html.push('</select>');
            } else if (field.key === 'color' || field.key === 'background') {
                html.push('<input type="hidden" class="metis-theme-element-input" id="' + id + '" data-element="' + el + '" data-prop="' + field.key + '">');
                html.push('<select class="metis-input metis-theme-color-token" data-element="' + el + '" data-prop="' + field.key + '">');
                html.push('<option value="transparent">Transparent</option>');
                html.push('<option value="var(--metis-color-text,#1a1f2b)">Text</option>');
                html.push('<option value="var(--metis-color-primary,#485bc7)">Primary</option>');
                html.push('<option value="var(--metis-color-accent,#ff7542)">Accent</option>');
                html.push('<option value="var(--metis-color-link,#2b59ff)">Link</option>');
                html.push('<option value="var(--metis-color-muted,#64748b)">Muted</option>');
                html.push('<option value="var(--metis-color-surface,#ffffff)">Surface</option>');
                html.push('<option value="var(--metis-color-bg,#ffffff)">Background</option>');
                html.push('<option value="var(--metis-color-border,#d8deea)">Border</option>');
                html.push('<option value="#000000">Black</option>');
                html.push('<option value="#ffffff">White</option>');
                html.push('</select>');
            } else if (field.key === 'margin' || field.key === 'padding') {
                html.push('<input type="hidden" class="metis-theme-element-input" id="' + id + '" data-element="' + el + '" data-prop="' + field.key + '">');
                html.push('<div class="metis-theme-box4" data-element="' + el + '" data-prop="' + field.key + '">');
                html.push('<button type="button" class="metis-theme-box4-link" data-linked="1" title="Link all sides" aria-label="Toggle linked sides"><span class="metis-theme-box4-link-icon" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M11.8 8.2a3 3 0 0 1 4.2 4.2l-2.1 2.1a3 3 0 0 1-4.2 0l-.7-.7 1.4-1.4.7.7a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 1 0-1.4-1.4l-.9.9-1.4-1.4.9-.9ZM8.2 11.8a3 3 0 0 1-4.2-4.2l2.1-2.1a3 3 0 0 1 4.2 0l.7.7-1.4 1.4-.7-.7a1 1 0 0 0-1.4 0L5.4 9.1a1 1 0 1 0 1.4 1.4l.9-.9 1.4 1.4-.9.9Zm.6-2.8 2.2-2.2 1.4 1.4-2.2 2.2-1.4-1.4Z"/></svg></span><span class="metis-theme-box4-link-text">Linked</span></button>');
                html.push('<div class="metis-theme-box4-grid">');
                html.push('<input type="text" class="metis-input metis-theme-box4-input" data-side="top" placeholder="T">');
                html.push('<input type="text" class="metis-input metis-theme-box4-input" data-side="right" placeholder="R">');
                html.push('<input type="text" class="metis-input metis-theme-box4-input" data-side="bottom" placeholder="B">');
                html.push('<input type="text" class="metis-input metis-theme-box4-input" data-side="left" placeholder="L">');
                html.push('</div>');
                html.push('</div>');
            } else if (field.key === 'border') {
                html.push('<input type="hidden" class="metis-theme-element-input" id="' + id + '" data-element="' + el + '" data-prop="' + field.key + '">');
                html.push('<div class="metis-theme-inline-grid">');
                html.push('<select class="metis-input metis-theme-border-width" data-element="' + el + '"><option value="0">None</option><option value="1">Hairline</option><option value="2">Thin</option><option value="3">Medium</option><option value="4">Thick</option></select>');
                html.push('<select class="metis-input metis-theme-border-style" data-element="' + el + '"><option value="solid">Solid</option><option value="dashed">Dashed</option><option value="dotted">Dotted</option><option value="double">Double</option><option value="none">None</option></select>');
                html.push('<select class="metis-input metis-theme-border-color" data-element="' + el + '"><option value="var(--metis-color-border,#d8deea)">Border</option><option value="var(--metis-color-text,#1a1f2b)">Text</option><option value="var(--metis-color-primary,#485bc7)">Primary</option><option value="var(--metis-color-accent,#ff7542)">Accent</option><option value="var(--metis-color-link,#2b59ff)">Link</option><option value="#000000">Black</option></select>');
                html.push('</div>');
            } else if (field.key === 'text_decoration') {
                html.push('<input type="hidden" class="metis-theme-element-input" id="' + id + '" data-element="' + el + '" data-prop="' + field.key + '">');
                html.push('<div class="metis-theme-inline-grid">');
                html.push('<select class="metis-input metis-theme-textdec-type" data-element="' + el + '"><option value="none">None</option><option value="underline">Underline</option><option value="line-through">Strikethrough</option><option value="overline">Overline</option></select>');
                html.push('<select class="metis-input metis-theme-textdec-color" data-element="' + el + '"><option value="currentColor">Match text</option><option value="var(--metis-color-link,#2b59ff)">Link</option><option value="var(--metis-color-primary,#485bc7)">Primary</option><option value="var(--metis-color-accent,#ff7542)">Accent</option><option value="var(--metis-color-text,#1a1f2b)">Text</option></select>');
                html.push('</div>');
            } else {
                html.push('<input type="text" class="metis-input metis-theme-element-input" id="' + id + '" data-element="' + el + '" data-prop="' + field.key + '">');
            }
            html.push('</div>');
        });
        html.push('</div>');
        html.push('</div>');
        html.push('<div class="metis-theme-element-live"><div class="metis-theme-element-live-title">Live Render</div>' + elementPreviewMarkup(el) + '</div>');
        html.push('</article>');
    });
    $grid.html(html.join(''));
    $('#metis-theme-element-jump').html(jumpHtml.join(''));
}

function parseBoxValue(raw) {
    var value = String(raw || '').trim();
    if (!value) return { top: '', right: '', bottom: '', left: '', linked: true };
    var p = value.split(/\s+/).filter(Boolean);
    if (p.length === 1) return { top: p[0], right: p[0], bottom: p[0], left: p[0], linked: true };
    if (p.length === 2) return { top: p[0], right: p[1], bottom: p[0], left: p[1], linked: false };
    if (p.length === 3) return { top: p[0], right: p[1], bottom: p[2], left: p[1], linked: false };
    return { top: p[0], right: p[1], bottom: p[2], left: p[3], linked: false };
}

function boxToString(top, right, bottom, left) {
    top = String(top || '').trim();
    right = String(right || '').trim();
    bottom = String(bottom || '').trim();
    left = String(left || '').trim();
    if (!top && !right && !bottom && !left) return '';
    if (top && top === right && top === bottom && top === left) return top;
    if (top === bottom && right === left) return (top + ' ' + right).trim();
    if (right === left) return (top + ' ' + right + ' ' + bottom).trim();
    return (top + ' ' + right + ' ' + bottom + ' ' + left).trim();
}

function syncBoxControlToHidden($box) {
    var linked = String($box.find('.metis-theme-box4-link').attr('data-linked') || '1') === '1';
    var top = String($box.find('.metis-theme-box4-input[data-side="top"]').val() || '').trim();
    var right = String($box.find('.metis-theme-box4-input[data-side="right"]').val() || '').trim();
    var bottom = String($box.find('.metis-theme-box4-input[data-side="bottom"]').val() || '').trim();
    var left = String($box.find('.metis-theme-box4-input[data-side="left"]').val() || '').trim();
    if (linked) {
        right = top;
        bottom = top;
        left = top;
        $box.find('.metis-theme-box4-input[data-side="right"]').val(right);
        $box.find('.metis-theme-box4-input[data-side="bottom"]').val(bottom);
        $box.find('.metis-theme-box4-input[data-side="left"]').val(left);
    }
    var value = boxToString(top, right, bottom, left);
    $box.closest('.metis-theme-field').find('.metis-theme-element-input').val(value);
}

function syncBoxControlsFromState() {
    $('.metis-theme-box4').each(function() {
        var $box = $(this);
        var raw = String($box.closest('.metis-theme-field').find('.metis-theme-element-input').val() || '');
        var parsed = parseBoxValue(raw);
        $box.find('.metis-theme-box4-input[data-side="top"]').val(parsed.top);
        $box.find('.metis-theme-box4-input[data-side="right"]').val(parsed.right);
        $box.find('.metis-theme-box4-input[data-side="bottom"]').val(parsed.bottom);
        $box.find('.metis-theme-box4-input[data-side="left"]').val(parsed.left);
        $box.find('.metis-theme-box4-link')
            .attr('data-linked', parsed.linked ? '1' : '0')
            .toggleClass('is-linked', parsed.linked)
            .find('.metis-theme-box4-link-text')
            .text(parsed.linked ? 'Linked' : 'Unlinked');
    });
}

function parseBorderValue(raw) {
    var value = String(raw || '').trim();
    var out = { width: '0', style: 'solid', color: 'var(--metis-color-border,#d8deea)' };
    if (!value || value === 'none') return out;
    var widthMatch = value.match(/(\d+)(px)?/);
    if (widthMatch) out.width = String(widthMatch[1]);
    if (/\b(dashed|dotted|double|solid|none)\b/.test(value)) {
        out.style = value.match(/\b(dashed|dotted|double|solid|none)\b/)[1];
    }
    if (/(var\([^)]+\)|#[0-9a-fA-F]{3,6}|rgba?\([^)]+\)|[a-zA-Z]+)/.test(value)) {
        var parts = value.split(/\s+/);
        out.color = String(parts[parts.length - 1] || out.color);
    }
    return out;
}

function borderValue(width, style, color) {
    var w = parseInt(String(width || '0'), 10);
    if (!Number.isFinite(w) || w <= 0 || style === 'none') return 'none';
    return String(w) + 'px ' + String(style || 'solid') + ' ' + String(color || 'var(--metis-color-border,#d8deea)');
}

function syncBorderControlsFromState() {
    $('.metis-theme-border-width').each(function() {
        var el = String($(this).data('element') || '');
        var $hidden = $('.metis-theme-element-input[data-element="' + el + '"][data-prop="border"]');
        var parsed = parseBorderValue($hidden.val());
        $('.metis-theme-border-width[data-element="' + el + '"]').val(parsed.width);
        $('.metis-theme-border-style[data-element="' + el + '"]').val(parsed.style);
        $('.metis-theme-border-color[data-element="' + el + '"]').val(parsed.color);
        $hidden.val(borderValue(parsed.width, parsed.style, parsed.color));
    });
}

function syncBorderControlToHidden(element) {
    var w = $('.metis-theme-border-width[data-element="' + element + '"]').val();
    var s = $('.metis-theme-border-style[data-element="' + element + '"]').val();
    var c = $('.metis-theme-border-color[data-element="' + element + '"]').val();
    $('.metis-theme-element-input[data-element="' + element + '"][data-prop="border"]').val(borderValue(w, s, c));
}

function parseTextDecorationValue(raw) {
    var value = String(raw || '').trim();
    var out = { type: 'none', color: 'currentColor' };
    if (!value || value === 'none') return out;
    if (/\b(underline|line-through|overline)\b/.test(value)) {
        out.type = value.match(/\b(underline|line-through|overline)\b/)[1];
    }
    var colorMatch = value.match(/(var\([^)]+\)|#[0-9a-fA-F]{3,6}|rgba?\([^)]+\)|currentColor)/);
    if (colorMatch) out.color = colorMatch[1];
    return out;
}

function textDecorationValue(type, color) {
    var t = String(type || 'none');
    if (t === 'none') return 'none';
    return t + ' solid ' + String(color || 'currentColor');
}

function syncTextDecorationControlsFromState() {
    $('.metis-theme-textdec-type').each(function() {
        var el = String($(this).data('element') || '');
        var $hidden = $('.metis-theme-element-input[data-element="' + el + '"][data-prop="text_decoration"]');
        var parsed = parseTextDecorationValue($hidden.val());
        $('.metis-theme-textdec-type[data-element="' + el + '"]').val(parsed.type);
        $('.metis-theme-textdec-color[data-element="' + el + '"]').val(parsed.color);
        $hidden.val(textDecorationValue(parsed.type, parsed.color));
    });
}

function syncTextDecorationControlToHidden(element) {
    var t = $('.metis-theme-textdec-type[data-element="' + element + '"]').val();
    var c = $('.metis-theme-textdec-color[data-element="' + element + '"]').val();
    $('.metis-theme-element-input[data-element="' + element + '"][data-prop="text_decoration"]').val(textDecorationValue(t, c));
}

function syncColorTokenControlsFromState() {
    $('.metis-theme-color-token').each(function() {
        var el = String($(this).data('element') || '');
        var prop = String($(this).data('prop') || '');
        var $hidden = $('.metis-theme-element-input[data-element="' + el + '"][data-prop="' + prop + '"]');
        var raw = String($hidden.val() || '').trim();
        if (!raw) {
            raw = String($(this).find('option:first').val() || '');
            $hidden.val(raw);
        }
        if ($(this).find('option[value="' + raw.replace(/"/g, '\\"') + '"]').length === 0) {
            $(this).append('<option value="' + $('<div>').text(raw).html() + '">Custom</option>');
        }
        $(this).val(raw);
    });
}

function syncColorTokenControlToHidden(element, prop) {
    var value = $('.metis-theme-color-token[data-element="' + element + '"][data-prop="' + prop + '"]').val();
    $('.metis-theme-element-input[data-element="' + element + '"][data-prop="' + prop + '"]').val(String(value || ''));
}

function syncFontSizeControlsFromState() {
    $('.metis-theme-fontsize-range').each(function() {
        var element = String($(this).attr('data-element') || '');
        var $hidden = $('.metis-theme-element-input[data-element="' + element + '"][data-prop="font_size"]');
        var px = cssFontSizeToPx($hidden.val() || '');
        $(this).val(String(px));
        $('.metis-theme-fontsize-value[data-element="' + element + '"]').text(px + 'px');
    });
}

function syncFontSizeToHidden(element) {
    var px = parseInt($('.metis-theme-fontsize-range[data-element="' + element + '"]').val(), 10) || 16;
    $('.metis-theme-element-input[data-element="' + element + '"][data-prop="font_size"]').val(pxToCssFontSize(px));
    $('.metis-theme-fontsize-value[data-element="' + element + '"]').text(px + 'px');
}

function collectElementRules() {
    var rules = {};
    $('.metis-theme-element-input').each(function() {
        var element = String($(this).data('element') || '');
        var prop = String($(this).data('prop') || '');
        if (!element || !prop) return;
        if (!rules[element]) rules[element] = {};
        rules[element][prop] = String($(this).val() || '').trim();
    });
    return rules;
}

function syncElementRulesFromState() {
    var elements = (state.global_styles && state.global_styles.elements && typeof state.global_styles.elements === 'object')
        ? state.global_styles.elements
        : {};
    $('.metis-theme-element-input').each(function() {
        var element = String($(this).data('element') || '');
        var prop = String($(this).data('prop') || '');
        var defaultsEl = defaults.global_styles && defaults.global_styles.elements && defaults.global_styles.elements[element]
            ? defaults.global_styles.elements[element]
            : {};
        var currentEl = elements[element] && typeof elements[element] === 'object' ? elements[element] : {};
        var value = currentEl[prop];
        if (value == null || value === '') value = defaultsEl[prop] || '';
        if (prop === 'font_weight') {
            value = normalizeHeadingWeightOption(value);
        } else if (prop === 'line_height') {
            value = normalizeLineHeightOption(value);
        }
        $(this).val(String(value));
    });
    syncBoxControlsFromState();
    syncFontSizeControlsFromState();
    syncColorTokenControlsFromState();
    syncBorderControlsFromState();
    syncTextDecorationControlsFromState();
    applyElementLivePreviews();
}

function applyElementLivePreviews() {
    var elements = (state.global_styles && state.global_styles.elements && typeof state.global_styles.elements === 'object')
        ? state.global_styles.elements
        : {};
    $('.metis-theme-element-live-target').each(function() {
        var key = String($(this).data('live-element') || '');
        if (!key) return;
        var defaultsEl = defaults.global_styles && defaults.global_styles.elements && defaults.global_styles.elements[key]
            ? defaults.global_styles.elements[key]
            : {};
        var currentEl = elements[key] && typeof elements[key] === 'object' ? elements[key] : {};
        var rule = Object.assign({}, defaultsEl, currentEl);
        var css = {};
        if (rule.font_family) css.fontFamily = rule.font_family;
        if (rule.font_size) css.fontSize = rule.font_size;
        if (rule.font_weight) css.fontWeight = rule.font_weight;
        if (rule.line_height) css.lineHeight = rule.line_height;
        if (rule.color) css.color = rule.color;
        if (rule.margin != null) css.margin = rule.margin;
        if (rule.padding != null) css.padding = rule.padding;
        if (rule.border_radius) css.borderRadius = rule.border_radius;
        if (rule.box_shadow) css.boxShadow = rule.box_shadow;
        if (rule.background) css.background = rule.background;
        if (rule.border) css.border = rule.border;
        if (rule.text_decoration) css.textDecoration = rule.text_decoration;
        $(this).css(css);
    });
}

function resetThemeGlideMenuPreview() {
    var $list = $('#metis-theme-menu-live .metis-template-menu > .metis-shell-menu-list').first();
    if (!$list.length) return;
    $list.children('.metis-shell-menu-item.has-children').removeClass('is-open');
}

var menuPresetDefinitions = {
    h_glide: {
        layout: 'glide_gradient',
        alignment: 'center',
        container: 'contained',
        desktop: { font_size: 19, item_spacing: 'normal', hover_style: 'none', active_style: 'none' },
        dropdown: { behavior: 'hover', animation: 'scale', radius: 8 },
        mobile: { breakpoint: 980, style: 'hamburger', menu_type: 'slide', button_style: 'rounded' },
        chevron: { type: 'none', animation: 'none' }
    },
    h_marker_dropdown: {
        layout: 'marker_dropdown',
        alignment: 'center',
        container: 'contained',
        desktop: { font_size: 13, item_spacing: 'normal', hover_style: 'none', active_style: 'none' },
        dropdown: { behavior: 'hover', animation: 'slide', radius: 0 },
        mobile: { breakpoint: 980, style: 'hamburger', menu_type: 'slide', button_style: 'rounded' },
        chevron: { type: 'none', animation: 'none' }
    },
    h_pill_dropdown: {
        layout: 'horizontal_clean',
        alignment: 'center',
        container: 'contained',
        desktop: { font_size: 14, item_spacing: 'normal', hover_style: 'fill', active_style: 'pill' },
        dropdown: { behavior: 'hover', animation: 'scale', radius: 18 },
        mobile: { breakpoint: 980, style: 'hamburger', menu_type: 'slide', button_style: 'rounded' },
        chevron: { type: 'chevron', animation: 'rotate' }
    },
    h_modern_bar: {
        layout: 'horizontal_clean',
        alignment: 'center',
        container: 'contained',
        desktop: { font_size: 14, item_spacing: 'normal', hover_style: 'underline', active_style: 'underline' },
        dropdown: { behavior: 'hover', animation: 'slide', radius: 14 },
        mobile: { breakpoint: 980, style: 'hamburger', menu_type: 'slide', button_style: 'rounded' },
        chevron: { type: 'chevron', animation: 'rotate' }
    }
};

function menuPresetForStyle(style) {
    var normalized = normalizeMenuStyleOption(style);
    return $.extend(true, {}, menuPresetDefinitions[normalized]);
}

function applyMenuLivePreview() {
    var $live = $('#metis-theme-menu-live');
    if (!$live.length) return;
    ensureObjectPath(state, ['global_styles', 'components', 'menu_config'], {});
    var menuComponent = (state.global_styles.components.menu && typeof state.global_styles.components.menu === 'object')
        ? state.global_styles.components.menu
        : {};

    var menuStyle = normalizeMenuStyleOption(menuComponent.style || ((state.global_styles.global_settings || {}).menu_style || ''), '');
    var preset = menuPresetForStyle(menuStyle);
    var layout = String(preset.layout || 'horizontal_clean');
    var alignment = String(preset.alignment || 'left');
    var container = String(preset.container || 'contained');
    var fontSize = parseInt((preset.desktop || {}).font_size, 10);
    var spacing = String((preset.desktop || {}).item_spacing || 'normal');
    var gap = 16;
    if (spacing === 'tight') gap = 10;
    if (spacing === 'wide') gap = 24;

    var dropdownBehavior = String((preset.dropdown || {}).behavior || 'hover');
    var dropdownAnimation = String((preset.dropdown || {}).animation || 'fade');
    var mobileType = 'slide';
    var chevronType = String((preset.chevron || {}).type || 'chevron');
    var chevronAnimation = String((preset.chevron || {}).animation || 'none');

    var classes = ['metis-theme-menu-live'];
    if (menuStyle) classes.push('metis-menu-style-' + menuStyle);
    if (layout) classes.push('metis-menu-layout-' + layout);
    if (alignment) classes.push('metis-menu-align-' + alignment);
    if (container) classes.push('metis-menu-container-' + container);
    if (mobileType) classes.push('metis-menu-mobile-' + mobileType);
    if (dropdownBehavior) classes.push('metis-menu-dropdown-' + dropdownBehavior);
    if (dropdownAnimation) classes.push('metis-menu-dropdown-anim-' + dropdownAnimation);
    if (chevronType && chevronType !== 'none') classes.push('metis-menu-chevron-' + chevronType);
    if (chevronAnimation) classes.push('metis-menu-chevron-anim-' + chevronAnimation);

    $live.attr('class', classes.join(' '));
    var el = $live.get(0);
    if (el) {
        el.style.setProperty('--metis-menu-font-size', fontSize + 'px');
        el.style.setProperty('--metis-menu-item-gap', gap + 'px');
    }
    $live.find('.metis-shell-menu-link, .metis-shell-menu-btn').removeClass('is-active');
    $live.find('.metis-shell-menu-item').removeClass('is-active is-active-ancestor');
    $live.find('.metis-shell-menu-item.has-children').removeClass('is-open');
    resetThemeGlideMenuPreview();
}

function ensureObjectPath(root, path, fallback) {
    var cur = root;
    for (var i = 0; i < path.length; i++) {
        var k = path[i];
        if (!cur[k] || typeof cur[k] !== 'object') {
            cur[k] = (i === path.length - 1 && fallback) ? fallback : {};
        }
        cur = cur[k];
    }
    return cur;
}

function parseNumber(v, fallback) {
    var n = parseFloat(v);
    return Number.isFinite(n) ? n : fallback;
}

function normalizeHeadingWeightOption(raw) {
    var weight = parseInt(raw, 10);
    if (!Number.isFinite(weight)) return '600';
    if (weight <= 350) return '300';
    if (weight <= 500) return '400';
    if (weight <= 700) return '600';
    return '800';
}

function normalizeLineHeightOption(raw) {
    var value = parseFloat(raw);
    if (!Number.isFinite(value)) return '1.60';
    var options = [1.25, 1.45, 1.60, 1.80];
    var best = options[0];
    var delta = Math.abs(value - best);
    for (var i = 1; i < options.length; i += 1) {
        var d = Math.abs(value - options[i]);
        if (d < delta) {
            delta = d;
            best = options[i];
        }
    }
    return best.toFixed(2);
}

function normalizeMenuDropdownWeightOption(raw) {
    var weight = String(raw == null ? '' : raw).replace(/[^0-9]/g, '');
    var allowed = { '300': true, '400': true, '500': true, '600': true, '700': true, '800': true };
    return allowed[weight] ? weight : '500';
}

function normalizeMenuVerticalAlignOption(raw) {
    var v = String(raw || 'center').toLowerCase();
    return (v === 'top' || v === 'bottom' || v === 'center') ? v : 'center';
}

function normalizeMenuSubOpenAnimationOption(raw) {
    var v = String(raw || 'fade').toLowerCase();
    return (v === 'none' || v === 'fade' || v === 'slide' || v === 'scale') ? v : 'fade';
}

function normalizeMenuSubHoverAnimationOption(raw) {
    var v = String(raw || 'fill').toLowerCase();
    if (v === 'diag_fill' || v === 'wave_fill') v = 'fill';
    return (v === 'none' || v === 'fill' || v === 'lift' || v === 'underline') ? v : 'fill';
}

function normalizeMenuActiveStyleOption(raw) {
    var v = String(raw || 'text').toLowerCase();
    return (v === 'text' || v === 'underline' || v === 'pill') ? v : 'text';
}

function normalizeMenuStyleOption(raw) {
    var value = String(raw || '').toLowerCase();
    var allowed = {
        h_glide: true,
        h_marker_dropdown: true,
        h_pill_dropdown: true,
        h_modern_bar: true
    };
    return allowed[value] ? value : 'h_glide';
}

function parseColor(v, fallback) {
    var raw = String(v || '').trim();
    if (/^#[0-9a-fA-F]{6}$/.test(raw)) return raw;
    return fallback;
}

function normalizeUnit(value, fallback) {
    var raw = String(value == null ? '' : value).trim();
    if (!raw) return fallback;
    if (/^-?\d+(\.\d+)?$/.test(raw)) return raw + 'px';
    if (/^-?\d+(\.\d+)?(px|rem|em|vh|vw|%)$/.test(raw)) return raw;
    return fallback;
}

function parseTokenEditor(text) {
    var map = {};
    String(text || '').split(/\n+/).forEach(function(line) {
        var raw = line.trim();
        if (!raw || raw.indexOf(':') === -1) return;
        var idx = raw.indexOf(':');
        var key = raw.slice(0, idx).trim();
        var val = raw.slice(idx + 1).trim();
        if (!key || !val) return;
        map[key] = val;
    });
    return map;
}

function collectThemeData() {
    var out = deepClone(state);
    ensureObjectPath(out, ['global_styles'], {});
    ensureObjectPath(out, ['global_styles', 'layout'], {});
    ensureObjectPath(out, ['global_styles', 'layout_tokens'], {});
    ensureObjectPath(out, ['global_styles', 'layout_tokens', 'breakpoints'], {});
    ensureObjectPath(out, ['global_styles', 'global_settings'], {});
    ensureObjectPath(out, ['global_styles', 'components'], {});
    ensureObjectPath(out, ['global_styles', 'components', 'buttons'], {});
    ensureObjectPath(out, ['global_styles', 'components', 'menu'], {});
    ensureObjectPath(out, ['global_styles', 'components', 'footer'], {});
    ensureObjectPath(out, ['global_styles', 'components', 'cards'], {});
    ensureObjectPath(out, ['global_styles', 'components', 'forms'], {});
    ensureObjectPath(out, ['global_styles', 'components', 'links'], {});
    ensureObjectPath(out, ['global_styles', 'elements'], {});
    ensureObjectPath(out, ['global_styles', 'advanced'], {});

    out.typography.base_size = String(parseNumber($('#metis-theme-base-size').val(), 16));
    out.typography.line_height = normalizeLineHeightOption($('#metis-theme-line-height').val());
    out.typography.heading_weight = normalizeHeadingWeightOption($('#metis-theme-heading-weight').val());
    out.typography.body_font = $('#metis-theme-body-font').val() || defaults.typography.body_font;
    out.typography.heading_font = $('#metis-theme-heading-font').val() || defaults.typography.heading_font;

    out.global_styles.layout.max_width = Math.max(720, Math.min(1920, parseInt($('#metis-theme-max-width').val(), 10) || 1200));
    out.global_styles.layout.container_width = Math.max(640, Math.min(1600, parseInt($('#metis-theme-container-width').val(), 10) || 860));
    out.global_styles.layout.spacing_preset = $('#metis-theme-spacing-preset').val() || 'balanced';
    if ($('#metis-theme-site-layout-profile').length) {
        out.global_styles.global_settings.site_layout_profile = String($('#metis-theme-site-layout-profile').val() || 'modern_split');
    }
    if ($('#metis-theme-newsletter-layout-profile').length) {
        out.global_styles.global_settings.newsletter_layout_profile = String($('#metis-theme-newsletter-layout-profile').val() || 'newsletter_standard');
    }
    out.global_styles.layout_tokens.breakpoints.sm = Math.max(360, Math.min(2400, parseInt($('#metis-theme-bp-sm').val(), 10) || 640));
    out.global_styles.layout_tokens.breakpoints.md = Math.max(360, Math.min(2400, parseInt($('#metis-theme-bp-md').val(), 10) || 768));
    out.global_styles.layout_tokens.breakpoints.lg = Math.max(360, Math.min(2400, parseInt($('#metis-theme-bp-lg').val(), 10) || 1024));
    out.global_styles.layout_tokens.breakpoints.xl = Math.max(360, Math.min(2400, parseInt($('#metis-theme-bp-xl').val(), 10) || 1280));
    out.global_styles.global_settings.title_format = String($('#metis-theme-title-format').val() || '{page} | {site}').trim() || '{page} | {site}';
    ensureObjectPath(out, ['global_styles', 'global_settings', 'branding_color_bindings'], {});

    out.global_styles.components.buttons.radius = Math.max(0, parseInt($('#metis-theme-btn-radius').val(), 10) || 0);
    var btnPad = parseBoxValue($('#metis-theme-btn-padding-box').val());
    var cardPad = parseBoxValue($('#metis-theme-card-padding-box').val());
    out.global_styles.components.buttons.padding_x = Math.max(4, parseInt(btnPad.right || btnPad.left || '14', 10) || 14);
    out.global_styles.components.buttons.padding_y = Math.max(4, parseInt(btnPad.top || btnPad.bottom || '10', 10) || 10);
    out.global_styles.components.cards.radius = Math.max(0, parseInt($('#metis-theme-card-radius').val(), 10) || 12);
    out.global_styles.components.cards.padding = Math.max(8, parseInt(cardPad.top || cardPad.right || cardPad.bottom || cardPad.left || '16', 10) || 16);
    out.global_styles.components.forms.radius = Math.max(0, parseInt($('#metis-theme-form-radius').val(), 10) || 8);
    out.global_styles.components.forms.border = Math.max(0, parseInt($('#metis-theme-form-border').val(), 10) || 1);
    out.global_styles.components.links.underline = ($('#metis-theme-link-underline').val() === '1') ? 1 : 0;
    ensureObjectPath(out, ['global_styles', 'components', 'menu_config'], {});
    var selectedMenuStyle = normalizeMenuStyleOption($('#metis-theme-menu-style').val(), '');
    var selectedMenuPreset = menuPresetForStyle(selectedMenuStyle);
    out.global_styles.components.menu.style = selectedMenuStyle;
    out.global_styles.global_settings.menu_style = selectedMenuStyle;
    out.global_styles.components.menu_config = $.extend(true, {}, selectedMenuPreset);
    var footerBinding = String($('.metis-theme-color-binding[data-key="footer_background"]').val() || '');
    if (footerBinding && Object.prototype.hasOwnProperty.call(brandingPalette, footerBinding)) {
        out.global_styles.global_settings.footer_background_binding = footerBinding;
        out.global_styles.components.footer.background = String(brandingPalette[footerBinding]);
    } else {
        delete out.global_styles.global_settings.footer_background_binding;
        out.global_styles.components.footer.background = parseColor($('.metis-theme-color[data-key="footer_background"]').val(), '#f8fafc');
    }
    out.global_styles.elements = collectElementRules();

    out.global_styles.advanced.custom_css = String($('#metis-theme-custom-css').val() || '');
    out.custom_tokens.tokens = parseTokenEditor($('#metis-theme-token-editor').val());

    $('.metis-theme-color').each(function() {
        var key = String($(this).data('key') || '');
        if (!key) return;
        if (key === 'footer_background') return;
        out.colors[key] = parseColor($(this).val(), defaults.colors[key] || '#000000');
    });
    $('.metis-theme-color-binding').each(function() {
        var key = String($(this).data('key') || '');
        var binding = String($(this).val() || '');
        if (!key) return;
        if (key === 'footer_background') return;
        if (binding && Object.prototype.hasOwnProperty.call(brandingPalette, binding)) {
            out.global_styles.global_settings.branding_color_bindings[key] = binding;
        } else {
            delete out.global_styles.global_settings.branding_color_bindings[key];
        }
    });

    $('.metis-theme-spacing').each(function() {
        var key = String($(this).data('key') || '');
        if (!key) return;
        out.spacing[key] = normalizeUnit($(this).val(), defaults.spacing[key] || '16px');
    });

    out.typography.custom_fonts = out.typography.custom_fonts || {};
    applyBrandingColorBindings(out);
    return out;
}

function renderCustomFontList() {
    var $list = $('#metis-theme-custom-font-list');
    $list.empty();
    var fonts = (state.typography && state.typography.custom_fonts) ? state.typography.custom_fonts : {};
    Object.keys(fonts).forEach(function(key) {
        var item = fonts[key] || {};
        if (!item.name) return;
        $list.append('<span class="metis-theme-chip">' + $('<div>').text(item.name).html() + '</span>');
    });
}

function ensureCustomFontsLoaded() {
    var fonts = (state.typography && state.typography.custom_fonts) ? state.typography.custom_fonts : {};
    var css = '';
    Object.keys(fonts).forEach(function(key) {
        var item = fonts[key] || {};
        if (!item.name || !item.data) return;
        var format = String(item.format || 'woff2').toLowerCase() === 'woff' ? 'woff' : 'woff2';
        css += '@font-face{font-family:"' + item.name.replace(/"/g, '') + '";src:url("' + item.data + '") format("' + format + '");font-weight:100 900;font-style:normal;font-display:swap;}\n';
    });
    var id = 'metis-theme-custom-font-face';
    var node = document.getElementById(id);
    if (!node) {
        node = document.createElement('style');
        node.id = id;
        document.head.appendChild(node);
    }
    node.textContent = css;
}

function applySpacingPreset(preset) {
    var target = String(preset || 'balanced');
    var map = {
        compact: { xs:'2px', sm:'6px', md:'12px', lg:'18px', xl:'28px', xxl:'44px' },
        balanced: { xs:'4px', sm:'8px', md:'16px', lg:'24px', xl:'40px', xxl:'64px' },
        airy: { xs:'6px', sm:'12px', md:'20px', lg:'32px', xl:'48px', xxl:'80px' }
    };
    if (map[target]) {
        state.spacing = Object.assign({}, state.spacing, map[target]);
    }
}

function applyPreview() {
    state = collectThemeData();

    ensureCustomFontsLoaded();
    applyElementLivePreviews();
    applyMenuLivePreview();

    var customCss = String(state.global_styles.advanced.custom_css || '');
    var id = 'metis-theme-inline-custom-css';
    var node = document.getElementById(id);
    if (!node) {
        node = document.createElement('style');
        node.id = id;
        document.head.appendChild(node);
    }
    node.textContent = customCss;

    renderAccessibilityChecks();
}

function relativeLuminance(hex) {
    var c = String(hex || '').trim();
    if (!/^#[0-9a-fA-F]{6}$/.test(c)) return 0;
    var r = parseInt(c.substr(1, 2), 16) / 255;
    var g = parseInt(c.substr(3, 2), 16) / 255;
    var b = parseInt(c.substr(5, 2), 16) / 255;
    var rgb = [r, g, b].map(function(v) {
        return v <= 0.03928 ? (v / 12.92) : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return (0.2126 * rgb[0]) + (0.7152 * rgb[1]) + (0.0722 * rgb[2]);
}

function contrastRatio(fg, bg) {
    var l1 = relativeLuminance(fg);
    var l2 = relativeLuminance(bg);
    var light = Math.max(l1, l2);
    var dark = Math.min(l1, l2);
    return (light + 0.05) / (dark + 0.05);
}

function renderAccessibilityChecks() {
    var text = state.colors.text || '#1a1f2b';
    var bg = state.colors.bg || '#ffffff';
    var link = state.colors.link || '#2b59ff';
    var baseSize = parseFloat(state.typography.base_size || '16') || 16;
    var textRatio = contrastRatio(text, bg);
    var linkRatio = contrastRatio(link, bg);
    var rows = [];
    rows.push('<div class="' + (textRatio >= 4.5 ? 'metis-theme-a11y-ok' : 'metis-theme-a11y-warn') + '">Text contrast: ' + textRatio.toFixed(2) + ':1 ' + (textRatio >= 4.5 ? 'Pass' : 'Needs improvement (target 4.5:1)') + '</div>');
    rows.push('<div class="' + (linkRatio >= 3.0 ? 'metis-theme-a11y-ok' : 'metis-theme-a11y-warn') + '">Link contrast: ' + linkRatio.toFixed(2) + ':1 ' + (linkRatio >= 3.0 ? 'Pass' : 'Needs improvement (target 3:1)') + '</div>');
    rows.push('<div class="' + (baseSize >= 16 ? 'metis-theme-a11y-ok' : 'metis-theme-a11y-warn') + '">Body font size: ' + baseSize + 'px ' + (baseSize >= 16 ? 'Pass' : 'Recommended minimum is 16px') + '</div>');
    $('#metis-theme-a11y-results').html(rows.join(''));
}

function versionStorageKey() {
    var uid = 'anon';
    if (window.metisAjax && window.metisAjax.current_user_id != null) {
        uid = String(window.metisAjax.current_user_id);
    } else if (window.metisWebsiteAjax && window.metisWebsiteAjax.current_user_id != null) {
        uid = String(window.metisWebsiteAjax.current_user_id);
    }
    return 'metis.website.theme.versions.' + uid;
}

function getVersionHistory() {
    try {
        var raw = window.localStorage.getItem(versionStorageKey());
        if (!raw) return [];
        var parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (_e) {
        return [];
    }
}

function saveVersionSnapshot(snapshot) {
    var list = getVersionHistory();
    list.unshift(snapshot);
    if (list.length > 10) {
        list = list.slice(0, 10);
    }
    try {
        window.localStorage.setItem(versionStorageKey(), JSON.stringify(list));
    } catch (_e) {}
    renderVersionOptions();
}

function renderVersionOptions() {
    var list = getVersionHistory();
    var html = ['<option value="">Select saved snapshot</option>'];
    list.forEach(function(item, i) {
        var label = item && item.label ? item.label : ('Snapshot ' + (i + 1));
        html.push('<option value="' + i + '">' + $('<div>').text(label).html() + '</option>');
    });
    $('#metis-theme-version-select').html(html.join(''));
}

function syncFormFromState() {
    applyBrandingColorBindings(state);
    ensureObjectPath(state, ['global_styles', 'layout_tokens', 'breakpoints'], {});
    ensureObjectPath(state, ['global_styles', 'global_settings'], {});
    ensureObjectPath(state, ['global_styles', 'components', 'buttons'], {});
    ensureObjectPath(state, ['global_styles', 'components', 'cards'], {});
    ensureObjectPath(state, ['global_styles', 'components', 'forms'], {});
    ensureObjectPath(state, ['global_styles', 'components', 'links'], {});
    ensureObjectPath(state, ['global_styles', 'components', 'menu'], {});
    ensureObjectPath(state, ['global_styles', 'components', 'footer'], {});
    ensureObjectPath(state, ['global_styles', 'advanced'], {});
    $('#metis-theme-body-font').val(state.typography.body_font || defaults.typography.body_font);
    $('#metis-theme-heading-font').val(state.typography.heading_font || defaults.typography.heading_font);
    $('#metis-theme-base-size').val(state.typography.base_size || defaults.typography.base_size);
    $('#metis-theme-line-height').val(normalizeLineHeightOption(state.typography.line_height || defaults.typography.line_height));
    $('#metis-theme-heading-weight').val(normalizeHeadingWeightOption(state.typography.heading_weight || defaults.typography.heading_weight));

    $('#metis-theme-max-width').val((state.global_styles.layout && state.global_styles.layout.max_width) ? state.global_styles.layout.max_width : 1200);
    $('#metis-theme-container-width').val((state.global_styles.layout && state.global_styles.layout.container_width) ? state.global_styles.layout.container_width : 860);
    $('#metis-theme-spacing-preset').val((state.global_styles.layout && state.global_styles.layout.spacing_preset) ? state.global_styles.layout.spacing_preset : 'balanced');
    if ($('#metis-theme-site-layout-profile').length) {
        $('#metis-theme-site-layout-profile').val((state.global_styles.global_settings && state.global_styles.global_settings.site_layout_profile) ? state.global_styles.global_settings.site_layout_profile : 'modern_split');
    }
    if ($('#metis-theme-newsletter-layout-profile').length) {
        $('#metis-theme-newsletter-layout-profile').val((state.global_styles.global_settings && state.global_styles.global_settings.newsletter_layout_profile) ? state.global_styles.global_settings.newsletter_layout_profile : 'newsletter_standard');
    }
    $('#metis-theme-bp-sm').val((state.global_styles.layout_tokens.breakpoints && state.global_styles.layout_tokens.breakpoints.sm) ? state.global_styles.layout_tokens.breakpoints.sm : 640);
    $('#metis-theme-bp-md').val((state.global_styles.layout_tokens.breakpoints && state.global_styles.layout_tokens.breakpoints.md) ? state.global_styles.layout_tokens.breakpoints.md : 768);
    $('#metis-theme-bp-lg').val((state.global_styles.layout_tokens.breakpoints && state.global_styles.layout_tokens.breakpoints.lg) ? state.global_styles.layout_tokens.breakpoints.lg : 1024);
    $('#metis-theme-bp-xl').val((state.global_styles.layout_tokens.breakpoints && state.global_styles.layout_tokens.breakpoints.xl) ? state.global_styles.layout_tokens.breakpoints.xl : 1280);
    $('#metis-theme-title-format').val((state.global_styles.global_settings && state.global_styles.global_settings.title_format) ? state.global_styles.global_settings.title_format : '{page} | {site}');

    $('#metis-theme-btn-radius').val(state.global_styles.components.buttons.radius);
    $('#metis-theme-btn-padding-box').val(
        String(state.global_styles.components.buttons.padding_y || 10) + 'px ' + String(state.global_styles.components.buttons.padding_x || 14) + 'px'
    );
    $('#metis-theme-card-radius').val(state.global_styles.components.cards.radius);
    $('#metis-theme-card-padding-box').val(String(state.global_styles.components.cards.padding || 16) + 'px');
    $('#metis-theme-form-radius').val(state.global_styles.components.forms.radius);
    $('#metis-theme-form-border').val(state.global_styles.components.forms.border);
    $('#metis-theme-link-underline').val(String(state.global_styles.components.links.underline ? 1 : 0));
    var menuComponent = (state.global_styles.components && state.global_styles.components.menu)
        ? state.global_styles.components.menu
        : {};
    $('#metis-theme-menu-style').val(normalizeMenuStyleOption(menuComponent.style || ((state.global_styles.global_settings || {}).menu_style || ''), ''));
    var footerBgBinding = String((state.global_styles.global_settings && state.global_styles.global_settings.footer_background_binding) ? state.global_styles.global_settings.footer_background_binding : '');
    var footerBg = (footerBgBinding && Object.prototype.hasOwnProperty.call(brandingPalette, footerBgBinding))
        ? String(brandingPalette[footerBgBinding])
        : ((state.global_styles.components.footer && state.global_styles.components.footer.background) ? state.global_styles.components.footer.background : '#f8fafc');
    $('.metis-theme-color[data-key="footer_background"]').val(footerBg);
    $('.metis-theme-color-binding[data-key="footer_background"]').val(footerBgBinding);
    $('.metis-theme-color-dot[data-key="footer_background"]').css('background', footerBg);
    updateBindingDropdownUi('footer_background');

    $('#metis-theme-custom-css').val(state.global_styles.advanced.custom_css || '');

    Object.keys(defaults.colors).forEach(function(key) {
        var val = state.colors[key] || defaults.colors[key];
        var binding = String((((state.global_styles || {}).global_settings || {}).branding_color_bindings || {})[key] || '');
        var locked = binding && Object.prototype.hasOwnProperty.call(brandingPalette, binding);
        $('.metis-theme-color-binding[data-key="' + key + '"]').val(binding);
        $('.metis-theme-color[data-key="' + key + '"]').val(val);
        $('.metis-theme-color[data-key="' + key + '"]').prop('disabled', !!locked).attr('title', locked ? 'Managed by branding token' : '');
        $('.metis-theme-color-dot[data-key="' + key + '"]')
            .css('background', val)
            .toggleClass('is-locked', !!locked)
            .attr('title', locked ? 'Managed by branding token' : 'Custom fixed color');
        updateBindingDropdownUi(key);
    });
    var footerBinding = String((((state.global_styles || {}).global_settings || {}).footer_background_binding) || '');
    var footerLocked = footerBinding && Object.prototype.hasOwnProperty.call(brandingPalette, footerBinding);
    $('.metis-theme-color-binding[data-key="footer_background"]').val(footerBinding);
    $('.metis-theme-color[data-key="footer_background"]')
        .prop('disabled', !!footerLocked)
        .attr('title', footerLocked ? 'Managed by branding token' : '');
    $('.metis-theme-color-dot[data-key="footer_background"]')
        .toggleClass('is-locked', !!footerLocked)
        .attr('title', footerLocked ? 'Managed by branding token' : 'Custom fixed color');
    updateBindingDropdownUi('footer_background');

    Object.keys(defaults.spacing).forEach(function(key) {
        var val = state.spacing[key] || defaults.spacing[key];
        $('.metis-theme-spacing[data-key="' + key + '"]').val(val);
    });

    var tokenLines = [];
    var tokens = (state.custom_tokens && state.custom_tokens.tokens) ? state.custom_tokens.tokens : {};
    Object.keys(tokens).forEach(function(k) {
        tokenLines.push(k + ': ' + tokens[k]);
    });
    $('#metis-theme-token-editor').val(tokenLines.join('\n'));

    renderCustomFontList();
    syncElementRulesFromState();
    rebuildStyledSelects();
    applyPreview();
}

function bindingLabelFor(key, binding) {
    if (binding && Object.prototype.hasOwnProperty.call(brandingPalette, binding)) {
        var label = String((brandingLabels && brandingLabels[binding]) ? brandingLabels[binding] : binding);
        return label;
    }
    return 'Custom / fixed';
}

function updateBindingDropdownUi(key) {
    var binding = String($('.metis-theme-color-binding[data-key="' + key + '"]').val() || '');
    var color = String($('.metis-theme-color[data-key="' + key + '"]').val() || defaults.colors[key] || '#000000');
    var dotColor = (binding && Object.prototype.hasOwnProperty.call(brandingPalette, binding))
        ? String(brandingPalette[binding])
        : color;
    var label = bindingLabelFor(key, binding);
    var $trigger = $('.metis-theme-binding-trigger[data-key="' + key + '"]');
    $trigger.find('.metis-theme-binding-dot').css('background', dotColor);
    $trigger.find('.metis-theme-binding-text').text(label);
}

function updateMenuStyleDropdownUi() {
    // no-op; menu style now uses dedicated menu section controls.
}

function closeAllBindingMenus() {
    $('.metis-theme-binding-dropdown').removeClass('is-open');
    $('.metis-theme-selectx').removeClass('is-open');
}

function isColorSelect($select) {
    return $select.hasClass('metis-theme-color-token')
        || $select.hasClass('metis-theme-border-color')
        || $select.hasClass('metis-theme-textdec-color');
}

function colorFromTokenValue(value) {
    var raw = String(value || '').trim();
    if (!raw) return '#ffffff';
    if (/^#[0-9a-fA-F]{6}$/.test(raw)) return raw;
    if (raw === 'currentColor') return String((state.colors && state.colors.text) ? state.colors.text : '#1a1f2b');
    var map = {
        'var(--metis-color-primary,#485bc7)': 'primary',
        'var(--metis-color-accent,#ff7542)': 'accent',
        'var(--metis-color-text,#1a1f2b)': 'text',
        'var(--metis-color-link,#2b59ff)': 'link',
        'var(--metis-color-muted,#64748b)': 'muted',
        'var(--metis-color-surface,#ffffff)': 'surface',
        'var(--metis-color-bg,#ffffff)': 'bg',
        'var(--metis-color-border,#d8deea)': 'border'
    };
    if (Object.prototype.hasOwnProperty.call(map, raw)) {
        var key = map[raw];
        return String((state.colors && state.colors[key]) ? state.colors[key] : '#ffffff');
    }
    return '#ffffff';
}

function selectLabelHtml($option, $select) {
    var text = String($option.text() || '');
    var safe = $('<div>').text(text).html();
    var style = '';
    if ($select.attr('id') === 'metis-theme-body-font' || $select.attr('id') === 'metis-theme-heading-font') {
        var family = String($option.val() || '').trim();
        if (family && family !== 'inherit') {
            style = ' style="font-family:' + $('<div>').text(family).html() + ';"';
        }
    }
    var dot = '';
    if (isColorSelect($select)) {
        var dotColor = colorFromTokenValue($option.val());
        dot = '<span class="metis-theme-selectx-dot" style="background:' + $('<div>').text(dotColor).html() + ';"></span>';
    }
    return dot + '<span class="metis-theme-selectx-label"' + style + '>' + safe + '</span>';
}

function rebuildStyledSelects() {
    $('.metis-theme-selectx').remove();
    var seq = 0;
    $('select.metis-input').each(function() {
        var $select = $(this);
        if ($select.closest('.metis-theme-binding-dropdown').length) return;
        if ($select.hasClass('metis-theme-color-binding')) return;
        if ($select.hasClass('metis-is-hidden')) return;
        var key = String($select.attr('id') || $select.attr('data-selectx-id') || '');
        if (!key) {
            seq += 1;
            key = 'selectx-' + seq;
            $select.attr('data-selectx-id', key);
        }

        $select.addClass('metis-theme-native-hidden');
        var value = String($select.val() || '');
        var $selected = $select.find('option[value="' + value.replace(/"/g, '\\"') + '"]').first();
        if (!$selected.length) $selected = $select.find('option:first');

        var html = [];
        html.push('<div class="metis-theme-selectx" data-for="' + $('<div>').text(key).html() + '">');
        html.push('<button type="button" class="metis-input metis-theme-selectx-trigger">');
        html.push(selectLabelHtml($selected, $select));
        html.push('<span class="metis-theme-binding-caret">▾</span>');
        html.push('</button>');
        html.push('<div class="metis-theme-selectx-menu">');
        $select.find('option').each(function() {
            var $opt = $(this);
            var optVal = String($opt.val() || '');
            var selectedClass = (optVal === value) ? ' is-selected' : '';
            html.push('<button type="button" class="metis-theme-selectx-option' + selectedClass + '" data-value="' + $('<div>').text(optVal).html() + '">' + selectLabelHtml($opt, $select) + '</button>');
        });
        html.push('</div>');
        html.push('</div>');
        $(html.join('')).insertAfter($select);
    });
}

function buildColorGrid() {
    var keys = Object.keys(defaults.colors).concat(['footer_background']);
    var html = '';
    var bindingOptions = ['<button type="button" class="metis-theme-binding-option" data-value="">'
        + '<span class="metis-theme-binding-option-dot"></span>'
        + '<span class="metis-theme-binding-option-text">Custom / fixed</span>'
        + '</button>'];
    Object.keys(brandingPalette).forEach(function(brandingKey) {
        var label = String((brandingLabels && brandingLabels[brandingKey]) ? brandingLabels[brandingKey] : brandingKey);
        var value = String(brandingPalette[brandingKey] || '#ffffff');
        bindingOptions.push(
            '<button type="button" class="metis-theme-binding-option" data-value="' + $('<div>').text(brandingKey).html() + '">'
            + '<span class="metis-theme-binding-option-dot" style="background:' + value + '"></span>'
            + '<span class="metis-theme-binding-option-text">' + $('<div>').text(label).html() + '</span>'
            + '</button>'
        );
    });
    keys.forEach(function(key) {
        var label = key.replace(/_/g, ' ').replace(/\b\w/g, function(ch) { return ch.toUpperCase(); });
        var value = (key === 'footer_background')
            ? String((((state.global_styles || {}).components || {}).footer || {}).background || '#f8fafc')
            : (state.colors[key] || defaults.colors[key]);
        var binding = (key === 'footer_background')
            ? String((((state.global_styles || {}).global_settings || {}).footer_background_binding) || '')
            : String((((state.global_styles || {}).global_settings || {}).branding_color_bindings || {})[key] || '');
        var locked = binding && Object.prototype.hasOwnProperty.call(brandingPalette, binding);
        var disabled = locked ? ' disabled' : '';
        var title = locked ? ' title="Managed by branding token"' : '';
        var triggerLabel = bindingLabelFor(key, binding);
        var triggerDot = (binding && Object.prototype.hasOwnProperty.call(brandingPalette, binding)) ? brandingPalette[binding] : value;
        html += '' +
            '<div class="metis-theme-field">' +
                '<label class="metis-theme-label">' + label + '</label>' +
                '<div class="metis-theme-color-wrap">' +
                    '<input type="color" class="metis-theme-color metis-is-hidden" data-key="' + key + '" value="' + value + '"' + disabled + title + '>' +
                    '<button type="button" class="metis-theme-color-dot' + (locked ? ' is-locked' : '') + '" data-key="' + key + '"' + title + ' style="background:' + value + '"></button>' +
                    '<input type="hidden" class="metis-theme-color-binding" data-key="' + key + '" value="' + $('<div>').text(binding).html() + '">' +
                    '<div class="metis-theme-binding-dropdown" data-key="' + key + '">' +
                        '<button type="button" class="metis-input metis-input-sm metis-theme-binding-trigger" data-key="' + key + '">' +
                            '<span class="metis-theme-binding-dot" style="background:' + triggerDot + '"></span>' +
                            '<span class="metis-theme-binding-text">' + $('<div>').text(triggerLabel).html() + '</span>' +
                            '<span class="metis-theme-binding-caret">▾</span>' +
                        '</button>' +
                        '<div class="metis-theme-binding-menu">' + bindingOptions.join('') + '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';
    });
    $('#metis-theme-color-grid').html(html);
}

function handleDataFile(input, callback) {
    var file = input && input.files && input.files[0] ? input.files[0] : null;
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(evt) {
        callback(file, String(evt.target && evt.target.result ? evt.target.result : ''));
    };
    reader.readAsDataURL(file);
}

function initNav() {
    $('.metis-theme-nav-btn').on('click', function() {
        var target = String($(this).data('section-target') || 'colors');
        $('.metis-theme-nav-btn').removeClass('is-active');
        $(this).addClass('is-active');
        $('.metis-theme-card').removeClass('is-active');
        $('.metis-theme-card[data-section="' + target + '"]').addClass('is-active');
    });
}

$(document).on('input change', '.metis-theme-color, .metis-theme-spacing, .metis-theme-element-input, #metis-theme-body-font, #metis-theme-heading-font, #metis-theme-base-size, #metis-theme-line-height, #metis-theme-heading-weight, #metis-theme-max-width, #metis-theme-container-width, #metis-theme-spacing-preset, #metis-theme-bp-sm, #metis-theme-bp-md, #metis-theme-bp-lg, #metis-theme-bp-xl, #metis-theme-title-format, #metis-theme-btn-radius, #metis-theme-card-radius, #metis-theme-form-radius, #metis-theme-form-border, #metis-theme-link-underline, #metis-theme-menu-style, #metis-theme-custom-css, #metis-theme-token-editor', function() {
    markThemeDirtyFromInput(this);
    if (this.id === 'metis-theme-spacing-preset') {
        applySpacingPreset($(this).val());
        syncFormFromState();
        return;
    }
    applyPreview();
});

$(document).on('click', '.metis-theme-box4-link', function() {
    var $btn = $(this);
    var linked = String($btn.attr('data-linked') || '1') === '1';
    $btn.attr('data-linked', linked ? '0' : '1')
        .toggleClass('is-linked', !linked)
        .find('.metis-theme-box4-link-text')
        .text(linked ? 'Unlinked' : 'Linked');
    var $box = $btn.closest('.metis-theme-box4');
    syncBoxControlToHidden($box);
    markThemeDirtyFromInput($box.find('input[type="hidden"], .metis-theme-box4-input').get(0) || this);
    applyPreview();
});

$(document).on('input change', '.metis-theme-box4-input', function() {
    var $box = $(this).closest('.metis-theme-box4');
    syncBoxControlToHidden($box);
    markThemeDirtyFromInput(this);
    applyPreview();
});

$(document).on('input change', '.metis-theme-border-width, .metis-theme-border-style, .metis-theme-border-color', function() {
    var el = String($(this).data('element') || '');
    if (!el) return;
    syncBorderControlToHidden(el);
    markThemeDirtyFromInput(this);
    applyPreview();
});

$(document).on('input change', '.metis-theme-textdec-type, .metis-theme-textdec-color', function() {
    var el = String($(this).data('element') || '');
    if (!el) return;
    syncTextDecorationControlToHidden(el);
    markThemeDirtyFromInput(this);
    applyPreview();
});

$(document).on('input change', '.metis-theme-color-token', function() {
    var el = String($(this).data('element') || '');
    var prop = String($(this).data('prop') || '');
    if (!el || !prop) return;
    syncColorTokenControlToHidden(el, prop);
    markThemeDirtyFromInput(this);
    applyPreview();
});

$(document).on('input change', '.metis-theme-fontsize-range', function() {
    var el = String($(this).data('element') || '');
    if (!el) return;
    syncFontSizeToHidden(el);
    markThemeDirtyFromInput(this);
    applyPreview();
});

$(document).on('input', '.metis-theme-color', function() {
    var key = String($(this).data('key') || '');
    if (!key) return;
    var colorValue = String($(this).val() || '');
    $('.metis-theme-color-dot[data-key="' + key + '"]').css('background', colorValue);
    if (key === 'footer_background') {
        ensureObjectPath(state, ['global_styles', 'components', 'footer'], {});
        state.global_styles.components.footer.background = parseColor(colorValue, '#f8fafc');
    }
    var binding = String($('.metis-theme-color-binding[data-key="' + key + '"]').val() || '');
    if (!binding) {
        updateBindingDropdownUi(key);
    }
    applyPreview();
});

$(document).on('change', '.metis-theme-color-binding', function() {
    var key = String($(this).data('key') || '');
    var binding = String($(this).val() || '');
    if (!key) return;
    markThemeDirtyFromInput(this);
    if (key === 'footer_background') {
        ensureObjectPath(state, ['global_styles', 'global_settings'], {});
        ensureObjectPath(state, ['global_styles', 'components', 'footer'], {});
        if (binding && Object.prototype.hasOwnProperty.call(brandingPalette, binding)) {
            state.global_styles.global_settings.footer_background_binding = binding;
            state.global_styles.components.footer.background = String(brandingPalette[binding]);
        } else {
            delete state.global_styles.global_settings.footer_background_binding;
        }
        syncFormFromState();
        return;
    }
    ensureObjectPath(state, ['global_styles', 'global_settings', 'branding_color_bindings'], {});
    if (binding && Object.prototype.hasOwnProperty.call(brandingPalette, binding)) {
        state.global_styles.global_settings.branding_color_bindings[key] = binding;
    } else {
        delete state.global_styles.global_settings.branding_color_bindings[key];
    }
    syncFormFromState();
});

$(document).on('click', '.metis-theme-color-dot', function() {
    var key = String($(this).data('key') || '');
    if (!key) return;
    var $input = $('.metis-theme-color[data-key="' + key + '"]');
    if ($input.prop('disabled')) return;
    $input.trigger('click');
});

$(document).on('click', '.metis-theme-binding-trigger', function(e) {
    e.preventDefault();
    e.stopPropagation();
    var key = String($(this).data('key') || '');
    var $dd;
    if (key) {
        $dd = $('.metis-theme-binding-dropdown[data-key="' + key + '"]');
    } else {
        $dd = $(this).closest('.metis-theme-binding-dropdown');
    }
    if (!$dd || !$dd.length) return;
    var willOpen = !$dd.hasClass('is-open');
    closeAllBindingMenus();
    if (willOpen) $dd.addClass('is-open');
});

$(document).on('click', '.metis-theme-selectx-trigger', function(e) {
    e.preventDefault();
    e.stopPropagation();
    var $dd = $(this).closest('.metis-theme-selectx');
    var willOpen = !$dd.hasClass('is-open');
    closeAllBindingMenus();
    if (willOpen) $dd.addClass('is-open');
});

$(document).on('click', '.metis-theme-selectx-option', function(e) {
    e.preventDefault();
    e.stopPropagation();
    var $opt = $(this);
    var rawValue = $opt.attr('data-value');
    var value = (typeof rawValue === 'undefined') ? '' : String(rawValue);
    var $dd = $opt.closest('.metis-theme-selectx');
    var key = String($dd.attr('data-for') || '');
    var $select = $('#' + key);
    if (!$select.length) {
        $select = $('select[data-selectx-id="' + key.replace(/"/g, '\\"') + '"]');
    }
    if (!$select.length) return;
    $select.val(value).trigger('change');
    rebuildStyledSelects();
    closeAllBindingMenus();
});

$(document).on('click', '.metis-theme-binding-option', function(e) {
    e.preventDefault();
    e.stopPropagation();
    var $dropdown = $(this).closest('.metis-theme-binding-dropdown');
    var key = String($dropdown.data('key') || '');
    var value = String($(this).data('value') || '');
    if (!key) return;
    $('.metis-theme-color-binding[data-key="' + key + '"]').val(value).trigger('change');
    closeAllBindingMenus();
});

$(document).on('click', function() {
    closeAllBindingMenus();
});

$(document).on('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAllBindingMenus();
    }
});

$(document).on('click', '#metis-theme-menu-live .metis-shell-menu-item.has-children > .metis-shell-menu-link, #metis-theme-menu-live .metis-shell-menu-item.has-children > .metis-shell-menu-btn', function(e) {
    e.preventDefault();
    var $item = $(this).closest('.metis-shell-menu-item.has-children');
    if (!$item.length) return;
    var willOpen = !$item.hasClass('is-open');
    $('#metis-theme-menu-live .metis-shell-menu-item.has-children').removeClass('is-open');
    if (willOpen) $item.addClass('is-open');
});

$(document).on('click', '#metis-theme-menu-live .metis-shell-menu-link, #metis-theme-menu-live .metis-shell-menu-btn', function(e) {
    e.preventDefault();
    var $target = $(this);
    if ($target.closest('.metis-shell-menu-item').hasClass('has-children')) return;
    var $live = $('#metis-theme-menu-live');
    $live.find('.metis-shell-menu-link, .metis-shell-menu-btn').removeClass('is-active');
    $live.find('.metis-shell-menu-item').removeClass('is-active');
    $target.addClass('is-active');
    var $item = $target.closest('.metis-shell-menu-item');
    $item.addClass('is-active');
});

$('#metis-theme-custom-font-file').on('change', function() {
    var name = String($('#metis-theme-custom-font-name').val() || '').trim();
    if (!name) {
        themeToast('Enter a custom font name before uploading.', 'error');
        this.value = '';
        return;
    }
    handleDataFile(this, function(file, dataUrl) {
        var ext = String(file.name || '').toLowerCase();
        var format = ext.endsWith('.woff') ? 'woff' : 'woff2';
        ensureObjectPath(state, ['typography', 'custom_fonts'], {});
        var key = name.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
        state.typography.custom_fonts[key] = { name: name, data: dataUrl, format: format };
        markThemeDirtyPath('typography');

        if ($('#metis-theme-body-font option[value="' + name + '"]').length === 0) {
            $('<option>').val(name).text(name).css('font-family', name).appendTo('#metis-theme-body-font');
            $('<option>').val(name).text(name).css('font-family', name).appendTo('#metis-theme-heading-font');
        }

        $('#metis-theme-body-font').val(name);
        $('#metis-theme-heading-font').val(name);
        rebuildStyledSelects();
        renderCustomFontList();
        applyPreview();
        themeToast('Custom font added to theme.', 'success');
    });
});

$('#metis-theme-reset-btn').on('click', function() {
    themeConfirm('Reset this theme form to default values?', function() {
        state = {
            colors: deepClone(defaults.colors),
            typography: deepClone(defaults.typography),
            spacing: deepClone(defaults.spacing),
            global_styles: deepClone(defaults.global_styles),
            custom_tokens: deepClone(defaults.custom_tokens)
        };
        applyBrandingColorBindings(state);
        syncFormFromState();
        themeForceFullSave = true;
        themeDirtyPaths = {};
        themeToast('Theme form reset to defaults.', 'success');
    });
});

$('#metis-theme-save-btn').on('click', function() {
    state = collectThemeData();

    var id = $('#metis-theme-id').val();
    var ajaxCfg = themeAjaxConfig('metis_website_theme_save');
    var dirtyPaths = Object.keys(themeDirtyPaths);
    var saveMode = (!id || themeForceFullSave || dirtyPaths.length === 0) ? 'full' : 'patch';
    var payload = {
        action: 'metis_website_theme_save',
        nonce: ajaxCfg.nonce,
        theme_save_mode: saveMode
    };
    if (saveMode === 'patch') {
        payload.theme_patch_json = JSON.stringify(state);
        payload.theme_patch_paths = dirtyPaths.join(',');
    } else {
        payload.color_palette_json = JSON.stringify(state.colors);
        payload.typography_json = JSON.stringify(state.typography);
        payload.spacing_json = JSON.stringify(state.spacing);
        payload.global_styles_json = JSON.stringify(state.global_styles);
        payload.custom_tokens_json = JSON.stringify(state.custom_tokens);
    }
    if (id) payload.id = id;

    var $btn = $('#metis-theme-save-btn');
    $btn.prop('disabled', true).text('Saving…');

    $.ajax({
        url: ajaxCfg.ajax_url,
        type: 'POST',
        data: payload,
        success: function(resp) {
            $btn.prop('disabled', false).text('Save & Activate');
            if (resp && resp.success) {
                if (resp.data && resp.data.theme) {
                    if (resp.data.theme.id) {
                        $('#metis-theme-id').val(String(resp.data.theme.id));
                    }
                    state = normalizeThemeForEditor(resp.data.theme);
                    syncFormFromState();
                }
                clearThemeSaveDirtyState();
                saveVersionSnapshot({
                    label: 'Saved ' + ((window.Metis && Metis.time && typeof Metis.time.format === 'function') ? Metis.time.format(new Date(), { empty: '' }) : new Date().toLocaleString()),
                    data: deepClone(state)
                });
                themeToast('Theme saved and activated.', 'success');
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to save theme.';
                themeToast(msg, 'error');
            }
        },
        error: function() {
            $btn.prop('disabled', false).text('Save & Activate');
            themeToast('Request failed while saving theme.', 'error');
        }
    });
});

$(document).on('click', '.metis-theme-element-jump-btn', function() {
    var key = String($(this).data('scroll-element') || '');
    var target = document.getElementById('metis-theme-element-card-' + key);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

$('#metis-theme-export-btn').on('click', function() {
    state = collectThemeData();
    var payload = {
        exported_at: new Date().toISOString(),
        source: 'metis_theme_export_v1',
        theme: state
    };
    var blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'metis-theme-' + (new Date().toISOString().replace(/[:.]/g, '-')) + '.json';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
});

$('#metis-theme-import-btn').on('click', function() {
    $('#metis-theme-import-file').trigger('click');
});

$('#metis-theme-import-file').on('change', function() {
    var file = this.files && this.files[0] ? this.files[0] : null;
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(evt) {
        try {
            var parsed = JSON.parse(String(evt.target && evt.target.result ? evt.target.result : '{}'));
            var incoming = parsed && parsed.theme ? parsed.theme : parsed;
            if (!incoming || typeof incoming !== 'object') {
                throw new Error('Invalid import format');
            }
            state = normalizeThemeForEditor(incoming);
            applyBrandingColorBindings(state);
            syncFormFromState();
            themeForceFullSave = true;
            themeDirtyPaths = {};
            themeToast('Theme imported into form. Save to activate.', 'success');
        } catch (_e) {
            themeToast('Could not import theme file.', 'error');
        }
    };
    reader.readAsText(file);
    this.value = '';
});

$('#metis-theme-version-apply-btn').on('click', function() {
    var index = parseInt($('#metis-theme-version-select').val(), 10);
    if (!Number.isFinite(index) || index < 0) {
        themeToast('Select a snapshot first.', 'warning');
        return;
    }
    var list = getVersionHistory();
    var item = list[index];
    if (!item || !item.data || typeof item.data !== 'object') {
        themeToast('Snapshot is invalid.', 'error');
        return;
    }
    state = normalizeThemeForEditor(item.data);
    applyBrandingColorBindings(state);
    syncFormFromState();
    themeForceFullSave = true;
    themeDirtyPaths = {};
    themeToast('Snapshot applied. Save to activate.', 'success');
});

initNav();
buildColorGrid();
buildElementGrid();
rebuildStyledSelects();
renderCustomFontList();
renderVersionOptions();
syncFormFromState();
clearThemeSaveDirtyState();

})();
</script>
