<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_core_bootstrap' ) ) {
    /**
     * Load procedural core files through a single dependency-aware bootstrap.
     *
     * This keeps bootstrap code declarative while the PSR-4 autoloader handles namespaced classes.
     *
     * @param string|array<int, string> $components
     */
    function metis_core_bootstrap( string|array $components ): void {
        static $manifest = null;
        static $loaded = [];

        if ( $manifest === null ) {
            $manifest = [
                'autoload' => [
                    'path' => __DIR__ . '/autoload.php',
                    'requires' => [],
                ],
                'standalone_runtime' => [
                    'path' => __DIR__ . '/standalone_runtime.php',
                    'requires' => [],
                ],
                'http' => [
                    'path' => __DIR__ . '/http.php',
                    'requires' => [ 'autoload' ],
                ],
                'log' => [
                    'path' => __DIR__ . '/log.php',
                    'requires' => [],
                ],
                'service_registry' => [
                    'path' => __DIR__ . '/service_registry.php',
                    'requires' => [ 'autoload' ],
                ],
                'router' => [
                    'path' => __DIR__ . '/router.php',
                    'requires' => [ 'service_registry' ],
                ],
                'modules' => [
                    'path' => __DIR__ . '/modules.php',
                    'requires' => [ 'service_registry' ],
                ],
                'security_enclave' => [
                    'path' => __DIR__ . '/security_enclave.php',
                    'requires' => [],
                ],
                'security_runtime_bridge' => [
                    'path' => __DIR__ . '/security_runtime_bridge.php',
                    'requires' => [ 'security_enclave', 'service_registry' ],
                ],
                'auth' => [
                    'path' => __DIR__ . '/auth.php',
                    'requires' => [],
                ],
                'ajax' => [
                    'path' => __DIR__ . '/ajax.php',
                    'requires' => [],
                ],
                'cron' => [
                    'path' => __DIR__ . '/cron.php',
                    'requires' => [],
                ],
                'integrity' => [
                    'path' => __DIR__ . '/integrity.php',
                    'requires' => [],
                ],
                'release' => [
                    'path' => __DIR__ . '/release.php',
                    'requires' => [ 'service_registry', 'integrity' ],
                ],
                'standalone_bootstrap' => [
                    'path' => __DIR__ . '/standalone_bootstrap.php',
                    'requires' => [ 'standalone_runtime', 'http', 'log', 'service_registry' ],
                ],
            ];
        }

        $queue = is_array( $components ) ? $components : [ $components ];

        foreach ( $queue as $component ) {
            $component = (string) $component;
            if ( $component === '' || isset( $loaded[ $component ] ) ) {
                continue;
            }

            if ( ! isset( $manifest[ $component ] ) ) {
                throw new InvalidArgumentException( 'Unknown Metis bootstrap component: ' . $component );
            }

            foreach ( $manifest[ $component ]['requires'] as $dependency ) {
                metis_core_bootstrap( $dependency );
            }

            require_once $manifest[ $component ]['path'];
            $loaded[ $component ] = true;
        }
    }
}
