<?php
$level = isset($data['level']) ? strtolower((string) $data['level']) : 'h2';
if (!preg_match('/^h[1-6]$/', $level)) { $level = 'h2'; }
$content = isset($data['content']) ? (string) $data['content'] : '';
echo '<' . metis_esc_attr($level) . ' class="metis-block-heading">' . $content . '</' . metis_esc_attr($level) . '>';
