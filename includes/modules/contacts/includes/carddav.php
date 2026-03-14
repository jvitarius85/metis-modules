<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function metis_contacts_carddav_log_event( string $level, string $message, array $context = [] ): void {
    if ( ! class_exists( 'Metis_Logger' ) ) {
        return;
    }

    $context['component'] = 'carddav';

    switch ( strtoupper( $level ) ) {
        case 'ERROR':
            Metis_Logger::error( $message, $context );
            break;
        case 'WARN':
            Metis_Logger::warn( $message, $context );
            break;
        default:
            Metis_Logger::info( $message, $context );
            break;
    }
}

function metis_contacts_carddav_request_context( ?Metis_Http_Request $request = null, array $extra = [] ): array {
    $path = $request instanceof Metis_Http_Request ? $request->path() : metis_request_path_relative_to_site();
    $method = $request instanceof Metis_Http_Request ? strtoupper( $request->method() ) : strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
    $headers = $request instanceof Metis_Http_Request ? $request->headers() : [];
    $server  = $request instanceof Metis_Http_Request ? $request->server() : $_SERVER;
    $authorization = $request instanceof Metis_Http_Request ? $request->header( 'authorization' ) : (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );

    return array_merge(
        [
            'method'                       => $method,
            'path'                         => '/' . ltrim( (string) $path, '/' ),
            'remote_addr'                  => sanitize_text_field( (string) ( $server['REMOTE_ADDR'] ?? '' ) ),
            'user_agent'                   => sanitize_text_field( substr( (string) ( $server['HTTP_USER_AGENT'] ?? '' ), 0, 255 ) ),
            'has_authorization_header'     => $authorization !== '',
            'authorization_scheme'         => $authorization !== '' ? strtolower( strtok( $authorization, ' ' ) ?: '' ) : '',
            'server_http_authorization'    => ! empty( $server['HTTP_AUTHORIZATION'] ),
            'server_redirect_authorization'=> ! empty( $server['REDIRECT_HTTP_AUTHORIZATION'] ),
            'server_php_auth_user'         => ! empty( $server['PHP_AUTH_USER'] ),
            'server_php_auth_pw'           => ! empty( $server['PHP_AUTH_PW'] ),
            'header_names'                 => implode( ',', array_keys( is_array( $headers ) ? $headers : [] ) ),
        ],
        $extra
    );
}

function metis_contacts_carddav_requested_props( string $xml ): array {
    $props = [];
    if ( preg_match_all( '#<([a-z0-9_-]+:)?([a-z0-9_-]+)(?:\s[^>]*)?/>#i', $xml, $matches ) ) {
        foreach ( (array) $matches[2] as $name ) {
            $name = strtolower( trim( (string) $name ) );
            if ( $name !== '' && ! in_array( $name, [ 'prop', 'allprop', 'propname' ], true ) ) {
                $props[] = $name;
            }
        }
    }
    return array_values( array_unique( $props ) );
}

function metis_contacts_carddav_requested_prop_tags( string $xml ): array {
    $tags = [];
    if ( preg_match_all( '#<([a-z0-9_-]+:)?([a-z0-9_-]+)(?:\s[^>]*)?/>#i', $xml, $matches, PREG_SET_ORDER ) ) {
        foreach ( $matches as $match ) {
            $prefix = isset( $match[1] ) ? rtrim( (string) $match[1], ':' ) : 'd';
            $local  = strtolower( trim( (string) ( $match[2] ?? '' ) ) );
            if ( $local === '' || in_array( $local, [ 'prop', 'allprop', 'propname' ], true ) ) {
                continue;
            }
            if ( ! isset( $tags[ $local ] ) ) {
                $tags[ $local ] = '<' . $prefix . ':' . $local . '/>';
            }
        }
    }
    return $tags;
}

function metis_contacts_carddav_base_path(): string {
    return '/dav';
}

function metis_contacts_carddav_normalize_username( string $username ): string {
    $username = rawurldecode( $username );

    if ( function_exists( 'sanitize_user' ) ) {
        return sanitize_user( $username, true );
    }

    $username = strtolower( trim( $username ) );
    return preg_replace( '/[^a-z0-9_.@-]/', '', $username ) ?: '';
}

function metis_contacts_carddav_is_request( ?Metis_Http_Request $request = null ): bool {
    $path = $request instanceof Metis_Http_Request ? $request->path() : metis_request_path_relative_to_site();
    $path = '/' . ltrim( (string) $path, '/' );
    $base = metis_contacts_carddav_base_path();

    return $path === $base || str_starts_with( $path, $base . '/' ) || in_array( $path, [ '/.well-known/carddav', '/.well-known/caldav' ], true );
}

function metis_contacts_carddav_endpoint_url( string $suffix = '' ): string {
    $base = trailingslashit( home_url( metis_contacts_carddav_base_path() ) );
    return $suffix === '' ? $base : $base . ltrim( $suffix, '/' );
}

function metis_contacts_carddav_hash_token( string $token ): string {
    return hash( 'sha256', 'metis-carddav|' . $token );
}

function metis_contacts_carddav_generate_token( int $length = 40 ): string {
    try {
        return rtrim( strtr( base64_encode( random_bytes( max( 24, $length ) ) ), '+/', '-_' ), '=' );
    } catch ( Exception $e ) {
        return metis_generate_password( $length, false, false );
    }
}

function metis_contacts_carddav_issue_token( int $user_id, string $label = 'CardDAV device' ): array {
    global $wpdb;

    $tokens_table = Metis_Tables::get( 'contact_dav_tokens' );
    $user_id      = max( 0, $user_id );
    $label        = sanitize_text_field( $label );

    if ( $user_id < 1 ) {
        metis_contacts_carddav_log_event( 'WARN', 'CardDAV token issuance rejected: invalid user ID', [ 'user_id' => $user_id, 'label' => $label ] );
        return [ 'ok' => false, 'error' => 'A valid user is required.' ];
    }

    $user = metis_get_user_by( 'id', $user_id );
    if ( ! $user instanceof WP_User ) {
        metis_contacts_carddav_log_event( 'WARN', 'CardDAV token issuance rejected: user not found', [ 'user_id' => $user_id, 'label' => $label ] );
        return [ 'ok' => false, 'error' => 'User not found.' ];
    }

    metis_install_db();

    $token        = metis_contacts_carddav_generate_token();
    $token_prefix = substr( $token, 0, 8 );
    $token_hash   = metis_contacts_carddav_hash_token( $token );

    $inserted = $wpdb->insert(
        $tokens_table,
        [
            'user_id'      => $user_id,
            'label'        => $label !== '' ? $label : 'CardDAV device',
            'token_prefix' => $token_prefix,
            'token_hash'   => $token_hash,
        ],
        [ '%d', '%s', '%s', '%s' ]
    );

    if ( ! $inserted ) {
        metis_contacts_carddav_log_event( 'ERROR', 'CardDAV token issuance failed: database insert failed', [
            'user_id'      => $user_id,
            'username'     => (string) $user->user_login,
            'label'        => $label,
            'token_prefix' => $token_prefix,
        ] );
        return [ 'ok' => false, 'error' => 'Unable to store CardDAV token.' ];
    }

    metis_contacts_carddav_log_event( 'INFO', 'CardDAV token issued', [
        'user_id'      => $user_id,
        'username'     => (string) $user->user_login,
        'label'        => $label,
        'token_id'     => (int) $wpdb->insert_id,
        'token_prefix' => $token_prefix,
    ] );

    return [
        'ok'          => true,
        'token'       => $token,
        'token_id'    => (int) $wpdb->insert_id,
        'token_prefix'=> $token_prefix,
        'username'    => (string) $user->user_login,
        'user_id'     => $user_id,
        'label'       => $label,
    ];
}

function metis_contacts_carddav_revoke_token( int $token_id, int $user_id ): bool {
    global $wpdb;

    $tokens_table = Metis_Tables::get( 'contact_dav_tokens' );
    $updated = $wpdb->update(
        $tokens_table,
        [ 'revoked_at' => current_time( 'mysql' ) ],
        [ 'id' => $token_id, 'user_id' => $user_id ],
        [ '%s' ],
        [ '%d', '%d' ]
    );

    metis_contacts_carddav_log_event(
        $updated !== false ? 'INFO' : 'ERROR',
        $updated !== false ? 'CardDAV token revoked' : 'CardDAV token revoke failed',
        [
            'user_id'  => $user_id,
            'token_id' => $token_id,
        ]
    );

    return $updated !== false;
}

function metis_contacts_carddav_list_tokens( int $user_id ): array {
    global $wpdb;

    $tokens_table = Metis_Tables::get( 'contact_dav_tokens' );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, label, token_prefix, last_used_at, created_at, revoked_at
             FROM {$tokens_table}
             WHERE user_id = %d
             ORDER BY created_at DESC, id DESC",
            $user_id
        ),
        ARRAY_A
    );

    return is_array( $rows ) ? $rows : [];
}

function metis_contacts_carddav_touch_contact( string $cid ): void {
    global $wpdb;

    $contacts_table = Metis_Tables::get( 'contacts' );
    if ( ! metis_contacts_column_exists( $contacts_table, 'updated_at' ) ) {
        return;
    }

    $wpdb->update(
        $contacts_table,
        [ 'updated_at' => current_time( 'mysql' ) ],
        [ 'cid' => $cid ],
        [ '%s' ],
        [ '%s' ]
    );
}

