<?php
$columns = isset($data['columns']) ? (int) $data['columns'] : 2;
if ($columns < 1) { $columns = 1; }
if ($columns > 4) { $columns = 4; }
$gap = isset($data['gap']) ? (string) $data['gap'] : '24px';
echo '<div class="metis-block-grid" style="display:grid;grid-template-columns:repeat(' . metis_esc_attr((string)$columns) . ',minmax(0,1fr));gap:' . metis_esc_attr($gap) . ';"></div>';
