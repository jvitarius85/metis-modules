<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services;

use Metis\Modules\Donations\CampaignService;
use Metis\Modules\Media\MediaLibraryService;

final class EditorOptionsService {
    /**
     * @return array<int,array{value:string,label:string}>
     */
    public static function authorOptions(): array {
        $db = \metis_db();
        $table = self::usersTable();
        $rows = $db->fetchAll(
            "SELECT ID, user_login, display_name, first_name, last_name, user_email
             FROM {$table}
             ORDER BY COALESCE(NULLIF(display_name,''), user_login) ASC
             LIMIT 250"
        ) ?: [];

        $options = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id = (int) ( $row['ID'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            $first = trim( (string) ( $row['first_name'] ?? '' ) );
            $last = trim( (string) ( $row['last_name'] ?? '' ) );
            $full = trim( $first . ' ' . $last );
            if ( $full === '' ) {
                $full = trim( (string) ( $row['display_name'] ?? '' ) );
            }
            if ( $full === '' ) {
                $full = trim( (string) ( $row['user_login'] ?? '' ) );
            }
            if ( $full === '' ) {
                continue;
            }
            $options[] = [
                'value' => (string) $id,
                'label' => $full,
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    public static function donationCampaignOptions(): array {
        $options = [];
        foreach ( CampaignService::getActiveCampaignOptions( 200 ) as $row ) {
            $value = trim( (string) ( $row['value'] ?? '' ) );
            $label = trim( (string) ( $row['label'] ?? '' ) );
            if ( $value === '' || $label === '' ) {
                continue;
            }
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array{id:string,name:string}>
     */
    public static function donationCampaignList(): array {
        $rows = [];
        foreach ( CampaignService::getActiveCampaigns( 200 ) as $campaign ) {
            if ( ! is_array( $campaign ) ) {
                continue;
            }
            $id = trim( (string) ( $campaign['cid'] ?? $campaign['campaign_code'] ?? $campaign['code'] ?? $campaign['id'] ?? '' ) );
            $name = trim( (string) ( $campaign['cname'] ?? $campaign['name'] ?? '' ) );
            if ( $id === '' || $name === '' ) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    public static function calendarSourceOptions(): array {
        if ( function_exists( 'metis_calendar_workspace_settings_all' ) ) {
            $workspace = \metis_calendar_workspace_settings_all();
            $configs = isset( $workspace['calendars'] ) && is_array( $workspace['calendars'] ) ? $workspace['calendars'] : [];
            $options = [];
            foreach ( $configs as $cfg ) {
                if ( ! is_array( $cfg ) ) {
                    continue;
                }
                $id = trim( (string) ( $cfg['calendar_id'] ?? '' ) );
                if ( $id === '' ) {
                    continue;
                }
                $label = trim( (string) ( $cfg['label'] ?? $cfg['name'] ?? '' ) );
                $options[] = [ 'value' => $id, 'label' => ( $label !== '' ? $label : $id ) ];
            }
            if ( $options !== [] ) {
                return $options;
            }
        }

        $table = \Metis_Tables::get( 'calendar_events' );
        if ( $table === '' ) {
            return [];
        }

        $rows = \metis_db()->fetchAll(
            "SELECT calendar_id
             FROM {$table}
             WHERE calendar_id IS NOT NULL AND calendar_id <> ''
             GROUP BY calendar_id
             ORDER BY calendar_id ASC
             LIMIT 100"
        ) ?: [];

        $options = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id = trim( (string) ( $row['calendar_id'] ?? '' ) );
            if ( $id === '' ) {
                continue;
            }
            $options[] = [ 'value' => $id, 'label' => $id ];
        }

        return $options;
    }

    /**
     * @return array<int,array{id:int,value:string,label:string,url:string,mime:string}>
     */
    public static function mediaOptions(): array {
        $items = MediaLibraryService::listItems( '', '', '', '', 'created_desc', 200 );
        $options = [];
        foreach ( $items as $item ) {
            $token = trim( (string) ( $item['token'] ?? '' ) );
            if ( $token === '' ) {
                continue;
            }
            $options[] = [
                'id' => (int) ( $item['id'] ?? 0 ),
                'value' => $token,
                'label' => (string) ( $item['file_name'] ?? $token ),
                'url' => (string) ( $item['url'] ?? '' ),
                'mime' => (string) ( $item['mime_type'] ?? '' ),
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array{value:string,label:string,slug:string}>
     */
    public static function testimonyCategoryOptions(): array {
        if ( ! class_exists( '\Metis\Modules\Testimonies\Repository' ) ) {
            return [];
        }

        return \Metis\Modules\Testimonies\Repository::categoryOptions( true );
    }

    private static function usersTable(): string {
        $db = \metis_db();
        $prefix = $db->prefix();
        $candidates = [];
        if ( $prefix !== '' ) {
            $prefixed = $prefix . 'users';
            if ( $prefixed !== 'users' ) {
                $candidates[] = $prefixed;
            }
        }
        $candidates[] = 'users';
        foreach ( array_unique( $candidates ) as $candidate ) {
            $found = $db->scalar( 'SHOW TABLES LIKE %s', [ $candidate ] );
            if ( is_string( $found ) && $found === $candidate ) {
                return $candidate;
            }
        }

        return 'users';
    }
}