function metis_contacts_carddav_book_slugs_for_contact( object $contact, ?object $details = null ): array {
    global $wpdb;

    $book_slugs = [ 'all' ];
    $cid        = (string) ( $contact->cid ?? '' );
    $contact_id = (int) ( $contact->id ?? 0 );

    if ( trim( (string) ( $contact->did ?? '' ) ) !== '' ) {
        $book_slugs[] = 'donors';
    }

    if ( $details && ! empty( $details->volunteer_status ) ) {
        $book_slugs[] = 'volunteers';
    }

    if ( $contact_id > 0 ) {
        $subs_table  = Metis_Tables::get( 'newsletter_subs' );
        $lists_table = Metis_Tables::get( 'newsletter_lists' );

        if ( metis_contacts_table_exists( $subs_table ) && metis_contacts_table_exists( $lists_table ) ) {
            $list_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ns.list_id
                     FROM {$subs_table} ns
                     INNER JOIN {$lists_table} nl ON nl.id = ns.list_id
                     WHERE ns.contact_id = %d",
                    $contact_id
                )
            );
            if ( is_array( $list_ids ) ) {
                foreach ( $list_ids as $list_id ) {
                    $book_slugs[] = 'list-' . (int) $list_id;
                }
            }
        }
    }

    $book_slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', $book_slugs ) ) ) );
    if ( $cid === '' ) {
        return [ 'all' ];
    }

    return $book_slugs;
}

function metis_contacts_carddav_log_change( string $cid, string $operation = 'upsert', array $book_slugs = [], ?string $etag = null ): void {
    global $wpdb;

    $sync_table = Metis_Tables::get( 'contact_dav_sync' );
    $cid        = sanitize_text_field( $cid );
    $operation  = $operation === 'delete' ? 'delete' : 'upsert';
    $etag       = is_string( $etag ) ? trim( $etag, '"' ) : null;

    if ( $cid === '' ) {
        return;
    }

    if ( empty( $book_slugs ) ) {
        $book_slugs = [ 'all' ];
    }

    foreach ( array_unique( $book_slugs ) as $book_slug ) {
        $book_slug = sanitize_title( (string) $book_slug );
        if ( $book_slug === '' ) {
            continue;
        }

        $wpdb->insert(
            $sync_table,
            [
                'book_slug'    => $book_slug,
                'contact_cid'  => $cid,
                'operation'    => $operation,
                'contact_etag' => $etag,
                'changed_at'   => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
    }
}

function metis_contacts_carddav_current_sync_token( string $book_slug ): string {
    global $wpdb;

    $sync_table = Metis_Tables::get( 'contact_dav_sync' );
    $book_slug  = sanitize_title( $book_slug );
    $sequence   = 0;

    if ( metis_contacts_table_exists( $sync_table ) ) {
        $sequence = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(id) FROM {$sync_table} WHERE book_slug = %s",
                $book_slug
            )
        );
    }

    $stamp = metis_contacts_carddav_book_timestamp( $book_slug );

    return 'metis-sync-' . $book_slug . '-' . $sequence . '-' . $stamp;
}

function metis_contacts_carddav_parse_sync_token( string $token ): array {
    if ( ! preg_match( '/^metis-sync-([a-z0-9\-]+)-(\d+)-(\d+)$/', $token, $matches ) ) {
        return [ 'book_slug' => '', 'sequence' => 0, 'timestamp' => 0 ];
    }

    return [
        'book_slug' => sanitize_title( (string) $matches[1] ),
        'sequence'  => (int) $matches[2],
        'timestamp' => (int) $matches[3],
    ];
}

function metis_contacts_carddav_book_timestamp( string $book_slug ): int {
    $contacts = metis_contacts_carddav_fetch_contacts_for_book( $book_slug );
    $max_ts   = 0;

    foreach ( $contacts as $entry ) {
        $ts = metis_contacts_carddav_contact_updated_timestamp( $entry['contact'], $entry['details'] );
        if ( $ts > $max_ts ) {
            $max_ts = $ts;
        }
    }

    return $max_ts;
}

function metis_contacts_carddav_contact_updated_timestamp( object $contact, ?object $details = null ): int {
    $timestamps = [];

    foreach ( [ $contact->updated_at ?? '', $contact->created_at ?? '', $details->updated_at ?? '', $details->created_at ?? '' ] as $candidate ) {
        $ts = strtotime( (string) $candidate );
        if ( $ts !== false ) {
            $timestamps[] = (int) $ts;
        }
    }

    return empty( $timestamps ) ? time() : max( $timestamps );
}

function metis_contacts_carddav_fetch_contact( string $cid ): ?array {
    global $wpdb;

    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );

    $contact = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$contacts_table} WHERE cid = %s LIMIT 1",
            $cid
        )
    );

    if ( ! $contact ) {
        return null;
    }

    $details_rows = metis_contacts_collect_linked_detail_rows(
        $details_table,
        (string) ( $contact->cid ?? '' ),
        (int) ( $contact->id ?? 0 ),
        (string) ( $contact->did ?? '' )
    );

    return [
        'contact' => $contact,
        'details' => ! empty( $details_rows ) ? $details_rows[0] : null,
    ];
}

function metis_contacts_carddav_fetch_contacts_for_book( string $book_slug ): array {
    global $wpdb;

    metis_contacts_ensure_schema();

    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );
    $subs_table     = Metis_Tables::get( 'newsletter_subs' );
    $book_slug      = sanitize_title( $book_slug );
    $query          = "SELECT c.* FROM {$contacts_table} c";
    $params         = [];
    $where          = [];

    if ( $book_slug === 'donors' ) {
        $where[] = "(c.did IS NOT NULL AND c.did <> '')";
    } elseif ( $book_slug === 'volunteers' ) {
        if ( metis_contacts_table_exists( $details_table ) ) {
            $query .= " INNER JOIN {$details_table} d ON (d.contact_id = c.id OR d.contact_cid = c.cid)";
            $where[] = 'COALESCE(d.volunteer_status, 0) = 1';
        } else {
            return [];
        }
    } elseif ( preg_match( '/^list-(\d+)$/', $book_slug, $matches ) ) {
        $list_id = (int) $matches[1];
        if ( ! metis_contacts_table_exists( $subs_table ) ) {
            return [];
        }
        $query .= " INNER JOIN {$subs_table} ns ON ns.contact_id = c.id";
        $where[] = 'ns.list_id = %d';
        $params[] = $list_id;
    }

    if ( ! empty( $where ) ) {
        $query .= ' WHERE ' . implode( ' AND ', $where );
    }

    $query .= ' GROUP BY c.id ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC';

    if ( ! empty( $params ) ) {
        $contacts = $wpdb->get_results( $wpdb->prepare( $query, ...$params ) );
    } else {
        $contacts = $wpdb->get_results( $query );
    }

    $result = [];
    foreach ( is_array( $contacts ) ? $contacts : [] as $contact ) {
        $details_rows = metis_contacts_collect_linked_detail_rows(
            $details_table,
            (string) ( $contact->cid ?? '' ),
            (int) ( $contact->id ?? 0 ),
            (string) ( $contact->did ?? '' )
        );
        $result[] = [
            'contact' => $contact,
            'details' => ! empty( $details_rows ) ? $details_rows[0] : null,
        ];
    }

    return $result;
}

function metis_contacts_carddav_book_definitions( WP_User $user ): array {
    $books = [
        [
            'slug'         => 'all',
            'display_name' => 'All Contacts',
            'readonly'     => false,
        ],
        [
            'slug'         => 'donors',
            'display_name' => 'Donors',
            'readonly'     => true,
        ],
        [
            'slug'         => 'volunteers',
            'display_name' => 'Volunteers',
            'readonly'     => false,
        ],
    ];

    return $books;
}

function metis_contacts_carddav_find_book( WP_User $user, string $book_slug ): ?array {
    foreach ( metis_contacts_carddav_book_definitions( $user ) as $book ) {
        if ( (string) ( $book['slug'] ?? '' ) === $book_slug ) {
            $book['sync_token'] = metis_contacts_carddav_current_sync_token( $book_slug );
            return $book;
        }
    }

    return null;
}

function metis_contacts_carddav_resource_path( WP_User $user, string $book_slug, string $cid ): string {
    return 'addressbooks/' . rawurlencode( $user->user_login ) . '/' . rawurlencode( $book_slug ) . '/' . rawurlencode( $cid ) . '.vcf';
}

function metis_contacts_carddav_resource_href( WP_User $user, string $book_slug, string $cid ): string {
    return metis_contacts_carddav_endpoint_url( metis_contacts_carddav_resource_path( $user, $book_slug, $cid ) );
}

function metis_contacts_carddav_contact_etag( object $contact, ?object $details = null ): string {
    $parts = [
        (string) ( $contact->cid ?? '' ),
        (string) ( $contact->updated_at ?? '' ),
        (string) ( $details->updated_at ?? '' ),
        (string) ( $contact->first_name ?? '' ),
        (string) ( $contact->last_name ?? '' ),
        (string) ( $contact->email ?? '' ),
        (string) ( $details->phone ?? '' ),
        (string) ( $details->address ?? '' ),
        (string) ( $details->city ?? '' ),
        (string) ( $details->state ?? '' ),
        (string) ( $details->zip ?? '' ),
        (string) ( $details->preferred_name ?? '' ),
        (string) ( $details->additional_emails_json ?? '' ),
    ];

    return '"' . sha1( implode( '|', $parts ) ) . '"';
}

function metis_contacts_carddav_escape_text( string $value ): string {
    $value = str_replace( [ "\\", ';', ',', "\r\n", "\r", "\n" ], [ '\\\\', '\;', '\,', '\n', '\n', '\n' ], $value );
    return trim( $value );
}

function metis_contacts_carddav_unescape_text( string $value ): string {
    return str_replace(
        [ '\n', '\N', '\,', '\;', '\\\\' ],
        [ "\n", "\n", ',', ';', '\\' ],
        $value
    );
}

