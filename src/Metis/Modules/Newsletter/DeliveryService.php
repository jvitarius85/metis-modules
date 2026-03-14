<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

use Metis\Http\Request;
use Metis\Http\Response;

final class DeliveryService {
    public static function gmailSend( string $to_email, string $subject, string $html_body, array $message_opts = [] ): array {
        if ( ! function_exists( 'metis_people_workspace_sync_settings' ) || ! function_exists( 'metis_people_workspace_google_access_token' ) ) {
            return [ 'ok' => false, 'error' => 'Workspace API service is not available.' ];
        }

        $cfg = \metis_people_workspace_sync_settings();
        if ( empty( $cfg['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $cfg['error'] ?? 'Workspace settings are not configured.' ) ];
        }

        $scopes = (array) ( $cfg['scopes'] ?? [] );
        if ( ! in_array( 'https://www.googleapis.com/auth/gmail.send', $scopes, true ) ) {
            $scopes[] = 'https://www.googleapis.com/auth/gmail.send';
        }
        $cfg['scopes'] = $scopes;

        $from_name    = trim( (string) ( $message_opts['from_name'] ?? '' ) );
        $from_email   = strtolower( trim( (string) ( $message_opts['from_email'] ?? '' ) ) );
        $reply_to     = trim( (string) ( $message_opts['reply_to'] ?? '' ) );
        $subject_user = strtolower( trim( (string) ( $cfg['subject'] ?? '' ) ) );
        $sender_user  = ( $subject_user !== '' && \is_email( $subject_user ) ) ? $subject_user : ( ( $from_email !== '' && \is_email( $from_email ) ) ? $from_email : '' );
        if ( $sender_user === '' ) {
            return [ 'ok' => false, 'error' => 'Workspace sender account is not configured.' ];
        }
        $from_address = ( $from_email !== '' && \is_email( $from_email ) ) ? $from_email : $sender_user;

        $cfg_send            = $cfg;
        $cfg_send['subject'] = $sender_user;
        $token               = \metis_people_workspace_google_access_token( $cfg_send );
        if ( empty( $token['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $token['error'] ?? 'Workspace token error.' ) ];
        }

        $to_email = trim( $to_email );
        if ( $to_email === '' || ! \is_email( $to_email ) ) {
            return [ 'ok' => false, 'error' => 'Recipient email is invalid.' ];
        }

        $subject = trim( $subject );
        if ( $subject === '' ) {
            $subject = 'Newsletter';
        }

        if ( $html_body === '' ) {
            $html_body = '<p>&nbsp;</p>';
        }

        $text_body = Support::plainTextFromHtml( $html_body );
        if ( $text_body === '' ) {
            $text_body = ' ';
        }

        $boundary       = 'metis_alt_' . \metis_generate_password( 18, false, false );
        $mixed_boundary = 'metis_mix_' . \metis_generate_password( 18, false, false );
        $headers        = [
            'MIME-Version: 1.0',
            'To: ' . $to_email,
            'Subject: ' . $subject,
        ];
        if ( $from_address !== '' && \is_email( $from_address ) ) {
            $headers[] = $from_name !== '' ? ( 'From: ' . $from_name . ' <' . $from_address . '>' ) : ( 'From: ' . $from_address );
        }
        if ( $sender_user !== '' && \is_email( $sender_user ) && strtolower( $sender_user ) !== strtolower( $from_address ) ) {
            $headers[] = 'Sender: ' . $sender_user;
        }
        if ( $reply_to !== '' && \is_email( $reply_to ) ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        $alt_body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $text_body . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $html_body . "\r\n\r\n"
            . '--' . $boundary . '--';

        $attachments     = isset( $message_opts['attachments'] ) && is_array( $message_opts['attachments'] ) ? $message_opts['attachments'] : [];
        $has_attachments = ! empty( $attachments );
        $headers[]       = $has_attachments
            ? 'Content-Type: multipart/mixed; boundary="' . $mixed_boundary . '"'
            : 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $mime = implode( "\r\n", $headers ) . "\r\n\r\n";
        if ( $has_attachments ) {
            $mime .= '--' . $mixed_boundary . "\r\n";
            $mime .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n";
            $mime .= $alt_body . "\r\n";

            foreach ( $attachments as $attachment ) {
                if ( ! is_array( $attachment ) ) {
                    continue;
                }
                $bytes     = '';
                $filename  = trim( (string) ( $attachment['name'] ?? 'attachment' ) );
                $mime_type = trim( (string) ( $attachment['mime'] ?? 'application/octet-stream' ) );
                $path      = (string) ( $attachment['path'] ?? '' );
                $url       = (string) ( $attachment['url'] ?? '' );

                if ( $path !== '' && file_exists( $path ) ) {
                    $bytes = (string) file_get_contents( $path );
                    if ( $filename === '' ) {
                        $filename = basename( $path );
                    }
                } elseif ( $url !== '' ) {
                    $resp_attachment = \metis_remote_get( $url, [ 'timeout' => 20 ] );
                    if ( ! \metis_is_error( $resp_attachment ) && (int) \metis_remote_retrieve_response_code( $resp_attachment ) < 300 ) {
                        $bytes = (string) \metis_remote_retrieve_body( $resp_attachment );
                        if ( $filename === '' ) {
                            $url_path = (string) parse_url( $url, PHP_URL_PATH );
                            $filename = $url_path !== '' ? basename( $url_path ) : 'attachment';
                        }
                    }
                }

                if ( $bytes === '' ) {
                    continue;
                }
                if ( $filename === '' ) {
                    $filename = 'attachment';
                }

                $mime .= '--' . $mixed_boundary . "\r\n";
                $mime .= 'Content-Type: ' . $mime_type . '; name="' . str_replace( '"', '', $filename ) . '"' . "\r\n";
                $mime .= "Content-Transfer-Encoding: base64\r\n";
                $mime .= 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' . "\r\n\r\n";
                $mime .= chunk_split( base64_encode( $bytes ) ) . "\r\n";
            }

            $mime .= '--' . $mixed_boundary . '--';
        } else {
            $mime .= $alt_body;
        }

        $payload  = [ 'raw' => Support::b64url( $mime ) ];
        $send_url = 'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode( $sender_user !== '' ? $sender_user : 'me' ) . '/messages/send';
        $resp     = \metis_remote_post( $send_url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) $token['access_token'],
                'Content-Type'  => 'application/json',
            ],
            'body'    => \metis_json_encode( $payload ),
        ] );

        if ( \metis_is_error( $resp ) ) {
            return [ 'ok' => false, 'error' => $resp->get_error_message() ];
        }

        $code    = (int) \metis_remote_retrieve_response_code( $resp );
        $raw     = (string) \metis_remote_retrieve_body( $resp );
        $decoded = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            $msg = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? '' ) : '';
            if ( $msg === '' ) {
                $msg = 'Gmail send failed (' . $code . ').';
            }
            return [ 'ok' => false, 'error' => $msg ];
        }

        return [
            'ok'        => true,
            'gmail_id'  => is_array( $decoded ) ? (string) ( $decoded['id'] ?? '' ) : '',
            'thread_id' => is_array( $decoded ) ? (string) ( $decoded['threadId'] ?? '' ) : '',
        ];
    }

