<?php
if (!defined('METIS_ROOT')) exit;

function metis_newsletter_allowed_block_types(): array {
    return ['text', 'feature_grid', 'cta', 'header', 'footer', 'social', 'unsubscribe', 'hero', 'video', 'columns', 'image', 'button', 'heading', 'spacer'];
}

function metis_newsletter_clean_key(string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '';
    return (string) $value;
}

function metis_newsletter_clean_text(string $value): string {
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim((string) $value);
}

function metis_newsletter_clean_hex(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1) {
        return $value;
    }
    return '';
}

function metis_newsletter_clean_html(string $value): string {
    $value = str_replace("\0", '', $value);
    $value = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $value);
    $value = preg_replace('/\x{00A0}/u', ' ', $value) ?? $value;
    $value = trim($value);
    if ($value !== '' && stripos($value, '<img') !== false) {
        $value = metis_newsletter_normalize_html_media_sources($value);
    }
    return $value;
}

function metis_newsletter_clean_url(string $value, string $fallback = '#'): string {
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }
    $resolved = metis_newsletter_resolve_media_reference_url($value);
    if ($resolved !== '') {
        return $resolved;
    }
    if (preg_match('#^(https?://|/|mailto:|tel:|data:image/)#i', $value) === 1) {
        return $value;
    }
    return $fallback;
}

function metis_newsletter_resolve_media_reference_url(string $value): string {
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($value === '') {
        return '';
    }

    if (preg_match('#^(https?://|/|mailto:|tel:|data:image/)#i', $value) === 1) {
        return $value;
    }

    $tokenMatch = function_exists( 'metis_media_find_by_token' ) ? metis_media_find_by_token( $value ) : null;
    if (is_array($tokenMatch)) {
        return metis_home_url('/media/' . $value);
    }

    $path = (string) (parse_url($value, PHP_URL_PATH) ?? '');
    $basename = basename($path !== '' ? $path : $value);
    $filenameCandidates = array_values(array_unique(array_filter([
        metis_filename_clean($value),
        metis_filename_clean(rawurldecode($value)),
        metis_filename_clean($basename),
        metis_filename_clean(rawurldecode($basename)),
    ])));

    foreach ($filenameCandidates as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $media = function_exists( 'metis_media_find_by_filename' ) ? metis_media_find_by_filename( $candidate ) : null;
        if (is_array($media) && trim((string) ($media['url'] ?? '')) !== '') {
            return (string) $media['url'];
        }
    }

    return '';
}

function metis_newsletter_normalize_html_media_sources(string $html): string {
    $html = trim($html);
    if ($html === '' || stripos($html, '<img') === false || !class_exists('DOMDocument')) {
        return $html;
    }

    $internalErrors = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $wrapper = '<!DOCTYPE html><html><body><metis-root>' . $html . '</metis-root></body></html>';
    $loaded = $doc->loadHTML($wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);
    if ($loaded === false) {
        return $html;
    }

    $roots = $doc->getElementsByTagName('metis-root');
    $root = $roots->length > 0 ? $roots->item(0) : null;
    if (!$root) {
        return $html;
    }

    foreach ($root->getElementsByTagName('img') as $img) {
        $src = trim((string) $img->getAttribute('src'));
        if ($src === '') {
            continue;
        }
        $resolved = metis_newsletter_resolve_media_reference_url($src);
        if ($resolved !== '') {
            $img->setAttribute('src', $resolved);
        }
    }

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }

    return trim($output);
}