function metis_contacts_carddav_fold_line( string $line ): string {
    $line = rtrim( $line, "\r\n" );
    $chunks = preg_split( '/(.{1,73})(?:\s|$)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

    if ( ! is_array( $chunks ) || count( $chunks ) <= 1 ) {
        return $line;
    }

    $first = array_shift( $chunks );
    return $first . "\r\n " . implode( "\r\n ", $chunks );
}

function metis_contacts_carddav_contact_to_vcard( object $contact, ?object $details = null ): string {
    $lines = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        'PRODID:-//Metis//CardDAV//EN',
        'UID:' . metis_contacts_carddav_escape_text( (string) ( $contact->cid ?? '' ) ),
        'N:' . metis_contacts_carddav_escape_text( (string) ( $contact->last_name ?? '' ) ) . ';' . metis_contacts_carddav_escape_text( (string) ( $contact->first_name ?? '' ) ) . ';;;',
    ];

    $formatted_name = trim( (string) ( $contact->first_name ?? '' ) . ' ' . (string) ( $contact->last_name ?? '' ) );
    if ( $formatted_name === '' ) {
        $formatted_name = (string) ( $contact->email ?? $contact->cid ?? 'Contact' );
    }
    $lines[] = 'FN:' . metis_contacts_carddav_escape_text( $formatted_name );

    $nickname = trim( (string) ( $details->preferred_name ?? '' ) );
    if ( $nickname !== '' ) {
        $lines[] = 'NICKNAME:' . metis_contacts_carddav_escape_text( $nickname );
    }

    $primary_email = strtolower( trim( (string) ( $contact->email ?? '' ) ) );
    if ( $primary_email !== '' ) {
        $lines[] = 'EMAIL;TYPE=INTERNET;TYPE=PREF:' . metis_contacts_carddav_escape_text( $primary_email );
    }

    $additional = json_decode( (string) ( $details->additional_emails_json ?? '[]' ), true );
    if ( is_array( $additional ) ) {
        foreach ( $additional as $email ) {
            $email = strtolower( trim( sanitize_email( (string) $email ) ) );
            if ( $email !== '' && $email !== $primary_email ) {
                $lines[] = 'EMAIL;TYPE=INTERNET:' . metis_contacts_carddav_escape_text( $email );
            }
        }
    }

    $phone = trim( (string) ( $details->phone ?? '' ) );
    if ( $phone !== '' ) {
        $lines[] = 'TEL;TYPE=CELL:' . metis_contacts_carddav_escape_text( $phone );
    }

    $address = trim( (string) ( $details->address ?? '' ) );
    $city    = trim( (string) ( $details->city ?? '' ) );
    $state   = trim( (string) ( $details->state ?? '' ) );
    $zip     = trim( (string) ( $details->zip ?? '' ) );
    if ( $address !== '' || $city !== '' || $state !== '' || $zip !== '' ) {
        $lines[] = 'ADR;TYPE=HOME:;;'
            . metis_contacts_carddav_escape_text( $address ) . ';'
            . metis_contacts_carddav_escape_text( $city ) . ';'
            . metis_contacts_carddav_escape_text( $state ) . ';'
            . metis_contacts_carddav_escape_text( $zip ) . ';';
    }

    $birthday = trim( (string) ( $details->birthday ?? '' ) );
    if ( $birthday !== '' ) {
        $lines[] = 'BDAY:' . metis_contacts_carddav_escape_text( $birthday );
    }

    $notes = [];
    if ( trim( (string) ( $contact->did ?? '' ) ) !== '' ) {
        $notes[] = 'Donor ID: ' . trim( (string) $contact->did );
    }
    if ( ! empty( $details->preferred_contact_method ) ) {
        $notes[] = 'Preferred contact: ' . trim( (string) $details->preferred_contact_method );
    }
    if ( ! empty( $details->do_not_contact ) ) {
        $notes[] = 'Do not contact';
    }
    if ( ! empty( $details->volunteer_status ) ) {
        $notes[] = 'Volunteer';
    }
    if ( ! empty( $details->anonymous_donor ) ) {
        $notes[] = 'Anonymous donor';
    }
    if ( ! empty( $notes ) ) {
        $lines[] = 'NOTE:' . metis_contacts_carddav_escape_text( implode( "\n", $notes ) );
    }

    $updated_ts = metis_contacts_carddav_contact_updated_timestamp( $contact, $details );
    $lines[] = 'REV:' . gmdate( 'Ymd\THis\Z', $updated_ts );
    $lines[] = 'X-METIS-CID:' . metis_contacts_carddav_escape_text( (string) ( $contact->cid ?? '' ) );
    $lines[] = 'END:VCARD';

    $folded = array_map( 'metis_contacts_carddav_fold_line', $lines );
    return implode( "\r\n", $folded ) . "\r\n";
}

function metis_contacts_carddav_parse_vcard( string $body ): array {
    $body  = str_replace( [ "\r\n", "\r" ], "\n", $body );
    $lines = explode( "\n", $body );
    $unfolded = [];

    foreach ( $lines as $line ) {
        if ( $line === '' ) {
            continue;
        }
        if ( ! empty( $unfolded ) && ( str_starts_with( $line, ' ' ) || str_starts_with( $line, "\t" ) ) ) {
            $unfolded[ count( $unfolded ) - 1 ] .= substr( $line, 1 );
            continue;
        }
        $unfolded[] = trim( $line );
    }

    $data = [
        'uid'                      => '',
        'first_name'               => '',
        'last_name'                => '',
        'formatted_name'           => '',
        'preferred_name'           => '',
        'email'                    => '',
        'additional_emails'        => [],
        'phone'                    => '',
        'address'                  => '',
        'city'                     => '',
        'state'                    => '',
        'zip'                      => '',
        'birthday'                 => '',
        'volunteer_status'         => 0,
        'do_not_contact'           => 0,
        'anonymous_donor'          => 0,
        'preferred_contact_method' => '',
        'did'                      => '',
    ];

    foreach ( $unfolded as $line ) {
        if ( strpos( $line, ':' ) === false ) {
            continue;
        }

        [ $name, $value ] = explode( ':', $line, 2 );
        $upper = strtoupper( trim( $name ) );
        $value = trim( metis_contacts_carddav_unescape_text( $value ) );

        if ( str_starts_with( $upper, 'EMAIL' ) ) {
            $email = strtolower( trim( sanitize_email( $value ) ) );
            if ( $email === '' || ! is_email( $email ) ) {
                continue;
            }
            if ( $data['email'] === '' ) {
                $data['email'] = $email;
            } else {
                $data['additional_emails'][] = $email;
            }
            continue;
        }

        if ( str_starts_with( $upper, 'TEL' ) ) {
            if ( $data['phone'] === '' ) {
                $data['phone'] = sanitize_text_field( $value );
            }
            continue;
        }

        if ( str_starts_with( $upper, 'ADR' ) ) {
            $segments = explode( ';', $value );
            $data['address'] = sanitize_text_field( (string) ( $segments[2] ?? '' ) );
            $data['city']    = sanitize_text_field( (string) ( $segments[3] ?? '' ) );
            $data['state']   = sanitize_text_field( (string) ( $segments[4] ?? '' ) );
            $data['zip']     = sanitize_text_field( (string) ( $segments[5] ?? '' ) );
            continue;
        }

        switch ( $upper ) {
            case 'UID':
            case 'X-METIS-CID':
                $data['uid'] = sanitize_text_field( $value );
                break;
            case 'FN':
                $data['formatted_name'] = sanitize_text_field( $value );
                break;
            case 'N':
                $parts = array_map( 'trim', explode( ';', $value ) );
                $data['last_name']  = sanitize_text_field( (string) ( $parts[0] ?? '' ) );
                $data['first_name'] = sanitize_text_field( (string) ( $parts[1] ?? '' ) );
                break;
            case 'NICKNAME':
                $data['preferred_name'] = sanitize_text_field( $value );
                break;
            case 'BDAY':
                if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
                    $data['birthday'] = $value;
                }
                break;
            case 'NOTE':
                $note_lower = strtolower( $value );
                if ( str_contains( $note_lower, 'do not contact' ) ) {
                    $data['do_not_contact'] = 1;
                }
                if ( str_contains( $note_lower, 'volunteer' ) ) {
                    $data['volunteer_status'] = 1;
                }
                if ( str_contains( $note_lower, 'anonymous donor' ) ) {
                    $data['anonymous_donor'] = 1;
                }
                if ( preg_match( '/donor id:\s*([A-Za-z0-9\-_]+)/i', $value, $matches ) ) {
                    $data['did'] = sanitize_text_field( (string) $matches[1] );
                }
                if ( preg_match( '/preferred contact:\s*([^\n]+)/i', $value, $matches ) ) {
                    $data['preferred_contact_method'] = sanitize_text_field( trim( (string) $matches[1] ) );
                }
                break;
        }
    }

    $data['additional_emails'] = array_values( array_unique( array_filter( $data['additional_emails'] ) ) );
    return $data;
}

