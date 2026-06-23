#!/usr/bin/env php
<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
$modulesDir = $rootDir . '/modules';
$releasesDir = $rootDir . '/module-releases';
$registryPath = $rootDir . '/meta/modules.json';

if (!is_dir($modulesDir) || !is_dir($releasesDir) || !is_file($registryPath)) {
    fwrite(STDERR, "Expected modules/, module-releases/, and meta/modules.json under {$rootDir}\n");
    exit(1);
}

$registry = json_decode((string) file_get_contents($registryPath), true);
if (!is_array($registry)) {
    fwrite(STDERR, "Invalid JSON in {$registryPath}\n");
    exit(1);
}

if (!is_array($registry['modules'] ?? null)) {
    $registry['modules'] = [];
}

$requestedSlugs = array_values(array_filter(array_map(static function (string $slug): string {
    return preg_replace('/[^a-z0-9_]+/i', '', strtolower(trim($slug))) ?? '';
}, array_slice($argv, 1))));

if ($requestedSlugs === []) {
    foreach (glob($modulesDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $requestedSlugs[] = basename($dir);
    }
}

$requestedSlugs = array_values(array_unique($requestedSlugs));
sort($requestedSlugs);

foreach ($requestedSlugs as $slug) {
    $manifestPath = $modulesDir . '/' . $slug . '/module.json';
    if (!is_file($manifestPath)) {
        fwrite(STDERR, "Missing module manifest: {$manifestPath}\n");
        exit(1);
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        fwrite(STDERR, "Invalid JSON in {$manifestPath}\n");
        exit(1);
    }

    $version = trim((string) ($manifest['version'] ?? ''));
    if ($version === '') {
        fwrite(STDERR, "module.json is missing version: {$manifestPath}\n");
        exit(1);
    }

    $archivePath = $releasesDir . '/' . $slug . '.' . $version . '.tar.gz';
    if (!is_file($archivePath)) {
        fwrite(STDERR, "Missing module archive: {$archivePath}\n");
        exit(1);
    }

    $minimum = trim((string) ($manifest['minimum_metis'] ?? ''));
    $maximum = trim((string) ($manifest['maximum_metis'] ?? ''));
    $compatibleCore = is_array($manifest['compatible_core'] ?? null) ? $manifest['compatible_core'] : [];

    $entry = is_array($registry['modules'][$slug] ?? null) ? $registry['modules'][$slug] : [];
    $entry['latest'] = $version;
    $entry['minimum_metis'] = $minimum;
    $entry['maximum_metis'] = $maximum;
    $entry['compatible_core'] = $compatibleCore;
    $entry['release_channel'] = trim((string) ($manifest['release_channel'] ?? ($entry['release_channel'] ?? 'stable')));
    $entry['download_url'] = sprintf(
        'https://raw.githubusercontent.com/jvitarius85/metis-private/main/module-releases/%s.%s.tar.gz',
        $slug,
        $version
    );
    $entry['sha256'] = hash_file('sha256', $archivePath);

    if (!is_array($entry['previous_versions'] ?? null)) {
        $entry['previous_versions'] = [];
    }

    $registry['modules'][$slug] = $entry;
}

ksort($registry['modules']);
$registry['generated_at'] = gmdate('Y-m-d\TH:i:s\Z');

$json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "Failed to encode {$registryPath}\n");
    exit(1);
}

file_put_contents($registryPath, $json . "\n");
fwrite(STDOUT, "Updated {$registryPath}\n");
