<?php
declare(strict_types=1);

namespace Metis\Core;

final class Version {
    public const CURRENT = '26.5.8.17';

    public static function sourcePath(?string $root = null): string {
        $base = $root;
        if (!is_string($base) || trim($base) === '') {
            if (\defined('METIS_PATH')) {
                $base = (string) \METIS_PATH;
            } elseif (\defined('METIS_ROOT')) {
                $base = (string) \METIS_ROOT;
            } else {
                $base = dirname(__DIR__, 3) . '/';
            }
        }

        return rtrim($base, "/\\") . '/index.php';
    }

    public static function current(?string $root = null): string {
        return self::CURRENT;
    }
}
