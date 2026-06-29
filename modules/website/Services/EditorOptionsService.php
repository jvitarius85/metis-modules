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
        $rows = self::personAuthorRows();
        $options = [];
        $seen = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id = (int) ( $row['auth_user_id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            if ( isset( $seen[ $id ] ) ) {
                continue;
            }
            $full = self::personAuthorLabelFromRow( $row );
            if ( $full === '' ) {
                continue;
            }
            $seen[ $id ] = true;
            $options[] = [
                'value' => (string) $id,
                'label' => $full,
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function personAuthorRows(): array {
        if ( ! class_exists( '\Metis_Tables' ) || ! \Metis_Tables::has( 'people' ) ) {
            return self::legacyAuthorRows();
        }

        $people_table = \Metis_Tables::get( 'people' );
        if ( $people_table === '' || ! self::tableExists( $people_table ) ) {
            return self::legacyAuthorRows();
        }

        $db = \metis_db();
        $rows = $db->fetchAll(
            "SELECT id, pid, email, first_name, last_name, display_name, status
             FROM {$people_table}
             ORDER BY display_name ASC, email ASC
             LIMIT 500"
        ) ?: [];
        if ( ! is_array( $rows ) || $rows === [] ) {
            return [];
        }

        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $person_id = (int) ( $row['id'] ?? 0 );
            if ( $person_id < 1 ) {
                continue;
            }

            $auth_user = self::authUserForPerson( $row );
            $row['auth_user_id'] = is_array( $auth_user ) ? (int) ( $auth_user['id'] ?? 0 ) : 0;
            $rows[ $index ] = $row;
        }

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function legacyAuthorRows(): array {
        $db = \metis_db();
        $table = self::usersTable();
        return $db->fetchAll(
            "SELECT ID, user_login, display_name, first_name, last_name, user_email
             FROM {$table}
             ORDER BY COALESCE(NULLIF(display_name,''), user_login) ASC
             LIMIT 250"
        ) ?: [];
    }

    /**
     * @param array<string,mixed> $person
     */
    private static function authUserForPerson( array $person ): ?array {
        $person_id = (int) ( $person['id'] ?? 0 );
        if ( $person_id < 1 || ! function_exists( 'metis_auth_find_user' ) ) {
            return null;
        }

        $auth_user = \metis_auth_find_user( 'person_id', $person_id );
        if ( is_array( $auth_user ) ) {
            return $auth_user;
        }

        $email = strtolower( trim( (string) ( $person['email'] ?? '' ) ) );
        if ( $email !== '' ) {
            $auth_user = \metis_auth_find_user( 'email', $email );
            if ( is_array( $auth_user ) ) {
                return $auth_user;
            }
        }

        if ( function_exists( 'metis_auth_upsert_user_from_person' ) ) {
            $created = \metis_auth_upsert_user_from_person( $person );
            if ( is_array( $created ) ) {
                return $created;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function personAuthorLabelFromRow( array $row ): string {
        $full = trim(
            (string) ( $row['first_name'] ?? '' )
            . ' '
            . (string) ( $row['last_name'] ?? '' )
        );
        if ( $full !== '' ) {
            return $full;
        }

        $display = trim( (string) ( $row['display_name'] ?? '' ) );
        if ( $display !== '' ) {
            return $display;
        }

        return trim( (string) ( $row['email'] ?? '' ) );
    }

    private static function tableExists( string $table ): bool {
        $table = trim( $table );
        if ( $table === '' ) {
            return false;
        }

        $found = \metis_db()->scalar( 'SHOW TABLES LIKE %s', [ $table ] );
        return is_string( $found ) && $found === $table;
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
                $label = trim( (string) ( $cfg['calendar_label'] ?? $cfg['calendar_name'] ?? $cfg['label'] ?? $cfg['name'] ?? '' ) );
                if ( $label === '' && function_exists( 'metis_calendar_sync_state' ) ) {
                    $state = \metis_calendar_sync_state( $id );
                    if ( is_array( $state ) ) {
                        $label = trim( (string) ( $state['calendar_name'] ?? '' ) );
                    }
                }
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

    /**
     * @return array<int,array{value:string,label:string,name:string,slug:string}>
     */
    public static function postTagOptions(): array {
        if ( ! class_exists( PostTagService::class ) ) {
            return [];
        }

        return PostTagService::options( true );
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    public static function popupOptions(): array {
        if ( ! class_exists( PopupService::class ) ) {
            return [];
        }

        $options = [];
        foreach ( PopupService::getAll() as $popup ) {
            if ( ! is_array( $popup ) ) {
                continue;
            }
            $id = (int) ( $popup['id'] ?? 0 );
            if ( $id < 1 ) {
                continue;
            }
            $name = trim( (string) ( $popup['name'] ?? '' ) );
            $status = metis_key_clean( (string) ( $popup['status'] ?? 'draft' ) );
            $label = $name !== '' ? $name : ( 'Popup #' . $id );
            if ( $status !== 'published' ) {
                $label .= ' [' . ucfirst( $status ) . ']';
            }
            $options[] = [
                'value' => (string) $id,
                'label' => $label,
            ];
        }

        return $options;
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