function metis_contacts_carddav_upsert_contact_from_vcard( array $payload, string $book_slug, ?string $cid_hint = null ): array {
    global $wpdb;

    metis_contacts_ensure_schema();

    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );
    $subs_table     = Metis_Tables::get( 'newsletter_subs' );

    $cid   = sanitize_text_field( (string) ( $payload['uid'] ?? $cid_hint ?? '' ) );
    $email = strtolower( trim( sanitize_email( (string) ( $payload['email'] ?? '' ) ) ) );
    $first = sanitize_text_field( (string) ( $payload['first_name'] ?? '' ) );
    $last  = sanitize_text_field( (string) ( $payload['last_name'] ?? '' ) );

    if ( $first === '' && $last === '' && ! empty( $payload['formatted_name'] ) ) {
        $parts = preg_split( '/\s+/', trim( (string) $payload['formatted_name'] ) );
        $first = sanitize_text_field( (string) array_shift( $parts ) );
        $last  = sanitize_text_field( trim( implode( ' ', $parts ) ) );
    }

    if ( $email === '' || ! is_email( $email ) ) {
        return [ 'ok' => false, 'status' => 400, 'error' => 'A primary email address is required.' ];
    }

    if ( $first === '' ) {
        $first = 'Contact';
    }

    $existing = null;
    if ( $cid !== '' ) {
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$contacts_table} WHERE cid = %s LIMIT 1",
                $cid
            )
        );
    }
    if ( ! $existing ) {
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$contacts_table} WHERE email = %s LIMIT 1",
                $email
            )
        );
    }

    $is_create = ! $existing;
    if ( $is_create ) {
        $cid = $cid !== '' ? $cid : metis_generate_code( 'CN', $contacts_table, 'cid' );
        $inserted = $wpdb->insert(
            $contacts_table,
            [
                'cid'        => $cid,
                'did'        => (string) ( $payload['did'] ?? '' ),
                'email'      => $email,
                'first_name' => $first,
                'last_name'  => $last,
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( ! $inserted ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Unable to create contact.' ];
        }

        $contact_id = (int) $wpdb->insert_id;
    } else {
        $contact_id = (int) $existing->id;
        $cid        = (string) $existing->cid;

        $email_conflict = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$contacts_table} WHERE email = %s AND id <> %d LIMIT 1",
                $email,
                $contact_id
            )
        );
        if ( $email_conflict > 0 ) {
            return [ 'ok' => false, 'status' => 409, 'error' => 'That email belongs to another contact.' ];
        }

        $updated = $wpdb->update(
            $contacts_table,
            [
                'email'      => $email,
                'first_name' => $first,
                'last_name'  => $last,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $contact_id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( $updated === false ) {
            return [ 'ok' => false, 'status' => 500, 'error' => 'Unable to update contact.' ];
        }
    }

    $detail = null;
    $detail_rows = metis_contacts_collect_linked_detail_rows( $details_table, $cid, $contact_id, (string) ( $payload['did'] ?? '' ) );
    if ( ! empty( $detail_rows ) ) {
        $detail = $detail_rows[0];
    }

    $additional = metis_contacts_normalize_additional_emails( (array) ( $payload['additional_emails'] ?? [] ) );
    $detail_payload = [];
    $detail_format  = [];
    $field_map = [
        'contact_cid'               => [ $cid, '%s' ],
        'contact_id'                => [ $contact_id, '%d' ],
        'phone'                     => [ metis_contacts_format_phone_us( (string) ( $payload['phone'] ?? '' ) ), '%s' ],
        'preferred_name'            => [ sanitize_text_field( (string) ( $payload['preferred_name'] ?? '' ) ), '%s' ],
        'preferred_contact_method'  => [ sanitize_text_field( (string) ( $payload['preferred_contact_method'] ?? '' ) ), '%s' ],
        'address'                   => [ sanitize_text_field( (string) ( $payload['address'] ?? '' ) ), '%s' ],
        'city'                      => [ sanitize_text_field( (string) ( $payload['city'] ?? '' ) ), '%s' ],
        'state'                     => [ sanitize_text_field( (string) ( $payload['state'] ?? '' ) ), '%s' ],
        'zip'                       => [ sanitize_text_field( (string) ( $payload['zip'] ?? '' ) ), '%s' ],
        'birthday'                  => [ sanitize_text_field( (string) ( $payload['birthday'] ?? '' ) ), '%s' ],
        'do_not_contact'            => [ ! empty( $payload['do_not_contact'] ) ? 1 : 0, '%d' ],
        'volunteer_status'          => [ ! empty( $payload['volunteer_status'] ) || $book_slug === 'volunteers' ? 1 : 0, '%d' ],
        'anonymous_donor'           => [ ! empty( $payload['anonymous_donor'] ) ? 1 : 0, '%d' ],
        'additional_emails_json'    => [ metis_json_encode( $additional ), '%s' ],
        'updated_at'                => [ current_time( 'mysql' ), '%s' ],
    ];

    foreach ( $field_map as $column => $meta ) {
        if ( ! metis_contacts_column_exists( $details_table, $column ) ) {
            continue;
        }
        $value = $meta[0];
        if ( in_array( $column, [ 'phone', 'preferred_name', 'preferred_contact_method', 'address', 'city', 'state', 'zip', 'birthday' ], true ) && (string) $value === '' ) {
            $value = null;
        }
        $detail_payload[ $column ] = $value;
        $detail_format[] = $meta[1];
    }

    if ( ! empty( $detail_payload ) ) {
        if ( $detail && isset( $detail->id ) ) {
            $updated = $wpdb->update(
                $details_table,
                $detail_payload,
                [ 'id' => (int) $detail->id ],
                $detail_format,
                [ '%d' ]
            );
            if ( $updated === false ) {
                return [ 'ok' => false, 'status' => 500, 'error' => 'Unable to update contact details.' ];
            }
        } else {
            $inserted = $wpdb->insert( $details_table, $detail_payload, $detail_format );
            if ( $inserted === false ) {
                return [ 'ok' => false, 'status' => 500, 'error' => 'Unable to create contact details.' ];
            }
        }
    }

    if ( preg_match( '/^list-(\d+)$/', $book_slug, $matches ) && metis_contacts_table_exists( $subs_table ) ) {
        $wpdb->replace(
            $subs_table,
            [
                'contact_id' => $contact_id,
                'list_id'    => (int) $matches[1],
            ],
            [ '%d', '%d' ]
        );
    }

    $entry = metis_contacts_carddav_fetch_contact( $cid );
    if ( ! $entry ) {
        return [ 'ok' => false, 'status' => 500, 'error' => 'Saved contact could not be reloaded.' ];
    }

    $etag = metis_contacts_carddav_contact_etag( $entry['contact'], $entry['details'] );
    metis_contacts_carddav_log_change( $cid, 'upsert', metis_contacts_carddav_book_slugs_for_contact( $entry['contact'], $entry['details'] ), $etag );

    return [
        'ok'      => true,
        'status'  => $is_create ? 201 : 204,
        'cid'     => $cid,
        'contact' => $entry['contact'],
        'details' => $entry['details'],
        'etag'    => $etag,
    ];
}

function metis_contacts_carddav_delete_contact( string $cid ): bool {
    global $wpdb;

    $entry = metis_contacts_carddav_fetch_contact( $cid );
    if ( ! $entry ) {
        return false;
    }

    $contact       = $entry['contact'];
    $details       = $entry['details'];
    $contacts_table = Metis_Tables::get( 'contacts' );
    $details_table  = Metis_Tables::get( 'contact_details' );
    $notes_table    = Metis_Tables::get( 'contact_notes' );
    $subs_table     = Metis_Tables::get( 'newsletter_subs' );
    $contact_id     = (int) ( $contact->id ?? 0 );
    $book_slugs     = metis_contacts_carddav_book_slugs_for_contact( $contact, $details );

    if ( $contact_id > 0 && metis_contacts_table_exists( $subs_table ) ) {
        $wpdb->delete( $subs_table, [ 'contact_id' => $contact_id ], [ '%d' ] );
    }
    if ( metis_contacts_table_exists( $details_table ) ) {
        if ( metis_contacts_column_exists( $details_table, 'contact_id' ) ) {
            $wpdb->delete( $details_table, [ 'contact_id' => $contact_id ], [ '%d' ] );
        }
        if ( metis_contacts_column_exists( $details_table, 'contact_cid' ) ) {
            $wpdb->delete( $details_table, [ 'contact_cid' => $cid ], [ '%s' ] );
        }
    }
    if ( metis_contacts_table_exists( $notes_table ) ) {
        if ( metis_contacts_column_exists( $notes_table, 'cid' ) ) {
            $wpdb->delete( $notes_table, [ 'cid' => $cid ], [ '%s' ] );
        } elseif ( metis_contacts_column_exists( $notes_table, 'contact_id' ) ) {
            $wpdb->delete( $notes_table, [ 'contact_id' => $contact_id ], [ '%d' ] );
        }
    }

    $deleted = $wpdb->delete( $contacts_table, [ 'id' => $contact_id ], [ '%d' ] );
    if ( $deleted === false ) {
        return false;
    }

    metis_contacts_carddav_log_change( $cid, 'delete', $book_slugs, null );
    return true;
}

function metis_contacts_carddav_xml_document( string $root, string $inner_xml, array $attributes = [] ): string {
    $attrs = [ 'xmlns:d="DAV:"', 'xmlns:cs="http://calendarserver.org/ns/"', 'xmlns:card="urn:ietf:params:xml:ns:carddav"' ];
    foreach ( $attributes as $name => $value ) {
        $attrs[] = $name . '="' . esc_attr( (string) $value ) . '"';
    }
    return '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<d:' . $root . ' ' . implode( ' ', $attrs ) . '>' . $inner_xml . '</d:' . $root . '>';
}

function metis_contacts_carddav_status_text( int $status ): string {
    if ( function_exists( 'get_status_header_desc' ) ) {
        $text = (string) get_status_header_desc( $status );
        if ( $text !== '' ) {
            return $text;
        }
    }

    return match ( $status ) {
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        207 => 'Multi-Status',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        410 => 'Gone',
        412 => 'Precondition Failed',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
        default => '',
    };
}

function metis_contacts_carddav_xml_propstat( array $props, int $status = 200 ): string {
    $xml = '<d:prop>';
    foreach ( $props as $prop ) {
        $xml .= $prop;
    }
    $xml .= '</d:prop><d:status>HTTP/1.1 ' . $status . ' ' . metis_contacts_carddav_status_text( $status ) . '</d:status>';
    return '<d:propstat>' . $xml . '</d:propstat>';
}

function metis_contacts_carddav_xml_response( string $href, array $props, int $status = 200 ): string {
    return '<d:response><d:href>' . esc_url( $href ) . '</d:href>' . metis_contacts_carddav_xml_propstat( $props, $status ) . '</d:response>';
}

function metis_contacts_carddav_xml_multistatus_response( string $href, array $ok_props, array $notfound_props = [] ): string {
    $xml = '<d:response><d:href>' . esc_url( $href ) . '</d:href>';
    if ( ! empty( $ok_props ) ) {
        $xml .= metis_contacts_carddav_xml_propstat( $ok_props, 200 );
    }
    if ( ! empty( $notfound_props ) ) {
        $xml .= metis_contacts_carddav_xml_propstat( $notfound_props, 404 );
    }
    return $xml . '</d:response>';
}

