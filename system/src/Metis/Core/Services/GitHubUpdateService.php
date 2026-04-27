<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Cache\CacheService;
use Metis\Core\Version;

final class GitHubUpdateService {
    private const CACHE_TTL = 21600;
    private const OFFICIAL_MODULES_PATH = 'meta/official-modules.json';
    private const RELEASES_MANIFEST_PATH = 'meta/releases.json';
    private const DEFAULT_MANIFEST_REF = 'stable';
    private const DEFAULT_METADATA_REF = 'main';

    public function __construct(
        private readonly GitHubClient $github,
        private readonly ConfigService $config = new ConfigService(),
        private readonly FileService $files = new FileService(),
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function checkForUpdates(bool $forceRefresh = false): array {
        $settings = $this->repositoryConfig();
        $currentVersion = (string) ($settings['current_version'] ?? Version::current());
        $cacheKey = 'api.github_release';

        if (!$forceRefresh) {
            $cached = CacheService::get($cacheKey);
            if (is_array($cached)) {
                return $this->normalizePayload($currentVersion, $cached);
            }
        } else {
            CacheService::forget($cacheKey);
        }

        $payload = $this->github->latestRelease(
            (string) $settings['owner'],
            (string) $settings['repo'],
            (string) ($settings['token'] ?? '')
        );
        CacheService::set($cacheKey, $payload, self::CACHE_TTL);

        $normalized = $this->normalizePayload($currentVersion, $payload);
        $this->logger->activity('github_update_checked', [
            'current_version' => $normalized['current_version'],
            'latest_version' => $normalized['latest_version'],
            'update_available' => $normalized['update_available'],
        ]);

        return $normalized;
    }

    public function officialModules(bool $forceRefresh = false): array {
        $catalog = $this->moduleCatalog($forceRefresh);
        return (array) ($catalog['official_modules'] ?? []);
    }

    public function requiredModules(bool $forceRefresh = false): array {
        $catalog = $this->moduleCatalog($forceRefresh);
        return (array) ($catalog['required_modules'] ?? []);
    }

    public function moduleCatalog(bool $forceRefresh = false): array {
        $settings = $this->repositoryConfig();
        $owner = (string) ($settings['owner'] ?? '');
        $repo = (string) ($settings['repo'] ?? '');
        if ($owner === '' || $repo === '') {
            return [
                'official_modules' => [],
                'required_modules' => [],
            ];
        }

        $cacheKey = sprintf(
            'api.github_module_catalog.%s.%s.%s',
            metis_key_clean($owner),
            metis_key_clean($repo),
            metis_key_clean((string) ($settings['metadata_ref'] ?? $settings['ref'] ?? ''))
        );

        if (!$forceRefresh) {
            $cached = CacheService::get($cacheKey);
            if (is_array($cached)) {
                $catalog = $this->normalizeModuleCatalog($cached);
                if ($this->hasModuleCatalogEntries($catalog)) {
                    return $catalog;
                }
            }
        } else {
            CacheService::forget($cacheKey);
        }

        try {
            $payload = $this->fetchModuleCatalogPayload(
                $owner,
                $repo,
                (string) ($settings['metadata_ref'] ?? ''),
                (string) ($settings['token'] ?? '')
            );
        } catch (\Throwable $exception) {
            $this->logger->warn('github_official_modules_lookup_failed', [
                'owner' => $owner,
                'repo' => $repo,
                'ref' => (string) ($settings['ref'] ?? ''),
                'message' => $exception->getMessage(),
            ]);
            return [
                'official_modules' => [],
                'required_modules' => [],
            ];
        }

        $catalog = $this->normalizeModuleCatalog($payload);
        if ($this->hasModuleCatalogEntries($catalog)) {
            CacheService::set($cacheKey, $catalog, self::CACHE_TTL);
        } else {
            CacheService::forget($cacheKey);
        }

        return $catalog;
    }

    public function cachedModuleCatalog(): array {
        $settings = $this->repositoryConfig();
        $owner = (string) ($settings['owner'] ?? '');
        $repo = (string) ($settings['repo'] ?? '');
        if ($owner === '' || $repo === '') {
            return [
                'official_modules' => [],
                'required_modules' => [],
            ];
        }

        $cacheKey = sprintf(
            'api.github_module_catalog.%s.%s.%s',
            metis_key_clean($owner),
            metis_key_clean($repo),
            metis_key_clean((string) ($settings['ref'] ?? ''))
        );
        $cached = CacheService::get($cacheKey);
        if (!is_array($cached)) {
            return [
                'official_modules' => [],
                'required_modules' => [],
            ];
        }

        return $this->normalizeModuleCatalog($cached);
    }

    public function semanticTagReleases(bool $forceRefresh = false): array {
        $settings = $this->repositoryConfig();
        $owner = (string) ($settings['owner'] ?? '');
        $repo = (string) ($settings['repo'] ?? '');
        if ($owner === '' || $repo === '') {
            return [];
        }

        $cacheKey = sprintf(
            'api.github_semantic_tags.%s.%s',
            metis_key_clean($owner),
            metis_key_clean($repo)
        );

        if (!$forceRefresh) {
            $cached = CacheService::get($cacheKey);
            if (is_array($cached)) {
                return $this->normalizeSemanticTagReleases($cached);
            }
        } else {
            CacheService::forget($cacheKey);
        }

        $tags = $this->github->repositoryTags($owner, $repo, (string) ($settings['token'] ?? ''));
        $releases = $this->normalizeSemanticTagReleases($tags);
        CacheService::set($cacheKey, $releases, self::CACHE_TTL);

        return $releases;
    }

    public function manifestReleases(bool $forceRefresh = false): array {
        $settings = $this->repositoryConfig();
        $owner = (string) ($settings['owner'] ?? '');
        $repo = (string) ($settings['repo'] ?? '');
        if ($owner === '' || $repo === '') {
            return [];
        }

        $cacheKey = sprintf(
            'api.github_release_manifest.%s.%s.%s',
            metis_key_clean($owner),
            metis_key_clean($repo),
            metis_key_clean((string) ($settings['metadata_ref'] ?? $settings['ref'] ?? ''))
        );

        if (!$forceRefresh) {
            $cached = CacheService::get($cacheKey);
            if (is_array($cached)) {
                return $this->normalizeManifestReleases($cached);
            }
        } else {
            CacheService::forget($cacheKey);
        }

        try {
            $payload = $this->fetchReleaseManifestPayload(
                $owner,
                $repo,
                (string) ($settings['metadata_ref'] ?? ''),
                (string) ($settings['token'] ?? '')
            );
        } catch (\Throwable $exception) {
            $this->logger->warn('github_release_manifest_lookup_failed', [
                'owner' => $owner,
                'repo' => $repo,
                'ref' => (string) ($settings['ref'] ?? ''),
                'message' => $exception->getMessage(),
            ]);
            return [];
        }

        $releases = $this->normalizeManifestReleases($payload);
        if ($releases !== []) {
            CacheService::set($cacheKey, $payload, self::CACHE_TTL);
        } else {
            CacheService::forget($cacheKey);
        }

        return $releases;
    }

    public function downloadReleaseArchive(string $tag, string $destination): array {
        $settings = $this->repositoryConfig();
        $owner = (string) ($settings['owner'] ?? '');
        $repo = (string) ($settings['repo'] ?? '');
        if ($owner === '' || $repo === '') {
            throw new \RuntimeException('GitHub repository settings are required before release archives can be downloaded.');
        }

        return $this->github->downloadRepositoryZipball(
            $owner,
            $repo,
            $tag,
            $destination,
            (string) ($settings['token'] ?? '')
        );
    }

    private function repositoryConfig(): array {
        $fileConfig = $this->config->loadFile('config/update.php', []);
        $currentVersion = (string) ($fileConfig['current_version'] ?? '');
        if ($currentVersion === '') {
            $currentVersion = Version::current();
        }

        $owner = (string) ($fileConfig['github']['owner'] ?? $this->config->get('github_update_owner', ''));
        $repo = (string) ($fileConfig['github']['repo'] ?? $this->config->get('github_update_repo', ''));

        if ($owner === '' || $repo === '') {
            $fallback = $this->repositoryConfigFromGitOrigin();
            if ($owner === '') {
                $owner = (string) ($fallback['owner'] ?? '');
            }
            if ($repo === '') {
                $repo = (string) ($fallback['repo'] ?? '');
            }
        }

        return [
            'owner' => $owner,
            'repo' => $repo,
            'ref' => $this->repositoryRef($fileConfig),
            'metadata_ref' => $this->repositoryMetadataRef($fileConfig),
            'token' => $this->resolveToken($fileConfig),
            'current_version' => $currentVersion,
        ];
    }

    private function repositoryRef(array $fileConfig): string {
        $ref = (string) ($fileConfig['github']['ref'] ?? $this->config->get('github_update_ref', ''));
        if ($ref !== '') {
            return metis_text_clean($ref);
        }

        return self::DEFAULT_MANIFEST_REF;
    }

    private function repositoryMetadataRef(array $fileConfig): string {
        $ref = (string) ($fileConfig['github']['metadata_ref'] ?? $this->config->get('github_update_metadata_ref', ''));
        if ($ref !== '') {
            return metis_text_clean($ref);
        }

        return self::DEFAULT_METADATA_REF;
    }

    private function resolveToken(array $fileConfig): string {
        $token = (string) ($fileConfig['github']['token'] ?? $this->config->get('github_update_token', ''));
        if ($token !== '') {
            return $token;
        }

        $tokenEnc = (string) ($fileConfig['github']['token_enc'] ?? $this->config->get('github_update_token_enc', ''));
        if ($tokenEnc === '') {
            return '';
        }

        return $this->decryptSecret($tokenEnc);
    }

    private function repositoryConfigFromGitOrigin(): array {
        $configPath = $this->resolveGitConfigPath();
        if ($configPath === '') {
            return [];
        }

        try {
            $config = $this->files->read($configPath);
        } catch (\RuntimeException) {
            return [];
        }

        if (!preg_match('/\\[remote\\s+"origin"\\](.*?)(?:\\n\\[|\\z)/s', $config, $match)) {
            return [];
        }

        if (!preg_match('/^\\s*url\\s*=\\s*(.+)\\s*$/mi', $match[1], $urlMatch)) {
            return [];
        }

        return $this->parseGitHubRemote((string) $urlMatch[1]);
    }

    private function resolveGitConfigPath(): string {
        $gitPath = $this->files->rootPath('.git');
        if (!$this->files->exists($gitPath)) {
            return '';
        }

        if (is_dir($gitPath)) {
            $configPath = rtrim($gitPath, '/\\') . '/config';
            return $this->files->exists($configPath) ? $configPath : '';
        }

        try {
            $pointer = trim($this->files->read($gitPath));
        } catch (\RuntimeException) {
            return '';
        }

        if (!str_starts_with($pointer, 'gitdir:')) {
            return '';
        }

        $gitDir = trim(substr($pointer, 7));
        if ($gitDir === '') {
            return '';
        }

        if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $gitDir)) {
            $gitDir = $this->files->rootPath($gitDir);
        }

