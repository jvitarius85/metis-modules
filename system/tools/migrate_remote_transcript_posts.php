<?php
declare(strict_types=1);

// @metis-governance ajax-security: CLI-only migration tool; no AJAX surface, nonce, csrf, permission, or SecureEnclave execution path.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$cfg = require __DIR__ . '/../config/database.php';
$host = $argv[1] ?? '10.0.4.130';
$apply = in_array('--apply', $argv, true);
$ids = [15, 18];
$backupRows = [];
foreach ($argv as $arg) {
    if (strpos($arg, '--ids=') === 0) {
        $rawIds = explode(',', substr($arg, 6));
        $ids = array_values(array_filter(array_map('intval', $rawIds)));
    } elseif (strpos($arg, '--backup-json=') === 0) {
        $path = substr($arg, 14);
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                foreach ($decoded as $row) {
                    if (is_array($row) && isset($row['id'])) {
                        $backupRows[(int) $row['id']] = $row;
                    }
                }
            }
        }
    }
}
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $cfg['port'], $cfg['database']);
$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$select = $pdo->prepare('SELECT id, slug, content_json, draft_content_json, published_content_json FROM metis_website_posts WHERE id = ?');
$update = $pdo->prepare('UPDATE metis_website_posts SET content_json = ?, draft_content_json = ?, published_content_json = ?, updated_at = NOW() WHERE id = ?');

$summary = ['scanned' => 0, 'candidates' => 0, 'migrated' => 0];
foreach ($ids as $id) {
    $row = $backupRows[$id] ?? null;
    if (!is_array($row)) {
        $select->execute([$id]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) {
        continue;
    }
    $summary['scanned']++;
    $payload = [];
    $changed = false;
    foreach (['content_json', 'draft_content_json', 'published_content_json'] as $field) {
        $raw = (string) ($row[$field] ?? '');
        $next = migrate_layout_json($raw);
        $payload[$field] = $next;
        if ($next !== $raw) {
            $changed = true;
        }
    }
    if (!$changed) {
        continue;
    }
    $summary['candidates']++;
    echo "[{$row['id']}] {$row['slug']}\n";
    if ($apply) {
        $update->execute([$payload['content_json'], $payload['draft_content_json'], $payload['published_content_json'], $id]);
        $summary['migrated']++;
    }
}

echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo 'Scanned: ' . $summary['scanned'] . PHP_EOL;
echo 'Candidates: ' . $summary['candidates'] . PHP_EOL;
echo 'Migrated: ' . $summary['migrated'] . PHP_EOL;

function migrate_layout_json(string $raw): string {
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $raw;
    }
    $sections = $decoded['editor_meta']['structured_builder']['sections'] ?? null;
    if (!is_array($sections) || count($sections) !== 1 || !is_array($sections[0] ?? null)) {
        return $raw;
    }
    $section = $sections[0];
    if (($section['type'] ?? '') !== 'text') {
        return $raw;
    }
    $body = trim((string) ($section['content']['body'] ?? ''));
    if ($body === '') {
        return $raw;
    }
    $split = split_transcript_html($body);
    if (($split['rows'] ?? []) === []) {
        return $raw;
    }

    $newSections = [];
    if (html_has_meaningful_content($split['intro_html'])) {
        $newSections[] = [
            'id' => 'section_0_intro',
            'type' => 'text',
            'header' => null,
            'subtext' => null,
            'content' => ['body' => repair_html_fragment((string) $split['intro_html'])],
        ];
    }
    $newSections[] = [
        'id' => 'section_0_transcript',
        'type' => 'transcript',
        'header' => null,
        'subtext' => null,
        'content' => [
            'source' => transcript_source_from_rows($split['rows']),
            'rows' => $split['rows'],
        ],
    ];
    if (html_has_meaningful_content($split['outro_html'])) {
        $newSections[] = [
            'id' => 'section_0_outro',
            'type' => 'text',
            'header' => null,
            'subtext' => null,
            'content' => ['body' => repair_html_fragment((string) $split['outro_html'])],
        ];
    }

    $decoded['version'] = 2;
    $decoded['editor_meta']['builder'] = 'structured_v1';
    $decoded['editor_meta']['saved_at'] = gmdate('c');
    $decoded['editor_meta']['section_count'] = count($newSections);
    $decoded['editor_meta']['structured_builder']['version'] = 1;
    $decoded['editor_meta']['structured_builder']['page_type'] = 'post';
    $decoded['editor_meta']['structured_builder']['sections'] = $newSections;

    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function split_transcript_html(string $html): array {
    if (trim($html) === '') {
        return ['intro_html' => $html, 'outro_html' => '', 'rows' => []];
    }

    if (stripos($html, '<table') !== false || stripos($html, 'wp-block-table') !== false) {
        return split_table_transcript_html($html);
    }

    return split_paragraph_transcript_html($html);
}

function split_table_transcript_html(string $html): array {
    if (!preg_match('/^(.*?)(<figure\b[^>]*wp-block-table[^>]*>.*?<\/figure>|<table\b.*?<\/table>)(.*)$/is', $html, $matches)) {
        return ['intro_html' => $html, 'outro_html' => '', 'rows' => []];
    }

    $intro = trim((string) ($matches[1] ?? ''));
    $tableHtml = (string) ($matches[2] ?? '');
    $outro = trim((string) ($matches[3] ?? ''));

    $doc = new DOMDocument('1.0', 'UTF-8');
    $wrapped = '<div id="root">' . $tableHtml . '</div>';
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        return ['intro_html' => $html, 'outro_html' => '', 'rows' => []];
    }
    $root = $doc->getElementById('root');
    if (!$root instanceof DOMElement) {
        return ['intro_html' => $html, 'outro_html' => '', 'rows' => []];
    }

    foreach ($root->getElementsByTagName('table') as $table) {
        if ($table instanceof DOMElement) {
            $rows = transcript_rows_from_table($table);
            if (count($rows) >= 2) {
                return ['intro_html' => $intro, 'outro_html' => $outro, 'rows' => normalize_transcript_rows($rows)];
            }
        }
    }

    return ['intro_html' => $html, 'outro_html' => '', 'rows' => []];
}

