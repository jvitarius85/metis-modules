<?php
$buttons = isset($data['buttons']) && is_array($data['buttons']) ? $data['buttons'] : [];
echo '<div class="metis-block-button-group">';
foreach ($buttons as $button) {
    if (!is_array($button)) { continue; }
    $label = isset($button['label']) ? (string) $button['label'] : 'Button';
    $url = isset($button['url']) ? (string) $button['url'] : '#';
    echo '<a class="metis-btn metis-btn-item" href="' . metis_esc_attr($url) . '">' . metis_esc_html($label) . '</a>';
}
echo '</div>';
