<?php
declare(strict_types=1);

namespace Metis\Modules\Newsletter;

final class QueueService {
    public static function queueCampaignMessages( int $campaign_id ): array {
        $db = self::db();

        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $messages_table  = \Metis_Tables::get( 'newsletter_messages' );

        $campaign = $db->fetchOne( "SELECT * FROM {$campaigns_table} WHERE id = %d LIMIT 1", [ $campaign_id ] );
        if ( ! $campaign ) {
            return [ 'ok' => false, 'message' => 'Campaign not found.' ];
        }

        $audience   = \metis_newsletter_decode_audience_json( (string) ( $campaign['audience_json'] ?? '' ) );
        $recipients = \metis_newsletter_collect_recipients( $campaign_id, $audience );
        if ( empty( $recipients ) ) {
            return [ 'ok' => false, 'message' => 'No eligible contacts found for this campaign audience.' ];
        }

        $queued = 0;
        $now    = \metis_current_time( 'mysql' );
        $recipient_contact_ids = [];

        foreach ( $recipients as $recipient ) {
            $contact_id = (int) ( $recipient['contact_id'] ?? 0 );
            if ( $contact_id > 0 ) {
                $recipient_contact_ids[ $contact_id ] = true;
            }
        }

        $existing_contact_ids = [];
        if ( ! empty( $recipient_contact_ids ) ) {
            $contact_ids = array_keys( $recipient_contact_ids );
            $placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
            $params = array_merge( [ $campaign_id ], $contact_ids );
            $rows = $db->fetchAll(
                "SELECT contact_id FROM {$messages_table} WHERE campaign_id = %d AND contact_id IN ({$placeholders})",
                $params
            );
            foreach ( (array) $rows as $row ) {
                $existing_contact_id = (int) ( $row['contact_id'] ?? 0 );
                if ( $existing_contact_id > 0 ) {
                    $existing_contact_ids[ $existing_contact_id ] = true;
                }
            }
        }

        foreach ( $recipients as $recipient ) {
            $contact_id = (int) ( $recipient['contact_id'] ?? 0 );
            if ( $contact_id < 1 ) {
                continue;
            }

            if ( isset( $existing_contact_ids[ $contact_id ] ) ) {
                continue;
            }

            $insert = $db->insert(
                $messages_table,
                [
                    'message_code' => \metis_generate_code( 'NM', $messages_table, 'message_code' ),
                    'campaign_id'  => $campaign_id,
                    'contact_id'   => $contact_id,
                    'email'        => strtolower( trim( (string) ( $recipient['email'] ?? '' ) ) ),
                    'status'       => 'queued',
                    'queued_at'    => $now,
                    'payload_json' => \metis_json_encode( [
                        'first_name'  => (string) ( $recipient['first_name'] ?? '' ),
                        'last_name'   => (string) ( $recipient['last_name'] ?? '' ),
                        'contact_cid' => (string) ( $recipient['contact_cid'] ?? '' ),
                        'city'        => (string) ( $recipient['city'] ?? '' ),
                        'state'       => (string) ( $recipient['state'] ?? '' ),
                    ] ),
                ],
                [ '%s', '%d', '%d', '%s', '%s', '%s', '%s' ]
            );

            if ( $insert !== false ) {
                $queued++;
                $existing_contact_ids[ $contact_id ] = true;
            }
        }

        $status = ! empty( $campaign['scheduled_at'] ) ? 'scheduled' : 'queued';
        $db->update(
            $campaigns_table,
            [
                'status'           => $status,
                'queued_at'        => $now,
                'total_recipients' => (int) $db->scalar( "SELECT COUNT(*) FROM {$messages_table} WHERE campaign_id = %d", [ $campaign_id ] ),
                'last_error'       => null,
            ],
            [ 'id' => $campaign_id ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d' ]
        );

        \metis_newsletter_audit_log( 'campaign_queued', 'campaign', $campaign_id, [ 'queued' => $queued, 'audience' => $audience ] );

        if ( \Metis\Core\Application::has_service( 'events' ) ) {
            \Metis\Core\Application::service( 'events' )->publish(
                'newsletter.campaign.queued',
                [
                    'campaign_id' => $campaign_id,
                    'queued'      => $queued,
                    'status'      => $status,
                    'audience'    => $audience,
                    'queued_at'   => $now,
                ]
            );
        }

        return [ 'ok' => true, 'queued' => $queued ];
    }

    public static function processQueue( int $limit = 100 ): array {
        $db = self::db();

        $limit           = max( 1, min( 500, $limit ) );
        $campaigns_table = \Metis_Tables::get( 'newsletter_campaigns' );
        $templates_table = \Metis_Tables::get( 'newsletter_templates' );
        $messages_table  = \Metis_Tables::get( 'newsletter_messages' );
        $events_table    = \Metis_Tables::get( 'newsletter_events' );
        $subs_table      = \Metis_Tables::get( 'newsletter_subs' );
        $now             = \metis_current_time( 'mysql' );

        $rows = $db->fetchAll(
            "SELECT m.*, c.campaign_code, c.name AS campaign_name, c.subject, c.from_name, c.from_email, c.reply_to, c.template_id, c.scheduled_at, c.doc_json AS campaign_doc_json, c.editor_body_html AS campaign_editor_body_html, c.html_body AS campaign_html_body, c.text_body AS campaign_text_body, c.attachments_json, t.doc_json AS template_doc_json, t.html_body, t.text_body
             FROM {$messages_table} m
             INNER JOIN {$campaigns_table} c ON c.id = m.campaign_id
             LEFT JOIN {$templates_table} t ON t.id = c.template_id
             WHERE m.status = 'queued'
               AND (c.scheduled_at IS NULL OR c.scheduled_at = '' OR c.scheduled_at <= %s)
             ORDER BY m.id ASC
             LIMIT %d",
            [ $now, $limit ]
        );

        if ( empty( $rows ) ) {
            return [ 'processed' => 0, 'sent' => 0, 'failed' => 0 ];
        }

        $campaignListRefs = [];
        $campaignIds = array_values( array_unique( array_filter( array_map(
            static fn ( array $row ): int => (int) ( $row['campaign_id'] ?? 0 ),
            $rows
        ) ) ) );
        if ( ! empty( $campaignIds ) ) {
            $campaignListsTable = \Metis_Tables::get( 'newsletter_campaign_lists' );
            $listsTable         = \Metis_Tables::get( 'newsletter_lists' );
            $placeholders       = implode( ',', array_fill( 0, count( $campaignIds ), '%d' ) );
            $listRows = $db->fetchAll(
                "SELECT cl.campaign_id, l.newsletter_list_uid, l.list_key
                 FROM {$campaignListsTable} cl
                 INNER JOIN {$listsTable} l ON l.id = cl.list_id
                 WHERE cl.campaign_id IN ({$placeholders})
                 ORDER BY cl.campaign_id ASC, cl.list_id ASC",
                $campaignIds
            ) ?: [];
            foreach ( $listRows as $listRow ) {
                $cid = (int) ( $listRow['campaign_id'] ?? 0 );
                if ( $cid < 1 || isset( $campaignListRefs[ $cid ] ) ) {
                    continue;
                }
                $campaignListRefs[ $cid ] = trim( (string) ( $listRow['newsletter_list_uid'] ?? $listRow['list_key'] ?? '' ) );
            }
        }

        $processed = 0;
        $sent      = 0;
        $failed    = 0;

        foreach ( $rows as $row ) {
            $processed++;
            $message_id      = (int) ( $row['id'] ?? 0 );
            $campaign_id     = (int) ( $row['campaign_id'] ?? 0 );
            $contact_payload = json_decode( (string) ( $row['payload_json'] ?? '{}' ), true );
            $contact_payload = is_array( $contact_payload ) ? $contact_payload : [];
            $contact_payload['email']         = (string) ( $row['email'] ?? '' );
            $contact_payload['campaign_name'] = (string) ( $row['campaign_name'] ?? '' );

            $subject  = (string) ( $row['subject'] ?? 'Newsletter' );
            $doc_json = (string) ( $row['campaign_doc_json'] ?? '' );
            $editor_body_html = array_key_exists( 'campaign_editor_body_html', $row )
                ? (string) ( $row['campaign_editor_body_html'] ?? '' )
                : null;
            if ( $doc_json === '' ) {
                $doc_json = (string) ( $row['template_doc_json'] ?? '' );
                $editor_body_html = null;
            }
            $doc_json = \metis_newsletter_normalize_campaign_doc_json( $doc_json, $editor_body_html );

            $html_body = '';
            $text_body = '';
            if ( $doc_json !== '' ) {
                $compiled  = \metis_newsletter_doc_compile( $doc_json );
                $html_body = (string) ( $compiled['html'] ?? '' );
                $text_body = (string) ( $compiled['text'] ?? '' );
            }
            if ( $html_body === '' ) { $html_body = (string) ( $row['campaign_html_body'] ?? '' ); }
            if ( $text_body === '' ) { $text_body = (string) ( $row['campaign_text_body'] ?? '' ); }
            if ( $html_body === '' ) { $html_body = (string) ( $row['html_body'] ?? '' ); }
            if ( $text_body === '' ) { $text_body = (string) ( $row['text_body'] ?? '' ); }

            $html_body     = Support::ensureEmailContainer( $html_body );
            $rendered_html = $html_body !== '' ? Support::renderTemplate( $html_body, $contact_payload ) : '';
            $rendered_text = $text_body !== '' ? Support::renderTemplate( $text_body, $contact_payload ) : '';
            if ( $rendered_html === '' && $rendered_text !== '' ) {
                $rendered_html = '<p>' . nl2br( \metis_escape_html( $rendered_text ) ) . '</p>';
            }

            $message_code    = (string) ( $row['message_code'] ?? '' );
            $contact_ref     = trim( (string) ( $contact_payload['contact_cid'] ?? '' ) );
            if ( $contact_ref === '' ) {
                $contact_ref = (string) ( (int) ( $row['contact_id'] ?? 0 ) );
            }
            $newsletter_ref  = trim( (string) ( $row['campaign_code'] ?? '' ) );
            if ( $newsletter_ref === '' ) {
                $newsletter_ref = (string) ( (int) ( $row['campaign_id'] ?? 0 ) );
            }
            $list_ref        = trim( (string) ( $campaignListRefs[ $campaign_id ] ?? '' ) );
            $unsubscribe_url = \metis_newsletter_public_unsubscribe_url( $contact_ref, $list_ref );
            $manage_url      = \metis_newsletter_public_manage_url( $contact_ref );
            $view_url        = \metis_newsletter_public_view_url( $newsletter_ref );
            $open_pixel_url  = \metis_newsletter_open_pixel_url_from_message_code( $message_code );
            $contact_payload['unsubscribe_url'] = $unsubscribe_url;
            $contact_payload['manage_subscription_url'] = $manage_url;
            $contact_payload['view_online_url'] = $view_url;
            $contact_payload['view_newsletter_url'] = $view_url;
            $rendered_html   = str_replace( [ '{{unsubscribe_url}}', '{{manage_subscription_url}}', '{{view_online_url}}', '{{view_newsletter_url}}', '{{open_pixel_url}}' ], [ $unsubscribe_url, $manage_url, $view_url, $view_url, $open_pixel_url ], $rendered_html );
            $rendered_text   = str_replace( [ '{{unsubscribe_url}}', '{{manage_subscription_url}}', '{{view_online_url}}', '{{view_newsletter_url}}' ], [ $unsubscribe_url, $manage_url, $view_url, $view_url ], $rendered_text );
            $rendered_html   = \metis_newsletter_inject_click_tracking( $rendered_html, $message_code );
            $rendered_html  .= '<img src="' . \metis_escape_url( $open_pixel_url ) . '" alt="" width="1" height="1" style="display:block;width:1px;height:1px;border:0;opacity:0;">';
            $body            = $rendered_html !== '' ? $rendered_html : $rendered_text;

            $default_from_name  = trim( (string) \Core_Settings_Service::get( 'newsletter_default_from_name', '' ) );
            $default_from_email = trim( (string) \Core_Settings_Service::get( 'newsletter_default_from_email', '' ) );
            $default_reply_to   = trim( (string) \Core_Settings_Service::get( 'newsletter_default_reply_to', '' ) );

            $from_name  = trim( (string) ( $row['from_name'] ?? '' ) );
            $from_email = trim( (string) ( $row['from_email'] ?? '' ) );
            $reply_to   = trim( (string) ( $row['reply_to'] ?? '' ) );
            if ( $from_name === '' ) { $from_name = $default_from_name; }
            if ( $from_email === '' ) { $from_email = $default_from_email; }
            if ( $reply_to === '' ) { $reply_to = $default_reply_to; }

            $attachments    = [];
            $attachment_raw = (string) ( $row['attachments_json'] ?? '' );
            if ( $attachment_raw !== '' ) {
                $decoded_attachments = json_decode( $attachment_raw, true );
                if ( is_array( $decoded_attachments ) ) {
                    foreach ( $decoded_attachments as $att ) {
                        if ( ! is_array( $att ) ) {
                            continue;
                        }
                        $path = (string) ( $att['path'] ?? '' );
                        $url  = (string) ( $att['url'] ?? '' );
                        $name = trim( (string) ( $att['name'] ?? '' ) );
                        $mime = trim( (string) ( $att['mime'] ?? 'application/octet-stream' ) );
                        if ( $path !== '' && file_exists( $path ) ) {
                            $attachments[] = [ 'path' => $path, 'name' => $name !== '' ? $name : basename( $path ), 'mime' => $mime ];
                        } elseif ( $url !== '' ) {
                            $attachments[] = [ 'url' => $url, 'name' => $name !== '' ? $name : basename( parse_url( $url, PHP_URL_PATH ) ?: 'attachment' ), 'mime' => $mime ];
                        }
                    }
                }
            }

            $send = DeliveryService::gmailSend(
                (string) ( $row['email'] ?? '' ),
                $subject,
                $body,
                [
                    'from_name'          => $from_name,
                    'from_email'         => $from_email,
                    'reply_to'           => $reply_to,
                    'attachments'        => $attachments,
                    'internal_reference' => $message_code !== '' ? 'NLMSG-' . $message_code : '',
                ]
            );

            if ( ! empty( $send['ok'] ) ) {
                $sent++;
                $db->update(
                    $messages_table,
                    [
                        'status'     => 'sent',
                        'sent_at'    => $now,
                        'provider'   => 'gmail_api',
                        'attempts'   => (int) ( $row['attempts'] ?? 0 ) + 1,
                        'last_error' => null,
                    ],
                    [ 'id' => $message_id ],
                    [ '%s', '%s', '%s', '%d', '%s' ],
                    [ '%d' ]
                );
                \metis_newsletter_track_event_for_message( $message_code, 'delivered', 'Gmail accepted' );

                if ( \Metis\Core\Application::has_service( 'events' ) ) {
                    \Metis\Core\Application::service( 'events' )->publish(
                        'newsletter.sent',
                        [
                            'campaign_id' => $campaign_id,
                            'message_id'  => $message_id,
                            'message_code'=> $message_code,
                            'contact_id'  => (int) ( $row['contact_id'] ?? 0 ),
                            'email'       => (string) ( $row['email'] ?? '' ),
                            'subject'     => $subject,
                            'provider'    => 'gmail_api',
                            'sent_at'     => $now,
                        ]
                    );
                }
            } else {
                $failed++;
                $send_error = 'Email delivery failed.';
                $db->update(
                    $messages_table,
                    [
                        'status'     => 'failed',
                        'provider'   => 'gmail_api',
                        'attempts'   => (int) ( $row['attempts'] ?? 0 ) + 1,
                        'last_error' => $send_error,
                    ],
                    [ 'id' => $message_id ],
                    [ '%s', '%s', '%d', '%s' ],
                    [ '%d' ]
                );

                $db->insert(
                    $events_table,
                    [
                        'event_code'  => \metis_generate_code( 'NE', $events_table, 'event_code' ),
                        'message_id'  => $message_id,
                        'campaign_id' => $campaign_id,
                        'contact_id'  => (int) ( $row['contact_id'] ?? 0 ),
                        'email'       => (string) ( $row['email'] ?? '' ),
                        'event_type'  => 'rejected',
                        'reason'      => $send_error,
                        'source'      => 'sender',
                        'event_at'    => $now,
                    ],
                    [ '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
                );

                if ( \Metis\Core\Application::has_service( 'events' ) ) {
                    \Metis\Core\Application::service( 'events' )->publish(
                        'newsletter.failed',
                        [
                            'campaign_id' => $campaign_id,
                            'message_id'  => $message_id,
                            'message_code'=> $message_code,
                            'contact_id'  => (int) ( $row['contact_id'] ?? 0 ),
                            'email'       => (string) ( $row['email'] ?? '' ),
                            'subject'     => $subject,
                            'provider'    => 'gmail_api',
                            'error'       => $send_error,
                            'failed_at'   => $now,
                        ]
                    );
                }
            }

            $stats = $db->fetchOne(
                "SELECT
                    SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) AS sent_count,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN status='bounced' THEN 1 ELSE 0 END) AS bounced_count,
                    SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected_count,
                    COUNT(*) AS total_count
                 FROM {$messages_table}
                 WHERE campaign_id = %d",
                [ $campaign_id ]
            );
            $stats          = is_array( $stats ) ? $stats : [];
            $total          = (int) ( $stats['total_count'] ?? 0 );
            $sent_count     = (int) ( $stats['sent_count'] ?? 0 );
            $failed_count   = (int) ( $stats['failed_count'] ?? 0 );
            $bounced_count  = (int) ( $stats['bounced_count'] ?? 0 );
            $rejected_count = (int) ( $stats['rejected_count'] ?? 0 );
            $campaign_status= ( $total > 0 && ( $sent_count + $failed_count + $bounced_count + $rejected_count ) >= $total ) ? 'sent' : 'sending';

            $db->update(
                $campaigns_table,
                [
                    'status'           => $campaign_status,
                    'sent_at'          => $campaign_status === 'sent' ? $now : null,
                    'total_recipients' => $total,
                    'sent_count'       => $sent_count,
                    'failed_count'     => $failed_count,
                    'bounced_count'    => $bounced_count,
                    'rejected_count'   => $rejected_count,
                    'last_error'       => $failed_count > 0 ? 'One or more messages failed.' : null,
                ],
                [ 'id' => $campaign_id ],
                [ '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' ],
                [ '%d' ]
            );

            if ( $failed_count > 0 ) {
                $db->execute(
                    $db->prepare(
                        "UPDATE {$subs_table} s
                         INNER JOIN {$messages_table} m ON m.contact_id = s.contact_id
                         SET s.last_event_at = %s
                         WHERE m.campaign_id = %d AND m.status = 'failed'",
                        $now,
                        $campaign_id
                    )
                );
            }
        }

        return [ 'processed' => $processed, 'sent' => $sent, 'failed' => $failed ];
    }

    private static function db(): \Metis\Services\DatabaseService {
        return \metis_db();
    }
}
