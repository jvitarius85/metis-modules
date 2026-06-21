<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Cache\CacheService;
use Metis\Core\ModulePathRegistry;
use Metis\Core\Version;

final class ModuleUpdateService {
    private const CACHE_KEY = 'updates.modules';
    private const CACHE_TTL = 21600;
    private const SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/';

    public function __construct(
        private readonly GitHubUpdateService $githubUpdates,
        private readonly FileService $files = new FileService(),
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function statusSnapshot(): array {
        $cached = CacheService::get( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        return $this->checkForUpdates( false );
    }

    public function checkForUpdates( bool $forceRefresh = false ): array {
        if ( ! $forceRefresh ) {
            $cached = CacheService::get( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        } else {
            CacheService::forget( self::CACHE_KEY );
        }

        $checkedAt = gmdate( 'c' );
        $currentMetisVersion = Version::current();
        $installedModules = $this->discoverInstalledModules();
        $registry = $this->githubUpdates->moduleRegistry( $forceRefresh );
        $registryModules = is_array( $registry['modules'] ?? null ) ? (array) $registry['modules'] : [];
        $registryStatus = trim( (string) ( $registry['status'] ?? 'unavailable' ) );
        $registryError = trim( (string) ( $registry['error'] ?? '' ) );

        $results = [];
        $updateCount = 0;
        $blockedCount = 0;

        foreach ( $installedModules as $module ) {
            $moduleId = (string) ( $module['id'] ?? '' );
            $installedVersion = trim( (string) ( $module['version'] ?? '' ) );
            $installedChannel = trim( (string) ( $module['release_channel'] ?? 'stable' ) );
            $installedMinimumMetis = trim( (string) ( $module['minimum_metis'] ?? '' ) );
            $registryEntry = is_array( $registryModules[ $moduleId ] ?? null ) ? (array) $registryModules[ $moduleId ] : [];
            $latestVersion = trim( (string) ( $registryEntry['latest'] ?? '' ) );
            $requiredMetis = trim( (string) ( $registryEntry['minimum_metis'] ?? $installedMinimumMetis ) );
            $releaseChannel = trim( (string) ( $registryEntry['release_channel'] ?? $installedChannel ) );

            $result = [
                'module' => $moduleId,
                'id' => $moduleId,
                'name' => (string) ( $module['name'] ?? $moduleId ),
                'current' => $installedVersion,
                'latest' => $latestVersion,
                'minimum_metis' => $requiredMetis,
                'installed_minimum_metis' => $installedMinimumMetis,
                'release_channel' => $installedChannel,
                'registry_release_channel' => $releaseChannel,
                'current_metis_version' => $currentMetisVersion,
                'update_available' => false,
                'status' => 'current',
                'reason' => '',
                'download_url' => trim( (string) ( $registryEntry['download_url'] ?? '' ) ),
                'sha256' => trim( (string) ( $registryEntry['sha256'] ?? '' ) ),
                'manifest_path' => (string) ( $module['manifest_path'] ?? '' ),
            ];

            if ( $registryEntry === [] ) {
                $result['status'] = 'registry_missing';
                $result['reason'] = 'Module is not present in the remote registry.';
                $results[] = $result;
                continue;
            }

            if ( ! $this->isSemanticVersion( $installedVersion ) ) {
                $result['status'] = 'invalid_installed_version';
                $result['reason'] = 'Installed module version is not valid semantic versioning.';
                $blockedCount++;
                $results[] = $result;
                continue;
            }

            if ( ! $this->isSemanticVersion( $latestVersion ) ) {
                $result['status'] = 'invalid_registry_version';
                $result['reason'] = 'Registry latest version is not valid semantic versioning.';
                $blockedCount++;
                $results[] = $result;
                continue;
            }

            if ( $releaseChannel !== '' && $installedChannel !== '' && $releaseChannel !== $installedChannel ) {
                $result['status'] = 'release_channel_mismatch';
                $result['reason'] = sprintf( 'Registry channel [%s] does not match installed channel [%s].', $releaseChannel, $installedChannel );
                $blockedCount++;
                $results[] = $result;
                continue;
            }

            if ( $requiredMetis !== '' && version_compare( $currentMetisVersion, $requiredMetis, '<' ) ) {
                $result['status'] = 'requires_newer_metis';
                $result['reason'] = sprintf( 'Requires Metis %s or newer.', $requiredMetis );
                $blockedCount++;
                $results[] = $result;
                continue;
            }

            if ( version_compare( $latestVersion, $installedVersion, '>' ) ) {
                $result['status'] = 'update_available';
                $result['update_available'] = true;
                $updateCount++;
            }

            $results[] = $result;
        }

        usort(
            $results,
            static fn ( array $left, array $right ): int => strcmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) )
        );

        $payload = [
            'checked_at' => $checkedAt,
            'current_metis_version' => $currentMetisVersion,
            'updates_available' => $updateCount > 0,
            'update_count' => $updateCount,
            'blocked_count' => $blockedCount,
            'module_count' => count( $results ),
            'registry_status' => $registryStatus,
            'registry_error' => $registryError,
            'registry_generated_at' => trim( (string) ( $registry['generated_at'] ?? '' ) ),
            'modules' => $results,
        ];

        CacheService::set( self::CACHE_KEY, $payload, self::CACHE_TTL );

        $this->logger->activity( 'module_updates_checked', [
            'registry_status' => $registryStatus,
            'module_count' => count( $results ),
            'update_count' => $updateCount,
            'blocked_count' => $blockedCount,
            'updates_available' => $updateCount > 0,
        ] );

        return $payload;
    }

    public function discoverInstalledModules(): array {
        $manifests = ModulePathRegistry::manifestPaths( 'module' );
        $modules = [];

        foreach ( $manifests as $manifestPath ) {
            $payload = $this->readManifest( $manifestPath );
            if ( $payload === null ) {
                continue;
            }

            $moduleId = metis_key_clean( (string) ( $payload['id'] ?? $payload['slug'] ?? basename( dirname( $manifestPath ) ) ) );
            if ( $moduleId === '' ) {
                $this->logger->warn( 'module_update_manifest_missing_id', [
                    'path' => $manifestPath,
                ] );
                continue;
            }

            $moduleName = trim( (string) ( $payload['label'] ?? $payload['title'] ?? $payload['name'] ?? $moduleId ) );
            if ( $moduleName === '' ) {
                $moduleName = $moduleId;
            }

            $modules[] = [
                'id' => $moduleId,
                'name' => $moduleName,
                'version' => trim( (string) ( $payload['version'] ?? '' ) ),
                'minimum_metis' => trim( (string) ( $payload['minimum_metis'] ?? '' ) ),
                'release_channel' => trim( (string) ( $payload['release_channel'] ?? 'stable' ) ),
                'manifest_path' => $manifestPath,
            ];
        }

        usort(
            $modules,
            static fn ( array $left, array $right ): int => strcmp( (string) ( $left['id'] ?? '' ), (string) ( $right['id'] ?? '' ) )
        );

        return $modules;
    }

    private function readManifest( string $manifestPath ): ?array {
        try {
            $payload = $this->files->readJson( $manifestPath, [] );
        } catch ( \Throwable $exception ) {
            $this->logger->warn( 'module_update_manifest_read_failed', [
                'path' => $manifestPath,
                'message' => $exception->getMessage(),
            ] );
            return null;
        }

        if ( $payload === [] ) {
            $this->logger->warn( 'module_update_manifest_invalid', [
                'path' => $manifestPath,
            ] );
            return null;
        }

        return $payload;
    }

    private function isSemanticVersion( string $version ): bool {
        return preg_match( self::SEMVER_PATTERN, trim( $version ) ) === 1;
    }
}
