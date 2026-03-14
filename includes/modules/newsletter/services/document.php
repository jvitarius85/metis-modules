<?php
if (!defined('ABSPATH')) exit;

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
        $type = sanitize_key((string) ($block['type'] ?? 'text'));
        if ($type === '') $type = 'text';
        $normalized_blocks[] = [
            'id' => sanitize_text_field((string) ($block['id'] ?? metis_generate_uuid())),
            'type' => $type,
            'data' => metis_newsletter_doc_sanitize_block_data($type, (array) ($block['data'] ?? [])),
        ];
    }

    if (empty($normalized_blocks)) {
        $normalized_blocks[] = [
            'id' => metis_generate_uuid(),
            'type' => 'text',
            'data' => ['html' => '<p>Add your content here.</p>'],
        ];
    }

    $settings = isset($doc['settings']) && is_array($doc['settings']) ? $doc['settings'] : [];
    $font_family = sanitize_text_field((string) ($settings['font_family'] ?? ''));
    $font_size = max(10, min(28, (int) ($settings['font_size'] ?? 16)));
    $text_color = sanitize_hex_color((string) ($settings['text_color'] ?? '')) ?: '#1f2937';
    $canvas_bg = sanitize_hex_color((string) ($settings['canvas_bg'] ?? '')) ?: '#ffffff';
    $block_bg_default = sanitize_hex_color((string) ($settings['block_bg_default'] ?? '')) ?: '';

    return [
        'version' => 1,
        'settings' => [
            'font_family' => $font_family,
            'font_size' => $font_size,
            'text_color' => $text_color,
            'canvas_bg' => $canvas_bg,
            'block_bg_default' => $block_bg_default,
        ],
        'blocks' => $normalized_blocks,
    ];
}

function metis_newsletter_doc_sanitize_common(array $data): array {
    $bg = sanitize_hex_color((string) ($data['bg_color'] ?? '')) ?: '';
    $align = strtolower((string) ($data['block_align'] ?? 'left'));
    if (!in_array($align, ['left', 'center', 'right'], true)) $align = 'left';
    return [
        'bg_color' => $bg,
        'min_height' => max(0, min(2000, (int) ($data['min_height'] ?? 0))),
        'margin_top' => max(0, min(200, (int) ($data['margin_top'] ?? 0))),
        'margin_bottom' => max(0, min(200, (int) ($data['margin_bottom'] ?? 0))),
        'width_pct' => max(20, min(100, (int) ($data['width_pct'] ?? 100))),
        'block_align' => $align,
    ];
}

