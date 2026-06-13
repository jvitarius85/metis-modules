<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

use Metis\Core\Services\EmailService;
use Metis\Http\Request;
use Metis\Http\Response;

final class DeliveryService {
    public static function gmailSend( string $to_email, string $subject, string $html_body, array $message_opts = [] ): array {
        if ( ! function_exists( 'metis_people_workspace_sync_settings' ) || ! function_exists( 'metis_people_workspace_google_access_token' ) ) {
            return [ 'ok' => false, 'error' => 'Workspace API service is not available.' ];
        }

        $cfg = \metis_people_workspace_sync_settings();
        if ( empty( $cfg['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace settings are not configured.' ];
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
        // Prefer explicit from_email so modules can enforce configured sender identity.
        $sender_user  = ( $from_email !== '' && \metis_email_is_valid( $from_email ) ) ? $from_email : ( ( $subject_user !== '' && \metis_email_is_valid( $subject_user ) ) ? $subject_user : '' );
        if ( $sender_user === '' ) {
            return [ 'ok' => false, 'error' => 'Workspace sender account is not configured.' ];
        }
        $from_address = ( $from_email !== '' && \metis_email_is_valid( $from_email ) ) ? $from_email : $sender_user;

        $cfg_send            = $cfg;
        $cfg_send['subject'] = $sender_user;
        $token               = \metis_people_workspace_google_access_token( $cfg_send );
        if ( empty( $token['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace token error.' ];
        }

        $to_email = trim( $to_email );
        if ( $to_email === '' || ! \metis_email_is_valid( $to_email ) ) {
            return [ 'ok' => false, 'error' => 'Recipient email is invalid.' ];
        }

        $subject = trim( $subject );
        if ( $subject === '' ) {
            $subject = 'Newsletter';
        }

        if ( $html_body === '' ) {
            $html_body = '<p>&nbsp;</p>';
        }

        $internal_reference = EmailService::normalizeInternalReference( $message_opts['internal_reference'] ?? '' );
        if ( $internal_reference !== '' ) {
            $html_body = EmailService::appendInternalReferenceToHtml( $html_body, $internal_reference );
        }

        $text_body = Support::plainTextFromHtml( $html_body );
        if ( $internal_reference !== '' ) {
            $text_body = EmailService::appendInternalReferenceToText( $text_body, $internal_reference );
        }
        if ( trim( $text_body ) === '' ) {
            $text_body = ' ';
        }

        $boundary         = 'metis_alt_' . \metis_runtime_generate_password( 18, false, false );
        $mixed_boundary   = 'metis_mix_' . \metis_runtime_generate_password( 18, false, false );
        $related_boundary = 'metis_rel_' . \metis_runtime_generate_password( 18, false, false );
        $headers        = [
            'MIME-Version: 1.0',
            'To: ' . $to_email,
            'Subject: ' . $subject,
        ];
        if ( $from_address !== '' && \metis_email_is_valid( $from_address ) ) {
            $headers[] = $from_name !== '' ? ( 'From: ' . $from_name . ' <' . $from_address . '>' ) : ( 'From: ' . $from_address );
        }
        if ( $sender_user !== '' && \metis_email_is_valid( $sender_user ) && strtolower( $sender_user ) !== strtolower( $from_address ) ) {
            $headers[] = 'Sender: ' . $sender_user;
        }
        if ( $reply_to !== '' && \metis_email_is_valid( $reply_to ) ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        $alt_body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text_body . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html_body . "\r\n\r\n"
            . '--' . $boundary . '--';

        $attachments     = isset( $message_opts['attachments'] ) && is_array( $message_opts['attachments'] ) ? $message_opts['attachments'] : [];
        $has_attachments = ! empty( $attachments );
        $inline_images   = isset( $message_opts['inline_images'] ) && is_array( $message_opts['inline_images'] ) ? $message_opts['inline_images'] : [];
        $has_inline      = ! empty( $inline_images );
        if ( $has_attachments ) {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed_boundary . '"';
        } elseif ( $has_inline ) {
            $headers[] = 'Content-Type: multipart/related; boundary="' . $related_boundary . '"';
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        }

        $mime = implode( "\r\n", $headers ) . "\r\n\r\n";
        $append_inline_parts = static function ( string &$mime_body, string $container_boundary ) use ( $inline_images ): void {
            foreach ( $inline_images as $inline ) {
                if ( ! is_array( $inline ) ) {
                    continue;
                }
                $bytes = '';
                if ( isset( $inline['data'] ) && is_string( $inline['data'] ) && $inline['data'] !== '' ) {
                    $bytes = $inline['data'];
                } else {
                    $path = (string) ( $inline['path'] ?? '' );
                    $url  = (string) ( $inline['url'] ?? '' );
                    if ( $path !== '' && file_exists( $path ) ) {
                        $bytes = (string) file_get_contents( $path );
                    } elseif ( $url !== '' && self::isSafeAttachmentUrl( $url ) ) {
                        $resp_inline = \metis_runtime_remote_get( $url, [ 'timeout' => 20 ] );
                        if ( ! \metis_runtime_is_error( $resp_inline ) && (int) \metis_runtime_remote_retrieve_response_code( $resp_inline ) < 300 ) {
                            $bytes = (string) \metis_runtime_remote_retrieve_body( $resp_inline );
                        }
                    }
                }
                if ( $bytes === '' ) {
                    continue;
                }
                $cid       = trim( (string) ( $inline['cid'] ?? '' ) );
                $mime_type = trim( (string) ( $inline['mime'] ?? 'application/octet-stream' ) );
                $name      = trim( (string) ( $inline['name'] ?? 'inline' ) );
                if ( $cid === '' ) {
                    $cid = 'inline_' . \metis_runtime_generate_password( 8, false, false );
                }
                if ( $name === '' ) {
                    $name = 'inline';
                }

                $mime_body .= '--' . $container_boundary . "\r\n";
                $mime_body .= 'Content-Type: ' . $mime_type . '; name="' . str_replace( '"', '', $name ) . '"' . "\r\n";
                $mime_body .= "Content-Transfer-Encoding: base64\r\n";
                $mime_body .= 'Content-ID: <' . str_replace( [ '<', '>' ], '', $cid ) . '>' . "\r\n";
                $mime_body .= 'Content-Disposition: inline; filename="' . str_replace( '"', '', $name ) . '"' . "\r\n\r\n";
                $mime_body .= chunk_split( base64_encode( $bytes ) ) . "\r\n";
            }
        };

        if ( $has_attachments ) {
            $mime .= '--' . $mixed_boundary . "\r\n";
            if ( $has_inline ) {
                $mime .= 'Content-Type: multipart/related; boundary="' . $related_boundary . '"' . "\r\n\r\n";
                $mime .= '--' . $related_boundary . "\r\n";
                $mime .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n";
                $mime .= $alt_body . "\r\n";
                $append_inline_parts( $mime, $related_boundary );
                $mime .= '--' . $related_boundary . '--' . "\r\n";
            } else {
                $mime .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n";
                $mime .= $alt_body . "\r\n";
            }

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
                } elseif ( $url !== '' && self::isSafeAttachmentUrl( $url ) ) {
                    $resp_attachment = \metis_runtime_remote_get( $url, [ 'timeout' => 20 ] );
                    if ( ! \metis_runtime_is_error( $resp_attachment ) && (int) \metis_runtime_remote_retrieve_response_code( $resp_attachment ) < 300 ) {
                        $bytes = (string) \metis_runtime_remote_retrieve_body( $resp_attachment );
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
        } elseif ( $has_inline ) {
            $mime .= '--' . $related_boundary . "\r\n";
            $mime .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n";
            $mime .= $alt_body . "\r\n";
            $append_inline_parts( $mime, $related_boundary );
            $mime .= '--' . $related_boundary . '--';
        } else {
            $mime .= $alt_body;
        }

        $payload  = [ 'raw' => Support::b64url( $mime ) ];
        $send_url = 'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode( $sender_user !== '' ? $sender_user : 'me' ) . '/messages/send';
        $resp     = \metis_runtime_remote_post( $send_url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . (string) $token['access_token'],
                'Content-Type'  => 'application/json',
            ],
            'body'    => \metis_json_encode( $payload ),
        ] );

        if ( \metis_runtime_is_error( $resp ) ) {
            return [ 'ok' => false, 'error' => 'Gmail send request failed.' ];
        }

        $code    = (int) \metis_runtime_remote_retrieve_response_code( $resp );
        $raw     = (string) \metis_runtime_remote_retrieve_body( $resp );
        $decoded = json_decode( $raw, true );
        if ( $code < 200 || $code >= 300 ) {
            $msg = 'Gmail send failed (' . $code . ').';
            return [ 'ok' => false, 'error' => $msg ];
        }

        return [
            'ok'        => true,
            'gmail_id'  => is_array( $decoded ) ? (string) ( $decoded['id'] ?? '' ) : '',
            'thread_id' => is_array( $decoded ) ? (string) ( $decoded['threadId'] ?? '' ) : '',
        ];
    }

    private static function isSafeAttachmentUrl( string $url ): bool {
        $url = trim( $url );
        if ( $url === '' ) {
            return false;
        }

        $parts = parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }

        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return false;
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( trim( (string) ( $parts['host'] ?? '' ) ) );
        if ( $scheme !== 'https' || $host === '' ) {
            return false;
        }
        if ( $host === 'localhost' || $host === '::1' || str_starts_with( $host, '127.' ) || str_ends_with( $host, '.local' ) ) {
            return false;
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            $valid_public = filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
            if ( ! $valid_public ) {
                return false;
            }
        }

        return true;
    }

    public static function googleSyncUsageForDate( string $date_ymd = '' ): array {
        if ( ! function_exists( 'metis_people_workspace_sync_settings' ) || ! function_exists( 'metis_people_workspace_google_request' ) ) {
            return [ 'ok' => false, 'error' => 'Workspace API service is not available.' ];
        }

        $db = self::db();

        $usage_table           = \Metis_Tables::get( 'newsletter_google_usage_daily' );
        $workspace_users_table = \Metis_Tables::get( 'people_workspace_users' );

        $used_default_date = false;
        if ( $date_ymd === '' ) {
            $date_ymd = ( new \DateTimeImmutable( 'now', Support::resolvedTimezone() ) )
                ->modify( '-1 day' )
                ->format( 'Y-m-d' );
            $used_default_date = true;
        }

        $cfg = \metis_people_workspace_sync_settings();
        if ( empty( $cfg['ok'] ) ) {
            return [ 'ok' => false, 'error' => 'Workspace settings not configured.' ];
        }

        $scopes       = (array) ( $cfg['scopes'] ?? [] );
        $report_scope = 'https://www.googleapis.com/auth/admin.reports.usage.readonly';
        if ( ! in_array( $report_scope, $scopes, true ) ) {
            $scopes[] = $report_scope;
        }
        $cfg['scopes'] = $scopes;

        $upsert_usage = static function ( string $workspace_email, int $sent_count, array $row_payload, string $source ) use ( $db, $usage_table, $workspace_users_table, $date_ymd, &$imported ): void {
            $workspace_email = strtolower( trim( $workspace_email ) );
            if ( ! \metis_email_is_valid( $workspace_email ) ) {
                return;
            }

            $workspace_row = $db->fetchOne(
                "SELECT id, person_id FROM {$workspace_users_table} WHERE primary_email = %s LIMIT 1",
                [ $workspace_email ]
            );

            $workspace_user_id = (int) ( $workspace_row['id'] ?? 0 );
            $person_id         = (int) ( $workspace_row['person_id'] ?? 0 );
            $exists_id         = (int) $db->scalar(
                "SELECT id FROM {$usage_table} WHERE usage_date = %s AND workspace_email = %s LIMIT 1",
                [ $date_ymd, $workspace_email ]
            );

            $payload = [
                'usage_date'        => $date_ymd,
                'workspace_email'   => $workspace_email,
                'sent_count'        => max( 0, $sent_count ),
                'source'            => $source,
                'workspace_user_id' => $workspace_user_id > 0 ? $workspace_user_id : null,
                'person_id'         => $person_id > 0 ? $person_id : null,
                'payload_json'      => \metis_json_encode( $row_payload ),
                'updated_at'        => \metis_current_time( 'mysql' ),
            ];

            if ( $exists_id > 0 ) {
                $ok = $db->update( $usage_table, $payload, [ 'id' => $exists_id ], [ '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ], [ '%d' ] );
                if ( $ok !== false ) {
                    $imported++;
                }
                return;
            }

            $payload['created_at'] = \metis_current_time( 'mysql' );
            $ok = $db->insert( $usage_table, $payload, [ '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' ] );
            if ( $ok !== false ) {
                $imported++;
            }
        };

        $sync_from_local_newsletter_logs = static function () use ( $db, $upsert_usage, $date_ymd, $used_default_date, &$imported ): array {
            $workspace_sender_email = strtolower( trim( (string) \Core_Settings_Service::get( 'newsletter_sender_email', '' ) ) );
            if ( ! \metis_email_is_valid( $workspace_sender_email ) ) {
                $workspace_sender_email = '';
            }

            $messages_table  = \Metis_Tables::get( 'newsletter_messages' );
            $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );

            $day_start = $date_ymd . ' 00:00:00';
            $day_end = ( new \DateTimeImmutable( $date_ymd . ' 00:00:00', Support::resolvedTimezone() ) )
                ->modify( '+1 day' )
                ->format( 'Y-m-d H:i:s' );

            $rows = $db->fetchAll(
                "SELECT LOWER(TRIM(COALESCE(NULLIF(c.from_email, ''), %s))) AS workspace_email,
                        COUNT(*) AS sent_total
                 FROM {$messages_table} m
                 LEFT JOIN {$campaigns_table} c ON c.id = m.campaign_id
                 WHERE m.status = 'sent'
                   AND m.sent_at IS NOT NULL
                   AND m.sent_at >= %s
                   AND m.sent_at < %s
                 GROUP BY workspace_email",
                [ $workspace_sender_email, $day_start, $day_end ]
            );

            foreach ( (array) $rows as $row ) {
                $workspace_email = (string) ( $row['workspace_email'] ?? '' );
                $sent_total      = (int) ( $row['sent_total'] ?? 0 );
                if ( $sent_total < 1 ) {
                    continue;
                }
                $upsert_usage(
                    $workspace_email,
                    $sent_total,
                    [
                        'source'    => 'metis_newsletter_logs',
                        'date'      => $date_ymd,
                        'sent_total'=> $sent_total,
                    ],
                    'metis_newsletter_logs'
                );
            }

            return [
                'ok'             => true,
                'status'         => 'fallback',
                'date'           => $date_ymd,
                'imported'       => $imported,
                'source'         => 'metis_newsletter_logs',
                'used_default_date' => $used_default_date,
            ];
        };

        $build_endpoint = static function ( string $report_date ): string {
            return 'https://admin.googleapis.com/admin/reports/v1/usage/users/all/dates/' . rawurlencode( $report_date ) . '?parameters=gmail:num_emails_sent&maxResults=100';
        };

        $endpoint   = $build_endpoint( $date_ymd );
        $imported   = 0;
        $page_token = '';
        $retried_with_older_date = false;

        do {
            $path = $endpoint;
            if ( $page_token !== '' ) {
                $path .= '&pageToken=' . rawurlencode( $page_token );
            }

            $resp = \metis_people_workspace_google_request( 'GET', $path, null, $cfg );
            if ( empty( $resp['ok'] ) ) {
                $status = (int) ( $resp['status'] ?? 0 );
                $error  = (string) ( $resp['error'] ?? 'Google usage report request failed.' );

                // Admin Reports usage data can lag; when no explicit date is supplied,
                // retry one additional day back before surfacing an error.
                if ( $used_default_date && ! $retried_with_older_date && $status === 400 ) {
                    $older_date = ( new \DateTimeImmutable( $date_ymd, Support::resolvedTimezone() ) )
                        ->modify( '-1 day' )
                        ->format( 'Y-m-d' );
                    if ( $older_date !== $date_ymd ) {
                        $date_ymd = $older_date;
                        $endpoint = $build_endpoint( $date_ymd );
                        $page_token = '';
                        $retried_with_older_date = true;
                        continue;
                    }
                }

                // Avoid persistent noisy failures when the Reports API is unavailable
                // or not delegated for this Workspace subject.
                if ( in_array( $status, [ 0, 401, 403, 404 ], true ) ) {
                    $fallback = $sync_from_local_newsletter_logs();
                    $fallback['google_status'] = $status;
                    $fallback['google_error']  = $error;
                    $fallback['message'] = 'Google usage reports API is unavailable or unauthorized; usage imported from local newsletter logs.';
                    return $fallback;
                }

                return [
                    'ok'            => false,
                    'error'         => 'Google usage report request failed.',
                    'status'        => $status,
                    'google_status' => $status,
                ];
            }

            $body    = (array) ( $resp['body'] ?? [] );
            $reports = (array) ( $body['usageReports'] ?? [] );

            foreach ( $reports as $row ) {
                $entity          = (array) ( $row['entity'] ?? [] );
                $workspace_email = strtolower( trim( (string) ( $entity['userEmail'] ?? '' ) ) );
                if ( ! \metis_email_is_valid( $workspace_email ) ) {
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

                $upsert_usage( $workspace_email, $sent_count, $row, 'google_reports' );
            }

            $page_token = (string) ( $body['nextPageToken'] ?? '' );
        } while ( $page_token !== '' );

        return [ 'ok' => true, 'date' => $date_ymd, 'imported' => $imported, 'source' => 'google_reports' ];
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
        $url     = is_string( $decoded ) ? metis_url_clean( $decoded ) : '';

        if ( $code !== '' ) {
            \metis_newsletter_track_event_for_message( $code, 'click' );
        }

        return Response::redirect( $url !== '' ? $url : metis_home_url( '/' ) );
    }

    public static function handleUnsubscribeRoute( Request $request ): Response {
        $code = (string) $request->attribute( 'code', '' );
        $ok   = $code !== '' ? \metis_newsletter_track_event_for_message( $code, 'unsubscribe', 'Unsubscribed via link' ) : false;
        $html = '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>' . ( $ok ? 'You are unsubscribed.' : 'Unable to process unsubscribe.' ) . '</h2><p>You can close this tab.</p></body></html>';
        return Response::html( $html, $ok ? 200 : 400 );
    }

    public static function handleManageRoute( Request $request ): Response {
        $db = self::db();

        $code           = (string) $request->attribute( 'code', '' );
        $messages_table = \Metis_Tables::get( 'newsletter_messages' );
        $lists_table    = \Metis_Tables::get( 'newsletter_lists' );
        $subs_table     = \Metis_Tables::get( 'newsletter_subs' );

        if ( $code === '' ) {
            return Response::html( '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Invalid management link.</h2></body></html>', 400 );
        }

        $msg = $db->fetchOne( "SELECT contact_id, email FROM {$messages_table} WHERE message_code = %s LIMIT 1", [ $code ] );
        if ( ! $msg ) {
            return Response::html( '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Link expired or invalid.</h2></body></html>', 404 );
        }

        $contact_id = (int) ( $msg['contact_id'] ?? 0 );
        $email      = (string) ( $msg['email'] ?? '' );
        $updated    = false;
        $action     = metis_key_clean( (string) ( $request->query()['action'] ?? '' ) );
        $list_id    = (int) ( $request->query()['list_id'] ?? 0 );

        if ( $action === 'toggle' && $list_id > 0 && $contact_id > 0 ) {
            $row = $db->fetchOne(
                "SELECT id, status FROM {$subs_table} WHERE contact_id = %d AND list_id = %d LIMIT 1",
                [ $contact_id, $list_id ]
            );
            if ( $row ) {
                $new_status = ( (string) ( $row['status'] ?? '' ) === 'subscribed' ) ? 'unsubscribed' : 'subscribed';
                $db->update(
                    $subs_table,
                    [
                        'status'          => $new_status,
                        'unsubscribed_at' => $new_status === 'subscribed' ? null : \metis_current_time( 'mysql' ),
                        'subscribed_at'   => $new_status === 'subscribed' ? \metis_current_time( 'mysql' ) : null,
                        'last_event_at'   => \metis_current_time( 'mysql' ),
                        'updated_at'      => \metis_current_time( 'mysql' ),
                    ],
                    [ 'id' => (int) ( $row['id'] ?? 0 ) ],
                    [ '%s', '%s', '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                $updated = true;
            }
        }

        $rows = $db->fetchAll(
            "SELECT l.id, l.name, s.status
             FROM {$lists_table} l
             LEFT JOIN {$subs_table} s ON s.list_id = l.id AND s.contact_id = %d
             WHERE l.is_active = 1
             ORDER BY l.name ASC",
            [ $contact_id ]
        );

        $base_manage = \metis_newsletter_manage_url_from_message_code( $code );
        $html        = '<html><body style="font-family:Arial,sans-serif;padding:24px;max-width:640px;margin:0 auto;">'
            . '<h2 style="margin:0 0 8px;">Manage newsletter subscriptions</h2>'
            . '<p style="margin:0 0 16px;color:#4b5563;">' . metis_escape_html( $email ) . ( $updated ? ' updated.' : '' ) . '</p>'
            . '<div style="display:grid;gap:10px;">';

        foreach ( $rows as $r ) {
            $lid           = (int) ( $r['id'] ?? 0 );
            $name          = metis_escape_html( (string) ( $r['name'] ?? 'List' ) );
            $is_subscribed = ( (string) ( $r['status'] ?? '' ) === 'subscribed' );
            $button        = $is_subscribed ? 'Unsubscribe' : 'Subscribe';
            $chip          = $is_subscribed ? '<span style="color:#166534;">Subscribed</span>' : '<span style="color:#b91c1c;">Unsubscribed</span>';
            $toggle_url    = metis_escape_url( metis_add_query_arg( [ 'action' => 'toggle', 'list_id' => $lid ], $base_manage ) );
            $html         .= '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;gap:12px;"><div><strong>' . $name . '</strong><br>' . $chip . '</div><a href="' . $toggle_url . '" style="display:inline-block;padding:8px 12px;background:#455BC7;color:#fff;text-decoration:none;border-radius:6px;">' . metis_escape_html( $button ) . '</a></div>';
        }

        $html .= '</div><p style="margin-top:16px;"><a href="' . metis_escape_url( \metis_newsletter_unsubscribe_url_from_message_code( $code ) ) . '">Unsubscribe from all</a></p></body></html>';
        return Response::html( $html );
    }

    public static function handlePublicSignupRoute( Request $request ): Response {
        NewsletterModule::ensureSchema();

        $payload = $request->parsed_body();
        if ( isset( $payload['payload'] ) && is_string( $payload['payload'] ) ) {
            $decoded = json_decode( $payload['payload'], true );
            if ( is_array( $decoded ) ) {
                $payload = $decoded;
            }
        }
        if ( ! is_array( $payload ) ) {
            $payload = [];
        }

        $first_name = trim( (string) ( $payload['first_name'] ?? '' ) );
        $last_name  = trim( (string) ( $payload['last_name'] ?? '' ) );
        $email      = strtolower( trim( (string) ( $payload['email'] ?? '' ) ) );

        if ( $first_name === '' || $last_name === '' || $email === '' ) {
            return self::respondPublicSignup( $request, [
                'success' => false,
                'message' => 'First name, last name, and email are required.',
            ], 400 );
        }

        if ( ! \metis_email_is_valid( $email ) ) {
            return self::respondPublicSignup( $request, [
                'success' => false,
                'message' => 'Enter a valid email address.',
            ], 400 );
        }

        $list_id = SubscriptionService::defaultListId();
        if ( $list_id < 1 ) {
            return self::respondPublicSignup( $request, [
                'success' => false,
                'message' => 'The default Newsletter list is not configured yet.',
            ], 503 );
        }

        $result = SubscriptionService::upsert( [
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'list_id' => $list_id,
            'status' => 'subscribed',
            'source' => 'website_popup_signup',
        ] );

        if ( empty( $result['success'] ) ) {
            return self::respondPublicSignup( $request, [
                'success' => false,
                'message' => (string) ( $result['message'] ?? 'Unable to save your sign-up.' ),
            ], (int) ( $result['status'] ?? 500 ) );
        }

        return self::respondPublicSignup( $request, [
            'success' => true,
            'message' => 'You are signed up for the Newsletter list.',
            'list_name' => SubscriptionService::DEFAULT_LIST_NAME,
        ], 200 );
    }

    public static function handlePublicUnsubscribeRoute( Request $request ): Response {
        $db = self::db();
        $resolved = self::resolvePublicContact( (string) $request->attribute( 'contact', '' ) );
        if ( ! $resolved ) {
            return Response::html( '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Contact not found.</h2></body></html>', 404 );
        }

        $subs_table         = \Metis_Tables::get( 'newsletter_subs' );
        $suppressions_table = \Metis_Tables::get( 'newsletter_suppressions' );
        $now                = \metis_current_time( 'mysql' );
        $list_ref           = trim( (string) ( $request->query()['list'] ?? '' ) );
        $resolved_list      = $list_ref !== '' ? self::resolvePublicList( $list_ref ) : null;

        if ( is_array( $resolved_list ) && (int) ( $resolved_list['id'] ?? 0 ) > 0 ) {
            $db->execute(
                "UPDATE {$subs_table}
                 SET status = 'unsubscribed',
                     unsubscribed_at = %s,
                     last_event_at = %s,
                     updated_at = %s
                 WHERE contact_id = %d
                   AND list_id = %d",
                [ $now, $now, $now, (int) $resolved['id'], (int) $resolved_list['id'] ]
            );
        } else {
            $db->execute(
                "UPDATE {$subs_table}
                 SET status = 'unsubscribed',
                     unsubscribed_at = %s,
                     last_event_at = %s,
                     updated_at = %s
                 WHERE contact_id = %d",
                [ $now, $now, $now, (int) $resolved['id'] ]
            );
        }

        $email = strtolower( trim( (string) ( $resolved['email'] ?? '' ) ) );
        if ( $email !== '' ) {
            $exists = (int) $db->scalar(
                "SELECT id FROM {$suppressions_table} WHERE (contact_id = %d OR email = %s) AND is_active = 1 LIMIT 1",
                [ (int) $resolved['id'], $email ]
            );
            if ( $exists < 1 ) {
                $db->insert(
                    $suppressions_table,
                    [
                        'suppression_code' => \metis_generate_code( 'NS', $suppressions_table, 'suppression_code' ),
                        'contact_id'       => (int) $resolved['id'],
                        'email'            => $email,
                        'reason'           => 'unsubscribe',
                        'source'           => 'public_route',
                        'is_active'        => 1,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]
                );
            }
        }

        $message = is_array( $resolved_list ) && (string) ( $resolved_list['name'] ?? '' ) !== ''
            ? 'You are unsubscribed from ' . (string) ( $resolved_list['name'] ?? '' ) . '.'
            : 'You are unsubscribed.';
        $html = '<html><body style="font-family:Arial,sans-serif;padding:24px;max-width:640px;margin:0 auto;">'
            . '<h2 style="margin:0 0 8px;">' . metis_escape_html( $message ) . '</h2>'
            . '<p style="margin:0;color:#4b5563;">' . metis_escape_html( (string) ( $resolved['email'] ?? '' ) ) . '</p>'
            . '</body></html>';
        return Response::html( $html );
    }

    public static function handlePublicManageRoute( Request $request ): Response {
        $db = self::db();
        $resolved = self::resolvePublicContact( (string) $request->attribute( 'contact', '' ) );
        if ( ! $resolved ) {
            return Response::html( '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Contact not found.</h2></body></html>', 404 );
        }

        $lists_table = \Metis_Tables::get( 'newsletter_lists' );
        $subs_table  = \Metis_Tables::get( 'newsletter_subs' );
        $updated     = false;
        $action      = metis_key_clean( (string) ( $request->query()['action'] ?? '' ) );
        $list_id     = (int) ( $request->query()['list_id'] ?? 0 );

        if ( $action === 'toggle' && $list_id > 0 ) {
            $row = $db->fetchOne(
                "SELECT id, status FROM {$subs_table} WHERE contact_id = %d AND list_id = %d LIMIT 1",
                [ (int) $resolved['id'], $list_id ]
            );
            $now = \metis_current_time( 'mysql' );
            if ( $row ) {
                $new_status = ( (string) ( $row['status'] ?? '' ) === 'subscribed' ) ? 'unsubscribed' : 'subscribed';
                $db->update(
                    $subs_table,
                    [
                        'status'          => $new_status,
                        'unsubscribed_at' => $new_status === 'subscribed' ? null : $now,
                        'subscribed_at'   => $new_status === 'subscribed' ? $now : null,
                        'last_event_at'   => $now,
                        'updated_at'      => $now,
                    ],
                    [ 'id' => (int) ( $row['id'] ?? 0 ) ],
                    [ '%s', '%s', '%s', '%s', '%s' ],
                    [ '%d' ]
                );
                $updated = true;
            }
        }

        $rows = $db->fetchAll(
            "SELECT l.id, l.name, s.status
             FROM {$lists_table} l
             LEFT JOIN {$subs_table} s ON s.list_id = l.id AND s.contact_id = %d
             WHERE l.is_active = 1
             ORDER BY l.name ASC",
            [ (int) $resolved['id'] ]
        );

        $base_manage = \metis_newsletter_public_manage_url( (string) ( $resolved['ref'] ?? '' ) );
        $html        = '<html><body style="font-family:Arial,sans-serif;padding:24px;max-width:640px;margin:0 auto;">'
            . '<h2 style="margin:0 0 8px;">Manage newsletter subscriptions</h2>'
            . '<p style="margin:0 0 16px;color:#4b5563;">' . metis_escape_html( (string) ( $resolved['email'] ?? '' ) ) . ( $updated ? ' updated.' : '' ) . '</p>'
            . '<div style="display:grid;gap:10px;">';

        foreach ( $rows as $r ) {
            $lid           = (int) ( $r['id'] ?? 0 );
            $name          = metis_escape_html( (string) ( $r['name'] ?? 'List' ) );
            $is_subscribed = ( (string) ( $r['status'] ?? '' ) === 'subscribed' );
            $button        = $is_subscribed ? 'Unsubscribe' : 'Subscribe';
            $chip          = $is_subscribed ? '<span style="color:#166534;">Subscribed</span>' : '<span style="color:#b91c1c;">Unsubscribed</span>';
            $toggle_url    = metis_escape_url( \metis_add_query_arg( [ 'action' => 'toggle', 'list_id' => $lid ], $base_manage ) );
            $html         .= '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;gap:12px;"><div><strong>' . $name . '</strong><br>' . $chip . '</div><a href="' . $toggle_url . '" style="display:inline-block;padding:8px 12px;background:#455BC7;color:#fff;text-decoration:none;border-radius:6px;">' . metis_escape_html( $button ) . '</a></div>';
        }

        $html .= '</div><p style="margin-top:16px;"><a href="' . metis_escape_url( \metis_newsletter_public_unsubscribe_url( (string) ( $resolved['ref'] ?? '' ) ) ) . '">Unsubscribe from all</a></p></body></html>';
        return Response::html( $html );
    }

    public static function handlePublicViewRoute( Request $request ): Response {
        $db = self::db();
        $resolved = self::resolvePublicCampaign( (string) $request->attribute( 'newsletter', '' ) );
        if ( ! $resolved ) {
            return Response::html( '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Newsletter not found.</h2></body></html>', 404 );
        }

        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $campaign = $db->fetchOne(
            "SELECT id, campaign_code, status, doc_json, editor_body_html, html_body, text_body, name
             FROM {$campaigns_table}
             WHERE id = %d
             LIMIT 1",
            [ (int) $resolved['id'] ]
        );
        if ( ! $campaign ) {
            return Response::html( '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Newsletter not found.</h2></body></html>', 404 );
        }

        $status = strtolower( trim( (string) ( $campaign['status'] ?? '' ) ) );
        if ( in_array( $status, [ 'draft', 'archived' ], true ) ) {
            return Response::html( '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Newsletter is not public.</h2></body></html>', 404 );
        }

        $doc_json = \metis_newsletter_normalize_campaign_doc_json(
            (string) ( $campaign['doc_json'] ?? '' ),
            array_key_exists( 'editor_body_html', $campaign ) ? (string) ( $campaign['editor_body_html'] ?? '' ) : null
        );
        $compiled = $doc_json !== '' ? \metis_newsletter_doc_compile( $doc_json ) : [ 'html' => '', 'text' => '' ];
        $html_body = (string) ( $compiled['html'] ?? '' );
        if ( $html_body === '' ) {
            $html_body = (string) ( $campaign['html_body'] ?? '' );
        }
        $html_body = Support::ensureEmailContainer( $html_body );
        $html_body = str_replace(
            [ '{{unsubscribe_url}}', '{{manage_subscription_url}}', '{{view_online_url}}', '{{view_newsletter_url}}', '{{open_pixel_url}}' ],
            [ '#', '#', \metis_newsletter_public_view_url( (string) ( $resolved['ref'] ?? '' ) ), \metis_newsletter_public_view_url( (string) ( $resolved['ref'] ?? '' ) ), '' ],
            $html_body
        );
        return Response::html( $html_body !== '' ? $html_body : '<html><body style="font-family:Arial,sans-serif;padding:24px;"><h2>Newsletter unavailable.</h2></body></html>' );
    }

    private static function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }

    private static function resolvePublicContact( string $ref ): ?array {
        $ref = trim( $ref );
        if ( $ref === '' ) {
            return null;
        }

        $db             = self::db();
        $contacts_table = \Metis_Tables::get( 'contacts' );

        if ( ctype_digit( $ref ) ) {
            $row = $db->fetchOne( "SELECT id, cid, email FROM {$contacts_table} WHERE id = %d LIMIT 1", [ (int) $ref ] );
            if ( $row ) {
                return [
                    'id'    => (int) ( $row['id'] ?? 0 ),
                    'ref'   => (string) ( $row['cid'] ?? $ref ),
                    'email' => (string) ( $row['email'] ?? '' ),
                ];
            }
        }

        if ( \class_exists( '\Metis\Core\CodeRegistry' ) ) {
            $resolved = \Metis\Core\CodeRegistry::resolve( $ref );
            if ( is_array( $resolved ) && (string) ( $resolved['entity_type'] ?? '' ) === 'contact' && (int) ( $resolved['id'] ?? 0 ) > 0 ) {
                $row = $db->fetchOne( "SELECT id, cid, email FROM {$contacts_table} WHERE id = %d LIMIT 1", [ (int) $resolved['id'] ] );
                if ( $row ) {
                    return [
                        'id'    => (int) ( $row['id'] ?? 0 ),
                        'ref'   => (string) ( $row['cid'] ?? (string) ( $resolved['entity_uid'] ?? $ref ) ),
                        'email' => (string) ( $row['email'] ?? '' ),
                    ];
                }
            }
        }

        $row = $db->fetchOne(
            "SELECT id, cid, email
             FROM {$contacts_table}
             WHERE cid = %s
             LIMIT 1",
            [ $ref ]
        );
        if ( ! $row ) {
            return null;
        }

        return [
            'id'    => (int) ( $row['id'] ?? 0 ),
            'ref'   => (string) ( $row['cid'] ?? $ref ),
            'email' => (string) ( $row['email'] ?? '' ),
        ];
    }

    private static function resolvePublicCampaign( string $ref ): ?array {
        $ref = trim( $ref );
        if ( $ref === '' ) {
            return null;
        }

        $db              = self::db();
        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );

        if ( ctype_digit( $ref ) ) {
            $row = $db->fetchOne( "SELECT id, campaign_code FROM {$campaigns_table} WHERE id = %d LIMIT 1", [ (int) $ref ] );
            if ( $row ) {
                return [
                    'id'  => (int) ( $row['id'] ?? 0 ),
                    'ref' => (string) ( $row['campaign_code'] ?? $ref ),
                ];
            }
        }

        if ( \class_exists( '\Metis\Core\CodeRegistry' ) ) {
            $resolved = \Metis\Core\CodeRegistry::resolve( $ref );
            if ( is_array( $resolved ) && (string) ( $resolved['entity_type'] ?? '' ) === 'newsletter_campaign' && (int) ( $resolved['id'] ?? 0 ) > 0 ) {
                $row = $db->fetchOne( "SELECT id, campaign_code FROM {$campaigns_table} WHERE id = %d LIMIT 1", [ (int) $resolved['id'] ] );
                if ( $row ) {
                    return [
                        'id'  => (int) ( $row['id'] ?? 0 ),
                        'ref' => (string) ( $row['campaign_code'] ?? (string) ( $resolved['entity_uid'] ?? $ref ) ),
                    ];
                }
            }
        }

        $row = $db->fetchOne(
            "SELECT id, campaign_code
             FROM {$campaigns_table}
             WHERE campaign_code = %s
             LIMIT 1",
            [ $ref ]
        );
        if ( ! $row ) {
            return null;
        }

        return [
            'id'  => (int) ( $row['id'] ?? 0 ),
            'ref' => (string) ( $row['campaign_code'] ?? $ref ),
        ];
    }

    private static function resolvePublicList( string $ref ): ?array {
        $ref = trim( $ref );
        if ( $ref === '' ) {
            return null;
        }

        $db          = self::db();
        $lists_table = \Metis_Tables::get( 'newsletter_lists' );

        if ( ctype_digit( $ref ) ) {
            $row = $db->fetchOne(
                "SELECT id, list_key, newsletter_list_uid, name
                 FROM {$lists_table}
                 WHERE id = %d
                 LIMIT 1",
                [ (int) $ref ]
            );
            if ( $row ) {
                return [
                    'id'   => (int) ( $row['id'] ?? 0 ),
                    'ref'  => (string) ( $row['newsletter_list_uid'] ?? $row['list_key'] ?? $ref ),
                    'name' => (string) ( $row['name'] ?? '' ),
                ];
            }
        }

        if ( \class_exists( '\Metis\Core\CodeRegistry' ) ) {
            $resolved = \Metis\Core\CodeRegistry::resolve( $ref );
            if ( is_array( $resolved ) && (string) ( $resolved['entity_type'] ?? '' ) === 'newsletter_list' && (int) ( $resolved['id'] ?? 0 ) > 0 ) {
                $row = $db->fetchOne(
                    "SELECT id, list_key, newsletter_list_uid, name
                     FROM {$lists_table}
                     WHERE id = %d
                     LIMIT 1",
                    [ (int) $resolved['id'] ]
                );
                if ( $row ) {
                    return [
                        'id'   => (int) ( $row['id'] ?? 0 ),
                        'ref'  => (string) ( $row['newsletter_list_uid'] ?? $row['list_key'] ?? (string) ( $resolved['entity_uid'] ?? $ref ) ),
                        'name' => (string) ( $row['name'] ?? '' ),
                    ];
                }
            }
        }

        $row = $db->fetchOne(
            "SELECT id, list_key, newsletter_list_uid, name
             FROM {$lists_table}
             WHERE newsletter_list_uid = %s OR list_key = %s
             LIMIT 1",
            [ $ref, $ref ]
        );
        if ( ! $row ) {
            return null;
        }

        return [
            'id'   => (int) ( $row['id'] ?? 0 ),
            'ref'  => (string) ( $row['newsletter_list_uid'] ?? $row['list_key'] ?? $ref ),
            'name' => (string) ( $row['name'] ?? '' ),
        ];
    }

    private static function respondPublicSignup( Request $request, array $payload, int $status ): Response {
        $expects_json = str_contains( strtolower( (string) $request->header( 'accept', '' ) ), 'application/json' )
            || strtolower( (string) $request->header( 'x-requested-with', '' ) ) === 'xmlhttprequest';

        if ( $expects_json ) {
            return Response::json( $payload, $status );
        }

        $title = ! empty( $payload['success'] ) ? 'Thanks for signing up' : 'Sign-up unavailable';
        $message = trim( (string) ( $payload['message'] ?? '' ) );
        if ( $message === '' ) {
            $message = ! empty( $payload['success'] ) ? 'You are signed up.' : 'We could not complete that sign-up.';
        }

        return Response::html(
            '<html><body style="font-family:Arial,sans-serif;padding:24px;max-width:640px;margin:0 auto;">'
            . '<h2 style="margin:0 0 8px;">' . metis_escape_html( $title ) . '</h2>'
            . '<p style="margin:0;color:#4b5563;">' . metis_escape_html( $message ) . '</p>'
            . '</body></html>',
            $status
        );
    }
}