function metis_contacts_carddav_supported_report_set_xml( bool $include_sync = true ): string {
    $reports = [
        '<d:supported-report><d:report><d:expand-property/></d:report></d:supported-report>',
        '<d:supported-report><d:report><d:principal-property-search/></d:report></d:supported-report>',
        '<d:supported-report><d:report><d:principal-search-property-set/></d:report></d:supported-report>',
        '<d:supported-report><d:report><card:addressbook-multiget/></d:report></d:supported-report>',
        '<d:supported-report><d:report><card:addressbook-query/></d:report></d:supported-report>',
    ];

    if ( $include_sync ) {
        $reports[] = '<d:supported-report><d:report><d:sync-collection/></d:report></d:supported-report>';
    }

    return '<d:supported-report-set>' . implode( '', $reports ) . '</d:supported-report-set>';
}

function metis_contacts_carddav_xml_headers(): array {
    return [
        'Content-Type' => 'application/xml; charset=' . get_bloginfo( 'charset' ),
        'DAV'          => '1, 2, addressbook',
    ];
}

function metis_contacts_carddav_propfind_mode( string $xml ): string {
    $xml = strtolower( $xml );
    if ( str_contains( $xml, '<propname' ) ) {
        return 'propname';
    }
    if ( str_contains( $xml, '<allprop' ) || trim( $xml ) === '' ) {
        return 'allprop';
    }
    return 'prop';
}

function metis_contacts_carddav_select_props( array $prop_map, string $request_body ): array {
    $mode = metis_contacts_carddav_propfind_mode( $request_body );
    if ( $mode === 'allprop' ) {
        return [ 'ok' => array_values( $prop_map ), 'notfound' => [] ];
    }

    if ( $mode === 'propname' ) {
        $names = [];
        foreach ( $prop_map as $name => $xml ) {
            if ( preg_match( '#^<([a-z0-9_-]+:)?([a-z0-9_-]+)#i', $xml, $m ) ) {
                $prefix = isset( $m[1] ) ? rtrim( (string) $m[1], ':' ) : '';
                $local  = (string) ( $m[2] ?? $name );
                $names[] = '<' . ( $prefix !== '' ? $prefix . ':' : '' ) . $local . '/>';
            }
        }
        return [ 'ok' => $names, 'notfound' => [] ];
    }

    $requested = metis_contacts_carddav_requested_prop_tags( $request_body );
    if ( empty( $requested ) ) {
        return [ 'ok' => array_values( $prop_map ), 'notfound' => [] ];
    }

    $selected = [];
    $notfound = [];
    foreach ( $requested as $name => $tag ) {
        if ( isset( $prop_map[ $name ] ) ) {
            $selected[] = $prop_map[ $name ];
        } else {
            $notfound[] = $tag;
        }
    }

    return [
        'ok'       => ! empty( $selected ) ? $selected : array_values( $prop_map ),
        'notfound' => $notfound,
    ];
}

function metis_contacts_carddav_resource_id_xml( string $seed ): string {
    return '<d:resource-id><d:href>urn:uuid:' . esc_html( sha1( 'metis-carddav-resource|' . $seed ) ) . '</d:href></d:resource-id>';
}

function metis_contacts_carddav_default_quota_xml(): array {
    return [
        '<d:quota-used-bytes>0</d:quota-used-bytes>',
        '<d:quota-available-bytes>1073741824</d:quota-available-bytes>',
        '<d:quota-used>0</d:quota-used>',
        '<d:quota-available>1073741824</d:quota-available>',
    ];
}

function metis_contacts_carddav_current_user_privileges_xml( bool $writable ): string {
    $privileges = [
        '<d:privilege><d:read/></d:privilege>',
        '<d:privilege><d:read-current-user-privilege-set/></d:privilege>',
    ];

    if ( $writable ) {
        $privileges[] = '<d:privilege><d:write/></d:privilege>';
        $privileges[] = '<d:privilege><d:write-properties/></d:privilege>';
        $privileges[] = '<d:privilege><d:bind/></d:privilege>';
        $privileges[] = '<d:privilege><d:unbind/></d:privilege>';
    }

    return '<d:current-user-privilege-set>' . implode( '', $privileges ) . '</d:current-user-privilege-set>';
}

function metis_contacts_carddav_home_props_xml( WP_User $user, string $home_href ): array {
    $principal_href = metis_contacts_carddav_endpoint_url( 'principals/' . rawurlencode( $user->user_login ) . '/' );

    return [
        'resourcetype'           => '<d:resourcetype><d:collection/></d:resourcetype>',
        'displayname'            => '<d:displayname>Metis Address Books</d:displayname>',
        'owner'                  => '<d:owner><d:href>' . esc_url( $principal_href ) . '</d:href></d:owner>',
        'current-user-privilege-set' => metis_contacts_carddav_current_user_privileges_xml( false ),
        'resource-id'            => metis_contacts_carddav_resource_id_xml( 'home|' . $user->user_login ),
        'supported-report-set'   => metis_contacts_carddav_supported_report_set_xml( false ),
        'sync-token'             => '<d:sync-token>' . esc_html( 'metis-home-' . $user->user_login ) . '</d:sync-token>',
        'add-member'             => '<d:add-member><d:href>' . esc_url( $home_href ) . '</d:href></d:add-member>',
        'max-resource-size'      => '<card:max-resource-size>1048576</card:max-resource-size>',
        'max-image-size'         => '<card:max-image-size>1048576</card:max-image-size>',
        'me-card'                => '<cs:me-card/>',
        'push-transports'        => '<cs:push-transports/>',
        'pushkey'                => '<cs:pushkey/>',
        'bulk-requests'          => '<x1:bulk-requests xmlns:x1="http://me.com/_namespace/"/>',
        'guardian-restricted'    => '<x1:guardian-restricted xmlns:x1="http://me.com/_namespace/">0</x1:guardian-restricted>',
        'quota-used-bytes'       => '<d:quota-used-bytes>0</d:quota-used-bytes>',
        'quota-available-bytes'  => '<d:quota-available-bytes>1073741824</d:quota-available-bytes>',
        'quota-used'             => '<d:quota-used>0</d:quota-used>',
        'quota-available'        => '<d:quota-available>1073741824</d:quota-available>',
    ];
}

function metis_contacts_carddav_addressbook_props_xml( array $book, bool $include_sync = true ): array {
    $book_slug = (string) ( $book['slug'] ?? '' );
    $display_name = (string) ( $book['display_name'] ?? $book_slug );
    $writable = empty( $book['readonly'] );

    return [
        'displayname'               => '<d:displayname>' . esc_html( $display_name ) . '</d:displayname>',
        'resourcetype'              => '<d:resourcetype><d:collection/><card:addressbook/></d:resourcetype>',
        'addressbook-description'   => '<card:addressbook-description>' . esc_html( $display_name ) . '</card:addressbook-description>',
        'supported-address-data'    => '<card:supported-address-data><card:address-data-type content-type="text/vcard" version="3.0"/></card:supported-address-data>',
        'current-user-privilege-set'=> metis_contacts_carddav_current_user_privileges_xml( $writable ),
        'resource-id'               => metis_contacts_carddav_resource_id_xml( 'book|' . $book_slug ),
        'supported-report-set'      => metis_contacts_carddav_supported_report_set_xml( $include_sync ),
        'sync-token'                => '<d:sync-token>' . esc_html( metis_contacts_carddav_current_sync_token( $book_slug ) ) . '</d:sync-token>',
        'getctag'                   => '<cs:getctag>' . esc_html( (string) metis_contacts_carddav_book_timestamp( $book_slug ) ) . '</cs:getctag>',
        'max-resource-size'         => '<card:max-resource-size>1048576</card:max-resource-size>',
        'max-image-size'            => '<card:max-image-size>1048576</card:max-image-size>',
        'bulk-requests'             => '<x1:bulk-requests xmlns:x1="http://me.com/_namespace/"/>',
        'guardian-restricted'       => '<x1:guardian-restricted xmlns:x1="http://me.com/_namespace/">0</x1:guardian-restricted>',
        'me-card'                   => '<cs:me-card/>',
        'push-transports'           => '<cs:push-transports/>',
        'pushkey'                   => '<cs:pushkey/>',
        'quota-used-bytes'          => '<d:quota-used-bytes>0</d:quota-used-bytes>',
        'quota-available-bytes'     => '<d:quota-available-bytes>1073741824</d:quota-available-bytes>',
        'quota-used'                => '<d:quota-used>0</d:quota-used>',
        'quota-available'           => '<d:quota-available>1073741824</d:quota-available>',
    ];
}

function metis_contacts_carddav_discovery_response( WP_User $user ): Metis_Http_Response {
    $principal_href = metis_contacts_carddav_endpoint_url( 'principals/' . rawurlencode( $user->user_login ) . '/' );
    $prop_map = [
        'current-user-principal' => '<d:current-user-principal><d:href>' . esc_url( $principal_href ) . '</d:href></d:current-user-principal>',
        'resourcetype'           => '<d:resourcetype><d:collection/></d:resourcetype>',
        'displayname'            => '<d:displayname>Metis CardDAV</d:displayname>',
        'supported-report-set'   => metis_contacts_carddav_supported_report_set_xml( false ),
    ];
    $body = metis_contacts_carddav_xml_document(
        'multistatus',
        metis_contacts_carddav_xml_multistatus_response(
            metis_contacts_carddav_endpoint_url(),
            ...array_values( metis_contacts_carddav_select_props( $prop_map, '' ) )
        )
    );

    return new Metis_Http_Response( 207, metis_contacts_carddav_xml_headers(), $body );
}

