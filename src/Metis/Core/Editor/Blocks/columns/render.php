<?php
$columns = isset($data['columns']) && is_array($data['columns']) ? $data['columns'] : [];
echo '<div class="metis-block-columns">';
foreach ($columns as $columnHtml) {
    $html = is_string($columnHtml) ? $columnHtml : '';
    echo '<div class="metis-block-column">' . $html . '</div>';
}
echo '</div>';
