<?php
declare(strict_types=1);

namespace Metis\Modules\Board;

final class WorkspaceService {
    public static function b64urlEncode( string $value ): string {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    public static function workspaceSettings(): array {
        $service_json_raw      = (string) \Metis\Core\Services\CredentialService::getBySetting( 'workspace_service_account_json' );
        $impersonation_admin   = strtolower( trim( (string) \Core_Settings_Service::get( 'workspace_impersonation_admin', '' ) ) );
        if ( $service_json_raw === '' || ! \metis_email_is_valid( $impersonation_admin ) ) {
            return [ 'ok' => false, 'error' => 'Workspace service account JSON or impersonation admin is not configured.' ];
        }

        $service = json_decode( $service_json_raw, true );
        if ( ! is_array( $service ) || empty( $service['client_email'] ) || empty( $service['private_key'] ) || empty( $service['token_uri'] ) ) {
            return [ 'ok' => false, 'error' => 'Invalid Workspace service account JSON in settings.' ];
        }

        return [
            'ok'      => true,
            'service' => $service,
            'subject' => $impersonation_admin,
            'scopes'  => [ 'https://www.googleapis.com/auth/drive' ],
        ];
    }

    public static function googleAccessToken( array $cfg ): array {
        $service      = (array) ( $cfg['service'] ?? [] );
        $client_email = (string) ( $service['client_email'] ?? '' );
        $private_key  = (string) ( $service['private_key'] ?? '' );
        $token_uri    = (string) ( $service['token_uri'] ?? 'https://oauth2.googleapis.com/token' );
        $subject      = (string) ( $cfg['subject'] ?? '' );
        $scopes       = (array) ( $cfg['scopes'] ?? [] );
        if ( $client_email === '' || $private_key === '' || $subject === '' || empty( $scopes ) ) {
            return [ 'ok' => false, 'error' => 'Workspace OAuth configuration is incomplete.' ];
        }

        $cache_key = 'metis_board_ws_token_' . md5( $client_email . '|' . $subject . '|' . implode( ' ', $scopes ) );
        $cached    = \metis_get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached['access_token'] ) ) {
            return [ 'ok' => true, 'access_token' => (string) $cached['access_token'] ];
        }

        $header    = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
        $now       = time();
        $claims    = [
            'iss'   => $client_email,
            'scope' => implode( ' ', $scopes ),
            'aud'   => $token_uri,
            'iat'   => $now,
            'exp'   => $now + 3600,
            'sub'   => $subject,
        ];
        $jwt_input = self::b64urlEncode( \metis_json_encode( $header ) ) . '.' . self::b64urlEncode( \metis_json_encode( $claims ) );
        $signature = '';
        $signed    = openssl_sign( $jwt_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
        if ( ! $signed ) {
            return [ 'ok' => false, 'error' => 'Could not sign Workspace JWT assertion.' ];
        }

        $assertion = $jwt_input . '.' . self::b64urlEncode( $signature );
        $response  = \metis_runtime_remote_post( $token_uri, [
            'timeout' => 20,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ],
        ] );

        if ( \metis_runtime_is_error( $response ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token request failed.' ];
        }

        $code = (int) \metis_runtime_remote_retrieve_response_code( $response );
        $body = json_decode( (string) \metis_runtime_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token request failed (' . $code . ').' ];
        }

        $access_token = (string) $body['access_token'];
        $ttl          = max( 120, ( (int) ( $body['expires_in'] ?? 3600 ) ) - 60 );
        \metis_set_transient( $cache_key, [ 'access_token' => $access_token ], $ttl );

        return [ 'ok' => true, 'access_token' => $access_token ];
    }

