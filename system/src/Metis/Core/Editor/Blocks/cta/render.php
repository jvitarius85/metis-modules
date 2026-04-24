<?php
$title = isset($data['title']) ? (string) $data['title'] : 'Call to Action';
$content = isset($data['content']) ? (string) $data['content'] : '';
$buttonLabel = isset($data['button_label']) ? (string) $data['button_label'] : 'Get Started';
$buttonUrl = isset($data['button_url']) ? (string) $data['button_url'] : '#';
echo '<section class="metis-block-cta"><h3>' . metis_esc_html($title) . '</h3>';
if ($content !== '') { echo '<p>' . metis_esc_html($content) . '</p>'; }
echo '<p><a class="metis-btn" href="' . metis_esc_attr($buttonUrl) . '">' . metis_esc_html($buttonLabel) . '</a></p></section>';
