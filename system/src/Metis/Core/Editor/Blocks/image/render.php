<?php
$src = isset($data['src']) ? (string) $data['src'] : '';
$alt = isset($data['alt']) ? (string) $data['alt'] : '';
if ($src !== '') {
    echo '<figure class="metis-block-image"><img src="' . metis_esc_attr($src) . '" alt="' . metis_esc_attr($alt) . '"></figure>';
}
