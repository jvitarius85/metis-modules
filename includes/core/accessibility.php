<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'metis_accessibility_profiles' ) ) {
    function metis_accessibility_profiles(): array {
        return [
            'none' => [
                'label' => 'Standard',
                'preferences' => [],
            ],
            'high-contrast' => [
                'label' => 'High Contrast',
                'preferences' => [
                    'contrast' => true,
                    'underline_links' => true,
                ],
            ],
            'large-text' => [
                'label' => 'Large Text',
                'preferences' => [
                    'large_text' => true,
                    'nav_labels' => true,
                ],
            ],
            'readable' => [
                'label' => 'Readable Typography',
                'preferences' => [
                    'readable_font' => true,
                    'reduced_motion' => true,
                ],
            ],
            'screen-reader' => [
                'label' => 'Screen Reader',
                'preferences' => [
                    'readable_font' => true,
                    'reduced_motion' => true,
                    'underline_links' => true,
                    'nav_labels' => true,
                ],
            ],
        ];
    }
}

if ( ! function_exists( 'metis_accessibility_settings' ) ) {
    function metis_accessibility_settings(): array {
        $profiles = metis_accessibility_profiles();
        $saved_profile = sanitize_key( (string) Core_Settings_Service::get( 'accessibility_default_profile', 'none' ) );
        if ( ! isset( $profiles[ $saved_profile ] ) ) {
            $saved_profile = 'none';
        }

        return [
            'toolbar_enabled' => (int) Core_Settings_Service::get( 'accessibility_toolbar_enabled', 1 ) === 1,
            'allow_overrides' => (int) Core_Settings_Service::get( 'accessibility_allow_overrides', 1 ) === 1,
            'default_profile' => $saved_profile,
            'storage_key' => 'metis-accessibility-preferences',
            'profiles' => $profiles,
        ];
    }
}

if ( ! function_exists( 'metis_accessibility_interface_enabled' ) ) {
    function metis_accessibility_interface_enabled(): bool {
        $settings = metis_accessibility_settings();
        return ! empty( $settings['toolbar_enabled'] ) && ! empty( $settings['allow_overrides'] );
    }
}

if ( ! function_exists( 'metis_accessibility_bootstrap_script' ) ) {
    function metis_accessibility_bootstrap_script(): string {
        $settings = metis_accessibility_settings();

        $payload = [
            'allowOverrides' => ! empty( $settings['allow_overrides'] ),
            'defaultProfile' => (string) $settings['default_profile'],
            'storageKey' => (string) $settings['storage_key'],
            'profiles' => array_map(
                static function ( array $profile ): array {
                    return [
                        'preferences' => (array) ( $profile['preferences'] ?? [] ),
                    ];
                },
                (array) $settings['profiles']
            ),
        ];

        $json = wp_json_encode( $payload );
        if ( ! is_string( $json ) || $json === '' ) {
            return '';
        }

        return "(function(){var c=" . $json . ";var d=document.documentElement;if(!d||!c){return;}function n(v){return v===true||v===1||v==='1'||v==='true';}function p(name){var profiles=c.profiles||{};var key=String(name||'');return profiles[key]&&profiles[key].preferences?profiles[key].preferences:{};}var prefs={profile:String(c.defaultProfile||'none')};var defaults=p(prefs.profile);Object.keys(defaults).forEach(function(key){prefs[key]=n(defaults[key]);});if(c.allowOverrides){try{var raw=window.localStorage.getItem(String(c.storageKey||''));if(raw){var saved=JSON.parse(raw);if(saved&&typeof saved==='object'){prefs=Object.assign({},prefs,saved);}}}catch(e){}}['contrast','large_text','readable_font','reduced_motion','underline_links','nav_labels'].forEach(function(key){d.setAttribute('data-mw-'+key.replace(/_/g,'-'),n(prefs[key])?'true':'false');});d.setAttribute('data-mw-profile',String(prefs.profile||'none'));})();";
    }
}
