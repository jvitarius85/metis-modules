<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Application;
use Metis\Core\Cache\CacheService;

final class UpdateService {
    private const CORE_CACHE_KEY = 'updates.core';
    private const CORE_CACHE_TTL = 21600;

    public function __construct(
        private readonly GitHubUpdateService $githubUpdates,
        private readonly ModuleUpdateService $moduleUpdates,
        private readonly IntegrityService $integrity,
        private readonly FileService $files = new FileService(),
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function checkForUpdates(bool $forceRefresh = false): array {
        return $this->checkCoreUpdates($forceRefresh);
    }

    public function checkCoreUpdates(bool $forceRefresh = false, ?array $prefetched = null): array {
        if (!$forceRefresh) {
            $cached = CacheService::get(self::CORE_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        } else {
            CacheService::forget(self::CORE_CACHE_KEY);
        }

        $payload = is_array($prefetched) ? $prefetched : $this->githubUpdates->checkForUpdates($forceRefresh);
        CacheService::set(self::CORE_CACHE_KEY, $payload, self::CORE_CACHE_TTL);
        return $payload;
    }

    public function checkModuleUpdates(bool $forceRefresh = false): array {
        return $this->moduleUpdates->checkForUpdates($forceRefresh);
    }

    public function refreshUpdateState(bool $forceRefresh = false, string $trigger = 'manual'): array {
        $checkedAt = gmdate('c');
        $core = \function_exists('metis_release_check_for_updates')
            ? \metis_release_check_for_updates($forceRefresh, $trigger)
            : ( Application::has_service('release')
                ? Application::service('release')->checkForUpdates($forceRefresh, $trigger)
                : $this->checkCoreUpdates($forceRefresh) );
        $this->checkCoreUpdates(true, $core);

        $modules = [];
        try {
            $modules = $this->checkModuleUpdates($forceRefresh);
        } catch (\Throwable $exception) {
            $this->logger->warn('module_update_refresh_failed', [
                'trigger' => $trigger,
                'message' => $exception->getMessage(),
            ]);
            $modules = [
                'checked_at' => $checkedAt,
                'updates_available' => false,
                'update_count' => 0,
                'blocked_count' => 0,
                'module_count' => 0,
                'registry_status' => 'failed',
                'registry_error' => $exception->getMessage(),
                'modules' => [],
            ];
        }

        return [
            'checked_at' => $checkedAt,
            'core' => $core,
            'modules' => $modules,
            'updates_available' => !empty($core['update_available']) || !empty($modules['updates_available']),
        ];
    }

    public function installUpdate(?array $release = null, string $trigger = 'manual'): array {
        $metadata = $release ?? $this->githubUpdates->checkForUpdates(true);
        $tmpDir = $this->files->ensureDirectory($this->files->rootPath('storage/runtime/update_tmp'));

        $result = [
            'status' => 'ready',
            'trigger' => $trigger,
            'current_version' => (string) ($metadata['current_version'] ?? ''),
            'latest_version' => (string) ($metadata['latest_version'] ?? ''),
            'download_url' => (string) ($metadata['download_url'] ?? ''),
            'temporary_directory' => $tmpDir,
            'steps' => [
                'download_release_archive',
                'verify_integrity_signature',
                'extract_archive',
                'compare_file_differences',
                'replace_changed_files',
                'execute_migrations',
                'clear_runtime_caches',
                'reload_modules',
            ],
        ];

        if (empty($metadata['update_available'])) {
            $result['status'] = 'already_current';
            return $result;
        }

        $integrity = $this->integrity->verifyBaseline();
        if (isset($integrity['ok']) && !$integrity['ok']) {
            $result['status'] = 'integrity_blocked';
            $result['integrity'] = $integrity;
            return $result;
        }

        if (Application::has_service('release')) {
            $tag = (string) ($metadata['tag_name'] ?? '');
            $releaseResult = Application::service('release')->applyRelease($tag, $trigger);
            $result['release_manager'] = $releaseResult;
            $result['status'] = !empty($releaseResult['ok']) ? 'installed' : (string) ($releaseResult['status'] ?? 'failed');
        } else {
            $result['status'] = 'dry_run';
        }

        $this->clearRuntimeCaches();
        $this->reloadModuleRegistry();
        CacheService::rebuildSystemCaches();
        $this->logger->activity('update_installed', [
            'trigger' => $trigger,
            'status' => $result['status'],
            'latest_version' => $result['latest_version'],
        ]);

        return $result;
    }

    public function clearRuntimeCaches(): void {
        CacheService::clearAll();

        foreach ([
            $this->files->rootPath('storage/runtime/cache'),
            $this->files->rootPath('storage/runtime/update_tmp'),
        ] as $path) {
            if (is_dir($path)) {
                foreach ($this->files->listFilesRecursive($path) as $file) {
                    $this->files->remove($file);
                }
            }
        }

        if (\function_exists('metis_standalone_invalidate_config_cache')) {
            \metis_standalone_invalidate_config_cache();
        }
    }

    public function reloadModuleRegistry(): void {
        if (Application::has_service('modules')) {
            $modules = Application::service('modules');
            if (is_object($modules) && method_exists($modules, 'reload')) {
                $modules->reload();
            }
        }
    }
}