    public static function googleSyncUsageForDate( string $date_ymd = '' ): array {
        if ( ! function_exists( 'metis_people_workspace_sync_settings' ) || ! function_exists( 'metis_people_workspace_google_request' ) ) {
            return [ 'ok' => false, 'error' => 'Workspace API service is not available.' ];
        }

        global $wpdb;

        $usage_table           = \Metis_Tables::get( 'newsletter_google_usage_daily' );
        $workspace_users_table = \Metis_Tables::get( 'people_workspace_users' );

        if ( $date_ymd === '' ) {
            $date_ymd = \metis_date( 'Y-m-d', time(), Support::resolvedTimezone() );
        }

        $cfg = \metis_people_workspace_sync_settings();
        if ( empty( $cfg['ok'] ) ) {
            return [ 'ok' => false, 'error' => (string) ( $cfg['error'] ?? 'Workspace settings not configured.' ) ];
        }

        $scopes       = (array) ( $cfg['scopes'] ?? [] );
        $report_scope = 'https://www.googleapis.com/auth/admin.reports.usage.readonly';
        if ( ! in_array( $report_scope, $scopes, true ) ) {
            $scopes[] = $report_scope;
        }
        $cfg['scopes'] = $scopes;

        $endpoint  = 'https://admin.googleapis.com/admin/reports/v1/usage/users/all/dates/' . rawurlencode( $date_ymd ) . '?parameters=gmail:num_emails_sent&maxResults=100';
        $imported  = 0;
        $page_token = '';

        do {
            $path = $endpoint;
            if ( $page_token !== '' ) {
                $path .= '&pageToken=' . rawurlencode( $page_token );
            }

            $resp = \metis_people_workspace_google_request( 'GET', $path, null, $cfg );
            if ( empty( $resp['ok'] ) ) {
                return [ 'ok' => false, 'error' => (string) ( $resp['error'] ?? 'Google usage report request failed.' ) ];
            }

            $body    = (array) ( $resp['body'] ?? [] );
            $reports = (array) ( $body['usageReports'] ?? [] );

            foreach ( $reports as $row ) {
                $entity          = (array) ( $row['entity'] ?? [] );
                $workspace_email = strtolower( trim( (string) ( $entity['userEmail'] ?? '' ) ) );
                if ( ! \is_email( $workspace_email ) ) {
                    continue;
                }

                $sent_count = 0;
                foreach ( (array) ( $row['parameters'] ?? [] ) as $param ) {
                    $param = (array) $param;
                    if ( (string) ( $param['name'] ?? '' ) !== 'gmail:num_emails_sent' ) {
                        continue;
                    }
                    if ( isset( $param['intValue'] ) ) {
                        $sent_count = (int) $param['intValue'];
                    } elseif ( isset( $param['stringValue'] ) ) {
                        $sent_count = (int) $param['stringValue'];
                    }
                    break;
                }

                $workspace_row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, person_id FROM {$workspace_users_table} WHERE primary_email = %s LIMIT 1",
                        $workspace_email
                    ),
                    ARRAY_A
                );

                $workspace_user_id = (int) ( $workspace_row['id'] ?? 0 );
                $person_id         = (int) ( $workspace_row['person_id'] ?? 0 );
                $exists_id         = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$usage_table} WHERE usage_date = %s AND workspace_email = %s LIMIT 1",
                        $date_ymd,
                        $workspace_email
                    )
                );

                $payload = [
                    'usage_date'        => $date_ymd,
                    'workspace_email'   => $workspace_email,
                    'sent_count'        => max( 0, $sent_count ),
                    'source'            => 'google_reports',
                    'workspace_user_id' => $workspace_user_id > 0 ? $workspace_user_id : null,
                    'person_id'         => $person_id > 0 ? $person_id : null,
                    'payload_json'      => \metis_json_encode( $row ),
                    'updated_at'        => \current_time( 'mysql' ),
                ];

                if ( $exists_id > 0 ) {
                    $ok = $wpdb->update( $usage_table, $payload, [ 'id' => $exists_id ], [ '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ], [ '%d' ] );
                    if ( $ok !== false ) {
                        $imported++;
                    }
                } else {
                    $payload['created_at'] = \current_time( 'mysql' );
                    $ok = $wpdb->insert( $usage_table, $payload, [ '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' ] );
                    if ( $ok !== false ) {
                        $imported++;
                    }
                }
            }

            $page_token = (string) ( $body['nextPageToken'] ?? '' );
        } while ( $page_token !== '' );

        return [ 'ok' => true, 'date' => $date_ymd, 'imported' => $imported ];
    }

    public static function handleOpenRoute( Request $request ): Response {
        $code = (string) $request->attribute( 'code', '' );
        if ( $code !== '' ) {
            \metis_newsletter_track_event_for_message( $code, 'open' );
        }

        $gif = base64_decode( 'R0lGODlhAQABAPAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==' ) ?: '';
        return new Response( 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ], $gif );
    }

    public static function handleClickRoute( Request $request ): Response {
        $code    = (string) $request->attribute( 'code', '' );
        $raw     = (string) ( $request->query()['u'] ?? '' );
        $decoded = base64_decode( rawurldecode( $raw ), true );
        $url     = is_string( $decoded ) ? esc_url_raw( $decoded ) : '';

        if ( $code !== '' ) {
            \metis_newsletter_track_event_for_message( $code, 'click' );
        }

        return Response::redirect( $url !== '' ? $url : home_url( '/' ) );
    }

    public static function handleUnsubscribeRoute( Request $request ): Response {
        $code = (string) $request->attribute( 'code', '' );
        $ok   = $code !== '' ? \metis_newsletter_track_event_for_message( $code, 'unsubscribe', 'Unsubscribed via link' ) : false;
        $html = '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>' . ( $ok ? 'You are unsubscribed.' : 'Unable to process unsubscribe.' ) . '</h2><p>You can close this tab.</p></body></html>';
        return Response::html( $html, $ok ? 200 : 400 );
    }

    public static function handleManageRoute( Request $request ): Response {
        global $wpdb;

        $code           = (string) $request->attribute( 'code', '' );
        $messages_table = \Metis_Tables::get( 'newsletter_messages' );
        $lists_table    = \Metis_Tables::get( 'newsletter_lists' );
        $subs_table     = \Metis_Tables::get( 'newsletter_subs' );

        if ( $code === '' ) {
            return Response::html( '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Invalid management link.</h2></body></html>', 400 );
        }

        $msg = $wpdb->get_row( $wpdb->prepare( "SELECT contact_id, email FROM {$messages_table} WHERE message_code = %s LIMIT 1", $code ), ARRAY_A );
        if ( ! $msg ) {
            return Response::html( '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Link expired or invalid.</h2></body></html>', 404 );
        }

        $contact_id = (int) ( $msg['contact_id'] ?? 0 );
        $email      = (string) ( $msg['email'] ?? '' );
        $updated    = false;
        $action     = sanitize_key( (string) ( $request->query()['action'] ?? '' ) );
        $list_id    = (int) ( $request->query()['list_id'] ?? 0 );

        if ( $action === 'toggle' && $list_id > 0 && $contact_id > 0 ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status FROM {$subs_table} WHERE contact_id = %d AND list_id = %d LIMIT 1",
                    $contact_id,
                    $list_id
                ),
                ARRAY_A
            );
            if ( $row ) {
                $new_status = ( (string) ( $row['status'] ?? '' ) === 'subscribed' ) ? 'unsubscribed' : 'subscribed';
                $wpdb->update(
                    $subs_table,
                    [
                        'status'          => $new_status,
                        'unsubscribed_at' => $new_status === 'subscribed' ? null : \current_time( 'mysql' ),
                        'subscribed_at'   => $new_status === 'subscribed' ? \current_time( 'mysql' ) : null,
                        'last_event_at'   => \current_time( 'mysql' ),
                        'updated_at'      => \current_time( 'mysql' ),
                    ],
                    [ 'id' => (int) ( $row['id'] ?? 0 ) ],
                    [ '%s', '%s', '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                $updated = true;
            }
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.id, l.name, s.status
                 FROM {$lists_table} l
                 LEFT JOIN {$subs_table} s ON s.list_id = l.id AND s.contact_id = %d
                 WHERE l.is_active = 1
                 ORDER BY l.name ASC",
                $contact_id
            ),
            ARRAY_A
        ) ?: [];

        $base_manage = \metis_newsletter_manage_url_from_message_code( $code );
        $html        = '<html><body style="font-family:Arial,sans-serif;padding:24px;max-width:640px;margin:0 auto;">'
            . '<h2 style="margin:0 0 8px;">Manage newsletter subscriptions</h2>'
            . '<p style="margin:0 0 16px;color:#4b5563;">' . esc_html( $email ) . ( $updated ? ' updated.' : '' ) . '</p>'
            . '<div style="display:grid;gap:10px;">';

        foreach ( $rows as $r ) {
            $lid           = (int) ( $r['id'] ?? 0 );
            $name          = esc_html( (string) ( $r['name'] ?? 'List' ) );
            $is_subscribed = ( (string) ( $r['status'] ?? '' ) === 'subscribed' );
            $button        = $is_subscribed ? 'Unsubscribe' : 'Subscribe';
            $chip          = $is_subscribed ? '<span style="color:#166534;">Subscribed</span>' : '<span style="color:#b91c1c;">Unsubscribed</span>';
            $toggle_url    = esc_url( add_query_arg( [ 'action' => 'toggle', 'list_id' => $lid ], $base_manage ) );
            $html         .= '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;gap:12px;"><div><strong>' . $name . '</strong><br>' . $chip . '</div><a href="' . $toggle_url . '" style="display:inline-block;padding:8px 12px;background:#455BC7;color:#fff;text-decoration:none;border-radius:6px;">' . esc_html( $button ) . '</a></div>';
        }

        $html .= '</div><p style="margin-top:16px;"><a href="' . esc_url( \metis_newsletter_unsubscribe_url_from_message_code( $code ) ) . '">Unsubscribe from all</a></p></body></html>';
        return Response::html( $html );
    }
}