function metis_newsletter_escape_html(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function metis_newsletter_escape_attr(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function metis_newsletter_escape_url(string $value): string {
    return metis_newsletter_escape_attr(metis_newsletter_clean_url($value, '#'));
}

function metis_newsletter_plain_text_fallback(string $html): string {
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim((string) $text);
}

function metis_newsletter_json_encode($value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}

function metis_newsletter_uuid(): string {
    try {
        return bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return 'nl_' . substr(sha1(uniqid('nl_', true)), 0, 16);
    }
}

function metis_newsletter_normalize_block_type(string $type): string {
    $type = metis_newsletter_clean_key($type);
    return in_array($type, metis_newsletter_allowed_block_types(), true) ? $type : 'text';
}

function metis_newsletter_theme_colors(): array {
    static $colors = null;
    if (is_array($colors)) {
        return $colors;
    }
    $colors = [];
    if (function_exists('metis_website_active_theme_colors')) {
        try {
            $colors = metis_website_active_theme_colors();
        } catch (Throwable $e) {
            $colors = [];
        }
    }

    if ($colors !== []) {
        return $colors;
    }

    if (function_exists('metis_db') && class_exists('Metis_Tables')) {
        try {
            $db = metis_db();
            $table = \Metis_Tables::get('website_theme_config');
            $row = $db->fetchOne(
                "SELECT color_palette_json, custom_tokens_json
                 FROM {$table}
                 WHERE is_active = 1
                 ORDER BY id DESC
                 LIMIT 1"
            );
            if (is_array($row) && !empty($row)) {
                $palette = json_decode((string) ($row['color_palette_json'] ?? '{}'), true);
                if (is_array($palette)) {
                    foreach ($palette as $key => $value) {
                        $name = metis_newsletter_clean_key((string) $key);
                        $hex = metis_newsletter_clean_hex((string) $value);
                        if ($name !== '' && $hex !== '') {
                            $colors[$name] = $hex;
                        }
                    }
                }

                $custom = json_decode((string) ($row['custom_tokens_json'] ?? '{}'), true);
                $tokens = is_array($custom['tokens'] ?? null) ? $custom['tokens'] : [];
                foreach ($tokens as $token) {
                    if (!is_array($token)) {
                        continue;
                    }
                    $name = metis_newsletter_clean_key((string) ($token['name'] ?? $token['key'] ?? ''));
                    $hex = metis_newsletter_clean_hex((string) ($token['value'] ?? ''));
                    if ($name !== '' && $hex !== '') {
                        $colors[$name] = $hex;
                    }
                }
            }
        } catch (Throwable $e) {
            // Keep graceful fallback behavior for email compilation.
        }
    }
    return $colors;
}

function metis_newsletter_theme_default_settings(): array {
    return [
        'font_size' => (int) Core_Settings_Service::get('newsletter_theme_font_size', 16),
        'text_color' => (string) Core_Settings_Service::get('newsletter_theme_text_color', 'text'),
        'canvas_bg' => (string) Core_Settings_Service::get('newsletter_theme_canvas_bg', 'background'),
        'content_width_mode' => (string) Core_Settings_Service::get('newsletter_theme_content_width_mode', 'normal'),
        'content_width' => max(520, min(820, (int) Core_Settings_Service::get('newsletter_theme_content_width', 680))),
        'divider_color' => (string) Core_Settings_Service::get('newsletter_theme_divider_color', 'border'),
        'divider_style' => (string) Core_Settings_Service::get('newsletter_theme_divider_style', 'solid'),
        'divider_weight' => max(1, min(6, (int) Core_Settings_Service::get('newsletter_theme_divider_weight', 1))),
        'header_html' => (string) Core_Settings_Service::get('newsletter_theme_header_html', ''),
        'personalized_html' => (string) Core_Settings_Service::get('newsletter_theme_personalized_html', ''),
        'body_html' => '',
        'closing_html' => (string) Core_Settings_Service::get('newsletter_theme_closing_html', ''),
        'footer_html' => (string) Core_Settings_Service::get('newsletter_theme_footer_html', ''),
        'header_bg' => (string) Core_Settings_Service::get('newsletter_theme_header_bg', 'transparent'),
        'header_text_color' => (string) Core_Settings_Service::get('newsletter_theme_header_text_color', 'text'),
        'header_padding' => (string) Core_Settings_Service::get('newsletter_theme_header_padding', '24px 28px 12px 28px'),
        'personalized_bg' => (string) Core_Settings_Service::get('newsletter_theme_personalized_bg', 'transparent'),
        'personalized_text_color' => (string) Core_Settings_Service::get('newsletter_theme_personalized_text_color', 'text'),
        'personalized_padding' => (string) Core_Settings_Service::get('newsletter_theme_personalized_padding', '0 28px 8px 28px'),
        'closing_bg' => (string) Core_Settings_Service::get('newsletter_theme_closing_bg', 'transparent'),
        'closing_text_color' => (string) Core_Settings_Service::get('newsletter_theme_closing_text_color', 'text'),
        'closing_padding' => (string) Core_Settings_Service::get('newsletter_theme_closing_padding', '12px 28px 8px 28px'),
        'footer_bg' => (string) Core_Settings_Service::get('newsletter_theme_footer_bg', 'bg'),
        'footer_text_color' => (string) Core_Settings_Service::get('newsletter_theme_footer_text_color', 'muted'),
        'footer_padding' => (string) Core_Settings_Service::get('newsletter_theme_footer_padding', '16px 28px 28px 28px'),
    ];
}

function metis_newsletter_theme_controlled_setting_keys(): array {
    return [
        'font_size',
        'text_color',
        'canvas_bg',
        'content_width_mode',
        'content_width',
        'divider_color',
        'divider_style',
        'divider_weight',
        'header_html',
        'personalized_html',
        'closing_html',
        'footer_html',
        'header_bg',
        'header_text_color',
        'header_padding',
        'personalized_bg',
        'personalized_text_color',
        'personalized_padding',
        'closing_bg',
        'closing_text_color',
        'closing_padding',
        'footer_bg',
        'footer_text_color',
        'footer_padding',
    ];
}

function metis_newsletter_normalize_campaign_doc($doc, ?string $editor_body_html = null): array {
    $normalized = metis_newsletter_doc_parse($doc);
    $settings = isset($normalized['settings']) && is_array($normalized['settings']) ? $normalized['settings'] : [];
    $defaults = metis_newsletter_theme_default_settings();
    $theme_controlled = array_fill_keys(metis_newsletter_theme_controlled_setting_keys(), true);

    foreach ($defaults as $key => $default_value) {
        $has_value = array_key_exists($key, $settings);
        $current_value = $settings[$key] ?? null;

        if (isset($theme_controlled[$key])) {
            $settings[$key] = $default_value;
            continue;
        }

        if (is_int($default_value)) {
            if (!$has_value || (int) $current_value < 1) {
                $settings[$key] = $default_value;
            }
            continue;
        }

        if (!$has_value || trim((string) $current_value) === '') {
            $settings[$key] = $default_value;
        }
    }

    if ($editor_body_html !== null) {
        $settings['body_html'] = metis_newsletter_clean_html($editor_body_html);
    }

    $normalized['settings'] = $settings;

    $blocks = isset($normalized['blocks']) && is_array($normalized['blocks']) ? $normalized['blocks'] : [];
    if ($blocks === []) {
        $blocks[] = [
            'id' => metis_newsletter_uuid(),
            'type' => 'text',
            'data' => ['body' => (string) ($settings['body_html'] ?? '')],
        ];
    }
    if (isset($blocks[0]) && is_array($blocks[0])) {
        $data = isset($blocks[0]['data']) && is_array($blocks[0]['data']) ? $blocks[0]['data'] : [];
        if ($editor_body_html !== null) {
            $data['body'] = (string) ($settings['body_html'] ?? '');
        } elseif (!array_key_exists('body', $data)) {
            $data['body'] = (string) ($settings['body_html'] ?? '');
        }
        $blocks[0]['data'] = $data;
    }
    $normalized['blocks'] = $blocks;

    return $normalized;
}

function metis_newsletter_normalize_campaign_doc_json($doc, ?string $editor_body_html = null): string {
    return metis_newsletter_json_encode(
        metis_newsletter_normalize_campaign_doc($doc, $editor_body_html)
    );
}

function metis_newsletter_resolve_theme_color(string $value, string $fallback = ''): string {
    $value = trim($value);
    if ($value === '' || $value === 'transparent') {
        return $value === 'transparent' ? 'transparent' : $fallback;
    }
    $hex = metis_newsletter_clean_hex($value);
    if ($hex !== '') {
        return $hex;
    }
    $colors = metis_newsletter_theme_colors();
    $normalized = strtolower($value);
    if (isset($colors[$value])) {
        $resolved = metis_newsletter_clean_hex((string) $colors[$value]);
        if ($resolved !== '') {
            return $resolved;
        }
    }
    if (isset($colors[$normalized])) {
        $resolved = metis_newsletter_clean_hex((string) $colors[$normalized]);
        if ($resolved !== '') {
            return $resolved;
        }
    }

    $aliases = [
        'text' => ['text', 'body_text', 'body', 'foreground'],
        'muted' => ['muted', 'text_muted', 'text-muted'],
        'accent' => ['accent', 'primary', 'link'],
        'background' => ['background', 'bg', 'surface', 'canvas'],
        'bg' => ['bg', 'background', 'surface', 'canvas'],
        'surface' => ['surface', 'background', 'bg', 'canvas'],
        'border' => ['border', 'card_border', 'card-border', 'outline'],
        'card_border' => ['card_border', 'border', 'card-border', 'outline'],
        'card-bg' => ['card_bg', 'card-bg', 'surface', 'background', 'bg'],
        'card_bg' => ['card_bg', 'card-bg', 'surface', 'background', 'bg'],
        'primary' => ['primary', 'accent', 'link'],
        'primary_dark' => ['primary_dark', 'primary-dark', 'accent'],
    ];

    if (isset($aliases[$normalized])) {
        foreach ($aliases[$normalized] as $alias) {
            if (!isset($colors[$alias])) {
                continue;
            }
            $resolved = metis_newsletter_clean_hex((string) $colors[$alias]);
            if ($resolved !== '') {
                return $resolved;
            }
        }
    }
    return $fallback;
}

function metis_newsletter_width_from_mode(string $mode, int $fallback = 680): int {
    $mode = metis_newsletter_clean_key($mode);
    if ($mode === 'narrow') return 560;
    if ($mode === 'wide') return 760;
    if ($mode === 'normal') return 680;
    return max(480, min(900, $fallback));
}

function metis_newsletter_region_style(array $settings, string $key, string $fallback_text = '#1f2937', string $extra = ''): string {
    $bg = metis_newsletter_resolve_theme_color((string) ($settings[$key . '_bg'] ?? 'transparent'), 'transparent');
    $text = metis_newsletter_resolve_theme_color((string) ($settings[$key . '_text_color'] ?? ''), $fallback_text);
    $padding = trim((string) ($settings[$key . '_padding'] ?? '0 0 0 0'));
    if ($padding === '') $padding = '0 0 0 0';
    $style = 'padding:' . $padding . ';';
    if ($bg !== '') $style .= 'background:' . $bg . ';';
    if ($text !== '') $style .= 'color:' . $text . ';';
    if ($extra !== '') $style .= $extra;
    return $style;
}

function metis_newsletter_region_background(array $settings, string $key): string {
    return metis_newsletter_resolve_theme_color((string) ($settings[$key . '_bg'] ?? 'transparent'), 'transparent');
}

function metis_newsletter_render_region_cell(
    array $settings,
    string $key,
    string $html,
    string $fallback_text = '#1f2937',
    string $extra = ''
): string {
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $style = metis_newsletter_region_style($settings, $key, $fallback_text, $extra);
    $bg = metis_newsletter_region_background($settings, $key);
    $bg_attr = $bg !== '' && $bg !== 'transparent' ? ' bgcolor="' . metis_newsletter_escape_attr($bg) . '"' : '';

    return '<tr><td data-metis-newsletter-region="' . metis_newsletter_escape_attr($key) . '"' . $bg_attr . ' style="' . metis_newsletter_escape_attr($style) . '">' . $html . '</td></tr>';
}

function metis_newsletter_divider_style_value(string $value): string {
    $value = metis_newsletter_clean_key($value);
    return in_array($value, ['solid', 'dashed', 'dotted', 'double'], true) ? $value : 'solid';
}

function metis_newsletter_apply_inline_element_styles(string $html, array $settings): string {
    $divider_color = metis_newsletter_resolve_theme_color((string) ($settings['divider_color'] ?? ''), '#dfe6f3');
    $divider_style = metis_newsletter_divider_style_value((string) ($settings['divider_style'] ?? 'solid'));
    $divider_weight = max(1, min(6, (int) ($settings['divider_weight'] ?? 1)));
    $divider_css = 'border:0;border-top:' . $divider_weight . 'px ' . $divider_style . ' ' . $divider_color . ';margin:18px 0;';
    $content_width = metis_newsletter_width_from_mode((string) ($settings['content_width_mode'] ?? ''), (int) ($settings['content_width'] ?? 680));

    if (!class_exists('DOMDocument')) {
        return preg_replace_callback(
            '/<hr\b([^>]*)>/i',
            static function (array $matches) use ($divider_css): string {
                $attrs = (string) ($matches[1] ?? '');
                if (preg_match('/class\s*=\s*([\"\'])(.*?)\1/i', $attrs, $class_match) !== 1) {
                    return '<hr' . $attrs . ' style="' . metis_newsletter_escape_attr($divider_css) . '">';
                }
                $class_list = ' ' . strtolower((string) ($class_match[2] ?? '')) . ' ';
                if (strpos($class_list, ' metis-inline-divider ') === false) {
                    return $matches[0];
                }
                if (preg_match('/style\s*=\s*([\"\'])(.*?)\1/i', $attrs, $style_match) === 1) {
                    $replacement = 'style=' . $style_match[1] . metis_newsletter_escape_attr(trim((string) $style_match[2], ';') . ';' . $divider_css) . $style_match[1];
                    return '<hr' . preg_replace('/style\s*=\s*([\"\'])(.*?)\1/i', $replacement, $attrs, 1) . '>';
                }
                return '<hr' . $attrs . ' style="' . metis_newsletter_escape_attr($divider_css) . '">';
            },
            $html
        ) ?? $html;
    }

    $internal_errors = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $wrapper = '<!DOCTYPE html><html><body><metis-root>' . $html . '</metis-root></body></html>';
    $loaded = $doc->loadHTML($wrapper, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($internal_errors);
    if ($loaded === false) {
        return $html;
    }

    $xpath = new DOMXPath($doc);

    foreach ($xpath->query('//hr') ?: [] as $node) {
        if (!($node instanceof DOMElement)) {
            continue;
        }
        $class_list = ' ' . strtolower((string) $node->getAttribute('class')) . ' ';
        if (strpos($class_list, ' metis-inline-divider ') === false) {
            continue;
        }
        $existing_style = trim((string) $node->getAttribute('style'), ';');
        $node->setAttribute('style', trim($existing_style . ';' . $divider_css, ';'));
    }

    foreach ($xpath->query('//img') ?: [] as $node) {
        if (!($node instanceof DOMElement)) {
            continue;
        }

        $figure = $node->parentNode instanceof DOMElement ? $node->parentNode : null;
        $figure_classes = $figure ? ' ' . strtolower((string) $figure->getAttribute('class')) . ' ' : '';
        $max_width = $content_width;
        $align = 'center';

        if (strpos($figure_classes, ' metis-inline-image ') !== false) {
            $align_attr = metis_newsletter_clean_key((string) $figure->getAttribute('data-align'));
            if (in_array($align_attr, ['left', 'center', 'right'], true)) {
                $align = $align_attr;
            }
            $size_attr = metis_newsletter_clean_key((string) $figure->getAttribute('data-size'));
            if ($size_attr === 'small') {
                $max_width = min(280, $content_width);
            } elseif ($size_attr === 'medium') {
                $max_width = min(520, $content_width);
            } elseif ($size_attr === 'large') {
                $max_width = min(720, $content_width);
            } elseif ($size_attr === 'full') {
                $max_width = $content_width;
            }
            if (strpos($figure_classes, ' is-small ') !== false) {
                $max_width = min(280, $content_width);
            } elseif (strpos($figure_classes, ' is-medium ') !== false) {
                $max_width = min(520, $content_width);
            } elseif (strpos($figure_classes, ' is-large ') !== false) {
                $max_width = min(720, $content_width);
            } elseif (strpos($figure_classes, ' is-full ') !== false) {
                $max_width = $content_width;
            }
        }

        $existing_style = trim((string) $node->getAttribute('style'), ';');
        if ($max_width >= $content_width) {
            $image_style = 'display:block;border:0;outline:none;text-decoration:none;height:auto;max-width:' . $max_width . 'px;width:100%;';
        } else {
            $image_style = 'display:inline-block;border:0;outline:none;text-decoration:none;height:auto;width:' . $max_width . 'px;max-width:100%;';
        }
        $node->setAttribute('style', trim($existing_style . ';' . $image_style, ';'));
        $node->setAttribute('width', (string) $max_width);

        if ($figure && strpos($figure_classes, ' metis-inline-image ') !== false) {
            $figure_style = trim((string) $figure->getAttribute('style'), ';');
            if ($align === 'right') {
                $figure_margin = 'margin:18px 0 18px auto;text-align:right;';
            } elseif ($align === 'left') {
                $figure_margin = 'margin:18px auto 18px 0;text-align:left;';
            } else {
                $figure_margin = 'margin:18px auto;text-align:center;';
            }
            $figure->setAttribute('style', trim($figure_style . ';' . $figure_margin, ';'));
        }
    }

    $root_nodes = $xpath->query('//metis-root');
    if (!$root_nodes || $root_nodes->length < 1) {
        return $html;
    }

    $root = $root_nodes->item(0);
    if (!$root) {
        return $html;
    }

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }

    return trim($output);
}

function metis_newsletter_doc_parse($input): array {
    $doc = [];
    if (is_array($input)) {
        $doc = $input;
    } elseif (is_string($input) && trim($input) !== '') {
        $decoded = json_decode($input, true);
        if (is_array($decoded)) {
            $doc = $decoded;
        }
    }

    $blocks = isset($doc['blocks']) && is_array($doc['blocks']) ? $doc['blocks'] : [];
    $normalized_blocks = [];

    foreach ($blocks as $block) {
        if (!is_array($block)) continue;
        $type = metis_newsletter_normalize_block_type((string) ($block['type'] ?? 'text'));
        $normalized_blocks[] = [
            'id' => metis_newsletter_clean_text((string) ($block['id'] ?? metis_newsletter_uuid())),
            'type' => $type,
            'data' => metis_newsletter_doc_sanitize_block_data($type, (array) ($block['data'] ?? [])),
        ];
    }

    if (empty($normalized_blocks)) {
        $normalized_blocks[] = [
            'id' => metis_newsletter_uuid(),
            'type' => 'text',
            'data' => ['html' => '<p>Add your content here.</p>'],
        ];
    }

    $settings = isset($doc['settings']) && is_array($doc['settings']) ? $doc['settings'] : [];
    $font_size = max(10, min(28, (int) ($settings['font_size'] ?? 16)));
    $text_color = metis_newsletter_resolve_theme_color((string) ($settings['text_color'] ?? ''), '#1f2937');
    $canvas_bg = metis_newsletter_resolve_theme_color((string) ($settings['canvas_bg'] ?? ''), '#ffffff');
    $block_bg_default = metis_newsletter_resolve_theme_color((string) ($settings['block_bg_default'] ?? ''), '');
    $content_width = metis_newsletter_width_from_mode((string) ($settings['content_width_mode'] ?? ''), (int) ($settings['content_width'] ?? 680));

    return [
        'version' => 1,
        'settings' => [
            'font_size' => $font_size,
            'text_color' => $text_color,
            'canvas_bg' => $canvas_bg,
            'block_bg_default' => $block_bg_default,
            'content_width_mode' => metis_newsletter_clean_key((string) ($settings['content_width_mode'] ?? 'normal')),
            'content_width' => $content_width,
            'divider_color' => metis_newsletter_clean_text((string) ($settings['divider_color'] ?? 'border')),
            'divider_style' => metis_newsletter_divider_style_value((string) ($settings['divider_style'] ?? 'solid')),
            'divider_weight' => max(1, min(6, (int) ($settings['divider_weight'] ?? 1))),
            'header_html' => metis_newsletter_clean_html((string) ($settings['header_html'] ?? '')),
            'personalized_html' => metis_newsletter_clean_html((string) ($settings['personalized_html'] ?? '')),
            'body_html' => metis_newsletter_clean_html((string) ($settings['body_html'] ?? '')),
            'closing_html' => metis_newsletter_clean_html((string) ($settings['closing_html'] ?? '')),
            'footer_html' => metis_newsletter_clean_html((string) ($settings['footer_html'] ?? '')),
            'header_bg' => metis_newsletter_clean_text((string) ($settings['header_bg'] ?? 'transparent')),
            'header_text_color' => metis_newsletter_clean_text((string) ($settings['header_text_color'] ?? 'text')),
            'header_padding' => metis_newsletter_clean_text((string) ($settings['header_padding'] ?? '24px 28px 12px 28px')),
            'personalized_bg' => metis_newsletter_clean_text((string) ($settings['personalized_bg'] ?? 'transparent')),
            'personalized_text_color' => metis_newsletter_clean_text((string) ($settings['personalized_text_color'] ?? 'text')),
            'personalized_padding' => metis_newsletter_clean_text((string) ($settings['personalized_padding'] ?? '0 28px 8px 28px')),
            'closing_bg' => metis_newsletter_clean_text((string) ($settings['closing_bg'] ?? 'transparent')),
            'closing_text_color' => metis_newsletter_clean_text((string) ($settings['closing_text_color'] ?? 'text')),
            'closing_padding' => metis_newsletter_clean_text((string) ($settings['closing_padding'] ?? '12px 28px 8px 28px')),
            'footer_bg' => metis_newsletter_clean_text((string) ($settings['footer_bg'] ?? 'transparent')),
            'footer_text_color' => metis_newsletter_clean_text((string) ($settings['footer_text_color'] ?? 'muted')),
            'footer_padding' => metis_newsletter_clean_text((string) ($settings['footer_padding'] ?? '16px 28px 28px 28px')),
        ],
        'blocks' => $normalized_blocks,
    ];
}

function metis_newsletter_doc_sanitize_common(array $data): array {
    return [
        'bg_color' => '',
        'min_height' => 0,
        'margin_top' => 0,
        'margin_bottom' => 0,
        'width_pct' => 100,
        'block_align' => 'left',
    ];
}

function metis_newsletter_doc_sanitize_block_data(string $type, array $data): array {
    $type = metis_newsletter_normalize_block_type($type);
    $common = metis_newsletter_doc_sanitize_common($data);
    switch ($type) {
        case 'feature_grid':
            $items = [];
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    if (!is_array($item)) continue;
                    $items[] = [
                        'icon' => metis_newsletter_clean_text((string) ($item['icon'] ?? '')),
                        'title' => metis_newsletter_clean_text((string) ($item['title'] ?? '')),
                        'text' => metis_newsletter_clean_text((string) ($item['text'] ?? '')),
                        'cta' => [
                            'label' => metis_newsletter_clean_text((string) (($item['cta']['label'] ?? $item['cta_label'] ?? '') ?: '')),
                            'url' => metis_newsletter_clean_url((string) (($item['cta']['url'] ?? $item['cta_url'] ?? '') ?: '#')),
                        ],
                    ];
                }
            }
            if ($items === []) {
                $items[] = [
                    'icon' => '',
                    'title' => 'Feature',
                    'text' => 'Feature description.',
                    'cta' => [ 'label' => 'Learn more', 'url' => '#' ],
                ];
            }
            return array_merge($common, [
                'header' => metis_newsletter_clean_text((string) ($data['header'] ?? '')),
                'subtext' => metis_newsletter_clean_text((string) ($data['subtext'] ?? '')),
                'columns' => max(2, min(4, (int) ($data['columns'] ?? 3))),
                'items' => $items,
            ]);
        case 'cta':
            $layout = (string) ($data['layout'] ?? 'single');
            $layout = $layout === 'split' ? 'split' : 'single';
            $items = [];
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    if (!is_array($item)) continue;
                    $items[] = [
                        'title' => metis_newsletter_clean_text((string) ($item['title'] ?? '')),
                        'text' => metis_newsletter_clean_text((string) ($item['text'] ?? '')),
                        'button' => [
                            'label' => metis_newsletter_clean_text((string) (($item['button']['label'] ?? $item['button_label'] ?? '') ?: '')),
                            'url' => metis_newsletter_clean_url((string) (($item['button']['url'] ?? $item['button_url'] ?? '') ?: '#')),
                        ],
                    ];
                }
            }
            if ($items === []) {
                $items[] = [
                    'title' => 'Call to Action',
                    'text' => 'Explain the next step.',
                    'button' => [ 'label' => 'Get Started', 'url' => '#' ],
                ];
            }
            return array_merge($common, [
                'header' => metis_newsletter_clean_text((string) ($data['header'] ?? '')),
                'subtext' => metis_newsletter_clean_text((string) ($data['subtext'] ?? '')),
                'layout' => $layout,
                'items' => $items,
            ]);
        case 'text':
            return array_merge($common, [
                'header' => metis_newsletter_clean_text((string) ($data['header'] ?? '')),
                'subtext' => metis_newsletter_clean_text((string) ($data['subtext'] ?? '')),
                'body' => metis_newsletter_clean_html((string) (($data['body'] ?? $data['html'] ?? '<p></p>'))),
            ]);
        case 'header':
            return array_merge($common, [
                'logo_src' => metis_newsletter_clean_url((string) ($data['logo_src'] ?? ''), ''),
                'logo_alt' => metis_newsletter_clean_text((string) ($data['logo_alt'] ?? '')),
                'logo_link' => metis_newsletter_clean_url((string) ($data['logo_link'] ?? ''), '#'),
                'bgcolor' => metis_newsletter_clean_hex((string) ($data['bgcolor'] ?? '')),
            ]);
        case 'footer':
            return array_merge($common, [
                'address' => metis_newsletter_clean_text((string) ($data['address'] ?? '')),
                'website' => metis_newsletter_clean_url((string) ($data['website'] ?? ''), ''),
                'html' => metis_newsletter_clean_html((string) ($data['html'] ?? '')),
            ]);
        case 'social':
            return array_merge($common, [
                'facebook' => metis_newsletter_clean_url((string) ($data['facebook'] ?? ''), ''),
                'instagram' => metis_newsletter_clean_url((string) ($data['instagram'] ?? ''), ''),
                'twitter' => metis_newsletter_clean_url((string) (($data['twitter'] ?? $data['x'] ?? '')), ''),
                'youtube' => metis_newsletter_clean_url((string) ($data['youtube'] ?? ''), ''),
                'website' => metis_newsletter_clean_url((string) ($data['website'] ?? ''), ''),
            ]);
        case 'unsubscribe':
            return array_merge($common, [
                'text' => metis_newsletter_clean_text((string) ($data['text'] ?? '')),
                'link_text' => metis_newsletter_clean_text((string) (($data['link_text'] ?? $data['label'] ?? ''))),
                'link_url' => metis_newsletter_clean_url((string) (($data['link_url'] ?? $data['url'] ?? '')), '#'),
            ]);
        case 'hero':
            return array_merge($common, [
                'title' => metis_newsletter_clean_text((string) ($data['title'] ?? '')),
                'content' => metis_newsletter_clean_html((string) (($data['content'] ?? $data['html'] ?? $data['body'] ?? ''))),
                'button_label' => metis_newsletter_clean_text((string) (($data['button_label'] ?? $data['label'] ?? ''))),
                'button_url' => metis_newsletter_clean_url((string) (($data['button_url'] ?? $data['url'] ?? '')), '#'),
                'image_src' => metis_newsletter_clean_url((string) ($data['image_src'] ?? ''), ''),
            ]);
        case 'video':
            return array_merge($common, [
                'url' => metis_newsletter_clean_url((string) ($data['url'] ?? ''), '#'),
                'label' => metis_newsletter_clean_text((string) (($data['label'] ?? $data['title'] ?? 'Watch Video'))),
                'thumb' => metis_newsletter_clean_url((string) ($data['thumb'] ?? ''), ''),
            ]);
        case 'columns':
            $columns = [];
            if (isset($data['columns']) && is_array($data['columns'])) {
                foreach ($data['columns'] as $index => $column) {
                    if (!is_array($column)) continue;
                    $columns[] = [
                        'key' => metis_newsletter_clean_key((string) ($column['key'] ?? ('col_' . ((int) $index + 1)))),
                        'html' => metis_newsletter_clean_html((string) ($column['html'] ?? '')),
                    ];
                }
            }
            if ($columns === []) {
                foreach (['left_html', 'right_html', 'col3_html', 'col4_html'] as $index => $key) {
                    $html = metis_newsletter_clean_html((string) ($data[$key] ?? ''));
                    if ($html === '') continue;
                    $columns[] = [
                        'key' => 'col_' . ((int) $index + 1),
                        'html' => $html,
                    ];
                }
            }
            if ($columns === []) {
                $count = max(2, min(4, (int) ($data['columns_count'] ?? 2)));
                for ($i = 1; $i <= $count; $i++) {
                    $columns[] = [
                        'key' => 'col_' . $i,
                        'html' => '<p></p>',
                    ];
                }
            }
            return array_merge($common, [
                'columns' => $columns,
                'columns_count' => max(2, min(4, count($columns))),
            ]);
        case 'image':
            return array_merge($common, [
                'src' => metis_newsletter_clean_url((string) ($data['src'] ?? ''), ''),
                'alt' => metis_newsletter_clean_text((string) ($data['alt'] ?? '')),
            ]);
        case 'button':
            return array_merge($common, [
                'label' => metis_newsletter_clean_text((string) ($data['label'] ?? '')),
                'url' => metis_newsletter_clean_url((string) ($data['url'] ?? ''), '#'),
            ]);
        case 'heading':
            return array_merge($common, [
                'text' => metis_newsletter_clean_text((string) (($data['text'] ?? $data['title'] ?? ''))),
            ]);
        case 'spacer':
            return array_merge($common, [
                'height' => max(8, min(160, (int) ($data['height'] ?? 24))),
            ]);
        default:
            return array_merge($common, [
                'header' => metis_newsletter_clean_text((string) ($data['header'] ?? '')),
                'subtext' => metis_newsletter_clean_text((string) ($data['subtext'] ?? '')),
                'body' => metis_newsletter_clean_html((string) (($data['body'] ?? $data['html'] ?? '<p></p>'))),
            ]);
    }
}

