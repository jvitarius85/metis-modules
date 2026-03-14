<?php
declare(strict_types=1);

namespace Metis\Modules\Calendar;

final class GoogleCalendarService {
    public static function b64urlEncode( string $value ): string {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
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

        $cache_key = 'metis_calendar_token_' . md5( $client_email . '|' . $subject . '|' . implode( ' ', $scopes ) );
        $cached    = \get_transient( $cache_key );
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
        $response  = \metis_remote_post( $token_uri, [
            'timeout' => 20,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ],
        ] );

        if ( \metis_is_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }

        $code = (int) \metis_remote_retrieve_response_code( $response );
        $body = json_decode( (string) \metis_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token request failed (' . $code . ').' ];
        }

        $access_token = (string) $body['access_token'];
        $ttl          = max( 120, ( (int) ( $body['expires_in'] ?? 3600 ) ) - 60 );
        \set_transient( $cache_key, [ 'access_token' => $access_token ], $ttl );

        return [ 'ok' => true, 'access_token' => $access_token ];
    }

    public static function googleRequest( string $method, string $url, ?string $raw_body, array $cfg ): array {
        $token = self::googleAccessToken( $cfg );
        if ( empty( $token['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $token['error'] ?? 'Workspace token error.' ) ];
        }

        $args = [
            'method'  => strtoupper( $method ),
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) $token['access_token'],
                'Content-Type'  => 'application/json',
            ],
        ];

        if ( $raw_body !== null ) {
            $args['body'] = $raw_body;
        }

        $response = \metis_remote_request( $url, $args );
        if ( \metis_is_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }

        $code    = (int) \metis_remote_retrieve_response_code( $response );
        $raw     = (string) \metis_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            $msg    = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? '' ) : '';
            $reason = '';
            if ( is_array( $decoded ) && ! empty( $decoded['error']['errors'][0] ) && is_array( $decoded['error']['errors'][0] ) ) {
                $first = (array) $decoded['error']['errors'][0];
                $r     = (string) ( $first['reason'] ?? '' );
                $loc   = (string) ( $first['location'] ?? '' );
                if ( $r !== '' && $loc !== '' ) {
                    $reason = $r . ' at ' . $loc;
                } elseif ( $r !== '' ) {
                    $reason = $r;
                }
            }
            if ( $msg === '' ) {
                $msg = 'Google Calendar API request failed (' . $code . ').';
            }
            if ( $reason !== '' ) {
                $msg .= ' [' . $reason . ']';
            }
            return [ 'ok' => false, 'error' => $msg, 'status' => $code, 'raw' => $raw ];
        }

        return [ 'ok' => true, 'status' => $code, 'body' => is_array( $decoded ) ? $decoded : [] ];
    }

    public static function getCalendarMeta( array $cfg, bool $allow_remote = false ): array {
        if ( empty( $cfg['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $cfg['error'] ?? 'Calendar is not configured.' ) ];
        }

        $cache_key = 'metis_calendar_meta_' . md5( (string) ( $cfg['calendar_id'] ?? '' ) );
        $cached    = \get_transient( $cache_key );
        if ( is_array( $cached ) && ! empty( $cached['summary'] ) ) {
            return [ 'ok' => true, 'summary' => (string) $cached['summary'], 'time_zone' => (string) ( $cached['time_zone'] ?? '' ) ];
        }

        $db_cached = SyncStore::cachedCalendarMeta( $cfg );
        if ( ! empty( $db_cached['summary'] ) ) {
            return $db_cached;
        }

        if ( ! $allow_remote ) {
            return [
                'ok'        => false,
                'error'     => 'Calendar metadata is not cached yet.',
                'summary'   => (string) ( $cfg['calendar_name'] ?? $cfg['calendar_label'] ?? $cfg['calendar_id'] ?? '' ),
                'time_zone' => '',
            ];
        }

        $url  = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( (string) $cfg['calendar_id'] );
        $resp = self::googleRequest( 'GET', $url, null, $cfg );
        if ( empty( $resp['ok'] ) ) {
            return [
                'ok'        => false,
                'error'     => (string) ( $resp['error'] ?? 'Failed to load calendar metadata.' ),
                'summary'   => (string) ( $cfg['calendar_id'] ?? '' ),
                'time_zone' => '',
            ];
        }

        $body      = (array) ( $resp['body'] ?? [] );
        $summary   = trim( (string) ( $body['summary'] ?? '' ) );
        $time_zone = trim( (string) ( $body['timeZone'] ?? '' ) );
        $payload   = [
            'summary'   => $summary !== '' ? $summary : (string) ( $cfg['calendar_id'] ?? '' ),
            'time_zone' => $time_zone,
        ];

        \set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );
        $state = SyncStore::syncState( (string) ( $cfg['calendar_id'] ?? '' ) );
        SyncStore::updateSyncState( (string) ( $cfg['calendar_id'] ?? '' ), [
            'calendar_name'     => (string) $payload['summary'],
            'last_synced_at'    => (string) ( $state['last_synced_at'] ?? '' ),
            'last_requested_at' => \current_time( 'mysql' ),
            'sync_status'       => (string) ( $state['sync_status'] ?? 'idle' ),
            'item_count'        => (int) ( $state['item_count'] ?? 0 ),
            'last_error'        => (string) ( $state['last_error'] ?? '' ),
        ] );

        return [ 'ok' => true ] + $payload;
    }

    public static function listCalendars( array $cfg ): array {
        $items      = [];
        $page_token = '';

        do {
            $params = [
                'minAccessRole' => 'reader',
                'showHidden'    => 'true',
                'showDeleted'   => 'false',
                'maxResults'    => 250,
            ];

            if ( $page_token !== '' ) {
                $params['pageToken'] = $page_token;
            }

            $url  = \add_query_arg( $params, 'https://www.googleapis.com/calendar/v3/users/me/calendarList' );
            $resp = self::googleRequest( 'GET', $url, null, $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => (string) ( $resp['error'] ?? 'Failed to load calendars.' ) ];
            }

            foreach ( (array) ( $resp['body']['items'] ?? [] ) as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $id      = trim( (string) ( $item['id'] ?? '' ) );
                $summary = trim( (string) ( $item['summary'] ?? '' ) );
                if ( $id === '' ) {
                    continue;
                }
                $items[] = [
                    'id'   => $id,
                    'name' => $summary !== '' ? $summary : $id,
                ];
            }

            $page_token = trim( (string) ( $resp['body']['nextPageToken'] ?? '' ) );
        } while ( $page_token !== '' );

        return [ 'ok' => true, 'calendars' => $items ];
    }

    public static function googleListEvents( array $cfg, string $sync_token = '' ): array {
        $all_items   = [];
        $page_token  = '';
        $resp        = [];

        do {
            $params = [ 'showDeleted' => 'true', 'maxResults' => 250 ];
            if ( $sync_token !== '' ) {
                $params['syncToken'] = $sync_token;
            } else {
                $params['singleEvents'] = 'true';
                $params['orderBy']      = 'startTime';
                $params['timeMin']      = gmdate( 'c', SyncStore::syncWindowStartTs() );
                $params['timeMax']      = gmdate( 'c', SyncStore::syncWindowEndTs() );
            }
            if ( $page_token !== '' ) {
                $params['pageToken'] = $page_token;
            }

            $url  = \add_query_arg( $params, 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( (string) $cfg['calendar_id'] ) . '/events' );
            $resp = self::googleRequest( 'GET', $url, null, $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => (string) ( $resp['error'] ?? 'Failed to load events.' ), 'status' => (int) ( $resp['status'] ?? 0 ) ];
            }

            foreach ( (array) ( $resp['body']['items'] ?? [] ) as $item ) {
                if ( is_array( $item ) ) {
                    $all_items[] = $item;
                }
            }

            $page_token = trim( (string) ( $resp['body']['nextPageToken'] ?? '' ) );
        } while ( $page_token !== '' );

        return [ 'ok' => true, 'items' => $all_items, 'next_sync_token' => trim( (string) ( $resp['body']['nextSyncToken'] ?? '' ) ) ];
    }
}