    public static function googleRequest( string $method, string $url, ?array $body, array $cfg, array $extra_headers = [] ): array {
        $token = self::googleAccessToken( $cfg );
        if ( empty( $token['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token error.' ];
        }

        $headers = array_merge( [
            'Authorization' => 'Bearer ' . (string) $token['access_token'],
            'Content-Type'  => 'application/json',
        ], $extra_headers );

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 30,
            'headers' => $headers,
        ];

        if ( $body !== null && ! isset( $extra_headers['Content-Type'] ) ) {
            $args['body'] = \metis_json_encode( $body );
        } elseif ( $body !== null ) {
            $args['body'] = (string) ( $body['raw_body'] ?? '' );
        }

        $response = \metis_runtime_remote_request( $url, $args );
        if ( \metis_runtime_is_error( $response ) ) {
            return [ 'ok' => false, 'error' => 'Google API request failed.' ];
        }

        $code    = (int) \metis_runtime_remote_retrieve_response_code( $response );
        $raw     = (string) \metis_runtime_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            return [ 'ok' => false, 'error' => 'Google API request failed (' . $code . ').', 'status' => $code, 'raw' => $raw ];
        }

        return [ 'ok' => true, 'status' => $code, 'body' => is_array( $decoded ) ? $decoded : [] ];
    }

    public static function sharedDriveId(): string {
        return trim( (string) \Core_Settings_Service::get( 'workspace_shared_drive_id', '' ) );
    }

    public static function fetchDriveFolderSummary( string $folder_id ): array {
        $id = trim( $folder_id );
        if ( $id === '' ) {
            return [ 'ok' => false, 'error' => 'Drive folder is required.' ];
        }

        $url = 'files/' . rawurlencode( $id ) . '?fields=id,name,webViewLink&supportsAllDrives=true';
        $resp = \metis_board_drive_request( 'GET', $url, null );
        if ( empty( $resp['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Failed to fetch Drive folder.' ];
        }

        $folder = (array) ( $resp['body'] ?? [] );
        return [
            'ok' => true,
            'id' => (string) ( $folder['id'] ?? $id ),
            'name' => trim( (string) ( $folder['name'] ?? '' ) ) !== '' ? trim( (string) $folder['name'] ) : 'Drive folder',
            'url' => (string) ( $folder['webViewLink'] ?? '' ),
        ];
    }

    public static function ensureNamedFolder( string $name, string $parent_id, string $shared_drive_id ): array {
        $name = trim( $name );
        if ( $name === '' || $parent_id === '' || $shared_drive_id === '' ) {
            return [ 'ok' => false, 'error' => 'Folder name, parent, and drive are required.' ];
        }

        $q = "trashed = false and mimeType = 'application/vnd.google-apps.folder' and '" . str_replace( "'", "\\'", $parent_id ) . "' in parents and name = '" . str_replace( "'", "\\'", $name ) . "'";
        $lookup_url = \metis_add_query_arg( [
            'q' => $q,
            'corpora' => 'drive',
            'driveId' => $shared_drive_id,
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives' => 'true',
            'useDomainAdminAccess' => 'true',
            'pageSize' => 1,
            'fields' => 'files(id,name,webViewLink)',
        ], 'https://www.googleapis.com/drive/v3/files' );
        $lookup = \metis_board_drive_request( 'GET', $lookup_url, null );
        if ( empty( $lookup['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Failed to find folder.' ];
        }

        $match = (array) ( ( $lookup['body']['files'][0] ?? [] ) );
        if ( ! empty( $match['id'] ) ) {
            $folder_id = (string) $match['id'];
            return [
                'ok' => true,
                'folder_id' => $folder_id,
                'folder_url' => 'https://drive.google.com/drive/folders/' . rawurlencode( $folder_id ),
                'created' => false,
            ];
        }

        $create_payload = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [ $parent_id ],
            'driveId' => $shared_drive_id,
        ];
        $create_url = \metis_add_query_arg( [ 'supportsAllDrives' => 'true' ], 'https://www.googleapis.com/drive/v3/files' );
        $created = \metis_board_drive_request( 'POST', $create_url, $create_payload );
        if ( empty( $created['ok'] ) || empty( $created['body']['id'] ) ) {
            return [ 'ok' => false, 'error' => 'Failed to create folder.' ];
        }

        $folder_id = (string) $created['body']['id'];
        return [
            'ok' => true,
            'folder_id' => $folder_id,
            'folder_url' => 'https://drive.google.com/drive/folders/' . rawurlencode( $folder_id ),
            'created' => true,
        ];
    }

    public static function prepareWorkspaceFolders( int $meeting_id ): array {
        $db = \metis_db();
        $meetings_table = \Metis_Tables::get( 'board_meetings' );
        $meeting = $db->fetchOne( "SELECT id, meeting_code, title, meeting_date FROM {$meetings_table} WHERE id = %d LIMIT 1", [ $meeting_id ] );
        if ( ! $meeting ) {
            return [ 'ok' => false, 'error' => 'Meeting not found.' ];
        }

        $shared_drive_id = self::sharedDriveId();
        if ( $shared_drive_id === '' ) {
            return [ 'ok' => false, 'error' => 'Shared Drive ID is not configured in Settings.' ];
        }

        $meeting_ts = strtotime( (string) ( $meeting['meeting_date'] ?? '' ) );
        if ( ! $meeting_ts ) {
            return [ 'ok' => false, 'error' => 'Meeting date is invalid.' ];
        }
        $year = \metis_runtime_date( 'Y', $meeting_ts, \metis_runtime_timezone() );
        $month = \metis_runtime_date( 'Y-m', $meeting_ts, \metis_runtime_timezone() );

        $root = self::ensureNamedFolder( '01 Board Meetings', $shared_drive_id, $shared_drive_id );
        if ( empty( $root['ok'] ) ) return $root;
        $year_folder = self::ensureNamedFolder( $year, (string) $root['folder_id'], $shared_drive_id );
        if ( empty( $year_folder['ok'] ) ) return $year_folder;
        $month_folder = self::ensureNamedFolder( $month, (string) $year_folder['folder_id'], $shared_drive_id );
        if ( empty( $month_folder['ok'] ) ) return $month_folder;
        $agenda_folder = self::ensureNamedFolder( 'Agenda', (string) $month_folder['folder_id'], $shared_drive_id );
        if ( empty( $agenda_folder['ok'] ) ) return $agenda_folder;
        $packet_folder = self::ensureNamedFolder( 'Packet', (string) $month_folder['folder_id'], $shared_drive_id );
        if ( empty( $packet_folder['ok'] ) ) return $packet_folder;
        $minutes_folder = self::ensureNamedFolder( 'Minutes', (string) $month_folder['folder_id'], $shared_drive_id );
        if ( empty( $minutes_folder['ok'] ) ) return $minutes_folder;
        $financials_folder = self::ensureNamedFolder( 'Financials', (string) $month_folder['folder_id'], $shared_drive_id );
        if ( empty( $financials_folder['ok'] ) ) return $financials_folder;
        $supporting_folder = self::ensureNamedFolder( 'Supporting Docs', (string) $month_folder['folder_id'], $shared_drive_id );
        if ( empty( $supporting_folder['ok'] ) ) return $supporting_folder;

        $meeting_payload = [
            'google_drive_folder_id' => (string) $month_folder['folder_id'],
            'google_drive_folder_url' => (string) $month_folder['folder_url'],
        ];
        $meeting_formats = [ '%s', '%s' ];
        if ( \metis_board_table_has_column( $meetings_table, 'google_drive_folder_name' ) ) {
            $meeting_payload['google_drive_folder_name'] = (string) $month;
            $meeting_formats[] = '%s';
        }
        $db->update( $meetings_table, $meeting_payload, [ 'id' => $meeting_id ], $meeting_formats, [ '%d' ] );

        return [
            'ok' => true,
            'meeting' => $meeting,
            'root' => $root,
            'year' => $year_folder,
            'month' => $month_folder,
            'agenda' => $agenda_folder,
            'packet' => $packet_folder,
            'minutes' => $minutes_folder,
            'financials' => $financials_folder,
            'supporting' => $supporting_folder,
        ];
    }

    public static function listDriveFiles( int $meeting_id, string $folder_id, string $search ): array {
        $db = \metis_db();
        $meetings_table = \Metis_Tables::get( 'board_meetings' );

        $folder_id = trim( $folder_id );
        $search = trim( $search );
        if ( $folder_id === '' && $meeting_id > 0 ) {
            $folder_id = (string) $db->scalar( "SELECT google_drive_folder_id FROM {$meetings_table} WHERE id = %d LIMIT 1", [ $meeting_id ] );
        }
        if ( $folder_id === '' ) {
            $folder_id = 'root';
        }

        $q_parts = [ 'trashed = false' ];
        if ( $folder_id !== '' ) {
            $q_parts[] = "'" . str_replace( "'", "\\'", $folder_id ) . "' in parents";
        }
        if ( $search !== '' ) {
            $q_parts[] = "name contains '" . str_replace( "'", "\\'", $search ) . "'";
        }
        $query = implode( ' and ', $q_parts );
        $url = \metis_add_query_arg( [
            'q' => $query,
            'pageSize' => 200,
            'orderBy' => 'folder,name',
            'fields' => 'files(id,name,mimeType,webViewLink,modifiedTime,size,parents)',
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives' => 'true',
        ], 'https://www.googleapis.com/drive/v3/files' );

        $resp = \metis_board_drive_request( 'GET', $url, null );
        if ( empty( $resp['ok'] ) ) {
            \metis_runtime_send_json_error( 'Failed to list Drive files.', 500 );
        }

        $files = [];
        foreach ( (array) ( ( $resp['body']['files'] ?? [] ) ) as $file ) {
            if ( ! is_array( $file ) ) {
                continue;
            }
            $files[] = [
                'id' => (string) ( $file['id'] ?? '' ),
                'name' => (string) ( $file['name'] ?? '' ),
                'mimeType' => (string) ( $file['mimeType'] ?? '' ),
                'isFolder' => ( (string) ( $file['mimeType'] ?? '' ) ) === 'application/vnd.google-apps.folder',
                'webViewLink' => (string) ( $file['webViewLink'] ?? '' ),
                'modifiedTime' => (string) ( $file['modifiedTime'] ?? '' ),
                'size' => (string) ( $file['size'] ?? '' ),
            ];
        }

        $parent_id = '';
        $folder_name = '';
        $path_segments = [];
        $meeting_folder_id = '';
        if ( $meeting_id > 0 ) {
            $meeting_folder_id = trim( (string) $db->scalar( "SELECT google_drive_folder_id FROM {$meetings_table} WHERE id = %d LIMIT 1", [ $meeting_id ] ) );
        }
        if ( $folder_id !== '' && $folder_id !== 'root' ) {
            $folder_url = \metis_add_query_arg( [
                'fields' => 'id,name,parents',
                'supportsAllDrives' => 'true',
            ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $folder_id ) );
            $parent_resp = \metis_board_drive_request( 'GET', $folder_url, null );
            if ( ! empty( $parent_resp['ok'] ) ) {
                $parents = (array) ( ( $parent_resp['body']['parents'] ?? [] ) );
                $parent_id = ! empty( $parents ) ? (string) $parents[0] : '';
                $folder_name = (string) ( ( $parent_resp['body']['name'] ?? '' ) );
                if ( $folder_name !== '' ) {
                    $path_segments[] = $folder_name;
                }
                $current_id = $parent_id;
                $loop_guard = 0;
                while ( $current_id !== '' && $loop_guard < 12 ) {
                    $loop_guard++;
                    if ( $meeting_folder_id !== '' && $current_id === $meeting_folder_id ) {
                        $meeting_name = '';
                        if ( \metis_board_table_has_column( $meetings_table, 'google_drive_folder_name' ) ) {
                            $meeting_name = trim( (string) $db->scalar( "SELECT google_drive_folder_name FROM {$meetings_table} WHERE id = %d LIMIT 1", [ $meeting_id ] ) );
                        }
                        if ( $meeting_name === '' ) {
                            $meeting_meta_url = \metis_add_query_arg( [
                                'fields' => 'id,name',
                                'supportsAllDrives' => 'true',
                            ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $current_id ) );
                            $meeting_meta_resp = \metis_board_drive_request( 'GET', $meeting_meta_url, null );
                            if ( ! empty( $meeting_meta_resp['ok'] ) ) {
                                $meeting_name = trim( (string) ( ( $meeting_meta_resp['body']['name'] ?? '' ) ) );
                            }
                        }
                        if ( $meeting_name !== '' ) {
                            array_unshift( $path_segments, $meeting_name );
                        }
                        break;
                    }
                    $meta_url = \metis_add_query_arg( [
                        'fields' => 'id,name,parents',
                        'supportsAllDrives' => 'true',
                    ], 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $current_id ) );
                    $meta_resp = \metis_board_drive_request( 'GET', $meta_url, null );
                    if ( empty( $meta_resp['ok'] ) ) {
                        break;
                    }
                    $meta = (array) ( $meta_resp['body'] ?? [] );
                    $meta_name = trim( (string) ( $meta['name'] ?? '' ) );
                    if ( $meta_name !== '' ) {
                        array_unshift( $path_segments, $meta_name );
                    }
                    $next_parents = (array) ( $meta['parents'] ?? [] );
                    $current_id = ! empty( $next_parents ) ? (string) $next_parents[0] : '';
                }
            }
        }

        return [
            'meeting_id' => $meeting_id,
            'folder_id' => $folder_id,
            'folder_name' => $folder_name,
            'folder_path' => implode( ' > ', array_values( array_filter( $path_segments, static function ( $segment ): bool {
                return trim( (string) $segment ) !== '';
            } ) ) ),
            'parent_id' => $parent_id,
            'files' => $files,
        ];
    }

    public static function createDriveFolder( int $meeting_id, string $parent_id, string $folder_name, bool $set_as_meeting ): array {
        $folder_name = trim( $folder_name );
        if ( $folder_name === '' ) {
            \metis_runtime_send_json_error( 'Folder name is required.', 422 );
        }

        $payload = [
            'name' => $folder_name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [ trim( $parent_id ) !== '' ? trim( $parent_id ) : 'root' ],
        ];
        $url = \metis_add_query_arg( [ 'supportsAllDrives' => 'true' ], 'https://www.googleapis.com/drive/v3/files' );
        $resp = \metis_board_drive_request( 'POST', $url, $payload );
        if ( empty( $resp['ok'] ) ) {
            \metis_runtime_send_json_error( 'Failed to create folder.', 500 );
        }

        $folder_id = (string) ( ( $resp['body']['id'] ?? '' ) );
        $folder_link = 'https://drive.google.com/drive/folders/' . rawurlencode( $folder_id );
        if ( $set_as_meeting && $meeting_id > 0 && $folder_id !== '' ) {
            self::assignMeetingFolder( $meeting_id, $folder_id, $folder_name );
        }

        return [
            'folder_id' => $folder_id,
            'folder_link' => $folder_link,
            'name' => $folder_name,
        ];
    }

    public static function assignMeetingFolder( int $meeting_id, string $folder_id, string $folder_name ): array {
        if ( $meeting_id < 1 || trim( $folder_id ) === '' ) {
            \metis_runtime_send_json_error( 'Meeting and folder are required.', 422 );
        }

        $db = \metis_db();
        $meetings_table = \Metis_Tables::get( 'board_meetings' );
        $folder_url = 'https://drive.google.com/drive/folders/' . rawurlencode( $folder_id );
        $meeting_payload = [
            'google_drive_folder_id' => $folder_id,
            'google_drive_folder_url' => $folder_url,
        ];
        $meeting_formats = [ '%s', '%s' ];
        if ( \metis_board_table_has_column( $meetings_table, 'google_drive_folder_name' ) ) {
            $meeting_payload['google_drive_folder_name'] = trim( $folder_name ) !== '' ? trim( $folder_name ) : 'Drive folder';
            $meeting_formats[] = '%s';
        }
        $ok = $db->update( $meetings_table, $meeting_payload, [ 'id' => $meeting_id ], $meeting_formats, [ '%d' ] );
        if ( $ok === false ) {
            \Metis_Logger::error( 'Board folder assign failed', [
                'meeting_id' => $meeting_id,
                'folder_id' => $folder_id,
                'db_error' => $db->lastError(),
            ] );
            \metis_runtime_send_json_error( 'Failed to set meeting folder.', 500 );
        }

        return [
            'folder_id' => $folder_id,
            'folder_url' => $folder_url,
            'folder_name' => trim( $folder_name ) !== '' ? trim( $folder_name ) : 'Drive folder',
        ];
    }
}