function split_paragraph_transcript_html(string $html): array {
    if (!preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $html, $matches)) {
        return ['intro_html' => $html, 'outro_html' => '', 'rows' => []];
    }

    $paragraphs = $matches[0] ?? [];
    $introParts = [];
    $outroParts = [];
    $rows = [];
    $seenTranscript = false;

    foreach ($paragraphs as $paragraphHtml) {
        $row = parse_speaker_paragraph_html($paragraphHtml);
        if ($row !== null) {
            $seenTranscript = true;
            $rows[] = $row;
            continue;
        }

        if (!$seenTranscript) {
            $introParts[] = $paragraphHtml;
        } else {
            $outroParts[] = $paragraphHtml;
        }
    }

    if (count($rows) < 2) {
        return ['intro_html' => $html, 'outro_html' => '', 'rows' => []];
    }

    $intro = trim(implode('', $introParts));
    $lead = extract_trailing_intro_paragraph($intro);
    if ($lead !== '') {
        array_unshift($rows, ['type' => 'message', 'speaker' => 'INTRODUCTION', 'text' => $lead]);
        $intro = trim(remove_trailing_intro_paragraph($intro));
    }

    return [
        'intro_html' => $intro,
        'outro_html' => trim(implode('', $outroParts)),
        'rows' => normalize_transcript_rows($rows),
    ];
}

function extract_transcript_table(DOMNode $node): ?DOMElement {
    if ($node instanceof DOMElement && strtolower($node->tagName) === 'table') {
        return $node;
    }
    if ($node instanceof DOMElement && strtolower($node->tagName) === 'figure' && strpos(' ' . $node->getAttribute('class') . ' ', ' wp-block-table ') !== false) {
        foreach ($node->getElementsByTagName('table') as $table) {
            if ($table instanceof DOMElement) {
                return $table;
            }
        }
    }
    return null;
}