function metis_newsletter_doc_sanitize_block_data(string $type, array $data): array {
    $common = metis_newsletter_doc_sanitize_common($data);
    switch ($type) {
        case 'header':
        case 'footer':
            return array_merge($common, ['html' => metis_kses_post((string) ($data['html'] ?? '<p></p>'))]);
        case 'hero':
            return array_merge($common, ['html' => metis_kses_post((string) ($data['html'] ?? '<div><h1>Hero</h1></div>'))]);
        case 'heading':
            return array_merge($common, ['text' => sanitize_text_field((string) ($data['text'] ?? 'Heading'))]);
        case 'text':
        case 'html':
            return array_merge($common, ['html' => metis_kses_post((string) ($data['html'] ?? '<p></p>'))]);
        case 'button':
        case 'call_to_action':
            return [
                'label' => sanitize_text_field((string) ($data['label'] ?? 'Call to Action')),
                'url' => esc_url_raw((string) ($data['url'] ?? '#')),
                'align' => in_array((string) ($data['align'] ?? 'left'), ['left', 'center', 'right'], true) ? (string) $data['align'] : 'left',
            ] + $common;
        case 'image':
        case 'klipy':
            return array_merge($common, [
                'src' => esc_url_raw((string) ($data['src'] ?? '')),
                'alt' => sanitize_text_field((string) ($data['alt'] ?? 'Newsletter image')),
                'href' => esc_url_raw((string) ($data['href'] ?? '')),
                'image_width_pct' => max(10, min(100, (int) ($data['image_width_pct'] ?? ($data['width_pct'] ?? 100)))),
                'align' => in_array((string) ($data['align'] ?? 'center'), ['left', 'center', 'right'], true) ? (string) $data['align'] : 'center',
            ]);
        case 'video':
            return array_merge($common, [
                'url' => esc_url_raw((string) ($data['url'] ?? '')),
                'label' => sanitize_text_field((string) ($data['label'] ?? 'Watch video')),
                'thumb' => esc_url_raw((string) ($data['thumb'] ?? '')),
            ]);
        case 'spacer':
            $height = (int) ($data['height'] ?? 16);
            $height = max(8, min(120, $height));
            return array_merge($common, ['height' => $height]);
        case 'columns':
            $columns = [];
            if (isset($data['columns']) && is_array($data['columns'])) {
                foreach ($data['columns'] as $idx => $col) {
                    if (!is_array($col)) continue;
                    $columns[] = [
                        'key' => sanitize_key((string) ($col['key'] ?? ('col_' . ($idx + 1)))),
                        'html' => metis_kses_post((string) ($col['html'] ?? '<p>Column</p>')),
                    ];
                }
            }
            if (count($columns) < 2) {
                $columns = [
                    ['key' => 'col_1', 'html' => metis_kses_post((string) ($data['left_html'] ?? '<p>Column 1</p>'))],
                    ['key' => 'col_2', 'html' => metis_kses_post((string) ($data['right_html'] ?? '<p>Column 2</p>'))],
                ];
            }
            if (count($columns) > 3) {
                $columns = array_slice($columns, 0, 3);
            }
            return array_merge($common, [
                'columns_count' => count($columns) === 3 ? 3 : 2,
                'columns' => $columns,
                'left_html' => (string) ($columns[0]['html'] ?? '<p>Column 1</p>'),
                'right_html' => (string) ($columns[1]['html'] ?? '<p>Column 2</p>'),
            ]);
        default:
            return array_merge($common, ['html' => metis_kses_post((string) ($data['html'] ?? '<p></p>'))]);
    }
}

function metis_newsletter_doc_wrap_block(string $html, array $data): string {
    $margin_top = (int) ($data['margin_top'] ?? 0);
    $margin_bottom = (int) ($data['margin_bottom'] ?? 0);
    $width_pct = max(20, min(100, (int) ($data['width_pct'] ?? 100)));
    $align = in_array((string) ($data['block_align'] ?? 'left'), ['left', 'center', 'right'], true) ? (string) $data['block_align'] : 'left';
    $bg = sanitize_hex_color((string) ($data['bg_color'] ?? '')) ?: '';
    $min_height = max(0, (int) ($data['min_height'] ?? 0));
    $style = 'margin:' . $margin_top . 'px ' . ($align === 'center' ? 'auto' : ($align === 'right' ? '0 0 0 auto' : '0 auto 0 0')) . ' ' . $margin_bottom . 'px;';
    $style .= 'width:' . $width_pct . '%;';
    if ($bg !== '') $style .= 'background:' . $bg . ';';
    if ($min_height > 0) $style .= 'min-height:' . $min_height . 'px;';
    return '<div style="' . esc_attr($style) . '">' . $html . '</div>';
}