function metis_newsletter_doc_wrap_block(string $html, array $data): string {
    $margin_top = (int) ($data['margin_top'] ?? 0);
    $margin_bottom = (int) ($data['margin_bottom'] ?? 0);
    $width_pct = max(20, min(100, (int) ($data['width_pct'] ?? 100)));
    $align = (string) ($data['block_align'] ?? 'left');
    if (!in_array($align, ['left', 'center', 'right'], true)) $align = 'left';
    $bg = metis_newsletter_clean_hex((string) ($data['bg_color'] ?? '')) ?: '';
    $min_height = max(0, (int) ($data['min_height'] ?? 0));
    $style = 'margin:' . $margin_top . 'px ' . ($align === 'center' ? 'auto' : ($align === 'right' ? '0 0 0 auto' : '0 auto 0 0')) . ' ' . $margin_bottom . 'px;';
    $style .= 'width:' . $width_pct . '%;';
    if ($bg !== '') $style .= 'background:' . $bg . ';';
    if ($min_height > 0) $style .= 'min-height:' . $min_height . 'px;';
    return '<div style="' . metis_newsletter_escape_attr($style) . '">' . $html . '</div>';
}


function metis_newsletter_doc_compile_legacy_blocks(array $doc): array {
    $html_chunks = [];
    $text_chunks = [];

    foreach ((array) $doc['blocks'] as $block) {
        $type = metis_newsletter_normalize_block_type((string) ($block['type'] ?? 'text'));
        $data = (array) ($block['data'] ?? []);

        switch ($type) {
            case 'feature_grid':
                $header = (string) ($data['header'] ?? '');
                $subtext = (string) ($data['subtext'] ?? '');
                $columns = max(2, min(4, (int) ($data['columns'] ?? 3)));
                $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
                if ($items === []) {
                    $items[] = [ 'icon' => '', 'title' => 'Feature', 'text' => 'Feature description.', 'cta' => [ 'label' => 'Learn more', 'url' => '#' ] ];
                }
                $rows = [];
                $row = [];
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $icon = (string) ($item['icon'] ?? '');
                    $title = (string) ($item['title'] ?? '');
                    $text = (string) ($item['text'] ?? '');
                    $cta = isset($item['cta']) && is_array($item['cta']) ? $item['cta'] : [];
                    $cta_label = (string) ($cta['label'] ?? '');
                    $cta_url = (string) ($cta['url'] ?? '#');
                    $icon_html = '';
                    if ($icon !== '') {
                        if (preg_match('#^https?://#i', $icon) === 1 || strpos($icon, '/') === 0) {
                            $icon_html = '<img src="' . metis_newsletter_escape_url($icon) . '" alt="" width="28" style="display:block;border:0;margin:0 0 8px;">';
                        } else {
                            $icon_html = '<div style="font-size:20px;line-height:1;margin:0 0 8px;">' . metis_newsletter_escape_html($icon) . '</div>';
                        }
                    }
                    $cta_html = $cta_label !== '' ? '<p style="margin:12px 0 0;"><a href="' . metis_newsletter_escape_url($cta_url) . '" style="color:#455BC7;text-decoration:underline;">' . metis_newsletter_escape_html($cta_label) . '</a></p>' : '';
                    $cell = $icon_html
                        . ($title !== '' ? '<h3 style="margin:0 0 6px;font-size:16px;line-height:1.3;">' . metis_newsletter_escape_html($title) . '</h3>' : '')
                        . ($text !== '' ? '<p style="margin:0;color:#4b5563;font-size:14px;line-height:1.6;">' . metis_newsletter_escape_html($text) . '</p>' : '')
                        . $cta_html;
                    $row[] = $cell;
                    if (count($row) === $columns) {
                        $rows[] = $row;
                        $row = [];
                    }
                }
                if ($row !== []) {
                    $rows[] = $row;
                }
                $table_rows = '';
                foreach ($rows as $row_items) {
                    $cells = '';
                    foreach ($row_items as $cell_html) {
                        $cells .= '<td valign="top" style="padding:8px 12px;">' . $cell_html . '</td>';
                    }
                    $table_rows .= '<tr>' . $cells . '</tr>';
                }
                $grid_html = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0;">' . $table_rows . '</table>';
                $header_html = '';
                if ($header !== '') $header_html .= '<h2 style="margin:0 0 8px;">' . metis_newsletter_escape_html($header) . '</h2>';
                if ($subtext !== '') $header_html .= '<p style="margin:0 0 16px;color:#6b7280;">' . metis_newsletter_escape_html($subtext) . '</p>';
                $html_chunks[] = metis_newsletter_doc_wrap_block($header_html . $grid_html, $data);
                $text_chunks[] = trim($header . ' ' . $subtext . ' ' . metis_newsletter_plain_text_fallback($grid_html));
                break;
            case 'cta':
                $header = (string) ($data['header'] ?? '');
                $subtext = (string) ($data['subtext'] ?? '');
                $layout = (string) ($data['layout'] ?? 'single');
                $layout = $layout === 'split' ? 'split' : 'single';
                $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
                if ($items === []) {
                    $items[] = [ 'title' => 'Call to Action', 'text' => 'Explain the next step.', 'button' => [ 'label' => 'Get Started', 'url' => '#' ] ];
                }
                $item = $items[0] ?? [];
                $title = (string) ($item['title'] ?? '');
                $text = (string) ($item['text'] ?? '');
                $button = isset($item['button']) && is_array($item['button']) ? $item['button'] : [];
                $btn_label = (string) ($button['label'] ?? '');
                $btn_url = (string) ($button['url'] ?? '#');
                $cta_button = $btn_label !== ''
                    ? '<a href="' . metis_newsletter_escape_url($btn_url) . '" style="display:inline-block;padding:10px 16px;background:#455BC7;color:#ffffff;text-decoration:none;border-radius:6px;">' . metis_newsletter_escape_html($btn_label) . '</a>'
                    : '';
                $cta_body = '';
                if ($header !== '') $cta_body .= '<h2 style="margin:0 0 8px;">' . metis_newsletter_escape_html($header) . '</h2>';
                if ($subtext !== '') $cta_body .= '<p style="margin:0 0 12px;color:#6b7280;">' . metis_newsletter_escape_html($subtext) . '</p>';
                if ($title !== '') $cta_body .= '<h3 style="margin:0 0 6px;font-size:18px;line-height:1.3;">' . metis_newsletter_escape_html($title) . '</h3>';
                if ($text !== '') $cta_body .= '<p style="margin:0 0 12px;color:#4b5563;line-height:1.6;">' . metis_newsletter_escape_html($text) . '</p>';
                if ($layout === 'split') {
                    $cta_html = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%"><tr>' .
                        '<td valign="top" style="padding-right:16px;">' . $cta_body . '</td>' .
                        '<td valign="middle" align="right" style="padding-left:16px;">' . $cta_button . '</td>' .
                        '</tr></table>';
                } else {
                    $cta_html = $cta_body . ($cta_button !== '' ? '<p style="margin:0;">' . $cta_button . '</p>' : '');
                }
                $html_chunks[] = metis_newsletter_doc_wrap_block($cta_html, $data);
                $text_chunks[] = trim($header . ' ' . $subtext . ' ' . $title . ' ' . $text . ' ' . $btn_label . ' ' . $btn_url);
                break;
            case 'text':
            case 'heading':
            case 'button':
            case 'image':
            case 'spacer':
            case 'header':
            case 'footer':
            case 'social':
            case 'unsubscribe':
            case 'hero':
            case 'video':
            case 'columns':
                switch ($type) {
                    case 'heading':
                        $heading = (string) ($data['text'] ?? '');
                        $html = $heading !== '' ? '<h2 style="margin:0 0 8px;">' . metis_newsletter_escape_html($heading) . '</h2>' : '';
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = $heading;
                        break;
                    case 'button':
                        $label = (string) ($data['label'] ?? '');
                        $url = (string) ($data['url'] ?? '#');
                        $html = $label !== '' ? '<p style="margin:0;"><a href="' . metis_newsletter_escape_url($url) . '" style="display:inline-block;padding:10px 16px;background:#455BC7;color:#ffffff;text-decoration:none;border-radius:6px;">' . metis_newsletter_escape_html($label) . '</a></p>' : '';
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = trim($label . ' ' . $url);
                        break;
                    case 'image':
                        $src = (string) ($data['src'] ?? '');
                        $alt = (string) ($data['alt'] ?? '');
                        $html = $src !== '' ? '<img src="' . metis_newsletter_escape_url($src) . '" alt="' . metis_newsletter_escape_attr($alt) . '" style="display:block;border:0;height:auto;max-width:100%;width:100%;">' : '';
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = $alt !== '' ? $alt : $src;
                        break;
                    case 'spacer':
                        $height = max(8, min(160, (int) ($data['height'] ?? 24)));
                        $html = '<div style="line-height:' . $height . 'px;height:' . $height . 'px;">&nbsp;</div>';
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        break;
                    case 'header':
                        $logo_src = (string) ($data['logo_src'] ?? '');
                        $logo_alt = (string) ($data['logo_alt'] ?? '');
                        $logo_link = (string) ($data['logo_link'] ?? '#');
                        $bgcolor = metis_newsletter_clean_hex((string) ($data['bgcolor'] ?? ''));
                        $content = $logo_src !== ''
                            ? '<img src="' . metis_newsletter_escape_url($logo_src) . '" alt="' . metis_newsletter_escape_attr($logo_alt) . '" style="display:block;border:0;max-width:220px;height:auto;">'
                            : '<strong style="font-size:20px;line-height:1.3;">' . metis_newsletter_escape_html($logo_alt !== '' ? $logo_alt : 'Newsletter') . '</strong>';
                        if ($logo_link !== '' && $logo_link !== '#') {
                            $content = '<a href="' . metis_newsletter_escape_url($logo_link) . '" style="color:inherit;text-decoration:none;">' . $content . '</a>';
                        }
                        $style = 'padding:20px 24px;';
                        if ($bgcolor !== '') $style .= 'background:' . $bgcolor . ';';
                        $html = '<div style="' . metis_newsletter_escape_attr($style) . '">' . $content . '</div>';
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = $logo_alt;
                        break;
                    case 'footer':
                        $footer_parts = [];
                        if ((string) ($data['address'] ?? '') !== '') {
                            $footer_parts[] = '<p style="margin:0 0 8px;color:#64748b;">' . metis_newsletter_escape_html((string) $data['address']) . '</p>';
                        }
                        if ((string) ($data['website'] ?? '') !== '') {
                            $footer_parts[] = '<p style="margin:0 0 8px;"><a href="' . metis_newsletter_escape_url((string) $data['website']) . '" style="color:#455BC7;text-decoration:underline;">' . metis_newsletter_escape_html((string) $data['website']) . '</a></p>';
                        }
                        if ((string) ($data['html'] ?? '') !== '') {
                            $footer_parts[] = (string) $data['html'];
                        }
                        $html = implode('', $footer_parts);
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = metis_newsletter_plain_text_fallback($html);
                        break;
                    case 'social':
                        $links = [];
                        foreach ([
                            'facebook' => 'Facebook',
                            'instagram' => 'Instagram',
                            'twitter' => 'X',
                            'youtube' => 'YouTube',
                            'website' => 'Website',
                        ] as $key => $label) {
                            $url = trim((string) ($data[$key] ?? ''));
                            if ($url === '') continue;
                            $links[] = '<a href="' . metis_newsletter_escape_url($url) . '" style="color:#455BC7;text-decoration:underline;margin-right:12px;">' . metis_newsletter_escape_html($label) . '</a>';
                        }
                        $html = $links === [] ? '' : '<p style="margin:0;">' . implode('', $links) . '</p>';
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = metis_newsletter_plain_text_fallback($html);
                        break;
                    case 'unsubscribe':
                        $text = (string) ($data['text'] ?? '');
                        $label = (string) ($data['link_text'] ?? 'Unsubscribe');
                        $url = (string) ($data['link_url'] ?? '#');
                        $html = '<p style="margin:0;color:#64748b;">'
                            . ($text !== '' ? metis_newsletter_escape_html($text) . ' ' : '')
                            . '<a href="' . metis_newsletter_escape_url($url) . '" style="color:#455BC7;text-decoration:underline;">' . metis_newsletter_escape_html($label) . '</a>'
                            . '</p>';
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = trim($text . ' ' . $label . ' ' . $url);
                        break;
                    case 'hero':
                        $title = (string) ($data['title'] ?? '');
                        $content = (string) ($data['content'] ?? '');
                        $button_label = (string) ($data['button_label'] ?? '');
                        $button_url = (string) ($data['button_url'] ?? '#');
                        $image_src = (string) ($data['image_src'] ?? '');
                        $html = '';
                        if ($image_src !== '') {
                            $html .= '<p style="margin:0 0 16px;"><img src="' . metis_newsletter_escape_url($image_src) . '" alt="" style="display:block;border:0;height:auto;max-width:100%;width:100%;"></p>';
                        }
                        if ($title !== '') $html .= '<h1 style="margin:0 0 12px;font-size:30px;line-height:1.2;">' . metis_newsletter_escape_html($title) . '</h1>';
                        if ($content !== '') $html .= $content;
                        if ($button_label !== '') {
                            $html .= '<p style="margin:16px 0 0;"><a href="' . metis_newsletter_escape_url($button_url) . '" style="display:inline-block;padding:12px 18px;background:#455BC7;color:#ffffff;text-decoration:none;border-radius:6px;">' . metis_newsletter_escape_html($button_label) . '</a></p>';
                        }
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = trim($title . ' ' . metis_newsletter_plain_text_fallback($content) . ' ' . $button_label . ' ' . $button_url);
                        break;
                    case 'video':
                        $url = (string) ($data['url'] ?? '#');
                        $label = (string) ($data['label'] ?? 'Watch Video');
                        $thumb = (string) ($data['thumb'] ?? '');
                        $html = '';
                        if ($thumb !== '') {
                            $html .= '<p style="margin:0 0 12px;"><a href="' . metis_newsletter_escape_url($url) . '"><img src="' . metis_newsletter_escape_url($thumb) . '" alt="' . metis_newsletter_escape_attr($label) . '" style="display:block;border:0;height:auto;max-width:100%;width:100%;"></a></p>';
                        }
                        $html .= '<p style="margin:0;"><a href="' . metis_newsletter_escape_url($url) . '" style="color:#455BC7;text-decoration:underline;">' . metis_newsletter_escape_html($label) . '</a></p>';
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = trim($label . ' ' . $url);
                        break;
                    case 'columns':
                        $columns = isset($data['columns']) && is_array($data['columns']) ? $data['columns'] : [];
                        $cells = '';
                        foreach ($columns as $column) {
                            if (!is_array($column)) continue;
                            $cells .= '<td valign="top" style="padding:0 12px 0 0;width:' . max(25, (int) floor(100 / max(1, count($columns)))) . '%;">' . (string) ($column['html'] ?? '') . '</td>';
                        }
                        $html = $cells === '' ? '' : '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%"><tr>' . $cells . '</tr></table>';
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = metis_newsletter_plain_text_fallback($html);
                        break;
                    case 'text':
                    default:
                        $header = (string) ($data['header'] ?? '');
                        $subtext = (string) ($data['subtext'] ?? '');
                        $body = (string) ($data['body'] ?? '<p></p>');
                        $html = '';
                        if ($header !== '') $html .= '<h2 style="margin:0 0 8px;">' . metis_newsletter_escape_html($header) . '</h2>';
                        if ($subtext !== '') $html .= '<p style="margin:0 0 12px;color:#6b7280;">' . metis_newsletter_escape_html($subtext) . '</p>';
                        $html .= $body;
                        $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                        $text_chunks[] = metis_newsletter_plain_text_fallback($html);
                        break;
                }
                break;
        }
    }

    return [
        'html' => implode("
", array_filter($html_chunks, static fn($x) => trim((string) $x) !== '')),
        'text' => trim(implode("

", array_filter($text_chunks, static fn($x) => trim((string) $x) !== ''))),
    ];
}

function metis_newsletter_doc_compile($doc): array {
    $doc = metis_newsletter_doc_parse($doc);
    $settings = isset($doc['settings']) && is_array($doc['settings']) ? $doc['settings'] : [];

    $header_html = (string) ($settings['header_html'] ?? '');
    $personalized_html = (string) ($settings['personalized_html'] ?? '');
    $body_html = (string) ($settings['body_html'] ?? '');
    $closing_html = (string) ($settings['closing_html'] ?? '');
    $footer_html = (string) ($settings['footer_html'] ?? '');

    if ($body_html === '') {
        $legacy = metis_newsletter_doc_compile_legacy_blocks($doc);
        $body_html = (string) ($legacy['html'] ?? '');
        $legacy_text = (string) ($legacy['text'] ?? '');
    } else {
        $legacy_text = '';
    }

    $header_html = metis_newsletter_apply_inline_element_styles($header_html, $settings);
    $personalized_html = metis_newsletter_apply_inline_element_styles($personalized_html, $settings);
    $body_html = metis_newsletter_apply_inline_element_styles($body_html, $settings);
    $closing_html = metis_newsletter_apply_inline_element_styles($closing_html, $settings);
    $footer_html = metis_newsletter_apply_inline_element_styles($footer_html, $settings);

    $font_size = max(10, min(28, (int) ($settings['font_size'] ?? 16)));
    $text_color = metis_newsletter_resolve_theme_color((string) ($settings['text_color'] ?? ''), '#1f2937');

    $regions = [];
    $regions[] = metis_newsletter_render_region_cell($settings, 'header', $header_html, '#1f2937');
    $regions[] = metis_newsletter_render_region_cell($settings, 'personalized', $personalized_html, '#1f2937');

    if (trim($body_html) !== '') {
        $regions[] = '<tr><td data-metis-newsletter-region="body" style="padding:12px 28px;font-size:' . $font_size . 'px;color:' . metis_newsletter_escape_attr($text_color) . ';">' . $body_html . '</td></tr>';
    }

    $regions[] = metis_newsletter_render_region_cell($settings, 'closing', $closing_html, '#1f2937');
    $regions[] = metis_newsletter_render_region_cell($settings, 'footer', $footer_html, '#64748b', 'font-size:13px;line-height:1.6;');

    $regions = array_values(array_filter($regions, static fn($html) => trim((string) $html) !== ''));

    $html = implode("
", $regions);
    $text = trim(implode("

", array_filter([
        metis_newsletter_plain_text_fallback($header_html),
        metis_newsletter_plain_text_fallback($personalized_html),
        metis_newsletter_plain_text_fallback($body_html),
        metis_newsletter_plain_text_fallback($closing_html),
        metis_newsletter_plain_text_fallback($footer_html),
        $legacy_text,
    ], static fn($x) => trim((string) $x) !== '')));
    if ($html === '') $html = '<p>&nbsp;</p>';
    if ($text === '') $text = metis_newsletter_plain_text_fallback($html);

    $canvas_bg = metis_newsletter_resolve_theme_color((string) ($settings['canvas_bg'] ?? ''), '#ffffff');
    $wrapper_style = 'background:' . metis_newsletter_escape_attr($canvas_bg) . ';';
    $wrapper_style .= 'color:' . metis_newsletter_escape_attr($text_color) . ';';
    $content_width = metis_newsletter_width_from_mode((string) ($settings['content_width_mode'] ?? ''), (int) ($settings['content_width'] ?? 680));
    $canvas_bg_attr = $canvas_bg !== '' && $canvas_bg !== 'transparent' ? ' bgcolor="' . metis_newsletter_escape_attr($canvas_bg) . '"' : '';
    $html_wrapped = '<table role="presentation" data-metis-newsletter-shell="1" cellspacing="0" cellpadding="0" border="0" width="100%"' . $canvas_bg_attr . ' style="width:100%;background:' . metis_newsletter_escape_attr($canvas_bg) . ';"><tr><td align="center"' . $canvas_bg_attr . ' style="padding:0;background:' . metis_newsletter_escape_attr($canvas_bg) . ';">'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="' . $content_width . '" style="width:' . $content_width . 'px;min-width:' . $content_width . 'px;max-width:' . $content_width . 'px;margin:0 auto;' . $wrapper_style . '">'
        . $html
        . '</table>'
        . '</td></tr></table>';

return [
        'doc' => $doc,
        'doc_json' => metis_newsletter_json_encode($doc),
        'html' => $html_wrapped,
        'text' => $text,
    ];
}
