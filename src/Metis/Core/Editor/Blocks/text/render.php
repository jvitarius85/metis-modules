<?php
$tag = isset($data['tag']) ? preg_replace('/[^a-z0-9]/i', '', (string) $data['tag']) : 'p';
if ($tag === '') { $tag = 'p'; }
$content = isset($data['content']) ? (string) $data['content'] : '';
echo '<' . metis_esc_attr($tag) . ' class="metis-block-text">' . $content . '</' . metis_esc_attr($tag) . '>';
