<?php
declare(strict_types=1);

namespace Metis\Core\Cache;

final class FileCache {
    private const GROUPS = [ 'api', 'query', 'modules', 'hermes', 'fragments' ];

    private string $basePath;

    public function __construct(?string $basePath = null) {
        $root = \defined('METIS_PATH') ? (string) \METIS_PATH : dirname(__DIR__, 4) . '/';
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? ($root . 'storage/runtime/cache')), '/') . '/';
        $this->ensureDirectories();
    }

    public function get(string $group, string $key): mixed {
        $path = $this->pathFor($group, $key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            @unlink($path);
            return null;
        }

        $expires = (int) ($payload['expires'] ?? 0);
        if ($expires > 0 && $expires < time()) {
            @unlink($path);
            return null;
        }

        $encoded = (string) ($payload['data'] ?? '');
        if ($encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded)) {
            @unlink($path);
            return null;
        }

        $value = @unserialize($decoded, [ 'allowed_classes' => false ]);
        if ($value === false && $decoded !== 'b:0;') {
            @unlink($path);
            return null;
        }

        return $value;
    }

    public function set(string $group, string $key, mixed $value, int $ttl = 3600): void {
        $groupPath = $this->groupPath($group);
        $this->ensureDirectory($groupPath);

        $payload = [
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'data' => base64_encode(serialize($value)),
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException(sprintf('Unable to encode cache payload for key [%s].', $key));
        }

        $path = $this->pathFor($group, $key);
        $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write cache file [%s].', $temp));
        }

        @chmod($temp, 0644);
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException(sprintf('Unable to move cache file into place [%s].', $path));
        }
    }

    public function forget(string $group, string $key): void {
        $path = $this->pathFor($group, $key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function clearGroup(string $group): void {
        $path = $this->groupPath($group);
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) glob($path . '*.cache') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function clearByPrefix(string $prefix): void {
        $prefix = $this->normalizeFilename($prefix);
        foreach (self::GROUPS as $group) {
            $path = $this->groupPath($group);
            if (!is_dir($path)) {
                continue;
            }

            foreach ((array) glob($path . '*.cache') as $file) {
                $filename = basename((string) $file, '.cache');
                if ($prefix === '' || $filename === $prefix || str_starts_with($filename, $prefix . '_')) {
                    @unlink($file);
                }
            }
        }
    }

    public function clearAll(): void {
        foreach (self::GROUPS as $group) {
            $this->clearGroup($group);
        }
    }

    public function ensureDirectories(): void {
        $this->ensureDirectory($this->basePath);
        foreach (self::GROUPS as $group) {
            $this->ensureDirectory($this->groupPath($group));
        }
    }

    public function pathFor(string $group, string $key): string {
        return $this->groupPath($group) . $this->normalizeFilenameForKey($group, $key) . '.cache';
    }

    private function groupPath(string $group): string {
        $group = $this->normalizeGroup($group);
        return $this->basePath . $group . '/';
    }

    private function normalizeGroup(string $group): string {
        $group = strtolower(trim($group));
        return in_array($group, self::GROUPS, true) ? $group : 'query';
    }

    private function normalizeFilenameForKey(string $group, string $key): string {
        $group = $this->normalizeGroup($group);
        $key = strtolower(trim($key));

        return match ($key) {
            'modules.registry' => 'module_registry',
            'prefix.registry' => 'prefix_registry',
            default => $this->normalizeFilename($key),
        };
    }

    private function normalizeFilename(string $value): string {
        $value = strtolower(trim($value));
        $value = str_replace([ '\\', '/', ':', '.' ], '_', $value);
        $value = preg_replace('/[^a-z0-9_\-]/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value !== '' ? $value : 'cache_item';
    }

    private function ensureDirectory(string $path): void {
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create cache directory [%s].', $path));
        }
    }
}
