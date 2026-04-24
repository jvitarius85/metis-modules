<?php
$height = isset($data['height']) ? (int) $data['height'] : 24;
if ($height < 0) { $height = 0; }
echo '<div class="metis-block-spacer" style="height:' . metis_esc_attr((string) $height) . 'px"></div>';
