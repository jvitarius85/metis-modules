<?php
$src = isset($data['src']) ? (string) $data['src'] : '';
$alt = isset($data['alt']) ? (string) $data['alt'] : '';
$heading = isset($data['heading']) ? (string) $data['heading'] : '';
$content = isset($data['content']) ? (string) $data['content'] : '';
echo '<section class="metis-block-image-content"><div class="metis-block-image-content-media">';
if ($src !== '') { echo '<img src="' . metis_esc_attr($src) . '" alt="' . metis_esc_attr($alt) . '">'; }
echo '</div><div class="metis-block-image-content-copy">';
if ($heading !== '') { echo '<h3>' . metis_esc_html($heading) . '</h3>'; }
if ($content !== '') { echo '<p>' . metis_esc_html($content) . '</p>'; }
echo '</div></section>';
