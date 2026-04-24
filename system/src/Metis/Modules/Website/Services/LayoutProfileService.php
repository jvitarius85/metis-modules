<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

final class LayoutProfileService {
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function websiteProfiles(): array {
        $profiles = [];
        foreach ( TemplateService::discoverTemplates() as $template ) {
            if ( ! is_array( $template ) ) {
                continue;
            }
            $slug = metis_key_clean( (string) ( $template['slug'] ?? '' ) );
            if ( $slug === '' ) {
                continue;
            }
            $profile = isset( $template['profile'] ) && is_array( $template['profile'] ) ? $template['profile'] : [];
            $profiles[ $slug ] = [
                'key' => $slug,
                'label' => (string) ( $template['name'] ?? $slug ),
                'description' => (string) ( $template['description'] ?? '' ),
                'header_variant' => (string) ( $profile['header_variant'] ?? 'split' ),
                'footer_variant' => (string) ( $profile['footer_variant'] ?? 'columns' ),
                'body_variant' => (string) ( $profile['body_variant'] ?? 'contained' ),
                'primary_menu_locations' => [ 'primary', 'header' ],
                'utility_menu_locations' => [ 'utility', 'secondary', 'top' ],
                'footer_menu_locations' => [ 'footer', 'primary', 'header' ],
            ];
        }

        return $profiles;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function newsletterProfiles(): array {
        return [
            'newsletter_standard' => [ 'key' => 'newsletter_standard', 'label' => 'Standard Newsletter' ],
            'newsletter_magazine' => [ 'key' => 'newsletter_magazine', 'label' => 'Magazine Digest' ],
            'newsletter_story' => [ 'key' => 'newsletter_story', 'label' => 'Story Spotlight' ],
            'newsletter_fundraising' => [ 'key' => 'newsletter_fundraising', 'label' => 'Fundraising Drive' ],
            'newsletter_event' => [ 'key' => 'newsletter_event', 'label' => 'Event Bulletin' ],
            'newsletter_minimal' => [ 'key' => 'newsletter_minimal', 'label' => 'Minimal Letter' ],
        ];
    }

    public static function defaultWebsiteProfileKey(): string {
        return TemplateService::getActiveTemplateSlug();
    }

    public static function defaultNewsletterProfileKey(): string {
        return 'newsletter_standard';
    }

    public static function sanitizeWebsiteProfile( string $key ): string {
        $candidate = metis_key_clean( $key );
        return array_key_exists( $candidate, self::websiteProfiles() ) ? $candidate : self::defaultWebsiteProfileKey();
    }

    public static function sanitizeNewsletterProfile( string $key ): string {
        $candidate = metis_key_clean( $key );
        return array_key_exists( $candidate, self::newsletterProfiles() ) ? $candidate : self::defaultNewsletterProfileKey();
    }

    /**
     * @return array<string,mixed>
     */
    public static function resolveWebsiteProfile( string $key ): array {
        $profiles = self::websiteProfiles();
        $resolved = self::sanitizeWebsiteProfile( $key );
        if ( isset( $profiles[ $resolved ] ) ) {
            return $profiles[ $resolved ];
        }

        $fallback = reset( $profiles );
        return is_array( $fallback ) ? $fallback : [
            'key' => 'centered_stack_marketing',
            'label' => 'Centered Stack Marketing',
            'description' => '',
            'header_variant' => 'split',
            'footer_variant' => 'columns',
            'body_variant' => 'contained',
            'primary_menu_locations' => [ 'primary', 'header' ],
            'utility_menu_locations' => [ 'utility', 'secondary', 'top' ],
            'footer_menu_locations' => [ 'footer', 'primary', 'header' ],
        ];
    }
}