        $configPath = rtrim(str_replace('\\', '/', $gitDir), '/') . '/config';
        return $this->files->exists($configPath) ? $configPath : '';
    }

    private function parseGitHubRemote(string $url): array {
        $url = trim($url);
        $patterns = [
            '#github\\.com[:/]([^/]+)/([^/]+?)(?:\\.git)?$#i',
            '#api\\.github\\.com/repos/([^/]+)/([^/]+?)(?:\\.git)?$#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $match)) {
                return [
                    'owner' => trim((string) $match[1]),
                    'repo' => trim((string) $match[2]),
                ];
            }
        }

        return [];
    }

    private function decryptSecret(string $encoded): string {
        $encoded = trim($encoded);
        if ($encoded === '') {
            return '';
        }

        if (\function_exists('metis_auth_decrypt_secret')) {
            return (string) \metis_auth_decrypt_secret($encoded);
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);

        foreach ($this->secretKeys() as $key) {
            $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            if (is_string($plain) && $plain !== '') {
                return $plain;
            }
        }

        return '';
    }

    private function secretKeys(): array {
        $appKey = $this->runtimeAppKey();
        $primary = hash('sha256', $appKey, true);

        $authKey = \defined('AUTH_KEY') ? (string) \AUTH_KEY : $appKey;
        $secureAuthKey = \defined('SECURE_AUTH_KEY') ? (string) \SECURE_AUTH_KEY : $authKey;
        $legacy = hash('sha256', $authKey . $secureAuthKey, true);

        return [ $primary, $legacy ];
    }

    private function runtimeAppKey(): string {
        if (\function_exists('metis_runtime_config_get')) {
            return (string) \metis_runtime_config_get('app_key', 'metis-local-key');
        }

        $databaseConfig = $this->config->loadFile('config/database.php', []);
        return (string) ($databaseConfig['app_key'] ?? 'metis-local-key');
    }

    private function normalizePayload(string $currentVersion, array $payload): array {
        $latestVersion = ltrim((string) ($payload['tag_name'] ?? ''), 'v');

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'update_available' => $latestVersion !== '' && version_compare($latestVersion, $currentVersion, '>'),
            'release_notes' => (string) ($payload['body'] ?? ''),
            'download_url' => (string) ($payload['zipball_url'] ?? ''),
            'published_at' => (string) ($payload['published_at'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
            'tag_name' => (string) ($payload['tag_name'] ?? ''),
        ];
    }

    private function normalizeModuleCatalog(array $payload): array {
        return [
            'official_modules' => $this->normalizeModuleSlugList($payload['official_modules'] ?? $payload),
            'required_modules' => $this->normalizeModuleSlugList($payload['required_modules'] ?? []),
        ];
    }

    private function fetchModuleCatalogPayload(string $owner, string $repo, string $preferredRef, string $token): array {
        $attempted = [];

        foreach ($this->moduleCatalogRefs($preferredRef) as $ref) {
            try {
                return $this->github->repositoryJsonFile(
                    $owner,
                    $repo,
                    self::OFFICIAL_MODULES_PATH,
                    $ref,
                    $token
                );
            } catch (\RuntimeException $exception) {
                $attempted[] = $ref !== '' ? $ref : '(default)';
            }
        }

        throw new \RuntimeException(
            sprintf(
                'Unable to load [%s] from refs: %s',
                self::OFFICIAL_MODULES_PATH,
                implode(', ', $attempted)
            )
        );
    }

    private function fetchReleaseManifestPayload(string $owner, string $repo, string $preferredRef, string $token): array {
        $attempted = [];

        foreach ($this->moduleCatalogRefs($preferredRef) as $ref) {
            try {
                return $this->github->repositoryJsonFile(
                    $owner,
                    $repo,
                    self::RELEASES_MANIFEST_PATH,
                    $ref,
                    $token
                );
            } catch (\RuntimeException $exception) {
                $attempted[] = $ref !== '' ? $ref : '(default)';
            }
        }

        throw new \RuntimeException(
            sprintf(
                'Unable to load [%s] from refs: %s',
                self::RELEASES_MANIFEST_PATH,
                implode(', ', $attempted)
            )
        );
    }

    private function moduleCatalogRefs(string $preferredRef): array {
        $refs = [ $preferredRef, self::DEFAULT_METADATA_REF, '', self::DEFAULT_MANIFEST_REF ];
        $normalized = [];

        foreach ($refs as $ref) {
            $key = trim($ref);
            if (array_key_exists($key, $normalized)) {
                continue;
            }

            $normalized[$key] = $key;
        }

        return array_values($normalized);
    }

    private function hasModuleCatalogEntries(array $catalog): bool {
        return !empty($catalog['official_modules']) || !empty($catalog['required_modules']);
    }

    private function normalizeModuleSlugList(mixed $modules): array {
        if (!is_array($modules)) {
            return [];
        }

        $normalized = [];
        foreach ($modules as $module) {
            $slug = metis_key_clean((string) $module);
            if ($slug !== '') {
                $normalized[$slug] = $slug;
            }
        }

        ksort($normalized);
        return array_values($normalized);
    }

    private function normalizeSemanticTagReleases(array $tags): array {
        $releases = [];

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $tagName = metis_text_clean((string) ($tag['name'] ?? $tag['tag'] ?? ''));
            $version = $this->versionFromTag($tagName);
            if ($tagName === '' || $version === '') {
                continue;
            }

            $commit = '';
            if (is_array($tag['commit'] ?? null)) {
                $commit = (string) ($tag['commit']['sha'] ?? '');
            } else {
                $commit = (string) ($tag['commit'] ?? '');
            }

            $releases[$tagName] = [
                'tag' => $tagName,
                'version' => $version,
                'commit' => $commit,
                'zipball_url' => (string) ($tag['zipball_url'] ?? ''),
                'source' => 'remote_tag_api',
                'trusted' => true,
                'cached' => false,
            ];
        }

        uasort(
            $releases,
            static fn(array $left, array $right): int => version_compare((string) $right['version'], (string) $left['version'])
        );

        return array_values($releases);
    }

    private function normalizeManifestReleases(array $payload): array {
        $rows = is_array($payload['releases'] ?? null) ? $payload['releases'] : [];
        $releases = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $tagName = metis_text_clean((string) ($row['tag'] ?? $row['tag_name'] ?? ''));
            $version = metis_text_clean((string) ($row['version'] ?? $this->versionFromTag($tagName)));
            if ($tagName === '' || $version === '' || $this->versionFromTag($tagName) === '') {
                continue;
            }

            $sha256 = strtolower(trim((string) ($row['sha256'] ?? '')));
            if ($sha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                $sha256 = '';
            }

            $releases[$tagName] = [
                'tag' => $tagName,
                'version' => $version,
                'commit' => trim((string) ($row['commit'] ?? '')),
                'zipball_url' => trim((string) ($row['zipball_url'] ?? $row['zip_url'] ?? $row['archive_url'] ?? $row['download_url'] ?? '')),
                'sha256' => $sha256,
                'notes_url' => trim((string) ($row['notes_url'] ?? '')),
                'published_at' => trim((string) ($row['published_at'] ?? '')),
                'minimum_php' => trim((string) ($row['minimum_php'] ?? '')),
                'source' => 'release_manifest',
                'trusted' => true,
                'cached' => false,
            ];
        }

        uasort(
            $releases,
            static fn(array $left, array $right): int => version_compare((string) $right['version'], (string) $left['version'])
        );

        return array_values($releases);
    }

    private function versionFromTag(string $tag): string {
        $version = preg_replace('/^v/i', '', trim($tag)) ?? '';
        return preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1 ? $version : '';
    }
}
