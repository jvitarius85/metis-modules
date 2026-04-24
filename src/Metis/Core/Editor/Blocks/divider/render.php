<?php
$label = isset($data['label']) ? trim((string) $data['label']) : '';
echo '<hr class="metis-block-divider">';
if ($label !== '') {
    echo '<p class="metis-block-divider-label">' . metis_esc_html($label) . '</p>';
}
