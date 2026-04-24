<?php
declare(strict_types=1);

namespace Metis\Core\Cache;

final class RuntimeCache {
    /**
     * @var array<string, mixed>
     */
    private static array $cache = [];

    public static function get(string $key): mixed {
        return self::$cache[$key] ?? null;
    }

    public static function has(string $key): bool {
        return array_key_exists($key, self::$cache);
    }

    public static function set(string $key, mixed $value): void {
        self::$cache[$key] = $value;
    }

    public static function forget(string $key): void {
        unset(self::$cache[$key]);
    }

    public static function clearPrefix(string $prefix): void {
        foreach (array_keys(self::$cache) as $key) {
            if ($prefix === '' || str_starts_with($key, $prefix)) {
                unset(self::$cache[$key]);
            }
        }
    }

    public static function clearAll(): void {
        self::$cache = [];
    }
}
