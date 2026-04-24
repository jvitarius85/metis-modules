<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Version;
use Metis\Core\Application;

final class SystemVersionService {
    public function current(): array {
        $modules = [];
        $moduleDetails = [];
        $moduleFailures = [];

        if ( Application::has_service( 'modules' ) ) {
            $moduleService = Application::service( 'modules' );

            foreach ( $moduleService->all() as $slug => $module ) {
                $version = (string) ( $module['config']['version'] ?? '0.0.0' );
                $modules[ $slug ] = $version;
                $moduleDetails[ $slug ] = [
                    'slug' => (string) $slug,
                    'version' => $version,
                    'status' => 'loaded',
                    'reason' => '',
                ];
            }

            if ( method_exists( $moduleService, 'bootFailures' ) ) {
                foreach ( (array) $moduleService->bootFailures() as $failure ) {
                    if ( ! is_array( $failure ) ) {
                        continue;
                    }

                    $slug = \metis_key_clean( (string) ( $failure['module'] ?? '' ) );
                    if ( $slug === '' ) {
                        continue;
                    }

                    $reason = trim( (string) ( $failure['reason'] ?? 'Module failed compliance validation.' ) );
                    $path = (string) ( $failure['path'] ?? '' );
                    $version = $this->manifestVersionFromFailurePath( $path );
                    if ( $version === '' ) {
                        $version = (string) ( $modules[ $slug ] ?? 'unknown' );
                    }

                    $moduleFailures[ $slug ] = [
                        'slug' => $slug,
                        'reason' => $reason,
                        'path' => $path,
                    ];

                    if ( ! isset( $modules[ $slug ] ) ) {
                        $modules[ $slug ] = $version;
                    }

                    $moduleDetails[ $slug ] = [
                        'slug' => $slug,
                        'version' => $version,
                        'status' => 'failed',
                        'reason' => $reason,
                    ];
                }
            }
        }

        ksort( $modules );
        ksort( $moduleDetails );
        ksort( $moduleFailures );

        $buildSource = Version::sourcePath();
        $buildStamp = @filemtime( $buildSource );

        return [
            'metis_version' => Version::current(),
            'build' => $buildStamp === false ? gmdate( 'Y.m.d' ) : gmdate( 'Y.m.d', $buildStamp ),
            'modules' => $modules,
            'module_details' => array_values( $moduleDetails ),
            'module_failures' => array_values( $moduleFailures ),
        ];
    }

    private function manifestVersionFromFailurePath( string $path ): string {
        $path = trim( $path );
        if ( $path === '' ) {
            return '';
        }

        $manifestPath = $path;
        if ( is_dir( $manifestPath ) ) {
            $manifestPath = rtrim( $manifestPath, '/\\' ) . '/module.json';
        } elseif ( substr( $manifestPath, -12 ) !== '/module.json' && substr( $manifestPath, -11 ) !== 'module.json' ) {
            $manifestPath = dirname( $manifestPath ) . '/module.json';
        }

        if ( ! is_file( $manifestPath ) ) {
            return '';
        }

        $raw = (string) @file_get_contents( $manifestPath );
        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) {
            return '';
        }

        return trim( (string) ( $payload['version'] ?? '' ) );
    }
}