function metis_contacts_carddav_principal_response( WP_User $user ): Metis_Http_Response {
    $principal_href = metis_contacts_carddav_endpoint_url( 'principals/' . rawurlencode( $user->user_login ) . '/' );
    $home_href      = metis_contacts_carddav_endpoint_url( 'addressbooks/' . rawurlencode( $user->user_login ) . '/' );
    $prop_map = [
        'resourcetype'         => '<d:resourcetype><d:principal/><d:collection/></d:resourcetype>',
        'displayname'          => '<d:displayname>' . esc_html( $user->display_name !== '' ? $user->display_name : $user->user_login ) . '</d:displayname>',
        'addressbook-home-set' => '<card:addressbook-home-set><d:href>' . esc_url( $home_href ) . '</d:href></card:addressbook-home-set>',
        'principal-url'        => '<d:principal-URL><d:href>' . esc_url( $principal_href ) . '</d:href></d:principal-URL>',
        'supported-report-set' => metis_contacts_carddav_supported_report_set_xml( false ),
    ];

    $body = metis_contacts_carddav_xml_document(
        'multistatus',
        metis_contacts_carddav_xml_multistatus_response(
            $principal_href,
            ...array_values( metis_contacts_carddav_select_props( $prop_map, '' ) )
        )
    );

    return new Metis_Http_Response( 207, metis_contacts_carddav_xml_headers(), $body );
}

function metis_contacts_carddav_home_response( WP_User $user, string $request_body = '' ): Metis_Http_Response {
    $responses = [];
    $home_href = metis_contacts_carddav_endpoint_url( 'addressbooks/' . rawurlencode( $user->user_login ) . '/' );

    $home_props = metis_contacts_carddav_select_props( metis_contacts_carddav_home_props_xml( $user, $home_href ), $request_body );
    $responses[] = metis_contacts_carddav_xml_multistatus_response(
        $home_href,
        $home_props['ok'],
        $home_props['notfound']
    );

    foreach ( metis_contacts_carddav_book_definitions( $user ) as $book ) {
        $book_href = metis_contacts_carddav_endpoint_url( 'addressbooks/' . rawurlencode( $user->user_login ) . '/' . rawurlencode( (string) $book['slug'] ) . '/' );
        $book_props = metis_contacts_carddav_select_props( metis_contacts_carddav_addressbook_props_xml( $book ), $request_body );
        $responses[] = metis_contacts_carddav_xml_multistatus_response(
            $book_href,
            $book_props['ok'],
            $book_props['notfound']
        );
    }

    $body = metis_contacts_carddav_xml_document( 'multistatus', implode( '', $responses ) );
    return new Metis_Http_Response( 207, metis_contacts_carddav_xml_headers(), $body );
}

function metis_contacts_carddav_collection_response( WP_User $user, array $book, string $request_body = '' ): Metis_Http_Response {
    $book_slug = (string) $book['slug'];
    $book_href = metis_contacts_carddav_endpoint_url( 'addressbooks/' . rawurlencode( $user->user_login ) . '/' . rawurlencode( $book_slug ) . '/' );
    $book_props = metis_contacts_carddav_select_props( metis_contacts_carddav_addressbook_props_xml( $book ), $request_body );
    $responses = [
        metis_contacts_carddav_xml_multistatus_response(
            $book_href,
            $book_props['ok'],
            $book_props['notfound']
        ),
    ];

    foreach ( metis_contacts_carddav_fetch_contacts_for_book( $book_slug ) as $entry ) {
        $contact = $entry['contact'];
        $details = $entry['details'];
        $cid     = (string) ( $contact->cid ?? '' );
        $etag    = metis_contacts_carddav_contact_etag( $contact, $details );
        $vcard   = metis_contacts_carddav_contact_to_vcard( $contact, $details );
        $responses[] = metis_contacts_carddav_xml_response(
            metis_contacts_carddav_resource_href( $user, $book_slug, $cid ),
            [
                '<d:getetag>' . esc_html( $etag ) . '</d:getetag>',
                '<d:getcontenttype>text/vcard; charset=utf-8</d:getcontenttype>',
                '<d:getcontentlength>' . strlen( $vcard ) . '</d:getcontentlength>',
            ]
        );
    }

    $body = metis_contacts_carddav_xml_document( 'multistatus', implode( '', $responses ) );
    return new Metis_Http_Response( 207, metis_contacts_carddav_xml_headers(), $body );
}

function metis_contacts_carddav_query_response( WP_User $user, array $book, string $request_body ): Metis_Http_Response {
    $responses = [];
    $book_slug = (string) $book['slug'];

    foreach ( metis_contacts_carddav_fetch_contacts_for_book( $book_slug ) as $entry ) {
        $contact = $entry['contact'];
        $details = $entry['details'];
        $cid     = (string) ( $contact->cid ?? '' );
        $responses[] = metis_contacts_carddav_xml_response(
            metis_contacts_carddav_resource_href( $user, $book_slug, $cid ),
            [
                '<d:getetag>' . esc_html( metis_contacts_carddav_contact_etag( $contact, $details ) ) . '</d:getetag>',
                '<card:address-data>' . esc_html( metis_contacts_carddav_contact_to_vcard( $contact, $details ) ) . '</card:address-data>',
            ]
        );
    }

    $body = metis_contacts_carddav_xml_document( 'multistatus', implode( '', $responses ) );
    return new Metis_Http_Response( 207, metis_contacts_carddav_xml_headers(), $body );
}

function metis_contacts_carddav_multiget_response( WP_User $user, array $book, string $request_body ): Metis_Http_Response {
    $hrefs = [];
    if ( preg_match_all( '#<[^:>]*:?href>(.*?)</[^:>]*:?href>#si', $request_body, $matches ) ) {
        $hrefs = array_map( 'html_entity_decode', (array) $matches[1] );
    }

    $responses = [];
    $book_slug = (string) $book['slug'];
    foreach ( $hrefs as $href ) {
        $path = metis_parse_url( $href, PHP_URL_PATH );
        if ( ! is_string( $path ) || ! preg_match( '#/dav/addressbooks/[^/]+/' . preg_quote( $book_slug, '#' ) . '/([^/]+)\.vcf$#', $path, $m ) ) {
            continue;
        }
        $cid = sanitize_text_field( rawurldecode( (string) $m[1] ) );
        $entry = metis_contacts_carddav_fetch_contact( $cid );
        if ( ! $entry ) {
            $responses[] = metis_contacts_carddav_xml_response( $href, [], 404 );
            continue;
        }
        $contact = $entry['contact'];
        $details = $entry['details'];
        $responses[] = metis_contacts_carddav_xml_response(
            $href,
            [
                '<d:getetag>' . esc_html( metis_contacts_carddav_contact_etag( $contact, $details ) ) . '</d:getetag>',
                '<card:address-data>' . esc_html( metis_contacts_carddav_contact_to_vcard( $contact, $details ) ) . '</card:address-data>',
            ]
        );
    }

    $body = metis_contacts_carddav_xml_document( 'multistatus', implode( '', $responses ) );
    return new Metis_Http_Response( 207, metis_contacts_carddav_xml_headers(), $body );
}

function metis_contacts_carddav_sync_collection_response( WP_User $user, array $book, string $request_body ): Metis_Http_Response {
    global $wpdb;

    $book_slug = (string) $book['slug'];
    $sync_table = Metis_Tables::get( 'contact_dav_sync' );
    $sync_token = '';
    if ( preg_match( '#<[^:>]*:?sync-token>(.*?)</[^:>]*:?sync-token>#si', $request_body, $matches ) ) {
        $sync_token = trim( html_entity_decode( (string) $matches[1] ) );
    }

    $parsed = metis_contacts_carddav_parse_sync_token( $sync_token );
    if ( $sync_token !== '' && ( $parsed['book_slug'] !== $book_slug || $parsed['sequence'] < 0 ) ) {
        return new Metis_Http_Response( 409, [ 'Content-Type' => 'application/xml; charset=' . get_bloginfo( 'charset' ) ], '' );
    }

    $responses = [];
    $seen      = [];
    $entries    = metis_contacts_carddav_fetch_contacts_for_book( $book_slug );

    foreach ( $entries as $entry ) {
        $contact = $entry['contact'];
        $details = $entry['details'];
        $cid     = (string) ( $contact->cid ?? '' );
        $ts      = metis_contacts_carddav_contact_updated_timestamp( $contact, $details );

        if ( $ts <= (int) $parsed['timestamp'] ) {
            continue;
        }

        $seen[ $cid ] = true;
        $responses[] = metis_contacts_carddav_xml_response(
            metis_contacts_carddav_resource_href( $user, $book_slug, $cid ),
            [
                '<d:getetag>' . esc_html( metis_contacts_carddav_contact_etag( $contact, $details ) ) . '</d:getetag>',
                '<card:address-data>' . esc_html( metis_contacts_carddav_contact_to_vcard( $contact, $details ) ) . '</card:address-data>',
            ]
        );
    }

    if ( metis_contacts_table_exists( $sync_table ) ) {
        $deleted_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT contact_cid
                 FROM {$sync_table}
                 WHERE book_slug = %s
                   AND id > %d
                   AND operation = 'delete'
                 ORDER BY id ASC",
                $book_slug,
                (int) $parsed['sequence']
            )
        );
        foreach ( is_array( $deleted_rows ) ? $deleted_rows : [] as $row ) {
            $cid = sanitize_text_field( (string) ( $row->contact_cid ?? '' ) );
            if ( $cid === '' || isset( $seen[ $cid ] ) ) {
                continue;
            }
            $responses[] = '<d:response><d:href>' . esc_url( metis_contacts_carddav_resource_href( $user, $book_slug, $cid ) ) . '</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>';
        }
    }

    $responses[] = '<d:sync-token>' . esc_html( metis_contacts_carddav_current_sync_token( $book_slug ) ) . '</d:sync-token>';
    $body = metis_contacts_carddav_xml_document( 'multistatus', implode( '', $responses ) );

    return new Metis_Http_Response( 207, metis_contacts_carddav_xml_headers(), $body );
}

