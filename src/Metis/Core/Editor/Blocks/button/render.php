<?php
$label = isset($data['label']) ? (string) $data['label'] : 'Learn more';
$url = isset($data['url']) ? (string) $data['url'] : '#';
echo '<p class="metis-block-button"><a class="metis-btn" href="' . metis_esc_attr($url) . '">' . metis_esc_html($label) . '</a></p>';
