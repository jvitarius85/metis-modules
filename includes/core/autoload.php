<?php
declare(strict_types=1);

if ( ! function_exists( 'metis_register_autoloader' ) ) {
    function metis_register_autoloader(): void {
        static $registered = false;

        if ( $registered ) {
            return;
        }

        $registered = true;

        spl_autoload_register(
            static function ( string $class ): void {
                $prefix = 'Metis\\';
                if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
                    return;
                }

                $relative = substr( $class, strlen( $prefix ) );
                if ( $relative === false || $relative === '' ) {
                    return;
                }

                $path = dirname( __DIR__, 2 ) . '/src/Metis/' . str_replace( '\\', '/', $relative ) . '.php';
                if ( is_file( $path ) ) {
                    require_once $path;
                }
            }
        );
    }
}

if ( ! function_exists( 'metis_register_class_aliases' ) ) {
    function metis_register_class_aliases(): void {
        static $aliased = false;

        if ( $aliased ) {
            return;
        }

        $aliased = true;

        $aliases = [
            'Metis\\Core\\Application' => 'Metis',
            'Metis\\Core\\ServiceRegistry' => 'Metis_Service_Registry',
            'Metis\\Core\\ModuleLoader' => 'Metis_Module_Loader_Service',
            'Metis\\Core\\RouterService' => 'Metis_Router_Service',
            'Metis\\Http\\Request' => 'Metis_Http_Request',
            'Metis\\Http\\Response' => 'Metis_Http_Response',
            'Metis\\Http\\Route' => 'Metis_Http_Route',
            'Metis\\Http\\Router' => 'Metis_Http_Router',
            'Metis\\Services\\AuthService' => 'Metis_Auth_Service',
            'Metis\\Services\\DatabaseService' => 'Metis_Database_Service',
            'Metis\\Services\\LoggerService' => 'Metis_Logger_Service',
            'Metis\\Services\\PermissionsService' => 'Metis_Permissions_Service',
            'Metis\\Services\\SettingsServiceAdapter' => 'Metis_Settings_Service_Adapter',
        ];

        foreach ( $aliases as $modern => $legacy ) {
            if ( class_exists( $legacy, false ) ) {
                continue;
            }

            if ( class_exists( $modern ) ) {
                class_alias( $modern, $legacy );
            }
        }
    }
}

metis_register_autoloader();
metis_register_class_aliases();