function metis_contacts_carddav_authenticate_request( Metis_Http_Request $request ): ?WP_User {
    global $wpdb;

    metis_install_db();
    $auth_scheme_matched = false;

    $authorization = trim( $request->header( 'authorization' ) );
    if ( $authorization === '' ) {
        metis_contacts_carddav_log_event( 'WARN', 'CardDAV authentication failed: missing Authorization header', metis_contacts_carddav_request_context( $request, [
            'reason' => 'missing_authorization',
        ] ) );
        return null;
    }

    if ( preg_match( '/^Basic\s+(.+)$/i', $authorization, $matches ) ) {
        $auth_scheme_matched = true;
        $decoded = base64_decode( (string) $matches[1], true );
        if ( ! is_string( $decoded ) || ! str_contains( $decoded, ':' ) ) {
            metis_contacts_carddav_log_event( 'WARN', 'CardDAV authentication failed: invalid Basic auth payload', metis_contacts_carddav_request_context( $request, [
                'auth_scheme' => 'basic',
                'reason'      => 'invalid_basic_payload',
            ] ) );
            return null;
        }
        [ $username, $secret ] = explode( ':', $decoded, 2 );
        $username = trim( (string) $username );
        $secret   = (string) $secret;

        $user = metis_get_user_by( 'login', $username );
        if ( ! $user instanceof WP_User && is_email( $username ) ) {
            $user = metis_get_user_by( 'email', $username );
        }
        if ( ! $user instanceof WP_User ) {
            metis_contacts_carddav_log_event( 'WARN', 'CardDAV authentication failed: user not found', metis_contacts_carddav_request_context( $request, [
                'auth_scheme' => 'basic',
                'reason'      => 'user_not_found',
                'username'    => $username,
            ] ) );
            return null;
        }

        if ( metis_check_password( $secret, (string) $user->user_pass, $user->ID ) ) {
            metis_contacts_carddav_log_event( 'INFO', 'CardDAV authentication succeeded using account password', metis_contacts_carddav_request_context( $request, [
                'auth_scheme' => 'basic',
                'auth_type'   => 'password',
                'user_id'     => (int) $user->ID,
                'username'    => (string) $user->user_login,
            ] ) );
            return $user;
        }

        $tokens_table = Metis_Tables::get( 'contact_dav_tokens' );
        $token_hash   = metis_contacts_carddav_hash_token( $secret );
        $token_row    = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tokens_table}
                 WHERE user_id = %d
                   AND token_hash = %s
                   AND revoked_at IS NULL
                 LIMIT 1",
                $user->ID,
                $token_hash
            )
        );

        if ( $token_row ) {
            $wpdb->update(
                $tokens_table,
                [ 'last_used_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $token_row->id ],
                [ '%s' ],
                [ '%d' ]
            );
            metis_contacts_carddav_log_event( 'INFO', 'CardDAV authentication succeeded using device token', metis_contacts_carddav_request_context( $request, [
                'auth_scheme'  => 'basic',
                'auth_type'    => 'token',
                'user_id'      => (int) $user->ID,
                'username'     => (string) $user->user_login,
                'token_id'     => (int) $token_row->id,
                'token_prefix' => (string) ( $token_row->token_prefix ?? '' ),
            ] ) );
            return $user;
        }

        metis_contacts_carddav_log_event( 'WARN', 'CardDAV authentication failed: password and token did not match', metis_contacts_carddav_request_context( $request, [
            'auth_scheme' => 'basic',
            'reason'      => 'secret_mismatch',
            'user_id'     => (int) $user->ID,
            'username'    => (string) $user->user_login,
        ] ) );
    }

    if ( preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
        $auth_scheme_matched = true;
        $token       = trim( (string) $matches[1] );
        $token_hash  = metis_contacts_carddav_hash_token( $token );
        $tokens_table = Metis_Tables::get( 'contact_dav_tokens' );
        $token_row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tokens_table}
                 WHERE token_hash = %s
                   AND revoked_at IS NULL
                 LIMIT 1",
                $token_hash
            )
        );
        if ( $token_row ) {
            $user = metis_get_user_by( 'id', (int) $token_row->user_id );
            if ( $user instanceof WP_User ) {
                $wpdb->update(
                    $tokens_table,
                    [ 'last_used_at' => current_time( 'mysql' ) ],
                    [ 'id' => (int) $token_row->id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                metis_contacts_carddav_log_event( 'INFO', 'CardDAV authentication succeeded using Bearer token', metis_contacts_carddav_request_context( $request, [
                    'auth_scheme'  => 'bearer',
                    'auth_type'    => 'token',
                    'user_id'      => (int) $user->ID,
                    'username'     => (string) $user->user_login,
                    'token_id'     => (int) $token_row->id,
                    'token_prefix' => (string) ( $token_row->token_prefix ?? '' ),
                ] ) );
                return $user;
            }
        }

        metis_contacts_carddav_log_event( 'WARN', 'CardDAV authentication failed: Bearer token did not match', metis_contacts_carddav_request_context( $request, [
            'auth_scheme' => 'bearer',
            'reason'      => 'token_mismatch',
        ] ) );
    }

    if ( ! $auth_scheme_matched ) {
        metis_contacts_carddav_log_event( 'WARN', 'CardDAV authentication failed: unsupported Authorization header', metis_contacts_carddav_request_context( $request, [
            'reason' => 'unsupported_authorization',
        ] ) );
    }

    return null;
}

function metis_contacts_carddav_require_authentication( Metis_Http_Request $request, callable $next ): Metis_Http_Response {
    $user = metis_contacts_carddav_authenticate_request( $request );
    if ( ! $user instanceof WP_User ) {
        return new Metis_Http_Response(
            401,
            [
                'WWW-Authenticate' => 'Basic realm="Metis CardDAV"',
                'Content-Type'     => 'text/plain; charset=' . get_bloginfo( 'charset' ),
            ],
            'CardDAV authentication required.'
        );
    }

    metis_set_current_user( $user->ID );

    if ( ! metis_contacts_can_view() ) {
        metis_contacts_carddav_log_event( 'WARN', 'CardDAV authorization denied: user lacks contact access', metis_contacts_carddav_request_context( $request, [
            'user_id'  => (int) $user->ID,
            'username' => (string) $user->user_login,
            'reason'   => 'contacts_permission_denied',
        ] ) );
        return new Metis_Http_Response( 403, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'You are not allowed to access contacts.' );
    }

    metis_contacts_carddav_log_event( 'INFO', 'CardDAV request authorized', metis_contacts_carddav_request_context( $request, [
        'user_id'  => (int) $user->ID,
        'username' => (string) $user->user_login,
    ] ) );

    return $next(
        $request
            ->with_attribute( 'dav_user', $user )
            ->with_attribute( 'dav_user_login', (string) $user->user_login )
    );
}

function metis_contacts_carddav_match_request( Metis_Http_Request $request ): ?array {
    if ( ! metis_contacts_carddav_is_request( $request ) ) {
        return null;
    }

    $path = '/' . ltrim( $request->path(), '/' );
    if ( in_array( $path, [ '/.well-known/carddav', '/.well-known/caldav' ], true ) ) {
        return [ 'dav_type' => 'well_known' ];
    }

    $base = '#^' . preg_quote( metis_contacts_carddav_base_path(), '#' ) . '(?:/(.*))?$#';
    if ( ! preg_match( $base, $path, $matches ) ) {
        return null;
    }

    $remainder = trim( (string) ( $matches[1] ?? '' ), '/' );
    if ( $remainder === '' ) {
        return [ 'dav_type' => 'root' ];
    }

    $parts = array_values( array_filter( explode( '/', $remainder ), 'strlen' ) );
    if ( $parts[0] === 'principals' && empty( $parts[1] ) ) {
        return [ 'dav_type' => 'principal_root' ];
    }
    if ( $parts[0] === 'principals' && ! empty( $parts[1] ) ) {
        return [
            'dav_type' => 'principal',
            'dav_target_user' => metis_contacts_carddav_normalize_username( (string) $parts[1] ),
        ];
    }

    if ( $parts[0] === 'addressbooks' && empty( $parts[1] ) ) {
        return [ 'dav_type' => 'addressbooks_root' ];
    }
    if ( $parts[0] === 'addressbooks' && ! empty( $parts[1] ) ) {
        $params = [
            'dav_type'        => 'home',
            'dav_target_user' => metis_contacts_carddav_normalize_username( (string) $parts[1] ),
        ];
        if ( ! empty( $parts[2] ) ) {
            $params['dav_type']      = 'collection';
            $params['dav_book_slug'] = sanitize_title( rawurldecode( (string) $parts[2] ) );
        }
        if ( ! empty( $parts[3] ) ) {
            $params['dav_type']     = 'resource';
            $resource               = rawurldecode( (string) $parts[3] );
            $params['dav_resource'] = $resource;
            $params['dav_cid']      = sanitize_text_field( preg_replace( '/\.vcf$/i', '', $resource ) );
        }
        return $params;
    }

    return [ 'dav_type' => 'root' ];
}

function metis_contacts_carddav_options_response(): Metis_Http_Response {
    return new Metis_Http_Response(
        204,
        [
            'Allow' => 'OPTIONS, PROPFIND, REPORT, GET, HEAD, PUT, DELETE',
            'DAV'   => '1, 2, addressbook',
            'MS-Author-Via' => 'DAV',
        ],
        ''
    );
}

