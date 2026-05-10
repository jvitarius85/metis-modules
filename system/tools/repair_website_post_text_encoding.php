<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Metis/Core/Runtime/CliToolGuard.php';
metis_require_cli_tool();

$root = dirname(__DIR__);
$config = require $root . '/config/database.php';
$args = $argv;
array_shift($args);
$host = '10.0.4.130';
$dryRun = false;
$apply = false;
foreach ($args as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if ($arg !== '') {
        $host = $arg;
    }
}
if (!$dryRun && !$apply) {
    fwrite(STDERR, "Use --dry-run or --apply\n");
    exit(1);
}
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int)($config['port'] ?? 3306), $config['database']);
$pdo = new PDO($dsn, (string)$config['username'], (string)$config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$fields = ['excerpt', 'content_json', 'draft_content_json', 'published_content_json'];
$rows = $pdo->query('SELECT id, slug, excerpt, content_json, draft_content_json, published_content_json FROM metis_website_posts ORDER BY id')->fetchAll();
$scan = 0; $changed = 0; $fieldChanges = 0;
$update = $pdo->prepare('UPDATE metis_website_posts SET excerpt = :excerpt, content_json = :content_json, draft_content_json = :draft_content_json, published_content_json = :published_content_json, updated_at = NOW() WHERE id = :id');
function repair_text(string $text): string {
    $replacements = [
        "\xEF\xBF\xBD" => "'",
        'â€™' => "'",
        'â€˜' => "'",
        'â€œ' => '"',
        'â€\x9d' => '"',
        'â€' => '"',
        'â€\x9c' => '"',
        'â€“' => '-',
        'â€”' => '—',
        'Â ' => ' ',
        'Â' => '',
        'Ã©' => 'é',
        'Ã¨' => 'è',
        'Ã¶' => 'ö',
        'Ã¼' => 'ü',
        'Ã±' => 'ñ',
        'â€¦' => '…',
    ];
    $current = strtr($text, $replacements);
    $current = preg_replace("/([A-Za-z])�([A-Za-z])/", "$1'$2", $current) ?: $current;
    $current = preg_replace('/\s+([,.;:!?])/', '$1', $current) ?: $current;
    return $current;
}
function repair_value($value) {
    if (is_string($value)) {
        return repair_text($value);
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) $value[$k] = repair_value($v);
        return $value;
    }
    return $value;
}
foreach ($rows as $row) {
    $scan++;
    $payload = [];
    $rowChanged = false;
    foreach ($fields as $field) {
        $original = (string)($row[$field] ?? '');
        $updated = $original;
        if ($original !== '' && in_array($field, ['content_json', 'draft_content_json', 'published_content_json'], true)) {
            $decoded = json_decode($original, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $repaired = repair_value($decoded);
                $updated = json_encode($repaired, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $updated = repair_text($original);
            }
        } else {
            $updated = repair_text($original);
        }
        $payload[$field] = $updated;
        if ($updated !== $original) {
            $rowChanged = true;
            $fieldChanges++;
        }
    }
    if ($rowChanged) {
        $changed++;
        if ($dryRun) {
            echo sprintf("CHANGE id=%d slug=%s\n", (int)$row['id'], (string)$row['slug']);
        } else {
            $update->execute([
                ':id' => (int)$row['id'],
                ':excerpt' => $payload['excerpt'],
                ':content_json' => $payload['content_json'],
                ':draft_content_json' => $payload['draft_content_json'],
                ':published_content_json' => $payload['published_content_json'],
            ]);
            echo sprintf("UPDATED id=%d slug=%s\n", (int)$row['id'], (string)$row['slug']);
        }
    }
}
echo sprintf("Scanned: %d\nChanged rows: %d\nChanged fields: %d\nMode: %s\n", $scan, $changed, $fieldChanges, $dryRun ? 'dry-run' : 'apply');
