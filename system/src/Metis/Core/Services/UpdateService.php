<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Application;
use Metis\Core\Cache\CacheService;

final class UpdateService {
    public function __construct(
        private readonly GitHubUpdateService $githubUpdates,
        private readonly IntegrityService $integrity,
        private readonly FileService $files = new FileService(),
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function checkForUpdates(bool $forceRefresh = false): array {
        return $this->githubUpdates->checkForUpdates($forceRefresh);
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
                    @unlink($file);
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
