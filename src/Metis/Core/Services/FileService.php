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

        return $root . ltrim(str_replace('\\', '/', $path), '/');
    }

    public function ensureDirectory(string $path): string {
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
        $this->ensureDirectory(dirname($path));

        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write file [%s].', $path));
        }
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
        $this->ensureDirectory(dirname($destination));

        if (!@copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Unable to copy [%s] to [%s].', $source, $destination));
        }
    }

    public function remove(string $path): void {
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
            throw new \RuntimeException(sprintf('Unable to remove file [%s].', $path));
        }
    }

    public function hashFile(string $path, string $algo = 'sha256'): string {
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
}
