<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Modules\Website\Entities\Page;

/**
 * Homepage service backed by global settings.
 *
 * Stores a single page ID in `site_homepage_page_id` so only one homepage can
 * be active at a time.
 */
final class HomepageService {
    private const SETTING_KEY = 'site_homepage_page_id';

    public static function getHomepagePageId(): ?int {
        if ( ! class_exists( '\Core_Settings_Service' ) ) {
            return null;
        }

        $raw = \Core_Settings_Service::get( self::SETTING_KEY, 0 );
        $id  = (int) $raw;
        return $id > 0 ? $id : null;
    }

    public static function getHomepagePage(): ?Page {
        $id = self::getHomepagePageId();
        if ( $id === null ) {
            return null;
        }

        $page = PageService::getById( $id );
        if ( $page === null ) {
            return null;
        }

        return $page;
    }

    public static function setHomepagePageId( int $page_id ): bool {
        if ( $page_id < 1 || ! class_exists( '\Core_Settings_Service' ) ) {
            return false;
        }

        $page = PageService::getById( $page_id );
        if ( $page === null ) {
            return false;
        }

        return (bool) \Core_Settings_Service::set( self::SETTING_KEY, $page_id, true );
    }

    public static function clearHomepageIfMatches( int $page_id ): void {
        if ( $page_id < 1 || ! class_exists( '\Core_Settings_Service' ) ) {
            return;
        }

        $current = self::getHomepagePageId();
        if ( $current === null || $current !== $page_id ) {
            return;
        }

        \Core_Settings_Service::delete( self::SETTING_KEY );
    }
}