function metis_contacts_carddav_handle_request( Metis_Http_Request $request ): Metis_Http_Response {
    $method      = strtoupper( $request->method() );
    $dav_type    = (string) $request->attribute( 'dav_type', 'root' );
    $target_user = (string) $request->attribute( 'dav_target_user', '' );
    $dav_user    = $request->attribute( 'dav_user' );

    metis_contacts_carddav_log_event( 'INFO', 'CardDAV request received', metis_contacts_carddav_request_context( $request, [
        'dav_type'     => $dav_type,
        'target_user'  => $target_user,
        'book_slug'    => (string) $request->attribute( 'dav_book_slug', '' ),
        'resource_cid' => (string) $request->attribute( 'dav_cid', '' ),
        'requested_props' => in_array( $method, [ 'PROPFIND', 'REPORT' ], true ) ? implode( ',', metis_contacts_carddav_requested_props( $request->body() ) ) : '',
        'body_snippet' => in_array( $method, [ 'PROPFIND', 'REPORT' ], true ) ? substr( preg_replace( '/\s+/', ' ', trim( $request->body() ) ), 0, 500 ) : '',
    ] ) );

    if ( $dav_type === 'well_known' ) {
        return Metis_Http_Response::redirect( metis_contacts_carddav_endpoint_url(), 302 );
    }

    if ( ! $dav_user instanceof WP_User ) {
        return new Metis_Http_Response( 500, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'CardDAV user context missing.' );
    }

    if ( $target_user !== '' && $target_user !== $dav_user->user_login ) {
        return new Metis_Http_Response( 403, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'You can only access your own CardDAV principal.' );
    }

    if ( $method === 'OPTIONS' ) {
        metis_contacts_carddav_log_event( 'INFO', 'CardDAV OPTIONS responded', metis_contacts_carddav_request_context( $request, [ 'status' => 204, 'dav_type' => $dav_type ] ) );
        return metis_contacts_carddav_options_response();
    }

    if ( $method === 'PROPFIND' ) {
        $body = $request->body();
        if ( $dav_type === 'root' ) {
            metis_contacts_carddav_log_event( 'INFO', 'CardDAV discovery response served', metis_contacts_carddav_request_context( $request, [ 'status' => 207, 'dav_type' => 'root' ] ) );
            return metis_contacts_carddav_discovery_response( $dav_user );
        }
        if ( $dav_type === 'principal' ) {
            metis_contacts_carddav_log_event( 'INFO', 'CardDAV principal response served', metis_contacts_carddav_request_context( $request, [ 'status' => 207, 'dav_type' => 'principal', 'target_user' => $target_user ] ) );
            return metis_contacts_carddav_principal_response( $dav_user );
        }
        if ( $dav_type === 'principal_root' ) {
            metis_contacts_carddav_log_event( 'INFO', 'CardDAV principals root response served', metis_contacts_carddav_request_context( $request, [ 'status' => 207, 'dav_type' => 'principal_root' ] ) );
            return metis_contacts_carddav_principal_response( $dav_user );
        }
        if ( $dav_type === 'addressbooks_root' ) {
            metis_contacts_carddav_log_event( 'INFO', 'CardDAV addressbooks root response served', metis_contacts_carddav_request_context( $request, [ 'status' => 207, 'dav_type' => 'addressbooks_root' ] ) );
            return metis_contacts_carddav_home_response( $dav_user, $body );
        }
        if ( $dav_type === 'home' ) {
            metis_contacts_carddav_log_event( 'INFO', 'CardDAV home response served', metis_contacts_carddav_request_context( $request, [ 'status' => 207, 'dav_type' => 'home', 'target_user' => $target_user ] ) );
            return metis_contacts_carddav_home_response( $dav_user, $body );
        }
        if ( $dav_type === 'collection' ) {
            $book = metis_contacts_carddav_find_book( $dav_user, (string) $request->attribute( 'dav_book_slug', '' ) );
            if ( ! is_array( $book ) ) {
                return new Metis_Http_Response( 404, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'Address book not found.' );
            }
            return metis_contacts_carddav_collection_response( $dav_user, $book, $body );
        }
        if ( $dav_type === 'resource' ) {
            $entry = metis_contacts_carddav_fetch_contact( (string) $request->attribute( 'dav_cid', '' ) );
            if ( ! $entry ) {
                return new Metis_Http_Response( 404, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'Contact not found.' );
            }
            $contact = $entry['contact'];
            $details = $entry['details'];
            $book_slug = (string) $request->attribute( 'dav_book_slug', 'all' );
            $body = metis_contacts_carddav_xml_document(
                'multistatus',
                metis_contacts_carddav_xml_response(
                    metis_contacts_carddav_resource_href( $dav_user, $book_slug, (string) $contact->cid ),
                    [
                        '<d:getetag>' . esc_html( metis_contacts_carddav_contact_etag( $contact, $details ) ) . '</d:getetag>',
                        '<d:getcontenttype>text/vcard; charset=utf-8</d:getcontenttype>',
                    ]
                )
            );
            return new Metis_Http_Response( 207, [ 'Content-Type' => 'application/xml; charset=' . get_bloginfo( 'charset' ) ], $body );
        }
    }

    if ( $method === 'REPORT' ) {
        $book = metis_contacts_carddav_find_book( $dav_user, (string) $request->attribute( 'dav_book_slug', '' ) );
        if ( ! is_array( $book ) ) {
            return new Metis_Http_Response( 404, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'Address book not found.' );
        }
        $body = $request->body();
        if ( str_contains( $body, 'sync-collection' ) ) {
            return metis_contacts_carddav_sync_collection_response( $dav_user, $book, $body );
        }
        if ( str_contains( $body, 'addressbook-query' ) ) {
            return metis_contacts_carddav_query_response( $dav_user, $book, $body );
        }
        return metis_contacts_carddav_multiget_response( $dav_user, $book, $body );
    }

    if ( in_array( $method, [ 'GET', 'HEAD' ], true ) && $dav_type === 'resource' ) {
        $entry = metis_contacts_carddav_fetch_contact( (string) $request->attribute( 'dav_cid', '' ) );
        if ( ! $entry ) {
            return new Metis_Http_Response( 404, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'Contact not found.' );
        }
        $contact = $entry['contact'];
        $details = $entry['details'];
        $vcard   = $method === 'HEAD' ? '' : metis_contacts_carddav_contact_to_vcard( $contact, $details );
        return new Metis_Http_Response(
            200,
            [
                'Content-Type'   => 'text/vcard; charset=utf-8',
                'ETag'           => metis_contacts_carddav_contact_etag( $contact, $details ),
                'Content-Length' => (string) strlen( $vcard ),
            ],
            $vcard
        );
    }

    if ( $method === 'PUT' && $dav_type === 'resource' ) {
        $book = metis_contacts_carddav_find_book( $dav_user, (string) $request->attribute( 'dav_book_slug', '' ) );
        if ( ! is_array( $book ) ) {
            return new Metis_Http_Response( 404, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'Address book not found.' );
        }
        if ( ! empty( $book['readonly'] ) && (string) $book['slug'] !== 'volunteers' ) {
            return new Metis_Http_Response( 403, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'This address book is read-only.' );
        }

        $if_match = trim( $request->header( 'if-match' ) );
        $cid      = (string) $request->attribute( 'dav_cid', '' );
        $existing = metis_contacts_carddav_fetch_contact( $cid );
        if ( $if_match !== '' && $existing ) {
            $etag = metis_contacts_carddav_contact_etag( $existing['contact'], $existing['details'] );
            if ( $etag !== $if_match ) {
                return new Metis_Http_Response( 412, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'ETag mismatch.' );
            }
        }

        $upsert = metis_contacts_carddav_upsert_contact_from_vcard(
            metis_contacts_carddav_parse_vcard( $request->body() ),
            (string) $book['slug'],
            $cid
        );
        if ( empty( $upsert['ok'] ) ) {
            metis_contacts_carddav_log_event( 'ERROR', 'CardDAV contact upsert failed', metis_contacts_carddav_request_context( $request, [
                'dav_type'     => $dav_type,
                'book_slug'    => (string) $book['slug'],
                'resource_cid' => $cid,
                'status'       => (int) ( $upsert['status'] ?? 500 ),
                'error'        => (string) ( $upsert['error'] ?? 'Unable to save contact.' ),
            ] ) );
            return new Metis_Http_Response( (int) ( $upsert['status'] ?? 500 ), [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], (string) ( $upsert['error'] ?? 'Unable to save contact.' ) );
        }

        metis_contacts_carddav_log_event( 'INFO', 'CardDAV contact upsert succeeded', metis_contacts_carddav_request_context( $request, [
            'dav_type'     => $dav_type,
            'book_slug'    => (string) $book['slug'],
            'resource_cid' => (string) ( $upsert['cid'] ?? $cid ),
            'status'       => (int) ( $upsert['status'] ?? 204 ),
            'etag'         => (string) ( $upsert['etag'] ?? '' ),
        ] ) );

        return new Metis_Http_Response(
            (int) ( $upsert['status'] ?? 204 ),
            [
                'ETag'     => (string) ( $upsert['etag'] ?? '' ),
                'Location' => metis_contacts_carddav_resource_href( $dav_user, (string) $book['slug'], (string) ( $upsert['cid'] ?? $cid ) ),
            ],
            ''
        );
    }

    if ( $method === 'DELETE' && $dav_type === 'resource' ) {
        $cid = (string) $request->attribute( 'dav_cid', '' );
        $existing = metis_contacts_carddav_fetch_contact( $cid );
        if ( ! $existing ) {
            return new Metis_Http_Response( 404, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'Contact not found.' );
        }

        $if_match = trim( $request->header( 'if-match' ) );
        if ( $if_match !== '' ) {
            $etag = metis_contacts_carddav_contact_etag( $existing['contact'], $existing['details'] );
            if ( $etag !== $if_match ) {
                return new Metis_Http_Response( 412, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'ETag mismatch.' );
            }
        }

        if ( ! metis_contacts_carddav_delete_contact( $cid ) ) {
            metis_contacts_carddav_log_event( 'ERROR', 'CardDAV contact delete failed', metis_contacts_carddav_request_context( $request, [
                'dav_type'     => $dav_type,
                'resource_cid' => $cid,
                'status'       => 500,
            ] ) );
            return new Metis_Http_Response( 500, [ 'Content-Type' => 'text/plain; charset=' . get_bloginfo( 'charset' ) ], 'Unable to delete contact.' );
        }

        metis_contacts_carddav_log_event( 'INFO', 'CardDAV contact deleted', metis_contacts_carddav_request_context( $request, [
            'dav_type'     => $dav_type,
            'resource_cid' => $cid,
            'status'       => 204,
        ] ) );

        return new Metis_Http_Response( 204, [], '' );
    }

    metis_contacts_carddav_log_event( 'WARN', 'CardDAV request rejected: method not allowed', metis_contacts_carddav_request_context( $request, [
        'dav_type' => $dav_type,
        'status'   => 405,
    ] ) );

    return new Metis_Http_Response( 405, [ 'Allow' => 'OPTIONS, PROPFIND, REPORT, GET, HEAD, PUT, DELETE' ], '' );
}
