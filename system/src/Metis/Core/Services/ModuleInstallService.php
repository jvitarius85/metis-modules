<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Application;
use Metis\Core\Cache\CacheService;
use Metis\Core\Version;

final class ModuleInstallService {
    private const SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/';

    public function __construct(
        private readonly GitHubUpdateService $githubUpdates,
        private readonly ModuleUpdateService $moduleUpdates,
        private readonly FileService $files = new FileService(),
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function installLatest(string $moduleId, bool $forceRefresh = true): array {
        $moduleId = metis_key_clean($moduleId);
        if ($moduleId === '') {
            return $this->failure('invalid_module', 'A valid module ID is required.');
        }

        $registry = $this->githubUpdates->moduleRegistry($forceRefresh);
        if (($registry['status'] ?? '') !== 'ready') {
            return $this->failure(
                'registry_unavailable',
                (string) ($registry['error'] ?? 'Module registry is unavailable.')
            );
        }

        $entry = is_array($registry['modules'][$moduleId] ?? null) ? (array) $registry['modules'][$moduleId] : [];
        if ($entry === []) {
            return $this->failure('missing_module', sprintf('Module [%s] is not present in the registry.', $moduleId));
        }

        $latestVersion = trim((string) ($entry['latest'] ?? ''));
        $minimumMetis = trim((string) ($entry['minimum_metis'] ?? ''));
        $downloadUrl = trim((string) ($entry['download_url'] ?? ''));
        $sha256 = strtolower(trim((string) ($entry['sha256'] ?? '')));
        if (preg_match(self::SEMVER_PATTERN, $latestVersion) !== 1) {
            return $this->failure('invalid_version', sprintf('Registry version [%s] is not valid semantic versioning.', $latestVersion));
        }
        if ($downloadUrl === '') {
            return $this->failure('missing_download', sprintf('Module [%s] does not have a download archive configured.', $moduleId));
        }
        if ($minimumMetis !== '' && version_compare(Version::current(), $minimumMetis, '<')) {
            return $this->failure('requires_newer_metis', sprintf('Module requires Metis %s or newer.', $minimumMetis));
        }

        $installedMap = [];
        foreach ($this->moduleUpdates->discoverInstalledModules() as $installedModule) {
            $installedId = metis_key_clean((string) ($installedModule['id'] ?? ''));
            if ($installedId !== '') {
                $installedMap[$installedId] = $installedModule;
            }
        }
        $current = is_array($installedMap[$moduleId] ?? null) ? (array) $installedMap[$moduleId] : [];
        $currentVersion = trim((string) ($current['version'] ?? ''));
        $moduleName = trim((string) ($current['name'] ?? ucwords(str_replace(['_', '-'], ' ', $moduleId))));
        $isUpdate = $current !== [];

        $runtimeRoot = $this->files->ensureDirectory($this->files->rootPath('storage/runtime/module_updates'));
        $workspace = $this->files->ensureDirectory($runtimeRoot . '/' . $moduleId . '-' . gmdate('YmdHis'));
        $archivePath = $workspace . '/' . $moduleId . '.' . $latestVersion . '.tar.gz';
        $extractPath = $workspace . '/extract';

        try {
            $this->downloadArchive($downloadUrl, $archivePath);
            if ($sha256 !== '') {
                $archiveHash = strtolower($this->files->hashFile($archivePath));
                if (!hash_equals($sha256, $archiveHash)) {
                    throw new \RuntimeException('Downloaded archive checksum did not match the registry sha256.');
                }
            }

            $this->extractArchive($archivePath, $extractPath);
            $moduleSource = $this->locateModuleSource($extractPath, $moduleId);
            if ($moduleSource === '') {
                throw new \RuntimeException(sprintf('Unable to locate module.json for [%s] inside the archive.', $moduleId));
            }

            $manifest = $this->files->readJson($moduleSource . '/module.json', []);
            $manifestId = metis_key_clean((string) ($manifest['id'] ?? $manifest['slug'] ?? basename($moduleSource)));
            $manifestVersion = trim((string) ($manifest['version'] ?? ''));
            if ($manifestId !== $moduleId) {
                throw new \RuntimeException(sprintf('Archive manifest ID [%s] does not match requested module [%s].', $manifestId, $moduleId));
            }
            if (preg_match(self::SEMVER_PATTERN, $manifestVersion) !== 1 || version_compare($manifestVersion, $latestVersion, '!=')) {
                throw new \RuntimeException(sprintf('Archive version [%s] does not match registry version [%s].', $manifestVersion, $latestVersion));
            }

            $destination = $this->files->rootPath('modules/' . $moduleId);
            if (is_dir($destination)) {
                $backupRoot = $this->files->ensureDirectory($runtimeRoot . '/backups');
                $this->copyDirectory($destination, $backupRoot . '/' . $moduleId . '-' . gmdate('YmdHis'));
                $this->files->remove($destination);
            }

            $this->copyDirectory($moduleSource, $destination);
            $this->refreshRuntimeState();
            $status = $this->moduleUpdates->checkForUpdates(true);
            $moduleStatus = [];
            foreach ((array) ($status['modules'] ?? []) as $row) {
                if (is_array($row) && metis_key_clean((string) ($row['id'] ?? '')) === $moduleId) {
                    $moduleStatus = $row;
                    break;
                }
            }

            $result = [
                'ok' => true,
                'status' => $isUpdate ? 'updated' : 'installed',
                'message' => sprintf('%s %s installed.', $moduleName, $latestVersion),
                'module' => $moduleId,
                'name' => $moduleName,
                'current' => $currentVersion,
                'latest' => $latestVersion,
                'minimum_metis' => $minimumMetis,
                'download_url' => $downloadUrl,
                'module_status' => $moduleStatus,
            ];

            $this->logger->activity('module_install_completed', [
                'module' => $moduleId,
                'status' => $result['status'],
                'current' => $currentVersion,
                'latest' => $latestVersion,
            ]);

            return $result;
        } catch (\Throwable $exception) {
            $this->logger->error('module_install_failed', [
                'module' => $moduleId,
                'version' => $latestVersion,
                'message' => $exception->getMessage(),
            ]);

            return $this->failure('install_failed', $exception->getMessage(), [
                'module' => $moduleId,
                'name' => $moduleName,
                'current' => $currentVersion,
                'latest' => $latestVersion,
            ]);
        }
    }

    private function downloadArchive(string $url, string $destination): void {
        if (function_exists('metis_runtime_remote_get')) {
            $response = metis_runtime_remote_get($url, [
                'timeout' => 60,
                'headers' => [
                    'Accept' => 'application/octet-stream',
                ],
            ]);
            if ($response instanceof \MetisError) {
                throw new \RuntimeException($response->get_error_message());
            }

            $status = (int) ($response['response']['code'] ?? 0);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('Archive download failed with status [%d].', $status));
            }

            $body = (string) ($response['body'] ?? '');
            if ($body === '') {
                throw new \RuntimeException('Archive download returned an empty response.');
            }

            $this->files->write($destination, $body);
            return;
        }

