<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class FileService {
    public function rootPath(string $path = ''): string {
        $root = \defined('METIS_PATH') ? (string) \METIS_PATH : dirname(__DIR__, 4) . '/';
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';

        if ($path === '') {
            return $root;
        }

        $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');
        foreach ($this->systemPathPrefixes() as $prefix => $constant) {
            if ($normalizedPath === $prefix || str_starts_with($normalizedPath, $prefix . '/')) {
                $systemRoot = \defined($constant) ? (string) \constant($constant) : $root . 'system/' . $prefix . '/';
                return rtrim(str_replace('\\', '/', $systemRoot), '/') . '/' . ltrim(substr($normalizedPath, strlen($prefix)), '/');
            }
        }

        return $root . $normalizedPath;
    }

    /**
     * @return array<string, string>
     */
    private function systemPathPrefixes(): array {
        return [
            'assets' => 'METIS_ASSETS_PATH',
            'cloudflare' => 'METIS_CLOUDFLARE_PATH',
            'config' => 'METIS_CONFIG_PATH',
            'core-services' => 'METIS_CORE_SERVICES_PATH',
            'docs' => 'METIS_DOCS_PATH',
            'modules' => 'METIS_MODULES_PATH',
            'src' => 'METIS_SRC_PATH',
            'tests' => 'METIS_TESTS_PATH',
            'tools' => 'METIS_TOOLS_PATH',
            'vendor' => 'METIS_VENDOR_PATH',
        ];
    }

    private function managedPath(string $path): string {
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with($normalized, '/')) {
            $normalized = $this->rootPath($normalized);
        }

        $root = rtrim($this->rootPath(), '/');
        $realRoot = realpath($root);
        $parent = dirname($normalized);
        $existingParent = $parent;
        while (!is_dir($existingParent) && dirname($existingParent) !== $existingParent) {
            $existingParent = dirname($existingParent);
        }
        $realParent = realpath($existingParent);

        if (!is_string($realRoot) || !is_string($realParent)) {
            throw new \RuntimeException(sprintf('Refusing unmanaged file path [%s].', $path));
        }

        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
        $realParent = rtrim(str_replace('\\', '/', $realParent), '/') . '/';
        if (!str_starts_with($realParent, $realRoot)) {
            throw new \RuntimeException(sprintf('Refusing unmanaged file path [%s].', $path));
        }

        return $normalized;
    }

    public function ensureDirectory(string $path): string {
        $path = $this->managedPath($path);
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Unable to create directory [%s].', $path));
        }

        return $path;
    }

    public function exists(string $path): bool {
        return file_exists($path);
    }

    public function read(string $path): string {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Unable to read file [%s].', $path));
        }

        return $contents;
    }

    public function write(string $path, string $contents): void {
        $path = $this->managedPath($path);
        $this->ensureDirectory(dirname($path));

        $this->auditFileOperation('file_write_attempted', $path, ['bytes' => strlen($contents)]);
        if (!$this->writeNative($path, $contents)) {
            $this->auditFileOperation('file_write_failed', $path, ['bytes' => strlen($contents)]);
            throw new \RuntimeException(sprintf('Unable to write file [%s].', $path));
        }
        $this->auditFileOperation('file_write_completed', $path, ['bytes' => strlen($contents)]);
    }

    public function readJson(string $path, array $default = []): array {
        if (!$this->exists($path)) {
            return $default;
        }

        $decoded = json_decode($this->read($path), true);
        return is_array($decoded) ? $decoded : $default;
    }

    public function writeJson(string $path, array $payload): void {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException(sprintf('Unable to encode JSON for [%s].', $path));
        }

        $this->write($path, $encoded . PHP_EOL);
    }

    public function copy(string $source, string $destination): void {
        $destination = $this->managedPath($destination);
        $this->ensureDirectory(dirname($destination));

        $this->auditFileOperation('file_copy_attempted', $destination, [
            'source_hash' => hash('sha256', str_replace('\\', '/', $source)),
        ]);
        if (!@copy($source, $destination)) {
            $this->auditFileOperation('file_copy_failed', $destination);
            throw new \RuntimeException(sprintf('Unable to copy [%s] to [%s].', $source, $destination));
        }
        $this->auditFileOperation('file_copy_completed', $destination);
    }

    public function remove(string $path): void {
        $path = $this->managedPath($path);
        if (is_dir($path) && !is_link($path)) {
            $items = scandir($path);
            if ($items === false) {
                throw new \RuntimeException(sprintf('Unable to scan directory [%s].', $path));
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $this->remove($path . '/' . $item);
            }

            if (!@rmdir($path)) {
                throw new \RuntimeException(sprintf('Unable to remove directory [%s].', $path));
            }

            return;
        }

        if (file_exists($path) && !@unlink($path)) {
            $this->auditFileOperation('file_remove_failed', $path);
            throw new \RuntimeException(sprintf('Unable to remove file [%s].', $path));
        }
        $this->auditFileOperation('file_remove_completed', $path);
    }

    public function setPermissions(string $path, int $mode): void {
        $path = $this->managedPath($path);
        $this->auditFileOperation('file_permission_change_attempted', $path, [
            'mode' => decoct($mode),
        ]);
        if (!@chmod($path, $mode)) {
            $this->auditFileOperation('file_permission_change_failed', $path, [
                'mode' => decoct($mode),
            ]);
            throw new \RuntimeException(sprintf('Unable to change permissions for [%s].', $path));
        }
        $this->auditFileOperation('file_permission_change_completed', $path, [
            'mode' => decoct($mode),
        ]);
    }

    public function hashFile(string $path, string $algo = 'sha256'): string {
        $path = $this->managedPath($path);
        $hash = @hash_file($algo, $path);
        if (!is_string($hash)) {
            throw new \RuntimeException(sprintf('Unable to hash file [%s].', $path));
        }

        return $hash;
    }

    public function extractZip(string $archive, string $destination): void {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive is not available.');
        }

        $this->ensureDirectory($destination);

        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException(sprintf('Unable to open archive [%s].', $archive));
        }

        $ok = $zip->extractTo($destination);
        $zip->close();

        if (!$ok) {
            throw new \RuntimeException(sprintf('Unable to extract archive [%s].', $archive));
        }
    }

    public function listFilesRecursive(string $directory): array {
        if (!is_dir($directory)) {
            return [];
        }

        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $result[] = $path;
        }

        sort($result);
        return $result;
    }

    private function writeNative(string $path, string $contents): bool {
        try {
            $file = new \SplFileObject($path, 'c+b');
            if (!$file->flock(LOCK_EX)) {
                return false;
            }

            if (!$file->ftruncate(0)) {
                $file->flock(LOCK_UN);
                return false;
            }

            $bytes = strlen($contents);
            $written = 0;
            while ($written < $bytes) {
                $chunk = substr($contents, $written);
                $result = $file->fwrite($chunk);
                if ($result === false || $result < 1) {
                    $file->flock(LOCK_UN);
                    return false;
                }
                $written += $result;
            }
            $file->fflush();
            $file->flock(LOCK_UN);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function auditFileOperation(string $event, string $path, array $context = []): void {
        if (!$this->shouldAuditFileOperation($event, $path)) {
            return;
        }

        $payload = [
            'module' => 'system',
            'resource' => [
                'type' => 'file',
                'id' => hash('sha256', str_replace('\\', '/', $path)),
            ],
            'context' => $context + [
                'path_hash' => hash('sha256', str_replace('\\', '/', $path)),
                'extension' => strtolower((string) pathinfo($path, PATHINFO_EXTENSION)),
            ],
        ];

        if (\function_exists('metis_audit_log_activity')) {
            try {
                \metis_audit_log_activity($event, $payload);
                return;
            } catch (\Throwable) {
                // Fall through to runtime logger when audit storage is unavailable.
            }
        }

        if (\class_exists('\Metis_Logger')) {
            \Metis_Logger::info($event, $payload['context']);
        }
    }

    private function shouldAuditFileOperation(string $event, string $path): bool {
        if ($event === 'file_write_failed' || str_ends_with($event, '_failed')) {
            return true;
        }

        if ($this->verboseOperationalAuditEnabled()) {
            return true;
        }

        if ($event === 'file_write_attempted') {
            return false;
        }

        if ($event === 'file_write_completed') {
            return $this->isSensitiveWritePath($path);
        }

        return true;
    }

    private function verboseOperationalAuditEnabled(): bool {
        if (!\class_exists('Core_Settings_Service')) {
            return false;
        }

        $value = \Core_Settings_Service::get('audit_verbose_operational_events', false);
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(\strtolower(\trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function isSensitiveWritePath(string $path): bool {
        $normalized = \str_replace('\\', '/', $path);
        $root = \rtrim(\str_replace('\\', '/', $this->rootPath()), '/') . '/';
        if (\str_starts_with($normalized, $root)) {
            $normalized = \substr($normalized, \strlen($root));
        }

        foreach (['system/config/', 'config/', 'system/modules/', 'modules/'] as $prefix) {
            if (\str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
