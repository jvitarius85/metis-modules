<?php
if ( ! defined( 'METIS_ROOT' ) ) exit;

function metis_drive_google_access_token( array $cfg ): array {
    $service     = (array) ( $cfg['service'] ?? [] );
    $client_email = (string) ( $service['client_email'] ?? '' );
    $private_key  = (string) ( $service['private_key'] ?? '' );
    $token_uri    = (string) ( $service['token_uri'] ?? 'https://oauth2.googleapis.com/token' );
    $subject      = (string) ( $cfg['subject'] ?? '' );
    $scopes       = (array)  ( $cfg['scopes'] ?? [] );
    if ( $client_email === '' || $private_key === '' || $subject === '' || empty( $scopes ) ) {
        return [ 'ok' => false, 'error' => 'Workspace OAuth configuration is incomplete.' ];
    }
    $cache_key = 'metis_drive_token_' . md5( $client_email . '|' . $subject . '|' . implode( ' ', $scopes ) );
    $cached    = metis_get_transient( $cache_key );
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
    $jwt_input = metis_drive_b64url_encode( metis_json_encode( $header ) ) . '.' . metis_drive_b64url_encode( metis_json_encode( $claims ) );
    $signature = '';
    $signed    = openssl_sign( $jwt_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
    if ( ! $signed ) return [ 'ok' => false, 'error' => 'Could not sign Workspace JWT assertion.' ];
    $assertion = $jwt_input . '.' . metis_drive_b64url_encode( $signature );
    $response  = metis_runtime_remote_post( $token_uri, [
        'timeout' => 20,
        'body'    => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ],
    ] );
    if ( metis_runtime_is_error( $response ) ) return [ 'ok' => false, 'error' => 'Workspace token request failed.' ];
    $code = (int) metis_runtime_remote_retrieve_response_code( $response );
    $body = json_decode( (string) metis_runtime_remote_retrieve_body( $response ), true );
    if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
        return [ 'ok' => false, 'error' => 'Workspace token request failed (' . $code . ').' ];
    }
    $access_token = (string) $body['access_token'];
    $ttl          = max( 120, ( (int) ( $body['expires_in'] ?? 3600 ) ) - 60 );
    metis_set_transient( $cache_key, [ 'access_token' => $access_token ], $ttl );
    return [ 'ok' => true, 'access_token' => $access_token ];
}

function metis_drive_google_request( string $method, string $url, ?string $raw_body, array $cfg, array $headers = [] ): array {
    $token = metis_drive_google_access_token( $cfg );
    if ( empty( $token['ok'] ) ) return [ 'ok' => false, 'error' => 'Workspace token error.' ];
    $request_headers = array_merge( [
        'Authorization' => 'Bearer ' . (string) $token['access_token'],
        'Content-Type'  => 'application/json',
    ], $headers );
    $args = [ 'method' => strtoupper( $method ), 'timeout' => 45, 'headers' => $request_headers ];
    if ( $raw_body !== null ) $args['body'] = $raw_body;
    $response = metis_runtime_remote_request( $url, $args );
    if ( metis_runtime_is_error( $response ) ) {
        return [ 'ok' => false, 'error' => 'Google Drive API request failed.' ];
    }
    $code    = (int) metis_runtime_remote_retrieve_response_code( $response );
    $raw     = (string) metis_runtime_remote_retrieve_body( $response );
    $decoded = json_decode( $raw, true );
    if ( $code < 200 || $code >= 300 ) {
        return [ 'ok' => false, 'error' => 'Google Drive API request failed (' . $code . ').', 'status' => $code, 'raw' => $raw ];
    }
    return [ 'ok' => true, 'status' => $code, 'body' => is_array( $decoded ) ? $decoded : [] ];
}

function metis_drive_list_shared_drives( array $cfg ): array {
    $drives     = [];
    $page_token = '';
    do {
        $params = [ 'pageSize' => 100, 'fields' => 'drives(id,name),nextPageToken', 'useDomainAdminAccess' => 'true' ];
        if ( $page_token !== '' ) $params['pageToken'] = $page_token;
        $url  = metis_add_query_arg( $params, 'https://www.googleapis.com/drive/v3/drives' );
        $resp = metis_drive_google_request( 'GET', $url, null, $cfg );
        if ( empty( $resp['ok'] ) ) return [ 'ok' => false, 'error' => 'Failed to load shared drives.' ];
        foreach ( (array) ( $resp['body']['drives'] ?? [] ) as $drive ) {
            $id   = (string) ( $drive['id'] ?? '' );
            $name = (string) ( $drive['name'] ?? '' );
            if ( $id === '' || $name === '' ) continue;
            $drives[] = [ 'id' => $id, 'name' => $name ];
        }
        $page_token = (string) ( $resp['body']['nextPageToken'] ?? '' );
    } while ( $page_token !== '' );
    return [ 'ok' => true, 'drives' => $drives ];
}
