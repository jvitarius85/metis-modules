<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_drive_workspace_base_settings(): array {
    $service = function_exists( 'metis_workspace_service_account_payload' ) ? metis_workspace_service_account_payload() : [];
    $impersonation_admin = strtolower( trim( (string) Core_Settings_Service::get( 'workspace_impersonation_admin', '' ) ) );
    if ( empty( $service ) || ! metis_email_is_valid( $impersonation_admin ) ) {
        return [ 'ok' => false, 'error' => 'Workspace service account JSON or impersonation admin is not configured.' ];
    }
    $service_error = function_exists( 'metis_workspace_service_account_error' ) ? metis_workspace_service_account_error( $service ) : '';
    if ( $service_error !== '' ) {
        return [ 'ok' => false, 'error' => $service_error ];
    }
    return [
        'ok'      => true,
        'service' => $service,
        'subject' => $impersonation_admin,
        'scopes'  => [ 'https://www.googleapis.com/auth/drive' ],
    ];
}

function metis_drive_setting_rows(): array {
    $rows       = Core_Settings_Service::get( 'workspace_drive_configs', [] );
    $normalized = [];
    if ( is_array( $rows ) ) {
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $drive_id = trim( (string) ( $row['drive_id'] ?? '' ) );
            if ( $drive_id === '' ) continue;
            $normalized[] = [
                'label'         => trim( (string) ( $row['label'] ?? '' ) ),
                'drive_id'      => $drive_id,
                'drive_name'    => trim( (string) ( $row['drive_name'] ?? '' ) ),
                'is_default'    => ! empty( $row['is_default'] ) ? 1 : 0,
                'is_users_home' => ! empty( $row['is_users_home'] ) ? 1 : 0,
            ];
        }
    }
    if ( empty( $normalized ) ) {
        $legacy_id = trim( (string) Core_Settings_Service::get( 'workspace_shared_drive_id', '' ) );
        if ( $legacy_id !== '' ) {
            $normalized[] = [
                'label'         => 'Primary Drive',
                'drive_id'      => $legacy_id,
                'drive_name'    => '',
                'is_default'    => 1,
                'is_users_home' => 0,
            ];
        }
    }
    return $normalized;
}

function metis_drive_default_setting(): array {
    $rows = metis_drive_setting_rows();
    if ( empty( $rows ) ) return [];
    foreach ( $rows as $row ) {
        if ( ! empty( $row['is_default'] ) ) return $row;
    }
    return $rows[0];
}

function metis_drive_setting_by_id( string $drive_id ): array {
    $drive_id = trim( $drive_id );
    if ( $drive_id === '' ) return [];
    foreach ( metis_drive_setting_rows() as $row ) {
        if ( (string) ( $row['drive_id'] ?? '' ) === $drive_id ) return $row;
    }
    return [];
}

function metis_drive_configured_drives(): array {
    $drives = [];
    foreach ( metis_drive_setting_rows() as $row ) {
        $drive_id = trim( (string) ( $row['drive_id'] ?? '' ) );
        if ( $drive_id === '' ) continue;
        $label      = trim( (string) ( $row['label'] ?? '' ) );
        $drive_name = trim( (string) ( $row['drive_name'] ?? '' ) );
        $drives[] = [
            'drive_id'      => $drive_id,
            'label'         => $label !== '' ? $label : ( $drive_name !== '' ? $drive_name : $drive_id ),
            'drive_name'    => $drive_name,
            'is_default'    => ! empty( $row['is_default'] ) ? 1 : 0,
            'is_users_home' => ! empty( $row['is_users_home'] ) ? 1 : 0,
        ];
    }
    return $drives;
}

function metis_drive_users_home_setting(): array {
    foreach ( metis_drive_setting_rows() as $row ) {
        if ( ! empty( $row['is_users_home'] ) ) return $row;
    }
    return [];
}

function metis_drive_workspace_settings( ?string $drive_id = null ): array {
    $base = metis_drive_workspace_base_settings();
    if ( empty( $base['ok'] ) ) return $base;
    $selected = $drive_id ? metis_drive_setting_by_id( $drive_id ) : [];
    if ( empty( $selected ) ) $selected = metis_drive_default_setting();
    $shared_drive_id = trim( (string) ( $selected['drive_id'] ?? '' ) );
    if ( $shared_drive_id === '' ) {
        return [ 'ok' => false, 'error' => 'No default shared drive is configured in Settings.' ];
    }
    $base['shared_drive_id']    = $shared_drive_id;
    $base['shared_drive_name']  = trim( (string) ( $selected['drive_name'] ?? '' ) );
    $base['shared_drive_label'] = trim( (string) ( $selected['label'] ?? '' ) );
    return $base;
}

function metis_drive_b64url_encode( string $value ): string {
    return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
}
