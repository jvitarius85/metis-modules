<?php
$title = isset($data['title']) ? (string) $data['title'] : 'Hero Title';
$subtitle = isset($data['subtitle']) ? (string) $data['subtitle'] : '';
$ctaLabel = isset($data['cta_label']) ? (string) $data['cta_label'] : 'Learn More';
$ctaUrl = isset($data['cta_url']) ? (string) $data['cta_url'] : '#';
echo '<section class="metis-block-hero"><h1>' . metis_esc_html($title) . '</h1>';
if ($subtitle !== '') { echo '<p>' . metis_esc_html($subtitle) . '</p>'; }
echo '<p><a class="metis-btn" href="' . metis_esc_attr($ctaUrl) . '">' . metis_esc_html($ctaLabel) . '</a></p></section>';
