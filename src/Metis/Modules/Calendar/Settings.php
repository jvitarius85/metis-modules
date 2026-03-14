<?php
declare(strict_types=1);

namespace Metis\Modules\Calendar;

final class Settings {
    public static function workspaceBaseSettings(): array {
        $service              = function_exists( 'metis_workspace_service_account_payload' ) ? \metis_workspace_service_account_payload() : [];
        $impersonation_admin  = strtolower( trim( (string) \Core_Settings_Service::get( 'workspace_impersonation_admin', '' ) ) );

        if ( empty( $service ) || ! \is_email( $impersonation_admin ) ) {
            return [ 'ok' => false, 'error' => 'Workspace service account JSON or impersonation admin is not configured.' ];
        }

        $service_error = function_exists( 'metis_workspace_service_account_error' ) ? \metis_workspace_service_account_error( $service ) : '';
        if ( $service_error !== '' ) {
            return [ 'ok' => false, 'error' => $service_error ];
        }

        return [
            'ok'      => true,
            'service' => $service,
            'subject' => $impersonation_admin,
            'scopes'  => [ 'https://www.googleapis.com/auth/calendar' ],
        ];
    }

    public static function settingRows(): array {
        $rows       = \Core_Settings_Service::get( 'workspace_calendar_configs', [] );
        $normalized = [];

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $calendar_id = trim( (string) ( $row['calendar_id'] ?? '' ) );
                if ( $calendar_id === '' ) {
                    continue;
                }

                $normalized[] = [
                    'label'         => trim( (string) ( $row['label'] ?? '' ) ),
                    'calendar_id'   => $calendar_id,
                    'calendar_name' => trim( (string) ( $row['calendar_name'] ?? '' ) ),
                    'is_default'    => ! empty( $row['is_default'] ) ? 1 : 0,
                ];
            }
        }

        if ( empty( $normalized ) ) {
            $legacy_id = trim( (string) \Core_Settings_Service::get( 'workspace_default_calendar_id', '' ) );
            if ( $legacy_id !== '' ) {
                $normalized[] = [
                    'label'         => 'Primary Calendar',
                    'calendar_id'   => $legacy_id,
                    'calendar_name' => '',
                    'is_default'    => 1,
                ];
            }
        }

        return $normalized;
    }

    public static function defaultSetting(): array {
        $rows = self::settingRows();
        if ( empty( $rows ) ) {
            return [];
        }

        foreach ( $rows as $row ) {
            if ( ! empty( $row['is_default'] ) ) {
                return $row;
            }
        }

        return $rows[0];
    }

    public static function settingMap(): array {
        $map = [];
        foreach ( self::settingRows() as $row ) {
            $calendar_id = trim( (string) ( $row['calendar_id'] ?? '' ) );
            if ( $calendar_id === '' ) {
                continue;
            }
            $map[ $calendar_id ] = $row;
        }

        return $map;
    }

    public static function settingsByIds( array $calendar_ids ): array {
        $map      = self::settingMap();
        $selected = [];

        foreach ( $calendar_ids as $calendar_id ) {
            $id = trim( (string) $calendar_id );
            if ( $id === '' || empty( $map[ $id ] ) ) {
                continue;
            }
            $selected[] = $map[ $id ];
        }

        return $selected;
    }

    public static function settingConfig( array $setting ): array {
        $base = self::workspaceBaseSettings();
        if ( empty( $base['ok'] ) ) {
            return $base;
        }

        $calendar_id = trim( (string) ( $setting['calendar_id'] ?? '' ) );
        if ( $calendar_id === '' ) {
            return [ 'ok' => false, 'error' => 'Calendar ID is missing.' ];
        }

        $base['calendar_id']   = $calendar_id;
        $base['calendar_name'] = trim( (string) ( $setting['calendar_name'] ?? '' ) );
        $base['calendar_label'] = trim( (string) ( $setting['label'] ?? '' ) );
        return $base;
    }

    public static function workspaceSettings(): array {
        $selected = self::defaultSetting();
        if ( empty( $selected ) ) {
            return [ 'ok' => false, 'error' => 'No default calendar is configured in Settings.' ];
        }

        return self::settingConfig( $selected );
    }

    public static function workspaceSettingsAll(): array {
        $base = self::workspaceBaseSettings();
        if ( empty( $base['ok'] ) ) {
            return $base;
        }

        $rows = self::settingRows();
        if ( empty( $rows ) ) {
            return [ 'ok' => false, 'error' => 'No calendars are configured in Settings.' ];
        }

        $configs = [];
        foreach ( $rows as $row ) {
            $cfg = self::settingConfig( $row );
            if ( empty( $cfg['ok'] ) ) {
                continue;
            }
            $configs[] = $cfg;
        }

        if ( empty( $configs ) ) {
            return [ 'ok' => false, 'error' => 'No valid calendars are configured in Settings.' ];
        }

        return [
            'ok'        => true,
            'service'   => $base['service'],
            'subject'   => $base['subject'],
            'scopes'    => $base['scopes'],
            'calendars' => $configs,
        ];
    }
}