function transcript_rows_from_table(DOMElement $table): array {
    $rows = [];
    foreach ($table->getElementsByTagName('tr') as $row) {
        if (!$row instanceof DOMElement) { continue; }
        $cells = [];
        foreach ($row->childNodes as $cell) {
            if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td','th'], true)) { $cells[] = $cell; }
        }
        if (count($cells) !== 2) { return []; }
        $speaker = sanitize_transcript_speaker((string) ($cells[0]->textContent ?? ''));
        if ($speaker === '') { return []; }
        $text = transcript_cell_text($cells[1]);
        if ($text === '') { continue; }
        if (preg_match('/^\(([^()]{1,180})\)\s*(.+)$/us', $text, $match) === 1) {
            $cue = repair_plain_text((string) ($match[1] ?? ''));
            $message = repair_plain_text((string) ($match[2] ?? ''));
            if ($cue !== '') { $rows[] = ['type' => 'cue', 'text' => $cue]; }
            if ($message !== '') { $rows[] = ['type' => 'message', 'speaker' => $speaker, 'text' => $message]; }
            continue;
        }
        $rows[] = ['type' => 'message', 'speaker' => $speaker, 'text' => $text];
    }
    return $rows;
}

function extract_transcript_paragraph_rows(array $children, int &$index): array {
    $rows = [];
    $count = count($children);
    $cursor = $index;
    while ($cursor < $count) {
        $row = extract_transcript_paragraph_row($children[$cursor]);
        if ($row === null) { break; }
        $rows[] = $row;
        $cursor++;
    }
    if (count($rows) < 2) { return []; }
    $index = $cursor;
    return normalize_transcript_rows($rows);
}

function extract_transcript_paragraph_row(DOMNode $node): ?array {
    if (!$node instanceof DOMElement || strtolower($node->tagName) !== 'p') { return null; }
    $first = null;
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText && trim(str_replace("\xc2\xa0", ' ', $child->nodeValue ?? '')) === '') { continue; }
        if ($child instanceof DOMElement) { $first = $child; }
        break;
    }
    if (!$first instanceof DOMElement || strtolower($first->tagName) !== 'strong') { return null; }
    $speaker = sanitize_transcript_speaker((string) $first->textContent);
    if ($speaker === '') { return null; }
    $clone = $node->cloneNode(true);
    if (!$clone instanceof DOMElement) { return null; }
    foreach (iterator_to_array($clone->childNodes) as $child) {
        if ($child instanceof DOMText && trim(str_replace("\xc2\xa0", ' ', $child->nodeValue ?? '')) === '') { $clone->removeChild($child); continue; }
        if ($child instanceof DOMElement && strtolower($child->tagName) === 'strong') { $clone->removeChild($child); }
        break;
    }
    $text = transcript_cell_text($clone);
    if ($text === '') { return null; }
    return ['type' => 'message', 'speaker' => $speaker, 'text' => $text];
}

function parse_speaker_paragraph_html(string $html): ?array {
    if (!preg_match('/^\s*<p\b[^>]*>\s*<strong\b[^>]*>(.*?)<\/strong>\s*(.*?)<\/p>\s*$/is', $html, $matches)) {
        return null;
    }

    $speakerRaw = repair_plain_text(strip_tags((string) ($matches[1] ?? '')));
    if (strpos($speakerRaw, ':') === false) {
        return null;
    }

    $speaker = sanitize_transcript_speaker($speakerRaw);
    if ($speaker === '') {
        return null;
    }

    $bodyHtml = trim((string) ($matches[2] ?? ''));
    $text = transcript_html_fragment_text($bodyHtml);
    if ($text === '') {
        return null;
    }

    return ['type' => 'message', 'speaker' => $speaker, 'text' => $text];
}

function extract_trailing_intro_paragraph(string $html): string {
    if (trim($html) === '' || !preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $html, $matches)) { return ''; }
    $paragraphs = $matches[0] ?? [];
    if ($paragraphs === []) { return ''; }
    $candidate = (string) end($paragraphs);
    $text = transcript_html_fragment_text($candidate);
    if ($text === '') { return ''; }
    if (preg_match('/listen here|kwbu|transcript produced|living it/i', $text) === 1 && mb_strlen($text) < 180) { return ''; }
    if (mb_strlen($text) < 120) { return ''; }
    return $text;
}

function remove_trailing_intro_paragraph(string $html): string {
    return preg_replace('/<p\b[^>]*>.*?<\/p>\s*$/is', '', $html) ?? $html;
}

function transcript_html_fragment_text(string $html): string {
    $normalized = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $normalized = preg_replace('/<\/(p|div|li|blockquote|h[1-6])>/i', "\n", $normalized) ?? $normalized;
    $normalized = strip_tags($normalized);
    $normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $normalized = repair_plain_text($normalized);
    $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;
    $normalized = preg_replace('/[ \t]+/', ' ', $normalized) ?? $normalized;
    return trim($normalized);
}

