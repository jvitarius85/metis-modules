<?php
declare(strict_types=1);

namespace Metis\Services;

final class SettingsServiceAdapter {
    public function init(): void {
        \Core_Settings_Service::init();
    }

    public function preload(): void {
        \Core_Settings_Service::preload();
    }

    public function get( string $key, mixed $default = null ): mixed {
        return \Core_Settings_Service::get( $key, $default );
    }

    public function set( string $key, mixed $value, bool $autoload = true ): bool {
        return \Core_Settings_Service::set( $key, $value, $autoload );
    }

    public function delete( string $key ): bool {
        return \Core_Settings_Service::delete( $key );
    }

    public function has( string $key ): bool {
        return \Core_Settings_Service::has( $key );
    }

    public function all(): array {
        return \Core_Settings_Service::all();
    }
}