        $body = @file_get_contents($url);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Archive download failed.');
        }

        $this->files->write($destination, $body);
    }

    private function extractArchive(string $archivePath, string $destination): void {
        $this->files->ensureDirectory($destination);
        $tarPath = preg_replace('/\.gz$/', '', $archivePath) ?: $archivePath . '.tar';
        if (is_file($tarPath)) {
            @unlink($tarPath);
        }

        try {
            $archive = new \PharData($archivePath);
            $archive->decompress();
            $tar = new \PharData($tarPath);
            $tar->extractTo($destination, null, true);
        } finally {
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }
        }
    }

    private function locateModuleSource(string $extractPath, string $moduleId): string {
        $direct = $extractPath . '/' . $moduleId;
        if (is_file($direct . '/module.json')) {
            return $direct;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'module.json') {
                continue;
            }

            $candidate = $file->getPath();
            $payload = $this->files->readJson($candidate . '/module.json', []);
            $candidateId = metis_key_clean((string) ($payload['id'] ?? $payload['slug'] ?? basename($candidate)));
            if ($candidateId === $moduleId) {
                return $candidate;
            }
        }

        return '';
    }

    private function copyDirectory(string $source, string $destination): void {
        $this->files->ensureDirectory($destination);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                $this->files->ensureDirectory($target);
                continue;
            }

            $this->files->copy($item->getPathname(), $target);
        }
    }

    private function refreshRuntimeState(): void {
        CacheService::forget('updates.modules');
        CacheService::clearGroup('modules');
        CacheService::clearGroup('fragments');

        if (Application::has_service('modules')) {
            $modules = Application::service('modules');
            if (is_object($modules) && method_exists($modules, 'reload')) {
                $modules->reload();
            }
        }

        if (function_exists('metis_standalone_invalidate_config_cache')) {
            metis_standalone_invalidate_config_cache();
        }

        CacheService::rebuildSystemCaches();
    }

    private function failure(string $status, string $message, array $payload = []): array {
        return array_merge([
            'ok' => false,
            'status' => $status,
            'message' => $message,
        ], $payload);
    }
}
