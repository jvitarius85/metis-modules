<?php
$items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
$ordered = !empty($data['ordered']);
$tag = $ordered ? 'ol' : 'ul';
echo '<' . $tag . ' class="metis-block-list">';
foreach ($items as $item) {
    echo '<li>' . metis_esc_html((string) $item) . '</li>';
}
echo '</' . $tag . '>';