function transcript_cell_text(DOMElement $cell): string {
    $html = '';
    foreach ($cell->childNodes as $child) {
        $html .= $cell->ownerDocument instanceof DOMDocument ? (string) $cell->ownerDocument->saveHTML($child) : '';
    }
    if (trim($html) === '') { return ''; }
    return transcript_html_fragment_text($html);
}

function normalize_transcript_rows(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $type = transcript_key_clean((string) ($row['type'] ?? 'message'));
        $text = repair_plain_text((string) ($row['text'] ?? ''));
        if ($text === '') { continue; }
        if ($type === 'cue') { $out[] = ['type' => 'cue', 'text' => $text]; continue; }
        $speaker = sanitize_transcript_speaker((string) ($row['speaker'] ?? ''));
        if ($speaker === '') { continue; }
        $out[] = ['type' => 'message', 'speaker' => $speaker, 'text' => $text];
    }
    return array_slice($out, 0, 1200);
}

function transcript_source_from_rows(array $rows): string {
    $lines = [];
    foreach ($rows as $row) {
        $type = (string) ($row['type'] ?? 'message');
        $text = repair_plain_text((string) ($row['text'] ?? ''));
        if ($text === '') { continue; }
        if ($type === 'cue') { $lines[] = '(' . $text . ')'; continue; }
        $speaker = sanitize_transcript_speaker((string) ($row['speaker'] ?? ''));
        if ($speaker === '') { continue; }
        $parts = preg_split("/\n/", $text) ?: [$text];
        $first = array_shift($parts);
        $lines[] = $speaker . ': ' . (string) $first;
        foreach ($parts as $part) { $lines[] = trim((string) $part); }
    }
    return trim(implode("\n", $lines));
}

function sanitize_transcript_speaker(string $speaker): string {
    $speaker = repair_plain_text($speaker);
    $speaker = preg_replace('/[^\PC\s:.-]/u', '', $speaker) ?? $speaker;
    $speaker = preg_replace('/[^\pL\pN .,&()\'"\/:-]+/u', '', $speaker) ?? $speaker;
    $speaker = preg_replace('/\s+/u', ' ', $speaker) ?? $speaker;
    $speaker = trim($speaker);
    if ($speaker === '' || mb_strlen($speaker) > 48) { return ''; }
    if (preg_match('/^[A-Za-z0-9 .,&()\'"\/-]+:?$/u', $speaker) !== 1) { return ''; }
    return rtrim($speaker, ':');
}

function transcript_key_clean(string $value): string {
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9_\-]/', '', str_replace(' ', '_', $value)) ?? '';
}

function html_has_meaningful_content(string $html): bool {
    $normalized = preg_replace('/<br\s*\/?\s*>/i', ' ', $html) ?? $html;
    $normalized = preg_replace('/&nbsp;/i', ' ', $normalized) ?? $normalized;
    $text = trim(strip_tags(html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $text !== '';
}

function repair_html_fragment(string $html): string {
    if (trim($html) === '') { return $html; }
    return repair_plain_text($html);
}

function repair_plain_text(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $map = [
        "Ã¢â‚¬â„¢" => "’", "Ã¢â‚¬Å“" => "“", "Ã¢â‚¬Â" => "”", "Ã¢â‚¬Â¦" => "…",
        "Ã¢â‚¬â€œ" => "–", "Ã¢â‚¬â€�" => "—", "Ã¢â‚¬Â¢" => "•", "ÃÂ " => ' ',
        "â€™" => "’", "â€œ" => "“", "â€" => "”", "â€¦" => "…", "â€“" => "–", "â€”" => "—",
        "â€˜" => "‘", "â€²" => "′", "Ã©" => "é", "Ã" => "à",
    ];
    $fixed = strtr($text, $map);
    if (preg_match('//u', $fixed) !== 1) {
        $redecoded = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        if (is_string($redecoded) && preg_match('//u', $redecoded) === 1) {
            $fixed = strtr($redecoded, $map);
        }
    }
    $fixed = preg_replace('/\x{00A0}/u', ' ', $fixed) ?? $fixed;
    $fixed = preg_replace('/[ \t]+/u', ' ', $fixed) ?? $fixed;
    return trim($fixed);
}
