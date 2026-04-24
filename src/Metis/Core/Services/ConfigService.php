<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class ConfigService {
    public function __construct(
        private readonly FileService $files = new FileService()
    ) {}

    public function get(string $key, mixed $default = null): mixed {
        if (\class_exists('Core_Settings_Service')) {
            return \Core_Settings_Service::get($key, $default);
        }

        return $default;
    }

    public function set(string $key, mixed $value, bool $autoload = true): bool {
        if (\class_exists('Core_Settings_Service')) {
            return \Core_Settings_Service::set($key, $value, $autoload);
        }

        return false;
    }

    public function has(string $key): bool {
        if (\class_exists('Core_Settings_Service')) {
            return \Core_Settings_Service::has($key);
        }

        return false;
    }

    public function loadFile(string $relativePath, array $default = []): array {
        $path = $this->files->rootPath($relativePath);
        if (!is_file($path)) {
            return $default;
        }

        $loaded = require $path;
        return is_array($loaded) ? $loaded : $default;
    }
}