function metis_newsletter_doc_compile($doc): array {
    $doc = metis_newsletter_doc_parse($doc);
    $html_chunks = [];
    $text_chunks = [];

    foreach ((array) $doc['blocks'] as $block) {
        $type = (string) ($block['type'] ?? 'text');
        $data = (array) ($block['data'] ?? []);

        switch ($type) {
            case 'header':
                $html = (string) ($data['html'] ?? '<p></p>');
                $html_chunks[] = metis_newsletter_doc_wrap_block('<div style="padding:10px 0;border-bottom:1px solid #e8edf7;">' . $html . '</div>', $data);
                $text_chunks[] = metis_newsletter_plain_text_from_html($html);
                break;
            case 'footer':
                $html = (string) ($data['html'] ?? '<p></p>');
                $html_chunks[] = metis_newsletter_doc_wrap_block('<div style="padding-top:12px;border-top:1px solid #e8edf7;color:#6b7280;font-size:12px;line-height:1.5;">' . $html . '</div>', $data);
                $text_chunks[] = metis_newsletter_plain_text_from_html($html);
                break;
            case 'hero':
                $html = (string) ($data['html'] ?? '<div><h1>Hero</h1></div>');
                $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                $text_chunks[] = metis_newsletter_plain_text_from_html($html);
                break;
            case 'heading':
                $text = (string) ($data['text'] ?? 'Heading');
                $html_chunks[] = metis_newsletter_doc_wrap_block('<h2 style="margin:0;">' . esc_html($text) . '</h2>', $data);
                $text_chunks[] = $text;
                break;
            case 'button':
            case 'call_to_action':
                $label = (string) ($data['label'] ?? 'Call to Action');
                $url = (string) ($data['url'] ?? '#');
                $align = (string) ($data['align'] ?? 'left');
                $align_css = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
                $html_chunks[] = metis_newsletter_doc_wrap_block('<p style="text-align:' . esc_attr($align_css) . ';margin:0;"><a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 14px;background:#455BC7;color:#ffffff;text-decoration:none;border-radius:6px;">' . esc_html($label) . '</a></p>', $data);
                $text_chunks[] = $label . ' - ' . $url;
                break;
            case 'image':
            case 'klipy':
                $src = (string) ($data['src'] ?? '');
                $alt = (string) ($data['alt'] ?? 'Newsletter image');
                $href = (string) ($data['href'] ?? '');
                $width_pct = max(10, min(100, (int) ($data['image_width_pct'] ?? ($data['width_pct'] ?? 100))));
                $align = in_array((string) ($data['align'] ?? 'center'), ['left', 'center', 'right'], true) ? (string) $data['align'] : 'center';
                if ($src !== '') {
                    $img = '<img src="' . esc_url($src) . '" alt="' . esc_attr($alt) . '" style="display:block;width:' . (int) $width_pct . '%;max-width:100%;height:auto;border:0;' . ($align === 'center' ? 'margin:0 auto;' : ($align === 'right' ? 'margin:0 0 0 auto;' : 'margin:0 auto 0 0;')) . '">';
                    $inner = $href !== '' ? '<p style="text-align:' . esc_attr($align) . ';margin:0;"><a href="' . esc_url($href) . '">' . $img . '</a></p>' : '<p style="text-align:' . esc_attr($align) . ';margin:0;">' . $img . '</p>';
                    $html_chunks[] = metis_newsletter_doc_wrap_block($inner, $data);
                    $text_chunks[] = '[Image] ' . $alt;
                }
                break;
            case 'video':
                $url = (string) ($data['url'] ?? '');
                $label = (string) ($data['label'] ?? 'Watch video');
                $thumb = (string) ($data['thumb'] ?? '');
                if ($url !== '') {
                    $video_inner = '';
                    if ($thumb !== '') {
                        $video_inner .= '<p style="margin:0 0 8px;"><a href="' . esc_url($url) . '" target="_blank" rel="noopener"><img src="' . esc_url($thumb) . '" alt="' . esc_attr($label) . '" style="display:block;width:100%;max-width:100%;height:auto;border:0;"></a></p>';
                    }
                    $video_inner .= '<p style="margin:0;"><a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a></p>';
                    $html_chunks[] = metis_newsletter_doc_wrap_block($video_inner, $data);
                    $text_chunks[] = $label . ' - ' . $url;
                }
                break;
            case 'spacer':
                $height = (int) ($data['height'] ?? 16);
                $height = max(8, min(120, $height));
                $html_chunks[] = metis_newsletter_doc_wrap_block('<div style="height:' . (int) $height . 'px;"></div>', $data);
                break;
            case 'columns':
                $columns = [];
                if (isset($data['columns']) && is_array($data['columns'])) {
                    foreach ($data['columns'] as $col) {
                        if (!is_array($col)) continue;
                        $columns[] = (string) ($col['html'] ?? '');
                    }
                }
                if (count($columns) < 2) {
                    $columns = [
                        (string) ($data['left_html'] ?? '<p>Column 1</p>'),
                        (string) ($data['right_html'] ?? '<p>Column 2</p>'),
                    ];
                }
                if (count($columns) > 3) {
                    $columns = array_slice($columns, 0, 3);
                }
                $col_count = count($columns) === 3 ? 3 : 2;
                if ($col_count === 2 && count($columns) > 2) {
                    $columns = array_slice($columns, 0, 2);
                }
                $cell_width = $col_count === 3 ? '33.333%' : '50%';
                $cell_html = '';
                foreach ($columns as $i => $col_html) {
                    $pad = $col_count === 3
                        ? ($i === 0 ? '0 8px 0 0' : ($i === 2 ? '0 0 0 8px' : '0 8px'))
                        : ($i === 0 ? '0 8px 0 0' : '0 0 0 8px');
                    $cell_html .= '<td width="' . esc_attr($cell_width) . '" valign="top" style="padding:' . esc_attr($pad) . ';">' . $col_html . '</td>';
                }
                $html_chunks[] = metis_newsletter_doc_wrap_block('<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0;"><tr>' . $cell_html . '</tr></table>', $data);
                $text_chunks[] = trim(implode("\n", array_map(static fn($x) => metis_newsletter_plain_text_from_html((string) $x), $columns)));
                break;
            case 'html':
            case 'text':
            default:
                $html = (string) ($data['html'] ?? '<p></p>');
                $html_chunks[] = metis_newsletter_doc_wrap_block($html, $data);
                $text_chunks[] = metis_newsletter_plain_text_from_html($html);
                break;
        }
    }

    $html = implode("\n", array_filter($html_chunks, static fn($x) => trim((string) $x) !== ''));
    $text = trim(implode("\n\n", array_filter($text_chunks, static fn($x) => trim((string) $x) !== '')));
    if ($html === '') $html = '<p>&nbsp;</p>';
    if ($text === '') $text = metis_newsletter_plain_text_from_html($html);

    $settings = isset($doc['settings']) && is_array($doc['settings']) ? $doc['settings'] : [];
    $canvas_bg = (string) ($settings['canvas_bg'] ?? '#ffffff');
    $wrapper_style = 'background:' . esc_attr($canvas_bg) . ';';
    $wrapper_style .= 'color:' . esc_attr((string) ($settings['text_color'] ?? '#1f2937')) . ';';
    $font_size = max(10, min(28, (int) ($settings['font_size'] ?? 16)));
    $wrapper_style .= 'font-size:' . $font_size . 'px;';
    $font_family = sanitize_text_field((string) ($settings['font_family'] ?? ''));
    if ($font_family !== '') $wrapper_style .= 'font-family:' . esc_attr($font_family) . ';';
    $html_wrapped = '<table role="presentation" data-metis-newsletter-shell="1" cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%;background:' . esc_attr($canvas_bg) . ';"><tr><td align="center" style="padding:0;">'
        . '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="680" style="width:680px;min-width:680px;max-width:680px;margin:0 auto;' . $wrapper_style . '"><tr><td style="padding:0;">'
        . $html
        . '</td></tr></table>'
        . '</td></tr></table>';

    return [
        'doc' => $doc,
        'doc_json' => metis_json_encode($doc),
        'html' => $html_wrapped,
        'text' => $text,
    ];
}
