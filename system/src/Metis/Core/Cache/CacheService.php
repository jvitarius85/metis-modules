<?php
declare(strict_types=1);

namespace Metis\Core\Cache;

use Metis\Core\Application;
use Metis\Core\Error\FailureIsolation;
use Metis\Modules\People\AccessManager;

class CacheService {
    private static ?self $instance = null;

    public function __construct(
        private readonly ?FileCache $fileCache = null
    ) {}

    public static function get(string $key): mixed {
        $cache = self::instance();
        if (RuntimeCache::has($key)) {
            return RuntimeCache::get($key);
        }

        $isolation = self::isolation();
        $value = $isolation instanceof FailureIsolation
            ? $isolation->isolate(
                'cache',
                fn (): mixed => $cache->fileCache()->get($cache->groupForKey($key), $key),
                [
                    'optional' => true,
                    'group' => $cache->groupForKey($key),
                    'key' => $key,
                    'service' => 'cache',
                    'fallback' => null,
                ]
            )
            : $cache->fileCache()->get($cache->groupForKey($key), $key);
        if ($value !== null) {
            RuntimeCache::set($key, $value);
        }

        return $value;
    }

    public static function set(string $key, mixed $value, int $ttl = 3600): mixed {
        $cache = self::instance();
        RuntimeCache::set($key, $value);
        $isolation = self::isolation();
        if ($isolation instanceof FailureIsolation) {
            $isolation->isolate(
                'cache_store',
                static function () use ($cache, $key, $value, $ttl): void {
                    $cache->fileCache()->set($cache->groupForKey($key), $key, $value, $ttl);
                },
                [
                    'optional' => true,
                    'group' => $cache->groupForKey($key),
                    'key' => $key,
                    'service' => 'cache',
                    'fallback' => null,
                ]
            );
        } else {
            $cache->fileCache()->set($cache->groupForKey($key), $key, $value, $ttl);
        }
        return $value;
    }

    public static function remember(string $key, int $ttl, callable $resolver): mixed {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $value = $resolver();
        self::set($key, $value, $ttl);
        return $value;
    }

    public static function forget(string $key): void {
        $cache = self::instance();
        RuntimeCache::forget($key);
        $cache->fileCache()->forget($cache->groupForKey($key), $key);
    }

    public static function clearGroup(string $group): void {
        $cache = self::instance();
        $group = strtolower(trim($group));

        RuntimeCache::clearPrefix($group === '' ? '' : $group . '.');

        if (in_array($group, [ 'api', 'query', 'modules', 'hermes', 'fragments' ], true)) {
            $cache->fileCache()->clearGroup($group);
            return;
        }

        $cache->fileCache()->clearByPrefix($group);
    }

    public static function clearByPrefix(string $prefix): void {
        $cache = self::instance();
        $prefix = strtolower(trim($prefix));

        RuntimeCache::clearPrefix($prefix);
        $cache->fileCache()->clearByPrefix($prefix);
    }

    public static function clearAll(): void {
        $cache = self::instance();
        RuntimeCache::clearAll();
        $cache->fileCache()->clearAll();
    }

    public static function rebuildSystemCaches(): array {
        $cache = self::instance();
        $cache->fileCache()->ensureDirectories();
        self::clearGroup('modules');
        self::clearGroup('permissions');
        self::clearGroup('hermes');
        self::clearGroup('fragments');

        $summary = [
            'module_registry' => false,
            'permissions' => false,
            'prefix_registry' => false,
            'configuration' => false,
            'hermes' => false,
        ];

        if (class_exists(Application::class) && Application::has_service('modules')) {
            $modules = Application::service('modules');
            if (is_object($modules)) {
                if (method_exists($modules, 'reload')) {
                    $modules->reload();
                } elseif (method_exists($modules, 'boot')) {
                    $modules->boot();
                }

                if (method_exists($modules, 'all')) {
                    self::set('modules.registry', (array) $modules->all(), 3600);
                    $summary['module_registry'] = true;
                }
            }
        }

        if (class_exists(AccessManager::class) && class_exists('Metis_Tables')) {
            AccessManager::seedPermissionsAndRoles();
            $personId = AccessManager::getCurrentPersonId();
            if ($personId > 0) {
                AccessManager::permissionMatrixForPerson($personId, true);
            }
            $summary['permissions'] = true;
        }

        $prefixRegistry = self::buildPrefixRegistry();
        if ($prefixRegistry !== []) {
            self::set('prefix.registry', $prefixRegistry, 3600);
            $summary['prefix_registry'] = true;
        }

        if (\function_exists('metis_standalone_compiled_config')) {
            $compiled = \metis_standalone_compiled_config(true);
            self::set('configuration.compiled', $compiled, 3600);
            $summary['configuration'] = true;
        }

        if (class_exists(Application::class) && Application::has_service('hermes_library')) {
            $library = Application::service('hermes_library');
            if (is_object($library) && method_exists($library, 'library')) {
                try {
                    $cacheKey = method_exists($library, 'cacheKey')
                        ? (string) $library->cacheKey()
                        : 'hermes.definition_library';
                    self::set($cacheKey, (array) $library->library(), 1800);
                    $summary['hermes'] = true;
                } catch (\Throwable $exception) {
                    $summary['hermes'] = false;
                    $summary['hermes_error'] = $exception->getMessage();
                    if (\class_exists('\Metis_Logger')) {
                        \Metis_Logger::warn('Hermes definition cache rebuild skipped', [
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $summary;
    }

    private static function buildPrefixRegistry(): array {
        if (class_exists(\Metis\Core\EntityCatalog::class)) {
            $registry = [];
            foreach ((array) \Metis\Core\EntityCatalog::definitions() as $entityType => $definition) {
                $registry[(string) $entityType] = [
                    'prefix' => (string) ($definition['prefix'] ?? ''),
                    'description' => (string) ($definition['description'] ?? $entityType),
                    'module_slug' => (string) ($definition['module_slug'] ?? ''),
                ];
            }
            return $registry;
        }

        return [];
    }

    private static function instance(): self {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        if (class_exists(Application::class) && Application::has_service('cache')) {
            $service = Application::service('cache');
            if ($service instanceof self) {
                self::$instance = $service;
                return self::$instance;
            }
        }

        self::$instance = new self();
        return self::$instance;
    }

    private static function isolation(): ?FailureIsolation {
        if (class_exists(Application::class) && Application::has_service('failure_isolation')) {
            $service = Application::service('failure_isolation');
            return $service instanceof FailureIsolation ? $service : null;
        }

        return null;
    }

    private function fileCache(): FileCache {
        return $this->fileCache instanceof FileCache ? $this->fileCache : new FileCache();
    }

    private function groupForKey(string $key): string {
        $key = strtolower(trim($key));

        if (str_starts_with($key, 'api.')) {
            return 'api';
        }

        if (str_starts_with($key, 'modules.')) {
            return 'modules';
        }

        if (str_starts_with($key, 'dashboard.')) {
            return 'fragments';
        }

        if (str_starts_with($key, 'hermes.')) {
            return 'hermes';
        }

        return 'query';
    }
}

final class Cache extends CacheService {}
